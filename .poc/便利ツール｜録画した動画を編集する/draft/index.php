<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * サーバー保存:
 *   ./data/projects/*.json
 *
 * ブラウザ保存:
 *   IndexedDB
 *   - projects : 編集データ
 *   - videos   : 動画本体
 *
 * 注意:
 *   「プロジェクト書き出し」は編集データ(JSON)です。
 *   MP4への再エンコードは行いません。
 */

const APP_VERSION = 6;
const MAX_SERVER_PROJECTS = 20;

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

function ensureProjectDir(): bool
{
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true)) {
        return false;
    }

    if (!is_dir(PROJECT_DIR) && !@mkdir(PROJECT_DIR, 0775, true)) {
        return false;
    }

    return is_dir(PROJECT_DIR) && is_writable(PROJECT_DIR);
}

function validProjectId(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{8,100}$/', $id);
}

function projectPath(string $id): string
{
    return PROJECT_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

function readServerProjects(): array
{
    if (!is_dir(PROJECT_DIR)) {
        return [];
    }

    $result = [];

    foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $data = json_decode(
            @file_get_contents($file) ?: '',
            true
        );

        if (is_array($data) && isset($data['projectId'])) {
            $result[] = $data;
        }
    }

    usort(
        $result,
        static fn(array $a, array $b): int =>
            strcmp(
                (string)($b['savedAt'] ?? ''),
                (string)($a['savedAt'] ?? '')
            )
    );

    return $result;
}

function projectSummary(array $project): array
{
    return [
        'projectId' => (string)($project['projectId'] ?? ''),
        'name' => (string)($project['name'] ?? '名称未設定'),
        'videoName' => (string)($project['videoName'] ?? ''),
        'savedAt' => (string)($project['savedAt'] ?? ''),
        'version' => (int)($project['version'] ?? 1),
        'storage' => 'server'
    ];
}

/* =========================================================
 * API
 * ======================================================= */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    if ($api === 'status') {
        jsonResponse([
            'ok' => true,
            'version' => APP_VERSION,
            'serverWritable' => ensureProjectDir(),
            'serverLimit' => MAX_SERVER_PROJECTS,
            'serverCount' => count(readServerProjects()),
            'php' => PHP_VERSION
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map(
                'projectSummary',
                readServerProjects()
            ),
            'limit' => MAX_SERVER_PROJECTS
        ]);
    }

    if ($api === 'load') {
        $id = (string)($_GET['id'] ?? '');

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $file = projectPath($id);

        if (!is_file($file)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトが見つかりません。'
            ], 404);
        }

        $data = json_decode(
            @file_get_contents($file) ?: '',
            true
        );

        if (!is_array($data)) {
            jsonResponse([
                'ok' => false,
                'message' => '保存データが壊れています。'
            ], 500);
        }

        jsonResponse([
            'ok' => true,
            'project' => $data
        ]);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse([
                'ok' => false,
                'message' => 'POST only'
            ], 405);
        }

        if (!ensureProjectDir()) {
            jsonResponse([
                'ok' => false,
                'message' => 'data/projects に書き込めません。'
            ], 500);
        }

        $payload = json_decode(
            file_get_contents('php://input') ?: '',
            true
        );

        if (!is_array($payload)) {
            jsonResponse([
                'ok' => false,
                'message' => 'JSONが不正です。'
            ], 400);
        }

        $id = trim((string)($payload['projectId'] ?? ''));

        if ($id === '') {
            $id = 'project-' . bin2hex(random_bytes(10));
        }

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $name = trim((string)($payload['name'] ?? ''));

        if ($name === '') {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクト名を入力してください。'
            ], 422);
        }

        if (mb_strlen($name) > 120) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクト名は120文字以内にしてください。'
            ], 422);
        }

        $existing = is_file(projectPath($id));
        $projects = readServerProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' =>
                    'サーバー保存上限に達しました。ローカル保存を使用してください。'
            ], 409);
        }

        $payload['version'] = APP_VERSION;
        $payload['projectId'] = $id;
        $payload['name'] = $name;
        $payload['savedAt'] = date('c');

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false) {
            jsonResponse([
                'ok' => false,
                'message' => 'JSON生成に失敗しました。'
            ], 500);
        }

        if (@file_put_contents(
            projectPath($id),
            $json,
            LOCK_EX
        ) === false) {
            jsonResponse([
                'ok' => false,
                'message' => '編集データを保存できませんでした。'
            ], 500);
        }

        jsonResponse([
            'ok' => true,
            'projectId' => $id,
            'savedAt' => $payload['savedAt'],
            'version' => APP_VERSION
        ]);
    }

    if ($api === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse([
                'ok' => false,
                'message' => 'POST only'
            ], 405);
        }

        $payload = json_decode(
            file_get_contents('php://input') ?: '',
            true
        );

        $id = is_array($payload)
            ? (string)($payload['projectId'] ?? '')
            : '';

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $file = projectPath($id);

        if (is_file($file) && !@unlink($file)) {
            jsonResponse([
                'ok' => false,
                'message' => '削除できませんでした。'
            ], 500);
        }

        jsonResponse(['ok' => true]);
    }

    jsonResponse([
        'ok' => false,
        'message' => 'Unknown API'
    ], 404);
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画上直接編集ツール</title>

<style>
:root{
    --bg:#101216;
    --panel:#1b1e23;
    --panel2:#252a30;
    --border:#414750;
    --text:#f5f7fa;
    --muted:#9ca5af;
    --blue:#176bb9;
    --green:#277a47;
    --red:#a73535;
    --yellow:#ffd447;
    --skip:#e38b28;
    --purple:#a66be8;
}

*{
    box-sizing:border-box;
}

html,
body{
    width:100%;
    height:100%;
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
}

body{
    overflow:hidden;
}

button,
input,
select,
textarea{
    font:inherit;
}

button{
    border:1px solid #565d66;
    background:#30353c;
    color:#fff;
    border-radius:6px;
    padding:8px 11px;
    cursor:pointer;
}

button:hover:not(:disabled){
    background:#424850;
}

button:disabled{
    opacity:.4;
    cursor:not-allowed;
}

button.primary{
    background:var(--blue);
}

button.success{
    background:var(--green);
}

button.danger{
    background:var(--red);
}

button.active{
    background:#9a6a12;
    border-color:#ffd34d;
}

input,
select,
textarea{
    width:100%;
    color:#fff;
    background:#252a30;
    border:1px solid #555c65;
    border-radius:5px;
    padding:7px;
}

textarea{
    min-height:90px;
    resize:vertical;
}

input[type=color]{
    height:38px;
    padding:3px;
}

.hidden{
    display:none!important;
}

header{
    height:50px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 14px;
    background:#1b1e22;
    border-bottom:1px solid #353a41;
}

header h1{
    margin:0;
    font-size:16px;
}

#status{
    color:#b6bec7;
    font-size:12px;
}

#message{
    position:fixed;
    top:60px;
    left:50%;
    transform:translateX(-50%);
    z-index:10000;
    display:none;
    max-width:90vw;
    padding:10px 16px;
    border-radius:7px;
    background:#983838;
    box-shadow:0 10px 35px #000b;
    white-space:pre-wrap;
}

#message.ok{
    background:#287348;
}

/* HOME */

#home{
    height:calc(100vh - 50px);
    display:flex;
    justify-content:center;
    align-items:center;
    padding:20px;
    background:#111317;
}

.home-card{
    width:min(760px,95vw);
    max-height:90vh;
    overflow:auto;
    padding:25px;
    background:#1b1e23;
    border:1px solid var(--border);
    border-radius:10px;
    box-shadow:0 15px 50px #0007;
}

.home-card h2{
    margin:0 0 8px;
}

.home-card p{
    color:var(--muted);
    font-size:13px;
    line-height:1.6;
}

.home-actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:15px;
}

.project-list{
    margin-top:20px;
}

.project{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:11px;
    margin:7px 0;
    border:1px solid #414750;
    border-radius:7px;
}

.project-info{
    min-width:0;
}

.project-name{
    font-weight:600;
}

.project-meta{
    color:#929aa4;
    font-size:11px;
    margin-top:3px;
}

.project-actions{
    display:flex;
    gap:5px;
    flex-shrink:0;
}

/* RECORDER */

#recorder{
    position:fixed;
    inset:50px 0 0;
    z-index:100;
    display:none;
    flex-direction:column;
    background:#000;
}

#previewWrap{
    flex:1;
    min-height:0;
    display:flex;
    justify-content:center;
    align-items:center;
}

#preview{
    max-width:100%;
    max-height:100%;
}

#recordToolbar{
    min-height:60px;
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:8px;
    padding:8px 12px;
    background:#1a1d21;
    border-top:1px solid #33383f;
}

/* EDITOR */

#editor{
    position:fixed;
    inset:0;
    z-index:200;
    display:none;
    flex-direction:column;
    background:#111;
}

.editor-top{
    height:50px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
    background:#1b1e22;
    border-bottom:1px solid #363b42;
}

#editorProjectName{
    width:230px;
    font-weight:600;
}

#editorStatus{
    color:#aeb6bf;
    font-size:12px;
}

.editor-main{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}

.video-area{
    flex:1;
    min-height:0;
    display:flex;
    justify-content:center;
    align-items:center;
    overflow:hidden;
    background:#000;
}

#videoStage{
    position:relative;
    background:#000;
    overflow:visible;
    line-height:0;
}

#recordedVideo{
    display:block;
    width:100%;
    height:100%;
    object-fit:fill;
}

#overlay{
    position:absolute;
    inset:0;
    pointer-events:none;
}

#objects{
    position:absolute;
    inset:0;
    pointer-events:none;
}

#connectors{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
}

.edit-object{
    position:absolute;
    pointer-events:auto;
    cursor:move;
    user-select:none;
    touch-action:none;
    overflow:visible;
    line-height:normal;
}

.edit-object.selected{
    outline:2px solid #fff;
    outline-offset:2px;
}

.edit-object.connect-source{
    outline:3px solid var(--yellow);
    outline-offset:4px;
}

.edit-object.comment{
    display:flex;
    align-items:center;
    justify-content:flex-start;
    min-width:40px;
    min-height:25px;
    padding:6px 9px;
    white-space:pre-wrap;
    word-break:break-word;
    overflow:hidden;
    box-shadow:0 2px 12px #0006;
}

.edit-object.box{
    background:transparent;
}

.edit-object.skip{
    border:2px dashed var(--skip);
    background:#e38b2818;
}

.skip-label{
    position:absolute;
    left:4px;
    top:4px;
    padding:2px 6px;
    color:#fff;
    background:#c87518;
    border-radius:4px;
    font-size:11px;
    pointer-events:none;
}

.resize-handle{
    position:absolute;
    right:-7px;
    bottom:-7px;
    width:14px;
    height:14px;
    border-radius:50%;
    background:#fff;
    border:1px solid #222;
    cursor:nwse-resize;
    display:none;
}

.edit-object.selected .resize-handle{
    display:block;
}

.connection-point{
    position:absolute;
    width:16px;
    height:16px;
    margin:-8px;
    border:2px solid #fff;
    background:#247bd3;
    border-radius:50%;
    display:none;
    z-index:20;
    cursor:crosshair;
}

.edit-object.selected .connection-point,
.edit-object.connect-source .connection-point{
    display:block;
}

.edit-object.connect-source .connection-point{
    background:var(--yellow);
    border-color:#111;
    box-shadow:0 0 0 3px #ffd44755;
}

.cp-n{left:50%;top:0}
.cp-ne{left:100%;top:0}
.cp-e{left:100%;top:50%}
.cp-se{left:100%;top:100%}
.cp-s{left:50%;top:100%}
.cp-sw{left:0;top:100%}
.cp-w{left:0;top:50%}
.cp-nw{left:0;top:0}

.connector{
    fill:none;
    pointer-events:none;
}

.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:20;
    pointer-events:stroke;
    cursor:pointer;
}

.connector.selected{
    filter:drop-shadow(0 0 4px #ffd447);
}

/* TIMELINE */

#timeline{
    height:220px;
    flex-shrink:0;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:8px 12px;
    overflow:hidden;
}

.seek-wrap{
    position:relative;
}

#seek{
    display:block;
    width:100%;
    margin:0;
}

.timeline-scale{
    position:relative;
    height:24px;
    margin:0;
    color:#8f969f;
    font-size:10px;
    border-left:1px solid #4a5058;
    border-right:1px solid #4a5058;
}

.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    white-space:nowrap;
}

.timeline-row{
    display:grid;
    grid-template-columns:72px 1fr;
    gap:7px;
    margin-top:5px;
    align-items:center;
    font-size:11px;
    color:#b0b7c0;
}

.track{
    position:relative;
    height:31px;
    background:#292d33;
    border-radius:4px;
    border:1px solid #3c4249;
    overflow:visible;
}

.track-item{
    position:absolute;
    top:3px;
    height:23px;
    min-width:4px;
    border-radius:3px;
    cursor:pointer;
    overflow:visible;
}

.track-item.comment{
    background:#42a5f5;
}

.track-item.box{
    background:#ef5350;
}

.track-item.skip{
    background:var(--skip);
}

.track-item.connection{
    background:var(--purple);
}

.track-item.selected{
    outline:2px solid #fff;
    outline-offset:1px;
}

.track-time{
    position:absolute;
    top:-16px;
    left:0;
    font-size:9px;
    color:#dfe4e9;
    white-space:nowrap;
    pointer-events:none;
}

.track-end-time{
    position:absolute;
    top:-16px;
    right:0;
    font-size:9px;
    color:#dfe4e9;
    white-space:nowrap;
    pointer-events:none;
}

.track-name{
    position:absolute;
    left:5px;
    right:5px;
    top:4px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:#fff;
    font-size:9px;
    pointer-events:none;
}

#timeReadout{
    min-width:145px;
    text-align:center;
    font-variant-numeric:tabular-nums;
    font-size:13px;
}

/* FOOTER */

#editorFooter{
    min-height:48px;
    display:flex;
    justify-content:center;
    align-items:center;
    flex-wrap:wrap;
    gap:7px;
    padding:6px 10px;
    background:#1b1e22;
    border-top:1px solid #383d44;
}

.connection-status{
    padding:6px 10px;
    border-radius:5px;
    background:#15181c;
    border:1px solid #414750;
    color:#d9dee4;
    font-size:12px;
}

/* CONTEXT MENU */

#contextMenu{
    position:fixed;
    z-index:7000;
    display:none;
    width:250px;
    padding:5px;
    border:1px solid #555b63;
    border-radius:7px;
    background:#292d33;
    box-shadow:0 12px 40px #000c;
}

#contextMenu button{
    display:block;
    width:100%;
    text-align:left;
    background:transparent;
    border:0;
}

#contextMenu button:hover{
    background:#3c424a;
}

.context-separator{
    height:1px;
    margin:5px 0;
    background:#464c54;
}

/* MODAL */

.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:8000;
    display:none;
    justify-content:center;
    align-items:center;
    padding:15px;
    background:#0009;
}

.modal{
    width:min(650px,96vw);
    max-height:92vh;
    overflow:auto;
    padding:17px;
    background:#20242a;
    border:1px solid #555b64;
    border-radius:9px;
    box-shadow:0 20px 70px #000c;
}

.modal h2{
    margin:0 0 12px;
    font-size:16px;
}

.modal-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 9px;
}

.full{
    grid-column:1/-1;
}

.field{
    display:block;
    margin:7px 0;
    color:#c5cad0;
    font-size:12px;
}

.modal-footer{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:13px;
}

.help{
    color:#999fa8;
    font-size:12px;
    line-height:1.55;
}

.badge{
    display:inline-block;
    padding:2px 6px;
    border-radius:4px;
    background:#30363d;
    color:#c7cdd4;
    font-size:10px;
}

@media(max-width:850px){
    #editorProjectName{
        width:150px;
    }

    #timeline{
        height:210px;
    }
}

@media(max-width:600px){
    .editor-top{
        overflow-x:auto;
    }

    .editor-top button{
        flex-shrink:0;
    }

    .editor-top input{
        flex-shrink:0;
        width:150px;
    }

    .timeline-row{
        grid-template-columns:55px 1fr;
    }
}
</style>
</head>

<body>

<header>
    <h1>動画上直接編集ツール</h1>
    <div id="status">待機中</div>
</header>

<div id="message"></div>

<section id="home">
    <div class="home-card">
        <h2>動画を編集</h2>

        <p>
            動画を読み込んでから編集を開始します。
            編集対象は動画画面上で直接操作できます。
            動画本体はブラウザのIndexedDBへ保存され、
            編集データはサーバーまたはローカルへ保存できます。
        </p>

        <div class="home-actions">
            <button id="newVideoBtn" class="primary">
                動画ファイルを読み込む
            </button>

            <button id="recordScreenBtn">
                画面を録画
            </button>

            <button id="manageBtn">
                保存データ管理
            </button>
        </div>

        <input
            id="videoFile"
            type="file"
            accept="video/*"
            class="hidden"
        >

        <div id="projectList"></div>
    </div>
</section>

<section id="recorder">

    <div id="previewWrap">
        <video
            id="preview"
            autoplay
            muted
            playsinline
        ></video>
    </div>

    <div id="recordToolbar">

        <label>
            <input id="systemAudio" type="checkbox">
            画面音声
        </label>

        <label>
            <input id="microphone" type="checkbox">
            マイク
        </label>

        <span id="timer">00:00:00</span>

        <button id="startRecord" class="primary">
            録画開始
        </button>

        <button id="pauseRecord" disabled>
            一時停止
        </button>

        <button id="stopRecord" class="danger" disabled>
            停止
        </button>

        <button id="recordCancel">
            戻る
        </button>

    </div>
</section>

<section id="editor">

    <div class="editor-top">

        <button id="backHome">
            閉じる
        </button>

        <input
            id="editorProjectName"
            maxlength="120"
            placeholder="プロジェクト名"
        >

        <button id="saveServer" class="success">
            保存
        </button>

        <button id="saveLocal">
            ローカル保存
        </button>

        <button id="exportJson">
            プロジェクト書き出し
        </button>

        <button id="importJson">
            プロジェクト読み込み
        </button>

        <input
            id="jsonFile"
            type="file"
            accept=".json,application/json"
            class="hidden"
        >

        <span id="editorStatus"></span>
    </div>

    <div class="editor-main">

        <div class="video-area" id="videoArea">

            <div id="videoStage">

                <video
                    id="recordedVideo"
                    playsinline
                    preload="metadata"
                ></video>

                <div id="overlay">
                    <svg id="connectors"></svg>
                    <div id="objects"></div>
                </div>

            </div>
        </div>

    </div>

    <div id="timeline">

        <div class="seek-wrap">
            <input
                id="seek"
                type="range"
                min="0"
                max="0"
                value="0"
                step=".01"
                disabled
            >
        </div>

        <div id="timelineScale" class="timeline-scale"></div>

        <div class="timeline-row">
            <span>コメント</span>
            <div id="commentTrack" class="track"></div>
        </div>

        <div class="timeline-row">
            <span>強調枠</span>
            <div id="boxTrack" class="track"></div>
        </div>

        <div class="timeline-row">
            <span>スキップ</span>
            <div id="skipTrack" class="track"></div>
        </div>

        <div class="timeline-row">
            <span>接続線</span>
            <div id="connectionTrack" class="track"></div>
        </div>

    </div>

    <div id="editorFooter">

        <button id="playVideo" class="primary">
            再生
        </button>

        <button id="pauseVideo">
            停止
        </button>

        <span id="timeReadout">
            00:00.00 / 00:00.00
        </span>

        <button id="startPoint">
            開始を現在位置
        </button>

        <button id="endPoint">
            終了を現在位置
        </button>

        <button id="connectMode">
            接続モード
        </button>

        <span
            id="connectionStatus"
            class="connection-status"
        >
            通常操作
        </span>

    </div>
</section>

<!-- コンテキストメニュー -->

<div id="contextMenu">

    <button data-action="add-comment">
        ＋コメントを追加
    </button>

    <button data-action="add-box">
        ＋強調枠を追加
    </button>

    <button data-action="add-skip">
        ＋スキップを追加
    </button>

    <div class="context-separator"></div>

    <button data-action="style">
        書式・内容・時間を変更
    </button>

    <button data-action="duplicate">
        複製
    </button>

    <button data-action="delete">
        削除
    </button>

</div>

<!-- 要素設定 -->

<div id="objectModal" class="modal-backdrop">

    <div class="modal">

        <h2 id="objectModalTitle">
            要素の設定
        </h2>

        <div class="modal-grid">

            <label
                id="mTextField"
                class="field full"
            >
                内容
                <textarea id="mText"></textarea>
            </label>

            <label class="field">
                開始秒
                <input
                    id="mStart"
                    type="number"
                    min="0"
                    step=".01"
                >
            </label>

            <label class="field">
                終了秒
                <input
                    id="mEnd"
                    type="number"
                    min="0"
                    step=".01"
                >
            </label>

            <label class="field">
                文字色
                <input
                    id="mColor"
                    type="color"
                    value="#ffffff"
                >
            </label>

            <label class="field">
                背景色
                <input
                    id="mBg"
                    type="color"
                    value="#000000"
                >
            </label>

            <label class="field">
                文字サイズ
                <input
                    id="mFontSize"
                    type="number"
                    min="8"
                    max="200"
                    value="24"
                >
            </label>

            <label class="field">
                不透明度 %
                <input
                    id="mOpacity"
                    type="number"
                    min="0"
                    max="100"
                    value="100"
                >
            </label>

            <label class="field">
                枠色
                <input
                    id="mBorderColor"
                    type="color"
                    value="#ffffff"
                >
            </label>

            <label class="field">
                枠幅
                <input
                    id="mBorderWidth"
                    type="number"
                    min="0"
                    max="30"
                    value="0"
                >
            </label>

            <label class="field">
                角丸
                <input
                    id="mRadius"
                    type="number"
                    min="0"
                    max="100"
                    value="4"
                >
            </label>

            <label class="field">
                太さ
                <select id="mWeight">
                    <option value="400">標準</option>
                    <option value="500">やや太い</option>
                    <option value="600" selected>太字</option>
                    <option value="700">かなり太い</option>
                    <option value="900">極太</option>
                </select>
            </label>

            <label class="field">
                X %
                <input
                    id="mX"
                    type="number"
                    min="0"
                    max="99"
                    step=".1"
                >
            </label>

            <label class="field">
                Y %
                <input
                    id="mY"
                    type="number"
                    min="0"
                    max="99"
                    step=".1"
                >
            </label>

            <label class="field">
                幅 %
                <input
                    id="mW"
                    type="number"
                    min=".5"
                    max="100"
                    step=".1"
                >
            </label>

            <label class="field">
                高さ %
                <input
                    id="mH"
                    type="number"
                    min=".5"
                    max="100"
                    step=".1"
                >
            </label>

        </div>

        <div class="modal-footer">
            <button id="cancelObjectStyle">
                キャンセル
            </button>
            <button
                id="applyObjectStyle"
                class="primary"
            >
                適用
            </button>
        </div>

    </div>
</div>

<!-- 結線設定 -->

<div id="connectionModal" class="modal-backdrop">

    <div class="modal">

        <h2>
            接続線の設定
        </h2>

        <div class="modal-grid">

            <label class="field">
                開始秒
                <input
                    id="cStart"
                    type="number"
                    min="0"
                    step=".01"
                >
            </label>

            <label class="field">
                終了秒
                <input
                    id="cEnd"
                    type="number"
                    min="0"
                    step=".01"
                >
            </label>

            <label class="field">
                線色
                <input
                    id="cColor"
                    type="color"
                    value="#ffffff"
                >
            </label>

            <label class="field">
                線幅
                <input
                    id="cWidth"
                    type="number"
                    min="1"
                    max="20"
                    value="3"
                >
            </label>

            <label class="field">
                線種
                <select id="cDash">
                    <option value="">実線</option>
                    <option value="8 5">破線</option>
                    <option value="2 5">点線</option>
                </select>
            </label>

            <label class="field">
                開始端
                <select id="cStartArrow">
                    <option value="">なし</option>
                    <option value="arrow">矢印</option>
                    <option value="circle">丸</option>
                </select>
            </label>

            <label class="field">
                終端
                <select id="cEndArrow">
                    <option value="arrow" selected>矢印</option>
                    <option value="">なし</option>
                    <option value="circle">丸</option>
                </select>
            </label>

            <label class="field">
                曲がり
                <input
                    id="cCurve"
                    type="number"
                    min="0"
                    max="200"
                    value="35"
                >
            </label>

        </div>

        <div class="modal-footer">
            <button id="cancelConnection">
                キャンセル
            </button>
            <button
                id="applyConnection"
                class="primary"
            >
                適用
            </button>
        </div>

    </div>
</div>

<script>
'use strict';

/* =========================================================
 * DOM / 基本
 * ======================================================= */

const $ = id => document.getElementById(id);

const APP = {
    version: 6,
    dbName: 'video_direct_editor_v6',
    dbVersion: 1
};

const state = {
    db: null,
    project: null,
    videoBlob: null,
    videoUrl: '',
    selected: null,
    selectedType: null,
    contextTarget: null,
    connectMode: false,
    connectSource: null,
    drag: null,
    dirty: false,
    messageTimer: null,
    recordStream: null,
    recorder: null,
    recordChunks: [],
    recordTimer: null,
    recordStartedAt: 0,
    modalType: null
};

/* =========================================================
 * 共通
 * ======================================================= */

function clone(value){
    return JSON.parse(JSON.stringify(value));
}

function uid(prefix = 'id'){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,10);
}

function number(value, fallback = 0){
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
}

function clamp(value, min, max){
    return Math.min(max, Math.max(min, value));
}

function nowISO(){
    return new Date().toISOString();
}

function formatTime(seconds){
    const n = Math.max(0, number(seconds));
    const min = Math.floor(n / 60);
    const sec = n - min * 60;

    return String(min).padStart(2,'0') +
        ':' +
        sec.toFixed(2).padStart(5,'0');
}

function currentTime(){
    return number(
        $('recordedVideo')?.currentTime,
        0
    );
}

function duration(){
    const video = $('recordedVideo');

    if (
        video &&
        Number.isFinite(video.duration) &&
        video.duration > 0
    ) {
        return video.duration;
    }

    return number(
        state.project?.duration,
        0
    );
}

function showMessage(text, ok = false){
    const box = $('message');

    if (!box) return;

    clearTimeout(state.messageTimer);

    box.textContent = text;
    box.classList.toggle('ok', ok);
    box.style.display = 'block';

    state.messageTimer = setTimeout(() => {
        box.style.display = 'none';
    }, 3500);
}

function setStatus(text){
    if ($('status')) {
        $('status').textContent = text;
    }

    if ($('editorStatus')) {
        $('editorStatus').textContent = text;
    }
}

function markDirty(){
    state.dirty = true;

    if ($('editorStatus')) {
        $('editorStatus').textContent = '未保存';
    }
}

/* =========================================================
 * PROJECT
 * ======================================================= */

function createEmptyProject(name, videoName){
    return {
        version: APP.version,
        projectId: uid('project'),
        name: name || '名称未設定',
        videoName: videoName || '',
        videoKey: '',
        duration: 0,
        createdAt: nowISO(),
        savedAt: '',
        elements: [],
        connections: []
    };
}

function normalizeProject(project){
    const p = clone(project || createEmptyProject());

    p.version = APP.version;
    p.projectId =
        String(p.projectId || uid('project'));

    p.name =
        String(p.name || '名称未設定');

    p.videoName =
        String(p.videoName || '');

    p.duration =
        Math.max(0, number(p.duration));

    p.elements =
        Array.isArray(p.elements)
            ? p.elements
            : [];

    p.connections =
        Array.isArray(p.connections)
            ? p.connections
            : [];

    p.elements = p.elements.map(e => ({
        id: String(e.id || uid('element')),
        type: ['comment','box','skip'].includes(e.type)
            ? e.type
            : 'comment',
        text: String(e.text || ''),
        x: clamp(number(e.x,10),0,99),
        y: clamp(number(e.y,10),0,99),
        w: clamp(number(e.w,25),.5,100),
        h: clamp(number(e.h,15),.5,100),
        start: Math.max(0, number(e.start,0)),
        end: Math.max(0, number(e.end,5)),
        style: {
            color: e.style?.color || '#ffffff',
            background: e.style?.background || '#000000',
            fontSize: number(e.style?.fontSize,24),
            opacity: number(e.style?.opacity,100),
            borderColor: e.style?.borderColor || '#ffffff',
            borderWidth: number(e.style?.borderWidth,0),
            radius: number(e.style?.radius,4),
            fontWeight: number(e.style?.fontWeight,600)
        }
    }));

    p.connections = p.connections.map(c => ({
        id: String(c.id || uid('connection')),
        from: String(c.from || ''),
        to: String(c.to || ''),
        fromPoint: c.fromPoint || 'e',
        toPoint: c.toPoint || 'w',
        start: Math.max(0, number(c.start,0)),
        end: Math.max(0, number(c.end,5)),
        color: c.color || '#ffffff',
        width: number(c.width,3),
        dash: c.dash || '',
        startArrow: c.startArrow || '',
        endArrow: c.endArrow || 'arrow',
        curve: number(c.curve,35)
    }));

    const d = p.duration || 0;

    p.elements.forEach(e => {
        e.end = clamp(
            Math.max(e.start, e.end),
            e.start,
            d || 999999
        );
    });

    p.connections.forEach(c => {
        c.end = Math.max(c.start, c.end);
    });

    return p;
}

/* =========================================================
 * INDEXED DB
 * ======================================================= */

function openDB(){
    return new Promise((resolve,reject)=>{
        const request = indexedDB.open(
            APP.dbName,
            APP.dbVersion
        );

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains('projects')) {
                db.createObjectStore(
                    'projects',
                    {keyPath:'projectId'}
                );
            }

            if (!db.objectStoreNames.contains('videos')) {
                db.createObjectStore(
                    'videos',
                    {keyPath:'key'}
                );
            }
        };

        request.onsuccess = () => {
            state.db = request.result;
            resolve(state.db);
        };

        request.onerror = () => {
            reject(request.error);
        };
    });
}

function idbPut(store, value){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(
            store,
            'readwrite'
        );

        tx.objectStore(store).put(value);

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

function idbGet(store, key){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(
            store,
            'readonly'
        );

        const req =
            tx.objectStore(store).get(key);

        req.onsuccess =
            () => resolve(req.result || null);

        req.onerror =
            () => reject(req.error);
    });
}

function idbGetAll(store){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(
            store,
            'readonly'
        );

        const req =
            tx.objectStore(store).getAll();

        req.onsuccess =
            () => resolve(req.result || []);

        req.onerror =
            () => reject(req.error);
    });
}

function idbDelete(store,key){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(
            store,
            'readwrite'
        );

        tx.objectStore(store).delete(key);

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

/* =========================================================
 * VIDEO
 * ======================================================= */

async function saveVideoBlob(blob,name,type){
    const key = uid('video');

    await idbPut('videos',{
        key,
        name,
        type: type || blob.type || 'video/mp4',
        size: blob.size,
        savedAt: nowISO(),
        blob
    });

    return key;
}

function chooseVideo(){
    $('videoFile')?.click();
}

async function handleVideoFile(file){
    if (!file) return;

    if (!file.type.startsWith('video/')) {
        showMessage('動画ファイルを選択してください。');
        return;
    }

    try{
        setStatus('動画を読み込んでいます…');

        const key = await saveVideoBlob(
            file,
            file.name,
            file.type
        );

        const project =
            createEmptyProject(
                file.name.replace(/\.[^.]+$/,''),
                file.name
            );

        project.videoKey = key;

        await openEditor(project,file);

        showMessage(
            '動画の読み込みが完了しました。編集できます。',
            true
        );
    }catch(error){
        console.error(error);

        showMessage(
            '動画を読み込めませんでした：' +
            error.message
        );

        setStatus('読み込み失敗');
    }
}

/* =========================================================
 * EDITOR OPEN
 * ======================================================= */

function waitVideoMetadata(){
    const video = $('recordedVideo');

    if (
        video.readyState >= 1 &&
        Number.isFinite(video.duration)
    ) {
        return Promise.resolve();
    }

    return new Promise((resolve,reject)=>{
        const timeout =
            setTimeout(
                () => reject(
                    new Error(
                        '動画のメタデータ取得がタイムアウトしました。'
                    )
                ),
                15000
            );

        const onLoaded = () => {
            clearTimeout(timeout);
            video.removeEventListener(
                'loadedmetadata',
                onLoaded
            );
            resolve();
        };

        video.addEventListener(
            'loadedmetadata',
            onLoaded,
            {once:true}
        );

        video.addEventListener(
            'error',
            () => {
                clearTimeout(timeout);
                reject(
                    new Error(
                        '動画を再生できません。'
                    )
                );
            },
            {once:true}
        );
    });
}

async function openEditor(project,blob){
    if (!blob && project.videoKey) {
        const record =
            await idbGet(
                'videos',
                project.videoKey
            );

        if (record) {
            blob = record.blob;
        }
    }

    if (!blob) {
        throw new Error(
            '編集対象の動画本体が見つかりません。'
        );
    }

    state.project =
        normalizeProject(project);

    state.videoBlob = blob;

    if (state.videoUrl) {
        URL.revokeObjectURL(
            state.videoUrl
        );
    }

    state.videoUrl =
        URL.createObjectURL(blob);

    const video = $('recordedVideo');

    video.pause();
    video.src = state.videoUrl;
    video.load();

    $('home').style.display = 'none';
    $('recorder').style.display = 'none';
    $('editor').style.display = 'flex';

    $('editorProjectName').value =
        state.project.name;

    state.selected = null;
    state.selectedType = null;
    state.connectSource = null;
    state.connectMode = false;
    state.dirty = false;

    $('seek').disabled = true;

    setStatus('動画を確認しています…');
    updateConnectionStatus();

    await waitVideoMetadata();

    const actualDuration =
        Number($('recordedVideo').duration);

    if (
        !Number.isFinite(actualDuration) ||
        actualDuration <= 0
    ) {
        throw new Error(
            '動画の長さを取得できませんでした。'
        );
    }

    /*
     * 動画そのもののdurationを唯一の基準にする。
     */
    state.project.duration =
        actualDuration;

    $('seek').min = '0';
    $('seek').max =
        String(actualDuration);
    $('seek').step = '.01';
    $('seek').value = '0';
    $('seek').disabled = false;

    /*
     * 既存要素の時間を動画長以内に補正。
     */
    state.project.elements.forEach(e=>{
        e.start =
            clamp(e.start,0,actualDuration);

        e.end =
            clamp(
                Math.max(e.start,e.end),
                e.start,
                actualDuration
            );
    });

    state.project.connections.forEach(c=>{
        c.start =
            clamp(c.start,0,actualDuration);

        c.end =
            clamp(
                Math.max(c.start,c.end),
                c.start,
                actualDuration
            );
    });

    updateStageSize();
    renderAll();

    setStatus('編集可能');
}

/* =========================================================
 * STAGE SIZE
 * ======================================================= */

function updateStageSize(){
    const video = $('recordedVideo');
    const area = $('videoArea');
    const stage = $('videoStage');

    if (
        !video ||
        !area ||
        !stage ||
        !video.videoWidth ||
        !video.videoHeight
    ) {
        return;
    }

    const ratio =
        video.videoWidth /
        video.videoHeight;

    const maxW =
        Math.max(100,area.clientWidth - 20);

    const maxH =
        Math.max(100,area.clientHeight - 20);

    let w = maxW;
    let h = w / ratio;

    if (h > maxH) {
        h = maxH;
        w = h * ratio;
    }

    stage.style.width = `${w}px`;
    stage.style.height = `${h}px`;
}

/* =========================================================
 * VISIBILITY
 * ======================================================= */

function visible(item,time){
    return (
        time >= number(item.start) &&
        time <= number(item.end)
    );
}

/* =========================================================
 * ELEMENTS
 * ======================================================= */

function addElement(type,x,y){
    if (!state.project) return;

    const d = duration();

    const start =
        clamp(
            currentTime(),
            0,
            d
        );

    const end =
        clamp(
            start + Math.min(5,Math.max(1,d-start)),
            start,
            d
        );

    const element = {
        id: uid('element'),
        type,
        text:
            type === 'comment'
                ? 'ここにコメント'
                : '',
        x:
            number(x,20),
        y:
            number(y,20),
        w:
            type === 'box'
                ? 35
                : type === 'skip'
                    ? 30
                    : 28,
        h:
            type === 'box'
                ? 25
                : type === 'skip'
                    ? 20
                    : 15,
        start,
        end,
        style:{
            color:
                type === 'skip'
                    ? '#ffffff'
                    : '#ffffff',
            background:
                type === 'comment'
                    ? '#000000'
                    : 'transparent',
            fontSize:24,
            opacity:
                type === 'comment'
                    ? 85
                    : 100,
            borderColor:
                type === 'box'
                    ? '#ff4040'
                    : '#ffffff',
            borderWidth:
                type === 'box'
                    ? 3
                    : 0,
            radius:4,
            fontWeight:600
        }
    };

    element.x =
        clamp(element.x,0,100-element.w);

    element.y =
        clamp(element.y,0,100-element.h);

    state.project.elements.push(element);

    selectElement(element.id);
    markDirty();
    renderAll();

    showMessage(
        '要素を追加しました。右クリックで内容・書式を変更できます。',
        true
    );
}

/* =========================================================
 * ELEMENT SELECTION
 * ======================================================= */

function selectElement(id){
    state.selected = id;
    state.selectedType = 'element';
    renderAll();
}

function selectConnection(id){
    state.selected = id;
    state.selectedType = 'connection';
    renderAll();
}

function selectNone(){
    state.selected = null;
    state.selectedType = null;
    state.connectSource = null;
    renderAll();
}

/* =========================================================
 * ELEMENT RENDER
 * ======================================================= */

function renderObjects(){
    const container = $('objects');

    if (!container) return;

    container.innerHTML = '';

    if (!state.project) return;

    const time = currentTime();

    state.project.elements.forEach(element=>{
        if (!visible(element,time)) {
            return;
        }

        const div =
            document.createElement('div');

        div.className =
            'edit-object ' +
            element.type +
            (
                state.selectedType === 'element' &&
                state.selected === element.id
                    ? ' selected'
                    : ''
            ) +
            (
                state.connectSource?.id === element.id
                    ? ' connect-source'
                    : ''
            );

        div.dataset.id = element.id;

        div.style.left =
            `${element.x}%`;

        div.style.top =
            `${element.y}%`;

        div.style.width =
            `${element.w}%`;

        div.style.height =
            `${element.h}%`;

        const s = element.style || {};

        div.style.color =
            s.color || '#fff';

        div.style.backgroundColor =
            element.type === 'box'
                ? 'transparent'
                : element.type === 'skip'
                    ? 'rgba(227,139,40,.10)'
                    : s.background || '#000';

        div.style.opacity =
            clamp(number(s.opacity,100),0,100)/100;

        div.style.fontSize =
            `${number(s.fontSize,24)}px`;

        div.style.fontWeight =
            number(s.fontWeight,600);

        div.style.borderColor =
            s.borderColor || '#fff';

        div.style.borderWidth =
            `${number(s.borderWidth,0)}px`;

        div.style.borderStyle =
            element.type === 'skip'
                ? 'dashed'
                : 'solid';

        div.style.borderRadius =
            `${number(s.radius,4)}px`;

        if (element.type === 'comment') {
            div.textContent =
                element.text || 'コメント';
        }

        if (element.type === 'skip') {
            const label =
                document.createElement('span');

            label.className = 'skip-label';
            label.textContent = 'スキップ';

            div.appendChild(label);
        }

        if (
            state.selectedType === 'element' &&
            state.selected === element.id
        ) {
            const resize =
                document.createElement('span');

            resize.className = 'resize-handle';

            div.appendChild(resize);
        }

        /*
         * 8方向の接点。
         */
        [
            'n',
            'ne',
            'e',
            'se',
            's',
            'sw',
            'w',
            'nw'
        ].forEach(point=>{
            const cp =
                document.createElement('span');

            cp.className =
                'connection-point cp-' + point;

            cp.dataset.point = point;

            div.appendChild(cp);
        });

        container.appendChild(div);
    });
}

/* =========================================================
 * CONNECTION GEOMETRY
 * ======================================================= */

function pointPosition(element,point){
    const x = number(element.x);
    const y = number(element.y);
    const w = number(element.w);
    const h = number(element.h);

    const map = {
        n:[x+w/2,y],
        ne:[x+w,y],
        e:[x+w,y+h/2],
        se:[x+w,y+h],
        s:[x+w/2,y+h],
        sw:[x,y+h],
        w:[x,y+h/2],
        nw:[x,y]
    };

    const p = map[point] || map.e;

    return {
        x:p[0],
        y:p[1]
    };
}

function buildConnectionPath(p1,p2,curve){
    const dx = p2.x-p1.x;
    const dy = p2.y-p1.y;
    const c = Math.max(
        5,
        Math.min(
            200,
            number(curve,35)
        )
    );

    const cp1 = {
        x:p1.x + dx*.35,
        y:p1.y + dy*.35
    };

    const cp2 = {
        x:p2.x - dx*.35,
        y:p2.y - dy*.35
    };

    if (Math.abs(dx) > Math.abs(dy)) {
        cp1.x =
            p1.x +
            (dx >= 0 ? c : -c);

        cp2.x =
            p2.x -
            (dx >= 0 ? c : -c);
    } else {
        cp1.y =
            p1.y +
            (dy >= 0 ? c : -c);

        cp2.y =
            p2.y -
            (dy >= 0 ? c : -c);
    }

    return `
        M ${p1.x} ${p1.y}
        C
        ${cp1.x} ${cp1.y},
        ${cp2.x} ${cp2.y},
        ${p2.x} ${p2.y}
    `;
}

/* =========================================================
 * CONNECTION RENDER
 * ======================================================= */

function renderConnections(){
    const svg = $('connectors');

    if (!svg) return;

    svg.innerHTML = '';

    if (!state.project) return;

    svg.setAttribute(
        'viewBox',
        '0 0 100 100'
    );

    svg.setAttribute(
        'preserveAspectRatio',
        'none'
    );

    const defs =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'defs'
        );

    defs.innerHTML = `
        <marker
            id="arrow"
            markerWidth="10"
            markerHeight="10"
            refX="9"
            refY="5"
            orient="auto"
        >
            <path
                d="M0,0 L10,5 L0,10 z"
                fill="context-stroke"
            />
        </marker>

        <marker
            id="circle"
            markerWidth="8"
            markerHeight="8"
            refX="4"
            refY="4"
        >
            <circle
                cx="4"
                cy="4"
                r="3"
                fill="context-stroke"
            />
        </marker>
    `;

    svg.appendChild(defs);

    const time = currentTime();

    state.project.connections.forEach(connection=>{
        if (!visible(connection,time)) {
            return;
        }

        const from =
            state.project.elements.find(
                e => e.id === connection.from
            );

        const to =
            state.project.elements.find(
                e => e.id === connection.to
            );

        if (!from || !to) return;

        const p1 =
            pointPosition(
                from,
                connection.fromPoint
            );

        const p2 =
            pointPosition(
                to,
                connection.toPoint
            );

        const d =
            buildConnectionPath(
                p1,
                p2,
                connection.curve
            );

        const path =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'path'
            );

        path.setAttribute('d',d);
        path.setAttribute(
            'class',
            'connector'
        );

        path.setAttribute(
            'stroke',
            connection.color || '#fff'
        );

        path.setAttribute(
            'stroke-width',
            number(connection.width,3)
        );

        if (connection.dash) {
            path.setAttribute(
                'stroke-dasharray',
                connection.dash
            );
        }

        if (
            connection.startArrow === 'arrow'
        ) {
            path.setAttribute(
                'marker-start',
                'url(#arrow)'
            );
        }

        if (
            connection.startArrow === 'circle'
        ) {
            path.setAttribute(
                'marker-start',
                'url(#circle)'
            );
        }

        if (
            connection.endArrow === 'arrow'
        ) {
            path.setAttribute(
                'marker-end',
                'url(#arrow)'
            );
        }

        if (
            connection.endArrow === 'circle'
        ) {
            path.setAttribute(
                'marker-end',
                'url(#circle)'
            );
        }

        if (
            state.selectedType === 'connection' &&
            state.selected === connection.id
        ) {
            path.classList.add('selected');
            path.setAttribute(
                'stroke-width',
                number(connection.width,3)+2
            );
        }

        svg.appendChild(path);

        /*
         * 太い透明な当たり判定。
         * 線を右クリックしやすくする。
         */
        const hit =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'path'
            );

        hit.setAttribute('d',d);
        hit.setAttribute(
            'class',
            'connector-hit'
        );

        hit.dataset.id =
            connection.id;

        svg.appendChild(hit);
    });
}

/* =========================================================
 * TIMELINE
 * ======================================================= */

function renderTimeline(){
    if (!state.project) return;

    const d = duration();

    if (d <= 0) return;

    renderTimelineScale(d);

    renderTrack(
        $('commentTrack'),
        state.project.elements.filter(
            e => e.type === 'comment'
        ),
        'comment',
        d
    );

    renderTrack(
        $('boxTrack'),
        state.project.elements.filter(
            e => e.type === 'box'
        ),
        'box',
        d
    );

    renderTrack(
        $('skipTrack'),
        state.project.elements.filter(
            e => e.type === 'skip'
        ),
        'skip',
        d
    );

    renderTrack(
        $('connectionTrack'),
        state.project.connections,
        'connection',
        d
    );
}

function renderTimelineScale(d){
    const scale = $('timelineScale');

    if (!scale) return;

    scale.innerHTML = '';

    const count =
        Math.max(
            2,
            Math.min(
                12,
                Math.ceil(d / 5) + 1
            )
        );

    for(let i=0;i<count;i++){
        const ratio =
            count === 1
                ? 0
                : i/(count-1);

        const span =
            document.createElement('span');

        span.style.left =
            `${ratio*100}%`;

        span.textContent =
            formatTime(d*ratio);

        scale.appendChild(span);
    }
}

function renderTrack(track,items,type,d){
    if (!track) return;

    track.innerHTML = '';

    items.forEach(item=>{
        const start =
            clamp(number(item.start),0,d);

        const end =
            clamp(
                Math.max(start,number(item.end)),
                start,
                d
            );

        const width =
            Math.max(
                .4,
                (end-start)/d*100
            );

        const left =
            start/d*100;

        const bar =
            document.createElement('div');

        bar.className =
            `track-item ${type}` +
            (
                state.selected === item.id &&
                state.selectedType ===
                    (type === 'connection'
                        ? 'connection'
                        : 'element')
                    ? ' selected'
                    : ''
            );

        bar.style.left =
            `${left}%`;

        bar.style.width =
            `${width}%`;

        bar.dataset.id =
            item.id;

        const startLabel =
            document.createElement('span');

        startLabel.className =
            'track-time';

        startLabel.textContent =
            formatTime(start);

        const endLabel =
            document.createElement('span');

        endLabel.className =
            'track-end-time';

        endLabel.textContent =
            formatTime(end);

        bar.appendChild(startLabel);
        bar.appendChild(endLabel);

        if (type !== 'connection') {
            const name =
                document.createElement('span');

            name.className =
                'track-name';

            name.textContent =
                item.type === 'comment'
                    ? (
                        item.text ||
                        'コメント'
                    )
                    : item.type === 'box'
                        ? '強調枠'
                        : 'スキップ';

            bar.appendChild(name);
        } else {
            const name =
                document.createElement('span');

            name.className =
                'track-name';

            name.textContent =
                '接続線';

            bar.appendChild(name);
        }

        bar.addEventListener(
            'click',
            event=>{
                event.stopPropagation();

                if (type === 'connection') {
                    selectConnection(item.id);
                } else {
                    selectElement(item.id);
                }

                $('recordedVideo').currentTime =
                    start;
            }
        );

        bar.addEventListener(
            'dblclick',
            event=>{
                event.stopPropagation();

                if (type === 'connection') {
                    openConnectionModal(item.id);
                } else {
                    openObjectModal(item.id);
                }
            }
        );

        track.appendChild(bar);
    });
}

/* =========================================================
 * SEEK / TIME
 * ======================================================= */

function updateTimeReadout(){
    const video = $('recordedVideo');
    const d = duration();

    if (!video) return;

    $('timeReadout').textContent =
        formatTime(video.currentTime) +
        ' / ' +
        formatTime(d);

    if (
        d > 0 &&
        $('seek')
    ) {
        $('seek').min = '0';
        $('seek').max = String(d);
        $('seek').value =
            clamp(
                number(video.currentTime),
                0,
                d
            );
    }
}

/* =========================================================
 * DRAG / RESIZE
 * ======================================================= */

function beginElementPointer(event){
    const object =
        event.target.closest(
            '.edit-object'
        );

    if (!object) return;

    const element =
        state.project.elements.find(
            e => e.id === object.dataset.id
        );

    if (!element) return;

    if (
        event.target.closest(
            '.connection-point'
        )
    ) {
        return;
    }

    if (
        event.target.closest(
            '.resize-handle'
        )
    ) {
        selectElement(element.id);

        const rect =
            $('videoStage').getBoundingClientRect();

        state.drag = {
            mode:'resize',
            id:element.id,
            startX:event.clientX,
            startY:event.clientY,
            originalW:element.w,
            originalH:element.h,
            stageW:rect.width,
            stageH:rect.height
        };

        object.setPointerCapture(
            event.pointerId
        );

        event.preventDefault();
        return;
    }

    if (state.connectMode) {
        selectElement(element.id);

        showMessage(
            '青い接点をクリックしてください。'
        );

        return;
    }

    selectElement(element.id);

    const rect =
        $('videoStage').getBoundingClientRect();

    state.drag = {
        mode:'move',
        id:element.id,
        startX:event.clientX,
        startY:event.clientY,
        originalX:element.x,
        originalY:element.y,
        stageW:rect.width,
        stageH:rect.height
    };

    object.setPointerCapture(
        event.pointerId
    );

    event.preventDefault();
}

function moveElementPointer(event){
    if (!state.drag) return;

    const element =
        state.project.elements.find(
            e => e.id === state.drag.id
        );

    if (!element) {
        state.drag = null;
        return;
    }

    const dx =
        event.clientX -
        state.drag.startX;

    const dy =
        event.clientY -
        state.drag.startY;

    if (state.drag.mode === 'move') {
        element.x =
            clamp(
                state.drag.originalX +
                dx/state.drag.stageW*100,
                0,
                100-element.w
            );

        element.y =
            clamp(
                state.drag.originalY +
                dy/state.drag.stageH*100,
                0,
                100-element.h
            );
    } else {
        element.w =
            clamp(
                state.drag.originalW +
                dx/state.drag.stageW*100,
                .5,
                100-element.x
            );

        element.h =
            clamp(
                state.drag.originalH +
                dy/state.drag.stageH*100,
                .5,
                100-element.y
            );
    }

    markDirty();
    renderAll();
}

function endElementPointer(){
    state.drag = null;
}

/* =========================================================
 * CONNECTION OPERATION
 * ======================================================= */

function updateConnectionStatus(){
    const status =
        $('connectionStatus');

    const button =
        $('connectMode');

    if (!status || !button) return;

    if (!state.connectMode) {
        status.textContent =
            '通常操作';

        button.classList.remove(
            'active'
        );

        return;
    }

    button.classList.add('active');

    if (state.connectSource) {
        status.textContent =
            '接続元選択済み：次に接続先の青い接点をクリック';
    } else {
        status.textContent =
            '接続モード：黄色にしたい接続元の青い接点をクリック';
    }
}

function handleConnectionPoint(
    element,
    point
){
    if (!state.connectMode) {
        selectElement(element.id);
        return;
    }

    if (!state.connectSource) {
        state.connectSource = {
            id:element.id,
            point
        };

        selectElement(element.id);
        updateConnectionStatus();

        showMessage(
            '接続元を設定しました。次に接続先の青い接点をクリックしてください。',
            true
        );

        renderAll();
        return;
    }

    if (
        state.connectSource.id === element.id &&
        state.connectSource.point === point
    ) {
        state.connectSource = null;
        updateConnectionStatus();
        renderAll();
        return;
    }

    const d = duration();

    const connection = {
        id:uid('connection'),
        from:state.connectSource.id,
        to:element.id,
        fromPoint:state.connectSource.point,
        toPoint:point,
        start:clamp(currentTime(),0,d),
        end:clamp(
            currentTime()+5,
            currentTime(),
            d
        ),
        color:'#ffffff',
        width:3,
        dash:'',
        startArrow:'',
        endArrow:'arrow',
        curve:35
    };

    state.project.connections.push(
        connection
    );

    state.connectSource = null;
    state.connectMode = false;

    state.selected =
        connection.id;

    state.selectedType =
        'connection';

    updateConnectionStatus();
    markDirty();
    renderAll();

    showMessage(
        '接続線を作成しました。',
        true
    );
}

/* =========================================================
 * CONTEXT MENU
 * ======================================================= */

function showContextMenu(x,y){
    const menu =
        $('contextMenu');

    if (!menu) return;

    menu.style.display =
        'block';

    requestAnimationFrame(()=>{
        const rect =
            menu.getBoundingClientRect();

        menu.style.left =
            Math.max(
                5,
                Math.min(
                    x,
                    window.innerWidth -
                    rect.width -
                    5
                )
            ) + 'px';

        menu.style.top =
            Math.max(
                5,
                Math.min(
                    y,
                    window.innerHeight -
                    rect.height -
                    5
                )
            ) + 'px';
    });
}

function hideContextMenu(){
    $('contextMenu').style.display =
        'none';
}

function contextAdd(type){
    if (
        state.contextTarget?.type === 'stage'
    ) {
        addElement(
            type,
            state.contextTarget.x,
            state.contextTarget.y
        );
    } else {
        addElement(type);
    }
}

/* =========================================================
 * DUPLICATE / DELETE
 * ======================================================= */

function duplicateSelected(){
    if (!state.selected) return;

    if (
        state.selectedType ===
        'element'
    ) {
        const source =
            state.project.elements.find(
                e => e.id === state.selected
            );

        if (!source) return;

        const copy = clone(source);

        copy.id =
            uid('element');

        copy.x =
            clamp(
                copy.x + 3,
                0,
                100-copy.w
            );

        copy.y =
            clamp(
                copy.y + 3,
                0,
                100-copy.h
            );

        state.project.elements.push(copy);
        selectElement(copy.id);
    }

    if (
        state.selectedType ===
        'connection'
    ) {
        const source =
            state.project.connections.find(
                c => c.id === state.selected
            );

        if (!source) return;

        const copy = clone(source);

        copy.id =
            uid('connection');

        state.project.connections.push(copy);

        selectConnection(copy.id);
    }

    markDirty();
    renderAll();
}

function deleteSelected(){
    if (!state.selected) return;

    if (
        state.selectedType ===
        'element'
    ) {
        const id =
            state.selected;

        state.project.elements =
            state.project.elements.filter(
                e => e.id !== id
            );

        state.project.connections =
            state.project.connections.filter(
                c =>
                    c.from !== id &&
                    c.to !== id
            );
    }

    if (
        state.selectedType ===
        'connection'
    ) {
        state.project.connections =
            state.project.connections.filter(
                c => c.id !== state.selected
            );
    }

    selectNone();
    markDirty();
    renderAll();

    showMessage(
        '削除しました。',
        true
    );
}

/* =========================================================
 * OBJECT MODAL
 * ======================================================= */

function normalizeColor(value,fallback){
    const text =
        String(value || '');

    const match =
        text.match(
            /^#([0-9a-f]{6})$/i
        );

    return match
        ? '#' + match[1]
        : fallback;
}

function openObjectModal(id){
    const element =
        state.project.elements.find(
            e => e.id === (
                id ||
                state.selected
            )
        );

    if (!element) return;

    state.selected =
        element.id;

    state.selectedType =
        'element';

    const s =
        element.style || {};

    $('objectModalTitle').textContent =
        element.type === 'comment'
            ? 'コメント設定'
            : element.type === 'box'
                ? '強調枠設定'
                : 'スキップ設定';

    $('mText').value =
        element.text || '';

    $('mStart').value =
        element.start;

    $('mEnd').value =
        element.end;

    $('mColor').value =
        normalizeColor(
            s.color,
            '#ffffff'
        );

    $('mBg').value =
        normalizeColor(
            s.background,
            '#000000'
        );

    $('mFontSize').value =
        number(s.fontSize,24);

    $('mOpacity').value =
        number(s.opacity,100);

    $('mBorderColor').value =
        normalizeColor(
            s.borderColor,
            '#ffffff'
        );

    $('mBorderWidth').value =
        number(s.borderWidth,0);

    $('mRadius').value =
        number(s.radius,4);

    $('mWeight').value =
        String(
            number(s.fontWeight,600)
        );

    $('mX').value =
        element.x;

    $('mY').value =
        element.y;

    $('mW').value =
        element.w;

    $('mH').value =
        element.h;

    $('mTextField').style.display =
        element.type === 'comment'
            ? 'block'
            : 'none';

    $('objectModal').style.display =
        'flex';
}

function applyObjectModal(){
    const element =
        state.project.elements.find(
            e => e.id === state.selected
        );

    if (!element) return;

    const d = duration();

    let start =
        number($('mStart').value,0);

    let end =
        number($('mEnd').value,start);

    start =
        clamp(start,0,d);

    end =
        clamp(
            Math.max(start,end),
            start,
            d
        );

    element.start = start;
    element.end = end;

    element.x =
        clamp(
            number($('mX').value,element.x),
            0,
            99.5
        );

    element.y =
        clamp(
            number($('mY').value,element.y),
            0,
            99.5
        );

    element.w =
        clamp(
            number($('mW').value,element.w),
            .5,
            100-element.x
        );

    element.h =
        clamp(
            number($('mH').value,element.h),
            .5,
            100-element.y
        );

    if (element.type === 'comment') {
        element.text =
            $('mText').value;
    }

    element.style = {
        color:
            $('mColor').value,
        background:
            $('mBg').value,
        fontSize:
            clamp(
                number(
                    $('mFontSize').value,
                    24
                ),
                8,
                200
            ),
        opacity:
            clamp(
                number(
                    $('mOpacity').value,
                    100
                ),
                0,
                100
            ),
        borderColor:
            $('mBorderColor').value,
        borderWidth:
            clamp(
                number(
                    $('mBorderWidth').value,
                    0
                ),
                0,
                30
            ),
        radius:
            clamp(
                number(
                    $('mRadius').value,
                    4
                ),
                0,
                100
            ),
        fontWeight:
            number(
                $('mWeight').value,
                600
            )
    };

    $('objectModal').style.display =
        'none';

    markDirty();
    renderAll();

    showMessage(
        '要素を更新しました。',
        true
    );
}

/* =========================================================
 * CONNECTION MODAL
 * ======================================================= */

function openConnectionModal(id){
    const connection =
        state.project.connections.find(
            c => c.id === (
                id ||
                state.selected
            )
        );

    if (!connection) return;

    state.selected =
        connection.id;

    state.selectedType =
        'connection';

    $('cStart').value =
        connection.start;

    $('cEnd').value =
        connection.end;

    $('cColor').value =
        normalizeColor(
            connection.color,
            '#ffffff'
        );

    $('cWidth').value =
        number(
            connection.width,
            3
        );

    $('cDash').value =
        connection.dash || '';

    $('cStartArrow').value =
        connection.startArrow || '';

    $('cEndArrow').value =
        connection.endArrow || 'arrow';

    $('cCurve').value =
        number(
            connection.curve,
            35
        );

    $('connectionModal').style.display =
        'flex';
}

function applyConnectionModal(){
    const connection =
        state.project.connections.find(
            c => c.id === state.selected
        );

    if (!connection) return;

    const d = duration();

    const start =
        clamp(
            number(
                $('cStart').value,
                connection.start
            ),
            0,
            d
        );

    const end =
        clamp(
            Math.max(
                start,
                number(
                    $('cEnd').value,
                    connection.end
                )
            ),
            start,
            d
        );

    connection.start = start;
    connection.end = end;

    connection.color =
        $('cColor').value;

    connection.width =
        clamp(
            number(
                $('cWidth').value,
                3
            ),
            1,
            20
        );

    connection.dash =
        $('cDash').value;

    connection.startArrow =
        $('cStartArrow').value;

    connection.endArrow =
        $('cEndArrow').value;

    connection.curve =
        clamp(
            number(
                $('cCurve').value,
                35
            ),
            0,
            200
        );

    $('connectionModal').style.display =
        'none';

    markDirty();
    renderAll();

    showMessage(
        '接続線を更新しました。',
        true
    );
}

/* =========================================================
 * START / END
 * ======================================================= */

function applyCurrentSelectionTime(
    start,
    end
){
    if (!state.selected) return;

    const d = duration();

    start =
        clamp(start,0,d);

    end =
        clamp(
            Math.max(start,end),
            start,
            d
        );

    const item =
        state.selectedType ===
            'element'
            ? state.project.elements.find(
                e => e.id === state.selected
            )
            : state.project.connections.find(
                c => c.id === state.selected
            );

    if (!item) return;

    item.start = start;
    item.end = end;

    markDirty();
    renderAll();
}

/* =========================================================
 * SAVE
 * ======================================================= */

function projectForSave(){
    const project =
        clone(state.project);

    project.version =
        APP.version;

    project.name =
        $('editorProjectName')
            .value
            .trim();

    if (!project.name) {
        throw new Error(
            'プロジェクト名を入力してください。'
        );
    }

    project.savedAt =
        nowISO();

    project.duration =
        duration();

    return project;
}

async function saveLocal(){
    try{
        const project =
            projectForSave();

        await idbPut(
            'projects',
            project
        );

        state.project =
            normalizeProject(project);

        state.dirty = false;

        setStatus(
            'ローカル保存済み'
        );

        showMessage(
            'ローカルに保存しました。',
            true
        );
    }catch(error){
        showMessage(
            error.message
        );
    }
}

async function saveServer(){
    try{
        const project =
            projectForSave();

        const response =
            await fetch(
                '?api=save',
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/json'
                    },
                    body:
                        JSON.stringify(project)
                }
            );

        const result =
            await response.json();

        if (!response.ok || !result.ok) {
            if (result.limit) {
                await idbPut(
                    'projects',
                    project
                );

                state.dirty = false;

                setStatus(
                    'サーバー上限のためローカル保存'
                );

                showMessage(
                    'サーバー保存上限に達したため、ローカル保存へ切り替えました。',
                    true
                );

                return;
            }

            throw new Error(
                result.message ||
                'サーバー保存に失敗しました。'
            );
        }

        project.projectId =
            result.projectId;

        project.savedAt =
            result.savedAt;

        state.project =
            normalizeProject(project);

        state.dirty = false;

        setStatus(
            'サーバー保存済み'
        );

        showMessage(
            'サーバーに保存しました。',
            true
        );

        renderProjectList();
    }catch(error){
        showMessage(
            'サーバー保存に失敗しました：' +
            error.message
        );
    }
}

/* =========================================================
 * EXPORT / IMPORT
 * ======================================================= */

function downloadText(
    filename,
    text,
    type
){
    const blob =
        new Blob(
            [text],
            {type}
        );

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href = url;
    a.download = filename;
    a.click();

    setTimeout(()=>{
        URL.revokeObjectURL(url);
    },1000);
}

function exportProject(){
    try{
        const project =
            projectForSave();

        downloadText(
            (
                project.name ||
                'project'
            ) + '.json',
            JSON.stringify(
                project,
                null,
                2
            ),
            'application/json'
        );

        showMessage(
            '編集プロジェクトを書き出しました。',
            true
        );
    }catch(error){
        showMessage(error.message);
    }
}

async function importProjectFile(file){
    if (!file) return;

    try{
        const text =
            await file.text();

        const project =
            normalizeProject(
                JSON.parse(text)
            );

        if (!project.videoKey) {
            throw new Error(
                'このJSONには動画本体への参照がありません。'
            );
        }

        const videoRecord =
            await idbGet(
                'videos',
                project.videoKey
            );

        if (!videoRecord) {
            throw new Error(
                'このプロジェクトに対応する動画本体がブラウザ内にありません。先に動画を読み込んでください。'
            );
        }

        await openEditor(
            project,
            videoRecord.blob
        );

        showMessage(
            'プロジェクトを読み込みました。',
            true
        );
    }catch(error){
        showMessage(
            'プロジェクト読み込みに失敗しました：' +
            error.message
        );
    }
}

/* =========================================================
 * SERVER PROJECT LIST
 * ======================================================= */

async function loadServerList(){
    const response =
        await fetch(
            '?api=list&_=' +
            Date.now(),
            {cache:'no-store'}
        );

    const data =
        await response.json();

    if (!data.ok) {
        throw new Error(
            data.message ||
            '一覧取得失敗'
        );
    }

    return data;
}

async function renderProjectList(){
    const box =
        $('projectList');

    if (!box) return;

    box.innerHTML =
        '<div class="help">保存データを確認中…</div>';

    try{
        const server =
            await loadServerList();

        const local =
            await idbGetAll('projects');

        const wrapper =
            document.createElement('div');

        wrapper.className =
            'project-list';

        const title =
            document.createElement('h3');

        title.textContent =
            '保存済みプロジェクト';

        wrapper.appendChild(title);

        const serverIds =
            new Set(
                server.projects.map(
                    p => p.projectId
                )
            );

        const all = [];

        server.projects.forEach(
            p => all.push({
                ...p,
                storage:'server'
            })
        );

        local
            .filter(
                p =>
                    !serverIds.has(
                        p.projectId
                    )
            )
            .forEach(
                p => all.push({
                    projectId:p.projectId,
                    name:p.name,
                    videoName:p.videoName,
                    savedAt:p.savedAt,
                    storage:'local'
                })
            );

        if (!all.length) {
            const empty =
                document.createElement('div');

            empty.className =
                'help';

            empty.textContent =
                '保存済みデータはありません。';

            wrapper.appendChild(empty);
        }

        all.forEach(project=>{
            const row =
                document.createElement('div');

            row.className =
                'project';

            const info =
                document.createElement('div');

            info.className =
                'project-info';

            const name =
                document.createElement('div');

            name.className =
                'project-name';

            name.textContent =
                project.name;

            const meta =
                document.createElement('div');

            meta.className =
                'project-meta';

            meta.textContent =
                `${project.storage === 'server'
                    ? 'サーバー'
                    : 'ローカル'} / ` +
                `${project.videoName || '動画未設定'} / ` +
                `${project.savedAt || ''}`;

            info.appendChild(name);
            info.appendChild(meta);

            const actions =
                document.createElement('div');

            actions.className =
                'project-actions';

            const load =
                document.createElement('button');

            load.textContent =
                '開く';

            load.addEventListener(
                'click',
                () =>
                    loadProject(
                        project.projectId,
                        project.storage
                    )
            );

            actions.appendChild(load);

            if (
                project.storage ===
                'local'
            ) {
                const del =
                    document.createElement(
                        'button'
                    );

                del.className =
                    'danger';

                del.textContent =
                    '削除';

                del.addEventListener(
                    'click',
                    () =>
                        deleteLocalProject(
                            project.projectId
                        )
                );

                actions.appendChild(del);
            }

            if (
                project.storage ===
                'server'
            ) {
                const del =
                    document.createElement(
                        'button'
                    );

                del.className =
                    'danger';

                del.textContent =
                    '削除';

                del.addEventListener(
                    'click',
                    () =>
                        deleteServerProject(
                            project.projectId
                        )
                );

                actions.appendChild(del);
            }

            row.appendChild(info);
            row.appendChild(actions);

            wrapper.appendChild(row);
        });

        box.innerHTML = '';
        box.appendChild(wrapper);
    }catch(error){
        box.innerHTML =
            `<div class="help">
                保存データを取得できませんでした。
                ${error.message}
            </div>`;
    }
}

async function loadProject(id,storage){
    try{
        let project;

        if (storage === 'local') {
            project =
                await idbGet(
                    'projects',
                    id
                );
        } else {
            const response =
                await fetch(
                    '?api=load&id=' +
                    encodeURIComponent(id) +
                    '&_=' +
                    Date.now(),
                    {
                        cache:'no-store'
                    }
                );

            const result =
                await response.json();

            if (!result.ok) {
                throw new Error(
                    result.message
                );
            }

            project =
                result.project;
        }

        if (!project) {
            throw new Error(
                'プロジェクトが見つかりません。'
            );
        }

        project =
            normalizeProject(project);

        if (!project.videoKey) {
            throw new Error(
                '動画本体への参照がありません。'
            );
        }

        const video =
            await idbGet(
                'videos',
                project.videoKey
            );

        if (!video) {
            throw new Error(
                '動画本体がブラウザの保存領域にありません。'
            );
        }

        await openEditor(
            project,
            video.blob
        );

        showMessage(
            'プロジェクトを読み込みました。',
            true
        );
    }catch(error){
        showMessage(
            '読み込みに失敗しました：' +
            error.message
        );
    }
}

async function deleteLocalProject(id){
    if (
        !confirm(
            'ローカル保存されたプロジェクトを削除しますか？'
        )
    ) {
        return;
    }

    await idbDelete(
        'projects',
        id
    );

    renderProjectList();

    showMessage(
        'ローカルプロジェクトを削除しました。',
        true
    );
}

async function deleteServerProject(id){
    if (
        !confirm(
            'サーバー保存されたプロジェクトを削除しますか？'
        )
    ) {
        return;
    }

    try{
        const response =
            await fetch(
                '?api=delete',
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/json'
                    },
                    body:
                        JSON.stringify({
                            projectId:id
                        })
                }
            );

        const result =
            await response.json();

        if (!result.ok) {
            throw new Error(
                result.message
            );
        }

        renderProjectList();

        showMessage(
            'サーバープロジェクトを削除しました。',
            true
        );
    }catch(error){
        showMessage(
            error.message
        );
    }
}

/* =========================================================
 * RECORDER
 * ======================================================= */

async function startRecording(){
    try{
        if (
            !navigator.mediaDevices?.getDisplayMedia
        ) {
            throw new Error(
                'このブラウザでは画面録画に対応していません。'
            );
        }

        const display =
            await navigator.mediaDevices.getDisplayMedia({
                video:true,
                audio:$('systemAudio').checked
            });

        let stream = display;

        if ($('microphone').checked) {
            const mic =
                await navigator.mediaDevices.getUserMedia({
                    audio:true
                });

            const audioTracks =
                mic.getAudioTracks();

            audioTracks.forEach(
                track =>
                    stream.addTrack(track)
            );
        }

        state.recordStream =
            stream;

        $('preview').srcObject =
            stream;

        const mimeTypes = [
            'video/webm;codecs=vp9,opus',
            'video/webm;codecs=vp8,opus',
            'video/webm'
        ];

        const mimeType =
            mimeTypes.find(
                type =>
                    MediaRecorder.isTypeSupported(
                        type
                    )
            ) || '';

        state.recordChunks = [];

        state.recorder =
            new MediaRecorder(
                stream,
                mimeType
                    ? {mimeType}
                    : undefined
            );

        state.recorder.ondataavailable =
            event=>{
                if (event.data.size) {
                    state.recordChunks.push(
                        event.data
                    );
                }
            };

        state.recorder.onstop =
            finishRecording;

        state.recorder.start(250);

        state.recordStartedAt =
            Date.now();

        state.recordTimer =
            setInterval(
                updateRecordTimer,
                250
            );

        $('startRecord').disabled =
            true;

        $('pauseRecord').disabled =
            false;

        $('stopRecord').disabled =
            false;

        showMessage(
            '録画を開始しました。',
            true
        );
    }catch(error){
        showMessage(
            '録画を開始できませんでした：' +
            error.message
        );
    }
}

function updateRecordTimer(){
    $('timer').textContent =
        formatTime(
            (
                Date.now() -
                state.recordStartedAt
            ) / 1000
        );
}

function pauseRecording(){
    if (!state.recorder) return;

    if (
        state.recorder.state ===
        'recording'
    ) {
        state.recorder.pause();
        $('pauseRecord').textContent =
            '再開';
        return;
    }

    if (
        state.recorder.state ===
        'paused'
    ) {
        state.recorder.resume();
        $('pauseRecord').textContent =
            '一時停止';
    }
}

function stopRecording(){
    if (
        state.recorder &&
        state.recorder.state !==
        'inactive'
    ) {
        state.recorder.stop();
    }
}

async function finishRecording(){
    clearInterval(
        state.recordTimer
    );

    state.recordTimer = null;

    state.recordStream
        ?.getTracks()
        .forEach(
            track =>
                track.stop()
        );

    const blob =
        new Blob(
            state.recordChunks,
            {
                type:
                    state.recorder?.mimeType ||
                    'video/webm'
            }
        );

    if (!blob.size) {
        showMessage(
            '録画データが空です。'
        );
        return;
    }

    const fileName =
        'recording-' +
        new Date()
            .toISOString()
            .replaceAll(':','-') +
        '.webm';

    const videoKey =
        await saveVideoBlob(
            blob,
            fileName,
            blob.type
        );

    const project =
        createEmptyProject(
            '画面録画',
            fileName
        );

    project.videoKey =
        videoKey;

    $('startRecord').disabled =
        false;

    $('pauseRecord').disabled =
        true;

    $('stopRecord').disabled =
        true;

    $('pauseRecord').textContent =
        '一時停止';

    await openEditor(
        project,
        blob
    );

    markDirty();

    showMessage(
        '録画した動画を編集対象として読み込みました。',
        true
    );
}

/* =========================================================
 * HOME
 * ======================================================= */

function closeEditor(){
    if (
        state.dirty &&
        !confirm(
            '未保存の編集があります。閉じますか？'
        )
    ) {
        return;
    }

    $('editor').style.display =
        'none';

    $('home').style.display =
        'flex';

    if (state.videoUrl) {
        URL.revokeObjectURL(
            state.videoUrl
        );

        state.videoUrl = '';
    }

    $('recordedVideo').pause();
    $('recordedVideo').removeAttribute('src');
    $('recordedVideo').load();

    state.project = null;
    state.videoBlob = null;
    state.selected = null;
    state.selectedType = null;
    state.connectSource = null;
    state.connectMode = false;
    state.dirty = false;

    setStatus('待機中');
    renderProjectList();
}

/* =========================================================
 * GLOBAL EVENTS
 * ======================================================= */

function bindEvents(){

    /*
     * 動画ファイル
     */
    $('newVideoBtn')
        .addEventListener(
            'click',
            chooseVideo
        );

    $('videoFile')
        .addEventListener(
            'change',
            async event=>{
                const file =
                    event.target.files?.[0];

                event.target.value = '';

                await handleVideoFile(file);
            }
        );

    /*
     * 画面録画
     */
    $('recordScreenBtn')
        .addEventListener(
            'click',
            ()=>{
                $('home').style.display =
                    'none';

                $('recorder').style.display =
                    'flex';

                $('startRecord').disabled =
                    false;
            }
        );

    $('recordCancel')
        .addEventListener(
            'click',
            ()=>{
                if (
                    state.recorder &&
                    state.recorder.state !==
                    'inactive'
                ) {
                    state.recorder.stop();
                }

                state.recordStream
                    ?.getTracks()
                    .forEach(
                        track =>
                            track.stop()
                    );

                $('recorder').style.display =
                    'none';

                $('home').style.display =
                    'flex';
            }
        );

    $('startRecord')
        .addEventListener(
            'click',
            startRecording
        );

    $('pauseRecord')
        .addEventListener(
            'click',
            pauseRecording
        );

    $('stopRecord')
        .addEventListener(
            'click',
            stopRecording
        );

    /*
     * 保存
     */
    $('saveServer')
        .addEventListener(
            'click',
            saveServer
        );

    $('saveLocal')
        .addEventListener(
            'click',
            saveLocal
        );

    $('exportJson')
        .addEventListener(
            'click',
            exportProject
        );

    $('importJson')
        .addEventListener(
            'click',
            () => $('jsonFile').click()
        );

    $('jsonFile')
        .addEventListener(
            'change',
            async event=>{
                const file =
                    event.target.files?.[0];

                event.target.value = '';

                await importProjectFile(file);
            }
        );

    $('manageBtn')
        .addEventListener(
            'click',
            renderProjectList
        );

    $('backHome')
        .addEventListener(
            'click',
            closeEditor
        );

    /*
     * プロジェクト名
     */
    $('editorProjectName')
        .addEventListener(
            'input',
            markDirty
        );

    /*
     * Seek
     */
    $('seek')
        .addEventListener(
            'input',
            event=>{
                const d =
                    duration();

                const value =
                    clamp(
                        number(
                            event.target.value
                        ),
                        0,
                        d
                    );

                $('recordedVideo')
                    .currentTime =
                    value;
            }
        );

    /*
     * 再生 / 停止
     */
    $('playVideo')
        .addEventListener(
            'click',
            ()=>{
                $('recordedVideo')
                    .play()
                    .catch(
                        console.error
                    );
            }
        );

    $('pauseVideo')
        .addEventListener(
            'click',
            ()=>{
                $('recordedVideo')
                    .pause();
            }
        );

    /*
     * 動画メタデータ
     */
    $('recordedVideo')
        .addEventListener(
            'loadedmetadata',
            ()=>{
                const d =
                    Number(
                        $('recordedVideo')
                            .duration
                    );

                if (
                    Number.isFinite(d) &&
                    d > 0
                ) {
                    state.project &&
                        (
                            state.project.duration =
                                d
                        );

                    $('seek').min = '0';
                    $('seek').max =
                        String(d);
                    $('seek').step = '.01';
                    $('seek').disabled =
                        false;
                }

                updateStageSize();
                renderAll();
            }
        );

    $('recordedVideo')
        .addEventListener(
            'timeupdate',
            ()=>{
                updateTimeReadout();
                renderObjects();
                renderConnections();
            }
        );

    $('recordedVideo')
        .addEventListener(
            'seeked',
            renderAll
        );

    $('recordedVideo')
        .addEventListener(
            'play',
            ()=>{
                renderObjects();
                renderConnections();
            }
        );

    /*
     * 動画領域上のドラッグ
     */
    $('objects')
        .addEventListener(
            'pointerdown',
            beginElementPointer
        );

    $('objects')
        .addEventListener(
            'pointermove',
            moveElementPointer
        );

    $('objects')
        .addEventListener(
            'pointerup',
            endElementPointer
        );

    $('objects')
        .addEventListener(
            'pointercancel',
            endElementPointer
        );

    /*
     * 接点
     */
    $('objects')
        .addEventListener(
            'pointerdown',
            event=>{
                const cp =
                    event.target.closest(
                        '.connection-point'
                    );

                if (!cp) return;

                const object =
                    cp.closest(
                        '.edit-object'
                    );

                if (!object) return;

                const element =
                    state.project.elements.find(
                        e =>
                            e.id ===
                            object.dataset.id
                    );

                if (!element) return;

                event.preventDefault();
                event.stopPropagation();

                handleConnectionPoint(
                    element,
                    cp.dataset.point
                );
            },
            true
        );

    /*
     * 動画上の右クリック
     */
    $('videoStage')
        .addEventListener(
            'contextmenu',
            event=>{
                const object =
                    event.target.closest(
                        '.edit-object'
                    );

                const connector =
                    event.target.closest(
                        '.connector-hit'
                    );

                if (object || connector) {
                    return;
                }

                event.preventDefault();

                const rect =
                    $('videoStage')
                        .getBoundingClientRect();

                const x =
                    clamp(
                        (
                            event.clientX -
                            rect.left
                        ) /
                        rect.width *
                        100,
                        0,
                        99
                    );

                const y =
                    clamp(
                        (
                            event.clientY -
                            rect.top
                        ) /
                        rect.height *
                        100,
                        0,
                        99
                    );

                state.contextTarget = {
                    type:'stage',
                    x,
                    y
                };

                state.selected = null;
                state.selectedType = null;

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

    /*
     * 要素右クリック
     */
    $('objects')
        .addEventListener(
            'contextmenu',
            event=>{
                const object =
                    event.target.closest(
                        '.edit-object'
                    );

                if (!object) return;

                event.preventDefault();
                event.stopPropagation();

                selectElement(
                    object.dataset.id
                );

                state.contextTarget = {
                    type:'element',
                    id:object.dataset.id
                };

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

    /*
     * 接続線右クリック
     */
    $('connectors')
        .addEventListener(
            'contextmenu',
            event=>{
                const hit =
                    event.target.closest(
                        '.connector-hit'
                    );

                if (!hit) return;

                event.preventDefault();
                event.stopPropagation();

                selectConnection(
                    hit.dataset.id
                );

                state.contextTarget = {
                    type:'connection',
                    id:hit.dataset.id
                };

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

    /*
     * コンテキストメニュー
     */
    $('contextMenu')
        .addEventListener(
            'click',
            event=>{
                const button =
                    event.target.closest(
                        'button'
                    );

                if (!button) return;

                const action =
                    button.dataset.action;

                hideContextMenu();

                if (
                    action ===
                    'add-comment'
                ) {
                    contextAdd(
                        'comment'
                    );
                    return;
                }

                if (
                    action ===
                    'add-box'
                ) {
                    contextAdd(
                        'box'
                    );
                    return;
                }

                if (
                    action ===
                    'add-skip'
                ) {
                    contextAdd(
                        'skip'
                    );
                    return;
                }

                if (
                    action === 'style'
                ) {
                    if (
                        state.contextTarget
                            ?.type ===
                        'connection'
                    ) {
                        openConnectionModal(
                            state.contextTarget.id
                        );
                    } else {
                        openObjectModal(
                            state.contextTarget?.id
                        );
                    }

                    return;
                }

                if (
                    action ===
                    'duplicate'
                ) {
                    duplicateSelected();
                    return;
                }

                if (
                    action === 'delete'
                ) {
                    deleteSelected();
                }
            }
        );

    document.addEventListener(
        'click',
        event=>{
            if (
                !event.target.closest(
                    '#contextMenu'
                )
            ) {
                hideContextMenu();
            }
        }
    );

    /*
     * 接続モード
     */
    $('connectMode')
        .addEventListener(
            'click',
            ()=>{
                state.connectMode =
                    !state.connectMode;

                state.connectSource =
                    null;

                updateConnectionStatus();
                renderAll();

                if (state.connectMode) {
                    showMessage(
                        '接続モード：接続元の要素に表示される青い接点をクリックしてください。',
                        true
                    );
                }
            }
        );

    /*
     * 開始 / 終了
     */
    $('startPoint')
        .addEventListener(
            'click',
            ()=>{
                if (!state.selected) {
                    showMessage(
                        '先に要素または接続線を選択してください。'
                    );
                    return;
                }

                const item =
                    state.selectedType ===
                        'element'
                        ? state.project.elements.find(
                            e =>
                                e.id ===
                                state.selected
                        )
                        : state.project.connections.find(
                            c =>
                                c.id ===
                                state.selected
                        );

                if (!item) return;

                applyCurrentSelectionTime(
                    currentTime(),
                    Math.max(
                        currentTime(),
                        item.end
                    )
                );
            }
        );

    $('endPoint')
        .addEventListener(
            'click',
            ()=>{
                if (!state.selected) {
                    showMessage(
                        '先に要素または接続線を選択してください。'
                    );
                    return;
                }

                const item =
                    state.selectedType ===
                        'element'
                        ? state.project.elements.find(
                            e =>
                                e.id ===
                                state.selected
                        )
                        : state.project.connections.find(
                            c =>
                                c.id ===
                                state.selected
                        );

                if (!item) return;

                applyCurrentSelectionTime(
                    Math.min(
                        item.start,
                        currentTime()
                    ),
                    currentTime()
                );
            }
        );

    /*
     * 要素モーダル
     */
    $('cancelObjectStyle')
        .addEventListener(
            'click',
            ()=>{
                $('objectModal').style.display =
                    'none';
            }
        );

    $('applyObjectStyle')
        .addEventListener(
            'click',
            applyObjectModal
        );

    $('objectModal')
        .addEventListener(
            'click',
            event=>{
                if (
                    event.target ===
                    $('objectModal')
                ) {
                    $('objectModal').style.display =
                        'none';
                }
            }
        );

    /*
     * 結線モーダル
     */
    $('cancelConnection')
        .addEventListener(
            'click',
            ()=>{
                $('connectionModal')
                    .style.display =
                    'none';
            }
        );

    $('applyConnection')
        .addEventListener(
            'click',
            applyConnectionModal
        );

    $('connectionModal')
        .addEventListener(
            'click',
            event=>{
                if (
                    event.target ===
                    $('connectionModal')
                ) {
                    $('connectionModal')
                        .style.display =
                        'none';
                }
            }
        );

    /*
     * Esc
     */
    document.addEventListener(
        'keydown',
        event=>{
            if (event.key === 'Escape') {
                hideContextMenu();

                $('objectModal').style.display =
                    'none';

                $('connectionModal')
                    .style.display =
                    'none';

                state.connectSource =
                    null;

                updateConnectionStatus();
                renderAll();
            }

            if (
                event.key === 'Delete' &&
                !event.target.matches(
                    'input,textarea,select'
                )
            ) {
                deleteSelected();
            }
        }
    );

    /*
     * Resize
     */
    window.addEventListener(
        'resize',
        ()=>{
            if (
                $('editor').style.display !==
                'none'
            ) {
                updateStageSize();
                renderAll();
            }
        }
    );
}

/* =========================================================
 * RENDER
 * ======================================================= */

function renderAll(){
    if (!state.project) return;

    renderObjects();
    renderConnections();
    renderTimeline();
    updateTimeReadout();
    updateConnectionStatus();
}

/* =========================================================
 * INIT
 *
 * 重要:
 * 全DOMが存在してからイベントを登録する。
 * これにより
 * "Cannot read properties of null
 *  (reading 'addEventListener')"
 * を防ぐ。
 * ======================================================= */

async function init(){
    try{
        await openDB();

        bindEvents();

        setStatus('待機中');

        await renderProjectList();

        /*
         * 保存済みデータ確認。
         */
        const local =
            await idbGetAll('projects');

        if (local.length) {
            setStatus(
                `待機中 / ローカル保存 ${local.length}件`
            );
        }
    }catch(error){
        console.error(error);

        showMessage(
            '初期化に失敗しました：' +
            error.message
        );
    }
}

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        init,
        {once:true}
    );
} else {
    init();
}
</script>

</body>
</html>

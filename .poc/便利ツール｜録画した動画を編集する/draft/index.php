<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * 編集データ:
 *   サーバー ./data/projects/*.json
 *   ブラウザ IndexedDB
 *
 * 動画本体:
 *   ブラウザ IndexedDB
 *
 * 「プロジェクト書き出し」はJSON。
 * MP4再エンコードは行わない。
 */

const APP_VERSION = 10;
const DB_VERSION = 3;
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
    return (bool)preg_match('/^[A-Za-z0-9_-]{8,120}$/', $id);
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
        $data = json_decode(@file_get_contents($file) ?: '', true);

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
    --orange:#e38b28;
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
    padding:7px 10px;
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
    height:48px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 14px;
    background:#1b1e22;
    border-bottom:1px solid #353a41;
}

header h1{
    margin:0;
    font-size:15px;
}

#status{
    color:#b6bec7;
    font-size:12px;
}

#message{
    position:fixed;
    top:58px;
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
    height:calc(100vh - 48px);
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
}

/* RECORDER */

#recorder{
    position:fixed;
    inset:48px 0 0;
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
    min-height:58px;
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
    min-height:48px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
    background:#1b1e22;
    border-bottom:1px solid #363b42;
}

#editorProjectName{
    width:220px;
    font-weight:600;
}

#editorStatus{
    color:#aeb6bf;
    font-size:12px;
    margin-left:auto;
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
    line-height:0;
    flex:none;
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
    line-height:normal;
    overflow:visible;
}

.edit-object.selected{
    outline:2px solid #fff;
    outline-offset:2px;
}

.edit-object.multi-selected{
    outline:2px dashed #ffd447;
    outline-offset:3px;
}

.edit-object.comment{
    display:flex;
    align-items:center;
    justify-content:flex-start;
    padding:5px 8px;
    white-space:pre-wrap;
    word-break:break-word;
    overflow:hidden;
}

.edit-object.box{
    background:transparent;
}

.edit-object.skip{
    border-style:dashed!important;
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
    width:14px;
    height:14px;
    margin:-7px;
    border:2px solid #fff;
    background:#247bd3;
    border-radius:50%;
    display:none;
    z-index:20;
    cursor:crosshair;
}

.edit-object.selected .connection-point,
.edit-object.multi-selected .connection-point{
    display:block;
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
    stroke-width:16;
    pointer-events:stroke;
    cursor:pointer;
}

.connector.selected{
    filter:drop-shadow(0 0 4px #ffd447);
}

/* TIMELINE */

#timeline{
    height:250px;
    flex-shrink:0;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:8px 12px;
    overflow:auto;
}

.seek-wrap{
    position:relative;
    padding:3px 0;
}

#seek{
    display:block;
    width:100%;
    margin:0;
}

.timeline-scale{
    position:relative;
    height:22px;
    color:#8f969f;
    font-size:10px;
}

.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    white-space:nowrap;
}

.timeline-row{
    display:grid;
    grid-template-columns:70px 1fr;
    gap:7px;
    margin-top:8px;
    align-items:center;
    font-size:11px;
    color:#b0b7c0;
}

.track{
    position:relative;
    height:32px;
    background:#292d33;
    border-radius:4px;
    border:1px solid #3c4249;
    overflow:visible;
}

.track-item{
    position:absolute;
    top:4px;
    height:22px;
    min-width:7px;
    border-radius:3px;
    cursor:pointer;
}

.track-item.comment{
    background:#42a5f5;
}

.track-item.box{
    background:#ef5350;
}

.track-item.skip{
    background:#e38b28;
}

.track-item.connection{
    background:#8e68d6;
}

.track-item.selected{
    outline:2px solid #fff;
    outline-offset:1px;
}

.track-handle{
    position:absolute;
    top:-2px;
    bottom:-2px;
    width:9px;
    z-index:5;
    cursor:ew-resize;
}

.track-handle.left{
    left:-4px;
}

.track-handle.right{
    right:-4px;
}

.track-time,
.track-end-time{
    position:absolute;
    top:-16px;
    font-size:9px;
    color:#dfe4e9;
    white-space:nowrap;
    pointer-events:none;
}

.track-time{
    left:0;
}

.track-end-time{
    right:0;
}

.track-name{
    position:absolute;
    left:5px;
    right:5px;
    top:3px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:#fff;
    font-size:9px;
    pointer-events:none;
}

.timeline-help{
    color:#777f89;
    font-size:10px;
    margin:9px 0 0 77px;
}

/* FOOTER */

#editorFooter{
    min-height:46px;
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
    width:270px;
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
    align-items:center;
    justify-content:center;
    padding:20px;
    background:#000a;
}

.modal{
    width:min(700px,96vw);
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

.color-grid{
    display:grid;
    grid-template-columns:repeat(6,32px);
    gap:6px;
    margin-top:6px;
}

.color-swatch{
    width:32px;
    height:32px;
    padding:0;
    border-radius:5px;
    border:2px solid #666;
}

.help{
    color:#999fa8;
    font-size:12px;
    line-height:1.55;
}

@media(max-width:800px){
    #timeline{
        height:230px;
    }

    .editor-top{
        overflow-x:auto;
    }

    #editorProjectName{
        width:150px;
    }

    #editorStatus{
        min-width:120px;
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
            動画を読み込んでメタデータの取得が完了してから編集画面を開きます。
            要素は動画上で直接追加・移動・リサイズ・編集できます。
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

        <button id="playVideo">
            ▶ 再生
        </button>

        <button id="pauseVideo">
            ■ 停止
        </button>

        <button id="saveServer" class="success">
            保存
        </button>

        <button id="saveLocal">
            ローカル保存
        </button>

        <button id="exportJson">
            JSON書き出し
        </button>

        <button id="importJson">
            JSON読み込み
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

        <div class="timeline-help">
            バー左右端をドラッグすると開始・終了時刻を変更できます。
            バーをクリックするとその時刻へ移動します。
        </div>
    </div>

    <div id="editorFooter">

        <span id="timeReadout">
            00:00.00 / 00:00.00
        </span>

        <span id="connectionStatus" class="connection-status">
            通常操作
        </span>

        <button id="clearSelection">
            選択解除
        </button>

        <button id="deleteSelected" class="danger">
            選択削除
        </button>

    </div>

</section>

<!-- コンテキストメニュー -->

<div id="contextMenu">

    <button id="ctxAddComment">
        ＋テキスト
    </button>

    <button id="ctxAddBox">
        ＋強調枠
    </button>

    <button id="ctxAddSkip">
        ＋スキップ
    </button>

    <div class="context-separator"></div>

    <button id="ctxEdit">
        編集・書式変更
    </button>

    <button id="ctxDuplicate">
        複製
    </button>

    <button id="ctxDelete" class="danger">
        削除
    </button>

    <button id="ctxConnect">
        選択した2要素を接続
    </button>

    <button id="ctxConnectionEdit">
        接続線を編集
    </button>

    <button id="ctxConnectionDelete" class="danger">
        接続線を削除
    </button>

</div>

<!-- 要素設定 -->

<div id="objectModal" class="modal-backdrop">

    <div class="modal">

        <h2 id="objectModalTitle">
            要素設定
        </h2>

        <div class="modal-grid">

            <label id="mTextField" class="field full">
                テキスト
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
                X %
                <input
                    id="mX"
                    type="number"
                    min="0"
                    max="100"
                    step=".1"
                >
            </label>

            <label class="field">
                Y %
                <input
                    id="mY"
                    type="number"
                    min="0"
                    max="100"
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

            <label class="field">
                文字サイズ
                <input
                    id="mFontSize"
                    type="number"
                    min="6"
                    max="200"
                    step="1"
                >
            </label>

            <label class="field">
                文字太さ
                <select id="mWeight">
                    <option value="400">標準</option>
                    <option value="500">中</option>
                    <option value="600">太字</option>
                    <option value="700">強調</option>
                    <option value="800">極太</option>
                </select>
            </label>

            <label class="field">
                透明度 %
                <input
                    id="mOpacity"
                    type="number"
                    min="0"
                    max="100"
                    step="1"
                >
            </label>

            <label class="field">
                枠線幅
                <input
                    id="mBorderWidth"
                    type="number"
                    min="0"
                    max="30"
                    step="1"
                >
            </label>

            <label class="field">
                角丸
                <input
                    id="mRadius"
                    type="number"
                    min="0"
                    max="100"
                    step="1"
                >
            </label>

            <label class="field">
                文字色
                <input id="mColor" type="color">
            </label>

            <label class="field">
                背景色
                <input id="mBg" type="color">
            </label>

            <label class="field">
                枠線色
                <input id="mBorderColor" type="color">
            </label>

            <div class="field full">
                12色パレット

                <div id="colorGrid" class="color-grid"></div>
            </div>

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

<!-- 接続設定 -->

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
                接続元
                <select id="cFromPoint">
                    <option value="n">上</option>
                    <option value="ne">右上</option>
                    <option value="e">右</option>
                    <option value="se">右下</option>
                    <option value="s">下</option>
                    <option value="sw">左下</option>
                    <option value="w">左</option>
                    <option value="nw">左上</option>
                </select>
            </label>

            <label class="field">
                接続先
                <select id="cToPoint">
                    <option value="n">上</option>
                    <option value="ne">右上</option>
                    <option value="e">右</option>
                    <option value="se">右下</option>
                    <option value="s">下</option>
                    <option value="sw">左下</option>
                    <option value="w">左</option>
                    <option value="nw">左上</option>
                </select>
            </label>

            <label class="field">
                線幅
                <input
                    id="cWidth"
                    type="number"
                    min="1"
                    max="10"
                    step=".5"
                >
            </label>

            <label class="field">
                色
                <input id="cColor" type="color">
            </label>

            <label class="field">
                線種
                <select id="cDash">
                    <option value="">実線</option>
                    <option value="6 4">破線</option>
                    <option value="2 4">点線</option>
                </select>
            </label>

            <label class="field">
                曲がり
                <input
                    id="cCurve"
                    type="number"
                    min="0"
                    max="100"
                    step="1"
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
 * 基本
 * ======================================================= */

const $ = id => document.getElementById(id);

const APP = {
    version: <?= APP_VERSION ?>,
    dbName: 'video_direct_editor_v10',
    dbVersion: <?= DB_VERSION ?>
};

const state = {
    db: null,
    project: null,
    videoBlob: null,
    videoUrl: '',
    selected: null,
    selectedType: null,
    multiSelected: [],
    contextTarget: null,
    drag: null,
    timelineDrag: null,
    dirty: false,
    modalElementId: null,
    modalConnectionId: null,
    pendingProject: null,
    messageTimer: null,
    recordStream: null,
    recorder: null,
    recordChunks: [],
    recordTimer: null,
    recordStartedAt: 0
};

const COLORS = [
    '#ffffff',
    '#ff4d4d',
    '#ff8a00',
    '#ffd43b',
    '#4cd964',
    '#20c997',
    '#00bcd4',
    '#4dabf7',
    '#5c7cfa',
    '#845ef7',
    '#be4bdb',
    '#f06595'
];

/* =========================================================
 * Utility
 * ======================================================= */

function clone(value){
    return JSON.parse(JSON.stringify(value));
}

function uid(prefix = 'id'){
    return prefix +
        '-' +
        Date.now().toString(36) +
        '-' +
        Math.random().toString(36).slice(2,10);
}

function number(value,fallback = 0){
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
}

function clamp(value,min,max){
    if (max < min) {
        return min;
    }

    return Math.min(
        max,
        Math.max(min,value)
    );
}

function formatTime(seconds){
    const n = Math.max(
        0,
        number(seconds)
    );

    const min = Math.floor(n / 60);
    const sec = n - min * 60;

    return String(min).padStart(2,'0') +
        ':' +
        sec.toFixed(2).padStart(5,'0');
}

function nowISO(){
    return new Date().toISOString();
}

function duration(){
    const d = Number(
        $('recordedVideo')?.duration
    );

    if (
        Number.isFinite(d) &&
        d > 0
    ) {
        return d;
    }

    return number(
        state.project?.duration,
        0
    );
}

function currentTime(){
    return number(
        $('recordedVideo')?.currentTime,
        0
    );
}

function setStatus(text){
    $('status').textContent = text;
}

function setEditorStatus(text){
    $('editorStatus').textContent = text;
}

function showMessage(text,ok = false){
    const box = $('message');

    box.textContent = text;
    box.classList.toggle('ok',ok);
    box.style.display = 'block';

    clearTimeout(
        state.messageTimer
    );

    state.messageTimer =
        setTimeout(
            () => {
                box.style.display = 'none';
            },
            3500
        );
}

function markDirty(){
    state.dirty = true;

    if (state.project) {
        setEditorStatus('未保存の変更あり');
    }
}

function elementById(id){
    return state.project?.elements?.find(
        e => e.id === id
    ) || null;
}

function connectionById(id){
    return state.project?.connections?.find(
        c => c.id === id
    ) || null;
}

function visible(item,time){
    return (
        time >= number(item.start) &&
        time < number(item.end)
    );
}

/* =========================================================
 * Project
 * ======================================================= */

function createEmptyProject(name,videoName){
    return {
        version:APP.version,
        projectId:uid('project'),
        name:name || '無題プロジェクト',
        videoName:videoName || '',
        videoKey:'',
        savedAt:'',
        duration:0,
        elements:[],
        connections:[]
    };
}

function normalizeProject(project){
    const p = clone(project || {});

    p.version = APP.version;
    p.projectId =
        String(
            p.projectId ||
            uid('project')
        );

    p.name =
        String(
            p.name ||
            '無題プロジェクト'
        );

    p.videoName =
        String(
            p.videoName ||
            ''
        );

    p.videoKey =
        String(
            p.videoKey ||
            ''
        );

    p.duration =
        Math.max(
            0,
            number(p.duration)
        );

    p.elements =
        Array.isArray(p.elements)
            ? p.elements
            : [];

    p.connections =
        Array.isArray(p.connections)
            ? p.connections
            : [];

    p.elements =
        p.elements.map(e => ({
            id:String(e.id || uid('element')),
            type:
                ['comment','box','skip']
                    .includes(e.type)
                    ? e.type
                    : 'comment',
            text:String(e.text || ''),
            x:number(e.x,10),
            y:number(e.y,10),
            w:number(e.w,25),
            h:number(e.h,12),
            start:number(e.start,0),
            end:number(e.end,5),
            style:{
                color:e.style?.color || '#ffffff',
                background:e.style?.background || '#000000',
                borderColor:e.style?.borderColor || '#ffffff',
                borderWidth:number(
                    e.style?.borderWidth,
                    e.type === 'box' ? 3 : 0
                ),
                radius:number(
                    e.style?.radius,
                    4
                ),
                fontSize:number(
                    e.style?.fontSize,
                    24
                ),
                fontWeight:number(
                    e.style?.fontWeight,
                    600
                ),
                opacity:number(
                    e.style?.opacity,
                    100
                )
            }
        }));

    p.connections =
        p.connections.map(c => ({
            id:String(c.id || uid('connection')),
            from:String(c.from || ''),
            to:String(c.to || ''),
            fromPoint:c.fromPoint || 'e',
            toPoint:c.toPoint || 'w',
            start:number(c.start,0),
            end:number(c.end,5),
            color:c.color || '',
            width:number(c.width,1.5),
            dash:c.dash || '',
            curve:number(c.curve,35)
        }));

    const d = p.duration;

    p.elements.forEach(e => {
        e.x = clamp(e.x,0,99);
        e.y = clamp(e.y,0,99);
        e.w = clamp(e.w,.5,100-e.x);
        e.h = clamp(e.h,.5,100-e.y);

        e.start =
            clamp(
                e.start,
                0,
                d || Number.MAX_SAFE_INTEGER
            );

        e.end =
            clamp(
                Math.max(e.start,e.end),
                e.start,
                d || Number.MAX_SAFE_INTEGER
            );
    });

    p.connections =
        p.connections.filter(
            c =>
                p.elements.some(e => e.id === c.from) &&
                p.elements.some(e => e.id === c.to)
        );

    p.connections.forEach(c => {
        c.start =
            clamp(
                c.start,
                0,
                d || Number.MAX_SAFE_INTEGER
            );

        c.end =
            clamp(
                Math.max(c.start,c.end),
                c.start,
                d || Number.MAX_SAFE_INTEGER
            );
    });

    return p;
}

/* =========================================================
 * IndexedDB
 * ======================================================= */

function openDB(){
    return new Promise((resolve,reject)=>{
        const request =
            indexedDB.open(
                APP.dbName,
                APP.dbVersion
            );

        request.onupgradeneeded = event => {
            const db = request.result;

            if (
                db.objectStoreNames.contains('videos')
            ) {
                const existing =
                    event.target.transaction.objectStore(
                        'videos'
                    );

                if (
                    existing.keyPath !== 'key'
                ) {
                    db.deleteObjectStore('videos');
                }
            }

            if (
                db.objectStoreNames.contains('projects')
            ) {
                const existing =
                    event.target.transaction.objectStore(
                        'projects'
                    );

                if (
                    existing.keyPath !== 'projectId'
                ) {
                    db.deleteObjectStore('projects');
                }
            }

            if (
                !db.objectStoreNames.contains('projects')
            ) {
                db.createObjectStore(
                    'projects',
                    {keyPath:'projectId'}
                );
            }

            if (
                !db.objectStoreNames.contains('videos')
            ) {
                db.createObjectStore(
                    'videos',
                    {keyPath:'key'}
                );
            }
        };

        request.onsuccess = () => {
            state.db = request.result;

            state.db.onversionchange = () => {
                state.db.close();
            };

            resolve(state.db);
        };

        request.onerror = () => {
            reject(
                request.error ||
                new Error('IndexedDBを開けませんでした。')
            );
        };
    });
}

function idbPut(store,value){
    return new Promise((resolve,reject)=>{
        if (!state.db) {
            reject(
                new Error(
                    'IndexedDBが初期化されていません。'
                )
            );
            return;
        }

        const tx =
            state.db.transaction(
                store,
                'readwrite'
            );

        tx.objectStore(store).put(value);

        tx.oncomplete =
            () => resolve();

        tx.onerror =
            () => reject(
                tx.error ||
                new Error('IndexedDB保存エラー')
            );

        tx.onabort =
            () => reject(
                tx.error ||
                new Error('IndexedDB保存が中断されました。')
            );
    });
}

function idbGet(store,key){
    return new Promise((resolve,reject)=>{
        const tx =
            state.db.transaction(
                store,
                'readonly'
            );

        const request =
            tx.objectStore(store).get(key);

        request.onsuccess =
            () => resolve(
                request.result || null
            );

        request.onerror =
            () => reject(
                request.error
            );
    });
}

function idbGetAll(store){
    return new Promise((resolve,reject)=>{
        const tx =
            state.db.transaction(
                store,
                'readonly'
            );

        const request =
            tx.objectStore(store).getAll();

        request.onsuccess =
            () => resolve(
                request.result || []
            );

        request.onerror =
            () => reject(
                request.error
            );
    });
}

function idbDelete(store,key){
    return new Promise((resolve,reject)=>{
        const tx =
            state.db.transaction(
                store,
                'readwrite'
            );

        tx.objectStore(store).delete(key);

        tx.oncomplete =
            () => resolve();

        tx.onerror =
            () => reject(tx.error);
    });
}

/* =========================================================
 * Video loading
 * ======================================================= */

function chooseVideo(){
    $('videoFile')?.click();
}

function validateVideoBlob(blob){
    return new Promise((resolve,reject)=>{
        const video =
            document.createElement('video');

        const url =
            URL.createObjectURL(blob);

        let done = false;

        const cleanup = () => {
            if (done) return;

            done = true;

            clearTimeout(timer);

            URL.revokeObjectURL(url);

            video.removeAttribute('src');
            video.load();
        };

        const timer =
            setTimeout(()=>{
                cleanup();

                reject(
                    new Error(
                        '動画の読み込みがタイムアウトしました。'
                    )
                );
            },15000);

        video.preload = 'metadata';
        video.muted = true;
        video.playsInline = true;

        video.onloadedmetadata = () => {
            const d =
                Number(video.duration);

            if (
                !Number.isFinite(d) ||
                d <= 0
            ) {
                cleanup();

                reject(
                    new Error(
                        '動画の長さを取得できませんでした。'
                    )
                );

                return;
            }

            const result = {
                duration:d,
                width:video.videoWidth,
                height:video.videoHeight
            };

            cleanup();
            resolve(result);
        };

        video.onerror = () => {
            cleanup();

            reject(
                new Error(
                    '動画ファイルをブラウザで再生できません。'
                )
            );
        };

        video.src = url;
        video.load();
    });
}

async function saveVideoBlob(blob,name,type){
    const key = uid('video');

    await idbPut(
        'videos',
        {
            key,
            name,
            type:type || blob.type || 'video/mp4',
            size:blob.size,
            savedAt:nowISO(),
            blob
        }
    );

    return key;
}

async function handleVideoFile(file){
    if (!file) return;

    if (
        !file.type ||
        !file.type.startsWith('video/')
    ) {
        showMessage(
            '動画ファイルを選択してください。'
        );
        return;
    }

    try{
        setStatus(
            '動画を確認しています…'
        );

        /*
         * 先に動画そのもののメタデータを取得する。
         * IndexedDB保存は後。
         */
        const metadata =
            await validateVideoBlob(file);

        let project;

        if (state.pendingProject) {
            project =
                normalizeProject(
                    state.pendingProject
                );

            project.videoName =
                file.name;

            state.pendingProject =
                null;
        } else {
            project =
                createEmptyProject(
                    file.name.replace(
                        /\.[^.]+$/,
                        ''
                    ),
                    file.name
                );
        }

        project.duration =
            metadata.duration;

        /*
         * ここで編集画面を開ける。
         * IDB保存失敗を編集開始の条件にしない。
         */
        await openEditor(
            project,
            file,
            metadata
        );

        /*
         * 動画本体の永続化は編集開始後に行う。
         */
        try{
            const key =
                await saveVideoBlob(
                    file,
                    file.name,
                    file.type
                );

            state.project.videoKey =
                key;

            markDirty();

            setEditorStatus(
                '編集可能 / 動画本体保存済み'
            );
        }catch(saveError){
            console.error(saveError);

            setEditorStatus(
                '編集可能 / 動画本体は一時使用中'
            );

            showMessage(
                '編集は開始できますが、動画本体のローカル保存に失敗しました。' +
                '\nブラウザのストレージ容量やIndexedDBを確認してください。'
            );
        }

        showMessage(
            '動画の読み込みが完了しました。編集できます。',
            true
        );

    }catch(error){
        console.error(error);

        state.pendingProject = null;

        showMessage(
            '動画を読み込めませんでした：' +
            error.message
        );

        setStatus(
            '読み込み失敗'
        );
    }
}

/* =========================================================
 * Editor
 * ======================================================= */

async function openEditor(project,blob,metadata = null){
    if (!blob && project.videoKey) {
        const record =
            await idbGet(
                'videos',
                project.videoKey
            );

        if (record?.blob) {
            blob = record.blob;
        }
    }

    if (!blob) {
        throw new Error(
            '編集対象の動画本体が見つかりません。'
        );
    }

    if (!metadata) {
        metadata =
            await validateVideoBlob(blob);
    }

    if (
        !Number.isFinite(metadata.duration) ||
        metadata.duration <= 0
    ) {
        throw new Error(
            '動画の長さを取得できませんでした。'
        );
    }

    state.project =
        normalizeProject(project);

    state.project.duration =
        metadata.duration;

    state.videoBlob =
        blob;

    if (state.videoUrl) {
        URL.revokeObjectURL(
            state.videoUrl
        );
    }

    state.videoUrl =
        URL.createObjectURL(blob);

    const video =
        $('recordedVideo');

    video.pause();

    video.src =
        state.videoUrl;

    video.load();

    state.selected = null;
    state.selectedType = null;
    state.multiSelected = [];
    state.drag = null;
    state.timelineDrag = null;
    state.dirty = false;

    $('editorProjectName').value =
        state.project.name;

    $('seek').min = '0';
    $('seek').max =
        String(metadata.duration);
    $('seek').step = '.01';
    $('seek').value = '0';
    $('seek').disabled = false;

    state.project.elements.forEach(e=>{
        e.start =
            clamp(
                e.start,
                0,
                metadata.duration
            );

        e.end =
            clamp(
                Math.max(e.start,e.end),
                e.start,
                metadata.duration
            );
    });

    state.project.connections.forEach(c=>{
        c.start =
            clamp(
                c.start,
                0,
                metadata.duration
            );

        c.end =
            clamp(
                Math.max(c.start,c.end),
                c.start,
                metadata.duration
            );
    });

    $('home').style.display = 'none';
    $('recorder').style.display = 'none';
    $('editor').style.display = 'flex';

    setStatus('編集可能');

    setEditorStatus(
        '編集可能'
    );

    updateStageSize();
    renderAll();
}

/* =========================================================
 * Stage
 * ======================================================= */

function updateStageSize(){
    const video =
        $('recordedVideo');

    const area =
        $('videoArea');

    const stage =
        $('videoStage');

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
        Math.max(
            100,
            area.clientWidth - 20
        );

    const maxH =
        Math.max(
            100,
            area.clientHeight - 20
        );

    let w =
        maxW;

    let h =
        w / ratio;

    if (h > maxH) {
        h = maxH;
        w = h * ratio;
    }

    stage.style.width =
        `${Math.floor(w)}px`;

    stage.style.height =
        `${Math.floor(h)}px`;
}

/* =========================================================
 * Selection
 * ======================================================= */

function selectNone(){
    state.selected = null;
    state.selectedType = null;
    state.multiSelected = [];

    renderAll();
}

function selectElement(id,shift = false){
    const element =
        elementById(id);

    if (!element) return;

    if (shift) {
        if (
            state.multiSelected.includes(id)
        ) {
            state.multiSelected =
                state.multiSelected.filter(
                    x => x !== id
                );
        } else if (
            state.multiSelected.length < 2
        ) {
            state.multiSelected.push(id);
        } else {
            state.multiSelected =
                [
                    state.multiSelected[1],
                    id
                ];
        }

        state.selected =
            id;

        state.selectedType =
            'element';

        renderAll();
        updateConnectionStatus();
        return;
    }

    state.multiSelected = [id];
    state.selected = id;
    state.selectedType = 'element';

    renderAll();
}

function selectConnection(id){
    if (!connectionById(id)) return;

    state.selected = id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    renderAll();
}

/* =========================================================
 * Add element
 * ======================================================= */

function addElement(type,x = null,y = null){
    const d =
        duration();

    if (!d) {
        showMessage(
            '動画の読み込みが完了していません。'
        );
        return;
    }

    const element = {
        id:uid('element'),
        type,
        text:
            type === 'comment'
                ? 'テキスト'
                : type === 'skip'
                    ? 'スキップ'
                    : '',
        x:
            x === null
                ? 10
                : clamp(x,0,80),
        y:
            y === null
                ? 10
                : clamp(y,0,80),
        w:
            type === 'box'
                ? 35
                : 25,
        h:
            type === 'box'
                ? 25
                : 12,
        start:
            clamp(
                currentTime(),
                0,
                d
            ),
        end:
            clamp(
                currentTime() + 5,
                0,
                d
            ),
        style:{
            color:
                type === 'box'
                    ? '#ff4d4d'
                    : type === 'skip'
                        ? '#e38b28'
                        : '#ffffff',
            background:
                type === 'comment'
                    ? '#000000'
                    : 'transparent',
            borderColor:
                type === 'box'
                    ? '#ff4d4d'
                    : type === 'skip'
                        ? '#e38b28'
                        : '#ffffff',
            borderWidth:
                type === 'box'
                    ? 3
                    : type === 'skip'
                        ? 2
                        : 0,
            radius:4,
            fontSize:24,
            fontWeight:600,
            opacity:100
        }
    };

    state.project.elements.push(
        element
    );

    selectElement(
        element.id
    );

    markDirty();
    renderAll();

    showMessage(
        '要素を追加しました。',
        true
    );
}

/* =========================================================
 * Render objects
 * ======================================================= */

const POINTS = [
    'n',
    'ne',
    'e',
    'se',
    's',
    'sw',
    'w',
    'nw'
];

function renderObjects(){
    const container =
        $('objects');

    container.innerHTML = '';

    if (!state.project) {
        return;
    }

    const time =
        currentTime();

    state.project.elements.forEach(element=>{
        if (!visible(element,time)) {
            return;
        }

        const object =
            document.createElement('div');

        object.className =
            'edit-object ' +
            element.type;

        if (
            state.selected === element.id &&
            state.selectedType === 'element'
        ) {
            object.classList.add('selected');
        }

        if (
            state.multiSelected.includes(
                element.id
            )
        ) {
            object.classList.add(
                'multi-selected'
            );
        }

        object.dataset.id =
            element.id;

        object.style.left =
            `${element.x}%`;

        object.style.top =
            `${element.y}%`;

        object.style.width =
            `${element.w}%`;

        object.style.height =
            `${element.h}%`;

        const s =
            element.style || {};

        object.style.color =
            s.color || '#fff';

        object.style.background =
            element.type === 'comment'
                ? (
                    s.background === 'transparent'
                        ? 'transparent'
                        : hexToRgba(
                            s.background || '#000000',
                            number(
                                s.opacity,
                                100
                            ) / 100
                        )
                )
                : 'transparent';

        object.style.border =
            `${number(s.borderWidth,0)}px solid ${s.borderColor || '#fff'}`;

        object.style.borderRadius =
            `${number(s.radius,4)}px`;

        object.style.fontSize =
            `${number(s.fontSize,24)}px`;

        object.style.fontWeight =
            String(
                number(
                    s.fontWeight,
                    600
                )
            );

        if (
            element.type === 'comment'
        ) {
            object.textContent =
                element.text ||
                'テキスト';
        }

        if (
            element.type === 'skip'
        ) {
            const label =
                document.createElement('div');

            label.className =
                'skip-label';

            label.textContent =
                'SKIP';

            object.appendChild(
                label
            );
        }

        if (
            element.type === 'box'
        ) {
            object.style.background =
                'transparent';
        }

        const resize =
            document.createElement('div');

        resize.className =
            'resize-handle';

        object.appendChild(
            resize
        );

        POINTS.forEach(point=>{
            const cp =
                document.createElement('div');

            cp.className =
                `connection-point cp-${point}`;

            cp.dataset.point =
                point;

            object.appendChild(cp);
        });

        container.appendChild(
            object
        );
    });
}

function hexToRgba(hex,alpha){
    const value =
        String(hex || '')
            .replace('#','');

    if (
        !/^[0-9a-f]{6}$/i.test(value)
    ) {
        return hex;
    }

    const r =
        parseInt(
            value.slice(0,2),
            16
        );

    const g =
        parseInt(
            value.slice(2,4),
            16
        );

    const b =
        parseInt(
            value.slice(4,6),
            16
        );

    return `rgba(${r},${g},${b},${alpha})`;
}

/* =========================================================
 * Connection
 * ======================================================= */

function pointPosition(element,point){
    const x =
        element.x;

    const y =
        element.y;

    const w =
        element.w;

    const h =
        element.h;

    switch(point){
        case 'n':
            return {
                x:x+w/2,
                y
            };

        case 'ne':
            return {
                x:x+w,
                y
            };

        case 'e':
            return {
                x:x+w,
                y:y+h/2
            };

        case 'se':
            return {
                x:x+w,
                y:y+h
            };

        case 's':
            return {
                x:x+w/2,
                y:y+h
            };

        case 'sw':
            return {
                x,
                y:y+h
            };

        case 'w':
            return {
                x,
                y:y+h/2
            };

        case 'nw':
            return {
                x,
                y
            };

        default:
            return {
                x:x+w,
                y:y+h/2
            };
    }
}

function buildConnectionPath(a,b,curve){
    const sx =
        a.x /
        100 *
        $('videoStage').clientWidth;

    const sy =
        a.y /
        100 *
        $('videoStage').clientHeight;

    const ex =
        b.x /
        100 *
        $('videoStage').clientWidth;

    const ey =
        b.y /
        100 *
        $('videoStage').clientHeight;

    const dx =
        ex - sx;

    const dy =
        ey - sy;

    const bend =
        Math.max(
            10,
            Math.min(
                250,
                Math.abs(dx) *
                number(curve,35) /
                100
            )
        );

    return `
        M ${sx} ${sy}
        C
        ${sx + (dx >= 0 ? bend : -bend)}
        ${sy},
        ${ex - (dx >= 0 ? bend : -bend)}
        ${ey},
        ${ex} ${ey}
    `;
}

function renderConnections(){
    const svg =
        $('connectors');

    svg.innerHTML = '';

    if (!state.project) {
        return;
    }

    const time =
        currentTime();

    state.project.connections.forEach(c=>{
        if (!visible(c,time)) {
            return;
        }

        const from =
            elementById(c.from);

        const to =
            elementById(c.to);

        if (!from || !to) {
            return;
        }

        const p1 =
            pointPosition(
                from,
                c.fromPoint
            );

        const p2 =
            pointPosition(
                to,
                c.toPoint
            );

        const path =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'path'
            );

        path.setAttribute(
            'd',
            buildConnectionPath(
                p1,
                p2,
                c.curve
            )
        );

        path.setAttribute(
            'stroke',
            c.color ||
            from.style?.borderColor ||
            '#ffffff'
        );

        path.setAttribute(
            'stroke-width',
            String(
                number(
                    c.width,
                    1.5
                )
            )
        );

        path.setAttribute(
            'class',
            'connector' +
            (
                state.selected === c.id &&
                state.selectedType === 'connection'
                    ? ' selected'
                    : ''
            )
        );

        if (c.dash) {
            path.setAttribute(
                'stroke-dasharray',
                c.dash
            );
        }

        svg.appendChild(path);

        const hit =
            path.cloneNode();

        hit.setAttribute(
            'class',
            'connector-hit'
        );

        hit.addEventListener(
            'click',
            event=>{
                event.stopPropagation();

                selectConnection(
                    c.id
                );
            }
        );

        svg.appendChild(hit);
    });
}

/* =========================================================
 * Timeline
 * ======================================================= */

function renderTimelineScale(){
    const scale =
        $('timelineScale');

    scale.innerHTML = '';

    const d =
        duration();

    if (!d) {
        return;
    }

    const count =
        Math.min(
            20,
            Math.max(
                2,
                Math.ceil(d / 5)
            )
        );

    for(let i=0;i<=count;i++){
        const span =
            document.createElement('span');

        const time =
            d * i / count;

        span.textContent =
            formatTime(time);

        span.style.left =
            `${i / count * 100}%`;

        scale.appendChild(span);
    }
}

function renderTrack(type,trackId){
    const track =
        $(trackId);

    track.innerHTML = '';

    if (!state.project) {
        return;
    }

    const d =
        duration();

    if (!d) {
        return;
    }

    let items = [];

    if (type === 'connection') {
        items =
            state.project.connections;
    } else {
        items =
            state.project.elements.filter(
                e => e.type === type
            );
    }

    items.forEach(item=>{
        const start =
            clamp(
                number(item.start),
                0,
                d
            );

        const end =
            clamp(
                Math.max(
                    start,
                    number(item.end)
                ),
                start,
                d
            );

        const bar =
            document.createElement('div');

        bar.className =
            `track-item ${type}`;

        if (
            state.selected === item.id &&
            state.selectedType ===
                (
                    type === 'connection'
                        ? 'connection'
                        : 'element'
                )
        ) {
            bar.classList.add(
                'selected'
            );
        }

        bar.style.left =
            `${start / d * 100}%`;

        bar.style.width =
            `${Math.max(
                .2,
                (end-start) / d * 100
            )}%`;

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

        const name =
            document.createElement('span');

        name.className =
            'track-name';

        name.textContent =
            type === 'connection'
                ? '接続線'
                : type === 'comment'
                    ? (
                        item.text ||
                        'テキスト'
                    )
                    : type === 'box'
                        ? '強調枠'
                        : 'スキップ';

        const leftHandle =
            document.createElement('div');

        leftHandle.className =
            'track-handle left';

        const rightHandle =
            document.createElement('div');

        rightHandle.className =
            'track-handle right';

        bar.appendChild(
            startLabel
        );

        bar.appendChild(
            endLabel
        );

        bar.appendChild(
            name
        );

        bar.appendChild(
            leftHandle
        );

        bar.appendChild(
            rightHandle
        );

        bar.addEventListener(
            'click',
            event=>{
                if (
                    event.target.closest(
                        '.track-handle'
                    )
                ) {
                    return;
                }

                event.stopPropagation();

                if (
                    type === 'connection'
                ) {
                    selectConnection(
                        item.id
                    );
                } else {
                    selectElement(
                        item.id
                    );
                }

                $('recordedVideo').currentTime =
                    start;
            }
        );

        bar.addEventListener(
            'dblclick',
            event=>{
                event.stopPropagation();

                if (
                    type === 'connection'
                ) {
                    openConnectionModal(
                        item.id
                    );
                } else {
                    openObjectModal(
                        item.id
                    );
                }
            }
        );

        leftHandle.addEventListener(
            'pointerdown',
            event=>{
                beginTimelineDrag(
                    event,
                    item,
                    'start'
                );
            }
        );

        rightHandle.addEventListener(
            'pointerdown',
            event=>{
                beginTimelineDrag(
                    event,
                    item,
                    'end'
                );
            }
        );

        track.appendChild(bar);
    });
}

function renderTimeline(){
    renderTimelineScale();

    renderTrack(
        'comment',
        'commentTrack'
    );

    renderTrack(
        'box',
        'boxTrack'
    );

    renderTrack(
        'skip',
        'skipTrack'
    );

    renderTrack(
        'connection',
        'connectionTrack'
    );
}

function beginTimelineDrag(event,item,edge){
    event.preventDefault();
    event.stopPropagation();

    const track =
        event.currentTarget.parentElement
            .parentElement;

    const rect =
        track.getBoundingClientRect();

    state.timelineDrag = {
        item,
        edge,
        left:rect.left,
        width:rect.width
    };

    window.addEventListener(
        'pointermove',
        moveTimelineDrag
    );

    window.addEventListener(
        'pointerup',
        endTimelineDrag,
        {once:true}
    );
}

function moveTimelineDrag(event){
    const drag =
        state.timelineDrag;

    if (!drag) return;

    const d =
        duration();

    if (!d) return;

    const ratio =
        clamp(
            (event.clientX -
                drag.left) /
            drag.width,
            0,
            1
        );

    const time =
        ratio * d;

    if (drag.edge === 'start') {
        drag.item.start =
            clamp(
                time,
                0,
                drag.item.end - .05
            );
    } else {
        drag.item.end =
            clamp(
                time,
                drag.item.start + .05,
                d
            );
    }

    markDirty();
    renderAll();
}

function endTimelineDrag(){
    state.timelineDrag = null;

    window.removeEventListener(
        'pointermove',
        moveTimelineDrag
    );
}

/* =========================================================
 * Time / skip
 * ======================================================= */

function updateTimeReadout(){
    const video =
        $('recordedVideo');

    if (!video) return;

    const d =
        duration();

    $('timeReadout').textContent =
        formatTime(video.currentTime) +
        ' / ' +
        formatTime(d);

    if (d > 0) {
        $('seek').max =
            String(d);

        $('seek').value =
            String(
                clamp(
                    video.currentTime,
                    0,
                    d
                )
            );
    }
}

function applySkip(){
    const video =
        $('recordedVideo');

    if (
        !video ||
        video.seeking ||
        video.paused
    ) {
        return;
    }

    const time =
        video.currentTime;

    const skip =
        state.project?.elements?.find(
            e =>
                e.type === 'skip' &&
                time >= e.start &&
                time < e.end
        );

    if (skip) {
        video.currentTime =
            Math.min(
                skip.end,
                duration()
            );
    }
}

/* =========================================================
 * Element pointer operation
 * ======================================================= */

function beginElementPointer(event){
    const object =
        event.target.closest(
            '.edit-object'
        );

    if (!object) return;

    const id =
        object.dataset.id;

    const element =
        elementById(id);

    if (!element) return;

    if (
        event.target.closest(
            '.connection-point'
        )
    ) {
        return;
    }

    const stage =
        $('videoStage');

    const rect =
        stage.getBoundingClientRect();

    const isResize =
        !!event.target.closest(
            '.resize-handle'
        );

    if (event.shiftKey) {
        selectElement(
            id,
            true
        );

        return;
    }

    selectElement(id);

    state.drag = {
        id,
        mode:
            isResize
                ? 'resize'
                : 'move',
        startX:event.clientX,
        startY:event.clientY,
        stageW:rect.width,
        stageH:rect.height,
        originalX:element.x,
        originalY:element.y,
        originalW:element.w,
        originalH:element.h
    };

    event.preventDefault();
}

function moveElementPointer(event){
    const drag =
        state.drag;

    if (!drag) return;

    const element =
        elementById(
            drag.id
        );

    if (!element) {
        state.drag = null;
        return;
    }

    const dx =
        event.clientX -
        drag.startX;

    const dy =
        event.clientY -
        drag.startY;

    if (
        drag.mode === 'move'
    ) {
        element.x =
            clamp(
                drag.originalX +
                dx /
                drag.stageW *
                100,
                0,
                100-element.w
            );

        element.y =
            clamp(
                drag.originalY +
                dy /
                drag.stageH *
                100,
                0,
                100-element.h
            );
    } else {
        element.w =
            clamp(
                drag.originalW +
                dx /
                drag.stageW *
                100,
                .5,
                100-element.x
            );

        element.h =
            clamp(
                drag.originalH +
                dy /
                drag.stageH *
                100,
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
 * Context menu
 * ======================================================= */

function hideContextMenu(){
    $('contextMenu').style.display =
        'none';
}

function showContextMenu(x,y){
    const menu =
        $('contextMenu');

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

function updateContextMenu(){
    const target =
        state.contextTarget;

    const hasElement =
        target?.type === 'element';

    const hasConnection =
        target?.type === 'connection';

    $('ctxEdit').style.display =
        hasElement
            ? 'block'
            : 'none';

    $('ctxDuplicate').style.display =
        hasElement
            ? 'block'
            : 'none';

    $('ctxDelete').style.display =
        hasElement
            ? 'block'
            : 'none';

    $('ctxConnectionEdit').style.display =
        hasConnection
            ? 'block'
            : 'none';

    $('ctxConnectionDelete').style.display =
        hasConnection
            ? 'block'
            : 'none';

    $('ctxConnect').style.display =
        state.multiSelected.length === 2
            ? 'block'
            : 'none';
}

/* =========================================================
 * Duplicate / delete
 * ======================================================= */

function duplicateSelected(){
    if (
        state.selectedType !== 'element'
    ) {
        return;
    }

    const source =
        elementById(
            state.selected
        );

    if (!source) return;

    const copy =
        clone(source);

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

    state.project.elements.push(
        copy
    );

    selectElement(
        copy.id
    );

    markDirty();
    renderAll();
}

function deleteSelected(){
    if (!state.selected) {
        return;
    }

    if (
        state.selectedType === 'element'
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

        state.multiSelected =
            state.multiSelected.filter(
                x => x !== id
            );
    }

    if (
        state.selectedType === 'connection'
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
 * Object modal
 * ======================================================= */

function normalizeColor(value,fallback){
    const text =
        String(value || '');

    return /^#[0-9a-f]{6}$/i.test(text)
        ? text
        : fallback;
}

function buildColorPalette(){
    const grid =
        $('colorGrid');

    grid.innerHTML = '';

    COLORS.forEach(color=>{
        const button =
            document.createElement('button');

        button.className =
            'color-swatch';

        button.style.background =
            color;

        button.title =
            color;

        button.addEventListener(
            'click',
            ()=>{
                $('mColor').value =
                    color;

                $('mBorderColor').value =
                    color;
            }
        );

        grid.appendChild(
            button
        );
    });
}

function openObjectModal(id){
    const element =
        elementById(
            id ||
            state.selected
        );

    if (!element) return;

    state.modalElementId =
        element.id;

    state.selected =
        element.id;

    state.selectedType =
        'element';

    const s =
        element.style || {};

    $('objectModalTitle').textContent =
        element.type === 'comment'
            ? 'テキスト設定'
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

    $('mBorderColor').value =
        normalizeColor(
            s.borderColor,
            '#ffffff'
        );

    $('mFontSize').value =
        number(
            s.fontSize,
            24
        );

    $('mOpacity').value =
        number(
            s.opacity,
            100
        );

    $('mBorderWidth').value =
        number(
            s.borderWidth,
            0
        );

    $('mRadius').value =
        number(
            s.radius,
            4
        );

    $('mWeight').value =
        String(
            number(
                s.fontWeight,
                600
            )
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

function applyObjectStyle(){
    const element =
        elementById(
            state.modalElementId
        );

    if (!element) {
        return;
    }

    const d =
        duration();

    const start =
        clamp(
            number(
                $('mStart').value,
                0
            ),
            0,
            d
        );

    const end =
        clamp(
            number(
                $('mEnd').value,
                d
            ),
            start + .05,
            d
        );

    element.start = start;
    element.end = end;

    element.x =
        clamp(
            number($('mX').value,10),
            0,
            99
        );

    element.y =
        clamp(
            number($('mY').value,10),
            0,
            99
        );

    element.w =
        clamp(
            number($('mW').value,25),
            .5,
            100-element.x
        );

    element.h =
        clamp(
            number($('mH').value,12),
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
        fontSize:
            clamp(
                number(
                    $('mFontSize').value,
                    24
                ),
                6,
                200
            ),
        fontWeight:
            number(
                $('mWeight').value,
                600
            ),
        opacity:
            clamp(
                number(
                    $('mOpacity').value,
                    100
                ),
                0,
                100
            )
    };

    if (
        element.type === 'skip'
    ) {
        element.style.borderColor =
            $('mBorderColor').value;

        element.style.borderWidth =
            Math.max(
                1,
                element.style.borderWidth
            );
    }

    if (
        element.type === 'box'
    ) {
        element.style.background =
            'transparent';
    }

    $('objectModal').style.display =
        'none';

    markDirty();
    renderAll();

    showMessage(
        '要素の設定を変更しました。',
        true
    );
}

/* =========================================================
 * Connection
 * ======================================================= */

function connectSelected(){
    if (
        state.multiSelected.length !== 2
    ) {
        showMessage(
            'Shiftキーを押しながら2つの要素を選択してください。'
        );
        return;
    }

    const from =
        elementById(
            state.multiSelected[0]
        );

    const to =
        elementById(
            state.multiSelected[1]
        );

    if (!from || !to) {
        return;
    }

    if (from.id === to.id) {
        return;
    }

    const d =
        duration();

    const color =
        from.style?.borderColor ||
        '#ffffff';

    const connection = {
        id:uid('connection'),
        from:from.id,
        to:to.id,
        fromPoint:'e',
        toPoint:'w',
        start:
            clamp(
                currentTime(),
                0,
                d
            ),
        end:
            clamp(
                currentTime()+5,
                .05,
                d
            ),
        color,
        width:1.5,
        dash:'',
        curve:35
    };

    if (
        connection.end <=
        connection.start
    ) {
        connection.start =
            Math.max(
                0,
                d - 1
            );

        connection.end =
            d;
    }

    state.project.connections.push(
        connection
    );

    state.selected =
        connection.id;

    state.selectedType =
        'connection';

    state.multiSelected = [];

    markDirty();
    renderAll();

    showMessage(
        '2つの要素を接続しました。',
        true
    );
}

function openConnectionModal(id){
    const connection =
        connectionById(
            id ||
            state.selected
        );

    if (!connection) {
        return;
    }

    state.modalConnectionId =
        connection.id;

    $('cStart').value =
        connection.start;

    $('cEnd').value =
        connection.end;

    $('cFromPoint').value =
        connection.fromPoint;

    $('cToPoint').value =
        connection.toPoint;

    $('cWidth').value =
        connection.width;

    $('cColor').value =
        normalizeColor(
            connection.color,
            '#ffffff'
        );

    $('cDash').value =
        connection.dash || '';

    $('cCurve').value =
        connection.curve;

    $('connectionModal').style.display =
        'flex';
}

function applyConnection(){
    const connection =
        connectionById(
            state.modalConnectionId
        );

    if (!connection) {
        return;
    }

    const d =
        duration();

    connection.start =
        clamp(
            number(
                $('cStart').value,
                0
            ),
            0,
            d
        );

    connection.end =
        clamp(
            number(
                $('cEnd').value,
                d
            ),
            connection.start + .05,
            d
        );

    connection.fromPoint =
        $('cFromPoint').value;

    connection.toPoint =
        $('cToPoint').value;

    connection.width =
        clamp(
            number(
                $('cWidth').value,
                1.5
            ),
            1,
            10
        );

    connection.color =
        $('cColor').value;

    connection.dash =
        $('cDash').value;

    connection.curve =
        clamp(
            number(
                $('cCurve').value,
                35
            ),
            0,
            100
        );

    $('connectionModal').style.display =
        'none';

    markDirty();
    renderAll();

    showMessage(
        '接続線を変更しました。',
        true
    );
}

function deleteConnection(id){
    const target =
        connectionById(id);

    if (!target) return;

    state.project.connections =
        state.project.connections.filter(
            c => c.id !== target.id
        );

    selectNone();
    markDirty();
    renderAll();
}

/* =========================================================
 * Save
 * ======================================================= */

function projectForSave(){
    if (!state.project) {
        throw new Error(
            '編集対象がありません。'
        );
    }

    const project =
        normalizeProject(
            clone(
                state.project
            )
        );

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

    project.duration =
        duration();

    project.savedAt =
        nowISO();

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

        setEditorStatus(
            'ローカル保存済み'
        );

        showMessage(
            'ローカルに保存しました。',
            true
        );

        renderProjectList();
    }catch(error){
        console.error(error);

        showMessage(
            'ローカル保存に失敗しました：' +
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
                '?api=save&_=' +
                Date.now(),
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/json'
                    },
                    cache:'no-store',
                    body:
                        JSON.stringify(
                            project
                        )
                }
            );

        const result =
            await response.json();

        if (
            !response.ok ||
            !result.ok
        ) {
            if (result.limit) {
                await idbPut(
                    'projects',
                    project
                );

                state.project =
                    normalizeProject(
                        project
                    );

                state.dirty = false;

                setEditorStatus(
                    '上限超過のためローカル保存済み'
                );

                showMessage(
                    'サーバー保存上限に達したため、ローカルへ保存しました。',
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
            normalizeProject(
                project
            );

        state.dirty = false;

        setEditorStatus(
            'サーバー保存済み'
        );

        showMessage(
            'サーバーに保存しました。',
            true
        );

        renderProjectList();

    }catch(error){
        console.error(error);

        showMessage(
            'サーバー保存に失敗しました：' +
            error.message
        );
    }
}

/* =========================================================
 * Export / Import
 * ======================================================= */

function downloadText(filename,text,type){
    const blob =
        new Blob(
            [text],
            {type}
        );

    const url =
        URL.createObjectURL(
            blob
        );

    const a =
        document.createElement('a');

    a.href = url;
    a.download = filename;

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(
        () => URL.revokeObjectURL(url),
        1000
    );
}

function exportProject(){
    try{
        const project =
            projectForSave();

        downloadText(
            (
                project.name ||
                'project'
            ) +
            '.json',
            JSON.stringify(
                project,
                null,
                2
            ),
            'application/json'
        );

        showMessage(
            'プロジェクトJSONを書き出しました。',
            true
        );
    }catch(error){
        showMessage(
            error.message
        );
    }
}

async function importProjectFile(file){
    if (!file) return;

    try{
        const text =
            await file.text();

        const project =
            JSON.parse(text);

        const normalized =
            normalizeProject(
                project
            );

        if (
            !normalized.videoName
        ) {
            throw new Error(
                '動画情報がないプロジェクトです。'
            );
        }

        /*
         * 動画本体はJSONに含めない。
         * IndexedDBに存在すれば即開く。
         * なければ動画選択へ進む。
         */
        if (
            normalized.videoKey
        ) {
            const record =
                await idbGet(
                    'videos',
                    normalized.videoKey
                );

            if (record?.blob) {
                await openEditor(
                    normalized,
                    record.blob
                );

                showMessage(
                    'プロジェクトを読み込みました。',
                    true
                );

                return;
            }
        }

        state.pendingProject =
            normalized;

        showMessage(
            'プロジェクトは読み込みました。対応する動画ファイルを選択してください。',
            true
        );

        chooseVideo();

    }catch(error){
        console.error(error);

        showMessage(
            'プロジェクト読み込みに失敗しました：' +
            error.message
        );
    }
}

/* =========================================================
 * Project list
 * ======================================================= */

async function renderProjectList(){
    const box =
        $('projectList');

    if (!box) return;

    box.innerHTML = '';

    try{
        const response =
            await fetch(
                '?api=list&_=' +
                Date.now(),
                {
                    cache:'no-store'
                }
            );

        const server =
            await response.json();

        const local =
            await idbGetAll(
                'projects'
            );

        const serverIds =
            new Set(
                (server.projects || [])
                    .map(p => p.projectId)
            );

        const all = [];

        (server.projects || []).forEach(
            p =>
                all.push({
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
                p =>
                    all.push({
                        ...p,
                        storage:'local'
                    })
            );

        if (!all.length) {
            const help =
                document.createElement('div');

            help.className =
                'help';

            help.style.marginTop =
                '20px';

            help.textContent =
                '保存済みプロジェクトはありません。';

            box.appendChild(help);

            return;
        }

        const title =
            document.createElement('h3');

        title.textContent =
            '保存済みプロジェクト';

        box.appendChild(title);

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
                (
                    project.storage === 'server'
                        ? 'サーバー'
                        : 'ローカル'
                ) +
                ' / ' +
                (
                    project.videoName ||
                    '動画不明'
                ) +
                ' / ' +
                (
                    project.savedAt ||
                    ''
                );

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

            actions.appendChild(
                load
            );

            const del =
                document.createElement('button');

            del.textContent =
                '削除';

            del.className =
                'danger';

            del.addEventListener(
                'click',
                () =>
                    project.storage === 'server'
                        ? deleteServerProject(
                            project.projectId
                        )
                        : deleteLocalProject(
                            project.projectId
                        )
            );

            actions.appendChild(
                del
            );

            row.appendChild(info);
            row.appendChild(actions);

            box.appendChild(row);
        });

    }catch(error){
        console.error(error);

        box.innerHTML =
            '<div class="help">保存データを取得できませんでした。</div>';
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
            normalizeProject(
                project
            );

        if (
            project.videoKey
        ) {
            const record =
                await idbGet(
                    'videos',
                    project.videoKey
                );

            if (record?.blob) {
                await openEditor(
                    project,
                    record.blob
                );

                showMessage(
                    'プロジェクトを読み込みました。',
                    true
                );

                return;
            }
        }

        state.pendingProject =
            project;

        showMessage(
            'プロジェクトは読み込みましたが、動画本体がこのブラウザにありません。動画ファイルを選択してください。',
            true
        );

        chooseVideo();

    }catch(error){
        console.error(error);

        showMessage(
            '読み込みに失敗しました：' +
            error.message
        );
    }
}

async function deleteLocalProject(id){
    if (
        !confirm(
            'ローカルプロジェクトを削除しますか？'
        )
    ) {
        return;
    }

    try{
        await idbDelete(
            'projects',
            id
        );

        renderProjectList();

        showMessage(
            'ローカルプロジェクトを削除しました。',
            true
        );
    }catch(error){
        showMessage(
            error.message
        );
    }
}

async function deleteServerProject(id){
    if (
        !confirm(
            'サーバープロジェクトを削除しますか？'
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
 * Recorder
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

        let stream =
            display;

        if (
            $('microphone').checked
        ) {
            const mic =
                await navigator.mediaDevices.getUserMedia({
                    audio:true
                });

            mic.getAudioTracks()
                .forEach(
                    track =>
                        stream.addTrack(track)
                );
        }

        state.recordStream =
            stream;

        $('preview').srcObject =
            stream;

        const types = [
            'video/webm;codecs=vp9,opus',
            'video/webm;codecs=vp8,opus',
            'video/webm'
        ];

        const mimeType =
            types.find(
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

        stream.getVideoTracks()[0].onended =
            () => {
                if (
                    state.recorder &&
                    state.recorder.state !==
                        'inactive'
                ) {
                    state.recorder.stop();
                }
            };

    }catch(error){
        console.error(error);

        showMessage(
            '録画を開始できませんでした：' +
            error.message
        );
    }
}

function updateRecordTimer(){
    if (!state.recordStartedAt) {
        return;
    }

    const elapsed =
        Math.floor(
            (
                Date.now() -
                state.recordStartedAt
            ) / 1000
        );

    const h =
        Math.floor(
            elapsed / 3600
        );

    const m =
        Math.floor(
            elapsed % 3600 / 60
        );

    const s =
        elapsed % 60;

    $('timer').textContent =
        [
            h,
            m,
            s
        ]
            .map(
                x =>
                    String(x).padStart(
                        2,
                        '0'
                    )
            )
            .join(':');
}

function pauseRecording(){
    if (!state.recorder) {
        return;
    }

    if (
        state.recorder.state === 'recording'
    ) {
        state.recorder.pause();
        $('pauseRecord').textContent =
            '再開';
    } else if (
        state.recorder.state === 'paused'
    ) {
        state.recorder.resume();
        $('pauseRecord').textContent =
            '一時停止';
    }
}

function stopRecording(){
    if (
        state.recorder &&
        state.recorder.state !== 'inactive'
    ) {
        state.recorder.stop();
    }
}

async function finishRecording(){
    clearInterval(
        state.recordTimer
    );

    state.recordTimer =
        null;

    state.recordStream
        ?.getTracks()
        .forEach(
            track =>
                track.stop()
        );

    const type =
        state.recordChunks[0]?.type ||
        'video/webm';

    const blob =
        new Blob(
            state.recordChunks,
            {type}
        );

    state.recordChunks = [];

    $('preview').srcObject =
        null;

    $('startRecord').disabled =
        false;

    $('pauseRecord').disabled =
        true;

    $('stopRecord').disabled =
        true;

    $('pauseRecord').textContent =
        '一時停止';

    try{
        const metadata =
            await validateVideoBlob(
                blob
            );

        const project =
            createEmptyProject(
                '画面録画 ' +
                new Date()
                    .toLocaleString(),
                'recording.webm'
            );

        project.duration =
            metadata.duration;

        await openEditor(
            project,
            blob,
            metadata
        );

        try{
            const key =
                await saveVideoBlob(
                    blob,
                    'recording.webm',
                    type
                );

            state.project.videoKey =
                key;

            markDirty();

        }catch(error){
            console.error(error);
        }

        showMessage(
            '録画した動画を編集対象として読み込みました。',
            true
        );

    }catch(error){
        showMessage(
            '録画データを編集できませんでした：' +
            error.message
        );
    }
}

/* =========================================================
 * Close editor
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

    hideContextMenu();

    if (state.videoUrl) {
        URL.revokeObjectURL(
            state.videoUrl
        );

        state.videoUrl = '';
    }

    $('recordedVideo').pause();

    $('recordedVideo')
        .removeAttribute('src');

    $('recordedVideo').load();

    state.project = null;
    state.videoBlob = null;
    state.selected = null;
    state.selectedType = null;
    state.multiSelected = [];
    state.drag = null;
    state.timelineDrag = null;
    state.dirty = false;

    setStatus('待機中');

    renderProjectList();
}

/* =========================================================
 * Event binding
 * ======================================================= */

function bindEvents(){

    $('newVideoBtn')
        ?.addEventListener(
            'click',
            chooseVideo
        );

    $('videoFile')
        ?.addEventListener(
            'change',
            async event=>{
                const file =
                    event.target.files?.[0];

                event.target.value = '';

                await handleVideoFile(
                    file
                );
            }
        );

    $('manageBtn')
        ?.addEventListener(
            'click',
            renderProjectList
        );

    $('recordScreenBtn')
        ?.addEventListener(
            'click',
            ()=>{
                $('home').style.display =
                    'none';

                $('recorder').style.display =
                    'flex';
            }
        );

    $('recordCancel')
        ?.addEventListener(
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
        ?.addEventListener(
            'click',
            startRecording
        );

    $('pauseRecord')
        ?.addEventListener(
            'click',
            pauseRecording
        );

    $('stopRecord')
        ?.addEventListener(
            'click',
            stopRecording
        );

    $('backHome')
        ?.addEventListener(
            'click',
            closeEditor
        );

    $('saveServer')
        ?.addEventListener(
            'click',
            saveServer
        );

    $('saveLocal')
        ?.addEventListener(
            'click',
            saveLocal
        );

    $('exportJson')
        ?.addEventListener(
            'click',
            exportProject
        );

    $('importJson')
        ?.addEventListener(
            'click',
            () =>
                $('jsonFile').click()
        );

    $('jsonFile')
        ?.addEventListener(
            'change',
            async event=>{
                const file =
                    event.target.files?.[0];

                event.target.value = '';

                await importProjectFile(
                    file
                );
            }
        );

    $('editorProjectName')
        ?.addEventListener(
            'input',
            ()=>{
                if (state.project) {
                    state.project.name =
                        $('editorProjectName')
                            .value;

                    markDirty();
                }
            }
        );

    $('seek')
        ?.addEventListener(
            'input',
            event=>{
                const d =
                    duration();

                $('recordedVideo')
                    .currentTime =
                    clamp(
                        number(
                            event.target.value
                        ),
                        0,
                        d
                    );
            }
        );

    $('playVideo')
        ?.addEventListener(
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
        ?.addEventListener(
            'click',
            ()=>{
                $('recordedVideo')
                    .pause();
            }
        );

    $('clearSelection')
        ?.addEventListener(
            'click',
            selectNone
        );

    $('deleteSelected')
        ?.addEventListener(
            'click',
            deleteSelected
        );

    $('recordedVideo')
        ?.addEventListener(
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
                    if (state.project) {
                        state.project.duration =
                            d;
                    }

                    $('seek').min =
                        '0';

                    $('seek').max =
                        String(d);

                    $('seek').disabled =
                        false;
                }

                updateStageSize();
                renderAll();
            }
        );

    $('recordedVideo')
        ?.addEventListener(
            'timeupdate',
            ()=>{
                updateTimeReadout();
                applySkip();
                renderObjects();
                renderConnections();
            }
        );

    $('recordedVideo')
        ?.addEventListener(
            'seeked',
            renderAll
        );

    $('videoArea')
        ?.addEventListener(
            'pointerdown',
            beginElementPointer
        );

    window.addEventListener(
        'pointermove',
        moveElementPointer
    );

    window.addEventListener(
        'pointerup',
        endElementPointer
    );

    /*
     * 動画画面右クリック。
     */
    $('videoStage')
        ?.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();

                const object =
                    event.target.closest(
                        '.edit-object'
                    );

                const connectionPath =
                    event.target.closest(
                        '.connector-hit'
                    );

                if (object) {
                    const id =
                        object.dataset.id;

                    state.contextTarget = {
                        type:'element',
                        id
                    };

                    selectElement(id);

                } else if (
                    connectionPath
                ) {
                    /*
                     * SVG hit pathにはIDを持たせていないので
                     * 選択済み接続を対象にする。
                     */
                    state.contextTarget = {
                        type:'connection',
                        id:
                            state.selectedType ===
                                'connection'
                                ? state.selected
                                : null
                    };

                } else {
                    const rect =
                        $('videoStage')
                            .getBoundingClientRect();

                    state.contextTarget = {
                        type:'stage',
                        x:
                            (
                                event.clientX -
                                rect.left
                            ) /
                            rect.width *
                            100,
                        y:
                            (
                                event.clientY -
                                rect.top
                            ) /
                            rect.height *
                            100
                    };
                }

                updateContextMenu();

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
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

    $('ctxAddComment')
        ?.addEventListener(
            'click',
            ()=>{
                const t =
                    state.contextTarget;

                addElement(
                    'comment',
                    t?.x ?? null,
                    t?.y ?? null
                );

                hideContextMenu();
            }
        );

    $('ctxAddBox')
        ?.addEventListener(
            'click',
            ()=>{
                const t =
                    state.contextTarget;

                addElement(
                    'box',
                    t?.x ?? null,
                    t?.y ?? null
                );

                hideContextMenu();
            }
        );

    $('ctxAddSkip')
        ?.addEventListener(
            'click',
            ()=>{
                const t =
                    state.contextTarget;

                addElement(
                    'skip',
                    t?.x ?? null,
                    t?.y ?? null
                );

                hideContextMenu();
            }
        );

    $('ctxEdit')
        ?.addEventListener(
            'click',
            ()=>{
                if (
                    state.contextTarget?.id
                ) {
                    openObjectModal(
                        state.contextTarget.id
                    );
                }

                hideContextMenu();
            }
        );

    $('ctxDuplicate')
        ?.addEventListener(
            'click',
            ()=>{
                duplicateSelected();
                hideContextMenu();
            }
        );

    $('ctxDelete')
        ?.addEventListener(
            'click',
            ()=>{
                deleteSelected();
                hideContextMenu();
            }
        );

    $('ctxConnect')
        ?.addEventListener(
            'click',
            ()=>{
                connectSelected();
                hideContextMenu();
            }
        );

    $('ctxConnectionEdit')
        ?.addEventListener(
            'click',
            ()=>{
                if (
                    state.contextTarget?.id
                ) {
                    openConnectionModal(
                        state.contextTarget.id
                    );
                } else {
                    openConnectionModal();
                }

                hideContextMenu();
            }
        );

    $('ctxConnectionDelete')
        ?.addEventListener(
            'click',
            ()=>{
                deleteConnection(
                    state.contextTarget?.id ||
                    state.selected
                );

                hideContextMenu();
            }
        );

    $('cancelObjectStyle')
        ?.addEventListener(
            'click',
            ()=>{
                $('objectModal')
                    .style.display =
                    'none';
            }
        );

    $('applyObjectStyle')
        ?.addEventListener(
            'click',
            applyObjectStyle
        );

    $('cancelConnection')
        ?.addEventListener(
            'click',
            ()=>{
                $('connectionModal')
                    .style.display =
                    'none';
            }
        );

    $('applyConnection')
        ?.addEventListener(
            'click',
            applyConnection
        );

    $('objectModal')
        ?.addEventListener(
            'click',
            event=>{
                if (
                    event.target ===
                    $('objectModal')
                ) {
                    event.currentTarget
                        .style.display =
                        'none';
                }
            }
        );

    $('connectionModal')
        ?.addEventListener(
            'click',
            event=>{
                if (
                    event.target ===
                    $('connectionModal')
                ) {
                    event.currentTarget
                        .style.display =
                        'none';
                }
            }
        );

    document.addEventListener(
        'keydown',
        event=>{
            if (
                event.key === 'Escape'
            ) {
                hideContextMenu();

                $('objectModal')
                    .style.display =
                    'none';

                $('connectionModal')
                    .style.display =
                    'none';
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
 * Render all
 * ======================================================= */

function renderAll(){
    if (!state.project) {
        return;
    }

    renderObjects();
    renderConnections();
    renderTimeline();
    updateTimeReadout();
}

/* =========================================================
 * Init
 * ======================================================= */

async function init(){
    try{
        buildColorPalette();

        await openDB();

        bindEvents();

        setStatus(
            '待機中'
        );

        await renderProjectList();

        try{
            const local =
                await idbGetAll(
                    'projects'
                );

            if (local.length) {
                setStatus(
                    '待機中 / ローカル保存 ' +
                    local.length +
                    '件'
                );
            }
        }catch(error){
            console.warn(
                'ローカルプロジェクト一覧取得失敗',
                error
            );
        }

    }catch(error){
        console.error(error);

        setStatus(
            '初期化エラー'
        );

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

<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * 保存:
 *   サーバー: ./data/projects/*.json
 *   ブラウザ: IndexedDB
 *
 * 動画本体:
 *   IndexedDB
 *
 * 編集データ:
 *   サーバーJSON / IndexedDB / JSON書き出し
 */

const APP_VERSION = 5;
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
                'message' =>
                    'data/projects に書き込めません。'
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

        $id = (string)($payload['projectId'] ?? '');

        if ($id === '') {
            $id = 'project-' . bin2hex(random_bytes(10));
        }

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
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
                'message' =>
                    'プロジェクト名は120文字以内にしてください。'
            ], 422);
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
    --cyan:#36b8d4;
    --skip:#e38b28;
}

*{box-sizing:border-box}

html,body{
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

body{overflow:hidden}

button,input,select,textarea{
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

button.primary{background:var(--blue)}
button.success{background:var(--green)}
button.danger{background:var(--red)}
button.active{
    background:#b67d19;
    border-color:#ffd34d;
}

input,select,textarea{
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
    position:absolute;
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
    width:13px;
    height:13px;
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
    stroke:#fff;
    stroke-width:2.5;
    pointer-events:none;
}

.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:18;
    pointer-events:stroke;
    cursor:pointer;
}

.connector.selected{
    stroke:var(--yellow);
    stroke-width:5;
}

/* TIMELINE */

#timeline{
    height:190px;
    flex-shrink:0;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:8px 12px;
}

#seek{
    width:100%;
    margin:0;
}

.timeline-scale{
    position:relative;
    height:22px;
    margin-left:78px;
    margin-right:4px;
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
    grid-template-columns:78px 1fr;
    gap:7px;
    margin-top:5px;
    align-items:center;
    font-size:11px;
    color:#b0b7c0;
}

.track{
    position:relative;
    height:27px;
    background:#292d33;
    border-radius:4px;
    border:1px solid #3c4249;
}

.track-item{
    position:absolute;
    top:3px;
    height:19px;
    min-width:4px;
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
    background:var(--skip);
}

.track-item.connection{
    background:#a66be8;
}

.track-label{
    position:absolute;
    top:21px;
    transform:translateX(-50%);
    font-size:9px;
    color:#d7dce2;
    white-space:nowrap;
    pointer-events:none;
}

.track-time{
    position:absolute;
    top:2px;
    left:50%;
    transform:translateX(-50%);
    font-size:9px;
    color:#fff;
    white-space:nowrap;
    pointer-events:none;
}

#timeReadout{
    min-width:130px;
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

/* CONTEXT */

#contextMenu{
    position:fixed;
    z-index:7000;
    display:none;
    width:235px;
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
    width:min(600px,96vw);
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

.connection-status{
    margin-top:8px;
    padding:8px;
    border-radius:5px;
    background:#15181c;
    border:1px solid #414750;
    color:#d9dee4;
    font-size:12px;
}

@media(max-width:850px){
    #editorProjectName{
        width:150px;
    }

    .timeline-row{
        grid-template-columns:60px 1fr;
    }

    .timeline-scale{
        margin-left:60px;
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
            動画本体はブラウザのIndexedDBへ永続保存し、
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
                    controls
                    playsinline
                ></video>

                <div id="overlay">

                    <svg id="connectors"></svg>

                    <div id="objects"></div>

                </div>

            </div>

        </div>

    </div>

    <div id="timeline">

        <input
            id="seek"
            type="range"
            min="0"
            max="0"
            value="0"
            step=".01"
        >

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
            00:00 / 00:00
        </span>

        <button id="startPoint">
            開始を現在位置
        </button>

        <button id="endPoint">
            終了を現在位置
        </button>

        <span
            id="connectionStatus"
            class="connection-status"
        >
            通常操作
        </span>

    </div>

</section>

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

    <button data-action="style">
        書式・内容を変更
    </button>

    <button data-action="duplicate">
        複製
    </button>

    <button data-action="delete">
        削除
    </button>

</div>

<div id="objectModal" class="modal-backdrop">

    <div class="modal">

        <h2>要素の設定</h2>

        <div class="modal-grid">

            <label id="mTextField" class="field full">
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
                <input id="mColor" type="color">
            </label>

            <label class="field">
                背景色
                <input id="mBg" type="color">
            </label>

            <label class="field">
                文字サイズ
                <input
                    id="mFontSize"
                    type="number"
                    min="8"
                    max="200"
                >
            </label>

            <label class="field">
                不透明度 %
                <input
                    id="mOpacity"
                    type="number"
                    min="0"
                    max="100"
                >
            </label>

            <label class="field">
                枠色
                <input
                    id="mBorderColor"
                    type="color"
                >
            </label>

            <label class="field">
                枠幅
                <input
                    id="mBorderWidth"
                    type="number"
                    min="0"
                    max="30"
                >
            </label>

            <label class="field">
                角丸
                <input
                    id="mRadius"
                    type="number"
                    min="0"
                    max="100"
                >
            </label>

            <label class="field">
                太さ
                <select id="mWeight">
                    <option value="400">標準</option>
                    <option value="500">中</option>
                    <option value="600">太字</option>
                    <option value="700">強調</option>
                    <option value="800">極太</option>
                </select>
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
                反映
            </button>

        </div>

    </div>

</div>

<div id="connectionModal" class="modal-backdrop">

    <div class="modal">

        <h2>接続線の設定</h2>

        <div class="modal-grid">

            <label class="field">
                始点
                <select id="cFromPoint"></select>
            </label>

            <label class="field">
                終点
                <select id="cToPoint"></select>
            </label>

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
                <input id="cColor" type="color">
            </label>

            <label class="field">
                太さ
                <input
                    id="cWidth"
                    type="number"
                    min="1"
                    max="30"
                    step=".5"
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
                曲線
                <select id="cCurve">
                    <option value="straight">直線</option>
                    <option value="smooth">曲線</option>
                </select>
            </label>

            <label class="field">
                始点記号
                <select id="cStartArrow">
                    <option value="none">なし</option>
                    <option value="arrow">矢印</option>
                    <option value="circle">丸</option>
                </select>
            </label>

            <label class="field">
                終点記号
                <select id="cEndArrow">
                    <option value="arrow">矢印</option>
                    <option value="none">なし</option>
                    <option value="circle">丸</option>
                </select>
            </label>

        </div>

        <div class="modal-footer">

            <button id="cancelConnectionStyle">
                キャンセル
            </button>

            <button
                id="applyConnectionStyle"
                class="primary"
            >
                反映
            </button>

        </div>

    </div>

</div>

<div id="manageModal" class="modal-backdrop">

    <div class="modal">

        <h2>保存データ管理</h2>

        <div id="manageInfo" class="help"></div>

        <div id="manageList"></div>

        <div class="modal-footer">

            <button id="closeManage">
                閉じる
            </button>

        </div>

    </div>

</div>

<script>
const APP_VERSION = <?= json_encode(APP_VERSION) ?>;
const MAX_SERVER_PROJECTS = <?= json_encode(MAX_SERVER_PROJECTS) ?>;
const API_BASE = location.pathname;

const state = {
    project:null,
    videoBlob:null,
    videoUrl:'',
    selected:null,
    selectedType:null,
    contextTarget:null,
    contextPosition:{x:0,y:0},
    connectMode:false,
    connectSource:null,
    db:null,
    recorder:null,
    recordStream:null,
    recordTimer:null,
    recordStartedAt:0,
    recordChunks:[],
    dirty:false,
    saving:false,
    drag:null
};

const $ = id => document.getElementById(id);

function uid(prefix='id'){
    if(window.crypto?.randomUUID){
        return prefix + '-' + crypto.randomUUID();
    }

    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2);
}

function clone(value){
    return JSON.parse(JSON.stringify(value));
}

function number(value,fallback=0){
    const n=Number(value);
    return Number.isFinite(n)?n:fallback;
}

function clamp(value,min,max){
    return Math.min(max,Math.max(min,value));
}

function nowISO(){
    return new Date().toISOString();
}

function formatTime(seconds){
    seconds=Math.max(0,number(seconds));

    const h=Math.floor(seconds/3600);
    const m=Math.floor((seconds%3600)/60);
    const s=Math.floor(seconds%60);

    return [
        String(h).padStart(2,'0'),
        String(m).padStart(2,'0'),
        String(s).padStart(2,'0')
    ].join(':');
}

function formatTimeShort(seconds){
    seconds=Math.max(0,number(seconds));

    const m=Math.floor(seconds/60);
    const s=Math.floor(seconds%60);

    return String(m).padStart(2,'0') +
        ':' +
        String(s).padStart(2,'0');
}

function escapeHtml(value){
    return String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

function showMessage(text,ok=false){
    const el=$('message');

    el.textContent=text;
    el.className=ok?'ok':'';
    el.style.display='block';

    clearTimeout(showMessage.timer);

    showMessage.timer=setTimeout(()=>{
        el.style.display='none';
    },3500);
}

function setStatus(text){
    $('status').textContent=text;
}

function setEditorStatus(text){
    $('editorStatus').textContent=text;
}

function markDirty(){
    state.dirty=true;
    setEditorStatus('未保存');
}

function markClean(text='保存済み'){
    state.dirty=false;
    setEditorStatus(text);
}

/* =========================================================
 * PROJECT
 * ======================================================= */

function createEmptyProject(name,videoName=''){
    return {
        version:APP_VERSION,
        projectId:uid('project'),
        name:String(name || '名称未設定').trim(),
        videoName,
        videoKey:'',
        createdAt:nowISO(),
        savedAt:'',
        duration:0,
        elements:[],
        connections:[]
    };
}

function normalizeElement(element){
    element.id ||= uid('element');
    element.type ||= 'comment';

    element.text ??=
        element.type==='comment'
            ? 'ここにコメント'
            : '';

    element.x=clamp(number(element.x,10),0,99);
    element.y=clamp(number(element.y,10),0,99);

    element.w=clamp(
        number(element.w,30),
        .5,
        100-element.x
    );

    element.h=clamp(
        number(element.h,20),
        .5,
        100-element.y
    );

    element.start=Math.max(
        0,
        number(element.start,0)
    );

    element.end=Math.max(
        element.start,
        number(
            element.end,
            state.project?.duration || element.start+5
        )
    );

    element.style={
        color:'#ffffff',
        background:
            element.type==='box'
                ? '#ff000000'
                : '#000000cc',
        fontSize:24,
        opacity:100,
        borderColor:
            element.type==='box'
                ? '#ff3b30'
                : '#ffffff',
        borderWidth:
            element.type==='box'
                ? 3
                : 0,
        radius:4,
        fontWeight:600,
        ...(element.style || {})
    };

    return element;
}

function normalizeConnection(connection){
    connection.id ||= uid('connection');

    connection.fromPoint ||= 'e';
    connection.toPoint ||= 'w';

    connection.color ||= '#ffffff';
    connection.width=number(
        connection.width,
        2.5
    );

    connection.dash ??='';
    connection.startArrow ||= 'none';
    connection.endArrow ||= 'arrow';
    connection.curve ||= 'straight';

    connection.start=Math.max(
        0,
        number(connection.start,0)
    );

    connection.end=Math.max(
        connection.start,
        number(
            connection.end,
            state.project?.duration || 999999
        )
    );

    return connection;
}

function normalizeProject(project){
    project.version=APP_VERSION;
    project.elements=Array.isArray(project.elements)
        ? project.elements.map(normalizeElement)
        : [];

    project.connections=Array.isArray(project.connections)
        ? project.connections.map(normalizeConnection)
        : [];

    return project;
}

/* =========================================================
 * INDEXED DB
 * ======================================================= */

function openDB(){
    return new Promise((resolve,reject)=>{
        const request=indexedDB.open(
            'videoOverlayEditor',
            3
        );

        request.onupgradeneeded=event=>{
            const db=event.target.result;

            if(!db.objectStoreNames.contains('videos')){
                db.createObjectStore(
                    'videos',
                    {keyPath:'key'}
                );
            }

            if(!db.objectStoreNames.contains('projects')){
                db.createObjectStore(
                    'projects',
                    {keyPath:'projectId'}
                );
            }
        };

        request.onsuccess=()=>{
            state.db=request.result;
            resolve(state.db);
        };

        request.onerror=()=>{
            reject(request.error);
        };
    });
}

function idbPut(store,value){
    return new Promise((resolve,reject)=>{
        const tx=state.db.transaction(
            store,
            'readwrite'
        );

        tx.objectStore(store).put(value);

        tx.oncomplete=()=>resolve();
        tx.onerror=()=>reject(tx.error);
    });
}

function idbGet(store,key){
    return new Promise((resolve,reject)=>{
        const tx=state.db.transaction(
            store,
            'readonly'
        );

        const req=tx.objectStore(store).get(key);

        req.onsuccess=()=>resolve(req.result);
        req.onerror=()=>reject(req.error);
    });
}

function idbGetAll(store){
    return new Promise((resolve,reject)=>{
        const tx=state.db.transaction(
            store,
            'readonly'
        );

        const req=tx.objectStore(store).getAll();

        req.onsuccess=()=>resolve(req.result);
        req.onerror=()=>reject(req.error);
    });
}

function idbDelete(store,key){
    return new Promise((resolve,reject)=>{
        const tx=state.db.transaction(
            store,
            'readwrite'
        );

        tx.objectStore(store).delete(key);

        tx.oncomplete=()=>resolve();
        tx.onerror=()=>reject(tx.error);
    });
}

/* =========================================================
 * VIDEO
 * ======================================================= */

async function saveVideoBlob(blob,name,type='video/mp4'){
    const key=uid('video');

    await idbPut('videos',{
        key,
        name,
        type:type || blob.type || 'video/mp4',
        size:blob.size,
        savedAt:nowISO(),
        blob
    });

    return key;
}

async function chooseVideo(){
    $('videoFile').click();
}

$('videoFile').addEventListener(
    'change',
    async event=>{
        const file=event.target.files?.[0];

        event.target.value='';

        if(!file){
            return;
        }

        if(!file.type.startsWith('video/')){
            showMessage(
                '動画ファイルを選択してください。'
            );
            return;
        }

        try{
            setStatus('動画を読み込んでいます…');

            const key=await saveVideoBlob(
                file,
                file.name,
                file.type
            );

            const project=createEmptyProject(
                file.name.replace(/\.[^.]+$/,''),
                file.name
            );

            project.videoKey=key;

            await openEditor(
                project,
                file
            );

            showMessage(
                '動画の読み込みが完了しました。編集できます。',
                true
            );
        }catch(error){
            showMessage(
                '動画を読み込めませんでした：' +
                error.message
            );
        }
    }
);

/* =========================================================
 * EDITOR
 * ======================================================= */

async function openEditor(project,blob){
    if(!blob){
        if(project.videoKey){
            const record=await idbGet(
                'videos',
                project.videoKey
            );

            if(record){
                blob=record.blob;
            }
        }
    }

    if(!blob){
        throw new Error(
            '編集対象の動画本体が見つかりません。'
        );
    }

    state.project=normalizeProject(
        clone(project)
    );

    state.videoBlob=blob;

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoUrl=URL.createObjectURL(blob);

    $('recordedVideo').src=state.videoUrl;

    $('home').style.display='none';
    $('recorder').style.display='none';
    $('editor').style.display='flex';

    $('editorProjectName').value=
        state.project.name;

    state.selected=null;
    state.selectedType=null;
    state.connectSource=null;
    state.connectMode=false;

    updateConnectionStatus();

    await waitVideoMetadata();

    /*
     * ここが重要。
     * 動画のmetadataが確定した後でdurationを採用する。
     */
    state.project.duration=
        Number.isFinite(
            $('recordedVideo').duration
        )
            ? $('recordedVideo').duration
            : state.project.duration;

    $('seek').min=0;
    $('seek').max=
        String(state.project.duration || 0);
    $('seek').step='.01';
    $('seek').value='0';

    updateStageSize();
    renderAll();

    markClean('読み込み完了');
    setStatus('編集可能');
}

function waitVideoMetadata(){
    return new Promise((resolve,reject)=>{
        const video=$('recordedVideo');

        if(video.readyState>=1 && video.duration){
            resolve();
            return;
        }

        const onLoaded=()=>{
            cleanup();
            resolve();
        };

        const onError=()=>{
            cleanup();
            reject(
                new Error('動画のメタデータを読み込めません。')
            );
        };

        function cleanup(){
            video.removeEventListener(
                'loadedmetadata',
                onLoaded
            );
            video.removeEventListener(
                'error',
                onError
            );
        }

        video.addEventListener(
            'loadedmetadata',
            onLoaded
        );

        video.addEventListener(
            'error',
            onError
        );
    });
}

function updateStageSize(){
    const video=$('recordedVideo');
    const stage=$('videoStage');
    const area=$('videoArea');

    if(!video.videoWidth || !video.videoHeight){
        return;
    }

    const maxW=Math.max(
        100,
        area.clientWidth-20
    );

    const maxH=Math.max(
        100,
        area.clientHeight-20
    );

    const ratio=
        video.videoWidth /
        video.videoHeight;

    let width=maxW;
    let height=width/ratio;

    if(height>maxH){
        height=maxH;
        width=height*ratio;
    }

    stage.style.width=
        Math.max(100,width)+'px';

    stage.style.height=
        Math.max(100,height)+'px';
}

function currentTime(){
    return $('recordedVideo').currentTime || 0;
}

function elementVisible(element,time){
    return (
        time>=element.start &&
        time<=element.end
    );
}

function connectionVisible(connection,time){
    return (
        time>=connection.start &&
        time<=connection.end
    );
}

/* =========================================================
 * RENDER OBJECTS
 * ======================================================= */

const POINTS=[
    ['n','上'],
    ['ne','右上'],
    ['e','右'],
    ['se','右下'],
    ['s','下'],
    ['sw','左下'],
    ['w','左'],
    ['nw','左上']
];

function renderObjects(){
    const container=$('objects');
    const time=currentTime();

    container.innerHTML='';

    if(!state.project){
        return;
    }

    state.project.elements.forEach(element=>{
        if(!elementVisible(element,time)){
            return;
        }

        const el=document.createElement('div');

        const selected=
            state.selectedType==='element' &&
            state.selected===element.id;

        const source=
            state.connectSource?.id===element.id;

        el.className=
            'edit-object ' +
            element.type +
            (selected?' selected':'') +
            (source?' connect-source':'');

        el.dataset.id=element.id;

        el.style.left=element.x+'%';
        el.style.top=element.y+'%';
        el.style.width=element.w+'%';
        el.style.height=element.h+'%';

        const style=element.style || {};

        el.style.color=style.color || '#fff';
        el.style.background=
            style.background || 'transparent';

        el.style.opacity=
            clamp(
                number(style.opacity,100),
                0,
                100
            )/100;

        el.style.border=
            `${number(style.borderWidth,0)}px solid ${
                style.borderColor || '#fff'
            }`;

        el.style.borderRadius=
            number(style.radius,0)+'px';

        el.style.fontSize=
            number(style.fontSize,24)+'px';

        el.style.fontWeight=
            number(style.fontWeight,600);

        if(element.type==='comment'){
            el.textContent=element.text;
        }

        if(element.type==='skip'){
            const label=document.createElement('div');
            label.className='skip-label';
            label.textContent='スキップ';
            el.appendChild(label);
        }

        const resize=document.createElement('div');

        resize.className='resize-handle';
        resize.dataset.resize='1';

        el.appendChild(resize);

        POINTS.forEach(([point])=>{
            const cp=document.createElement('div');

            cp.className=
                'connection-point cp-'+point;

            cp.dataset.point=point;

            el.appendChild(cp);
        });

        container.appendChild(el);
    });
}

/* =========================================================
 * CONNECTIONS
 * ======================================================= */

function pointPosition(element,point){
    const x=number(element.x);
    const y=number(element.y);
    const w=number(element.w);
    const h=number(element.h);

    const positions={
        n:[x+w/2,y],
        ne:[x+w,y],
        e:[x+w,y+h/2],
        se:[x+w,y+h],
        s:[x+w/2,y+h],
        sw:[x,y+h],
        w:[x,y+h/2],
        nw:[x,y]
    };

    const p=positions[point] || positions.e;

    return [
        p[0]/100 * $('videoStage').clientWidth,
        p[1]/100 * $('videoStage').clientHeight
    ];
}

function buildConnectionPath(p1,p2,curve){
    const x1=p1[0];
    const y1=p1[1];
    const x2=p2[0];
    const y2=p2[1];

    if(curve!=='smooth'){
        return `M ${x1} ${y1} L ${x2} ${y2}`;
    }

    const dx=Math.abs(x2-x1);
    const bend=Math.max(20,dx*.45);

    return `
        M ${x1} ${y1}
        C ${x1+bend} ${y1},
          ${x2-bend} ${y2},
          ${x2} ${y2}
    `;
}

function renderConnections(){
    const svg=$('connectors');

    svg.innerHTML=`
        <defs>
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
        </defs>
    `;

    if(!state.project){
        return;
    }

    const time=currentTime();

    state.project.connections.forEach(connection=>{
        if(!connectionVisible(connection,time)){
            return;
        }

        const from=state.project.elements.find(
            e=>e.id===connection.from
        );

        const to=state.project.elements.find(
            e=>e.id===connection.to
        );

        if(!from || !to){
            return;
        }

        const p1=pointPosition(
            from,
            connection.fromPoint
        );

        const p2=pointPosition(
            to,
            connection.toPoint
        );

        const d=buildConnectionPath(
            p1,
            p2,
            connection.curve
        );

        const path=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        path.setAttribute('d',d);
        path.setAttribute(
            'class',
            'connector' +
            (
                state.selectedType==='connection' &&
                state.selected===connection.id
                    ? ' selected'
                    : ''
            )
        );

        path.setAttribute(
            'stroke',
            connection.color
        );

        path.setAttribute(
            'stroke-width',
            connection.width
        );

        if(connection.dash){
            path.setAttribute(
                'stroke-dasharray',
                connection.dash
            );
        }

        if(connection.startArrow==='arrow'){
            path.setAttribute(
                'marker-start',
                'url(#arrow)'
            );
        }

        if(connection.startArrow==='circle'){
            path.setAttribute(
                'marker-start',
                'url(#circle)'
            );
        }

        if(connection.endArrow==='arrow'){
            path.setAttribute(
                'marker-end',
                'url(#arrow)'
            );
        }

        if(connection.endArrow==='circle'){
            path.setAttribute(
                'marker-end',
                'url(#circle)'
            );
        }

        const hit=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        hit.setAttribute('d',d);
        hit.setAttribute(
            'class',
            'connector-hit'
        );

        hit.dataset.id=connection.id;

        svg.appendChild(path);
        svg.appendChild(hit);
    });
}

/* =========================================================
 * TIMELINE
 * ======================================================= */

function renderTimeline(){
    if(!state.project){
        return;
    }

    const duration=Math.max(
        .01,
        state.project.duration ||
        $('recordedVideo').duration ||
        .01
    );

    const tracks={
        comment:$('commentTrack'),
        box:$('boxTrack'),
        skip:$('skipTrack'),
        connection:$('connectionTrack')
    };

    Object.values(tracks).forEach(
        track=>track.innerHTML=''
    );

    state.project.elements.forEach(element=>{
        const track=tracks[element.type];

        if(!track){
            return;
        }

        addTimelineItem(
            track,
            element,
            duration,
            element.type
        );
    });

    state.project.connections.forEach(connection=>{
        addTimelineItem(
            tracks.connection,
            connection,
            duration,
            'connection'
        );
    });

    renderTimelineScale(duration);
}

function addTimelineItem(
    track,
    item,
    duration,
    type
){
    const bar=document.createElement('div');

    const left=
        clamp(
            item.start/duration*100,
            0,
            100
        );

    const width=
        clamp(
            (item.end-item.start)/
            duration*100,
            .3,
            100-left
        );

    bar.className=
        'track-item '+
        type;

    bar.style.left=left+'%';
    bar.style.width=width+'%';

    const label=document.createElement('span');

    label.className='track-label';

    label.textContent=
        formatTimeShort(item.start)+
        '–'+
        formatTimeShort(item.end);

    bar.appendChild(label);

    const time=document.createElement('span');

    time.className='track-time';

    time.textContent=
        formatTimeShort(item.start)+
        ' → '+
        formatTimeShort(item.end);

    bar.appendChild(time);

    bar.title=
        `${formatTime(item.start)} ～ ${formatTime(item.end)}`;

    bar.addEventListener(
        'click',
        event=>{
            event.stopPropagation();

            if(type==='connection'){
                selectConnection(item.id);
            }else{
                selectElement(item.id);
            }

            $('recordedVideo').currentTime=
                item.start;
        }
    );

    track.appendChild(bar);
}

function renderTimelineScale(duration){
    const scale=$('timelineScale');

    scale.innerHTML='';

    const count=duration>300
        ? 10
        : duration>120
            ? 8
            : 6;

    for(let i=0;i<=count;i++){
        const span=document.createElement('span');

        const percent=
            i/count*100;

        span.style.left=percent+'%';

        span.textContent=
            formatTimeShort(
                duration*i/count
            );

        scale.appendChild(span);
    }
}

/* =========================================================
 * SELECTION
 * ======================================================= */

function selectElement(id){
    state.selectedType='element';
    state.selected=id;

    if(!state.connectMode){
        state.connectSource=null;
    }

    updateSelectionInfo();
    renderAll();
}

function selectConnection(id){
    state.selectedType='connection';
    state.selected=id;
    state.connectSource=null;

    updateSelectionInfo();
    renderAll();
}

function selectNone(){
    state.selected=null;
    state.selectedType=null;
    state.connectSource=null;

    updateSelectionInfo();

    renderAll();
}

function updateSelectionInfo(){
    const info=$('connectionStatus');

    if(state.connectMode){
        if(state.connectSource){
            info.textContent=
                '接続中：接続先の青い接点をクリックしてください';
        }else{
            info.textContent=
                '接続開始：接続元の青い接点をクリックしてください';
        }

        return;
    }

    if(state.selectedType==='element'){
        const element=state.project?.elements.find(
            e=>e.id===state.selected
        );

        if(element){
            info.textContent=
                `${element.type} / `+
                `${formatTime(element.start)} → `+
                `${formatTime(element.end)}`;
        }

        return;
    }

    if(state.selectedType==='connection'){
        const c=state.project?.connections.find(
            x=>x.id===state.selected
        );

        if(c){
            info.textContent=
                `接続線 / `+
                `${formatTime(c.start)} → `+
                `${formatTime(c.end)}`;
        }

        return;
    }

    info.textContent='通常操作';
}

/* =========================================================
 * ADD
 * ======================================================= */

function addElement(
    type,
    x=null,
    y=null
){
    if(!state.project){
        return;
    }

    const time=currentTime();

    const duration=
        state.project.duration ||
        $('recordedVideo').duration ||
        10;

    const start=clamp(
        time,
        0,
        duration
    );

    const end=Math.min(
        duration,
        start+5
    );

    const element={
        id:uid('element'),
        type,
        text:
            type==='comment'
                ? 'ここにコメント'
                : '',
        x:x==null?10:x,
        y:y==null?10:y,
        w:type==='skip'?35:30,
        h:type==='comment'?12:25,
        start,
        end,
        style:{
            color:'#ffffff',
            background:
                type==='box'
                    ? '#ff000000'
                    : '#000000cc',
            fontSize:24,
            opacity:type==='skip'?30:100,
            borderColor:
                type==='box'
                    ? '#ff3b30'
                    : type==='skip'
                        ? '#e38b28'
                        : '#ffffff',
            borderWidth:
                type==='box'
                    ? 3
                    : type==='skip'
                        ? 2
                        : 0,
            radius:4,
            fontWeight:600
        }
    };

    normalizeElement(element);

    state.project.elements.push(element);

    markDirty();
    selectElement(element.id);
}

/* =========================================================
 * POINTER / DIRECT EDIT
 * ======================================================= */

$('objects').addEventListener(
    'pointerdown',
    event=>{
        const object=
            event.target.closest('.edit-object');

        if(!object){
            return;
        }

        const element=
            state.project.elements.find(
                e=>e.id===object.dataset.id
            );

        if(!element){
            return;
        }

        if(
            event.target.classList.contains(
                'connection-point'
            )
        ){
            event.stopPropagation();

            if(state.connectMode){
                handleConnectionPoint(
                    element,
                    event.target.dataset.point
                );
            }

            return;
        }

        if(
            event.target.classList.contains(
                'resize-handle'
            )
        ){
            selectElement(element.id);

            const rect=
                $('videoStage').getBoundingClientRect();

            state.drag={
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

        if(state.connectMode){
            selectElement(element.id);

            showMessage(
                '青い接点をクリックしてください。'
            );

            return;
        }

        selectElement(element.id);

        const rect=
            $('videoStage').getBoundingClientRect();

        state.drag={
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
);

$('objects').addEventListener(
    'pointermove',
    event=>{
        if(!state.drag){
            return;
        }

        const element=
            state.project.elements.find(
                e=>e.id===state.drag.id
            );

        if(!element){
            state.drag=null;
            return;
        }

        const dx=
            event.clientX-state.drag.startX;

        const dy=
            event.clientY-state.drag.startY;

        if(state.drag.mode==='move'){
            element.x=clamp(
                state.drag.originalX+
                dx/state.drag.stageW*100,
                0,
                100-element.w
            );

            element.y=clamp(
                state.drag.originalY+
                dy/state.drag.stageH*100,
                0,
                100-element.h
            );
        }else{
            element.w=clamp(
                state.drag.originalW+
                dx/state.drag.stageW*100,
                .5,
                100-element.x
            );

            element.h=clamp(
                state.drag.originalH+
                dy/state.drag.stageH*100,
                .5,
                100-element.y
            );
        }

        markDirty();
        renderAll();
    }
);

$('objects').addEventListener(
    'pointerup',
    ()=>{
        state.drag=null;
    }
);

/* =========================================================
 * RIGHT CLICK
 * ======================================================= */

$('objects').addEventListener(
    'contextmenu',
    event=>{
        const object=
            event.target.closest('.edit-object');

        if(!object){
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        selectElement(
            object.dataset.id
        );

        state.contextTarget={
            type:'element',
            id:object.dataset.id
        };

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

$('videoStage').addEventListener(
    'contextmenu',
    event=>{
        if(
            event.target.closest('.edit-object') ||
            event.target.closest('.connector-hit')
        ){
            return;
        }

        event.preventDefault();

        const rect=
            $('videoStage').getBoundingClientRect();

        const x=
            clamp(
                (event.clientX-rect.left)/
                rect.width*100,
                0,
                99
            );

        const y=
            clamp(
                (event.clientY-rect.top)/
                rect.height*100,
                0,
                99
            );

        state.contextTarget={
            type:'stage',
            x,
            y
        };

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

/* =========================================================
 * CONNECTIONS
 * ======================================================= */

function handleConnectionPoint(element,point){
    if(!state.connectSource){
        state.connectSource={
            id:element.id,
            point
        };

        updateConnectionStatus();

        showMessage(
            '接続元を設定しました。次に接続先の青い接点をクリックしてください。',
            true
        );

        renderAll();

        return;
    }

    if(
        state.connectSource.id===element.id &&
        state.connectSource.point===point
    ){
        state.connectSource=null;
        updateConnectionStatus();
        renderAll();
        return;
    }

    const duration=
        state.project.duration ||
        $('recordedVideo').duration ||
        999999;

    const connection={
        id:uid('connection'),

        from:state.connectSource.id,
        fromPoint:state.connectSource.point,

        to:element.id,
        toPoint:point,

        color:'#ffffff',
        width:2.5,
        dash:'',
        startArrow:'none',
        endArrow:'arrow',
        curve:'straight',

        start:currentTime(),
        end:Math.min(
            duration,
            currentTime()+5
        )
    };

    normalizeConnection(connection);

    state.project.connections.push(
        connection
    );

    state.connectSource=null;
    state.connectMode=false;

    markDirty();
    selectConnection(connection.id);

    showMessage(
        '接続しました。線を右クリックすると接点と書式を変更できます。',
        true
    );
}

function updateConnectionStatus(){
    const button=$('connectMode');

    if(state.connectMode){
        button.classList.add('active');
        button.textContent='接続モード終了';
    }else{
        button.classList.remove('active');
        button.textContent='線で接続';
    }

    updateSelectionInfo();
}

$('connectors').addEventListener(
    'click',
    event=>{
        const target=
            event.target.closest('.connector-hit');

        if(!target){
            return;
        }

        event.stopPropagation();

        selectConnection(
            target.dataset.id
        );
    }
);

$('connectors').addEventListener(
    'contextmenu',
    event=>{
        const target=
            event.target.closest('.connector-hit');

        if(!target){
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        selectConnection(
            target.dataset.id
        );

        state.contextTarget={
            type:'connection',
            id:target.dataset.id
        };

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

$('connectMode').addEventListener(
    'click',
    ()=>{
        state.connectMode=!state.connectMode;
        state.connectSource=null;

        updateConnectionStatus();
        renderAll();

        if(state.connectMode){
            showMessage(
                '接続モード：まず接続元の要素にある青い接点をクリックしてください。',
                true
            );
        }
    }
);

/* =========================================================
 * CONTEXT MENU
 * ======================================================= */

function showContextMenu(x,y){
    const menu=$('contextMenu');

    menu.style.display='block';

    const w=235;
    const h=220;

    menu.style.left=
        Math.min(
            x,
            window.innerWidth-w-5
        )+'px';

    menu.style.top=
        Math.min(
            y,
            window.innerHeight-h-5
        )+'px';
}

function hideContextMenu(){
    $('contextMenu').style.display='none';
}

document.addEventListener(
    'click',
    event=>{
        if(!event.target.closest('#contextMenu')){
            hideContextMenu();
        }
    }
);

$('contextMenu').addEventListener(
    'click',
    event=>{
        const button=
            event.target.closest('button');

        if(!button){
            return;
        }

        const action=
            button.dataset.action;

        hideContextMenu();

        if(
            action==='add-comment' ||
            action==='add-box' ||
            action==='add-skip'
        ){
            const type=
                action==='add-comment'
                    ? 'comment'
                    : action==='add-box'
                        ? 'box'
                        : 'skip';

            if(
                state.contextTarget?.type==='stage'
            ){
                addElement(
                    type,
                    state.contextTarget.x,
                    state.contextTarget.y
                );
            }else{
                addElement(type);
            }

            return;
        }

        if(action==='style'){
            openStyleModal();
            return;
        }

        if(action==='duplicate'){
            duplicateSelected();
            return;
        }

        if(action==='delete'){
            deleteSelected();
        }
    }
);

/* =========================================================
 * DUPLICATE / DELETE
 * ======================================================= */

function duplicateSelected(){
    if(
        !state.selected ||
        !state.selectedType
    ){
        return;
    }

    if(state.selectedType==='element'){
        const source=
            state.project.elements.find(
                e=>e.id===state.selected
            );

        if(!source){
            return;
        }

        const copy=clone(source);

        copy.id=uid('element');

        copy.x=clamp(
            copy.x+3,
            0,
            100-copy.w
        );

        copy.y=clamp(
            copy.y+3,
            0,
            100-copy.h
        );

        state.project.elements.push(copy);

        markDirty();
        selectElement(copy.id);

        return;
    }

    const source=
        state.project.connections.find(
            c=>c.id===state.selected
        );

    if(!source){
        return;
    }

    const copy=clone(source);

    copy.id=uid('connection');

    state.project.connections.push(copy);

    markDirty();
    selectConnection(copy.id);
}

function deleteSelected(){
    if(
        !state.selected ||
        !state.selectedType
    ){
        return;
    }

    if(!confirm('選択中の項目を削除しますか？')){
        return;
    }

    if(state.selectedType==='element'){
        const id=state.selected;

        state.project.elements=
            state.project.elements.filter(
                e=>e.id!==id
            );

        state.project.connections=
            state.project.connections.filter(
                c=>c.from!==id &&
                c.to!==id
            );
    }else{
        state.project.connections=
            state.project.connections.filter(
                c=>c.id!==state.selected
            );
    }

    markDirty();
    selectNone();
}

/* =========================================================
 * OBJECT MODAL
 * ======================================================= */

function openStyleModal(){
    if(
        state.selectedType!=='element'
    ){
        return;
    }

    const element=
        state.project.elements.find(
            e=>e.id===state.selected
        );

    if(!element){
        return;
    }

    const style=element.style || {};

    $('mText').value=element.text || '';

    $('mStart').value=element.start;
    $('mEnd').value=element.end;

    $('mColor').value=
        normalizeColor(
            style.color,
            '#ffffff'
        );

    $('mBg').value=
        normalizeColor(
            style.background,
            '#000000'
        );

    $('mFontSize').value=
        number(style.fontSize,24);

    $('mOpacity').value=
        number(style.opacity,100);

    $('mBorderColor').value=
        normalizeColor(
            style.borderColor,
            '#ffffff'
        );

    $('mBorderWidth').value=
        number(style.borderWidth,0);

    $('mRadius').value=
        number(style.radius,4);

    $('mWeight').value=
        String(
            number(style.fontWeight,600)
        );

    $('mTextField').style.display=
        element.type==='comment'
            ? 'block'
            : 'none';

    $('objectModal').style.display='flex';
}

function normalizeColor(value,fallback){
    const text=String(value || '');

    const match=text.match(
        /^#([0-9a-f]{6})/i
    );

    return match
        ? '#'+match[1]
        : fallback;
}

$('cancelObjectStyle').addEventListener(
    'click',
    ()=>{
        $('objectModal').style.display='none';
    }
);

$('applyObjectStyle').addEventListener(
    'click',
    ()=>{
        const element=
            state.project.elements.find(
                e=>e.id===state.selected
            );

        if(!element){
            return;
        }

        const duration=
            state.project.duration ||
            $('recordedVideo').duration ||
            0;

        let start=clamp(
            number($('mStart').value,0),
            0,
            duration
        );

        let end=clamp(
            number($('mEnd').value,duration),
            0,
            duration
        );

        if(end<start){
            [start,end]=[end,start];
        }

        element.start=start;
        element.end=Math.max(
            start,
            end
        );

        if(element.type==='comment'){
            element.text=$('mText').value;
        }

        element.style={
            ...(element.style || {}),
            color:$('mColor').value,
            background:$('mBg').value,
            fontSize:clamp(
                number($('mFontSize').value,24),
                8,
                200
            ),
            opacity:clamp(
                number($('mOpacity').value,100),
                0,
                100
            ),
            borderColor:$('mBorderColor').value,
            borderWidth:clamp(
                number($('mBorderWidth').value,0),
                0,
                30
            ),
            radius:clamp(
                number($('mRadius').value,4),
                0,
                100
            ),
            fontWeight:number(
                $('mWeight').value,
                600
            )
        };

        $('objectModal').style.display='none';

        markDirty();
        renderAll();
    }
);

/* =========================================================
 * CONNECTION MODAL
 * ======================================================= */

function fillPointSelect(id,value){
    const select=$(id);

    select.innerHTML=POINTS.map(
        ([point,label])=>
            `<option value="${point}">
                ${label} (${point})
            </option>`
    ).join('');

    select.value=value;
}

function openConnectionModal(){
    if(state.selectedType!=='connection'){
        return;
    }

    const connection=
        state.project.connections.find(
            c=>c.id===state.selected
        );

    if(!connection){
        return;
    }

    fillPointSelect(
        'cFromPoint',
        connection.fromPoint
    );

    fillPointSelect(
        'cToPoint',
        connection.toPoint
    );

    $('cStart').value=connection.start;
    $('cEnd').value=connection.end;

    $('cColor').value=
        normalizeColor(
            connection.color,
            '#ffffff'
        );

    $('cWidth').value=connection.width;
    $('cDash').value=connection.dash;
    $('cCurve').value=connection.curve;
    $('cStartArrow').value=
        connection.startArrow;
    $('cEndArrow').value=
        connection.endArrow;

    $('connectionModal').style.display='flex';
}

$('cancelConnectionStyle').addEventListener(
    'click',
    ()=>{
        $('connectionModal').style.display='none';
    }
);

$('applyConnectionStyle').addEventListener(
    'click',
    ()=>{
        const connection=
            state.project.connections.find(
                c=>c.id===state.selected
            );

        if(!connection){
            return;
        }

        const duration=
            state.project.duration ||
            $('recordedVideo').duration ||
            0;

        let start=clamp(
            number($('cStart').value,0),
            0,
            duration
        );

        let end=clamp(
            number($('cEnd').value,duration),
            0,
            duration
        );

        if(end<start){
            [start,end]=[end,start];
        }

        connection.fromPoint=
            $('cFromPoint').value;

        connection.toPoint=
            $('cToPoint').value;

        connection.start=start;
        connection.end=end;

        connection.color=
            $('cColor').value;

        connection.width=
            clamp(
                number(
                    $('cWidth').value,
                    2.5
                ),
                1,
                30
            );

        connection.dash=
            $('cDash').value;

        connection.curve=
            $('cCurve').value;

        connection.startArrow=
            $('cStartArrow').value;

        connection.endArrow=
            $('cEndArrow').value;

        $('connectionModal').style.display=
            'none';

        markDirty();
        renderAll();
    }
);

/* =========================================================
 * SELECTION TIME EDITOR
 * ======================================================= */

function applyCurrentSelectionTime(
    start,
    end
){
    if(!state.selected){
        return;
    }

    const duration=
        state.project.duration ||
        $('recordedVideo').duration ||
        0;

    start=clamp(
        number(start,0),
        0,
        duration
    );

    end=clamp(
        number(end,duration),
        0,
        duration
    );

    if(end<start){
        [start,end]=[end,start];
    }

    if(state.selectedType==='element'){
        const item=
            state.project.elements.find(
                e=>e.id===state.selected
            );

        if(item){
            item.start=start;
            item.end=end;
        }
    }

    if(state.selectedType==='connection'){
        const item=
            state.project.connections.find(
                c=>c.id===state.selected
            );

        if(item){
            item.start=start;
            item.end=end;
        }
    }

    markDirty();
    renderAll();
}

$('startPoint').addEventListener(
    'click',
    ()=>{
        if(!state.selected){
            return;
        }

        const now=currentTime();

        const item=
            state.selectedType==='element'
                ? state.project.elements.find(
                    e=>e.id===state.selected
                )
                : state.project.connections.find(
                    c=>c.id===state.selected
                );

        if(!item){
            return;
        }

        applyCurrentSelectionTime(
            now,
            Math.max(now,item.end)
        );
    }
);

$('endPoint').addEventListener(
    'click',
    ()=>{
        if(!state.selected){
            return;
        }

        const now=currentTime();

        const item=
            state.selectedType==='element'
                ? state.project.elements.find(
                    e=>e.id===state.selected
                )
                : state.project.connections.find(
                    c=>c.id===state.selected
                );

        if(!item){
            return;
        }

        applyCurrentSelectionTime(
            Math.min(item.start,now),
            now
        );
    }
);

/* =========================================================
 * VIDEO / SEEK
 * ======================================================= */

$('seek').addEventListener(
    'input',
    event=>{
        const duration=
            state.project?.duration ||
            $('recordedVideo').duration ||
            0;

        const value=clamp(
            number(event.target.value),
            0,
            duration
        );

        $('recordedVideo').currentTime=value;
    }
);

$('playVideo').addEventListener(
    'click',
    ()=>{
        $('recordedVideo').play();
    }
);

$('pauseVideo').addEventListener(
    'click',
    ()=>{
        $('recordedVideo').pause();
    }
);

$('recordedVideo').addEventListener(
    'loadedmetadata',
    ()=>{
        const duration=
            $('recordedVideo').duration;

        if(Number.isFinite(duration)){
            state.project.duration=duration;

            $('seek').min=0;
            $('seek').max=String(duration);
            $('seek').step='.01';
        }

        updateStageSize();
        renderAll();
    }
);

$('recordedVideo').addEventListener(
    'timeupdate',
    ()=>{
        updateTimeReadout();
        renderObjects();
        renderConnections();
    }
);

$('recordedVideo').addEventListener(
    'seeked',
    ()=>{
        renderAll();
    }
);

$('recordedVideo').addEventListener(
    'play',
    ()=>{
        renderObjects();
        renderConnections();
    }
);

function updateTimeReadout(){
    const video=$('recordedVideo');

    $('timeReadout').textContent=
        formatTime(video.currentTime)+
        ' / '+
        formatTime(
            video.duration ||
            state.project?.duration ||
            0
        );

    const duration=
        video.duration ||
        state.project?.duration ||
        0;

    $('seek').max=String(duration);
    $('seek').value=
        video.currentTime || 0;
}

/* =========================================================
 * RENDER
 * ======================================================= */

function renderAll(){
    renderObjects();
    renderConnections();
    renderTimeline();
    updateTimeReadout();
    updateSelectionInfo();
}

window.addEventListener(
    'resize',
    ()=>{
        if(
            $('editor').style.display!=='none'
        ){
            updateStageSize();
            renderAll();
        }
    }
);

/* =========================================================
 * SAVE
 * ======================================================= */

function projectForSave(){
    const project=clone(
        state.project
    );

    project.version=APP_VERSION;

    project.name=
        $('editorProjectName').value.trim();

    if(!project.name){
        throw new Error(
            'プロジェクト名を入力してください。'
        );
    }

    project.savedAt=nowISO();

    return project;
}

async function saveLocal(show=true){
    const project=projectForSave();

    await idbPut(
        'projects',
        project
    );

    state.project=normalizeProject(
        project
    );

    markClean('ローカル保存済み');

    if(show){
        showMessage(
            'ローカルへ保存しました。',
            true
        );
    }

    return project;
}

async function saveServer(){
    if(state.saving){
        return;
    }

    state.saving=true;

    try{
        const project=projectForSave();

        const response=await fetch(
            API_BASE+'?api=save',
            {
                method:'POST',
                headers:{
                    'Content-Type':
                        'application/json'
                },
                body:JSON.stringify(project)
            }
        );

        const data=await response.json();

        if(!response.ok || !data.ok){
            if(data.limit){
                await saveLocal(false);

                showMessage(
                    'サーバー保存上限に達したため、ローカルへ保存しました。',
                    true
                );

                return;
            }

            throw new Error(
                data.message ||
                'サーバー保存に失敗しました。'
            );
        }

        state.project.projectId=
            data.projectId;

        state.project.savedAt=
            data.savedAt;

        markClean('サーバー保存済み');

        showMessage(
            'サーバーへ保存しました。',
            true
        );
    }finally{
        state.saving=false;
    }
}

$('saveLocal').addEventListener(
    'click',
    async ()=>{
        try{
            await saveLocal();
        }catch(error){
            showMessage(error.message);
        }
    }
);

$('saveServer').addEventListener(
    'click',
    async ()=>{
        try{
            await saveServer();
        }catch(error){
            showMessage(error.message);
        }
    }
);

/* =========================================================
 * EXPORT / IMPORT
 * ======================================================= */

$('exportJson').addEventListener(
    'click',
    ()=>{
        try{
            const project=projectForSave();

            const blob=new Blob(
                [
                    JSON.stringify(
                        {
                            project,
                            exportedAt:nowISO(),
                            appVersion:APP_VERSION
                        },
                        null,
                        2
                    )
                ],
                {
                    type:'application/json'
                }
            );

            const url=
                URL.createObjectURL(blob);

            const a=
                document.createElement('a');

            a.href=url;

            a.download=
                (
                    project.name ||
                    'project'
                ).replace(
                    /[\\/:*?"<>|]/g,
                    '_'
                )+
                '.json';

            a.click();

            setTimeout(
                ()=>{
                    URL.revokeObjectURL(url);
                },
                1000
            );

            showMessage(
                'プロジェクトを書き出しました。',
                true
            );
        }catch(error){
            showMessage(error.message);
        }
    }
);

$('importJson').addEventListener(
    'click',
    ()=>{
        $('jsonFile').click();
    }
);

$('jsonFile').addEventListener(
    'change',
    async event=>{
        const file=event.target.files?.[0];

        event.target.value='';

        if(!file){
            return;
        }

        try{
            const data=
                JSON.parse(
                    await file.text()
                );

            const project=
                data.project || data;

            if(
                !project ||
                typeof project!=='object'
            ){
                throw new Error(
                    'プロジェクトデータがありません。'
                );
            }

            const video=
                await requestVideoForImport(
                    project
                );

            if(!video){
                return;
            }

            const videoKey=
                await saveVideoBlob(
                    video,
                    video.name,
                    video.type
                );

            project.projectId=
                uid('project');

            project.videoKey=
                videoKey;

            project.videoName=
                video.name;

            await openEditor(
                project,
                video
            );

            markDirty();

            showMessage(
                'プロジェクトを読み込みました。',
                true
            );
        }catch(error){
            showMessage(
                '読み込みに失敗しました：'+
                error.message
            );
        }
    }
);

async function requestVideoForImport(project){
    const videoFile=
        document.createElement('input');

    videoFile.type='file';
    videoFile.accept='video/*';

    return new Promise(resolve=>{
        videoFile.onchange=()=>{
            resolve(
                videoFile.files?.[0] || null
            );
        };

        videoFile.click();
    });
}

/* =========================================================
 * PROJECT MANAGEMENT
 * ======================================================= */

async function loadServerList(){
    const response=await fetch(
        API_BASE+'?api=list',
        {
            cache:'no-store'
        }
    );

    if(!response.ok){
        throw new Error(
            'サーバー一覧を取得できません。'
        );
    }

    return response.json();
}

async function renderManageList(){
    const box=$('manageList');

    box.innerHTML=
        '<div class="help">読み込み中…</div>';

    const server=
        await loadServerList();

    const local=
        await idbGetAll('projects');

    $('manageInfo').innerHTML=
        `サーバー保存：${server.projects.length}/${server.limit}件<br>`+
        `ローカル保存：${local.length}件`;

    const rows=[];

    server.projects.forEach(project=>{
        rows.push({
            ...project,
            storage:'server'
        });
    });

    local.forEach(project=>{
        rows.push({
            ...project,
            storage:'local'
        });
    });

    if(!rows.length){
        box.innerHTML=
            '<div class="help">保存データはありません。</div>';
        return;
    }

    box.innerHTML=rows.map(
        project=>`
            <div class="project">
                <div class="project-info">
                    <div class="project-name">
                        ${escapeHtml(project.name)}
                    </div>

                    <div class="project-meta">
                        ${escapeHtml(project.videoName || '')}
                        /
                        ${escapeHtml(project.storage)}
                        /
                        ${escapeHtml(project.savedAt || '')}
                    </div>
                </div>

                <div class="project-actions">
                    <button
                        class="small"
                        data-open-project="${escapeHtml(project.projectId)}"
                        data-storage="${project.storage}"
                    >
                        開く
                    </button>

                    <button
                        class="small danger"
                        data-delete-project="${escapeHtml(project.projectId)}"
                        data-storage="${project.storage}"
                    >
                        削除
                    </button>
                </div>
            </div>
        `
    ).join('');
}

$('manageBtn').addEventListener(
    'click',
    async ()=>{
        try{
            await renderManageList();
            $('manageModal').style.display='flex';
        }catch(error){
            showMessage(error.message);
        }
    }
);

$('closeManage').addEventListener(
    'click',
    ()=>{
        $('manageModal').style.display='none';
    }
);

$('manageList').addEventListener(
    'click',
    async event=>{
        const open=
            event.target.closest(
                '[data-open-project]'
            );

        const del=
            event.target.closest(
                '[data-delete-project]'
            );

        if(open){
            try{
                const id=
                    open.dataset.openProject;

                const storage=
                    open.dataset.storage;

                let project;

                if(storage==='local'){
                    project=await idbGet(
                        'projects',
                        id
                    );
                }else{
                    const response=
                        await fetch(
                            API_BASE+
                            '?api=load&id='+
                            encodeURIComponent(id),
                            {
                                cache:'no-store'
                            }
                        );

                    const data=
                        await response.json();

                    if(!data.ok){
                        throw new Error(
                            data.message
                        );
                    }

                    project=data.project;
                }

                if(!project){
                    throw new Error(
                        'プロジェクトが見つかりません。'
                    );
                }

                const video=
                    project.videoKey
                        ? await idbGet(
                            'videos',
                            project.videoKey
                        )
                        : null;

                if(!video){
                    showMessage(
                        'このプロジェクトの動画本体がブラウザ内にありません。動画ファイルを再指定してください。'
                    );
                    return;
                }

                $('manageModal').style.display=
                    'none';

                await openEditor(
                    project,
                    video.blob
                );
            }catch(error){
                showMessage(
                    '開けません：'+
                    error.message
                );
            }

            return;
        }

        if(del){
            if(
                !confirm(
                    'この保存データを削除しますか？'
                )
            ){
                return;
            }

            try{
                const id=
                    del.dataset.deleteProject;

                const storage=
                    del.dataset.storage;

                if(storage==='local'){
                    await idbDelete(
                        'projects',
                        id
                    );
                }else{
                    const response=
                        await fetch(
                            API_BASE+'?api=delete',
                            {
                                method:'POST',
                                headers:{
                                    'Content-Type':
                                        'application/json'
                                },
                                body:JSON.stringify({
                                    projectId:id
                                })
                            }
                        );

                    const data=
                        await response.json();

                    if(!data.ok){
                        throw new Error(
                            data.message
                        );
                    }
                }

                await renderManageList();

                showMessage(
                    '削除しました。',
                    true
                );
            }catch(error){
                showMessage(
                    error.message
                );
            }
        }
    }
);

/* =========================================================
 * CLOSE
 * ======================================================= */

$('backHome').addEventListener(
    'click',
    async ()=>{
        if(
            state.dirty &&
            !confirm(
                '未保存の変更があります。閉じますか？'
            )
        ){
            return;
        }

        $('editor').style.display='none';
        $('home').style.display='flex';

        state.project=null;
        state.selected=null;
        state.selectedType=null;
        state.connectSource=null;
        state.connectMode=false;

        if(state.videoUrl){
            URL.revokeObjectURL(
                state.videoUrl
            );

            state.videoUrl='';
        }

        $('recordedVideo').removeAttribute(
            'src'
        );

        $('recordedVideo').load();

        setStatus('待機中');

        await renderProjectList();
    }
);

/* =========================================================
 * RECORDER
 * ======================================================= */

$('recordScreenBtn').addEventListener(
    'click',
    openRecorder
);

$('recordCancel').addEventListener(
    'click',
    ()=>{
        $('recorder').style.display='none';
        $('home').style.display='flex';
    }
);

async function openRecorder(){
    if(
        !navigator.mediaDevices?.getDisplayMedia
    ){
        showMessage(
            'このブラウザでは画面録画に対応していません。'
        );
        return;
    }

    $('home').style.display='none';
    $('recorder').style.display='flex';

    $('timer').textContent='00:00:00';

    state.recordChunks=[];
    state.recordStream=null;
    state.recorder=null;
}

$('startRecord').addEventListener(
    'click',
    startRecording
);

$('pauseRecord').addEventListener(
    'click',
    pauseRecording
);

$('stopRecord').addEventListener(
    'click',
    stopRecording
);

async function startRecording(){
    try{
        const displayStream=
            await navigator.mediaDevices
                .getDisplayMedia({
                    video:true,
                    audio:$('systemAudio').checked
                });

        let stream=displayStream;

        if(
            $('microphone').checked &&
            navigator.mediaDevices.getUserMedia
        ){
            const mic=
                await navigator.mediaDevices
                    .getUserMedia({
                        audio:true
                    });

            stream=new MediaStream([
                ...displayStream.getVideoTracks(),
                ...displayStream.getAudioTracks(),
                ...mic.getAudioTracks()
            ]);
        }

        state.recordStream=stream;

        $('preview').srcObject=stream;

        const mimeTypes=[
            'video/webm;codecs=vp9,opus',
            'video/webm;codecs=vp8,opus',
            'video/webm'
        ];

        const mimeType=
            mimeTypes.find(
                type=>
                    MediaRecorder.isTypeSupported(
                        type
                    )
            ) || '';

        state.recordChunks=[];

        state.recorder=
            new MediaRecorder(
                stream,
                mimeType
                    ? {mimeType}
                    : undefined
            );

        state.recorder.ondataavailable=
            event=>{
                if(event.data.size){
                    state.recordChunks.push(
                        event.data
                    );
                }
            };

        state.recorder.onstop=
            finishRecording;

        state.recorder.start(250);

        state.recordStartedAt=
            Date.now();

        state.recordTimer=
            setInterval(
                updateRecordTimer,
                250
            );

        $('startRecord').disabled=true;
        $('pauseRecord').disabled=false;
        $('stopRecord').disabled=false;

        showMessage(
            '録画を開始しました。',
            true
        );
    }catch(error){
        showMessage(
            '録画を開始できませんでした：'+
            error.message
        );
    }
}

function updateRecordTimer(){
    $('timer').textContent=
        formatTime(
            (Date.now()-
            state.recordStartedAt)/1000
        );
}

function pauseRecording(){
    if(!state.recorder){
        return;
    }

    if(
        state.recorder.state==='recording'
    ){
        state.recorder.pause();
        $('pauseRecord').textContent='再開';
        return;
    }

    if(
        state.recorder.state==='paused'
    ){
        state.recorder.resume();
        $('pauseRecord').textContent='一時停止';
    }
}

function stopRecording(){
    if(
        state.recorder &&
        state.recorder.state!=='inactive'
    ){
        state.recorder.stop();
    }
}

async function finishRecording(){
    clearInterval(
        state.recordTimer
    );

    state.recordTimer=null;

    state.recordStream?.getTracks()
        .forEach(
            track=>track.stop()
        );

    const blob=new Blob(
        state.recordChunks,
        {
            type:
                state.recorder?.mimeType ||
                'video/webm'
        }
    );

    if(!blob.size){
        showMessage(
            '録画データが空です。'
        );
        return;
    }

    const fileName=
        'recording-'+
        new Date()
            .toISOString()
            .replaceAll(':','-')+
        '.webm';

    const videoKey=
        await saveVideoBlob(
            blob,
            fileName,
            blob.type
        );

    const project=
        createEmptyProject(
            '画面録画',
            fileName
        );

    project.videoKey=videoKey;

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
 * HOME PROJECT LIST
 * ======================================================= */

async function renderProjectList(){
    const box=$('projectList');

    box.innerHTML=
        '<div class="help">保存データを確認中…</div>';

    try{
        const server=
            await loadServerList();

        const local=
            await idbGetAll('projects');

        const rows=[
            ...server.projects.map(
                p=>({...p,storage:'server'})
            ),
            ...local.map(
                p=>({...p,storage:'local'})
            )
        ];

        if(!rows.length){
            box.innerHTML='';
            return;
        }

        box.innerHTML=
            '<h3>保存済みプロジェクト</h3>'+
            rows.map(
                p=>`
                    <div class="project">
                        <div class="project-info">
                            <div class="project-name">
                                ${escapeHtml(p.name)}
                            </div>

                            <div class="project-meta">
                                ${escapeHtml(p.videoName || '')}
                                /
                                ${escapeHtml(p.storage)}
                            </div>
                        </div>

                        <div class="project-actions">
                            <button
                                data-home-open="${escapeHtml(p.projectId)}"
                                data-storage="${p.storage}"
                            >
                                開く
                            </button>
                        </div>
                    </div>
                `
            ).join('');
    }catch{
        box.innerHTML=
            '<div class="help">保存データを取得できません。</div>';
    }
}

$('projectList').addEventListener(
    'click',
    async event=>{
        const button=
            event.target.closest(
                '[data-home-open]'
            );

        if(!button){
            return;
        }

        const id=button.dataset.homeOpen;
        const storage=button.dataset.storage;

        try{
            let project;

            if(storage==='local'){
                project=await idbGet(
                    'projects',
                    id
                );
            }else{
                const response=
                    await fetch(
                        API_BASE+
                        '?api=load&id='+
                        encodeURIComponent(id),
                        {
                            cache:'no-store'
                        }
                    );

                const data=
                    await response.json();

                project=data.project;
            }

            if(!project){
                throw new Error(
                    'プロジェクトが見つかりません。'
                );
            }

            const video=
                await idbGet(
                    'videos',
                    project.videoKey
                );

            if(!video){
                throw new Error(
                    '動画本体がブラウザにありません。'
                );
            }

            await openEditor(
                project,
                video.blob
            );
        }catch(error){
            showMessage(error.message);
        }
    }
);

/* =========================================================
 * KEYBOARD
 * ======================================================= */

document.addEventListener(
    'keydown',
    event=>{
        if(event.key==='Escape'){
            hideContextMenu();

            $('objectModal').style.display=
                'none';

            $('connectionModal').style.display=
                'none';

            state.connectSource=null;

            if(state.connectMode){
                state.connectMode=false;
                updateConnectionStatus();
                renderAll();
            }
        }

        if(
            event.key==='Delete' &&
            state.selected &&
            !event.target.matches(
                'input,textarea,select'
            )
        ){
            deleteSelected();
        }
    }
);

/* =========================================================
 * INITIALIZE
 * ======================================================= */

async function init(){
    try{
        await openDB();

        setStatus('準備完了');

        await renderProjectList();
    }catch(error){
        setStatus('初期化エラー');

        showMessage(
            '初期化に失敗しました：'+
            error.message
        );
    }
}

init();
</script>

</body>
</html>

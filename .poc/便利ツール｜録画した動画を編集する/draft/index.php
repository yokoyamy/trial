<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 完結版
 *
 * 保存:
 *   サーバー: data/projects/*.json
 *   ブラウザ: IndexedDB
 *
 * 動画:
 *   ブラウザIndexedDBにBlobとして保存
 *
 * 書き出し:
 *   編集プロジェクトJSON
 *
 * ※ブラウザ単体で既存MP4へHTML/SVG要素を焼き込んだ
 *   完成MP4を安定生成する機能は含めない。
 */

const APP_VERSION = 40;
const SERVER_PROJECT_LIMIT = 20;

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
    return preg_match('/^[A-Za-z0-9_-]{8,120}$/', $id) === 1;
}

function projectPath(string $id): string
{
    return PROJECT_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

function readProjects(): array
{
    if (!is_dir(PROJECT_DIR)) {
        return [];
    }

    $result = [];

    foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $json = @file_get_contents($file);
        $data = json_decode($json ?: '', true);

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
        'version' => (int)($project['version'] ?? 1)
    ];
}

/* ---------------------------------------------------------
 * API
 * --------------------------------------------------------- */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    if ($api === 'status') {
        jsonResponse([
            'ok' => true,
            'version' => APP_VERSION,
            'serverWritable' => ensureProjectDir(),
            'limit' => SERVER_PROJECT_LIMIT,
            'count' => count(readProjects())
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map(
                'projectSummary',
                readProjects()
            ),
            'limit' => SERVER_PROJECT_LIMIT
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
                'message' => 'サーバー保存領域へ書き込めません。'
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
            $name = '名称未設定';
        }

        if (mb_strlen($name) > 120) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクト名は120文字以内です。'
            ], 422);
        }

        $existing = is_file(projectPath($id));
        $projects = readProjects();

        if (!$existing && count($projects) >= SERVER_PROJECT_LIMIT) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' =>
                    'サーバー保存上限に達しています。' .
                    'ローカル保存を使用してください。'
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
                'message' => '保存できませんでした。'
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
    --panel:#1b1f24;
    --panel2:#252a30;
    --panel3:#30363e;
    --border:#444b54;
    --text:#f4f6f8;
    --muted:#9da6b0;
    --blue:#1976d2;
    --blue2:#42a5f5;
    --red:#ef5350;
    --orange:#e38b28;
    --green:#2e7d52;
    --yellow:#ffd447;
    --purple:#805ad5;
}

*{box-sizing:border-box}

html,body{
    width:100%;
    height:100%;
    margin:0;
}

body{
    overflow:hidden;
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

button,
input,
select,
textarea{
    font:inherit;
}

button{
    color:#fff;
    background:#30363e;
    border:1px solid #555d67;
    border-radius:6px;
    padding:7px 11px;
    cursor:pointer;
}

button:hover:not(:disabled){
    background:#414850;
}

button:disabled{
    opacity:.4;
    cursor:not-allowed;
}

button.primary{
    background:var(--blue);
    border-color:#2489e9;
}

button.success{
    background:var(--green);
}

button.danger{
    background:#9d3737;
}

input,
select,
textarea{
    width:100%;
    color:#fff;
    background:#252a30;
    border:1px solid #555d67;
    border-radius:5px;
    padding:7px;
}

input[type=color]{
    height:38px;
    padding:3px;
}

textarea{
    min-height:90px;
    resize:vertical;
}

.hidden{
    display:none!important;
}

/* =========================================================
   Header
   ========================================================= */

header{
    height:48px;
    display:flex;
    align-items:center;
    gap:12px;
    padding:0 14px;
    background:#191c20;
    border-bottom:1px solid #363b42;
}

header h1{
    margin:0;
    font-size:15px;
}

#status{
    margin-left:auto;
    color:#aeb7c0;
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
    background:#963c3c;
    box-shadow:0 12px 40px #000b;
    white-space:pre-wrap;
}

#message.ok{
    background:#287348;
}

/* =========================================================
   Home
   ========================================================= */

#home{
    height:calc(100vh - 48px);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.home-card{
    width:min(760px,95vw);
    max-height:90vh;
    overflow:auto;
    padding:25px;
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:10px;
    box-shadow:0 15px 60px #0008;
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
    margin:16px 0;
}

.project-list{
    margin-top:15px;
}

.project-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:10px;
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
    color:#929ba5;
    font-size:11px;
    margin-top:3px;
}

.project-actions{
    display:flex;
    gap:5px;
}

/* =========================================================
   Editor
   ========================================================= */

#editor{
    position:fixed;
    inset:0;
    z-index:100;
    display:none;
    flex-direction:column;
    background:#111;
}

#editor.ready{
    display:flex;
}

.editor-top{
    height:48px;
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
    margin-left:auto;
    color:#aeb6bf;
    font-size:12px;
}

.editor-main{
    min-height:0;
    flex:1;
    display:flex;
    flex-direction:column;
}

/* =========================================================
   Video area
   ========================================================= */

.video-area{
    position:relative;
    flex:0 0 auto;
    height:min(43vh,470px);
    min-height:220px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:#000;
}

#videoStage{
    position:relative;
    width:min(72vw,900px);
    height:calc(100% - 16px);
    max-height:440px;
    background:#000;
    overflow:hidden;
}

#recordedVideo{
    display:block;
    width:100%;
    height:100%;
    object-fit:contain;
    background:#000;
}

#objects,
#connectorSvg{
    position:absolute;
    inset:0;
}

#objects{
    z-index:5;
}

#connectorSvg{
    z-index:4;
    width:100%;
    height:100%;
    pointer-events:none;
    overflow:visible;
}

.connector-hit{
    pointer-events:stroke;
    cursor:pointer;
}

.edit-object{
    position:absolute;
    z-index:10;
    user-select:none;
    touch-action:none;
    cursor:move;
    line-height:normal;
    overflow:visible;
}

.edit-object.comment{
    display:flex;
    align-items:center;
    padding:5px 8px;
    white-space:pre-wrap;
    overflow:hidden;
    word-break:break-word;
}

.edit-object.box{
    background:transparent;
}

.edit-object.skip{
    background:#e38b2820;
    border-style:dashed!important;
}

.edit-object.selected{
    outline:2px solid #fff;
    outline-offset:2px;
}

.edit-object.multi-selected{
    outline:2px dashed var(--yellow);
    outline-offset:3px;
}

.skip-label{
    position:absolute;
    top:4px;
    left:4px;
    padding:2px 6px;
    background:#c87518;
    color:#fff;
    border-radius:4px;
    font-size:10px;
    pointer-events:none;
}

.resize-handle{
    position:absolute;
    right:-8px;
    bottom:-8px;
    width:15px;
    height:15px;
    border-radius:50%;
    border:1px solid #222;
    background:#fff;
    cursor:nwse-resize;
    display:none;
}

.edit-object.selected .resize-handle{
    display:block;
}

/* =========================================================
   Single shared seek control
   ========================================================= */

.video-controls{
    position:absolute;
    left:50%;
    bottom:8px;
    transform:translateX(-50%);
    width:min(900px,90%);
    display:flex;
    align-items:center;
    gap:8px;
    padding:7px 9px;
    border-radius:7px;
    background:#111c;
    backdrop-filter:blur(4px);
    z-index:50;
}

#playButton{
    width:42px;
    min-width:42px;
    padding:5px;
    font-size:16px;
}

#timelineSeek{
    flex:1;
    min-width:0;
}

#videoTime{
    min-width:125px;
    text-align:right;
    font-size:11px;
    font-variant-numeric:tabular-nums;
}

/* =========================================================
   Timeline
   ========================================================= */

.timeline{
    min-height:260px;
    flex:1 1 auto;
    overflow:auto;
    padding:6px 10px 14px;
    background:#191c20;
    border-top:1px solid #363b42;
}

.timeline-toolbar{
    height:38px;
    display:flex;
    align-items:center;
    gap:7px;
    position:sticky;
    top:0;
    z-index:200;
    background:#191c20;
}

.timeline-toolbar .time-label{
    min-width:140px;
    font-size:12px;
    color:#d6dce2;
    font-variant-numeric:tabular-nums;
}

.timeline-toolbar .help{
    color:#8f99a3;
    font-size:11px;
}

#timelineZoom{
    width:100px;
}

.timeline-scroll{
    position:relative;
    overflow-x:auto;
    overflow-y:visible;
    padding-bottom:35px;
}

.timeline-content{
    position:relative;
    min-width:800px;
}

.timeline-scale{
    position:relative;
    height:31px;
    border-bottom:1px solid #484e55;
    color:#929ba5;
    font-size:10px;
}

.timeline-scale-mark{
    position:absolute;
    bottom:3px;
    transform:translateX(-50%);
    white-space:nowrap;
}

.timeline-scale-mark::before{
    content:"";
    position:absolute;
    bottom:-4px;
    left:50%;
    width:1px;
    height:5px;
    background:#59616a;
}

.timeline-row{
    display:grid;
    grid-template-columns:76px minmax(0,1fr);
    gap:8px;
    margin-top:18px;
    align-items:start;
}

.timeline-label{
    width:76px;
    color:#b2bac3;
    font-size:11px;
    padding-top:12px;
    white-space:nowrap;
}

.track{
    position:relative;
    height:42px;
    min-width:0;
    background:#292e34;
    border:1px solid #3d444c;
    border-radius:4px;
    overflow:visible;
}

.track.connection-track{
    background:#25292e;
}

.track-item{
    position:absolute;
    min-width:12px;
    height:28px;
    top:6px;
    border-radius:3px;
    cursor:grab;
    z-index:10;
    touch-action:none;
    overflow:visible;
}

.track-item:active{
    cursor:grabbing;
}

.track-item.comment{
    background:#1976d2;
}

.track-item.box{
    background:#ef5350;
}

.track-item.skip{
    background:#e38b28;
}

.track-item.connection{
    background:#805ad5;
}

.track-item.selected{
    outline:2px solid #fff;
    outline-offset:1px;
}

.track-item.multi{
    outline:2px dashed var(--yellow);
    outline-offset:2px;
}

.track-handle{
    position:absolute;
    top:-5px;
    bottom:-5px;
    width:14px;
    z-index:40;
    cursor:ew-resize;
}

.track-handle.left{
    left:-7px;
}

.track-handle.right{
    right:-7px;
}

.track-item:hover .track-handle{
    background:#ffffff55;
}

.track-time,
.track-end-time{
    position:absolute;
    top:-18px;
    font-size:9px;
    color:#e1e5e9;
    white-space:nowrap;
    pointer-events:none;
    font-variant-numeric:tabular-nums;
}

.track-time{
    left:0;
}

.track-end-time{
    right:0;
}

.track-name{
    position:absolute;
    left:6px;
    right:6px;
    top:6px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    pointer-events:none;
    font-size:10px;
}

.playhead{
    position:absolute;
    top:0;
    bottom:-10px;
    width:2px;
    background:#42a5f5;
    box-shadow:0 0 4px #42a5f5;
    z-index:100;
    pointer-events:none;
}

.playhead::before{
    content:"";
    position:absolute;
    top:-5px;
    left:-5px;
    width:12px;
    height:12px;
    border-radius:50%;
    background:#42a5f5;
}

/* =========================================================
   Context menu
   ========================================================= */

#contextMenu{
    position:fixed;
    z-index:9000;
    display:none;
    min-width:210px;
    padding:5px;
    background:#252a30;
    border:1px solid #555d67;
    border-radius:7px;
    box-shadow:0 12px 35px #000a;
}

.context-title{
    padding:7px 9px;
    color:#aab3bd;
    font-size:11px;
    border-bottom:1px solid #414850;
    margin-bottom:4px;
}

.context-item{
    width:100%;
    display:block;
    text-align:left;
    border:0;
    background:transparent;
    padding:8px 9px;
    border-radius:4px;
}

.context-item:hover{
    background:#394149;
}

/* =========================================================
   Modal
   ========================================================= */

.modal{
    position:fixed;
    inset:0;
    z-index:8000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:#0009;
}

.modal-card{
    width:min(580px,95vw);
    max-height:90vh;
    overflow:auto;
    padding:18px;
    background:#1b1f24;
    border:1px solid #4b525b;
    border-radius:9px;
    box-shadow:0 20px 70px #000c;
}

.modal-card h3{
    margin:0 0 15px;
}

.form-grid{
    display:grid;
    grid-template-columns:120px 1fr;
    gap:9px;
    align-items:center;
}

.form-grid label{
    color:#b7bec6;
    font-size:12px;
}

.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:15px;
}

.palette{
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:5px;
}

.palette button{
    height:27px;
    padding:0;
    border:2px solid transparent;
}

.palette button.active{
    border-color:#fff;
    box-shadow:0 0 0 1px #000;
}

/* =========================================================
   Loading overlay
   ========================================================= */

#loading{
    position:fixed;
    inset:0;
    z-index:12000;
    display:none;
    align-items:center;
    justify-content:center;
    background:#000b;
}

.loading-card{
    padding:25px 35px;
    border-radius:9px;
    background:#1b1f24;
    border:1px solid #444;
    text-align:center;
}

.spinner{
    width:30px;
    height:30px;
    margin:0 auto 10px;
    border:3px solid #444;
    border-top-color:#42a5f5;
    border-radius:50%;
    animation:spin .8s linear infinite;
}

@keyframes spin{
    to{transform:rotate(360deg)}
}

/* =========================================================
   Empty editor state
   ========================================================= */

#videoEmpty{
    position:absolute;
    inset:0;
    z-index:60;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#9da6b0;
    background:#050505;
    text-align:center;
    padding:30px;
}

#videoEmpty strong{
    display:block;
    color:#fff;
    margin-bottom:7px;
}
</style>
</head>

<body>

<header>
    <h1>動画上直接編集ツール</h1>
    <div id="status">準備中</div>
</header>

<div id="message"></div>

<section id="home">
    <div class="home-card">
        <h2>動画編集プロジェクト</h2>

        <p>
            まずプロジェクトを作成し、動画を読み込んでください。
            動画の読み込みと長さの取得が完了するまで編集画面は開始されません。
        </p>

        <div class="home-actions">
            <button id="newProject" class="primary">
                新しいプロジェクト
            </button>

            <button id="importProject">
                プロジェクトを読み込む
            </button>

            <input
                id="projectFile"
                class="hidden"
                type="file"
                accept=".json,application/json"
            >
        </div>

        <div class="project-list" id="projectList"></div>
    </div>
</section>

<section id="editor">

    <div class="editor-top">
        <button id="backHome">← 戻る</button>

        <input
            id="editorProjectName"
            type="text"
            placeholder="プロジェクト名"
        >

        <button id="chooseVideo" class="primary">
            動画を読み込む
        </button>

        <input
            id="videoFile"
            class="hidden"
            type="file"
            accept="video/*"
        >

        <button id="saveProject" class="success" disabled>
            保存
        </button>

        <button id="exportProject">
            書き出し
        </button>

        <div id="editorStatus">
            動画未読込
        </div>
    </div>

    <main class="editor-main">

        <div class="video-area">

            <div id="videoStage">

                <video
                    id="recordedVideo"
                    preload="metadata"
                    playsinline
                ></video>

                <div id="videoEmpty">
                    <div>
                        <strong>動画を読み込んでください</strong>
                        動画の読み込み完了後に編集できます。
                    </div>
                </div>

                <svg id="connectorSvg"></svg>

                <div id="objects"></div>

            </div>

            <!-- 唯一の再生位置スライダー -->
            <div class="video-controls">
                <button id="playButton" disabled>▶</button>

                <input
                    id="timelineSeek"
                    type="range"
                    min="0"
                    max="0"
                    step="0.01"
                    value="0"
                    disabled
                >

                <div id="videoTime">00:00.00 / 00:00.00</div>
            </div>
        </div>

        <section class="timeline">

            <div class="timeline-toolbar">

                <button id="timelinePlay" disabled>▶</button>

                <div class="time-label" id="timelineTime">
                    00:00.00 / 00:00.00
                </div>

                <label class="help">
                    時間目盛り
                </label>

                <select id="timelineZoom">
                    <option value="0.5">0.5秒</option>
                    <option value="1" selected>1秒</option>
                    <option value="5">5秒</option>
                    <option value="10">10秒</option>
                    <option value="30">30秒</option>
                    <option value="60">60秒</option>
                </select>

                <div class="help">
                    Shift+クリックで複数選択 → 右クリックで接続
                </div>

            </div>

            <div class="timeline-scroll" id="timelineScroll">
                <div class="timeline-content" id="timelineContent">
                    <div class="timeline-scale" id="timelineScale"></div>
                    <div id="tracks"></div>
                    <div class="playhead" id="playhead"></div>
                </div>
            </div>

        </section>

    </main>
</section>

<!-- =========================================================
     Element modal
     ========================================================= -->

<div class="modal" id="elementModal">
    <div class="modal-card">

        <h3 id="elementModalTitle">要素設定</h3>

        <div class="form-grid">

            <label>種類</label>
            <select id="elementType">
                <option value="comment">テキスト</option>
                <option value="box">強調枠</option>
                <option value="skip">スキップ</option>
            </select>

            <label>テキスト</label>
            <textarea id="elementText"></textarea>

            <label>開始</label>
            <input id="elementStart" type="number" min="0" step="0.01">

            <label>終了</label>
            <input id="elementEnd" type="number" min="0" step="0.01">

            <label>X (%)</label>
            <input id="elementX" type="number" min="0" max="100" step="0.1">

            <label>Y (%)</label>
            <input id="elementY" type="number" min="0" max="100" step="0.1">

            <label>幅 (%)</label>
            <input id="elementW" type="number" min="1" max="100" step="0.1">

            <label>高さ (%)</label>
            <input id="elementH" type="number" min="1" max="100" step="0.1">

            <label>文字サイズ</label>
            <input id="elementFontSize" type="number" min="8" max="96">

            <label>線幅</label>
            <input id="elementBorderWidth" type="number" min="0" max="20" step="1">

            <label>線種</label>
            <select id="elementBorderStyle">
                <option value="solid">実線</option>
                <option value="dashed">破線</option>
                <option value="dotted">点線</option>
            </select>

            <label>色</label>
            <div class="palette" id="elementPalette"></div>

        </div>

        <div class="modal-actions">
            <button id="deleteElement" class="danger">
                削除
            </button>
            <button id="cancelElement">
                キャンセル
            </button>
            <button id="applyElement" class="primary">
                適用
            </button>
        </div>

    </div>
</div>

<!-- =========================================================
     Connection modal
     ========================================================= -->

<div class="modal" id="connectionModal">
    <div class="modal-card">

        <h3>接続線の設定</h3>

        <div class="form-grid">

            <label>始点</label>
            <select id="connectionFromPoint">
                <option value="top">上</option>
                <option value="right">右</option>
                <option value="bottom">下</option>
                <option value="left">左</option>
                <option value="center">中央</option>
            </select>

            <label>終点</label>
            <select id="connectionToPoint">
                <option value="top">上</option>
                <option value="right">右</option>
                <option value="bottom">下</option>
                <option value="left">左</option>
                <option value="center">中央</option>
            </select>

            <label>開始</label>
            <input id="connectionStart" type="number" min="0" step="0.01">

            <label>終了</label>
            <input id="connectionEnd" type="number" min="0" step="0.01">

            <label>線色</label>
            <input id="connectionColor" type="color">

            <label>太さ</label>
            <input id="connectionWidth" type="number" min=".5" max="10" step=".5">

            <label>線種</label>
            <select id="connectionDash">
                <option value="">実線</option>
                <option value="6 4">破線</option>
                <option value="2 4">点線</option>
            </select>

            <label>矢印</label>
            <select id="connectionArrow">
                <option value="0">なし</option>
                <option value="1">あり</option>
            </select>

            <label>曲線</label>
            <input id="connectionCurve" type="number" min="0" max="100">

        </div>

        <div class="modal-actions">
            <button id="deleteConnection" class="danger">
                削除
            </button>
            <button id="cancelConnection">
                キャンセル
            </button>
            <button id="applyConnection" class="primary">
                適用
            </button>
        </div>

    </div>
</div>

<!-- =========================================================
     Context menu
     ========================================================= -->

<div id="contextMenu">
    <div class="context-title" id="contextTitle">
        要素
    </div>

    <button class="context-item" id="ctxAddText">
        ＋ テキスト
    </button>

    <button class="context-item" id="ctxAddBox">
        ＋ 強調枠
    </button>

    <button class="context-item" id="ctxAddSkip">
        ＋ スキップ
    </button>

    <button class="context-item" id="ctxEdit">
        編集・書式変更
    </button>

    <button class="context-item" id="ctxConnect">
        選択した2要素を接続
    </button>

    <button class="context-item" id="ctxEditConnection">
        接続線を編集
    </button>

    <button class="context-item" id="ctxDelete">
        削除
    </button>
</div>

<!-- =========================================================
     Loading
     ========================================================= -->

<div id="loading">
    <div class="loading-card">
        <div class="spinner"></div>
        <div id="loadingText">処理中...</div>
    </div>
</div>

<script>
'use strict';

/* =========================================================
   Constants
   ========================================================= */

const APP_VERSION = 40;
const DB_NAME = 'video-direct-editor-v40';
const DB_VERSION = 1;

const COLORS = [
    '#ffffff',
    '#000000',
    '#ef5350',
    '#ec407a',
    '#ab47bc',
    '#5c6bc0',
    '#42a5f5',
    '#26a69a',
    '#66bb6a',
    '#ffca28',
    '#ffa726',
    '#8d6e63'
];

/* =========================================================
   State
   ========================================================= */

const state = {
    project:null,
    videoReady:false,
    videoObjectUrl:null,

    selectedId:null,
    selectedType:null,

    multiSelected:[],

    modalTarget:null,
    connectionModalTarget:null,

    context:{
        x:0,
        y:0,
        targetId:null,
        targetType:null
    },

    drag:null,

    dirty:false,
    saveTimer:null,

    skipLock:false,

    duration:0
};

/* =========================================================
   Helpers
   ========================================================= */

const $ = id => document.getElementById(id);

function clamp(value,min,max){
    return Math.min(max,Math.max(min,value));
}

function num(value,fallback=0){
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
}

function uid(prefix='id'){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,10);
}

function fmt(seconds){
    seconds = Math.max(0,num(seconds));

    const m = Math.floor(seconds / 60);
    const s = seconds - m * 60;

    return String(m).padStart(2,'0') +
        ':' +
        s.toFixed(2).padStart(5,'0');
}

function deepCopy(value){
    return JSON.parse(JSON.stringify(value));
}

function message(text,ok=false){
    const el = $('message');

    el.textContent = text;
    el.classList.toggle('ok',ok);
    el.style.display = 'block';

    clearTimeout(message.timer);

    message.timer = setTimeout(()=>{
        el.style.display = 'none';
    },3200);
}

function setLoading(show,text='処理中...'){
    $('loading').style.display =
        show ? 'flex' : 'none';

    $('loadingText').textContent = text;
}

function markDirty(){
    if(!state.project) return;

    state.dirty = true;
    $('editorStatus').textContent =
        state.videoReady
            ? '未保存の変更'
            : '動画未読込';

    clearTimeout(state.saveTimer);

    state.saveTimer = setTimeout(()=>{
        saveLocalProject().catch(()=>{});
    },700);
}

/* =========================================================
   Project model
   ========================================================= */

function emptyProject(name='新しい動画編集'){
    return {
        version:APP_VERSION,
        projectId:uid('project'),
        name,
        videoName:'',
        videoDuration:0,
        elements:[],
        connections:[],
        savedAt:'',
        createdAt:new Date().toISOString()
    };
}

function normalizeProject(raw){
    const p =
        raw && typeof raw === 'object'
            ? deepCopy(raw)
            : emptyProject();

    p.version = APP_VERSION;

    if(!p.projectId){
        p.projectId = uid('project');
    }

    if(!p.name){
        p.name = '名称未設定';
    }

    if(!Array.isArray(p.elements)){
        p.elements = [];
    }

    if(!Array.isArray(p.connections)){
        p.connections = [];
    }

    p.videoDuration =
        Math.max(
            0,
            num(p.videoDuration)
        );

    p.elements =
        p.elements.map(normalizeElement);

    p.connections =
        p.connections
            .map(normalizeConnection)
            .filter(Boolean);

    return p;
}

function normalizeElement(e){
    const d =
        Math.max(
            .01,
            state.duration ||
            num(state.project?.videoDuration,60) ||
            60
        );

    let start =
        clamp(
            num(e?.start),
            0,
            d
        );

    let end =
        clamp(
            num(e?.end,d),
            0,
            d
        );

    if(end <= start){
        end = Math.min(d,start + Math.min(1,d));

        if(end <= start){
            start = Math.max(0,d - .01);
            end = d;
        }
    }

    const type =
        ['comment','box','skip'].includes(e?.type)
            ? e.type
            : 'comment';

    return {
        id:e?.id || uid('element'),
        type,
        text:String(e?.text || ''),
        start,
        end,

        x:clamp(num(e?.x,20),0,99),
        y:clamp(num(e?.y,20),0,99),

        w:clamp(
            num(e?.w,type === 'box' ? 40 : 30),
            1,
            100
        ),

        h:clamp(
            num(e?.h,type === 'box' ? 30 : 15),
            1,
            100
        ),

        color:e?.color || (
            type === 'box'
                ? '#ef5350'
                : type === 'skip'
                    ? '#ffa726'
                    : '#ffffff'
        ),

        fontSize:clamp(num(e?.fontSize,24),8,96),

        borderWidth:clamp(
            num(e?.borderWidth,type === 'comment' ? 1 : 2),
            0,
            20
        ),

        borderStyle:
            ['solid','dashed','dotted'].includes(e?.borderStyle)
                ? e.borderStyle
                : 'solid'
    };
}

function normalizeConnection(c){
    if(!c || !c.from || !c.to){
        return null;
    }

    return {
        id:c.id || uid('connection'),
        from:String(c.from),
        to:String(c.to),

        fromPoint:
            ['top','right','bottom','left','center']
                .includes(c.fromPoint)
                ? c.fromPoint
                : 'right',

        toPoint:
            ['top','right','bottom','left','center']
                .includes(c.toPoint)
                ? c.toPoint
                : 'left',

        start:Math.max(0,num(c.start)),
        end:Math.max(
            num(c.start),
            num(c.end,state.duration)
        ),

        color:c.color || '#ffffff',
        width:clamp(num(c.width,1.5),.5,10),
        dash:String(c.dash || ''),
        arrow:Boolean(c.arrow),
        curve:clamp(num(c.curve,30),0,100)
    };
}

function getElement(id){
    if(!state.project) return null;

    return state.project.elements.find(
        e=>e.id === id
    ) || null;
}

function getConnection(id){
    if(!state.project) return null;

    return state.project.connections.find(
        c=>c.id === id
    ) || null;
}

function duration(){
    return Math.max(
        .01,
        state.duration ||
        num(state.project?.videoDuration) ||
        0.01
    );
}

/* =========================================================
   IndexedDB
   ========================================================= */

let dbPromise = null;

function openDB(){
    if(dbPromise) return dbPromise;

    dbPromise = new Promise((resolve,reject)=>{
        const request =
            indexedDB.open(DB_NAME,DB_VERSION);

        request.onupgradeneeded = ()=>{
            const db = request.result;

            if(!db.objectStoreNames.contains('projects')){
                db.createObjectStore(
                    'projects',
                    {keyPath:'projectId'}
                );
            }

            if(!db.objectStoreNames.contains('videos')){
                db.createObjectStore(
                    'videos',
                    {keyPath:'projectId'}
                );
            }
        };

        request.onsuccess = ()=>{
            resolve(request.result);
        };

        request.onerror = ()=>{
            reject(request.error);
        };
    });

    return dbPromise;
}

async function idbPut(storeName,value){
    const db = await openDB();

    return new Promise((resolve,reject)=>{
        const tx = db.transaction(
            storeName,
            'readwrite'
        );

        const store =
            tx.objectStore(storeName);

        const request = store.put(value);

        request.onsuccess = ()=>{
            resolve();
        };

        request.onerror = ()=>{
            reject(request.error);
        };
    });
}

async function idbGet(storeName,key){
    const db = await openDB();

    return new Promise((resolve,reject)=>{
        const tx = db.transaction(
            storeName,
            'readonly'
        );

        const request =
            tx.objectStore(storeName).get(key);

        request.onsuccess = ()=>{
            resolve(request.result || null);
        };

        request.onerror = ()=>{
            reject(request.error);
        };
    });
}

async function idbDelete(storeName,key){
    const db = await openDB();

    return new Promise((resolve,reject)=>{
        const tx = db.transaction(
            storeName,
            'readwrite'
        );

        const request =
            tx.objectStore(storeName).delete(key);

        request.onsuccess = ()=>{
            resolve();
        };

        request.onerror = ()=>{
            reject(request.error);
        };
    });
}

async function idbListProjects(){
    const db = await openDB();

    return new Promise((resolve,reject)=>{
        const tx = db.transaction(
            'projects',
            'readonly'
        );

        const request =
            tx.objectStore('projects').getAll();

        request.onsuccess = ()=>{
            resolve(
                Array.isArray(request.result)
                    ? request.result
                    : []
            );
        };

        request.onerror = ()=>{
            reject(request.error);
        };
    });
}

/* =========================================================
   Project local save
   ========================================================= */

async function saveLocalProject(){
    if(!state.project) return;

    const copy = deepCopy(state.project);

    copy.version = APP_VERSION;
    copy.savedAt = new Date().toISOString();

    await idbPut(
        'projects',
        copy
    );
}

/* =========================================================
   Server project list
   ========================================================= */

async function fetchServerProjects(){
    try{
        const response =
            await fetch('?api=list',{
                cache:'no-store'
            });

        if(!response.ok){
            return [];
        }

        const data =
            await response.json();

        return Array.isArray(data.projects)
            ? data.projects.map(p=>({
                ...p,
                storage:'server'
            }))
            : [];
    }catch{
        return [];
    }
}

async function renderProjectList(){
    const list = $('projectList');

    list.innerHTML =
        '<div style="color:#929aa4;font-size:12px">' +
        '保存済みプロジェクトを読み込み中...' +
        '</div>';

    const [server,local] =
        await Promise.all([
            fetchServerProjects(),
            idbListProjects().catch(()=>[])
        ]);

    const map = new Map();

    local.forEach(p=>{
        map.set(
            p.projectId,
            {
                ...p,
                storage:'local'
            }
        );
    });

    server.forEach(p=>{
        map.set(
            p.projectId,
            p
        );
    });

    const projects =
        Array.from(map.values())
            .sort(
                (a,b)=>
                    String(b.savedAt || '')
                        .localeCompare(
                            String(a.savedAt || '')
                        )
            );

    list.innerHTML = '';

    if(!projects.length){
        list.innerHTML =
            '<div style="color:#929aa4;font-size:12px">' +
            '保存済みプロジェクトはありません。' +
            '</div>';
        return;
    }

    projects.forEach(p=>{
        const row =
            document.createElement('div');

        row.className =
            'project-row';

        const info =
            document.createElement('div');

        info.className =
            'project-info';

        const name =
            document.createElement('div');

        name.className =
            'project-name';

        name.textContent =
            p.name || '名称未設定';

        const meta =
            document.createElement('div');

        meta.className =
            'project-meta';

        meta.textContent =
            (
                p.storage === 'server'
                    ? 'サーバー'
                    : 'このブラウザ'
            ) +
            ' / ' +
            (p.videoName || '動画未設定') +
            ' / ' +
            (p.savedAt || '');

        info.append(
            name,
            meta
        );

        const actions =
            document.createElement('div');

        actions.className =
            'project-actions';

        const open =
            document.createElement('button');

        open.textContent =
            '開く';

        open.onclick = async ()=>{
            await loadProjectById(
                p.projectId,
                p.storage
            );
        };

        const del =
            document.createElement('button');

        del.className =
            'danger';

        del.textContent =
            '削除';

        del.onclick = async ()=>{
            if(!confirm(
                'このプロジェクトを削除しますか？'
            )){
                return;
            }

            try{
                if(p.storage === 'server'){
                    await fetch(
                        '?api=delete',
                        {
                            method:'POST',
                            headers:{
                                'Content-Type':
                                    'application/json'
                            },
                            body:JSON.stringify({
                                projectId:
                                    p.projectId
                            })
                        }
                    );
                }

                await idbDelete(
                    'projects',
                    p.projectId
                ).catch(()=>{});

                await idbDelete(
                    'videos',
                    p.projectId
                ).catch(()=>{});

                await renderProjectList();
            }catch(error){
                message(error.message);
            }
        };

        actions.append(
            open,
            del
        );

        row.append(
            info,
            actions
        );

        list.appendChild(row);
    });
}

/* =========================================================
   Editor state / locking
   ========================================================= */

function setEditorLocked(locked){
    const disabled =
        locked || !state.videoReady;

    $('playButton').disabled =
        disabled;

    $('timelinePlay').disabled =
        disabled;

    $('timelineSeek').disabled =
        disabled;

    $('saveProject').disabled =
        disabled;

    $('chooseVideo').disabled =
        false;

    document
        .querySelectorAll(
            '#timelineZoom'
        )
        .forEach(el=>{
            el.disabled =
                false;
        });
}

function showEditor(){
    $('home').style.display =
        'none';

    $('editor').classList.add(
        'ready'
    );
}

function showHome(){
    stopVideo();

    $('editor').classList.remove(
        'ready'
    );

    $('home').style.display =
        'flex';

    state.project = null;
    state.videoReady = false;
    state.duration = 0;

    if(state.videoObjectUrl){
        URL.revokeObjectURL(
            state.videoObjectUrl
        );
        state.videoObjectUrl = null;
    }

    $('recordedVideo').removeAttribute(
        'src'
    );

    $('recordedVideo').load();

    renderProjectList();
}

/* =========================================================
   New project
   ========================================================= */

async function newProject(){
    if(state.dirty && !confirm(
        '未保存の変更があります。新しいプロジェクトを作成しますか？'
    )){
        return;
    }

    state.project =
        emptyProject();

    state.videoReady = false;
    state.duration = 0;
    state.dirty = false;

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];

    $('editorProjectName').value =
        state.project.name;

    $('editorStatus').textContent =
        '動画未読込';

    $('videoEmpty').style.display =
        'flex';

    showEditor();
    setEditorLocked(true);
    renderAll();

    message(
        'プロジェクトを作成しました。動画を読み込んでください。',
        true
    );
}

/* =========================================================
   Load project
   ========================================================= */

async function loadProjectById(id,storage){
    try{
        setLoading(
            true,
            'プロジェクトを読み込んでいます...'
        );

        let project = null;

        if(storage === 'local'){
            project =
                await idbGet(
                    'projects',
                    id
                );
        }else{
            const response =
                await fetch(
                    '?api=load&id=' +
                    encodeURIComponent(id),
                    {cache:'no-store'}
                );

            if(!response.ok){
                throw new Error(
                    'プロジェクトを読み込めませんでした。'
                );
            }

            const data =
                await response.json();

            project =
                data.project;
        }

        if(!project){
            throw new Error(
                'プロジェクトが見つかりません。'
            );
        }

        state.project =
            normalizeProject(project);

        state.videoReady = false;
        state.duration =
            num(state.project.videoDuration);

        state.dirty = false;
        state.selectedId = null;
        state.selectedType = null;
        state.multiSelected = [];

        $('editorProjectName').value =
            state.project.name;

        showEditor();
        setEditorLocked(true);

        const savedVideo =
            await idbGet(
                'videos',
                state.project.projectId
            ).catch(()=>null);

        if(savedVideo?.blob){
            await attachVideoBlob(
                savedVideo.blob
            );
        }else{
            $('editorStatus').textContent =
                '動画未読込';

            $('videoEmpty').style.display =
                'flex';

            renderAll();

            message(
                'プロジェクトを読み込みました。動画を読み込んでください。',
                true
            );
        }

    }catch(error){
        message(
            '読み込みに失敗しました。\n' +
            error.message
        );
    }finally{
        setLoading(false);
    }
}

/* =========================================================
   Video loading
   ========================================================= */

async function handleVideoFile(file){
    if(!file || !state.project){
        return;
    }

    if(!file.type.startsWith('video/')){
        message(
            '動画ファイルを選択してください。'
        );
        return;
    }

    try{
        setLoading(
            true,
            '動画を読み込んでいます...'
        );

        await attachVideoBlob(
            file
        );

        state.project.videoName =
            file.name;

        $('editorStatus').textContent =
            '動画読込完了';

        markDirty();

        await saveLocalProject();

        message(
            '動画の読み込みが完了しました。編集できます。',
            true
        );

    }catch(error){
        state.videoReady = false;
        setEditorLocked(true);

        message(
            '動画の読み込みに失敗しました。\n' +
            error.message
        );
    }finally{
        setLoading(false);
    }
}

async function attachVideoBlob(blob){
    if(!state.project){
        throw new Error(
            'プロジェクトがありません。'
        );
    }

    const video =
        $('recordedVideo');

    if(state.videoObjectUrl){
        URL.revokeObjectURL(
            state.videoObjectUrl
        );
    }

    const url =
        URL.createObjectURL(blob);

    state.videoObjectUrl =
        url;

    state.videoReady =
        false;

    $('videoEmpty').style.display =
        'none';

    video.src = url;
    video.load();

    await new Promise((resolve,reject)=>{
        let finished = false;

        const done = ()=>{
            if(finished) return;
            finished = true;

            cleanup();
            resolve();
        };

        const fail = ()=>{
            if(finished) return;
            finished = true;

            cleanup();
            reject(
                new Error(
                    'ブラウザがこの動画を再生できません。'
                )
            );
        };

        const cleanup = ()=>{
            video.removeEventListener(
                'loadedmetadata',
                done
            );

            video.removeEventListener(
                'error',
                fail
            );
        };

        video.addEventListener(
            'loadedmetadata',
            done,
            {once:true}
        );

        video.addEventListener(
            'error',
            fail,
            {once:true}
        );
    });

    if(!Number.isFinite(video.duration) ||
       video.duration <= 0){
        throw new Error(
            '動画の長さを取得できません。'
        );
    }

    state.duration =
        video.duration;

    state.project.videoDuration =
        video.duration;

    /*
     * 既存要素の時間を動画時間内へ正規化。
     * これを読み込み完了時に必ず行う。
     */
    state.project.elements =
        state.project.elements
            .map(normalizeElement);

    state.project.connections =
        state.project.connections
            .map(normalizeConnection)
            .filter(Boolean)
            .filter(c=>
                c.from &&
                c.to
            );

    $('timelineSeek').min = '0';
    $('timelineSeek').max =
        String(video.duration);

    $('timelineSeek').step =
        '0.01';

    $('timelineSeek').value =
        String(
            clamp(
                num(video.currentTime),
                0,
                video.duration
            )
        );

    $('videoEmpty').style.display =
        'none';

    state.videoReady =
        true;

    setEditorLocked(false);

    $('editorStatus').textContent =
        '編集可能';

    renderAll();

    await idbPut(
        'videos',
        {
            projectId:
                state.project.projectId,
            blob,
            name:
                state.project.videoName
        }
    );
}

/* =========================================================
   Video playback
   ========================================================= */

function currentTime(){
    return state.videoReady
        ? num(
            $('recordedVideo').currentTime
        )
        : 0;
}

async function togglePlay(){
    if(!state.videoReady){
        return;
    }

    const video =
        $('recordedVideo');

    if(video.paused){
        try{
            await video.play();
        }catch(error){
            message(
                '再生できません。\n' +
                error.message
            );
        }
    }else{
        video.pause();
    }
}

function stopVideo(){
    const video =
        $('recordedVideo');

    try{
        video.pause();
    }catch{}

    if(Number.isFinite(video.duration)){
        video.currentTime =
            0;
    }
}

function seekTo(time){
    if(!state.videoReady){
        return;
    }

    const video =
        $('recordedVideo');

    const t =
        clamp(
            num(time),
            0,
            video.duration
        );

    try{
        video.currentTime = t;
    }catch{}

    updateTimeUI();
    renderAll();
}

/* =========================================================
   Shared time / playhead
   ========================================================= */

function updateTimeUI(){
    if(!state.videoReady){
        $('videoTime').textContent =
            '00:00.00 / 00:00.00';

        $('timelineTime').textContent =
            '00:00.00 / 00:00.00';

        return;
    }

    const t =
        currentTime();

    const d =
        duration();

    $('timelineSeek').value =
        String(t);

    $('videoTime').textContent =
        fmt(t) +
        ' / ' +
        fmt(d);

    $('timelineTime').textContent =
        fmt(t) +
        ' / ' +
        fmt(d);

    const ratio =
        d > 0
            ? t / d
            : 0;

    const content =
        $('timelineContent');

    const playhead =
        $('playhead');

    playhead.style.left =
        `${ratio * content.clientWidth}px`;

    $('playButton').textContent =
        $('recordedVideo').paused
            ? '▶'
            : 'Ⅱ';

    $('timelinePlay').textContent =
        $('recordedVideo').paused
            ? '▶'
            : 'Ⅱ';
}

function updatePlayhead(){
    updateTimeUI();
}

/* =========================================================
   Skip playback
   ========================================================= */

function checkSkip(){
    if(!state.videoReady ||
       state.skipLock ||
       $('recordedVideo').paused ||
       !state.project){
        return;
    }

    const t =
        currentTime();

    const skip =
        state.project.elements.find(
            e=>
                e.type === 'skip' &&
                t >= e.start &&
                t < e.end - .02
        );

    if(!skip){
        return;
    }

    const target =
        Math.min(
            duration(),
            skip.end
        );

    state.skipLock = true;

    $('recordedVideo').currentTime =
        target;

    setTimeout(()=>{
        state.skipLock = false;
    },120);

    updateTimeUI();
    renderAll();
}

/* =========================================================
   Rendering
   ========================================================= */

function renderAll(){
    renderObjects();
    renderTimeline();
    renderConnectors();
    updatePlayhead();
}

function renderObjects(){
    const container =
        $('objects');

    container.innerHTML = '';

    if(!state.project ||
       !state.videoReady){
        return;
    }

    const t =
        currentTime();

    state.project.elements.forEach(e=>{
        if(
            t < e.start ||
            t > e.end
        ){
            return;
        }

        const el =
            document.createElement('div');

        el.className =
            'edit-object ' +
            e.type;

        if(e.id === state.selectedId){
            el.classList.add(
                'selected'
            );
        }

        if(state.multiSelected.includes(e.id)){
            el.classList.add(
                'multi-selected'
            );
        }

        el.dataset.id =
            e.id;

        el.style.left =
            `${e.x}%`;

        el.style.top =
            `${e.y}%`;

        el.style.width =
            `${e.w}%`;

        el.style.height =
            `${e.h}%`;

        el.style.border =
            `${e.borderWidth}px ` +
            `${e.borderStyle} ` +
            e.color;

        el.style.color =
            e.color;

        if(e.type === 'comment'){
            el.style.fontSize =
                `${e.fontSize}px`;

            el.textContent =
                e.text || 'テキスト';
        }

        if(e.type === 'box'){
            el.style.background =
                hexToAlpha(e.color,.10);
        }

        if(e.type === 'skip'){
            el.style.background =
                hexToAlpha(e.color,.12);

            const label =
                document.createElement('span');

            label.className =
                'skip-label';

            label.textContent =
                'SKIP';

            el.appendChild(label);
        }

        const handle =
            document.createElement('div');

        handle.className =
            'resize-handle';

        el.appendChild(handle);

        bindObjectEvents(
            el,
            handle,
            e
        );

        container.appendChild(el);
    });
}

function hexToAlpha(color,alpha){
    if(!/^#[0-9a-f]{6}$/i.test(color)){
        return color;
    }

    const r =
        parseInt(
            color.slice(1,3),
            16
        );

    const g =
        parseInt(
            color.slice(3,5),
            16
        );

    const b =
        parseInt(
            color.slice(5,7),
            16
        );

    return `rgba(${r},${g},${b},${alpha})`;
}

/* =========================================================
   Timeline rendering
   ========================================================= */

function timelinePixelWidth(){
    const d =
        duration();

    const secondsPerMajor =
        num(
            $('timelineZoom').value,
            1
        );

    /*
     * 目盛りの粒度とは独立して、
     * 動画全体が必ず一本のX軸になる。
     */
    return Math.max(
        800,
        d / secondsPerMajor * 80
    );
}

function renderTimeline(){
    const content =
        $('timelineContent');

    const tracks =
        $('tracks');

    if(!state.project ||
       !state.videoReady){
        content.style.width =
            '800px';

        tracks.innerHTML = '';

        $('timelineScale').innerHTML =
            '';

        return;
    }

    const width =
        timelinePixelWidth();

    content.style.width =
        `${width}px`;

    renderScale(
        width
    );

    tracks.innerHTML = '';

    const groups = [
        ['テキスト','comment'],
        ['強調枠','box'],
        ['スキップ','skip'],
        ['接続','connection']
    ];

    groups.forEach(([name,type])=>{
        const items =
            type === 'connection'
                ? state.project.connections
                : state.project.elements
                    .filter(e=>e.type === type);

        if(!items.length){
            return;
        }

        renderTrack(
            name,
            type,
            items,
            width
        );
    });

    /*
     * すべてのトラックで同じ左端・右端。
     * 再生インデックスも同じ content 上。
     */
    updatePlayhead();
}

function renderScale(width){
    const scale =
        $('timelineScale');

    scale.innerHTML = '';

    const d =
        duration();

    const step =
        num(
            $('timelineZoom').value,
            1
        );

    for(
        let t = 0;
        t <= d + .0001;
        t += step
    ){
        const mark =
            document.createElement('span');

        mark.className =
            'timeline-scale-mark';

        mark.textContent =
            fmt(
                Math.min(t,d)
            );

        mark.style.left =
            `${t / d * 100}%`;

        scale.appendChild(mark);
    }
}

function renderTrack(name,type,items,width){
    const row =
        document.createElement('div');

    row.className =
        'timeline-row';

    const label =
        document.createElement('div');

    label.className =
        'timeline-label';

    label.textContent =
        name;

    const track =
        document.createElement('div');

    track.className =
        'track';

    if(type === 'connection'){
        track.classList.add(
            'connection-track'
        );
    }

    /*
     * ここが重要。
     *
     * widthは「動画全体の長さ」だけから決定。
     * 赤棒の位置、青棒の位置、選択要素の位置は
     * width計算へ一切影響しない。
     */
    track.style.width =
        `${width}px`;

    row.append(
        label,
        track
    );

    const lanes = [];

    const sorted =
        [...items].sort(
            (a,b)=>
                num(a.start) -
                num(b.start)
        );

    sorted.forEach(item=>{
        const d =
            duration();

        const start =
            clamp(
                num(item.start),
                0,
                d
            );

        const end =
            clamp(
                num(item.end,d),
                start,
                d
            );

        let lane = 0;

        while(
            lanes[lane] !== undefined &&
            lanes[lane] > start
        ){
            lane++;
        }

        lanes[lane] =
            end;

        const bar =
            document.createElement('div');

        bar.className =
            `track-item ${type}`;

        if(item.id === state.selectedId){
            bar.classList.add(
                'selected'
            );
        }

        if(
            state.multiSelected
                .includes(item.id)
        ){
            bar.classList.add(
                'multi'
            );
        }

        bar.dataset.id =
            item.id;

        /*
         * X座標は常に動画duration。
         */
        bar.style.left =
            `${start / d * 100}%`;

        bar.style.width =
            `${Math.max(
                0.35,
                (end-start) / d * 100
            )}%`;

        bar.style.top =
            `${6 + lane * 31}px`;

        if(lane > 0){
            track.style.height =
                `${42 + lane * 31}px`;
        }

        const startLabel =
            document.createElement('span');

        startLabel.className =
            'track-time';

        startLabel.textContent =
            fmt(start);

        const endLabel =
            document.createElement('span');

        endLabel.className =
            'track-end-time';

        endLabel.textContent =
            fmt(end);

        const nameLabel =
            document.createElement('span');

        nameLabel.className =
            'track-name';

        if(type === 'comment'){
            nameLabel.textContent =
                item.text || 'テキスト';
        }else if(type === 'box'){
            nameLabel.textContent =
                '強調枠';
        }else if(type === 'skip'){
            nameLabel.textContent =
                'SKIP';
        }else{
            nameLabel.textContent =
                '接続';
        }

        bar.append(
            startLabel,
            endLabel,
            nameLabel
        );

        if(type !== 'connection'){
            const left =
                document.createElement('div');

            left.className =
                'track-handle left';

            const right =
                document.createElement('div');

            right.className =
                'track-handle right';

            left.addEventListener(
                'pointerdown',
                event=>{
                    event.preventDefault();
                    event.stopPropagation();

                    beginTimelineResize(
                        event,
                        item,
                        'start',
                        track
                    );
                }
            );

            right.addEventListener(
                'pointerdown',
                event=>{
                    event.preventDefault();
                    event.stopPropagation();

                    beginTimelineResize(
                        event,
                        item,
                        'end',
                        track
                    );
                }
            );

            bar.append(
                left,
                right
            );
        }

        bar.addEventListener(
            'pointerdown',
            event=>{
                if(
                    event.target.classList
                        .contains('track-handle')
                ){
                    return;
                }

                beginTimelineMove(
                    event,
                    item,
                    type,
                    track
                );
            }
        );

        bar.addEventListener(
            'click',
            event=>{
                if(event.shiftKey){
                    toggleMultiSelection(
                        item.id,
                        type
                    );
                }else{
                    selectItem(
                        item.id,
                        type,
                        true
                    );
                }

                event.stopPropagation();
            }
        );

        bar.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                openContextMenu(
                    event.clientX,
                    event.clientY,
                    item.id,
                    type
                );
            }
        );

        track.appendChild(bar);
    });

    tracks.appendChild(row);
}

/* =========================================================
   Selection
   ========================================================= */

function clearSelection(){
    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];

    renderAll();
}

function selectItem(id,type,seek=true){
    state.selectedId =
        id;

    state.selectedType =
        type;

    state.multiSelected =
        [id];

    const item =
        type === 'connection'
            ? getConnection(id)
            : getElement(id);

    if(
        seek &&
        item &&
        state.videoReady
    ){
        seekTo(
            type === 'connection'
                ? item.start
                : item.start
        );
    }

    renderAll();
}

function toggleMultiSelection(id,type){
    if(type === 'connection'){
        return;
    }

    if(state.selectedType &&
       state.selectedType !== type){
        /*
         * 異なる種類でも選択可能。
         * ただし接続対象は要素だけ。
         */
    }

    const index =
        state.multiSelected.indexOf(id);

    if(index >= 0){
        state.multiSelected.splice(
            index,
            1
        );
    }else{
        state.multiSelected.push(id);
    }

    state.selectedId =
        id;

    state.selectedType =
        type;

    renderAll();
}

/* =========================================================
   Object events
   ========================================================= */

function bindObjectEvents(el,handle,item){
    el.addEventListener(
        'pointerdown',
        event=>{
            if(
                event.button !== 0 ||
                event.target === handle
            ){
                return;
            }

            beginObjectMove(
                event,
                item,
                el
            );
        }
    );

    handle.addEventListener(
        'pointerdown',
        event=>{
            if(event.button !== 0){
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            beginObjectResize(
                event,
                item,
                el
            );
        }
    );

    el.addEventListener(
        'click',
        event=>{
            event.stopPropagation();

            if(event.shiftKey){
                toggleMultiSelection(
                    item.id,
                    item.type
                );
            }else{
                selectItem(
                    item.id,
                    item.type,
                    true
                );
            }
        }
    );

    el.addEventListener(
        'contextmenu',
        event=>{
            event.preventDefault();
            event.stopPropagation();

            if(
                !state.multiSelected
                    .includes(item.id)
            ){
                state.multiSelected =
                    [item.id];

                state.selectedId =
                    item.id;

                state.selectedType =
                    item.type;
            }

            openContextMenu(
                event.clientX,
                event.clientY,
                item.id,
                item.type
            );
        }
    );
}

/* =========================================================
   Pointer capture helper
   ========================================================= */

function safeCapture(el,event){
    if(
        !el ||
        !event ||
        typeof el.setPointerCapture !==
            'function'
    ){
        return;
    }

    try{
        if(
            typeof el.hasPointerCapture !==
                'function' ||
            !el.hasPointerCapture(
                event.pointerId
            )
        ){
            el.setPointerCapture(
                event.pointerId
            );
        }
    }catch{
        /*
         * pointercapture失敗を操作全体の
         * JavaScript例外にしない。
         */
    }
}

function safeRelease(el,event){
    if(
        !el ||
        !event ||
        typeof el.releasePointerCapture !==
            'function'
    ){
        return;
    }

    try{
        if(
            typeof el.hasPointerCapture !==
                'function' ||
            el.hasPointerCapture(
                event.pointerId
            )
        ){
            el.releasePointerCapture(
                event.pointerId
            );
        }
    }catch{}
}

/* =========================================================
   Object move
   ========================================================= */

function beginObjectMove(event,item,element){
    if(!state.videoReady){
        return;
    }

    if(event.shiftKey){
        toggleMultiSelection(
            item.id,
            item.type
        );
        return;
    }

    state.selectedId =
        item.id;

    state.selectedType =
        item.type;

    if(
        !state.multiSelected.includes(
            item.id
        )
    ){
        state.multiSelected =
            [item.id];
    }

    const rect =
        $('videoStage').getBoundingClientRect();

    state.drag = {
        kind:'object-move',
        pointerId:event.pointerId,
        item,
        rect,
        startX:event.clientX,
        startY:event.clientY,
        x:item.x,
        y:item.y
    };

    safeCapture(
        element,
        event
    );

    const move = ev=>{
        if(
            !state.drag ||
            state.drag.pointerId !==
                ev.pointerId
        ){
            return;
        }

        const dx =
            (ev.clientX -
             state.drag.startX) /
            state.drag.rect.width *
            100;

        const dy =
            (ev.clientY -
             state.drag.startY) /
            state.drag.rect.height *
            100;

        item.x =
            clamp(
                state.drag.x + dx,
                0,
                100 - item.w
            );

        item.y =
            clamp(
                state.drag.y + dy,
                0,
                100 - item.h
            );

        markDirty();
        renderObjects();
        renderConnectors();
    };

    const end = ev=>{
        if(
            !state.drag ||
            state.drag.pointerId !==
                ev.pointerId
        ){
            return;
        }

        safeRelease(
            element,
            ev
        );

        cleanup();
        state.drag = null;
        markDirty();
        renderAll();
    };

    const cleanup = ()=>{
        window.removeEventListener(
            'pointermove',
            move
        );

        window.removeEventListener(
            'pointerup',
            end
        );

        window.removeEventListener(
            'pointercancel',
            end
        );
    };

    window.addEventListener(
        'pointermove',
        move
    );

    window.addEventListener(
        'pointerup',
        end
    );

    window.addEventListener(
        'pointercancel',
        end
    );

    renderAll();
}

/* =========================================================
   Object resize
   ========================================================= */

function beginObjectResize(event,item,element){
    if(!state.videoReady){
        return;
    }

    const rect =
        $('videoStage').getBoundingClientRect();

    state.drag = {
        kind:'object-resize',
        pointerId:event.pointerId,
        item,
        rect,
        startX:event.clientX,
        startY:event.clientY,
        w:item.w,
        h:item.h
    };

    safeCapture(
        element,
        event
    );

    const move = ev=>{
        if(
            !state.drag ||
            state.drag.pointerId !==
                ev.pointerId
        ){
            return;
        }

        const dx =
            (ev.clientX -
             state.drag.startX) /
            rect.width *
            100;

        const dy =
            (ev.clientY -
             state.drag.startY) /
            rect.height *
            100;

        item.w =
            clamp(
                state.drag.w + dx,
                1,
                100 - item.x
            );

        item.h =
            clamp(
                state.drag.h + dy,
                1,
                100 - item.y
            );

        markDirty();
        renderObjects();
        renderConnectors();
    };

    const end = ev=>{
        if(
            !state.drag ||
            state.drag.pointerId !==
                ev.pointerId
        ){
            return;
        }

        safeRelease(
            element,
            ev
        );

        cleanup();
        state.drag = null;
        markDirty();
        renderAll();
    };

    const cleanup = ()=>{
        window.removeEventListener(
            'pointermove',
            move
        );

        window.removeEventListener(
            'pointerup',
            end
        );

        window.removeEventListener(
            'pointercancel',
            end
        );
    };

    window.addEventListener(
        'pointermove',
        move
    );

    window.addEventListener(
        'pointerup',
        end
    );

    window.addEventListener(
        'pointercancel',
        end
    );
}

/* =========================================================
   Timeline move
   ========================================================= */

function timelineTimeFromPointer(
    event,
    track
){
    const rect =
        track.getBoundingClientRect();

    const x =
        clamp(
            event.clientX - rect.left,
            0,
            rect.width
        );

    return (
        x / rect.width
    ) * duration();
}

function beginTimelineMove(
    event,
    item,
    type,
    track
){
    if(!state.videoReady){
        return;
    }

    if(event.shiftKey){
        toggleMultiSelection(
            item.id,
            type
        );
        return;
    }

    state.selectedId =
        item.id;

    state.selectedType =
        type;

    state.multiSelected =
        [item.id];

    const d =
        duration();

    const start =
        num(item.start);

    const end =
        num(item.end);

    const length =
        Math.max(
            .01,
            end - start
        );

    const startTime =
        timelineTimeFromPointer(
            event,
            track
        );

    state.drag = {
        kind:'timeline-move',
        pointerId:event.pointerId,
        item,
        type,
        track,
        startTime,
        originalStart:start,
        originalEnd:end,
        length,
        trackElement:track
    };

    safeCapture(
        track,
        event
    );

    const move = ev=>{
        if(
            !state.drag ||
            state.drag.pointerId !==
                ev.pointerId
        ){
            return;
        }

        const now =
            timelineTimeFromPointer(
                ev,
                track
            );

        const delta =
            now -
            state.drag.startTime;

        let newStart =
            state.drag.originalStart +
            delta;

        newStart =
            clamp(
                newStart,
                0,
                d - state.drag.length
            );

        item.start =
            newStart;

        item.end =
            newStart +
            state.drag.length;

        markDirty();
        renderTimeline();
        renderObjects();
        renderConnectors();
    };

    const end = ev=>{
        if(
            !state.drag ||
            state.drag.pointerId !==
                ev.pointerId
        ){
            return;
        }

        safeRelease(
            track,
            ev
        );

        cleanup();

        state.drag = null;

        markDirty();
        renderAll();
    };

    const cleanup = ()=>{
        window.removeEventListener(
            'pointermove',
            move
        );

        window.removeEventListener(
            'pointerup',
            end
        );

        window.removeEventListener(
            'pointercancel',
            end
        );
    };

    window.addEventListener(
        'pointermove',
        move
    );

    window.addEventListener(
        'pointerup',
        end
    );

    window.addEventListener(
        'pointercancel',
        end
    );
}

/* =========================================================
   Timeline resize
   ========================================================= */

function beginTimelineResize(
    event,
    item,
    edge,
    track
){
    if(!state.videoReady){
        return;
    }

    const d =
        duration();

    const originalStart =
        num(item.start);

    const originalEnd =
        num(item.end);

    state.drag = {
        kind:'timeline-resize',
        pointerId:event.pointerId,
        item,
        edge,
        track,
        originalStart,
        originalEnd
    };

    safeCapture(
        track,
        event
    );

    const move = ev=>{
        if(
            !state.drag ||
            state.drag.pointerId !==
                ev.pointerId
        ){
            return;
        }

        const time =
            timelineTimeFromPointer(
                ev,
                track
            );

        const MIN =
            Math.min(
                .05,
                d
            );

        if(edge === 'start'){
            item.start =
                clamp(
                    time,
                    0,
                    Math.max(
                        0,
                        item.end - MIN
                    )
                );
        }else{
            item.end =
                clamp(
                    time,
                    Math.min(
                        d,
                        item.start + MIN
                    ),
                    d
                );
        }

        /*
         * 左端まで縮めてもwidth=0にはしない。
         */
        if(item.end <= item.start){
            if(edge === 'start'){
                item.start =
                    Math.max(
                        0,
                        item.end - MIN
                    );
            }else{
                item.end =
                    Math.min(
                        d,
                        item.start + MIN
                    );
            }
        }

        markDirty();

        renderTimeline();
        renderObjects();
        renderConnectors();
    };

    const end = ev=>{
        if(
            !state.drag ||
            state.drag.pointerId !==
                ev.pointerId
        ){
            return;
        }

        safeRelease(
            track,
            ev
        );

        cleanup();

        state.drag = null;

        markDirty();
        renderAll();
    };

    const cleanup = ()=>{
        window.removeEventListener(
            'pointermove',
            move
        );

        window.removeEventListener(
            'pointerup',
            end
        );

        window.removeEventListener(
            'pointercancel',
            end
        );
    };

    window.addEventListener(
        'pointermove',
        move
    );

    window.addEventListener(
        'pointerup',
        end
    );

    window.addEventListener(
        'pointercancel',
        end
    );
}

/* =========================================================
   Context menu
   ========================================================= */

function openContextMenu(
    x,
    y,
    targetId,
    targetType
){
    state.context = {
        x,
        y,
        targetId,
        targetType
    };

    const menu =
        $('contextMenu');

    const isConnection =
        targetType === 'connection';

    const selectedCount =
        state.multiSelected.length;

    $('ctxConnect').style.display =
        !isConnection &&
        selectedCount === 2
            ? 'block'
            : 'none';

    $('ctxEditConnection').style.display =
        isConnection
            ? 'block'
            : 'none';

    $('ctxEdit').style.display =
        isConnection
            ? 'none'
            : targetId
                ? 'block'
                : 'none';

    $('ctxDelete').style.display =
        targetId
            ? 'block'
            : 'none';

    $('ctxAddText').style.display =
        isConnection
            ? 'none'
            : 'block';

    $('ctxAddBox').style.display =
        isConnection
            ? 'none'
            : 'block';

    $('ctxAddSkip').style.display =
        isConnection
            ? 'none'
            : 'block';

    $('contextTitle').textContent =
        targetId
            ? (
                isConnection
                    ? '接続線'
                    : '選択中の要素'
            )
            : '動画';

    menu.style.display =
        'block';

    const w =
        menu.offsetWidth;

    const h =
        menu.offsetHeight;

    menu.style.left =
        `${Math.min(
            x,
            window.innerWidth - w - 8
        )}px`;

    menu.style.top =
        `${Math.min(
            y,
            window.innerHeight - h - 8
        )}px`;
}

function closeContextMenu(){
    $('contextMenu').style.display =
        'none';
}

/* =========================================================
   Add element
   ========================================================= */

function addElement(
    type,
    xPercent=20,
    yPercent=20
){
    if(!state.project ||
       !state.videoReady){
        message(
            '先に動画を読み込んでください。'
        );
        return;
    }

    const d =
        duration();

    const t =
        currentTime();

    const length =
        Math.min(
            type === 'skip'
                ? 2
                : 5,
            Math.max(
                .1,
                d - t
            )
        );

    const element =
        normalizeElement({
            id:uid('element'),
            type,
            text:
                type === 'comment'
                    ? 'テキスト'
                    : '',
            start:t,
            end:t + length,
            x:clamp(xPercent,0,80),
            y:clamp(yPercent,0,80),
            w:type === 'box' ? 35 : 28,
            h:type === 'box' ? 30 : 15,
            color:
                type === 'box'
                    ? '#ef5350'
                    : type === 'skip'
                        ? '#ffa726'
                        : '#ffffff'
        });

    state.project.elements.push(
        element
    );

    state.selectedId =
        element.id;

    state.selectedType =
        element.type;

    state.multiSelected =
        [element.id];

    markDirty();
    renderAll();

    if(type !== 'skip'){
        openElementModal(
            element
        );
    }
}

/* =========================================================
   Element modal
   ========================================================= */

function renderPalette(){
    const palette =
        $('elementPalette');

    palette.innerHTML = '';

    COLORS.forEach(color=>{
        const button =
            document.createElement('button');

        button.type =
            'button';

        button.style.background =
            color;

        button.dataset.color =
            color;

        button.addEventListener(
            'click',
            ()=>{
                palette.dataset.value =
                    color;

                palette
                    .querySelectorAll(
                        'button'
                    )
                    .forEach(b=>{
                        b.classList.toggle(
                            'active',
                            b.dataset.color ===
                                color
                        );
                    });
            }
        );

        palette.appendChild(button);
    });
}

function openElementModal(element){
    if(!element){
        return;
    }

    state.modalTarget =
        element.id;

    $('elementModal').style.display =
        'flex';

    $('elementType').value =
        element.type;

    $('elementText').value =
        element.text || '';

    $('elementStart').value =
        element.start;

    $('elementEnd').value =
        element.end;

    $('elementX').value =
        element.x;

    $('elementY').value =
        element.y;

    $('elementW').value =
        element.w;

    $('elementH').value =
        element.h;

    $('elementFontSize').value =
        element.fontSize;

    $('elementBorderWidth').value =
        element.borderWidth;

    $('elementBorderStyle').value =
        element.borderStyle;

    $('elementPalette').dataset.value =
        element.color;

    $('elementPalette')
        .querySelectorAll(
            'button'
        )
        .forEach(b=>{
            b.classList.toggle(
                'active',
                b.dataset.color ===
                    element.color
            );
        });

    updateElementModalFields();
}

function updateElementModalFields(){
    const type =
        $('elementType').value;

    const row =
        $('elementText').closest(
            'div'
        );

    if(row){
        /*
         * テキスト以外でもtextareaを残す。
         * 一般ユーザーが後から変更しやすい。
         */
    }

    $('elementFontSize').disabled =
        type !== 'comment';
}

function closeElementModal(){
    $('elementModal').style.display =
        'none';

    state.modalTarget =
        null;
}

function applyElementModal(){
    const element =
        getElement(
            state.modalTarget
        );

    if(!element){
        closeElementModal();
        return;
    }

    const d =
        duration();

    const start =
        clamp(
            num(
                $('elementStart').value
            ),
            0,
            d
        );

    const MIN =
        Math.min(
            .05,
            d
        );

    const end =
        clamp(
            num(
                $('elementEnd').value
            ),
            Math.min(
                d,
                start + MIN
            ),
            d
        );

    if(end <= start){
        message(
            '終了時間は開始時間より後にしてください。'
        );
        return;
    }

    const type =
        $('elementType').value;

    element.type =
        type;

    element.text =
        $('elementText').value;

    element.start =
        start;

    element.end =
        end;

    element.x =
        clamp(
            num($('elementX').value),
            0,
            99
        );

    element.y =
        clamp(
            num($('elementY').value),
            0,
            99
        );

    element.w =
        clamp(
            num($('elementW').value,30),
            1,
            100 - element.x
        );

    element.h =
        clamp(
            num($('elementH').value,15),
            1,
            100 - element.y
        );

    element.fontSize =
        clamp(
            num(
                $('elementFontSize').value,
                24
            ),
            8,
            96
        );

    element.borderWidth =
        clamp(
            num(
                $('elementBorderWidth').value,
                2
            ),
            0,
            20
        );

    element.borderStyle =
        $('elementBorderStyle').value;

    element.color =
        $('elementPalette').dataset.value ||
        element.color;

    if(type === 'skip'){
        element.borderStyle =
            'dashed';
    }

    markDirty();
    closeElementModal();
    renderAll();

    message(
        '要素を更新しました。',
        true
    );
}

function deleteElement(id){
    if(!state.project){
        return;
    }

    const index =
        state.project.elements.findIndex(
            e=>e.id === id
        );

    if(index < 0){
        return;
    }

    state.project.elements.splice(
        index,
        1
    );

    /*
     * 要素削除時、その要素に接続している線も削除。
     * 孤立線を残さない。
     */
    state.project.connections =
        state.project.connections.filter(
            c=>
                c.from !== id &&
                c.to !== id
        );

    state.multiSelected =
        state.multiSelected.filter(
            x=>x !== id
        );

    if(state.selectedId === id){
        state.selectedId = null;
        state.selectedType = null;
    }

    markDirty();
    closeElementModal();
    renderAll();
}

/* =========================================================
   Connection creation
   ========================================================= */

function canConnect(){
    const ids =
        state.multiSelected
            .filter(id=>getElement(id));

    return ids.length === 2;
}

function createConnectionFromSelection(){
    if(!canConnect()){
        message(
            '接続する要素を2つ選択してください。\n' +
            'Shiftキーを押しながら要素を2つクリックしてください。'
        );
        return;
    }

    const ids =
        state.multiSelected
            .filter(id=>getElement(id));

    const a =
        getElement(ids[0]);

    const b =
        getElement(ids[1]);

    if(!a || !b){
        return;
    }

    const d =
        duration();

    const start =
        Math.max(
            a.start,
            b.start
        );

    const end =
        Math.min(
            a.end,
            b.end
        );

    /*
     * 時間帯が重ならなくても線自体は作成できる。
     * その場合は2要素の共通時間を0にせず、
     * 選択要素の外側へ時間を広げる。
     */
    const connectionStart =
        end > start
            ? start
            : Math.min(
                a.start,
                b.start
            );

    const connectionEnd =
        end > start
            ? end
            : Math.max(
                a.end,
                b.end
            );

    const connection =
        normalizeConnection({
            id:uid('connection'),
            from:a.id,
            to:b.id,
            fromPoint:
                defaultPoint(a,b),
            toPoint:
                defaultPoint(b,a),
            start:
                clamp(
                    connectionStart,
                    0,
                    d
                ),
            end:
                clamp(
                    connectionEnd,
                    0,
                    d
                ),
            /*
             * 始点要素の枠色と同じ。
             */
            color:a.color,
            width:1.5,
            dash:'',
            arrow:true,
            curve:30
        });

    state.project.connections.push(
        connection
    );

    state.selectedId =
        connection.id;

    state.selectedType =
        'connection';

    state.multiSelected =
        [];

    markDirty();
    renderAll();

    openConnectionModal(
        connection
    );
}

function defaultPoint(a,b){
    const acx =
        a.x + a.w / 2;

    const acy =
        a.y + a.h / 2;

    const bcx =
        b.x + b.w / 2;

    const bcy =
        b.y + b.h / 2;

    const dx =
        bcx - acx;

    const dy =
        bcy - acy;

    if(Math.abs(dx) >= Math.abs(dy)){
        return dx >= 0
            ? 'right'
            : 'left';
    }

    return dy >= 0
        ? 'bottom'
        : 'top';
}

/* =========================================================
   Connection rendering
   ========================================================= */

function pointOfElement(
    element,
    point
){
    switch(point){
        case 'top':
            return {
                x:
                    element.x +
                    element.w / 2,
                y:
                    element.y
            };

        case 'right':
            return {
                x:
                    element.x +
                    element.w,
                y:
                    element.y +
                    element.h / 2
            };

        case 'bottom':
            return {
                x:
                    element.x +
                    element.w / 2,
                y:
                    element.y +
                    element.h
            };

        case 'left':
            return {
                x:
                    element.x,
                y:
                    element.y +
                    element.h / 2
            };

        default:
            return {
                x:
                    element.x +
                    element.w / 2,
                y:
                    element.y +
                    element.h / 2
            };
    }
}

function renderConnectors(){
    const svg =
        $('connectorSvg');

    svg.innerHTML = '';

    if(
        !state.project ||
        !state.videoReady
    ){
        return;
    }

    const width =
        $('videoStage').clientWidth;

    const height =
        $('videoStage').clientHeight;

    if(!width || !height){
        return;
    }

    svg.setAttribute(
        'viewBox',
        `0 0 ${width} ${height}`
    );

    svg.setAttribute(
        'width',
        String(width)
    );

    svg.setAttribute(
        'height',
        String(height)
    );

    const defs =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'defs'
        );

    svg.appendChild(defs);

    const t =
        currentTime();

    state.project.connections.forEach(c=>{
        const from =
            getElement(c.from);

        const to =
            getElement(c.to);

        if(!from || !to){
            return;
        }

        if(
            t < c.start ||
            t > c.end
        ){
            return;
        }

        const p1 =
            pointOfElement(
                from,
                c.fromPoint
            );

        const p2 =
            pointOfElement(
                to,
                c.toPoint
            );

        const x1 =
            p1.x / 100 * width;

        const y1 =
            p1.y / 100 * height;

        const x2 =
            p2.x / 100 * width;

        const y2 =
            p2.y / 100 * height;

        const dx =
            Math.abs(x2-x1);

        const curve =
            Math.max(
                20,
                dx * c.curve / 100
            );

        let d;

        if(c.curve <= 0){
            d =
                `M ${x1} ${y1} ` +
                `L ${x2} ${y2}`;
        }else{
            const dir =
                x2 >= x1
                    ? 1
                    : -1;

            const c1x =
                x1 +
                curve * dir;

            const c2x =
                x2 -
                curve * dir;

            d =
                `M ${x1} ${y1} ` +
                `C ${c1x} ${y1}, ` +
                `${c2x} ${y2}, ` +
                `${x2} ${y2}`;
        }

        let markerId = '';

        if(c.arrow){
            markerId =
                'arrow-' + c.id;

            const marker =
                document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'marker'
                );

            marker.setAttribute(
                'id',
                markerId
            );

            marker.setAttribute(
                'viewBox',
                '0 0 10 10'
            );

            marker.setAttribute(
                'refX',
                '9'
            );

            marker.setAttribute(
                'refY',
                '5'
            );

            marker.setAttribute(
                'markerWidth',
                '6'
            );

            marker.setAttribute(
                'markerHeight',
                '6'
            );

            marker.setAttribute(
                'orient',
                'auto'
            );

            const path =
                document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'path'
                );

            path.setAttribute(
                'd',
                'M 0 0 L 10 5 L 0 10 z'
            );

            path.setAttribute(
                'fill',
                c.color
            );

            marker.appendChild(path);
            defs.appendChild(marker);
        }

        /*
         * 背景側に太い透明ヒット領域。
         * これで線そのものを右クリックしやすくする。
         */
        const hit =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'path'
            );

        hit.setAttribute(
            'd',
            d
        );

        hit.setAttribute(
            'fill',
            'none'
        );

        hit.setAttribute(
            'stroke',
            'transparent'
        );

        hit.setAttribute(
            'stroke-width',
            '16'
        );

        hit.classList.add(
            'connector-hit'
        );

        hit.dataset.id =
            c.id;

        hit.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                state.selectedId =
                    c.id;

                state.selectedType =
                    'connection';

                openContextMenu(
                    event.clientX,
                    event.clientY,
                    c.id,
                    'connection'
                );
            }
        );

        svg.appendChild(hit);

        const path =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'path'
            );

        path.setAttribute(
            'd',
            d
        );

        path.setAttribute(
            'stroke',
            c.color
        );

        path.setAttribute(
            'stroke-width',
            String(c.width)
        );

        path.setAttribute(
            'fill',
            'none'
        );

        path.setAttribute(
            'vector-effect',
            'non-scaling-stroke'
        );

        if(c.dash){
            path.setAttribute(
                'stroke-dasharray',
                c.dash
            );
        }

        if(markerId){
            path.setAttribute(
                'marker-end',
                `url(#${markerId})`
            );
        }

        svg.appendChild(path);
    });
}

/* =========================================================
   Connection modal
   ========================================================= */

function openConnectionModal(connection){
    if(!connection){
        return;
    }

    state.connectionModalTarget =
        connection.id;

    $('connectionModal').style.display =
        'flex';

    $('connectionFromPoint').value =
        connection.fromPoint;

    $('connectionToPoint').value =
        connection.toPoint;

    $('connectionStart').value =
        connection.start;

    $('connectionEnd').value =
        connection.end;

    $('connectionColor').value =
        /^#[0-9a-f]{6}$/i.test(
            connection.color
        )
            ? connection.color
            : '#ffffff';

    $('connectionWidth').value =
        connection.width;

    $('connectionDash').value =
        connection.dash;

    $('connectionArrow').value =
        connection.arrow
            ? '1'
            : '0';

    $('connectionCurve').value =
        connection.curve;
}

function closeConnectionModal(){
    $('connectionModal').style.display =
        'none';

    state.connectionModalTarget =
        null;
}

function applyConnectionModal(){
    const connection =
        getConnection(
            state.connectionModalTarget
        );

    if(!connection){
        closeConnectionModal();
        return;
    }

    const d =
        duration();

    const start =
        clamp(
            num(
                $('connectionStart').value
            ),
            0,
            d
        );

    const end =
        clamp(
            num(
                $('connectionEnd').value
            ),
            start,
            d
        );

    if(end < start){
        message(
            '終了時間が開始時間より前です。'
        );
        return;
    }

    connection.fromPoint =
        $('connectionFromPoint').value;

    connection.toPoint =
        $('connectionToPoint').value;

    connection.start =
        start;

    connection.end =
        end;

    connection.color =
        $('connectionColor').value;

    connection.width =
        clamp(
            num(
                $('connectionWidth').value,
                1.5
            ),
            .5,
            10
        );

    connection.dash =
        $('connectionDash').value;

    connection.arrow =
        $('connectionArrow').value ===
            '1';

    connection.curve =
        clamp(
            num(
                $('connectionCurve').value,
                30
            ),
            0,
            100
        );

    markDirty();
    closeConnectionModal();
    renderAll();

    message(
        '接続線を更新しました。',
        true
    );
}

function deleteConnection(id){
    if(!state.project){
        return;
    }

    const index =
        state.project.connections.findIndex(
            c=>c.id === id
        );

    if(index < 0){
        return;
    }

    state.project.connections.splice(
        index,
        1
    );

    if(state.selectedId === id){
        state.selectedId = null;
        state.selectedType = null;
    }

    markDirty();
    closeConnectionModal();
    renderAll();
}

/* =========================================================
   Save
   ========================================================= */

async function saveProject(){
    if(!state.project){
        return;
    }

    if(!state.videoReady){
        message(
            '動画を読み込んでから保存してください。'
        );
        return;
    }

    state.project.name =
        $('editorProjectName').value.trim() ||
        '名称未設定';

    try{
        setLoading(
            true,
            '保存しています...'
        );

        /*
         * 常にまずローカル保存。
         * サーバー保存失敗でも作業データを失わない。
         */
        await saveLocalProject();

        let serverSaved = false;

        try{
            const response =
                await fetch(
                    '?api=save',
                    {
                        method:'POST',
                        headers:{
                            'Content-Type':
                                'application/json'
                        },
                        body:JSON.stringify(
                            state.project
                        )
                    }
                );

            const data =
                await response.json();

            if(response.ok && data.ok){
                state.project.projectId =
                    data.projectId ||
                    state.project.projectId;

                state.project.savedAt =
                    data.savedAt || '';

                serverSaved = true;
            }else if(
                response.status === 409 &&
                data.limit
            ){
                serverSaved = false;
            }
        }catch{}

        await saveLocalProject();

        state.dirty = false;

        $('editorStatus').textContent =
            serverSaved
                ? '保存済み（サーバー）'
                : '保存済み（このブラウザ）';

        await renderProjectList();

        message(
            serverSaved
                ? '保存しました。'
                : '保存しました（このブラウザ）。\n' +
                  'サーバー保存上限などのためローカル保存を使用しています。',
            true
        );

    }catch(error){
        message(
            '保存に失敗しました。\n' +
            error.message
        );
    }finally{
        setLoading(false);
    }
}

/* =========================================================
   Export / import
   ========================================================= */

function exportProject(){
    if(!state.project){
        message(
            'プロジェクトがありません。'
        );
        return;
    }

    const copy =
        deepCopy(state.project);

    const blob =
        new Blob(
            [
                JSON.stringify(
                    copy,
                    null,
                    2
                )
            ],
            {
                type:
                    'application/json'
            }
        );

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href =
        url;

    a.download =
        (
            state.project.name ||
            'video-project'
        ) +
        '.json';

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(()=>{
        URL.revokeObjectURL(url);
    },1000);

    message(
        '編集プロジェクトを書き出しました。',
        true
    );
}

async function importProjectFile(file){
    if(!file){
        return;
    }

    try{
        setLoading(
            true,
            'プロジェクトを読み込んでいます...'
        );

        const text =
            await file.text();

        const raw =
            JSON.parse(text);

        state.project =
            normalizeProject(raw);

        state.videoReady =
            false;

        state.duration =
            num(
                state.project.videoDuration
            );

        state.dirty = false;

        $('editorProjectName').value =
            state.project.name;

        showEditor();
        setEditorLocked(true);

        const video =
            await idbGet(
                'videos',
                state.project.projectId
            ).catch(()=>null);

        if(video?.blob){
            await attachVideoBlob(
                video.blob
            );
        }else{
            $('editorStatus').textContent =
                '動画未読込';

            $('videoEmpty').style.display =
                'flex';

            renderAll();

            message(
                'プロジェクトを読み込みました。\n' +
                '動画を選択してください。',
                true
            );
        }

    }catch(error){
        message(
            'プロジェクトの読み込みに失敗しました。\n' +
            error.message
        );
    }finally{
        setLoading(false);
    }
}

/* =========================================================
   Video position -> element visibility
   ========================================================= */

function handleTimeUpdate(){
    updateTimeUI();

    checkSkip();

    /*
     * 要素の表示・非表示を再生位置に同期。
     * タイムラインは別の時間軸を持たない。
     */
    renderObjects();
    renderConnectors();
}

/* =========================================================
   Events
   ========================================================= */

function initEvents(){

    $('newProject').addEventListener(
        'click',
        newProject
    );

    $('backHome').addEventListener(
        'click',
        ()=>{
            if(
                state.dirty &&
                !confirm(
                    '未保存の変更があります。戻りますか？'
                )
            ){
                return;
            }

            showHome();
        }
    );

    $('chooseVideo').addEventListener(
        'click',
        ()=>{
            $('videoFile').click();
        }
    );

    $('videoFile').addEventListener(
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

    $('playButton').addEventListener(
        'click',
        togglePlay
    );

    $('timelinePlay').addEventListener(
        'click',
        togglePlay
    );

    /*
     * 動画とタイムラインが共有する唯一のseek。
     */
    $('timelineSeek').addEventListener(
        'input',
        event=>{
            seekTo(
                num(event.target.value)
            );
        }
    );

    $('saveProject').addEventListener(
        'click',
        saveProject
    );

    $('exportProject').addEventListener(
        'click',
        exportProject
    );

    $('importProject').addEventListener(
        'click',
        ()=>{
            $('projectFile').click();
        }
    );

    $('projectFile').addEventListener(
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

    $('editorProjectName').addEventListener(
        'input',
        ()=>{
            if(!state.project){
                return;
            }

            state.project.name =
                $('editorProjectName')
                    .value;

            markDirty();
        }
    );

    $('recordedVideo').addEventListener(
        'loadedmetadata',
        ()=>{
            if(!state.videoReady){
                return;
            }

            renderAll();
        }
    );

    $('recordedVideo').addEventListener(
        'timeupdate',
        handleTimeUpdate
    );

    $('recordedVideo').addEventListener(
        'play',
        ()=>{
            updateTimeUI();
        }
    );

    $('recordedVideo').addEventListener(
        'pause',
        ()=>{
            updateTimeUI();
        }
    );

    $('recordedVideo').addEventListener(
        'ended',
        ()=>{
            updateTimeUI();
            renderAll();
        }
    );

    $('recordedVideo').addEventListener(
        'loadeddata',
        ()=>{
            if(state.videoReady){
                renderAll();
            }
        }
    );

    $('timelineZoom').addEventListener(
        'change',
        ()=>{
            renderTimeline();
        }
    );

    $('videoStage').addEventListener(
        'contextmenu',
        event=>{
            if(
                event.target.closest(
                    '.edit-object'
                )
            ){
                return;
            }

            event.preventDefault();

            if(!state.videoReady){
                return;
            }

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
                    90
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
                    90
                );

            state.context =
                {
                    x:event.clientX,
                    y:event.clientY,
                    targetId:null,
                    targetType:null,
                    addX:x,
                    addY:y
                };

            openContextMenu(
                event.clientX,
                event.clientY,
                null,
                null
            );
        }
    );

    $('videoStage').addEventListener(
        'click',
        event=>{
            if(
                event.target ===
                $('videoStage')
            ){
                clearSelection();
            }
        }
    );

    $('ctxAddText').addEventListener(
        'click',
        ()=>{
            const x =
                state.context.addX ?? 20;

            const y =
                state.context.addY ?? 20;

            closeContextMenu();

            addElement(
                'comment',
                x,
                y
            );
        }
    );

    $('ctxAddBox').addEventListener(
        'click',
        ()=>{
            const x =
                state.context.addX ?? 20;

            const y =
                state.context.addY ?? 20;

            closeContextMenu();

            addElement(
                'box',
                x,
                y
            );
        }
    );

    $('ctxAddSkip').addEventListener(
        'click',
        ()=>{
            closeContextMenu();

            addElement(
                'skip',
                state.context.addX ?? 20,
                state.context.addY ?? 20
            );
        }
    );

    $('ctxEdit').addEventListener(
        'click',
        ()=>{
            const item =
                getElement(
                    state.context.targetId
                );

            closeContextMenu();

            if(item){
                openElementModal(
                    item
                );
            }
        }
    );

    $('ctxConnect').addEventListener(
        'click',
        ()=>{
            closeContextMenu();

            createConnectionFromSelection();
        }
    );

    $('ctxEditConnection').addEventListener(
        'click',
        ()=>{
            const connection =
                getConnection(
                    state.context.targetId
                );

            closeContextMenu();

            if(connection){
                openConnectionModal(
                    connection
                );
            }
        }
    );

    $('ctxDelete').addEventListener(
        'click',
        ()=>{
            const id =
                state.context.targetId;

            const type =
                state.context.targetType;

            closeContextMenu();

            if(type === 'connection'){
                deleteConnection(id);
            }else if(id){
                deleteElement(id);
            }
        }
    );

    $('cancelElement').addEventListener(
        'click',
        closeElementModal
    );

    $('applyElement').addEventListener(
        'click',
        applyElementModal
    );

    $('deleteElement').addEventListener(
        'click',
        ()=>{
            const id =
                state.modalTarget;

            if(
                id &&
                confirm(
                    'この要素を削除しますか？'
                )
            ){
                deleteElement(id);
            }
        }
    );

    $('elementType').addEventListener(
        'change',
        updateElementModalFields
    );

    $('cancelConnection').addEventListener(
        'click',
        closeConnectionModal
    );

    $('applyConnection').addEventListener(
        'click',
        applyConnectionModal
    );

    $('deleteConnection').addEventListener(
        'click',
        ()=>{
            const id =
                state.connectionModalTarget;

            if(
                id &&
                confirm(
                    'この接続線を削除しますか？'
                )
            ){
                deleteConnection(id);
            }
        }
    );

    document.addEventListener(
        'click',
        event=>{
            const menu =
                $('contextMenu');

            if(
                menu.style.display ===
                    'block' &&
                !menu.contains(event.target)
            ){
                closeContextMenu();
            }
        }
    );

    window.addEventListener(
        'resize',
        ()=>{
            renderAll();
        }
    );

    document.addEventListener(
        'keydown',
        event=>{
            if(
                event.key === 'Escape'
            ){
                closeContextMenu();
                closeElementModal();
                closeConnectionModal();
            }

            if(
                event.key === 'Delete' &&
                !event.target.matches(
                    'input,textarea,select'
                )
            ){
                if(
                    state.selectedType ===
                    'connection' &&
                    state.selectedId
                ){
                    deleteConnection(
                        state.selectedId
                    );
                }else if(
                    state.selectedId
                ){
                    deleteElement(
                        state.selectedId
                    );
                }
            }

            if(
                event.code === 'Space' &&
                !event.target.matches(
                    'input,textarea,select'
                )
            ){
                event.preventDefault();

                if(state.videoReady){
                    togglePlay();
                }
            }
        }
    );
}

/* =========================================================
   Initialize
   ========================================================= */

async function init(){
    renderPalette();

    initEvents();

    $('editor').classList.remove(
        'ready'
    );

    setEditorLocked(true);

    $('status').textContent =
        '準備完了';

    await renderProjectList();
}

init().catch(error=>{
    console.error(error);

    message(
        '初期化に失敗しました。\n' +
        error.message
    );
});
</script>

</body>
</html>

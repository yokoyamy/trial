<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 完結版
 *
 * 保存:
 *   - サーバー: data/projects/*.json
 *   - ブラウザ: IndexedDB
 *   - 動画Blob: IndexedDB
 *
 * 書き出し:
 *   - 編集プロジェクトJSON
 *   - MP4への焼き込みはブラウザ標準機能だけでは安定しないため行わない
 */

const APP_VERSION = 31;
const MAX_SERVER_PROJECTS = 20;

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function serverProjects(): array
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

function projectSummary(array $p): array
{
    return [
        'projectId' => (string)($p['projectId'] ?? ''),
        'name' => (string)($p['name'] ?? '名称未設定'),
        'videoName' => (string)($p['videoName'] ?? ''),
        'videoDuration' => (float)($p['videoDuration'] ?? 0),
        'savedAt' => (string)($p['savedAt'] ?? ''),
        'version' => (int)($p['version'] ?? 1),
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
            'serverCount' => count(serverProjects())
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map('projectSummary', serverProjects()),
            'limit' => MAX_SERVER_PROJECTS
        ]);
    }

    if ($api === 'load') {
        $id = (string)($_GET['id'] ?? '');

        if (!validProjectId($id)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
        }

        $file = projectPath($id);

        if (!is_file($file)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトが見つかりません。'], 404);
        }

        $data = json_decode(@file_get_contents($file) ?: '', true);

        if (!is_array($data)) {
            jsonResponse(['ok' => false, 'message' => '保存データが壊れています。'], 500);
        }

        jsonResponse(['ok' => true, 'project' => $data]);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        if (!ensureProjectDir()) {
            jsonResponse([
                'ok' => false,
                'message' => 'サーバー保存領域へ書き込めません。'
            ], 500);
        }

        $payload = json_decode(file_get_contents('php://input') ?: '', true);

        if (!is_array($payload)) {
            jsonResponse(['ok' => false, 'message' => 'JSONが不正です。'], 400);
        }

        $id = trim((string)($payload['projectId'] ?? ''));

        if ($id === '') {
            $id = 'project-' . bin2hex(random_bytes(10));
        }

        if (!validProjectId($id)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
        }

        $name = trim((string)($payload['name'] ?? '名称未設定'));
        $name = $name !== '' ? $name : '名称未設定';

        if (mb_strlen($name) > 120) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクト名は120文字以内です。'
            ], 422);
        }

        $existing = is_file(projectPath($id));
        $projects = serverProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' => 'サーバー保存上限です。ローカル保存を使用してください。'
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

        if (@file_put_contents(projectPath($id), $json, LOCK_EX) === false) {
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
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        $payload = json_decode(file_get_contents('php://input') ?: '', true);
        $id = is_array($payload) ? (string)($payload['projectId'] ?? '') : '';

        if (!validProjectId($id)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
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
<html lang="ja" data-app-version="<?= htmlspecialchars((string)APP_VERSION, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画上直接編集ツール</title>

<style>
:root{
    --bg:#101216;
    --panel:#1b1e23;
    --panel2:#252a30;
    --panel3:#30353c;
    --border:#414750;
    --text:#f5f7fa;
    --muted:#9ca5af;
    --blue:#1976d2;
    --red:#ef4444;
    --green:#287348;
    --orange:#d9821b;
    --yellow:#ffd447;
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

button,input,select,textarea{font:inherit}

button{
    border:1px solid #565d66;
    background:#30353c;
    color:#fff;
    border-radius:6px;
    padding:7px 10px;
    cursor:pointer;
}

button:hover:not(:disabled){background:#424850}
button:disabled{opacity:.4;cursor:not-allowed}
button.primary{background:var(--blue)}
button.success{background:var(--green)}
button.danger{background:#a73535}

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

.hidden{display:none!important}

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
    top:55px;
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

#message.ok{background:#287348}

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

.home-card h2{margin:0 0 8px}

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

.project-info{min-width:0}

.project-name{font-weight:600}

.project-meta{
    color:#929aa4;
    font-size:11px;
    margin-top:3px;
}

.project-actions{
    display:flex;
    gap:5px;
}

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
    flex:0 0 auto;
    height:min(42vh,470px);
    min-height:230px;
    display:flex;
    justify-content:center;
    align-items:center;
    overflow:hidden;
    background:#000;
    position:relative;
}

#videoStage{
    position:relative;
    background:#000;
    line-height:0;
    width:min(72vw,900px);
    height:min(38vh,430px);
    max-height:calc(100% - 16px);
}

#recordedVideo{
    display:block;
    width:100%;
    height:100%;
    object-fit:contain;
    background:#000;
}

#objects,
#connectors{
    position:absolute;
    inset:0;
}

#connectors{
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
    outline:2px dashed var(--yellow);
    outline-offset:3px;
}

.edit-object.comment{
    display:flex;
    align-items:center;
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

.edit-object.selected .resize-handle{display:block}

.video-controls{
    position:absolute;
    left:50%;
    bottom:8px;
    transform:translateX(-50%);
    width:min(900px,92%);
    display:flex;
    align-items:center;
    gap:8px;
    padding:7px 9px;
    border-radius:7px;
    background:#111c;
    backdrop-filter:blur(4px);
    z-index:50;
}

#seek{
    flex:1;
    min-width:0;
}

#videoTime{
    min-width:130px;
    color:#fff;
    font-size:11px;
    font-variant-numeric:tabular-nums;
    text-align:right;
}

.timeline{
    flex:1 1 auto;
    min-height:270px;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:6px 10px 10px;
    overflow:auto;
}

.timeline-toolbar{
    height:38px;
    display:flex;
    align-items:center;
    gap:6px;
    position:sticky;
    top:0;
    z-index:200;
    background:#191c20;
}

#playToggle{
    width:42px;
    padding:5px;
    font-size:16px;
}

#currentTime{
    color:#d8dde2;
    font-variant-numeric:tabular-nums;
    min-width:145px;
}

#timelineZoom{width:100px}

.connection-status{
    padding:5px 9px;
    border-radius:5px;
    background:#15181c;
    border:1px solid #414750;
    color:#d9dee4;
    font-size:11px;
    margin-left:auto;
}

.timeline-scroll{
    position:relative;
    overflow-x:auto;
    overflow-y:visible;
    padding-bottom:20px;
}

.timeline-content{
    position:relative;
    min-width:800px;
}

.timeline-scale{
    position:relative;
    height:32px;
    color:#8f969f;
    font-size:10px;
    border-bottom:1px solid #444;
}

.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    white-space:nowrap;
}

.timeline-scale::after{
    content:"";
    position:absolute;
    left:0;
    right:0;
    bottom:0;
    height:1px;
    background:#1976d2;
}

.timeline-seek-layer{
    position:absolute;
    left:0;
    right:0;
    top:0;
    height:32px;
    z-index:5;
    cursor:pointer;
}

.timeline-playhead{
    position:absolute;
    top:32px;
    bottom:0;
    width:2px;
    background:#ef4444;
    box-shadow:0 0 6px #ef4444;
    z-index:150;
    pointer-events:none;
}

.timeline-playhead::before{
    content:"";
    position:absolute;
    top:-4px;
    left:-5px;
    width:12px;
    height:12px;
    border-radius:50%;
    background:#ef4444;
}

.timeline-row{
    display:grid;
    grid-template-columns:70px 1fr;
    gap:7px;
    margin-top:18px;
    align-items:start;
    font-size:11px;
    color:#b0b7c0;
}

.timeline-label{
    width:70px;
    white-space:nowrap;
    padding-top:12px;
}

.track{
    position:relative;
    height:42px;
    min-width:0;
    background:
        repeating-linear-gradient(
            to right,
            transparent 0,
            transparent calc(10% - 1px),
            #343a41 calc(10% - 1px),
            #343a41 10%
        ),
        #292d33;
    border-radius:4px;
    border:1px solid #3c4249;
    overflow:visible;
}

.track-item{
    position:absolute;
    height:28px;
    top:6px;
    min-width:14px;
    border-radius:3px;
    cursor:grab;
    z-index:10;
    touch-action:none;
    overflow:visible;
}

.track-item:active{cursor:grabbing}

.track-item.comment{background:#1976d2}
.track-item.box{background:#ef5350}
.track-item.skip{background:#d9821b}
.track-item.connection{background:#805ad5}

.track-item.selected{
    outline:2px solid #fff;
    outline-offset:1px;
}

.track-item.multi{
    outline:2px dashed #ffd447;
    outline-offset:2px;
}

.track-handle{
    position:absolute;
    top:-4px;
    bottom:-4px;
    width:14px;
    z-index:30;
    cursor:ew-resize;
}

.track-handle.left{left:-7px}
.track-handle.right{right:-7px}

.track-item:hover .track-handle{background:#fff8}

.track-time,
.track-end-time{
    position:absolute;
    top:-17px;
    font-size:9px;
    color:#dfe4e9;
    white-space:nowrap;
    pointer-events:none;
}

.track-time{left:0}
.track-end-time{right:0}

.track-name{
    position:absolute;
    left:7px;
    right:7px;
    top:6px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:#fff;
    font-size:9px;
    pointer-events:none;
}

.connector{
    cursor:pointer;
    pointer-events:stroke;
}

.connector.selected{
    filter:drop-shadow(0 0 3px #fff);
}

#contextMenu{
    position:fixed;
    z-index:10000;
    display:none;
    min-width:190px;
    background:#22262b;
    border:1px solid #555c65;
    border-radius:7px;
    box-shadow:0 10px 35px #0009;
    padding:5px;
}

#contextMenu button{
    display:block;
    width:100%;
    border:0;
    background:transparent;
    text-align:left;
    border-radius:4px;
}

#contextMenu button:hover{background:#3a4047}

.context-separator{
    height:1px;
    background:#454b53;
    margin:4px 0;
}

.modal-backdrop{
    position:fixed;
    inset:0;
    display:none;
    align-items:center;
    justify-content:center;
    z-index:12000;
    background:#0009;
    padding:20px;
}

.modal{
    width:min(620px,96vw);
    max-height:90vh;
    overflow:auto;
    background:#1b1e23;
    border:1px solid #4a5058;
    border-radius:9px;
    padding:18px;
    box-shadow:0 20px 60px #000b;
}

.modal h3{
    margin:0 0 15px;
}

.form-row{
    display:grid;
    grid-template-columns:130px 1fr;
    align-items:center;
    gap:10px;
    margin:8px 0;
}

.form-row>label{
    color:#c7cdd4;
    font-size:12px;
}

.color-palette{
    display:grid;
    grid-template-columns:repeat(6,32px);
    gap:6px;
}

.palette-color{
    width:30px;
    height:30px;
    padding:0;
    border-radius:5px;
    border:2px solid #555;
}

.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:15px;
}

#editor.locked .video-area::after{
    content:"動画を読み込むまで編集できません";
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:16px;
    background:#0008;
    z-index:100;
    pointer-events:none;
}

#editor.locked .timeline{
    pointer-events:none;
    opacity:.65;
}

#editorFooter{
    min-height:40px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:7px;
    background:#1b1e22;
    border-top:1px solid #383d44;
}

@media(max-width:800px){
    .editor-top{
        flex-wrap:wrap;
        min-height:78px;
    }

    #editorProjectName{width:170px}

    .video-area{height:40vh}

    .form-row{
        grid-template-columns:1fr;
        gap:4px;
    }
}
</style>
</head>

<body>

<header>
    <h1>動画上直接編集ツール</h1>
    <div id="status">初期化中...</div>
</header>

<div id="message"></div>

<section id="home">
    <div class="home-card">
        <h2>動画編集</h2>
        <p>
            動画を読み込んでから編集します。
            動画ファイルはブラウザのIndexedDBへ保存でき、
            編集データはサーバーまたはローカルへ保存できます。
        </p>

        <div class="home-actions">
            <button id="newProject" class="primary">新しい編集を開始</button>
            <button id="importProject">プロジェクトを読み込む</button>
            <input
                id="projectFile"
                type="file"
                accept=".json,application/json"
                class="hidden"
            >
        </div>

        <h3>保存済み編集</h3>
        <div id="projectList">読み込み中...</div>
    </div>
</section>

<section id="editor">

    <div class="editor-top">
        <button id="backHome">戻る</button>

        <input
            id="editorProjectName"
            value="新しい編集"
            maxlength="120"
        >

        <button id="chooseVideo" class="primary">動画を読み込む</button>

        <input
            id="videoFile"
            type="file"
            accept="video/*"
            class="hidden"
        >

        <button id="saveProject" class="success">保存</button>
        <button id="exportProject">書き出し</button>

        <span id="editorStatus">動画未読込</span>
    </div>

    <main class="editor-main">

        <section class="video-area" id="videoArea">

            <div id="videoStage">
                <video
                    id="recordedVideo"
                    preload="metadata"
                    playsinline
                ></video>

                <svg id="connectors"></svg>
                <div id="objects"></div>
            </div>

            <div class="video-controls">
                <button id="videoPlay" title="再生 / 停止">▶</button>

                <input
                    id="seek"
                    type="range"
                    min="0"
                    max="0"
                    step="0.001"
                    value="0"
                >

                <span id="videoTime">00:00.000 / 00:00.000</span>
            </div>

        </section>

        <section class="timeline">

            <div class="timeline-toolbar">

                <button id="playToggle" title="再生 / 停止">▶</button>

                <span id="currentTime">
                    00:00.000 / 00:00.000
                </span>

                <label>
                    時間軸
                    <select id="timelineZoom">
                        <option value="0.5">0.5x</option>
                        <option value="1" selected>1x</option>
                        <option value="2">2x</option>
                        <option value="3">3x</option>
                        <option value="5">5x</option>
                    </select>
                </label>

                <button id="timelineFit">全体表示</button>

                <span id="connectionStatus" class="connection-status">
                    Shift＋クリックで2要素を選択
                </span>

            </div>

            <div class="timeline-scroll" id="timelineScroll">

                <div
                    class="timeline-content"
                    id="timelineContent"
                >

                    <div
                        class="timeline-scale"
                        id="timelineScale"
                    ></div>

                    <div
                        class="timeline-playhead"
                        id="timelinePlayhead"
                    ></div>

                    <div
                        class="timeline-seek-layer"
                        id="timelineSeekLayer"
                    ></div>

                    <div id="timelineTracks"></div>

                </div>

            </div>

        </section>

    </main>

    <footer id="editorFooter">
        <button id="quickText">＋テキスト</button>
        <button id="quickBox">＋強調枠</button>
        <button id="quickSkip">＋スキップ</button>
    </footer>

</section>

<div id="contextMenu">

    <button data-action="add-comment">＋テキストを追加</button>
    <button data-action="add-box">＋強調枠を追加</button>
    <button data-action="add-skip">＋スキップを追加</button>

    <div class="context-separator"></div>

    <button data-action="edit">書式・内容を編集</button>
    <button data-action="duplicate">複製</button>
    <button data-action="delete">削除</button>

    <div class="context-separator"></div>

    <button
        id="contextConnect"
        data-action="connect"
    >
        選択した2要素を接続
    </button>

</div>

<div id="elementModal" class="modal-backdrop">
    <div class="modal">

        <h3>要素編集</h3>

        <div class="form-row">
            <label>種類</label>
            <select id="elementType">
                <option value="comment">テキスト</option>
                <option value="box">強調枠</option>
                <option value="skip">スキップ</option>
            </select>
        </div>

        <div class="form-row" id="elementTextRow">
            <label>テキスト</label>
            <textarea id="elementText"></textarea>
        </div>

        <div class="form-row">
            <label>開始秒</label>
            <input id="elementStart" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>終了秒</label>
            <input id="elementEnd" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>色</label>
            <div>
                <input id="elementColor" type="color">
                <div id="elementPalette" class="color-palette"></div>
            </div>
        </div>

        <div class="form-row">
            <label>文字サイズ</label>
            <input id="elementFontSize" type="number" min="8" max="96">
        </div>

        <div class="form-row">
            <label>枠線太さ</label>
            <input id="elementBorderWidth" type="number" min="0" max="20" step="1">
        </div>

        <div class="form-row">
            <label>枠線種類</label>
            <select id="elementBorderStyle">
                <option value="solid">実線</option>
                <option value="dashed">破線</option>
                <option value="dotted">点線</option>
            </select>
        </div>

        <div class="form-row">
            <label>背景色</label>
            <input id="elementFill" type="color">
        </div>

        <div class="modal-actions">
            <button id="elementDelete" class="danger">削除</button>
            <button id="elementCancel">キャンセル</button>
            <button id="elementApply" class="primary">適用</button>
        </div>

    </div>
</div>

<div id="connectionModal" class="modal-backdrop">
    <div class="modal">

        <h3>接続線編集</h3>

        <div class="form-row">
            <label>接続元</label>
            <select id="connectionFromPoint">
                <option value="n">上</option>
                <option value="e">右</option>
                <option value="s">下</option>
                <option value="w">左</option>
            </select>
        </div>

        <div class="form-row">
            <label>接続先</label>
            <select id="connectionToPoint">
                <option value="n">上</option>
                <option value="e">右</option>
                <option value="s">下</option>
                <option value="w">左</option>
            </select>
        </div>

        <div class="form-row">
            <label>開始秒</label>
            <input id="connectionStart" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>終了秒</label>
            <input id="connectionEnd" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>色</label>
            <input id="connectionColor" type="color">
        </div>

        <div class="form-row">
            <label>太さ</label>
            <input id="connectionWidth" type="number" min=".5" max="10" step=".5">
        </div>

        <div class="form-row">
            <label>線種</label>
            <select id="connectionDash">
                <option value="">実線</option>
                <option value="5 4">破線</option>
                <option value="2 3">点線</option>
            </select>
        </div>

        <div class="form-row">
            <label>矢印</label>
            <select id="connectionArrow">
                <option value="1">あり</option>
                <option value="0">なし</option>
            </select>
        </div>

        <div class="form-row">
            <label>曲率</label>
            <input id="connectionCurve" type="number" min="0" max="100">
        </div>

        <div class="modal-actions">
            <button id="connectionDelete" class="danger">接続削除</button>
            <button id="connectionCancel">キャンセル</button>
            <button id="connectionApply" class="primary">適用</button>
        </div>

    </div>
</div>

<script>
'use strict';

/*
 * PHPからJavaScriptへ明示的に値を渡す。
 * constの二重定義ではなくwindow経由にすることで、
 * 古いキャッシュ由来の定義衝突を避ける。
 */
window.APP_VERSION = Number(
    document.documentElement.dataset.appVersion || 31
);

window.MAX_SERVER_PROJECTS = <?= json_encode(MAX_SERVER_PROJECTS) ?>;

const APP_VERSION = window.APP_VERSION;
const MAX_SERVER_PROJECTS = window.MAX_SERVER_PROJECTS;

const $ = id => document.getElementById(id);

const COLORS = [
    '#ffffff',
    '#000000',
    '#ef4444',
    '#f97316',
    '#f59e0b',
    '#eab308',
    '#22c55e',
    '#14b8a6',
    '#06b6d4',
    '#3b82f6',
    '#8b5cf6',
    '#ec4899'
];

const state = {
    project:null,
    db:null,
    videoObjectUrl:null,

    selectedId:null,
    selectedType:null,
    multiSelected:[],

    context:null,

    elementDrag:null,
    timelineDrag:null,

    elementModalTarget:null,
    connectionModalTarget:null,

    zoom:1,
    dirty:false,

    messageTimer:null,
    skipGuard:false
};

function uid(prefix){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,10);
}

function num(value,fallback=0){
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
}

function clamp(value,min,max){
    return Math.max(min,Math.min(max,value));
}

function fmt(sec){
    sec = Math.max(0,num(sec));
    const m = Math.floor(sec / 60);
    const s = sec - m * 60;

    return String(m).padStart(2,'0') +
        ':' +
        s.toFixed(3).padStart(6,'0');
}

function message(text,ok=false){
    const el = $('message');

    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    clearTimeout(state.messageTimer);

    state.messageTimer = setTimeout(()=>{
        el.style.display = 'none';
    },3000);
}

function duration(){
    const video = $('recordedVideo');

    const vd = num(video.duration);

    if(Number.isFinite(vd) && vd > 0){
        return vd;
    }

    return num(state.project?.videoDuration,0);
}

function currentTime(){
    return clamp(
        num($('recordedVideo').currentTime),
        0,
        duration() || Infinity
    );
}

function setCurrentTime(time){
    const d = duration();

    if(!d){
        return;
    }

    const t = clamp(num(time),0,d);

    $('recordedVideo').currentTime = t;

    updateTimeUI();
    renderObjects();
    renderConnectors();
    updatePlayhead();
}

function emptyProject(){
    return {
        version:APP_VERSION,
        projectId:uid('project'),
        name:'新しい編集',
        videoName:'',
        videoDuration:0,
        videoType:'',
        videoSize:0,
        elements:[],
        connections:[],
        createdAt:new Date().toISOString(),
        savedAt:''
    };
}

function normalizeElement(e){
    const d = duration() || num(state.project?.videoDuration);

    e = e && typeof e === 'object' ? e : {};

    e.id = String(e.id || uid('element'));

    e.type = ['comment','box','skip'].includes(e.type)
        ? e.type
        : 'comment';

    e.start = clamp(num(e.start),0,Math.max(0,d));

    e.end = clamp(
        num(e.end,e.start + 5),
        Math.min(d,e.start + Math.min(.05,d)),
        Math.max(0,d)
    );

    e.x = clamp(num(e.x,10),0,100);
    e.y = clamp(num(e.y,10),0,100);

    e.w = clamp(
        num(e.w,30),
        1,
        100 - e.x
    );

    e.h = clamp(
        num(e.h,15),
        1,
        100 - e.y
    );

    e.text = String(e.text ?? 'テキスト');
    e.color = e.color || '#ffffff';

    e.fontSize = clamp(
        num(e.fontSize,24),
        8,
        96
    );

    e.borderWidth = clamp(
        num(e.borderWidth,2),
        0,
        20
    );

    e.borderStyle = e.borderStyle || 'solid';
    e.fill = e.fill || 'transparent';

    return e;
}

function normalizeConnection(c){
    c = c && typeof c === 'object' ? c : {};

    c.id = String(c.id || uid('connection'));

    c.from = String(c.from || '');
    c.to = String(c.to || '');

    c.fromPoint = ['n','e','s','w'].includes(c.fromPoint)
        ? c.fromPoint
        : 'e';

    c.toPoint = ['n','e','s','w'].includes(c.toPoint)
        ? c.toPoint
        : 'w';

    c.color = c.color || '#ffffff';

    c.width = clamp(
        num(c.width,1.5),
        .5,
        10
    );

    c.dash = typeof c.dash === 'string'
        ? c.dash
        : '';

    c.arrow = c.arrow !== false;

    c.curve = clamp(
        num(c.curve,35),
        0,
        100
    );

    const d = duration();

    c.start = clamp(
        num(c.start),
        0,
        d
    );

    c.end = clamp(
        num(c.end,d),
        c.start,
        d
    );

    return c;
}

function normalizeProject(p){
    p = p && typeof p === 'object'
        ? p
        : emptyProject();

    p.version = num(p.version,APP_VERSION);

    p.projectId = String(
        p.projectId || uid('project')
    );

    p.name = String(
        p.name || '名称未設定'
    );

    p.videoDuration = num(p.videoDuration,0);
    p.videoName = String(p.videoName || '');
    p.videoType = String(p.videoType || '');
    p.videoSize = num(p.videoSize,0);

    p.elements = Array.isArray(p.elements)
        ? p.elements.map(normalizeElement)
        : [];

    p.connections = Array.isArray(p.connections)
        ? p.connections.map(normalizeConnection)
        : [];

    return p;
}

function getElement(id){
    return state.project?.elements.find(
        e => e.id === id
    ) || null;
}

function getConnection(id){
    return state.project?.connections.find(
        c => c.id === id
    ) || null;
}

function markDirty(){
    state.dirty = true;

    if(state.project){
        $('editorStatus').textContent =
            `編集中 / ${state.project.videoName || '動画未読込'} / 未保存`;
    }
}

function showHome(){
    $('editor').style.display = 'none';
    $('home').style.display = 'flex';
}

function showEditor(){
    $('home').style.display = 'none';
    $('editor').style.display = 'flex';
}

function setEditorLocked(locked){
    $('editor').classList.toggle('locked',locked);

    [
        'saveProject',
        'exportProject',
        'quickText',
        'quickBox',
        'quickSkip'
    ].forEach(id=>{
        $(id).disabled = locked;
    });
}

function clearVideo(){
    const video = $('recordedVideo');

    video.pause();

    if(state.videoObjectUrl){
        URL.revokeObjectURL(state.videoObjectUrl);
        state.videoObjectUrl = null;
    }

    video.removeAttribute('src');
    video.load();

    $('seek').value = '0';
    $('seek').max = '0';
    $('videoTime').textContent =
        '00:00.000 / 00:00.000';

    $('currentTime').textContent =
        '00:00.000 / 00:00.000';

    $('editorStatus').textContent =
        '動画未読込';
}

function newProject(){
    state.project = emptyProject();

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];
    state.context = null;
    state.dirty = false;

    $('editorProjectName').value =
        state.project.name;

    clearVideo();
    showEditor();
    setEditorLocked(true);
    renderAll();
}

async function loadProject(project){
    state.project = normalizeProject(project);

    $('editorProjectName').value =
        state.project.name;

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];
    state.context = null;
    state.dirty = false;

    showEditor();

    const videoRecord =
        await idbGetVideo(
            state.project.projectId
        );

    if(videoRecord?.blob){
        await attachVideoBlob(videoRecord.blob);
    }else{
        clearVideo();
        setEditorLocked(true);
        message(
            'プロジェクトを読み込みました。動画ファイルも読み込んでください。'
        );
    }

    renderAll();
}

async function handleVideoFile(file){
    if(!file){
        return;
    }

    if(!file.type.startsWith('video/')){
        message('動画ファイルを選択してください。');
        return;
    }

    if(!state.project){
        state.project = emptyProject();
    }

    try{
        const url = URL.createObjectURL(file);
        const video = $('recordedVideo');

        if(state.videoObjectUrl){
            URL.revokeObjectURL(state.videoObjectUrl);
        }

        state.videoObjectUrl = url;

        video.src = url;
        video.load();

        await new Promise((resolve,reject)=>{
            const onLoaded = ()=>{
                cleanup();
                resolve();
            };

            const onError = ()=>{
                cleanup();
                reject(
                    new Error('動画を読み込めませんでした。')
                );
            };

            const cleanup = ()=>{
                video.removeEventListener(
                    'loadedmetadata',
                    onLoaded
                );
                video.removeEventListener(
                    'error',
                    onError
                );
            };

            video.addEventListener(
                'loadedmetadata',
                onLoaded,
                {once:true}
            );

            video.addEventListener(
                'error',
                onError,
                {once:true}
            );
        });

        state.project.videoName = file.name;
        state.project.videoType = file.type;
        state.project.videoSize = file.size;
        state.project.videoDuration = video.duration;

        await idbPutVideo(
            state.project.projectId,
            file
        );

        setEditorLocked(false);

        $('editorStatus').textContent =
            `${file.name} / ${fmt(video.duration)}`;

        $('seek').min = '0';
        $('seek').max = String(video.duration);
        $('seek').value = '0';

        renderAll();

        message(
            `動画を読み込みました。長さ ${fmt(video.duration)}`,
            true
        );

    }catch(error){
        console.error(error);

        clearVideo();
        setEditorLocked(true);

        message(
            error?.message ||
            '動画の読み込みに失敗しました。'
        );
    }
}

async function attachVideoBlob(blob){
    if(!(blob instanceof Blob)){
        return false;
    }

    const file = new File(
        [blob],
        state.project.videoName || 'video',
        {
            type:
                state.project.videoType ||
                blob.type ||
                'video/mp4'
        }
    );

    await handleVideoFile(file);

    return true;
}

/* =========================================================
 * Video controls
 * ======================================================= */

async function togglePlay(){
    if(!duration()){
        message('先に動画を読み込んでください。');
        return;
    }

    const video = $('recordedVideo');

    try{
        if(video.paused){
            await video.play();
        }else{
            video.pause();
        }
    }catch(error){
        console.error(error);
        message('動画を再生できませんでした。');
    }
}

function updatePlayButtons(){
    const playing = !$('recordedVideo').paused;

    $('videoPlay').textContent =
        playing ? 'Ⅱ' : '▶';

    $('playToggle').textContent =
        playing ? 'Ⅱ' : '▶';
}

function updateTimeUI(){
    const d = duration();
    const t = currentTime();

    $('videoTime').textContent =
        `${fmt(t)} / ${fmt(d)}`;

    $('currentTime').textContent =
        `${fmt(t)} / ${fmt(d)}`;

    if(d){
        $('seek').value = String(t);
        $('seek').max = String(d);
    }
}

function seekInput(event){
    setCurrentTime(
        num(event.target.value)
    );
}

function handleVideoTimeUpdate(){
    const video = $('recordedVideo');
    const t = video.currentTime;

    /*
     * スキップ:
     * 現在時刻が開始以上、終了未満なら一度だけ終了位置へ移動。
     */
    if(!state.skipGuard && state.project){
        const skip = state.project.elements.find(
            e =>
                e.type === 'skip' &&
                t >= e.start &&
                t < e.end - .001
        );

        if(skip){
            state.skipGuard = true;
            video.currentTime = skip.end;

            setTimeout(()=>{
                state.skipGuard = false;
            },50);

            return;
        }
    }

    updateTimeUI();
    renderObjects();
    renderConnectors();
    updatePlayhead();
    updatePlayButtons();
}

function handleVideoEnded(){
    updatePlayButtons();
}

/* =========================================================
 * Video object rendering
 * ======================================================= */

function renderObjects(){
    const container = $('objects');

    container.innerHTML = '';

    if(!state.project){
        return;
    }

    const t = currentTime();

    const visible =
        state.project.elements.filter(
            e =>
                t >= e.start &&
                t <= e.end
        );

    visible.forEach(e=>{
        const el = document.createElement('div');

        el.className =
            `edit-object ${e.type}` +
            (
                state.selectedId === e.id &&
                state.selectedType === 'element'
                    ? ' selected'
                    : ''
            ) +
            (
                state.multiSelected.includes(e.id)
                    ? ' multi-selected'
                    : ''
            );

        el.dataset.id = e.id;

        el.style.left = `${e.x}%`;
        el.style.top = `${e.y}%`;
        el.style.width = `${e.w}%`;
        el.style.height = `${e.h}%`;

        el.style.color = e.color;
        el.style.border =
            `${e.borderWidth}px ${e.borderStyle} ${e.color}`;

        if(e.fill !== 'transparent'){
            el.style.background = e.fill;
        }

        if(e.type === 'comment'){
            el.style.fontSize = `${e.fontSize}px`;
            el.textContent = e.text;
        }

        if(e.type === 'box'){
            el.style.background =
                e.fill === 'transparent'
                    ? 'transparent'
                    : e.fill;
        }

        if(e.type === 'skip'){
            const label =
                document.createElement('span');

            label.className = 'skip-label';
            label.textContent = 'SKIP';

            el.appendChild(label);
        }

        const resize =
            document.createElement('div');

        resize.className = 'resize-handle';

        el.appendChild(resize);

        el.addEventListener(
            'pointerdown',
            event=>{
                if(event.button !== 0){
                    return;
                }

                if(event.target === resize){
                    beginElementDrag(
                        event,
                        e,
                        'resize'
                    );
                    return;
                }

                beginElementDrag(
                    event,
                    e,
                    'move'
                );
            }
        );

        el.addEventListener(
            'click',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectElement(
                    e.id,
                    event.shiftKey
                );
            }
        );

        el.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectElement(e.id,false);
                openElementModal(e);
            }
        );

        el.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectElement(
                    e.id,
                    event.shiftKey
                );

                state.context = {
                    type:'element',
                    id:e.id
                };

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

        container.appendChild(el);
    });
}

function beginElementDrag(event,e,mode){
    if(!state.project || state.project.videoDuration <= 0){
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    const stage =
        $('videoStage').getBoundingClientRect();

    const target =
        event.currentTarget;

    try{
        target.setPointerCapture(
            event.pointerId
        );
    }catch(_){}

    selectElement(
        e.id,
        event.shiftKey
    );

    state.elementDrag = {
        id:e.id,
        mode,
        pointerId:event.pointerId,

        startX:event.clientX,
        startY:event.clientY,

        stageW:stage.width,
        stageH:stage.height,

        x:e.x,
        y:e.y,
        w:e.w,
        h:e.h
    };

    window.addEventListener(
        'pointermove',
        moveElementDrag
    );

    window.addEventListener(
        'pointerup',
        endElementDrag,
        {once:true}
    );
}

function moveElementDrag(event){
    const drag = state.elementDrag;

    if(!drag){
        return;
    }

    if(
        drag.pointerId !== undefined &&
        event.pointerId !== drag.pointerId
    ){
        return;
    }

    const e = getElement(drag.id);

    if(!e){
        return;
    }

    const dx =
        (event.clientX - drag.startX) /
        drag.stageW * 100;

    const dy =
        (event.clientY - drag.startY) /
        drag.stageH * 100;

    if(drag.mode === 'move'){
        e.x = clamp(
            drag.x + dx,
            0,
            100 - e.w
        );

        e.y = clamp(
            drag.y + dy,
            0,
            100 - e.h
        );
    }else{
        e.w = clamp(
            drag.w + dx,
            1,
            100 - e.x
        );

        e.h = clamp(
            drag.h + dy,
            1,
            100 - e.y
        );
    }

    markDirty();

    const dom =
        document.querySelector(
            `.edit-object[data-id="${CSS.escape(e.id)}"]`
        );

    if(dom){
        dom.style.left = `${e.x}%`;
        dom.style.top = `${e.y}%`;
        dom.style.width = `${e.w}%`;
        dom.style.height = `${e.h}%`;
    }

    renderConnectors();
}

function endElementDrag(){
    window.removeEventListener(
        'pointermove',
        moveElementDrag
    );

    state.elementDrag = null;

    renderAll();
}

/* =========================================================
 * Selection
 * ======================================================= */

function selectElement(id,multi=false){
    const e = getElement(id);

    if(!e){
        return;
    }

    if(multi){
        if(state.multiSelected.includes(id)){
            state.multiSelected =
                state.multiSelected.filter(
                    x => x !== id
                );
        }else{
            if(state.multiSelected.length >= 2){
                state.multiSelected.shift();
            }

            state.multiSelected.push(id);
        }
    }else{
        state.multiSelected = [id];
    }

    state.selectedId = id;
    state.selectedType = 'element';

    /*
     * 要素を選択したら必ずその開始時間へ移動。
     * これにより「バーを選んだのに画面に要素がない」を防ぐ。
     */
    if(
        Math.abs(currentTime() - e.start) > .001
    ){
        setCurrentTime(e.start);
    }

    updateConnectionStatus();
    renderObjects();
    renderTimeline();
}

function selectConnection(id){
    const c = getConnection(id);

    if(!c){
        return;
    }

    state.selectedId = id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    /*
     * 接続バーを選択した場合も開始時間へ移動。
     */
    setCurrentTime(c.start);

    updateConnectionStatus();
    renderTimeline();
    renderConnectors();
}

/* =========================================================
 * Add elements
 * ======================================================= */

function addElement(type,x=10,y=10){
    if(!duration()){
        message('動画を読み込んでから要素を追加してください。');
        return null;
    }

    const t = currentTime();
    const d = duration();

    const end =
        Math.min(
            d,
            t + (
                type === 'skip'
                    ? 3
                    : 5
            )
        );

    const e = normalizeElement({
        id:uid('element'),
        type,
        start:t,
        end,
        x:clamp(x,0,80),
        y:clamp(y,0,80),
        w:type === 'box' ? 30 : 32,
        h:type === 'box' ? 25 : 14,
        text:type === 'comment'
            ? 'テキスト'
            : '',
        color:
            type === 'skip'
                ? '#d9821b'
                : '#ffffff',
        fontSize:24,
        borderWidth:2,
        borderStyle:
            type === 'skip'
                ? 'dashed'
                : 'solid',
        fill:
            type === 'box'
                ? 'transparent'
                : 'transparent'
    });

    state.project.elements.push(e);

    state.selectedId = e.id;
    state.selectedType = 'element';
    state.multiSelected = [e.id];

    markDirty();
    renderAll();

    if(type === 'comment'){
        openElementModal(e);
    }

    return e;
}

/* =========================================================
 * Element modal
 * ======================================================= */

function buildPalette(){
    const root = $('elementPalette');

    root.innerHTML = '';

    COLORS.forEach(color=>{
        const button =
            document.createElement('button');

        button.type = 'button';
        button.className = 'palette-color';
        button.style.background = color;
        button.title = color;

        button.addEventListener(
            'click',
            ()=>{
                $('elementColor').value = color;
            }
        );

        root.appendChild(button);
    });
}

function openElementModal(e){
    state.elementModalTarget = e.id;

    $('elementType').value = e.type;
    $('elementText').value = e.text || '';
    $('elementStart').value = String(e.start);
    $('elementEnd').value = String(e.end);
    $('elementColor').value =
        /^#[0-9a-f]{6}$/i.test(e.color)
            ? e.color
            : '#ffffff';

    $('elementFontSize').value =
        String(e.fontSize);

    $('elementBorderWidth').value =
        String(e.borderWidth);

    $('elementBorderStyle').value =
        e.borderStyle;

    $('elementFill').value =
        /^#[0-9a-f]{6}$/i.test(e.fill)
            ? e.fill
            : '#000000';

    updateElementModalVisibility();

    $('elementModal').style.display = 'flex';
}

function updateElementModalVisibility(){
    const type = $('elementType').value;

    $('elementTextRow').style.display =
        type === 'comment'
            ? 'grid'
            : 'none';
}

function closeElementModal(){
    $('elementModal').style.display = 'none';
    state.elementModalTarget = null;
}

function applyElementModal(){
    const e =
        getElement(
            state.elementModalTarget
        );

    if(!e){
        closeElementModal();
        return;
    }

    const d = duration();

    let start = clamp(
        num($('elementStart').value),
        0,
        d
    );

    let end = clamp(
        num($('elementEnd').value),
        start,
        d
    );

    if(end < start){
        message('終了時間は開始時間以降にしてください。');
        return;
    }

    e.type =
        $('elementType').value;

    e.text =
        $('elementText').value;

    e.start = start;
    e.end = end;

    e.color =
        $('elementColor').value;

    e.fontSize =
        clamp(
            num($('elementFontSize').value,24),
            8,
            96
        );

    e.borderWidth =
        clamp(
            num($('elementBorderWidth').value,2),
            0,
            20
        );

    e.borderStyle =
        $('elementBorderStyle').value;

    /*
     * input type=color は透明を表現できないため、
     * #000000 を指定した場合だけ通常色として扱う。
     * 強調枠の透明初期値は維持可能。
     */
    if(e.type === 'box'){
        e.fill =
            $('elementFill').value;
    }

    if(e.type === 'skip'){
        e.borderStyle = 'dashed';
    }

    markDirty();

    closeElementModal();
    renderAll();

    message(
        '要素を更新しました。',
        true
    );
}

/* =========================================================
 * Connections
 * ======================================================= */

function connectSelected(){
    if(state.multiSelected.length !== 2){
        message(
            'Shift＋クリックで接続する2要素を選択してください。'
        );
        return;
    }

    const [fromId,toId] =
        state.multiSelected;

    const from = getElement(fromId);
    const to = getElement(toId);

    if(!from || !to){
        message('接続対象が見つかりません。');
        return;
    }

    if(from.id === to.id){
        message('同じ要素同士は接続できません。');
        return;
    }

    const duplicate =
        state.project.connections.some(
            c =>
                (
                    c.from === from.id &&
                    c.to === to.id
                ) ||
                (
                    c.from === to.id &&
                    c.to === from.id
                )
        );

    if(duplicate){
        message('この2要素はすでに接続されています。');
        return;
    }

    const c = normalizeConnection({
        id:uid('connection'),

        from:from.id,
        to:to.id,

        fromPoint:'e',
        toPoint:'w',

        start:Math.min(
            from.start,
            to.start
        ),

        end:Math.max(
            from.end,
            to.end
        ),

        /*
         * 要素枠と同じ色を初期値にする。
         */
        color:from.color || '#ffffff',

        /*
         * 初期値を細くする。
         */
        width:1.5,

        dash:'',
        arrow:true,
        curve:35
    });

    state.project.connections.push(c);

    state.selectedId = c.id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    markDirty();
    renderAll();

    message(
        '接続線を作成しました。',
        true
    );
}

function pointOfElement(e,point){
    let x = e.x;
    let y = e.y;

    if(point === 'n'){
        x += e.w / 2;
    }

    if(point === 'e'){
        x += e.w;
        y += e.h / 2;
    }

    if(point === 's'){
        x += e.w / 2;
        y += e.h;
    }

    if(point === 'w'){
        y += e.h / 2;
    }

    return {x,y};
}

function renderConnectors(){
    const svg = $('connectors');

    svg.innerHTML = '';

    if(!state.project){
        return;
    }

    const t = currentTime();

    const defs =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'defs'
        );

    svg.appendChild(defs);

    for(const c of state.project.connections){

        const from = getElement(c.from);
        const to = getElement(c.to);

        if(!from || !to){
            continue;
        }

        const selected =
            state.selectedType === 'connection' &&
            state.selectedId === c.id;

        /*
         * 通常は時間範囲内だけ表示。
         * 選択中は時間外でも表示して編集対象を失わない。
         */
        if(
            !selected &&
            (
                t < c.start ||
                t > c.end
            )
        ){
            continue;
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

        const width = svg.clientWidth;
        const height = svg.clientHeight;

        const x1 = p1.x / 100 * width;
        const y1 = p1.y / 100 * height;

        const x2 = p2.x / 100 * width;
        const y2 = p2.y / 100 * height;

        const dx = Math.abs(x2 - x1);

        const curve =
            Math.max(
                20,
                dx * c.curve / 100
            );

        let pathData;

        if(c.curve <= 0){
            pathData =
                `M ${x1} ${y1} L ${x2} ${y2}`;
        }else{
            const dir = x2 >= x1 ? 1 : -1;

            const c1x =
                x1 + curve * dir;

            const c2x =
                x2 - curve * dir;

            pathData =
                `M ${x1} ${y1} ` +
                `C ${c1x} ${y1}, ` +
                `${c2x} ${y2}, ` +
                `${x2} ${y2}`;
        }

        let markerId = '';

        if(c.arrow){
            markerId = `arrow-${c.id}`;

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
                'auto-start-reverse'
            );

            const arrow =
                document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'path'
                );

            arrow.setAttribute(
                'd',
                'M 0 0 L 10 5 L 0 10 z'
            );

            arrow.setAttribute(
                'fill',
                c.color
            );

            marker.appendChild(arrow);
            defs.appendChild(marker);
        }

        const path =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'path'
            );

        path.setAttribute(
            'd',
            pathData
        );

        path.setAttribute(
            'stroke',
            c.color
        );

        path.setAttribute(
            'stroke-width',
            String(
                selected
                    ? Math.max(2,c.width)
                    : c.width
            )
        );

        path.setAttribute(
            'fill',
            'none'
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

        path.classList.add('connector');

        if(selected){
            path.classList.add('selected');
        }

        path.style.pointerEvents = 'stroke';

        path.addEventListener(
            'click',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectConnection(c.id);
            }
        );

        path.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectConnection(c.id);
                openConnectionModal(c);
            }
        );

        path.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectConnection(c.id);

                state.context = {
                    type:'connection',
                    id:c.id
                };

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

        svg.appendChild(path);
    }
}

function openConnectionModal(c){
    state.connectionModalTarget = c.id;

    $('connectionFromPoint').value =
        c.fromPoint;

    $('connectionToPoint').value =
        c.toPoint;

    $('connectionStart').value =
        c.start;

    $('connectionEnd').value =
        c.end;

    $('connectionColor').value =
        /^#[0-9a-f]{6}$/i.test(c.color)
            ? c.color
            : '#ffffff';

    $('connectionWidth').value =
        c.width;

    $('connectionDash').value =
        c.dash;

    $('connectionArrow').value =
        c.arrow ? '1' : '0';

    $('connectionCurve').value =
        c.curve;

    $('connectionModal').style.display =
        'flex';
}

function closeConnectionModal(){
    $('connectionModal').style.display = 'none';
    state.connectionModalTarget = null;
}

function applyConnectionModal(){
    const c =
        getConnection(
            state.connectionModalTarget
        );

    if(!c){
        closeConnectionModal();
        return;
    }

    const d = duration();

    const start = clamp(
        num($('connectionStart').value),
        0,
        d
    );

    const end = clamp(
        num($('connectionEnd').value),
        start,
        d
    );

    c.fromPoint =
        $('connectionFromPoint').value;

    c.toPoint =
        $('connectionToPoint').value;

    c.start = start;
    c.end = end;

    c.color =
        $('connectionColor').value;

    c.width =
        clamp(
            num($('connectionWidth').value,1.5),
            .5,
            10
        );

    c.dash =
        $('connectionDash').value;

    c.arrow =
        $('connectionArrow').value === '1';

    c.curve =
        clamp(
            num($('connectionCurve').value,35),
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
    const index =
        state.project.connections.findIndex(
            c => c.id === id
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
    renderAll();
}

/* =========================================================
 * Timeline
 *
 * ここが重要:
 *
 * すべてのトラックは同じ timelineWidth。
 * timelineWidth は動画の長さだけで決まる。
 * 再生位置・要素位置・赤線位置は幅計算に一切使わない。
 * ======================================================= */

function timelineWidth(){
    const d = duration();

    if(!d){
        return 800;
    }

    return Math.max(
        800,
        d * 70 * state.zoom
    );
}

function timelineStep(){
    if(state.zoom >= 4){
        return 1;
    }

    if(state.zoom >= 2){
        return 2;
    }

    if(state.zoom >= 1){
        return 5;
    }

    return 10;
}

function renderTimeline(){
    const content =
        $('timelineContent');

    const scale =
        $('timelineScale');

    const tracks =
        $('timelineTracks');

    /*
     * ここで必ずtracksをローカル取得。
     * 旧版の「tracks is not defined」を根本的に防ぐ。
     */
    scale.innerHTML = '';
    tracks.innerHTML = '';

    const d = duration();

    if(!d){
        content.style.width = '800px';

        renderTimelineScale(800,1);

        updatePlayhead();
        return;
    }

    const width = timelineWidth();

    /*
     * scale / playhead / seek / tracks 全部が
     * 同一timeline-contentを基準にする。
     */
    content.style.width =
        `${width}px`;

    renderTimelineScale(
        width,
        d
    );

    const groups = [
        [
            'テキスト',
            'comment',
            state.project.elements.filter(
                e => e.type === 'comment'
            )
        ],
        [
            '強調枠',
            'box',
            state.project.elements.filter(
                e => e.type === 'box'
            )
        ],
        [
            'スキップ',
            'skip',
            state.project.elements.filter(
                e => e.type === 'skip'
            )
        ],
        [
            '接続',
            'connection',
            state.project.connections
        ]
    ];

    groups.forEach(
        ([name,type,items])=>{
            renderTrack(
                name,
                type,
                items,
                width,
                d
            );
        }
    );

    updatePlayhead();
}

function renderTimelineScale(width,d){
    const scale =
        $('timelineScale');

    scale.innerHTML = '';

    const step =
        timelineStep();

    for(
        let t = 0;
        t <= d + .0001;
        t += step
    ){
        const actual =
            Math.min(t,d);

        const span =
            document.createElement('span');

        span.textContent =
            fmt(actual);

        span.style.left =
            `${actual / d * 100}%`;

        scale.appendChild(span);
    }
}

function renderTrack(
    name,
    type,
    items,
    width,
    d
){
    const tracks =
        $('timelineTracks');

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

    /*
     * 全トラック同一幅。
     * 赤線位置や要素位置で変化しない。
     */
    track.style.width =
        `${width}px`;

    row.appendChild(label);
    row.appendChild(track);

    /*
     * 同じ種類の要素が時間的に重なる場合、
     * 同じX軸のまま縦方向へレーン分けする。
     */
    const lanes = [];

    const sorted =
        [...items].sort(
            (a,b)=>
                num(a.start) -
                num(b.start)
        );

    sorted.forEach(item=>{
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

        lanes[lane] = end;

        const bar =
            document.createElement('div');

        bar.className =
            `track-item ${type}` +
            (
                state.selectedId === item.id
                    ? ' selected'
                    : ''
            ) +
            (
                state.multiSelected.includes(item.id)
                    ? ' multi'
                    : ''
            );

        bar.dataset.id =
            item.id;

        /*
         * Xは常に動画時間基準。
         */
        bar.style.left =
            `${start / d * 100}%`;

        /*
         * 幅も必ずend-start。
         */
        bar.style.width =
            `${Math.max(
                .25,
                (end-start) / d * 100
            )}%`;

        bar.style.top =
            `${6 + lane * 31}px`;

        if(lane > 0){
            track.style.height =
                `${42 + lane * 31}px`;
        }

        const startText =
            document.createElement('span');

        startText.className =
            'track-time';

        startText.textContent =
            fmt(start);

        const endText =
            document.createElement('span');

        endText.className =
            'track-end-time';

        endText.textContent =
            fmt(end);

        const nameText =
            document.createElement('span');

        nameText.className =
            'track-name';

        if(type === 'comment'){
            nameText.textContent =
                item.text || 'テキスト';
        }else if(type === 'box'){
            nameText.textContent =
                '強調枠';
        }else if(type === 'skip'){
            nameText.textContent =
                'SKIP';
        }else{
            nameText.textContent =
                `${fmt(start)} → ${fmt(end)}`;
        }

        bar.appendChild(startText);
        bar.appendChild(endText);
        bar.appendChild(nameText);

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
                    beginTimelineResize(
                        event,
                        item,
                        'left',
                        track,
                        d
                    );
                }
            );

            right.addEventListener(
                'pointerdown',
                event=>{
                    beginTimelineResize(
                        event,
                        item,
                        'right',
                        track,
                        d
                    );
                }
            );

            bar.appendChild(left);
            bar.appendChild(right);
        }

        bar.addEventListener(
            'click',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(type === 'connection'){
                    selectConnection(item.id);
                }else{
                    selectElement(
                        item.id,
                        event.shiftKey
                    );
                }
            }
        );

        bar.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(type === 'connection'){
                    selectConnection(item.id);
                    openConnectionModal(item);
                }else{
                    selectElement(
                        item.id,
                        event.shiftKey
                    );
                    openElementModal(item);
                }
            }
        );

        bar.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(type === 'connection'){
                    selectConnection(item.id);

                    state.context = {
                        type:'connection',
                        id:item.id
                    };
                }else{
                    selectElement(
                        item.id,
                        event.shiftKey
                    );

                    state.context = {
                        type:'element',
                        id:item.id
                    };
                }

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

        if(type !== 'connection'){
            bar.addEventListener(
                'pointerdown',
                event=>{
                    if(
                        event.target.closest(
                            '.track-handle'
                        )
                    ){
                        return;
                    }

                    beginTimelineMove(
                        event,
                        item,
                        track,
                        d
                    );
                }
            );
        }

        track.appendChild(bar);
    });

    tracks.appendChild(row);
}

/* =========================================================
 * Timeline dragging
 * ======================================================= */

function beginTimelineMove(
    event,
    item,
    track,
    d
){
    if(event.button !== 0){
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    selectElement(
        item.id,
        event.shiftKey
    );

    state.timelineDrag = {
        mode:'move',
        id:item.id,
        pointerId:event.pointerId,
        track,
        d,
        startPointer:event.clientX,
        originalStart:num(item.start),
        originalEnd:num(item.end)
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

function beginTimelineResize(
    event,
    item,
    edge,
    track,
    d
){
    if(event.button !== 0){
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    selectElement(
        item.id,
        false
    );

    state.timelineDrag = {
        mode:edge,
        id:item.id,
        pointerId:event.pointerId,
        track,
        d,
        startPointer:event.clientX,
        originalStart:num(item.start),
        originalEnd:num(item.end)
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

    if(!drag){
        return;
    }

    if(
        drag.pointerId !== undefined &&
        event.pointerId !== drag.pointerId
    ){
        return;
    }

    const item =
        getElement(drag.id);

    if(!item){
        return;
    }

    const rect =
        drag.track.getBoundingClientRect();

    if(rect.width <= 0){
        return;
    }

    const secondsPerPixel =
        drag.d / rect.width;

    const delta =
        (
            event.clientX -
            drag.startPointer
        ) * secondsPerPixel;

    const minLength =
        Math.min(.05,drag.d);

    if(drag.mode === 'move'){
        const len =
            drag.originalEnd -
            drag.originalStart;

        const start =
            clamp(
                drag.originalStart + delta,
                0,
                drag.d - len
            );

        item.start = start;
        item.end = start + len;

    }else if(drag.mode === 'left'){
        item.start =
            clamp(
                drag.originalStart + delta,
                0,
                drag.originalEnd - minLength
            );

    }else{
        item.end =
            clamp(
                drag.originalEnd + delta,
                drag.originalStart + minLength,
                drag.d
            );
    }

    markDirty();

    /*
     * ドラッグ中もバーを再生成する。
     * ただしwindowでpointermoveを受けているため、
     * 再生成で元要素が消えてもドラッグイベントは失われない。
     */
    renderTimeline();
}

function endTimelineDrag(){
    window.removeEventListener(
        'pointermove',
        moveTimelineDrag
    );

    state.timelineDrag = null;

    renderAll();
}

/* =========================================================
 * Playhead
 * ======================================================= */

function updatePlayhead(){
    const d = duration();

    const playhead =
        $('timelinePlayhead');

    if(!d){
        playhead.style.left = '0px';
        return;
    }

    const ratio =
        clamp(
            currentTime() / d,
            0,
            1
        );

    /*
     * timeline-content自身が動画時間軸なので、
     * 0秒と最後の秒が必ず同じX軸になる。
     */
    playhead.style.left =
        `${ratio * 100}%`;
}

function seekFromTimeline(event){
    if(!duration()){
        return;
    }

    const rect =
        $('timelineSeekLayer')
            .getBoundingClientRect();

    if(rect.width <= 0){
        return;
    }

    const x =
        clamp(
            event.clientX - rect.left,
            0,
            rect.width
        );

    setCurrentTime(
        x / rect.width * duration()
    );
}

/* =========================================================
 * Context menu
 * ======================================================= */

function showContextMenu(x,y){
    const menu = $('contextMenu');
    const connect = $('contextConnect');

    connect.style.display =
        state.multiSelected.length === 2
            ? 'block'
            : 'none';

    menu.style.display = 'block';

    const rect =
        menu.getBoundingClientRect();

    menu.style.left =
        `${Math.min(
            x,
            window.innerWidth -
            rect.width -
            8
        )}px`;

    menu.style.top =
        `${Math.min(
            y,
            window.innerHeight -
            rect.height -
            8
        )}px`;
}

function hideContextMenu(){
    $('contextMenu').style.display = 'none';
}

function updateConnectionStatus(){
    const el =
        $('connectionStatus');

    if(state.multiSelected.length === 2){
        el.textContent =
            '2要素選択中 → 右クリック「選択した2要素を接続」';
        return;
    }

    if(state.multiSelected.length === 1){
        el.textContent =
            '1要素選択中 → Shift＋クリックでもう1つ';
        return;
    }

    if(state.selectedType === 'connection'){
        el.textContent =
            '接続線を選択中 → 右クリック／ダブルクリックで編集';
        return;
    }

    el.textContent =
        'Shift＋クリックで2要素選択 → 右クリックで接続';
}

/* =========================================================
 * Delete / duplicate
 * ======================================================= */

function deleteSelected(){
    if(
        state.selectedType === 'connection' &&
        state.selectedId
    ){
        deleteConnection(
            state.selectedId
        );

        message(
            '接続線を削除しました。',
            true
        );

        return;
    }

    const ids =
        state.multiSelected.length
            ? [...state.multiSelected]
            : state.selectedId
                ? [state.selectedId]
                : [];

    if(!ids.length){
        message('削除する要素を選択してください。');
        return;
    }

    state.project.elements =
        state.project.elements.filter(
            e => !ids.includes(e.id)
        );

    state.project.connections =
        state.project.connections.filter(
            c =>
                !ids.includes(c.from) &&
                !ids.includes(c.to)
        );

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];

    markDirty();
    renderAll();

    message(
        '要素を削除しました。',
        true
    );
}

function duplicateSelected(){
    const e =
        getElement(state.selectedId);

    if(!e){
        message('複製する要素を選択してください。');
        return;
    }

    const copy =
        JSON.parse(
            JSON.stringify(e)
        );

    copy.id =
        uid('element');

    copy.x =
        clamp(
            copy.x + 3,
            0,
            100 - copy.w
        );

    copy.y =
        clamp(
            copy.y + 3,
            0,
            100 - copy.h
        );

    state.project.elements.push(copy);

    selectElement(
        copy.id,
        false
    );

    markDirty();
    renderAll();

    message(
        '要素を複製しました。',
        true
    );
}

/* =========================================================
 * Render
 * ======================================================= */

function renderAll(){
    renderObjects();
    renderConnectors();
    renderTimeline();
    updateTimeUI();
    updatePlayhead();
    updateConnectionStatus();
    updatePlayButtons();
}

/* =========================================================
 * IndexedDB
 * ======================================================= */

function openDB(){
    return new Promise((resolve,reject)=>{
        const request =
            indexedDB.open(
                'VideoDirectEditor',
                4
            );

        request.onupgradeneeded =
            event=>{
                const db =
                    event.target.result;

                if(
                    !db.objectStoreNames.contains(
                        'projects'
                    )
                ){
                    db.createObjectStore(
                        'projects',
                        {
                            keyPath:'projectId'
                        }
                    );
                }

                if(
                    !db.objectStoreNames.contains(
                        'videos'
                    )
                ){
                    db.createObjectStore(
                        'videos',
                        {
                            keyPath:'projectId'
                        }
                    );
                }
            };

        request.onsuccess = ()=>{
            state.db = request.result;
            resolve(state.db);
        };

        request.onerror = ()=>{
            reject(
                request.error ||
                new Error(
                    'IndexedDBを開けません。'
                )
            );
        };
    });
}

function idbPutProject(project){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            reject(
                new Error(
                    'IndexedDB unavailable'
                )
            );
            return;
        }

        if(
            !project ||
            !project.projectId
        ){
            reject(
                new Error(
                    'projectIdがありません。'
                )
            );
            return;
        }

        const copy =
            JSON.parse(
                JSON.stringify(project)
            );

        const tx =
            state.db.transaction(
                'projects',
                'readwrite'
            );

        tx.objectStore(
            'projects'
        ).put(copy);

        tx.oncomplete =
            ()=>resolve();

        tx.onerror =
            ()=>reject(tx.error);
    });
}

function idbGetProject(id){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            resolve(null);
            return;
        }

        const tx =
            state.db.transaction(
                'projects',
                'readonly'
            );

        const request =
            tx.objectStore(
                'projects'
            ).get(id);

        request.onsuccess =
            ()=>resolve(
                request.result || null
            );

        request.onerror =
            ()=>reject(request.error);
    });
}

function idbListProjects(){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            resolve([]);
            return;
        }

        const tx =
            state.db.transaction(
                'projects',
                'readonly'
            );

        const request =
            tx.objectStore(
                'projects'
            ).getAll();

        request.onsuccess =
            ()=>resolve(
                Array.isArray(request.result)
                    ? request.result
                    : []
            );

        request.onerror =
            ()=>reject(request.error);
    });
}

function idbDeleteProject(id){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            resolve();
            return;
        }

        const tx =
            state.db.transaction(
                ['projects','videos'],
                'readwrite'
            );

        tx.objectStore(
            'projects'
        ).delete(id);

        tx.objectStore(
            'videos'
        ).delete(id);

        tx.oncomplete =
            ()=>resolve();

        tx.onerror =
            ()=>reject(tx.error);
    });
}

function idbPutVideo(projectId,blob){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            reject(
                new Error(
                    'IndexedDB unavailable'
                )
            );
            return;
        }

        if(!projectId){
            reject(
                new Error(
                    'projectIdがありません。'
                )
            );
            return;
        }

        const record = {
            projectId,
            blob
        };

        const tx =
            state.db.transaction(
                'videos',
                'readwrite'
            );

        /*
         * keyPath=projectIdなので、
         * projectIdを必ず持つrecordだけをputする。
         */
        tx.objectStore(
            'videos'
        ).put(record);

        tx.oncomplete =
            ()=>resolve();

        tx.onerror =
            ()=>reject(tx.error);
    });
}

function idbGetVideo(projectId){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            resolve(null);
            return;
        }

        const tx =
            state.db.transaction(
                'videos',
                'readonly'
            );

        const request =
            tx.objectStore(
                'videos'
            ).get(projectId);

        request.onsuccess =
            ()=>resolve(
                request.result || null
            );

        request.onerror =
            ()=>reject(request.error);
    });
}

/* =========================================================
 * Save / Export
 * ======================================================= */

async function saveProject(){
    if(!state.project){
        return;
    }

    if(!duration()){
        message(
            '動画を読み込んでから保存してください。'
        );
        return;
    }

    state.project.name =
        $('editorProjectName')
            .value
            .trim() ||
        '名称未設定';

    try{
        await idbPutProject(
            state.project
        );

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

        const result =
            await response.json();

        if(
            !response.ok ||
            !result.ok
        ){
            if(result.limit){
                message(
                    'サーバー保存上限です。ローカル保存は完了しています。',
                    true
                );
            }else{
                throw new Error(
                    result.message ||
                    'サーバー保存に失敗しました。'
                );
            }
        }else{
            state.project.savedAt =
                result.savedAt;

            await idbPutProject(
                state.project
            );
        }

        state.dirty = false;

        $('editorStatus').textContent =
            `保存済み / ${state.project.name}`;

        await renderProjectList();

        message(
            '保存しました。',
            true
        );

    }catch(error){
        console.error(error);

        try{
            await idbPutProject(
                state.project
            );

            state.dirty = false;

            message(
                'ローカル保存しました。サーバー保存は失敗しています。',
                true
            );

        }catch(localError){
            console.error(localError);

            message(
                '保存に失敗しました。'
            );
        }
    }
}

function exportProject(){
    if(!state.project){
        return;
    }

    const data =
        JSON.parse(
            JSON.stringify(state.project)
        );

    data.exportedAt =
        new Date().toISOString();

    const blob =
        new Blob(
            [
                JSON.stringify(
                    data,
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

    a.href = url;

    a.download =
        `${sanitizeFileName(
            state.project.name
        ) || 'video-project'}.json`;

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

function sanitizeFileName(name){
    return String(name)
        .replace(/[\\/:*?"<>|]/g,'_')
        .trim();
}

/* =========================================================
 * Project list
 * ======================================================= */

async function renderProjectList(){
    const root =
        $('projectList');

    root.innerHTML = '';

    let server = [];
    let local = [];

    try{
        const response =
            await fetch(
                '?api=list&_=' +
                Date.now(),
                {
                    cache:'no-store'
                }
            );

        if(response.ok){
            const data =
                await response.json();

            if(data.ok){
                server = data.projects || [];
            }
        }
    }catch(error){
        console.warn(error);
    }

    try{
        local =
            await idbListProjects();
    }catch(error){
        console.warn(error);
    }

    const map = new Map();

    server.forEach(p=>{
        map.set(
            p.projectId,
            {
                ...p,
                storage:'server'
            }
        );
    });

    local.forEach(p=>{
        if(!map.has(p.projectId)){
            map.set(
                p.projectId,
                {
                    ...p,
                    storage:'local'
                }
            );
        }
    });

    const projects =
        [...map.values()].sort(
            (a,b)=>
                String(b.savedAt || '')
                    .localeCompare(
                        String(a.savedAt || '')
                    )
        );

    if(!projects.length){
        root.textContent =
            '保存済み編集はありません。';
        return;
    }

    projects.forEach(p=>{
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
            p.name || '名称未設定';

        const meta =
            document.createElement('div');

        meta.className =
            'project-meta';

        meta.textContent =
            `${p.videoName || '動画未登録'} / ` +
            `${fmt(num(p.videoDuration))} / ` +
            `${p.storage === 'server' ? 'サーバー' : 'ローカル'}`;

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
            async ()=>{
                try{
                    let project = null;

                    if(p.storage === 'server'){
                        const response =
                            await fetch(
                                `?api=load&id=${encodeURIComponent(p.projectId)}&_=${Date.now()}`,
                                {
                                    cache:'no-store'
                                }
                            );

                        const data =
                            await response.json();

                        if(data.ok){
                            project =
                                data.project;
                        }
                    }

                    if(!project){
                        project =
                            await idbGetProject(
                                p.projectId
                            );
                    }

                    if(!project){
                        throw new Error(
                            'プロジェクトデータがありません。'
                        );
                    }

                    await loadProject(
                        project
                    );

                }catch(error){
                    console.error(error);
                    message(
                        error?.message ||
                        'プロジェクトを開けませんでした。'
                    );
                }
            }
        );

        const del =
            document.createElement('button');

        del.textContent =
            '削除';

        del.className =
            'danger';

        del.addEventListener(
            'click',
            async ()=>{
                if(
                    !confirm(
                        'この編集データを削除しますか？'
                    )
                ){
                    return;
                }

                try{
                    await idbDeleteProject(
                        p.projectId
                    );

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

                    await renderProjectList();

                    message(
                        '削除しました。',
                        true
                    );

                }catch(error){
                    console.error(error);
                    message(
                        '削除に失敗しました。'
                    );
                }
            }
        );

        actions.appendChild(load);
        actions.appendChild(del);

        row.appendChild(info);
        row.appendChild(actions);

        root.appendChild(row);
    });
}

/* =========================================================
 * Import
 * ======================================================= */

async function importProjectFile(file){
    if(!file){
        return;
    }

    try{
        const text =
            await file.text();

        const data =
            JSON.parse(text);

        if(
            !data ||
            typeof data !== 'object'
        ){
            throw new Error(
                'プロジェクト形式が不正です。'
            );
        }

        const project =
            normalizeProject(data);

        await loadProject(
            project
        );

        await idbPutProject(
            state.project
        );

        message(
            'プロジェクトを読み込みました。',
            true
        );

    }catch(error){
        console.error(error);

        message(
            error?.message ||
            'プロジェクトを読み込めませんでした。'
        );
    }
}

/* =========================================================
 * Context actions
 * ======================================================= */

function handleContextAction(action){
    hideContextMenu();

    if(action === 'add-comment'){
        addElement('comment');
        return;
    }

    if(action === 'add-box'){
        addElement('box');
        return;
    }

    if(action === 'add-skip'){
        addElement('skip');
        return;
    }

    if(action === 'edit'){
        if(
            state.context?.type === 'connection'
        ){
            const c =
                getConnection(
                    state.context.id
                );

            if(c){
                openConnectionModal(c);
            }
        }else if(
            state.context?.type === 'element'
        ){
            const e =
                getElement(
                    state.context.id
                );

            if(e){
                openElementModal(e);
            }
        }

        return;
    }

    if(action === 'duplicate'){
        duplicateSelected();
        return;
    }

    if(action === 'delete'){
        deleteSelected();
        return;
    }

    if(action === 'connect'){
        connectSelected();
    }
}

/* =========================================================
 * Events
 * ======================================================= */

function initEvents(){

    $('newProject').addEventListener(
        'click',
        newProject
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

            await handleVideoFile(file);
        }
    );

    $('backHome').addEventListener(
        'click',
        async ()=>{
            if(
                state.dirty &&
                !confirm(
                    '未保存の変更があります。戻りますか？'
                )
            ){
                return;
            }

            if(state.videoObjectUrl){
                URL.revokeObjectURL(
                    state.videoObjectUrl
                );

                state.videoObjectUrl = null;
            }

            $('recordedVideo').pause();

            showHome();
            await renderProjectList();
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

    $('editorProjectName').addEventListener(
        'input',
        ()=>{
            if(state.project){
                state.project.name =
                    $('editorProjectName')
                        .value
                        .trim() ||
                    '名称未設定';

                markDirty();
            }
        }
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

            await importProjectFile(file);
        }
    );

    $('videoPlay').addEventListener(
        'click',
        togglePlay
    );

    $('playToggle').addEventListener(
        'click',
        togglePlay
    );

    $('seek').addEventListener(
        'input',
        seekInput
    );

    $('timelineSeekLayer').addEventListener(
        'click',
        seekFromTimeline
    );

    $('timelineZoom').addEventListener(
        'change',
        event=>{
            state.zoom =
                clamp(
                    num(
                        event.target.value,
                        1
                    ),
                    .5,
                    5
                );

            renderTimeline();
        }
    );

    $('timelineFit').addEventListener(
        'click',
        ()=>{
            const d = duration();

            if(!d){
                return;
            }

            const scroll =
                $('timelineScroll');

            const available =
                Math.max(
                    500,
                    scroll.clientWidth
                );

            const target =
                available /
                (d * 70);

            const values =
                [.5,1,2,3,5];

            let closest =
                values[0];

            values.forEach(value=>{
                if(
                    Math.abs(
                        value - target
                    ) <
                    Math.abs(
                        closest - target
                    )
                ){
                    closest = value;
                }
            });

            state.zoom = closest;

            $('timelineZoom').value =
                String(closest);

            renderTimeline();
        }
    );

    $('recordedVideo').addEventListener(
        'timeupdate',
        handleVideoTimeUpdate
    );

    $('recordedVideo').addEventListener(
        'play',
        updatePlayButtons
    );

    $('recordedVideo').addEventListener(
        'pause',
        updatePlayButtons
    );

    $('recordedVideo').addEventListener(
        'ended',
        handleVideoEnded
    );

    $('recordedVideo').addEventListener(
        'loadedmetadata',
        ()=>{
            if(
                state.project &&
                Number.isFinite(
                    $('recordedVideo').duration
                )
            ){
                state.project.videoDuration =
                    $('recordedVideo').duration;

                setEditorLocked(false);

                renderAll();
            }
        }
    );

    $('quickText').addEventListener(
        'click',
        ()=>{
            addElement('comment');
        }
    );

    $('quickBox').addEventListener(
        'click',
        ()=>{
            addElement('box');
        }
    );

    $('quickSkip').addEventListener(
        'click',
        ()=>{
            addElement('skip');
        }
    );

    $('elementType').addEventListener(
        'change',
        updateElementModalVisibility
    );

    $('elementApply').addEventListener(
        'click',
        applyElementModal
    );

    $('elementCancel').addEventListener(
        'click',
        closeElementModal
    );

    $('elementDelete').addEventListener(
        'click',
        ()=>{
            const id =
                state.elementModalTarget;

            closeElementModal();

            if(id){
                state.selectedId = id;
                state.selectedType = 'element';
                deleteSelected();
            }
        }
    );

    $('connectionApply').addEventListener(
        'click',
        applyConnectionModal
    );

    $('connectionCancel').addEventListener(
        'click',
        closeConnectionModal
    );

    $('connectionDelete').addEventListener(
        'click',
        ()=>{
            const id =
                state.connectionModalTarget;

            closeConnectionModal();

            if(id){
                deleteConnection(id);
            }
        }
    );

    document.querySelectorAll(
        '#contextMenu [data-action]'
    ).forEach(button=>{
        button.addEventListener(
            'click',
            ()=>{
                handleContextAction(
                    button.dataset.action
                );
            }
        );
    });

    document.addEventListener(
        'click',
        event=>{
            if(
                !event.target.closest(
                    '#contextMenu'
                )
            ){
                hideContextMenu();
            }
        }
    );

    window.addEventListener(
        'resize',
        ()=>{
            renderConnectors();
            renderTimeline();
        }
    );

    document.addEventListener(
        'keydown',
        event=>{
            if(event.key === 'Escape'){
                hideContextMenu();
                closeElementModal();
                closeConnectionModal();
                return;
            }

            if(
                event.key === 'Delete' &&
                !['INPUT','TEXTAREA','SELECT'].includes(
                    document.activeElement?.tagName
                )
            ){
                deleteSelected();
            }
        }
    );

    $('videoArea').addEventListener(
        'contextmenu',
        event=>{
            /*
             * 要素上のcontextmenuは個別処理済み。
             * 背景右クリックなら、その位置へ要素を追加するメニュー。
             */
            if(
                event.target.closest('.edit-object')
            ){
                return;
            }

            event.preventDefault();

            state.context = {
                type:'stage',
                x:event.clientX,
                y:event.clientY
            };

            showContextMenu(
                event.clientX,
                event.clientY
            );
        }
    );
}

/* =========================================================
 * Context menu stage-position handling
 * ======================================================= */

const originalHandleContextAction =
    handleContextAction;

handleContextAction = function(action){

    if(
        state.context?.type === 'stage' &&
        [
            'add-comment',
            'add-box',
            'add-skip'
        ].includes(action)
    ){
        const rect =
            $('videoStage')
                .getBoundingClientRect();

        const x =
            clamp(
                (
                    state.context.x -
                    rect.left
                ) / rect.width * 100,
                0,
                90
            );

        const y =
            clamp(
                (
                    state.context.y -
                    rect.top
                ) / rect.height * 100,
                0,
                90
            );

        hideContextMenu();

        const type =
            action === 'add-comment'
                ? 'comment'
                : action === 'add-box'
                    ? 'box'
                    : 'skip';

        addElement(
            type,
            x,
            y
        );

        return;
    }

    originalHandleContextAction(action);
};

/* =========================================================
 * Initialisation
 * ======================================================= */

async function init(){

    buildPalette();

    try{
        await openDB();

        $('status').textContent =
            `準備完了 / v${APP_VERSION}`;

    }catch(error){
        console.warn(error);

        $('status').textContent =
            'ローカル保存なし';

        message(
            'IndexedDBを利用できません。サーバー保存は利用できます。'
        );
    }

    initEvents();
    await renderProjectList();

    showHome();
}

init().catch(error=>{
    console.error(error);

    $('status').textContent =
        '初期化エラー';

    message(
        '初期化に失敗しました。ブラウザのコンソールを確認してください。'
    );
});
</script>

</body>
</html>

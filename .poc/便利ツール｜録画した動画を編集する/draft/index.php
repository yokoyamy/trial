<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * 保存:
 *   サーバー : data/projects/*.json
 *   ブラウザ : IndexedDB
 *
 * 動画本体:
 *   原則ブラウザ内で保持
 *   サーバーへ勝手にアップロードしない
 *
 * 書き出し:
 *   編集プロジェクト JSON
 *
 * 時間軸:
 *   動画durationを唯一の基準とし、
 *   目盛り・全トラック・プレイヘッドを同一X軸で描画。
 */

const APP_VERSION = 31;
const MAX_SERVER_PROJECTS = 20;

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
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

function readProjects(): array
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

function projectSummary(array $p): array
{
    return [
        'projectId' => (string)($p['projectId'] ?? ''),
        'name' => (string)($p['name'] ?? '名称未設定'),
        'videoName' => (string)($p['videoName'] ?? ''),
        'videoDuration' => (float)($p['videoDuration'] ?? 0),
        'savedAt' => (string)($p['savedAt'] ?? ''),
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
            'serverCount' => count(readProjects())
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map(
                'projectSummary',
                readProjects()
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
            $name = '名称未設定';
        }

        if (mb_strlen($name) > 120) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクト名は120文字以内にしてください。'
            ], 422);
        }

        $existing = is_file(projectPath($id));

        if (
            !$existing &&
            count(readProjects()) >= MAX_SERVER_PROJECTS
        ) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' =>
                    'サーバー保存上限に達しました。' .
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

        if (
            @file_put_contents(
                projectPath($id),
                $json,
                LOCK_EX
            ) === false
        ) {
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

<script>
window.APP_VERSION = <?=json_encode(APP_VERSION)?>;
</script>

<style>
:root{
    --bg:#101216;
    --panel:#1b1e23;
    --panel2:#252a30;
    --border:#414750;
    --text:#f5f7fa;
    --muted:#9ca5af;
    --blue:#1976d2;
    --red:#ef4444;
    --green:#277a47;
    --orange:#e38b28;
    --yellow:#ffd447;
    --purple:#805ad5;
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
    background:#a73535;
}

button.warning{
    background:#996116;
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

#message.ok{
    background:#287348;
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

#home{
    height:calc(100vh - 48px);
    display:flex;
    justify-content:center;
    align-items:center;
    padding:20px;
    background:#111317;
}

.home-card{
    width:min(780px,95vw);
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

#selectionInfo{
    min-width:190px;
    padding:5px 8px;
    border-radius:5px;
    border:1px solid #444b54;
    background:#15181c;
    color:#cdd4da;
    font-size:11px;
    text-align:center;
}

#selectionInfo.connect-ready{
    border-color:#ffd447;
    color:#ffd447;
}

.editor-main{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}

.video-area{
    flex:1 1 auto;
    min-height:220px;
    max-height:43vh;
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
    flex:none;
    width:min(72vw,900px);
    height:min(35vh,450px);
}

#recordedVideo{
    display:block;
    width:100%;
    height:100%;
    object-fit:contain;
    background:#000;
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
    z-index:20;
}

#objects{
    z-index:30;
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

.connection-line{
    fill:none;
    stroke-linecap:round;
    stroke-width:1.5;
    pointer-events:stroke;
    cursor:pointer;
}

.connection-line.selected{
    stroke-width:3;
    filter:drop-shadow(0 0 4px #ffd447);
}

.connection-arrow{
    fill:currentColor;
}

.timeline{
    flex:0 0 330px;
    min-height:330px;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:7px 12px;
    overflow:auto;
}

.timeline-toolbar{
    height:38px;
    display:flex;
    align-items:center;
    gap:6px;
    position:sticky;
    top:0;
    z-index:100;
    background:#191c20;
}

#playToggle{
    width:42px;
    padding:5px;
    font-size:17px;
}

#currentTime{
    color:#d8dde2;
    font-variant-numeric:tabular-nums;
    min-width:150px;
}

#timelineZoom{
    width:100px;
}

.timeline-scroll{
    position:relative;
    min-width:100%;
    overflow-x:auto;
    overflow-y:visible;
    padding-bottom:20px;
}

.timeline-content{
    position:relative;
    min-width:100%;
}

.timeline-scale{
    position:relative;
    height:34px;
    color:#8f969f;
    font-size:10px;
    border-bottom:1px solid #555;
    user-select:none;
}

.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    white-space:nowrap;
}

.timeline-scale .tick{
    position:absolute;
    bottom:0;
    width:1px;
    height:7px;
    background:#656c74;
}

.timeline-row{
    display:grid;
    grid-template-columns:76px 1fr;
    gap:7px;
    margin-top:17px;
    align-items:start;
    font-size:11px;
    color:#b0b7c0;
}

.timeline-label{
    width:76px;
    white-space:nowrap;
    padding-top:10px;
}

.track{
    position:relative;
    min-width:0;
    height:42px;
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
    min-width:18px;
    border-radius:3px;
    cursor:grab;
    z-index:5;
    touch-action:none;
    top:var(--lane-top,5px);
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
    background:#7050ad;
}

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
    width:13px;
    z-index:10;
    cursor:ew-resize;
}

.track-handle.left{
    left:-6px;
}

.track-handle.right{
    right:-6px;
}

.track-item:hover .track-handle{
    background:#fff8;
}

.track-time,
.track-end-time{
    position:absolute;
    top:-17px;
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

.playhead{
    position:absolute;
    top:0;
    bottom:0;
    width:2px;
    background:#ef4444;
    box-shadow:0 0 7px #ef4444;
    z-index:1000;
    pointer-events:none;
}

.playhead:before{
    content:"";
    position:absolute;
    top:-3px;
    left:-5px;
    width:12px;
    height:12px;
    border-radius:50%;
    background:#ef4444;
}

.timeline-seek{
    position:absolute;
    left:0;
    top:0;
    height:34px;
    width:100%;
    cursor:pointer;
    z-index:3;
}

.connection-status{
    padding:5px 9px;
    border-radius:5px;
    background:#15181c;
    border:1px solid #414750;
    color:#d9dee4;
    font-size:11px;
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

#contextMenu{
    position:fixed;
    z-index:7000;
    display:none;
    width:290px;
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
    width:min(720px,96vw);
    max-height:92vh;
    overflow:auto;
    padding:17px;
    background:#20242a;
    border:1px solid #555b64;
    border-radius:9px;
}

.modal h3{
    margin:0 0 14px;
}

.form-row{
    display:grid;
    grid-template-columns:120px 1fr;
    gap:10px;
    align-items:center;
    margin:9px 0;
}

.form-row label{
    color:#c7cdd3;
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
    height:28px;
    padding:0;
    border:2px solid transparent;
}

.palette button.active{
    border-color:#fff;
}

#editor.locked .video-area:after{
    content:"動画を読み込んでください";
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#0009;
    color:#fff;
    font-size:18px;
    pointer-events:none;
}

@media(max-height:700px){
    .video-area{
        max-height:39vh;
        min-height:180px;
    }

    .timeline{
        flex-basis:310px;
        min-height:310px;
    }

    #videoStage{
        height:min(30vh,390px);
    }
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
        <h2>動画を編集</h2>

        <p>
            先に動画を読み込んでください。
            動画の長さを取得してから編集画面を有効にします。
            動画上で右クリックすると、テキスト・強調枠・スキップを追加できます。
        </p>

        <div class="home-actions">
            <button class="primary" id="openVideo">
                動画を読み込んで編集
            </button>

            <button id="importProject">
                保存データを読み込む
            </button>

            <input
                id="videoFile"
                type="file"
                accept="video/*"
                class="hidden"
            >

            <input
                id="projectFile"
                type="file"
                accept=".json,application/json"
                class="hidden"
            >
        </div>

        <h3>保存済み</h3>
        <div id="projectList"></div>
    </div>
</section>

<section id="editor" class="locked">

    <div class="editor-top">

        <button id="backHome">
            ← 戻る
        </button>

        <input
            id="editorProjectName"
            value="名称未設定"
            aria-label="プロジェクト名"
        >

        <button class="success" id="saveProject">
            保存
        </button>

        <button id="localSave">
            ローカル保存
        </button>

        <button id="localLoad">
            ローカル読込
        </button>

        <button id="exportProject">
            JSON書出
        </button>

        <button id="loadVideoButton">
            動画変更
        </button>

        <input
            id="editorVideoFile"
            type="file"
            accept="video/*"
            class="hidden"
        >

        <div id="selectionInfo">
            1つ選択
        </div>

        <span id="editorStatus">
            動画未読込
        </span>

    </div>

    <div class="editor-main">

        <div class="video-area" id="videoArea">

            <div id="videoStage">

                <video
                    id="recordedVideo"
                    preload="metadata"
                    playsinline
                ></video>

                <svg id="connectors"></svg>

                <div id="objects"></div>

            </div>

        </div>

        <div class="timeline">

            <div class="timeline-toolbar">

                <button id="playToggle" disabled>▶</button>

                <span id="currentTime">
                    00:00.000 / 00:00.000
                </span>

                <button id="timelineZoomOut" disabled>−</button>

                <select id="timelineZoom" disabled>
                    <option value="0.5">0.5x</option>
                    <option value="1" selected>1x</option>
                    <option value="2">2x</option>
                    <option value="3">3x</option>
                    <option value="5">5x</option>
                    <option value="8">8x</option>
                </select>

                <button id="timelineZoomIn" disabled>＋</button>

                <button id="timelineFit" disabled>
                    全体表示
                </button>

                <span
                    id="connectionStatus"
                    class="connection-status"
                >
                    Shift＋クリックで2要素を選択
                </span>

            </div>

            <div
                id="timelineScroll"
                class="timeline-scroll"
            >
                <div
                    id="timelineContent"
                    class="timeline-content"
                >
                    <div
                        id="timelineScale"
                        class="timeline-scale"
                    ></div>

                    <div id="timelineTracks"></div>

                    <div
                        id="timelinePlayhead"
                        class="playhead"
                    ></div>
                </div>
            </div>

        </div>

    </div>

    <div id="editorFooter">
        <span>
            動画上右クリック：追加 /
            要素右クリック：編集・削除 /
            Shift＋クリック：複数選択 /
            2要素選択後右クリック：接続
        </span>
    </div>

</section>

<div id="contextMenu">

    <button data-action="add-comment">
        ＋ テキストを追加
    </button>

    <button data-action="add-box">
        ＋ 強調枠を追加
    </button>

    <button data-action="add-skip">
        ＋ スキップ区間を追加
    </button>

    <div class="context-separator"></div>

    <button data-action="edit">
        ✎ 選択要素を編集
    </button>

    <button data-action="duplicate">
        ⧉ 複製
    </button>

    <button data-action="delete">
        🗑 削除
    </button>

    <button
        id="contextConnect"
        data-action="connect"
        disabled
    >
        🔗 選択した2要素を接続
    </button>

    <button
        id="contextEditConnection"
        data-action="edit-connection"
        disabled
    >
        ✎ 接続線を編集
    </button>

</div>

<div
    id="elementModal"
    class="modal-backdrop"
>
    <div class="modal">

        <h3 id="elementModalTitle">
            要素を編集
        </h3>

        <div
            class="form-row"
            id="elementTextRow"
        >
            <label>テキスト</label>
            <textarea id="elementText"></textarea>
        </div>

        <div class="form-row">
            <label>開始</label>
            <input
                id="elementStart"
                type="number"
                min="0"
                step="0.001"
            >
        </div>

        <div class="form-row">
            <label>終了</label>
            <input
                id="elementEnd"
                type="number"
                min="0"
                step="0.001"
            >
        </div>

        <div class="form-row">
            <label>X位置 %</label>
            <input
                id="elementX"
                type="number"
                min="0"
                max="100"
                step="0.1"
            >
        </div>

        <div class="form-row">
            <label>Y位置 %</label>
            <input
                id="elementY"
                type="number"
                min="0"
                max="100"
                step="0.1"
            >
        </div>

        <div class="form-row">
            <label>幅 %</label>
            <input
                id="elementW"
                type="number"
                min="1"
                max="100"
                step="0.1"
            >
        </div>

        <div class="form-row">
            <label>高さ %</label>
            <input
                id="elementH"
                type="number"
                min="1"
                max="100"
                step="0.1"
            >
        </div>

        <div class="form-row">
            <label>色</label>

            <div class="palette" id="palette">
                <button data-color="#ef4444"></button>
                <button data-color="#f97316"></button>
                <button data-color="#f59e0b"></button>
                <button data-color="#eab308"></button>
                <button data-color="#84cc16"></button>
                <button data-color="#22c55e"></button>
                <button data-color="#06b6d4"></button>
                <button data-color="#3b82f6"></button>
                <button data-color="#6366f1"></button>
                <button data-color="#8b5cf6"></button>
                <button data-color="#ec4899"></button>
                <button data-color="#ffffff"></button>
            </div>
        </div>

        <div class="form-row">
            <label>枠線太さ</label>
            <input
                id="elementBorderWidth"
                type="number"
                min="0"
                max="20"
                step="1"
            >
        </div>

        <div class="form-row">
            <label>角丸</label>
            <input
                id="elementRadius"
                type="number"
                min="0"
                max="100"
                step="1"
            >
        </div>

        <div
            class="form-row"
            id="fontSizeRow"
        >
            <label>文字サイズ</label>
            <input
                id="elementFontSize"
                type="number"
                min="8"
                max="200"
                step="1"
            >
        </div>

        <div
            class="form-row"
            id="fontWeightRow"
        >
            <label>文字太さ</label>

            <select id="elementFontWeight">
                <option value="400">標準</option>
                <option value="500">中</option>
                <option value="600">やや太い</option>
                <option value="700">太字</option>
                <option value="800">極太</option>
            </select>
        </div>

        <div class="modal-actions">
            <button id="modalCancel">
                キャンセル
            </button>

            <button
                class="primary"
                id="modalSave"
            >
                保存
            </button>
        </div>

    </div>
</div>

<div
    id="connectionModal"
    class="modal-backdrop"
>
    <div class="modal">

        <h3>接続線を編集</h3>

        <div class="form-row">
            <label>開始</label>
            <input
                id="connectionStart"
                type="number"
                min="0"
                step="0.001"
            >
        </div>

        <div class="form-row">
            <label>終了</label>
            <input
                id="connectionEnd"
                type="number"
                min="0"
                step="0.001"
            >
        </div>

        <div class="form-row">
            <label>線の色</label>
            <input
                id="connectionColor"
                type="color"
            >
        </div>

        <div class="form-row">
            <label>線の太さ</label>
            <input
                id="connectionWidth"
                type="number"
                min="0.5"
                max="10"
                step="0.5"
            >
        </div>

        <div class="modal-actions">
            <button id="connectionDelete" class="danger">
                接続削除
            </button>

            <button id="connectionCancel">
                キャンセル
            </button>

            <button
                id="connectionSave"
                class="primary"
            >
                保存
            </button>
        </div>

    </div>
</div>

<script>
'use strict';

/* =========================================================
 * Utility
 * ======================================================= */

const $ = id => document.getElementById(id);

const clamp = (v,min,max) =>
    Math.min(max,Math.max(min,v));

const uid = prefix =>
    prefix + '-' +
    Date.now().toString(36) + '-' +
    Math.random().toString(36).slice(2,10);

function fmt(sec){
    sec = Math.max(0,Number(sec)||0);

    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    const ms = Math.floor((sec % 1) * 1000);

    return (
        String(m).padStart(2,'0') +
        ':' +
        String(s).padStart(2,'0') +
        '.' +
        String(ms).padStart(3,'0')
    );
}

function clone(value){
    return JSON.parse(JSON.stringify(value));
}

function message(text,ok=false){
    const el = $('message');

    if(!el){
        return;
    }

    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    clearTimeout(message.timer);

    message.timer = setTimeout(()=>{
        el.style.display = 'none';
    },3200);
}

function apiUrl(name,params={}){
    const q = new URLSearchParams({
        api:name,
        ...params
    });

    return location.pathname + '?' + q.toString();
}

/* =========================================================
 * IndexedDB
 * ======================================================= */

const DB_NAME = 'video-editor-db-v31';
const DB_VERSION = 1;
const STORE = 'projects';

let dbPromise = null;

function openDB(){
    if(dbPromise){
        return dbPromise;
    }

    dbPromise = new Promise((resolve,reject)=>{
        const req = indexedDB.open(DB_NAME,DB_VERSION);

        req.onupgradeneeded = ()=>{
            const db = req.result;

            if(!db.objectStoreNames.contains(STORE)){
                db.createObjectStore(STORE,{
                    keyPath:'id'
                });
            }
        };

        req.onsuccess = ()=>{
            resolve(req.result);
        };

        req.onerror = ()=>{
            reject(req.error);
        };
    });

    return dbPromise;
}

async function localPut(record){
    const db = await openDB();

    return new Promise((resolve,reject)=>{
        const tx = db.transaction(STORE,'readwrite');
        const store = tx.objectStore(STORE);

        store.put({
            id:String(record.id),
            project:record.project,
            videoBlob:record.videoBlob || null,
            savedAt:new Date().toISOString()
        });

        tx.oncomplete = ()=>resolve(true);
        tx.onerror = ()=>reject(tx.error);
    });
}

async function localGet(id){
    const db = await openDB();

    return new Promise((resolve,reject)=>{
        const tx = db.transaction(STORE,'readonly');
        const req = tx.objectStore(STORE).get(String(id));

        req.onsuccess = ()=>{
            resolve(req.result || null);
        };

        req.onerror = ()=>{
            reject(req.error);
        };
    });
}

async function localList(){
    const db = await openDB();

    return new Promise((resolve,reject)=>{
        const tx = db.transaction(STORE,'readonly');
        const req = tx.objectStore(STORE).getAll();

        req.onsuccess = ()=>{
            resolve(req.result || []);
        };

        req.onerror = ()=>{
            reject(req.error);
        };
    });
}

async function localDelete(id){
    const db = await openDB();

    return new Promise((resolve,reject)=>{
        const tx = db.transaction(STORE,'readwrite');

        tx.objectStore(STORE).delete(String(id));

        tx.oncomplete = ()=>resolve(true);
        tx.onerror = ()=>reject(tx.error);
    });
}

/* =========================================================
 * State
 * ======================================================= */

const state = {
    project:null,
    editorReady:false,
    videoUrl:null,
    videoBlob:null,

    selectedId:null,
    selectedType:null,

    multiSelected:[],

    context:null,

    elementDrag:null,
    timelineDrag:null,

    modalElementId:null,
    modalConnectionId:null,

    zoom:1,
    dirty:false,

    localProjectId:null
};

/* =========================================================
 * Project model
 * ======================================================= */

function emptyProject(){
    return {
        version:window.APP_VERSION,
        projectId:uid('project'),
        name:'名称未設定',
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

function duration(){
    return Number(
        state.project?.videoDuration ||
        $('recordedVideo')?.duration ||
        0
    );
}

function currentTime(){
    return Number(
        $('recordedVideo')?.currentTime || 0
    );
}

function getElement(id){
    return state.project?.elements.find(
        e=>e.id===id
    ) || null;
}

function getConnection(id){
    return state.project?.connections.find(
        c=>c.id===id
    ) || null;
}

function resetSelection(){
    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];
}

/* =========================================================
 * Home / Editor
 * ======================================================= */

function showHome(){
    $('editor').style.display = 'none';
    $('home').style.display = 'flex';

    const video = $('recordedVideo');

    if(video){
        video.pause();
        video.removeAttribute('src');
        video.load();
    }

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
        state.videoUrl = null;
    }

    state.videoBlob = null;
    state.editorReady = false;

    renderProjectList();
}

function showEditor(){
    $('home').style.display = 'none';
    $('editor').style.display = 'flex';
}

function setLocked(locked){
    $('editor').classList.toggle(
        'locked',
        locked
    );

    [
        'playToggle',
        'timelineZoomOut',
        'timelineZoom',
        'timelineZoomIn',
        'timelineFit'
    ].forEach(id=>{
        const el = $(id);

        if(el){
            el.disabled = locked;
        }
    });
}

function requireEditor(){
    if(
        !state.project ||
        !state.editorReady ||
        duration() <= 0
    ){
        message(
            '動画の読み込みが完了してから編集してください。'
        );

        return false;
    }

    return true;
}

function startNewProject(){
    state.project = emptyProject();

    state.editorReady = false;
    state.videoBlob = null;
    state.dirty = false;

    resetSelection();

    $('editorProjectName').value =
        state.project.name;

    $('editorStatus').textContent =
        '動画未読込';

    showEditor();
    setLocked(true);
    renderAll();
}

/* =========================================================
 * Video
 * ======================================================= */

async function loadVideoFile(file){
    if(!file){
        return;
    }

    if(!file.type.startsWith('video/')){
        message('動画ファイルを選択してください。');
        return;
    }

    const video = $('recordedVideo');

    state.editorReady = false;
    setLocked(true);

    $('editorStatus').textContent =
        '動画を読み込み中…';

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
        state.videoUrl = null;
    }

    state.videoBlob = file;
    state.videoUrl = URL.createObjectURL(file);

    video.pause();
    video.src = state.videoUrl;
    video.load();

    try{
        await new Promise((resolve,reject)=>{
            const ok = ()=>{
                cleanup();
                resolve();
            };

            const ng = ()=>{
                cleanup();
                reject(
                    new Error(
                        '動画のメタデータを読み込めませんでした。'
                    )
                );
            };

            const cleanup = ()=>{
                video.removeEventListener(
                    'loadedmetadata',
                    ok
                );

                video.removeEventListener(
                    'error',
                    ng
                );
            };

            video.addEventListener(
                'loadedmetadata',
                ok,
                {once:true}
            );

            video.addEventListener(
                'error',
                ng,
                {once:true}
            );
        });

        if(!Number.isFinite(video.duration) || video.duration <= 0){
            throw new Error(
                '動画の長さを取得できませんでした。'
            );
        }

        state.project.videoName = file.name;
        state.project.videoDuration = video.duration;
        state.project.videoType = file.type;
        state.project.videoSize = file.size;

        state.editorReady = true;

        setLocked(false);

        $('editorStatus').textContent =
            '動画読込完了';

        message(
            `動画を読み込みました。長さ ${fmt(video.duration)}`,
            true
        );

        renderAll();

    }catch(error){
        console.error(error);

        state.editorReady = false;
        setLocked(true);

        $('editorStatus').textContent =
            '動画読込エラー';

        message(
            error.message ||
            '動画を読み込めませんでした。'
        );
    }
}

async function replaceVideo(file){
    if(!state.project){
        return;
    }

    await loadVideoFile(file);
}

/* =========================================================
 * Elements
 * ======================================================= */

function makeElement(type,start=currentTime()){
    const d = duration();

    const s = clamp(
        Number(start)||0,
        0,
        Math.max(0,d-.05)
    );

    const len = Math.min(
        5,
        Math.max(.05,d-s)
    );

    return {
        id:uid('element'),
        type,

        start:s,
        end:Math.min(
            d,
            s + len
        ),

        x:10,
        y:10,

        w:type === 'box' ? 35 : 30,
        h:type === 'box' ? 25 : 12,

        color:
            type === 'skip'
                ? '#f59e0b'
                : type === 'box'
                    ? '#ef4444'
                    : '#3b82f6',

        borderWidth:
            type === 'box'
                ? 2
                : 1,

        borderStyle:
            type === 'skip'
                ? 'dashed'
                : 'solid',

        radius:5,
        opacity:1,

        text:
            type === 'comment'
                ? 'テキスト'
                : '',

        fontSize:22,
        fontWeight:600
    };
}

function addElement(type){
    if(!requireEditor()){
        return;
    }

    const e = makeElement(
        type,
        currentTime()
    );

    state.project.elements.push(e);

    selectElement(
        e.id,
        false
    );

    state.dirty = true;

    renderAll();

    openElementModal(e);
}

function duplicateSelected(){
    if(state.selectedType !== 'element'){
        message(
            '複製する要素を選択してください。'
        );
        return;
    }

    const source =
        getElement(state.selectedId);

    if(!source){
        return;
    }

    const copy = clone(source);

    copy.id = uid('element');

    copy.x = clamp(
        copy.x + 3,
        0,
        100-copy.w
    );

    copy.y = clamp(
        copy.y + 3,
        0,
        100-copy.h
    );

    copy.start = clamp(
        copy.start + .25,
        0,
        Math.max(0,duration()-.05)
    );

    copy.end = clamp(
        copy.end + .25,
        copy.start+.05,
        duration()
    );

    state.project.elements.push(copy);

    selectElement(
        copy.id,
        false
    );

    state.dirty = true;

    renderAll();
}

function deleteSelected(){
    if(state.selectedType === 'connection'){
        deleteConnection(
            state.selectedId
        );
        return;
    }

    if(state.selectedType !== 'element'){
        return;
    }

    const id = state.selectedId;

    state.project.elements =
        state.project.elements.filter(
            e=>e.id!==id
        );

    state.project.connections =
        state.project.connections.filter(
            c=>c.from!==id && c.to!==id
        );

    resetSelection();

    state.dirty = true;

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
        const i =
            state.multiSelected.indexOf(id);

        if(i >= 0){
            state.multiSelected.splice(i,1);
        }else{
            state.multiSelected.push(id);
        }

        if(
            state.multiSelected.length > 2
        ){
            state.multiSelected =
                state.multiSelected.slice(-2);
        }

    }else{
        state.multiSelected = [id];
    }

    state.selectedId = id;
    state.selectedType = 'element';

    renderAll();
}

function selectConnection(id){
    if(!getConnection(id)){
        return;
    }

    state.selectedId = id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    renderAll();
}

function selectionText(){
    if(state.multiSelected.length === 2){
        return '2要素選択中・接続可能';
    }

    if(state.multiSelected.length === 1){
        return '1要素選択中';
    }

    return '選択なし';
}

function updateSelectionUI(){
    const info = $('selectionInfo');

    info.textContent =
        selectionText();

    info.classList.toggle(
        'connect-ready',
        state.multiSelected.length === 2
    );

    const status =
        $('connectionStatus');

    if(state.multiSelected.length === 2){
        status.textContent =
            '2要素選択中 → 右クリック → 接続';

    }else if(state.multiSelected.length === 1){
        status.textContent =
            'Shift＋クリックでもう1要素を選択';

    }else{
        status.textContent =
            'Shift＋クリックで2要素を選択';
    }

    $('contextConnect').disabled =
        state.multiSelected.length !== 2;
}

/* =========================================================
 * Video rendering
 * ======================================================= */

function renderObjects(){
    const container = $('objects');

    if(!container){
        return;
    }

    container.innerHTML = '';

    if(!state.editorReady){
        return;
    }

    const t = currentTime();

    for(const e of state.project.elements){

        if(
            t < e.start ||
            t > e.end
        ){
            continue;
        }

        const el =
            document.createElement('div');

        el.className =
            'edit-object ' +
            e.type;

        if(
            e.id === state.selectedId &&
            state.selectedType === 'element'
        ){
            el.classList.add('selected');
        }

        if(
            state.multiSelected.includes(e.id)
        ){
            el.classList.add('multi-selected');
        }

        el.dataset.id = e.id;

        el.style.left = `${e.x}%`;
        el.style.top = `${e.y}%`;
        el.style.width = `${e.w}%`;
        el.style.height = `${e.h}%`;

        el.style.border =
            `${e.borderWidth}px ${e.borderStyle} ${e.color}`;

        el.style.borderRadius =
            `${e.radius}px`;

        el.style.opacity =
            String(e.opacity);

        if(e.type === 'comment'){
            el.style.fontSize =
                `${e.fontSize}px`;

            el.style.fontWeight =
                String(e.fontWeight);

            el.style.color =
                e.color;

            el.textContent =
                e.text || 'テキスト';
        }

        if(e.type === 'skip'){
            const label =
                document.createElement('span');

            label.className =
                'skip-label';

            label.textContent =
                'SKIP';

            el.appendChild(label);
        }

        if(
            e.id === state.selectedId &&
            state.selectedType === 'element' &&
            e.type !== 'skip'
        ){
            const handle =
                document.createElement('div');

            handle.className =
                'resize-handle';

            handle.addEventListener(
                'pointerdown',
                event=>{
                    beginElementResize(
                        event,
                        e
                    );
                }
            );

            el.appendChild(handle);
        }

        el.addEventListener(
            'pointerdown',
            event=>{
                if(
                    event.target.closest(
                        '.resize-handle'
                    )
                ){
                    return;
                }

                if(event.button !== 0){
                    return;
                }

                selectElement(
                    e.id,
                    event.shiftKey
                );

                beginElementMove(
                    event,
                    e
                );

                event.stopPropagation();
            }
        );

        el.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(!state.multiSelected.includes(e.id)){
                    selectElement(
                        e.id,
                        event.shiftKey
                    );
                }

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

        el.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
                openElementModal(e);
            }
        );

        container.appendChild(el);
    }
}

/* =========================================================
 * Element dragging
 * ======================================================= */

function beginElementMove(event,e){
    if(!requireEditor()){
        return;
    }

    const stage =
        $('videoStage').getBoundingClientRect();

    state.elementDrag = {
        mode:'move',
        id:e.id,

        startX:event.clientX,
        startY:event.clientY,

        originalX:e.x,
        originalY:e.y,

        stageW:Math.max(1,stage.width),
        stageH:Math.max(1,stage.height)
    };

    event.preventDefault();
}

function beginElementResize(event,e){
    if(!requireEditor()){
        return;
    }

    const stage =
        $('videoStage').getBoundingClientRect();

    state.elementDrag = {
        mode:'resize',
        id:e.id,

        startX:event.clientX,
        startY:event.clientY,

        originalW:e.w,
        originalH:e.h,

        stageW:Math.max(1,stage.width),
        stageH:Math.max(1,stage.height)
    };

    event.preventDefault();
    event.stopPropagation();
}

function moveElement(event){
    const drag =
        state.elementDrag;

    if(!drag){
        return;
    }

    const e = getElement(drag.id);

    if(!e){
        state.elementDrag = null;
        return;
    }

    const dx =
        (event.clientX-drag.startX) /
        drag.stageW * 100;

    const dy =
        (event.clientY-drag.startY) /
        drag.stageH * 100;

    if(drag.mode === 'move'){
        e.x = clamp(
            drag.originalX + dx,
            0,
            100-e.w
        );

        e.y = clamp(
            drag.originalY + dy,
            0,
            100-e.h
        );
    }

    if(drag.mode === 'resize'){
        e.w = clamp(
            drag.originalW + dx,
            1,
            100-e.x
        );

        e.h = clamp(
            drag.originalH + dy,
            1,
            100-e.y
        );
    }

    state.dirty = true;

    renderObjects();
    renderConnectors();
}

function endElementDrag(){
    state.elementDrag = null;
}

/* =========================================================
 * Connections
 * ======================================================= */

function connectSelected(){
    if(state.multiSelected.length !== 2){
        message(
            'Shiftキーを押しながら2つの要素を選択してください。'
        );
        return;
    }

    const from =
        getElement(state.multiSelected[0]);

    const to =
        getElement(state.multiSelected[1]);

    if(!from || !to){
        message(
            '接続対象が見つかりません。'
        );
        return;
    }

    const exists =
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

    if(exists){
        message(
            'この2要素はすでに接続されています。'
        );
        return;
    }

    const start =
        Math.min(
            from.start,
            to.start
        );

    const end =
        Math.max(
            from.end,
            to.end
        );

    const connection = {
        id:uid('connection'),
        from:from.id,
        to:to.id,

        start,
        end,

        color:from.color || '#888',
        width:1.5
    };

    state.project.connections.push(
        connection
    );

    state.selectedId =
        connection.id;

    state.selectedType =
        'connection';

    state.multiSelected = [];

    state.dirty = true;

    renderAll();

    message(
        '接続線を作成しました。',
        true
    );
}

function deleteConnection(id){
    state.project.connections =
        state.project.connections.filter(
            c=>c.id!==id
        );

    resetSelection();

    state.dirty = true;

    renderAll();
}

function connectionPoint(e){
    return {
        x:e.x + e.w / 2,
        y:e.y + e.h / 2
    };
}

function renderConnectors(){
    const svg =
        $('connectors');

    if(!svg){
        return;
    }

    svg.innerHTML = '';

    if(!state.editorReady){
        return;
    }

    const stage =
        $('videoStage');

    const width =
        stage.clientWidth;

    const height =
        stage.clientHeight;

    svg.setAttribute(
        'viewBox',
        `0 0 ${width} ${height}`
    );

    for(const c of state.project.connections){

        const from =
            getElement(c.from);

        const to =
            getElement(c.to);

        if(!from || !to){
            continue;
        }

        const p1 =
            connectionPoint(from);

        const p2 =
            connectionPoint(to);

        const x1 =
            p1.x / 100 * width;

        const y1 =
            p1.y / 100 * height;

        const x2 =
            p2.x / 100 * width;

        const y2 =
            p2.y / 100 * height;

        const line =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'line'
            );

        line.setAttribute(
            'x1',
            String(x1)
        );

        line.setAttribute(
            'y1',
            String(y1)
        );

        line.setAttribute(
            'x2',
            String(x2)
        );

        line.setAttribute(
            'y2',
            String(y2)
        );

        line.setAttribute(
            'stroke',
            c.color || from.color || '#777'
        );

        line.setAttribute(
            'stroke-width',
            String(c.width || 1.5)
        );

        line.classList.add(
            'connection-line'
        );

        if(
            c.id === state.selectedId &&
            state.selectedType === 'connection'
        ){
            line.classList.add('selected');
        }

        line.addEventListener(
            'click',
            event=>{
                event.stopPropagation();
                selectConnection(c.id);
            }
        );

        line.addEventListener(
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

        svg.appendChild(line);
    }
}

/* =========================================================
 * Timeline geometry
 * ======================================================= */

/*
 * ここが重要。
 *
 * widthは必ず
 *
 *     動画duration × pxPerSecond
 *
 * だけで決める。
 *
 * プレイヘッド位置や個々の要素幅から
 * timeline widthを決めない。
 */

function timelineWidth(){
    const d = duration();

    if(!d){
        return 800;
    }

    const pxPerSecond =
        80 * state.zoom;

    return Math.max(
        800,
        d * pxPerSecond
    );
}

function timeToX(time,width){
    const d = duration();

    if(!d){
        return 0;
    }

    return clamp(
        time / d,
        0,
        1
    ) * width;
}

function xToTime(x,width){
    const d = duration();

    if(!d || width <= 0){
        return 0;
    }

    return clamp(
        x / width,
        0,
        1
    ) * d;
}

/* =========================================================
 * Timeline lane allocation
 * ======================================================= */

function allocateLanes(items){
    const lanes = [];

    const sorted =
        [...items].sort(
            (a,b)=>
                a.start-b.start ||
                a.end-b.end
        );

    const result = [];

    for(const item of sorted){

        let lane = 0;

        while(
            lanes[lane] !== undefined &&
            lanes[lane] > item.start
        ){
            lane++;
        }

        lanes[lane] = item.end;

        result.push({
            item,
            lane
        });
    }

    return result;
}

/* =========================================================
 * Timeline
 * ======================================================= */

function renderTimeline(){
    const scale =
        $('timelineScale');

    const tracks =
        $('timelineTracks');

    const content =
        $('timelineContent');

    if(!scale || !tracks || !content){
        return;
    }

    scale.innerHTML = '';
    tracks.innerHTML = '';

    const d = duration();

    if(!d){
        content.style.width = '800px';
        return;
    }

    const width =
        timelineWidth();

    /*
     * scaleと全trackに同一width。
     */
    content.style.width =
        `${width}px`;

    const step =
        state.zoom >= 5
            ? 1
            : state.zoom >= 3
                ? 2
                : state.zoom >= 2
                    ? 5
                    : 10;

    for(
        let t=0;
        t<=d+.00001;
        t+=step
    ){
        const actual =
            Math.min(t,d);

        const x =
            actual / d * 100;

        const tick =
            document.createElement('i');

        tick.className = 'tick';
        tick.style.left =
            `${x}%`;

        scale.appendChild(tick);

        const label =
            document.createElement('span');

        label.textContent =
            fmt(actual);

        label.style.left =
            `${x}%`;

        scale.appendChild(label);
    }

    const rows = [
        {
            label:'テキスト',
            type:'comment'
        },
        {
            label:'強調枠',
            type:'box'
        },
        {
            label:'スキップ',
            type:'skip'
        },
        {
            label:'接続',
            type:'connection'
        }
    ];

    for(const row of rows){
        renderTrackRow(
            row,
            width,
            d
        );
    }

    updatePlayhead();
}

function renderTrackRow(row,width,d){
    const rowEl =
        document.createElement('div');

    rowEl.className =
        'timeline-row';

    const label =
        document.createElement('div');

    label.className =
        'timeline-label';

    label.textContent =
        row.label;

    const track =
        document.createElement('div');

    track.className =
        'track';

    track.style.width =
        `${width}px`;

    /*
     * 空白部分クリックでseek。
     */
    track.addEventListener(
        'click',
        event=>{
            if(
                event.target.closest(
                    '.track-item,.track-handle'
                )
            ){
                return;
            }

            const rect =
                track.getBoundingClientRect();

            seek(
                xToTime(
                    event.clientX -
                    rect.left,
                    width
                )
            );
        }
    );

    let items = [];

    if(row.type === 'connection'){
        items =
            state.project.connections;
    }else{
        items =
            state.project.elements.filter(
                e=>e.type===row.type
            );
    }

    const lanes =
        allocateLanes(items);

    /*
     * 同じ種類・同じ時間帯が複数あっても
     * 重ならないレーンにする。
     */
    const laneCount =
        Math.max(
            1,
            ...lanes.map(x=>x.lane+1)
        );

    track.style.height =
        `${Math.max(42,laneCount*36+6)}px`;

    for(const info of lanes){

        const item =
            info.item;

        const bar =
            document.createElement('div');

        bar.className =
            'track-item ' +
            row.type;

        bar.dataset.id =
            item.id;

        bar.style.left =
            `${item.start / d * 100}%`;

        bar.style.width =
            `${Math.max(
                .15,
                (item.end-item.start)/d*100
            )}%`;

        bar.style.setProperty(
            '--lane-top',
            `${5+info.lane*36}px`
        );

        const selected =
            row.type === 'connection'
                ? (
                    state.selectedType ===
                    'connection' &&
                    state.selectedId === item.id
                )
                : state.multiSelected.includes(
                    item.id
                );

        if(
            row.type === 'connection'
                ? selected
                : state.selectedId === item.id
        ){
            bar.classList.add('selected');
        }

        if(
            row.type !== 'connection' &&
            state.multiSelected.includes(item.id)
        ){
            bar.classList.add('multi');
        }

        const startLabel =
            document.createElement('span');

        startLabel.className =
            'track-time';

        startLabel.textContent =
            fmt(item.start);

        const endLabel =
            document.createElement('span');

        endLabel.className =
            'track-end-time';

        endLabel.textContent =
            fmt(item.end);

        const name =
            document.createElement('span');

        name.className =
            'track-name';

        name.textContent =
            row.type === 'comment'
                ? (
                    item.text ||
                    'テキスト'
                )
                : row.type === 'connection'
                    ? '接続線'
                    : row.label;

        bar.appendChild(startLabel);
        bar.appendChild(endLabel);
        bar.appendChild(name);

        if(row.type !== 'connection'){

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
                        'start',
                        track
                    );
                }
            );

            right.addEventListener(
                'pointerdown',
                event=>{
                    beginTimelineResize(
                        event,
                        item,
                        'end',
                        track
                    );
                }
            );

            bar.appendChild(left);
            bar.appendChild(right);
        }

        bar.addEventListener(
            'click',
            event=>{
                if(
                    event.target.closest(
                        '.track-handle'
                    )
                ){
                    return;
                }

                if(row.type === 'connection'){
                    selectConnection(item.id);
                }else{
                    selectElement(
                        item.id,
                        event.shiftKey
                    );
                }

                seek(item.start);
            }
        );

        bar.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();

                if(row.type === 'connection'){
                    openConnectionModal(item);
                }else{
                    openElementModal(item);
                }
            }
        );

        bar.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(row.type === 'connection'){
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

        if(row.type !== 'connection'){
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

                    if(event.button !== 0){
                        return;
                    }

                    selectElement(
                        item.id,
                        event.shiftKey
                    );

                    state.timelineDrag = {
                        item,
                        track,
                        mode:'move',
                        startX:event.clientX,
                        originalStart:item.start,
                        originalEnd:item.end
                    };

                    event.preventDefault();
                }
            );
        }

        track.appendChild(bar);
    }

    rowEl.appendChild(label);
    rowEl.appendChild(track);

    $('timelineTracks').appendChild(rowEl);
}

/* =========================================================
 * Timeline dragging
 * ======================================================= */

function beginTimelineResize(
    event,
    item,
    side,
    track
){
    state.timelineDrag = {
        item,
        track,
        mode:side,
        startX:event.clientX,
        originalStart:item.start,
        originalEnd:item.end
    };

    event.preventDefault();
    event.stopPropagation();
}

function moveTimeline(event){
    const drag =
        state.timelineDrag;

    if(!drag){
        return;
    }

    const item =
        drag.item;

    const rect =
        drag.track.getBoundingClientRect();

    const width =
        rect.width;

    const dx =
        event.clientX -
        drag.startX;

    const delta =
        dx / width * duration();

    if(drag.mode === 'move'){

        const len =
            drag.originalEnd -
            drag.originalStart;

        let start =
            drag.originalStart +
            delta;

        start = clamp(
            start,
            0,
            duration()-len
        );

        item.start = start;
        item.end = start+len;

    }else if(drag.mode === 'start'){

        item.start = clamp(
            drag.originalStart+delta,
            0,
            item.end-.05
        );

    }else if(drag.mode === 'end'){

        item.end = clamp(
            drag.originalEnd+delta,
            item.start+.05,
            duration()
        );
    }

    /*
     * 要素の開始終了が変わったら、
     * 接続線も自然に追随する。
     */
    state.dirty = true;

    renderTimeline();
    renderObjects();
    renderConnectors();
}

function endTimelineDrag(){
    state.timelineDrag = null;
}

/* =========================================================
 * Playhead / Seek
 * ======================================================= */

function seek(time){
    if(!requireEditor()){
        return;
    }

    const video =
        $('recordedVideo');

    video.currentTime =
        clamp(
            Number(time)||0,
            0,
            duration()
        );

    updatePlayhead();
    renderObjects();
    renderConnectors();
}

function updatePlayhead(){
    const d = duration();

    if(!d){
        return;
    }

    const width =
        timelineWidth();

    const x =
        currentTime()/d*width;

    const playhead =
        $('timelinePlayhead');

    if(playhead){
        playhead.style.left =
            `${x}px`;
    }

    $('currentTime').textContent =
        `${fmt(currentTime())} / ${fmt(d)}`;
}

function checkSkip(){
    if(!state.editorReady){
        return;
    }

    const video =
        $('recordedVideo');

    if(video.paused){
        return;
    }

    const t =
        video.currentTime;

    const skip =
        state.project.elements.find(
            e =>
                e.type === 'skip' &&
                t >= e.start &&
                t < e.end-.01
        );

    if(skip){
        video.currentTime =
            Math.min(
                duration(),
                skip.end
            );
    }
}

/* =========================================================
 * Element modal
 * ======================================================= */

function openElementModal(e){
    if(!e){
        return;
    }

    state.modalElementId =
        e.id;

    $('elementModalTitle').textContent =
        e.type === 'comment'
            ? 'テキストを編集'
            : e.type === 'box'
                ? '強調枠を編集'
                : 'スキップ区間を編集';

    $('elementTextRow').style.display =
        e.type === 'comment'
            ? 'grid'
            : 'none';

    $('fontSizeRow').style.display =
        e.type === 'comment'
            ? 'grid'
            : 'none';

    $('fontWeightRow').style.display =
        e.type === 'comment'
            ? 'grid'
            : 'none';

    $('elementText').value =
        e.text || '';

    $('elementStart').value =
        e.start.toFixed(3);

    $('elementEnd').value =
        e.end.toFixed(3);

    $('elementX').value =
        e.x.toFixed(1);

    $('elementY').value =
        e.y.toFixed(1);

    $('elementW').value =
        e.w.toFixed(1);

    $('elementH').value =
        e.h.toFixed(1);

    $('elementBorderWidth').value =
        e.borderWidth;

    $('elementRadius').value =
        e.radius;

    $('elementFontSize').value =
        e.fontSize;

    $('elementFontWeight').value =
        e.fontWeight;

    setPalette(e.color);

    $('elementModal').style.display =
        'flex';
}

function setPalette(color){
    document
        .querySelectorAll(
            '#palette button'
        )
        .forEach(btn=>{
            btn.classList.toggle(
                'active',
                btn.dataset.color === color
            );

            btn.style.background =
                btn.dataset.color;
        });
}

function closeElementModal(){
    state.modalElementId = null;

    $('elementModal').style.display =
        'none';
}

function saveElementModal(){
    const e =
        getElement(
            state.modalElementId
        );

    if(!e){
        closeElementModal();
        return;
    }

    const d = duration();

    let start =
        Number($('elementStart').value);

    let end =
        Number($('elementEnd').value);

    if(!Number.isFinite(start)){
        message('開始時間が不正です。');
        return;
    }

    if(!Number.isFinite(end)){
        message('終了時間が不正です。');
        return;
    }

    start = clamp(start,0,d);
    end = clamp(end,0,d);

    if(end <= start){
        message(
            '終了時間は開始時間より後にしてください。'
        );
        return;
    }

    e.start = start;
    e.end = end;

    e.x = clamp(
        Number($('elementX').value)||0,
        0,
        100
    );

    e.y = clamp(
        Number($('elementY').value)||0,
        0,
        100
    );

    e.w = clamp(
        Number($('elementW').value)||1,
        1,
        100-e.x
    );

    e.h = clamp(
        Number($('elementH').value)||1,
        1,
        100-e.y
    );

    e.borderWidth =
        clamp(
            Number($('elementBorderWidth').value)||0,
            0,
            20
        );

    e.radius =
        clamp(
            Number($('elementRadius').value)||0,
            0,
            100
        );

    e.fontSize =
        clamp(
            Number($('elementFontSize').value)||22,
            8,
            200
        );

    e.fontWeight =
        Number($('elementFontWeight').value)||400;

    e.text =
        $('elementText').value;

    const activeColor =
        document.querySelector(
            '#palette button.active'
        );

    if(activeColor){
        e.color =
            activeColor.dataset.color;
    }

    state.dirty = true;

    closeElementModal();

    renderAll();
}

/* =========================================================
 * Connection modal
 * ======================================================= */

function openConnectionModal(c){
    if(!c){
        return;
    }

    state.modalConnectionId =
        c.id;

    $('connectionStart').value =
        c.start.toFixed(3);

    $('connectionEnd').value =
        c.end.toFixed(3);

    $('connectionColor').value =
        c.color || '#777777';

    $('connectionWidth').value =
        c.width || 1.5;

    $('connectionModal').style.display =
        'flex';
}

function closeConnectionModal(){
    state.modalConnectionId = null;

    $('connectionModal').style.display =
        'none';
}

function saveConnectionModal(){
    const c =
        getConnection(
            state.modalConnectionId
        );

    if(!c){
        closeConnectionModal();
        return;
    }

    let start =
        Number($('connectionStart').value);

    let end =
        Number($('connectionEnd').value);

    start =
        clamp(start,0,duration());

    end =
        clamp(end,0,duration());

    if(end <= start){
        message(
            '終了時間は開始時間より後にしてください。'
        );
        return;
    }

    c.start = start;
    c.end = end;

    c.color =
        $('connectionColor').value ||
        '#777777';

    c.width =
        clamp(
            Number(
                $('connectionWidth').value
            ) || 1.5,
            .5,
            10
        );

    state.dirty = true;

    closeConnectionModal();

    renderAll();
}

/* =========================================================
 * Context menu
 * ======================================================= */

function showContextMenu(x,y){
    const menu =
        $('contextMenu');

    const context =
        state.context;

    $('contextEditConnection').disabled =
        context?.type !== 'connection';

    $('contextConnect').disabled =
        state.multiSelected.length !== 2;

    menu.style.display =
        'block';

    const w = 290;
    const h = 350;

    menu.style.left =
        `${Math.min(
            x,
            window.innerWidth-w-8
        )}px`;

    menu.style.top =
        `${Math.min(
            y,
            window.innerHeight-h-8
        )}px`;
}

function hideContextMenu(){
    $('contextMenu').style.display =
        'none';
}

/* =========================================================
 * Save / Load
 * ======================================================= */

function cleanProjectForSave(){
    const p = clone(state.project);

    p.version =
        window.APP_VERSION;

    p.name =
        $('editorProjectName').value.trim() ||
        '名称未設定';

    return p;
}

async function saveServer(){
    if(!state.project){
        return;
    }

    const project =
        cleanProjectForSave();

    try{
        const response =
            await fetch(
                apiUrl('save'),
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/json'
                    },
                    body:JSON.stringify(project)
                }
            );

        const data =
            await response.json();

        if(!response.ok || !data.ok){
            if(data.limit){
                await saveLocal();

                message(
                    'サーバー上限に達したため、ローカルへ保存しました。',
                    true
                );

                return;
            }

            throw new Error(
                data.message ||
                'サーバー保存に失敗しました。'
            );
        }

        state.project.projectId =
            data.projectId;

        state.project.savedAt =
            data.savedAt;

        state.project.version =
            data.version;

        state.dirty = false;

        await renderProjectList();

        message(
            'サーバーへ保存しました。',
            true
        );

    }catch(error){
        console.error(error);

        try{
            await saveLocal();

            message(
                'サーバー保存に失敗したため、ローカルへ保存しました。',
                true
            );

        }catch(localError){
            console.error(localError);

            message(
                '保存に失敗しました。\n' +
                error.message
            );
        }
    }
}

async function saveLocal(){
    if(!state.project){
        return;
    }

    const project =
        cleanProjectForSave();

    await localPut({
        id:project.projectId,
        project,
        videoBlob:state.videoBlob
    });

    state.project =
        project;

    state.dirty = false;

    await renderProjectList();

    message(
        'このブラウザに保存しました。',
        true
    );
}

async function loadLocal(id){
    const record =
        await localGet(id);

    if(!record){
        throw new Error(
            'ローカル保存データがありません。'
        );
    }

    state.project =
        record.project;

    state.videoBlob =
        record.videoBlob || null;

    state.editorReady = false;

    resetSelection();

    $('editorProjectName').value =
        state.project.name ||
        '名称未設定';

    showEditor();
    setLocked(true);

    if(state.videoBlob){
        await loadVideoFile(
            state.videoBlob
        );
    }else{
        $('editorStatus').textContent =
            '動画未読込';

        message(
            '編集データは読み込みました。動画ファイルを選択してください。'
        );
    }

    renderAll();
}

async function loadServer(id){
    const response =
        await fetch(
            apiUrl('load',{id})
        );

    const data =
        await response.json();

    if(!response.ok || !data.ok){
        throw new Error(
            data.message ||
            '読み込みに失敗しました。'
        );
    }

    state.project =
        data.project;

    state.videoBlob = null;
    state.editorReady = false;

    resetSelection();

    $('editorProjectName').value =
        state.project.name ||
        '名称未設定';

    showEditor();
    setLocked(true);

    /*
     * サーバーには動画本体を保存していないため、
     * 編集データだけ読み込んだ状態にする。
     */
    $('editorStatus').textContent =
        '動画未読込';

    message(
        '編集データを読み込みました。動画ファイルを選択してください。',
        true
    );

    renderAll();
}

/* =========================================================
 * Project list
 * ======================================================= */

async function renderProjectList(){
    const container =
        $('projectList');

    container.innerHTML =
        '<div style="color:#888;font-size:12px">読み込み中…</div>';

    let serverProjects = [];
    let localProjects = [];

    try{
        const response =
            await fetch(
                apiUrl('list')
            );

        const data =
            await response.json();

        if(data.ok){
            serverProjects =
                data.projects || [];
        }
    }catch(error){
        console.warn(error);
    }

    try{
        localProjects =
            await localList();
    }catch(error){
        console.warn(error);
    }

    container.innerHTML = '';

    if(
        !serverProjects.length &&
        !localProjects.length
    ){
        container.innerHTML =
            '<div style="color:#888;font-size:12px">保存済みプロジェクトはありません。</div>';

        return;
    }

    for(const p of serverProjects){
        const row =
            document.createElement('div');

        row.className =
            'project';

        const info =
            document.createElement('div');

        info.className =
            'project-info';

        info.innerHTML =
            `<div class="project-name">${escapeHtml(p.name)}</div>` +
            `<div class="project-meta">サーバー / ${escapeHtml(p.videoName || '動画未設定')} / ${fmt(p.videoDuration || 0)}</div>`;

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
            ()=>{
                loadServer(p.projectId)
                    .catch(error=>{
                        console.error(error);
                        message(error.message);
                    });
            }
        );

        const del =
            document.createElement('button');

        del.className =
            'danger';

        del.textContent =
            '削除';

        del.addEventListener(
            'click',
            async ()=>{
                if(!confirm(
                    `「${p.name}」を削除しますか？`
                )){
                    return;
                }

                try{
                    const response =
                        await fetch(
                            apiUrl('delete'),
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

                    const data =
                        await response.json();

                    if(!data.ok){
                        throw new Error(
                            data.message ||
                            '削除に失敗しました。'
                        );
                    }

                    renderProjectList();

                }catch(error){
                    message(error.message);
                }
            }
        );

        actions.appendChild(load);
        actions.appendChild(del);

        row.appendChild(info);
        row.appendChild(actions);

        container.appendChild(row);
    }

    for(const record of localProjects){
        const p =
            record.project;

        const row =
            document.createElement('div');

        row.className =
            'project';

        const info =
            document.createElement('div');

        info.className =
            'project-info';

        info.innerHTML =
            `<div class="project-name">${escapeHtml(p.name || '名称未設定')}</div>` +
            `<div class="project-meta">このブラウザ / ${escapeHtml(p.videoName || '動画未設定')} / ${fmt(p.videoDuration || 0)}</div>`;

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
            ()=>{
                loadLocal(record.id)
                    .catch(error=>{
                        console.error(error);
                        message(error.message);
                    });
            }
        );

        const del =
            document.createElement('button');

        del.className =
            'danger';

        del.textContent =
            '削除';

        del.addEventListener(
            'click',
            async ()=>{
                if(!confirm(
                    `「${p.name}」をローカルから削除しますか？`
                )){
                    return;
                }

                try{
                    await localDelete(record.id);
                    renderProjectList();
                }catch(error){
                    message(error.message);
                }
            }
        );

        actions.appendChild(load);
        actions.appendChild(del);

        row.appendChild(info);
        row.appendChild(actions);

        container.appendChild(row);
    }
}

function escapeHtml(value){
    return String(value)
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

/* =========================================================
 * JSON import/export
 * ======================================================= */

function exportProject(){
    if(!state.project){
        return;
    }

    const project =
        cleanProjectForSave();

    const blob =
        new Blob(
            [
                JSON.stringify(
                    project,
                    null,
                    2
                )
            ],
            {
                type:'application/json'
            }
        );

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href = url;

    a.download =
        (
            project.name ||
            'video-project'
        ) +
        '.json';

    a.click();

    URL.revokeObjectURL(url);
}

async function importProjectFile(file){
    const text =
        await file.text();

    const project =
        JSON.parse(text);

    validateProject(project);

    state.project =
        project;

    state.videoBlob = null;
    state.editorReady = false;

    resetSelection();

    $('editorProjectName').value =
        project.name ||
        '名称未設定';

    showEditor();
    setLocked(true);

    $('editorStatus').textContent =
        '動画未読込';

    renderAll();

    message(
        '編集データを読み込みました。動画を選択してください。',
        true
    );
}

function validateProject(project){
    if(!project || typeof project !== 'object'){
        throw new Error(
            'プロジェクトデータが不正です。'
        );
    }

    if(!Array.isArray(project.elements)){
        project.elements = [];
    }

    if(!Array.isArray(project.connections)){
        project.connections = [];
    }

    project.elements =
        project.elements.filter(
            e =>
                e &&
                typeof e.id === 'string' &&
                typeof e.type === 'string'
        );

    project.connections =
        project.connections.filter(
            c =>
                c &&
                typeof c.id === 'string' &&
                typeof c.from === 'string' &&
                typeof c.to === 'string'
        );
}

/* =========================================================
 * Global events
 * ======================================================= */

window.addEventListener(
    'pointermove',
    event=>{
        if(state.elementDrag){
            moveElement(event);
        }

        if(state.timelineDrag){
            moveTimeline(event);
        }
    }
);

window.addEventListener(
    'pointerup',
    ()=>{
        endElementDrag();
        endTimelineDrag();
    }
);

$('openVideo').addEventListener(
    'click',
    ()=>{
        startNewProject();
        $('videoFile').click();
    }
);

$('videoFile').addEventListener(
    'change',
    event=>{
        const file =
            event.target.files?.[0];

        if(file){
            /*
             * 動画読み込み完了後に編集可能になる。
             */
            loadVideoFile(file);
        }

        event.target.value = '';
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
    event=>{
        const file =
            event.target.files?.[0];

        if(file){
            importProjectFile(file)
                .catch(error=>{
                    console.error(error);
                    message(
                        error.message ||
                        '読み込みに失敗しました。'
                    );
                });
        }

        event.target.value = '';
    }
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

$('saveProject').addEventListener(
    'click',
    ()=>{
        saveServer();
    }
);

$('localSave').addEventListener(
    'click',
    ()=>{
        saveLocal().catch(error=>{
            console.error(error);
            message(
                'ローカル保存に失敗しました。\n' +
                error.message
            );
        });
    }
);

$('localLoad').addEventListener(
    'click',
    async ()=>{
        try{
            const list =
                await localList();

            if(!list.length){
                message(
                    'ローカル保存データがありません。'
                );
                return;
            }

            const names =
                list
                    .map(
                        (x,i)=>
                            `${i+1}: ${x.project?.name || '名称未設定'}`
                    )
                    .join('\n');

            const answer =
                prompt(
                    '読み込む番号を入力してください。\n\n' +
                    names
                );

            if(answer === null){
                return;
            }

            const index =
                Number(answer)-1;

            if(
                !Number.isInteger(index) ||
                !list[index]
            ){
                message(
                    '番号が不正です。'
                );
                return;
            }

            await loadLocal(
                list[index].id
            );

        }catch(error){
            console.error(error);
            message(error.message);
        }
    }
);

$('exportProject').addEventListener(
    'click',
    exportProject
);

$('loadVideoButton').addEventListener(
    'click',
    ()=>{
        $('editorVideoFile').click();
    }
);

$('editorVideoFile').addEventListener(
    'change',
    event=>{
        const file =
            event.target.files?.[0];

        if(file){
            replaceVideo(file);
        }

        event.target.value = '';
    }
);

/* =========================================================
 * Video events
 * ======================================================= */

$('recordedVideo').addEventListener(
    'timeupdate',
    ()=>{
        updatePlayhead();
        renderObjects();
        renderConnectors();
        checkSkip();
    }
);

$('recordedVideo').addEventListener(
    'play',
    ()=>{
        $('playToggle').textContent =
            'Ⅱ';
    }
);

$('recordedVideo').addEventListener(
    'pause',
    ()=>{
        $('playToggle').textContent =
            '▶';
    }
);

$('recordedVideo').addEventListener(
    'ended',
    ()=>{
        $('playToggle').textContent =
            '▶';

        updatePlayhead();
    }
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
        }

        renderAll();
    }
);

$('playToggle').addEventListener(
    'click',
    ()=>{
        if(!requireEditor()){
            return;
        }

        const video =
            $('recordedVideo');

        if(video.paused){
            video.play().catch(error=>{
                console.error(error);
                message(
                    '動画を再生できませんでした。'
                );
            });
        }else{
            video.pause();
        }
    }
);

/* =========================================================
 * Timeline zoom
 * ======================================================= */

function changeZoom(dir){
    const values =
        [.5,1,2,3,5,8];

    const current =
        values.indexOf(state.zoom);

    const index =
        clamp(
            current+dir,
            0,
            values.length-1
        );

    state.zoom =
        values[index];

    $('timelineZoom').value =
        String(state.zoom);

    renderTimeline();
}

$('timelineZoom').addEventListener(
    'change',
    event=>{
        state.zoom =
            Number(event.target.value)||1;

        renderTimeline();
    }
);

$('timelineZoomOut').addEventListener(
    'click',
    ()=>{
        changeZoom(-1);
    }
);

$('timelineZoomIn').addEventListener(
    'click',
    ()=>{
        changeZoom(1);
    }
);

$('timelineFit').addEventListener(
    'click',
    ()=>{
        if(!duration()){
            return;
        }

        const scroll =
            $('timelineScroll');

        const available =
            Math.max(
                700,
                scroll.clientWidth-100
            );

        const target =
            available /
            (duration()*80);

        const values =
            [.5,1,2,3,5,8];

        state.zoom =
            values.reduce(
                (best,value)=>
                    Math.abs(value-target) <
                    Math.abs(best-target)
                        ? value
                        : best
            );

        $('timelineZoom').value =
            String(state.zoom);

        renderTimeline();
    }
);

$('timelineScale').addEventListener(
    'click',
    event=>{
        if(!requireEditor()){
            return;
        }

        const rect =
            $('timelineScale').getBoundingClientRect();

        const width =
            timelineWidth();

        seek(
            xToTime(
                event.clientX -
                rect.left,
                width
            )
        );
    }
);

/* =========================================================
 * Video area right click
 * ======================================================= */

$('videoArea').addEventListener(
    'contextmenu',
    event=>{
        if(
            event.target.closest(
                '.edit-object,.connection-line'
            )
        ){
            return;
        }

        event.preventDefault();

        if(!requireEditor()){
            return;
        }

        state.context = {
            type:'empty'
        };

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

/* =========================================================
 * Context menu
 * ======================================================= */

$('contextMenu').addEventListener(
    'click',
    event=>{
        const button =
            event.target.closest(
                '[data-action]'
            );

        if(!button){
            return;
        }

        const action =
            button.dataset.action;

        const context =
            state.context;

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
                context?.type === 'element'
            ){
                openElementModal(
                    getElement(context.id)
                );
            }else if(
                state.selectedType === 'element'
            ){
                openElementModal(
                    getElement(
                        state.selectedId
                    )
                );
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
            return;
        }

        if(action === 'edit-connection'){
            const c =
                context?.type === 'connection'
                    ? getConnection(context.id)
                    : getConnection(
                        state.selectedId
                    );

            openConnectionModal(c);
        }
    }
);

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

/* =========================================================
 * Element modal events
 * ======================================================= */

$('modalCancel').addEventListener(
    'click',
    closeElementModal
);

$('modalSave').addEventListener(
    'click',
    saveElementModal
);

$('elementModal').addEventListener(
    'click',
    event=>{
        if(
            event.target ===
            $('elementModal')
        ){
            closeElementModal();
        }
    }
);

document
    .querySelectorAll(
        '#palette button'
    )
    .forEach(button=>{
        button.style.background =
            button.dataset.color;

        button.addEventListener(
            'click',
            ()=>{
                setPalette(
                    button.dataset.color
                );
            }
        );
    });

/* =========================================================
 * Connection modal events
 * ======================================================= */

$('connectionCancel').addEventListener(
    'click',
    closeConnectionModal
);

$('connectionSave').addEventListener(
    'click',
    saveConnectionModal
);

$('connectionDelete').addEventListener(
    'click',
    ()=>{
        const id =
            state.modalConnectionId;

        closeConnectionModal();

        if(id){
            deleteConnection(id);
        }
    }
);

$('connectionModal').addEventListener(
    'click',
    event=>{
        if(
            event.target ===
            $('connectionModal')
        ){
            closeConnectionModal();
        }
    }
);

/* =========================================================
 * Keyboard
 * ======================================================= */

document.addEventListener(
    'keydown',
    event=>{
        if(
            event.key === 'Delete' &&
            !$('elementModal').style.display &&
            !$('connectionModal').style.display
        ){
            deleteSelected();
        }

        if(
            event.key === 'Escape'
        ){
            hideContextMenu();
            closeElementModal();
            closeConnectionModal();
        }
    }
);

/* =========================================================
 * Rendering
 * ======================================================= */

function renderAll(){
    renderObjects();
    renderConnectors();
    renderTimeline();
    updatePlayhead();
    updateSelectionUI();
}

/* =========================================================
 * Initialisation
 * ======================================================= */

(async function init(){

    $('status').textContent =
        `v${window.APP_VERSION}`;

    $('editor').style.display =
        'none';

    $('home').style.display =
        'flex';

    setLocked(true);

    try{
        await renderProjectList();
    }catch(error){
        console.error(error);
    }

    renderAll();

})();
</script>

</body>
</html>

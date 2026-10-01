<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * サーバー保存:
 *   ./data/projects/*.json
 *
 * 動画本体:
 *   ブラウザ IndexedDB
 *
 * プロジェクト書き出し:
 *   JSON
 *
 * MP4再エンコード:
 *   このファイルでは行わない。
 */

const APP_VERSION = 11;
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
 * PHP API
 * ======================================================= */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    if ($api === 'status') {
        jsonResponse([
            'ok' => true,
            'version' => APP_VERSION,
            'serverWritable' => ensureProjectDir(),
            'serverLimit' => MAX_SERVER_PROJECTS,
            'serverCount' => count(readServerProjects())
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map('projectSummary', readServerProjects()),
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
                'message' => 'data/projects に書き込めません。'
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

        $name = trim((string)($payload['name'] ?? ''));

        if ($name === '') {
            jsonResponse(['ok' => false, 'message' => 'プロジェクト名を入力してください。'], 422);
        }

        if (mb_strlen($name) > 120) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクト名は120文字以内にしてください。'], 422);
        }

        $existing = is_file(projectPath($id));
        $projects = readServerProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' => 'サーバー保存上限に達しました。ローカル保存を使用してください。'
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
            jsonResponse(['ok' => false, 'message' => 'JSON生成に失敗しました。'], 500);
        }

        if (@file_put_contents(projectPath($id), $json, LOCK_EX) === false) {
            jsonResponse(['ok' => false, 'message' => '編集データを保存できませんでした。'], 500);
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
            jsonResponse(['ok' => false, 'message' => '削除できませんでした。'], 500);
        }

        jsonResponse(['ok' => true]);
    }

    jsonResponse(['ok' => false, 'message' => 'Unknown API'], 404);
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
*{box-sizing:border-box}
html,body{
    width:100%;
    height:100%;
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
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
button.danger{background:var(--red)}
input,select,textarea{
    width:100%;
    color:#fff;
    background:#252a30;
    border:1px solid #555c65;
    border-radius:5px;
    padding:7px;
}
textarea{min-height:90px;resize:vertical}
input[type=color]{height:38px;padding:3px}
.hidden{display:none!important}

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
#message.ok{background:#287348}

header{
    height:48px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 14px;
    background:#1b1e22;
    border-bottom:1px solid #353a41;
}
header h1{margin:0;font-size:15px}
#status{color:#b6bec7;font-size:12px}

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
.home-card p{color:var(--muted);font-size:13px;line-height:1.6}
.home-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px}
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
.project-meta{color:#929aa4;font-size:11px;margin-top:3px}
.project-actions{display:flex;gap:5px}

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
#editorProjectName{width:220px;font-weight:600}
#editorStatus{color:#aeb6bf;font-size:12px;margin-left:auto}

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
    position:relative;
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
.edit-object.box{background:transparent}
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

.connector{
    fill:none;
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
    filter:drop-shadow(0 0 4px #ffd447);
}

#timeline{
    height:260px;
    flex-shrink:0;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:7px 12px;
    overflow:auto;
}
.timeline-toolbar{
    height:34px;
    display:flex;
    align-items:center;
    gap:6px;
}
#playToggle{
    width:42px;
    padding:5px;
}
#timelineZoom{width:110px}
#currentTime{
    color:#d8dde2;
    font-variant-numeric:tabular-nums;
    min-width:110px;
}
.timeline-scroll{
    position:relative;
    min-width:100%;
}
.timeline-content{
    position:relative;
}
.timeline-scale{
    position:relative;
    height:23px;
    color:#8f969f;
    font-size:10px;
    border-bottom:1px solid #444;
}
.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    white-space:nowrap;
}
.playhead{
    position:absolute;
    top:0;
    bottom:0;
    width:2px;
    background:#ff3b30;
    box-shadow:0 0 5px #ff3b30;
    z-index:100;
    pointer-events:none;
}
.playhead:before{
    content:"";
    position:absolute;
    top:-2px;
    left:-5px;
    width:12px;
    height:12px;
    border-radius:50%;
    background:#ff3b30;
}
.timeline-row{
    display:grid;
    grid-template-columns:70px 1fr;
    gap:7px;
    margin-top:12px;
    align-items:center;
    font-size:11px;
    color:#b0b7c0;
}
.track{
    position:relative;
    height:34px;
    background:#292d33;
    border-radius:4px;
    border:1px solid #3c4249;
    overflow:visible;
}
.track-item{
    position:absolute;
    top:5px;
    height:24px;
    min-width:9px;
    border-radius:3px;
    cursor:grab;
}
.track-item:active{cursor:grabbing}
.track-item.comment{background:#42a5f5}
.track-item.box{background:#ef5350}
.track-item.skip{background:#e38b28}
.track-item.connection{background:#8e68d6}
.track-item.selected{
    outline:2px solid #fff;
    outline-offset:1px;
}
.track-handle{
    position:absolute;
    top:-3px;
    bottom:-3px;
    width:11px;
    z-index:5;
    cursor:ew-resize;
}
.track-handle.left{left:-5px}
.track-handle.right{right:-5px}
.track-item:hover .track-handle{background:#fff5}
.track-time,.track-end-time{
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
    left:6px;
    right:6px;
    top:4px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:#fff;
    font-size:9px;
    pointer-events:none;
}

#editorFooter{
    min-height:42px;
    display:flex;
    justify-content:center;
    align-items:center;
    flex-wrap:wrap;
    gap:7px;
    padding:5px 10px;
    background:#1b1e22;
    border-top:1px solid #383d44;
}
.connection-status{
    padding:5px 9px;
    border-radius:5px;
    background:#15181c;
    border:1px solid #414750;
    color:#d9dee4;
    font-size:11px;
}

#contextMenu{
    position:fixed;
    z-index:7000;
    display:none;
    width:260px;
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
#contextMenu button:hover{background:#3c424a}
.context-separator{
    height:1px;
    margin:5px 0;
    background:#464c54;
}
#contextConnect[disabled]{display:none}

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
}
.modal h3{margin:0 0 14px}
.form-row{
    display:grid;
    grid-template-columns:120px 1fr;
    gap:10px;
    align-items:center;
    margin:9px 0;
}
.form-row label{color:#c7cdd3;font-size:12px}
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
.palette button.active{border-color:#fff}

#editor.locked .video-area:after{
    content:"動画を読み込んでください";
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#0008;
    color:#fff;
    font-size:18px;
    pointer-events:none;
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
            動画を読み込んでから、動画画面上で直接編集できます。
            要素の追加・移動・サイズ変更・書式変更・削除、
            タイムライン上の開始終了時間変更、要素間接続、スキップ区間を利用できます。
        </p>

        <div class="home-actions">
            <button class="primary" id="newProject">新しい編集を開始</button>
            <button id="openVideo">動画を読み込む</button>
            <button id="importProject">プロジェクトを読み込む</button>
            <input id="videoFile" type="file" accept="video/*" class="hidden">
            <input id="projectFile" type="file" accept=".json,application/json" class="hidden">
        </div>

        <h3>保存済み</h3>
        <div id="projectList"></div>
    </div>
</section>

<section id="editor" class="locked">

    <div class="editor-top">
        <button id="backHome">← 戻る</button>
        <input id="editorProjectName" value="名称未設定">
        <button class="success" id="saveProject">保存</button>
        <button id="exportProject">書き出し</button>
        <button id="loadVideoButton">動画変更</button>
        <input id="editorVideoFile" type="file" accept="video/*" class="hidden">
        <span id="editorStatus">動画未読込</span>
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

        <div id="timeline">

            <div class="timeline-toolbar">
                <button id="playToggle">▶</button>
                <span id="currentTime">00:00.000 / 00:00.000</span>

                <button id="timelineZoomOut">−</button>

                <select id="timelineZoom">
                    <option value="0.5">0.5×</option>
                    <option value="1" selected>1×</option>
                    <option value="2">2×</option>
                    <option value="3">3×</option>
                    <option value="5">5×</option>
                </select>

                <button id="timelineZoomIn">＋</button>
            </div>

            <div class="timeline-scroll">
                <div id="timelineContent" class="timeline-content">
                    <div id="timelineScale" class="timeline-scale"></div>
                    <div id="timelineTracks"></div>
                    <div id="playhead" class="playhead"></div>
                </div>
            </div>

        </div>

    </div>

    <div id="editorFooter">
        <span id="connectionStatus" class="connection-status">
            Shift+クリックで2要素を選択 → 右クリックで接続
        </span>
    </div>

</section>

<div id="contextMenu">

    <button data-action="add-comment">＋ テキスト</button>
    <button data-action="add-box">＋ 強調枠</button>
    <button data-action="add-skip">＋ スキップ</button>

    <div class="context-separator"></div>

    <button data-action="edit">編集</button>
    <button data-action="duplicate">複製</button>
    <button data-action="delete">削除</button>

    <div class="context-separator"></div>

    <button id="contextConnect" data-action="connect">
        🔗 選択した2要素を接続
    </button>

</div>

<div id="elementModal" class="modal-backdrop">
    <div class="modal">

        <h3 id="modalTitle">要素を編集</h3>

        <div class="form-row">
            <label>種類</label>
            <div id="modalType"></div>
        </div>

        <div class="form-row" id="textRow">
            <label>テキスト</label>
            <textarea id="elementText"></textarea>
        </div>

        <div class="form-row">
            <label>開始</label>
            <input id="elementStart" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>終了</label>
            <input id="elementEnd" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>枠線色</label>
            <div>
                <div id="palette" class="palette"></div>
                <input id="elementColor" type="color">
            </div>
        </div>

        <div class="form-row">
            <label>枠線太さ</label>
            <input id="elementBorderWidth" type="number" min="0" max="20" step="0.5">
        </div>

        <div class="form-row" id="fontSizeRow">
            <label>文字サイズ</label>
            <input id="elementFontSize" type="number" min="8" max="200" step="1">
        </div>

        <div class="form-row" id="fontWeightRow">
            <label>文字太さ</label>
            <select id="elementFontWeight">
                <option value="400">通常</option>
                <option value="600">太字</option>
                <option value="700">極太</option>
            </select>
        </div>

        <div class="modal-actions">
            <button id="modalCancel">キャンセル</button>
            <button class="primary" id="modalSave">保存</button>
        </div>

    </div>
</div>

<script>
'use strict';

/* =========================================================
 * 基本
 * ======================================================= */

const $ = id => document.getElementById(id);

const COLORS = [
    '#ffffff',
    '#000000',
    '#ff3b30',
    '#ff9500',
    '#ffcc00',
    '#34c759',
    '#00c7be',
    '#007aff',
    '#5856d6',
    '#af52de',
    '#ff2d55',
    '#8e8e93'
];

const DB_NAME = 'video-direct-editor';
const DB_VERSION = 1;
const STORE = 'projects';

const state = {
    project:null,
    selectedId:null,
    selectedType:null,
    multiSelected:[],
    context:null,
    drag:null,
    timelineDrag:null,
    skipLock:false,
    zoom:1,
    videoObjectUrl:null,
    db:null,
    dirty:false
};

function uid(prefix='id'){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,10);
}

function clamp(value,min,max){
    return Math.max(min,Math.min(max,value));
}

function duration(){
    const video = $('recordedVideo');
    return Number.isFinite(video.duration) ? video.duration : 0;
}

function currentTime(){
    return $('recordedVideo').currentTime || 0;
}

function fmt(sec){
    sec = Math.max(0,Number(sec)||0);

    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    const ms = Math.floor((sec % 1) * 1000);

    return String(m).padStart(2,'0') + ':' +
        String(s).padStart(2,'0') + '.' +
        String(ms).padStart(3,'0');
}

function markDirty(){
    state.dirty = true;
    $('editorStatus').textContent =
        '変更あり';
}

let messageTimer = null;

function message(text,ok=false){
    const el = $('message');

    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    clearTimeout(messageTimer);

    messageTimer = setTimeout(()=>{
        el.style.display = 'none';
    },3000);
}

/* =========================================================
 * IndexedDB
 * ======================================================= */

function openDB(){
    return new Promise((resolve,reject)=>{
        const request = indexedDB.open(DB_NAME,DB_VERSION);

        request.onupgradeneeded = ()=>{
            const db = request.result;

            if(!db.objectStoreNames.contains(STORE)){
                db.createObjectStore(STORE,{
                    keyPath:'projectId'
                });
            }
        };

        request.onsuccess = ()=>{
            state.db = request.result;
            resolve(state.db);
        };

        request.onerror = ()=>{
            reject(request.error);
        };
    });
}

function idbPut(project){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            reject(new Error('IndexedDB unavailable'));
            return;
        }

        const tx = state.db.transaction(STORE,'readwrite');
        tx.objectStore(STORE).put(project);

        tx.oncomplete = resolve;
        tx.onerror = ()=>reject(tx.error);
    });
}

function idbGetAll(){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(STORE,'readonly');
        const req = tx.objectStore(STORE).getAll();

        req.onsuccess = ()=>resolve(req.result || []);
        req.onerror = ()=>reject(req.error);
    });
}

function idbGet(id){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(STORE,'readonly');
        const req = tx.objectStore(STORE).get(id);

        req.onsuccess = ()=>resolve(req.result || null);
        req.onerror = ()=>reject(req.error);
    });
}

function idbDelete(id){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(STORE,'readwrite');

        tx.objectStore(STORE).delete(id);

        tx.oncomplete = resolve;
        tx.onerror = ()=>reject(tx.error);
    });
}

/* =========================================================
 * Project
 * ======================================================= */

function newProject(){
    state.project = {
        version:11,
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

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];
    state.dirty = false;

    $('editorProjectName').value = state.project.name;

    showEditor();
    setEditorLocked(true);
    renderAll();
}

function showEditor(){
    $('home').style.display = 'none';
    $('editor').style.display = 'flex';
}

function showHome(){
    $('editor').style.display = 'none';
    $('home').style.display = 'flex';

    if(state.videoObjectUrl){
        URL.revokeObjectURL(state.videoObjectUrl);
        state.videoObjectUrl = null;
    }

    $('recordedVideo').removeAttribute('src');
    $('recordedVideo').load();

    renderProjectList();
}

function setEditorLocked(locked){
    $('editor').classList.toggle('locked',locked);
}

function normalizeElement(e){
    const d = duration() || state.project?.videoDuration || 0;

    e.start = clamp(Number(e.start)||0,0,d);

    e.end = clamp(
        Number(e.end)||Math.min(d,e.start+5),
        Math.min(d,e.start+0.05),
        d
    );

    e.x = clamp(Number(e.x)||10,0,99);
    e.y = clamp(Number(e.y)||10,0,99);
    e.w = clamp(Number(e.w)||30,1,100-e.x);
    e.h = clamp(Number(e.h)||15,1,100-e.y);
}

function makeElement(type,time){
    const d = duration();

    const start = clamp(
        Number.isFinite(time) ? time : 0,
        0,
        d
    );

    const end = clamp(
        start + Math.min(5,Math.max(.05,d-start)),
        start+.05,
        d || start+.05
    );

    return {
        id:uid('element'),
        type,
        text:
            type === 'comment'
                ? 'テキスト'
                : '',
        start,
        end,
        x:10,
        y:10,
        w:type === 'box' ? 35 : 30,
        h:type === 'box' ? 25 : 15,
        color:
            type === 'skip'
                ? '#ff9500'
                : '#ffffff',
        borderWidth:2,
        borderStyle:
            type === 'skip'
                ? 'dashed'
                : 'solid',
        fontSize:24,
        fontWeight:600,
        opacity:1,
        radius:4
    };
}

/* =========================================================
 * Video
 * ======================================================= */

async function handleVideo(file){
    if(!file || !file.type.startsWith('video/')){
        message('動画ファイルを選択してください。');
        return;
    }

    const video = $('recordedVideo');

    setEditorLocked(true);

    if(state.videoObjectUrl){
        URL.revokeObjectURL(state.videoObjectUrl);
    }

    state.videoObjectUrl = URL.createObjectURL(file);

    video.src = state.videoObjectUrl;
    video.load();

    $('editorStatus').textContent =
        '動画を読み込み中…';

    await new Promise((resolve,reject)=>{
        const loaded = ()=>{
            video.removeEventListener('loadedmetadata',loaded);
            video.removeEventListener('error',failed);
            resolve();
        };

        const failed = ()=>{
            video.removeEventListener('loadedmetadata',loaded);
            video.removeEventListener('error',failed);
            reject(new Error('動画を読み込めませんでした'));
        };

        video.addEventListener('loadedmetadata',loaded);
        video.addEventListener('error',failed);
    }).catch(err=>{
        message(err.message);
        return null;
    });

    if(!Number.isFinite(video.duration) || video.duration <= 0){
        message('動画の長さを取得できませんでした。');
        return;
    }

    state.project.videoName = file.name;
    state.project.videoType = file.type;
    state.project.videoSize = file.size;
    state.project.videoDuration = video.duration;

    $('editorProjectName').value =
        state.project.name;

    setEditorLocked(false);

    $('editorStatus').textContent =
        `${file.name} / ${fmt(video.duration)}`;

    state.dirty = false;

    renderAll();

    message('動画の読み込みが完了しました。編集できます。',true);
}

/* =========================================================
 * Elements
 * ======================================================= */

function addElement(type){
    if(!state.project || duration() <= 0){
        message('先に動画を読み込んでください。');
        return;
    }

    const e = makeElement(type,currentTime());

    state.project.elements.push(e);

    state.selectedId = e.id;
    state.selectedType = 'element';
    state.multiSelected = [];

    markDirty();
    hideContextMenu();
    renderAll();

    if(type === 'comment' || type === 'box' || type === 'skip'){
        openElementModal(e);
    }
}

function selectElement(id){
    state.selectedId = id;
    state.selectedType = 'element';
    state.multiSelected = [id];
    renderAll();
}

function toggleMultiSelection(id){
    const index = state.multiSelected.indexOf(id);

    if(index >= 0){
        state.multiSelected.splice(index,1);
    }else{
        if(state.multiSelected.length >= 2){
            state.multiSelected.shift();
        }

        state.multiSelected.push(id);
    }

    state.selectedId =
        state.multiSelected[state.multiSelected.length-1] || null;

    state.selectedType = 'element';

    renderAll();

    if(state.multiSelected.length === 2){
        $('connectionStatus').textContent =
            '2要素を選択中。右クリック →「選択した2要素を接続」';
    }else{
        $('connectionStatus').textContent =
            'Shift+クリックで2要素を選択 → 右クリックで接続';
    }
}

function getElement(id){
    return state.project?.elements.find(e=>e.id===id) || null;
}

function deleteSelected(){
    if(!state.project) return;

    if(state.selectedType === 'connection'){
        state.project.connections =
            state.project.connections.filter(
                c=>c.id!==state.selectedId
            );
    }else{
        const id = state.selectedId;

        state.project.elements =
            state.project.elements.filter(e=>e.id!==id);

        state.project.connections =
            state.project.connections.filter(
                c=>c.from!==id && c.to!==id
            );
    }

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];

    markDirty();
    hideContextMenu();
    renderAll();
}

function duplicateSelected(){
    const e = getElement(state.selectedId);

    if(!e) return;

    const copy = structuredClone(e);

    copy.id = uid('element');
    copy.x = clamp(copy.x+3,0,100-copy.w);
    copy.y = clamp(copy.y+3,0,100-copy.h);

    state.project.elements.push(copy);

    selectElement(copy.id);
    markDirty();
    hideContextMenu();
}

function moveElementPointer(event){
    if(!state.drag || !state.project) return;

    const e = getElement(state.drag.id);

    if(!e){
        state.drag = null;
        return;
    }

    const dx = event.clientX - state.drag.startX;
    const dy = event.clientY - state.drag.startY;

    if(state.drag.mode === 'move'){
        e.x = clamp(
            state.drag.originalX +
            dx/state.drag.stageW*100,
            0,
            100-e.w
        );

        e.y = clamp(
            state.drag.originalY +
            dy/state.drag.stageH*100,
            0,
            100-e.h
        );
    }else{
        e.w = clamp(
            state.drag.originalW +
            dx/state.drag.stageW*100,
            1,
            100-e.x
        );

        e.h = clamp(
            state.drag.originalH +
            dy/state.drag.stageH*100,
            1,
            100-e.y
        );
    }

    markDirty();
    renderObjects();
    renderConnectors();
}

function beginElementPointer(event){
    const object = event.target.closest('.edit-object');

    if(!object || !state.project || duration() <= 0){
        return;
    }

    const e = getElement(object.dataset.id);

    if(!e) return;

    if(event.target.closest('.resize-handle')){
        selectElement(e.id);

        const rect = $('videoStage').getBoundingClientRect();

        state.drag = {
            mode:'resize',
            id:e.id,
            startX:event.clientX,
            startY:event.clientY,
            originalW:e.w,
            originalH:e.h,
            stageW:Math.max(1,rect.width),
            stageH:Math.max(1,rect.height)
        };

        event.preventDefault();
        return;
    }

    if(event.shiftKey){
        toggleMultiSelection(e.id);
        event.preventDefault();
        return;
    }

    selectElement(e.id);

    const rect = $('videoStage').getBoundingClientRect();

    state.drag = {
        mode:'move',
        id:e.id,
        startX:event.clientX,
        startY:event.clientY,
        originalX:e.x,
        originalY:e.y,
        stageW:Math.max(1,rect.width),
        stageH:Math.max(1,rect.height)
    };

    event.preventDefault();
}

function endElementPointer(){
    state.drag = null;
}

/* =========================================================
 * Connections
 * ======================================================= */

function connectSelected(){
    if(state.multiSelected.length !== 2){
        message('Shiftキーを押しながら2つの要素を選択してください。');
        return;
    }

    const [fromId,toId] = state.multiSelected;

    if(fromId === toId){
        message('同じ要素同士は接続できません。');
        return;
    }

    const from = getElement(fromId);
    const to = getElement(toId);

    if(!from || !to){
        message('接続対象が見つかりません。');
        return;
    }

    const duplicate =
        state.project.connections.some(
            c=>c.from===fromId && c.to===toId
        );

    if(duplicate){
        message('その2要素は既に接続されています。');
        return;
    }

    const color =
        from.color ||
        '#ffffff';

    const connection = {
        id:uid('connection'),
        from:fromId,
        to:toId,
        fromPoint:'e',
        toPoint:'w',
        start:Math.min(from.start,to.start),
        end:Math.max(from.end,to.end),
        color,
        width:1.5,
        dash:'',
        arrow:true,
        curve:35
    };

    state.project.connections.push(connection);

    state.selectedId = connection.id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    markDirty();
    hideContextMenu();
    renderAll();

    message('要素を接続しました。',true);
}

/* =========================================================
 * Render video
 * ======================================================= */

function renderObjects(){
    const container = $('objects');
    container.innerHTML = '';

    if(!state.project) return;

    const t = currentTime();

    state.project.elements.forEach(e=>{
        if(t < e.start || t > e.end){
            return;
        }

        const el = document.createElement('div');

        el.className =
            'edit-object ' +
            e.type +
            (e.id===state.selectedId ? ' selected' : '') +
            (state.multiSelected.includes(e.id)
                ? ' multi-selected'
                : '');

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

        if(e.type === 'box'){
            el.style.background =
                'transparent';
        }

        if(e.type === 'skip'){
            const label = document.createElement('div');

            label.className = 'skip-label';
            label.textContent = 'SKIP';

            el.appendChild(label);
        }

        const resize = document.createElement('div');
        resize.className = 'resize-handle';
        el.appendChild(resize);

        el.addEventListener(
            'pointerdown',
            beginElementPointer
        );

        el.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                state.context = {
                    type:'element',
                    id:e.id
                };

                selectElement(e.id);

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

        el.addEventListener('dblclick',event=>{
            event.preventDefault();
            openElementModal(e);
        });

        container.appendChild(el);
    });
}

/* =========================================================
 * SVG connections
 * ======================================================= */

function pointForElement(e,point){
    const x = e.x;
    const y = e.y;
    const w = e.w;
    const h = e.h;

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

    return map[point] || map.e;
}

function renderConnectors(){
    const svg = $('connectors');

    svg.innerHTML = '';

    if(!state.project) return;

    const ns = 'http://www.w3.org/2000/svg';

    state.project.connections.forEach(c=>{
        const from = getElement(c.from);
        const to = getElement(c.to);

        if(!from || !to) return;

        const a = pointForElement(
            from,
            c.fromPoint || 'e'
        );

        const b = pointForElement(
            to,
            c.toPoint || 'w'
        );

        const dx = Math.abs(b[0]-a[0]);

        const curve =
            Math.max(
                3,
                Math.min(25,dx*.35)
            );

        const pathData =
            `M ${a[0]} ${a[1]} ` +
            `C ${a[0]+curve} ${a[1]}, ` +
            `${b[0]-curve} ${b[1]}, ` +
            `${b[0]} ${b[1]}`;

        const path = document.createElementNS(
            ns,
            'path'
        );

        path.setAttribute('d',pathData);
        path.setAttribute(
            'stroke',
            c.color || from.color || '#fff'
        );
        path.setAttribute(
            'stroke-width',
            String(c.width || 1.5)
        );
        path.setAttribute(
            'stroke-dasharray',
            c.dash || ''
        );
        path.classList.add('connector');

        if(c.id === state.selectedId){
            path.classList.add('selected');
        }

        svg.appendChild(path);

        const hit = document.createElementNS(
            ns,
            'path'
        );

        hit.setAttribute('d',pathData);
        hit.classList.add('connector-hit');

        hit.addEventListener('click',event=>{
            event.stopPropagation();

            state.selectedId = c.id;
            state.selectedType = 'connection';
            state.multiSelected = [];

            renderAll();
        });

        hit.addEventListener('contextmenu',event=>{
            event.preventDefault();
            event.stopPropagation();

            state.context = {
                type:'connection',
                id:c.id
            };

            state.selectedId = c.id;
            state.selectedType = 'connection';

            showContextMenu(
                event.clientX,
                event.clientY
            );
        });

        svg.appendChild(hit);
    });
}

/* =========================================================
 * Timeline
 * ======================================================= */

function timelineWidth(){
    return Math.max(
        700,
        duration() * 8 * state.zoom
    );
}

function renderTimeline(){
    const content = $('timelineContent');
    const scale = $('timelineScale');
    const tracks = $('timelineTracks');

    const d = duration();

    content.style.width =
        `${timelineWidth()}px`;

    scale.innerHTML = '';
    tracks.innerHTML = '';

    if(!d){
        return;
    }

    const width = timelineWidth();

    let step;

    if(state.zoom >= 3){
        step = 1;
    }else if(state.zoom >= 2){
        step = 2;
    }else if(state.zoom >= 1){
        step = 5;
    }else{
        step = 10;
    }

    for(let t=0;t<=d;t+=step){
        const span = document.createElement('span');

        span.textContent = fmt(t);

        span.style.left =
            `${t/d*100}%`;

        scale.appendChild(span);
    }

    renderTrack(
        'テキスト',
        'comment',
        state.project.elements.filter(
            e=>e.type==='comment'
        ),
        width,
        d
    );

    renderTrack(
        '強調枠',
        'box',
        state.project.elements.filter(
            e=>e.type==='box'
        ),
        width,
        d
    );

    renderTrack(
        'スキップ',
        'skip',
        state.project.elements.filter(
            e=>e.type==='skip'
        ),
        width,
        d
    );

    renderTrack(
        '接続',
        'connection',
        state.project.connections,
        width,
        d
    );

    updatePlayhead();
}

function renderTrack(name,type,items,width,d){
    const row = document.createElement('div');
    row.className = 'timeline-row';

    const label = document.createElement('div');
    label.textContent = name;

    const track = document.createElement('div');
    track.className = 'track';
    track.style.width = `${width}px`;

    row.appendChild(label);
    row.appendChild(track);

    items.forEach(item=>{
        const bar = document.createElement('div');

        bar.className =
            `track-item ${type}` +
            (
                item.id===state.selectedId
                    ? ' selected'
                    : ''
            );

        const start =
            Number(item.start) || 0;

        const end =
            Number(item.end) || 0;

        bar.style.left =
            `${start/d*100}%`;

        bar.style.width =
            `${Math.max(.2,(end-start)/d*100)}%`;

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

        nameText.textContent =
            type==='comment'
                ? (item.text || 'テキスト')
                : type==='box'
                    ? '強調枠'
                    : type==='skip'
                        ? 'SKIP'
                        : '接続';

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
                        'start',
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
                        'end',
                        track,
                        d
                    );
                }
            );

            bar.appendChild(left);
            bar.appendChild(right);
        }

        bar.addEventListener(
            'pointerdown',
            event=>{
                if(
                    event.target.closest('.track-handle') ||
                    type==='connection'
                ){
                    return;
                }

                selectTimelineItem(item,type);

                const rect =
                    track.getBoundingClientRect();

                state.timelineDrag = {
                    item,
                    side:'move',
                    rect,
                    duration:d,
                    startXRatio:
                        (event.clientX-rect.left) /
                        rect.width,
                    originalStart:start,
                    originalEnd:end
                };

                event.preventDefault();
            }
        );

        bar.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();

                if(type==='connection'){
                    editConnection(item);
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

                state.context = {
                    type:
                        type==='connection'
                            ? 'connection'
                            : 'element',
                    id:item.id
                };

                selectTimelineItem(item,type);

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

        track.appendChild(bar);
    });

    $('timelineTracks').appendChild(row);
}

function selectTimelineItem(item,type){
    state.selectedId = item.id;
    state.selectedType =
        type==='connection'
            ? 'connection'
            : 'element';

    state.multiSelected = [];

    renderAll();
}

function beginTimelineResize(
    event,
    item,
    side,
    track,
    d
){
    event.preventDefault();
    event.stopPropagation();

    state.timelineDrag = {
        item,
        side,
        rect:track.getBoundingClientRect(),
        duration:d
    };
}

function moveTimeline(event){
    const drag = state.timelineDrag;

    if(!drag) return;

    const ratio = clamp(
        (event.clientX-drag.rect.left) /
        drag.rect.width,
        0,
        1
    );

    const time =
        ratio * drag.duration;

    if(drag.side==='start'){
        drag.item.start =
            clamp(
                time,
                0,
                Number(drag.item.end)-.05
            );
    }else if(drag.side==='end'){
        drag.item.end =
            clamp(
                time,
                Number(drag.item.start)+.05,
                drag.duration
            );
    }else{
        const length =
            drag.originalEnd -
            drag.originalStart;

        const delta =
            (ratio-drag.startXRatio) *
            drag.duration;

        const start =
            clamp(
                drag.originalStart+delta,
                0,
                drag.duration-length
            );

        drag.item.start = start;
        drag.item.end = start+length;
    }

    normalizeElement(drag.item);

    markDirty();
    renderTimeline();
    renderObjects();
    renderConnectors();
}

function endTimelineDrag(){
    state.timelineDrag = null;
}

/* =========================================================
 * Playhead / Skip
 * ======================================================= */

function updatePlayhead(){
    const d = duration();

    if(!d){
        $('playhead').style.display = 'none';
        return;
    }

    $('playhead').style.display = 'block';

    $('playhead').style.left =
        `${currentTime()/d*100}%`;

    $('currentTime').textContent =
        `${fmt(currentTime())} / ${fmt(d)}`;
}

function processSkip(){
    if(state.skipLock || !state.project) return;

    const now = currentTime();

    const skip =
        state.project.elements.find(
            e =>
                e.type==='skip' &&
                now>=e.start &&
                now<e.end-.02
        );

    if(!skip) return;

    state.skipLock = true;

    $('recordedVideo').currentTime =
        Math.min(skip.end,duration());

    requestAnimationFrame(()=>{
        state.skipLock = false;
    });
}

/* =========================================================
 * Context menu
 * ======================================================= */

function showContextMenu(x,y){
    const menu = $('contextMenu');

    const canConnect =
        state.multiSelected.length===2;

    $('contextConnect').disabled =
        !canConnect;

    menu.style.display = 'block';

    const w = 260;
    const h = menu.offsetHeight;

    menu.style.left =
        `${Math.min(x,innerWidth-w-5)}px`;

    menu.style.top =
        `${Math.min(y,innerHeight-h-5)}px`;
}

function hideContextMenu(){
    $('contextMenu').style.display = 'none';
    state.context = null;
}

/* =========================================================
 * Element modal
 * ======================================================= */

let modalTarget = null;

function openElementModal(e){
    if(!e) return;

    modalTarget = e;

    $('modalTitle').textContent =
        e.type==='comment'
            ? 'テキストを編集'
            : e.type==='box'
                ? '強調枠を編集'
                : 'スキップを編集';

    $('modalType').textContent =
        e.type==='comment'
            ? 'テキスト'
            : e.type==='box'
                ? '強調枠'
                : 'スキップ';

    $('elementText').value =
        e.text || '';

    $('elementStart').value =
        Number(e.start).toFixed(3);

    $('elementEnd').value =
        Number(e.end).toFixed(3);

    $('elementColor').value =
        e.color || '#ffffff';

    $('elementBorderWidth').value =
        e.borderWidth ?? 2;

    $('elementFontSize').value =
        e.fontSize ?? 24;

    $('elementFontWeight').value =
        e.fontWeight ?? 600;

    const textVisible =
        e.type==='comment';

    $('textRow').style.display =
        textVisible ? 'grid' : 'none';

    $('fontSizeRow').style.display =
        textVisible ? 'grid' : 'none';

    $('fontWeightRow').style.display =
        textVisible ? 'grid' : 'none';

    renderPalette();

    $('elementModal').style.display =
        'flex';
}

function closeElementModal(){
    $('elementModal').style.display =
        'none';

    modalTarget = null;
}

function saveElementModal(){
    if(!modalTarget) return;

    const d =
        duration() ||
        state.project.videoDuration;

    modalTarget.text =
        $('elementText').value;

    modalTarget.start =
        clamp(
            Number($('elementStart').value) || 0,
            0,
            d
        );

    modalTarget.end =
        clamp(
            Number($('elementEnd').value) || 0,
            modalTarget.start+.05,
            d
        );

    modalTarget.color =
        $('elementColor').value;

    modalTarget.borderWidth =
        clamp(
            Number($('elementBorderWidth').value)||0,
            0,
            20
        );

    modalTarget.fontSize =
        clamp(
            Number($('elementFontSize').value)||24,
            8,
            200
        );

    modalTarget.fontWeight =
        Number($('elementFontWeight').value)||600;

    normalizeElement(modalTarget);

    markDirty();
    closeElementModal();
    renderAll();
}

function renderPalette(){
    const p = $('palette');

    p.innerHTML = '';

    COLORS.forEach(color=>{
        const b = document.createElement('button');

        b.type = 'button';
        b.style.background = color;

        if(
            modalTarget &&
            modalTarget.color.toLowerCase() ===
            color.toLowerCase()
        ){
            b.classList.add('active');
        }

        b.addEventListener('click',()=>{
            $('elementColor').value = color;
            renderPalette();
        });

        p.appendChild(b);
    });
}

/* =========================================================
 * Connection modal
 * ======================================================= */

function editConnection(c){
    const color =
        prompt(
            '線の色を入力してください',
            c.color || '#ffffff'
        );

    if(color===null) return;

    const width =
        prompt(
            '線の太さ(px)',
            String(c.width || 1.5)
        );

    if(width===null) return;

    c.color = color;
    c.width = clamp(
        Number(width)||1.5,
        .5,
        10
    );

    markDirty();
    renderAll();
}

/* =========================================================
 * Project save/load
 * ======================================================= */

async function saveProject(){
    if(!state.project){
        message('プロジェクトがありません。');
        return;
    }

    if(duration()<=0){
        message('先に動画を読み込んでください。');
        return;
    }

    state.project.name =
        $('editorProjectName').value.trim() ||
        '名称未設定';

    state.project.videoDuration =
        duration();

    try{
        const response =
            await fetch('?api=save',{
                method:'POST',
                headers:{
                    'Content-Type':'application/json'
                },
                body:JSON.stringify(state.project)
            });

        const result =
            await response.json();

        if(result.ok){
            state.project.projectId =
                result.projectId;

            state.project.savedAt =
                result.savedAt;

            state.dirty = false;

            $('editorStatus').textContent =
                '保存済み';

            message('サーバーに保存しました。',true);
            return;
        }

        if(result.limit){
            throw new Error(
                'SERVER_LIMIT'
            );
        }

        throw new Error(
            result.message ||
            '保存に失敗しました'
        );

    }catch(err){

        if(err.message!=='SERVER_LIMIT'){
            try{
                await idbPut(state.project);

                state.dirty = false;

                $('editorStatus').textContent =
                    'ローカル保存済み';

                message(
                    'サーバー保存に失敗したため、ブラウザに保存しました。',
                    true
                );

                return;

            }catch(localError){
                console.error(localError);
            }
        }else{
            try{
                await idbPut(state.project);

                state.dirty = false;

                $('editorStatus').textContent =
                    'ローカル保存済み';

                message(
                    'サーバー保存上限のため、ローカルに保存しました。',
                    true
                );

                return;

            }catch(localError){
                console.error(localError);
            }
        }

        message('保存できませんでした。');
    }
}

function exportProject(){
    if(!state.project){
        message('プロジェクトがありません。');
        return;
    }

    const data =
        JSON.stringify(
            state.project,
            null,
            2
        );

    const blob =
        new Blob(
            [data],
            {type:'application/json'}
        );

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href = url;

    a.download =
        `${state.project.name || 'project'}.json`;

    a.click();

    URL.revokeObjectURL(url);
}

async function importProjectFile(file){
    if(!file) return;

    try{
        const text =
            await file.text();

        const project =
            JSON.parse(text);

        if(
            !project ||
            !Array.isArray(project.elements) ||
            !Array.isArray(project.connections)
        ){
            throw new Error(
                'プロジェクト形式が不正です。'
            );
        }

        state.project = project;

        if(!state.project.projectId){
            state.project.projectId =
                uid('project');
        }

        if(!state.project.name){
            state.project.name =
                '読み込んだプロジェクト';
        }

        state.selectedId = null;
        state.selectedType = null;
        state.multiSelected = [];

        $('editorProjectName').value =
            state.project.name;

        showEditor();

        setEditorLocked(true);

        message(
            'プロジェクトを読み込みました。動画を選択してください。',
            true
        );

    }catch(err){
        console.error(err);
        message(
            err.message ||
            'プロジェクトを読み込めませんでした。'
        );
    }
}

async function loadServerProject(id){
    try{
        const response =
            await fetch(
                '?api=load&id='+
                encodeURIComponent(id)
            );

        const result =
            await response.json();

        if(!result.ok){
            throw new Error(
                result.message ||
                '読み込みに失敗しました。'
            );
        }

        state.project =
            result.project;

        state.selectedId = null;
        state.selectedType = null;
        state.multiSelected = [];

        $('editorProjectName').value =
            state.project.name || '';

        showEditor();
        setEditorLocked(true);

        message(
            'プロジェクトを読み込みました。動画を選択してください。',
            true
        );

    }catch(err){
        message(err.message);
    }
}

/* =========================================================
 * Project list
 * ======================================================= */

async function renderProjectList(){
    const list = $('projectList');

    list.innerHTML =
        '<div style="color:#888">読み込み中…</div>';

    let projects = [];

    try{
        const response =
            await fetch('?api=list');

        const result =
            await response.json();

        if(result.ok){
            projects =
                result.projects || [];
        }
    }catch(err){
        console.error(err);
    }

    try{
        const local =
            await idbGetAll();

        local.forEach(p=>{
            if(
                !projects.some(
                    s=>s.projectId===p.projectId
                )
            ){
                projects.push({
                    ...p,
                    storage:'local'
                });
            }
        });
    }catch(err){
        console.error(err);
    }

    if(!projects.length){
        list.innerHTML =
            '<div style="color:#888">保存されたプロジェクトはありません。</div>';
        return;
    }

    list.innerHTML = '';

    projects.forEach(p=>{
        const row =
            document.createElement('div');

        row.className = 'project';

        const info =
            document.createElement('div');

        info.className = 'project-info';

        const name =
            document.createElement('div');

        name.className = 'project-name';
        name.textContent =
            p.name || '名称未設定';

        const meta =
            document.createElement('div');

        meta.className = 'project-meta';

        meta.textContent =
            `${p.videoName || '動画未設定'} / ` +
            `${p.storage==='local' ? 'ローカル' : 'サーバー'} / ` +
            `${p.savedAt || ''}`;

        info.appendChild(name);
        info.appendChild(meta);

        const actions =
            document.createElement('div');

        actions.className =
            'project-actions';

        const load =
            document.createElement('button');

        load.textContent = '開く';

        load.addEventListener('click',()=>{
            if(p.storage==='local'){
                idbGet(p.projectId)
                    .then(project=>{
                        if(project){
                            state.project = project;
                            $('editorProjectName').value =
                                project.name || '';
                            showEditor();
                            setEditorLocked(true);
                            message(
                                'ローカルプロジェクトを開きました。動画を選択してください。',
                                true
                            );
                        }
                    });
            }else{
                loadServerProject(p.projectId);
            }
        });

        actions.appendChild(load);

        if(p.storage==='local'){
            const del =
                document.createElement('button');

            del.className='danger';
            del.textContent='削除';

            del.addEventListener('click',async()=>{
                if(!confirm('このローカルプロジェクトを削除しますか？')){
                    return;
                }

                await idbDelete(p.projectId);
                renderProjectList();
            });

            actions.appendChild(del);
        }

        row.appendChild(info);
        row.appendChild(actions);

        list.appendChild(row);
    });
}

/* =========================================================
 * Render all
 * ======================================================= */

function renderAll(){
    renderObjects();
    renderConnectors();
    renderTimeline();
    updatePlayhead();
}

/* =========================================================
 * Events
 * ======================================================= */

$('newProject').addEventListener(
    'click',
    newProject
);

$('openVideo').addEventListener(
    'click',
    ()=>{
        if(!state.project){
            newProject();
        }

        $('videoFile').click();
    }
);

$('videoFile').addEventListener(
    'change',
    event=>{
        handleVideo(event.target.files[0]);
        event.target.value='';
    }
);

$('loadVideoButton').addEventListener(
    'click',
    ()=>$('editorVideoFile').click()
);

$('editorVideoFile').addEventListener(
    'change',
    event=>{
        handleVideo(event.target.files[0]);
        event.target.value='';
    }
);

$('importProject').addEventListener(
    'click',
    ()=>$('projectFile').click()
);

$('projectFile').addEventListener(
    'change',
    event=>{
        importProjectFile(event.target.files[0]);
        event.target.value='';
    }
);

$('backHome').addEventListener(
    'click',
    ()=>{
        if(
            state.dirty &&
            !confirm(
                '保存していない変更があります。戻りますか？'
            )
        ){
            return;
        }

        showHome();
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
                $('editorProjectName').value;
            markDirty();
        }
    }
);

$('videoArea').addEventListener(
    'pointerdown',
    beginElementPointer
);

window.addEventListener(
    'pointermove',
    event=>{
        if(state.drag){
            moveElementPointer(event);
        }

        if(state.timelineDrag){
            moveTimeline(event);
        }
    }
);

window.addEventListener(
    'pointerup',
    ()=>{
        endElementPointer();
        endTimelineDrag();
    }
);

$('recordedVideo').addEventListener(
    'loadedmetadata',
    ()=>{
        setEditorLocked(false);

        if(state.project){
            state.project.videoDuration =
                $('recordedVideo').duration;
        }

        renderAll();
    }
);

$('recordedVideo').addEventListener(
    'timeupdate',
    ()=>{
        processSkip();
        updatePlayhead();
        renderObjects();
    }
);

$('recordedVideo').addEventListener(
    'play',
    ()=>{
        $('playToggle').textContent='❚❚';
    }
);

$('recordedVideo').addEventListener(
    'pause',
    ()=>{
        $('playToggle').textContent='▶';
    }
);

$('recordedVideo').addEventListener(
    'ended',
    ()=>{
        $('playToggle').textContent='▶';
    }
);

$('playToggle').addEventListener(
    'click',
    ()=>{
        const video = $('recordedVideo');

        if(!video.src || duration()<=0){
            message('先に動画を読み込んでください。');
            return;
        }

        if(video.paused){
            video.play().catch(err=>{
                console.error(err);
                message('動画を再生できませんでした。');
            });
        }else{
            video.pause();
        }
    }
);

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
        const values=[.5,1,2,3,5];
        const current =
            values.indexOf(state.zoom);

        state.zoom =
            values[Math.max(0,current-1)];

        $('timelineZoom').value =
            String(state.zoom);

        renderTimeline();
    }
);

$('timelineZoomIn').addEventListener(
    'click',
    ()=>{
        const values=[.5,1,2,3,5];
        const current =
            values.indexOf(state.zoom);

        state.zoom =
            values[Math.min(values.length-1,current+1)];

        $('timelineZoom').value =
            String(state.zoom);

        renderTimeline();
    }
);

/* 動画領域右クリック */
$('videoArea').addEventListener(
    'contextmenu',
    event=>{
        event.preventDefault();

        if(
            event.target.closest('.edit-object') ||
            event.target.closest('.connector-hit')
        ){
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

/* タイムライン空白右クリック */
$('timeline').addEventListener(
    'contextmenu',
    event=>{
        if(
            event.target.closest('.track-item')
        ){
            return;
        }

        event.preventDefault();

        state.context = {
            type:'empty'
        };

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

/* context menu */
$('contextMenu').addEventListener(
    'click',
    event=>{
        const button =
            event.target.closest('[data-action]');

        if(!button) return;

        const action =
            button.dataset.action;

        const context =
            state.context;

        hideContextMenu();

        if(action==='add-comment'){
            addElement('comment');
            return;
        }

        if(action==='add-box'){
            addElement('box');
            return;
        }

        if(action==='add-skip'){
            addElement('skip');
            return;
        }

        if(action==='edit'){
            if(
                context?.type==='element'
            ){
                openElementModal(
                    getElement(context.id)
                );
            }else if(
                context?.type==='connection'
            ){
                const c =
                    state.project.connections.find(
                        c=>c.id===context.id
                    );

                if(c) editConnection(c);
            }

            return;
        }

        if(action==='duplicate'){
            duplicateSelected();
            return;
        }

        if(action==='delete'){
            deleteSelected();
            return;
        }

        if(action==='connect'){
            connectSelected();
        }
    }
);

document.addEventListener(
    'click',
    event=>{
        if(
            !event.target.closest('#contextMenu')
        ){
            hideContextMenu();
        }
    }
);

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

$('recordedVideo').addEventListener(
    'durationchange',
    ()=>{
        renderTimeline();
    }
);

/* =========================================================
 * Keyboard
 * ======================================================= */

document.addEventListener(
    'keydown',
    event=>{
        if(
            event.key==='Delete' &&
            !$('elementModal').style.display
        ){
            deleteSelected();
        }

        if(
            event.key==='Escape'
        ){
            hideContextMenu();
            closeElementModal();
        }

        if(
            event.key===' ' &&
            document.activeElement.tagName!=='INPUT' &&
            document.activeElement.tagName!=='TEXTAREA'
        ){
            event.preventDefault();
            $('playToggle').click();
        }
    }
);

/* =========================================================
 * Start
 * ======================================================= */

(async function init(){

    try{
        await openDB();
    }catch(err){
        console.error(err);
    }

    $('status').textContent =
        '準備完了';

    await renderProjectList();

})();
</script>

</body>
</html>

<?php
declare(strict_types=1);

const APP_VERSION = '4.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'version' => APP_VERSION
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'export-json') {
        $json = $_POST['project'] ?? '';
        if ($json === '') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'project is required'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="video-editor-project.json"');
        echo $json;
        exit;
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集・注釈ツール</title>

<style>
:root{
    --bg:#0f1722;
    --panel:#182231;
    --panel2:#202c3c;
    --line:#334155;
    --text:#e5e7eb;
    --muted:#94a3b8;
    --accent:#2563eb;
    --danger:#dc2626;
    --green:#22c55e;
    --timeline-label:120px;
    --track-h:54px;
}

*{box-sizing:border-box}

html,body{
    margin:0;
    width:100%;
    height:100%;
    overflow:hidden;
    background:var(--bg);
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif
}

button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}

.app{
    width:100%;
    height:100vh;
    display:flex;
    flex-direction:column
}

.topbar{
    height:54px;
    flex:none;
    display:flex;
    align-items:center;
    gap:7px;
    padding:7px 10px;
    border-bottom:1px solid var(--line);
    background:#0b1320
}

.brand{
    font-weight:700;
    white-space:nowrap;
    margin-right:8px
}

.btn{
    border:1px solid #40516a;
    border-radius:6px;
    background:#253246;
    color:#e5e7eb;
    padding:7px 11px
}

.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:#2563eb;border-color:#3b82f6}
.btn.primary:hover:not(:disabled){background:#1d4ed8}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{font-size:12px;padding:5px 8px}

.status{
    margin-left:auto;
    color:var(--muted);
    font-size:12px;
    white-space:nowrap
}

.main{
    min-height:0;
    flex:1;
    display:flex;
    flex-direction:column
}

.video-area{
    min-height:260px;
    flex:1;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:12px;
    background:#070c13;
    overflow:hidden
}

.video-wrap{
    position:relative;
    width:min(900px,100%);
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center
}

#video{
    display:block;
    max-width:100%;
    max-height:100%;
    background:#000;
    border-radius:4px
}

.video-overlay{
    position:absolute;
    inset:0;
    pointer-events:none
}

.overlay-element{
    position:absolute;
    min-width:30px;
    min-height:20px;
    pointer-events:auto;
    user-select:none;
    touch-action:none
}

.overlay-element.selected{
    outline:2px solid #60a5fa;
    outline-offset:2px
}

.overlay-element.multi-selected{
    outline:2px solid #fbbf24;
    outline-offset:2px
}

.overlay-text{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:4px;
    overflow:hidden;
    white-space:pre-wrap
}

.overlay-box{
    width:100%;
    height:100%;
    border:3px solid currentColor;
    background:rgba(255,255,255,.04)
}

.overlay-skip{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    border:2px dashed #f97316;
    color:#fdba74;
    background:rgba(249,115,22,.12);
    font-size:12px
}

.resize-handle{
    position:absolute;
    width:10px;
    height:10px;
    z-index:5;
    background:#fff;
    border:1px solid #111827;
    border-radius:2px
}

.resize-handle.tl{left:-6px;top:-6px;cursor:nwse-resize}
.resize-handle.tr{right:-6px;top:-6px;cursor:nesw-resize}
.resize-handle.bl{left:-6px;bottom:-6px;cursor:nesw-resize}
.resize-handle.br{right:-6px;bottom:-6px;cursor:nwse-resize}

.empty-video{
    position:absolute;
    color:#64748b;
    text-align:center;
    line-height:1.8;
    pointer-events:none
}

.empty-video strong{
    display:block;
    color:#94a3b8;
    font-size:18px
}

.timeline-panel{
    flex:none;
    height:320px;
    min-height:0;
    display:flex;
    flex-direction:column;
    border-top:1px solid var(--line);
    background:#101923
}

.timeline-toolbar{
    height:42px;
    flex:none;
    display:flex;
    align-items:center;
    gap:7px;
    padding:5px 8px;
    border-bottom:1px solid var(--line)
}

.time-readout{
    min-width:115px;
    font-variant-numeric:tabular-nums;
    color:#dbeafe
}

.selection-hint{
    color:#64748b;
    font-size:11px
}

.connection-mode{
    color:#fbbf24;
    font-size:11px;
    font-weight:600
}

.zoom-control{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:6px;
    color:var(--muted);
    font-size:12px
}

.zoom-control input{width:120px}

.timeline-scroll{
    position:relative;
    flex:1;
    min-height:0;
    overflow:auto
}

.timeline-content{
    position:relative;
    min-width:700px
}

.axis-row{
    position:sticky;
    top:0;
    z-index:30;
    height:34px;
    display:flex;
    background:#131d29;
    border-bottom:1px solid var(--line)
}

.axis-label{
    width:var(--timeline-label);
    min-width:var(--timeline-label);
    flex:none;
    display:flex;
    align-items:center;
    padding-left:8px;
    color:#94a3b8;
    font-size:12px;
    background:#131d29;
    border-right:1px solid var(--line);
    position:sticky;
    left:0;
    z-index:40
}

.axis-track{
    position:relative;
    height:100%;
    flex:1;
    min-width:0
}

.tick{
    position:absolute;
    top:0;
    height:100%;
    border-left:1px solid #334155;
    padding:5px 0 0 3px;
    color:#64748b;
    font-size:10px;
    pointer-events:none;
    white-space:nowrap
}

.track-row{
    position:relative;
    height:var(--track-h);
    display:flex;
    border-bottom:1px solid #263445
}

.track-label{
    width:var(--timeline-label);
    min-width:var(--timeline-label);
    flex:none;
    display:flex;
    align-items:center;
    gap:5px;
    padding:0 8px;
    color:#cbd5e1;
    font-size:12px;
    background:#111b27;
    border-right:1px solid var(--line);
    position:sticky;
    left:0;
    z-index:20
}

.type-dot{
    width:8px;
    height:8px;
    flex:none;
    border-radius:50%
}

.track-lane{
    position:relative;
    flex:1;
    min-width:0
}

.track-grid{
    position:absolute;
    inset:0;
    pointer-events:none;
    background-image:
        linear-gradient(
            to right,
            rgba(148,163,184,.09) 1px,
            transparent 1px
        )
}

.element-bar{
    position:absolute;
    top:9px;
    height:36px;
    min-width:12px;
    border:1px solid currentColor;
    border-radius:5px;
    display:flex;
    align-items:center;
    overflow:visible;
    cursor:grab;
    user-select:none;
    touch-action:none;
    z-index:5
}

.element-bar:active{cursor:grabbing}

.element-bar.selected{
    box-shadow:0 0 0 2px #fbbf24
}

.element-bar.multi-selected{
    box-shadow:0 0 0 2px #60a5fa
}

.element-bar.connection-source{
    box-shadow:
        0 0 0 2px #f59e0b,
        0 0 12px rgba(245,158,11,.7)
}

.bar-label{
    padding:0 8px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:11px;
    pointer-events:none
}

.element-bar .handle{
    position:absolute;
    top:0;
    width:10px;
    height:100%;
    z-index:8
}

.element-bar .handle.left{
    left:-5px;
    cursor:ew-resize
}

.element-bar .handle.right{
    right:-5px;
    cursor:ew-resize
}

.element-bar .handle:hover{
    background:rgba(255,255,255,.2)
}

.playhead{
    position:absolute;
    top:0;
    bottom:0;
    width:2px;
    z-index:50;
    pointer-events:none;
    background:#ef4444;
    box-shadow:0 0 4px rgba(239,68,68,.6)
}

.playhead::before{
    content:"";
    position:absolute;
    top:0;
    left:-5px;
    width:12px;
    height:12px;
    background:#ef4444;
    clip-path:polygon(0 0,100% 0,50% 100%)
}

.playhead-label{
    position:absolute;
    top:12px;
    left:5px;
    padding:2px 4px;
    border-radius:3px;
    background:#ef4444;
    color:#fff;
    font-size:10px;
    white-space:nowrap
}

/*
 * 重要:
 * SVGはtimeline-content全体と完全に同じ座標系。
 * connectionAnchor()では固定値を使わず、
 * 実際のelement-barのDOM矩形から座標を取得する。
 */
.connection-svg{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    z-index:45;
    overflow:visible;
    pointer-events:none
}

.connection-path{
    fill:none;
    stroke-width:2;
    pointer-events:stroke;
    cursor:pointer
}

.connection-path.selected{
    stroke-width:4
}

.connection-endpoint{
    fill:#fff;
    pointer-events:none
}

.connection-preview{
    fill:none;
    stroke:#fbbf24;
    stroke-width:2;
    stroke-dasharray:6 4;
    pointer-events:none
}

.context-menu{
    position:fixed;
    display:none;
    z-index:2000;
    min-width:210px;
    padding:5px;
    border:1px solid #475569;
    border-radius:6px;
    background:#172235;
    box-shadow:0 12px 30px rgba(0,0,0,.4)
}

.context-menu button{
    display:block;
    width:100%;
    padding:8px;
    border:0;
    border-radius:4px;
    background:transparent;
    color:#e5e7eb;
    text-align:left
}

.context-menu button:hover{background:#293a52}
.context-menu .danger-item{color:#fca5a5}

.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:3000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:rgba(0,0,0,.65)
}

.modal{
    width:min(520px,100%);
    overflow:hidden;
    border:1px solid #475569;
    border-radius:8px;
    background:#182231;
    box-shadow:0 20px 60px rgba(0,0,0,.5)
}

.modal-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:12px 15px;
    border-bottom:1px solid var(--line)
}

.modal-body{
    max-height:75vh;
    overflow:auto;
    display:grid;
    gap:12px;
    padding:15px
}

.modal-foot{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    padding:12px 15px;
    border-top:1px solid var(--line)
}

.field{
    display:grid;
    gap:5px
}

.field label{
    color:#94a3b8;
    font-size:12px
}

.field input,
.field select,
.field textarea{
    width:100%;
    padding:7px;
    border:1px solid #40516a;
    border-radius:5px;
    background:#0f1722;
    color:#e5e7eb
}

.field textarea{
    min-height:80px;
    resize:vertical
}

.color-grid{
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:6px
}

.color-choice{
    height:30px;
    border:2px solid transparent;
    border-radius:4px
}

.color-choice.active{
    border-color:#fff;
    box-shadow:0 0 0 1px #60a5fa
}

.storage-item{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px;
    border-bottom:1px solid #334155
}

.storage-item .info{
    min-width:0;
    flex:1
}

.storage-item .name{
    font-weight:600;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap
}

.storage-item .meta{
    color:#64748b;
    font-size:11px
}

.toast{
    position:fixed;
    right:15px;
    bottom:15px;
    z-index:5000;
    display:none;
    max-width:400px;
    padding:10px 14px;
    border:1px solid #475569;
    border-radius:6px;
    background:#1e293b;
    color:#e5e7eb;
    box-shadow:0 10px 30px rgba(0,0,0,.35)
}

.file-input{display:none}

.hidden{display:none!important}

@media(max-width:800px){
    :root{--timeline-label:95px}
    .brand{display:none}
    .timeline-panel{height:285px}
    .video-area{min-height:220px}
}
</style>
</head>

<body>

<div class="app">

<header class="topbar">
    <div class="brand">動画編集・注釈</div>

    <button class="btn primary" id="openVideoBtn">動画を読み込む</button>
    <input id="videoFile" class="file-input" type="file" accept="video/*">

    <button class="btn" id="newProjectBtn">新規</button>
    <button class="btn" id="saveProjectBtn" disabled>保存</button>
    <button class="btn" id="loadProjectBtn">保存データ</button>

    <button class="btn" id="exportProjectBtn">プロジェクト書き出し</button>

    <label class="btn">
        プロジェクト読込
        <input
            id="importProjectInput"
            class="file-input"
            type="file"
            accept=".json,application/json">
    </label>

    <span class="status" id="statusText">動画を読み込んでください</span>
</header>

<main class="main">

<section class="video-area" id="videoArea">

    <div class="video-wrap" id="videoWrap">

        <video id="video" preload="metadata" playsinline></video>

        <div class="video-overlay" id="videoOverlay"></div>

        <div class="empty-video" id="emptyVideo">
            <strong>動画を読み込んでください</strong>
            動画の読み込み後に編集できます<br>
            動画上で右クリックすると要素を追加できます
        </div>

    </div>

</section>

<section class="timeline-panel">

    <div class="timeline-toolbar">

        <button class="btn small" id="playBtn" disabled>▶</button>
        <button class="btn small" id="stopBtn" disabled>■</button>

        <span class="time-readout" id="timeReadout">
            00:00.000 / 00:00.000
        </span>

        <span class="selection-hint" id="selectionHint"></span>

        <span class="connection-mode hidden" id="connectionMode">
            接続モード：次の要素をクリック
        </span>

        <button class="btn small hidden" id="cancelConnectionBtn">
            接続キャンセル
        </button>

        <div class="zoom-control">
            <span>時間スケール</span>
            <input
                id="zoomRange"
                type="range"
                min="1"
                max="5"
                step=".1"
                value="1">
            <span id="zoomValue">1.0×</span>
        </div>

    </div>

    <div class="timeline-scroll" id="timelineScroll">

        <div class="timeline-content" id="timelineContent">

            <div class="axis-row">
                <div class="axis-label">時間</div>
                <div class="axis-track" id="axisTrack"></div>
            </div>

            <div id="tracksContainer"></div>

            <svg
                class="connection-svg"
                id="connectionSvg"
                preserveAspectRatio="none">
            </svg>

            <svg
                class="connection-svg"
                id="connectionPreviewSvg"
                preserveAspectRatio="none">
            </svg>

            <div class="playhead" id="playhead">
                <span class="playhead-label" id="playheadLabel">
                    00:00.000
                </span>
            </div>

        </div>

    </div>

</section>

</main>
</div>

<div class="context-menu" id="contextMenu">

    <button id="ctxAddText">テキストを追加</button>
    <button id="ctxAddBox">強調枠を追加</button>
    <button id="ctxAddSkip">スキップを追加</button>

    <button id="ctxEdit">選択要素を編集</button>

    <button id="ctxConnect">2要素を接続</button>
    <button id="ctxStartConnect">接続モード開始</button>
    <button id="ctxDisconnect">接続を解除</button>
    <button id="ctxConnectionStyle">接続線の書式</button>

    <button id="ctxDelete" class="danger-item">削除</button>

</div>

<div class="modal-backdrop" id="elementModal">

<div class="modal">

<div class="modal-head">
    <strong id="elementModalTitle">要素を編集</strong>
    <button class="btn small" id="elementModalClose">閉じる</button>
</div>

<div class="modal-body">

<div class="field">
    <label>要素名</label>
    <input id="elementName" type="text">
</div>

<div class="field" id="textField">
    <label>テキスト</label>
    <textarea id="elementText"></textarea>
</div>

<div class="field">
    <label>開始時間（秒）</label>
    <input id="elementStart" type="number" min="0" step=".001">
</div>

<div class="field">
    <label>終了時間（秒）</label>
    <input id="elementEnd" type="number" min="0" step=".001">
</div>

<div class="field">
    <label>横位置（%）</label>
    <input id="elementX" type="number" min="0" max="100" step=".1">
</div>

<div class="field">
    <label>縦位置（%）</label>
    <input id="elementY" type="number" min="0" max="100" step=".1">
</div>

<div class="field">
    <label>幅（%）</label>
    <input id="elementW" type="number" min="1" max="100" step=".1">
</div>

<div class="field">
    <label>高さ（%）</label>
    <input id="elementH" type="number" min="1" max="100" step=".1">
</div>

<div class="field">
    <label>色</label>
    <div class="color-grid" id="colorGrid"></div>
</div>

<div class="field" id="fontSizeField">
    <label>文字サイズ（px）</label>
    <input id="elementFontSize" type="number" min="8" max="100">
</div>

<div class="field" id="fontWeightField">
    <label>文字書式</label>
    <select id="elementFontWeight">
        <option value="400">標準</option>
        <option value="500">やや太い</option>
        <option value="700">太字</option>
        <option value="900">極太</option>
    </select>
</div>

</div>

<div class="modal-foot">
    <button class="btn" id="elementModalCancel">キャンセル</button>
    <button class="btn primary" id="elementModalSave">保存</button>
</div>

</div>
</div>

<div class="modal-backdrop" id="storageModal">

<div class="modal">

<div class="modal-head">
    <strong>保存データ</strong>
    <button class="btn small" id="storageClose">閉じる</button>
</div>

<div class="modal-body">
    <div id="storageList"></div>
</div>

</div>
</div>

<div class="modal-backdrop" id="projectNameModal">

<div class="modal">

<div class="modal-head">
    <strong>プロジェクト名</strong>
</div>

<div class="modal-body">

<div class="field">
    <label>名前</label>
    <input
        id="projectNameInput"
        type="text"
        maxlength="100"
        placeholder="例：商品説明動画">
</div>

</div>

<div class="modal-foot">
    <button class="btn" id="projectNameCancel">キャンセル</button>
    <button class="btn primary" id="projectNameOk">作成</button>
</div>

</div>
</div>

<div class="toast" id="toast"></div>

<script>
(() => {
'use strict';

/* =========================================================
 * 基本設定
 * ======================================================= */

const APP_VERSION = '4.0.0';

const COLORS = [
    '#ef4444','#f97316','#eab308','#22c55e',
    '#14b8a6','#06b6d4','#3b82f6','#6366f1',
    '#8b5cf6','#ec4899','#f8fafc','#94a3b8'
];

const DB_NAME = 'VideoAnnotationEditorDB';
const DB_VERSION = 2;
const PROJECT_STORE = 'projects';

const state = {
    project:null,
    videoBlob:null,
    videoUrl:'',
    duration:0,
    editorReady:false,
    currentTime:0,

    selectedIds:new Set(),
    selectedConnectionId:null,

    contextTargetId:null,
    contextTargetConnectionId:null,
    contextX:0,
    contextY:0,

    modalElementId:null,
    editingColor:'#3b82f6',

    dragging:null,

    connectionMode:false,
    connectionSourceId:null,
    connectionPointer:{x:0,y:0},

    saveTimer:null
};

const $ = id => document.getElementById(id);

const els = {
    video:$('video'),
    videoWrap:$('videoWrap'),
    videoArea:$('videoArea'),
    videoOverlay:$('videoOverlay'),
    emptyVideo:$('emptyVideo'),

    videoFile:$('videoFile'),
    openVideoBtn:$('openVideoBtn'),
    newProjectBtn:$('newProjectBtn'),
    saveProjectBtn:$('saveProjectBtn'),
    loadProjectBtn:$('loadProjectBtn'),
    exportProjectBtn:$('exportProjectBtn'),
    importProjectInput:$('importProjectInput'),

    statusText:$('statusText'),

    playBtn:$('playBtn'),
    stopBtn:$('stopBtn'),
    timeReadout:$('timeReadout'),
    selectionHint:$('selectionHint'),

    connectionMode:$('connectionMode'),
    cancelConnectionBtn:$('cancelConnectionBtn'),

    zoomRange:$('zoomRange'),
    zoomValue:$('zoomValue'),

    timelineScroll:$('timelineScroll'),
    timelineContent:$('timelineContent'),
    axisTrack:$('axisTrack'),
    tracksContainer:$('tracksContainer'),

    connectionSvg:$('connectionSvg'),
    connectionPreviewSvg:$('connectionPreviewSvg'),

    playhead:$('playhead'),
    playheadLabel:$('playheadLabel'),

    contextMenu:$('contextMenu'),

    ctxAddText:$('ctxAddText'),
    ctxAddBox:$('ctxAddBox'),
    ctxAddSkip:$('ctxAddSkip'),
    ctxEdit:$('ctxEdit'),
    ctxConnect:$('ctxConnect'),
    ctxStartConnect:$('ctxStartConnect'),
    ctxDisconnect:$('ctxDisconnect'),
    ctxConnectionStyle:$('ctxConnectionStyle'),
    ctxDelete:$('ctxDelete'),

    elementModal:$('elementModal'),
    elementModalTitle:$('elementModalTitle'),
    elementModalClose:$('elementModalClose'),
    elementModalCancel:$('elementModalCancel'),
    elementModalSave:$('elementModalSave'),

    elementName:$('elementName'),
    elementText:$('elementText'),
    elementStart:$('elementStart'),
    elementEnd:$('elementEnd'),
    elementX:$('elementX'),
    elementY:$('elementY'),
    elementW:$('elementW'),
    elementH:$('elementH'),
    elementFontSize:$('elementFontSize'),
    elementFontWeight:$('elementFontWeight'),

    textField:$('textField'),
    fontSizeField:$('fontSizeField'),
    fontWeightField:$('fontWeightField'),

    colorGrid:$('colorGrid'),

    storageModal:$('storageModal'),
    storageClose:$('storageClose'),
    storageList:$('storageList'),

    projectNameModal:$('projectNameModal'),
    projectNameInput:$('projectNameInput'),
    projectNameCancel:$('projectNameCancel'),
    projectNameOk:$('projectNameOk'),

    toast:$('toast')
};

/* =========================================================
 * 共通
 * ======================================================= */

function uid(prefix='id'){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,9);
}

function clamp(value,min,max){
    return Math.max(min,Math.min(max,value));
}

function formatTime(seconds){
    const value = Math.max(0,Number(seconds)||0);
    const minutes = Math.floor(value/60);
    const secs = Math.floor(value%60);
    const millis = Math.floor(
        (value-Math.floor(value))*1000
    );

    return String(minutes).padStart(2,'0') + ':' +
        String(secs).padStart(2,'0') + '.' +
        String(millis).padStart(3,'0');
}

function formatShortTime(seconds){
    if(seconds < 60){
        return seconds.toFixed(
            seconds % 1 ? 1 : 0
        ) + 's';
    }

    const m = Math.floor(seconds/60);
    const s = Math.floor(seconds%60);

    return String(m).padStart(2,'0') + ':' +
        String(s).padStart(2,'0');
}

function showToast(message,error=false){
    els.toast.textContent = message;
    els.toast.style.display = 'block';
    els.toast.style.borderColor =
        error ? '#991b1b' : '#475569';

    clearTimeout(showToast.timer);

    showToast.timer = setTimeout(() => {
        els.toast.style.display = 'none';
    },2800);
}

function setStatus(message){
    els.statusText.textContent = message;
}

function typeLabel(type){
    return type === 'text'
        ? 'テキスト'
        : type === 'box'
            ? '強調枠'
            : 'スキップ';
}

function typeColor(type){
    return type === 'text'
        ? '#60a5fa'
        : type === 'box'
            ? '#a78bfa'
            : '#f97316';
}

function hexToRgba(hex,alpha){
    const clean = String(hex).replace('#','');

    if(clean.length !== 6){
        return `rgba(59,130,246,${alpha})`;
    }

    const r = parseInt(clean.slice(0,2),16);
    const g = parseInt(clean.slice(2,4),16);
    const b = parseInt(clean.slice(4,6),16);

    return `rgba(${r},${g},${b},${alpha})`;
}

function sanitizeFilename(name){
    return String(name)
        .replace(/[\\/:*?"<>|]/g,'_')
        .trim() || 'project';
}

/* =========================================================
 * プロジェクト
 * ======================================================= */

function emptyProject(name='新規プロジェクト'){
    return {
        appVersion:APP_VERSION,
        id:uid('project'),
        name,
        createdAt:new Date().toISOString(),
        updatedAt:new Date().toISOString(),
        videoName:'',
        duration:0,
        elements:[],
        connections:[]
    };
}

function createElement(type,start,end,overrides={}){
    const defaults = {
        id:uid('element'),
        type,
        name:type === 'text'
            ? 'テキスト'
            : type === 'box'
                ? '強調枠'
                : 'スキップ',

        text:type === 'text' ? 'テキスト' : '',

        start,
        end,

        x:10,
        y:10,

        w:type === 'text' ? 28 : 32,
        h:type === 'text' ? 12 : 30,

        color:type === 'skip'
            ? '#f97316'
            : '#3b82f6',

        fontSize:24,
        fontWeight:'700'
    };

    return Object.assign(defaults,overrides);
}

function normalizeElement(raw,duration){
    const type =
        ['text','box','skip'].includes(raw?.type)
            ? raw.type
            : 'text';

    let start = Number(raw?.start);
    let end = Number(raw?.end);

    if(!Number.isFinite(start)) start = 0;
    if(!Number.isFinite(end)) end = start + 3;

    const max =
        duration > 0
            ? duration
            : Math.max(end,3);

    start = clamp(start,0,max);

    end = clamp(
        end,
        start + .05,
        max
    );

    if(end <= start){
        end = Math.min(max,start+.05);
    }

    return {
        id:String(raw?.id || uid('element')),
        type,
        name:String(
            raw?.name ||
            typeLabel(type)
        ),
        text:String(raw?.text || ''),
        start,
        end,
        x:clamp(Number(raw?.x)||0,0,100),
        y:clamp(Number(raw?.y)||0,0,100),
        w:clamp(Number(raw?.w)||20,1,100),
        h:clamp(Number(raw?.h)||15,1,100),
        color:COLORS.includes(raw?.color)
            ? raw.color
            : '#3b82f6',
        fontSize:clamp(
            Number(raw?.fontSize)||24,
            8,
            100
        ),
        fontWeight:String(
            raw?.fontWeight || '700'
        )
    };
}

function normalizeProject(project){
    const p = emptyProject();

    if(!project || typeof project !== 'object'){
        return p;
    }

    p.id = String(
        project.id || uid('project')
    );

    p.name = String(
        project.name || 'プロジェクト'
    );

    p.createdAt =
        project.createdAt || p.createdAt;

    p.updatedAt =
        project.updatedAt || p.updatedAt;

    p.videoName = String(
        project.videoName || ''
    );

    p.duration =
        Number(project.duration) || 0;

    p.elements = Array.isArray(project.elements)
        ? project.elements.map(
            e => normalizeElement(
                e,
                p.duration
            )
        )
        : [];

    const ids = new Set(
        p.elements.map(e => e.id)
    );

    p.connections = Array.isArray(project.connections)
        ? project.connections
            .filter(c =>
                c &&
                ids.has(c.from) &&
                ids.has(c.to) &&
                c.from !== c.to
            )
            .map(c => ({
                id:String(
                    c.id || uid('connection')
                ),
                from:String(c.from),
                to:String(c.to),
                color:COLORS.includes(c.color)
                    ? c.color
                    : '#64748b',
                width:clamp(
                    Number(c.width)||2,
                    1,
                    8
                ),
                dash:c.dash === 'dashed'
                    ? 'dashed'
                    : 'solid'
            }))
        : [];

    return p;
}

function getElement(id){
    return state.project?.elements.find(
        e => e.id === id
    ) || null;
}

function selectedElements(){
    return [...state.selectedIds]
        .map(getElement)
        .filter(Boolean);
}

function canEdit(){
    return Boolean(
        state.editorReady &&
        state.project &&
        state.duration > 0
    );
}

function visible(element){
    return (
        state.currentTime >= element.start-.0005 &&
        state.currentTime <= element.end+.0005
    );
}

function markDirty(){
    if(!state.project) return;

    state.project.updatedAt =
        new Date().toISOString();

    els.saveProjectBtn.disabled = !canEdit();
    els.saveProjectBtn.textContent = '保存*';

    clearTimeout(state.saveTimer);

    state.saveTimer = setTimeout(() => {
        if(canEdit()){
            saveCurrentProject(false)
                .catch(console.error);
        }
    },1000);
}

function markClean(){
    els.saveProjectBtn.textContent = '保存';
}

/* =========================================================
 * IndexedDB
 * ======================================================= */

function openDB(){
    return new Promise((resolve,reject) => {

        const request =
            indexedDB.open(
                DB_NAME,
                DB_VERSION
            );

        request.onupgradeneeded = () => {
            const db = request.result;

            if(!db.objectStoreNames.contains(
                PROJECT_STORE
            )){
                db.createObjectStore(
                    PROJECT_STORE,
                    {keyPath:'id'}
                );
            }
        };

        request.onsuccess = () =>
            resolve(request.result);

        request.onerror = () =>
            reject(request.error);
    });
}

function dbPut(record){
    return openDB().then(db =>
        new Promise((resolve,reject) => {

            const tx = db.transaction(
                PROJECT_STORE,
                'readwrite'
            );

            tx.objectStore(
                PROJECT_STORE
            ).put(record);

            tx.oncomplete = () => {
                db.close();
                resolve();
            };

            tx.onerror = () => {
                db.close();
                reject(tx.error);
            };
        })
    );
}

function dbGetAll(){
    return openDB().then(db =>
        new Promise((resolve,reject) => {

            const tx = db.transaction(
                PROJECT_STORE,
                'readonly'
            );

            const req =
                tx.objectStore(
                    PROJECT_STORE
                ).getAll();

            req.onsuccess = () => {
                const result = req.result || [];
                db.close();
                resolve(result);
            };

            req.onerror = () => {
                db.close();
                reject(req.error);
            };
        })
    );
}

function dbGet(id){
    return openDB().then(db =>
        new Promise((resolve,reject) => {

            const req =
                db.transaction(
                    PROJECT_STORE,
                    'readonly'
                )
                .objectStore(
                    PROJECT_STORE
                )
                .get(id);

            req.onsuccess = () => {
                const result = req.result || null;
                db.close();
                resolve(result);
            };

            req.onerror = () => {
                db.close();
                reject(req.error);
            };
        })
    );
}

function dbDelete(id){
    return openDB().then(db =>
        new Promise((resolve,reject) => {

            const tx = db.transaction(
                PROJECT_STORE,
                'readwrite'
            );

            tx.objectStore(
                PROJECT_STORE
            ).delete(id);

            tx.oncomplete = () => {
                db.close();
                resolve();
            };

            tx.onerror = () => {
                db.close();
                reject(tx.error);
            };
        })
    );
}

async function saveCurrentProject(manual=true){
    if(!state.project || !state.videoBlob){
        if(manual){
            showToast(
                '保存する動画がありません',
                true
            );
        }
        return;
    }

    const project =
        structuredClone(state.project);

    project.updatedAt =
        new Date().toISOString();

    await dbPut({
        id:project.id,
        project,
        videoBlob:state.videoBlob,
        savedAt:new Date().toISOString()
    });

    state.project.updatedAt =
        project.updatedAt;

    markClean();

    if(manual){
        showToast('保存しました');
    }
}

async function listStoredProjects(){
    els.storageList.innerHTML =
        '<div style="color:#94a3b8">読み込み中…</div>';

    try{
        const records = await dbGetAll();

        records.sort((a,b) =>
            String(b.savedAt)
                .localeCompare(
                    String(a.savedAt)
                )
        );

        if(!records.length){
            els.storageList.innerHTML =
                '<div style="color:#94a3b8">保存データはありません。</div>';
            return;
        }

        els.storageList.innerHTML = '';

        for(const record of records){

            const item =
                document.createElement('div');

            item.className =
                'storage-item';

            const info =
                document.createElement('div');

            info.className = 'info';

            const name =
                document.createElement('div');

            name.className = 'name';

            name.textContent =
                record.project?.name ||
                'プロジェクト';

            const meta =
                document.createElement('div');

            meta.className = 'meta';

            meta.textContent =
                (record.project?.videoName || '') +
                ' / ' +
                formatTime(
                    record.project?.duration || 0
                ) +
                ' / ' +
                new Date(
                    record.savedAt
                ).toLocaleString();

            info.append(name,meta);

            const load =
                document.createElement('button');

            load.className =
                'btn small';

            load.textContent = '読込';

            load.addEventListener(
                'click',
                async () => {
                    try{
                        await loadStoredProject(
                            record.id
                        );
                        closeStorageModal();
                    }catch(error){
                        console.error(error);
                        showToast(
                            '読み込みに失敗しました',
                            true
                        );
                    }
                }
            );

            const del =
                document.createElement('button');

            del.className =
                'btn small danger';

            del.textContent = '削除';

            del.addEventListener(
                'click',
                async () => {

                    if(!confirm(
                        'この保存データを削除しますか？'
                    )){
                        return;
                    }

                    await dbDelete(record.id);
                    listStoredProjects();
                }
            );

            item.append(
                info,
                load,
                del
            );

            els.storageList.appendChild(item);
        }

    }catch(error){
        console.error(error);
        els.storageList.innerHTML =
            '<div style="color:#fca5a5">保存データを読み込めませんでした。</div>';
    }
}

async function loadStoredProject(id){

    const record = await dbGet(id);

    if(!record || !record.project){
        throw new Error(
            '保存データがありません'
        );
    }

    if(!record.videoBlob){
        throw new Error(
            '動画データがありません'
        );
    }

    releaseVideoUrl();

    state.videoBlob =
        record.videoBlob;

    state.project =
        normalizeProject(
            record.project
        );

    state.selectedIds.clear();
    state.selectedConnectionId = null;
    cancelConnectionMode();

    const url =
        URL.createObjectURL(
            state.videoBlob
        );

    state.videoUrl = url;

    els.video.src = url;
    els.video.load();

    await new Promise((resolve,reject) => {

        const onMeta = () => {
            cleanup();
            resolve();
        };

        const onError = () => {
            cleanup();
            reject(
                new Error(
                    '動画を読み込めませんでした'
                )
            );
        };

        const cleanup = () => {
            els.video.removeEventListener(
                'loadedmetadata',
                onMeta
            );

            els.video.removeEventListener(
                'error',
                onError
            );
        };

        els.video.addEventListener(
            'loadedmetadata',
            onMeta
        );

        els.video.addEventListener(
            'error',
            onError
        );
    });

    state.duration =
        Number(els.video.duration);

    if(!Number.isFinite(
        state.duration
    ) || state.duration <= 0){
        throw new Error(
            '動画時間を取得できません'
        );
    }

    state.project.duration =
        state.duration;

    state.project.videoName =
        state.videoBlob.name ||
        state.project.videoName;

    for(const element of
        state.project.elements){

        element.start =
            clamp(
                element.start,
                0,
                state.duration
            );

        element.end =
            clamp(
                element.end,
                element.start+.05,
                state.duration
            );
    }

    state.editorReady = true;
    state.currentTime = 0;

    els.video.currentTime = 0;
    els.emptyVideo.style.display = 'none';

    enableEditor();

    setStatus(
        '編集可能：' +
        state.project.name +
        ' / ' +
        state.project.videoName
    );

    renderAll();
    markClean();

    showToast('プロジェクトを読み込みました');
}

/* =========================================================
 * 動画
 * ======================================================= */

function releaseVideoUrl(){
    try{
        els.video.pause();
        els.video.removeAttribute('src');
        els.video.load();
    }catch(_){}

    if(state.videoUrl){
        try{
            URL.revokeObjectURL(
                state.videoUrl
            );
        }catch(_){}
    }

    state.videoUrl = '';
}

function enableEditor(){
    els.playBtn.disabled = false;
    els.stopBtn.disabled = false;
    els.saveProjectBtn.disabled = false;
}

function disableEditor(){
    els.playBtn.disabled = true;
    els.stopBtn.disabled = true;
    els.saveProjectBtn.disabled = true;

    els.emptyVideo.style.display = 'block';

    els.videoOverlay.innerHTML = '';
    els.tracksContainer.innerHTML = '';
    els.connectionSvg.innerHTML = '';
    els.connectionPreviewSvg.innerHTML = '';

    els.playhead.style.display = 'none';
}

function handleVideoFile(file){

    if(!file || !file.type.startsWith('video/')){
        showToast(
            '動画ファイルを選択してください',
            true
        );
        return;
    }

    releaseVideoUrl();

    state.videoBlob = file;
    state.duration = 0;
    state.editorReady = false;

    disableEditor();

    const url =
        URL.createObjectURL(file);

    state.videoUrl = url;

    els.video.src = url;
    els.video.load();

    setStatus('動画を読み込んでいます…');

    els.video.addEventListener(
        'loadedmetadata',
        onVideoMetadata,
        {once:true}
    );

    els.video.addEventListener(
        'error',
        () => {
            state.editorReady = false;
            setStatus(
                '動画を読み込めませんでした'
            );
            showToast(
                '動画を読み込めませんでした',
                true
            );
        },
        {once:true}
    );
}

function onVideoMetadata(){

    const duration =
        Number(els.video.duration);

    if(!Number.isFinite(duration) ||
       duration <= 0){

        showToast(
            '動画時間を取得できません',
            true
        );

        return;
    }

    state.duration = duration;

    if(!state.project){
        state.project =
            emptyProject();
    }

    state.project.duration =
        duration;

    state.project.videoName =
        state.videoBlob?.name ||
        state.project.videoName ||
        'video';

    state.editorReady = true;
    state.currentTime = 0;

    els.video.currentTime = 0;
    els.emptyVideo.style.display = 'none';

    enableEditor();

    setStatus(
        '編集可能：' +
        state.project.videoName +
        ' / ' +
        formatTime(duration)
    );

    renderAll();

    saveCurrentProject(false)
        .catch(console.error);
}

function resetToNewProject(name){

    releaseVideoUrl();

    state.videoBlob = null;
    state.project =
        emptyProject(name);

    state.duration = 0;
    state.currentTime = 0;
    state.editorReady = false;

    state.selectedIds.clear();
    state.selectedConnectionId = null;

    cancelConnectionMode();

    els.videoFile.value = '';

    disableEditor();

    els.timeReadout.textContent =
        '00:00.000 / 00:00.000';

    setStatus(
        '動画を読み込んでください'
    );

    renderAll();
}

/* =========================================================
 * タイムライン geometry
 *
 * ここが今回の重要修正箇所。
 *
 * 以前:
 *   120px固定値
 *   timelineContent.clientWidth
 *   CSS flex幅
 *   各要素の%座標
 *
 * が混在していた。
 *
 * 今回:
 *   axisTrack.getBoundingClientRect()
 *   track-lane.getBoundingClientRect()
 *   element-bar.getBoundingClientRect()
 *
 * を基準にする。
 * ======================================================= */

function getTimelineGeometry(){

    const contentRect =
        els.timelineContent.getBoundingClientRect();

    const axisRect =
        els.axisTrack.getBoundingClientRect();

    return {
        contentRect,
        axisRect,

        left:axisRect.left -
            contentRect.left,

        width:axisRect.width,

        top:axisRect.top -
            contentRect.top
    };
}

function timeToTimelineX(time){

    const g =
        getTimelineGeometry();

    if(!state.duration ||
       g.width <= 0){
        return g.left;
    }

    return g.left +
        clamp(
            time/state.duration,
            0,
            1
        ) * g.width;
}

function timelineXToTime(clientX){

    const g =
        getTimelineGeometry();

    if(g.width <= 0 ||
       !state.duration){
        return 0;
    }

    const x =
        clientX -
        g.axisRect.left;

    return clamp(
        x/g.width * state.duration,
        0,
        state.duration
    );
}

/* =========================================================
 * Timeline
 * ======================================================= */

function timelineWidth(){

    const scrollWidth =
        els.timelineScroll.clientWidth;

    const base =
        Math.max(700,scrollWidth-2);

    const zoom =
        Number(els.zoomRange.value) || 1;

    return Math.max(
        base,
        Math.round(base*zoom)
    );
}

function chooseTickStep(duration,width){

    const target = 90;

    const approx =
        duration /
        Math.max(1,width/target);

    const candidates = [
        .1,.25,.5,1,2,5,10,15,
        30,60,120,300,600
    ];

    return candidates.find(
        x => x >= approx
    ) || 600;
}

function renderAxis(){

    els.axisTrack.innerHTML = '';

    if(!state.duration) return;

    const width =
        els.axisTrack.clientWidth;

    const step =
        chooseTickStep(
            state.duration,
            width
        );

    for(
        let t=0;
        t<=state.duration+.0001;
        t+=step
    ){

        const tick =
            document.createElement('div');

        tick.className = 'tick';

        tick.style.left =
            (
                t/state.duration*100
            ) + '%';

        tick.textContent =
            formatShortTime(t);

        els.axisTrack.appendChild(tick);
    }
}

function renderTimeline(){

    els.tracksContainer.innerHTML = '';

    if(!state.project ||
       state.duration <= 0){

        els.timelineContent.style.width =
            '100%';

        return;
    }

    const width =
        timelineWidth();

    els.timelineContent.style.width =
        width + 'px';

    const groups = [
        {type:'text',label:'テキスト'},
        {type:'box',label:'強調枠'},
        {type:'skip',label:'スキップ'}
    ];

    for(const group of groups){

        const row =
            document.createElement('div');

        row.className = 'track-row';

        const label =
            document.createElement('div');

        label.className =
            'track-label';

        const dot =
            document.createElement('span');

        dot.className =
            'type-dot';

        dot.style.background =
            typeColor(group.type);

        label.append(
            dot,
            document.createTextNode(
                group.label
            )
        );

        row.appendChild(label);

        const lane =
            document.createElement('div');

        lane.className =
            'track-lane';

        const grid =
            document.createElement('div');

        grid.className =
            'track-grid';

        lane.appendChild(grid);

        for(
            const element of
            state.project.elements.filter(
                e => e.type === group.type
            )
        ){

            lane.appendChild(
                createElementBar(element)
            );
        }

        row.appendChild(lane);

        els.tracksContainer.appendChild(row);
    }

    renderAxis();
}

function createElementBar(element){

    const bar =
        document.createElement('div');

    bar.className =
        'element-bar';

    bar.dataset.id =
        element.id;

    if(state.selectedIds.has(
        element.id
    )){

        bar.classList.add(
            state.selectedIds.size > 1
                ? 'multi-selected'
                : 'selected'
        );
    }

    if(
        state.connectionMode &&
        state.connectionSourceId ===
        element.id
    ){
        bar.classList.add(
            'connection-source'
        );
    }

    /*
     * start/endは同じlaneの幅に対して
     * 必ず割合で指定する。
     *
     * ここでtimeline全体幅を使わない。
     */
    bar.style.left =
        (
            element.start /
            state.duration *
            100
        ) + '%';

    bar.style.width =
        (
            Math.max(
                .001,
                (
                    element.end -
                    element.start
                ) /
                state.duration
            ) * 100
        ) + '%';

    bar.style.color =
        element.color;

    bar.style.background =
        hexToRgba(
            element.color,
            .2
        );

    const label =
        document.createElement('span');

    label.className =
        'bar-label';

    label.textContent =
        element.name +
        '  ' +
        formatTime(element.start) +
        ' ～ ' +
        formatTime(element.end);

    bar.appendChild(label);

    const left =
        document.createElement('span');

    left.className =
        'handle left';

    left.dataset.edge = 'left';

    const right =
        document.createElement('span');

    right.className =
        'handle right';

    right.dataset.edge = 'right';

    bar.append(left,right);

    bar.addEventListener(
        'pointerdown',
        beginBarPointer
    );

    bar.addEventListener(
        'click',
        event => {
            event.stopPropagation();

            if(state.connectionMode){
                handleConnectionClick(
                    element.id
                );
                return;
            }

            selectElement(
                element.id,
                event.shiftKey
            );
        }
    );

    bar.addEventListener(
        'dblclick',
        event => {
            event.stopPropagation();

            if(!state.connectionMode){
                openElementModal(
                    element.id
                );
            }
        }
    );

    bar.addEventListener(
        'contextmenu',
        event => {
            event.preventDefault();
            event.stopPropagation();

            if(!state.selectedIds.has(
                element.id
            )){
                selectElement(
                    element.id,
                    event.shiftKey
                );
            }

            openContextMenu(
                event.clientX,
                event.clientY,
                element.id,
                null
            );
        }
    );

    bar.title =
        element.name +
        '\n開始: ' +
        formatTime(element.start) +
        '\n終了: ' +
        formatTime(element.end);

    return bar;
}

/* =========================================================
 * 要素選択
 * ======================================================= */

function selectElement(id,additive=false){

    const element =
        getElement(id);

    if(!element) return;

    if(!additive){
        state.selectedIds.clear();
        state.selectedConnectionId = null;
    }

    if(
        additive &&
        state.selectedIds.has(id)
    ){
        state.selectedIds.delete(id);
    }else{
        state.selectedIds.add(id);
    }

    /*
     * Shift選択時にseek()→renderAll()して
     * DOMを作り直すとpointerイベント中の
     * elementが破棄される問題を避ける。
     */
    if(!additive){
        seek(element.start);
    }else{
        state.currentTime =
            clamp(
                element.start,
                0,
                state.duration
            );

        try{
            els.video.currentTime =
                state.currentTime;
        }catch(_){}

        renderAll();
    }
}

function updateSelectionHint(){

    const count =
        state.selectedIds.size;

    if(!count){
        els.selectionHint.textContent = '';
        return;
    }

    els.selectionHint.textContent =
        count === 1
            ? '1要素選択中'
            : `${count}要素選択中`;
}

/* =========================================================
 * 動画上要素
 * ======================================================= */

function renderOverlay(){

    els.videoOverlay.innerHTML = '';

    if(!canEdit()) return;

    for(
        const element of
        state.project.elements
    ){

        if(!visible(element)){
            continue;
        }

        const node =
            document.createElement('div');

        node.className =
            'overlay-element';

        node.dataset.id =
            element.id;

        if(state.selectedIds.has(
            element.id
        )){

            node.classList.add(
                state.selectedIds.size > 1
                    ? 'multi-selected'
                    : 'selected'
            );
        }

        node.style.left =
            element.x + '%';

        node.style.top =
            element.y + '%';

        node.style.width =
            element.w + '%';

        node.style.height =
            element.h + '%';

        node.style.color =
            element.color;

        if(element.type === 'text'){

            const content =
                document.createElement('div');

            content.className =
                'overlay-text';

            content.textContent =
                element.text ||
                element.name;

            content.style.fontSize =
                element.fontSize + 'px';

            content.style.fontWeight =
                element.fontWeight;

            content.style.color =
                element.color;

            node.appendChild(content);

        }else if(element.type === 'box'){

            const box =
                document.createElement('div');

            box.className =
                'overlay-box';

            box.style.color =
                element.color;

            node.appendChild(box);

        }else{

            const skip =
                document.createElement('div');

            skip.className =
                'overlay-skip';

            skip.textContent =
                'SKIP';

            node.appendChild(skip);
        }

        for(
            const side of
            ['tl','tr','bl','br']
        ){

            const handle =
                document.createElement('div');

            handle.className =
                'resize-handle ' + side;

            handle.dataset.handle =
                side;

            node.appendChild(handle);
        }

        node.addEventListener(
            'pointerdown',
            beginOverlayPointer
        );

        node.addEventListener(
            'click',
            event => {
                event.stopPropagation();

                if(state.connectionMode){
                    handleConnectionClick(
                        element.id
                    );
                    return;
                }

                selectElement(
                    element.id,
                    event.shiftKey
                );
            }
        );

        node.addEventListener(
            'dblclick',
            event => {
                event.stopPropagation();

                if(!state.connectionMode){
                    openElementModal(
                        element.id
                    );
                }
            }
        );

        node.addEventListener(
            'contextmenu',
            event => {

                event.preventDefault();
                event.stopPropagation();

                if(!state.selectedIds.has(
                    element.id
                )){
                    selectElement(
                        element.id,
                        event.shiftKey
                    );
                }

                openContextMenu(
                    event.clientX,
                    event.clientY,
                    element.id,
                    null
                );
            }
        );

        els.videoOverlay.appendChild(node);
    }

    els.emptyVideo.style.display =
        'none';
}

function beginOverlayPointer(event){

    if(!canEdit()) return;

    event.preventDefault();
    event.stopPropagation();

    const node =
        event.currentTarget;

    const id =
        node.dataset.id;

    const element =
        getElement(id);

    if(!element) return;

    if(state.connectionMode){
        return;
    }

    const handle =
        event.target.closest(
            '.resize-handle'
        );

    const additive =
        event.shiftKey;

    if(!state.selectedIds.has(id)){

        /*
         * ここではselectElement()を呼ばず、
         * DOMを再生成しない。
         */
        if(!additive){
            state.selectedIds.clear();
        }

        state.selectedIds.add(id);
        state.selectedConnectionId = null;
    }else if(
        !additive &&
        state.selectedIds.size > 1
    ){
        state.selectedIds.clear();
        state.selectedIds.add(id);
    }

    const rect =
        els.videoOverlay.getBoundingClientRect();

    state.dragging = {
        mode:handle
            ? 'resize'
            : 'move',

        handle:
            handle?.dataset.handle || '',

        id,

        startClientX:event.clientX,
        startClientY:event.clientY,

        rect,

        original:{
            x:element.x,
            y:element.y,
            w:element.w,
            h:element.h
        }
    };

    try{
        node.setPointerCapture(
            event.pointerId
        );
    }catch(_){}

    window.addEventListener(
        'pointermove',
        moveOverlayPointer
    );

    window.addEventListener(
        'pointerup',
        endOverlayPointer,
        {once:true}
    );

    renderOverlay();
    renderTimeline();
    renderConnections();
    updateSelectionHint();
}

function moveOverlayPointer(event){

    if(!state.dragging) return;

    const d =
        state.dragging;

    const element =
        getElement(d.id);

    if(!element) return;

    const dx =
        (
            event.clientX -
            d.startClientX
        ) /
        d.rect.width *
        100;

    const dy =
        (
            event.clientY -
            d.startClientY
        ) /
        d.rect.height *
        100;

    if(d.mode === 'move'){

        element.x =
            clamp(
                d.original.x + dx,
                0,
                100 - element.w
            );

        element.y =
            clamp(
                d.original.y + dy,
                0,
                100 - element.h
            );

    }else{

        resizeElementByHandle(
            element,
            d.original,
            d.handle,
            dx,
            dy
        );
    }

    markDirty();

    renderOverlay();
    renderTimeline();
    renderConnections();
}

function resizeElementByHandle(
    element,
    original,
    handle,
    dx,
    dy
){

    const minW = 2;
    const minH = 2;

    let x = original.x;
    let y = original.y;
    let w = original.w;
    let h = original.h;

    if(handle.includes('l')){

        const newX =
            clamp(
                original.x + dx,
                0,
                original.x +
                original.w -
                minW
            );

        x = newX;

        w =
            original.w +
            (
                original.x -
                newX
            );
    }

    if(handle.includes('r')){

        w =
            clamp(
                original.w + dx,
                minW,
                100 - original.x
            );
    }

    if(handle.includes('t')){

        const newY =
            clamp(
                original.y + dy,
                0,
                original.y +
                original.h -
                minH
            );

        y = newY;

        h =
            original.h +
            (
                original.y -
                newY
            );
    }

    if(handle.includes('b')){

        h =
            clamp(
                original.h + dy,
                minH,
                100 - original.y
            );
    }

    element.x =
        clamp(x,0,100-minW);

    element.y =
        clamp(y,0,100-minH);

    element.w =
        clamp(
            w,
            minW,
            100-element.x
        );

    element.h =
        clamp(
            h,
            minH,
            100-element.y
        );
}

function endOverlayPointer(){
    state.dragging = null;

    window.removeEventListener(
        'pointermove',
        moveOverlayPointer
    );

    renderAll();
}

/* =========================================================
 * タイムライン要素移動
 * ======================================================= */

function beginBarPointer(event){

    if(!canEdit()) return;

    event.preventDefault();
    event.stopPropagation();

    const bar =
        event.currentTarget;

    const id =
        bar.dataset.id;

    const element =
        getElement(id);

    if(!element) return;

    if(state.connectionMode){
        return;
    }

    const lane =
        bar.parentElement;

    const rect =
        lane.getBoundingClientRect();

    const edge =
        event.target.closest(
            '.handle'
        )?.dataset.edge || '';

    /*
     * 選択状態だけ更新して
     * pointerdown中のDOMを再生成しない。
     */
    if(!state.selectedIds.has(id)){

        if(!event.shiftKey){
            state.selectedIds.clear();
        }

        state.selectedIds.add(id);
        state.selectedConnectionId = null;

        updateSelectionHint();
    }

    state.dragging = {
        mode:edge
            ? 'timeline-resize'
            : 'timeline-move',

        edge,
        id,

        rect,

        original:{
            start:element.start,
            end:element.end
        },

        startClientX:event.clientX
    };

    try{
        bar.setPointerCapture(
            event.pointerId
        );
    }catch(_){}

    window.addEventListener(
        'pointermove',
        moveBarPointer
    );

    window.addEventListener(
        'pointerup',
        endBarPointer,
        {once:true}
    );
}

function moveBarPointer(event){

    if(!state.dragging ||
       !state.project){
        return;
    }

    const d =
        state.dragging;

    const element =
        getElement(d.id);

    if(!element) return;

    /*
     * laneの実幅を使う。
     * timelineContent幅を使わない。
     */
    const deltaTime =
        (
            event.clientX -
            d.startClientX
        ) /
        d.rect.width *
        state.duration;

    const minDuration = .05;

    if(d.mode === 'timeline-move'){

        const duration =
            d.original.end -
            d.original.start;

        let start =
            d.original.start +
            deltaTime;

        start =
            clamp(
                start,
                0,
                Math.max(
                    0,
                    state.duration -
                    duration
                )
            );

        element.start = start;
        element.end =
            start + duration;

    }else if(
        d.edge === 'left'
    ){

        element.start =
            clamp(
                d.original.start +
                deltaTime,
                0,
                d.original.end -
                minDuration
            );

    }else{

        element.end =
            clamp(
                d.original.end +
                deltaTime,
                d.original.start +
                minDuration,
                state.duration
            );
    }

    markDirty();

    renderTimeline();
    renderConnections();
    renderOverlay();
    updatePlayhead();
}

function endBarPointer(){

    state.dragging = null;

    window.removeEventListener(
        'pointermove',
        moveBarPointer
    );

    renderAll();
}

/* =========================================================
 * 接続線
 *
 * 重要:
 * connectionAnchor()で時間→座標変換を
 * 再計算しない。
 *
 * 実際に描画されたelement-barの
 * getBoundingClientRect()を使う。
 *
 * これにより:
 * - zoom
 * - label幅
 * - scrollbar
 * - responsive
 * - CSS変化
 * - browser rounding
 *
 * の差が接続線に伝播しない。
 * ======================================================= */

function getBarElement(id){

    return els.tracksContainer.querySelector(
        `.element-bar[data-id="${CSS.escape(id)}"]`
    );
}

function getElementBarAnchor(id,side){

    const bar =
        getBarElement(id);

    if(!bar){
        return null;
    }

    const barRect =
        bar.getBoundingClientRect();

    const contentRect =
        els.timelineContent.getBoundingClientRect();

    return {
        x:
            (
                side === 'right'
                    ? barRect.right
                    : barRect.left
            ) -
            contentRect.left,

        y:
            (
                barRect.top +
                barRect.height/2
            ) -
            contentRect.top
    };
}

function renderConnections(){

    els.connectionSvg.innerHTML = '';
    els.connectionPreviewSvg.innerHTML = '';

    const width =
        els.timelineContent.scrollWidth;

    const height =
        els.timelineContent.scrollHeight;

    for(
        const svg of [
            els.connectionSvg,
            els.connectionPreviewSvg
        ]
    ){

        svg.setAttribute(
            'width',
            width
        );

        svg.setAttribute(
            'height',
            height
        );

        svg.setAttribute(
            'viewBox',
            `0 0 ${width} ${height}`
        );
    }

    if(!state.project) return;

    for(
        const connection of
        state.project.connections
    ){

        const a =
            getElementBarAnchor(
                connection.from,
                'right'
            );

        const b =
            getElementBarAnchor(
                connection.to,
                'left'
            );

        if(!a || !b){
            continue;
        }

        drawConnection(
            els.connectionSvg,
            a,
            b,
            connection
        );
    }

    renderConnectionPreview();
}

function drawConnection(
    svg,
    a,
    b,
    connection
){

    const direction =
        b.x >= a.x ? 1 : -1;

    const distance =
        Math.abs(b.x-a.x);

    const bend =
        Math.max(
            35,
            distance*.45
        );

    const cp1x =
        a.x + bend*direction;

    const cp2x =
        b.x - bend*direction;

    const path =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

    path.setAttribute(
        'd',
        `M ${a.x} ${a.y}
         C ${cp1x} ${a.y},
           ${cp2x} ${b.y},
           ${b.x} ${b.y}`
    );

    path.setAttribute(
        'class',
        'connection-path' +
        (
            state.selectedConnectionId ===
            connection.id
                ? ' selected'
                : ''
        )
    );

    path.setAttribute(
        'stroke',
        connection.color ||
        '#64748b'
    );

    path.setAttribute(
        'stroke-width',
        connection.width || 2
    );

    if(connection.dash === 'dashed'){
        path.setAttribute(
            'stroke-dasharray',
            '6 4'
        );
    }

    path.addEventListener(
        'click',
        event => {

            event.preventDefault();
            event.stopPropagation();

            state.selectedConnectionId =
                connection.id;

            state.selectedIds.clear();

            renderAll();
        }
    );

    path.addEventListener(
        'contextmenu',
        event => {

            event.preventDefault();
            event.stopPropagation();

            state.selectedConnectionId =
                connection.id;

            state.selectedIds.clear();

            renderAll();

            openContextMenu(
                event.clientX,
                event.clientY,
                null,
                connection.id
            );
        }
    );

    svg.appendChild(path);

    const end =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );

    end.setAttribute(
        'cx',
        b.x
    );

    end.setAttribute(
        'cy',
        b.y
    );

    end.setAttribute(
        'r',
        '4'
    );

    end.setAttribute(
        'class',
        'connection-endpoint'
    );

    end.setAttribute(
        'stroke',
        connection.color ||
        '#64748b'
    );

    svg.appendChild(end);
}

/* =========================================================
 * 接続モード
 * ======================================================= */

function startConnectionMode(sourceId=null){

    if(!canEdit()){
        showToast(
            '先に動画を読み込んでください',
            true
        );
        return;
    }

    if(sourceId &&
       !getElement(sourceId)){
        sourceId = null;
    }

    state.connectionMode = true;
    state.connectionSourceId =
        sourceId;

    els.connectionMode.classList.remove(
        'hidden'
    );

    els.cancelConnectionBtn.classList.remove(
        'hidden'
    );

    renderAll();

    if(sourceId){
        showToast(
            '接続先の要素をクリックしてください'
        );
    }else{
        showToast(
            '接続元の要素をクリックしてください'
        );
    }
}

function cancelConnectionMode(){

    state.connectionMode = false;
    state.connectionSourceId = null;

    els.connectionMode.classList.add(
        'hidden'
    );

    els.cancelConnectionBtn.classList.add(
        'hidden'
    );

    renderAll();
}

function handleConnectionClick(id){

    if(!state.connectionMode){
        return;
    }

    if(!state.connectionSourceId){

        state.connectionSourceId = id;

        renderAll();

        showToast(
            '接続先の要素をクリックしてください'
        );

        return;
    }

    if(
        state.connectionSourceId === id
    ){
        showToast(
            '同じ要素には接続できません',
            true
        );
        return;
    }

    createConnection(
        state.connectionSourceId,
        id
    );

    cancelConnectionMode();
}

function createConnection(from,to){

    if(!state.project) return;

    if(from === to){
        showToast(
            '同じ要素には接続できません',
            true
        );
        return;
    }

    const exists =
        state.project.connections.some(
            c =>
                (
                    c.from === from &&
                    c.to === to
                ) ||
                (
                    c.from === to &&
                    c.to === from
                )
        );

    if(exists){
        showToast(
            'この2要素はすでに接続されています',
            true
        );
        return;
    }

    const source =
        getElement(from);

    if(!source) return;

    state.project.connections.push({
        id:uid('connection'),
        from,
        to,
        color:source.color,
        width:2,
        dash:'solid'
    });

    state.selectedIds.clear();

    markDirty();
    renderAll();

    showToast(
        '要素間を接続しました'
    );
}

function connectSelected(){

    if(state.selectedIds.size !== 2){

        showToast(
            'Shiftを押しながら2つの要素を選択してください',
            true
        );

        return;
    }

    const [from,to] =
        [...state.selectedIds];

    createConnection(
        from,
        to
    );
}

function disconnectSelected(){

    if(!state.project) return;

    if(state.selectedConnectionId){

        const id =
            state.selectedConnectionId;

        state.project.connections =
            state.project.connections.filter(
                c => c.id !== id
            );

        state.selectedConnectionId =
            null;

        markDirty();
        renderAll();

        showToast(
            '接続を解除しました'
        );

        return;
    }

    if(state.selectedIds.size !== 2){

        showToast(
            '2要素または接続線を選択してください',
            true
        );

        return;
    }

    const [a,b] =
        [...state.selectedIds];

    state.project.connections =
        state.project.connections.filter(
            c =>
                !(
                    (
                        c.from === a &&
                        c.to === b
                    ) ||
                    (
                        c.from === b &&
                        c.to === a
                    )
                )
        );

    markDirty();
    renderAll();

    showToast(
        '接続を解除しました'
    );
}

function renderConnectionPreview(){

    if(
        !state.connectionMode ||
        !state.connectionSourceId
    ){
        return;
    }

    const source =
        getElementBarAnchor(
            state.connectionSourceId,
            'right'
        );

    if(!source) return;

    const contentRect =
        els.timelineContent.getBoundingClientRect();

    const x =
        state.connectionPointer.x -
        contentRect.left;

    const y =
        state.connectionPointer.y -
        contentRect.top;

    const direction =
        x >= source.x ? 1 : -1;

    const bend =
        Math.max(
            35,
            Math.abs(x-source.x)*.45
        );

    const path =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

    path.setAttribute(
        'class',
        'connection-preview'
    );

    path.setAttribute(
        'd',
        `M ${source.x} ${source.y}
         C ${source.x + bend*direction} ${source.y},
           ${x - bend*direction} ${y},
           ${x} ${y}`
    );

    els.connectionPreviewSvg.appendChild(
        path
    );
}

/* =========================================================
 * 接続線の書式
 * ======================================================= */

function openConnectionStyleModal(connection){

    if(!connection) return;

    const backdrop =
        document.createElement('div');

    backdrop.className =
        'modal-backdrop';

    backdrop.style.display =
        'flex';

    const modal =
        document.createElement('div');

    modal.className =
        'modal';

    const head =
        document.createElement('div');

    head.className =
        'modal-head';

    const title =
        document.createElement('strong');

    title.textContent =
        '接続線の書式';

    head.appendChild(title);

    const body =
        document.createElement('div');

    body.className =
        'modal-body';

    const colorField =
        document.createElement('div');

    colorField.className =
        'field';

    const colorLabel =
        document.createElement('label');

    colorLabel.textContent =
        '色';

    const colorGrid =
        document.createElement('div');

    colorGrid.className =
        'color-grid';

    let color =
        connection.color ||
        '#64748b';

    for(const c of COLORS){

        const button =
            document.createElement('button');

        button.type = 'button';

        button.className =
            'color-choice' +
            (
                c === color
                    ? ' active'
                    : ''
            );

        button.style.background =
            c;

        button.addEventListener(
            'click',
            () => {

                color = c;

                colorGrid
                    .querySelectorAll(
                        '.color-choice'
                    )
                    .forEach(
                        x =>
                            x.classList.remove(
                                'active'
                            )
                    );

                button.classList.add(
                    'active'
                );
            }
        );

        colorGrid.appendChild(button);
    }

    colorField.append(
        colorLabel,
        colorGrid
    );

    const widthField =
        document.createElement('div');

    widthField.className =
        'field';

    const widthLabel =
        document.createElement('label');

    widthLabel.textContent =
        '太さ';

    const widthInput =
        document.createElement('input');

    widthInput.type =
        'number';

    widthInput.min = '1';
    widthInput.max = '8';
    widthInput.step = '1';

    widthInput.value =
        connection.width || 2;

    widthField.append(
        widthLabel,
        widthInput
    );

    const dashField =
        document.createElement('div');

    dashField.className =
        'field';

    const dashLabel =
        document.createElement('label');

    dashLabel.textContent =
        '線種';

    const dashSelect =
        document.createElement('select');

    dashSelect.innerHTML =
        '<option value="solid">実線</option>' +
        '<option value="dashed">破線</option>';

    dashSelect.value =
        connection.dash || 'solid';

    dashField.append(
        dashLabel,
        dashSelect
    );

    body.append(
        colorField,
        widthField,
        dashField
    );

    const foot =
        document.createElement('div');

    foot.className =
        'modal-foot';

    const cancel =
        document.createElement('button');

    cancel.className =
        'btn';

    cancel.textContent =
        'キャンセル';

    const save =
        document.createElement('button');

    save.className =
        'btn primary';

    save.textContent =
        '保存';

    foot.append(
        cancel,
        save
    );

    modal.append(
        head,
        body,
        foot
    );

    backdrop.appendChild(modal);

    document.body.appendChild(
        backdrop
    );

    const close =
        () => backdrop.remove();

    cancel.addEventListener(
        'click',
        close
    );

    backdrop.addEventListener(
        'click',
        event => {
            if(event.target === backdrop){
                close();
            }
        }
    );

    save.addEventListener(
        'click',
        () => {

            connection.color =
                color;

            connection.width =
                clamp(
                    Number(
                        widthInput.value
                    ) || 2,
                    1,
                    8
                );

            connection.dash =
                dashSelect.value ===
                'dashed'
                    ? 'dashed'
                    : 'solid';

            markDirty();
            renderAll();
            close();

            showToast(
                '接続線の書式を更新しました'
            );
        }
    );
}

/* =========================================================
 * 要素追加・削除
 * ======================================================= */

function addElement(
    type,
    xPercent=20,
    yPercent=20
){

    if(!canEdit()){
        showToast(
            '先に動画を読み込んでください',
            true
        );
        return;
    }

    const start =
        clamp(
            state.currentTime,
            0,
            Math.max(
                0,
                state.duration-.05
            )
        );

    const end =
        Math.min(
            state.duration,
            start +
            Math.min(
                5,
                state.duration-start
            )
        );

    if(end <= start){
        showToast(
            '動画の終端では追加できません',
            true
        );
        return;
    }

    const element =
        createElement(
            type,
            start,
            end,
            {
                x:clamp(
                    xPercent,
                    0,
                    80
                ),
                y:clamp(
                    yPercent,
                    0,
                    80
                )
            }
        );

    state.project.elements.push(
        element
    );

    state.selectedIds.clear();
    state.selectedIds.add(
        element.id
    );

    markDirty();
    renderAll();

    openElementModal(
        element.id
    );
}

function deleteSelected(){

    if(!state.project) return;

    if(state.contextTargetConnectionId){

        const id =
            state.contextTargetConnectionId;

        state.project.connections =
            state.project.connections.filter(
                c => c.id !== id
            );

        state.contextTargetConnectionId =
            null;

        state.selectedConnectionId =
            null;

        markDirty();
        renderAll();

        showToast(
            '接続を削除しました'
        );

        return;
    }

    const ids =
        new Set(
            state.selectedIds
        );

    if(state.contextTargetId){
        ids.add(
            state.contextTargetId
        );
    }

    if(!ids.size){
        return;
    }

    state.project.elements =
        state.project.elements.filter(
            e => !ids.has(e.id)
        );

    state.project.connections =
        state.project.connections.filter(
            c =>
                !ids.has(c.from) &&
                !ids.has(c.to)
        );

    state.selectedIds.clear();
    state.selectedConnectionId =
        null;

    markDirty();
    renderAll();

    showToast(
        '要素を削除しました'
    );
}

/* =========================================================
 * 要素編集
 * ======================================================= */

function openElementModal(id){

    if(!canEdit()) return;

    const element =
        getElement(id);

    if(!element) return;

    state.modalElementId =
        id;

    state.editingColor =
        element.color;

    els.elementModalTitle.textContent =
        typeLabel(element.type) +
        'を編集';

    els.elementName.value =
        element.name;

    els.elementText.value =
        element.text;

    els.elementStart.value =
        element.start.toFixed(3);

    els.elementEnd.value =
        element.end.toFixed(3);

    els.elementX.value =
        element.x.toFixed(1);

    els.elementY.value =
        element.y.toFixed(1);

    els.elementW.value =
        element.w.toFixed(1);

    els.elementH.value =
        element.h.toFixed(1);

    els.elementFontSize.value =
        element.fontSize;

    els.elementFontWeight.value =
        element.fontWeight;

    const text =
        element.type === 'text';

    els.textField.style.display =
        text ? 'grid' : 'none';

    els.fontSizeField.style.display =
        text ? 'grid' : 'none';

    els.fontWeightField.style.display =
        text ? 'grid' : 'none';

    renderColorChoices();

    els.elementModal.style.display =
        'flex';
}

function closeElementModal(){

    els.elementModal.style.display =
        'none';

    state.modalElementId = null;
}

function renderColorChoices(){

    els.colorGrid.innerHTML = '';

    for(const color of COLORS){

        const button =
            document.createElement('button');

        button.type = 'button';

        button.className =
            'color-choice' +
            (
                color ===
                state.editingColor
                    ? ' active'
                    : ''
            );

        button.style.background =
            color;

        button.title = color;

        button.addEventListener(
            'click',
            () => {

                state.editingColor =
                    color;

                renderColorChoices();
            }
        );

        els.colorGrid.appendChild(
            button
        );
    }
}

function saveElementModal(){

    const element =
        getElement(
            state.modalElementId
        );

    if(!element){
        closeElementModal();
        return;
    }

    let start =
        Number(
            els.elementStart.value
        );

    let end =
        Number(
            els.elementEnd.value
        );

    if(
        !Number.isFinite(start) ||
        !Number.isFinite(end)
    ){
        showToast(
            '開始・終了時間を正しく入力してください',
            true
        );
        return;
    }

    start =
        clamp(
            start,
            0,
            state.duration
        );

    end =
        clamp(
            end,
            0,
            state.duration
        );

    if(end <= start){
        showToast(
            '終了時間は開始時間より後にしてください',
            true
        );
        return;
    }

    element.name =
        els.elementName.value.trim() ||
        typeLabel(element.type);

    element.text =
        els.elementText.value;

    element.start = start;
    element.end = end;

    element.x =
        clamp(
            Number(
                els.elementX.value
            ) || 0,
            0,
            100
        );

    element.y =
        clamp(
            Number(
                els.elementY.value
            ) || 0,
            0,
            100
        );

    element.w =
        clamp(
            Number(
                els.elementW.value
            ) || 1,
            1,
            100-element.x
        );

    element.h =
        clamp(
            Number(
                els.elementH.value
            ) || 1,
            1,
            100-element.y
        );

    element.color =
        state.editingColor;

    element.fontSize =
        clamp(
            Number(
                els.elementFontSize.value
            ) || 24,
            8,
            100
        );

    element.fontWeight =
        els.elementFontWeight.value;

    markDirty();

    closeElementModal();

    renderAll();
}

/* =========================================================
 * 再生・seek
 * ======================================================= */

function seek(time){

    if(!state.editorReady) return;

    const value =
        clamp(
            Number(time)||0,
            0,
            state.duration
        );

    state.currentTime =
        value;

    try{
        els.video.currentTime =
            value;
    }catch(_){}

    handleSkipAtCurrentTime();

    renderAll();
}

function handleSkipAtCurrentTime(){

    if(
        !state.project ||
        !state.editorReady
    ){
        return;
    }

    const skip =
        state.project.elements.find(
            e =>
                e.type === 'skip' &&
                state.currentTime >= e.start &&
                state.currentTime < e.end-.02
        );

    if(
        skip &&
        !state.skipLock
    ){

        state.skipLock = true;

        seekWithoutSkip(
            skip.end
        );

        setTimeout(
            () => {
                state.skipLock = false;
            },
            100
        );
    }
}

function seekWithoutSkip(time){

    const value =
        clamp(
            time,
            0,
            state.duration
        );

    state.currentTime =
        value;

    try{
        els.video.currentTime =
            value;
    }catch(_){}

    renderAll();
}

function togglePlay(){

    if(!canEdit()) return;

    if(els.video.paused){

        const promise =
            els.video.play();

        if(
            promise &&
            typeof promise.catch ===
            'function'
        ){

            promise.catch(
                error => {

                    if(
                        error.name !==
                        'AbortError'
                    ){

                        console.error(error);

                        showToast(
                            '再生を開始できませんでした',
                            true
                        );
                    }
                }
            );
        }

    }else{

        els.video.pause();
    }
}

function stopVideo(){

    if(!state.editorReady) return;

    els.video.pause();

    try{
        els.video.currentTime = 0;
    }catch(_){}

    state.currentTime = 0;

    renderAll();
}

function updatePlayhead(){

    if(
        !state.project ||
        state.duration <= 0
    ){

        els.playhead.style.display =
            'none';

        return;
    }

    els.playhead.style.display =
        'block';

    /*
     * 固定120pxを使わず、
     * 実際のaxisTrackから算出。
     */
    const x =
        timeToTimelineX(
            state.currentTime
        );

    els.playhead.style.left =
        x + 'px';

    els.playheadLabel.textContent =
        formatTime(
            state.currentTime
        );

    els.timeReadout.textContent =
        formatTime(
            state.currentTime
        ) +
        ' / ' +
        formatTime(
            state.duration
        );
}

/* =========================================================
 * Context menu
 * ======================================================= */

function openContextMenu(
    x,
    y,
    targetId,
    connectionId
){

    state.contextTargetId =
        targetId;

    state.contextTargetConnectionId =
        connectionId;

    els.ctxEdit.style.display =
        targetId
            ? 'block'
            : 'none';

    els.ctxDelete.style.display =
        targetId ||
        connectionId
            ? 'block'
            : 'none';

    els.ctxConnect.style.display =
        state.selectedIds.size === 2
            ? 'block'
            : 'none';

    els.ctxStartConnect.style.display =
        targetId
            ? 'block'
            : 'block';

    els.ctxDisconnect.style.display =
        connectionId ||
        state.selectedIds.size === 2
            ? 'block'
            : 'none';

    els.ctxConnectionStyle.style.display =
        connectionId
            ? 'block'
            : 'none';

    const width = 220;
    const height = 300;

    els.contextMenu.style.left =
        Math.max(
            5,
            Math.min(
                x,
                window.innerWidth-width-5
            )
        ) + 'px';

    els.contextMenu.style.top =
        Math.max(
            5,
            Math.min(
                y,
                window.innerHeight-height-5
            )
        ) + 'px';

    els.contextMenu.style.display =
        'block';
}

function closeContextMenu(){

    els.contextMenu.style.display =
        'none';

    state.contextTargetId = null;
    state.contextTargetConnectionId =
        null;
}

/* =========================================================
 * プロジェクト import/export
 * ======================================================= */

function exportProject(){

    if(
        !state.project ||
        !state.editorReady
    ){

        showToast(
            '動画を読み込んでから書き出してください',
            true
        );

        return;
    }

    const data = {
        format:
            'video-annotation-project',

        version:
            APP_VERSION,

        exportedAt:
            new Date().toISOString(),

        project:
            state.project
    };

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

    downloadBlob(
        blob,
        sanitizeFilename(
            state.project.name
        ) + '.json'
    );

    showToast(
        'プロジェクト情報を書き出しました'
    );
}

async function importProjectFile(file){

    try{

        const text =
            await file.text();

        const data =
            JSON.parse(text);

        const project =
            data.project || data;

        if(
            !project ||
            !Array.isArray(
                project.elements
            )
        ){
            throw new Error(
                'プロジェクト形式が不正です'
            );
        }

        if(
            !state.editorReady ||
            !state.videoBlob
        ){

            showToast(
                '先に対象動画を読み込んでください',
                true
            );

            return;
        }

        const imported =
            normalizeProject(
                project
            );

        if(
            imported.duration > 0 &&
            Math.abs(
                imported.duration -
                state.duration
            ) > .5
        ){

            const ok =
                confirm(
                    'プロジェクトと動画の長さが異なります。\n' +
                    '現在の動画時間に合わせて読み込みますか？'
                );

            if(!ok) return;
        }

        imported.duration =
            state.duration;

        imported.videoName =
            state.videoBlob.name ||
            imported.videoName;

        state.project =
            imported;

        state.selectedIds.clear();
        state.selectedConnectionId =
            null;

        cancelConnectionMode();

        markDirty();
        renderAll();

        showToast(
            'プロジェクト情報を読み込みました'
        );

    }catch(error){

        console.error(error);

        showToast(
            'プロジェクトを読み込めませんでした',
            true
        );
    }
}

function downloadBlob(
    blob,
    filename
){

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href = url;
    a.download = filename;

    document.body.appendChild(a);

    a.click();

    a.remove();

    setTimeout(
        () =>
            URL.revokeObjectURL(url),
        1000
    );
}

/* =========================================================
 * Project UI
 * ======================================================= */

function openStorageModal(){

    els.storageModal.style.display =
        'flex';

    listStoredProjects();
}

function closeStorageModal(){

    els.storageModal.style.display =
        'none';
}

function openProjectNameModal(){

    els.projectNameInput.value = '';

    els.projectNameModal.style.display =
        'flex';

    setTimeout(
        () =>
            els.projectNameInput.focus(),
        30
    );
}

function closeProjectNameModal(){

    els.projectNameModal.style.display =
        'none';
}

function createNamedProject(){

    const name =
        els.projectNameInput.value.trim() ||
        '新規プロジェクト';

    closeProjectNameModal();

    resetToNewProject(
        name
    );

    showToast(
        '新規プロジェクトを作成しました'
    );
}

/* =========================================================
 * renderAll
 * ======================================================= */

function renderAll(){

    renderOverlay();

    renderTimeline();

    /*
     * renderTimeline()でelement-barの
     * 実DOMが確定した後に接続線を描く。
     */
    renderConnections();

    updatePlayhead();

    updateSelectionHint();
}

/* =========================================================
 * Video events
 * ======================================================= */

els.video.addEventListener(
    'timeupdate',
    () => {

        if(!state.editorReady){
            return;
        }

        state.currentTime =
            Number(
                els.video.currentTime
            ) || 0;

        handleSkipAtCurrentTime();

        /*
         * 再生中はDOM全体を再生成しない。
         * 再生ヘッドだけ更新する。
         */
        renderOverlay();
        updatePlayhead();
    }
);

els.video.addEventListener(
    'play',
    () => {
        els.playBtn.textContent = 'Ⅱ';
    }
);

els.video.addEventListener(
    'pause',
    () => {
        els.playBtn.textContent = '▶';
    }
);

els.video.addEventListener(
    'ended',
    () => {

        els.playBtn.textContent = '▶';

        state.currentTime =
            state.duration;

        renderAll();
    }
);

/* =========================================================
 * Timeline mouse/pointer
 * ======================================================= */

els.timelineContent.addEventListener(
    'pointermove',
    event => {

        if(state.connectionMode){

            state.connectionPointer = {
                x:event.clientX,
                y:event.clientY
            };

            renderConnectionPreview();
        }
    }
);

els.timelineContent.addEventListener(
    'pointerdown',
    event => {

        if(!canEdit()) return;

        if(
            event.target.closest(
                '.element-bar'
            ) ||
            event.target.closest(
                '.track-label'
            ) ||
            event.target.closest(
                '.connection-path'
            )
        ){
            return;
        }

        if(state.connectionMode){
            return;
        }

        /*
         * axisTrackの実矩形から時間を求める。
         * 以前の「120px固定」がここにもあった。
         */
        const time =
            timelineXToTime(
                event.clientX
            );

        seek(time);
    }
);

/* =========================================================
 * Zoom
 * ======================================================= */

els.zoomRange.addEventListener(
    'input',
    () => {

        els.zoomValue.textContent =
            Number(
                els.zoomRange.value
            ).toFixed(1) + '×';

        renderAll();
    }
);

/* =========================================================
 * Video context menu
 * ======================================================= */

els.videoArea.addEventListener(
    'contextmenu',
    event => {

        event.preventDefault();

        if(!canEdit()){

            showToast(
                '動画の読み込み完了後に編集できます',
                true
            );

            return;
        }

        const rect =
            els.videoOverlay.getBoundingClientRect();

        state.contextX =
            clamp(
                (
                    event.clientX -
                    rect.left
                ) /
                rect.width *
                100,
                0,
                80
            );

        state.contextY =
            clamp(
                (
                    event.clientY -
                    rect.top
                ) /
                rect.height *
                100,
                0,
                80
            );

        openContextMenu(
            event.clientX,
            event.clientY,
            null,
            null
        );
    }
);

/* =========================================================
 * Context menu buttons
 * ======================================================= */

els.ctxAddText.addEventListener(
    'click',
    () => {

        const x = state.contextX;
        const y = state.contextY;

        closeContextMenu();

        addElement(
            'text',
            x,
            y
        );
    }
);

els.ctxAddBox.addEventListener(
    'click',
    () => {

        const x = state.contextX;
        const y = state.contextY;

        closeContextMenu();

        addElement(
            'box',
            x,
            y
        );
    }
);

els.ctxAddSkip.addEventListener(
    'click',
    () => {

        const x = state.contextX;
        const y = state.contextY;

        closeContextMenu();

        addElement(
            'skip',
            x,
            y
        );
    }
);

els.ctxEdit.addEventListener(
    'click',
    () => {

        const id =
            state.contextTargetId;

        closeContextMenu();

        if(id){
            openElementModal(id);
        }
    }
);

els.ctxConnect.addEventListener(
    'click',
    () => {

        closeContextMenu();

        connectSelected();
    }
);

els.ctxStartConnect.addEventListener(
    'click',
    () => {

        const id =
            state.contextTargetId;

        closeContextMenu();

        startConnectionMode(
            id || null
        );
    }
);

els.ctxDisconnect.addEventListener(
    'click',
    () => {

        const id =
            state.contextTargetConnectionId;

        closeContextMenu();

        if(id){
            state.selectedConnectionId =
                id;
        }

        disconnectSelected();
    }
);

els.ctxConnectionStyle.addEventListener(
    'click',
    () => {

        const id =
            state.contextTargetConnectionId;

        closeContextMenu();

        if(!id || !state.project){
            return;
        }

        const connection =
            state.project.connections.find(
                c => c.id === id
            );

        if(connection){
            openConnectionStyleModal(
                connection
            );
        }
    }
);

els.ctxDelete.addEventListener(
    'click',
    () => {

        closeContextMenu();

        deleteSelected();
    }
);

els.cancelConnectionBtn.addEventListener(
    'click',
    cancelConnectionMode
);

/* =========================================================
 * Global click / keyboard
 * ======================================================= */

document.addEventListener(
    'click',
    event => {

        if(
            !els.contextMenu.contains(
                event.target
            )
        ){
            closeContextMenu();
        }
    }
);

document.addEventListener(
    'keydown',
    event => {

        if(event.key === 'Escape'){

            closeContextMenu();
            closeElementModal();
            closeProjectNameModal();
            closeStorageModal();

            if(state.connectionMode){
                cancelConnectionMode();
            }
        }

        if(
            event.key === 'Delete' &&
            !event.target.matches(
                'input,textarea,select'
            )
        ){

            deleteSelected();
        }

        if(
            event.key === ' ' &&
            !event.target.matches(
                'input,textarea,select'
            )
        ){

            event.preventDefault();

            if(canEdit()){
                togglePlay();
            }
        }

        /*
         * Cキーでも接続モード。
         */
        if(
            event.key.toLowerCase() === 'c' &&
            !event.target.matches(
                'input,textarea,select'
            )
        ){

            if(state.selectedIds.size === 1){

                startConnectionMode(
                    [...state.selectedIds][0]
                );
            }
        }
    }
);

/* =========================================================
 * Modals
 * ======================================================= */

els.elementModalClose.addEventListener(
    'click',
    closeElementModal
);

els.elementModalCancel.addEventListener(
    'click',
    closeElementModal
);

els.elementModalSave.addEventListener(
    'click',
    saveElementModal
);

els.elementModal.addEventListener(
    'click',
    event => {

        if(
            event.target ===
            els.elementModal
        ){
            closeElementModal();
        }
    }
);

els.storageClose.addEventListener(
    'click',
    closeStorageModal
);

els.storageModal.addEventListener(
    'click',
    event => {

        if(
            event.target ===
            els.storageModal
        ){
            closeStorageModal();
        }
    }
);

els.projectNameCancel.addEventListener(
    'click',
    closeProjectNameModal
);

els.projectNameOk.addEventListener(
    'click',
    createNamedProject
);

els.projectNameInput.addEventListener(
    'keydown',
    event => {

        if(event.key === 'Enter'){

            event.preventDefault();

            createNamedProject();
        }
    }
);

/* =========================================================
 * Top buttons
 * ======================================================= */

els.openVideoBtn.addEventListener(
    'click',
    () => {
        els.videoFile.click();
    }
);

els.videoFile.addEventListener(
    'change',
    event => {

        const file =
            event.target.files?.[0];

        if(!file) return;

        state.project =
            emptyProject(
                file.name.replace(
                    /\.[^.]+$/,
                    ''
                ) ||
                '動画プロジェクト'
            );

        state.project.videoName =
            file.name;

        state.selectedIds.clear();
        state.selectedConnectionId =
            null;

        cancelConnectionMode();

        handleVideoFile(file);
    }
);

els.newProjectBtn.addEventListener(
    'click',
    openProjectNameModal
);

els.saveProjectBtn.addEventListener(
    'click',
    () => {

        saveCurrentProject(true)
            .catch(error => {

                console.error(error);

                showToast(
                    '保存に失敗しました',
                    true
                );
            });
    }
);

els.loadProjectBtn.addEventListener(
    'click',
    openStorageModal
);

els.exportProjectBtn.addEventListener(
    'click',
    exportProject
);

els.importProjectInput.addEventListener(
    'change',
    event => {

        const file =
            event.target.files?.[0];

        if(file){
            importProjectFile(file);
        }

        event.target.value = '';
    }
);

/* =========================================================
 * Resize
 * ======================================================= */

let resizeTimer = null;

window.addEventListener(
    'resize',
    () => {

        clearTimeout(
            resizeTimer
        );

        resizeTimer =
            setTimeout(
                () => {

                    if(state.editorReady){
                        renderAll();
                    }
                },
                30
            );
    }
);

/* =========================================================
 * 初期化
 * ======================================================= */

els.zoomValue.textContent =
    '1.0×';

disableEditor();

window.addEventListener(
    'beforeunload',
    () => {

        if(state.videoUrl){

            try{
                URL.revokeObjectURL(
                    state.videoUrl
                );
            }catch(_){}
        }
    }
);

})();
</script>

</body>
</html>

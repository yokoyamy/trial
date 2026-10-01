<?php
declare(strict_types=1);

const APP_VERSION = '5.1.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => true, 'version' => APP_VERSION],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if ($action === 'export-json') {
        $project = $_POST['project'] ?? '';

        if ($project === '') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['ok' => false, 'error' => 'project is required'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        json_decode($project, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['ok' => false, 'error' => 'invalid json'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header(
            'Content-Disposition: attachment; filename="video-editor-project.json"'
        );
        echo $project;
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
    --bg:#0b1220;
    --panel:#111b2a;
    --panel2:#182438;
    --line:#334155;
    --text:#e5e7eb;
    --muted:#94a3b8;
    --blue:#2563eb;
    --red:#ef4444;

    --label:120px;
    --track:56px;
}

*{
    box-sizing:border-box;
}

html,
body{
    width:100%;
    height:100%;
    margin:0;
    overflow:hidden;

    background:var(--bg);
    color:var(--text);

    font-family:
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
    cursor:pointer;
}

button:disabled{
    opacity:.45;
    cursor:not-allowed;
}

.hidden{
    display:none!important;
}

.app{
    width:100%;
    height:100%;
    display:flex;
    flex-direction:column;
}


/* ========================================
   上部操作バー
======================================== */

.topbar{
    height:52px;
    flex:none;

    display:flex;
    align-items:center;
    gap:7px;

    padding:7px 10px;

    background:#080f1b;
    border-bottom:1px solid var(--line);
}

.brand{
    font-weight:700;
    margin-right:8px;
    white-space:nowrap;
}

.btn{
    border:1px solid #40516a;
    border-radius:6px;

    background:#243247;
    color:var(--text);

    padding:7px 11px;
}

.btn:hover:not(:disabled){
    background:#304158;
}

.btn.primary{
    background:var(--blue);
    border-color:#3b82f6;
}

.btn.small{
    padding:5px 8px;
    font-size:12px;
}

.status{
    margin-left:auto;

    color:var(--muted);
    font-size:12px;

    white-space:nowrap;
}

.file-input{
    display:none;
}


/* ========================================
   メイン
======================================== */

.main{
    min-height:0;
    flex:1;

    display:flex;
    flex-direction:column;
}


/* ========================================
   動画エリア
======================================== */

.video-area{
    min-height:260px;
    flex:1;

    display:flex;
    align-items:center;
    justify-content:center;

    padding:12px;

    background:#050a11;

    overflow:hidden;
}

.video-wrap{
    position:relative;

    width:min(1000px,100%);
    height:100%;

    display:flex;
    align-items:center;
    justify-content:center;
}

#video{
    display:block;

    max-width:100%;
    max-height:100%;

    background:#000;
}


/*
 * 動画上の編集領域
 *
 * SVGもここに配置する。
 * タイムラインには接続線を配置しない。
 */
.video-overlay{
    position:absolute;
    inset:0;

    pointer-events:none;
}


/*
 * 動画上の接続線
 */
.video-connection-svg{
    position:absolute;

    z-index:5;

    overflow:visible;

    pointer-events:none;
}


/*
 * 接続線の外周。
 * 背景が明るい動画でも線を視認しやすくする。
 */
.video-connection-halo{
    fill:none;

    stroke:#000;
    stroke-width:8;

    stroke-linecap:round;
    stroke-linejoin:round;

    opacity:.75;
}


/*
 * 接続線本体
 */
.video-connection-path{
    fill:none;

    stroke:#f8fafc;
    stroke-width:3;

    stroke-linecap:round;
    stroke-linejoin:round;

    filter:
        drop-shadow(
            0 1px 2px rgba(0,0,0,.8)
        );
}


/*
 * 選択された接続線
 */
.video-connection-path.selected{
    stroke:#fbbf24;
    stroke-width:5;
}


/*
 * 接続先の丸印
 */
.video-connection-endpoint{
    stroke:#000;
    stroke-width:2;
}


/*
 * 接続先の矢印
 */
.video-connection-arrow{
    stroke:#000;
    stroke-width:1;
}


/* ========================================
   動画上の要素
======================================== */

.overlay-element{
    position:absolute;

    min-width:24px;
    min-height:20px;

    pointer-events:auto;

    user-select:none;

    touch-action:none;

    cursor:move;

    z-index:10;
}

.overlay-element.selected{
    outline:2px solid #60a5fa;
}

.overlay-element.multi-selected{
    outline:2px solid #fbbf24;
}


/*
 * 要素のリサイズハンドル
 */
.resize-handle{
    position:absolute;

    width:10px;
    height:10px;

    border:2px solid #fff;

    background:#2563eb;

    border-radius:2px;

    z-index:30;

    opacity:0;
}

.overlay-element.selected .resize-handle{
    opacity:1;
}

.resize-handle.nw{
    left:-6px;
    top:-6px;
    cursor:nwse-resize;
}

.resize-handle.n{
    left:50%;
    top:-6px;
    transform:translateX(-50%);
    cursor:ns-resize;
}

.resize-handle.ne{
    right:-6px;
    top:-6px;
    cursor:nesw-resize;
}

.resize-handle.e{
    right:-6px;
    top:50%;
    transform:translateY(-50%);
    cursor:ew-resize;
}

.resize-handle.se{
    right:-6px;
    bottom:-6px;
    cursor:nwse-resize;
}

.resize-handle.s{
    left:50%;
    bottom:-6px;
    transform:translateX(-50%);
    cursor:ns-resize;
}

.resize-handle.sw{
    left:-6px;
    bottom:-6px;
    cursor:nesw-resize;
}

.resize-handle.w{
    left:-6px;
    top:50%;
    transform:translateY(-50%);
    cursor:ew-resize;
}


.overlay-text{
    width:100%;
    height:100%;

    display:flex;
    align-items:center;
    justify-content:center;

    padding:4px;

    overflow:hidden;

    white-space:pre-wrap;

    text-shadow:
        0 1px 3px #000,
        0 0 2px #000;
}

.overlay-box{
    width:100%;
    height:100%;

    border:3px solid currentColor;

    background:#ffffff08;
}

.overlay-skip{
    width:100%;
    height:100%;

    display:flex;
    align-items:center;
    justify-content:center;

    border:2px dashed #f97316;

    color:#fdba74;

    background:#f973161f;
}

.empty-video{
    position:absolute;

    text-align:center;

    color:#64748b;

    line-height:1.8;

    pointer-events:none;
}

.empty-video strong{
    display:block;

    color:#94a3b8;

    font-size:18px;
}


/* ========================================
   タイムライン
======================================== */

.timeline-panel{
    height:330px;
    flex:none;

    display:flex;
    flex-direction:column;

    border-top:1px solid var(--line);

    background:#0e1724;
}

.timeline-toolbar{
    height:43px;
    flex:none;

    display:flex;
    align-items:center;

    gap:7px;

    padding:5px 8px;

    border-bottom:1px solid var(--line);
}

.time-readout{
    min-width:120px;

    font-variant-numeric:tabular-nums;

    color:#dbeafe;
}

.selection-hint{
    color:#64748b;
    font-size:11px;
}

.zoom-control{
    margin-left:auto;

    display:flex;
    align-items:center;

    gap:6px;

    color:var(--muted);

    font-size:12px;
}

.zoom-control input{
    width:120px;
}

.timeline-scroll{
    position:relative;

    flex:1;
    min-height:0;

    width:100%;

    overflow-y:auto;
    overflow-x:hidden;
}

.timeline-content{
    position:relative;

    width:100%;
    min-width:0;
}

.axis-row{
    position:sticky;

    top:0;

    z-index:30;

    height:34px;
    width:100%;

    display:flex;

    background:#131d29;

    border-bottom:1px solid var(--line);
}

.axis-label{
    width:var(--label);
    min-width:var(--label);

    flex:none;

    display:flex;
    align-items:center;

    padding-left:8px;

    background:#131d29;

    border-right:1px solid var(--line);

    font-size:12px;

    position:sticky;
    left:0;

    z-index:40;
}

.axis-track{
    position:relative;

    height:100%;

    flex:1;

    min-width:0;
}

.tick{
    position:absolute;

    top:0;

    height:100%;

    border-left:1px solid #334155;

    padding:5px 0 0 3px;

    font-size:10px;

    color:#64748b;

    white-space:nowrap;

    pointer-events:none;
}

.track-row{
    height:var(--track);
    width:100%;

    display:flex;

    border-bottom:1px solid #263445;
}

.track-label{
    width:var(--label);
    min-width:var(--label);

    flex:none;

    display:flex;
    align-items:center;

    gap:5px;

    padding:0 8px;

    background:#111b27;

    border-right:1px solid var(--line);

    font-size:12px;

    position:sticky;
    left:0;

    z-index:20;
}

.type-dot{
    width:8px;
    height:8px;

    border-radius:50%;

    flex:none;
}

.track-lane{
    position:relative;

    flex:1;

    min-width:0;
}

.track-grid{
    position:absolute;

    inset:0;

    pointer-events:none;
}

.element-bar{
    position:absolute;

    top:9px;

    height:38px;

    min-width:12px;

    border:1px solid currentColor;

    border-radius:5px;

    display:flex;
    align-items:center;

    overflow:visible;

    cursor:grab;

    user-select:none;

    touch-action:none;

    z-index:5;
}

.element-bar:active{
    cursor:grabbing;
}

.element-bar.selected{
    box-shadow:
        0 0 0 2px #60a5fa;
}

.element-bar.multi-selected{
    box-shadow:
        0 0 0 2px #fbbf24;
}

.bar-label{
    padding:0 8px;

    overflow:hidden;

    text-overflow:ellipsis;

    white-space:nowrap;

    font-size:11px;

    pointer-events:none;
}

.element-bar .handle{
    position:absolute;

    top:0;

    width:10px;

    height:100%;

    z-index:8;
}

.element-bar .handle.left{
    left:-5px;

    cursor:ew-resize;
}

.element-bar .handle.right{
    right:-5px;

    cursor:ew-resize;
}

.playhead{
    position:absolute;

    top:34px;
    bottom:0;

    width:2px;

    z-index:50;

    pointer-events:none;

    background:var(--red);

    box-shadow:
        0 0 4px #ef444499;
}

.playhead::before{
    content:"";

    position:absolute;

    top:-1px;
    left:-5px;

    width:12px;
    height:12px;

    background:var(--red);

    clip-path:
        polygon(
            0 0,
            100% 0,
            50% 100%
        );
}

.playhead-label{
    position:absolute;

    top:12px;
    left:5px;

    padding:2px 4px;

    background:var(--red);

    color:#fff;

    font-size:10px;

    white-space:nowrap;

    border-radius:3px;
}


/* ========================================
   モーダル
======================================== */

.modal-backdrop{
    position:fixed;

    inset:0;

    z-index:3000;

    display:none;

    align-items:center;
    justify-content:center;

    padding:20px;

    background:#000a;
}

.modal{
    width:min(520px,100%);

    background:#182231;

    border:1px solid #475569;

    border-radius:8px;

    overflow:hidden;
}

.modal-head,
.modal-foot{
    padding:12px 15px;

    border-color:var(--line);
}

.modal-head{
    display:flex;

    justify-content:space-between;

    border-bottom:1px solid var(--line);
}

.modal-body{
    padding:15px;

    display:grid;

    gap:12px;

    max-height:75vh;

    overflow:auto;
}

.modal-foot{
    display:flex;

    justify-content:flex-end;

    gap:8px;

    border-top:1px solid var(--line);
}

.field{
    display:grid;

    gap:5px;
}

.field label{
    font-size:12px;

    color:var(--muted);
}

.field input,
.field select,
.field textarea{
    width:100%;

    padding:7px;

    background:#0f1722;

    color:var(--text);

    border:1px solid #40516a;

    border-radius:5px;
}

.field textarea{
    min-height:80px;

    resize:vertical;
}

.color-grid{
    display:grid;

    grid-template-columns:
        repeat(6,1fr);

    gap:6px;
}

.color-choice{
    height:28px;

    border:2px solid transparent;

    border-radius:4px;
}

.color-choice.active{
    border-color:#fff;

    box-shadow:
        0 0 0 1px #60a5fa;
}


/* ========================================
   コンテキストメニュー
======================================== */

.context-menu{
    position:fixed;

    display:none;

    z-index:2000;

    min-width:210px;

    padding:5px;

    background:#172235;

    border:1px solid #475569;

    border-radius:6px;

    box-shadow:
        0 12px 30px #0006;
}

.context-menu button{
    display:block;

    width:100%;

    padding:8px;

    border:0;

    background:transparent;

    color:var(--text);

    text-align:left;

    border-radius:4px;
}

.context-menu button:hover{
    background:#293a52;
}


/* ========================================
   保存データ
======================================== */

.storage-item{
    display:flex;

    align-items:center;

    gap:8px;

    padding:10px;

    border-bottom:1px solid #334155;
}

.storage-item .info{
    min-width:0;

    flex:1;
}

.storage-item .name{
    font-weight:600;

    overflow:hidden;

    text-overflow:ellipsis;

    white-space:nowrap;
}

.storage-item .meta{
    font-size:11px;

    color:#64748b;
}


/* ========================================
   通知
======================================== */

.toast{
    position:fixed;

    right:15px;
    bottom:15px;

    z-index:5000;

    display:none;

    padding:10px 14px;

    background:#1e293b;

    border:1px solid #475569;

    border-radius:6px;

    box-shadow:
        0 10px 30px #0006;
}


@media(max-width:800px){

    :root{
        --label:95px;
    }

    .brand{
        display:none;
    }

    .timeline-panel{
        height:300px;
    }
}
</style>
</head>

<body>

<div class="app">

<header class="topbar">

    <div class="brand">
        動画編集・注釈
    </div>

    <button
        class="btn primary"
        id="openVideoBtn"
    >
        動画を読み込む
    </button>

    <input
        class="file-input"
        id="videoFile"
        type="file"
        accept="video/*"
    >

    <button
        class="btn"
        id="newProjectBtn"
    >
        新規
    </button>

    <button
        class="btn"
        id="saveProjectBtn"
        disabled
    >
        保存
    </button>

    <button
        class="btn"
        id="loadProjectBtn"
    >
        保存データ
    </button>

    <button
        class="btn"
        id="exportProjectBtn"
    >
        書き出し
    </button>

    <label class="btn">
        プロジェクト読込
        <input
            class="file-input"
            id="importProjectInput"
            type="file"
            accept=".json,application/json"
        >
    </label>

    <span
        class="status"
        id="statusText"
    >
        動画を読み込んでください
    </span>

</header>


<main class="main">

<section class="video-area">

    <div
        class="video-wrap"
        id="videoWrap"
    >

        <video
            id="video"
            preload="metadata"
            playsinline
        ></video>

        <!--
         * 動画上の要素・接続線を表示する領域
         -->
        <div
            class="video-overlay"
            id="videoOverlay"
        ></div>

        <div
            class="empty-video"
            id="emptyVideo"
        >
            <strong>
                動画を読み込んでください
            </strong>

            動画上で右クリックすると要素を追加できます
        </div>

    </div>

</section>


<section class="timeline-panel">

<div class="timeline-toolbar">

    <button
        class="btn small"
        id="playBtn"
        disabled
    >
        ▶
    </button>

    <button
        class="btn small"
        id="stopBtn"
        disabled
    >
        ■
    </button>

    <span
        class="time-readout"
        id="timeReadout"
    >
        00:00.000 / 00:00.000
    </span>

    <span
        class="selection-hint"
        id="selectionHint"
    ></span>

    <div class="zoom-control">

        <span>
            時間スケール
        </span>

        <input
            id="zoomRange"
            type="range"
            min="1"
            max="5"
            step=".1"
            value="1"
        >

        <span id="zoomValue">
            1.0×
        </span>

    </div>

</div>


<div
    class="timeline-scroll"
    id="timelineScroll"
>

<div
    class="timeline-content"
    id="timelineContent"
>

    <div class="axis-row">

        <div class="axis-label">
            時間
        </div>

        <div
            class="axis-track"
            id="axisTrack"
        ></div>

    </div>

    <div id="tracksContainer"></div>

    <div
        class="playhead"
        id="playhead"
    >
        <span
            class="playhead-label"
            id="playheadLabel"
        >
            00:00.000
        </span>
    </div>

</div>

</div>

</section>

</main>

</div>


<!-- コンテキストメニュー -->

<div
    class="context-menu"
    id="contextMenu"
>

    <button id="ctxAddText">
        テキストを追加
    </button>

    <button id="ctxAddBox">
        強調枠を追加
    </button>

    <button id="ctxAddSkip">
        スキップを追加
    </button>

    <button id="ctxEdit">
        選択要素を編集
    </button>

    <button id="ctxConnect">
        選択2要素を接続
    </button>

    <button id="ctxDisconnect">
        接続を解除
    </button>

    <button id="ctxDelete">
        削除
    </button>

</div>


<!-- 要素編集 -->

<div
    class="modal-backdrop"
    id="elementModal"
>

<div class="modal">

    <div class="modal-head">

        <strong>
            要素を編集
        </strong>

        <button
            class="btn small"
            id="elementModalClose"
        >
            閉じる
        </button>

    </div>

    <div class="modal-body">

        <div class="field">

            <label>
                要素名
            </label>

            <input
                id="elementName"
            >

        </div>

        <div
            class="field"
            id="textField"
        >

            <label>
                テキスト
            </label>

            <textarea
                id="elementText"
            ></textarea>

        </div>

        <div class="field">

            <label>
                開始時間
            </label>

            <input
                id="elementStart"
                type="number"
                min="0"
                step=".001"
            >

        </div>

        <div class="field">

            <label>
                終了時間
            </label>

            <input
                id="elementEnd"
                type="number"
                min="0"
                step=".001"
            >

        </div>

        <div class="field">

            <label>
                横位置（%）
            </label>

            <input
                id="elementX"
                type="number"
                min="0"
                max="100"
                step=".1"
            >

        </div>

        <div class="field">

            <label>
                縦位置（%）
            </label>

            <input
                id="elementY"
                type="number"
                min="0"
                max="100"
                step=".1"
            >

        </div>

        <div class="field">

            <label>
                幅（%）
            </label>

            <input
                id="elementW"
                type="number"
                min="1"
                max="100"
                step=".1"
            >

        </div>

        <div class="field">

            <label>
                高さ（%）
            </label>

            <input
                id="elementH"
                type="number"
                min="1"
                max="100"
                step=".1"
            >

        </div>

        <div
            class="field"
            id="fontField"
        >

            <label>
                文字サイズ
            </label>

            <input
                id="elementFontSize"
                type="number"
                min="8"
                max="100"
            >

        </div>

        <div
            class="field"
            id="weightField"
        >

            <label>
                文字太さ
            </label>

            <select id="elementFontWeight">

                <option value="400">
                    標準
                </option>

                <option value="700">
                    太字
                </option>

                <option value="900">
                    極太
                </option>

            </select>

        </div>

        <div class="field">

            <label>
                色
            </label>

            <div
                class="color-grid"
                id="colorGrid"
            ></div>

        </div>

    </div>

    <div class="modal-foot">

        <button
            class="btn"
            id="elementModalCancel"
        >
            キャンセル
        </button>

        <button
            class="btn primary"
            id="elementModalSave"
        >
            保存
        </button>

    </div>

</div>

</div>


<!-- 保存データ -->

<div
    class="modal-backdrop"
    id="storageModal"
>

<div class="modal">

    <div class="modal-head">

        <strong>
            保存データ
        </strong>

        <button
            class="btn small"
            id="storageClose"
        >
            閉じる
        </button>

    </div>

    <div
        class="modal-body"
        id="storageList"
    ></div>

</div>

</div>


<div
    class="toast"
    id="toast"
></div>


<script>
'use strict';

const $ =
    id => document.getElementById(id);


const els = {

    video:
        $('video'),

    videoWrap:
        $('videoWrap'),

    overlay:
        $('videoOverlay'),

    empty:
        $('emptyVideo'),

    file:
        $('videoFile'),

    open:
        $('openVideoBtn'),

    save:
        $('saveProjectBtn'),

    load:
        $('loadProjectBtn'),

    exportBtn:
        $('exportProjectBtn'),

    import:
        $('importProjectInput'),

    status:
        $('statusText'),

    play:
        $('playBtn'),

    stop:
        $('stopBtn'),

    readout:
        $('timeReadout'),

    hint:
        $('selectionHint'),

    zoom:
        $('zoomRange'),

    zoomValue:
        $('zoomValue'),

    scroll:
        $('timelineScroll'),

    content:
        $('timelineContent'),

    axis:
        $('axisTrack'),

    tracks:
        $('tracksContainer'),

    playhead:
        $('playhead'),

    playheadLabel:
        $('playheadLabel'),

    menu:
        $('contextMenu'),

    modal:
        $('elementModal'),

    storageModal:
        $('storageModal')
};


const state = {

    project:null,

    duration:0,

    currentTime:0,

    videoUrl:'',

    videoBlob:null,

    selectedIds:new Set(),

    selectedConnectionId:null,

    contextElementId:null,

    contextConnectionId:null,

    pendingXY:null,

    drag:null,

    dirty:false,

    modalElementId:null,

    colors:[
        '#60a5fa',
        '#22c55e',
        '#f59e0b',
        '#ef4444',
        '#c084fc',
        '#14b8a6',
        '#f97316',
        '#e879f9',
        '#38bdf8',
        '#a3e635',
        '#fb7185',
        '#facc15'
    ]
};


/* ========================================
   共通
======================================== */

function uid(prefix='id'){

    return (
        prefix +
        '-' +
        Date.now().toString(36) +
        '-' +
        Math.random()
            .toString(36)
            .slice(2,8)
    );
}


function clamp(value,min,max){

    return Math.max(
        min,
        Math.min(max,value)
    );
}


function time(value){

    value =
        Math.max(
            0,
            Number(value) || 0
        );

    return (
        String(
            Math.floor(value / 60)
        ).padStart(2,'0') +
        ':' +
        String(
            Math.floor(value % 60)
        ).padStart(2,'0') +
        '.' +
        String(
            Math.floor(
                (value % 1) * 1000
            )
        ).padStart(3,'0')
    );
}


function shortTime(value){

    return value < 60

        ? value.toFixed(
            value % 1 ? 1 : 0
        ) + 's'

        : String(
            Math.floor(value / 60)
        ).padStart(2,'0') +
        ':' +
        String(
            Math.floor(value % 60)
        ).padStart(2,'0');
}


function elementColor(type){

    if(type === 'text'){
        return '#60a5fa';
    }

    if(type === 'box'){
        return '#22c55e';
    }

    return '#f97316';
}


function rgba(hex,alpha){

    const n =
        parseInt(
            hex.slice(1),
            16
        );

    return (
        `rgba(` +
        `${n >> 16},` +
        `${(n >> 8) & 255},` +
        `${n & 255},` +
        `${alpha})`
    );
}


function toast(
    message,
    error=false
){

    els.status.textContent =
        message;

    const t =
        $('toast');

    t.textContent =
        message;

    t.style.display =
        'block';

    t.style.borderColor =
        error
            ? '#ef4444'
            : '#475569';

    clearTimeout(
        toast.timer
    );

    toast.timer =
        setTimeout(
            () => {
                t.style.display =
                    'none';
            },
            2200
        );
}


function setStatus(message){

    els.status.textContent =
        message;
}


function markDirty(){

    state.dirty =
        true;

    setStatus(
        '変更あり'
    );
}


function markClean(){

    state.dirty =
        false;

    setStatus(
        state.project
            ? `編集中：${state.project.name}`
            : '動画を読み込んでください'
    );
}


function emptyProject(
    name='新規プロジェクト'
){

    return {

        version:5,

        name,

        duration:
            state.duration,

        videoName:
            state.videoBlob?.name ||
            'video',

        elements:[],

        connections:[]
    };
}


function getElement(id){

    return (
        state.project?.elements.find(
            e => e.id === id
        ) ||
        null
    );
}


function canEdit(){

    return (
        !!state.project &&
        state.duration > 0
    );
}


function visible(element){

    return (
        state.currentTime >=
            element.start &&
        state.currentTime <
            element.end
    );
}


/* ========================================
   プロジェクト
======================================== */

function normalizeProject(project){

    if(
        !project ||
        typeof project !== 'object'
    ){

        throw Error(
            'プロジェクト形式が不正です'
        );
    }


    project.version =
        5;

    project.name =
        String(
            project.name ||
            'プロジェクト'
        );

    project.duration =
        Number(project.duration) ||
        state.duration;


    project.elements =
        Array.isArray(
            project.elements
        )
            ? project.elements
            : [];


    project.connections =
        Array.isArray(
            project.connections
        )
            ? project.connections
            : [];


    project.elements =
        project.elements.map(
            element => {

                const type =
                    [
                        'text',
                        'box',
                        'skip'
                    ].includes(
                        element.type
                    )
                        ? element.type
                        : 'text';


                const start =
                    clamp(
                        Number(
                            element.start
                        ) || 0,
                        0,
                        project.duration
                    );


                const end =
                    clamp(
                        Number(
                            element.end
                        ) ||
                        Math.min(
                            project.duration,
                            start + 2
                        ),
                        .05,
                        project.duration
                    );


                return {

                    id:
                        String(
                            element.id ||
                            uid('el')
                        ),

                    type,

                    name:
                        String(
                            element.name ||
                            '要素'
                        ),

                    text:
                        String(
                            element.text ||
                            ''
                        ),

                    start,

                    end:
                        end <= start
                            ? Math.min(
                                project.duration,
                                start + .05
                            )
                            : end,

                    x:
                        clamp(
                            Number(
                                element.x
                            ) || 10,
                            0,
                            99
                        ),

                    y:
                        clamp(
                            Number(
                                element.y
                            ) || 10,
                            0,
                            99
                        ),

                    w:
                        clamp(
                            Number(
                                element.w
                            ) || 30,
                            1,
                            100
                        ),

                    h:
                        clamp(
                            Number(
                                element.h
                            ) || 15,
                            1,
                            100
                        ),

                    color:
                        element.color ||
                        elementColor(type),

                    fontSize:
                        Number(
                            element.fontSize
                        ) || 28,

                    fontWeight:
                        String(
                            element.fontWeight ||
                            '700'
                        )
                };
            }
        );


    const ids =
        new Set(
            project.elements.map(
                e => e.id
            )
        );


    project.connections =
        project.connections

            .filter(
                connection =>
                    connection &&
                    connection.from !==
                        connection.to &&
                    ids.has(
                        connection.from
                    ) &&
                    ids.has(
                        connection.to
                    )
            )

            .map(
                connection => ({

                    id:
                        String(
                            connection.id ||
                            uid('c')
                        ),

                    from:
                        connection.from,

                    to:
                        connection.to,

                    color:
                        connection.color ||
                        '#60a5fa',

                    width:
                        Number(
                            connection.width
                        ) || 3
                })
            );


    return project;
}


/* ========================================
   全体描画
======================================== */

function renderAll(){

    renderTimeline();

    renderOverlay();

    renderVideoConnections();

    updatePlayhead();

    updateHint();
}


/* ========================================
   タイムライン
======================================== */

function timelineGeometry(){

    const rect =
        els.axis.getBoundingClientRect();

    return {

        left:
            rect.left,

        width:
            rect.width
    };
}


function xToTime(clientX){

    const geometry =
        timelineGeometry();


    if(
        !geometry.width ||
        !state.duration
    ){

        return 0;
    }


    return clamp(

        (
            (
                clientX -
                geometry.left
            ) /
            geometry.width
        ) *
        state.duration,

        0,

        state.duration
    );
}


function chooseStep(
    duration,
    width
){

    const target =
        85;

    const approx =
        duration /
        Math.max(
            1,
            width / target
        );


    return [
        .1,
        .25,
        .5,
        1,
        2,
        5,
        10,
        15,
        30,
        60,
        120,
        300,
        600
    ].find(
        x => x >= approx
    ) || 600;
}


function renderAxis(){

    els.axis.innerHTML =
        '';


    if(!state.duration){
        return;
    }


    const width =
        els.axis.clientWidth;


    const step =
        chooseStep(
            state.duration,
            width
        );


    for(
        let t = 0;
        t <=
            state.duration +
            .0001;
        t += step
    ){

        const tick =
            document.createElement(
                'div'
            );

        tick.className =
            'tick';

        tick.style.left =
            `${t / state.duration * 100}%`;

        tick.textContent =
            shortTime(t);

        els.axis.appendChild(
            tick
        );
    }
}


function renderTimeline(){

    els.tracks.innerHTML =
        '';


    const groups = [

        ['text','テキスト'],

        ['box','強調枠'],

        ['skip','スキップ']
    ];


    for(
        const [type,label]
        of groups
    ){

        const row =
            document.createElement(
                'div'
            );

        row.className =
            'track-row';


        const trackLabel =
            document.createElement(
                'div'
            );

        trackLabel.className =
            'track-label';


        const dot =
            document.createElement(
                'span'
            );

        dot.className =
            'type-dot';

        dot.style.background =
            elementColor(type);


        trackLabel.append(
            dot,
            document.createTextNode(
                label
            )
        );


        const lane =
            document.createElement(
                'div'
            );

        lane.className =
            'track-lane';


        const grid =
            document.createElement(
                'div'
            );

        grid.className =
            'track-grid';

        grid.style.backgroundImage =
            'linear-gradient(' +
            'to right,' +
            'rgba(148,163,184,.09) 1px,' +
            'transparent 1px' +
            ')';


        grid.style.backgroundSize =
            `${Math.max(
                10,
                100 / state.duration
            )}% 100%`;


        lane.appendChild(
            grid
        );


        (
            state.project?.elements ||
            []
        )
            .filter(
                element =>
                    element.type === type
            )
            .forEach(
                element =>
                    lane.appendChild(
                        createTimelineBar(
                            element
                        )
                    )
            );


        row.append(
            trackLabel,
            lane
        );


        els.tracks.appendChild(
            row
        );
    }


    renderAxis();
}


function createTimelineBar(
    element
){

    const bar =
        document.createElement(
            'div'
        );

    bar.className =
        'element-bar';

    bar.dataset.id =
        element.id;


    if(
        state.selectedIds.has(
            element.id
        )
    ){

        bar.classList.add(
            state.selectedIds.size > 1
                ? 'multi-selected'
                : 'selected'
        );
    }


    bar.style.left =
        `${
            element.start /
            state.duration *
            100
        }%`;


    bar.style.width =
        `${Math.max(
            .001,
            (
                element.end -
                element.start
            ) /
            state.duration *
            100
        )}%`;


    bar.style.color =
        element.color;


    bar.style.background =
        rgba(
            element.color,
            .2
        );


    const label =
        document.createElement(
            'span'
        );

    label.className =
        'bar-label';

    label.textContent =
        `${element.name} ` +
        `${time(element.start)} ～ ` +
        `${time(element.end)}`;


    const left =
        document.createElement(
            'span'
        );

    left.className =
        'handle left';

    left.dataset.edge =
        'left';


    const right =
        document.createElement(
            'span'
        );

    right.className =
        'handle right';

    right.dataset.edge =
        'right';


    bar.append(
        label,
        left,
        right
    );


    bar.addEventListener(
        'pointerdown',
        beginTimelineDrag
    );


    bar.addEventListener(
        'click',
        event => {

            event.stopPropagation();

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

            openElement(
                element.id
            );
        }
    );


    bar.addEventListener(
        'contextmenu',
        event => {

            event.preventDefault();

            event.stopPropagation();

            selectElement(
                element.id,
                event.shiftKey
            );

            openMenu(
                event.clientX,
                event.clientY,
                element.id,
                null
            );
        }
    );


    return bar;
}


/* ========================================
   選択・接続
======================================== */

function selectElement(
    id,
    additive=false
){

    const element =
        getElement(id);


    if(!element){
        return;
    }


    state.selectedConnectionId =
        null;


    if(!additive){

        state.selectedIds.clear();
    }


    if(
        additive &&
        state.selectedIds.has(id)
    ){

        state.selectedIds.delete(
            id
        );

    }else{

        state.selectedIds.add(
            id
        );
    }


    if(
        state.selectedIds.size === 1 &&
        !additive
    ){

        seek(
            element.start
        );
    }


    updateHint();

    renderTimeline();

    renderOverlay();

    renderVideoConnections();


    /*
     * 2要素を選択したら接続する。
     */
    if(
        state.selectedIds.size === 2
    ){

        connectSelected();
    }
}


function connectSelected(){

    if(
        !state.project ||
        state.selectedIds.size !== 2
    ){

        return;
    }


    const ids =
        [...state.selectedIds];


    const from =
        ids[0];

    const to =
        ids[1];


    const exists =
        state.project.connections.some(
            connection =>
                (
                    connection.from === from &&
                    connection.to === to
                ) ||
                (
                    connection.from === to &&
                    connection.to === from
                )
        );


    if(exists){

        toast(
            'この2要素はすでに接続されています'
        );

        return;
    }


    const source =
        getElement(from);


    state.project.connections.push({

        id:
            uid('c'),

        from,

        to,

        color:
            source?.color ||
            '#60a5fa',

        width:3
    });


    markDirty();

    renderVideoConnections();

    toast(
        '2要素を接続しました'
    );
}


function updateHint(){

    els.hint.textContent =
        state.selectedIds.size
            ? `${state.selectedIds.size}要素選択中`
            : '';
}


/* ========================================
   動画上の要素
======================================== */

function getVideoRect(){

    const rect =
        els.video.getBoundingClientRect();


    return {

        left:
            rect.left,

        top:
            rect.top,

        width:
            rect.width,

        height:
            rect.height
    };
}


function elementPixelRect(
    element
){

    const video =
        getVideoRect();


    return {

        left:
            video.left +
            video.width *
            element.x /
            100,

        top:
            video.top +
            video.height *
            element.y /
            100,

        width:
            video.width *
            element.w /
            100,

        height:
            video.height *
            element.h /
            100
    };
}


function renderOverlay(){

    els.overlay.innerHTML =
        '';


    if(!canEdit()){
        return;
    }


    /*
     * SVGは要素より下、要素はその上に表示する。
     */
    const connectionSvg =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'svg'
        );

    connectionSvg.id =
        'videoConnectionSvg';

    connectionSvg.classList.add(
        'video-connection-svg'
    );

    connectionSvg.style.pointerEvents =
        'none';


    els.overlay.appendChild(
        connectionSvg
    );


    const videoRect =
        getVideoRect();


    if(
        !videoRect.width ||
        !videoRect.height
    ){

        return;
    }


    connectionSvg.style.left =
        `${videoRect.left -
          els.videoWrap.getBoundingClientRect().left}px`;


    connectionSvg.style.top =
        `${videoRect.top -
          els.videoWrap.getBoundingClientRect().top}px`;


    connectionSvg.setAttribute(
        'width',
        videoRect.width
    );

    connectionSvg.setAttribute(
        'height',
        videoRect.height
    );

    connectionSvg.setAttribute(
        'viewBox',
        `0 0 ${videoRect.width} ${videoRect.height}`
    );


    /*
     * 要素を表示。
     */
    for(
        const element
        of state.project.elements
    ){

        if(
            !visible(element)
        ){

            continue;
        }


        const node =
            document.createElement(
                'div'
            );

        node.className =
            'overlay-element';

        node.dataset.id =
            element.id;


        if(
            state.selectedIds.has(
                element.id
            )
        ){

            node.classList.add(
                state.selectedIds.size > 1
                    ? 'multi-selected'
                    : 'selected'
            );
        }


        node.style.left =
            `${
                videoRect.left -
                els.videoWrap.getBoundingClientRect().left +
                videoRect.width *
                element.x /
                100
            }px`;


        node.style.top =
            `${
                videoRect.top -
                els.videoWrap.getBoundingClientRect().top +
                videoRect.height *
                element.y /
                100
            }px`;


        node.style.width =
            `${
                videoRect.width *
                element.w /
                100
            }px`;


        node.style.height =
            `${
                videoRect.height *
                element.h /
                100
            }px`;


        node.style.color =
            element.color;


        if(
            element.type === 'text'
        ){

            const text =
                document.createElement(
                    'div'
                );

            text.className =
                'overlay-text';

            text.textContent =
                element.text ||
                element.name;

            text.style.fontSize =
                element.fontSize +
                'px';

            text.style.fontWeight =
                element.fontWeight;

            node.appendChild(
                text
            );

        }else if(
            element.type === 'box'
        ){

            const box =
                document.createElement(
                    'div'
                );

            box.className =
                'overlay-box';

            node.appendChild(
                box
            );

        }else{

            const skip =
                document.createElement(
                    'div'
                );

            skip.className =
                'overlay-skip';

            skip.textContent =
                'スキップ';

            node.appendChild(
                skip
            );
        }


        /*
         * 8方向のリサイズハンドル。
         */
        [
            'nw',
            'n',
            'ne',
            'e',
            'se',
            's',
            'sw',
            'w'
        ].forEach(
            direction => {

                const handle =
                    document.createElement(
                        'span'
                    );

                handle.className =
                    `resize-handle ${direction}`;

                handle.dataset.direction =
                    direction;

                node.appendChild(
                    handle
                );
            }
        );


        /*
         * 動画上の位置変更・サイズ変更。
         */
        node.addEventListener(
            'pointerdown',
            event => {

                if(
                    event.button !== 0
                ){

                    return;
                }


                event.stopPropagation();

                event.preventDefault();

                startVideoElementDrag(
                    event,
                    element
                );
            }
        );


        node.addEventListener(
            'click',
            event => {

                event.stopPropagation();

                if(
                    !state.drag
                ){

                    selectElement(
                        element.id,
                        event.shiftKey
                    );
                }
            }
        );


        node.addEventListener(
            'dblclick',
            event => {

                event.stopPropagation();

                openElement(
                    element.id
                );
            }
        );


        node.addEventListener(
            'contextmenu',
            event => {

                event.preventDefault();

                event.stopPropagation();

                selectElement(
                    element.id,
                    event.shiftKey
                );

                openMenu(
                    event.clientX,
                    event.clientY,
                    element.id,
                    null
                );
            }
        );


        els.overlay.appendChild(
            node
        );
    }


    /*
     * 要素を描画した後に接続線を描画。
     */
    renderVideoConnections();
}


/* ========================================
   動画上の要素ドラッグ
======================================== */

function startVideoElementDrag(
    event,
    element
){

    const video =
        getVideoRect();


    if(
        !video.width ||
        !video.height
    ){

        return;
    }


    const handle =
        event.target.closest(
            '.resize-handle'
        );


    /*
     * 選択状態を更新。
     */
    if(
        !state.selectedIds.has(
            element.id
        )
    ){

        selectElement(
            element.id,
            event.shiftKey
        );
    }


    state.drag = {

        kind:'video-element',

        id:
            element.id,

        mode:
            handle
                ? 'resize'
                : 'move',

        direction:
            handle?.dataset.direction ||
            '',

        startClientX:
            event.clientX,

        startClientY:
            event.clientY,

        original:{

            x:
                element.x,

            y:
                element.y,

            w:
                element.w,

            h:
                element.h
        },

        videoWidth:
            video.width,

        videoHeight:
            video.height
    };


    window.addEventListener(
        'pointermove',
        moveVideoElementDrag
    );


    window.addEventListener(
        'pointerup',
        endVideoElementDrag,
        {
            once:true
        }
    );
}


function moveVideoElementDrag(
    event
){

    const drag =
        state.drag;


    if(
        !drag ||
        drag.kind !==
            'video-element'
    ){

        return;
    }


    const element =
        getElement(
            drag.id
        );


    if(!element){
        return;
    }


    const dx =
        (
            event.clientX -
            drag.startClientX
        );


    const dy =
        (
            event.clientY -
            drag.startClientY
        );


    const dxPercent =
        dx /
        drag.videoWidth *
        100;


    const dyPercent =
        dy /
        drag.videoHeight *
        100;


    const original =
        drag.original;


    const minWidth =
        2;


    const minHeight =
        2;


    if(
        drag.mode === 'move'
    ){

        element.x =
            clamp(
                original.x +
                dxPercent,
                0,
                100 -
                original.w
            );


        element.y =
            clamp(
                original.y +
                dyPercent,
                0,
                100 -
                original.h
            );

    }else{

        resizeVideoElement(
            element,
            original,
            drag.direction,
            dxPercent,
            dyPercent,
            minWidth,
            minHeight
        );
    }


    markDirty();

    /*
     * 動画上の要素を再描画。
     * これにより接続線も追従する。
     */
    renderOverlay();

    renderVideoConnections();
}


function resizeVideoElement(
    element,
    original,
    direction,
    dx,
    dy,
    minWidth,
    minHeight
){

    let x =
        original.x;

    let y =
        original.y;

    let w =
        original.w;

    let h =
        original.h;


    if(
        direction.includes('w')
    ){

        const nextX =
            clamp(
                original.x +
                dx,
                0,
                original.x +
                original.w -
                minWidth
            );

        x =
            nextX;

        w =
            original.w -
            (
                nextX -
                original.x
            );
    }


    if(
        direction.includes('e')
    ){

        w =
            clamp(
                original.w +
                dx,
                minWidth,
                100 -
                original.x
            );
    }


    if(
        direction.includes('n')
    ){

        const nextY =
            clamp(
                original.y +
                dy,
                0,
                original.y +
                original.h -
                minHeight
            );

        y =
            nextY;

        h =
            original.h -
            (
                nextY -
                original.y
            );
    }


    if(
        direction.includes('s')
    ){

        h =
            clamp(
                original.h +
                dy,
                minHeight,
                100 -
                original.y
            );
    }


    element.x =
        x;

    element.y =
        y;

    element.w =
        w;

    element.h =
        h;
}


function endVideoElementDrag(){

    state.drag =
        null;


    window.removeEventListener(
        'pointermove',
        moveVideoElementDrag
    );


    renderAll();
}


/* ========================================
   動画上の接続線
======================================== */

function getOverlayElement(
    id
){

    return els.overlay.querySelector(
        `.overlay-element[data-id="${CSS.escape(id)}"]`
    );
}


function videoConnectionPoint(
    id,
    side
){

    const element =
        getElement(id);


    const node =
        getOverlayElement(id);


    if(
        !element ||
        !node ||
        !visible(element)
    ){

        return null;
    }


    const video =
        getVideoRect();


    const wrap =
        els.videoWrap.getBoundingClientRect();


    const rect =
        node.getBoundingClientRect();


    let x;

    if(side === 'right'){

        x =
            rect.right -
            video.left;

    }else{

        x =
            rect.left -
            video.left;
    }


    const y =
        rect.top +
        rect.height / 2 -
        video.top;


    return {
        x,
        y
    };
}


function renderVideoConnections(){

    const svg =
        document.getElementById(
            'videoConnectionSvg'
        );


    if(!svg){
        return;
    }


    svg.innerHTML =
        '';


    const video =
        getVideoRect();


    if(
        !video.width ||
        !video.height ||
        !state.project
    ){

        return;
    }


    svg.setAttribute(
        'width',
        video.width
    );

    svg.setAttribute(
        'height',
        video.height
    );

    svg.setAttribute(
        'viewBox',
        `0 0 ${video.width} ${video.height}`
    );


    /*
     * 現在表示されている要素間だけ
     * 接続線を表示。
     */
    for(
        const connection
        of state.project.connections
    ){

        const from =
            getElement(
                connection.from
            );

        const to =
            getElement(
                connection.to
            );


        if(
            !from ||
            !to ||
            !visible(from) ||
            !visible(to)
        ){

            continue;
        }


        const start =
            videoConnectionPoint(
                connection.from,
                'right'
            );


        const end =
            videoConnectionPoint(
                connection.to,
                'left'
            );


        if(
            !start ||
            !end
        ){

            continue;
        }


        drawVideoConnection(
            svg,
            start,
            end,
            connection
        );
    }
}


function drawVideoConnection(
    svg,
    start,
    end,
    connection
){

    const distance =
        Math.abs(
            end.x -
            start.x
        );


    const bend =
        Math.max(
            30,
            Math.min(
                140,
                distance * .35
            )
        );


    /*
     * 接続先が左側にある場合でも
     * 見やすい曲線になるようにする。
     */
    const direction =
        end.x >= start.x
            ? 1
            : -1;


    const pathData =
        end.x >= start.x

            ? `M ${start.x} ${start.y}
               C ${start.x + bend} ${start.y},
                 ${end.x - bend} ${end.y},
                 ${end.x} ${end.y}`

            : `M ${start.x} ${start.y}
               C ${start.x - bend} ${start.y},
                 ${end.x + bend} ${end.y},
                 ${end.x} ${end.y}`;


    /*
     * 黒い外周。
     */
    const halo =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );


    halo.classList.add(
        'video-connection-halo'
    );


    halo.setAttribute(
        'd',
        pathData
    );


    svg.appendChild(
        halo
    );


    /*
     * 本線。
     */
    const path =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );


    path.classList.add(
        'video-connection-path'
    );


    if(
        state.selectedConnectionId ===
        connection.id
    ){

        path.classList.add(
            'selected'
        );
    }


    path.setAttribute(
        'stroke',
        connection.color ||
        '#60a5fa'
    );


    path.setAttribute(
        'stroke-width',
        state.selectedConnectionId ===
            connection.id
            ? 5
            : 3
    );


    path.setAttribute(
        'd',
        pathData
    );


    svg.appendChild(
        path
    );


    /*
     * 接続先。
     */
    const endpoint =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );


    endpoint.classList.add(
        'video-connection-endpoint'
    );


    endpoint.setAttribute(
        'cx',
        end.x
    );

    endpoint.setAttribute(
        'cy',
        end.y
    );

    endpoint.setAttribute(
        'r',
        state.selectedConnectionId ===
            connection.id
            ? 7
            : 5
    );

    endpoint.setAttribute(
        'fill',
        connection.color ||
        '#60a5fa'
    );


    svg.appendChild(
        endpoint
    );


    /*
     * 矢印。
     */
    const arrowSize =
        8;


    const arrow =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );


    arrow.classList.add(
        'video-connection-arrow'
    );


    const arrowX =
        end.x;


    const arrowY =
        end.y;


    const arrowPath =
        direction === 1

            ? `M ${arrowX - arrowSize}
                 ${arrowY - arrowSize}
               L ${arrowX}
                 ${arrowY}
               L ${arrowX - arrowSize}
                 ${arrowY + arrowSize}
               Z`

            : `M ${arrowX + arrowSize}
                 ${arrowY - arrowSize}
               L ${arrowX}
                 ${arrowY}
               L ${arrowX + arrowSize}
                 ${arrowY + arrowSize}
               Z`;


    arrow.setAttribute(
        'd',
        arrowPath
    );


    arrow.setAttribute(
        'fill',
        connection.color ||
        '#60a5fa'
    );


    svg.appendChild(
        arrow
    );
}


/* ========================================
   再生位置
======================================== */

function updatePlayhead(){

    if(!canEdit()){

        els.playhead.style.display =
            'none';

        return;
    }


    els.playhead.style.display =
        'block';


    const geometry =
        timelineGeometry();


    const x =
        geometry.width *
        clamp(
            state.currentTime /
            state.duration,
            0,
            1
        );


    els.playhead.style.left =
        `calc(
            var(--label) +
            ${x}px
        )`;


    els.playheadLabel.textContent =
        time(
            state.currentTime
        );


    els.readout.textContent =
        `${time(
            state.currentTime
        )} / ${time(
            state.duration
        )}`;
}


function seek(value){

    state.currentTime =
        clamp(
            Number(value) || 0,
            0,
            state.duration
        );


    try{

        els.video.currentTime =
            state.currentTime;

    }catch(_){}


    renderOverlay();

    renderVideoConnections();

    updatePlayhead();
}


/* ========================================
   タイムライン上の操作
======================================== */

function beginTimelineDrag(
    event
){

    const bar =
        event.currentTarget;


    const element =
        getElement(
            bar.dataset.id
        );


    if(!element){
        return;
    }


    event.preventDefault();

    event.stopPropagation();


    const handle =
        event.target.closest(
            '.handle'
        );


    const lane =
        bar.parentElement;


    state.drag = {

        kind:
            'timeline',

        id:
            element.id,

        mode:
            handle
                ? 'resize'
                : 'move',

        edge:
            handle?.dataset.edge ||
            '',

        startClientX:
            event.clientX,

        original:{

            start:
                element.start,

            end:
                element.end
        },

        laneWidth:
            lane.getBoundingClientRect()
                .width
    };


    window.addEventListener(
        'pointermove',
        moveTimelineDrag
    );


    window.addEventListener(
        'pointerup',
        endTimelineDrag,
        {
            once:true
        }
    );
}


function moveTimelineDrag(
    event
){

    const drag =
        state.drag;


    if(
        !drag ||
        drag.kind !==
            'timeline'
    ){

        return;
    }


    const element =
        getElement(
            drag.id
        );


    if(!element){
        return;
    }


    const dt =
        (
            event.clientX -
            drag.startClientX
        ) /
        Math.max(
            1,
            drag.laneWidth
        ) *
        state.duration;


    const minimum =
        .05;


    if(
        drag.mode === 'move'
    ){

        const length =
            drag.original.end -
            drag.original.start;


        element.start =
            clamp(
                drag.original.start +
                dt,
                0,
                state.duration -
                length
            );


        element.end =
            element.start +
            length;

    }else if(
        drag.edge === 'left'
    ){

        element.start =
            clamp(
                drag.original.start +
                dt,
                0,
                drag.original.end -
                minimum
            );

    }else{

        element.end =
            clamp(
                drag.original.end +
                dt,
                drag.original.start +
                minimum,
                state.duration
            );
    }


    markDirty();

    renderTimeline();

    renderOverlay();

    renderVideoConnections();

    updatePlayhead();
}


function endTimelineDrag(){

    state.drag =
        null;


    window.removeEventListener(
        'pointermove',
        moveTimelineDrag
    );


    renderAll();
}


/* ========================================
   要素編集
======================================== */

function openElement(id){

    const element =
        getElement(id);


    if(!element){
        return;
    }


    state.modalElementId =
        id;


    $('elementName').value =
        element.name;

    $('elementText').value =
        element.text;

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

    $('elementFontWeight').value =
        element.fontWeight;


    $('textField')
        .classList
        .toggle(
            'hidden',
            element.type !== 'text'
        );


    $('fontField')
        .classList
        .toggle(
            'hidden',
            element.type !== 'text'
        );


    $('weightField')
        .classList
        .toggle(
            'hidden',
            element.type !== 'text'
        );


    buildColors(
        element.color
    );


    els.modal.style.display =
        'flex';
}


function buildColors(
    active
){

    const grid =
        $('colorGrid');


    grid.innerHTML =
        '';


    state.colors.forEach(
        colorValue => {

            const button =
                document.createElement(
                    'button'
                );


            button.type =
                'button';

            button.className =
                'color-choice' +
                (
                    colorValue === active
                        ? ' active'
                        : ''
                );


            button.style.background =
                colorValue;


            button.dataset.color =
                colorValue;


            button.onclick =
                () => {

                    grid
                        .querySelectorAll(
                            '.active'
                        )
                        .forEach(
                            node =>
                                node.classList
                                    .remove(
                                        'active'
                                    )
                        );


                    button.classList.add(
                        'active'
                    );
                };


            grid.appendChild(
                button
            );
        }
    );
}


function closeElement(){

    els.modal.style.display =
        'none';

    state.modalElementId =
        null;
}


function saveElement(){

    const element =
        getElement(
            state.modalElementId
        );


    if(!element){
        return;
    }


    const start =
        Number(
            $('elementStart').value
        );


    const end =
        Number(
            $('elementEnd').value
        );


    if(
        !Number.isFinite(start) ||
        !Number.isFinite(end) ||
        start < 0 ||
        end <= start ||
        end > state.duration
    ){

        toast(
            '開始・終了時間が不正です',
            true
        );

        return;
    }


    element.name =
        $('elementName')
            .value
            .trim() ||
        '要素';


    element.text =
        $('elementText').value;


    element.start =
        start;

    element.end =
        end;


    element.x =
        clamp(
            Number(
                $('elementX').value
            ) || 0,
            0,
            99
        );


    element.y =
        clamp(
            Number(
                $('elementY').value
            ) || 0,
            0,
            99
        );


    element.w =
        clamp(
            Number(
                $('elementW').value
            ) || 1,
            1,
            100
        );


    element.h =
        clamp(
            Number(
                $('elementH').value
            ) || 1,
            1,
            100
        );


    element.fontSize =
        clamp(
            Number(
                $('elementFontSize').value
            ) || 28,
            8,
            100
        );


    element.fontWeight =
        $('elementFontWeight').value;


    element.color =
        $('colorGrid .active')
            ?.dataset
            .color ||
        element.color;


    markDirty();

    closeElement();

    renderAll();
}


/* ========================================
   要素追加
======================================== */

function addElement(type){

    if(!canEdit()){

        toast(
            '先に動画を読み込んでください',
            true
        );

        return;
    }


    const start =
        clamp(
            state.currentTime,
            0,
            state.duration -
            .05
        );


    const end =
        Math.min(
            state.duration,
            start + 3
        );


    const xy =
        state.pendingXY ||
        {
            x:10,
            y:10
        };


    state.pendingXY =
        null;


    const element = {

        id:
            uid('el'),

        type,

        name:
            type === 'text'
                ? 'テキスト'
                : type === 'box'
                    ? '強調枠'
                    : 'スキップ',

        text:
            type === 'text'
                ? 'テキスト'
                : '',

        start,

        end,

        x:
            clamp(
                xy.x,
                0,
                99
            ),

        y:
            clamp(
                xy.y,
                0,
                99
            ),

        w:35,

        h:18,

        color:
            elementColor(type),

        fontSize:28,

        fontWeight:'700'
    };


    state.project.elements.push(
        element
    );


    state.selectedIds.clear();

    state.selectedIds.add(
        element.id
    );


    markDirty();

    renderAll();

    openElement(
        element.id
    );
}


/* ========================================
   削除・接続解除
======================================== */

function deleteSelected(){

    if(
        state.selectedConnectionId
    ){

        state.project.connections =
            state.project.connections.filter(
                connection =>
                    connection.id !==
                    state.selectedConnectionId
            );


        state.selectedConnectionId =
            null;


        markDirty();

        renderAll();

        return;
    }


    if(
        !state.selectedIds.size
    ){

        return;
    }


    const ids =
        new Set(
            state.selectedIds
        );


    state.project.elements =
        state.project.elements.filter(
            element =>
                !ids.has(
                    element.id
                )
        );


    state.project.connections =
        state.project.connections.filter(
            connection =>
                !ids.has(
                    connection.from
                ) &&
                !ids.has(
                    connection.to
                )
        );


    state.selectedIds.clear();

    markDirty();

    renderAll();
}


function disconnectSelected(){

    if(
        state.selectedConnectionId
    ){

        state.project.connections =
            state.project.connections.filter(
                connection =>
                    connection.id !==
                    state.selectedConnectionId
            );


        state.selectedConnectionId =
            null;


        markDirty();

        renderAll();

        return;
    }


    if(
        state.selectedIds.size === 2
    ){

        const [
            a,
            b
        ] =
            [...state.selectedIds];


        state.project.connections =
            state.project.connections.filter(
                connection =>
                    !(
                        (
                            connection.from === a &&
                            connection.to === b
                        ) ||
                        (
                            connection.from === b &&
                            connection.to === a
                        )
                    )
            );


        markDirty();

        renderAll();
    }
}


/* ========================================
   コンテキストメニュー
======================================== */

function openMenu(
    x,
    y,
    elementId,
    connectionId
){

    state.contextElementId =
        elementId;

    state.contextConnectionId =
        connectionId;


    els.menu.style.display =
        'block';


    els.menu.style.left =
        Math.min(
            x,
            innerWidth - 220
        ) + 'px';


    els.menu.style.top =
        Math.min(
            y,
            innerHeight - 240
        ) + 'px';
}


function closeMenu(){

    els.menu.style.display =
        'none';
}


function createAt(type){

    addElement(type);

    closeMenu();
}


/* ========================================
   保存・読込
======================================== */

function saveLocal(){

    if(!state.project){
        return;
    }


    const project =
        structuredClone(
            state.project
        );


    project.savedAt =
        new Date().toISOString();


    const key =
        'video-editor:' +
        project.name;


    localStorage.setItem(
        key,
        JSON.stringify(
            project
        )
    );


    markClean();

    toast(
        '保存しました'
    );
}


function listStorage(){

    const list =
        $('storageList');


    list.innerHTML =
        '';


    Object.keys(
        localStorage
    )
        .filter(
            key =>
                key.startsWith(
                    'video-editor:'
                )
        )
        .sort()
        .forEach(
            key => {

                try{

                    const project =
                        JSON.parse(
                            localStorage.getItem(
                                key
                            )
                        );


                    const row =
                        document.createElement(
                            'div'
                        );

                    row.className =
                        'storage-item';


                    row.innerHTML =
                        `
                        <div class="info">
                            <div class="name"></div>
                            <div class="meta">
                                ${
                                    project.videoName ||
                                    ''
                                }
                                /
                                ${
                                    project.elements?.length ||
                                    0
                                }
                                要素
                            </div>
                        </div>
                        `;


                    row.querySelector(
                        '.name'
                    ).textContent =
                        project.name;


                    const button =
                        document.createElement(
                            'button'
                        );


                    button.className =
                        'btn small';

                    button.textContent =
                        '読込';


                    button.onclick =
                        () => {

                            loadProject(
                                project
                            );

                            els.storageModal.style.display =
                                'none';
                        };


                    row.appendChild(
                        button
                    );


                    list.appendChild(
                        row
                    );

                }catch(_){}
            }
        );


    els.storageModal.style.display =
        'flex';
}


function loadProject(project){

    try{

        state.project =
            normalizeProject(
                structuredClone(
                    project
                )
            );


        state.duration =
            state.project.duration;


        if(
            els.video.duration &&
            Number.isFinite(
                els.video.duration
            )
        ){

            state.duration =
                els.video.duration;
        }


        state.project.duration =
            state.duration;


        state.currentTime =
            0;


        state.selectedIds.clear();

        state.selectedConnectionId =
            null;


        enableEditor();

        renderAll();

        markClean();

        toast(
            'プロジェクトを読み込みました'
        );

    }catch(error){

        toast(
            'プロジェクトを読み込めません：' +
            error.message,
            true
        );
    }
}


function exportProject(){

    if(!state.project){
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
            {
                type:
                    'application/json'
            }
        );


    const link =
        document.createElement(
            'a'
        );


    link.href =
        URL.createObjectURL(
            blob
        );


    link.download =
        (
            state.project.name ||
            'project'
        ) +
        '.json';


    link.click();


    setTimeout(
        () => {

            URL.revokeObjectURL(
                link.href
            );

        },
        1000
    );
}


/* ========================================
   動画
======================================== */

function enableEditor(){

    els.play.disabled =
        false;

    els.stop.disabled =
        false;

    els.save.disabled =
        false;

    els.empty.style.display =
        'none';
}


function disableEditor(){

    els.play.disabled =
        true;

    els.stop.disabled =
        true;

    els.save.disabled =
        true;

    els.empty.style.display =
        'block';


    els.overlay.innerHTML =
        '';


    els.tracks.innerHTML =
        '';


    els.playhead.style.display =
        'none';
}


function handleVideo(file){

    if(
        !file ||
        !file.type.startsWith(
            'video/'
        )
    ){

        toast(
            '動画ファイルを選択してください',
            true
        );

        return;
    }


    if(state.videoUrl){

        URL.revokeObjectURL(
            state.videoUrl
        );
    }


    state.videoBlob =
        file;


    state.videoUrl =
        URL.createObjectURL(
            file
        );


    state.project =
        emptyProject(
            file.name
        );


    state.duration =
        0;


    state.currentTime =
        0;


    state.selectedIds.clear();

    state.selectedConnectionId =
        null;


    disableEditor();


    els.video.src =
        state.videoUrl;


    setStatus(
        '動画を読み込んでいます…'
    );
}


function newProject(){

    state.project =
        null;


    state.selectedIds.clear();

    state.selectedConnectionId =
        null;


    state.duration =
        0;

    state.currentTime =
        0;


    if(state.videoUrl){

        URL.revokeObjectURL(
            state.videoUrl
        );

        state.videoUrl =
            '';
    }


    els.video.removeAttribute(
        'src'
    );


    els.video.load();


    disableEditor();


    setStatus(
        '動画を読み込んでください'
    );
}


/* ========================================
   イベント
======================================== */

els.open.onclick =
    () =>
        els.file.click();


els.file.onchange =
    () =>
        handleVideo(
            els.file.files[0]
        );


$('newProjectBtn').onclick =
    newProject;


els.save.onclick =
    saveLocal;


els.load.onclick =
    listStorage;


els.exportBtn.onclick =
    exportProject;


els.import.onchange =
    async event => {

        const file =
            event.target.files[0];


        if(!file){
            return;
        }


        try{

            loadProject(
                JSON.parse(
                    await file.text()
                )
            );

        }catch(error){

            toast(
                'JSONを読み込めません：' +
                error.message,
                true
            );
        }


        event.target.value =
            '';
    };


els.video.onloadedmetadata =
    () => {

        state.duration =
            Number(
                els.video.duration
            );


        if(
            !state.project
        ){

            state.project =
                emptyProject();
        }


        state.project.duration =
            state.duration;


        state.currentTime =
            0;


        enableEditor();

        renderAll();

        markClean();
    };


els.video.ontimeupdate =
    () => {

        state.currentTime =
            els.video.currentTime;


        updatePlayhead();

        renderOverlay();

        renderVideoConnections();
    };


els.video.onended =
    () => {

        els.play.textContent =
            '▶';


        state.currentTime =
            state.duration;


        renderAll();
    };


els.play.onclick =
    async () => {

        if(!canEdit()){
            return;
        }


        if(
            els.video.paused
        ){

            try{

                await els.video.play();

                els.play.textContent =
                    'Ⅱ';

            }catch(error){

                toast(
                    '再生できません',
                    true
                );
            }

        }else{

            els.video.pause();

            els.play.textContent =
                '▶';
        }
    };


els.stop.onclick =
    () => {

        els.video.pause();

        els.play.textContent =
            '▶';

        seek(0);
    };


/*
 * タイムライン上をクリックして
 * 再生位置を変更。
 */
els.content.addEventListener(
    'pointerdown',
    event => {

        if(
            event.target.closest(
                '.element-bar'
            )
        ){

            return;
        }


        if(
            event.target.closest(
                '.axis-track'
            ) ||
            event.target.closest(
                '.track-lane'
            )
        ){

            seek(
                xToTime(
                    event.clientX
                )
            );
        }
    }
);


/*
 * 動画上で右クリックして要素を追加。
 */
els.videoWrap.addEventListener(
    'contextmenu',
    event => {

        event.preventDefault();


        if(!canEdit()){
            return;
        }


        const video =
            getVideoRect();


        if(
            event.clientX <
                video.left ||
            event.clientX >
                video.left +
                video.width ||
            event.clientY <
                video.top ||
            event.clientY >
                video.top +
                video.height
        ){

            return;
        }


        state.pendingXY = {

            x:
                clamp(
                    (
                        event.clientX -
                        video.left
                    ) /
                    video.width *
                    100,
                    0,
                    99
                ),

            y:
                clamp(
                    (
                        event.clientY -
                        video.top
                    ) /
                    video.height *
                    100,
                    0,
                    99
                )
        };


        state.contextElementId =
            null;

        state.contextConnectionId =
            null;


        openMenu(
            event.clientX,
            event.clientY,
            null,
            null
        );
    }
);


/*
 * 右クリックメニュー
 */
$('ctxAddText').onclick =
    () =>
        createAt('text');


$('ctxAddBox').onclick =
    () =>
        createAt('box');


$('ctxAddSkip').onclick =
    () =>
        createAt('skip');


$('ctxEdit').onclick =
    () => {

        if(
            state.contextElementId
        ){

            openElement(
                state.contextElementId
            );
        }

        closeMenu();
    };


$('ctxConnect').onclick =
    () => {

        if(
            state.selectedIds.size === 2
        ){

            connectSelected();

        }else{

            toast(
                '2要素を選択してください',
                true
            );
        }


        closeMenu();
    };


$('ctxDisconnect').onclick =
    () => {

        disconnectSelected();

        closeMenu();
    };


$('ctxDelete').onclick =
    () => {

        deleteSelected();

        closeMenu();
    };


document.addEventListener(
    'click',
    event => {

        if(
            !event.target.closest(
                '.context-menu'
            )
        ){

            closeMenu();
        }
    }
);


/*
 * キーボード操作
 */
document.addEventListener(
    'keydown',
    event => {

        if(
            event.key === 'Escape'
        ){

            closeMenu();

            closeElement();
        }


        if(
            (
                event.key === 'Delete' ||
                event.key === 'Backspace'
            ) &&
            ![
                'INPUT',
                'TEXTAREA',
                'SELECT'
            ].includes(
                document.activeElement.tagName
            )
        ){

            deleteSelected();
        }


        if(
            event.key === ' ' &&
            ![
                'INPUT',
                'TEXTAREA'
            ].includes(
                document.activeElement.tagName
            )
        ){

            event.preventDefault();

            els.play.click();
        }
    }
);


/*
 * 要素編集モーダル
 */
$('elementModalClose').onclick =
    closeElement;


$('elementModalCancel').onclick =
    closeElement;


$('elementModalSave').onclick =
    saveElement;


/*
 * 保存データ
 */
$('storageClose').onclick =
    () => {

        els.storageModal.style.display =
            'none';
    };


/*
 * ウィンドウサイズ変更時。
 *
 * 動画サイズが変わると要素・接続線の
 * 表示位置も変わるため再描画する。
 */
window.addEventListener(
    'resize',
    () => {

        renderOverlay();

        renderVideoConnections();

        renderAxis();

        updatePlayhead();
    }
);


/*
 * スクロール時はタイムラインだけ更新。
 * 接続線は動画上なのでスクロールの影響を受けない。
 */
els.scroll.addEventListener(
    'scroll',
    () => {

        updatePlayhead();
    }
);


/*
 * 初期状態。
 */
els.empty.style.display =
    'block';

</script>

</body>
</html>

<?php
declare(strict_types=1);

const APP_VERSION = '6.0.0';

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
        header('Content-Disposition: attachment; filename="video-editor-project.json"');
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
    --right-menu:270px;
}

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    width:100%;
    height:100%;
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
    height:100vh;
    display:flex;
    flex-direction:column;
}

/* ------------------------------
   上部
------------------------------ */

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

.btn.danger{
    background:#7f1d1d;
    border-color:#991b1b;
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

/* ------------------------------
   メイン
------------------------------ */

.main{
    min-height:0;
    flex:1;
    display:flex;
    flex-direction:column;
    padding-right:var(--right-menu);
}

/* ------------------------------
   動画
------------------------------ */

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

.video-overlay{
    position:absolute;
    inset:0;
    pointer-events:none;
}

/*
 * 動画上の接続線
 */
.video-connections{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
    z-index:10;
}

.video-connection{
    fill:none;
    stroke:#fbbf24;
    stroke-width:3;
    stroke-linecap:round;
    stroke-linejoin:round;
    pointer-events:stroke;
    cursor:pointer;
    filter:
        drop-shadow(0 0 2px rgba(0,0,0,.9))
        drop-shadow(0 0 3px rgba(0,0,0,.8));
}

.video-connection.selected{
    stroke:#60a5fa;
    stroke-width:5;
}

.video-connection-hit{
    fill:none;
    stroke:transparent;
    stroke-width:16;
    pointer-events:stroke;
    cursor:pointer;
}

.connection-arrow{
    pointer-events:none;
}

.connection-handle{
    fill:#fff;
    stroke:#2563eb;
    stroke-width:3;
    cursor:crosshair;
    pointer-events:auto;
}

.connection-handle.start{
    fill:#22c55e;
}

.connection-handle.end{
    fill:#ef4444;
}

.connection-label{
    fill:#0f172a;
    stroke:#475569;
    stroke-width:1;
    opacity:.95;
    pointer-events:none;
}

.connection-label-text{
    fill:#fff;
    font-size:11px;
    font-weight:600;
    pointer-events:none;
}

/* ------------------------------
   動画上の要素
------------------------------ */

.overlay-element{
    position:absolute;
    min-width:30px;
    min-height:20px;
    pointer-events:auto;
    user-select:none;
    touch-action:none;
    z-index:20;
}

.overlay-element.selected{
    outline:2px solid #60a5fa;
    outline-offset:2px;
}

.overlay-element.multi-selected{
    outline:2px solid #fbbf24;
    outline-offset:2px;
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

.overlay-element .resize-handle{
    position:absolute;
    width:10px;
    height:10px;
    border:2px solid #fff;
    background:#2563eb;
    border-radius:2px;
    z-index:30;
    display:none;
}

.overlay-element.selected .resize-handle{
    display:block;
}

.resize-nw{
    left:-6px;
    top:-6px;
    cursor:nwse-resize;
}

.resize-n{
    left:50%;
    top:-6px;
    transform:translateX(-50%);
    cursor:ns-resize;
}

.resize-ne{
    right:-6px;
    top:-6px;
    cursor:nesw-resize;
}

.resize-e{
    right:-6px;
    top:50%;
    transform:translateY(-50%);
    cursor:ew-resize;
}

.resize-se{
    right:-6px;
    bottom:-6px;
    cursor:nwse-resize;
}

.resize-s{
    left:50%;
    bottom:-6px;
    transform:translateX(-50%);
    cursor:ns-resize;
}

.resize-sw{
    left:-6px;
    bottom:-6px;
    cursor:nesw-resize;
}

.resize-w{
    left:-6px;
    top:50%;
    transform:translateY(-50%);
    cursor:ew-resize;
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

/* ------------------------------
   右メニュー
------------------------------ */

.right-menu{
    position:fixed;
    top:52px;
    right:0;
    bottom:0;
    width:var(--right-menu);
    z-index:1000;
    background:#111b2a;
    border-left:1px solid var(--line);
    overflow-y:auto;
}

.right-menu-header{
    min-height:52px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:10px 14px;
    border-bottom:1px solid var(--line);
}

.right-menu-title{
    font-weight:700;
    font-size:14px;
}

.right-menu-type{
    color:#94a3b8;
    font-size:11px;
}

.right-menu-body{
    padding:13px;
}

.right-menu-empty{
    padding:25px 12px;
    color:#64748b;
    font-size:12px;
    line-height:1.8;
    text-align:center;
}

.property-group{
    margin-bottom:16px;
}

.property-title{
    margin-bottom:7px;
    color:#94a3b8;
    font-size:11px;
    font-weight:600;
}

.property-row{
    display:flex;
    align-items:center;
    gap:6px;
    margin-bottom:7px;
}

.property-row label{
    width:58px;
    flex:none;
    color:#94a3b8;
    font-size:11px;
}

.property-row input,
.property-row select{
    min-width:0;
    flex:1;
    padding:6px 7px;
    color:#e5e7eb;
    background:#0f1722;
    border:1px solid #40516a;
    border-radius:5px;
}

.property-row input[type="color"]{
    padding:2px;
    height:30px;
}

.property-buttons{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:6px;
}

.property-buttons .btn{
    width:100%;
    font-size:12px;
}

.shape-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:6px;
}

.shape-button{
    min-height:45px;
    padding:5px;
    border:1px solid #40516a;
    border-radius:5px;
    background:#1b293c;
    color:#dbeafe;
    font-size:11px;
}

.shape-button:hover{
    background:#293a52;
}

.shape-button.active{
    border-color:#60a5fa;
    background:#1e3a5f;
}

.line-preview{
    width:100%;
    height:34px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#0b1220;
    border:1px solid #334155;
    border-radius:5px;
    margin-bottom:8px;
}

.line-preview span{
    width:80%;
    height:0;
    border-top:3px solid #fbbf24;
}

.line-preview span.dashed{
    border-top-style:dashed;
}

.line-preview span.dotted{
    border-top-style:dotted;
}

.endpoint-help{
    padding:8px;
    background:#0f1722;
    border:1px solid #334155;
    border-radius:5px;
    color:#94a3b8;
    font-size:11px;
    line-height:1.6;
}

/* ------------------------------
   タイムライン
------------------------------ */

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
    box-shadow:0 0 0 2px #60a5fa;
}

.element-bar.multi-selected{
    box-shadow:0 0 0 2px #fbbf24;
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
    box-shadow:0 0 4px #ef444499;
}

.playhead::before{
    content:"";
    position:absolute;
    top:-1px;
    left:-5px;
    width:12px;
    height:12px;
    background:var(--red);
    clip-path:polygon(0 0,100% 0,50% 100%);
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

/* ------------------------------
   モーダル
------------------------------ */

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
    grid-template-columns:repeat(6,1fr);
    gap:6px;
}

.color-choice{
    height:28px;
    border:2px solid transparent;
    border-radius:4px;
}

.color-choice.active{
    border-color:#fff;
    box-shadow:0 0 0 1px #60a5fa;
}

/* ------------------------------
   右クリックメニュー
------------------------------ */

.context-menu{
    position:fixed;
    display:none;
    z-index:4000;
    min-width:210px;
    padding:5px;
    background:#172235;
    border:1px solid #475569;
    border-radius:6px;
    box-shadow:0 12px 30px #0006;
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

/* ------------------------------
   保存データ
------------------------------ */

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

/* ------------------------------
   Toast
------------------------------ */

.toast{
    position:fixed;
    right:285px;
    bottom:15px;
    z-index:5000;
    display:none;
    padding:10px 14px;
    background:#1e293b;
    border:1px solid #475569;
    border-radius:6px;
    box-shadow:0 10px 30px #0006;
}

/* ------------------------------
   レスポンシブ
------------------------------ */

@media(max-width:1000px){
    :root{
        --right-menu:230px;
        --label:95px;
    }

    .brand{
        display:none;
    }

    .toast{
        right:245px;
    }
}

@media(max-width:700px){
    :root{
        --right-menu:205px;
        --label:85px;
    }

    .topbar{
        overflow-x:auto;
    }

    .right-menu{
        width:var(--right-menu);
    }

    .toast{
        right:220px;
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


        <!-- 動画上の接続線 -->

        <svg
            class="video-connections"
            id="videoConnections"
        ></svg>


        <!-- 動画上の要素 -->

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


<!-- ======================================
     右メニュー
======================================= -->

<aside
    class="right-menu"
    id="rightMenu"
>

    <div class="right-menu-header">

        <div>

            <div
                class="right-menu-title"
                id="rightMenuTitle"
            >
                プロパティ
            </div>

            <div
                class="right-menu-type"
                id="rightMenuType"
            >
                未選択
            </div>

        </div>

    </div>


    <div
        class="right-menu-body"
        id="rightMenuBody"
    >

        <div
            class="right-menu-empty"
            id="rightMenuEmpty"
        >
            動画上またはタイムライン上の<br>
            要素を選択してください。
        </div>


        <!-- 要素メニュー -->

        <div
            id="elementProperties"
            class="hidden"
        >

            <div class="property-group">

                <div class="property-title">
                    基本設定
                </div>

                <div class="property-row">

                    <label>
                        名前
                    </label>

                    <input
                        id="sideElementName"
                        type="text"
                    >

                </div>

                <div
                    class="property-row"
                    id="sideTextRow"
                >

                    <label>
                        テキスト
                    </label>

                    <input
                        id="sideElementText"
                        type="text"
                    >

                </div>

            </div>


            <!-- 強調枠専用 -->

            <div
                class="property-group hidden"
                id="shapeGroup"
            >

                <div class="property-title">
                    強調枠の形
                </div>

                <div
                    class="shape-grid"
                    id="shapeGrid"
                >

                    <button
                        class="shape-button"
                        data-shape="rectangle"
                    >
                        □ 四角
                    </button>

                    <button
                        class="shape-button"
                        data-shape="rounded"
                    >
                        ▢ 角丸
                    </button>

                    <button
                        class="shape-button"
                        data-shape="ellipse"
                    >
                        ○ 円形
                    </button>

                    <button
                        class="shape-button"
                        data-shape="pill"
                    >
                        ▭ カプセル
                    </button>

                </div>

            </div>


            <div class="property-group">

                <div class="property-title">
                    動画上の位置・サイズ
                </div>

                <div class="property-row">

                    <label>
                        X
                    </label>

                    <input
                        id="sideX"
                        type="number"
                        min="0"
                        max="100"
                        step=".1"
                    >

                </div>

                <div class="property-row">

                    <label>
                        Y
                    </label>

                    <input
                        id="sideY"
                        type="number"
                        min="0"
                        max="100"
                        step=".1"
                    >

                </div>

                <div class="property-row">

                    <label>
                        幅
                    </label>

                    <input
                        id="sideW"
                        type="number"
                        min="1"
                        max="100"
                        step=".1"
                    >

                </div>

                <div class="property-row">

                    <label>
                        高さ
                    </label>

                    <input
                        id="sideH"
                        type="number"
                        min="1"
                        max="100"
                        step=".1"
                    >

                </div>

            </div>


            <div class="property-group">

                <div class="property-title">
                    表示時間
                </div>

                <div class="property-row">

                    <label>
                        開始
                    </label>

                    <input
                        id="sideStart"
                        type="number"
                        min="0"
                        step=".001"
                    >

                </div>

                <div class="property-row">

                    <label>
                        終了
                    </label>

                    <input
                        id="sideEnd"
                        type="number"
                        min="0"
                        step=".001"
                    >

                </div>

            </div>


            <div
                class="property-group"
                id="sideTextStyleGroup"
            >

                <div class="property-title">
                    文字設定
                </div>

                <div class="property-row">

                    <label>
                        サイズ
                    </label>

                    <input
                        id="sideFontSize"
                        type="number"
                        min="8"
                        max="100"
                    >

                </div>

                <div class="property-row">

                    <label>
                        太さ
                    </label>

                    <select id="sideFontWeight">

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

            </div>


            <div class="property-group">

                <div class="property-title">
                    色
                </div>

                <div class="property-row">

                    <label>
                        色
                    </label>

                    <input
                        id="sideColor"
                        type="color"
                    >

                </div>

            </div>


            <div class="property-buttons">

                <button
                    class="btn"
                    id="sideEditDetail"
                >
                    詳細編集
                </button>

                <button
                    class="btn danger"
                    id="sideDeleteElement"
                >
                    削除
                </button>

            </div>

        </div>


        <!-- 接続線メニュー -->

        <div
            id="connectionProperties"
            class="hidden"
        >

            <div class="property-group">

                <div class="property-title">
                    接続線
                </div>

                <div class="line-preview">
                    <span id="linePreview"></span>
                </div>

                <div class="property-row">

                    <label>
                        線種
                    </label>

                    <select id="connectionStyle">

                        <option value="solid">
                            実線
                        </option>

                        <option value="dashed">
                            破線
                        </option>

                        <option value="dotted">
                            点線
                        </option>

                    </select>

                </div>

                <div class="property-row">

                    <label>
                        太さ
                    </label>

                    <input
                        id="connectionWidth"
                        type="number"
                        min="1"
                        max="12"
                        step="1"
                    >

                </div>

                <div class="property-row">

                    <label>
                        色
                    </label>

                    <input
                        id="connectionColor"
                        type="color"
                    >

                </div>

            </div>


            <div class="property-group">

                <div class="property-title">
                    接続元
                </div>

                <div class="property-row">

                    <label>
                        位置
                    </label>

                    <select id="connectionFromAnchor">

                        <option value="top">
                            上
                        </option>

                        <option value="right">
                            右
                        </option>

                        <option value="bottom">
                            下
                        </option>

                        <option value="left">
                            左
                        </option>

                    </select>

                </div>

            </div>


            <div class="property-group">

                <div class="property-title">
                    接続先
                </div>

                <div class="property-row">

                    <label>
                        位置
                    </label>

                    <select id="connectionToAnchor">

                        <option value="top">
                            上
                        </option>

                        <option value="right">
                            右
                        </option>

                        <option value="bottom">
                            下
                        </option>

                        <option value="left">
                            左
                        </option>

                    </select>

                </div>

            </div>


            <div class="endpoint-help">
                動画上の接続線を選択すると、線の両端にハンドルが表示されます。<br>
                ハンドルをドラッグすると、接続位置を直接変更できます。
            </div>


            <div
                style="height:8px"
            ></div>


            <button
                class="btn danger"
                id="sideDeleteConnection"
                style="width:100%"
            >
                接続を削除
            </button>

        </div>

    </div>

</aside>


<!-- ======================================
     右クリックメニュー
======================================= -->

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


<!-- ======================================
     要素編集モーダル
======================================= -->

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

                <input id="elementName">

            </div>


            <div
                class="field"
                id="textField"
            >

                <label>
                    テキスト
                </label>

                <textarea id="elementText"></textarea>

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


            <div
                class="field"
                id="modalShapeField"
            >

                <label>
                    強調枠の形
                </label>

                <select id="elementShape">

                    <option value="rectangle">
                        四角
                    </option>

                    <option value="rounded">
                        角丸
                    </option>

                    <option value="ellipse">
                        円形
                    </option>

                    <option value="pill">
                        カプセル
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


<!-- ======================================
     保存データ
======================================= -->

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


<!-- ======================================
     プロジェクト名
======================================= -->

<div
    class="modal-backdrop"
    id="nameModal"
>

    <div class="modal">

        <div class="modal-head">

            <strong>
                プロジェクト名
            </strong>

        </div>

        <div class="modal-body">

            <div class="field">

                <label>
                    名前
                </label>

                <input id="projectName">

            </div>

        </div>

        <div class="modal-foot">

            <button
                class="btn"
                id="nameCancel"
            >
                キャンセル
            </button>

            <button
                class="btn primary"
                id="nameOk"
            >
                作成
            </button>

        </div>

    </div>

</div>


<div
    class="toast"
    id="toast"
></div>


<script>
'use strict';

const $ = id => document.getElementById(id);

const els = {

    video: $('video'),
    videoWrap: $('videoWrap'),
    overlay: $('videoOverlay'),
    videoConnections: $('videoConnections'),
    empty: $('emptyVideo'),

    file: $('videoFile'),
    open: $('openVideoBtn'),
    newBtn: $('newProjectBtn'),
    save: $('saveProjectBtn'),
    load: $('loadProjectBtn'),
    exportBtn: $('exportProjectBtn'),
    import: $('importProjectInput'),

    status: $('statusText'),
    play: $('playBtn'),
    stop: $('stopBtn'),
    readout: $('timeReadout'),
    hint: $('selectionHint'),

    zoom: $('zoomRange'),
    zoomValue: $('zoomValue'),
    scroll: $('timelineScroll'),

    content: $('timelineContent'),
    axis: $('axisTrack'),
    tracks: $('tracksContainer'),
    playhead: $('playhead'),
    playheadLabel: $('playheadLabel'),

    menu: $('contextMenu'),

    modal: $('elementModal'),
    nameModal: $('nameModal'),
    storageModal: $('storageModal'),

    rightMenuTitle: $('rightMenuTitle'),
    rightMenuType: $('rightMenuType'),
    rightMenuEmpty: $('rightMenuEmpty'),
    elementProperties: $('elementProperties'),
    connectionProperties: $('connectionProperties')
};


const state = {

    project: null,

    duration: 0,
    currentTime: 0,

    videoUrl: '',
    videoBlob: null,

    selectedIds: new Set(),
    selectedConnectionId: null,

    contextElementId: null,
    contextConnectionId: null,

    drag: null,

    connectionDrag: null,

    dirty: false,

    modalElementId: null,

    pendingXY: null,

    colors: [
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


/* ----------------------------------------
   共通
---------------------------------------- */

function uid(prefix = 'id') {

    return (
        prefix +
        '-' +
        Date.now().toString(36) +
        '-' +
        Math.random().toString(36).slice(2, 8)
    );
}


function clamp(value, min, max) {

    return Math.max(
        min,
        Math.min(max, value)
    );
}


function time(value) {

    value = Math.max(
        0,
        Number(value) || 0
    );

    return (
        String(Math.floor(value / 60)).padStart(2, '0') +
        ':' +
        String(Math.floor(value % 60)).padStart(2, '0') +
        '.' +
        String(
            Math.floor((value % 1) * 1000)
        ).padStart(3, '0')
    );
}


function shortTime(value) {

    return value < 60
        ? value.toFixed(value % 1 ? 1 : 0) + 's'
        : String(Math.floor(value / 60)).padStart(2, '0') +
          ':' +
          String(Math.floor(value % 60)).padStart(2, '0');
}


function color(type) {

    if (type === 'text') {
        return '#60a5fa';
    }

    if (type === 'box') {
        return '#22c55e';
    }

    return '#f97316';
}


function rgba(hex, alpha) {

    const n = parseInt(
        String(hex).replace('#', ''),
        16
    );

    return (
        'rgba(' +
        ((n >> 16) & 255) +
        ',' +
        ((n >> 8) & 255) +
        ',' +
        (n & 255) +
        ',' +
        alpha +
        ')'
    );
}


function toast(message, error = false) {

    els.status.textContent = message;

    const box = $('toast');

    box.textContent = message;

    box.style.display = 'block';

    box.style.borderColor =
        error
            ? '#ef4444'
            : '#475569';

    clearTimeout(toast.timer);

    toast.timer = setTimeout(() => {

        box.style.display = 'none';

    }, 2200);
}


function setStatus(message) {

    els.status.textContent = message;
}


function markDirty() {

    state.dirty = true;

    setStatus('変更あり');
}


function markClean() {

    state.dirty = false;

    setStatus(
        state.project
            ? '編集中：' + state.project.name
            : '動画を読み込んでください'
    );
}


function get(id) {

    if (!state.project) {
        return null;
    }

    return (
        state.project.elements.find(
            element => element.id === id
        ) || null
    );
}


function getConnection(id) {

    if (!state.project) {
        return null;
    }

    return (
        state.project.connections.find(
            connection => connection.id === id
        ) || null
    );
}


function canEdit() {

    return (
        !!state.project &&
        state.duration > 0
    );
}


function visible(element) {

    return (
        state.currentTime >= element.start &&
        state.currentTime < element.end
    );
}


/* ----------------------------------------
   プロジェクト
---------------------------------------- */

function emptyProject(
    name = '新規プロジェクト'
) {

    return {

        version: 6,

        name,

        duration: state.duration,

        videoName:
            state.videoBlob?.name || 'video',

        elements: [],

        connections: []
    };
}


function normalizeProject(project) {

    if (
        !project ||
        typeof project !== 'object'
    ) {
        throw new Error(
            'プロジェクト形式が不正です'
        );
    }

    project.version = 6;

    project.name =
        String(
            project.name || 'プロジェクト'
        );

    project.duration =
        Number(project.duration) ||
        state.duration;

    project.elements =
        Array.isArray(project.elements)
            ? project.elements
            : [];

    project.connections =
        Array.isArray(project.connections)
            ? project.connections
            : [];


    project.elements =
        project.elements.map(element => ({

            id:
                String(
                    element.id ||
                    uid('el')
                ),

            type:
                ['text', 'box', 'skip'].includes(
                    element.type
                )
                    ? element.type
                    : 'text',

            name:
                String(
                    element.name || '要素'
                ),

            text:
                String(
                    element.text || ''
                ),

            start:
                clamp(
                    Number(element.start) || 0,
                    0,
                    project.duration
                ),

            end:
                clamp(
                    Number(element.end) ||
                    Math.min(
                        project.duration,
                        (Number(element.start) || 0) + 2
                    ),
                    .05,
                    project.duration
                ),

            x:
                clamp(
                    Number(element.x) || 10,
                    0,
                    99
                ),

            y:
                clamp(
                    Number(element.y) || 10,
                    0,
                    99
                ),

            w:
                clamp(
                    Number(element.w) || 30,
                    1,
                    100
                ),

            h:
                clamp(
                    Number(element.h) || 15,
                    1,
                    100
                ),

            color:
                element.color ||
                color(element.type),

            fontSize:
                Number(element.fontSize) ||
                28,

            fontWeight:
                String(
                    element.fontWeight || '700'
                ),

            shape:
                ['rectangle','rounded','ellipse','pill'].includes(
                    element.shape
                )
                    ? element.shape
                    : 'rectangle'

        }));


    project.elements.forEach(element => {

        if (
            element.end <=
            element.start
        ) {
            element.end =
                Math.min(
                    project.duration,
                    element.start + .05
                );
        }

    });


    const ids =
        new Set(
            project.elements.map(
                element => element.id
            )
        );


    project.connections =
        project.connections

            .filter(connection =>
                connection &&
                connection.from !== connection.to &&
                ids.has(connection.from) &&
                ids.has(connection.to)
            )

            .map(connection => ({

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
                    '#fbbf24',

                width:
                    Number(connection.width) ||
                    3,

                style:
                    ['solid','dashed','dotted'].includes(
                        connection.style
                    )
                        ? connection.style
                        : 'solid',

                fromAnchor:
                    ['top','right','bottom','left'].includes(
                        connection.fromAnchor
                    )
                        ? connection.fromAnchor
                        : 'right',

                toAnchor:
                    ['top','right','bottom','left'].includes(
                        connection.toAnchor
                    )
                        ? connection.toAnchor
                        : 'left'
            }));


    return project;
}


/* ----------------------------------------
   全体描画
---------------------------------------- */

function renderAll() {

    renderTimeline();

    renderOverlay();

    renderVideoConnections();

    updatePlayhead();

    updateHint();

    renderRightMenu();
}


/* ----------------------------------------
   タイムライン
---------------------------------------- */

function timelineGeometry() {

    const rect =
        els.axis.getBoundingClientRect();

    return {

        left: rect.left,

        width: rect.width
    };
}


function timeToX(value) {

    const geometry =
        timelineGeometry();

    return (
        geometry.left +
        clamp(
            value / state.duration,
            0,
            1
        ) *
        geometry.width
    );
}


function xToTime(clientX) {

    const geometry =
        timelineGeometry();

    if (
        !geometry.width ||
        !state.duration
    ) {
        return 0;
    }

    return clamp(
        (
            (clientX - geometry.left) /
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
) {

    const target = 85;

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
        value => value >= approx
    ) || 600;
}


function renderAxis() {

    els.axis.innerHTML = '';

    if (!state.duration) {
        return;
    }

    const width =
        els.axis.clientWidth;

    const step =
        chooseStep(
            state.duration,
            width
        );


    for (
        let current = 0;
        current <= state.duration + .0001;
        current += step
    ) {

        const tick =
            document.createElement('div');

        tick.className = 'tick';

        tick.style.left =
            (
                current /
                state.duration *
                100
            ) + '%';

        tick.textContent =
            shortTime(current);

        els.axis.appendChild(tick);
    }
}


function renderTimeline() {

    els.tracks.innerHTML = '';

    const groups = [

        ['text', 'テキスト'],

        ['box', '強調枠'],

        ['skip', 'スキップ']

    ];


    for (
        const [type, label]
        of groups
    ) {

        const row =
            document.createElement('div');

        row.className =
            'track-row';


        const trackLabel =
            document.createElement('div');

        trackLabel.className =
            'track-label';


        const dot =
            document.createElement('span');

        dot.className =
            'type-dot';

        dot.style.background =
            color(type);


        trackLabel.append(
            dot,
            document.createTextNode(label)
        );


        const lane =
            document.createElement('div');

        lane.className =
            'track-lane';


        const grid =
            document.createElement('div');

        grid.className =
            'track-grid';

        grid.style.backgroundImage =
            'linear-gradient(to right,rgba(148,163,184,.09) 1px,transparent 1px)';

        grid.style.backgroundSize =
            `${Math.max(10,100 / state.duration)}% 100%`;


        lane.appendChild(grid);


        (
            state.project?.elements || []
        )
            .filter(
                element =>
                    element.type === type
            )
            .forEach(
                element =>
                    lane.appendChild(
                        createTimelineBar(element)
                    )
            );


        row.append(
            trackLabel,
            lane
        );

        els.tracks.appendChild(row);
    }


    renderAxis();
}


function createTimelineBar(element) {

    const bar =
        document.createElement('div');

    bar.className =
        'element-bar';

    bar.dataset.id =
        element.id;


    if (
        state.selectedIds.has(
            element.id
        )
    ) {

        bar.classList.add(
            state.selectedIds.size > 1
                ? 'multi-selected'
                : 'selected'
        );
    }


    bar.style.left =
        (
            element.start /
            state.duration *
            100
        ) + '%';


    bar.style.width =
        Math.max(
            .001,
            (
                (
                    element.end -
                    element.start
                ) /
                state.duration *
                100
            )
        ) + '%';


    bar.style.color =
        element.color;

    bar.style.background =
        rgba(
            element.color,
            .2
        );


    const label =
        document.createElement('span');

    label.className =
        'bar-label';

    label.textContent =
        element.name +
        ' ' +
        time(element.start) +
        ' ～ ' +
        time(element.end);


    const left =
        document.createElement('span');

    left.className =
        'handle left';

    left.dataset.edge =
        'left';


    const right =
        document.createElement('span');

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


/* ----------------------------------------
   要素選択
---------------------------------------- */

function selectElement(
    id,
    additive = false
) {

    const element =
        get(id);

    if (!element) {
        return;
    }


    state.selectedConnectionId =
        null;


    if (!additive) {
        state.selectedIds.clear();
    }


    if (
        additive &&
        state.selectedIds.has(id)
    ) {

        state.selectedIds.delete(id);

    } else {

        state.selectedIds.add(id);
    }


    if (
        state.selectedIds.size === 1 &&
        !additive
    ) {

        seek(element.start);
    }


    renderAll();


    if (
        state.selectedIds.size === 2
    ) {

        connectSelected();
    }
}


function connectSelected() {

    const ids =
        [...state.selectedIds];

    if (ids.length !== 2) {
        return;
    }


    const [from, to] =
        ids;


    if (
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
        )
    ) {

        toast(
            'この2要素はすでに接続されています'
        );

        return;
    }


    state.project.connections.push({

        id:
            uid('c'),

        from,

        to,

        color:
            '#fbbf24',

        width:
            3,

        style:
            'solid',

        fromAnchor:
            'right',

        toAnchor:
            'left'
    });


    markDirty();

    renderAll();

    toast(
        '2要素を接続しました'
    );
}


/* ----------------------------------------
   動画上の要素
---------------------------------------- */

function renderOverlay() {

    els.overlay.innerHTML = '';

    if (!canEdit()) {
        return;
    }


    for (
        const element
        of state.project.elements
    ) {

        if (!visible(element)) {
            continue;
        }


        const node =
            document.createElement('div');

        node.className =
            'overlay-element';

        node.dataset.id =
            element.id;


        if (
            state.selectedIds.has(
                element.id
            )
        ) {

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


        if (
            element.type === 'text'
        ) {

            const text =
                document.createElement('div');

            text.className =
                'overlay-text';

            text.textContent =
                element.text ||
                element.name;

            text.style.fontSize =
                element.fontSize + 'px';

            text.style.fontWeight =
                element.fontWeight;

            node.appendChild(text);

        } else if (
            element.type === 'box'
        ) {

            const box =
                document.createElement('div');

            box.className =
                'overlay-box';

            applyShape(
                box,
                element.shape
            );

            node.appendChild(box);

        } else {

            const skip =
                document.createElement('div');

            skip.className =
                'overlay-skip';

            skip.textContent =
                'スキップ';

            node.appendChild(skip);
        }


        addResizeHandles(node);


        node.addEventListener(
            'pointerdown',
            event =>
                beginVideoElementDrag(
                    event,
                    element
                )
        );


        node.addEventListener(
            'click',
            event => {

                event.stopPropagation();

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


        els.overlay.appendChild(node);
    }
}


function addResizeHandles(node) {

    const positions = [
        'nw',
        'n',
        'ne',
        'e',
        'se',
        's',
        'sw',
        'w'
    ];


    positions.forEach(
        position => {

            const handle =
                document.createElement('span');

            handle.className =
                'resize-handle resize-' +
                position;

            handle.dataset.resize =
                position;

            node.appendChild(handle);
        }
    );
}


function applyShape(
    element,
    shape
) {

    element.style.borderRadius =
        '0';

    element.style.clipPath =
        'none';


    if (shape === 'rounded') {

        element.style.borderRadius =
            '14px';

    } else if (
        shape === 'ellipse'
    ) {

        element.style.borderRadius =
            '50%';

    } else if (
        shape === 'pill'
    ) {

        element.style.borderRadius =
            '999px';
    }
}


/* ----------------------------------------
   動画上の要素ドラッグ
---------------------------------------- */

function beginVideoElementDrag(
    event,
    element
) {

    event.stopPropagation();

    event.preventDefault();


    if (
        event.target.closest(
            '.resize-handle'
        )
    ) {

        beginVideoResize(
            event,
            element
        );

        return;
    }


    const rect =
        els.videoWrap.getBoundingClientRect();


    state.drag = {

        type:
            'video-move',

        id:
            element.id,

        startX:
            event.clientX,

        startY:
            event.clientY,

        original: {

            x:
                element.x,

            y:
                element.y,

            w:
                element.w,

            h:
                element.h
        },

        width:
            rect.width,

        height:
            rect.height
    };


    event.currentTarget.setPointerCapture?.(
        event.pointerId
    );


    window.addEventListener(
        'pointermove',
        moveVideoDrag
    );


    window.addEventListener(
        'pointerup',
        endVideoDrag,
        {
            once:true
        }
    );
}


function beginVideoResize(
    event,
    element
) {

    const rect =
        els.videoWrap.getBoundingClientRect();


    state.drag = {

        type:
            'video-resize',

        id:
            element.id,

        direction:
            event.target.dataset.resize,

        startX:
            event.clientX,

        startY:
            event.clientY,

        original: {

            x:
                element.x,

            y:
                element.y,

            w:
                element.w,

            h:
                element.h
        },

        width:
            rect.width,

        height:
            rect.height
    };


    event.currentTarget
        .setPointerCapture?.(
            event.pointerId
        );


    window.addEventListener(
        'pointermove',
        moveVideoDrag
    );


    window.addEventListener(
        'pointerup',
        endVideoDrag,
        {
            once:true
        }
    );
}


function moveVideoDrag(event) {

    const drag =
        state.drag;

    if (!drag) {
        return;
    }


    const element =
        get(drag.id);

    if (!element) {
        return;
    }


    const dx =
        (
            event.clientX -
            drag.startX
        ) /
        drag.width *
        100;


    const dy =
        (
            event.clientY -
            drag.startY
        ) /
        drag.height *
        100;


    if (
        drag.type ===
        'video-move'
    ) {

        const maxX =
            100 -
            drag.original.w;

        const maxY =
            100 -
            drag.original.h;


        element.x =
            clamp(
                drag.original.x + dx,
                0,
                maxX
            );


        element.y =
            clamp(
                drag.original.y + dy,
                0,
                maxY
            );

    } else {

        resizeVideoElement(
            element,
            drag,
            dx,
            dy
        );
    }


    markDirty();

    renderOverlay();

    renderVideoConnections();

    renderRightMenu();
}


function resizeVideoElement(
    element,
    drag,
    dx,
    dy
) {

    const direction =
        drag.direction;


    let x =
        drag.original.x;

    let y =
        drag.original.y;

    let w =
        drag.original.w;

    let h =
        drag.original.h;


    if (
        direction.includes('e')
    ) {

        w =
            clamp(
                drag.original.w + dx,
                2,
                100 - x
            );
    }


    if (
        direction.includes('s')
    ) {

        h =
            clamp(
                drag.original.h + dy,
                2,
                100 - y
            );
    }


    if (
        direction.includes('w')
    ) {

        const right =
            drag.original.x +
            drag.original.w;

        x =
            clamp(
                drag.original.x + dx,
                0,
                right - 2
            );

        w =
            right - x;
    }


    if (
        direction.includes('n')
    ) {

        const bottom =
            drag.original.y +
            drag.original.h;

        y =
            clamp(
                drag.original.y + dy,
                0,
                bottom - 2
            );

        h =
            bottom - y;
    }


    element.x = x;
    element.y = y;
    element.w = w;
    element.h = h;
}


function endVideoDrag() {

    state.drag = null;

    window.removeEventListener(
        'pointermove',
        moveVideoDrag
    );

    renderAll();
}


/* ----------------------------------------
   接続線
---------------------------------------- */

function anchorPoint(
    element,
    anchor
) {

    const x =
        element.x;

    const y =
        element.y;

    const w =
        element.w;

    const h =
        element.h;


    if (anchor === 'top') {

        return {
            x: x + w / 2,
            y
        };
    }


    if (anchor === 'bottom') {

        return {
            x: x + w / 2,
            y: y + h
        };
    }


    if (anchor === 'left') {

        return {
            x,
            y: y + h / 2
        };
    }


    return {

        x:
            x + w,

        y:
            y + h / 2
    };
}


function renderVideoConnections() {

    els.videoConnections.innerHTML = '';

    if (
        !state.project ||
        !canEdit()
    ) {
        return;
    }


    const width =
        els.videoWrap.clientWidth;

    const height =
        els.videoWrap.clientHeight;


    els.videoConnections.setAttribute(
        'viewBox',
        `0 0 ${width} ${height}`
    );

    els.videoConnections.setAttribute(
        'width',
        width
    );

    els.videoConnections.setAttribute(
        'height',
        height
    );


    for (
        const connection
        of state.project.connections
    ) {

        const from =
            get(connection.from);

        const to =
            get(connection.to);


        if (
            !from ||
            !to ||
            !visible(from) ||
            !visible(to)
        ) {
            continue;
        }


        drawVideoConnection(
            connection,
            from,
            to,
            width,
            height
        );
    }
}


function drawVideoConnection(
    connection,
    from,
    to,
    width,
    height
) {

    const start =
        anchorPoint(
            from,
            connection.fromAnchor
        );


    const end =
        anchorPoint(
            to,
            connection.toAnchor
        );


    const sx =
        start.x /
        100 *
        width;

    const sy =
        start.y /
        100 *
        height;

    const ex =
        end.x /
        100 *
        width;

    const ey =
        end.y /
        100 *
        height;


    const distance =
        Math.max(
            35,
            Math.abs(ex - sx) * .35 +
            Math.abs(ey - sy) * .15
        );


    let c1x = sx;
    let c1y = sy;
    let c2x = ex;
    let c2y = ey;


    if (
        connection.fromAnchor === 'left'
    ) {
        c1x -= distance;

    } else if (
        connection.fromAnchor === 'right'
    ) {
        c1x += distance;

    } else if (
        connection.fromAnchor === 'top'
    ) {
        c1y -= distance;

    } else {
        c1y += distance;
    }


    if (
        connection.toAnchor === 'left'
    ) {
        c2x -= distance;

    } else if (
        connection.toAnchor === 'right'
    ) {
        c2x += distance;

    } else if (
        connection.toAnchor === 'top'
    ) {
        c2y -= distance;

    } else {
        c2y += distance;
    }


    const path =
        `M ${sx} ${sy} ` +
        `C ${c1x} ${c1y}, ` +
        `${c2x} ${c2y}, ` +
        `${ex} ${ey}`;


    const hit =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

    hit.classList.add(
        'video-connection-hit'
    );

    hit.setAttribute(
        'd',
        path
    );


    hit.addEventListener(
        'click',
        event => {

            event.stopPropagation();

            selectConnection(
                connection.id
            );
        }
    );


    const visiblePath =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

    visiblePath.classList.add(
        'video-connection'
    );


    if (
        state.selectedConnectionId ===
        connection.id
    ) {

        visiblePath.classList.add(
            'selected'
        );
    }


    visiblePath.setAttribute(
        'd',
        path
    );

    visiblePath.setAttribute(
        'stroke',
        connection.color
    );

    visiblePath.setAttribute(
        'stroke-width',
        connection.width
    );


    if (
        connection.style ===
        'dashed'
    ) {

        visiblePath.setAttribute(
            'stroke-dasharray',
            '9 6'
        );

    } else if (
        connection.style ===
        'dotted'
    ) {

        visiblePath.setAttribute(
            'stroke-dasharray',
            '2 6'
        );
    }


    visiblePath.addEventListener(
        'click',
        event => {

            event.stopPropagation();

            selectConnection(
                connection.id
            );
        }
    );


    els.videoConnections.appendChild(
        hit
    );

    els.videoConnections.appendChild(
        visiblePath
    );


    /*
     * 終端矢印
     */

    const arrow =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );

    arrow.setAttribute(
        'cx',
        ex
    );

    arrow.setAttribute(
        'cy',
        ey
    );

    arrow.setAttribute(
        'r',
        state.selectedConnectionId === connection.id
            ? 7
            : 5
    );

    arrow.setAttribute(
        'fill',
        connection.color
    );

    arrow.classList.add(
        'connection-arrow'
    );

    els.videoConnections.appendChild(
        arrow
    );


    /*
     * 選択時のみ両端ハンドル
     */

    if (
        state.selectedConnectionId ===
        connection.id
    ) {

        const startHandle =
            createConnectionHandle(
                sx,
                sy,
                'start'
            );


        const endHandle =
            createConnectionHandle(
                ex,
                ey,
                'end'
            );


        els.videoConnections.appendChild(
            startHandle
        );

        els.videoConnections.appendChild(
            endHandle
        );
    }
}


function createConnectionHandle(
    x,
    y,
    type
) {

    const handle =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );

    handle.classList.add(
        'connection-handle',
        type
    );

    handle.setAttribute(
        'cx',
        x
    );

    handle.setAttribute(
        'cy',
        y
    );

    handle.setAttribute(
        'r',
        7
    );


    handle.addEventListener(
        'pointerdown',
        event => {

            event.stopPropagation();

            beginConnectionHandleDrag(
                event,
                type
            );
        }
    );


    return handle;
}


function selectConnection(id) {

    const connection =
        getConnection(id);

    if (!connection) {
        return;
    }


    state.selectedIds.clear();

    state.selectedConnectionId =
        id;


    renderAll();
}


function beginConnectionHandleDrag(
    event,
    type
) {

    const connection =
        getConnection(
            state.selectedConnectionId
        );


    if (!connection) {
        return;
    }


    state.connectionDrag = {

        id:
            connection.id,

        type,

        startX:
            event.clientX,

        startY:
            event.clientY,

        original:
            type === 'start'
                ? connection.fromAnchor
                : connection.toAnchor
    };


    window.addEventListener(
        'pointermove',
        moveConnectionHandle
    );


    window.addEventListener(
        'pointerup',
        endConnectionHandle,
        {
            once:true
        }
    );
}


function moveConnectionHandle(event) {

    const drag =
        state.connectionDrag;

    if (!drag) {
        return;
    }


    const connection =
        getConnection(
            drag.id
        );


    if (!connection) {
        return;
    }


    const rect =
        els.videoWrap.getBoundingClientRect();


    const x =
        clamp(
            (
                event.clientX -
                rect.left
            ) /
            rect.width *
            100,
            0,
            100
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
            100
        );


    const target =
        drag.type === 'start'
            ? get(connection.from)
            : get(connection.to);


    if (!target) {
        return;
    }


    const candidates = [
        {
            anchor:'top',
            x:target.x + target.w / 2,
            y:target.y
        },
        {
            anchor:'right',
            x:target.x + target.w,
            y:target.y + target.h / 2
        },
        {
            anchor:'bottom',
            x:target.x + target.w / 2,
            y:target.y + target.h
        },
        {
            anchor:'left',
            x:target.x,
            y:target.y + target.h / 2
        }
    ];


    candidates.sort(
        (a,b) =>
            distance(
                a.x,
                a.y,
                x,
                y
            ) -
            distance(
                b.x,
                b.y,
                x,
                y
            )
    );


    const nearest =
        candidates[0];


    if (
        drag.type === 'start'
    ) {

        connection.fromAnchor =
            nearest.anchor;

    } else {

        connection.toAnchor =
            nearest.anchor;
    }


    markDirty();

    renderVideoConnections();

    renderRightMenu();
}


function endConnectionHandle() {

    state.connectionDrag = null;

    window.removeEventListener(
        'pointermove',
        moveConnectionHandle
    );

    renderAll();
}


function distance(
    x1,
    y1,
    x2,
    y2
) {

    return Math.sqrt(
        Math.pow(x1 - x2, 2) +
        Math.pow(y1 - y2, 2)
    );
}


/* ----------------------------------------
   再生位置
---------------------------------------- */

function updatePlayhead() {

    if (!canEdit()) {

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
        `calc(var(--label) + ${x}px)`;


    els.playheadLabel.textContent =
        time(state.currentTime);


    els.readout.textContent =
        time(state.currentTime) +
        ' / ' +
        time(state.duration);
}


function seek(value) {

    state.currentTime =
        clamp(
            Number(value) || 0,
            0,
            state.duration
        );


    try {

        els.video.currentTime =
            state.currentTime;

    } catch (_) {}


    renderOverlay();

    renderVideoConnections();

    updatePlayhead();
}


function updateHint() {

    if (
        state.selectedConnectionId
    ) {

        els.hint.textContent =
            '接続線を選択中';

        return;
    }


    els.hint.textContent =
        state.selectedIds.size
            ? state.selectedIds.size +
              '要素選択中'
            : '';
}


/* ----------------------------------------
   タイムライン操作
---------------------------------------- */

function beginTimelineDrag(event) {

    const bar =
        event.currentTarget;

    const element =
        get(bar.dataset.id);


    if (!element) {
        return;
    }


    event.stopPropagation();

    event.preventDefault();


    const handle =
        event.target.closest(
            '.handle'
        );


    const lane =
        bar.parentElement;


    state.drag = {

        type:
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

        x:
            event.clientX,

        original: {

            start:
                element.start,

            end:
                element.end
        },

        laneWidth:
            lane.getBoundingClientRect().width
    };


    bar.setPointerCapture?.(
        event.pointerId
    );


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


function moveTimelineDrag(event) {

    const drag =
        state.drag;

    if (
        !drag ||
        drag.type !== 'timeline'
    ) {
        return;
    }


    const element =
        get(drag.id);


    if (!element) {
        return;
    }


    const delta =
        (
            event.clientX -
            drag.x
        ) /
        Math.max(
            1,
            drag.laneWidth
        ) *
        state.duration;


    const minimum =
        .05;


    if (
        drag.mode ===
        'move'
    ) {

        const length =
            drag.original.end -
            drag.original.start;


        element.start =
            clamp(
                drag.original.start +
                delta,
                0,
                state.duration -
                length
            );


        element.end =
            element.start +
            length;

    } else if (
        drag.edge ===
        'left'
    ) {

        element.start =
            clamp(
                drag.original.start +
                delta,
                0,
                drag.original.end -
                minimum
            );

    } else {

        element.end =
            clamp(
                drag.original.end +
                delta,
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

    renderRightMenu();
}


function endTimelineDrag() {

    state.drag = null;

    window.removeEventListener(
        'pointermove',
        moveTimelineDrag
    );

    renderAll();
}


/* ----------------------------------------
   右メニュー
---------------------------------------- */

function renderRightMenu() {

    if (
        state.selectedConnectionId
    ) {

        renderConnectionMenu();

        return;
    }


    if (
        state.selectedIds.size === 1
    ) {

        const id =
            [...state.selectedIds][0];

        const element =
            get(id);


        if (element) {

            renderElementMenu(
                element
            );

            return;
        }
    }


    els.rightMenuTitle.textContent =
        'プロパティ';

    els.rightMenuType.textContent =
        '未選択';

    els.rightMenuEmpty.classList.remove(
        'hidden'
    );

    els.elementProperties.classList.add(
        'hidden'
    );

    els.connectionProperties.classList.add(
        'hidden'
    );
}


function renderElementMenu(
    element
) {

    els.rightMenuTitle.textContent =
        element.name;


    els.rightMenuType.textContent =
        element.type === 'text'
            ? 'テキスト'
            : element.type === 'box'
                ? '強調枠'
                : 'スキップ';


    els.rightMenuEmpty.classList.add(
        'hidden'
    );

    els.elementProperties.classList.remove(
        'hidden'
    );

    els.connectionProperties.classList.add(
        'hidden'
    );


    $('sideElementName').value =
        element.name;

    $('sideElementText').value =
        element.text;

    $('sideX').value =
        Number(element.x.toFixed(1));

    $('sideY').value =
        Number(element.y.toFixed(1));

    $('sideW').value =
        Number(element.w.toFixed(1));

    $('sideH').value =
        Number(element.h.toFixed(1));

    $('sideStart').value =
        element.start;

    $('sideEnd').value =
        element.end;

    $('sideFontSize').value =
        element.fontSize;

    $('sideFontWeight').value =
        element.fontWeight;

    $('sideColor').value =
        element.color;


    const isText =
        element.type === 'text';

    const isBox =
        element.type === 'box';


    $('sideTextRow')
        .classList
        .toggle(
            'hidden',
            !isText
        );


    $('sideTextStyleGroup')
        .classList
        .toggle(
            'hidden',
            !isText
        );


    $('shapeGroup')
        .classList
        .toggle(
            'hidden',
            !isBox
        );


    updateShapeButtons(
        element.shape
    );
}


function renderConnectionMenu() {

    const connection =
        getConnection(
            state.selectedConnectionId
        );


    if (!connection) {

        state.selectedConnectionId =
            null;

        renderRightMenu();

        return;
    }


    els.rightMenuTitle.textContent =
        '接続線';

    els.rightMenuType.textContent =
        '要素間接続';


    els.rightMenuEmpty.classList.add(
        'hidden'
    );

    els.elementProperties.classList.add(
        'hidden'
    );

    els.connectionProperties.classList.remove(
        'hidden'
    );


    $('connectionStyle').value =
        connection.style;

    $('connectionWidth').value =
        connection.width;

    $('connectionColor').value =
        connection.color;

    $('connectionFromAnchor').value =
        connection.fromAnchor;

    $('connectionToAnchor').value =
        connection.toAnchor;


    updateLinePreview(
        connection
    );
}


function updateLinePreview(
    connection
) {

    const preview =
        $('linePreview');


    preview.className = '';


    if (
        connection.style ===
        'dashed'
    ) {

        preview.classList.add(
            'dashed'
        );

    } else if (
        connection.style ===
        'dotted'
    ) {

        preview.classList.add(
            'dotted'
        );
    }


    preview.style.borderTop =
        `${connection.width}px ${connection.style === 'solid' ? 'solid' : connection.style} ${connection.color}`;
}


function updateShapeButtons(
    shape
) {

    document
        .querySelectorAll(
            '.shape-button'
        )
        .forEach(
            button => {

                button.classList.toggle(
                    'active',
                    button.dataset.shape ===
                    shape
                );
            }
        );
}


/* ----------------------------------------
   強調枠形状
---------------------------------------- */

document
    .querySelectorAll(
        '.shape-button'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                () => {

                    const id =
                        [...state.selectedIds][0];

                    const element =
                        get(id);


                    if (
                        !element ||
                        element.type !==
                        'box'
                    ) {
                        return;
                    }


                    element.shape =
                        button.dataset.shape;


                    markDirty();

                    renderAll();
                }
            );
        }
    );


$('elementShape')
    .addEventListener(
        'change',
        event => {

            const element =
                get(
                    state.modalElementId
                );


            if (!element) {
                return;
            }


            element.shape =
                event.target.value;
        }
    );


/* ----------------------------------------
   右メニュー変更
---------------------------------------- */

$('sideElementName')
    .addEventListener(
        'input',
        event => {

            const element =
                getSelectedElement();

            if (!element) {
                return;
            }

            element.name =
                event.target.value;

            markDirty();

            renderTimeline();
        }
    );


$('sideElementText')
    .addEventListener(
        'input',
        event => {

            const element =
                getSelectedElement();

            if (!element) {
                return;
            }

            element.text =
                event.target.value;

            markDirty();

            renderOverlay();
        }
    );


[
    ['sideX','x',0,99],
    ['sideY','y',0,99],
    ['sideW','w',1,100],
    ['sideH','h',1,100],
    ['sideStart','start',0,null],
    ['sideEnd','end',0,null]
].forEach(
    ([id,key,min,max]) => {

        $(id).addEventListener(
            'change',
            event => {

                const element =
                    getSelectedElement();

                if (!element) {
                    return;
                }


                let value =
                    Number(
                        event.target.value
                    );


                if (
                    !Number.isFinite(value)
                ) {
                    return;
                }


                if (max !== null) {
                    value =
                        clamp(
                            value,
                            min,
                            max
                        );
                }


                if (
                    key === 'start' &&
                    value >= element.end
                ) {

                    value =
                        Math.max(
                            0,
                            element.end - .05
                        );
                }


                if (
                    key === 'end' &&
                    value <= element.start
                ) {

                    value =
                        Math.min(
                            state.duration,
                            element.start + .05
                        );
                }


                element[key] =
                    value;


                markDirty();

                renderAll();
            }
        );
    }
);


$('sideFontSize')
    .addEventListener(
        'change',
        event => {

            const element =
                getSelectedElement();

            if (!element) {
                return;
            }


            element.fontSize =
                clamp(
                    Number(event.target.value) || 28,
                    8,
                    100
                );


            markDirty();

            renderAll();
        }
    );


$('sideFontWeight')
    .addEventListener(
        'change',
        event => {

            const element =
                getSelectedElement();

            if (!element) {
                return;
            }


            element.fontWeight =
                event.target.value;

            markDirty();

            renderAll();
        }
    );


$('sideColor')
    .addEventListener(
        'change',
        event => {

            const element =
                getSelectedElement();

            if (!element) {
                return;
            }


            element.color =
                event.target.value;

            markDirty();

            renderAll();
        }
    );


$('sideDeleteElement')
    .addEventListener(
        'click',
        () => {

            deleteSelected();
        }
    );


$('sideEditDetail')
    .addEventListener(
        'click',
        () => {

            const element =
                getSelectedElement();

            if (element) {
                openElement(
                    element.id
                );
            }
        }
    );


function getSelectedElement() {

    if (
        state.selectedIds.size !== 1
    ) {
        return null;
    }


    return get(
        [...state.selectedIds][0]
    );
}


/* ----------------------------------------
   接続線メニュー変更
---------------------------------------- */

$('connectionStyle')
    .addEventListener(
        'change',
        event => {

            const connection =
                getConnection(
                    state.selectedConnectionId
                );

            if (!connection) {
                return;
            }


            connection.style =
                event.target.value;

            markDirty();

            renderAll();
        }
    );


$('connectionWidth')
    .addEventListener(
        'change',
        event => {

            const connection =
                getConnection(
                    state.selectedConnectionId
                );

            if (!connection) {
                return;
            }


            connection.width =
                clamp(
                    Number(
                        event.target.value
                    ) || 3,
                    1,
                    12
                );

            markDirty();

            renderAll();
        }
    );


$('connectionColor')
    .addEventListener(
        'change',
        event => {

            const connection =
                getConnection(
                    state.selectedConnectionId
                );

            if (!connection) {
                return;
            }


            connection.color =
                event.target.value;

            markDirty();

            renderAll();
        }
    );


$('connectionFromAnchor')
    .addEventListener(
        'change',
        event => {

            const connection =
                getConnection(
                    state.selectedConnectionId
                );

            if (!connection) {
                return;
            }


            connection.fromAnchor =
                event.target.value;

            markDirty();

            renderAll();
        }
    );


$('connectionToAnchor')
    .addEventListener(
        'change',
        event => {

            const connection =
                getConnection(
                    state.selectedConnectionId
                );

            if (!connection) {
                return;
            }


            connection.toAnchor =
                event.target.value;

            markDirty();

            renderAll();
        }
    );


$('sideDeleteConnection')
    .addEventListener(
        'click',
        () => {

            if (
                !state.selectedConnectionId
            ) {
                return;
            }


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
        }
    );


/* ----------------------------------------
   要素編集
---------------------------------------- */

function openElement(id) {

    const element =
        get(id);

    if (!element) {
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

    $('elementShape').value =
        element.shape;


    const isText =
        element.type === 'text';

    const isBox =
        element.type === 'box';


    $('textField')
        .classList
        .toggle(
            'hidden',
            !isText
        );


    $('fontField')
        .classList
        .toggle(
            'hidden',
            !isText
        );


    $('weightField')
        .classList
        .toggle(
            'hidden',
            !isText
        );


    $('modalShapeField')
        .classList
        .toggle(
            'hidden',
            !isBox
        );


    buildColors(
        element.color
    );


    els.modal.style.display =
        'flex';
}


function buildColors(active) {

    const grid =
        $('colorGrid');

    grid.innerHTML = '';


    state.colors.forEach(
        currentColor => {

            const button =
                document.createElement(
                    'button'
                );

            button.type =
                'button';

            button.className =
                'color-choice' +
                (
                    currentColor === active
                        ? ' active'
                        : ''
                );

            button.style.background =
                currentColor;

            button.dataset.color =
                currentColor;


            button.onclick =
                () => {

                    grid
                        .querySelectorAll(
                            '.active'
                        )
                        .forEach(
                            node =>
                                node.classList.remove(
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


function closeElement() {

    els.modal.style.display =
        'none';

    state.modalElementId =
        null;
}


function saveElement() {

    const element =
        get(
            state.modalElementId
        );


    if (!element) {
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


    if (
        !Number.isFinite(start) ||
        !Number.isFinite(end) ||
        start < 0 ||
        end <= start ||
        end > state.duration
    ) {

        toast(
            '開始・終了時間が不正です',
            true
        );

        return;
    }


    element.name =
        $('elementName').value.trim() ||
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

    element.shape =
        $('elementShape').value;

    element.color =
        $('colorGrid .active')?.dataset.color ||
        element.color;


    markDirty();

    closeElement();

    renderAll();
}


/* ----------------------------------------
   要素追加
---------------------------------------- */

function addElement(
    type
) {

    if (!canEdit()) {

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
            state.duration - .05
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
            xy.x,

        y:
            xy.y,

        w:
            35,

        h:
            18,

        color:
            color(type),

        fontSize:
            28,

        fontWeight:
            '700',

        shape:
            'rectangle'
    };


    state.project.elements.push(
        element
    );


    state.selectedIds.clear();

    state.selectedIds.add(
        element.id
    );

    state.selectedConnectionId =
        null;


    markDirty();

    renderAll();

    openElement(
        element.id
    );
}


/* ----------------------------------------
   削除
---------------------------------------- */

function deleteSelected() {

    if (
        state.selectedConnectionId
    ) {

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


    if (
        !state.selectedIds.size
    ) {
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
                !ids.has(connection.from) &&
                !ids.has(connection.to)
        );


    state.selectedIds.clear();

    markDirty();

    renderAll();
}


function disconnectSelected() {

    if (
        state.selectedConnectionId
    ) {

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


    if (
        state.selectedIds.size === 2
    ) {

        const [
            first,
            second
        ] =
            [...state.selectedIds];


        state.project.connections =
            state.project.connections.filter(
                connection =>
                    !(
                        (
                            connection.from === first &&
                            connection.to === second
                        ) ||
                        (
                            connection.from === second &&
                            connection.to === first
                        )
                    )
            );


        markDirty();

        renderAll();
    }
}


/* ----------------------------------------
   コンテキストメニュー
---------------------------------------- */

function openMenu(
    x,
    y,
    id,
    connection
) {

    state.contextElementId =
        id;

    state.contextConnectionId =
        connection;


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


function closeMenu() {

    els.menu.style.display =
        'none';
}


function createAt(type) {

    addElement(type);

    closeMenu();
}


/* ----------------------------------------
   保存
---------------------------------------- */

function saveLocal() {

    if (!state.project) {
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
        JSON.stringify(project)
    );


    markClean();

    toast(
        '保存しました'
    );
}


function listStorage() {

    const list =
        $('storageList');

    list.innerHTML = '';


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

                try {

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
                            <div class="meta"></div>
                        </div>
                        `;


                    row.querySelector(
                        '.name'
                    ).textContent =
                        project.name;


                    row.querySelector(
                        '.meta'
                    ).textContent =
                        (
                            project.videoName ||
                            ''
                        ) +
                        ' / ' +
                        (
                            project.elements?.length ||
                            0
                        ) +
                        '要素 / ' +
                        (
                            project.connections?.length ||
                            0
                        ) +
                        '接続';


                    const load =
                        document.createElement(
                            'button'
                        );

                    load.className =
                        'btn small';

                    load.textContent =
                        '読込';


                    load.onclick =
                        () => {

                            loadProject(
                                project
                            );

                            els.storageModal
                                .style.display =
                                'none';
                        };


                    row.appendChild(
                        load
                    );

                    list.appendChild(
                        row
                    );

                } catch (_) {}
            }
        );


    els.storageModal.style.display =
        'flex';
}


/* ----------------------------------------
   読込
---------------------------------------- */

function loadProject(project) {

    try {

        state.project =
            normalizeProject(
                structuredClone(
                    project
                )
            );


        state.duration =
            state.project.duration;


        state.selectedIds.clear();

        state.selectedConnectionId =
            null;

        state.currentTime =
            0;


        if (
            els.video.duration &&
            Number.isFinite(
                els.video.duration
            )
        ) {

            state.duration =
                els.video.duration;

            state.project.duration =
                state.duration;
        }


        enableEditor();

        renderAll();

        markClean();

        toast(
            'プロジェクトを読み込みました'
        );

    } catch (error) {

        toast(
            'プロジェクトを読み込めません：' +
            error.message,
            true
        );
    }
}


function enableEditor() {

    els.play.disabled =
        false;

    els.stop.disabled =
        false;

    els.save.disabled =
        false;

    els.empty.style.display =
        'none';
}


function disableEditor() {

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

    els.videoConnections.innerHTML =
        '';

    els.tracks.innerHTML =
        '';

    els.playhead.style.display =
        'none';

    renderRightMenu();
}


/* ----------------------------------------
   動画
---------------------------------------- */

function handleVideo(file) {

    if (
        !file ||
        !file.type.startsWith(
            'video/'
        )
    ) {

        toast(
            '動画ファイルを選択してください',
            true
        );

        return;
    }


    if (state.videoUrl) {

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

    disableEditor();


    els.video.src =
        state.videoUrl;


    setStatus(
        '動画を読み込んでいます…'
    );
}


function newProject() {

    state.project =
        null;

    state.selectedIds.clear();

    state.selectedConnectionId =
        null;

    state.duration =
        0;

    state.currentTime =
        0;


    if (state.videoUrl) {

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


/* ----------------------------------------
   書き出し
---------------------------------------- */

function exportProject() {

    if (!state.project) {
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


    const url =
        URL.createObjectURL(
            blob
        );


    const link =
        document.createElement(
            'a'
        );


    link.href =
        url;

    link.download =
        (
            state.project.name ||
            'project'
        ) +
        '.json';


    document.body.appendChild(
        link
    );

    link.click();

    document.body.removeChild(
        link
    );


    setTimeout(
        () =>
            URL.revokeObjectURL(
                url
            ),
        1000
    );
}


/* ----------------------------------------
   イベント
---------------------------------------- */

els.open.onclick =
    () =>
        els.file.click();


els.file.onchange =
    () =>
        handleVideo(
            els.file.files[0]
        );


els.newBtn.onclick =
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

        if (!file) {
            return;
        }


        try {

            loadProject(
                JSON.parse(
                    await file.text()
                )
            );

        } catch (error) {

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


        if (!state.project) {

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

        if (
            !els.video.paused
        ) {

            state.currentTime =
                els.video.currentTime;

            updatePlayhead();

            renderOverlay();

            renderVideoConnections();
        }
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

        if (!canEdit()) {
            return;
        }


        if (
            els.video.paused
        ) {

            try {

                await els.video.play();

                els.play.textContent =
                    'Ⅱ';

            } catch (error) {

                toast(
                    '再生できません',
                    true
                );
            }

        } else {

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


els.zoom.oninput =
    () => {

        els.zoomValue.textContent =
            Number(
                els.zoom.value
            ).toFixed(1) +
            '×';

        renderAxis();

        updatePlayhead();
    };


/* ----------------------------------------
   タイムラインクリック
---------------------------------------- */

els.content.addEventListener(
    'pointerdown',
    event => {

        if (
            event.target.closest(
                '.element-bar'
            ) ||
            event.target.closest(
                '.playhead'
            )
        ) {
            return;
        }


        if (
            event.target.closest(
                '.axis-track'
            ) ||
            event.target.closest(
                '.track-lane'
            )
        ) {

            seek(
                xToTime(
                    event.clientX
                )
            );
        }
    }
);


/* ----------------------------------------
   動画クリック
---------------------------------------- */

els.videoWrap.addEventListener(
    'click',
    event => {

        if (
            event.target.closest(
                '.overlay-element'
            ) ||
            event.target.closest(
                '.video-connection'
            ) ||
            event.target.closest(
                '.video-connection-hit'
            ) ||
            event.target.closest(
                '.connection-handle'
            )
        ) {
            return;
        }


        state.selectedIds.clear();

        state.selectedConnectionId =
            null;

        renderAll();
    }
);


/* ----------------------------------------
   動画右クリック
---------------------------------------- */

els.videoWrap.addEventListener(
    'contextmenu',
    event => {

        event.preventDefault();

        if (!canEdit()) {
            return;
        }


        const rect =
            els.video.getBoundingClientRect();


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


        state.contextElementId =
            null;

        state.contextConnectionId =
            null;

        state.pendingXY = {
            x,
            y
        };


        openMenu(
            event.clientX,
            event.clientY,
            null,
            null
        );
    }
);


/* ----------------------------------------
   右クリックメニュー
---------------------------------------- */

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

        if (
            state.contextElementId
        ) {

            openElement(
                state.contextElementId
            );
        }

        closeMenu();
    };


$('ctxConnect').onclick =
    () => {

        if (
            state.selectedIds.size ===
            2
        ) {

            connectSelected();

        } else {

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

        if (
            !event.target.closest(
                '.context-menu'
            )
        ) {

            closeMenu();
        }
    }
);


/* ----------------------------------------
   キーボード
---------------------------------------- */

document.addEventListener(
    'keydown',
    event => {

        if (
            event.key ===
            'Escape'
        ) {

            closeMenu();

            closeElement();
        }


        if (
            (
                event.key ===
                'Delete' ||
                event.key ===
                'Backspace'
            ) &&
            ![
                'INPUT',
                'TEXTAREA',
                'SELECT'
            ].includes(
                document.activeElement.tagName
            )
        ) {

            deleteSelected();
        }


        if (
            event.key === ' ' &&
            ![
                'INPUT',
                'TEXTAREA'
            ].includes(
                document.activeElement.tagName
            )
        ) {

            event.preventDefault();

            els.play.click();
        }
    }
);


/* ----------------------------------------
   モーダル
---------------------------------------- */

$('elementModalClose').onclick =
    closeElement;


$('elementModalCancel').onclick =
    closeElement;


$('elementModalSave').onclick =
    saveElement;


$('storageClose').onclick =
    () =>
        els.storageModal.style.display =
            'none';


/* ----------------------------------------
   リサイズ・スクロール
---------------------------------------- */

window.addEventListener(
    'resize',
    () => {

        renderVideoConnections();

        renderAxis();

        updatePlayhead();
    }
);


els.scroll.addEventListener(
    'scroll',
    () => {

        updatePlayhead();
    }
);


/* ----------------------------------------
   描画ループ
---------------------------------------- */

function animationLoop() {

    if (
        state.selectedConnectionId
    ) {

        renderVideoConnections();
    }

    requestAnimationFrame(
        animationLoop
    );
}


animationLoop();


/* ----------------------------------------
   初期状態
---------------------------------------- */

renderRightMenu();

</script>

</body>
</html>

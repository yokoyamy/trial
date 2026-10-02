<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集・注釈</title>

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
    --right:270px;
    --label:105px;
}

*{box-sizing:border-box}

html,body{
    width:100%;
    height:100%;
    margin:0;
    overflow:hidden;
    background:var(--bg);
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
}

button,input,select,textarea{font:inherit}

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
    white-space:nowrap;
    margin-right:8px;
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

.main{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}

.editor-area{
    flex:1;
    min-height:0;
    display:flex;
    overflow:hidden;
}

.video-area{
    flex:1;
    min-width:0;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:12px;
    background:#050a11;
    overflow:hidden;
}

.video-wrap{
    position:relative;
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
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

.overlay-connections{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
    z-index:20;
}

.connection-path{
    fill:none;
    pointer-events:stroke;
    cursor:pointer;
}

.connection-path.selected{
    filter:drop-shadow(0 0 4px #60a5fa);
}

.connection-endpoint{
    cursor:grab;
    pointer-events:auto;
}

.connection-endpoint.selected{
    stroke:#fff;
    stroke-width:3;
}

.overlay-element{
    position:absolute;
    min-width:30px;
    min-height:20px;
    pointer-events:auto;
    user-select:none;
    touch-action:none;
    z-index:30;
}

.overlay-element.selected{
    outline:2px solid #60a5fa;
    outline-offset:2px;
}

.overlay-element.multi-selected{
    outline:2px solid #fbbf24;
}

.overlay-content{
    width:100%;
    height:100%;
    overflow:hidden;
    position:relative;
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
    text-align:center;
}

.overlay-box{
    width:100%;
    height:100%;
    border:3px solid currentColor;
    background:#ffffff08;
}

.overlay-box.rectangle{
    border-radius:0;
}

.overlay-box.rounded{
    border-radius:18px;
}

.overlay-box.ellipse{
    border-radius:50%;
}

.overlay-box.diamond{
    transform:rotate(45deg) scale(.7);
    border-radius:4px;
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

.resize-handle{
    position:absolute;
    width:10px;
    height:10px;
    background:#60a5fa;
    border:2px solid #fff;
    border-radius:50%;
    z-index:50;
    display:none;
}

.overlay-element.selected .resize-handle{
    display:block;
}

.resize-handle.nw{left:-6px;top:-6px;cursor:nwse-resize}
.resize-handle.ne{right:-6px;top:-6px;cursor:nesw-resize}
.resize-handle.sw{left:-6px;bottom:-6px;cursor:nesw-resize}
.resize-handle.se{right:-6px;bottom:-6px;cursor:nwse-resize}

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

.properties{
    width:var(--right);
    flex:none;
    background:#111b2a;
    border-left:1px solid var(--line);
    overflow-y:auto;
    display:flex;
    flex-direction:column;
}

.properties-header{
    height:48px;
    flex:none;
    display:flex;
    align-items:center;
    padding:0 14px;
    border-bottom:1px solid var(--line);
    font-weight:700;
}

.properties-empty{
    padding:25px 16px;
    color:#64748b;
    font-size:13px;
    line-height:1.7;
}

.property-section{
    padding:14px;
    border-bottom:1px solid #263445;
}

.property-title{
    margin-bottom:12px;
    font-size:12px;
    color:#94a3b8;
    font-weight:700;
}

.field{
    display:grid;
    gap:5px;
    margin-bottom:10px;
}

.field:last-child{
    margin-bottom:0;
}

.field label{
    color:#94a3b8;
    font-size:11px;
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
    min-height:65px;
    resize:vertical;
}

.inline{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:7px;
}

.color-row{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.color-choice{
    width:25px;
    height:25px;
    border:2px solid transparent;
    border-radius:4px;
    padding:0;
}

.color-choice.active{
    border-color:#fff;
    box-shadow:0 0 0 1px #60a5fa;
}

.property-actions{
    display:flex;
    gap:6px;
}

.property-actions .btn{
    flex:1;
}

.timeline-panel{
    height:300px;
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
    overflow:auto;
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
    height:56px;
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
}

.playhead-label{
    position:absolute;
    top:-22px;
    left:5px;
    padding:2px 4px;
    background:var(--red);
    color:#fff;
    font-size:10px;
    white-space:nowrap;
    border-radius:3px;
}

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
    box-shadow:0 10px 30px #0006;
}

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

@media(max-width:850px){
    :root{--right:225px;--label:90px}
    .brand{display:none}
}

@media(max-width:650px){
    :root{--right:200px;--label:75px}
    .properties{width:var(--right)}
    .topbar .btn:not(.primary){display:none}
}
</style>
</head>

<body>

<div class="app">

<header class="topbar">
    <div class="brand">動画編集・注釈</div>

    <button class="btn primary" id="openVideoBtn">動画を読み込む</button>
    <input class="file-input" id="videoFile" type="file" accept="video/*">

    <button class="btn" id="newProjectBtn">新規</button>
    <button class="btn" id="saveProjectBtn" disabled>保存</button>

    <span class="status" id="statusText">動画を読み込んでください</span>
</header>


<main class="main">

<div class="editor-area">

<section class="video-area">

    <div class="video-wrap" id="videoWrap">

        <video
            id="video"
            preload="metadata"
            playsinline
        ></video>

        <div class="video-overlay" id="videoOverlay">

            <svg
                id="connectionSvg"
                class="overlay-connections"
                preserveAspectRatio="none"
            ></svg>

        </div>

        <div class="empty-video" id="emptyVideo">
            <strong>動画を読み込んでください</strong>
            動画上で右クリックすると要素を追加できます
        </div>

    </div>

</section>


<aside class="properties">

    <div class="properties-header">
        <span id="propertiesTitle">選択してください</span>
    </div>

    <div id="propertiesBody">

        <div class="properties-empty">
            動画上またはタイムライン上の要素を選択すると、
            選択した対象に応じた操作メニューが表示されます。
        </div>

    </div>

</aside>

</div>


<section class="timeline-panel">

    <div class="timeline-toolbar">

        <button class="btn small" id="playBtn" disabled>▶</button>
        <button class="btn small" id="stopBtn" disabled>■</button>

        <span class="time-readout" id="timeReadout">
            00:00.000 / 00:00.000
        </span>

        <span class="selection-hint" id="selectionHint"></span>

        <div class="zoom-control">
            <span>時間スケール</span>
            <input
                id="zoomRange"
                type="range"
                min="1"
                max="5"
                step=".1"
                value="1"
            >
            <span id="zoomValue">1.0×</span>
        </div>

    </div>


    <div class="timeline-scroll" id="timelineScroll">

        <div class="timeline-content" id="timelineContent">

            <div class="axis-row">

                <div class="axis-label">時間</div>

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
                >00:00.000</span>
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
    <button id="ctxConnect">選択2要素を接続</button>
    <button id="ctxDisconnect">接続を解除</button>
    <button id="ctxDelete">削除</button>

</div>


<div class="toast" id="toast"></div>


<script>
'use strict';

const $ = id => document.getElementById(id);

const els = {
    video: $('video'),
    videoWrap: $('videoWrap'),
    overlay: $('videoOverlay'),
    connectionSvg: $('connectionSvg'),
    empty: $('emptyVideo'),

    file: $('videoFile'),
    open: $('openVideoBtn'),
    newBtn: $('newProjectBtn'),
    save: $('saveProjectBtn'),

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

    propertiesTitle: $('propertiesTitle'),
    propertiesBody: $('propertiesBody'),

    menu: $('contextMenu'),
    toast: $('toast')
};


const state = {

    project: null,

    duration: 0,
    currentTime: 0,

    videoUrl: '',

    selectedIds: new Set(),
    selectedConnectionId: null,

    pendingXY: null,

    drag: null,

    dirty: false,

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
        '#a3e635'
    ]
};


function uid(prefix='id'){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,8);
}


function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}


function time(v){

    v = Math.max(0,Number(v) || 0);

    return (
        String(Math.floor(v / 60)).padStart(2,'0') +
        ':' +
        String(Math.floor(v % 60)).padStart(2,'0') +
        '.' +
        String(Math.floor((v % 1) * 1000)).padStart(3,'0')
    );
}


function shortTime(v){

    if(v < 60){
        return v.toFixed(v % 1 ? 1 : 0) + 's';
    }

    return (
        String(Math.floor(v / 60)).padStart(2,'0') +
        ':' +
        String(Math.floor(v % 60)).padStart(2,'0')
    );
}


function typeColor(type){

    if(type === 'text') return '#60a5fa';
    if(type === 'box') return '#22c55e';

    return '#f97316';
}


function rgba(hex,a){

    const n = parseInt(hex.slice(1),16);

    return `rgba(
        ${n >> 16},
        ${(n >> 8) & 255},
        ${n & 255},
        ${a}
    )`;
}


function toast(message,error=false){

    els.status.textContent = message;

    els.toast.textContent = message;
    els.toast.style.display = 'block';

    els.toast.style.borderColor =
        error ? '#ef4444' : '#475569';

    clearTimeout(state.toastTimer);

    state.toastTimer = setTimeout(() => {
        els.toast.style.display = 'none';
    },2500);
}


function markDirty(){

    state.dirty = true;

    els.status.textContent =
        state.project ?
        `変更あり：${state.project.name}` :
        '変更あり';
}


function markClean(){

    state.dirty = false;

    els.status.textContent =
        state.project ?
        `編集中：${state.project.name}` :
        '動画を読み込んでください';
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


function canEdit(){

    return !!state.project &&
        state.duration > 0;
}


function visible(e){

    return (
        state.currentTime >= e.start &&
        state.currentTime < e.end
    );
}


function emptyProject(name='新規プロジェクト'){

    return {
        version: 6,
        name,
        duration: state.duration,
        videoName: 'video',
        elements: [],
        connections: []
    };
}


/* ================================
   全体描画
================================ */

function renderAll(){

    renderTimeline();
    renderOverlay();
    renderConnections();
    updatePlayhead();
    updateProperties();
    updateHint();
}


/* ================================
   タイムライン
================================ */

function timelineGeometry(){

    const r =
        els.axis.getBoundingClientRect();

    return {
        left:r.left,
        width:r.width
    };
}


function timeToX(t){

    const g = timelineGeometry();

    return g.left +
        clamp(t / state.duration,0,1) *
        g.width;
}


function xToTime(clientX){

    const g = timelineGeometry();

    if(!g.width || !state.duration){
        return 0;
    }

    return clamp(
        (clientX - g.left) /
        g.width *
        state.duration,
        0,
        state.duration
    );
}


function chooseStep(duration,width){

    const target = 85;

    const approx =
        duration /
        Math.max(1,width / target);

    return [
        .1,.25,.5,1,2,5,10,
        15,30,60,120,300,600
    ].find(x => x >= approx) || 600;
}


function renderAxis(){

    els.axis.innerHTML = '';

    if(!state.duration) return;

    const width = els.axis.clientWidth;

    const step =
        chooseStep(state.duration,width);

    for(
        let t=0;
        t<=state.duration+.0001;
        t+=step
    ){

        const tick =
            document.createElement('div');

        tick.className = 'tick';

        tick.style.left =
            `${t / state.duration * 100}%`;

        tick.textContent =
            shortTime(t);

        els.axis.appendChild(tick);
    }
}


function renderTimeline(){

    els.tracks.innerHTML = '';

    const groups = [
        ['text','テキスト'],
        ['box','強調枠'],
        ['skip','スキップ']
    ];

    for(const [type,label] of groups){

        const row =
            document.createElement('div');

        row.className = 'track-row';


        const labelNode =
            document.createElement('div');

        labelNode.className = 'track-label';


        const dot =
            document.createElement('span');

        dot.className = 'type-dot';

        dot.style.background =
            typeColor(type);


        labelNode.append(
            dot,
            document.createTextNode(label)
        );


        const lane =
            document.createElement('div');

        lane.className = 'track-lane';


        const grid =
            document.createElement('div');

        grid.className = 'track-grid';

        grid.style.backgroundImage =
            'linear-gradient(to right,rgba(148,163,184,.09) 1px,transparent 1px)';

        if(state.duration){

            grid.style.backgroundSize =
                `${Math.max(10,100/state.duration)}% 100%`;
        }


        lane.appendChild(grid);


        (
            state.project?.elements || []
        )
        .filter(e => e.type === type)
        .forEach(e => {

            lane.appendChild(
                createTimelineBar(e)
            );

        });


        row.append(
            labelNode,
            lane
        );

        els.tracks.appendChild(row);
    }

    renderAxis();
}


function createTimelineBar(e){

    const bar =
        document.createElement('div');

    bar.className = 'element-bar';

    bar.dataset.id = e.id;


    if(state.selectedIds.has(e.id)){
        bar.classList.add('selected');
    }


    bar.style.left =
        `${e.start / state.duration * 100}%`;

    bar.style.width =
        `${Math.max(
            .001,
            (e.end-e.start) /
            state.duration * 100
        )}%`;


    bar.style.color = e.color;

    bar.style.background =
        rgba(e.color,.2);


    const label =
        document.createElement('span');

    label.className = 'bar-label';

    label.textContent =
        `${e.name} ${time(e.start)} ～ ${time(e.end)}`;


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
        ev => {

            ev.stopPropagation();

            selectElement(
                e.id,
                ev.shiftKey
            );
        }
    );


    bar.addEventListener(
        'contextmenu',
        ev => {

            ev.preventDefault();
            ev.stopPropagation();

            selectElement(e.id,false);

            openContextMenu(
                ev.clientX,
                ev.clientY
            );
        }
    );


    return bar;
}


/* ================================
   要素選択
================================ */

function selectElement(id,additive=false){

    const e = getElement(id);

    if(!e) return;


    state.selectedConnectionId = null;


    if(!additive){
        state.selectedIds.clear();
    }


    if(
        additive &&
        state.selectedIds.has(id)
    ){

        state.selectedIds.delete(id);

    }else{

        state.selectedIds.add(id);
    }


    if(
        state.selectedIds.size === 1 &&
        !additive
    ){

        seek(e.start);
    }


    renderAll();
}


function selectConnection(id){

    const c = getConnection(id);

    if(!c) return;


    state.selectedIds.clear();

    state.selectedConnectionId = id;

    renderAll();
}


/* ================================
   動画上の要素
================================ */

function renderOverlay(){

    const old =
        els.overlay.querySelectorAll(
            '.overlay-element'
        );

    old.forEach(n => n.remove());


    if(!canEdit()) return;


    for(const e of state.project.elements){

        if(!visible(e)) continue;


        const node =
            document.createElement('div');

        node.className =
            'overlay-element';

        node.dataset.id = e.id;


        if(state.selectedIds.has(e.id)){
            node.classList.add(
                state.selectedIds.size > 1 ?
                'multi-selected' :
                'selected'
            );
        }


        node.style.left = e.x + '%';
        node.style.top = e.y + '%';
        node.style.width = e.w + '%';
        node.style.height = e.h + '%';
        node.style.color = e.color;


        const content =
            document.createElement('div');

        content.className =
            'overlay-content';


        if(e.type === 'text'){

            const text =
                document.createElement('div');

            text.className =
                'overlay-text';

            text.textContent =
                e.text || e.name;

            text.style.fontSize =
                e.fontSize + 'px';

            text.style.fontWeight =
                e.fontWeight;

            content.appendChild(text);

        }else if(e.type === 'box'){

            const box =
                document.createElement('div');

            box.className =
                'overlay-box ' +
                (e.shape || 'rectangle');

            content.appendChild(box);

        }else{

            const skip =
                document.createElement('div');

            skip.className =
                'overlay-skip';

            skip.textContent =
                'スキップ';

            content.appendChild(skip);
        }


        node.appendChild(content);


        if(state.selectedIds.has(e.id)){

            ['nw','ne','sw','se'].forEach(pos => {

                const handle =
                    document.createElement('span');

                handle.className =
                    `resize-handle ${pos}`;

                handle.dataset.resize =
                    pos;

                node.appendChild(handle);
            });
        }


        node.addEventListener(
            'pointerdown',
            ev => beginOverlayDrag(ev,e)
        );


        node.addEventListener(
            'click',
            ev => {

                ev.stopPropagation();

                selectElement(
                    e.id,
                    ev.shiftKey
                );
            }
        );


        node.addEventListener(
            'contextmenu',
            ev => {

                ev.preventDefault();
                ev.stopPropagation();

                selectElement(e.id,false);

                openContextMenu(
                    ev.clientX,
                    ev.clientY
                );
            }
        );


        els.overlay.appendChild(node);
    }
}


/* ================================
   動画上の移動・サイズ変更
================================ */

function beginOverlayDrag(ev,e){

    ev.preventDefault();
    ev.stopPropagation();


    const node =
        ev.currentTarget;

    const resize =
        ev.target.closest(
            '.resize-handle'
        );


    if(!state.selectedIds.has(e.id)){
        selectElement(e.id,false);
    }


    const rect =
        els.video.getBoundingClientRect();


    state.drag = {

        kind: resize ?
            'overlay-resize' :
            'overlay-move',

        id:e.id,

        resize:resize?.dataset.resize || '',

        startX:ev.clientX,
        startY:ev.clientY,

        rect,

        original:{
            x:e.x,
            y:e.y,
            w:e.w,
            h:e.h
        }
    };


    node.setPointerCapture?.(
        ev.pointerId
    );


    window.addEventListener(
        'pointermove',
        moveOverlayDrag
    );

    window.addEventListener(
        'pointerup',
        endOverlayDrag,
        {once:true}
    );
}


function moveOverlayDrag(ev){

    const d = state.drag;

    if(!d) return;


    const e =
        getElement(d.id);

    if(!e) return;


    const dx =
        (ev.clientX - d.startX) /
        d.rect.width *
        100;

    const dy =
        (ev.clientY - d.startY) /
        d.rect.height *
        100;


    if(d.kind === 'overlay-move'){

        e.x =
            clamp(
                d.original.x + dx,
                0,
                100 - d.original.w
            );

        e.y =
            clamp(
                d.original.y + dy,
                0,
                100 - d.original.h
            );

    }else{

        const minW = 3;
        const minH = 3;

        if(d.resize.includes('e')){

            e.w =
                clamp(
                    d.original.w + dx,
                    minW,
                    100 - d.original.x
                );
        }

        if(d.resize.includes('s')){

            e.h =
                clamp(
                    d.original.h + dy,
                    minH,
                    100 - d.original.y
                );
        }

        if(d.resize.includes('w')){

            const right =
                d.original.x +
                d.original.w;

            e.x =
                clamp(
                    d.original.x + dx,
                    0,
                    right - minW
                );

            e.w =
                right - e.x;
        }

        if(d.resize.includes('n')){

            const bottom =
                d.original.y +
                d.original.h;

            e.y =
                clamp(
                    d.original.y + dy,
                    0,
                    bottom - minH
                );

            e.h =
                bottom - e.y;
        }
    }


    markDirty();

    renderOverlay();
    renderConnections();
    updateProperties();
}


function endOverlayDrag(){

    state.drag = null;

    window.removeEventListener(
        'pointermove',
        moveOverlayDrag
    );

    renderAll();
}


/* ================================
   タイムライン移動
================================ */

function beginTimelineDrag(ev){

    const bar =
        ev.currentTarget;

    const e =
        getElement(bar.dataset.id);

    if(!e) return;


    ev.preventDefault();
    ev.stopPropagation();


    const handle =
        ev.target.closest('.handle');

    const lane =
        bar.parentElement;


    state.drag = {

        kind:'timeline',

        id:e.id,

        mode:handle ?
            'resize' :
            'move',

        edge:
            handle?.dataset.edge || '',

        x:ev.clientX,

        original:{
            start:e.start,
            end:e.end
        },

        laneWidth:
            lane.getBoundingClientRect().width
    };


    bar.setPointerCapture?.(
        ev.pointerId
    );


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


function moveTimelineDrag(ev){

    const d = state.drag;

    if(!d) return;


    const e =
        getElement(d.id);

    if(!e) return;


    const dt =
        (ev.clientX - d.x) /
        Math.max(1,d.laneWidth) *
        state.duration;


    const min = .05;


    if(d.mode === 'move'){

        const len =
            d.original.end -
            d.original.start;

        e.start =
            clamp(
                d.original.start + dt,
                0,
                state.duration - len
            );

        e.end =
            e.start + len;

    }else if(d.edge === 'left'){

        e.start =
            clamp(
                d.original.start + dt,
                0,
                d.original.end - min
            );

    }else{

        e.end =
            clamp(
                d.original.end + dt,
                d.original.start + min,
                state.duration
            );
    }


    markDirty();

    renderTimeline();
    renderOverlay();
    renderConnections();
}


function endTimelineDrag(){

    state.drag = null;

    window.removeEventListener(
        'pointermove',
        moveTimelineDrag
    );

    renderAll();
}


/* ================================
   接続線
================================ */

const anchors = [
    'left',
    'right',
    'top',
    'bottom'
];


function anchorPoint(e,position){

    let x = e.x + e.w / 2;
    let y = e.y + e.h / 2;

    if(position === 'left'){
        x = e.x;
    }

    if(position === 'right'){
        x = e.x + e.w;
    }

    if(position === 'top'){
        y = e.y;
    }

    if(position === 'bottom'){
        y = e.y + e.h;
    }


    const rect =
        els.video.getBoundingClientRect();


    return {
        x:rect.width * x / 100,
        y:rect.height * y / 100
    };
}


function connectionDefaults(){

    return {
        fromAnchor:'right',
        toAnchor:'left',
        color:'#60a5fa',
        width:3,
        style:'solid'
    };
}


function renderConnections(){

    els.connectionSvg.innerHTML = '';


    if(!canEdit()) return;


    const rect =
        els.video.getBoundingClientRect();


    els.connectionSvg.setAttribute(
        'viewBox',
        `0 0 ${rect.width} ${rect.height}`
    );


    for(
        const c of state.project.connections
    ){

        const from =
            getElement(c.from);

        const to =
            getElement(c.to);


        if(!from || !to) continue;

        if(
            !visible(from) &&
            !visible(to)
        ){
            continue;
        }


        const a =
            anchorPoint(
                from,
                c.fromAnchor || 'right'
            );

        const b =
            anchorPoint(
                to,
                c.toAnchor || 'left'
            );


        drawConnection(
            c,
            a,
            b
        );
    }
}


function drawConnection(c,a,b){

    const path =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );


    const dx =
        Math.abs(b.x - a.x);


    const bend =
        Math.max(
            35,
            Math.min(140,dx * .35)
        );


    path.setAttribute(
        'd',
        `M ${a.x} ${a.y}
         C ${a.x + bend} ${a.y},
           ${b.x - bend} ${b.y},
           ${b.x} ${b.y}`
    );


    path.setAttribute(
        'stroke',
        c.color || '#60a5fa'
    );

    path.setAttribute(
        'stroke-width',
        state.selectedConnectionId === c.id ?
            Math.max(5,c.width || 3) :
            c.width || 3
    );


    path.setAttribute(
        'stroke-linecap',
        'round'
    );


    if(c.style === 'dashed'){
        path.setAttribute(
            'stroke-dasharray',
            '9 6'
        );
    }

    if(c.style === 'dotted'){
        path.setAttribute(
            'stroke-dasharray',
            '2 7'
        );
    }


    path.classList.add(
        'connection-path'
    );


    if(
        state.selectedConnectionId === c.id
    ){
        path.classList.add('selected');
    }


    path.addEventListener(
        'click',
        ev => {

            ev.stopPropagation();

            selectConnection(c.id);
        }
    );


    els.connectionSvg.appendChild(path);


    createEndpoint(
        c,
        'from',
        a
    );

    createEndpoint(
        c,
        'to',
        b
    );
}


function createEndpoint(c,type,point){

    if(
        state.selectedConnectionId !== c.id
    ){
        return;
    }


    const circle =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );


    circle.setAttribute(
        'cx',
        point.x
    );

    circle.setAttribute(
        'cy',
        point.y
    );

    circle.setAttribute(
        'r',
        7
    );

    circle.setAttribute(
        'fill',
        c.color || '#60a5fa'
    );

    circle.classList.add(
        'connection-endpoint'
    );


    circle.addEventListener(
        'pointerdown',
        ev => {

            ev.preventDefault();
            ev.stopPropagation();

            beginEndpointDrag(
                ev,
                c,
                type
            );
        }
    );


    els.connectionSvg.appendChild(
        circle
    );
}


function beginEndpointDrag(ev,c,type){

    state.endpointDrag = {
        connection:c,
        type,
        rect:els.video.getBoundingClientRect()
    };


    window.addEventListener(
        'pointermove',
        moveEndpointDrag
    );

    window.addEventListener(
        'pointerup',
        endEndpointDrag,
        {once:true}
    );
}


function moveEndpointDrag(ev){

    const d =
        state.endpointDrag;

    if(!d) return;


    const e =
        getElement(
            d.type === 'from' ?
            d.connection.from :
            d.connection.to
        );


    if(!e) return;


    const x =
        clamp(
            (ev.clientX - d.rect.left) /
            d.rect.width *
            100,
            0,
            100
        );

    const y =
        clamp(
            (ev.clientY - d.rect.top) /
            d.rect.height *
            100,
            0,
            100
        );


    let best = 'left';
    let bestDistance = Infinity;


    for(const position of anchors){

        const p =
            anchorPoint(e,position);


        const px =
            p.x /
            d.rect.width *
            100;

        const py =
            p.y /
            d.rect.height *
            100;


        const distance =
            Math.hypot(
                x-px,
                y-py
            );


        if(distance < bestDistance){

            bestDistance = distance;
            best = position;
        }
    }


    if(d.type === 'from'){
        d.connection.fromAnchor = best;
    }else{
        d.connection.toAnchor = best;
    }


    markDirty();

    renderConnections();
    updateProperties();
}


function endEndpointDrag(){

    state.endpointDrag = null;

    window.removeEventListener(
        'pointermove',
        moveEndpointDrag
    );

    renderAll();
}


function connectSelected(){

    if(state.selectedIds.size !== 2){
        toast(
            '接続する要素を2つ選択してください',
            true
        );
        return;
    }


    const ids =
        [...state.selectedIds];


    const exists =
        state.project.connections.some(
            c =>
                c.from === ids[0] &&
                c.to === ids[1]
                ||
                c.from === ids[1] &&
                c.to === ids[0]
        );


    if(exists){

        toast(
            'この2要素はすでに接続されています',
            true
        );

        return;
    }


    const c = {
        id:uid('connection'),
        from:ids[0],
        to:ids[1],
        ...connectionDefaults()
    };


    state.project.connections.push(c);

    state.selectedIds.clear();

    state.selectedConnectionId =
        c.id;


    markDirty();

    renderAll();

    toast(
        '要素を接続しました'
    );
}


/* ================================
   右メニュー
================================ */

function updateProperties(){

    if(state.selectedConnectionId){

        renderConnectionProperties();

        return;
    }


    if(state.selectedIds.size === 1){

        const id =
            [...state.selectedIds][0];

        const e =
            getElement(id);

        if(e){

            renderElementProperties(e);

            return;
        }
    }


    els.propertiesTitle.textContent =
        '選択してください';


    els.propertiesBody.innerHTML = `
        <div class="properties-empty">
            動画上またはタイムライン上の要素を選択すると、
            選択した対象に応じた操作メニューが表示されます。
        </div>
    `;
}


function renderElementProperties(e){

    els.propertiesTitle.textContent =
        e.type === 'text' ?
        'テキスト' :
        e.type === 'box' ?
        '強調枠' :
        'スキップ';


    let html = '';


    html += `
        <div class="property-section">

            <div class="property-title">
                基本
            </div>

            <div class="field">
                <label>名前</label>
                <input
                    id="propName"
                    value="${escapeHtml(e.name)}"
                >
            </div>
    `;


    if(e.type === 'text'){

        html += `
            <div class="field">
                <label>テキスト</label>
                <textarea id="propText">${escapeHtml(e.text)}</textarea>
            </div>

            <div class="inline">

                <div class="field">
                    <label>文字サイズ</label>
                    <input
                        id="propFontSize"
                        type="number"
                        min="8"
                        max="100"
                        value="${e.fontSize}"
                    >
                </div>

                <div class="field">
                    <label>文字太さ</label>
                    <select id="propWeight">
                        <option value="400" ${e.fontWeight==='400'?'selected':''}>標準</option>
                        <option value="700" ${e.fontWeight==='700'?'selected':''}>太字</option>
                        <option value="900" ${e.fontWeight==='900'?'selected':''}>極太</option>
                    </select>
                </div>

            </div>
        `;
    }


    if(e.type === 'box'){

        html += `
            <div class="field">
                <label>図形</label>

                <select id="propShape">

                    <option value="rectangle"
                        ${e.shape==='rectangle'?'selected':''}>
                        長方形
                    </option>

                    <option value="rounded"
                        ${e.shape==='rounded'?'selected':''}>
                        角丸
                    </option>

                    <option value="ellipse"
                        ${e.shape==='ellipse'?'selected':''}>
                        楕円
                    </option>

                    <option value="diamond"
                        ${e.shape==='diamond'?'selected':''}>
                        ひし形
                    </option>

                </select>
            </div>
        `;
    }


    html += `
        </div>

        <div class="property-section">

            <div class="property-title">
                位置・サイズ
            </div>

            <div class="inline">

                <div class="field">
                    <label>X (%)</label>
                    <input
                        id="propX"
                        type="number"
                        min="0"
                        max="100"
                        step=".1"
                        value="${e.x}"
                    >
                </div>

                <div class="field">
                    <label>Y (%)</label>
                    <input
                        id="propY"
                        type="number"
                        min="0"
                        max="100"
                        step=".1"
                        value="${e.y}"
                    >
                </div>

            </div>

            <div class="inline">

                <div class="field">
                    <label>幅 (%)</label>
                    <input
                        id="propW"
                        type="number"
                        min="1"
                        max="100"
                        step=".1"
                        value="${e.w}"
                    >
                </div>

                <div class="field">
                    <label>高さ (%)</label>
                    <input
                        id="propH"
                        type="number"
                        min="1"
                        max="100"
                        step=".1"
                        value="${e.h}"
                    >
                </div>

            </div>

        </div>

        <div class="property-section">

            <div class="property-title">
                表示時間
            </div>

            <div class="inline">

                <div class="field">
                    <label>開始</label>
                    <input
                        id="propStart"
                        type="number"
                        min="0"
                        step=".001"
                        value="${e.start}"
                    >
                </div>

                <div class="field">
                    <label>終了</label>
                    <input
                        id="propEnd"
                        type="number"
                        min="0"
                        step=".001"
                        value="${e.end}"
                    >
                </div>

            </div>

        </div>

        <div class="property-section">

            <div class="property-title">
                色
            </div>

            <div
                class="color-row"
                id="propColors"
            ></div>

        </div>

        <div class="property-section">

            <div class="property-actions">

                <button
                    class="btn danger"
                    id="propDelete"
                >
                    削除
                </button>

                <button
                    class="btn primary"
                    id="propConnect"
                >
                    2要素を接続
                </button>

            </div>

        </div>
    `;


    els.propertiesBody.innerHTML =
        html;


    buildPropertyColors(e);


    [
        'propName',
        'propText',
        'propFontSize',
        'propWeight',
        'propShape',
        'propX',
        'propY',
        'propW',
        'propH',
        'propStart',
        'propEnd'
    ].forEach(id => {

        const node = $(id);

        if(!node) return;

        node.addEventListener(
            'change',
            () => applyElementProperties(e)
        );
    });


    $('propDelete').onclick =
        deleteSelected;


    $('propConnect').onclick =
        connectSelected;
}


function buildPropertyColors(e){

    const wrap =
        $('propColors');

    if(!wrap) return;


    state.colors.forEach(color => {

        const button =
            document.createElement('button');

        button.type = 'button';

        button.className =
            'color-choice' +
            (
                e.color === color ?
                ' active' :
                ''
            );

        button.style.background =
            color;


        button.onclick = () => {

            e.color = color;

            markDirty();

            renderAll();
        };


        wrap.appendChild(button);
    });
}


function applyElementProperties(e){

    e.name =
        $('propName')?.value.trim() ||
        '要素';


    if(e.type === 'text'){

        e.text =
            $('propText')?.value || '';

        e.fontSize =
            clamp(
                Number($('propFontSize')?.value) || 28,
                8,
                100
            );

        e.fontWeight =
            $('propWeight')?.value || '700';
    }


    if(e.type === 'box'){

        e.shape =
            $('propShape')?.value ||
            'rectangle';
    }


    e.x =
        clamp(
            Number($('propX')?.value) || 0,
            0,
            99
        );


    e.y =
        clamp(
            Number($('propY')?.value) || 0,
            0,
            99
        );


    e.w =
        clamp(
            Number($('propW')?.value) || 1,
            1,
            100
        );


    e.h =
        clamp(
            Number($('propH')?.value) || 1,
            1,
            100
        );


    const start =
        Number($('propStart')?.value);


    const end =
        Number($('propEnd')?.value);


    if(
        Number.isFinite(start) &&
        Number.isFinite(end) &&
        start >= 0 &&
        end > start &&
        end <= state.duration
    ){

        e.start = start;
        e.end = end;

    }else{

        toast(
            '開始・終了時間が不正です',
            true
        );

        return;
    }


    markDirty();

    renderAll();
}


function renderConnectionProperties(){

    const c =
        getConnection(
            state.selectedConnectionId
        );


    if(!c) return;


    const from =
        getElement(c.from);

    const to =
        getElement(c.to);


    els.propertiesTitle.textContent =
        '接続線';


    els.propertiesBody.innerHTML = `

        <div class="property-section">

            <div class="property-title">
                接続元・接続先
            </div>

            <div class="field">

                <label>接続元</label>

                <select id="propFromAnchor">

                    ${anchorOptions(
                        c.fromAnchor || 'right'
                    )}

                </select>

            </div>

            <div class="field">

                <label>接続先</label>

                <select id="propToAnchor">

                    ${anchorOptions(
                        c.toAnchor || 'left'
                    )}

                </select>

            </div>

            <div style="font-size:11px;color:#64748b">
                ${from?.name || ''} → ${to?.name || ''}
            </div>

        </div>


        <div class="property-section">

            <div class="property-title">
                線の表示
            </div>

            <div class="field">

                <label>線種</label>

                <select id="propLineStyle">

                    <option value="solid"
                        ${c.style==='solid'?'selected':''}>
                        実線
                    </option>

                    <option value="dashed"
                        ${c.style==='dashed'?'selected':''}>
                        破線
                    </option>

                    <option value="dotted"
                        ${c.style==='dotted'?'selected':''}>
                        点線
                    </option>

                </select>

            </div>

            <div class="field">

                <label>太さ</label>

                <input
                    id="propLineWidth"
                    type="number"
                    min="1"
                    max="12"
                    value="${c.width || 3}"
                >

            </div>

        </div>


        <div class="property-section">

            <div class="property-title">
                色
            </div>

            <div
                class="color-row"
                id="connectionColors"
            ></div>

        </div>


        <div class="property-section">

            <div class="property-actions">

                <button
                    class="btn danger"
                    id="propDeleteConnection"
                >
                    接続を削除
                </button>

            </div>

        </div>
    `;


    $('propFromAnchor').onchange =
        () => {

            c.fromAnchor =
                $('propFromAnchor').value;

            markDirty();

            renderAll();
        };


    $('propToAnchor').onchange =
        () => {

            c.toAnchor =
                $('propToAnchor').value;

            markDirty();

            renderAll();
        };


    $('propLineStyle').onchange =
        () => {

            c.style =
                $('propLineStyle').value;

            markDirty();

            renderAll();
        };


    $('propLineWidth').onchange =
        () => {

            c.width =
                clamp(
                    Number(
                        $('propLineWidth').value
                    ) || 3,
                    1,
                    12
                );

            markDirty();

            renderAll();
        };


    state.colors.forEach(color => {

        const button =
            document.createElement('button');

        button.type = 'button';

        button.className =
            'color-choice' +
            (
                c.color === color ?
                ' active' :
                ''
            );

        button.style.background =
            color;


        button.onclick = () => {

            c.color = color;

            markDirty();

            renderAll();
        };


        $('connectionColors')
            .appendChild(button);
    });


    $('propDeleteConnection').onclick =
        deleteSelected;
}


function anchorOptions(active){

    return anchors.map(
        anchor =>
            `<option value="${anchor}"
                ${anchor === active ? 'selected' : ''}>
                ${anchorName(anchor)}
             </option>`
    ).join('');
}


function anchorName(anchor){

    return {
        left:'左',
        right:'右',
        top:'上',
        bottom:'下'
    }[anchor] || anchor;
}


/* ================================
   選択削除
================================ */

function deleteSelected(){

    if(state.selectedConnectionId){

        state.project.connections =
            state.project.connections.filter(
                c =>
                    c.id !==
                    state.selectedConnectionId
            );

        state.selectedConnectionId = null;

        markDirty();

        renderAll();

        return;
    }


    if(!state.selectedIds.size){
        return;
    }


    const ids =
        new Set(state.selectedIds);


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

    markDirty();

    renderAll();
}


function disconnectSelected(){

    if(state.selectedConnectionId){

        state.project.connections =
            state.project.connections.filter(
                c =>
                    c.id !==
                    state.selectedConnectionId
            );

        state.selectedConnectionId = null;

        markDirty();

        renderAll();

        return;
    }


    if(state.selectedIds.size !== 2){
        return;
    }


    const ids =
        [...state.selectedIds];


    state.project.connections =
        state.project.connections.filter(
            c =>
                !(
                    (
                        c.from === ids[0] &&
                        c.to === ids[1]
                    ) ||
                    (
                        c.from === ids[1] &&
                        c.to === ids[0]
                    )
                )
        );


    markDirty();

    renderAll();
}


/* ================================
   要素追加
================================ */

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
            Math.max(0,state.duration-.05)
        );


    const end =
        Math.min(
            state.duration,
            start + 3
        );


    const xy =
        state.pendingXY ||
        {x:10,y:10};


    state.pendingXY = null;


    const e = {

        id:uid('el'),

        type,

        name:
            type === 'text' ?
            'テキスト' :
            type === 'box' ?
            '強調枠' :
            'スキップ',

        text:
            type === 'text' ?
            'テキスト' :
            '',

        start,
        end,

        x:xy.x,
        y:xy.y,

        w:35,
        h:18,

        color:typeColor(type),

        fontSize:28,
        fontWeight:'700',

        shape:'rectangle'
    };


    state.project.elements.push(e);


    state.selectedIds.clear();

    state.selectedIds.add(e.id);

    state.selectedConnectionId = null;


    markDirty();

    renderAll();

    toast(
        '要素を追加しました'
    );
}


/* ================================
   コンテキストメニュー
================================ */

function openContextMenu(x,y){

    els.menu.style.display =
        'block';


    const width = 220;
    const height = 280;


    els.menu.style.left =
        Math.min(
            x,
            window.innerWidth - width - 8
        ) + 'px';


    els.menu.style.top =
        Math.min(
            y,
            window.innerHeight - height - 8
        ) + 'px';
}


function closeContextMenu(){

    els.menu.style.display =
        'none';
}


/* ================================
   動画
================================ */

function handleVideo(file){

    if(
        !file ||
        !file.type.startsWith('video/')
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


    state.videoUrl =
        URL.createObjectURL(file);


    state.project =
        emptyProject(file.name);


    state.project.videoName =
        file.name;


    state.duration = 0;

    state.currentTime = 0;

    state.selectedIds.clear();

    state.selectedConnectionId = null;


    els.video.src =
        state.videoUrl;


    els.empty.style.display =
        'none';


    els.status.textContent =
        '動画を読み込んでいます…';
}


function newProject(){

    state.project = null;

    state.duration = 0;

    state.currentTime = 0;

    state.selectedIds.clear();

    state.selectedConnectionId = null;


    if(state.videoUrl){

        URL.revokeObjectURL(
            state.videoUrl
        );

        state.videoUrl = '';
    }


    els.video.removeAttribute(
        'src'
    );

    els.video.load();


    els.empty.style.display =
        'block';


    els.play.disabled = true;
    els.stop.disabled = true;
    els.save.disabled = true;


    renderAll();

    els.status.textContent =
        '動画を読み込んでください';
}


/* ================================
   再生
================================ */

function seek(t){

    state.currentTime =
        clamp(
            Number(t) || 0,
            0,
            state.duration
        );


    try{

        els.video.currentTime =
            state.currentTime;

    }catch(_){}


    renderOverlay();

    renderConnections();

    updatePlayhead();
}


function updatePlayhead(){

    if(!canEdit()){

        els.playhead.style.display =
            'none';

        return;
    }


    els.playhead.style.display =
        'block';


    const g =
        timelineGeometry();


    const x =
        g.width *
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
        `${time(state.currentTime)} / ${time(state.duration)}`;
}


function updateHint(){

    if(state.selectedConnectionId){

        els.hint.textContent =
            '接続線を選択中';

        return;
    }


    if(state.selectedIds.size){

        els.hint.textContent =
            `${state.selectedIds.size}要素選択中`;

        return;
    }


    els.hint.textContent = '';
}


/* ================================
   保存
================================ */

function saveProject(){

    if(!state.project) return;


    localStorage.setItem(
        'video-editor-project',
        JSON.stringify(state.project)
    );


    markClean();

    toast(
        '保存しました'
    );
}


/* ================================
   補助
================================ */

function escapeHtml(value){

    return String(value ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}


/* ================================
   イベント
================================ */

els.open.onclick = () => {
    els.file.click();
};


els.file.onchange = () => {

    handleVideo(
        els.file.files[0]
    );
};


els.newBtn.onclick =
    newProject;


els.save.onclick =
    saveProject;


els.video.onloadedmetadata = () => {

    state.duration =
        Number(els.video.duration) || 0;


    if(!state.project){

        state.project =
            emptyProject();
    }


    state.project.duration =
        state.duration;


    els.play.disabled =
        false;

    els.stop.disabled =
        false;

    els.save.disabled =
        false;


    els.empty.style.display =
        'none';


    state.currentTime = 0;

    renderAll();

    markClean();
};


els.video.ontimeupdate = () => {

    if(!els.video.paused){

        state.currentTime =
            els.video.currentTime;

        renderOverlay();

        renderConnections();

        updatePlayhead();
    }
};


els.video.onended = () => {

    els.play.textContent = '▶';

    state.currentTime =
        state.duration;

    renderAll();
};


els.play.onclick = async () => {

    if(!canEdit()) return;


    if(els.video.paused){

        try{

            await els.video.play();

            els.play.textContent =
                'Ⅱ';

        }catch(_){

            toast(
                '動画を再生できません',
                true
            );
        }

    }else{

        els.video.pause();

        els.play.textContent =
            '▶';
    }
};


els.stop.onclick = () => {

    els.video.pause();

    els.play.textContent =
        '▶';

    seek(0);
};


els.zoom.oninput = () => {

    els.zoomValue.textContent =
        Number(
            els.zoom.value
        ).toFixed(1) + '×';

    renderAxis();

    updatePlayhead();
};


/* タイムラインクリック */

els.content.addEventListener(
    'pointerdown',
    ev => {

        if(
            ev.target.closest('.element-bar') ||
            ev.target.closest('.connection-path')
        ){
            return;
        }


        if(
            ev.target.closest('.axis-track') ||
            ev.target.closest('.track-lane')
        ){

            seek(
                xToTime(ev.clientX)
            );
        }
    }
);


/* 動画クリック */

els.videoWrap.addEventListener(
    'pointerdown',
    ev => {

        if(
            ev.target.closest('.overlay-element') ||
            ev.target.closest('.connection-path') ||
            ev.target.closest('.connection-endpoint')
        ){
            return;
        }


        state.selectedIds.clear();

        state.selectedConnectionId =
            null;

        renderAll();
    }
);


/* 動画右クリック */

els.videoWrap.addEventListener(
    'contextmenu',
    ev => {

        ev.preventDefault();

        if(!canEdit()) return;


        const rect =
            els.video.getBoundingClientRect();


        if(
            ev.clientX < rect.left ||
            ev.clientX > rect.right ||
            ev.clientY < rect.top ||
            ev.clientY > rect.bottom
        ){
            return;
        }


        const x =
            clamp(
                (ev.clientX - rect.left) /
                rect.width * 100,
                0,
                95
            );


        const y =
            clamp(
                (ev.clientY - rect.top) /
                rect.height * 100,
                0,
                95
            );


        state.pendingXY = {
            x,
            y
        };


        openContextMenu(
            ev.clientX,
            ev.clientY
        );
    }
);


/* コンテキストメニュー */

$('ctxAddText').onclick = () => {

    addElement('text');

    closeContextMenu();
};


$('ctxAddBox').onclick = () => {

    addElement('box');

    closeContextMenu();
};


$('ctxAddSkip').onclick = () => {

    addElement('skip');

    closeContextMenu();
};


$('ctxEdit').onclick = () => {

    if(state.selectedIds.size === 1){

        updateProperties();
    }

    closeContextMenu();
};


$('ctxConnect').onclick = () => {

    connectSelected();

    closeContextMenu();
};


$('ctxDisconnect').onclick = () => {

    disconnectSelected();

    closeContextMenu();
};


$('ctxDelete').onclick = () => {

    deleteSelected();

    closeContextMenu();
};


document.addEventListener(
    'click',
    ev => {

        if(
            !ev.target.closest('.context-menu')
        ){

            closeContextMenu();
        }
    }
);


document.addEventListener(
    'keydown',
    ev => {

        if(
            (
                ev.key === 'Delete' ||
                ev.key === 'Backspace'
            ) &&
            ![
                'INPUT',
                'TEXTAREA',
                'SELECT'
            ].includes(
                document.activeElement?.tagName
            )
        ){

            deleteSelected();
        }


        if(ev.key === 'Escape'){

            closeContextMenu();
        }


        if(
            ev.key === ' ' &&
            ![
                'INPUT',
                'TEXTAREA'
            ].includes(
                document.activeElement?.tagName
            )
        ){

            ev.preventDefault();

            els.play.click();
        }
    }
);


/* リサイズ */

window.addEventListener(
    'resize',
    () => {

        renderOverlay();

        renderConnections();

        renderAxis();

        updatePlayhead();
    }
);


els.scroll.addEventListener(
    'scroll',
    () => {

        renderAxis();

        updatePlayhead();
    }
);


/* 初期表示 */

renderAll();

</script>

</body>
</html>

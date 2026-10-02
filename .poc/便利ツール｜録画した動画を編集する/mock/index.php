<?php
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
    --label:90px;
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

button,input{
    font:inherit;
}

button{
    cursor:pointer;
}

button:disabled{
    opacity:.45;
    cursor:not-allowed;
}

.app{
    width:100%;
    height:100%;
    display:flex;
    flex-direction:column;
}

.top{
    height:52px;
    flex:none;
    display:flex;
    align-items:center;
    gap:8px;
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

.btn:hover{
    background:#304158;
}

.btn.primary{
    background:var(--blue);
    border-color:#3b82f6;
}

.file{
    display:none;
}

.status{
    margin-left:auto;
    color:var(--muted);
    font-size:12px;
}

.main{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}

.video-area{
    flex:1;
    min-height:260px;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:12px;
    background:#050a11;
}

.video-wrap{
    position:relative;
    width:min(1100px,100%);
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:#000;
}

#video{
    display:block;
    max-width:100%;
    max-height:100%;
    width:100%;
    height:100%;
    object-fit:contain;
}

.connection-layer{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
    z-index:10;
}

.connection{
    fill:none;
    stroke:#fbbf24;
    stroke-width:3;
    pointer-events:stroke;
    cursor:pointer;
}

.connection.selected{
    stroke:#60a5fa;
    stroke-width:6;
}

.connection-handle{
    fill:#fff;
    stroke:#60a5fa;
    stroke-width:3;
    pointer-events:auto;
    cursor:crosshair;
}

.overlay{
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:20;
}

.element{
    position:absolute;
    min-width:35px;
    min-height:25px;
    pointer-events:auto;
    user-select:none;
    touch-action:none;
    cursor:move;
}

.element.selected{
    outline:2px solid #60a5fa;
    outline-offset:2px;
}

.element.multi{
    outline:2px solid #fbbf24;
    outline-offset:2px;
}

.element-body{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
}

.element.text .element-body{
    color:#60a5fa;
    text-shadow:0 1px 3px #000;
}

.element.box .element-body{
    border:4px solid currentColor;
    background:#ffffff08;
}

.element.box.round .element-body{
    border-radius:18px;
}

.element.box.circle .element-body{
    border-radius:50%;
}

.element.skip .element-body{
    border:2px dashed currentColor;
    color:#f97316;
    background:#f9731622;
}

.handle{
    position:absolute;
    width:10px;
    height:10px;
    background:#fff;
    border:2px solid #60a5fa;
    border-radius:2px;
    z-index:30;
}

.handle.nw{left:-6px;top:-6px;cursor:nwse-resize}
.handle.ne{right:-6px;top:-6px;cursor:nesw-resize}
.handle.sw{left:-6px;bottom:-6px;cursor:nesw-resize}
.handle.se{right:-6px;bottom:-6px;cursor:nwse-resize}

.empty{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    color:#64748b;
    text-align:center;
    pointer-events:none;
    line-height:1.8;
}

.empty strong{
    color:#94a3b8;
    font-size:18px;
}

.timeline{
    height:260px;
    flex:none;
    display:flex;
    flex-direction:column;
    border-top:1px solid var(--line);
    background:#0e1724;
}

.toolbar{
    height:43px;
    flex:none;
    display:flex;
    align-items:center;
    gap:8px;
    padding:5px 8px;
    border-bottom:1px solid var(--line);
}

.time{
    min-width:150px;
    color:#dbeafe;
    font-variant-numeric:tabular-nums;
}

.hint{
    color:#94a3b8;
    font-size:12px;
}

.zoom{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:6px;
    color:var(--muted);
    font-size:12px;
}

.zoom input{
    width:100px;
}

.scroll{
    flex:1;
    min-height:0;
    overflow:auto;
}

.timeline-content{
    position:relative;
    width:100%;
    min-width:700px;
}

.axis{
    height:30px;
    display:flex;
    position:sticky;
    top:0;
    z-index:50;
    background:#131d29;
    border-bottom:1px solid var(--line);
}

.axis-label{
    width:var(--label);
    min-width:var(--label);
    padding:7px 8px;
    border-right:1px solid var(--line);
    font-size:11px;
    position:sticky;
    left:0;
    z-index:60;
    background:#131d29;
}

.axis-track{
    position:relative;
    flex:1;
}

.tick{
    position:absolute;
    top:0;
    height:100%;
    border-left:1px solid #334155;
    padding:5px 0 0 3px;
    font-size:9px;
    color:#64748b;
}

.row{
    height:55px;
    display:flex;
    border-bottom:1px solid #263445;
}

.row-label{
    width:var(--label);
    min-width:var(--label);
    display:flex;
    align-items:center;
    gap:5px;
    padding:0 8px;
    border-right:1px solid var(--line);
    background:#111b27;
    font-size:11px;
    position:sticky;
    left:0;
    z-index:20;
}

.dot{
    width:8px;
    height:8px;
    border-radius:50%;
}

.lane{
    position:relative;
    flex:1;
}

.bar{
    position:absolute;
    top:9px;
    height:37px;
    border:1px solid currentColor;
    border-radius:5px;
    display:flex;
    align-items:center;
    cursor:grab;
    user-select:none;
    touch-action:none;
}

.bar.selected{
    box-shadow:0 0 0 2px #60a5fa;
}

.bar.multi{
    box-shadow:0 0 0 2px #fbbf24;
}

.bar span{
    padding:0 7px;
    font-size:10px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.bar-handle{
    position:absolute;
    top:0;
    width:9px;
    height:100%;
    z-index:3;
}

.bar-handle.left{
    left:-4px;
    cursor:ew-resize;
}

.bar-handle.right{
    right:-4px;
    cursor:ew-resize;
}

.playhead{
    position:absolute;
    top:30px;
    bottom:0;
    width:2px;
    background:#ef4444;
    z-index:70;
    pointer-events:none;
}

.playhead:before{
    content:"";
    position:absolute;
    top:-1px;
    left:-5px;
    width:12px;
    height:12px;
    background:#ef4444;
    clip-path:polygon(0 0,100% 0,50% 100%);
}

.context,
.connection-menu{
    position:fixed;
    z-index:1000;
    display:none;
    min-width:220px;
    padding:5px;
    background:#172235;
    border:1px solid #475569;
    border-radius:7px;
    box-shadow:0 12px 30px #0008;
}

.context button,
.connection-menu button{
    display:block;
    width:100%;
    padding:8px;
    border:0;
    background:transparent;
    color:#e5e7eb;
    text-align:left;
    border-radius:4px;
}

.context button:hover,
.connection-menu button:hover{
    background:#293a52;
}

.menu-title{
    padding:6px 8px;
    color:#94a3b8;
    font-size:11px;
}

.menu-group{
    padding:3px 0;
}

.menu-separator{
    height:1px;
    background:#334155;
    margin:4px 0;
}

.toast{
    position:fixed;
    right:15px;
    bottom:15px;
    z-index:3000;
    display:none;
    padding:10px 14px;
    background:#1e293b;
    border:1px solid #475569;
    border-radius:6px;
}

@media(max-width:800px){
    :root{--label:80px}
    .brand{display:none}
    .timeline{height:230px}
}
</style>
</head>

<body>

<div class="app">

<header class="top">
    <div class="brand">動画編集・注釈</div>

    <button class="btn primary" id="openVideo">
        動画を読み込む
    </button>

    <input
        class="file"
        id="videoFile"
        type="file"
        accept="video/*"
    >

    <button class="btn" id="newBtn">
        新規
    </button>

    <span class="status" id="status">
        動画を読み込んでください
    </span>
</header>

<main class="main">

<section class="video-area">

    <div class="video-wrap" id="videoWrap">

        <video
            id="video"
            preload="metadata"
            playsinline
        ></video>

        <svg
            class="connection-layer"
            id="connectionLayer"
        ></svg>

        <div
            class="overlay"
            id="overlay"
        ></div>

        <div
            class="empty"
            id="empty"
        >
            <strong>動画を読み込んでください</strong>
            動画読み込み後、画面上で要素を追加できます
        </div>

    </div>

</section>

<section class="timeline">

    <div class="toolbar">

        <button class="btn" id="play">▶</button>

        <button class="btn" id="stop">■</button>

        <span class="time" id="time">
            00:00.000 / 00:00.000
        </span>

        <span class="hint" id="hint"></span>

        <div class="zoom">
            <span>時間スケール</span>
            <input
                id="zoom"
                type="range"
                min="1"
                max="5"
                step=".1"
                value="1"
            >
            <span id="zoomValue">1.0×</span>
        </div>

    </div>

    <div class="scroll" id="scroll">

        <div
            class="timeline-content"
            id="timelineContent"
        >

            <div class="axis">

                <div class="axis-label">
                    時間
                </div>

                <div
                    class="axis-track"
                    id="axis"
                ></div>

            </div>

            <div id="rows"></div>

            <div
                class="playhead"
                id="playhead"
            ></div>

        </div>

    </div>

</section>

</main>
</div>


<!-- 要素用メニュー -->

<div
    class="context"
    id="context"
>

    <div
        class="menu-title"
        id="contextTitle"
    >
        動画画面
    </div>

    <div class="menu-group">

        <button data-add="text">
            テキストを追加
        </button>

        <button data-add="box">
            強調枠を追加
        </button>

        <button data-add="skip">
            スキップを追加
        </button>

    </div>

    <div class="menu-separator"></div>

    <button id="changeShape">
        強調枠の図形を変更
    </button>

    <button id="connect">
        選択要素を接続
    </button>

    <button id="deleteElements">
        選択要素を削除
    </button>

</div>


<!-- 接続線用メニュー -->

<div
    class="connection-menu"
    id="connectionMenu"
>

    <div class="menu-title">
        接続線
    </div>

    <div class="menu-group">

        <button data-line="solid">
            実線
        </button>

        <button data-line="dashed">
            破線
        </button>

        <button data-line="dotted">
            点線
        </button>

    </div>

    <div class="menu-separator"></div>

    <div class="menu-title">
        始点位置
    </div>

    <button data-from="left">左</button>
    <button data-from="center">中央</button>
    <button data-from="right">右</button>

    <div class="menu-title">
        終点位置
    </div>

    <button data-to="left">左</button>
    <button data-to="center">中央</button>
    <button data-to="right">右</button>

    <div class="menu-separator"></div>

    <button id="deleteConnection">
        接続線を削除
    </button>

</div>


<div
    class="toast"
    id="toast"
></div>


<script>
'use strict';

const $ = id => document.getElementById(id);

const el = {
    video: $('video'),
    wrap: $('videoWrap'),
    overlay: $('overlay'),
    layer: $('connectionLayer'),
    empty: $('empty'),
    file: $('videoFile'),
    open: $('openVideo'),
    newBtn: $('newBtn'),
    status: $('status'),
    play: $('play'),
    stop: $('stop'),
    time: $('time'),
    hint: $('hint'),
    zoom: $('zoom'),
    zoomValue: $('zoomValue'),
    scroll: $('scroll'),
    content: $('timelineContent'),
    axis: $('axis'),
    rows: $('rows'),
    playhead: $('playhead'),
    context: $('context'),
    contextTitle: $('contextTitle'),
    connectionMenu: $('connectionMenu'),
    toast: $('toast')
};

const state = {
    videoUrl: '',
    duration: 0,
    current: 0,
    elements: [],
    connections: [],
    selected: new Set(),
    selectedConnection: null,
    drag: null,
    connectionDrag: null,
    next: 1
};

const colors = {
    text: '#60a5fa',
    box: '#22c55e',
    skip: '#f97316'
};

function uid(prefix){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        state.next++;
}

function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}

function fmt(v){
    v = Math.max(0,Number(v)||0);

    const m = Math.floor(v / 60);
    const s = Math.floor(v % 60);
    const ms = Math.floor((v % 1) * 1000);

    return String(m).padStart(2,'0') + ':' +
        String(s).padStart(2,'0') + '.' +
        String(ms).padStart(3,'0');
}

function shortTime(v){
    if(v < 60){
        return v.toFixed(v % 1 ? 1 : 0) + 's';
    }

    return String(Math.floor(v / 60)).padStart(2,'0') +
        ':' +
        String(Math.floor(v % 60)).padStart(2,'0');
}

function getElement(id){
    return state.elements.find(e => e.id === id);
}

function visible(e){
    return state.current >= e.start &&
        state.current < e.end;
}

function toast(message){
    el.toast.textContent = message;
    el.toast.style.display = 'block';

    clearTimeout(toast.timer);

    toast.timer = setTimeout(() => {
        el.toast.style.display = 'none';
    },1800);
}

function status(message){
    el.status.textContent = message;
}

function canEdit(){
    return state.duration > 0;
}


/* 動画 */

el.open.onclick = () => {
    el.file.click();
};

el.file.onchange = () => {

    const file = el.file.files[0];

    if(!file){
        return;
    }

    if(!file.type.startsWith('video/')){
        toast('動画ファイルを選択してください');
        return;
    }

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoUrl = URL.createObjectURL(file);

    el.video.src = state.videoUrl;

    state.elements = [];
    state.connections = [];
    state.selected.clear();
    state.selectedConnection = null;
    state.current = 0;
    state.duration = 0;

    el.empty.style.display = 'flex';

    status('動画を読み込んでいます…');
};

el.video.onloadedmetadata = () => {

    state.duration = Number(el.video.duration) || 0;

    el.empty.style.display = 'none';

    status('編集中');

    render();
};

el.video.ontimeupdate = () => {

    state.current = el.video.currentTime || 0;

    renderOverlay();
    renderConnections();
    updatePlayhead();
};

el.play.onclick = async () => {

    if(!canEdit()){
        return;
    }

    if(el.video.paused){

        try{
            await el.video.play();
            el.play.textContent = 'Ⅱ';
        }catch(e){
            toast('再生できません');
        }

    }else{

        el.video.pause();
        el.play.textContent = '▶';
    }
};

el.stop.onclick = () => {

    el.video.pause();

    el.play.textContent = '▶';

    seek(0);
};

function seek(t){

    state.current = clamp(
        t,
        0,
        state.duration
    );

    try{
        el.video.currentTime = state.current;
    }catch(e){}

    renderOverlay();
    renderConnections();
    updatePlayhead();
}


/* 要素 */

function addElement(type){

    if(!canEdit()){
        toast('先に動画を読み込んでください');
        return;
    }

    const start = clamp(
        state.current,
        0,
        Math.max(0,state.duration - .1)
    );

    const item = {
        id: uid('el'),
        type: type,
        name:
            type === 'text' ? 'テキスト' :
            type === 'box' ? '強調枠' :
            'スキップ',

        text:
            type === 'text' ? 'テキスト' : '',

        start: start,
        end: Math.min(
            state.duration,
            start + 3
        ),

        x: 12,
        y: 12,

        w:
            type === 'box' ? 35 : 30,

        h:
            type === 'box' ? 25 : 15,

        color: colors[type],

        shape: 'rectangle',

        fontSize: 28,
        fontWeight: '700'
    };

    state.elements.push(item);

    state.selected.clear();
    state.selected.add(item.id);
    state.selectedConnection = null;

    render();

    toast(item.name + 'を追加しました');
}


/*
 * Shiftクリックで複数選択。
 * 通常クリックでは単一選択。
 */
function selectElement(id,additive){

    const item = getElement(id);

    if(!item){
        return;
    }

    state.selectedConnection = null;

    if(!additive){
        state.selected.clear();
    }

    if(additive && state.selected.has(id)){
        state.selected.delete(id);
    }else{
        state.selected.add(id);
    }

    if(
        state.selected.size === 1 &&
        !additive
    ){
        seek(item.start);
    }

    render();
}

function deleteSelected(){

    if(!state.selected.size){
        return;
    }

    const ids = new Set(state.selected);

    state.elements =
        state.elements.filter(
            e => !ids.has(e.id)
        );

    state.connections =
        state.connections.filter(
            c =>
                !ids.has(c.from) &&
                !ids.has(c.to)
        );

    state.selected.clear();
    state.selectedConnection = null;

    render();
}

function connectSelected(){

    if(state.selected.size !== 2){
        toast('接続する要素を2つ選択してください');
        return;
    }

    const [from,to] = [...state.selected];

    if(
        state.connections.some(c =>
            (c.from === from && c.to === to) ||
            (c.from === to && c.to === from)
        )
    ){
        toast('すでに接続されています');
        return;
    }

    state.connections.push({
        id: uid('connection'),
        from: from,
        to: to,
        line: 'solid',
        fromPos: 'right',
        toPos: 'left'
    });

    state.selectedConnection = null;

    render();

    toast('要素を接続しました');
}


/* 動画画面の要素 */

function renderOverlay(){

    el.overlay.innerHTML = '';

    if(!canEdit()){
        return;
    }

    state.elements.forEach(item => {

        if(!visible(item)){
            return;
        }

        const node = document.createElement('div');

        node.className =
            'element ' + item.type;

        if(item.shape === 'round'){
            node.classList.add('round');
        }

        if(item.shape === 'circle'){
            node.classList.add('circle');
        }

        if(state.selected.has(item.id)){
            node.classList.add(
                state.selected.size > 1
                    ? 'multi'
                    : 'selected'
            );
        }

        node.dataset.id = item.id;

        node.style.left = item.x + '%';
        node.style.top = item.y + '%';
        node.style.width = item.w + '%';
        node.style.height = item.h + '%';
        node.style.color = item.color;

        const body =
            document.createElement('div');

        body.className = 'element-body';

        if(item.type === 'text'){

            body.textContent =
                item.text || item.name;

            body.style.fontSize =
                item.fontSize + 'px';

            body.style.fontWeight =
                item.fontWeight;

        }else if(item.type === 'skip'){

            body.textContent = 'スキップ';
        }

        node.appendChild(body);

        /*
         * サイズ変更用ハンドル
         */
        ['nw','ne','sw','se'].forEach(pos => {

            const handle =
                document.createElement('span');

            handle.className =
                'handle ' + pos;

            handle.dataset.resize = pos;

            node.appendChild(handle);
        });

        /*
         * 要素選択・移動
         */
        node.addEventListener(
            'pointerdown',
            ev => {

                ev.preventDefault();
                ev.stopPropagation();

                const additive =
                    ev.shiftKey;

                const resize =
                    ev.target.closest('.handle');

                if(
                    !state.selected.has(item.id) ||
                    !additive
                ){
                    selectElement(
                        item.id,
                        additive
                    );
                }

                beginVideoDrag(
                    ev,
                    item.id,
                    resize
                );
            }
        );

        /*
         * 右クリックで選択要素に応じたメニュー
         */
        node.addEventListener(
            'contextmenu',
            ev => {

                ev.preventDefault();
                ev.stopPropagation();

                if(!state.selected.has(item.id)){
                    selectElement(item.id,false);
                }

                openElementMenu(
                    ev.clientX,
                    ev.clientY,
                    item
                );
            }
        );

        el.overlay.appendChild(node);
    });
}


/*
 * 動画画面上での移動・サイズ変更
 */
function beginVideoDrag(ev,id,resize){

    const item = getElement(id);

    if(!item){
        return;
    }

    const rect =
        el.wrap.getBoundingClientRect();

    state.drag = {

        id: id,

        mode:
            resize ? 'resize' : 'move',

        resize:
            resize?.dataset.resize || '',

        startX: ev.clientX,
        startY: ev.clientY,

        original: {
            x: item.x,
            y: item.y,
            w: item.w,
            h: item.h
        },

        width: rect.width,
        height: rect.height
    };

    ev.target.setPointerCapture?.(
        ev.pointerId
    );

    window.addEventListener(
        'pointermove',
        moveVideoDrag
    );

    window.addEventListener(
        'pointerup',
        endVideoDrag,
        {once:true}
    );
}

function moveVideoDrag(ev){

    const d = state.drag;

    if(!d){
        return;
    }

    const item = getElement(d.id);

    if(!item){
        return;
    }

    const dx =
        (ev.clientX - d.startX) /
        d.width * 100;

    const dy =
        (ev.clientY - d.startY) /
        d.height * 100;

    const o = d.original;

    if(d.mode === 'move'){

        item.x =
            clamp(
                o.x + dx,
                0,
                100 - o.w
            );

        item.y =
            clamp(
                o.y + dy,
                0,
                100 - o.h
            );

    }else{

        if(d.resize.includes('e')){

            item.w =
                clamp(
                    o.w + dx,
                    5,
                    100 - o.x
                );
        }

        if(d.resize.includes('s')){

            item.h =
                clamp(
                    o.h + dy,
                    5,
                    100 - o.y
                );
        }

        if(d.resize.includes('w')){

            const nx =
                clamp(
                    o.x + dx,
                    0,
                    o.x + o.w - 5
                );

            item.x = nx;
            item.w =
                o.w - (nx - o.x);
        }

        if(d.resize.includes('n')){

            const ny =
                clamp(
                    o.y + dy,
                    0,
                    o.y + o.h - 5
                );

            item.y = ny;
            item.h =
                o.h - (ny - o.y);
        }
    }

    renderOverlay();
    renderConnections();
}

function endVideoDrag(){

    state.drag = null;

    window.removeEventListener(
        'pointermove',
        moveVideoDrag
    );
}


/* タイムライン */

function renderTimeline(){

    el.rows.innerHTML = '';

    const groups = [
        ['text','テキスト'],
        ['box','強調枠'],
        ['skip','スキップ']
    ];

    groups.forEach(
        ([type,label]) => {

            const row =
                document.createElement('div');

            row.className = 'row';

            const labelNode =
                document.createElement('div');

            labelNode.className = 'row-label';

            const dot =
                document.createElement('span');

            dot.className = 'dot';
            dot.style.background =
                colors[type];

            labelNode.append(
                dot,
                document.createTextNode(label)
            );

            const lane =
                document.createElement('div');

            lane.className = 'lane';

            state.elements
                .filter(e => e.type === type)
                .forEach(item => {

                    const bar =
                        document.createElement('div');

                    bar.className = 'bar';

                    if(state.selected.has(item.id)){

                        bar.classList.add(
                            state.selected.size > 1
                                ? 'multi'
                                : 'selected'
                        );
                    }

                    bar.dataset.id = item.id;

                    bar.style.left =
                        item.start /
                        state.duration * 100 +
                        '%';

                    bar.style.width =
                        Math.max(
                            .5,
                            (item.end - item.start) /
                            state.duration * 100
                        ) + '%';

                    bar.style.color =
                        item.color;

                    bar.style.background =
                        item.color + '33';

                    const text =
                        document.createElement('span');

                    text.textContent =
                        item.name;

                    const left =
                        document.createElement('i');

                    left.className =
                        'bar-handle left';

                    left.dataset.edge = 'left';

                    const right =
                        document.createElement('i');

                    right.className =
                        'bar-handle right';

                    right.dataset.edge = 'right';

                    bar.append(
                        text,
                        left,
                        right
                    );

                    bar.addEventListener(
                        'click',
                        ev => {

                            ev.stopPropagation();

                            selectElement(
                                item.id,
                                ev.shiftKey
                            );
                        }
                    );

                    bar.addEventListener(
                        'pointerdown',
                        ev => {

                            ev.stopPropagation();

                            beginBarDrag(
                                ev,
                                item.id
                            );
                        }
                    );

                    lane.appendChild(bar);
                });

            row.append(
                labelNode,
                lane
            );

            el.rows.appendChild(row);
        }
    );

    renderAxis();
}

function beginBarDrag(ev,id){

    const item = getElement(id);

    if(!item){
        return;
    }

    const bar = ev.currentTarget;

    const handle =
        ev.target.closest('.bar-handle');

    const lane = bar.parentElement;

    const width =
        lane.getBoundingClientRect().width;

    state.drag = {

        id: id,

        mode:
            handle ? 'resize' : 'move',

        edge:
            handle?.dataset.edge || '',

        startX: ev.clientX,

        width: width,

        original: {
            start: item.start,
            end: item.end
        }
    };

    bar.setPointerCapture?.(
        ev.pointerId
    );

    window.addEventListener(
        'pointermove',
        moveBarDrag
    );

    window.addEventListener(
        'pointerup',
        endBarDrag,
        {once:true}
    );
}

function moveBarDrag(ev){

    const d = state.drag;

    if(!d){
        return;
    }

    const item = getElement(d.id);

    if(!item){
        return;
    }

    const dt =
        (ev.clientX - d.startX) /
        d.width *
        state.duration;

    if(d.mode === 'move'){

        const length =
            d.original.end -
            d.original.start;

        item.start =
            clamp(
                d.original.start + dt,
                0,
                state.duration - length
            );

        item.end =
            item.start + length;

    }else if(d.edge === 'left'){

        item.start =
            clamp(
                d.original.start + dt,
                0,
                d.original.end - .05
            );

    }else{

        item.end =
            clamp(
                d.original.end + dt,
                d.original.start + .05,
                state.duration
            );
    }

    renderTimeline();
    renderOverlay();
    renderConnections();
}

function endBarDrag(){

    state.drag = null;

    window.removeEventListener(
        'pointermove',
        moveBarDrag
    );
}

function renderAxis(){

    el.axis.innerHTML = '';

    if(!state.duration){
        return;
    }

    const step =
        state.duration <= 10 ? 1 :
        state.duration <= 30 ? 5 :
        state.duration <= 120 ? 10 :
        30;

    for(
        let t = 0;
        t <= state.duration + .001;
        t += step
    ){

        const tick =
            document.createElement('div');

        tick.className = 'tick';

        tick.style.left =
            t / state.duration * 100 +
            '%';

        tick.textContent =
            shortTime(t);

        el.axis.appendChild(tick);
    }
}


/* 接続線 */

function positionOf(item,pos){

    if(pos === 'left'){
        return {
            x:item.x,
            y:item.y + item.h / 2
        };
    }

    if(pos === 'right'){
        return {
            x:item.x + item.w,
            y:item.y + item.h / 2
        };
    }

    return {
        x:item.x + item.w / 2,
        y:item.y + item.h / 2
    };
}

function drawConnection(c){

    const from = getElement(c.from);
    const to = getElement(c.to);

    if(
        !from ||
        !to ||
        (!visible(from) && !visible(to))
    ){
        return;
    }

    const rect =
        el.wrap.getBoundingClientRect();

    const a =
        positionOf(
            from,
            c.fromPos
        );

    const b =
        positionOf(
            to,
            c.toPos
        );

    const ax =
        a.x / 100 * rect.width;

    const ay =
        a.y / 100 * rect.height;

    const bx =
        b.x / 100 * rect.width;

    const by =
        b.y / 100 * rect.height;

    const bend =
        Math.max(
            35,
            Math.abs(bx - ax) * .35
        );

    const path =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

    path.classList.add('connection');

    if(
        state.selectedConnection === c.id
    ){
        path.classList.add('selected');
    }

    if(c.line === 'dashed'){
        path.setAttribute(
            'stroke-dasharray',
            '9 6'
        );
    }

    if(c.line === 'dotted'){
        path.setAttribute(
            'stroke-dasharray',
            '2 6'
        );
    }

    path.setAttribute(
        'd',
        `M ${ax} ${ay}
         C ${ax + bend} ${ay},
           ${bx - bend} ${by},
           ${bx} ${by}`
    );

    path.addEventListener(
        'click',
        ev => {

            ev.stopPropagation();

            selectConnection(
                c.id,
                ev.clientX,
                ev.clientY
            );
        }
    );

    el.layer.appendChild(path);

    if(
        state.selectedConnection === c.id
    ){

        addConnectionHandle(
            c,
            'from',
            ax,
            ay
        );

        addConnectionHandle(
            c,
            'to',
            bx,
            by
        );
    }
}

function addConnectionHandle(
    connection,
    side,
    x,
    y
){

    const circle =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );

    circle.classList.add(
        'connection-handle'
    );

    circle.setAttribute('cx',x);
    circle.setAttribute('cy',y);
    circle.setAttribute('r',7);

    circle.addEventListener(
        'pointerdown',
        ev => {

            ev.stopPropagation();
            ev.preventDefault();

            state.connectionDrag = {
                id: connection.id,
                side: side
            };

            window.addEventListener(
                'pointermove',
                moveConnectionHandle
            );

            window.addEventListener(
                'pointerup',
                endConnectionHandle,
                {once:true}
            );
        }
    );

    el.layer.appendChild(circle);
}

function moveConnectionHandle(ev){

    const d =
        state.connectionDrag;

    if(!d){
        return;
    }

    const connection =
        state.connections.find(
            c => c.id === d.id
        );

    if(!connection){
        return;
    }

    const rect =
        el.wrap.getBoundingClientRect();

    const x =
        clamp(
            (ev.clientX - rect.left) /
            rect.width * 100,
            0,
            100
        );

    const y =
        clamp(
            (ev.clientY - rect.top) /
            rect.height * 100,
            0,
            100
        );

    const target =
        state.elements.find(item =>
            visible(item) &&
            x >= item.x - 5 &&
            x <= item.x + item.w + 5 &&
            y >= item.y - 5 &&
            y <= item.y + item.h + 5
        );

    if(!target){
        return;
    }

    const local =
        (x - target.x) /
        Math.max(1,target.w);

    const pos =
        local < .33
            ? 'left'
            : local > .66
                ? 'right'
                : 'center';

    if(d.side === 'from'){

        connection.from =
            target.id;

        connection.fromPos =
            pos;

    }else{

        connection.to =
            target.id;

        connection.toPos =
            pos;
    }

    renderConnections();
}

function endConnectionHandle(){

    state.connectionDrag = null;

    window.removeEventListener(
        'pointermove',
        moveConnectionHandle
    );
}

function renderConnections(){

    el.layer.innerHTML = '';

    if(!canEdit()){
        return;
    }

    el.layer.setAttribute(
        'viewBox',
        `0 0 ${el.wrap.clientWidth} ${el.wrap.clientHeight}`
    );

    state.connections.forEach(
        drawConnection
    );
}

function selectConnection(id,x,y){

    state.selected.clear();

    state.selectedConnection = id;

    render();

    openConnectionMenu(
        x,
        y
    );
}


/* メニュー */

function openElementMenu(x,y,item){

    state.selectedConnection = null;

    el.connectionMenu.style.display =
        'none';

    el.contextTitle.textContent =
        state.selected.size > 1
            ? `${state.selected.size}要素を選択中`
            : item.name;

    $('changeShape').style.display =
        item.type === 'box'
            ? 'block'
            : 'none';

    $('connect').style.display =
        state.selected.size === 2
            ? 'block'
            : 'none';

    el.context.style.display =
        'block';

    el.context.style.left =
        Math.min(
            x,
            innerWidth - 235
        ) + 'px';

    el.context.style.top =
        Math.min(
            y,
            innerHeight - 260
        ) + 'px';
}

function openConnectionMenu(x,y){

    el.context.style.display =
        'none';

    el.connectionMenu.style.display =
        'block';

    const rect =
        el.wrap.getBoundingClientRect();

    let left =
        x - rect.left + 10;

    let top =
        y - rect.top + 10;

    left =
        clamp(
            left,
            5,
            rect.width - 230
        );

    top =
        clamp(
            top,
            5,
            rect.height - 280
        );

    el.connectionMenu.style.left =
        left + 'px';

    el.connectionMenu.style.top =
        top + 'px';
}

function closeMenus(){

    el.context.style.display =
        'none';

    el.connectionMenu.style.display =
        'none';
}


/* 要素追加 */

el.context
    .querySelectorAll('[data-add]')
    .forEach(button => {

        button.onclick = () => {

            addElement(
                button.dataset.add
            );

            closeMenus();
        };
    });


/* 強調枠の図形変更 */

$('changeShape').onclick = () => {

    const id =
        [...state.selected][0];

    const item =
        getElement(id);

    if(
        !item ||
        item.type !== 'box'
    ){
        return;
    }

    item.shape =
        item.shape === 'rectangle'
            ? 'round'
            : item.shape === 'round'
                ? 'circle'
                : 'rectangle';

    render();

    closeMenus();

    toast(
        item.shape === 'rectangle'
            ? '長方形'
            : item.shape === 'round'
                ? '角丸'
                : '円形'
    );
};


/* 接続 */

$('connect').onclick = () => {

    connectSelected();

    closeMenus();
};

$('deleteElements').onclick = () => {

    deleteSelected();

    closeMenus();
};


/* 接続線メニュー */

el.connectionMenu
    .querySelectorAll('[data-line]')
    .forEach(button => {

        button.onclick = () => {

            const c =
                state.connections.find(
                    x =>
                        x.id ===
                        state.selectedConnection
                );

            if(c){

                c.line =
                    button.dataset.line;

                renderConnections();
            }
        };
    });

el.connectionMenu
    .querySelectorAll('[data-from]')
    .forEach(button => {

        button.onclick = () => {

            const c =
                state.connections.find(
                    x =>
                        x.id ===
                        state.selectedConnection
                );

            if(c){

                c.fromPos =
                    button.dataset.from;

                renderConnections();
            }
        };
    });

el.connectionMenu
    .querySelectorAll('[data-to]')
    .forEach(button => {

        button.onclick = () => {

            const c =
                state.connections.find(
                    x =>
                        x.id ===
                        state.selectedConnection
                );

            if(c){

                c.toPos =
                    button.dataset.to;

                renderConnections();
            }
        };
    });

$('deleteConnection').onclick = () => {

    if(!state.selectedConnection){
        return;
    }

    state.connections =
        state.connections.filter(
            c =>
                c.id !==
                state.selectedConnection
        );

    state.selectedConnection = null;

    render();

    closeMenus();
};


/* 動画背景 */

el.wrap.addEventListener(
    'contextmenu',
    ev => {

        if(
            ev.target.closest('.element') ||
            ev.target.closest('.connection')
        ){
            return;
        }

        ev.preventDefault();

        state.selected.clear();
        state.selectedConnection = null;

        render();

        el.contextTitle.textContent =
            '動画画面';

        $('changeShape').style.display =
            'none';

        $('connect').style.display =
            'none';

        el.context.style.display =
            'block';

        el.context.style.left =
            Math.min(
                ev.clientX,
                innerWidth - 235
            ) + 'px';

        el.context.style.top =
            Math.min(
                ev.clientY,
                innerHeight - 260
            ) + 'px';
    }
);


/*
 * メニュー外クリック
 */
document.addEventListener(
    'pointerdown',
    ev => {

        if(
            !ev.target.closest('.context') &&
            !ev.target.closest('.connection-menu')
        ){
            closeMenus();
        }
    }
);


/*
 * タイムライン上のクリックで再生位置変更
 */
el.content.addEventListener(
    'pointerdown',
    ev => {

        if(
            ev.target.closest('.bar') ||
            ev.target.closest('.axis')
        ){
            return;
        }

        const rect =
            el.axis.getBoundingClientRect();

        if(rect.width){

            seek(
                clamp(
                    (ev.clientX - rect.left) /
                    rect.width *
                    state.duration,
                    0,
                    state.duration
                )
            );
        }
    }
);


/* 描画 */

function updatePlayhead(){

    if(!state.duration){

        el.playhead.style.display =
            'none';

        return;
    }

    el.playhead.style.display =
        'block';

    el.playhead.style.left =
        'calc(var(--label) + ' +
        state.current /
        state.duration * 100 +
        '%)';

    el.time.textContent =
        fmt(state.current) +
        ' / ' +
        fmt(state.duration);
}

function updateHint(){

    if(state.selectedConnection){

        el.hint.textContent =
            '接続線を選択中';

    }else if(state.selected.size){

        el.hint.textContent =
            state.selected.size +
            '要素選択中';

    }else{

        el.hint.textContent = '';
    }
}

function render(){

    renderTimeline();
    renderOverlay();
    renderConnections();
    updatePlayhead();
    updateHint();
}


/* 新規 */

el.newBtn.onclick = () => {

    el.video.pause();

    if(state.videoUrl){
        URL.revokeObjectURL(
            state.videoUrl
        );
    }

    state.videoUrl = '';
    state.duration = 0;
    state.current = 0;
    state.elements = [];
    state.connections = [];
    state.selected.clear();
    state.selectedConnection = null;

    el.video.removeAttribute('src');
    el.video.load();

    el.empty.style.display =
        'flex';

    el.play.textContent =
        '▶';

    status(
        '動画を読み込んでください'
    );

    render();
};


/* ズーム */

el.zoom.oninput = () => {

    el.zoomValue.textContent =
        Number(
            el.zoom.value
        ).toFixed(1) + '×';

    /*
     * モックでは時間軸の見た目だけ変更。
     */
    const scale =
        Number(el.zoom.value);

    el.content.style.minWidth =
        (700 * scale) + 'px';

    renderTimeline();
    renderConnections();
    updatePlayhead();
};


/* 画面サイズ変更 */

window.addEventListener(
    'resize',
    () => {

        renderOverlay();
        renderConnections();
        updatePlayhead();
    }
);


/* 初期表示 */

render();

</script>

</body>
</html>

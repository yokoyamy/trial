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

button,input{font:inherit}

button{
    cursor:pointer;
    color:var(--text);
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
}

.btn{
    border:1px solid #40516a;
    border-radius:6px;
    background:#243247;
    padding:7px 11px;
}

.btn:hover{background:#304158}

.btn.primary{
    background:var(--blue);
    border-color:#3b82f6;
}

.file{display:none}

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
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#000;
}

#video{
    display:block;
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
    z-index:10;
    pointer-events:none;
}

.connection{
    fill:none;
    stroke:#ef4444;
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
    z-index:20;
    pointer-events:none;
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
    white-space:nowrap;
}

.element.box .element-body{
    border:4px solid #ef4444;
    background:#ef444422;
}

.element.box.round .element-body{
    border-radius:18px;
}

.element.box.circle .element-body{
    border-radius:50%;
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
    line-height:1.8;
    pointer-events:none;
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

.zoom{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:6px;
    color:var(--muted);
    font-size:12px;
}

.zoom input{width:100px}

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

.context{
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

.context button{
    display:block;
    width:100%;
    padding:8px;
    border:0;
    background:transparent;
    text-align:left;
    border-radius:4px;
}

.context button:hover{
    background:#293a52;
}

.menu-title{
    padding:6px 8px;
    color:#94a3b8;
    font-size:11px;
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

    <button class="btn primary" id="openVideo">動画を読み込む</button>
    <input class="file" id="videoFile" type="file" accept="video/*">

    <button class="btn" id="newBtn">新規</button>

    <span class="status" id="status">
        動画を読み込んでください
    </span>
</header>

<main class="main">

<section class="video-area">
    <div class="video-wrap" id="videoWrap">

        <video id="video" preload="metadata" playsinline></video>

        <svg class="connection-layer" id="connectionLayer"></svg>

        <div class="overlay" id="overlay"></div>

        <div class="empty" id="empty">
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

        <div class="zoom">
            <span>時間スケール</span>
            <input id="zoom" type="range" min="1" max="10" step=".1" value="1">
            <span id="zoomValue">1.0×</span>
        </div>

    </div>

    <div class="scroll" id="scroll">

        <div class="timeline-content" id="timelineContent">

            <div class="axis">
                <div class="axis-label">時間</div>
                <div class="axis-track" id="axis"></div>
            </div>

            <div id="rows"></div>

            <div class="playhead" id="playhead"></div>

        </div>

    </div>

</section>

</main>
</div>

<!-- 要素用右クリックメニュー -->
<div class="context" id="elementMenu">

    <div class="menu-title" id="elementMenuTitle">
        要素
    </div>

    <button data-add="text">テキストを追加</button>
    <button data-add="box">強調枠を追加</button>
    <button data-add="skip">スキップを追加</button>

    <div class="menu-separator"></div>

    <button id="changeShape">強調枠の図形を変更</button>
    <button id="connect">選択要素を接続</button>
    <button id="deleteElements">選択要素を削除</button>

</div>

<!-- 接続線用右クリックメニュー -->
<div class="context" id="connectionMenu">

    <div class="menu-title">接続線</div>

    <button data-line="solid">実線</button>
    <button data-line="dashed">破線</button>
    <button data-line="wavy">波線</button>

    <div class="menu-separator"></div>

    <div class="menu-title">始点位置</div>

    <button data-from="left">左</button>
    <button data-from="center">中央</button>
    <button data-from="right">右</button>

    <div class="menu-title">終点位置</div>

    <button data-to="left">左</button>
    <button data-to="center">中央</button>
    <button data-to="right">右</button>

    <div class="menu-separator"></div>

    <button id="deleteConnection">接続線を削除</button>

</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $ = id => document.getElementById(id);

const el = {
    video:$('video'),
    wrap:$('videoWrap'),
    overlay:$('overlay'),
    layer:$('connectionLayer'),
    empty:$('empty'),
    file:$('videoFile'),
    open:$('openVideo'),
    newBtn:$('newBtn'),
    status:$('status'),
    play:$('play'),
    stop:$('stop'),
    time:$('time'),
    zoom:$('zoom'),
    zoomValue:$('zoomValue'),
    scroll:$('scroll'),
    content:$('timelineContent'),
    axis:$('axis'),
    rows:$('rows'),
    playhead:$('playhead'),
    elementMenu:$('elementMenu'),
    elementMenuTitle:$('elementMenuTitle'),
    connectionMenu:$('connectionMenu'),
    toast:$('toast')
};

const state = {
    videoUrl:'',
    duration:0,
    current:0,
    elements:[],
    connections:[],
    selected:new Set(),
    selectedConnection:null,
    drag:null,
    connectionDrag:null,
    next:1,
    lastSkip:null
};

const colors = {
    text:'#60a5fa',
    box:'#ef4444',
    skip:'#f97316'
};

function uid(prefix){
    return prefix + '-' + Date.now().toString(36) + '-' + state.next++;
}

function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}

function fmt(v){
    v = Math.max(0,Number(v)||0);
    const m = Math.floor(v/60);
    const s = Math.floor(v%60);
    const ms = Math.floor((v%1)*1000);

    return String(m).padStart(2,'0') + ':' +
           String(s).padStart(2,'0') + '.' +
           String(ms).padStart(3,'0');
}

function shortTime(v){
    if(v < 60){
        return v.toFixed(v%1 ? 1 : 0) + 's';
    }

    return String(Math.floor(v/60)).padStart(2,'0') +
           ':' +
           String(Math.floor(v%60)).padStart(2,'0');
}

function getElement(id){
    return state.elements.find(e => e.id === id);
}

/*
 * 動画再生時の表示判定。
 * コメント・強調枠は時間範囲内だけ表示する。
 * スキップは動画上には表示しない。
 */
function visible(item){
    return item.type !== 'skip' &&
           state.current >= item.start &&
           state.current < item.end;
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


/* =========================
   動画
========================= */

el.open.onclick = () => el.file.click();

el.file.onchange = () => {

    const file = el.file.files[0];

    if(!file) return;

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
    state.lastSkip = null;

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

    /*
     * スキップ区間に再生位置が入ったら
     * その区間の終了位置まで移動する。
     */
    if(!el.video.paused){

        const skip = state.elements.find(item =>
            item.type === 'skip' &&
            state.current >= item.start &&
            state.current < item.end
        );

        if(skip && state.lastSkip !== skip.id){

            state.lastSkip = skip.id;
            seek(skip.end);
            return;
        }

        if(!skip){
            state.lastSkip = null;
        }
    }

    renderTimeOnly();
};

el.video.onplay = () => {
    el.play.textContent = 'Ⅱ';
    state.lastSkip = null;
};

el.video.onpause = () => {
    el.play.textContent = '▶';
};

el.video.onended = () => {
    el.play.textContent = '▶';
    state.current = state.duration;
    render();
};

el.play.onclick = async () => {

    if(!canEdit()) return;

    if(el.video.paused){

        try{
            await el.video.play();
        }catch(e){
            toast('再生できません');
        }

    }else{

        el.video.pause();
    }
};

el.stop.onclick = () => {

    el.video.pause();
    state.lastSkip = null;
    seek(0);
};

function seek(t){

    state.current = clamp(t,0,state.duration);
    state.lastSkip = null;

    try{
        el.video.currentTime = state.current;
    }catch(e){}

    render();
}

function renderTimeOnly(){

    el.time.textContent =
        fmt(state.current) +
        ' / ' +
        fmt(state.duration);

    updatePlayhead();

    renderOverlay();
    renderConnections();
}

function updatePlayhead(){

    if(!state.duration){
        el.playhead.style.left = 'var(--label)';
        return;
    }

    const trackWidth =
        el.axis.parentElement.getBoundingClientRect().width;

    const x =
        90 +
        trackWidth *
        (state.current / state.duration);

    el.playhead.style.left = x + 'px';
}


/* =========================
   要素
========================= */

function addElement(type){

    if(!canEdit()){
        toast('先に動画を読み込んでください');
        return;
    }

    const start = clamp(
        state.current,
        0,
        Math.max(0,state.duration-.1)
    );

    const item = {
        id:uid('el'),
        type:type,

        name:
            type === 'text' ? 'コメント' :
            type === 'box' ? '強調枠' :
            'スキップ',

        text:type === 'text' ? 'コメント' : '',

        start:start,
        end:Math.min(state.duration,start+3),

        x:12,
        y:12,
        w:type === 'box' ? 35 : 30,
        h:type === 'box' ? 25 : 15,

        color:colors[type],
        shape:'rectangle',

        fontSize:28
    };

    state.elements.push(item);

    state.selected.clear();
    state.selected.add(item.id);
    state.selectedConnection = null;

    closeMenus();
    render();

    toast(item.name + 'を追加しました');
}

function selectElement(id,additive){

    const item = getElement(id);

    if(!item) return;

    state.selectedConnection = null;

    if(!additive){
        state.selected.clear();
    }

    if(additive && state.selected.has(id)){
        state.selected.delete(id);
    }else{
        state.selected.add(id);
    }

    render();
}

function deleteSelected(){

    if(!state.selected.size) return;

    const ids = new Set(state.selected);

    state.elements =
        state.elements.filter(e => !ids.has(e.id));

    state.connections =
        state.connections.filter(c =>
            !ids.has(c.from) &&
            !ids.has(c.to)
        );

    state.selected.clear();
    state.selectedConnection = null;

    closeMenus();
    render();
}

function connectSelected(){

    if(state.selected.size !== 2){
        toast('接続する要素を2つ選択してください');
        return;
    }

    const [from,to] = [...state.selected];

    if(state.connections.some(c =>
        (c.from === from && c.to === to) ||
        (c.from === to && c.to === from)
    )){
        toast('すでに接続されています');
        return;
    }

    state.connections.push({
        id:uid('connection'),
        from:from,
        to:to,
        line:'solid',
        fromPos:'right',
        toPos:'left'
    });

    state.selectedConnection = null;

    closeMenus();
    render();

    toast('要素を接続しました');
}


/* =========================
   動画上の要素
========================= */

function renderOverlay(){

    el.overlay.innerHTML = '';

    if(!canEdit()) return;

    state.elements.forEach(item => {

        if(!visible(item)) return;

        const node = document.createElement('div');

        node.className = 'element ' + item.type;

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

        const body = document.createElement('div');
        body.className = 'element-body';

        if(item.type === 'text'){

            body.textContent = item.text;

            body.style.fontSize =
                item.fontSize + 'px';

        }else if(item.type === 'box'){

            body.textContent = '';

        }

        node.appendChild(body);

        ['nw','ne','sw','se'].forEach(pos => {

            const handle = document.createElement('span');

            handle.className = 'handle ' + pos;
            handle.dataset.resize = pos;

            node.appendChild(handle);
        });

        node.addEventListener('pointerdown',ev => {

            ev.preventDefault();
            ev.stopPropagation();

            const resize =
                ev.target.closest('.handle');

            selectElement(
                item.id,
                ev.shiftKey
            );

            beginVideoDrag(
                ev,
                item.id,
                resize
            );
        });

        /*
         * 左クリックではメニューを開かない。
         */
        node.addEventListener('contextmenu',ev => {

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
        });

        el.overlay.appendChild(node);
    });
}


/* =========================
   動画上の移動・サイズ変更
========================= */

function beginVideoDrag(ev,id,resize){

    const item = getElement(id);

    if(!item) return;

    const rect =
        el.wrap.getBoundingClientRect();

    state.drag = {
        id:id,
        mode:resize ? 'resize' : 'move',
        resize:resize?.dataset.resize || '',
        startX:ev.clientX,
        startY:ev.clientY,
        original:{
            x:item.x,
            y:item.y,
            w:item.w,
            h:item.h
        },
        width:rect.width,
        height:rect.height
    };

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

    if(!d) return;

    const item = getElement(d.id);

    if(!item) return;

    const dx =
        (ev.clientX-d.startX)/d.width*100;

    const dy =
        (ev.clientY-d.startY)/d.height*100;

    const o = d.original;

    if(d.mode === 'move'){

        item.x = clamp(
            o.x+dx,
            0,
            100-o.w
        );

        item.y = clamp(
            o.y+dy,
            0,
            100-o.h
        );

    }else{

        if(d.resize.includes('e')){
            item.w = clamp(
                o.w+dx,
                5,
                100-o.x
            );
        }

        if(d.resize.includes('s')){
            item.h = clamp(
                o.h+dy,
                5,
                100-o.y
            );
        }

        if(d.resize.includes('w')){

            const nx = clamp(
                o.x+dx,
                0,
                o.x+o.w-5
            );

            item.x = nx;
            item.w = o.w-(nx-o.x);
        }

        if(d.resize.includes('n')){

            const ny = clamp(
                o.y+dy,
                0,
                o.y+o.h-5
            );

            item.y = ny;
            item.h = o.h-(ny-o.y);
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


/* =========================
   タイムライン
========================= */

function renderTimeline(){

    el.rows.innerHTML = '';

    const groups = [
        ['text','コメント'],
        ['box','強調枠'],
        ['skip','スキップ']
    ];

    groups.forEach(([type,label]) => {

        const row = document.createElement('div');
        row.className = 'row';

        const labelNode =
            document.createElement('div');

        labelNode.className = 'row-label';

        const dot =
            document.createElement('span');

        dot.className = 'dot';
        dot.style.background = colors[type];

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
                    item.start/state.duration*100 + '%';

                bar.style.width =
                    Math.max(
                        .5,
                        (item.end-item.start) /
                        state.duration*100
                    ) + '%';

                bar.style.color = item.color;
                bar.style.background =
                    item.color + '33';

                const text =
                    document.createElement('span');

                text.textContent = item.name;

                const left =
                    document.createElement('i');

                left.className = 'bar-handle left';
                left.dataset.edge = 'left';

                const right =
                    document.createElement('i');

                right.className = 'bar-handle right';
                right.dataset.edge = 'right';

                bar.append(text,left,right);

                /*
                 * タイムラインをクリックすると
                 * その時刻へ動画カーソルを移動。
                 */
                bar.addEventListener('click',ev => {

                    ev.stopPropagation();

                    selectElement(
                        item.id,
                        ev.shiftKey
                    );
                });

                bar.addEventListener('pointerdown',ev => {

                    ev.stopPropagation();

                    beginBarDrag(
                        ev,
                        item.id
                    );
                });

                lane.appendChild(bar);
            });

        row.append(labelNode,lane);

        el.rows.appendChild(row);
    });

    renderAxis();
}

function beginBarDrag(ev,id){

    const item = getElement(id);

    if(!item) return;

    const bar = ev.currentTarget;

    const handle =
        ev.target.closest('.bar-handle');

    const lane = bar.parentElement;

    state.drag = {
        id:id,
        mode:handle ? 'resize' : 'move',
        edge:handle?.dataset.edge || '',
        startX:ev.clientX,
        width:lane.getBoundingClientRect().width,
        original:{
            start:item.start,
            end:item.end
        }
    };

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

    if(!d) return;

    const item = getElement(d.id);

    if(!item) return;

    const dt =
        (ev.clientX-d.startX) /
        d.width *
        state.duration;

    if(d.mode === 'move'){

        const length =
            d.original.end-d.original.start;

        item.start = clamp(
            d.original.start+dt,
            0,
            state.duration-length
        );

        item.end =
            item.start+length;

    }else if(d.edge === 'left'){

        item.start = clamp(
            d.original.start+dt,
            0,
            d.original.end-.05
        );

    }else{

        item.end = clamp(
            d.original.end+dt,
            d.original.start+.05,
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

    if(!state.duration) return;

    /*
     * 右端を必ず動画終了時間にする。
     * 時間スケール変更では区切り値だけ変える。
     */
    const scale = Number(el.zoom.value);

    const base =
        state.duration <= 10 ? 1 :
        state.duration <= 30 ? 5 :
        state.duration <= 120 ? 10 :
        30;

    const step =
        Math.max(.1,base/scale);

    for(let t=0;t<=state.duration+.001;t+=step){

        if(t > state.duration) t = state.duration;

        const tick =
            document.createElement('div');

        tick.className = 'tick';

        tick.style.left =
            t/state.duration*100 + '%';

        tick.textContent =
            shortTime(t);

        el.axis.appendChild(tick);

        if(t === state.duration) break;
    }
}

el.zoom.oninput = () => {

    el.zoomValue.textContent =
        Number(el.zoom.value).toFixed(1) + '×';

    renderTimeline();
    updatePlayhead();
};


/* =========================
   接続線
========================= */

function positionOf(item,pos){

    if(pos === 'left'){
        return {
            x:item.x,
            y:item.y+item.h/2
        };
    }

    if(pos === 'right'){
        return {
            x:item.x+item.w,
            y:item.y+item.h/2
        };
    }

    return {
        x:item.x+item.w/2,
        y:item.y+item.h/2
    };
}

function drawConnection(c){

    const from = getElement(c.from);
    const to = getElement(c.to);

    if(!from || !to) return;

    /*
     * 接続線は、関連する要素が動画上に
     * 表示されている時間帯だけ表示。
     */
    if(
        !visible(from) &&
        !visible(to)
    ){
        return;
    }

    const rect =
        el.wrap.getBoundingClientRect();

    const a = positionOf(from,c.fromPos);
    const b = positionOf(to,c.toPos);

    const ax = a.x/100*rect.width;
    const ay = a.y/100*rect.height;
    const bx = b.x/100*rect.width;
    const by = b.y/100*rect.height;

    const path =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

    path.classList.add('connection');

    if(state.selectedConnection === c.id){
        path.classList.add('selected');
    }

    let d;

    if(c.line === 'wavy'){

        const dx = bx-ax;
        const dy = by-ay;
        const length = Math.max(20,Math.hypot(dx,dy));
        const count = Math.max(5,Math.floor(length/28));

        const nx = -dy/length;
        const ny = dx/length;

        let parts = [`M ${ax} ${ay}`];

        for(let i=1;i<=count;i++){

            const t = i/count;

            const x = ax+dx*t;
            const y = ay+dy*t;

            const wave =
                Math.sin(t*Math.PI*count) * 8;

            parts.push(
                `L ${x+nx*wave} ${y+ny*wave}`
            );
        }

        d = parts.join(' ');

    }else{

        const bend =
            Math.max(
                35,
                Math.abs(bx-ax)*.35
            );

        d =
            `M ${ax} ${ay}
             C ${ax+bend} ${ay},
               ${bx-bend} ${by},
               ${bx} ${by}`;
    }

    path.setAttribute('d',d);

    if(c.line === 'dashed'){
        path.setAttribute(
            'stroke-dasharray',
            '9 6'
        );
    }

    /*
     * 左クリックは選択だけ。
     * メニューはここでは開かない。
     */
    path.addEventListener('click',ev => {

        ev.stopPropagation();

        selectConnection(c.id);
    });

    /*
     * 右クリックだけ接続線メニューを開く。
     */
    path.addEventListener('contextmenu',ev => {

        ev.preventDefault();
        ev.stopPropagation();

        selectConnection(c.id);

        openConnectionMenu(
            ev.clientX,
            ev.clientY
        );
    });

    el.layer.appendChild(path);

    if(state.selectedConnection === c.id){

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

function addConnectionHandle(connection,side,x,y){

    const circle =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );

    circle.classList.add('connection-handle');

    circle.setAttribute('cx',x);
    circle.setAttribute('cy',y);
    circle.setAttribute('r',7);

    circle.addEventListener('pointerdown',ev => {

        ev.stopPropagation();
        ev.preventDefault();

        state.connectionDrag = {
            id:connection.id,
            side:side
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
    });

    el.layer.appendChild(circle);
}

function moveConnectionHandle(ev){

    const d = state.connectionDrag;

    if(!d) return;

    const connection =
        state.connections.find(
            c => c.id === d.id
        );

    if(!connection) return;

    const rect =
        el.wrap.getBoundingClientRect();

    const x = clamp(
        (ev.clientX-rect.left) /
        rect.width*100,
        0,
        100
    );

    const y = clamp(
        (ev.clientY-rect.top) /
        rect.height*100,
        0,
        100
    );

    const target =
        state.elements.find(item =>
            item.type !== 'skip' &&
            visible(item) &&
            x >= item.x-5 &&
            x <= item.x+item.w+5 &&
            y >= item.y-5 &&
            y <= item.y+item.h+5
        );

    if(!target) return;

    const local =
        (x-target.x) /
        Math.max(1,target.w);

    const pos =
        local < .33
            ? 'left'
            : local > .66
                ? 'right'
                : 'center';

    if(d.side === 'from'){

        connection.from = target.id;
        connection.fromPos = pos;

    }else{

        connection.to = target.id;
        connection.toPos = pos;
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

    if(!canEdit()) return;

    el.layer.setAttribute(
        'viewBox',
        `0 0 ${el.wrap.clientWidth} ${el.wrap.clientHeight}`
    );

    state.connections.forEach(drawConnection);
}

function selectConnection(id){

    state.selected.clear();
    state.selectedConnection = id;

    render();
}


/* =========================
   メニュー
========================= */

function closeMenus(){

    el.elementMenu.style.display = 'none';
    el.connectionMenu.style.display = 'none';
}

function showMenu(menu,x,y){

    closeMenus();

    menu.style.display = 'block';

    const width = menu.offsetWidth;
    const height = menu.offsetHeight;

    menu.style.left =
        Math.min(
            x,
            window.innerWidth-width-8
        ) + 'px';

    menu.style.top =
        Math.min(
            y,
            window.innerHeight-height-8
        ) + 'px';
}

function openElementMenu(x,y,item){

    el.elementMenuTitle.textContent =
        state.selected.size > 1
            ? '複数要素'
            : item.name;

    el.elementMenu
        .querySelector('#changeShape')
        .style.display =
            item.type === 'box' &&
            state.selected.size === 1
                ? 'block'
                : 'none';

    el.elementMenu
        .querySelector('#connect')
        .style.display =
            state.selected.size === 2
                ? 'block'
                : 'none';

    showMenu(
        el.elementMenu,
        x,
        y
    );
}

function openConnectionMenu(x,y){

    showMenu(
        el.connectionMenu,
        x,
        y
    );
}


/* 要素追加 */
el.elementMenu
    .querySelectorAll('[data-add]')
    .forEach(button => {

        button.onclick = () => {

            addElement(
                button.dataset.add
            );
        };
    });


/* 強調枠の図形変更 */
$('changeShape').onclick = () => {

    const item =
        [...state.selected]
            .map(getElement)
            .find(Boolean);

    if(!item || item.type !== 'box') return;

    item.shape =
        item.shape === 'rectangle'
            ? 'round'
            : item.shape === 'round'
                ? 'circle'
                : 'rectangle';

    closeMenus();
    render();
};


/* 接続 */
$('connect').onclick = () => {

    connectSelected();
};


/* 要素削除 */
$('deleteElements').onclick = () => {

    deleteSelected();
};


/* 線種変更 */
el.connectionMenu
    .querySelectorAll('[data-line]')
    .forEach(button => {

        button.onclick = () => {

            const connection =
                state.connections.find(
                    c => c.id === state.selectedConnection
                );

            if(!connection) return;

            connection.line =
                button.dataset.line;

            closeMenus();
            renderConnections();
        };
    });


/* 始点 */
el.connectionMenu
    .querySelectorAll('[data-from]')
    .forEach(button => {

        button.onclick = () => {

            const connection =
                state.connections.find(
                    c => c.id === state.selectedConnection
                );

            if(!connection) return;

            connection.fromPos =
                button.dataset.from;

            closeMenus();
            renderConnections();
        };
    });


/* 終点 */
el.connectionMenu
    .querySelectorAll('[data-to]')
    .forEach(button => {

        button.onclick = () => {

            const connection =
                state.connections.find(
                    c => c.id === state.selectedConnection
                );

            if(!connection) return;

            connection.toPos =
                button.dataset.to;

            closeMenus();
            renderConnections();
        };
    });


/* 接続線削除 */
$('deleteConnection').onclick = () => {

    if(!state.selectedConnection) return;

    state.connections =
        state.connections.filter(
            c => c.id !== state.selectedConnection
        );

    state.selectedConnection = null;

    closeMenus();
    render();
};


/* =========================
   画面共通操作
========================= */

/*
 * 動画・タイムラインの空白クリック。
 * タイムラインではクリック位置へカーソルを移動。
 */
el.axis.addEventListener('click',ev => {

    if(!state.duration) return;

    const rect =
        el.axis.getBoundingClientRect();

    const ratio =
        clamp(
            (ev.clientX-rect.left) /
            rect.width,
            0,
            1
        );

    seek(
        ratio*state.duration
    );
});

el.rows.addEventListener('click',ev => {

    if(
        ev.target.closest('.bar') ||
        ev.target.closest('.bar-handle')
    ){
        return;
    }

    const lane =
        ev.target.closest('.lane');

    if(!lane || !state.duration) return;

    const rect =
        lane.getBoundingClientRect();

    const ratio =
        clamp(
            (ev.clientX-rect.left) /
            rect.width,
            0,
            1
        );

    seek(
        ratio*state.duration
    );
});

el.wrap.addEventListener('click',ev => {

    if(
        ev.target.closest('.element') ||
        ev.target.closest('.connection-handle')
    ){
        return;
    }

    state.selected.clear();
    state.selectedConnection = null;

    closeMenus();
    render();
});


/*
 * 動画領域の空白を右クリックすると
 * 要素追加メニューだけ表示。
 */
el.wrap.addEventListener('contextmenu',ev => {

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

    showMenu(
        el.elementMenu,
        ev.clientX,
        ev.clientY
    );
});


document.addEventListener('click',ev => {

    if(
        !ev.target.closest('.context') &&
        !ev.target.closest('.connection')
    ){
        closeMenus();
    }
});


/*
 * 新規。
 * モックなので保存は行わず、編集状態だけ初期化。
 */
el.newBtn.onclick = () => {

    el.video.pause();

    state.elements = [];
    state.connections = [];
    state.selected.clear();
    state.selectedConnection = null;
    state.current = 0;
    state.lastSkip = null;

    seek(0);

    status(
        state.duration
            ? '編集中'
            : '動画を読み込んでください'
    );

    render();
};


/* =========================
   全体描画
========================= */

function render(){

    el.time.textContent =
        fmt(state.current) +
        ' / ' +
        fmt(state.duration);

    renderOverlay();
    renderConnections();
    renderTimeline();
    updatePlayhead();
}


/*
 * ウィンドウサイズ変更時も
 * 動画上の接続線を追従させる。
 */
window.addEventListener(
    'resize',
    () => {
        renderOverlay();
        renderConnections();
        renderTimeline();
        updatePlayhead();
    }
);

render();
</script>

</body>
</html>

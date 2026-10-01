<?php
declare(strict_types=1);
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
    --right:270px;
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
button,input,select{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}

.app{
    width:100vw;
    height:100vh;
    display:flex;
    flex-direction:column
}

.topbar{
    height:52px;
    flex:none;
    display:flex;
    align-items:center;
    gap:7px;
    padding:7px 10px;
    background:#080f1b;
    border-bottom:1px solid var(--line)
}
.brand{
    font-weight:700;
    margin-right:8px;
    white-space:nowrap
}
.btn{
    border:1px solid #40516a;
    border-radius:6px;
    background:#243247;
    color:var(--text);
    padding:7px 11px
}
.btn:hover{background:#304158}
.btn.primary{background:var(--blue);border-color:#3b82f6}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}

.status{
    margin-left:auto;
    color:var(--muted);
    font-size:12px;
    white-space:nowrap
}
.file-input{display:none}

.main{
    min-height:0;
    flex:1;
    display:flex;
    flex-direction:column
}

.workspace{
    min-height:0;
    flex:1;
    display:flex
}

.video-area{
    min-width:0;
    flex:1;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:14px;
    background:#050a11
}

.video-wrap{
    position:relative;
    width:min(1050px,100%);
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#000;
    overflow:hidden
}

#video{
    display:block;
    max-width:100%;
    max-height:100%;
    background:#000
}

/*
 * 動画上の編集レイヤー。
 * 要素・接続線はすべてここに描画する。
 */
.video-overlay{
    position:absolute;
    inset:0;
    pointer-events:none
}

.connection-layer{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
    z-index:10
}

.connection-path{
    fill:none;
    pointer-events:stroke;
    cursor:pointer
}

.connection-path.selected{
    filter:drop-shadow(0 0 3px #fff);
}

.connection-handle{
    pointer-events:auto;
    cursor:crosshair;
    stroke:#fff;
    stroke-width:2
}

.overlay-element{
    position:absolute;
    min-width:25px;
    min-height:20px;
    user-select:none;
    pointer-events:auto;
    cursor:move;
    z-index:20
}

.overlay-element.selected{
    outline:2px solid #60a5fa;
    outline-offset:2px
}

.overlay-content{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden
}

.overlay-text{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
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

.overlay-box.rounded{border-radius:16px}
.overlay-box.circle{border-radius:50%}
.overlay-box.ellipse{border-radius:50%}

.resize-handle{
    position:absolute;
    width:9px;
    height:9px;
    background:#fff;
    border:1px solid #2563eb;
    z-index:30
}
.resize-handle.nw{left:-6px;top:-6px;cursor:nwse-resize}
.resize-handle.ne{right:-6px;top:-6px;cursor:nesw-resize}
.resize-handle.sw{left:-6px;bottom:-6px;cursor:nesw-resize}
.resize-handle.se{right:-6px;bottom:-6px;cursor:nwse-resize}

.empty-video{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    color:#64748b;
    line-height:1.8;
    pointer-events:none
}
.empty-video strong{
    display:block;
    color:#94a3b8;
    font-size:18px
}

.right-panel{
    width:var(--right);
    flex:none;
    background:var(--panel);
    border-left:1px solid var(--line);
    overflow:auto
}

.panel-head{
    padding:13px 15px;
    border-bottom:1px solid var(--line);
    font-weight:700
}

.panel-empty{
    padding:25px 18px;
    color:var(--muted);
    font-size:13px;
    line-height:1.8
}

.panel-section{
    padding:13px 15px;
    border-bottom:1px solid var(--line)
}

.panel-title{
    font-size:12px;
    color:var(--muted);
    margin-bottom:9px
}

.field{
    display:grid;
    gap:5px;
    margin-bottom:10px
}
.field:last-child{margin-bottom:0}
.field label{
    font-size:11px;
    color:var(--muted)
}
.field input,
.field select{
    width:100%;
    padding:7px 8px;
    border:1px solid #40516a;
    border-radius:5px;
    background:#0f1722;
    color:var(--text)
}

.button-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:6px
}

.choice{
    padding:7px 5px;
    border:1px solid #40516a;
    border-radius:5px;
    background:#1b293d;
    color:#ddd;
    font-size:12px
}
.choice.active{
    background:#2563eb;
    border-color:#60a5fa
}

.color-row{
    display:flex;
    gap:6px;
    flex-wrap:wrap
}
.color{
    width:25px;
    height:25px;
    border:2px solid transparent;
    border-radius:4px
}
.color.active{
    border-color:#fff;
    box-shadow:0 0 0 1px #60a5fa
}

.timeline-panel{
    height:285px;
    flex:none;
    display:flex;
    flex-direction:column;
    border-top:1px solid var(--line);
    background:#0e1724
}

.timeline-toolbar{
    height:43px;
    flex:none;
    display:flex;
    align-items:center;
    gap:7px;
    padding:5px 8px;
    border-bottom:1px solid var(--line)
}

.time-readout{
    min-width:130px;
    font-variant-numeric:tabular-nums;
    color:#dbeafe
}

.timeline-scroll{
    position:relative;
    flex:1;
    min-height:0;
    overflow:auto
}

.timeline-content{
    position:relative;
    min-width:700px;
    height:100%
}

.axis{
    height:32px;
    display:flex;
    border-bottom:1px solid var(--line)
}

.axis-label,
.track-label{
    width:110px;
    min-width:110px;
    background:#131d29;
    border-right:1px solid var(--line);
    padding:7px 8px;
    font-size:11px;
    color:#94a3b8
}

.axis-track{
    position:relative;
    flex:1
}

.tick{
    position:absolute;
    top:0;
    height:100%;
    border-left:1px solid #334155;
    padding:5px 0 0 3px;
    color:#64748b;
    font-size:10px
}

.track{
    height:62px;
    display:flex;
    border-bottom:1px solid #263445
}

.track-lane{
    position:relative;
    flex:1
}

.track-grid{
    position:absolute;
    inset:0;
    background-image:linear-gradient(
        to right,
        rgba(148,163,184,.08) 1px,
        transparent 1px
    );
    pointer-events:none
}

.element-bar{
    position:absolute;
    top:12px;
    height:37px;
    min-width:12px;
    border:1px solid currentColor;
    border-radius:5px;
    display:flex;
    align-items:center;
    cursor:pointer;
    overflow:hidden
}

.element-bar.selected{
    box-shadow:0 0 0 2px #60a5fa
}

.bar-label{
    padding:0 7px;
    font-size:10px;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis
}

.handle{
    position:absolute;
    top:0;
    width:8px;
    height:100%;
    cursor:ew-resize
}
.handle.left{left:-4px}
.handle.right{right:-4px}

.playhead{
    position:absolute;
    top:32px;
    bottom:0;
    width:2px;
    background:#ef4444;
    z-index:50;
    pointer-events:none
}

.playhead-label{
    position:absolute;
    top:-20px;
    left:4px;
    padding:2px 4px;
    background:#ef4444;
    border-radius:3px;
    color:#fff;
    font-size:9px;
    white-space:nowrap
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
    border-radius:6px
}

@media(max-width:850px){
    :root{--right:225px}
    .brand{display:none}
    .right-panel{width:var(--right)}
}

@media(max-width:650px){
    .workspace{flex-direction:column}
    .video-area{min-height:280px}
    .right-panel{
        width:100%;
        height:220px;
        border-left:0;
        border-top:1px solid var(--line)
    }
}
</style>
</head>

<body>

<div class="app">

<header class="topbar">
    <div class="brand">動画編集・注釈</div>

    <button class="btn primary" id="openVideo">動画を読み込む</button>
    <input class="file-input" id="videoFile" type="file" accept="video/*">

    <button class="btn" id="newProject">新規</button>

    <span class="status" id="status">
        動画を読み込んでください
    </span>
</header>


<main class="main">

<div class="workspace">

<section class="video-area">

    <div class="video-wrap" id="videoWrap">

        <video id="video" playsinline preload="metadata"></video>

        <div class="video-overlay" id="overlay">

            <svg
                class="connection-layer"
                id="connectionLayer"
                preserveAspectRatio="none">
            </svg>

        </div>

        <div class="empty-video" id="emptyVideo">
            <div>
                <strong>動画を読み込んでください</strong>
                動画上で右クリックすると要素を追加できます
            </div>
        </div>

    </div>

</section>


<aside class="right-panel">

    <div class="panel-head">
        <span id="panelTitle">操作メニュー</span>
    </div>

    <div id="panelBody">
        <div class="panel-empty">
            動画上の要素を選択すると、<br>
            選択した要素に応じた操作を表示します。
        </div>
    </div>

</aside>

</div>


<section class="timeline-panel">

    <div class="timeline-toolbar">

        <button class="btn small" id="play">▶</button>
        <button class="btn small" id="stop">■</button>

        <span class="time-readout" id="readout">
            00:00.000 / 00:00.000
        </span>

        <button class="btn small" id="addText">＋テキスト</button>
        <button class="btn small" id="addBox">＋強調枠</button>
        <button class="btn small" id="addSkip">＋スキップ</button>

    </div>


    <div class="timeline-scroll">

        <div class="timeline-content" id="timeline">

            <div class="axis">

                <div class="axis-label">
                    時間
                </div>

                <div class="axis-track" id="axis"></div>

            </div>


            <div id="tracks"></div>


            <div
                class="playhead"
                id="playhead"
                style="display:none">
                <span
                    class="playhead-label"
                    id="playheadLabel">
                    00:00.000
                </span>
            </div>

        </div>

    </div>

</section>

</main>

</div>


<div class="toast" id="toast"></div>


<script>
'use strict';

const $ = id => document.getElementById(id);

const video = $('video');
const overlay = $('overlay');
const connectionLayer = $('connectionLayer');
const videoWrap = $('videoWrap');
const emptyVideo = $('emptyVideo');

const tracks = $('tracks');
const axis = $('axis');
const timeline = $('timeline');
const playhead = $('playhead');
const playheadLabel = $('playheadLabel');

const panelTitle = $('panelTitle');
const panelBody = $('panelBody');

const state = {
    videoUrl:null,
    duration:0,
    currentTime:0,

    elements:[],
    connections:[],

    selectedElement:null,
    selectedConnection:null,

    drag:null
};


function uid(prefix){
    return prefix + '-' +
        Date.now().toString(36) +
        '-' +
        Math.random().toString(36).slice(2,7);
}


function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}


function timeText(v){
    v=Math.max(0,Number(v)||0);

    const m=Math.floor(v/60);
    const s=Math.floor(v%60);
    const ms=Math.floor((v%1)*1000);

    return String(m).padStart(2,'0') +
        ':' +
        String(s).padStart(2,'0') +
        '.' +
        String(ms).padStart(3,'0');
}


function colorFor(type){
    if(type==='text') return '#60a5fa';
    if(type==='box') return '#22c55e';
    return '#f97316';
}


function rgba(hex,a){
    const n=parseInt(hex.slice(1),16);

    return `rgba(
        ${n>>16},
        ${(n>>8)&255},
        ${n&255},
        ${a}
    )`;
}


function toast(message){
    $('toast').textContent=message;
    $('toast').style.display='block';

    clearTimeout(toast.timer);

    toast.timer=setTimeout(()=>{
        $('toast').style.display='none';
    },1800);
}


function selectedElement(){
    return state.elements.find(
        e=>e.id===state.selectedElement
    ) || null;
}


function selectedConnection(){
    return state.connections.find(
        c=>c.id===state.selectedConnection
    ) || null;
}


function visible(e){
    return state.currentTime>=e.start &&
           state.currentTime<e.end;
}


function selectElement(id){
    if(!state.elements.some(e=>e.id===id)) return;

    state.selectedElement=id;
    state.selectedConnection=null;

    renderAll();
}


function selectConnection(id){
    if(!state.connections.some(c=>c.id===id)) return;

    state.selectedConnection=id;
    state.selectedElement=null;

    renderAll();
}


function clearSelection(){
    state.selectedElement=null;
    state.selectedConnection=null;
    renderAll();
}


/*
 * -----------------------------
 * 動画読み込み
 * -----------------------------
 */

$('openVideo').onclick=()=>{
    $('videoFile').click();
};


$('videoFile').onchange=()=>{
    const file=$('videoFile').files[0];

    if(!file || !file.type.startsWith('video/')){
        toast('動画ファイルを選択してください');
        return;
    }

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoUrl=URL.createObjectURL(file);

    video.src=state.videoUrl;

    state.elements=[];
    state.connections=[];
    state.selectedElement=null;
    state.selectedConnection=null;

    $('status').textContent='動画を読み込んでいます…';
};


video.onloadedmetadata=()=>{
    state.duration=Number(video.duration)||0;
    state.currentTime=0;

    emptyVideo.style.display='none';

    $('status').textContent='編集中';

    renderAll();
};


video.ontimeupdate=()=>{
    state.currentTime=video.currentTime;

    renderTimeline();
    renderVideoElements();
    renderConnections();
    updatePlayhead();
};


$('play').onclick=async()=>{
    if(!state.duration) return;

    if(video.paused){
        try{
            await video.play();
            $('play').textContent='Ⅱ';
        }catch(e){
            toast('再生できません');
        }
    }else{
        video.pause();
        $('play').textContent='▶';
    }
};


$('stop').onclick=()=>{
    video.pause();
    $('play').textContent='▶';
    video.currentTime=0;
};


$('newProject').onclick=()=>{
    video.pause();

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoUrl=null;
    state.duration=0;
    state.currentTime=0;
    state.elements=[];
    state.connections=[];
    state.selectedElement=null;
    state.selectedConnection=null;

    video.removeAttribute('src');
    video.load();

    emptyVideo.style.display='flex';

    $('status').textContent='動画を読み込んでください';

    renderAll();
};


/*
 * -----------------------------
 * 要素追加
 * -----------------------------
 */

function addElement(type,x=10,y=10){

    if(!state.duration){
        toast('先に動画を読み込んでください');
        return;
    }

    const start=state.currentTime;
    const end=Math.min(
        state.duration,
        start+3
    );

    const e={
        id:uid('el'),
        type:type,
        name:
            type==='text'
                ? 'テキスト'
                : type==='box'
                    ? '強調枠'
                    : 'スキップ',

        text:
            type==='text'
                ? 'テキスト'
                : '',

        start:start,
        end:end,

        x:clamp(x,0,85),
        y:clamp(y,0,85),

        w:type==='box'?30:32,
        h:type==='box'?22:15,

        color:colorFor(type),

        fontSize:26,
        fontWeight:'700',

        shape:'rectangle'
    };

    state.elements.push(e);

    selectElement(e.id);

    $('status').textContent='要素を追加しました';
}


$('addText').onclick=()=>addElement('text');
$('addBox').onclick=()=>addElement('box');
$('addSkip').onclick=()=>addElement('skip');


/*
 * -----------------------------
 * 動画上の要素
 * -----------------------------
 */

function renderVideoElements(){

    overlay
        .querySelectorAll('.overlay-element')
        .forEach(n=>n.remove());

    if(!state.duration) return;

    state.elements.forEach(e=>{

        if(!visible(e)) return;

        const node=document.createElement('div');

        node.className='overlay-element';

        if(state.selectedElement===e.id){
            node.classList.add('selected');
        }

        node.dataset.id=e.id;

        node.style.left=e.x+'%';
        node.style.top=e.y+'%';
        node.style.width=e.w+'%';
        node.style.height=e.h+'%';
        node.style.color=e.color;

        const content=document.createElement('div');

        content.className='overlay-content';

        if(e.type==='text'){

            content.className='overlay-text';

            content.textContent=e.text;

            content.style.fontSize=e.fontSize+'px';
            content.style.fontWeight=e.fontWeight;

        }else if(e.type==='box'){

            content.className='overlay-box';

            if(e.shape==='rounded'){
                content.classList.add('rounded');
            }

            if(e.shape==='circle'){
                content.classList.add('circle');
            }

            if(e.shape==='ellipse'){
                content.classList.add('ellipse');
            }

        }else{

            content.className='overlay-box';
            content.style.borderStyle='dashed';
            content.style.color='#f97316';
            content.textContent='スキップ';

        }

        node.appendChild(content);


        if(state.selectedElement===e.id){

            ['nw','ne','sw','se'].forEach(pos=>{

                const h=document.createElement('span');

                h.className='resize-handle '+pos;

                h.dataset.resize=pos;

                node.appendChild(h);

            });

        }


        node.addEventListener('pointerdown',ev=>{
            ev.stopPropagation();
            beginElementDrag(ev,e,node);
        });


        node.addEventListener('click',ev=>{
            ev.stopPropagation();
            selectElement(e.id);
        });


        node.addEventListener('dblclick',ev=>{
            ev.stopPropagation();
            selectElement(e.id);
        });


        overlay.appendChild(node);
    });
}


function beginElementDrag(ev,e,node){

    selectElement(e.id);

    ev.preventDefault();

    const resize=ev.target.dataset.resize || null;

    const rect=videoWrap.getBoundingClientRect();

    state.drag={
        type:resize?'resize':'move',
        resize:resize,
        id:e.id,
        startX:ev.clientX,
        startY:ev.clientY,
        original:{
            x:e.x,
            y:e.y,
            w:e.w,
            h:e.h
        },
        width:rect.width,
        height:rect.height
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


function moveElementDrag(ev){

    const d=state.drag;

    if(!d) return;

    const e=state.elements.find(x=>x.id===d.id);

    if(!e) return;

    const dx=(ev.clientX-d.startX)/d.width*100;
    const dy=(ev.clientY-d.startY)/d.height*100;

    if(d.type==='move'){

        e.x=clamp(
            d.original.x+dx,
            0,
            100-d.original.w
        );

        e.y=clamp(
            d.original.y+dy,
            0,
            100-d.original.h
        );

    }else{

        if(d.resize.includes('e')){
            e.w=clamp(
                d.original.w+dx,
                3,
                100-d.original.x
            );
        }

        if(d.resize.includes('s')){
            e.h=clamp(
                d.original.h+dy,
                3,
                100-d.original.y
            );
        }

        if(d.resize.includes('w')){

            const nx=clamp(
                d.original.x+dx,
                0,
                d.original.x+d.original.w-3
            );

            e.w=d.original.w-(nx-d.original.x);
            e.x=nx;
        }

        if(d.resize.includes('n')){

            const ny=clamp(
                d.original.y+dy,
                0,
                d.original.y+d.original.h-3
            );

            e.h=d.original.h-(ny-d.original.y);
            e.y=ny;
        }
    }

    renderVideoElements();
    renderConnections();
    renderPanel();
}


function endElementDrag(){
    state.drag=null;

    window.removeEventListener(
        'pointermove',
        moveElementDrag
    );
}


/*
 * -----------------------------
 * 接続線
 * -----------------------------
 *
 * 接続線は動画画面上だけに描画する。
 * タイムライン上には描画しない。
 */

function anchorPoint(e,position){

    const x=e.x;
    const y=e.y;
    const w=e.w;
    const h=e.h;

    const map={
        tl:[x,y],
        tc:[x+w/2,y],
        tr:[x+w,y],

        ml:[x,y+h/2],
        mc:[x+w/2,y+h/2],
        mr:[x+w,y+h/2],

        bl:[x,y+h],
        bc:[x+w/2,y+h],
        br:[x+w,y+h]
    };

    const p=map[position] || map.mr;

    return {
        x:p[0],
        y:p[1]
    };
}


function renderConnections(){

    connectionLayer.innerHTML='';

    if(!state.duration) return;

    const svgNS='http://www.w3.org/2000/svg';

    connectionLayer.setAttribute(
        'viewBox',
        `0 0 ${videoWrap.clientWidth} ${videoWrap.clientHeight}`
    );

    state.connections.forEach(c=>{

        const from=state.elements.find(
            e=>e.id===c.from
        );

        const to=state.elements.find(
            e=>e.id===c.to
        );

        if(!from || !to) return;

        if(!visible(from) && !visible(to)) return;

        const W=videoWrap.clientWidth;
        const H=videoWrap.clientHeight;

        const a=anchorPoint(from,c.fromPos);
        const b=anchorPoint(to,c.toPos);

        const ax=a.x/100*W;
        const ay=a.y/100*H;

        const bx=b.x/100*W;
        const by=b.y/100*H;

        const dx=Math.abs(bx-ax);
        const bend=Math.max(35,dx*.45);

        const path=document.createElementNS(
            svgNS,
            'path'
        );

        path.classList.add('connection-path');

        if(state.selectedConnection===c.id){
            path.classList.add('selected');
        }

        path.setAttribute(
            'stroke',
            c.color
        );

        path.setAttribute(
            'stroke-width',
            c.width
        );

        path.setAttribute(
            'stroke-dasharray',
            c.style==='dashed'
                ? '9 6'
                : c.style==='dotted'
                    ? '2 7'
                    : 'none'
        );

        path.setAttribute(
            'd',
            `M ${ax} ${ay}
             C ${ax+bend} ${ay},
               ${bx-bend} ${by},
               ${bx} ${by}`
        );

        path.addEventListener('click',ev=>{
            ev.stopPropagation();
            selectConnection(c.id);
        });

        connectionLayer.appendChild(path);


        /*
         * 接続線選択時のみ始点・終点を操作できる。
         */
        if(state.selectedConnection===c.id){

            drawConnectionHandle(
                ax,
                ay,
                'from',
                c
            );

            drawConnectionHandle(
                bx,
                by,
                'to',
                c
            );
        }
    });
}


function drawConnectionHandle(x,y,type,c){

    const svgNS='http://www.w3.org/2000/svg';

    const circle=document.createElementNS(
        svgNS,
        'circle'
    );

    circle.classList.add('connection-handle');

    circle.setAttribute('cx',x);
    circle.setAttribute('cy',y);
    circle.setAttribute('r',7);

    circle.setAttribute(
        'fill',
        type==='from'
            ? '#2563eb'
            : '#ef4444'
    );

    circle.addEventListener(
        'pointerdown',
        ev=>{
            ev.stopPropagation();
            beginConnectionHandle(ev,c,type);
        }
    );

    connectionLayer.appendChild(circle);
}


function beginConnectionHandle(ev,c,type){

    ev.preventDefault();

    state.drag={
        type:'connection',
        connection:c,
        endpoint:type
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


function nearestAnchor(e,clientX,clientY){

    const rect=videoWrap.getBoundingClientRect();

    const px=(clientX-rect.left)/rect.width*100;
    const py=(clientY-rect.top)/rect.height*100;

    const points=[
        ['tl',e.x,e.y],
        ['tc',e.x+e.w/2,e.y],
        ['tr',e.x+e.w,e.y],

        ['ml',e.x,e.y+e.h/2],
        ['mc',e.x+e.w/2,e.y+e.h/2],
        ['mr',e.x+e.w,e.y+e.h/2],

        ['bl',e.x,e.y+e.h],
        ['bc',e.x+e.w/2,e.y+e.h],
        ['br',e.x+e.w,e.y+e.h]
    ];

    let best=points[0];
    let dist=Infinity;

    points.forEach(p=>{

        const dx=p[1]-px;
        const dy=p[2]-py;
        const d=dx*dx+dy*dy;

        if(d<dist){
            dist=d;
            best=p;
        }
    });

    return best[0];
}


function moveConnectionHandle(ev){

    const d=state.drag;

    if(!d || d.type!=='connection') return;

    const c=d.connection;

    const elementId=
        d.endpoint==='from'
            ? c.from
            : c.to;

    const e=state.elements.find(
        x=>x.id===elementId
    );

    if(!e) return;

    const pos=nearestAnchor(
        e,
        ev.clientX,
        ev.clientY
    );

    if(d.endpoint==='from'){
        c.fromPos=pos;
    }else{
        c.toPos=pos;
    }

    renderConnections();
    renderPanel();
}


function endConnectionHandle(){

    state.drag=null;

    window.removeEventListener(
        'pointermove',
        moveConnectionHandle
    );
}


/*
 * -----------------------------
 * 接続作成
 * -----------------------------
 */

function connectElements(a,b){

    if(!a || !b || a===b) return;

    const exists=state.connections.some(
        c=>
            (c.from===a && c.to===b) ||
            (c.from===b && c.to===a)
    );

    if(exists){
        toast('この2要素はすでに接続されています');
        return;
    }

    state.connections.push({
        id:uid('connection'),

        from:a,
        to:b,

        fromPos:'mr',
        toPos:'ml',

        color:'#60a5fa',
        width:3,
        style:'solid'
    });

    toast('要素を接続しました');

    renderAll();
}


/*
 * Shiftクリックで2要素を選択して接続。
 */
overlay.addEventListener('click',ev=>{
    if(ev.target===overlay){
        clearSelection();
    }
});


let pendingElement=null;

overlay.addEventListener('pointerdown',ev=>{
    const node=ev.target.closest('.overlay-element');

    if(!node) return;

    const id=node.dataset.id;

    if(ev.shiftKey){

        if(!pendingElement){

            pendingElement=id;
            selectElement(id);

        }else if(pendingElement!==id){

            connectElements(
                pendingElement,
                id
            );

            pendingElement=null;
        }

    }else{

        pendingElement=null;
        selectElement(id);
    }
});


/*
 * -----------------------------
 * 右メニュー
 * -----------------------------
 */

function renderPanel(){

    if(state.selectedConnection){

        renderConnectionPanel();
        return;
    }

    const e=selectedElement();

    if(e){

        renderElementPanel(e);
        return;
    }

    panelTitle.textContent='操作メニュー';

    panelBody.innerHTML=`
        <div class="panel-empty">
            動画上の要素を選択してください。<br><br>
            Shiftキーを押しながら別の要素を選択すると、
            2つの要素を接続できます。
        </div>
    `;
}


function renderElementPanel(e){

    panelTitle.textContent=
        e.type==='text'
            ? 'テキスト'
            : e.type==='box'
                ? '強調枠'
                : 'スキップ';

    let html='';


    if(e.type==='text'){

        html+=`
        <div class="panel-section">

            <div class="panel-title">
                テキスト
            </div>

            <div class="field">
                <label>表示内容</label>
                <input id="propText"
                    value="${escapeHtml(e.text)}">
            </div>

            <div class="field">
                <label>文字サイズ</label>
                <input id="propFont"
                    type="number"
                    min="8"
                    max="100"
                    value="${e.fontSize}">
            </div>

            <div class="button-grid">
                <button class="choice ${e.fontWeight==='400'?'active':''}"
                    data-weight="400">標準</button>

                <button class="choice ${e.fontWeight==='700'?'active':''}"
                    data-weight="700">太字</button>

                <button class="choice ${e.fontWeight==='900'?'active':''}"
                    data-weight="900">極太</button>
            </div>

        </div>`;
    }


    if(e.type==='box'){

        html+=`
        <div class="panel-section">

            <div class="panel-title">
                図形
            </div>

            <div class="button-grid">

                <button class="choice ${e.shape==='rectangle'?'active':''}"
                    data-shape="rectangle">
                    四角
                </button>

                <button class="choice ${e.shape==='rounded'?'active':''}"
                    data-shape="rounded">
                    角丸
                </button>

                <button class="choice ${e.shape==='circle'?'active':''}"
                    data-shape="circle">
                    円
                </button>

                <button class="choice ${e.shape==='ellipse'?'active':''}"
                    data-shape="ellipse">
                    楕円
                </button>

            </div>

        </div>`;
    }


    html+=`
    <div class="panel-section">

        <div class="panel-title">
            表示時間
        </div>

        <div class="field">
            <label>開始</label>
            <input id="propStart"
                type="number"
                min="0"
                step=".1"
                value="${e.start}">
        </div>

        <div class="field">
            <label>終了</label>
            <input id="propEnd"
                type="number"
                min="0"
                step=".1"
                value="${e.end}">
        </div>

    </div>


    <div class="panel-section">

        <div class="panel-title">
            色
        </div>

        <div class="color-row">

            ${[
                '#60a5fa',
                '#22c55e',
                '#f59e0b',
                '#ef4444',
                '#c084fc',
                '#14b8a6',
                '#f97316',
                '#e879f9'
            ].map(c=>`
                <button
                    class="color ${e.color===c?'active':''}"
                    data-color="${c}"
                    style="background:${c}">
                </button>
            `).join('')}

        </div>

    </div>


    <div class="panel-section">

        <button class="btn danger"
            id="deleteElement"
            style="width:100%">
            この要素を削除
        </button>

    </div>
    `;

    panelBody.innerHTML=html;


    $('propStart').onchange=()=>{
        e.start=clamp(
            Number($('propStart').value),
            0,
            Math.max(0,state.duration-.05)
        );

        if(e.end<=e.start){
            e.end=Math.min(
                state.duration,
                e.start+.1
            );
        }

        renderAll();
    };


    $('propEnd').onchange=()=>{
        e.end=clamp(
            Number($('propEnd').value),
            e.start+.05,
            state.duration
        );

        renderAll();
    };


    if(e.type==='text'){

        $('propText').oninput=()=>{
            e.text=$('propText').value;
            renderVideoElements();
        };

        $('propFont').onchange=()=>{
            e.fontSize=clamp(
                Number($('propFont').value),
                8,
                100
            );

            renderVideoElements();
        };

        panelBody
            .querySelectorAll('[data-weight]')
            .forEach(b=>{
                b.onclick=()=>{
                    e.fontWeight=b.dataset.weight;
                    renderPanel();
                    renderVideoElements();
                };
            });
    }


    if(e.type==='box'){

        panelBody
            .querySelectorAll('[data-shape]')
            .forEach(b=>{
                b.onclick=()=>{
                    e.shape=b.dataset.shape;
                    renderPanel();
                    renderVideoElements();
                };
            });
    }


    panelBody
        .querySelectorAll('[data-color]')
        .forEach(b=>{
            b.onclick=()=>{
                e.color=b.dataset.color;
                renderPanel();
                renderVideoElements();
                renderConnections();
            };
        });


    $('deleteElement').onclick=()=>{
        state.elements=
            state.elements.filter(
                x=>x.id!==e.id
            );

        state.connections=
            state.connections.filter(
                c=>c.from!==e.id &&
                   c.to!==e.id
            );

        state.selectedElement=null;

        renderAll();
    };
}


function renderConnectionPanel(){

    const c=selectedConnection();

    if(!c) return;

    panelTitle.textContent='接続線';

    panelBody.innerHTML=`

        <div class="panel-section">

            <div class="panel-title">
                線の種類
            </div>

            <div class="button-grid">

                <button class="choice ${c.style==='solid'?'active':''}"
                    data-line="solid">
                    実線
                </button>

                <button class="choice ${c.style==='dashed'?'active':''}"
                    data-line="dashed">
                    破線
                </button>

                <button class="choice ${c.style==='dotted'?'active':''}"
                    data-line="dotted">
                    点線
                </button>

            </div>

        </div>


        <div class="panel-section">

            <div class="panel-title">
                線の太さ
            </div>

            <div class="button-grid">

                <button class="choice ${c.width===2?'active':''}"
                    data-width="2">
                    細い
                </button>

                <button class="choice ${c.width===3?'active':''}"
                    data-width="3">
                    標準
                </button>

                <button class="choice ${c.width===5?'active':''}"
                    data-width="5">
                    太い
                </button>

            </div>

        </div>


        <div class="panel-section">

            <div class="panel-title">
                接続位置
            </div>

            <div class="field">

                <label>始点</label>

                <select id="fromPos">
                    ${anchorOptions(c.fromPos)}
                </select>

            </div>

            <div class="field">

                <label>終点</label>

                <select id="toPos">
                    ${anchorOptions(c.toPos)}
                </select>

            </div>

            <div style="
                color:#94a3b8;
                font-size:11px;
                line-height:1.7;
                margin-top:5px">

                動画上の接続線端点をドラッグしても
                接続位置を変更できます。

            </div>

        </div>


        <div class="panel-section">

            <div class="panel-title">
                色
            </div>

            <div class="color-row">

                ${[
                    '#60a5fa',
                    '#22c55e',
                    '#f59e0b',
                    '#ef4444',
                    '#c084fc',
                    '#14b8a6',
                    '#f97316'
                ].map(color=>`
                    <button
                        class="color ${c.color===color?'active':''}"
                        data-line-color="${color}"
                        style="background:${color}">
                    </button>
                `).join('')}

            </div>

        </div>


        <div class="panel-section">

            <button class="btn danger"
                id="deleteConnection"
                style="width:100%">
                接続を解除
            </button>

        </div>
    `;


    panelBody
        .querySelectorAll('[data-line]')
        .forEach(b=>{
            b.onclick=()=>{
                c.style=b.dataset.line;
                renderAll();
            };
        });


    panelBody
        .querySelectorAll('[data-width]')
        .forEach(b=>{
            b.onclick=()=>{
                c.width=Number(b.dataset.width);
                renderAll();
            };
        });


    $('fromPos').onchange=()=>{
        c.fromPos=$('fromPos').value;
        renderConnections();
        renderPanel();
    };


    $('toPos').onchange=()=>{
        c.toPos=$('toPos').value;
        renderConnections();
        renderPanel();
    };


    panelBody
        .querySelectorAll('[data-line-color]')
        .forEach(b=>{
            b.onclick=()=>{
                c.color=b.dataset.lineColor;
                renderAll();
            };
        });


    $('deleteConnection').onclick=()=>{

        state.connections=
            state.connections.filter(
                x=>x.id!==c.id
            );

        state.selectedConnection=null;

        renderAll();
    };
}


function anchorOptions(active){

    const names={
        tl:'左上',
        tc:'上',
        tr:'右上',
        ml:'左',
        mc:'中央',
        mr:'右',
        bl:'左下',
        bc:'下',
        br:'右下'
    };

    return Object.entries(names)
        .map(([key,label])=>
            `<option value="${key}"
                ${active===key?'selected':''}>
                ${label}
            </option>`
        ).join('');
}


function escapeHtml(value){

    return String(value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}


/*
 * -----------------------------
 * タイムライン
 * -----------------------------
 */

function renderTimeline(){

    tracks.innerHTML='';
    axis.innerHTML='';

    if(!state.duration){
        updatePlayhead();
        return;
    }

    const width=axis.clientWidth || 600;

    const step=
        state.duration/Math.max(1,width/80);

    const steps=[
        .1,.25,.5,1,2,5,10,15,30,60
    ];

    const actual=
        steps.find(x=>x>=step) || 60;


    for(
        let t=0;
        t<=state.duration+.001;
        t+=actual
    ){

        const tick=document.createElement('div');

        tick.className='tick';

        tick.style.left=
            (t/state.duration*100)+'%';

        tick.textContent=timeText(t);

        axis.appendChild(tick);
    }


    [
        ['text','テキスト'],
        ['box','強調枠'],
        ['skip','スキップ']
    ].forEach(([type,label])=>{

        const row=document.createElement('div');

        row.className='track';


        const labelNode=document.createElement('div');

        labelNode.className='track-label';

        labelNode.textContent=label;


        const lane=document.createElement('div');

        lane.className='track-lane';


        const grid=document.createElement('div');

        grid.className='track-grid';

        lane.appendChild(grid);


        state.elements
            .filter(e=>e.type===type)
            .forEach(e=>{

                const bar=document.createElement('div');

                bar.className='element-bar';

                if(state.selectedElement===e.id){
                    bar.classList.add('selected');
                }

                bar.style.left=
                    (e.start/state.duration*100)+'%';

                bar.style.width=
                    ((e.end-e.start)/state.duration*100)+'%';

                bar.style.color=e.color;

                bar.style.background=
                    rgba(e.color,.22);

                const labelText=document.createElement('span');

                labelText.className='bar-label';

                labelText.textContent=
                    e.name+' '+
                    timeText(e.start)+
                    '～'+
                    timeText(e.end);

                bar.appendChild(labelText);


                const left=document.createElement('span');

                left.className='handle left';

                left.dataset.edge='left';


                const right=document.createElement('span');

                right.className='handle right';

                right.dataset.edge='right';


                bar.append(left,right);


                bar.onpointerdown=ev=>{
                    ev.stopPropagation();

                    if(ev.target.classList.contains('handle')){
                        beginTimelineDrag(
                            ev,
                            e,
                            ev.target.dataset.edge
                        );
                    }else{
                        selectElement(e.id);
                    }
                };


                lane.appendChild(bar);
            });


        row.append(labelNode,lane);

        tracks.appendChild(row);
    }


    tracks.onclick=ev=>{
        if(ev.target===tracks){
            clearSelection();
        }
    };


    axis.onclick=ev=>{
        const rect=axis.getBoundingClientRect();

        const ratio=
            clamp(
                (ev.clientX-rect.left)/rect.width,
                0,
                1
            );

        video.currentTime=
            ratio*state.duration;
    };
}


function beginTimelineDrag(ev,e,edge){

    ev.preventDefault();

    const lane=ev.currentTarget.parentElement;

    state.drag={
        type:'timeline',
        id:e.id,
        edge:edge,
        x:ev.clientX,
        width:lane.getBoundingClientRect().width,
        start:e.start,
        end:e.end
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


function moveTimelineDrag(ev){

    const d=state.drag;

    if(!d || d.type!=='timeline') return;

    const e=state.elements.find(
        x=>x.id===d.id
    );

    if(!e) return;

    const dt=
        (ev.clientX-d.x)/
        d.width*
        state.duration;

    if(d.edge==='left'){

        e.start=clamp(
            d.start+dt,
            0,
            d.end-.05
        );

    }else{

        e.end=clamp(
            d.end+dt,
            d.start+.05,
            state.duration
        );
    }

    renderAll();
}


function endTimelineDrag(){

    state.drag=null;

    window.removeEventListener(
        'pointermove',
        moveTimelineDrag
    );
}


/*
 * -----------------------------
 * 再生位置
 * -----------------------------
 */

function updatePlayhead(){

    if(!state.duration){
        playhead.style.display='none';
        return;
    }

    playhead.style.display='block';

    playhead.style.left=
        `calc(110px + ${
            state.currentTime/state.duration*
            Math.max(
                0,
                timeline.clientWidth-110
            )
        }px)`;

    playheadLabel.textContent=
        timeText(state.currentTime);

    $('readout').textContent=
        timeText(state.currentTime)+
        ' / '+
        timeText(state.duration);
}


/*
 * -----------------------------
 * 右クリック追加
 * -----------------------------
 */

videoWrap.oncontextmenu=ev=>{

    ev.preventDefault();

    if(!state.duration) return;

    const rect=videoWrap.getBoundingClientRect();

    const x=clamp(
        (ev.clientX-rect.left)/
        rect.width*100,
        0,
        90
    );

    const y=clamp(
        (ev.clientY-rect.top)/
        rect.height*100,
        0,
        90
    );

    const type=prompt(
        '追加する要素を入力してください\n\n1: テキスト\n2: 強調枠\n3: スキップ',
        '2'
    );

    if(type==='1'){
        addElement('text',x,y);
    }else if(type==='2'){
        addElement('box',x,y);
    }else if(type==='3'){
        addElement('skip',x,y);
    }
};


/*
 * 動画外をクリックしたら選択解除。
 */
videoWrap.addEventListener('pointerdown',ev=>{

    if(
        ev.target===video ||
        ev.target===videoWrap
    ){
        clearSelection();
    }
});


/*
 * 全体描画
 */
function renderAll(){

    renderTimeline();
    renderVideoElements();
    renderConnections();
    renderPanel();
    updatePlayhead();
}


window.addEventListener(
    'resize',
    ()=>{
        renderVideoElements();
        renderConnections();
        renderTimeline();
        updatePlayhead();
    }
);


renderAll();

</script>

</body>
</html>

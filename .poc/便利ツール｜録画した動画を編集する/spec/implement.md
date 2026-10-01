<?php
declare(strict_types=1);

const DATA_FILE = __DIR__ . '/projects.json';

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function readProjects(): array {
    if (!is_file(DATA_FILE)) return [];
    $v = json_decode(file_get_contents(DATA_FILE) ?: '{}', true);
    return is_array($v) ? $v : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $data = json_decode($_POST['data'] ?? '', true);

        if (!is_array($data)) {
            jsonResponse(['ok'=>false,'error'=>'データが不正です'],400);
        }

        $data['savedAt'] = date('c');
        $all = readProjects();
        $name = trim((string)($data['name'] ?? 'プロジェクト'));
        if ($name === '') $name = 'プロジェクト';

        $all[$name] = $data;

        if (file_put_contents(
            DATA_FILE,
            json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        ) === false) {
            jsonResponse(['ok'=>false,'error'=>'保存できません'],500);
        }

        jsonResponse(['ok'=>true]);
    }

    if ($action === 'load') {
        jsonResponse(['ok'=>true,'projects'=>readProjects()]);
    }

    if ($action === 'delete') {
        $all = readProjects();
        unset($all[(string)($_POST['name'] ?? '')]);

        file_put_contents(
            DATA_FILE,
            json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        jsonResponse(['ok'=>true]);
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画注釈編集</title>

<style>
*{box-sizing:border-box}
html,body{
    margin:0;
    height:100%;
    overflow:hidden;
    background:#09111d;
    color:#e5e7eb;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif
}
button,input,select,textarea{font:inherit}
button{cursor:pointer}

.app{height:100%;display:flex;flex-direction:column}

.top{
    height:50px;
    display:flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
    background:#070d17;
    border-bottom:1px solid #334155
}
.brand{font-weight:700;margin-right:10px}
.btn{
    padding:6px 10px;
    border:1px solid #40516a;
    border-radius:5px;
    background:#243247;
    color:#e5e7eb
}
.btn:hover{background:#304158}
.primary{background:#2563eb;border-color:#3b82f6}
.danger{background:#7f1d1d}
.status{margin-left:auto;color:#94a3b8;font-size:12px}
.file{display:none}

.main{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column
}

.video-area{
    flex:1;
    min-height:250px;
    background:#05090f;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    padding:12px
}

.stage{
    position:relative;
    max-width:100%;
    max-height:100%;
    line-height:0;
    overflow:hidden
}
.stage.connecting{cursor:crosshair}
.stage.zooming{cursor:crosshair}

video{
    display:block;
    max-width:100%;
    max-height:100%;
    background:#000
}

.overlay{
    position:absolute;
    inset:0
}

.svg{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    pointer-events:none;
    overflow:visible
}

.empty{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    color:#64748b;
    line-height:1.8
}
.empty strong{font-size:18px;color:#94a3b8}

.el{
    position:absolute;
    min-width:20px;
    min-height:16px;
    cursor:move;
    user-select:none;
    touch-action:none
}
.el.selected{z-index:20}

.body{
    width:100%;
    height:100%
}

.el.selected .body{
    outline:2px solid #60a5fa;
    outline-offset:2px
}

.comment{
    display:flex;
    align-items:center;
    justify-content:center;
    padding:5px 8px;
    background:#000b;
    border:1px solid currentColor;
    border-radius:4px;
    text-align:center;
    line-height:1.2;
    white-space:pre-wrap;
    overflow:hidden
}

.highlight{
    border:3px solid currentColor
}

.circle{border-radius:50%}
.ellipse{border-radius:50%}

.skip{
    display:flex;
    align-items:center;
    justify-content:center;
    border:2px dashed #fb923c;
    background:#f9731626;
    color:#fdba74;
    font-weight:700;
    font-size:12px
}

.zoom{
    border:2px solid #facc15;
    background:#111827;
    overflow:hidden
}

.zoom-label{
    position:absolute;
    left:4px;
    top:4px;
    z-index:5;
    padding:2px 5px;
    background:#000c;
    color:#facc15;
    border-radius:3px;
    font-size:11px;
    line-height:1.2
}

.zoom-content{
    position:absolute;
    inset:0;
    overflow:hidden
}

.zoom-content video{
    position:absolute;
    max-width:none;
    max-height:none
}

.handle{
    display:none;
    position:absolute;
    width:9px;
    height:9px;
    background:white;
    border:1px solid #2563eb;
    border-radius:2px;
    z-index:30
}

.el.selected .handle{display:block}

.nw{left:-5px;top:-5px;cursor:nwse-resize}
.n{left:50%;top:-5px;transform:translateX(-50%);cursor:ns-resize}
.ne{right:-5px;top:-5px;cursor:nesw-resize}
.e{right:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}
.se{right:-5px;bottom:-5px;cursor:nwse-resize}
.s{left:50%;bottom:-5px;transform:translateX(-50%);cursor:ns-resize}
.sw{left:-5px;bottom:-5px;cursor:nesw-resize}
.w{left:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}

.bottom{
    height:330px;
    display:flex;
    border-top:1px solid #334155;
    background:#0e1724
}

.timeline{
    flex:1;
    min-width:0;
    display:flex;
    flex-direction:column
}

.toolbar{
    height:43px;
    display:flex;
    align-items:center;
    gap:7px;
    padding:5px 8px;
    border-bottom:1px solid #334155
}

.readout{
    font-variant-numeric:tabular-nums;
    color:#dbeafe
}

.selection{
    color:#94a3b8;
    font-size:12px
}

.scale{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:5px;
    color:#94a3b8;
    font-size:12px
}

.scroll{
    flex:1;
    overflow:auto
}

.content{
    position:relative;
    min-width:700px
}

.axis{
    height:32px;
    display:flex;
    position:sticky;
    top:0;
    z-index:50;
    background:#131d29;
    border-bottom:1px solid #334155
}

.label{
    width:100px;
    min-width:100px;
    padding:8px;
    border-right:1px solid #334155;
    font-size:12px
}

.axistrack{
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
    height:52px;
    display:flex;
    border-bottom:1px solid #263445
}

.tracklabel{
    width:100px;
    min-width:100px;
    padding:17px 8px;
    background:#111b27;
    border-right:1px solid #334155;
    font-size:12px
}

.lane{
    position:relative;
    flex:1
}

.bar{
    position:absolute;
    top:8px;
    height:36px;
    min-width:8px;
    border:1px solid currentColor;
    border-radius:5px;
    display:flex;
    align-items:center;
    cursor:grab;
    touch-action:none
}

.bar.selected{
    box-shadow:0 0 0 2px #60a5fa
}

.bar span{
    padding:0 6px;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
    font-size:11px;
    pointer-events:none
}

.bh{
    position:absolute;
    top:0;
    width:9px;
    height:100%;
    z-index:2
}

.bh.left{left:-5px;cursor:ew-resize}
.bh.right{right:-5px;cursor:ew-resize}

.playhead{
    position:absolute;
    top:32px;
    bottom:0;
    width:3px;
    background:#ef4444;
    z-index:80
}

.playhead:before{
    content:"";
    position:absolute;
    top:0;
    left:-5px;
    width:13px;
    height:13px;
    background:#ef4444;
    clip-path:polygon(0 0,100% 0,50% 100%)
}

.phlabel{
    position:absolute;
    top:12px;
    left:6px;
    background:#ef4444;
    color:white;
    font-size:10px;
    padding:2px 4px;
    border-radius:3px;
    white-space:nowrap
}

.ctx{
    position:fixed;
    z-index:1000;
    display:none;
    min-width:210px;
    background:#172235;
    border:1px solid #475569;
    border-radius:6px;
    padding:4px;
    box-shadow:0 12px 30px #0008
}

.ctx button{
    display:block;
    width:100%;
    padding:8px;
    border:0;
    background:none;
    color:#e5e7eb;
    text-align:left;
    border-radius:4px
}

.ctx button:hover{background:#293a52}
.ctx button:disabled{opacity:.4;cursor:not-allowed}
.ctx hr{border:0;border-top:1px solid #334155}

.modalbg{
    position:fixed;
    inset:0;
    z-index:2000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:#000a
}

.modal{
    width:min(560px,100%);
    max-height:90vh;
    background:#182231;
    border:1px solid #475569;
    border-radius:8px;
    display:flex;
    flex-direction:column
}

.head,.foot{
    padding:11px 14px;
    border-bottom:1px solid #334155
}

.head{
    display:flex;
    justify-content:space-between
}

.foot{
    border-top:1px solid #334155;
    border-bottom:0;
    display:flex;
    justify-content:flex-end;
    gap:7px
}

.bodyform{
    padding:14px;
    display:grid;
    gap:10px;
    overflow:auto
}

.field{
    display:grid;
    gap:4px
}

.field label{
    font-size:12px;
    color:#94a3b8
}

.field input,
.field textarea,
.field select{
    width:100%;
    padding:7px;
    background:#0f1722;
    border:1px solid #40516a;
    color:#e5e7eb;
    border-radius:5px
}

.field textarea{min-height:80px}

.two{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:9px
}

.hidden{display:none!important}

.colors{
    display:flex;
    flex-wrap:wrap;
    gap:5px
}

.color{
    width:28px;
    height:25px;
    border:2px solid transparent;
    border-radius:4px
}

.color.active{border-color:white}

.item{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px;
    border-bottom:1px solid #334155
}

.item>div:first-child{flex:1}
.small{font-size:11px;color:#94a3b8}

.toast{
    position:fixed;
    right:15px;
    bottom:15px;
    z-index:3000;
    background:#1e293b;
    border:1px solid #475569;
    border-radius:6px;
    padding:9px 13px;
    display:none
}

.zoom-mask{
    position:absolute;
    border:2px dashed #facc15;
    background:#facc1515;
    pointer-events:none;
    z-index:100
}

.connection{
    fill:none;
    pointer-events:stroke;
    cursor:pointer
}

.connection.selected{
    filter:drop-shadow(0 0 3px #60a5fa)
}

.connection-end{
    fill:#facc15;
    stroke:#713f12;
    stroke-width:1.5;
    cursor:crosshair;
    pointer-events:auto
}

.connection-end:hover{
    fill:#fff
}

.connection-start{
    fill:#60a5fa;
    stroke:#1e3a8a;
    stroke-width:1.5
}

@media(max-width:700px){
    .brand{display:none}
    .bottom{height:300px}
}
</style>
</head>

<body>
<div class="app">

<header class="top">
    <div class="brand">動画注釈編集</div>

    <button class="btn primary" id="videoOpen">動画を開く</button>
    <input class="file" id="videoFile" type="file" accept="video/*">

    <button class="btn" id="newBtn">新規</button>
    <button class="btn" id="saveBtn" disabled>保存</button>
    <button class="btn" id="openBtn">開く</button>

    <span class="status" id="status">動画を開いてください</span>
</header>

<main class="main">

<section class="video-area">

<div class="stage" id="stage">

    <video id="video" playsinline preload="metadata"></video>

    <div class="overlay" id="overlay">
        <svg class="svg" id="svg"></svg>
    </div>

    <div class="empty" id="empty">
        <strong>動画を開いてください</strong>
        <span>動画上で右クリックすると要素を追加できます</span>
    </div>

</div>

</section>

<section class="bottom">

<div class="timeline">

<div class="toolbar">
    <button class="btn" id="play" disabled>▶</button>
    <button class="btn" id="stop" disabled>■</button>

    <span class="readout" id="readout">
        00:00.000 / 00:00.000
    </span>

    <span class="selection" id="selection"></span>

    <div class="scale">
        時間表示
        <input id="scale" type="range" min="1" max="5" step=".1" value="1">
        <span id="scaleText">1.0×</span>
    </div>
</div>

<div class="scroll" id="scroll">

<div class="content" id="content">

<div class="axis">
    <div class="label">時間</div>
    <div class="axistrack" id="axis"></div>
</div>

<div id="tracks"></div>

<div class="playhead" id="playhead">
    <span class="phlabel" id="phlabel"></span>
</div>

</div>
</div>
</div>
</section>
</main>
</div>

<!-- 右クリックメニュー -->

<div class="ctx" id="ctx">

    <button data-add="comment">＋ コメント</button>
    <button data-add="highlight">＋ 強調枠</button>
    <button data-add="skip">＋ スキップ</button>
    <button data-add="zoom">＋ 拡大</button>

    <hr>

    <button id="connect">この要素から接続</button>
    <button id="editConnection">接続線を編集</button>
    <button id="disconnect">接続を解除</button>

    <hr>

    <button id="editElement">要素を編集</button>
    <button id="deleteElement">削除</button>

</div>

<!-- 要素編集 -->

<div class="modalbg" id="elementModal">

<div class="modal">

<div class="head">
    <strong id="elementTitle">要素編集</strong>
    <button class="btn" data-close="elementModal">閉じる</button>
</div>

<div class="bodyform">

<div class="field">
    <label>名前</label>
    <input id="eName">
</div>

<div class="field" id="textBox">
    <label>コメント</label>
    <textarea id="eText"></textarea>
</div>

<div class="two">

<div class="field">
    <label>開始</label>
    <input id="eStart" type="number" step=".001" min="0">
</div>

<div class="field">
    <label>終了</label>
    <input id="eEnd" type="number" step=".001" min="0">
</div>

</div>

<div class="two">

<div class="field">
    <label>左位置 (%)</label>
    <input id="eX" type="number" step=".1">
</div>

<div class="field">
    <label>上位置 (%)</label>
    <input id="eY" type="number" step=".1">
</div>

</div>

<div class="two">

<div class="field">
    <label>幅 (%)</label>
    <input id="eW" type="number" step=".1">
</div>

<div class="field">
    <label>高さ (%)</label>
    <input id="eH" type="number" step=".1">
</div>

</div>

<div class="field" id="fontBox">
    <label>文字サイズ</label>
    <input id="eFont" type="number" min="8" max="100">
</div>

<div class="field" id="shapeBox">
    <label>形</label>
    <select id="eShape">
        <option value="square">四角</option>
        <option value="circle">丸</option>
        <option value="ellipse">楕円</option>
    </select>
</div>

<div class="field">
    <label>色</label>
    <div class="colors" id="colors"></div>
</div>

</div>

<div class="foot">
    <button class="btn danger" id="deleteModal">削除</button>
    <button class="btn" data-close="elementModal">キャンセル</button>
    <button class="btn primary" id="saveElement">保存</button>
</div>

</div>
</div>

<!-- 接続線編集 -->

<div class="modalbg" id="connectionModal">

<div class="modal">

<div class="head">
    <strong>接続線を編集</strong>
    <button class="btn" data-close="connectionModal">閉じる</button>
</div>

<div class="bodyform">

<div class="two">

<div class="field">
    <label>接続元</label>
    <select id="fromPoint">
        <option value="top">上</option>
        <option value="right">右</option>
        <option value="bottom">下</option>
        <option value="left">左</option>
    </select>
</div>

<div class="field">
    <label>接続先</label>
    <select id="toPoint">
        <option value="top">上</option>
        <option value="right">右</option>
        <option value="bottom">下</option>
        <option value="left">左</option>
    </select>
</div>

</div>

<div class="two">

<div class="field">
    <label>線の太さ</label>
    <input id="lineWidth" type="number" min="1" max="12" step=".5">
</div>

<div class="field">
    <label>線種</label>
    <select id="lineStyle">
        <option value="solid">実線</option>
        <option value="dashed">破線</option>
        <option value="dotted">点線</option>
    </select>
</div>

</div>

<div class="two">

<div class="field">
    <label>矢印</label>
    <select id="arrow">
        <option value="none">なし</option>
        <option value="end">終点</option>
        <option value="both">両端</option>
        <option value="start">始点</option>
    </select>
</div>

<div class="field">
    <label>色</label>
    <input id="lineColor" type="color">
</div>

</div>

</div>

<div class="foot">
    <button class="btn danger" id="deleteConnection">接続解除</button>
    <button class="btn" data-close="connectionModal">キャンセル</button>
    <button class="btn primary" id="saveConnection">保存</button>
</div>

</div>
</div>

<!-- 保存プロジェクト -->

<div class="modalbg" id="storageModal">

<div class="modal">

<div class="head">
    <strong>保存したプロジェクト</strong>
    <button class="btn" data-close="storageModal">閉じる</button>
</div>

<div class="bodyform" id="storage"></div>

</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $=id=>document.getElementById(id);

const V={
    video:$('video'),
    stage:$('stage'),
    overlay:$('overlay'),
    svg:$('svg'),
    empty:$('empty'),
    tracks:$('tracks'),
    axis:$('axis'),
    content:$('content'),
    scroll:$('scroll'),
    playhead:$('playhead'),
    phlabel:$('phlabel')
};

const A={
    project:null,
    duration:0,
    time:0,
    url:'',
    selected:new Set(),
    selectedConnection:null,
    editId:null,
    editConnection:null,
    drag:null,
    connectFrom:null,
    zoomSelecting:null,
    colors:[
        '#60a5fa','#22c55e','#f59e0b','#ef4444',
        '#c084fc','#14b8a6','#f97316','#e879f9',
        '#38bdf8','#a3e635','#fb7185','#facc15'
    ]
};

const uid=()=>crypto.randomUUID?.()||
    Date.now()+'-'+Math.random().toString(36).slice(2);

const el=id=>A.project?.elements.find(x=>x.id===id);
const conn=id=>A.project?.connections.find(x=>x.id===id);

const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));

const fmt=n=>{
    n=Math.max(0,+n||0);

    return `${String(Math.floor(n/60)).padStart(2,'0')}:`+
           `${String(Math.floor(n%60)).padStart(2,'0')}.`+
           `${String(Math.floor(n%1*1000)).padStart(3,'0')}`;
};

const colorType=t=>({
    comment:'#60a5fa',
    highlight:'#22c55e',
    skip:'#f97316',
    zoom:'#facc15'
}[t]);

function msg(s,error=false){

    $('status').textContent=s;
    $('toast').textContent=s;
    $('toast').style.display='block';
    $('toast').style.borderColor=error?'#ef4444':'#475569';

    clearTimeout(msg.t);

    msg.t=setTimeout(()=>{
        $('toast').style.display='none';
    },2200);
}

const dirty=()=>{
    $('status').textContent='変更あり';
};

const clean=()=>{
    $('status').textContent=A.project?
        '編集中：'+A.project.name:
        '動画を開いてください';
};

function blank(name='新規プロジェクト'){
    return {
        version:2,
        name,
        videoName:'',
        duration:A.duration,
        elements:[],
        connections:[]
    };
}

/* -------------------------
   時間軸
------------------------- */

function range(){

    const z=+$('scale').value;
    const span=A.duration/z;

    const start=clamp(
        A.time-span/2,
        0,
        Math.max(0,A.duration-span)
    );

    return {
        start,
        end:start+span
    };
}

function timeX(t){

    const r=range();

    return r.end===r.start?
        0:
        clamp(
            (t-r.start)/(r.end-r.start)*100,
            0,
            100
        );
}

function xTime(x){

    const r=$('axis').getBoundingClientRect();
    const q=range();

    return q.start+
        (x-r.left)/r.width*
        (q.end-q.start);
}

function renderAxis(){

    $('axis').innerHTML='';

    if(!A.duration)return;

    const q=range();

    const steps=[
        .1,.25,.5,1,2,5,10,15,30,
        60,120,300
    ];

    const step=
        steps.find(x=>x>=((q.end-q.start)/8))||600;

    for(
        let t=Math.ceil(q.start/step)*step;
        t<=q.end;
        t+=step
    ){

        const n=document.createElement('span');

        n.className='tick';
        n.style.left=timeX(t)+'%';
        n.textContent=fmt(t);

        $('axis').appendChild(n);
    }
}

function renderTimeline(){

    $('tracks').innerHTML='';

    if(!A.duration){
        renderAxis();
        return;
    }

    [
        ['comment','コメント'],
        ['highlight','強調枠'],
        ['skip','スキップ'],
        ['zoom','拡大']
    ].forEach(([type,title])=>{

        const row=document.createElement('div');
        row.className='track';

        const label=document.createElement('div');
        label.className='tracklabel';
        label.textContent=title;

        const lane=document.createElement('div');
        lane.className='lane';

        A.project.elements
            .filter(e=>e.type===type)
            .forEach(e=>{

                const b=document.createElement('div');

                b.className='bar';
                b.dataset.id=e.id;

                b.style.left=timeX(e.start)+'%';

                b.style.width=
                    Math.max(
                        .4,
                        timeX(e.end)-timeX(e.start)
                    )+'%';

                b.style.color=e.color;
                b.style.background=e.color+'33';

                if(A.selected.has(e.id))
                    b.classList.add('selected');

                const s=document.createElement('span');

                s.textContent=e.name;

                b.appendChild(s);

                ['left','right'].forEach(side=>{

                    const h=document.createElement('i');

                    h.className='bh '+side;
                    h.dataset.edge=side;

                    b.appendChild(h);
                });

                b.onpointerdown=ev=>{
                    barDown(ev,e.id);
                };

                b.ondblclick=ev=>{
                    ev.stopPropagation();
                    openElement(e.id);
                };

                b.oncontextmenu=ev=>{
                    ev.preventDefault();
                    select(e.id,false);
                    context(ev.clientX,ev.clientY,e.id);
                };

                lane.appendChild(b);
            });

        row.append(label,lane);

        $('tracks').appendChild(row);
    });

    renderAxis();
}

/* -------------------------
   動画上の要素
------------------------- */

function renderOverlay(){

    V.overlay
        .querySelectorAll('.el')
        .forEach(n=>n.remove());

    if(!A.project)return;

    A.project.elements
        .filter(e=>A.time>=e.start&&A.time<e.end)
        .forEach(e=>{

            const n=document.createElement('div');

            n.className='el';
            n.dataset.id=e.id;

            Object.assign(n.style,{
                left:e.x+'%',
                top:e.y+'%',
                width:e.w+'%',
                height:e.h+'%',
                color:e.color
            });

            if(A.selected.has(e.id))
                n.classList.add('selected');

            const b=document.createElement('div');

            b.className='body';

            if(e.type==='comment'){

                b.classList.add('comment');

                b.textContent=e.text||e.name;
                b.style.fontSize=(e.fontSize||28)+'px';

            }else if(e.type==='highlight'){

                b.className=
                    'body highlight '+(e.shape||'square');

            }else if(e.type==='skip'){

                b.className='body skip';
                b.textContent='スキップ';

            }else if(e.type==='zoom'){

                b.className='body zoom';

                const z=document.createElement('div');
                z.className='zoom-content';

                const zv=document.createElement('video');

                zv.muted=true;
                zv.playsInline=true;
                zv.src=V.video.src;
                zv.currentTime=A.time;

                const sw=Math.max(.1,e.sourceW||20);
                const sh=Math.max(.1,e.sourceH||20);

                zv.style.width=(10000/sw)+'%';
                zv.style.height=(10000/sh)+'%';

                zv.style.left=(-(e.sourceX||0)*100/sw)+'%';
                zv.style.top=(-(e.sourceY||0)*100/sh)+'%';

                z.appendChild(zv);
                b.appendChild(z);

                const label=document.createElement('span');

                label.className='zoom-label';
                label.textContent='拡大';

                b.appendChild(label);
            }

            n.appendChild(b);

            if(A.selected.has(e.id)){

                [
                    'nw','n','ne','e',
                    'se','s','sw','w'
                ].forEach(p=>{

                    const h=document.createElement('i');

                    h.className='handle '+p;
                    h.dataset.resize=p;

                    n.appendChild(h);
                });
            }

            n.onpointerdown=ev=>{
                elementDown(ev,e.id);
            };

            n.ondblclick=ev=>{
                ev.stopPropagation();
                openElement(e.id);
            };

            n.oncontextmenu=ev=>{
                ev.preventDefault();
                select(e.id,false);
                context(ev.clientX,ev.clientY,e.id);
            };

            V.overlay.appendChild(n);
        });

    renderConnections();
}

/* -------------------------
   接続線
------------------------- */

function point(rect,p,base){

    const x=rect.left-base.left;
    const y=rect.top-base.top;
    const w=rect.width;
    const h=rect.height;

    return {
        top:[x+w/2,y],
        right:[x+w,y+h/2],
        bottom:[x+w/2,y+h],
        left:[x,y+h/2]
    }[p];
}

function renderConnections(){

    V.svg.innerHTML='';

    if(!A.project)return;

    const base=V.overlay.getBoundingClientRect();

    A.project.connections.forEach(c=>{

        const a=V.overlay.querySelector(
            `[data-id="${CSS.escape(c.from)}"]`
        );

        const b=V.overlay.querySelector(
            `[data-id="${CSS.escape(c.to)}"]`
        );

        if(!a||!b)return;

        const p1=point(
            a.getBoundingClientRect(),
            c.fromPoint,
            base
        );

        const p2=point(
            b.getBoundingClientRect(),
            c.toPoint,
            base
        );

        const dx=p2[0]-p1[0];
        const dy=p2[1]-p1[1];

        const path=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        path.classList.add('connection');

        if(A.selectedConnection===c.id)
            path.classList.add('selected');

        path.setAttribute(
            'd',
            `M${p1[0]} ${p1[1]}
             L${p1[0]+dx*.45} ${p1[1]+dy*.15}
             L${p2[0]-dx*.45} ${p2[1]-dy*.15}
             L${p2[0]} ${p2[1]}`
        );

        path.style.stroke=c.color;
        path.style.strokeWidth=c.width;

        path.style.strokeDasharray=
            c.style==='dashed'?'8 5':
            c.style==='dotted'?'2 5':
            'none';

        path.dataset.connection=c.id;

        if(c.arrow==='end'||c.arrow==='both')
            path.setAttribute('marker-end',
                `url(#arrow-end-${c.id})`);

        if(c.arrow==='start'||c.arrow==='both')
            path.setAttribute('marker-start',
                `url(#arrow-start-${c.id})`);

        path.onclick=ev=>{

            ev.stopPropagation();

            A.selectedConnection=c.id;
            A.selected.clear();

            render();
        };

        path.oncontextmenu=ev=>{

            ev.preventDefault();
            ev.stopPropagation();

            A.selectedConnection=c.id;
            A.selected.clear();

            context(ev.clientX,ev.clientY);
        };

        V.svg.appendChild(path);

        /* 矢印 */

        if(c.arrow!=='none'){

            const defs=document.createElementNS(
                'http://www.w3.org/2000/svg',
                'defs'
            );

            ['end','start'].forEach(dir=>{

                if(
                    (dir==='end' &&
                    (c.arrow==='end'||c.arrow==='both')) ||
                    (dir==='start' &&
                    (c.arrow==='start'||c.arrow==='both'))
                ){

                    const m=document.createElementNS(
                        'http://www.w3.org/2000/svg',
                        'marker'
                    );

                    m.id=`arrow-${dir}-${c.id}`;
                    m.markerWidth=8;
                    m.markerHeight=8;
                    m.refX=dir==='end'?7:1;
                    m.refY=4;
                    m.orient='auto';
                    m.markerUnits='strokeWidth';

                    const q=document.createElementNS(
                        'http://www.w3.org/2000/svg',
                        'path'
                    );

                    q.setAttribute(
                        'd',
                        'M0,0 L8,4 L0,8 z'
                    );

                    q.setAttribute('fill',c.color);

                    m.appendChild(q);
                    defs.appendChild(m);
                }
            });

            V.svg.appendChild(defs);
        }

        /* 接続端 */

        const start=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );

        start.classList.add('connection-start');
        start.setAttribute('cx',p1[0]);
        start.setAttribute('cy',p1[1]);
        start.setAttribute('r',5);

        const end=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );

        end.classList.add('connection-end');
        end.setAttribute('cx',p2[0]);
        end.setAttribute('cy',p2[1]);
        end.setAttribute('r',7);

        end.dataset.connection=c.id;
        end.dataset.endpoint='to';

        end.onpointerdown=ev=>{
            connectionEndpointDown(ev,c.id,'to');
        };

        start.dataset.connection=c.id;
        start.dataset.endpoint='from';

        start.onpointerdown=ev=>{
            connectionEndpointDown(ev,c.id,'from');
        };

        V.svg.appendChild(start);
        V.svg.appendChild(end);
    });
}

/* -------------------------
   接続端ドラッグ
------------------------- */

function connectionEndpointDown(ev,id,side){

    ev.preventDefault();
    ev.stopPropagation();

    const c=conn(id);
    if(!c)return;

    A.drag={
        connection:id,
        side
    };

    V.stage.classList.add('connecting');

    addEventListener(
        'pointermove',
        connectionEndpointMove
    );

    addEventListener(
        'pointerup',
        connectionEndpointUp,
        {once:true}
    );
}

function connectionEndpointMove(ev){
    /* ドラッグ中はカーソル表示のみ */
}

function connectionEndpointUp(ev){

    removeEventListener(
        'pointermove',
        connectionEndpointMove
    );

    const d=A.drag;

    V.stage.classList.remove('connecting');

    if(!d?.connection)return;

    const c=conn(d.connection);

    if(!c){
        A.drag=null;
        return;
    }

    const target=document.elementFromPoint(
        ev.clientX,
        ev.clientY
    )?.closest('.el');

    if(target){

        const targetId=target.dataset.id;
        const targetElement=el(targetId);

        if(targetElement){

            const r=target.getBoundingClientRect();

            const x=ev.clientX-r.left;
            const y=ev.clientY-r.top;

            const side=nearestSide(
                x,
                y,
                r.width,
                r.height
            );

            if(d.side==='to'){
                c.to=targetId;
                c.toPoint=side;
            }else{
                c.from=targetId;
                c.fromPoint=side;
            }

            dirty();
            render();
            msg('接続先を変更しました');
        }
    }

    A.drag=null;
}

function nearestSide(x,y,w,h){

    const distances={
        top:Math.abs(y),
        right:Math.abs(w-x),
        bottom:Math.abs(h-y),
        left:Math.abs(x)
    };

    return Object.keys(distances)
        .sort((a,b)=>distances[a]-distances[b])[0];
}

/* -------------------------
   描画
------------------------- */

function render(){

    renderTimeline();
    renderOverlay();

    $('playhead').style.display=
        A.duration?'block':'none';

    $('playhead').style.left=
        `calc(100px + (100% - 100px)*${timeX(A.time)/100})`;

    $('phlabel').textContent=fmt(A.time);

    $('readout').textContent=
        `${fmt(A.time)} / ${fmt(A.duration)}`;

    $('selection').textContent=
        A.selected.size?
            `${A.selected.size}個選択`:
            A.selectedConnection?
                '接続線を選択':'';
}

/* -------------------------
   選択
------------------------- */

function select(id,multi=false){

    A.selectedConnection=null;

    if(!multi)
        A.selected.clear();

    if(multi&&A.selected.has(id))
        A.selected.delete(id);
    else
        A.selected.add(id);

    render();
}

/* -------------------------
   右クリック
------------------------- */

function context(x,y,id=null){

    if(id)
        A.selected=new Set([id]);

    $('ctx').style.display='block';

    $('ctx').style.left=
        Math.min(x,innerWidth-225)+'px';

    $('ctx').style.top=
        Math.min(y,innerHeight-300)+'px';

    $('connect').disabled=
        !A.selected.size;

    $('editConnection').disabled=
        !A.selectedConnection;

    $('disconnect').disabled=
        !A.selectedConnection &&
        !A.selected.size;
}

function closeContext(){
    $('ctx').style.display='none';
}

/* -------------------------
   要素追加
------------------------- */

function add(type){

    if(!A.project||!A.duration)
        return msg(
            '先に動画を開いてください',
            true
        );

    const e={
        id:uid(),
        type,

        name:
            type==='comment'?'コメント':
            type==='highlight'?'強調枠':
            type==='skip'?'スキップ':
            '拡大',

        text:type==='comment'?'コメント':'',

        start:A.time,
        end:Math.min(A.duration,A.time+3),

        x:A.addPoint?.x??10,
        y:A.addPoint?.y??10,

        w:type==='comment'?30:
          type==='zoom'?30:25,

        h:type==='comment'?15:
          type==='zoom'?25:20,

        color:colorType(type),
        fontSize:28,
        shape:'square',

        sourceX:20,
        sourceY:20,
        sourceW:20,
        sourceH:20
    };

    A.project.elements.push(e);
    A.selected=new Set([e.id]);

    dirty();
    closeContext();
    render();

    if(type==='zoom')
        startZoomArea(e.id);
    else
        openElement(e.id);

    A.addPoint=null;
}

/* -------------------------
   拡大範囲指定
------------------------- */

function startZoomArea(id){

    const e=el(id);
    if(!e)return;

    msg('動画上で拡大する範囲をドラッグしてください');

    V.stage.classList.add('zooming');

    A.zoomSelecting={
        id,
        sx:0,
        sy:0,
        mask:null
    };

    const down=ev=>{

        if(ev.button!==0)return;

        const r=V.video.getBoundingClientRect();

        if(
            ev.clientX<r.left||
            ev.clientX>r.right||
            ev.clientY<r.top||
            ev.clientY>r.bottom
        )return;

        A.zoomSelecting.sx=ev.clientX;
        A.zoomSelecting.sy=ev.clientY;

        const mask=document.createElement('div');

        mask.className='zoom-mask';

        mask.style.left=
            (ev.clientX-r.left)+'px';

        mask.style.top=
            (ev.clientY-r.top)+'px';

        mask.style.width='0';
        mask.style.height='0';

        V.overlay.appendChild(mask);

        A.zoomSelecting.mask=mask;

        addEventListener(
            'pointermove',
            move
        );

        addEventListener(
            'pointerup',
            up,
            {once:true}
        );
    };

    const move=ev=>{

        const z=A.zoomSelecting;
        if(!z?.mask)return;

        const r=V.video.getBoundingClientRect();

        const x1=z.sx-r.left;
        const y1=z.sy-r.top;
        const x2=ev.clientX-r.left;
        const y2=ev.clientY-r.top;

        const x=Math.min(x1,x2);
        const y=Math.min(y1,y2);
        const w=Math.abs(x2-x1);
        const h=Math.abs(y2-y1);

        Object.assign(
            z.mask.style,
            {
                left:x+'px',
                top:y+'px',
                width:w+'px',
                height:h+'px'
            }
        );
    };

    const up=ev=>{

        removeEventListener(
            'pointermove',
            move
        );

        const z=A.zoomSelecting;

        if(!z)return;

        const r=V.video.getBoundingClientRect();

        let x1=z.sx-r.left;
        let y1=z.sy-r.top;
        let x2=ev.clientX-r.left;
        let y2=ev.clientY-r.top;

        let x=Math.min(x1,x2);
        let y=Math.min(y1,y2);

        let w=Math.abs(x2-x1);
        let h=Math.abs(y2-y1);

        x=clamp(x,0,r.width);
        y=clamp(y,0,r.height);

        w=Math.min(w,r.width-x);
        h=Math.min(h,r.height-y);

        if(w<10||h<10){

            z.mask?.remove();
            A.zoomSelecting=null;
            V.stage.classList.remove('zooming');

            return msg(
                '拡大範囲が小さすぎます',
                true
            );
        }

        e.sourceX=x/r.width*100;
        e.sourceY=y/r.height*100;
        e.sourceW=w/r.width*100;
        e.sourceH=h/r.height*100;

        z.mask?.remove();

        A.zoomSelecting=null;

        V.stage.classList.remove('zooming');

        dirty();
        render();
        openElement(id);
    };

    V.overlay.addEventListener(
        'pointerdown',
        down,
        {once:true}
    );
}

/* -------------------------
   要素ドラッグ
------------------------- */

function elementDown(ev,id){

    if(ev.button!==0)return;

    if(A.zoomSelecting)return;

    ev.preventDefault();
    ev.stopPropagation();

    const e=el(id);
    const resize=ev.target.dataset.resize||'';

    select(
        id,
        ev.ctrlKey||ev.metaKey
    );

    if(A.connectFrom){

        createConnection(
            A.connectFrom,
            id
        );

        A.connectFrom=null;

        V.stage.classList.remove('connecting');

        return;
    }

    const r=V.overlay.getBoundingClientRect();

    A.drag={
        id,
        resize,
        sx:ev.clientX,
        sy:ev.clientY,
        x:e.x,
        y:e.y,
        w:e.w,
        h:e.h,
        rw:r.width,
        rh:r.height
    };

    addEventListener(
        'pointermove',
        elementMove
    );

    addEventListener(
        'pointerup',
        endDrag,
        {once:true}
    );
}

function elementMove(ev){

    const d=A.drag;
    const e=el(d?.id);

    if(!d||!e)return;

    const dx=
        (ev.clientX-d.sx)/d.rw*100;

    const dy=
        (ev.clientY-d.sy)/d.rh*100;

    const min=.7;

    if(!d.resize){

        e.x=clamp(
            d.x+dx,
            0,
            100-d.w
        );

        e.y=clamp(
            d.y+dy,
            0,
            100-d.h
        );

    }else{

        let x=d.x;
        let y=d.y;
        let w=d.w;
        let h=d.h;

        if(d.resize.includes('w')){

            x=clamp(
                d.x+dx,
                0,
                d.x+d.w-min
            );

            w=d.w-(x-d.x);
        }

        if(d.resize.includes('e')){

            w=clamp(
                d.w+dx,
                min,
                100-d.x
            );
        }

        if(d.resize.includes('n')){

            y=clamp(
                d.y+dy,
                0,
                d.y+d.h-min
            );

            h=d.h-(y-d.y);
        }

        if(d.resize.includes('s')){

            h=clamp(
                d.h+dy,
                min,
                100-d.y
            );
        }

        Object.assign(e,{x,y,w,h});
    }

    dirty();
    render();
}

/* -------------------------
   タイムラインドラッグ
------------------------- */

function barDown(ev,id){

    ev.preventDefault();
    ev.stopPropagation();

    select(
        id,
        ev.ctrlKey||ev.metaKey
    );

    const e=el(id);

    const h=ev.target.dataset.edge;

    const lane=
        ev.currentTarget.parentElement;

    const r=range();

    const rect=
        lane.getBoundingClientRect();

    A.drag={
        id,
        h,
        sx:ev.clientX,
        start:e.start,
        end:e.end,
        width:rect.width,
        span:r.end-r.start
    };

    addEventListener(
        'pointermove',
        barMove
    );

    addEventListener(
        'pointerup',
        endDrag,
        {once:true}
    );
}

function barMove(ev){

    const d=A.drag;
    const e=el(d?.id);

    if(!d||!e)return;

    const dt=
        (ev.clientX-d.sx)/
        d.width*
        d.span;

    if(!d.h){

        const len=d.end-d.start;

        e.start=clamp(
            d.start+dt,
            0,
            A.duration-len
        );

        e.end=e.start+len;

    }else if(d.h==='left'){

        e.start=clamp(
            d.start+dt,
            0,
            e.end-.05
        );

    }else{

        e.end=clamp(
            d.end+dt,
            e.start+.05,
            A.duration
        );
    }

    dirty();
    render();
}

function endDrag(){

    A.drag=null;

    removeEventListener(
        'pointermove',
        elementMove
    );

    removeEventListener(
        'pointermove',
        barMove
    );
}

/* -------------------------
   要素編集
------------------------- */

function openElement(id){

    const e=el(id);
    if(!e)return;

    A.editId=id;

    $('elementTitle').textContent=
        e.type==='comment'?'コメント編集':
        e.type==='highlight'?'強調枠編集':
        e.type==='skip'?'スキップ編集':
        '拡大編集';

    $('eName').value=e.name;
    $('eText').value=e.text||'';

    $('eStart').value=e.start;
    $('eEnd').value=e.end;

    $('eX').value=e.x;
    $('eY').value=e.y;
    $('eW').value=e.w;
    $('eH').value=e.h;

    $('eFont').value=e.fontSize||28;
    $('eShape').value=e.shape||'square';

    $('textBox').classList.toggle(
        'hidden',
        e.type!=='comment'
    );

    $('fontBox').classList.toggle(
        'hidden',
        e.type!=='comment'
    );

    $('shapeBox').classList.toggle(
        'hidden',
        e.type!=='highlight'
    );

    $('colors').innerHTML='';

    A.colors.forEach(c=>{

        const b=document.createElement('button');

        b.className=
            'color'+
            (c===e.color?' active':'');

        b.style.background=c;
        b.dataset.color=c;

        b.onclick=()=>{

            document
                .querySelectorAll('.color')
                .forEach(x=>
                    x.classList.remove('active')
                );

            b.classList.add('active');
        };

        $('colors').appendChild(b);
    });

    $('elementModal').style.display='flex';
}

$('saveElement').onclick=()=>{

    const e=el(A.editId);

    const s=+$('eStart').value;
    const en=+$('eEnd').value;

    if(
        !e||
        s<0||
        en<=s||
        en>A.duration
    ){
        return msg(
            '時間範囲が不正です',
            true
        );
    }

    Object.assign(e,{
        name:$('eName').value.trim()||'要素',
        start:s,
        end:en,
        x:clamp(+$('eX').value,0,99),
        y:clamp(+$('eY').value,0,99),
        w:clamp(+$('eW').value,1,100),
        h:clamp(+$('eH').value,1,100),
        color:
            document.querySelector('.color.active')
                ?.dataset.color||
            e.color
    });

    if(e.type==='comment'){

        e.text=$('eText').value;

        e.fontSize=clamp(
            +$('eFont').value||28,
            8,
            100
        );
    }

    if(e.type==='highlight')
        e.shape=$('eShape').value;

    $('elementModal').style.display='none';

    dirty();
    render();
};

/* -------------------------
   接続開始
------------------------- */

function startConnection(id){

    if(!el(id))return;

    A.connectFrom=id;

    A.selected=new Set([id]);

    V.stage.classList.add('connecting');

    closeContext();

    msg(
        '接続先の要素をクリックしてください'
    );
}

/* -------------------------
   接続作成
------------------------- */

function createConnection(fromId,toId){

    if(!fromId||!toId||fromId===toId)
        return;

    const a=el(fromId);
    const b=el(toId);

    if(!a||!b)return;

    const c={
        id:uid(),
        from:a.id,
        to:b.id,
        fromPoint:'right',
        toPoint:'left',
        color:'#facc15',
        width:2.5,
        style:'solid',
        arrow:'end'
    };

    A.project.connections.push(c);

    A.selectedConnection=c.id;
    A.selected.clear();

    dirty();
    render();

    msg('接続しました');
}

/* -------------------------
   接続線編集
------------------------- */

function openConnection(){

    const c=conn(A.selectedConnection);

    if(!c)
        return msg(
            '接続線を選択してください',
            true
        );

    A.editConnection=c.id;

    $('fromPoint').value=c.fromPoint;
    $('toPoint').value=c.toPoint;

    $('lineWidth').value=c.width;
    $('lineStyle').value=c.style;

    $('arrow').value=c.arrow;
    $('lineColor').value=c.color;

    $('connectionModal').style.display='flex';
}

$('saveConnection').onclick=()=>{

    const c=conn(A.editConnection);

    if(!c)return;

    Object.assign(c,{
        fromPoint:$('fromPoint').value,
        toPoint:$('toPoint').value,
        width:clamp(
            +$('lineWidth').value,
            1,
            12
        ),
        style:$('lineStyle').value,
        arrow:$('arrow').value,
        color:$('lineColor').value
    });

    $('connectionModal').style.display='none';

    dirty();
    render();
};

$('deleteConnection').onclick=()=>{

    if(!A.editConnection)return;

    A.project.connections=
        A.project.connections.filter(
            c=>c.id!==A.editConnection
        );

    A.selectedConnection=null;
    A.editConnection=null;

    $('connectionModal').style.display='none';

    dirty();
    render();
};

/* -------------------------
   削除
------------------------- */

function deleteSelected(){

    if(!A.project)return;

    const ids=new Set(A.selected);

    A.project.elements=
        A.project.elements.filter(
            e=>!ids.has(e.id)
        );

    A.project.connections=
        A.project.connections.filter(
            c=>!ids.has(c.from)&&!ids.has(c.to)
        );

    A.selected.clear();
    A.selectedConnection=null;

    $('elementModal').style.display='none';

    dirty();
    render();
}

/* -------------------------
   メニュー
------------------------- */

$('connect').onclick=()=>{

    if(A.selected.size!==1){

        closeContext();

        return msg(
            '接続元となる要素を1つ選択してください',
            true
        );
    }

    startConnection(
        [...A.selected][0]
    );
};

$('editConnection').onclick=()=>{
    closeContext();
    openConnection();
};

$('disconnect').onclick=()=>{

    if(A.selectedConnection){

        A.project.connections=
            A.project.connections.filter(
                c=>c.id!==A.selectedConnection
            );

        A.selectedConnection=null;

    }else{

        const ids=A.selected;

        A.project.connections=
            A.project.connections.filter(
                c=>!ids.has(c.from)&&
                   !ids.has(c.to)
            );
    }

    dirty();
    render();
    closeContext();
};

$('editElement').onclick=()=>{

    closeContext();

    if(A.selected.size===1)
        openElement([...A.selected][0]);
};

$('deleteElement').onclick=()=>{

    closeContext();
    deleteSelected();
};

document
    .querySelectorAll('[data-add]')
    .forEach(b=>{

        b.onclick=()=>{
            add(b.dataset.add);
        };
    });

/* -------------------------
   動画上右クリック
------------------------- */

$('stage').oncontextmenu=ev=>{

    ev.preventDefault();

    if(!A.project)return;

    const r=V.video.getBoundingClientRect();

    if(
        ev.clientX>=r.left&&
        ev.clientX<=r.right&&
        ev.clientY>=r.top&&
        ev.clientY<=r.bottom
    ){

        A.addPoint={
            x:(ev.clientX-r.left)/r.width*100,
            y:(ev.clientY-r.top)/r.height*100
        };

        context(
            ev.clientX,
            ev.clientY
        );
    }
};

/* -------------------------
   タイムライン
------------------------- */

$('content').onpointerdown=ev=>{

    if(ev.target.closest('.bar'))return;

    if(
        ev.target.closest('.lane')||
        ev.target.closest('.axistrack')
    ){

        A.time=clamp(
            xTime(ev.clientX),
            0,
            A.duration
        );

        V.video.currentTime=A.time;

        render();
    }
};

$('playhead').onpointerdown=ev=>{

    ev.preventDefault();

    const f=x=>{

        A.time=clamp(
            xTime(x),
            0,
            A.duration
        );

        V.video.currentTime=A.time;

        render();
    };

    f(ev.clientX);

    const move=e=>f(e.clientX);

    const up=()=>{

        removeEventListener(
            'pointermove',
            move
        );

        removeEventListener(
            'pointerup',
            up
        );
    };

    addEventListener(
        'pointermove',
        move
    );

    addEventListener(
        'pointerup',
        up
    );
};

/* -------------------------
   動画
------------------------- */

$('videoOpen').onclick=()=>{
    $('videoFile').click();
};

$('videoFile').onchange=ev=>{

    const f=ev.target.files?.[0];

    if(!f?.type.startsWith('video/'))
        return msg(
            '動画ファイルを選択してください',
            true
        );

    if(A.url)
        URL.revokeObjectURL(A.url);

    A.url=URL.createObjectURL(f);

    A.project=blank(f.name);
    A.project.videoName=f.name;

    A.time=0;
    A.selected.clear();
    A.selectedConnection=null;

    V.video.src=A.url;
    V.video.load();
};

V.video.onloadedmetadata=()=>{

    A.duration=V.video.duration;

    if(A.project)
        A.project.duration=A.duration;

    V.empty.style.display='none';

    $('play').disabled=false;
    $('stop').disabled=false;
    $('saveBtn').disabled=false;

    clean();
    render();
};

V.video.ontimeupdate=()=>{

    if(!V.video.paused){

        A.time=V.video.currentTime;

        render();
    }
};

V.video.onended=()=>{

    A.time=A.duration;

    render();
};

$('play').onclick=async()=>{

    if(V.video.paused)
        await V.video.play();
    else
        V.video.pause();
};

$('stop').onclick=()=>{

    V.video.pause();

    A.time=0;
    V.video.currentTime=0;

    render();
};

$('scale').oninput=()=>{

    $('scaleText').textContent=
        (+$('scale').value).toFixed(1)+'×';

    render();
};

/* -------------------------
   新規
------------------------- */

$('newBtn').onclick=()=>{

    if(A.url)
        URL.revokeObjectURL(A.url);

    A.project=null;
    A.duration=0;
    A.time=0;
    A.selected.clear();
    A.selectedConnection=null;
    A.url='';

    V.video.removeAttribute('src');
    V.video.load();

    V.empty.style.display='flex';

    $('play').disabled=true;
    $('stop').disabled=true;
    $('saveBtn').disabled=true;

    render();
    clean();
};

/* -------------------------
   保存
------------------------- */

$('saveBtn').onclick=async()=>{

    if(!A.project)return;

    const fd=new FormData();

    fd.append('action','save');
    fd.append(
        'data',
        JSON.stringify(A.project)
    );

    try{

        const r=await fetch('',{
            method:'POST',
            body:fd
        });

        const j=await r.json();

        if(!j.ok)
            throw Error(j.error);

        clean();
        msg('保存しました');

    }catch(e){

        msg(
            '保存できません：'+e.message,
            true
        );
    }
};

/* -------------------------
   開く
------------------------- */

$('openBtn').onclick=async()=>{

    try{

        const fd=new FormData();

        fd.append('action','load');

        const r=await fetch('',{
            method:'POST',
            body:fd
        });

        const j=await r.json();

        showStorage(j.projects||{});

    }catch(e){

        msg(
            '保存データを取得できません',
            true
        );
    }
};

function escapeHtml(s){

    return String(s).replace(
        /[&<>"']/g,
        c=>({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            '"':'&quot;',
            "'":'&#39;'
        }[c])
    );
}

function showStorage(data){

    const box=$('storage');

    box.innerHTML='';

    const names=Object.keys(data);

    if(!names.length){

        box.innerHTML=
            '<div style="padding:20px;color:#94a3b8">'+
            '保存されたプロジェクトはありません'+
            '</div>';
    }

    names.forEach(name=>{

        const p=data[name];

        const row=document.createElement('div');

        row.className='item';

        const info=document.createElement('div');

        info.innerHTML=
            `<strong>${escapeHtml(name)}</strong>`+
            `<div class="small">`+
            `${escapeHtml(p.videoName||'動画未設定')} / `+
            `${(p.elements||[]).length}要素 / `+
            `${(p.connections||[]).length}接続`+
            `</div>`;

        const load=document.createElement('button');

        load.className='btn';
        load.textContent='開く';

        load.onclick=()=>{
            loadProject(p);
        };

        const del=document.createElement('button');

        del.className='btn danger';
        del.textContent='削除';

        del.onclick=()=>{
            deleteProject(name);
        };

        row.append(info,load,del);

        box.appendChild(row);
    });

    $('storageModal').style.display='flex';
}

function loadProject(p){

    A.project=p;

    if(!Array.isArray(A.project.elements))
        A.project.elements=[];

    if(!Array.isArray(A.project.connections))
        A.project.connections=[];

    A.duration=+p.duration||0;
    A.time=0;

    A.selected.clear();
    A.selectedConnection=null;

    if(V.video.duration>0)
        A.duration=V.video.duration;

    $('storageModal').style.display='none';

    $('saveBtn').disabled=!A.duration;
    $('play').disabled=!A.duration;
    $('stop').disabled=!A.duration;

    V.empty.style.display=
        A.duration?'none':'flex';

    clean();
    render();
}

async function deleteProject(name){

    if(!confirm(
        'このプロジェクトを削除しますか？'
    ))return;

    const fd=new FormData();

    fd.append('action','delete');
    fd.append('name',name);

    await fetch('',{
        method:'POST',
        body:fd
    });

    $('openBtn').click();
}

/* -------------------------
   共通操作
------------------------- */

document
    .querySelectorAll('[data-close]')
    .forEach(b=>{

        b.onclick=()=>{
            $(b.dataset.close).style.display='none';
        };
    });

document.addEventListener('click',ev=>{

    if(!ev.target.closest('.ctx'))
        closeContext();
});

document.addEventListener('keydown',ev=>{

    if(
        ['INPUT','TEXTAREA','SELECT']
        .includes(
            document.activeElement?.tagName
        )
    )return;

    if(ev.key==='Delete'||
       ev.key==='Backspace'){

        deleteSelected();
    }

    if(ev.key===' '){

        ev.preventDefault();

        $('play').click();
    }

    if(ev.key==='Escape'){

        A.connectFrom=null;
        A.zoomSelecting=null;

        V.stage.classList.remove(
            'connecting',
            'zooming'
        );

        closeContext();

        document
            .querySelectorAll('.modalbg')
            .forEach(x=>{
                x.style.display='none';
            });
    }
});

addEventListener('resize',render);

V.scroll.addEventListener(
    'scroll',
    renderConnections
);

render();
</script>

</body>
</html>
<?php
declare(strict_types=1);

const APP_VERSION = '6.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'version' => APP_VERSION], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'export-json') {
        $project = $_POST['project'] ?? '';
        $decoded = json_decode($project, true);

        if ($project === '' || !is_array($decoded)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'invalid json'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="video-editor-project.json"');
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
    --bg:#09111d;--panel:#111c2b;--panel2:#172438;--line:#334155;
    --text:#e5e7eb;--muted:#94a3b8;--blue:#2563eb;--red:#ef4444;
    --yellow:#fbbf24;--label:105px;--track:58px
}
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:var(--bg);color:var(--text);
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
.hidden{display:none!important}

.app{height:100vh;display:flex;flex-direction:column}
.topbar{
    height:52px;flex:none;display:flex;align-items:center;gap:7px;padding:7px 10px;
    background:#070e18;border-bottom:1px solid var(--line)
}
.brand{font-weight:700;margin-right:8px;white-space:nowrap}
.btn{
    border:1px solid #40516a;border-radius:6px;background:#243247;color:var(--text);
    padding:7px 11px
}
.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:var(--blue);border-color:#3b82f6}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}
.status{margin-left:auto;color:var(--muted);font-size:12px;white-space:nowrap}
.file-input{display:none}

.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{
    min-height:260px;flex:1;display:flex;align-items:center;justify-content:center;
    padding:12px;background:#03070c;overflow:hidden
}
.video-wrap{
    position:relative;width:min(1100px,100%);height:100%;
    display:flex;align-items:center;justify-content:center
}
#video{display:block;max-width:100%;max-height:100%;background:#000}
.video-overlay{position:absolute;inset:0;pointer-events:none}
.overlay-element{
    position:absolute;min-width:30px;min-height:20px;pointer-events:auto;
    user-select:none;touch-action:none
}
.overlay-element.selected{outline:2px solid #60a5fa;outline-offset:1px}
.overlay-element.active{filter:drop-shadow(0 0 7px #fbbf24)}
.overlay-comment{
    width:100%;height:100%;display:flex;align-items:center;justify-content:center;
    padding:5px;overflow:hidden;white-space:pre-wrap;text-align:center;
    text-shadow:0 2px 4px #000;font-weight:700
}
.overlay-shape{
    width:100%;height:100%;border:3px solid currentColor;
    background:color-mix(in srgb,currentColor 10%,transparent)
}
.shape-circle{border-radius:50%}
.shape-ellipse{border-radius:50%}
.shape-rect{border-radius:4px}
.overlay-skip{
    width:100%;height:100%;display:flex;align-items:center;justify-content:center;
    border:2px dashed currentColor;color:#fb923c;background:#f973161c;
    font-size:12px;font-weight:700
}

.connection-layer{
    position:absolute;inset:0;width:100%;height:100%;
    pointer-events:none;overflow:visible
}
.connection{
    fill:none;stroke:var(--yellow);stroke-width:2.5;
    stroke-dasharray:7 5;opacity:.95
}
.connection-dot{
    fill:var(--yellow);stroke:#111827;stroke-width:2
}

.empty-video{
    position:absolute;text-align:center;color:#64748b;line-height:1.8;
    pointer-events:none
}
.empty-video strong{display:block;color:#94a3b8;font-size:18px}

.bottom{
    height:330px;flex:none;display:flex;min-height:0;
    border-top:1px solid var(--line);background:#0d1724
}
.timeline-panel{min-width:0;flex:1;display:flex;flex-direction:column}
.timeline-toolbar{
    height:43px;flex:none;display:flex;align-items:center;gap:7px;padding:5px 8px;
    border-bottom:1px solid var(--line)
}
.time-readout{min-width:120px;font-variant-numeric:tabular-nums;color:#dbeafe}
.selection-hint{font-size:11px;color:#64748b}
.zoom-control{
    margin-left:auto;display:flex;align-items:center;gap:6px;
    color:var(--muted);font-size:12px
}
.zoom-control input{width:120px}

.timeline-scroll{position:relative;flex:1;min-height:0;overflow:auto}
.timeline-content{position:relative;width:100%;min-width:650px}
.axis-row{
    position:sticky;top:0;z-index:30;height:34px;display:flex;
    background:#131d29;border-bottom:1px solid var(--line)
}
.axis-label{
    width:var(--label);min-width:var(--label);display:flex;align-items:center;
    padding-left:8px;background:#131d29;border-right:1px solid var(--line);
    font-size:12px;position:sticky;left:0;z-index:40
}
.axis-track{position:relative;height:100%;flex:1;min-width:0}
.tick{
    position:absolute;top:0;height:100%;border-left:1px solid #334155;
    padding:5px 0 0 3px;font-size:10px;color:#64748b;
    white-space:nowrap;pointer-events:none
}

.track-row{height:var(--track);display:flex;border-bottom:1px solid #263445}
.track-label{
    width:var(--label);min-width:var(--label);display:flex;align-items:center;
    gap:6px;padding:0 8px;background:#111b27;border-right:1px solid var(--line);
    font-size:12px;position:sticky;left:0;z-index:20
}
.type-dot{width:8px;height:8px;border-radius:50%;flex:none}
.track-lane{position:relative;flex:1;min-width:0}
.track-grid{
    position:absolute;inset:0;pointer-events:none;
    background-image:linear-gradient(to right,rgba(148,163,184,.08) 1px,transparent 1px)
}
.element-bar{
    position:absolute;top:9px;height:39px;min-width:15px;
    border:1px solid currentColor;border-radius:5px;display:flex;
    align-items:center;overflow:visible;cursor:grab;user-select:none;
    touch-action:none;z-index:5
}
.element-bar:active{cursor:grabbing}
.element-bar.selected{box-shadow:0 0 0 2px #60a5fa}
.element-bar .label{
    padding:0 8px;overflow:hidden;text-overflow:ellipsis;
    white-space:nowrap;font-size:11px;pointer-events:none
}
.element-bar .handle{
    position:absolute;top:0;width:10px;height:100%;z-index:8
}
.element-bar .handle.left{left:-5px;cursor:ew-resize}
.element-bar .handle.right{right:-5px;cursor:ew-resize}

.playhead{
    position:absolute;top:34px;bottom:0;width:3px;z-index:80;
    background:var(--red);box-shadow:0 0 5px #ef444499;
    cursor:ew-resize;touch-action:none
}
.playhead:before{
    content:"";position:absolute;top:-1px;left:-5px;width:13px;height:13px;
    background:var(--red);clip-path:polygon(0 0,100% 0,50% 100%)
}
.playhead-label{
    position:absolute;top:12px;left:6px;padding:2px 4px;
    background:var(--red);color:#fff;font-size:10px;white-space:nowrap;border-radius:3px
}

.context-menu{
    position:fixed;display:none;z-index:2000;min-width:230px;padding:5px;
    background:#172235;border:1px solid #475569;border-radius:6px;
    box-shadow:0 12px 30px #0008
}
.context-menu button{
    display:block;width:100%;padding:8px;border:0;background:transparent;
    color:var(--text);text-align:left;border-radius:4px
}
.context-menu button:hover{background:#293a52}

.modal-backdrop{
    position:fixed;inset:0;z-index:3000;display:none;align-items:center;
    justify-content:center;padding:20px;background:#000a
}
.modal{
    width:min(560px,100%);background:#182231;
    border:1px solid #475569;border-radius:8px;overflow:hidden
}
.modal-head,.modal-foot{padding:12px 15px;border-color:var(--line)}
.modal-head{display:flex;justify-content:space-between;border-bottom:1px solid var(--line)}
.modal-body{padding:15px;display:grid;gap:11px;max-height:75vh;overflow:auto}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--line)}
.field{display:grid;gap:5px}
.field label{font-size:12px;color:var(--muted)}
.field input,.field select,.field textarea{
    width:100%;padding:7px;background:#0f1722;color:var(--text);
    border:1px solid #40516a;border-radius:5px
}
.field textarea{min-height:100px;resize:vertical}
.color-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:6px}
.color-choice{
    height:28px;border:2px solid transparent;border-radius:4px
}
.color-choice.active{border-color:#fff;box-shadow:0 0 0 1px #60a5fa}

.toast{
    position:fixed;right:15px;bottom:15px;z-index:5000;display:none;
    padding:10px 14px;background:#1e293b;border:1px solid #475569;
    border-radius:6px;box-shadow:0 10px 30px #0006
}

@media(max-width:700px){
    :root{--label:82px}
    .brand{display:none}
    .bottom{height:300px}
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
    <button class="btn" id="saveProject" disabled>保存</button>
    <button class="btn" id="loadProject">保存データ</button>
    <button class="btn" id="exportProject">書き出し</button>

    <label class="btn">
        プロジェクト読込
        <input class="file-input" id="importProject" type="file" accept=".json,application/json">
    </label>

    <span class="status" id="status">動画を読み込んでください</span>
</header>

<main class="main">

<section class="video-area">
    <div class="video-wrap" id="videoWrap">

        <video id="video" preload="metadata" playsinline></video>

        <!-- 接続線はここだけに描画する -->
        <svg class="connection-layer" id="connectionLayer"
             preserveAspectRatio="none"></svg>

        <div class="video-overlay" id="videoOverlay"></div>

        <div class="empty-video" id="emptyVideo">
            <strong>動画を読み込んでください</strong>
            動画上で右クリックすると要素を追加できます
        </div>

    </div>
</section>

<section class="bottom">
<section class="timeline-panel">

<div class="timeline-toolbar">
    <button class="btn small" id="play" disabled>▶</button>
    <button class="btn small" id="stop" disabled>■</button>

    <span class="time-readout" id="readout">00:00.000 / 00:00.000</span>
    <span class="selection-hint" id="selectionHint"></span>

    <div class="zoom-control">
        <span>時間スケール</span>
        <input id="zoom" type="range" min="1" max="5" step=".1" value="1">
        <span id="zoomValue">1.0×</span>
    </div>
</div>

<div class="timeline-scroll" id="timelineScroll">
<div class="timeline-content" id="timelineContent">

    <div class="axis-row">
        <div class="axis-label">時間</div>
        <div class="axis-track" id="axis"></div>
    </div>

    <div id="tracks"></div>

    <div class="playhead" id="playhead">
        <span class="playhead-label" id="playheadLabel">00:00.000</span>
    </div>

</div>
</div>

</section>
</section>
</main>
</div>

<!-- 右クリックメニュー -->
<div class="context-menu" id="contextMenu">
    <button data-action="add-comment">コメントを追加</button>
    <button data-action="add-box">強調枠を追加</button>
    <button data-action="add-skip">スキップを追加</button>
    <button data-action="edit">選択要素を編集</button>
    <button data-action="connect">コメントと強調枠を接続</button>
    <button data-action="disconnect">接続を解除</button>
    <button data-action="delete">削除</button>
</div>

<!-- 要素編集 -->
<div class="modal-backdrop" id="elementModal">
<div class="modal">

<div class="modal-head">
    <strong id="elementModalTitle">要素を編集</strong>
    <button class="btn small" id="elementClose">閉じる</button>
</div>

<div class="modal-body">

    <div class="field">
        <label>要素名</label>
        <input id="elementName">
    </div>

    <div class="field" id="commentTextField">
        <label>コメント</label>
        <textarea id="elementText"
                  placeholder="動画上に表示するコメントを入力してください"></textarea>
    </div>

    <div class="field hidden" id="shapeField">
        <label>強調枠の形</label>
        <select id="elementShape">
            <option value="rect">四角</option>
            <option value="circle">丸</option>
            <option value="ellipse">楕円</option>
        </select>
    </div>

    <div class="field">
        <label>開始時間（秒）</label>
        <input id="elementStart" type="number" min="0" step=".001">
    </div>

    <div class="field">
        <label>終了時間（秒）</label>
        <input id="elementEnd" type="number" min="0.001" step=".001">
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

    <div class="field" id="fontField">
        <label>文字サイズ</label>
        <input id="elementFontSize" type="number" min="8" max="100">
    </div>

    <div class="field" id="weightField">
        <label>文字太さ</label>
        <select id="elementFontWeight">
            <option value="400">標準</option>
            <option value="700">太字</option>
            <option value="900">極太</option>
        </select>
    </div>

    <div class="field">
        <label>色</label>
        <div class="color-grid" id="colorGrid"></div>
    </div>

</div>

<div class="modal-foot">
    <button class="btn" id="elementCancel">キャンセル</button>
    <button class="btn primary" id="elementSave">保存</button>
</div>

</div>
</div>

<!-- ローカル保存一覧 -->
<div class="modal-backdrop" id="storageModal">
<div class="modal">

<div class="modal-head">
    <strong>保存データ</strong>
    <button class="btn small" id="storageClose">閉じる</button>
</div>

<div class="modal-body" id="storageList"></div>

</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $ = id => document.getElementById(id);

const ui = {
    video:$('video'),
    wrap:$('videoWrap'),
    overlay:$('videoOverlay'),
    connection:$('connectionLayer'),
    empty:$('emptyVideo'),
    file:$('videoFile'),
    open:$('openVideo'),
    newBtn:$('newProject'),
    save:$('saveProject'),
    load:$('loadProject'),
    exportBtn:$('exportProject'),
    import:$('importProject'),
    status:$('status'),
    play:$('play'),
    stop:$('stop'),
    readout:$('readout'),
    hint:$('selectionHint'),
    zoom:$('zoom'),
    zoomValue:$('zoomValue'),
    scroll:$('timelineScroll'),
    content:$('timelineContent'),
    axis:$('axis'),
    tracks:$('tracks'),
    playhead:$('playhead'),
    playheadLabel:$('playheadLabel'),
    menu:$('contextMenu'),
    modal:$('elementModal'),
    storage:$('storageModal'),
    storageList:$('storageList')
};

const state = {
    project:null,
    duration:0,
    currentTime:0,
    videoUrl:'',
    videoFile:null,
    selected:new Set(),
    contextId:null,
    pendingXY:null,
    modalId:null,
    drag:null,
    dirty:false,
    colors:[
        '#60a5fa','#22c55e','#f59e0b','#ef4444',
        '#c084fc','#14b8a6','#f97316','#e879f9',
        '#38bdf8','#a3e635','#fb7185','#facc15','#ffffff'
    ]
};

const typeColor = {
    comment:'#60a5fa',
    box:'#22c55e',
    skip:'#f97316'
};

function uid(prefix='id'){
    return prefix+'-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8);
}

function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}

function formatTime(v){
    v=Math.max(0,Number(v)||0);
    return String(Math.floor(v/60)).padStart(2,'0')+':'+
        String(Math.floor(v%60)).padStart(2,'0')+'.'+
        String(Math.floor(v%1*1000)).padStart(3,'0');
}

function shortTime(v){
    return v<60 ? v.toFixed(v%1 ? 1 : 0)+'s' :
        String(Math.floor(v/60)).padStart(2,'0')+':'+
        String(Math.floor(v%60)).padStart(2,'0');
}

function getElement(id){
    return state.project?.elements.find(e=>e.id===id)||null;
}

function canEdit(){
    return !!state.project && state.duration>0;
}

function dirty(){
    state.dirty=true;
    ui.status.textContent='変更あり';
}

function clean(){
    state.dirty=false;
    ui.status.textContent=state.project ?
        '編集中：'+state.project.name :
        '動画を読み込んでください';
}

function toast(message,error=false){
    ui.status.textContent=message;

    const t=$('toast');
    t.textContent=message;
    t.style.display='block';
    t.style.borderColor=error?'#ef4444':'#475569';

    clearTimeout(toast.timer);
    toast.timer=setTimeout(()=>t.style.display='none',2200);
}

/* =========================
   プロジェクト
========================= */

function newProject(name='新規プロジェクト'){
    return {
        version:APP_VERSION,
        name,
        duration:state.duration,
        videoName:state.videoFile?.name||'video',
        elements:[]
    };
}

function normalizeProject(p){
    if(!p || typeof p!=='object') throw Error('プロジェクト形式が不正です');

    const duration=Number(p.duration)||state.duration;

    const elements=Array.isArray(p.elements) ? p.elements.map(e=>{
        const type=['comment','box','skip'].includes(e.type) ? e.type : 'comment';
        const start=clamp(Number(e.start)||0,0,duration);
        const end=clamp(Number(e.end)||start+3,0,duration);

        return {
            id:String(e.id||uid('el')),
            type,
            name:String(e.name||(
                type==='comment'?'コメント':
                type==='box'?'強調枠':'スキップ'
            )),
            text:String(e.text||''),
            shape:['rect','circle','ellipse'].includes(e.shape) ? e.shape : 'rect',
            start,
            end:Math.max(start+.05,Math.min(duration,end)),
            x:clamp(Number(e.x)||10,0,99),
            y:clamp(Number(e.y)||10,0,99),
            w:clamp(Number(e.w)||30,1,100),
            h:clamp(Number(e.h)||18,1,100),
            color:e.color||typeColor[type],
            fontSize:clamp(Number(e.fontSize)||28,8,100),
            fontWeight:String(e.fontWeight||'700')
        };
    }) : [];

    return {
        version:APP_VERSION,
        name:String(p.name||'プロジェクト'),
        duration,
        videoName:String(p.videoName||'video'),
        elements
    };
}

/* =========================
   タイムライン座標
========================= */

function visibleRange(){
    if(!state.duration)return {start:0,end:0};

    const zoom=Number(ui.zoom.value)||1;
    const span=state.duration/zoom;

    let start=state.currentTime-span/2;
    start=clamp(start,0,Math.max(0,state.duration-span));

    return {start,end:start+span};
}

function timePercent(t){
    const r=visibleRange();
    if(r.end<=r.start)return 0;
    return clamp((t-r.start)/(r.end-r.start),0,1)*100;
}

function clientTime(x){
    const rect=ui.axis.getBoundingClientRect();
    const r=visibleRange();

    if(!rect.width)return 0;

    return clamp(
        r.start+(x-rect.left)/rect.width*(r.end-r.start),
        0,state.duration
    );
}

function renderAxis(){
    ui.axis.innerHTML='';
    if(!state.duration)return;

    const r=visibleRange();
    const width=Math.max(1,ui.axis.clientWidth);
    const approx=(r.end-r.start)/Math.max(1,width/80);
    const steps=[.1,.25,.5,1,2,5,10,15,30,60,120,300];
    const step=steps.find(s=>s>=approx)||600;

    for(let t=Math.ceil(r.start/step)*step;t<=r.end+.001;t+=step){
        const tick=document.createElement('div');
        tick.className='tick';
        tick.style.left=((t-r.start)/(r.end-r.start)*100)+'%';
        tick.textContent=shortTime(t);
        ui.axis.appendChild(tick);
    }
}

/* =========================
   タイムライン
========================= */

function renderTimeline(){
    ui.tracks.innerHTML='';
    if(!state.project)return;

    const groups=[
        ['comment','コメント'],
        ['box','強調枠'],
        ['skip','スキップ']
    ];

    groups.forEach(([type,label])=>{
        const row=document.createElement('div');
        row.className='track-row';

        const title=document.createElement('div');
        title.className='track-label';

        const dot=document.createElement('span');
        dot.className='type-dot';
        dot.style.background=typeColor[type];

        title.append(dot,document.createTextNode(label));

        const lane=document.createElement('div');
        lane.className='track-lane';

        const grid=document.createElement('div');
        grid.className='track-grid';
        grid.style.backgroundSize=
            `${Math.max(10,100/state.duration)}% 100%`;

        lane.appendChild(grid);

        state.project.elements
            .filter(e=>e.type===type)
            .forEach(e=>lane.appendChild(createBar(e)));

        row.append(title,lane);
        ui.tracks.appendChild(row);
    });

    renderAxis();
}

function createBar(e){
    const bar=document.createElement('div');
    bar.className='element-bar';
    bar.dataset.id=e.id;

    if(state.selected.has(e.id))bar.classList.add('selected');

    bar.style.left=timePercent(e.start)+'%';
    bar.style.width=Math.max(.7,timePercent(e.end)-timePercent(e.start))+'%';
    bar.style.color=e.color;
    bar.style.background=hexAlpha(e.color,.18);

    const label=document.createElement('span');
    label.className='label';
    label.textContent=e.name;

    const left=document.createElement('span');
    left.className='handle left';
    left.dataset.edge='left';

    const right=document.createElement('span');
    right.className='handle right';
    right.dataset.edge='right';

    bar.append(label,left,right);

    bar.addEventListener('pointerdown',startDrag);

    bar.addEventListener('click',ev=>{
        ev.stopPropagation();
        select(e.id,ev.shiftKey);
    });

    bar.addEventListener('dblclick',ev=>{
        ev.stopPropagation();
        openElement(e.id);
    });

    bar.addEventListener('contextmenu',ev=>{
        ev.preventDefault();
        ev.stopPropagation();
        select(e.id,false);
        openMenu(ev.clientX,ev.clientY,e.id);
    });

    return bar;
}

function select(id,add=false){
    if(!getElement(id))return;

    if(!add)state.selected.clear();

    if(add && state.selected.has(id))state.selected.delete(id);
    else state.selected.add(id);

    ui.hint.textContent=state.selected.size ?
        `${state.selected.size}要素選択中` : '';

    renderAll();
}

/* =========================
   動画上の要素
========================= */

function isVisible(e){
    return state.currentTime>=e.start && state.currentTime<e.end;
}

function renderOverlay(){
    ui.overlay.innerHTML='';
    if(!canEdit())return;

    state.project.elements.filter(isVisible).forEach(e=>{
        const el=document.createElement('div');
        el.className='overlay-element';
        el.dataset.id=e.id;

        if(state.selected.has(e.id))el.classList.add('selected');

        el.style.left=e.x+'%';
        el.style.top=e.y+'%';
        el.style.width=e.w+'%';
        el.style.height=e.h+'%';
        el.style.color=e.color;

        if(e.type==='comment'){
            const text=document.createElement('div');
            text.className='overlay-comment';
            text.textContent=e.text||e.name;
            text.style.fontSize=e.fontSize+'px';
            text.style.fontWeight=e.fontWeight;
            el.appendChild(text);
        }

        if(e.type==='box'){
            const shape=document.createElement('div');
            shape.className='overlay-shape shape-'+e.shape;
            el.appendChild(shape);
        }

        if(e.type==='skip'){
            const skip=document.createElement('div');
            skip.className='overlay-skip';
            skip.textContent='スキップ';
            el.appendChild(skip);
        }

        el.addEventListener('pointerdown',ev=>{
            ev.stopPropagation();
            startVideoDrag(ev,e);
        });

        el.addEventListener('click',ev=>{
            ev.stopPropagation();
            select(e.id,ev.shiftKey);
        });

        el.addEventListener('dblclick',ev=>{
            ev.stopPropagation();
            openElement(e.id);
        });

        el.addEventListener('contextmenu',ev=>{
            ev.preventDefault();
            ev.stopPropagation();
            select(e.id,false);
            openMenu(ev.clientX,ev.clientY,e.id);
        });

        ui.overlay.appendChild(el);
    });

    renderConnections();
}

/* =========================
   動画上の接続線
========================= */

function renderConnections(){
    ui.connection.innerHTML='';

    if(!canEdit())return;

    const videoRect=ui.video.getBoundingClientRect();
    const wrapRect=ui.wrap.getBoundingClientRect();

    if(!videoRect.width||!videoRect.height)return;

    ui.connection.setAttribute('viewBox',
        `0 0 ${wrapRect.width} ${wrapRect.height}`);

    state.project.elements
        .filter(e=>e.type==='comment')
        .forEach(comment=>{
            const targetId=comment.connectTo;
            if(!targetId)return;

            const target=getElement(targetId);

            if(!target || target.type!=='box')return;
            if(!isVisible(comment)||!isVisible(target))return;

            const a={
                x:videoRect.left+(comment.x+comment.w/2)/100*videoRect.width-wrapRect.left,
                y:videoRect.top+(comment.y+comment.h/2)/100*videoRect.height-wrapRect.top
            };

            const b={
                x:videoRect.left+(target.x+target.w/2)/100*videoRect.width-wrapRect.left,
                y:videoRect.top+(target.y+target.h/2)/100*videoRect.height-wrapRect.top
            };

            const dx=b.x-a.x;
            const curve=Math.max(35,Math.abs(dx)*.3);

            const path=document.createElementNS(
                'http://www.w3.org/2000/svg','path'
            );

            path.classList.add('connection');
            path.setAttribute(
                'd',
                `M ${a.x} ${a.y}
                 C ${a.x+curve} ${a.y},
                   ${b.x-curve} ${b.y},
                   ${b.x} ${b.y}`
            );

            ui.connection.appendChild(path);

            const dot=document.createElementNS(
                'http://www.w3.org/2000/svg','circle'
            );

            dot.classList.add('connection-dot');
            dot.setAttribute('cx',b.x);
            dot.setAttribute('cy',b.y);
            dot.setAttribute('r','5');

            ui.connection.appendChild(dot);
        });
}

/* =========================
   再生位置
========================= */

function renderPlayhead(){
    if(!canEdit()){
        ui.playhead.style.display='none';
        return;
    }

    ui.playhead.style.display='block';

    const p=timePercent(state.currentTime);

    ui.playhead.style.left=
        `calc(var(--label) + (100% - var(--label))*${p/100})`;

    ui.playheadLabel.textContent=formatTime(state.currentTime);
    ui.readout.textContent=
        `${formatTime(state.currentTime)} / ${formatTime(state.duration)}`;
}

function seek(t){
    state.currentTime=clamp(Number(t)||0,0,state.duration);

    if(Number.isFinite(ui.video.duration)){
        try{ui.video.currentTime=state.currentTime}catch(_){}
    }

    renderAll();
}

/* =========================
   ドラッグ
========================= */

function startDrag(ev){
    const id=ev.currentTarget.dataset.id;
    const e=getElement(id);
    if(!e)return;

    ev.preventDefault();
    ev.stopPropagation();

    select(id,false);

    const handle=ev.target.closest('.handle');
    const lane=ev.currentTarget.parentElement;
    const range=visibleRange();

    state.drag={
        id,
        mode:handle?'resize':'move',
        edge:handle?.dataset.edge||'',
        x:ev.clientX,
        width:lane.getBoundingClientRect().width,
        range,
        start:e.start,
        end:e.end
    };

    window.addEventListener('pointermove',moveDrag);
    window.addEventListener('pointerup',endDrag,{once:true});
}

function moveDrag(ev){
    const d=state.drag;
    if(!d)return;

    const e=getElement(d.id);
    if(!e)return;

    const dt=(ev.clientX-d.x)/Math.max(1,d.width)*
        (d.range.end-d.range.start);

    const min=.05;

    if(d.mode==='move'){
        const len=d.end-d.start;
        e.start=clamp(d.start+dt,0,state.duration-len);
        e.end=e.start+len;
    }else if(d.edge==='left'){
        e.start=clamp(d.start+dt,0,d.end-min);
    }else{
        e.end=clamp(d.end+dt,d.start+min,state.duration);
    }

    dirty();
    renderAll();
}

function endDrag(){
    state.drag=null;
    window.removeEventListener('pointermove',moveDrag);
    renderAll();
}

function startVideoDrag(ev,e){
    ev.preventDefault();

    const rect=ui.video.getBoundingClientRect();

    state.drag={
        id:e.id,
        mode:'video',
        x:ev.clientX,
        y:ev.clientY,
        originalX:e.x,
        originalY:e.y,
        rect
    };

    window.addEventListener('pointermove',moveVideoDrag);
    window.addEventListener('pointerup',endVideoDrag,{once:true});
}

function moveVideoDrag(ev){
    const d=state.drag;
    if(!d||d.mode!=='video')return;

    const e=getElement(d.id);
    if(!e)return;

    e.x=clamp(
        d.originalX+(ev.clientX-d.x)/d.rect.width*100,
        0,100-e.w
    );

    e.y=clamp(
        d.originalY+(ev.clientY-d.y)/d.rect.height*100,
        0,100-e.h
    );

    dirty();
    renderOverlay();
}

function endVideoDrag(){
    state.drag=null;
    window.removeEventListener('pointermove',moveVideoDrag);
    renderAll();
}

/* =========================
   要素編集
========================= */

function openElement(id){
    const e=getElement(id);
    if(!e)return;

    state.modalId=id;

    $('elementModalTitle').textContent=
        e.type==='comment'?'コメントを編集':
        e.type==='box'?'強調枠を編集':'スキップを編集';

    $('elementName').value=e.name;
    $('elementText').value=e.text;
    $('elementStart').value=e.start;
    $('elementEnd').value=e.end;
    $('elementX').value=e.x;
    $('elementY').value=e.y;
    $('elementW').value=e.w;
    $('elementH').value=e.h;
    $('elementFontSize').value=e.fontSize;
    $('elementFontWeight').value=e.fontWeight;
    $('elementShape').value=e.shape;

    $('commentTextField').classList.toggle('hidden',e.type!=='comment');
    $('fontField').classList.toggle('hidden',e.type!=='comment');
    $('weightField').classList.toggle('hidden',e.type!=='comment');
    $('shapeField').classList.toggle('hidden',e.type!=='box');

    buildColors(e.color);

    ui.modal.style.display='flex';
}

function buildColors(active){
    const grid=$('colorGrid');
    grid.innerHTML='';

    state.colors.forEach(color=>{
        const b=document.createElement('button');
        b.type='button';
        b.className='color-choice'+(color===active?' active':'');
        b.style.background=color;
        b.dataset.color=color;

        b.onclick=()=>{
            grid.querySelectorAll('.active')
                .forEach(x=>x.classList.remove('active'));
            b.classList.add('active');
        };

        grid.appendChild(b);
    });
}

function closeElement(){
    ui.modal.style.display='none';
    state.modalId=null;
}

function saveElement(){
    const e=getElement(state.modalId);
    if(!e)return;

    const start=Number($('elementStart').value);
    const end=Number($('elementEnd').value);

    if(!Number.isFinite(start)||!Number.isFinite(end)||
       start<0||end<=start||end>state.duration){
        toast('開始・終了時間が不正です',true);
        return;
    }

    e.name=$('elementName').value.trim()||(
        e.type==='comment'?'コメント':
        e.type==='box'?'強調枠':'スキップ'
    );

    e.text=$('elementText').value;
    e.start=start;
    e.end=end;
    e.x=clamp(Number($('elementX').value)||0,0,99);
    e.y=clamp(Number($('elementY').value)||0,0,99);
    e.w=clamp(Number($('elementW').value)||1,1,100);
    e.h=clamp(Number($('elementH').value)||1,1,100);
    e.fontSize=clamp(Number($('elementFontSize').value)||28,8,100);
    e.fontWeight=$('elementFontWeight').value;
    e.shape=$('elementShape').value;
    e.color=$('colorGrid .active')?.dataset.color||e.color;

    dirty();
    closeElement();
    renderAll();
}

/* =========================
   追加
========================= */

function addElement(type){
    if(!canEdit()){
        toast('先に動画を読み込んでください',true);
        return;
    }

    const start=clamp(state.currentTime,0,state.duration-.05);

    const e={
        id:uid('el'),
        type,
        name:type==='comment'?'コメント':
             type==='box'?'強調枠':'スキップ',
        text:type==='comment'?'コメント':'',
        shape:'rect',
        start,
        end:Math.min(state.duration,start+3),
        x:state.pendingXY?.x??10,
        y:state.pendingXY?.y??10,
        w:type==='box'?30:35,
        h:type==='box'?22:18,
        color:typeColor[type],
        fontSize:28,
        fontWeight:'700'
    };

    state.pendingXY=null;

    state.project.elements.push(e);
    state.selected.clear();
    state.selected.add(e.id);

    dirty();
    renderAll();
    openElement(e.id);
}

/* =========================
   接続
========================= */

function connectSelected(){
    const selected=[...state.selected];

    if(selected.length!==2){
        toast('コメントと強調枠を1つずつ選択してください',true);
        return;
    }

    const a=getElement(selected[0]);
    const b=getElement(selected[1]);

    if(!a||!b){
        toast('要素が見つかりません',true);
        return;
    }

    let comment,box;

    if(a.type==='comment'&&b.type==='box'){
        comment=a;box=b;
    }else if(a.type==='box'&&b.type==='comment'){
        comment=b;box=a;
    }else{
        toast('接続できるのはコメントと強調枠です',true);
        return;
    }

    comment.connectTo=box.id;

    dirty();
    renderAll();
    toast('動画上に接続線を表示しました');
}

function disconnectSelected(){
    let changed=false;

    state.project.elements.forEach(e=>{
        if(e.type==='comment'&&
           (state.selected.has(e.id)||state.selected.has(e.connectTo))){
            if(e.connectTo){
                delete e.connectTo;
                changed=true;
            }
        }
    });

    if(changed){
        dirty();
        renderAll();
        toast('接続を解除しました');
    }
}

/* =========================
   削除
========================= */

function deleteSelected(){
    if(!state.selected.size)return;

    const ids=new Set(state.selected);

    state.project.elements=
        state.project.elements.filter(e=>!ids.has(e.id));

    state.project.elements.forEach(e=>{
        if(e.type==='comment'&&ids.has(e.connectTo)){
            delete e.connectTo;
        }
    });

    state.selected.clear();
    dirty();
    renderAll();
}

/* =========================
   右クリックメニュー
========================= */

function openMenu(x,y,id=null){
    state.contextId=id;

    ui.menu.style.display='block';
    ui.menu.style.left=Math.min(x,innerWidth-240)+'px';
    ui.menu.style.top=Math.min(y,innerHeight-270)+'px';
}

function closeMenu(){
    ui.menu.style.display='none';
}

ui.menu.addEventListener('click',ev=>{
    const action=ev.target.closest('button')?.dataset.action;
    if(!action)return;

    switch(action){
        case 'add-comment':addElement('comment');break;
        case 'add-box':addElement('box');break;
        case 'add-skip':addElement('skip');break;
        case 'edit':
            if(state.contextId)openElement(state.contextId);
            break;
        case 'connect':connectSelected();break;
        case 'disconnect':disconnectSelected();break;
        case 'delete':deleteSelected();break;
    }

    closeMenu();
});

/* =========================
   動画右クリック
========================= */

ui.wrap.addEventListener('contextmenu',ev=>{
    ev.preventDefault();

    if(!canEdit())return;

    const rect=ui.video.getBoundingClientRect();

    if(!rect.width||!rect.height)return;

    state.pendingXY={
        x:clamp((ev.clientX-rect.left)/rect.width*100,0,95),
        y:clamp((ev.clientY-rect.top)/rect.height*100,0,95)
    };

    openMenu(ev.clientX,ev.clientY);
});

/* =========================
   保存
========================= */

function saveLocal(){
    if(!state.project)return;

    const p=structuredClone(state.project);
    p.savedAt=new Date().toISOString();

    localStorage.setItem(
        'video-editor:'+p.name,
        JSON.stringify(p)
    );

    clean();
    toast('保存しました');
}

function showStorage(){
    ui.storageList.innerHTML='';

    const keys=Object.keys(localStorage)
        .filter(k=>k.startsWith('video-editor:'))
        .sort();

    if(!keys.length){
        ui.storageList.textContent='保存されたプロジェクトはありません。';
    }

    keys.forEach(key=>{
        try{
            const p=JSON.parse(localStorage.getItem(key));

            const row=document.createElement('div');
            row.style.cssText=
                'display:flex;gap:10px;align-items:center;padding:10px;border-bottom:1px solid #334155';

            const info=document.createElement('div');
            info.style.flex='1';

            const name=document.createElement('strong');
            name.textContent=p.name;

            const meta=document.createElement('div');
            meta.style.cssText='font-size:11px;color:#64748b;margin-top:3px';
            meta.textContent=
                `${p.videoName||''} / ${p.elements?.length||0}要素`;

            info.append(name,meta);

            const load=document.createElement('button');
            load.className='btn small';
            load.textContent='読込';

            load.onclick=()=>{
                loadProject(p);
                ui.storage.style.display='none';
            };

            const del=document.createElement('button');
            del.className='btn small danger';
            del.textContent='削除';

            del.onclick=()=>{
                localStorage.removeItem(key);
                showStorage();
            };

            row.append(info,load,del);
            ui.storageList.appendChild(row);
        }catch(_){}
    });

    ui.storage.style.display='flex';
}

function loadProject(project){
    try{
        state.project=normalizeProject(structuredClone(project));
        state.duration=state.project.duration;

        if(Number.isFinite(ui.video.duration)&&ui.video.duration>0){
            state.duration=ui.video.duration;
            state.project.duration=state.duration;
        }

        state.currentTime=0;
        state.selected.clear();

        enableEditor();
        renderAll();
        clean();

        toast('プロジェクトを読み込みました');
    }catch(e){
        toast('読込エラー：'+e.message,true);
    }
}

function exportProject(){
    if(!state.project)return;

    const form=document.createElement('form');
    form.method='POST';
    form.action=location.href;
    form.style.display='none';

    const action=document.createElement('input');
    action.name='action';
    action.value='export-json';

    const project=document.createElement('input');
    project.name='project';
    project.value=JSON.stringify(state.project);

    form.append(action,project);
    document.body.appendChild(form);
    form.submit();
    form.remove();
}

/* =========================
   動画
========================= */

function enableEditor(){
    ui.play.disabled=false;
    ui.stop.disabled=false;
    ui.save.disabled=false;
    ui.empty.style.display='none';
}

function disableEditor(){
    ui.play.disabled=true;
    ui.stop.disabled=true;
    ui.save.disabled=true;
    ui.empty.style.display='block';

    ui.overlay.innerHTML='';
    ui.connection.innerHTML='';
    ui.tracks.innerHTML='';
    ui.playhead.style.display='none';
}

function loadVideo(file){
    if(!file||!file.type.startsWith('video/')){
        toast('動画ファイルを選択してください',true);
        return;
    }

    if(state.videoUrl)URL.revokeObjectURL(state.videoUrl);

    state.videoFile=file;
    state.videoUrl=URL.createObjectURL(file);
    state.project=newProject(file.name);
    state.duration=0;
    state.currentTime=0;

    disableEditor();

    ui.video.src=state.videoUrl;
    ui.video.load();

    ui.status.textContent='動画を読み込んでいます…';
}

function resetProject(){
    if(!confirm('現在のプロジェクトを破棄して新規作成しますか？'))return;

    state.project=null;
    state.selected.clear();
    state.currentTime=0;
    state.duration=0;

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
        state.videoUrl='';
    }

    state.videoFile=null;

    ui.video.removeAttribute('src');
    ui.video.load();

    disableEditor();
    ui.status.textContent='動画を読み込んでください';
}

/* =========================
   イベント
========================= */

ui.open.onclick=()=>ui.file.click();
ui.file.onchange=()=>loadVideo(ui.file.files[0]);

ui.newBtn.onclick=resetProject;
ui.save.onclick=saveLocal;
ui.load.onclick=showStorage;
ui.exportBtn.onclick=exportProject;

ui.import.onchange=async ev=>{
    const file=ev.target.files[0];
    if(!file)return;

    try{
        loadProject(JSON.parse(await file.text()));
    }catch(e){
        toast('JSON読込エラー：'+e.message,true);
    }

    ev.target.value='';
};

ui.video.onloadedmetadata=()=>{
    state.duration=Number(ui.video.duration)||0;

    if(!state.project){
        state.project=newProject();
    }

    state.project.duration=state.duration;
    state.currentTime=0;

    enableEditor();
    renderAll();
    clean();
};

ui.video.ontimeupdate=()=>{
    if(!ui.video.paused){
        state.currentTime=ui.video.currentTime;
        renderAll();
    }
};

ui.video.onended=()=>{
    ui.play.textContent='▶';
    state.currentTime=state.duration;
    renderAll();
};

ui.play.onclick=async()=>{
    if(!canEdit())return;

    if(ui.video.paused){
        try{
            await ui.video.play();
            ui.play.textContent='Ⅱ';
        }catch(e){
            toast('動画を再生できません',true);
        }
    }else{
        ui.video.pause();
        ui.play.textContent='▶';
    }
};

ui.stop.onclick=()=>{
    ui.video.pause();
    ui.play.textContent='▶';
    seek(0);
};

ui.zoom.oninput=()=>{
    ui.zoomValue.textContent=
        Number(ui.zoom.value).toFixed(1)+'×';
    renderAll();
};

/* タイムラインクリック */
ui.content.addEventListener('pointerdown',ev=>{
    if(
        ev.target.closest('.element-bar')||
        ev.target.closest('.playhead')
    )return;

    if(ev.target.closest('.track-lane')||
       ev.target.closest('.axis-track')){
        seek(clientTime(ev.clientX));
    }
});

/* 再生ヘッド */
let playheadDrag=false;

ui.playhead.addEventListener('pointerdown',ev=>{
    if(!canEdit())return;

    ev.preventDefault();
    playheadDrag=true;
    seek(clientTime(ev.clientX));
});

window.addEventListener('pointermove',ev=>{
    if(playheadDrag)seek(clientTime(ev.clientX));
});

window.addEventListener('pointerup',()=>{
    playheadDrag=false;
});

$('elementClose').onclick=closeElement;
$('elementCancel').onclick=closeElement;
$('elementSave').onclick=saveElement;

$('storageClose').onclick=()=>{
    ui.storage.style.display='none';
};

document.addEventListener('click',ev=>{
    if(!ev.target.closest('.context-menu'))closeMenu();
});

document.addEventListener('keydown',ev=>{
    const tag=document.activeElement?.tagName;

    if(ev.key==='Escape'){
        closeMenu();
        closeElement();
        ui.storage.style.display='none';
    }

    if(
        (ev.key==='Delete'||ev.key==='Backspace')&&
        !['INPUT','TEXTAREA','SELECT'].includes(tag)
    ){
        deleteSelected();
    }

    if(
        ev.key===' '&&
        !['INPUT','TEXTAREA','SELECT'].includes(tag)
    ){
        ev.preventDefault();
        ui.play.click();
    }
});

window.addEventListener('resize',renderAll);
ui.scroll.addEventListener('scroll',renderConnections);

function hexAlpha(hex,alpha){
    if(!/^#[0-9a-f]{6}$/i.test(hex))return hex;

    const n=parseInt(hex.slice(1),16);
    return `rgba(${n>>16},${n>>8&255},${n&255},${alpha})`;
}

function renderAll(){
    renderTimeline();
    renderOverlay();
    renderPlayhead();
}

renderAll();
</script>
</body>
</html>

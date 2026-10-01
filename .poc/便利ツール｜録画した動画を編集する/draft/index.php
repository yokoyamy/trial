<?php
declare(strict_types=1);

const APP_VERSION = '6.0.0';

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
        $project = $_POST['project'] ?? '';
        $decoded = json_decode($project, true);

        if ($project === '' || !is_array($decoded)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'invalid json'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="video-project.json"');
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
<title>動画注釈編集ツール</title>
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
    --comment:#60a5fa;
    --highlight:#22c55e;
    --skip:#f97316;
    --label:110px;
    --track:54px;
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
.hidden{display:none!important}

.app{
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
    white-space:nowrap;
    margin-right:8px
}
.btn{
    border:1px solid #40516a;
    border-radius:6px;
    background:#243247;
    color:var(--text);
    padding:7px 11px
}
.btn:hover:not(:disabled){background:#304158}
.btn.primary{
    background:var(--blue);
    border-color:#3b82f6
}
.btn.danger{
    background:#7f1d1d;
    border-color:#991b1b
}
.btn.small{
    padding:5px 8px;
    font-size:12px
}
.status{
    margin-left:auto;
    color:var(--muted);
    font-size:12px;
    white-space:nowrap
}
.file-input{display:none}

.main{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column
}

.video-area{
    flex:1;
    min-height:250px;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:12px;
    background:#050a11;
    overflow:hidden
}

.video-wrap{
    position:relative;
    width:min(1100px,100%);
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center
}

#video{
    display:block;
    max-width:100%;
    max-height:100%;
    background:#000
}

.video-overlay{
    position:absolute;
    inset:0;
    pointer-events:none
}

.overlay-svg{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
    z-index:20
}

.connection{
    fill:none;
    stroke:#facc15;
    stroke-width:2.5;
    stroke-dasharray:7 5;
    opacity:.95
}

.connection.active{
    stroke:#fff;
    stroke-width:4
}

.connection-dot{
    fill:#facc15;
    stroke:#111827;
    stroke-width:2
}

.overlay-element{
    position:absolute;
    min-width:30px;
    min-height:20px;
    pointer-events:auto;
    user-select:none;
    z-index:10
}

.overlay-element.selected{
    outline:2px solid #60a5fa;
    outline-offset:2px
}

.overlay-comment{
    width:100%;
    height:100%;
    padding:6px 9px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    white-space:pre-wrap;
    overflow:hidden;
    background:#0009;
    border:1px solid currentColor;
    border-radius:4px;
    line-height:1.25
}

.overlay-highlight{
    width:100%;
    height:100%;
    border:3px solid currentColor;
    background:transparent
}

.overlay-highlight.square{border-radius:0}
.overlay-highlight.circle{
    border-radius:50%
}
.overlay-highlight.ellipse{
    border-radius:50%
}

.overlay-skip{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    border:2px dashed currentColor;
    background:#f9731622;
    color:#fdba74;
    border-radius:4px;
    font-size:12px;
    font-weight:700
}

.empty-video{
    position:absolute;
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

.bottom{
    height:330px;
    flex:none;
    display:flex;
    min-height:0;
    border-top:1px solid var(--line);
    background:#0e1724
}

.timeline-panel{
    min-width:0;
    flex:1;
    display:flex;
    flex-direction:column
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
    min-width:150px;
    font-variant-numeric:tabular-nums;
    color:#dbeafe
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
    min-width:700px;
    width:100%
}

.axis-row{
    position:sticky;
    top:0;
    z-index:50;
    height:34px;
    display:flex;
    background:#131d29;
    border-bottom:1px solid var(--line)
}

.axis-label{
    width:var(--label);
    min-width:var(--label);
    display:flex;
    align-items:center;
    padding-left:8px;
    background:#131d29;
    border-right:1px solid var(--line);
    font-size:12px;
    position:sticky;
    left:0;
    z-index:60
}

.axis-track{
    position:relative;
    height:100%;
    flex:1
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
    pointer-events:none
}

.track-row{
    height:var(--track);
    display:flex;
    border-bottom:1px solid #263445
}

.track-label{
    width:var(--label);
    min-width:var(--label);
    display:flex;
    align-items:center;
    gap:6px;
    padding:0 8px;
    background:#111b27;
    border-right:1px solid var(--line);
    font-size:12px;
    position:sticky;
    left:0;
    z-index:20
}

.type-dot{
    width:8px;
    height:8px;
    border-radius:50%;
    flex:none
}

.track-lane{
    position:relative;
    flex:1;
    min-width:0
}

.track-grid{
    position:absolute;
    inset:0;
    pointer-events:none
}

.element-bar{
    position:absolute;
    top:8px;
    height:38px;
    min-width:14px;
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
    box-shadow:0 0 0 2px #60a5fa
}

.bar-label{
    padding:0 8px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:11px;
    pointer-events:none
}

.handle{
    position:absolute;
    top:0;
    width:10px;
    height:100%;
    z-index:8
}
.handle.left{
    left:-5px;
    cursor:ew-resize
}
.handle.right{
    right:-5px;
    cursor:ew-resize
}

.playhead{
    position:absolute;
    top:34px;
    bottom:0;
    width:3px;
    z-index:80;
    pointer-events:auto;
    background:var(--red);
    box-shadow:0 0 5px #ef444499;
    cursor:ew-resize;
    touch-action:none
}

.playhead::before{
    content:"";
    position:absolute;
    top:-1px;
    left:-5px;
    width:13px;
    height:13px;
    background:var(--red);
    clip-path:polygon(0 0,100% 0,50% 100%)
}

.playhead-label{
    position:absolute;
    top:12px;
    left:6px;
    padding:2px 4px;
    background:var(--red);
    color:#fff;
    font-size:10px;
    white-space:nowrap;
    border-radius:3px
}

.context-menu{
    position:fixed;
    display:none;
    z-index:2000;
    min-width:210px;
    padding:5px;
    background:#172235;
    border:1px solid #475569;
    border-radius:6px;
    box-shadow:0 12px 30px #0008
}

.context-menu button{
    display:block;
    width:100%;
    padding:8px;
    border:0;
    background:transparent;
    color:var(--text);
    text-align:left;
    border-radius:4px
}

.context-menu button:hover{background:#293a52}

.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:3000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:#000a
}

.modal{
    width:min(570px,100%);
    max-height:90vh;
    display:flex;
    flex-direction:column;
    background:#182231;
    border:1px solid #475569;
    border-radius:8px;
    overflow:hidden
}

.modal-head,
.modal-foot{
    padding:12px 15px;
    border-color:var(--line)
}

.modal-head{
    display:flex;
    justify-content:space-between;
    border-bottom:1px solid var(--line)
}

.modal-body{
    padding:15px;
    display:grid;
    gap:11px;
    overflow:auto
}

.modal-foot{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid var(--line)
}

.field{
    display:grid;
    gap:5px
}

.field label{
    font-size:12px;
    color:var(--muted)
}

.field input,
.field select,
.field textarea{
    width:100%;
    padding:7px;
    background:#0f1722;
    color:var(--text);
    border:1px solid #40516a;
    border-radius:5px
}

.field textarea{
    min-height:90px;
    resize:vertical
}

.two{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px
}

.color-grid{
    display:grid;
    grid-template-columns:repeat(8,1fr);
    gap:6px
}

.color-choice{
    height:28px;
    border:2px solid transparent;
    border-radius:4px
}

.color-choice.active{
    border-color:#fff;
    box-shadow:0 0 0 1px #60a5fa
}

.storage-list{
    padding:0!important;
    gap:0!important
}

.storage-item{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px;
    border-bottom:1px solid #334155
}

.storage-info{
    min-width:0;
    flex:1
}

.storage-name{
    font-weight:600;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap
}

.storage-meta{
    color:#64748b;
    font-size:11px
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
    box-shadow:0 10px 30px #0006
}

@media(max-width:800px){
    :root{--label:90px}
    .brand{display:none}
    .bottom{height:300px}
}

@media(max-width:600px){
    .status{display:none}
}
</style>
</head>

<body>
<div class="app">

<header class="topbar">
    <div class="brand">動画注釈編集</div>

    <button class="btn primary" id="openVideo">動画を読み込む</button>
    <input class="file-input" id="videoFile" type="file" accept="video/*">

    <button class="btn" id="newProject">新規</button>
    <button class="btn" id="saveProject" disabled>保存</button>
    <button class="btn" id="loadProject">保存データ</button>
    <button class="btn" id="exportProject" disabled>JSON書出し</button>

    <label class="btn">
        JSON読込
        <input class="file-input" id="importProject" type="file" accept=".json,application/json">
    </label>

    <span class="status" id="status">動画を読み込んでください</span>
</header>

<main class="main">

<section class="video-area">
    <div class="video-wrap" id="videoWrap">
        <video id="video" preload="metadata" playsinline></video>

        <div class="video-overlay" id="videoOverlay">
            <svg class="overlay-svg" id="connectionSvg"></svg>
        </div>

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
            <div class="axis-track" id="axisTrack"></div>
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

<div class="context-menu" id="contextMenu">
    <button data-add="comment">＋ コメント</button>
    <button data-add="highlight">＋ 強調枠</button>
    <button data-add="skip">＋ スキップ</button>
    <hr>
    <button id="editContext">編集</button>
    <button id="deleteContext">削除</button>
</div>

<div class="modal-backdrop" id="elementModal">
<div class="modal">

<div class="modal-head">
    <strong id="modalTitle">要素編集</strong>
    <button class="btn small" id="modalClose">閉じる</button>
</div>

<div class="modal-body">

    <div class="field">
        <label>要素名</label>
        <input id="fieldName">
    </div>

    <div class="field" id="commentTextField">
        <label>コメント</label>
        <textarea id="fieldText" placeholder="動画上に表示するコメント"></textarea>
    </div>

    <div class="field hidden" id="shapeField">
        <label>強調枠の形</label>
        <select id="fieldShape">
            <option value="square">四角</option>
            <option value="circle">丸</option>
            <option value="ellipse">楕円</option>
        </select>
    </div>

    <div class="two">
        <div class="field">
            <label>開始時間</label>
            <input id="fieldStart" type="number" min="0" step=".001">
        </div>
        <div class="field">
            <label>終了時間</label>
            <input id="fieldEnd" type="number" min="0" step=".001">
        </div>
    </div>

    <div class="two">
        <div class="field">
            <label>横位置 (%)</label>
            <input id="fieldX" type="number" min="0" max="99" step=".1">
        </div>
        <div class="field">
            <label>縦位置 (%)</label>
            <input id="fieldY" type="number" min="0" max="99" step=".1">
        </div>
    </div>

    <div class="two">
        <div class="field">
            <label>幅 (%)</label>
            <input id="fieldW" type="number" min="1" max="100" step=".1">
        </div>
        <div class="field">
            <label>高さ (%)</label>
            <input id="fieldH" type="number" min="1" max="100" step=".1">
        </div>
    </div>

    <div class="field" id="fontField">
        <label>文字サイズ</label>
        <input id="fieldFont" type="number" min="8" max="100" step="1">
    </div>

    <div class="field" id="colorField">
        <label>色</label>
        <div class="color-grid" id="colorGrid"></div>
    </div>

    <div class="field" id="targetField">
        <label>接続する強調枠</label>
        <select id="fieldTarget">
            <option value="">接続しない</option>
        </select>
    </div>

</div>

<div class="modal-foot">
    <button class="btn danger" id="modalDelete">削除</button>
    <button class="btn" id="modalCancel">キャンセル</button>
    <button class="btn primary" id="modalSave">保存</button>
</div>

</div>
</div>

<div class="modal-backdrop" id="storageModal">
<div class="modal">

<div class="modal-head">
    <strong>保存データ</strong>
    <button class="btn small" id="storageClose">閉じる</button>
</div>

<div class="modal-body storage-list" id="storageList"></div>

</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $ = id => document.getElementById(id);

const UI = {
    video:$('video'),
    videoWrap:$('videoWrap'),
    overlay:$('videoOverlay'),
    connectionSvg:$('connectionSvg'),
    empty:$('emptyVideo'),
    file:$('videoFile'),
    openVideo:$('openVideo'),
    newProject:$('newProject'),
    saveProject:$('saveProject'),
    loadProject:$('loadProject'),
    exportProject:$('exportProject'),
    importProject:$('importProject'),
    status:$('status'),
    play:$('play'),
    stop:$('stop'),
    readout:$('readout'),
    zoom:$('zoom'),
    zoomValue:$('zoomValue'),
    scroll:$('timelineScroll'),
    content:$('timelineContent'),
    axis:$('axisTrack'),
    tracks:$('tracks'),
    playhead:$('playhead'),
    playheadLabel:$('playheadLabel'),
    menu:$('contextMenu'),
    modal:$('elementModal'),
    storage:$('storageModal'),
    storageList:$('storageList'),
    modalTitle:$('modalTitle'),
    fieldName:$('fieldName'),
    fieldText:$('fieldText'),
    fieldShape:$('fieldShape'),
    fieldStart:$('fieldStart'),
    fieldEnd:$('fieldEnd'),
    fieldX:$('fieldX'),
    fieldY:$('fieldY'),
    fieldW:$('fieldW'),
    fieldH:$('fieldH'),
    fieldFont:$('fieldFont'),
    colorGrid:$('colorGrid'),
    fieldTarget:$('fieldTarget'),
    commentTextField:$('commentTextField'),
    shapeField:$('shapeField'),
    fontField:$('fontField'),
    targetField:$('targetField'),
    modalClose:$('modalClose'),
    modalDelete:$('modalDelete'),
    modalCancel:$('modalCancel'),
    modalSave:$('modalSave'),
    storageClose:$('storageClose'),
    toast:$('toast')
};

const APP = {
    version:'6.0.0',
    project:null,
    duration:0,
    time:0,
    videoUrl:'',
    selected:new Set(),
    modalId:null,
    contextId:null,
    contextXY:null,
    dirty:false,
    drag:null,
    playheadDrag:false,
    colors:[
        '#60a5fa','#22c55e','#f59e0b','#ef4444',
        '#c084fc','#14b8a6','#f97316','#e879f9',
        '#38bdf8','#a3e635','#fb7185','#facc15'
    ]
};

function uid(prefix){
    return prefix+'-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8);
}

function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}

function fmt(sec){
    sec=Math.max(0,Number(sec)||0);
    const m=Math.floor(sec/60);
    const s=Math.floor(sec%60);
    const ms=Math.floor((sec%1)*1000);
    return String(m).padStart(2,'0')+':'+
           String(s).padStart(2,'0')+'.'+
           String(ms).padStart(3,'0');
}

function shortTime(sec){
    sec=Number(sec)||0;
    if(sec<60)return sec.toFixed(sec%1?1:0)+'s';
    return String(Math.floor(sec/60)).padStart(2,'0')+':'+
           String(Math.floor(sec%60)).padStart(2,'0');
}

function typeColor(type){
    return type==='comment'?'#60a5fa':
           type==='highlight'?'#22c55e':'#f97316';
}

function rgba(hex,alpha){
    const n=parseInt(hex.slice(1),16);
    return `rgba(${n>>16},${n>>8&255},${n&255},${alpha})`;
}

function toast(message,error=false){
    UI.status.textContent=message;
    UI.toast.textContent=message;
    UI.toast.style.display='block';
    UI.toast.style.borderColor=error?'#ef4444':'#475569';
    clearTimeout(toast.timer);
    toast.timer=setTimeout(()=>UI.toast.style.display='none',2500);
}

function setStatus(message){
    UI.status.textContent=message;
}

function dirty(){
    APP.dirty=true;
    setStatus('変更あり');
}

function clean(){
    APP.dirty=false;
    setStatus(APP.project?'編集中：'+APP.project.name:'動画を読み込んでください');
}

function canEdit(){
    return !!APP.project && APP.duration>0;
}

function getElement(id){
    return APP.project?.elements.find(e=>e.id===id)||null;
}

function createProject(name='新規プロジェクト'){
    return {
        version:APP.version,
        name,
        videoName:'',
        duration:APP.duration,
        elements:[]
    };
}

/* ------------------------------
   Project normalization
------------------------------ */

function normalizeProject(input){
    if(!input || typeof input!=='object'){
        throw new Error('プロジェクト形式が不正です');
    }

    const duration=Number(input.duration)||APP.duration;

    const p={
        version:APP.version,
        name:String(input.name||'プロジェクト'),
        videoName:String(input.videoName||''),
        duration,
        elements:[]
    };

    const allowed=['comment','highlight','skip'];

    for(const raw of Array.isArray(input.elements)?input.elements:[]){
        const type=allowed.includes(raw.type)?raw.type:'comment';

        const start=clamp(Number(raw.start)||0,0,duration);
        const end=clamp(
            Number(raw.end)||Math.min(duration,start+3),
            0,
            duration
        );

        const e={
            id:String(raw.id||uid('el')),
            type,
            name:String(raw.name||(
                type==='comment'?'コメント':
                type==='highlight'?'強調枠':'スキップ'
            )),
            text:String(raw.text||''),
            start,
            end:Math.max(start+.05,Math.min(duration,end)),
            x:clamp(Number(raw.x)||10,0,99),
            y:clamp(Number(raw.y)||10,0,99),
            w:clamp(Number(raw.w)||30,1,100),
            h:clamp(Number(raw.h)||15,1,100),
            color:typeof raw.color==='string'&&/^#[0-9a-f]{6}$/i.test(raw.color)
                ?raw.color
                :typeColor(type),
            fontSize:clamp(Number(raw.fontSize)||28,8,100),
            shape:['square','circle','ellipse'].includes(raw.shape)
                ?raw.shape
                :'square',
            target:String(raw.target||'')
        };

        if(e.type!=='comment')e.text='';
        if(e.type!=='highlight')e.target='';

        p.elements.push(e);
    }

    const ids=new Set(p.elements.map(e=>e.id));

    for(const e of p.elements){
        if(e.type==='comment' && e.target){
            const target=getProjectElement(p,e.target);
            if(!target || target.type!=='highlight')e.target='';
        }
    }

    return p;
}

function getProjectElement(project,id){
    return project.elements.find(e=>e.id===id)||null;
}

/* ------------------------------
   Video
------------------------------ */

function loadVideo(file){
    if(!file || !file.type.startsWith('video/')){
        toast('動画ファイルを選択してください',true);
        return;
    }

    if(APP.videoUrl){
        URL.revokeObjectURL(APP.videoUrl);
        APP.videoUrl='';
    }

    APP.videoUrl=URL.createObjectURL(file);
    APP.project=createProject(file.name);
    APP.project.videoName=file.name;
    APP.duration=0;
    APP.time=0;
    APP.selected.clear();

    UI.video.pause();
    UI.video.src=APP.videoUrl;
    UI.video.load();

    setStatus('動画を読み込んでいます…');
}

function resetApplication(){
    UI.video.pause();

    if(APP.videoUrl){
        URL.revokeObjectURL(APP.videoUrl);
        APP.videoUrl='';
    }

    UI.video.removeAttribute('src');
    UI.video.load();

    APP.project=null;
    APP.duration=0;
    APP.time=0;
    APP.selected.clear();
    APP.modalId=null;
    APP.contextId=null;
    APP.dirty=false;

    disableEditor();
    renderAll();
    setStatus('動画を読み込んでください');
}

function enableEditor(){
    UI.play.disabled=false;
    UI.stop.disabled=false;
    UI.saveProject.disabled=false;
    UI.exportProject.disabled=false;
    UI.empty.style.display='none';
}

function disableEditor(){
    UI.play.disabled=true;
    UI.stop.disabled=true;
    UI.saveProject.disabled=true;
    UI.exportProject.disabled=true;
    UI.empty.style.display='block';
}

/* ------------------------------
   Timeline geometry
------------------------------ */

function visibleRange(){
    if(!APP.duration)return {start:0,end:0};

    const zoom=Number(UI.zoom.value)||1;
    const span=APP.duration/zoom;

    let start=APP.time-span/2;

    start=clamp(
        start,
        0,
        Math.max(0,APP.duration-span)
    );

    return {
        start,
        end:start+span
    };
}

function timePercent(time){
    const r=visibleRange();

    if(!r.end || r.end<=r.start)return 0;

    return clamp(
        (time-r.start)/(r.end-r.start)*100,
        0,
        100
    );
}

function clientToTime(clientX){
    const rect=UI.axis.getBoundingClientRect();
    const r=visibleRange();

    if(!rect.width)return 0;

    return clamp(
        r.start+
        ((clientX-rect.left)/rect.width)*(r.end-r.start),
        0,
        APP.duration
    );
}

/* ------------------------------
   Timeline rendering
------------------------------ */

function renderAxis(){
    UI.axis.innerHTML='';

    if(!APP.duration)return;

    const r=visibleRange();
    const span=r.end-r.start;
    const width=Math.max(1,UI.axis.clientWidth);

    const approx=span/Math.max(1,width/80);
    const steps=[
        .1,.25,.5,1,2,5,10,15,30,60,
        120,300,600
    ];

    const step=steps.find(v=>v>=approx)||600;
    const first=Math.ceil(r.start/step)*step;

    for(let t=first;t<=r.end+.0001;t+=step){
        const tick=document.createElement('div');
        tick.className='tick';
        tick.style.left=((t-r.start)/span*100)+'%';
        tick.textContent=shortTime(t);
        UI.axis.appendChild(tick);
    }
}

function renderTimeline(){
    UI.tracks.innerHTML='';

    if(!APP.duration || !APP.project){
        renderAxis();
        return;
    }

    const rows=[
        ['comment','コメント'],
        ['highlight','強調枠'],
        ['skip','スキップ']
    ];

    for(const [type,label] of rows){
        const row=document.createElement('div');
        row.className='track-row';

        const labelNode=document.createElement('div');
        labelNode.className='track-label';

        const dot=document.createElement('span');
        dot.className='type-dot';
        dot.style.background=typeColor(type);

        labelNode.append(
            dot,
            document.createTextNode(label)
        );

        const lane=document.createElement('div');
        lane.className='track-lane';

        const grid=document.createElement('div');
        grid.className='track-grid';
        grid.style.backgroundImage=
            'linear-gradient(to right,rgba(148,163,184,.09) 1px,transparent 1px)';
        grid.style.backgroundSize=
            Math.max(10,100/Math.max(1,APP.duration))+'% 100%';

        lane.appendChild(grid);

        for(const e of APP.project.elements){
            if(e.type===type){
                lane.appendChild(createBar(e));
            }
        }

        row.append(labelNode,lane);
        UI.tracks.appendChild(row);
    }

    renderAxis();
}

function createBar(e){
    const bar=document.createElement('div');
    bar.className='element-bar';
    bar.dataset.id=e.id;

    if(APP.selected.has(e.id)){
        bar.classList.add('selected');
    }

    const left=timePercent(e.start);
    const right=timePercent(e.end);

    bar.style.left=left+'%';
    bar.style.width=Math.max(.7,right-left)+'%';
    bar.style.color=e.color;
    bar.style.background=rgba(e.color,.20);

    const label=document.createElement('span');
    label.className='bar-label';
    label.textContent=
        `${e.name} ${fmt(e.start)}～${fmt(e.end)}`;

    const leftHandle=document.createElement('span');
    leftHandle.className='handle left';
    leftHandle.dataset.edge='left';

    const rightHandle=document.createElement('span');
    rightHandle.className='handle right';
    rightHandle.dataset.edge='right';

    bar.append(label,leftHandle,rightHandle);

    bar.addEventListener('pointerdown',startElementDrag);

    bar.addEventListener('click',ev=>{
        ev.stopPropagation();
        selectElement(e.id,ev.shiftKey);
    });

    bar.addEventListener('dblclick',ev=>{
        ev.stopPropagation();
        openElement(e.id);
    });

    bar.addEventListener('contextmenu',ev=>{
        ev.preventDefault();
        ev.stopPropagation();

        selectElement(e.id,false);
        openContext(ev.clientX,ev.clientY,e.id);
    });

    return bar;
}

/* ------------------------------
   Selection
------------------------------ */

function selectElement(id,additive=false){
    if(!getElement(id))return;

    if(!additive){
        APP.selected.clear();
    }

    if(additive && APP.selected.has(id)){
        APP.selected.delete(id);
    }else{
        APP.selected.add(id);
    }

    renderAll();
}

/* ------------------------------
   Video overlay
------------------------------ */

function isVisible(e){
    return APP.time>=e.start && APP.time<e.end;
}

function renderOverlay(){
    UI.overlay.querySelectorAll('.overlay-element').forEach(n=>n.remove());

    if(!canEdit())return;

    for(const e of APP.project.elements){
        if(!isVisible(e))continue;

        const node=document.createElement('div');
        node.className='overlay-element';
        node.dataset.id=e.id;

        if(APP.selected.has(e.id)){
            node.classList.add('selected');
        }

        node.style.left=e.x+'%';
        node.style.top=e.y+'%';
        node.style.width=e.w+'%';
        node.style.height=e.h+'%';
        node.style.color=e.color;

        if(e.type==='comment'){
            const text=document.createElement('div');
            text.className='overlay-comment';
            text.textContent=e.text||e.name;
            text.style.fontSize=e.fontSize+'px';
            node.appendChild(text);
        }

        if(e.type==='highlight'){
            const box=document.createElement('div');
            box.className='overlay-highlight '+e.shape;
            node.appendChild(box);
        }

        if(e.type==='skip'){
            const skip=document.createElement('div');
            skip.className='overlay-skip';
            skip.textContent='スキップ';
            node.appendChild(skip);
        }

        node.addEventListener('pointerdown',ev=>ev.stopPropagation());

        node.addEventListener('click',ev=>{
            ev.stopPropagation();
            selectElement(e.id,ev.shiftKey);
        });

        node.addEventListener('dblclick',ev=>{
            ev.stopPropagation();
            openElement(e.id);
        });

        node.addEventListener('contextmenu',ev=>{
            ev.preventDefault();
            ev.stopPropagation();

            selectElement(e.id,false);
            openContext(ev.clientX,ev.clientY,e.id);
        });

        UI.overlay.appendChild(node);
    }
}

/* ------------------------------
   Connection line on VIDEO
------------------------------ */

function renderConnections(){
    UI.connectionSvg.innerHTML='';

    if(!canEdit())return;

    const overlayRect=UI.overlay.getBoundingClientRect();

    for(const comment of APP.project.elements){
        if(comment.type!=='comment')continue;
        if(!comment.target)continue;

        const highlight=getElement(comment.target);

        if(!highlight || highlight.type!=='highlight')continue;
        if(!isVisible(comment) || !isVisible(highlight))continue;

        const commentNode=
            UI.overlay.querySelector(
                `.overlay-element[data-id="${CSS.escape(comment.id)}"]`
            );

        const highlightNode=
            UI.overlay.querySelector(
                `.overlay-element[data-id="${CSS.escape(highlight.id)}"]`
            );

        if(!commentNode || !highlightNode)continue;

        const a=commentNode.getBoundingClientRect();
        const b=highlightNode.getBoundingClientRect();

        const ax=a.left+a.width/2-overlayRect.left;
        const ay=a.top+a.height/2-overlayRect.top;

        const bx=b.left+b.width/2-overlayRect.left;
        const by=b.top+b.height/2-overlayRect.top;

        const dx=bx-ax;
        const bend=Math.max(30,Math.abs(dx)*.25);

        const path=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        path.classList.add('connection');

        if(APP.selected.has(comment.id) ||
           APP.selected.has(highlight.id)){
            path.classList.add('active');
        }

        path.setAttribute(
            'd',
            `M ${ax} ${ay}
             C ${ax+Math.sign(dx)*bend} ${ay},
               ${bx-Math.sign(dx)*bend} ${by},
               ${bx} ${by}`
        );

        UI.connectionSvg.appendChild(path);

        const dot=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );

        dot.classList.add('connection-dot');
        dot.setAttribute('cx',bx);
        dot.setAttribute('cy',by);
        dot.setAttribute('r','5');

        UI.connectionSvg.appendChild(dot);
    }
}

/* ------------------------------
   Playhead
------------------------------ */

function renderPlayhead(){
    if(!canEdit()){
        UI.playhead.style.display='none';
        return;
    }

    UI.playhead.style.display='block';

    const p=timePercent(APP.time);

    UI.playhead.style.left=
        `calc(var(--label) + (100% - var(--label)) * ${p/100})`;

    UI.playheadLabel.textContent=fmt(APP.time);
    UI.readout.textContent=
        `${fmt(APP.time)} / ${fmt(APP.duration)}`;
}

/* ------------------------------
   Seek
------------------------------ */

function seek(value){
    if(!canEdit())return;

    APP.time=clamp(Number(value)||0,0,APP.duration);

    if(Math.abs(UI.video.currentTime-APP.time)>.001){
        try{
            UI.video.currentTime=APP.time;
        }catch(_){}
    }

    renderAll();
}

/* ------------------------------
   Element drag
------------------------------ */

function startElementDrag(ev){
    const bar=ev.currentTarget;
    const e=getElement(bar.dataset.id);

    if(!e)return;

    ev.preventDefault();
    ev.stopPropagation();

    const handle=ev.target.closest('.handle');
    const lane=bar.parentElement;

    APP.drag={
        id:e.id,
        mode:handle?'resize':'move',
        edge:handle?.dataset.edge||'',
        startX:ev.clientX,
        laneWidth:Math.max(1,lane.getBoundingClientRect().width),
        originalStart:e.start,
        originalEnd:e.end
    };

    window.addEventListener('pointermove',moveElementDrag);
    window.addEventListener('pointerup',stopElementDrag,{once:true});
}

function moveElementDrag(ev){
    const d=APP.drag;

    if(!d)return;

    const e=getElement(d.id);

    if(!e)return;

    const r=visibleRange();
    const delta=
        (ev.clientX-d.startX)/
        d.laneWidth*
        (r.end-r.start);

    const minLength=.05;

    if(d.mode==='move'){
        const length=d.originalEnd-d.originalStart;

        e.start=clamp(
            d.originalStart+delta,
            0,
            APP.duration-length
        );

        e.end=e.start+length;
    }else if(d.edge==='left'){
        e.start=clamp(
            d.originalStart+delta,
            0,
            d.originalEnd-minLength
        );
    }else{
        e.end=clamp(
            d.originalEnd+delta,
            d.originalStart+minLength,
            APP.duration
        );
    }

    dirty();

    renderTimeline();
    renderOverlay();
    renderConnections();
    renderPlayhead();
}

function stopElementDrag(){
    APP.drag=null;
    window.removeEventListener('pointermove',moveElementDrag);
    renderAll();
}

/* ------------------------------
   Playhead drag
------------------------------ */

UI.playhead.addEventListener('pointerdown',ev=>{
    if(!canEdit())return;

    ev.preventDefault();
    ev.stopPropagation();

    APP.playheadDrag=true;
    seek(clientToTime(ev.clientX));
});

window.addEventListener('pointermove',ev=>{
    if(APP.playheadDrag){
        seek(clientToTime(ev.clientX));
    }
});

window.addEventListener('pointerup',()=>{
    APP.playheadDrag=false;
});

/* ------------------------------
   Add element
------------------------------ */

function addElement(type){
    if(!canEdit()){
        toast('先に動画を読み込んでください',true);
        return;
    }

    const start=clamp(
        APP.time,
        0,
        Math.max(0,APP.duration-.05)
    );

    const end=Math.min(
        APP.duration,
        start+3
    );

    const xy=APP.contextXY||{x:10,y:10};

    const e={
        id:uid('el'),
        type,
        name:
            type==='comment'?'コメント':
            type==='highlight'?'強調枠':
            'スキップ',
        text:type==='comment'?'コメント':'',
        start,
        end,
        x:clamp(xy.x,0,99),
        y:clamp(xy.y,0,99),
        w:type==='comment'?30:25,
        h:type==='comment'?15:20,
        color:typeColor(type),
        fontSize:28,
        shape:'square',
        target:''
    };

    APP.project.elements.push(e);
    APP.selected.clear();
    APP.selected.add(e.id);
    APP.contextXY=null;

    dirty();
    closeContext();
    renderAll();
    openElement(e.id);
}

/* ------------------------------
   Element modal
------------------------------ */

function openElement(id){
    const e=getElement(id);

    if(!e)return;

    APP.modalId=id;

    UI.modalTitle.textContent=
        e.type==='comment'?'コメント編集':
        e.type==='highlight'?'強調枠編集':
        'スキップ編集';

    UI.fieldName.value=e.name;
    UI.fieldText.value=e.text;
    UI.fieldShape.value=e.shape;
    UI.fieldStart.value=e.start;
    UI.fieldEnd.value=e.end;
    UI.fieldX.value=e.x;
    UI.fieldY.value=e.y;
    UI.fieldW.value=e.w;
    UI.fieldH.value=e.h;
    UI.fieldFont.value=e.fontSize;

    UI.commentTextField.classList.toggle(
        'hidden',
        e.type!=='comment'
    );

    UI.shapeField.classList.toggle(
        'hidden',
        e.type!=='highlight'
    );

    UI.fontField.classList.toggle(
        'hidden',
        e.type!=='comment'
    );

    UI.targetField.classList.toggle(
        'hidden',
        e.type!=='comment'
    );

    buildColors(e.color);
    buildTargets(e);

    UI.modal.style.display='flex';

    if(e.type==='comment'){
        setTimeout(()=>UI.fieldText.focus(),0);
    }
}

function buildColors(active){
    UI.colorGrid.innerHTML='';

    for(const color of APP.colors){
        const button=document.createElement('button');

        button.type='button';
        button.className='color-choice';
        button.style.background=color;
        button.dataset.color=color;

        if(color.toLowerCase()===String(active).toLowerCase()){
            button.classList.add('active');
        }

        button.onclick=()=>{
            UI.colorGrid
                .querySelectorAll('.active')
                .forEach(x=>x.classList.remove('active'));

            button.classList.add('active');
        };

        UI.colorGrid.appendChild(button);
    }
}

function buildTargets(comment){
    UI.fieldTarget.innerHTML=
        '<option value="">接続しない</option>';

    if(!APP.project)return;

    for(const e of APP.project.elements){
        if(e.type!=='highlight')continue;

        const option=document.createElement('option');
        option.value=e.id;
        option.textContent=e.name;

        if(e.id===comment.target){
            option.selected=true;
        }

        UI.fieldTarget.appendChild(option);
    }
}

function closeElementModal(){
    UI.modal.style.display='none';
    APP.modalId=null;
}

function saveElement(){
    const e=getElement(APP.modalId);

    if(!e)return;

    const start=Number(UI.fieldStart.value);
    const end=Number(UI.fieldEnd.value);

    if(
        !Number.isFinite(start) ||
        !Number.isFinite(end) ||
        start<0 ||
        end<=start ||
        end>APP.duration
    ){
        toast('開始・終了時間が不正です',true);
        return;
    }

    e.name=UI.fieldName.value.trim()||'要素';
    e.start=start;
    e.end=end;
    e.x=clamp(Number(UI.fieldX.value)||0,0,99);
    e.y=clamp(Number(UI.fieldY.value)||0,0,99);
    e.w=clamp(Number(UI.fieldW.value)||1,1,100);
    e.h=clamp(Number(UI.fieldH.value)||1,1,100);
    e.color=
        UI.colorGrid.querySelector('.active')?.dataset.color ||
        e.color;

    if(e.type==='comment'){
        e.text=UI.fieldText.value;
        e.fontSize=clamp(
            Number(UI.fieldFont.value)||28,
            8,
            100
        );

        const target=UI.fieldTarget.value;
        const targetElement=getElement(target);

        e.target=
            targetElement?.type==='highlight'
                ?target
                :'';
    }

    if(e.type==='highlight'){
        e.shape=UI.fieldShape.value;
    }

    dirty();
    closeElementModal();
    renderAll();
}

/* ------------------------------
   Delete
------------------------------ */

function deleteSelected(){
    if(!APP.project || !APP.selected.size)return;

    const ids=new Set(APP.selected);

    APP.project.elements=
        APP.project.elements.filter(e=>!ids.has(e.id));

    for(const e of APP.project.elements){
        if(e.type==='comment' && ids.has(e.target)){
            e.target='';
        }
    }

    APP.selected.clear();

    dirty();
    closeContext();
    renderAll();
}

/* ------------------------------
   Context menu
------------------------------ */

function openContext(x,y,id=null){
    APP.contextId=id;

    UI.menu.style.display='block';

    UI.menu.style.left=
        Math.min(x,window.innerWidth-225)+'px';

    UI.menu.style.top=
        Math.min(y,window.innerHeight-250)+'px';
}

function closeContext(){
    UI.menu.style.display='none';
    APP.contextId=null;
}

UI.menu.querySelectorAll('[data-add]').forEach(button=>{
    button.addEventListener('click',()=>{
        addElement(button.dataset.add);
    });
});

$('editContext').addEventListener('click',()=>{
    if(APP.contextId){
        openElement(APP.contextId);
    }

    closeContext();
});

$('deleteContext').addEventListener('click',()=>{
    if(APP.contextId){
        APP.selected.clear();
        APP.selected.add(APP.contextId);
        deleteSelected();
    }

    closeContext();
});

/* ------------------------------
   Video right click
------------------------------ */

UI.videoWrap.addEventListener('contextmenu',ev=>{
    ev.preventDefault();

    if(!canEdit())return;

    const rect=UI.video.getBoundingClientRect();

    if(
        ev.clientX<rect.left ||
        ev.clientX>rect.right ||
        ev.clientY<rect.top ||
        ev.clientY>rect.bottom
    ){
        return;
    }

    APP.contextXY={
        x:clamp(
            (ev.clientX-rect.left)/rect.width*100,
            0,
            99
        ),
        y:clamp(
            (ev.clientY-rect.top)/rect.height*100,
            0,
            99
        )
    };

    openContext(ev.clientX,ev.clientY,null);
});

/* ------------------------------
   Timeline right click
------------------------------ */

UI.content.addEventListener('contextmenu',ev=>{
    ev.preventDefault();

    if(!canEdit())return;

    const bar=ev.target.closest('.element-bar');

    if(bar){
        const id=bar.dataset.id;

        APP.selected.clear();
        APP.selected.add(id);

        openContext(ev.clientX,ev.clientY,id);
        renderAll();
        return;
    }

    if(
        ev.target.closest('.track-lane') ||
        ev.target.closest('.axis-track')
    ){
        seek(clientToTime(ev.clientX));

        APP.contextXY=null;
        openContext(ev.clientX,ev.clientY,null);
    }
});

/* ------------------------------
   Timeline click
------------------------------ */

UI.content.addEventListener('pointerdown',ev=>{
    if(
        ev.target.closest('.element-bar') ||
        ev.target.closest('.playhead')
    ){
        return;
    }

    if(
        ev.target.closest('.track-lane') ||
        ev.target.closest('.axis-track')
    ){
        seek(clientToTime(ev.clientX));
    }
});

/* ------------------------------
   Playback
------------------------------ */

async function togglePlay(){
    if(!canEdit())return;

    if(UI.video.paused){
        if(APP.time>=APP.duration-.001){
            APP.time=0;
            UI.video.currentTime=0;
        }

        try{
            await UI.video.play();
            UI.play.textContent='Ⅱ';
        }catch(error){
            if(error.name!=='AbortError'){
                toast('動画を再生できません',true);
            }
        }
    }else{
        UI.video.pause();
        UI.play.textContent='▶';
    }
}

function stopVideo(){
    UI.video.pause();
    UI.play.textContent='▶';
    seek(0);
}

UI.play.addEventListener('click',togglePlay);
UI.stop.addEventListener('click',stopVideo);

UI.video.addEventListener('timeupdate',()=>{
    if(!UI.video.paused){
        APP.time=UI.video.currentTime;
        renderAll();
    }
});

UI.video.addEventListener('play',()=>{
    UI.play.textContent='Ⅱ';
});

UI.video.addEventListener('pause',()=>{
    UI.play.textContent='▶';
});

UI.video.addEventListener('ended',()=>{
    UI.play.textContent='▶';
    APP.time=APP.duration;
    renderAll();
});

UI.video.addEventListener('loadedmetadata',()=>{
    const duration=Number(UI.video.duration);

    if(!Number.isFinite(duration) || duration<=0){
        toast('動画の長さを取得できません',true);
        return;
    }

    APP.duration=duration;
    APP.time=0;

    if(!APP.project){
        APP.project=createProject(UI.video.src);
    }

    APP.project.duration=duration;

    enableEditor();
    renderAll();
    clean();
});

/* ------------------------------
   Zoom
------------------------------ */

UI.zoom.addEventListener('input',()=>{
    UI.zoomValue.textContent=
        Number(UI.zoom.value).toFixed(1)+'×';

    renderAll();
});

/* ------------------------------
   LocalStorage
------------------------------ */

function storageKey(name){
    return 'video-annotation-project:'+name;
}

function saveLocal(){
    if(!APP.project)return;

    try{
        const copy=structuredClone(APP.project);

        copy.savedAt=new Date().toISOString();

        localStorage.setItem(
            storageKey(copy.name),
            JSON.stringify(copy)
        );

        clean();
        toast('保存しました');
    }catch(error){
        toast('保存に失敗しました：'+error.message,true);
    }
}

function openStorage(){
    UI.storageList.innerHTML='';

    const keys=Object.keys(localStorage)
        .filter(k=>k.startsWith('video-annotation-project:'))
        .sort();

    if(!keys.length){
        const empty=document.createElement('div');
        empty.style.padding='20px';
        empty.style.color='#94a3b8';
        empty.textContent='保存データはありません';
        UI.storageList.appendChild(empty);
    }

    for(const key of keys){
        try{
            const project=JSON.parse(
                localStorage.getItem(key)
            );

            const row=document.createElement('div');
            row.className='storage-item';

            const info=document.createElement('div');
            info.className='storage-info';

            const name=document.createElement('div');
            name.className='storage-name';
            name.textContent=project.name;

            const meta=document.createElement('div');
            meta.className='storage-meta';
            meta.textContent=
                `${project.videoName||'動画未設定'} / `+
                `${project.elements?.length||0}要素`;

            info.append(name,meta);

            const load=document.createElement('button');
            load.className='btn small';
            load.textContent='読込';

            load.onclick=()=>{
                loadProject(project);
                UI.storage.style.display='none';
            };

            const remove=document.createElement('button');
            remove.className='btn small danger';
            remove.textContent='削除';

            remove.onclick=()=>{
                if(confirm('この保存データを削除しますか？')){
                    localStorage.removeItem(key);
                    openStorage();
                }
            };

            row.append(info,load,remove);
            UI.storageList.appendChild(row);
        }catch(_){}
    }

    UI.storage.style.display='flex';
}

function loadProject(input){
    try{
        const p=normalizeProject(input);

        APP.project=p;
        APP.duration=
            Number(UI.video.duration)>0
                ?Number(UI.video.duration)
                :p.duration;

        APP.project.duration=APP.duration;
        APP.time=0;
        APP.selected.clear();

        if(APP.duration<=0){
            toast(
                'プロジェクトを読み込みました。動画を読み込んでください。',
                false
            );
        }else{
            enableEditor();
        }

        renderAll();
        clean();
    }catch(error){
        toast(
            'プロジェクトを読み込めません：'+error.message,
            true
        );
    }
}

/* ------------------------------
   JSON export/import
------------------------------ */

function exportProject(){
    if(!APP.project)return;

    const data=JSON.stringify(
        APP.project,
        null,
        2
    );

    const blob=new Blob(
        [data],
        {type:'application/json;charset=utf-8'}
    );

    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');

    a.href=url;
    a.download=
        (APP.project.name||'video-project')+'.json';

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(()=>{
        URL.revokeObjectURL(url);
    },1000);
}

UI.importProject.addEventListener('change',async ev=>{
    const file=ev.target.files?.[0];

    if(!file)return;

    try{
        const text=await file.text();
        loadProject(JSON.parse(text));
        toast('JSONを読み込みました');
    }catch(error){
        toast(
            'JSONを読み込めません：'+error.message,
            true
        );
    }

    ev.target.value='';
});

/* ------------------------------
   UI buttons
------------------------------ */

UI.openVideo.addEventListener(
    'click',
    ()=>UI.file.click()
);

UI.file.addEventListener(
    'change',
    ()=>loadVideo(UI.file.files?.[0])
);

UI.newProject.addEventListener(
    'click',
    resetApplication
);

UI.saveProject.addEventListener(
    'click',
    saveLocal
);

UI.loadProject.addEventListener(
    'click',
    openStorage
);

UI.exportProject.addEventListener(
    'click',
    exportProject
);

/* ------------------------------
   Modal
------------------------------ */

UI.modalClose.addEventListener(
    'click',
    closeElementModal
);

UI.modalCancel.addEventListener(
    'click',
    closeElementModal
);

UI.modalSave.addEventListener(
    'click',
    saveElement
);

UI.modalDelete.addEventListener(
    'click',
    ()=>{
        if(APP.modalId){
            APP.selected.clear();
            APP.selected.add(APP.modalId);
            deleteSelected();
        }

        closeElementModal();
    }
);

UI.storageClose.addEventListener(
    'click',
    ()=>UI.storage.style.display='none'
);

/* ------------------------------
   Keyboard
------------------------------ */

document.addEventListener('keydown',ev=>{
    const tag=document.activeElement?.tagName;

    if(ev.key==='Escape'){
        closeContext();
        closeElementModal();
        UI.storage.style.display='none';
    }

    if(
        (ev.key==='Delete' || ev.key==='Backspace') &&
        !['INPUT','TEXTAREA','SELECT'].includes(tag)
    ){
        ev.preventDefault();
        deleteSelected();
    }

    if(
        ev.key===' ' &&
        !['INPUT','TEXTAREA','SELECT'].includes(tag)
    ){
        ev.preventDefault();
        togglePlay();
    }
});

/* ------------------------------
   Resize / scroll
------------------------------ */

window.addEventListener(
    'resize',
    renderAll
);

UI.scroll.addEventListener(
    'scroll',
    renderConnections
);

/* ------------------------------
   Render all
------------------------------ */

function renderAll(){
    renderTimeline();
    renderOverlay();
    renderConnections();
    renderPlayhead();
}

/* ------------------------------
   Initial state
------------------------------ */

UI.zoomValue.textContent='1.0×';
renderAll();

</script>
</body>
</html>

<?php
declare(strict_types=1);

const APP_VERSION = '9.0.0';

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
        $project = json_decode($_POST['project'] ?? '', true);

        if (!is_array($project)) {
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
        echo json_encode($project, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
    --bg:#09111d;
    --panel:#101b2a;
    --panel2:#172438;
    --line:#334155;
    --text:#e5e7eb;
    --muted:#94a3b8;
    --blue:#2563eb;
    --red:#ef4444;
    --label:100px;
    --track:52px;
}

*{box-sizing:border-box}

html,body{
    margin:0;
    width:100%;
    height:100%;
    overflow:hidden;
    background:var(--bg);
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
}

button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}

.hidden{display:none!important}

.app{
    height:100vh;
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
    background:#070d17;
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

.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:var(--blue);border-color:#3b82f6}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}

.status{
    margin-left:auto;
    color:var(--muted);
    font-size:12px;
}

.file-input{display:none}

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
    background:#05090f;
    overflow:hidden;
}

.video-stage{
    position:relative;
    max-width:100%;
    max-height:100%;
    line-height:0;
}

#video{
    display:block;
    max-width:100%;
    max-height:100%;
    background:#000;
}

.overlay{
    position:absolute;
    inset:0;
    overflow:hidden;
}

.overlay-svg{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    z-index:1;
    pointer-events:none;
}

.connection{
    fill:none;
    stroke:#facc15;
    stroke-width:2.5;
    stroke-dasharray:7 5;
}

.connection.active{
    stroke:#fff;
    stroke-width:4;
}

.connection-dot{
    fill:#facc15;
    stroke:#111827;
    stroke-width:2;
}

.element{
    position:absolute;
    z-index:5;
    min-width:24px;
    min-height:18px;
    user-select:none;
    touch-action:none;
}

.element.selected{z-index:20}

.element-body{
    width:100%;
    height:100%;
    position:relative;
}

.element.selected .element-body{
    outline:2px solid #60a5fa;
    outline-offset:2px;
}

.comment-body{
    display:flex;
    align-items:center;
    justify-content:center;
    width:100%;
    height:100%;
    padding:5px 8px;
    background:#000b;
    border:1px solid currentColor;
    border-radius:4px;
    line-height:1.2;
    text-align:center;
    white-space:pre-wrap;
    overflow:hidden;
}

.highlight-body{
    width:100%;
    height:100%;
    border:3px solid currentColor;
}

.highlight-body.circle{border-radius:50%}
.highlight-body.ellipse{border-radius:50%}

.skip-body{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    border:2px dashed #fb923c;
    background:#f9731626;
    color:#fdba74;
    border-radius:4px;
    font-size:12px;
    font-weight:700;
}

.resize-handle{
    display:none;
    position:absolute;
    width:10px;
    height:10px;
    background:#fff;
    border:1px solid #2563eb;
    border-radius:2px;
    z-index:30;
}

.element.selected .resize-handle{display:block}

.rh-nw{left:-5px;top:-5px;cursor:nwse-resize}
.rh-n{left:50%;top:-5px;transform:translateX(-50%);cursor:ns-resize}
.rh-ne{right:-5px;top:-5px;cursor:nesw-resize}
.rh-e{right:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}
.rh-se{right:-5px;bottom:-5px;cursor:nwse-resize}
.rh-s{left:50%;bottom:-5px;transform:translateX(-50%);cursor:ns-resize}
.rh-sw{left:-5px;bottom:-5px;cursor:nesw-resize}
.rh-w{left:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}

.bottom{
    height:330px;
    flex:none;
    display:flex;
    border-top:1px solid var(--line);
    background:#0e1724;
}

.timeline{
    min-width:0;
    flex:1;
    display:flex;
    flex-direction:column;
}

.timeline-toolbar{
    height:43px;
    display:flex;
    align-items:center;
    gap:7px;
    padding:5px 8px;
    border-bottom:1px solid var(--line);
}

.time-readout{
    min-width:150px;
    font-variant-numeric:tabular-nums;
    color:#dbeafe;
}

.selection-info{
    font-size:11px;
    color:#94a3b8;
}

.zoom{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:6px;
    color:var(--muted);
    font-size:12px;
}

.zoom input{width:120px}

.timeline-scroll{
    position:relative;
    flex:1;
    min-height:0;
    overflow:auto;
}

.timeline-content{
    position:relative;
    min-width:700px;
    width:100%;
}

.axis{
    height:34px;
    position:sticky;
    top:0;
    z-index:50;
    display:flex;
    background:#131d29;
    border-bottom:1px solid var(--line);
}

.axis-label{
    width:var(--label);
    min-width:var(--label);
    display:flex;
    align-items:center;
    padding-left:8px;
    border-right:1px solid var(--line);
    font-size:12px;
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
    font-size:10px;
    color:#64748b;
    white-space:nowrap;
    pointer-events:none;
}

.track{
    height:var(--track);
    display:flex;
    border-bottom:1px solid #263445;
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
    top:8px;
    height:36px;
    min-width:8px;
    border:1px solid currentColor;
    border-radius:5px;
    display:flex;
    align-items:center;
    cursor:grab;
    touch-action:none;
    user-select:none;
}

.bar:active{cursor:grabbing}

.bar.selected{
    box-shadow:0 0 0 2px #60a5fa;
}

.bar-label{
    padding:0 7px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-size:11px;
    pointer-events:none;
}

.bar-handle{
    position:absolute;
    top:-1px;
    width:12px;
    height:38px;
    z-index:5;
    background:transparent;
}

.bar-handle.left{
    left:-6px;
    cursor:ew-resize;
}

.bar-handle.right{
    right:-6px;
    cursor:ew-resize;
}

.playhead{
    position:absolute;
    top:34px;
    bottom:0;
    width:3px;
    background:var(--red);
    z-index:80;
    cursor:ew-resize;
}

.playhead:before{
    content:"";
    position:absolute;
    top:-1px;
    left:-5px;
    width:13px;
    height:13px;
    background:var(--red);
    clip-path:polygon(0 0,100% 0,50% 100%);
}

.playhead-label{
    position:absolute;
    top:12px;
    left:6px;
    background:var(--red);
    padding:2px 4px;
    border-radius:3px;
    color:#fff;
    font-size:10px;
    white-space:nowrap;
}

.context{
    position:fixed;
    display:none;
    z-index:2000;
    min-width:210px;
    padding:5px;
    background:#172235;
    border:1px solid #475569;
    border-radius:6px;
    box-shadow:0 12px 30px #0008;
}

.context button{
    display:block;
    width:100%;
    padding:8px;
    border:0;
    background:none;
    color:var(--text);
    text-align:left;
    border-radius:4px;
}

.context button:hover{background:#293a52}

.context hr{
    border:0;
    border-top:1px solid #334155;
    margin:4px 0;
}

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
    width:min(570px,100%);
    max-height:90vh;
    display:flex;
    flex-direction:column;
    background:#182231;
    border:1px solid #475569;
    border-radius:8px;
    overflow:hidden;
}

.modal-head,.modal-foot{padding:12px 15px}
.modal-head{
    display:flex;
    justify-content:space-between;
    border-bottom:1px solid var(--line);
}

.modal-body{
    padding:15px;
    display:grid;
    gap:11px;
    overflow:auto;
}

.modal-foot{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid var(--line);
}

.field{display:grid;gap:5px}
.field label{font-size:12px;color:var(--muted)}

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
    min-height:90px;
    resize:vertical;
}

.two{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}

.colors{
    display:grid;
    grid-template-columns:repeat(8,1fr);
    gap:6px;
}

.color{
    height:28px;
    border:2px solid transparent;
    border-radius:4px;
}

.color.active{
    border-color:#fff;
    box-shadow:0 0 0 1px #60a5fa;
}

.storage{
    padding:0!important;
    gap:0!important;
}

.storage-item{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px;
    border-bottom:1px solid #334155;
}

.storage-info{
    min-width:0;
    flex:1;
}

.storage-name{
    font-weight:600;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.storage-meta{
    color:#64748b;
    font-size:11px;
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

.video-zoom{
    position:absolute;
    right:10px;
    bottom:10px;
    z-index:100;
    display:flex;
    gap:5px;
    align-items:center;
    padding:5px;
    background:#07101dcc;
    border:1px solid #334155;
    border-radius:5px;
}

.video-zoom input{width:110px}

@media(max-width:800px){
    :root{--label:88px}
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

    <div class="video-stage" id="stage">

        <video id="video" preload="metadata" playsinline></video>

        <div class="overlay" id="overlay">
            <svg class="overlay-svg" id="svg"></svg>
        </div>

        <div class="empty-video" id="empty">
            <strong>動画を読み込んでください</strong>
            <span>右クリックで注釈を追加できます</span>
        </div>

        <div class="video-zoom">
            <span>動画拡大</span>
            <input id="videoZoom" type="range" min="1" max="4" step=".05" value="1">
            <span id="videoZoomValue">100%</span>
        </div>

    </div>

</section>

<section class="bottom">

<section class="timeline">

<div class="timeline-toolbar">

    <button class="btn small" id="play" disabled>▶</button>
    <button class="btn small" id="stop" disabled>■</button>
    <button class="btn small" id="connect" disabled>🔗 接続</button>
    <button class="btn small" id="disconnect" disabled>接続解除</button>

    <span class="selection-info" id="selectionInfo"></span>
    <span class="time-readout" id="readout">00:00.000 / 00:00.000</span>

    <div class="zoom">
        <span>時間スケール</span>
        <input id="zoom" type="range" min="1" max="5" step=".1" value="1">
        <span id="zoomValue">1.0×</span>
    </div>

</div>

<div class="timeline-scroll" id="scroll">

<div class="timeline-content" id="content">

<div class="axis">
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

<div class="context" id="context">

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
    <input id="name">
</div>

<div class="field" id="textField">
    <label>コメント</label>
    <textarea id="text"></textarea>
</div>

<div class="field hidden" id="shapeField">
    <label>強調枠の形</label>
    <select id="shape">
        <option value="square">四角</option>
        <option value="circle">丸</option>
        <option value="ellipse">楕円</option>
    </select>
</div>

<div class="two">
    <div class="field">
        <label>開始時間</label>
        <input id="start" type="number" min="0" step=".001">
    </div>

    <div class="field">
        <label>終了時間</label>
        <input id="end" type="number" min="0" step=".001">
    </div>
</div>

<div class="two">
    <div class="field">
        <label>横位置 (%)</label>
        <input id="x" type="number" min="0" max="99" step=".1">
    </div>

    <div class="field">
        <label>縦位置 (%)</label>
        <input id="y" type="number" min="0" max="99" step=".1">
    </div>
</div>

<div class="two">
    <div class="field">
        <label>幅 (%)</label>
        <input id="w" type="number" min="1" max="100" step=".1">
    </div>

    <div class="field">
        <label>高さ (%)</label>
        <input id="h" type="number" min="1" max="100" step=".1">
    </div>
</div>

<div class="field" id="fontField">
    <label>文字サイズ</label>
    <input id="font" type="number" min="8" max="100">
</div>

<div class="field">
    <label>色</label>
    <div class="colors" id="colors"></div>
</div>

<div class="field" id="targetField">
    <label>接続する強調枠</label>
    <select id="target">
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

<div class="modal-body storage" id="storageList"></div>

</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

/* =========================================================
   基本
========================================================= */

const $ = id => document.getElementById(id);

const UI = new Proxy({}, {
    get(_, id) {
        const el = $(id);
        if (!el) throw new Error(`UI element not found: #${id}`);
        return el;
    }
});

const A = {
    version:'9.0.0',
    project:null,
    url:'',
    duration:0,
    time:0,
    selected:new Set(),
    modalId:null,
    contextId:null,
    contextXY:null,
    drag:null,
    playheadDrag:false,
    dirty:false,
    videoZoom:1,
    colors:[
        '#60a5fa','#22c55e','#f59e0b','#ef4444',
        '#c084fc','#14b8a6','#f97316','#e879f9',
        '#38bdf8','#a3e635','#fb7185','#facc15'
    ]
};

const typeColor = type => ({
    comment:'#60a5fa',
    highlight:'#22c55e',
    skip:'#f97316'
}[type] || '#60a5fa');

const get = id => A.project?.elements.find(e => e.id === id) || null;

const uid = () =>
    'el-' + Date.now().toString(36) + '-' +
    Math.random().toString(36).slice(2,7);

const clamp = (v,min,max) =>
    Math.max(min,Math.min(max,v));

function fmt(value){
    const v=Math.max(0,Number(value)||0);
    const m=Math.floor(v/60);
    const s=Math.floor(v%60);
    const ms=Math.floor((v%1)*1000);

    return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}.${String(ms).padStart(3,'0')}`;
}

function shortTime(v){
    return v < 60
        ? `${Number(v.toFixed(v%1 ? 1 : 0))}s`
        : `${String(Math.floor(v/60)).padStart(2,'0')}:${String(Math.floor(v%60)).padStart(2,'0')}`;
}

function rgba(hex,a){
    const n=parseInt(hex.slice(1),16);
    return `rgba(${n>>16},${n>>8&255},${n&255},${a})`;
}

function toast(message,error=false){
    UI.status.textContent=message;
    UI.toast.textContent=message;
    UI.toast.style.display='block';
    UI.toast.style.borderColor=error?'#ef4444':'#475569';

    clearTimeout(toast.timer);
    toast.timer=setTimeout(() => {
        UI.toast.style.display='none';
    },2400);
}

function markDirty(){
    A.dirty=true;
    UI.status.textContent='変更あり';
}

function markClean(){
    A.dirty=false;
    UI.status.textContent=A.project
        ? `編集中：${A.project.name}`
        : '動画を読み込んでください';
}

function editable(){
    return !!A.project && A.duration > 0;
}

/* =========================================================
   プロジェクト
========================================================= */

function newProject(name='新規プロジェクト'){
    return {
        version:A.version,
        name,
        videoName:'',
        duration:A.duration,
        elements:[]
    };
}

function normalize(src){
    if(!src || typeof src!=='object'){
        throw Error('プロジェクト形式が不正です');
    }

    const duration=Number(src.duration)||A.duration;

    const p={
        version:A.version,
        name:String(src.name||'プロジェクト'),
        videoName:String(src.videoName||''),
        duration,
        elements:[]
    };

    const validTypes=['comment','highlight','skip'];

    (Array.isArray(src.elements)?src.elements:[]).forEach(r=>{
        const type=validTypes.includes(r.type)?r.type:'comment';

        const start=clamp(
            Number(r.start)||0,
            0,
            duration
        );

        const end=clamp(
            Number(r.end)||Math.min(duration,start+3),
            start+.05,
            duration
        );

        p.elements.push({
            id:String(r.id||uid()),
            type,
            name:String(r.name||type),
            text:String(r.text||''),
            start,
            end,
            x:clamp(Number(r.x)||0,0,99),
            y:clamp(Number(r.y)||0,0,99),
            w:clamp(Number(r.w)||30,1,100),
            h:clamp(Number(r.h)||20,1,100),
            color:/^#[0-9a-f]{6}$/i.test(String(r.color||''))
                ? r.color
                : typeColor(type),
            fontSize:clamp(Number(r.fontSize)||28,8,100),
            shape:['square','circle','ellipse'].includes(r.shape)
                ? r.shape
                : 'square',
            target:String(r.target||'')
        });
    });

    const ids=new Set(p.elements.map(e=>e.id));

    p.elements.forEach(e=>{
        if(
            e.type!=='comment' ||
            !ids.has(e.target) ||
            !p.elements.some(t=>t.id===e.target && t.type==='highlight')
        ){
            e.target='';
        }
    });

    return p;
}

/* =========================================================
   動画
========================================================= */

function loadVideo(file){
    if(!file || !file.type.startsWith('video/')){
        toast('動画ファイルを選択してください',true);
        return;
    }

    if(A.url)URL.revokeObjectURL(A.url);

    A.url=URL.createObjectURL(file);
    A.project=newProject(file.name);
    A.project.videoName=file.name;
    A.duration=0;
    A.time=0;
    A.selected.clear();

    UI.video.src=A.url;
    UI.video.load();

    UI.status.textContent='動画を読み込んでいます…';
}

function reset(){
    UI.video.pause();

    if(A.url)URL.revokeObjectURL(A.url);

    A.url='';
    A.project=null;
    A.duration=0;
    A.time=0;
    A.selected.clear();
    A.dirty=false;
    A.videoZoom=1;

    UI.video.removeAttribute('src');
    UI.video.load();

    UI.videoZoom.value=1;
    UI.videoZoomValue.textContent='100%';

    UI.empty.style.display='flex';

    UI.play.disabled=true;
    UI.stop.disabled=true;
    UI.saveProject.disabled=true;
    UI.exportProject.disabled=true;

    render();
    UI.status.textContent='動画を読み込んでください';
}

UI.video.onloadedmetadata=()=>{
    const d=Number(UI.video.duration);

    if(!Number.isFinite(d)||d<=0){
        toast('動画の長さを取得できません',true);
        return;
    }

    A.duration=d;
    A.time=0;

    if(A.project)A.project.duration=d;

    UI.empty.style.display='none';

    UI.play.disabled=false;
    UI.stop.disabled=false;
    UI.saveProject.disabled=false;
    UI.exportProject.disabled=false;

    render();
    markClean();
};

UI.video.ontimeupdate=()=>{
    if(!UI.video.paused){
        A.time=UI.video.currentTime;
        renderPlayhead();
        renderOverlay();
        renderConnections();
    }
};

UI.video.onplay=()=>{
    UI.play.textContent='Ⅱ';
};

UI.video.onpause=()=>{
    UI.play.textContent='▶';
};

UI.video.onended=()=>{
    A.time=A.duration;
    render();
};

async function togglePlay(){
    if(!editable())return;

    if(UI.video.paused){
        if(A.time>=A.duration-.01)seek(0);

        try{
            await UI.video.play();
        }catch(e){
            if(e.name!=='AbortError'){
                toast('動画を再生できません',true);
            }
        }
    }else{
        UI.video.pause();
    }
}

function stop(){
    UI.video.pause();
    seek(0);
}

function seek(time){
    if(!editable())return;

    A.time=clamp(Number(time)||0,0,A.duration);

    if(Math.abs(UI.video.currentTime-A.time)>.002){
        UI.video.currentTime=A.time;
    }

    render();
}

/* =========================================================
   動画拡大
========================================================= */

UI.videoZoom.oninput=()=>{
    A.videoZoom=Number(UI.videoZoom.value);

    UI.videoZoomValue.textContent=
        Math.round(A.videoZoom*100)+'%';

    UI.video.style.transform=`scale(${A.videoZoom})`;
};

/* =========================================================
   タイムライン表示範囲
========================================================= */

function viewRange(){
    const zoom=Number(UI.zoom.value)||1;
    const span=A.duration/zoom;

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

function timePercent(time){
    const r=viewRange();

    return r.end===r.start
        ? 0
        : clamp(
            (time-r.start)/(r.end-r.start)*100,
            0,
            100
        );
}

function xToTime(x){
    const rect=UI.axis.getBoundingClientRect();
    const r=viewRange();

    if(!rect.width)return A.time;

    return clamp(
        r.start+(x-rect.left)/rect.width*(r.end-r.start),
        0,
        A.duration
    );
}

/* =========================================================
   タイムライン
========================================================= */

function renderAxis(){
    UI.axis.innerHTML='';

    if(!A.duration)return;

    const r=viewRange();
    const span=r.end-r.start;
    const width=Math.max(UI.axis.clientWidth,1);
    const approx=span/Math.max(width/80,1);

    const steps=[
        .1,.25,.5,1,2,5,10,15,30,60,
        120,300,600
    ];

    const step=steps.find(v=>v>=approx)||600;

    for(
        let t=Math.ceil(r.start/step)*step;
        t<=r.end+.001;
        t+=step
    ){
        const tick=document.createElement('div');

        tick.className='tick';
        tick.style.left=((t-r.start)/span*100)+'%';
        tick.textContent=shortTime(t);

        UI.axis.appendChild(tick);
    }
}

function createBar(e){
    const bar=document.createElement('div');

    bar.className='bar';
    bar.dataset.id=e.id;
    bar.style.left=timePercent(e.start)+'%';

    /*
     * 重要:
     * widthを「100 - left」のような計算にしない。
     * startとendの差だけで幅を決定する。
     */
    const width=Math.max(
        .25,
        timePercent(e.end)-timePercent(e.start)
    );

    bar.style.width=width+'%';

    bar.style.color=e.color;
    bar.style.background=rgba(e.color,.2);

    if(A.selected.has(e.id)){
        bar.classList.add('selected');
    }

    const label=document.createElement('span');
    label.className='bar-label';
    label.textContent=`${e.name} ${fmt(e.start)}～${fmt(e.end)}`;

    const left=document.createElement('span');
    const right=document.createElement('span');

    left.className='bar-handle left';
    right.className='bar-handle right';

    left.dataset.edge='left';
    right.dataset.edge='right';

    bar.append(label,left,right);

    bar.addEventListener('pointerdown',ev=>{
        timelinePointerDown(ev,e.id);
    });

    bar.addEventListener('dblclick',ev=>{
        ev.stopPropagation();
        openElement(e.id);
    });

    bar.addEventListener('contextmenu',ev=>{
        ev.preventDefault();
        ev.stopPropagation();
        select(e.id);
        showContext(ev.clientX,ev.clientY,e.id);
    });

    return bar;
}

function renderTimeline(){
    UI.tracks.innerHTML='';

    if(!editable()){
        renderAxis();
        return;
    }

    const types=[
        ['comment','コメント'],
        ['highlight','強調枠'],
        ['skip','スキップ']
    ];

    types.forEach(([type,label])=>{
        const row=document.createElement('div');
        row.className='track';

        const head=document.createElement('div');
        head.className='track-label';

        const dot=document.createElement('span');
        dot.className='dot';
        dot.style.background=typeColor(type);

        head.append(
            dot,
            document.createTextNode(label)
        );

        const lane=document.createElement('div');
        lane.className='lane';

        A.project.elements
            .filter(e=>e.type===type)
            .forEach(e=>{
                lane.appendChild(createBar(e));
            });

        row.append(head,lane);
        UI.tracks.appendChild(row);
    });

    renderAxis();
}

/* =========================================================
   ★ タイムラインの正しいドラッグ処理
========================================================= */

function timelinePointerDown(ev,id){
    if(ev.button!==0)return;

    ev.preventDefault();
    ev.stopPropagation();

    const element=get(id);
    if(!element)return;

    const handle=ev.target.closest('.bar-handle');

    /*
     * ここではrender()を呼ばない。
     * これが非常に重要。
     */
    if(!(ev.ctrlKey||ev.metaKey)){
        if(!A.selected.has(id)){
            A.selected.clear();
            A.selected.add(id);
        }
    }else{
        if(A.selected.has(id)){
            A.selected.delete(id);
        }else{
            A.selected.add(id);
        }
    }

    renderSelection();

    const lane=ev.currentTarget.parentElement;
    const rect=lane.getBoundingClientRect();
    const range=viewRange();

    A.drag={
        type:'timeline',
        id,
        mode:handle?'resize':'move',
        edge:handle?.dataset.edge||'',
        pointerStart:ev.clientX,
        start:element.start,
        end:element.end,
        laneWidth:rect.width,
        rangeSpan:range.end-range.start
    };

    window.addEventListener('pointermove',timelinePointerMove);
    window.addEventListener('pointerup',timelinePointerUp,{once:true});
}

function timelinePointerMove(ev){
    const d=A.drag;
    if(!d || d.type!=='timeline')return;

    const element=get(d.id);
    if(!element)return;

    const delta=
        (ev.clientX-d.pointerStart) /
        d.laneWidth *
        d.rangeSpan;

    const minDuration=.05;

    if(d.mode==='move'){
        const length=d.end-d.start;

        element.start=clamp(
            d.start+delta,
            0,
            A.duration-length
        );

        element.end=element.start+length;

    }else if(d.edge==='left'){

        /*
         * 左端だけを動かす。
         * 右端は絶対に変更しない。
         */
        element.start=clamp(
            d.start+delta,
            0,
            d.end-minDuration
        );

    }else if(d.edge==='right'){

        /*
         * ★今回の重要修正
         *
         * 右端を現在位置から再計算するのではなく、
         * ドラッグ開始位置からの差分だけ加算する。
         *
         * これでクリックしただけで最右端まで
         * 広がることがない。
         */
        element.end=clamp(
            d.end+delta,
            d.start+minDuration,
            A.duration
        );
    }

    markDirty();

    /*
     * ドラッグ中はDOMを破壊しない。
     * バー自身だけを直接更新する。
     */
    updateBar(element);
}

function timelinePointerUp(){
    window.removeEventListener(
        'pointermove',
        timelinePointerMove
    );

    A.drag=null;

    renderTimeline();
    renderOverlay();
    renderConnections();
    renderSelection();
}

function updateBar(e){
    const bar=UI.tracks.querySelector(
        `.bar[data-id="${CSS.escape(e.id)}"]`
    );

    if(!bar)return;

    bar.style.left=timePercent(e.start)+'%';
    bar.style.width=Math.max(
        .25,
        timePercent(e.end)-timePercent(e.start)
    )+'%';

    const label=bar.querySelector('.bar-label');

    if(label){
        label.textContent=
            `${e.name} ${fmt(e.start)}～${fmt(e.end)}`;
    }
}

/* =========================================================
   動画上の要素
========================================================= */

function handleMarkup(){
    return [
        'nw','n','ne','e',
        'se','s','sw','w'
    ].map(
        x=>`<span class="resize-handle rh-${x}" data-resize="${x}"></span>`
    ).join('');
}

function renderOverlay(){
    UI.overlay.querySelectorAll('.element').forEach(
        n=>n.remove()
    );

    if(!editable())return;

    A.project.elements
        .filter(e=>A.time>=e.start && A.time<e.end)
        .forEach(e=>{

        const node=document.createElement('div');

        node.className='element';
        node.dataset.id=e.id;

        node.style.left=e.x+'%';
        node.style.top=e.y+'%';
        node.style.width=e.w+'%';
        node.style.height=e.h+'%';
        node.style.color=e.color;

        if(A.selected.has(e.id)){
            node.classList.add('selected');
        }

        const body=document.createElement('div');

        if(e.type==='comment'){
            body.className='element-body comment-body';
            body.textContent=e.text||e.name;
            body.style.fontSize=e.fontSize+'px';

        }else if(e.type==='highlight'){
            body.className=
                'element-body highlight-body '+e.shape;

        }else{
            body.className='element-body skip-body';
            body.textContent='スキップ';
        }

        node.appendChild(body);

        if(A.selected.has(e.id)){
            node.insertAdjacentHTML(
                'beforeend',
                handleMarkup()
            );
        }

        node.addEventListener('pointerdown',ev=>{
            overlayPointerDown(ev,e.id);
        });

        node.addEventListener('dblclick',ev=>{
            ev.stopPropagation();
            openElement(e.id);
        });

        node.addEventListener('contextmenu',ev=>{
            ev.preventDefault();
            ev.stopPropagation();
            select(e.id);
            showContext(
                ev.clientX,
                ev.clientY,
                e.id
            );
        });

        UI.overlay.appendChild(node);
    });
}

/* =========================================================
   動画上の移動・リサイズ
========================================================= */

function overlayPointerDown(ev,id){
    if(ev.button!==0)return;

    ev.preventDefault();
    ev.stopPropagation();

    const e=get(id);
    if(!e)return;

    const resize=
        ev.target.closest('[data-resize]')
        ?.dataset.resize || '';

    if(!(ev.ctrlKey||ev.metaKey)){
        if(!A.selected.has(id)){
            A.selected.clear();
            A.selected.add(id);
        }
    }else{
        if(A.selected.has(id)){
            A.selected.delete(id);
        }else{
            A.selected.add(id);
        }
    }

    renderSelection();

    const rect=UI.overlay.getBoundingClientRect();

    A.drag={
        type:'overlay',
        id,
        resize,
        pointerX:ev.clientX,
        pointerY:ev.clientY,
        x:e.x,
        y:e.y,
        w:e.w,
        h:e.h,
        width:rect.width,
        height:rect.height
    };

    window.addEventListener(
        'pointermove',
        overlayPointerMove
    );

    window.addEventListener(
        'pointerup',
        overlayPointerUp,
        {once:true}
    );
}

function overlayPointerMove(ev){
    const d=A.drag;
    if(!d || d.type!=='overlay')return;

    const e=get(d.id);
    if(!e)return;

    const dx=(ev.clientX-d.pointerX)/d.width*100;
    const dy=(ev.clientY-d.pointerY)/d.height*100;

    const minW=.8;
    const minH=.8;

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
                d.x+d.w-minW
            );

            w=d.w-(x-d.x);
        }

        if(d.resize.includes('e')){
            w=clamp(
                d.w+dx,
                minW,
                100-d.x
            );
        }

        if(d.resize.includes('n')){
            y=clamp(
                d.y+dy,
                0,
                d.y+d.h-minH
            );

            h=d.h-(y-d.y);
        }

        if(d.resize.includes('s')){
            h=clamp(
                d.h+dy,
                minH,
                100-d.y
            );
        }

        e.x=x;
        e.y=y;
        e.w=w;
        e.h=h;
    }

    markDirty();

    const node=UI.overlay.querySelector(
        `.element[data-id="${CSS.escape(e.id)}"]`
    );

    if(node){
        node.style.left=e.x+'%';
        node.style.top=e.y+'%';
        node.style.width=e.w+'%';
        node.style.height=e.h+'%';
    }

    renderConnections();
}

function overlayPointerUp(){
    window.removeEventListener(
        'pointermove',
        overlayPointerMove
    );

    A.drag=null;

    renderOverlay();
    renderConnections();
}

/* =========================================================
   接続線
========================================================= */

function renderConnections(){
    UI.svg.innerHTML='';

    if(!editable())return;

    const overlayRect=UI.overlay.getBoundingClientRect();

    A.project.elements
        .filter(e=>e.type==='comment' && e.target)
        .forEach(comment=>{

        const highlight=get(comment.target);

        if(!highlight ||
           highlight.type!=='highlight'){
            return;
        }

        if(
            comment.start>A.time ||
            comment.end<=A.time ||
            highlight.start>A.time ||
            highlight.end<=A.time
        ){
            return;
        }

        const commentNode=
            UI.overlay.querySelector(
                `.element[data-id="${CSS.escape(comment.id)}"]`
            );

        const highlightNode=
            UI.overlay.querySelector(
                `.element[data-id="${CSS.escape(highlight.id)}"]`
            );

        if(!commentNode || !highlightNode)return;

        const a=commentNode.getBoundingClientRect();
        const b=highlightNode.getBoundingClientRect();

        const ax=a.left+a.width/2-overlayRect.left;
        const ay=a.top+a.height/2-overlayRect.top;

        const bx=b.left+b.width/2-overlayRect.left;
        const by=b.top+b.height/2-overlayRect.top;

        const direction=Math.sign(bx-ax)||1;
        const bend=Math.max(
            30,
            Math.abs(bx-ax)*.25
        );

        const path=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        path.classList.add('connection');

        if(
            A.selected.has(comment.id) ||
            A.selected.has(highlight.id)
        ){
            path.classList.add('active');
        }

        path.setAttribute(
            'd',
            `M${ax} ${ay}
             C${ax+direction*bend} ${ay},
              ${bx-direction*bend} ${by},
              ${bx} ${by}`
        );

        UI.svg.appendChild(path);

        const dot=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'circle'
        );

        dot.classList.add('connection-dot');
        dot.setAttribute('cx',bx);
        dot.setAttribute('cy',by);
        dot.setAttribute('r',5);

        UI.svg.appendChild(dot);
    });
}

/* =========================================================
   選択
========================================================= */

function select(id,multi=false){
    if(!get(id))return;

    if(!multi){
        A.selected.clear();
        A.selected.add(id);
    }else{
        if(A.selected.has(id)){
            A.selected.delete(id);
        }else{
            A.selected.add(id);
        }
    }

    render();
}

function renderSelection(){
    UI.selectionInfo.textContent=
        A.selected.size
            ? `${A.selected.size}個選択`
            : '';

    UI.connect.disabled=A.selected.size!==2;
    UI.disconnect.disabled=A.selected.size===0;
}

/* =========================================================
   再生ヘッド
========================================================= */

function renderPlayhead(){
    if(!editable()){
        UI.playhead.style.display='none';
        return;
    }

    UI.playhead.style.display='block';

    const p=timePercent(A.time);

    UI.playhead.style.left=
        `calc(var(--label) + (100% - var(--label)) * ${p/100})`;

    UI.playheadLabel.textContent=fmt(A.time);

    UI.readout.textContent=
        `${fmt(A.time)} / ${fmt(A.duration)}`;
}

/* =========================================================
   要素追加
========================================================= */

function addElement(type){
    if(!editable()){
        toast('先に動画を読み込んでください',true);
        return;
    }

    const pos=A.contextXY || {x:10,y:10};

    const start=A.time;
    const end=Math.min(
        A.duration,
        start+3
    );

    const e={
        id:uid(),
        type,
        name:
            type==='comment'
                ? 'コメント'
                : type==='highlight'
                    ? '強調枠'
                    : 'スキップ',
        text:type==='comment'?'コメント':'',
        start,
        end,
        x:clamp(pos.x,0,99),
        y:clamp(pos.y,0,99),
        w:type==='comment'?30:25,
        h:type==='comment'?15:20,
        color:typeColor(type),
        fontSize:28,
        shape:'square',
        target:''
    };

    A.project.elements.push(e);
    A.selected=new Set([e.id]);
    A.contextXY=null;

    closeContext();
    markDirty();
    render();
    openElement(e.id);
}

/* =========================================================
   編集モーダル
========================================================= */

function openElement(id){
    const e=get(id);
    if(!e)return;

    A.modalId=id;

    UI.modalTitle.textContent=
        e.type==='comment'
            ? 'コメント編集'
            : e.type==='highlight'
                ? '強調枠編集'
                : 'スキップ編集';

    UI.name.value=e.name;
    UI.text.value=e.text;
    UI.shape.value=e.shape;

    UI.start.value=e.start;
    UI.end.value=e.end;

    UI.x.value=e.x;
    UI.y.value=e.y;
    UI.w.value=e.w;
    UI.h.value=e.h;

    UI.font.value=e.fontSize;

    UI.textField.classList.toggle(
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

    UI.elementModal.style.display='flex';
}

function buildColors(active){
    UI.colors.innerHTML='';

    A.colors.forEach(color=>{
        const button=document.createElement('button');

        button.type='button';
        button.className='color';
        button.style.background=color;
        button.dataset.color=color;

        if(
            color.toLowerCase()===
            String(active).toLowerCase()
        ){
            button.classList.add('active');
        }

        button.onclick=()=>{
            UI.colors
                .querySelectorAll('.active')
                .forEach(x=>x.classList.remove('active'));

            button.classList.add('active');
        };

        UI.colors.appendChild(button);
    });
}

function buildTargets(comment){
    UI.target.innerHTML=
        '<option value="">接続しない</option>';

    A.project.elements
        .filter(e=>e.type==='highlight')
        .forEach(e=>{
            const option=document.createElement('option');

            option.value=e.id;
            option.textContent=e.name;
            option.selected=e.id===comment.target;

            UI.target.appendChild(option);
        });
}

function closeModal(){
    UI.elementModal.style.display='none';
    A.modalId=null;
}

function saveElement(){
    const e=get(A.modalId);
    if(!e)return;

    const start=Number(UI.start.value);
    const end=Number(UI.end.value);

    if(
        !Number.isFinite(start) ||
        !Number.isFinite(end) ||
        start<0 ||
        end<=start ||
        end>A.duration
    ){
        toast('開始・終了時間が不正です',true);
        return;
    }

    e.name=UI.name.value.trim()||'要素';
    e.start=start;
    e.end=end;

    e.x=clamp(Number(UI.x.value)||0,0,99);
    e.y=clamp(Number(UI.y.value)||0,0,99);

    e.w=clamp(Number(UI.w.value)||1,1,100);
    e.h=clamp(Number(UI.h.value)||1,1,100);

    e.color=
        UI.colors.querySelector('.active')
            ?.dataset.color || e.color;

    if(e.type==='comment'){
        e.text=UI.text.value;
        e.fontSize=clamp(
            Number(UI.font.value)||28,
            8,
            100
        );

        const target=get(UI.target.value);

        e.target=
            target?.type==='highlight'
                ? target.id
                : '';

    }else if(e.type==='highlight'){
        e.shape=UI.shape.value;
    }

    closeModal();
    markDirty();
    render();
}

/* =========================================================
   削除・接続
========================================================= */

function deleteSelected(){
    if(!A.project || !A.selected.size)return;

    const ids=new Set(A.selected);

    A.project.elements=
        A.project.elements.filter(
            e=>!ids.has(e.id)
        );

    A.project.elements.forEach(e=>{
        if(
            e.type==='comment' &&
            ids.has(e.target)
        ){
            e.target='';
        }
    });

    A.selected.clear();

    closeContext();
    closeModal();

    markDirty();
    render();
}

function connectSelected(){
    if(A.selected.size!==2){
        toast(
            'コメント1個と強調枠1個を選択してください',
            true
        );
        return;
    }

    const items=[...A.selected].map(get);

    const comment=
        items.find(e=>e?.type==='comment');

    const highlight=
        items.find(e=>e?.type==='highlight');

    if(!comment || !highlight){
        toast(
            'コメントと強調枠を1個ずつ選択してください',
            true
        );
        return;
    }

    comment.target=highlight.id;

    markDirty();
    render();
    toast('接続しました');
}

function disconnectSelected(){
    if(!A.selected.size)return;

    let changed=false;

    A.project.elements.forEach(e=>{
        if(
            e.type==='comment' &&
            A.selected.has(e.id) &&
            e.target
        ){
            e.target='';
            changed=true;
        }
    });

    if(changed){
        markDirty();
        render();
        toast('接続を解除しました');
    }
}

/* =========================================================
   コンテキストメニュー
========================================================= */

function showContext(x,y,id=null){
    A.contextId=id;

    UI.context.style.display='block';

    UI.context.style.left=
        Math.min(x,innerWidth-220)+'px';

    UI.context.style.top=
        Math.min(y,innerHeight-230)+'px';
}

function closeContext(){
    UI.context.style.display='none';
    A.contextId=null;
}

UI.context.querySelectorAll('[data-add]')
    .forEach(button=>{
        button.onclick=()=>{
            addElement(button.dataset.add);
        };
    });

UI.editContext.onclick=()=>{
    if(A.contextId){
        openElement(A.contextId);
    }

    closeContext();
};

UI.deleteContext.onclick=()=>{
    if(A.contextId){
        A.selected=new Set([A.contextId]);
        deleteSelected();
    }

    closeContext();
};

/* =========================================================
   ステージ右クリック
========================================================= */

UI.stage.oncontextmenu=ev=>{
    ev.preventDefault();

    if(!editable())return;

    const r=UI.video.getBoundingClientRect();

    if(
        ev.clientX<r.left ||
        ev.clientX>r.right ||
        ev.clientY<r.top ||
        ev.clientY>r.bottom
    ){
        return;
    }

    A.contextXY={
        x:(ev.clientX-r.left)/r.width*100,
        y:(ev.clientY-r.top)/r.height*100
    };

    showContext(
        ev.clientX,
        ev.clientY
    );
};

UI.content.oncontextmenu=ev=>{
    ev.preventDefault();

    if(!editable())return;

    const bar=ev.target.closest('.bar');

    if(bar){
        select(bar.dataset.id);
        showContext(
            ev.clientX,
            ev.clientY,
            bar.dataset.id
        );
        return;
    }

    if(
        ev.target.closest('.lane') ||
        ev.target.closest('.axis-track')
    ){
        seek(xToTime(ev.clientX));
        showContext(
            ev.clientX,
            ev.clientY
        );
    }
};

UI.content.onpointerdown=ev=>{
    if(
        ev.target.closest('.bar') ||
        ev.target.closest('.playhead')
    ){
        return;
    }

    if(
        ev.target.closest('.lane') ||
        ev.target.closest('.axis-track')
    ){
        seek(xToTime(ev.clientX));
    }
};

UI.overlay.onclick=ev=>{
    if(ev.target===UI.overlay){
        A.selected.clear();
        render();
    }
};

/* =========================================================
   再生ヘッド
========================================================= */

UI.playhead.onpointerdown=ev=>{
    if(!editable())return;

    ev.preventDefault();
    ev.stopPropagation();

    A.playheadDrag=true;
    seek(xToTime(ev.clientX));
};

window.addEventListener('pointermove',ev=>{
    if(A.playheadDrag){
        seek(xToTime(ev.clientX));
    }
});

window.addEventListener('pointerup',()=>{
    A.playheadDrag=false;
});

/* =========================================================
   保存
========================================================= */

function storageKey(name){
    return 'video-annotation-project:'+name;
}

function saveLocal(){
    if(!A.project)return;

    try{
        const project=JSON.parse(
            JSON.stringify(A.project)
        );

        project.savedAt=new Date().toISOString();

        localStorage.setItem(
            storageKey(project.name),
            JSON.stringify(project)
        );

        markClean();
        toast('保存しました');

    }catch(e){
        toast(
            '保存に失敗しました：'+e.message,
            true
        );
    }
}

function openStorage(){
    UI.storageList.innerHTML='';

    const keys=Object.keys(localStorage)
        .filter(k=>k.startsWith(
            'video-annotation-project:'
        ));

    if(!keys.length){
        UI.storageList.innerHTML=
            '<div style="padding:20px;color:#94a3b8">保存データはありません</div>';
    }else{
        keys.forEach(key=>{
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
                    `${project.videoName||'動画未設定'} / ${project.elements?.length||0}要素`;

                info.append(name,meta);

                const load=document.createElement('button');
                load.className='btn small';
                load.textContent='読込';

                load.onclick=()=>{
                    loadProject(project);
                    UI.storageModal.style.display='none';
                };

                const del=document.createElement('button');
                del.className='btn small danger';
                del.textContent='削除';

                del.onclick=()=>{
                    if(confirm('この保存データを削除しますか？')){
                        localStorage.removeItem(key);
                        openStorage();
                    }
                };

                row.append(info,load,del);
                UI.storageList.appendChild(row);

            }catch(_){}
        });
    }

    UI.storageModal.style.display='flex';
}

function loadProject(src){
    try{
        const project=normalize(src);

        A.project=project;

        const videoDuration=
            Number(UI.video.duration);

        A.duration=
            videoDuration>0
                ? videoDuration
                : project.duration;

        A.project.duration=A.duration;
        A.time=0;
        A.selected.clear();

        UI.empty.style.display=
            A.duration>0?'none':'flex';

        UI.play.disabled=A.duration<=0;
        UI.stop.disabled=A.duration<=0;
        UI.saveProject.disabled=A.duration<=0;
        UI.exportProject.disabled=A.duration<=0;

        render();
        markClean();

        if(A.duration<=0){
            toast(
                'プロジェクトを読み込みました。動画を読み込んでください'
            );
        }

    }catch(e){
        toast(
            'プロジェクトを読み込めません：'+e.message,
            true
        );
    }
}

function exportProject(){
    if(!A.project)return;

    const blob=new Blob(
        [JSON.stringify(A.project,null,2)],
        {type:'application/json'}
    );

    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');

    a.href=url;
    a.download=
        (A.project.name||'video-project')+
        '.json';

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(
        ()=>URL.revokeObjectURL(url),
        1000
    );
}

/* =========================================================
   UIイベント
========================================================= */

UI.openVideo.onclick=()=>{
    UI.videoFile.click();
};

UI.videoFile.onchange=()=>{
    loadVideo(
        UI.videoFile.files?.[0]
    );
};

UI.newProject.onclick=reset;
UI.saveProject.onclick=saveLocal;
UI.loadProject.onclick=openStorage;
UI.exportProject.onclick=exportProject;

UI.importProject.onchange=async ev=>{
    const file=ev.target.files?.[0];

    if(!file)return;

    try{
        const project=JSON.parse(
            await file.text()
        );

        loadProject(project);
        toast('JSONを読み込みました');

    }catch(e){
        toast(
            'JSONを読み込めません：'+e.message,
            true
        );
    }

    ev.target.value='';
};

UI.zoom.oninput=()=>{
    UI.zoomValue.textContent=
        Number(UI.zoom.value).toFixed(1)+'×';

    render();
};

UI.play.onclick=togglePlay;
UI.stop.onclick=stop;
UI.connect.onclick=connectSelected;
UI.disconnect.onclick=disconnectSelected;

UI.modalClose.onclick=closeModal;
UI.modalCancel.onclick=closeModal;
UI.modalSave.onclick=saveElement;
UI.modalDelete.onclick=deleteSelected;

UI.storageClose.onclick=()=>{
    UI.storageModal.style.display='none';
};

document.addEventListener('click',ev=>{
    if(!ev.target.closest('.context')){
        closeContext();
    }
});

document.addEventListener('keydown',ev=>{
    const tag=document.activeElement?.tagName;
    const editing=
        ['INPUT','TEXTAREA','SELECT'].includes(tag);

    if(ev.key==='Escape'){
        closeContext();
        closeModal();
        UI.storageModal.style.display='none';
    }

    if(
        (ev.key==='Delete'||ev.key==='Backspace') &&
        !editing
    ){
        ev.preventDefault();
        deleteSelected();
    }

    if(ev.key===' '&&!editing){
        ev.preventDefault();
        togglePlay();
    }

    if(
        (ev.ctrlKey||ev.metaKey) &&
        ev.key.toLowerCase()==='a' &&
        !editing
    ){
        ev.preventDefault();

        if(A.project){
            A.selected=new Set(
                A.project.elements.map(e=>e.id)
            );

            render();
        }
    }
});

window.addEventListener('resize',render);

UI.scroll.addEventListener(
    'scroll',
    renderConnections
);

/* =========================================================
   全体描画
========================================================= */

function render(){
    renderTimeline();
    renderOverlay();
    renderConnections();
    renderPlayhead();
    renderSelection();
}

UI.zoomValue.textContent='1.0×';
UI.videoZoomValue.textContent='100%';

render();
</script>

</body>
</html>

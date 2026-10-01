<?php
declare(strict_types=1);

const APP_VERSION = '5.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'version'=>APP_VERSION], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'export-json') {
        $project = $_POST['project'] ?? '';
        if ($project === '') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'project is required'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        json_decode($project, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'invalid json'], JSON_UNESCAPED_UNICODE);
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
    --bg:#0b1220;--panel:#111b2a;--panel2:#182438;--line:#334155;
    --text:#e5e7eb;--muted:#94a3b8;--blue:#2563eb;--red:#ef4444;
    --label:120px;--track:56px;
}
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:var(--bg);color:var(--text);
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
.hidden{display:none!important}

.app{height:100vh;display:flex;flex-direction:column}
.topbar{height:52px;flex:none;display:flex;align-items:center;gap:7px;padding:7px 10px;
background:#080f1b;border-bottom:1px solid var(--line)}
.brand{font-weight:700;margin-right:8px;white-space:nowrap}
.btn{border:1px solid #40516a;border-radius:6px;background:#243247;color:var(--text);padding:7px 11px}
.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:var(--blue);border-color:#3b82f6}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}
.status{margin-left:auto;color:var(--muted);font-size:12px;white-space:nowrap}
.file-input{display:none}

.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{min-height:260px;flex:1;display:flex;align-items:center;justify-content:center;
padding:12px;background:#050a11;overflow:hidden}
.video-wrap{position:relative;width:min(1000px,100%);height:100%;display:flex;
align-items:center;justify-content:center}
#video{display:block;max-width:100%;max-height:100%;background:#000}
.video-overlay{position:absolute;inset:0;pointer-events:none}
.overlay-element{position:absolute;min-width:30px;min-height:20px;pointer-events:auto;user-select:none}
.overlay-element.selected{outline:2px solid #60a5fa}
.overlay-element.multi-selected{outline:2px solid #fbbf24}
.overlay-text{width:100%;height:100%;display:flex;align-items:center;justify-content:center;
padding:4px;overflow:hidden;white-space:pre-wrap}
.overlay-box{width:100%;height:100%;border:3px solid currentColor;background:#ffffff08}
.overlay-skip{width:100%;height:100%;display:flex;align-items:center;justify-content:center;
border:2px dashed #f97316;color:#fdba74;background:#f973161f}
.empty-video{position:absolute;text-align:center;color:#64748b;line-height:1.8;pointer-events:none}
.empty-video strong{display:block;color:#94a3b8;font-size:18px}

.timeline-panel{height:330px;flex:none;display:flex;flex-direction:column;
border-top:1px solid var(--line);background:#0e1724}
.timeline-toolbar{height:43px;flex:none;display:flex;align-items:center;gap:7px;padding:5px 8px;
border-bottom:1px solid var(--line)}
.time-readout{min-width:120px;font-variant-numeric:tabular-nums;color:#dbeafe}
.selection-hint{color:#64748b;font-size:11px}
.zoom-control{margin-left:auto;display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12px}
.zoom-control input{width:120px}

.timeline-scroll{position:relative;flex:1;min-height:0;width:100%;overflow-y:auto;overflow-x:hidden}
.timeline-content{position:relative;width:100%;min-width:0}
.axis-row{position:sticky;top:0;z-index:30;height:34px;width:100%;display:flex;
background:#131d29;border-bottom:1px solid var(--line)}
.axis-label{width:var(--label);min-width:var(--label);flex:none;display:flex;align-items:center;
padding-left:8px;background:#131d29;border-right:1px solid var(--line);font-size:12px;
position:sticky;left:0;z-index:40}
.axis-track{position:relative;height:100%;flex:1;min-width:0}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #334155;padding:5px 0 0 3px;
font-size:10px;color:#64748b;white-space:nowrap;pointer-events:none}

.track-row{height:var(--track);width:100%;display:flex;border-bottom:1px solid #263445}
.track-label{width:var(--label);min-width:var(--label);flex:none;display:flex;align-items:center;
gap:5px;padding:0 8px;background:#111b27;border-right:1px solid var(--line);font-size:12px;
position:sticky;left:0;z-index:20}
.type-dot{width:8px;height:8px;border-radius:50%;flex:none}
.track-lane{position:relative;flex:1;min-width:0}
.track-grid{position:absolute;inset:0;pointer-events:none}
.element-bar{position:absolute;top:9px;height:38px;min-width:12px;border:1px solid currentColor;
border-radius:5px;display:flex;align-items:center;overflow:visible;cursor:grab;user-select:none;
touch-action:none;z-index:5}
.element-bar:active{cursor:grabbing}
.element-bar.selected{box-shadow:0 0 0 2px #60a5fa}
.element-bar.multi-selected{box-shadow:0 0 0 2px #fbbf24}
.bar-label{padding:0 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
font-size:11px;pointer-events:none}
.element-bar .handle{position:absolute;top:0;width:10px;height:100%;z-index:8}
.element-bar .handle.left{left:-5px;cursor:ew-resize}
.element-bar .handle.right{right:-5px;cursor:ew-resize}

.playhead{position:absolute;top:34px;bottom:0;width:2px;z-index:50;pointer-events:none;
background:var(--red);box-shadow:0 0 4px #ef444499}
.playhead::before{content:"";position:absolute;top:-1px;left:-5px;width:12px;height:12px;
background:var(--red);clip-path:polygon(0 0,100% 0,50% 100%)}
.playhead-label{position:absolute;top:12px;left:5px;padding:2px 4px;background:var(--red);
color:#fff;font-size:10px;white-space:nowrap;border-radius:3px}

.connection-svg{position:absolute;left:var(--label);right:0;top:34px;width:calc(100% - var(--label));
height:calc(100% - 34px);z-index:45;overflow:visible;pointer-events:none}
.connection-path{fill:none;stroke-width:2;pointer-events:stroke;cursor:pointer}
.connection-path.selected{stroke-width:4}
.connection-endpoint{pointer-events:none}
.connection-preview{fill:none;stroke:#fbbf24;stroke-width:2;stroke-dasharray:6 4}

.modal-backdrop{position:fixed;inset:0;z-index:3000;display:none;align-items:center;
justify-content:center;padding:20px;background:#000a}
.modal{width:min(520px,100%);background:#182231;border:1px solid #475569;border-radius:8px;overflow:hidden}
.modal-head,.modal-foot{padding:12px 15px;border-color:var(--line)}
.modal-head{display:flex;justify-content:space-between;border-bottom:1px solid var(--line)}
.modal-body{padding:15px;display:grid;gap:12px;max-height:75vh;overflow:auto}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--line)}
.field{display:grid;gap:5px}
.field label{font-size:12px;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;padding:7px;background:#0f1722;color:var(--text);
border:1px solid #40516a;border-radius:5px}
.field textarea{min-height:80px;resize:vertical}
.color-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:6px}
.color-choice{height:28px;border:2px solid transparent;border-radius:4px}
.color-choice.active{border-color:#fff;box-shadow:0 0 0 1px #60a5fa}

.context-menu{position:fixed;display:none;z-index:2000;min-width:210px;padding:5px;
background:#172235;border:1px solid #475569;border-radius:6px;box-shadow:0 12px 30px #0006}
.context-menu button{display:block;width:100%;padding:8px;border:0;background:transparent;
color:var(--text);text-align:left;border-radius:4px}
.context-menu button:hover{background:#293a52}

.storage-item{display:flex;align-items:center;gap:8px;padding:10px;border-bottom:1px solid #334155}
.storage-item .info{min-width:0;flex:1}
.storage-item .name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.storage-item .meta{font-size:11px;color:#64748b}
.toast{position:fixed;right:15px;bottom:15px;z-index:5000;display:none;padding:10px 14px;
background:#1e293b;border:1px solid #475569;border-radius:6px;box-shadow:0 10px 30px #0006}
@media(max-width:800px){:root{--label:95px}.brand{display:none}.timeline-panel{height:300px}}
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
    <button class="btn" id="loadProjectBtn">保存データ</button>
    <button class="btn" id="exportProjectBtn">書き出し</button>
    <label class="btn">プロジェクト読込<input class="file-input" id="importProjectInput" type="file" accept=".json,application/json"></label>
    <span class="status" id="statusText">動画を読み込んでください</span>
</header>

<main class="main">
<section class="video-area">
    <div class="video-wrap" id="videoWrap">
        <video id="video" preload="metadata" playsinline></video>
        <div class="video-overlay" id="videoOverlay"></div>
        <div class="empty-video" id="emptyVideo"><strong>動画を読み込んでください</strong>
        動画上で右クリックすると要素を追加できます</div>
    </div>
</section>

<section class="timeline-panel">
<div class="timeline-toolbar">
    <button class="btn small" id="playBtn" disabled>▶</button>
    <button class="btn small" id="stopBtn" disabled>■</button>
    <span class="time-readout" id="timeReadout">00:00.000 / 00:00.000</span>
    <span class="selection-hint" id="selectionHint"></span>
    <div class="zoom-control">
        <span>時間スケール</span>
        <input id="zoomRange" type="range" min="1" max="5" step=".1" value="1">
        <span id="zoomValue">1.0×</span>
    </div>
</div>

<div class="timeline-scroll" id="timelineScroll">
<div class="timeline-content" id="timelineContent">
    <div class="axis-row">
        <div class="axis-label">時間</div>
        <div class="axis-track" id="axisTrack"></div>
    </div>
    <div id="tracksContainer"></div>
    <svg class="connection-svg" id="connectionSvg" preserveAspectRatio="none"></svg>
    <svg class="connection-svg" id="connectionPreviewSvg" preserveAspectRatio="none"></svg>
    <div class="playhead" id="playhead"><span class="playhead-label" id="playheadLabel">00:00.000</span></div>
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

<div class="modal-backdrop" id="elementModal">
<div class="modal">
<div class="modal-head"><strong>要素を編集</strong><button class="btn small" id="elementModalClose">閉じる</button></div>
<div class="modal-body">
    <div class="field"><label>要素名</label><input id="elementName"></div>
    <div class="field" id="textField"><label>テキスト</label><textarea id="elementText"></textarea></div>
    <div class="field"><label>開始時間</label><input id="elementStart" type="number" min="0" step=".001"></div>
    <div class="field"><label>終了時間</label><input id="elementEnd" type="number" min="0" step=".001"></div>
    <div class="field"><label>横位置（%）</label><input id="elementX" type="number" min="0" max="100" step=".1"></div>
    <div class="field"><label>縦位置（%）</label><input id="elementY" type="number" min="0" max="100" step=".1"></div>
    <div class="field"><label>幅（%）</label><input id="elementW" type="number" min="1" max="100" step=".1"></div>
    <div class="field"><label>高さ（%）</label><input id="elementH" type="number" min="1" max="100" step=".1"></div>
    <div class="field" id="fontField"><label>文字サイズ</label><input id="elementFontSize" type="number" min="8" max="100"></div>
    <div class="field" id="weightField"><label>文字太さ</label><select id="elementFontWeight"><option value="400">標準</option><option value="700">太字</option><option value="900">極太</option></select></div>
    <div class="field"><label>色</label><div class="color-grid" id="colorGrid"></div></div>
</div>
<div class="modal-foot"><button class="btn" id="elementModalCancel">キャンセル</button><button class="btn primary" id="elementModalSave">保存</button></div>
</div>
</div>

<div class="modal-backdrop" id="storageModal">
<div class="modal">
<div class="modal-head"><strong>保存データ</strong><button class="btn small" id="storageClose">閉じる</button></div>
<div class="modal-body" id="storageList"></div>
</div>
</div>

<div class="modal-backdrop" id="nameModal">
<div class="modal">
<div class="modal-head"><strong>プロジェクト名</strong></div>
<div class="modal-body"><div class="field"><label>名前</label><input id="projectName"></div></div>
<div class="modal-foot"><button class="btn" id="nameCancel">キャンセル</button><button class="btn primary" id="nameOk">作成</button></div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $=id=>document.getElementById(id);
const els={
 video:$('video'),videoWrap:$('videoWrap'),overlay:$('videoOverlay'),empty:$('emptyVideo'),
 file:$('videoFile'),open:$('openVideoBtn'),newBtn:$('newProjectBtn'),save:$('saveProjectBtn'),
 load:$('loadProjectBtn'),exportBtn:$('exportProjectBtn'),import:$('importProjectInput'),
 status:$('statusText'),play:$('playBtn'),stop:$('stopBtn'),readout:$('timeReadout'),
 hint:$('selectionHint'),zoom:$('zoomRange'),zoomValue:$('zoomValue'),scroll:$('timelineScroll'),
 content:$('timelineContent'),axis:$('axisTrack'),tracks:$('tracksContainer'),svg:$('connectionSvg'),
 preview:$('connectionPreviewSvg'),playhead:$('playhead'),playheadLabel:$('playheadLabel'),
 menu:$('contextMenu'),modal:$('elementModal'),nameModal:$('nameModal'),storageModal:$('storageModal')
};

const state={
 project:null,duration:0,currentTime:0,videoUrl:'',videoBlob:null,
 selectedIds:new Set(),selectedConnectionId:null,contextElementId:null,contextConnectionId:null,
 drag:null,dirty:false,raf:0,modalElementId:null,pendingName:null,colors:['#60a5fa','#22c55e','#f59e0b','#ef4444','#c084fc','#14b8a6','#f97316','#e879f9','#38bdf8','#a3e635','#fb7185','#facc15']
};

function uid(p='id'){return p+'-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8)}
function clamp(v,a,b){return Math.max(a,Math.min(b,v))}
function time(v){v=Math.max(0,Number(v)||0);return `${String(Math.floor(v/60)).padStart(2,'0')}:${String(Math.floor(v%60)).padStart(2,'0')}.${String(Math.floor(v%1*1000)).padStart(3,'0')}`}
function shortTime(v){return v<60?v.toFixed(v%1?1:0)+'s':`${String(Math.floor(v/60)).padStart(2,'0')}:${String(Math.floor(v%60)).padStart(2,'0')}`}
function color(type){return type==='text'?'#60a5fa':type==='box'?'#22c55e':'#f97316'}
function rgba(hex,a){const n=parseInt(hex.slice(1),16);return `rgba(${n>>16},${n>>8&255},${n&255},${a})`}
function toast(s,error=false){els.status.textContent=s;const t=$('toast');t.textContent=s;t.style.display='block';t.style.borderColor=error?'#ef4444':'#475569';clearTimeout(toast.timer);toast.timer=setTimeout(()=>t.style.display='none',2200)}
function setStatus(s){els.status.textContent=s}
function markDirty(){state.dirty=true;setStatus('変更あり')}
function markClean(){state.dirty=false;setStatus(state.project?`編集中：${state.project.name}`:'動画を読み込んでください')}

function emptyProject(name='新規プロジェクト'){
 return {version:5,name,duration:state.duration,videoName:state.videoBlob?.name||'video',elements:[],connections:[]};
}
function get(id){return state.project?.elements.find(e=>e.id===id)||null}
function canEdit(){return !!state.project&&state.duration>0}
function visible(e){return state.currentTime>=e.start&&state.currentTime<e.end}

function normalizeProject(p){
 if(!p||typeof p!=='object')throw Error('プロジェクト形式が不正です');
 p.version=5;p.name=String(p.name||'プロジェクト');p.duration=Number(p.duration)||state.duration;
 p.elements=Array.isArray(p.elements)?p.elements:[];p.connections=Array.isArray(p.connections)?p.connections:[];
 p.elements=p.elements.map(e=>({
   id:String(e.id||uid('el')),type:['text','box','skip'].includes(e.type)?e.type:'text',
   name:String(e.name||'要素'),text:String(e.text||''),start:clamp(Number(e.start)||0,0,p.duration),
   end:clamp(Number(e.end)||Math.min(p.duration,(Number(e.start)||0)+2),.05,p.duration),
   x:clamp(Number(e.x)||10,0,99),y:clamp(Number(e.y)||10,0,99),
   w:clamp(Number(e.w)||30,1,100),h:clamp(Number(e.h)||15,1,100),
   color:e.color||color(e.type),fontSize:Number(e.fontSize)||28,fontWeight:String(e.fontWeight||'700')
 }));
 p.elements.forEach(e=>{if(e.end<=e.start)e.end=Math.min(p.duration,e.start+.05)});
 const ids=new Set(p.elements.map(e=>e.id));
 p.connections=p.connections.filter(c=>c&&c.from!==c.to&&ids.has(c.from)&&ids.has(c.to))
 .map(c=>({id:String(c.id||uid('c')),from:c.from,to:c.to,color:c.color||'#64748b',width:Number(c.width)||2}));
 return p;
}

function renderAll(){
 renderTimeline();renderOverlay();renderConnections();updatePlayhead();updateHint();
}

function timelineGeometry(){
 const r=els.axis.getBoundingClientRect();
 return {left:r.left,width:r.width};
}

function timeToX(t){
 const g=timelineGeometry();
 return g.left+clamp(t/state.duration,0,1)*g.width;
}
function xToTime(clientX){
 const g=timelineGeometry();
 return !g.width||!state.duration?0:clamp((clientX-g.left)/g.width*state.duration,0,state.duration);
}

function chooseStep(duration,width){
 const target=85;
 const approx=duration/Math.max(1,width/target);
 return [.1,.25,.5,1,2,5,10,15,30,60,120,300,600].find(x=>x>=approx)||600;
}

function renderAxis(){
 els.axis.innerHTML='';
 if(!state.duration)return;
 const w=els.axis.clientWidth,step=chooseStep(state.duration,w);
 for(let t=0;t<=state.duration+.0001;t+=step){
   const n=document.createElement('div');n.className='tick';n.style.left=`${t/state.duration*100}%`;n.textContent=shortTime(t);els.axis.appendChild(n);
 }
}

function renderTimeline(){
 els.tracks.innerHTML='';
 els.content.style.width='100%';

 const groups=[['text','テキスト'],['box','強調枠'],['skip','スキップ']];
 for(const [type,label] of groups){
   const row=document.createElement('div');row.className='track-row';
   const l=document.createElement('div');l.className='track-label';
   const dot=document.createElement('span');dot.className='type-dot';dot.style.background=color(type);
   l.append(dot,document.createTextNode(label));
   const lane=document.createElement('div');lane.className='track-lane';
   const grid=document.createElement('div');grid.className='track-grid';
   grid.style.backgroundImage='linear-gradient(to right,rgba(148,163,184,.09) 1px,transparent 1px)';
   grid.style.backgroundSize=`${Math.max(10,100/state.duration)}% 100%`;
   lane.appendChild(grid);
   (state.project?.elements||[]).filter(e=>e.type===type).forEach(e=>lane.appendChild(bar(e)));
   row.append(l,lane);els.tracks.appendChild(row);
 }
 renderAxis();
}

function bar(e){
 const b=document.createElement('div');b.className='element-bar';b.dataset.id=e.id;
 if(state.selectedIds.has(e.id))b.classList.add(state.selectedIds.size>1?'multi-selected':'selected');
 b.style.left=`${e.start/state.duration*100}%`;
 b.style.width=`${Math.max(.001,(e.end-e.start)/state.duration*100)}%`;
 b.style.color=e.color;b.style.background=rgba(e.color,.2);
 const label=document.createElement('span');label.className='bar-label';
 label.textContent=`${e.name} ${time(e.start)} ～ ${time(e.end)}`;
 const lh=document.createElement('span');lh.className='handle left';lh.dataset.edge='left';
 const rh=document.createElement('span');rh.className='handle right';rh.dataset.edge='right';
 b.append(label,lh,rh);
 b.addEventListener('pointerdown',beginDrag);
 b.addEventListener('click',ev=>{ev.stopPropagation();selectElement(e.id,ev.shiftKey)});
 b.addEventListener('dblclick',ev=>{ev.stopPropagation();openElement(e.id)});
 b.addEventListener('contextmenu',ev=>{
   ev.preventDefault();ev.stopPropagation();selectElement(e.id,ev.shiftKey);openMenu(ev.clientX,ev.clientY,e.id,null);
 });
 return b;
}

function selectElement(id,additive=false){
 const e=get(id);if(!e)return;
 if(!additive)state.selectedIds.clear();
 if(additive&&state.selectedIds.has(id))state.selectedIds.delete(id);else state.selectedIds.add(id);
 if(state.selectedIds.size===1&&!additive)seek(e.start);
 updateHint();renderTimeline();renderConnections();renderOverlay();
 if(state.selectedIds.size===2)connectSelected();
}

function connectSelected(){
 const ids=[...state.selectedIds];if(ids.length!==2)return;
 const [a,b]=ids;
 if(state.project.connections.some(c=>(c.from===a&&c.to===b)||(c.from===b&&c.to===a))){
   toast('この2要素はすでに接続されています');return;
 }
 const e=get(a);
 state.project.connections.push({id:uid('c'),from:a,to:b,color:e?.color||'#64748b',width:2});
 markDirty();renderConnections();toast('2要素を接続しました');
}

function updateHint(){els.hint.textContent=state.selectedIds.size?`${state.selectedIds.size}要素選択中`:''}

function renderOverlay(){
 els.overlay.innerHTML='';
 if(!canEdit())return;
 for(const e of state.project.elements){
   if(!visible(e))continue;
   const n=document.createElement('div');n.className='overlay-element';n.dataset.id=e.id;
   if(state.selectedIds.has(e.id))n.classList.add(state.selectedIds.size>1?'multi-selected':'selected');
   n.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%;color:${e.color}`;
   if(e.type==='text'){
     const t=document.createElement('div');t.className='overlay-text';t.textContent=e.text||e.name;
     t.style.fontSize=e.fontSize+'px';t.style.fontWeight=e.fontWeight;n.appendChild(t);
   }else if(e.type==='box'){
     const x=document.createElement('div');x.className='overlay-box';n.appendChild(x);
   }else{
     const x=document.createElement('div');x.className='overlay-skip';x.textContent='スキップ';n.appendChild(x);
   }
   n.addEventListener('pointerdown',ev=>ev.stopPropagation());
   n.addEventListener('click',ev=>{ev.stopPropagation();selectElement(e.id,ev.shiftKey)});
   n.addEventListener('dblclick',ev=>{ev.stopPropagation();openElement(e.id)});
   els.overlay.appendChild(n);
 }
}

function getBar(id){return els.tracks.querySelector(`.element-bar[data-id="${CSS.escape(id)}"]`)}

function anchor(id,side){
 const b=getBar(id);if(!b)return null;
 const br=b.getBoundingClientRect(),sr=els.content.getBoundingClientRect();
 return {x:(side==='right'?br.right:br.left)-sr.left,y:br.top+br.height/2-sr.top};
}

function renderConnections(){
 els.svg.innerHTML='';els.preview.innerHTML='';
 const cr=els.content.getBoundingClientRect();
 const w=cr.width,h=Math.max(els.content.scrollHeight,els.content.clientHeight);
 for(const s of [els.svg,els.preview]){
   s.setAttribute('width',w);s.setAttribute('height',h);s.setAttribute('viewBox',`0 0 ${w} ${h}`);
 }
 if(!state.project)return;
 for(const c of state.project.connections){
   const a=anchor(c.from,'right'),b=anchor(c.to,'left');if(a&&b)drawConnection(els.svg,a,b,c);
 }
}

function drawConnection(svg,a,b,c){
 const bend=Math.max(28,Math.min(100,Math.abs(b.x-a.x)*.35));
 const p=document.createElementNS('http://www.w3.org/2000/svg','path');
 p.classList.add('connection-path');if(state.selectedConnectionId===c.id)p.classList.add('selected');
 p.setAttribute('stroke',c.color||'#64748b');p.setAttribute('stroke-width',c.width||2);
 p.setAttribute('d',`M ${a.x} ${a.y} C ${a.x+bend} ${a.y},${b.x-bend} ${b.y},${b.x} ${b.y}`);
 p.addEventListener('click',ev=>{
   ev.stopPropagation();state.selectedConnectionId=c.id;state.selectedIds.clear();renderConnections();openMenu(ev.clientX,ev.clientY,null,c.id);
 });
 svg.appendChild(p);
 const end=document.createElementNS('http://www.w3.org/2000/svg','circle');
 end.setAttribute('cx',b.x);end.setAttribute('cy',b.y);end.setAttribute('r',4);
 end.setAttribute('fill',c.color||'#64748b');end.setAttribute('class','connection-endpoint');svg.appendChild(end);
}

function updatePlayhead(){
 if(!canEdit()){els.playhead.style.display='none';return}
 els.playhead.style.display='block';
 const g=timelineGeometry();
 const x=g.width*clamp(state.currentTime/state.duration,0,1);
 els.playhead.style.left=`calc(var(--label) + ${x}px)`;
 els.playheadLabel.textContent=time(state.currentTime);
 els.readout.textContent=`${time(state.currentTime)} / ${time(state.duration)}`;
}

function seek(t){
 state.currentTime=clamp(Number(t)||0,0,state.duration);
 try{els.video.currentTime=state.currentTime}catch(_){}
 renderOverlay();updatePlayhead();renderConnections();
}

function beginDrag(ev){
 const b=ev.currentTarget,e=get(b.dataset.id);if(!e)return;
 ev.stopPropagation();ev.preventDefault();
 const handle=ev.target.closest('.handle');
 const lane=b.parentElement;
 state.drag={id:e.id,mode:handle?'resize':'move',edge:handle?.dataset.edge||'',x:ev.clientX,
   original:{start:e.start,end:e.end},laneWidth:lane.getBoundingClientRect().width};
 b.setPointerCapture?.(ev.pointerId);
 window.addEventListener('pointermove',moveDrag);
 window.addEventListener('pointerup',endDrag,{once:true});
}
function moveDrag(ev){
 const d=state.drag;if(!d)return;const e=get(d.id);if(!e)return;
 const dt=(ev.clientX-d.x)/Math.max(1,d.laneWidth)*state.duration;
 const min=.05;
 if(d.mode==='move'){
   const len=d.original.end-d.original.start;
   e.start=clamp(d.original.start+dt,0,state.duration-len);e.end=e.start+len;
 }else if(d.edge==='left'){
   e.start=clamp(d.original.start+dt,0,d.original.end-min);
 }else{
   e.end=clamp(d.original.end+dt,d.original.start+min,state.duration);
 }
 markDirty();renderTimeline();renderOverlay();renderConnections();updatePlayhead();
}
function endDrag(){state.drag=null;window.removeEventListener('pointermove',moveDrag);renderAll()}

function openElement(id){
 const e=get(id);if(!e)return;
 state.modalElementId=id;
 $('elementName').value=e.name;$('elementText').value=e.text;
 $('elementStart').value=e.start;$('elementEnd').value=e.end;
 $('elementX').value=e.x;$('elementY').value=e.y;$('elementW').value=e.w;$('elementH').value=e.h;
 $('elementFontSize').value=e.fontSize;$('elementFontWeight').value=e.fontWeight;
 $('textField').classList.toggle('hidden',e.type!=='text');
 $('fontField').classList.toggle('hidden',e.type!=='text');
 $('weightField').classList.toggle('hidden',e.type!=='text');
 buildColors(e.color);els.modal.style.display='flex';
}
function buildColors(active){
 const g=$('colorGrid');g.innerHTML='';
 state.colors.forEach(c=>{const b=document.createElement('button');b.type='button';b.className='color-choice'+(c===active?' active':'');
 b.style.background=c;b.dataset.color=c;b.onclick=()=>{g.querySelectorAll('.active').forEach(x=>x.classList.remove('active'));b.classList.add('active')};g.appendChild(b)})
}
function closeElement(){els.modal.style.display='none';state.modalElementId=null}
function saveElement(){
 const e=get(state.modalElementId);if(!e)return;
 const start=Number($('elementStart').value),end=Number($('elementEnd').value);
 if(!Number.isFinite(start)||!Number.isFinite(end)||start<0||end<=start||end>state.duration){toast('開始・終了時間が不正です',true);return}
 e.name=$('elementName').value.trim()||'要素';e.text=$('elementText').value;
 e.start=start;e.end=end;e.x=clamp(Number($('elementX').value)||0,0,99);
 e.y=clamp(Number($('elementY').value)||0,0,99);e.w=clamp(Number($('elementW').value)||1,1,100);
 e.h=clamp(Number($('elementH').value)||1,1,100);e.fontSize=clamp(Number($('elementFontSize').value)||28,8,100);
 e.fontWeight=$('elementFontWeight').value;e.color=$('colorGrid .active')?.dataset.color||e.color;
 markDirty();closeElement();renderAll();
}

function addElement(type){
 if(!canEdit()){toast('先に動画を読み込んでください',true);return}
 const start=clamp(state.currentTime,0,state.duration-.05),end=Math.min(state.duration,start+3);
 const e={id:uid('el'),type,name:type==='text'?'テキスト':type==='box'?'強調枠':'スキップ',
 text:type==='text'?'テキスト':'' ,start,end,x:10,y:10,w:35,h:18,color:color(type),fontSize:28,fontWeight:'700'};
 state.project.elements.push(e);state.selectedIds.clear();state.selectedIds.add(e.id);markDirty();renderAll();openElement(e.id);
}

function deleteSelected(){
 if(state.selectedConnectionId){
   state.project.connections=state.project.connections.filter(c=>c.id!==state.selectedConnectionId);
   state.selectedConnectionId=null;markDirty();renderAll();return;
 }
 if(!state.selectedIds.size)return;
 const ids=new Set(state.selectedIds);
 state.project.elements=state.project.elements.filter(e=>!ids.has(e.id));
 state.project.connections=state.project.connections.filter(c=>!ids.has(c.from)&&!ids.has(c.to));
 state.selectedIds.clear();markDirty();renderAll();
}

function disconnectSelected(){
 if(state.selectedConnectionId){
   state.project.connections=state.project.connections.filter(c=>c.id!==state.selectedConnectionId);
   state.selectedConnectionId=null;markDirty();renderAll();return;
 }
 if(state.selectedIds.size===2){
   const [a,b]=[...state.selectedIds];
   state.project.connections=state.project.connections.filter(c=>!((c.from===a&&c.to===b)||(c.from===b&&c.to===a)));
   markDirty();renderAll();
 }
}

function openMenu(x,y,id,connection){
 state.contextElementId=id;state.contextConnectionId=connection;els.menu.style.display='block';
 els.menu.style.left=Math.min(x,innerWidth-220)+'px';els.menu.style.top=Math.min(y,innerHeight-240)+'px';
}
function closeMenu(){els.menu.style.display='none'}
function createAt(type){
 addElement(type);
 closeMenu();
}

function saveLocal(){
 if(!state.project)return;
 const p=structuredClone(state.project);p.savedAt=new Date().toISOString();
 const key='video-editor:'+p.name;
 localStorage.setItem(key,JSON.stringify(p));markClean();toast('保存しました');
}
function listStorage(){
 const list=$('storageList');list.innerHTML='';
 Object.keys(localStorage).filter(k=>k.startsWith('video-editor:')).sort().forEach(k=>{
   try{
     const p=JSON.parse(localStorage.getItem(k));const row=document.createElement('div');row.className='storage-item';
     row.innerHTML=`<div class="info"><div class="name"></div><div class="meta">${p.videoName||''} / ${p.elements?.length||0}要素</div></div>`;
     row.querySelector('.name').textContent=p.name;
     const load=document.createElement('button');load.className='btn small';load.textContent='読込';
     load.onclick=()=>{loadProject(p);els.storageModal.style.display='none'};
     row.appendChild(load);list.appendChild(row);
   }catch(_){}
 });
 els.storageModal.style.display='flex';
}
function loadProject(p){
 try{state.project=normalizeProject(structuredClone(p));state.duration=state.project.duration;state.selectedIds.clear();state.selectedConnectionId=null;state.currentTime=0;
 if(els.video.duration&&Number.isFinite(els.video.duration))state.duration=els.video.duration;
 state.project.duration=state.duration;enableEditor();renderAll();markClean();toast('プロジェクトを読み込みました')}
 catch(e){toast('プロジェクトを読み込めません：'+e.message,true)}
}
function enableEditor(){els.play.disabled=false;els.stop.disabled=false;els.save.disabled=false;els.empty.style.display='none'}
function disableEditor(){els.play.disabled=true;els.stop.disabled=true;els.save.disabled=true;els.empty.style.display='block';els.overlay.innerHTML='';els.tracks.innerHTML='';els.svg.innerHTML='';els.playhead.style.display='none'}

function handleVideo(file){
 if(!file||!file.type.startsWith('video/')){toast('動画ファイルを選択してください',true);return}
 if(state.videoUrl)URL.revokeObjectURL(state.videoUrl);
 state.videoBlob=file;state.videoUrl=URL.createObjectURL(file);state.project=emptyProject(file.name);
 state.duration=0;disableEditor();els.video.src=state.videoUrl;setStatus('動画を読み込んでいます…');
}
function newProject(){
 state.project=null;state.selectedIds.clear();state.selectedConnectionId=null;state.duration=0;state.currentTime=0;
 if(state.videoUrl){URL.revokeObjectURL(state.videoUrl);state.videoUrl=''}
 els.video.removeAttribute('src');els.video.load();disableEditor();setStatus('動画を読み込んでください');
}
function exportProject(){
 if(!state.project)return;
 const data=JSON.stringify(state.project,null,2),blob=new Blob([data],{type:'application/json'});
 const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=(state.project.name||'project')+'.json';a.click();
 setTimeout(()=>URL.revokeObjectURL(a.href),1000);
}

els.open.onclick=()=>els.file.click();
els.file.onchange=()=>handleVideo(els.file.files[0]);
$('newProjectBtn').onclick=newProject;
els.save.onclick=saveLocal;
els.load.onclick=listStorage;
els.exportBtn.onclick=exportProject;
$('importProjectInput').onchange=async ev=>{
 const f=ev.target.files[0];if(!f)return;
 try{loadProject(JSON.parse(await f.text()))}catch(e){toast('JSONを読み込めません：'+e.message,true)}
 ev.target.value='';
};

els.video.onloadedmetadata=()=>{
 state.duration=Number(els.video.duration);
 if(!state.project)state.project=emptyProject();
 state.project.duration=state.duration;state.currentTime=0;enableEditor();renderAll();markClean();
};
els.video.ontimeupdate=()=>{
 if(!els.video.paused){state.currentTime=els.video.currentTime;updatePlayhead();renderOverlay();renderConnections()}
};
els.video.onended=()=>{$('playBtn').textContent='▶';state.currentTime=state.duration;renderAll()};

els.play.onclick=async()=>{
 if(!canEdit())return;
 if(els.video.paused){try{await els.video.play();els.play.textContent='Ⅱ'}catch(e){toast('再生できません',true)}}
 else{els.video.pause();els.play.textContent='▶'}
};
els.stop.onclick=()=>{els.video.pause();els.play.textContent='▶';seek(0)};

els.zoom.oninput=()=>{
 els.zoomValue.textContent=Number(els.zoom.value).toFixed(1)+'×';
 // 重要：ここではtimeline-contentのwidthを変更しない。
 renderAxis();renderConnections();updatePlayhead();
};

els.content.addEventListener('pointerdown',ev=>{
 if(ev.target.closest('.element-bar')||ev.target.closest('.connection-path'))return;
 if(ev.target.closest('.axis-track')||ev.target.closest('.track-lane'))seek(xToTime(ev.clientX));
});

window.addEventListener('resize',()=>{renderAxis();renderConnections();updatePlayhead()});
els.scroll.addEventListener('scroll',()=>{renderConnections();updatePlayhead()});

$('ctxAddText').onclick=()=>createAt('text');
$('ctxAddBox').onclick=()=>createAt('box');
$('ctxAddSkip').onclick=()=>createAt('skip');
$('ctxEdit').onclick=()=>{if(state.contextElementId)openElement(state.contextElementId);closeMenu()};
$('ctxConnect').onclick=()=>{if(state.selectedIds.size===2)connectSelected();else toast('2要素を選択してください',true);closeMenu()};
$('ctxDisconnect').onclick=()=>{disconnectSelected();closeMenu()};
$('ctxDelete').onclick=()=>{deleteSelected();closeMenu()};

document.addEventListener('click',ev=>{if(!ev.target.closest('.context-menu'))closeMenu()});
document.addEventListener('keydown',ev=>{
 if(ev.key==='Escape'){closeMenu();closeElement()}
 if((ev.key==='Delete'||ev.key==='Backspace')&&!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName))deleteSelected();
 if(ev.key===' '&&!['INPUT','TEXTAREA'].includes(document.activeElement.tagName)){ev.preventDefault();els.play.click()}
});
$('elementModalClose').onclick=closeElement;
$('elementModalCancel').onclick=closeElement;
$('elementModalSave').onclick=saveElement;
$('storageClose').onclick=()=>els.storageModal.style.display='none';

els.content.addEventListener('dblclick',ev=>{
 if(ev.target.closest('.element-bar'))return;
});
els.videoWrap?.addEventListener('contextmenu',ev=>{
 ev.preventDefault();
 if(!canEdit())return;
 const rect=els.video.getBoundingClientRect();
 const x=clamp((ev.clientX-rect.left)/rect.width*100,0,99);
 const y=clamp((ev.clientY-rect.top)/rect.height*100,0,99);
 state.contextElementId=null;state.contextConnectionId=null;openMenu(ev.clientX,ev.clientY,null,null);
 state.pendingXY={x,y};
});
const oldCreate=addElement;
addElement=function(type){
 if(!canEdit()){toast('先に動画を読み込んでください',true);return}
 const start=clamp(state.currentTime,0,state.duration-.05),end=Math.min(state.duration,start+3);
 const xy=state.pendingXY||{x:10,y:10};state.pendingXY=null;
 const e={id:uid('el'),type,name:type==='text'?'テキスト':type==='box'?'強調枠':'スキップ',
 text:type==='text'?'テキスト':'',start,end,x:xy.x,y:xy.y,w:35,h:18,color:color(type),fontSize:28,fontWeight:'700'};
 state.project.elements.push(e);state.selectedIds.clear();state.selectedIds.add(e.id);markDirty();renderAll();openElement(e.id);
};

function animationLoop(){
 if(!state.drag)renderConnections();
 requestAnimationFrame(animationLoop);
}
animationLoop();
</script>
</body>
</html>

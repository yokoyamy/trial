<?php
declare(strict_types=1);

const APP_VERSION = '7.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'version'=>APP_VERSION], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'export-json') {
        $json = $_POST['project'] ?? '';
        $data = json_decode($json, true);

        if (!is_array($data)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'invalid json'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="video-project.json"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
 --bg:#0b1220;--panel:#111b2a;--panel2:#182438;--line:#334155;
 --text:#e5e7eb;--muted:#94a3b8;--blue:#2563eb;--red:#ef4444;
 --label:105px;--track:54px
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
.brand{font-weight:700;white-space:nowrap;margin-right:8px}
.btn{border:1px solid #40516a;border-radius:6px;background:#243247;color:var(--text);padding:7px 11px}
.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:var(--blue);border-color:#3b82f6}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}
.status{margin-left:auto;color:var(--muted);font-size:12px;white-space:nowrap}
.file-input{display:none}
.main{flex:1;min-height:0;display:flex;flex-direction:column}
.video-area{flex:1;min-height:250px;display:flex;align-items:center;justify-content:center;
 padding:12px;background:#050a11;overflow:hidden}
.video-wrap{position:relative;width:min(1100px,100%);height:100%;display:flex;align-items:center;justify-content:center}
#video{display:block;max-width:100%;max-height:100%;background:#000}
.video-overlay{position:absolute;inset:0;pointer-events:none}
.overlay-svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none;z-index:20}
.connection{fill:none;stroke:#facc15;stroke-width:2.5;stroke-dasharray:7 5;opacity:.95}
.connection.active{stroke:#fff;stroke-width:4}
.connection-dot{fill:#facc15;stroke:#111827;stroke-width:2}
.overlay-element{position:absolute;min-width:30px;min-height:20px;pointer-events:auto;user-select:none;z-index:10}
.overlay-element.selected{outline:2px solid #60a5fa;outline-offset:2px}
.overlay-comment{width:100%;height:100%;padding:6px 9px;display:flex;align-items:center;justify-content:center;
 text-align:center;white-space:pre-wrap;overflow:hidden;background:#0009;border:1px solid currentColor;border-radius:4px;line-height:1.25}
.overlay-highlight{width:100%;height:100%;border:3px solid currentColor}
.overlay-highlight.circle,.overlay-highlight.ellipse{border-radius:50%}
.overlay-skip{width:100%;height:100%;display:flex;align-items:center;justify-content:center;
 border:2px dashed currentColor;background:#f9731622;color:#fdba74;border-radius:4px;font-size:12px;font-weight:700}
.empty-video{position:absolute;text-align:center;color:#64748b;line-height:1.8;pointer-events:none}
.empty-video strong{display:block;color:#94a3b8;font-size:18px}
.bottom{height:330px;flex:none;display:flex;min-height:0;border-top:1px solid var(--line);background:#0e1724}
.timeline-panel{min-width:0;flex:1;display:flex;flex-direction:column}
.timeline-toolbar{height:43px;flex:none;display:flex;align-items:center;gap:7px;padding:5px 8px;border-bottom:1px solid var(--line)}
.time-readout{min-width:150px;font-variant-numeric:tabular-nums;color:#dbeafe}
.zoom-control{margin-left:auto;display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12px}
.zoom-control input{width:120px}
.timeline-scroll{position:relative;flex:1;min-height:0;overflow:auto}
.timeline-content{position:relative;min-width:700px;width:100%}
.axis-row{position:sticky;top:0;z-index:50;height:34px;display:flex;background:#131d29;border-bottom:1px solid var(--line)}
.axis-label{width:var(--label);min-width:var(--label);display:flex;align-items:center;padding-left:8px;
 background:#131d29;border-right:1px solid var(--line);font-size:12px;position:sticky;left:0;z-index:60}
.axis-track{position:relative;height:100%;flex:1}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #334155;padding:5px 0 0 3px;
 font-size:10px;color:#64748b;white-space:nowrap;pointer-events:none}
.track-row{height:var(--track);display:flex;border-bottom:1px solid #263445}
.track-label{width:var(--label);min-width:var(--label);display:flex;align-items:center;gap:6px;padding:0 8px;
 background:#111b27;border-right:1px solid var(--line);font-size:12px;position:sticky;left:0;z-index:20}
.type-dot{width:8px;height:8px;border-radius:50%;flex:none}
.track-lane{position:relative;flex:1;min-width:0}
.track-grid{position:absolute;inset:0;pointer-events:none}
.element-bar{position:absolute;top:8px;height:38px;min-width:14px;border:1px solid currentColor;border-radius:5px;
 display:flex;align-items:center;overflow:visible;cursor:grab;user-select:none;touch-action:none;z-index:5}
.element-bar:active{cursor:grabbing}
.element-bar.selected{box-shadow:0 0 0 2px #60a5fa}
.bar-label{padding:0 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;pointer-events:none}
.handle{position:absolute;top:0;width:10px;height:100%;z-index:8}
.handle.left{left:-5px;cursor:ew-resize}.handle.right{right:-5px;cursor:ew-resize}
.playhead{position:absolute;top:34px;bottom:0;width:3px;z-index:80;background:var(--red);cursor:ew-resize;touch-action:none}
.playhead::before{content:"";position:absolute;top:-1px;left:-5px;width:13px;height:13px;background:var(--red);
 clip-path:polygon(0 0,100% 0,50% 100%)}
.playhead-label{position:absolute;top:12px;left:6px;padding:2px 4px;background:var(--red);color:#fff;font-size:10px;white-space:nowrap;border-radius:3px}
.context-menu{position:fixed;display:none;z-index:2000;min-width:220px;padding:5px;background:#172235;
 border:1px solid #475569;border-radius:6px;box-shadow:0 12px 30px #0008}
.context-menu button{display:block;width:100%;padding:8px;border:0;background:transparent;color:var(--text);text-align:left;border-radius:4px}
.context-menu button:hover{background:#293a52}
.context-menu hr{border:0;border-top:1px solid #334155;margin:4px 0}
.modal-backdrop{position:fixed;inset:0;z-index:3000;display:none;align-items:center;justify-content:center;padding:20px;background:#000a}
.modal{width:min(570px,100%);max-height:90vh;display:flex;flex-direction:column;background:#182231;
 border:1px solid #475569;border-radius:8px;overflow:hidden}
.modal-head,.modal-foot{padding:12px 15px;border-color:var(--line)}
.modal-head{display:flex;justify-content:space-between;border-bottom:1px solid var(--line)}
.modal-body{padding:15px;display:grid;gap:11px;overflow:auto}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--line)}
.field{display:grid;gap:5px}.field label{font-size:12px;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;padding:7px;background:#0f1722;color:var(--text);
 border:1px solid #40516a;border-radius:5px}
.field textarea{min-height:90px;resize:vertical}
.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.color-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:6px}
.color-choice{height:28px;border:2px solid transparent;border-radius:4px}
.color-choice.active{border-color:#fff;box-shadow:0 0 0 1px #60a5fa}
.storage-list{padding:0!important;gap:0!important}
.storage-item{display:flex;align-items:center;gap:8px;padding:10px;border-bottom:1px solid #334155}
.storage-info{min-width:0;flex:1}.storage-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.storage-meta{color:#64748b;font-size:11px}
.toast{position:fixed;right:15px;bottom:15px;z-index:5000;display:none;padding:10px 14px;
 background:#1e293b;border:1px solid #475569;border-radius:6px;box-shadow:0 10px 30px #0006}
.selection-info{font-size:11px;color:#94a3b8;margin-left:5px}
@media(max-width:800px){:root{--label:90px}.brand{display:none}.bottom{height:300px}}
@media(max-width:600px){.status{display:none}}
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
 <label class="btn">JSON読込<input class="file-input" id="importProject" type="file" accept=".json,application/json"></label>
 <span class="status" id="status">動画を読み込んでください</span>
</header>

<main class="main">
<section class="video-area">
 <div class="video-wrap" id="videoWrap">
  <video id="video" preload="metadata" playsinline></video>
  <div class="video-overlay" id="videoOverlay"><svg class="overlay-svg" id="connectionSvg"></svg></div>
  <div class="empty-video" id="emptyVideo"><strong>動画を読み込んでください</strong>動画上で右クリックすると要素を追加できます</div>
 </div>
</section>

<section class="bottom">
<section class="timeline-panel">
 <div class="timeline-toolbar">
  <button class="btn small" id="play" disabled>▶</button>
  <button class="btn small" id="stop" disabled>■</button>
  <button class="btn small" id="connect" disabled>🔗 接続</button>
  <button class="btn small" id="disconnect" disabled>接続解除</button>
  <span class="selection-info" id="selectionInfo"></span>
  <span class="time-readout" id="readout">00:00.000 / 00:00.000</span>
  <div class="zoom-control"><span>時間スケール</span><input id="zoom" type="range" min="1" max="5" step=".1" value="1"><span id="zoomValue">1.0×</span></div>
 </div>
 <div class="timeline-scroll" id="timelineScroll">
  <div class="timeline-content" id="timelineContent">
   <div class="axis-row"><div class="axis-label">時間</div><div class="axis-track" id="axisTrack"></div></div>
   <div id="tracks"></div>
   <div class="playhead" id="playhead"><span class="playhead-label" id="playheadLabel">00:00.000</span></div>
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
 <div class="modal-head"><strong id="modalTitle">要素編集</strong><button class="btn small" id="modalClose">閉じる</button></div>
 <div class="modal-body">
  <div class="field"><label>要素名</label><input id="fieldName"></div>
  <div class="field" id="commentTextField"><label>コメント</label><textarea id="fieldText"></textarea></div>
  <div class="field hidden" id="shapeField"><label>強調枠の形</label><select id="fieldShape"><option value="square">四角</option><option value="circle">丸</option><option value="ellipse">楕円</option></select></div>
  <div class="two">
   <div class="field"><label>開始時間</label><input id="fieldStart" type="number" min="0" step=".001"></div>
   <div class="field"><label>終了時間</label><input id="fieldEnd" type="number" min="0" step=".001"></div>
  </div>
  <div class="two">
   <div class="field"><label>横位置 (%)</label><input id="fieldX" type="number" min="0" max="99" step=".1"></div>
   <div class="field"><label>縦位置 (%)</label><input id="fieldY" type="number" min="0" max="99" step=".1"></div>
  </div>
  <div class="two">
   <div class="field"><label>幅 (%)</label><input id="fieldW" type="number" min="1" max="100" step=".1"></div>
   <div class="field"><label>高さ (%)</label><input id="fieldH" type="number" min="1" max="100" step=".1"></div>
  </div>
  <div class="field" id="fontField"><label>文字サイズ</label><input id="fieldFont" type="number" min="8" max="100"></div>
  <div class="field"><label>色</label><div class="color-grid" id="colorGrid"></div></div>
  <div class="field" id="targetField"><label>接続する強調枠</label><select id="fieldTarget"><option value="">接続しない</option></select></div>
 </div>
 <div class="modal-foot"><button class="btn danger" id="modalDelete">削除</button><button class="btn" id="modalCancel">キャンセル</button><button class="btn primary" id="modalSave">保存</button></div>
</div>
</div>

<div class="modal-backdrop" id="storageModal">
<div class="modal">
 <div class="modal-head"><strong>保存データ</strong><button class="btn small" id="storageClose">閉じる</button></div>
 <div class="modal-body storage-list" id="storageList"></div>
</div>
</div>
<div class="toast" id="toast"></div>

<script>
'use strict';

const $=id=>document.getElementById(id);
const UI={
 video:$('video'),wrap:$('videoWrap'),overlay:$('videoOverlay'),svg:$('connectionSvg'),empty:$('emptyVideo'),
 file:$('videoFile'),open:$('openVideo'),newBtn:$('newProject'),save:$('saveProject'),load:$('loadProject'),
 export:$('exportProject'),import:$('importProject'),status:$('status'),play:$('play'),stop:$('stop'),
 connect:$('connect'),disconnect:$('disconnect'),selectionInfo:$('selectionInfo'),readout:$('readout'),
 zoom:$('zoom'),zoomValue:$('zoomValue'),scroll:$('timelineScroll'),content:$('timelineContent'),
 axis:$('axisTrack'),tracks:$('tracks'),playhead:$('playhead'),playheadLabel:$('playheadLabel'),
 menu:$('contextMenu'),modal:$('elementModal'),storage:$('storageModal'),storageList:$('storageList'),
 modalTitle:$('modalTitle'),name:$('fieldName'),text:$('fieldText'),shape:$('fieldShape'),
 start:$('fieldStart'),end:$('fieldEnd'),x:$('fieldX'),y:$('fieldY'),w:$('fieldW'),h:$('fieldH'),
 font:$('fieldFont'),colors:$('colorGrid'),target:$('fieldTarget'),textField:$('commentTextField'),
 shapeField:$('shapeField'),fontField:$('fontField'),targetField:$('targetField'),
 modalClose:$('modalClose'),modalDelete:$('modalDelete'),modalCancel:$('modalCancel'),modalSave:$('modalSave'),
 storageClose:$('storageClose'),toast:$('toast')
};

const APP={
 version:'7.0.0',project:null,duration:0,time:0,url:'',selected:new Set(),modalId:null,contextId:null,
 contextXY:null,dirty:false,drag:null,playheadDrag:false,
 colors:['#60a5fa','#22c55e','#f59e0b','#ef4444','#c084fc','#14b8a6','#f97316','#e879f9','#38bdf8','#a3e635','#fb7185','#facc15']
};

const uid=p=>p+'-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8);
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const fmt=s=>{s=Math.max(0,Number(s)||0);return String(Math.floor(s/60)).padStart(2,'0')+':'+String(Math.floor(s%60)).padStart(2,'0')+'.'+String(Math.floor(s%1*1000)).padStart(3,'0')};
const shortTime=s=>s<60?Number(s.toFixed(s%1?1:0))+'s':String(Math.floor(s/60)).padStart(2,'0')+':'+String(Math.floor(s%60)).padStart(2,'0');
const typeColor=t=>t==='comment'?'#60a5fa':t==='highlight'?'#22c55e':'#f97316';
const rgba=(h,a)=>{const n=parseInt(h.slice(1),16);return`rgba(${n>>16},${n>>8&255},${n&255},${a})`};
const get=id=>APP.project?.elements.find(e=>e.id===id)||null;

function toast(msg,error=false){
 UI.status.textContent=msg;UI.toast.textContent=msg;UI.toast.style.display='block';
 UI.toast.style.borderColor=error?'#ef4444':'#475569';clearTimeout(toast.t);
 toast.t=setTimeout(()=>UI.toast.style.display='none',2500);
}
function dirty(){APP.dirty=true;UI.status.textContent='変更あり'}
function clean(){APP.dirty=false;UI.status.textContent=APP.project?'編集中：'+APP.project.name:'動画を読み込んでください'}
function canEdit(){return!!APP.project&&APP.duration>0}

function project(name='新規プロジェクト'){
 return{version:APP.version,name,videoName:'',duration:APP.duration,elements:[]};
}

function normalize(input){
 if(!input||typeof input!=='object')throw Error('プロジェクト形式が不正です');
 const duration=Number(input.duration)||APP.duration;
 const p={version:APP.version,name:String(input.name||'プロジェクト'),videoName:String(input.videoName||''),duration,elements:[]};
 const types=['comment','highlight','skip'];
 for(const r of Array.isArray(input.elements)?input.elements:[]){
  const type=types.includes(r.type)?r.type:'comment';
  const start=clamp(Number(r.start)||0,0,duration);
  const end=clamp(Number(r.end)||Math.min(duration,start+3),start+.05,duration);
  p.elements.push({
   id:String(r.id||uid('el')),type,name:String(r.name||type),text:String(r.text||''),
   start,end,x:clamp(Number(r.x)||10,0,99),y:clamp(Number(r.y)||10,0,99),
   w:clamp(Number(r.w)||30,1,100),h:clamp(Number(r.h)||15,1,100),
   color:/^#[0-9a-f]{6}$/i.test(String(r.color||''))?r.color:typeColor(type),
   fontSize:clamp(Number(r.fontSize)||28,8,100),
   shape:['square','circle','ellipse'].includes(r.shape)?r.shape:'square',
   target:String(r.target||'')
  });
 }
 const ids=new Set(p.elements.map(e=>e.id));
 p.elements.forEach(e=>{if(e.type!=='comment'||!ids.has(e.target)||getIn(p,e.target)?.type!=='highlight')e.target=e.type==='comment'&&ids.has(e.target)&&getIn(p,e.target)?.type==='highlight'?e.target:''});
 return p;
}
const getIn=(p,id)=>p.elements.find(e=>e.id===id)||null;

function loadVideo(file){
 if(!file||!file.type.startsWith('video/'))return toast('動画ファイルを選択してください',true);
 if(APP.url)URL.revokeObjectURL(APP.url);
 APP.url=URL.createObjectURL(file);APP.project=project(file.name);APP.project.videoName=file.name;
 APP.duration=0;APP.time=0;APP.selected.clear();UI.video.src=APP.url;UI.video.load();UI.status.textContent='動画を読み込んでいます…';
}
function reset(){
 UI.video.pause();if(APP.url)URL.revokeObjectURL(APP.url);APP.url='';
 UI.video.removeAttribute('src');UI.video.load();APP.project=null;APP.duration=0;APP.time=0;APP.selected.clear();APP.dirty=false;
 UI.play.disabled=UI.stop.disabled=UI.save.disabled=UI.export.disabled=true;UI.empty.style.display='block';render();
 UI.status.textContent='動画を読み込んでください';
}

function enable(){
 UI.play.disabled=UI.stop.disabled=UI.save.disabled=UI.export.disabled=false;UI.empty.style.display='none';
}

function range(){
 const span=APP.duration/(Number(UI.zoom.value)||1),start=clamp(APP.time-span/2,0,Math.max(0,APP.duration-span));
 return{start,end:start+span};
}
function percent(t){
 const r=range();return r.end>r.start?clamp((t-r.start)/(r.end-r.start)*100,0,100):0;
}
function clientTime(x){
 const q=UI.axis.getBoundingClientRect(),r=range();
 return q.width?clamp(r.start+(x-q.left)/q.width*(r.end-r.start),0,APP.duration):0;
}

function renderAxis(){
 UI.axis.innerHTML='';if(!APP.duration)return;
 const r=range(),span=r.end-r.start,width=Math.max(1,UI.axis.clientWidth),approx=span/Math.max(1,width/80);
 const steps=[.1,.25,.5,1,2,5,10,15,30,60,120,300,600],step=steps.find(v=>v>=approx)||600;
 for(let t=Math.ceil(r.start/step)*step;t<=r.end+.001;t+=step){
  const n=document.createElement('div');n.className='tick';n.style.left=(t-r.start)/span*100+'%';n.textContent=shortTime(t);UI.axis.appendChild(n);
 }
}

function bar(e){
 const n=document.createElement('div');n.className='element-bar';n.dataset.id=e.id;
 if(APP.selected.has(e.id))n.classList.add('selected');
 n.style.left=percent(e.start)+'%';n.style.width=Math.max(.7,percent(e.end)-percent(e.start))+'%';
 n.style.color=e.color;n.style.background=rgba(e.color,.2);
 const label=document.createElement('span');label.className='bar-label';label.textContent=`${e.name} ${fmt(e.start)}～${fmt(e.end)}`;
 const l=document.createElement('span');l.className='handle left';l.dataset.edge='left';
 const r=document.createElement('span');r.className='handle right';r.dataset.edge='right';
 n.append(label,l,r);
 n.onpointerdown=startDrag;
 n.onclick=ev=>{ev.stopPropagation();select(e.id,ev.ctrlKey||ev.metaKey||ev.shiftKey)};
 n.ondblclick=ev=>{ev.stopPropagation();openElement(e.id)};
 n.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();select(e.id,false);context(ev.clientX,ev.clientY,e.id)};
 return n;
}

function renderTimeline(){
 UI.tracks.innerHTML='';if(!APP.project||!APP.duration){renderAxis();return}
 [['comment','コメント'],['highlight','強調枠'],['skip','スキップ']].forEach(([type,label])=>{
  const row=document.createElement('div');row.className='track-row';
  const lab=document.createElement('div');lab.className='track-label';
  const dot=document.createElement('span');dot.className='type-dot';dot.style.background=typeColor(type);
  lab.append(dot,document.createTextNode(label));
  const lane=document.createElement('div');lane.className='track-lane';
  const grid=document.createElement('div');grid.className='track-grid';
  grid.style.backgroundImage='linear-gradient(to right,rgba(148,163,184,.09) 1px,transparent 1px)';
  grid.style.backgroundSize=Math.max(10,100/Math.max(1,APP.duration))+'% 100%';
  lane.appendChild(grid);APP.project.elements.filter(e=>e.type===type).forEach(e=>lane.appendChild(bar(e)));
  row.append(lab,lane);UI.tracks.appendChild(row);
 });
 renderAxis();
}

function renderOverlay(){
 UI.overlay.querySelectorAll('.overlay-element').forEach(n=>n.remove());
 if(!canEdit())return;
 APP.project.elements.filter(e=>APP.time>=e.start&&APP.time<e.end).forEach(e=>{
  const n=document.createElement('div');n.className='overlay-element';n.dataset.id=e.id;
  if(APP.selected.has(e.id))n.classList.add('selected');
  n.style.cssText+=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%;color:${e.color};`;
  if(e.type==='comment'){
   const x=document.createElement('div');x.className='overlay-comment';x.textContent=e.text||e.name;x.style.fontSize=e.fontSize+'px';n.appendChild(x);
  }else if(e.type==='highlight'){
   const x=document.createElement('div');x.className='overlay-highlight '+e.shape;n.appendChild(x);
  }else{
   const x=document.createElement('div');x.className='overlay-skip';x.textContent='スキップ';n.appendChild(x);
  }
  n.onpointerdown=ev=>ev.stopPropagation();
  n.onclick=ev=>{ev.stopPropagation();select(e.id,ev.ctrlKey||ev.metaKey||ev.shiftKey)};
  n.ondblclick=ev=>{ev.stopPropagation();openElement(e.id)};
  n.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();select(e.id,false);context(ev.clientX,ev.clientY,e.id)};
  UI.overlay.appendChild(n);
 });
}

function renderConnections(){
 UI.svg.innerHTML='';
 if(!canEdit())return;
 const overlayRect=UI.overlay.getBoundingClientRect();
 APP.project.elements.filter(e=>e.type==='comment'&&e.target).forEach(comment=>{
  const target=get(comment.target);
  if(!target||target.type!=='highlight'||comment.start>APP.time||comment.end<=APP.time||target.start>APP.time||target.end<=APP.time)return;
  const a=UI.overlay.querySelector(`[data-id="${CSS.escape(comment.id)}"]`);
  const b=UI.overlay.querySelector(`[data-id="${CSS.escape(target.id)}"]`);
  if(!a||!b)return;
  const ar=a.getBoundingClientRect(),br=b.getBoundingClientRect();
  const ax=ar.left+ar.width/2-overlayRect.left,ay=ar.top+ar.height/2-overlayRect.top;
  const bx=br.left+br.width/2-overlayRect.left,by=br.top+br.height/2-overlayRect.top;
  const dx=bx-ax,bend=Math.max(30,Math.abs(dx)*.25);
  const path=document.createElementNS('http://www.w3.org/2000/svg','path');
  path.classList.add('connection');
  if(APP.selected.has(comment.id)||APP.selected.has(target.id))path.classList.add('active');
  path.setAttribute('d',`M ${ax} ${ay} C ${ax+Math.sign(dx||1)*bend} ${ay},${bx-Math.sign(dx||1)*bend} ${by},${bx} ${by}`);
  UI.svg.appendChild(path);
  const dot=document.createElementNS('http://www.w3.org/2000/svg','circle');
  dot.classList.add('connection-dot');dot.setAttribute('cx',bx);dot.setAttribute('cy',by);dot.setAttribute('r','5');UI.svg.appendChild(dot);
 });
}

function renderPlayhead(){
 if(!canEdit()){UI.playhead.style.display='none';return}
 UI.playhead.style.display='block';UI.playhead.style.left=`calc(var(--label) + (100% - var(--label)) * ${percent(APP.time)/100})`;
 UI.playheadLabel.textContent=fmt(APP.time);UI.readout.textContent=`${fmt(APP.time)} / ${fmt(APP.duration)}`;
}
function renderSelection(){
 const count=APP.selected.size;UI.selectionInfo.textContent=count?`${count}個選択`:'';
 UI.connect.disabled=count<2;UI.disconnect.disabled=!count;
}
function render(){renderTimeline();renderOverlay();renderConnections();renderPlayhead();renderSelection()}

function select(id,add=false){
 if(!get(id))return;
 if(!add)APP.selected.clear();
 if(add&&APP.selected.has(id))APP.selected.delete(id);else APP.selected.add(id);
 render();
}

function seek(t){
 if(!canEdit())return;APP.time=clamp(Number(t)||0,0,APP.duration);
 try{if(Math.abs(UI.video.currentTime-APP.time)>.001)UI.video.currentTime=APP.time}catch(_){}
 render();
}

function startDrag(ev){
 const e=get(ev.currentTarget.dataset.id);if(!e)return;
 ev.preventDefault();ev.stopPropagation();
 const handle=ev.target.closest('.handle'),lane=ev.currentTarget.parentElement,r=range();
 APP.drag={id:e.id,mode:handle?'resize':'move',edge:handle?.dataset.edge||'',x:ev.clientX,width:Math.max(1,lane.getBoundingClientRect().width),
  start:e.start,end:e.end,span:r.end-r.start};
 window.addEventListener('pointermove',moveDrag);window.addEventListener('pointerup',stopDrag,{once:true});
}
function moveDrag(ev){
 const d=APP.drag,e=get(d?.id);if(!d||!e)return;
 const delta=(ev.clientX-d.x)/d.width*d.span;
 if(d.mode==='move'){const len=d.end-d.start;e.start=clamp(d.start+delta,0,APP.duration-len);e.end=e.start+len}
 else if(d.edge==='left')e.start=clamp(d.start+delta,0,d.end-.05);
 else e.end=clamp(d.end+delta,e.start+.05,APP.duration);
 dirty();render();
}
function stopDrag(){APP.drag=null;window.removeEventListener('pointermove',moveDrag);render()}

UI.playhead.onpointerdown=ev=>{if(canEdit()){ev.preventDefault();APP.playheadDrag=true;seek(clientTime(ev.clientX))}};
window.addEventListener('pointermove',ev=>{if(APP.playheadDrag)seek(clientTime(ev.clientX))});
window.addEventListener('pointerup',()=>APP.playheadDrag=false);

function add(type){
 if(!canEdit())return toast('先に動画を読み込んでください',true);
 const start=APP.time,end=Math.min(APP.duration,start+3),xy=APP.contextXY||{x:10,y:10};
 const e={id:uid('el'),type,name:type==='comment'?'コメント':type==='highlight'?'強調枠':'スキップ',
  text:type==='comment'?'コメント':'',start,end,x:clamp(xy.x,0,99),y:clamp(xy.y,0,99),
  w:type==='comment'?30:25,h:type==='comment'?15:20,color:typeColor(type),fontSize:28,shape:'square',target:''};
 APP.project.elements.push(e);APP.selected.clear();APP.selected.add(e.id);APP.contextXY=null;dirty();closeContext();render();openElement(e.id);
}

function openElement(id){
 const e=get(id);if(!e)return;APP.modalId=id;
 UI.modalTitle.textContent=e.type==='comment'?'コメント編集':e.type==='highlight'?'強調枠編集':'スキップ編集';
 UI.name.value=e.name;UI.text.value=e.text;UI.shape.value=e.shape;UI.start.value=e.start;UI.end.value=e.end;
 UI.x.value=e.x;UI.y.value=e.y;UI.w.value=e.w;UI.h.value=e.h;UI.font.value=e.fontSize;
 UI.textField.classList.toggle('hidden',e.type!=='comment');UI.shapeField.classList.toggle('hidden',e.type!=='highlight');
 UI.fontField.classList.toggle('hidden',e.type!=='comment');UI.targetField.classList.toggle('hidden',e.type!=='comment');
 buildColors(e.color);buildTargets(e);UI.modal.style.display='flex';
}
function buildColors(active){
 UI.colors.innerHTML='';
 APP.colors.forEach(c=>{const b=document.createElement('button');b.type='button';b.className='color-choice';b.style.background=c;b.dataset.color=c;
  if(c.toLowerCase()===String(active).toLowerCase())b.classList.add('active');
  b.onclick=()=>{UI.colors.querySelectorAll('.active').forEach(x=>x.classList.remove('active'));b.classList.add('active')};
  UI.colors.appendChild(b);
 });
}
function buildTargets(comment){
 UI.target.innerHTML='<option value="">接続しない</option>';
 APP.project.elements.filter(e=>e.type==='highlight').forEach(e=>{
  const o=document.createElement('option');o.value=e.id;o.textContent=e.name;o.selected=e.id===comment.target;UI.target.appendChild(o);
 });
}
function closeModal(){UI.modal.style.display='none';APP.modalId=null}
function saveElement(){
 const e=get(APP.modalId);if(!e)return;
 const start=Number(UI.start.value),end=Number(UI.end.value);
 if(!Number.isFinite(start)||!Number.isFinite(end)||start<0||end<=start||end>APP.duration)return toast('開始・終了時間が不正です',true);
 e.name=UI.name.value.trim()||'要素';e.start=start;e.end=end;e.x=clamp(Number(UI.x.value)||0,0,99);e.y=clamp(Number(UI.y.value)||0,0,99);
 e.w=clamp(Number(UI.w.value)||1,1,100);e.h=clamp(Number(UI.h.value)||1,1,100);
 e.color=UI.colors.querySelector('.active')?.dataset.color||e.color;
 if(e.type==='comment'){e.text=UI.text.value;e.fontSize=clamp(Number(UI.font.value)||28,8,100);const t=get(UI.target.value);e.target=t?.type==='highlight'?t.id:''}
 if(e.type==='highlight')e.shape=UI.shape.value;
 dirty();closeModal();render();
}

function deleteSelected(){
 if(!APP.project||!APP.selected.size)return;
 const ids=new Set(APP.selected);
 APP.project.elements=APP.project.elements.filter(e=>!ids.has(e.id));
 APP.project.elements.forEach(e=>{if(e.type==='comment'&&ids.has(e.target))e.target=''});
 APP.selected.clear();closeContext();closeModal();dirty();render();
}

function connectSelected(){
 if(APP.selected.size!==2)return toast('コメント1個と強調枠1個を選択してください',true);
 const items=[...APP.selected].map(get);
 const comment=items.find(e=>e?.type==='comment'),highlight=items.find(e=>e?.type==='highlight');
 if(!comment||!highlight)return toast('接続にはコメントと強調枠を1個ずつ選択してください',true);
 comment.target=highlight.id;dirty();render();toast('接続しました');
}
function disconnectSelected(){
 if(!APP.selected.size)return;
 let changed=false;
 APP.project.elements.forEach(e=>{if(e.type==='comment'&&APP.selected.has(e.id)&&e.target){e.target='';changed=true}});
 if(changed){dirty();render();toast('接続を解除しました')}
}

function context(x,y,id=null){
 APP.contextId=id;UI.menu.style.display='block';UI.menu.style.left=Math.min(x,innerWidth-225)+'px';UI.menu.style.top=Math.min(y,innerHeight-250)+'px';
}
function closeContext(){UI.menu.style.display='none';APP.contextId=null}

UI.menu.querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>add(b.dataset.add));
$('editContext').onclick=()=>{if(APP.contextId)openElement(APP.contextId);closeContext()};
$('deleteContext').onclick=()=>{if(APP.contextId){APP.selected.clear();APP.selected.add(APP.contextId);deleteSelected()}closeContext()};

UI.wrap.oncontextmenu=ev=>{
 ev.preventDefault();if(!canEdit())return;
 const r=UI.video.getBoundingClientRect();
 if(ev.clientX<r.left||ev.clientX>r.right||ev.clientY<r.top||ev.clientY>r.bottom)return;
 APP.contextXY={x:clamp((ev.clientX-r.left)/r.width*100,0,99),y:clamp((ev.clientY-r.top)/r.height*100,0,99)};
 context(ev.clientX,ev.clientY);
};

UI.content.oncontextmenu=ev=>{
 ev.preventDefault();if(!canEdit())return;
 const b=ev.target.closest('.element-bar');
 if(b){select(b.dataset.id,false);context(ev.clientX,ev.clientY,b.dataset.id);return}
 if(ev.target.closest('.track-lane')||ev.target.closest('.axis-track')){seek(clientTime(ev.clientX));APP.contextXY=null;context(ev.clientX,ev.clientY)}
};
UI.content.onpointerdown=ev=>{
 if(ev.target.closest('.element-bar')||ev.target.closest('.playhead'))return;
 if(ev.target.closest('.track-lane')||ev.target.closest('.axis-track'))seek(clientTime(ev.clientX));
};

async function togglePlay(){
 if(!canEdit())return;
 if(UI.video.paused){if(APP.time>=APP.duration-.001)seek(0);try{await UI.video.play()}catch(e){if(e.name!=='AbortError')toast('動画を再生できません',true)}}
 else UI.video.pause();
}
function stop(){UI.video.pause();seek(0)}
UI.play.onclick=togglePlay;UI.stop.onclick=stop;UI.connect.onclick=connectSelected;UI.disconnect.onclick=disconnectSelected;
UI.video.ontimeupdate=()=>{if(!UI.video.paused){APP.time=UI.video.currentTime;render()}};
UI.video.onplay=()=>UI.play.textContent='Ⅱ';UI.video.onpause=()=>UI.play.textContent='▶';
UI.video.onended=()=>{APP.time=APP.duration;render()};
UI.video.onloadedmetadata=()=>{
 const d=Number(UI.video.duration);if(!Number.isFinite(d)||d<=0)return toast('動画の長さを取得できません',true);
 APP.duration=d;APP.time=0;APP.project.duration=d;enable();render();clean();
};

UI.zoom.oninput=()=>{UI.zoomValue.textContent=Number(UI.zoom.value).toFixed(1)+'×';render()};
window.addEventListener('resize',render);UI.scroll.onscroll=renderConnections;

function storageKey(n){return'video-annotation-project:'+n}
function saveLocal(){
 if(!APP.project)return;
 try{
  const p=structuredClone(APP.project);p.savedAt=new Date().toISOString();
  localStorage.setItem(storageKey(p.name),JSON.stringify(p));clean();toast('保存しました');
 }catch(e){toast('保存に失敗しました：'+e.message,true)}
}
function openStorage(){
 UI.storageList.innerHTML='';
 const keys=Object.keys(localStorage).filter(k=>k.startsWith('video-annotation-project:'));
 if(!keys.length){UI.storageList.innerHTML='<div style="padding:20px;color:#94a3b8">保存データはありません</div>'}
 keys.forEach(key=>{
  try{
   const p=JSON.parse(localStorage.getItem(key)),row=document.createElement('div');row.className='storage-item';
   const info=document.createElement('div');info.className='storage-info';
   const name=document.createElement('div');name.className='storage-name';name.textContent=p.name;
   const meta=document.createElement('div');meta.className='storage-meta';meta.textContent=`${p.videoName||'動画未設定'} / ${p.elements?.length||0}要素`;
   info.append(name,meta);
   const load=document.createElement('button');load.className='btn small';load.textContent='読込';load.onclick=()=>{loadProject(p);UI.storage.style.display='none'};
   const del=document.createElement('button');del.className='btn small danger';del.textContent='削除';del.onclick=()=>{if(confirm('この保存データを削除しますか？')){localStorage.removeItem(key);openStorage()}};
   row.append(info,load,del);UI.storageList.appendChild(row);
  }catch(_){}
 });
 UI.storage.style.display='flex';
}
function loadProject(input){
 try{
  APP.project=normalize(input);APP.duration=Number(UI.video.duration)>0?Number(UI.video.duration):APP.project.duration;
  APP.project.duration=APP.duration;APP.time=0;APP.selected.clear();
  if(APP.duration>0)enable();render();clean();
  if(APP.duration<=0)toast('プロジェクトを読み込みました。動画を読み込んでください');
 }catch(e){toast('プロジェクトを読み込めません：'+e.message,true)}
}

function exportProject(){
 if(!APP.project)return;
 const blob=new Blob([JSON.stringify(APP.project,null,2)],{type:'application/json;charset=utf-8'});
 const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=(APP.project.name||'video-project')+'.json';
 document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
UI.import.onchange=async ev=>{
 const f=ev.target.files?.[0];if(!f)return;
 try{loadProject(JSON.parse(await f.text()));toast('JSONを読み込みました')}catch(e){toast('JSONを読み込めません：'+e.message,true)}
 ev.target.value='';
};

UI.open.onclick=()=>UI.file.click();UI.file.onchange=()=>loadVideo(UI.file.files?.[0]);
UI.newBtn.onclick=reset;UI.save.onclick=saveLocal;UI.load.onclick=openStorage;UI.export.onclick=exportProject;
UI.modalClose.onclick=UI.modalCancel.onclick=closeModal;UI.modalSave.onclick=saveElement;
UI.modalDelete.onclick=deleteSelected;UI.storageClose.onclick=()=>UI.storage.style.display='none';

document.addEventListener('keydown',ev=>{
 const tag=document.activeElement?.tagName;
 if(ev.key==='Escape'){closeContext();closeModal();UI.storage.style.display='none'}
 if((ev.key==='Delete'||ev.key==='Backspace')&&!['INPUT','TEXTAREA','SELECT'].includes(tag)){ev.preventDefault();deleteSelected()}
 if(ev.key===' '&&!['INPUT','TEXTAREA','SELECT'].includes(tag)){ev.preventDefault();togglePlay()}
 if((ev.ctrlKey||ev.metaKey)&&ev.key.toLowerCase()==='a'&&!['INPUT','TEXTAREA','SELECT'].includes(tag)){
  ev.preventDefault();if(APP.project){APP.selected=new Set(APP.project.elements.map(e=>e.id));render()}
 }
});

UI.zoomValue.textContent='1.0×';
render();
</script>
</body>
</html>

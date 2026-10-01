<?php
declare(strict_types=1);

const APP_VERSION = '8.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'version'=>APP_VERSION], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'export-json') {
        $data = json_decode($_POST['project'] ?? '', true);
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
 --bg:#09111d;--panel:#101b2a;--panel2:#172438;--line:#334155;
 --text:#e5e7eb;--muted:#94a3b8;--blue:#2563eb;--red:#ef4444;
 --label:100px;--track:52px
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
 background:#070d17;border-bottom:1px solid var(--line)}
.brand{font-weight:700;margin-right:8px;white-space:nowrap}
.btn{border:1px solid #40516a;border-radius:6px;background:#243247;color:var(--text);padding:7px 11px}
.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:var(--blue);border-color:#3b82f6}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}
.status{margin-left:auto;color:var(--muted);font-size:12px;white-space:nowrap}
.file-input{display:none}

.main{flex:1;min-height:0;display:flex;flex-direction:column}
.video-area{flex:1;min-height:260px;display:flex;align-items:center;justify-content:center;
 padding:12px;background:#05090f;overflow:hidden}
.video-stage{position:relative;max-width:100%;max-height:100%;line-height:0}
#video{display:block;max-width:100%;max-height:100%;background:#000}
.overlay{position:absolute;inset:0;overflow:visible}
.overlay-svg{position:absolute;inset:0;width:100%;height:100%;z-index:1;pointer-events:none;overflow:visible}
.connection{fill:none;stroke:#facc15;stroke-width:2.5;stroke-dasharray:7 5}
.connection.active{stroke:#fff;stroke-width:4}
.connection-dot{fill:#facc15;stroke:#111827;stroke-width:2}

.element{position:absolute;z-index:5;min-width:24px;min-height:18px;cursor:move;
 user-select:none;touch-action:none}
.element.selected{z-index:20}
.element-body{width:100%;height:100%;position:relative}
.element.selected .element-body{outline:2px solid #60a5fa;outline-offset:2px}
.comment-body{display:flex;align-items:center;justify-content:center;width:100%;height:100%;
 padding:5px 8px;background:#000b;border:1px solid currentColor;border-radius:4px;
 line-height:1.2;text-align:center;white-space:pre-wrap;overflow:hidden}
.highlight-body{width:100%;height:100%;border:3px solid currentColor}
.highlight-body.circle,.highlight-body.ellipse{border-radius:50%}
.skip-body{width:100%;height:100%;display:flex;align-items:center;justify-content:center;
 border:2px dashed #fb923c;background:#f9731626;color:#fdba74;border-radius:4px;font-size:12px;font-weight:700}
.resize-handle{display:none;position:absolute;width:9px;height:9px;background:#fff;
 border:1px solid #2563eb;border-radius:2px;z-index:30}
.element.selected .resize-handle{display:block}
.rh-nw{left:-5px;top:-5px;cursor:nwse-resize}.rh-n{left:50%;top:-5px;transform:translateX(-50%);cursor:ns-resize}
.rh-ne{right:-5px;top:-5px;cursor:nesw-resize}.rh-e{right:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}
.rh-se{right:-5px;bottom:-5px;cursor:nwse-resize}.rh-s{left:50%;bottom:-5px;transform:translateX(-50%);cursor:ns-resize}
.rh-sw{left:-5px;bottom:-5px;cursor:nesw-resize}.rh-w{left:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}

.empty-video{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
 flex-direction:column;color:#64748b;line-height:1.8;text-align:center;pointer-events:none}
.empty-video strong{color:#94a3b8;font-size:18px}

.bottom{height:330px;flex:none;display:flex;border-top:1px solid var(--line);background:#0e1724}
.timeline{min-width:0;flex:1;display:flex;flex-direction:column}
.timeline-toolbar{height:43px;display:flex;align-items:center;gap:7px;padding:5px 8px;border-bottom:1px solid var(--line)}
.time-readout{min-width:150px;font-variant-numeric:tabular-nums;color:#dbeafe}
.selection-info{font-size:11px;color:#94a3b8}
.zoom{margin-left:auto;display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12px}
.zoom input{width:120px}

.timeline-scroll{position:relative;flex:1;min-height:0;overflow:auto}
.timeline-content{position:relative;min-width:700px;width:100%}
.axis{height:34px;position:sticky;top:0;z-index:50;display:flex;background:#131d29;border-bottom:1px solid var(--line)}
.axis-label{width:var(--label);min-width:var(--label);display:flex;align-items:center;padding-left:8px;
 border-right:1px solid var(--line);font-size:12px;position:sticky;left:0;z-index:60;background:#131d29}
.axis-track{position:relative;flex:1}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #334155;padding:5px 0 0 3px;
 font-size:10px;color:#64748b;white-space:nowrap;pointer-events:none}
.track{height:var(--track);display:flex;border-bottom:1px solid #263445}
.track-label{width:var(--label);min-width:var(--label);display:flex;align-items:center;gap:6px;
 padding:0 8px;background:#111b27;border-right:1px solid var(--line);font-size:12px;position:sticky;left:0;z-index:20}
.dot{width:8px;height:8px;border-radius:50%;flex:none}
.lane{position:relative;flex:1}
.bar{position:absolute;top:8px;height:36px;min-width:12px;border:1px solid currentColor;border-radius:5px;
 display:flex;align-items:center;cursor:grab;touch-action:none;user-select:none}
.bar.selected{box-shadow:0 0 0 2px #60a5fa}
.bar-label{padding:0 7px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;pointer-events:none}
.bar-handle{position:absolute;top:0;width:9px;height:100%;z-index:3}
.bar-handle.left{left:-5px;cursor:ew-resize}.bar-handle.right{right:-5px;cursor:ew-resize}
.playhead{position:absolute;top:34px;bottom:0;width:3px;background:var(--red);z-index:80;cursor:ew-resize}
.playhead:before{content:"";position:absolute;top:-1px;left:-5px;width:13px;height:13px;background:var(--red);
 clip-path:polygon(0 0,100% 0,50% 100%)}
.playhead-label{position:absolute;top:12px;left:6px;background:var(--red);padding:2px 4px;
 border-radius:3px;color:#fff;font-size:10px;white-space:nowrap}

.context{position:fixed;display:none;z-index:2000;min-width:210px;padding:5px;background:#172235;
 border:1px solid #475569;border-radius:6px;box-shadow:0 12px 30px #0008}
.context button{display:block;width:100%;padding:8px;border:0;background:none;color:var(--text);
 text-align:left;border-radius:4px}
.context button:hover{background:#293a52}
.context hr{border:0;border-top:1px solid #334155;margin:4px 0}

.modal-backdrop{position:fixed;inset:0;z-index:3000;display:none;align-items:center;justify-content:center;
 padding:20px;background:#000a}
.modal{width:min(570px,100%);max-height:90vh;display:flex;flex-direction:column;
 background:#182231;border:1px solid #475569;border-radius:8px;overflow:hidden}
.modal-head,.modal-foot{padding:12px 15px;border-color:var(--line)}
.modal-head{display:flex;justify-content:space-between;border-bottom:1px solid var(--line)}
.modal-body{padding:15px;display:grid;gap:11px;overflow:auto}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--line)}
.field{display:grid;gap:5px}.field label{font-size:12px;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;padding:7px;background:#0f1722;color:var(--text);
 border:1px solid #40516a;border-radius:5px}
.field textarea{min-height:90px;resize:vertical}
.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.colors{display:grid;grid-template-columns:repeat(8,1fr);gap:6px}
.color{height:28px;border:2px solid transparent;border-radius:4px}
.color.active{border-color:#fff;box-shadow:0 0 0 1px #60a5fa}
.storage{padding:0!important;gap:0!important}
.storage-item{display:flex;align-items:center;gap:8px;padding:10px;border-bottom:1px solid #334155}
.storage-info{min-width:0;flex:1}.storage-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.storage-meta{color:#64748b;font-size:11px}
.toast{position:fixed;right:15px;bottom:15px;z-index:5000;display:none;padding:10px 14px;
 background:#1e293b;border:1px solid #475569;border-radius:6px;box-shadow:0 10px 30px #0006}
@media(max-width:800px){:root{--label:88px}.brand{display:none}.bottom{height:300px}}
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
 <div class="video-stage" id="stage">
  <video id="video" preload="metadata" playsinline></video>
  <div class="overlay" id="overlay"><svg class="overlay-svg" id="svg"></svg></div>
  <div class="empty-video" id="empty"><strong>動画を読み込んでください</strong><span>右クリックで要素を追加できます</span></div>
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
   <div class="zoom"><span>時間スケール</span><input id="zoom" type="range" min="1" max="5" step=".1" value="1"><span id="zoomValue">1.0×</span></div>
  </div>
  <div class="timeline-scroll" id="scroll">
   <div class="timeline-content" id="content">
    <div class="axis"><div class="axis-label">時間</div><div class="axis-track" id="axis"></div></div>
    <div id="tracks"></div>
    <div class="playhead" id="playhead"><span class="playhead-label" id="playheadLabel">00:00.000</span></div>
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
 <div class="modal-head"><strong id="modalTitle">要素編集</strong><button class="btn small" id="modalClose">閉じる</button></div>
 <div class="modal-body">
  <div class="field"><label>要素名</label><input id="name"></div>
  <div class="field" id="textField"><label>コメント</label><textarea id="text"></textarea></div>
  <div class="field hidden" id="shapeField"><label>強調枠の形</label><select id="shape"><option value="square">四角</option><option value="circle">丸</option><option value="ellipse">楕円</option></select></div>
  <div class="two">
   <div class="field"><label>開始時間</label><input id="start" type="number" min="0" step=".001"></div>
   <div class="field"><label>終了時間</label><input id="end" type="number" min="0" step=".001"></div>
  </div>
  <div class="two">
   <div class="field"><label>横位置 (%)</label><input id="x" type="number" min="0" max="99" step=".1"></div>
   <div class="field"><label>縦位置 (%)</label><input id="y" type="number" min="0" max="99" step=".1"></div>
  </div>
  <div class="two">
   <div class="field"><label>幅 (%)</label><input id="w" type="number" min="1" max="100" step=".1"></div>
   <div class="field"><label>高さ (%)</label><input id="h" type="number" min="1" max="100" step=".1"></div>
  </div>
  <div class="field" id="fontField"><label>文字サイズ</label><input id="font" type="number" min="8" max="100"></div>
  <div class="field"><label>色</label><div class="colors" id="colors"></div></div>
  <div class="field" id="targetField"><label>接続する強調枠</label><select id="target"><option value="">接続しない</option></select></div>
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
 <div class="modal-head"><strong>保存データ</strong><button class="btn small" id="storageClose">閉じる</button></div>
 <div class="modal-body storage" id="storageList"></div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $=id=>document.getElementById(id);
const U={
 video:$('video'),stage:$('stage'),overlay:$('overlay'),svg:$('svg'),empty:$('empty'),
 file:$('videoFile'),open:$('openVideo'),newBtn:$('newProject'),save:$('saveProject'),
 load:$('loadProject'),export:$('exportProject'),import:$('importProject'),status:$('status'),
 play:$('play'),stop:$('stop'),connect:$('connect'),disconnect:$('disconnect'),
 selection:$('selectionInfo'),readout:$('readout'),zoom:$('zoom'),zoomValue:$('zoomValue'),
 scroll:$('scroll'),content:$('content'),axis:$('axis'),tracks:$('tracks'),
 playhead:$('playhead'),playheadLabel:$('playheadLabel'),context:$('context'),
 modal:$('elementModal'),storage:$('storageModal'),storageList:$('storageList'),
 title:$('modalTitle'),name:$('name'),text:$('text'),shape:$('shape'),start:$('start'),
 end:$('end'),x:$('x'),y:$('y'),w:$('w'),h:$('h'),font:$('font'),colors:$('colors'),
 target:$('target'),textField:$('textField'),shapeField:$('shapeField'),fontField:$('fontField'),
 targetField:$('targetField'),modalClose:$('modalClose'),modalDelete:$('modalDelete'),
 modalCancel:$('modalCancel'),modalSave:$('modalSave'),storageClose:$('storageClose'),toast:$('toast')
};

const A={
 version:'8.0.0',project:null,url:'',duration:0,time:0,selected:new Set(),
 modalId:null,contextId:null,contextXY:null,drag:null,playheadDrag:false,dirty:false,
 colors:['#60a5fa','#22c55e','#f59e0b','#ef4444','#c084fc','#14b8a6','#f97316','#e879f9','#38bdf8','#a3e635','#fb7185','#facc15']
};

const uid=()=>`el-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`;
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const get=id=>A.project?.elements.find(e=>e.id===id)||null;
const typeColor=t=>({comment:'#60a5fa',highlight:'#22c55e',skip:'#f97316'}[t]||'#60a5fa');
const fmt=v=>{
 v=Math.max(0,Number(v)||0);
 return `${String(Math.floor(v/60)).padStart(2,'0')}:${String(Math.floor(v%60)).padStart(2,'0')}.${String(Math.floor(v%1*1000)).padStart(3,'0')}`;
};
const short=v=>v<60?`${Number(v.toFixed(v%1?1:0))}s`:`${String(Math.floor(v/60)).padStart(2,'0')}:${String(Math.floor(v%60)).padStart(2,'0')}`;
const rgba=(hex,a)=>{
 const n=parseInt(hex.slice(1),16);
 return `rgba(${n>>16},${n>>8&255},${n&255},${a})`;
};
const esc=s=>CSS.escape(String(s));

function toast(msg,error=false){
 U.status.textContent=msg;U.toast.textContent=msg;U.toast.style.display='block';
 U.toast.style.borderColor=error?'#ef4444':'#475569';
 clearTimeout(toast.timer);toast.timer=setTimeout(()=>U.toast.style.display='none',2400);
}
function dirty(){A.dirty=true;U.status.textContent='変更あり'}
function clean(){A.dirty=false;U.status.textContent=A.project?`編集中：${A.project.name}`:'動画を読み込んでください'}
function editable(){return!!A.project&&A.duration>0}
function project(name='新規プロジェクト'){
 return {version:A.version,name,videoName:'',duration:A.duration,elements:[]};
}

function normalize(src){
 if(!src||typeof src!=='object')throw Error('プロジェクト形式が不正です');
 const duration=Number(src.duration)||A.duration;
 const p={version:A.version,name:String(src.name||'プロジェクト'),videoName:String(src.videoName||''),
  duration,elements:[]};
 const types=['comment','highlight','skip'];
 (Array.isArray(src.elements)?src.elements:[]).forEach(r=>{
  const type=types.includes(r.type)?r.type:'comment';
  const start=clamp(Number(r.start)||0,0,duration);
  const end=clamp(Number(r.end)||Math.min(duration,start+3),start+.05,duration);
  p.elements.push({
   id:String(r.id||uid()),type,name:String(r.name||type),text:String(r.text||''),
   start,end,x:clamp(Number(r.x)||0,0,99),y:clamp(Number(r.y)||0,0,99),
   w:clamp(Number(r.w)||30,1,100),h:clamp(Number(r.h)||20,1,100),
   color:/^#[0-9a-f]{6}$/i.test(String(r.color||''))?r.color:typeColor(type),
   fontSize:clamp(Number(r.fontSize)||28,8,100),
   shape:['square','circle','ellipse'].includes(r.shape)?r.shape:'square',
   target:String(r.target||'')
  });
 });
 const ids=new Set(p.elements.map(e=>e.id));
 p.elements.forEach(e=>{
  if(e.type!=='comment'||!ids.has(e.target)||!p.elements.some(t=>t.id===e.target&&t.type==='highlight'))e.target='';
 });
 return p;
}

function loadVideo(file){
 if(!file?.type.startsWith('video/'))return toast('動画ファイルを選択してください',true);
 if(A.url)URL.revokeObjectURL(A.url);
 A.url=URL.createObjectURL(file);A.project=project(file.name);A.project.videoName=file.name;
 A.duration=0;A.time=0;A.selected.clear();U.video.src=A.url;U.video.load();
 U.status.textContent='動画を読み込んでいます…';
}
function reset(){
 U.video.pause();
 if(A.url)URL.revokeObjectURL(A.url);
 A.url='';A.project=null;A.duration=0;A.time=0;A.selected.clear();A.dirty=false;
 U.video.removeAttribute('src');U.video.load();U.empty.style.display='flex';
 U.play.disabled=U.stop.disabled=U.save.disabled=U.export.disabled=true;render();
 U.status.textContent='動画を読み込んでください';
}

function viewRange(){
 const span=A.duration/(Number(U.zoom.value)||1);
 const start=clamp(A.time-span/2,0,Math.max(0,A.duration-span));
 return {start,end:start+span};
}
function timePercent(t){
 const r=viewRange();return r.end===r.start?0:clamp((t-r.start)/(r.end-r.start)*100,0,100);
}
function xToTime(x){
 const r=U.axis.getBoundingClientRect(),q=viewRange();
 return r.width?clamp(q.start+(x-r.left)/r.width*(q.end-q.start),0,A.duration):A.time;
}

function renderAxis(){
 U.axis.innerHTML='';
 if(!A.duration)return;
 const r=viewRange(),span=r.end-r.start,width=Math.max(U.axis.clientWidth,1);
 const approx=span/Math.max(width/80,1);
 const steps=[.1,.25,.5,1,2,5,10,15,30,60,120,300,600];
 const step=steps.find(v=>v>=approx)||600;
 for(let t=Math.ceil(r.start/step)*step;t<=r.end+.001;t+=step){
  const n=document.createElement('div');n.className='tick';
  n.style.left=((t-r.start)/span*100)+'%';n.textContent=short(t);U.axis.appendChild(n);
 }
}

function makeBar(e){
 const n=document.createElement('div');n.className='bar';n.dataset.id=e.id;
 n.style.left=timePercent(e.start)+'%';n.style.width=Math.max(.6,timePercent(e.end)-timePercent(e.start))+'%';
 n.style.color=e.color;n.style.background=rgba(e.color,.2);
 if(A.selected.has(e.id))n.classList.add('selected');

 const label=document.createElement('span');label.className='bar-label';
 label.textContent=`${e.name} ${fmt(e.start)}～${fmt(e.end)}`;
 const l=document.createElement('span'),r=document.createElement('span');
 l.className='bar-handle left';r.className='bar-handle right';l.dataset.edge='left';r.dataset.edge='right';
 n.append(label,l,r);

 n.onpointerdown=ev=>timelineDrag(ev,e.id);
 n.onclick=ev=>{ev.stopPropagation();select(e.id,ev.ctrlKey||ev.metaKey)};
 n.ondblclick=ev=>{ev.stopPropagation();openElement(e.id)};
 n.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();select(e.id);showContext(ev.clientX,ev.clientY,e.id)};
 return n;
}

function renderTimeline(){
 U.tracks.innerHTML='';
 if(!editable()){renderAxis();return}
 [['comment','コメント'],['highlight','強調枠'],['skip','スキップ']].forEach(([type,label])=>{
  const row=document.createElement('div');row.className='track';
  const head=document.createElement('div');head.className='track-label';
  const dot=document.createElement('span');dot.className='dot';dot.style.background=typeColor(type);
  head.append(dot,document.createTextNode(label));
  const lane=document.createElement('div');lane.className='lane';
  lane.style.backgroundImage='linear-gradient(to right,rgba(148,163,184,.08) 1px,transparent 1px)';
  lane.style.backgroundSize=Math.max(10,100/A.duration)+'% 100%';
  A.project.elements.filter(e=>e.type===type).forEach(e=>lane.appendChild(makeBar(e)));
  row.append(head,lane);U.tracks.appendChild(row);
 });
 renderAxis();
}

function handleMarkup(){
 return ['nw','n','ne','e','se','s','sw','w'].map(x=>`<span class="resize-handle rh-${x}" data-resize="${x}"></span>`).join('');
}

function renderOverlay(){
 U.overlay.querySelectorAll('.element').forEach(n=>n.remove());
 if(!editable())return;

 A.project.elements.filter(e=>A.time>=e.start&&A.time<e.end).forEach(e=>{
  const n=document.createElement('div');n.className='element';n.dataset.id=e.id;
  n.style.left=e.x+'%';n.style.top=e.y+'%';n.style.width=e.w+'%';n.style.height=e.h+'%';n.style.color=e.color;
  if(A.selected.has(e.id))n.classList.add('selected');

  const body=document.createElement('div');body.className='element-body';
  if(e.type==='comment'){
   body.className+=' comment-body';body.textContent=e.text||e.name;body.style.fontSize=e.fontSize+'px';
  }else if(e.type==='highlight'){
   body.className+=' highlight-body '+e.shape;
  }else{
   body.className+=' skip-body';body.textContent='スキップ';
  }
  n.append(body);
  if(A.selected.has(e.id))n.insertAdjacentHTML('beforeend',handleMarkup());

  n.onpointerdown=ev=>overlayPointerDown(ev,e.id);
  n.onclick=ev=>{ev.stopPropagation();select(e.id,ev.ctrlKey||ev.metaKey)};
  n.ondblclick=ev=>{ev.stopPropagation();openElement(e.id)};
  n.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();select(e.id);showContext(ev.clientX,ev.clientY,e.id)};
  U.overlay.appendChild(n);
 });
}

function renderConnections(){
 U.svg.innerHTML='';
 if(!editable())return;

 const rect=U.overlay.getBoundingClientRect();
 const active=A.project.elements.filter(e=>e.type==='comment'&&e.target);

 active.forEach(comment=>{
  const highlight=get(comment.target);
  if(!highlight||highlight.type!=='highlight')return;
  if(comment.start>A.time||comment.end<=A.time||highlight.start>A.time||highlight.end<=A.time)return;

  const ca=U.overlay.querySelector(`[data-id="${esc(comment.id)}"]`);
  const ha=U.overlay.querySelector(`[data-id="${esc(highlight.id)}"]`);
  if(!ca||!ha)return;

  const a=ca.getBoundingClientRect(),b=ha.getBoundingClientRect();
  const ax=a.left+a.width/2-rect.left,ay=a.top+a.height/2-rect.top;
  const bx=b.left+b.width/2-rect.left,by=b.top+b.height/2-rect.top;
  const bend=Math.max(30,Math.abs(bx-ax)*.25),dir=Math.sign(bx-ax)||1;

  const path=document.createElementNS('http://www.w3.org/2000/svg','path');
  path.classList.add('connection');
  if(A.selected.has(comment.id)||A.selected.has(highlight.id))path.classList.add('active');
  path.setAttribute('d',`M${ax} ${ay} C${ax+dir*bend} ${ay},${bx-dir*bend} ${by},${bx} ${by}`);
  U.svg.appendChild(path);

  const dot=document.createElementNS('http://www.w3.org/2000/svg','circle');
  dot.classList.add('connection-dot');dot.setAttribute('cx',bx);dot.setAttribute('cy',by);dot.setAttribute('r',5);
  U.svg.appendChild(dot);
 });
}

function renderPlayhead(){
 if(!editable()){U.playhead.style.display='none';return}
 U.playhead.style.display='block';
 U.playhead.style.left=`calc(var(--label) + (100% - var(--label)) * ${timePercent(A.time)/100})`;
 U.playheadLabel.textContent=fmt(A.time);
 U.readout.textContent=`${fmt(A.time)} / ${fmt(A.duration)}`;
}
function renderSelection(){
 U.selection.textContent=A.selected.size?`${A.selected.size}個選択`:'';
 U.connect.disabled=A.selected.size!==2;
 U.disconnect.disabled=!A.selected.size;
}
function render(){
 renderTimeline();renderOverlay();renderConnections();renderPlayhead();renderSelection();
}

function select(id,multi=false){
 if(!get(id))return;
 if(!multi)A.selected.clear();
 if(multi&&A.selected.has(id))A.selected.delete(id);else A.selected.add(id);
 render();
}
function seek(t){
 if(!editable())return;
 A.time=clamp(Number(t)||0,0,A.duration);
 if(Math.abs((U.video.currentTime||0)-A.time)>.002)try{U.video.currentTime=A.time}catch(_){}
 render();
}

/* ---------- 動画上の直接操作 ---------- */

function overlayPointerDown(ev,id){
 if(ev.button!==0)return;
 ev.preventDefault();ev.stopPropagation();
 const e=get(id);if(!e)return;

 const resize=ev.target.closest('[data-resize]')?.dataset.resize||'';
 if(!(ev.ctrlKey||ev.metaKey))select(id);
 else select(id,true);

 const rect=U.overlay.getBoundingClientRect();
 A.drag={
  id,resize,
  sx:ev.clientX,sy:ev.clientY,
  x:e.x,y:e.y,w:e.w,h:e.h,
  rectW:rect.width,rectH:rect.height
 };
 window.addEventListener('pointermove',overlayMove);
 window.addEventListener('pointerup',overlayUp,{once:true});
}

function overlayMove(ev){
 const d=A.drag,e=get(d?.id);if(!d||!e)return;
 const dx=(ev.clientX-d.sx)/d.rectW*100,dy=(ev.clientY-d.sy)/d.rectH*100;
 const minW=.8,minH=.8;

 if(!d.resize){
  e.x=clamp(d.x+dx,0,100-d.w);e.y=clamp(d.y+dy,0,100-d.h);
 }else{
  let x=d.x,y=d.y,w=d.w,h=d.h;
  if(d.resize.includes('w')){x=clamp(d.x+dx,0,d.x+d.w-minW);w=d.w-(x-d.x)}
  if(d.resize.includes('e'))w=clamp(d.w+dx,minW,100-d.x)
  if(d.resize.includes('n')){y=clamp(d.y+dy,0,d.y+d.h-minH);h=d.h-(y-d.y)}
  if(d.resize.includes('s'))h=clamp(d.h+dy,minH,100-d.y)
  e.x=x;e.y=y;e.w=w;e.h=h;
 }
 dirty();render();
}
function overlayUp(){
 A.drag=null;
 window.removeEventListener('pointermove',overlayMove);
 render();
}

/* ---------- タイムライン操作 ---------- */

function timelineDrag(ev,id){
 if(ev.button!==0)return;
 ev.preventDefault();ev.stopPropagation();
 const e=get(id);if(!e)return;
 const handle=ev.target.closest('.bar-handle');
 if(!(ev.ctrlKey||ev.metaKey))select(id);
 const lane=ev.currentTarget.parentElement,rect=lane.getBoundingClientRect(),r=viewRange();
 A.drag={id,mode:handle?'resize':'move',edge:handle?.dataset.edge||'',
  sx:ev.clientX,start:e.start,end:e.end,width:rect.width,span:r.end-r.start};
 window.addEventListener('pointermove',timelineMove);
 window.addEventListener('pointerup',timelineUp,{once:true});
}
function timelineMove(ev){
 const d=A.drag,e=get(d?.id);if(!d||!e)return;
 const delta=(ev.clientX-d.sx)/d.width*d.span;
 if(d.mode==='move'){
  const len=d.end-d.start;
  e.start=clamp(d.start+delta,0,A.duration-len);e.end=e.start+len;
 }else if(d.edge==='left'){
  e.start=clamp(d.start+delta,0,e.end-.05);
 }else{
  e.end=clamp(d.end+delta,e.start+.05,A.duration);
 }
 dirty();render();
}
function timelineUp(){
 A.drag=null;window.removeEventListener('pointermove',timelineMove);render();
}

/* ---------- 再生ヘッド ---------- */

U.playhead.onpointerdown=ev=>{
 if(!editable())return;
 ev.preventDefault();ev.stopPropagation();A.playheadDrag=true;seek(xToTime(ev.clientX));
};
window.addEventListener('pointermove',ev=>{if(A.playheadDrag)seek(xToTime(ev.clientX))});
window.addEventListener('pointerup',()=>A.playheadDrag=false);

/* ---------- 追加 ---------- */

function add(type){
 if(!editable())return toast('先に動画を読み込んでください',true);
 const p=A.contextXY||{x:10,y:10},start=A.time,end=Math.min(A.duration,start+3);
 const e={
  id:uid(),type,name:type==='comment'?'コメント':type==='highlight'?'強調枠':'スキップ',
  text:type==='comment'?'コメント':'',start,end,
  x:clamp(p.x,0,99),y:clamp(p.y,0,99),
  w:type==='comment'?30:25,h:type==='comment'?15:20,
  color:typeColor(type),fontSize:28,shape:'square',target:''
 };
 A.project.elements.push(e);A.selected=new Set([e.id]);A.contextXY=null;closeContext();dirty();render();openElement(e.id);
}

/* ---------- 編集モーダル ---------- */

function openElement(id){
 const e=get(id);if(!e)return;
 A.modalId=id;
 U.title.textContent=e.type==='comment'?'コメント編集':e.type==='highlight'?'強調枠編集':'スキップ編集';
 U.name.value=e.name;U.text.value=e.text;U.shape.value=e.shape;
 U.start.value=e.start;U.end.value=e.end;U.x.value=e.x;U.y.value=e.y;U.w.value=e.w;U.h.value=e.h;
 U.font.value=e.fontSize;
 U.textField.classList.toggle('hidden',e.type!=='comment');
 U.shapeField.classList.toggle('hidden',e.type!=='highlight');
 U.fontField.classList.toggle('hidden',e.type!=='comment');
 U.targetField.classList.toggle('hidden',e.type!=='comment');
 buildColors(e.color);buildTargets(e);
 U.modal.style.display='flex';
}
function buildColors(active){
 U.colors.innerHTML='';
 A.colors.forEach(c=>{
  const b=document.createElement('button');b.type='button';b.className='color';
  b.style.background=c;b.dataset.color=c;
  if(c.toLowerCase()===String(active).toLowerCase())b.classList.add('active');
  b.onclick=()=>{U.colors.querySelectorAll('.active').forEach(x=>x.classList.remove('active'));b.classList.add('active')};
  U.colors.appendChild(b);
 });
}
function buildTargets(comment){
 U.target.innerHTML='<option value="">接続しない</option>';
 A.project.elements.filter(e=>e.type==='highlight').forEach(e=>{
  const o=document.createElement('option');o.value=e.id;o.textContent=e.name;o.selected=e.id===comment.target;
  U.target.appendChild(o);
 });
}
function closeModal(){U.modal.style.display='none';A.modalId=null}

function saveElement(){
 const e=get(A.modalId);if(!e)return;
 const start=Number(U.start.value),end=Number(U.end.value);
 if(!Number.isFinite(start)||!Number.isFinite(end)||start<0||end<=start||end>A.duration)
  return toast('開始・終了時間が不正です',true);

 e.name=U.name.value.trim()||'要素';e.start=start;e.end=end;
 e.x=clamp(Number(U.x.value)||0,0,99);e.y=clamp(Number(U.y.value)||0,0,99);
 e.w=clamp(Number(U.w.value)||1,1,100);e.h=clamp(Number(U.h.value)||1,1,100);
 e.color=U.colors.querySelector('.active')?.dataset.color||e.color;

 if(e.type==='comment'){
  e.text=U.text.value;e.fontSize=clamp(Number(U.font.value)||28,8,100);
  const t=get(U.target.value);e.target=t?.type==='highlight'?t.id:'';
 }else if(e.type==='highlight'){
  e.shape=U.shape.value;
 }
 closeModal();dirty();render();
}

/* ---------- 削除・接続 ---------- */

function deleteSelected(){
 if(!A.project||!A.selected.size)return;
 const ids=new Set(A.selected);
 A.project.elements=A.project.elements.filter(e=>!ids.has(e.id));
 A.project.elements.forEach(e=>{if(e.type==='comment'&&ids.has(e.target))e.target=''});
 A.selected.clear();closeContext();closeModal();dirty();render();
}

function connectSelected(){
 if(A.selected.size!==2)return toast('コメント1個と強調枠1個を選択してください',true);
 const items=[...A.selected].map(get);
 const comment=items.find(e=>e?.type==='comment');
 const highlight=items.find(e=>e?.type==='highlight');
 if(!comment||!highlight)return toast('コメントと強調枠を1個ずつ選択してください',true);
 comment.target=highlight.id;dirty();render();toast('接続しました');
}
function disconnectSelected(){
 if(!A.selected.size)return;
 let changed=false;
 A.project.elements.forEach(e=>{
  if(e.type==='comment'&&A.selected.has(e.id)&&e.target){e.target='';changed=true}
 });
 if(changed){dirty();render();toast('接続を解除しました')}
}

/* ---------- コンテキストメニュー ---------- */

function showContext(x,y,id=null){
 A.contextId=id;U.context.style.display='block';
 U.context.style.left=Math.min(x,innerWidth-220)+'px';
 U.context.style.top=Math.min(y,innerHeight-230)+'px';
}
function closeContext(){U.context.style.display='none';A.contextId=null}

U.context.querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>add(b.dataset.add));
$('editContext').onclick=()=>{if(A.contextId)openElement(A.contextId);closeContext()};
$('deleteContext').onclick=()=>{
 if(A.contextId){A.selected=new Set([A.contextId]);deleteSelected()}
 closeContext();
};

U.stage.oncontextmenu=ev=>{
 ev.preventDefault();
 if(!editable())return;
 const r=U.video.getBoundingClientRect();
 if(ev.clientX<r.left||ev.clientX>r.right||ev.clientY<r.top||ev.clientY>r.bottom)return;
 A.contextXY={x:(ev.clientX-r.left)/r.width*100,y:(ev.clientY-r.top)/r.height*100};
 showContext(ev.clientX,ev.clientY);
};

U.content.oncontextmenu=ev=>{
 ev.preventDefault();
 if(!editable())return;
 const b=ev.target.closest('.bar');
 if(b){select(b.dataset.id);showContext(ev.clientX,ev.clientY,b.dataset.id);return}
 if(ev.target.closest('.lane')||ev.target.closest('.axis-track')){
  seek(xToTime(ev.clientX));A.contextXY=null;showContext(ev.clientX,ev.clientY);
 }
};
U.content.onpointerdown=ev=>{
 if(ev.target.closest('.bar')||ev.target.closest('.playhead'))return;
 if(ev.target.closest('.lane')||ev.target.closest('.axis-track'))seek(xToTime(ev.clientX));
};
U.overlay.onclick=ev=>{
 if(ev.target===U.overlay){A.selected.clear();render()}
};

/* ---------- 動画 ---------- */

async function togglePlay(){
 if(!editable())return;
 if(U.video.paused){
  if(A.time>=A.duration-.01)seek(0);
  try{await U.video.play()}catch(e){if(e.name!=='AbortError')toast('動画を再生できません',true)}
 }else U.video.pause();
}
function stop(){U.video.pause();seek(0)}

U.play.onclick=togglePlay;U.stop.onclick=stop;U.connect.onclick=connectSelected;U.disconnect.onclick=disconnectSelected;

U.video.ontimeupdate=()=>{
 if(!U.video.paused){A.time=U.video.currentTime;render()}
};
U.video.onplay=()=>U.play.textContent='Ⅱ';
U.video.onpause=()=>U.play.textContent='▶';
U.video.onended=()=>{A.time=A.duration;render()};
U.video.onloadedmetadata=()=>{
 const d=Number(U.video.duration);
 if(!Number.isFinite(d)||d<=0)return toast('動画の長さを取得できません',true);
 A.duration=d;A.time=0;
 if(A.project)A.project.duration=d;
 U.empty.style.display='none';
 U.play.disabled=U.stop.disabled=U.save.disabled=U.export.disabled=false;
 render();clean();
};

/* ---------- 保存 ---------- */

function storageKey(name){return'video-annotation-project:'+name}

function saveLocal(){
 if(!A.project)return;
 try{
  const p=JSON.parse(JSON.stringify(A.project));p.savedAt=new Date().toISOString();
  localStorage.setItem(storageKey(p.name),JSON.stringify(p));clean();toast('保存しました');
 }catch(e){toast('保存に失敗しました：'+e.message,true)}
}

function openStorage(){
 U.storageList.innerHTML='';
 const keys=Object.keys(localStorage).filter(k=>k.startsWith('video-annotation-project:'));
 if(!keys.length){
  U.storageList.innerHTML='<div style="padding:20px;color:#94a3b8">保存データはありません</div>';
 }else keys.forEach(key=>{
  try{
   const p=JSON.parse(localStorage.getItem(key)),row=document.createElement('div');row.className='storage-item';
   const info=document.createElement('div');info.className='storage-info';
   const name=document.createElement('div');name.className='storage-name';name.textContent=p.name;
   const meta=document.createElement('div');meta.className='storage-meta';
   meta.textContent=`${p.videoName||'動画未設定'} / ${p.elements?.length||0}要素`;
   info.append(name,meta);
   const load=document.createElement('button');load.className='btn small';load.textContent='読込';
   load.onclick=()=>{loadProject(p);U.storage.style.display='none'};
   const del=document.createElement('button');del.className='btn small danger';del.textContent='削除';
   del.onclick=()=>{if(confirm('この保存データを削除しますか？')){localStorage.removeItem(key);openStorage()}};
   row.append(info,load,del);U.storageList.appendChild(row);
  }catch(_){}
 });
 U.storage.style.display='flex';
}

function loadProject(src){
 try{
  const p=normalize(src);
  A.project=p;
  const videoDuration=Number(U.video.duration);
  A.duration=videoDuration>0?videoDuration:p.duration;
  A.project.duration=A.duration;A.time=0;A.selected.clear();
  U.empty.style.display=A.duration>0?'none':'flex';
  U.play.disabled=U.stop.disabled=U.save.disabled=U.export.disabled=A.duration<=0;
  render();clean();
  if(A.duration<=0)toast('プロジェクトを読み込みました。動画を読み込んでください');
 }catch(e){toast('プロジェクトを読み込めません：'+e.message,true)}
}

function exportProject(){
 if(!A.project)return;
 const blob=new Blob([JSON.stringify(A.project,null,2)],{type:'application/json'});
 const url=URL.createObjectURL(blob),a=document.createElement('a');
 a.href=url;a.download=(A.project.name||'video-project')+'.json';
 document.body.appendChild(a);a.click();a.remove();
 setTimeout(()=>URL.revokeObjectURL(url),1000);
}

/* ---------- UI ---------- */

U.open.onclick=()=>U.file.click();
U.file.onchange=()=>loadVideo(U.file.files?.[0]);
U.newBtn.onclick=reset;
U.save.onclick=saveLocal;
U.load.onclick=openStorage;
U.export.onclick=exportProject;

U.import.onchange=async ev=>{
 const f=ev.target.files?.[0];if(!f)return;
 try{loadProject(JSON.parse(await f.text()));toast('JSONを読み込みました')}
 catch(e){toast('JSONを読み込めません：'+e.message,true)}
 ev.target.value='';
};

U.zoom.oninput=()=>{
 U.zoomValue.textContent=Number(U.zoom.value).toFixed(1)+'×';render();
};

U.modalClose.onclick=U.modalCancel.onclick=closeModal;
U.modalSave.onclick=saveElement;
U.modalDelete.onclick=deleteSelected;
U.storageClose.onclick=()=>U.storage.style.display='none';

document.addEventListener('click',ev=>{
 if(!ev.target.closest('.context'))closeContext();
});

document.addEventListener('keydown',ev=>{
 const tag=document.activeElement?.tagName;
 const input=['INPUT','TEXTAREA','SELECT'].includes(tag);

 if(ev.key==='Escape'){
  closeContext();closeModal();U.storage.style.display='none';
 }
 if((ev.key==='Delete'||ev.key==='Backspace')&&!input){
  ev.preventDefault();deleteSelected();
 }
 if(ev.key===' '&&!input){
  ev.preventDefault();togglePlay();
 }
 if((ev.ctrlKey||ev.metaKey)&&ev.key.toLowerCase()==='a'&&!input){
  ev.preventDefault();
  if(A.project){A.selected=new Set(A.project.elements.map(e=>e.id));render()}
 }
});

window.addEventListener('resize',render);
U.scroll.addEventListener('scroll',renderConnections);

U.zoomValue.textContent='1.0×';
render();
</script>
</body>
</html>

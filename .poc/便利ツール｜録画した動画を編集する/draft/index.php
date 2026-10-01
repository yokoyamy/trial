<?php
declare(strict_types=1);

const APP_VERSION = '9.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (($_POST['action'] ?? '') === 'health') {
        echo json_encode(['ok'=>true,'version'=>APP_VERSION], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_POST['action'] ?? '') === 'export-json') {
        $data = json_decode($_POST['project'] ?? '', true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'invalid json'], JSON_UNESCAPED_UNICODE);
            exit;
        }
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
 --label:105px;--track:52px
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
.stage{position:relative;max-width:100%;max-height:100%;line-height:0;overflow:visible}
#video{display:block;max-width:100%;max-height:100%;background:#000}
.overlay{position:absolute;inset:0;overflow:visible}
.svg{position:absolute;inset:0;width:100%;height:100%;z-index:1;pointer-events:none;overflow:visible}
.connection{fill:none;stroke:#facc15;stroke-width:2.5;stroke-dasharray:7 5}
.connection.active{stroke:#fff;stroke-width:4}
.connection-dot{fill:#facc15;stroke:#111827;stroke-width:2}

.element{position:absolute;z-index:5;min-width:18px;min-height:14px;cursor:move;
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
.handle{display:none;position:absolute;width:10px;height:10px;background:#fff;
 border:1px solid #2563eb;border-radius:2px;z-index:30}
.element.selected .handle{display:block}
.h-nw{left:-5px;top:-5px;cursor:nwse-resize}.h-n{left:50%;top:-5px;transform:translateX(-50%);cursor:ns-resize}
.h-ne{right:-5px;top:-5px;cursor:nesw-resize}.h-e{right:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}
.h-se{right:-5px;bottom:-5px;cursor:nwse-resize}.h-s{left:50%;bottom:-5px;transform:translateX(-50%);cursor:ns-resize}
.h-sw{left:-5px;bottom:-5px;cursor:nesw-resize}.h-w{left:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}

.empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;
 color:#64748b;line-height:1.8;text-align:center;pointer-events:none}
.empty strong{color:#94a3b8;font-size:18px}

.bottom{height:335px;flex:none;display:flex;border-top:1px solid var(--line);background:#0e1724}
.timeline{min-width:0;flex:1;display:flex;flex-direction:column}
.toolbar{height:43px;display:flex;align-items:center;gap:7px;padding:5px 8px;border-bottom:1px solid var(--line)}
.readout{min-width:150px;font-variant-numeric:tabular-nums;color:#dbeafe}
.info{font-size:11px;color:#94a3b8}
.zoom{margin-left:auto;display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12px}
.zoom input{width:110px}
.range{display:flex;align-items:center;gap:4px}
.range input{width:70px;padding:4px;background:#0f1722;color:#fff;border:1px solid #40516a;border-radius:4px}

.scroll{position:relative;flex:1;min-height:0;overflow:auto}
.content{position:relative;min-width:760px;width:100%}
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
.bar{position:absolute;top:8px;height:36px;min-width:8px;border:1px solid currentColor;border-radius:5px;
 display:flex;align-items:center;cursor:grab;touch-action:none;user-select:none}
.bar.selected{box-shadow:0 0 0 2px #60a5fa}
.bar-label{padding:0 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;pointer-events:none}
.bar-edge{position:absolute;top:0;width:12px;height:100%;z-index:4}
.bar-edge.left{left:-6px;cursor:ew-resize}.bar-edge.right{right:-6px;cursor:ew-resize}

.playhead{position:absolute;top:34px;bottom:0;width:3px;background:var(--red);z-index:80;cursor:ew-resize}
.playhead:before{content:"";position:absolute;top:-1px;left:-5px;width:13px;height:13px;background:var(--red);
 clip-path:polygon(0 0,100% 0,50% 100%)}
.playhead-label{position:absolute;top:12px;left:6px;background:var(--red);padding:2px 4px;
 border-radius:3px;color:#fff;font-size:10px;white-space:nowrap}

.context{position:fixed;display:none;z-index:2000;min-width:210px;padding:5px;background:#172235;
 border:1px solid #475569;border-radius:6px;box-shadow:0 12px 30px #0008}
.context button{display:block;width:100%;padding:8px;border:0;background:none;color:var(--text);text-align:left;border-radius:4px}
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
.storage-item{display:flex;align-items:center;gap:8px;padding:10px;border-bottom:1px solid #334155}
.storage-info{min-width:0;flex:1}.storage-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.storage-meta{color:#64748b;font-size:11px}
.toast{position:fixed;right:15px;bottom:15px;z-index:5000;display:none;padding:10px 14px;
 background:#1e293b;border:1px solid #475569;border-radius:6px;box-shadow:0 10px 30px #0006}

@media(max-width:800px){:root{--label:90px}.brand{display:none}.bottom{height:310px}}
@media(max-width:600px){.status{display:none}.range{display:none}}
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
 <div class="stage" id="stage">
  <video id="video" preload="metadata" playsinline></video>
  <div class="overlay" id="overlay"><svg class="svg" id="svg"></svg></div>
  <div class="empty" id="empty"><strong>動画を読み込んでください</strong><span>右クリックで要素を追加できます</span></div>
 </div>
</section>

<section class="bottom">
<section class="timeline">
 <div class="toolbar">
  <button class="btn small" id="play" disabled>▶</button>
  <button class="btn small" id="stop" disabled>■</button>
  <button class="btn small" id="connect" disabled>🔗 接続</button>
  <button class="btn small" id="disconnect" disabled>接続解除</button>
  <span class="info" id="selectionInfo"></span>
  <span class="readout" id="readout">00:00.000 / 00:00.000</span>

  <div class="range">
   <label>範囲</label>
   <input id="rangeStart" type="number" min="0" step=".001" placeholder="開始">
   <span>～</span>
   <input id="rangeEnd" type="number" min="0" step=".001" placeholder="終了">
   <button class="btn small" id="rangePlay">範囲再生</button>
   <button class="btn small" id="rangeZoom">範囲拡大</button>
   <button class="btn small" id="rangeReset">全体</button>
  </div>

  <div class="zoom"><span>時間</span><input id="zoom" type="range" min="1" max="8" step=".1" value="1"><span id="zoomValue">1.0×</span></div>
 </div>

 <div class="scroll" id="scroll">
  <div class="content" id="content">
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
  <div class="field hidden" id="shapeField"><label>強調枠の形</label>
   <select id="shape"><option value="square">四角</option><option value="circle">丸</option><option value="ellipse">楕円</option></select>
  </div>
  <div class="two">
   <div class="field"><label>開始時間</label><input id="start" type="number" min="0" step=".001"></div>
   <div class="field"><label>終了時間</label><input id="end" type="number" min="0" step=".001"></div>
  </div>
  <div class="two">
   <div class="field"><label>横位置 (%)</label><input id="x" type="number" min="0" max="99" step=".1"></div>
   <div class="field"><label>縦位置 (%)</label><input id="y" type="number" min="0" max="99" step=".1"></div>
  </div>
  <div class="two">
   <div class="field"><label>幅 (%)</label><input id="w" type="number" min=".5" max="100" step=".1"></div>
   <div class="field"><label>高さ (%)</label><input id="h" type="number" min=".5" max="100" step=".1"></div>
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
 <div class="modal-body" id="storageList"></div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $=id=>document.getElementById(id);
const E={
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
 target:$('target'),textField:$('textField'),shapeField:$('shapeField'),
 fontField:$('fontField'),targetField:$('targetField'),toast:$('toast'),
 rangeStart:$('rangeStart'),rangeEnd:$('rangeEnd'),rangePlay:$('rangePlay'),
 rangeZoom:$('rangeZoom'),rangeReset:$('rangeReset')
};

const S={
 version:'9.0.0',project:null,url:'',duration:0,time:0,selected:new Set(),
 modalId:null,contextId:null,contextXY:null,drag:null,range:null,dirty:false,
 colors:['#60a5fa','#22c55e','#f59e0b','#ef4444','#c084fc','#14b8a6','#f97316','#e879f9','#38bdf8','#a3e635','#fb7185','#facc15']
};

const uid=()=>`el-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`;
const get=id=>S.project?.elements.find(e=>e.id===id)||null;
const color=t=>({comment:'#60a5fa',highlight:'#22c55e',skip:'#f97316'}[t]||'#60a5fa');
const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
const esc=s=>CSS.escape(String(s));
const fmt=n=>{n=Math.max(0,Number(n)||0);return `${String(Math.floor(n/60)).padStart(2,'0')}:${String(Math.floor(n%60)).padStart(2,'0')}.${String(Math.floor(n%1*1000)).padStart(3,'0')}`};
const short=n=>n<60?`${Number(n.toFixed(n%1?1:0))}s`:`${String(Math.floor(n/60)).padStart(2,'0')}:${String(Math.floor(n%60)).padStart(2,'0')}`;
const rgba=(h,a)=>{const n=parseInt(h.slice(1),16);return`rgba(${n>>16},${n>>8&255},${n&255},${a})`};

function toast(msg,error=false){
 E.status.textContent=msg;E.toast.textContent=msg;E.toast.style.display='block';
 E.toast.style.borderColor=error?'#ef4444':'#475569';
 clearTimeout(toast.t);toast.t=setTimeout(()=>E.toast.style.display='none',2400);
}
function dirty(){S.dirty=true;E.status.textContent='変更あり'}
function clean(){S.dirty=false;E.status.textContent=S.project?`編集中：${S.project.name}`:'動画を読み込んでください'}
function editable(){return!!S.project&&S.duration>0}
function blankProject(name='新規プロジェクト'){return{version:S.version,name,videoName:'',duration:S.duration,elements:[]}}

function normalize(src){
 if(!src||typeof src!=='object')throw Error('プロジェクト形式が不正です');
 const duration=Number(src.duration)||S.duration,p={version:S.version,name:String(src.name||'プロジェクト'),
  videoName:String(src.videoName||''),duration,elements:[]};
 const types=['comment','highlight','skip'];
 (Array.isArray(src.elements)?src.elements:[]).forEach(r=>{
  const type=types.includes(r.type)?r.type:'comment',start=clamp(Number(r.start)||0,0,duration);
  const end=clamp(Number(r.end)||Math.min(duration,start+3),start+.05,duration);
  p.elements.push({
   id:String(r.id||uid()),type,name:String(r.name||type),text:String(r.text||''),
   start,end,x:clamp(Number(r.x)||0,0,99),y:clamp(Number(r.y)||0,0,99),
   w:clamp(Number(r.w)||30,.5,100),h:clamp(Number(r.h)||20,.5,100),
   color:/^#[0-9a-f]{6}$/i.test(String(r.color||''))?r.color:color(type),
   fontSize:clamp(Number(r.fontSize)||28,8,100),
   shape:['square','circle','ellipse'].includes(r.shape)?r.shape:'square',target:String(r.target||'')
  });
 });
 const ids=new Set(p.elements.map(e=>e.id));
 p.elements.forEach(e=>{
  if(e.type!=='comment'||!ids.has(e.target)||!p.elements.some(x=>x.id===e.target&&x.type==='highlight'))e.target='';
 });
 return p;
}

/* 時間表示範囲 */
function view(){
 if(!S.duration)return{start:0,end:1};
 if(S.range)return S.range;
 const span=S.duration/(Number(E.zoom.value)||1);
 const start=clamp(S.time-span/2,0,Math.max(0,S.duration-span));
 return{start,end:start+span};
}
function pct(t){
 const r=view();return r.end===r.start?0:clamp((t-r.start)/(r.end-r.start)*100,0,100);
}
function timeAt(clientX,el=E.axis){
 const r=el.getBoundingClientRect(),v=view();
 return r.width?clamp(v.start+(clientX-r.left)/r.width*(v.end-v.start),0,S.duration):S.time;
}

/* ---------- 描画 ---------- */

function renderAxis(){
 E.axis.innerHTML='';
 if(!S.duration)return;
 const r=view(),span=r.end-r.start,width=Math.max(E.axis.clientWidth,1);
 const approx=span/Math.max(width/85,1),steps=[.1,.25,.5,1,2,5,10,15,30,60,120,300,600];
 const step=steps.find(x=>x>=approx)||600;
 for(let t=Math.ceil(r.start/step)*step;t<=r.end+.001;t+=step){
  const n=document.createElement('div');n.className='tick';n.style.left=((t-r.start)/span*100)+'%';n.textContent=short(t);E.axis.append(n);
 }
}

function renderTimeline(){
 E.tracks.innerHTML='';
 if(!editable()){renderAxis();return}
 [['comment','コメント'],['highlight','強調枠'],['skip','スキップ']].forEach(([type,label])=>{
  const row=document.createElement('div');row.className='track';
  const head=document.createElement('div');head.className='track-label';
  const dot=document.createElement('span');dot.className='dot';dot.style.background=color(type);
  head.append(dot,document.createTextNode(label));
  const lane=document.createElement('div');lane.className='lane';
  S.project.elements.filter(e=>e.type===type).forEach(e=>lane.append(makeBar(e)));
  row.append(head,lane);E.tracks.append(row);
 });
 renderAxis();
}

function makeBar(e){
 const n=document.createElement('div');n.className='bar';n.dataset.id=e.id;
 n.style.left=pct(e.start)+'%';n.style.width=Math.max(.3,pct(e.end)-pct(e.start))+'%';
 n.style.color=e.color;n.style.background=rgba(e.color,.2);
 if(S.selected.has(e.id))n.classList.add('selected');

 const label=document.createElement('span');label.className='bar-label';
 label.textContent=`${e.name} ${fmt(e.start)}～${fmt(e.end)}`;
 const l=document.createElement('span'),r=document.createElement('span');
 l.className='bar-edge left';r.className='bar-edge right';
 l.dataset.edge='left';r.dataset.edge='right';
 n.append(label,l,r);

 n.onpointerdown=ev=>timelineDown(ev,e.id);
 n.ondblclick=ev=>{ev.stopPropagation();openElement(e.id)};
 n.oncontextmenu=ev=>{ev.preventDefault();select(e.id);showContext(ev.clientX,ev.clientY,e.id)};
 return n;
}

function handles(){
 return ['nw','n','ne','e','se','s','sw','w'].map(x=>`<span class="handle h-${x}" data-resize="${x}"></span>`).join('');
}

function renderOverlay(){
 E.overlay.querySelectorAll('.element').forEach(n=>n.remove());
 if(!editable())return;

 S.project.elements.filter(e=>S.time>=e.start&&S.time<e.end).forEach(e=>{
  const n=document.createElement('div');n.className='element';n.dataset.id=e.id;
  n.style.left=e.x+'%';n.style.top=e.y+'%';n.style.width=e.w+'%';n.style.height=e.h+'%';n.style.color=e.color;
  if(S.selected.has(e.id))n.classList.add('selected');

  const b=document.createElement('div');
  if(e.type==='comment'){
   b.className='element-body comment-body';b.textContent=e.text||e.name;b.style.fontSize=e.fontSize+'px';
  }else if(e.type==='highlight'){
   b.className=`element-body highlight-body ${e.shape}`;
  }else{
   b.className='element-body skip-body';b.textContent='スキップ';
  }
  n.append(b);
  if(S.selected.has(e.id))n.insertAdjacentHTML('beforeend',handles());

  n.onpointerdown=ev=>overlayDown(ev,e.id);
  n.ondblclick=ev=>{ev.stopPropagation();openElement(e.id)};
  n.oncontextmenu=ev=>{ev.preventDefault();select(e.id);showContext(ev.clientX,ev.clientY,e.id)};
  E.overlay.append(n);
 });
 renderConnections();
}

function renderConnections(){
 E.svg.innerHTML='';
 if(!editable())return;
 const rect=E.overlay.getBoundingClientRect();

 S.project.elements.filter(e=>e.type==='comment'&&e.target).forEach(c=>{
  const h=get(c.target);
  if(!h||h.type!=='highlight')return;
  if(c.start>S.time||c.end<=S.time||h.start>S.time||h.end<=S.time)return;

  const a=E.overlay.querySelector(`[data-id="${esc(c.id)}"]`);
  const b=E.overlay.querySelector(`[data-id="${esc(h.id)}"]`);
  if(!a||!b)return;

  const ar=a.getBoundingClientRect(),br=b.getBoundingClientRect();
  const ax=ar.left+ar.width/2-rect.left,ay=ar.top+ar.height/2-rect.top;
  const bx=br.left+br.width/2-rect.left,by=br.top+br.height/2-rect.top;
  const dx=bx-ax,curve=Math.max(35,Math.abs(dx)*.28),dir=Math.sign(dx)||1;

  const p=document.createElementNS('http://www.w3.org/2000/svg','path');
  p.classList.add('connection');
  if(S.selected.has(c.id)||S.selected.has(h.id))p.classList.add('active');
  p.setAttribute('d',`M${ax} ${ay} C${ax+curve*dir} ${ay},${bx-curve*dir} ${by},${bx} ${by}`);
  E.svg.append(p);

  const dot=document.createElementNS('http://www.w3.org/2000/svg','circle');
  dot.classList.add('connection-dot');dot.setAttribute('cx',bx);dot.setAttribute('cy',by);dot.setAttribute('r',5);
  E.svg.append(dot);
 });
}

function renderPlayhead(){
 if(!editable()){E.playhead.style.display='none';return}
 E.playhead.style.display='block';
 E.playhead.style.left=`calc(var(--label) + (100% - var(--label)) * ${pct(S.time)/100})`;
 E.playheadLabel.textContent=fmt(S.time);
 E.readout.textContent=`${fmt(S.time)} / ${fmt(S.duration)}`;
}
function renderSelection(){
 E.selection.textContent=S.selected.size?`${S.selected.size}個選択`:'';
 E.connect.disabled=S.selected.size!==2;
 E.disconnect.disabled=!S.selected.size;
}
function render(){renderTimeline();renderOverlay();renderPlayhead();renderSelection()}

/* ---------- 選択 ---------- */

function select(id,multi=false){
 if(!get(id))return;
 if(!multi)S.selected.clear();
 if(multi&&S.selected.has(id))S.selected.delete(id);else S.selected.add(id);
 render();
}

/* ---------- 動画上のリサイズ・移動 ---------- */

function overlayDown(ev,id){
 if(ev.button!==0)return;
 ev.preventDefault();ev.stopPropagation();
 const e=get(id);if(!e)return;

 const resize=ev.target.closest('[data-resize]')?.dataset.resize||'';
 select(id,ev.ctrlKey||ev.metaKey);

 const r=E.overlay.getBoundingClientRect();
 S.drag={
  kind:'overlay',id,resize,sx:ev.clientX,sy:ev.clientY,
  x:e.x,y:e.y,w:e.w,h:e.h,rw:r.width,rh:r.height
 };
 window.addEventListener('pointermove',dragMove);
 window.addEventListener('pointerup',dragEnd,{once:true});
}

function overlayDrag(d,ev,e){
 const dx=(ev.clientX-d.sx)/d.rw*100,dy=(ev.clientY-d.sy)/d.rh*100,min=.5;
 if(!d.resize){
  e.x=clamp(d.x+dx,0,100-d.w);e.y=clamp(d.y+dy,0,100-d.h);return;
 }
 let x=d.x,y=d.y,w=d.w,h=d.h;

 if(d.resize.includes('w')){
  x=clamp(d.x+dx,0,d.x+d.w-min);
  w=d.w-(x-d.x);
 }
 if(d.resize.includes('e'))w=clamp(d.w+dx,min,100-d.x);
 if(d.resize.includes('n')){
  y=clamp(d.y+dy,0,d.y+d.h-min);
  h=d.h-(y-d.y);
 }
 if(d.resize.includes('s'))h=clamp(d.h+dy,min,100-d.y);

 e.x=x;e.y=y;e.w=w;e.h=h;
}

/* ---------- タイムラインの移動・端点リサイズ ---------- */

function timelineDown(ev,id){
 if(ev.button!==0)return;
 ev.preventDefault();ev.stopPropagation();
 const e=get(id);if(!e)return;

 const edge=ev.target.closest('.bar-edge')?.dataset.edge||'';
 select(id,ev.ctrlKey||ev.metaKey);

 const lane=ev.currentTarget.parentElement,r=lane.getBoundingClientRect(),v=view();
 S.drag={
  kind:'timeline',id,edge,sx:ev.clientX,start:e.start,end:e.end,
  width:r.width,span:v.end-v.start
 };
 window.addEventListener('pointermove',dragMove);
 window.addEventListener('pointerup',dragEnd,{once:true});
}

function timelineDrag(d,ev,e){
 const delta=(ev.clientX-d.sx)/d.width*d.span;
 if(!d.edge){
  const len=d.end-d.start,eStart=clamp(d.start+delta,0,S.duration-len);
  e.start=eStart;e.end=eStart+len;
 }else if(d.edge==='left'){
  e.start=clamp(d.start+delta,0,e.end-.05);
 }else{
  e.end=clamp(d.end+delta,e.start+.05,S.duration);
 }
}

function dragMove(ev){
 const d=S.drag,e=get(d?.id);if(!d||!e)return;
 if(d.kind==='overlay')overlayDrag(d,ev,e);else timelineDrag(d,ev,e);
 dirty();render();
}
function dragEnd(){
 S.drag=null;
 window.removeEventListener('pointermove',dragMove);
 render();
}

/* ---------- 再生ヘッド ---------- */

let headDrag=false;
E.playhead.onpointerdown=ev=>{
 if(!editable())return;
 ev.preventDefault();ev.stopPropagation();headDrag=true;seek(timeAt(ev.clientX));
};
window.addEventListener('pointermove',ev=>{if(headDrag)seek(timeAt(ev.clientX))});
window.addEventListener('pointerup',()=>headDrag=false);

function seek(t){
 if(!editable())return;
 S.time=clamp(Number(t)||0,0,S.duration);
 if(Math.abs((E.video.currentTime||0)-S.time)>.002){
  try{E.video.currentTime=S.time}catch(_){}
 }
 render();
}

/* ---------- 追加 ---------- */

function add(type){
 if(!editable())return toast('先に動画を読み込んでください',true);
 const p=S.contextXY||{x:10,y:10},start=S.time,end=Math.min(S.duration,start+3);
 const e={
  id:uid(),type,name:type==='comment'?'コメント':type==='highlight'?'強調枠':'スキップ',
  text:type==='comment'?'コメント':'',start,end,x:clamp(p.x,0,99),y:clamp(p.y,0,99),
  w:type==='comment'?30:25,h:type==='comment'?15:20,color:color(type),
  fontSize:28,shape:'square',target:''
 };
 S.project.elements.push(e);S.selected=new Set([e.id]);S.contextXY=null;
 closeContext();dirty();render();openElement(e.id);
}

/* ---------- 編集 ---------- */

function openElement(id){
 const e=get(id);if(!e)return;
 S.modalId=id;
 E.title.textContent=e.type==='comment'?'コメント編集':e.type==='highlight'?'強調枠編集':'スキップ編集';
 E.name.value=e.name;E.text.value=e.text;E.shape.value=e.shape;
 E.start.value=e.start;E.end.value=e.end;E.x.value=e.x;E.y.value=e.y;E.w.value=e.w;E.h.value=e.h;E.font.value=e.fontSize;
 E.textField.classList.toggle('hidden',e.type!=='comment');
 E.shapeField.classList.toggle('hidden',e.type!=='highlight');
 E.fontField.classList.toggle('hidden',e.type!=='comment');
 E.targetField.classList.toggle('hidden',e.type!=='comment');
 buildColors(e.color);buildTargets(e);
 E.modal.style.display='flex';
}
function buildColors(active){
 E.colors.innerHTML='';
 S.colors.forEach(c=>{
  const b=document.createElement('button');b.type='button';b.className='color';b.style.background=c;b.dataset.color=c;
  if(c.toLowerCase()===String(active).toLowerCase())b.classList.add('active');
  b.onclick=()=>{E.colors.querySelectorAll('.active').forEach(x=>x.classList.remove('active'));b.classList.add('active')};
  E.colors.append(b);
 });
}
function buildTargets(comment){
 E.target.innerHTML='<option value="">接続しない</option>';
 S.project.elements.filter(e=>e.type==='highlight').forEach(e=>{
  const o=document.createElement('option');o.value=e.id;o.textContent=e.name;o.selected=e.id===comment.target;E.target.append(o);
 });
}
function closeModal(){E.modal.style.display='none';S.modalId=null}

function saveElement(){
 const e=get(S.modalId);if(!e)return;
 const start=Number(E.start.value),end=Number(E.end.value);
 if(!Number.isFinite(start)||!Number.isFinite(end)||start<0||end<=start||end>S.duration)
  return toast('開始・終了時間が不正です',true);

 const x=Number(E.x.value),y=Number(E.y.value),w=Number(E.w.value),h=Number(E.h.value);
 if([x,y,w,h].some(v=>!Number.isFinite(v))||x<0||y<0||w<=0||h<=0||x+w>100||y+h>100)
  return toast('位置またはサイズが不正です',true);

 e.name=E.name.value.trim()||'要素';e.start=start;e.end=end;
 e.x=x;e.y=y;e.w=w;e.h=h;
 e.color=E.colors.querySelector('.active')?.dataset.color||e.color;

 if(e.type==='comment'){
  e.text=E.text.value;e.fontSize=clamp(Number(E.font.value)||28,8,100);
  const t=get(E.target.value);e.target=t?.type==='highlight'?t.id:'';
 }else if(e.type==='highlight')e.shape=E.shape.value;

 closeModal();dirty();render();
}

/* ---------- 接続 ---------- */

function connect(){
 if(S.selected.size!==2)return toast('コメント1個と強調枠1個を選択してください',true);
 const a=[...S.selected].map(get),c=a.find(e=>e?.type==='comment'),h=a.find(e=>e?.type==='highlight');
 if(!c||!h)return toast('コメントと強調枠を1個ずつ選択してください',true);
 c.target=h.id;dirty();render();toast('接続しました');
}
function disconnect(){
 let changed=false;
 S.project?.elements.forEach(e=>{
  if(e.type==='comment'&&S.selected.has(e.id)&&e.target){e.target='';changed=true}
 });
 if(changed){dirty();render();toast('接続を解除しました')}
}

/* ---------- 削除 ---------- */

function removeSelected(){
 if(!S.project||!S.selected.size)return;
 const ids=new Set(S.selected);
 S.project.elements=S.project.elements.filter(e=>!ids.has(e.id));
 S.project.elements.forEach(e=>{if(e.type==='comment'&&ids.has(e.target))e.target=''});
 S.selected.clear();closeContext();closeModal();dirty();render();
}

/* ---------- コンテキスト ---------- */

function showContext(x,y,id=null){
 S.contextId=id;E.context.style.display='block';
 E.context.style.left=Math.min(x,innerWidth-220)+'px';
 E.context.style.top=Math.min(y,innerHeight-230)+'px';
}
function closeContext(){E.context.style.display='none';S.contextId=null}

E.context.querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>add(b.dataset.add));
$('editContext').onclick=()=>{if(S.contextId)openElement(S.contextId);closeContext()};
$('deleteContext').onclick=()=>{if(S.contextId){S.selected=new Set([S.contextId]);removeSelected()}closeContext()};

E.stage.oncontextmenu=ev=>{
 ev.preventDefault();
 if(!editable())return;
 const r=E.video.getBoundingClientRect();
 if(ev.clientX<r.left||ev.clientX>r.right||ev.clientY<r.top||ev.clientY>r.bottom)return;
 S.contextXY={x:(ev.clientX-r.left)/r.width*100,y:(ev.clientY-r.top)/r.height*100};
 showContext(ev.clientX,ev.clientY);
};

E.content.oncontextmenu=ev=>{
 ev.preventDefault();
 if(!editable())return;
 const b=ev.target.closest('.bar');
 if(b){select(b.dataset.id);showContext(ev.clientX,ev.clientY,b.dataset.id);return}
 if(ev.target.closest('.lane')||ev.target.closest('.axis-track')){seek(timeAt(ev.clientX));S.contextXY=null;showContext(ev.clientX,ev.clientY)}
};

E.content.onpointerdown=ev=>{
 if(ev.target.closest('.bar,.playhead'))return;
 if(ev.target.closest('.lane,.axis-track'))seek(timeAt(ev.clientX));
};
E.overlay.onclick=ev=>{if(ev.target===E.overlay){S.selected.clear();render()}};

/* ---------- 再生 ---------- */

async function togglePlay(){
 if(!editable())return;
 if(E.video.paused){
  if(S.time>=S.duration-.01)seek(0);
  try{await E.video.play()}catch(e){if(e.name!=='AbortError')toast('動画を再生できません',true)}
 }else E.video.pause();
}
function stop(){E.video.pause();seek(0)}
E.play.onclick=togglePlay;E.stop.onclick=stop;E.connect.onclick=connect;E.disconnect.onclick=disconnect;

E.video.ontimeupdate=()=>{
 if(!E.video.paused){
  S.time=E.video.currentTime;
  if(S.range&&S.time>=S.range.end-.01){
   E.video.pause();seek(S.range.end);
  }else render();
 }
};
E.video.onplay=()=>E.play.textContent='Ⅱ';
E.video.onpause=()=>E.play.textContent='▶';
E.video.onended=()=>{S.time=S.duration;render()};

E.video.onloadedmetadata=()=>{
 const d=Number(E.video.duration);
 if(!Number.isFinite(d)||d<=0)return toast('動画の長さを取得できません',true);
 S.duration=d;S.time=0;
 if(S.project)S.project.duration=d;
 E.empty.style.display='none';
 E.play.disabled=E.stop.disabled=E.save.disabled=E.export.disabled=false;
 render();clean();
};

/* ---------- 動画 ---------- */

function loadVideo(file){
 if(!file?.type.startsWith('video/'))return toast('動画ファイルを選択してください',true);
 if(S.url)URL.revokeObjectURL(S.url);
 S.url=URL.createObjectURL(file);S.project=blankProject(file.name);S.project.videoName=file.name;
 S.duration=0;S.time=0;S.selected.clear();S.range=null;
 E.video.src=S.url;E.video.load();E.status.textContent='動画を読み込んでいます…';
}
function reset(){
 E.video.pause();
 if(S.url)URL.revokeObjectURL(S.url);
 S.url='';S.project=null;S.duration=0;S.time=0;S.range=null;S.selected.clear();S.dirty=false;
 E.video.removeAttribute('src');E.video.load();E.empty.style.display='flex';
 E.play.disabled=E.stop.disabled=E.save.disabled=E.export.disabled=true;render();
 E.status.textContent='動画を読み込んでください';
}

/* ---------- 範囲ズーム ---------- */

function readRange(){
 if(!editable())return null;
 let a=Number(E.rangeStart.value),b=Number(E.rangeEnd.value);
 if(!Number.isFinite(a)||!Number.isFinite(b))return toast('範囲の開始・終了を入力してください',true),null;
 a=clamp(a,0,S.duration);b=clamp(b,0,S.duration);
 if(b<=a+.05)return toast('終了時間は開始時間より後にしてください',true),null;
 return{start:a,end:b};
}
E.rangeZoom.onclick=()=>{
 const r=readRange();if(!r)return;
 S.range=r;E.zoom.value=1;E.zoomValue.textContent='範囲';
 seek(r.start);render();
};
E.rangePlay.onclick=()=>{
 const r=readRange();if(!r)return;
 S.range=r;seek(r.start);E.video.play().catch(()=>toast('再生できません',true));
};
E.rangeReset.onclick=()=>{
 S.range=null;E.rangeStart.value='';E.rangeEnd.value='';E.zoom.value=1;E.zoomValue.textContent='1.0×';render();
};

/* ---------- 保存 ---------- */

const storageKey=name=>'video-annotation-project:'+name;

function saveLocal(){
 if(!S.project)return;
 try{
  const p=JSON.parse(JSON.stringify(S.project));p.savedAt=new Date().toISOString();
  localStorage.setItem(storageKey(p.name),JSON.stringify(p));clean();toast('保存しました');
 }catch(e){toast('保存に失敗しました：'+e.message,true)}
}
function openStorage(){
 E.storageList.innerHTML='';
 const keys=Object.keys(localStorage).filter(k=>k.startsWith('video-annotation-project:'));
 if(!keys.length)E.storageList.innerHTML='<div style="padding:20px;color:#94a3b8">保存データはありません</div>';
 keys.forEach(key=>{
  try{
   const p=JSON.parse(localStorage.getItem(key)),row=document.createElement('div');row.className='storage-item';
   const info=document.createElement('div');info.className='storage-info';
   const n=document.createElement('div');n.className='storage-name';n.textContent=p.name;
   const m=document.createElement('div');m.className='storage-meta';m.textContent=`${p.videoName||'動画未設定'} / ${p.elements?.length||0}要素`;
   info.append(n,m);
   const load=document.createElement('button');load.className='btn small';load.textContent='読込';
   load.onclick=()=>{loadProject(p);E.storage.style.display='none'};
   const del=document.createElement('button');del.className='btn small danger';del.textContent='削除';
   del.onclick=()=>{if(confirm('この保存データを削除しますか？')){localStorage.removeItem(key);openStorage()}};
   row.append(info,load,del);E.storageList.append(row);
  }catch(_){}
 });
 E.storage.style.display='flex';
}
function loadProject(src){
 try{
  const p=normalize(src);S.project=p;
  const vd=Number(E.video.duration);S.duration=vd>0?vd:p.duration;
  p.duration=S.duration;S.time=0;S.range=null;S.selected.clear();
  E.empty.style.display=S.duration?'none':'flex';
  E.play.disabled=E.stop.disabled=E.save.disabled=E.export.disabled=!S.duration;
  render();clean();
 }catch(e){toast('プロジェクトを読み込めません：'+e.message,true)}
}
function exportProject(){
 if(!S.project)return;
 const blob=new Blob([JSON.stringify(S.project,null,2)],{type:'application/json'});
 const u=URL.createObjectURL(blob),a=document.createElement('a');
 a.href=u;a.download=(S.project.name||'video-project')+'.json';document.body.append(a);a.click();a.remove();
 setTimeout(()=>URL.revokeObjectURL(u),1000);
}

/* ---------- UI ---------- */

E.open.onclick=()=>E.file.click();
E.file.onchange=()=>loadVideo(E.file.files?.[0]);
E.newBtn.onclick=reset;
E.save.onclick=saveLocal;
E.load.onclick=openStorage;
E.export.onclick=exportProject;

E.import.onchange=async ev=>{
 const f=ev.target.files?.[0];if(!f)return;
 try{loadProject(JSON.parse(await f.text()));toast('JSONを読み込みました')}
 catch(e){toast('JSONを読み込めません：'+e.message,true)}
 ev.target.value='';
};

E.zoom.oninput=()=>{
 S.range=null;
 E.rangeStart.value='';E.rangeEnd.value='';
 E.zoomValue.textContent=Number(E.zoom.value).toFixed(1)+'×';render();
};

E.modalClose.onclick=E.modalCancel.onclick=closeModal;
E.modalSave.onclick=saveElement;
E.modalDelete.onclick=removeSelected;
E.storageClose.onclick=()=>E.storage.style.display='none';

document.addEventListener('click',ev=>{if(!ev.target.closest('.context'))closeContext()});

document.addEventListener('keydown',ev=>{
 const input=['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName);
 if(ev.key==='Escape'){closeContext();closeModal();E.storage.style.display='none'}
 if((ev.key==='Delete'||ev.key==='Backspace')&&!input){ev.preventDefault();removeSelected()}
 if(ev.key===' '&&!input){ev.preventDefault();togglePlay()}
 if((ev.ctrlKey||ev.metaKey)&&ev.key.toLowerCase()==='a'&&!input&&S.project){
  ev.preventDefault();S.selected=new Set(S.project.elements.map(e=>e.id));render();
 }
});

window.addEventListener('resize',render);
E.scroll.addEventListener('scroll',renderConnections);

E.zoomValue.textContent='1.0×';
render();
</script>
</body>
</html>

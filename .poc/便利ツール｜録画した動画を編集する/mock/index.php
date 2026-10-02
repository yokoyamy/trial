<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;font-family:Arial,"Noto Sans JP",sans-serif;color:#222;background:#f5f6f8}
button,input{font:inherit}
button{cursor:pointer}
.app{height:100vh;display:flex;flex-direction:column;overflow:hidden}
.header{height:56px;display:flex;align-items:center;padding:0 18px;background:#fff;border-bottom:1px solid #ddd;gap:14px;flex:none}
.title{font-size:18px;font-weight:700}
.header .status{margin-left:auto;color:#666;font-size:13px}
.primary{border:0;border-radius:6px;background:#2864d7;color:#fff;padding:9px 15px;font-weight:700}
.main{flex:1;min-height:0;display:flex;flex-direction:column;padding:10px;gap:10px}
.preview{height:42%;min-height:230px;background:#111;border-radius:7px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.preview video{width:100%;height:100%;object-fit:contain;background:#111}
.preview-empty{color:#aaa;font-size:14px}
.video-name{position:absolute;top:10px;left:10px;background:#000b;color:#fff;border-radius:4px;padding:5px 8px;font-size:12px;display:none}
.editor{flex:1;min-height:0;background:#fff;border:1px solid #ddd;border-radius:7px;display:flex;flex-direction:column;overflow:hidden}
.editor-head{height:44px;display:flex;align-items:center;padding:0 10px;border-bottom:1px solid #e2e2e2;flex:none}
.editor-help{font-size:12px;color:#666}
.editor-state{margin-left:auto;font-size:12px;color:#2864d7}
.work-area{position:relative;flex:1;min-height:180px;overflow:auto;background:#fafafa}
.objects{position:relative;width:100%;height:100%;min-height:300px}
.connections{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible;z-index:1}
.element{position:absolute;width:140px;min-height:58px;padding:10px;background:#fff;border:2px solid #2864d7;border-radius:7px;box-shadow:0 2px 5px #0001;cursor:move;user-select:none;z-index:2}
.element.selected{outline:2px solid #2864d7;outline-offset:3px}
.element-name{font-size:13px;font-weight:700}
.element-time{margin-top:5px;color:#777;font-size:11px}
.element-delete{position:absolute;right:4px;top:2px;border:0;background:none;color:#777;padding:0;font-size:16px}
.context{position:fixed;display:none;z-index:50;width:170px;background:#fff;border:1px solid #ccc;border-radius:6px;box-shadow:0 5px 18px #0003;padding:4px}
.context button{width:100%;border:0;background:#fff;text-align:left;padding:9px;border-radius:4px}
.context button:hover{background:#eef4ff;color:#2454c5}
.timeline{height:125px;flex:none;border-top:1px solid #ddd;padding:8px 12px;background:#fff}
.timeline-head{height:28px;display:flex;align-items:center}
.time{font-size:12px;color:#555}
.scale{margin-left:auto;display:flex;align-items:center;gap:6px}
.scale button{width:28px;height:25px;border:1px solid #ccc;background:#fff;border-radius:4px}
.scale span{width:65px;text-align:center;font-size:12px}
.ruler-wrap{height:76px;overflow-x:auto;overflow-y:hidden;margin-top:3px}
.ruler{height:70px;position:relative;min-width:100%;border-top:1px solid #999}
.tick{position:absolute;top:0;height:70px;border-left:1px solid #bbb}
.tick span{position:absolute;top:5px;left:4px;white-space:nowrap;font-size:10px;color:#666}
.playhead{position:absolute;top:0;height:70px;width:2px;background:#df3b3b;z-index:5;pointer-events:none}
.scrub{position:absolute;inset:0;cursor:pointer}
.modal{position:fixed;inset:0;background:#0007;display:none;align-items:center;justify-content:center;z-index:30}
.modal.show{display:flex}
.modal-box{width:390px;max-width:calc(100vw - 30px);background:#fff;border-radius:8px;padding:20px;box-shadow:0 15px 40px #0005}
.modal-box h2{margin:0 0 14px;font-size:18px}
.choice{width:100%;display:block;text-align:left;border:1px solid #d5d9df;background:#fafafa;border-radius:6px;padding:13px;margin-top:8px}
.choice:hover{border-color:#2864d7;background:#eef4ff}
.choice strong{display:block}
.choice small{display:block;margin-top:4px;color:#777}
.modal-close{margin-top:14px;border:1px solid #ccc;background:#fff;border-radius:5px;padding:7px 12px}
@media(max-width:700px){
.preview{height:35%;min-height:190px}
.timeline{height:115px}
}
</style>
</head>
<body>
<div class="app">
<header class="header">
<div class="title">動画編集</div>
<button class="primary" id="selectVideo">動画を選択</button>
<div class="status" id="videoStatus">動画未選択</div>
</header>

<main class="main">
<section class="preview" id="preview">
<div class="preview-empty" id="previewEmpty">動画を選択してください</div>
<video id="video" controls playsinline preload="metadata" style="display:none"></video>
<div class="video-name" id="videoName"></div>
</section>

<section class="editor">
<div class="editor-head">
<div class="editor-help">編集領域：右クリックで要素を追加できます</div>
<div class="editor-state" id="editorState">通常操作</div>
</div>

<div class="work-area" id="workArea">
<div class="objects" id="objects">
<svg class="connections" id="connections"></svg>
</div>
</div>

<div class="timeline">
<div class="timeline-head">
<div class="time" id="timeText">00:00.0 / 00:30.0</div>
<div class="scale">
<button id="zoomOut">−</button>
<span id="scaleText">標準</span>
<button id="zoomIn">＋</button>
</div>
</div>
<div class="ruler-wrap" id="rulerWrap">
<div class="ruler" id="ruler">
<div class="scrub" id="scrub"></div>
<div class="playhead" id="playhead"></div>
</div>
</div>
</div>
</section>
</main>
</div>

<div class="context" id="contextMenu">
<button id="addElement">要素を追加</button>
</div>

<div class="modal" id="videoModal">
<div class="modal-box">
<h2>動画を選択</h2>
<button class="choice" data-video="original">
<strong>オリジナル動画</strong>
<small>録画した元の動画</small>
</button>
<button class="choice" data-video="work">
<strong>作業動画</strong>
<small>編集中の作業動画</small>
</button>
<button class="modal-close" id="closeModal">キャンセル</button>
</div>
</div>

<script>
const state={
 video:null,
 duration:30,
 time:0,
 scale:1,
 selected:null,
 connectSource:null,
 nextId:1,
 elements:[],
 connections:[]
};

const video=document.getElementById('video');
const preview=document.getElementById('preview');
const objects=document.getElementById('objects');
const connections=document.getElementById('connections');
const workArea=document.getElementById('workArea');
const ruler=document.getElementById('ruler');
const playhead=document.getElementById('playhead');
const contextMenu=document.getElementById('contextMenu');
const editorState=document.getElementById('editorState');

const sources={
 original:'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
 work:'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.webm'
};

function formatTime(sec){
 sec=Math.max(0,sec||0);
 return String(Math.floor(sec/60)).padStart(2,'0')+':'+(sec%60).toFixed(1).padStart(4,'0');
}

function openVideoSelector(){
 document.getElementById('videoModal').classList.add('show');
}

document.getElementById('selectVideo').onclick=openVideoSelector;
document.getElementById('closeModal').onclick=()=>{
 document.getElementById('videoModal').classList.remove('show');
};

document.querySelectorAll('.choice').forEach(button=>{
 button.onclick=()=>{
  selectVideo(button.dataset.video);
  document.getElementById('videoModal').classList.remove('show');
 };
});

function selectVideo(type){
 state.video=type;
 state.time=0;
 video.src=sources[type];
 video.style.display='block';
 document.getElementById('previewEmpty').style.display='none';
 document.getElementById('videoName').style.display='block';
 document.getElementById('videoName').textContent=
  type==='original'?'オリジナル動画':'作業動画';
 document.getElementById('videoStatus').textContent=
  type==='original'?'オリジナル動画':'作業動画';
 video.load();
 updateTimeline();
}

video.addEventListener('loadedmetadata',()=>{
 if(Number.isFinite(video.duration)&&video.duration>0){
  state.duration=video.duration;
 }else{
  state.duration=30;
 }
 updateTimeline();
});

video.addEventListener('timeupdate',()=>{
 state.time=video.currentTime;
 updateTimeline();
});

function updateTimeline(){
 document.getElementById('timeText').textContent=
  formatTime(state.time)+' / '+formatTime(state.duration);
 const ratio=state.duration?state.time/state.duration:0;
 playhead.style.left=(ratio*100)+'%';
 renderRuler();
}

function interval(){
 if(state.scale===2)return 1;
 if(state.scale===1)return 5;
 return 10;
}

function renderRuler(){
 ruler.querySelectorAll('.tick').forEach(el=>el.remove());

 const pxPerSecond=state.scale===2?45:(state.scale===1?24:12);
 const width=Math.max(
  document.getElementById('rulerWrap').clientWidth,
  state.duration*pxPerSecond
 );
 ruler.style.width=width+'px';

 const step=interval();
 for(let t=0;t<=state.duration;t+=step){
  const tick=document.createElement('div');
  tick.className='tick';
  tick.style.left=(t*pxPerSecond)+'px';

  const label=document.createElement('span');
  label.textContent=formatTime(t);
  tick.appendChild(label);
  ruler.appendChild(tick);
 }

 const ratio=state.duration?state.time/state.duration:0;
 playhead.style.left=(ratio*100)+'%';
}

document.getElementById('zoomIn').onclick=()=>{
 state.scale=Math.min(2,state.scale+1);
 document.getElementById('scaleText').textContent=
  state.scale===2?'拡大':state.scale===1?'標準':'縮小';
 updateTimeline();
};

document.getElementById('zoomOut').onclick=()=>{
 state.scale=Math.max(.5,state.scale-1);
 document.getElementById('scaleText').textContent=
  state.scale===2?'拡大':state.scale===1?'標準':'縮小';
 updateTimeline();
};

document.getElementById('scrub').onclick=e=>{
 if(!state.video)return;
 const rect=ruler.getBoundingClientRect();
 const x=e.clientX-rect.left;
 const ratio=Math.max(0,Math.min(1,x/ruler.clientWidth));
 video.currentTime=ratio*state.duration;
};

workArea.addEventListener('contextmenu',e=>{
 e.preventDefault();
 const rect=workArea.getBoundingClientRect();
 contextMenu.style.left=e.clientX+'px';
 contextMenu.style.top=e.clientY+'px';
 contextMenu.dataset.x=String(e.clientX-rect.left+workArea.scrollLeft);
 contextMenu.dataset.y=String(e.clientY-rect.top+workArea.scrollTop);
 contextMenu.style.display='block';
});

document.addEventListener('click',e=>{
 if(!contextMenu.contains(e.target))contextMenu.style.display='none';
});

document.getElementById('addElement').onclick=()=>{
 const x=Number(contextMenu.dataset.x||30);
 const y=Number(contextMenu.dataset.y||30);
 addElement(x,y);
 contextMenu.style.display='none';
};

function addElement(x,y){
 const id=state.nextId++;
 state.elements.push({
  id,
  name:'要素 '+id,
  x:Math.max(0,x-70),
  y:Math.max(0,y-30),
  visible:true
 });
 state.selected=id;
 renderElements();
 editorState.textContent='要素を選択中';
}

function getElement(id){
 return state.elements.find(item=>item.id===id);
}

function renderElements(){
 objects.querySelectorAll('.element').forEach(el=>el.remove());

 state.elements.forEach(item=>{
  if(!item.visible)return;

  const el=document.createElement('div');
  el.className='element'+(item.id===state.selected?' selected':'');
  el.dataset.id=item.id;
  el.style.left=item.x+'px';
  el.style.top=item.y+'px';

  const name=document.createElement('div');
  name.className='element-name';
  name.textContent=item.name;
  el.appendChild(name);

  const time=document.createElement('div');
  time.className='element-time';
  time.textContent='編集要素';
  el.appendChild(time);

  const del=document.createElement('button');
  del.className='element-delete';
  del.textContent='×';
  del.title='削除';
  del.onclick=e=>{
   e.stopPropagation();
   deleteElement(item.id);
  };
  el.appendChild(del);

  el.addEventListener('mousedown',startDrag);
  el.addEventListener('click',e=>{
   if(e.target!==del){
    selectElement(item.id);
   }
  });

  el.addEventListener('contextmenu',e=>{
   e.preventDefault();
   e.stopPropagation();
   selectElement(item.id);
   contextMenu.style.display='block';
   contextMenu.style.left=e.clientX+'px';
   contextMenu.style.top=e.clientY+'px';
   contextMenu.dataset.x='0';
   contextMenu.dataset.y='0';
  });

  objects.appendChild(el);
 });

 drawConnections();
}

function selectElement(id){
 if(!getElement(id))return;

 if(state.connectSource!==null&&state.connectSource!==id){
  createConnection(state.connectSource,id);
  state.connectSource=null;
  state.selected=id;
  editorState.textContent='接続しました';
 }else{
  state.selected=id;
  editorState.textContent='要素を選択中';
 }

 renderElements();
}

function deleteElement(id){
 state.elements=state.elements.filter(item=>item.id!==id);
 state.connections=state.connections.filter(
  line=>line.from!==id&&line.to!==id
 );

 if(state.selected===id)state.selected=null;
 if(state.connectSource===id)state.connectSource=null;

 editorState.textContent='通常操作';
 renderElements();
}

let drag=null;

function startDrag(e){
 if(e.button!==0)return;

 const id=Number(e.currentTarget.dataset.id);
 const item=getElement(id);
 if(!item)return;

 selectElement(id);

 drag={
  id,
  startX:e.clientX,
  startY:e.clientY,
  x:item.x,
  y:item.y
 };

 document.addEventListener('mousemove',moveDrag);
 document.addEventListener('mouseup',endDrag,{once:true});
}

function moveDrag(e){
 if(!drag)return;

 const item=getElement(drag.id);
 if(!item)return;

 item.x=Math.max(
  0,
  drag.x+e.clientX-drag.startX
 );
 item.y=Math.max(
  0,
  drag.y+e.clientY-drag.startY
 );

 const el=objects.querySelector(
  '.element[data-id="'+drag.id+'"]'
 );

 if(el){
  el.style.left=item.x+'px';
  el.style.top=item.y+'px';
 }

 drawConnections();
}

function endDrag(){
 drag=null;
 document.removeEventListener('mousemove',moveDrag);
}

function createConnection(from,to){
 if(!getElement(from)||!getElement(to)||from===to)return;

 if(!state.connections.some(
  line=>line.from===from&&line.to===to
 )){
  state.connections.push({from,to});
 }

 editorState.textContent='接続しました';
 drawConnections();
}

function drawConnections(){
 while(connections.firstChild){
  connections.removeChild(connections.firstChild);
 }

 state.connections=state.connections.filter(line=>{
  const from=getElement(line.from);
  const to=getElement(line.to);
  return !!from&&!!to;
 });

 const areaRect=objects.getBoundingClientRect();

 state.connections.forEach(line=>{
  const from=getElement(line.from);
  const to=getElement(line.to);

  if(!from||!to||!from.visible)return;

  const fromEl=objects.querySelector(
   '.element[data-id="'+from.id+'"]'
  );
  const toEl=objects.querySelector(
   '.element[data-id="'+to.id+'"]'
  );

  if(!fromEl||!toEl)return;

  const a=fromEl.getBoundingClientRect();
  const b=toEl.getBoundingClientRect();

  const x1=a.left-areaRect.left+a.width;
  const y1=a.top-areaRect.top+a.height/2;
  const x2=b.left-areaRect.left;
  const y2=b.top-areaRect.top+b.height/2;

  const bend=Math.max(25,Math.abs(x2-x1)*.35);

  const path=document.createElementNS(
   'http://www.w3.org/2000/svg',
   'path'
  );

  path.setAttribute(
   'd',
   'M '+x1+' '+y1+
   ' C '+(x1+bend)+' '+y1+
   ', '+(x2-bend)+' '+y2+
   ', '+x2+' '+y2
  );
  path.setAttribute('fill','none');
  path.setAttribute('stroke','#2864d7');
  path.setAttribute('stroke-width','2');
  path.setAttribute('stroke-linecap','round');

  connections.appendChild(path);
 });
}

window.addEventListener('resize',()=>{
 drawConnections();
 updateTimeline();
});

workArea.addEventListener('scroll',drawConnections);

updateTimeline();
renderElements();
</script>
</body>
</html>

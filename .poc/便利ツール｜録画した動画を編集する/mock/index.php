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
html,body{margin:0;width:100%;height:100%;font-family:Arial,"Noto Sans JP",sans-serif;color:#222;background:#f4f5f7}
button{font:inherit;cursor:pointer}
.app{height:100%;display:flex;flex-direction:column}
.header{height:52px;flex:none;background:#fff;border-bottom:1px solid #d8dbe0;display:flex;align-items:center;padding:0 16px}
.title{font-size:18px;font-weight:700}
.header-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.video-name{font-size:12px;color:#666}
.primary{border:0;border-radius:5px;background:#2864d7;color:#fff;padding:8px 14px}
.editor{flex:1;min-height:0;display:flex;flex-direction:column;margin:10px;background:#fff;border:1px solid #d8dbe0;border-radius:7px;overflow:hidden}
.preview{height:38%;min-height:190px;flex:none;background:#111;display:flex;align-items:center;justify-content:center;position:relative;color:#999}
.preview video{width:100%;height:100%;object-fit:contain;background:#111}
.preview-label{position:absolute;left:10px;top:10px;background:#0009;color:#fff;padding:5px 8px;border-radius:4px;font-size:12px;z-index:2}
.toolbar{height:44px;flex:none;border-bottom:1px solid #e1e3e7;display:flex;align-items:center;padding:0 10px;gap:7px}
.toolbar button{border:1px solid #cfd3d9;background:#fff;border-radius:5px;padding:6px 11px}
.toolbar button.active{border-color:#2864d7;background:#eef4ff;color:#2864d7}
.status{margin-left:auto;color:#666;font-size:12px}
.canvas-wrap{position:relative;flex:1;min-height:180px;overflow:auto;background:#fafbfc}
.canvas{position:relative;width:100%;height:100%;min-width:700px;min-height:300px;background-image:linear-gradient(#e9ecf0 1px,transparent 1px),linear-gradient(90deg,#e9ecf0 1px,transparent 1px);background-size:24px 24px}
.connections{position:absolute;left:0;top:0;width:100%;height:100%;pointer-events:none;overflow:visible}
.object{position:absolute;width:140px;min-height:58px;padding:10px;background:#fff;border:2px solid #2864d7;border-radius:7px;box-shadow:0 2px 5px #0001;cursor:move;user-select:none;z-index:2}
.object.selected{outline:2px solid #2864d7;outline-offset:3px}
.object.hidden{display:none}
.object-name{font-size:13px;font-weight:700}
.object-info{font-size:11px;color:#777;margin-top:5px}
.timeline{height:112px;flex:none;border-top:1px solid #dfe2e6;padding:8px 10px;background:#fff}
.timeline-head{height:24px;display:flex;align-items:center}
.time{font-size:12px;color:#555}
.scale{margin-left:auto;display:flex;align-items:center;gap:5px}
.scale button{width:27px;height:24px;border:1px solid #cfd3d9;background:#fff;border-radius:4px;padding:0}
.scale span{width:52px;text-align:center;font-size:12px}
.ruler-wrap{height:68px;overflow-x:auto;overflow-y:hidden;margin-top:4px;border-top:1px solid #aaa}
.ruler{height:67px;position:relative;min-width:100%}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #c1c5ca}
.tick.major{border-color:#92979e}
.tick span{position:absolute;top:5px;left:4px;font-size:10px;color:#666;white-space:nowrap}
.scrub{position:absolute;inset:0;z-index:3;cursor:pointer}
.playhead{position:absolute;top:0;width:2px;height:100%;background:#e23b3b;z-index:4;pointer-events:none}
.context{position:fixed;display:none;z-index:20;background:#fff;border:1px solid #cfd3d9;border-radius:6px;box-shadow:0 6px 20px #0003;padding:5px;min-width:170px}
.context.show{display:block}
.context button{display:block;width:100%;border:0;background:#fff;text-align:left;padding:8px 10px;border-radius:4px}
.context button:hover{background:#eef3ff}
.modal{position:fixed;inset:0;background:#0007;display:none;align-items:center;justify-content:center;z-index:30}
.modal.show{display:flex}
.modal-box{width:380px;max-width:calc(100% - 24px);background:#fff;border-radius:8px;padding:18px;box-shadow:0 15px 40px #0005}
.modal-box h2{margin:0 0 14px;font-size:18px}
.choices{display:grid;gap:8px}
.choice{width:100%;text-align:left;border:1px solid #d2d6dc;background:#fafafa;border-radius:6px;padding:12px}
.choice:hover{border-color:#2864d7;background:#eef4ff}
.choice strong{display:block;margin-bottom:4px}
.choice small{color:#666}
.modal-actions{text-align:right;margin-top:14px}
.modal-actions button{border:1px solid #cfd3d9;background:#fff;border-radius:5px;padding:7px 12px}
</style>
</head>
<body>
<div class="app">
<header class="header">
  <div class="title">動画編集</div>
  <div class="header-right">
    <span class="video-name" id="videoName">動画未選択</span>
    <button class="primary" id="selectVideo">動画を選択</button>
  </div>
</header>

<section class="editor">
  <div class="preview" id="preview">
    <span id="emptyMessage">動画を選択してください</span>
    <video id="video" controls playsinline hidden></video>
    <div class="preview-label" id="videoLabel" hidden></div>
  </div>

  <div class="toolbar">
    <button id="connectButton">接続</button>
    <button id="deleteButton">削除</button>
    <span class="status" id="status">キャンバスを右クリックして要素を追加</span>
  </div>

  <div class="canvas-wrap" id="canvasWrap">
    <div class="canvas" id="canvas">
      <svg class="connections" id="connections"></svg>
    </div>
  </div>

  <div class="timeline">
    <div class="timeline-head">
      <span class="time" id="time">00:00.0 / 00:30.0</span>
      <div class="scale">
        <button id="zoomOut">−</button>
        <span id="scale">1.0x</span>
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
</div>

<div class="context" id="context">
  <button id="add">要素を追加</button>
  <button id="source">接続元にする</button>
  <button id="target">接続先にする</button>
  <button id="toggle">表示／非表示</button>
  <button id="remove">削除</button>
</div>

<div class="modal" id="modal">
  <div class="modal-box">
    <h2>動画を選択</h2>
    <div class="choices">
      <button class="choice" data-video="original">
        <strong>オリジナル動画</strong>
        <small>録画した元の動画</small>
      </button>
      <button class="choice" data-video="work">
        <strong>作業動画</strong>
        <small>編集中の作業用動画</small>
      </button>
    </div>
    <div class="modal-actions">
      <button id="cancel">キャンセル</button>
    </div>
  </div>
</div>

<script>
const state={
  video:null,
  duration:30,
  time:0,
  scale:1,
  objects:[],
  links:[],
  selected:null,
  source:null,
  connect:false,
  nextId:1
};

const canvas=document.getElementById('canvas');
const svg=document.getElementById('connections');
const video=document.getElementById('video');
const modal=document.getElementById('modal');
const context=document.getElementById('context');
const playhead=document.getElementById('playhead');
const ruler=document.getElementById('ruler');
const rulerWrap=document.getElementById('rulerWrap');

const videoUrl='https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

function formatTime(v){
  v=Math.max(0,Number(v)||0);
  return String(Math.floor(v/60)).padStart(2,'0')+':'+
    (v%60).toFixed(1).padStart(4,'0');
}

function getObject(id){
  return state.objects.find(o=>o.id===id)||null;
}

function selectVideo(type){
  state.video=type;
  video.src=videoUrl;
  video.hidden=false;
  document.getElementById('emptyMessage').hidden=true;
  document.getElementById('videoLabel').hidden=false;
  document.getElementById('videoLabel').textContent=
    type==='original'?'オリジナル動画':'作業動画';
  document.getElementById('videoName').textContent=
    type==='original'?'オリジナル動画':'作業動画';
  video.currentTime=0;
  video.load();
  state.time=0;
  modal.classList.remove('show');
  updateTimeline();
}

document.getElementById('selectVideo').onclick=()=>{
  modal.classList.add('show');
};

document.getElementById('cancel').onclick=()=>{
  modal.classList.remove('show');
};

document.querySelectorAll('.choice').forEach(button=>{
  button.onclick=()=>selectVideo(button.dataset.video);
});

video.addEventListener('loadedmetadata',()=>{
  if(Number.isFinite(video.duration)&&video.duration>0){
    state.duration=video.duration;
  }
  updateTimeline();
});

video.addEventListener('timeupdate',()=>{
  state.time=video.currentTime;
  updateTimeline(false);
});

function scaleInfo(){
  if(state.scale===0.5)return {interval:10,px:28};
  if(state.scale===1)return {interval:5,px:28};
  if(state.scale===2)return {interval:1,px:40};
  return {interval:0.5,px:40};
}

function renderRuler(){
  ruler.querySelectorAll('.tick').forEach(e=>e.remove());

  const info=scaleInfo();
  const width=Math.max(
    rulerWrap.clientWidth,
    state.duration*info.px
  );

  ruler.style.width=width+'px';

  for(let t=0;t<=state.duration;t+=info.interval){
    const tick=document.createElement('div');
    tick.className='tick';
    if(t%5===0)tick.classList.add('major');
    tick.style.left=(t*info.px)+'px';

    const label=document.createElement('span');
    label.textContent=formatTime(t);
    tick.appendChild(label);
    ruler.appendChild(tick);
  }

  playhead.style.left=(state.time*info.px)+'px';
}

function updateTimeline(draw=true){
  document.getElementById('time').textContent=
    formatTime(state.time)+' / '+formatTime(state.duration);
  document.getElementById('scale').textContent=
    state.scale.toFixed(1)+'x';

  if(draw)renderRuler();
  else{
    const info=scaleInfo();
    playhead.style.left=(state.time*info.px)+'px';
  }
}

document.getElementById('zoomIn').onclick=()=>{
  state.scale=Math.min(4,state.scale*2);
  updateTimeline();
};

document.getElementById('zoomOut').onclick=()=>{
  state.scale=Math.max(0.5,state.scale/2);
  updateTimeline();
};

document.getElementById('scrub').onclick=e=>{
  if(!state.video)return;

  const rect=ruler.getBoundingClientRect();
  const info=scaleInfo();
  const x=Math.max(0,e.clientX-rect.left);
  video.currentTime=Math.min(
    state.duration,
    x/info.px
  );
};

function addObject(x,y){
  const id=state.nextId++;
  state.objects.push({
    id:id,
    name:'要素 '+id,
    x:Math.max(0,x),
    y:Math.max(0,y),
    visible:true
  });
  state.selected=id;
  renderObjects();
}

function renderObjects(){
  canvas.querySelectorAll('.object').forEach(e=>e.remove());

  state.objects.forEach(obj=>{
    const el=document.createElement('div');
    el.className='object'+
      (state.selected===obj.id?' selected':'')+
      (!obj.visible?' hidden':'');
    el.dataset.id=obj.id;
    el.style.left=obj.x+'px';
    el.style.top=obj.y+'px';

    const name=document.createElement('div');
    name.className='object-name';
    name.textContent=obj.name;

    const info=document.createElement('div');
    info.className='object-info';
    info.textContent='編集要素';

    el.append(name,info);

    el.addEventListener('mousedown',startDrag);

    el.addEventListener('click',e=>{
      if(e.button!==0)return;

      state.selected=obj.id;

      if(state.connect){
        if(state.source===null){
          state.source=obj.id;
          setStatus('接続先を選択してください');
        }else if(state.source!==obj.id){
          connect(state.source,obj.id);
          state.source=null;
          state.connect=false;
          document.getElementById('connectButton').classList.remove('active');
          setStatus('接続しました');
        }
      }

      renderObjects();
    });

    el.addEventListener('contextmenu',e=>{
      e.preventDefault();
      state.selected=obj.id;
      openContext(e.clientX,e.clientY,obj.id);
      renderObjects();
    });

    canvas.appendChild(el);
  });

  drawConnections();
}

function openContext(x,y,id){
  context.dataset.id=id;
  context.classList.add('show');

  context.style.left=Math.min(
    x,
    window.innerWidth-context.offsetWidth-8
  )+'px';

  context.style.top=Math.min(
    y,
    window.innerHeight-context.offsetHeight-8
  )+'px';
}

function closeContext(){
  context.classList.remove('show');
}

canvas.addEventListener('contextmenu',e=>{
  if(e.target.closest('.object'))return;

  e.preventDefault();

  const rect=canvas.getBoundingClientRect();
  addObject(
    e.clientX-rect.left,
    e.clientY-rect.top
  );

  closeContext();
});

document.addEventListener('click',e=>{
  if(!e.target.closest('.context'))closeContext();
});

document.getElementById('add').onclick=()=>{
  const rect=canvas.getBoundingClientRect();

  addObject(
    context.offsetLeft-rect.left,
    context.offsetTop-rect.top
  );

  closeContext();
};

document.getElementById('source').onclick=()=>{
  const id=Number(context.dataset.id);
  if(!getObject(id))return;

  state.selected=id;
  state.source=id;
  state.connect=true;
  document.getElementById('connectButton').classList.add('active');
  setStatus('接続先を選択してください');
  closeContext();
  renderObjects();
};

document.getElementById('target').onclick=()=>{
  const id=Number(context.dataset.id);

  if(
    state.source!==null&&
    state.source!==id
  ){
    connect(state.source,id);
    state.source=null;
    state.connect=false;
    document.getElementById('connectButton').classList.remove('active');
  }

  closeContext();
};

document.getElementById('toggle').onclick=()=>{
  const obj=getObject(Number(context.dataset.id));

  if(obj){
    obj.visible=!obj.visible;
    renderObjects();
  }

  closeContext();
};

document.getElementById('remove').onclick=()=>{
  removeObject(Number(context.dataset.id));
  closeContext();
};

document.getElementById('connectButton').onclick=()=>{
  state.connect=!state.connect;
  state.source=null;

  document.getElementById('connectButton')
    .classList.toggle('active',state.connect);

  setStatus(
    state.connect?
    '接続元を選択してください':
    'キャンバスを右クリックして要素を追加'
  );
};

document.getElementById('deleteButton').onclick=()=>{
  if(state.selected!==null){
    removeObject(state.selected);
  }
};

function connect(from,to){
  if(!getObject(from)||!getObject(to)||from===to)return;

  if(!state.links.some(l=>l.from===from&&l.to===to)){
    state.links.push({from:from,to:to});
  }

  drawConnections();
}

function removeObject(id){
  state.objects=state.objects.filter(o=>o.id!==id);

  state.links=state.links.filter(l=>
    l.from!==id&&l.to!==id
  );

  if(state.selected===id)state.selected=null;
  if(state.source===id)state.source=null;

  renderObjects();
}

function drawConnections(){
  svg.innerHTML='';

  const areaRect=canvas.getBoundingClientRect();

  state.links=state.links.filter(l=>
    getObject(l.from)&&getObject(l.to)
  );

  state.links.forEach(link=>{
    const from=getObject(link.from);
    const to=getObject(link.to);

    if(!from.visible)return;

    const a=canvas.querySelector(
      '.object[data-id="'+from.id+'"]'
    );
    const b=canvas.querySelector(
      '.object[data-id="'+to.id+'"]'
    );

    if(!a||!b)return;

    const ar=a.getBoundingClientRect();
    const br=b.getBoundingClientRect();

    const x1=ar.left-areaRect.left+ar.width;
    const y1=ar.top-areaRect.top+ar.height/2;
    const x2=br.left-areaRect.left;
    const y2=br.top-areaRect.top+br.height/2;

    const bend=Math.max(30,Math.abs(x2-x1)*0.4);

    const path=document.createElementNS(
      'http://www.w3.org/2000/svg',
      'path'
    );

    path.setAttribute(
      'd',
      `M ${x1} ${y1} C ${x1+bend} ${y1}, ${x2-bend} ${y2}, ${x2} ${y2}`
    );
    path.setAttribute('fill','none');
    path.setAttribute('stroke','#2864d7');
    path.setAttribute('stroke-width','2');
    path.setAttribute('stroke-linecap','round');

    svg.appendChild(path);
  });
}

let drag=null;

function startDrag(e){
  if(e.button!==0)return;

  const el=e.currentTarget;
  const obj=getObject(Number(el.dataset.id));

  if(!obj)return;

  state.selected=obj.id;

  drag={
    id:obj.id,
    startX:e.clientX,
    startY:e.clientY,
    x:obj.x,
    y:obj.y
  };

  renderObjects();
  e.preventDefault();
}

document.addEventListener('mousemove',e=>{
  if(!drag)return;

  const obj=getObject(drag.id);
  if(!obj)return;

  obj.x=Math.max(
    0,
    drag.x+e.clientX-drag.startX
  );

  obj.y=Math.max(
    0,
    drag.y+e.clientY-drag.startY
  );

  const el=canvas.querySelector(
    '.object[data-id="'+obj.id+'"]'
  );

  if(el){
    el.style.left=obj.x+'px';
    el.style.top=obj.y+'px';
  }

  drawConnections();
});

document.addEventListener('mouseup',()=>{
  drag=null;
});

function setStatus(text){
  document.getElementById('status').textContent=text;
}

canvas.addEventListener('scroll',drawConnections);
window.addEventListener('resize',()=>{
  renderRuler();
  drawConnections();
});

renderObjects();
updateTimeline();
</script>
</body>
</html>

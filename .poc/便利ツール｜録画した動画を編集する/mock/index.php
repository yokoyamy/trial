<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;font-family:Arial,"Noto Sans JP",sans-serif;color:#222;background:#f3f4f6}
button{font:inherit;cursor:pointer}
.app{height:100vh;display:flex;flex-direction:column;overflow:hidden}
.header{height:52px;flex:none;background:#fff;border-bottom:1px solid #d8dbe0;display:flex;align-items:center;padding:0 16px;gap:14px}
.title{font-size:18px;font-weight:700}
.header-actions{margin-left:auto;display:flex;align-items:center;gap:10px}
.video-name{font-size:13px;color:#666}
.btn{border:1px solid #cfd3d9;background:#fff;border-radius:5px;padding:7px 12px}
.btn.primary{border-color:#2864d7;background:#2864d7;color:#fff}
.main{flex:1;min-height:0;display:flex;flex-direction:column;padding:10px;gap:10px}
.preview{height:36%;min-height:190px;background:#111;border-radius:7px;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden}
.preview video{width:100%;height:100%;object-fit:contain;background:#111}
.preview.empty{color:#aaa}
.preview-label{position:absolute;left:10px;top:10px;color:#fff;background:#0009;border-radius:4px;padding:5px 8px;font-size:12px;z-index:2}
.editor{flex:1;min-height:0;background:#fff;border:1px solid #d8dbe0;border-radius:7px;display:flex;flex-direction:column;overflow:hidden}
.editor-head{height:44px;flex:none;border-bottom:1px solid #e1e3e7;display:flex;align-items:center;padding:0 10px;gap:7px}
.mode{margin-left:auto;color:#666;font-size:12px}
.canvas-wrap{position:relative;flex:1;min-height:180px;overflow:auto;background:#fafbfc}
.object-area{position:relative;min-width:100%;min-height:100%;height:100%;background-image:linear-gradient(#e8ebef 1px,transparent 1px),linear-gradient(90deg,#e8ebef 1px,transparent 1px);background-size:24px 24px}
.connections{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible}
.object{position:absolute;width:140px;min-height:58px;padding:10px;background:#fff;border:2px solid #2864d7;border-radius:7px;box-shadow:0 2px 6px #0001;user-select:none;cursor:move;z-index:2}
.object.selected{outline:2px solid #2864d7;outline-offset:3px}
.object.hidden{display:none}
.object-name{font-size:13px;font-weight:700}
.object-time{margin-top:5px;color:#777;font-size:11px}
.object-delete{position:absolute;right:4px;top:2px;border:0;background:none;color:#888;padding:0;font-size:15px}
.object-delete:hover{color:#d22}
.timeline{height:118px;flex:none;border-top:1px solid #dfe2e6;background:#fff;padding:8px 10px}
.timeline-head{height:24px;display:flex;align-items:center}
.time{font-size:12px;color:#555}
.scale{margin-left:auto;display:flex;align-items:center;gap:5px}
.scale button{width:27px;height:24px;padding:0;border:1px solid #cfd3d9;background:#fff;border-radius:4px}
.scale span{width:55px;text-align:center;font-size:12px}
.ruler-wrap{height:72px;position:relative;margin-top:5px;overflow-x:auto;overflow-y:hidden;border-top:1px solid #aaa}
.ruler{position:relative;height:71px;min-width:100%}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #bfc3c9}
.tick.major{border-color:#92979e}
.tick span{position:absolute;top:5px;left:4px;white-space:nowrap;color:#666;font-size:10px}
.scrub{position:absolute;inset:0;z-index:3;cursor:pointer}
.playhead{position:absolute;top:0;height:100%;width:2px;background:#e23b3b;z-index:4;pointer-events:none}
.context{position:fixed;display:none;z-index:50;background:#fff;border:1px solid #cfd3d9;border-radius:6px;box-shadow:0 5px 18px #0003;min-width:170px;padding:5px}
.context.show{display:block}
.context button{display:block;width:100%;border:0;background:#fff;text-align:left;padding:8px 10px;border-radius:4px}
.context button:hover{background:#eef3ff}
.context button:disabled{color:#aaa;background:#fff;cursor:default}
.modal{position:fixed;inset:0;background:#0007;display:none;align-items:center;justify-content:center;z-index:40}
.modal.show{display:flex}
.modal-box{width:390px;max-width:calc(100vw - 24px);background:#fff;border-radius:8px;padding:18px;box-shadow:0 15px 45px #0005}
.modal-box h2{margin:0 0 14px;font-size:18px}
.video-list{display:grid;gap:8px}
.video-choice{width:100%;text-align:left;padding:12px;border:1px solid #d3d7dd;background:#fafafa;border-radius:6px}
.video-choice:hover,.video-choice.selected{border-color:#2864d7;background:#eef4ff}
.video-choice strong{display:block;margin-bottom:4px}
.video-choice small{color:#666}
.modal-actions{display:flex;justify-content:flex-end;margin-top:14px}
.empty-note{font-size:12px;color:#888;pointer-events:none}
@media(max-width:700px){
  .header{padding:0 10px}
  .video-name{display:none}
  .main{padding:6px}
  .preview{height:30%;min-height:160px}
}
</style>
</head>
<body>
<div class="app">

<header class="header">
  <div class="title">動画編集</div>
  <div class="header-actions">
    <span class="video-name" id="videoName">動画未選択</span>
    <button class="btn primary" id="selectVideoButton">動画を選択</button>
  </div>
</header>

<main class="main">

  <section class="preview empty" id="preview">
    <div class="empty-note" id="previewEmpty">動画を選択してください</div>
    <video id="video" controls playsinline hidden></video>
    <div class="preview-label" id="previewLabel" hidden></div>
  </section>

  <section class="editor">

    <div class="editor-head">
      <button class="btn" id="connectButton">接続</button>
      <button class="btn" id="deleteButton">削除</button>
      <span class="mode" id="modeText">キャンバスを右クリックして要素を追加</span>
    </div>

    <div class="canvas-wrap" id="canvasWrap">
      <div class="object-area" id="objectArea">
        <svg class="connections" id="connections"></svg>
      </div>
    </div>

    <div class="timeline">
      <div class="timeline-head">
        <span class="time" id="timeText">00:00.0 / 00:30.0</span>
        <div class="scale">
          <button id="zoomOut">−</button>
          <span id="scaleText">1.0x</span>
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
  <button id="addContext">要素を追加</button>
  <button id="connectSourceContext">接続元にする</button>
  <button id="connectTargetContext">接続先にする</button>
  <button id="toggleContext">表示／非表示</button>
  <button id="deleteContext">削除</button>
</div>

<div class="modal" id="videoModal">
  <div class="modal-box">
    <h2>動画を選択</h2>
    <div class="video-list">
      <button class="video-choice" data-video="original">
        <strong>オリジナル動画</strong>
        <small>録画した元の動画</small>
      </button>
      <button class="video-choice" data-video="work">
        <strong>作業動画</strong>
        <small>編集中の作業用動画</small>
      </button>
    </div>
    <div class="modal-actions">
      <button class="btn" id="closeModal">キャンセル</button>
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
  connections:[],
  selected:null,
  contextObject:null,
  connectSource:null,
  connectMode:false,
  nextId:1
};

const videoSources={
  original:'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
  work:'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4'
};

const preview=document.getElementById('preview');
const video=document.getElementById('video');
const objectArea=document.getElementById('objectArea');
const connections=document.getElementById('connections');
const canvasWrap=document.getElementById('canvasWrap');
const ruler=document.getElementById('ruler');
const rulerWrap=document.getElementById('rulerWrap');
const playhead=document.getElementById('playhead');
const timeText=document.getElementById('timeText');
const scaleText=document.getElementById('scaleText');
const contextMenu=document.getElementById('contextMenu');
const modeText=document.getElementById('modeText');

function fmt(sec){
  sec=Math.max(0,Number(sec)||0);
  return String(Math.floor(sec/60)).padStart(2,'0')+':'+
    (sec%60).toFixed(1).padStart(4,'0');
}

function objectById(id){
  return state.objects.find(o=>o.id===id)||null;
}

function visibleObject(id){
  const o=objectById(id);
  return o&&o.visible;
}

function selectVideo(type){
  state.video=type;
  video.hidden=false;
  preview.classList.remove('empty');
  document.getElementById('previewEmpty').hidden=true;
  document.getElementById('previewLabel').hidden=false;
  document.getElementById('previewLabel').textContent=
    type==='original'?'オリジナル動画':'作業動画';
  document.getElementById('videoName').textContent=
    type==='original'?'オリジナル動画':'作業動画';

  document.querySelectorAll('.video-choice').forEach(btn=>{
    btn.classList.toggle('selected',btn.dataset.video===type);
  });

  video.pause();
  video.src=videoSources[type];
  video.currentTime=0;
  video.load();

  state.time=0;
  state.duration=30;
  updateTimeline();

  document.getElementById('videoModal').classList.remove('show');
}

document.getElementById('selectVideoButton').onclick=()=>{
  document.getElementById('videoModal').classList.add('show');
};

document.getElementById('closeModal').onclick=()=>{
  document.getElementById('videoModal').classList.remove('show');
};

document.querySelectorAll('.video-choice').forEach(btn=>{
  btn.onclick=()=>selectVideo(btn.dataset.video);
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

function getScaleData(){
  if(state.scale===0.5)return {seconds:10,px:70};
  if(state.scale===1)return {seconds:5,px:90};
  if(state.scale===2)return {seconds:1,px:80};
  return {seconds:0.5,px:80};
}

function renderRuler(){
  ruler.querySelectorAll('.tick').forEach(el=>el.remove());

  const data=getScaleData();
  const totalWidth=Math.max(
    rulerWrap.clientWidth,
    state.duration*(data.px/data.seconds)
  );

  ruler.style.width=totalWidth+'px';

  for(let t=0;t<=state.duration+0.001;t+=data.seconds){
    const tick=document.createElement('div');
    tick.className='tick';
    if(Math.abs(t%5)<0.001)tick.classList.add('major');

    tick.style.left=(t*(data.px/data.seconds))+'px';

    const label=document.createElement('span');
    label.textContent=fmt(t);
    tick.appendChild(label);
    ruler.appendChild(tick);
  }

  const position=state.time*(data.px/data.seconds);
  playhead.style.left=position+'px';
}

function updateTimeline(renderRulerNow=true){
  timeText.textContent=fmt(state.time)+' / '+fmt(state.duration);
  scaleText.textContent=state.scale.toFixed(1)+'x';

  if(renderRulerNow)renderRuler();

  const data=getScaleData();
  playhead.style.left=(state.time*(data.px/data.seconds))+'px';
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
  const data=getScaleData();
  const x=e.clientX-rect.left;
  const next=Math.max(
    0,
    Math.min(state.duration,x/(data.px/data.seconds))
  );

  video.currentTime=next;
  state.time=next;
  updateTimeline(false);
};

function addObject(x,y){
  const id=state.nextId++;
  const obj={
    id:id,
    name:'要素 '+id,
    x:Math.max(0,x-70),
    y:Math.max(0,y-30),
    visible:true
  };

  state.objects.push(obj);
  state.selected=id;
  renderObjects();
  updateMode();
}

function renderObjects(){
  objectArea.querySelectorAll('.object').forEach(el=>el.remove());

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

    const tm=document.createElement('div');
    tm.className='object-time';
    tm.textContent='編集要素';

    const del=document.createElement('button');
    del.className='object-delete';
    del.textContent='×';
    del.title='削除';

    del.addEventListener('click',e=>{
      e.stopPropagation();
      deleteObject(obj.id);
    });

    el.appendChild(name);
    el.appendChild(tm);
    el.appendChild(del);

    el.addEventListener('mousedown',startDrag);

    el.addEventListener('click',e=>{
      if(e.target===del)return;

      state.selected=obj.id;

      if(state.connectMode){
        if(state.connectSource===null){
          state.connectSource=obj.id;
          updateMode();
        }else if(state.connectSource!==obj.id){
          createConnection(state.connectSource,obj.id);
          state.connectSource=null;
          state.connectMode=false;
          updateMode();
        }
      }else{
        updateMode();
      }

      renderObjects();
    });

    el.addEventListener('contextmenu',e=>{
      e.preventDefault();
      state.selected=obj.id;
      state.contextObject=obj.id;
      renderObjects();
      openContext(e.clientX,e.clientY,true);
    });

    objectArea.appendChild(el);
  });

  drawConnections();
}

function deleteObject(id){
  state.objects=state.objects.filter(o=>o.id!==id);

  state.connections=state.connections.filter(c=>
    c.source!==id
  );

  if(state.selected===id)state.selected=null;
  if(state.contextObject===id)state.contextObject=null;
  if(state.connectSource===id)state.connectSource=null;

  renderObjects();
  updateMode();
  closeContext();
}

function createConnection(source,target){
  if(source===target)return;

  const duplicate=state.connections.some(c=>
    c.source===source&&c.target===target
  );

  if(duplicate)return;

  if(!objectById(source)||!objectById(target))return;

  state.connections.push({
    source:source,
    target:target
  });

  drawConnections();
}

function drawConnections(){
  connections.innerHTML='';

  const rect=objectArea.getBoundingClientRect();

  connections.setAttribute('width',objectArea.scrollWidth);
  connections.setAttribute('height',objectArea.scrollHeight);
  connections.setAttribute('viewBox','0 0 '+objectArea.scrollWidth+' '+objectArea.scrollHeight);

  state.connections=state.connections.filter(c=>
    objectById(c.source)&&objectById(c.target)
  );

  state.connections.forEach(c=>{
    if(!visibleObject(c.source))return;
    if(!visibleObject(c.target))return;

    const sourceEl=objectArea.querySelector(
      '.object[data-id="'+c.source+'"]'
    );
    const targetEl=objectArea.querySelector(
      '.object[data-id="'+c.target+'"]'
    );

    if(!sourceEl||!targetEl)return;

    const sr=sourceEl.getBoundingClientRect();
    const tr=targetEl.getBoundingClientRect();

    const x1=sr.left-rect.left+sr.width;
    const y1=sr.top-rect.top+sr.height/2;
    const x2=tr.left-rect.left;
    const y2=tr.top-rect.top+tr.height/2;

    const path=document.createElementNS(
      'http://www.w3.org/2000/svg',
      'path'
    );

    const dx=Math.max(35,Math.abs(x2-x1)*0.45);
    const d=
      'M '+x1+' '+y1+
      ' C '+(x1+dx)+' '+y1+
      ' '+(x2-dx)+' '+y2+
      ' '+x2+' '+y2;

    path.setAttribute('d',d);
    path.setAttribute('fill','none');
    path.setAttribute('stroke','#2864d7');
    path.setAttribute('stroke-width','2');
    path.setAttribute('stroke-linecap','round');

    connections.appendChild(path);
  });
}

let drag=null;

function startDrag(e){
  if(e.button!==0)return;

  const el=e.currentTarget;
  const id=Number(el.dataset.id);
  const obj=objectById(id);

  if(!obj)return;

  state.selected=id;
  renderObjects();

  drag={
    id:id,
    startX:e.clientX,
    startY:e.clientY,
    originX:obj.x,
    originY:obj.y
  };

  e.preventDefault();
}

document.addEventListener('mousemove',e=>{
  if(!drag)return;

  const obj=objectById(drag.id);
  if(!obj)return;

  obj.x=Math.max(0,drag.originX+e.clientX-drag.startX);
  obj.y=Math.max(0,drag.originY+e.clientY-drag.startY);

  const el=objectArea.querySelector(
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

function openContext(x,y,objectMode){
  contextMenu.classList.add('show');

  const sourceButton=document.getElementById('connectSourceContext');
  const targetButton=document.getElementById('connectTargetContext');
  const toggleButton=document.getElementById('toggleContext');
  const deleteButton=document.getElementById('deleteContext');

  sourceButton.style.display=objectMode?'block':'none';
  targetButton.style.display=objectMode?'block':'none';
  toggleButton.style.display=objectMode?'block':'none';
  deleteButton.style.display=objectMode?'block':'none';

  contextMenu.style.left=Math.min(
    x,
    window.innerWidth-contextMenu.offsetWidth-8
  )+'px';

  contextMenu.style.top=Math.min(
    y,
    window.innerHeight-contextMenu.offsetHeight-8
  )+'px';
}

function closeContext(){
  contextMenu.classList.remove('show');
}

objectArea.addEventListener('contextmenu',e=>{
  e.preventDefault();

  if(e.target.closest('.object'))return;

  const rect=objectArea.getBoundingClientRect();

  addObject(
    e.clientX-rect.left+canvasWrap.scrollLeft,
    e.clientY-rect.top+canvasWrap.scrollTop
  );

  closeContext();
});

document.addEventListener('click',e=>{
  if(!e.target.closest('.context'))closeContext();
});

document.getElementById('addContext').onclick=()=>{
  const rect=objectArea.getBoundingClientRect();

  addObject(
    contextMenu.offsetLeft-rect.left+canvasWrap.scrollLeft,
    contextMenu.offsetTop-rect.top+canvasWrap.scrollTop
  );

  closeContext();
};

document.getElementById('connectButton').onclick=()=>{
  state.connectMode=!state.connectMode;

  if(!state.connectMode){
    state.connectSource=null;
  }

  updateMode();
};

document.getElementById('connectSourceContext').onclick=()=>{
  if(state.contextObject!==null){
    state.connectSource=state.contextObject;
    state.connectMode=true;
    state.selected=state.contextObject;
    updateMode();
  }
  closeContext();
};

document.getElementById('connectTargetContext').onclick=()=>{
  if(
    state.connectSource!==null&&
    state.contextObject!==null&&
    state.connectSource!==state.contextObject
  ){
    createConnection(
      state.connectSource,
      state.contextObject
    );
    state.connectSource=null;
    state.connectMode=false;
    renderObjects();
    updateMode();
  }
  closeContext();
};

document.getElementById('toggleContext').onclick=()=>{
  const obj=objectById(state.contextObject);

  if(obj){
    obj.visible=!obj.visible;
    renderObjects();
  }

  closeContext();
};

document.getElementById('deleteContext').onclick=()=>{
  if(state.contextObject!==null){
    deleteObject(state.contextObject);
  }
};

document.getElementById('deleteButton').onclick=()=>{
  if(state.selected!==null){
    deleteObject(state.selected);
  }
};

function updateMode(){
  if(state.connectMode){
    if(state.connectSource===null){
      modeText.textContent='接続元を選択してください';
    }else{
      const obj=objectById(state.connectSource);
      modeText.textContent=
        (obj?obj.name:'接続元')+' → 接続先を選択してください';
    }
  }else{
    modeText.textContent='キャンバスを右クリックして要素を追加';
  }
}

window.addEventListener('resize',()=>{
  renderRuler();
  drawConnections();
});

canvasWrap.addEventListener('scroll',drawConnections);

renderObjects();
updateTimeline();
updateMode();
</script>
</body>
</html>

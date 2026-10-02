<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{
  margin:0;height:100%;
  font-family:Arial,"Noto Sans JP",sans-serif;
  color:#222;background:#f3f4f6
}
button,select,input{font:inherit}
button{cursor:pointer}
.app{height:100vh;display:flex;flex-direction:column;overflow:hidden}

.header{
  height:54px;background:#fff;border-bottom:1px solid #d7d9dd;
  display:flex;align-items:center;padding:0 18px;gap:12px;flex:none
}
.logo{font-size:18px;font-weight:700}
.header-right{
  margin-left:auto;display:flex;align-items:center;gap:8px
}
.selected-video{font-size:12px;color:#666;margin-right:5px}
.primary{
  border:0;background:#2864d7;color:#fff;
  border-radius:6px;padding:8px 14px;font-weight:700
}
.secondary{
  border:1px solid #2864d7;background:#fff;color:#2454c5;
  border-radius:6px;padding:7px 13px;font-weight:700
}

.workspace{
  flex:1;min-height:0;
  display:grid;grid-template-columns:minmax(0,1fr) 260px;
  gap:10px;padding:10px
}
.center{
  display:flex;flex-direction:column;gap:10px;
  min-width:0;min-height:0
}

.preview{
  background:#111;border-radius:8px;
  min-height:250px;flex:0 0 35vh;
  display:flex;align-items:center;justify-content:center;
  position:relative;overflow:hidden;color:#aaa
}
.preview video{
  width:100%;height:100%;display:block;
  background:#111
}
.preview.empty{font-size:15px}
.preview-label{
  position:absolute;top:10px;left:10px;
  color:#fff;background:#0009;padding:5px 8px;
  border-radius:4px;font-size:12px;z-index:2
}

.editor{
  background:#fff;border:1px solid #d8dbe0;
  border-radius:8px;display:flex;flex-direction:column;
  min-height:0;flex:1;overflow:hidden
}
.editor-toolbar{
  height:48px;border-bottom:1px solid #e1e3e6;
  display:flex;align-items:center;gap:7px;
  padding:7px 10px;flex:none
}
.editor-toolbar button{
  border:1px solid #d4d8de;background:#fff;
  border-radius:5px;padding:6px 10px
}
.editor-toolbar button.active{
  border-color:#2864d7;background:#edf3ff;
  color:#2454c5
}
.status{
  margin-left:auto;font-size:12px;color:#666
}

.canvas-wrap{
  position:relative;flex:1;min-height:240px;
  overflow:auto;background:#f8f9fb
}
.object-area{
  position:relative;width:100%;height:100%;min-height:300px;
  background-image:
    linear-gradient(#e8ebef 1px,transparent 1px),
    linear-gradient(90deg,#e8ebef 1px,transparent 1px);
  background-size:24px 24px
}
.connections{
  position:absolute;inset:0;width:100%;height:100%;
  pointer-events:none;overflow:visible
}
.object{
  position:absolute;width:130px;min-height:58px;
  background:#fff;border:2px solid #2864d7;
  border-radius:7px;padding:10px;
  box-shadow:0 2px 6px #0001;
  user-select:none;cursor:move;z-index:2
}
.object.selected{
  outline:2px solid #2864d7;outline-offset:3px
}
.object.hidden{display:none}
.object-name{font-weight:700;font-size:13px}
.object-time{font-size:11px;color:#777;margin-top:5px}
.object-delete{
  position:absolute;right:4px;top:3px;
  border:0;background:transparent;color:#888;
  font-size:15px;padding:0
}
.object-delete:hover{color:#d22}

.timeline{
  height:122px;border-top:1px solid #dfe2e6;
  background:#fff;flex:none;padding:9px 12px
}
.timeline-head{
  display:flex;align-items:center;height:25px
}
.time-label{font-size:12px;color:#555}
.scale{
  margin-left:auto;display:flex;
  align-items:center;gap:6px
}
.scale button{
  width:28px;height:25px;
  border:1px solid #d4d8de;background:#fff;
  border-radius:4px
}
.scale span{
  width:58px;text-align:center;font-size:12px
}
.ruler{
  height:72px;position:relative;margin-top:3px;
  overflow:hidden;border-top:1px solid #aaa
}
.tick{
  position:absolute;top:0;height:100%;
  border-left:1px solid #bfc3c9
}
.tick span{
  position:absolute;top:5px;left:4px;
  font-size:10px;color:#666;white-space:nowrap
}
.playhead{
  position:absolute;top:0;width:2px;height:100%;
  background:#e23b3b;z-index:4;pointer-events:none
}
.scrub{
  position:absolute;inset:0;cursor:pointer
}

.right-panel{
  background:#fff;border:1px solid #d8dbe0;
  border-radius:8px;min-height:0;overflow:hidden
}
.panel-title{
  height:42px;padding:12px 14px;
  border-bottom:1px solid #e1e3e6;font-weight:700
}
.panel-body{
  padding:12px;overflow:auto;height:calc(100% - 42px)
}
.inspector-row{margin-bottom:14px}
.inspector-row label{
  display:block;font-size:12px;color:#666;
  margin-bottom:5px
}
.inspector-row input,
.inspector-row select{
  width:100%;padding:7px;
  border:1px solid #d4d8de;border-radius:5px
}
.obj-buttons{display:grid;gap:7px}
.obj-buttons button{
  border:1px solid #d4d8de;background:#fff;
  border-radius:5px;padding:8px;text-align:left
}
.empty-state{
  color:#888;text-align:center;padding:25px 10px
}
.help{
  font-size:12px;line-height:1.7;
  color:#666;margin-top:15px
}

.modal{
  position:fixed;inset:0;background:#0007;
  display:none;align-items:center;
  justify-content:center;z-index:20
}
.modal.show{display:flex}
.modal-box{
  width:400px;max-width:calc(100vw - 30px);
  background:#fff;border-radius:9px;
  box-shadow:0 15px 45px #0005;padding:20px
}
.modal-box h2{
  margin:0 0 15px;font-size:18px
}
.video-list{display:grid;gap:8px}
.video-choice{
  width:100%;text-align:left;
  border:1px solid #d7dbe2;background:#fafafa;
  border-radius:7px;padding:12px
}
.video-choice:hover{
  border-color:#2864d7;background:#eef4ff
}
.video-choice strong{
  display:block;margin-bottom:5px
}
.video-choice small{color:#666}
.modal-actions{
  display:flex;justify-content:flex-end;
  gap:8px;margin-top:18px
}

@media(max-width:900px){
  .workspace{grid-template-columns:1fr}
  .right-panel{display:none}
}
</style>
</head>

<body>
<div class="app">

<header class="header">
  <div class="logo">動画編集</div>

  <div class="header-right">
    <span class="selected-video" id="selectedVideoText">
      編集対象：未選択
    </span>

    <button class="secondary" id="openVideo">
      動画を開く
    </button>

    <button class="primary" id="resumeWork">
      編集作業を再開する
    </button>
  </div>
</header>

<div class="workspace">

<main class="center">

  <div class="preview empty" id="preview">

    <span id="emptyText">
      「動画を開く」または「編集作業を再開する」を選択してください
    </span>

    <video
      id="video"
      controls
      playsinline
      style="display:none"
    ></video>

    <div
      class="preview-label"
      id="videoLabel"
      style="display:none"
    ></div>

  </div>

  <section class="editor">

    <div class="editor-toolbar">

      <button id="addObject">
        ＋ オブジェクト
      </button>

      <button id="connectMode">
        接続
      </button>

      <button id="hideObject">
        表示／非表示
      </button>

      <button id="deleteObject">
        削除
      </button>

      <span class="status" id="modeStatus">
        通常操作
      </span>

    </div>

    <div class="canvas-wrap">
      <div class="object-area" id="objectArea">
        <svg
          class="connections"
          id="connections"
        ></svg>
      </div>
    </div>

    <div class="timeline">

      <div class="timeline-head">

        <span class="time-label" id="currentTime">
          00:00.0 / 00:30.0
        </span>

        <div class="scale">

          <button id="zoomOut">−</button>

          <span id="scaleText">1.0x</span>

          <button id="zoomIn">＋</button>

        </div>

      </div>

      <div class="ruler" id="ruler">
        <div class="scrub" id="scrub"></div>
        <div class="playhead" id="playhead"></div>
      </div>

    </div>

  </section>

</main>

<aside class="right-panel">

  <div class="panel-title">
    オブジェクト操作
  </div>

  <div class="panel-body">

    <div
      id="inspectorEmpty"
      class="empty-state"
    >
      オブジェクトを選択してください。
    </div>

    <div
      id="inspector"
      style="display:none"
    >

      <div class="inspector-row">

        <label>名前</label>

        <input
          id="objectName"
          type="text"
        >

      </div>

      <div class="inspector-row">

        <label>表示状態</label>

        <select id="objectVisible">
          <option value="1">表示</option>
          <option value="0">非表示</option>
        </select>

      </div>

      <div class="obj-buttons">

        <button id="connectFrom">
          このオブジェクトを接続元にする
        </button>

        <button id="connectTo">
          このオブジェクトを接続先にする
        </button>

      </div>

    </div>

  </div>

</aside>

</div>
</div>

<div class="modal" id="videoModal">

  <div class="modal-box">

    <h2>動画を開く</h2>

    <div class="video-list">

      <button
        class="video-choice"
        data-video="original"
      >
        <strong>オリジナル動画</strong>
        <small>録画した元の動画を編集対象にします。</small>
      </button>

      <button
        class="video-choice"
        data-video="work"
      >
        <strong>作業動画</strong>
        <small>編集中の作業用動画を編集対象にします。</small>
      </button>

    </div>

    <div class="modal-actions">
      <button id="closeModal">
        キャンセル
      </button>
    </div>

  </div>

</div>

<script>
const state={
  video:null,
  objects:[],
  connections:[],
  selected:null,
  connectMode:false,
  connectSource:null,
  nextId:1,
  scale:1,
  duration:30,
  time:0
};

const area=document.getElementById('objectArea');
const svg=document.getElementById('connections');
const video=document.getElementById('video');
const preview=document.getElementById('preview');
const playhead=document.getElementById('playhead');
const ruler=document.getElementById('ruler');
const currentTime=document.getElementById('currentTime');
const scaleText=document.getElementById('scaleText');
const status=document.getElementById('modeStatus');
const modal=document.getElementById('videoModal');

const videoSources={
  original:
    'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
  work:
    'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.webm'
};

function fmt(sec){
  sec=Math.max(0,sec);

  return String(
    Math.floor(sec/60)
  ).padStart(2,'0')+':'+
  (sec%60).toFixed(1).padStart(4,'0');
}

function objectById(id){
  return state.objects.find(o=>o.id===id);
}

function clearObjects(){
  state.objects=[];
  state.connections=[];
  state.selected=null;
  state.connectSource=null;
  state.nextId=1;
}

function selectVideo(type){

  state.video=type;

  video.src=videoSources[type];
  video.style.display='block';

  preview.classList.remove('empty');

  document.getElementById('emptyText')
    .style.display='none';

  const label=document.getElementById('videoLabel');

  label.style.display='block';

  label.textContent=
    type==='original'
      ?'オリジナル動画'
      :'作業動画';

  document.getElementById(
    'selectedVideoText'
  ).textContent=
    type==='original'
      ?'編集対象：オリジナル動画'
      :'編集対象：作業動画';

  document.querySelectorAll(
    '.video-choice'
  ).forEach(button=>{
    button.classList.toggle(
      'selected',
      button.dataset.video===type
    );
  });

  modal.classList.remove('show');

  video.currentTime=0;
  video.load();

  state.time=0;

  if(type==='work'){
    createWorkObjects();
  }else{
    createOriginalObjects();
  }

  renderObjects();
  updateInspector();
  updateTimeline();
}

function createOriginalObjects(){

  clearObjects();

  state.objects=[
    {
      id:1,
      name:'編集ポイント 1',
      x:70,
      y:70,
      visible:true
    },
    {
      id:2,
      name:'編集ポイント 2',
      x:300,
      y:180,
      visible:true
    }
  ];

  state.nextId=3;
}

function createWorkObjects(){

  clearObjects();

  state.objects=[
    {
      id:1,
      name:'編集ポイント 1',
      x:70,
      y:70,
      visible:true
    },
    {
      id:2,
      name:'編集ポイント 2',
      x:300,
      y:180,
      visible:true
    },
    {
      id:3,
      name:'編集ポイント 3',
      x:530,
      y:80,
      visible:true
    }
  ];

  state.connections=[
    {from:1,to:2},
    {from:2,to:3}
  ];

  state.nextId=4;
}

document.querySelectorAll(
  '.video-choice'
).forEach(button=>{

  button.addEventListener(
    'click',
    ()=>{
      selectVideo(button.dataset.video);
    }
  );

});

document.getElementById(
  'openVideo'
).onclick=()=>{

  modal.classList.add('show');

};

document.getElementById(
  'resumeWork'
).onclick=()=>{

  selectVideo('work');

};

document.getElementById(
  'closeModal'
).onclick=()=>{

  modal.classList.remove('show');

};

video.addEventListener(
  'loadedmetadata',
  ()=>{

    state.duration=
      Number.isFinite(video.duration)&&
      video.duration>0
        ?video.duration
        :30;

    updateTimeline();

  }
);

video.addEventListener(
  'timeupdate',
  ()=>{

    state.time=video.currentTime;

    updateTimeline();

  }
);

function updateTimeline(){

  currentTime.textContent=
    fmt(state.time)+' / '+fmt(state.duration);

  const ratio=
    state.duration
      ?state.time/state.duration
      :0;

  playhead.style.left=
    (ratio*100)+'%';

  renderRuler();
}

function getInterval(){

  if(state.scale>=2){
    return 1;
  }

  if(state.scale===1){
    return 5;
  }

  return 10;
}

function renderRuler(){

  ruler.querySelectorAll(
    '.tick'
  ).forEach(element=>{
    element.remove();
  });

  const interval=getInterval();

  const pxPerSecond=
    22*state.scale;

  const totalWidth=Math.max(
    ruler.clientWidth,
    state.duration*pxPerSecond
  );

  ruler.style.minWidth=
    totalWidth+'px';

  const count=
    Math.ceil(
      state.duration/interval
    );

  for(
    let i=0;
    i<=count;
    i++
  ){

    const time=i*interval;

    if(time>state.duration){
      break;
    }

    const tick=
      document.createElement('div');

    tick.className='tick';

    tick.style.left=
      (time*pxPerSecond)+'px';

    const label=
      document.createElement('span');

    label.textContent=
      fmt(time);

    tick.appendChild(label);

    ruler.appendChild(tick);
  }

  const ratio=
    state.duration
      ?state.time/state.duration
      :0;

  playhead.style.left=
    (ratio*100)+'%';
}

document.getElementById(
  'zoomIn'
).onclick=()=>{

  state.scale=
    Math.min(
      4,
      state.scale*2
    );

  scaleText.textContent=
    state.scale.toFixed(1)+'x';

  updateTimeline();

};

document.getElementById(
  'zoomOut'
).onclick=()=>{

  state.scale=
    Math.max(
      .5,
      state.scale/2
    );

  scaleText.textContent=
    state.scale.toFixed(1)+'x';

  updateTimeline();

};

document.getElementById(
  'scrub'
).onclick=e=>{

  if(!state.video){
    return;
  }

  const rect=
    ruler.getBoundingClientRect();

  const ratio=Math.max(
    0,
    Math.min(
      1,
      (e.clientX-rect.left)/rect.width
    )
  );

  video.currentTime=
    ratio*state.duration;
};

function addObject(){

  const id=state.nextId++;

  state.objects.push({
    id:id,
    name:'オブジェクト '+id,
    x:40+(id%4)*155,
    y:35+
      Math.floor(
        (id-1)/4
      )*95,
    visible:true
  });

  renderObjects();

  selectObject(id);
}

document.getElementById(
  'addObject'
).onclick=addObject;

function selectObject(id){

  state.selected=id;

  renderObjects();
  updateInspector();
}

function renderObjects(){

  area.querySelectorAll(
    '.object'
  ).forEach(element=>{
    element.remove();
  });

  state.objects.forEach(obj=>{

    const element=
      document.createElement('div');

    element.className=
      'object'+
      (
        state.selected===obj.id
          ?' selected'
          :''
      )+
      (
        !obj.visible
          ?' hidden'
          :''
      );

    element.dataset.id=obj.id;

    element.style.left=
      obj.x+'px';

    element.style.top=
      obj.y+'px';

    const name=
      document.createElement('div');

    name.className='object-name';
    name.textContent=obj.name;

    element.appendChild(name);

    const time=
      document.createElement('div');

    time.className='object-time';
    time.textContent=
      '編集オブジェクト';

    element.appendChild(time);

    const del=
      document.createElement('button');

    del.className='object-delete';
    del.textContent='×';
    del.title='削除';

    del.onclick=e=>{

      e.stopPropagation();

      deleteObject(obj.id);

    };

    element.appendChild(del);

    element.addEventListener(
      'mousedown',
      startDrag
    );

    element.addEventListener(
      'click',
      e=>{

        if(e.target===del){
          return;
        }

        selectObject(obj.id);

        if(state.connectMode){
          handleConnectClick(obj.id);
        }

      }
    );

    area.appendChild(element);

  });

  drawConnections();
}

let drag=null;

function startDrag(e){

  if(e.button!==0){
    return;
  }

  const id=
    Number(
      e.currentTarget.dataset.id
    );

  const obj=objectById(id);

  selectObject(id);

  drag={
    id:id,
    startX:e.clientX,
    startY:e.clientY,
    x:obj.x,
    y:obj.y
  };

  document.addEventListener(
    'mousemove',
    moveDrag
  );

  document.addEventListener(
    'mouseup',
    endDrag,
    {once:true}
  );
}

function moveDrag(e){

  if(!drag){
    return;
  }

  const obj=
    objectById(drag.id);

  obj.x=Math.max(
    0,
    drag.x+
      e.clientX-
      drag.startX
  );

  obj.y=Math.max(
    0,
    drag.y+
      e.clientY-
      drag.startY
  );

  const element=
    area.querySelector(
      '.object[data-id="'+
      drag.id+
      '"]'
    );

  if(element){

    element.style.left=
      obj.x+'px';

    element.style.top=
      obj.y+'px';
  }

  drawConnections();
}

function endDrag(){

  drag=null;

  document.removeEventListener(
    'mousemove',
    moveDrag
  );
}

function deleteObject(id){

  state.connections=
    state.connections.filter(
      connection=>
        connection.from!==id&&
        connection.to!==id
    );

  state.objects=
    state.objects.filter(
      object=>object.id!==id
    );

  if(state.selected===id){
    state.selected=null;
  }

  if(state.connectSource===id){
    state.connectSource=null;
  }

  renderObjects();
  updateInspector();
}

function updateInspector(){

  const obj=
    objectById(
      state.selected
    );

  const empty=
    document.getElementById(
      'inspectorEmpty'
    );

  const inspector=
    document.getElementById(
      'inspector'
    );

  if(!obj){

    empty.style.display='block';
    inspector.style.display='none';

    return;
  }

  empty.style.display='none';
  inspector.style.display='block';

  document.getElementById(
    'objectName'
  ).value=obj.name;

  document.getElementById(
    'objectVisible'
  ).value=
    obj.visible?'1':'0';
}

document.getElementById(
  'objectName'
).oninput=e=>{

  const obj=
    objectById(
      state.selected
    );

  if(!obj){
    return;
  }

  obj.name=e.target.value;

  const element=
    area.querySelector(
      '.object[data-id="'+
      obj.id+
      '"] .object-name'
    );

  if(element){
    element.textContent=
      obj.name;
  }
};

document.getElementById(
  'objectVisible'
).onchange=e=>{

  const obj=
    objectById(
      state.selected
    );

  if(!obj){
    return;
  }

  obj.visible=
    e.target.value==='1';

  renderObjects();
  updateInspector();
};

document.getElementById(
  'hideObject'
).onclick=()=>{

  const obj=
    objectById(
      state.selected
    );

  if(!obj){
    return;
  }

  obj.visible=
    !obj.visible;

  renderObjects();
  updateInspector();
};

document.getElementById(
  'deleteObject'
).onclick=()=>{

  if(state.selected!==null){
    deleteObject(
      state.selected
    );
  }
};

document.getElementById(
  'connectMode'
).onclick=()=>{

  state.connectMode=
    !state.connectMode;

  state.connectSource=null;

  document.getElementById(
    'connectMode'
  ).classList.toggle(
    'active',
    state.connectMode
  );

  status.textContent=
    state.connectMode
      ?'接続元を選択してください'
      :'通常操作';
};

document.getElementById(
  'connectFrom'
).onclick=()=>{

  if(state.selected===null){
    return;
  }

  state.connectMode=true;
  state.connectSource=
    state.selected;

  status.textContent=
    '接続先を選択してください';

  document.getElementById(
    'connectMode'
  ).classList.add('active');
};

document.getElementById(
  'connectTo'
).onclick=()=>{

  if(
    state.connectSource===null||
    state.selected===null
  ){
    return;
  }

  createConnection(
    state.connectSource,
    state.selected
  );

  state.connectSource=null;

  status.textContent=
    '接続元を選択してください';
};

function handleConnectClick(id){

  if(state.connectSource===null){

    state.connectSource=id;

    status.textContent=
      '接続先を選択してください';

    return;
  }

  if(state.connectSource===id){
    return;
  }

  createConnection(
    state.connectSource,
    id
  );

  state.connectSource=null;

  status.textContent=
    '接続元を選択してください';
}

function createConnection(from,to){

  if(
    !objectById(from)||
    !objectById(to)
  ){
    return;
  }

  const exists=
    state.connections.some(
      connection=>
        connection.from===from&&
        connection.to===to
    );

  if(!exists){

    state.connections.push({
      from:from,
      to:to
    });

  }

  drawConnections();
}

function drawConnections(){

  while(svg.firstChild){
    svg.removeChild(
      svg.firstChild
    );
  }

  const areaRect=
    area.getBoundingClientRect();

  state.connections=
    state.connections.filter(
      connection=>
        objectById(connection.from)&&
        objectById(connection.to)
    );

  state.connections.forEach(
    connection=>{

      const from=
        objectById(
          connection.from
        );

      const to=
        objectById(
          connection.to
        );

      if(
        !from||
        !to||
        !from.visible
      ){
        return;
      }

      const fromElement=
        area.querySelector(
          '.object[data-id="'+
          from.id+
          '"]'
        );

      const toElement=
        area.querySelector(
          '.object[data-id="'+
          to.id+
          '"]'
        );

      if(
        !fromElement||
        !toElement
      ){
        return;
      }

      const fromRect=
        fromElement.getBoundingClientRect();

      const toRect=
        toElement.getBoundingClientRect();

      const x1=
        fromRect.left-
        areaRect.left+
        fromRect.width;

      const y1=
        fromRect.top-
        areaRect.top+
        fromRect.height/2;

      const x2=
        toRect.left-
        areaRect.left;

      const y2=
        toRect.top-
        areaRect.top+
        toRect.height/2;

      const bend=
        Math.max(
          35,
          Math.abs(x2-x1)*.45
        );

      const path=
        document.createElementNS(
          'http://www.w3.org/2000/svg',
          'path'
        );

      path.setAttribute(
        'd',
        `M ${x1} ${y1}
         C ${x1+bend} ${y1},
           ${x2-bend} ${y2},
           ${x2} ${y2}`
      );

      path.setAttribute(
        'fill',
        'none'
      );

      path.setAttribute(
        'stroke',
        '#2864d7'
      );

      path.setAttribute(
        'stroke-width',
        '2'
      );

      path.setAttribute(
        'stroke-linecap',
        'round'
      );

      const arrow=
        document.createElementNS(
          'http://www.w3.org/2000/svg',
          'polygon'
        );

      const size=6;

      arrow.setAttribute(
        'points',
        `${x2},${y2}
         ${x2-size},${y2-size/2}
         ${x2-size},${y2+size/2}`
      );

      arrow.setAttribute(
        'fill',
        '#2864d7'
      );

      svg.appendChild(path);
      svg.appendChild(arrow);

    }
  );
}

window.addEventListener(
  'resize',
  ()=>{
    renderObjects();
    updateTimeline();
  }
);

addObject();
addObject();

state.objects[0].x=70;
state.objects[0].y=70;

state.objects[1].x=300;
state.objects[1].y=180;

state.selected=null;

renderObjects();
updateInspector();
updateTimeline();
</script>

</body>
</html>
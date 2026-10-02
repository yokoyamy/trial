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
html,body{margin:0;height:100%;font-family:Arial,"Noto Sans JP",sans-serif;color:#222}
button{font:inherit;cursor:pointer}
:root{--accent:#2864d7;--border:#d9dde4;--bg:#f5f6f8}
.app{height:100vh;display:flex;flex-direction:column;background:var(--bg);overflow:hidden}
.header{height:56px;flex:none;background:#fff;border-bottom:1px solid var(--border);
 display:flex;align-items:center;padding:0 18px}
.title{font-size:18px;font-weight:700}
.header-actions{margin-left:auto;display:flex;align-items:center;gap:12px}
.selected-video{font-size:13px;color:#666}
.primary{border:0;border-radius:6px;background:var(--accent);color:#fff;padding:9px 16px;font-weight:700}
.main{flex:1;min-height:0;padding:12px;display:flex;flex-direction:column;gap:12px}
.preview{height:38%;min-height:220px;background:#111;border-radius:8px;position:relative;
 display:flex;align-items:center;justify-content:center;overflow:hidden}
.preview video{width:100%;height:100%;object-fit:contain;background:#111}
.preview.empty{color:#aaa}
.video-label{position:absolute;left:10px;top:10px;background:#000b;color:#fff;
 padding:5px 8px;border-radius:4px;font-size:12px;display:none}
.editor{flex:1;min-height:0;background:#fff;border:1px solid var(--border);border-radius:8px;
 display:flex;flex-direction:column;overflow:hidden}
.editor-head{height:46px;flex:none;border-bottom:1px solid var(--border);
 display:flex;align-items:center;padding:0 12px;gap:8px}
.editor-head button{border:1px solid #ccd1d9;background:#fff;border-radius:5px;padding:6px 11px}
.editor-head button.active{border-color:var(--accent);background:#eef4ff;color:var(--accent)}
.help{margin-left:auto;font-size:12px;color:#777}
.stage-wrap{flex:1;min-height:180px;overflow:auto;background:#fafbfc}
.stage{position:relative;min-width:100%;min-height:100%;height:100%;
 background-image:linear-gradient(#e9edf2 1px,transparent 1px),
 linear-gradient(90deg,#e9edf2 1px,transparent 1px);
 background-size:24px 24px}
.connections{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:1}
.element{position:absolute;width:150px;min-height:62px;padding:10px 28px 10px 11px;
 background:#fff;border:2px solid var(--accent);border-radius:7px;z-index:2;
 box-shadow:0 2px 7px #0001;user-select:none;cursor:move}
.element.selected{outline:2px solid var(--accent);outline-offset:3px}
.element.hidden{display:none}
.element-name{font-size:13px;font-weight:700}
.element-time{font-size:11px;color:#777;margin-top:5px}
.element-delete{position:absolute;right:5px;top:3px;border:0;background:transparent;
 color:#888;font-size:16px;padding:0}
.element-delete:hover{color:#d22}
.timeline{height:108px;flex:none;border-top:1px solid var(--border);padding:8px 12px;background:#fff}
.timeline-head{height:24px;display:flex;align-items:center}
.time{font-size:12px;color:#555}
.scale{margin-left:auto;display:flex;align-items:center;gap:6px}
.scale button{width:28px;height:25px;border:1px solid #ccd1d9;background:#fff;border-radius:4px}
.scale-value{width:54px;text-align:center;font-size:12px}
.ruler-wrap{height:62px;overflow:auto;margin-top:4px}
.ruler{position:relative;height:58px;min-width:100%;border-top:1px solid #999}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #bcc1c8}
.tick span{position:absolute;top:4px;left:4px;font-size:10px;color:#666;white-space:nowrap}
.playhead{position:absolute;top:0;height:100%;width:2px;background:#e23b3b;z-index:5;pointer-events:none}
.scrub{position:absolute;inset:0;cursor:pointer;z-index:4}
.modal{position:fixed;inset:0;background:#0008;display:none;align-items:center;justify-content:center;z-index:20}
.modal.show{display:flex}
.modal-box{width:400px;max-width:calc(100vw - 30px);background:#fff;border-radius:9px;
 padding:20px;box-shadow:0 15px 45px #0005}
.modal-box h2{margin:0 0 16px;font-size:18px}
.video-list{display:grid;gap:9px}
.video-choice{width:100%;text-align:left;border:1px solid #d4d9e0;background:#fff;
 border-radius:7px;padding:13px}
.video-choice:hover,.video-choice.selected{border-color:var(--accent);background:#eef4ff}
.video-choice strong{display:block;margin-bottom:4px}
.video-choice small{color:#666}
.modal-foot{display:flex;justify-content:flex-end;margin-top:16px}
.context-menu{position:fixed;display:none;z-index:30;background:#fff;border:1px solid #ccd1d9;
 border-radius:6px;box-shadow:0 8px 24px #0003;padding:4px}
.context-menu.show{display:block}
.context-menu button{display:block;border:0;background:#fff;width:150px;text-align:left;
 padding:8px 10px;border-radius:4px}
.context-menu button:hover{background:#eef4ff;color:var(--accent)}
.notice{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);
 color:#888;font-size:13px;pointer-events:none}
@media(max-width:700px){
 .help{display:none}
 .preview{height:32%}
 .element{width:135px}
}
</style>
</head>
<body>
<div class="app">

<header class="header">
  <div class="title">動画編集</div>
  <div class="header-actions">
    <span class="selected-video" id="selectedVideo">動画未選択</span>
    <button class="primary" id="openVideo">動画を選択</button>
  </div>
</header>

<main class="main">

  <div class="preview empty" id="preview">
    <span id="previewEmpty">動画を選択してください</span>
    <video id="video" controls playsinline style="display:none"></video>
    <div class="video-label" id="videoLabel"></div>
  </div>

  <section class="editor">
    <div class="editor-head">
      <button id="connectButton">接続</button>
      <button id="hideButton">表示／非表示</button>
      <button id="deleteButton">削除</button>
      <span class="help" id="operationHelp">右クリックで要素を追加できます</span>
    </div>

    <div class="stage-wrap" id="stageWrap">
      <div class="stage" id="stage">
        <svg class="connections" id="connections"></svg>
        <div class="notice" id="stageNotice">右クリックで要素を追加</div>
      </div>
    </div>

    <div class="timeline">
      <div class="timeline-head">
        <span class="time" id="timeText">00:00.0 / 00:00.0</span>
        <div class="scale">
          <button id="zoomOut">−</button>
          <span class="scale-value" id="scaleText">1.0x</span>
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
        <small>編集中の作業動画</small>
      </button>
    </div>
    <div class="modal-foot">
      <button id="closeVideo">キャンセル</button>
    </div>
  </div>
</div>

<div class="context-menu" id="contextMenu">
  <button id="addElement">要素を追加</button>
</div>

<script>
const state={
  video:null,
  duration:0,
  time:0,
  scale:1,
  objects:[],
  connections:[],
  selected:null,
  connectMode:false,
  connectSource:null,
  nextId:1
};

const videoSources={
  original:'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
  work:'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.webm'
};

const stage=document.getElementById('stage');
const stageWrap=document.getElementById('stageWrap');
const svg=document.getElementById('connections');
const video=document.getElementById('video');
const preview=document.getElementById('preview');
const previewEmpty=document.getElementById('previewEmpty');
const videoLabel=document.getElementById('videoLabel');
const selectedVideo=document.getElementById('selectedVideo');
const modal=document.getElementById('videoModal');
const contextMenu=document.getElementById('contextMenu');
const ruler=document.getElementById('ruler');
const playhead=document.getElementById('playhead');
const timeText=document.getElementById('timeText');
const scaleText=document.getElementById('scaleText');
const connectButton=document.getElementById('connectButton');
const operationHelp=document.getElementById('operationHelp');

function formatTime(sec){
  sec=Math.max(0,Number(sec)||0);
  const min=Math.floor(sec/60);
  const s=(sec%60).toFixed(1).padStart(4,'0');
  return String(min).padStart(2,'0')+':'+s;
}

function openVideoSelector(){
  modal.classList.add('show');
}

function closeVideoSelector(){
  modal.classList.remove('show');
}

document.getElementById('openVideo').onclick=openVideoSelector;
document.getElementById('closeVideo').onclick=closeVideoSelector;

document.querySelectorAll('.video-choice').forEach(button=>{
  button.addEventListener('click',()=>{
    selectVideo(button.dataset.video);
  });
});

function selectVideo(type){
  state.video=type;
  state.time=0;
  video.pause();
  video.src=videoSources[type];
  video.style.display='block';
  preview.classList.remove('empty');
  previewEmpty.style.display='none';
  videoLabel.style.display='block';
  videoLabel.textContent=type==='original'?'オリジナル動画':'作業動画';
  selectedVideo.textContent=type==='original'?'オリジナル動画':'作業動画';
  closeVideoSelector();
  video.load();
  updateTimeline();
}

video.addEventListener('loadedmetadata',()=>{
  state.duration=Number.isFinite(video.duration)?video.duration:0;
  updateTimeline();
});

video.addEventListener('timeupdate',()=>{
  state.time=video.currentTime;
  updateTimeline();
});

video.addEventListener('durationchange',()=>{
  if(Number.isFinite(video.duration))state.duration=video.duration;
  updateTimeline();
});

function updateTimeline(){
  timeText.textContent=formatTime(state.time)+' / '+formatTime(state.duration);
  const ratio=state.duration>0?state.time/state.duration:0;
  playhead.style.left=(ratio*100)+'%';
  renderRuler();
}

function scaleSettings(){
  if(state.scale===0.5)return {seconds:10,pixels:10};
  if(state.scale===1)return {seconds:5,pixels:22};
  if(state.scale===2)return {seconds:1,pixels:44};
  return {seconds:0.5,pixels:70};
}

function renderRuler(){
  ruler.querySelectorAll('.tick').forEach(el=>el.remove());

  const settings=scaleSettings();
  const duration=Math.max(state.duration,1);
  const width=Math.max(
    stageWrap.clientWidth,
    duration*settings.pixels+20
  );

  ruler.style.width=width+'px';
  stage.style.minWidth=Math.max(stageWrap.clientWidth,width)+'px';

  for(let t=0;t<=duration+0.0001;t+=settings.seconds){
    const tick=document.createElement('div');
    tick.className='tick';
    tick.style.left=(t*settings.pixels)+'px';

    const label=document.createElement('span');
    label.textContent=formatTime(t);
    tick.appendChild(label);
    ruler.appendChild(tick);
  }

  const ratio=state.duration>0?state.time/state.duration:0;
  playhead.style.left=(ratio*100)+'%';
}

document.getElementById('zoomIn').onclick=()=>{
  state.scale=Math.min(4,state.scale*2);
  scaleText.textContent=state.scale.toFixed(1)+'x';
  updateTimeline();
};

document.getElementById('zoomOut').onclick=()=>{
  state.scale=Math.max(0.5,state.scale/2);
  scaleText.textContent=state.scale.toFixed(1)+'x';
  updateTimeline();
};

document.getElementById('scrub').onclick=e=>{
  if(!state.video||!state.duration)return;

  const rect=ruler.getBoundingClientRect();
  const settings=scaleSettings();
  const x=Math.max(0,e.clientX-rect.left);
  const t=Math.max(0,Math.min(state.duration,x/settings.pixels));

  video.currentTime=t;
};

stage.addEventListener('contextmenu',e=>{
  e.preventDefault();

  const rect=stage.getBoundingClientRect();
  const x=Math.max(0,e.clientX-rect.left);
  const y=Math.max(0,e.clientY-rect.top);

  contextMenu.dataset.x=String(x);
  contextMenu.dataset.y=String(y);
  contextMenu.style.left=e.clientX+'px';
  contextMenu.style.top=e.clientY+'px';
  contextMenu.classList.add('show');
});

document.addEventListener('click',e=>{
  if(!contextMenu.contains(e.target))contextMenu.classList.remove('show');
});

document.getElementById('addElement').onclick=()=>{
  const x=Number(contextMenu.dataset.x||40);
  const y=Number(contextMenu.dataset.y||40);

  const id=state.nextId++;
  state.objects.push({
    id,
    name:'要素 '+id,
    x,
    y,
    visible:true
  });

  contextMenu.classList.remove('show');
  state.selected=id;
  renderObjects();
};

function getObject(id){
  return state.objects.find(o=>o.id===id);
}

function selectObject(id){
  state.selected=id;
  renderObjects();
}

function renderObjects(){
  stage.querySelectorAll('.element').forEach(el=>el.remove());

  state.objects.forEach(obj=>{
    if(!obj.visible)return;

    const el=document.createElement('div');
    el.className='element'+(state.selected===obj.id?' selected':'');
    el.dataset.id=obj.id;
    el.style.left=obj.x+'px';
    el.style.top=obj.y+'px';

    const name=document.createElement('div');
    name.className='element-name';
    name.textContent=obj.name;

    const time=document.createElement('div');
    time.className='element-time';
    time.textContent='編集要素';

    const del=document.createElement('button');
    del.className='element-delete';
    del.textContent='×';
    del.title='削除';

    del.onclick=e=>{
      e.stopPropagation();
      deleteObject(obj.id);
    };

    el.appendChild(name);
    el.appendChild(time);
    el.appendChild(del);

    el.addEventListener('mousedown',startDrag);
    el.addEventListener('click',e=>{
      if(e.target!==del)handleObjectClick(obj.id);
    });

    stage.appendChild(el);
  });

  drawConnections();
  document.getElementById('stageNotice').style.display=
    state.objects.length?'none':'block';
}

function handleObjectClick(id){
  if(state.connectMode){
    if(state.connectSource===null){
      state.connectSource=id;
      state.selected=id;
      operationHelp.textContent='接続先を選択してください';
      renderObjects();
      return;
    }

    if(state.connectSource!==id){
      createConnection(state.connectSource,id);
      state.connectSource=null;
      state.selected=id;
      operationHelp.textContent='接続元を選択してください';
      renderObjects();
      return;
    }

    return;
  }

  selectObject(id);
}

let drag=null;

function startDrag(e){
  if(e.button!==0||state.connectMode)return;

  const id=Number(e.currentTarget.dataset.id);
  const obj=getObject(id);
  if(!obj)return;

  state.selected=id;
  renderObjects();

  drag={
    id,
    startX:e.clientX,
    startY:e.clientY,
    x:obj.x,
    y:obj.y
  };

  document.addEventListener('mousemove',moveDrag);
  document.addEventListener('mouseup',endDrag,{once:true});
}

function moveDrag(e){
  if(!drag)return;

  const obj=getObject(drag.id);
  if(!obj)return;

  obj.x=Math.max(0,drag.x+e.clientX-drag.startX);
  obj.y=Math.max(0,drag.y+e.clientY-drag.startY);

  const el=stage.querySelector('.element[data-id="'+drag.id+'"]');

  if(el){
    el.style.left=obj.x+'px';
    el.style.top=obj.y+'px';
  }

  drawConnections();
}

function endDrag(){
  drag=null;
  document.removeEventListener('mousemove',moveDrag);
}

function deleteObject(id){
  state.objects=state.objects.filter(obj=>obj.id!==id);
  state.connections=state.connections.filter(c=>c.from!==id&&c.to!==id);

  if(state.selected===id)state.selected=null;
  if(state.connectSource===id)state.connectSource=null;

  renderObjects();
}

document.getElementById('deleteButton').onclick=()=>{
  if(state.selected!==null)deleteObject(state.selected);
};

document.getElementById('hideButton').onclick=()=>{
  const obj=getObject(state.selected);
  if(!obj)return;

  obj.visible=!obj.visible;

  if(!obj.visible){
    state.connections=state.connections.filter(c=>c.from!==obj.id);
  }

  renderObjects();
};

connectButton.onclick=()=>{
  state.connectMode=!state.connectMode;
  state.connectSource=null;

  connectButton.classList.toggle('active',state.connectMode);

  operationHelp.textContent=state.connectMode
    ?'接続元を選択してください'
    :'右クリックで要素を追加できます';

  renderObjects();
};

function createConnection(from,to){
  const source=getObject(from);
  const target=getObject(to);

  if(!source||!target||!source.visible||!target.visible)return;

  const exists=state.connections.some(
    c=>c.from===from&&c.to===to
  );

  if(!exists){
    state.connections.push({from,to});
  }

  drawConnections();
}

function drawConnections(){
  while(svg.firstChild)svg.removeChild(svg.firstChild);

  state.connections=state.connections.filter(c=>{
    const from=getObject(c.from);
    const to=getObject(c.to);
    return !!from&&!!to&&from.visible&&to.visible;
  });

  const stageRect=stage.getBoundingClientRect();

  state.connections.forEach(c=>{
    const from=getObject(c.from);
    const to=getObject(c.to);
    if(!from||!to||!from.visible||!to.visible)return;

    const fromEl=stage.querySelector('.element[data-id="'+from.id+'"]');
    const toEl=stage.querySelector('.element[data-id="'+to.id+'"]');

    if(!fromEl||!toEl)return;

    const a=fromEl.getBoundingClientRect();
    const b=toEl.getBoundingClientRect();

    const x1=a.left-stageRect.left+a.width;
    const y1=a.top-stageRect.top+a.height/2;
    const x2=b.left-stageRect.left;
    const y2=b.top-stageRect.top+b.height/2;

    const bend=Math.max(30,Math.abs(x2-x1)*0.4);

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
    path.setAttribute('stroke','var(--accent)');
    path.setAttribute('stroke-width','2');
    path.setAttribute('stroke-linecap','round');

    svg.appendChild(path);
  });
}

window.addEventListener('resize',()=>{
  updateTimeline();
  drawConnections();
});

stageWrap.addEventListener('scroll',drawConnections);

updateTimeline();
renderObjects();
</script>
</body>
</html>

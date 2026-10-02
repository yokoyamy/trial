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
button{font:inherit;cursor:pointer}
.app{height:100vh;display:flex;flex-direction:column;overflow:hidden}
.header{height:56px;display:flex;align-items:center;padding:0 18px;background:#fff;border-bottom:1px solid #d9dce1;gap:18px;flex:none}
.title{font-size:18px;font-weight:700}
.header-actions{margin-left:auto;display:flex;align-items:center;gap:12px}
.selected-name{font-size:13px;color:#666}
.primary{border:0;border-radius:6px;background:#2864d7;color:#fff;padding:9px 15px;font-weight:700}
.main{flex:1;min-height:0;display:flex;flex-direction:column;padding:12px;gap:12px}
.preview{height:42%;min-height:230px;background:#111;border-radius:8px;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden}
.preview.empty{color:#aaa;font-size:14px}
.preview video{width:100%;height:100%;object-fit:contain;background:#111}
.video-label{position:absolute;left:10px;top:10px;background:#000b;color:#fff;padding:5px 8px;border-radius:4px;font-size:12px}
.editor{flex:1;min-height:0;background:#fff;border:1px solid #d9dce1;border-radius:8px;display:flex;flex-direction:column;overflow:hidden}
.editor-head{height:45px;border-bottom:1px solid #e0e2e6;display:flex;align-items:center;padding:0 12px;gap:8px;flex:none}
.mode{font-size:12px;color:#666;margin-left:auto}
.edit-area{position:relative;flex:1;min-height:180px;overflow:auto;background:#fafbfc}
.stage{position:relative;min-width:100%;min-height:100%;height:100%;background-image:linear-gradient(#e8ebef 1px,transparent 1px),linear-gradient(90deg,#e8ebef 1px,transparent 1px);background-size:24px 24px}
.connections{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible}
.item{position:absolute;width:140px;min-height:58px;padding:10px 28px 10px 10px;background:#fff;border:2px solid #2864d7;border-radius:7px;box-shadow:0 2px 6px #0001;cursor:move;user-select:none;z-index:2}
.item.selected{outline:2px solid #2864d7;outline-offset:3px}
.item.connect-source{outline:2px solid #2864d7;outline-offset:4px}
.item-name{font-weight:700;font-size:13px}
.item-info{font-size:11px;color:#777;margin-top:5px}
.item-delete{position:absolute;right:5px;top:3px;border:0;background:none;color:#888;font-size:16px;padding:0;line-height:1}
.item-delete:hover{color:#d22}
.timeline{height:118px;border-top:1px solid #dfe2e6;background:#fff;padding:9px 12px;flex:none}
.timeline-head{height:25px;display:flex;align-items:center}
.time{font-size:12px;color:#555}
.scale{margin-left:auto;display:flex;align-items:center;gap:6px}
.scale button{width:28px;height:25px;border:1px solid #d3d7dc;background:#fff;border-radius:4px}
.scale-value{width:64px;text-align:center;font-size:12px}
.ruler-wrap{height:70px;position:relative;overflow-x:auto;overflow-y:hidden;border-top:1px solid #aaa;margin-top:3px}
.ruler{height:70px;position:relative;min-width:100%}
.tick{position:absolute;top:0;height:70px;border-left:1px solid #bfc3c9}
.tick span{position:absolute;left:4px;top:5px;font-size:10px;color:#666;white-space:nowrap}
.playhead{position:absolute;top:0;width:2px;height:70px;background:#e23b3b;z-index:5;pointer-events:none}
.scrub{position:absolute;inset:0;cursor:pointer}
.modal{position:fixed;inset:0;background:#0008;display:none;align-items:center;justify-content:center;z-index:50}
.modal.show{display:flex}
.dialog{width:420px;max-width:calc(100vw - 30px);background:#fff;border-radius:9px;padding:20px;box-shadow:0 15px 45px #0005}
.dialog h2{margin:0 0 15px;font-size:18px}
.video-list{display:grid;gap:9px}
.video-choice{width:100%;text-align:left;border:1px solid #d4d8de;background:#fafafa;border-radius:7px;padding:13px}
.video-choice:hover{border-color:#2864d7;background:#eef4ff}
.video-choice strong{display:block;margin-bottom:5px}
.video-choice small{color:#666}
.dialog-actions{display:flex;justify-content:flex-end;margin-top:16px}
.secondary{border:1px solid #d4d8de;background:#fff;border-radius:6px;padding:8px 14px}
.context-help{position:absolute;left:12px;bottom:10px;background:#fffddf;border:1px solid #e5df9d;color:#625e2a;padding:6px 9px;border-radius:5px;font-size:11px;z-index:10;pointer-events:none}
@media(max-width:700px){
 .header{padding:0 10px}
 .selected-name{display:none}
 .main{padding:7px}
 .preview{height:35%;min-height:180px}
 .item{width:125px}
}
</style>
</head>
<body>
<div class="app">
<header class="header">
  <div class="title">動画編集</div>
  <div class="header-actions">
    <span class="selected-name" id="selectedName">動画未選択</span>
    <button class="primary" id="selectVideoButton">動画を選択</button>
  </div>
</header>

<main class="main">
  <section class="preview empty" id="preview">
    <span id="emptyMessage">「動画を選択」から編集する動画を選択してください</span>
    <video id="video" controls playsinline preload="metadata" hidden></video>
    <div class="video-label" id="videoLabel" hidden></div>
  </section>

  <section class="editor">
    <div class="editor-head">
      <span>編集</span>
      <span class="mode" id="mode">通常操作</span>
    </div>

    <div class="edit-area" id="editArea">
      <div class="stage" id="stage">
        <svg class="connections" id="connections"></svg>
      </div>
      <div class="context-help">右クリックで要素を追加</div>
    </div>

    <div class="timeline">
      <div class="timeline-head">
        <span class="time" id="timeLabel">00:00.0 / 00:30.0</span>
        <div class="scale">
          <button id="zoomOut" aria-label="縮小">−</button>
          <span class="scale-value" id="scaleValue">1.0x</span>
          <button id="zoomIn" aria-label="拡大">＋</button>
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
  <div class="dialog">
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
    <div class="dialog-actions">
      <button class="secondary" id="closeModal">キャンセル</button>
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
  connectSource:null,
  nextId:1,
  dragging:null
};

const videoSources={
  original:"https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4",
  work:"https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4"
};

const video=document.getElementById("video");
const preview=document.getElementById("preview");
const stage=document.getElementById("stage");
const connections=document.getElementById("connections");
const editArea=document.getElementById("editArea");
const ruler=document.getElementById("ruler");
const rulerWrap=document.getElementById("rulerWrap");
const playhead=document.getElementById("playhead");
const timeLabel=document.getElementById("timeLabel");
const scaleValue=document.getElementById("scaleValue");
const mode=document.getElementById("mode");
const modal=document.getElementById("videoModal");

function formatTime(seconds){
  seconds=Math.max(0,Number(seconds)||0);
  const minutes=Math.floor(seconds/60);
  const rest=(seconds%60).toFixed(1).padStart(4,"0");
  return String(minutes).padStart(2,"0")+":"+rest;
}

function openVideoSelector(){
  modal.classList.add("show");
}

function closeVideoSelector(){
  modal.classList.remove("show");
}

document.getElementById("selectVideoButton").addEventListener("click",openVideoSelector);
document.getElementById("closeModal").addEventListener("click",closeVideoSelector);

document.querySelectorAll(".video-choice").forEach(button=>{
  button.addEventListener("click",()=>{
    selectVideo(button.dataset.video);
  });
});

function selectVideo(type){
  state.video=type;
  state.time=0;
  video.src=videoSources[type];
  video.hidden=false;
  preview.classList.remove("empty");
  document.getElementById("emptyMessage").hidden=true;
  document.getElementById("videoLabel").hidden=false;
  document.getElementById("videoLabel").textContent=
    type==="original" ? "オリジナル動画" : "作業動画";
  document.getElementById("selectedName").textContent=
    type==="original" ? "オリジナル動画" : "作業動画";

  closeVideoSelector();
  video.load();

  video.addEventListener("loadedmetadata",()=>{
    if(Number.isFinite(video.duration) && video.duration>0){
      state.duration=video.duration;
    }else{
      state.duration=30;
    }
    updateTimeline();
  },{once:true});

  updateTimeline();
}

video.addEventListener("timeupdate",()=>{
  state.time=video.currentTime;
  updateTimeline();
});

video.addEventListener("durationchange",()=>{
  if(Number.isFinite(video.duration) && video.duration>0){
    state.duration=video.duration;
    updateTimeline();
  }
});

function updateTimeline(){
  timeLabel.textContent=formatTime(state.time)+" / "+formatTime(state.duration);
  const ratio=state.duration>0 ? state.time/state.duration : 0;
  playhead.style.left=(ratio*100)+"%";
  renderRuler();
}

function getScaleInfo(){
  if(state.scale===0.5){
    return {px:10,interval:10};
  }
  if(state.scale===1){
    return {px:20,interval:5};
  }
  if(state.scale===2){
    return {px:40,interval:2};
  }
  return {px:80,interval:1};
}

function renderRuler(){
  ruler.querySelectorAll(".tick").forEach(tick=>tick.remove());

  const info=getScaleInfo();
  const width=Math.max(rulerWrap.clientWidth,state.duration*info.px+100);
  ruler.style.width=width+"px";

  for(let t=0;t<=state.duration+0.001;t+=info.interval){
    const tick=document.createElement("div");
    tick.className="tick";
    tick.style.left=(t*info.px)+"px";

    const label=document.createElement("span");
    label.textContent=formatTime(t);
    tick.appendChild(label);

    ruler.appendChild(tick);
  }

  const ratio=state.duration>0 ? state.time/state.duration : 0;
  playhead.style.left=(ratio*100)+"%";
}

document.getElementById("zoomIn").addEventListener("click",()=>{
  state.scale=Math.min(4,state.scale*2);
  scaleValue.textContent=state.scale.toFixed(1)+"x";
  updateTimeline();
});

document.getElementById("zoomOut").addEventListener("click",()=>{
  state.scale=Math.max(0.5,state.scale/2);
  scaleValue.textContent=state.scale.toFixed(1)+"x";
  updateTimeline();
});

document.getElementById("scrub").addEventListener("click",event=>{
  if(!state.video)return;

  const rect=ruler.getBoundingClientRect();
  const info=getScaleInfo();
  const x=Math.max(0,event.clientX-rect.left);
  const time=Math.max(0,Math.min(state.duration,x/info.px));

  video.currentTime=time;
  state.time=time;
  updateTimeline();
});

function objectById(id){
  return state.objects.find(object=>object.id===id) || null;
}

function addObject(x,y){
  const id=state.nextId++;
  const object={
    id:id,
    name:"要素 "+id,
    x:Math.max(10,x-70),
    y:Math.max(10,y-30),
    visible:true
  };

  state.objects.push(object);
  state.selected=id;
  renderObjects();
  updateMode();
}

editArea.addEventListener("contextmenu",event=>{
  event.preventDefault();

  const rect=stage.getBoundingClientRect();
  const x=event.clientX-rect.left+stage.scrollLeft;
  const y=event.clientY-rect.top+stage.scrollTop;

  addObject(x,y);
});

function selectObject(id){
  if(!objectById(id))return;

  if(state.connectSource!==null && state.connectSource!==id){
    createConnection(state.connectSource,id);
    state.connectSource=null;
  }else if(state.connectSource===id){
    state.connectSource=null;
  }

  state.selected=id;
  renderObjects();
  updateMode();
}

function startConnection(id){
  if(!objectById(id))return;

  if(state.connectSource===null){
    state.connectSource=id;
  }else if(state.connectSource===id){
    state.connectSource=null;
  }else{
    createConnection(state.connectSource,id);
    state.connectSource=null;
  }

  state.selected=id;
  renderObjects();
  updateMode();
}

function createConnection(from,to){
  if(from===to)return;

  const exists=state.connections.some(connection=>
    connection.from===from && connection.to===to
  );

  if(!exists){
    state.connections.push({from:from,to:to});
  }

  drawConnections();
}

function removeConnectionsFor(id){
  state.connections=state.connections.filter(connection=>
    connection.from!==id && connection.to!==id
  );

  if(state.connectSource===id){
    state.connectSource=null;
  }
}

function deleteObject(id){
  state.objects=state.objects.filter(object=>object.id!==id);
  removeConnectionsFor(id);

  if(state.selected===id){
    state.selected=null;
  }

  renderObjects();
  updateMode();
}

function toggleObjectVisibility(id){
  const object=objectById(id);
  if(!object)return;

  object.visible=!object.visible;

  if(!object.visible && state.connectSource===id){
    state.connectSource=null;
  }

  renderObjects();
  updateMode();
}

function renderObjects(){
  stage.querySelectorAll(".item").forEach(item=>item.remove());

  state.objects.forEach(object=>{
    if(!object.visible)return;

    const element=document.createElement("div");
    element.className="item";
    element.dataset.id=String(object.id);
    element.style.left=object.x+"px";
    element.style.top=object.y+"px";

    if(state.selected===object.id){
      element.classList.add("selected");
    }

    if(state.connectSource===object.id){
      element.classList.add("connect-source");
    }

    const name=document.createElement("div");
    name.className="item-name";
    name.textContent=object.name;

    const info=document.createElement("div");
    info.className="item-info";
    info.textContent="右クリックで操作";

    const remove=document.createElement("button");
    remove.className="item-delete";
    remove.textContent="×";
    remove.title="削除";

    remove.addEventListener("click",event=>{
      event.stopPropagation();
      deleteObject(object.id);
    });

    element.appendChild(name);
    element.appendChild(info);
    element.appendChild(remove);

    element.addEventListener("mousedown",event=>{
      if(event.button!==0)return;
      if(event.target===remove)return;

      const rect=element.getBoundingClientRect();
      state.dragging={
        id:object.id,
        offsetX:event.clientX-rect.left,
        offsetY:event.clientY-rect.top
      };

      state.selected=object.id;
      renderObjects();
      updateMode();

      event.preventDefault();
    });

    element.addEventListener("click",event=>{
      if(event.target===remove)return;
      if(state.dragging)return;
      selectObject(object.id);
    });

    element.addEventListener("contextmenu",event=>{
      event.preventDefault();
      event.stopPropagation();

      state.selected=object.id;

      const menuAction=confirm(
        "「"+object.name+"」を操作します。\n\n"+
        "OK：接続元にする\n"+
        "キャンセル：表示／非表示を切り替える"
      );

      if(menuAction){
        startConnection(object.id);
      }else{
        toggleObjectVisibility(object.id);
      }
    });

    stage.appendChild(element);
  });

  drawConnections();
}

window.addEventListener("mousemove",event=>{
  if(!state.dragging)return;

  const object=objectById(state.dragging.id);
  if(!object)return;

  const rect=stage.getBoundingClientRect();

  object.x=Math.max(
    0,
    event.clientX-rect.left+stage.scrollLeft-state.dragging.offsetX
  );

  object.y=Math.max(
    0,
    event.clientY-rect.top+stage.scrollTop-state.dragging.offsetY
  );

  const element=stage.querySelector(
    '.item[data-id="'+object.id+'"]'
  );

  if(element){
    element.style.left=object.x+"px";
    element.style.top=object.y+"px";
  }

  drawConnections();
});

window.addEventListener("mouseup",()=>{
  state.dragging=null;
});

function drawConnections(){
  while(connections.firstChild){
    connections.removeChild(connections.firstChild);
  }

  state.connections=state.connections.filter(connection=>{
    const from=objectById(connection.from);
    const to=objectById(connection.to);

    if(!from || !to)return false;
    if(!from.visible)return false;

    return true;
  });

  state.connections.forEach(connection=>{
    const from=objectById(connection.from);
    const to=objectById(connection.to);

    if(!from || !to || !from.visible)return;

    const fromElement=stage.querySelector(
      '.item[data-id="'+from.id+'"]'
    );
    const toElement=stage.querySelector(
      '.item[data-id="'+to.id+'"]'
    );

    if(!fromElement || !toElement)return;

    const x1=from.x+fromElement.offsetWidth;
    const y1=from.y+fromElement.offsetHeight/2;
    const x2=to.x;
    const y2=to.y+toElement.offsetHeight/2;

    const dx=Math.max(35,Math.abs(x2-x1)*0.45);
    const path=document.createElementNS(
      "http://www.w3.org/2000/svg",
      "path"
    );

    path.setAttribute(
      "d",
      "M "+x1+" "+y1+
      " C "+(x1+dx)+" "+y1+
      " "+(x2-dx)+" "+y2+
      " "+x2+" "+y2
    );

    path.setAttribute("fill","none");
    path.setAttribute("stroke","#2864d7");
    path.setAttribute("stroke-width","2");
    path.setAttribute("stroke-linecap","round");

    connections.appendChild(path);
  });
}

function updateMode(){
  if(state.connectSource!==null){
    const source=objectById(state.connectSource);
    mode.textContent=source
      ? "接続元「"+source.name+"」→ 接続先を選択"
      : "通常操作";
  }else if(state.selected!==null){
    const selected=objectById(state.selected);
    mode.textContent=selected
      ? "選択中：「"+selected.name+"」"
      : "通常操作";
  }else{
    mode.textContent="通常操作";
  }
}

editArea.addEventListener("scroll",()=>{
  drawConnections();
});

window.addEventListener("resize",()=>{
  updateTimeline();
  drawConnections();
});

updateTimeline();
renderObjects();
updateMode();
</script>
</body>
</html>

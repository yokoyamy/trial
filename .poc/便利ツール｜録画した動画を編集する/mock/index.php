<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{
  margin:0;width:100%;height:100%;overflow:hidden;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
  color:#e8edf5;background:#0d1117
}
button,input,select{font:inherit}
button{cursor:pointer}
.app{height:100%;display:flex;flex-direction:column}

.header{
  height:54px;flex:none;display:flex;align-items:center;gap:8px;
  padding:0 12px;background:#161b22;border-bottom:1px solid #30363d
}
.title{font-weight:700;margin-right:8px;white-space:nowrap}
.btn{
  border:1px solid #3b4654;background:#242c36;color:#e8edf5;
  border-radius:6px;padding:7px 11px
}
.btn:hover{background:#303a47}
.primary{background:#1769aa;border-color:#2387d9}
.status{margin-left:auto;color:#9aa5b1;font-size:12px;white-space:nowrap}

.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{
  min-height:0;flex:1;background:#05070a;
  display:flex;align-items:center;justify-content:center;padding:12px
}
.video-wrap{
  position:relative;width:min(1200px,100%);height:100%;
  background:#000;overflow:hidden;border:1px solid #222
}
video{width:100%;height:100%;object-fit:contain;display:block}
.svg-layer{
  position:absolute;inset:0;width:100%;height:100%;
  overflow:visible;z-index:10;pointer-events:none
}
.connection-hit{
  fill:none;stroke:transparent;stroke-width:18;
  vector-effect:non-scaling-stroke;pointer-events:stroke;cursor:pointer
}
.connection{
  fill:none;stroke:#90a4ae;stroke-width:2;
  vector-effect:non-scaling-stroke;pointer-events:none
}
.connection.selected{stroke:#4da3ff;stroke-width:3}

.overlay{
  position:absolute;inset:0;z-index:20;pointer-events:none
}
.element{
  position:absolute;pointer-events:auto;user-select:none;
  touch-action:none;min-width:45px;min-height:28px;cursor:move
}
.element.selected{outline:2px solid #4da3ff;outline-offset:3px}
.element.multi{outline:2px solid #ffc107;outline-offset:3px}
.element.active-dim{opacity:.35}

.element-body{
  width:100%;height:100%;
  display:flex;align-items:center;justify-content:center;
  overflow:hidden
}
.comment .element-body{
  color:#fff;text-shadow:0 1px 4px #000;
  font-size:18px;padding:4px;background:#0005
}
.highlight .element-body{
  border:4px solid #f04444;background:transparent
}
.highlight.round .element-body{border-radius:18px}
.highlight.circle .element-body{border-radius:50%}
.zoombox .element-body{
  border:3px solid #54d68a;background:#ffffff18;
  box-shadow:0 0 0 9999px #0002
}
.handle{
  position:absolute;width:10px;height:10px;background:#fff;
  border:2px solid #4da3ff;z-index:5
}
.nw{left:-5px;top:-5px;cursor:nwse-resize}
.ne{right:-5px;top:-5px;cursor:nesw-resize}
.sw{left:-5px;bottom:-5px;cursor:nesw-resize}
.se{right:-5px;bottom:-5px;cursor:nwse-resize}

.hint{
  position:absolute;left:50%;top:50%;
  transform:translate(-50%,-50%);
  color:#66717f;text-align:center;pointer-events:none;
  line-height:1.8
}

.timeline{
  height:285px;flex:none;background:#11161d;
  border-top:1px solid #30363d;display:flex;flex-direction:column
}
.timeline-toolbar{
  height:44px;flex:none;display:flex;align-items:center;gap:8px;
  padding:5px 9px;border-bottom:1px solid #30363d
}
.time{
  font-variant-numeric:tabular-nums;min-width:170px;color:#dbeafe
}
.scale{
  margin-left:auto;display:flex;align-items:center;gap:7px;
  color:#8b949e;font-size:12px
}
.scale input{width:130px}

.timeline-scroll{
  min-height:0;flex:1;overflow:auto
}
.timeline-content{
  position:relative;min-width:700px;height:100%
}
.axis{
  height:32px;position:relative;margin-left:110px;
  background:#171d25;border-bottom:1px solid #30363d
}
.tick{
  position:absolute;top:0;height:100%;
  border-left:1px solid #343b45;color:#7d8590;
  font-size:10px;padding:4px 0 0 3px
}
.rows{margin-left:110px}
.row{
  height:48px;position:relative;
  border-bottom:1px solid #242b33
}
.row-label{
  position:absolute;right:100%;top:0;width:110px;height:48px;
  display:flex;align-items:center;padding:0 8px;gap:6px;
  background:#161b22;border-right:1px solid #30363d;
  font-size:11px
}
.dot{width:8px;height:8px;border-radius:50%;flex:none}
.lane{height:100%;position:relative}
.bar{
  position:absolute;top:8px;height:32px;border-radius:5px;
  border:1px solid currentColor;display:flex;
  align-items:center;cursor:grab;user-select:none;
  touch-action:none;overflow:visible
}
.bar.selected{box-shadow:0 0 0 2px #4da3ff}
.bar.multi{box-shadow:0 0 0 2px #ffc107}
.bar span{
  font-size:10px;padding:0 7px;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis
}
.bar-handle{
  position:absolute;top:0;height:100%;width:8px;
  cursor:ew-resize;z-index:2
}
.bar-handle.left{left:-4px}
.bar-handle.right{right:-4px}

.playhead{
  position:absolute;top:32px;bottom:0;width:2px;
  background:#f04444;z-index:100;pointer-events:none
}
.playhead:before{
  content:"";position:absolute;top:-2px;left:-5px;
  width:12px;height:12px;background:#f04444;
  clip-path:polygon(0 0,100% 0,50% 100%)
}

.menu{
  position:fixed;z-index:1000;display:none;
  min-width:220px;max-width:260px;
  background:#1c2531;border:1px solid #46515f;
  border-radius:7px;box-shadow:0 12px 30px #0009;padding:5px
}
.menu-title{
  font-size:11px;color:#8b949e;padding:6px 8px
}
.menu button{
  display:block;width:100%;text-align:left;border:0;
  background:transparent;color:#e8edf5;padding:8px;border-radius:4px
}
.menu button:hover{background:#2b3745}
.menu hr{border:0;border-top:1px solid #303b48;margin:4px 0}
.menu select{
  width:100%;background:#242c36;color:#fff;
  border:1px solid #46515f;padding:6px;border-radius:4px
}

.toast{
  position:fixed;right:15px;bottom:15px;
  background:#202938;border:1px solid #46515f;
  border-radius:6px;padding:9px 13px;display:none;z-index:2000
}

@media(max-width:800px){
  .title{display:none}
  .timeline{height:240px}
  .status{display:none}
}
</style>
</head>

<body>
<div class="app">

<header class="header">
  <div class="title">動画編集・注釈</div>

  <button class="btn primary" id="loadBtn">動画を選択</button>
  <input id="fileInput" type="file" accept="video/*" hidden>

  <button class="btn" id="playBtn">▶ 再生</button>
  <button class="btn" id="stopBtn">■ 停止</button>

  <div class="status" id="status">動画を選択してください</div>
</header>

<main class="main">

<section class="video-area">
  <div class="video-wrap" id="videoWrap">

    <video id="video" playsinline preload="metadata"></video>

    <svg id="svg" class="svg-layer">
      <defs>
        <marker id="arrowEnd" markerWidth="9" markerHeight="9"
                refX="8" refY="4.5" orient="auto">
          <path d="M0,0 L9,4.5 L0,9 Z" fill="#90a4ae"></path>
        </marker>
        <marker id="arrowEndSelected" markerWidth="9" markerHeight="9"
                refX="8" refY="4.5" orient="auto">
          <path d="M0,0 L9,4.5 L0,9 Z" fill="#4da3ff"></path>
        </marker>
        <marker id="arrowStart" markerWidth="9" markerHeight="9"
                refX="1" refY="4.5" orient="auto">
          <path d="M9,0 L0,4.5 L9,9 Z" fill="#90a4ae"></path>
        </marker>
        <marker id="arrowStartSelected" markerWidth="9" markerHeight="9"
                refX="1" refY="4.5" orient="auto">
          <path d="M9,0 L0,4.5 L9,9 Z" fill="#4da3ff"></path>
        </marker>
      </defs>
    </svg>

    <div id="overlay" class="overlay"></div>

    <div id="hint" class="hint">
      動画を選択してください<br>
      読み込み後、動画上で右クリックすると要素を追加できます
    </div>

  </div>
</section>

<section class="timeline">

  <div class="timeline-toolbar">
    <div class="time" id="time">00:00.000 / 00:00.000</div>

    <div class="scale">
      時間軸
      <input id="scale" type="range" min="0.5" max="4" step="0.1" value="1">
      <span id="scaleText">1.0×</span>
    </div>
  </div>

  <div class="timeline-scroll" id="timelineScroll">
    <div class="timeline-content" id="timelineContent">

      <div class="axis" id="axis"></div>

      <div class="rows">

        <div class="row">
          <div class="row-label">
            <span class="dot" style="background:#4da3ff"></span>
            コメント
          </div>
          <div class="lane" id="lane-comment"></div>
        </div>

        <div class="row">
          <div class="row-label">
            <span class="dot" style="background:#f04444"></span>
            強調枠
          </div>
          <div class="lane" id="lane-highlight"></div>
        </div>

        <div class="row">
          <div class="row-label">
            <span class="dot" style="background:#54d68a"></span>
            拡大枠
          </div>
          <div class="lane" id="lane-zoombox"></div>
        </div>

        <div class="row">
          <div class="row-label">
            <span class="dot" style="background:#ff9800"></span>
            スキップ
          </div>
          <div class="lane" id="lane-skip"></div>
        </div>

      </div>

      <div class="playhead" id="playhead"></div>
    </div>
  </div>

</section>
</main>
</div>

<div id="menu" class="menu"></div>
<div id="toast" class="toast"></div>

<script>
const $ = id => document.getElementById(id);

const video = $("video");
const wrap = $("videoWrap");
const overlay = $("overlay");
const svg = $("svg");
const hint = $("hint");
const menu = $("menu");
const toast = $("toast");
const axis = $("axis");
const timelineContent = $("timelineContent");
const timelineScroll = $("timelineScroll");
const playhead = $("playhead");

let duration = 60;
let current = 0;
let scale = 1;
let selected = [];
let connections = [];
let nextId = 5;
let drag = null;
let menuTarget = null;
let objectUrl = null;

/* モック用初期データ */
const elements = [
  {
    id:1,
    type:"comment",
    label:"コメント",
    start:5,
    end:18,
    x:18,
    y:15,
    w:190,
    h:55,
    text:"ここを確認してください"
  },
  {
    id:2,
    type:"highlight",
    label:"強調枠",
    start:8,
    end:24,
    x:52,
    y:34,
    w:230,
    h:130,
    shape:"rect"
  },
  {
    id:3,
    type:"zoombox",
    label:"拡大枠",
    start:20,
    end:35,
    x:20,
    y:58,
    w:180,
    h:100
  },
  {
    id:4,
    type:"skip",
    label:"スキップ",
    start:38,
    end:45,
    x:0,
    y:0,
    w:0,
    h:0
  }
];

const colors = {
  comment:"#4da3ff",
  highlight:"#f04444",
  zoombox:"#54d68a",
  skip:"#ff9800"
};

/* ------------------------------
   共通
------------------------------ */

function toastMsg(message){
  toast.textContent = message;
  toast.style.display = "block";
  clearTimeout(toastMsg.timer);
  toastMsg.timer = setTimeout(()=>{
    toast.style.display = "none";
  },1800);
}

function fmt(t){
  t = Math.max(0, Number(t) || 0);
  const m = Math.floor(t / 60);
  const s = t % 60;
  return String(m).padStart(2,"0") + ":" +
         s.toFixed(3).padStart(6,"0");
}

function activeElements(){
  return elements.filter(e =>
    current >= e.start && current <= e.end
  );
}

function videoPosition(e){
  return {
    left:e.x + "%",
    top:e.y + "%",
    width:e.w + "%",
    height:e.h + "%"
  };
}

/* ------------------------------
   動画
------------------------------ */

$("loadBtn").addEventListener("click",()=>{
  $("fileInput").click();
});

$("fileInput").addEventListener("change",e=>{
  const file = e.target.files[0];
  if(!file) return;

  if(objectUrl){
    URL.revokeObjectURL(objectUrl);
  }

  objectUrl = URL.createObjectURL(file);
  video.src = objectUrl;
  video.load();

  statusMessage("動画を読み込み中...");
});

video.addEventListener("loadedmetadata",()=>{
  duration = Number.isFinite(video.duration) ? video.duration : 60;
  current = 0;

  hint.style.display = "none";
  statusMessage("動画を読み込みました");

  renderAxis();
  renderTimeline();
  updatePlayhead();
  renderOverlay();
});

video.addEventListener("timeupdate",()=>{
  current = video.currentTime;
  renderOverlay();
  updatePlayhead();
});

video.addEventListener("ended",()=>{
  current = duration;
  $("playBtn").textContent = "▶ 再生";
  renderOverlay();
  updatePlayhead();
});

$("playBtn").addEventListener("click",()=>{
  if(!video.src){
    toastMsg("先に動画を選択してください");
    return;
  }

  if(video.paused){
    video.play();
    $("playBtn").textContent = "❚❚ 一時停止";
  }else{
    video.pause();
    $("playBtn").textContent = "▶ 再生";
  }
});

$("stopBtn").addEventListener("click",()=>{
  if(!video.src) return;

  video.pause();
  video.currentTime = 0;
  current = 0;
  $("playBtn").textContent = "▶ 再生";
  updatePlayhead();
  renderOverlay();
});

function statusMessage(message){
  $("status").textContent = message;
}

/* ------------------------------
   再生位置
------------------------------ */

function timelineWidth(){
  const base = Math.max(900, timelineScroll.clientWidth - 20);
  return base * scale;
}

function updateTimelineWidth(){
  const width = timelineWidth();
  timelineContent.style.width = (width + 110) + "px";
}

function renderAxis(){
  updateTimelineWidth();

  axis.innerHTML = "";

  const width = timelineWidth();
  const step = duration <= 30 ? 5 :
               duration <= 120 ? 10 :
               duration <= 600 ? 30 : 60;

  for(let t=0;t<=duration;t+=step){
    const tick = document.createElement("div");
    tick.className = "tick";
    tick.style.left = (t / duration * width) + "px";
    tick.textContent = fmt(t).slice(0,5);
    axis.appendChild(tick);
  }

  if(duration > 0){
    const endTick = document.createElement("div");
    endTick.className = "tick";
    endTick.style.left = width + "px";
    endTick.textContent = fmt(duration).slice(0,5);
    axis.appendChild(endTick);
  }
}

function setCurrentTime(t){
  current = Math.max(0,Math.min(duration,Number(t) || 0));

  if(video.src){
    try{
      video.currentTime = current;
    }catch(e){}
  }

  updatePlayhead();
  renderOverlay();
}

function updatePlayhead(){
  const width = timelineWidth();

  const x = 110 + (
    duration > 0 ? current / duration * width : 0
  );

  playhead.style.left = x + "px";
  $("time").textContent =
    fmt(current) + " / " + fmt(duration);
}

/* タイムラインの空白部分をクリック */
timelineContent.addEventListener("click",e=>{
  if(e.target.closest(".bar")) return;

  const rect = timelineContent.getBoundingClientRect();
  const x = e.clientX - rect.left - 110;

  if(x >= 0 && x <= timelineWidth()){
    setCurrentTime(x / timelineWidth() * duration);
  }
});

/* ------------------------------
   オーバーレイ
------------------------------ */

function renderOverlay(){
  overlay.innerHTML = "";

  activeElements().forEach(e=>{
    if(e.type === "skip") return;

    const d = document.createElement("div");

    d.className =
      "element " + e.type +
      (selected.includes(e.id) ? " selected" : "") +
      (
        selected.length > 1 && selected.includes(e.id)
        ? " multi"
        : ""
      );

    d.dataset.id = e.id;

    Object.assign(d.style,videoPosition(e));

    if(current < e.start || current > e.end){
      d.classList.add("active-dim");
    }

    if(e.type === "highlight"){
      d.classList.add(e.shape || "rect");
    }

    const body = document.createElement("div");
    body.className = "element-body";

    if(e.type === "comment"){
      body.textContent = e.text;
    }

    if(e.type === "zoombox"){
      body.textContent = "拡大表示範囲";
      body.style.color = "#9ff0bd";
      body.style.fontSize = "12px";
    }

    d.appendChild(body);

    ["nw","ne","sw","se"].forEach(pos=>{
      const h = document.createElement("div");
      h.className = "handle " + pos;
      h.dataset.resize = pos;
      d.appendChild(h);
    });

    d.addEventListener("pointerdown",startElement);

    d.addEventListener("click",e2=>{
      e2.stopPropagation();
      selectElement(Number(d.dataset.id),e2);
    });

    d.addEventListener("contextmenu",e2=>{
      e2.preventDefault();
      e2.stopPropagation();

      const id = Number(d.dataset.id);

      if(!selected.includes(id)){
        selectElement(id,e2);
      }

      showElementMenu(e2.clientX,e2.clientY,id);
    });

    overlay.appendChild(d);
  });

  renderConnections();
}

/* 動画上の空白をクリック */
wrap.addEventListener("click",e=>{
  if(e.target.closest(".element")) return;
  if(e.target.closest(".connection-hit")) return;

  clearSelection();
});

function selectElement(id,event){
  const multi =
    event &&
    (event.shiftKey || event.ctrlKey || event.metaKey);

  if(multi){
    selected = selected.includes(id)
      ? selected.filter(x=>x !== id)
      : [...selected,id];
  }else{
    selected = [id];
  }

  clearConnectionSelection();
  renderOverlay();
  renderTimeline();
}

function clearSelection(){
  selected = [];
  clearConnectionSelection();
  menu.style.display = "none";
  renderOverlay();
  renderTimeline();
}

function clearConnectionSelection(){
  connections.forEach(c=>c.selected=false);
}

/* ------------------------------
   要素移動・サイズ変更
------------------------------ */

function startElement(ev){
  if(ev.button !== 0) return;

  const el = elements.find(
    x => x.id === Number(ev.currentTarget.dataset.id)
  );

  if(!el) return;

  ev.stopPropagation();

  const resize = ev.target.dataset.resize || null;

  if(!selected.includes(el.id)){
    selectElement(el.id,ev);
  }

  const basePositions = {};

  selected.forEach(id=>{
    const e = elements.find(x=>x.id===id);
    if(e){
      basePositions[id] = {
        x:e.x,y:e.y,w:e.w,h:e.h
      };
    }
  });

  drag = {
    kind:resize ? "resize" : "move",
    el,
    resize,
    x:ev.clientX,
    y:ev.clientY,
    ox:el.x,
    oy:el.y,
    ow:el.w,
    oh:el.h,
    basePositions
  };

  window.addEventListener("pointermove",moveElement);
  window.addEventListener("pointerup",endElementDrag,{once:true});
}

function moveElement(ev){
  if(!drag) return;

  const r = wrap.getBoundingClientRect();

  const dx = (ev.clientX - drag.x) / r.width * 100;
  const dy = (ev.clientY - drag.y) / r.height * 100;

  if(drag.kind === "move"){

    selected.forEach(id=>{
      const e = elements.find(x=>x.id===id);
      const base = drag.basePositions[id];

      if(!e || !base || e.type==="skip") return;

      e.x = Math.max(
        0,
        Math.min(100 - e.w,base.x + dx)
      );

      e.y = Math.max(
        0,
        Math.min(100 - e.h,base.y + dy)
      );
    });

  }else{

    const e = drag.el;

    let x = drag.ox;
    let y = drag.oy;
    let w = drag.ow;
    let h = drag.oh;

    if(drag.resize.includes("e")){
      w = Math.max(5,drag.ow + dx);
    }

    if(drag.resize.includes("s")){
      h = Math.max(5,drag.oh + dy);
    }

    if(drag.resize.includes("w")){
      x = drag.ox + dx;
      w = drag.ow - dx;
    }

    if(drag.resize.includes("n")){
      y = drag.oy + dy;
      h = drag.oh - dy;
    }

    e.x = Math.max(0,Math.min(95,x));
    e.y = Math.max(0,Math.min(95,y));
    e.w = Math.max(5,Math.min(100-e.x,w));
    e.h = Math.max(5,Math.min(100-e.y,h));
  }

  renderOverlay();
}

function endElementDrag(){
  drag = null;
  window.removeEventListener("pointermove",moveElement);
  renderTimeline();
}

/* ------------------------------
   タイムライン
------------------------------ */

function renderTimeline(){
  ["comment","highlight","zoombox","skip"].forEach(type=>{
    const lane = $("lane-" + type);
    lane.innerHTML = "";

    elements
      .filter(e=>e.type===type)
      .forEach(e=>{
        const bar = document.createElement("div");

        bar.className =
          "bar " +
          (selected.includes(e.id) ? "selected " : "") +
          (
            selected.length > 1 && selected.includes(e.id)
            ? "multi"
            : ""
          );

        const width = timelineWidth();
        const start = Number(e.start);
        const end = Number(e.end);

        bar.style.left =
          (start / duration * width) + "px";

        bar.style.width =
          Math.max(
            20,
            (end-start) / duration * width
          ) + "px";

        bar.style.background =
          colors[type] + "55";

        bar.style.color = colors[type];

        const label = document.createElement("span");
        label.textContent = e.label;
        bar.appendChild(label);

        const left = document.createElement("div");
        left.className = "bar-handle left";

        const right = document.createElement("div");
        right.className = "bar-handle right";

        bar.appendChild(left);
        bar.appendChild(right);

        bar.addEventListener("click",ev=>{
          ev.stopPropagation();
          selectElement(e.id,ev);
        });

        bar.addEventListener("pointerdown",ev=>{
          if(ev.button !== 0) return;

          ev.stopPropagation();

          if(ev.target.classList.contains("bar-handle")){
            startTimeResize(ev,e);
            return;
          }

          selectElement(e,ev);

          startBarMove(ev,e);
        });

        lane.appendChild(bar);
      });
  });

  updateTimelineWidth();
  updatePlayhead();
}

function startBarMove(ev,e){
  const width = timelineWidth();

  const startX = ev.clientX;
  const originalStart = e.start;
  const length = e.end - e.start;

  function move(event){
    const delta =
      (event.clientX - startX) / width * duration;

    const ns = Math.max(
      0,
      Math.min(duration-length,originalStart+delta)
    );

    e.start = Number(ns.toFixed(2));
    e.end = Number((ns+length).toFixed(2));

    renderTimeline();
    renderOverlay();
  }

  function up(){
    document.removeEventListener("pointermove",move);
    document.removeEventListener("pointerup",up);
  }

  document.addEventListener("pointermove",move);
  document.addEventListener("pointerup",up);
}

function startTimeResize(ev,e){
  const width = timelineWidth();
  const startX = ev.clientX;

  const originalStart = e.start;
  const originalEnd = e.end;

  const isLeft =
    ev.target.classList.contains("left");

  function move(event){

    const delta =
      (event.clientX - startX) / width * duration;

    if(isLeft){
      e.start = Number(
        Math.max(
          0,
          Math.min(
            originalEnd - .1,
            originalStart + delta
          )
        ).toFixed(2)
      );
    }else{
      e.end = Number(
        Math.min(
          duration,
          Math.max(
            originalStart + .1,
            originalEnd + delta
          )
        ).toFixed(2)
      );
    }

    renderTimeline();
    renderOverlay();
  }

  function up(){
    document.removeEventListener("pointermove",move);
    document.removeEventListener("pointerup",up);
  }

  document.addEventListener("pointermove",move);
  document.addEventListener("pointerup",up);
}

/* ------------------------------
   接続線
------------------------------ */

function connectionPoint(e,pos){
  const x=e.x;
  const y=e.y;
  const w=e.w;
  const h=e.h;

  const map={
    tl:[x,y],
    t:[x+w/2,y],
    tr:[x+w,y],
    l:[x,y+h/2],
    r:[x+w,y+h/2],
    bl:[x,y+h],
    b:[x+w/2,y+h],
    br:[x+w,y+h]
  };

  return map[pos] || map.r;
}

function renderConnections(){
  svg.querySelectorAll(".connection-hit,.connection")
     .forEach(x=>x.remove());

  connections.forEach(c=>{

    const a = elements.find(e=>e.id===c.from);
    const b = elements.find(e=>e.id===c.to);

    if(!a || !b) return;

    const p1 = connectionPoint(a,c.fromPos);
    const p2 = connectionPoint(b,c.toPos);

    let d = "";

    if(c.style==="orthogonal"){

      const mx = (p1[0]+p2[0])/2;

      d =
        `M ${p1[0]} ${p1[1]}
         L ${mx} ${p1[1]}
         L ${mx} ${p2[1]}
         L ${p2[0]} ${p2[1]}`;

    }else if(c.style==="wave"){

      const dx = p2[0]-p1[0];
      const dy = p2[1]-p1[1];

      const len = Math.sqrt(dx*dx+dy*dy) || 1;

      const nx = -dy/len * 2;
      const ny = dx/len * 2;

      d = `M ${p1[0]} ${p1[1]}`;

      for(let i=1;i<=16;i++){
        const t=i/16;
        const wave=Math.sin(t*Math.PI*8);

        d +=
          ` L ${p1[0]+dx*t+nx*wave}
             ${p1[1]+dy*t+ny*wave}`;
      }

    }else{

      d =
        `M ${p1[0]} ${p1[1]}
         L ${p2[0]} ${p2[1]}`;
    }

    const hit =
      document.createElementNS(
        "http://www.w3.org/2000/svg",
        "path"
      );

    hit.setAttribute("class","connection-hit");
    hit.setAttribute("d",d);
    hit.dataset.cid=c.id;

    hit.addEventListener("click",ev=>{
      ev.stopPropagation();
      selectConnection(c.id);
    });

    hit.addEventListener("contextmenu",ev=>{
      ev.preventDefault();
      ev.stopPropagation();
      selectConnection(c.id);
      showConnectionMenu(ev.clientX,ev.clientY,c.id);
    });

    svg.appendChild(hit);

    const line =
      document.createElementNS(
        "http://www.w3.org/2000/svg",
        "path"
      );

    line.setAttribute(
      "class",
      "connection" + (c.selected ? " selected" : "")
    );

    line.setAttribute("d",d);

    if(c.style==="dashed"){
      line.setAttribute("stroke-dasharray","8 6");
    }

    if(c.style==="dotted"){
      line.setAttribute("stroke-dasharray","2 5");
    }

    if(c.fromArrow){
      line.setAttribute(
        "marker-start",
        c.selected
          ? "url(#arrowStartSelected)"
          : "url(#arrowStart)"
      );
    }

    if(c.toArrow){
      line.setAttribute(
        "marker-end",
        c.selected
          ? "url(#arrowEndSelected)"
          : "url(#arrowEnd)"
      );
    }

    svg.appendChild(line);

    if(c.selected){
      addConnectionHandle(c,p1,"from");
      addConnectionHandle(c,p2,"to");
    }
  });
}

function addConnectionHandle(c,p,end){
  const h =
    document.createElementNS(
      "http://www.w3.org/2000/svg",
      "circle"
    );

  h.setAttribute("cx",p[0]);
  h.setAttribute("cy",p[1]);
  h.setAttribute("r","2.4");
  h.setAttribute("fill","#fff");
  h.setAttribute("stroke","#4da3ff");
  h.setAttribute("stroke-width","1.5");
  h.style.cursor="crosshair";
  h.style.pointerEvents="auto";

  h.addEventListener("pointerdown",ev=>{
    ev.stopPropagation();
    startConnectionHandle(ev,c,end);
  });

  svg.appendChild(h);
}

function selectConnection(id){
  connections.forEach(c=>{
    c.selected = c.id===id;
  });

  selected = [];

  renderOverlay();
  renderTimeline();
}

function startConnectionHandle(ev,c,end){
  drag = {
    kind:"connection",
    c,
    end
  };

  window.addEventListener("pointermove",moveConnection);
  window.addEventListener("pointerup",endConnection,{once:true});
}

function moveConnection(ev){
  if(!drag || drag.kind!=="connection") return;

  const r = wrap.getBoundingClientRect();

  const x =
    (ev.clientX-r.left)/r.width*100;

  const y =
    (ev.clientY-r.top)/r.height*100;

  const target = elements
    .filter(e=>e.type!=="skip")
    .find(e=>
      x>=e.x &&
      x<=e.x+e.w &&
      y>=e.y &&
      y<=e.y+e.h
    );

  if(!target) return;

  const relx=(x-target.x)/target.w;
  const rely=(y-target.y)/target.h;

  let pos;

  if(rely<.25){
    pos=relx<.33?"tl":relx>.66?"tr":"t";
  }else if(rely>.75){
    pos=relx<.33?"bl":relx>.66?"br":"b";
  }else{
    pos=relx<.5?"l":"r";
  }

  if(drag.end==="from"){
    drag.c.from=target.id;
    drag.c.fromPos=pos;
  }else{
    drag.c.to=target.id;
    drag.c.toPos=pos;
  }

  renderConnections();
}

function endConnection(){
  drag = null;
  window.removeEventListener("pointermove",moveConnection);
}

/* ------------------------------
   要素追加
------------------------------ */

function addElement(type,x,y){

  const r = wrap.getBoundingClientRect();

  const px =
    ((x-r.left)/r.width*100);

  const py =
    ((y-r.top)/r.height*100);

  const data = {
    id:nextId++,
    type,
    label:
      type==="comment" ? "コメント" :
      type==="highlight" ? "強調枠" :
      type==="zoombox" ? "拡大枠" : "スキップ",

    start:Math.max(0,Math.min(duration-5,current)),
    end:Math.min(duration,current+5),

    x:Math.max(0,Math.min(75,px)),
    y:Math.max(0,Math.min(75,py)),
    w:type==="comment"?190:180,
    h:type==="comment"?55:100
  };

  if(type==="highlight"){
    data.shape="rect";
    data.w=230;
    data.h=130;
  }

  if(type==="skip"){
    data.x=0;
    data.y=0;
    data.w=0;
    data.h=0;
  }

  if(type==="comment"){
    data.text="新しいコメント";
  }

  elements.push(data);

  selected=[data.id];

  renderOverlay();
  renderTimeline();

  toastMsg(data.label+"を追加しました");
}

/* ------------------------------
   メニュー
------------------------------ */

function openMenu(x,y,html){
  menu.innerHTML=html;
  menu.style.display="block";

  menu.style.left =
    Math.min(x,innerWidth-240) + "px";

  menu.style.top =
    Math.min(y,innerHeight-280) + "px";
}

function showElementMenu(x,y,id){

  const e = elements.find(v=>v.id===id);
  if(!e) return;

  menuTarget = {
    type:"element",
    id
  };

  let html =
    `<div class="menu-title">${e.label} の操作</div>`;

  if(e.type==="comment"){
    html += `
      <button data-action="editComment">
        コメントを変更
      </button>
    `;
  }

  if(e.type==="highlight"){
    html += `
      <button data-action="shapeRect">四角形</button>
      <button data-action="shapeRound">角丸</button>
      <button data-action="shapeCircle">円形</button>
    `;
  }

  if(e.type==="zoombox"){
    html += `
      <button data-action="connectFrom">
        接続線を作成
      </button>
    `;
  }

  if(selected.length>=2){
    html += `
      <hr>
      <button data-action="connect">
        選択した要素を接続
      </button>
    `;
  }

  html += `
    <hr>
    <button data-action="delete">削除</button>
  `;

  openMenu(x,y,html);
}

function showConnectionMenu(x,y,id){

  const c = connections.find(v=>v.id===id);
  if(!c) return;

  menuTarget = {
    type:"connection",
    id
  };

  const html = `
    <div class="menu-title">接続線の操作</div>

    <select id="lineStyle">
      <option value="solid"
        ${c.style==="solid"?"selected":""}>
        直線
      </option>
      <option value="orthogonal"
        ${c.style==="orthogonal"?"selected":""}>
        折れ線
      </option>
      <option value="wave"
        ${c.style==="wave"?"selected":""}>
        波線
      </option>
      <option value="dashed"
        ${c.style==="dashed"?"selected":""}>
        破線
      </option>
      <option value="dotted"
        ${c.style==="dotted"?"selected":""}>
        点線
      </option>
    </select>

    <hr>

    <button data-action="toggleFromArrow">
      始点の終端：${c.fromArrow?"矢印":"なし"}
    </button>

    <button data-action="toggleToArrow">
      終点の終端：${c.toArrow?"矢印":"なし"}
    </button>

    <button data-action="deleteConnection">
      接続線を削除
    </button>
  `;

  openMenu(x,y,html);

  setTimeout(()=>{
    const s=$("lineStyle");

    if(s){
      s.onchange=()=>{
        c.style=s.value;
        renderConnections();
      };
    }
  },0);
}

menu.addEventListener("click",ev=>{

  const action = ev.target.dataset.action;

  if(!action || !menuTarget) return;

  if(menuTarget.type==="element"){

    const e =
      elements.find(v=>v.id===menuTarget.id);

    if(!e) return;

    if(action==="shapeRect"){
      e.shape="rect";
    }

    if(action==="shapeRound"){
      e.shape="round";
    }

    if(action==="shapeCircle"){
      e.shape="circle";
    }

    if(action==="editComment"){
      const value =
        prompt("コメント",e.text);

      if(value!==null){
        e.text=value;
      }
    }

    if(action==="connect"){
      if(selected.length>=2){

        const from=selected[0];
        const to=selected[1];

        if(from!==to){

          connections.push({
            id:nextId++,
            from,
            to,
            fromPos:"r",
            toPos:"l",
            style:"solid",
            fromArrow:false,
            toArrow:false,
            selected:false
          });

          selected=[];
          toastMsg("接続線を作成しました");
        }
      }
    }

    if(action==="connectFrom"){
      startConnectMode(e.id);
    }

    if(action==="delete"){
      deleteElement(e.id);
    }

    renderOverlay();
    renderTimeline();
  }

  if(menuTarget.type==="connection"){

    const c =
      connections.find(v=>v.id===menuTarget.id);

    if(!c) return;

    if(action==="toggleFromArrow"){
      c.fromArrow=!c.fromArrow;
    }

    if(action==="toggleToArrow"){
      c.toArrow=!c.toArrow;
    }

    if(action==="deleteConnection"){
      connections =
        connections.filter(v=>v.id!==c.id);

      selected=[];
    }

    renderConnections();
  }

  menu.style.display="none";
});

document.addEventListener("pointerdown",ev=>{
  if(!menu.contains(ev.target)){
    menu.style.display="none";
  }
});

/* ------------------------------
   接続線作成モード
------------------------------ */

let connectMode = false;
let connectFrom = null;

function startConnectMode(id){
  connectMode=true;
  connectFrom=id;

  toastMsg(
    "接続先の要素をクリックしてください"
  );
}

function handleConnectClick(id){
  if(!connectMode) return;

  if(connectFrom===id){
    connectMode=false;
    connectFrom=null;
    return;
  }

  connections.push({
    id:nextId++,
    from:connectFrom,
    to:id,
    fromPos:"r",
    toPos:"l",
    style:"solid",
    fromArrow:false,
    toArrow:false,
    selected:false
  });

  connectMode=false;
  connectFrom=null;

  renderOverlay();
  renderTimeline();

  toastMsg("接続線を作成しました");
}

/* ------------------------------
   要素削除
------------------------------ */

function deleteElement(id){

  const index =
    elements.findIndex(e=>e.id===id);

  if(index<0) return;

  elements.splice(index,1);

  connections =
    connections.filter(c=>
      c.from!==id && c.to!==id
    );

  selected =
    selected.filter(x=>x!==id);

  toastMsg("要素を削除しました");
}

/* ------------------------------
   要素クリック時の接続処理
------------------------------ */

overlay.addEventListener("click",e=>{
  const el=e.target.closest(".element");
  if(!el) return;

  if(connectMode){
    e.stopPropagation();

    handleConnectClick(
      Number(el.dataset.id)
    );
  }
});

/* ------------------------------
   動画上の右クリック
------------------------------ */

wrap.addEventListener("contextmenu",e=>{
  if(e.target.closest(".element")) return;
  if(e.target.closest(".connection-hit")) return;

  e.preventDefault();

  menuTarget=null;

  const html = `
    <div class="menu-title">要素を追加</div>
    <button data-add="comment">＋ コメント</button>
    <button data-add="highlight">＋ 強調枠</button>
    <button data-add="zoombox">＋ 拡大枠</button>
    <button data-add="skip">＋ スキップ</button>
  `;

  openMenu(e.clientX,e.clientY,html);
});

menu.addEventListener("click",e=>{
  const type=e.target.dataset.add;
  if(!type) return;

  const x=parseFloat(menu.style.left);
  const y=parseFloat(menu.style.top);

  menu.style.display="none";

  addElement(type,x,y);
});

/* ------------------------------
   時間軸拡大縮小
------------------------------ */

$("scale").addEventListener("input",e=>{
  scale=Number(e.target.value);

  $("scaleText").textContent =
    scale.toFixed(1) + "×";

  renderAxis();
  renderTimeline();
});

/* ------------------------------
   キーボード
------------------------------ */

document.addEventListener("keydown",e=>{

  if(e.key==="Escape"){
    connectMode=false;
    connectFrom=null;
    menu.style.display="none";
    toastMsg("操作をキャンセルしました");
  }

  if(
    (e.key==="Delete" || e.key==="Backspace") &&
    selected.length
  ){
    [...selected].forEach(id=>{
      deleteElement(id);
    });

    renderOverlay();
    renderTimeline();
  }
});

/* ------------------------------
   リサイズ
------------------------------ */

window.addEventListener("resize",()=>{
  renderAxis();
  renderTimeline();
  renderOverlay();
});

/* ------------------------------
   初期状態
------------------------------ */

function initMock(){

  /* モックでは動画未選択でも
     編集操作を確認できるよう60秒で表示 */

  duration=60;
  current=0;

  renderAxis();
  renderTimeline();
  renderOverlay();
  updatePlayhead();
}

initMock();
</script>

</body>
</html>

<?php
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;font-family:Arial,"Noto Sans JP",sans-serif;color:#222;background:#f5f6f8}
button,input{font:inherit}
button{border:1px solid #bbb;background:#fff;border-radius:5px;padding:8px 14px;cursor:pointer}
button:hover{background:#f0f2f5}
button.primary{background:#2563eb;color:#fff;border-color:#2563eb}
button.danger{color:#c62828}
.hidden{display:none!important}

#app{width:100%;min-height:100vh}
#main{width:100%;padding:18px}
.header{height:48px;background:#202938;color:#fff;display:flex;align-items:center;padding:0 16px;margin:-18px -18px 18px}
.header h1{font-size:18px;margin:0}
.header .spacer{flex:1}

.mainAction{display:flex;justify-content:center;margin:35px 0 25px}
.mainAction button{font-size:16px;padding:12px 28px}

.selection{width:100%;border:1px solid #ccc;background:#fff;border-radius:7px;padding:18px;margin-bottom:18px}
.selection h2{font-size:17px;margin:0 0 15px}
.videoChoices{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}
.videoChoice{border:1px solid #ccc;border-radius:6px;padding:13px;background:#fff}
.videoChoice strong{display:block;margin-bottom:5px}
.videoChoice small{color:#666}
.videoChoice .actions{margin-top:10px;display:flex;gap:7px}

.editor{width:100%;padding:12px}
.editorHeader{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.editorHeader h2{font-size:17px;margin:0}
.editorHeader .spacer{flex:1}
.status{font-size:12px;color:#777}

.videoArea{width:100%;height:min(58vh,620px);min-height:360px;background:#111;position:relative;overflow:hidden;border-radius:5px}
#videoPlayer{width:100%;height:100%;display:block;object-fit:contain}
#objectsLayer{position:absolute;inset:0}
#connections{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none;z-index:20}
.object{position:absolute;z-index:30;cursor:move;user-select:none}
.object.selected{outline:2px solid #f59e0b}
.object.connectSource{outline:2px solid #f59e0b}
.object.connectTarget{outline:2px dashed #f59e0b}
.object.comment{padding:7px;background:rgba(255,255,255,.9);border:1px solid #555;border-radius:3px}
.object.highlight{border:2px solid #f59e0b;background:rgba(245,158,11,.12)}
.object.zoom{border:2px solid #2563eb;background:rgba(37,99,235,.12)}
.object.skip{background:rgba(80,80,80,.35);border:2px dashed #666}
.deleteHandle{position:absolute;right:-8px;top:-8px;width:18px;height:18px;border-radius:50%;background:#c62828;color:#fff;font-size:12px;line-height:18px;text-align:center;cursor:pointer;z-index:50}
.objectLabel{position:absolute;left:4px;top:3px;font-size:11px;color:#555;background:#fff8;padding:1px 3px}

.connection{fill:none;stroke:#f59e0b;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.connectionHit{fill:none;stroke:transparent;stroke-width:12;pointer-events:stroke}

.controlBar{display:flex;align-items:center;gap:7px;padding:10px 0}
.controlBar .time{min-width:105px;color:#555}
.controlBar .spacer{flex:1}

.timeline{border:1px solid #bbb;background:#fff;border-radius:5px;overflow:hidden}
.timelineTop{height:42px;display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid #ccc}
.timelineTop .spacer{flex:1}
.scaleLabel{font-size:13px}
.scale{width:150px}
.timelineViewport{height:205px;overflow-x:auto;overflow-y:hidden}
.timelineInner{position:relative;height:100%;min-width:900px}
.ruler{height:38px;position:relative;border-bottom:1px solid #bbb;background:#fafafa}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #aaa;padding:4px 0 0 4px;font-size:10px;color:#555}
.trackArea{position:relative;height:167px}
.clip{position:absolute;height:30px;top:18px;background:#2563eb;color:#fff;border-radius:4px;padding:7px 8px;font-size:11px;white-space:nowrap;overflow:hidden}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#e11d48;z-index:100;pointer-events:none}
.playhead:before{content:"";position:absolute;left:-5px;top:-1px;width:12px;height:12px;background:#e11d48;border-radius:50%}

.connectionHelp{margin-top:8px;color:#666;font-size:12px}
.message{position:fixed;right:18px;bottom:18px;background:#202938;color:#fff;padding:10px 14px;border-radius:5px;z-index:1000}

.modalBg{position:fixed;inset:0;background:rgba(0,0,0,.42);display:flex;align-items:center;justify-content:center;z-index:500}
.modal{width:min(560px,calc(100% - 30px));background:#fff;border-radius:7px;padding:18px;box-shadow:0 12px 40px #0004}
.modal h3{margin:0 0 15px}
.modalGrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.modalCard{border:1px solid #ccc;border-radius:6px;padding:14px}
.modalCard strong{display:block;margin-bottom:6px}
.modalCard small{color:#666}
.modalActions{display:flex;justify-content:flex-end;gap:7px;margin-top:16px}

@media(max-width:700px){
 .videoArea{height:45vh;min-height:280px}
 .controlBar{flex-wrap:wrap}
 .timelineTop{flex-wrap:wrap;height:auto}
 .modalGrid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div id="main">
  <div class="header">
    <h1>動画編集</h1>
    <div class="spacer"></div>
  </div>

  <div class="mainAction">
    <button id="openVideoSelect" class="primary">動画を選択</button>
  </div>

  <div id="selection" class="selection hidden">
    <h2>編集する動画を選択</h2>
    <div id="videoChoices" class="videoChoices"></div>
  </div>
</div>

<div id="editor" class="editor hidden">
  <div class="editorHeader">
    <button id="backButton">動画選択へ戻る</button>
    <h2 id="editorTitle"></h2>
    <span class="spacer"></span>
    <span id="connectionMode" class="status"></span>
  </div>

  <div id="videoArea" class="videoArea">
    <video id="videoPlayer" controls></video>
    <div id="objectsLayer"></div>
    <svg id="connections"></svg>
  </div>

  <div class="controlBar">
    <button id="play" class="primary">▶ 再生</button>
    <button id="pause">⏸ 一時停止</button>
    <button id="stop">■ 停止</button>
    <span id="time" class="time">00:00 / 00:00</span>
    <span class="spacer"></span>
    <button id="addComment">コメント</button>
    <button id="addHighlight">強調枠</button>
    <button id="addZoom">拡大枠</button>
    <button id="addSkip">スキップ</button>
    <button id="connectButton">接続</button>
  </div>

  <div class="timeline">
    <div class="timelineTop">
      <span class="scaleLabel">タイムスケール</span>
      <input id="scale" class="scale" type="range" min="0" max="4" step="1" value="2">
      <span id="scaleText">5秒</span>
      <span class="spacer"></span>
      <span class="connectionHelp">接続：接続ボタン → 接続元 → 接続先</span>
    </div>
    <div id="timelineViewport" class="timelineViewport">
      <div id="timelineInner" class="timelineInner">
        <div id="ruler" class="ruler"></div>
        <div id="trackArea" class="trackArea"></div>
        <div id="playhead" class="playhead"></div>
      </div>
    </div>
  </div>
</div>

<div id="modalBg" class="modalBg hidden">
  <div class="modal">
    <h3>動画を選択</h3>
    <div class="modalGrid">
      <div class="modalCard">
        <strong>オリジナル動画</strong>
        <small>元の録画動画を編集します。</small>
        <div class="modalActions">
          <button id="originalButton" class="primary">選択</button>
        </div>
      </div>
      <div class="modalCard">
        <strong>作業動画</strong>
        <small>保存済みの編集作業を再開します。</small>
        <div class="modalActions">
          <button id="workButton">選択</button>
        </div>
      </div>
    </div>
    <div class="modalActions">
      <button id="closeModal">キャンセル</button>
    </div>
  </div>
</div>

<input id="fileInput" type="file" accept="video/*" class="hidden">
<div id="message" class="message hidden"></div>

<script>
"use strict";

const $=id=>document.getElementById(id);

const SAMPLE_VIDEO="https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4";

const state={
  videoType:null,
  videoId:null,
  videoName:"",
  duration:30,
  position:0,
  scale:2,
  objects:[],
  connections:[],
  selected:null,
  connecting:false,
  source:null,
  dragging:null,
  nextObject:1
};

const scales=[
  {step:1,width:70,label:"1秒"},
  {step:2,width:70,label:"2秒"},
  {step:5,width:70,label:"5秒"},
  {step:10,width:70,label:"10秒"},
  {step:30,width:70,label:"30秒"}
];

function showMessage(text){
  const m=$("message");
  m.textContent=text;
  m.classList.remove("hidden");
  clearTimeout(showMessage.timer);
  showMessage.timer=setTimeout(()=>m.classList.add("hidden"),1800);
}

function formatTime(sec){
  sec=Math.max(0,Number(sec)||0);
  const h=Math.floor(sec/3600);
  const m=Math.floor(sec%3600/60);
  const s=Math.floor(sec%60);
  return h
    ? `${String(h).padStart(2,"0")}:${String(m).padStart(2,"0")}:${String(s).padStart(2,"0")}`
    : `${String(m).padStart(2,"0")}:${String(s).padStart(2,"0")}`;
}

function openSelection(){
  renderVideoChoices();
  $("selection").classList.remove("hidden");
}

function closeSelection(){
  $("selection").classList.add("hidden");
}

function renderVideoChoices(){
  const videos=getOriginalVideos();
  const works=getWorks();
  const area=$("videoChoices");

  area.innerHTML="";

  if(!videos.length && !works.length){
    const empty=document.createElement("div");
    empty.className="videoChoice";
    empty.innerHTML="<strong>動画がありません</strong><small>オリジナル動画を追加してください。</small>";
    const b=document.createElement("button");
    b.textContent="オリジナル動画を追加";
    b.className="primary";
    b.onclick=()=>$("fileInput").click();
    empty.appendChild(document.createElement("br"));
    empty.appendChild(b);
    area.appendChild(empty);
    return;
  }

  videos.forEach(v=>{
    const card=document.createElement("div");
    card.className="videoChoice";
    card.innerHTML=`<strong>${escapeHtml(v.name)}</strong><small>オリジナル動画</small>`;
    const actions=document.createElement("div");
    actions.className="actions";
    const select=document.createElement("button");
    select.textContent="この動画を編集";
    select.className="primary";
    select.onclick=()=>openEditor("original",v);
    const del=document.createElement("button");
    del.textContent="削除";
    del.className="danger";
    del.onclick=()=>deleteOriginal(v.id);
    actions.append(select,del);
    card.appendChild(actions);
    area.appendChild(card);
  });

  works.forEach(w=>{
    const card=document.createElement("div");
    card.className="videoChoice";
    card.innerHTML=`<strong>${escapeHtml(w.name)}</strong><small>作業動画</small>`;
    const actions=document.createElement("div");
    actions.className="actions";
    const select=document.createElement("button");
    select.textContent="この作業を再開";
    select.className="primary";
    select.onclick=()=>openEditor("work",w);
    const del=document.createElement("button");
    del.textContent="削除";
    del.className="danger";
    del.onclick=()=>deleteWork(w.id);
    actions.append(select,del);
    card.appendChild(actions);
    area.appendChild(card);
  });

  const add=document.createElement("div");
  add.className="videoChoice";
  add.innerHTML="<strong>新しいオリジナル動画</strong><small>録画済み動画を追加します。</small>";
  const b=document.createElement("button");
  b.textContent="動画ファイルを追加";
  b.onclick=()=>$("fileInput").click();
  add.appendChild(document.createElement("br"));
  add.appendChild(b);
  area.appendChild(add);
}

function getOriginalVideos(){
  try{return JSON.parse(localStorage.getItem("mock_videos")||"[]")}catch(e){return[]}
}

function getWorks(){
  try{return JSON.parse(localStorage.getItem("mock_works")||"[]")}catch(e){return[]}
}

function saveWorks(list){
  localStorage.setItem("mock_works",JSON.stringify(list));
}

function saveVideos(list){
  localStorage.setItem("mock_videos",JSON.stringify(list));
}

function deleteOriginal(id){
  const list=getOriginalVideos().filter(v=>v.id!==id);
  saveVideos(list);
  renderVideoChoices();
}

function deleteWork(id){
  const list=getWorks().filter(v=>v.id!==id);
  saveWorks(list);
  renderVideoChoices();
}

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g,c=>({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"
  }[c]));
}

$("openVideoSelect").onclick=()=>{
  $("modalBg").classList.remove("hidden");
};

$("closeModal").onclick=()=>{
  $("modalBg").classList.add("hidden");
};

$("originalButton").onclick=()=>{
  $("modalBg").classList.add("hidden");
  $("fileInput").click();
};

$("workButton").onclick=()=>{
  $("modalBg").classList.add("hidden");
  openSelection();
};

$("fileInput").onchange=e=>{
  const file=e.target.files[0];
  if(!file)return;

  const url=URL.createObjectURL(file);
  const video={
    id:"v_"+Date.now(),
    name:file.name,
    url:url,
    duration:0
  };

  const probe=document.createElement("video");
  probe.preload="metadata";
  probe.src=url;
  probe.onloadedmetadata=()=>{
    video.duration=probe.duration||30;
    const list=getOriginalVideos();
    list.push(video);
    saveVideos(list);
    openEditor("original",video);
  };
};

function openEditor(type,data){
  state.videoType=type;
  state.videoId=data.id;
  state.videoName=data.name;
  state.duration=Number(data.duration||data.videoDuration||30);
  state.position=0;
  state.scale=2;
  state.selected=null;
  state.connecting=false;
  state.source=null;
  state.dragging=null;
  state.objects=[];
  state.connections=[];

  if(type==="work"){
    state.duration=Number(data.videoDuration||30);
    state.objects=(data.objects||[]).map(o=>({...o}));
    state.connections=(data.connections||[]).map(c=>({...c}));
  }

  $("main").classList.add("hidden");
  $("editor").classList.remove("hidden");
  $("editorTitle").textContent=data.name+(type==="work"?"（作業動画）":"（オリジナル動画）");

  const video=$("videoPlayer");
  video.src=data.url||SAMPLE_VIDEO;
  video.currentTime=0;
  video.load();

  video.onloadedmetadata=()=>{
    if(Number.isFinite(video.duration)&&video.duration>0){
      state.duration=video.duration;
      renderAll();
    }
  };

  video.ontimeupdate=()=>{
    state.position=video.currentTime;
    renderTime();
    renderPlayhead();
    renderObjects();
    renderConnections();
  };

  video.onended=()=>{
    state.position=state.duration;
    renderTime();
    renderPlayhead();
    renderObjects();
    renderConnections();
  };

  renderAll();
}

$("backButton").onclick=()=>{
  if(state.videoType==="work")saveCurrentWork();
  stopVideo();
  $("editor").classList.add("hidden");
  $("main").classList.remove("hidden");
  openSelection();
};

function stopVideo(){
  const v=$("videoPlayer");
  v.pause();
  v.currentTime=0;
}

$("play").onclick=()=>{
  const v=$("videoPlayer");
  v.play().catch(()=>showMessage("動画を再生できませんでした"));
};

$("pause").onclick=()=>$("videoPlayer").pause();

$("stop").onclick=()=>{
  const v=$("videoPlayer");
  v.pause();
  v.currentTime=0;
  state.position=0;
  renderAll();
};

$("addComment").onclick=()=>addObject("comment");
$("addHighlight").onclick=()=>addObject("highlight");
$("addZoom").onclick=()=>addObject("zoom");
$("addSkip").onclick=()=>addObject("skip");

$("connectButton").onclick=()=>{
  state.connecting=!state.connecting;
  state.source=null;
  $("connectionMode").textContent=state.connecting
    ?"接続モード：接続元を選択してください"
    :"";
  renderObjects();
};

function addObject(type){
  const o={
    id:"o_"+state.nextObject++,
    type:type,
    x:.18,
    y:.18,
    w:type==="comment"?.25:.28,
    h:type==="comment"?.10:.22,
    start:0,
    end:state.duration
  };

  state.objects.push(o);
  state.selected=o.id;
  renderAll();
  showMessage("オブジェクトを追加しました");
}

function deleteObject(id){
  state.objects=state.objects.filter(o=>o.id!==id);
  state.connections=state.connections.filter(c=>c.from!==id);
  if(state.selected===id)state.selected=null;
  if(state.source===id)state.source=null;
  renderAll();
}

function objectVisible(o){
  return state.position>=o.start&&state.position<=o.end;
}

function renderObjects(){
  const layer=$("objectsLayer");
  layer.innerHTML="";

  state.objects.forEach(o=>{
    if(!objectVisible(o))return;

    const el=document.createElement("div");
    el.className=`object ${o.type}`;
    if(state.selected===o.id)el.classList.add("selected");
    if(state.source===o.id)el.classList.add("connectSource");

    el.dataset.id=o.id;
    el.style.left=(o.x*100)+"%";
    el.style.top=(o.y*100)+"%";
    el.style.width=(o.w*100)+"%";
    el.style.height=(o.h*100)+"%";

    if(o.type==="comment"){
      el.textContent="コメント";
    }else if(o.type==="highlight"){
      el.innerHTML='<span class="objectLabel">強調</span>';
    }else if(o.type==="zoom"){
      el.innerHTML='<span class="objectLabel">拡大</span>';
    }else{
      el.innerHTML='<span class="objectLabel">スキップ</span>';
    }

    const del=document.createElement("span");
    del.className="deleteHandle";
    del.textContent="×";
    del.onclick=e=>{
      e.stopPropagation();
      deleteObject(o.id);
    };
    el.appendChild(del);

    el.onpointerdown=e=>{
      if(e.target===del)return;
      handleObjectPointerDown(e,o);
    };

    el.onclick=e=>{
      if(state.connecting){
        handleConnectionClick(o.id);
      }else{
        state.selected=o.id;
        renderObjects();
        renderConnections();
      }
      e.stopPropagation();
    };

    layer.appendChild(el);
  });
}

function handleObjectPointerDown(e,o){
  if(state.connecting)return;

  state.selected=o.id;

  const area=$("videoArea").getBoundingClientRect();
  state.dragging={
    id:o.id,
    startX:e.clientX,
    startY:e.clientY,
    x:o.x,
    y:o.y,
    areaW:area.width,
    areaH:area.height
  };

  e.currentTarget.setPointerCapture(e.pointerId);
  e.currentTarget.onpointermove=moveObject;
  e.currentTarget.onpointerup=endObjectMove;
}

function moveObject(e){
  if(!state.dragging)return;

  const d=state.dragging;
  const o=state.objects.find(x=>x.id===d.id);
  if(!o)return;

  o.x=Math.max(0,Math.min(1-o.w,d.x+(e.clientX-d.startX)/d.areaW));
  o.y=Math.max(0,Math.min(1-o.h,d.y+(e.clientY-d.startY)/d.areaH));

  renderObjects();
  renderConnections();
}

function endObjectMove(){
  state.dragging=null;
}

function handleConnectionClick(id){
  if(!state.source){
    state.source=id;
    $("connectionMode").textContent="接続モード：接続先を選択してください";
    renderObjects();
    return;
  }

  if(state.source===id){
    state.source=null;
    $("connectionMode").textContent="接続モード：接続元を選択してください";
    renderObjects();
    return;
  }

  state.connections=state.connections.filter(c=>!(c.from===state.source&&c.to===id));
  state.connections.push({
    id:"c_"+Date.now(),
    from:state.source,
    to:id
  });

  state.source=null;
  $("connectionMode").textContent="接続を作成しました。接続元を選択してください";
  renderConnections();
  renderObjects();
}

function anchor(o,side){
  if(side==="right")return{x:o.x+o.w,y:o.y+o.h/2};
  if(side==="left")return{x:o.x,y:o.y+o.h/2};
  if(side==="bottom")return{x:o.x+o.w/2,y:o.y+o.h};
  return{x:o.x+o.w/2,y:o.y};
}

function makePath(a,b){
  const start=anchor(a,"right");
  const end=anchor(b,"left");
  const mid=(start.x+end.x)/2;
  return `M ${start.x*100} ${start.y*100} L ${mid*100} ${start.y*100} L ${mid*100} ${end.y*100} L ${end.x*100} ${end.y*100}`;
}

function renderConnections(){
  const svg=$("connections");
  svg.innerHTML="";

  state.connections.forEach(c=>{
    const from=state.objects.find(o=>o.id===c.from);
    const to=state.objects.find(o=>o.id===c.to);

    if(!from||!to)return;
    if(!objectVisible(from))return;

    const path=makePath(from,to);

    const hit=document.createElementNS("http://www.w3.org/2000/svg","path");
    hit.setAttribute("d",path);
    hit.classList.add("connectionHit");
    hit.onclick=()=>{
      state.connections=state.connections.filter(x=>x.id!==c.id);
      renderConnections();
    };

    const line=document.createElementNS("http://www.w3.org/2000/svg","path");
    line.setAttribute("d",path);
    line.classList.add("connection");

    svg.appendChild(hit);
    svg.appendChild(line);
  });

  if(state.source){
    const from=state.objects.find(o=>o.id===state.source);
    if(from&&objectVisible(from)){
      const temp=document.createElementNS("http://www.w3.org/2000/svg","path");
      const p=anchor(from,"right");
      const x=(p.x+.08)*100;
      temp.setAttribute("d",`M ${p.x*100} ${p.y*100} L ${x} ${p.y*100}`);
      temp.classList.add("connection");
      temp.setAttribute("stroke-dasharray","5 4");
      svg.appendChild(temp);
    }
  }
}

function renderTimeline(){
  const s=scales[state.scale];
  $("scaleText").textContent=s.label;

  const width=Math.max(900,state.duration/s.step*s.width);
  $("timelineInner").style.width=width+"px";

  const ruler=$("ruler");
  ruler.innerHTML="";

  for(let t=0;t<=state.duration+0.001;t+=s.step){
    const tick=document.createElement("div");
    tick.className="tick";
    tick.style.left=(t/state.duration*width)+"px";
    tick.textContent=formatTime(t);
    ruler.appendChild(tick);
  }

  const track=$("trackArea");
  track.innerHTML="";

  const clip=document.createElement("div");
  clip.className="clip";
  clip.style.left="0";
  clip.style.width=width+"px";
  clip.textContent=state.videoName;
  track.appendChild(clip);

  renderPlayhead();
}

function renderPlayhead(){
  const s=scales[state.scale];
  const width=Math.max(900,state.duration/s.step*s.width);
  $("playhead").style.left=(state.position/state.duration*width)+"px";
}

$("scale").oninput=e=>{
  state.scale=Number(e.target.value);
  renderTimeline();
  renderConnections();
};

$("timelineViewport").onclick=e=>{
  if(e.target.closest(".clip"))return;

  const inner=$("timelineInner").getBoundingClientRect();
  const x=e.clientX-inner.left;
  const width=inner.width;
  const t=Math.max(0,Math.min(state.duration,x/width*state.duration));
  $("videoPlayer").currentTime=t;
  state.position=t;
  renderAll();
};

function renderTime(){
  $("time").textContent=`${formatTime(state.position)} / ${formatTime(state.duration)}`;
}

function renderAll(){
  renderTime();
  renderObjects();
  renderConnections();
  renderTimeline();
}

function saveCurrentWork(){
  if(state.videoType!=="work")return;

  const works=getWorks();
  const i=works.findIndex(w=>w.id===state.videoId);

  if(i>=0){
    works[i].objects=state.objects;
    works[i].connections=state.connections;
    works[i].videoDuration=state.duration;
    saveWorks(works);
  }
}

window.addEventListener("resize",()=>{
  renderConnections();
  renderPlayhead();
});

window.addEventListener("keydown",e=>{
  if(e.key==="Delete"&&state.selected){
    deleteObject(state.selected);
  }

  if(e.key==="Escape"&&state.connecting){
    state.connecting=false;
    state.source=null;
    $("connectionMode").textContent="";
    renderObjects();
    renderConnections();
  }
});

renderVideoChoices();
</script>
</body>
</html>

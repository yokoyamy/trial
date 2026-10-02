<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f5f8;color:#202733;font-family:Arial,"Hiragino Kaku Gothic ProN",Meiryo,sans-serif}
button,input,select{font:inherit}
button{cursor:pointer}
.hidden{display:none!important}

.top{height:58px;background:#172033;color:#fff;display:flex;align-items:center;gap:12px;padding:0 18px}
.top h1{margin:0;flex:1;font-size:17px}
.top button{border:0;border-radius:6px;padding:8px 13px;background:#fff;color:#172033}
.top .primary{background:#2563eb;color:#fff}

#home{max-width:1100px;margin:auto;padding:24px}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:18px;margin-bottom:18px}
.panel h2{font-size:16px;margin:0 0 14px}
.video-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.video-card{border:1px solid #d9dee7;border-radius:8px;padding:14px;background:#fff}
.video-card strong{display:block;margin-bottom:7px}
.video-card small{display:block;color:#667085}
.actions{display:flex;gap:7px;margin-top:12px}
.actions button{border:1px solid #cfd5df;background:#fff;border-radius:5px;padding:6px 9px}
.empty{color:#667085;padding:10px 0}

#editor{display:none;height:calc(100vh - 58px);overflow:hidden}
.editor-main{height:100%;display:flex;flex-direction:column;min-width:0}
.stage-wrap{flex:1;min-height:320px;padding:14px;background:#252b36}
.stage{position:relative;width:100%;height:100%;background:#111;overflow:hidden;border-radius:7px}
.stage video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000}
.overlay{position:absolute;inset:0;pointer-events:none}
.el{position:absolute;pointer-events:auto;user-select:none;cursor:move}
.el.selected{outline:2px solid #3b82f6;outline-offset:2px}
.comment{padding:8px 11px;white-space:pre-wrap;min-width:100px}
.zoom{border:2px solid #a855f7;background:rgba(168,85,247,.08)}
.el .resize{position:absolute;right:-5px;bottom:-5px;width:10px;height:10px;background:#fff;border:2px solid #2563eb;border-radius:2px;cursor:nwse-resize}

.line-layer{position:absolute;inset:0;width:100%;height:100%;z-index:8;overflow:visible;pointer-events:none}
.line-group{pointer-events:none}
.line-visible{fill:none;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
.line-hit{fill:none;stroke:transparent;stroke-width:16;pointer-events:stroke;cursor:pointer}
.line.selected .line-visible{filter:drop-shadow(0 0 3px #fff)}
.temp-line{fill:none;stroke:#22c55e;stroke-width:3;stroke-dasharray:7 5;pointer-events:none}

.connect-port{position:absolute;width:12px;height:12px;border:2px solid #fff;background:#22c55e;border-radius:50%;z-index:30;cursor:crosshair;display:none}
.el.selected .connect-port{display:block}
.connect-port.left{left:-6px;top:50%;margin-top:-6px}
.connect-port.right{right:-6px;top:50%;margin-top:-6px}
.connect-port.top{top:-6px;left:50%;margin-left:-6px}
.connect-port.bottom{bottom:-6px;left:50%;margin-left:-6px}
.el.connection-target{outline:3px dashed #38bdf8}

.connecting{position:absolute;left:12px;top:12px;background:#f59e0b;color:#111;padding:7px 10px;border-radius:5px;font-size:12px;z-index:40}

.bottom{height:285px;background:#fff;border-top:1px solid #ccd2dc;display:flex;flex-direction:column}
.controls{height:48px;border-bottom:1px solid #e0e4ea;display:flex;align-items:center;gap:8px;padding:6px 10px}
.controls button{border:1px solid #cbd2dc;background:#fff;border-radius:5px;padding:6px 10px}
.controls .play{background:#2563eb;color:#fff;border-color:#2563eb}
.time{font-variant-numeric:tabular-nums;min-width:105px}
.scale{display:flex;align-items:center;gap:5px;margin-left:auto}
.scale input{width:110px}

.timeline-area{position:relative;flex:1;min-height:0;overflow:hidden}
.timeline{position:absolute;inset:0}
.ruler{height:30px;position:absolute;left:0;right:0;top:0;border-bottom:1px solid #d7dce4;background:#fafbfc}
.tick{position:absolute;top:0;height:30px;border-left:1px solid #cfd5df;color:#667085;font-size:10px;padding-left:3px}
.tracks{position:absolute;left:0;right:0;top:30px;bottom:0;overflow:hidden}
.track-row{position:absolute;left:0;right:0;height:38px;border-bottom:1px solid #edf0f4}
.track-label{position:absolute;left:7px;top:10px;width:72px;font-size:11px;color:#667085}
.track-content{position:absolute;left:82px;right:0;top:0;bottom:0}
.block{position:absolute;height:27px;top:5px;border-radius:4px;color:#fff;font-size:11px;padding:6px 13px;cursor:grab;overflow:hidden;white-space:nowrap}
.block.selected{box-shadow:0 0 0 2px #2563eb inset}
.block.comment{background:#2563eb}
.block.highlight{background:#f97316}
.block.zoom{background:#9333ea}
.block.skip{background:#64748b}
.handle{position:absolute;top:0;bottom:0;width:8px;cursor:ew-resize}
.handle.l{left:0}.handle.r{right:0}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#e11d48;z-index:30;pointer-events:none}
.playhead:before{content:"";position:absolute;left:-5px;top:0;width:12px;height:12px;border-radius:50%;background:#e11d48}

.context{position:fixed;display:none;z-index:1000;width:260px;max-height:80vh;overflow:auto;background:#fff;border:1px solid #cbd2dc;border-radius:7px;box-shadow:0 8px 28px #0003;padding:5px}
.context button{display:block;width:100%;text-align:left;background:#fff;border:0;padding:8px 10px;border-radius:4px}
.context button:hover{background:#eef4ff}
.menu-title{font-size:11px;color:#667085;padding:6px 10px;border-bottom:1px solid #edf0f4}
.menu-row{padding:7px 10px;font-size:12px}
.menu-row label{display:block;margin-bottom:4px;color:#475467}
.menu-row input[type=text],.menu-row input[type=number],.menu-row select{width:100%;padding:5px;border:1px solid #cbd2dc;border-radius:4px;background:#fff}
.colors{display:flex;flex-wrap:wrap;gap:5px}
.color{width:21px;height:21px;border-radius:4px;border:2px solid #fff;box-shadow:0 0 0 1px #b8bec8;cursor:pointer;padding:0}
.color.active{outline:2px solid #111;outline-offset:1px}
.sep{height:1px;background:#e7eaf0;margin:4px 0}

.notice{position:fixed;right:15px;bottom:15px;background:#172033;color:#fff;border-radius:6px;padding:10px 14px;display:none;z-index:2000}
.modal{position:fixed;inset:0;background:#0007;display:none;align-items:center;justify-content:center;z-index:1500}
.modal-box{background:#fff;border-radius:9px;padding:20px;width:min(420px,90vw)}
.modal-box h3{margin:0 0 14px}
.modal-box input{width:100%;padding:8px;border:1px solid #cbd2dc;border-radius:5px}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:15px}
.modal-actions button{padding:7px 12px;border:1px solid #cbd2dc;background:#fff;border-radius:5px}
.modal-actions .ok{background:#2563eb;color:#fff;border-color:#2563eb}

@media(max-width:700px){
  .bottom{height:300px}
  .controls{flex-wrap:wrap;height:70px}
  .scale{margin-left:0}
}
</style>
</head>
<body>

<header class="top">
  <h1 id="title">動画編集</h1>
  <button id="backBtn">編集画面を終了</button>
  <button id="saveBtn" class="primary">保存</button>
  <button id="exportBtn">編集結果を動画にする</button>
</header>

<section id="home">
  <div class="panel">
    <h2>動画を選択</h2>
    <input id="videoFile" type="file" accept="video/*">
    <div id="videoList" class="video-list"></div>
  </div>
  <div class="panel">
    <h2>保存済み編集作業</h2>
    <div id="projectList" class="video-list"></div>
  </div>
  <div class="panel">
    <h2>編集結果動画</h2>
    <div id="resultList" class="video-list"></div>
  </div>
</section>

<section id="editor">
  <main class="editor-main">
    <div class="stage-wrap">
      <div id="stage" class="stage">
        <video id="video" controls></video>
        <svg id="lineLayer" class="line-layer"></svg>
        <div id="overlay" class="overlay"></div>
        <div id="connecting" class="connecting" style="display:none">
          接続中：接続先の要素へドラッグしてください。
        </div>
      </div>
    </div>

    <div class="bottom">
      <div class="controls">
        <button id="playBtn" class="play">▶ 再生</button>
        <button id="pauseBtn">Ⅱ 一時停止</button>
        <span id="time" class="time">0:00 / 0:00</span>
        <button id="zoomOut">－</button>
        <button id="zoomIn">＋</button>
        <div class="scale">
          スケール
          <input id="scale" type="range" min="50" max="200" value="100">
        </div>
      </div>

      <div id="timelineArea" class="timeline-area">
        <div id="timeline" class="timeline">
          <div id="ruler" class="ruler"></div>
          <div id="tracks" class="tracks"></div>
          <div id="playhead" class="playhead"></div>
        </div>
      </div>
    </div>
  </main>
</section>

<div id="menu" class="context"></div>
<div id="notice" class="notice"></div>

<div id="modal" class="modal">
  <div class="modal-box">
    <h3 id="modalTitle"></h3>
    <div id="modalBody"></div>
    <div class="modal-actions">
      <button id="modalCancel">キャンセル</button>
      <button id="modalOk" class="ok">確定</button>
    </div>
  </div>
</div>

<script>
"use strict";

const $=id=>document.getElementById(id);

const PALETTE=[
  "#000000","#ffffff","#ef4444","#f97316","#f59e0b","#eab308",
  "#84cc16","#22c55e","#14b8a6","#06b6d4","#3b82f6","#6366f1",
  "#8b5cf6","#ec4899","#64748b","#92400e"
];

const state={
  duration:60,
  scale:1,
  videoUrl:"",
  videoName:"",
  selected:new Set(),
  contextId:null,
  contextType:null,
  connecting:null,
  elements:[],
  lines:[],
  projects:JSON.parse(localStorage.getItem("mockProjects")||"[]"),
  results:JSON.parse(localStorage.getItem("mockResults")||"[]"),
  dirty:false,
  nextId:1,
  skipLock:false
};

function uid(prefix="e"){return prefix+(state.nextId++);}
function clamp(v,a,b){return Math.max(a,Math.min(b,v));}
function esc(v){
  return String(v).replace(/[&<>"']/g,c=>({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"
  }[c]));
}
function fmt(t){
  t=Math.max(0,Number(t)||0);
  return Math.floor(t/60)+":"+String(Math.floor(t%60)).padStart(2,"0");
}
function active(id){return state.elements.find(e=>e.id===id);}
function activeLine(id){return state.lines.find(l=>l.id===id);}

function markDirty(){
  state.dirty=true;
  $("title").textContent="動画編集 *";
}
function toast(text){
  $("notice").textContent=text;
  $("notice").style.display="block";
  clearTimeout(toast.timer);
  toast.timer=setTimeout(()=>$("notice").style.display="none",1800);
}

function home(){
  $("home").style.display="block";
  $("editor").style.display="none";
  hideMenu();
  renderLists();
}

function openEditor(){
  if(!state.videoUrl)return;
  $("home").style.display="none";
  $("editor").style.display="flex";
  $("title").textContent=(state.videoName||"動画")+" - 編集";
  render();
}

function renderLists(){
  const list=$("videoList");
  list.innerHTML="";

  if(!state.videoUrl){
    list.innerHTML="<div class='video-card'><strong>動画未選択</strong><small>上の「動画を選択」から動画ファイルを指定してください。</small></div>";
  }else{
    const card=document.createElement("div");
    card.className="video-card";
    card.innerHTML="<strong>"+esc(state.videoName)+"</strong><small>"+fmt(state.duration)+" / オリジナル動画</small><div class='actions'><button id='openCurrent'>編集する</button></div>";
    list.appendChild(card);
    $("openCurrent").onclick=openEditor;
  }

  $("projectList").innerHTML=state.projects.length
    ?state.projects.map((p,i)=>`
      <div class="video-card">
        <strong>${esc(p.name)}</strong>
        <small>${esc(p.videoName)} / ${esc(p.savedAt)}</small>
        <div class="actions">
          <button onclick="resumeProject(${i})">再開</button>
          <button onclick="duplicateProject(${i})">複製</button>
          <button onclick="deleteProject(${i})">削除</button>
        </div>
      </div>`).join("")
    :"<div class='empty'>保存済み編集作業はありません。</div>";

  $("resultList").innerHTML=state.results.length
    ?state.results.map((r,i)=>`
      <div class="video-card">
        <strong>${esc(r.name)}</strong>
        <small>編集結果動画 / 元動画: ${esc(r.videoName)}</small>
        <div class="actions"><button onclick="useResult(${i})">編集対象にする</button></div>
      </div>`).join("")
    :"<div class='empty'>編集結果動画はありません。</div>";
}

$("videoFile").onchange=e=>{
  const f=e.target.files[0];
  if(!f)return;

  const url=URL.createObjectURL(f);
  const probe=document.createElement("video");
  probe.preload="metadata";

  probe.onloadedmetadata=()=>{
    state.videoUrl=url;
    state.videoName=f.name;
    state.duration=probe.duration||60;
    state.elements=[];
    state.lines=[];
    state.selected.clear();
    state.dirty=false;

    $("video").src=url;
    $("video").onloadedmetadata=()=>{
      state.duration=$("video").duration||state.duration;
      home();
      toast("動画を選択しました");
    };
    $("video").load();
  };

  probe.onerror=()=>toast("動画の読み込みに失敗しました。");
  probe.src=url;
};

$("playBtn").onclick=()=>{
  if(!$("video").src)return;
  $("video").play();
};

$("pauseBtn").onclick=()=>$("video").pause();

$("video").ontimeupdate=()=>{
  skipIfNeeded();
  updatePosition();
};

$("video").onended=()=>{
  state.skipLock=false;
  updatePosition();
};

function skipIfNeeded(){
  const v=$("video");
  if(v.paused||v.ended||state.skipLock)return;

  const skip=state.elements
    .filter(e=>e.type==="skip")
    .find(e=>v.currentTime>=e.start && v.currentTime<e.end);

  if(!skip)return;

  const next=Math.min(skip.end+0.001,state.duration);

  state.skipLock=true;

  if(next>=state.duration){
    v.currentTime=Math.max(0,state.duration-0.001);
    state.skipLock=false;
    return;
  }

  v.currentTime=next;

  requestAnimationFrame(()=>{
    state.skipLock=false;
    if(!v.paused&&!v.ended)v.play().catch(()=>{});
  });
}

function updatePosition(){
  const v=$("video");
  const dur=Math.max(0,state.duration);
  $("time").textContent=fmt(v.currentTime)+" / "+fmt(dur);

  const area=$("timelineArea");
  if(area){
    const w=area.clientWidth||1;
    const x=dur?(v.currentTime/dur)*w:0;
    $("playhead").style.left=x+"px";
  }

  renderVideo();
  renderTimeline();
}

$("scale").oninput=e=>{
  state.scale=Number(e.target.value)/100;
  renderTimeline();
};

$("zoomIn").onclick=()=>{
  $("scale").value=Math.min(200,Number($("scale").value)+10);
  $("scale").oninput();
};

$("zoomOut").onclick=()=>{
  $("scale").value=Math.max(50,Number($("scale").value)-10);
  $("scale").oninput();
};

function render(){
  if(!$("stage")||!$("timelineArea"))return;
  renderVideo();
  renderLines();
  renderTimeline();
  updatePositionOnly();
}

function updatePositionOnly(){
  const v=$("video");
  $("time").textContent=fmt(v.currentTime)+" / "+fmt(state.duration);
  const area=$("timelineArea");
  const w=area?area.clientWidth:1;
  $("playhead").style.left=(state.duration?v.currentTime/state.duration*w:0)+"px";
}

function renderVideo(){
  const overlay=$("overlay");
  overlay.innerHTML="";

  const v=$("video");
  const now=v.currentTime||0;

  state.elements
    .filter(e=>e.type!=="skip"&&now>=e.start&&now<=e.end)
    .forEach(e=>{
      const d=document.createElement("div");
      d.className="el "+e.type+(state.selected.has(e.id)?" selected":"");
      d.dataset.id=e.id;
      d.style.left=e.x+"%";
      d.style.top=e.y+"%";
      d.style.width=e.w+"%";
      d.style.height=e.h+"%";

      if(state.connecting&&state.connecting.from!==e.id){
        d.classList.add("connection-target");
      }

      if(e.type==="comment"){
        d.classList.add("comment");
        d.textContent=e.text;
        d.style.color=e.textColor;
        d.style.background=e.fill?e.bg:"transparent";
        d.style.border=e.border
          ?e.borderWidth+"px "+e.borderStyle+" "+e.borderColor
          :"none";
        d.style.fontSize=e.fontSize+"px";
        d.style.fontFamily=e.font;
      }

      if(e.type==="highlight"){
        d.style.border=e.borderWidth+"px "+e.borderStyle+" "+e.borderColor;
        d.style.background=e.fill?e.fillColor:"transparent";
      }

      if(e.type==="zoom"){
        d.style.border="2px solid #a855f7";
        d.style.background="rgba(168,85,247,.08)";
        d.innerHTML="<span style='background:#9333ea;color:#fff;padding:3px 5px;font-size:11px'>拡大枠</span>";
      }

      if(state.selected.has(e.id)){
        ["left","right","top","bottom"].forEach(side=>{
          const p=document.createElement("i");
          p.className="connect-port "+side;
          p.dataset.port=side;
          p.onpointerdown=ev=>startConnection(ev,e,side);
          d.appendChild(p);
        });

        const resize=document.createElement("i");
        resize.className="resize";
        resize.onpointerdown=ev=>startResize(ev,e);
        d.appendChild(resize);
      }

      d.onpointerdown=ev=>{
        if(ev.target.classList.contains("resize")||
           ev.target.classList.contains("connect-port"))return;
        startMove(ev,e);
      };

      d.onclick=ev=>{
        ev.stopPropagation();
        if(state.connecting){
          finishConnection(e);
          return;
        }
        select(e.id,ev.shiftKey);
      };

      d.oncontextmenu=ev=>{
        ev.preventDefault();
        ev.stopPropagation();
        select(e.id,false);
        showMenu(ev.clientX,ev.clientY,e.id,e.type);
      };

      overlay.appendChild(d);
    });

  renderLines();
}

function anchor(e,side,W,H){
  const x=e.x/100*W;
  const y=e.y/100*H;
  const w=e.w/100*W;
  const h=e.h/100*H;

  if(side==="left")return{x:x,y:y+h/2};
  if(side==="top")return{x:x+w/2,y:y};
  if(side==="bottom")return{x:x+w/2,y:y+h};
  return{x:x+w,y:y+h/2};
}

function rectOf(e,W,H){
  return{
    x:e.x/100*W,
    y:e.y/100*H,
    w:e.w/100*W,
    h:e.h/100*H
  };
}

function intersects(a,b){
  return a.x<b.x+b.w&&a.x+a.w>b.x&&a.y<b.y+b.h&&a.y+a.h>b.y;
}

function route(line,a,b,W,H){
  if(line.shape==="straight")return[a,b];

  const others=state.elements
    .filter(e=>e.id!==line.from&&e.id!==line.to&&e.type!=="skip")
    .map(e=>rectOf(e,W,H));

  if(line.shape==="elbow"){
    const candidates=[
      [a,{x:b.x,y:a.y},b],
      [a,{x:a.x,y:b.y},b],
      [a,{x:(a.x+b.x)/2,y:a.y},{x:(a.x+b.x)/2,y:b.y},b],
      [a,{x:a.x,y:(a.y+b.y)/2},{x:b.x,y:(a.y+b.y)/2},b]
    ];

    for(const points of candidates){
      let blocked=false;
      for(let i=0;i<points.length-1&&!blocked;i++){
        const p=points[i],q=points[i+1];
        const box={
          x:Math.min(p.x,q.x)-8,
          y:Math.min(p.y,q.y)-8,
          w:Math.abs(q.x-p.x)+16,
          h:Math.abs(q.y-p.y)+16
        };
        if(others.some(o=>intersects(box,o)))blocked=true;
      }
      if(!blocked)return points;
    }

    return candidates[0];
  }

  const points=[a];
  const dx=b.x-a.x;
  const dy=b.y-a.y;

  for(let i=1;i<8;i++){
    const t=i/8;
    points.push({
      x:a.x+dx*t,
      y:a.y+dy*t+Math.sin(t*Math.PI*4)*14
    });
  }

  points.push(b);
  return points;
}

function pathD(points,shape){
  if(shape==="wavy"){
    let d="M "+points[0].x+" "+points[0].y;

    for(let i=1;i<points.length;i++){
      const a=points[i-1],b=points[i];
      const dx=b.x-a.x,dy=b.y-a.y;
      const len=Math.hypot(dx,dy)||1;
      const nx=-dy/len*6,ny=dx/len*6;

      d+=" C "+
        (a.x+dx/3+nx)+" "+(a.y+dy/3+ny)+" "+
        (a.x+dx*2/3-nx)+" "+(a.y+dy*2/3-ny)+" "+
        b.x+" "+b.y;
    }

    return d;
  }

  return points.map((p,i)=>
    (i?"L ":"M ")+p.x+" "+p.y
  ).join(" ");
}

function markerId(kind,color){
  return "mk_"+kind+"_"+color.replace("#","");
}

function renderLines(){
  const svg=$("lineLayer");
  if(!svg)return;

  svg.replaceChildren();

  const W=$("stage").clientWidth||1;
  const H=$("stage").clientHeight||1;

  svg.setAttribute("viewBox","0 0 "+W+" "+H);
  svg.setAttribute("preserveAspectRatio","none");

  const defs=document.createElementNS("http://www.w3.org/2000/svg","defs");

  ["arrow","circle"].forEach(kind=>{
    PALETTE.forEach(color=>{
      const marker=document.createElementNS("http://www.w3.org/2000/svg","marker");
      marker.id=markerId(kind,color);
      marker.setAttribute("markerWidth","8");
      marker.setAttribute("markerHeight","8");
      marker.setAttribute("refX",kind==="arrow"?"7":"4");
      marker.setAttribute("refY","4");
      marker.setAttribute("orient","auto");
      marker.setAttribute("markerUnits","userSpaceOnUse");

      if(kind==="arrow"){
        const p=document.createElementNS("http://www.w3.org/2000/svg","path");
        p.setAttribute("d","M0,0 L8,4 L0,8 Z");
        p.setAttribute("fill",color);
        marker.appendChild(p);
      }else{
        const c=document.createElementNS("http://www.w3.org/2000/svg","circle");
        c.setAttribute("cx","4");
        c.setAttribute("cy","4");
        c.setAttribute("r","3");
        c.setAttribute("fill",color);
        marker.appendChild(c);
      }

      defs.appendChild(marker);
    });
  });

  svg.appendChild(defs);

  state.lines.forEach(line=>{
    const a=active(line.from);
    const b=active(line.to);
    if(!a||!b)return;

    const p1=anchor(a,line.fromSide||"right",W,H);
    const p2=anchor(b,line.toSide||"left",W,H);
    const points=route(line,p1,p2,W,H);
    const d=pathD(points,line.shape);

    const g=document.createElementNS("http://www.w3.org/2000/svg","g");
    g.classList.add("line-group");
    g.dataset.line=line.id;

    if(state.selected.has(line.id))g.classList.add("line","selected");
    else g.classList.add("line");

    const visible=document.createElementNS("http://www.w3.org/2000/svg","path");
    visible.classList.add("line-visible");
    visible.setAttribute("d",d);
    visible.setAttribute("stroke",line.color);
    visible.setAttribute("stroke-width",line.width);

    if(line.dash)visible.setAttribute("stroke-dasharray",line.dash);

    if(line.end!=="none"){
      visible.setAttribute(
        "marker-end",
        "url(#"+markerId(line.end,line.color)+")"
      );
    }

    const hit=document.createElementNS("http://www.w3.org/2000/svg","path");
    hit.classList.add("line-hit");
    hit.setAttribute("d",d);
    hit.dataset.line=line.id;

    hit.onpointerdown=ev=>{
      ev.stopPropagation();
      selectLine(line.id,ev.shiftKey);
    };

    hit.oncontextmenu=ev=>{
      ev.preventDefault();
      ev.stopPropagation();
      selectLine(line.id,false);
      showMenu(ev.clientX,ev.clientY,line.id,"line");
    };

    g.appendChild(visible);
    g.appendChild(hit);
    svg.appendChild(g);
  });

  if(state.connecting){
    const p=state.connecting.start;
    const q=state.connecting.current;
    const temp=document.createElementNS("http://www.w3.org/2000/svg","path");
    temp.classList.add("temp-line");
    temp.setAttribute("d","M "+p.x+" "+p.y+" L "+q.x+" "+q.y);
    svg.appendChild(temp);
  }
}

function renderTimeline(){
  const area=$("timelineArea");
  if(!area)return;

  const width=area.clientWidth||1;
  const dur=Math.max(1,state.duration);

  $("ruler").innerHTML="";
  const step=dur<=30?5:dur<=120?10:dur<=300?30:60;

  for(let t=0;t<=dur;t+=step){
    const tick=document.createElement("div");
    tick.className="tick";
    tick.style.left=(t/dur*width)+"px";
    tick.textContent=fmt(t);
    $("ruler").appendChild(tick);
  }

  const sorted=state.elements
    .slice()
    .sort((a,b)=>a.start-b.start);

  const rows=[];

  sorted.forEach(e=>{
    let row=0;
    while(rows[row]&&rows[row].some(x=>x.start<e.end&&x.end>e.start))row++;
    if(!rows[row])rows[row]=[];
    rows[row].push(e);
    e._row=row;
  });

  $("tracks").innerHTML="";
  $("tracks").style.height=Math.max(38,rows.length*38)+"px";

  rows.forEach((_,i)=>{
    const row=document.createElement("div");
    row.className="track-row";
    row.style.top=(i*38)+"px";
    row.innerHTML="<div class='track-label'>トラック "+(i+1)+"</div><div class='track-content'></div>";
    $("tracks").appendChild(row);
  });

  sorted.forEach(e=>{
    const row=$("tracks").children[e._row];
    if(!row)return;

    const content=row.querySelector(".track-content");
    const b=document.createElement("div");

    b.className="block "+e.type+(state.selected.has(e.id)?" selected":"");
    b.dataset.id=e.id;

    const left=e.start/dur*width;
    const w=Math.max(12,(e.end-e.start)/dur*width);

    b.style.left=left+"px";
    b.style.width=w+"px";
    b.textContent=
      e.type==="comment"?e.text:
      e.type==="highlight"?"強調枠":
      e.type==="zoom"?"拡大枠":"スキップ";

    b.onpointerdown=ev=>startBlock(ev,e);
    b.onclick=ev=>{
      ev.stopPropagation();
      select(e.id,ev.shiftKey);
    };
    b.oncontextmenu=ev=>{
      ev.preventDefault();
      ev.stopPropagation();
      select(e.id,false);
      showMenu(ev.clientX,ev.clientY,e.id,e.type);
    };

    const l=document.createElement("i");
    const r=document.createElement("i");
    l.className="handle l";
    r.className="handle r";

    l.onpointerdown=ev=>startTimeResize(ev,e,"start");
    r.onpointerdown=ev=>startTimeResize(ev,e,"end");

    b.append(l,r);
    content.appendChild(b);
  });
}

function select(id,multi=false){
  if(!multi)state.selected.clear();
  state.selected.add(id);
  hideMenu();
  render();
}

function selectLine(id,multi=false){
  select(id,multi);
}

$("stage").onclick=()=>{
  state.selected.clear();
  cancelConnection();
  hideMenu();
  render();
};

$("timelineArea").onclick=ev=>{
  if(ev.target.closest(".block"))return;
  seekFromTimeline(ev.clientX);
};

function seekFromTimeline(clientX){
  const r=$("timelineArea").getBoundingClientRect();
  const t=clamp(
    (clientX-r.left)/Math.max(1,r.width)*state.duration,
    0,
    state.duration
  );
  $("video").currentTime=t;
  updatePositionOnly();
}

function startMove(ev,e){
  if(ev.button!==0)return;
  if(ev.target.classList.contains("resize")||
     ev.target.classList.contains("connect-port"))return;

  if(state.connecting){
    finishConnection(e);
    return;
  }

  select(e.id,ev.shiftKey);

  const rect=$("stage").getBoundingClientRect();
  const sx=ev.clientX,sy=ev.clientY;
  const ox=e.x,oy=e.y;

  const move=mv=>{
    e.x=clamp(
      ox+(mv.clientX-sx)/Math.max(1,rect.width)*100,
      0,
      100-e.w
    );
    e.y=clamp(
      oy+(mv.clientY-sy)/Math.max(1,rect.height)*100,
      0,
      100-e.h
    );
    markDirty();
    render();
  };

  const up=()=>{
    document.removeEventListener("pointermove",move);
    document.removeEventListener("pointerup",up);
  };

  document.addEventListener("pointermove",move);
  document.addEventListener("pointerup",up);
}

function startResize(ev,e){
  ev.stopPropagation();

  const rect=$("stage").getBoundingClientRect();
  const sx=ev.clientX,sy=ev.clientY;
  const ow=e.w,oh=e.h;

  const move=mv=>{
    e.w=clamp(
      ow+(mv.clientX-sx)/Math.max(1,rect.width)*100,
      5,
      100-e.x
    );
    e.h=clamp(
      oh+(mv.clientY-sy)/Math.max(1,rect.height)*100,
      5,
      100-e.y
    );
    markDirty();
    render();
  };

  const up=()=>{
    document.removeEventListener("pointermove",move);
    document.removeEventListener("pointerup",up);
  };

  document.addEventListener("pointermove",move);
  document.addEventListener("pointerup",up);
}

function startConnection(ev,e,side){
  ev.stopPropagation();
  ev.preventDefault();

  const r=$("stage").getBoundingClientRect();
  const p=anchor(e,side,r.width,r.height);

  state.connecting={
    from:e.id,
    fromSide:side,
    start:p,
    current:p
  };

  $("connecting").style.display="block";

  const move=mv=>{
    state.connecting.current={
      x:clamp(mv.clientX-r.left,0,r.width),
      y:clamp(mv.clientY-r.top,0,r.height)
    };
    renderLines();
  };

  const up=mv=>{
    const target=mv.target.closest(".el");

    if(target){
      const id=target.dataset.id;
      if(id&&id!==e.id){
        finishConnection(active(id));
      }else{
        cancelConnection();
      }
    }else{
      cancelConnection();
    }

    document.removeEventListener("pointermove",move);
    document.removeEventListener("pointerup",up);
  };

  document.addEventListener("pointermove",move);
  document.addEventListener("pointerup",up);
}

function finishConnection(target){
  if(!state.connecting||!target)return;

  if(target.id===state.connecting.from){
    cancelConnection();
    return;
  }

  createLine(
    state.connecting.from,
    target.id,
    state.connecting.fromSide
  );

  cancelConnection();
  render();
}

function cancelConnection(){
  state.connecting=null;
  $("connecting").style.display="none";
}

function startBlock(ev,e){
  if(ev.target.classList.contains("handle"))return;
  ev.stopPropagation();

  select(e.id,ev.shiftKey);

  const r=$("timelineArea").getBoundingClientRect();
  const sx=ev.clientX;
  const os=e.start;
  const oe=e.end;
  const len=oe-os;

  const move=mv=>{
    const dt=(mv.clientX-sx)/Math.max(1,r.width)*state.duration;
    e.start=clamp(os+dt,0,state.duration-len);
    e.end=e.start+len;
    markDirty();
    renderTimeline();
    renderVideo();
  };

  const up=()=>{
    document.removeEventListener("pointermove",move);
    document.removeEventListener("pointerup",up);
  };

  document.addEventListener("pointermove",move);
  document.addEventListener("pointerup",up);
}

function startTimeResize(ev,e,which){
  ev.stopPropagation();

  const r=$("timelineArea").getBoundingClientRect();
  const sx=ev.clientX;
  const os=e.start;
  const oe=e.end;

  const move=mv=>{
    const dt=(mv.clientX-sx)/Math.max(1,r.width)*state.duration;

    if(which==="start"){
      e.start=clamp(os+dt,0,e.end-.1);
    }else{
      e.end=clamp(oe+dt,e.start+.1,state.duration);
    }

    markDirty();
    renderTimeline();
    renderVideo();
  };

  const up=()=>{
    document.removeEventListener("pointermove",move);
    document.removeEventListener("pointerup",up);
  };

  document.addEventListener("pointermove",move);
  document.addEventListener("pointerup",up);
}

function createElement(type){
  const now=clamp($("video").currentTime||0,0,state.duration);
  const end=Math.min(state.duration,now+8);

  const e={
    id:uid(),
    type,
    start:now,
    end:Math.max(now+.5,end),
    x:12,
    y:15,
    w:type==="comment"?28:25,
    h:type==="comment"?15:25
  };

  if(type==="comment"){
    Object.assign(e,{
      text:"コメント",
      border:true,
      borderWidth:2,
      borderStyle:"solid",
      borderColor:"#2563eb",
      textColor:"#111827",
      bg:"#ffffff",
      fill:true,
      fontSize:18,
      font:"Arial"
    });
  }

  if(type==="highlight"){
    Object.assign(e,{
      borderWidth:3,
      borderStyle:"solid",
      borderColor:"#ef4444",
      fill:true,
      fillColor:"#fecaca"
    });
  }

  if(type==="skip"){
    e.x=0;e.y=0;e.w=0;e.h=0;
  }

  state.elements.push(e);
  state.selected.clear();
  state.selected.add(e.id);
  markDirty();
  hideMenu();
  render();
}

function createLine(from,to,fromSide="right"){
  if(!from||!to||from===to)return;
  if(state.lines.some(l=>l.from===from&&l.to===to))return;

  state.lines.push({
    id:uid("l"),
    from,
    to,
    shape:"straight",
    dash:"",
    width:3,
    color:"#111827",
    end:"none",
    fromSide,
    toSide:"left"
  });

  markDirty();
  toast("接続線を作成しました");
}

$("stage").addEventListener("contextmenu",ev=>{
  if(ev.target.closest(".el"))return;
  ev.preventDefault();
  showAddMenu(ev.clientX,ev.clientY);
});

$("timelineArea").addEventListener("contextmenu",ev=>{
  if(ev.target.closest(".block"))return;
  ev.preventDefault();
  showAddMenu(ev.clientX,ev.clientY);
});

function showAddMenu(x,y){
  const m=$("menu");

  m.innerHTML=
    "<div class='menu-title'>ここへ追加</div>"+
    ["comment:コメント","highlight:強調枠","zoom:拡大枠","skip:スキップ"]
      .map(v=>{
        const [a,b]=v.split(":");
        return "<button data-add='"+a+"'>"+b+"</button>";
      }).join("");

  m.querySelectorAll("[data-add]").forEach(b=>{
    b.onclick=()=>createElement(b.dataset.add);
  });

  positionMenu(x,y);
}

function showMenu(x,y,id,type){
  const m=$("menu");
  const e=active(id);
  const l=activeLine(id);

  let html="<div class='menu-title'>"+label(type)+"</div>";

  if(type==="line")html+=lineMenu(l);
  else if(type==="comment")html+=commentMenu(e);
  else if(type==="highlight")html+=highlightMenu(e);
  else if(type==="zoom")html+=zoomMenu(e);
  else if(type==="skip")html+=skipMenu(e);

  html+="<div class='sep'></div>";

  if(type!=="line"&&type!=="skip"){
    html+="<button id='connectBtn'>この要素から接続</button>";
  }

  html+="<button id='deleteBtn'>削除</button>";

  m.innerHTML=html;
  bindMenu(type,id);
  positionMenu(x,y);
}

function label(t){
  return{
    comment:"コメント",
    highlight:"強調枠",
    zoom:"拡大枠",
    skip:"スキップ",
    line:"接続線"
  }[t]||t;
}

function commentMenu(e){
  return `
    <div class="menu-row">
      <label>本文</label>
      <input id="mText" type="text" value="${esc(e.text)}">
    </div>
    <div class="menu-row">
      <label>線</label>
      <select id="mBorder">
        <option value="1" ${e.border?"selected":""}>あり</option>
        <option value="0" ${!e.border?"selected":""}>なし</option>
      </select>
    </div>
    <div class="menu-row">
      <label>線種</label>
      <select id="mBorderStyle">${styles(e.borderStyle)}</select>
    </div>
    <div class="menu-row">
      <label>線の太さ</label>
      <select id="mBorderWidth">
        ${[1,2,3,4].map(v=>`<option ${e.borderWidth===v?"selected":""}>${v}</option>`).join("")}
      </select>
    </div>
    <div class="menu-row">
      <label>文字色</label>
      <div class="colors">${palette("mTextColor",e.textColor)}</div>
    </div>
    <div class="menu-row">
      <label>背景色</label>
      <div class="colors">${palette("mBg",e.bg)}</div>
    </div>
    <div class="menu-row">
      <label>塗り潰し</label>
      <select id="mFill">
        <option value="1" ${e.fill?"selected":""}>あり</option>
        <option value="0" ${!e.fill?"selected":""}>なし</option>
      </select>
    </div>`;
}

function highlightMenu(e){
  return `
    <div class="menu-row">
      <label>線種</label>
      <select id="mBorderStyle">${styles(e.borderStyle)}</select>
    </div>
    <div class="menu-row">
      <label>線の太さ</label>
      <select id="mBorderWidth">
        ${[1,2,3,4,6].map(v=>`<option ${e.borderWidth===v?"selected":""}>${v}</option>`).join("")}
      </select>
    </div>
    <div class="menu-row">
      <label>線色</label>
      <div class="colors">${palette("mBorderColor",e.borderColor)}</div>
    </div>
    <div class="menu-row">
      <label>塗り潰し色</label>
      <div class="colors">${palette("mFillColor",e.fillColor)}</div>
    </div>
    <div class="menu-row">
      <label>塗り潰し</label>
      <select id="mFill">
        <option value="1" ${e.fill?"selected":""}>あり</option>
        <option value="0" ${!e.fill?"selected":""}>なし</option>
      </select>
    </div>`;
}

function zoomMenu(){
  return `<div class="menu-row">指定範囲を拡大表示する枠です。</div>`;
}

function skipMenu(e){
  return `
    <div class="menu-row">
      <label>開始</label>
      <input id="mStart" type="number" min="0" max="${state.duration}" step=".1" value="${e.start.toFixed(1)}">
    </div>
    <div class="menu-row">
      <label>終了</label>
      <input id="mEnd" type="number" min="0" max="${state.duration}" step=".1" value="${e.end.toFixed(1)}">
    </div>`;
}

function lineMenu(l){
  return `
    <div class="menu-row">
      <label>線形</label>
      <select id="mShape">
        <option value="straight" ${l.shape==="straight"?"selected":""}>直線</option>
        <option value="elbow" ${l.shape==="elbow"?"selected":""}>折れ線</option>
        <option value="wavy" ${l.shape==="wavy"?"selected":""}>波線</option>
      </select>
    </div>
    <div class="menu-row">
      <label>線種</label>
      <select id="mDash">
        <option value="" ${!l.dash?"selected":""}>実線</option>
        <option value="2 5" ${l.dash==="2 5"?"selected":""}>点線</option>
        <option value="9 5" ${l.dash==="9 5"?"selected":""}>破線</option>
        <option value="12 5 2 5" ${l.dash==="12 5 2 5"?"selected":""}>一点鎖線</option>
      </select>
    </div>
    <div class="menu-row">
      <label>太さ</label>
      <select id="mWidth">
        ${[2,3,4,6].map(v=>`<option ${l.width===v?"selected":""}>${v}</option>`).join("")}
      </select>
    </div>
    <div class="menu-row">
      <label>色</label>
      <div class="colors">${palette("mLineColor",l.color)}</div>
    </div>
    <div class="menu-row">
      <label>終端</label>
      <select id="mEnd">
        <option value="none" ${l.end==="none"?"selected":""}>なし</option>
        <option value="arrow" ${l.end==="arrow"?"selected":""}>矢印</option>
        <option value="circle" ${l.end==="circle"?"selected":""}>丸</option>
      </select>
    </div>
    <div class="menu-row">
      <label>始点位置</label>
      <select id="mFromSide">${sides(l.fromSide)}</select>
    </div>
    <div class="menu-row">
      <label>終点位置</label>
      <select id="mToSide">${sides(l.toSide)}</select>
    </div>`;
}

function styles(v){
  return[
    ["solid","実線"],
    ["dotted","点線"],
    ["dashed","破線"],
    ["dashdot","一点鎖線"]
  ].map(x=>`<option value="${x[0]}" ${v===x[0]?"selected":""}>${x[1]}</option>`).join("");
}

function sides(v){
  return[
    ["left","左"],
    ["right","右"],
    ["top","上"],
    ["bottom","下"]
  ].map(x=>`<option value="${x[0]}" ${v===x[0]?"selected":""}>${x[1]}</option>`).join("");
}

function palette(id,current){
  return PALETTE.map(c=>`
    <button
      type="button"
      class="color ${current===c?"active":""}"
      data-color="${id}"
      data-value="${c}"
      style="background:${c}"
      title="${c}">
    </button>`).join("");
}

function bindMenu(type,id){
  const e=active(id);
  const l=activeLine(id);

  $("menu").querySelectorAll("[data-color]").forEach(b=>{
    b.onclick=()=>{
      const key=b.dataset.color;
      const value=b.dataset.value;

      if(type==="highlight"){
        if(key==="mBorderColor")e.borderColor=value;
        if(key==="mFillColor")e.fillColor=value;
      }

      if(type==="comment"){
        if(key==="mTextColor")e.textColor=value;
        if(key==="mBg")e.bg=value;
      }

      if(type==="line"&&key==="mLineColor")l.color=value;

      markDirty();
      showMenu($("menu").offsetLeft,$("menu").offsetTop,id,type);
      render();
    };
  });

  const set=(idn,fn)=>{
    const x=$(idn);
    if(!x)return;
    x.oninput=x.onchange=()=>{
      fn(x.value);
      markDirty();
      render();
    };
  };

  if(type==="comment"){
    set("mText",v=>e.text=v);
    set("mBorder",v=>e.border=v==="1");
    set("mBorderStyle",v=>e.borderStyle=v);
    set("mBorderWidth",v=>e.borderWidth=Number(v));
    set("mFill",v=>e.fill=v==="1");
  }

  if(type==="highlight"){
    set("mBorderStyle",v=>e.borderStyle=v);
    set("mBorderWidth",v=>e.borderWidth=Number(v));
    set("mFill",v=>e.fill=v==="1");
  }

  if(type==="skip"){
    set("mStart",v=>{
      e.start=clamp(Number(v)||0,0,e.end-.1);
    });
    set("mEnd",v=>{
      e.end=clamp(Number(v)||0,e.start+.1,state.duration);
    });
  }

  if(type==="line"){
    set("mShape",v=>l.shape=v);
    set("mDash",v=>l.dash=v);
    set("mWidth",v=>l.width=Number(v));
    set("mEnd",v=>l.end=v);
    set("mFromSide",v=>l.fromSide=v);
    set("mToSide",v=>l.toSide=v);
  }

  const connect=$("connectBtn");
  if(connect){
    connect.onclick=()=>{
      if(!e)return;
      state.connecting={
        from:e.id,
        fromSide:"right",
        start:anchor(
          e,
          "right",
          $("stage").clientWidth||1,
          $("stage").clientHeight||1
        ),
        current:anchor(
          e,
          "right",
          $("stage").clientWidth||1,
          $("stage").clientHeight||1
        )
      };
      $("connecting").style.display="block";
      hideMenu();
      render();
      toast("接続先の要素へドラッグしてください");
    };
  }

  $("deleteBtn").onclick=()=>removeSelected();
}

function positionMenu(x,y){
  const m=$("menu");
  m.style.display="block";

  requestAnimationFrame(()=>{
    const mw=m.offsetWidth||260;
    const mh=m.offsetHeight||200;
    m.style.left=Math.max(8,Math.min(x,innerWidth-mw-8))+"px";
    m.style.top=Math.max(8,Math.min(y,innerHeight-mh-8))+"px";
  });
}

function hideMenu(){
  $("menu").style.display="none";
}

document.addEventListener("pointerdown",ev=>{
  if(!$("menu").contains(ev.target))hideMenu();
});

function removeSelected(){
  const ids=[...state.selected];
  if(!ids.length)return;

  state.elements=state.elements.filter(e=>!ids.includes(e.id));
  state.lines=state.lines.filter(l=>
    !ids.includes(l.from)&&
    !ids.includes(l.to)&&
    !ids.includes(l.id)
  );

  state.selected.clear();
  cancelConnection();
  markDirty();
  hideMenu();
  render();
}

function saveProject(name){
  if(state.projects.length>=10){
    toast("保存できる編集作業は10件までです。既存データを削除してください。");
    return;
  }

  state.projects.push({
    name,
    videoName:state.videoName,
    savedAt:new Date().toLocaleString("ja-JP"),
    duration:state.duration,
    elements:JSON.parse(JSON.stringify(state.elements)),
    lines:JSON.parse(JSON.stringify(state.lines))
  });

  localStorage.setItem("mockProjects",JSON.stringify(state.projects));
  state.dirty=false;
  $("title").textContent=(state.videoName||"動画")+" - 編集";
  toast("保存しました");
  renderLists();
}

$("saveBtn").onclick=()=>{
  if(!state.videoUrl)return;

  showModal(
    "編集作業を保存",
    "<input id='projectName' value='新しい編集作業'>",
    ()=>{
      const name=$("projectName").value.trim()||"編集作業";
      saveProject(name);
    }
  );
};

$("backBtn").onclick=()=>{
  if(!state.dirty){
    home();
    return;
  }

  showModal(
    "未保存の変更があります",
    "保存して終了しますか？",
    ()=>{
      saveProject("編集作業");
      home();
    },
    "保存して終了",
    "破棄して終了",
    ()=>{
      state.dirty=false;
      home();
    }
  );
};

$("exportBtn").onclick=()=>{
  if(!state.videoUrl)return;

  if(state.results.length>=10){
    toast("編集結果動画は10件までです。");
    return;
  }

  showModal(
    "編集結果を動画にする",
    "現在の編集内容を使って編集結果動画を作成します。",
    ()=>{
      state.results.push({
        name:"編集結果 "+(state.results.length+1),
        videoName:state.videoName,
        createdAt:new Date().toLocaleString("ja-JP")
      });

      localStorage.setItem("mockResults",JSON.stringify(state.results));
      toast("編集結果動画を作成しました（モック）");
      renderLists();
    },
    "作成"
  );
};

function showModal(title,body,ok,okText="確定",cancelText="キャンセル",alt){
  $("modalTitle").textContent=title;
  $("modalBody").innerHTML=body;
  $("modalOk").textContent=okText;
  $("modalCancel").textContent=cancelText;
  $("modal").style.display="flex";

  $("modalOk").onclick=()=>{
    closeModal();
    if(ok)ok();
  };

  $("modalCancel").onclick=()=>{
    closeModal();
    if(alt)alt();
  };
}

function closeModal(){
  $("modal").style.display="none";
}

function resumeProject(i){
  const p=state.projects[i];
  if(!p)return;

  if(!state.videoUrl){
    toast("元動画ファイルをもう一度選択してください");
    return;
  }

  state.videoName=p.videoName;
  state.duration=p.duration;
  state.elements=JSON.parse(JSON.stringify(p.elements||[]));
  state.lines=JSON.parse(JSON.stringify(p.lines||[]));
  state.selected.clear();
  state.dirty=false;

  $("video").load();
  openEditor();
}

function deleteProject(i){
  if(!confirm("この編集作業を削除しますか？"))return;

  state.projects.splice(i,1);
  localStorage.setItem("mockProjects",JSON.stringify(state.projects));
  renderLists();
}

function duplicateProject(i){
  if(state.projects.length>=10){
    toast("編集作業は10件までです。");
    return;
  }

  const p=JSON.parse(JSON.stringify(state.projects[i]));
  p.name=p.name+" の複製";
  p.savedAt=new Date().toLocaleString("ja-JP");

  state.projects.push(p);
  localStorage.setItem("mockProjects",JSON.stringify(state.projects));
  renderLists();
}

function useResult(i){
  const r=state.results[i];
  if(!r)return;

  toast("編集対象にする場合は元動画ファイルを選択してください");
}

window.addEventListener("resize",()=>{
  if($("editor").style.display!=="none")render();
});

renderLists();
</script>
</body>
</html>

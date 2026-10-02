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
body{margin:0;font-family:Arial,"Hiragino Kaku Gothic ProN",Meiryo,sans-serif;background:#f3f5f8;color:#202733}
button,input,select{font:inherit}
button{cursor:pointer}
.top{height:58px;background:#172033;color:#fff;display:flex;align-items:center;padding:0 18px;gap:14px}
.top h1{font-size:17px;margin:0;flex:1}
.top button{border:0;border-radius:6px;padding:8px 13px;background:#fff;color:#172033}
.top .primary{background:#3b82f6;color:#fff}
#home{padding:24px;max-width:1100px;margin:auto}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:18px;margin-bottom:18px}
.panel h2{font-size:16px;margin:0 0 14px}
.video-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.video-card{border:1px solid #d9dee7;border-radius:8px;padding:14px;background:#fff}
.video-card strong{display:block;margin-bottom:7px}
.video-card small{color:#667085}
.video-card .actions{display:flex;gap:7px;margin-top:12px}
.video-card button{border:1px solid #cfd5df;background:#fff;border-radius:5px;padding:6px 9px}
.empty{color:#667085;padding:10px 0}
#editor{display:none;height:calc(100vh - 58px);overflow:hidden}
.editor-main{height:100%;display:flex;flex-direction:column;min-width:0}
.stage-wrap{flex:1;min-height:320px;padding:14px;background:#252b36}
.stage{position:relative;width:100%;height:100%;background:#111;overflow:hidden;border-radius:7px;display:flex;align-items:center;justify-content:center}
.stage video{max-width:100%;max-height:100%;width:100%;height:100%;object-fit:contain;background:#000}
.overlay{position:absolute;inset:0;pointer-events:none}
.el{position:absolute;pointer-events:auto;user-select:none;cursor:move}
.el.selected{outline:2px solid #3b82f6;outline-offset:2px}
.comment{padding:8px 11px;white-space:pre-wrap;min-width:100px}
.highlight{border-style:solid}
.zoom{border:2px solid #a855f7;background:rgba(168,85,247,.08)}
.el .resize{position:absolute;right:-5px;bottom:-5px;width:10px;height:10px;background:#fff;border:2px solid #3b82f6;border-radius:2px;cursor:nwse-resize}
.line-layer{position:absolute;inset:0;pointer-events:none}
.line-hit{pointer-events:stroke;cursor:pointer}
.line.selected .line-visible{filter:drop-shadow(0 0 3px #fff)}
.connecting{position:absolute;left:12px;top:12px;background:#f59e0b;color:#111;padding:6px 9px;border-radius:5px;font-size:12px;z-index:20}
.bottom{height:275px;background:#fff;border-top:1px solid #ccd2dc;display:flex;flex-direction:column}
.controls{height:48px;border-bottom:1px solid #e0e4ea;display:flex;align-items:center;gap:8px;padding:6px 10px}
.controls button{border:1px solid #cbd2dc;background:#fff;border-radius:5px;padding:6px 10px}
.controls .play{background:#2563eb;color:#fff;border-color:#2563eb}
.time{font-variant-numeric:tabular-nums;min-width:100px}
.scale{display:flex;align-items:center;gap:5px;margin-left:auto}
.scale input{width:110px}
.timeline-area{position:relative;flex:1;min-height:0;overflow:hidden}
.timeline-scroll{position:absolute;inset:0;overflow:hidden}
.timeline{position:absolute;left:0;right:0;top:0;bottom:0}
.ruler{height:30px;position:absolute;left:0;right:0;top:0;border-bottom:1px solid #d7dce4;background:#fafbfc}
.tick{position:absolute;top:0;height:30px;border-left:1px solid #cfd5df;color:#667085;font-size:10px;padding-left:3px}
.tracks{position:absolute;left:0;right:0;top:30px;bottom:0;overflow:hidden}
.track-row{position:absolute;left:0;right:0;height:38px;border-bottom:1px solid #edf0f4}
.track-label{position:absolute;left:7px;top:10px;width:72px;font-size:11px;color:#667085;z-index:3}
.track-content{position:absolute;left:82px;right:0;top:0;bottom:0}
.block{position:absolute;height:27px;top:5px;border-radius:4px;color:#fff;font-size:11px;padding:6px 13px;cursor:grab;overflow:hidden;white-space:nowrap}
.block.selected{box-shadow:0 0 0 2px #2563eb inset}
.block.skip{background:#64748b}
.block.comment{background:#2563eb}
.block.highlight{background:#f97316}
.block.zoom{background:#9333ea}
.handle{position:absolute;top:0;bottom:0;width:7px;cursor:ew-resize}
.handle.l{left:0}
.handle.r{right:0}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#ef4444;z-index:30;pointer-events:none}
.playhead:before{content:"";position:absolute;top:0;left:-5px;border-left:6px solid transparent;border-right:6px solid transparent;border-top:8px solid #ef4444}
.context{position:fixed;display:none;z-index:1000;background:#fff;border:1px solid #cfd5df;border-radius:7px;box-shadow:0 8px 25px rgba(0,0,0,.18);min-width:190px;padding:5px}
.context button{display:block;width:100%;text-align:left;background:#fff;border:0;padding:8px 10px;border-radius:4px}
.context button:hover{background:#eef4ff}
.menu-title{font-size:11px;color:#667085;padding:6px 10px;border-bottom:1px solid #edf0f4}
.menu-row{padding:7px 10px;font-size:12px}
.menu-row label{display:block;margin-bottom:4px;color:#475467}
.menu-row input[type=text],.menu-row input[type=number],.menu-row select{width:100%;padding:5px;border:1px solid #cbd2dc;border-radius:4px;background:#fff}
.colors{display:flex;flex-wrap:wrap;gap:5px}
.color{width:21px;height:21px;border-radius:4px;border:2px solid #fff;box-shadow:0 0 0 1px #b8bec8;cursor:pointer}
.sep{height:1px;background:#e7eaf0;margin:4px 0}
.notice{position:fixed;right:15px;bottom:15px;background:#172033;color:#fff;border-radius:6px;padding:10px 14px;display:none;z-index:2000}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1500}
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
        <div id="connecting" class="connecting" style="display:none">接続元を選択中。接続先の要素へドラッグしてください。</div>
      </div>
    </div>

    <div class="bottom">
      <div class="controls">
        <button id="playBtn" class="play">▶ 再生</button>
        <button id="pauseBtn">Ⅱ 一時停止</button>
        <span id="time" class="time">0:00 / 0:00</span>
        <button id="zoomOut">－</button>
        <button id="zoomIn">＋</button>
        <div class="scale">スケール <input id="scale" type="range" min="50" max="200" value="100"></div>
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
const $=id=>document.getElementById(id);
const state={
  duration:60,
  scale:1,
  videoUrl:"",
  videoName:"",
  selected:new Set(),
  contextId:null,
  contextType:null,
  connectingFrom:null,
  elements:[],
  lines:[],
  projects:JSON.parse(localStorage.getItem("mockProjects")||"[]"),
  results:JSON.parse(localStorage.getItem("mockResults")||"[]"),
  dirty:false,
  nextId:1
};
const colors=["#ef4444","#f97316","#f59e0b","#eab308","#84cc16","#22c55e","#14b8a6","#06b6d4","#3b82f6","#6366f1","#8b5cf6","#ec4899"];

function uid(){return "e"+(state.nextId++);}
function esc(s){return String(s).replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[m]));}
function fmt(t){t=Math.max(0,Number(t)||0);return Math.floor(t/60)+":"+String(Math.floor(t%60)).padStart(2,"0");}
function markDirty(){state.dirty=true;$("title").textContent="動画編集 *";}
function toast(s){$("notice").textContent=s;$("notice").style.display="block";setTimeout(()=>$("notice").style.display="none",1800);}
function clamp(v,a,b){return Math.max(a,Math.min(b,v));}
function active(id){
  return state.elements.find(e=>e.id===id);
}
function activeLine(id){
  return state.lines.find(l=>l.id===id);
}

function home(){
  $("home").style.display="block";
  $("editor").style.display="none";
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
    const card=document.createElement("div");
    card.className="video-card";
    card.innerHTML="<strong>動画未選択</strong><small>上の「動画を選択」から動画ファイルを指定してください。</small>";
    list.appendChild(card);
  }else{
    const card=document.createElement("div");
    card.className="video-card";
    card.innerHTML="<strong>"+esc(state.videoName)+"</strong><small>"+fmt(state.duration)+" / オリジナル動画</small><div class='actions'><button id='openCurrent'>編集する</button></div>";
    list.appendChild(card);
    $("openCurrent").onclick=openEditor;
  }
  $("projectList").innerHTML=state.projects.length?state.projects.map((p,i)=>`
    <div class="video-card">
      <strong>${esc(p.name)}</strong>
      <small>${esc(p.videoName)} / ${esc(p.savedAt)}</small>
      <div class="actions">
        <button onclick="resumeProject(${i})">再開</button>
        <button onclick="deleteProject(${i})">削除</button>
        <button onclick="duplicateProject(${i})">複製</button>
      </div>
    </div>`).join(""):"<div class='empty'>保存済み編集作業はありません。</div>";
  $("resultList").innerHTML=state.results.length?state.results.map((r,i)=>`
    <div class="video-card">
      <strong>${esc(r.name)}</strong>
      <small>編集結果動画 / 元動画: ${esc(r.videoName)}</small>
      <div class="actions"><button onclick="useResult(${i})">編集対象にする</button></div>
    </div>`).join(""):"<div class='empty'>編集結果動画はありません。</div>";
}

$("videoFile").onchange=e=>{
  const f=e.target.files[0];
  if(!f)return;
  state.videoUrl=URL.createObjectURL(f);
  state.videoName=f.name;
  $("video").src=state.videoUrl;
  $("video").onloadedmetadata=()=>{
    state.duration=$("video").duration||60;
    home();
    toast("動画を選択しました");
  };
};

$("playBtn").onclick=()=>{$("video").play();};
$("pauseBtn").onclick=()=>{$("video").pause();};
$("video").ontimeupdate=()=>{
  const v=$("video");
  skipIfNeeded();
  updatePosition();
};
$("video").onended=()=>updatePosition();
$("scale").oninput=e=>{
  state.scale=Number(e.target.value)/100;
  renderTimeline();
};
$("zoomIn").onclick=()=>{$("scale").value=Math.min(200,Number($("scale").value)+10);$("scale").oninput();};
$("zoomOut").onclick=()=>{$("scale").value=Math.max(50,Number($("scale").value)-10);$("scale").oninput();};

function skipIfNeeded(){
  const v=$("video");
  if(v.paused||v.ended)return;
  const s=state.elements.find(e=>e.type==="skip"&&v.currentTime>=e.start&&v.currentTime<e.end);
  if(s){
    v.currentTime=Math.min(s.end+0.03,state.duration);
  }
}
function updatePosition(){
  const v=$("video");
  $("time").textContent=fmt(v.currentTime)+" / "+fmt(state.duration);
  const area=$("timelineArea");
  const w=area.clientWidth||1;
  const x=(v.currentTime/state.duration)*w;
  $("playhead").style.left=x+"px";
  renderVisible();
}

function render(){
  if(!$("stage")||!$("timelineArea"))return;
  renderVideo();
  renderTimeline();
  updatePosition();
}
function renderVideo(){
  const overlay=$("overlay");
  overlay.innerHTML="";
  const v=$("video");
  const visible=state.elements.filter(e=>e.type!=="skip"&&v.currentTime>=e.start&&v.currentTime<=e.end);
  visible.forEach(e=>{
    const d=document.createElement("div");
    d.className="el "+e.type+(state.selected.has(e.id)?" selected":"");
    d.dataset.id=e.id;
    d.style.left=e.x+"%";
    d.style.top=e.y+"%";
    d.style.width=e.w+"%";
    d.style.height=e.h+"%";
    if(e.type==="comment"){
      d.textContent=e.text;
      d.style.color=e.textColor;
      d.style.background=e.fill?e.bg:"transparent";
      d.style.border=e.border?e.borderWidth+"px "+e.borderStyle+" "+e.borderColor:"none";
      d.style.fontSize=e.fontSize+"px";
      d.style.fontFamily=e.font;
    }
    if(e.type==="highlight"){
      d.style.borderWidth=e.borderWidth+"px";
      d.style.borderColor=e.borderColor;
      d.style.borderStyle=e.borderStyle;
      d.style.background=e.fill?e.fillColor:"transparent";
    }
    if(e.type==="zoom"){
      d.style.borderColor="#a855f7";
      d.style.background="rgba(168,85,247,.08)";
      d.innerHTML="<span style='background:#9333ea;color:#fff;padding:3px 5px;font-size:11px'>拡大枠</span>";
    }
    if(state.selected.has(e.id)){
      const h=document.createElement("div");
      h.className="resize";
      h.onpointerdown=ev=>startResize(ev,e);
      d.appendChild(h);
    }
    d.onpointerdown=ev=>startMove(ev,e);
    d.onclick=ev=>{
      ev.stopPropagation();
      select(e.id,ev.shiftKey);
    };
    d.oncontextmenu=ev=>{
      ev.preventDefault();ev.stopPropagation();
      select(e.id,false);
      showMenu(ev.clientX,ev.clientY,e.id,e.type);
    };
    d.ondblclick=ev=>{
      ev.stopPropagation();
      if(e.type==="zoom")toast("拡大対象範囲を確認できます");
    };
    overlay.appendChild(d);
  });
  renderLines();
}

function renderLines(){
  const svg=$("lineLayer");
  svg.innerHTML="";
  svg.setAttribute("viewBox","0 0",);
  const W=$("stage").clientWidth||1,H=$("stage").clientHeight||1;
  svg.setAttribute("viewBox","0 0 "+W+" "+H);
  state.lines.forEach(l=>{
    const a=active(l.from),b=active(l.to);
    if(!a||!b)return;
    const p1=anchor(a,l.fromSide||"right",W,H);
    const p2=anchor(b,l.toSide||"left",W,H);
    const pts=route(l,p1,p2,W,H);
    const hit=document.createElementNS("http://www.w3.org/2000/svg","path");
    hit.setAttribute("d",pathD(pts,l.shape));
    hit.setAttribute("fill","none");
    hit.setAttribute("stroke","transparent");
    hit.setAttribute("stroke-width","14");
    hit.setAttribute("class","line-hit");
    hit.dataset.line=l.id;
    hit.onpointerdown=ev=>{
      ev.stopPropagation();
      selectLine(l.id,ev.shiftKey);
    };
    hit.oncontextmenu=ev=>{
      ev.preventDefault();ev.stopPropagation();
      selectLine(l.id,false);
      showMenu(ev.clientX,ev.clientY,l.id,"line");
    };
    const visible=document.createElementNS("http://www.w3.org/2000/svg","path");
    visible.setAttribute("d",pathD(pts,l.shape));
    visible.setAttribute("fill","none");
    visible.setAttribute("stroke",l.color);
    visible.setAttribute("stroke-width",l.width);
    visible.setAttribute("stroke-linecap","round");
    visible.setAttribute("stroke-linejoin","round");
    visible.setAttribute("class","line-visible");
    if(l.dash)visible.setAttribute("stroke-dasharray",l.dash);
    if(l.end!=="none")visible.setAttribute("marker-end","url(#"+markerId(l.end,l.color)+")");
    const g=document.createElementNS("http://www.w3.org/2000/svg","g");
    g.classList.add("line");
    if(state.selected.has(l.id))g.classList.add("selected");
    g.appendChild(visible);g.appendChild(hit);svg.appendChild(g);
  });
  const defs=document.createElementNS("http://www.w3.org/2000/svg","defs");
  ["arrow","circle"].forEach(kind=>{
    colors.slice(0,1).forEach(c=>{
      const m=document.createElementNS("http://www.w3.org/2000/svg","marker");
      m.id=markerId(kind,c);m.markerWidth="8";m.markerHeight="8";m.refX="7";m.refY="4";m.orient="auto";
      const p=document.createElementNS("http://www.w3.org/2000/svg",kind==="arrow"?"path":"circle");
      if(kind==="arrow"){p.setAttribute("d","M0,0 L8,4 L0,8 z");}
      else{p.setAttribute("cx","4");p.setAttribute("cy","4");p.setAttribute("r","3");}
      p.setAttribute("fill",c);m.appendChild(p);defs.appendChild(m);
    });
  });
  svg.prepend(defs);
}
function markerId(k,c){return "mk"+k+c.replace("#","");}
function anchor(e,side,W,H){
  const x=e.x/100*W,y=e.y/100*H,w=e.w/100*W,h=e.h/100*H;
  if(side==="left")return{x,y:y+h/2};
  if(side==="top")return{x:x+w/2,y};
  if(side==="bottom")return{x:x+w/2,y:y+h};
  return{x:x+w,y:y+h/2};
}
function route(l,a,b,W,H){
  if(l.shape==="straight")return[a,b];
  const pad=24;
  let p=[a];
  if(l.shape==="elbow"){
    const midX=(a.x+b.x)/2;
    p=[a,{x:midX,y:a.y},{x:midX,y:b.y},b];
    return avoid(p,l,W,H);
  }
  const dx=(b.x-a.x)/3;
  for(let i=1;i<6;i++){
    const t=i/6;
    p.push({x:a.x+(b.x-a.x)*t,y:a.y+(b.y-a.y)*t+Math.sin(t*Math.PI*2)*18});
  }
  p.push(b);
  return p;
}
function avoid(points,line,W,H){
  const others=state.elements.filter(e=>e.id!==line.from&&e.id!==line.to&&e.type!=="skip");
  const out=[points[0]];
  for(let i=1;i<points.length-1;i++){
    let p=points[i];
    others.forEach(e=>{
      const x=e.x/100*W,y=e.y/100*H,w=e.w/100*W,h=e.h/100*H;
      if(p.x>x-5&&p.x<x+w+5&&p.y>y-5&&p.y<y+h+5)p={x:p.x+Math.max(25,w/2),y:p.y};
    });
    out.push(p);
  }
  out.push(points[points.length-1]);
  return out;
}
function pathD(points,shape){
  if(shape==="wavy"){
    let d="M"+points[0].x+" "+points[0].y;
    for(let i=1;i<points.length;i++){
      const a=points[i-1],b=points[i],dx=b.x-a.x,dy=b.y-a.y,len=Math.hypot(dx,dy)||1;
      const nx=-dy/len*6,ny=dx/len*6;
      const m1={x:a.x+dx/3+nx,y:a.y+dy/3+ny};
      const m2={x:a.x+dx*2/3-nx,y:a.y+dy*2/3-ny};
      d+=" C"+m1.x+" "+m1.y+" "+m2.x+" "+m2.y+" "+b.x+" "+b.y;
    }
    return d;
  }
  return points.map((p,i)=>(i?"L":"M")+p.x+" "+p.y).join(" ");
}

function renderTimeline(){
  const area=$("timelineArea");
  if(!area)return;
  const w=area.clientWidth||600;
  const dur=Math.max(1,state.duration);
  $("ruler").innerHTML="";
  const interval=dur<=30?5:dur<=120?10:dur<=300?30:60;
  for(let t=0;t<=dur;t+=interval){
    const x=t/dur*w;
    const tick=document.createElement("div");
    tick.className="tick";tick.style.left=x+"px";tick.textContent=fmt(t);
    $("ruler").appendChild(tick);
  }
  $("tracks").innerHTML="";
  const active=state.elements.slice().sort((a,b)=>a.start-b.start);
  const rows=[];
  active.forEach(e=>{
    let row=0;
    while(rows[row]&&rows[row].some(x=>overlap(e,x)))row++;
    if(!rows[row])rows[row]=[];
    rows[row].push(e);
    e._row=row;
  });
  const h=Math.max(1,rows.length)*38;
  $("tracks").style.height=h+"px";
  rows.forEach((r,i)=>{
    const row=document.createElement("div");
    row.className="track-row";row.style.top=i*38+"px";
    row.innerHTML="<div class='track-label'>"+(i+1)+"</div><div class='track-content'></div>";
    $("tracks").appendChild(row);
  });
  active.forEach(e=>{
    const row=$("tracks").children[e._row];
    if(!row)return;
    const content=row.querySelector(".track-content");
    const b=document.createElement("div");
    b.className="block "+e.type+(state.selected.has(e.id)?" selected":"");
    b.dataset.id=e.id;
    const left=e.start/dur*w;
    const width=Math.max(12,(e.end-e.start)/dur*w);
    b.style.left=left+"px";b.style.width=width+"px";
    b.textContent=e.type==="comment"?e.text:(e.type==="highlight"?"強調枠":e.type==="zoom"?"拡大枠":"スキップ");
    b.onpointerdown=ev=>startBlock(ev,e,b);
    b.onclick=ev=>{ev.stopPropagation();select(e.id,ev.shiftKey);};
    b.oncontextmenu=ev=>{
      ev.preventDefault();ev.stopPropagation();
      select(e.id,false);showMenu(ev.clientX,ev.clientY,e.id,e.type);
    };
    const l=document.createElement("i"),r=document.createElement("i");
    l.className="handle l";r.className="handle r";
    l.onpointerdown=ev=>startTimeResize(ev,e,"start");
    r.onpointerdown=ev=>startTimeResize(ev,e,"end");
    b.append(l,r);content.appendChild(b);
  });
}
function overlap(a,b){return a.start<b.end&&a.end>b.start;}
function renderVisible(){renderVideo();renderTimeline();}

function select(id,multi){
  if(!multi)state.selected.clear();
  state.selected.add(id);
  hideMenu();render();
}
function selectLine(id,multi){select(id,multi);}
$("stage").onclick=()=>{state.selected.clear();state.connectingFrom=null;$("connecting").style.display="none";hideMenu();render();};
$("timelineArea").onclick=ev=>{
  if(ev.target.closest(".block"))return;
  seekFromTimeline(ev.clientX);
};
function seekFromTimeline(clientX){
  const r=$("timelineArea").getBoundingClientRect();
  const t=clamp((clientX-r.left)/r.width*state.duration,0,state.duration);
  $("video").currentTime=t;
  updatePosition();
}

function startMove(ev,e){
  if(ev.button!==0)return;
  if(ev.target.classList.contains("resize"))return;
  if(ev.shiftKey)select(e.id,true);else select(e.id,false);
  if(state.connectingFrom&&state.connectingFrom!==e.id){
    createLine(state.connectingFrom,e.id);
    state.connectingFrom=null;
    $("connecting").style.display="none";
    render();
    return;
  }
  const stage=$("stage"),rect=stage.getBoundingClientRect();
  const sx=ev.clientX,sy=ev.clientY,ox=e.x,oy=e.y;
  const move=x=>{
    e.x=clamp(ox+(x.clientX-sx)/rect.width*100,0,100-e.w);
    e.y=clamp(oy+(x.clientY-sy)/rect.height*100,0,100-e.h);
    markDirty();render();
  };
  const up=()=>{document.removeEventListener("pointermove",move);document.removeEventListener("pointerup",up);};
  document.addEventListener("pointermove",move);document.addEventListener("pointerup",up);
}
function startResize(ev,e){
  ev.stopPropagation();
  const rect=$("stage").getBoundingClientRect(),sx=ev.clientX,sy=ev.clientY,ow=e.w,oh=e.h,ox=e.x,oy=e.y;
  const move=x=>{
    e.w=clamp(ow+(x.clientX-sx)/rect.width*100,5,100-ox);
    e.h=clamp(oh+(x.clientY-sy)/rect.height*100,5,100-oy);
    markDirty();render();
  };
  const up=()=>{document.removeEventListener("pointermove",move);document.removeEventListener("pointerup",up);};
  document.addEventListener("pointermove",move);document.addEventListener("pointerup",up);
}
function startBlock(ev,e,b){
  if(ev.target.classList.contains("handle"))return;
  ev.stopPropagation();
  select(e.id,ev.shiftKey);
  const area=$("timelineArea"),r=area.getBoundingClientRect(),sx=ev.clientX,os=e.start,oe=e.end;
  const move=x=>{
    const dt=(x.clientX-sx)/r.width*state.duration;
    const len=oe-os;
    e.start=clamp(os+dt,0,state.duration-len);
    e.end=e.start+len;
    markDirty();renderTimeline();renderVideo();
  };
  const up=()=>{document.removeEventListener("pointermove",move);document.removeEventListener("pointerup",up);};
  document.addEventListener("pointermove",move);document.addEventListener("pointerup",up);
}
function startTimeResize(ev,e,which){
  ev.stopPropagation();
  const r=$("timelineArea").getBoundingClientRect(),sx=ev.clientX,os=e.start,oe=e.end;
  const move=x=>{
    const dt=(x.clientX-sx)/r.width*state.duration;
    if(which==="start")e.start=clamp(os+dt,0,e.end-.1);
    else e.end=clamp(oe+dt,e.start+.1,state.duration);
    markDirty();renderTimeline();renderVideo();
  };
  const up=()=>{document.removeEventListener("pointermove",move);document.removeEventListener("pointerup",up);};
  document.addEventListener("pointermove",move);document.addEventListener("pointerup",up);
}

function createElement(type){
  const now=clamp($("video").currentTime||0,0,state.duration);
  const end=Math.min(state.duration,now+8);
  const e={
    id:uid(),type,start:now,end:Math.max(now+.5,end),
    x:12,y:15,w:type==="comment"?28:25,h:type==="comment"?15:25
  };
  if(type==="comment")Object.assign(e,{text:"コメント",border:true,borderWidth:2,borderStyle:"solid",borderColor:"#2563eb",textColor:"#111827",bg:"#ffffff",fill:true,fontSize:18,font:"Arial"});
  if(type==="highlight")Object.assign(e,{borderWidth:3,borderStyle:"solid",borderColor:"#ef4444",fill:true,fillColor:"rgba(239,68,68,.20)"});
  if(type==="zoom")Object.assign(e,{});
  if(type==="skip"){e.x=0;e.y=0;e.w=0;e.h=0;}
  state.elements.push(e);
  state.selected.clear();state.selected.add(e.id);markDirty();hideMenu();render();
}
function createLine(from,to){
  if(from===to)return;
  if(state.lines.some(l=>l.from===from&&l.to===to))return;
  state.lines.push({id:"l"+Date.now()+Math.random(),from,to,shape:"straight",dash:"",width:3,color:"#111827",end:"none",fromSide:"right",toSide:"left"});
  markDirty();toast("接続線を作成しました");
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
  m.innerHTML="<div class='menu-title'>ここへ追加</div>"+
    ["comment:コメント","highlight:強調枠","zoom:拡大枠","skip:スキップ"].map(v=>{
      const [a,b]=v.split(":");
      return "<button data-add='"+a+"'>"+b+"</button>";
    }).join("");
  m.querySelectorAll("[data-add]").forEach(b=>b.onclick=()=>createElement(b.dataset.add));
  positionMenu(x,y);
}
function showMenu(x,y,id,type){
  const m=$("menu");
  const e=active(id),l=activeLine(id);
  let html="<div class='menu-title'>"+label(type)+"</div>";
  if(type==="line")html+=lineMenu(l);
  else if(type==="comment")html+=commentMenu(e);
  else if(type==="highlight")html+=highlightMenu(e);
  else if(type==="zoom")html+=zoomMenu(e);
  else if(type==="skip")html+=skipMenu(e);
  html+="<div class='sep'></div><button id='connectBtn'>この要素から接続</button><button id='deleteBtn'>削除</button>";
  m.innerHTML=html;
  bindMenu(type,id);
  positionMenu(x,y);
}
function label(t){return{comment:"コメント",highlight:"強調枠",zoom:"拡大枠",skip:"スキップ",line:"接続線"}[t]||t;}
function commentMenu(e){
  return `<div class="menu-row"><label>本文</label><input id="mText" type="text" value="${esc(e.text)}"></div>
  <div class="menu-row"><label>線</label><select id="mBorder"><option value="1" ${e.border?"selected":""}>あり</option><option value="0" ${!e.border?"selected":""}>なし</option></select></div>
  <div class="menu-row"><label>線種</label><select id="mBorderStyle">${styles(e.borderStyle)}</select></div>
  <div class="menu-row"><label>線の太さ</label><select id="mBorderWidth"><option>1</option><option ${e.borderWidth==2?"selected":""}>2</option><option ${e.borderWidth==3?"selected":""}>3</option><option ${e.borderWidth==4?"selected":""}>4</option></select></div>
  <div class="menu-row"><label>文字色</label><div class="colors">${palette("mTextColor",e.textColor)}</div></div>
  <div class="menu-row"><label>背景色</label><div class="colors">${palette("mBg",e.bg)}</div></div>
  <div class="menu-row"><label>塗り潰し</label><select id="mFill"><option value="1" ${e.fill?"selected":""}>あり</option><option value="0" ${!e.fill?"selected":""}>なし</option></select></div>`;
}
function highlightMenu(e){
  return `<div class="menu-row"><label>線種</label><select id="mBorderStyle">${styles(e.borderStyle)}</select></div>
  <div class="menu-row"><label>線の太さ</label><select id="mBorderWidth"><option>1</option><option ${e.borderWidth==2?"selected":""}>2</option><option ${e.borderWidth==3?"selected":""}>3</option><option ${e.borderWidth==4?"selected":""}>4</option></select></div>
  <div class="menu-row"><label>線色</label><div class="colors">${palette("mBorderColor",e.borderColor)}</div></div>
  <div class="menu-row"><label>塗り潰し色</label><div class="colors">${palette("mFillColor",e.fillColor)}</div></div>
  <div class="menu-row"><label>塗り潰し</label><select id="mFill"><option value="1" ${e.fill?"selected":""}>あり</option><option value="0" ${!e.fill?"selected":""}>なし</option></select></div>`;
}
function zoomMenu(e){return `<div class="menu-row">指定範囲を拡大表示する枠です。</div>`;}
function skipMenu(e){return `<div class="menu-row"><label>開始</label><input id="mStart" type="number" step=".1" value="${e.start.toFixed(1)}"></div><div class="menu-row"><label>終了</label><input id="mEnd" type="number" step=".1" value="${e.end.toFixed(1)}"></div>`;}
function lineMenu(l){
  return `<div class="menu-row"><label>線形</label><select id="mShape"><option value="straight" ${l.shape==="straight"?"selected":""}>直線</option><option value="elbow" ${l.shape==="elbow"?"selected":""}>折れ線</option><option value="wavy" ${l.shape==="wavy"?"selected":""}>波線</option></select></div>
  <div class="menu-row"><label>線種</label><select id="mDash"><option value="" ${!l.dash?"selected":""}>実線</option><option value="2 5" ${l.dash==="2 5"?"selected":""}>点線</option><option value="9 5" ${l.dash==="9 5"?"selected":""}>破線</option><option value="12 5 2 5" ${l.dash==="12 5 2 5"?"selected":""}>一点鎖線</option></select></div>
  <div class="menu-row"><label>太さ</label><select id="mWidth"><option>2</option><option ${l.width==3?"selected":""}>3</option><option ${l.width==4?"selected":""}>4</option><option ${l.width==6?"selected":""}>6</option></select></div>
  <div class="menu-row"><label>色</label><div class="colors">${palette("mLineColor",l.color)}</div></div>
  <div class="menu-row"><label>終端</label><select id="mEnd"><option value="none" ${l.end==="none"?"selected":""}>なし</option><option value="arrow" ${l.end==="arrow"?"selected":""}>矢印</option><option value="circle" ${l.end==="circle"?"selected":""}>丸</option></select></div>
  <div class="menu-row"><label>始点位置</label><select id="mFromSide">${sides(l.fromSide)}</select></div>
  <div class="menu-row"><label>終点位置</label><select id="mToSide">${sides(l.toSide)}</select></div>`;
}
function styles(v){return["solid:実線","dotted:点線","dashed:破線","dashdot:一点鎖線"].map(x=>{let [a,b]=x.split(":");return`<option value="${a}" ${v===a?"selected":""}>${b}</option>`}).join("");}
function sides(v){return["left:左","right:右","top:上","bottom:下"].map(x=>{let[a,b]=x.split(":");return`<option value="${a}" ${v===a?"selected":""}>${b}</option>`}).join("");}
function palette(id,current){
  return colors.map(c=>`<button class="color" data-color="${id}" data-value="${c}" style="background:${c};${current===c?"outline:2px solid #111;outline-offset:1px":""}" title="${c}"></button>`).join("");
}
function bindMenu(type,id){
  const e=active(id),l=activeLine(id);
  $("menu").querySelectorAll("[data-color]").forEach(b=>b.onclick=()=>{
    const v=b.dataset.value,n=b.dataset.color;
    if(type==="highlight"&&n==="mFillColor")e.fillColor=v;
    else if(type==="highlight")e.borderColor=v;
    else if(type==="comment"&&n==="mTextColor")e.textColor=v;
    else if(type==="comment")e.bg=v;
    else if(type==="line")l.color=v;
    markDirty();render();showMenu($("menu").offsetLeft,$("menu").offsetTop,id,type);
  });
  const set=(idn,fn)=>{
    const x=$(idn);if(x)x.oninput=x.onchange=()=>{fn(x.value);markDirty();render();};
  };
  if(type==="comment"){
    set("mText",v=>e.text=v);set("mBorder",v=>e.border=v==="1");set("mBorderStyle",v=>e.borderStyle=v);set("mBorderWidth",v=>e.borderWidth=Number(v));set("mFill",v=>e.fill=v==="1");
  }
  if(type==="highlight"){
    set("mBorderStyle",v=>e.borderStyle=v);set("mBorderWidth",v=>e.borderWidth=Number(v));set("mFill",v=>e.fill=v==="1");
  }
  if(type==="skip"){
    set("mStart",v=>e.start=clamp(Number(v)||0,0,e.end-.1));set("mEnd",v=>e.end=clamp(Number(v)||0,e.start+.1,state.duration));
  }
  if(type==="line"){
    set("mShape",v=>l.shape=v);set("mDash",v=>l.dash=v);set("mWidth",v=>l.width=Number(v));set("mEnd",v=>l.end=v);set("mFromSide",v=>l.fromSide=v);set("mToSide",v=>l.toSide=v);
  }
  $("connectBtn").onclick=()=>{
    state.connectingFrom=id;$("connecting").style.display="block";hideMenu();toast("接続先の要素へドラッグしてください");
  };
  $("deleteBtn").onclick=()=>removeSelected();
}
function positionMenu(x,y){
  const m=$("menu");m.style.display="block";
  const mw=210,mh=m.offsetHeight;
  m.style.left=Math.min(x,innerWidth-mw-8)+"px";
  m.style.top=Math.min(y,innerHeight-mh-8)+"px";
}
function hideMenu(){$("menu").style.display="none";}
document.addEventListener("pointerdown",ev=>{if(!$("menu").contains(ev.target))hideMenu();});

function removeSelected(){
  const ids=[...state.selected];
  if(!ids.length)return;
  state.elements=state.elements.filter(e=>!ids.includes(e.id));
  state.lines=state.lines.filter(l=>!ids.includes(l.from)&&!ids.includes(l.to)&&!ids.includes(l.id));
  state.selected.clear();markDirty();hideMenu();render();
}

function saveProject(name){
  if(state.projects.length>=10){toast("保存できる編集作業は10件までです。既存データを削除してください。");return;}
  state.projects.push({name,videoName:state.videoName,savedAt:new Date().toLocaleString("ja-JP"),duration:state.duration,elements:JSON.parse(JSON.stringify(state.elements)),lines:JSON.parse(JSON.stringify(state.lines))});
  localStorage.setItem("mockProjects",JSON.stringify(state.projects));
  state.dirty=false;$("title").textContent=(state.videoName||"動画")+" - 編集";
  toast("保存しました");renderLists();
}
$("saveBtn").onclick=()=>{
  if(!state.videoUrl)return;
  showModal("編集作業を保存","<input id='projectName' value='新しい編集作業'>",()=>{
    const n=$("projectName").value.trim()||"編集作業";
    saveProject(n);
  });
};
$("backBtn").onclick=()=>{
  if(state.dirty){
    showModal("未保存の変更があります","保存して終了しますか？",()=>{
      saveProject("編集作業");
      home();
    },"保存して終了","破棄して終了",()=>{state.dirty=false;home();});
  }else home();
};
$("exportBtn").onclick=()=>{
  if(!state.videoUrl)return;
  if(state.results.length>=10){toast("編集結果動画は10件までです。");return;}
  showModal("編集結果を動画にする","現在の編集内容を使って編集結果動画を作成します。",()=>{
    state.results.push({name:"編集結果 "+(state.results.length+1),videoName:state.videoName,createdAt:new Date().toLocaleString("ja-JP")});
    localStorage.setItem("mockResults",JSON.stringify(state.results));
    toast("編集結果動画を作成しました（モック）");renderLists();
  },"作成");
};

function showModal(title,body,ok,okText="確定",cancelText="キャンセル",alt){
  $("modalTitle").textContent=title;$("modalBody").innerHTML=body;
  $("modalOk").textContent=okText;$("modalCancel").textContent=cancelText;
  $("modal").style.display="flex";
  $("modalOk").onclick=()=>{closeModal();ok&&ok();};
  $("modalCancel").onclick=()=>{closeModal();alt&&alt();};
}
function closeModal(){$("modal").style.display="none";}
function resumeProject(i){
  const p=state.projects[i];
  state.videoName=p.videoName;state.duration=p.duration;
  state.elements=JSON.parse(JSON.stringify(p.elements));state.lines=JSON.parse(JSON.stringify(p.lines));
  state.videoUrl=state.videoUrl||"";
  if(!state.videoUrl){toast("元動画ファイルをもう一度選択してください");return;}
  openEditor();
}
function deleteProject(i){
  if(!confirm("この編集作業を削除しますか？"))return;
  state.projects.splice(i,1);localStorage.setItem("mockProjects",JSON.stringify(state.projects));renderLists();
}
function duplicateProject(i){
  if(state.projects.length>=10){toast("編集作業は10件までです。");return;}
  const p=JSON.parse(JSON.stringify(state.projects[i]));p.name=p.name+" の複製";p.savedAt=new Date().toLocaleString("ja-JP");
  state.projects.push(p);localStorage.setItem("mockProjects",JSON.stringify(state.projects));renderLists();
}
function useResult(i){
  const r=state.results[i];
  state.videoName=r.name;state.videoUrl="";
  $("video").removeAttribute("src");
  $("videoFile").value="";
  toast("編集対象にする場合は元動画ファイルを選択してください");
}

window.addEventListener("resize",render);
renderLists();
</script>
</body>
</html>

<?php
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f4f6;color:#222;font:14px Arial,sans-serif}
button,input,select{font:inherit}
button{padding:7px 11px;border:1px solid #aaa;border-radius:4px;background:#fff;cursor:pointer}
button.primary{background:#2563eb;color:#fff;border-color:#2563eb}
button.danger{color:#b91c1c}
.hidden{display:none!important}
.wrap{max-width:1100px;margin:auto;padding:14px}
.card{background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:12px;margin-bottom:12px}
.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.grow{flex:1}
.muted{color:#6b7280}
.top{background:#1f2937;color:#fff;padding:9px 12px;border-radius:5px;margin-bottom:12px}
.list{display:grid;gap:8px}
.item{border:1px solid #d1d5db;padding:10px;border-radius:5px;background:#fff}
.item .meta{color:#6b7280;font-size:12px;margin-top:3px}

.videoBox{max-width:850px;margin:auto}
.video{height:430px;background:#111;position:relative;overflow:hidden;user-select:none;touch-action:none}
.video>video{width:100%;height:100%;object-fit:contain;display:block;pointer-events:none}
#layer{position:absolute;inset:0}
#svg{
 position:absolute;
 inset:0;
 width:100%;
 height:100%;
 z-index:5;
 overflow:visible;
 pointer-events:none
}
.obj{
 position:absolute;
 z-index:10;
 cursor:grab;
 user-select:none;
 touch-action:none
}
.obj.selected{outline:2px dashed #f59e0b}
.obj.source{outline:3px solid #22c55e}
.obj.target{outline:3px dashed #38bdf8}
.obj.connecting{opacity:.9}
.obj:active{cursor:grabbing}

.port{
 position:absolute;
 width:14px;
 height:14px;
 border-radius:50%;
 background:#16a34a;
 border:2px solid #fff;
 z-index:30;
 cursor:crosshair;
 box-shadow:0 1px 4px #0008
}
.obj.selected .port{display:block}
.port.t{left:50%;top:-7px;transform:translateX(-50%)}
.port.r{right:-7px;top:50%;transform:translateY(-50%)}
.port.b{left:50%;bottom:-7px;transform:translateX(-50%)}
.port.l{left:-7px;top:50%;transform:translateY(-50%)}

.resize{
 position:absolute;
 right:-5px;
 bottom:-5px;
 width:12px;
 height:12px;
 background:#f59e0b;
 cursor:nwse-resize;
 z-index:35
}

.line{fill:none;stroke-linecap:round;stroke-linejoin:round}
.temp{fill:none;stroke:#22c55e;stroke-width:3;stroke-dasharray:6 5}
.lineHit{fill:none;stroke:transparent;stroke-width:18}

.timeline{border:1px solid #9ca3af;border-radius:5px;background:#fff;overflow:hidden}
.tViewport{height:245px;overflow-x:hidden;overflow-y:auto}
.tInner{position:relative;width:100%;height:100%}
.ruler{height:34px;border-bottom:1px solid #aaa;position:relative}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #aaa;padding:4px;font-size:10px}
.lanes{position:relative;min-height:190px}
.clip{
 position:absolute;
 height:28px;
 border-radius:4px;
 color:#fff;
 padding:6px 12px;
 white-space:nowrap;
 cursor:grab;
 font-size:11px;
 overflow:visible;
 user-select:none
}
.clip.selected{outline:2px solid #f59e0b}
.clip.skip{background:#6b7280!important}
.ch{position:absolute;top:0;bottom:0;width:8px;background:#ffffff77;cursor:ew-resize}
.ch.l{left:0}.ch.r{right:0}
.playhead{
 position:absolute;
 top:0;
 bottom:0;
 width:2px;
 background:#e11d48;
 z-index:30;
 pointer-events:none
}
.playhead:before{
 content:"";
 position:absolute;
 left:-5px;
 top:0;
 width:12px;
 height:12px;
 border-radius:50%;
 background:#e11d48
}

.scaleWrap{display:flex;align-items:center;gap:6px}
.scaleWrap input{width:150px}
.menu{
 position:fixed;
 z-index:1000;
 width:315px;
 max-height:80vh;
 overflow:auto;
 background:#fff;
 border:1px solid #aaa;
 border-radius:5px;
 box-shadow:0 6px 20px #0003;
 padding:12px
}
.menu h3{margin:0 0 8px;font-size:16px}
.menu label{display:block;margin:8px 0}
.menu input[type=text],
.menu input[type=number],
.menu select{width:100%;padding:5px}
.choices{display:flex;gap:5px;flex-wrap:wrap;margin:5px 0}
.choices button.active{background:#dbeafe;border-color:#2563eb}
.colors{display:flex;gap:4px;flex-wrap:wrap}
.color{width:22px;height:22px;padding:0}
.color.active{outline:2px solid #2563eb}
.sep{border:0;border-top:1px solid #ddd;margin:10px 0}
.notice{background:#fef3c7;border:1px solid #f59e0b;border-radius:4px;padding:8px}
</style>
</head>
<body>

<div id="home" class="wrap">
 <div class="card">
  <h2>動画編集</h2>
  <p class="muted">編集対象の動画を明示的に選択してください。</p>
  <div class="row">
   <button id="addVideo" class="primary">動画を追加</button>
   <input id="videoFile" type="file" accept="video/*" class="hidden">
  </div>
 </div>
 <div class="card"><h3>動画一覧</h3><div id="videoList" class="list"></div></div>
 <div class="card"><h3>保存済み編集作業</h3><div id="workList" class="list"></div></div>
 <div class="card"><h3>編集結果動画</h3><div id="resultList" class="list"></div></div>
</div>

<div id="editor" class="wrap hidden">
 <div class="top row">
  <b id="title"></b>
  <span class="grow"></span>
  <span id="dirty" class="hidden">未保存</span>
  <button id="save">保存</button>
  <button id="output">編集結果を動画にする</button>
  <button id="exit">編集作業を終了する</button>
 </div>

 <div class="videoBox">
  <div id="video" class="video"></div>
 </div>

 <div class="card">
  <div class="row">
   <button id="play" class="primary">▶ 再生</button>
   <button id="pause">⏸ 一時停止</button>
   <button id="stop">■ 停止</button>
   <span id="time" class="muted"></span>
   <span class="grow"></span>
   <div class="scaleWrap">
    タイムラインスケール
    <input id="scale" type="range" min="1" max="3" step=".1" value="1">
    <span id="scaleText">1.0x</span>
   </div>
  </div>
  <p class="muted">
   動画領域またはタイムラインの空白部分を右クリックすると要素を追加できます。
   要素を選択すると四隅の接続点が表示されます。接続点から別の要素へドラッグすると接続線を作成できます。
  </p>
 </div>

 <div id="timeline" class="timeline">
  <div id="tViewport" class="tViewport">
   <div id="tInner" class="tInner">
    <div id="ruler" class="ruler"></div>
    <div id="lanes" class="lanes"></div>
   </div>
  </div>
 </div>
</div>

<div id="popup"></div>

<script>
"use strict";

const $=id=>document.getElementById(id);
const TYPES={comment:"コメント",highlight:"強調枠",zoom:"拡大枠",skip:"スキップ"};
const COLORS=[
 "#000000","#ffffff","#ef4444","#f97316","#eab308","#22c55e",
 "#06b6d4","#3b82f6","#8b5cf6","#ec4899","#6b7280","#92400e"
];

let uid=1;
let state=null;
let playTimer=null;
let connect=null;

const newid=p=>p+(uid++);
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const fmt=v=>Math.floor(v/60)+":"+String(Math.floor(v%60)).padStart(2,"0");

function mark(){
 state.dirty=true;
 $("dirty").classList.remove("hidden");
}

function clearPopup(){
 $("popup").innerHTML="";
}

function object(id){
 return state.objects.find(o=>o.id===id);
}

function selected(o){
 return state.sel.includes(o.id);
}

function videoPoint(e){
 const r=$("video").getBoundingClientRect();
 return {
  x:clamp((e.clientX-r.left)/r.width,0,1),
  y:clamp((e.clientY-r.top)/r.height,0,1)
 };
}

function setPosition(t){
 state.pos=clamp(t,0,state.dur);
 state.video.currentTime=state.pos;
 render();
}

$("addVideo").onclick=()=>$("videoFile").click();

$("videoFile").onchange=e=>{
 const f=e.target.files[0];
 if(!f)return;

 const v=document.createElement("video");
 v.preload="metadata";
 v.src=URL.createObjectURL(f);

 v.onloadedmetadata=()=>{
  const videos=JSON.parse(localStorage.videos||"[]");

  if(videos.length>=10){
   alert("オリジナル動画は10件までです。既存動画を削除してください。");
   return;
  }

  videos.push({
   id:newid("v"),
   name:f.name,
   url:v.src,
   dur:v.duration
  });

  localStorage.videos=JSON.stringify(videos);
  renderHome();
 };
};

function renderHome(){
 const videos=JSON.parse(localStorage.videos||"[]");
 const works=JSON.parse(localStorage.works||"[]");
 const results=JSON.parse(localStorage.results||"[]");

 $("videoList").innerHTML=videos.length
  ?videos.map(v=>`
   <div class="item">
    <b>${v.name}</b>
    <div class="meta">${fmt(v.dur)}　オリジナル動画</div>
    <div class="row" style="margin-top:6px">
     <button data-open="${v.id}">新しい編集作業</button>
     <button class="danger" data-delv="${v.id}">削除</button>
    </div>
   </div>`).join("")
  :'<div class="muted">動画はありません。</div>';

 $("workList").innerHTML=works.length
  ?works.map(w=>`
   <div class="item">
    <b>${w.name}</b>
    <div class="meta">${w.videoName}　${w.saved}</div>
    <div class="row" style="margin-top:6px">
     <button data-work="${w.id}">再開</button>
     <button data-copy="${w.id}">複製</button>
     <button class="danger" data-delw="${w.id}">削除</button>
    </div>
   </div>`).join("")
  :'<div class="muted">保存済み編集作業はありません。</div>';

 $("resultList").innerHTML=results.length
  ?results.map(r=>`
   <div class="item">
    <b>${r.name}</b>
    <div class="meta">${r.source}</div>
    <div class="row" style="margin-top:6px">
     <button data-result="${r.id}">確認</button>
     <button class="danger" data-delr="${r.id}">削除</button>
    </div>
   </div>`).join("")
  :'<div class="muted">編集結果動画はありません。</div>';

 document.querySelectorAll("[data-open]").forEach(b=>b.onclick=()=>startNew(b.dataset.open));
 document.querySelectorAll("[data-work]").forEach(b=>b.onclick=()=>resumeWork(b.dataset.work));
 document.querySelectorAll("[data-copy]").forEach(b=>b.onclick=()=>copyWork(b.dataset.copy));
 document.querySelectorAll("[data-delv]").forEach(b=>b.onclick=()=>deleteVideo(b.dataset.delv));
 document.querySelectorAll("[data-delw]").forEach(b=>b.onclick=()=>deleteWork(b.dataset.delw));
 document.querySelectorAll("[data-delr]").forEach(b=>b.onclick=()=>deleteResult(b.dataset.delr));
 document.querySelectorAll("[data-result]").forEach(b=>b.onclick=()=>previewResult(b.dataset.result));
}

function startNew(id){
 const v=JSON.parse(localStorage.videos||"[]").find(x=>x.id===id);
 if(v)openEditor(v,{
  id:null,
  name:"新しい編集作業",
  objects:[],
  lines:[]
 });
}

function resumeWork(id){
 const w=JSON.parse(localStorage.works||"[]").find(x=>x.id===id);
 if(!w)return;
 const v=JSON.parse(localStorage.videos||"[]").find(x=>x.id===w.videoId);
 if(v)openEditor(v,w);
}

function copyWork(id){
 const ws=JSON.parse(localStorage.works||"[]");
 const w=ws.find(x=>x.id===id);
 if(!w)return;
 if(ws.length>=10){
  alert("編集作業は10件までです。");
  return;
 }
 const copy=JSON.parse(JSON.stringify(w));
 copy.id=newid("w");
 copy.name+=" のコピー";
 copy.saved=new Date().toLocaleString("ja-JP");
 ws.push(copy);
 localStorage.works=JSON.stringify(ws);
 renderHome();
}

function deleteVideo(id){
 if(!confirm("この動画を削除しますか？"))return;
 localStorage.videos=JSON.stringify(
  JSON.parse(localStorage.videos||"[]").filter(v=>v.id!==id)
 );
 renderHome();
}

function deleteWork(id){
 if(!confirm("この編集作業を削除しますか？"))return;
 localStorage.works=JSON.stringify(
  JSON.parse(localStorage.works||"[]").filter(w=>w.id!==id)
 );
 renderHome();
}

function deleteResult(id){
 if(!confirm("この編集結果動画を削除しますか？"))return;
 localStorage.results=JSON.stringify(
  JSON.parse(localStorage.results||"[]").filter(r=>r.id!==id)
 );
 renderHome();
}

function previewResult(id){
 const r=JSON.parse(localStorage.results||"[]").find(x=>x.id===id);
 if(!r)return;

 if(r.url){
  const w=window.open("");
  w.document.write(`<video controls autoplay style="max-width:100%" src="${r.url}"></video>`);
 }else{
  alert("このモックでは結果動画の実ファイル生成は行いません。");
 }
}

function openEditor(v,w){
 state={
  videoId:v.id,
  name:w.name,
  dur:v.dur,
  url:v.url,
  objects:w.objects||[],
  lines:w.lines||[],
  sel:[],
  line:null,
  pos:0,
  scale:1,
  dirty:false,
  workId:w.id
 };

 $("home").classList.add("hidden");
 $("editor").classList.remove("hidden");
 $("title").textContent=v.name+" ／ "+w.name;

 $("video").innerHTML=
  '<video preload="auto"></video>'+
  '<div id="layer"><svg id="svg" viewBox="0 0 100 100" preserveAspectRatio="none"></svg></div>';

 state.video=$("video").querySelector("video");
 state.video.src=v.url;

 state.video.onended=()=>{
  stopPlay();
  state.pos=state.dur;
  render();
 };

 state.video.ontimeupdate=()=>{
  if(!state.video.seeking){
   state.pos=state.video.currentTime;
   renderTimelineOnly();
  }
 };

 render();
}

function closeEditor(){
 stopPlay();
 state=null;
 $("editor").classList.add("hidden");
 $("home").classList.remove("hidden");
 renderHome();
}

function render(){
 if(!state)return;
 renderObjects();
 renderLines();
 renderTimeline();
 updateTime();
}

function updateTime(){
 $("time").textContent=fmt(state.pos)+" / "+fmt(state.dur);
}

function renderObjects(){
 const layer=$("layer");
 const svg=$("svg");

 Array.from(layer.children).forEach(x=>{
  if(x!==svg)x.remove();
 });

 state.objects.forEach(o=>{
  if(o.type!=="skip"&&(state.pos<o.s||state.pos>o.e))return;

  const d=document.createElement("div");

  d.className=
   "obj"+
   (selected(o)?" selected":"")+
   (connect&&connect.from===o.id?" source":"")+
   (connect&&connect.target===o.id?" target":"");

  d.dataset.id=o.id;
  d.style.left=o.x*100+"%";
  d.style.top=o.y*100+"%";
  d.style.width=o.w*100+"%";
  d.style.height=o.h*100+"%";

  if(o.type==="comment"){
   d.textContent=o.a.text;
   d.style.color=o.a.textColor;
   d.style.fontSize=o.a.size+"px";
   d.style.fontFamily=o.a.font;
   d.style.background=o.a.fill?o.a.bg:"transparent";
   d.style.border=o.a.border
    ?`${o.a.borderWidth}px solid ${o.a.borderColor}`
    :"none";
   d.style.padding="5px";
  }

  if(o.type==="highlight"){
   d.style.border=
    `${o.a.width}px ${o.a.style} ${o.a.color}`;
   d.style.background=o.a.fill
    ?o.a.fillColor
    :"transparent";
  }

  if(o.type==="zoom"){
   d.style.border="2px solid #059669";
   d.style.background="#05966922";
   d.innerHTML=
    `<span style="background:#059669;color:#fff;padding:2px 5px">${o.a.zoom}倍</span>`;
  }

  if(selected(o)){
   ["t","r","b","l"].forEach(s=>{
    const p=document.createElement("i");
    p.className="port "+s;
    p.dataset.port=s;
    d.appendChild(p);
   });

   const r=document.createElement("i");
   r.className="resize";
   d.appendChild(r);
  }

  layer.appendChild(d);
 });
}

function anchor(o,side){
 if(side==="t")return{x:o.x+o.w/2,y:o.y};
 if(side==="r")return{x:o.x+o.w,y:o.y+o.h/2};
 if(side==="b")return{x:o.x+o.w/2,y:o.y+o.h};
 return{x:o.x,y:o.y+o.h/2};
}

function nearestSide(o,p){
 const a={
  t:Math.abs(p.y-o.y),
  r:Math.abs(p.x-o.x-o.w),
  b:Math.abs(p.y-o.y-o.h),
  l:Math.abs(p.x-o.x)
 };
 return Object.keys(a).sort((x,y)=>a[x]-a[y])[0];
}

function pathFor(l){
 const a=object(l.from);
 const b=object(l.to);

 if(!a||!b)return"";

 const p=anchor(a,l.fs);
 const q=anchor(b,l.ts);

 if(l.shape==="直線"){
  return `M ${p.x*100} ${p.y*100} L ${q.x*100} ${q.y*100}`;
 }

 if(l.shape==="波線"){
  let d=`M ${p.x*100} ${p.y*100}`;
  const dx=q.x-p.x;
  const dy=q.y-p.y;
  const len=Math.hypot(dx,dy)||1;
  const nx=-dy/len*.018;
  const ny=dx/len*.018;

  for(let i=1;i<=24;i++){
   const t=i/24;
   const s=i%2?1:-1;
   d+=` L ${(p.x+dx*t+nx*s)*100} ${(p.y+dy*t+ny*s)*100}`;
  }

  return d;
 }

 const mx=(p.x+q.x)/2;
 const my=(p.y+q.y)/2;

 return [
  `M ${p.x*100} ${p.y*100}`,
  `L ${mx*100} ${p.y*100}`,
  `L ${mx*100} ${q.y*100}`,
  `L ${q.x*100} ${q.y*100}`
 ].join(" ");
}

function renderLines(){
 const svg=$("svg");
 if(!svg)return;

 while(svg.firstChild)svg.firstChild.remove();

 state.lines.forEach(l=>{
  const d=pathFor(l);
  if(!d)return;

  const g=document.createElementNS("http://www.w3.org/2000/svg","g");
  const p=document.createElementNS("http://www.w3.org/2000/svg","path");

  p.setAttribute("d",d);
  p.setAttribute("stroke",state.line===l.id?"#f59e0b":l.color);
  p.setAttribute("stroke-width",l.width);
  p.setAttribute("stroke-dasharray",l.dash||"");
  p.classList.add("line");

  if(l.end==="arrow"){
   const defs=document.createElementNS(
    "http://www.w3.org/2000/svg","defs"
   );

   const marker=document.createElementNS(
    "http://www.w3.org/2000/svg","marker"
   );

   const markerId="arrow_"+l.id;

   marker.setAttribute("id",markerId);
   marker.setAttribute("viewBox","0 0 10 10");
   marker.setAttribute("refX","9");
   marker.setAttribute("refY","5");
   marker.setAttribute("markerWidth","6");
   marker.setAttribute("markerHeight","6");
   marker.setAttribute("orient","auto");

   const z=document.createElementNS(
    "http://www.w3.org/2000/svg","path"
   );

   z.setAttribute("d","M0 0 L10 5 L0 10 Z");
   z.setAttribute("fill",l.color);

   marker.appendChild(z);
   defs.appendChild(marker);
   g.appendChild(defs);

   p.setAttribute("marker-end",`url(#${markerId})`);
  }

  g.appendChild(p);
  svg.appendChild(g);
 });

 if(connect){
  const p=document.createElementNS(
   "http://www.w3.org/2000/svg","path"
  );

  p.classList.add("temp");
  p.setAttribute(
   "d",
   `M ${connect.start.x*100} ${connect.start.y*100} L ${connect.cur.x*100} ${connect.cur.y*100}`
  );

  svg.appendChild(p);
 }
}

function renderTimelineOnly(){
 const ph=document.querySelector(".playhead");

 if(ph){
  ph.style.left=(state.dur?state.pos/state.dur*100:0)+"%";
 }

 updateTime();
}

function renderTimeline(){
 const vp=$("tViewport");
 const inner=$("tInner");
 const ruler=$("ruler");
 const lanes=$("lanes");

 if(!vp||!inner||!ruler||!lanes)return;

 inner.style.width="100%";

 ruler.innerHTML="";
 lanes.innerHTML="";

 const step=
  state.dur<=30?5:
  state.dur<=120?10:
  state.dur<=300?30:60;

 for(let t=0;t<=state.dur;t+=step){
  const tick=document.createElement("i");
  tick.className="tick";
  tick.style.left=(state.dur?t/state.dur*100:0)+"%";
  tick.textContent=fmt(t);
  ruler.appendChild(tick);
 }

 const rows=[];

 state.objects.forEach(o=>{
  let row=0;

  while(rows[row]!=null&&rows[row]>o.s)row++;

  rows[row]=o.e;

  const c=document.createElement("div");

  c.className=
   "clip"+
   (selected(o)?" selected":"")+
   (o.type==="skip"?" skip":"");

  c.dataset.id=o.id;

  c.style.left=(state.dur?o.s/state.dur*100:0)+"%";
  c.style.width=Math.max(
   state.dur?(o.e-o.s)/state.dur*100:.8,
   .8
  )+"%";
  c.style.top=8+row*32+"px";

  c.style.background=
   o.type==="skip"?"#6b7280":
   o.type==="comment"?"#2563eb":
   o.type==="highlight"?"#dc2626":"#059669";

  c.textContent=TYPES[o.type]+" "+fmt(o.s)+"-"+fmt(o.e);

  const left=document.createElement("i");
  const right=document.createElement("i");

  left.className="ch l";
  right.className="ch r";

  c.prepend(left);
  c.appendChild(right);
  lanes.appendChild(c);
 });

 const ph=document.createElement("div");
 ph.className="playhead";
 ph.style.left=(state.dur?state.pos/state.dur*100:0)+"%";
 lanes.appendChild(ph);

 $("scaleText").textContent=state.scale.toFixed(1)+"x";
}

function addObject(type,t,p){
 const s=clamp(t,0,Math.max(0,state.dur-5));

 const o={
  id:newid("e"),
  type,
  s,
  e:Math.min(state.dur,s+5),
  x:clamp(p.x-.16,0,.68),
  y:clamp(p.y-.1,0,.7),
  w:.32,
  h:.2,
  a:{}
 };

 if(type==="comment"){
  o.a={
   text:"コメント",
   border:true,
   borderWidth:2,
   borderColor:"#2563eb",
   textColor:"#000",
   fill:true,
   bg:"#fff",
   size:18,
   font:"Arial"
  };
 }

 if(type==="highlight"){
  o.a={
   width:3,
   color:"#ef4444",
   fill:true,
   fillColor:"#ef444455",
   style:"solid",
   shape:"直線"
  };
 }

 if(type==="zoom"){
  o.a={zoom:1.5};
 }

 state.objects.push(o);
 state.sel=[o.id];
 state.line=null;

 mark();
 clearPopup();
 render();
}

function addMenu(x,y,t,p){
 menu(
  x,y,
  `<h3>要素を追加</h3>
   <div class="muted">開始位置 ${fmt(t)}</div>
   <div class="choices">
    <button data-add="comment">コメント</button>
    <button data-add="highlight">強調枠</button>
    <button data-add="zoom">拡大枠</button>
    <button data-add="skip">スキップ</button>
   </div>`
 );

 document.querySelectorAll("[data-add]").forEach(b=>{
  b.onclick=()=>addObject(b.dataset.add,t,p);
 });
}

function menu(x,y,html){
 clearPopup();

 $("popup").innerHTML=`<div class="menu">${html}</div>`;

 const m=$("popup").firstElementChild;

 m.style.left=Math.min(
  x,
  innerWidth-m.offsetWidth-8
 )+"px";

 m.style.top=Math.min(
  y,
  innerHeight-m.offsetHeight-8
 )+"px";
}

function colorButtons(prop,current){
 return COLORS.map(c=>
  `<button class="color ${c===current?"active":""}"
   data-cprop="${prop}"
   data-c="${c}"
   style="background:${c}"></button>`
 ).join("");
}

function elementMenu(x,y,o){
 let h=`<h3>${TYPES[o.type]}</h3>`;

 if(o.type==="comment"){
  h+=`
   <label>本文
    <input id="text" type="text" value="${String(o.a.text).replace(/"/g,"&quot;")}">
   </label>
   <label>文字サイズ
    <input id="size" type="number" min="8" max="72" value="${o.a.size}">
   </label>
   <label>フォント
    <select id="font">
     <option>Arial</option>
     <option>serif</option>
     <option>sans-serif</option>
     <option>monospace</option>
    </select>
   </label>
   <label>線
    <input id="border" type="checkbox" ${o.a.border?"checked":""}>
   </label>
   <label>塗り潰し
    <input id="fill" type="checkbox" ${o.a.fill?"checked":""}>
   </label>
   <div>文字色</div>
   <div class="colors">${colorButtons("textColor",o.a.textColor)}</div>
   <div>線色</div>
   <div class="colors">${colorButtons("borderColor",o.a.borderColor)}</div>
   <div>背景色</div>
   <div class="colors">${colorButtons("bg",o.a.bg)}</div>`;
 }

 if(o.type==="highlight"){
  h+=`
   <div>線の太さ</div>
   <div class="choices">
    ${[2,3,5,8].map(v=>`<button data-width="${v}">${v}px</button>`).join("")}
   </div>
   <div>線種</div>
   <div class="choices">
    ${[
     ["solid","実線"],
     ["dotted","点線"],
     ["dashed","破線"],
     ["dashdot","一点鎖線"]
    ].map(v=>`<button data-hstyle="${v[0]}">${v[1]}</button>`).join("")}
   </div>
   <div>線形</div>
   <div class="choices">
    ${["直線","折れ線","波線"].map(v=>`<button data-hshape="${v}">${v}</button>`).join("")}
   </div>
   <label>塗り潰し
    <input id="hfill" type="checkbox" ${o.a.fill?"checked":""}>
   </label>
   <label>塗り潰し色
    <input id="hcolor" type="color" value="${o.a.fillColor.substring(0,7)}">
   </label>
   <div>線色</div>
   <div class="colors">${colorButtons("color",o.a.color)}</div>`;
 }

 if(o.type==="zoom"){
  h+=`
   <label>拡大倍率
    <select id="zoom">
     <option value="1.25">1.25倍</option>
     <option value="1.5">1.5倍</option>
     <option value="2">2倍</option>
     <option value="3">3倍</option>
    </select>
   </label>`;
 }

 if(o.type==="skip"){
  h+=`<div class="notice">スキップは動画領域には表示されません。</div>`;
 }

 h+=`
  <hr class="sep">
  <button id="del" class="danger">この要素を削除</button>`;

 menu(x,y,h);

 $("text")?.addEventListener("input",e=>{
  o.a.text=e.target.value;
  mark();
  render();
 });

 $("size")?.addEventListener("change",e=>{
  o.a.size=+e.target.value;
  mark();
  render();
 });

 $("font")?.addEventListener("change",e=>{
  o.a.font=e.target.value;
  mark();
  render();
 });

 $("border")?.addEventListener("change",e=>{
  o.a.border=e.target.checked;
  mark();
  render();
 });

 $("fill")?.addEventListener("change",e=>{
  o.a.fill=e.target.checked;
  mark();
  render();
 });

 $("hfill")?.addEventListener("change",e=>{
  o.a.fill=e.target.checked;
  mark();
  render();
 });

 $("hcolor")?.addEventListener("input",e=>{
  o.a.fillColor=e.target.value+"55";
  mark();
  render();
 });

 $("zoom")?.addEventListener("change",e=>{
  o.a.zoom=+e.target.value;
  mark();
  render();
 });

 document.querySelectorAll("[data-cprop]").forEach(b=>{
  b.onclick=()=>{
   o.a[b.dataset.cprop]=b.dataset.c;
   mark();
   render();
   elementMenu(x,y,o);
  };
 });

 document.querySelectorAll("[data-width]").forEach(b=>{
  b.onclick=()=>{
   o.a.width=+b.dataset.width;
   mark();
   render();
   elementMenu(x,y,o);
  };
 });

 document.querySelectorAll("[data-hstyle]").forEach(b=>{
  b.onclick=()=>{
   o.a.style=b.dataset.hstyle;
   mark();
   render();
   elementMenu(x,y,o);
  };
 });

 document.querySelectorAll("[data-hshape]").forEach(b=>{
  b.onclick=()=>{
   o.a.shape=b.dataset.hshape;
   mark();
   render();
   elementMenu(x,y,o);
  };
 });

 $("del").onclick=()=>{
  state.objects=state.objects.filter(v=>v.id!==o.id);
  state.lines=state.lines.filter(l=>l.from!==o.id&&l.to!==o.id);
  state.sel=state.sel.filter(v=>v!==o.id);
  state.line=null;
  mark();
  clearPopup();
  render();
 };
}

function lineMenu(x,y,l){
 const sides=[
  ["t","上"],
  ["r","右"],
  ["b","下"],
  ["l","左"]
 ];

 menu(
  x,y,
  `<h3>接続線</h3>
   <div>線形</div>
   <div class="choices">
    ${["直線","折れ線","波線"].map(v=>
     `<button data-shape="${v}">${v}</button>`
    ).join("")}
   </div>
   <div>線種</div>
   <div class="choices">
    ${[
     ["","実線"],
     ["2 5","点線"],
     ["8 5","破線"],
     ["10 5 2 5","一点鎖線"]
    ].map(v=>
     `<button data-dash="${v[0]}">${v[1]}</button>`
    ).join("")}
   </div>
   <label>太さ
    <select id="lw">
     <option>2</option>
     <option>3</option>
     <option>5</option>
    </select>
   </label>
   <div>接続元</div>
   <div class="choices">
    ${sides.map(v=>`<button data-fs="${v[0]}">${v[1]}</button>`).join("")}
   </div>
   <div>接続先</div>
   <div class="choices">
    ${sides.map(v=>`<button data-ts="${v[0]}">${v[1]}</button>`).join("")}
   </div>
   <label>終端
    <select id="end">
     <option value="none">なし</option>
     <option value="arrow">矢印</option>
    </select>
   </label>
   <div>色</div>
   <div class="colors">${colorButtons("color",l.color)}</div>
   <hr class="sep">
   <button id="dell" class="danger">接続線を削除</button>`
 );

 document.querySelectorAll("[data-shape]").forEach(b=>{
  b.onclick=()=>{
   l.shape=b.dataset.shape;
   mark();
   render();
   lineMenu(x,y,l);
  };
 });

 document.querySelectorAll("[data-dash]").forEach(b=>{
  b.onclick=()=>{
   l.dash=b.dataset.dash;
   mark();
   render();
   lineMenu(x,y,l);
  };
 });

 document.querySelectorAll("[data-fs]").forEach(b=>{
  b.onclick=()=>{
   l.fs=b.dataset.fs;
   mark();
   render();
   lineMenu(x,y,l);
  };
 });

 document.querySelectorAll("[data-ts]").forEach(b=>{
  b.onclick=()=>{
   l.ts=b.dataset.ts;
   mark();
   render();
   lineMenu(x,y,l);
  };
 });

 document.querySelectorAll("[data-cprop]").forEach(b=>{
  b.onclick=()=>{
   l[b.dataset.cprop]=b.dataset.c;
   mark();
   render();
   lineMenu(x,y,l);
  };
 });

 $("lw").onchange=e=>{
  l.width=+e.target.value;
  mark();
  render();
 };

 $("end").onchange=e=>{
  l.end=e.target.value;
  mark();
  render();
 };

 $("dell").onclick=()=>{
  state.lines=state.lines.filter(v=>v.id!==l.id);
  state.line=null;
  mark();
  clearPopup();
  render();
 };
}

function objectLine(id){
 return state.lines.find(l=>l.id===id);
}

/* 接続線開始 */
$("video").addEventListener("pointerdown",e=>{
 if(e.button!==0)return;

 const port=e.target.closest(".port");

 if(port){
  const el=e.target.closest(".obj");
  if(!el)return;

  const o=object(el.dataset.id);
  if(!o)return;

  const side=port.dataset.port;
  const start=anchor(o,side);

  connect={
   from:o.id,
   fs:side,
   start,
   cur:start,
   target:null
  };

  e.preventDefault();
  e.stopPropagation();

  const move=ev=>{
   if(!connect)return;

   connect.cur=videoPoint(ev);
   connect.target=null;

   const hit=document.elementsFromPoint(
    ev.clientX,
    ev.clientY
   );

   const targetEl=hit.find(x=>
    x.classList&&
    x.classList.contains("obj")&&
    x.dataset.id!==o.id
   );

   if(targetEl){
    const target=object(targetEl.dataset.id);

    if(target&&target.type!=="skip"){
     connect.target=target.id;
    }
   }

   renderLines();
   renderObjects();
  };

  const up=ev=>{
   window.removeEventListener("pointermove",move);
   window.removeEventListener("pointerup",up);

   if(connect&&connect.target){
    const target=object(connect.target);

    if(target){
     state.lines.push({
      id:newid("l"),
      from:o.id,
      to:target.id,
      fs:connect.fs,
      ts:nearestSide(target,connect.cur),
      shape:"直線",
      dash:"",
      width:3,
      color:"#2563eb",
      end:"none"
     });

     state.sel=[];
     state.line=state.lines[state.lines.length-1].id;
     mark();
    }
   }

   connect=null;
   render();
  };

  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);

  return;
 }

 const resize=e.target.closest(".resize");
 const n=e.target.closest(".obj");

 if(!n)return;

 const o=object(n.dataset.id);
 if(!o)return;

 state.sel=e.shiftKey
  ?(state.sel.includes(o.id)
    ?state.sel.filter(v=>v!==o.id)
    :[...state.sel,o.id])
  :[o.id];

 state.line=null;
 render();

 if(resize){
  resizeObject(o,e);
 }else{
  moveObject(o,e);
 }
});

function moveObject(o,e){
 const r=$("video").getBoundingClientRect();
 const sx=e.clientX;
 const sy=e.clientY;
 const ox=o.x;
 const oy=o.y;

 const mv=ev=>{
  o.x=clamp(
   ox+(ev.clientX-sx)/r.width,
   0,
   1-o.w
  );

  o.y=clamp(
   oy+(ev.clientY-sy)/r.height,
   0,
   1-o.h
  );

  mark();
  render();
 };

 const up=()=>{
  window.removeEventListener("pointermove",mv);
  window.removeEventListener("pointerup",up);
 };

 window.addEventListener("pointermove",mv);
 window.addEventListener("pointerup",up);
}

function resizeObject(o,e){
 const r=$("video").getBoundingClientRect();
 const sx=e.clientX;
 const sy=e.clientY;
 const ow=o.w;
 const oh=o.h;

 const mv=ev=>{
  o.w=clamp(
   ow+(ev.clientX-sx)/r.width,
   .05,
   1-o.x
  );

  o.h=clamp(
   oh+(ev.clientY-sy)/r.height,
   .05,
   1-o.y
  );

  mark();
  render();
 };

 const up=()=>{
  window.removeEventListener("pointermove",mv);
  window.removeEventListener("pointerup",up);
 };

 window.addEventListener("pointermove",mv);
 window.addEventListener("pointerup",up);
}

$("video").addEventListener("contextmenu",e=>{
 e.preventDefault();

 const obj=e.target.closest(".obj");

 if(obj){
  const o=object(obj.dataset.id);
  state.sel=[o.id];
  state.line=null;
  render();
  elementMenu(e.clientX,e.clientY,o);
  return;
 }

 addMenu(
  e.clientX,
  e.clientY,
  state.pos,
  videoPoint(e)
 );
});

$("timeline").addEventListener("contextmenu",e=>{
 e.preventDefault();

 const c=e.target.closest(".clip");

 if(c){
  const o=object(c.dataset.id);
  state.sel=[o.id];
  state.line=null;
  render();
  elementMenu(e.clientX,e.clientY,o);
 }else{
  addMenu(
   e.clientX,
   e.clientY,
   timelineTime(e),
   {x:.25,y:.25}
  );
 }
});

function timelineTime(e){
 const r=$("tInner").getBoundingClientRect();
 return clamp(
  (e.clientX-r.left)/r.width,
  0,
  1
 )*state.dur;
}

$("lanes").addEventListener("pointerdown",e=>{
 const c=e.target.closest(".clip");

 if(!c){
  timelineSeek(e);
  return;
 }

 const o=object(c.dataset.id);

 state.sel=e.shiftKey
  ?(state.sel.includes(o.id)
    ?state.sel.filter(v=>v!==o.id)
    :[...state.sel,o.id])
  :[o.id];

 state.line=null;
 render();

 const h=e.target.closest(".ch");

 if(h){
  resizeTime(o,h.classList.contains("l"),e);
 }else{
  moveTime(o,e);
 }
});

function timelineSeek(e){
 const r=$("tInner").getBoundingClientRect();

 setPosition(
  clamp((e.clientX-r.left)/r.width,0,1)*state.dur
 );

 const mv=ev=>{
  const q=$("tInner").getBoundingClientRect();

  setPosition(
   clamp((ev.clientX-q.left)/q.width,0,1)*state.dur
  );
 };

 const up=()=>{
  window.removeEventListener("pointermove",mv);
  window.removeEventListener("pointerup",up);
 };

 window.addEventListener("pointermove",mv);
 window.addEventListener("pointerup",up);
}

function moveTime(o,e){
 const r=$("tInner").getBoundingClientRect();
 const sx=e.clientX;
 const os=o.s;
 const oe=o.e;
 const d=oe-os;

 const mv=ev=>{
  const dt=(ev.clientX-sx)/r.width*state.dur;

  o.s=clamp(
   os+dt,
   0,
   state.dur-d
  );

  o.e=o.s+d;

  mark();
  render();
 };

 const up=()=>{
  window.removeEventListener("pointermove",mv);
  window.removeEventListener("pointerup",up);
 };

 window.addEventListener("pointermove",mv);
 window.addEventListener("pointerup",up);
}

function resizeTime(o,left,e){
 const r=$("tInner").getBoundingClientRect();
 const sx=e.clientX;
 const os=o.s;
 const oe=o.e;

 const mv=ev=>{
  const dt=(ev.clientX-sx)/r.width*state.dur;

  if(left){
   o.s=clamp(os+dt,0,oe-.1);
  }else{
   o.e=clamp(oe+dt,o.s+.1,state.dur);
  }

  mark();
  render();
 };

 const up=()=>{
  window.removeEventListener("pointermove",mv);
  window.removeEventListener("pointerup",up);
 };

 window.addEventListener("pointermove",mv);
 window.addEventListener("pointerup",up);
}

$("ruler").addEventListener("pointerdown",timelineSeek);

$("scale").oninput=e=>{
 state.scale=+e.target.value;
 renderTimeline();
};

$("play").onclick=()=>{
 if(playTimer)return;

 state.video.currentTime=state.pos;
 state.video.play();

 playTimer=setInterval(()=>{
  state.pos=state.video.currentTime;

  const skip=state.objects.find(o=>
   o.type==="skip"&&
   state.pos>=o.s&&
   state.pos<o.e
  );

  if(skip){
   state.pos=skip.e;
   state.video.currentTime=skip.e;
  }

  renderTimelineOnly();
 },50);
};

$("pause").onclick=()=>{
 stopPlay();
};

$("stop").onclick=()=>{
 stopPlay();
 state.video.pause();
 state.pos=0;
 state.video.currentTime=0;
 render();
};

function stopPlay(){
 if(playTimer){
  clearInterval(playTimer);
  playTimer=null;
 }

 if(state?.video){
  state.video.pause();
 }
}

$("save").onclick=()=>{
 const ws=JSON.parse(localStorage.works||"[]");

 let name=state.workId
  ?(ws.find(w=>w.id===state.workId)||{}).name
  :prompt("編集作業名を入力してください","編集作業");

 if(name===null||name===undefined)return;

 const data={
  id:state.workId||newid("w"),
  name,
  videoId:state.videoId,
  videoName:$("title").textContent.split(" ／ ")[0],
  saved:new Date().toLocaleString("ja-JP"),
  objects:state.objects,
  lines:state.lines
 };

 const i=ws.findIndex(w=>w.id===data.id);

 if(i<0&&ws.length>=10){
  alert("編集作業は10件までです。既存編集作業を削除してください。");
  return;
 }

 if(i<0){
  ws.push(data);
 }else{
  ws[i]=data;
 }

 localStorage.works=JSON.stringify(ws);

 state.workId=data.id;
 state.dirty=false;
 $("dirty").classList.add("hidden");
 $("title").textContent=data.videoName+" ／ "+data.name;

 alert("保存しました。");
};

$("output").onclick=()=>{
 if(state.dirty&&!confirm("未保存の変更があります。保存してから作成しますか？"))return;

 if(state.dirty){
  $("save").click();
 }

 const rs=JSON.parse(localStorage.results||"[]");

 if(rs.length>=10){
  alert("編集結果動画は10件までです。既存動画を削除してください。");
  return;
 }

 rs.push({
  id:newid("r"),
  name:$("title").textContent+" 編集結果",
  source:$("title").textContent,
  url:state.url
 });

 localStorage.results=JSON.stringify(rs);

 alert("編集結果動画を作成しました。モックでは元動画を結果動画として確認できます。");
};

$("exit").onclick=()=>{
 if(state.dirty){
  const r=confirm("未保存の変更があります。保存して終了しますか？");

  if(r){
   $("save").click();
  }else if(!confirm("保存せずに終了しますか？")){
   return;
  }
 }

 closeEditor();
};

document.addEventListener("pointerdown",e=>{
 if(!e.target.closest(".menu")){
  clearPopup();
 }
});

document.addEventListener("keydown",e=>{
 if(e.key==="Escape"){
  connect=null;
  clearPopup();

  if(state)render();
 }
});

renderHome();
</script>
</body>
</html>

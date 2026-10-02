<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:#e8edf5;background:#0d1117}
button,input,select{font:inherit}button{cursor:pointer}
.app{height:100%;display:flex;flex-direction:column}
.header{height:52px;flex:none;display:flex;align-items:center;gap:8px;padding:0 12px;background:#161b22;border-bottom:1px solid #30363d}
.title{font-weight:700;margin-right:10px}
.btn{border:1px solid #3b4654;background:#242c36;color:#e8edf5;border-radius:6px;padding:7px 11px}
.btn:hover{background:#303a47}.primary{background:#1769aa;border-color:#2387d9}
.status{margin-left:auto;color:#8b949e;font-size:12px}
.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{min-height:0;flex:1;background:#05070a;display:flex;align-items:center;justify-content:center;padding:12px}
.video-wrap{position:relative;width:min(1200px,100%);height:100%;background:#000;overflow:hidden}
video{width:100%;height:100%;object-fit:contain;display:block}
.svg-layer{position:absolute;inset:0;width:100%;height:100%;overflow:visible;z-index:10}
.connection-hit{fill:none;stroke:transparent;stroke-width:14;vector-effect:non-scaling-stroke;pointer-events:stroke;cursor:pointer}
.connection{fill:none;stroke:#ff5252;stroke-width:1.2;vector-effect:non-scaling-stroke;pointer-events:none}
.connection.selected{stroke:#4da3ff;stroke-width:2}
.connection-handle{fill:#fff;stroke:#4da3ff;stroke-width:1.5;vector-effect:non-scaling-stroke;cursor:crosshair;pointer-events:auto}
.overlay{position:absolute;inset:0;z-index:20;pointer-events:none}
.element{position:absolute;pointer-events:auto;user-select:none;touch-action:none;min-width:40px;min-height:25px;cursor:move}
.element.selected{outline:2px solid #4da3ff;outline-offset:3px}
.element.multi{outline:2px solid #ffc107;outline-offset:3px}
.element-body{width:100%;height:100%;display:flex;align-items:center;justify-content:center;overflow:hidden}
.comment .element-body{color:#fff;text-shadow:0 1px 4px #000;font-size:18px;padding:4px}
.highlight .element-body{border:3px solid #f04444;background:transparent}
.highlight.round .element-body{border-radius:18px}
.highlight.circle .element-body{border-radius:50%}
.zoombox .element-body{border:3px solid #111;background:#1113}
.skip .element-body{border:2px dashed #ff9800;background:#ff980033;color:#ffb74d;font-size:12px}
.handle{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #4da3ff;z-index:5}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.ne{right:-5px;top:-5px;cursor:nesw-resize}
.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.se{right:-5px;bottom:-5px;cursor:nwse-resize}
.timeline{height:255px;flex:none;background:#11161d;border-top:1px solid #30363d;display:flex;flex-direction:column}
.timeline-toolbar{height:42px;flex:none;display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid #30363d}
.time{font-variant-numeric:tabular-nums;min-width:150px;color:#dbeafe}
.scale{margin-left:auto;display:flex;align-items:center;gap:7px;color:#8b949e;font-size:12px}.scale input{width:110px}
.timeline-scroll{min-height:0;flex:1;overflow:auto}
.timeline-content{position:relative;min-width:700px;height:100%}
.axis{height:30px;position:relative;margin-left:100px;background:#171d25;border-bottom:1px solid #30363d}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #343b45;color:#7d8590;font-size:10px;padding:4px 0 0 3px}
.rows{margin-left:100px}
.row{position:relative;border-bottom:1px solid #242b33}
.row-label{position:absolute;right:100%;top:0;width:100px;display:flex;align-items:center;padding:0 8px;gap:5px;background:#161b22;border-right:1px solid #30363d;font-size:11px}
.dot{width:8px;height:8px;border-radius:50%}
.lane{position:relative}
.bar{position:absolute;height:34px;border-radius:5px;border:1px solid currentColor;display:flex;align-items:center;cursor:grab;user-select:none;touch-action:none;overflow:visible}
.bar.selected{box-shadow:0 0 0 2px #4da3ff}.bar.multi{box-shadow:0 0 0 2px #ffc107}
.bar span{font-size:10px;padding:0 7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar-handle{position:absolute;top:0;height:100%;width:8px;cursor:ew-resize}
.bar-handle.left{left:-4px}.bar-handle.right{right:-4px}
.playhead{position:absolute;top:30px;bottom:0;width:2px;background:#f04444;z-index:100;pointer-events:none}
.playhead:before{content:"";position:absolute;top:-2px;left:-5px;width:12px;height:12px;background:#f04444;clip-path:polygon(0 0,100% 0,50% 100%)}
.menu{position:fixed;z-index:1000;display:none;min-width:210px;background:#1c2531;border:1px solid #46515f;border-radius:7px;box-shadow:0 12px 30px #0009;padding:5px}
.menu-title{font-size:11px;color:#8b949e;padding:6px 8px}
.menu button{display:block;width:100%;text-align:left;border:0;background:transparent;color:#e8edf5;padding:8px;border-radius:4px}
.menu button:hover{background:#2b3745}
.menu hr{border:0;border-top:1px solid #303b48;margin:4px 0}
.menu select{width:100%;background:#242c36;color:#fff;border:1px solid #46515f;padding:6px;border-radius:4px}
.hint{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);color:#66717f;text-align:center;pointer-events:none}
.toast{position:fixed;right:15px;bottom:15px;background:#202938;border:1px solid #46515f;border-radius:6px;padding:9px 13px;display:none;z-index:2000}
@media(max-width:800px){.title{display:none}.timeline{height:220px}}
</style>
</head>
<body>
<div class="app">
<header class="header">
<div class="title">動画編集・注釈</div>
<button class="btn primary" id="loadBtn">動画を読み込む</button>
<input id="fileInput" type="file" accept="video/*" hidden>
<button class="btn" id="saveBtn">保存</button>
<button class="btn" id="exportBtn">書き出し</button>
<div class="status" id="status">動画を読み込んでください</div>
</header>

<main class="main">
<section class="video-area">
<div class="video-wrap" id="videoWrap">
<video id="video" playsinline preload="metadata"></video>
<svg id="svg" class="svg-layer"></svg>
<div id="overlay" class="overlay"></div>
<div id="hint" class="hint">動画を読み込んでください<br>読み込み後、動画上で右クリックすると要素を追加できます</div>
</div>
</section>

<section class="timeline">
<div class="timeline-toolbar">
<button class="btn" id="playBtn">▶</button>
<button class="btn" id="stopBtn">■</button>
<div class="time" id="time">00:00.000 / 00:00.000</div>
<div class="scale">時間スケール
<input id="scale" type="range" min="1" max="10" step=".1" value="1">
<span id="scaleText">1.0×</span>
</div>
</div>
<div class="timeline-scroll" id="timelineScroll">
<div class="timeline-content" id="timelineContent">
<div class="axis" id="axis"></div>
<div class="rows" id="rows"></div>
<div class="playhead" id="playhead"></div>
</div>
</div>
</section>
</main>
</div>

<div id="menu" class="menu"></div>
<div id="toast" class="toast"></div>

<script>
const $=id=>document.getElementById(id);
const video=$("video"),wrap=$("videoWrap"),overlay=$("overlay"),svg=$("svg");
const menu=$("menu"),toast=$("toast"),rows=$("rows"),axis=$("axis");
const content=$("timelineContent"),playhead=$("playhead");

let duration=60,scale=1,current=0,selected=[],selectedConnection=null;
let connections=[],drag=null,menuTarget=null,nextId=5;
const SAVE_KEY="video-editor-mock-v3";

const elements=[
{id:1,type:"comment",label:"コメント",start:5,end:18,x:18,y:15,w:19,h:9,text:"ここを確認してください"},
{id:2,type:"highlight",label:"強調枠",start:8,end:24,x:52,y:34,w:23,h:15,shape:"rect"},
{id:3,type:"zoombox",label:"拡大枠",start:20,end:35,x:20,y:58,w:18,h:12,shape:"rect"},
{id:4,type:"skip",label:"スキップ",start:38,end:45,x:0,y:0,w:0,h:0}
];

const colors={comment:"#4da3ff",highlight:"#f04444",zoombox:"#111",skip:"#ff9800"};

function toastMsg(s){
 toast.textContent=s;
 toast.style.display="block";
 clearTimeout(toastMsg.t);
 toastMsg.t=setTimeout(()=>toast.style.display="none",1800);
}

function fmt(t){
 t=Math.max(0,t||0);
 const s=t%60;
 return String(Math.floor(t/60)).padStart(2,"0")+":"+s.toFixed(3).padStart(6,"0");
}

function activeElements(){
 return elements.filter(e=>current>=e.start&&current<=e.end);
}

function videoPosition(e){
 return{left:e.x+"%",top:e.y+"%",width:e.w+"%",height:e.h+"%"};
}

function selectElement(id,event){
 const multi=event&&(event.shiftKey||event.ctrlKey||event.metaKey);
 selectedConnection=null;
 if(multi){
  selected=selected.includes(id)?selected.filter(x=>x!==id):[...selected,id];
 }else{
  selected=selected.length===1&&selected[0]===id?selected:[id];
 }
 renderOverlay();renderRows();
}

function clearSelection(){
 selected=[];
 selectedConnection=null;
 menu.style.display="none";
 renderOverlay();renderRows();renderConnections();
}

function renderOverlay(){
 overlay.innerHTML="";
 activeElements().forEach(e=>{
  if(e.type==="skip")return;

  const d=document.createElement("div");
  d.className="element "+e.type+
   (selected.includes(e.id)?" selected":"")+
   (selected.length>1&&selected.includes(e.id)?" multi":"");
  d.dataset.id=e.id;
  Object.assign(d.style,videoPosition(e));

  if(e.type==="highlight")d.classList.add(e.shape||"rect");

  const body=document.createElement("div");
  body.className="element-body";
  if(e.type==="comment")body.textContent=e.text;
  d.appendChild(body);

  ["nw","ne","sw","se"].forEach(p=>{
   const h=document.createElement("div");
   h.className="handle "+p;
   h.dataset.resize=p;
   d.appendChild(h);
  });

  d.addEventListener("pointerdown",startElement);
  d.addEventListener("contextmenu",ev=>{
   ev.preventDefault();ev.stopPropagation();
   if(!selected.includes(e.id))selectElement(e.id,ev);
   showElementMenu(ev.clientX,ev.clientY,e.id);
  });

  overlay.appendChild(d);
 });

 renderConnections();
}

function startElement(ev){
 if(ev.button!==0)return;
 const el=elements.find(x=>x.id===Number(ev.currentTarget.dataset.id));
 if(!el)return;
 ev.stopPropagation();

 const resize=ev.target.dataset.resize||null;
 if(!selected.includes(el.id))selectElement(el,ev);

 if(resize){
  drag={kind:"resize",el,resize,x:ev.clientX,y:ev.clientY,
   ox:el.x,oy:el.y,ow:el.w,oh:el.h};
 }else{
  const base=selected.map(id=>{
   const e=elements.find(x=>x.id===id);
   return{id,x:e.x,y:e.y};
  });
  drag={kind:"move",x:ev.clientX,y:ev.clientY,base};
 }

 window.addEventListener("pointermove",moveElement);
 window.addEventListener("pointerup",endDrag,{once:true});
}

function moveElement(ev){
 if(!drag)return;
 const r=wrap.getBoundingClientRect();
 const dx=(ev.clientX-drag.x)/r.width*100;
 const dy=(ev.clientY-drag.y)/r.height*100;

 if(drag.kind==="move"){
  drag.base.forEach(b=>{
   const e=elements.find(x=>x.id===b.id);
   if(!e||e.type==="skip")return;
   e.x=Math.max(0,Math.min(100-e.w,b.x+dx));
   e.y=Math.max(0,Math.min(100-e.h,b.y+dy));
  });
 }else{
  const e=drag.el;
  let x=drag.ox,y=drag.oy,w=drag.ow,h=drag.oh;

  if(drag.resize.includes("e"))w=drag.ow+dx;
  if(drag.resize.includes("s"))h=drag.oh+dy;
  if(drag.resize.includes("w")){x=drag.ox+dx;w=drag.ow-dx}
  if(drag.resize.includes("n")){y=drag.oy+dy;h=drag.oh-dy}

  x=Math.max(0,Math.min(100-5,x));
  y=Math.max(0,Math.min(100-5,y));
  w=Math.max(5,Math.min(100-x,w));
  h=Math.max(5,Math.min(100-y,h));

  e.x=x;e.y=y;e.w=w;e.h=h;
 }

 renderOverlay();
}

function endDrag(){
 drag=null;
 window.removeEventListener("pointermove",moveElement);
}

function connectionPoint(e,pos){
 const x=e.x,y=e.y,w=e.w,h=e.h;
 return({
  tl:[x,y],t:[x+w/2,y],tr:[x+w,y],
  l:[x,y+h/2],r:[x+w,y+h/2],
  bl:[x,y+h],b:[x+w/2,y+h],br:[x+w,y+h]
 })[pos]||[x+w,y+h/2];
}

function rectHit(a,b,r,pad=1.8){
 const minX=Math.min(a[0],b[0])-pad;
 const maxX=Math.max(a[0],b[0])+pad;
 const minY=Math.min(a[1],b[1])-pad;
 const maxY=Math.max(a[1],b[1])+pad;
 return r.x<maxX&&r.x+r.w>minX&&r.y<maxY&&r.y+r.h>minY;
}

function segmentHitsRect(a,b,r,pad=1.8){
 const rr={x:r.x-pad,y:r.y-pad,w:r.w+pad*2,h:r.h+pad*2};
 const dx=b[0]-a[0],dy=b[1]-a[1];

 if(Math.abs(dx)<.0001){
  return a[0]>=rr.x&&a[0]<=rr.x+rr.w&&
   Math.max(a[1],b[1])>=rr.y&&Math.min(a[1],b[1])<=rr.y+rr.h;
 }

 if(Math.abs(dy)<.0001){
  return a[1]>=rr.y&&a[1]<=rr.y+rr.h&&
   Math.max(a[0],b[0])>=rr.x&&Math.min(a[0],b[0])<=rr.x+rr.w;
 }

 const steps=Math.max(8,Math.ceil(Math.max(Math.abs(dx),Math.abs(dy))*2));
 for(let i=0;i<=steps;i++){
  const t=i/steps,x=a[0]+dx*t,y=a[1]+dy*t;
  if(x>=rr.x&&x<=rr.x+rr.w&&y>=rr.y&&y<=rr.y+rr.h)return true;
 }
 return false;
}

function routeClear(points,obstacles){
 for(let i=0;i<points.length-1;i++){
  for(const r of obstacles){
   if(segmentHitsRect(points[i],points[i+1],r))return false;
  }
 }
 return true;
}

function makeOrthogonalRoute(p1,p2,a,b){
 const obstacles=elements.filter(e=>
  e.type!=="skip"&&e.id!==a.id&&e.id!==b.id&&e.w>0&&e.h>0
 );

 const mx=(p1[0]+p2[0])/2;
 const my=(p1[1]+p2[1])/2;
 const gap=4;

 const candidates=[
  [p1,[mx,p1[1]],[mx,p2[1]],p2],
  [p1,[p1[0],my],[p2[0],my],p2],
  [p1,[p1[0]+gap,p1[1]],[p1[0]+gap,p2[1]],[p2[0],p2[1]],p2],
  [p1,[p1[0]-gap,p1[1]],[p1[0]-gap,p2[1]],[p2[0],p2[1]],p2],
  [p1,[p1[0],p1[1]+gap],[p2[0],p1[1]+gap],[p2[0],p2[1]],p2],
  [p1,[p1[0],p1[1]-gap],[p2[0],p1[1]-gap],[p2[0],p2[1]],p2]
 ];

 let best=null,bestScore=Infinity;

 candidates.forEach(points=>{
  if(!routeClear(points,obstacles))return;
  let score=0;
  for(let i=0;i<points.length-1;i++){
   score+=Math.abs(points[i+1][0]-points[i][0])+
          Math.abs(points[i+1][1]-points[i][1]);
  }
  score+=points.length;
  if(score<bestScore){bestScore=score;best=points}
 });

 return best||[p1,p2];
}

function wavePath(points){
 let d=`M ${points[0][0]} ${points[0][1]}`;
 for(let s=0;s<points.length-1;s++){
  const a=points[s],b=points[s+1];
  const dx=b[0]-a[0],dy=b[1]-a[1];
  const len=Math.sqrt(dx*dx+dy*dy);
  if(len<.01)continue;

  const nx=-dy/len*1.3,ny=dx/len*1.3;
  const count=Math.max(2,Math.ceil(len/5));

  for(let i=1;i<=count;i++){
   const t=i/count;
   const amp=Math.sin(t*Math.PI*4)*1;
   d+=` L ${a[0]+dx*t+nx*amp} ${a[1]+dy*t+ny*amp}`;
  }
 }
 return d;
}

function pathForConnection(c,a,b){
 const p1=connectionPoint(a,c.fromPos);
 const p2=connectionPoint(b,c.toPos);

 if(c.style==="solid"||c.style==="dashed")
  return{d:`M ${p1[0]} ${p1[1]} L ${p2[0]} ${p2[1]}`,end:p2};

 const route=makeOrthogonalRoute(p1,p2,a,b);

 if(c.style==="wave")return{d:wavePath(route),end:p2};

 return{
  d:route.map((p,i)=>(i?"L ":"M ")+p[0]+" "+p[1]).join(" "),
  end:p2
 };
}

function renderConnections(){
 svg.innerHTML="";
 svg.setAttribute("viewBox","0 0 100 100");
 svg.setAttribute("preserveAspectRatio","none");

 connections.forEach(c=>{
  const a=elements.find(e=>e.id===c.from);
  const b=elements.find(e=>e.id===c.to);
  if(!a||!b)return;

  const info=pathForConnection(c,a,b);
  const selectedLine=selectedConnection===c.id;

  const hit=document.createElementNS("http://www.w3.org/2000/svg","path");
  hit.setAttribute("d",info.d);
  hit.setAttribute("class","connection-hit");
  hit.dataset.cid=c.id;

  hit.addEventListener("pointerdown",ev=>{
   ev.stopPropagation();
   selected=[];
   selectedConnection=c.id;
   renderConnections();renderRows();
  });

  hit.addEventListener("contextmenu",ev=>{
   ev.preventDefault();ev.stopPropagation();
   selected=[];
   selectedConnection=c.id;
   renderConnections();renderRows();
   showConnectionMenu(ev.clientX,ev.clientY,c.id);
  });

  svg.appendChild(hit);

  const path=document.createElementNS("http://www.w3.org/2000/svg","path");
  path.setAttribute("d",info.d);
  path.setAttribute("class","connection"+(selectedLine?" selected":""));

  if(c.style==="dashed"){
   path.setAttribute("stroke-dasharray","7 5");
  }

  svg.appendChild(path);

  if(selectedLine){
   [connectionPoint(a,c.fromPos),connectionPoint(b,c.toPos)].forEach((p,i)=>{
    const h=document.createElementNS("http://www.w3.org/2000/svg","circle");
    h.setAttribute("cx",p[0]);
    h.setAttribute("cy",p[1]);
    h.setAttribute("r","2.8");
    h.setAttribute("class","connection-handle");
    h.dataset.end=i?"to":"from";
    h.dataset.cid=c.id;
    h.addEventListener("pointerdown",startConnectionHandle);
    svg.appendChild(h);
   });
  }
 });
}

function startConnectionHandle(ev){
 ev.stopPropagation();
 const c=connections.find(x=>x.id===Number(ev.currentTarget.dataset.cid));
 if(!c)return;
 drag={kind:"connection",c,end:ev.currentTarget.dataset.end};
 window.addEventListener("pointermove",moveConnection);
 window.addEventListener("pointerup",endConnection,{once:true});
}

function moveConnection(ev){
 if(!drag)return;
 const r=wrap.getBoundingClientRect();
 const x=(ev.clientX-r.left)/r.width*100;
 const y=(ev.clientY-r.top)/r.height*100;

 const target=elements.filter(e=>e.type!=="skip").find(e=>
  x>=e.x&&x<=e.x+e.w&&y>=e.y&&y<=e.y+e.h
 );

 if(!target)return;

 const rx=(x-target.x)/target.w;
 const ry=(y-target.y)/target.h;
 let pos;

 if(ry<.25)pos=rx<.33?"tl":rx>.66?"tr":"t";
 else if(ry>.75)pos=rx<.33?"bl":rx>.66?"br":"b";
 else pos=rx<.5?"l":"r";

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
 drag=null;
 window.removeEventListener("pointermove",moveConnection);
}

function createConnection(){
 if(selected.length!==2){
  toastMsg("接続する要素を2つ選択してください");
  return;
 }

 const[from,to]=selected;
 if(from===to)return;

 if(connections.some(c=>
  (c.from===from&&c.to===to)||(c.from===to&&c.to===from))){
  toastMsg("この2つの要素はすでに接続されています");
  return;
 }

 const a=elements.find(e=>e.id===from);
 const b=elements.find(e=>e.id===to);

 let fromPos="r",toPos="l";
 if(a&&b){
  const ac=[a.x+a.w/2,a.y+a.h/2];
  const bc=[b.x+b.w/2,b.y+b.h/2];
  if(Math.abs(bc[0]-ac[0])>=Math.abs(bc[1]-ac[1])){
   fromPos=bc[0]>=ac[0]?"r":"l";
   toPos=bc[0]>=ac[0]?"l":"r";
  }else{
   fromPos=bc[1]>=ac[1]?"b":"t";
   toPos=bc[1]>=ac[1]?"t":"b";
  }
 }

 connections.push({
  id:nextId++,
  from,to,fromPos,toPos,
  style:"solid",
  startMarker:"none",
  endMarker:"none"
 });

 selected=[];
 selectedConnection=connections[connections.length-1].id;
 renderOverlay();renderRows();renderConnections();
 toastMsg("接続線を作成しました");
}

function showElementMenu(x,y,id){
 const e=elements.find(v=>v.id===id);
 if(!e)return;
 menuTarget={type:"element",id};

 let html=`<div class="menu-title">${e.label} の操作</div>`;

 if(e.type==="highlight"){
  html+=`<button data-action="shapeRect">四角形</button>
  <button data-action="shapeRound">角丸</button>
  <button data-action="shapeCircle">円形</button>`;
 }

 if(e.type==="comment")
  html+=`<button data-action="editComment">コメントを変更</button>`;

 if(selected.length>=2)
  html+=`<hr><button data-action="connect">選択した要素を接続</button>`;

 html+=`<hr><button data-action="delete">削除</button>`;
 openMenu(x,y,html);
}

function showConnectionMenu(x,y,id){
 const c=connections.find(v=>v.id===id);
 if(!c)return;

 menuTarget={type:"connection",id};

 openMenu(x,y,`
 <div class="menu-title">接続線の操作</div>
 <select id="lineStyle">
 <option value="solid" ${c.style==="solid"?"selected":""}>直線</option>
 <option value="orthogonal" ${c.style==="orthogonal"?"selected":""}>折れ線</option>
 <option value="wave" ${c.style==="wave"?"selected":""}>波線</option>
 <option value="dashed" ${c.style==="dashed"?"selected":""}>破線</option>
 </select>
 <hr>
 <div class="menu-title">終端</div>
 <select id="endMarker">
 <option value="none" ${c.endMarker==="none"?"selected":""}>なし</option>
 <option value="arrow" ${c.endMarker==="arrow"?"selected":""}>矢印</option>
 </select>
 <hr>
 <button data-action="deleteConnection">接続線を削除</button>`);

 setTimeout(()=>{
  const s=$("lineStyle");
  if(s)s.onchange=()=>{
   c.style=s.value;
   renderConnections();
  };

  const m=$("endMarker");
  if(m)m.onchange=()=>{
   c.endMarker=m.value;
   renderConnections();
  };
 },0);
}

function openMenu(x,y,html){
 menu.innerHTML=html;
 menu.style.display="block";
 menu.style.left=Math.min(x,innerWidth-230)+"px";
 menu.style.top=Math.min(y,innerHeight-260)+"px";
}

menu.addEventListener("click",ev=>{
 const a=ev.target.dataset.action;
 if(!a||!menuTarget)return;

 if(menuTarget.type==="element"){
  const e=elements.find(v=>v.id===menuTarget.id);
  if(!e)return;

  if(a==="shapeRect")e.shape="rect";
  if(a==="shapeRound")e.shape="round";
  if(a==="shapeCircle")e.shape="circle";

  if(a==="editComment"){
   const v=prompt("コメント",e.text);
   if(v!==null)e.text=v;
  }

  if(a==="connect")createConnection();

  if(a==="delete"){
   elements.splice(elements.findIndex(v=>v.id===e.id),1);
   connections=connections.filter(c=>c.from!==e.id&&c.to!==e.id);
   selected=[];
   selectedConnection=null;
  }

  renderOverlay();renderRows();renderConnections();
 }

 if(menuTarget.type==="connection"){
  const c=connections.find(v=>v.id===menuTarget.id);
  if(!c)return;

  if(a==="deleteConnection"){
   connections=connections.filter(v=>v.id!==c.id);
   selected=[];
   selectedConnection=null;
   renderConnections();renderRows();
  }
 }

 menu.style.display="none";
});

document.addEventListener("pointerdown",ev=>{
 if(!menu.contains(ev.target))menu.style.display="none";
});

function addElement(type,x,y){
 const r=wrap.getBoundingClientRect();
 const px=(x-r.left)/r.width*100;
 const py=(y-r.top)/r.height*100;

 /*
  * 追加時は動画領域内に必ず収める。
  * 大きすぎる要素を作らないよう、種類ごとの初期サイズを
  * 現在の表示領域に対して控えめに設定する。
  */
 const sizes={
  comment:{w:18,h:7},
  highlight:{w:22,h:13},
  zoombox:{w:18,h:11},
  skip:{w:12,h:5}
 };

 const size=sizes[type];

 const e={
  id:nextId++,
  type,
  label:type==="comment"?"コメント":
   type==="highlight"?"強調枠":
   type==="zoombox"?"拡大枠":"スキップ",
  start:Math.max(0,current-1),
  end:Math.min(duration,current+8),
  x:Math.max(0,Math.min(100-size.w,px-size.w/2)),
  y:Math.max(0,Math.min(100-size.h,py-size.h/2)),
  w:size.w,
  h:size.h,
  text:type==="comment"?"新しいコメント":"",
  shape:"rect"
 };

 elements.push(e);
 selected=[e.id];
 selectedConnection=null;
 renderOverlay();renderRows();
}

wrap.addEventListener("contextmenu",ev=>{
 ev.preventDefault();

 if(ev.target.closest(".element")||
    ev.target.closest(".connection-hit"))return;

 openMenu(ev.clientX,ev.clientY,`
 <div class="menu-title">要素を追加</div>
 <button data-add="comment">コメント</button>
 <button data-add="highlight">強調枠</button>
 <button data-add="zoombox">拡大枠</button>
 <button data-add="skip">スキップ</button>`);

 menuTarget={type:"add",x:ev.clientX,y:ev.clientY};
});

menu.addEventListener("click",ev=>{
 const type=ev.target.dataset.add;
 if(!type||!menuTarget||menuTarget.type!=="add")return;
 addElement(type,menuTarget.x,menuTarget.y);
 menu.style.display="none";
});

wrap.addEventListener("pointerdown",ev=>{
 if(ev.button===0&&
    !ev.target.closest(".element")&&
    !ev.target.closest(".connection-hit")){
  clearSelection();
 }
});

function renderAxis(){
 content.style.width=Math.max(700,Math.round(1000*scale))+"px";
 axis.innerHTML="";

 for(let i=0;i<=10;i++){
  const tick=document.createElement("div");
  tick.className="tick";
  tick.style.left=i*10+"%";
  tick.textContent=fmt(duration*i/10);
  axis.appendChild(tick);
 }
}

function lanesFor(items){
 const lanes=[];

 [...items].sort((a,b)=>a.start-b.start||a.end-b.end).forEach(e=>{
  let n=0;
  while(lanes[n]&&lanes[n].some(x=>e.start<x.end&&e.end>x.start))n++;
  (lanes[n]||(lanes[n]=[])).push(e);
  e._lane=n;
 });

 return lanes.length||1;
}

function renderRows(){
 rows.innerHTML="";

 [
  ["comment","コメント","#4da3ff"],
  ["highlight","強調枠","#f04444"],
  ["zoombox","拡大枠","#111"],
  ["skip","スキップ","#ff9800"]
 ].forEach(([type,label,color])=>{
  const items=elements.filter(e=>e.type===type);
  const count=lanesFor(items);
  const h=count*52;

  const row=document.createElement("div");
  row.className="row";
  row.style.height=h+"px";

  const labelEl=document.createElement("div");
  labelEl.className="row-label";
  labelEl.style.height=h+"px";
  labelEl.innerHTML=`<span class="dot" style="background:${color}"></span>${label}`;
  row.appendChild(labelEl);

  const lane=document.createElement("div");
  lane.className="lane";
  lane.style.height=h+"px";

  items.forEach(e=>{
   const bar=document.createElement("div");
   bar.className="bar"+
    (selected.includes(e.id)?" selected":"")+
    (selected.length>1&&selected.includes(e.id)?" multi":"");

   bar.style.color=colors[e.type];
   bar.style.left=e.start/duration*100+"%";
   bar.style.width=(e.end-e.start)/duration*100+"%";
   bar.style.top=9+e._lane*52+"px";
   bar.dataset.id=e.id;

   bar.innerHTML=`<span>${e.label}</span>
   <i class="bar-handle left"></i>
   <i class="bar-handle right"></i>`;

   bar.addEventListener("pointerdown",ev=>{
    if(ev.button!==0)return;
    ev.stopPropagation();

    if(!selected.includes(e.id))selectElement(e.id,ev);

    drag={
     kind:ev.target.classList.contains("bar-handle")?
      "timelineResize":"timelineMove",
     el:e,
     x:ev.clientX,
     start:e.start,
     end:e.end,
     side:ev.target.classList.contains("left")?"left":"right"
    };

    window.addEventListener("pointermove",timelineDrag);
    window.addEventListener("pointerup",endTimelineDrag,{once:true});
   });

   bar.addEventListener("contextmenu",ev=>{
    ev.preventDefault();ev.stopPropagation();
    if(!selected.includes(e.id))selectElement(e.id,ev);
    showElementMenu(ev.clientX,ev.clientY,e.id);
   });

   lane.appendChild(bar);
  });

  row.appendChild(lane);
  rows.appendChild(row);
 });
}

function timelineDrag(ev){
 if(!drag)return;

 const bar=ev.target.closest(".bar")||rows.querySelector(".bar");
 const lane=bar?bar.parentElement:null;
 if(!lane)return;

 const r=lane.getBoundingClientRect();
 const dt=(ev.clientX-drag.x)/r.width*duration;

 if(drag.kind==="timelineMove"){
  const len=drag.end-drag.start;
  drag.el.start=Math.max(0,Math.min(duration-len,drag.start+dt));
  drag.el.end=drag.el.start+len;
 }else if(drag.side==="left"){
  drag.el.start=Math.max(0,Math.min(drag.end-.2,drag.start+dt));
 }else{
  drag.el.end=Math.min(duration,Math.max(drag.start+.2,drag.end+dt));
 }

 renderRows();renderOverlay();
}

function endTimelineDrag(){
 drag=null;
 window.removeEventListener("pointermove",timelineDrag);
}

function updateTime(){
 current=Math.max(0,Math.min(duration,video.currentTime||0));
 $("time").textContent=fmt(current)+" / "+fmt(duration);
 playhead.style.left="calc(100px + "+(duration?current/duration*100:0)+"%)";
 renderOverlay();

 const skip=elements.find(e=>
  e.type==="skip"&&current>=e.start&&current<e.end);

 if(skip&&!video.paused)video.currentTime=skip.end;
}

function dataForSave(){
 return{
  version:3,
  duration,
  elements:elements.map(e=>{
   const x={...e};
   delete x._lane;
   return x;
  }),
  connections:connections.map(c=>({...c}))
 };
}

function saveData(){
 try{
  localStorage.setItem(SAVE_KEY,JSON.stringify(dataForSave()));
  toastMsg("編集結果を保存しました");
  $("status").textContent="編集結果を保存済み";
 }catch(e){
  toastMsg("保存できませんでした");
 }
}

function loadSavedData(){
 try{
  const raw=localStorage.getItem(SAVE_KEY);
  if(!raw)return false;

  const data=JSON.parse(raw);
  if(!data||!Array.isArray(data.elements))return false;

  elements.splice(0,elements.length,...data.elements);
  connections=Array.isArray(data.connections)?data.connections:[];
  duration=Number(data.duration)||duration;

  nextId=elements.reduce((m,e)=>Math.max(m,e.id||0),0);
  nextId=connections.reduce((m,c)=>Math.max(m,c.id||0),nextId)+1;

  selected=[];
  selectedConnection=null;

  renderAxis();
  renderRows();
  renderOverlay();
  updateTime();

  $("status").textContent="保存した編集結果を読み込みました";
  return true;
 }catch(e){
  return false;
 }
}

function exportData(){
 const blob=new Blob(
  [JSON.stringify(dataForSave(),null,2)],
  {type:"application/json"}
 );
 const a=document.createElement("a");
 a.href=URL.createObjectURL(blob);
 a.download="video-edit-data.json";
 a.click();
 setTimeout(()=>URL.revokeObjectURL(a.href),1000);
 toastMsg("編集結果を書き出しました");
}

video.addEventListener("loadedmetadata",()=>{
 duration=video.duration||60;
 $("status").textContent="動画編集中";
 $("hint").style.display="none";
 renderAxis();renderRows();updateTime();
});

video.addEventListener("timeupdate",updateTime);
video.addEventListener("ended",()=>$("playBtn").textContent="▶");

$("loadBtn").onclick=()=>$("fileInput").click();

$("fileInput").onchange=ev=>{
 const file=ev.target.files[0];
 if(!file)return;
 video.src=URL.createObjectURL(file);
 video.load();
};

$("saveBtn").onclick=saveData;
$("exportBtn").onclick=exportData;

$("playBtn").onclick=()=>{
 if(!video.src){
  toastMsg("先に動画を読み込んでください");
  return;
 }

 if(video.paused){
  video.play();
  $("playBtn").textContent="⏸";
 }else{
  video.pause();
  $("playBtn").textContent="▶";
 }
};

$("stopBtn").onclick=()=>{
 video.pause();
 video.currentTime=0;
 $("playBtn").textContent="▶";
};

$("scale").oninput=ev=>{
 scale=Number(ev.target.value);
 $("scaleText").textContent=scale.toFixed(1)+"×";
 renderAxis();renderRows();updateTime();
};

axis.addEventListener("pointerdown",ev=>{
 const r=axis.getBoundingClientRect();
 video.currentTime=
  Math.max(0,Math.min(1,(ev.clientX-r.left)/r.width))*duration;
});

rows.addEventListener("dblclick",ev=>{
 const lane=ev.target.closest(".lane");
 if(!lane)return;

 const r=lane.getBoundingClientRect();
 video.currentTime=
  Math.max(0,Math.min(duration,(ev.clientX-r.left)/r.width*duration));
});

document.addEventListener("keydown",ev=>{
 if(ev.key==="Escape"){
  selected=[];
  selectedConnection=null;
  renderOverlay();
  renderRows();
  renderConnections();
  menu.style.display="none";
 }

 if((ev.ctrlKey||ev.metaKey)&&ev.key.toLowerCase()==="s"){
  ev.preventDefault();
  saveData();
 }
});

loadSavedData();
renderAxis();
renderRows();
renderOverlay();
updateTime();
</script>
</body>
</html>

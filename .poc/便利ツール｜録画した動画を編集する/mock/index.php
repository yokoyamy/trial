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
button,input,select{font:inherit}
button{cursor:pointer}
.app{height:100%;display:flex;flex-direction:column}
.header{height:52px;flex:none;display:flex;align-items:center;gap:8px;padding:0 12px;background:#161b22;border-bottom:1px solid #30363d}
.title{font-weight:700;margin-right:10px}
.btn{border:1px solid #3b4654;background:#242c36;color:#e8edf5;border-radius:6px;padding:7px 11px}
.btn:hover{background:#303a47}
.primary{background:#1769aa;border-color:#2387d9}
.status{margin-left:auto;color:#8b949e;font-size:12px}
.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{min-height:0;flex:1;background:#05070a;display:flex;align-items:center;justify-content:center;padding:12px}
.video-wrap{position:relative;width:min(1200px,100%);height:100%;background:#000;overflow:hidden}
video{width:100%;height:100%;object-fit:contain;display:block}
.svg-layer{position:absolute;inset:0;width:100%;height:100%;z-index:10;overflow:visible}
.connection{fill:none;stroke:#ff5252;stroke-width:3;pointer-events:stroke;cursor:pointer}
.connection.selected{stroke:#4da3ff;stroke-width:6}
.connection-handle{fill:#fff;stroke:#4da3ff;stroke-width:3;cursor:crosshair}
.overlay{position:absolute;inset:0;z-index:20;pointer-events:none}
.element{position:absolute;pointer-events:auto;user-select:none;touch-action:none;min-width:40px;min-height:25px;cursor:move}
.element.selected{outline:2px solid #4da3ff;outline-offset:3px}
.element.multi{outline:2px solid #ffc107;outline-offset:3px}
.element-body{width:100%;height:100%;display:flex;align-items:center;justify-content:center;overflow:hidden}
.comment .element-body{color:#fff;text-shadow:0 1px 4px #000;font-size:18px;padding:4px}
.highlight .element-body{border:4px solid #f04444;background:transparent}
.highlight.round .element-body{border-radius:18px}
.highlight.circle .element-body{border-radius:50%}
.zoombox .element-body{border:3px solid #111;background:#1113}
.skip .element-body{border:2px dashed #ff9800;background:#ff980033}
.handle{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #4da3ff;z-index:5}
.nw{left:-5px;top:-5px;cursor:nwse-resize}
.ne{right:-5px;top:-5px;cursor:nesw-resize}
.sw{left:-5px;bottom:-5px;cursor:nesw-resize}
.se{right:-5px;bottom:-5px;cursor:nwse-resize}

.timeline{height:255px;flex:none;background:#11161d;border-top:1px solid #30363d;display:flex;flex-direction:column}
.timeline-toolbar{height:42px;flex:none;display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid #30363d}
.time{font-variant-numeric:tabular-nums;min-width:150px;color:#dbeafe}
.scale{margin-left:auto;display:flex;align-items:center;gap:7px;color:#8b949e;font-size:12px}
.scale input{width:120px}
.timeline-scroll{min-height:0;flex:1;overflow:auto}
.timeline-content{position:relative;height:100%}
.axis{height:30px;position:relative;margin-left:100px;background:#171d25;border-bottom:1px solid #30363d}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #343b45;color:#7d8590;font-size:10px;padding:4px 0 0 3px}
.rows{margin-left:100px}
.row{height:52px;position:relative;border-bottom:1px solid #242b33}
.row-label{position:absolute;right:100%;top:0;width:100px;height:52px;display:flex;align-items:center;padding:0 8px;gap:5px;background:#161b22;border-right:1px solid #30363d;font-size:11px}
.dot{width:8px;height:8px;border-radius:50%}
.lane{height:100%;position:relative}
.bar{position:absolute;top:9px;height:34px;border-radius:5px;border:1px solid currentColor;display:flex;align-items:center;cursor:grab;user-select:none;touch-action:none;overflow:visible}
.bar.selected{box-shadow:0 0 0 2px #4da3ff}
.bar.multi{box-shadow:0 0 0 2px #ffc107}
.bar span{font-size:10px;padding:0 7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar-handle{position:absolute;top:0;height:100%;width:8px;cursor:ew-resize}
.bar-handle.left{left:-4px}
.bar-handle.right{right:-4px}
.playhead{position:absolute;top:30px;bottom:0;width:2px;background:#f04444;z-index:100;pointer-events:none}
.playhead:before{content:"";position:absolute;top:-2px;left:-5px;width:12px;height:12px;background:#f04444;clip-path:polygon(0 0,100% 0,50% 100%)}

.menu{position:fixed;z-index:1000;display:none;min-width:210px;background:#1c2531;border:1px solid #46515f;border-radius:7px;box-shadow:0 12px 30px #0009;padding:5px}
.menu-title{font-size:11px;color:#8b949e;padding:6px 8px}
.menu button{display:block;width:100%;text-align:left;border:0;background:transparent;color:#e8edf5;padding:8px;border-radius:4px}
.menu button:hover{background:#2b3745}
.menu select{width:100%;background:#242c36;color:#fff;border:1px solid #46515f;padding:6px;border-radius:4px}
.menu hr{border:0;border-top:1px solid #303b48;margin:4px 0}
.hint{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);color:#66717f;text-align:center;pointer-events:none}
.toast{position:fixed;right:15px;bottom:15px;background:#202938;border:1px solid #46515f;border-radius:6px;padding:9px 13px;display:none;z-index:2000}
</style>
</head>

<body>
<div class="app">
<header class="header">
  <div class="title">動画編集・注釈</div>
  <button class="btn primary" id="loadBtn">動画を選択</button>
  <input id="fileInput" type="file" accept="video/*" hidden>
  <button class="btn" id="newBtn">新規</button>
  <div class="status" id="status">動画を選択してください</div>
</header>

<main class="main">
<section class="video-area">
  <div class="video-wrap" id="videoWrap">
    <video id="video" playsinline preload="metadata"></video>
    <svg id="svg" class="svg-layer"></svg>
    <div id="overlay" class="overlay"></div>
    <div id="hint" class="hint">動画を選択してください</div>
  </div>
</section>

<section class="timeline">
  <div class="timeline-toolbar">
    <button class="btn" id="playBtn">▶</button>
    <button class="btn" id="stopBtn">■</button>
    <div class="time" id="time">00:00.000 / 00:00.000</div>
    <div class="scale">
      時間スケール
      <input id="scale" type="range" min="1" max="5" step=".1" value="1">
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

let duration=60;
let current=0;
let scale=1;
let selected=[];
let menuTarget=null;
let drag=null;
let nextId=10;

const elements=[
 {id:1,type:"comment",label:"コメント",start:5,end:18,x:8,y:10,w:22,h:10,text:"ここを確認してください"},
 {id:2,type:"highlight",label:"強調枠",start:8,end:24,x:45,y:30,w:25,h:28,shape:"rect"},
 {id:3,type:"zoombox",label:"拡大枠",start:20,end:35,x:18,y:55,w:22,h:22,shape:"rect"},
 {id:4,type:"skip",label:"スキップ",start:38,end:45,x:0,y:0,w:0,h:0}
];

let connections=[
 {id:5,from:1,to:2,fromPos:"r",toPos:"l",style:"solid",startArrow:false,endArrow:true},
 {id:6,from:2,to:3,fromPos:"b",toPos:"t",style:"orthogonal",startArrow:false,endArrow:true}
];

function fmt(t){
 t=Math.max(0,t||0);
 const m=Math.floor(t/60),s=t%60;
 return String(m).padStart(2,"0")+":"+s.toFixed(3).padStart(6,"0");
}

function toastMsg(s){
 toast.textContent=s;
 toast.style.display="block";
 clearTimeout(toastMsg.t);
 toastMsg.t=setTimeout(()=>toast.style.display="none",1800);
}

function activeElements(){
 return elements.filter(e=>{
  if(e.type==="skip")return false;
  return current>=e.start && current<e.end;
 });
}

function videoPosition(e){
 return {left:e.x+"%",top:e.y+"%",width:e.w+"%",height:e.h+"%"};
}

function selectElement(id,ev){
 const multi=ev&&(ev.shiftKey||ev.ctrlKey||ev.metaKey);
 if(multi){
  selected=selected.includes(id)
   ?selected.filter(x=>x!==id)
   :selected.concat(id);
 }else{
  selected=[id];
 }
 renderOverlay();
 renderRows();
 showSelectionMenu();
}

function selectConnection(id){
 selected=[id];
 renderOverlay();
 renderRows();
}

function clearSelection(){
 selected=[];
 menu.style.display="none";
 renderOverlay();
 renderRows();
}

function renderOverlay(){
 overlay.innerHTML="";

 activeElements().forEach(e=>{
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
  d.addEventListener("click",ev=>{
   ev.stopPropagation();
   selectElement(e.id,ev);
  });

  overlay.appendChild(d);
 });

 renderConnections();
}

function startElement(ev){
 if(ev.button!==0)return;
 ev.stopPropagation();

 const e=elements.find(x=>x.id===Number(ev.currentTarget.dataset.id));
 if(!e)return;

 const resize=ev.target.dataset.resize||null;

 if(!selected.includes(e.id))selectElement(e.id,ev);

 drag={
  kind:resize?"resize":"move",
  el:e,
  x:ev.clientX,
  y:ev.clientY,
  ox:e.x,
  oy:e.y,
  ow:e.w,
  oh:e.h,
  resize
 };

 window.addEventListener("pointermove",moveElement);
 window.addEventListener("pointerup",endElementDrag,{once:true});
}

function moveElement(ev){
 if(!drag)return;

 const r=wrap.getBoundingClientRect();
 const dx=(ev.clientX-drag.x)/r.width*100;
 const dy=(ev.clientY-drag.y)/r.height*100;

 if(drag.kind==="move"){
  const ids=selected.length?selected:[drag.el.id];

  ids.forEach(id=>{
   const e=elements.find(x=>x.id===id);
   if(!e||e.type==="skip")return;

   if(e._bx===undefined){
    e._bx=e.x;
    e._by=e.y;
   }

   e.x=Math.max(0,Math.min(100-e.w,e._bx+dx));
   e.y=Math.max(0,Math.min(100-e.h,e._by+dy));
  });
 }else{
  const e=drag.el;
  let x=drag.ox,y=drag.oy,w=drag.ow,h=drag.oh;

  if(drag.resize.includes("e"))w=drag.ow+dx;
  if(drag.resize.includes("s"))h=drag.oh+dy;

  if(drag.resize.includes("w")){
   x=drag.ox+dx;
   w=drag.ow-dx;
  }

  if(drag.resize.includes("n")){
   y=drag.oy+dy;
   h=drag.oh-dy;
  }

  e.x=Math.max(0,Math.min(95,x));
  e.y=Math.max(0,Math.min(95,y));
  e.w=Math.max(5,Math.min(100-e.x,w));
  e.h=Math.max(5,Math.min(100-e.y,h));
 }

 renderOverlay();
}

function endElementDrag(){
 elements.forEach(e=>{
  delete e._bx;
  delete e._by;
 });
 drag=null;
 window.removeEventListener("pointermove",moveElement);
}

function connectionPoint(e,pos){
 const x=e.x,y=e.y,w=e.w,h=e.h;
 return {
  tl:[x,y],t:[x+w/2,y],tr:[x+w,y],
  l:[x,y+h/2],r:[x+w,y+h/2],
  bl:[x,y+h],b:[x+w/2,y+h],br:[x+w,y+h]
 }[pos]||[x+w,y+h/2];
}

function connectionPath(c){
 const a=elements.find(e=>e.id===c.from);
 const b=elements.find(e=>e.id===c.to);
 if(!a||!b)return null;

 const p1=connectionPoint(a,c.fromPos);
 const p2=connectionPoint(b,c.toPos);

 if(c.style==="orthogonal"){
  const mx=(p1[0]+p2[0])/2;
  return `M ${p1[0]} ${p1[1]} L ${mx} ${p1[1]} L ${mx} ${p2[1]} L ${p2[0]} ${p2[1]}`;
 }

 if(c.style==="wave"){
  const dx=p2[0]-p1[0],dy=p2[1]-p1[1];
  const len=Math.sqrt(dx*dx+dy*dy)||1;
  const nx=-dy/len*2,ny=dx/len*2;
  let d=`M ${p1[0]} ${p1[1]}`;
  for(let i=1;i<=20;i++){
   const t=i/20;
   const x=p1[0]+dx*t+nx*Math.sin(t*Math.PI*6);
   const y=p1[1]+dy*t+ny*Math.sin(t*Math.PI*6);
   d+=` L ${x} ${y}`;
  }
  return d;
 }

 return `M ${p1[0]} ${p1[1]} L ${p2[0]} ${p2[1]}`;
}

function renderConnections(){
 svg.innerHTML="";
 svg.setAttribute("viewBox","0 0 100 100");
 svg.setAttribute("preserveAspectRatio","none");

 connections.forEach(c=>{
  const d=connectionPath(c);
  if(!d)return;

  const path=document.createElementNS("http://www.w3.org/2000/svg","path");
  path.setAttribute("d",d);
  path.setAttribute("class","connection"+(
   selected.length===1&&selected[0]===c.id?" selected":""
  ));
  path.dataset.cid=c.id;

  if(c.style==="dashed")path.setAttribute("stroke-dasharray","8 6");

  if(c.endArrow){
   path.setAttribute("marker-end","url(#arrow)");
  }

  path.addEventListener("click",ev=>{
   ev.stopPropagation();
   selectConnection(c.id);
  });

  path.addEventListener("contextmenu",ev=>{
   ev.preventDefault();
   ev.stopPropagation();
   selectConnection(c.id);
   showConnectionMenu(ev.clientX,ev.clientY,c.id);
  });

  svg.appendChild(path);

  if(selected.length===1&&selected[0]===c.id){
   const a=elements.find(e=>e.id===c.from);
   const b=elements.find(e=>e.id===c.to);
   if(!a||!b)return;

   [connectionPoint(a,c.fromPos),connectionPoint(b,c.toPos)].forEach((p,i)=>{
    const h=document.createElementNS("http://www.w3.org/2000/svg","circle");
    h.setAttribute("cx",p[0]);
    h.setAttribute("cy",p[1]);
    h.setAttribute("r","2.3");
    h.setAttribute("class","connection-handle");
    h.dataset.cid=c.id;
    h.dataset.end=i?"to":"from";
    h.addEventListener("pointerdown",startConnectionHandle);
    svg.appendChild(h);
   });
  }
 });

 if(!svg.querySelector("#arrow")){
  const defs=document.createElementNS("http://www.w3.org/2000/svg","defs");
  defs.innerHTML='<marker id="arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#ff5252"/></marker>';
  svg.prepend(defs);
 }
}

function startConnectionHandle(ev){
 ev.stopPropagation();

 const c=connections.find(x=>x.id===Number(ev.currentTarget.dataset.cid));
 if(!c)return;

 drag={
  kind:"connection",
  c,
  end:ev.currentTarget.dataset.end
 };

 window.addEventListener("pointermove",moveConnection);
 window.addEventListener("pointerup",endConnection,{once:true});
}

function moveConnection(ev){
 if(!drag)return;

 const r=wrap.getBoundingClientRect();
 const x=(ev.clientX-r.left)/r.width*100;
 const y=(ev.clientY-r.top)/r.height*100;

 const target=elements.find(e=>
  e.type!=="skip" &&
  x>=e.x && x<=e.x+e.w &&
  y>=e.y && y<=e.y+e.h
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

function showSelectionMenu(){
 if(selected.length===0){
  menu.style.display="none";
  return;
 }

 if(selected.length>1){
  openMenu(
   Math.min(innerWidth-240,Math.max(10,innerWidth-250)),
   70,
   `<div class="menu-title">${selected.length}個を選択中</div>
    <button data-action="connect">選択した要素を接続</button>
    <button data-action="deleteSelected">選択した要素を削除</button>`
  );
  menuTarget={type:"multi"};
  return;
 }

 const id=selected[0];
 const e=elements.find(x=>x.id===id);
 const c=connections.find(x=>x.id===id);

 if(e)showElementMenu(innerWidth-235,65,id);
 else if(c)showConnectionMenu(innerWidth-235,65,id);
}

function showElementMenu(x,y,id){
 const e=elements.find(v=>v.id===id);
 if(!e)return;

 menuTarget={type:"element",id};

 let html=`<div class="menu-title">${e.label} の操作</div>`;

 if(e.type==="highlight"){
  html+=`
   <button data-action="shapeRect">四角形</button>
   <button data-action="shapeRound">角丸</button>
   <button data-action="shapeCircle">円形</button>`;
 }

 if(e.type==="comment"){
  html+=`<button data-action="editComment">コメントを変更</button>`;
 }

 if(e.type==="zoombox"){
  html+=`<button data-action="zoomInfo">拡大枠を確認</button>`;
 }

 if(e.type==="skip"){
  html+=`<button data-action="skipInfo">スキップ範囲を確認</button>`;
 }

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
  </select>
  <hr>
  <button data-action="startHandle">始点の接続位置を変更</button>
  <button data-action="endHandle">終点の接続位置を変更</button>
  <button data-action="toggleArrow">終端を${c.endArrow?"なし":"矢印"}に変更</button>
  <button data-action="deleteConnection">接続線を削除</button>
 `);

 const select=$("lineStyle");
 if(select){
  select.onchange=()=>{
   c.style=select.value;
   renderConnections();
  };
 }
}

function openMenu(x,y,html){
 menu.innerHTML=html;
 menu.style.display="block";
 menu.style.left=Math.max(5,Math.min(x,innerWidth-225))+"px";
 menu.style.top=Math.max(5,Math.min(y,innerHeight-280))+"px";
}

menu.addEventListener("click",ev=>{
 const a=ev.target.dataset.action;
 if(!a)return;

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

  if(a==="delete"){
   elements.splice(elements.findIndex(v=>v.id===e.id),1);
   connections=connections.filter(c=>c.from!==e.id&&c.to!==e.id);
   selected=[];
  }

  renderOverlay();
  renderRows();
 }

 if(menuTarget.type==="multi"){
  if(a==="connect"){
   if(selected.length>=2){
    const from=selected[0],to=selected[1];
    connections.push({
     id:nextId++,
     from,to,
     fromPos:"r",
     toPos:"l",
     style:"solid",
     endArrow:true
    });
    selected=[connections.at(-1).id];
   }
  }

  if(a==="deleteSelected"){
   elements=elements.filter(e=>!selected.includes(e.id));
   selected=[];
  }

  renderOverlay();
  renderRows();
 }

 if(menuTarget.type==="connection"){
  const c=connections.find(v=>v.id===menuTarget.id);
  if(!c)return;

  if(a==="startHandle"){
   toastMsg("接続線の始点を動画上の要素へドラッグしてください");
  }

  if(a==="endHandle"){
   toastMsg("接続線の終点を動画上の要素へドラッグしてください");
  }

  if(a==="toggleArrow"){
   c.endArrow=!c.endArrow;
  }

  if(a==="deleteConnection"){
   connections=connections.filter(v=>v.id!==c.id);
   selected=[];
  }

  renderConnections();
 }

 menu.style.display="none";
});

function addElement(type,time,x,y){
 const defaults={
  comment:[22,10],
  highlight:[25,28],
  zoombox:[22,22],
  skip:[0,0]
 };

 const [w,h]=defaults[type];
 const start=Math.max(0,Math.min(duration-.2,time));
 const end=type==="skip"
  ?Math.min(duration,start+7)
  :Math.min(duration,start+8);

 const r=wrap.getBoundingClientRect();
 const px=x==null?35:(x-r.left)/r.width*100;
 const py=y==null?35:(y-r.top)/r.height*100;

 const e={
  id:nextId++,
  type,
  label:{comment:"コメント",highlight:"強調枠",zoombox:"拡大枠",skip:"スキップ"}[type],
  start,
  end,
  x:type==="skip"?0:Math.max(0,Math.min(100-w,px-w/2)),
  y:type==="skip"?0:Math.max(0,Math.min(100-h,py-h/2)),
  w,h,
  text:type==="comment"?"新しいコメント":"",
  shape:"rect"
 };

 elements.push(e);
 selected=[e.id];
 renderOverlay();
 renderRows();
 showSelectionMenu();
}

wrap.addEventListener("contextmenu",ev=>{
 ev.preventDefault();

 if(ev.target.closest(".element")||ev.target.closest(".connection"))return;

 openMenu(ev.clientX,ev.clientY,`
  <div class="menu-title">要素を追加</div>
  <button data-add="comment">コメント</button>
  <button data-add="highlight">強調枠</button>
  <button data-add="zoombox">拡大枠</button>
  <button data-add="skip">スキップ</button>
 `);

 menuTarget={type:"addVideo",x:ev.clientX,y:ev.clientY};
});

rows.addEventListener("contextmenu",ev=>{
 ev.preventDefault();

 const lane=ev.target.closest(".lane");
 if(!lane)return;

 const r=lane.getBoundingClientRect();
 const t=Math.max(0,Math.min(duration,
  (ev.clientX-r.left)/r.width*duration
 ));

 openMenu(ev.clientX,ev.clientY,`
  <div class="menu-title">${fmt(t)} に要素を追加</div>
  <button data-add="comment">コメント</button>
  <button data-add="highlight">強調枠</button>
  <button data-add="zoombox">拡大枠</button>
  <button data-add="skip">スキップ</button>
 `);

 menuTarget={type:"addTimeline",time:t};
});

menu.addEventListener("click",ev=>{
 const type=ev.target.dataset.add;
 if(!type)return;

 if(menuTarget.type==="addVideo"){
  addElement(type,0,menuTarget.x,menuTarget.y);
 }

 if(menuTarget.type==="addTimeline"){
  addElement(type,menuTarget.time);
 }

 menu.style.display="none";
});

wrap.addEventListener("pointerdown",ev=>{
 if(ev.button===0&&!ev.target.closest(".element")&&!ev.target.closest(".connection")){
  clearSelection();
 }
});

function timelineWidth(){
 return Math.max(900,Math.round(900*scale));
}

function renderAxis(){
 const width=timelineWidth();
 content.style.width=width+"px";

 axis.innerHTML="";
 for(let i=0;i<=10;i++){
  const tick=document.createElement("div");
  tick.className="tick";
  tick.style.left=(i*10)+"%";
  tick.textContent=fmt(duration*i/10);
  axis.appendChild(tick);
 }
}

function renderRows(){
 rows.innerHTML="";

 const defs=[
  ["comment","コメント","#4da3ff"],
  ["highlight","強調枠","#f04444"],
  ["zoombox","拡大枠","#111"],
  ["skip","スキップ","#ff9800"]
 ];

 defs.forEach(([type,label,color])=>{
  const row=document.createElement("div");
  row.className="row";

  const labelEl=document.createElement("div");
  labelEl.className="row-label";
  labelEl.innerHTML=`<span class="dot" style="background:${color}"></span>${label}`;
  row.appendChild(labelEl);

  const lane=document.createElement("div");
  lane.className="lane";

  elements.filter(e=>e.type===type).forEach(e=>{
   const bar=document.createElement("div");
   bar.className="bar"+
    (selected.includes(e.id)?" selected":"")+
    (selected.length>1&&selected.includes(e.id)?" multi":"");

   bar.style.color=color;
   bar.style.left=(e.start/duration*100)+"%";
   bar.style.width=Math.max(.5,(e.end-e.start)/duration*100)+"%";
   bar.dataset.id=e.id;

   bar.innerHTML=`<span>${e.label}</span>
    <i class="bar-handle left"></i>
    <i class="bar-handle right"></i>`;

   bar.addEventListener("pointerdown",ev=>{
    if(ev.button!==0)return;
    ev.stopPropagation();

    const resize=ev.target.classList.contains("bar-handle");

    selectElement(e.id,ev);

    drag={
     kind:resize?"timelineResize":"timelineMove",
     el:e,
     x:ev.clientX,
     start:e.start,
     end:e.end,
     side:ev.target.classList.contains("left")?"left":"right"
    };

    window.addEventListener("pointermove",timelineDrag);
    window.addEventListener("pointerup",endTimelineDrag,{once:true});
   });

   lane.appendChild(bar);
  });

  row.appendChild(lane);
  rows.appendChild(row);
 });
}

function timelineDrag(ev){
 if(!drag)return;

 const lane=rows.querySelector(".lane");
 if(!lane)return;

 const r=lane.getBoundingClientRect();
 const dt=(ev.clientX-drag.x)/r.width*duration;

 if(drag.kind==="timelineMove"){
  const len=drag.end-drag.start;
  drag.el.start=Math.max(0,Math.min(duration-len,drag.start+dt));
  drag.el.end=drag.el.start+len;
 }else{
  if(drag.side==="left"){
   drag.el.start=Math.max(0,Math.min(drag.end-.2,drag.start+dt));
  }else{
   drag.el.end=Math.min(duration,Math.max(drag.start+.2,drag.end+dt));
  }
 }

 renderRows();
 renderOverlay();
}

function endTimelineDrag(){
 drag=null;
 window.removeEventListener("pointermove",timelineDrag);
}

function setCurrent(t){
 current=Math.max(0,Math.min(duration,t));

 if(video.src){
  video.currentTime=current;
 }

 updateTime();
}

function updateTime(){
 current=Math.max(0,Math.min(duration,video.currentTime||current));
 $("time").textContent=fmt(current)+" / "+fmt(duration);

 const pct=duration?current/duration:0;
 playhead.style.left=`calc(100px + ${pct*100}% )`;

 renderOverlay();

 const skip=elements.find(e=>
  e.type==="skip"&&current>=e.start&&current<e.end
 );

 if(skip&&!video.paused){
  video.currentTime=skip.end;
 }
}

video.addEventListener("loadedmetadata",()=>{
 duration=Number.isFinite(video.duration)?video.duration:60;
 current=0;

 $("status").textContent="動画編集中";
 $("hint").style.display="none";

 renderAxis();
 renderRows();
 updateTime();
});

video.addEventListener("timeupdate",updateTime);
video.addEventListener("ended",()=>{
 $("playBtn").textContent="▶";
 updateTime();
});

$("loadBtn").onclick=()=>$("fileInput").click();

$("fileInput").onchange=ev=>{
 const file=ev.target.files[0];
 if(!file)return;

 video.src=URL.createObjectURL(file);
 video.load();
};

$("newBtn").onclick=()=>{
 video.pause();
 video.removeAttribute("src");
 video.load();

 selected=[];
 current=0;
 duration=60;

 connections=[
  {id:5,from:1,to:2,fromPos:"r",toPos:"l",style:"solid",endArrow:true},
  {id:6,from:2,to:3,fromPos:"b",toPos:"t",style:"orthogonal",endArrow:true}
 ];

 $("status").textContent="動画を選択してください";
 $("hint").style.display="block";

 renderAxis();
 renderRows();
 renderOverlay();
 updateTime();
};

$("playBtn").onclick=()=>{
 if(!video.src){
  toastMsg("先に動画を選択してください");
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
 current=0;
 $("playBtn").textContent="▶";
 updateTime();
};

$("scale").oninput=ev=>{
 scale=Number(ev.target.value);
 $("scaleText").textContent=scale.toFixed(1)+"×";

 const before=current;
 renderAxis();
 renderRows();
 setCurrent(before);
};

axis.addEventListener("pointerdown",ev=>{
 const r=axis.getBoundingClientRect();
 const ratio=Math.max(0,Math.min(1,(ev.clientX-r.left)/r.width));
 setCurrent(ratio*duration);
});

rows.addEventListener("pointerdown",ev=>{
 if(ev.button!==0)return;

 const bar=ev.target.closest(".bar");
 if(bar)return;

 const lane=ev.target.closest(".lane");
 if(!lane)return;

 const r=lane.getBoundingClientRect();
 const t=Math.max(0,Math.min(duration,
  (ev.clientX-r.left)/r.width*duration
 ));

 setCurrent(t);
});

rows.addEventListener("dblclick",ev=>{
 const lane=ev.target.closest(".lane");
 if(!lane)return;

 const r=lane.getBoundingClientRect();
 const t=Math.max(0,Math.min(duration,
  (ev.clientX-r.left)/r.width*duration
 ));

 setCurrent(t);
});

document.addEventListener("pointerdown",ev=>{
 if(!menu.contains(ev.target))menu.style.display="none";
});

document.addEventListener("keydown",ev=>{
 if(ev.key==="Escape"){
  selected=[];
  menu.style.display="none";
  renderOverlay();
  renderRows();
 }
});

renderAxis();
renderRows();
renderOverlay();
updateTime();
</script>
</body>
</html>

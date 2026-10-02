<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;font-family:Arial,"Noto Sans JP",sans-serif;background:#0d1117;color:#e8edf5}
button,input,select{font:inherit}
button{cursor:pointer}
.app{height:100%;display:flex;flex-direction:column}
.header{height:50px;display:flex;align-items:center;gap:8px;padding:0 12px;background:#161b22;border-bottom:1px solid #30363d}
.title{font-weight:bold;margin-right:8px}.btn{background:#242c36;color:#fff;border:1px solid #46515f;border-radius:5px;padding:6px 11px}.primary{background:#1769aa}
.status{margin-left:auto;color:#8b949e;font-size:12px}
.main{flex:1;min-height:0;display:flex;flex-direction:column}
.video-area{flex:1;min-height:0;background:#05070a;padding:10px;display:flex;justify-content:center;align-items:center}
.video-wrap{position:relative;width:min(1200px,100%);height:100%;background:#000;overflow:hidden}
video{width:100%;height:100%;object-fit:contain}
.svg{position:absolute;inset:0;width:100%;height:100%;z-index:10}
.overlay{position:absolute;inset:0;z-index:20;pointer-events:none}
.element{position:absolute;pointer-events:auto;cursor:move;touch-action:none;user-select:none}
.element.selected{outline:2px solid #4da3ff;outline-offset:3px}
.element.multi{outline:2px solid #ffc107;outline-offset:3px}
.body{width:100%;height:100%;display:flex;align-items:center;justify-content:center;overflow:hidden}
.comment .body{padding:5px;color:#fff;text-shadow:0 1px 4px #000;font-size:17px}
.highlight .body{border:4px solid #f04444}
.highlight.round .body{border-radius:18px}
.highlight.circle .body{border-radius:50%}
.zoom .body{border:3px solid #fff5;background:#fff1}
.handle{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #4da3ff}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.ne{right:-5px;top:-5px;cursor:nesw-resize}
.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.se{right:-5px;bottom:-5px;cursor:nwse-resize}
.timeline{height:250px;flex:none;background:#11161d;border-top:1px solid #30363d;display:flex;flex-direction:column}
.toolbar{height:42px;display:flex;align-items:center;gap:7px;padding:5px 9px;border-bottom:1px solid #30363d}
.time{min-width:145px;font-variant-numeric:tabular-nums;color:#dbeafe}
.scale{margin-left:auto;color:#8b949e;font-size:12px}.scale input{width:110px}
.scroll{flex:1;min-height:0;overflow:auto}
.content{position:relative;height:100%;min-width:700px}
.axis{height:30px;margin-left:90px;position:relative;border-bottom:1px solid #30363d;background:#171d25}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #343b45;color:#7d8590;font-size:10px;padding:4px 0 0 3px}
.rows{margin-left:90px}
.row{height:50px;position:relative;border-bottom:1px solid #242b33}
.label{position:absolute;right:100%;width:90px;height:50px;display:flex;align-items:center;padding:0 7px;font-size:11px;background:#161b22;border-right:1px solid #30363d}
.dot{width:8px;height:8px;border-radius:50%;margin-right:5px}
.lane{height:100%;position:relative}
.bar{position:absolute;top:8px;height:34px;border:1px solid currentColor;border-radius:5px;display:flex;align-items:center;cursor:grab;touch-action:none}
.bar.selected{box-shadow:0 0 0 2px #4da3ff}.bar span{font-size:10px;padding:0 7px;white-space:nowrap}
.edge{position:absolute;top:0;width:10px;height:100%;cursor:ew-resize}.edge.l{left:-5px}.edge.r{right:-5px}
.playhead{position:absolute;top:30px;bottom:0;width:12px;margin-left:-6px;z-index:100;cursor:ew-resize}
.playhead:after{content:"";position:absolute;left:5px;top:0;bottom:0;width:2px;background:#f04444}
.playhead:before{content:"";position:absolute;top:-2px;left:0;border-left:6px solid transparent;border-right:6px solid transparent;border-top:9px solid #f04444}
.menu{position:fixed;z-index:1000;display:none;min-width:200px;background:#1c2531;border:1px solid #46515f;border-radius:6px;padding:5px;box-shadow:0 12px 30px #0009}
.menu-title{font-size:11px;color:#8b949e;padding:5px 7px}.menu button,.menu select{width:100%;display:block;background:transparent;color:#fff;border:0;padding:7px;text-align:left;border-radius:4px}.menu select{background:#242c36;border:1px solid #46515f}.menu button:hover{background:#2b3745}
.menu hr{border:0;border-top:1px solid #303b48}
.toast{position:fixed;right:15px;bottom:15px;background:#202938;border:1px solid #46515f;padding:8px 12px;border-radius:5px;display:none;z-index:2000}
.hint{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);color:#66717f;text-align:center}
</style>
</head>
<body>
<div class="app">
<header class="header">
 <div class="title">動画編集・注釈</div>
 <button class="btn primary" id="load">動画を選択</button>
 <input id="file" type="file" accept="video/*" hidden>
 <button class="btn" id="new">新規</button>
 <span class="status" id="status">動画を選択してください</span>
</header>

<main class="main">
<section class="video-area">
 <div class="video-wrap" id="wrap">
  <video id="video" playsinline preload="metadata"></video>
  <svg class="svg" id="svg"></svg>
  <div class="overlay" id="overlay"></div>
  <div class="hint" id="hint">「動画を選択」から動画を読み込んでください</div>
 </div>
</section>

<section class="timeline">
 <div class="toolbar">
  <button class="btn" id="play">▶</button>
  <button class="btn" id="stop">■</button>
  <span class="time" id="time">00:00.000 / 00:00.000</span>
  <label class="scale">時間スケール
   <input id="scale" type="range" min="1" max="6" step=".1" value="1">
   <span id="scaleText">1.0×</span>
  </label>
 </div>
 <div class="scroll" id="scroll">
  <div class="content" id="content">
   <div class="axis" id="axis"></div>
   <div class="rows" id="rows"></div>
   <div class="playhead" id="playhead"></div>
  </div>
 </div>
</section>
</main>
</div>

<div class="menu" id="menu"></div>
<div class="toast" id="toast"></div>

<script>
const $=id=>document.getElementById(id);
const video=$("video"),wrap=$("wrap"),overlay=$("overlay"),svg=$("svg");
const axis=$("axis"),rows=$("rows"),content=$("content"),playhead=$("playhead"),menu=$("menu");
let duration=60,current=0,scale=1,selected=[],drag=null,nextId=10,menuTarget=null;

const colors={comment:"#4da3ff",highlight:"#f04444",zoom:"#fff",skip:"#ff9800"};

let elements=[
 {id:1,type:"comment",label:"コメント",start:5,end:18,x:15,y:12,w:20,h:12,text:"ここを確認してください"},
 {id:2,type:"highlight",label:"強調枠",start:8,end:25,x:48,y:32,w:25,h:28,shape:"rect"},
 {id:3,type:"zoom",label:"拡大枠",start:20,end:36,x:18,y:55,w:25,h:25,shape:"rect"},
 {id:4,type:"skip",label:"スキップ",start:40,end:47,x:0,y:0,w:0,h:0}
];

let connections=[
 {id:5,from:1,to:2,fromPos:"r",toPos:"l",style:"solid",end:"arrow"},
 {id:6,from:2,to:3,fromPos:"b",toPos:"t",style:"orthogonal",end:"arrow"}
];

function fmt(t){
 const m=Math.floor(t/60),s=(t%60).toFixed(3);
 return String(m).padStart(2,"0")+":"+s.padStart(6,"0");
}
function toast(s){
 $("toast").textContent=s;$("toast").style.display="block";
 clearTimeout(toast.t);toast.t=setTimeout(()=>$("toast").style.display="none",1500);
}
function active(e){return current>=e.start&&current<e.end}
function pos(e){return{left:e.x+"%",top:e.y+"%",width:e.w+"%",height:e.h+"%"}}

function renderOverlay(){
 overlay.innerHTML="";
 elements.filter(active).filter(e=>e.type!=="skip").forEach(e=>{
  const d=document.createElement("div");
  d.className="element "+e.type+(selected.includes(e.id)?" selected":"")+
    (selected.length>1&&selected.includes(e.id)?" multi":"");
  d.dataset.id=e.id;Object.assign(d.style,pos(e));
  if(e.type==="highlight")d.classList.add(e.shape||"rect");
  const b=document.createElement("div");b.className="body";b.textContent=e.type==="comment"?e.text:"";
  d.appendChild(b);
  ["nw","ne","sw","se"].forEach(p=>{
   const h=document.createElement("i");h.className="handle "+p;h.dataset.resize=p;d.appendChild(h);
  });
  d.onpointerdown=startElement;
  d.oncontextmenu=ev=>{ev.preventDefault();select(e.id,ev);showElementMenu(ev.clientX,ev.clientY,e.id)};
  overlay.appendChild(d);
 });
 renderConnections();
}

function select(id,ev){
 const multi=ev&&(ev.shiftKey||ev.ctrlKey||ev.metaKey);
 if(multi)selected=selected.includes(id)?selected.filter(x=>x!==id):[...selected,id];
 else selected=[id];
 menu.style.display="none";renderOverlay();renderRows();
}

function clearSelection(){selected=[];menu.style.display="none";renderOverlay();renderRows()}

function startElement(ev){
 if(ev.button!==0)return;
 const e=elements.find(x=>x.id==ev.currentTarget.dataset.id);if(!e)return;
 ev.stopPropagation();
 select(e.id,ev);
 const r=ev.currentTarget.dataset.resize;
 drag={kind:r?"resize":"move",e,startX:ev.clientX,startY:ev.clientY,
       x:e.x,y:e.y,w:e.w,h:e.h,resize:r};
 window.addEventListener("pointermove",moveElement);
 window.addEventListener("pointerup",endDrag,{once:true});
}
function moveElement(ev){
 if(!drag)return;
 const r=wrap.getBoundingClientRect(),dx=(ev.clientX-drag.startX)/r.width*100,dy=(ev.clientY-drag.startY)/r.height*100;
 if(drag.kind==="move"){
  const ids=selected.length?selected:[drag.e.id];
  ids.forEach(id=>{
   const e=elements.find(x=>x.id===id);if(!e||e.type==="skip")return;
   e.x=Math.max(0,Math.min(100-e.w,e.x0??(e.x=drag.x)+dx));
   e.y=Math.max(0,Math.min(100-e.h,e.y0??(e.y=drag.y)+dy));
  });
 }else{
  let e=drag.e,x=drag.x,y=drag.y,w=drag.w,h=drag.h;
  if(drag.resize.includes("e"))w=Math.max(5,drag.w+dx);
  if(drag.resize.includes("s"))h=Math.max(5,drag.h+dy);
  if(drag.resize.includes("w")){x=drag.x+dx;w=drag.w-dx}
  if(drag.resize.includes("n")){y=drag.y+dy;h=drag.h-dy}
  e.x=Math.max(0,Math.min(95,x));e.y=Math.max(0,Math.min(95,y));
  e.w=Math.max(5,Math.min(100-e.x,w));e.h=Math.max(5,Math.min(100-e.y,h));
 }
 renderOverlay();
}
function endDrag(){
 elements.forEach(e=>{delete e.x0;delete e.y0});
 drag=null;window.removeEventListener("pointermove",moveElement);
}

function point(e,p){
 const x=e.x,y=e.y,w=e.w,h=e.h;
 return({tl:[x,y],t:[x+w/2,y],tr:[x+w,y],l:[x,y+h/2],r:[x+w,y+h/2],
 bl:[x,y+h],b:[x+w/2,y+h],br:[x+w,y+h]}[p]||[x+w,y+h/2]);
}

function renderConnections(){
 svg.innerHTML="";svg.setAttribute("viewBox","0 0 100 100");svg.setAttribute("preserveAspectRatio","none");
 connections.forEach(c=>{
  const a=elements.find(e=>e.id===c.from),b=elements.find(e=>e.id===c.to);if(!a||!b)return;
  const p1=point(a,c.fromPos),p2=point(b,c.toPos);
  let d=`M${p1[0]} ${p1[1]}`;
  if(c.style==="orthogonal"){
   const mx=(p1[0]+p2[0])/2;d+=` L${mx} ${p1[1]} L${mx} ${p2[1]} L${p2[0]} ${p2[1]}`;
  }else if(c.style==="wave"){
   const dx=p2[0]-p1[0],dy=p2[1]-p1[1],len=Math.hypot(dx,dy)||1,nx=-dy/len*1.6,ny=dx/len*1.6;
   for(let i=1;i<=16;i++){let t=i/16;d+=` L${p1[0]+dx*t+nx*Math.sin(t*Math.PI*8)} ${p1[1]+dy*t+ny*Math.sin(t*Math.PI*8)}`}
  }else d+=` L${p2[0]} ${p2[1]}`;

  const path=document.createElementNS("http://www.w3.org/2000/svg","path");
  path.setAttribute("d",d);
  path.setAttribute("fill","none");
  path.setAttribute("stroke","#f04444");
  path.setAttribute("stroke-width",c.id===selected[0]?"4":"4");
  path.setAttribute("class","connection");
  path.style.pointerEvents="stroke";
  if(c.id===selected[0]&&selected.length===1)path.setAttribute("stroke","#4da3ff");
  if(c.end==="arrow"){
   path.setAttribute("marker-end","url(#arrow)");
  }
  path.onpointerdown=ev=>{if(ev.button===0){ev.stopPropagation();selected=[c.id];renderConnections();renderRows()}};
  path.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();selected=[c.id];renderConnections();renderRows();showConnectionMenu(ev.clientX,ev.clientY,c.id)};
  svg.appendChild(path);

  if(c.id===selected[0]&&selected.length===1){
   [p1,p2].forEach((p,i)=>{
    const h=document.createElementNS("http://www.w3.org/2000/svg","circle");
    h.setAttribute("cx",p[0]);h.setAttribute("cy",p[1]);h.setAttribute("r",3);
    h.setAttribute("fill","#fff");h.setAttribute("stroke","#4da3ff");h.setAttribute("stroke-width",2);
    h.style.cursor="crosshair";h.dataset.end=i?"to":"from";h.dataset.cid=c.id;
    h.onpointerdown=startConnectionHandle;svg.appendChild(h);
   });
  }
 });
 const defs=document.createElementNS("http://www.w3.org/2000/svg","defs");
 defs.innerHTML='<marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8 Z" fill="#f04444"/></marker>';
 svg.prepend(defs);
}

function startConnectionHandle(ev){
 ev.stopPropagation();const c=connections.find(x=>x.id==ev.currentTarget.dataset.cid);if(!c)return;
 drag={kind:"connection",c,end:ev.currentTarget.dataset.end};
 window.addEventListener("pointermove",moveConnection);
 window.addEventListener("pointerup",()=>{drag=null;window.removeEventListener("pointermove",moveConnection)},{once:true});
}
function moveConnection(ev){
 if(!drag)return;
 const r=wrap.getBoundingClientRect(),x=(ev.clientX-r.left)/r.width*100,y=(ev.clientY-r.top)/r.height*100;
 const e=elements.find(v=>v.type!=="skip"&&x>=v.x&&x<=v.x+v.w&&y>=v.y&&y<=v.y+v.h);
 if(!e)return;
 const rx=(x-e.x)/e.w,ry=(y-e.y)/e.h;
 let p=ry<.25?(rx<.33?"tl":rx>.66?"tr":"t"):ry>.75?(rx<.33?"bl":rx>.66?"br":"b"):(rx<.5?"l":"r");
 if(drag.end==="from"){drag.c.from=e.id;drag.c.fromPos=p}else{drag.c.to=e.id;drag.c.toPos=p}
 renderConnections();
}

function showElementMenu(x,y,id){
 const e=elements.find(v=>v.id===id);if(!e)return;
 menuTarget={type:"element",id};
 let h=`<div class="menu-title">${e.label} の操作</div>`;
 if(e.type==="highlight")h+=`<button data-a="rect">四角形</button><button data-a="round">角丸</button><button data-a="circle">円形</button>`;
 if(e.type==="comment")h+=`<button data-a="comment">コメント変更</button>`;
 h+=`<button data-a="delete">削除</button>`;
 if(selected.length>=2)h+=`<hr><button data-a="connect">選択要素を接続</button>`;
 openMenu(x,y,h);
}

function showConnectionMenu(x,y,id){
 const c=connections.find(v=>v.id===id);if(!c)return;
 menuTarget={type:"connection",id};
 openMenu(x,y,`
  <div class="menu-title">接続線の操作</div>
  <select id="lineStyle">
   <option value="solid" ${c.style==="solid"?"selected":""}>直線</option>
   <option value="orthogonal" ${c.style==="orthogonal"?"selected":""}>折れ線</option>
   <option value="wave" ${c.style==="wave"?"selected":""}>波線</option>
  </select>
  <button data-a="from">始点の接続位置を変更</button>
  <button data-a="to">終点の接続位置を変更</button>
  <button data-a="end">終端を変更</button>
  <hr><button data-a="deleteConnection">接続線を削除</button>
 `);
 $("lineStyle").onchange=ev=>{c.style=ev.target.value;renderConnections()};
}
function openMenu(x,y,h){
 menu.innerHTML=h;menu.style.display="block";
 menu.style.left=Math.min(x,innerWidth-220)+"px";menu.style.top=Math.min(y,innerHeight-260)+"px";
}

menu.onclick=ev=>{
 const a=ev.target.dataset.a;if(!a)return;
 if(menuTarget.type==="element"){
  const e=elements.find(v=>v.id===menuTarget.id);
  if(a==="rect"||a==="round"||a==="circle")e.shape=a;
  if(a==="comment"){const v=prompt("コメント",e.text);if(v!==null)e.text=v}
  if(a==="delete"){elements=elements.filter(v=>v.id!==e.id);connections=connections.filter(c=>c.from!==e.id&&c.to!==e.id);selected=[]}
  if(a==="connect"&&selected.length>=2){
   connections.push({id:nextId++,from:selected[0],to:selected[1],fromPos:"r",toPos:"l",style:"solid",end:"arrow"});
   selected=[];
  }
  renderOverlay();renderRows();
 }
 if(menuTarget.type==="connection"){
  const c=connections.find(v=>v.id===menuTarget.id);
  if(a==="from"||a==="to")toast("動画上の終端をドラッグして接続位置を変更");
  if(a==="end"){c.end=c.end==="arrow"?"none":"arrow";renderConnections()}
  if(a==="deleteConnection"){connections=connections.filter(v=>v.id!==c.id);selected=[]}
 }
 menu.style.display="none";
};

document.addEventListener("pointerdown",ev=>{
 if(!menu.contains(ev.target))menu.style.display="none";
});
wrap.oncontextmenu=ev=>{
 if(ev.target.closest(".element")||ev.target.closest(".connection"))return;
 ev.preventDefault();
 menuTarget={type:"add",x:ev.clientX,y:ev.clientY};
 openMenu(ev.clientX,ev.clientY,`<div class="menu-title">要素を追加</div>
 <button data-add="comment">コメント</button><button data-add="highlight">強調枠</button>
 <button data-add="zoom">拡大枠</button><button data-add="skip">スキップ</button>`);
};
menu.addEventListener("click",ev=>{
 const type=ev.target.dataset.add;if(!type)return;
 const r=wrap.getBoundingClientRect(),x=(menuTarget.x-r.left)/r.width*100,y=(menuTarget.y-r.top)/r.height*100;
 elements.push({id:nextId++,type,label:{comment:"コメント",highlight:"強調枠",zoom:"拡大枠",skip:"スキップ"}[type],
  start:Math.max(0,current-1),end:Math.min(duration,current+8),
  x:Math.max(0,x-10),y:Math.max(0,y-8),w:type==="comment"?20:25,h:type==="comment"?12:25,
  text:type==="comment"?"新しいコメント":"",shape:"rect"});
 selected=[elements.at(-1).id];menu.style.display="none";renderOverlay();renderRows();
});

function renderAxis(){
 const width=Math.max(700,900*scale);content.style.width=width+"px";
 axis.innerHTML="";
 for(let i=0;i<=10;i++){
  const t=duration*i/10,d=document.createElement("div");
  d.className="tick";d.style.left=i*10+"%";d.textContent=fmt(t);axis.appendChild(d);
 }
}
function renderRows(){
 rows.innerHTML="";
 [["comment","コメント"],["highlight","強調枠"],["zoom","拡大枠"],["skip","スキップ"]].forEach(([type,label])=>{
  const row=document.createElement("div");row.className="row";
  row.innerHTML=`<div class="label"><i class="dot" style="background:${colors[type]}"></i>${label}</div>`;
  const lane=document.createElement("div");lane.className="lane";
  elements.filter(e=>e.type===type).forEach(e=>{
   const b=document.createElement("div");b.className="bar"+(selected.includes(e.id)?" selected":"");
   b.style.color=colors[type];b.style.left=e.start/duration*100+"%";b.style.width=(e.end-e.start)/duration*100+"%";
   b.innerHTML=`<span>${label}</span><i class="edge l"></i><i class="edge r"></i>`;
   b.onpointerdown=ev=>{
    if(ev.button!==0)return;ev.stopPropagation();select(e.id,ev);
    const resize=ev.target.classList.contains("edge");
    drag={kind:resize?"timeResize":"timeMove",e,x:ev.clientX,start:e.start,end:e.end,side:ev.target.classList.contains("l")?"l":"r"};
    window.addEventListener("pointermove",timelineMove);window.addEventListener("pointerup",endTimeline,{once:true});
   };
   lane.appendChild(b);
  });
  row.appendChild(lane);rows.appendChild(row);
 });
}
function timelineMove(ev){
 if(!drag)return;
 const lane=rows.querySelector(".lane"),r=lane.getBoundingClientRect(),dt=(ev.clientX-drag.x)/r.width*duration;
 if(drag.kind==="timeMove"){let len=drag.end-drag.start;drag.e.start=Math.max(0,Math.min(duration-len,drag.start+dt));drag.e.end=drag.e.start+len}
 else if(drag.side==="l")drag.e.start=Math.max(0,Math.min(drag.end-.2,drag.start+dt));
 else drag.e.end=Math.min(duration,Math.max(drag.start+.2,drag.end+dt));
 renderRows();renderOverlay();
}
function endTimeline(){drag=null;window.removeEventListener("pointermove",timelineMove)}

function setTimeFromClient(x){
 const r=axis.getBoundingClientRect(),t=Math.max(0,Math.min(duration,(x-r.left)/r.width*duration));
 video.currentTime=t;
}
function startPlayhead(ev){
 ev.stopPropagation();drag={kind:"playhead"};
 setTimeFromClient(ev.clientX);
 window.addEventListener("pointermove",movePlayhead);
 window.addEventListener("pointerup",endPlayhead,{once:true});
}
function movePlayhead(ev){if(drag?.kind==="playhead")setTimeFromClient(ev.clientX)}
function endPlayhead(){drag=null;window.removeEventListener("pointermove",movePlayhead)}
playhead.onpointerdown=startPlayhead;
axis.onpointerdown=ev=>{setTimeFromClient(ev.clientX)};
rows.onpointerdown=ev=>{
 if(ev.target.closest(".bar"))return;
 if(ev.button===0)setTimeFromClient(ev.clientX);
};

function update(){
 current=Math.max(0,Math.min(duration,video.currentTime||0));
 $("time").textContent=fmt(current)+" / "+fmt(duration);
 const p=duration?current/duration:0;
 playhead.style.left=`calc(90px + ${p*100}% )`;
 renderOverlay();
 const skip=elements.find(e=>e.type==="skip"&&current>=e.start&&current<e.end);
 if(skip&&!video.paused)video.currentTime=skip.end;
}

video.onloadedmetadata=()=>{
 duration=video.duration||60;$("status").textContent="動画編集中";$("hint").style.display="none";
 renderAxis();renderRows();update();
};
video.ontimeupdate=update;
video.onended=()=>{$("play").textContent="▶"};

$("load").onclick=()=>$("file").click();
$("file").onchange=ev=>{
 const f=ev.target.files[0];if(!f)return;
 video.src=URL.createObjectURL(f);video.load();
};
$("play").onclick=()=>{
 if(!video.src)return toast("先に動画を選択してください");
 if(video.paused){video.play();$("play").textContent="⏸"}else{video.pause();$("play").textContent="▶"}
};
$("stop").onclick=()=>{video.pause();video.currentTime=0;$("play").textContent="▶"};
$("new").onclick=()=>{
 video.pause();video.removeAttribute("src");video.load();selected=[];connections=[];
 duration=60;current=0;$("status").textContent="動画を選択してください";$("hint").style.display="block";
 renderAxis();renderRows();renderOverlay();update();
};
$("scale").oninput=ev=>{
 scale=Number(ev.target.value);$("scaleText").textContent=scale.toFixed(1)+"×";
 renderAxis();renderRows();update();
};
document.onkeydown=ev=>{if(ev.key==="Escape")clearSelection()};

renderAxis();renderRows();renderOverlay();update();
</script>
</body>
</html>

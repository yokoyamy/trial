<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#0d1117;color:#e8edf5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
button,input,select{font:inherit}button{cursor:pointer}
.app{height:100%;display:flex;flex-direction:column}
.header{height:52px;display:flex;align-items:center;gap:8px;padding:0 12px;background:#161b22;border-bottom:1px solid #30363d;flex:none}
.title{font-weight:700;margin-right:10px}.btn{border:1px solid #3b4654;background:#242c36;color:#e8edf5;border-radius:6px;padding:7px 11px}.btn:hover{background:#303a47}.primary{background:#1769aa;border-color:#2387d9}
.status{margin-left:auto;color:#8b949e;font-size:12px}
.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{min-height:0;flex:1;background:#05070a;display:flex;align-items:center;justify-content:center;padding:12px}
.video-wrap{position:relative;width:min(1200px,100%);height:100%;background:#000;overflow:hidden}
video{width:100%;height:100%;object-fit:contain;display:block}
.svg-layer{position:absolute;inset:0;width:100%;height:100%;z-index:10;overflow:visible}
.connection-hit{fill:none;stroke:transparent;stroke-width:16;vector-effect:non-scaling-stroke;pointer-events:stroke;cursor:pointer}
.connection{fill:none;stroke:#ff5252;stroke-width:1.5;vector-effect:non-scaling-stroke;pointer-events:none}
.connection.selected{stroke:#4da3ff;stroke-width:2.5}
.connection-handle{fill:#fff;stroke:#4da3ff;stroke-width:1.5;vector-effect:non-scaling-stroke;cursor:crosshair}
.overlay{position:absolute;inset:0;z-index:20;pointer-events:none}
.element{position:absolute;pointer-events:auto;user-select:none;touch-action:none;cursor:move;min-width:40px;min-height:25px}
.element.selected{outline:2px solid #4da3ff;outline-offset:3px}
.element.multi{outline:2px solid #ffc107;outline-offset:3px}
.element-body{width:100%;height:100%;display:flex;align-items:center;justify-content:center;overflow:hidden}
.comment .element-body{color:#fff;font-size:18px;padding:4px;text-shadow:0 1px 4px #000}
.highlight .element-body{border:3px solid #f04444;background:transparent}
.highlight.round .element-body{border-radius:18px}.highlight.circle .element-body{border-radius:50%}
.zoombox .element-body{border:3px solid #5c6370;background:#1118}
.skip .element-body{border:2px dashed #ff9800;background:#ff980033;color:#ffb74d;font-size:12px}
.handle{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #4da3ff;z-index:5}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.ne{right:-5px;top:-5px;cursor:nesw-resize}.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.se{right:-5px;bottom:-5px;cursor:nwse-resize}
.timeline{height:255px;flex:none;background:#11161d;border-top:1px solid #30363d;display:flex;flex-direction:column}
.timeline-toolbar{height:42px;display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid #30363d;flex:none}
.time{font-variant-numeric:tabular-nums;min-width:150px;color:#dbeafe}
.scale{margin-left:auto;display:flex;align-items:center;gap:7px;color:#8b949e;font-size:12px}.scale input{width:110px}
.timeline-scroll{min-height:0;flex:1;overflow:auto}
.timeline-content{position:relative;height:100%;min-width:700px}
.axis{height:30px;position:relative;margin-left:100px;background:#171d25;border-bottom:1px solid #30363d}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #343b45;color:#7d8590;font-size:10px;padding:4px 0 0 3px}
.rows{margin-left:100px}.row{position:relative;border-bottom:1px solid #242b33}
.row-label{position:absolute;right:100%;top:0;width:100px;display:flex;align-items:center;padding:0 8px;gap:5px;background:#161b22;border-right:1px solid #30363d;font-size:11px}
.dot{width:8px;height:8px;border-radius:50%}
.lane{position:relative}.bar{position:absolute;height:34px;border-radius:5px;border:1px solid currentColor;display:flex;align-items:center;cursor:grab;user-select:none;touch-action:none}
.bar.selected{box-shadow:0 0 0 2px #4da3ff}.bar.multi{box-shadow:0 0 0 2px #ffc107}
.bar span{font-size:10px;padding:0 7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar-handle{position:absolute;top:0;height:100%;width:8px;cursor:ew-resize}.bar-handle.left{left:-4px}.bar-handle.right{right:-4px}
.playhead{position:absolute;top:30px;bottom:0;width:2px;background:#f04444;z-index:100;pointer-events:none}.playhead:before{content:"";position:absolute;top:-2px;left:-5px;width:12px;height:12px;background:#f04444;clip-path:polygon(0 0,100% 0,50% 100%)}
.menu{position:fixed;z-index:1000;display:none;min-width:230px;max-width:280px;background:#1c2531;border:1px solid #46515f;border-radius:7px;box-shadow:0 12px 30px #0009;padding:5px}
.menu-title{font-size:11px;color:#8b949e;padding:6px 8px}.menu button{display:block;width:100%;text-align:left;border:0;background:transparent;color:#e8edf5;padding:8px;border-radius:4px}.menu button:hover{background:#2b3745}
.menu hr{border:0;border-top:1px solid #303b48;margin:4px 0}.menu select,.menu input[type=color]{width:100%;background:#242c36;color:#fff;border:1px solid #46515f;padding:6px;border-radius:4px}
.menu .field{padding:4px 8px;color:#c9d1d9;font-size:12px}.menu .field input[type=range]{width:100%}
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
<button class="btn" id="saveBtn">保存</button>
<button class="btn" id="loadSaveBtn">保存内容を復元</button>
<div class="status" id="status">動画を選択してください</div>
</header>

<main class="main">
<section class="video-area">
<div class="video-wrap" id="videoWrap">
<video id="video" playsinline preload="metadata"></video>
<svg id="svg" class="svg-layer"></svg>
<div id="overlay" class="overlay"></div>
<div id="hint" class="hint">動画を選択してください<br>動画上で右クリックすると要素を追加できます</div>
</div>
</section>

<section class="timeline">
<div class="timeline-toolbar">
<button class="btn" id="playBtn">▶</button>
<button class="btn" id="stopBtn">■</button>
<div class="time" id="time">00:00.000 / 00:00.000</div>
<div class="scale">時間軸
<input id="scale" type="range" min=".5" max="5" step=".1" value="1">
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
const content=$("timelineContent"),playhead=$("playhead"),scroll=$("timelineScroll");

let duration=60,current=0,scale=1,selected=[],selectedConnection=null,drag=null,menuTarget=null,nextId=5;
let elements=[
 {id:1,type:"comment",label:"コメント",start:5,end:18,x:18,y:15,w:19,h:8,text:"ここを確認してください",fontSize:18,color:"#fff"},
 {id:2,type:"highlight",label:"強調枠",start:8,end:24,x:52,y:34,w:23,h:15,shape:"rect",color:"#f04444",lineWidth:3},
 {id:3,type:"zoombox",label:"拡大枠",start:20,end:35,x:20,y:58,w:18,h:11,color:"#5c6370"},
 {id:4,type:"skip",label:"スキップ",start:38,end:45,x:0,y:0,w:0,h:0}
];
let connections=[];
const colors={comment:"#4da3ff",highlight:"#f04444",zoombox:"#5c6370",skip:"#ff9800"};

function toastMsg(s){toast.textContent=s;toast.style.display="block";clearTimeout(toastMsg.t);toastMsg.t=setTimeout(()=>toast.style.display="none",1800)}
function fmt(t){t=Math.max(0,t||0);return String(Math.floor(t/60)).padStart(2,"0")+":"+String(t%60).padStart(6,"0")}
function activeElements(){return elements.filter(e=>e.type!=="skip"&&current>=e.start&&current<=e.end)}
function pxPerSec(){return 70*scale}
function findElement(id){return elements.find(e=>e.id===Number(id))}

function selectElement(id,ev){
 const n=Number(id),multi=ev&&(ev.shiftKey||ev.ctrlKey||ev.metaKey);
 selectedConnection=null;
 if(multi)selected=selected.includes(n)?selected.filter(x=>x!==n):[...selected,n];
 else selected=[n];
 renderOverlay();renderRows();
}
function clearSelection(){selected=[];selectedConnection=null;menu.style.display="none";renderOverlay();renderRows();renderConnections()}

function renderOverlay(){
 overlay.innerHTML="";
 activeElements().forEach(e=>{
  const d=document.createElement("div");
  d.className="element "+e.type+(selected.includes(e.id)?" selected":"")+(selected.length>1&&selected.includes(e.id)?" multi":"");
  d.dataset.id=e.id;
  d.style.left=e.x+"%";d.style.top=e.y+"%";d.style.width=e.w+"%";d.style.height=e.h+"%";
  if(e.type==="highlight"){
   d.classList.add(e.shape||"rect");
   d.style.setProperty("--dummy","0");
  }
  const body=document.createElement("div");body.className="element-body";
  if(e.type==="comment"){body.textContent=e.text;body.style.fontSize=(e.fontSize||18)+"px";body.style.color=e.color||"#fff"}
  if(e.type==="highlight"){body.style.borderColor=e.color||"#f04444";body.style.borderWidth=(e.lineWidth||3)+"px"}
  if(e.type==="zoombox")body.style.borderColor=e.color||"#5c6370";
  d.appendChild(body);
  ["nw","ne","sw","se"].forEach(p=>{const h=document.createElement("div");h.className="handle "+p;h.dataset.resize=p;d.appendChild(h)});
  d.addEventListener("pointerdown",startElement);
  d.addEventListener("contextmenu",ev=>{ev.preventDefault();ev.stopPropagation();if(!selected.includes(e.id))selectElement(e.id,ev);showElementMenu(ev.clientX,ev.clientY,e.id)});
  overlay.appendChild(d);
 });
 renderConnections();
}

function startElement(ev){
 if(ev.button!==0)return;
 const el=findElement(ev.currentTarget.dataset.id);if(!el)return;
 ev.stopPropagation();
 if(!selected.includes(el.id))selectElement(el,ev);
 const resize=ev.target.dataset.resize||null;
 const base=selected.map(id=>findElement(id)).filter(Boolean).map(e=>({id:e.id,x:e.x,y:e.y}));
 drag=resize
  ?{kind:"resize",el,resize,x:ev.clientX,y:ev.clientY,ox:el.x,oy:el.y,ow:el.w,oh:el.h}
  :{kind:"move",x:ev.clientX,y:ev.clientY,base};
 window.addEventListener("pointermove",moveElement);window.addEventListener("pointerup",endDrag,{once:true});
}

function moveElement(ev){
 if(!drag)return;
 const r=wrap.getBoundingClientRect(),dx=(ev.clientX-drag.x)/r.width*100,dy=(ev.clientY-drag.y)/r.height*100;
 if(drag.kind==="move"){
  drag.base.forEach(b=>{const e=findElement(b.id);if(!e)return;e.x=Math.max(0,Math.min(100-e.w,b.x+dx));e.y=Math.max(0,Math.min(100-e.h,b.y+dy))});
 }else{
  const e=drag.el;let x=drag.ox,y=drag.oy,w=drag.ow,h=drag.oh;
  if(drag.resize.includes("e"))w=drag.ow+dx;if(drag.resize.includes("s"))h=drag.oh+dy;
  if(drag.resize.includes("w")){x=drag.ox+dx;w=drag.ow-dx}
  if(drag.resize.includes("n")){y=drag.oy+dy;h=drag.oh-dy}
  w=Math.max(5,Math.min(100,w));h=Math.max(5,Math.min(100,h));
  x=Math.max(0,Math.min(100-w,x));y=Math.max(0,Math.min(100-h,y));
  e.x=x;e.y=y;e.w=w;e.h=h;
 }
 renderOverlay();
}
function endDrag(){drag=null;window.removeEventListener("pointermove",moveElement)}

function point(e,pos){
 const x=e.x,y=e.y,w=e.w,h=e.h;
 return({tl:[x,y],t:[x+w/2,y],tr:[x+w,y],l:[x,y+h/2],r:[x+w,y+h/2],bl:[x,y+h],b:[x+w/2,y+h],br:[x+w,y+h]})[pos]||[x+w,y+h/2];
}
function hitSegment(a,b,r,p=2){
 const x1=r.x-p,y1=r.y-p,x2=r.x+r.w+p,y2=r.y+r.h+p,steps=20;
 for(let i=0;i<=steps;i++){const t=i/steps,x=a[0]+(b[0]-a[0])*t,y=a[1]+(b[1]-a[1])*t;if(x>=x1&&x<=x2&&y>=y1&&y<=y2)return true}
 return false;
}
function route(a,b,from,to){
 const obs=elements.filter(e=>e.id!==from.id&&e.id!==to.id&&e.type!=="skip"&&e.w>0);
 const mx=(a[0]+b[0])/2,my=(a[1]+b[1])/2;
 const candidates=[
  [a,[mx,a[1]],[mx,b[1]],b],
  [a,[a[0],my],[b[0],my],b],
  [a,[a[0]+5,a[1]],[a[0]+5,b[1]],b],
  [a,[a[0]-5,a[1]],[a[0]-5,b[1]],b],
  [a,[a[0],a[1]+5],[b[0],a[1]+5],b],
  [a,[a[0],a[1]-5],[b[0],a[1]-5],b]
 ];
 for(const c of candidates)if(c.every((p,i)=>i===0||!obs.some(o=>hitSegment(c[i-1],p,o))))return c;
 return [a,b];
}
function pathData(c,a,b){
 const p1=point(a,c.fromPos),p2=point(b,c.toPos);
 if(c.style==="solid"||c.style==="dashed")return`M${p1[0]} ${p1[1]} L${p2[0]} ${p2[1]}`;
 const pts=route(p1,p2,a,b);
 if(c.style==="orthogonal")return pts.map((p,i)=>(i?"L":"M")+p[0]+" "+p[1]).join(" ");
 let d="M"+pts[0][0]+" "+pts[0][1];
 for(let s=0;s<pts.length-1;s++){
  const a1=pts[s],b1=pts[s+1],dx=b1[0]-a1[0],dy=b1[1]-a1[1],len=Math.hypot(dx,dy)||1,nx=-dy/len,ny=dx/len;
  const count=Math.max(2,Math.ceil(len/4));
  for(let i=1;i<=count;i++){const t=i/count,w=Math.sin(t*Math.PI*4)*1;d+=` L${a1[0]+dx*t+nx*w} ${a1[1]+dy*t+ny*w}`}
 }
 return d;
}

function renderConnections(){
 svg.innerHTML="";svg.setAttribute("viewBox","0 0 100 100");svg.setAttribute("preserveAspectRatio","none");
 connections.forEach(c=>{
  const a=findElement(c.from),b=findElement(c.to);if(!a||!b)return;
  const d=pathData(c,a,b),sel=selectedConnection===c.id;
  const hit=document.createElementNS("http://www.w3.org/2000/svg","path");
  hit.setAttribute("d",d);hit.setAttribute("class","connection-hit");hit.dataset.cid=c.id;
  hit.addEventListener("pointerdown",ev=>{ev.stopPropagation();selected=[];selectedConnection=c.id;renderConnections();renderRows()});
  hit.addEventListener("contextmenu",ev=>{ev.preventDefault();ev.stopPropagation();selected=[];selectedConnection=c.id;renderConnections();renderRows();showConnectionMenu(ev.clientX,ev.clientY,c.id)});
  svg.appendChild(hit);
  const p=document.createElementNS("http://www.w3.org/2000/svg","path");p.setAttribute("d",d);p.setAttribute("class","connection"+(sel?" selected":""));
  if(c.style==="dashed")p.setAttribute("stroke-dasharray","7 5");
  if(c.endMarker==="arrow"){p.setAttribute("marker-end","url(#arrow)")}
  svg.appendChild(p);
  if(sel)[point(a,c.fromPos),point(b,c.toPos)].forEach((q,i)=>{const h=document.createElementNS("http://www.w3.org/2000/svg","circle");h.setAttribute("cx",q[0]);h.setAttribute("cy",q[1]);h.setAttribute("r",2.5);h.setAttribute("class","connection-handle");h.dataset.end=i?"to":"from";h.dataset.cid=c.id;h.addEventListener("pointerdown",startConnectionHandle);svg.appendChild(h)});
 });
 const defs=document.createElementNS("http://www.w3.org/2000/svg","defs"),m=document.createElementNS("http://www.w3.org/2000/svg","marker");
 m.id="arrow";m.setAttribute("markerWidth","7");m.setAttribute("markerHeight","7");m.setAttribute("refX","6");m.setAttribute("refY","3.5");m.setAttribute("orient","auto");m.setAttribute("markerUnits","strokeWidth");
 const q=document.createElementNS("http://www.w3.org/2000/svg","path");q.setAttribute("d","M0,0 L7,3.5 L0,7 Z");q.setAttribute("fill","#4da3ff");m.appendChild(q);defs.appendChild(m);svg.prepend(defs);
}

function startConnectionHandle(ev){
 ev.stopPropagation();const c=connections.find(x=>x.id===Number(ev.currentTarget.dataset.cid));if(!c)return;
 drag={kind:"connection",c,end:ev.currentTarget.dataset.end};
 window.addEventListener("pointermove",moveConnection);window.addEventListener("pointerup",endConnection,{once:true});
}
function moveConnection(ev){
 if(!drag)return;const r=wrap.getBoundingClientRect(),x=(ev.clientX-r.left)/r.width*100,y=(ev.clientY-r.top)/r.height*100;
 const t=elements.find(e=>e.type!=="skip"&&x>=e.x&&x<=e.x+e.w&&y>=e.y&&y<=e.y+e.h);if(!t)return;
 const rx=(x-t.x)/t.w,ry=(y-t.y)/t.h;
 const pos=ry<.25?(rx<.33?"tl":rx>.66?"tr":"t"):ry>.75?(rx<.33?"bl":rx>.66?"br":"b"):(rx<.5?"l":"r");
 if(drag.end==="from"){drag.c.from=t.id;drag.c.fromPos=pos}else{drag.c.to=t.id;drag.c.toPos=pos}
 renderConnections();
}
function endConnection(){drag=null;window.removeEventListener("pointermove",moveConnection)}

function createConnection(){
 if(selected.length!==2){toastMsg("接続する要素を2つ選択してください");return}
 const[from,to]=selected;if(from===to)return;
 if(connections.some(c=>(c.from===from&&c.to===to)||(c.from===to&&c.to===from))){toastMsg("すでに接続されています");return}
 const a=findElement(from),b=findElement(to),ac=[a.x+a.w/2,a.y+a.h/2],bc=[b.x+b.w/2,b.y+b.h/2];
 const horizontal=Math.abs(bc[0]-ac[0])>=Math.abs(bc[1]-ac[1]);
 connections.push({id:nextId++,from,to,fromPos:horizontal?(bc[0]>ac[0]?"r":"l"):(bc[1]>ac[1]?"b":"t"),toPos:horizontal?(bc[0]>ac[0]?"l":"r"):(bc[1]>ac[1]?"t":"b"),style:"solid",endMarker:"none"});
 selected=[];selectedConnection=connections.at(-1).id;renderOverlay();renderRows();toastMsg("接続線を作成しました");
}

function showElementMenu(x,y,id){
 const e=findElement(id);if(!e)return;menuTarget={type:"element",id};
 let h=`<div class="menu-title">${e.label}</div>`;
 if(e.type==="comment")h+=`<button data-action="editText">文字を変更</button><div class="field">文字色<input id="textColor" type="color" value="${e.color||"#ffffff"}"></div><div class="field">文字サイズ<input id="fontSize" type="range" min="10" max="40" value="${e.fontSize||18}"></div>`;
 if(e.type==="highlight")h+=`<button data-action="rect">四角形</button><button data-action="round">角丸</button><button data-action="circle">円形</button><div class="field">枠色<input id="lineColor" type="color" value="${e.color||"#f04444"}"></div><div class="field">枠の太さ<input id="lineWidth" type="range" min="1" max="8" value="${e.lineWidth||3}"></div>`;
 if(e.type==="zoombox")h+=`<div class="field">枠色<input id="zoomColor" type="color" value="${e.color||"#5c6370"}"></div>`;
 if(selected.length===2)h+=`<hr><button data-action="connect">選択した2要素を接続</button>`;
 h+=`<hr><button data-action="delete">削除</button>`;
 openMenu(x,y,h);
 setTimeout(()=>{
  ["textColor","fontSize","lineColor","lineWidth","zoomColor"].forEach(id=>{const q=$(id);if(q)q.oninput=()=>{if(id==="textColor")e.color=q.value;if(id==="fontSize")e.fontSize=Number(q.value);if(id==="lineColor")e.color=q.value;if(id==="lineWidth")e.lineWidth=Number(q.value);if(id==="zoomColor")e.color=q.value;renderOverlay()}});
 },0);
}

function showConnectionMenu(x,y,id){
 const c=connections.find(v=>v.id===id);if(!c)return;menuTarget={type:"connection",id};
 openMenu(x,y,`<div class="menu-title">接続線</div>
 <select id="lineStyle"><option value="solid" ${c.style==="solid"?"selected":""}>直線</option><option value="orthogonal" ${c.style==="orthogonal"?"selected":""}>折れ線</option><option value="wave" ${c.style==="wave"?"selected":""}>波線</option><option value="dashed" ${c.style==="dashed"?"selected":""}>破線</option></select>
 <div class="field">終端<select id="endMarker"><option value="none" ${c.endMarker==="none"?"selected":""}>なし</option><option value="arrow" ${c.endMarker==="arrow"?"selected":""}>矢印</option></select></div>
 <hr><button data-action="deleteConnection">接続線を削除</button>`);
 $("lineStyle").onchange=e=>{c.style=e.target.value;renderConnections()};
 $("endMarker").onchange=e=>{c.endMarker=e.target.value;renderConnections()};
}

function openMenu(x,y,html){menu.innerHTML=html;menu.style.display="block";menu.style.left=Math.max(4,Math.min(x,innerWidth-290))+"px";menu.style.top=Math.max(4,Math.min(y,innerHeight-330))+"px"}

menu.addEventListener("click",ev=>{
 const a=ev.target.dataset.action;if(!a||!menuTarget)return;
 if(menuTarget.type==="element"){
  const e=findElement(menuTarget.id);if(!e)return;
  if(a==="editText"){const v=prompt("コメント",e.text);if(v!==null)e.text=v}
  if(a==="rect")e.shape="rect";if(a==="round")e.shape="round";if(a==="circle")e.shape="circle";
  if(a==="connect")createConnection();
  if(a==="delete"){elements=elements.filter(x=>x.id!==e.id);connections=connections.filter(c=>c.from!==e.id&&c.to!==e.id);selected=[];selectedConnection=null}
  renderOverlay();renderRows();
 }
 if(menuTarget.type==="connection"&&a==="deleteConnection"){connections=connections.filter(c=>c.id!==menuTarget.id);selectedConnection=null;renderConnections();renderRows()}
 menu.style.display="none";
});
document.addEventListener("pointerdown",ev=>{if(!menu.contains(ev.target))menu.style.display="none"});

function addElement(type,x,y){
 const r=wrap.getBoundingClientRect(),px=(x-r.left)/r.width*100,py=(y-r.top)/r.height*100;
 const size={comment:[19,8],highlight:[23,15],zoombox:[18,11],skip:[12,5]}[type];
 const e={id:nextId++,type,label:type==="comment"?"コメント":type==="highlight"?"強調枠":type==="zoombox"?"拡大枠":"スキップ",start:Math.max(0,current-1),end:Math.min(duration,current+8),x:Math.max(0,Math.min(100-size[0],px-size[0]/2)),y:Math.max(0,Math.min(100-size[1],py-size[1]/2)),w:size[0],h:size[1],text:type==="comment"?"新しいコメント":"",shape:"rect",fontSize:18,color:colors[type]||"#fff",lineWidth:3};
 elements.push(e);selected=[e.id];renderOverlay();renderRows();
}

wrap.addEventListener("contextmenu",ev=>{
 ev.preventDefault();if(ev.target.closest(".element")||ev.target.closest(".connection-hit"))return;
 menuTarget={type:"add",x:ev.clientX,y:ev.clientY};
 openMenu(ev.clientX,ev.clientY,`<div class="menu-title">要素を追加</div><button data-add="comment">コメント</button><button data-add="highlight">強調枠</button><button data-add="zoombox">拡大枠</button><button data-add="skip">スキップ</button>`);
});
menu.addEventListener("click",ev=>{const t=ev.target.dataset.add;if(t&&menuTarget?.type==="add"){addElement(t,menuTarget.x,menuTarget.y);menu.style.display="none"}});
wrap.addEventListener("pointerdown",ev=>{if(ev.button===0&&!ev.target.closest(".element")&&!ev.target.closest(".connection-hit"))clearSelection()});

function lanesFor(items){
 const lanes=[];[...items].sort((a,b)=>a.start-b.start||a.end-b.end).forEach(e=>{let n=0;while(lanes[n]?.some(x=>e.start<x.end&&e.end>x.start))n++;(lanes[n]||(lanes[n]=[])).push(e);e._lane=n});return lanes.length||1;
}
function renderAxis(){
 const width=Math.max(scroll.clientWidth-100,duration*pxPerSec());content.style.width=width+100+"px";axis.style.width=width+"px";rows.style.width=width+"px";axis.innerHTML="";
 const step=duration>120?30:duration>60?10:5;
 for(let t=0;t<=duration+.001;t+=step){const q=document.createElement("div");q.className="tick";q.style.left=(t*pxPerSec())+"px";q.textContent=fmt(t);axis.appendChild(q)}
 playhead.style.left=(100+current*pxPerSec())+"px";
}
function renderRows(){
 rows.innerHTML="";
 [["comment","コメント","#4da3ff"],["highlight","強調枠","#f04444"],["zoombox","拡大枠","#5c6370"],["skip","スキップ","#ff9800"]].forEach(([type,label,color])=>{
  const items=elements.filter(e=>e.type===type),count=lanesFor(items),row=document.createElement("div");row.className="row";row.style.height=count*52+"px";
  const lab=document.createElement("div");lab.className="row-label";lab.style.height=count*52+"px";lab.innerHTML=`<span class="dot" style="background:${color}"></span>${label}`;row.appendChild(lab);
  const lane=document.createElement("div");lane.className="lane";lane.style.height=count*52+"px";
  items.forEach(e=>{
   const bar=document.createElement("div");bar.className="bar"+(selected.includes(e.id)?" selected":"")+(selected.length>1&&selected.includes(e.id)?" multi":"");
   bar.style.color=colors[e.type];bar.style.left=e.start*pxPerSec()+"px";bar.style.width=Math.max(8,(e.end-e.start)*pxPerSec())+"px";bar.style.top=9+e._lane*52+"px";bar.dataset.id=e.id;
   bar.innerHTML=`<span>${e.label}</span><i class="bar-handle left"></i><i class="bar-handle right"></i>`;
   bar.addEventListener("pointerdown",ev=>{if(ev.button!==0)return;ev.stopPropagation();if(!selected.includes(e.id))selectElement(e.id,ev);drag={kind:ev.target.classList.contains("bar-handle")?"timelineResize":"timelineMove",el:e,x:ev.clientX,start:e.start,end:e.end,side:ev.target.classList.contains("left")?"left":"right"};window.addEventListener("pointermove",timelineDrag);window.addEventListener("pointerup",endTimelineDrag,{once:true})});
   bar.addEventListener("contextmenu",ev=>{ev.preventDefault();ev.stopPropagation();if(!selected.includes(e.id))selectElement(e.id,ev);showElementMenu(ev.clientX,ev.clientY,e.id)});
   lane.appendChild(bar);
  });row.appendChild(lane);rows.appendChild(row);
 });
}
function timelineDrag(ev){
 if(!drag)return;const dt=(ev.clientX-drag.x)/pxPerSec();
 if(drag.kind==="timelineMove"){const len=drag.end-drag.start;drag.el.start=Math.max(0,Math.min(duration-len,drag.start+dt));drag.el.end=drag.el.start+len}
 else if(drag.side==="left")drag.el.start=Math.max(0,Math.min(drag.end-.2,drag.start+dt));
 else drag.el.end=Math.min(duration,Math.max(drag.start+.2,drag.end+dt));
 renderRows();renderOverlay();
}
function endTimelineDrag(){drag=null;window.removeEventListener("pointermove",timelineDrag)}

function updateTime(){
 current=Math.max(0,Math.min(duration,video.currentTime||0));$("time").textContent=fmt(current)+" / "+fmt(duration);
 playhead.style.left=(100+current*pxPerSec())+"px";renderOverlay();
 const skip=elements.find(e=>e.type==="skip"&&current>=e.start&&current<e.end);if(skip&&!video.paused)video.currentTime=skip.end;
}
function saveData(){
 localStorage.setItem("video-editor-mock",JSON.stringify({duration,elements,connections,nextId}));
 toastMsg("編集結果を保存しました");$("status").textContent="編集結果を保存済み";
}
function loadSaved(){
 try{const d=JSON.parse(localStorage.getItem("video-editor-mock"));if(!d)return false;duration=d.duration||duration;elements=d.elements||elements;connections=d.connections||[];nextId=d.nextId||5;renderAxis();renderRows();renderOverlay();updateTime();toastMsg("保存内容を復元しました");return true}catch(e){return false}
}

$("loadBtn").onclick=()=>$("fileInput").click();
$("fileInput").onchange=ev=>{const f=ev.target.files[0];if(!f)return;video.src=URL.createObjectURL(f);video.load()};
$("saveBtn").onclick=saveData;$("loadSaveBtn").onclick=loadSaved;
$("playBtn").onclick=()=>{if(!video.src){toastMsg("先に動画を選択してください");return}if(video.paused){video.play();$("playBtn").textContent="⏸"}else{video.pause();$("playBtn").textContent="▶"}};
$("stopBtn").onclick=()=>{video.pause();video.currentTime=0;$("playBtn").textContent="▶"};
$("scale").oninput=ev=>{scale=Number(ev.target.value);$("scaleText").textContent=scale.toFixed(1)+"×";renderAxis();renderRows();updateTime()};

axis.addEventListener("pointerdown",ev=>{const r=axis.getBoundingClientRect();video.currentTime=Math.max(0,Math.min(duration,(ev.clientX-r.left)/pxPerSec()))});
rows.addEventListener("pointerdown",ev=>{if(ev.target.closest(".bar"))return;const r=rows.getBoundingClientRect();video.currentTime=Math.max(0,Math.min(duration,(ev.clientX-r.left)/pxPerSec()))});

video.addEventListener("loadedmetadata",()=>{duration=video.duration||60;$("status").textContent="動画編集中";$("hint").style.display="none";renderAxis();renderRows();updateTime()});
video.addEventListener("timeupdate",updateTime);
video.addEventListener("ended",()=>$("playBtn").textContent="▶");
window.addEventListener("resize",renderAxis);

document.addEventListener("keydown",ev=>{
 if((ev.ctrlKey||ev.metaKey)&&ev.key.toLowerCase()==="s"){ev.preventDefault();saveData()}
 if(ev.key==="Escape"){selected=[];selectedConnection=null;menu.style.display="none";renderOverlay();renderRows()}
});

renderAxis();renderRows();renderOverlay();updateTime();
</script>
</body>
</html>

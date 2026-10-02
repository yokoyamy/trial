<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f4f6;color:#222;font:14px Arial,sans-serif}
button,input,select,textarea{font:inherit}
button{padding:6px 10px;border:1px solid #aaa;border-radius:4px;background:#fff;cursor:pointer}
button.primary{background:#2563eb;color:#fff;border-color:#2563eb}
button.danger{color:#b91c1c}
.hidden{display:none!important}
.wrap,.editor{max-width:1050px;margin:auto;padding:16px}
.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px;margin-bottom:14px}
.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.grow{flex:1}
.muted{color:#666}
.orig{border:2px solid #bfdbfe;border-radius:5px;margin:8px 0}
.orig .head{padding:9px;background:#dbeafe}
.orig .body{padding:8px}
.top{padding:9px 12px;background:#1f2937;color:#fff}
.video-wrap{max-width:800px;margin:12px auto}
.video{position:relative;height:420px;background:#000;overflow:hidden;user-select:none}
.video video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none}
.demo{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#777;font-size:26px;pointer-events:none}
#objects{position:absolute;inset:0}
#lines{position:absolute;inset:0;width:100%;height:100%;overflow:visible;z-index:3;pointer-events:none}
.obj{position:absolute;z-index:10;touch-action:none;cursor:grab;overflow:visible}
.obj:active{cursor:grabbing}
.obj.sel{outline:2px dashed #fbbf24}
.obj.source{outline:3px solid #22c55e}
.obj.target{outline:3px dashed #38bdf8}
.resize{position:absolute;right:-1px;bottom:-1px;width:12px;height:12px;background:#fbbf24;cursor:nwse-resize}
.connect-port{position:absolute;width:10px;height:10px;border:2px solid #fff;background:#22c55e;border-radius:50%;z-index:30;display:none}
.obj.sel .connect-port{display:block}
.connect-port.t{top:-6px;left:50%;margin-left:-5px}
.connect-port.r{right:-6px;top:50%;margin-top:-5px}
.connect-port.b{bottom:-6px;left:50%;margin-left:-5px}
.connect-port.l{left:-6px;top:50%;margin-top:-5px}
.connecting .obj{cursor:crosshair}
.line{fill:none;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
.line-hit{fill:none;stroke:transparent;stroke-width:18;pointer-events:stroke;cursor:pointer}
.line.selected{stroke:#fbbf24}
.temp{fill:none;stroke:#22c55e;stroke-width:3;stroke-dasharray:7 5;pointer-events:none}
.line-handle{fill:#fff;stroke:#f59e0b;stroke-width:2;pointer-events:auto;cursor:move}
.timeline{background:#fff;border:1px solid #aaa;border-radius:5px;overflow:hidden}
.ruler{height:30px;border-bottom:1px solid #aaa;position:relative}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #aaa;padding-left:3px;font-size:10px}
.lanes{height:190px;position:relative}
.clip{position:absolute;height:24px;border-radius:3px;color:#fff;padding:4px 6px;font-size:11px;white-space:nowrap;overflow:hidden;cursor:grab}
.clip.sel{outline:2px solid #fbbf24}
.clip.skip{background:#6b7280!important}
.cursor{position:absolute;top:0;bottom:0;border-left:2px solid #e11d48;z-index:20;pointer-events:none}
.cursor:before{content:"";position:absolute;top:0;left:-6px;width:12px;height:12px;border-radius:50%;background:#e11d48}
.overview{height:28px;background:#e5e7eb;border:1px solid #aaa;margin:8px 4px;position:relative}
.view-range{position:absolute;top:0;bottom:0;background:#93c5fd66;border:2px solid #2563eb}
.scale{width:150px}
.menu{position:fixed;z-index:100;background:#fff;border:1px solid #aaa;border-radius:5px;padding:10px;box-shadow:0 4px 16px #0003;width:290px;max-height:80vh;overflow:auto}
.menu h4{margin:0 0 8px}
.menu label{display:block;margin:7px 0}
.menu input[type=text],.menu input[type=number],.menu select{width:100%;padding:5px}
.menu .choices{display:flex;gap:4px;flex-wrap:wrap}
.menu .choices button.active{background:#dbeafe;border-color:#2563eb}
.colors{display:flex;gap:4px;flex-wrap:wrap}
.color{width:22px;height:22px;padding:0;border:1px solid #777}
.color.active{outline:2px solid #2563eb}
hr{border:0;border-top:1px solid #ddd;margin:10px 0}
.notice{padding:8px;background:#fef3c7;border:1px solid #f59e0b;border-radius:4px}
</style>
</head>
<body>

<div id="home" class="wrap">
<div class="card">
<h2>動画を選択</h2>
<p class="muted">編集する動画を明示的に選択してください。</p>
<button id="choose" class="primary">動画を選択</button>
<input id="file" type="file" accept="video/*" class="hidden">
</div>
<div class="card">
<h2>オリジナル動画</h2>
<div id="videoList"></div>
</div>
</div>

<div id="editor" class="editor hidden">
<div class="top row">
<b id="videoName"></b>
<span>／</span>
<span id="workName">新しい編集作業</span>
<span class="grow"></span>
<span id="dirty" class="hidden">未保存</span>
<button id="save">保存</button>
<button id="make">編集結果を動画にする</button>
<button id="finish">編集作業を終了する</button>
</div>

<div class="video-wrap">
<div id="video" class="video"></div>
</div>

<div class="card">
<div class="row">
<button id="play" class="primary">▶ 再生</button>
<button id="pause">⏸ 一時停止</button>
<button id="stop">■ 停止</button>
<span id="position" class="muted"></span>
<span class="grow"></span>
<button id="add">＋ 要素を追加</button>
<button id="fit">全体表示</button>
<label>スケール <input id="scale" class="scale" type="range" min="1" max="4" step=".1" value="1"></label>
</div>
</div>

<div class="timeline">
<div id="ruler" class="ruler"></div>
<div id="lanes" class="lanes"></div>
</div>
<div id="overview" class="overview"></div>

<p class="muted">
動画上の要素はドラッグして移動できます。接続線を作る場合は、選択した要素の緑色の接続点から別の要素へドラッグしてください。
</p>
</div>

<div id="popup"></div>

<script>
(function(){
"use strict";

const $=id=>document.getElementById(id);
const TYPES={
 comment:{name:"コメント",color:"#2563eb"},
 highlight:{name:"強調枠",color:"#dc2626"},
 zoom:{name:"拡大枠",color:"#059669"},
 skip:{name:"スキップ",color:"#6b7280"}
};
const COLORS=["#000000","#ffffff","#ef4444","#f97316","#eab308","#22c55e","#06b6d4","#3b82f6","#8b5cf6","#ec4899","#6b7280","#92400e"];

let no=1;
let sourceVideo=null;
let state=null;
let timer=null;
let connectionDrag=null;

const uid=p=>p+(no++);
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const find=(a,id)=>a.find(x=>x.id===id);
const esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const fmt=s=>Math.floor(s/60)+":"+String(Math.floor(s%60)).padStart(2,"0");

$("choose").onclick=()=>$("file").click();

$("file").onchange=function(){
 const f=this.files[0];
 if(!f)return;
 const url=URL.createObjectURL(f);
 const v=document.createElement("video");
 v.preload="metadata";
 v.onloadedmetadata=function(){
  sourceVideo={id:uid("v"),name:f.name,url:url,dur:v.duration};
  openEditor();
 };
 v.onerror=function(){alert("動画の読み込みに失敗しました。")};
 v.src=url;
};

function openEditor(){
 state={
  dur:sourceVideo.dur,
  pos:0,
  scale:1,
  selected:[],
  selectedLine:null,
  objects:[],
  lines:[],
  dirty:false
 };
 $("home").classList.add("hidden");
 $("editor").classList.remove("hidden");
 $("videoName").textContent=sourceVideo.name;
 $("workName").textContent="新しい編集作業";
 $("video").innerHTML="";
 const v=document.createElement("video");
 v.src=sourceVideo.url;
 v.preload="auto";
 $("video").appendChild(v);
 state.video=v;
 const layer=document.createElement("div");
 layer.id="objects";
 $("video").appendChild(layer);
 const svg=document.createElementNS("http://www.w3.org/2000/svg","svg");
 svg.id="lines";
 layer.appendChild(svg);
 render();
}

function mark(){
 state.dirty=true;
 $("dirty").classList.remove("hidden");
}

function videoPoint(e){
 const r=$("video").getBoundingClientRect();
 return {
  x:clamp((e.clientX-r.left)/r.width,0,1),
  y:clamp((e.clientY-r.top)/r.height,0,1)
 };
}

function addObject(type){
 const start=clamp(state.pos,0,Math.max(0,state.dur-5));
 const o={
  id:uid("e"),
  type:type,
  s:start,
  e:Math.min(state.dur,start+5),
  x:.28,
  y:.25,
  w:.32,
  h:.20,
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
   width:4,
   color:"#ef4444",
   fill:false,
   style:"solid"
  };
 }

 if(type==="zoom"){
  o.a={zoom:1.5};
 }

 if(type==="skip"){
  o.x=0;
  o.y=0;
  o.w=0;
  o.h=0;
 }

 state.objects.push(o);
 state.selected=[o.id];
 state.selectedLine=null;
 mark();
 closeMenu();
 render();
}

function addMenu(x,y){
 showMenu(x,y,`
  <h4>要素を追加</h4>
  <button data-add="comment">コメント</button>
  <button data-add="highlight">強調枠</button>
  <button data-add="zoom">拡大枠</button>
  <button data-add="skip">スキップ</button>
 `);
}

$("add").onclick=e=>addMenu(e.clientX,e.clientY);

$("popup").onclick=function(e){
 const b=e.target.closest("[data-add]");
 if(b)addObject(b.dataset.add);
};

function render(){
 if(!state)return;
 renderObjects();
 renderLines();
 renderTimeline();
 updatePosition();
}

function renderObjects(){
 const layer=$("objects");
 const svg=layer.querySelector("#lines");
 layer.innerHTML="";
 layer.appendChild(svg);

 state.objects.forEach(o=>{
  if(o.type==="skip")return;
  if(state.pos<o.s||state.pos>o.e)return;

  const d=document.createElement("div");
  d.className="obj"+(state.selected.includes(o.id)?" sel":"");
  if(connectionDrag){
   if(connectionDrag.from===o.id)d.classList.add("source");
   if(connectionDrag.target===o.id)d.classList.add("target");
  }
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
   d.style.padding="5px";
   d.style.background=o.a.fill?o.a.bg:"transparent";
   d.style.border=o.a.border?o.a.borderWidth+"px solid "+o.a.borderColor:"none";
  }

  if(o.type==="highlight"){
   d.style.border=o.a.width+"px "+o.a.style+" "+o.a.color;
   d.style.background=o.a.fill?o.a.color+"33":"transparent";
  }

  if(o.type==="zoom"){
   d.style.border="2px solid #059669";
   d.style.background="#05966922";
   d.innerHTML='<span style="background:#059669;color:#fff;padding:2px 5px">拡大 '+o.a.zoom+'倍</span>';
  }

  if(state.selected.includes(o.id)){
   ["t","r","b","l"].forEach(side=>{
    const p=document.createElement("i");
    p.className="connect-port "+side;
    p.dataset.port=side;
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

function otherObjects(line){
 return state.objects.filter(o=>o.id!==line.from&&o.id!==line.to&&o.type!=="skip");
}

function intersectsRect(x1,y1,x2,y2,o,pad=.015){
 return Math.max(x1,x2)>=o.x-pad&&
        Math.min(x1,x2)<=o.x+o.w+pad&&
        Math.max(y1,y2)>=o.y-pad&&
        Math.min(y1,y2)<=o.y+o.h+pad;
}

function route(line){
 const a=find(state.objects,line.from);
 const b=find(state.objects,line.to);
 if(!a||!b)return "";

 const p=anchor(a,line.fromSide);
 const q=anchor(b,line.toSide);

 if(line.shape==="直線")
  return `M ${p.x*100} ${p.y*100} L ${q.x*100} ${q.y*100}`;

 const obstacles=otherObjects(line);
 const clear=(pts)=>{
  for(let i=0;i<pts.length-1;i++){
   for(const o of obstacles){
    if(intersectsRect(pts[i].x,pts[i].y,pts[i+1].x,pts[i+1].y,o))return false;
   }
  }
  return true;
 };

 const candidates=[];

 if(line.shape==="折れ線"){
  const xs=[p.x,q.x,(p.x+q.x)/2,.1,.9];
  const ys=[p.y,q.y,(p.y+q.y)/2,.1,.9];
  xs.forEach(mx=>{
   candidates.push([
    p,{x:mx,y:p.y},{x:mx,y:q.y},q
   ]);
  });
  ys.forEach(my=>{
   candidates.push([
    p,{x:p.x,y:my},{x:q.x,y:my},q
   ]);
  });
 }else{
  const mx=(p.x+q.x)/2;
  const my=(p.y+q.y)/2;
  candidates.push([
   p,{x:mx,y:p.y},{x:mx,y:q.y},q
  ]);
  candidates.push([
   p,{x:p.x,y:my},{x:q.x,y:my},q
  ]);
 }

 for(const pts of candidates){
  if(clear(pts))return pts.map((v,i)=>(i?"L ":"M ")+v.x*100+" "+v.y*100).join(" ");
 }

 return `M ${p.x*100} ${p.y*100} L ${q.x*100} ${q.y*100}`;
}

function wavePath(line){
 const a=find(state.objects,line.from);
 const b=find(state.objects,line.to);
 if(!a||!b)return "";

 const p=anchor(a,line.fromSide);
 const q=anchor(b,line.toSide);
 const dx=q.x-p.x,dy=q.y-p.y;
 const len=Math.sqrt(dx*dx+dy*dy)||1;
 const nx=-dy/len*.018;
 const ny=dx/len*.018;
 const count=16;
 let d=`M ${p.x*100} ${p.y*100}`;
 for(let i=1;i<=count;i++){
  const t=i/count;
  const off=(i%2?1:-1);
  d+=` L ${(p.x+dx*t+nx*off)*100} ${(p.y+dy*t+ny*off)*100}`;
 }
 return d;
}

function renderLines(){
 const svg=$("lines");
 while(svg.firstChild)svg.removeChild(svg.firstChild);

 state.lines.forEach(line=>{
  let d=line.shape==="波線"?wavePath(line):route(line);
  if(!d)return;

  const g=document.createElementNS("http://www.w3.org/2000/svg","g");
  g.dataset.line=line.id;

  const hit=document.createElementNS("http://www.w3.org/2000/svg","path");
  hit.setAttribute("d",d);
  hit.classList.add("line-hit");

  const visible=document.createElementNS("http://www.w3.org/2000/svg","path");
  visible.setAttribute("d",d);
  visible.setAttribute("stroke",line.color);
  visible.setAttribute("stroke-width",line.width);
  visible.setAttribute("stroke-dasharray",line.dash);
  visible.classList.add("line");
  if(state.selectedLine===line.id)visible.classList.add("selected");

  if(line.end==="arrow"){
   const defs=document.createElementNS("http://www.w3.org/2000/svg","defs");
   const marker=document.createElementNS("http://www.w3.org/2000/svg","marker");
   const mid="m"+line.id;
   marker.id=mid;
   marker.setAttribute("viewBox","0 0 10 10");
   marker.setAttribute("refX","9");
   marker.setAttribute("refY","5");
   marker.setAttribute("markerWidth","6");
   marker.setAttribute("markerHeight","6");
   marker.setAttribute("orient","auto");
   const mp=document.createElementNS("http://www.w3.org/2000/svg","path");
   mp.setAttribute("d","M0 0 L10 5 L0 10 Z");
   mp.setAttribute("fill",line.color);
   marker.appendChild(mp);
   defs.appendChild(marker);
   g.appendChild(defs);
   visible.setAttribute("marker-end","url(#"+mid+")");
  }

  g.appendChild(hit);
  g.appendChild(visible);

  if(state.selectedLine===line.id){
   const a=find(state.objects,line.from);
   const b=find(state.objects,line.to);
   if(a&&b){
    ["from","to"].forEach(kind=>{
     const o=kind==="from"?a:b;
     const side=kind==="from"?line.fromSide:line.toSide;
     const p=anchor(o,side);
     const h=document.createElementNS("http://www.w3.org/2000/svg","circle");
     h.classList.add("line-handle");
     h.dataset.handle=kind;
     h.dataset.line=line.id;
     h.setAttribute("cx",p.x*100);
     h.setAttribute("cy",p.y*100);
     h.setAttribute("r","0.9");
     g.appendChild(h);
    });
   }
  }

  svg.appendChild(g);
 });

 if(connectionDrag){
  const p=connectionDrag.start;
  const q=connectionDrag.current;
  const t=document.createElementNS("http://www.w3.org/2000/svg","path");
  t.classList.add("temp");
  t.setAttribute("d",`M ${p.x*100} ${p.y*100} L ${q.x*100} ${q.y*100}`);
  svg.appendChild(t);
 }
}

function renderTimeline(){
 const lanes=$("lanes");
 const width=lanes.clientWidth||800;
 const start=0,end=state.dur;
 let ruler="";

 const step=state.dur<=30?5:state.dur<=120?10:30;
 for(let t=0;t<=state.dur;t+=step)
  ruler+=`<div class="tick" style="left:${t/state.dur*100}%">${fmt(t)}</div>`;

 $("ruler").innerHTML=ruler;

 const rows=[];
 let html="";

 state.objects.forEach(o=>{
  let row=0;
  while(rows[row]!=null&&rows[row]>o.s)row++;
  rows[row]=o.e;

  const left=o.s/end*100;
  const width=(o.e-o.s)/end*100;
  html+=`<div class="clip ${o.type==="skip"?"skip":""} ${state.selected.includes(o.id)?"sel":""}" data-id="${o.id}" style="left:${left}%;width:${Math.max(width,.5)}%;top:${8+row*30}px;background:${TYPES[o.type].color}">${TYPES[o.type].name} ${fmt(o.s)}-${fmt(o.e)}</div>`;
 });

 lanes.innerHTML=html;

 if(state.pos<=state.dur){
  const c=document.createElement("div");
  c.className="cursor";
  c.style.left=state.pos/state.dur*100+"%";
  lanes.appendChild(c);
 }

 $("overview").innerHTML=`<div class="view-range" style="left:${state.viewStart/state.dur*100}%;width:${(state.viewEnd-state.viewStart)/state.dur*100}%"></div>`;
}

function updatePosition(){
 $("position").textContent=fmt(state.pos)+" / "+fmt(state.dur);
}

function showMenu(x,y,html){
 closeMenu();
 $("popup").innerHTML=`<div class="menu">${html}</div>`;
 const m=$("popup").firstElementChild;
 m.style.left=Math.min(x,innerWidth-m.offsetWidth-8)+"px";
 m.style.top=Math.min(y,innerHeight-m.offsetHeight-8)+"px";
}

function closeMenu(){
 $("popup").innerHTML="";
}

function elementMenu(x,y,o){
 let html=`<h4>${TYPES[o.type].name}</h4>`;

 if(o.type==="comment"){
  html+=`
   <label>コメント本文<input id="mtext" type="text" value="${esc(o.a.text)}"></label>
   <label>文字サイズ<input id="msize" type="number" min="8" max="72" value="${o.a.size}"></label>
   <label>フォント
    <select id="mfont">
     <option ${o.a.font==="Arial"?"selected":""}>Arial</option>
     <option ${o.a.font==="serif"?"selected":""}>serif</option>
     <option ${o.a.font==="sans-serif"?"selected":""}>sans-serif</option>
     <option ${o.a.font==="monospace"?"selected":""}>monospace</option>
    </select>
   </label>
   <label>線<input id="mborder" type="checkbox" ${o.a.border?"checked":""}></label>
   <label>塗り潰し<input id="mfill" type="checkbox" ${o.a.fill?"checked":""}></label>
   <div>文字色</div><div class="colors">${colorButtons("textColor",o.a.textColor)}</div>
   <div>線色</div><div class="colors">${colorButtons("borderColor",o.a.borderColor)}</div>
   <div>背景色</div><div class="colors">${colorButtons("bg",o.a.bg)}</div>
  `;
 }

 if(o.type==="highlight"){
  html+=`
   <div>線の太さ</div>
   <div class="choices">${[2,4,6,8].map(v=>`<button data-width="${v}" class="${o.a.width===v?"active":""}">${v}px</button>`).join("")}</div>
   <label>塗り潰し<input id="hfill" type="checkbox" ${o.a.fill?"checked":""}></label>
   <div>線種</div>
   <div class="choices">
    <button data-style="solid" class="${o.a.style==="solid"?"active":""}">実線</button>
    <button data-style="dotted" class="${o.a.style==="dotted"?"active":""}">点線</button>
    <button data-style="dashed" class="${o.a.style==="dashed"?"active":""}">破線</button>
    <button data-style="double" class="${o.a.style==="double"?"active":""}">一点鎖線</button>
   </div>
  `;
 }

 if(o.type==="zoom"){
  html+=`
   <label>拡大倍率
    <select id="zoom">
     <option value="1.25" ${o.a.zoom==1.25?"selected":""}>1.25倍</option>
     <option value="1.5" ${o.a.zoom==1.5?"selected":""}>1.5倍</option>
     <option value="2" ${o.a.zoom==2?"selected":""}>2倍</option>
     <option value="3" ${o.a.zoom==3?"selected":""}>3倍</option>
    </select>
   </label>
  `;
 }

 if(o.type==="skip"){
  html+=`<div class="notice">スキップは動画上には表示されません。</div>`;
 }

 html+=`
  <hr>
  <button id="startLine">この要素から接続線を作成</button>
  <button id="deleteObject" class="danger">この要素を削除</button>
 `;

 showMenu(x,y,html);

 $("mtext")?.addEventListener("input",e=>{o.a.text=e.target.value;mark();render()});
 $("msize")?.addEventListener("change",e=>{o.a.size=clamp(+e.target.value||18,8,72);mark();render()});
 $("mfont")?.addEventListener("change",e=>{o.a.font=e.target.value;mark();render()});
 $("mborder")?.addEventListener("change",e=>{o.a.border=e.target.checked;mark();render()});
 $("mfill")?.addEventListener("change",e=>{o.a.fill=e.target.checked;mark();render()});
 $("hfill")?.addEventListener("change",e=>{o.a.fill=e.target.checked;mark();render()});
 $("zoom")?.addEventListener("change",e=>{o.a.zoom=+e.target.value;mark();render()});

 document.querySelectorAll("[data-width]").forEach(b=>b.onclick=()=>{
  o.a.width=+b.dataset.width;mark();render();elementMenu(x,y,o);
 });

 document.querySelectorAll("[data-style]").forEach(b=>b.onclick=()=>{
  o.a.style=b.dataset.style;mark();render();elementMenu(x,y,o);
 });

 document.querySelectorAll("[data-color]").forEach(b=>b.onclick=()=>{
  o.a[b.dataset.color]=b.dataset.value;mark();render();elementMenu(x,y,o);
 });

 $("startLine").onclick=()=>{
  closeMenu();
  const r=$("video").getBoundingClientRect();
  const p=anchor(o,"r");
  startConnection(o,p);
 };

 $("deleteObject").onclick=()=>{
  state.objects=state.objects.filter(v=>v.id!==o.id);
  state.lines=state.lines.filter(v=>v.from!==o.id&&v.to!==o.id);
  state.selected=state.selected.filter(v=>v!==o.id);
  state.selectedLine=null;
  mark();
  closeMenu();
  render();
 };
}

function colorButtons(prop,current){
 return COLORS.map(c=>`<button class="color ${c===current?"active":""}" data-color="${prop}" data-value="${c}" style="background:${c}"></button>`).join("");
}

function lineMenu(x,y,line){
 const shapes=["直線","折れ線","波線"];
 const dashes=[
  ["","実線"],
  ["2 5","点線"],
  ["8 5","破線"],
  ["10 5 2 5","一点鎖線"]
 ];
 const sides=[
  ["t","上"],
  ["r","右"],
  ["b","下"],
  ["l","左"]
 ];

 showMenu(x,y,`
  <h4>接続線</h4>
  <div>線形</div>
  <div class="choices">${shapes.map(v=>`<button data-shape="${v}" class="${line.shape===v?"active":""}">${v}</button>`).join("")}</div>
  <div>線種</div>
  <div class="choices">${dashes.map(v=>`<button data-dash="${v[0]}" class="${line.dash===v[0]?"active":""}">${v[1]}</button>`).join("")}</div>
  <label>線の太さ
   <select id="lineWidth">
    <option value="2" ${line.width===2?"selected":""}>2px</option>
    <option value="3" ${line.width===3?"selected":""}>3px</option>
    <option value="5" ${line.width===5?"selected":""}>5px</option>
   </select>
  </label>
  <div>接続元</div>
  <div class="choices">${sides.map(v=>`<button data-from="${v[0]}" class="${line.fromSide===v[0]?"active":""}">${v[1]}</button>`).join("")}</div>
  <div>接続先</div>
  <div class="choices">${sides.map(v=>`<button data-to="${v[0]}" class="${line.toSide===v[0]?"active":""}">${v[1]}</button>`).join("")}</div>
  <label>終端
   <select id="lineEnd">
    <option value="none" ${line.end==="none"?"selected":""}>なし</option>
    <option value="arrow" ${line.end==="arrow"?"selected":""}>矢印</option>
   </select>
  </label>
  <div>線色</div><div class="colors">${colorButtons("color",line.color)}</div>
  <hr>
  <button id="deleteLine" class="danger">接続線を削除</button>
 `);

 document.querySelectorAll("[data-shape]").forEach(b=>b.onclick=()=>{
  line.shape=b.dataset.shape;
  mark();render();lineMenu(x,y,line);
 });

 document.querySelectorAll("[data-dash]").forEach(b=>b.onclick=()=>{
  line.dash=b.dataset.dash;
  mark();render();lineMenu(x,y,line);
 });

 document.querySelectorAll("[data-from]").forEach(b=>b.onclick=()=>{
  line.fromSide=b.dataset.from;
  mark();render();lineMenu(x,y,line);
 });

 document.querySelectorAll("[data-to]").forEach(b=>b.onclick=()=>{
  line.toSide=b.dataset.to;
  mark();render();lineMenu(x,y,line);
 });

 document.querySelectorAll("[data-color]").forEach(b=>b.onclick=()=>{
  line[b.dataset.color]=b.dataset.value;
  mark();render();lineMenu(x,y,line);
 });

 $("lineWidth").onchange=e=>{
  line.width=+e.target.value;
  mark();render();
 };

 $("lineEnd").onchange=e=>{
  line.end=e.target.value;
  mark();render();
 };

 $("deleteLine").onclick=()=>{
  state.lines=state.lines.filter(v=>v.id!==line.id);
  state.selectedLine=null;
  mark();closeMenu();render();
 };
}

function startConnection(o,start){
 connectionDrag={
  from:o.id,
  start:start,
  current:start,
  target:null
 };
 $("video").classList.add("connecting");

 const move=e=>{
  connectionDrag.current=videoPoint(e);
  const n=document.elementFromPoint(e.clientX,e.clientY)?.closest(".obj");
  connectionDrag.target=n?find(state.objects,n.dataset.id):null;
  if(connectionDrag.target?.id===o.id)connectionDrag.target=null;
  render();
 };

 const up=e=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);

  if(connectionDrag.target){
   const target=connectionDrag.target;
   const fromSide=nearestSide(o,connectionDrag.start);
   const toSide=nearestSide(target,connectionDrag.current);

   state.lines.push({
    id:uid("l"),
    from:o.id,
    to:target.id,
    fromSide:fromSide,
    toSide:toSide,
    shape:"直線",
    dash:"",
    width:3,
    color:"#f59e0b",
    end:"none"
   });

   state.selectedLine=state.lines[state.lines.length-1].id;
   state.selected=[];
   mark();
  }

  connectionDrag=null;
  $("video").classList.remove("connecting");
  render();
 };

 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

function nearestSide(o,p){
 const sides={
  t:Math.abs(p.y-o.y),
  r:Math.abs(p.x-(o.x+o.w)),
  b:Math.abs(p.y-(o.y+o.h)),
  l:Math.abs(p.x-o.x)
 };
 return Object.keys(sides).sort((a,b)=>sides[a]-sides[b])[0];
}

$("video").addEventListener("pointerdown",function(e){
 if(e.button!==0)return;

 const handle=e.target.closest(".connect-port");
 if(handle){
  const n=e.target.closest(".obj");
  const o=find(state.objects,n.dataset.id);
  startConnection(o,anchor(o,handle.dataset.port));
  e.preventDefault();
  return;
 }

 const resize=e.target.closest(".resize");
 if(resize){
  const n=e.target.closest(".obj");
  resizeObject(find(state.objects,n.dataset.id),e);
  e.preventDefault();
  return;
 }

 const n=e.target.closest(".obj");

 if(!n){
  state.selected=[];
  state.selectedLine=null;
  render();
  return;
 }

 const o=find(state.objects,n.dataset.id);
 state.selectedLine=null;

 if(e.shiftKey){
  if(state.selected.includes(o.id))
   state.selected=state.selected.filter(v=>v!==o.id);
  else
   state.selected.push(o.id);
  render();
  return;
 }

 state.selected=[o.id];
 render();
 moveObject(o,e);
});

function moveObject(o,e){
 const r=$("video").getBoundingClientRect();
 const sx=e.clientX,sy=e.clientY,ox=o.x,oy=o.y;

 const move=ev=>{
  const dx=(ev.clientX-sx)/r.width;
  const dy=(ev.clientY-sy)/r.height;
  o.x=clamp(ox+dx,0,1-o.w);
  o.y=clamp(oy+dy,0,1-o.h);
  mark();
  render();
 };

 const up=()=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);
 };

 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

function resizeObject(o,e){
 const r=$("video").getBoundingClientRect();
 const sx=e.clientX,sy=e.clientY,ow=o.w,oh=o.h;

 const move=ev=>{
  o.w=clamp(ow+(ev.clientX-sx)/r.width,.05,1-o.x);
  o.h=clamp(oh+(ev.clientY-sy)/r.height,.05,1-o.y);
  mark();
  render();
 };

 const up=()=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);
 };

 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

$("video").addEventListener("contextmenu",function(e){
 e.preventDefault();

 const lineGroup=e.target.closest("[data-line]");
 if(lineGroup){
  const line=find(state.lines,lineGroup.dataset.line);
  state.selected=[];
  state.selectedLine=line.id;
  render();
  lineMenu(e.clientX,e.clientY,line);
  return;
 }

 const n=e.target.closest(".obj");
 if(n){
  const o=find(state.objects,n.dataset.id);
  state.selected=[o.id];
  state.selectedLine=null;
  render();
  elementMenu(e.clientX,e.clientY,o);
  return;
 }

 addMenu(e.clientX,e.clientY);
});

$("video").addEventListener("click",function(e){
 const g=e.target.closest("[data-line]");
 if(g){
  state.selected=[];
  state.selectedLine=g.dataset.line;
  render();
 }
});

$("lanes").addEventListener("pointerdown",function(e){
 const clip=e.target.closest(".clip");
 if(!clip){
  seekFromTimeline(e);
  return;
 }

 const o=find(state.objects,clip.dataset.id);
 if(!o)return;

 if(e.shiftKey){
  state.selected.includes(o.id)
   ?state.selected=state.selected.filter(v=>v!==o.id)
   :state.selected.push(o.id);
 }else{
  state.selected=[o.id];
 }

 state.selectedLine=null;
 render();

 const r=$("lanes").getBoundingClientRect();
 const sx=e.clientX;
 const os=o.s;
 const oe=o.e;
 const duration=oe-os;

 const move=ev=>{
  const dt=(ev.clientX-sx)/r.width*state.dur;
  o.s=clamp(os+dt,0,state.dur-duration);
  o.e=o.s+duration;
  mark();
  render();
 };

 const up=()=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);
 };

 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
 e.preventDefault();
});

function seekFromTimeline(e){
 const r=$("lanes").getBoundingClientRect();
 state.pos=clamp((e.clientX-r.left)/r.width*state.dur,0,state.dur);
 state.video.currentTime=state.pos;
 render();
}

$("ruler").addEventListener("pointerdown",seekFromTimeline);

$("play").onclick=()=>{
 if(timer)return;

 state.video.currentTime=state.pos;
 state.video.play();

 timer=setInterval(()=>{
  state.pos=state.video.currentTime;

  const skip=state.objects.find(o=>
   o.type==="skip"&&state.pos>=o.s&&state.pos<o.e
  );

  if(skip){
   state.pos=skip.e;
   state.video.currentTime=skip.e;
  }

  if(state.pos>=state.dur){
   state.pos=state.dur;
   clearInterval(timer);
   timer=null;
   state.video.pause();
  }

  render();
 },50);
};

$("pause").onclick=()=>{
 state.video.pause();
 clearInterval(timer);
 timer=null;
};

$("stop").onclick=()=>{
 state.video.pause();
 clearInterval(timer);
 timer=null;
 state.pos=0;
 state.video.currentTime=0;
 render();
};

$("scale").oninput=function(){
 state.scale=+this.value;
 renderTimelineScaled();
};

function renderTimelineScaled(){
 const lanes=$("lanes");
 lanes.style.transformOrigin="left top";
 lanes.style.transform="scaleX("+state.scale+")";
 renderTimeline();
}

$("fit").onclick=()=>{
 state.scale=1;
 $("scale").value=1;
 state.viewStart=0;
 state.viewEnd=state.dur;
 render();
};

$("save").onclick=()=>{
 const name=prompt("編集作業名を入力してください",$("workName").textContent);
 if(name===null)return;
 $("workName").textContent=name||"編集作業";
 state.dirty=false;
 $("dirty").classList.add("hidden");
 alert("編集作業を保存しました。");
};

$("make").onclick=()=>{
 if(state.dirty){
  const ok=confirm("未保存の変更があります。保存してから編集結果を作成しますか？");
  if(ok)$("save").click();
 }
 alert("編集結果動画の作成を開始しました。");
};

$("finish").onclick=()=>{
 if(state.dirty){
  const result=confirm("未保存の変更があります。保存して終了しますか？");
  if(result)$("save").click();
 }
 clearInterval(timer);
 timer=null;
 state.video.pause();
 state=null;
 $("editor").classList.add("hidden");
 $("home").classList.remove("hidden");
};

document.addEventListener("pointerdown",function(e){
 if(!e.target.closest(".menu"))closeMenu();
},true);

document.addEventListener("keydown",function(e){
 if(e.key==="Escape"){
  connectionDrag=null;
  $("video")?.classList.remove("connecting");
  closeMenu();
  if(state)render();
 }
});

window.addEventListener("resize",function(){
 if(state)render();
});

})();
</script>
</body>
</html>

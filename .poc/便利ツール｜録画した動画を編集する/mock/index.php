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
button,input,select{font:inherit}
button{padding:6px 10px;border:1px solid #aaa;border-radius:4px;background:#fff;cursor:pointer}
button.primary{background:#2563eb;color:#fff;border-color:#2563eb}
button.danger{color:#b91c1c}
.hidden{display:none!important}
.wrap{max-width:1050px;margin:auto;padding:16px}
.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px;margin-bottom:14px}
.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.grow{flex:1}.muted{color:#666}
.top{padding:9px 12px;background:#1f2937;color:#fff}
.video-wrap{max-width:800px;margin:12px auto}
.video{position:relative;height:420px;background:#000;overflow:hidden;user-select:none}
.video video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none}
#objects{position:absolute;inset:0}
#lines{position:absolute;inset:0;width:100%;height:100%;z-index:20;pointer-events:none;overflow:visible}
.obj{position:absolute;z-index:30;cursor:grab;touch-action:none}
.obj:active{cursor:grabbing}
.obj.sel{outline:2px dashed #fbbf24}
.obj.source{outline:3px solid #22c55e}
.obj.target{outline:3px dashed #38bdf8}
.connect-port{display:none;position:absolute;width:12px;height:12px;border:2px solid #fff;background:#22c55e;border-radius:50%;z-index:50;cursor:crosshair}
.obj.sel .connect-port{display:block}
.connect-port.t{top:-6px;left:50%;transform:translateX(-50%)}
.connect-port.r{right:-6px;top:50%;transform:translateY(-50%)}
.connect-port.b{bottom:-6px;left:50%;transform:translateX(-50%)}
.connect-port.l{left:-6px;top:50%;transform:translateY(-50%)}
.resize-video{position:absolute;right:-5px;bottom:-5px;width:12px;height:12px;background:#fbbf24;cursor:nwse-resize}
.line{fill:none;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
.line-hit{fill:none;stroke:transparent;stroke-width:18;pointer-events:stroke;cursor:pointer}
.line.selected{stroke:#fbbf24}
.temp{fill:none;stroke:#22c55e;stroke-width:3;stroke-dasharray:7 5;pointer-events:none}
.timeline{background:#fff;border:1px solid #aaa;border-radius:5px;overflow:auto}
.timeline-inner{min-width:100%;position:relative}
.ruler{height:30px;border-bottom:1px solid #aaa;position:relative}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #aaa;padding-left:3px;font-size:10px}
.lanes{height:200px;position:relative}
.clip{position:absolute;height:26px;border-radius:3px;color:#fff;padding:5px 15px;font-size:11px;white-space:nowrap;overflow:visible;cursor:grab}
.clip.sel{outline:2px solid #fbbf24}
.clip.skip{background:#6b7280!important}
.clip-handle{position:absolute;top:0;bottom:0;width:8px;background:#ffffff88;cursor:ew-resize;z-index:5}
.clip-handle.left{left:0}.clip-handle.right{right:0}
.cursor{position:absolute;top:0;bottom:0;border-left:2px solid #e11d48;z-index:100;pointer-events:none}
.cursor:before{content:"";position:absolute;top:0;left:-6px;width:12px;height:12px;border-radius:50%;background:#e11d48}
.overview{height:28px;background:#e5e7eb;border:1px solid #aaa;margin:8px 4px;position:relative}
.view-range{position:absolute;top:0;bottom:0;background:#93c5fd66;border:2px solid #2563eb}
.menu{position:fixed;z-index:1000;background:#fff;border:1px solid #aaa;border-radius:5px;padding:10px;box-shadow:0 4px 16px #0003;width:290px;max-height:80vh;overflow:auto}
.menu h4{margin:0 0 8px}
.menu label{display:block;margin:7px 0}
.menu input[type=text],.menu input[type=number],.menu input[type=color],.menu select{width:100%;padding:5px}
.choices{display:flex;gap:4px;flex-wrap:wrap}
.choices button.active{background:#dbeafe;border-color:#2563eb}
.colors{display:flex;gap:4px;flex-wrap:wrap}
.color{width:22px;height:22px;padding:0}
.color.active{outline:2px solid #2563eb}
hr{border:0;border-top:1px solid #ddd;margin:10px 0}
.notice{padding:8px;background:#fef3c7;border:1px solid #f59e0b;border-radius:4px}
</style>
</head>
<body>

<div id="home" class="wrap">
<div class="card">
<h2>動画を選択</h2>
<p class="muted">編集する動画を選択してください。</p>
<button id="choose" class="primary">動画を選択</button>
<input id="file" type="file" accept="video/*" class="hidden">
</div>
</div>

<div id="editor" class="wrap hidden">
<div class="top row">
<b id="videoName"></b><span>／</span><span id="workName">新しい編集作業</span>
<span class="grow"></span><span id="dirty" class="hidden">未保存</span>
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
<button id="fit">全体表示</button>
<label>スケール <input id="scale" type="range" min="1" max="4" step=".1" value="1"></label>
</div>
<p class="muted">要素の追加は動画領域またはタイムライン上で右クリックしてください。接続線は要素の緑色の接続点から別の要素へドラッグします。</p>
</div>

<div class="timeline">
<div id="timelineInner" class="timeline-inner">
<div id="ruler" class="ruler"></div>
<div id="lanes" class="lanes"></div>
</div>
</div>
<div id="overview" class="overview"></div>
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
let no=1,state=null,timer=null,connection=null;

const uid=p=>p+(no++);
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const find=(a,id)=>a.find(x=>x.id===id);
const fmt=s=>Math.floor(s/60)+":"+String(Math.floor(s%60)).padStart(2,"0");
const esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));

$("choose").onclick=()=>$("file").click();

$("file").onchange=function(){
 const f=this.files[0];
 if(!f)return;
 const url=URL.createObjectURL(f);
 const v=document.createElement("video");
 v.preload="metadata";
 v.onloadedmetadata=()=>{
  state={
   name:f.name,url,dur:v.duration,pos:0,scale:1,
   selected:[],selectedLine:null,objects:[],lines:[],dirty:false
  };
  openEditor();
 };
 v.src=url;
};

function openEditor(){
 $("home").classList.add("hidden");
 $("editor").classList.remove("hidden");
 $("videoName").textContent=state.name;
 $("video").innerHTML="";
 const v=document.createElement("video");
 v.src=state.url;
 v.preload="auto";
 $("video").appendChild(v);
 state.video=v;
 const objects=document.createElement("div");
 objects.id="objects";
 const svg=document.createElementNS("http://www.w3.org/2000/svg","svg");
 svg.id="lines";
 objects.appendChild(svg);
 $("video").appendChild(objects);
 render();
}

function mark(){
 state.dirty=true;
 $("dirty").classList.remove("hidden");
}

function videoPoint(e){
 const r=$("video").getBoundingClientRect();
 return{x:clamp((e.clientX-r.left)/r.width,0,1),y:clamp((e.clientY-r.top)/r.height,0,1)};
}

function timelineTime(e){
 const r=$("timelineInner").getBoundingClientRect();
 return clamp((e.clientX-r.left)/r.width,0,1)*state.dur;
}

function addObject(type,time,x=.28,y=.25){
 const s=clamp(time,0,Math.max(0,state.dur-5));
 const o={
  id:uid("e"),type,s,e:Math.min(state.dur,s+5),
  x:clamp(x,0,.68),y:clamp(y,0,.7),w:.32,h:.2,a:{}
 };
 if(type==="comment")o.a={text:"コメント",border:true,borderWidth:2,borderColor:"#2563eb",textColor:"#000",fill:true,bg:"#fff",size:18,font:"Arial"};
 if(type==="highlight")o.a={width:4,color:"#ef4444",fill:false,fillColor:"#ef444433"};
 if(type==="zoom")o.a={zoom:1.5};
 if(type==="skip"){o.x=0;o.y=0;o.w=0;o.h=0}
 state.objects.push(o);
 state.selected=[o.id];
 state.selectedLine=null;
 mark();
 closeMenu();
 render();
}

function addMenu(x,y,time,xp,yp){
 showMenu(x,y,`
 <h4>要素を追加</h4>
 <div class="muted">開始位置：${fmt(time)}</div>
 <div class="choices" style="margin-top:8px">
 <button data-add="comment">コメント</button>
 <button data-add="highlight">強調枠</button>
 <button data-add="zoom">拡大枠</button>
 <button data-add="skip">スキップ</button>
 </div>`);
 document.querySelectorAll("[data-add]").forEach(b=>b.onclick=()=>addObject(b.dataset.add,time,xp,yp));
}

function render(){
 if(!state)return;
 renderObjects();
 renderLines();
 renderTimeline();
 $("position").textContent=fmt(state.pos)+" / "+fmt(state.dur);
}

function renderObjects(){
 const layer=$("objects");
 const svg=layer.querySelector("#lines");
 layer.innerHTML="";
 layer.appendChild(svg);

 state.objects.forEach(o=>{
  if(o.type==="skip"||state.pos<o.s||state.pos>o.e)return;
  const d=document.createElement("div");
  d.className="obj"+(state.selected.includes(o.id)?" sel":"");
  if(connection){
   if(connection.from===o.id)d.classList.add("source");
   if(connection.target===o.id)d.classList.add("target");
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
   d.style.border=o.a.width+"px solid "+o.a.color;
   d.style.background=o.a.fill?o.a.fillColor:"transparent";
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
   if(o.type!=="skip"){
    const r=document.createElement("i");
    r.className="resize-video";
    d.appendChild(r);
   }
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
 const q={
  t:Math.abs(p.y-o.y),
  r:Math.abs(p.x-(o.x+o.w)),
  b:Math.abs(p.y-(o.y+o.h)),
  l:Math.abs(p.x-o.x)
 };
 return Object.keys(q).sort((a,b)=>q[a]-q[b])[0];
}

function linePath(l){
 const a=find(state.objects,l.from),b=find(state.objects,l.to);
 if(!a||!b)return"";
 const p=anchor(a,l.fromSide),q=anchor(b,l.toSide);
 if(l.shape==="直線")return`M ${p.x*100} ${p.y*100} L ${q.x*100} ${q.y*100}`;
 const horizontal=Math.abs(q.x-p.x)>Math.abs(q.y-p.y);
 const m=horizontal?(p.x+q.x)/2:(p.y+q.y)/2;
 return horizontal
  ?`M ${p.x*100} ${p.y*100} L ${m*100} ${p.y*100} L ${m*100} ${q.y*100} L ${q.x*100} ${q.y*100}`
  :`M ${p.x*100} ${p.y*100} L ${p.x*100} ${m*100} L ${q.x*100} ${m*100} L ${q.x*100} ${q.y*100}`;
}

function wavePath(l){
 const a=find(state.objects,l.from),b=find(state.objects,l.to);
 if(!a||!b)return"";
 const p=anchor(a,l.fromSide),q=anchor(b,l.toSide);
 const dx=q.x-p.x,dy=q.y-p.y,len=Math.hypot(dx,dy)||1;
 const nx=-dy/len*.018,ny=dx/len*.018;
 let d=`M ${p.x*100} ${p.y*100}`;
 for(let i=1;i<=16;i++){
  const t=i/16,o=i%2?1:-1;
  d+=` L ${(p.x+dx*t+nx*o)*100} ${(p.y+dy*t+ny*o)*100}`;
 }
 return d;
}

function renderLines(){
 const svg=$("lines");
 while(svg.firstChild)svg.removeChild(svg.firstChild);

 state.lines.forEach(l=>{
  const d=l.shape==="波線"?wavePath(l):linePath(l);
  if(!d)return;
  const g=document.createElementNS("http://www.w3.org/2000/svg","g");
  g.dataset.line=l.id;

  const hit=document.createElementNS("http://www.w3.org/2000/svg","path");
  hit.setAttribute("d",d);
  hit.classList.add("line-hit");

  const p=document.createElementNS("http://www.w3.org/2000/svg","path");
  p.setAttribute("d",d);
  p.setAttribute("stroke",l.color);
  p.setAttribute("stroke-width",l.width);
  p.setAttribute("stroke-dasharray",l.dash);
  p.classList.add("line");
  if(state.selectedLine===l.id)p.classList.add("selected");

  if(l.end==="arrow"){
   const marker=document.createElementNS("http://www.w3.org/2000/svg","marker");
   marker.id="arrow"+l.id;
   marker.setAttribute("viewBox","0 0 10 10");
   marker.setAttribute("refX","9");
   marker.setAttribute("refY","5");
   marker.setAttribute("markerWidth","6");
   marker.setAttribute("markerHeight","6");
   marker.setAttribute("orient","auto");
   const mp=document.createElementNS("http://www.w3.org/2000/svg","path");
   mp.setAttribute("d","M0 0 L10 5 L0 10 Z");
   mp.setAttribute("fill",l.color);
   marker.appendChild(mp);
   const defs=document.createElementNS("http://www.w3.org/2000/svg","defs");
   defs.appendChild(marker);
   g.appendChild(defs);
   p.setAttribute("marker-end","url(#arrow"+l.id+")");
  }

  g.appendChild(hit);
  g.appendChild(p);
  svg.appendChild(g);
 });

 if(connection){
  const p=connection.start,q=connection.current;
  const t=document.createElementNS("http://www.w3.org/2000/svg","path");
  t.classList.add("temp");
  t.setAttribute("d",`M ${p.x*100} ${p.y*100} L ${q.x*100} ${q.y*100}`);
  svg.appendChild(t);
 }
}

function renderTimeline(){
 const inner=$("timelineInner");
 const base=Math.max($("timeline").clientWidth||900,900);
 inner.style.width=(base*state.scale)+"px";

 let ticks="";
 const step=state.dur<=30?5:state.dur<=120?10:30;
 for(let t=0;t<=state.dur;t+=step)
  ticks+=`<div class="tick" style="left:${t/state.dur*100}%">${fmt(t)}</div>`;
 $("ruler").innerHTML=ticks;

 const lanes=$("lanes");
 lanes.innerHTML="";
 const rows=[];

 state.objects.forEach(o=>{
  let row=0;
  while(rows[row]!=null&&rows[row]>o.s)row++;
  rows[row]=o.e;

  const c=document.createElement("div");
  c.className="clip"+(o.type==="skip"?" skip":"")+(state.selected.includes(o.id)?" sel":"");
  c.dataset.id=o.id;
  c.style.left=o.s/state.dur*100+"%";
  c.style.width=Math.max((o.e-o.s)/state.dur*100,.8)+"%";
  c.style.top=8+row*32+"px";
  c.style.background=TYPES[o.type].color;
  c.textContent=TYPES[o.type].name+" "+fmt(o.s)+"-"+fmt(o.e);

  const left=document.createElement("i");
  left.className="clip-handle left";
  left.dataset.resize="left";
  const right=document.createElement("i");
  right.className="clip-handle right";
  right.dataset.resize="right";
  c.prepend(left);
  c.appendChild(right);
  lanes.appendChild(c);
 });

 const cursor=document.createElement("div");
 cursor.className="cursor";
 cursor.style.left=state.pos/state.dur*100+"%";
 lanes.appendChild(cursor);

 $("overview").innerHTML='<div class="view-range" style="left:0;width:100%"></div>';
}

function showMenu(x,y,html){
 closeMenu();
 $("popup").innerHTML=`<div class="menu">${html}</div>`;
 const m=$("popup").firstElementChild;
 m.style.left=Math.min(x,innerWidth-m.offsetWidth-8)+"px";
 m.style.top=Math.min(y,innerHeight-m.offsetHeight-8)+"px";
}
function closeMenu(){$("popup").innerHTML=""}

function colorButtons(prop,current){
 return COLORS.map(c=>`<button class="color ${c===current?"active":""}" data-color="${prop}" data-value="${c}" style="background:${c}"></button>`).join("");
}

function elementMenu(x,y,o){
 let html=`<h4>${TYPES[o.type].name}</h4>`;

 if(o.type==="comment")html+=`
 <label>コメント本文<input id="mtext" type="text" value="${esc(o.a.text)}"></label>
 <label>文字サイズ<input id="msize" type="number" value="${o.a.size}"></label>
 <label>フォント<select id="mfont">
 <option ${o.a.font==="Arial"?"selected":""}>Arial</option>
 <option ${o.a.font==="serif"?"selected":""}>serif</option>
 <option ${o.a.font==="sans-serif"?"selected":""}>sans-serif</option>
 <option ${o.a.font==="monospace"?"selected":""}>monospace</option>
 </select></label>
 <label>線 <input id="mborder" type="checkbox" ${o.a.border?"checked":""}></label>
 <label>塗り潰し <input id="mfill" type="checkbox" ${o.a.fill?"checked":""}></label>
 <div>文字色</div><div class="colors">${colorButtons("textColor",o.a.textColor)}</div>
 <div>線色</div><div class="colors">${colorButtons("borderColor",o.a.borderColor)}</div>
 <div>背景色</div><div class="colors">${colorButtons("bg",o.a.bg)}</div>`;

 if(o.type==="highlight")html+=`
 <label>線の太さ<select id="hwidth">
 <option value="2">2px</option><option value="4">4px</option><option value="6">6px</option><option value="8">8px</option>
 </select></label>
 <div>線の色</div><div class="colors">${colorButtons("color",o.a.color)}</div>
 <label>塗り潰し <input id="hfill" type="checkbox" ${o.a.fill?"checked":""}></label>
 <div>塗り潰し色</div>
 <input id="hfillColor" type="color" value="${o.a.fillColor.substring(0,7)}">`;

 if(o.type==="zoom")html+=`
 <label>拡大倍率<select id="zoom">
 <option value="1.25">1.25倍</option><option value="1.5">1.5倍</option><option value="2">2倍</option><option value="3">3倍</option>
 </select></label>`;

 if(o.type==="skip")html+=`<div class="notice">スキップは動画上には表示されません。</div>`;

 html+=`<hr><button id="startLine">この要素から接続線を作成</button>
 <button id="deleteObject" class="danger">この要素を削除</button>`;

 showMenu(x,y,html);

 $("mtext")?.addEventListener("input",e=>{o.a.text=e.target.value;mark();render()});
 $("msize")?.addEventListener("change",e=>{o.a.size=clamp(+e.target.value||18,8,72);mark();render()});
 $("mfont")?.addEventListener("change",e=>{o.a.font=e.target.value;mark();render()});
 $("mborder")?.addEventListener("change",e=>{o.a.border=e.target.checked;mark();render()});
 $("mfill")?.addEventListener("change",e=>{o.a.fill=e.target.checked;mark();render()});
 $("hfill")?.addEventListener("change",e=>{o.a.fill=e.target.checked;mark();render()});
 $("hfillColor")?.addEventListener("input",e=>{o.a.fillColor=e.target.value+"55";mark();render()});
 $("hwidth")?.addEventListener("change",e=>{o.a.width=+e.target.value;mark();render()});
 $("zoom")?.addEventListener("change",e=>{o.a.zoom=+e.target.value;mark();render()});

 document.querySelectorAll("[data-color]").forEach(b=>b.onclick=()=>{
  o.a[b.dataset.color]=b.dataset.value;
  mark();render();elementMenu(x,y,o);
 });

 $("startLine").onclick=()=>{
  closeMenu();
  startConnection(o,anchor(o,"r"));
 };

 $("deleteObject").onclick=()=>{
  state.objects=state.objects.filter(v=>v.id!==o.id);
  state.lines=state.lines.filter(v=>v.from!==o.id&&v.to!==o.id);
  state.selected=[];
  state.selectedLine=null;
  mark();render();
 };
}

function lineMenu(x,y,l){
 showMenu(x,y,`
 <h4>接続線</h4>
 <div>線形</div>
 <div class="choices">
 <button data-shape="直線" class="${l.shape==="直線"?"active":""}">直線</button>
 <button data-shape="折れ線" class="${l.shape==="折れ線"?"active":""}">折れ線</button>
 <button data-shape="波線" class="${l.shape==="波線"?"active":""}">波線</button>
 </div>
 <div>線種</div>
 <div class="choices">
 <button data-dash="">実線</button><button data-dash="2 5">点線</button>
 <button data-dash="8 5">破線</button><button data-dash="10 5 2 5">一点鎖線</button>
 </div>
 <label>線の太さ<select id="lineWidth">
 <option value="2">2px</option><option value="3">3px</option><option value="5">5px</option>
 </select></label>
 <div>接続元</div>
 <div class="choices">${["t","r","b","l"].map(v=>`<button data-from="${v}">${v}</button>`).join("")}</div>
 <div>接続先</div>
 <div class="choices">${["t","r","b","l"].map(v=>`<button data-to="${v}">${v}</button>`).join("")}</div>
 <label>終端<select id="lineEnd"><option value="none">なし</option><option value="arrow">矢印</option></select></label>
 <div>線色</div><div class="colors">${colorButtons("color",l.color)}</div>
 <hr><button id="deleteLine" class="danger">接続線を削除</button>`);

 document.querySelectorAll("[data-shape]").forEach(b=>b.onclick=()=>{
  l.shape=b.dataset.shape;mark();render();lineMenu(x,y,l);
 });
 document.querySelectorAll("[data-dash]").forEach(b=>b.onclick=()=>{
  l.dash=b.dataset.dash;mark();render();lineMenu(x,y,l);
 });
 document.querySelectorAll("[data-from]").forEach(b=>b.onclick=()=>{
  l.fromSide=b.dataset.from;mark();render();lineMenu(x,y,l);
 });
 document.querySelectorAll("[data-to]").forEach(b=>b.onclick=()=>{
  l.toSide=b.dataset.to;mark();render();lineMenu(x,y,l);
 });
 document.querySelectorAll("[data-color]").forEach(b=>b.onclick=()=>{
  l.color=b.dataset.value;mark();render();lineMenu(x,y,l);
 });
 $("lineWidth").onchange=e=>{l.width=+e.target.value;mark();render()};
 $("lineEnd").onchange=e=>{l.end=e.target.value;mark();render()};
 $("deleteLine").onclick=()=>{
  state.lines=state.lines.filter(v=>v.id!==l.id);
  state.selectedLine=null;mark();closeMenu();render();
 };
}

function startConnection(o,start){
 connection={from:o.id,start,current:start,target:null};
 render();

 const move=e=>{
  connection.current=videoPoint(e);
  const els=document.elementsFromPoint(e.clientX,e.clientY);
  const el=els.find(x=>x.classList&&x.classList.contains("obj"));
  connection.target=el&&el.dataset.id!==o.id?find(state.objects,el.dataset.id):null;
  render();
 };

 const up=e=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);
  if(connection.target){
   const target=connection.target;
   state.lines.push({
    id:uid("l"),from:o.id,to:target.id,
    fromSide:nearestSide(o,connection.start),
    toSide:nearestSide(target,connection.current),
    shape:"直線",dash:"",width:3,color:"#f59e0b",end:"none"
   });
   state.selectedLine=state.lines[state.lines.length-1].id;
   state.selected=[];
   mark();
  }
  connection=null;
  render();
 };

 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

$("video").addEventListener("pointerdown",e=>{
 if(e.button!==0)return;

 const port=e.target.closest(".connect-port");
 if(port){
  const o=find(state.objects,e.target.closest(".obj").dataset.id);
  startConnection(o,anchor(o,port.dataset.port));
  e.preventDefault();
  e.stopPropagation();
  return;
 }

 const resize=e.target.closest(".resize-video");
 if(resize){
  resizeVideo(find(state.objects,e.target.closest(".obj").dataset.id),e);
  e.preventDefault();
  return;
 }

 const n=e.target.closest(".obj");
 if(!n){
  state.selected=[];state.selectedLine=null;render();return;
 }

 const o=find(state.objects,n.dataset.id);
 state.selected=[o.id];state.selectedLine=null;render();
 moveVideo(o,e);
});

function moveVideo(o,e){
 const r=$("video").getBoundingClientRect();
 const sx=e.clientX,sy=e.clientY,ox=o.x,oy=o.y;
 const move=ev=>{
  o.x=clamp(ox+(ev.clientX-sx)/r.width,0,1-o.w);
  o.y=clamp(oy+(ev.clientY-sy)/r.height,0,1-o.h);
  mark();render();
 };
 const up=()=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);
 };
 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

function resizeVideo(o,e){
 const r=$("video").getBoundingClientRect();
 const sx=e.clientX,sy=e.clientY,ow=o.w,oh=o.h;
 const move=ev=>{
  o.w=clamp(ow+(ev.clientX-sx)/r.width,.05,1-o.x);
  o.h=clamp(oh+(ev.clientY-sy)/r.height,.05,1-o.y);
  mark();render();
 };
 const up=()=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);
 };
 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

$("video").addEventListener("contextmenu",e=>{
 e.preventDefault();

 const lg=e.target.closest("[data-line]");
 if(lg){
  state.selected=[];state.selectedLine=lg.dataset.line;render();
  lineMenu(e.clientX,e.clientY,find(state.lines,lg.dataset.line));
  return;
 }

 const n=e.target.closest(".obj");
 if(n){
  const o=find(state.objects,n.dataset.id);
  state.selected=[o.id];state.selectedLine=null;render();
  elementMenu(e.clientX,e.clientY,o);
  return;
 }

 const p=videoPoint(e);
 addMenu(e.clientX,e.clientY,state.pos,p.x,p.y);
});

$("video").addEventListener("click",e=>{
 const g=e.target.closest("[data-line]");
 if(g){
  state.selected=[];state.selectedLine=g.dataset.line;render();
 }
});

$("lanes").addEventListener("pointerdown",e=>{
 const clip=e.target.closest(".clip");

 if(!clip){
  seekFromTimeline(e);
  dragPlayhead(e);
  return;
 }

 const o=find(state.objects,clip.dataset.id);
 if(!o)return;

 const handle=e.target.closest(".clip-handle");
 if(handle){
  resizeTimeline(o,handle.dataset.resize,e);
  e.preventDefault();
  return;
 }

 state.selected=[o.id];state.selectedLine=null;render();
 moveTimeline(o,e);
 e.preventDefault();
});

function moveTimeline(o,e){
 const r=$("timelineInner").getBoundingClientRect();
 const sx=e.clientX,os=o.s,oe=o.e,d=oe-os;
 const move=ev=>{
  const dt=(ev.clientX-sx)/r.width*state.dur;
  o.s=clamp(os+dt,0,state.dur-d);
  o.e=o.s+d;
  mark();render();
 };
 const up=()=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);
 };
 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

function resizeTimeline(o,side,e){
 const r=$("timelineInner").getBoundingClientRect();
 const sx=e.clientX,os=o.s,oe=o.e;
 const move=ev=>{
  const dt=(ev.clientX-sx)/r.width*state.dur;
  if(side==="left")o.s=clamp(os+dt,0,oe-.2);
  else o.e=clamp(oe+dt,o.s+.2,state.dur);
  mark();render();
 };
 const up=()=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);
 };
 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

function seekFromTimeline(e){
 const r=$("timelineInner").getBoundingClientRect();
 state.pos=clamp((e.clientX-r.left)/r.width*state.dur,0,state.dur);
 state.video.currentTime=state.pos;
 render();
}

function dragPlayhead(e){
 const move=ev=>seekFromTimeline(ev);
 const up=()=>{
  window.removeEventListener("pointermove",move);
  window.removeEventListener("pointerup",up);
 };
 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

$("ruler").addEventListener("pointerdown",e=>{
 seekFromTimeline(e);dragPlayhead(e);
});

$("scale").oninput=e=>{
 state.scale=+e.target.value;
 renderTimeline();
};

$("fit").onclick=()=>{
 state.scale=1;
 $("scale").value=1;
 render();
};

$("play").onclick=()=>{
 if(timer)return;
 state.video.currentTime=state.pos;
 state.video.play();
 timer=setInterval(()=>{
  state.pos=state.video.currentTime;
  const skip=state.objects.find(o=>o.type==="skip"&&state.pos>=o.s&&state.pos<o.e);
  if(skip){
   state.pos=skip.e;
   state.video.currentTime=skip.e;
  }
  if(state.pos>=state.dur){
   state.pos=state.dur;
   state.video.pause();
   clearInterval(timer);
   timer=null;
  }
  render();
 },50);
};

$("pause").onclick=()=>{
 state.video.pause();
 clearInterval(timer);timer=null;
};

$("stop").onclick=()=>{
 state.video.pause();
 clearInterval(timer);timer=null;
 state.pos=0;
 state.video.currentTime=0;
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

$("make").onclick=()=>alert("現在の編集内容で編集結果動画を作成します。");

$("finish").onclick=()=>{
 if(state.dirty&&!confirm("未保存の変更があります。保存せずに終了しますか？"))return;
 clearInterval(timer);timer=null;state=null;
 $("editor").classList.add("hidden");
 $("home").classList.remove("hidden");
};

document.addEventListener("pointerdown",e=>{
 if(!e.target.closest(".menu"))closeMenu();
},true);

$("video").addEventListener("dblclick",e=>{
 const n=e.target.closest(".obj");
 if(n){
  const o=find(state.objects,n.dataset.id);
  elementMenu(e.clientX,e.clientY,o);
 }
});

$("timelineInner").addEventListener("contextmenu",e=>{
 e.preventDefault();

 const clip=e.target.closest(".clip");
 if(clip){
  const o=find(state.objects,clip.dataset.id);
  state.selected=[o.id];state.selectedLine=null;render();
  elementMenu(e.clientX,e.clientY,o);
  return;
 }

 const r=$("timelineInner").getBoundingClientRect();
 const x=clamp((e.clientX-r.left)/r.width,0,1);
 addMenu(e.clientX,e.clientY,x*state.dur,.25,.25);
});

})();
</script>
</body>
</html>

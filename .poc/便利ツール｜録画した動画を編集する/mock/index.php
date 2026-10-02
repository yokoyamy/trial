<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f4f6;color:#222;font:14px sans-serif}
button,input{font:inherit}button{padding:5px 10px;border:1px solid #aaa;border-radius:4px;background:#fff;cursor:pointer}
button.primary{background:#2563eb;color:#fff;border-color:#2563eb}.danger{color:#b91c1c}
.wrap{max-width:1000px;margin:auto;padding:16px}.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px;margin-bottom:14px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.grow{flex:1}.muted{color:#666}.hidden{display:none!important}
.orig{border:2px solid #93c5fd;border-radius:5px;margin:8px 0}.orig>div{padding:8px}.head{background:#dbeafe}
.work{padding:6px;border-top:1px dashed #ccc}.top{background:#1f2937;color:#fff;padding:8px 12px}
.videoWrap{max-width:760px;margin:12px auto}.video{height:380px;background:#000;position:relative;overflow:hidden;user-select:none}
.video video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none}
.demo{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#777;font-size:28px;pointer-events:none}
#objects{position:absolute;inset:0}.obj{position:absolute;cursor:grab;touch-action:none;z-index:10}
.obj:active{cursor:grabbing}.obj.sel{outline:2px dashed #fbbf24;z-index:20}
.resize{position:absolute;right:-1px;bottom:-1px;width:12px;height:12px;background:#fbbf24;cursor:nwse-resize}
#lines{position:absolute;inset:0;width:100%;height:100%;z-index:5;pointer-events:none}
.hit{fill:none;stroke:transparent;stroke-width:18;pointer-events:stroke;cursor:pointer}
.line{fill:none;stroke-linecap:round;stroke-linejoin:round}
.temp{fill:none;stroke:#22c55e;stroke-width:3;stroke-dasharray:7 5}
.connecting .obj{cursor:crosshair}.source{outline:3px solid #22c55e!important}.target{outline:3px dashed #38bdf8!important}
.editor{max-width:1000px;margin:auto}.timeline{background:#fff;border:1px solid #aaa;border-radius:4px;overflow:hidden}
.ruler{height:28px;border-bottom:1px solid #aaa;position:relative}.tick{position:absolute;top:0;height:100%;border-left:1px solid #aaa;padding-left:3px;font-size:10px}
.lanes{height:170px;position:relative;cursor:grab}.clip{position:absolute;height:24px;border-radius:3px;color:#fff;padding:4px 6px;font-size:11px;overflow:hidden;white-space:nowrap;cursor:grab}
.clip.sel{outline:2px solid #fbbf24}.cursor{position:absolute;top:0;bottom:0;border-left:2px solid #e11d48;pointer-events:none}.cursor i{position:absolute;top:0;left:-7px;width:12px;height:12px;border-radius:50%;background:#e11d48}
.overview{height:28px;background:#e5e7eb;border:1px solid #aaa;margin:8px 4px;position:relative}.range{position:absolute;top:0;bottom:0;background:#93c5fd66;border:2px solid #2563eb}
.handle{position:absolute;top:-2px;bottom:-2px;width:10px;background:#2563eb;cursor:ew-resize}.handle.l{left:-5px}.handle.r{right:-5px}
.menu{position:fixed;z-index:100;background:#fff;border:1px solid #aaa;border-radius:5px;padding:7px;box-shadow:0 3px 12px #0003;min-width:210px;max-width:300px}
.menu button{display:block;width:100%;text-align:left;border:0;margin:2px 0}.menu button:hover{background:#eff6ff}
.menu label{display:flex;gap:5px;align-items:center;margin:5px 0}.sw{display:flex;flex-wrap:wrap;gap:3px}.sw b{width:20px;height:20px;border:1px solid #888;cursor:pointer}.sw b.on{outline:2px solid #2563eb}
.choice{display:flex;gap:3px;flex-wrap:wrap}.choice button{width:auto;border:1px solid #ccc}.choice button.on{background:#dbeafe;border-color:#2563eb}
</style>
</head>
<body>

<div id="home" class="wrap">
<div class="card">
<h2>動画を選択</h2>
<button id="choose" class="primary">動画を選択</button>
<input id="file" type="file" accept="video/*" class="hidden">
<p class="muted">動画ファイルを選択すると編集画面を開きます。</p>
</div>
<div class="card"><h2>オリジナル動画</h2><div id="videos"></div></div>
</div>

<div id="editor" class="editor hidden">
<div class="top row">
<b id="videoName"></b><span>／</span><span id="workName">新しい編集作業</span>
<span class="grow"></span><span id="changed" class="hidden">未保存の変更あり</span>
<button id="back">編集作業を終了する</button>
</div>

<div class="videoWrap">
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
</div>
</div>

<div class="timeline">
<div id="ruler" class="ruler"></div>
<div id="lanes" class="lanes"></div>
</div>
<div id="overview" class="overview"></div>
<p class="muted">動画上：要素をドラッグして移動できます。要素から別の要素へドラッグすると接続線を作成できます。空白部分を右クリックすると要素を追加できます。</p>
</div>

<div id="popup"></div>
<div id="modal"></div>

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
const colors=["#000","#fff","#dc2626","#ea580c","#eab308","#16a34a","#0891b2","#2563eb","#7c3aed","#db2777","#6b7280"];
let seq=1,videoData=null,state=null,drag=null,connect=null,timer=null;

const uid=p=>p+(seq++);
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const find=(a,id)=>a.find(x=>x.id===id);
const esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const fmt=s=>{s=Math.max(0,s);return Math.floor(s/60)+":"+String(Math.floor(s%60)).padStart(2,"0")};

$("choose").onclick=()=>$("file").click();
$("file").onchange=function(){
 const f=this.files[0];if(!f)return;
 const u=URL.createObjectURL(f),v=document.createElement("video");
 v.preload="metadata";
 v.onloadedmetadata=()=>{
  videoData={id:uid("v"),name:f.name,url:u,dur:v.duration};
  openEditor();
 };
 v.onerror=()=>alert("動画を読み込めませんでした。");
 v.src=u;
};

function openEditor(){
 state={
  dur:videoData.dur,pos:0,viewStart:0,viewEnd:videoData.dur,
  objects:[],lines:[],selected:[],selectedLine:null,dirty:false
 };
 $("home").classList.add("hidden");$("editor").classList.remove("hidden");
 $("videoName").textContent=videoData.name;
 $("video").innerHTML="";
 const v=document.createElement("video");
 v.src=videoData.url;v.controls=false;
 $("video").appendChild(v);state.video=v;
 const objects=document.createElement("div");objects.id="objects";$("video").appendChild(objects);
 const svg=document.createElementNS("http://www.w3.org/2000/svg","svg");
 svg.id="lines";svg.setAttribute("viewBox","0 0 100 100");objects.appendChild(svg);
 render();
}

function mark(){state.dirty=true;$("changed").classList.remove("hidden")}

function position(e){
 const r=$("video").getBoundingClientRect();
 return{x:clamp((e.clientX-r.left)/r.width,0,1),y:clamp((e.clientY-r.top)/r.height,0,1)};
}

function visible(o){return state.pos>=o.s&&state.pos<=o.e}

function render(){
 if(!state)return;
 $("position").textContent=fmt(state.pos)+" / "+fmt(state.dur);
 renderObjects();renderTimeline();renderLines();
}

function renderObjects(){
 const layer=$("objects");
 let svg=layer.querySelector("#lines");
 layer.innerHTML="";
 layer.appendChild(svg);
 state.objects.forEach(o=>{
  if(o.type==="skip"||!visible(o))return;
  const d=document.createElement("div");
  d.className="obj"+(state.selected.includes(o.id)?" sel":"");
  if(connect&&connect.from===o.id)d.classList.add("source");
  if(connect&&connect.target===o.id)d.classList.add("target");
  d.dataset.id=o.id;
  d.style.cssText=`left:${o.x*100}%;top:${o.y*100}%;width:${o.w*100}%;height:${o.h*100}%;`;
  if(o.type==="comment"){
   d.style.color=o.a.textColor;
   d.style.fontSize=o.a.size+"px";
   d.style.fontFamily=o.a.font;
   d.style.padding="5px";
   if(o.a.fill)d.style.background=o.a.bg;
   if(o.a.border)d.style.border=`${o.a.width}px solid ${o.a.borderColor}`;
   d.textContent=o.a.text;
  }else if(o.type==="highlight"){
   d.style.border=`${o.a.width}px ${o.a.style} ${o.a.color}`;
   if(o.a.fill)d.style.background=o.a.color+"33";
  }else if(o.type==="zoom"){
   d.style.border="2px solid #059669";d.style.background="#05966922";
   d.innerHTML='<span style="background:#059669;color:#fff;padding:2px 5px">拡大</span>';
  }
  if(state.selected.includes(o.id)){
   const r=document.createElement("div");r.className="resize";d.appendChild(r);
  }
  layer.appendChild(d);
 });
}

function anchor(o,a){
 if(a==="top")return{x:o.x+o.w/2,y:o.y};
 if(a==="right")return{x:o.x+o.w,y:o.y+o.h/2};
 if(a==="bottom")return{x:o.x+o.w/2,y:o.y+o.h};
 if(a==="left")return{x:o.x,y:o.y+o.h/2};
 return{x:o.x+o.w/2,y:o.y+o.h/2};
}

function renderLines(){
 const svg=$("lines");while(svg.firstChild)svg.removeChild(svg.firstChild);
 state.lines.forEach(c=>{
  const a=find(state.objects,c.from),b=find(state.objects,c.to);if(!a||!b)return;
  const p=anchor(a,c.fromAnchor),q=anchor(b,c.toAnchor);
  let d=`M ${p.x*100} ${p.y*100}`;
  if(c.shape==="折れ線"){
   const mx=(p.x+q.x)/2;
   d+=` L ${mx*100} ${p.y*100} L ${mx*100} ${q.y*100}`;
  }else if(c.shape==="波線"){
   const n=10,dx=(q.x-p.x)/n,dy=(q.y-p.y)/n;
   for(let i=1;i<=n;i++){
    const x=p.x+dx*i,y=p.y+dy*i;
    const off=(i%2?0.018:-0.018);
    d+=` L ${(x-dy*off)*100} ${(y+dx*off)*100}`;
   }
  }else d+=` L ${q.x*100} ${q.y*100}`;
  const g=document.createElementNS("http://www.w3.org/2000/svg","g");
  g.dataset.line=c.id;
  const hit=document.createElementNS("http://www.w3.org/2000/svg","path");
  hit.classList.add("hit");hit.setAttribute("d",d);
  const line=document.createElementNS("http://www.w3.org/2000/svg","path");
  line.classList.add("line");line.setAttribute("d",d);
  line.setAttribute("stroke",c.color);line.setAttribute("stroke-width",c.width);
  line.setAttribute("stroke-dasharray",c.style);
  if(state.selectedLine===c.id)line.setAttribute("stroke","#fbbf24");
  if(c.end==="arrow"){
   const defs=document.createElementNS("http://www.w3.org/2000/svg","defs");
   const m=document.createElementNS("http://www.w3.org/2000/svg","marker");
   m.id="arrow"+c.id;m.setAttribute("viewBox","0 0 10 10");m.setAttribute("refX","9");m.setAttribute("refY","5");
   m.setAttribute("markerWidth","6");m.setAttribute("markerHeight","6");m.setAttribute("orient","auto");
   const pth=document.createElementNS("http://www.w3.org/2000/svg","path");pth.setAttribute("d","M0 0L10 5L0 10z");pth.setAttribute("fill",c.color);
   m.appendChild(pth);defs.appendChild(m);g.appendChild(defs);line.setAttribute("marker-end","url(#arrow"+c.id+")");
  }
  g.appendChild(hit);g.appendChild(line);svg.appendChild(g);
 });
 if(connect){
  const p=connect.start,q=connect.current;
  const t=document.createElementNS("http://www.w3.org/2000/svg","path");
  t.classList.add("temp");t.setAttribute("d",`M${p.x*100} ${p.y*100}L${q.x*100} ${q.y*100}`);svg.appendChild(t);
 }
}

function renderTimeline(){
 const w=$("lanes").clientWidth||700,sp=state.viewEnd-state.viewStart;
 let ruler="";
 for(let t=Math.ceil(state.viewStart/10)*10;t<=state.viewEnd;t+=10){
  ruler+=`<div class="tick" style="left:${(t-state.viewStart)/sp*100}%">${fmt(t)}</div>`;
 }
 $("ruler").innerHTML=ruler;
 const rows=[];let h="";
 state.objects.forEach(o=>{
  if(o.e<state.viewStart||o.s>state.viewEnd)return;
  let row=0;while(rows[row]>o.s)row++;rows[row]=o.e;
  const x=(o.s-state.viewStart)/sp*w,ww=(o.e-o.s)/sp*w;
  h+=`<div class="clip${state.selected.includes(o.id)?" sel":""}" data-id="${o.id}" style="left:${x}px;width:${Math.max(4,ww)}px;top:${6+row*28}px;background:${TYPES[o.type].color}">${TYPES[o.type].name}</div>`;
 });
 $("lanes").innerHTML=h;
 if(state.pos>=state.viewStart&&state.pos<=state.viewEnd){
  const c=document.createElement("div");c.className="cursor";
  c.style.left=(state.pos-state.viewStart)/sp*100+"%";c.innerHTML="<i></i>";$("lanes").appendChild(c);
 }
 $("overview").innerHTML=`<div class="range" style="left:${state.viewStart/state.dur*100}%;width:${sp/state.dur*100}%"></div>`;
}

function addObject(type){
 const s=clamp(state.pos,0,Math.max(0,state.dur-5)),o={
  id:uid("e"),type,s,e:Math.min(state.dur,s+5),x:.3,y:.25,w:.3,h:.2,a:{}
 };
 if(type==="comment")o.a={text:"コメント",border:true,width:2,borderColor:"#2563eb",textColor:"#000",bg:"#fff",fill:true,size:16,font:"sans-serif"};
 if(type==="highlight")o.a={width:4,color:"#dc2626",fill:false,style:"solid"};
 state.objects.push(o);state.selected=[o.id];state.selectedLine=null;mark();closePopup();render();
}

function menu(x,y,html){
 closePopup();$("popup").innerHTML=`<div class="menu" id="menu">${html}</div>`;
 const m=$("menu");m.style.left=Math.min(x,innerWidth-m.offsetWidth-8)+"px";m.style.top=Math.min(y,innerHeight-m.offsetHeight-8)+"px";
}

function closePopup(){$("popup").innerHTML=""}

$("video").oncontextmenu=function(e){
 e.preventDefault();const o=e.target.closest(".obj");
 if(o){state.selected=[o.dataset.id];state.selectedLine=null;render();elementMenu(e.clientX,e.clientY,find(state.objects,o.dataset.id));}
 else basicMenu(e.clientX,e.clientY);
};

function basicMenu(x,y){
 menu(x,y,`<b>要素を追加</b>
 <button data-add="comment">コメントを追加</button>
 <button data-add="highlight">強調枠を追加</button>
 <button data-add="zoom">拡大枠を追加</button>
 <button data-add="skip">スキップを追加</button>`);
}

function elementMenu(x,y,o){
 let h=`<b>${TYPES[o.type].name}</b>`;
 if(o.type==="comment")h+=`<label>文字<input id="txt" value="${esc(o.a.text)}"></label>
 <label>線あり<input id="border" type="checkbox" ${o.a.border?"checked":""}></label>
 <label>文字サイズ<input id="size" type="number" value="${o.a.size}" min="8" max="72" style="width:65px"></label>`;
 if(o.type==="highlight")h+=`<label>塗り潰し<input id="fill" type="checkbox" ${o.a.fill?"checked":""}></label>
 <div>線種</div><div class="choice">${["solid","dotted","dashed","double"].map(x=>`<button data-style="${x}" class="${o.a.style===x?"on":""}">${x}</button>`).join("")}</div>`;
 h+=`<hr><button id="connectStart">接続線を開始</button><button id="delete" class="danger">削除</button>`;
 menu(x,y,h);
 $("txt")?.addEventListener("input",e=>{o.a.text=e.target.value;mark();render()});
 $("border")?.addEventListener("change",e=>{o.a.border=e.target.checked;mark();render()});
 $("size")?.addEventListener("change",e=>{o.a.size=clamp(+e.target.value||16,8,72);mark();render()});
 $("fill")?.addEventListener("change",e=>{o.a.fill=e.target.checked;mark();render()});
 document.querySelectorAll("[data-style]").forEach(b=>b.onclick=()=>{o.a.style=b.dataset.style;mark();render();elementMenu(x,y,o)});
 $("delete").onclick=()=>{state.objects=state.objects.filter(x=>x.id!==o.id);state.lines=state.lines.filter(c=>c.from!==o.id&&c.to!==o.id);state.selected=[];mark();closePopup();render()};
 $("connectStart").onclick=()=>{
  closePopup();startConnection(o,{clientX:$("video").getBoundingClientRect().left+(o.x+o.w/2)*$("video").clientWidth,clientY:$("video").getBoundingClientRect().top+(o.y+o.h/2)*$("video").clientHeight});
 };
}

$("popup").onclick=function(e){
 const b=e.target.closest("[data-add]");if(b)addObject(b.dataset.add);
};

$("video").addEventListener("pointerdown",function(e){
 if(e.button!==0)return;
 if(e.target.closest(".hit"))return;
 const n=e.target.closest(".obj");
 if(!n){state.selected=[];state.selectedLine=null;render();return}
 const o=find(state.objects,n.dataset.id);
 if(e.shiftKey){state.selected.includes(o.id)?state.selected=state.selected.filter(x=>x!==o.id):state.selected.push(o.id);render();return}
 state.selected=[o.id];state.selectedLine=null;
 if(e.target.classList.contains("resize"))return resize(o,e);
 startMove(o,e);
});

function startMove(o,e){
 const r=$("video").getBoundingClientRect(),sx=e.clientX,sy=e.clientY,ox=o.x,oy=o.y;
 let moved=false;
 const move=ev=>{
  const dx=(ev.clientX-sx)/r.width,dy=(ev.clientY-sy)/r.height;
  if(Math.abs(dx)+Math.abs(dy)>.004)moved=true;
  o.x=clamp(ox+dx,0,1-o.w);o.y=clamp(oy+dy,0,1-o.h);
  mark();render();
 };
 const up=ev=>{
  window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up);
  if(moved){connect=null}else startConnection(o,e);
 };
 window.addEventListener("pointermove",move);window.addEventListener("pointerup",up);
 e.preventDefault();
}

function resize(o,e){
 const r=$("video").getBoundingClientRect(),sx=e.clientX,sy=e.clientY,ow=o.w,oh=o.h;
 const move=ev=>{o.w=clamp(ow+(ev.clientX-sx)/r.width,.05,1-o.x);o.h=clamp(oh+(ev.clientY-sy)/r.height,.05,1-o.y);mark();render()};
 const up=()=>{window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up)};
 window.addEventListener("pointermove",move);window.addEventListener("pointerup",up);e.preventDefault();
}

function startConnection(o,e){
 const p=position(e);connect={from:o.id,start:p,current:p,target:null};
 $("video").classList.add("connecting");render();
 const move=ev=>{
  connect.current=position(ev);
  const n=document.elementFromPoint(ev.clientX,ev.clientY)?.closest(".obj");
  connect.target=n?find(state.objects,n.dataset.id):null;
  if(connect.target?.id===connect.from)connect.target=null;
  render();
 };
 const up=ev=>{
  window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up);
  if(connect.target){
   const a=find(state.objects,connect.from),b=connect.target;
   state.lines.push({id:uid("l"),from:a.id,to:b.id,shape:"直線",style:"",color:"#f59e0b",width:3,fromAnchor:"right",toAnchor:"left",end:"none"});
   state.selected=[];state.selectedLine=state.lines[state.lines.length-1].id;mark();
  }
  connect=null;$("video").classList.remove("connecting");render();
 };
 window.addEventListener("pointermove",move);window.addEventListener("pointerup",up);
}

$("video").addEventListener("click",e=>{
 const g=e.target.closest("[data-line]");if(!g)return;
 state.selected=[];state.selectedLine=g.dataset.line;render();lineMenu(e.clientX,e.clientY,find(state.lines,g.dataset.line));
});

function lineMenu(x,y,c){
 menu(x,y,`<b>接続線</b>
 <div>線形</div><div class="choice">${["直線","折れ線","波線"].map(x=>`<button data-shape="${x}" class="${c.shape===x?"on":""}">${x}</button>`).join("")}</div>
 <div>線種</div><div class="choice">${[["","実線"],["2 5","点線"],["8 5","破線"],["10 5 2 5","一点鎖線"]].map(x=>`<button data-line-style="${x[0]}" class="${c.style===x[0]?"on":""}">${x[1]}</button>`).join("")}</div>
 <label>矢印<input id="arrow" type="checkbox" ${c.end==="arrow"?"checked":""}></label>
 <hr><button id="delLine" class="danger">接続線を削除</button>`);
 document.querySelectorAll("[data-shape]").forEach(b=>b.onclick=()=>{c.shape=b.dataset.shape;mark();render();lineMenu(x,y,c)});
 document.querySelectorAll("[data-line-style]").forEach(b=>b.onclick=()=>{c.style=b.dataset.lineStyle;mark();render();lineMenu(x,y,c)});
 $("arrow").onchange=e=>{c.end=e.target.checked?"arrow":"none";mark();render()};
 $("delLine").onclick=()=>{state.lines=state.lines.filter(x=>x.id!==c.id);state.selectedLine=null;mark();closePopup();render()};
}

$("lanes").addEventListener("pointerdown",function(e){
 const n=e.target.closest(".clip");if(!n)return;
 const o=find(state.objects,n.dataset.id),r=$("lanes").getBoundingClientRect(),sx=e.clientX,os=o.s,oe=o.e;
 state.selected=[o.id];render();
 const move=ev=>{
  const dt=(ev.clientX-sx)/r.width*(state.viewEnd-state.viewStart);
  o.s=clamp(os+dt,0,oe-os>0?state.dur-(oe-os):state.dur);o.e=o.s+(oe-os);mark();render();
 };
 const up=()=>{window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up)};
 window.addEventListener("pointermove",move);window.addEventListener("pointerup",up);e.preventDefault();
});

$("ruler").onpointerdown=e=>{
 const move=ev=>{
  const r=$("lanes").getBoundingClientRect(),t=state.viewStart+clamp(ev.clientX-r.left,0,r.width)/r.width*(state.viewEnd-state.viewStart);
  state.pos=clamp(t,0,state.dur);state.video.currentTime=state.pos;render();
 };
 const up=()=>{window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up)};
 move(e);window.addEventListener("pointermove",move);window.addEventListener("pointerup",up);
};

$("play").onclick=()=>{
 if(timer)return;
 if(state.pos>=state.dur)state.pos=0;
 state.video.currentTime=state.pos;
 state.video.play();
 timer=setInterval(()=>{
  state.pos=state.video.currentTime;
  if(state.pos>=state.dur){clearInterval(timer);timer=null;state.video.pause()}
  render();
 },50);
};
$("pause").onclick=()=>{state.video.pause();clearInterval(timer);timer=null};
$("stop").onclick=()=>{state.video.pause();clearInterval(timer);timer=null;state.pos=0;state.video.currentTime=0;render()};
$("fit").onclick=()=>{state.viewStart=0;state.viewEnd=state.dur;render()};

$("back").onclick=()=>{
 if(state.dirty&&!confirm("未保存の変更があります。終了しますか？"))return;
 clearInterval(timer);timer=null;state.video.pause();state=null;
 $("editor").classList.add("hidden");$("home").classList.remove("hidden");
};

document.addEventListener("pointerdown",e=>{if(!e.target.closest(".menu"))closePopup()},true);
document.addEventListener("keydown",e=>{
 if(e.key==="Escape"){connect=null;$("video")?.classList.remove("connecting");closePopup();render()}
});
window.onresize=()=>state&&render();

$("videos").innerHTML=`<div class="orig"><div class="head row"><b>動画を選択するとここに表示されます</b><span class="grow"></span></div></div>`;
})();
</script>
</body>
</html>

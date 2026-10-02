<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f5f7;color:#222;font:14px Arial,sans-serif}
button,input,select,textarea{font:inherit}
button{padding:6px 10px;border:1px solid #aaa;border-radius:4px;background:#fff;cursor:pointer}
button.primary{background:#2563eb;color:#fff;border-color:#2563eb}
button.danger{color:#b91c1c}
.hidden{display:none!important}
.wrap{max-width:1100px;margin:auto;padding:16px}
.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px;margin-bottom:14px}
.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.grow{flex:1}.muted{color:#666}
.top{padding:9px 12px;background:#1f2937;color:#fff}
.video{position:relative;height:420px;background:#000;overflow:hidden;user-select:none}
.video>video{width:100%;height:100%;object-fit:contain;pointer-events:none}
#objects{position:absolute;inset:0}
#lines{position:absolute;inset:0;width:100%;height:100%;z-index:5;overflow:visible;pointer-events:none}
.obj{position:absolute;z-index:10;cursor:grab;touch-action:none}
.obj:active{cursor:grabbing}
.obj.sel{outline:2px dashed #f59e0b}
.obj .port{display:none;position:absolute;width:10px;height:10px;border:2px solid #fff;border-radius:50%;background:#22c55e;z-index:20}
.obj.sel .port{display:block}
.port.t{left:50%;top:-6px;margin-left:-5px}.port.r{right:-6px;top:50%;margin-top:-5px}
.port.b{left:50%;bottom:-6px;margin-left:-5px}.port.l{left:-6px;top:50%;margin-top:-5px}
.resize{position:absolute;right:-2px;bottom:-2px;width:12px;height:12px;background:#fbbf24;cursor:nwse-resize}
.line{fill:none;stroke-linecap:round;stroke-linejoin:round}
.line-hit{fill:none;stroke:transparent;stroke-width:18;pointer-events:stroke;cursor:pointer}
.line.selected{stroke:#f59e0b}
.temp{fill:none;stroke:#22c55e;stroke-width:3;stroke-dasharray:7 5}
.timeline-box{background:#fff;border:1px solid #aaa;border-radius:5px;overflow:hidden}
.timeline-scroll{overflow-x:auto}
.timeline{position:relative;min-width:800px}
.ruler{height:32px;border-bottom:1px solid #aaa;position:relative}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #aaa;padding-left:3px;font-size:10px}
.lanes{position:relative;height:210px}
.clip{position:absolute;height:25px;padding:5px;color:#fff;border-radius:3px;font-size:11px;white-space:nowrap;overflow:hidden;cursor:grab}
.clip.sel{outline:2px solid #f59e0b}
.cursor{position:absolute;top:0;bottom:0;width:2px;background:#e11d48;z-index:30;pointer-events:none}
.cursor:before{content:"";position:absolute;top:0;left:-5px;width:12px;height:12px;background:#e11d48;border-radius:50%}
.overview{height:24px;background:#e5e7eb;border:1px solid #aaa;margin:8px 0;position:relative}
.range{position:absolute;top:0;bottom:0;background:#93c5fd66;border:2px solid #2563eb}
.menu{position:fixed;z-index:100;background:#fff;border:1px solid #aaa;border-radius:5px;padding:10px;width:290px;max-height:80vh;overflow:auto;box-shadow:0 4px 16px #0003}
.menu h4{margin:0 0 8px}.menu label{display:block;margin:7px 0}
.menu input,.menu select{width:100%;padding:5px}
.choices{display:flex;gap:4px;flex-wrap:wrap}.choices button.active{background:#dbeafe}
.colors{display:flex;gap:4px;flex-wrap:wrap}.color{width:22px;height:22px;padding:0}
.color.active{outline:2px solid #2563eb}
</style>
</head>
<body>

<div id="home" class="wrap">
  <div class="card">
    <h2>動画を選択</h2>
    <p class="muted">編集する動画を選択してください。</p>
    <button id="pick" class="primary">動画を選択</button>
    <input id="file" type="file" accept="video/*" hidden>
  </div>
  <div class="card">
    <h2>編集対象</h2>
    <div id="videos"></div>
  </div>
</div>

<div id="editor" class="wrap hidden">
  <div class="top row">
    <b id="videoName"></b>
    <span class="grow"></span>
    <span id="dirty" class="hidden">未保存</span>
    <button id="save">保存</button>
    <button id="make">編集結果を動画にする</button>
    <button id="exit">編集作業を終了する</button>
  </div>

  <div style="margin:12px 0">
    <div id="screen" class="video"></div>
  </div>

  <div class="card">
    <div class="row">
      <button id="play" class="primary">▶ 再生</button>
      <button id="pause">⏸ 一時停止</button>
      <button id="stop">■ 停止</button>
      <span id="time" class="muted"></span>
      <span class="grow"></span>
      <button id="add">＋ 要素を追加</button>
      <label>スケール
        <input id="scale" type="range" min="1" max="4" step=".1" value="1">
      </label>
    </div>
  </div>

  <div class="timeline-box">
    <div class="timeline-scroll">
      <div id="timeline" class="timeline">
        <div id="ruler" class="ruler"></div>
        <div id="lanes" class="lanes"></div>
      </div>
    </div>
  </div>
  <div id="overview" class="overview"></div>
  <p class="muted">スケールは時間軸の表示倍率です。動画の再生位置や要素の時間は変更しません。</p>
</div>

<div id="menu"></div>

<script>
(() => {
"use strict";

const $=id=>document.getElementById(id);
const types={
  comment:["コメント","#2563eb"],
  highlight:["強調枠","#dc2626"],
  zoom:["拡大枠","#059669"],
  skip:["スキップ","#6b7280"]
};
const colors=["#000","#fff","#ef4444","#f97316","#eab308","#22c55e","#06b6d4","#3b82f6","#8b5cf6","#ec4899","#6b7280","#92400e"];
let uid=1,videoFile=null,timer=null,drag=null;

let state=null;

function id(p){return p+(uid++)}
function clamp(v,min,max){return Math.max(min,Math.min(max,v))}
function get(a,id){return a.find(x=>x.id===id)}
function time(v){return Math.floor(v/60)+":"+String(Math.floor(v%60)).padStart(2,"0")}
function dirty(){
  state.dirty=true;
  $("dirty").classList.remove("hidden");
}

$("pick").onclick=()=>$("file").click();

$("file").onchange=e=>{
  const f=e.target.files[0];
  if(!f)return;
  const u=URL.createObjectURL(f),v=document.createElement("video");
  v.preload="metadata";
  v.onloadedmetadata=()=>{
    videoFile={name:f.name,url:u,duration:v.duration};
    start();
  };
  v.onerror=()=>alert("動画を読み込めませんでした。");
  v.src=u;
};

function start(){
  state={
    duration:videoFile.duration,
    pos:0,
    scale:1,
    objects:[],
    lines:[],
    selected:[],
    selectedLine:null,
    dirty:false
  };

  $("home").classList.add("hidden");
  $("editor").classList.remove("hidden");
  $("videoName").textContent=videoFile.name;

  const screen=$("screen");
  screen.innerHTML="";
  const v=document.createElement("video");
  v.src=videoFile.url;
  v.preload="auto";
  screen.appendChild(v);
  state.video=v;

  const objects=document.createElement("div");
  objects.id="objects";
  screen.appendChild(objects);

  const svg=document.createElementNS("http://www.w3.org/2000/svg","svg");
  svg.id="lines";
  objects.appendChild(svg);

  render();
}

function add(type){
  const s=clamp(state.pos,0,state.duration-5);
  const o={
    id:id("e"),type,s,e:Math.min(state.duration,s+5),
    x:.3,y:.25,w:.3,h:.2,a:{}
  };

  if(type==="comment")
    o.a={text:"コメント",size:18,font:"Arial",textColor:"#000",bg:"#fff",fill:true,border:true,borderWidth:2,borderColor:"#2563eb"};

  if(type==="highlight")
    o.a={width:4,color:"#ef4444",fill:false,style:"solid"};

  if(type==="zoom")o.a={zoom:1.5};
  if(type==="skip"){o.x=0;o.y=0;o.w=0;o.h=0}

  state.objects.push(o);
  state.selected=[o.id];
  state.selectedLine=null;
  dirty();
  closeMenu();
  render();
}

$("add").onclick=e=>addMenu(e.clientX,e.clientY);

function addMenu(x,y){
  menu(x,y,`
    <h4>要素を追加</h4>
    <button data-add="comment">コメント</button>
    <button data-add="highlight">強調枠</button>
    <button data-add="zoom">拡大枠</button>
    <button data-add="skip">スキップ</button>
  `);
}

$("menu").onclick=e=>{
  const b=e.target.closest("[data-add]");
  if(b)add(b.dataset.add);
};

function render(){
  renderObjects();
  renderLines();
  renderTimeline();
  $("time").textContent=time(state.pos)+" / "+time(state.duration);
}

function renderObjects(){
  const layer=$("objects"),svg=layer.querySelector("#lines");
  layer.innerHTML="";
  layer.appendChild(svg);

  state.objects.forEach(o=>{
    if(o.type==="skip"||state.pos<o.s||state.pos>o.e)return;

    const d=document.createElement("div");
    d.className="obj"+(state.selected.includes(o.id)?" sel":"");
    d.dataset.id=o.id;
    d.style.cssText+=`left:${o.x*100}%;top:${o.y*100}%;width:${o.w*100}%;height:${o.h*100}%;`;

    if(o.type==="comment"){
      d.textContent=o.a.text;
      d.style.color=o.a.textColor;
      d.style.fontSize=o.a.size+"px";
      d.style.fontFamily=o.a.font;
      d.style.padding="5px";
      d.style.background=o.a.fill?o.a.bg:"transparent";
      d.style.border=o.a.border?`${o.a.borderWidth}px solid ${o.a.borderColor}`:"none";
    }

    if(o.type==="highlight"){
      d.style.border=`${o.a.width}px ${o.a.style} ${o.a.color}`;
      d.style.background=o.a.fill?o.a.color+"33":"transparent";
    }

    if(o.type==="zoom"){
      d.style.border="2px solid #059669";
      d.style.background="#05966922";
      d.innerHTML=`<span style="background:#059669;color:#fff;padding:2px 5px">${o.a.zoom}倍</span>`;
    }

    if(state.selected.includes(o.id)){
      ["t","r","b","l"].forEach(side=>{
        const p=document.createElement("i");
        p.className="port "+side;
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

function point(e){
  const r=$("screen").getBoundingClientRect();
  return{x:clamp((e.clientX-r.left)/r.width,0,1),y:clamp((e.clientY-r.top)/r.height,0,1)}
}

function anchor(o,s){
  if(s==="t")return{x:o.x+o.w/2,y:o.y};
  if(s==="r")return{x:o.x+o.w,y:o.y+o.h/2};
  if(s==="b")return{x:o.x+o.w/2,y:o.y+o.h};
  return{x:o.x,y:o.y+o.h/2};
}

function nearest(o,p){
  const a={
    t:Math.abs(p.y-o.y),
    r:Math.abs(p.x-o.x-o.w),
    b:Math.abs(p.y-o.y-o.h),
    l:Math.abs(p.x-o.x)
  };
  return Object.keys(a).sort((x,y)=>a[x]-a[y])[0];
}

function route(l){
  const a=get(state.objects,l.from),b=get(state.objects,l.to);
  if(!a||!b)return"";
  const p=anchor(a,l.fs),q=anchor(b,l.ts);

  if(l.shape==="直線")return `M${p.x*100} ${p.y*100} L${q.x*100} ${q.y*100}`;

  if(l.shape==="波線"){
    const dx=q.x-p.x,dy=q.y-p.y,len=Math.hypot(dx,dy)||1;
    const nx=-dy/len*.018,ny=dx/len*.018;
    let d=`M${p.x*100} ${p.y*100}`;
    for(let i=1;i<=16;i++){
      const t=i/16,o=i%2?1:-1;
      d+=` L${(p.x+dx*t+nx*o)*100} ${(p.y+dy*t+ny*o)*100}`;
    }
    return d;
  }

  const mx=(p.x+q.x)/2,my=(p.y+q.y)/2;
  if(l.axis==="v")
    return `M${p.x*100} ${p.y*100} L${mx*100} ${p.y*100} L${mx*100} ${q.y*100} L${q.x*100} ${q.y*100}`;
  return `M${p.x*100} ${p.y*100} L${p.x*100} ${my*100} L${q.x*100} ${my*100} L${q.x*100} ${q.y*100}`;
}

function renderLines(){
  const svg=$("lines");
  while(svg.firstChild)svg.removeChild(svg.firstChild);

  state.lines.forEach(l=>{
    const d=route(l);
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
      const defs=document.createElementNS("http://www.w3.org/2000/svg","defs");
      const marker=document.createElementNS("http://www.w3.org/2000/svg","marker");
      const mid="arrow"+l.id;
      marker.id=mid;
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
      defs.appendChild(marker);
      g.appendChild(defs);
      p.setAttribute("marker-end",`url(#${mid})`);
    }

    g.append(hit,p);
    svg.appendChild(g);
  });

  if(drag&&drag.line===false){
    const p=drag.start,q=drag.now;
    const t=document.createElementNS("http://www.w3.org/2000/svg","path");
    t.classList.add("temp");
    t.setAttribute("d",`M${p.x*100} ${p.y*100} L${q.x*100} ${q.y*100}`);
    svg.appendChild(t);
  }
}

function renderTimeline(){
  const width=Math.max(800,800*state.scale);
  $("timeline").style.width=width+"px";

  let r="";
  const step=state.duration<=30?5:state.duration<=120?10:30;
  for(let t=0;t<=state.duration;t+=step)
    r+=`<div class="tick" style="left:${t/state.duration*100}%">${time(t)}</div>`;
  $("ruler").innerHTML=r;

  const rows=[],lanes=$("lanes");
  lanes.innerHTML="";

  state.objects.forEach(o=>{
    let row=0;
    while(rows[row]!=null&&rows[row]>o.s)row++;
    rows[row]=o.e;

    const c=document.createElement("div");
    c.className="clip"+(state.selected.includes(o.id)?" sel":"");
    c.dataset.id=o.id;
    c.style.left=o.s/state.duration*100+"%";
    c.style.width=Math.max((o.e-o.s)/state.duration*100,.5)+"%";
    c.style.top=8+row*30+"px";
    c.style.background=types[o.type][1];
    c.textContent=types[o.type][0]+" "+time(o.s)+"-"+time(o.e);
    lanes.appendChild(c);
  });

  const cursor=document.createElement("div");
  cursor.className="cursor";
  cursor.style.left=state.pos/state.duration*100+"%";
  lanes.appendChild(cursor);

  $("overview").innerHTML=`<div class="range" style="left:0;width:100%"></div>`;
}

$("screen").addEventListener("pointerdown",e=>{
  const port=e.target.closest(".port");

  if(port){
    const o=get(state.objects,e.target.closest(".obj").dataset.id);
    drag={line:false,from:o.id,fs:port.dataset.port,start:anchor(o,port.dataset.port),now:anchor(o,port.dataset.port)};
    window.addEventListener("pointermove",connectionMove);
    window.addEventListener("pointerup",connectionUp,{once:true});
    e.preventDefault();
    return;
  }

  const resize=e.target.closest(".resize");
  if(resize){
    resizeObject(get(state.objects,e.target.closest(".obj").dataset.id),e);
    e.preventDefault();
    return;
  }

  const el=e.target.closest(".obj");
  if(!el){
    state.selected=[];
    state.selectedLine=null;
    render();
    return;
  }

  const o=get(state.objects,el.dataset.id);
  state.selected=e.shiftKey
    ?(state.selected.includes(o.id)?state.selected.filter(x=>x!==o.id):[...state.selected,o.id])
    :[o.id];
  state.selectedLine=null;

  if(!e.shiftKey)moveObject(o,e);
  render();
});

function connectionMove(e){
  drag.now=point(e);
  const el=document.elementFromPoint(e.clientX,e.clientY)?.closest(".obj");
  drag.target=el?el.dataset.id:null;
  renderLines();
}

function connectionUp(){
  window.removeEventListener("pointermove",connectionMove);
  if(drag.target&&drag.target!==drag.from){
    const b=get(state.objects,drag.target);
    state.lines.push({
      id:id("l"),from:drag.from,to:b.id,
      fs:drag.fs,ts:nearest(b,drag.now),
      shape:"直線",axis:"v",dash:"",width:3,color:"#f59e0b",end:"none"
    });
    state.selectedLine=state.lines.at(-1).id;
    state.selected=[];
    dirty();
  }
  drag=null;
  render();
}

function moveObject(o,e){
  const r=$("screen").getBoundingClientRect(),sx=e.clientX,sy=e.clientY,ox=o.x,oy=o.y;
  const move=ev=>{
    o.x=clamp(ox+(ev.clientX-sx)/r.width,0,1-o.w);
    o.y=clamp(oy+(ev.clientY-sy)/r.height,0,1-o.h);
    dirty();
    render();
  };
  const up=()=>{window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up)};
  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);
}

function resizeObject(o,e){
  const r=$("screen").getBoundingClientRect(),sx=e.clientX,sy=e.clientY,ow=o.w,oh=o.h;
  const move=ev=>{
    o.w=clamp(ow+(ev.clientX-sx)/r.width,.05,1-o.x);
    o.h=clamp(oh+(ev.clientY-sy)/r.height,.05,1-o.y);
    dirty();
    render();
  };
  const up=()=>{window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up)};
  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);
}

$("screen").oncontextmenu=e=>{
  e.preventDefault();
  const line=e.target.closest("[data-line]");
  if(line){
    state.selectedLine=line.dataset.line;
    state.selected=[];
    render();
    lineMenu(e.clientX,e.clientY,get(state.lines,state.selectedLine));
    return;
  }
  const el=e.target.closest(".obj");
  if(el){
    const o=get(state.objects,el.dataset.id);
    state.selected=[o.id];
    state.selectedLine=null;
    render();
    objectMenu(e.clientX,e.clientY,o);
  }
};

function colorButtons(key,current){
  return colors.map(c=>`<button class="color ${c===current?"active":""}" data-key="${key}" data-value="${c}" style="background:${c}"></button>`).join("");
}

function objectMenu(x,y,o){
  let h=`<h4>${types[o.type][0]}</h4>`;

  if(o.type==="comment")h+=`
    <label>本文<input id="txt" value="${o.a.text}"></label>
    <label>文字サイズ<input id="size" type="number" value="${o.a.size}"></label>
    <label>フォント<select id="font"><option>Arial</option><option>serif</option><option>sans-serif</option><option>monospace</option></select></label>
    <label>線<input id="border" type="checkbox" ${o.a.border?"checked":""}></label>
    <label>塗り潰し<input id="fill" type="checkbox" ${o.a.fill?"checked":""}></label>
    <div>文字色</div><div class="colors">${colorButtons("textColor",o.a.textColor)}</div>
    <div>線色</div><div class="colors">${colorButtons("borderColor",o.a.borderColor)}</div>
    <div>背景色</div><div class="colors">${colorButtons("bg",o.a.bg)}</div>`;

  if(o.type==="highlight")h+=`
    <div>線の太さ</div><div class="choices">${[2,4,6,8].map(v=>`<button data-width="${v}">${v}px</button>`).join("")}</div>
    <label>塗り潰し<input id="fill" type="checkbox" ${o.a.fill?"checked":""}></label>
    <div>線種</div><div class="choices">${["solid","dotted","dashed","double"].map(v=>`<button data-style="${v}">${v}</button>`).join("")}</div>`;

  if(o.type==="zoom")h+=`<label>拡大倍率<select id="zoom"><option>1.25</option><option>1.5</option><option>2</option><option>3</option></select></label>`;

  h+=`<hr><button id="del" class="danger">削除</button>`;
  menu(x,y,h);

  $("txt")?.addEventListener("input",e=>{o.a.text=e.target.value;dirty();render()});
  $("size")?.addEventListener("change",e=>{o.a.size=+e.target.value;dirty();render()});
  $("font")?.addEventListener("change",e=>{o.a.font=e.target.value;dirty();render()});
  $("border")?.addEventListener("change",e=>{o.a.border=e.target.checked;dirty();render()});
  $("fill")?.addEventListener("change",e=>{o.a.fill=e.target.checked;dirty();render()});
  $("zoom")?.addEventListener("change",e=>{o.a.zoom=+e.target.value;dirty();render()});

  document.querySelectorAll("[data-key]").forEach(b=>b.onclick=()=>{
    o.a[b.dataset.key]=b.dataset.value;dirty();render();objectMenu(x,y,o)
  });
  document.querySelectorAll("[data-width]").forEach(b=>b.onclick=()=>{
    o.a.width=+b.dataset.width;dirty();render();objectMenu(x,y,o)
  });
  document.querySelectorAll("[data-style]").forEach(b=>b.onclick=()=>{
    o.a.style=b.dataset.style;dirty();render();objectMenu(x,y,o)
  });

  $("del").onclick=()=>{
    state.objects=state.objects.filter(v=>v.id!==o.id);
    state.lines=state.lines.filter(v=>v.from!==o.id&&v.to!==o.id);
    state.selected=[];
    dirty();closeMenu();render();
  };
}

function lineMenu(x,y,l){
  menu(x,y,`
    <h4>接続線</h4>
    <div>線形</div>
    <div class="choices">
      <button data-shape="直線">直線</button>
      <button data-shape="折れ線">折れ線</button>
      <button data-shape="波線">波線</button>
    </div>
    <div>線種</div>
    <div class="choices">
      <button data-dash="">実線</button>
      <button data-dash="2 5">点線</button>
      <button data-dash="8 5">破線</button>
      <button data-dash="10 5 2 5">一点鎖線</button>
    </div>
    <label>太さ<select id="lw"><option>2</option><option>3</option><option>5</option></select></label>
    <div>接続元</div>
    <div class="choices">${["t","r","b","l"].map(v=>`<button data-fs="${v}">${v}</button>`).join("")}</div>
    <div>接続先</div>
    <div class="choices">${["t","r","b","l"].map(v=>`<button data-ts="${v}">${v}</button>`).join("")}</div>
    <label>終端<select id="end"><option value="none">なし</option><option value="arrow">矢印</option></select></label>
    <div>色</div><div class="colors">${colorButtons("color",l.color)}</div>
    <hr><button id="dell" class="danger">接続線を削除</button>
  `);

  document.querySelectorAll("[data-shape]").forEach(b=>b.onclick=()=>{l.shape=b.dataset.shape;dirty();render();lineMenu(x,y,l)});
  document.querySelectorAll("[data-dash]").forEach(b=>b.onclick=()=>{l.dash=b.dataset.dash;dirty();render();lineMenu(x,y,l)});
  document.querySelectorAll("[data-fs]").forEach(b=>b.onclick=()=>{l.fs=b.dataset.fs;dirty();render();lineMenu(x,y,l)});
  document.querySelectorAll("[data-ts]").forEach(b=>b.onclick=()=>{l.ts=b.dataset.ts;dirty();render();lineMenu(x,y,l)});
  document.querySelectorAll("[data-key]").forEach(b=>b.onclick=()=>{l[b.dataset.key]=b.dataset.value;dirty();render();lineMenu(x,y,l)});
  $("lw").onchange=e=>{l.width=+e.target.value;dirty();render()};
  $("end").onchange=e=>{l.end=e.target.value;dirty();render()};
  $("dell").onclick=()=>{
    state.lines=state.lines.filter(v=>v.id!==l.id);
    state.selectedLine=null;
    dirty();closeMenu();render();
  };
}

function menu(x,y,html){
  closeMenu();
  $("menu").innerHTML=`<div class="menu">${html}</div>`;
  const m=$("menu").firstElementChild;
  m.style.left=Math.min(x,innerWidth-m.offsetWidth-8)+"px";
  m.style.top=Math.min(y,innerHeight-m.offsetHeight-8)+"px";
}
function closeMenu(){$("menu").innerHTML=""}

$("lanes").addEventListener("pointerdown",e=>{
  const clip=e.target.closest(".clip");
  if(!clip){
    seek(e);
    return;
  }

  const o=get(state.objects,clip.dataset.id);
  state.selected=e.shiftKey
    ?(state.selected.includes(o.id)?state.selected.filter(x=>x!==o.id):[...state.selected,o.id])
    :[o.id];
  state.selectedLine=null;
  render();

  const rect=$("timeline").getBoundingClientRect(),sx=e.clientX,old=o.s,d=o.e-o.s;
  const move=ev=>{
    const dt=(ev.clientX-sx)/rect.width*state.duration;
    o.s=clamp(old+dt,0,state.duration-d);
    o.e=o.s+d;
    dirty();render();
  };
  const up=()=>{window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up)};
  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);
});

$("ruler").onpointerdown=seek;

function seek(e){
  const r=$("timeline").getBoundingClientRect();
  state.pos=clamp((e.clientX-r.left)/r.width,0,1)*state.duration;
  state.video.currentTime=state.pos;
  render();
}

$("play").onclick=()=>{
  if(timer)return;
  state.video.play();
  timer=setInterval(()=>{
    state.pos=state.video.currentTime;
    const s=state.objects.find(o=>o.type==="skip"&&state.pos>=o.s&&state.pos<o.e);
    if(s){
      state.pos=s.e;
      state.video.currentTime=s.e;
    }
    if(state.pos>=state.duration){
      state.pos=state.duration;
      clearInterval(timer);timer=null;
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

$("scale").oninput=e=>{
  state.scale=+e.target.value;
  renderTimeline();
};

$("save").onclick=()=>{
  const name=prompt("編集作業名", "新しい編集作業");
  if(name===null)return;
  state.name=name||"編集作業";
  state.dirty=false;
  $("dirty").classList.add("hidden");
  alert("編集作業を保存しました。");
};

$("make").onclick=()=>{
  alert("現在の編集内容で編集結果動画を作成します。");
};

$("exit").onclick=()=>{
  if(state.dirty){
    const a=confirm("未保存の変更があります。保存して終了しますか？");
    if(a)$("save").click();
    else if(!confirm("保存せずに終了しますか？"))return;
  }
  clearInterval(timer);timer=null;
  $("editor").classList.add("hidden");
  $("home").classList.remove("hidden");
  state=null;
};

document.addEventListener("pointerdown",e=>{
  if(!e.target.closest(".menu"))closeMenu();
},true);

})();
</script>
</body>
</html>

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
button{border:1px solid #aaa;background:#fff;border-radius:4px;padding:6px 10px;cursor:pointer}
button.primary{background:#2563eb;color:#fff;border-color:#2563eb}
button.danger{color:#c00}
.hidden{display:none!important}
.wrap{max-width:1100px;margin:auto;padding:16px}
.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px;margin-bottom:14px}
.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.grow{flex:1}
.muted{color:#666}
.header{background:#1f2937;color:#fff;padding:9px 12px}
.video{height:430px;background:#000;position:relative;overflow:hidden;user-select:none}
.video video{width:100%;height:100%;object-fit:contain;pointer-events:none}
#objects{position:absolute;inset:0}
#lines{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none;z-index:20}
.obj{position:absolute;z-index:30;cursor:grab;touch-action:none}
.obj:active{cursor:grabbing}
.obj.selected{outline:2px dashed #f59e0b}
.port{display:none;position:absolute;width:14px;height:14px;background:#22c55e;border:2px solid #fff;border-radius:50%;z-index:50;cursor:crosshair}
.obj.selected .port{display:block}
.port.t{left:50%;top:-7px;transform:translateX(-50%)}
.port.r{right:-7px;top:50%;transform:translateY(-50%)}
.port.b{left:50%;bottom:-7px;transform:translateX(-50%)}
.port.l{left:-7px;top:50%;transform:translateY(-50%)}
.resize{position:absolute;right:-5px;bottom:-5px;width:12px;height:12px;background:#fbbf24;cursor:nwse-resize}
.line{fill:none;stroke-linecap:round;stroke-linejoin:round}
.line-hit{fill:none;stroke:transparent;stroke-width:18;pointer-events:stroke;cursor:pointer}
.line.selected{stroke:#f59e0b}
.temp{fill:none;stroke:#22c55e;stroke-width:3;stroke-dasharray:7 5}
.timeline-box{background:#fff;border:1px solid #bbb;border-radius:5px;overflow:hidden}
.timeline-view{overflow-x:auto;position:relative}
.timeline{position:relative;min-width:100%;height:270px}
.ruler{height:35px;border-bottom:1px solid #bbb;position:relative}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #aaa;padding:3px 0 0 4px;font-size:10px;white-space:nowrap}
.lanes{position:relative;height:235px}
.clip{position:absolute;height:28px;border-radius:4px;color:#fff;padding:6px;font-size:11px;white-space:nowrap;overflow:hidden;cursor:grab}
.clip.selected{outline:2px solid #f59e0b}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#e11d48;z-index:100;cursor:ew-resize}
.playhead:before{content:"";position:absolute;left:-6px;top:0;width:14px;height:14px;background:#e11d48;border-radius:50%}
.context{position:fixed;z-index:1000;background:#fff;border:1px solid #aaa;border-radius:5px;padding:10px;width:285px;max-height:80vh;overflow:auto;box-shadow:0 5px 20px #0004}
.context h4{margin:0 0 9px}
.context label{display:block;margin:8px 0}
.context input,.context select{width:100%;padding:5px}
.choice{display:flex;gap:4px;flex-wrap:wrap}
.choice button.active{background:#dbeafe}
.colors{display:flex;gap:4px;flex-wrap:wrap}
.color{width:23px;height:23px;padding:0}
.color.active{outline:2px solid #2563eb}
.overview{height:20px;background:#e5e7eb;border:1px solid #aaa;margin-top:8px;position:relative}
.overview-mark{position:absolute;top:0;bottom:0;background:#93c5fd;border:1px solid #2563eb}
.status{min-height:20px;margin-top:8px;color:#666}
</style>
</head>
<body>

<div id="home" class="wrap">
  <div class="card">
    <h2>動画を選択</h2>
    <button id="choose" class="primary">動画を選択</button>
    <input id="file" type="file" accept="video/*" hidden>
  </div>
</div>

<div id="editor" class="wrap hidden">
  <div class="header row">
    <b id="name"></b>
    <span class="grow"></span>
    <span id="unsaved" class="hidden">未保存</span>
    <button id="save">保存</button>
    <button id="export">編集結果を動画にする</button>
    <button id="finish">編集作業を終了する</button>
  </div>

  <div class="card" style="margin-top:12px">
    <div id="video" class="video"></div>
  </div>

  <div class="card">
    <div class="row">
      <button id="play" class="primary">▶ 再生</button>
      <button id="pause">⏸ 一時停止</button>
      <button id="stop">■ 停止</button>
      <span id="clock" class="muted"></span>
      <span class="grow"></span>
      <label>スケール
        <input id="scale" type="range" min="1" max="3" step=".1" value="1">
      </label>
    </div>
    <div class="status">要素の追加：動画領域またはタイムライン上で右クリック</div>
  </div>

  <div class="timeline-box">
    <div id="timelineView" class="timeline-view">
      <div id="timeline" class="timeline">
        <div id="ruler" class="ruler"></div>
        <div id="lanes" class="lanes"></div>
      </div>
    </div>
  </div>
  <div id="overview" class="overview"></div>
</div>

<div id="menu"></div>

<script>
(()=>{
"use strict";

const $=id=>document.getElementById(id);
const types={
  comment:["コメント","#2563eb"],
  highlight:["強調枠","#dc2626"],
  zoom:["拡大枠","#059669"],
  skip:["スキップ","#6b7280"]
};
const colors=["#000000","#ffffff","#ef4444","#f97316","#eab308","#22c55e","#06b6d4","#3b82f6","#8b5cf6","#ec4899","#6b7280","#92400e"];
let seq=1,timer=null,gesture=null,state=null;

const uid=p=>p+(seq++);
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const fmt=t=>Math.floor(t/60)+":"+String(Math.floor(t%60)).padStart(2,"0");
const obj=id=>state.objects.find(o=>o.id===id);
const line=id=>state.lines.find(l=>l.id===id);

$("choose").onclick=()=>$("file").click();

$("file").onchange=e=>{
  const f=e.target.files[0];
  if(!f)return;
  const u=URL.createObjectURL(f);
  const v=document.createElement("video");
  v.preload="metadata";
  v.onloadedmetadata=()=>{
    state={
      file:f,url:u,duration:v.duration,pos:0,scale:1,
      objects:[],lines:[],selected:[],selectedLine:null,dirty:false,video:null
    };
    openEditor();
  };
  v.src=u;
};

function openEditor(){
  $("home").classList.add("hidden");
  $("editor").classList.remove("hidden");
  $("name").textContent=state.file.name;

  const v=document.createElement("video");
  v.src=state.url;
  v.preload="auto";
  $("video").innerHTML="";
  $("video").appendChild(v);
  state.video=v;

  const layer=document.createElement("div");
  layer.id="objects";

  const svg=document.createElementNS("http://www.w3.org/2000/svg","svg");
  svg.id="lines";
  layer.appendChild(svg);
  $("video").appendChild(layer);

  render();
}

function markDirty(){
  state.dirty=true;
  $("unsaved").classList.remove("hidden");
}

function addElement(type,t,x=.3,y=.25){
  const start=clamp(t,0,Math.max(0,state.duration-5));
  const o={
    id:uid("e"),type,s:start,e:Math.min(state.duration,start+5),
    x:clamp(x,0,.7),y:clamp(y,0,.7),w:.3,h:.2,a:{}
  };

  if(type==="comment"){
    o.a={text:"コメント",size:18,font:"Arial",textColor:"#000",
      bg:"#fff",fill:true,border:true,borderWidth:2,borderColor:"#2563eb"};
  }
  if(type==="highlight"){
    o.a={width:4,color:"#ef4444",fill:false,style:"solid"};
  }
  if(type==="zoom")o.a={zoom:1.5};
  if(type==="skip"){
    o.x=0;o.y=0;o.w=0;o.h=0;
  }

  state.objects.push(o);
  state.selected=[o.id];
  state.selectedLine=null;
  markDirty();
  closeMenu();
  render();
}

function showAddMenu(x,y,t,px,py){
  menu(x,y,`
    <h4>要素を追加</h4>
    <div class="muted">開始位置：${fmt(t)}</div>
    <div class="choice" style="margin-top:8px">
      <button data-add="comment">コメント</button>
      <button data-add="highlight">強調枠</button>
      <button data-add="zoom">拡大枠</button>
      <button data-add="skip">スキップ</button>
    </div>
  `);

  document.querySelectorAll("[data-add]").forEach(b=>{
    b.onclick=()=>addElement(b.dataset.add,t,px,py);
  });
}

function point(e){
  const r=$("video").getBoundingClientRect();
  return{
    x:clamp((e.clientX-r.left)/r.width,0,1),
    y:clamp((e.clientY-r.top)/r.height,0,1)
  };
}

function timelineTime(e){
  const r=$("timeline").getBoundingClientRect();
  return clamp((e.clientX-r.left)/r.width,0,1)*state.duration;
}

function render(){
  renderObjects();
  renderLines();
  renderTimeline();
  $("clock").textContent=fmt(state.pos)+" / "+fmt(state.duration);
}

function renderObjects(){
  const layer=$("objects");
  const svg=layer.querySelector("#lines");
  layer.innerHTML="";
  layer.appendChild(svg);

  state.objects.forEach(o=>{
    if(o.type==="skip"||state.pos<o.s||state.pos>o.e)return;

    const d=document.createElement("div");
    d.className="obj"+(state.selected.includes(o.id)?" selected":"");
    d.dataset.id=o.id;
    d.style.left=o.x*100+"%";
    d.style.top=o.y*100+"%";
    d.style.width=o.w*100+"%";
    d.style.height=o.h*100+"%";

    if(o.type==="comment"){
      d.textContent=o.a.text;
      d.style.padding="5px";
      d.style.fontSize=o.a.size+"px";
      d.style.fontFamily=o.a.font;
      d.style.color=o.a.textColor;
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

function anchor(o,p){
  if(p==="t")return{x:o.x+o.w/2,y:o.y};
  if(p==="r")return{x:o.x+o.w,y:o.y+o.h/2};
  if(p==="b")return{x:o.x+o.w/2,y:o.y+o.h};
  return{x:o.x,y:o.y+o.h/2};
}

function nearestPort(o,p){
  const q={
    t:Math.abs(p.y-o.y),
    r:Math.abs(p.x-(o.x+o.w)),
    b:Math.abs(p.y-(o.y+o.h)),
    l:Math.abs(p.x-o.x)
  };
  return Object.keys(q).sort((a,b)=>q[a]-q[b])[0];
}

function pathFor(l){
  const a=obj(l.from),b=obj(l.to);
  if(!a||!b)return"";
  const p=anchor(a,l.fromPort),q=anchor(b,l.toPort);

  if(l.shape==="直線")
    return `M${p.x*100} ${p.y*100} L${q.x*100} ${q.y*100}`;

  const horizontal=Math.abs(q.x-p.x)>Math.abs(q.y-p.y);
  const m=horizontal?(p.x+q.x)/2:(p.y+q.y)/2;

  if(l.shape==="波線"){
    const dx=q.x-p.x,dy=q.y-p.y,len=Math.hypot(dx,dy)||1;
    const nx=-dy/len*.02,ny=dx/len*.02;
    let d=`M${p.x*100} ${p.y*100}`;
    for(let i=1;i<=20;i++){
      const z=i/20,o=i%2?.7:-.7;
      d+=` L${(p.x+dx*z+nx*o)*100} ${(p.y+dy*z+ny*o)*100}`;
    }
    return d;
  }

  if(horizontal)
    return `M${p.x*100} ${p.y*100} L${m*100} ${p.y*100} L${m*100} ${q.y*100} L${q.x*100} ${q.y*100}`;

  return `M${p.x*100} ${p.y*100} L${p.x*100} ${m*100} L${q.x*100} ${m*100} L${q.x*100} ${q.y*100}`;
}

function renderLines(){
  const svg=$("lines");
  while(svg.firstChild)svg.removeChild(svg.firstChild);

  state.lines.forEach(l=>{
    const d=pathFor(l);
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
      marker.id="arrow_"+l.id;
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
      p.setAttribute("marker-end","url(#arrow_"+l.id+")");
    }

    g.appendChild(hit);
    g.appendChild(p);
    svg.appendChild(g);
  });

  if(gesture&&gesture.kind==="connect"){
    const p=gesture.start;
    const q=gesture.now;
    const t=document.createElementNS("http://www.w3.org/2000/svg","path");
    t.classList.add("temp");
    t.setAttribute("d",`M${p.x*100} ${p.y*100} L${q.x*100} ${q.y*100}`);
    svg.appendChild(t);
  }
}

function renderTimeline(){
  const view=$("timelineView");
  const width=Math.max(view.clientWidth,view.clientWidth*state.scale);
  $("timeline").style.width=width+"px";

  const step=state.duration<=30?5:state.duration<=120?10:30;
  let ticks="";
  for(let t=0;t<=state.duration;t+=step)
    ticks+=`<div class="tick" style="left:${t/state.duration*100}%">${fmt(t)}</div>`;
  $("ruler").innerHTML=ticks;

  const lanes=$("lanes");
  lanes.innerHTML="";
  const ends=[];

  state.objects.forEach(o=>{
    let row=0;
    while(ends[row]!=null&&ends[row]>o.s)row++;
    ends[row]=o.e;

    const c=document.createElement("div");
    c.className="clip"+(state.selected.includes(o.id)?" selected":"");
    c.dataset.id=o.id;
    c.style.left=o.s/state.duration*100+"%";
    c.style.width=Math.max((o.e-o.s)/state.duration*100,.8)+"%";
    c.style.top=8+row*32+"px";
    c.style.background=types[o.type][1];
    c.textContent=types[o.type][0]+" "+fmt(o.s)+"-"+fmt(o.e);
    lanes.appendChild(c);
  });

  const ph=document.createElement("div");
  ph.className="playhead";
  ph.style.left=state.pos/state.duration*100+"%";
  lanes.appendChild(ph);

  $("overview").innerHTML=`<div class="overview-mark" style="left:0;width:100%"></div>`;
}

function startMove(o,e){
  const r=$("video").getBoundingClientRect();
  const sx=e.clientX,sy=e.clientY,ox=o.x,oy=o.y;

  const move=ev=>{
    o.x=clamp(ox+(ev.clientX-sx)/r.width,0,1-o.w);
    o.y=clamp(oy+(ev.clientY-sy)/r.height,0,1-o.h);
    markDirty();
    render();
  };
  const up=()=>{
    window.removeEventListener("pointermove",move);
    window.removeEventListener("pointerup",up);
  };
  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);
}

function startResize(o,e){
  const r=$("video").getBoundingClientRect();
  const sx=e.clientX,sy=e.clientY,ow=o.w,oh=o.h;

  const move=ev=>{
    o.w=clamp(ow+(ev.clientX-sx)/r.width,.05,1-o.x);
    o.h=clamp(oh+(ev.clientY-sy)/r.height,.05,1-o.y);
    markDirty();
    render();
  };
  const up=()=>{
    window.removeEventListener("pointermove",move);
    window.removeEventListener("pointerup",up);
  };
  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);
}

$("video").addEventListener("pointerdown",e=>{
  const port=e.target.closest(".port");

  if(port){
    const o=obj(e.target.closest(".obj").dataset.id);
    const r=$("video").getBoundingClientRect();
    gesture={
      kind:"connect",
      from:o.id,
      fromPort:port.dataset.port,
      start:anchor(o,port.dataset.port),
      now:point(e),
      target:null
    };
    e.target.setPointerCapture?.(e.pointerId);
    window.addEventListener("pointermove",connectMove);
    window.addEventListener("pointerup",connectEnd,{once:true});
    e.preventDefault();
    e.stopPropagation();
    return;
  }

  const resize=e.target.closest(".resize");
  if(resize){
    startResize(obj(e.target.closest(".obj").dataset.id),e);
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

  const o=obj(el.dataset.id);
  state.selected=e.shiftKey
    ?(state.selected.includes(o.id)
      ?state.selected.filter(x=>x!==o.id)
      :state.selected.concat(o.id))
    :[o.id];
  state.selectedLine=null;

  if(!e.shiftKey)startMove(o,e);
  render();
});

function connectMove(e){
  if(!gesture)return;
  gesture.now=point(e);

  const el=document.elementFromPoint(e.clientX,e.clientY)?.closest(".obj");
  gesture.target=el?el.dataset.id:null;
  renderLines();
}

function connectEnd(){
  window.removeEventListener("pointermove",connectMove);
  if(!gesture)return;

  const target=gesture.target;

  if(target&&target!==gesture.from){
    const b=obj(target);
    const p=gesture.now;
    state.lines.push({
      id:uid("l"),
      from:gesture.from,
      to:b.id,
      fromPort:gesture.fromPort,
      toPort:nearestPort(b,p),
      shape:"直線",
      dash:"",
      width:3,
      color:"#f59e0b",
      end:"none"
    });
    state.selected=[];
    state.selectedLine=state.lines[state.lines.length-1].id;
    markDirty();
  }

  gesture=null;
  render();
}

$("video").oncontextmenu=e=>{
  e.preventDefault();

  const lineEl=e.target.closest("[data-line]");
  if(lineEl){
    state.selectedLine=lineEl.dataset.line;
    state.selected=[];
    render();
    lineMenu(e.clientX,e.clientY,line(state.selectedLine));
    return;
  }

  const el=e.target.closest(".obj");
  if(el){
    const o=obj(el.dataset.id);
    state.selected=[o.id];
    state.selectedLine=null;
    render();
    objectMenu(e.clientX,e.clientY,o);
    return;
  }

  const p=point(e);
  showAddMenu(e.clientX,e.clientY,state.pos,p.x,p.y);
};

$("timelineView").oncontextmenu=e=>{
  e.preventDefault();

  const clip=e.target.closest(".clip");
  if(clip){
    const o=obj(clip.dataset.id);
    state.selected=[o.id];
    state.selectedLine=null;
    render();
    objectMenu(e.clientX,e.clientY,o);
    return;
  }

  const t=timelineTime(e);
  const r=$("timeline").getBoundingClientRect();
  const x=clamp((e.clientX-r.left)/r.width,0,1);
  showAddMenu(e.clientX,e.clientY,t,x,.25);
};

$("timelineView").onpointerdown=e=>{
  if(e.target.closest(".clip"))return;
  if(e.target.closest(".playhead")||e.target.closest("#lanes")||e.target.closest("#ruler")){
    setTime(timelineTime(e));
    const move=ev=>setTime(timelineTime(ev));
    const up=()=>{
      window.removeEventListener("pointermove",move);
      window.removeEventListener("pointerup",up);
    };
    window.addEventListener("pointermove",move);
    window.addEventListener("pointerup",up);
  }
};

$("lanes").addEventListener("pointerdown",e=>{
  const c=e.target.closest(".clip");
  if(!c)return;

  const o=obj(c.dataset.id);
  state.selected=e.shiftKey
    ?(state.selected.includes(o.id)
      ?state.selected.filter(x=>x!==o.id)
      :state.selected.concat(o.id))
    :[o.id];
  state.selectedLine=null;
  render();

  const r=$("timeline").getBoundingClientRect();
  const sx=e.clientX,old=o.s,d=o.e-o.s;

  const move=ev=>{
    const dt=(ev.clientX-sx)/r.width*state.duration;
    o.s=clamp(old+dt,0,state.duration-d);
    o.e=o.s+d;
    markDirty();
    render();
  };
  const up=()=>{
    window.removeEventListener("pointermove",move);
    window.removeEventListener("pointerup",up);
  };
  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);
});

function setTime(t){
  state.pos=clamp(t,0,state.duration);
  state.video.currentTime=state.pos;
  render();
}

function colorHtml(key,current){
  return colors.map(c=>`<button class="color ${c===current?"active":""}" data-color="${key}" data-value="${c}" style="background:${c}"></button>`).join("");
}

function objectMenu(x,y,o){
  let h=`<h4>${types[o.type][0]}</h4>`;

  if(o.type==="comment"){
    h+=`
    <label>本文<input id="txt" value="${o.a.text}"></label>
    <label>文字サイズ<input id="size" type="number" value="${o.a.size}"></label>
    <label>フォント<select id="font"><option>Arial</option><option>serif</option><option>sans-serif</option><option>monospace</option></select></label>
    <label>線 <input id="border" type="checkbox" ${o.a.border?"checked":""}></label>
    <label>塗り潰し <input id="fill" type="checkbox" ${o.a.fill?"checked":""}></label>
    <div>文字色</div><div class="colors">${colorHtml("textColor",o.a.textColor)}</div>
    <div>線色</div><div class="colors">${colorHtml("borderColor",o.a.borderColor)}</div>
    <div>背景色</div><div class="colors">${colorHtml("bg",o.a.bg)}</div>`;
  }

  if(o.type==="highlight"){
    h+=`
    <div>線の太さ</div>
    <div class="choice">${[2,4,6,8].map(v=>`<button data-width="${v}">${v}px</button>`).join("")}</div>
    <label>塗り潰し <input id="fill" type="checkbox" ${o.a.fill?"checked":""}></label>
    <div>線種</div>
    <div class="choice">${["solid","dotted","dashed","double"].map(v=>`<button data-style="${v}">${v}</button>`).join("")}</div>
    <div>線形</div>
    <div class="choice">${["直線","折れ線","波線"].map(v=>`<button data-shape="${v}">${v}</button>`).join("")}</div>`;
  }

  if(o.type==="zoom"){
    h+=`<label>拡大倍率<select id="zoom"><option>1.25</option><option>1.5</option><option>2</option><option>3</option></select></label>`;
  }

  h+=`<hr><button id="delete" class="danger">削除</button>`;
  menu(x,y,h);

  $("txt")?.addEventListener("input",e=>{o.a.text=e.target.value;markDirty();render()});
  $("size")?.addEventListener("change",e=>{o.a.size=+e.target.value;markDirty();render()});
  $("font")?.addEventListener("change",e=>{o.a.font=e.target.value;markDirty();render()});
  $("border")?.addEventListener("change",e=>{o.a.border=e.target.checked;markDirty();render()});
  $("fill")?.addEventListener("change",e=>{o.a.fill=e.target.checked;markDirty();render()});
  $("zoom")?.addEventListener("change",e=>{o.a.zoom=+e.target.value;markDirty();render()});

  document.querySelectorAll("[data-color]").forEach(b=>b.onclick=()=>{
    o.a[b.dataset.color]=b.dataset.value;
    markDirty();
    render();
    objectMenu(x,y,o);
  });

  document.querySelectorAll("[data-width]").forEach(b=>b.onclick=()=>{
    o.a.width=+b.dataset.width;
    markDirty();
    render();
    objectMenu(x,y,o);
  });

  document.querySelectorAll("[data-style]").forEach(b=>b.onclick=()=>{
    o.a.style=b.dataset.style;
    markDirty();
    render();
    objectMenu(x,y,o);
  });

  document.querySelectorAll("[data-shape]").forEach(b=>b.onclick=()=>{
    o.a.shape=b.dataset.shape;
    markDirty();
    render();
    objectMenu(x,y,o);
  });

  $("delete").onclick=()=>{
    state.objects=state.objects.filter(v=>v.id!==o.id);
    state.lines=state.lines.filter(v=>v.from!==o.id&&v.to!==o.id);
    state.selected=[];
    markDirty();
    closeMenu();
    render();
  };
}

function lineMenu(x,y,l){
  h=`
  <h4>接続線</h4>
  <div>線形</div>
  <div class="choice">
    <button data-shape="直線">直線</button>
    <button data-shape="折れ線">折れ線</button>
    <button data-shape="波線">波線</button>
  </div>
  <div>線種</div>
  <div class="choice">
    <button data-dash="">実線</button>
    <button data-dash="2 5">点線</button>
    <button data-dash="8 5">破線</button>
    <button data-dash="10 5 2 5">一点鎖線</button>
  </div>
  <label>太さ<select id="lineWidth"><option>2</option><option>3</option><option>5</option></select></label>
  <div>始点</div>
  <div class="choice">${["t","r","b","l"].map(v=>`<button data-from="${v}">${v}</button>`).join("")}</div>
  <div>終点</div>
  <div class="choice">${["t","r","b","l"].map(v=>`<button data-to="${v}">${v}</button>`).join("")}</div>
  <label>終端<select id="end"><option value="none">なし</option><option value="arrow">矢印</option></select></label>
  <div>色</div><div class="colors">${colorHtml("color",l.color)}</div>
  <hr><button id="deleteLine" class="danger">接続線を削除</button>`;

  menu(x,y,h);

  document.querySelectorAll("[data-shape]").forEach(b=>b.onclick=()=>{
    l.shape=b.dataset.shape;markDirty();render();lineMenu(x,y,l);
  });
  document.querySelectorAll("[data-dash]").forEach(b=>b.onclick=()=>{
    l.dash=b.dataset.dash;markDirty();render();lineMenu(x,y,l);
  });
  document.querySelectorAll("[data-from]").forEach(b=>b.onclick=()=>{
    l.fromPort=b.dataset.from;markDirty();render();lineMenu(x,y,l);
  });
  document.querySelectorAll("[data-to]").forEach(b=>b.onclick=()=>{
    l.toPort=b.dataset.to;markDirty();render();lineMenu(x,y,l);
  });
  document.querySelectorAll("[data-color]").forEach(b=>b.onclick=()=>{
    l[b.dataset.color]=b.dataset.value;markDirty();render();lineMenu(x,y,l);
  });

  $("lineWidth").onchange=e=>{l.width=+e.target.value;markDirty();render()};
  $("end").onchange=e=>{l.end=e.target.value;markDirty();render()};

  $("deleteLine").onclick=()=>{
    state.lines=state.lines.filter(v=>v.id!==l.id);
    state.selectedLine=null;
    markDirty();
    closeMenu();
    render();
  };
}

function menu(x,y,html){
  closeMenu();
  $("menu").innerHTML=`<div class="context">${html}</div>`;
  const m=$("menu").firstElementChild;
  m.style.left=Math.min(x,innerWidth-m.offsetWidth-8)+"px";
  m.style.top=Math.min(y,innerHeight-m.offsetHeight-8)+"px";
}

function closeMenu(){
  $("menu").innerHTML="";
}

document.addEventListener("pointerdown",e=>{
  if(!e.target.closest(".context"))closeMenu();
},true);

$("scale").oninput=e=>{
  state.scale=+e.target.value;
  renderTimeline();
};

$("play").onclick=()=>{
  if(timer)return;
  state.video.play();

  timer=setInterval(()=>{
    state.pos=state.video.currentTime;

    const skip=state.objects.find(o=>o.type==="skip"&&state.pos>=o.s&&state.pos<o.e);
    if(skip){
      state.pos=skip.e;
      state.video.currentTime=skip.e;
    }

    if(state.pos>=state.duration){
      state.pos=state.duration;
      state.video.pause();
      clearInterval(timer);
      timer=null;
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
  setTime(0);
};

$("save").onclick=()=>{
  const n=prompt("編集作業名","新しい編集作業");
  if(n===null)return;
  state.dirty=false;
  $("unsaved").classList.add("hidden");
  alert("編集作業を保存しました。");
};

$("export").onclick=()=>{
  alert("現在の編集内容で編集結果動画を作成します。");
};

$("finish").onclick=()=>{
  if(state.dirty){
    const save=confirm("未保存の変更があります。保存して終了しますか？");
    if(save)$("save").click();
    else if(!confirm("保存せずに終了しますか？"))return;
  }

  clearInterval(timer);
  timer=null;
  $("editor").classList.add("hidden");
  $("home").classList.remove("hidden");
  state=null;
};

})();
</script>
</body>
</html>

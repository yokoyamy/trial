<?php
/* 動画編集モック - 全文差し替え版 */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;height:100%;font:13px "Yu Gothic",sans-serif;color:#222;background:#eef1f5}
button,input{font:inherit}
button{padding:5px 9px;border:1px solid #999;border-radius:4px;background:#fff;cursor:pointer}
button:hover{background:#eaf2ff}
.primary{background:#2563eb;color:#fff;border-color:#2563eb}
.danger{color:#b91c1c;border-color:#fca5a5}
.hidden{display:none!important}
.small{font-size:11px;color:#666}
.sp{flex:1}
#home{height:100%;padding:16px;display:grid;grid-template-columns:1.4fr 1fr;gap:16px}
.panel{background:#fff;border:1px solid #ccd2da;border-radius:6px;padding:12px;overflow:auto}
.panel h2{margin:0 0 10px;font-size:15px}
.videoRow{border:1px solid #bbb;border-radius:5px;margin:8px 0;background:#fafafa}
.videoHead{padding:8px;background:#e8eef7;display:flex;gap:8px;align-items:center}
.workList{margin:5px 8px 8px 28px;border-left:3px solid #3b82f6;padding-left:8px}
.workRow{display:flex;gap:8px;align-items:center;padding:6px 3px;border-bottom:1px dotted #ccc}
#editor{height:100%;display:flex;flex-direction:column}
#top{display:flex;align-items:center;gap:7px;padding:7px 10px;background:#1f2937;color:#fff}
#top button{background:#374151;color:#fff;border-color:#666}
#dirty{color:#facc15}
#stage{flex:1;min-height:0;background:#111;display:flex;align-items:center;justify-content:center;padding:8px}
#videoBox{position:relative;width:min(80vw,760px);aspect-ratio:16/9;background:#000;overflow:hidden;user-select:none}
#videoBox video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain}
#sample{position:absolute;inset:0;background:linear-gradient(135deg,#172554,#0f766e 55%,#92400e);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px}
#sample span{padding:12px 20px;border:1px solid #ffffff66;background:#0005;border-radius:8px}
#objects{position:absolute;inset:0}
.obj{position:absolute;border:2px solid #22c55e;background:#22c55e22;cursor:move;user-select:none}
.obj.comment{background:#fff;color:#111;border-color:#2563eb;padding:5px;display:flex;align-items:center;justify-content:center}
.obj.box{border-color:#ef4444}
.obj.zoom{border-color:#06b6d4;background:#06b6d422}
.obj.skip{border-color:#6b7280;background:#6b728044}
.obj.sel{outline:2px dashed #facc15;outline-offset:2px}
.resize{position:absolute;right:-5px;bottom:-5px;width:10px;height:10px;background:#facc15;border:1px solid #333;cursor:nwse-resize}
#lines{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
.lineHit{pointer-events:stroke;stroke:transparent;stroke-width:16;fill:none;cursor:pointer}
#connectHint{position:absolute;left:8px;top:8px;background:#dc2626;color:#fff;padding:4px 8px;border-radius:4px;z-index:20}
#controls{display:flex;gap:7px;align-items:center;flex-wrap:wrap;padding:7px 10px;background:#e5e7eb;border-top:1px solid #aaa}
#controls input[type=range]{width:150px}
#timeline{height:280px;background:#fff;border-top:1px solid #bbb;padding:6px 10px}
#info{display:flex;gap:15px;margin-bottom:5px}
#tlrow{display:flex;gap:4px;height:190px}
#tlrow>button{width:28px}
#tl{position:relative;flex:1;min-width:0}
#ruler{height:27px;position:relative;border:1px solid #aaa;background:#f5f7fa;overflow:hidden}
.tick{position:absolute;height:100%;border-left:1px solid #888;padding-left:3px;font-size:10px;pointer-events:none}
#lanes{height:150px;position:relative;border:1px solid #aaa;border-top:0;overflow:hidden;background:#fff}
.track{position:absolute;height:23px;border-radius:3px;color:#fff;line-height:23px;padding:0 9px;white-space:nowrap;overflow:hidden;border:1px solid #0005;cursor:move}
.track.sel{outline:2px solid #facc15}
.edge{position:absolute;top:0;height:100%;width:7px;background:#0004;cursor:ew-resize}
.edge.l{left:0}.edge.r{right:0}
#cursor{position:absolute;top:0;width:2px;background:#ef4444;z-index:20;pointer-events:none}
#cursor i{position:absolute;left:-7px;top:-7px;width:16px;height:16px;border-radius:50%;background:#ef4444;border:2px solid #fff;pointer-events:auto;cursor:ew-resize}
#overview{height:24px;margin-top:6px;background:#e2e8f0;border:1px solid #94a3b8;position:relative}
#window{position:absolute;top:0;height:100%;background:#2563eb33;border:2px solid #2563eb;cursor:grab}
.grip{position:absolute;top:0;width:8px;height:100%;background:#2563eb;cursor:ew-resize}
.grip.l{left:-2px}.grip.r{right:-2px}
#menu{position:fixed;z-index:100;background:#fff;border:1px solid #888;border-radius:5px;padding:6px;box-shadow:0 4px 15px #0004;min-width:190px;max-width:280px}
#menu button{display:block;width:100%;text-align:left;border:0;margin:2px 0}
#modal{position:fixed;inset:0;background:#0007;display:none;align-items:center;justify-content:center;z-index:200}
#modalBox{background:#fff;border-radius:6px;padding:15px;min-width:330px;max-width:520px}
#modalBtns{display:flex;justify-content:flex-end;gap:6px;margin-top:12px}
</style>
</head>
<body>

<section id="home">
  <div class="panel">
    <h2>オリジナル動画</h2>
    <div style="display:flex;gap:6px;margin-bottom:8px">
      <button class="primary" id="pick">動画を選択</button>
      <button id="sampleBtn">例題動画を取り込む</button>
      <input id="file" type="file" accept="video/*" hidden>
    </div>
    <div id="videos"></div>
  </div>
  <div class="panel">
    <h2>編集結果の動画</h2>
    <div id="results"></div>
  </div>
</section>

<section id="editor" class="hidden">
  <div id="top">
    <b>動画：<span id="videoName"></span></b>
    <b>編集作業：<span id="workName"></span></b>
    <span id="dirty"></span>
    <span class="sp"></span>
    <button id="save">保存</button>
    <button id="saveAs">別の編集作業として保存</button>
    <button id="export">編集結果を動画にする</button>
    <button id="finish">終了</button>
  </div>

  <div id="stage">
    <div id="videoBox">
      <video id="video" preload="metadata"></video>
      <div id="sample"><span>例題動画<br><small>実動画を選択するとここに表示されます</small></span></div>
      <div id="objects"></div>
      <svg id="lines"></svg>
      <div id="connectHint" class="hidden">接続先の要素をクリックしてください　Escで解除</div>
    </div>
  </div>

  <div id="controls">
    <button id="play">▶ 再生</button>
    <button id="pause">⏸ 一時停止</button>
    <button id="stop">■ 停止</button>
    <span id="pos"></span>
    <span class="sp"></span>
    <button id="zoomOut">－</button>
    <input id="zoomSlider" type="range" min="0" max="1000" value="0">
    <button id="zoomIn">＋</button>
    <span id="zoomInfo"></span>
    <button id="fit">全体表示</button>
  </div>

  <div id="timeline">
    <div id="info"><span id="range"></span><span>タイムライン上の要素はドラッグで移動、左右端で長さ変更</span></div>
    <div id="tlrow">
      <button id="left">◀</button>
      <div id="tl">
        <div id="ruler"></div>
        <div id="lanes"></div>
        <div id="cursor"><i></i></div>
      </div>
      <button id="right">▶</button>
    </div>
    <div id="overview"><div id="window"><i class="grip l"></i><i class="grip r"></i></div></div>
  </div>
</section>

<div id="menu" class="hidden"></div>
<div id="modal"><div id="modalBox"><div id="modalText"></div><div id="modalBtns"></div></div></div>

<script>
"use strict";
const $=id=>document.getElementById(id);
const MIN=.3,LIMIT=10,DEFLEN=5;
const COLORS={comment:"#2563eb",box:"#ef4444",zoom:"#06b6d4",skip:"#6b7280"};
const NAMES={comment:"コメント",box:"強調枠",zoom:"拡大枠",skip:"スキップ"};
let seq=1,edit=null,drag=null,menuTarget=null;

const uid=()=>seq++;
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
function time(t){let m=Math.floor(t/60),s=t-m*60;return m+":"+s.toFixed(2).padStart(5,"0")}
function showModal(text,buttons){
  $("modalText").innerHTML=text;$("modalBtns").innerHTML="";
  buttons.forEach(b=>{let x=document.createElement("button");x.textContent=b[0];if(b[2])x.className=b[2];x.onclick=()=>{b[1]&&b[1]();$("modal").style.display="none"};$("modalBtns").appendChild(x)});
  $("modal").style.display="flex";
}
function newVideo(name,url,dur){
  return {id:uid(),name,url:url||"",dur:dur||60,created:Date.now()};
}
let videos=[],works=[],results=[];

function home(){
  $("videos").innerHTML=videos.length?videos.map(v=>{
    let ws=works.filter(w=>w.videoId===v.id);
    return `<div class="videoRow"><div class="videoHead"><div><b>${esc(v.name)}</b><br><span class="small">編集作業 ${ws.length}件</span></div><span class="sp"></span><button class="primary" data-new="${v.id}">新しい編集作業</button><button class="danger" data-del="${v.id}">削除</button></div><div class="workList">${ws.length?ws.map(w=>`<div class="workRow"><span>${esc(w.name)}</span><span class="small">保存 ${new Date(w.saved).toLocaleString()}</span><span class="sp"></span><button data-open="${w.id}">再開</button><button class="danger" data-dw="${w.id}">削除</button></div>`).join(""):"<span class='small'>編集作業はありません</span>"}</div></div>`
  }).join(""):"<p class='small'>オリジナル動画はありません。</p>";
  $("results").innerHTML=results.length?results.map(r=>`<div class="workRow"><span>${esc(r.name)}</span><span class="small">${new Date(r.created).toLocaleString()}</span><span class="sp"></span><button class="danger" data-dr="${r.id}">削除</button></div>`).join(""):"<p class='small'>編集結果はありません。</p>";
}
$("pick").onclick=()=>$("file").click();
$("file").onchange=e=>{
  let f=e.target.files[0];if(!f)return;
  let u=URL.createObjectURL(f),v=document.createElement("video");
  v.preload="metadata";v.onloadedmetadata=()=>{if(videos.length>=LIMIT){showModal("オリジナル動画は10件までです。",[[ "閉じる" ]]);return}let x=newVideo(f.name,u,v.duration);videos.push(x);home();openEditor(x,null)};
  v.onerror=()=>showModal("この動画を読み込めませんでした。",[["閉じる"]]);v.src=u;
};
$("sampleBtn").onclick=()=>{
  if(videos.length>=LIMIT)return showModal("オリジナル動画は10件までです。",[["閉じる"]]);
  let v=newVideo("例題動画_操作説明.mp4","",60);videos.push(v);home();openEditor(v,null);
};
$("videos").onclick=e=>{
  let b=e.target.closest("button");if(!b)return;
  if(b.dataset.new)return openEditor(videos.find(v=>v.id==b.dataset.new),null);
  if(b.dataset.open){let w=works.find(w=>w.id==b.dataset.open);openEditor(videos.find(v=>v.id==w.videoId),w)}
  if(b.dataset.del){let id=+b.dataset.del;videos=videos.filter(v=>v.id!==id);works=works.filter(w=>w.videoId!==id);home()}
  if(b.dataset.dw){works=works.filter(w=>w.id!=b.dataset.dw);home()}
};
$("results").onclick=e=>{let b=e.target.closest("[data-dr]");if(b){results=results.filter(r=>r.id!=b.dataset.dr);home()}};

function openEditor(v,w){
  edit={video:v,work:w,dur:v.dur,time:0,vs:0,ve:v.dur,els:w?JSON.parse(JSON.stringify(w.els)):[],lines:w?JSON.parse(JSON.stringify(w.lines)):[],sel:[],line:null,dirty:false,playing:false};
  $("home").classList.add("hidden");$("editor").classList.remove("hidden");
  $("videoName").textContent=v.name;$("workName").textContent=w?w.name:"未保存";
  $("sample").classList.toggle("hidden",!!v.url);
  $("video").classList.toggle("hidden",!v.url);
  if(v.url){$("video").src=v.url;$("video").currentTime=0}
  render();
}
function dirty(){edit.dirty=true;$("dirty").textContent="（未保存の変更あり）"}
function render(){
  renderVideo();renderTimeline();$("pos").textContent=time(edit.time)+" / "+time(edit.dur);
  $("range").textContent="表示範囲："+time(edit.vs)+" ～ "+time(edit.ve);
  let z=edit.dur/(edit.ve-edit.vs);$("zoomInfo").textContent=z.toFixed(1)+"倍";
  $("zoomSlider").value=Math.round(Math.log(z)/Math.log(edit.dur/MIN)*1000);
}
function renderVideo(){
  let box=$("videoBox"),layer=$("objects");layer.innerHTML="";
  edit.els.forEach(o=>{
    if(edit.time<o.s||edit.time>o.e)return;
    let d=document.createElement("div");d.className="obj "+o.type+(edit.sel.includes(o.id)?" sel":"");
    d.dataset.id=o.id;d.style.cssText=`left:${o.x*100}%;top:${o.y*100}%;width:${o.w*100}%;height:${o.h*100}%`;
    d.textContent=o.type==="comment"?o.text:NAMES[o.type];
    if(edit.sel.includes(o.id)){let r=document.createElement("i");r.className="resize";d.appendChild(r)}
    layer.appendChild(d);
  });
  renderLines();
}
function renderLines(){
  let s=$("lines"),w=$("videoBox").clientWidth,h=$("videoBox").clientHeight;
  s.setAttribute("viewBox",`0 0 ${w} ${h}`);
  s.innerHTML="";
  edit.lines.forEach(l=>{
    let a=edit.els.find(x=>x.id===l.a),b=edit.els.find(x=>x.id===l.b);if(!a||!b)return;
    let x1=(a.x+a.w/2)*w,y1=(a.y+a.h/2)*h,x2=(b.x+b.w/2)*w,y2=(b.y+b.h/2)*h;
    let g=document.createElementNS("http://www.w3.org/2000/svg","g");
    let p=document.createElementNS("http://www.w3.org/2000/svg","path");
    p.setAttribute("d",`M${x1},${y1} L${x2},${y2}`);p.setAttribute("stroke",l===edit.line?"#f59e0b":"#fff");p.setAttribute("stroke-width",l===edit.line?4:2);p.setAttribute("fill","none");
    g.appendChild(p);s.appendChild(g);
  });
}
function layout(){
  let rows=[];
  return Object.fromEntries([...edit.els].sort((a,b)=>a.s-b.s).map(o=>{let i=0;while(rows[i]!=null&&rows[i]>o.s)i++;rows[i]=o.e;return[o.id,i]}));
}
function x2t(x){let w=$("lanes").clientWidth;return edit.vs+x/w*(edit.ve-edit.vs)}
function t2x(t){return(t-edit.vs)/(edit.ve-edit.vs)*$("lanes").clientWidth}
function renderTimeline(){
  let w=edit.ve-edit.vs,step=w<5?.5:w<20?1:w<60?5:10,h="";
  for(let t=Math.ceil(edit.vs/step)*step;t<=edit.ve+.001;t+=step)h+=`<i class="tick" style="left:${t2x(t)}px">${time(t)}</i>`;
  $("ruler").innerHTML=h;
  let rows=layout();$("lanes").innerHTML=edit.els.map(o=>{
    if(o.e<edit.vs||o.s>edit.ve)return"";
    return `<div class="track${edit.sel.includes(o.id)?" sel":""}" data-id="${o.id}" style="left:${t2x(o.s)}px;width:${Math.max(12,t2x(o.e)-t2x(o.s))}px;top:${4+rows[o.id]*27}px;background:${COLORS[o.type]}"><i class="edge l"></i>${NAMES[o.type]}<i class="edge r"></i></div>`
  }).join("");
  let c=$("cursor");c.style.left=t2x(edit.time)+"px";c.style.height="177px";
  let win=$("window"),ow=$("overview").clientWidth;win.style.left=edit.vs/edit.dur*ow+"px";win.style.width=(edit.ve-edit.vs)/edit.dur*ow+"px";
}
function seek(t){edit.time=clamp(t,0,edit.dur);if(edit.video&&edit.video.url)$("video").currentTime=edit.time;render()}
$("ruler").onpointerdown=e=>{seek(x2t(e.offsetX));let f=ev=>seek(x2t(ev.clientX-$("ruler").getBoundingClientRect().left));let u=()=>{removeEventListener("pointermove",f);removeEventListener("pointerup",u)};addEventListener("pointermove",f);addEventListener("pointerup",u)};
$("cursor").onpointerdown=e=>{e.stopPropagation();$("ruler").dispatchEvent(new PointerEvent("pointerdown",{clientX:e.clientX,bubbles:true}))};

function trackDrag(start,move){
  let f=e=>move(e),u=()=>{removeEventListener("pointermove",f);removeEventListener("pointerup",u);drag=null};
  addEventListener("pointermove",f);addEventListener("pointerup",u);
}
$("lanes").onpointerdown=e=>{
  let n=e.target.closest(".track");if(!n)return;
  let o=edit.els.find(x=>x.id==n.dataset.id),r=$("lanes").getBoundingClientRect(),start=e.clientX,os=o.s,oe=o.e,mode=e.target.classList.contains("l")?"l":e.target.classList.contains("r")?"r":"m";
  if(e.shiftKey)edit.sel=edit.sel.includes(o.id)?edit.sel.filter(x=>x!==o.id):edit.sel.concat(o.id);else edit.sel=[o.id];
  render();
  trackDrag(e,ev=>{
    let dt=(ev.clientX-start)/r.width*(edit.ve-edit.vs);
    if(mode==="m"){let len=oe-os;o.s=clamp(os+dt,0,edit.dur-len);o.e=o.s+len}
    if(mode==="l")o.s=clamp(os+dt,0,oe-MIN);
    if(mode==="r")o.e=clamp(oe+dt,os+MIN,edit.dur);
    dirty();render();
  });
};
$("objects").onpointerdown=e=>{
  let n=e.target.closest(".obj");if(!n)return;
  let o=edit.els.find(x=>x.id==n.dataset.id);if(!o)return;
  if(edit.connectFrom){connect(edit.connectFrom,o.id);edit.connectFrom=null;$("connectHint").classList.add("hidden");render();return}
  if(e.shiftKey)edit.sel=edit.sel.includes(o.id)?edit.sel.filter(x=>x!==o.id):edit.sel.concat(o.id);else edit.sel=[o.id];
  let r=$("videoBox").getBoundingClientRect(),sx=e.clientX,sy=e.clientY,orig=edit.sel.map(id=>{let x=edit.els.find(q=>q.id===id);return{id,x:x.x,y:x.y,w:x.w,h:x.h}});
  let resize=e.target.classList.contains("resize");
  trackDrag(e,ev=>{
    let dx=(ev.clientX-sx)/r.width,dy=(ev.clientY-sy)/r.height;
    orig.forEach(a=>{let x=edit.els.find(q=>q.id===a.id);if(resize&&edit.sel.length===1){x.w=clamp(a.w+dx,.03,1-a.x);x.h=clamp(a.h+dy,.03,1-a.y)}else{x.x=clamp(a.x+dx,0,1-a.w);x.y=clamp(a.y+dy,0,1-a.h)}});dirty();render();
  });render();
};

function connect(a,b){
  if(a===b)return;
  if(!edit.lines.some(l=>l.a===a&&l.b===b))edit.lines.push({id:uid(),a,b});
  dirty();
}
function add(type){
  let s=edit.time,e=Math.min(edit.dur,s+DEFLEN),o={id:uid(),type,s,e,x:.3,y:.3,w:.3,h:.2};
  if(type==="comment")o.text="コメント";
  if(type==="skip")o.w=.05;
  edit.els.push(o);edit.sel=[o.id];dirty();render();
}
function deleteSelected(){
  edit.lines=edit.lines.filter(l=>!edit.sel.includes(l.a)&&!edit.sel.includes(l.b));
  edit.els=edit.els.filter(o=>!edit.sel.includes(o.id));edit.sel=[];dirty();render();
}
$("videoBox").oncontextmenu=e=>{
  e.preventDefault();let n=e.target.closest(".obj");menuTarget=n?edit.els.find(o=>o.id==n.dataset.id):null;
  let m=$("menu");m.innerHTML="";
  if(menuTarget){
    [["接続元にする",()=>{edit.connectFrom=menuTarget.id;$("connectHint").classList.remove("hidden")}],
     ["削除",deleteSelected]].forEach(a=>{let b=document.createElement("button");b.textContent=a[0];b.onclick=()=>{a[1]();m.classList.add("hidden")};m.appendChild(b)});
  }else{
    [["コメントを追加",()=>add("comment")],["強調枠を追加",()=>add("box")],["拡大枠を追加",()=>add("zoom")],["スキップを追加",()=>add("skip")]].forEach(a=>{let b=document.createElement("button");b.textContent=a[0];b.onclick=()=>{a[1]();m.classList.add("hidden")};m.appendChild(b)});
  }
  m.style.left=Math.min(e.clientX,innerWidth-290)+"px";m.style.top=Math.min(e.clientY,innerHeight-250)+"px";m.classList.remove("hidden");
};
addEventListener("pointerdown",e=>{if(!e.target.closest("#menu"))$("menu").classList.add("hidden")});
addEventListener("keydown",e=>{if(e.key==="Escape"){edit.connectFrom=null;$("connectHint").classList.add("hidden")};if(e.key==="Delete"&&edit.sel.length)deleteSelected()});

function setRange(s,e){let w=clamp(e-s,MIN,edit.dur);s=clamp(s,0,edit.dur-w);edit.vs=s;edit.ve=s+w}
function zoom(f,c){let w=edit.ve-edit.vs,n=clamp(w/f,MIN,edit.dur),r=(c-edit.vs)/w;setRange(c-r*n,c-r*n+n);render()}
$("zoomIn").onclick=()=>zoom(1.5,edit.time>=edit.vs&&edit.time<=edit.ve?edit.time:(edit.vs+edit.ve)/2);
$("zoomOut").onclick=()=>zoom(.667,(edit.vs+edit.ve)/2);
$("fit").onclick=()=>{setRange(0,edit.dur);render()};
$("zoomSlider").oninput=e=>{let z=Math.pow(edit.dur/MIN,+e.target.value/1000),n=edit.dur/z,c=(edit.vs+edit.ve)/2;setRange(c-n/2,c+n/2);render()};
$("left").onclick=()=>{let w=edit.ve-edit.vs;setRange(edit.vs-w*.25,edit.ve-w*.25);render()};
$("right").onclick=()=>{let w=edit.ve-edit.vs;setRange(edit.vs+w*.25,edit.ve+w*.25);render()};
$("overview").onpointerdown=e=>{
  if(e.target.classList.contains("grip"))return;
  let r=$("overview").getBoundingClientRect(),c=(e.clientX-r.left)/r.width*edit.dur,w=edit.ve-edit.vs;setRange(c-w/2,c+w/2);render();
};
$("window").onpointerdown=e=>{
  if(e.target.classList.contains("grip"))return;
  let r=$("overview").getBoundingClientRect(),sx=e.clientX,s=edit.vs,w=edit.ve-edit.vs;
  trackDrag(e,ev=>{let d=(ev.clientX-sx)/r.width*edit.dur;setRange(s+d,s+d+w);render()});
};
$("overview").onclick=e=>{if(e.target!==$("overview"))return};

$("play").onclick=()=>{
  if(edit.playing)return;edit.playing=true;
  let last=performance.now();
  function loop(now){if(!edit.playing)return;edit.time+=((now-last)/1000);last=now;
    if(edit.time>=edit.dur){edit.time=edit.dur;edit.playing=false}
    if(edit.video.url)$("video").currentTime=edit.time;render();if(edit.playing)requestAnimationFrame(loop)}
  if(edit.video.url)$("video").play();requestAnimationFrame(loop);
};
$("pause").onclick=()=>{edit.playing=false;$("video").pause()};
$("stop").onclick=()=>{edit.playing=false;$("video").pause();seek(0)};
$("video").ontimeupdate=()=>{if(edit.playing){edit.time=$("video").currentTime;render()}};

function saveWork(name,overwrite){
  let data={els:JSON.parse(JSON.stringify(edit.els)),lines:JSON.parse(JSON.stringify(edit.lines))};
  if(overwrite){edit.work.name=name;edit.work.saved=Date.now();edit.work.els=data.els;edit.work.lines=data.lines}
  else{let w={id:uid(),videoId:edit.video.id,name,saved:Date.now(),els:data.els,lines:data.lines};works.push(w);edit.work=w}
  edit.dirty=false;$("dirty").textContent="";$("workName").textContent=name;home();
}
$("save").onclick=()=>{
  if(edit.work)return saveWork(edit.work.name,true);
  showModal("編集作業の名前<br><input id='name' style='width:100%' value='編集作業'>",[["保存",()=>saveWork($("name").value.trim()||"編集作業",false)],["キャンセル"]]);
};
$("saveAs").onclick=()=>showModal("編集作業の名前<br><input id='name' style='width:100%' value='別の編集作業'>",[["保存",()=>saveWork($("name").value.trim()||"別の編集作業",false)],["キャンセル"]]);
$("export").onclick=()=>{
  showModal("編集結果を作成しています…<br><progress id='progress' max='100' value='0' style='width:100%'></progress>",[]);
  let p=0,t=setInterval(()=>{p+=10;$("progress").value=p;if(p>=100){clearInterval(t);results.push({id:uid(),name:edit.video.name+" 編集結果",created:Date.now()});showModal("編集結果を作成しました。",[["OK"]])}},80);
};
$("finish").onclick=()=>{
  if(!edit.dirty){closeEditor();return}
  showModal("未保存の変更があります。",[["保存",()=>{$("save").click()}],["保存せず終了",closeEditor],["編集に戻る"]]);
};
function closeEditor(){edit.playing=false;$("video").pause();$("editor").classList.add("hidden");$("home").classList.remove("hidden");home()}
home();
</script>
</body>
</html>

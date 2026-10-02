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
.header{height:54px;display:flex;align-items:center;gap:8px;padding:0 12px;background:#161b22;border-bottom:1px solid #30363d}
.title{font-weight:700;margin-right:8px}.btn{border:1px solid #3b4654;background:#242c36;color:#e8edf5;border-radius:6px;padding:7px 11px}.primary{background:#1769aa;border-color:#2387d9}.status{margin-left:auto;color:#9aa5b1;font-size:12px}
.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{min-height:0;flex:1;background:#05070a;display:flex;padding:12px}
.video-wrap{position:relative;width:min(1200px,100%);height:100%;margin:auto;background:#000;overflow:hidden;border:1px solid #222}
video{width:100%;height:100%;object-fit:contain;display:block}
.overlay{position:absolute;inset:0;z-index:20;pointer-events:none}
.element{position:absolute;pointer-events:auto;user-select:none;touch-action:none;min-width:45px;min-height:28px;cursor:move}
.element.selected{outline:2px solid #4da3ff;outline-offset:3px}
.element-body{width:100%;height:100%;display:flex;align-items:center;justify-content:center;overflow:hidden}
.comment .element-body{color:#fff;text-shadow:0 1px 4px #000;font-size:18px;padding:4px;background:#0005}
.highlight .element-body{border:4px solid #f04444}.highlight.round .element-body{border-radius:18px}.highlight.circle .element-body{border-radius:50%}
.zoombox .element-body{border:3px solid #54d68a;background:#ffffff18}
.handle{position:absolute;width:10px;height:10px;background:#fff;border:2px solid #4da3ff}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.ne{right:-5px;top:-5px;cursor:nesw-resize}.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.se{right:-5px;bottom:-5px;cursor:nwse-resize}
.hint{position:absolute;inset:0;display:grid;place-items:center;color:#66717f;text-align:center;line-height:1.8;pointer-events:none}
svg{position:absolute;inset:0;width:100%;height:100%;z-index:10;pointer-events:none}
.connection-hit{fill:none;stroke:transparent;stroke-width:18;pointer-events:stroke;cursor:pointer}
.connection{fill:none;stroke:#90a4ae;stroke-width:2}.connection.selected{stroke:#4da3ff;stroke-width:3}
.timeline{height:285px;flex:none;background:#11161d;border-top:1px solid #30363d;display:flex;flex-direction:column}
.timeline-toolbar{height:44px;display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid #30363d}
.time{font-variant-numeric:tabular-nums;min-width:170px}.scale{margin-left:auto;display:flex;align-items:center;gap:7px;color:#8b949e;font-size:12px}.scale input{width:130px}
.timeline-scroll{min-height:0;flex:1;overflow:hidden}
.timeline-content{position:relative;width:100%;height:100%}
.axis{height:32px;position:relative;margin-left:110px;background:#171d25;border-bottom:1px solid #30363d}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #343b45;color:#7d8590;font-size:10px;padding:4px 0 0 3px;white-space:nowrap}
.rows{margin-left:110px}.row{height:48px;position:relative;border-bottom:1px solid #242b33}.row-label{position:absolute;right:100%;top:0;width:110px;height:48px;display:flex;align-items:center;padding:0 8px;background:#161b22;border-right:1px solid #30363d;font-size:11px}.dot{width:8px;height:8px;border-radius:50%;margin-right:6px}
.lane{height:100%;position:relative}.bar{position:absolute;top:8px;height:32px;border-radius:5px;border:1px solid currentColor;display:flex;align-items:center;cursor:grab;user-select:none;touch-action:none}.bar.selected{box-shadow:0 0 0 2px #4da3ff}.bar span{font-size:10px;padding:0 7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bar-handle{position:absolute;top:0;width:8px;height:100%;cursor:ew-resize}.bar-handle.left{left:-4px}.bar-handle.right{right:-4px}
.playhead{position:absolute;top:32px;bottom:0;width:2px;background:#f04444;z-index:100;pointer-events:none}.playhead:before{content:"";position:absolute;top:-2px;left:-5px;width:12px;height:12px;background:#f04444;clip-path:polygon(0 0,100% 0,50% 100%)}
.menu{position:fixed;z-index:1000;display:none;min-width:220px;background:#1c2531;border:1px solid #46515f;border-radius:7px;box-shadow:0 12px 30px #0009;padding:5px}.menu-title{font-size:11px;color:#8b949e;padding:6px 8px}.menu button{display:block;width:100%;text-align:left;border:0;background:transparent;color:#e8edf5;padding:8px;border-radius:4px}.menu button:hover{background:#2b3745}.menu hr{border:0;border-top:1px solid #303b48;margin:4px 0}.menu select{width:100%;background:#242c36;color:#fff;border:1px solid #46515f;padding:6px;border-radius:4px}
.toast{position:fixed;right:15px;bottom:15px;background:#202938;border:1px solid #46515f;border-radius:6px;padding:9px 13px;display:none;z-index:2000}
</style>
</head>
<body>
<div class="app">
<header class="header">
<div class="title">動画編集・注釈</div>
<button class="btn primary" id="loadBtn">動画を選択</button>
<input id="fileInput" type="file" accept="video/*" hidden>
<button class="btn" id="playBtn">▶ 再生</button>
<button class="btn" id="stopBtn">■ 停止</button>
<div class="status" id="status">動画を選択してください</div>
</header>

<main class="main">
<section class="video-area">
<div class="video-wrap" id="videoWrap">
<video id="video" playsinline preload="metadata"></video>
<svg id="svg">
<defs>
<marker id="arrow" markerWidth="9" markerHeight="9" refX="8" refY="4.5" orient="auto"><path d="M0,0 L9,4.5 L0,9 Z" fill="#90a4ae"/></marker>
<marker id="arrowSel" markerWidth="9" markerHeight="9" refX="8" refY="4.5" orient="auto"><path d="M0,0 L9,4.5 L0,9 Z" fill="#4da3ff"/></marker>
</defs>
</svg>
<div id="overlay" class="overlay"></div>
<div id="hint" class="hint">動画を選択してください<br>読み込み後、動画上で右クリックすると要素を追加できます</div>
</div>
</section>

<section class="timeline">
<div class="timeline-toolbar">
<div class="time" id="time">00:00.000 / 00:00.000</div>
<div class="scale">時間軸
<input id="scale" type="range" min="0.5" max="4" step="0.1" value="1">
<span id="scaleText">1.0×</span>
</div>
</div>
<div class="timeline-scroll">
<div class="timeline-content" id="timelineContent">
<div class="axis" id="axis"></div>
<div class="rows">
<div class="row"><div class="row-label"><span class="dot" style="background:#4da3ff"></span>コメント</div><div class="lane" id="lane-comment"></div></div>
<div class="row"><div class="row-label"><span class="dot" style="background:#f04444"></span>強調枠</div><div class="lane" id="lane-highlight"></div></div>
<div class="row"><div class="row-label"><span class="dot" style="background:#54d68a"></span>拡大枠</div><div class="lane" id="lane-zoombox"></div></div>
<div class="row"><div class="row-label"><span class="dot" style="background:#ff9800"></span>スキップ</div><div class="lane" id="lane-skip"></div></div>
</div>
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
const video=$("video"),wrap=$("videoWrap"),overlay=$("overlay"),svg=$("svg"),menu=$("menu"),toast=$("toast"),axis=$("axis"),playhead=$("playhead"),timelineContent=$("timelineContent");

let duration=60,current=0,scale=1,selected=[],connections=[],nextId=5,drag=null,menuTarget=null,objectUrl=null,connectMode=null;

const colors={comment:"#4da3ff",highlight:"#f04444",zoombox:"#54d68a",skip:"#ff9800"};

const elements=[
{id:1,type:"comment",label:"コメント",start:5,end:18,x:18,y:15,w:190,h:55,text:"ここを確認してください"},
{id:2,type:"highlight",label:"強調枠",start:8,end:24,x:52,y:34,w:230,h:130,shape:"rect"},
{id:3,type:"zoombox",label:"拡大枠",start:20,end:35,x:20,y:58,w:180,h:100},
{id:4,type:"skip",label:"スキップ",start:38,end:45}
];

function fmt(t){
t=Math.max(0,Number(t)||0);
let m=Math.floor(t/60),s=t%60;
return String(m).padStart(2,"0")+":"+s.toFixed(3).padStart(6,"0");
}

function toastMsg(s){
toast.textContent=s;toast.style.display="block";
clearTimeout(toastMsg.timer);
toastMsg.timer=setTimeout(()=>toast.style.display="none",1600);
}

function timelineWidth(){
return timelineContent.clientWidth-110;
}

/* スケールはタイムライン幅を変更しない。
   時間目盛りの読みやすさだけを変更する。 */
function tickStep(){
let base=duration<=30?5:duration<=120?10:duration<=600?30:60;
return Math.max(1,base/scale);
}

function renderAxis(){
axis.innerHTML="";
let w=timelineWidth(),step=tickStep();

for(let t=0;t<=duration+0.0001;t+=step){
let d=document.createElement("div");
d.className="tick";
d.style.left=(t/duration*w)+"px";
d.textContent=fmt(t).slice(0,5);
axis.appendChild(d);
}

if(duration>0){
let d=document.createElement("div");
d.className="tick";
d.style.left=w+"px";
d.textContent=fmt(duration).slice(0,5);
axis.appendChild(d);
}
}

function updatePlayhead(){
let w=timelineWidth();
playhead.style.left=(110+current/duration*w)+"px";
$("time").textContent=fmt(current)+" / "+fmt(duration);
}

function setCurrentTime(t){
current=Math.max(0,Math.min(duration,Number(t)||0));
if(video.src)try{video.currentTime=current}catch(e){}
updatePlayhead();
renderOverlay();
}

function activeElements(){
return elements.filter(e=>current>=e.start&&current<=e.end);
}

function renderOverlay(){
overlay.innerHTML="";
activeElements().forEach(e=>{
if(e.type==="skip")return;

let d=document.createElement("div");
d.className="element "+e.type+(selected.includes(e.id)?" selected":"");
d.dataset.id=e.id;
d.style.left=e.x+"%";
d.style.top=e.y+"%";
d.style.width=e.w+"px";
d.style.height=e.h+"px";

if(e.type==="highlight")d.classList.add(e.shape||"rect");

let body=document.createElement("div");
body.className="element-body";

if(e.type==="comment")body.textContent=e.text;
if(e.type==="zoombox"){
body.textContent="拡大表示範囲";
body.style.color="#9ff0bd";
body.style.fontSize="12px";
}

d.appendChild(body);

["nw","ne","sw","se"].forEach(p=>{
let h=document.createElement("i");
h.className="handle "+p;
h.dataset.resize=p;
d.appendChild(h);
});

d.addEventListener("pointerdown",startElement);
d.addEventListener("click",e=>{e.stopPropagation();selectElement(e.currentTarget.dataset.id,e)});
d.addEventListener("contextmenu",e=>{
e.preventDefault();e.stopPropagation();
selectElement(d.dataset.id,e);
showElementMenu(e.clientX,e.clientY,Number(d.dataset.id));
});

overlay.appendChild(d);
});

renderConnections();
}

function selectElement(id,e){
id=Number(id);
if(connectMode){
if(connectMode!==id){
connections.push({id:nextId++,from:connectMode,to:id,fromPos:"r",toPos:"l",style:"solid",endArrow:false,selected:false});
connectMode=null;
toastMsg("接続線を作成しました");
renderOverlay();
}
return;
}

if(e&&(e.shiftKey||e.ctrlKey||e.metaKey)){
selected=selected.includes(id)?selected.filter(x=>x!==id):[...selected,id];
}else selected=[id];

connections.forEach(c=>c.selected=false);
renderOverlay();
renderTimeline();
}

function clearSelection(){
selected=[];
connections.forEach(c=>c.selected=false);
menu.style.display="none";
renderOverlay();
renderTimeline();
}

wrap.addEventListener("click",e=>{
if(!e.target.closest(".element")&&!e.target.closest(".connection-hit"))clearSelection();
});

function startElement(ev){
if(ev.button!==0)return;
let el=elements.find(x=>x.id===Number(ev.currentTarget.dataset.id));
if(!el)return;
ev.stopPropagation();

if(!selected.includes(el.id))selectElement(el.id,ev);

let resize=ev.target.dataset.resize||null;
let base={};
selected.forEach(id=>{
let x=elements.find(a=>a.id===id);
if(x)base[id]={x:x.x,y:x.y,w:x.w,h:x.h};
});

drag={el,resize,sx:ev.clientX,sy:ev.clientY,base};
addEventListener("pointermove",moveElement);
addEventListener("pointerup",endElement,{once:true});
}

function moveElement(ev){
if(!drag)return;
let r=wrap.getBoundingClientRect();
let dx=(ev.clientX-drag.sx)/r.width*100;
let dy=(ev.clientY-drag.sy)/r.height*100;

if(!drag.resize){
selected.forEach(id=>{
let e=elements.find(x=>x.id===id),b=drag.base[id];
if(!e||!b)return;
e.x=Math.max(0,Math.min(100-e.w/r.width*100,b.x+dx));
e.y=Math.max(0,Math.min(100-e.h/r.height*100,b.y+dy));
});
}else{
let e=drag.el,b=drag.base[e.id],p=drag.resize;
if(p.includes("e"))e.w=Math.max(45,b.w+(ev.clientX-drag.sx));
if(p.includes("s"))e.h=Math.max(28,b.h+(ev.clientY-drag.sy));
if(p.includes("w")){e.x=Math.max(0,b.x+dx);e.w=Math.max(45,b.w-(ev.clientX-drag.sx))}
if(p.includes("n")){e.y=Math.max(0,b.y+dy);e.h=Math.max(28,b.h-(ev.clientY-drag.sy))}
}
renderOverlay();
}

function endElement(){
drag=null;
removeEventListener("pointermove",moveElement);
renderTimeline();
}

function renderTimeline(){
["comment","highlight","zoombox","skip"].forEach(type=>{
let lane=$("lane-"+type);
lane.innerHTML="";

elements.filter(e=>e.type===type).forEach(e=>{
let bar=document.createElement("div");
bar.className="bar"+(selected.includes(e.id)?" selected":"");
bar.style.left=(e.start/duration*timelineWidth())+"px";
bar.style.width=Math.max(20,(e.end-e.start)/duration*timelineWidth())+"px";
bar.style.color=colors[type];
bar.style.background=colors[type]+"55";

let span=document.createElement("span");
span.textContent=e.label;
bar.appendChild(span);

let l=document.createElement("i"),r=document.createElement("i");
l.className="bar-handle left";r.className="bar-handle right";
bar.append(l,r);

bar.onclick=ev=>{ev.stopPropagation();selectElement(e.id,ev)};
bar.onpointerdown=ev=>{
if(ev.target.classList.contains("bar-handle"))return resizeTime(ev,e,ev.target.classList.contains("left"));
moveTime(ev,e);
};

lane.appendChild(bar);
});
});

updatePlayhead();
}

function moveTime(ev,e){
let sx=ev.clientX,start=e.start,len=e.end-e.start,w=timelineWidth();
function mv(x){
let delta=(x.clientX-sx)/w*duration;
let n=Math.max(0,Math.min(duration-len,start+delta));
e.start=n;e.end=n+len;
renderTimeline();renderOverlay();
}
function up(){removeEventListener("pointermove",mv);removeEventListener("pointerup",up)}
addEventListener("pointermove",mv);addEventListener("pointerup",up);
}

function resizeTime(ev,e,left){
let sx=ev.clientX,os=e.start,oe=e.end,w=timelineWidth();
function mv(x){
let d=(x.clientX-sx)/w*duration;
if(left)e.start=Math.max(0,Math.min(oe-.1,os+d));
else e.end=Math.min(duration,Math.max(os+.1,oe+d));
renderTimeline();renderOverlay();
}
function up(){removeEventListener("pointermove",mv);removeEventListener("pointerup",up)}
addEventListener("pointermove",mv);addEventListener("pointerup",up);
}

function point(e,pos){
let p={
t:[e.x+e.w/2,e.y],
r:[e.x+e.w,e.y+e.h/2],
b:[e.x+e.w/2,e.y+e.h],
l:[e.x,e.y+e.h/2]
};
return p[pos]||p.r;
}

function renderConnections(){
svg.querySelectorAll(".connection,.connection-hit").forEach(x=>x.remove());

connections.forEach(c=>{
let a=elements.find(e=>e.id===c.from),b=elements.find(e=>e.id===c.to);
if(!a||!b)return;

let p1=point(a,c.fromPos),p2=point(b,c.toPos),d;

if(c.style==="orthogonal"){
let mx=(p1[0]+p2[0])/2;
d=`M${p1[0]} ${p1[1]} L${mx} ${p1[1]} L${mx} ${p2[1]} L${p2[0]} ${p2[1]}`;
}else d=`M${p1[0]} ${p1[1]} L${p2[0]} ${p2[1]}`;

let hit=document.createElementNS("http://www.w3.org/2000/svg","path");
hit.setAttribute("class","connection-hit");
hit.setAttribute("d",d);
hit.onclick=e=>{e.stopPropagation();c.selected=true;selected=[];renderConnections()};
hit.oncontextmenu=e=>{e.preventDefault();e.stopPropagation();lineMenu(e.clientX,e.clientY,c)};
svg.appendChild(hit);

let line=document.createElementNS("http://www.w3.org/2000/svg","path");
line.setAttribute("class","connection"+(c.selected?" selected":""));
line.setAttribute("d",d);
if(c.style==="dashed")line.setAttribute("stroke-dasharray","8 6");
if(c.style==="dotted")line.setAttribute("stroke-dasharray","2 5");
if(c.style==="dashdot")line.setAttribute("stroke-dasharray","8 4 2 4");
if(c.endArrow)line.setAttribute("marker-end",c.selected?"url(#arrowSel)":"url(#arrow)");
svg.appendChild(line);
});
}

function openMenu(x,y,html){
menu.innerHTML=html;
menu.style.display="block";
menu.style.left=Math.min(x,innerWidth-230)+"px";
menu.style.top=Math.min(y,innerHeight-260)+"px";
}

function showElementMenu(x,y,id){
let e=elements.find(v=>v.id===id);
let h=`<div class="menu-title">${e.label}</div>`;

if(e.type==="comment")h+=`
<button data-a="text">コメントを変更</button>
<button data-a="font">文字サイズを変更</button>
<button data-a="bg">背景色を変更</button>`;

if(e.type==="highlight")h+=`
<button data-a="rect">直線</button>
<button data-a="round">角丸</button>
<button data-a="circle">円形</button>
<button data-a="fill">塗り潰し</button>`;

if(e.type==="zoombox")h+=`<button data-a="connect">接続線を作成</button>`;

h+=`<hr><button data-a="delete">削除</button>`;
menuTarget={type:"element",id};
openMenu(x,y,h);
}

function lineMenu(x,y,c){
menuTarget={type:"line",id:c.id};
openMenu(x,y,`
<div class="menu-title">接続線</div>
<select id="lineStyle">
<option value="solid">実線</option>
<option value="dotted">点線</option>
<option value="dashed">破線</option>
<option value="dashdot">一点鎖線</option>
</select>
<hr>
<button data-a="straight">直線</button>
<button data-a="orthogonal">折れ線</button>
<button data-a="arrow">終点矢印</button>
<button data-a="deleteLine">削除</button>
`);
setTimeout(()=>{
$("lineStyle").value=c.style;
$("lineStyle").onchange=e=>{c.style=e.target.value;renderConnections()};
},0);
}

menu.addEventListener("click",e=>{
let a=e.target.dataset.a;
if(!a||!menuTarget)return;

if(menuTarget.type==="element"){
let x=elements.find(v=>v.id===menuTarget.id);
if(a==="text"){let v=prompt("コメント",x.text);if(v!==null)x.text=v}
if(a==="font")x.fontSize=(x.fontSize||18)+2;
if(a==="bg")x.bg=x.bg?"":"#0008";
if(a==="rect"||a==="round"||a==="circle")x.shape=a;
if(a==="fill")x.fill=!x.fill;
if(a==="connect"){connectMode=x.id;toastMsg("接続先の要素をクリックしてください")}
if(a==="delete"){
let i=elements.findIndex(v=>v.id===x.id);
if(i>=0)elements.splice(i,1);
connections=connections.filter(c=>c.from!==x.id&&c.to!==x.id);
selected=[];
}
}else{
let c=connections.find(v=>v.id===menuTarget.id);
if(!c)return;
if(a==="straight")c.style="solid";
if(a==="orthogonal")c.style="orthogonal";
if(a==="arrow")c.endArrow=!c.endArrow;
if(a==="deleteLine")connections=connections.filter(v=>v.id!==c.id);
}
menu.style.display="none";
renderOverlay();
renderTimeline();
});

document.addEventListener("pointerdown",e=>{
if(!menu.contains(e.target))menu.style.display="none";
});

wrap.addEventListener("contextmenu",e=>{
if(e.target.closest(".element")||e.target.closest(".connection-hit"))return;
e.preventDefault();
menuTarget=null;
openMenu(e.clientX,e.clientY,`
<div class="menu-title">要素を追加</div>
<button data-add="comment">＋ コメント</button>
<button data-add="highlight">＋ 強調枠</button>
<button data-add="zoombox">＋ 拡大枠</button>
<button data-add="skip">＋ スキップ</button>
`);
menuTarget={type:"add",x:e.clientX,y:e.clientY};
});

menu.addEventListener("click",e=>{
let type=e.target.dataset.add;
if(!type)return;

let r=wrap.getBoundingClientRect();
let x=(menuTarget.x-r.left)/r.width*100;
let y=(menuTarget.y-r.top)/r.height*100;

let n={
id:nextId++,type,label:type==="comment"?"コメント":type==="highlight"?"強調枠":type==="zoombox"?"拡大枠":"スキップ",
start:current,end:Math.min(duration,current+5),
x:Math.max(0,Math.min(70,x)),y:Math.max(0,Math.min(70,y)),
w:type==="comment"?190:type==="highlight"?230:180,
h:type==="comment"?55:type==="highlight"?130:100,
text:"新しいコメント",shape:"rect"
};

elements.push(n);
selected=[n.id];
menu.style.display="none";
renderOverlay();
renderTimeline();
});

$("loadBtn").onclick=()=>$("fileInput").click();

$("fileInput").onchange=e=>{
let f=e.target.files[0];
if(!f)return;
if(objectUrl)URL.revokeObjectURL(objectUrl);
objectUrl=URL.createObjectURL(f);
video.src=objectUrl;
video.load();
$("status").textContent="動画を読み込み中...";
};

video.onloadedmetadata=()=>{
duration=Number.isFinite(video.duration)?video.duration:60;
current=0;
$("hint").style.display="none";
$("status").textContent="動画を読み込みました";
renderAxis();
renderTimeline();
renderOverlay();
};

video.ontimeupdate=()=>{
current=video.currentTime;
updatePlayhead();
renderOverlay();
};

video.onended=()=>{
current=duration;
$("playBtn").textContent="▶ 再生";
updatePlayhead();
renderOverlay();
};

$("playBtn").onclick=()=>{
if(!video.src)return toastMsg("先に動画を選択してください");
if(video.paused){
video.play();
$("playBtn").textContent="❚❚ 一時停止";
}else{
video.pause();
$("playBtn").textContent="▶ 再生";
}
};

$("stopBtn").onclick=()=>{
if(!video.src)return;
video.pause();
video.currentTime=0;
current=0;
$("playBtn").textContent="▶ 再生";
updatePlayhead();
renderOverlay();
};

$("scale").oninput=e=>{
scale=Number(e.target.value);
$("scaleText").textContent=scale.toFixed(1)+"×";

/* ここではタイムラインの幅を変更しない */
renderAxis();
renderTimeline();
};

timelineContent.onclick=e=>{
if(e.target.closest(".bar"))return;
let r=timelineContent.getBoundingClientRect();
let x=e.clientX-r.left-110;
if(x>=0&&x<=timelineWidth())setCurrentTime(x/timelineWidth()*duration);
};

window.onresize=()=>{
renderAxis();
renderTimeline();
renderOverlay();
};

document.onkeydown=e=>{
if(e.key==="Escape"){
connectMode=null;
menu.style.display="none";
}
if((e.key==="Delete"||e.key==="Backspace")&&selected.length){
selected.forEach(id=>{
let i=elements.findIndex(x=>x.id===id);
if(i>=0)elements.splice(i,1);
connections=connections.filter(c=>!selected.includes(c.from)&&!selected.includes(c.to));
});
selected=[];
renderOverlay();
renderTimeline();
}
};

renderAxis();
renderTimeline();
renderOverlay();
updatePlayhead();
</script>
</body>
</html>

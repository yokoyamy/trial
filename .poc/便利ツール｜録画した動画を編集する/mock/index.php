<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
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

.svg-layer{position:absolute;inset:0;width:100%;height:100%;overflow:visible;z-index:10}
.connection{
fill:none;
stroke:#ff5252;
stroke-width:1.2;
vector-effect:non-scaling-stroke;
pointer-events:stroke;
cursor:pointer
}
.connection.selected{
stroke:#4da3ff;
stroke-width:2.2
}
.connection-handle{
fill:#fff;
stroke:#4da3ff;
stroke-width:1.5;
vector-effect:non-scaling-stroke;
cursor:crosshair;
pointer-events:auto
}

.overlay{position:absolute;inset:0;z-index:20;pointer-events:none}
.element{
position:absolute;
pointer-events:auto;
user-select:none;
touch-action:none;
min-width:40px;
min-height:25px;
cursor:move
}
.element.selected{
outline:2px solid #4da3ff;
outline-offset:3px
}
.element.multi{
outline:2px solid #ffc107;
outline-offset:3px
}
.element-body{
width:100%;
height:100%;
display:flex;
align-items:center;
justify-content:center;
overflow:hidden
}
.comment .element-body{
color:#fff;
text-shadow:0 1px 4px #000;
font-size:18px;
padding:4px
}
.highlight .element-body{
border:3px solid #f04444;
background:transparent
}
.highlight.round .element-body{border-radius:18px}
.highlight.circle .element-body{border-radius:50%}
.zoombox .element-body{
border:3px solid #111;
background:#1113
}
.skip .element-body{
border:2px dashed #ff9800;
background:#ff980033;
color:#ffb74d;
font-size:12px
}
.handle{
position:absolute;
width:9px;
height:9px;
background:#fff;
border:2px solid #4da3ff;
z-index:5
}
.nw{left:-5px;top:-5px;cursor:nwse-resize}
.ne{right:-5px;top:-5px;cursor:nesw-resize}
.sw{left:-5px;bottom:-5px;cursor:nesw-resize}
.se{right:-5px;bottom:-5px;cursor:nwse-resize}

.timeline{
height:280px;
flex:none;
background:#11161d;
border-top:1px solid #30363d;
display:flex;
flex-direction:column
}
.timeline-toolbar{
height:42px;
flex:none;
display:flex;
align-items:center;
gap:8px;
padding:5px 9px;
border-bottom:1px solid #30363d
}
.time{
font-variant-numeric:tabular-nums;
min-width:150px;
color:#dbeafe
}
.scale{
margin-left:auto;
display:flex;
align-items:center;
gap:7px;
color:#8b949e;
font-size:12px
}
.scale input{width:110px}

.timeline-scroll{
min-height:0;
flex:1;
overflow:auto
}
.timeline-content{
position:relative;
min-width:700px;
height:100%
}
.axis{
height:30px;
position:relative;
margin-left:100px;
background:#171d25;
border-bottom:1px solid #30363d
}
.tick{
position:absolute;
top:0;
height:100%;
border-left:1px solid #343b45;
color:#7d8590;
font-size:10px;
padding:4px 0 0 3px
}

.rows{
margin-left:100px;
padding-bottom:12px
}
.row{
position:relative;
min-height:52px;
border-bottom:1px solid #242b33
}
.row-label{
position:absolute;
right:100%;
top:0;
width:100px;
height:100%;
display:flex;
align-items:center;
padding:0 8px;
gap:5px;
background:#161b22;
border-right:1px solid #30363d;
font-size:11px
}
.dot{
width:8px;
height:8px;
border-radius:50%
}
.lane{
position:relative;
height:52px
}
.bar{
position:absolute;
top:9px;
height:34px;
border-radius:5px;
border:1px solid currentColor;
display:flex;
align-items:center;
cursor:grab;
user-select:none;
touch-action:none;
overflow:visible
}
.bar.selected{box-shadow:0 0 0 2px #4da3ff}
.bar.multi{box-shadow:0 0 0 2px #ffc107}
.bar span{
font-size:10px;
padding:0 7px;
white-space:nowrap;
overflow:hidden;
text-overflow:ellipsis
}
.bar-handle{
position:absolute;
top:0;
height:100%;
width:8px;
cursor:ew-resize
}
.bar-handle.left{left:-4px}
.bar-handle.right{right:-4px}

.playhead{
position:absolute;
top:30px;
bottom:0;
width:2px;
background:#f04444;
z-index:100;
pointer-events:none
}
.playhead:before{
content:"";
position:absolute;
top:-2px;
left:-5px;
width:12px;
height:12px;
background:#f04444;
clip-path:polygon(0 0,100% 0,50% 100%)
}

.menu{
position:fixed;
z-index:1000;
display:none;
min-width:220px;
background:#1c2531;
border:1px solid #46515f;
border-radius:7px;
box-shadow:0 12px 30px #0009;
padding:5px
}
.menu-title{
font-size:11px;
color:#8b949e;
padding:6px 8px
}
.menu button{
display:block;
width:100%;
text-align:left;
border:0;
background:transparent;
color:#e8edf5;
padding:8px;
border-radius:4px
}
.menu button:hover{background:#2b3745}
.menu hr{
border:0;
border-top:1px solid #303b48;
margin:4px 0
}
.menu select{
width:100%;
background:#242c36;
color:#fff;
border:1px solid #46515f;
padding:6px;
border-radius:4px
}
.hint{
position:absolute;
left:50%;
top:50%;
transform:translate(-50%,-50%);
color:#66717f;
text-align:center;
pointer-events:none
}
.toast{
position:fixed;
right:15px;
bottom:15px;
background:#202938;
border:1px solid #46515f;
border-radius:6px;
padding:9px 13px;
display:none;
z-index:2000
}
@media(max-width:800px){
.title{display:none}
.timeline{height:240px}
}
</style>
</head>

<body>
<div class="app">

<header class="header">
<div class="title">動画編集・注釈</div>
<button class="btn primary" id="loadBtn">動画を読み込む</button>
<input id="fileInput" type="file" accept="video/*" hidden>
<div class="status" id="status">動画を読み込んでください</div>
</header>

<main class="main">

<section class="video-area">
<div class="video-wrap" id="videoWrap">

<video id="video" playsinline preload="metadata"></video>

<svg id="svg" class="svg-layer"></svg>

<div id="overlay" class="overlay"></div>

<div id="hint" class="hint">
動画を読み込んでください<br>
読み込み後、動画上で右クリックすると要素を追加できます
</div>

</div>
</section>

<section class="timeline">

<div class="timeline-toolbar">
<button class="btn" id="playBtn">▶</button>
<button class="btn" id="stopBtn">■</button>
<div class="time" id="time">00:00.000 / 00:00.000</div>

<div class="scale">
時間スケール
<input id="scale" type="range" min="1" max="10" step=".1" value="1">
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

const video=$("video");
const wrap=$("videoWrap");
const overlay=$("overlay");
const svg=$("svg");
const menu=$("menu");
const toast=$("toast");
const rows=$("rows");
const axis=$("axis");
const content=$("timelineContent");
const playhead=$("playhead");

let duration=60;
let scale=1;
let current=0;

let selected=[];
let connections=[];
let drag=null;
let menuTarget=null;
let nextId=5;

const elements=[
{
id:1,
type:"comment",
label:"コメント",
start:5,
end:18,
x:18,
y:15,
w:190,
h:55,
text:"ここを確認してください"
},
{
id:2,
type:"highlight",
label:"強調枠",
start:8,
end:24,
x:52,
y:34,
w:230,
h:130,
shape:"rect"
},
{
id:3,
type:"zoombox",
label:"拡大枠",
start:20,
end:35,
x:20,
y:58,
w:180,
h:100,
shape:"rect"
},
{
id:4,
type:"skip",
label:"スキップ",
start:38,
end:45,
x:0,
y:0,
w:0,
h:0
}
];

const colors={
comment:"#4da3ff",
highlight:"#f04444",
zoombox:"#111",
skip:"#ff9800"
};

function toastMsg(s){
toast.textContent=s;
toast.style.display="block";
clearTimeout(toastMsg.t);
toastMsg.t=setTimeout(()=>{
toast.style.display="none";
},1800);
}

function fmt(t){
t=Math.max(0,t||0);
const m=Math.floor(t/60);
const s=t%60;

return String(m).padStart(2,"0")+":"+s.toFixed(3).padStart(6,"0");
}

function activeElements(){
return elements.filter(e=>
current>=e.start &&
current<=e.end
);
}

function videoPosition(e){
return{
left:e.x+"%",
top:e.y+"%",
width:e.w+"%",
height:e.h+"%"
};
}

function renderOverlay(){

overlay.innerHTML="";

activeElements().forEach(e=>{

if(e.type==="skip")return;

const d=document.createElement("div");

d.className=
"element "+
e.type+
(selected.includes(e.id)?" selected":"")+
(selected.length>1&&selected.includes(e.id)?" multi":"");

d.dataset.id=e.id;

Object.assign(
d.style,
videoPosition(e)
);

if(e.type==="highlight"){
d.classList.add(e.shape||"rect");
}

const body=document.createElement("div");
body.className="element-body";

if(e.type==="comment"){
body.textContent=e.text;
}

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
selectElement(Number(d.dataset.id),ev);
});

/*
 * 重要:
 * 右クリックでは単一選択へ戻さない。
 * すでに複数選択されている場合はその状態を維持する。
 */
d.addEventListener("contextmenu",ev=>{

ev.preventDefault();
ev.stopPropagation();

const id=Number(d.dataset.id);

if(!selected.includes(id)){
selectElement(id,ev);
}

showElementMenu(
ev.clientX,
ev.clientY,
id
);
});

overlay.appendChild(d);

});

renderConnections();
}

function selectElement(id,event){

const multi=
event &&
(event.shiftKey||event.ctrlKey||event.metaKey);

if(multi){

if(selected.includes(id)){
selected=selected.filter(x=>x!==id);
}else{
selected=[...selected,id];
}

}else{

selected=[id];

}

renderOverlay();
renderRows();
}

function clearSelection(){

selected=[];

menu.style.display="none";

renderOverlay();
renderRows();
renderConnections();
}

function startElement(ev){

if(ev.button!==0)return;

const el=elements.find(
x=>x.id===Number(ev.currentTarget.dataset.id)
);

if(!el)return;

ev.stopPropagation();

const resize=
ev.target.dataset.resize||null;

if(resize){

drag={
kind:"resize",
el,
resize,
x:ev.clientX,
y:ev.clientY,
ox:el.x,
oy:el.y,
ow:el.w,
oh:el.h
};

}else{

if(!selected.includes(el.id)){
selectElement(el.id,ev);
}

drag={
kind:"move",
el,
x:ev.clientX,
y:ev.clientY,
selected:[...selected],
base:elements
.filter(x=>selected.includes(x.id))
.map(x=>({
id:x.id,
x:x.x,
y:x.y
}))
};

}

window.addEventListener(
"pointermove",
moveElement
);

window.addEventListener(
"pointerup",
endDrag,
{once:true}
);
}

function moveElement(ev){

if(!drag)return;

const r=wrap.getBoundingClientRect();

const dx=
(ev.clientX-drag.x)/
r.width*100;

const dy=
(ev.clientY-drag.y)/
r.height*100;

if(drag.kind==="move"){

drag.base.forEach(b=>{

const e=elements.find(x=>x.id===b.id);

if(!e)return;

e.x=Math.max(
0,
Math.min(
100-e.w,
b.x+dx
)
);

e.y=Math.max(
0,
Math.min(
100-e.h,
b.y+dy
)
);

});

}else{

const e=drag.el;

let x=drag.ox;
let y=drag.oy;
let w=drag.ow;
let h=drag.oh;

if(drag.resize.includes("e")){
w=Math.max(5,drag.ow+dx);
}

if(drag.resize.includes("s")){
h=Math.max(5,drag.oh+dy);
}

if(drag.resize.includes("w")){
x=drag.ox+dx;
w=drag.ow-dx;
}

if(drag.resize.includes("n")){
y=drag.oy+dy;
h=drag.oh-dy;
}

e.x=Math.max(
0,
Math.min(
95,
x
)
);

e.y=Math.max(
0,
Math.min(
95,
y
)
);

e.w=Math.max(
5,
Math.min(
100-e.x,
w
)
);

e.h=Math.max(
5,
Math.min(
100-e.y,
h
)
);

}

renderOverlay();
}

function endDrag(){

drag=null;

window.removeEventListener(
"pointermove",
moveElement
);

}

function connectionPoint(e,pos){

const x=e.x;
const y=e.y;
const w=e.w;
const h=e.h;

const map={
tl:[x,y],
t:[x+w/2,y],
tr:[x+w,y],
l:[x,y+h/2],
r:[x+w,y+h/2],
bl:[x,y+h],
b:[x+w/2,y+h],
br:[x+w,y+h]
};

return map[pos]||map.r;
}

function renderConnections(){

svg.innerHTML="";

svg.setAttribute(
"viewBox",
"0 0 100 100"
);

svg.setAttribute(
"preserveAspectRatio",
"none"
);

connections.forEach(c=>{

const a=elements.find(
e=>e.id===c.from
);

const b=elements.find(
e=>e.id===c.to
);

if(!a||!b)return;

const p1=connectionPoint(
a,
c.fromPos
);

const p2=connectionPoint(
b,
c.toPos
);

let d="";

if(c.style==="orthogonal"){

const mx=
(p1[0]+p2[0])/2;

d=
`M ${p1[0]} ${p1[1]}
 L ${mx} ${p1[1]}
 L ${mx} ${p2[1]}
 L ${p2[0]} ${p2[1]}`;

}else if(c.style==="wave"){

const dx=p2[0]-p1[0];
const dy=p2[1]-p1[1];

const len=
Math.sqrt(dx*dx+dy*dy)||1;

const nx=-dy/len*1.8;
const ny=dx/len*1.8;

d=
`M ${p1[0]} ${p1[1]}`;

for(let i=1;i<=12;i++){

const t=i/12;

d+=
` L ${
p1[0]+dx*t+nx*Math.sin(t*Math.PI*12)
} ${
p1[1]+dy*t+ny*Math.sin(t*Math.PI*12)
}`;

}

}else{

d=
`M ${p1[0]} ${p1[1]}
 L ${p2[0]} ${p2[1]}`;

}

const path=
document.createElementNS(
"http://www.w3.org/2000/svg",
"path"
);

path.setAttribute(
"class",
"connection"+
(c.id===selected[0]&&selected.length===1
?" selected":"")
);

path.setAttribute(
"d",
d
);

path.dataset.cid=c.id;

if(c.style==="dashed"){
path.setAttribute(
"stroke-dasharray",
"8 6"
);
}

path.addEventListener("click",ev=>{

ev.stopPropagation();

selected=[c.id];

renderConnections();
renderRows();

});

path.addEventListener(
"contextmenu",
ev=>{

ev.preventDefault();
ev.stopPropagation();

selected=[c.id];

renderConnections();
renderRows();

showConnectionMenu(
ev.clientX,
ev.clientY,
c.id
);

});

svg.appendChild(path);

if(
c.id===selected[0] &&
selected.length===1
){

[p1,p2].forEach((p,i)=>{

const h=
document.createElementNS(
"http://www.w3.org/2000/svg",
"circle"
);

h.setAttribute(
"cx",
p[0]
);

h.setAttribute(
"cy",
p[1]
);

h.setAttribute(
"r",
"2"
);

h.setAttribute(
"class",
"connection-handle"
);

h.dataset.end=
i?"to":"from";

h.dataset.cid=c.id;

h.addEventListener(
"pointerdown",
startConnectionHandle
);

svg.appendChild(h);

});

}

});

}

function startConnectionHandle(ev){

ev.stopPropagation();

const cid=
Number(ev.currentTarget.dataset.cid);

const end=
ev.currentTarget.dataset.end;

const c=
connections.find(x=>x.id===cid);

if(!c)return;

drag={
kind:"connection",
c,
end
};

window.addEventListener(
"pointermove",
moveConnection
);

window.addEventListener(
"pointerup",
endConnection,
{once:true}
);

}

function moveConnection(ev){

if(!drag)return;

const r=
wrap.getBoundingClientRect();

const x=
(ev.clientX-r.left)/
r.width*100;

const y=
(ev.clientY-r.top)/
r.height*100;

const target=
elements
.filter(e=>e.type!=="skip")
.find(e=>
x>=e.x &&
x<=e.x+e.w &&
y>=e.y &&
y<=e.y+e.h
);

if(!target)return;

const relx=
(x-target.x)/target.w;

const rely=
(y-target.y)/target.h;

let pos;

if(rely<.25){
pos=
relx<.33
?"tl"
:relx>.66
?"tr"
:"t";
}else if(rely>.75){
pos=
relx<.33
?"bl"
:relx>.66
?"br"
:"b";
}else{
pos=relx<.5?"l":"r";
}

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

window.removeEventListener(
"pointermove",
moveConnection
);

}

function createConnection(){

if(selected.length!==2){

toastMsg(
"接続する要素を2つ選択してください"
);

return;
}

const from=selected[0];
const to=selected[1];

if(from===to){

toastMsg(
"異なる2つの要素を選択してください"
);

return;
}

const exists=
connections.some(c=>
(c.from===from&&c.to===to)||
(c.from===to&&c.to===from)
);

if(exists){

toastMsg(
"この2つの要素はすでに接続されています"
);

return;
}

connections.push({

id:nextId++,

from,
to,

fromPos:"r",
toPos:"l",

style:"solid",

startMarker:"none",
endMarker:"none"

});

selected=[];

renderOverlay();
renderRows();
renderConnections();

toastMsg(
"接続線を作成しました"
);
}

function showElementMenu(x,y,id){

const e=
elements.find(v=>v.id===id);

if(!e)return;

menuTarget={
type:"element",
id
};

let html=
`<div class="menu-title">${e.label} の操作</div>`;

if(e.type==="highlight"){

html+=`
<button data-action="shapeRect">四角形</button>
<button data-action="shapeRound">角丸</button>
<button data-action="shapeCircle">円形</button>
`;

}

if(e.type==="comment"){

html+=
`<button data-action="editComment">コメントを変更</button>`;

}

if(selected.length===2){

html+=`
<hr>
<button data-action="connect">
選択した2つの要素を接続
</button>
`;

}

html+=`
<hr>
<button data-action="delete">削除</button>
`;

openMenu(x,y,html);

}

function showConnectionMenu(x,y,id){

const c=
connections.find(v=>v.id===id);

if(!c)return;

menuTarget={
type:"connection",
id
};

const html=`
<div class="menu-title">接続線の操作</div>

<select id="lineStyle">
<option value="solid" ${c.style==="solid"?"selected":""}>直線</option>
<option value="orthogonal" ${c.style==="orthogonal"?"selected":""}>折れ線</option>
<option value="wave" ${c.style==="wave"?"selected":""}>波線</option>
<option value="dashed" ${c.style==="dashed"?"selected":""}>破線</option>
</select>

<hr>

<button data-action="deleteConnection">
接続線を削除
</button>
`;

openMenu(x,y,html);

setTimeout(()=>{

const s=$("lineStyle");

if(s){

s.onchange=()=>{

c.style=s.value;

renderConnections();

};

}

},0);

}

function openMenu(x,y,html){

menu.innerHTML=html;

menu.style.display="block";

menu.style.left=
Math.min(
x,
innerWidth-235
)+"px";

menu.style.top=
Math.min(
y,
innerHeight-270
)+"px";

}

menu.addEventListener("click",ev=>{

const action=
ev.target.dataset.action;

if(!action||!menuTarget)return;

if(menuTarget.type==="element"){

const e=
elements.find(
v=>v.id===menuTarget.id
);

if(!e)return;

if(action==="shapeRect"){
e.shape="rect";
}

if(action==="shapeRound"){
e.shape="round";
}

if(action==="shapeCircle"){
e.shape="circle";
}

if(action==="editComment"){

const v=
prompt(
"コメント",
e.text
);

if(v!==null){
e.text=v;
}

}

if(action==="connect"){

createConnection();

}

if(action==="delete"){

const i=
elements.findIndex(
v=>v.id===e.id
);

if(i>=0){
elements.splice(i,1);
}

connections=
connections.filter(c=>
c.from!==e.id &&
c.to!==e.id
);

selected=[];

}

renderOverlay();
renderRows();
renderConnections();

}

if(menuTarget.type==="connection"){

const c=
connections.find(
v=>v.id===menuTarget.id
);

if(!c)return;

if(action==="deleteConnection"){

connections=
connections.filter(
v=>v.id!==c.id
);

selected=[];

renderConnections();
renderRows();

}

}

menu.style.display="none";

});

document.addEventListener(
"pointerdown",
ev=>{

if(!menu.contains(ev.target)){
menu.style.display="none";
}

}
);

function addElement(type,x,y){

const r=
wrap.getBoundingClientRect();

const px=
(x-r.left)/r.width*100;

const py=
(y-r.top)/r.height*100;

const e={
id:nextId++,
type,
label:
type==="comment"
?"コメント":
type==="highlight"
?"強調枠":
type==="zoombox"
?"拡大枠":
"スキップ",

start:Math.max(
0,
current-1
),

end:Math.min(
duration,
current+8
),

x:Math.max(
0,
Math.min(
85,
px-10
)
),

y:Math.max(
0,
Math.min(
80,
py-8
)
),

w:type==="comment"
?180
:220,

h:type==="comment"
?50
:110,

text:
type==="comment"
?"新しいコメント"
:"",

shape:"rect"
};

elements.push(e);

selected=[e.id];

renderOverlay();
renderRows();

}

wrap.addEventListener(
"contextmenu",
ev=>{

ev.preventDefault();

if(
ev.target.closest(".element")||
ev.target.closest(".connection")
){
return;
}

const x=ev.clientX;
const y=ev.clientY;

const html=`
<div class="menu-title">要素を追加</div>
<button data-add="comment">コメント</button>
<button data-add="highlight">強調枠</button>
<button data-add="zoombox">拡大枠</button>
<button data-add="skip">スキップ</button>
`;

openMenu(x,y,html);

menuTarget={
type:"add",
x,
y
};

});

menu.addEventListener(
"click",
ev=>{

const type=
ev.target.dataset.add;

if(!type||!menuTarget)return;

if(menuTarget.type!=="add")return;

addElement(
type,
menuTarget.x,
menuTarget.y
);

menu.style.display="none";

}
);

wrap.addEventListener(
"pointerdown",
ev=>{

if(
ev.button===0 &&
!ev.target.closest(".element") &&
!ev.target.closest(".connection")
){
clearSelection();
}

}
);

function renderAxis(){

const width=
Math.max(
700,
Math.round(1000*scale)
);

content.style.width=
width+"px";

axis.innerHTML="";

for(let i=0;i<=10;i++){

const t=
duration*i/10;

const tick=
document.createElement("div");

tick.className="tick";

tick.style.left=
(i*10)+"%";

tick.textContent=
fmt(t);

axis.appendChild(tick);

}

}

/*
 * タイムラインの重なりを自動でレーン分けする。
 *
 * 同じ種類の要素で時間が重なった場合、
 * 1段目、2段目、3段目……と縦に並べる。
 *
 * 種類をまたいでも同じ時間帯なら同じ行の中で
 * 空いているレーンを使うため、重なって表示されない。
 */
function buildTimelineLanes(items){

const sorted=
[...items].sort((a,b)=>
a.start-b.start ||
a.end-b.end ||
a.id-b.id
);

const lanes=[];

sorted.forEach(e=>{

let laneIndex=0;

while(true){

if(!lanes[laneIndex]){
lanes[laneIndex]=[];
}

const conflict=
lanes[laneIndex].some(other=>
e.start<other.end &&
e.end>other.start
);

if(!conflict){
lanes[laneIndex].push(e);
e._lane=laneIndex;
break;
}

laneIndex++;

}

});

return lanes.length;
}

function renderRows(){

rows.innerHTML="";

const defs=[
["comment","コメント","#4da3ff"],
["highlight","強調枠","#f04444"],
["zoombox","拡大枠","#111"],
["skip","スキップ","#ff9800"]
];

defs.forEach(
([type,label,color])=>{

const items=
elements.filter(
e=>e.type===type
);

/*
 * 種類ごとに時間帯が重なる要素を
 * 縦方向へ自動配置する。
 */
const laneCount=
buildTimelineLanes(items);

const row=
document.createElement("div");

row.className="row";

row.style.height=
Math.max(
52,
laneCount*52
)+"px";

const labelEl=
document.createElement("div");

labelEl.className="row-label";

labelEl.innerHTML=
`<span class="dot" style="background:${color}"></span>${label}`;

row.appendChild(labelEl);

const laneContainer=
document.createElement("div");

laneContainer.className="lane";

laneContainer.style.height=
Math.max(
52,
laneCount*52
)+"px";

items.forEach(e=>{

const bar=
document.createElement("div");

bar.className=
"bar"+
(selected.includes(e.id)
?" selected":"")+
(
selected.length>1 &&
selected.includes(e.id)
?" multi":""
);

bar.style.color=
colors[e.type];

bar.style.left=
(e.start/duration*100)+"%";

bar.style.width=
((e.end-e.start)/duration*100)+"%";

bar.style.top=
(9+e._lane*52)+"px";

bar.innerHTML=
`<span>${e.label}</span>
<i class="bar-handle left"></i>
<i class="bar-handle right"></i>`;

bar.dataset.id=e.id;

bar.addEventListener(
"pointerdown",
ev=>{

if(ev.button!==0)return;

ev.stopPropagation();

const resize=
ev.target.classList.contains(
"bar-handle"
);

drag={
kind:resize
?"timelineResize"
:"timelineMove",

el:e,

x:ev.clientX,

start:e.start,
end:e.end,

side:
ev.target.classList.contains("left")
?"left"
:"right"
};

selectElement(e.id,ev);

window.addEventListener(
"pointermove",
timelineDrag
);

window.addEventListener(
"pointerup",
endTimelineDrag,
{once:true}
);

});

/*
 * タイムライン上の右クリックでも
 * 動画上と同じ操作メニューを使用する。
 */
bar.addEventListener(
"contextmenu",
ev=>{

ev.preventDefault();
ev.stopPropagation();

if(!selected.includes(e.id)){
selectElement(e.id,ev);
}

showElementMenu(
ev.clientX,
ev.clientY,
e.id
);

});

laneContainer.appendChild(bar);

});

row.appendChild(laneContainer);

rows.appendChild(row);

});

}

function timelineDrag(ev){

if(!drag)return;

const lane=
rows.querySelector(".lane");

if(!lane)return;

const r=
lane.getBoundingClientRect();

const dt=
(ev.clientX-drag.x)/
r.width*
duration;

if(drag.kind==="timelineMove"){

const len=
drag.end-drag.start;

drag.el.start=
Math.max(
0,
Math.min(
duration-len,
drag.start+dt
)
);

drag.el.end=
drag.el.start+len;

}else{

if(drag.side==="left"){

drag.el.start=
Math.max(
0,
Math.min(
drag.end-.2,
drag.start+dt
)
);

}else{

drag.el.end=
Math.min(
duration,
Math.max(
drag.start+.2,
drag.end+dt
)
);

}

}

renderRows();
renderOverlay();

}

function endTimelineDrag(){

drag=null;

window.removeEventListener(
"pointermove",
timelineDrag
);

}

function updateTime(){

current=
Math.max(
0,
Math.min(
duration,
video.currentTime||0
)
);

$("time").textContent=
fmt(current)+
" / "+
fmt(duration);

const pct=
duration
?current/duration
:0;

playhead.style.left=
"calc(100px + "+
(pct*100)+
"%)";

renderOverlay();

const skip=
elements.find(e=>
e.type==="skip" &&
current>=e.start &&
current<e.end
);

if(skip&&!video.paused){
video.currentTime=
skip.end;
}

}

video.addEventListener(
"loadedmetadata",
()=>{

duration=
video.duration||60;

$("status").textContent=
"動画編集中";

$("hint").style.display=
"none";

renderAxis();
renderRows();
updateTime();

}
);

video.addEventListener(
"timeupdate",
updateTime
);

video.addEventListener(
"ended",
()=>{
$("playBtn").textContent="▶";
}
);

$("loadBtn").onclick=()=>
$("fileInput").click();

$("fileInput").onchange=
ev=>{

const file=
ev.target.files[0];

if(!file)return;

video.src=
URL.createObjectURL(file);

video.load();

};

$("playBtn").onclick=()=>{

if(!video.src){

toastMsg(
"先に動画を読み込んでください"
);

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

$("playBtn").textContent="▶";

};

$("scale").oninput=
ev=>{

scale=
Number(ev.target.value);

$("scaleText").textContent=
scale.toFixed(1)+"×";

renderAxis();
renderRows();
updateTime();

};

axis.addEventListener(
"pointerdown",
ev=>{

const r=
axis.getBoundingClientRect();

const ratio=
Math.max(
0,
Math.min(
1,
(ev.clientX-r.left)/r.width
)
);

video.currentTime=
ratio*duration;

}
);

rows.addEventListener(
"dblclick",
ev=>{

const lane=
ev.target.closest(".lane");

if(!lane)return;

const r=
lane.getBoundingClientRect();

video.currentTime=
Math.max(
0,
Math.min(
duration,
(ev.clientX-r.left)/
r.width*
duration
)
);

}
);

document.addEventListener(
"keydown",
ev=>{

if(ev.key==="Escape"){

selected=[];

renderOverlay();
renderRows();
renderConnections();

menu.style.display="none";

}

});

renderAxis();
renderRows();
renderOverlay();
updateTime();

</script>
</body>
</html>

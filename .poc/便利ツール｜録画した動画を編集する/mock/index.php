<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}html,body{margin:0;height:100%;overflow:hidden;background:#11161d;color:#eee;font-family:Arial,"Noto Sans JP",sans-serif}
button,input{font:inherit}button{border:1px solid #46515f;background:#242c36;color:#eee;border-radius:5px;padding:6px 10px;cursor:pointer}
button:hover{background:#303b48}.primary{background:#1769aa}.app{height:100%;display:flex;flex-direction:column}
header{height:52px;display:flex;align-items:center;gap:7px;padding:7px 10px;background:#161b22;border-bottom:1px solid #30363d}
.status{margin-left:auto;color:#9aa5b1;font-size:12px}.main{flex:1;min-height:0;display:flex;flex-direction:column}
.video{flex:1;min-height:0;background:#05070a;padding:10px}.screen{height:100%;position:relative;background:#000;overflow:hidden}
video{width:100%;height:100%;object-fit:contain}.layer{position:absolute;inset:0;z-index:2}
.el{position:absolute;cursor:move;user-select:none;min-width:40px;min-height:25px}.el.sel{outline:2px solid #4da3ff;outline-offset:3px}
.comment{padding:7px 10px;color:#fff;background:#0009}.highlight{border:4px solid #f04444}.highlight.round{border-radius:18px}.highlight.circle{border-radius:50%}
.zoom{border:3px solid #54d68a;background:#54d68a18}
.handle{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #4da3ff}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.ne{right:-5px;top:-5px;cursor:nesw-resize}.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.se{right:-5px;bottom:-5px;cursor:nwse-resize}
svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:1}.linehit{pointer-events:stroke;stroke:transparent;stroke-width:18;fill:none}.line{fill:none;stroke:#90a4ae;stroke-width:2}.line.sel{stroke:#4da3ff;stroke-width:3}
.hint{position:absolute;inset:0;display:grid;place-items:center;color:#657080;text-align:center;pointer-events:none;z-index:3}
.timeline{height:270px;flex:none;border-top:1px solid #30363d;background:#11161d}
.tools{height:42px;display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid #30363d}
.time{min-width:165px}.scale{margin-left:auto;color:#8b949e;font-size:12px}.scale input{width:120px}
.scroll{height:calc(100% - 42px);overflow:hidden;position:relative}.tl{position:relative;height:100%;width:100%}
.axis{height:30px;margin-left:95px;margin-right:15px;position:relative;border-bottom:1px solid #30363d}
.tick{position:absolute;height:100%;border-left:1px solid #343b45;color:#7d8590;font-size:10px;padding-left:3px;white-space:nowrap}
.tick.end{border-left:none;border-right:2px solid #f0b000;padding:0 3px 0 0;transform:translateX(-100%);color:#f0b000}
.rows{margin-left:95px;margin-right:15px}.row{height:45px;position:relative;border-bottom:1px solid #242b33;overflow:hidden}
.label{position:absolute;right:100%;width:95px;height:45px;display:flex;align-items:center;padding-left:7px;background:#161b22;font-size:11px}
.lane{position:absolute;inset:0}
.bar{position:absolute;top:7px;height:31px;border:1px solid;border-radius:5px;display:flex;align-items:center;padding:0 7px;font-size:10px;cursor:grab;overflow:hidden;white-space:nowrap}.bar.sel{box-shadow:0 0 0 2px #4da3ff}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#f04444;z-index:20;cursor:ew-resize}
.playhead::before{content:"";position:absolute;left:-7px;top:0;width:16px;height:16px;background:#f04444;border-radius:3px 3px 8px 8px}
.playhead::after{content:"";position:absolute;left:-8px;right:-8px;top:0;bottom:0}
.menu{position:fixed;display:none;z-index:100;background:#1c2531;border:1px solid #46515f;border-radius:6px;padding:5px;min-width:210px;box-shadow:0 10px 30px #0009}.menu button{display:block;width:100%;text-align:left;margin:2px 0}
.toast{position:fixed;right:12px;bottom:12px;background:#202938;border:1px solid #46515f;padding:8px 12px;border-radius:5px;display:none;z-index:200}
</style>
</head>
<body>
<div class="app">
<header>
<b>録画動画編集</b>
<button class="primary" id="pick">動画を選択</button>
<input id="file" type="file" accept="video/*" hidden>
<span class="status" id="status">動画を選択してください</span>
</header>

<div class="main">
<div class="video"><div class="screen" id="screen">
<video id="video" playsinline></video>
<svg id="svg"><defs><marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0 0L8 4L0 8Z" fill="#90a4ae"/></marker></defs></svg>
<div class="layer" id="layer"></div>
<div class="hint" id="hint">動画を選択してください<br>動画上で右クリックすると要素を追加できます</div>
</div></div>

<div class="timeline">
<div class="tools">
<button id="play">▶ 再生</button><button id="stop">■ 停止</button>
<span class="time" id="time">00:00.000 / 00:00.000</span>
<span class="scale">時間軸 <input id="scale" type="range" min=".5" max="4" step=".1" value="1"> <span id="scaleText">1.0×</span></span></div>
<div class="scroll" id="scroll"><div class="tl" id="tl">
<div class="axis" id="axis"></div>
<div class="rows">
<div class="row"><div class="label">● コメント</div><div class="lane" id="comment"></div></div>
<div class="row"><div class="label">● 強調枠</div><div class="lane" id="highlight"></div></div>
<div class="row"><div class="label">● 拡大枠</div><div class="lane" id="zoom"></div></div>
<div class="row"><div class="label">● スキップ</div><div class="lane" id="skip"></div></div>
</div>
<div class="playhead" id="head"></div>
</div></div>
</div>
</div></div>

<div class="menu" id="menu"></div><div class="toast" id="toast"></div>

<script>
const $=id=>document.getElementById(id),video=$("video"),scr=$("screen"),layer=$("layer"),svg=$("svg"),menu=$("menu");
let duration=60,current=0,scale=1,selected=[],next=5,drag=null,menuTarget=null,connectMode=null;
const colors={comment:"#4da3ff",highlight:"#f04444",zoom:"#54d68a",skip:"#ff9800"};
let els=[
{id:1,type:"comment",label:"コメント",start:5,end:18,x:18,y:15,w:25,h:9,text:"ここを確認してください"},
{id:2,type:"highlight",label:"強調枠",start:8,end:24,x:52,y:34,w:25,h:24,shape:"rect"},
{id:3,type:"zoom",label:"拡大枠",start:20,end:35,x:20,y:58,w:22,h:18},
{id:4,type:"skip",label:"スキップ",start:38,end:45}
],lines=[];

function fmt(v){let m=Math.floor(v/60),s=(v%60).toFixed(3);return String(m).padStart(2,"0")+":"+s.padStart(6,"0")}
function toast(s){let t=$("toast");t.textContent=s;t.style.display="block";clearTimeout(t.x);t.x=setTimeout(()=>t.style.display="none",1500)}
/* タイムライン幅は領域に固定。スケールでは変えない */
function timelineWidth(){return Math.max(100,$("scroll").clientWidth-95-15)}
function active(e){return current>=e.start&&current<=e.end}

function render(){
layer.innerHTML="";
els.filter(e=>e.type!="skip"&&active(e)).forEach(e=>{
let d=document.createElement("div");d.className="el "+e.type+(selected.includes(e.id)?" sel":"");d.dataset.id=e.id;
d.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%`;
if(e.shape)d.classList.add(e.shape);
if(e.type=="comment")d.textContent=e.text;
if(e.type=="zoom"){d.textContent="拡大表示範囲";d.style.color="#9ff0bd";d.style.textAlign="center";d.style.padding="8px"}
["nw","ne","sw","se"].forEach(p=>{let h=document.createElement("i");h.className="handle "+p;h.dataset.resize=p;d.append(h)});
d.onpointerdown=startMove;
d.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();if(!selected.includes(e.id)){selected=[e.id];render()}elementMenu(ev.clientX,ev.clientY,e.id)};
layer.append(d);
});
drawLines();timeline();
}

function select(id,e){
if(e&&(e.shiftKey||e.ctrlKey||e.metaKey))selected=selected.includes(id)?selected.filter(x=>x!=id):[...selected,id];
else selected=[id];
render();
}
function clear(){selected=[];menu.style.display="none";render()}
scr.onclick=e=>{if(!e.target.closest(".el"))clear()};

function startMove(e){
if(e.button!==0)return;
let o=els.find(x=>x.id==+e.currentTarget.dataset.id);if(!o)return;
e.stopPropagation();menu.style.display="none";
let multi=e.shiftKey||e.ctrlKey||e.metaKey;
if(!selected.includes(o.id)){
  selected=multi?[...selected,o.id]:[o.id];
  e.currentTarget.classList.add("sel");
}
let base={};selected.forEach(id=>{let x=els.find(a=>a.id==id);if(x)base[id]={x:x.x,y:x.y,w:x.w,h:x.h}});
drag={o,resize:e.target.dataset.resize,sx:e.clientX,sy:e.clientY,base,moved:false,multi};
addEventListener("pointermove",move);addEventListener("pointerup",end,{once:true});
}
function move(e){
if(!drag)return;
let r=scr.getBoundingClientRect(),dx=(e.clientX-drag.sx)/r.width*100,dy=(e.clientY-drag.sy)/r.height*100;
if(!drag.moved&&Math.abs(e.clientX-drag.sx)+Math.abs(e.clientY-drag.sy)<4)return;
drag.moved=true;
if(!drag.resize)selected.forEach(id=>{let x=els.find(a=>a.id==id),b=drag.base[id];if(x){x.x=Math.max(0,Math.min(100-x.w,b.x+dx));x.y=Math.max(0,Math.min(100-x.h,b.y+dy))}});
else{let x=drag.o,b=drag.base[x.id],p=drag.resize;
if(p.includes("e"))x.w=Math.max(5,Math.min(100-x.x,b.w+dx));
if(p.includes("s"))x.h=Math.max(5,Math.min(100-x.y,b.h+dy));
if(p.includes("w")){let nx=Math.max(0,b.x+dx);x.w=Math.max(5,b.w+(b.x-nx));x.x=nx}
if(p.includes("n")){let ny=Math.max(0,b.y+dy);x.h=Math.max(5,b.h+(b.y-ny));x.y=ny}}
render();
}
function end(e){
if(drag&&!drag.moved&&!drag.resize){
  if(drag.multi)selected=selected.includes(drag.o.id)?selected:[...selected,drag.o.id];
  else selected=[drag.o.id];
}
drag=null;removeEventListener("pointermove",move);render();
}

function drawAxis(){
let a=$("axis"),W=timelineWidth();a.innerHTML="";
let steps=[0.1,0.2,0.5,1,2,5,10,15,30,60,120,300,600],
    pxPerSec=W/duration,
    minPx=60/scale,
    step=steps.find(s=>s*pxPerSec>=minPx)||600;
for(let t=0;t<duration-step*0.3;t+=step){
let d=document.createElement("div");d.className="tick";
d.style.left=t/duration*W+"px";d.textContent=fmt(t).slice(0,8);a.append(d);
}
let e=document.createElement("div");e.className="tick end";
e.style.left=W+"px";e.textContent=fmt(duration).slice(0,8);a.append(e);
}

function timeline(){
drawAxis();
let W=timelineWidth();
["comment","highlight","zoom","skip"].forEach(type=>{
let box=$(type);box.innerHTML="";
els.filter(e=>e.type==type).forEach(e=>{
let b=document.createElement("div");b.className="bar"+(selected.includes(e.id)?" sel":"");
let L=e.start/duration*W,R=e.end/duration*W;
b.style.left=L+"px";b.style.width=Math.max(24,Math.min(R,W)-L)+"px";
b.style.color=colors[type];b.style.background=colors[type]+"44";b.textContent=e.label;
b.onclick=x=>{x.stopPropagation();select(e.id,x)};
box.append(b);
});
});
$("head").style.left=95+current/duration*W+"px";
$("time").textContent=fmt(current)+" / "+fmt(duration);
}

function drawLines(){svg.querySelectorAll(".line,.linehit").forEach(x=>x.remove())}

function elementMenu(x,y,id){
let e=els.find(a=>a.id==id);
let h=`<b>${e.label}</b>`;
if(e.type=="comment")h+=`<button data-a="text">コメント変更</button>`;
if(e.type=="highlight")h+=`<button data-a="rect">直角</button><button data-a="round">角丸</button><button data-a="circle">円形</button>`;
if(e.type=="zoom")h+=`<button data-a="connect">接続線を作成</button>`;
h+=`<button data-a="delete">削除</button>`;
menu.innerHTML=h;menu.style.display="block";menu.style.left=Math.min(x,innerWidth-220)+"px";menu.style.top=Math.min(y,innerHeight-180)+"px";menuTarget=id;
}
menu.addEventListener("click",e=>{
let a=e.target.dataset.a;if(!a)return;let x=els.find(v=>v.id==menuTarget);if(!x)return;
if(a=="text"){let v=prompt("コメント",x.text);if(v!==null)x.text=v}
if(["rect","round","circle"].includes(a))x.shape=a;
if(a=="connect"){connectMode=x.id;toast("接続先をクリックしてください")}
if(a=="delete"){els=els.filter(v=>v.id!=x.id);selected=[]}
menu.style.display="none";render();
});

scr.oncontextmenu=e=>{
if(e.target.closest(".el"))return;e.preventDefault();
menu.innerHTML=`<b>要素を追加</b><button data-add="comment">コメント</button><button data-add="highlight">強調枠</button><button data-add="zoom">拡大枠</button><button data-add="skip">スキップ</button>`;
menu.style.display="block";menu.style.left=e.clientX+"px";menu.style.top=e.clientY+"px";menuTarget={x:e.clientX,y:e.clientY};
};
menu.addEventListener("click",e=>{
let t=e.target.dataset.add;if(!t)return;
let r=scr.getBoundingClientRect(),w=25,h=18,
x=Math.max(0,Math.min(100-w,(menuTarget.x-r.left)/r.width*100)),
y=Math.max(0,Math.min(100-h,(menuTarget.y-r.top)/r.height*100));
els.push({id:next++,type:t,label:t=="comment"?"コメント":t=="highlight"?"強調枠":t=="zoom"?"拡大枠":"スキップ",start:current,end:Math.min(duration,current+5),x,y,w,h,text:"新しいコメント",shape:"rect"});
menu.style.display="none";render();
});

$("pick").onclick=()=>$("file").click();
$("file").onchange=e=>{let f=e.target.files[0];if(!f)return;video.src=URL.createObjectURL(f);video.onloadedmetadata=()=>{duration=video.duration;$("hint").style.display="none";$("status").textContent=f.name;render()}};
$("play").onclick=()=>{if(!video.src)return toast("先に動画を選択してください");video.paused?video.play():video.pause()};
$("stop").onclick=()=>{if(video.src){video.pause();video.currentTime=0}};
video.onplay=()=>$("play").textContent="❚❚ 一時停止";
video.onpause=()=>$("play").textContent="▶ 再生";
video.ontimeupdate=()=>{current=video.currentTime;render()};

/* スケールは幅を変えず、目盛りの細かさだけを変える */
$("scale").oninput=e=>{scale=+e.target.value;$("scaleText").textContent=scale.toFixed(1)+"×";render()};

function seekFromX(cx){
let r=$("tl").getBoundingClientRect(),x=cx-r.left-95;
current=Math.max(0,Math.min(duration,x/timelineWidth()*duration));
if(video.src)video.currentTime=current;
render();
}
$("head").onpointerdown=e=>{
e.preventDefault();e.stopPropagation();
let mv=ev=>seekFromX(ev.clientX);
addEventListener("pointermove",mv);
addEventListener("pointerup",()=>removeEventListener("pointermove",mv),{once:true});
};
$("tl").onclick=e=>{
if(e.target.closest(".bar"))return;
let r=$("tl").getBoundingClientRect(),x=e.clientX-r.left-95;
if(x>=0)seekFromX(e.clientX);
};

addEventListener("keydown",e=>{
if(e.key=="Escape")menu.style.display="none";
if(e.key=="Delete"&&selected.length){els=els.filter(x=>!selected.includes(x.id));selected=[];render()}
});
addEventListener("click",e=>{if(!e.target.closest(".menu"))menu.style.display="none"});
addEventListener("resize",render);
render();
</script>
</body>
</html>
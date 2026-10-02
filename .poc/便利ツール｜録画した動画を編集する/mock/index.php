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
svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:1}.line{fill:none;stroke:#90a4ae;stroke-width:2}
.hint{position:absolute;inset:0;display:grid;place-items:center;color:#657080;text-align:center;pointer-events:none}
.timeline{height:270px;flex:none;border-top:1px solid #30363d;background:#11161d}
.tools{height:42px;display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid #30363d}
.time{min-width:165px}.scale{margin-left:auto;color:#8b949e;font-size:12px}.scale input{width:120px}
.scroll{height:calc(100% - 42px);overflow-x:auto;overflow-y:hidden}.tl{position:relative;height:100%}
.axis{height:30px;margin-left:95px;position:relative;border-bottom:1px solid #30363d}
.tick{position:absolute;height:100%;border-left:1px solid #343b45;color:#7d8590;font-size:10px;padding-left:3px;white-space:nowrap}
.rows{margin-left:95px}.row{height:45px;position:relative;border-bottom:1px solid #242b33}
.label{position:absolute;right:100%;width:95px;height:45px;display:flex;align-items:center;padding-left:7px;background:#161b22;font-size:11px}
.bar{position:absolute;top:7px;height:31px;border:1px solid;border-radius:5px;display:flex;align-items:center;padding:0 7px;font-size:10px;cursor:pointer;white-space:nowrap;overflow:hidden}
.bar.sel{box-shadow:0 0 0 2px #4da3ff}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#f04444;z-index:20;cursor:ew-resize}
.playhead::before{content:"";position:absolute;left:-7px;top:0;width:16px;height:16px;background:#f04444;border-radius:3px 3px 8px 8px}
.playhead::after{content:"";position:absolute;left:-8px;right:-8px;top:0;bottom:0}
.menu{position:fixed;display:none;z-index:100;background:#1c2531;border:1px solid #46515f;border-radius:6px;padding:5px;min-width:210px;box-shadow:0 10px 30px #0009}.menu button{display:block;width:100%;text-align:left;margin:2px 0}
.toast{position:fixed;right:12px;bottom:12px;background:#202938;border:1px solid #46515f;padding:8px 12px;border-radius:5px;display:none}
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
<svg id="svg"></svg>
<div class="layer" id="layer"></div>
<div class="hint" id="hint">動画を選択してください<br>動画上で右クリックすると要素を追加できます</div>
</div></div>

<div class="timeline">
<div class="tools">
<button id="play">▶ 再生</button><button id="stop">■ 停止</button>
<span class="time" id="time">00:00.000 / 00:00.000</span>
<span class="scale">時間軸 <input id="scale" type="range" min=".5" max="4" step=".1" value="1"> <span id="scaleText">1.0×</span></span>
</div>
<div class="scroll" id="scroll"><div class="tl" id="tl">
<div class="axis" id="axis"></div>
<div class="rows">
<div class="row"><div class="label">● コメント</div><div id="comment"></div></div>
<div class="row"><div class="label">● 強調枠</div><div id="highlight"></div></div>
<div class="row"><div class="label">● 拡大枠</div><div id="zoom"></div></div>
<div class="row"><div class="label">● スキップ</div><div id="skip"></div></div>
</div>
<div class="playhead" id="head"></div>
</div></div>
</div>
</div>
</div>

<div class="menu" id="menu"></div><div class="toast" id="toast"></div>

<script>
const $=id=>document.getElementById(id),video=$("video"),screen=$("screen"),layer=$("layer"),menu=$("menu");
let duration=60,current=0,scale=1,selected=[],next=5,drag=null,menuTarget=null;
const colors={comment:"#4da3ff",highlight:"#f04444",zoom:"#54d68a",skip:"#ff9800"};
let els=[
{id:1,type:"comment",label:"コメント",start:5,end:18,x:18,y:15,w:25,h:9,text:"ここを確認してください"},
{id:2,type:"highlight",label:"強調枠",start:8,end:24,x:52,y:34,w:25,h:24,shape:"rect"},
{id:3,type:"zoom",label:"拡大枠",start:20,end:35,x:20,y:58,w:22,h:18},
{id:4,type:"skip",label:"スキップ",start:38,end:45}
];

function fmt(v){let m=Math.floor(v/60),s=(v%60).toFixed(3);return String(m).padStart(2,"0")+":"+s.padStart(6,"0")}
function toast(s){let t=$("toast");t.textContent=s;t.style.display="block";clearTimeout(t.x);t.x=setTimeout(()=>t.style.display="none",1500)}
function timelineWidth(){return Math.max(700,$("scroll").clientWidth-115)*scale}
function active(e){return current>=e.start&&current<=e.end}

function render(){
layer.innerHTML="";
els.filter(e=>e.type!="skip"&&active(e)).forEach(e=>{
let d=document.createElement("div");d.className="el "+e.type+(selected.includes(e.id)?" sel":"");d.dataset.id=e.id;
d.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%`;
if(e.type=="highlight"&&e.shape&&e.shape!="rect")d.classList.add(e.shape);
if(e.type=="comment")d.textContent=e.text;
if(e.type=="zoom"){d.textContent="拡大表示範囲";d.style.color="#9ff0bd";d.style.textAlign="center";d.style.padding="8px"}
if(selected.includes(e.id))["nw","ne","sw","se"].forEach(p=>{let h=document.createElement("i");h.className="handle "+p;h.dataset.resize=p;d.append(h)});
d.onpointerdown=startMove;
d.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();if(!selected.includes(e.id)){selected=[e.id];render()}elementMenu(ev.clientX,ev.clientY,e.id)};
layer.append(d);
});
timeline();
}

function clear(){selected=[];menu.style.display="none";render()}
screen.onclick=e=>{if(!e.target.closest(".el"))clear()};

function startMove(e){
if(e.button!==0)return;
e.stopPropagation();menu.style.display="none";
let id=+e.currentTarget.dataset.id,o=els.find(x=>x.id==id);if(!o)return;
let multi=e.shiftKey||e.ctrlKey||e.metaKey,resize=e.target.dataset.resize;
if(multi){selected=selected.includes(id)?selected.filter(x=>x!=id):[...selected,id]}
else if(!selected.includes(id))selected=[id];
let base={};selected.forEach(i=>{let x=els.find(a=>a.id==i);if(x)base[i]={x:x.x,y:x.y,w:x.w,h:x.h}});
drag={o,resize,sx:e.clientX,sy:e.clientY,base,moved:false,multi};
addEventListener("pointermove",move);addEventListener("pointerup",end,{once:true});
render();
}
function move(e){
if(!drag)return;
if(!drag.moved&&Math.abs(e.clientX-drag.sx)+Math.abs(e.clientY-drag.sy)<3)return;
drag.moved=true;
let r=screen.getBoundingClientRect(),dx=(e.clientX-drag.sx)/r.width*100,dy=(e.clientY-drag.sy)/r.height*100;
if(!drag.resize)selected.forEach(id=>{let x=els.find(a=>a.id==id),b=drag.base[id];if(x&&b&&x.w!=null){x.x=Math.max(0,Math.min(100-x.w,b.x+dx));x.y=Math.max(0,Math.min(100-x.h,b.y+dy))}});
else{let x=drag.o,b=drag.base[x.id],p=drag.resize;
if(p.includes("e"))x.w=Math.max(5,Math.min(100-x.x,b.w+dx));
if(p.includes("s"))x.h=Math.max(5,Math.min(100-x.y,b.h+dy));
if(p.includes("w")){let nx=Math.max(0,Math.min(b.x+b.w-5,b.x+dx));x.w=b.w+(b.x-nx);x.x=nx}
if(p.includes("n")){let ny=Math.max(0,Math.min(b.y+b.h-5,b.y+dy));x.h=b.h+(b.y-ny);x.y=ny}}
renderKeep();
}
/* ドラッグ中はDOMを破棄せず位置だけ更新 */
function renderKeep(){
els.forEach(e=>{let d=layer.querySelector(`[data-id="${e.id}"]`);if(d)d.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%`});
}
function end(){
if(drag&&!drag.moved&&!drag.multi)selected=[drag.o.id];
drag=null;removeEventListener("pointermove",move);render();
}

function drawAxis(){
let a=$("axis");a.innerHTML="";
let steps=[0.5,1,2,5,10,15,30,60,120,300,600],w=timelineWidth(),
    step=steps.find(s=>s/duration*w>=60)||600;
for(let t=0;t<=duration;t+=step){
let d=document.createElement("div");d.className="tick";
d.style.left=t/duration*w+"px";d.textContent=fmt(t).slice(0,5)+(step<1?fmt(t).slice(5,8):"");a.append(d);
}
}

function timeline(){
let w=timelineWidth();
["comment","highlight","zoom","skip"].forEach(type=>{
let box=$(type);box.innerHTML="";
els.filter(e=>e.type==type).forEach(e=>{
let b=document.createElement("div");b.className="bar"+(selected.includes(e.id)?" sel":"");
b.style.left=e.start/duration*w+"px";b.style.width=Math.max(24,(e.end-e.start)/duration*w)+"px";
b.style.color=colors[type];b.style.background=colors[type]+"44";b.textContent=e.label;
b.onclick=x=>{x.stopPropagation();
if(x.shiftKey||x.ctrlKey||x.metaKey)selected=selected.includes(e.id)?selected.filter(i=>i!=e.id):[...selected,e.id];
else selected=[e.id];
render()};
b.oncontextmenu=x=>{x.preventDefault();x.stopPropagation();selected=[e.id];render();elementMenu(x.clientX,x.clientY,e.id)};
box.append(b);
});
});
drawAxis();
$("tl").style.width=w+115+"px";
$("head").style.left=95+current/duration*w+"px";
$("time").textContent=fmt(current)+" / "+fmt(duration);
}

function elementMenu(x,y,id){
let e=els.find(a=>a.id==id);
let h=`<b>${e.label}</b>`;
if(e.type=="comment")h+=`<button data-a="text">コメント変更</button>`;
if(e.type=="highlight")h+=`<button data-a="rect">直線（四角）</button><button data-a="round">角丸</button><button data-a="circle">円形</button>`;
h+=`<button data-a="delete">削除</button>`;
menu.innerHTML=h;menu.style.display="block";menu.style.left=Math.min(x,innerWidth-220)+"px";menu.style.top=Math.min(y,innerHeight-180)+"px";menuTarget=id;
}
menu.onclick=e=>{
let a=e.target.dataset.a;if(!a)return;let x=els.find(v=>v.id==menuTarget);if(!x)return;
if(a=="text"){let v=prompt("コメント",x.text);if(v!==null)x.text=v}
if(["rect","round","circle"].includes(a))x.shape=a;
if(a=="delete"){els=els.filter(v=>v.id!=x.id);selected=[]}
menu.style.display="none";render();
};

screen.oncontextmenu=e=>{
if(e.target.closest(".el"))return;e.preventDefault();
menu.innerHTML=`<b>要素を追加</b><button data-add="comment">コメント</button><button data-add="highlight">強調枠</button><button data-add="zoom">拡大枠</button><button data-add="skip">スキップ</button>`;
menu.style.display="block";menu.style.left=e.clientX+"px";menu.style.top=e.clientY+"px";menuTarget={x:e.clientX,y:e.clientY};
};
menu.addEventListener("click",e=>{
let t=e.target.dataset.add;if(!t)return;
let r=screen.getBoundingClientRect(),x=Math.max(0,Math.min(75,(menuTarget.x-r.left)/r.width*100)),y=Math.max(0,Math.min(82,(menuTarget.y-r.top)/r.height*100));
let n={id:next++,type:t,label:t=="comment"?"コメント":t=="highlight"?"強調枠":t=="zoom"?"拡大枠":"スキップ",start:current,end:Math.min(duration,current+5)};
if(t!="skip")Object.assign(n,{x,y,w:25,h:18,text:"新しいコメント",shape:"rect"});
els.push(n);selected=[n.id];
menu.style.display="none";render();
});

$("pick").onclick=()=>$("file").click();
$("file").onchange=e=>{let f=e.target.files[0];if(!f)return;video.src=URL.createObjectURL(f);video.onloadedmetadata=()=>{duration=video.duration;$("hint").style.display="none";$("status").textContent=f.name;render()}};
$("play").onclick=()=>{if(!video.src)return toast("先に動画を選択してください");video.paused?video.play():video.pause()};
$("stop").onclick=()=>{if(video.src){video.pause();video.currentTime=0}};
video.onplay=()=>$("play").textContent="❚❚ 一時停止";
video.onpause=()=>$("play").textContent="▶ 再生";
video.ontimeupdate=()=>{current=video.currentTime;render()};

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
if(e.target.closest(".bar")||e.target.closest(".playhead"))return;
let r=$("tl").getBoundingClientRect();
if(e.clientX-r.left-95>=0)seekFromX(e.clientX);
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
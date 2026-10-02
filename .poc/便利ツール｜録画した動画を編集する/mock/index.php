<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}html,body{margin:0;height:100%;overflow:hidden;background:#11161d;color:#eee;font-family:Arial,"Noto Sans JP",sans-serif}
button,input,select{font:inherit}.app{height:100%;display:flex;flex-direction:column}
header{height:52px;display:flex;align-items:center;gap:7px;padding:7px 10px;background:#161b22;border-bottom:1px solid #30363d}
button{border:1px solid #46515f;background:#242c36;color:#eee;border-radius:5px;padding:6px 10px;cursor:pointer}
button:hover{background:#303b48}.primary{background:#1769aa}.status{margin-left:auto;color:#9aa5b1;font-size:12px}
.main{flex:1;min-height:0;display:flex;flex-direction:column}.video{flex:1;min-height:0;background:#05070a;padding:10px}
.screen{height:100%;position:relative;background:#000;overflow:hidden}
video{width:100%;height:100%;object-fit:contain}.layer{position:absolute;inset:0}
.el{position:absolute;cursor:move;user-select:none;min-width:40px;min-height:25px}
.el.sel{outline:2px solid #4da3ff;outline-offset:3px}.comment{padding:7px 10px;color:#fff;background:#0009}
.highlight{border:4px solid #f04444}.highlight.round{border-radius:18px}.highlight.circle{border-radius:50%}
.zoom{border:3px solid #54d68a;background:#54d68a18}
.handle{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #4da3ff}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.ne{right:-5px;top:-5px;cursor:nesw-resize}
.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.se{right:-5px;bottom:-5px;cursor:nwse-resize}
svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}.linehit{pointer-events:stroke;stroke:transparent;stroke-width:18;fill:none;cursor:pointer}.line{fill:none;stroke:#90a4ae;stroke-width:2}
.line.sel{stroke:#4da3ff;stroke-width:3}.hint{position:absolute;inset:0;display:grid;place-items:center;color:#657080;text-align:center}
.timeline{height:270px;flex:none;border-top:1px solid #30363d;background:#11161d}
.tools{height:42px;display:flex;align-items:center;gap:8px;padding:5px 9px;border-bottom:1px solid #30363d}
.time{min-width:165px}.zoomctl{margin-left:auto;color:#8b949e;font-size:12px}.zoomctl input{width:120px}
.scroll{height:calc(100% - 42px);overflow:auto}.tl{position:relative;min-width:800px;height:100%}
.axis{height:30px;margin-left:95px;position:relative;border-bottom:1px solid #30363d}.tick{position:absolute;height:100%;border-left:1px solid #343b45;color:#7d8590;font-size:10px;padding-left:3px}
.rows{margin-left:95px}.row{height:45px;position:relative;border-bottom:1px solid #242b33}.label{position:absolute;right:100%;width:95px;height:45px;display:flex;align-items:center;padding-left:7px;background:#161b22;font-size:11px}
.bar{position:absolute;top:7px;height:31px;border:1px solid currentColor;border-radius:5px;display:flex;align-items:center;padding:0 7px;font-size:10px;cursor:grab}
.bar.sel{box-shadow:0 0 0 2px #4da3ff}.playhead{position:absolute;top:30px;bottom:0;width:2px;background:#f04444;z-index:20;pointer-events:none}
.menu{position:fixed;display:none;z-index:100;background:#1c2531;border:1px solid #46515f;border-radius:6px;padding:5px;min-width:210px;box-shadow:0 10px 30px #0009}
.menu button,.menu select{display:block;width:100%;margin:2px 0;text-align:left}.menu select{background:#242c36;color:#fff;border:1px solid #46515f;padding:6px}
.toast{position:fixed;right:12px;bottom:12px;background:#202938;border:1px solid #46515f;padding:8px 12px;border-radius:5px;display:none}
</style>
</head>
<body>
<div class="app">
<header>
  <b>録画動画編集</b>
  <button class="primary" id="pick">動画を選択</button>
  <input id="file" type="file" accept="video/*" hidden>
  <button id="play">▶ 再生</button>
  <button id="stop">■ 停止</button>
  <span class="status" id="status">動画を選択してください</span>
</header>

<div class="main">
<div class="video">
  <div class="screen" id="screen">
    <video id="video" playsinline></video>
    <svg id="svg">
      <defs>
        <marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
          <path d="M0 0L8 4L0 8Z" fill="#90a4ae"/>
        </marker>
        <marker id="arrowSel" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
          <path d="M0 0L8 4L0 8Z" fill="#4da3ff"/>
        </marker>
      </defs>
    </svg>
    <div class="layer" id="layer"></div>
    <div class="hint" id="hint">動画を選択してください<br>動画上で右クリックすると要素を追加できます</div>
  </div>
</div>

<div class="timeline">
  <div class="tools">
    <span class="time" id="time">00:00.000 / 00:00.000</span>
    <span class="zoomctl">時間軸
      <input id="scale" type="range" min=".5" max="4" step=".1" value="1">
      <span id="scaleText">1.0×</span>
    </span>
  </div>
  <div class="scroll" id="scroll">
    <div class="tl" id="tl">
      <div class="axis" id="axis"></div>
      <div class="rows">
        <div class="row"><div class="label">● コメント</div><div id="comment"></div></div>
        <div class="row"><div class="label">● 強調枠</div><div id="highlight"></div></div>
        <div class="row"><div class="label">● 拡大枠</div><div id="zoom"></div></div>
        <div class="row"><div class="label">● スキップ</div><div id="skip"></div></div>
      </div>
      <div class="playhead" id="head"></div>
    </div>
  </div>
</div>
</div>
</div>

<div class="menu" id="menu"></div>
<div class="toast" id="toast"></div>

<script>
const $=id=>document.getElementById(id);
const video=$("video"),screen=$("screen"),layer=$("layer"),svg=$("svg");
let duration=60,current=0,scale=1,selected=[],menuTarget=null,drag=null,next=5;
const color={comment:"#4da3ff",highlight:"#f04444",zoom:"#54d68a",skip:"#ff9800"};

let els=[
 {id:1,type:"comment",label:"コメント",start:5,end:18,x:18,y:15,w:25,h:9,text:"ここを確認してください"},
 {id:2,type:"highlight",label:"強調枠",start:8,end:24,x:52,y:34,w:25,h:24,shape:"rect"},
 {id:3,type:"zoom",label:"拡大枠",start:20,end:35,x:20,y:58,w:22,h:18},
 {id:4,type:"skip",label:"スキップ",start:38,end:45}
];
let lines=[];

function fmt(v){
 let m=Math.floor(v/60),s=(v%60).toFixed(3);
 return String(m).padStart(2,"0")+":"+s.padStart(6,"0");
}
function width(){return Math.max(700,$("scroll").clientWidth-15)*scale}
function toast(s){$("toast").textContent=s;$("toast").style.display="block";clearTimeout(toast.t);toast.t=setTimeout(()=>$("toast").style.display="none",1600)}
function seek(t){
 current=Math.max(0,Math.min(duration,t));
 if(video.src)video.currentTime=current;
 render();timeline();
}
function active(e){return current>=e.start&&current<=e.end}
function render(){
 layer.innerHTML="";
 els.filter(e=>e.type!="skip"&&active(e)).forEach(e=>{
  let d=document.createElement("div");
  d.className="el "+e.type+(selected.includes(e.id)?" sel":"")+(e.shape=="round"?" round":"")+(e.shape=="circle"?" circle":"");
  d.dataset.id=e.id;
  d.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%`;
  if(e.type=="comment")d.textContent=e.text;
  if(e.type=="zoom"){d.textContent="拡大表示範囲";d.style.color="#9ff0bd";d.style.textAlign="center";d.style.padding="8px"}
  ["nw","ne","sw","se"].forEach(p=>{let h=document.createElement("i");h.className="handle "+p;h.dataset.resize=p;d.append(h)});
  d.onpointerdown=moveStart;
  d.onclick=e=>{e.stopPropagation();select(+d.dataset.id,e)};
  d.oncontextmenu=e=>{e.preventDefault();e.stopPropagation();select(+d.dataset.id,e);elementMenu(e.clientX,e.clientY,+d.dataset.id)};
  layer.append(d);
 });
 drawLines();
}
function select(id,e){
 if(e&&(e.shiftKey||e.ctrlKey||e.metaKey))selected=selected.includes(id)?selected.filter(x=>x!=id):[...selected,id];
 else selected=[id];
 lines.forEach(l=>l.sel=false);render();timeline();
}
function clear(){
 selected=[];lines.forEach(l=>l.sel=false);menu.style.display="none";render();timeline();
}
screen.onclick=e=>{if(!e.target.closest(".el"))clear()};

function moveStart(e){
 if(e.button!==0)return;
 let obj=els.find(x=>x.id==e.currentTarget.dataset.id);if(!obj)return;
 if(!selected.includes(obj.id))select(obj.id,e);
 let resize=e.target.dataset.resize;
 let base={};selected.forEach(id=>{let x=els.find(a=>a.id==id);if(x)base[id]={x:x.x,y:x.y,w:x.w,h:x.h}});
 drag={obj,resize,sx:e.clientX,sy:e.clientY,base};
 addEventListener("pointermove",moving);
 addEventListener("pointerup",moveEnd,{once:true});
}
function moving(e){
 if(!drag)return;
 let r=screen.getBoundingClientRect(),dx=(e.clientX-drag.sx)/r.width*100,dy=(e.clientY-drag.sy)/r.height*100;
 if(!drag.resize)selected.forEach(id=>{
  let x=els.find(a=>a.id==id),b=drag.base[id];if(!x||x.type=="skip")return;
  x.x=Math.max(0,Math.min(100-x.w,b.x+dx));x.y=Math.max(0,Math.min(100-x.h,b.y+dy));
 });
 else{
  let x=drag.obj,b=drag.base[x.id],p=drag.resize;
  if(p.includes("e"))x.w=Math.max(5,Math.min(100-x.x,b.w+dx));
  if(p.includes("s"))x.h=Math.max(5,Math.min(100-x.y,b.h+dy));
  if(p.includes("w")){x.x=Math.max(0,b.x+dx);x.w=Math.max(5,b.w-dx)}
  if(p.includes("n")){x.y=Math.max(0,b.y+dy);x.h=Math.max(5,b.h-dy)}
 }
 render();
}
function moveEnd(){drag=null;removeEventListener("pointermove",moving);timeline()}

function timeline(){
 ["comment","highlight","zoom","skip"].forEach(type=>{
  let box=$(type);box.innerHTML="";
  els.filter(e=>e.type==type).forEach(e=>{
   let b=document.createElement("div");b.className="bar"+(selected.includes(e.id)?" sel":"");
   b.style.left=e.start/duration*width()+"px";b.style.width=Math.max(24,(e.end-e.start)/duration*width())+"px";
   b.style.color=color[type];b.style.background=color[type]+"44";b.textContent=e.label;
   b.onclick=x=>{x.stopPropagation();select(e.id,x)};
   b.onpointerdown=x=>{if(x.button===0)barDrag(x,e)};
   box.append(b);
  });
 });
 $("tl").style.width=width()+95+"px";
 $("head").style.left=95+current/duration*width()+"px";
 $("time").textContent=fmt(current)+" / "+fmt(duration);
}
function barDrag(ev,e){
 let sx=ev.clientX,ss=e.start,len=e.end-e.start;
 function mv(x){let d=(x.clientX-sx)/width()*duration,n=Math.max(0,Math.min(duration-len,ss+d));e.start=n;e.end=n+len;timeline();render()}
 function up(){removeEventListener("pointermove",mv);removeEventListener("pointerup",up)}
 addEventListener("pointermove",mv);addEventListener("pointerup",up);
}

function drawLines(){
 svg.querySelectorAll(".line,.linehit").forEach(x=>x.remove());
 lines.forEach(l=>{
  let a=els.find(x=>x.id==l.from),b=els.find(x=>x.id==l.to);if(!a||!b)return;
  let p1=point(a,l.fp),p2=point(b,l.tp),d;
  if(l.shape=="orthogonal"){let mx=(p1[0]+p2[0])/2;d=`M${p1[0]} ${p1[1]} L${mx} ${p1[1]} L${mx} ${p2[1]} L${p2[0]} ${p2[1]}`}
  else d=`M${p1[0]} ${p1[1]} L${p2[0]} ${p2[1]}`;
  let h=document.createElementNS("http://www.w3.org/2000/svg","path");
  h.setAttribute("d",d);h.classList.add("linehit");h.onclick=e=>{e.stopPropagation();lineSelect(l.id)};
  svg.append(h);
  let q=document.createElementNS("http://www.w3.org/2000/svg","path");
  q.setAttribute("d",d);q.classList.add("line"+(l.sel?" sel":""));
  q.setAttribute("stroke-dasharray",l.style=="dashed"?"8 5":l.style=="dotted"?"2 5":l.style=="dashdot"?"8 4 2 4":"none");
  if(l.endArrow)q.setAttribute("marker-end",l.sel?"url(#arrowSel)":"url(#arrow)");
  svg.append(q);
 });
}
function point(e,p){
 let m={t:[e.x+e.w/2,e.y],r:[e.x+e.w,e.y+e.h/2],b:[e.x+e.w/2,e.y+e.h],l:[e.x,e.y+e.h/2]};
 return m[p]||m.r;
}
function lineSelect(id){selected=[];lines.forEach(l=>l.sel=l.id==id);render();timeline();let l=lines.find(x=>x.id==id);lineMenu(innerWidth/2,innerHeight/2,l)}

function elementMenu(x,y,id){
 let e=els.find(a=>a.id==id),h=`<b>${e.label}</b>`;
 if(e.type=="comment")h+=`<button data-a="text">コメント変更</button><button data-a="font">文字サイズ</button><button data-a="bg">背景色</button>`;
 if(e.type=="highlight")h+=`<button data-a="rect">直線</button><button data-a="round">角丸</button><button data-a="circle">円形</button><button data-a="fill">塗り潰し</button>`;
 if(e.type=="zoom")h+=`<button data-a="connect">接続線を作成</button>`;
 h+=`<button data-a="delete">削除</button>`;
 openMenu(x,y,h,{type:"element",id});
}
function lineMenu(x,y,l){
 openMenu(x,y,`<b>接続線</b>
 <select id="ls"><option value="solid">実線</option><option value="dotted">点線</option><option value="dashed">破線</option><option value="dashdot">一点鎖線</option></select>
 <button data-a="straight">直線</button><button data-a="orthogonal">折れ線</button><button data-a="arrow">終点矢印</button><button data-a="deleteLine">削除</button>`,{type:"line",id:l.id});
 setTimeout(()=>{$("ls").value=l.style;$("ls").onchange=e=>{l.style=e.target.value;drawLines()}},0);
}
function openMenu(x,y,html,target){menu.innerHTML=html;menu.style.display="block";menu.style.left=Math.min(x,innerWidth-220)+"px";menu.style.top=Math.min(y,innerHeight-250)+"px";menuTarget=target}
const menu=$("menu");
menu.onclick=e=>{
 let a=e.target.dataset.a;if(!a||!menuTarget)return;
 if(menuTarget.type=="element"){
  let x=els.find(v=>v.id==menuTarget.id);
  if(a=="text"){let v=prompt("コメント",x.text);if(v!==null)x.text=v}
  if(a=="rect"||a=="round"||a=="circle")x.shape=a;
  if(a=="font"){x.font=(x.font||18)+2}
  if(a=="bg")x.bg=x.bg=="#fff3"?"#0009":"#fff3";
  if(a=="fill")x.fill=!x.fill;
  if(a=="delete"){els=els.filter(v=>v.id!=x.id);lines=lines.filter(v=>v.from!=x.id&&v.to!=x.id);selected=[]}
  if(a=="connect"){connectMode=x.id;toast("接続先の要素をクリックしてください")}
 }else{
  let l=lines.find(v=>v.id==menuTarget.id);
  if(a=="straight")l.shape="straight";
  if(a=="orthogonal")l.shape="orthogonal";
  if(a=="arrow")l.endArrow=!l.endArrow;
  if(a=="deleteLine")lines=lines.filter(v=>v.id!=l.id);
 }
 menu.style.display="none";render();timeline();
};
document.addEventListener("click",e=>{if(!menu.contains(e.target))menu.style.display="none"});

let connectMode=null;
layer.addEventListener("click",e=>{
 if(!connectMode)return;
 let d=e.target.closest(".el");if(!d)return;
 let to=+d.dataset.id;if(to==connectMode)return;
 lines.push({id:next++,from:connectMode,to,fp:"r",tp:"l",shape:"straight",style:"solid",endArrow:false,sel:false});
 connectMode=null;render();toast("接続線を作成しました");
});

screen.oncontextmenu=e=>{
 if(e.target.closest(".el")||e.target.closest(".linehit"))return;
 e.preventDefault();
 openMenu(e.clientX,e.clientY,`<b>要素を追加</b>
 <button data-add="comment">コメント</button>
 <button data-add="highlight">強調枠</button>
 <button data-add="zoom">拡大枠</button>
 <button data-add="skip">スキップ</button>`,{type:"add",x:e.clientX,y:e.clientY});
};
menu.addEventListener("click",e=>{
 let t=e.target.dataset.add;if(!t)return;
 let r=screen.getBoundingClientRect(),x=(menuTarget.x-r.left)/r.width*100,y=(menuTarget.y-r.top)/r.height*100;
 let n={id:next++,type:t,label:t=="comment"?"コメント":t=="highlight"?"強調枠":t=="zoom"?"拡大枠":"スキップ",start:current,end:Math.min(duration,current+5),x:Math.max(0,Math.min(75,x)),y:Math.max(0,Math.min(75,y)),w:t=="highlight"?25:22,h:t=="comment"?9:18,text:"新しいコメント",shape:"rect"};
 els.push(n);selected=[n.id];menu.style.display="none";render();timeline();
});

$("pick").onclick=()=>$("file").click();
$("file").onchange=e=>{
 let f=e.target.files[0];if(!f)return;
 video.src=URL.createObjectURL(f);video.onloadedmetadata=()=>{duration=video.duration;$("hint").style.display="none";render();timeline();};
 $("status").textContent=f.name;
};
$("play").onclick=()=>{
 if(!video.src)return toast("先に動画を選択してください");
 video.paused?(video.play(),$("play").textContent="❚❚ 一時停止"):(video.pause(),$("play").textContent="▶ 再生");
};
$("stop").onclick=()=>{if(video.src){video.pause();video.currentTime=0;$("play").textContent="▶ 再生"}};
video.ontimeupdate=()=>{current=video.currentTime;render();timeline()};
$("scale").oninput=e=>{scale=+e.target.value;$("scaleText").textContent=scale.toFixed(1)+"×";timeline()};
$("tl").onclick=e=>{
 if(e.target.closest(".bar"))return;
 let r=$("tl").getBoundingClientRect(),x=e.clientX-r.left-95;
 if(x>=0)seek(x/width()*duration);
};
addEventListener("keydown",e=>{
 if(e.key=="Escape"){connectMode=null;menu.style.display="none"}
 if((e.key=="Delete"||e.key=="Backspace")&&selected.length){
  els=els.filter(x=>!selected.includes(x.id));lines=lines.filter(x=>!selected.includes(x.from)&&!selected.includes(x.to));selected=[];render();timeline();
 }
});
addEventListener("resize",()=>timeline());

render();timeline();
</script>
</body>
</html>

<?php ?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}html,body{margin:0;height:100%;overflow:hidden;background:#11161d;color:#eee;font-family:Arial,"Noto Sans JP",sans-serif}
button,input{font:inherit}button{border:1px solid #46515f;background:#242c36;color:#eee;border-radius:5px;padding:6px 10px;cursor:pointer}button:hover{background:#303b48}
.app,.main{display:flex;flex-direction:column}.app{height:100%}.main{flex:1;min-height:0}
header,.tools{display:flex;align-items:center;gap:8px;padding:7px 10px}header{height:52px;background:#161b22;border-bottom:1px solid #30363d}
.status{margin-left:auto;color:#9aa5b1;font-size:12px}
.video{flex:1;min-height:0;background:#05070a;padding:10px}.screen{height:100%;position:relative;background:#000;overflow:hidden}
video{width:100%;height:100%;object-fit:contain}.layer{position:absolute;inset:0;z-index:2;pointer-events:none}
.el{position:absolute;cursor:move;user-select:none;min-width:40px;min-height:25px;pointer-events:auto}
.el.sel{outline:2px solid #4da3ff;outline-offset:3px}.el.target{outline:2px dashed #ffd54f;outline-offset:3px;cursor:crosshair}
.comment{padding:7px 10px;color:#fff;background:#0009}.highlight{border:4px solid #f04444}.round{border-radius:18px}.circle{border-radius:50%}
.zoom{border:3px solid #54d68a;background:#54d68a18;color:#9ff0bd;text-align:center;padding:8px}
.handle{position:absolute;width:9px;height:9px;background:#fff;border:2px solid #4da3ff}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.ne{right:-5px;top:-5px;cursor:nesw-resize}.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.se{right:-5px;bottom:-5px;cursor:nwse-resize}
svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:3}
.hit{pointer-events:stroke;stroke:transparent;stroke-width:18;fill:none;cursor:pointer}
.line{fill:none;stroke:#90a4ae;stroke-width:2;pointer-events:none}.line.sel{stroke:#4da3ff;stroke-width:3}.line.pre{stroke:#ffd54f;stroke-dasharray:6 4}
.endh{fill:#fff;stroke:#4da3ff;stroke-width:2;pointer-events:auto;cursor:grab}
.hint{position:absolute;inset:0;display:grid;place-items:center;color:#657080;text-align:center;pointer-events:none;z-index:4}
.timeline{height:270px;flex:none;border-top:1px solid #30363d;display:flex;flex-direction:column}
.tools{height:42px;flex:none;border-bottom:1px solid #30363d}.time{min-width:165px}.scale{margin-left:auto;color:#8b949e;font-size:12px}.scale input{width:120px}
.scroll{flex:1;min-height:0;overflow-x:hidden;overflow-y:auto;position:relative}.tl{position:relative;min-height:100%}
.axis{height:26px;margin:0 15px 0 95px;position:relative;border-bottom:1px solid #30363d}
.tick{position:absolute;height:100%;border-left:1px solid #4a5563;color:#9aa5b1;font-size:10px;padding-left:3px;white-space:nowrap}
.tick.end{border:0;border-right:2px solid #f0b000;padding:0 3px 0 0;transform:translateX(-100%);color:#f0b000}
.rows{margin:0 15px 0 95px;position:relative}.row{height:34px;position:relative;border-bottom:1px solid #242b33}
.row .label{position:absolute;right:100%;width:95px;height:34px;display:flex;align-items:center;padding-left:7px;background:#161b22;font-size:10px;color:#8b949e}
.bar{position:absolute;top:4px;height:26px;border:1px solid;border-radius:5px;display:flex;align-items:center;padding:0 12px;font-size:10px;cursor:grab;overflow:hidden;white-space:nowrap;user-select:none}
.bar.sel{box-shadow:0 0 0 2px #4da3ff}.bar.moving{cursor:grabbing}
.bar .g{position:absolute;top:0;bottom:0;width:8px;cursor:ew-resize;background:#fff3}.bar .g:hover{background:#fff7}
.bar .gl{left:0;border-radius:4px 0 0 4px}.bar .gr{right:0;border-radius:0 4px 4px 0}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#f04444;z-index:20;cursor:ew-resize}
.playhead::before{content:"";position:absolute;left:-7px;top:0;width:16px;height:16px;background:#f04444;border-radius:3px 3px 8px 8px}
.playhead::after{content:"";position:absolute;left:-8px;right:-8px;top:0;bottom:0}
.menu{position:fixed;display:none;z-index:100;background:#1c2531;border:1px solid #46515f;border-radius:6px;padding:5px;min-width:210px;max-height:95vh;overflow-y:auto;box-shadow:0 10px 30px #0009}
.menu button{display:block;width:100%;text-align:left;margin:2px 0}.menu hr{border:0;border-top:1px solid #46515f;margin:4px 0}.menu .cur{background:#1769aa}
.toast{position:fixed;right:12px;bottom:12px;background:#202938;border:1px solid #46515f;padding:8px 12px;border-radius:5px;display:none;z-index:200}
</style></head>
<body><div class="app">
<header><b>録画動画編集</b><button style="background:#1769aa" id="pick">動画を選択</button><input id="file" type="file" accept="video/*" hidden><span class="status" id="status">動画を選択してください</span></header>
<div class="main">
<div class="video"><div class="screen" id="screen"><video id="video" playsinline></video><div class="layer" id="layer"></div><svg id="svg"></svg>
<div class="hint" id="hint">動画を選択してください<br>動画上で右クリックすると要素を追加できます</div></div></div>
<div class="timeline">
<div class="tools"><button id="play">▶ 再生</button><button id="stop">■ 停止</button><span class="time" id="time"></span>
<span class="scale">時間軸 <input id="scale" type="range" min=".5" max="4" step=".1" value="1"> <span id="scaleText">1.0×</span></span></div>
<div class="scroll"><div class="tl" id="tl"><div class="axis" id="axis"></div><div class="rows" id="rows"></div><div class="playhead" id="head"></div></div></div>
</div></div></div>
<div class="menu" id="menu"></div><div class="toast" id="toast"></div>
<script>
const $=id=>document.getElementById(id),video=$("video"),scr=$("screen"),layer=$("layer"),svg=$("svg"),menu=$("menu");
const TYPES={comment:["コメント","#4da3ff"],highlight:["強調枠","#f04444"],zoom:["拡大枠","#54d68a"],skip:["スキップ","#ff9800"]};
const SIDES=["top","right","bottom","left"],SJ={top:"上",right:"右",bottom:"下",left:"左"},DASH={solid:"",dotted:"2 5",dashed:"10 6",dashdot:"12 5 2 5"};
let duration=60,current=0,scale=1,selected=[],selLine=null,next=6,nextLine=1,drag=null,mt=null,connect=null,mouse=null,endDrag=null,tdrag=null;
let els=[{id:1,type:"comment",start:5,end:18,x:18,y:15,w:25,h:9,text:"ここを確認してください"},{id:2,type:"highlight",start:8,end:24,x:52,y:34,w:25,h:24},{id:3,type:"zoom",start:20,end:35,x:20,y:58,w:22,h:18},{id:4,type:"skip",start:38,end:45},{id:5,type:"comment",start:10,end:30,x:55,y:10,w:25,h:9,text:"同時間帯のコメント"}];
let lines=[];
const fmt=v=>String(Math.floor(v/60)).padStart(2,"0")+":"+(v%60).toFixed(3).padStart(6,"0");
const fmtTick=(v,step)=>{let m=Math.floor(v/60),s=v-m*60,dec=step<1?(step<.1?2:1):0;return String(m).padStart(2,"0")+":"+s.toFixed(dec).padStart(dec?3+dec:2,"0")};
const toast=s=>{let t=$("toast");t.textContent=s;t.style.display="block";clearTimeout(t.x);t.x=setTimeout(()=>t.style.display="none",1800)};
const TW=()=>Math.max(100,$("tl").clientWidth-110),active=e=>current>=e.start&&current<=e.end,byId=id=>els.find(a=>a.id==id);
const multi=e=>e.shiftKey||e.ctrlKey||e.metaKey;
function pick(id,e){selLine=null;selected=multi(e)?(selected.includes(id)?selected.filter(i=>i!=id):[...selected,id]):[id]}
function cancelConnect(){connect=null;mouse=null}

function render(){
layer.innerHTML="";
els.filter(e=>e.type!="skip"&&active(e)).forEach(e=>{
 let d=document.createElement("div");d.dataset.id=e.id;
 d.className="el "+e.type+" "+(e.shape||"")+(selected.includes(e.id)?" sel":"")+(connect&&connect!=e.id?" target":"");
 d.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%`;
 d.textContent=e.type=="comment"?e.text:e.type=="zoom"?"拡大表示範囲":"";
 ["nw","ne","sw","se"].forEach(p=>d.insertAdjacentHTML("beforeend",`<i class="handle ${p}" data-resize="${p}"></i>`));
 d.onpointerdown=startMove;
 d.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();if(connect)return;if(!selected.includes(e.id)){selected=[e.id];selLine=null;render()}elMenu(ev.clientX,ev.clientY,e)};
 layer.append(d)});
drawLines();timeline();
}
scr.onclick=e=>{if(!e.target.closest(".el,svg *")){selected=[];selLine=null;cancelConnect();menu.style.display="none";render()}};
scr.onpointermove=e=>{if(!connect)return;let r=scr.getBoundingClientRect();mouse={x:e.clientX-r.left,y:e.clientY-r.top};drawLines()};

function startMove(e){
 if(e.button!==0)return;let o=byId(+e.currentTarget.dataset.id);e.stopPropagation();menu.style.display="none";
 if(connect){
  if(connect==o.id)return;
  lines.push({id:nextLine,from:connect,to:o.id,fs:"auto",ts:"auto",form:"straight",dash:"solid",se:"none",ee:"none"});
  selLine=nextLine++;selected=[];cancelConnect();return render();
 }
 selLine=null;if(!selected.includes(o.id))selected=multi(e)?[...selected,o.id]:[o.id];
 let base={};selected.forEach(id=>{let x=byId(id);base[id]={...x}});
 drag={o,resize:e.target.dataset.resize,sx:e.clientX,sy:e.clientY,base,moved:false,m:multi(e)};
 addEventListener("pointermove",move);addEventListener("pointerup",end,{once:true});
}
function move(e){
 let r=scr.getBoundingClientRect(),dx=(e.clientX-drag.sx)/r.width*100,dy=(e.clientY-drag.sy)/r.height*100;
 if(!drag.moved&&Math.abs(e.clientX-drag.sx)+Math.abs(e.clientY-drag.sy)<4)return;drag.moved=true;
 if(!drag.resize)selected.forEach(id=>{let x=byId(id),b=drag.base[id];if(x.type!="skip"){x.x=Math.max(0,Math.min(100-x.w,b.x+dx));x.y=Math.max(0,Math.min(100-x.h,b.y+dy))}});
 else{let x=drag.o,b=drag.base[x.id],p=drag.resize;
  if(p.includes("e"))x.w=Math.max(5,Math.min(100-x.x,b.w+dx));
  if(p.includes("s"))x.h=Math.max(5,Math.min(100-x.y,b.h+dy));
  if(p.includes("w")){let n=Math.max(0,b.x+dx);x.w=Math.max(5,b.w+b.x-n);x.x=n}
  if(p.includes("n")){let n=Math.max(0,b.y+dy);x.h=Math.max(5,b.h+b.y-n);x.y=n}}
 render();
}
function end(){
 if(!drag.moved&&!drag.resize)selected=drag.m?[...new Set([...selected,drag.o.id])]:[drag.o.id];
 drag=null;removeEventListener("pointermove",move);render();
}

/* ---- 接続線 ---- */
const rp=e=>{let r=scr.getBoundingClientRect();return{x:e.x/100*r.width,y:e.y/100*r.height,w:e.w/100*r.width,h:e.h/100*r.height}};
function anchor(e,s){let r=rp(e),cx=r.x+r.w/2,cy=r.y+r.h/2;
 return s=="top"?{x:cx,y:r.y,s}:s=="bottom"?{x:cx,y:r.y+r.h,s}:s=="left"?{x:r.x,y:cy,s}:{x:r.x+r.w,y:cy,s}}
function autoSide(a,b){let A=rp(a),B=rp(b),dx=B.x+B.w/2-A.x-A.w/2,dy=B.y+B.h/2-A.y-A.h/2;
 return Math.abs(dx)>Math.abs(dy)?[dx>0?"right":"left",dx>0?"left":"right"]:[dy>0?"bottom":"top",dy>0?"top":"bottom"]}
const out=(p,d)=>({x:p.x+(p.s=="left"?-d:p.s=="right"?d:0),y:p.y+(p.s=="top"?-d:p.s=="bottom"?d:0)});
function hit(a,b,r){
 let x1=r.x-4,y1=r.y-4,x2=r.x+r.w+4,y2=r.y+r.h+4,dx=b.x-a.x,dy=b.y-a.y,t0=0,t1=1;
 for(let [p,q] of [[-dx,a.x-x1],[dx,x2-a.x],[-dy,a.y-y1],[dy,y2-a.y]]){
  if(!p){if(q<0)return false}else{let t=q/p;if(p<0){if(t>t1)return false;t0=Math.max(t0,t)}else{if(t<t0)return false;t1=Math.min(t1,t)}}}
 return true}
function route(form,A,B,obst){
 if(form=="straight")return[A,B];
 let a=out(A,18),b=out(B,18),h=A.s=="left"||A.s=="right";
 let xs=[a.x,b.x,...obst.flatMap(r=>[r.x,r.x+r.w])],ys=[a.y,b.y,...obst.flatMap(r=>[r.y,r.y+r.h])];
 let c=[[A,a,h?{x:b.x,y:a.y}:{x:a.x,y:b.y},b,B],[A,a,h?{x:a.x,y:b.y}:{x:b.x,y:a.y},b,B],
  ...[Math.min(...ys)-14,Math.max(...ys)+14].map(v=>[A,a,{x:a.x,y:v},{x:b.x,y:v},b,B]),
  ...[Math.min(...xs)-14,Math.max(...xs)+14].map(v=>[A,a,{x:v,y:a.y},{x:v,y:b.y},b,B])];
 return c.find(p=>!p.slice(1).some((q,i)=>obst.some(r=>hit(p[i],q,r))))||c[0]}
function toPath(pts,form){
 if(form!="wave")return"M"+pts.map(p=>p.x.toFixed(1)+" "+p.y.toFixed(1)).join("L");
 let seg=pts.slice(1).map((q,i)=>Math.hypot(q.x-pts[i].x,q.y-pts[i].y)),total=seg.reduce((a,b)=>a+b,0)||1,N=60,d="";
 for(let i=0;i<=N;i++){
  let s=i/N*total,k=0;while(k<seg.length-1&&s>seg[k]){s-=seg[k];k++}
  let p=pts[k],q=pts[k+1],l=seg[k]||1,u=s/l,nx=-(q.y-p.y)/l,ny=(q.x-p.x)/l,o=Math.sin(i/N*Math.PI*2)*10;
  d+=(i?"L":"M")+(p.x+(q.x-p.x)*u+nx*o).toFixed(1)+" "+(p.y+(q.y-p.y)*u+ny*o).toFixed(1)}
 return d}
function marker(kind,id,c){if(kind=="none")return"";
 let s=kind=="arrow"?'<path d="M0 0L10 5L0 10Z"':kind=="circle"?'<circle cx="5" cy="5" r="4"':'<rect x="1" y="1" width="8" height="8"';
 return`<marker id="${id}" markerWidth="10" markerHeight="10" refX="${kind=="arrow"?9:5}" refY="5" orient="auto-start-reverse" markerUnits="userSpaceOnUse">${s} fill="${c}"/></marker>`}
function drawLines(){
 let defs="",body="";lines=lines.filter(L=>byId(L.from)&&byId(L.to));
 lines.forEach(L=>{
  let a=byId(L.from),b=byId(L.to);if(!active(a)||!active(b))return;
  let au=autoSide(a,b),A=anchor(a,L.fs=="auto"?au[0]:L.fs),B=anchor(b,L.ts=="auto"?au[1]:L.ts);
  let obst=els.filter(e=>e.type!="skip"&&e!=a&&e!=b&&active(e)).map(rp),d=toPath(route(L.form,A,B,obst),L.form),sel=selLine==L.id,c=sel?"#4da3ff":"#90a4ae";
  defs+=marker(L.se,"ms"+L.id,c)+marker(L.ee,"me"+L.id,c);
  body+=`<path class="hit" data-line="${L.id}" d="${d}"/><path class="line${sel?" sel":""}" d="${d}"${DASH[L.dash]?` stroke-dasharray="${DASH[L.dash]}"`:""}${L.se!="none"?` marker-start="url(#ms${L.id})"`:""}${L.ee!="none"?` marker-end="url(#me${L.id})"`:""}/>`;
  if(sel)body+=[[A,"from"],[B,"to"]].map(([p,k])=>`<circle class="endh" data-end="${k}" data-line="${L.id}" cx="${p.x}" cy="${p.y}" r="6"/>`).join("");
 });
 let src=connect&&byId(connect);
 if(src&&mouse&&active(src)){
  let r=rp(src),cx=r.x+r.w/2,cy=r.y+r.h/2,s=Math.abs(mouse.x-cx)>Math.abs(mouse.y-cy)?(mouse.x>cx?"right":"left"):(mouse.y>cy?"bottom":"top"),A=anchor(src,s);
  body+=`<path class="line pre" d="M${A.x} ${A.y}L${mouse.x} ${mouse.y}"/><circle cx="${mouse.x}" cy="${mouse.y}" r="4" fill="#ffd54f"/>`;
 }
 svg.innerHTML=`<defs>${defs}</defs>`+body;
 svg.querySelectorAll(".hit").forEach(p=>{
  p.onclick=e=>{e.stopPropagation();selLine=+p.dataset.line;selected=[];render()};
  p.oncontextmenu=e=>{e.preventDefault();e.stopPropagation();selLine=+p.dataset.line;selected=[];render();lineMenu(e.clientX,e.clientY,selLine)}});
 svg.querySelectorAll(".endh").forEach(h=>h.onpointerdown=e=>{e.stopPropagation();e.preventDefault();endDrag=h.dataset;
  addEventListener("pointermove",endMove);addEventListener("pointerup",()=>{endDrag=null;removeEventListener("pointermove",endMove)},{once:true})});
}
function endMove(e){
 let r=scr.getBoundingClientRect(),L=lines.find(l=>l.id==endDrag.line),k=endDrag.end=="from",el=byId(k?L.from:L.to);
 L[k?"fs":"ts"]=SIDES.map(s=>({s,d:Math.hypot(anchor(el,s).x-(e.clientX-r.left),anchor(el,s).y-(e.clientY-r.top))})).sort((a,b)=>a.d-b.d)[0].s;render()}

/* ---- メニュー ---- */
function showMenu(x,y,h){menu.innerHTML=h;menu.style.display="block";menu.style.left=Math.min(x,innerWidth-230)+"px";menu.style.top=Math.max(5,Math.min(y,innerHeight-menu.offsetHeight-5))+"px"}
const btns=(items,cur,key)=>items.map(([k,n])=>`<button${k==cur?' class="cur"':''} data-l="${key}:${k}">${n}</button>`).join("");
function lineMenu(x,y,id){
 let L=lines.find(l=>l.id==id),E=[["none","なし"],["arrow","矢印"],["circle","丸"],["square","四角"]],S=[["auto","自動"],...SIDES.map(s=>[s,SJ[s]])];
 mt={line:id};
 showMenu(x,y,`<b>接続線</b><hr>線形${btns([["straight","直線"],["elbow","折れ線"],["wave","波線"]],L.form,"form")}<hr>線種${btns([["solid","実線"],["dotted","点線"],["dashed","破線"],["dashdot","一点鎖線"]],L.dash,"dash")}<hr>始点の終端${btns(E,L.se,"se")}<hr>終点の終端${btns(E,L.ee,"ee")}<hr>始点の接続位置${btns(S,L.fs,"fs")}<hr>終点の接続位置${btns(S,L.ts,"ts")}<hr><button data-l="del:1">接続線を削除</button>`);
}
function elMenu(x,y,e){
 mt={el:e.id};
 showMenu(x,y,`<b>${TYPES[e.type][0]}</b>`+(e.type=="comment"?'<button data-a="text">コメント変更</button>':"")+(e.type=="highlight"?'<button data-a="rect">直角</button><button data-a="round">角丸</button><button data-a="circle">円形</button>':"")+(e.type!="skip"?'<button data-a="connect">接続線を作成</button>':"")+'<button data-a="del">削除</button>');
}
scr.oncontextmenu=e=>{if(e.target.closest(".el,svg *"))return;e.preventDefault();cancelConnect();mt={x:e.clientX,y:e.clientY};
 showMenu(e.clientX,e.clientY,"<b>要素を追加</b>"+Object.entries(TYPES).map(([k,v])=>`<button data-add="${k}">${v[0]}</button>`).join(""))};
menu.onclick=e=>{
 let b=e.target.closest("button");if(!b)return;let d=b.dataset;
 if(d.l){let L=lines.find(l=>l.id==mt.line),[k,v]=d.l.split(":");
  if(k=="del"){lines=lines.filter(l=>l!=L);selLine=null;menu.style.display="none"}else{L[k]=v;lineMenu(parseFloat(menu.style.left),parseFloat(menu.style.top),L.id)}
  return render()}
 if(d.a){let x=byId(mt.el);
  if(d.a=="text"){let v=prompt("コメント",x.text);if(v!==null)x.text=v}
  else if(d.a=="connect"){
   if(!active(x)){current=x.start;if(video.src)video.currentTime=current}
   connect=x.id;selected=[x.id];let p=rp(x);mouse={x:p.x+p.w+40,y:p.y+p.h/2}}
  else if(d.a=="del"){els=els.filter(v=>v!=x);selected=[]}
  else x.shape=d.a;
  menu.style.display="none";return render()}
 if(d.add){let r=scr.getBoundingClientRect();
  els.push({id:next++,type:d.add,start:current,end:Math.min(duration,current+5),x:Math.max(0,Math.min(75,(mt.x-r.left)/r.width*100)),y:Math.max(0,Math.min(82,(mt.y-r.top)/r.height*100)),w:25,h:18,text:"新しいコメント"});
  menu.style.display="none";render()}
};

/* ---- タイムライン ---- */
/* 目盛りは主目盛りのみ。ラベル間隔を画面上で120px以上にして、密にならないようにする */
function niceStep(minSec){
 let p=Math.pow(10,Math.floor(Math.log10(minSec))),m=[1,2,5,10].find(k=>k*p>=minSec);return m*p}

/* 行割り当て: 開始時間順に、時間が重ならない最初の行へ入れる。重なる要素は必ず別の行になる */
function assignRows(){
 let rowEnd=[],map={};
 [...els].sort((a,b)=>a.start-b.start||a.end-b.end||a.id-b.id).forEach(e=>{
  let r=rowEnd.findIndex(t=>t<=e.start+1e-9);
  if(r<0){r=rowEnd.length;rowEnd.push(0)}
  rowEnd[r]=e.end;map[e.id]=r});
 return{map,count:Math.max(rowEnd.length,1)}}

function timeline(){
 let W=TW(),ax=$("axis"),pxSec=W/duration*scale,major=niceStep(120/pxSec);
 ax.innerHTML="";
 for(let t=0;t<duration;t+=major){
  let x=t/duration*W;
  if(t>0&&W-x<60)break;
  ax.insertAdjacentHTML("beforeend",`<div class="tick" style="left:${x}px">${fmtTick(t,major)}</div>`)}
 ax.insertAdjacentHTML("beforeend",`<div class="tick end" style="left:${W}px">${fmt(duration).slice(0,8)}</div>`);
 let {map,count}=assignRows(),h="";
 for(let r=0;r<count;r++){
  h+=`<div class="row"><div class="label">行 ${r+1}</div>`+els.filter(e=>map[e.id]==r).map(e=>{
   let [n,c]=TYPES[e.type],L=e.start/duration*W,R=Math.min(e.end/duration*W,W);
   return`<div class="bar${selected.includes(e.id)?" sel":""}" data-id="${e.id}" style="left:${L}px;width:${Math.max(24,R-L)}px;color:${c};background:${c}44"><i class="g gl" data-edge="l"></i>${n}<i class="g gr" data-edge="r"></i></div>`}).join("")+`</div>`}
 $("rows").innerHTML=h;
 $("rows").querySelectorAll(".bar").forEach(b=>{
  b.onpointerdown=startTdrag;
  b.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();let id=+b.dataset.id,e=byId(id);
   if(!selected.includes(id)){selected=[id];selLine=null;render()}
   elMenu(ev.clientX,ev.clientY,e)}});
 $("head").style.left=95+current/duration*W+"px";$("time").textContent=fmt(current)+" / "+fmt(duration);
}

/* バー操作: 端のハンドル=開始/終了の調整、それ以外=全体移動 */
function startTdrag(e){
 if(e.button!==0)return;e.stopPropagation();menu.style.display="none";
 let b=e.currentTarget,id=+b.dataset.id,o=byId(id),edge=e.target.dataset.edge,mode=edge||"m";
 if(!selected.includes(id))pick(id,e);
 tdrag={o,id,mode,sx:e.clientX,s:o.start,e:o.end,moved:false,ev:e};
 addEventListener("pointermove",tmove);addEventListener("pointerup",tend,{once:true});
}
function tmove(e){
 if(!tdrag.moved&&Math.abs(e.clientX-tdrag.sx)<3)return;tdrag.moved=true;
 let dt=(e.clientX-tdrag.sx)/TW()*duration,o=tdrag.o;
 if(tdrag.mode=="l")o.start=Math.max(0,Math.min(tdrag.e-.1,tdrag.s+dt));
 else if(tdrag.mode=="r")o.end=Math.min(duration,Math.max(tdrag.s+.1,tdrag.e+dt));
 else{let len=tdrag.e-tdrag.s,s=Math.max(0,Math.min(duration-len,tdrag.s+dt));o.start=s;o.end=s+len}
 render();
}
function tend(){
 removeEventListener("pointermove",tmove);
 if(!tdrag.moved)pick(tdrag.id,tdrag.ev);
 tdrag=null;render();
}

function seek(cx){current=Math.max(0,Math.min(duration,(cx-$("tl").getBoundingClientRect().left-95)/TW()*duration));if(video.src)video.currentTime=current;render()}
$("head").onpointerdown=e=>{e.preventDefault();e.stopPropagation();let m=ev=>seek(ev.clientX);addEventListener("pointermove",m);addEventListener("pointerup",()=>removeEventListener("pointermove",m),{once:true})};
$("tl").onclick=e=>{if(!e.target.closest(".bar")&&e.clientX-$("tl").getBoundingClientRect().left>=95)seek(e.clientX)};
$("scale").oninput=e=>{scale=+e.target.value;$("scaleText").textContent=scale.toFixed(1)+"×";render()};

/* ---- 動画・共通 ---- */
$("pick").onclick=()=>$("file").click();
$("file").onchange=e=>{let f=e.target.files[0];if(!f)return;video.src=URL.createObjectURL(f);video.onloadedmetadata=()=>{duration=video.duration;$("hint").style.display="none";$("status").textContent=f.name;render()}};
$("play").onclick=()=>video.src?(video.paused?video.play():video.pause()):toast("先に動画を選択してください");
$("stop").onclick=()=>{if(video.src){video.pause();video.currentTime=0}};
video.onplay=()=>$("play").textContent="❚❚ 一時停止";video.onpause=()=>$("play").textContent="▶ 再生";
video.ontimeupdate=()=>{current=video.currentTime;render()};
addEventListener("keydown",e=>{
 if(e.key=="Escape"){menu.style.display="none";cancelConnect();render()}
 if(e.key=="Delete"){if(selLine)lines=lines.filter(l=>l.id!=selLine);else els=els.filter(x=>!selected.includes(x.id));selLine=null;selected=[];render()}});
addEventListener("click",e=>{if(!e.target.closest(".menu"))menu.style.display="none"});
addEventListener("resize",render);render();
</script></body></html>
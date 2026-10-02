<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
body{margin:0;font:14px sans-serif;background:#f3f4f6;color:#222}
button{font:inherit;padding:5px 10px;border:1px solid #aaa;border-radius:4px;background:#fff;cursor:pointer}
.p{background:#2563eb;color:#fff}.d{color:#b91c1c}
.hidden{display:none!important}.wrap{max-width:1000px;margin:auto;padding:16px}
.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;margin-bottom:14px}
.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.sp{flex:1}.c{color:#666;font-size:13px}
.orig{border:2px solid #93c5fd;margin:10px 0;border-radius:6px;background:#f8fbff}
.oh{padding:8px;background:#dbeafe}.works{padding:8px 8px 8px 24px}
.work{padding:6px 0;border-bottom:1px dashed #ccc}
.empty{color:#777}
.top{padding:8px 12px;background:#1f2937;color:#fff}
.vwrap{max-width:760px;margin:12px auto;padding:0 12px}
.video{position:relative;height:360px;background:#000;overflow:hidden;user-select:none}
.video video{position:absolute;width:100%;height:100%;object-fit:contain;pointer-events:none}
.dummy{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#777;font-size:28px}
#layer{position:absolute;inset:0}
.ve{position:absolute;z-index:10;cursor:grab;touch-action:none;overflow:visible}
.ve:active{cursor:grabbing}
.ve.sel{outline:2px dashed #fbbf24}
.rz{position:absolute;right:0;bottom:0;width:12px;height:12px;background:#fbbf24;cursor:nwse-resize}
.connecting .ve{cursor:crosshair}
.source{outline:3px solid #22c55e!important}
.target{outline:3px dashed #38bdf8!important}
svg{position:absolute;inset:0;width:100%;height:100%;z-index:5;overflow:visible;pointer-events:none}
.conn-hit{fill:none;stroke:transparent;stroke-width:16;pointer-events:stroke;cursor:pointer}
.conn-line{fill:none;stroke-linecap:round;stroke-linejoin:round}
.temp{fill:none;stroke:#22c55e;stroke-width:2;stroke-dasharray:6 4}
.tw{max-width:900px;margin:auto;padding:0 12px 16px}
.num{background:#fff;border:1px solid #ddd;padding:4px 8px;border-radius:4px}
.tlrow{display:flex;gap:4px}.tl{position:relative;flex:1;background:#fff;border:1px solid #aaa;overflow:hidden}
.ruler{height:28px;position:relative;border-bottom:1px solid #aaa;background:#fafafa}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #aaa;font-size:10px;padding-left:3px}
.lanes{height:150px;position:relative;cursor:grab}
.el{position:absolute;height:22px;color:#fff;padding:3px 6px;border-radius:3px;overflow:hidden;white-space:nowrap}
.el.sel{outline:2px solid #fbbf24}
.bar{height:28px;margin:8px 6px;background:#e5e7eb;border:1px solid #aaa;position:relative}
.view{position:absolute;top:0;bottom:0;background:#93c5fd88;border:2px solid #2563eb}
.hd{position:absolute;top:-2px;bottom:-2px;width:10px;background:#2563eb;cursor:ew-resize}.hd.l{left:-6px}.hd.r{right:-6px}
.cur{position:absolute;top:0;bottom:0;border-left:2px solid #e11d48}
.mask{position:fixed;inset:0;background:#0006;display:flex;align-items:center;justify-content:center;z-index:50}
.modal{background:#fff;padding:16px;border-radius:6px;max-width:430px;width:90%}
.btns{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.cm{position:fixed;z-index:60;background:#fff;border:1px solid #aaa;border-radius:5px;padding:6px;box-shadow:0 3px 12px #0003;min-width:180px}
.cm button{display:block;width:100%;text-align:left;border:0}.cm button:hover{background:#eef6ff}
</style>
</head>
<body>

<div id="home" class="wrap">
<div class="card">
<h2>動画を選択</h2>
<p class="c">編集する動画を選択してください。</p>
<button class="p" id="pick">動画を選択</button>
<input id="file" type="file" accept="video/*" class="hidden">
</div>
<div class="card">
<h2>オリジナル動画 <span id="oc" class="c"></span></h2>
<div id="ol"></div>
</div>
<div class="card">
<h2>編集結果の動画 <span id="rc" class="c"></span></h2>
<div id="rl"></div>
</div>
</div>

<div id="editor" class="hidden">
<div class="top row">
<b>動画: <span id="ev"></span></b>
<b>編集作業: <span id="wn"></span></b>
<span id="dirty" class="hidden">（未保存の変更あり）</span>
<span class="sp"></span>
<button id="end">編集作業を終了する</button>
</div>

<div class="vwrap"><div class="video" id="vb"></div></div>

<div class="tw">
<div class="row">
<button id="play">▶ 再生</button>
<button id="pause">⏸ 一時停止</button>
<button id="stop">■ 停止</button>
<span id="pos" class="num"></span>
<span class="sp"></span>
<button id="fit">全体表示</button>
</div>
<div class="row"><span id="range" class="num"></span></div>
<div class="tlrow">
<button id="prev">◀</button>
<div class="tl" id="tl">
<div id="ruler" class="ruler"></div>
<div id="lanes" class="lanes"></div>
</div>
<button id="next">▶</button>
</div>
<div id="bar" class="bar"></div>
</div>
</div>

<div id="modal"></div>
<div id="menu"></div>

<script>
(function(){
"use strict";

var $=function(id){return document.getElementById(id)};
var uid=0,MIN=.5,LEN=5;
var TYPES={
comment:{name:"コメント",color:"#2563eb"},
hl:{name:"強調枠",color:"#dc2626"}
};

var videos=[
{id:"v1",name:"操作手順_顧客登録.mp4",date:new Date(2026,8,20,10),dur:120},
{id:"v2",name:"障害再現_決済画面.mp4",date:new Date(2026,8,25,14,30),dur:75}
];

var works=[
{id:"w1",vid:"v1",name:"顧客登録_注釈入り",date:new Date(2026,8,21,11)}
];

var results=[];
var ed=null,drag=null,connect=null;

function id(p){return p+(++uid)}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]})}
function find(a,x){return a.filter(function(v){return v.id===x})[0]}
function clamp(v,a,b){return Math.max(a,Math.min(b,v))}
function time(v){var m=Math.floor(v/60),s=Math.floor(v%60);return m+":"+(s<10?"0":"")+s}
function date(v){return v.getFullYear()+"/"+(v.getMonth()+1)+"/"+v.getDate()+" "+v.getHours()+":"+("0"+v.getMinutes()).slice(-2)}

function home(){
$("oc").textContent="（"+videos.length+"/10件）";
$("rc").textContent="（"+results.length+"/10件）";

$("ol").innerHTML=videos.map(function(v){
var ws=works.filter(function(w){return w.vid===v.id});
return '<div class="orig"><div class="oh row"><b>'+esc(v.name)+'</b><span class="c">取り込み: '+date(v.date)+'</span><span class="sp"></span><button class="p" data-a="new" data-id="'+v.id+'">新しい編集作業を始める</button><button class="d" data-a="delv" data-id="'+v.id+'">削除</button></div><div class="works">'+
(ws.length?ws.map(function(w){return '<div class="work row"><span>'+esc(w.name)+'</span><span class="c">保存: '+date(w.date)+'</span><span class="sp"></span><button data-a="open" data-id="'+w.id+'">再開</button><button class="d" data-a="delw" data-id="'+w.id+'">削除</button></div>'}).join(""):'<div class="empty">編集作業はありません</div>')+
'</div></div>';
}).join("");

$("rl").innerHTML=results.length?results.map(function(r){return '<div class="work">'+esc(r.name)+'</div>'}).join(""):'<div class="empty">編集結果はありません</div>';
}

$("ol").onclick=function(e){
var b=e.target.closest("[data-a]");if(!b)return;
var x=b.dataset.id,a=b.dataset.a;
if(a==="new")openEditor(x);
if(a==="open"){var w=find(works,x);openEditor(w.vid,w)}
if(a==="delw"){works=works.filter(function(w){return w.id!==x});home()}
if(a==="delv"){videos=videos.filter(function(v){return v.id!==x});works=works.filter(function(w){return w.vid!==x});home()}
};

$("pick").onclick=function(){$("file").click()};
$("file").onchange=function(){
var f=this.files[0];if(!f)return;
var u=URL.createObjectURL(f),v=document.createElement("video");
v.preload="metadata";
v.onloadedmetadata=function(){
videos.push({id:id("v"),name:f.name,date:new Date(),dur:v.duration,url:u});
openEditor(videos[videos.length-1].id);
};
v.src=u;
};

function openEditor(vid,w){
var v=find(videos,vid);
ed={
vid:vid,dur:v.dur,pos:0,vs:0,ve:v.dur,video:null,
els:[],connections:[],sel:[],dirty:false,timer:null
};

if(w){
var a=id("e"),b=id("e");
ed.els=[
{id:a,type:"comment",s:v.dur*.05,e:v.dur*.25,x:.12,y:.12,w:.28,h:.16,text:"コメント"},
{id:b,type:"hl",s:v.dur*.18,e:v.dur*.42,x:.55,y:.35,w:.25,h:.25}
];
ed.connections=[{id:id("c"),from:a,to:b,color:"#f59e0b",width:3}];
}

$("home").classList.add("hidden");
$("editor").classList.remove("hidden");
$("ev").textContent=v.name;
$("wn").textContent=w?w.name:"未保存の編集作業";
$("vb").innerHTML="";

if(v.url){
ed.video=document.createElement("video");
ed.video.src=v.url;
$("vb").appendChild(ed.video);
}else{
var d=document.createElement("div");d.className="dummy";d.textContent="動画領域";$("vb").appendChild(d);
}

var layer=document.createElement("div");
layer.id="layer";
$("vb").appendChild(layer);
draw();
}

function dirty(){
ed.dirty=true;
$("dirty").classList.remove("hidden");
}

function draw(){
drawVideo();
drawTimeline();
}

function drawVideo(){
var l=$("layer");
l.innerHTML='<svg id="svg" viewBox="0 0 100 100" preserveAspectRatio="none"></svg>';

ed.els.forEach(function(e){
if(ed.pos<e.s||ed.pos>e.e)return;

var n=document.createElement("div");
n.className="ve"+(ed.sel.indexOf(e.id)>=0?" sel":"");
if(connect&&connect.from===e.id)n.classList.add("source");
if(connect&&connect.target===e.id)n.classList.add("target");
n.dataset.id=e.id;
n.style.left=e.x*100+"%";
n.style.top=e.y*100+"%";
n.style.width=e.w*100+"%";
n.style.height=e.h*100+"%";
n.style.background=e.type==="comment"?"#fff":"#dc262622";
n.style.border=e.type==="comment"?"2px solid #2563eb":"3px solid #dc2626";
n.style.color="#111";
n.style.padding="5px";
n.textContent=e.type==="comment"?e.text:"強調枠";

if(ed.sel.indexOf(e.id)>=0){
var r=document.createElement("div");
r.className="rz";
r.dataset.resize=e.id;
n.appendChild(r);
}

l.appendChild(n);
});

drawConnections();
}

function point(e){
var r=$("vb").getBoundingClientRect();
return{x:clamp((e.clientX-r.left)/r.width,0,1),y:clamp((e.clientY-r.top)/r.height,0,1)}
}

function anchor(e){
return{x:e.x+e.w/2,y:e.y+e.h/2}
}

function drawConnections(){
var svg=$("svg");if(!svg)return;

ed.connections.forEach(function(c){
var a=find(ed.els,c.from),b=find(ed.els,c.to);if(!a||!b)return;
var p=anchor(a),q=anchor(b);
var line=document.createElementNS("http://www.w3.org/2000/svg","path");
line.setAttribute("d","M"+p.x*100+" "+p.y*100+" L"+q.x*100+" "+q.y*100);
line.setAttribute("stroke",c.color);
line.setAttribute("stroke-width",c.width/2);
line.setAttribute("class","conn-line");
svg.appendChild(line);

var hit=document.createElementNS("http://www.w3.org/2000/svg","path");
hit.setAttribute("d","M"+p.x*100+" "+p.y*100+" L"+q.x*100+" "+q.y*100);
hit.setAttribute("class","conn-hit");
hit.dataset.cid=c.id;
svg.appendChild(hit);
});

if(connect){
var p=connect.start,q=connect.current;
var t=document.createElementNS("http://www.w3.org/2000/svg","path");
t.setAttribute("d","M"+p.x*100+" "+p.y*100+" L"+q.x*100+" "+q.y*100);
t.setAttribute("class","temp");
svg.appendChild(t);
}
}

$("vb").addEventListener("pointerdown",function(e){
if(e.button!==0)return;

var n=e.target.closest(".ve");
if(!n)return;

var el=find(ed.els,n.dataset.id);
if(!el)return;

if(e.target.dataset.resize){
resize(el,e);
return;
}

if(e.shiftKey){
ed.sel.indexOf(el.id)>=0?ed.sel.splice(ed.sel.indexOf(el.id),1):ed.sel.push(el.id);
draw();
return;
}

/*
通常のドラッグは要素移動。
移動が発生した場合だけ、接続線作成ではなく
そのまま要素移動として確定する。
*/
ed.sel=[el.id];

var r=$("vb").getBoundingClientRect();
var sx=e.clientX,sy=e.clientY,ox=el.x,oy=el.y,moved=false;

function move(ev){
var dx=(ev.clientX-sx)/r.width;
var dy=(ev.clientY-sy)/r.height;

if(Math.abs(dx)+Math.abs(dy)>.005)moved=true;

el.x=clamp(ox+dx,0,1-el.w);
el.y=clamp(oy+dy,0,1-el.h);

dirty();
draw();
}

function up(){
window.removeEventListener("pointermove",move);
window.removeEventListener("pointerup",up);
drag=null;
}

drag={type:"move"};
window.addEventListener("pointermove",move);
window.addEventListener("pointerup",up);
e.preventDefault();
});

$("vb").addEventListener("pointerdown",function(e){
if(e.button!==0||e.target.closest(".ve"))return;
ed.sel=[];
draw();
});

function resize(el,e){
var r=$("vb").getBoundingClientRect(),sx=e.clientX,sy=e.clientY,ow=el.w,oh=el.h;
function move(ev){
el.w=clamp(ow+(ev.clientX-sx)/r.width,.05,1-el.x);
el.h=clamp(oh+(ev.clientY-sy)/r.height,.05,1-el.y);
dirty();draw();
}
function up(){window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up)}
window.addEventListener("pointermove",move);
window.addEventListener("pointerup",up);
e.preventDefault();
}

function startConnection(el,e){
var p=point(e);
connect={from:el.id,start:p,current:p,target:null};
$("vb").classList.add("connecting");

function move(ev){
connect.current=point(ev);
var n=document.elementFromPoint(ev.clientX,ev.clientY);
var x=n&&n.closest(".ve");
connect.target=x?find(ed.els,x.dataset.id):null;
if(connect.target&&connect.target.id===connect.from)connect.target=null;
draw();
}

function up(ev){
window.removeEventListener("pointermove",move);
window.removeEventListener("pointerup",up);
if(connect&&connect.target){
ed.connections.push({id:id("c"),from:connect.from,to:connect.target.id,color:"#f59e0b",width:3});
dirty();
}
connect=null;
$("vb").classList.remove("connecting");
draw();
}

window.addEventListener("pointermove",move);
window.addEventListener("pointerup",up);
e.preventDefault();
}

document.addEventListener("contextmenu",function(e){
if(!ed||$("editor").classList.contains("hidden"))return;
var n=e.target.closest(".ve");
if(!n)return;
e.preventDefault();
var el=find(ed.els,n.dataset.id);
menu(e.clientX,e.clientY,el);
});

function menu(x,y,el){
$("menu").innerHTML="";
var m=document.createElement("div");
m.className="cm";
m.style.left=Math.min(x,innerWidth-190)+"px";
m.style.top=Math.min(y,innerHeight-120)+"px";

var b=document.createElement("button");
b.textContent="接続線を作成";
b.onclick=function(){
$("menu").innerHTML="";
startConnection(el,{clientX:x,clientY:y});
};
m.appendChild(b);

var d=document.createElement("button");
d.textContent="削除";
d.className="d";
d.onclick=function(){
ed.els=ed.els.filter(function(v){return v.id!==el.id});
ed.connections=ed.connections.filter(function(c){return c.from!==el.id&&c.to!==el.id});
ed.sel=[];dirty();$("menu").innerHTML="";draw();
};
m.appendChild(d);
$("menu").appendChild(m);
}

document.addEventListener("pointerdown",function(e){
if(!e.target.closest(".cm"))$("menu").innerHTML="";
});

function drawTimeline(){
var w=$("tl").clientWidth,sp=ed.ve-ed.vs;
var h="";
for(var t=Math.ceil(ed.vs/10)*10;t<=ed.ve;t+=10)
h+='<div class="tick" style="left:'+((t-ed.vs)/sp*w)+'px">'+time(t)+'</div>';
$("ruler").innerHTML=h;

var rows=[],html="";
ed.els.forEach(function(e){
var row=0;
while(rows[row]>e.s)row++;
rows[row]=e.e;
if(e.e<ed.vs||e.s>ed.ve)return;
var x=(e.s-ed.vs)/sp*w;
var ww=(e.e-e.s)/sp*w;
html+='<div class="el '+(ed.sel.indexOf(e.id)>=0?"sel":"")+'" data-id="'+e.id+'" style="left:'+x+'px;width:'+Math.max(3,ww)+'px;top:'+(8+row*28)+'px;background:'+TYPES[e.type].color+'">'+(e.type==="comment"?esc(e.text):"強調枠")+'</div>';
});
$("lanes").innerHTML=html;
$("range").textContent="表示範囲: "+time(ed.vs)+" ～ "+time(ed.ve);
$("pos").textContent="再生位置: "+time(ed.pos)+" / "+time(ed.dur);

var b=$("bar");
b.innerHTML='<div class="view" style="left:'+ed.vs/ed.dur*100+'%;width:'+(ed.ve-ed.vs)/ed.dur*100+'%"><i class="hd l"></i><i class="hd r"></i></div>';
}

$("lanes").addEventListener("pointerdown",function(e){
var n=e.target.closest(".el");
if(!n)return;
var el=find(ed.els,n.dataset.id);
ed.sel=[el.id];
var r=$("tl").getBoundingClientRect(),sx=e.clientX,os=el.s,oe=el.e;
function move(ev){
var dt=(ev.clientX-sx)/r.width*(ed.ve-ed.vs);
var len=oe-os;
el.s=clamp(os+dt,0,ed.dur-len);
el.e=el.s+len;
dirty();draw();
}
function up(){window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up)}
window.addEventListener("pointermove",move);
window.addEventListener("pointerup",up);
e.preventDefault();
});

$("play").onclick=function(){
if(ed.timer)return;
var last=performance.now();
ed.timer=setInterval(function(){
var now=performance.now();
ed.pos=Math.min(ed.dur,ed.pos+(now-last)/1000);
last=now;
if(ed.video)ed.video.currentTime=ed.pos;
if(ed.pos>=ed.dur){clearInterval(ed.timer);ed.timer=null}
draw();
},50);
if(ed.video)ed.video.play();
};

$("pause").onclick=function(){
clearInterval(ed.timer);ed.timer=null;
if(ed.video)ed.video.pause();
};

$("stop").onclick=function(){
clearInterval(ed.timer);ed.timer=null;
ed.pos=0;
if(ed.video)ed.video.currentTime=0;
draw();
};

$("fit").onclick=function(){ed.vs=0;ed.ve=ed.dur;draw()};

$("prev").onclick=function(){
var sp=ed.ve-ed.vs;
ed.vs=clamp(ed.vs-sp*.5,0,ed.dur-sp);
ed.ve=ed.vs+sp;
draw();
};

$("next").onclick=function(){
var sp=ed.ve-ed.vs;
ed.vs=clamp(ed.vs+sp*.5,0,ed.dur-sp);
ed.ve=ed.vs+sp;
draw();
};

$("bar").addEventListener("pointerdown",function(e){
if(!e.target.closest(".view"))return;
var r=$("bar").getBoundingClientRect(),sx=e.clientX,os=ed.vs,sp=ed.ve-ed.vs;
function move(ev){
var d=(ev.clientX-sx)/r.width*ed.dur;
ed.vs=clamp(os+d,0,ed.dur-sp);
ed.ve=ed.vs+sp;
draw();
}
function up(){window.removeEventListener("pointermove",move);window.removeEventListener("pointerup",up)}
window.addEventListener("pointermove",move);
window.addEventListener("pointerup",up);
});

$("end").onclick=function(){
clearInterval(ed.timer);
$("editor").classList.add("hidden");
$("home").classList.remove("hidden");
home();
};

window.addEventListener("resize",function(){if(ed)draw()});
home();

})();
</script>
</body>
</html>

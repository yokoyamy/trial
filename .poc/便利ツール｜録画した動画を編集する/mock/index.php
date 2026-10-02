<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;overflow-x:hidden;font:14px sans-serif;background:#f3f4f6;color:#222}
button{font:inherit;cursor:pointer;border:1px solid #9ca3af;background:#fff;border-radius:4px;padding:4px 10px}
button:disabled{opacity:.4;cursor:default}
.p{background:#2563eb;color:#fff;border-color:#1d4ed8}.d{color:#b91c1c;border-color:#fca5a5}
h2{font-size:16px;margin:0 0 8px}.wrap{max-width:1000px;margin:0 auto;padding:16px}.hidden{display:none!important}
.card{background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:12px;margin-bottom:16px}
.c{color:#6b7280;font-size:13px}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.sp{flex:1}
.orig{border:2px solid #93c5fd;border-radius:6px;margin:10px 0;background:#f8fbff}
.oh{padding:8px;background:#dbeafe}.works{margin:8px 8px 8px 28px;border-left:3px solid #93c5fd;padding-left:10px}
.work{padding:5px 0;border-bottom:1px dashed #d1d5db}.empty{color:#6b7280;font-style:italic}
.mask{position:fixed;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;z-index:50}
.modal{background:#fff;border-radius:6px;padding:16px;max-width:460px;width:92%}
.btns{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
.top{padding:8px 12px;background:#1f2937;color:#fff}
.vwrap{max-width:700px;margin:12px auto;padding:0 12px}
.video{position:relative;background:#000;color:#fff;height:300px;overflow:hidden;user-select:none}
.video video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none}
.dummy{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:28px;color:#9ca3af;pointer-events:none}
.ve{position:absolute;cursor:move;overflow:hidden}
.ve.sel{outline:2px dashed #fbbf24;outline-offset:1px}
.rz{position:absolute;right:0;bottom:0;width:12px;height:12px;background:#fbbf24;cursor:nwse-resize}
.tw{max-width:900px;margin:0 auto;padding:0 12px 16px}.tw .row{margin-bottom:8px}
.num{background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:13px}
.tlrow{display:flex;gap:4px}.tlrow>button{padding:0 10px;font-size:16px}
.tl{position:relative;flex:1;min-width:0;background:#fff;border:1px solid #9ca3af;border-radius:4px;overflow:hidden;user-select:none}
.ruler{position:relative;height:28px;border-bottom:1px solid #9ca3af;background:#f9fafb;cursor:pointer}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #9ca3af}.tick span{position:absolute;top:2px;left:3px;font-size:11px}
.lanes{position:relative;height:150px;cursor:grab}
.el{position:absolute;height:22px;border-radius:3px;color:#fff;font-size:11px;padding:2px 6px;overflow:hidden;white-space:nowrap;cursor:pointer}
.el.sel{outline:2px solid #fbbf24}
.end{position:absolute;top:0;bottom:0;border-left:2px solid #dc2626;font-size:10px;color:#dc2626;padding-left:3px}
.cur{position:absolute;top:0;bottom:0;border-left:2px solid #e11d48;pointer-events:none}
.cur i{position:absolute;top:0;left:-9px;width:16px;height:16px;background:#e11d48;border:2px solid #fff;border-radius:50%;box-shadow:0 0 2px #0006;cursor:ew-resize;pointer-events:auto}
.bar{position:relative;height:30px;background:#e5e7eb;border:1px solid #9ca3af;border-radius:4px;margin:8px 6px 0;user-select:none}
.mk{position:absolute;top:11px;height:8px;background:rgba(100,116,139,.45)}
.pc{position:absolute;top:0;bottom:0;width:2px;background:rgba(225,29,72,.6)}
.view{position:absolute;top:0;bottom:0;background:rgba(37,99,235,.25);border-top:2px solid #2563eb;border-bottom:2px solid #2563eb;cursor:grab}
.hd{position:absolute;top:-2px;bottom:-2px;width:12px;background:#2563eb;cursor:ew-resize;border-radius:3px}
.hd.l{left:-6px}.hd.r{right:-6px}
.cm{position:fixed;z-index:60;background:#fff;border:1px solid #9ca3af;border-radius:6px;box-shadow:0 4px 12px #0003;padding:6px;min-width:190px;max-width:260px}
.cm button{display:block;width:100%;text-align:left;border:0;margin:1px 0}
.cm button:hover{background:#eff6ff}
.cm label{display:flex;gap:6px;align-items:center;margin:4px 2px;font-size:13px}
.cm hr{border:0;border-top:1px solid #e5e7eb;margin:4px 0}
.cm .t{font-size:12px;color:#6b7280;padding:2px 4px}
.sw{display:flex;flex-wrap:wrap;gap:3px;margin:2px}
.sw b{width:20px;height:20px;border:1px solid #9ca3af;border-radius:3px;cursor:pointer}
.sw b.on{outline:2px solid #2563eb}
.ch{display:flex;gap:3px;margin:2px;flex-wrap:wrap}
.ch button{width:auto;border:1px solid #d1d5db;text-align:center;padding:2px 6px}
.ch button.on{background:#dbeafe;border-color:#2563eb}
</style></head><body>
<div id="home" class="wrap">
 <div class="card"><h2>動画を選択</h2><p class="c">現在、編集対象の動画は選択されていません。</p>
  <button class="p" id="pick">動画を選択</button><input type="file" id="file" accept="video/*" class="hidden"></div>
 <div class="card"><h2>オリジナル動画と編集作業 <span class="c" id="oc"></span></h2><div id="ol"></div></div>
 <div class="card"><h2>編集結果の動画 <span class="c" id="rc"></span></h2><div id="rl"></div></div>
</div>
<div id="editor" class="hidden">
 <div class="top row"><b>動画: <span id="ev"></span></b><b>編集作業: <span id="wn"></span></b><span id="dirty" class="hidden">（未保存の変更あり）</span><span class="sp"></span><button id="end">編集作業を終了する</button></div>
 <div class="vwrap"><div class="video" id="vb"></div></div>
 <div class="tw">
  <div class="row"><button id="play">▶ 再生</button><button id="pause">⏸ 一時停止</button><button id="stopb">■ 停止</button><span class="num" id="pos"></span><span class="sp"></span>
   <button id="out">−</button><input type="range" id="zoom" min="0" max="1000" value="0" style="width:160px"><button id="in">＋</button>
   <span class="num" id="zt"></span><button id="fit">全体表示に戻す</button></div>
  <div class="row"><span class="num" id="rt"></span></div>
  <div class="tlrow"><button id="bl">◀</button>
   <div class="tl" id="tl"><div class="ruler" id="ruler"></div><div class="lanes" id="lanes"></div></div>
  <button id="br">▶</button></div>
  <div class="bar" id="bar"></div>
 </div>
</div>
<div id="mr"></div>
<div id="cmr"></div>
<script>
(function(){
var LIM=10,MIN=0.5,MINLEN=0.5,DEFLEN=5,seq=1,$=function(i){return document.getElementById(i)};
var uid=function(p){return p+seq++},z=function(n){return("0"+n).slice(-2)};
var esc=function(t){return String(t).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]})};
var fd=function(d){return d.getFullYear()+"/"+z(d.getMonth()+1)+"/"+z(d.getDate())+" "+z(d.getHours())+":"+z(d.getMinutes())};
var ft=function(s,n){var m=Math.floor(s/60),r=s-m*60;return m+":"+(r<10?"0":"")+r.toFixed(n)};
var find=function(a,id){return a.filter(function(x){return x.id===id})[0]};
var clamp=function(v,a,b){return Math.max(a,Math.min(b,v))};
var COLORS=["#000000","#ffffff","#dc2626","#ea580c","#eab308","#16a34a","#059669","#0891b2","#2563eb","#7c3aed","#db2777","#6b7280"];
var FONTS=["sans-serif","serif","monospace","cursive"];
var WIDTHS=[1,2,4,6];
var DASH={"実線":"","点線":"2 4","破線":"8 4","一点鎖線":"10 4 2 4"};
var TYPES={comment:{n:"コメント",c:"#2563eb"},hl:{n:"強調枠",c:"#dc2626"},zoom:{n:"拡大枠",c:"#059669"},skip:{n:"スキップ",c:"#6b7280"}};
var videos=[{id:"v0",name:"操作手順_顧客登録.mp4",at:new Date(2026,8,20,10,0),dur:120},{id:"v00",name:"障害再現_決済画面.mp4",at:new Date(2026,8,25,14,30),dur:75}];
var works=[{id:"w0",vid:"v0",name:"顧客登録_注釈入り",saved:new Date(2026,8,21,11,0)},{id:"w1",vid:"v0",name:"顧客登録_短縮版",saved:new Date(2026,8,22,16,45)}];
var results=[{id:"r0",name:"顧客登録_完成版",vn:"操作手順_顧客登録.mp4",at:new Date(2026,8,23,9,0)}];

function modal(h,bs){$("mr").innerHTML='<div class="mask"><div class="modal">'+h+'<div class="btns" id="mb"></div></div></div>';
 bs.forEach(function(b){var e=document.createElement("button");e.textContent=b[0];e.className=b[2]||"";
  e.onclick=function(){$("mr").innerHTML="";if(b[1])b[1]()};$("mb").appendChild(e)})}
function conf(m,fn){modal("<p>"+m+"</p>",[["キャンセル"],["削除する",fn,"d"]])}
function cnt(id,n){$(id).textContent="（"+n+"/"+LIM+"件）"}

function home(){
 cnt("oc",videos.length);cnt("rc",results.length);var h="";
 videos.forEach(function(v){
  var ws=works.filter(function(w){return w.vid===v.id}).sort(function(a,b){return b.saved-a.saved});
  h+='<div class="orig"><div class="oh row"><b>'+esc(v.name)+'</b><span class="c">取り込み: '+fd(v.at)+' ／ 編集作業: '+ws.length+'件</span><span class="sp"></span>'+
   '<button class="p" data-a="new" data-i="'+v.id+'">新しい編集作業を始める</button><button class="d" data-a="dv" data-i="'+v.id+'">削除</button></div><div class="works">'+
   (ws.length?"":'<div class="empty">編集作業はありません</div>');
  ws.forEach(function(w){h+='<div class="work row"><span>'+esc(w.name)+'</span><span class="c">最終保存: '+fd(w.saved)+'</span><span class="sp"></span>'+
   '<button data-a="res" data-i="'+w.id+'">再開</button><button class="d" data-a="dw" data-i="'+w.id+'">削除</button></div>'});
  h+='</div></div>'});
 $("ol").innerHTML=h;
 $("rl").innerHTML=results.map(function(r){return'<div class="work row"><span>'+esc(r.name)+'</span><span class="c">元: '+esc(r.vn)+' ／ 作成: '+fd(r.at)+'</span><span class="sp"></span><button class="d" data-a="dr" data-i="'+r.id+'">削除</button></div>'}).join("")}
function act(e){var b=e.target.closest("button[data-a]");if(!b)return;var a=b.dataset.a,i=b.dataset.i;
 if(a==="new")edit(i,null);
 if(a==="res")edit(find(works,i).vid,find(works,i));
 if(a==="dw")conf("編集作業「"+esc(find(works,i).name)+"」を削除します。",function(){works=works.filter(function(w){return w.id!==i});home()});
 if(a==="dr")conf("この編集結果の動画を削除します。",function(){results=results.filter(function(r){return r.id!==i});home()});
 if(a==="dv"){var n=works.filter(function(w){return w.vid===i}).length;
  conf("動画「"+esc(find(videos,i).name)+"」を削除します。"+(n?"<br><b>配下の編集作業"+n+"件も一緒に削除されます。</b>":"")+"<br>編集結果の動画は残ります。",function(){delVideo(i);home()})}}
function delVideo(i){videos=videos.filter(function(v){return v.id!==i});works=works.filter(function(w){return w.vid!==i})}
$("ol").onclick=act;$("rl").onclick=act;

$("pick").onclick=function(){$("file").value="";$("file").click()};
$("file").onchange=function(){var f=this.files[0];if(!f)return;var u=URL.createObjectURL(f),p=document.createElement("video");
 p.preload="metadata";
 p.onloadedmetadata=function(){add({id:uid("v"),name:f.name,at:new Date(),dur:p.duration,url:u})};
 p.onerror=function(){modal("<p>この動画を読み込めませんでした。</p>",[["閉じる"]])};p.src=u};
function add(v){
 if(videos.length<LIM){videos.push(v);return edit(v.id,null)}
 var h="<p><b>オリジナル動画は"+LIM+"件までです。</b>削除するものを選んでください。</p>"+videos.map(function(x,i){
  return'<label><input type="radio" name="del" value="'+x.id+'"'+(i?"":" checked")+'> '+esc(x.name)+'（配下の編集作業も削除）</label><br>'}).join("");
 modal(h,[["キャンセル"],["削除して取り込む",function(){delVideo(document.querySelector('input[name=del]:checked').value);videos.push(v);edit(v.id,null)},"d"]])}

/* ---- 編集画面 ---- */
var ed,drag=null,sliding=false;
function edit(id,w){var v=find(videos,id);
 ed={dur:v.dur,pos:0,vs:0,ve:v.dur,t:null,vd:null,els:[],sel:[],dirty:false};
 if(w){var d=v.dur;ed.els=[
  {id:uid("e"),type:"comment",s:d*.05,e:d*.2,x:.1,y:.1,w:.3,h:.15,a:{text:"コメント",line:true,lw:2,lc:"#2563eb",tc:"#000000",bg:"#ffffff",fill:true,fs:16,font:"sans-serif"}},
  {id:uid("e"),type:"hl",s:d*.15,e:d*.4,x:.5,y:.3,w:.3,h:.3,a:{lw:4,lc:"#dc2626",fill:false,dash:"実線",shape:"直線"}}]}
 $("home").classList.add("hidden");$("editor").classList.remove("hidden");$("ev").textContent=v.name;
 $("wn").textContent=w?w.name:"未保存の編集作業";
 $("vb").innerHTML="";
 if(v.url){ed.vd=document.createElement("video");ed.vd.src=v.url;$("vb").appendChild(ed.vd)}
 else{var dm=document.createElement("div");dm.className="dummy";dm.textContent="動画領域";$("vb").appendChild(dm)}
 var L=document.createElement("div");L.id="vl";L.style.cssText="position:absolute;inset:0";$("vb").appendChild(L);
 setDirty(!!w&&false);draw()}
function setDirty(b){ed.dirty=b;$("dirty").classList.toggle("hidden",!b)}
function changed(){setDirty(true);draw()}
$("end").onclick=function(){
 var fin=function(){stop();closeMenu();$("editor").classList.add("hidden");$("home").classList.remove("hidden");home()};
 if(!ed.dirty)return fin();
 modal("<p>未保存の変更があります。</p>",[["編集に戻る"],["保存せずに終了",fin,"d"]])};
function stop(){clearInterval(ed.t);ed.t=null;if(ed.vd)ed.vd.pause()}
$("play").onclick=function(){if(ed.t)return;if(ed.pos>=ed.dur)ed.pos=0;var l=performance.now();
 if(ed.vd){ed.vd.currentTime=ed.pos;ed.vd.play()}
 ed.t=setInterval(function(){var n=performance.now();ed.pos=Math.min(ed.dur,ed.vd?ed.vd.currentTime:ed.pos+(n-l)/1000);l=n;
  if(ed.pos>=ed.dur)stop();
  var sp=ed.ve-ed.vs;if(!drag&&sp<ed.dur&&(ed.pos>ed.ve-sp*.05||ed.pos<ed.vs))view(ed.pos-sp*.3);draw()},50)};
$("pause").onclick=stop;
$("stopb").onclick=function(){stop();setPos(0);draw()};
function setPos(t){ed.pos=clamp(t,0,ed.dur);if(ed.vd)ed.vd.currentTime=ed.pos}

function view(s,sp){if(sp===undefined)sp=ed.ve-ed.vs;sp=Math.max(MIN,Math.min(ed.dur,sp));ed.vs=Math.max(0,Math.min(ed.dur-sp,s));ed.ve=ed.vs+sp}
function zoom(f,t){var sp=ed.ve-ed.vs,ns=Math.max(MIN,Math.min(ed.dur,sp/f));view(t-(t-ed.vs)/sp*ns,ns)}
function ctr(){return ed.pos>=ed.vs&&ed.pos<=ed.ve?ed.pos:(ed.vs+ed.ve)/2}
function t2x(t,w){return(t-ed.vs)/(ed.ve-ed.vs)*w}
function x2t(x,w){return ed.vs+x/w*(ed.ve-ed.vs)}
$("in").onclick=function(){zoom(1.5,ctr());draw()};
$("out").onclick=function(){zoom(1/1.5,ctr());draw()};
$("fit").onclick=function(){view(0,ed.dur);draw()};
function pan(d){view(ed.vs+d*(ed.ve-ed.vs)*.25);draw()}
$("bl").onclick=function(){pan(-1)};$("br").onclick=function(){pan(1)};
function ml(){return Math.log(Math.max(1.0001,ed.dur/MIN))}
$("zoom").onpointerdown=function(){sliding=true};
window.addEventListener("pointerup",function(){sliding=false});
$("zoom").oninput=function(){var c=ctr(),ns=ed.dur/Math.exp(ml()*this.value/1000);view(c-(c-ed.vs)/(ed.ve-ed.vs)*ns,ns);draw()};
$("tl").addEventListener("wheel",function(e){e.preventDefault();var r=$("tl").getBoundingClientRect();
 zoom(e.deltaY<0?1.25:.8,x2t(e.clientX-r.left,r.width));draw()},{passive:false});

/* ---- 要素の行配置 ---- */
function layout(){var rows=[],m={};
 ed.els.slice().sort(function(a,b){return a.s-b.s}).forEach(function(el){
  var r=0;while(rows[r]>el.s)r++;rows[r]=el.e;m[el.id]=r});return m}
function isVisible(el){return ed.pos>=el.s&&ed.pos<=el.e}

/* ---- 描画 ---- */
function draw(){
 var w=$("tl").clientWidth,sp=ed.ve-ed.vs,tg=sp/Math.max(2,w/90),cs=[.05,.1,.2,.5,1,2,5,10,15,30,60,120,300,600],st=600,h="",k;
 for(k=0;k<cs.length;k++)if(cs[k]>=tg){st=cs[k];break}
 var dc=st<.1?2:st<1?1:0;
 for(var t=Math.ceil(ed.vs/st)*st;t<=ed.ve+1e-9;t+=st)h+='<div class="tick" style="left:'+t2x(t,w)+'px"><span>'+ft(t,dc)+'</span></div>';
 $("ruler").innerHTML=h;
 var lay=layout();h="";
 ed.els.forEach(function(el){
  if(el.e<ed.vs||el.s>ed.ve)return;
  var x1=Math.max(0,t2x(el.s,w)),x2=Math.min(w,t2x(el.e,w)),sel=ed.sel.indexOf(el.id)>=0;
  h+='<div class="el'+(sel?" sel":"")+'" data-id="'+el.id+'" style="left:'+x1+'px;width:'+Math.max(2,x2-x1)+'px;top:'+(6+lay[el.id]*26)+'px;background:'+TYPES[el.type].c+'">'+esc(label(el))+'</div>'});
 if(ed.dur<=ed.ve)h+='<div class="end" style="left:'+Math.min(w-2,t2x(ed.dur,w))+'px">終了</div>';
 $("lanes").innerHTML=h;
 var o=$("tl").querySelector(".cur");if(o)o.remove();
 if(ed.pos>=ed.vs&&ed.pos<=ed.ve){var c=document.createElement("div");c.className="cur";c.style.left=t2x(ed.pos,w)+"px";c.innerHTML="<i></i>";$("tl").appendChild(c)}
 h=ed.els.map(function(e){return'<div class="mk" style="left:'+e.s/ed.dur*100+'%;width:'+(e.e-e.s)/ed.dur*100+'%"></div>'}).join("");
 h+='<div class="pc" style="left:'+ed.pos/ed.dur*100+'%"></div><div class="view" style="left:'+ed.vs/ed.dur*100+'%;width:'+sp/ed.dur*100+'%"><div class="hd l" data-h="l"></div><div class="hd r" data-h="r"></div></div>';
 $("bar").innerHTML=h;
 var sc=ed.dur/sp;
 $("rt").textContent="表示範囲: "+ft(ed.vs,2)+" 〜 "+ft(ed.ve,2)+"（幅 "+sp.toFixed(2)+"秒）";
 $("zt").textContent=(sc<1.005?"1倍（全体表示）":sc.toFixed(1)+"倍");
 $("pos").textContent="再生位置 "+ft(ed.pos,2)+" / "+ft(ed.dur,2);
 if(!sliding)$("zoom").value=Math.round(Math.log(sc)/ml()*1000);
 drawVideo()}
function label(el){return TYPES[el.type].n+(el.type==="comment"?"："+el.a.text:"")}

function drawVideo(){var L=$("vl"),h="";if(!L)return;
 ed.els.forEach(function(el){
  if(el.type==="skip"||!isVisible(el))return;
  var sel=ed.sel.indexOf(el.id)>=0,st="left:"+el.x*100+"%;top:"+el.y*100+"%;width:"+el.w*100+"%;height:"+el.h*100+"%;",a=el.a,inner="";
  if(el.type==="comment"){
   st+="color:"+a.tc+";font-size:"+a.fs+"px;font-family:"+a.font+";padding:4px;"+(a.fill?"background:"+a.bg+";":"")+(a.line?"border:"+a.lw+"px solid "+a.lc+";":"");
   inner=esc(a.text)}
  else if(el.type==="hl"){
   st+=(a.fill?"background:"+a.lc+"33;":"");
   inner='<svg width="100%" height="100%" style="position:absolute;inset:0;pointer-events:none" preserveAspectRatio="none"><rect x="'+a.lw/2+'" y="'+a.lw/2+'" width="calc(100% - '+a.lw+'px)" height="calc(100% - '+a.lw+'px)" fill="none" stroke="'+a.lc+'" stroke-width="'+a.lw+'" stroke-dasharray="'+DASH[a.dash]+'"/></svg>'}
  else{st+="border:2px solid #059669;background:rgba(5,150,105,.15);";inner='<span style="font-size:11px;background:#059669;padding:1px 4px">拡大</span>'}
  h+='<div class="ve'+(sel?" sel":"")+'" data-id="'+el.id+'" style="'+st+'">'+inner+(sel?'<div class="rz" data-rz="1"></div>':"")+'</div>'});
 L.innerHTML=h}

/* ---- 選択 ---- */
function select(id,add){
 if(add){var i=ed.sel.indexOf(id);if(i>=0)ed.sel.splice(i,1);else ed.sel.push(id)}
 else if(ed.sel.indexOf(id)<0)ed.sel=[id]}
function elAt(e){var n=e.target.closest("[data-id]");return n?find(ed.els,n.dataset.id):null}

/* ---- タイムラインのポインタ操作 ---- */
$("ruler").addEventListener("pointerdown",function(e){
 drag={k:"pos"};var mv=function(ev){var r=$("tl").getBoundingClientRect();setPos(x2t(clamp(ev.clientX-r.left,0,r.width),r.width));draw()};mv(e);
 track(mv)});
$("tl").addEventListener("pointerdown",function(e){
 if(e.target.closest(".cur i")){drag={k:"pos"};track(function(ev){var r=$("tl").getBoundingClientRect();setPos(x2t(clamp(ev.clientX-r.left,0,r.width),r.width));draw()})}});
$("lanes").addEventListener("pointerdown",function(e){
 if(e.button!==0)return;closeMenu();
 var el=elAt(e),r=$("tl").getBoundingClientRect(),w=r.width;
 if(!el){ed.sel=[];drag={k:"pan"};var sx=e.clientX,s0=ed.vs,sp=ed.ve-ed.vs;draw();
  track(function(ev){view(s0-(ev.clientX-sx)/w*sp);draw()});return}
 select(el.id,e.shiftKey);draw();
 var x0=e.clientX,os=el.s,oe=el.e,len=oe-os,edge=e.offsetX,bw=e.target.offsetWidth,mode="move";
 if(edge<6)mode="l";else if(bw-edge<6)mode="r";
 drag={k:"time"};
 track(function(ev){var dt=(ev.clientX-x0)/w*(ed.ve-ed.vs);
  if(mode==="move"){el.s=clamp(os+dt,0,ed.dur-len);el.e=el.s+len}
  else if(mode==="l")el.s=clamp(os+dt,0,oe-MINLEN);
  else el.e=clamp(oe+dt,os+MINLEN,ed.dur);
  setDirty(true);draw()})});
function track(mv){var up=function(){window.removeEventListener("pointermove",mv);window.removeEventListener("pointerup",up);drag=null;draw()};
 window.addEventListener("pointermove",mv);window.addEventListener("pointerup",up)}

/* ---- 帯の操作 ---- */
$("bar").addEventListener("pointerdown",function(e){
 var r=$("bar").getBoundingClientRect(),W=r.width,sp=ed.ve-ed.vs,hd=e.target.dataset.h;
 if(hd){var s0=ed.vs,e0=ed.ve;drag={k:"bar"};
  track(function(ev){var t=clamp((ev.clientX-r.left)/W*ed.dur,0,ed.dur);
   if(hd==="l")view(Math.min(t,e0-MIN),e0-Math.min(t,e0-MIN));else view(s0,Math.max(t,s0+MIN)-s0);draw()});return}
 if(e.target.closest(".view")){var x0=e.clientX,s1=ed.vs;drag={k:"bar"};
  track(function(ev){view(s1+(ev.clientX-x0)/W*ed.dur);draw()});return}
 view((e.clientX-r.left)/W*ed.dur-sp/2);draw()});

/* ---- 動画領域の操作 ---- */
$("vb").addEventListener("pointerdown",function(e){
 if(e.button!==0)return;closeMenu();
 var el=elAt(e);
 if(!el){ed.sel=[];draw();return}
 select(el.id,e.shiftKey);draw();
 var r=$("vb").getBoundingClientRect(),x0=e.clientX,y0=e.clientY,rz=!!e.target.dataset.rz;
 var sels=ed.els.filter(function(q){return ed.sel.indexOf(q.id)>=0}).map(function(q){return{q:q,x:q.x,y:q.y,w:q.w,h:q.h}});
 drag={k:"ve"};
 track(function(ev){var dx=(ev.clientX-x0)/r.width,dy=(ev.clientY-y0)/r.height;
  sels.forEach(function(o){
   if(rz&&sels.length===1){o.q.w=clamp(o.w+dx,.05,1-o.q.x);o.q.h=clamp(o.h+dy,.05,1-o.q.y)}
   else if(!rz){o.q.x=clamp(o.x+dx,0,1-o.q.w);o.q.y=clamp(o.y+dy,0,1-o.q.h)}});
  setDirty(true);draw()})});

/* ---- 右クリックメニュー ---- */
document.addEventListener("contextmenu",function(e){
 if(!ed||$("editor").classList.contains("hidden"))return;
 var inVid=$("vb").contains(e.target),inTl=$("lanes").contains(e.target);
 if(!inVid&&!inTl)return;
 e.preventDefault();
 var el=elAt(e);
 if(el){if(ed.sel.indexOf(el.id)<0)ed.sel=[el.id];draw();showElementMenu(e.clientX,e.clientY)}
 else{ed.sel=[];draw();showBasicMenu(e.clientX,e.clientY)}});
document.addEventListener("pointerdown",function(e){if(!e.target.closest(".cm"))closeMenu()},true);
document.addEventListener("keydown",function(e){if(e.key==="Escape")closeMenu()});
function closeMenu(){$("cmr").innerHTML=""}
function openMenu(x,y,build){
 closeMenu();var m=document.createElement("div");m.className="cm";build(m);$("cmr").appendChild(m);
 m.style.left=Math.min(x,innerWidth-m.offsetWidth-8)+"px";m.style.top=Math.min(y,innerHeight-m.offsetHeight-8)+"px"}
function mbtn(m,txt,fn,cls){var b=document.createElement("button");b.textContent=txt;if(cls)b.className=cls;
 b.onclick=function(){closeMenu();fn()};m.appendChild(b)}
function mtitle(m,t){var d=document.createElement("div");d.className="t";d.textContent=t;m.appendChild(d)}
function showBasicMenu(x,y){openMenu(x,y,function(m){
 mtitle(m,"要素を追加（現在の再生位置から）");
 Object.keys(TYPES).forEach(function(k){mbtn(m,TYPES[k].n+"を追加",function(){addEl(k)})});
 m.appendChild(document.createElement("hr"));
 mbtn(m,"動画を選択",function(){stop();$("editor").classList.add("hidden");$("home").classList.remove("hidden");home();$("pick").click()})})}
function addEl(type){
 var s=Math.min(ed.pos,ed.dur-MINLEN),e=Math.min(ed.dur,s+DEFLEN);
 var n={id:uid("e"),type:type,s:s,e:e,x:.3,y:.3,w:.3,h:.2,a:null};
 if(type==="comment")n.a={text:"コメント",line:true,lw:2,lc:"#2563eb",tc:"#000000",bg:"#ffffff",fill:true,fs:16,font:"sans-serif"};
 if(type==="hl")n.a={lw:4,lc:"#dc2626",fill:false,dash:"実線",shape:"直線"};
 ed.els.push(n);ed.sel=[n.id];changed()}
function delSel(){ed.els=ed.els.filter(function(q){return ed.sel.indexOf(q.id)<0});ed.sel=[];changed()}
function showElementMenu(x,y){openMenu(x,y,function(m){
 if(ed.sel.length>1){
  mtitle(m,"複数選択（"+ed.sel.length+"件）");
  mbtn(m,"まとめて削除",function(){conf("選択した"+ed.sel.length+"件の要素を削除します。",delSel)},"d");
  mbtn(m,"開始時間を揃える",function(){var s=find(ed.els,ed.sel[0]).s;ed.sel.forEach(function(id){var q=find(ed.els,id),l=q.e-q.s;q.s=clamp(s,0,ed.dur-l);q.e=q.s+l});changed()});return}
 var el=find(ed.els,ed.sel[0]);mtitle(m,TYPES[el.type].n+"の操作");
 if(el.type==="comment")commentMenu(m,el);
 if(el.type==="hl")hlMenu(m,el);
 if(el.type==="zoom"||el.type==="skip")timeMenu(m,el);
 m.appendChild(document.createElement("hr"));
 mbtn(m,"削除",function(){conf("この要素を削除します。",delSel)},"d")})}
function timeMenu(m,el){
 var l=document.createElement("label");l.textContent="開始(秒)";var i=document.createElement("input");i.type="number";i.step="0.1";i.style.width="70px";i.value=el.s.toFixed(1);
 i.onchange=function(){el.s=clamp(+i.value,0,el.e-MINLEN);changed()};l.appendChild(i);m.appendChild(l);
 var l2=document.createElement("label");l2.textContent="終了(秒)";var i2=document.createElement("input");i2.type="number";i2.step="0.1";i2.style.width="70px";i2.value=el.e.toFixed(1);
 i2.onchange=function(){el.e=clamp(+i2.value,el.s+MINLEN,ed.dur);changed()};l2.appendChild(i2);m.appendChild(l2)}
function swatches(m,title,cur,fn){mtitle(m,title);var d=document.createElement("div");d.className="sw";
 COLORS.forEach(function(c){var b=document.createElement("b");b.style.background=c;if(c===cur)b.className="on";
  b.onclick=function(ev){ev.stopPropagation();fn(c);Array.prototype.forEach.call(d.children,function(x){x.className=""});b.className="on"};d.appendChild(b)});m.appendChild(d)}
function choices(m,title,opts,cur,fn,render){mtitle(m,title);var d=document.createElement("div");d.className="ch";
 opts.forEach(function(o){var b=document.createElement("button");if(render)render(b,o);else b.textContent=o;if(o===cur)b.className="on";
  b.onclick=function(ev){ev.stopPropagation();fn(o);Array.prototype.forEach.call(d.children,function(x){x.className=""});b.className="on"};d.appendChild(b)});m.appendChild(d)}
function widthChoice(m,a){choices(m,"線の太さ",WIDTHS,a.lw,function(v){a.lw=v;changed()},function(b,o){b.innerHTML='<span style="display:inline-block;width:28px;height:'+o+'px;background:#000;vertical-align:middle"></span>'})}
function check(m,txt,cur,fn){var l=document.createElement("label"),i=document.createElement("input");i.type="checkbox";i.checked=!!cur;
 i.onchange=function(){fn(i.checked)};l.appendChild(i);l.appendChild(document.createTextNode(txt));m.appendChild(l)}
function commentMenu(m,el){var a=el.a;
 var l=document.createElement("label");l.textContent="文字";var t=document.createElement("input");t.type="text";t.value=a.text;t.style.width="150px";
 t.oninput=function(){a.text=t.value;changed()};l.appendChild(t);m.appendChild(l);
 check(m,"線あり",a.line,function(v){a.line=v;changed()});
 widthChoice(m,a);
 swatches(m,"線の色",a.lc,function(c){a.lc=c;changed()});
 swatches(m,"文字色",a.tc,function(c){a.tc=c;changed()});
 check(m,"塗り潰しあり",a.fill,function(v){a.fill=v;changed()});
 swatches(m,"背景色",a.bg,function(c){a.bg=c;changed()});
 var l2=document.createElement("label");l2.textContent="文字サイズ";var n=document.createElement("input");n.type="number";n.min=8;n.max=72;n.value=a.fs;n.style.width="60px";
 n.onchange=function(){a.fs=clamp(+n.value||16,8,72);changed()};l2.appendChild(n);m.appendChild(l2);
 choices(m,"フォント",FONTS,a.font,function(v){a.font=v;changed()},function(b,o){b.style.fontFamily=o;b.textContent="あAa"})}
function hlMenu(m,el){var a=el.a;
 widthChoice(m,a);
 swatches(m,"線の色",a.lc,function(c){a.lc=c;changed()});
 check(m,"塗り潰しあり",a.fill,function(v){a.fill=v;changed()});
 choices(m,"線種",Object.keys(DASH),a.dash,function(v){a.dash=v;changed()});
 choices(m,"線形",["直線","折れ線","波線"],a.shape,function(v){a.shape=v;changed()})}

window.addEventListener("resize",function(){if(ed&&!$("editor").classList.contains("hidden"))draw()});
home();
})();
</script></body></html>
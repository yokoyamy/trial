<!DOCTYPE html> 
<html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;overflow-x:hidden;font:14px sans-serif;background:#f3f4f6;color:#222}
button{font:inherit;cursor:pointer;border:1px solid #9ca3af;background:#fff;border-radius:4px;padding:4px 10px}
.p{background:#2563eb;color:#fff;border-color:#1d4ed8}.d{color:#b91c1c;border-color:#fca5a5}
h2{font-size:16px;margin:0 0 8px}.wrap{max-width:1000px;margin:0 auto;padding:16px}.hidden{display:none!important}
.card{background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:12px;margin-bottom:16px}
.c{color:#6b7280;font-size:13px}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.sp{flex:1}
.orig{border:2px solid #93c5fd;border-radius:6px;margin:10px 0;background:#f8fbff}
.oh{padding:8px;background:#dbeafe}.works{margin:8px 8px 8px 28px;border-left:3px solid #93c5fd;padding-left:10px}
.work{padding:5px 0;border-bottom:1px dashed #d1d5db}.empty{color:#6b7280;font-style:italic}
.mask{position:fixed;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center}
.modal{background:#fff;border-radius:6px;padding:16px;max-width:460px;width:92%}
.btns{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
.top{padding:8px 12px;background:#1f2937;color:#fff}
.video{background:#000;color:#fff;height:300px;margin:12px auto;max-width:700px;display:flex;align-items:center;justify-content:center;font-size:28px}
.video video{width:100%;height:100%;object-fit:contain}
.tw{max-width:900px;margin:0 auto;padding:0 12px 16px}.tw .row{margin-bottom:8px}
.num{background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:13px}
.tlrow{display:flex;gap:4px}.tlrow>button{padding:0 10px;font-size:16px}
.tl{position:relative;flex:1;min-width:0;background:#fff;border:1px solid #9ca3af;border-radius:4px;overflow:hidden;user-select:none}
.ruler{position:relative;height:28px;border-bottom:1px solid #9ca3af;background:#f9fafb;cursor:pointer}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #9ca3af}.tick span{position:absolute;top:2px;left:3px;font-size:11px}
.lanes{position:relative;height:120px;cursor:grab}
.el{position:absolute;height:22px;border-radius:3px;color:#fff;font-size:11px;padding:2px 6px;overflow:hidden;white-space:nowrap}
.end{position:absolute;top:0;bottom:0;border-left:2px solid #dc2626;font-size:10px;color:#dc2626;padding-left:3px}
.cur{position:absolute;top:0;bottom:0;border-left:2px solid #e11d48;pointer-events:none}
.cur i{position:absolute;top:0;left:-9px;width:16px;height:16px;background:#e11d48;border:2px solid #fff;border-radius:50%;box-shadow:0 0 2px #0006;cursor:ew-resize;pointer-events:auto}
.bar{position:relative;height:30px;background:#e5e7eb;border:1px solid #9ca3af;border-radius:4px;margin:8px 6px 0;user-select:none}
.mk{position:absolute;top:11px;height:8px;background:rgba(100,116,139,.45)}
.pc{position:absolute;top:0;bottom:0;width:2px;background:rgba(225,29,72,.6)}
.view{position:absolute;top:0;bottom:0;background:rgba(37,99,235,.25);border-top:2px solid #2563eb;border-bottom:2px solid #2563eb;cursor:grab}
.hd{position:absolute;top:-2px;bottom:-2px;width:12px;background:#2563eb;cursor:ew-resize;border-radius:3px}
.hd.l{left:-6px}.hd.r{right:-6px}
</style></head><body>
<div id="home" class="wrap">
 <div class="card"><h2>動画を選択</h2><p class="c">現在、編集対象の動画は選択されていません。</p>
  <button class="p" id="pick">動画を選択</button><input type="file" id="file" accept="video/*" class="hidden"></div>
 <div class="card"><h2>オリジナル動画と編集作業 <span class="c" id="oc"></span></h2><div id="ol"></div></div>
 <div class="card"><h2>編集結果の動画 <span class="c" id="rc"></span></h2><div id="rl"></div></div>
</div>
<div id="editor" class="hidden">
 <div class="top row"><b>動画: <span id="ev"></span></b><b>編集作業: 未保存の編集作業</b><span class="sp"></span><button id="end">編集作業を終了する</button></div>
 <div class="video" id="vb"></div>
 <div class="tw">
  <div class="row"><button id="play">▶ 再生</button><button id="pause">⏸ 一時停止</button><span class="num" id="pos"></span><span class="sp"></span>
   <button id="out">－</button><input type="range" id="zoom" min="0" max="1000" value="0" style="width:160px"><button id="in">＋</button>
   <span class="num" id="zt"></span><button id="fit">全体表示に戻す</button></div>
  <div class="row"><span class="num" id="rt"></span></div>
  <div class="tlrow"><button id="bl">◀</button>
   <div class="tl" id="tl"><div class="ruler" id="ruler"></div><div class="lanes" id="lanes"></div></div>
  <button id="br">▶</button></div>
  <div class="bar" id="bar"></div>
 </div>
</div>
<div id="mr"></div>
<script>
(function(){
var LIM=10,MIN=0.5,seq=1,$=function(i){return document.getElementById(i)};
var uid=function(p){return p+seq++},z=function(n){return("0"+n).slice(-2)};
var esc=function(t){return String(t).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]})};
var fd=function(d){return d.getFullYear()+"/"+z(d.getMonth()+1)+"/"+z(d.getDate())+" "+z(d.getHours())+":"+z(d.getMinutes())};
var ft=function(s,n){var m=Math.floor(s/60),r=s-m*60;return m+":"+(r<10?"0":"")+r.toFixed(n)};
var find=function(a,id){return a.filter(function(x){return x.id===id})[0]};
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
  h+='<div class="orig"><div class="oh row"><b>🎬 '+esc(v.name)+'</b><span class="c">取り込み: '+fd(v.at)+' ／ 編集作業: '+ws.length+'件</span><span class="sp"></span>'+
   '<button class="p" data-a="new" data-i="'+v.id+'">新しい編集作業を始める</button><button class="d" data-a="dv" data-i="'+v.id+'">削除</button></div><div class="works">'+
   (ws.length?"":'<div class="empty">編集作業はありません</div>');
  ws.forEach(function(w){h+='<div class="work row"><span>📝 '+esc(w.name)+'</span><span class="c">最終保存: '+fd(w.saved)+'</span><span class="sp"></span>'+
   '<button data-a="res" data-i="'+w.id+'">再開</button><button class="d" data-a="dw" data-i="'+w.id+'">削除</button></div>'});
  h+='</div></div>'});
 $("ol").innerHTML=h;
 $("rl").innerHTML=results.map(function(r){return'<div class="work row"><span>🎞 '+esc(r.name)+'</span><span class="c">元: '+esc(r.vn)+' ／ 作成: '+fd(r.at)+'</span><span class="sp"></span><button class="d" data-a="dr" data-i="'+r.id+'">削除</button></div>'}).join("")}
function act(e){var b=e.target.closest("button[data-a]");if(!b)return;var a=b.dataset.a,i=b.dataset.i;
 if(a==="new")edit(i);
 if(a==="res")edit(find(works,i).vid);
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
 if(videos.length<LIM){videos.push(v);return edit(v.id)}
 var h="<p><b>オリジナル動画は"+LIM+"件までです。</b>削除するものを選んでください。</p>"+videos.map(function(x,i){
  return'<label><input type="radio" name="del" value="'+x.id+'"'+(i?"":" checked")+'> '+esc(x.name)+'（配下の編集作業も削除）</label><br>'}).join("");
 modal(h,[["キャンセル"],["削除して取り込む",function(){delVideo(document.querySelector('input[name=del]:checked').value);videos.push(v);edit(v.id)},"d"]])}

var ed,drag=null,sliding=false;
function edit(id){var v=find(videos,id);
 ed={dur:v.dur,pos:0,vs:0,ve:v.dur,t:null,vd:null,els:[
  {n:"コメント1",s:v.dur*.05,e:v.dur*.2,c:"#2563eb"},{n:"強調枠1",s:v.dur*.15,e:v.dur*.4,c:"#dc2626"},{n:"拡大枠1",s:v.dur*.18,e:v.dur*.3,c:"#059669"}]};
 $("home").classList.add("hidden");$("editor").classList.remove("hidden");$("ev").textContent=v.name;$("vb").innerHTML="";
 if(v.url){ed.vd=document.createElement("video");ed.vd.src=v.url;$("vb").appendChild(ed.vd)}
 draw()}
$("end").onclick=function(){stop();$("editor").classList.add("hidden");$("home").classList.remove("hidden");home()};
function stop(){clearInterval(ed.t);ed.t=null;if(ed.vd)ed.vd.pause()}
$("play").onclick=function(){if(ed.t)return;if(ed.pos>=ed.dur)ed.pos=0;var l=performance.now();
 if(ed.vd){ed.vd.currentTime=ed.pos;ed.vd.play()}
 ed.t=setInterval(function(){var n=performance.now();ed.pos=Math.min(ed.dur,ed.vd?ed.vd.currentTime:ed.pos+(n-l)/1000);l=n;
  if(ed.pos>=ed.dur)stop();
  var sp=ed.ve-ed.vs;if(!drag&&sp<ed.dur&&(ed.pos>ed.ve-sp*.05||ed.pos<ed.vs))view(ed.pos-sp*.3);draw()},50)};
$("pause").onclick=stop;

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
window.addEventListener("pointerup",function(){sliding=false;if(ed)draw()});
$("zoom").oninput=function(){var c=ctr(),ns=ed.dur/Math.exp(ml()*this.value/1000);view(c-(c-ed.vs)/(ed.ve-ed.vs)*ns,ns);draw()};
$("tl").addEventListener("wheel",function(e){e.preventDefault();var r=$("tl").getBoundingClientRect();
 zoom(e.deltaY<0?1.25:.8,x2t(e.clientX-r.left,r.width));draw()},{passive:false});

function draw(){
 var w=$("tl").clientWidth,sp=ed.ve-ed.vs,tg=sp/Math.max(2,w/90),cs=[.05,.1,.2,.5,1,2,5,10,15,30,60,120,300,600],st=600,h="",k;
 for(k=0;k<cs.length;k++)if(cs[k]>=tg){st=cs[k];break}
 var dc=st<.1?2:st<1?1:0;
 for(var t=Math.ceil(ed.vs/st)*st;t<=ed.ve+1e-9;t+=st)h+='<div class="tick" style="left:'+t2x(t,w)+'px"><span>'+ft(t,dc)+'</span></div>';
 $("ruler").innerHTML=h;
 var rows=[];h="";
 ed.els.slice().sort(function(a,b){return a.s-b.s}).forEach(function(el){
  var r=0;while(rows[r]>el.s)r++;rows[r]=el.e;
  if(el.e<ed.vs||el.s>ed.ve)return;
  var x1=Math.max(0,t2x(el.s,w)),x2=Math.min(w,t2x(el.e,w));
  h+='<div class="el" style="left:'+x1+'px;width:'+Math.max(2,x2-x1)+'px;top:'+(6+r*26)+'px;background:'+el.c+'">'+esc(el.n)+'</div>'});
 if(ed.dur<=ed.ve)h+='<div class="end" style="left:'+Math.min(w-2,t2x(ed.dur,w))+'px">終了</div>';
 $("lanes").innerHTML=h;
 var o=$("tl").querySelector(".cur");if(o)o.remove();
 if(ed.pos>=ed.vs&&ed.pos<=ed.ve){var c=document.createElement("div");c.className="cur";c.style.left=t2x(ed.pos,w)+"px";c.innerHTML="<i></i>";$("tl").appendChild(c)}
 h=ed.els.map(function(e){return'<div class="mk" style="left:'+e.s/ed.dur*100+'%;width:'+(e.e-e.s)/ed.dur*100+'%"></div>'}).join("");
 h+='<div class="pc" style="left:'+ed.pos/ed.dur*100+'%"></div><div class="view" style="left:'+ed.vs/ed.dur*100+'%;width:'+sp/ed.dur*100+'%"><div class="hd l" data-h="l"></div><div class="hd r" data-h="r"></div></div>';
 $("bar").innerHTML=h;
 var sc=ed.dur/sp;
 $("rt").textContent="表示範囲: "+ft(ed.vs,2)+" 〜 "+ft(ed.ve,2)+"（幅 "+sp.toFixed(2)+"秒）";
 $("pos").textContent="再生位置 "+ft(ed.pos,1)+" / 総時間 "+ft(ed.dur,1);
 $("zt").textContent=Math.round(sc*10)/10+"倍"+(sc<1.001?"（全体表示）":"");
 if(!sliding)$("zoom").value=Math.round(Math.log(sc)/ml()*1000);
 if(!ed.vd)$("vb").textContent=ft(ed.pos,1)}

function seek(e){var r=$("tl").getBoundingClientRect();ed.pos=Math.max(0,Math.min(ed.dur,x2t(e.clientX-r.left,r.width)));if(ed.vd)ed.vd.currentTime=ed.pos;draw()}
$("ruler").onmousedown=function(e){drag={k:"seek"};seek(e)};
$("tl").addEventListener("mousedown",function(e){if(e.target.tagName==="I"){drag={k:"seek"};e.preventDefault();e.stopPropagation()}},true);
$("lanes").onmousedown=function(e){drag={k:"pan",x:e.clientX,s:ed.vs}};
$("bar").onmousedown=function(e){var h=e.target.dataset.h,r=$("bar").getBoundingClientRect();
 if(h){drag={k:h,s:ed.vs,e:ed.ve};e.preventDefault();return}
 if(!e.target.classList.contains("view"))view((e.clientX-r.left)/r.width*ed.dur-(ed.ve-ed.vs)/2);
 drag={k:"bar",x:e.clientX,s:ed.vs};draw()};
window.addEventListener("mousemove",function(e){if(!drag)return;var k=drag.k,tw=$("tl").clientWidth,b=$("bar").getBoundingClientRect();
 if(k==="seek")return seek(e);
 if(k==="pan")view(drag.s-(e.clientX-drag.x)/tw*(ed.ve-ed.vs));
 else if(k==="bar")view(drag.s+(e.clientX-drag.x)/b.width*ed.dur);
 else{var t=Math.max(0,Math.min(ed.dur,(e.clientX-b.left)/b.width*ed.dur));
  if(k==="l"){t=Math.min(t,drag.e-MIN);view(t,drag.e-t)}else view(drag.s,Math.max(t,drag.s+MIN)-drag.s)}
 draw()});
window.addEventListener("mouseup",function(){drag=null});
window.addEventListener("resize",function(){if(ed&&!$("editor").classList.contains("hidden"))draw()});
home();
})();
</script></body></html>
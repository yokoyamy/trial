<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;overflow-x:hidden;font:14px sans-serif;background:#f3f4f6;color:#222}
button{font:inherit;cursor:pointer;border:1px solid #9ca3af;background:#fff;border-radius:4px;padding:4px 10px}
button:disabled{opacity:.4;cursor:not-allowed}
.p{background:#2563eb;color:#fff;border-color:#1d4ed8}.d{color:#b91c1c;border-color:#fca5a5}
h2{font-size:16px;margin:0 0 8px}.wrap{max-width:1000px;margin:0 auto;padding:16px}.hidden{display:none!important}
.card{background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:12px;margin-bottom:16px}
.c{color:#6b7280;font-size:13px}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.sp{flex:1}
.orig{border:2px solid #93c5fd;border-radius:6px;margin:10px 0;background:#f8fbff}
.oh{padding:8px;background:#dbeafe}.works{margin:8px 8px 8px 28px;border-left:3px solid #93c5fd;padding-left:10px}
.work{padding:5px 0;border-bottom:1px dashed #d1d5db}.empty{color:#6b7280;font-style:italic}
.mask{position:fixed;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;z-index:50}
.modal{background:#fff;border-radius:6px;padding:16px;max-width:460px;width:92%}
.modal input[type=text]{width:100%;padding:4px}
.btns{display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}
.top{padding:8px 12px;background:#1f2937;color:#fff}
.top button{padding:3px 8px}
.video{position:relative;background:#000;color:#fff;height:300px;margin:12px auto;max-width:700px;overflow:hidden;user-select:none}
.video video{width:100%;height:100%;object-fit:contain;display:block}
.stage{position:absolute;inset:0;overflow:hidden}
.ve{position:absolute;cursor:move;font-size:14px;padding:2px 4px;overflow:hidden}
.ve.sel{outline:2px dashed #facc15;outline-offset:2px}
.rz{position:absolute;right:0;bottom:0;width:10px;height:10px;background:#facc15;cursor:nwse-resize}
.zbox{background:rgba(255,255,255,.08)}
#svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
#svg .hit{pointer-events:stroke;cursor:pointer}
.tw{max-width:900px;margin:0 auto;padding:0 12px 16px}.tw .row{margin-bottom:8px}
.num{background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:13px}
.tlrow{display:flex;gap:4px}.tlrow>button{padding:0 10px;font-size:16px}
.tl{position:relative;flex:1;min-width:0;background:#fff;border:1px solid #9ca3af;border-radius:4px;overflow:hidden;user-select:none}
.ruler{position:relative;height:28px;border-bottom:1px solid #9ca3af;background:#f9fafb;cursor:pointer}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #9ca3af}.tick span{position:absolute;top:2px;left:3px;font-size:11px}
.lanes{position:relative;height:150px;cursor:grab;overflow:hidden}
.el{position:absolute;height:22px;border-radius:3px;color:#fff;font-size:11px;padding:2px 10px;overflow:hidden;white-space:nowrap;cursor:move}
.el.sel{outline:2px solid #facc15}
.el i{position:absolute;top:0;bottom:0;width:7px;background:rgba(0,0,0,.35);cursor:ew-resize}
.el i.l{left:0}.el i.r{right:0}
.end{position:absolute;top:0;bottom:0;border-left:2px solid #dc2626;font-size:10px;color:#dc2626;padding-left:3px;pointer-events:none}
.cur{position:absolute;top:0;bottom:0;border-left:2px solid #e11d48;pointer-events:none;z-index:5}
.cur i{position:absolute;top:0;left:-9px;width:16px;height:16px;background:#e11d48;border:2px solid #fff;border-radius:50%;box-shadow:0 0 2px #0006;cursor:ew-resize;pointer-events:auto}
.bar{position:relative;height:30px;background:#e5e7eb;border:1px solid #9ca3af;border-radius:4px;margin:8px 6px 0;user-select:none}
.mk{position:absolute;top:11px;height:8px;background:rgba(100,116,139,.45)}
.pc{position:absolute;top:0;bottom:0;width:2px;background:rgba(225,29,72,.6)}
.view{position:absolute;top:0;bottom:0;background:rgba(37,99,235,.25);border-top:2px solid #2563eb;border-bottom:2px solid #2563eb;cursor:grab}
.hd{position:absolute;top:-2px;bottom:-2px;width:12px;background:#2563eb;cursor:ew-resize;border-radius:3px}
.hd.l{left:-6px}.hd.r{right:-6px}
.menu{position:fixed;z-index:60;background:#fff;border:1px solid #9ca3af;border-radius:6px;box-shadow:0 4px 12px #0003;padding:8px;min-width:200px;max-width:260px;font-size:13px}
.menu .mi{display:block;width:100%;text-align:left;border:0;padding:4px 6px}
.menu .mi:hover{background:#eff6ff}
.menu label{display:flex;gap:6px;align-items:center;margin:4px 0}
.menu label>span{width:64px;color:#6b7280;flex:none}
.menu select,.menu input[type=text],.menu input[type=number]{flex:1;min-width:0}
.menu h4{margin:0 0 4px;font-size:12px;color:#6b7280}
.sw{display:flex;flex-wrap:wrap;gap:3px}.sw b{width:18px;height:18px;border:1px solid #6b7280;cursor:pointer}
.sw b.on{outline:2px solid #2563eb}
.wd{display:flex;gap:3px}.wd b{flex:1;border:1px solid #9ca3af;padding:3px;cursor:pointer;text-align:center;background:#fff}
.wd b.on{outline:2px solid #2563eb}.wd b i{display:block;background:#222;width:100%}
.toast{position:fixed;bottom:12px;left:50%;transform:translateX(-50%);background:#111;color:#fff;padding:8px 14px;border-radius:6px;z-index:70}
.pg{height:10px;background:#e5e7eb;border-radius:5px;overflow:hidden}.pg div{height:100%;background:#2563eb;width:0}
</style></head><body>
<div id="home" class="wrap">
 <div class="card"><h2>動画を選択</h2><p class="c">現在、編集対象の動画は選択されていません。</p>
  <button class="p" id="pick">動画を選択</button><input type="file" id="file" accept="video/*" class="hidden"></div>
 <div class="card"><h2>オリジナル動画と編集作業 <span class="c" id="oc"></span></h2><div id="ol"></div></div>
 <div class="card"><h2>編集結果の動画 <span class="c" id="rc"></span></h2><div id="rl"></div></div>
</div>
<div id="editor" class="hidden">
 <div class="top row"><b>動画: <span id="ev"></span></b><b>編集作業: <span id="ew"></span><span id="dirty"></span></b><span class="sp"></span>
  <button id="save">保存</button><button id="saveas">別の編集作業として保存</button><button id="make">編集結果を動画にする</button><button id="end">編集作業を終了する</button></div>
 <div class="video" id="vb"><div class="stage" id="stage"><svg id="svg"></svg></div></div>
 <div class="tw">
  <div class="row"><button id="play">▶ 再生</button><button id="pause">⏸ 一時停止</button><button id="stop">⏹ 停止</button><span class="num" id="pos"></span>
   <label class="c"><input type="checkbox" id="skipon" checked>スキップを飛ばして再生</label><span class="sp"></span>
   <button id="out">−</button><input type="range" id="zoom" min="0" max="1000" value="0" style="width:160px"><button id="in">＋</button>
   <span class="num" id="zt"></span><button id="fit">全体表示に戻す</button></div>
  <div class="row"><span class="num" id="rt"></span></div>
  <div class="tlrow"><button id="bl">◀</button>
   <div class="tl" id="tl"><div class="ruler" id="ruler"></div><div class="lanes" id="lanes"></div></div>
  <button id="br">▶</button></div>
  <div class="bar" id="bar"></div>
 </div>
</div>
<div id="mr"></div><div id="menu"></div>
<script>
(function(){
var LIM=10,MINV=0.5,MINE=0.5,ZMAX=0,seq=1,$=function(i){return document.getElementById(i)};
var uid=function(p){return p+seq++},z=function(n){return("0"+n).slice(-2)};
var esc=function(t){return String(t).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]})};
var fd=function(d){return d.getFullYear()+"/"+z(d.getMonth()+1)+"/"+z(d.getDate())+" "+z(d.getHours())+":"+z(d.getMinutes())};
var ft=function(s,n){var m=Math.floor(s/60),r=s-m*60;return m+":"+(r<10?"0":"")+r.toFixed(n)};
var find=function(a,id){return a.filter(function(x){return x.id===id})[0]};
var cl=function(v,a,b){return Math.max(a,Math.min(b,v))};
var COLORS=["#000000","#ffffff","#ef4444","#f97316","#eab308","#22c55e","#14b8a6","#3b82f6","#6366f1","#a855f7","#ec4899","#6b7280"];
var FONTS=["sans-serif","serif","monospace","cursive","fantasy"];
var DASH={solid:"",dot:"2 4",dash:"10 6",chain:"14 4 2 4"};
var DN={solid:"実線",dot:"点線",dash:"破線",chain:"一点鎖線"},SN={line:"直線",poly:"折れ線",wave:"波線"};
var videos=[{id:"v0",name:"操作手順_顧客登録.mp4",at:new Date(2026,8,20,10,0),dur:120},{id:"v00",name:"障害再現_決済画面.mp4",at:new Date(2026,8,25,14,30),dur:75}];
var works=[{id:"w0",vid:"v0",name:"顧客登録_注釈入り",saved:new Date(2026,8,21,11,0),els:[{id:"e1",type:"comment",s:5,e:20,x:.1,y:.1,w:.3,h:.15,text:"ここをクリック",lc:"#ffffff",lw:2,line:true,tc:"#ffffff",bg:"#3b82f6",fill:true,fs:16,font:"sans-serif"}],cons:[]}];
var results=[{id:"r0",src:"操作手順_顧客登録.mp4",name:"顧客登録_完成版",at:new Date(2026,8,22,9,0)}];
var cur=null,S=null,url=null,vidEl=null,fake=0,playing=false,lastT=0,raf=0,connFrom=null,drag=null,pdrag=null;
var view={a:0,b:1},DUR=1;
function toast(t){var d=document.createElement("div");d.className="toast";d.textContent=t;document.body.appendChild(d);setTimeout(function(){d.remove()},2200)}
function modal(html,btns,fn){var m=$("mr");m.innerHTML='<div class="mask"><div class="modal">'+html+'<div class="btns">'+btns.map(function(b,i){return'<button data-i="'+i+'" class="'+(b.c||"")+'">'+b.t+'</button>'}).join("")+'</div></div></div>';
 Array.prototype.forEach.call(m.querySelectorAll(".btns button"),function(b){b.onclick=function(){var i=+b.dataset.i,v=m.querySelector("input[type=text]");var sel=m.querySelector("input[type=radio]:checked");var r=fn(i,v?v.value:"",sel?sel.value:"");if(r!==false)m.innerHTML=""}})}
/* ---- 初期画面 ---- */
function home(){
 $("oc").textContent="("+videos.length+"/"+LIM+"件)";$("rc").textContent="("+results.length+"/"+LIM+"件)";
 $("ol").innerHTML=videos.map(function(v){var ws=works.filter(function(w){return w.vid===v.id}).sort(function(a,b){return b.saved-a.saved});
  return'<div class="orig"><div class="oh row"><b>'+esc(v.name)+'</b><span class="c">取込 '+fd(v.at)+' / 編集作業 '+ws.length+'件</span><span class="sp"></span><button class="p" data-ns="'+v.id+'">新しい編集作業を始める</button><button class="d" data-dv="'+v.id+'">削除</button></div><div class="works">'+
  (ws.length?ws.map(function(w){return'<div class="work row"><span>'+esc(w.name)+'</span><span class="c">最終保存 '+fd(w.saved)+'</span><span class="sp"></span><button data-rs="'+w.id+'">再開</button><button class="d" data-dw="'+w.id+'">削除</button></div>'}).join(""):'<div class="empty">編集作業はありません</div>')+'</div></div>'}).join("")||'<p class="empty">オリジナル動画はありません</p>';
 $("rl").innerHTML=results.map(function(r){return'<div class="work row"><b>'+esc(r.name)+'</b><span class="c">元動画: '+esc(r.src)+' / 作成 '+fd(r.at)+'</span><span class="sp"></span><button class="d" data-dr="'+r.id+'">削除</button></div>'}).join("")||'<p class="empty">編集結果の動画はありません</p>';
 Array.prototype.forEach.call(document.querySelectorAll("[data-ns]"),function(b){b.onclick=function(){start(find(videos,b.dataset.ns),null)}});
 Array.prototype.forEach.call(document.querySelectorAll("[data-rs]"),function(b){b.onclick=function(){var w=find(works,b.dataset.rs);start(find(videos,w.vid),w)}});
 Array.prototype.forEach.call(document.querySelectorAll("[data-dv]"),function(b){b.onclick=function(){delVideo(b.dataset.dv)}});
 Array.prototype.forEach.call(document.querySelectorAll("[data-dw]"),function(b){b.onclick=function(){var w=find(works,b.dataset.dw);modal("<p>編集作業「"+esc(w.name)+"」を削除します。オリジナル動画と編集結果の動画には影響しません。</p>",[{t:"削除",c:"d"},{t:"キャンセル"}],function(i){if(i===0){works=works.filter(function(x){return x!==w});home()}})}});
 Array.prototype.forEach.call(document.querySelectorAll("[data-dr]"),function(b){b.onclick=function(){var r=find(results,b.dataset.dr);modal("<p>編集結果の動画「"+esc(r.name)+"」を削除します。</p>",[{t:"削除",c:"d"},{t:"キャンセル"}],function(i){if(i===0){results=results.filter(function(x){return x!==r});home()}})}});
}
function delVideo(id,after){var v=find(videos,id),n=works.filter(function(w){return w.vid===id}).length;
 modal("<p>オリジナル動画「"+esc(v.name)+"」を削除します。"+(n?"配下の編集作業"+n+"件も一緒に削除されます。":"")+"作成済みの編集結果の動画は残ります。</p>",[{t:"削除",c:"d"},{t:"キャンセル"}],function(i){if(i===0){videos=videos.filter(function(x){return x!==v});works=works.filter(function(w){return w.vid!==id});home();if(after)after()}})}
$("pick").onclick=function(){$("file").click()};
$("file").onchange=function(){var f=this.files[0];this.value="";if(!f)return;var u=URL.createObjectURL(f),t=document.createElement("video");
 t.preload="metadata";t.onerror=function(){URL.revokeObjectURL(u);toast("動画を読み込めませんでした: "+f.name)};
 t.onloadedmetadata=function(){var d=isFinite(t.duration)&&t.duration>0?t.duration:0;if(!d){toast("動画を読み込めませんでした: "+f.name);return}
  var add=function(){var v={id:uid("v"),name:f.name,at:new Date(),dur:d,url:u};videos.push(v);home();start(v,null)};
  if(videos.length>=LIM){var h="<p>オリジナル動画が上限("+LIM+"件)です。削除する動画を選んでください(配下の編集作業も一緒に削除されます)。</p>"+videos.map(function(v,i){var n=works.filter(function(w){return w.vid===v.id}).length;return'<label style="display:block"><input type="radio" name="dv" value="'+v.id+'"'+(i?'':' checked')+'> '+esc(v.name)+'(編集作業'+n+'件)</label>'}).join("");
   modal(h,[{t:"削除して取り込む",c:"p"},{t:"キャンセル"}],function(i,_,sel){if(i===0){videos=videos.filter(function(x){return x.id!==sel});works=works.filter(function(w){return w.vid!==sel});add()}})}else add()};
 t.src=u};
/* ---- 編集画面 ---- */
function newState(){return{els:[],cons:[],sel:[],dirty:false}}
function start(v,w){cur=v;DUR=v.dur;S=newState();S.work=w;S.name=w?w.name:null;
 if(w){S.els=JSON.parse(JSON.stringify(w.els));S.cons=JSON.parse(JSON.stringify(w.cons))}
 $("home").classList.add("hidden");$("editor").classList.remove("hidden");$("ev").textContent=v.name;
 var vb=$("vb");Array.prototype.forEach.call(vb.querySelectorAll("video,.fake"),function(x){x.remove()});vidEl=null;fake=0;playing=false;
 if(v.url){vidEl=document.createElement("video");vidEl.src=v.url;vidEl.muted=true;vb.insertBefore(vidEl,$("stage"))}
 else{var f=document.createElement("div");f.className="fake";f.style.cssText="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:24px;color:#aaa";f.textContent="(例題動画) "+v.name;vb.insertBefore(f,$("stage"))}
 view={a:0,b:DUR};connFrom=null;closeMenu();label();render();loop()}
function label(){$("ew").textContent=S.name||"未保存の編集作業";$("dirty").textContent=S.dirty?" ＊未保存の変更あり":""}
function dirty(){S.dirty=true;label()}
function T(){return vidEl?vidEl.currentTime:fake}
function setT(t){t=cl(t,0,DUR);if(vidEl)vidEl.currentTime=t;else fake=t}
function loop(){cancelAnimationFrame(raf);var step=function(ts){if(!cur)return;var dt=(ts-lastT)/1000;lastT=ts;
  if(playing){if(!vidEl){fake+=dt;if(fake>=DUR){fake=DUR;playing=false}}else if(vidEl.ended)playing=false;
   if($("skipon").checked){var t=T();S.els.forEach(function(e){if(e.type==="skip"&&t>=e.s&&t<e.e)setT(e.e)})}
   var t2=T(),w=view.b-view.a;if(!pdrag&&!(drag&&drag.manual)&&(t2>view.b||t2<view.a)){var a=cl(t2-w*.1,0,DUR-w);view={a:a,b:a+w}}}
  tlRender();stageRender();raf=requestAnimationFrame(step)};lastT=performance.now();raf=requestAnimationFrame(step)}
$("play").onclick=function(){if(T()>=DUR)setT(0);if(vidEl)vidEl.play();playing=true};
$("pause").onclick=function(){if(vidEl)vidEl.pause();playing=false};
$("stop").onclick=function(){if(vidEl)vidEl.pause();playing=false;setT(0)};
/* ---- タイムライン ---- */
function W(){return $("tl").clientWidth}
function t2x(t){return(t-view.a)/(view.b-view.a)*W()}
function x2t(x){return view.a+x/W()*(view.b-view.a)}
function zoomTo(nw,c){nw=cl(nw,Math.min(MINV,DUR),DUR);var w=view.b-view.a,r=(c-view.a)/w,a=cl(c-r*nw,0,DUR-nw);view={a:a,b:a+nw}}
function cc(){var t=T();return(t>=view.a&&t<=view.b)?t:(view.a+view.b)/2}
function scale(){return DUR/(view.b-view.a)}
$("in").onclick=function(){zoomTo((view.b-view.a)/1.5,cc())};
$("out").onclick=function(){zoomTo((view.b-view.a)*1.5,cc())};
$("fit").onclick=function(){view={a:0,b:DUR}};
$("zoom").oninput=function(){var mx=DUR/Math.min(MINV,DUR),s=Math.pow(mx,this.value/1000);zoomTo(DUR/s,cc())};
$("tl").addEventListener("wheel",function(e){e.preventDefault();var r=$("tl").getBoundingClientRect();zoomTo((view.b-view.a)/(e.deltaY<0?1.25:1/1.25),x2t(e.clientX-r.left))},{passive:false});
function pan(d){var w=view.b-view.a,a=cl(view.a+d,0,DUR-w);view={a:a,b:a+w}}
$("bl").onclick=function(){pan(-(view.b-view.a)*.25)};$("br").onclick=function(){pan((view.b-view.a)*.25)};
function step(w){var c=[.1,.2,.5,1,2,5,10,15,30,60,120,300];for(var i=0;i<c.length;i++)if(w/c[i]<=10)return c[i];return 600}
function lanesOf(){var ls=[],m={};S.els.slice().sort(function(a,b){return a.s-b.s}).forEach(function(e){var i=0;while(ls[i]!==undefined&&ls[i]>e.s)i++;ls[i]=e.e;m[e.id]=i});return m}
var CLR={comment:"#2563eb",hl:"#dc2626",zoom:"#059669",skip:"#6b7280"},NM={comment:"コメント",hl:"強調枠",zoom:"拡大枠",skip:"スキップ"};
function tlRender(){var w=view.b-view.a,st=step(w),h="",t0=Math.ceil(view.a/st)*st;
 for(var t=t0;t<=view.b+1e-6;t+=st)h+='<div class="tick" style="left:'+t2x(t)+'px"><span>'+ft(t,st<1?1:0)+'</span></div>';
 if(DUR>=view.a&&DUR<=view.b)h+='<div class="end" style="left:'+t2x(DUR)+'px">終了</div>';$("ruler").innerHTML=h;
 var lm=lanesOf(),ln="";S.els.forEach(function(e){var l=t2x(e.s),r=t2x(e.e);if(r<0||l>W())return;
  ln+='<div class="el'+(S.sel.indexOf(e.id)>=0?" sel":"")+'" data-id="'+e.id+'" style="left:'+l+'px;width:'+Math.max(8,r-l)+'px;top:'+(6+lm[e.id]*26)+'px;background:'+CLR[e.type]+'"><i class="l"></i>'+NM[e.type]+'<i class="r"></i></div>'});
 var t=T();if(t>=view.a&&t<=view.b)ln+='<div class="cur" style="left:'+t2x(t)+'px"><i id="hdl"></i></div>';$("lanes").innerHTML=ln;
 var sc=scale();$("zt").textContent=(sc<1.005?"全体表示 ":"")+sc.toFixed(1)+"倍";$("rt").textContent="表示範囲 "+ft(view.a,1)+" 〜 "+ft(view.b,1)+"(幅 "+w.toFixed(1)+"秒)";
 $("pos").textContent=ft(T(),1)+" / "+ft(DUR,1);
 if(document.activeElement!==$("zoom")||!zdrag)$("zoom").value=Math.round(1000*Math.log(sc)/Math.log(DUR/Math.min(MINV,DUR)||1)||0);
 var b="";S.els.forEach(function(e){b+='<div class="mk" style="left:'+e.s/DUR*100+'%;width:'+Math.max(.5,(e.e-e.s)/DUR*100)+'%"></div>'});
 b+='<div class="pc" style="left:'+T()/DUR*100+'%"></div><div class="view" id="vw" style="left:'+view.a/DUR*100+'%;width:'+w/DUR*100+'%"><div class="hd l"></div><div class="hd r"></div></div>';$("bar").innerHTML=b}
var zdrag=false;$("zoom").onpointerdown=function(){zdrag=true};window.addEventListener("pointerup",function(){zdrag=false});
/* タイムライン操作 */
$("ruler").onpointerdown=function(e){var r=$("tl").getBoundingClientRect();pdrag={k:"ruler"};setT(x2t(e.clientX-r.left));$("ruler").setPointerCapture(e.pointerId)};
$("ruler").onpointermove=function(e){if(pdrag&&pdrag.k==="ruler"){var r=$("tl").getBoundingClientRect();setT(x2t(e.clientX-r.left))}};
$("ruler").onpointerup=function(){pdrag=null};
$("lanes").onpointerdown=function(e){if(e.button!==0)return;var r=$("tl").getBoundingClientRect(),x=e.clientX-r.left;
 if(e.target.id==="hdl"){pdrag={k:"hdl"};$("lanes").setPointerCapture(e.pointerId);return}
 var el=e.target.closest(".el");
 if(el){var id=el.dataset.id,o=find(S.els,id);pick(id,e.shiftKey);var zone=e.target.tagName==="I"?e.target.className:"m";
  drag={k:"tl",id:id,zone:zone,x0:x,s:o.s,e:o.e,manual:true};$("lanes").setPointerCapture(e.pointerId)}
 else{if(!e.shiftKey)S.sel=[];closeMenu();drag={k:"pan",x0:e.clientX,a:view.a,manual:true};$("lanes").setPointerCapture(e.pointerId)}};
$("lanes").onpointermove=function(e){var r=$("tl").getBoundingClientRect(),x=e.clientX-r.left;
 if(pdrag&&pdrag.k==="hdl"){setT(x2t(x));return}
 if(!drag)return;
 if(drag.k==="pan"){var dt=(e.clientX-drag.x0)/W()*(view.b-view.a),w=view.b-view.a,a=cl(drag.a-dt,0,DUR-w);view={a:a,b:a+w}}
 else if(drag.k==="tl"){var o=find(S.els,drag.id),dt=(x-drag.x0)/W()*(view.b-view.a),q=scale()>2?.1:.5;
  if(x>W()-8)pan((view.b-view.a)*.03);else if(x<8)pan(-(view.b-view.a)*.03);
  var rd=function(v){return Math.round(v/q)*q};
  if(drag.zone==="m"){var len=drag.e-drag.s,s=cl(rd(drag.s+dt),0,DUR-len);o.s=s;o.e=s+len}
  else if(drag.zone==="l")o.s=cl(rd(drag.s+dt),0,o.e-MINE);
  else o.e=cl(rd(drag.e+dt),o.s+MINE,DUR);dirty()}};
$("lanes").onpointerup=function(){pdrag=null;drag=null};
$("lanes").oncontextmenu=function(e){e.preventDefault();var el=e.target.closest(".el");if(el){if(S.sel.indexOf(el.dataset.id)<0)S.sel=[el.dataset.id];openMenu(e.clientX,e.clientY)}else{S.sel=[];openMenu(e.clientX,e.clientY,"base")}};
$("bar").onpointerdown=function(e){var r=$("bar").getBoundingClientRect(),x=(e.clientX-r.left)/r.width*DUR,w=view.b-view.a;
 if(e.target.classList.contains("hd")){drag={k:"bh",side:e.target.classList.contains("l")?"l":"r",manual:true}}
 else if(e.target.id==="vw"){drag={k:"bv",off:x-view.a,manual:true}}
 else{var a=cl(x-w/2,0,DUR-w);view={a:a,b:a+w};return}
 $("bar").setPointerCapture(e.pointerId)};
$("bar").onpointermove=function(e){if(!drag||(drag.k!=="bh"&&drag.k!=="bv"))return;var r=$("bar").getBoundingClientRect(),x=cl((e.clientX-r.left)/r.width*DUR,0,DUR),w=view.b-view.a;
 if(drag.k==="bv"){var a=cl(x-drag.off,0,DUR-w);view={a:a,b:a+w}}
 else if(drag.side==="l")view={a:Math.min(x,view.b-Math.min(MINV,DUR)),b:view.b};
 else view={a:view.a,b:Math.max(x,view.a+Math.min(MINV,DUR))}};
$("bar").onpointerup=function(){drag=null};
/* ---- 動画領域 ---- */
function vis(e,t){return t>=e.s&&t<e.e}
function pick(id,add){closeMenu();if(add){var i=S.sel.indexOf(id);if(i>=0)S.sel.splice(i,1);else S.sel.push(id)}else S.sel=[id]}
var zb=null;
function stageRender(){var st=$("stage"),R=st.getBoundingClientRect(),t=T(),h="",zs=S.els.filter(function(e){return e.type==="zoom"&&vis(e,t)});
 var z=zs.length?zs[zs.length-1]:null;
 st.style.transform=z?"":"";
 var map=function(e){if(!z)return{x:e.x,y:e.y,w:e.w,h:e.h};return{x:(e.x-z.x)/z.w,y:(e.y-z.y)/z.h,w:e.w/z.w,h:e.h/z.h}};
 var vs=S.els.filter(function(e){return e.type!=="skip"&&vis(e,t)});
 var key=vs.map(function(e){return e.id+e.x+e.y+e.w+e.h+(e.text||"")+(e.lc||"")+(e.lw||"")+(e.fill||"")+(e.bg||"")+(e.ds||"")+(e.shape||"")+(e.fs||"")+(e.font||"")+(e.tc||"")+(e.line||"")}).join("|")+S.sel.join(",")+(z?z.id+z.x+z.y+z.w+z.h:"")+JSON.stringify(S.cons)+(connFrom||"")+R.width;
 if(key===stageRender.k)return;stageRender.k=key;
 Array.prototype.forEach.call(st.querySelectorAll(".ve"),function(n){n.remove()});
 var PW=R.width,PH=R.height,rects={};
 vs.forEach(function(e){var m=map(e);rects[e.id]={x:m.x*PW,y:m.y*PH,w:m.w*PW,h:m.h*PH};var n=document.createElement("div");n.className="ve"+(e.type==="zoom"?" zbox":"")+(S.sel.indexOf(e.id)>=0?" sel":"");n.dataset.id=e.id;
  var r=rects[e.id];n.style.cssText="left:"+r.x+"px;top:"+r.y+"px;width:"+r.w+"px;height:"+r.h+"px;";
  if(e.type==="comment"){n.textContent=e.text;n.style.color=e.tc;n.style.fontSize=e.fs+"px";n.style.fontFamily=e.font;if(e.fill)n.style.background=e.bg;if(e.line)n.style.border=e.lw+"px solid "+e.lc}
  else if(e.type==="hl"){n.style.border="0";if(e.fill)n.style.background=e.lc+"33"}
  else{n.style.border="2px dashed #34d399";n.textContent="拡大枠";n.style.color="#34d399"}
  if(S.sel.length===1&&S.sel[0]===e.id){var g=document.createElement("div");g.className="rz";n.appendChild(g)}
  st.appendChild(n)});
 $("svg").setAttribute("viewBox","0 0 "+PW+" "+PH);var sv="";
 vs.filter(function(e){return e.type==="hl"}).forEach(function(e){var r=rects[e.id];sv+='<path d="'+shapePath(e.shape,[[r.x,r.y],[r.x+r.w,r.y],[r.x+r.w,r.y+r.h],[r.x,r.y+r.h]],true)+'" fill="none" stroke="'+e.lc+'" stroke-width="'+e.lw+'" stroke-dasharray="'+DASH[e.ds]+'"/>'});
 S.cons.forEach(function(c){var a=find(S.els,c.a),b=find(S.els,c.b);if(!a||!b||!vis(a,t)||!vis(b,t)||a.type==="zoom"&&false)return;var ra=rects[a.id],rb=rects[b.id];if(!ra||!rb)return;
  var pts=conPts(c,ra,rb,Object.keys(rects).filter(function(k){return k!==a.id&&k!==b.id}).map(function(k){return rects[k]}));
  var d=shapePath(c.shape,pts,false),sel=S.sel.indexOf(c.id)>=0;
  sv+='<path d="'+d+'" fill="none" stroke="'+(sel?"#facc15":c.lc)+'" stroke-width="'+(sel?c.lw+2:c.lw)+'" stroke-dasharray="'+DASH[c.ds]+'"/>';
  sv+='<path class="hit" data-con="'+c.id+'" d="'+d+'" fill="none" stroke="transparent" stroke-width="16"/>';
  sv+=endMark(c.ea,pts[0],pts[1],c.lc)+endMark(c.eb,pts[pts.length-1],pts[pts.length-2],c.lc)});
 if(connFrom){vs.forEach(function(e){if(e.id!==connFrom&&e.type!=="skip"){var r=rects[e.id];sv+='<rect x="'+r.x+'" y="'+r.y+'" width="'+r.w+'" height="'+r.h+'" fill="none" stroke="#f0abfc" stroke-width="3" stroke-dasharray="4 3"/>'}});
  sv+='<text x="8" y="18" fill="#f0abfc" font-size="13">接続先の要素をクリック(何もない場所で中止)</text>'}
 $("svg").innerHTML=sv;stageRender.rects=rects}
function anchor(r,side,to){var cx=r.x+r.w/2,cy=r.y+r.h/2;
 if(side==="auto"){var dx=to.x+to.w/2-cx,dy=to.y+to.h/2-cy;side=Math.abs(dx)*r.h>Math.abs(dy)*r.w?(dx>0?"r":"l"):(dy>0?"b":"t")}
 return side==="t"?[cx,r.y]:side==="b"?[cx,r.y+r.h]:side==="l"?[r.x,cy]:[r.x+r.w,cy]}
function hitRect(p,q,r){var mnx=Math.min(p[0],q[0]),mxx=Math.max(p[0],q[0]),mny=Math.min(p[1],q[1]),mxy=Math.max(p[1],q[1]);return!(mxx<r.x||mnx>r.x+r.w||mxy<r.y||mny>r.y+r.h)}
function conPts(c,ra,rb,others){var p=anchor(ra,c.sa,rb),q=anchor(rb,c.sb,ra);
 if(c.shape==="line")return[p,q];
 var mid=[[p[0],p[1],q[0],p[1]],[p[0],p[1],p[0],q[1]]].map(function(v){return[[v[0],v[1]],[v[2],v[3]],[q[0],q[1]]]});
 var ok=function(pts){return!others.some(function(r){return hitRect(pts[0],pts[1],r)||hitRect(pts[1],pts[2],r)})};
 for(var i=0;i<2;i++)if(ok(mid[i]))return mid[i];
 var top=Math.min(p[1],q[1],ra.y,rb.y)-20,alt=[p,[p[0],top],[q[0],top],q];return alt}
function shapePath(shape,pts,close){
 if(shape==="wave"){var d="M"+pts[0][0]+" "+pts[0][1],L=pts.concat(close?[pts[0]]:[]);
  for(var i=1;i<L.length;i++){var a=L[i-1],b=L[i],len=Math.hypot(b[0]-a[0],b[1]-a[1]),n=Math.max(2,Math.round(len/16)),nx=-(b[1]-a[1])/(len||1),ny=(b[0]-a[0])/(len||1);
   for(var k=1;k<=n;k++){var f=k/n,fx=a[0]+(b[0]-a[0])*f,fy=a[1]+(b[1]-a[1])*f,o=k===n?0:(k%2?4:-4);d+=" L"+(fx+nx*o)+" "+(fy+ny*o)}}return d}
 return"M"+pts.map(function(p){return p[0]+" "+p[1]}).join(" L")+(close?" Z":"")}
function endMark(k,p,q,c){if(k==="none"||!p||!q)return"";var ang=Math.atan2(p[1]-q[1],p[0]-q[0]);
 if(k==="arrow")return'<path d="M'+p[0]+" "+p[1]+" L"+(p[0]-12*Math.cos(ang-.4))+" "+(p[1]-12*Math.sin(ang-.4))+" L"+(p[0]-12*Math.cos(ang+.4))+" "+(p[1]-12*Math.sin(ang+.4))+'Z" fill="'+c+'"/>';
 if(k==="circle")return'<circle cx="'+p[0]+'" cy="'+p[1]+'" r="5" fill="'+c+'"/>';
 return'<rect x="'+(p[0]-5)+'" y="'+(p[1]-5)+'" width="10" height="10" fill="'+c+'"/>'}
/* 動画領域の操作 */
$("stage").addEventListener("pointerdown",function(e){if(e.button!==0)return;var st=$("stage"),R=st.getBoundingClientRect();
 var cn=e.target.getAttribute&&e.target.getAttribute("data-con");
 if(connFrom){var n=e.target.closest(".ve");if(n&&n.dataset.id!==connFrom){addCon(connFrom,n.dataset.id)}else if(!n){connFrom=null;toast("接続を中止しました")}stageRender.k=null;return}
 if(cn){pick(cn,e.shiftKey);stageRender.k=null;return}
 var n=e.target.closest(".ve");
 if(!n){if(!e.shiftKey)S.sel=[];closeMenu();stageRender.k=null;return}
 var id=n.dataset.id;if(S.sel.indexOf(id)<0||e.shiftKey)pick(id,e.shiftKey);
 if(S.sel.indexOf(id)<0){stageRender.k=null;return}
 var rz=e.target.classList.contains("rz");
 drag={k:rz?"rz":"mv",x0:e.clientX,y0:e.clientY,R:R,orig:S.sel.map(function(i){var o=find(S.els,i);return o?{o:o,x:o.x,y:o.y,w:o.w,h:o.h}:null}).filter(Boolean),id:id};
 st.setPointerCapture(e.pointerId);stageRender.k=null});
$("stage").addEventListener("pointermove",function(e){if(!drag||(drag.k!=="mv"&&drag.k!=="rz"))return;var dx=(e.clientX-drag.x0)/drag.R.width,dy=(e.clientY-drag.y0)/drag.R.height;
 var zs=S.els.filter(function(x){return x.type==="zoom"&&vis(x,T())}),z=zs.length?zs[zs.length-1]:null;if(z){dx*=z.w;dy*=z.h}
 drag.orig.forEach(function(q){if(drag.k==="mv"){q.o.x=cl(q.x+dx,0,1-q.o.w);q.o.y=cl(q.y+dy,0,1-q.o.h)}else{q.o.w=cl(q.w+dx,.04,1-q.o.x);q.o.h=cl(q.h+dy,.04,1-q.o.y)}});dirty();stageRender.k=null});
$("stage").addEventListener("pointerup",function(){if(drag&&(drag.k==="mv"||drag.k==="rz"))drag=null});
$("stage").addEventListener("contextmenu",function(e){e.preventDefault();var cn=e.target.getAttribute&&e.target.getAttribute("data-con"),n=e.target.closest(".ve");
 if(cn){if(S.sel.indexOf(cn)<0)S.sel=[cn]}else if(n){if(S.sel.indexOf(n.dataset.id)<0)S.sel=[n.dataset.id]}else S.sel=[];
 stageRender.k=null;openMenu(e.clientX,e.clientY,(cn||n)?null:"base")});
function addCon(a,b){connFrom=null;if(a===b)return;if(S.cons.some(function(c){return c.a===a&&c.b===b})){toast("同じ向きの接続は既にあります");return}
 S.cons.push({id:uid("c"),a:a,b:b,shape:"line",ds:"solid",lc:"#facc15",lw:3,sa:"auto",sb:"auto",ea:"none",eb:"none"});S.sel=[];dirty();toast("接続線を作成しました")}
/* ---- 要素追加・削除 ---- */
function addEl(type){var t=T(),e={id:uid("e"),type:type,s:Math.min(t,Math.max(0,DUR-MINE)),e:Math.min(DUR,t+Math.min(5,DUR)),x:.3,y:.3,w:.25,h:.15};
 if(e.e-e.s<MINE)e.s=Math.max(0,e.e-MINE);
 if(type==="comment"){e.text="コメント";e.lc="#ffffff";e.lw=2;e.line=true;e.tc="#ffffff";e.bg="#3b82f6";e.fill=true;e.fs=16;e.font="sans-serif"}
 if(type==="hl"){e.lc="#ef4444";e.lw=3;e.fill=false;e.ds="solid";e.shape="line"}
 if(type==="zoom"){e.w=.4;e.h=.4}
 if(type==="skip"){e.x=e.y=e.w=e.h=0}
 S.els.push(e);S.sel=[e.id];dirty();stageRender.k=null}
function delSel(){var ids=S.sel.slice(),cn=S.cons.filter(function(c){return ids.indexOf(c.a)>=0||ids.indexOf(c.b)>=0});
 var go=function(){S.els=S.els.filter(function(e){return ids.indexOf(e.id)<0});S.cons=S.cons.filter(function(c){return cn.indexOf(c)<0&&ids.indexOf(c.id)<0});S.sel=[];dirty();stageRender.k=null};
 var hasEl=ids.some(function(i){return find(S.els,i)});
 if(hasEl&&cn.length)modal("<p>選択した要素を削除します。つながっている接続線"+cn.length+"本も一緒に削除されます。</p>",[{t:"削除",c:"d"},{t:"キャンセル"}],function(i){if(i===0)go()});else go()}
/* ---- 操作メニュー ---- */
function closeMenu(){$("menu").innerHTML=""}
window.addEventListener("pointerdown",function(e){if(!e.target.closest(".menu")&&e.button===0)closeMenu()});
window.addEventListener("keydown",function(e){if(e.key==="Escape"){closeMenu();if(connFrom){connFrom=null;stageRender.k=null}}});
function sw(k,v){return'<div class="sw" data-k="'+k+'">'+COLORS.map(function(c){return'<b data-v="'+c+'" class="'+(c===v?"on":"")+'" style="background:'+c+'"></b>'}).join("")+'</div>'}
function wd(k,v){return'<div class="wd" data-k="'+k+'">'+[1,2,4,8].map(function(n){return'<b data-v="'+n+'" class="'+(n===v?"on":"")+'"><i style="height:'+n+'px"></i></b>'}).join("")+'</div>'}
function sel1(k,v,o){return'<select data-k="'+k+'">'+o.map(function(x){return'<option value="'+x[0]+'"'+(x[0]===v?" selected":"")+'>'+x[1]+'</option>'}).join("")+'</select>'}
function openMenu(x,y,mode){var h="",o=S.sel.length===1?(find(S.els,S.sel[0])||find(S.cons,S.sel[0])):null,tgt=null;
 if(mode==="base"||!S.sel.length){h='<h4>基本操作</h4><button class="mi" data-a="pick">動画を選択…</button><button class="mi" data-a="add-comment">コメントを追加</button><button class="mi" data-a="add-hl">強調枠を追加</button><button class="mi" data-a="add-zoom">拡大枠を追加</button><button class="mi" data-a="add-skip">スキップを追加</button>'}
 else if(S.sel.length>1){h='<h4>複数選択('+S.sel.length+'件)</h4><button class="mi" data-a="del">まとめて削除</button><button class="mi" data-a="align">表示時間を先頭に揃える</button>'}
 else if(o&&o.a){tgt=o;h='<h4>接続線</h4>'+
  '<label><span>線形</span>'+sel1("shape",o.shape,[["line","直線"],["poly","折れ線"],["wave","波線"]])+'</label>'+
  '<label><span>線種</span>'+sel1("ds",o.ds,Object.keys(DN).map(function(k){return[k,DN[k]]}))+'</label>'+
  '<label><span>太さ</span>'+wd("lw",o.lw)+'</label><label><span>色</span>'+sw("lc",o.lc)+'</label>'+
  '<label><span>始点位置</span>'+sel1("sa",o.sa,[["auto","自動"],["t","上"],["r","右"],["b","下"],["l","左"]])+'</label>'+
  '<label><span>終点位置</span>'+sel1("sb",o.sb,[["auto","自動"],["t","上"],["r","右"],["b","下"],["l","左"]])+'</label>'+
  '<label><span>始点終端</span>'+sel1("ea",o.ea,[["none","なし"],["arrow","矢印"],["circle","丸"],["sq","四角"]])+'</label>'+
  '<label><span>終点終端</span>'+sel1("eb",o.eb,[["none","なし"],["arrow","矢印"],["circle","丸"],["sq","四角"]])+'</label><button class="mi d" data-a="del">接続線を削除</button>'}
 else if(o){tgt=o;
  if(o.type==="comment")h='<h4>コメント</h4><label><span>内容</span><input type="text" data-k="text" value="'+esc(o.text)+'"></label>'+
   '<label><span>線</span><input type="checkbox" data-k="line"'+(o.line?" checked":"")+'></label><label><span>線の太さ</span>'+wd("lw",o.lw)+'</label><label><span>線の色</span>'+sw("lc",o.lc)+'</label>'+
   '<label><span>文字色</span>'+sw("tc",o.tc)+'</label><label><span>背景色</span>'+sw("bg",o.bg)+'</label><label><span>塗り潰し</span><input type="checkbox" data-k="fill"'+(o.fill?" checked":"")+'></label>'+
   '<label><span>文字サイズ</span><input type="number" data-k="fs" min="8" max="60" value="'+o.fs+'"></label>'+
   '<label><span>フォント</span><select data-k="font">'+FONTS.map(function(f){return'<option value="'+f+'" style="font-family:'+f+'"'+(f===o.font?" selected":"")+'>'+f+' あいうAbc</option>'}).join("")+'</select></label>';
  else if(o.type==="hl")h='<h4>強調枠</h4><label><span>線の太さ</span>'+wd("lw",o.lw)+'</label><label><span>線の色</span>'+sw("lc",o.lc)+'</label><label><span>塗り潰し</span><input type="checkbox" data-k="fill"'+(o.fill?" checked":"")+'></label>'+
   '<label><span>線種</span>'+sel1("ds",o.ds,Object.keys(DN).map(function(k){return[k,DN[k]]}))+'</label><label><span>線形</span>'+sel1("shape",o.shape,[["line","直線"],["poly","折れ線"],["wave","波線"]])+'</label>';
  else if(o.type==="zoom")h='<h4>拡大枠</h4><label><span>X位置</span><input type="number" step="0.05" min="0" max="1" data-k="x" value="'+o.x.toFixed(2)+'"></label><label><span>Y位置</span><input type="number" step="0.05" min="0" max="1" data-k="y" value="'+o.y.toFixed(2)+'"></label><label><span>幅</span><input type="number" step="0.05" min="0.1" max="1" data-k="w" value="'+o.w.toFixed(2)+'"></label><label><span>高さ</span><input type="number" step="0.05" min="0.1" max="1" data-k="h" value="'+o.h.toFixed(2)+'"></label><p class="c">重なる場合は後から追加した拡大枠を優先します。</p>';
  else h='<h4>スキップ</h4><label><span>開始(秒)</span><input type="number" step="0.5" data-k="s" value="'+o.s.toFixed(1)+'"></label><label><span>終了(秒)</span><input type="number" step="0.5" data-k="e" value="'+o.e.toFixed(1)+'"></label>';
  if(o.type!=="skip")h+='<button class="mi" data-a="conn">ここから接続を開始</button>';
  h+='<button class="mi d" data-a="del">削除</button>'}
 var m=$("menu");m.innerHTML='<div class="menu" style="left:'+Math.min(x,innerWidth-270)+'px;top:'+Math.min(y,innerHeight-300)+'px">'+h+'</div>';
 var mn=m.firstChild;
 Array.prototype.forEach.call(mn.querySelectorAll("[data-a]"),function(b){b.onclick=function(){var a=b.dataset.a;closeMenu();
  if(a==="pick")$("file").click();else if(a.indexOf("add-")===0)addEl(a.slice(4));else if(a==="del")delSel();
  else if(a==="conn"){connFrom=S.sel[0];stageRender.k=null;toast("接続先の要素をクリックしてください")}
  else if(a==="align"){var s0=Math.min.apply(null,S.sel.map(function(i){return find(S.els,i).s}));S.sel.forEach(function(i){var e=find(S.els,i);if(e){var l=e.e-e.s;e.s=s0;e.e=Math.min(DUR,s0+l)}});dirty()}}});
 var set=function(k,v){if(!tgt)return;tgt[k]=v;if(k==="s"||k==="e"){tgt.s=cl(tgt.s,0,DUR-MINE);tgt.e=cl(tgt.e,tgt.s+MINE,DUR)}dirty();stageRender.k=null};
 Array.prototype.forEach.call(mn.querySelectorAll("input,select"),function(i){i.onchange=i.oninput=function(){var k=i.dataset.k;set(k,i.type==="checkbox"?i.checked:i.type==="number"?+i.value:i.value)}});
 Array.prototype.forEach.call(mn.querySelectorAll(".sw b,.wd b"),function(b){b.onclick=function(){var p=b.parentNode;set(p.dataset.k,p.classList.contains("wd")?+b.dataset.v:b.dataset.v);Array.prototype.forEach.call(p.children,function(c){c.classList.remove("on")});b.classList.add("on")}})}
/* ---- 保存・出力・終了 ---- */
function doSave(as){var finish=function(name){var id=uid("w");
  if(S.work&&!as){S.work.els=JSON.parse(JSON.stringify(S.els));S.work.cons=JSON.parse(JSON.stringify(S.cons));S.work.saved=new Date()}
  else{if(works.length>=LIM){delWorkFlow(function(){fin
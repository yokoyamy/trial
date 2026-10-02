<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック（初期画面・タイムライン）</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;overflow-x:hidden;font-family:"Hiragino Sans","Meiryo",sans-serif;background:#f3f4f6;color:#222}
button{font:inherit;cursor:pointer;border:1px solid #9ca3af;background:#fff;border-radius:4px;padding:4px 10px}
button.primary{background:#2563eb;color:#fff;border-color:#1d4ed8}
button.danger{color:#b91c1c;border-color:#fca5a5}
h2{font-size:16px;margin:0 0 8px}
.wrap{max-width:1000px;margin:0 auto;padding:16px}
.hidden{display:none!important}
.card{background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:12px;margin-bottom:16px}
.cnt{color:#6b7280;font-weight:normal;font-size:13px}
.cnt.full{color:#b91c1c;font-weight:bold}
.orig{border:2px solid #93c5fd;border-radius:6px;margin:10px 0;background:#f8fbff}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.row .sp{flex:1}
.orig-head{padding:8px;background:#dbeafe}
.works{margin:8px 8px 8px 28px;border-left:3px solid #93c5fd;padding-left:10px}
.work{padding:5px 0;border-bottom:1px dashed #d1d5db}
.work:last-child{border-bottom:none}
.empty{color:#6b7280;font-style:italic}
.res{padding:6px 0;border-bottom:1px solid #e5e7eb}
.mask{position:fixed;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;z-index:50}
.modal{background:#fff;border-radius:6px;padding:16px;max-width:460px;width:92%}
.modal .btns{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
.modal label{display:block;margin:4px 0}
.topbar{padding:8px 12px;background:#1f2937;color:#fff}
.dirty{color:#fbbf24}
.video{background:#000;color:#fff;height:240px;margin:12px auto;max-width:700px;display:flex;align-items:center;justify-content:center;font-size:28px;border-radius:4px}
.tl-wrap{max-width:900px;margin:0 auto;padding:0 12px 16px}
.tl-wrap .row{margin-bottom:8px}
.num{background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:13px}
.tl{position:relative;background:#fff;border:1px solid #9ca3af;border-radius:4px;overflow:hidden;width:100%;user-select:none}
.ruler{position:relative;height:28px;border-bottom:1px solid #9ca3af;background:#f9fafb;cursor:pointer}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #9ca3af}
.tick span{position:absolute;top:2px;left:3px;font-size:11px;white-space:nowrap}
.lanes{position:relative;height:120px;cursor:grab}
.el{position:absolute;height:22px;border-radius:3px;color:#fff;font-size:11px;padding:2px 6px;overflow:hidden;white-space:nowrap}
.edge{position:absolute;top:0;bottom:0;width:18px;background:rgba(245,158,11,.35);display:flex;align-items:center;justify-content:center;z-index:3}
.edge.l{left:0}.edge.r{right:0}
.endmark{position:absolute;top:0;bottom:0;border-left:2px solid #dc2626;z-index:2}
.endmark span{position:absolute;top:0;left:3px;font-size:10px;color:#dc2626;background:#fff}
.cursor{position:absolute;top:0;bottom:0;border-left:2px solid #e11d48;z-index:4;pointer-events:none}
.bar{position:relative;height:26px;background:#e5e7eb;border:1px solid #9ca3af;border-radius:4px;margin-top:8px}
.bar .mk{position:absolute;top:9px;height:8px;background:rgba(100,116,139,.45)}
.bar .pc{position:absolute;top:0;bottom:0;width:2px;background:rgba(225,29,72,.6)}
.bar .view{position:absolute;top:0;bottom:0;background:rgba(37,99,235,.3);border:2px solid #2563eb;border-radius:3px;cursor:grab;min-width:6px}
.hint{color:#6b7280;font-size:12px;margin-top:6px}
</style>
</head>
<body>

<div id="home" class="wrap">
  <div class="card">
    <h2>動画を選択</h2>
    <p class="cnt">現在、編集対象の動画は選択されていません。</p>
    <button class="primary" id="btnPick">動画を選択</button>
  </div>
  <div class="card"><h2>オリジナル動画と編集作業 <span class="cnt" id="origCnt"></span></h2><div id="origList"></div></div>
  <div class="card"><h2>編集結果の動画 <span class="cnt" id="resCnt"></span></h2><div id="resList"></div></div>
</div>

<div id="editor" class="hidden">
  <div class="topbar row">
    <b>動画: <span id="edVideo"></span></b>
    <b>編集作業: <span id="edWork"></span></b>
    <span class="dirty hidden" id="edDirty">● 未保存の変更あり</span>
    <span class="sp"></span>
    <button id="btnEnd">編集作業を終了する</button>
  </div>
  <div class="video" id="videoText"></div>
  <div class="tl-wrap">
    <div class="row">
      <button id="btnPlay">▶ 再生</button>
      <button id="btnPause">⏸ 一時停止</button>
      <span class="num" id="posTxt"></span>
      <span class="sp"></span>
      <button id="btnOut">－</button>
      <input type="range" id="zoom" min="0" max="100" value="0" style="width:140px">
      <button id="btnIn">＋</button>
      <span class="num" id="zoomTxt"></span>
      <button id="btnFit">全体表示に戻す</button>
    </div>
    <div class="row"><span class="num" id="rangeTxt"></span></div>
    <div class="tl" id="tl"><div class="ruler" id="ruler"></div><div class="lanes" id="lanes"></div></div>
    <div class="bar" id="bar"></div>
    <div class="hint">ホイール：拡大縮小（マウス位置中心）／ レーンのドラッグ：表示範囲の移動 ／ 目盛りのクリック・ドラッグ：再生位置の変更 ／ 下の帯：全体の長さと表示範囲</div>
  </div>
</div>

<div id="modalRoot"></div>

<script>
(function(){
"use strict";
var LIMIT=10,MIN_SPAN=0.5,seq=1;
function $(id){return document.getElementById(id);}
function uid(p){return p+(seq++);}
function esc(t){return String(t).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c];});}
function z2(n){return ("0"+n).slice(-2);}
function fmtDT(d){return d.getFullYear()+"/"+z2(d.getMonth()+1)+"/"+z2(d.getDate())+" "+z2(d.getHours())+":"+z2(d.getMinutes());}
function fmtT(s,dec){var m=Math.floor(s/60),r=s-m*60;return m+":"+(r<10?"0":"")+r.toFixed(dec);}

/* ===== データ ===== */
var videos=[],works=[],results=[];
var v1={id:uid("v"),name:"操作手順_顧客登録.mp4",at:new Date(2026,8,20,10,0),dur:120};
var v2={id:uid("v"),name:"障害再現_決済画面.mp4",at:new Date(2026,8,25,14,30),dur:75};
var v3={id:uid("v"),name:"新機能デモ.mp4",at:new Date(2026,8,28,9,15),dur:45};
videos.push(v1,v2,v3);
works.push(
  {id:uid("w"),videoId:v1.id,name:"顧客登録_注釈入り",saved:new Date(2026,8,21,11,0),els:[{n:"コメント1",s:5,e:20,c:"#2563eb"},{n:"強調枠1",s:10,e:40,c:"#dc2626"},{n:"拡大枠1",s:60,e:90,c:"#059669"}]},
  {id:uid("w"),videoId:v1.id,name:"顧客登録_短縮版",saved:new Date(2026,8,22,16,45),els:[{n:"コメント1",s:0,e:15,c:"#2563eb"}]},
  {id:uid("w"),videoId:v2.id,name:"決済_原因箇所",saved:new Date(2026,8,26,10,20),els:[{n:"強調枠1",s:20,e:50,c:"#dc2626"}]}
);
results.push({id:uid("r"),name:"顧客登録_完成版",videoName:v1.name,at:new Date(2026,8,23,9,0)});

/* ===== モーダル ===== */
function closeModal(){$("modalRoot").innerHTML="";}
function modal(html,buttons){
  $("modalRoot").innerHTML='<div class="mask"><div class="modal">'+html+'<div class="btns" id="mBtns"></div></div></div>';
  buttons.forEach(function(b){
    var el=document.createElement("button");
    el.textContent=b.label;if(b.cls)el.className=b.cls;
    el.onclick=function(){closeModal();if(b.fn)b.fn();};
    $("mBtns").appendChild(el);
  });
}
function confirmDlg(msg,fn){modal("<p>"+msg+"</p>",[{label:"キャンセル"},{label:"削除する",cls:"danger",fn:fn}]);}

/* ===== 初期画面 ===== */
function setCnt(id,n){$(id).textContent="（"+n+"/"+LIMIT+"件）";$(id).className="cnt"+(n>=LIMIT?" full":"");}
function renderHome(){
  setCnt("origCnt",videos.length);setCnt("resCnt",results.length);
  var h=videos.length?"":'<div class="empty">オリジナル動画はありません</div>';
  videos.forEach(function(v){
    var ws=works.filter(function(w){return w.videoId===v.id;}).sort(function(a,b){return b.saved-a.saved;});
    h+='<div class="orig"><div class="orig-head row"><b>🎬 '+esc(v.name)+'</b>'+
      '<span class="cnt">取り込み: '+fmtDT(v.at)+' ／ 編集作業: '+ws.length+'件</span><span class="sp"></span>'+
      '<button class="primary" data-act="new" data-id="'+v.id+'">新しい編集作業を始める</button>'+
      '<button class="danger" data-act="delV" data-id="'+v.id+'">削除</button></div><div class="works">';
    if(!ws.length)h+='<div class="empty">編集作業はありません</div>';
    ws.forEach(function(w){
      h+='<div class="work row"><span>📝 '+esc(w.name)+'</span><span class="cnt">最終保存: '+fmtDT(w.saved)+'</span><span class="sp"></span>'+
        '<button data-act="resume" data-id="'+w.id+'">再開</button>'+
        '<button class="danger" data-act="delW" data-id="'+w.id+'">削除</button></div>';
    });
    h+='</div></div>';
  });
  $("origList").innerHTML=h;
  var rh=results.length?"":'<div class="empty">編集結果の動画はありません</div>';
  results.forEach(function(r){
    rh+='<div class="res row"><span>🎞 '+esc(r.name)+'</span><span class="cnt">元: '+esc(r.videoName)+' ／ 作成: '+fmtDT(r.at)+'</span><span class="sp"></span>'+
      '<button class="danger" data-act="delR" data-id="'+r.id+'">削除</button></div>';
  });
  $("resList").innerHTML=rh;
}
function find(arr,id){return arr.filter(function(x){return x.id===id;})[0];}
function onAction(e){
  var b=e.target.closest("button[data-act]");if(!b)return;
  var id=b.getAttribute("data-id"),act=b.getAttribute("data-act");
  if(act==="new")startEdit(id,null);
  if(act==="resume")startEdit(find(works,id).videoId,id);
  if(act==="delW")confirmDlg("編集作業「"+esc(find(works,id).name)+"」を削除します。オリジナル動画と編集結果の動画には影響しません。",function(){
    works=works.filter(function(w){return w.id!==id;});renderHome();});
  if(act==="delV"){
    var n=works.filter(function(w){return w.videoId===id;}).length,v=find(videos,id);
    confirmDlg("動画「"+esc(v.name)+"」を削除します。"+(n?"<br><b>配下の編集作業"+n+"件も一緒に削除されます。</b>":"")+"<br>作成済みの編集結果の動画は残ります。",function(){
      videos=videos.filter(function(x){return x.id!==id;});works=works.filter(function(w){return w.videoId!==id;});renderHome();});
  }
  if(act==="delR")confirmDlg("この編集結果の動画を削除します。",function(){
    results=results.filter(function(r){return r.id!==id;});renderHome();});
}
$("origList").onclick=onAction;$("resList").onclick=onAction;

/* 動画選択：10件超過時は削除する動画を操作者が選ぶ */
function importVideo(name,dur){
  var v={id:uid("v"),name:name,at:new Date(),dur:dur};
  videos.push(v);startEdit(v.id,null);
}
$("btnPick").onclick=function(){
  function pick(){
    modal('<p>取り込む動画を選択（モック）</p>'+
      '<label><input type="radio" name="pick" value="60" checked> 操作デモ.mp4（1:00）</label>'+
      '<label><input type="radio" name="pick" value="180"> 画面収録.mp4（3:00）</label>'+
      '<label><input type="radio" name="pick" value="20"> 短い動画.mp4（0:20）</label>',
      [{label:"キャンセル"},{label:"選択",cls:"primary",fn:function(){
        var s=document.querySelector('input[name="pick"]:checked');
        importVideo(s.parentNode.textContent.trim().replace(/（.*$/,""),+s.value);
      }}]);
  }
  if(videos.length<LIMIT){pick();return;}
  var h='<p><b>オリジナル動画は'+LIMIT+'件までです。</b>削除するものを選んでください（自動では削除しません）。</p>';
  videos.forEach(function(v,i){
    var n=works.filter(function(w){return w.videoId===v.id;}).length;
    h+='<label><input type="radio" name="del" value="'+v.id+'"'+(i?'':' checked')+'> '+esc(v.name)+'（配下の編集作業'+n+'件も削除）</label>';
  });
  modal(h,[{label:"キャンセル"},{label:"削除して続ける",cls:"danger",fn:function(){
    var id=document.querySelector('input[name="del"]:checked').value;
    videos=videos.filter(function(x){return x.id!==id;});works=works.filter(function(w){return w.videoId!==id;});
    renderHome();pick();
  }}]);
};

/* ===== 編集画面 ===== */
var ed={dur:60,els:[],pos:0,vs:0,ve:60,timer:null,workId:null,workName:"",videoId:null};
var userMoving=false;
function startEdit(videoId,workId){
  var v=find(videos,videoId),w=workId?find(works,workId):null;
  ed.videoId=videoId;ed.workId=workId;ed.workName=w?w.name:"";
  ed.dur=v.dur;ed.pos=0;ed.vs=0;ed.ve=v.dur;
  ed.els=w?w.els:[{n:"コメント1",s:3,e:12,c:"#2563eb"},{n:"強調枠1",s:8,e:25,c:"#dc2626"},{n:"拡大枠1",s:10,e:20,c:"#059669"}];
  $("home").classList.add("hidden");$("editor").classList.remove("hidden");
  $("edVideo").textContent=v.name;
  $("edWork").textContent=w?w.name:"未保存の編集作業";
  renderTL();
}
$("btnEnd").onclick=function(){
  stopPlay();
  $("editor").classList.add("hidden");$("home").classList.remove("hidden");renderHome();
};

/* 再生 */
function stopPlay(){if(ed.timer){clearInterval(ed.timer);ed.timer=null;}}
$("btnPlay").onclick=function(){
  if(ed.timer)return;
  if(ed.pos>=ed.dur)ed.pos=0;
  var last=performance.now();
  ed.timer=setInterval(function(){
    var now=performance.now();ed.pos=Math.min(ed.dur,ed.pos+(now-last)/1000);last=now;
    if(ed.pos>=ed.dur)stopPlay();
    follow();renderTL();
  },50);
};
$("btnPause").onclick=stopPlay;
function follow(){ /* 再生位置が範囲外に出そうなら表示範囲を追従。操作中は妨げない */
  var span=ed.ve-ed.vs;
  if(userMoving||span>=ed.dur)return;
  if(ed.pos>ed.ve-span*0.05)setView(ed.pos-span*0.7);
  else if(ed.pos<ed.vs)setView(ed.pos-span*0.1);
}

/* 表示範囲・倍率 */
function setView(start,span){
  if(span===undefined)span=ed.ve-ed.vs;
  span=Math.max(MIN_SPAN,Math.min(ed.dur,span));
  ed.vs=Math.max(0,Math.min(ed.dur-span,start));ed.ve=ed.vs+span;
}
function zoomAt(factor,t){ /* t の時間位置を固定して拡大縮小 */
  var span=ed.ve-ed.vs,ns=Math.max(MIN_SPAN,Math.min(ed.dur,span/factor));
  setView(t-(t-ed.vs)/span*ns,ns);
}
function center(){return (ed.pos>=ed.vs&&ed.pos<=ed.ve)?ed.pos:(ed.vs+ed.ve)/2;}
function t2x(t,w){return (t-ed.vs)/(ed.ve-ed.vs)*w;}
function x2t(x,w){return ed.vs+x/w*(ed.ve-ed.vs);}
$("btnIn").onclick=function(){zoomAt(1.5,center());renderTL();};
$("btnOut").onclick=function(){zoomAt(1/1.5,center());renderTL();};
$("btnFit").onclick=function(){setView(0,ed.dur);renderTL();};
$("zoom").oninput=function(){
  var sc=Math.exp(Math.log(ed.dur/MIN_SPAN)*this.value/100),c=center(),ns=ed.dur/sc;
  setView(c-(c-ed.vs)/(ed.ve-ed.vs)*ns,ns);renderTL();
};
$("tl").addEventListener("wheel",function(e){
  e.preventDefault();
  var r=$("tl").getBoundingClientRect();
  zoomAt(e.deltaY<0?1.25:0.8,x2t(e.clientX-r.left,r.width));renderTL();
},{passive:false});

/* 描画 */
function niceStep(span,w){
  var target=span/Math.max(2,w/90),c=[0.05,0.1,0.2,0.5,1,2,5,10,15,30,60,120,300,600];
  for(var i=0;i<c.length;i++)if(c[i]>=target)return c[i];
  return 600;
}
function renderTL(){
  var w=$("tl").clientWidth,span=ed.ve-ed.vs,step=niceStep(span,w),dec=step<0.1?2:(step<1?1:0),h="";
  for(var t=Math.ceil(ed.vs/step)*step;t<=ed.ve+1e-9;t+=step)
    h+='<div class="tick" style="left:'+t2x(t,w)+'px"><span>'+fmtT(t,dec)+'</span></div>';
  $("ruler").innerHTML=h;

  /* 同じ時間帯の要素は縦に並べる */
  var rows=[],lh="",lHid=false,rHid=false;
  ed.els.map(function(el){return {el:el};}).sort(function(a,b){return a.el.s-b.el.s;}).forEach(function(p){
    var r=0;while(rows[r]!==undefined&&rows[r]>p.el.s)r++;
    rows[r]=p.el.e;
    var el=p.el;
    if(el.s<ed.vs)lHid=true;
    if(el.e>ed.ve)rHid=true;
    if(el.e<ed.vs||el.s>ed.ve)return;
    var x1=Math.max(0,t2x(el.s,w)),x2=Math.min(w,t2x(el.e,w));
    lh+='<div class="el" style="left:'+x1+'px;width:'+Math.max(2,x2-x1)+'px;top:'+(6+r*26)+'px;background:'+el.c+'" title="'+esc(el.n)+' '+fmtT(el.s,1)+'〜'+fmtT(el.e,1)+'">'+esc(el.n)+'</div>';
  });
  if(ed.dur<=ed.ve)lh+='<div class="endmark" style="left:'+Math.min(w-2,t2x(ed.dur,w))+'px"><span>終了</span></div>';
  if(lHid)lh+='<div class="edge l" title="左側に要素があります">◀</div>';
  if(rHid)lh+='<div class="edge r" title="右側に要素があります">▶</div>';
  $("lanes").innerHTML=lh;

  var old=$("tl").querySelector(".cursor");if(old)old.remove();
  if(ed.pos>=ed.vs&&ed.pos<=ed.ve){
    var c=document.createElement("div");c.className="cursor";c.style.left=t2x(ed.pos,w)+"px";$("tl").appendChild(c);
  }

  var bh="";
  ed.els.forEach(function(el){bh+='<div class="mk" style="left:'+el.s/ed.dur*100+'%;width:'+Math.max(0.5,(el.e-el.s)/ed.dur*100)+'%"></div>';});
  bh+='<div class="pc" style="left:'+ed.pos/ed.dur*100+'%"></div>'+
      '<div class="view" id="barView" style="left:'+ed.vs/ed.dur*100+'%;width:'+span/ed.dur*100+'%"></div>';
  $("bar").innerHTML=bh;

  var sc=ed.dur/span;
  $("rangeTxt").textContent="表示範囲: "+fmtT(ed.vs,2)+" 〜 "+fmtT(ed.ve,2)+"（幅 "+span.toFixed(2)+"秒）";
  $("posTxt").textContent="再生位置 "+fmtT(ed.pos,1)+" / 総時間 "+fmtT(ed.dur,1);
  $("zoomTxt").textContent=(Math.round(sc*10)/10)+"倍"+(sc<1.001?"（全体表示）":"");
  $("zoom").value=Math.round(Math.log(sc)/Math.log(ed.dur/MIN_SPAN)*100);
  $("videoText").textContent=fmtT(ed.pos,1);
}

/* マウス操作：再生位置の変更／表示範囲の移動／帯の操作 */
var drag=null;
function seek(e){var r=$("tl").getBoundingClientRect();ed.pos=Math.max(0,Math.min(ed.dur,x2t(e.clientX-r.left,r.width)));renderTL();}
$("ruler").addEventListener("mousedown",function(e){drag={k:"seek"};userMoving=true;seek(e);});
$("lanes").addEventListener("mousedown",function(e){drag={k:"pan",x:e.clientX,vs:ed.vs};userMoving=true;});
$("bar").addEventListener("mousedown",function(e){
  if(e.target.id!=="barView"){
    var r=$("bar").getBoundingClientRect();
    setView((e.clientX-r.left)/r.width*ed.dur-(ed.ve-ed.vs)/2);renderTL();
  }
  drag={k:"bar",x:e.clientX,vs:ed.vs};userMoving=true;
});
window.addEventListener("mousemove",function(e){
  if(!drag)return;
  if(drag.k==="seek")seek(e);
  if(drag.k==="pan"){setView(drag.vs-(e.clientX-drag.x)/$("tl").clientWidth*(ed.ve-ed.vs));renderTL();}
  if(drag.k==="bar"){setView(drag.vs+(e.clientX-drag.x)/$("bar").clientWidth*ed.dur);renderTL();}
});
window.addEventListener("mouseup",function(){drag=null;userMoving=false;});
window.addEventListener("resize",function(){if(!$("editor").classList.contains("hidden"))renderTL();});

renderHome();
})();
</script>
</body>
</html>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Yu Gothic",sans-serif;font-size:13px;color:#222;background:#f3f4f6;overflow-x:hidden}
button{cursor:pointer;font-size:12px;padding:4px 8px;border:1px solid #999;background:#fff;border-radius:3px}
button:hover{background:#eef}
button.danger{color:#b00;border-color:#b00}
button.primary{background:#2563eb;color:#fff;border-color:#2563eb}
h2{margin:0 0 8px;font-size:15px}
#home{padding:16px;display:grid;grid-template-columns:1.4fr 1fr;gap:16px}
.panel{background:#fff;border:1px solid #ccc;border-radius:6px;padding:12px}
.vrow{border:1px solid #bbb;border-radius:4px;margin-bottom:10px;background:#fafafa}
.vhead{padding:8px;background:#e8edf5;display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap}
.children{margin:6px 6px 8px 28px;border-left:3px solid #2563eb;padding-left:8px}
.wrow{display:flex;justify-content:space-between;align-items:center;padding:4px;border-bottom:1px dotted #ccc;gap:6px}
.empty{color:#888;padding:4px}
.small{color:#666;font-size:11px}
#edit{display:none;flex-direction:column;height:100vh}
#topbar{background:#1f2937;color:#fff;padding:6px 10px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
#topbar button{background:#374151;color:#fff;border-color:#555}
#topbar .info{margin-right:auto}
#dirty{color:#fbbf24;font-weight:bold}
#stage{flex:1;min-height:0;display:flex;justify-content:center;align-items:center;background:#111;padding:6px}
#vwrap{position:relative;width:640px;height:360px;background:#000;overflow:hidden;user-select:none}
#vcontent{position:absolute;left:0;top:0;width:640px;height:360px;transform-origin:0 0;background:linear-gradient(135deg,#1e3a8a,#059669 50%,#d97706)}
#vcontent .grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.12) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.12) 1px,transparent 1px);background-size:40px 40px}
#vlabel{position:absolute;left:8px;bottom:6px;color:#fff;font-size:12px;opacity:.8}
.el{position:absolute;cursor:move}
.el.sel{outline:2px dashed #fde047;outline-offset:2px}
.el .rh{position:absolute;right:-5px;bottom:-5px;width:10px;height:10px;background:#fde047;border:1px solid #333;cursor:nwse-resize;display:none}
.el.sel .rh{display:block}
.el.target{outline:3px solid #22c55e}
.cm{display:flex;align-items:center;justify-content:center;text-align:center;overflow:hidden;padding:2px}
.zm{border:2px solid #38bdf8;background:rgba(56,189,248,.12)}
#svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
#svg .hit{pointer-events:stroke;cursor:pointer;stroke:transparent;stroke-width:16;fill:none}
#banner{position:absolute;top:4px;left:50%;transform:translateX(-50%);background:#22c55e;color:#fff;padding:3px 10px;border-radius:10px;display:none;z-index:50;font-size:12px;white-space:nowrap}
#ctl{background:#e5e7eb;padding:6px 10px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;border-top:1px solid #aaa}
#ctl .sp{margin-left:auto}
#tlwrap{background:#fff;padding:4px 10px 8px;border-top:1px solid #ccc;height:290px;overflow:hidden}
#tlinfo{display:flex;gap:12px;font-size:12px;margin-bottom:3px;flex-wrap:wrap}
#tlrow{display:flex;gap:4px;align-items:stretch}
#tlrow>button{width:26px;padding:0}
#tlmain{flex:1;min-width:0;position:relative;padding-top:16px}
#ruler{position:relative;height:24px;background:#f1f5f9;border:1px solid #bbb;cursor:pointer}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #888;font-size:10px;padding-left:2px;pointer-events:none;white-space:nowrap}
.endmark{position:absolute;top:0;height:100%;border-left:3px solid #dc2626;pointer-events:none}
.endmark span{position:absolute;top:-1px;left:3px;font-size:10px;color:#dc2626;background:#fff}
#lanes{position:relative;height:150px;border:1px solid #bbb;border-top:none;background:#fff;overflow:hidden;cursor:grab}
.tb{position:absolute;height:22px;border-radius:3px;color:#fff;font-size:11px;line-height:22px;padding:0 10px;overflow:hidden;white-space:nowrap;cursor:pointer;border:1px solid rgba(0,0,0,.4)}
.tb.sel{outline:2px solid #fde047}
.tb .hl,.tb .hr{position:absolute;top:0;width:7px;height:100%;background:rgba(0,0,0,.35);cursor:ew-resize}
.tb .hl{left:0}.tb .hr{right:0}
#cursor{position:absolute;top:0;width:0;border-left:2px solid #dc2626;z-index:10;pointer-events:none}
#handle{position:absolute;top:0;left:-8px;width:16px;height:16px;border-radius:50%;background:#dc2626;border:2px solid #fff;cursor:ew-resize;pointer-events:auto}
#overview{position:relative;height:26px;margin-top:6px;background:#e2e8f0;border:1px solid #94a3b8}
#ovwin{position:absolute;top:0;height:100%;background:rgba(37,99,235,.25);border:2px solid #2563eb;cursor:grab}
#ovwin .g{position:absolute;top:0;width:8px;height:100%;background:#2563eb;cursor:ew-resize}
#ovwin .g.l{left:-2px}#ovwin .g.r{right:-2px}
.ovitem{position:absolute;height:3px;background:rgba(0,0,0,.4);pointer-events:none}
#ovplay{position:absolute;top:0;height:100%;border-left:2px solid #dc2626;pointer-events:none}
#menu{position:fixed;z-index:1000;background:#fff;border:1px solid #666;border-radius:4px;box-shadow:0 3px 10px rgba(0,0,0,.3);padding:6px;min-width:200px;max-width:270px;display:none;max-height:85vh;overflow:auto}
#menu .mt{font-weight:bold;border-bottom:1px solid #ccc;margin-bottom:4px;padding-bottom:2px}
#menu .mi{display:block;width:100%;text-align:left;margin:2px 0;border:none;background:none;padding:4px 6px}
#menu .mi:hover{background:#dbeafe}
#menu .mr{margin:4px 0}
#menu .mr>label{display:block;font-size:11px;color:#555}
#menu input[type=text],#menu input[type=number]{width:100%}
.sw{display:inline-block;width:18px;height:18px;border:1px solid #666;margin:1px;cursor:pointer}
.sw.on{outline:2px solid #2563eb}
.opt{display:inline-block;border:1px solid #999;margin:1px;padding:2px 4px;cursor:pointer;background:#fff;font-size:11px}
.opt.on{outline:2px solid #2563eb;background:#dbeafe}
.opt svg{display:block}
#modal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:2000}
#modal .box{background:#fff;padding:16px;border-radius:6px;min-width:340px;max-width:520px;max-height:80vh;overflow:auto}
#modal .btns{margin-top:12px;display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
#modal label.cand{display:block;padding:3px}
#player{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:1500;align-items:center;justify-content:center;flex-direction:column;color:#fff;gap:8px}
#player .pv{width:560px;height:315px;background:linear-gradient(135deg,#7c3aed,#0ea5e9);display:flex;align-items:center;justify-content:center;font-size:18px;text-align:center;padding:10px}
#progress{width:100%;height:14px;background:#ddd;border-radius:7px;overflow:hidden;margin-top:8px}
#progress div{height:100%;width:0;background:#2563eb}
</style>
</head>
<body>

<div id="home">
  <div class="panel">
    <h2 id="hOrig"></h2>
    <div style="margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap">
      <button class="primary" id="btnPick">動画を選択</button>
      <button id="btnSample">例題動画を取り込む</button>
      <button id="btnBroken">読み込めない動画を選ぶ（テスト）</button>
    </div>
    <input type="file" id="file" accept="video/*" style="display:none">
    <div id="homeMsg" style="color:#b00;margin-bottom:6px"></div>
    <div id="origList"></div>
    <div class="small">※編集対象の動画は未選択です。動画を選択するまで編集画面は開始しません。</div>
  </div>
  <div class="panel">
    <h2 id="hRes"></h2>
    <div id="resList"></div>
  </div>
</div>

<div id="edit">
  <div id="topbar">
    <div class="info">動画：<b id="eVideo"></b> ／ 編集作業：<b id="eWork"></b> <span id="dirty"></span></div>
    <button id="bSave">保存</button>
    <button id="bSaveAs">別の編集作業として保存</button>
    <button id="bExport">編集結果を動画にする</button>
    <button id="bEnd">編集作業を終了する</button>
  </div>
  <div id="stage">
    <div id="vwrap">
      <div id="vcontent"><div class="grid"></div></div>
      <svg id="svg"></svg>
      <div id="vlabel"></div>
      <div id="banner"></div>
    </div>
  </div>
  <div id="ctl">
    <button id="bPlay">▶ 再生</button>
    <button id="bPause">⏸ 一時停止</button>
    <button id="bStop">⏹ 停止</button>
    <label><input type="checkbox" id="skipChk" checked> 再生時にスキップ範囲を飛ばす</label>
    <span id="posInfo"></span>
    <span class="sp"></span>
    <button id="zOut">－</button>
    <input type="range" id="zSlider" min="0" max="1000" value="0" style="width:140px">
    <button id="zIn">＋</button>
    <span id="zInfo"></span>
    <button id="zReset">全体表示に戻す</button>
  </div>
  <div id="tlwrap">
    <div id="tlinfo"><span id="rangeInfo"></span></div>
    <div id="tlrow">
      <button id="pLeft">◀</button>
      <div id="tlmain">
        <div id="ruler"></div>
        <div id="lanes"></div>
        <div id="cursor"><div id="handle"></div></div>
      </div>
      <button id="pRight">▶</button>
    </div>
    <div id="overview"><div id="ovwin"><div class="g l"></div><div class="g r"></div></div></div>
  </div>
</div>

<div id="menu"></div>
<div id="modal"><div class="box" id="mbox"></div></div>
<div id="player"><div class="pv" id="pv"></div><button id="pClose">閉じる</button></div>

<script>
"use strict";
const $=id=>document.getElementById(id);
const LIM=10,MINVIS=0.5,MINDUR=0.3,DEF_DUR=5,VW=640,VH=360;
const COLORS=["#000000","#ffffff","#ef4444","#f97316","#eab308","#22c55e","#14b8a6","#3b82f6","#6366f1","#a855f7","#ec4899","#6b7280"];
const FONTS=["sans-serif","serif","monospace","cursive","Yu Mincho","Meiryo"];
const DASH={solid:"",dot:"2 4",dash:"8 5",dashdot:"10 4 2 4"};
const DASHN={solid:"実線",dot:"点線",dash:"破線",dashdot:"一点鎖線"};
const SHAPEN={line:"直線",poly:"折れ線",wave:"波線"};
const ENDN={none:"なし",arrow:"矢印",circle:"丸",square:"四角"};
const SIDEN={auto:"自動",top:"上",right:"右",bottom:"下",left:"左"};
const KIND={comment:"コメント",box:"強調枠",zoom:"拡大枠",skip:"スキップ"};
const KCOL={comment:"#2563eb",box:"#dc2626",zoom:"#0891b2",skip:"#6b7280"};

const S={videos:[],works:[],results:[],seq:1,cur:null,sel:[],selLine:null,time:0,dur:60,playing:false,vs:0,ve:60,connFrom:null};

const uid=()=>S.seq++;
const clamp=(v,a,b)=>Math.min(b,Math.max(a,v));
const esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
function fmtT(t,fine){t=Math.max(0,t);const m=Math.floor(t/60),s=t-m*60;return m+":"+(fine?s.toFixed(2).padStart(5,"0"):String(Math.floor(s)).padStart(2,"0"))}
function fmtD(ts){const d=new Date(ts);return d.getFullYear()+"/"+(d.getMonth()+1)+"/"+d.getDate()+" "+String(d.getHours()).padStart(2,"0")+":"+String(d.getMinutes()).padStart(2,"0")}

/* ---------- モーダル ---------- */
function modal(html,btns){
  $("mbox").innerHTML=html+'<div class="btns" id="mbtns"></div>';
  btns.forEach(b=>{const e=document.createElement("button");e.textContent=b.t;if(b.c)e.className=b.c;
    e.onclick=()=>{const v=b.f?b.f():null;if(v!==false)closeModal()};$("mbtns").appendChild(e)});
  $("modal").style.display="flex";
}
function closeModal(){$("modal").style.display="none"}

/* ---------- 初期画面 ---------- */
function renderHome(){
  $("hOrig").textContent=`オリジナル動画（${S.videos.length}/${LIM}件）`;
  $("hRes").textContent=`編集結果の動画（${S.results.length}/${LIM}件）`;
  let h=`<div class="small" style="margin-bottom:6px">編集作業 合計 ${S.works.length}/${LIM}件</div>`;
  if(!S.videos.length)h+='<div class="empty">オリジナル動画はありません。「動画を選択」から取り込んでください。</div>';
  S.videos.forEach(v=>{
    const ws=S.works.filter(w=>w.videoId===v.id).sort((a,b)=>b.saved-a.saved);
    h+=`<div class="vrow"><div class="vhead"><div><b>${esc(v.name)}</b><br><span class="small">取り込み：${fmtD(v.imported)} ／ 編集作業 ${ws.length}件</span></div>
    <div><button class="primary" data-new="${v.id}">新しい編集作業を始める</button> <button class="danger" data-delv="${v.id}">削除</button></div></div><div class="children">`;
    if(!ws.length)h+='<div class="empty">編集作業はありません</div>';
    ws.forEach(w=>{h+=`<div class="wrow"><div>${esc(w.name)}<br><span class="small">最終保存：${fmtD(w.saved)}</span></div>
      <div><button data-resume="${w.id}">再開</button> <button class="danger" data-delw="${w.id}">削除</button></div></div>`});
    h+="</div></div>";
  });
  $("origList").innerHTML=h;
  let r=S.results.length?"":'<div class="empty">編集結果の動画はありません</div>';
  S.results.forEach(x=>{
    const alive=S.videos.some(v=>v.id===x.videoId);
    r+=`<div class="wrow"><div><b>${esc(x.name)}</b><br><span class="small">元の動画：${esc(x.origName)}${alive?"":"（削除済み）"} ／ 作成：${fmtD(x.created)}</span></div>
    <div><button data-play="${x.id}">再生</button> <button class="danger" data-delr="${x.id}">削除</button></div></div>`});
  $("resList").innerHTML=r;
}
$("origList").onclick=e=>{
  const d=e.target.dataset;
  if(d.new)startWork(+d.new);
  else if(d.resume)resumeWork(+d.resume);
  else if(d.delv)delVideo(+d.delv);
  else if(d.delw)delWork(+d.delw);
};
$("resList").onclick=e=>{
  const d=e.target.dataset;
  if(d.play){const x=S.results.find(r=>r.id==d.play);$("pv").innerHTML=`編集結果の動画：${esc(x.name)}<br>元の動画：${esc(x.origName)}<br>（再生モック）`;$("player").style.display="flex"}
  if(d.delr){const x=S.results.find(r=>r.id==d.delr);
    modal(`編集結果の動画「${esc(x.name)}」を削除しますか？`,[{t:"削除する",c:"danger",f:()=>{S.results=S.results.filter(r=>r.id!=x.id);renderHome()}},{t:"キャンセル"}])}
};
$("pClose").onclick=()=>$("player").style.display="none";
$("btnPick").onclick=()=>$("file").click();
$("file").onchange=e=>{
  const f=e.target.files[0];if(!f)return;
  const url=URL.createObjectURL(f),vd=document.createElement("video");
  vd.preload="metadata";
  vd.onloadedmetadata=()=>importVideo(f.name,isFinite(vd.duration)?vd.duration:60);
  vd.onerror=()=>{$("homeMsg").textContent="選択した動画を読み込めませんでした。編集画面は開始しません。"};
  vd.src=url;e.target.value="";
};
$("btnSample").onclick=()=>importVideo("例題動画.mp4",60);
$("btnBroken").onclick=()=>{$("homeMsg").textContent="選択した動画「壊れた動画.mp4」を読み込めませんでした。編集画面は開始しません。"};

function importVideo(name,dur){
  $("homeMsg").textContent="";
  const go=()=>{const v={id:uid(),name,dur,imported:Date.now()};S.videos.push(v);renderHome();startWork(v.id)};
  if(S.videos.length<LIM){go();return}
  let h="オリジナル動画が上限（10件）です。削除する動画を選んでください。<br><small>※配下の編集作業も一緒に削除されます。</small><br>";
  S.videos.forEach((v,i)=>{const n=S.works.filter(w=>w.videoId===v.id).length;
    h+=`<label class="cand"><input type="radio" name="cand" value="${v.id}" ${i?"":"checked"}> ${esc(v.name)}（編集作業${n}件も削除）</label>`});
  modal(h,[{t:"削除して取り込む",c:"danger",f:()=>{
    const id=+document.querySelector('input[name=cand]:checked').value;
    S.videos=S.videos.filter(v=>v.id!==id);S.works=S.works.filter(w=>w.videoId!==id);go()}},{t:"キャンセル"}]);
}
function delVideo(id){
  const v=S.videos.find(x=>x.id===id),n=S.works.filter(w=>w.videoId===id).length,rn=S.results.filter(r=>r.videoId===id).length;
  modal(`動画「${esc(v.name)}」を削除しますか？`+(n?`<br><b>配下の編集作業${n}件も一緒に削除されます。</b>`:"")+(rn?`<br>作成済みの編集結果の動画（${rn}件）は残ります。`:""),
    [{t:"削除する",c:"danger",f:()=>{S.videos=S.videos.filter(x=>x.id!==id);S.works=S.works.filter(w=>w.videoId!==id);renderHome()}},{t:"キャンセル"}]);
}
function delWork(id){
  const w=S.works.find(x=>x.id===id);
  modal(`編集作業「${esc(w.name)}」を削除しますか？<br>オリジナル動画と編集結果の動画には影響しません。`,
    [{t:"削除する",c:"danger",f:()=>{S.works=S.works.filter(x=>x.id!==id);renderHome()}},{t:"キャンセル"}]);
}

/* ---------- 編集開始・再開・終了 ---------- */
function startWork(vid){
  const v=S.videos.find(x=>x.id===vid);
  S.cur={video:v,work:null,data:{els:[],lines:[],nid:1},dirty:false};openEditor();
}
function resumeWork(wid){
  const w=S.works.find(x=>x.id===wid),v=S.videos.find(x=>x.id===w.videoId);
  S.cur={video:v,work:w,data:JSON.parse(JSON.stringify(w.data)),dirty:false};openEditor();
}
function openEditor(){
  S.dur=S.cur.video.dur;S.time=0;S.vs=0;S.ve=S.dur;S.sel=[];S.selLine=null;S.connFrom=null;S.playing=false;
  $("home").style.display="none";$("edit").style.display="flex";closeMenu();renderAll();
}
function markDirty(){S.cur.dirty=true;updateHead()}
function updateHead(){
  $("eVideo").textContent=S.cur.video.name;
  $("eWork").textContent=S.cur.work?S.cur.work.name:"未保存の編集作業";
  $("dirty").textContent=S.cur.dirty?"● 未保存の変更あり":"";
  $("vlabel").textContent="元動画："+S.cur.video.name;
}
function endWork(){
  pause();S.cur=null;closeMenu();
  $("edit").style.display="none";$("home").style.display="grid";renderHome();
}

/* ---------- 保存・出力 ---------- */
function workLimitCheck(then){
  if(S.works.length<LIM){then();return}
  let h="編集作業が上限（10件）です。削除する編集作業を選んでください。<br>";
  S.works.forEach((w,i)=>{const v=S.videos.find(x=>x.id===w.videoId);
    h+=`<label class="cand"><input type="radio" name="cw" value="${w.id}" ${i?"":"checked"}> ${esc(w.name)}（${esc(v?v.name:"")}）</label>`});
  modal(h,[{t:"削除して保存",c:"danger",f:()=>{
    const id=+document.querySelector('input[name=cw]:checked').value;
    if(S.cur.work&&S.cur.work.id===id)S.cur.work=null;
    S.works=S.works.filter(w=>w.id!==id);then()}},{t:"キャンセル"}]);
}
function doSave(name,cb){
  const w={id:uid(),videoId:S.cur.video.id,name,saved:Date.now(),data:JSON.parse(JSON.stringify(S.cur.data))};
  S.works.push(w);S.cur.work=w;S.cur.dirty=false;updateHead();if(cb)cb();
}
function overwrite(){S.cur.work.saved=Date.now();S.cur.work.data=JSON.parse(JSON.stringify(S.cur.data));S.cur.dirty=false;updateHead()}
function askName(def,ok){
  modal(`編集作業の名前：<br><input type="text" id="nm" value="${esc(def)}" style="width:100%">`,
    [{t:"保存",c:"primary",f:()=>{const n=$("nm").value.trim()||def;setTimeout(()=>workLimitCheck(()=>ok(n)),0)}},{t:"キャンセル"}]);
}
$("bSave").onclick=()=>{if(S.cur.work)overwrite();else askName(S.cur.video.name+" の編集作業",n=>doSave(n))};
$("bSaveAs").onclick=()=>askName((S.cur.work?S.cur.work.name:S.cur.video.name+" の編集作業")+"（別）",n=>doSave(n));
$("bEnd").onclick=()=>{
  if(!S.cur.dirty){endWork();return}
  modal("未保存の変更があります。保存しますか？",[
    {t:"保存して終了",c:"primary",f:()=>{
      if(S.cur.work){overwrite();endWork()}
      else askName(S.cur.video.name+" の編集作業",n=>doSave(n,endWork))}},
    {t:"保存せずに終了",f:()=>{endWork()}},
    {t:"編集に戻る"}]);
};
$("bExport").onclick=()=>{
  const def=S.cur.video.name+" 編集結果";
  modal(`編集結果の動画の名前：<br><input type="text" id="nm" value="${esc(def)}" style="width:100%"><div class="small">※編集作業は自動では保存されません。</div>`,
    [{t:"作成する",c:"primary",f:()=>{
      const n=$("nm").value.trim()||def;
      setTimeout(()=>{
        if(S.results.length<LIM){runExport(n);return}
        let h="編集結果の動画が上限（10件）です。削除する動画を選んでください。<br>";
        S.results.forEach((r,i)=>{h+=`<label class="cand"><input type="radio" name="cr" value="${r.id}" ${i?"":"checked"}> ${esc(r.name)}</label>`});
        modal(h,[{t:"削除して作成",c:"danger",f:()=>{
          const id=+document.querySelector('input[name=cr]:checked').value;
          S.results=S.results.filter(r=>r.id!==id);setTimeout(()=>runExport(n),0)}},{t:"キャンセル"}]);
      },0);
    }},{t:"キャンセル"}]);
};
function runExport(n){
  modal('編集結果を動画にしています…<div id="progress"><div></div></div>',[]);
  let p=0;const t=setInterval(()=>{
    p+=10;const b=document.querySelector("#progress div");if(b)b.style.width=p+"%";
    if(p>=100){clearInterval(t);
      S.results.push({id:uid(),name:n,videoId:S.cur.video.id,origName:S.cur.video.name,created:Date.now()});
      modal(`「${esc(n)}」を作成しました。元の編集作業とオリジナル動画は保持されています。`,[{t:"OK"}])}
  },150);
}

/* ---------- 再生 ---------- */
let timer=null,lastTs=0;
function play(){
  if(S.playing)return;
  if(S.time>=S.dur)S.time=0;
  S.playing=true;lastTs=performance.now();timer=requestAnimationFrame(tick);
}
function pause(){S.playing=false;if(timer)cancelAnimationFrame(timer);timer=null}
function tick(ts){
  if(!S.playing)return;
  S.time+=(ts-lastTs)/1000;lastTs=ts;
  if($("skipChk").checked)S.cur.data.els.filter(e=>e.kind==="skip"&&S.time>=e.start&&S.time<e.end).forEach(e=>{S.time=e.end});
  if(S.time>=S.dur){S.time=S.dur;S.playing=false}
  followPlay();renderVideo();renderTimeline();renderPos();
  if(S.playing)timer=requestAnimationFrame(tick);
}
function followPlay(){
  if(dragHandle||userMoving)return;
  const w=S.ve-S.vs;
  if(w>=S.dur-1e-6)return;
  if(S.time>S.ve-w*0.05||S.time<S.vs)setRange(S.time-w*0.2,S.time-w*0.2+w);
}
$("bPlay").onclick=play;$("bPause").onclick=pause;
$("bStop").onclick=()=>{pause();S.time=0;renderAll()};

/* ---------- 要素データ ---------- */
const getEl=id=>S.cur.data.els.find(e=>e.id===id);
const visible=(e,t)=>t>=e.start&&t<e.end;
function addEl(kind,x,y){
  const d=S.cur.data;
  const b={id:d.nid++,kind,start:S.time,end:Math.min(S.dur,S.time+DEF_DUR)};
  if(b.end-b.start<MINDUR)b.start=Math.max(0,b.end-DEF_DUR);
  if(kind==="comment")Object.assign(b,{x:clamp(x-70,0,VW-140),y:clamp(y-20,0,VH-40),w:140,h:40,text:"コメント",lineOn:true,lw:2,lc:"#000000",tc:"#000000",bg:"#ffffff",fillOn:true,fs:16,font:"sans-serif"});
  if(kind==="box")Object.assign(b,{x:clamp(x-60,0,VW-120),y:clamp(y-40,0,VH-80),w:120,h:80,lw:3,lc:"#ef4444",fillOn:false,fc:"#ef4444",dash:"solid"});
  if(kind==="zoom")Object.assign(b,{x:clamp(x-60,0,VW-120),y:clamp(y-40,0,VH-80),w:120,h:80});
  if(kind==="skip")b.end=Math.min(S.dur,S.time+3);
  d.els.push(b);S.sel=[b.id];S.selLine=null;markDirty();renderAll();
}
function delEls(ids){
  const d=S.cur.data,lines=d.lines.filter(l=>ids.includes(l.from)||ids.includes(l.to));
  const go=()=>{d.els=d.els.filter(e=>!ids.includes(e.id));d.lines=d.lines.filter(l=>!lines.includes(l));S.sel=[];S.selLine=null;markDirty();renderAll()};
  if(lines.length)modal(`要素を削除します。つながっている接続線${lines.length}本も一緒に削除されます。よろしいですか？`,[{t:"削除する",c:"danger",f:go},{t:"キャンセル"}]);
  else go();
}
function activeZoom(){const z=S.cur.data.els.filter(e=>e.kind==="zoom"&&visible(e,S.time));return z.length?z[z.length-1]:null}
function zk(){const z=activeZoom();return z?Math.min(VW/z.w,VH/z.h):1}
function tr(px,py){const z=activeZoom();if(!z)return [px,py];const k=zk();return [(px-z.x)*k+(VW-z.w*k)/2,(py-z.y)*k+(VH-z.h*k)/2]}
function elRect(e){const [x,y]=tr(e.x,e.y),k=zk();return {x,y,w:e.w*k,h:e.h*k}}

/* ---------- 動画領域の描画 ---------- */
function renderVideo(){
  const c=$("vcontent"),w=$("vwrap"),az=activeZoom(),k=zk(),d=S.cur.data;
  if(az)c.style.transform=`translate(${(VW-az.w*k)/2-az.x*k}px,${(VH-az.h*k)/2-az.y*k}px) scale(${k})`;
  else c.style.transform="none";
  w.querySelectorAll(".el").forEach(n=>n.remove());
  d.els.forEach(e=>{
    if(e.kind==="skip"||!visible(e,S.time)||(e.kind==="zoom"&&az))return;
    const r=elRect(e),n=document.createElement("div");
    n.className="el"+(S.sel.includes(e.id)?" sel":"")+(S.connFrom&&S.connFrom!==e.id?" target":"");
    n.dataset.id=e.id;
    n.style.cssText=`left:${r.x}px;top:${r.y}px;width:${r.w}px;height:${r.h}px`;
    if(e.kind==="comment"){
      n.classList.add("cm");
      n.style.fontSize=e.fs*k+"px";n.style.fontFamily=e.font;n.style.color=e.tc;
      n.style.background=e.fillOn?e.bg:"transparent";
      n.style.border=e.lineOn?`${e.lw*k}px solid ${e.lc}`:"none";
      n.textContent=e.text;
    }else if(e.kind==="box"){
      n.style.background=e.fillOn?e.fc+"55":"transparent";
      n.innerHTML=`<svg width="${r.w}" height="${r.h}" style="position:absolute;left:0;top:0;overflow:visible;pointer-events:none"><rect x="${e.lw*k/2}" y="${e.lw*k/2}" width="${Math.max(1,r.w-e.lw*k)}" height="${Math.max(1,r.h-e.lw*k)}" fill="none" stroke="${e.lc}" stroke-width="${e.lw*k}" stroke-dasharray="${DASH[e.dash]}"/></svg>`;
    }else if(e.kind==="zoom")n.classList.add("zm");
    n.insertAdjacentHTML("beforeend",'<div class="rh"></div>');
    w.appendChild(n);
  });
  const zs=d.els.filter(e=>e.kind==="zoom"&&visible(e,S.time));
  if(S.connFrom){$("banner").style.display="block";$("banner").textContent="接続作成中：接続先の要素をクリック（何もない場所で中止）"}
  else if(zs.length){$("banner").style.display="block";$("banner").textContent=zs.length>1?`拡大枠が${zs.length}つ重なっています：後から追加した枠を優先して表示中`:"拡大枠を表示中"}
  else $("banner").style.display="none";
  renderLines();
}

/* ---------- 接続線 ---------- */
function anchor(e,side,toward){
  const r=elRect(e);
  const P={top:[r.x+r.w/2,r.y],bottom:[r.x+r.w/2,r.y+r.h],left:[r.x,r.y+r.h/2],right:[r.x+r.w,r.y+r.h/2]};
  if(side&&side!=="auto")return {p:P[side],s:side};
  const dx=toward[0]-(r.x+r.w/2),dy=toward[1]-(r.y+r.h/2);
  const s=Math.abs(dx)*r.h>Math.abs(dy)*r.w?(dx>0?"right":"left"):(dy>0?"bottom":"top");
  return {p:P[s],s};
}
const centerOf=e=>{const r=elRect(e);return [r.x+r.w/2,r.y+r.h/2]};
function segHit(p,q,r){
  for(let i=0;i<=16;i++){const x=p[0]+(q[0]-p[0])*i/16,y=p[1]+(q[1]-p[1])*i/16;
    if(x>r.x-2&&x<r.x+r.w+2&&y>r.y-2&&y<r.y+r.h+2)return true}
  return false;
}
function lineGeom(l){
  const a=getEl(l.from),b=getEl(l.to);
  if(!a||!b||!visible(a,S.time)||!visible(b,S.time))return null;
  const az=activeZoom();
  if(az&&(a.kind==="zoom"||b.kind==="zoom"))return null;
  const A=anchor(a,l.fs,centerOf(b)),B=anchor(b,l.ts,centerOf(a));
  if(l.shape==="line")return [A.p,B.p];
  const obs=S.cur.data.els.filter(e=>e.id!==a.id&&e.id!==b.id&&e.kind!=="skip"&&visible(e,S.time)&&!(e.kind==="zoom"&&az)).map(elRect);
  const out=(p,s,d)=>({top:[p[0],p[1]-d],bottom:[p[0],p[1]+d],left:[p[0]-d,p[1]],right:[p[0]+d,p[1]]}[s]);
  const a1=out(A.p,A.s,18),b1=out(B.p,B.s,18);
  const horiz=Math.abs(b1[0]-a1[0])>=Math.abs(b1[1]-a1[1]);
  const mid=[(a1[0]+b1[0])/2,(a1[1]+b1[1])/2];
  let c1=horiz?[mid[0],a1[1]]:[a1[0],mid[1]],c2=horiz?[mid[0],b1[1]]:[b1[0],mid[1]];
  const hit=(p,q)=>obs.some(o=>segHit(p,q,o));
  if(hit(a1,c1)||hit(c1,c2)||hit(c2,b1)){
    const cands=horiz?[10,VH-10].map(y=>[[a1[0],y],[b1[0],y]]):[10,VW-10].map(x=>[[x,a1[1]],[x,b1[1]]]);
    const best=cands.find(c=>!hit(a1,c[0])&&!hit(c[0],c[1])&&!hit(c[1],b1))||cands[0];
    c1=best[0];c2=best[1];
  }
  return [A.p,a1,c1,c2,b1,B.p];
}
function pathOf(pts,shape){
  if(shape!=="wave")return "M"+pts.map(p=>p.join(",")).join(" L");
  let d=`M${pts[0][0]},${pts[0][1]}`;
  for(let i=1;i<pts.length;i++){
    const p=pts[i-1],q=pts[i],dx=q[0]-p[0],dy=q[1]-p[1],len=Math.hypot(dx,dy)||1;
    const n=Math.max(2,Math.round(len/14)),nx=-dy/len,ny=dx/len;
    for(let j=1;j<=n;j++){
      const tm=(j-.5)/n,t=j/n,amp=(j%2?1:-1)*4;
      d+=` Q${p[0]+dx*tm+nx*amp},${p[1]+dy*tm+ny*amp} ${p[0]+dx*t},${p[1]+dy*t}`;
    }
  }
  return d;
}
function endMark(p,q,type,col,w){
  if(type==="none")return "";
  const ang=Math.atan2(p[1]-q[1],p[0]-q[0]),s=8+w;
  if(type==="arrow"){const a1=ang+2.6,a2=ang-2.6;
    return `<polygon points="${p[0]},${p[1]} ${p[0]+s*Math.cos(a1)},${p[1]+s*Math.sin(a1)} ${p[0]+s*Math.cos(a2)},${p[1]+s*Math.sin(a2)}" fill="${col}"/>`}
  if(type==="circle")return `<circle cx="${p[0]}" cy="${p[1]}" r="${s/2}" fill="${col}"/>`;
  return `<rect x="${p[0]-s/2}" y="${p[1]-s/2}" width="${s}" height="${s}" fill="${col}"/>`;
}
function renderLines(){
  let h="";
  S.cur.data.lines.forEach(l=>{
    const pts=lineGeom(l);if(!pts)return;
    const d=pathOf(pts,l.shape),sel=S.selLine===l.id,col=sel?"#f59e0b":l.color,sw=sel?l.w+2:l.w;
    h+=`<g><path d="${d}" fill="none" stroke="${col}" stroke-width="${sw}" stroke-dasharray="${DASH[l.dash]}"/>`
      +endMark(pts[0],pts[1],l.startEnd,col,l.w)+endMark(pts[pts.length-1],pts[pts.length-2],l.endEnd,col,l.w)
      +`<path class="hit" d="${d}" data-lid="${l.id}"/></g>`;
  });
  $("svg").innerHTML=h;
}
function renderAll(){if(!S.cur)return;updateHead();renderVideo();renderTimeline();renderPos()}

/* ---------- 動画領域の操作 ---------- */
let drag=null;
const vpos=ev=>{const r=$("vwrap").getBoundingClientRect();return [ev.clientX-r.left,ev.clientY-r.top]};
$("vwrap").addEventListener("mousedown",ev=>{
  if(ev.button!==0)return;
  closeMenu();
  const lid=ev.target.dataset&&ev.target.dataset.lid,elN=ev.target.closest(".el");
  if(S.connFrom){
    if(elN&&+elN.dataset.id!==S.connFrom&&getEl(+elN.dataset.id).kind!=="skip"){
      const to=+elN.dataset.id,d=S.cur.data;
      if(d.lines.some(l=>l.from===S.connFrom&&l.to===to))alert("同じ向きの接続線がすでにあります");
      else{d.lines.push({id:d.nid++,from:S.connFrom,to,shape:"line",dash:"solid",color:"#ffffff",w:2,startEnd:"none",endEnd:"none",fs:"auto",ts:"auto"});markDirty()}
    }
    S.connFrom=null;renderAll();return;
  }
  if(lid){S.selLine=+lid;S.sel=[];renderAll();return}
  if(elN){
    const id=+elN.dataset.id;
    if(ev.shiftKey)S.sel=S.sel.includes(id)?S.sel.filter(x=>x!==id):[...S.sel,id];
    else if(!S.sel.includes(id))S.sel=[id];
    S.selLine=null;
    drag={type:ev.target.classList.contains("rh")?"resize":"move",p:vpos(ev),k:zk(),
      orig:S.sel.map(i=>{const e=getEl(i);return {id:i,x:e.x,y:e.y,w:e.w,h:e.h}})};
    renderAll();
  }else{S.sel=[];S.selLine=null;renderAll()}
});
window.addEventListener("keydown",e=>{if(e.key==="Escape"){S.connFrom=null;closeMenu();renderAll()}});

/* ---------- タイムライン ---------- */
let dragHandle=false,userMoving=false,rulerDrag=false,tdrag=null,odrag=null;
const tlW=()=>$("lanes").clientWidth;
const t2x=t=>(t-S.vs)/(S.ve-S.vs)*tlW();
const x2t=x=>S.vs+x/tlW()*(S.ve-S.vs);
function setRange(s,e){const w=clamp(e-s,MINVIS,S.dur);s=clamp(s,0,S.dur-w);S.vs=s;S.ve=s+w}
function zoomAt(f,c){
  const w=S.ve-S.vs,nw=clamp(w/f,MINVIS,S.dur),r=(c-S.vs)/w;
  setRange(c-r*nw,c-r*nw+nw);renderTimeline();
}
const zoomCenter=()=>(S.time>=S.vs&&S.time<=S.ve)?S.time:(S.vs+S.ve)/2;
$("zIn").onclick=()=>zoomAt(1.5,zoomCenter());
$("zOut").onclick=()=>zoomAt(1/1.5,zoomCenter());
$("zReset").onclick=()=>{setRange(0,S.dur);renderTimeline()};
$("zSlider").oninput=e=>{
  const z=Math.pow(S.dur/MINVIS,+e.target.value/1000),nw=clamp(S.dur/z,MINVIS,S.dur),c=zoomCenter(),r=(c-S.vs)/(S.ve-S.vs);
  setRange(c-r*nw,c-r*nw+nw);renderTimeline(true);
};
$("pLeft").onclick=()=>{const w=S.ve-S.vs;setRange(S.vs-w*.25,S.ve-w*.25);renderTimeline()};
$("pRight").onclick=()=>{const w=S.ve-S.vs;setRange(S.vs+w*.25,S.ve+w*.25);renderTimeline()};
$("tlmain").addEventListener("wheel",ev=>{
  ev.preventDefault();
  zoomAt(ev.deltaY<0?1.25:1/1.25,x2t(ev.clientX-$("lanes").getBoundingClientRect().left));
},{passive:false});

function layoutLanes(){
  const ends=[],res={};
  [...S.cur.data.els].sort((a,b)=>a.start-b.start||a.id-b.id).forEach(e=>{
    let i=0;while(ends[i]!==undefined&&ends[i]>e.start)i++;ends[i]=e.end;res[e.id]=i});
  return res;
}
function tickStep(w){const s=[.1,.2,.5,1,2,5,10,15,30,60,120,300,600];return s.find(x=>x>=w/8)||600}
function renderTimeline(skipSlider){
  if(!S.cur)return;
  const w=S.ve-S.vs,z=S.dur/w,step=tickStep(w),fine=step<1;
  let h="";
  for(let t=Math.ceil(S.vs/step)*step;t<=S.ve+1e-9;t+=step)h+=`<div class="tick" style="left:${t2x(t)}px">${fmtT(t,fine)}</div>`;
  if(S.dur>=S.vs&&S.dur<=S.ve+1e-9)h+=`<div class="endmark" style="left:${Math.min(t2x(S.dur),tlW()-3)}px"><span>終了</span></div>`;
  $("ruler").innerHTML=h;
  const lanes=layoutLanes();let b="";
  S.cur.data.els.forEach(e=>{
    if(e.end<S.vs||e.start>S.ve)return;
    const x1=t2x(e.start),x2=t2x(e.end);
    b+=`<div class="tb${S.sel.includes(e.id)?" sel":""}" data-id="${e.id}" style="left:${x1}px;width:${Math.max(8,x2-x1)}px;top:${4+lanes[e.id]*26}px;background:${KCOL[e.kind]}"><div class="hl" data-h="l"></div>${esc(KIND[e.kind]+(e.kind==="comment"?"："+e.text:""))}<div class="hr" data-h="r"></div></div>`;
  });
  $("lanes").innerHTML=b;
  const ow=$("overview").clientWidth;
  $("ovwin").style.left=(S.vs/S.dur*ow)+"px";$("ovwin").style.width=(w/S.dur*ow)+"px";
  $("overview").querySelectorAll(".ovitem,#ovplay").forEach(n=>n.remove());
  S.cur.data.els.forEach(e=>{
    const n=document.createElement("div");n.className="ovitem";
    n.style.cssText=`left:${e.start/S.dur*ow}px;width:${Math.max(2,(e.end-e.start)/S.dur*ow)}px;top:${3+(lanes[e.id]%5)*4}px`;
    $("overview").appendChild(n);
  });
  const pl=document.createElement("div");pl.id="ovplay";$("overview").appendChild(pl);
  $("rangeInfo").textContent=`表示範囲：${fmtT(S.vs,true)} ~ ${fmtT(S.ve,true)}（幅 ${w.toFixed(2)}秒）`;
  $("zInfo").textContent=`倍率 ${z.toFixed(z<10?1:0)}倍`+(w>=S.dur-1e-6?"（全体表示）":"");
  if(!skipSlider)$("zSlider").value=Math.round(Math.log(z)/Math.log(S.dur/MINVIS)*1000);
  renderPos();
}
function renderPos(){
  if(!S.cur)return;
  $("posInfo").textContent=`${fmtT(S.time,true)} / ${fmtT(S.dur,true)}`;
  const vis=S.time>=S.vs&&S.time<=S.ve,c=$("cursor");
  c.style.display=vis?"block":"none";
  if(vis){
    c.style.left=($("lanes").offsetLeft+1+t2x(S.time))+"px";
    c.style.top=$("ruler").offsetTop-16+"px";
    c.style.height=($("ruler").offsetHeight+$("lanes").offsetHeight+16)+"px";
  }
  const pl=$("ovplay");if(pl)pl.style.left=(S.time/S.dur*$("overview").clientWidth)+"px";
}
/* 再生位置の操作 */
function seekFromRuler(ev){S.time=clamp(x2t(ev.clientX-$("ruler").getBoundingClientRect().left),0,S.dur);if(S.playing)lastTs=performance.now();renderVideo();renderPos()}
$("ruler").addEventListener("mousedown",ev=>{if(ev.button!==0)return;rulerDrag=true;seekFromRuler(ev)});
$("handle").addEventListener("mousedown",ev=>{ev.stopPropagation();ev.preventDefault();dragHandle=true});
/* タイムライン要素の操作 */
$("lanes").addEventListener("mousedown",ev=>{
  if(ev.button!==0)return;
  closeMenu();
  const tb=ev.target.closest(".tb");
  if(tb){
    const id=+tb.dataset.id;
    if(ev.shiftKey)S.sel=S.sel.includes(id)?S.sel.filter(x=>x!==id):[...S.sel,id];
    else if(!S.sel.includes(id))S.sel=[id];
    S.selLine=null;
    const e=getEl(id);
    tdrag={mode:ev.target.dataset.h||"body",id,x:ev.clientX,s:e.start,e:e.end};
    userMoving=true;renderAll();return;
  }
  S.sel=[];S.selLine=null;
  tdrag={mode:"pan",x:ev.clientX,vs:S.vs,ve:S.ve};userMoving=true;renderAll();
});
/* 全体帯の操作 */
$("overview").addEventListener("mousedown",ev=>{
  if(ev.button!==0)return;
  const t=ev.target;
  if(t.classList.contains("g")){odrag={mode:t.classList.contains("l")?"l":"r",x:ev.clientX,vs:S.vs,ve:S.ve};userMoving=true;return}
  if(t.id==="ovwin"){odrag={mode:"move",x:ev.clientX,vs:S.vs,ve:S.ve};userMoving=true;return}
  const r=$("overview").getBoundingClientRect(),c=(ev.clientX-r.left)/r.width*S.dur,w=S.ve-S.vs;
  setRange(c-w/2,c+w/2);renderTimeline();
});
/* 共通 mousemove / mouseup */
window.addEventListener("mousemove",ev=>{
  if(!S.cur)return;
  if(drag){
    const p=vpos(ev),dx=(p[0]-drag.p[0])/drag.k,dy=(p[1]-drag.p[1])/drag.k;
    drag.orig.forEach(o=>{const e=getEl(o.id);
      if(drag.type==="move"){e.x=clamp(o.x+dx,0,VW-e.w);e.y=clamp(o.y+dy,0,VH-e.h)}
      else{e.w=clamp(o.w+dx,20,VW-e.x);e.h=clamp(o.h+dy,16,VH-e.y)}});
    markDirty();renderVideo();return;
  }
  if(dragHandle){
    S.time=clamp(x2t(ev.clientX-$("ruler").getBoundingClientRect().left),0,S.dur);
    if(S.playing)lastTs=performance.now();
    renderVideo();renderPos();return;
  }
  if(rulerDrag){seekFromRuler(ev);return}
  if(odrag){
    const ow=$("overview").clientWidth,dt=(ev.clientX-odrag.x)/ow*S.dur;
    if(odrag.mode==="move")setRange(odrag.vs+dt,odrag.ve+dt);
    else if(odrag.mode==="l")setRange(clamp(odrag.vs+dt,0,odrag.ve-MINVIS),odrag.ve);
    else setRange(odrag.vs,clamp(odrag.ve+dt,odrag.vs+MINVIS,S.dur));
    renderTimeline();return;
  }
  if(tdrag){
    const spp=(S.ve-S.vs)/tlW(),dt=(ev.clientX-tdrag.x)*spp;
    if(tdrag.mode==="pan"){setRange(tdrag.vs-dt,tdrag.ve-dt);renderTimeline();return}
    const e=getEl(tdrag.id),fine=(S.ve-S.vs)<S.dur*0.5?0.05:0.5,q=v=>Math.round(v/fine)*fine;
    const r=$("lanes").getBoundingClientRect();
    // 端までドラッグしたら表示範囲を自動移動
    if(S.ve-S.vs<S.dur-1e-6){
      const w=S.ve-S.vs;
      if(ev.clientX>r.right-8)setRange(S.vs+w*.02,S.ve+w*.02);
      else if(ev.clientX<r.left+8)setRange(S.vs-w*.02,S.ve-w*.02);
    }
    if(tdrag.mode==="body"){
      const len=tdrag.e-tdrag.s,ns=clamp(q(tdrag.s+dt),0,S.dur-len);e.start=ns;e.end=ns+len;
    }else if(tdrag.mode==="l")e.start=clamp(q(tdrag.s+dt),0,e.end-MINDUR);
    else e.end=clamp(q(tdrag.e+dt),e.start+MINDUR,S.dur);
    markDirty();renderVideo();renderTimeline();
  }
});
window.addEventListener("mouseup",()=>{
  drag=null;dragHandle=false;rulerDrag=false;tdrag=null;odrag=null;userMoving=false;
});

/* ---------- 右クリックメニュー ---------- */
function closeMenu(){$("menu").style.display="none";$("menu").innerHTML=""}
document.addEventListener("mousedown",ev=>{if(!ev.target.closest("#menu"))close
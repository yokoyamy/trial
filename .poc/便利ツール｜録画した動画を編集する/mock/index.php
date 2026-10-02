<?php
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;height:100%;font-family:"Yu Gothic",sans-serif;font-size:13px;color:#222;background:#f3f4f6}
button,input{font:inherit}
button{border:1px solid #999;background:#fff;border-radius:4px;padding:5px 9px;cursor:pointer}
button:hover{background:#eef4ff}
button.primary{background:#2563eb;border-color:#2563eb;color:#fff}
button.danger{color:#b00000;border-color:#d88}
button:disabled{opacity:.45;cursor:default}
.hidden{display:none!important}
.small{font-size:11px;color:#666}
.sp{flex:1}
.row{display:flex;align-items:center;gap:6px;flex-wrap:wrap}

#home{padding:16px;display:grid;grid-template-columns:1.4fr 1fr;gap:16px}
.panel{background:#fff;border:1px solid #ccc;border-radius:6px;padding:12px}
.panel h2{font-size:15px;margin:0 0 10px}
.vrow{border:1px solid #bbb;border-radius:5px;margin-bottom:10px;background:#fafafa}
.vhead{padding:8px;background:#e8edf5;display:flex;align-items:center;gap:8px}
.children{margin:6px 8px 8px 28px;border-left:3px solid #2563eb;padding-left:8px}
.wrow{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 4px;border-bottom:1px dotted #ccc}
.empty{color:#888;padding:8px}

#edit{height:100vh;display:none;flex-direction:column;min-height:0}
#topbar{background:#1f2937;color:#fff;padding:7px 10px;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
#topbar button{background:#374151;color:#fff;border-color:#555}
#topbar .info{display:flex;gap:8px;align-items:center}
#dirty{color:#fbbf24;font-weight:bold}

#stage{flex:1;min-height:0;background:#151515;display:flex;align-items:center;justify-content:center;padding:8px;overflow:hidden}
#video{
  position:relative;width:720px;height:405px;background:linear-gradient(135deg,#183b82,#087f6b 50%,#b96c16);
  overflow:hidden;user-select:none;touch-action:none;box-shadow:0 2px 12px #0008
}
#videoGrid{
  position:absolute;inset:0;
  background-image:linear-gradient(#fff2 1px,transparent 1px),linear-gradient(90deg,#fff2 1px,transparent 1px);
  background-size:45px 45px;pointer-events:none
}
#videoTitle{position:absolute;left:10px;bottom:8px;color:#fff;opacity:.8;font-size:12px;pointer-events:none}
#svgLines{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:10}
#svgLines .lineHit{pointer-events:stroke;stroke:transparent;stroke-width:18;fill:none;cursor:pointer}
.el{
  position:absolute;z-index:20;cursor:move;user-select:none;touch-action:none;min-width:20px;min-height:16px
}
.el.selected{outline:2px dashed #ffe047;outline-offset:2px}
.el.connectTarget{outline:3px solid #22c55e;outline-offset:3px}
.el.comment{display:flex;align-items:center;justify-content:center;text-align:center;padding:4px;overflow:hidden}
.el.zoom{border:2px solid #38bdf8;background:#38bdf822}
.el.box{background:#ef444422}
.handle{
  position:absolute;width:10px;height:10px;background:#ffe047;border:1px solid #333;
  display:none;z-index:30;touch-action:none
}
.el.selected .handle{display:block}
.h-n{left:50%;top:-6px;transform:translateX(-50%);cursor:ns-resize}
.h-s{left:50%;bottom:-6px;transform:translateX(-50%);cursor:ns-resize}
.h-e{right:-6px;top:50%;transform:translateY(-50%);cursor:ew-resize}
.h-w{left:-6px;top:50%;transform:translateY(-50%);cursor:ew-resize}
.h-ne{right:-6px;top:-6px;cursor:nesw-resize}
.h-nw{left:-6px;top:-6px;cursor:nwse-resize}
.h-se{right:-6px;bottom:-6px;cursor:nwse-resize}
.h-sw{left:-6px;bottom:-6px;cursor:nesw-resize}

#notice{
  position:absolute;top:8px;left:50%;transform:translateX(-50%);z-index:50;
  padding:5px 12px;border-radius:15px;background:#22c55e;color:#fff;display:none
}

#controls{background:#e5e7eb;border-top:1px solid #aaa;padding:6px 10px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
#controls .time{font-variant-numeric:tabular-nums}

#timelineArea{background:#fff;border-top:1px solid #aaa;padding:7px 10px 9px}
#tlInfo{height:20px;color:#555}
#tlRow{display:flex;align-items:stretch;gap:5px}
#tlRow>button{width:34px}
#timeline{position:relative;flex:1;min-width:300px;border:1px solid #999;border-radius:4px;overflow:hidden;user-select:none;touch-action:none}
#ruler{height:28px;background:#f8fafc;border-bottom:1px solid #aaa;position:relative;cursor:pointer}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #bbb;padding:3px 0 0 3px;font-size:10px;white-space:nowrap}
#lanes{height:132px;position:relative;background:#fff;cursor:grab}
#lanes.panning{cursor:grabbing}
.tb{
  position:absolute;height:24px;border-radius:4px;color:#fff;padding:4px 14px;
  overflow:hidden;white-space:nowrap;cursor:move;min-width:14px;touch-action:none
}
.tb.selected{outline:2px solid #f59e0b;outline-offset:1px}
.th{
  position:absolute;top:0;bottom:0;width:9px;background:#ffffff66;z-index:2;cursor:ew-resize
}
.th.l{left:0}.th.r{right:0}
#cursor{position:absolute;top:0;bottom:0;width:2px;background:#e11d48;z-index:8;pointer-events:none}
#cursorDot{position:absolute;top:-5px;left:-7px;width:15px;height:15px;border-radius:50%;background:#e11d48;border:2px solid #fff;pointer-events:auto;cursor:ew-resize}
#overview{height:28px;margin-top:7px;background:#e5e7eb;border:1px solid #aaa;border-radius:4px;position:relative;overflow:hidden}
.ovitem{position:absolute;top:4px;height:7px;background:#64748b99;border-radius:2px}
#ovwin{position:absolute;top:0;bottom:0;background:#2563eb22;border:2px solid #2563eb;cursor:grab;z-index:3}
#ovwin.grab{cursor:grabbing}
.ovh{position:absolute;top:-1px;bottom:-1px;width:10px;background:#2563eb;cursor:ew-resize}
.ovh.l{left:-5px}.ovh.r{right:-5px}
#ovplay{position:absolute;top:0;bottom:0;width:2px;background:#e11d48;z-index:5;pointer-events:none}

#menu{
  position:fixed;display:none;z-index:100;background:#fff;border:1px solid #999;
  border-radius:5px;box-shadow:0 5px 18px #0004;min-width:210px;padding:5px
}
#menu button{display:block;width:100%;border:0;text-align:left;background:#fff}
#menu button:hover{background:#eef4ff}
#menu .title{font-size:11px;color:#666;padding:4px 6px}
#menu hr{border:0;border-top:1px solid #ddd;margin:4px 0}

#modal{position:fixed;inset:0;background:#0007;display:none;align-items:center;justify-content:center;z-index:200}
#modalBox{background:#fff;border-radius:6px;padding:16px;width:min(480px,92vw);max-height:80vh;overflow:auto}
#modalBtns{display:flex;justify-content:flex-end;gap:7px;margin-top:14px}
.candidate{display:block;padding:5px}

@media(max-width:800px){
  #home{grid-template-columns:1fr}
  #video{width:min(720px,96vw);height:auto;aspect-ratio:16/9}
}
</style>
</head>
<body>

<div id="home">
  <section class="panel">
    <h2>オリジナル動画 <span id="origCount"></span></h2>
    <div class="row" style="margin-bottom:10px">
      <button class="primary" id="pick">動画を選択</button>
      <button id="sample">サンプル動画を追加</button>
      <input id="file" type="file" accept="video/*" hidden>
    </div>
    <div id="origList"></div>
  </section>
  <section class="panel">
    <h2>編集結果の動画 <span id="resultCount"></span></h2>
    <div id="resultList"></div>
  </section>
</div>

<div id="edit">
  <div id="topbar">
    <div class="info">
      <b id="eVideo"></b>
      <span>/</span>
      <span id="eWork"></span>
      <span id="dirty"></span>
    </div>
    <span class="sp"></span>
    <button id="save">保存</button>
    <button id="saveAs">別名で保存</button>
    <button id="export">編集結果を作成</button>
    <button id="end">編集作業を終了</button>
  </div>

  <div id="stage">
    <div id="video">
      <div id="videoGrid"></div>
      <svg id="svgLines"></svg>
      <div id="notice"></div>
      <div id="videoTitle"></div>
    </div>
  </div>

  <div id="controls">
    <button id="play">▶ 再生</button>
    <button id="pause">⏸ 一時停止</button>
    <button id="stop">■ 停止</button>
    <label><input id="skipPlay" type="checkbox" checked> スキップ範囲を飛ばす</label>
    <span class="time" id="timeInfo"></span>
    <span class="sp"></span>
    <button id="zout">−</button>
    <input id="zslider" type="range" min="0" max="1000" value="0" style="width:150px">
    <button id="zin">＋</button>
    <button id="zreset">全体表示</button>
    <span id="zoomInfo"></span>
  </div>

  <div id="timelineArea">
    <div id="tlInfo"></div>
    <div id="tlRow">
      <button id="left">◀</button>
      <div id="timeline">
        <div id="ruler"></div>
        <div id="lanes"></div>
        <div id="cursor"><div id="cursorDot"></div></div>
      </div>
      <button id="right">▶</button>
    </div>
    <div id="overview">
      <div id="ovwin"><div class="ovh l"></div><div class="ovh r"></div></div>
      <div id="ovplay"></div>
    </div>
  </div>
</div>

<div id="menu"></div>
<div id="modal"><div id="modalBox"><div id="modalContent"></div><div id="modalBtns"></div></div></div>

<script>
"use strict";

const $=id=>document.getElementById(id);
const LIM=10,MIN_TIME=.3,MIN_VIEW=.5,DEF_TIME=5,W=720,H=405;
const KIND={
  comment:{name:"コメント",color:"#2563eb"},
  box:{name:"強調枠",color:"#dc2626"},
  zoom:{name:"拡大枠",color:"#0891b2"},
  skip:{name:"スキップ",color:"#6b7280"}
};
const state={
  seq:1,videos:[],works:[],results:[],
  cur:null,time:0,dur:60,vs:0,ve:60,playing:false,
  selected:[],selectedLine:null,connectFrom:null
};

let videoDrag=null,timelineDrag=null,overviewDrag=null,rulerDrag=false;
let animation=null,lastTime=0;

const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const uid=()=>state.seq++;
const esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const fmt=t=>{
  t=Math.max(0,t);
  const m=Math.floor(t/60),s=t-m*60;
  return m+":"+String(s.toFixed(2)).padStart(5,"0");
};
const now=()=>new Date().toLocaleString("ja-JP");
const getEl=id=>state.cur.data.els.find(e=>e.id===id);
const selectedEls=()=>state.cur.data.els.filter(e=>state.selected.includes(e.id));
const visible=e=>state.time>=e.start&&state.time<e.end;

function modal(html,buttons){
  $("modalContent").innerHTML=html;
  $("modalBtns").innerHTML="";
  buttons.forEach(b=>{
    const x=document.createElement("button");
    x.textContent=b.text;
    if(b.cls)x.className=b.cls;
    x.onclick=()=>{
      const r=b.fn?b.fn():true;
      if(r!==false)closeModal();
    };
    $("modalBtns").appendChild(x);
  });
  $("modal").style.display="flex";
}
function closeModal(){$("modal").style.display="none"}

function renderHome(){
  $("origCount").textContent="("+state.videos.length+"/"+LIM+"件)";
  $("resultCount").textContent="("+state.results.length+"/"+LIM+"件)";
  let h="";
  if(!state.videos.length)h='<div class="empty">オリジナル動画はありません。</div>';
  state.videos.forEach(v=>{
    const ws=state.works.filter(w=>w.videoId===v.id);
    h+=`<div class="vrow">
      <div class="vhead">
        <div><b>${esc(v.name)}</b><br><span class="small">取り込み：${esc(v.imported)} / 編集作業 ${ws.length}件</span></div>
        <span class="sp"></span>
        <button class="primary" data-new="${v.id}">新しい編集作業</button>
        <button class="danger" data-delv="${v.id}">削除</button>
      </div><div class="children">`;
    if(!ws.length)h+='<div class="empty">編集作業はありません。</div>';
    ws.forEach(w=>{
      h+=`<div class="wrow">
        <div>${esc(w.name)}<br><span class="small">最終保存：${esc(w.saved)}</span></div>
        <div><button data-resume="${w.id}">再開</button> <button class="danger" data-delw="${w.id}">削除</button></div>
      </div>`;
    });
    h+="</div></div>";
  });
  $("origList").innerHTML=h;

  let r="";
  if(!state.results.length)r='<div class="empty">編集結果の動画はありません。</div>';
  state.results.forEach(x=>{
    r+=`<div class="wrow">
      <div><b>${esc(x.name)}</b><br><span class="small">元：${esc(x.origName)} / 作成：${esc(x.created)}</span></div>
      <div><button data-play="${x.id}">再生</button> <button class="danger" data-delr="${x.id}">削除</button></div>
    </div>`;
  });
  $("resultList").innerHTML=r;
}

$("origList").onclick=e=>{
  const d=e.target.dataset;
  if(d.new)startWork(+d.new);
  if(d.resume)resumeWork(+d.resume);
  if(d.delv)deleteVideo(+d.delv);
  if(d.delw)deleteWork(+d.delw);
};
$("resultList").onclick=e=>{
  const d=e.target.dataset;
  if(d.play){
    const r=state.results.find(x=>x.id==d.play);
    modal(`<b>${esc(r.name)}</b><p>編集結果の再生モックです。</p>`,[{text:"閉じる"}]);
  }
  if(d.delr){
    const r=state.results.find(x=>x.id==d.delr);
    modal(`「${esc(r.name)}」を削除しますか？`,[
      {text:"削除する",cls:"danger",fn:()=>{state.results=state.results.filter(x=>x.id!==r.id);renderHome()}},
      {text:"キャンセル"}
    ]);
  }
};

$("pick").onclick=()=>$("file").click();
$("sample").onclick=()=>importVideo("サンプル動画.mp4",60);
$("file").onchange=e=>{
  const f=e.target.files[0];
  if(!f)return;
  const v=document.createElement("video");
  const u=URL.createObjectURL(f);
  v.preload="metadata";
  v.onloadedmetadata=()=>{
    importVideo(f.name,isFinite(v.duration)?v.duration:60,u);
    URL.revokeObjectURL(u);
  };
  v.onerror=()=>modal("この動画を読み込めませんでした。",[{text:"閉じる"}]);
  v.src=u;
  e.target.value="";
};

function importVideo(name,dur,url){
  const go=()=>{
    const v={id:uid(),name,dur,url:url||null,imported:now()};
    state.videos.push(v);renderHome();startWork(v.id);
  };
  if(state.videos.length<LIM){go();return}
  let h="オリジナル動画が上限です。削除する動画を選んでください。";
  state.videos.forEach((v,i)=>{
    h+=`<label class="candidate"><input type="radio" name="delv" value="${v.id}" ${i?"":"checked"}>${esc(v.name)}</label>`;
  });
  modal(h,[
    {text:"削除して取り込む",cls:"danger",fn:()=>{
      const id=+document.querySelector('input[name=delv]:checked').value;
      state.videos=state.videos.filter(v=>v.id!==id);
      state.works=state.works.filter(w=>w.videoId!==id);
      go();
    }},
    {text:"キャンセル"}
  ]);
}

function deleteVideo(id){
  const v=state.videos.find(x=>x.id===id);
  const n=state.works.filter(x=>x.videoId===id).length;
  modal(`「${esc(v.name)}」を削除しますか？<br>${n?"配下の編集作業"+n+"件も削除されます。":""}`,[
    {text:"削除する",cls:"danger",fn:()=>{
      state.videos=state.videos.filter(x=>x.id!==id);
      state.works=state.works.filter(x=>x.videoId!==id);
      renderHome();
    }},
    {text:"キャンセル"}
  ]);
}
function deleteWork(id){
  const w=state.works.find(x=>x.id===id);
  modal(`編集作業「${esc(w.name)}」を削除しますか？`,[
    {text:"削除する",cls:"danger",fn:()=>{state.works=state.works.filter(x=>x.id!==id);renderHome()}},
    {text:"キャンセル"}
  ]);
}

function blankData(){
  return {nid:1,els:[],lines:[]};
}
function startWork(id){
  const v=state.videos.find(x=>x.id===id);
  state.cur={video:v,work:null,data:blankData(),dirty:false};
  openEditor();
}
function resumeWork(id){
  const w=state.works.find(x=>x.id===id);
  const v=state.videos.find(x=>x.id===w.videoId);
  state.cur={video:v,work:w,data:JSON.parse(JSON.stringify(w.data)),dirty:false};
  openEditor();
}
function openEditor(){
  state.dur=state.cur.video.dur||60;
  state.time=0;state.vs=0;state.ve=state.dur;
  state.selected=[];state.selectedLine=null;state.connectFrom=null;
  stop();
  $("home").style.display="none";
  $("edit").style.display="flex";
  updateHead();renderAll();
}
function updateHead(){
  $("eVideo").textContent=state.cur.video.name;
  $("eWork").textContent=state.cur.work?state.cur.work.name:"未保存の編集作業";
  $("dirty").textContent=state.cur.dirty?"● 未保存の変更あり":"";
  $("videoTitle").textContent="元動画："+state.cur.video.name;
}
function dirty(){state.cur.dirty=true;updateHead()}

function endEditor(){
  if(!state.cur.dirty)return finishEditor();
  modal("未保存の変更があります。",[
    {text:"保存して終了",cls:"primary",fn:()=>saveWork(finishEditor)},
    {text:"保存せず終了",fn:finishEditor},
    {text:"編集に戻る"}
  ]);
}
function finishEditor(){
  stop();state.cur=null;state.selected=[];state.connectFrom=null;
  $("edit").style.display="none";$("home").style.display="grid";renderHome();
}
$("end").onclick=endEditor;

function askSaveName(def,cb){
  modal(`編集作業の名前<br><input id="saveName" value="${esc(def)}" style="width:100%;margin-top:7px">`,[
    {text:"保存",cls:"primary",fn:()=>{
      const n=$("saveName").value.trim()||def;
      if(state.works.length>=LIM&&!state.cur.work){
        let h="編集作業が上限です。削除するものを選んでください。";
        state.works.forEach((w,i)=>h+=`<label class="candidate"><input type="radio" name="dw" value="${w.id}" ${i?"":"checked"}>${esc(w.name)}</label>`);
        setTimeout(()=>modal(h,[
          {text:"削除して保存",cls:"danger",fn:()=>{
            const id=+document.querySelector('input[name=dw]:checked').value;
            state.works=state.works.filter(w=>w.id!==id);
            createWork(n,cb);
          }},
          {text:"キャンセル"}
        ]),0);
        return false;
      }
      createWork(n,cb);
    }},
    {text:"キャンセル"}
  ]);
}
function createWork(name,cb){
  const w={id:uid(),videoId:state.cur.video.id,name,saved:now(),data:JSON.parse(JSON.stringify(state.cur.data))};
  state.works.push(w);state.cur.work=w;state.cur.dirty=false;updateHead();
  if(cb)cb();
}
function saveWork(cb){
  if(state.cur.work){
    state.cur.work.data=JSON.parse(JSON.stringify(state.cur.data));
    state.cur.work.saved=now();state.cur.dirty=false;updateHead();
    if(cb)cb();
  }else askSaveName(state.cur.video.name+" の編集作業",cb);
}
$("save").onclick=()=>saveWork();
$("saveAs").onclick=()=>askSaveName((state.cur.work?state.cur.work.name:state.cur.video.name)+"（別）");

$("export").onclick=()=>{
  const def=state.cur.video.name+" 編集結果";
  modal(`編集結果の動画の名前<br><input id="exportName" value="${esc(def)}" style="width:100%;margin-top:7px"><p class="small">編集作業は自動保存されません。</p>`,[
    {text:"作成する",cls:"primary",fn:()=>{
      const n=$("exportName").value.trim()||def;
      runExport(n);
    }},
    {text:"キャンセル"}
  ]);
};
function runExport(name){
  modal("編集結果を作成しています…<div style='height:8px;background:#ddd;margin-top:12px'><div id='progress' style='height:100%;width:0;background:#2563eb'></div></div>",[]);
  let p=0;
  const t=setInterval(()=>{
    p+=10;
    if($("progress"))$("progress").style.width=p+"%";
    if(p>=100){
      clearInterval(t);
      state.results.push({id:uid(),name,videoId:state.cur.video.id,origName:state.cur.video.name,created:now()});
      modal(`「${esc(name)}」を作成しました。`,[{text:"OK"}]);
    }
  },80);
}

/* 再生 */
function play(){
  if(state.playing)return;
  if(state.time>=state.dur)state.time=0;
  state.playing=true;lastTime=performance.now();
  animation=requestAnimationFrame(tick);
}
function pause(){
  state.playing=false;
  if(animation)cancelAnimationFrame(animation);
  animation=null;
}
function stop(){pause()}
function tick(ts){
  if(!state.playing)return;
  state.time+=((ts-lastTime)/1000);lastTime=ts;
  const skips=state.cur.data.els.filter(e=>e.kind==="skip"&&state.time>=e.start&&state.time<e.end);
  if($("skipPlay").checked&&skips.length)state.time=skips[skips.length-1].end;
  if(state.time>=state.dur){state.time=state.dur;pause()}
  followPlay();renderVideo();renderTimeline();renderPosition();
  if(state.playing)animation=requestAnimationFrame(tick);
}
$("play").onclick=play;
$("pause").onclick=pause;
$("stop").onclick=()=>{stop();state.time=0;renderAll()};

function setRange(s,e){
  let w=clamp(e-s,MIN_VIEW,state.dur);
  s=clamp(s,0,state.dur-w);state.vs=s;state.ve=s+w;
}
function zoomAt(f,c){
  const w=state.ve-state.vs,nw=clamp(w/f,MIN_VIEW,state.dur),r=(c-state.vs)/w;
  setRange(c-r*nw,c-r*nw+nw);renderTimeline();
}
function zoomCenter(){return state.time>=state.vs&&state.time<=state.ve?state.time:(state.vs+state.ve)/2}
$("zin").onclick=()=>zoomAt(1.5,zoomCenter());
$("zout").onclick=()=>zoomAt(1/1.5,zoomCenter());
$("zreset").onclick=()=>{setRange(0,state.dur);renderTimeline()};
$("zslider").oninput=e=>{
  const z=Math.pow(state.dur/MIN_VIEW,+e.target.value/1000);
  const nw=clamp(state.dur/z,MIN_VIEW,state.dur),c=zoomCenter(),r=(c-state.vs)/(state.ve-state.vs);
  setRange(c-r*nw,c-r*nw+nw);renderTimeline();
};
$("left").onclick=()=>{const w=state.ve-state.vs;setRange(state.vs-w*.25,state.ve-w*.25);renderTimeline()};
$("right").onclick=()=>{const w=state.ve-state.vs;setRange(state.vs+w*.25,state.ve+w*.25);renderTimeline()};
$("timeline").onwheel=e=>{
  e.preventDefault();
  const r=$("lanes").getBoundingClientRect();
  zoomAt(e.deltaY<0?1.25:.8,state.vs+((e.clientX-r.left)/r.width)*(state.ve-state.vs));
};

function followPlay(){
  const w=state.ve-state.vs;
  if(w>=state.dur)return;
  if(state.time>state.ve-w*.05||state.time<state.vs)setRange(state.time-w*.2,state.time-w*.2+w);
}

/* 要素 */
function addElement(kind,x,y){
  const d=state.cur.data;
  let s=clamp(state.time,0,state.dur-MIN_TIME),e=Math.min(state.dur,s+DEF_TIME);
  const b={id:d.nid++,kind,start:s,end:e,x:0,y:0,w:0,h:0};
  if(kind==="comment")Object.assign(b,{x:x-70,y:y-25,w:140,h:50,text:"コメント",lineOn:true,lw:2,lc:"#2563eb",tc:"#000",bg:"#fff",fillOn:true,fs:16,font:"sans-serif"});
  if(kind==="box")Object.assign(b,{x:x-70,y:y-50,w:140,h:100,lw:3,lc:"#dc2626",fillOn:false,fc:"#dc2626",dash:"solid"});
  if(kind==="zoom")Object.assign(b,{x:x-80,y:y-60,w:160,h:120});
  if(kind==="skip"){b.x=0;b.y=0;b.w=1;b.h=1;b.end=Math.min(state.dur,state.time+3)}
  d.els.push(b);state.selected=[b.id];state.selectedLine=null;dirty();renderAll();
}
function deleteSelected(){
  const ids=state.selected;
  if(!ids.length)return;
  const lines=state.cur.data.lines.filter(l=>ids.includes(l.from)||ids.includes(l.to));
  const go=()=>{
    state.cur.data.els=state.cur.data.els.filter(e=>!ids.includes(e.id));
    state.cur.data.lines=state.cur.data.lines.filter(l=>!ids.includes(l.from)&&!ids.includes(l.to));
    state.selected=[];state.selectedLine=null;dirty();renderAll();
  };
  if(lines.length)modal(`選択した要素と接続線${lines.length}本を削除しますか？`,[
    {text:"削除する",cls:"danger",fn:go},{text:"キャンセル"}
  ]);else go();
}

/* 動画領域 */
function activeZoom(){
  const z=state.cur.data.els.filter(e=>e.kind==="zoom"&&e.start<=state.time&&state.time<e.end);
  return z.length?z[z.length-1]:null;
}
function transformRect(e){
  const z=activeZoom();
  if(!z)return{x:e.x,y:e.y,w:e.w,h:e.h};
  const k=Math.min(W/z.w,H/z.h);
  return{x:(e.x-z.x)*k+(W-z.w*k)/2,y:(e.y-z.y)*k+(H-z.h*k)/2,w:e.w*k,h:e.h*k};
}
function renderVideo(){
  const box=$("video");
  box.querySelectorAll(".el").forEach(x=>x.remove());
  state.cur.data.els.forEach(e=>{
    if(e.kind==="skip"||!visible(e))return;
    const r=transformRect(e),n=document.createElement("div");
    n.className="el "+e.kind+(state.selected.includes(e.id)?" selected":"")+
      (state.connectFrom&&state.connectFrom!==e.id?" connectTarget":"");
    n.dataset.id=e.id;
    n.style.left=r.x+"px";n.style.top=r.y+"px";n.style.width=r.w+"px";n.style.height=r.h+"px";
    if(e.kind==="comment"){
      n.textContent=e.text;
      n.style.color=e.tc;n.style.fontSize=e.fs+"px";n.style.fontFamily=e.font;
      n.style.background=e.fillOn?e.bg:"transparent";
      n.style.border=e.lineOn?e.lw+"px solid "+e.lc:"none";
    }
    if(e.kind==="box"){
      n.style.border=e.lw+"px "+(e.dash==="dash"?"dashed":"solid")+" "+e.lc;
      n.style.background=e.fillOn?e.fc+"44":"transparent";
    }
    if(e.kind==="zoom")n.textContent="拡大枠";
    ["nw","n","ne","w","e","sw","s","se"].forEach(k=>{
      const h=document.createElement("div");h.className="handle h-"+k;h.dataset.resize=k;n.appendChild(h);
    });
    box.appendChild(n);
  });
  renderLines();
  const z=activeZoom();
  const notice=$("notice");
  if(state.connectFrom){
    notice.style.display="block";notice.textContent="接続作成中：接続先の要素をクリック";
  }else if(z){
    notice.style.display="block";notice.textContent="拡大枠を表示中";
  }else notice.style.display="none";
}
function pointInVideo(e){
  const r=$("video").getBoundingClientRect();
  return [e.clientX-r.left,e.clientY-r.top];
}
function beginVideoDrag(ev){
  if(ev.button!==0)return;
  const target=ev.target.closest(".el");
  const resize=ev.target.dataset.resize;
  if(state.connectFrom){
    if(target&&+target.dataset.id!==state.connectFrom){
      const to=+target.dataset.id;
      if(!state.cur.data.lines.some(l=>l.from===state.connectFrom&&l.to===to)){
        state.cur.data.lines.push({id:state.cur.data.nid++,from:state.connectFrom,to,shape:"line",color:"#fff",w:2,dash:"solid",start:"none",end:"arrow"});
        dirty();
      }
    }
    state.connectFrom=null;renderAll();return;
  }
  const lid=ev.target.dataset.lid;
  if(lid){
    state.selectedLine=+lid;state.selected=[];renderAll();return;
  }
  if(!target){
    state.selected=[];state.selectedLine=null;renderAll();return;
  }
  const id=+target.dataset.id;
  if(ev.shiftKey){
    state.selected=state.selected.includes(id)?state.selected.filter(x=>x!==id):state.selected.concat(id);
  }else if(!state.selected.includes(id))state.selected=[id];
  state.selectedLine=null;
  const p=pointInVideo(ev);
  videoDrag={
    mode:resize?"resize":"move",resize,id,x:p[0],y:p[1],
    originals:selectedEls().map(e=>({id:e.id,x:e.x,y:e.y,w:e.w,h:e.h}))
  };
  ev.currentTarget.setPointerCapture?.(ev.pointerId);
  renderAll();
}
$("video").addEventListener("pointerdown",beginVideoDrag);
$("video").addEventListener("pointermove",ev=>{
  if(!videoDrag)return;
  const p=pointInVideo(ev),dx=p[0]-videoDrag.x,dy=p[1]-videoDrag.y;
  videoDrag.originals.forEach(o=>{
    const e=getEl(o.id);
    if(videoDrag.mode==="move"){
      e.x=clamp(o.x+dx,0,W-o.w);e.y=clamp(o.y+dy,0,H-o.h);
      return;
    }
    if(videoDrag.originals.length!==1)return;
    const minW=24,minH=18,dir=videoDrag.resize;
    let l=o.x,t=o.y,r=o.x+o.w,b=o.y+o.h;
    if(dir.includes("w"))l=clamp(o.x+dx,0,r-minW);
    if(dir.includes("e"))r=clamp(o.x+o.w+dx,l+minW,W);
    if(dir.includes("n"))t=clamp(o.y+dy,0,b-minH);
    if(dir.includes("s"))b=clamp(o.y+o.h+dy,t+minH,H);
    e.x=l;e.y=t;e.w=r-l;e.h=b-t;
  });
  dirty();renderVideo();
});
$("video").addEventListener("pointerup",()=>{videoDrag=null});
$("video").addEventListener("pointercancel",()=>{videoDrag=null});

/* 接続線 */
function anchor(e,toward){
  const r=transformRect(e),cx=r.x+r.w/2,cy=r.y+r.h/2,dx=toward[0]-cx,dy=toward[1]-cy;
  if(Math.abs(dx)*r.h>Math.abs(dy)*r.w)
    return dx>=0?[r.x+r.w,cy]:[r.x,cy];
  return dy>=0?[cx,r.y+r.h]:[cx,r.y];
}
function linePoints(line){
  const a=getEl(line.from),b=getEl(line.to);
  if(!a||!b||!visible(a)||!visible(b))return null;
  const ac=transformRect(a),bc=transformRect(b);
  const A=anchor(a,[bc.x+bc.w/2,bc.y+bc.h/2]);
  const B=anchor(b,[ac.x+ac.w/2,ac.y+bc.h/2]);
  if(line.shape==="line")return[A,B];
  const mx=(A[0]+B[0])/2,my=(A[1]+B[1])/2;
  return[A,[mx,A[1]],[mx,B[1]],B];
}
function renderLines(){
  let html="";
  state.cur.data.lines.forEach(l=>{
    const pts=linePoints(l);if(!pts)return;
    const d=pts.map((p,i)=>(i?"L":"M")+p[0]+","+p[1]).join(" ");
    const col=state.selectedLine===l.id?"#f59e0b":l.color||"#fff";
    html+=`<path d="${d}" fill="none" stroke="${col}" stroke-width="${l.w||2}" stroke-dasharray="${l.dash==="dash"?"8 5":""}"/>`;
    if(l.end==="arrow"){
      const p=pts[pts.length-1],q=pts[pts.length-2],ang=Math.atan2(p[1]-q[1],p[0]-q[0]),s=10;
      const a1=ang+2.65,a2=ang-2.65;
      html+=`<polygon points="${p[0]},${p[1]} ${p[0]+s*Math.cos(a1)},${p[1]+s*Math.sin(a1)} ${p[0]+s*Math.cos(a2)},${p[1]+s*Math.sin(a2)}" fill="${col}"/>`;
    }
    html+=`<path class="lineHit" data-lid="${l.id}" d="${d}"/>`;
  });
  $("svgLines").innerHTML=html;
}

/* タイムライン */
function tlWidth(){return $("lanes").clientWidth}
function t2x(t){return (t-state.vs)/(state.ve-state.vs)*tlWidth()}
function x2t(x){return state.vs+x/tlWidth()*(state.ve-state.vs)}
function laneMap(){
  const ends=[],map={};
  [...state.cur.data.els].sort((a,b)=>a.start-b.start||a.id-b.id).forEach(e=>{
    let i=0;while(ends[i]!==undefined&&ends[i]>e.start)i++;
    ends[i]=e.end;map[e.id]=i;
  });
  return map;
}
function renderTimeline(){
  const w=state.ve-state.vs,step=[.1,.2,.5,1,2,5,10,15,30,60,120,300].find(x=>x>=w/8)||600;
  let h="";
  for(let t=Math.ceil(state.vs/step)*step;t<=state.ve+1e-9;t+=step)
    h+=`<div class="tick" style="left:${t2x(t)}px">${fmt(t)}</div>`;
  $("ruler").innerHTML=h;
  const lanes=laneMap();
  h="";
  state.cur.data.els.forEach(e=>{
    if(e.end<state.vs||e.start>state.ve)return;
    const x=t2x(e.start),width=Math.max(14,t2x(e.end)-x);
    h+=`<div class="tb${state.selected.includes(e.id)?" selected":""}" data-id="${e.id}" style="left:${x}px;width:${width}px;top:${4+(lanes[e.id]||0)*27}px;background:${KIND[e.kind].color}">
      <div class="th l" data-edge="l"></div>${esc(KIND[e.kind].name+(e.kind==="comment"?"："+e.text:""))}<div class="th r" data-edge="r"></div>
    </div>`;
  });
  $("lanes").innerHTML=h;
  const ow=$("overview").clientWidth;
  $("ovwin").style.left=(state.vs/state.dur*ow)+"px";
  $("ovwin").style.width=(w/state.dur*ow)+"px";
  $("overview").querySelectorAll(".ovitem").forEach(x=>x.remove());
  state.cur.data.els.forEach(e=>{
    const n=document.createElement("div");n.className="ovitem";
    n.style.left=e.start/state.dur*ow+"px";
    n.style.width=Math.max(2,(e.end-e.start)/state.dur*ow)+"px";
    $("overview").appendChild(n);
  });
  const z=state.dur/w;
  $("tlInfo").textContent=`表示範囲：${fmt(state.vs)} ～ ${fmt(state.ve)}　（幅 ${w.toFixed(2)}秒）`;
  $("zoomInfo").textContent=`倍率 ${z.toFixed(z<10?1:0)}倍`;
  $("zslider").value=Math.round(Math.log(z)/Math.log(state.dur/MIN_VIEW)*1000);
  renderPosition();
}
function renderPosition(){
  $("timeInfo").textContent=`${fmt(state.time)} / ${fmt(state.dur)}`;
  const x=t2x(state.time);
  $("cursor").style.left=x+"px";
  $("cursor").style.display=state.time>=state.vs&&state.time<=state.ve?"block":"none";
  $("ovplay").style.left=(state.time/state.dur*100)+"%";
}
function renderAll(){updateHead();renderVideo();renderTimeline()}

function beginTimeline(ev){
  if(ev.button!==0)return;
  const tb=ev.target.closest(".tb");
  if(!tb){
    state.selected=[];state.selectedLine=null;
    timelineDrag={mode:"pan",x:ev.clientX,vs:state.vs,ve:state.ve};
    return;
  }
  const id=+tb.dataset.id,e=getEl(id);
  if(ev.shiftKey)state.selected=state.selected.includes(id)?state.selected.filter(x=>x!==id):state.selected.concat(id);
  else if(!state.selected.includes(id))state.selected=[id];
  const edge=ev.target.dataset.edge||"body";
  timelineDrag={mode:edge,id,x:ev.clientX,s:e.start,e:e.end};
  renderAll();
}
$("lanes").addEventListener("pointerdown",beginTimeline);
$("lanes").addEventListener("pointermove",ev=>{
  if(!timelineDrag)return;
  const px=(ev.clientX-timelineDrag.x),dt=px/tlWidth()*(state.ve-state.vs);
  if(timelineDrag.mode==="pan"){
    setRange(timelineDrag.vs-dt,timelineDrag.ve-dt);renderTimeline();return;
  }
  const e=getEl(timelineDrag.id);
  const snap=(state.ve-state.vs)<state.dur*.5?.05:.5;
  const q=v=>Math.round(v/snap)*snap;
  if(timelineDrag.mode==="body"){
    const len=timelineDrag.e-timelineDrag.s;
    e.start=clamp(q(timelineDrag.s+dt),0,state.dur-len);e.end=e.start+len;
  }else if(timelineDrag.mode==="l"){
    e.start=clamp(q(timelineDrag.s+dt),0,e.end-MIN_TIME);
  }else{
    e.end=clamp(q(timelineDrag.e+dt),e.start+MIN_TIME,state.dur);
  }
  dirty();renderVideo();renderTimeline();
});
window.addEventListener("pointerup",()=>{timelineDrag=null;overviewDrag=null;rulerDrag=false});

/* ルーラー */
$("ruler").addEventListener("pointerdown",ev=>{
  const move=x=>{
    state.time=clamp(x2t(x-$("ruler").getBoundingClientRect().left),0,state.dur);
    renderVideo();renderPosition();
  };
  rulerDrag=true;move(ev.clientX);
});
window.addEventListener("pointermove",ev=>{
  if(!rulerDrag)return;
  state.time=clamp(x2t(ev.clientX-$("ruler").getBoundingClientRect().left),0,state.dur);
  renderVideo();renderPosition();
});

/* 全体表示帯 */
$("overview").addEventListener("pointerdown",ev=>{
  const r=$("overview").getBoundingClientRect(),ow=r.width;
  const x=ev.clientX-r.left;
  if(ev.target.classList.contains("ovh")){
    overviewDrag={mode:ev.target.classList.contains("l")?"l":"r",x:ev.clientX,vs:state.vs,ve:state.ve};
    return;
  }
  if(ev.target=== $("ovwin")||ev.target.parentElement===$("ovwin")){
    overviewDrag={mode:"move",x:ev.clientX,vs:state.vs,ve:state.ve};
    return;
  }
  const c=x/ow*state.dur,w=state.ve-state.vs;
  setRange(c-w/2,c+w/2);renderTimeline();
});
$("overview").addEventListener("pointermove",ev=>{
  if(!overviewDrag)return;
  const ow=$("overview").clientWidth,dt=(ev.clientX-overviewDrag.x)/ow*state.dur;
  if(overviewDrag.mode==="move")setRange(overviewDrag.vs+dt,overviewDrag.ve+dt);
  if(overviewDrag.mode==="l")setRange(overviewDrag.vs+dt,overviewDrag.ve);
  if(overviewDrag.mode==="r")setRange(overviewDrag.vs,overviewDrag.ve+dt);
  renderTimeline();
});

/* 右クリックメニュー */
const menu=$("menu");
function closeMenu(){menu.style.display="none";menu.innerHTML=""}
function menuAt(x,y,build){
  closeMenu();build(menu);menu.style.display="block";
  const r=menu.getBoundingClientRect();
  menu.style.left=Math.min(x,innerWidth-r.width-8)+"px";
  menu.style.top=Math.min(y,innerHeight-r.height-8)+"px";
}
function menuButton(text,fn,cls){
  const b=document.createElement("button");b.textContent=text;
  if(cls)b.className=cls;b.onclick=()=>{closeMenu();fn()};menu.appendChild(b);
}
$("video").addEventListener("contextmenu",ev=>{
  ev.preventDefault();
  const el=ev.target.closest(".el");
  if(el){
    const id=+el.dataset.id;
    if(!state.selected.includes(id))state.selected=[id];
    renderAll();
    menuAt(ev.clientX,ev.clientY,m=>{
      const title=document.createElement("div");title.className="title";title.textContent=KIND[getEl(id).kind].name+"の操作";m.appendChild(title);
      menuButton("接続線を引く",()=>{state.connectFrom=id;renderVideo()});
      menuButton("削除",deleteSelected,"danger");
    });
  }else{
    menuAt(ev.clientX,ev.clientY,m=>{
      const t=document.createElement("div");t.className="title";t.textContent="要素を追加";m.appendChild(t);
      Object.keys(KIND).forEach(k=>menuButton(KIND[k].name+"を追加",()=>{
        addElement(k,W/2,H/2);
      }));
    });
  }
});
document.addEventListener("contextmenu",ev=>{
  if(!$("video").contains(ev.target)&&!$("lanes").contains(ev.target))return;
});
document.addEventListener("pointerdown",ev=>{
  if(!ev.target.closest("#menu"))closeMenu();
});
document.addEventListener("keydown",ev=>{
  if(ev.key==="Escape"){state.connectFrom=null;closeMenu();renderAll()}
  if((ev.key==="Delete"||ev.key==="Backspace")&&state.selected.length&&!["INPUT","TEXTAREA"].includes(ev.target.tagName)){
    deleteSelected();
  }
});

/* 初期データ */
state.videos=[
  {id:uid(),name:"操作手順_顧客登録.mp4",dur:90,url:null,imported:"2026/09/20 10:00"},
  {id:uid(),name:"障害再現_決済画面.mp4",dur:60,url:null,imported:"2026/09/25 14:30"}
];
state.works=[
  {id:uid(),videoId:state.videos[0].id,name:"顧客登録_注釈入り",saved:"2026/09/21 11:00",data:{
    nid:4,
    els:[
      {id:1,kind:"comment",start:5,end:20,x:70,y:45,w:180,h:55,text:"ここに入力します",lineOn:true,lw:2,lc:"#2563eb",tc:"#000",bg:"#fff",fillOn:true,fs:16,font:"sans-serif"},
      {id:2,kind:"box",start:15,end:35,x:360,y:110,w:190,h:110,lw:3,lc:"#dc2626",fillOn:false,fc:"#dc2626",dash:"solid"},
      {id:3,kind:"zoom",start:35,end:50,x:250,y:80,w:190,h:130}
    ],
    lines:[{id:3,from:1,to:2,shape:"line",color:"#fff",w:2,dash:"solid",start:"none",end:"arrow"}]
  }}
];
state.results=[{id:uid(),name:"顧客登録_完成版",videoId:state.videos[0].id,origName:state.videos[0].name,created:"2026/09/23 09:00"}];

renderHome();
</script>
</body>
</html>

<?php
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Yu Gothic",sans-serif;font-size:13px;color:#222;background:#f3f4f6}
button,input,select{font:inherit}
button{cursor:pointer;border:1px solid #999;background:#fff;border-radius:4px;padding:6px 10px}
button:hover{background:#eef2ff}
button.primary{background:#2563eb;color:#fff;border-color:#2563eb}
button.danger{color:#b91c1c;border-color:#fca5a5}
button.small{padding:4px 7px;font-size:12px}
h1,h2,h3{margin:0}
h2{font-size:16px;margin-bottom:10px}
h3{font-size:14px;margin-bottom:8px}
.muted{color:#6b7280;font-size:12px}
.sp{flex:1}
.badge{display:inline-block;padding:2px 7px;border-radius:10px;background:#e5e7eb;color:#555;font-size:11px}
.badge.blue{background:#dbeafe;color:#1d4ed8}
.badge.green{background:#dcfce7;color:#166534}
.badge.orange{background:#ffedd5;color:#9a3412}

#home{padding:18px;display:grid;grid-template-columns:1.4fr 1fr;gap:16px}
.panel{background:#fff;border:1px solid #d1d5db;border-radius:7px;padding:14px}
.panel-head{display:flex;align-items:center;gap:8px;margin-bottom:12px}
.video-row{border:1px solid #cbd5e1;border-radius:6px;margin-bottom:10px;overflow:hidden}
.video-head{padding:9px;background:#eef2f7;display:flex;align-items:center;gap:8px}
.video-name{font-weight:bold}
.video-meta{color:#6b7280;font-size:11px}
.work-list{padding:6px 12px 10px 28px;border-left:3px solid #2563eb;margin:6px 8px 8px 14px}
.work-row{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px dotted #ccc}
.work-row:last-child{border-bottom:0}
.empty{color:#888;padding:8px}
.result-row{display:flex;align-items:center;gap:8px;padding:9px 2px;border-bottom:1px dotted #ccc}
.result-row:last-child{border-bottom:0}
.message{color:#b91c1c;margin-bottom:8px}

#editor{display:none;height:100vh;overflow:hidden;flex-direction:column;background:#111827;color:#e5e7eb}
#topbar{background:#1f2937;padding:7px 10px;display:flex;align-items:center;gap:7px;min-height:50px}
#topbar button{background:#374151;color:#fff;border-color:#4b5563}
#topbar button.primary{background:#2563eb}
#topinfo{display:flex;align-items:center;gap:7px;min-width:0}
#topinfo .title{font-weight:bold;white-space:nowrap}
#dirty{color:#fbbf24}
#stage{flex:1;min-height:0;background:#090d14;display:flex;align-items:center;justify-content:center;padding:10px}
#videoFrame{position:relative;width:min(900px,95vw);aspect-ratio:16/9;background:#000;overflow:hidden;border:1px solid #374151}
#videoFrame video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain}
#fakeVideo{position:absolute;inset:0;background:linear-gradient(135deg,#17336f,#087f6c 52%,#c16a16);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px}
#fakeVideo:before{content:"";position:absolute;inset:0;background-image:linear-gradient(#ffffff18 1px,transparent 1px),linear-gradient(90deg,#ffffff18 1px,transparent 1px);background-size:45px 45px}
#fakeVideo span{position:relative;background:#0008;padding:8px 15px;border-radius:5px}
#overlay{position:absolute;inset:0}
.element{position:absolute;cursor:move;user-select:none}
.element.selected{outline:2px dashed #fde047;outline-offset:2px}
.element .resize{position:absolute;right:-5px;bottom:-5px;width:10px;height:10px;background:#fde047;border:1px solid #333;cursor:nwse-resize}
.comment{display:flex;align-items:center;justify-content:center;text-align:center;overflow:hidden;padding:4px}
.highlight{width:100%;height:100%}
.zoom{width:100%;height:100%;border:2px solid #10b981;background:#10b98133;display:flex;align-items:center;justify-content:center}
.zoom span{background:#059669;color:#fff;padding:2px 6px;font-size:11px}
.skip-banner{position:absolute;bottom:5px;left:50%;transform:translateX(-50%);background:#111c;color:#fbbf24;padding:4px 12px;border-radius:12px;font-size:11px}
#connectionSvg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
#connectionSvg .hit{pointer-events:stroke;stroke:transparent;stroke-width:18;fill:none;cursor:pointer}
#control{background:#e5e7eb;color:#222;padding:6px 10px;display:flex;align-items:center;gap:7px;flex-wrap:wrap;border-top:1px solid #aaa}
#control .sp{margin-left:auto}
#position{font-variant-numeric:tabular-nums}

#timeline{height:300px;background:#fff;color:#222;padding:6px 10px;border-top:1px solid #aaa}
#timelineInfo{height:25px;display:flex;align-items:center;gap:12px;font-size:12px}
#timelineTools{height:32px;display:flex;align-items:center;gap:5px}
#timelineTools button{padding:3px 7px}
#timelineArea{height:225px;position:relative;overflow:hidden}
#ruler{position:absolute;left:0;right:0;top:0;height:27px;background:#f1f5f9;border:1px solid #aaa;cursor:pointer}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #888;font-size:10px;padding-left:3px;pointer-events:none}
#lanes{position:absolute;left:0;right:0;top:27px;bottom:0;border:1px solid #aaa;border-top:0;overflow:hidden;background:#fff}
.lane-row{position:absolute;left:0;right:0;height:36px;border-bottom:1px solid #e5e7eb}
.lane-label{position:absolute;left:0;top:0;width:85px;height:36px;padding:10px 6px;background:#f8fafc;border-right:1px solid #ddd;font-size:11px}
.track{position:absolute;left:85px;right:0;top:0;height:36px}
.timeline-bar{position:absolute;top:6px;height:24px;border-radius:4px;color:#fff;font-size:10px;padding:5px 7px;white-space:nowrap;overflow:hidden;cursor:grab}
.timeline-bar.selected{outline:2px solid #fde047}
.timeline-bar .left,.timeline-bar .right{position:absolute;top:0;width:6px;height:100%;background:#0004;cursor:ew-resize}
.timeline-bar .left{left:0}.timeline-bar .right{right:0}
#playhead{position:absolute;top:0;width:2px;background:#dc2626;height:100%;z-index:20;pointer-events:none}
#playhead:before{content:"";position:absolute;top:-2px;left:-6px;width:13px;height:13px;background:#dc2626;clip-path:polygon(0 0,100% 0,50% 100%)}
#overview{height:24px;background:#e2e8f0;border:1px solid #94a3b8;position:relative}
#viewWindow{position:absolute;top:0;height:100%;background:#2563eb33;border:2px solid #2563eb}
#viewWindow .grip{position:absolute;top:0;width:7px;height:100%;background:#2563eb;cursor:ew-resize}
#viewWindow .grip.left{left:-2px}.grip.right{right:-2px}
.overview-item{position:absolute;top:3px;height:4px;background:#475569}
#overviewPlay{position:absolute;top:0;height:100%;border-left:2px solid #dc2626}

#menu{position:fixed;z-index:1000;display:none;background:#fff;color:#222;border:1px solid #666;border-radius:5px;box-shadow:0 5px 20px #0004;padding:6px;min-width:230px;max-width:290px;max-height:85vh;overflow:auto}
.menu-title{font-weight:bold;border-bottom:1px solid #ddd;padding:4px;margin-bottom:4px}
.menu-button{display:block;width:100%;text-align:left;border:0;background:#fff;padding:6px}
.menu-button:hover{background:#dbeafe}
.menu-section{margin:7px 2px}
.menu-section label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.menu-section input[type=text],.menu-section input[type=number],.menu-section select{width:100%;padding:5px;border:1px solid #aaa;border-radius:3px}
.color-list{display:flex;flex-wrap:wrap;gap:3px}
.color{width:19px;height:19px;padding:0;border:1px solid #777}
.color.active{outline:2px solid #2563eb}
.choice-list{display:flex;flex-wrap:wrap;gap:3px}
.choice{padding:3px 5px;font-size:11px}
.choice.active{background:#dbeafe;border-color:#2563eb}

#modal{display:none;position:fixed;inset:0;background:#0008;z-index:2000;align-items:center;justify-content:center}
.modal-box{background:#fff;color:#222;width:min(520px,92vw);max-height:82vh;overflow:auto;border-radius:7px;padding:16px}
.modal-title{font-size:16px;font-weight:bold;margin-bottom:10px}
.modal-actions{display:flex;justify-content:flex-end;gap:7px;flex-wrap:wrap;margin-top:14px}
.modal-input{width:100%;padding:7px;border:1px solid #aaa;border-radius:4px}
.radio-row{display:block;padding:5px 0}
.progress{height:14px;background:#ddd;border-radius:7px;overflow:hidden;margin-top:10px}
.progress div{height:100%;width:0;background:#2563eb}
#toast{position:fixed;right:18px;bottom:18px;background:#1f2937;color:#fff;padding:10px 15px;border-radius:5px;z-index:3000;box-shadow:0 4px 15px #0005}
.hidden{display:none!important}

@media(max-width:800px){
 #home{grid-template-columns:1fr;padding:10px}
 #topbar{flex-wrap:wrap}
 #topinfo{width:100%}
 #timeline{height:280px}
}
</style>
</head>
<body>

<div id="home">
  <section class="panel">
    <div class="panel-head">
      <div>
        <h2>オリジナル動画</h2>
        <div class="muted">編集対象の動画を明示的に選択してください。</div>
      </div>
      <span class="sp"></span>
      <span id="videoCount" class="badge blue"></span>
      <button class="primary" id="addVideo">動画を追加</button>
      <input id="fileInput" type="file" accept="video/*" hidden>
    </div>
    <div id="homeMessage" class="message"></div>
    <div id="videoList"></div>
  </section>

  <section class="panel">
    <div class="panel-head">
      <div>
        <h2>編集結果動画</h2>
        <div class="muted">編集作業から作成した動画です。</div>
      </div>
      <span class="sp"></span>
      <span id="resultCount" class="badge green"></span>
    </div>
    <div id="resultList"></div>
  </section>
</div>

<div id="editor">
  <div id="topbar">
    <div id="topinfo">
      <span class="title">動画編集</span>
      <span>動画：<b id="editorVideo"></b></span>
      <span>編集作業：<b id="editorWork"></b></span>
      <span id="dirty"></span>
    </div>
    <span class="sp"></span>
    <button id="save">保存</button>
    <button id="saveAs">別の編集作業として保存</button>
    <button id="export" class="primary">編集結果を動画にする</button>
    <button id="finish">編集作業を終了する</button>
  </div>

  <div id="stage">
    <div id="videoFrame">
      <div id="fakeVideo"><span id="fakeVideoText"></span></div>
      <video id="realVideo" class="hidden" playsinline></video>
      <svg id="connectionSvg"></svg>
      <div id="overlay"></div>
    </div>
  </div>

  <div id="control">
    <button id="play">▶ 再生</button>
    <button id="pause">⏸ 一時停止</button>
    <button id="stop">⏹ 停止</button>
    <label><input id="skipPlay" type="checkbox" checked> スキップを反映して再生</label>
    <span id="position"></span>
    <span class="sp"></span>
    <button id="zoomOut">－</button>
    <input id="zoomSlider" type="range" min="0" max="100" value="0" style="width:150px">
    <button id="zoomIn">＋</button>
    <span id="zoomInfo"></span>
    <button id="zoomFit">全体表示</button>
  </div>

  <div id="timeline">
    <div id="timelineInfo">
      <span id="rangeInfo"></span>
      <span class="muted">時間軸の拡大・縮小は表示だけを変更します。</span>
    </div>
    <div id="timelineTools">
      <button id="panLeft">◀</button>
      <button id="panRight">▶</button>
      <button id="addComment">コメント</button>
      <button id="addHighlight">強調枠</button>
      <button id="addZoom">拡大枠</button>
      <button id="addSkip">スキップ</button>
      <span class="sp"></span>
      <span class="muted">右クリック：操作メニュー</span>
    </div>
    <div id="timelineArea">
      <div id="ruler"></div>
      <div id="lanes"></div>
      <div id="playhead"></div>
    </div>
    <div id="overview"></div>
  </div>
</div>

<div id="menu"></div>
<div id="modal"></div>
<div id="toast" class="hidden"></div>

<script>
"use strict";

const LIMIT=10;
const MIN_TIME=.5;
const DEFAULT_TIME=5;
const COLORS=["#000000","#ffffff","#ef4444","#f97316","#eab308","#22c55e","#14b8a6","#3b82f6","#6366f1","#a855f7","#ec4899","#6b7280"];
const TYPES={
  comment:{name:"コメント",color:"#2563eb"},
  highlight:{name:"強調枠",color:"#dc2626"},
  zoom:{name:"拡大枠",color:"#059669"},
  skip:{name:"スキップ",color:"#d97706"}
};
const LINE_TYPES=["実線","点線","破線","一点鎖線"];
const SHAPES=["直線","折れ線","波線"];
const FONTS=["sans-serif","serif","monospace","cursive"];

let seq=100;
let videos=[
 {id:1,name:"操作手順_顧客登録.mp4",duration:120,imported:"2026/09/20 10:00"},
 {id:2,name:"障害再現_決済画面.mp4",duration:75,imported:"2026/09/25 14:30"}
];
let works=[
 {id:1,videoId:1,name:"顧客登録_注釈入り",saved:"2026/09/21 11:00",data:null},
 {id:2,videoId:1,name:"顧客登録_短縮版",saved:"2026/09/22 16:45",data:null}
];
let results=[
 {id:1,name:"顧客登録_完成版",videoId:1,source:"操作手順_顧客登録.mp4",created:"2026/09/23 09:00"}
];
let editor=null;
let menuTarget=null;
let drag=null;
let timer=null;
let realVideoUrl=null;

const $=id=>document.getElementById(id);
const uid=()=>++seq;
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const esc=s=>String(s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const clone=o=>JSON.parse(JSON.stringify(o));

function time(t){
 t=Math.max(0,Number(t)||0);
 const m=Math.floor(t/60),s=t-m*60;
 return String(m).padStart(2,"0")+":"+s.toFixed(1).padStart(4,"0");
}

function toast(text){
 $("toast").textContent=text;
 $("toast").classList.remove("hidden");
 clearTimeout(toast._timer);
 toast._timer=setTimeout(()=>$("toast").classList.add("hidden"),1800);
}

function showModal(title,body,buttons){
 const root=$("modal");
 root.innerHTML=
  '<div class="modal-box">'+
  '<div class="modal-title">'+title+'</div>'+
  '<div>'+body+'</div>'+
  '<div class="modal-actions" id="modalActions"></div>'+
  '</div>';
 buttons.forEach(b=>{
   const btn=document.createElement("button");
   btn.textContent=b.text;
   if(b.class)btn.className=b.class;
   btn.onclick=()=>{
     if(b.action)b.action();
     if(b.close!==false)closeModal();
   };
   $("modalActions").appendChild(btn);
 });
 root.style.display="flex";
}

function closeModal(){$("modal").style.display="none";$("modal").innerHTML=""}

function nowText(){
 const d=new Date();
 return d.getFullYear()+"/"+String(d.getMonth()+1).padStart(2,"0")+"/"+String(d.getDate()).padStart(2,"0")+" "+
 String(d.getHours()).padStart(2,"0")+":"+String(d.getMinutes()).padStart(2,"0");
}

function renderHome(){
 $("videoCount").textContent=videos.length+"/"+LIMIT+"件";
 $("resultCount").textContent=results.length+"/"+LIMIT+"件";

 let h="";
 if(!videos.length)h='<div class="empty">オリジナル動画はありません。</div>';

 videos.forEach(v=>{
   const ws=works.filter(w=>w.videoId===v.id);
   h+=
   '<div class="video-row">'+
   '<div class="video-head">'+
   '<div><div class="video-name">'+esc(v.name)+'</div>'+
   '<div class="video-meta">長さ '+time(v.duration)+' ／ 取り込み '+esc(v.imported)+'</div></div>'+
   '<span class="sp"></span>'+
   '<span class="badge blue">'+ws.length+'件の編集作業</span>'+
   '<button class="primary small" data-new="'+v.id+'">新しい編集作業</button>'+
   '<button class="danger small" data-delete-video="'+v.id+'">削除</button>'+
   '</div>'+
   '<div class="work-list">';

   if(!ws.length)h+='<div class="empty">保存済みの編集作業はありません。</div>';

   ws.forEach(w=>{
     h+=
     '<div class="work-row">'+
     '<div><b>'+esc(w.name)+'</b><div class="muted">最終保存：'+esc(w.saved)+'</div></div>'+
     '<span class="sp"></span>'+
     '<button class="small" data-resume="'+w.id+'">再開</button>'+
     '<button class="small" data-copy="'+w.id+'">複製</button>'+
     '<button class="danger small" data-delete-work="'+w.id+'">削除</button>'+
     '</div>';
   });
   h+='</div></div>';
 });
 $("videoList").innerHTML=h;

 let r="";
 if(!results.length)r='<div class="empty">編集結果動画はありません。</div>';
 results.forEach(x=>{
   r+=
   '<div class="result-row">'+
   '<div><b>'+esc(x.name)+'</b>'+
   '<div class="muted">元動画：'+esc(x.source)+' ／ 作成：'+esc(x.created)+'</div></div>'+
   '<span class="sp"></span>'+
   '<button class="small" data-result-play="'+x.id+'">再生</button>'+
   '<button class="small" data-result-edit="'+x.id+'">編集対象にする</button>'+
   '<button class="danger small" data-result-delete="'+x.id+'">削除</button>'+
   '</div>';
 });
 $("resultList").innerHTML=r;
}

$("videoList").onclick=e=>{
 const d=e.target.dataset;
 if(d.new)startEditor(Number(d.new),null);
 if(d.resume)startEditor(findWork(Number(d.resume)).videoId,findWork(Number(d.resume)));
 if(d.copy)duplicateWork(Number(d.copy));
 if(d["delete-work"])deleteWork(Number(d["delete-work"]));
 if(d["delete-video"])deleteVideo(Number(d["delete-video"]));
};

$("resultList").onclick=e=>{
 const d=e.target.dataset;
 if(d.resultPlay)playResult(Number(d.resultPlay));
 if(d.resultEdit)editResult(Number(d.resultEdit));
 if(d.resultDelete)deleteResult(Number(d.resultDelete));
};

function findVideo(id){return videos.find(v=>v.id===id)}
function findWork(id){return works.find(w=>w.id===id)}
function findElement(id){return editor.elements.find(x=>x.id===id)}
function findResult(id){return results.find(x=>x.id===id)}

$("addVideo").onclick=()=>$("fileInput").click();

$("fileInput").onchange=e=>{
 const file=e.target.files[0];
 if(!file)return;
 const url=URL.createObjectURL(file);
 const v=document.createElement("video");
 v.preload="metadata";
 v.onloadedmetadata=()=>{
   const duration=Number.isFinite(v.duration)?v.duration:60;
   importVideo(file.name,duration,url);
 };
 v.onerror=()=>{
   $("homeMessage").textContent="選択した動画を読み込めませんでした。編集画面は開始しません。";
   URL.revokeObjectURL(url);
 };
 v.src=url;
 e.target.value="";
};

function importVideo(name,duration,url){
 $("homeMessage").textContent="";
 const add=()=>{
   videos.push({id:uid(),name,duration,imported:nowText(),url:url||null});
   renderHome();
   startEditor(videos[videos.length-1].id,null);
 };
 if(videos.length<LIMIT){add();return}
 const choices=videos.map((v,i)=>
   '<label class="radio-row"><input type="radio" name="removeVideo" value="'+v.id+'" '+(!i?"checked":"")+'> '+
   esc(v.name)+'</label>').join("");
 showModal("オリジナル動画の上限",
   '<p>オリジナル動画は10件までです。</p><p>新しい動画を追加するには、削除する動画を選択してください。</p>'+choices,
   [{text:"キャンセル"},{text:"削除して追加",class:"danger",action:()=>{
     const r=document.querySelector('input[name="removeVideo"]:checked');
     if(!r)return;
     const id=Number(r.value);
     videos=videos.filter(v=>v.id!==id);
     works=works.filter(w=>w.videoId!==id);
     add();
   }}]);
}

function deleteVideo(id){
 const v=findVideo(id);
 const count=works.filter(w=>w.videoId===id).length;
 showModal("動画を削除",
  '<p>「'+esc(v.name)+'」を削除します。</p>'+
  (count?'<p><b>関連する編集作業'+count+'件も削除されます。</b></p>':'')+
  '<p class="muted">既に作成した編集結果動画は削除しません。</p>',
  [{text:"キャンセル"},{text:"削除する",class:"danger",action:()=>{
    videos=videos.filter(x=>x.id!==id);
    works=works.filter(w=>w.videoId!==id);
    renderHome();
  }}]);
}

function deleteWork(id){
 const w=findWork(id);
 showModal("編集作業を削除",
  '<p>「'+esc(w.name)+'」を削除します。</p><p class="muted">オリジナル動画と編集結果動画には影響しません。</p>',
  [{text:"キャンセル"},{text:"削除する",class:"danger",action:()=>{
    works=works.filter(x=>x.id!==id);
    renderHome();
  }}]);
}

function duplicateWork(id){
 const w=findWork(id);
 if(works.length>=LIMIT){
   showModal("編集作業の上限","<p>編集作業は10件までです。既存の編集作業を削除してください。</p>",[{text:"閉じる"}]);
   return;
 }
 showModal("編集作業を複製",
  '<label>編集作業名<input class="modal-input" id="copyName" value="'+esc(w.name+"（コピー）")+'"></label>',
  [{text:"キャンセル"},{text:"複製する",class:"primary",action:()=>{
    works.push({
      id:uid(),
      videoId:w.videoId,
      name:$("copyName").value.trim()||w.name+"（コピー）",
      saved:nowText(),
      data:w.data?clone(w.data):null
    });
    renderHome();
    toast("編集作業を複製しました");
  }}]);
}

function startEditor(videoId,work){
 const video=findVideo(videoId);
 editor={
   video,
   work,
   duration:video.duration,
   position:0,
   viewStart:0,
   viewEnd:video.duration,
   elements:work&&work.data?clone(work.data.elements):createDefaultElements(video.duration),
   connections:work&&work.data?clone(work.data.connections):[],
   selected:[],
   selectedConnection:null,
   dirty:false
 };
 $("home").style.display="none";
 $("editor").style.display="flex";
 $("editorVideo").textContent=video.name;
 $("editorWork").textContent=work?work.name:"未保存の編集作業";
 $("dirty").textContent="";
 if(realVideoUrl){URL.revokeObjectURL(realVideoUrl);realVideoUrl=null}
 if(video.url){
   realVideoUrl=video.url;
   $("realVideo").src=video.url;
   $("realVideo").classList.remove("hidden");
   $("fakeVideo").classList.add("hidden");
 }else{
   $("realVideo").classList.add("hidden");
   $("fakeVideo").classList.remove("hidden");
   $("fakeVideoText").textContent=video.name;
 }
 draw();
}

function createDefaultElements(duration){
 return [
  {id:uid(),type:"comment",s:Math.min(duration*.05,duration-MIN_TIME),e:Math.min(duration*.2,duration),
   x:.08,y:.08,w:.32,h:.14,
   a:{text:"ここを確認してください",line:true,lw:2,lc:"#2563eb",tc:"#000",bg:"#fff",fill:true,fs:16,font:"sans-serif"}},
  {id:uid(),type:"highlight",s:Math.min(duration*.2,duration-MIN_TIME),e:Math.min(duration*.4,duration),
   x:.50,y:.28,w:.32,h:.30,
   a:{lw:4,lc:"#dc2626",fill:false,dash:"実線",shape:"直線"}},
  {id:uid(),type:"zoom",s:Math.min(duration*.45,duration-MIN_TIME),e:Math.min(duration*.60,duration),
   x:.15,y:.53,w:.28,h:.22,a:{scale:1.5}},
  {id:uid(),type:"skip",s:Math.min(duration*.72,duration-MIN_TIME),e:Math.min(duration*.78+MIN_TIME,duration),
   x:0,y:0,w:0,h:0,a:{}}
 ];
}

function markDirty(){
 editor.dirty=true;
 $("dirty").textContent="● 未保存の変更あり";
}

function clearDirty(){
 editor.dirty=false;
 $("dirty").textContent="";
}

function stopPlayback(){
 if(timer){clearInterval(timer);timer=null}
 $("realVideo").pause();
 $("play").textContent="▶ 再生";
}

$("play").onclick=()=>{
 if(timer)return;
 if(editor.position>=editor.duration)editor.position=0;
 $("play").textContent="❚❚ 再生中";
 let last=performance.now();
 if(editor.video.url)$("realVideo").play().catch(()=>{});
 timer=setInterval(()=>{
   const now=performance.now();
   const dt=(now-last)/1000;
   last=now;
   editor.position=editor.video.url?$("realVideo").currentTime:editor.position+dt;
   if($("skipPlay").checked){
     const skip=editor.elements.find(x=>x.type==="skip"&&editor.position>=x.s&&editor.position<x.e);
     if(skip){
       editor.position=skip.e;
       if(editor.video.url)$("realVideo").currentTime=skip.e;
     }
   }
   if(editor.position>=editor.duration){
     editor.position=editor.duration;
     stopPlayback();
   }
   draw();
 },50);
};

$("pause").onclick=()=>stopPlayback();
$("stop").onclick=()=>{
 stopPlayback();
 editor.position=0;
 if(editor.video.url)$("realVideo").currentTime=0;
 draw();
};

$("realVideo").addEventListener("timeupdate",()=>{
 if(editor&&timer){
   editor.position=$("realVideo").currentTime;
   draw();
 }
});

function timelineWidth(){return $("lanes").clientWidth-85}
function scaleRange(){
 const z=Number($("zoomSlider").value)/100;
 return Math.max(.05,1-z*.92);
}

function setView(start,end){
 const width=clamp(end-start,.05,editor.duration);
 start=clamp(start,0,editor.duration-width);
 editor.viewStart=start;
 editor.viewEnd=start+width;
 drawTimeline();
}

function zoomTimeline(focus,factor){
 const old=editor.viewEnd-editor.viewStart;
 const next=clamp(old/factor,.05,editor.duration);
 const ratio=(focus-editor.viewStart)/old;
 setView(focus-ratio*next,focus-ratio*next+next);
}

$("zoomIn").onclick=()=>zoomTimeline(editor.position,1.5);
$("zoomOut").onclick=()=>zoomTimeline(editor.position,1/1.5);
$("zoomFit").onclick=()=>setView(0,editor.duration);

$("zoomSlider").oninput=()=>{
 const z=Number($("zoomSlider").value)/100;
 const width=editor.duration*(1-z*.92);
 const center=editor.position;
 setView(center-width/2,center+width/2);
};

$("panLeft").onclick=()=>{
 const w=editor.viewEnd-editor.viewStart;
 setView(editor.viewStart-w*.25,editor.viewEnd-w*.25);
};
$("panRight").onclick=()=>{
 const w=editor.viewEnd-editor.viewStart;
 setView(editor.viewStart+w*.25,editor.viewEnd+w*.25);
};

function draw(){
 if(!editor)return;
 drawVideo();
 drawTimeline();
}

function drawVideo(){
 const overlay=$("overlay");
 overlay.innerHTML="";
 editor.elements.forEach(el=>{
   if(el.type==="skip")return;
   if(editor.position<el.s||editor.position>el.e)return;

   const d=document.createElement("div");
   d.className="element"+(editor.selected.includes(el.id)?" selected":"");
   d.dataset.id=el.id;
   d.style.left=(el.x*100)+"%";
   d.style.top=(el.y*100)+"%";
   d.style.width=(el.w*100)+"%";
   d.style.height=(el.h*100)+"%";

   const a=el.a;
   if(el.type==="comment"){
     d.classList.add("comment");
     d.textContent=a.text;
     d.style.color=a.tc;
     d.style.fontSize=a.fs+"px";
     d.style.fontFamily=a.font;
     d.style.background=a.fill?a.bg:"transparent";
     d.style.border=a.line?a.lw+"px solid "+a.lc:"none";
   }
   if(el.type==="highlight"){
     d.classList.add("highlight");
     d.style.border=a.lw+"px "+dashStyle(a.dash)+" "+a.lc;
     d.style.background=a.fill?a.lc+"33":"transparent";
   }
   if(el.type==="zoom"){
     d.classList.add("zoom");
     d.innerHTML='<span>拡大 '+(a.scale||1.5)+'倍</span>';
   }

   if(editor.selected.includes(el.id)){
     const h=document.createElement("div");
     h.className="resize";
     d.appendChild(h);
   }

   d.onpointerdown=e=>elementDown(e,el);
   d.oncontextmenu=e=>{
     e.preventDefault();
     e.stopPropagation();
     editor.selected=[el.id];
     editor.selectedConnection=null;
     draw();
     openElementMenu(e.clientX,e.clientY,el);
   };
   overlay.appendChild(d);
 });

 drawConnections();

 const skip=editor.elements.find(x=>x.type==="skip"&&editor.position>=x.s&&editor.position<x.e);
 if(skip){
   const b=document.createElement("div");
   b.className="skip-banner";
   b.textContent="この範囲はスキップされます";
   overlay.appendChild(b);
 }
}

function dashStyle(v){
 if(v==="点線")return"dotted";
 if(v==="破線")return"dashed";
 if(v==="一点鎖線")return"solid";
 return"solid";
}

function elementDown(e,el){
 if(e.button!==0)return;
 closeMenu();
 if(e.shiftKey){
   editor.selected=editor.selected.includes(el.id)?
     editor.selected.filter(x=>x!==el.id):editor.selected.concat(el.id);
 }else if(!editor.selected.includes(el.id)){
   editor.selected=[el.id];
 }
 editor.selectedConnection=null;
 draw();

 const rect=$("videoFrame").getBoundingClientRect();
 const sx=e.clientX,sy=e.clientY;
 const resize=!!e.target.classList.contains("resize");
 const originals=editor.selected.map(id=>{
   const x=findElement(id);
   return {x,id,y:x.y,w:x.w,h:x.h};
 });

 function move(ev){
   const dx=(ev.clientX-sx)/rect.width;
   const dy=(ev.clientY-sy)/rect.height;
   originals.forEach(o=>{
     const x=findElement(o.id);
     if(resize&&editor.selected.length===1){
       x.w=clamp(o.w+dx,.05,1-x.x);
       x.h=clamp(o.h+dy,.05,1-x.y);
     }else{
       x.x=clamp(o.x+dx,0,1-x.w);
       x.y=clamp(o.y+dy,0,1-x.h);
     }
   });
   markDirty();
   draw();
 }
 function up(){
   window.removeEventListener("pointermove",move);
   window.removeEventListener("pointerup",up);
 }
 window.addEventListener("pointermove",move);
 window.addEventListener("pointerup",up);
}

function drawConnections(){
 const svg=$("connectionSvg");
 let h="";
 editor.connections.forEach(c=>{
   const a=findElement(c.from),b=findElement(c.to);
   if(!a||!b)return;
   if(a.type==="skip"||b.type==="skip")return;
   if(editor.position<a.s||editor.position>a.e||editor.position<b.s||editor.position>b.e)return;

   const p1=point(a,c.fromSide||"right");
   const p2=point(b,c.toSide||"left");
   const selected=editor.selectedConnection===c.id;
   let points="";
   if(c.shape==="直線"){
     points=p1.x*100+"%,"+p1.y*100+"% "+p2.x*100+"%,"+p2.y*100+"%";
   }else if(c.shape==="折れ線"){
     const mx=(p1.x+p2.x)/2;
     points=p1.x*100+"%,"+p1.y*100+"% "+mx*100+"%,"+p1.y*100+"% "+mx*100+"%,"+p2.y*100+"% "+p2.x*100+"%,"+p2.y*100+"%";
   }else{
     points=p1.x*100+"%,"+p1.y*100+"% "+p2.x*100+"%,"+p2.y*100+"%";
   }

   const dash=c.dash==="点線"?"2 4":c.dash==="破線"?"8 5":c.dash==="一点鎖線"?"10 4 2 4":"";
   h+='<polyline points="'+points+'" fill="none" stroke="'+(selected?"#f59e0b":"#2563eb")+'" stroke-width="'+(selected?4:2)+'" stroke-dasharray="'+dash+'"></polyline>';
   h+='<polyline class="hit" points="'+points+'" data-connection="'+c.id+'"></polyline>';
   if(c.endArrow)h+='<circle cx="'+p2.x*100+'%" cy="'+p2.y*100+'%" r="5" fill="'+(selected?"#f59e0b":"#2563eb")+'"></circle>';
 });
 svg.innerHTML=h;
 svg.querySelectorAll(".hit").forEach(el=>{
   el.addEventListener("contextmenu",e=>{
     e.preventDefault();
     e.stopPropagation();
     editor.selectedConnection=Number(el.dataset.connection);
     editor.selected=[];
     openConnectionMenu(e.clientX,e.clientY);
   });
   el.addEventListener("click",e=>{
     e.stopPropagation();
     editor.selectedConnection=Number(el.dataset.connection);
     editor.selected=[];
     draw();
   });
 });
}

function point(el,side){
 if(side==="top")return{x:el.x+el.w/2,y:el.y};
 if(side==="bottom")return{x:el.x+el.w/2,y:el.y+el.h};
 if(side==="left")return{x:el.x,y:el.y+el.h/2};
 return{x:el.x+el.w,y:el.y+el.h/2};
}

function drawTimeline(){
 const width=timelineWidth();
 const span=editor.viewEnd-editor.viewStart;
 const step=span<=10?1:span<=30?2:span<=90?5:span<=180?10:30;
 let ruler="";
 for(let t=Math.ceil(editor.viewStart/step)*step;t<=editor.viewEnd+.001;t+=step){
   const x=(t-editor.viewStart)/span*width;
   ruler+='<div class="tick" style="left:'+(85+x)+'px">'+time(t)+'</div>';
 }
 $("ruler").innerHTML=ruler;

 const rows={};
 const ends=[];
 editor.elements.slice().sort((a,b)=>a.s-b.s).forEach(el=>{
   let row=0;
   while(ends[row]>el.s)row++;
   ends[row]=el.e;
   rows[el.id]=row;
 });

 let lanes="";
 const laneTypes=["comment","highlight","zoom","skip"];
 laneTypes.forEach((type,i)=>{
   lanes+='<div class="lane-row" style="top:'+(i*36)+'px">'+
   '<div class="lane-label">'+TYPES[type].name+'</div>'+
   '<div class="track" data-lane="'+type+'">';
   editor.elements.filter(el=>el.type===type).forEach(el=>{
     if(el.e<editor.viewStart||el.s>editor.viewEnd)return;
     const x=(el.s-editor.viewStart)/span*width;
     const x2=(el.e-editor.viewStart)/span*width;
     const selected=editor.selected.includes(el.id);
     lanes+='<div class="timeline-bar '+(selected?"selected":"")+'" data-id="'+el.id+'" '+
       'style="left:'+x+'px;width:'+Math.max(8,x2-x)+'px;background:'+TYPES[type].color+'">'+
       '<div class="left"></div>'+
       esc(TYPES[type].name+(type==="comment"?"："+el.a.text:""))+
       '<div class="right"></div></div>';
   });
   lanes+='</div></div>';
 });
 $("lanes").innerHTML=lanes;

 $("playhead").style.left=(85+(editor.position-editor.viewStart)/span*width)+"px";

 let overview="";
 editor.elements.forEach(el=>{
   overview+='<div class="overview-item" style="left:'+(el.s/editor.duration*100)+'%;width:'+((el.e-el.s)/editor.duration*100)+'%"></div>';
 });
 overview+='<div id="viewWindow"><div class="grip left"></div><div class="grip right"></div></div>';
 overview+='<div id="overviewPlay"></div>';
 $("overview").innerHTML=overview;

 const win=$("viewWindow");
 win.style.left=(editor.viewStart/editor.duration*100)+"%";
 win.style.width=((editor.viewEnd-editor.viewStart)/editor.duration*100)+"%";
 $("overviewPlay").style.left=(editor.position/editor.duration*100)+"%";

 $("position").textContent=time(editor.position)+" / "+time(editor.duration);
 $("rangeInfo").textContent="表示範囲："+time(editor.viewStart)+" ～ "+time(editor.viewEnd)+"（"+(editor.viewEnd-editor.viewStart).toFixed(1)+"秒）";
 $("zoomInfo").textContent="倍率 "+(editor.duration/(editor.viewEnd-editor.viewStart)).toFixed(1)+"倍";

 const ratio=editor.duration/(editor.viewEnd-editor.viewStart);
 $("zoomSlider").value=Math.round(Math.log(ratio)/Math.log(editor.duration/.05)*100);

 bindTimeline();
}

function bindTimeline(){
 $("ruler").onpointerdown=e=>{
   seekTimeline(e);
   const move=ev=>seekTimeline(ev);
   const up=()=>{
     window.removeEventListener("pointermove",move);
     window.removeEventListener("pointerup",up);
   };
   window.addEventListener("pointermove",move);
   window.addEventListener("pointerup",up);
 };

 $("lanes").querySelectorAll(".timeline-bar").forEach(bar=>{
   bar.onpointerdown=e=>{
     e.stopPropagation();
     const el=findElement(Number(bar.dataset.id));
     if(e.shiftKey){
       editor.selected=editor.selected.includes(el.id)?
         editor.selected.filter(x=>x!==el.id):editor.selected.concat(el.id);
     }else{
       editor.selected=[el.id];
     }
     editor.selectedConnection=null;
     draw();

     const track=bar.parentElement.getBoundingClientRect();
     const startX=e.clientX;
     const oldS=el.s,oldE=el.e,len=oldE-oldS;
     const mode=e.target.classList.contains("left")?"left":e.target.classList.contains("right")?"right":"move";

     const move=ev=>{
       const dt=(ev.clientX-startX)/track.width*(editor.viewEnd-editor.viewStart);
       if(mode==="move"){
         el.s=clamp(oldS+dt,0,editor.duration-len);
         el.e=el.s+len;
       }else if(mode==="left"){
         el.s=clamp(oldS+dt,0,oldE-MIN_TIME);
       }else{
         el.e=clamp(oldE+dt,oldS+MIN_TIME,editor.duration);
       }
       markDirty();
       draw();
     };
     const up=()=>{
       window.removeEventListener("pointermove",move);
       window.removeEventListener("pointerup",up);
     };
     window.addEventListener("pointermove",move);
     window.addEventListener("pointerup",up);
   };

   bar.oncontextmenu=e=>{
     e.preventDefault();
     e.stopPropagation();
     const el=findElement(Number(bar.dataset.id));
     editor.selected=[el.id];
     editor.selectedConnection=null;
     openElementMenu(e.clientX,e.clientY,el);
   };
 });
}

function seekTimeline(e){
 const r=$("ruler").getBoundingClientRect();
 const x=clamp(e.clientX-r.left-85,0,timelineWidth());
 editor.position=clamp(editor.viewStart+x/timelineWidth()*(editor.viewEnd-editor.viewStart),0,editor.duration);
 if(editor.video.url)$("realVideo").currentTime=editor.position;
 draw();
}

$("overview").onpointerdown=e=>{
 const r=$("overview").getBoundingClientRect();
 const ratio=(e.clientX-r.left)/r.width;
 const width=editor.viewEnd-editor.viewStart;

 if(e.target.classList.contains("left")||e.target.classList.contains("right")){
   const side=e.target.classList.contains("left")?"left":"right";
   const start=editor.viewStart,end=editor.viewEnd;
   const move=ev=>{
     const t=clamp((ev.clientX-r.left)/r.width*editor.duration,0,editor.duration);
     if(side==="left")setView(t,end);else setView(start,t);
   };
   const up=()=>{
     window.removeEventListener("pointermove",move);
     window.removeEventListener("pointerup",up);
   };
   window.addEventListener("pointermove",move);
   window.addEventListener("pointerup",up);
   return;
 }

 if(e.target.closest("#viewWindow")){
   const start=editor.viewStart;
   const move=ev=>{
     const dt=(ev.clientX-e.clientX)/r.width*editor.duration;
     setView(start+dt,start+dt+width);
   };
   const up=()=>{
     window.removeEventListener("pointermove",move);
     window.removeEventListener("pointerup",up);
   };
   window.addEventListener("pointermove",move);
   window.addEventListener("pointerup",up);
   return;
 }

 setView(ratio*editor.duration-width/2,ratio*editor.duration+width/2);
};

function openMenu(x,y,build){
 closeMenu();
 const m=$("menu");
 m.innerHTML="";
 build(m);
 m.style.display="block";
 m.style.left=Math.min(x,innerWidth-m.offsetWidth-8)+"px";
 m.style.top=Math.min(y,innerHeight-m.offsetHeight-8)+"px";
}

function closeMenu(){
 $("menu").style.display="none";
 $("menu").innerHTML="";
}

function menuTitle(m,text){
 const d=document.createElement("div");
 d.className="menu-title";
 d.textContent=text;
 m.appendChild(d);
}

function menuButton(m,text,action,cls){
 const b=document.createElement("button");
 b.className="menu-button "+(cls||"");
 b.textContent=text;
 b.onclick=()=>{closeMenu();action()};
 m.appendChild(b);
}

function menuSection(m,label,value,type,onchange){
 const d=document.createElement("div");
 d.className="menu-section";
 d.innerHTML="<label>"+label+"</label>";
 const input=document.createElement("input");
 input.type=type;
 input.value=value;
 input.onchange=()=>onchange(input.value);
 d.appendChild(input);
 m.appendChild(d);
}

function menuChoice(m,label,items,current,onchange){
 const d=document.createElement("div");
 d.className="menu-section";
 d.innerHTML="<label>"+label+"</label>";
 const list=document.createElement("div");
 list.className="choice-list";
 items.forEach(item=>{
   const b=document.createElement("button");
   b.className="choice"+(item===current?" active":"");
   b.textContent=item;
   b.onclick=()=>{
     onchange(item);
     Array.from(list.children).forEach(x=>x.classList.remove("active"));
     b.classList.add("active");
   };
   list.appendChild(b);
 });
 d.appendChild(list);
 m.appendChild(d);
}

function menuCheck(m,label,value,onchange){
 const d=document.createElement("div");
 d.className="menu-section";
 const l=document.createElement("label");
 const input=document.createElement("input");
 input.type="checkbox";
 input.checked=value;
 input.onchange=()=>onchange(input.checked);
 l.appendChild(input);
 l.appendChild(document.createTextNode(" "+label));
 d.appendChild(l);
 m.appendChild(d);
}

function menuColors(m,label,current,onchange){
 const d=document.createElement("div");
 d.className="menu-section";
 d.innerHTML="<label>"+label+"</label>";
 const list=document.createElement("div");
 list.className="color-list";
 COLORS.forEach(c=>{
   const b=document.createElement("button");
   b.className="color"+(c===current?" active":"");
   b.style.background=c;
   b.onclick=()=>{
     onchange(c);
     Array.from(list.children).forEach(x=>x.classList.remove("active"));
     b.classList.add("active");
   };
   list.appendChild(b);
 });
 d.appendChild(list);
 m.appendChild(d);
}

function timeMenu(m,el){
 menuSection(m,"開始時間",el.s.toFixed(1),"number",v=>{
   el.s=clamp(Number(v)||0,0,el.e-MIN_TIME);
   markDirty();draw();
 });
 menuSection(m,"終了時間",el.e.toFixed(1),"number",v=>{
   el.e=clamp(Number(v)||0,el.s+MIN_TIME,editor.duration);
   markDirty();draw();
 });
}

function openElementMenu(x,y,el){
 openMenu(x,y,m=>{
   menuTitle(m,TYPES[el.type].name+"の操作");

   if(el.type==="comment"){
     menuSection(m,"コメント本文",el.a.text,"text",v=>{el.a.text=v;markDirty();draw()});
     menuCheck(m,"線を表示",el.a.line,v=>{el.a.line=v;markDirty();draw()});
     menuChoice(m,"線の太さ",["1","2","4","6"],String(el.a.lw),v=>{el.a.lw=Number(v);markDirty();draw()});
     menuColors(m,"線の色",el.a.lc,v=>{el.a.lc=v;markDirty();draw()});
     menuColors(m,"文字色",el.a.tc,v=>{el.a.tc=v;markDirty();draw()});
     menuCheck(m,"塗り潰し",el.a.fill,v=>{el.a.fill=v;markDirty();draw()});
     menuColors(m,"背景色",el.a.bg,v=>{el.a.bg=v;markDirty();draw()});
     menuChoice(m,"文字サイズ",["12","16","20","24","32"],String(el.a.fs),v=>{el.a.fs=Number(v);markDirty();draw()});
     menuChoice(m,"フォント",FONTS,el.a.font,v=>{el.a.font=v;markDirty();draw()});
   }

   if(el.type==="highlight"){
     menuChoice(m,"線の太さ",["1","2","4","6"],String(el.a.lw),v=>{el.a.lw=Number(v);markDirty();draw()});
     menuColors(m,"線の色",el.a.lc,v=>{el.a.lc=v;markDirty();draw()});
     menuCheck(m,"塗り潰し",el.a.fill,v=>{el.a.fill=v;markDirty();draw()});
     menuChoice(m,"線種",LINE_TYPES,el.a.dash,v=>{el.a.dash=v;markDirty();draw()});
     menuChoice(m,"線形",["直線","折れ線","波線"],el.a.shape,v=>{el.a.shape=v;markDirty();draw()});
   }

   if(el.type==="zoom"){
     menuChoice(m,"拡大率",["1.2","1.5","2.0","2.5"],String(el.a.scale||1.5),v=>{el.a.scale=Number(v);markDirty();draw()});
   }

   timeMenu(m,el);

   const hr=document.createElement("hr");
   m.appendChild(hr);

   if(el.type!=="skip"){
     menuButton(m,"接続線を作成",()=>beginConnection(el.id));
   }

   menuButton(m,"削除",()=>{
     showModal("要素を削除",
       "<p>"+TYPES[el.type].name+"を削除します。</p>",
       [{text:"キャンセル"},{text:"削除する",class:"danger",action:()=>{
         editor.elements=editor.elements.filter(x=>x.id!==el.id);
         editor.connections=editor.connections.filter(c=>c.from!==el.id&&c.to!==el.id);
         editor.selected=[];
         markDirty();draw();
       }}]);
   },"danger");
 });
}

function beginConnection(from){
 editor.selected=[];
 editor.selectedConnection=null;
 closeMenu();
 toast("接続先の要素をクリックしてください");
 editor.connectionFrom=from;
}

$("videoFrame").onpointerdown=e=>{
 if(e.button!==0)return;
 if(editor.connectionFrom){
   const target=e.target.closest(".element");
   if(target){
     const id=Number(target.dataset.id);
     if(id!==editor.connectionFrom){
       const to=findElement(id);
       if(to.type!=="skip"){
         editor.connections.push({
           id:uid(),
           from:editor.connectionFrom,
           to:id,
           fromSide:"right",
           toSide:"left",
           dash:"実線",
           shape:"直線",
           endArrow:false
         });
         markDirty();
         toast("接続線を作成しました");
       }
     }
   }
   editor.connectionFrom=null;
   draw();
   return;
 }
 if(e.target===$("videoFrame")||e.target===$("fakeVideo")){
   editor.selected=[];
   editor.selectedConnection=null;
   draw();
 }
};

function openConnectionMenu(x,y){
 const c=editor.connections.find(x=>x.id===editor.selectedConnection);
 if(!c)return;

 openMenu(x,y,m=>{
   menuTitle(m,"接続線の操作");
   menuChoice(m,"線種",LINE_TYPES,c.dash,v=>{c.dash=v;markDirty();draw()});
   menuChoice(m,"線形",SHAPES,c.shape,v=>{c.shape=v;markDirty();draw()});
   menuChoice(m,"接続元",["上","右","下","左"],sideName(c.fromSide),v=>{
     c.fromSide=sideValue(v);markDirty();draw();
   });
   menuChoice(m,"接続先",["上","右","下","左"],sideName(c.toSide),v=>{
     c.toSide=sideValue(v);markDirty();draw();
   });
   menuCheck(m,"終端を矢印にする",c.endArrow,v=>{c.endArrow=v;markDirty();draw()});
   menuButton(m,"接続線を削除",()=>{
     editor.connections=editor.connections.filter(x=>x.id!==c.id);
     editor.selectedConnection=null;
     markDirty();draw();
   },"danger");
 });
}

function sideName(v){
 return v==="top"?"上":v==="right"?"右":v==="bottom"?"下":"左";
}
function sideValue(v){
 return v==="上"?"top":v==="右"?"right":v==="下"?"bottom":"left";
}

function openBasicMenu(x,y){
 openMenu(x,y,m=>{
   menuTitle(m,"要素を追加");
   menuButton(m,"コメントを追加",()=>addElement("comment"));
   menuButton(m,"強調枠を追加",()=>addElement("highlight"));
   menuButton(m,"拡大枠を追加",()=>addElement("zoom"));
   menuButton(m,"スキップを追加",()=>addElement("skip"));
   const hr=document.createElement("hr");
   m.appendChild(hr);
   menuButton(m,"選択を解除",()=>{editor.selected=[];editor.selectedConnection=null;draw()});
 });
}

function addElement(type){
 const s=clamp(editor.position,0,editor.duration-MIN_TIME);
 const el={
   id:uid(),type,s,e:Math.min(editor.duration,s+DEFAULT_TIME),
   x:.32,y:.30,w:.30,h:.20,a:{}
 };
 if(type==="comment")el.a={text:"コメント",line:true,lw:2,lc:"#2563eb",tc:"#000",bg:"#fff",fill:true,fs:16,font:"sans-serif"};
 if(type==="highlight")el.a={lw:4,lc:"#dc2626",fill:false,dash:"実線",shape:"直線"};
 if(type==="zoom")el.a={scale:1.5};
 if(type==="skip"){el.x=0;el.y=0;el.w=0;el.h=0;el.a={}}
 editor.elements.push(el);
 editor.selected=[el.id];
 markDirty();
 draw();
 toast(TYPES[type].name+"を追加しました");
}

$("videoFrame").oncontextmenu=e=>{
 e.preventDefault();
 const el=e.target.closest(".element");
 if(el){
   const x=findElement(Number(el.dataset.id));
   editor.selected=[x.id];
   editor.selectedConnection=null;
   openElementMenu(e.clientX,e.clientY,x);
 }else if(editor.selectedConnection){
   openConnectionMenu(e.clientX,e.clientY);
 }else{
   openBasicMenu(e.clientX,e.clientY);
 }
};

$("timeline").oncontextmenu=e=>{
 e.preventDefault();
 const bar=e.target.closest(".timeline-bar");
 if(bar){
   const el=findElement(Number(bar.dataset.id));
   editor.selected=[el.id];
   editor.selectedConnection=null;
   openElementMenu(e.clientX,e.clientY,el);
 }else{
   openBasicMenu(e.clientX,e.clientY);
 }
};

$("addComment").onclick=()=>addElement("comment");
$("addHighlight").onclick=()=>addElement("highlight");
$("addZoom").onclick=()=>addElement("zoom");
$("addSkip").onclick=()=>addElement("skip");

document.addEventListener("pointerdown",e=>{
 if(!e.target.closest("#menu"))closeMenu();
});

document.addEventListener("keydown",e=>{
 if(e.key==="Escape"){
   editor.connectionFrom=null;
   closeMenu();
   closeModal();
   if(editor)draw();
 }
});

function saveCurrent(asNew){
 if(asNew||!editor.work){
   if(works.length>=LIMIT){
     showModal("編集作業の上限",
       "<p>編集作業は10件までです。</p><p>既存の編集作業を削除してから保存してください。</p>",
       [{text:"閉じる"}]);
     return;
   }
   showModal("編集作業を保存",
     '<label>編集作業名<input class="modal-input" id="saveName" value="'+esc(editor.work?editor.work.name:editor.video.name+" の編集作業")+'"></label>',
     [{text:"キャンセル"},{text:"保存する",class:"primary",action:()=>{
       const name=$("saveName").value.trim()||"新しい編集作業";
       const w={
         id:uid(),
         videoId:editor.video.id,
         name,
         saved:nowText(),
         data:{elements:clone(editor.elements),connections:clone(editor.connections)}
       };
       works.push(w);
       editor.work=w;
       $("editorWork").textContent=w.name;
       clearDirty();
       toast("編集作業を保存しました");
     }}]);
 }else{
   editor.work.saved=nowText();
   editor.work.data={elements:clone(editor.elements),connections:clone(editor.connections)};
   clearDirty();
   toast("編集作業を保存しました");
 }
}

$("save").onclick=()=>saveCurrent(false);
$("saveAs").onclick=()=>saveCurrent(true);

$("finish").onclick=()=>{
 if(!editor.dirty){
   closeEditor();
   return;
 }
 showModal("未保存の変更があります",
   "<p>編集内容を保存してから終了しますか？</p>",
   [
    {text:"編集に戻る"},
    {text:"保存せず終了",action:()=>closeEditor()},
    {text:"保存して終了",class:"primary",action:()=>{
      if(editor.work){
        saveCurrent(false);
        setTimeout(closeEditor,50);
      }else{
        saveCurrent(false);
        setTimeout(closeEditor,100);
      }
    }}
   ]);
};

function closeEditor(){
 stopPlayback();
 closeMenu();
 if(realVideoUrl){URL.revokeObjectURL(realVideoUrl);realVideoUrl=null}
 $("editor").style.display="none";
 $("home").style.display="grid";
 editor=null;
 renderHome();
}

$("export").onclick=()=>{
 if(results.length>=LIMIT){
   showModal("編集結果動画の上限",
     "<p>編集結果動画は10件までです。</p><p>既存の編集結果動画を削除してください。</p>",
     [{text:"閉じる"}]);
   return;
 }

 const defaultName=editor.video.name.replace(/\.[^.]+$/,"")+" 編集結果";
 if(editor.dirty){
   showModal("未保存の変更があります",
     "<p>現在の編集内容を保存してから動画を作成しますか？</p>",
     [
      {text:"キャンセル"},
      {text:"保存せず作成",action:()=>exportDialog(defaultName)},
      {text:"保存して作成",class:"primary",action:()=>{
        if(editor.work){
          saveCurrent(false);
          setTimeout(()=>exportDialog(defaultName),50);
        }else{
          saveCurrent(false);
          setTimeout(()=>exportDialog(defaultName),100);
        }
      }}
     ]);
 }else{
   exportDialog(defaultName);
 }
};

function exportDialog(defaultName){
 showModal("編集結果を動画にする",
   '<label>動画名<input class="modal-input" id="resultName" value="'+esc(defaultName)+'"></label>'+
   '<p class="muted">編集結果として別の動画を作成します。元動画と編集作業はそのまま残ります。</p>',
   [{text:"キャンセル"},{text:"作成する",class:"primary",action:()=>{
     createResult($("resultName").value.trim()||defaultName);
   }}]);
}

function createResult(name){
 showModal("編集結果を作成中",
   "<p>編集内容を動画に反映しています。</p><div class='progress'><div id='progressBar'></div></div>",
   []);
 let p=0;
 const t=setInterval(()=>{
   p+=10;
   const bar=$("progressBar");
   if(bar)bar.style.width=p+"%";
   if(p>=100){
     clearInterval(t);
     closeModal();
     results.push({
       id:uid(),
       name,
       videoId:editor.video.id,
       source:editor.video.name,
       created:nowText()
     });
     toast("編集結果動画を作成しました");
   }
 },80);
}

function playResult(id){
 const r=findResult(id);
 showModal("編集結果動画",
   "<div style='background:#111;color:#fff;height:260px;display:flex;align-items:center;justify-content:center;text-align:center'>"+
   "▶ 再生中のモック<br><br>"+esc(r.name)+"</div>"+
   "<p>元動画："+esc(r.source)+"</p>",
   [{text:"閉じる"}]);
}

function editResult(id){
 const r=findResult(id);
 showModal("編集結果動画を編集対象にする",
   "<p>「"+esc(r.name)+"」を新しい動画として扱います。</p>"+
   "<p class='muted'>元の動画・編集作業・編集結果動画は変更しません。</p>",
   [{text:"キャンセル"},{text:"編集対象にする",class:"primary",action:()=>{
     if(videos.length>=LIMIT){
       toast("オリジナル動画が10件に達しています");
       return;
     }
     const v={
       id:uid(),
       name:r.name+".mp4",
       duration:60,
       imported:nowText()
     };
     videos.push(v);
     renderHome();
     startEditor(v.id,null);
   }}]);
}

function deleteResult(id){
 const r=findResult(id);
 showModal("編集結果動画を削除",
   "<p>「"+esc(r.name)+"」を削除します。</p>",
   [{text:"キャンセル"},{text:"削除する",class:"danger",action:()=>{
     results=results.filter(x=>x.id!==id);
     renderHome();
   }}]);
}

$("home").addEventListener("click",e=>{
 if(e.target===e.currentTarget){
   // 初期画面の空白クリックでは何もしない
 }
});

window.addEventListener("resize",()=>{if(editor)draw()});

renderHome();
</script>
</body>
</html>

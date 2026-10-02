<?php ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>録画動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;height:100%;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;background:#f3f4f6;color:#222}
body{overflow:auto}
button,input,select{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:default}
.app{min-height:100vh}
.hidden{display:none!important}
.wrap{max-width:1180px;margin:0 auto;padding:20px}
.card{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:16px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 6px}
h2{font-size:17px;margin:0 0 10px}
h3{font-size:14px;margin:14px 0 7px}
p{margin:7px 0}
.muted{color:#6b7280;font-size:13px}
.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sp{flex:1}
.btn{border:1px solid #9ca3af;background:#fff;border-radius:5px;padding:7px 11px}
.btn:hover{background:#f3f4f6}
.primary{background:#2563eb;color:#fff;border-color:#1d4ed8}
.primary:hover{background:#1d4ed8}
.danger{color:#b91c1c;border-color:#fca5a5}
.danger:hover{background:#fef2f2}
.small{padding:4px 8px;font-size:12px}
.badge{display:inline-block;border-radius:999px;padding:3px 8px;font-size:11px;background:#e5e7eb;color:#374151}
.badge.blue{background:#dbeafe;color:#1d4ed8}
.badge.green{background:#dcfce7;color:#166534}
.badge.orange{background:#ffedd5;color:#9a3412}

.video-list{display:flex;flex-direction:column;gap:10px}
.video-card{border:1px solid #d1d5db;border-radius:7px;overflow:hidden}
.video-head{padding:10px 12px;background:#eff6ff;display:flex;align-items:center;gap:8px}
.work-list{padding:5px 12px 10px}
.work-item{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px dashed #d1d5db}
.work-item:last-child{border-bottom:0}
.empty{color:#6b7280;padding:10px 0;font-style:italic}
.result-item{display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px dashed #d1d5db}
.result-item:last-child{border-bottom:0}

.editor{height:100vh;display:flex;flex-direction:column;overflow:hidden;background:#111827;color:#e5e7eb}
.editor-head{height:54px;flex:none;background:#1f2937;border-bottom:1px solid #374151;padding:7px 12px;display:flex;align-items:center;gap:8px}
.editor-title{font-weight:700;white-space:nowrap}
.editor-head .btn{background:#374151;color:#fff;border-color:#4b5563}
.editor-head .primary{background:#2563eb;border-color:#2563eb}
.dirty{color:#fbbf24;font-size:12px}

.video-stage{min-height:0;flex:1;background:#030712;display:flex;align-items:center;justify-content:center;padding:12px}
.video-box{position:relative;width:min(1050px,100%);height:100%;background:#000;border:1px solid #374151;overflow:hidden}
.video-box video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain}
.video-placeholder{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#6b7280;font-size:22px}
.overlay{position:absolute;inset:0}
.element{position:absolute;min-width:50px;min-height:30px;cursor:move;user-select:none;touch-action:none}
.element.selected{outline:2px solid #fbbf24;outline-offset:2px}
.element-body{width:100%;height:100%;display:flex;align-items:center;justify-content:center;overflow:hidden}
.comment-body{padding:4px;text-align:center}
.highlight-body{width:100%;height:100%}
.zoom-body{width:100%;height:100%;border:2px solid #059669;background:#05966922}
.resize-handle{position:absolute;right:-5px;bottom:-5px;width:11px;height:11px;background:#fff;border:2px solid #2563eb;cursor:nwse-resize}
.skip-overlay{position:absolute;left:0;right:0;bottom:0;background:#000a;color:#fbbf24;text-align:center;padding:5px;font-size:12px;pointer-events:none}

.timeline{height:310px;flex:none;background:#111827;border-top:1px solid #374151;padding:8px 12px}
.timeline-tools{height:40px;display:flex;align-items:center;gap:7px}
.timeline-tools .btn{background:#374151;color:#fff;border-color:#4b5563}
.timebox{font-variant-numeric:tabular-nums;color:#dbeafe;min-width:170px}
.range-info{font-size:12px;color:#9ca3af}
.timeline-scroll{height:235px;overflow:auto}
.timeline-inner{position:relative;min-width:700px}
.ruler{height:30px;margin-left:95px;position:relative;border-bottom:1px solid #4b5563;background:#1f2937}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #4b5563;color:#9ca3af;font-size:10px;padding-left:3px}
.lanes{margin-left:95px}
.lane{height:42px;position:relative;border-bottom:1px solid #283342;background:#151e2b}
.lane-label{position:absolute;right:100%;top:0;width:95px;height:42px;background:#1f2937;border-right:1px solid #374151;padding:12px 7px;font-size:11px}
.lane-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px}
.timeline-bar{position:absolute;top:8px;height:26px;border-radius:4px;color:#fff;font-size:10px;padding:5px 7px;overflow:hidden;white-space:nowrap;cursor:grab}
.timeline-bar.selected{outline:2px solid #fbbf24}
.playhead{position:absolute;top:30px;bottom:0;width:2px;background:#ef4444;z-index:30;pointer-events:none}
.playhead:before{content:"";position:absolute;top:-3px;left:-5px;width:12px;height:12px;background:#ef4444;clip-path:polygon(0 0,100% 0,50% 100%)}

.menu{position:fixed;z-index:1000;min-width:230px;max-width:300px;background:#fff;color:#111827;border:1px solid #9ca3af;border-radius:7px;box-shadow:0 10px 30px #0005;padding:6px}
.menu-title{font-size:11px;color:#6b7280;padding:5px 7px}
.menu button{display:block;width:100%;text-align:left;background:#fff;border:0;border-radius:4px;padding:7px}
.menu button:hover{background:#eff6ff}
.menu hr{border:0;border-top:1px solid #e5e7eb;margin:5px 0}
.menu label{display:flex;align-items:center;gap:6px;margin:5px 2px;font-size:12px}
.menu input[type=text],.menu input[type=number],.menu select{width:100%;padding:5px;border:1px solid #d1d5db;border-radius:4px}
.swatches{display:flex;gap:3px;flex-wrap:wrap;padding:2px}
.swatch{width:20px;height:20px;border:1px solid #9ca3af;border-radius:3px;padding:0!important}
.swatch.on{outline:2px solid #2563eb}
.choices{display:flex;gap:3px;flex-wrap:wrap}
.choices button{width:auto;border:1px solid #d1d5db;padding:3px 6px;text-align:center}
.choices button.on{background:#dbeafe;border-color:#2563eb}

.modal-mask{position:fixed;inset:0;background:#0008;z-index:2000;display:flex;align-items:center;justify-content:center}
.modal{background:#fff;color:#111827;border-radius:8px;width:min(520px,92vw);padding:18px;box-shadow:0 20px 60px #0008}
.modal h3{margin-top:0;font-size:17px}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
.modal-content{max-height:60vh;overflow:auto}
.modal input[type=text],.modal select{padding:7px;border:1px solid #9ca3af;border-radius:5px;width:100%}
.modal label.block{display:block;margin:8px 0}
.toast{position:fixed;right:18px;bottom:18px;z-index:3000;background:#1f2937;color:#fff;border-radius:6px;padding:10px 14px;box-shadow:0 5px 20px #0005}

@media(max-width:800px){
 .wrap{padding:10px}
 .editor-head{height:auto;min-height:54px}
 .editor-title{display:none}
 .video-stage{padding:5px}
 .timeline{height:285px}
 .timebox{min-width:130px}
}
</style>
</head>
<body>

<div id="home" class="wrap">
  <div class="card">
    <div class="row">
      <div>
        <h1>録画動画を編集する</h1>
        <div class="muted">オリジナル動画を選択して編集作業を開始します。</div>
      </div>
      <div class="sp"></div>
      <button class="btn primary" id="addVideoBtn">動画を追加</button>
      <input id="videoFile" type="file" accept="video/*" hidden>
    </div>
  </div>

  <div class="card">
    <h2>オリジナル動画と編集作業 <span class="muted" id="videoCount"></span></h2>
    <div class="video-list" id="videoList"></div>
  </div>

  <div class="card">
    <h2>編集結果動画 <span class="muted" id="resultCount"></span></h2>
    <div id="resultList"></div>
  </div>
</div>

<div id="editor" class="editor hidden">
  <header class="editor-head">
    <div class="editor-title">動画編集</div>
    <div>動画：<b id="editorVideoName"></b></div>
    <div>編集作業：<b id="editorWorkName"></b></div>
    <span class="dirty hidden" id="dirtyMark">● 未保存の変更あり</span>
    <div class="sp"></div>
    <button class="btn" id="saveBtn">保存</button>
    <button class="btn" id="saveAsBtn">別の編集作業として保存</button>
    <button class="btn primary" id="exportBtn">編集結果を動画にする</button>
    <button class="btn" id="endBtn">編集作業を終了する</button>
  </header>

  <section class="video-stage">
    <div class="video-box" id="videoBox">
      <video id="editorVideo" playsinline preload="metadata"></video>
      <div class="video-placeholder" id="videoPlaceholder">動画を選択してください</div>
      <div class="overlay" id="overlay"></div>
    </div>
  </section>

  <section class="timeline">
    <div class="timeline-tools">
      <button class="btn" id="playBtn">▶ 再生</button>
      <button class="btn" id="pauseBtn">⏸ 一時停止</button>
      <button class="btn" id="stopBtn">■ 停止</button>
      <span class="timebox" id="timeBox">00:00.00 / 00:00.00</span>
      <span class="sp"></span>
      <button class="btn" id="zoomOutBtn">−</button>
      <input id="zoomRange" type="range" min="0" max="100" value="0" style="width:150px">
      <button class="btn" id="zoomInBtn">＋</button>
      <button class="btn" id="fitBtn">全体表示</button>
    </div>

    <div class="timeline-tools">
      <span class="range-info" id="rangeInfo"></span>
    </div>

    <div class="timeline-scroll" id="timelineScroll">
      <div class="timeline-inner" id="timelineInner">
        <div class="ruler" id="ruler"></div>
        <div class="lanes" id="lanes"></div>
        <div class="playhead" id="playhead"></div>
      </div>
    </div>
  </section>
</div>

<div id="menuRoot"></div>
<div id="modalRoot"></div>
<div id="toast" class="toast hidden"></div>

<script>
(function(){
"use strict";

var LIMIT=10;
var MIN_LENGTH=.5;
var DEFAULT_LENGTH=5;
var seq=1;
var COLORS=["#000000","#ffffff","#dc2626","#ea580c","#eab308","#16a34a","#059669","#0891b2","#2563eb","#7c3aed","#db2777","#6b7280"];
var FONTS=["sans-serif","serif","monospace","cursive"];
var LINE_WIDTHS=[1,2,4,6];
var DASHES={"実線":"","点線":"2 4","破線":"8 4","一点鎖線":"10 4 2 4"};
var TYPES={
  comment:{name:"コメント",color:"#2563eb"},
  highlight:{name:"強調枠",color:"#dc2626"},
  zoom:{name:"拡大枠",color:"#059669"},
  skip:{name:"スキップ",color:"#d97706"}
};

var videos=[
  {id:"v1",name:"操作手順_顧客登録.mp4",duration:120,created:"2026/09/20 10:00",url:null},
  {id:"v2",name:"障害再現_決済画面.mp4",duration:75,created:"2026/09/25 14:30",url:null}
];

var works=[
  {id:"w1",videoId:"v1",name:"顧客登録_注釈入り",saved:"2026/09/21 11:00",snapshot:null},
  {id:"w2",videoId:"v1",name:"顧客登録_短縮版",saved:"2026/09/22 16:45",snapshot:null}
];

var results=[
  {id:"r1",name:"顧客登録_完成版",sourceVideo:"操作手順_顧客登録.mp4",created:"2026/09/23 09:00"}
];

var editorState=null;
var drag=null;
var objectUrls=[];

function $(id){return document.getElementById(id)}
function uid(prefix){return prefix+(seq++)}
function clamp(v,min,max){return Math.max(min,Math.min(max,v))}
function find(arr,id){return arr.find(function(x){return x.id===id})}
function escapeHtml(s){
  return String(s).replace(/[&<>"']/g,function(c){
    return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
  });
}
function timeText(t){
  t=Math.max(0,Number(t)||0);
  var m=Math.floor(t/60),s=t-m*60;
  return String(m).padStart(2,"0")+":"+s.toFixed(2).padStart(5,"0");
}
function toast(message){
  $("toast").textContent=message;
  $("toast").classList.remove("hidden");
  clearTimeout(toast.timer);
  toast.timer=setTimeout(function(){$("toast").classList.add("hidden")},1800);
}
function modal(title,html,buttons){
  var root=$("modalRoot");
  root.innerHTML='<div class="modal-mask"><div class="modal"><h3>'+title+'</h3><div class="modal-content">'+html+'</div><div class="modal-actions" id="modalActions"></div></div></div>';
  buttons.forEach(function(b){
    var btn=document.createElement("button");
    btn.className="btn "+(b.primary?"primary ":"")+(b.danger?"danger ":"");
    btn.textContent=b.text;
    btn.onclick=function(){
      if(b.close!==false)root.innerHTML="";
      if(b.action)b.action();
    };
    $("modalActions").appendChild(btn);
  });
}
function confirmModal(message,action){
  modal("確認","<p>"+message+"</p>",[
    {text:"キャンセル"},
    {text:"実行する",danger:true,action:action}
  ]);
}
function closeMenu(){$("menuRoot").innerHTML=""}

function renderHome(){
  $("videoCount").textContent="（"+videos.length+"/"+LIMIT+"件）";
  $("resultCount").textContent="（"+results.length+"/"+LIMIT+"件）";

  var html="";
  videos.forEach(function(v){
    var ws=works.filter(function(w){return w.videoId===v.id});
    html+='<div class="video-card">';
    html+='<div class="video-head">';
    html+='<b>'+escapeHtml(v.name)+'</b>';
    html+='<span class="badge blue">'+timeText(v.duration)+'</span>';
    html+='<span class="muted">取り込み：'+escapeHtml(v.created)+'</span>';
    html+='<span class="sp"></span>';
    html+='<button class="btn primary small" data-action="new" data-id="'+v.id+'">新しい編集作業</button>';
    html+='<button class="btn danger small" data-action="delete-video" data-id="'+v.id+'">削除</button>';
    html+='</div>';
    html+='<div class="work-list">';
    if(!ws.length){
      html+='<div class="empty">編集作業はありません</div>';
    }else{
      ws.forEach(function(w){
        html+='<div class="work-item">';
        html+='<b>'+escapeHtml(w.name)+'</b>';
        html+='<span class="muted">最終保存：'+escapeHtml(w.saved)+'</span>';
        html+='<span class="sp"></span>';
        html+='<button class="btn small" data-action="resume" data-id="'+w.id+'">再開</button>';
        html+='<button class="btn small" data-action="duplicate" data-id="'+w.id+'">複製</button>';
        html+='<button class="btn danger small" data-action="delete-work" data-id="'+w.id+'">削除</button>';
        html+='</div>';
      });
    }
    html+='</div></div>';
  });

  if(!videos.length)html='<div class="empty">オリジナル動画はありません。</div>';
  $("videoList").innerHTML=html;

  var rh="";
  if(!results.length){
    rh='<div class="empty">編集結果動画はありません。</div>';
  }else{
    results.forEach(function(r){
      rh+='<div class="result-item">';
      rh+='<b>'+escapeHtml(r.name)+'</b>';
      rh+='<span class="badge green">編集結果</span>';
      rh+='<span class="muted">元：'+escapeHtml(r.sourceVideo)+' ／ 作成：'+escapeHtml(r.created)+'</span>';
      rh+='<span class="sp"></span>';
      rh+='<button class="btn small" data-action="use-result" data-id="'+r.id+'">編集対象にする</button>';
      rh+='<button class="btn danger small" data-action="delete-result" data-id="'+r.id+'">削除</button>';
      rh+='</div>';
    });
  }
  $("resultList").innerHTML=rh;
}

$("videoList").onclick=function(e){
  var b=e.target.closest("button[data-action]");
  if(!b)return;
  var action=b.dataset.action,id=b.dataset.id;

  if(action==="new"){
    startEditor(id,null);
  }
  if(action==="resume"){
    var w=find(works,id);
    startEditor(w.videoId,w);
  }
  if(action==="duplicate"){
    duplicateWork(id);
  }
  if(action==="delete-work"){
    var dw=find(works,id);
    confirmModal("編集作業「"+escapeHtml(dw.name)+"」を削除します。",function(){
      works=works.filter(function(x){return x.id!==id});
      renderHome();
      toast("編集作業を削除しました");
    });
  }
  if(action==="delete-video"){
    deleteVideo(id);
  }
};

$("resultList").onclick=function(e){
  var b=e.target.closest("button[data-action]");
  if(!b)return;
  var action=b.dataset.action,id=b.dataset.id;
  if(action==="delete-result"){
    confirmModal("この編集結果動画を削除します。",function(){
      results=results.filter(function(x){return x.id!==id});
      renderHome();
      toast("編集結果動画を削除しました");
    });
  }
  if(action==="use-result"){
    var r=find(results,id);
    modal("編集結果動画を編集対象にする",
      "<p>「"+escapeHtml(r.name)+"」を新しいオリジナル動画として扱います。</p>"+
      "<p class='muted'>元のオリジナル動画や編集作業は変更されません。</p>",
      [
        {text:"キャンセル"},
        {text:"編集対象にする",primary:true,action:function(){
          var v={id:uid("v"),name:r.name,duration:60,created:new Date().toLocaleString("ja-JP"),url:null};
          if(videos.length>=LIMIT){
            modal("オリジナル動画の上限",
              "<p>オリジナル動画は"+LIMIT+"件までです。既存動画を削除してから追加してください。</p>",
              [{text:"閉じる"}]);
            return;
          }
          videos.push(v);
          renderHome();
          startEditor(v.id,null);
        }}
      ]);
  }
};

function deleteVideo(id){
  var v=find(videos,id);
  var ws=works.filter(function(w){return w.videoId===id});
  confirmModal(
    "動画「"+escapeHtml(v.name)+"」を削除します。"+
    (ws.length?"<br><br><b>関連する編集作業"+ws.length+"件も削除されます。</b>":"")+
    "<br><br>既に作成した編集結果動画は削除しません。",
    function(){
      videos=videos.filter(function(x){return x.id!==id});
      works=works.filter(function(x){return x.videoId!==id});
      renderHome();
      toast("動画を削除しました");
    }
  );
}

function duplicateWork(id){
  var w=find(works,id);
  if(works.length>=LIMIT){
    modal("編集作業の上限","<p>編集作業は"+LIMIT+"件までです。既存の編集作業を削除してください。</p>",[{text:"閉じる"}]);
    return;
  }
  modal("編集作業を複製",
    '<label class="block">新しい編集作業名<input id="dupName" type="text" value="'+escapeHtml(w.name)+"_コピー"+'"></label>',
    [
      {text:"キャンセル"},
      {text:"複製する",primary:true,action:function(){
        var name=$("dupName").value.trim()||w.name+"_コピー";
        works.push({
          id:uid("w"),
          videoId:w.videoId,
          name:name,
          saved:new Date().toLocaleString("ja-JP"),
          snapshot:w.snapshot?clone(w.snapshot):null
        });
        renderHome();
        toast("編集作業を複製しました");
      }}
    ]);
}

$("addVideoBtn").onclick=function(){
  $("videoFile").value="";
  $("videoFile").click();
};

$("videoFile").onchange=function(){
  var file=this.files[0];
  if(!file)return;
  var url=URL.createObjectURL(file);
  objectUrls.push(url);
  var v=document.createElement("video");
  v.preload="metadata";
  v.onloadedmetadata=function(){
    var item={
      id:uid("v"),
      name:file.name,
      duration:Number.isFinite(v.duration)?v.duration:60,
      created:new Date().toLocaleString("ja-JP"),
      url:url
    };
    if(videos.length>=LIMIT){
      modal("オリジナル動画の上限",
        "<p>オリジナル動画は"+LIMIT+"件までです。</p>"+
        videos.map(function(x){
          return '<label class="block"><input type="radio" name="replaceVideo" value="'+x.id+'"> '+escapeHtml(x.name)+'</label>';
        }).join(""),
        [
          {text:"キャンセル"},
          {text:"削除して追加",danger:true,action:function(){
            var radio=document.querySelector('input[name="replaceVideo"]:checked');
            if(!radio){toast("削除する動画を選択してください");return}
            var old=radio.value;
            videos=videos.filter(function(x){return x.id!==old});
            works=works.filter(function(x){return x.videoId!==old});
            videos.push(item);
            renderHome();
            startEditor(item.id,null);
          }}
        ]);
    }else{
      videos.push(item);
      renderHome();
      startEditor(item.id,null);
    }
  };
  v.onerror=function(){
    modal("読み込みエラー","<p>この動画を読み込めませんでした。</p>",[{text:"閉じる"}]);
  };
  v.src=url;
};

function clone(obj){
  return JSON.parse(JSON.stringify(obj));
}

function defaultElements(duration){
  return [
    {
      id:uid("e"),type:"comment",s:duration*.05,e:Math.min(duration,duration*.2),
      x:.10,y:.10,w:.30,h:.15,
      a:{text:"ここを確認してください",line:true,lw:2,lc:"#2563eb",tc:"#000000",bg:"#ffffff",fill:true,fs:16,font:"sans-serif"}
    },
    {
      id:uid("e"),type:"highlight",s:duration*.15,e:Math.min(duration,duration*.4),
      x:.50,y:.30,w:.30,h:.30,
      a:{lw:4,lc:"#dc2626",fill:false,dash:"実線",shape:"直線"}
    },
    {
      id:uid("e"),type:"zoom",s:duration*.45,e:Math.min(duration,duration*.60),
      x:.18,y:.52,w:.25,h:.20,a:{scale:1.5}
    },
    {
      id:uid("e"),type:"skip",s:Math.min(duration*.7,duration-MIN_LENGTH),
      e:Math.min(duration*.75+MIN_LENGTH,duration),x:0,y:0,w:0,h:0,a:{}
    }
  ];
}

function startEditor(videoId,work){
  var v=find(videos,videoId);
  editorState={
    video:v,
    work:work,
    duration:v.duration,
    position:0,
    viewStart:0,
    viewEnd:v.duration,
    elements:work&&work.snapshot?clone(work.snapshot.elements):defaultElements(v.duration),
    connections:work&&work.snapshot?clone(work.snapshot.connections||[]):[],
    selected:[],
    selectedConnection:null,
    dirty:false,
    timer:null
  };

  $("home").classList.add("hidden");
  $("editor").classList.remove("hidden");
  $("editorVideoName").textContent=v.name;
  $("editorWorkName").textContent=work?work.name:"未保存の編集作業";
  $("dirtyMark").classList.add("hidden");

  var ev=$("editorVideo");
  ev.pause();
  ev.removeAttribute("src");
  ev.load();
  if(v.url){
    ev.src=v.url;
    $("videoPlaceholder").classList.add("hidden");
    ev.onloadedmetadata=function(){
      editorState.duration=Number.isFinite(ev.duration)?ev.duration:v.duration;
      editorState.viewEnd=editorState.duration;
      draw();
    };
  }else{
    $("videoPlaceholder").classList.remove("hidden");
  }
  draw();
}

function setDirty(value){
  editorState.dirty=value;
  $("dirtyMark").classList.toggle("hidden",!value);
}

function changed(){
  setDirty(true);
  draw();
}

function stopPlayback(){
  if(editorState.timer){
    clearInterval(editorState.timer);
    editorState.timer=null;
  }
  $("editorVideo").pause();
  $("playBtn").textContent="▶ 再生";
}

function setPosition(t){
  var e=editorState;
  e.position=clamp(t,0,e.duration);
  var skip=e.elements.filter(function(x){return x.type==="skip"});
  skip.forEach(function(s){
    if(e.position>=s.s&&e.position<s.e)e.position=s.e;
  });
  var video=$("editorVideo");
  if(video.src){
    try{video.currentTime=e.position}catch(err){}
  }
}

$("playBtn").onclick=function(){
  var e=editorState;
  if(e.timer)return;
  if(e.position>=e.duration)e.position=0;
  var last=performance.now();
  if($("editorVideo").src){
    try{$("editorVideo").currentTime=e.position;$("editorVideo").play()}catch(err){}
  }
  $("playBtn").textContent="❚❚ 再生中";
  e.timer=setInterval(function(){
    var now=performance.now();
    var dt=(now-last)/1000;
    last=now;
    if($("editorVideo").src){
      e.position=$("editorVideo").currentTime;
    }else{
      e.position=Math.min(e.duration,e.position+dt);
    }
    var skip=e.elements.find(function(x){return x.type==="skip"&&e.position>=x.s&&e.position<x.e});
    if(skip){
      e.position=skip.e;
      if($("editorVideo").src){
        try{$("editorVideo").currentTime=skip.e}catch(err){}
      }
    }
    if(e.position>=e.duration){
      e.position=e.duration;
      stopPlayback();
    }
    draw();
  },50);
};

$("pauseBtn").onclick=stopPlayback;
$("stopBtn").onclick=function(){stopPlayback();setPosition(0);draw()};

$("editorVideo").addEventListener("timeupdate",function(){
  if(!editorState)return;
  editorState.position=this.currentTime;
  draw();
});

$("editorVideo").addEventListener("ended",function(){
  if(editorState){
    editorState.position=editorState.duration;
    stopPlayback();
    draw();
  }
});

function timelineWidth(){
  var base=Math.max(720,$("timelineScroll").clientWidth-20);
  var scale=1+(editorState?getScale():0);
  return base*scale;
}
function getScale(){
  var v=Number($("zoomRange").value)||0;
  return v/100*3;
}
function renderRuler(){
  var e=editorState,w=$("timelineScroll").clientWidth-95;
  var width=Math.max(650,w*(1+getScale()));
  $("timelineInner").style.width=(width+95)+"px";
  var span=e.viewEnd-e.viewStart;
  var step=span<=20?2:span<=60?5:span<=180?10:30;
  var h="";
  for(var t=Math.ceil(e.viewStart/step)*step;t<=e.viewEnd+.001;t+=step){
    var x=(t-e.viewStart)/span*width;
    h+='<div class="tick" style="left:'+x+'px">'+timeText(t)+'</div>';
  }
  $("ruler").innerHTML=h;
  return width;
}

function layoutRows(){
  var rows={};
  var ends=[];
  editorState.elements.slice().sort(function(a,b){return a.s-b.s}).forEach(function(el){
    var r=0;
    while(ends[r]>el.s)r++;
    ends[r]=el.e;
    rows[el.id]=r;
  });
  return rows;
}

function draw(){
  if(!editorState)return;
  var e=editorState;
  var width=renderRuler();
  var span=e.viewEnd-e.viewStart;
  var rowMap=layoutRows();
  var lanes={
    comment:"lane-comment",
    highlight:"lane-highlight",
    zoom:"lane-zoom",
    skip:"lane-skip"
  };
  Object.keys(lanes).forEach(function(type){$(lanes[type]).innerHTML=""});

  e.elements.forEach(function(el){
    if(el.e<e.viewStart||el.s>e.viewEnd)return;
    var lane=$(lanes[el.type]);
    var x=Math.max(0,(el.s-e.viewStart)/span*width);
    var x2=Math.min(width,(el.e-e.viewStart)/span*width);
    var bar=document.createElement("div");
    bar.className="timeline-bar"+(e.selected.indexOf(el.id)>=0?" selected":"");
    bar.dataset.id=el.id;
    bar.style.left=x+"px";
    bar.style.width=Math.max(3,x2-x)+"px";
    bar.style.background=TYPES[el.type].color;
    bar.textContent=TYPES[el.type].name+(el.type==="comment"?"："+el.a.text:"");
    lane.appendChild(bar);
  });

  var playX=(e.position-e.viewStart)/span*width;
  $("playhead").style.left=clamp(playX,0,width)+"px";

  $("timeBox").textContent=timeText(e.position)+" / "+timeText(e.duration);
  $("rangeInfo").textContent="表示範囲："+timeText(e.viewStart)+" 〜 "+timeText(e.viewEnd);

  drawVideo();
}

function drawVideo(){
  var e=editorState;
  var overlay=$("overlay");
  overlay.innerHTML="";

  e.elements.forEach(function(el){
    if(el.type==="skip")return;
    if(e.position<el.s||e.position>el.e)return;

    var d=document.createElement("div");
    d.className="element"+(e.selected.indexOf(el.id)>=0?" selected":"");
    d.dataset.id=el.id;
    d.style.left=(el.x*100)+"%";
    d.style.top=(el.y*100)+"%";
    d.style.width=(el.w*100)+"%";
    d.style.height=(el.h*100)+"%";

    var body=document.createElement("div");
    body.className="element-body";

    if(el.type==="comment"){
      body.className="element-body comment-body";
      body.textContent=el.a.text;
      body.style.color=el.a.tc;
      body.style.fontSize=el.a.fs+"px";
      body.style.fontFamily=el.a.font;
      body.style.background=el.a.fill?el.a.bg:"transparent";
      body.style.border=el.a.line?el.a.lw+"px solid "+el.a.lc:"none";
    }

    if(el.type==="highlight"){
      body.className="element-body highlight-body";
      body.style.border=el.a.lw+"px "+(el.a.dash==="実線"?"solid":el.a.dash==="点線"?"dotted":el.a.dash==="破線"?"dashed":"double")+" "+el.a.lc;
      body.style.background=el.a.fill?el.a.lc+"33":"transparent";
      if(el.a.shape==="折れ線")body.style.clipPath="polygon(0 0,100% 0,85% 20%,100% 40%,85% 60%,100% 80%,100% 100%,0 100%)";
      if(el.a.shape==="波線")body.style.borderRadius="30%";
    }

    if(el.type==="zoom"){
      body.className="element-body zoom-body";
      body.innerHTML='<span style="background:#059669;color:#fff;padding:2px 6px;font-size:11px">拡大表示</span>';
    }

    d.appendChild(body);

    if(e.selected.indexOf(el.id)>=0){
      var handle=document.createElement("div");
      handle.className="resize-handle";
      handle.dataset.resize="1";
      d.appendChild(handle);
    }

    d.addEventListener("pointerdown",elementPointerDown);
    d.addEventListener("contextmenu",function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      if(e.selected.indexOf(el.id)<0)e.selected=[el.id];
      e.selectedConnection=null;
      draw();
      showElementMenu(ev.clientX,ev.clientY);
    });

    overlay.appendChild(d);
  });

  drawConnections();

  var activeSkip=e.elements.find(function(x){
    return x.type==="skip"&&e.position>=x.s&&e.position<x.e;
  });
  if(activeSkip){
    var s=document.createElement("div");
    s.className="skip-overlay";
    s.textContent="スキップ範囲";
    overlay.appendChild(s);
  }
}

function elementPointerDown(ev){
  if(ev.button!==0)return;
  var id=ev.currentTarget.dataset.id;
  var el=find(editorState.elements,id);
  if(!el)return;

  ev.stopPropagation();
  closeMenu();

  var multi=ev.shiftKey;
  if(multi){
    if(editorState.selected.indexOf(id)>=0){
      editorState.selected=editorState.selected.filter(function(x){return x!==id});
    }else{
      editorState.selected.push(id);
    }
  }else if(editorState.selected.indexOf(id)<0){
    editorState.selected=[id];
  }
  editorState.selectedConnection=null;
  draw();

  var rect=$("videoBox").getBoundingClientRect();
  var startX=ev.clientX,startY=ev.clientY;
  var resize=!!ev.target.dataset.resize;
  var originals=editorState.selected.map(function(sel){
    var x=find(editorState.elements,sel);
    return {el:x,x:x.x,y:x.y,w:x.w,h:x.h};
  });

  function move(e2){
    var dx=(e2.clientX-startX)/rect.width;
    var dy=(e2.clientY-startY)/rect.height;
    originals.forEach(function(o){
      if(resize&&editorState.selected.length===1){
        o.el.w=clamp(o.w+dx,.05,1-o.el.x);
        o.el.h=clamp(o.h+dy,.05,1-o.el.y);
      }else if(!resize){
        o.el.x=clamp(o.x+dx,0,1-o.el.w);
        o.el.y=clamp(o.y+dy,0,1-o.el.h);
      }
    });
    changed();
  }
  function up(){
    window.removeEventListener("pointermove",move);
    window.removeEventListener("pointerup",up);
  }
  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);
}

$("overlay").addEventListener("click",function(e){
  if(e.target===this){
    editorState.selected=[];
    editorState.selectedConnection=null;
    draw();
  }
});

function connectionPoint(el,side){
  if(side==="top")return{x:el.x+el.w/2,y:el.y};
  if(side==="right")return{x:el.x+el.w,y:el.y+el.h/2};
  if(side==="bottom")return{x:el.x+el.w/2,y:el.y+el.h};
  return{x:el.x,y:el.y+el.h/2};
}

function drawConnections(){
  var e=editorState;
  var svg=document.createElementNS("http://www.w3.org/2000/svg","svg");
  svg.setAttribute("style","position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible");
  var defs=document.createElementNS("http://www.w3.org/2000/svg","defs");
  var marker=document.createElementNS("http://www.w3.org/2000/svg","marker");
  marker.setAttribute("id","arrow");
  marker.setAttribute("markerWidth","8");
  marker.setAttribute("markerHeight","8");
  marker.setAttribute("refX","7");
  marker.setAttribute("refY","4");
  marker.setAttribute("orient","auto");
  var path=document.createElementNS("http://www.w3.org/2000/svg","path");
  path.setAttribute("d","M0,0 L8,4 L0,8 Z");
  path.setAttribute("fill","#2563eb");
  marker.appendChild(path);
  defs.appendChild(marker);
  svg.appendChild(defs);

  e.connections.forEach(function(c){
    var a=find(e.elements,c.from),b=find(e.elements,c.to);
    if(!a||!b)return;
    if(a.type==="skip"||b.type==="skip")return;
    if(e.position<a.s||e.position>a.e||e.position<b.s||e.position>b.e)return;

    var p1=connectionPoint(a,c.fromSide||"right");
    var p2=connectionPoint(b,c.toSide||"left");
    var line=document.createElementNS("http://www.w3.org/2000/svg","line");
    line.setAttribute("x1",p1.x*100+"%");
    line.setAttribute("y1",p1.y*100+"%");
    line.setAttribute("x2",p2.x*100+"%");
    line.setAttribute("y2",p2.y*100+"%");
    line.setAttribute("stroke",e.selectedConnection===c.id?"#2563eb":"#6b7280");
    line.setAttribute("stroke-width",e.selectedConnection===c.id?"3":"2");
    line.setAttribute("pointer-events","none");
    if(c.dash)line.setAttribute("stroke-dasharray",DASHES[c.dash]||"");
    if(c.endArrow)line.setAttribute("marker-end","url(#arrow)");

    var hit=document.createElementNS("http://www.w3.org/2000/svg","line");
    hit.setAttribute("x1",p1.x*100+"%");
    hit.setAttribute("y1",p1.y*100+"%");
    hit.setAttribute("x2",p2.x*100+"%");
    hit.setAttribute("y2",p2.y*100+"%");
    hit.setAttribute("stroke","transparent");
    hit.setAttribute("stroke-width","18");
    hit.setAttribute("pointer-events","stroke");
    hit.style.cursor="pointer";
    hit.dataset.connection=c.id;
    hit.addEventListener("click",function(ev){
      ev.stopPropagation();
      e.selected=[];
      e.selectedConnection=c.id;
      draw();
    });
    hit.addEventListener("contextmenu",function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      e.selected=[];
      e.selectedConnection=c.id;
      showConnectionMenu(ev.clientX,ev.clientY);
    });

    svg.appendChild(line);
    svg.appendChild(hit);
  });
  $("overlay").prepend(svg);
}

function showMenu(x,y,builder){
  closeMenu();
  var m=document.createElement("div");
  m.className="menu";
  builder(m);
  $("menuRoot").appendChild(m);
  var left=Math.min(x,window.innerWidth-m.offsetWidth-8);
  var top=Math.min(y,window.innerHeight-m.offsetHeight-8);
  m.style.left=Math.max(5,left)+"px";
  m.style.top=Math.max(5,top)+"px";
}
function menuTitle(m,text){
  var d=document.createElement("div");
  d.className="menu-title";
  d.textContent=text;
  m.appendChild(d);
}
function menuButton(m,text,fn,cls){
  var b=document.createElement("button");
  b.textContent=text;
  if(cls)b.className=cls;
  b.onclick=function(){closeMenu();fn()};
  m.appendChild(b);
}
function menuCheck(m,text,value,fn){
  var l=document.createElement("label");
  var i=document.createElement("input");
  i.type="checkbox";i.checked=!!value;
  i.onchange=function(){fn(i.checked)};
  l.appendChild(i);
  l.appendChild(document.createTextNode(text));
  m.appendChild(l);
}
function menuChoices(m,title,options,current,fn){
  menuTitle(m,title);
  var d=document.createElement("div");
  d.className="choices";
  options.forEach(function(o){
    var b=document.createElement("button");
    b.textContent=o;
    if(o===current)b.className="on";
    b.onclick=function(){
      fn(o);
      Array.from(d.children).forEach(function(x){x.className=""});
      b.className="on";
    };
    d.appendChild(b);
  });
  m.appendChild(d);
}
function menuColors(m,title,current,fn){
  menuTitle(m,title);
  var d=document.createElement("div");
  d.className="swatches";
  COLORS.forEach(function(c){
    var b=document.createElement("button");
    b.className="swatch"+(c===current?" on":"");
    b.style.background=c;
    b.onclick=function(){
      fn(c);
      Array.from(d.children).forEach(function(x){x.classList.remove("on")});
      b.classList.add("on");
    };
    d.appendChild(b);
  });
  m.appendChild(d);
}
function menuWidth(m,a){
  menuChoices(m,"線の太さ",LINE_WIDTHS.map(String),String(a.lw),function(v){
    a.lw=Number(v);changed();
  });
}
function timeInputs(m,el){
  var l=document.createElement("label");
  l.textContent="開始時間";
  var i=document.createElement("input");
  i.type="number";i.step=".1";i.value=el.s.toFixed(1);
  i.onchange=function(){
    el.s=clamp(Number(i.value)||0,0,el.e-MIN_LENGTH);
    changed();
  };
  l.appendChild(i);m.appendChild(l);

  var l2=document.createElement("label");
  l2.textContent="終了時間";
  var i2=document.createElement("input");
  i2.type="number";i2.step=".1";i2.value=el.e.toFixed(1);
  i2.onchange=function(){
    el.e=clamp(Number(i2.value)||el.s+MIN_LENGTH,el.s+MIN_LENGTH,editorState.duration);
    changed();
  };
  l2.appendChild(i2);m.appendChild(l2);
}

function showElementMenu(x,y){
  var e=editorState;
  if(e.selected.length>1){
    showMenu(x,y,function(m){
      menuTitle(m,"複数選択："+e.selected.length+"件");
      menuButton(m,"まとめて削除",function(){
        confirmModal("選択した要素を削除します。",function(){
          e.elements=e.elements.filter(function(x){return e.selected.indexOf(x.id)<0});
          e.connections=e.connections.filter(function(c){
            return e.elements.some(function(x){return x.id===c.from})&&e.elements.some(function(x){return x.id===c.to});
          });
          e.selected=[];
          changed();
        });
      },"danger");
      menuButton(m,"開始時間を揃える",function(){
        var first=find(e.elements,e.selected[0]);
        e.selected.forEach(function(id){
          var q=find(e.elements,id);
          var len=q.e-q.s;
          q.s=clamp(first.s,0,e.duration-len);
          q.e=q.s+len;
        });
        changed();
      });
    });
    return;
  }

  var el=find(e.elements,e.selected[0]);
  if(!el)return;

  showMenu(x,y,function(m){
    menuTitle(m,TYPES[el.type].name+"の操作");

    if(el.type==="comment"){
      var l=document.createElement("label");
      l.textContent="コメント本文";
      var input=document.createElement("input");
      input.type="text";input.value=el.a.text;
      input.oninput=function(){el.a.text=input.value;changed()};
      l.appendChild(input);m.appendChild(l);

      menuCheck(m,"線あり",el.a.line,function(v){el.a.line=v;changed()});
      menuWidth(m,el.a);
      menuColors(m,"線の色",el.a.lc,function(v){el.a.lc=v;changed()});
      menuColors(m,"文字色",el.a.tc,function(v){el.a.tc=v;changed()});
      menuCheck(m,"塗り潰しあり",el.a.fill,function(v){el.a.fill=v;changed()});
      menuColors(m,"背景色",el.a.bg,function(v){el.a.bg=v;changed()});
      menuChoices(m,"文字サイズ",["12","16","20","24","32","40"],String(el.a.fs),function(v){el.a.fs=Number(v);changed()});
      menuChoices(m,"フォント",FONTS,el.a.font,function(v){el.a.font=v;changed()});
    }

    if(el.type==="highlight"){
      menuWidth(m,el.a);
      menuColors(m,"線の色",el.a.lc,function(v){el.a.lc=v;changed()});
      menuCheck(m,"塗り潰しあり",el.a.fill,function(v){el.a.fill=v;changed()});
      menuChoices(m,"線種",Object.keys(DASH),el.a.dash,function(v){el.a.dash=v;changed()});
      menuChoices(m,"線形",["直線","折れ線","波線"],el.a.shape,function(v){el.a.shape=v;changed()});
    }

    if(el.type==="zoom"){
      timeInputs(m,el);
      menuChoices(m,"拡大率",["1.2","1.5","2.0","2.5"],String(el.a.scale||1.5),function(v){el.a.scale=Number(v);changed()});
    }

    if(el.type==="skip"){
      timeInputs(m,el);
      menuTitle(m,"スキップ");
      var p=document.createElement("div");
      p.className="muted";
      p.textContent="再生時、この範囲を自動的に飛ばします。";
      m.appendChild(p);
    }

    m.appendChild(document.createElement("hr"));

    menuButton(m,"接続線を作成",function(){startConnection(el.id)});
    menuButton(m,"削除",function(){
      confirmModal("この要素を削除します。",function(){
        e.elements=e.elements.filter(function(x){return x.id!==el.id});
        e.connections=e.connections.filter(function(c){return c.from!==el.id&&c.to!==el.id});
        e.selected=[];
        changed();
      });
    },"danger");
  });
}

function startConnection(fromId){
  var candidates=editorState.elements.filter(function(x){
    return x.id!==fromId&&x.type!=="skip";
  });
  if(!candidates.length){toast("接続先にできる要素がありません");return}
  modal("接続先を選択",
    '<p class="muted">接続する要素を選択してください。</p>'+
    candidates.map(function(x){
      return '<label class="block"><input type="radio" name="connectionTarget" value="'+x.id+'"> '+escapeHtml(TYPES[x.type].name)+'</label>';
    }).join(""),
    [
      {text:"キャンセル"},
      {text:"接続する",primary:true,action:function(){
        var target=document.querySelector('input[name="connectionTarget"]:checked');
        if(!target){toast("接続先を選択してください");return}
        var exists=editorState.connections.some(function(c){return c.from===fromId&&c.to===target.value});
        if(exists){toast("すでに接続されています");return}
        editorState.connections.push({
          id:uid("c"),from:fromId,to:target.value,
          fromSide:"right",toSide:"left",dash:"実線",shape:"直線",endArrow:false
        });
        changed();
        toast("接続線を作成しました");
      }}
    ]);
}

function showConnectionMenu(x,y){
  var c=find(editorState.connections,editorState.selectedConnection);
  if(!c)return;
  showMenu(x,y,function(m){
    menuTitle(m,"接続線の操作");
    menuChoices(m,"線種",Object.keys(DASH),c.dash,function(v){c.dash=v;changed()});
    menuChoices(m,"線形",["直線","折れ線","波線"],c.shape,function(v){c.shape=v;changed()});
    menuChoices(m,"始点",["top","right","bottom","left"],c.fromSide,function(v){c.fromSide=v;changed()});
    menuChoices(m,"終点",["top","right","bottom","left"],c.toSide,function(v){c.toSide=v;changed()});
    menuCheck(m,"終端を矢印にする",c.endArrow,function(v){c.endArrow=v;changed()});
    m.appendChild(document.createElement("hr"));
    menuButton(m,"削除",function(){
      editorState.connections=editorState.connections.filter(function(q){return q.id!==c.id});
      editorState.selectedConnection=null;
      changed();
    },"danger");
  });
}

function showBasicMenu(x,y){
  showMenu(x,y,function(m){
    menuTitle(m,"要素を追加");
    Object.keys(TYPES).forEach(function(type){
      menuButton(m,TYPES[type].name+"を追加",function(){addElement(type)});
    });
    m.appendChild(document.createElement("hr"));
    menuButton(m,"動画を選択し直す",function(){
      stopPlayback();
      $("editor").classList.add("hidden");
      $("home").classList.remove("hidden");
      renderHome();
    });
  });
}

function addElement(type){
  var e=editorState;
  var start=clamp(e.position,0,e.duration-MIN_LENGTH);
  var end=Math.min(e.duration,start+DEFAULT_LENGTH);
  var item={
    id:uid("e"),type:type,s:start,e:end,x:.32,y:.28,w:.30,h:.20,a:{}
  };
  if(type==="comment"){
    item.a={text:"コメント",line:true,lw:2,lc:"#2563eb",tc:"#000000",bg:"#ffffff",fill:true,fs:16,font:"sans-serif"};
  }
  if(type==="highlight"){
    item.a={lw:4,lc:"#dc2626",fill:false,dash:"実線",shape:"直線"};
  }
  if(type==="zoom"){
    item.a={scale:1.5};
  }
  if(type==="skip"){
    item.x=0;item.y=0;item.w=0;item.h=0;
    item.a={};
  }
  e.elements.push(item);
  e.selected=[item.id];
  changed();
  toast(TYPES[type].name+"を追加しました");
}

$("videoBox").addEventListener("contextmenu",function(e){
  if(e.target.closest(".element")||e.target.closest("svg"))return;
  e.preventDefault();
  editorState.selected=[];
  editorState.selectedConnection=null;
  draw();
  showBasicMenu(e.clientX,e.clientY);
});

$("timelineScroll").addEventListener("contextmenu",function(e){
  if(e.target.closest(".timeline-bar"))return;
  e.preventDefault();
  editorState.selected=[];
  editorState.selectedConnection=null;
  draw();
  showBasicMenu(e.clientX,e.clientY);
});

$("lanes").addEventListener("pointerdown",function(e){
  var bar=e.target.closest(".timeline-bar");
  if(!bar)return;
  var id=bar.dataset.id,el=find(editorState.elements,id);
  if(!el)return;
  if(e.shiftKey){
    if(editorState.selected.indexOf(id)>=0){
      editorState.selected=editorState.selected.filter(function(x){return x!==id});
    }else{
      editorState.selected.push(id);
    }
  }else{
    editorState.selected=[id];
  }
  editorState.selectedConnection=null;
  draw();

  var rect=$("timelineScroll").getBoundingClientRect();
  var width=$("ruler").clientWidth;
  var span=editorState.viewEnd-editorState.viewStart;
  var startX=e.clientX,oldS=el.s,oldE=el.e,len=oldE-oldS;
  function move(ev){
    var dt=(ev.clientX-startX)/width*span;
    el.s=clamp(oldS+dt,0,editorState.duration-len);
    el.e=el.s+len;
    changed();
  }
  function up(){
    window.removeEventListener("pointermove",move);
    window.removeEventListener("pointerup",up);
  }
  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);
});

$("ruler").addEventListener("pointerdown",function(e){
  var rect=$("ruler").getBoundingClientRect();
  var t=editorState.viewStart+(e.clientX-rect.left)/rect.width*(editorState.viewEnd-editorState.viewStart);
  setPosition(t);
  draw();
});

$("playhead").addEventListener("pointerdown",function(e){
  e.stopPropagation();
  var ruler=$("ruler");
  function move(ev){
    var r=ruler.getBoundingClientRect();
    var t=editorState.viewStart+(ev.clientX-r.left)/r.width*(editorState.viewEnd-editorState.viewStart);
    setPosition(t);
    draw();
  }
  function up(){
    window.removeEventListener("pointermove",move);
    window.removeEventListener("pointerup",up);
  }
  window.addEventListener("pointermove",move);
  window.addEventListener("pointerup",up);
});

$("zoomRange").oninput=function(){draw()};
$("zoomInBtn").onclick=function(){
  $("zoomRange").value=clamp(Number($("zoomRange").value)+20,0,100);
  draw();
};
$("zoomOutBtn").onclick=function(){
  $("zoomRange").value=clamp(Number($("zoomRange").value)-20,0,100);
  draw();
};
$("fitBtn").onclick=function(){
  $("zoomRange").value=0;
  editorState.viewStart=0;
  editorState.viewEnd=editorState.duration;
  draw();
};

function saveWork(asNew){
  var e=editorState;
  if(asNew||!e.work){
    if(works.length>=LIMIT){
      modal("編集作業の上限",
        "<p>編集作業は"+LIMIT+"件までです。</p><p>既存の編集作業を削除してください。</p>",
        [{text:"閉じる"}]);
      return;
    }
    modal("編集作業を保存",
      '<label class="block">編集作業名<input id="workNameInput" type="text" value="'+escapeHtml(e.work?e.work.name:"新しい編集作業")+'"></label>',
      [
        {text:"キャンセル"},
        {text:"保存する",primary:true,action:function(){
          var name=$("workNameInput").value.trim()||"新しい編集作業";
          var w={
            id:uid("w"),videoId:e.video.id,name:name,
            saved:new Date().toLocaleString("ja-JP"),
            snapshot:{elements:clone(e.elements),connections:clone(e.connections)}
          };
          works.push(w);
          e.work=w;
          $("editorWorkName").textContent=w.name;
          setDirty(false);
          toast("編集作業を保存しました");
        }}
      ]);
  }else{
    e.work.saved=new Date().toLocaleString("ja-JP");
    e.work.snapshot={elements:clone(e.elements),connections:clone(e.connections)};
    setDirty(false);
    toast("編集作業を保存しました");
  }
}

$("saveBtn").onclick=function(){saveWork(false)};
$("saveAsBtn").onclick=function(){saveWork(true)};

$("exportBtn").onclick=function(){
  var e=editorState;
  if(results.length>=LIMIT){
    modal("編集結果動画の上限",
      "<p>編集結果動画は"+LIMIT+"件までです。</p><p>既存の編集結果動画を削除してください。</p>",
      [{text:"閉じる"}]);
    return;
  }

  var proceed=function(){
    modal("編集結果動画を作成",
      '<label class="block">動画名<input id="resultName" type="text" value="'+escapeHtml((e.work?e.work.name:e.video.name.replace(/\.[^.]+$/,""))+"_完成版")+'"></label>'+
      '<p class="muted">モックでは実際の動画ファイルは生成せず、作成済み状態を確認します。</p>',
      [
        {text:"キャンセル"},
        {text:"作成する",primary:true,action:function(){
          var name=$("resultName").value.trim()||"編集結果";
          results.push({
            id:uid("r"),
            name:name,
            sourceVideo:e.video.name,
            created:new Date().toLocaleString("ja-JP")
          });
          setDirty(false);
          toast("編集結果動画を作成しました");
        }}
      ]);
  };

  if(e.dirty){
    modal("未保存の変更があります",
      "<p>現在の編集内容を保存してから編集結果動画を作成しますか？</p>",
      [
        {text:"キャンセル"},
        {text:"保存せず反映",action:proceed},
        {text:"保存して作成",primary:true,action:function(){
          saveWork(false);
          setTimeout(proceed,50);
        }}
      ]);
  }else{
    proceed();
  }
};

$("endBtn").onclick=function(){
  var e=editorState;
  var finish=function(){
    stopPlayback();
    closeMenu();
    $("editor").classList.add("hidden");
    $("home").classList.remove("hidden");
    renderHome();
  };

  if(!e.dirty){
    finish();
    return;
  }

  modal("未保存の変更があります",
    "<p>編集内容を保存せずに終了しますか？</p>",
    [
      {text:"編集に戻る"},
      {text:"保存して終了",primary:true,action:function(){saveWork(false);setTimeout(finish,50)}},
      {text:"保存せず終了",danger:true,action:finish}
    ]);
};

document.addEventListener("pointerdown",function(e){
  if(!e.target.closest(".menu"))closeMenu();
},true);

document.addEventListener("keydown",function(e){
  if(e.key==="Escape"){
    closeMenu();
    $("modalRoot").innerHTML="";
  }
});

window.addEventListener("resize",function(){
  if(editorState&&!$("editor").classList.contains("hidden"))draw();
});

renderHome();

})();
</script>
</body>
</html>

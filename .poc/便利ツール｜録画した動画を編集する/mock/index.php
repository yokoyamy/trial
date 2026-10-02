<?php
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;font-family:"Yu Gothic",sans-serif;font-size:13px;color:#222;background:#eef1f5}
button,input{font:inherit}
button{padding:5px 10px;border:1px solid #999;background:#fff;border-radius:4px;cursor:pointer}
button:hover{background:#eef5ff}
.primary{background:#2563eb;color:#fff;border-color:#2563eb}
.danger{color:#b91c1c;border-color:#fca5a5}
.hidden{display:none!important}
.sp{flex:1}
.small{font-size:11px;color:#666}

#home{height:100%;padding:16px;display:grid;grid-template-columns:1.4fr 1fr;gap:16px}
.panel{background:#fff;border:1px solid #ccd2da;border-radius:6px;padding:12px;overflow:auto}
.panel h2{font-size:15px;margin:0 0 10px}
.video-item{border:1px solid #bbb;border-radius:5px;margin-bottom:10px;background:#fafafa}
.video-head{display:flex;align-items:center;gap:8px;padding:8px;background:#e8eef7}
.work-list{margin:6px 8px 8px 28px;border-left:3px solid #2563eb;padding-left:8px}
.work-row{display:flex;align-items:center;gap:8px;padding:6px 3px;border-bottom:1px dotted #ccc}
.empty{padding:8px;color:#888}

#editor{height:100%;display:flex;flex-direction:column;min-height:0}
#topbar{height:42px;display:flex;align-items:center;gap:7px;padding:6px 10px;background:#1f2937;color:#fff;flex:none}
#topbar button{background:#374151;color:#fff;border-color:#666}
#dirty{color:#facc15}

#stage{height:calc(100vh - 42px - 250px - 43px);min-height:320px;background:#111;display:flex;align-items:center;justify-content:center;padding:10px;flex:none}
#video-frame{position:relative;width:min(760px,80vw);height:min(428px,calc(100% - 10px));aspect-ratio:16/9;background:#000;overflow:hidden;user-select:none}
#video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000}
#placeholder{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;background:linear-gradient(135deg,#172554,#0f766e 55%,#92400e);font-size:20px;text-align:center}
#objects{position:absolute;inset:0}
.object{position:absolute;cursor:move;user-select:none}
.object.selected{outline:2px dashed #facc15;outline-offset:2px}
.object.target{outline:3px solid #22c55e;outline-offset:2px}
.object.comment{display:flex;align-items:center;justify-content:center;text-align:center;padding:4px;border:2px solid #2563eb;background:#fff;color:#111;overflow:hidden}
.object.box{border:3px solid #ef4444;background:#ef444422}
.object.zoom{border:2px solid #06b6d4;background:#06b6d422}
.object.skip{border:2px dashed #6b7280;background:#6b728044}
.resize{position:absolute;right:-5px;bottom:-5px;width:10px;height:10px;background:#facc15;border:1px solid #333;cursor:nwse-resize}
.object:not(.selected) .resize{display:none}

#lines{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
#lines .hit{pointer-events:stroke;stroke:transparent;stroke-width:14;fill:none;cursor:pointer}
#connect-message{position:absolute;left:50%;top:8px;transform:translateX(-50%);background:#dc2626;color:#fff;padding:5px 12px;border-radius:12px;z-index:30;white-space:nowrap}

#controls{height:43px;display:flex;align-items:center;gap:7px;padding:6px 10px;background:#e5e7eb;border-top:1px solid #aaa;border-bottom:1px solid #bbb;flex:none}
#controls input[type=range]{width:150px}

#timeline{height:250px;background:#fff;padding:7px 10px;flex:none;overflow:hidden}
#timeline-info{height:22px;display:flex;align-items:center;gap:15px}
#timeline-row{display:flex;height:174px;gap:4px}
#timeline-row>button{width:28px;padding:0}
#timeline-main{position:relative;flex:1;min-width:0;padding-top:25px}
#ruler{height:25px;position:relative;border:1px solid #aaa;background:#f5f7fa;overflow:hidden;cursor:pointer}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #888;padding-left:3px;font-size:10px;pointer-events:none;white-space:nowrap}
#lanes{height:149px;position:relative;border:1px solid #aaa;border-top:0;overflow:hidden;background:#fff}
.track{position:absolute;height:23px;border-radius:3px;color:#fff;line-height:23px;padding:0 9px;white-space:nowrap;overflow:hidden;border:1px solid #0004;cursor:move}
.track.selected{outline:2px solid #facc15}
.edge{position:absolute;top:0;width:7px;height:100%;background:#0005;cursor:ew-resize}
.edge.left{left:0}
.edge.right{right:0}
#cursor{position:absolute;top:25px;width:2px;background:#ef4444;z-index:15;pointer-events:none}
#cursor-handle{position:absolute;left:-7px;top:-8px;width:16px;height:16px;border-radius:50%;background:#ef4444;border:2px solid #fff;pointer-events:auto;cursor:ew-resize}

#overview{height:28px;margin-top:6px;background:#e2e8f0;border:1px solid #94a3b8;position:relative}
#window{position:absolute;top:0;height:100%;background:#2563eb33;border:2px solid #2563eb;cursor:grab}
.over-grip{position:absolute;top:0;width:8px;height:100%;background:#2563eb;cursor:ew-resize}
.over-grip.left{left:-2px}
.over-grip.right{right:-2px}

#menu{position:fixed;z-index:1000;display:none;min-width:190px;background:#fff;border:1px solid #777;border-radius:5px;box-shadow:0 4px 15px #0004;padding:6px}
#menu button{display:block;width:100%;text-align:left;border:0;margin:2px 0}

#modal{position:fixed;inset:0;z-index:2000;background:#0007;display:none;align-items:center;justify-content:center}
#modal-box{background:#fff;border-radius:6px;padding:15px;min-width:340px;max-width:520px}
#modal-buttons{display:flex;justify-content:flex-end;gap:6px;margin-top:12px}
</style>
</head>
<body>

<div id="home">
  <section class="panel">
    <h2>オリジナル動画</h2>
    <div style="display:flex;gap:6px;margin-bottom:10px">
      <button id="choose-video" class="primary">動画を選択</button>
      <button id="sample-video">例題動画を取り込む</button>
      <input id="file" type="file" accept="video/*" hidden>
    </div>
    <div id="video-list"></div>
  </section>

  <section class="panel">
    <h2>編集結果の動画</h2>
    <div id="result-list"></div>
  </section>
</div>

<div id="editor" class="hidden">
  <div id="topbar">
    <b>動画：<span id="video-name"></span></b>
    <span>／ 編集作業：<span id="work-name"></span></span>
    <span id="dirty"></span>
    <span class="sp"></span>
    <button id="save">保存</button>
    <button id="save-as">別の編集作業として保存</button>
    <button id="export">編集結果を動画にする</button>
    <button id="finish">編集作業を終了</button>
  </div>

  <div id="stage">
    <div id="video-frame">
      <video id="video" controls></video>
      <div id="placeholder">例題動画<br><span style="font-size:13px">動画ファイルを選択すると実際の動画を表示します</span></div>
      <div id="objects"></div>
      <svg id="lines"></svg>
      <div id="connect-message" class="hidden">接続先の要素をクリックしてください（Escで解除）</div>
    </div>
  </div>

  <div id="controls">
    <button id="play">▶ 再生</button>
    <button id="pause">⏸ 一時停止</button>
    <button id="stop">■ 停止</button>
    <span id="position"></span>
    <span class="sp"></span>
    <button id="zoom-out">－</button>
    <input id="zoom-slider" type="range" min="0" max="1000" value="0">
    <button id="zoom-in">＋</button>
    <span id="zoom-info"></span>
    <button id="zoom-reset">全体表示</button>
  </div>

  <div id="timeline">
    <div id="timeline-info">
      <span id="range-info"></span>
      <span class="small">タイムライン上の要素はドラッグで移動、左右端で開始・終了位置を変更</span>
    </div>
    <div id="timeline-row">
      <button id="page-left">◀</button>
      <div id="timeline-main">
        <div id="ruler"></div>
        <div id="lanes"></div>
        <div id="cursor"><i id="cursor-handle"></i></div>
      </div>
      <button id="page-right">▶</button>
    </div>
    <div id="overview">
      <div id="window">
        <i class="over-grip left"></i>
        <i class="over-grip right"></i>
      </div>
    </div>
  </div>
</div>

<div id="menu"></div>
<div id="modal"><div id="modal-box"><div id="modal-content"></div><div id="modal-buttons"></div></div></div>

<script>
"use strict";

const $=id=>document.getElementById(id);
const MIN_TIME=.3;
const DEFAULT_TIME=5;
const MAX_ITEMS=10;
const COLORS={comment:"#2563eb",box:"#dc2626",zoom:"#0891b2",skip:"#6b7280"};
const NAMES={comment:"コメント",box:"強調枠",zoom:"拡大枠",skip:"スキップ"};

let nextId=1;
let videos=[];
let works=[];
let results=[];
let editor=null;

const uid=()=>nextId++;
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const fmt=t=>{let m=Math.floor(t/60),s=t-m*60;return m+":"+s.toFixed(2).padStart(5,"0")};

function modal(html,buttons){
  $("modal-content").innerHTML=html;
  $("modal-buttons").innerHTML="";
  buttons.forEach(x=>{
    const b=document.createElement("button");
    b.textContent=x[0];
    if(x[2])b.className=x[2];
    b.onclick=()=>{if(x[1])x[1]();if(x[3]!==false)closeModal()};
    $("modal-buttons").appendChild(b);
  });
  $("modal").style.display="flex";
}
function closeModal(){$("modal").style.display="none"}

function renderHome(){
  $("video-list").innerHTML=videos.length?videos.map(v=>{
    const ws=works.filter(w=>w.videoId===v.id);
    return `<div class="video-item">
      <div class="video-head">
        <div><b>${esc(v.name)}</b><br><span class="small">編集作業 ${ws.length}件</span></div>
        <span class="sp"></span>
        <button class="primary" data-new="${v.id}">新しい編集作業</button>
        <button class="danger" data-delete-video="${v.id}">削除</button>
      </div>
      <div class="work-list">
        ${ws.length?ws.map(w=>`
          <div class="work-row">
            <span>${esc(w.name)}</span>
            <span class="small">保存 ${new Date(w.saved).toLocaleString()}</span>
            <span class="sp"></span>
            <button data-open="${w.id}">再開</button>
            <button class="danger" data-delete-work="${w.id}">削除</button>
          </div>`).join(""):"<div class='empty'>編集作業はありません</div>"}
      </div>
    </div>`;
  }).join(""):"<div class='empty'>オリジナル動画はありません。</div>";

  $("result-list").innerHTML=results.length?results.map(r=>`
    <div class="work-row">
      <span>${esc(r.name)}</span>
      <span class="small">${new Date(r.created).toLocaleString()}</span>
      <span class="sp"></span>
      <button data-result="${r.id}">再生</button>
      <button class="danger" data-delete-result="${r.id}">削除</button>
    </div>`).join(""):"<div class='empty'>編集結果の動画はありません。</div>";
}

$("choose-video").onclick=()=>$("file").click();

$("file").onchange=e=>{
  const f=e.target.files[0];
  if(!f)return;

  const url=URL.createObjectURL(f);
  const probe=document.createElement("video");
  probe.preload="metadata";

  probe.onloadedmetadata=()=>{
    const v={
      id:uid(),
      name:f.name,
      url:url,
      duration:isFinite(probe.duration)?probe.duration:60
    };
    videos.push(v);
    renderHome();
    openEditor(v,null);
  };

  probe.onerror=()=>{
    URL.revokeObjectURL(url);
    modal("選択した動画を読み込めませんでした。",[["閉じる"]]);
  };

  probe.src=url;
  e.target.value="";
};

$("sample-video").onclick=()=>{
  const v={
    id:uid(),
    name:"例題動画.mp4",
    url:"",
    duration:60
  };
  videos.push(v);
  renderHome();
  openEditor(v,null);
};

$("video-list").onclick=e=>{
  const b=e.target.closest("button");
  if(!b)return;

  if(b.dataset.new)openEditor(videos.find(v=>v.id==b.dataset.new),null);

  if(b.dataset.open){
    const w=works.find(w=>w.id==b.dataset.open);
    openEditor(videos.find(v=>v.id===w.videoId),w);
  }

  if(b.dataset.deleteVideo){
    const id=+b.dataset.deleteVideo;
    videos=videos.filter(v=>v.id!==id);
    works=works.filter(w=>w.videoId!==id);
    renderHome();
  }

  if(b.dataset.deleteWork){
    works=works.filter(w=>w.id!=b.dataset.deleteWork);
    renderHome();
  }
};

$("result-list").onclick=e=>{
  const b=e.target.closest("button");
  if(!b)return;

  if(b.dataset.deleteResult){
    results=results.filter(r=>r.id!=b.dataset.deleteResult);
    renderHome();
  }

  if(b.dataset.result){
    const r=results.find(x=>x.id==b.dataset.result);
    modal(`編集結果「${esc(r.name)}」<br><br>編集結果の動画を再生するモックです。`,[["閉じる"]]);
  }
};

function openEditor(video,work){
  editor={
    video:video,
    work:work,
    duration:video.duration,
    time:0,
    viewStart:0,
    viewEnd:video.duration,
    selected:[],
    selectedLine:null,
    connecting:null,
    dirty:false,
    elements:work?JSON.parse(JSON.stringify(work.elements)):[],
    lines:work?JSON.parse(JSON.stringify(work.lines)):[]
  };

  $("home").classList.add("hidden");
  $("editor").classList.remove("hidden");

  $("video-name").textContent=video.name;
  $("work-name").textContent=work?work.name:"未保存";
  $("dirty").textContent="";

  $("video").pause();

  if(video.url){
    $("video").src=video.url;
    $("video").classList.remove("hidden");
    $("placeholder").classList.add("hidden");
  }else{
    $("video").removeAttribute("src");
    $("video").classList.add("hidden");
    $("placeholder").classList.remove("hidden");
  }

  render();
}

function closeEditor(){
  $("video").pause();
  $("editor").classList.add("hidden");
  $("home").classList.remove("hidden");
  editor=null;
  renderHome();
}

function changed(){
  editor.dirty=true;
  $("dirty").textContent="（未保存の変更あり）";
}

function currentElement(id){
  return editor.elements.find(x=>x.id===id);
}

function active(id){
  const e=currentElement(id);
  return e&&editor.time>=e.start&&editor.time<e.end;
}

function addElement(type){
  const t=editor.time;
  const e={
    id:uid(),
    type:type,
    start:t,
    end:Math.min(editor.duration,t+(type==="skip"?3:DEFAULT_TIME)),
    x:.32,
    y:.28,
    w:.28,
    h:.2,
    text:type==="comment"?"コメント":""
  };

  if(type==="comment"){
    e.w=.3;e.h=.12;e.x=.35;e.y=.35;
  }

  if(type==="skip"){
    e.w=.04;e.h=.04;e.x=.48;e.y=.48;
  }

  editor.elements.push(e);
  editor.selected=[e.id];
  editor.selectedLine=null;
  changed();
  render();
}

function removeSelected(){
  if(!editor.selected.length)return;

  const ids=new Set(editor.selected);
  editor.elements=editor.elements.filter(e=>!ids.has(e.id));
  editor.lines=editor.lines.filter(l=>!ids.has(l.from)&&!ids.has(l.to));
  editor.selected=[];
  editor.selectedLine=null;
  changed();
  render();
}

function render(){
  if(!editor)return;
  renderVideo();
  renderTimeline();
  renderPosition();
  $("zoom-slider").value=zoomValue();
  $("zoom-info").textContent=(editor.duration/(editor.viewEnd-editor.viewStart)).toFixed(1)+"倍";
  $("range-info").textContent=`表示範囲：${fmt(editor.viewStart)} ～ ${fmt(editor.viewEnd)}`;
  $("position").textContent=`${fmt(editor.time)} / ${fmt(editor.duration)}`;
}

function renderVideo(){
  const box=$("video-frame");
  const layer=$("objects");

  layer.innerHTML="";

  editor.elements.forEach(e=>{
    if(!active(e.id)||e.type==="skip")return;

    const n=document.createElement("div");
    n.className="object "+e.type+(editor.selected.includes(e.id)?" selected":"");

    if(editor.connecting&&editor.connecting!==e.id)
      n.classList.add("target");

    n.dataset.id=e.id;
    n.style.left=(e.x*100)+"%";
    n.style.top=(e.y*100)+"%";
    n.style.width=(e.w*100)+"%";
    n.style.height=(e.h*100)+"%";

    if(e.type==="comment")n.textContent=e.text;
    if(e.type==="box")n.textContent="";
    if(e.type==="zoom")n.textContent="拡大枠";

    const r=document.createElement("i");
    r.className="resize";
    n.appendChild(r);

    layer.appendChild(n);
  });

  $("connect-message").classList.toggle("hidden",!editor.connecting);

  renderLines();
}

function center(e){
  return [e.x+e.w/2,e.y+e.h/2];
}

function anchor(e,target){
  const c=center(e),t=center(target);
  const dx=t[0]-c[0],dy=t[1]-c[1];

  if(Math.abs(dx)>Math.abs(dy))
    return [e.x+(dx>0?e.w:0),e.y+e.h/2];

  return [e.x+e.w/2,e.y+(dy>0?e.h:0)];
}

function renderLines(){
  const svg=$("lines");
  let html="";

  editor.lines.forEach(l=>{
    const a=currentElement(l.from);
    const b=currentElement(l.to);
    if(!a||!b||!active(a.id)||!active(b.id))return;

    const p=anchor(a,b);
    const q=anchor(b,a);

    const x1=p[0]*$("video-frame").clientWidth;
    const y1=p[1]*$("video-frame").clientHeight;
    const x2=q[0]*$("video-frame").clientWidth;
    const y2=q[1]*$("video-frame").clientHeight;

    const selected=editor.selectedLine===l.id;
    const color=selected?"#f59e0b":"#fff";

    html+=`<path d="M${x1},${y1} L${x2},${y2}" stroke="${color}" stroke-width="${selected?4:2}" fill="none"/>`;
    html+=`<path class="hit" data-line="${l.id}" d="M${x1},${y1} L${x2},${y2}"/>`;
  });

  svg.innerHTML=html;
}

function timelineWidth(){
  return $("lanes").clientWidth;
}

function timeToX(t){
  return (t-editor.viewStart)/(editor.viewEnd-editor.viewStart)*timelineWidth();
}

function xToTime(x){
  return editor.viewStart+x/timelineWidth()*(editor.viewEnd-editor.viewStart);
}

function layoutTracks(){
  const rows=[];
  const result={};

  [...editor.elements]
    .sort((a,b)=>a.start-b.start||a.id-b.id)
    .forEach(e=>{
      let row=0;
      while(rows[row]!=null&&rows[row]>e.start)row++;
      rows[row]=e.end;
      result[e.id]=row;
    });

  return result;
}

function renderTimeline(){
  const width=editor.viewEnd-editor.viewStart;
  const step=width<5?.5:width<20?1:width<60?5:10;

  let ruler="";

  for(let t=Math.ceil(editor.viewStart/step)*step;t<=editor.viewEnd+.001;t+=step){
    ruler+=`<i class="tick" style="left:${timeToX(t)}px">${fmt(t)}</i>`;
  }

  $("ruler").innerHTML=ruler;

  const rows=layoutTracks();
  let html="";

  editor.elements.forEach(e=>{
    if(e.end<editor.viewStart||e.start>editor.viewEnd)return;

    const left=timeToX(e.start);
    const right=timeToX(e.end);

    html+=`
      <div class="track ${editor.selected.includes(e.id)?"selected":""}"
           data-id="${e.id}"
           style="left:${left}px;width:${Math.max(10,right-left)}px;top:${4+rows[e.id]*27}px;background:${COLORS[e.type]}">
        <i class="edge left" data-edge="left"></i>
        ${NAMES[e.type]}${e.type==="comment"?"："+esc(e.text):""}
        <i class="edge right" data-edge="right"></i>
      </div>`;
  });

  $("lanes").innerHTML=html;

  const overviewWidth=$("overview").clientWidth;
  $("window").style.left=(editor.viewStart/editor.duration*overviewWidth)+"px";
  $("window").style.width=((editor.viewEnd-editor.viewStart)/editor.duration*overviewWidth)+"px";
}

function renderPosition(){
  const c=$("cursor");

  if(editor.time<editor.viewStart||editor.time>editor.viewEnd){
    c.style.display="none";
    return;
  }

  c.style.display="block";
  c.style.left=timeToX(editor.time)+"px";
  c.style.height="174px";
}

function zoomValue(){
  const z=editor.duration/(editor.viewEnd-editor.viewStart);
  return Math.round(Math.log(z)/Math.log(editor.duration/.5)*1000);
}

function setView(s,e){
  const width=clamp(e-s,.5,editor.duration);
  s=clamp(s,0,editor.duration-width);
  editor.viewStart=s;
  editor.viewEnd=s+width;
}

function zoomAt(f,centerTime){
  const width=editor.viewEnd-editor.viewStart;
  const newWidth=clamp(width/f,.5,editor.duration);
  const ratio=(centerTime-editor.viewStart)/width;

  setView(
    centerTime-ratio*newWidth,
    centerTime-ratio*newWidth+newWidth
  );

  renderTimeline();
  renderPosition();
}

$("zoom-in").onclick=()=>{
  const c=editor.time>=editor.viewStart&&editor.time<=editor.viewEnd
    ?editor.time:(editor.viewStart+editor.viewEnd)/2;
  zoomAt(1.5,c);
};

$("zoom-out").onclick=()=>{
  zoomAt(.667,(editor.viewStart+editor.viewEnd)/2);
};

$("zoom-reset").onclick=()=>{
  setView(0,editor.duration);
  render();
};

$("zoom-slider").oninput=e=>{
  const z=Math.pow(editor.duration/.5,+e.target.value/1000);
  const width=clamp(editor.duration/z,.5,editor.duration);
  const c=editor.time;
  setView(c-width/2,c+width/2);
  renderTimeline();
  renderPosition();
};

$("page-left").onclick=()=>{
  const w=editor.viewEnd-editor.viewStart;
  setView(editor.viewStart-w*.25,editor.viewEnd-w*.25);
  render();
};

$("page-right").onclick=()=>{
  const w=editor.viewEnd-editor.viewStart;
  setView(editor.viewStart+w*.25,editor.viewEnd+w*.25);
  render();
};

$("ruler").onmousedown=e=>{
  if(e.button!==0)return;
  editor.time=clamp(
    xToTime(e.clientX-$("ruler").getBoundingClientRect().left),
    0,
    editor.duration
  );

  if(editor.video.url)
    $("video").currentTime=editor.time;

  render();
};

let cursorDrag=false;

$("cursor-handle").onmousedown=e=>{
  e.preventDefault();
  e.stopPropagation();
  cursorDrag=true;
};

let timelineDrag=null;
let objectDrag=null;
let overviewDrag=null;

$("lanes").onmousedown=e=>{
  if(e.button!==0)return;

  const track=e.target.closest(".track");

  if(!track){
    editor.selected=[];
    render();
    return;
  }

  const id=+track.dataset.id;
  const obj=currentElement(id);

  if(e.shiftKey){
    editor.selected=editor.selected.includes(id)
      ?editor.selected.filter(x=>x!==id)
      :editor.selected.concat(id);
  }else if(!editor.selected.includes(id)){
    editor.selected=[id];
  }

  const edge=e.target.dataset.edge||"move";

  timelineDrag={
    id:id,
    mode:edge,
    x:e.clientX,
    start:obj.start,
    end:obj.end
  };

  render();
};

$("video-frame").onmousedown=e=>{
  if(e.button!==0)return;

  const target=e.target.closest(".object");

  if(editor.connecting){
    if(target){
      const to=+target.dataset.id;

      if(to!==editor.connecting){
        const exists=editor.lines.some(
          l=>l.from===editor.connecting&&l.to===to
        );

        if(!exists){
          editor.lines.push({
            id:uid(),
            from:editor.connecting,
            to:to
          });
          changed();
        }
      }
    }

    editor.connecting=null;
    render();
    return;
  }

  if(!target){
    editor.selected=[];
    editor.selectedLine=null;
    render();
    return;
  }

  const id=+target.dataset.id;

  if(e.shiftKey){
    editor.selected=editor.selected.includes(id)
      ?editor.selected.filter(x=>x!==id)
      :editor.selected.concat(id);
  }else if(!editor.selected.includes(id)){
    editor.selected=[id];
  }

  const rect=$("video-frame").getBoundingClientRect();

  objectDrag={
    resize:e.target.classList.contains("resize"),
    x:e.clientX,
    y:e.clientY,
    rect:rect,
    original:editor.selected.map(id=>{
      const o=currentElement(id);
      return {
        id:id,
        x:o.x,
        y:o.y,
        w:o.w,
        h:o.h
      };
    })
  };

  render();
};

$("lines").onclick=e=>{
  const p=e.target.closest("[data-line]");

  if(!p)return;

  editor.selected=[];
  editor.selectedLine=+p.dataset.line;
  render();
};

document.oncontextmenu=e=>{
  if(!e.target.closest("#video-frame"))return;

  e.preventDefault();

  const target=e.target.closest(".object");
  const menu=$("menu");

  menu.innerHTML="";

  if(target){
    const id=+target.dataset.id;
    const o=currentElement(id);

    addMenu("接続元にする",()=>{
      editor.connecting=id;
      editor.selected=[id];
      editor.selectedLine=null;
      render();
    });

    addMenu("要素を削除",removeSelected);

    if(o.type==="comment"){
      addMenu("コメントを変更",()=>{
        modal(
          `コメント<br><input id="edit-text" value="${esc(o.text)}" style="width:100%">`,
          [["変更",()=>{
            o.text=$("edit-text").value;
            changed();
            render();
          }],["キャンセル"]]
        );
      });
    }
  }else{
    addMenu("コメントを追加",()=>addElement("comment"));
    addMenu("強調枠を追加",()=>addElement("box"));
    addMenu("拡大枠を追加",()=>addElement("zoom"));
    addMenu("スキップを追加",()=>addElement("skip"));
  }

  menu.style.left=Math.min(e.clientX,innerWidth-220)+"px";
  menu.style.top=Math.min(e.clientY,innerHeight-250)+"px";
  menu.style.display="block";
};

function addMenu(text,fn){
  const b=document.createElement("button");
  b.textContent=text;
  b.onclick=()=>{
    $("menu").style.display="none";
    fn();
  };
  $("menu").appendChild(b);
}

document.addEventListener("mousedown",e=>{
  if(!e.target.closest("#menu"))
    $("menu").style.display="none";
});

window.onmousemove=e=>{
  if(!editor)return;

  if(cursorDrag){
    editor.time=clamp(
      xToTime(e.clientX-$("ruler").getBoundingClientRect().left),
      0,
      editor.duration
    );

    if(editor.video.url)
      $("video").currentTime=editor.time;

    render();
    return;
  }

  if(objectDrag){
    const dx=(e.clientX-objectDrag.x)/objectDrag.rect.width;
    const dy=(e.clientY-objectDrag.y)/objectDrag.rect.height;

    objectDrag.original.forEach(o=>{
      const n=currentElement(o.id);

      if(objectDrag.resize&&editor.selected.length===1){
        n.w=clamp(o.w+dx,.03,1-n.x);
        n.h=clamp(o.h+dy,.03,1-n.y);
      }else{
        n.x=clamp(o.x+dx,0,1-n.w);
        n.y=clamp(o.y+dy,0,1-n.h);
      }
    });

    changed();
    renderVideo();
    renderLines();
    return;
  }

  if(timelineDrag){
    const width=$("lanes").clientWidth;
    const dt=(e.clientX-timelineDrag.x)/width*
      (editor.viewEnd-editor.viewStart);

    const o=currentElement(timelineDrag.id);

    if(timelineDrag.mode==="move"){
      const len=timelineDrag.end-timelineDrag.start;
      o.start=clamp(
        timelineDrag.start+dt,
        0,
        editor.duration-len
      );
      o.end=o.start+len;
    }

    if(timelineDrag.mode==="left"){
      o.start=clamp(
        timelineDrag.start+dt,
        0,
        o.end-MIN_TIME
      );
    }

    if(timelineDrag.mode==="right"){
      o.end=clamp(
        timelineDrag.end+dt,
        o.start+MIN_TIME,
        editor.duration
      );
    }

    changed();
    render();
  }

  if(overviewDrag){
    const width=$("overview").clientWidth;
    const dt=(e.clientX-overviewDrag.x)/width*editor.duration;

    if(overviewDrag.mode==="move")
      setView(overviewDrag.start+dt,overviewDrag.end+dt);

    if(overviewDrag.mode==="left")
      setView(
        overviewDrag.start+dt,
        overviewDrag.end
      );

    if(overviewDrag.mode==="right")
      setView(
        overviewDrag.start,
        overviewDrag.end+dt
      );

    render();
  }
};

window.onmouseup=()=>{
  cursorDrag=false;
  objectDrag=null;
  timelineDrag=null;
  overviewDrag=null;
};

$("overview").onmousedown=e=>{
  if(e.button!==0)return;

  const target=e.target;

  if(target.classList.contains("over-grip")){
    overviewDrag={
      mode:target.classList.contains("left")?"left":"right",
      x:e.clientX,
      start:editor.viewStart,
      end:editor.viewEnd
    };
    return;
  }

  if(target.id==="window"){
    overviewDrag={
      mode:"move",
      x:e.clientX,
      start:editor.viewStart,
      end:editor.viewEnd
    };
    return;
  }

  const rect=$("overview").getBoundingClientRect();
  const center=(e.clientX-rect.left)/rect.width*editor.duration;
  const width=editor.viewEnd-editor.viewStart;

  setView(center-width/2,center+width/2);
  render();
};

document.onkeydown=e=>{
  if(e.key==="Escape"){
    editor.connecting=null;
    $("menu").style.display="none";
    render();
  }

  if(e.key==="Delete")
    removeSelected();
};

$("play").onclick=()=>{
  if(editor.video.url){
    $("video").play();
    return;
  }

  editor.playing=true;
  editor.last=performance.now();
  requestAnimationFrame(playSample);
};

function playSample(now){
  if(!editor||!editor.playing)return;

  editor.time+=(now-editor.last)/1000;
  editor.last=now;

  if(editor.time>=editor.duration){
    editor.time=editor.duration;
    editor.playing=false;
  }

  render();

  if(editor.playing)
    requestAnimationFrame(playSample);
}

$("pause").onclick=()=>{
  editor.playing=false;
  $("video").pause();
};

$("stop").onclick=()=>{
  editor.playing=false;
  $("video").pause();
  editor.time=0;
  if(editor.video.url)
    $("video").currentTime=0;
  render();
};

$("video").ontimeupdate=()=>{
  if(!editor)return;
  editor.time=$("video").currentTime;
  renderVideo();
  renderPosition();
};

function saveCurrent(name,newWork){
  const data={
    elements:JSON.parse(JSON.stringify(editor.elements)),
    lines:JSON.parse(JSON.stringify(editor.lines))
  };

  if(newWork||!editor.work){
    const w={
      id:uid(),
      videoId:editor.video.id,
      name:name,
      saved:Date.now(),
      elements:data.elements,
      lines:data.lines
    };

    works.push(w);
    editor.work=w;
  }else{
    editor.work.saved=Date.now();
    editor.work.elements=data.elements;
    editor.work.lines=data.lines;
  }

  editor.dirty=false;
  $("dirty").textContent="";
  $("work-name").textContent=editor.work.name;
};

$("save").onclick=()=>{
  if(editor.work){
    saveCurrent(editor.work.name,false);
    return;
  }

  modal(
    `編集作業の名前<br><input id="save-name" value="${esc(editor.video.name+" の編集作業")}" style="width:100%">`,
    [["保存",()=>{
      saveCurrent(
        $("save-name").value.trim()||"編集作業",
        true
      );
    }],["キャンセル"]]
  );
};

$("save-as").onclick=()=>{
  modal(
    `編集作業の名前<br><input id="save-name" value="${esc((editor.work?editor.work.name:"編集作業")+"（別）")}" style="width:100%">`,
    [["保存",()=>{
      saveCurrent(
        $("save-name").value.trim()||"編集作業",
        true
      );
    }],["キャンセル"]]
  );
};

$("finish").onclick=()=>{
  if(!editor.dirty){
    closeEditor();
    return;
  }

  modal(
    "未保存の変更があります。",
    [
      ["保存して終了",()=>{
        if(editor.work){
          saveCurrent(editor.work.name,false);
          closeEditor();
        }else{
          modal(
            `編集作業の名前<br><input id="save-name" value="${esc(editor.video.name+" の編集作業")}" style="width:100%">`,
            [["保存",()=>{
              saveCurrent(
                $("save-name").value.trim()||"編集作業",
                true
              );
              closeEditor();
            }],["キャンセル"]]
          );
        }
      },"primary",false],
      ["保存せず終了",closeEditor],
      ["編集に戻る"]
    ]
  );
};

$("export").onclick=()=>{
  modal(
    `編集結果の動画名<br>
     <input id="result-name" value="${esc(editor.video.name+" 編集結果")}" style="width:100%">`,
    [["作成する",()=>{
      const name=$("result-name").value.trim()||"編集結果";
      results.push({
        id:uid(),
        videoId:editor.video.id,
        name:name,
        created:Date.now()
      });

      modal(
        `「${esc(name)}」を作成しました。`,
        [["閉じる"]]
      );
    }],["キャンセル"]]
  );
};

renderHome();
</script>
</body>
</html>

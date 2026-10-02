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
button{padding:5px 9px;border:1px solid #999;border-radius:4px;background:#fff;cursor:pointer}
button:hover{background:#eef5ff}
.primary{background:#2563eb;color:#fff;border-color:#2563eb}
.danger{color:#b91c1c;border-color:#fca5a5}
.hidden{display:none!important}
.sp{flex:1}
.small{font-size:11px;color:#666}

#home{height:100%;padding:16px;display:grid;grid-template-columns:1.4fr 1fr;gap:16px}
.panel{background:#fff;border:1px solid #ccd2da;border-radius:6px;padding:12px;overflow:auto}
.panel h2{margin:0 0 10px;font-size:15px}
.video-row{border:1px solid #bbb;border-radius:5px;margin-bottom:10px;background:#fafafa}
.video-head{display:flex;align-items:center;gap:8px;padding:8px;background:#e8eef7}
.work-list{margin:6px 8px 8px 28px;border-left:3px solid #2563eb;padding-left:8px}
.work-row{display:flex;align-items:center;gap:8px;padding:6px 3px;border-bottom:1px dotted #ccc}
.empty{padding:8px;color:#888}

#editor{height:100%;display:flex;flex-direction:column;min-height:0}
#topbar{height:44px;display:flex;align-items:center;gap:6px;padding:6px 10px;background:#1f2937;color:#fff;flex:none}
#topbar button{background:#374151;color:#fff;border-color:#666}
#dirty{color:#facc15}
#connect-state{display:none;background:#b91c1c;color:#fff;padding:4px 9px;border-radius:12px;font-weight:bold}
#connect-state.on{display:block}

#stage{height:calc(100vh - 44px - 250px - 43px);min-height:320px;background:#111;display:flex;align-items:center;justify-content:center;padding:10px;flex:none}
#video-frame{position:relative;width:min(800px,90vw);height:min(450px,calc(100% - 10px));aspect-ratio:16/9;background:#000;overflow:hidden;user-select:none}
#placeholder{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;color:#fff;background:linear-gradient(135deg,#172554,#0f766e 55%,#92400e);font-size:19px}
#video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000}
#objects{position:absolute;inset:0}
.object{position:absolute;cursor:move;user-select:none}
.object.selected{outline:2px dashed #fde047;outline-offset:2px}
.object.target{outline:3px solid #22c55e;outline-offset:3px}
.object.comment{display:flex;align-items:center;justify-content:center;text-align:center;padding:4px;border:2px solid #2563eb;background:#fff;overflow:hidden}
.object.box{border:3px solid #ef4444;background:#ef444422}
.object.zoom{border:2px solid #06b6d4;background:#06b6d422}
.object.skip{border:2px dashed #6b7280;background:#6b728044}
.resize{position:absolute;right:-5px;bottom:-5px;width:10px;height:10px;background:#fde047;border:1px solid #333;cursor:nwse-resize}
.object:not(.selected) .resize{display:none}

#lines{position:absolute;inset:0;width:100%;height:100%;z-index:20;pointer-events:none}
#lines .visible-line{fill:none}
#lines .hit-line{fill:none;stroke:transparent;stroke-width:18;pointer-events:stroke;cursor:pointer}
#connect-banner{position:absolute;top:8px;left:50%;transform:translateX(-50%);z-index:40;background:#dc2626;color:#fff;padding:6px 14px;border-radius:15px;font-weight:bold;white-space:nowrap;display:none}
#connect-banner.show{display:block}

#controls{height:43px;display:flex;align-items:center;gap:6px;padding:6px 10px;background:#e5e7eb;border-top:1px solid #aaa;border-bottom:1px solid #bbb;flex:none}
#controls input[type=range]{width:150px}

#timeline{height:250px;background:#fff;padding:7px 10px;flex:none;overflow:hidden}
#timeline-info{height:22px;display:flex;align-items:center;gap:12px}
#timeline-row{height:174px;display:flex;gap:4px}
#timeline-row>button{width:28px;padding:0}
#timeline-main{position:relative;flex:1;min-width:0;padding-top:25px}
#ruler{height:25px;position:relative;border:1px solid #aaa;background:#f5f7fa;cursor:pointer;overflow:hidden}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #888;padding-left:3px;font-size:10px;pointer-events:none;white-space:nowrap}
#lanes{height:149px;position:relative;border:1px solid #aaa;border-top:0;overflow:hidden;background:#fff}
.track{position:absolute;height:23px;border-radius:3px;color:#fff;line-height:23px;padding:0 9px;white-space:nowrap;overflow:hidden;border:1px solid #0004;cursor:move}
.track.selected{outline:2px solid #fde047}
.edge{position:absolute;top:0;width:8px;height:100%;background:#0005;cursor:ew-resize}
.edge.left{left:0}.edge.right{right:0}
#cursor{position:absolute;top:25px;width:2px;background:#ef4444;z-index:30;pointer-events:none}
#cursor-handle{position:absolute;left:-7px;top:-8px;width:16px;height:16px;border-radius:50%;background:#ef4444;border:2px solid #fff;pointer-events:auto;cursor:ew-resize}

#overview{height:28px;margin-top:6px;background:#e2e8f0;border:1px solid #94a3b8;position:relative}
#window{position:absolute;top:0;height:100%;background:#2563eb33;border:2px solid #2563eb;cursor:grab}
.grip{position:absolute;top:0;width:8px;height:100%;background:#2563eb;cursor:ew-resize}
.grip.left{left:-2px}.grip.right{right:-2px}

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
      <button id="pick" class="primary">動画を選択</button>
      <button id="sample">例題動画を取り込む</button>
      <input id="file" type="file" accept="video/*" hidden>
    </div>
    <div id="videos"></div>
  </section>

  <section class="panel">
    <h2>編集結果の動画</h2>
    <div id="results"></div>
  </section>
</div>

<div id="editor" class="hidden">
  <div id="topbar">
    <b>動画：<span id="video-name"></span></b>
    <span>／ 編集作業：<span id="work-name"></span></span>
    <span id="dirty"></span>
    <span class="sp"></span>
    <span id="connect-state">接続作成モード</span>
    <button id="connect">接続線を引く</button>
    <button id="save">保存</button>
    <button id="saveas">別の編集作業として保存</button>
    <button id="export">編集結果を動画にする</button>
    <button id="finish">編集作業を終了</button>
  </div>

  <div id="stage">
    <div id="video-frame">
      <video id="video" controls></video>
      <div id="placeholder">例題動画<br><span style="font-size:12px">動画ファイルを選択すると実際の動画を表示します</span></div>
      <div id="objects"></div>
      <svg id="lines"></svg>
      <div id="connect-banner">接続元は選択済みです。接続先の要素をクリックしてください</div>
    </div>
  </div>

  <div id="controls">
    <button id="play">▶ 再生</button>
    <button id="pause">⏸ 一時停止</button>
    <button id="stop">■ 停止</button>
    <span id="position"></span>
    <span class="sp"></span>
    <button id="zoomout">－</button>
    <input id="zoom" type="range" min="0" max="1000" value="0">
    <button id="zoomin">＋</button>
    <span id="zoominfo"></span>
    <button id="zoomreset">全体表示</button>
  </div>

  <div id="timeline">
    <div id="timeline-info">
      <span id="range"></span>
      <span class="small">要素はドラッグで移動、左右端で開始・終了位置を変更できます</span>
    </div>
    <div id="timeline-row">
      <button id="left">◀</button>
      <div id="timeline-main">
        <div id="ruler"></div>
        <div id="lanes"></div>
        <div id="cursor"><i id="cursor-handle"></i></div>
      </div>
      <button id="right">▶</button>
    </div>
    <div id="overview">
      <div id="window">
        <i class="grip left"></i>
        <i class="grip right"></i>
      </div>
    </div>
  </div>
</div>

<div id="menu"></div>
<div id="modal"><div id="modal-box"><div id="modal-content"></div><div id="modal-buttons"></div></div></div>

<script>
"use strict";

const $=id=>document.getElementById(id);
const COLORS={comment:"#2563eb",box:"#dc2626",zoom:"#0891b2",skip:"#6b7280"};
const NAMES={comment:"コメント",box:"強調枠",zoom:"拡大枠",skip:"スキップ"};

let seq=1;
let videos=[];
let works=[];
let results=[];
let editor=null;

const uid=()=>seq++;
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const esc=s=>String(s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const timeText=t=>{
  const m=Math.floor(t/60);
  const s=(t-m*60).toFixed(2).padStart(5,"0");
  return m+":"+s;
};

function modal(html,buttons){
  $("modal-content").innerHTML=html;
  $("modal-buttons").innerHTML="";
  buttons.forEach(x=>{
    const b=document.createElement("button");
    b.textContent=x[0];
    if(x[2])b.className=x[2];
    b.onclick=()=>{
      if(x[1])x[1]();
      if(x[3]!==false)$("modal").style.display="none";
    };
    $("modal-buttons").appendChild(b);
  });
  $("modal").style.display="flex";
}

function closeModal(){
  $("modal").style.display="none";
}

function renderHome(){
  $("videos").innerHTML=videos.length?videos.map(v=>{
    const ws=works.filter(w=>w.videoId===v.id);
    return `<div class="video-row">
      <div class="video-head">
        <div><b>${esc(v.name)}</b><br><span class="small">編集作業 ${ws.length}件</span></div>
        <span class="sp"></span>
        <button class="primary" data-new="${v.id}">新しい編集作業</button>
        <button class="danger" data-dv="${v.id}">削除</button>
      </div>
      <div class="work-list">
        ${ws.length?ws.map(w=>`
          <div class="work-row">
            <span>${esc(w.name)}</span>
            <span class="small">${new Date(w.saved).toLocaleString()}</span>
            <span class="sp"></span>
            <button data-open="${w.id}">再開</button>
            <button class="danger" data-dw="${w.id}">削除</button>
          </div>`).join(""):"<div class='empty'>編集作業はありません</div>"}
      </div>
    </div>`;
  }).join(""):"<div class='empty'>オリジナル動画はありません。</div>";

  $("results").innerHTML=results.length?results.map(r=>`
    <div class="work-row">
      <span>${esc(r.name)}</span>
      <span class="small">${new Date(r.created).toLocaleString()}</span>
      <span class="sp"></span>
      <button data-result="${r.id}">再生</button>
      <button class="danger" data-dr="${r.id}">削除</button>
    </div>`).join(""):"<div class='empty'>編集結果の動画はありません。</div>";
}

$("pick").onclick=()=>$("file").click();

$("file").onchange=e=>{
  const f=e.target.files[0];
  if(!f)return;

  const url=URL.createObjectURL(f);
  const v=document.createElement("video");
  v.preload="metadata";

  v.onloadedmetadata=()=>{
    videos.push({
      id:uid(),
      name:f.name,
      url:url,
      duration:isFinite(v.duration)?v.duration:60
    });
    renderHome();
    openEditor(videos[videos.length-1]);
  };

  v.onerror=()=>{
    URL.revokeObjectURL(url);
    modal("動画を読み込めませんでした。",[["閉じる"]]);
  };

  v.src=url;
  e.target.value="";
};

$("sample").onclick=()=>{
  const v={
    id:uid(),
    name:"例題動画.mp4",
    url:"",
    duration:60
  };
  videos.push(v);
  renderHome();
  openEditor(v);
};

$("videos").onclick=e=>{
  const b=e.target.closest("button");
  if(!b)return;

  if(b.dataset.new){
    openEditor(videos.find(v=>v.id==b.dataset.new));
    return;
  }

  if(b.dataset.open){
    const w=works.find(w=>w.id==b.dataset.open);
    openEditor(
      videos.find(v=>v.id===w.videoId),
      w
    );
    return;
  }

  if(b.dataset.dv){
    videos=videos.filter(v=>v.id!=b.dataset.dv);
    works=works.filter(w=>w.videoId!=b.dataset.dv);
    renderHome();
    return;
  }

  if(b.dataset.dw){
    works=works.filter(w=>w.id!=b.dataset.dw);
    renderHome();
  }
};

$("results").onclick=e=>{
  const b=e.target.closest("button");
  if(!b)return;

  if(b.dataset.dr){
    results=results.filter(r=>r.id!=b.dataset.dr);
    renderHome();
  }

  if(b.dataset.result){
    const r=results.find(r=>r.id==b.dataset.result);
    modal(`編集結果「${esc(r.name)}」<br><br>再生モックです。`,[["閉じる"]]);
  }
};

function openEditor(video,work){
  editor={
    video:video,
    work:work||null,
    duration:video.duration,
    time:0,
    viewStart:0,
    viewEnd:video.duration,
    selected:[],
    selectedLine:null,
    connectFrom:null,
    dirty:false,
    playing:false,
    elements:work?JSON.parse(JSON.stringify(work.elements)):[],
    lines:work?JSON.parse(JSON.stringify(work.lines)):[]
  };

  $("home").classList.add("hidden");
  $("editor").classList.remove("hidden");

  $("video-name").textContent=video.name;
  $("work-name").textContent=work?work.name:"未保存";
  $("dirty").textContent="";

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

function endEditor(){
  if(editor)editor.playing=false;
  $("editor").classList.add("hidden");
  $("home").classList.remove("hidden");
  editor=null;
  renderHome();
}

function changed(){
  editor.dirty=true;
  $("dirty").textContent="（未保存の変更あり）";
}

function element(id){
  return editor.elements.find(e=>e.id===id);
}

function visible(e){
  return editor.time>=e.start&&editor.time<e.end;
}

function addElement(type){
  const t=editor.time;
  const e={
    id:uid(),
    type:type,
    start:t,
    end:Math.min(editor.duration,t+(type==="skip"?3:5)),
    x:.32,
    y:.28,
    w:.28,
    h:.2,
    text:type==="comment"?"コメント":""
  };

  if(type==="comment"){
    e.x=.35;e.y=.35;e.w=.3;e.h=.12;
  }

  if(type==="skip"){
    e.x=.48;e.y=.48;e.w=.04;e.h=.04;
  }

  editor.elements.push(e);
  editor.selected=[e.id];
  editor.selectedLine=null;
  changed();
  render();
}

function deleteSelected(){
  if(!editor.selected.length)return;

  const ids=new Set(editor.selected);

  editor.elements=editor.elements.filter(e=>!ids.has(e.id));
  editor.lines=editor.lines.filter(l=>!ids.has(l.from)&&!ids.has(l.to));
  editor.selected=[];
  editor.selectedLine=null;
  editor.connectFrom=null;

  changed();
  render();
}

function center(e){
  return [e.x+e.w/2,e.y+e.h/2];
}

function anchor(e,target){
  const c=center(e);
  const t=center(target);
  const dx=t[0]-c[0];
  const dy=t[1]-c[1];

  if(Math.abs(dx)>Math.abs(dy))
    return [e.x+(dx>0?e.w:0),e.y+e.h/2];

  return [e.x+e.w/2,e.y+(dy>0?e.h:0)];
}

function render(){
  if(!editor)return;
  renderVideo();
  renderTimeline();
  renderPosition();
  $("zoom").value=zoomValue();
  $("zoominfo").textContent=(editor.duration/(editor.viewEnd-editor.viewStart)).toFixed(1)+"倍";
  $("range").textContent=`表示範囲：${timeText(editor.viewStart)} ～ ${timeText(editor.viewEnd)}`;
}

function renderVideo(){
  const layer=$("objects");
  layer.innerHTML="";

  editor.elements.forEach(e=>{
    if(!visible(e)||e.type==="skip")return;

    const n=document.createElement("div");

    n.className="object "+e.type;

    if(editor.selected.includes(e.id))
      n.classList.add("selected");

    if(editor.connectFrom!==null&&editor.connectFrom!==e.id)
      n.classList.add("target");

    n.dataset.id=e.id;
    n.style.left=(e.x*100)+"%";
    n.style.top=(e.y*100)+"%";
    n.style.width=(e.w*100)+"%";
    n.style.height=(e.h*100)+"%";

    if(e.type==="comment")
      n.textContent=e.text;

    if(e.type==="zoom")
      n.textContent="拡大枠";

    n.insertAdjacentHTML(
      "beforeend",
      '<i class="resize"></i>'
    );

    layer.appendChild(n);
  });

  $("connect-state").classList.toggle(
    "on",
    editor.connectFrom!==null
  );

  $("connect-banner").classList.toggle(
    "show",
    editor.connectFrom!==null
  );

  renderLines();
}

function renderLines(){
  const svg=$("lines");
  const w=$("video-frame").clientWidth;
  const h=$("video-frame").clientHeight;

  let html="";

  editor.lines.forEach(l=>{
    const a=element(l.from);
    const b=element(l.to);

    if(!a||!b||!visible(a)||!visible(b))
      return;

    const p=anchor(a,b);
    const q=anchor(b,a);

    const x1=p[0]*w;
    const y1=p[1]*h;
    const x2=q[0]*w;
    const y2=q[1]*h;

    const color=
      editor.selectedLine===l.id
      ?"#f59e0b"
      :"#ffffff";

    html+=`
      <line
        class="visible-line"
        x1="${x1}" y1="${y1}"
        x2="${x2}" y2="${y2}"
        stroke="${color}"
        stroke-width="${editor.selectedLine===l.id?4:3}"
      />
      <line
        class="hit-line"
        data-line="${l.id}"
        x1="${x1}" y1="${y1}"
        x2="${x2}" y2="${y2}"
      />
    `;
  });

  svg.innerHTML=html;
}

function zoomValue(){
  const z=editor.duration/(editor.viewEnd-editor.viewStart);
  return Math.round(
    Math.log(z)/Math.log(editor.duration/.5)*1000
  );
}

function setView(start,end){
  const width=clamp(
    end-start,
    .5,
    editor.duration
  );

  start=clamp(
    start,
    0,
    editor.duration-width
  );

  editor.viewStart=start;
  editor.viewEnd=start+width;
}

function zoomAt(f,centerTime){
  const old=editor.viewEnd-editor.viewStart;
  const nw=clamp(
    old/f,
    .5,
    editor.duration
  );

  const ratio=(centerTime-editor.viewStart)/old;

  setView(
    centerTime-ratio*nw,
    centerTime-ratio*nw+nw
  );

  render();
}

$("zoomin").onclick=()=>{
  zoomAt(
    1.5,
    editor.time>=editor.viewStart&&editor.time<=editor.viewEnd
      ?editor.time
      :(editor.viewStart+editor.viewEnd)/2
  );
};

$("zoomout").onclick=()=>{
  zoomAt(
    .667,
    (editor.viewStart+editor.viewEnd)/2
  );
};

$("zoomreset").onclick=()=>{
  setView(0,editor.duration);
  render();
};

$("zoom").oninput=e=>{
  const z=Math.pow(
    editor.duration/.5,
    +e.target.value/1000
  );

  const width=clamp(
    editor.duration/z,
    .5,
    editor.duration
  );

  const c=editor.time;

  setView(
    c-width/2,
    c+width/2
  );

  render();
};

$("left").onclick=()=>{
  const w=editor.viewEnd-editor.viewStart;
  setView(
    editor.viewStart-w*.25,
    editor.viewEnd-w*.25
  );
  render();
};

$("right").onclick=()=>{
  const w=editor.viewEnd-editor.viewStart;
  setView(
    editor.viewStart+w*.25,
    editor.viewEnd+w*.25
  );
  render();
};

/*
 * 接続線作成
 *
 * 1. 要素を1つ選択
 * 2. 「接続線を引く」を押す
 * 3. 接続先の要素をクリック
 *
 * 接続中は要素の移動・リサイズを開始しない。
 */
$("connect").onclick=()=>{
  if(editor.connectFrom!==null){
    editor.connectFrom=null;
    render();
    return;
  }

  if(editor.selected.length!==1){
    modal(
      "接続線を引くには、まず接続元の要素を1つ選択してください。",
      [["閉じる"]]
    );
    return;
  }

  const source=element(editor.selected[0]);

  if(!source||source.type==="skip"){
    modal(
      "スキップ要素は接続元にできません。",
      [["閉じる"]]
    );
    return;
  }

  editor.connectFrom=source.id;
  editor.selectedLine=null;
  render();
};

$("video-frame").addEventListener("mousedown",e=>{
  if(e.button!==0)return;

  const target=e.target.closest(".object");

  /*
   * 最優先で接続処理。
   * 通常のドラッグ処理には絶対に流さない。
   */
  if(editor.connectFrom!==null){

    if(target){
      const to=Number(target.dataset.id);

      if(to===editor.connectFrom){
        modal(
          "接続元と同じ要素には接続できません。",
          [["閉じる"]]
        );
        return;
      }

      const destination=element(to);

      if(destination.type==="skip"){
        modal(
          "スキップ要素には接続できません。",
          [["閉じる"]]
        );
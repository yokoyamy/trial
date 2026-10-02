<?php
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集モック</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;height:100%;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:#263238;background:#f3f5f7}
button,select,input{font:inherit}
button{cursor:pointer}

.app{height:100vh;display:flex;flex-direction:column}
.header{height:54px;background:#263238;color:#fff;display:flex;align-items:center;padding:0 16px;gap:14px}
.header .title{font-size:17px;font-weight:700}
.header .sub{font-size:12px;color:#b8c4c9}
.header .spacer{flex:1}
.header button{border:0;border-radius:5px;padding:7px 12px;background:#455a64;color:#fff}
.header button.primary{background:#1976d2}

.toolbar{height:52px;background:#fff;border-bottom:1px solid #d7dde0;display:flex;align-items:center;padding:0 14px;gap:7px}
.toolbar button{border:1px solid #cbd3d7;background:#fff;border-radius:5px;padding:6px 10px;color:#37474f}
.toolbar button:hover{background:#eef3f5}
.toolbar .sep{width:1px;height:26px;background:#d5dadd;margin:0 5px}
.toolbar .hint{margin-left:auto;font-size:12px;color:#78909c}

.workspace{flex:1;min-height:0;display:flex;flex-direction:column}

.editor{flex:1;min-height:0;display:flex;padding:12px;gap:12px}
.stage-panel{flex:1;min-width:0;background:#fff;border:1px solid #d5dce0;border-radius:7px;display:flex;flex-direction:column;overflow:hidden}
.stage-head{height:40px;border-bottom:1px solid #e0e4e7;display:flex;align-items:center;padding:0 12px;font-size:13px;font-weight:600}
.stage-head .time{margin-left:auto;font-weight:400;color:#607d8b}
.stage-wrap{flex:1;min-height:0;display:flex;align-items:center;justify-content:center;background:#dfe5e8;padding:18px}
.stage{position:relative;width:min(100%,960px);aspect-ratio:16/9;background:#20272b;border-radius:4px;overflow:hidden;box-shadow:0 3px 12px #0002}
.stage-video{
 position:absolute;inset:0;
 background:
 radial-gradient(circle at 30% 35%,#536d78 0 10%,transparent 11%),
 linear-gradient(135deg,#263238,#455a64 45%,#1d2529);
}
.stage-video:after{content:"VIDEO";position:absolute;inset:0;display:grid;place-items:center;color:#ffffff22;font-size:48px;font-weight:800;letter-spacing:8px}

.element{
 position:absolute;min-width:70px;min-height:34px;
 border:2px solid #42a5f5;border-radius:5px;
 user-select:none;cursor:move;
}
.element.selected{outline:2px solid #ff9800;outline-offset:3px}
.element .label{position:absolute;left:6px;top:4px;font-size:12px;color:#fff;text-shadow:0 1px 2px #000;pointer-events:none}
.comment{background:#fff;color:#263238;border-color:#607d8b;padding:9px 12px;font-size:14px}
.comment .label{position:static;color:#263238;text-shadow:none}
.highlight{border-color:#ef5350;background:#ef535020}
.zoom{border-color:#66bb6a;background:#66bb6a18}
.element .handle{
 position:absolute;width:8px;height:8px;background:#fff;border:1px solid #455a64;
 right:-5px;bottom:-5px;cursor:nwse-resize;border-radius:2px
}

.timeline-panel{height:245px;background:#fff;border-top:1px solid #ccd4d8;display:flex;flex-direction:column}
.timeline-head{height:42px;display:flex;align-items:center;padding:0 12px;border-bottom:1px solid #e1e5e7;gap:7px}
.timeline-head strong{font-size:13px}
.timeline-head button{border:1px solid #ccd4d8;background:#fff;border-radius:4px;padding:4px 9px}
.timeline-head .scale{margin-left:auto;display:flex;align-items:center;gap:6px;font-size:12px;color:#607d8b}
.timeline-scroll{flex:1;overflow:auto}
.timeline{position:relative;min-width:760px;height:190px}
.ruler{position:absolute;left:110px;right:10px;top:0;height:28px;border-bottom:1px solid #cfd6da;background:#fafbfc}
.tick{position:absolute;top:0;height:28px;border-left:1px solid #d8dee1;padding-left:3px;font-size:10px;color:#78909c}
.row{position:absolute;left:0;right:10px;height:42px;border-bottom:1px solid #edf0f1}
.row .name{position:absolute;left:8px;top:11px;width:95px;font-size:11px;color:#455a64;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.track{position:absolute;left:110px;right:0;top:0;height:42px}
.bar{
 position:absolute;top:8px;height:26px;border-radius:4px;
 color:#fff;font-size:11px;padding:5px 8px;overflow:hidden;white-space:nowrap;
 cursor:pointer;border:1px solid #0002
}
.bar.selected{box-shadow:0 0 0 2px #ff9800}
.playhead{
 position:absolute;top:0;bottom:0;width:2px;background:#e53935;z-index:20;
 pointer-events:none
}
.playhead:before{
 content:"";position:absolute;top:-1px;left:-5px;border-left:6px solid transparent;
 border-right:6px solid transparent;border-top:8px solid #e53935
}

.side{
 width:240px;background:#fff;border:1px solid #d5dce0;border-radius:7px;
 display:flex;flex-direction:column;overflow:hidden
}
.side-head{padding:11px 12px;border-bottom:1px solid #e1e5e7;font-weight:700;font-size:13px}
.side-body{padding:12px;overflow:auto;font-size:12px}
.side .empty{color:#90a4ae;line-height:1.7}
.prop{margin-bottom:14px}
.prop-title{font-weight:700;color:#455a64;margin-bottom:7px}
.prop-row{display:flex;align-items:center;gap:6px;margin:5px 0}
.prop-row label{width:66px;color:#607d8b}
.prop-row select,.prop-row input[type=number]{flex:1;min-width:0;border:1px solid #cbd3d7;border-radius:4px;padding:4px}
.colors{display:grid;grid-template-columns:repeat(6,1fr);gap:5px}
.color{height:22px;border:2px solid #fff;outline:1px solid #c8d0d4;border-radius:3px;cursor:pointer}
.color.active{outline:2px solid #ff9800}
.check{display:flex;align-items:center;gap:6px}

.context{
 position:fixed;z-index:1000;display:none;width:230px;background:#fff;
 border:1px solid #c7d0d4;border-radius:6px;box-shadow:0 5px 18px #0003;padding:5px
}
.context button{
 display:block;width:100%;text-align:left;border:0;background:#fff;padding:8px 10px;border-radius:4px;font-size:12px
}
.context button:hover{background:#eef3f5}
.context .ctx-title{padding:7px 10px;font-size:11px;color:#78909c;border-bottom:1px solid #edf0f1;margin-bottom:3px}

.modal{
 position:fixed;inset:0;background:#0006;display:none;align-items:center;justify-content:center;z-index:2000
}
.modal-box{width:430px;background:#fff;border-radius:8px;box-shadow:0 10px 35px #0005;padding:20px}
.modal-box h3{margin:0 0 15px;font-size:17px}
.video-choice{border:1px solid #ccd5d9;border-radius:6px;padding:12px;margin:8px 0;cursor:pointer}
.video-choice:hover{background:#f1f6f8;border-color:#90a4ae}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
.modal-actions button{border:1px solid #cbd3d7;background:#fff;border-radius:5px;padding:7px 13px}
.modal-actions .primary{background:#1976d2;color:#fff;border-color:#1976d2}

.status{position:fixed;right:15px;bottom:15px;background:#263238;color:#fff;padding:8px 12px;border-radius:5px;font-size:12px;opacity:0;transition:.2s;z-index:3000}
.status.show{opacity:1}

.hidden{display:none!important}
</style>
</head>
<body>

<div class="app">

<header class="header">
  <div class="title">動画編集</div>
  <div class="sub" id="workName">新しい編集作業</div>
  <div class="spacer"></div>
  <button onclick="openVideoModal()">動画を選択</button>
  <button onclick="saveWork()">編集作業を保存</button>
  <button onclick="finishWork()">編集作業を終了</button>
</header>

<div class="toolbar">
  <button onclick="addElement('comment')">＋ コメント</button>
  <button onclick="addElement('highlight')">＋ 強調枠</button>
  <button onclick="addElement('zoom')">＋ 拡大枠</button>
  <button onclick="addElement('skip')">＋ スキップ</button>
  <div class="sep"></div>
  <button onclick="startConnect()">接続線を作成</button>
  <button onclick="deleteSelected()">削除</button>
  <div class="hint" id="toolHint">要素を選択すると操作メニューを表示します</div>
</div>

<div class="workspace">

<div class="editor">

  <section class="stage-panel">
    <div class="stage-head">
      動画プレビュー
      <span class="time" id="currentTime">00:00.0 / 01:00.0</span>
    </div>

    <div class="stage-wrap">
      <div class="stage" id="stage" oncontextmenu="stageContext(event)">
        <div class="stage-video"></div>

        <div class="element comment" id="e1"
             style="left:10%;top:15%;width:190px;height:55px"
             data-type="comment" data-start="5" data-end="28"
             onmousedown="elementDown(event,this)"
             oncontextmenu="elementContext(event,this)">
          <div class="label">ここを確認してください</div>
          <div class="handle"></div>
        </div>

        <div class="element highlight" id="e2"
             style="left:48%;top:23%;width:180px;height:105px"
             data-type="highlight" data-start="12" data-end="42"
             onmousedown="elementDown(event,this)"
             oncontextmenu="elementContext(event,this)">
          <div class="label">強調範囲</div>
          <div class="handle"></div>
        </div>

        <div class="element zoom" id="e3"
             style="left:24%;top:57%;width:150px;height:85px"
             data-type="zoom" data-start="20" data-end="48"
             onmousedown="elementDown(event,this)"
             oncontextmenu="elementContext(event,this)">
          <div class="label">拡大表示</div>
          <div class="handle"></div>
        </div>

        <svg id="connections" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none">
        </svg>
      </div>
    </div>
  </section>

  <aside class="side">
    <div class="side-head" id="sideTitle">操作メニュー</div>
    <div class="side-body" id="sideBody">
      <div class="empty">
        要素を選択してください。<br><br>
        右クリックでも操作メニューを開けます。<br><br>
        接続線を作る場合は「接続線を作成」を押してから、接続元と接続先を順番に選択します。
      </div>
    </div>
  </aside>

</div>

<div class="timeline-panel">
  <div class="timeline-head">
    <strong>タイムライン</strong>
    <button onclick="togglePlay()" id="playBtn">▶ 再生</button>
    <button onclick="setTime(0)">最初</button>
    <button onclick="setTime(60)">最後</button>

    <div class="scale">
      スケール
      <button onclick="changeScale(-1)">−</button>
      <span id="scaleText">100%</span>
      <button onclick="changeScale(1)">＋</button>
    </div>
  </div>

  <div class="timeline-scroll" id="timelineScroll">
    <div class="timeline" id="timeline">
      <div class="ruler" id="ruler"></div>

      <div class="row" style="top:28px">
        <div class="name">コメント</div>
        <div class="track" id="track-comment"></div>
      </div>
      <div class="row" style="top:70px">
        <div class="name">強調枠</div>
        <div class="track" id="track-highlight"></div>
      </div>
      <div class="row" style="top:112px">
        <div class="name">拡大枠</div>
        <div class="track" id="track-zoom"></div>
      </div>
      <div class="row" style="top:154px">
        <div class="name">スキップ</div>
        <div class="track" id="track-skip"></div>
      </div>

      <div class="playhead" id="playhead"></div>
    </div>
  </div>
</div>

</div>
</div>

<div class="context" id="context"></div>

<div class="modal" id="videoModal">
  <div class="modal-box">
    <h3>オリジナル動画を選択</h3>
    <div class="video-choice" onclick="chooseVideo('操作説明動画.mp4')">
      <strong>操作説明動画.mp4</strong><br>
      <span style="font-size:12px;color:#78909c">01:00　サンプル動画</span>
    </div>
    <div class="video-choice" onclick="chooseVideo('サービス紹介.mp4')">
      <strong>サービス紹介.mp4</strong><br>
      <span style="font-size:12px;color:#78909c">00:45　サンプル動画</span>
    </div>
    <div class="modal-actions">
      <button onclick="closeVideoModal()">キャンセル</button>
    </div>
  </div>
</div>

<div class="status" id="status"></div>

<script>
const DURATION = 60;
let currentTime = 0;
let playing = false;
let playTimer = null;
let scale = 1;
let selected = [];
let connectMode = false;
let connectFrom = null;
let connections = [];
let drag = null;

const stage = document.getElementById('stage');
const timeline = document.getElementById('timeline');
const ruler = document.getElementById('ruler');

function showStatus(msg){
  const el=document.getElementById('status');
  el.textContent=msg;
  el.classList.add('show');
  clearTimeout(el._timer);
  el._timer=setTimeout(()=>el.classList.remove('show'),1800);
}

function fmt(t){
  const m=Math.floor(t/60);
  const s=(t%60).toFixed(1).padStart(4,'0');
  return String(m).padStart(2,'0')+':'+s;
}

function timelineWidth(){
  return Math.max(650, 650*scale);
}

function updateTimelineWidth(){
  timeline.style.width=timelineWidth()+'px';
  ruler.style.right='0';
  document.querySelectorAll('.track').forEach(t=>t.style.right='0');
}

function renderRuler(){
  ruler.innerHTML='';
  const width=timelineWidth()-120;
  for(let t=0;t<=DURATION;t+=5){
    const tick=document.createElement('div');
    tick.className='tick';
    tick.style.left=(t/DURATION*width)+'px';
    tick.textContent=fmt(t).slice(0,5);
    ruler.appendChild(tick);
  }
}

function changeScale(dir){
  scale=Math.max(.5,Math.min(2.5,scale+dir*.25));
  document.getElementById('scaleText').textContent=Math.round(scale*100)+'%';
  updateTimelineWidth();
  renderRuler();
  renderTimeline();
  updatePlayhead();
}

function getElements(){
  return [...stage.querySelectorAll('.element')];
}

function addElement(type){
  const id='e'+Date.now();
  const el=document.createElement('div');
  el.className='element '+type;
  el.id=id;
  el.dataset.type=type;
  el.dataset.start=Math.max(0,currentTime).toFixed(1);
  el.dataset.end=Math.min(DURATION,currentTime+15).toFixed(1);

  const presets={
    comment:{left:'8%',top:'10%',width:'190px',height:'55px',text:'新しいコメント'},
    highlight:{left:'38%',top:'38%',width:'180px',height:'100px',text:'強調範囲'},
    zoom:{left:'65%',top:'12%',width:'140px',height:'80px',text:'拡大表示'}
  };

  if(type==='skip'){
    el.style.display='none';
    el.dataset.start=Math.max(0,currentTime).toFixed(1);
    el.dataset.end=Math.min(DURATION,currentTime+8).toFixed(1);
  }else{
    const p=presets[type];
    Object.assign(el.style,{left:p.left,top:p.top,width:p.width,height:p.height});
    el.innerHTML='<div class="label">'+p.text+'</div><div class="handle"></div>';
    el.onmousedown=e=>elementDown(e,el);
    el.oncontextmenu=e=>elementContext(e,el);
  }

  stage.appendChild(el);
  selectOnly(el);
  renderTimeline();
  showStatus(type==='skip'?'スキップを追加しました':'要素を追加しました');
}

function selectOnly(el){
  selected=[el];
  refreshSelection();
}

function toggleSelect(el){
  if(selected.includes(el)) selected=selected.filter(x=>x!==el);
  else selected.push(el);
  refreshSelection();
}

function refreshSelection(){
  getElements().forEach(e=>e.classList.toggle('selected',selected.includes(e)));
  if(selected.length===1) renderProperties(selected[0]);
  else if(selected.length>1) renderMulti();
  else clearProperties();
}

function clearProperties(){
  document.getElementById('sideTitle').textContent='操作メニュー';
  document.getElementById('sideBody').innerHTML='<div class="empty">要素を選択してください。<br><br>右クリックでも操作メニューを開けます。</div>';
  document.getElementById('toolHint').textContent='要素を選択すると操作メニューを表示します';
}

function renderMulti(){
  document.getElementById('sideTitle').textContent='複数選択';
  document.getElementById('sideBody').innerHTML='<div class="empty">'+selected.length+'個の要素を選択中です。<br><br>移動や削除など、共通して利用できる操作を行えます。</div>';
  document.getElementById('toolHint').textContent=selected.length+'個を選択中';
}

function renderProperties(el){
  const type=el.dataset.type;
  const names={comment:'コメント',highlight:'強調枠',zoom:'拡大枠',skip:'スキップ'};
  document.getElementById('sideTitle').textContent=names[type]||type;
  document.getElementById('toolHint').textContent=names[type]+'を選択中';

  if(type==='skip'){
    document.getElementById('sideBody').innerHTML=`
      <div class="prop"><div class="prop-title">表示時間</div>
        <div class="prop-row"><label>開始</label><input type="number" step=".1" value="${el.dataset.start}" onchange="setStart(this.value)"></div>
        <div class="prop-row"><label>終了</label><input type="number" step=".1" value="${el.dataset.end}" onchange="setEnd(this.value)"></div>
      </div>`;
    return;
  }

  let html=`
  <div class="prop"><div class="prop-title">表示時間</div>
    <div class="prop-row"><label>開始</label><input type="number" step=".1" value="${el.dataset.start}" onchange="setStart(this.value)"></div>
    <div class="prop-row"><label>終了</label><input type="number" step=".1" value="${el.dataset.end}" onchange="setEnd(this.value)"></div>
  </div>`;

  if(type==='comment'){
    html+=`
    <div class="prop"><div class="prop-title">コメント</div>
      <div class="prop-row"><label>線</label><select onchange="setBorder(this.value)">
        <option value="on">あり</option><option value="off">なし</option>
      </select></div>
      <div class="prop-row"><label>線の太さ</label><select onchange="setBorderWidth(this.value)">
        <option>1</option><option selected>2</option><option>3</option><option>4</option>
      </select></div>
      <div class="prop-row"><label>文字サイズ</label><select onchange="setFontSize(this.value)">
        <option>12</option><option selected>14</option><option>16</option><option>20</option><option>24</option>
      </select></div>
      <div class="prop-row"><label>フォント</label><select onchange="setFont(this.value)">
        <option>ゴシック</option><option>明朝</option><option>丸ゴシック</option>
      </select></div>
    </div>
    <div class="prop"><div class="prop-title">文字色</div>${colorPicker('text')}</div>
    <div class="prop"><div class="prop-title">背景色</div>${colorPicker('bg')}</div>
    <div class="prop"><div class="prop-title">線の色</div>${colorPicker('border')}</div>`;
  }

  if(type==='highlight'){
    html+=`
    <div class="prop"><div class="prop-title">強調枠</div>
      <div class="prop-row"><label>線の太さ</label><select onchange="setBorderWidth(this.value)">
        <option>1</option><option selected>2</option><option>3</option><option>4</option><option>6</option>
      </select></div>
      <div class="prop-row"><label>線種</label><select onchange="setLineStyle(this.value)">
        <option value="solid">実線</option><option value="dotted">点線</option>
        <option value="dashed">破線</option><option value="dashdot">一点鎖線</option>
      </select></div>
      <div class="prop-row"><label>線形</label><select onchange="setShape(this.value)">
        <option>直線</option><option>折れ線</option><option>波線</option>
      </select></div>
      <label class="check"><input type="checkbox" checked onchange="setFill(this.checked)"> 塗り潰し</label>
    </div>
    <div class="prop"><div class="prop-title">線の色</div>${colorPicker('border')}</div>`;
  }

  if(type==='zoom'){
    html+=`
    <div class="prop"><div class="prop-title">拡大表示</div>
      <div class="prop-row"><label>倍率</label><select onchange="setZoom(this.value)">
        <option>1.5</option><option selected>2</option><option>2.5</option><option>3</option>
      </select></div>
    </div>`;
  }

  document.getElementById('sideBody').innerHTML=html;
}

function colorPicker(kind){
  const colors=['#000000','#ffffff','#e53935','#fb8c00','#fdd835','#43a047','#00acc1','#1e88e5','#3949ab','#8e24aa','#6d4c41','#78909c'];
  return '<div class="colors">'+colors.map(c=>`<div class="color" style="background:${c}" onclick="setColor('${kind}','${c}')"></div>`).join('')+'</div>';
}

function setColor(kind,color){
  const el=selected[0]; if(!el)return;
  if(kind==='text') el.style.color=color;
  if(kind==='bg') el.style.background=color;
  if(kind==='border') el.style.borderColor=color;
}

function setBorder(v){if(selected[0])selected[0].style.borderStyle=v==='on'?'solid':'none'}
function setBorderWidth(v){if(selected[0])selected[0].style.borderWidth=v+'px'}
function setFontSize(v){if(selected[0])selected[0].style.fontSize=v+'px'}
function setFont(v){
  if(!selected[0])return;
  const fonts={'ゴシック':'sans-serif','明朝':'serif','丸ゴシック':'Arial Rounded MT Bold,sans-serif'};
  selected[0].style.fontFamily=fonts[v]||'sans-serif';
}
function setLineStyle(v){
  if(!selected[0])return;
  selected[0].style.borderStyle=v==='dashdot'?'dashed':v;
}
function setShape(v){
  if(!selected[0])return;
  selected[0].dataset.shape=v;
}
function setFill(v){
  if(!selected[0])return;
  selected[0].style.background=v?'#ef535020':'transparent';
}
function setZoom(v){if(selected[0])selected[0].dataset.zoom=v}

function setStart(v){
  if(!selected[0])return;
  let n=Math.max(0,Math.min(Number(v),Number(selected[0].dataset.end)-.1));
  selected[0].dataset.start=n;
  renderTimeline();
}
function setEnd(v){
  if(!selected[0])return;
  let n=Math.min(DURATION,Math.max(Number(v),Number(selected[0].dataset.start)+.1));
  selected[0].dataset.end=n;
  renderTimeline();
}

function elementDown(e,el){
  if(e.button!==0)return;
  e.stopPropagation();

  if(e.shiftKey) toggleSelect(el);
  else if(!connectMode) selectOnly(el);

  if(connectMode){
    handleConnect(el);
    return;
  }

  const r=stage.getBoundingClientRect();
  const er=el.getBoundingClientRect();
  const resize=e.target.classList.contains('handle');

  drag={
    el,
    resize,
    sx:e.clientX,sy:e.clientY,
    left:er.left-r.left,top:er.top-r.top,
    width:er.width,height:er.height
  };

  document.addEventListener('mousemove',dragMove);
  document.addEventListener('mouseup',dragEnd,{once:true});
}

function dragMove(e){
  if(!drag)return;
  const r=stage.getBoundingClientRect();
  const dx=e.clientX-drag.sx;
  const dy=e.clientY-drag.sy;

  if(drag.resize){
    drag.el.style.width=Math.max(70,drag.width+dx)+'px';
    drag.el.style.height=Math.max(34,drag.height+dy)+'px';
  }else{
    const x=Math.max(0,Math.min(r.width-drag.width,drag.left+dx));
    const y=Math.max(0,Math.min(r.height-drag.height,drag.top+dy));
    drag.el.style.left=x+'px';
    drag.el.style.top=y+'px';
  }
  drawConnections();
}

function dragEnd(){
  document.removeEventListener('mousemove',dragMove);
  drag=null;
  renderTimeline();
}

function startConnect(){
  connectMode=true;
  connectFrom=null;
  document.getElementById('toolHint').textContent='接続元の要素をクリックしてください';
  showStatus('接続線の作成モード');
}

function handleConnect(el){
  if(!connectFrom){
    connectFrom=el;
    selectOnly(el);
    document.getElementById('toolHint').textContent='接続先の要素をクリックしてください';
    return;
  }
  if(connectFrom===el){
    connectFrom=null;
    return;
  }
  connections.push({
    from:connectFrom.id,
    to:el.id,
    style:'solid',
    shape:'直線',
    start:'none',
    end:'none'
  });
  connectMode=false;
  connectFrom=null;
  document.getElementById('toolHint').textContent='接続線を作成しました';
  drawConnections();
  showStatus('接続線を作成しました');
}

function anchorPoint(el,side){
  const x=el.offsetLeft,y=el.offsetTop,w=el.offsetWidth,h=el.offsetHeight;
  if(side==='left')return{x:x,y:y+h/2};
  if(side==='right')return{x:x+w,y:y+h/2};
  if(side==='top')return{x:x+w/2,y:y};
  return{x:x+w/2,y:y+h};
}

function bestAnchors(a,b){
  const ax=a.offsetLeft+a.offsetWidth/2;
  const ay=a.offsetTop+a.offsetHeight/2;
  const bx=b.offsetLeft+b.offsetWidth/2;
  const by=b.offsetTop+b.offsetHeight/2;
  if(Math.abs(bx-ax)>=Math.abs(by-ay)){
    return bx>=ax?[anchorPoint(a,'right'),anchorPoint(b,'left')]:
                  [anchorPoint(a,'left'),anchorPoint(b,'right')];
  }
  return by>=ay?[anchorPoint(a,'bottom'),anchorPoint(b,'top')]:
                [anchorPoint(a,'top'),anchorPoint(b,'bottom')];
}

function drawConnections(){
  const svg=document.getElementById('connections');
  svg.innerHTML='';
  connections.forEach((c,i)=>{
    const a=document.getElementById(c.from);
    const b=document.getElementById(c.to);
    if(!a||!b)return;

    const [p1,p2]=bestAnchors(a,b);
    const selectedLine=c.selected;
    let d='';

    if(c.shape==='折れ線'){
      const mx=(p1.x+p2.x)/2;
      d=`M ${p1.x} ${p1.y} L ${mx} ${p1.y} L ${mx} ${p2.y} L ${p2.x} ${p2.y}`;
    }else if(c.shape==='波線'){
      const mx=(p1.x+p2.x)/2;
      const amp=8;
      d=`M ${p1.x} ${p1.y} C ${p1.x+30} ${p1.y-amp},${mx-30} ${p1.y+amp},${mx} ${p1.y} C ${mx+30} ${p1.y-amp},${p2.x-30} ${p2.y+amp},${p2.x} ${p2.y}`;
    }else{
      d=`M ${p1.x} ${p1.y} L ${p2.x} ${p2.y}`;
    }

    const hit=document.createElementNS('http://www.w3.org/2000/svg','path');
    hit.setAttribute('d',d);
    hit.setAttribute('fill','none');
    hit.setAttribute('stroke','transparent');
    hit.setAttribute('stroke-width','16');
    hit.style.pointerEvents='stroke';
    hit.style.cursor='pointer';
    hit.onclick=e=>{e.stopPropagation();selectConnection(i)};
    svg.appendChild(hit);

    const line=document.createElementNS('http://www.w3.org/2000/svg','path');
    line.setAttribute('d',d);
    line.setAttribute('fill','none');
    line.setAttribute('stroke',selectedLine?'#ff9800':'#90a4ae');
    line.setAttribute('stroke-width',selectedLine?'3':'2');
    if(c.style==='dashed')line.setAttribute('stroke-dasharray','7 5');
    if(c.style==='dotted')line.setAttribute('stroke-dasharray','2 5');
    if(c.style==='dashdot')line.setAttribute('stroke-dasharray','9 4 2 4');
    line.style.pointerEvents='none';
    svg.appendChild(line);

    if(c.end!=='none'){
      const marker=document.createElementNS('http://www.w3.org/2000/svg','polygon');
      marker.setAttribute('points',`${p2.x},${p2.y} ${p2.x-9},${p2.y-5} ${p2.x-9},${p2.y+5}`);
      marker.setAttribute('fill',selectedLine?'#ff9800':'#90a4ae');
      svg.appendChild(marker);
    }
  });
}

function selectConnection(i){
  connections.forEach(c=>c.selected=false);
  connections[i].selected=true;
  selected=[];
  getElements().forEach(e=>e.classList.remove('selected'));
  renderConnectionProperties(i);
}

function renderConnectionProperties(i){
  const c=connections[i];
  document.getElementById('sideTitle').textContent='接続線';
  document.getElementById('toolHint').textContent='接続線を選択中';
  document.getElementById('sideBody').innerHTML=`
    <div class="prop">
      <div class="prop-title">線形</div>
      <select style="width:100%;padding:5px" onchange="connectionShape(${i},this.value)">
        <option ${c.shape==='直線'?'selected':''}>直線</option>
        <option ${c.shape==='折れ線'?'selected':''}>折れ線</option>
        <option ${c.shape==='波線'?'selected':''}>波線</option>
      </select>
    </div>
    <div class="prop">
      <div class="prop-title">線種</div>
      <select style="width:100%;padding:5px" onchange="connectionStyle(${i},this.value)">
        <option value="solid" ${c.style==='solid'?'selected':''}>実線</option>
        <option value="dotted" ${c.style==='dotted'?'selected':''}>点線</option>
        <option value="dashed" ${c.style==='dashed'?'selected':''}>破線</option>
        <option value="dashdot" ${c.style==='dashdot'?'selected':''}>一点鎖線</option>
      </select>
    </div>
    <div class="prop">
      <div class="prop-title">始点</div>
      <select style="width:100%;padding:5px" onchange="connectionStart(${i},this.value)">
        <option value="none">なし</option><option value="arrow">矢印</option>
      </select>
    </div>
    <div class="prop">
      <div class="prop-title">終点</div>
      <select style="width:100%;padding:5px" onchange="connectionEnd(${i},this.value)">
        <option value="none">なし</option><option value="arrow">矢印</option>
      </select>
    </div>
    <div class="prop">
      <div class="prop-title">線の色</div>
      ${colorPickerConnection(i)}
    </div>
    <button onclick="deleteConnection(${i})" style="width:100%;padding:7px;border:1px solid #e57373;background:#fff;color:#c62828;border-radius:4px">この接続線を削除</button>`;
}

function colorPickerConnection(i){
  const colors=['#000000','#e53935','#fb8c00','#fdd835','#43a047','#00acc1','#1e88e5','#3949ab','#8e24aa','#6d4c41','#78909c','#ffffff'];
  return '<div class="colors">'+colors.map(c=>`<div class="color" style="background:${c}" onclick="connectionColor(${i},'${c}')"></div>`).join('')+'</div>';
}
function connectionShape(i,v){connections[i].shape=v;drawConnections()}
function connectionStyle(i,v){connections[i].style=v;drawConnections()}
function connectionStart(i,v){connections[i].start=v;drawConnections()}
function connectionEnd(i,v){connections[i].end=v;drawConnections()}
function connectionColor(i,v){connections[i].color=v;drawConnections()}
function deleteConnection(i){connections.splice(i,1);clearProperties();drawConnections()}

function renderTimeline(){
  ['comment','highlight','zoom','skip'].forEach(type=>{
    const track=document.getElementById('track-'+type);
    track.innerHTML='';
    const els=getElements().filter(e=>e.dataset.type===type);
    els.forEach((el,index)=>{
      const bar=document.createElement('div');
      bar.className='bar '+(selected.includes(el)?'selected':'');
      const width=timelineWidth()-120;
      const s=Number(el.dataset.start),en=Number(el.dataset.end);
      bar.style.left=(s/DURATION*width)+'px';
      bar.style.width=Math.max(18,(en-s)/DURATION*width)+'px';
      const colors={comment:'#607d8b',highlight:'#ef5350',zoom:'#66bb6a',skip:'#90a4ae'};
      bar.style.background=colors[type];
      bar.textContent=type==='comment'?'コメント':type==='highlight'?'強調枠':type==='zoom'?'拡大枠':'スキップ';
      bar.onclick=e=>{
        e.stopPropagation();
        if(e.shiftKey)toggleSelect(el);else selectOnly(el);
      };
      bar.onmousedown=e=>{
        if(e.button!==0)return;
        e.stopPropagation();
        const r=track.getBoundingClientRect();
        const startX=e.clientX;
        const original=Number(el.dataset.start);
        const widthTime=en-s;
        const pxPerSec=width/DURATION;
        function move(ev){
          const delta=(ev.clientX-startX)/pxPerSec;
          let ns=Math.max(0,Math.min(DURATION-widthTime,original+delta));
          el.dataset.start=ns.toFixed(1);
          el.dataset.end=(ns+widthTime).toFixed(1);
          renderTimeline();
          updateVisible();
        }
        function up(){
          document.removeEventListener('mousemove',move);
          document.removeEventListener('mouseup',up);
        }
        document.addEventListener('mousemove',move);
        document.addEventListener('mouseup',up);
      };
      track.appendChild(bar);
    });
  });
}

timeline.addEventListener('click',e=>{
  if(e.target.closest('.bar'))return;
  const r=timeline.getBoundingClientRect();
  const x=e.clientX-r.left-110;
  const width=timelineWidth()-120;
  if(x>=0&&x<=width)setTime(x/width*DURATION);
});

function setTime(t){
  currentTime=Math.max(0,Math.min(DURATION,t));
  updatePlayhead();
  updateVisible();
}

function updatePlayhead(){
  const width=timelineWidth()-120;
  const x=110+currentTime/DURATION*width;
  document.getElementById('playhead').style.left=x+'px';
  document.getElementById('currentTime').textContent=fmt(currentTime)+' / '+fmt(DURATION);
}

function updateVisible(){
  getElements().forEach(el=>{
    if(el.dataset.type==='skip')return;
    const s=Number(el.dataset.start),e=Number(el.dataset.end);
    el.style.opacity=currentTime>=s&&currentTime<=e?'1':'.35';
  });
}

function togglePlay(){
  playing=!playing;
  document.getElementById('playBtn').textContent=playing?'❚❚ 一時停止':'▶ 再生';
  if(playing){
    const start=performance.now();
    const base=currentTime;
    function step(now){
      if(!playing)return;
      currentTime=base+(now-start)/1000;
      if(currentTime>=DURATION){
        currentTime=DURATION;
        playing=false;
        document.getElementById('playBtn').textContent='▶ 再生';
      }
      updatePlayhead();
      updateVisible();
      if(playing)requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
}

function deleteSelected(){
  if(selected.length){
    selected.forEach(e=>e.remove());
    connections=connections.filter(c=>document.getElementById(c.from)&&document.getElementById(c.to));
    selected=[];
    clearProperties();
    drawConnections();
    renderTimeline();
    showStatus('選択した要素を削除しました');
  }
}

function stageContext(e){
  e.preventDefault();
  closeContext();
  showContext(e.clientX,e.clientY,[
    ['＋ コメント',()=>addElement('comment')],
    ['＋ 強調枠',()=>addElement('highlight')],
    ['＋ 拡大枠',()=>addElement('zoom')],
    ['＋ スキップ',()=>addElement('skip')]
  ]);
}

function elementContext(e,el){
  e.preventDefault();
  e.stopPropagation();
  selectOnly(el);
  closeContext();
  showContext(e.clientX,e.clientY,[
    ['属性を変更',()=>renderProperties(el)],
    ['削除',()=>{selected=[el];deleteSelected()}]
  ]);
}

function showContext(x,y,items){
  const c=document.getElementById('context');
  c.innerHTML='';
  items.forEach(([label,fn])=>{
    const b=document.createElement('button');
    b.textContent=label;
    b.onclick=()=>{closeContext();fn()};
    c.appendChild(b);
  });
  c.style.left=Math.min(x,innerWidth-240)+'px';
  c.style.top=Math.min(y,innerHeight-160)+'px';
  c.style.display='block';
}

function closeContext(){document.getElementById('context').style.display='none'}
document.addEventListener('click',closeContext);

function openVideoModal(){document.getElementById('videoModal').style.display='flex'}
function closeVideoModal(){document.getElementById('videoModal').style.display='none'}
function chooseVideo(name){
  document.getElementById('workName').textContent=name+' / 新しい編集作業';
  closeVideoModal();
  showStatus('オリジナル動画を選択しました');
}

function saveWork(){
  showStatus('現在の編集作業を保存しました');
}

function finishWork(){
  if(confirm('編集作業を終了します。現在の編集内容を保存しますか？')){
    saveWork();
  }
  showStatus('初期画面へ戻ります');
}

document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){
    connectMode=false;
    connectFrom=null;
    closeContext();
    document.getElementById('toolHint').textContent='要素を選択すると操作メニューを表示します';
  }
});

updateTimelineWidth();
renderRuler();
renderTimeline();
updatePlayhead();
updateVisible();

connections.push({
  from:'e1',to:'e2',style:'solid',shape:'直線',start:'none',end:'none',selected:false
});
drawConnections();
</script>

</body>
</html>

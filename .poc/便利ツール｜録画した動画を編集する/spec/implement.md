<?php
declare(strict_types=1);

const DATA_FILE = __DIR__ . '/projects.json';

function projects(): array {
    if (!is_file(DATA_FILE)) return [];
    $v = json_decode((string)file_get_contents(DATA_FILE), true);
    return is_array($v) ? $v : [];
}
function saveProjects(array $v): bool {
    return (bool)file_put_contents(
        DATA_FILE,
        json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}
function jsonOut(mixed $v, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($v, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $data = json_decode((string)($_POST['data'] ?? ''), true);
        if ($name === '' || !is_array($data)) jsonOut(['ok'=>false,'error'=>'保存データが不正です'],400);

        $all = projects();
        $all[$name] = ['savedAt'=>date('c'),'project'=>$data];
        if (!saveProjects($all)) jsonOut(['ok'=>false,'error'=>'保存できませんでした'],500);
        jsonOut(['ok'=>true]);
    }

    if ($action === 'list') jsonOut(projects());

    if ($action === 'load') {
        $name = (string)($_POST['name'] ?? '');
        $all = projects();
        if (!isset($all[$name])) jsonOut(['ok'=>false,'error'=>'保存データがありません'],404);
        jsonOut(['ok'=>true,'data'=>$all[$name]['project']]);
    }

    if ($action === 'delete') {
        $name = (string)($_POST['name'] ?? '');
        $all = projects();
        unset($all[$name]);
        saveProjects($all);
        jsonOut(['ok'=>true]);
    }

    jsonOut(['ok'=>false,'error'=>'unknown action'],400);
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画注釈編集</title>
<style>
*{box-sizing:border-box}
:root{
 --bg:#08111d;--panel:#111c2b;--panel2:#18263a;--line:#33445b;
 --text:#e5edf7;--muted:#91a1b6;--blue:#2563eb;--red:#ef4444;
 --label:105px;--track:50px
}
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:var(--bg);color:var(--text);
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
.hidden{display:none!important}
.app{height:100vh;display:flex;flex-direction:column}
.top{
height:52px;display:flex;align-items:center;gap:7px;padding:7px 10px;
background:#060d17;border-bottom:1px solid var(--line);flex:none
}
.brand{font-weight:700;margin-right:8px;white-space:nowrap}
.btn{
border:1px solid #40536c;background:#243247;color:var(--text);
border-radius:6px;padding:7px 11px
}
.btn:hover:not(:disabled){background:#30435d}
.primary{background:var(--blue);border-color:#3b82f6}
.danger{background:#7f1d1d;border-color:#991b1b}
.small{font-size:12px;padding:5px 8px}
.status{margin-left:auto;color:var(--muted);font-size:12px}
.file{display:none}

.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{
min-height:260px;flex:1;background:#03070c;padding:12px;
display:flex;align-items:center;justify-content:center;overflow:hidden
}
.stage{position:relative;max-width:100%;max-height:100%;line-height:0;overflow:hidden}
#video{display:block;max-width:100%;max-height:100%;background:#000}
#overlay{position:absolute;inset:0;overflow:hidden}
.svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible;z-index:4}
.empty{
position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
flex-direction:column;color:#64748b;line-height:1.8;pointer-events:none
}
.empty strong{font-size:18px;color:#94a3b8}

.el{
position:absolute;min-width:15px;min-height:15px;z-index:8;
user-select:none;touch-action:none
}
.el.sel{z-index:20}
.elbody{width:100%;height:100%}
.el.sel .elbody{outline:2px solid #60a5fa;outline-offset:2px}
.comment{
display:flex;align-items:center;justify-content:center;text-align:center;
padding:5px 8px;background:#000b;border:1px solid currentColor;
border-radius:4px;line-height:1.2;white-space:pre-wrap;overflow:hidden
}
.highlight{border:3px solid currentColor}
.circle{border-radius:50%}
.ellipse{border-radius:50%}
.skip{
display:flex;align-items:center;justify-content:center;
border:2px dashed #fb923c;background:#f9731626;color:#fdba74;
font-weight:700;font-size:12px;border-radius:4px
}
.zoomarea{
border:2px solid #38bdf8;background:#38bdf822;
box-shadow:0 0 0 1px #0ea5e955;
display:flex;align-items:flex-start;justify-content:flex-start
}
.zoomarea:after{content:"拡大範囲";font-size:11px;background:#0284c7;color:#fff;padding:2px 5px}

.rh{
display:none;position:absolute;width:9px;height:9px;background:#fff;
border:1px solid #2563eb;border-radius:2px;z-index:30
}
.sel .rh{display:block}
.nw{left:-5px;top:-5px;cursor:nwse-resize}.n{left:50%;top:-5px;transform:translateX(-50%);cursor:ns-resize}
.ne{right:-5px;top:-5px;cursor:nesw-resize}.e{right:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}
.se{right:-5px;bottom:-5px;cursor:nwse-resize}.s{left:50%;bottom:-5px;transform:translateX(-50%);cursor:ns-resize}
.sw{left:-5px;bottom:-5px;cursor:nesw-resize}.w{left:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}

.bottom{height:330px;flex:none;border-top:1px solid var(--line);background:#0e1724}
.toolbar{
height:43px;display:flex;align-items:center;gap:7px;padding:5px 8px;
border-bottom:1px solid var(--line)
}
.readout{min-width:145px;font-variant-numeric:tabular-nums;color:#dbeafe}
.info{font-size:11px;color:var(--muted)}
.scale{margin-left:auto;display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12px}
.scale input{width:120px}

.scroll{height:calc(100% - 43px);overflow:auto}
.content{position:relative;min-width:760px}
.axis{height:34px;display:flex;position:sticky;top:0;z-index:50;background:#131d29;border-bottom:1px solid var(--line)}
.axislabel{
width:var(--label);min-width:var(--label);padding-left:8px;display:flex;align-items:center;
border-right:1px solid var(--line);position:sticky;left:0;z-index:60;background:#131d29;font-size:12px
}
.axistrack{position:relative;flex:1}
.tick{
position:absolute;top:0;height:100%;border-left:1px solid #334155;
padding:5px 0 0 3px;font-size:10px;color:#64748b;pointer-events:none;white-space:nowrap
}
.track{height:var(--track);display:flex;border-bottom:1px solid #263445}
.tracklabel{
width:var(--label);min-width:var(--label);display:flex;align-items:center;gap:6px;
padding:0 8px;background:#111b27;border-right:1px solid var(--line);
position:sticky;left:0;z-index:20;font-size:12px
}
.dot{width:8px;height:8px;border-radius:50%}
.lane{position:relative;flex:1}
.bar{
position:absolute;top:8px;height:34px;min-width:10px;border:1px solid currentColor;
border-radius:5px;display:flex;align-items:center;cursor:grab;user-select:none;touch-action:none
}
.bar.sel{box-shadow:0 0 0 2px #60a5fa}
.bartext{padding:0 7px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;pointer-events:none}
.bh{position:absolute;top:0;width:10px;height:100%;z-index:3}
.bh.l{left:-5px;cursor:ew-resize}.bh.r{right:-5px;cursor:ew-resize}

.playhead{
position:absolute;top:34px;bottom:0;width:3px;background:var(--red);z-index:80;cursor:ew-resize
}
.playhead:before{
content:"";position:absolute;top:-1px;left:-5px;width:13px;height:13px;background:var(--red);
clip-path:polygon(0 0,100% 0,50% 100%)
}
.playlabel{
position:absolute;top:12px;left:6px;background:var(--red);padding:2px 4px;
border-radius:3px;font-size:10px;white-space:nowrap;color:#fff
}

.menu{
position:fixed;display:none;z-index:1000;min-width:210px;padding:5px;
background:#172235;border:1px solid #475569;border-radius:6px;
box-shadow:0 12px 30px #0008
}
.menu button{
display:block;width:100%;border:0;background:none;color:var(--text);
text-align:left;padding:8px;border-radius:4px
}
.menu button:hover{background:#293a52}
.menu hr{border:0;border-top:1px solid #334155;margin:4px 0}

.back{
position:fixed;inset:0;z-index:2000;display:none;align-items:center;justify-content:center;
padding:20px;background:#000a
}
.modal{
width:min(570px,100%);max-height:90vh;display:flex;flex-direction:column;
background:#182231;border:1px solid #475569;border-radius:8px;overflow:hidden
}
.head,.foot{padding:12px 15px;border-color:var(--line)}
.head{display:flex;justify-content:space-between;border-bottom:1px solid var(--line)}
.body{padding:15px;display:grid;gap:10px;overflow:auto}
.foot{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--line)}
.field{display:grid;gap:5px}.field label{font-size:12px;color:var(--muted)}
.field input,.field select,.field textarea{
width:100%;padding:7px;background:#0f1722;color:var(--text);
border:1px solid #40516a;border-radius:5px
}
.field textarea{min-height:80px;resize:vertical}
.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.colors{display:grid;grid-template-columns:repeat(8,1fr);gap:5px}
.color{height:27px;border:2px solid transparent;border-radius:4px}
.color.active{border-color:#fff;box-shadow:0 0 0 1px #60a5fa}
.list{padding:0!important;gap:0!important}
.row{display:flex;align-items:center;gap:8px;padding:10px;border-bottom:1px solid #334155}
.rowinfo{min-width:0;flex:1}.rowname{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rowmeta{font-size:11px;color:#64748b}
.toast{
position:fixed;right:15px;bottom:15px;z-index:3000;display:none;
padding:10px 14px;background:#1e293b;border:1px solid #475569;border-radius:6px
}
.line-sample{height:18px;border-bottom:3px solid #facc15}
@media(max-width:800px){:root{--label:88px}.brand{display:none}.bottom{height:300px}}
</style>
</head>
<body>
<div class="app">
<header class="top">
 <div class="brand">動画注釈編集</div>
 <button class="btn primary" id="open">動画を読み込む</button>
 <input class="file" id="file" type="file" accept="video/*">
 <button class="btn" id="new">新規</button>
 <button class="btn" id="save" disabled>保存</button>
 <button class="btn" id="saved">保存データ</button>
 <span class="status" id="status">動画を読み込んでください</span>
</header>

<main class="main">
<section class="video-area">
 <div class="stage" id="stage">
  <video id="video" playsinline preload="metadata"></video>
  <div id="overlay"><svg class="svg" id="svg"></svg></div>
  <div class="empty" id="empty"><strong>動画を読み込んでください</strong><span>動画上で右クリックすると要素を追加できます</span></div>
 </div>
</section>

<section class="bottom">
 <div class="toolbar">
  <button class="btn small" id="play" disabled>▶</button>
  <button class="btn small" id="stop" disabled>■</button>
  <button class="btn small" id="connect" disabled>接続</button>
  <button class="btn small" id="disconnect" disabled>接続解除</button>
  <span class="info" id="info"></span>
  <span class="readout" id="readout">00:00.000 / 00:00.000</span>
  <div class="scale">時間表示 <input id="scale" type="range" min="1" max="5" step=".1" value="1"><span id="scalev">1.0×</span></div>
 </div>
 <div class="scroll" id="scroll">
  <div class="content" id="content">
   <div class="axis"><div class="axislabel">時間</div><div class="axistrack" id="axis"></div></div>
   <div id="tracks"></div>
   <div class="playhead" id="playhead"><span class="playlabel" id="playlabel">00:00.000</span></div>
  </div>
 </div>
</section>
</main>
</div>

<div class="menu" id="menu">
 <button data-add="comment">＋ コメント</button>
 <button data-add="highlight">＋ 強調枠</button>
 <button data-add="zoom">＋ 動画拡大範囲</button>
 <button data-add="skip">＋ スキップ</button>
 <hr>
 <button id="connectMenu">選択した2要素を接続</button>
 <button id="edit">編集</button>
 <button id="delete">削除</button>
</div>

<div class="back" id="elementBack">
<div class="modal">
 <div class="head"><strong id="modalTitle">要素編集</strong><button class="btn small" id="close">閉じる</button></div>
 <div class="body">
  <div class="field"><label>名前</label><input id="name"></div>
  <div class="field" id="textBox"><label>コメント</label><textarea id="text"></textarea></div>
  <div class="field" id="shapeBox"><label>強調枠</label><select id="shape"><option value="square">四角</option><option value="circle">丸</option><option value="ellipse">楕円</option></select></div>
  <div class="two">
   <div class="field"><label>開始</label><input id="start" type="number" min="0" step=".001"></div>
   <div class="field"><label>終了</label><input id="end" type="number" min="0" step=".001"></div>
  </div>
  <div class="two">
   <div class="field"><label>横位置 %</label><input id="x" type="number" min="0" max="99" step=".1"></div>
   <div class="field"><label>縦位置 %</label><input id="y" type="number" min="0" max="99" step=".1"></div>
  </div>
  <div class="two">
   <div class="field"><label>幅 %</label><input id="w" type="number" min=".5" max="100" step=".1"></div>
   <div class="field"><label>高さ %</label><input id="h" type="number" min=".5" max="100" step=".1"></div>
  </div>
  <div class="field" id="fontBox"><label>文字サイズ</label><input id="font" type="number" min="8" max="100"></div>
  <div class="field"><label>色</label><div class="colors" id="colors"></div></div>
  <div class="field" id="targetBox"><label>接続先</label><select id="target"><option value="">接続しない</option></select></div>
 </div>
 <div class="foot">
  <button class="btn danger" id="delElement">削除</button>
  <button class="btn" id="cancel">キャンセル</button>
  <button class="btn primary" id="apply">保存</button>
 </div>
</div>
</div>

<div class="back" id="lineBack">
<div class="modal">
 <div class="head"><strong>接続線の設定</strong><button class="btn small" id="lineClose">閉じる</button></div>
 <div class="body">
  <div class="field"><label>始点</label><select id="fromPoint"><option value="right">右</option><option value="left">左</option><option value="top">上</option><option value="bottom">下</option><option value="center">中央</option></select></div>
  <div class="field"><label>終点</label><select id="toPoint"><option value="left">左</option><option value="right">右</option><option value="top">上</option><option value="bottom">下</option><option value="center">中央</option></select></div>
  <div class="two">
   <div class="field"><label>色</label><input id="lineColor" type="color"></div>
   <div class="field"><label>太さ</label><input id="lineWidth" type="number" min="1" max="12" step="1"></div>
  </div>
  <div class="field"><label>線種</label><select id="lineDash"><option value="">実線</option><option value="7 5">破線</option><option value="2 4">点線</option></select></div>
  <div class="field"><label>接続線の向き</label><select id="lineCurve"><option value="curve">曲線</option><option value="straight">直線</option></select></div>
 </div>
 <div class="foot"><button class="btn" id="lineCancel">キャンセル</button><button class="btn primary" id="lineApply">保存</button></div>
</div>
</div>

<div class="back" id="savedBack">
<div class="modal">
 <div class="head"><strong>保存したプロジェクト</strong><button class="btn small" id="savedClose">閉じる</button></div>
 <div class="body list" id="savedList"></div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $=id=>document.getElementById(id);
const ui={
 video:$('video'),stage:$('stage'),overlay:$('overlay'),svg:$('svg'),empty:$('empty'),
 file:$('file'),open:$('open'),new:$('new'),save:$('save'),saved:$('saved'),status:$('status'),
 play:$('play'),stop:$('stop'),connect:$('connect'),disconnect:$('disconnect'),
 info:$('info'),readout:$('readout'),scale:$('scale'),scalev:$('scalev'),
 scroll:$('scroll'),content:$('content'),axis:$('axis'),tracks:$('tracks'),
 playhead:$('playhead'),playlabel:$('playlabel'),menu:$('menu'),
 back:$('elementBack'),title:$('modalTitle'),name:$('name'),text:$('text'),
 shape:$('shape'),start:$('start'),end:$('end'),x:$('x'),y:$('y'),w:$('w'),h:$('h'),
 font:$('font'),colors:$('colors'),target:$('target'),textBox:$('textBox'),
 shapeBox:$('shapeBox'),fontBox:$('fontBox'),targetBox:$('targetBox'),
 close:$('close'),apply:$('apply'),cancel:$('cancel'),delElement:$('delElement'),
 lineBack:$('lineBack'),fromPoint:$('fromPoint'),toPoint:$('toPoint'),
 lineColor:$('lineColor'),lineWidth:$('lineWidth'),lineDash:$('lineDash'),
 lineCurve:$('lineCurve'),lineClose:$('lineClose'),lineCancel:$('lineCancel'),
 lineApply:$('lineApply'),savedBack:$('savedBack'),savedList:$('savedList'),
 savedClose:$('savedClose'),toast:$('toast')
};

const state={
 project:null,url:'',duration:0,time:0,selected:new Set(),
 editId:null,lineKey:null,menuId:null,menuXY:null,drag:null,ph:false
};
const colors=['#60a5fa','#22c55e','#f59e0b','#ef4444','#c084fc','#14b8a6','#f97316','#e879f9','#38bdf8','#a3e635','#fb7185','#facc15'];
const uid=()=>crypto.randomUUID?.()||'e-'+Date.now()+'-'+Math.random().toString(36).slice(2);
const get=id=>state.project?.elements.find(e=>e.id===id);
const fmt=n=>{n=Math.max(0,+n||0);return `${String(Math.floor(n/60)).padStart(2,'0')}:${String(Math.floor(n%60)).padStart(2,'0')}.${String(Math.floor(n%1*1000)).padStart(3,'0')}`};
const clamp=(n,a,b)=>Math.max(a,Math.min(b,n));
const colorOf=t=>({comment:'#60a5fa',highlight:'#22c55e',zoom:'#38bdf8',skip:'#f97316'}[t]||'#60a5fa');
const toast=(s,err=false)=>{ui.status.textContent=s;ui.toast.textContent=s;ui.toast.style.display='block';ui.toast.style.borderColor=err?'#ef4444':'#475569';clearTimeout(toast.t);toast.t=setTimeout(()=>ui.toast.style.display='none',2200)};
const dirty=()=>{if(state.project)state.project.dirty=true;ui.status.textContent='変更あり'};
const clean=()=>{if(state.project){state.project.dirty=false;ui.status.textContent=`編集中：${state.project.name}`}};
const ok=()=>!!state.project&&state.duration>0;

function emptyProject(name='新規プロジェクト'){
 return {version:2,name,videoName:'',duration:state.duration,elements:[]};
}
function validProject(p){
 if(!p||typeof p!=='object')throw Error('プロジェクトが不正です');
 p.elements=Array.isArray(p.elements)?p.elements:[];
 p.elements=p.elements.map(e=>{
  const type=['comment','highlight','zoom','skip'].includes(e.type)?e.type:'comment';
  const start=clamp(+e.start||0,0,state.duration);
  return {
   id:String(e.id||uid()),type,name:String(e.name||type),text:String(e.text||''),
   start,end:clamp(+e.end||Math.min(state.duration,start+3),start+.05,state.duration),
   x:clamp(+e.x||0,0,99),y:clamp(+e.y||0,0,99),
   w:clamp(+e.w||25,.5,100),h:clamp(+e.h||20,.5,100),
   color:/^#[0-9a-f]{6}$/i.test(e.color||'')?e.color:colorOf(type),
   fontSize:clamp(+e.fontSize||28,8,100),shape:['square','circle','ellipse'].includes(e.shape)?e.shape:'square',
   target:String(e.target||''),
   from:e.from||'right',to:e.to||'left',lineColor:e.lineColor||'#facc15',
   lineWidth:clamp(+e.lineWidth||2,1,12),lineDash:e.lineDash||'',lineCurve:e.lineCurve||'curve'
  };
 });
 return p;
}

function loadVideo(file){
 if(!file?.type.startsWith('video/'))return toast('動画ファイルを選択してください',true);
 if(state.url)URL.revokeObjectURL(state.url);
 state.url=URL.createObjectURL(file);state.project=emptyProject(file.name);state.project.videoName=file.name;
 state.duration=0;state.time=0;state.selected.clear();ui.video.src=state.url;ui.video.load();
 ui.status.textContent='動画を読み込んでいます…';
}
function reset(){
 ui.video.pause();if(state.url)URL.revokeObjectURL(state.url);
 state.url='';state.project=null;state.duration=state.time=0;state.selected.clear();
 ui.video.removeAttribute('src');ui.video.load();ui.empty.style.display='flex';
 ui.play.disabled=ui.stop.disabled=ui.save.disabled=true;render();
 ui.status.textContent='動画を読み込んでください';
}

function range(){
 const span=state.duration/(+ui.scale.value||1),start=clamp(state.time-span/2,0,Math.max(0,state.duration-span));
 return {start,end:start+span};
}
function pct(t){const r=range();return r.end===r.start?0:clamp((t-r.start)/(r.end-r.start)*100,0,100)}
function timeAt(x){
 const r=ui.axis.getBoundingClientRect(),q=range();
 return r.width?clamp(q.start+(x-r.left)/r.width*(q.end-q.start),0,state.duration):state.time;
}
function renderAxis(){
 ui.axis.innerHTML='';if(!state.duration)return;
 const q=range(),span=q.end-q.start,width=Math.max(ui.axis.clientWidth,1);
 const approx=span/Math.max(width/80,1),steps=[.1,.25,.5,1,2,5,10,15,30,60,120,300,600];
 const step=steps.find(v=>v>=approx)||600;
 for(let t=Math.ceil(q.start/step)*step;t<=q.end+.001;t+=step){
  const n=document.createElement('div');n.className='tick';n.style.left=((t-q.start)/span*100)+'%';
  n.textContent=fmt(t);ui.axis.append(n);
 }
}

function resizeHandles(){return ['nw','n','ne','e','se','s','sw','w'].map(x=>`<i class="rh ${x}" data-r="${x}"></i>`).join('')}

function renderOverlay(){
 ui.overlay.querySelectorAll('.el').forEach(n=>n.remove());
 if(!ok())return;

 state.project.elements.filter(e=>state.time>=e.start&&state.time<e.end).forEach(e=>{
  const n=document.createElement('div');n.className='el'+(state.selected.has(e.id)?' sel':'');
  n.dataset.id=e.id;n.style.cssText=`left:${e.x}%;top:${e.y}%;width:${e.w}%;height:${e.h}%;color:${e.color}`;
  const b=document.createElement('div');b.className='elbody '+(
   e.type==='comment'?'comment':e.type==='highlight'?'highlight '+e.shape:e.type==='zoom'?'zoomarea':'skip'
  );
  if(e.type==='comment'){b.textContent=e.text||e.name;b.style.fontSize=e.fontSize+'px'}
  if(e.type==='skip')b.textContent='スキップ';
  n.append(b);if(state.selected.has(e.id))n.insertAdjacentHTML('beforeend',resizeHandles());
  n.onpointerdown=ev=>elementDrag(ev,e.id);
  n.ondblclick=ev=>{ev.stopPropagation();openElement(e.id)};
  n.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();select(e.id,ev.ctrlKey||ev.metaKey);menu(ev.clientX,ev.clientY,e.id)};
  ui.overlay.append(n);
 });
 renderLines();
}

function point(rect,pos,base){
 const x=rect.left-base.left,y=rect.top-base.top;
 if(pos==='left')return{x,y:y+rect.height/2};
 if(pos==='right')return{x:x+rect.width,y:y+rect.height/2};
 if(pos==='top')return{x:x+rect.width/2,y};
 if(pos==='bottom')return{x:x+rect.width/2,y:y+rect.height};
 return{x:x+rect.width/2,y:y+rect.height/2};
}
function renderLines(){
 ui.svg.innerHTML='';if(!ok())return;
 const base=ui.overlay.getBoundingClientRect();
 state.project.elements.filter(e=>e.type==='comment'&&e.target).forEach(e=>{
  const t=get(e.target),a=ui.overlay.querySelector(`[data-id="${CSS.escape(e.id)}"]`),b=ui.overlay.querySelector(`[data-id="${CSS.escape(t?.id||'')}"]`);
  if(!t||t.type!=='highlight'||!a||!b)return;
  const p=point(a.getBoundingClientRect(),e.from,base),q=point(b.getBoundingClientRect(),e.to,base);
  const path=document.createElementNS('http://www.w3.org/2000/svg','path');
  let d;
  if(e.lineCurve==='straight')d=`M${p.x} ${p.y} L${q.x} ${q.y}`;
  else{
   const dx=Math.max(25,Math.abs(q.x-p.x)*.35),dy=Math.max(25,Math.abs(q.y-p.y)*.35);
   let c1={x:p.x,y:p.y},c2={x:q.x,y:q.y};
   if(e.from==='right')c1.x+=dx;if(e.from==='left')c1.x-=dx;if(e.from==='top')c1.y-=dy;if(e.from==='bottom')c1.y+=dy;
   if(e.to==='right')c2.x+=dx;if(e.to==='left')c2.x-=dx;if(e.to==='top')c2.y-=dy;if(e.to==='bottom')c2.y+=dy;
   d=`M${p.x} ${p.y} C${c1.x} ${c1.y},${c2.x} ${c2.y},${q.x} ${q.y}`;
  }
  path.setAttribute('d',d);path.style.cssText=`fill:none;stroke:${e.lineColor};stroke-width:${e.lineWidth};stroke-dasharray:${e.lineDash};pointer-events:stroke;cursor:pointer`;
  path.dataset.line=e.id;
  path.onclick=ev=>{ev.stopPropagation();openLine(e.id)};
  path.oncontextmenu=ev=>{ev.preventDefault();ev.stopPropagation();openLine(e.id)};
  ui.svg.append(path);
 });
}

function renderTimeline(){
 ui.tracks.innerHTML='';if(!ok()){renderAxis();return}
 const types=[['comment','コメント'],['highlight','強調枠'],['zoom','動画拡大'],['skip','スキップ']];
 types.forEach(([type,label])=>{
  const row=document.createElement('div');row.className='track';
  const head=document.createElement('div');head.className='tracklabel';
  const dot=document.createElement('span');dot.className='dot';dot.style.background=colorOf(type);
  head.append(dot,label);
  const lane=document.createElement('div');lane.className='lane';
  state.project.elements.filter(e=>e.type===type).forEach(e=>{
   const b=document.createElement('div');b.className='bar'+(state.selected.has(e.id)?' sel':'');
   b.dataset.id=e.id;b.style.left=pct(e.start)+'%';b.style.width=Math.max(.5,pct(e.end)-pct(e.start))+'%';
   b.style.color=e.color;b.style.background=e.color+'33';
   b.innerHTML=`<span class="bartext">${e.name} ${fmt(e.start)}～${fmt(e.end)}</span><i class="bh l" data-edge="l"></i><i class="bh r" data-edge="r"></i>`;
   b.onpointerdown=ev=>barDrag(ev,e.id);
   b.oncontextmenu=ev=>{ev.preventDefault();select(e.id,ev.ctrlKey||ev.metaKey);menu(ev.clientX,ev.clientY,e.id)};
   b.ondblclick=ev=>{ev.stopPropagation();openElement(e.id)};
   lane.append(b);
  });
  row.append(head,lane);ui.tracks.append(row);
 });
 renderAxis();
}
function renderHead(){
 if(!ok()){ui.playhead.style.display='none';return}
 ui.playhead.style.display='block';
 ui.playhead.style.left=`calc(var(--label) + (100% - var(--label))*${pct(state.time)/100})`;
 ui.playlabel.textContent=fmt(state.time);ui.readout.textContent=`${fmt(state.time)} / ${fmt(state.duration)}`;
}
function render(){
 renderTimeline();renderOverlay();renderHead();
 ui.info.textContent=state.selected.size?`${state.selected.size}個選択`:'';
 ui.connect.disabled=state.selected.size!==2;ui.disconnect.disabled=!state.selected.size;
}
function select(id,multi=false){
 if(!get(id))return;
 if(!multi)state.selected.clear();
 if(multi&&state.selected.has(id))state.selected.delete(id);else state.selected.add(id);
 render();
}
function seek(t){
 if(!ok())return;
 state.time=clamp(+t||0,0,state.duration);
 if(Math.abs((ui.video.currentTime||0)-state.time)>.002)try{ui.video.currentTime=state.time}catch(_){}
 render();
}

/* 要素の移動・サイズ変更 */
function elementDrag(ev,id){
 if(ev.button!==0)return;ev.preventDefault();ev.stopPropagation();
 const e=get(id),r=ui.overlay.getBoundingClientRect(),edge=ev.target.closest('[data-r]')?.dataset.r||'';
 if(!(ev.ctrlKey||ev.metaKey))select(id);
 else select(id,true);
 state.drag={kind:'element',id,edge,sx:ev.clientX,sy:ev.clientY,rw:r.width,rh:r.height,x:e.x,y:e.y,w:e.w,h:e.h};
 window.addEventListener('pointermove',dragMove);window.addEventListener('pointerup',dragUp,{once:true});
}
function barDrag(ev,id){
 if(ev.button!==0)return;ev.preventDefault();ev.stopPropagation();
 const e=get(id),edge=ev.target.closest('.bh')?.dataset.edge||'',lane=ev.currentTarget.parentElement,r=lane.getBoundingClientRect(),q=range();
 if(!(ev.ctrlKey||ev.metaKey))select(id);
 state.drag={kind:'bar',id,edge,sx:ev.clientX,width:r.width,span:q.end-q.start,start:e.start,end:e.end};
 window.addEventListener('pointermove',dragMove);window.addEventListener('pointerup',dragUp,{once:true});
}
function dragMove(ev){
 const d=state.drag;if(!d)return;
 const e=get(d.id);if(!e)return;
 if(d.kind==='element'){
  const dx=(ev.clientX-d.sx)/d.rw*100,dy=(ev.clientY-d.sy)/d.rh*100,min=.5;
  if(!d.edge){e.x=clamp(d.x+dx,0,100-d.w);e.y=clamp(d.y+dy,0,100-d.h)}
  else{
   let x=d.x,y=d.y,w=d.w,h=d.h;
   if(d.edge.includes('w')){x=clamp(d.x+dx,0,d.x+d.w-min);w=d.w-(x-d.x)}
   if(d.edge.includes('e'))w=clamp(d.w+dx,min,100-d.x)
   if(d.edge.includes('n')){y=clamp(d.y+dy,0,d.y+d.h-min);h=d.h-(y-d.y)}
   if(d.edge.includes('s'))h=clamp(d.h+dy,min,100-d.y)
   Object.assign(e,{x,y,w,h});
  }
 }else{
  const dt=(ev.clientX-d.sx)/d.width*d.span;
  if(!d.edge){const len=d.end-d.start;e.start=clamp(d.start+dt,0,state.duration-len);e.end=e.start+len}
  else if(d.edge==='l')e.start=clamp(d.start+dt,0,e.end-.05);
  else e.end=clamp(d.end+dt,e.start+.05,state.duration);
 }
 dirty();render();
}
function dragUp(){state.drag=null;window.removeEventListener('pointermove',dragMove);render()}

/* 右クリックメニュー */
function menu(x,y,id=null){
 state.menuId=id;ui.menu.style.display='block';
 ui.menu.style.left=Math.min(x,innerWidth-225)+'px';ui.menu.style.top=Math.min(y,innerHeight-270)+'px';
}
function closeMenu(){ui.menu.style.display='none';state.menuId=null}
ui.menu.querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>{
 const type=b.dataset.add;if(!ok())return toast('先に動画を読み込んでください',true);
 const p=state.menuXY||{x:10,y:10},s=state.time,e={
  id:uid(),type,name:{comment:'コメント',highlight:'強調枠',zoom:'動画拡大',skip:'スキップ'}[type],
  text:type==='comment'?'コメント':'',start:s,end:Math.min(state.duration,s+3),
  x:p.x,y:p.y,w:type==='comment'?30:type==='zoom'?30:25,h:type==='comment'?15:type==='zoom'?25:20,
  color:colorOf(type),fontSize:28,shape:'square',target:'',from:'right',to:'left',
  lineColor:'#facc15',lineWidth:2,lineDash:'',lineCurve:'curve'
 };
 state.project.elements.push(e);state.selected=new Set([e.id]);closeMenu();dirty();render();openElement(e.id);
});
$('edit').onclick=()=>{if(state.menuId)openElement(state.menuId);closeMenu()};
$('delete').onclick=()=>{if(state.menuId){state.selected=new Set([state.menuId]);deleteSelected()}closeMenu()};
$('connectMenu').onclick=()=>{connect();closeMenu()};

ui.stage.oncontextmenu=ev=>{
 ev.preventDefault();if(!ok())return;
 const r=ui.video.getBoundingClientRect();
 if(ev.clientX<r.left||ev.clientX>r.right||ev.clientY<r.top||ev.clientY>r.bottom)return;
 state.menuXY={x:(ev.clientX-r.left)/r.width*100,y:(ev.clientY-r.top)/r.height*100};
 menu(ev.clientX,ev.clientY);
};
ui.overlay.onclick=ev=>{if(ev.target===ui.overlay){state.selected.clear();render()}};

/* 接続 */
function connect(){
 if(state.selected.size!==2)return toast('2つの要素を選択してください',true);
 const a=[...state.selected].map(get),comment=a.find(e=>e?.type==='comment'),target=a.find(e=>e&&(e.type==='highlight'||e.type==='zoom'));
 if(!comment||!target)return toast('コメントと強調枠を選択してください',true);
 comment.target=target.id;dirty();render();toast('接続しました');
}
function disconnect(){
 let n=0;state.project?.elements.forEach(e=>{if(e.type==='comment'&&state.selected.has(e.id)&&e.target){e.target='';n++}});
 if(n){dirty();render();toast('接続を解除しました')}
}
function deleteSelected(){
 if(!state.project||!state.selected.size)return;
 const ids=new Set(state.selected);
 state.project.elements=state.project.elements.filter(e=>!ids.has(e.id));
 state.project.elements.forEach(e=>{if(e.type==='comment'&&ids.has(e.target))e.target=''});
 state.selected.clear();dirty();render();
}

/* 要素編集 */
function openElement(id){
 const e=get(id);if(!e)return;state.editId=id;
 ui.title.textContent={comment:'コメント編集',highlight:'強調枠編集',zoom:'動画拡大範囲編集',skip:'スキップ編集'}[e.type];
 ui.name.value=e.name;ui.text.value=e.text;ui.shape.value=e.shape;
 ui.start.value=e.start;ui.end.value=e.end;ui.x.value=e.x;ui.y.value=e.y;ui.w.value=e.w;ui.h.value=e.h;ui.font.value=e.fontSize;
 ui.textBox.classList.toggle('hidden',e.type!=='comment');
 ui.shapeBox.classList.toggle('hidden',e.type!=='highlight');
 ui.fontBox.classList.toggle('hidden',e.type!=='comment');
 ui.targetBox.classList.toggle('hidden',e.type!=='comment');
 ui.target.innerHTML='<option value="">接続しない</option>';
 state.project.elements.filter(x=>x.type==='highlight'||x.type==='zoom').forEach(x=>{
  const o=new Option(x.name,x.id,e.target===x.id,e.target===x.id);ui.target.append(o)
 });
 ui.colors.innerHTML='';
 colors.forEach(c=>{const b=document.createElement('button');b.type='button';b.className='color';b.style.background=c;b.dataset.color=c;if(c.toLowerCase()===e.color.toLowerCase())b.classList.add('active');b.onclick=()=>{ui.colors.querySelector('.active')?.classList.remove('active');b.classList.add('active')};ui.colors.append(b)});
 ui.back.style.display='flex';
}
function closeElement(){ui.back.style.display='none';state.editId=null}
function applyElement(){
 const e=get(state.editId);if(!e)return;
 const s=+ui.start.value,end=+ui.end.value;
 if(!Number.isFinite(s)||!Number.isFinite(end)||s<0||end<=s||end>state.duration)return toast('開始・終了時間を確認してください',true);
 Object.assign(e,{name:ui.name.value.trim()||'要素',start:s,end,x:clamp(+ui.x.value||0,0,99),y:clamp(+ui.y.value||0,0,99),w:clamp(+ui.w.value||1,.5,100),h:clamp(+ui.h.value||1,.5,100),color:ui.colors.querySelector('.active')?.dataset.color||e.color});
 if(e.type==='comment'){e.text=ui.text.value;e.fontSize=clamp(+ui.font.value||28,8,100);e.target=get(ui.target.value)?.id||''}
 if(e.type==='highlight')e.shape=ui.shape.value;
 closeElement();dirty();render();
}
ui.delElement.onclick=()=>{if(state.editId){state.selected=new Set([state.editId]);deleteSelected();closeElement()}};

/* 接続線編集 */
function openLine(id){
 const e=get(id);if(!e||e.type!=='comment'||!e.target)return;
 state.lineKey=id;ui.fromPoint.value=e.from;ui.toPoint.value=e.to;
 ui.lineColor.value=e.lineColor;ui.lineWidth.value=e.lineWidth;
 ui.lineDash.value=e.lineDash;ui.lineCurve.value=e.lineCurve;ui.lineBack.style.display='flex';
}
function applyLine(){
 const e=get(state.lineKey);if(!e)return;
 Object.assign(e,{from:ui.fromPoint.value,to:ui.toPoint.value,lineColor:ui.lineColor.value,lineWidth:clamp(+ui.lineWidth.value,1,12),lineDash:ui.lineDash.value,lineCurve:ui.lineCurve.value});
 ui.lineBack.style.display='none';dirty();render();
}

/* 動画 */
async function toggle(){
 if(!ok())return;
 if(ui.video.paused){if(state.time>=state.duration-.01)seek(0);try{await ui.video.play()}catch(e){toast('動画を再生できません',true)}}
 else ui.video.pause();
}
ui.video.onloadedmetadata=()=>{
 state.duration=+ui.video.duration||0;if(!state.project)state.project=emptyProject();
 state.project.duration=state.duration;state.time=0;ui.empty.style.display='none';
 ui.play.disabled=ui.stop.disabled=ui.save.disabled=false;render();clean();
};
ui.video.ontimeupdate=()=>{if(!ui.video.paused){state.time=ui.video.currentTime;render()}};
ui.video.onplay=()=>ui.play.textContent='Ⅱ';ui.video.onpause=()=>ui.play.textContent='▶';
ui.video.onended=()=>{state.time=state.duration;render()};

/* 保存 */
async function post(action,data={}){
 const fd=new FormData();fd.append('action',action);Object.entries(data).forEach(([k,v])=>fd.append(k,typeof v==='string'?v:JSON.stringify(v)));
 const r=await fetch(location.href,{method:'POST',body:fd}),j=await r.json();if(!r.ok||j.ok===false)throw Error(j.error||'処理に失敗しました');return j;
}
async function saveProject(){
 if(!state.project)return;
 let name=state.project.name.trim();
 if(!name)name=prompt('プロジェクト名を入力してください','動画プロジェクト')||'';
 if(!name)return;
 state.project.name=name;
 try{await post('save',{name,data:JSON.stringify(state.project)});clean();toast('保存しました')}
 catch(e){toast(e.message,true)}
}
async function openSaved(){
 try{
  const j=await post('list'),all=j;
  ui.savedList.innerHTML='';
  const names=Object.keys(all);
  if(!names.length)ui.savedList.innerHTML='<div style="padding:20px;color:#94a3b8">保存したプロジェクトはありません</div>';
  names.sort((a,b)=>(all[b].savedAt||'').localeCompare(all[a].savedAt||'')).forEach(name=>{
   const row=document.createElement('div');row.className='row';
   const info=document.createElement('div');info.className='rowinfo';
   info.innerHTML=`<div class="rowname"></div><div class="rowmeta"></div>`;
   info.querySelector('.rowname').textContent=name;
   info.querySelector('.rowmeta').textContent=all[name].project?.videoName||'動画未設定';
   const load=document.createElement('button');load.className='btn small';load.textContent='開く';
   load.onclick=()=>loadSaved(name);
   const del=document.createElement('button');del.className='btn small danger';del.textContent='削除';
   del.onclick=async()=>{if(confirm('このプロジェクトを削除しますか？')){await post('delete',{name});openSaved()}};
   row.append(info,load,del);ui.savedList.append(row);
  });
  ui.savedBack.style.display='flex';
 }catch(e){toast(e.message,true)}
}
async function loadSaved(name){
 try{
  const j=await post('load',{name});
  state.project=validProject(j.data);
  state.time=0;state.selected.clear();ui.savedBack.style.display='none';
  if(+ui.video.duration>0){state.duration=ui.video.duration;state.project.duration=state.duration;ui.empty.style.display='none';ui.play.disabled=ui.stop.disabled=ui.save.disabled=false}
  else {state.duration=+state.project.duration||0;ui.empty.style.display='flex'}
  render();clean();toast('プロジェクトを開きました');
 }catch(e){toast(e.message,true)}
}

/* 再生ヘッド */
ui.playhead.onpointerdown=ev=>{if(!ok())return;ev.preventDefault();state.ph=true;seek(timeAt(ev.clientX))};
window.addEventListener('pointermove',ev=>{if(state.ph)seek(timeAt(ev.clientX))});
window.addEventListener('pointerup',()=>state.ph=false);

ui.content.onpointerdown=ev=>{
 if(ev.target.closest('.bar')||ev.target.closest('.playhead'))return;
 if(ev.target.closest('.lane')||ev.target.closest('.axistrack'))seek(timeAt(ev.clientX));
};
ui.content.oncontextmenu=ev=>{
 ev.preventDefault();if(!ok())return;
 const bar=ev.target.closest('.bar');
 if(bar){select(bar.dataset.id,ev.ctrlKey||ev.metaKey);menu(ev.clientX,ev.clientY,bar.dataset.id)}
 else if(ev.target.closest('.lane')||ev.target.closest('.axistrack')){seek(timeAt(ev.clientX));menu(ev.clientX,ev.clientY)}
};

/* UI */
ui.open.onclick=()=>ui.file.click();
ui.file.onchange=()=>loadVideo(ui.file.files?.[0]);
ui.new.onclick=reset;
ui.save.onclick=saveProject;
ui.saved.onclick=openSaved;
ui.play.onclick=toggle;
ui.stop.onclick=()=>{ui.video.pause();seek(0)};
ui.connect.onclick=connect;
ui.disconnect.onclick=disconnect;
ui.scale.oninput=()=>{ui.scalev.textContent=(+ui.scale.value).toFixed(1)+'×';render()};
ui.close.onclick=ui.cancel.onclick=closeElement;
ui.apply.onclick=applyElement;
ui.lineClose.onclick=ui.lineCancel.onclick=()=>ui.lineBack.style.display='none';
ui.lineApply.onclick=applyLine;
ui.savedClose.onclick=()=>ui.savedBack.style.display='none';

document.addEventListener('click',e=>{if(!e.target.closest('.menu'))closeMenu()});
document.addEventListener('keydown',e=>{
 const input=['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName);
 if(e.key==='Escape'){closeMenu();closeElement();ui.lineBack.style.display='none';ui.savedBack.style.display='none'}
 if((e.key==='Delete'||e.key==='Backspace')&&!input){e.preventDefault();deleteSelected()}
 if(e.key===' '&&!input){e.preventDefault();toggle()}
 if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='a'&&!input&&state.project){
  e.preventDefault();state.selected=new Set(state.project.elements.map(x=>x.id));render()
 }
});
window.addEventListener('resize',render);
ui.scroll.addEventListener('scroll',renderLines);
ui.scalev.textContent='1.0×';
render();
</script>
</body>
</html>

<?php
declare(strict_types=1);

const APP_VERSION = '7.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'version' => APP_VERSION
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'export-json') {
        $raw = $_POST['project'] ?? '';
        $data = json_decode($raw, true);

        if ($raw === '' || !is_array($data)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'invalid json'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="video-project.json"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画注釈編集ツール</title>
<style>
:root{
    --bg:#0b1220;--panel:#111b2a;--panel2:#182438;--line:#334155;
    --text:#e5e7eb;--muted:#94a3b8;--blue:#2563eb;--red:#ef4444;
    --comment:#60a5fa;--highlight:#22c55e;--skip:#f97316;
    --label:110px;--track:54px
}
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:var(--bg);color:var(--text);
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
.hidden{display:none!important}

.app{height:100vh;display:flex;flex-direction:column}
.topbar{height:52px;flex:none;display:flex;align-items:center;gap:6px;padding:7px 9px;
background:#080f1b;border-bottom:1px solid var(--line)}
.brand{font-weight:700;white-space:nowrap;margin-right:7px}
.btn{border:1px solid #40516a;border-radius:6px;background:#243247;color:var(--text);padding:7px 10px}
.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:var(--blue);border-color:#3b82f6}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}
.status{margin-left:auto;color:var(--muted);font-size:12px;white-space:nowrap}
.file-input{display:none}

.main{flex:1;min-height:0;display:flex;flex-direction:column}
.video-area{flex:1;min-height:240px;display:flex;align-items:center;justify-content:center;
padding:10px;background:#050a11;overflow:hidden}
.video-wrap{position:relative;width:100%;height:100%;display:flex;align-items:center;justify-content:center}
#video{display:block;max-width:100%;max-height:100%;background:#000}
.video-stage{position:absolute;pointer-events:none}
.video-overlay{position:absolute;inset:0;pointer-events:none}
.overlay-svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none;z-index:1}
.connection{fill:none;stroke:#facc15;stroke-width:2.5;stroke-dasharray:7 5}
.connection.active{stroke:#fff;stroke-width:4}
.connection-dot{fill:#facc15;stroke:#111827;stroke-width:2}

.overlay-element{position:absolute;min-width:20px;min-height:20px;z-index:5;
pointer-events:auto;user-select:none;touch-action:none}
.overlay-element.selected{outline:2px solid #60a5fa;outline-offset:2px}
.overlay-comment{width:100%;height:100%;padding:5px 8px;display:flex;align-items:center;
justify-content:center;text-align:center;white-space:pre-wrap;overflow:hidden;background:#0009;
border:1px solid currentColor;border-radius:4px;line-height:1.25}
.overlay-highlight{width:100%;height:100%;border:3px solid currentColor}
.overlay-highlight.circle,.overlay-highlight.ellipse{border-radius:50%}
.overlay-skip{width:100%;height:100%;display:flex;align-items:center;justify-content:center;
border:2px dashed currentColor;background:#f9731622;color:#fdba74;border-radius:4px;
font-size:12px;font-weight:700}

.resize-handle{position:absolute;width:9px;height:9px;background:#fff;border:1px solid #2563eb;
border-radius:2px;z-index:20;display:none}
.overlay-element.selected .resize-handle{display:block}
.rh-nw{left:-5px;top:-5px;cursor:nwse-resize}
.rh-ne{right:-5px;top:-5px;cursor:nesw-resize}
.rh-sw{left:-5px;bottom:-5px;cursor:nesw-resize}
.rh-se{right:-5px;bottom:-5px;cursor:nwse-resize}

.empty-video{position:absolute;text-align:center;color:#64748b;line-height:1.8;pointer-events:none}
.empty-video strong{display:block;color:#94a3b8;font-size:18px}

.bottom{height:330px;flex:none;display:flex;min-height:0;border-top:1px solid var(--line);background:#0e1724}
.timeline-panel{min-width:0;flex:1;display:flex;flex-direction:column}
.timeline-toolbar{height:43px;flex:none;display:flex;align-items:center;gap:6px;padding:5px 8px;
border-bottom:1px solid var(--line)}
.time-readout{min-width:150px;font-variant-numeric:tabular-nums;color:#dbeafe}
.zoom-control{margin-left:auto;display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12px}
.zoom-control input{width:120px}
.timeline-scroll{position:relative;flex:1;min-height:0;overflow:auto}
.timeline-content{position:relative;min-width:700px;width:100%}
.axis-row{position:sticky;top:0;z-index:50;height:34px;display:flex;background:#131d29;border-bottom:1px solid var(--line)}
.axis-label{width:var(--label);min-width:var(--label);display:flex;align-items:center;padding-left:8px;
background:#131d29;border-right:1px solid var(--line);font-size:12px;position:sticky;left:0;z-index:60}
.axis-track{position:relative;height:100%;flex:1}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #334155;padding:5px 0 0 3px;
font-size:10px;color:#64748b;white-space:nowrap;pointer-events:none}
.track-row{height:var(--track);display:flex;border-bottom:1px solid #263445}
.track-label{width:var(--label);min-width:var(--label);display:flex;align-items:center;gap:6px;
padding:0 8px;background:#111b27;border-right:1px solid var(--line);font-size:12px;
position:sticky;left:0;z-index:20}
.type-dot{width:8px;height:8px;border-radius:50%;flex:none}
.track-lane{position:relative;flex:1;min-width:0}
.track-grid{position:absolute;inset:0;pointer-events:none}
.element-bar{position:absolute;top:8px;height:38px;min-width:14px;border:1px solid currentColor;
border-radius:5px;display:flex;align-items:center;overflow:visible;cursor:grab;user-select:none;
touch-action:none;z-index:5}
.element-bar:active{cursor:grabbing}
.element-bar.selected{box-shadow:0 0 0 2px #60a5fa}
.bar-label{padding:0 7px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;pointer-events:none}
.handle{position:absolute;top:0;width:10px;height:100%;z-index:8}
.handle.left{left:-5px;cursor:ew-resize}.handle.right{right:-5px;cursor:ew-resize}
.playhead{position:absolute;top:34px;bottom:0;width:3px;z-index:80;pointer-events:auto;
background:var(--red);cursor:ew-resize;touch-action:none}
.playhead:before{content:"";position:absolute;top:-1px;left:-5px;width:13px;height:13px;
background:var(--red);clip-path:polygon(0 0,100% 0,50% 100%)}
.playhead-label{position:absolute;top:12px;left:6px;padding:2px 4px;background:var(--red);
color:#fff;font-size:10px;white-space:nowrap;border-radius:3px}

.context-menu{position:fixed;display:none;z-index:2000;min-width:210px;padding:5px;
background:#172235;border:1px solid #475569;border-radius:6px;box-shadow:0 12px 30px #0008}
.context-menu button{display:block;width:100%;padding:8px;border:0;background:transparent;color:var(--text);
text-align:left;border-radius:4px}
.context-menu button:hover{background:#293a52}
.context-menu hr{border:0;border-top:1px solid #334155;margin:4px 0}

.modal-backdrop{position:fixed;inset:0;z-index:3000;display:none;align-items:center;justify-content:center;
padding:20px;background:#000a}
.modal{width:min(580px,100%);max-height:90vh;display:flex;flex-direction:column;background:#182231;
border:1px solid #475569;border-radius:8px;overflow:hidden}
.modal-head,.modal-foot{padding:12px 15px;border-color:var(--line)}
.modal-head{display:flex;justify-content:space-between;border-bottom:1px solid var(--line)}
.modal-body{padding:15px;display:grid;gap:10px;overflow:auto}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--line)}
.field{display:grid;gap:5px}.field label{font-size:12px;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;padding:7px;background:#0f1722;color:var(--text);
border:1px solid #40516a;border-radius:5px}
.field textarea{min-height:90px;resize:vertical}
.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.color-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:6px}
.color-choice{height:28px;border:2px solid transparent;border-radius:4px}
.color-choice.active{border-color:#fff;box-shadow:0 0 0 1px #60a5fa}
.storage-list{padding:0!important;gap:0!important}
.storage-item{display:flex;align-items:center;gap:8px;padding:10px;border-bottom:1px solid #334155}
.storage-info{min-width:0;flex:1}.storage-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.storage-meta{color:#64748b;font-size:11px}
.toast{position:fixed;right:15px;bottom:15px;z-index:5000;display:none;padding:10px 14px;
background:#1e293b;border:1px solid #475569;border-radius:6px;box-shadow:0 10px 30px #0006}

@media(max-width:800px){
    :root{--label:90px}
    .brand{display:none}
    .bottom{height:300px}
}
@media(max-width:600px){.status{display:none}}
</style>
</head>
<body>
<div class="app">
<header class="topbar">
    <div class="brand">動画注釈編集</div>
    <button class="btn primary" id="openVideo">動画を読み込む</button>
    <input class="file-input" id="videoFile" type="file" accept="video/*">

    <button class="btn" id="newProject">新規</button>
    <button class="btn" id="saveProject" disabled>保存</button>
    <button class="btn" id="loadProject">保存データ</button>
    <button class="btn" id="exportProject" disabled>JSON書出し</button>
    <label class="btn">JSON読込
        <input class="file-input" id="importProject" type="file" accept=".json,application/json">
    </label>

    <button class="btn" id="connect" disabled>接続</button>
    <button class="btn" id="disconnect" disabled>接続解除</button>
    <span class="status" id="status">動画を読み込んでください</span>
</header>

<main class="main">
<section class="video-area">
    <div class="video-wrap" id="videoWrap">
        <video id="video" preload="metadata" playsinline></video>
        <div class="video-stage" id="videoStage">
            <div class="video-overlay" id="videoOverlay">
                <svg class="overlay-svg" id="connectionSvg"></svg>
            </div>
        </div>
        <div class="empty-video" id="emptyVideo">
            <strong>動画を読み込んでください</strong>
            動画上で右クリックすると要素を追加できます
        </div>
    </div>
</section>

<section class="bottom">
<section class="timeline-panel">
<div class="timeline-toolbar">
    <button class="btn small" id="play" disabled>▶</button>
    <button class="btn small" id="stop" disabled>■</button>
    <span class="time-readout" id="readout">00:00.000 / 00:00.000</span>
    <div class="zoom-control">
        <span>時間スケール</span>
        <input id="zoom" type="range" min="1" max="5" step=".1" value="1">
        <span id="zoomValue">1.0×</span>
    </div>
</div>

<div class="timeline-scroll" id="timelineScroll">
<div class="timeline-content" id="timelineContent">
    <div class="axis-row">
        <div class="axis-label">時間</div>
        <div class="axis-track" id="axisTrack"></div>
    </div>
    <div id="tracks"></div>
    <div class="playhead" id="playhead">
        <span class="playhead-label" id="playheadLabel">00:00.000</span>
    </div>
</div>
</div>
</section>
</section>
</main>
</div>

<div class="context-menu" id="contextMenu">
    <button data-add="comment">＋ コメント</button>
    <button data-add="highlight">＋ 強調枠</button>
    <button data-add="skip">＋ スキップ</button>
    <hr>
    <button id="editContext">編集</button>
    <button id="connectContext">選択要素を接続</button>
    <button id="disconnectContext">接続解除</button>
    <button id="deleteContext">削除</button>
</div>

<div class="modal-backdrop" id="elementModal">
<div class="modal">
<div class="modal-head">
    <strong id="modalTitle">要素編集</strong>
    <button class="btn small" id="modalClose">閉じる</button>
</div>
<div class="modal-body">
    <div class="field">
        <label>要素名</label>
        <input id="fieldName">
    </div>

    <div class="field" id="commentTextField">
        <label>コメント</label>
        <textarea id="fieldText" placeholder="動画上に表示するコメント"></textarea>
    </div>

    <div class="field hidden" id="shapeField">
        <label>強調枠の形</label>
        <select id="fieldShape">
            <option value="square">四角</option>
            <option value="circle">丸</option>
            <option value="ellipse">楕円</option>
        </select>
    </div>

    <div class="two">
        <div class="field"><label>開始時間</label><input id="fieldStart" type="number" min="0" step=".001"></div>
        <div class="field"><label>終了時間</label><input id="fieldEnd" type="number" min="0" step=".001"></div>
    </div>

    <div class="two">
        <div class="field"><label>横位置 (%)</label><input id="fieldX" type="number" min="0" max="100" step=".1"></div>
        <div class="field"><label>縦位置 (%)</label><input id="fieldY" type="number" min="0" max="100" step=".1"></div>
    </div>

    <div class="two">
        <div class="field"><label>幅 (%)</label><input id="fieldW" type="number" min="1" max="100" step=".1"></div>
        <div class="field"><label>高さ (%)</label><input id="fieldH" type="number" min="1" max="100" step=".1"></div>
    </div>

    <div class="field" id="fontField">
        <label>文字サイズ</label>
        <input id="fieldFont" type="number" min="8" max="100" step="1">
    </div>

    <div class="field">
        <label>色</label>
        <div class="color-grid" id="colorGrid"></div>
    </div>

    <div class="field" id="targetField">
        <label>接続する強調枠</label>
        <select id="fieldTarget">
            <option value="">接続しない</option>
        </select>
    </div>
</div>
<div class="modal-foot">
    <button class="btn danger" id="modalDelete">削除</button>
    <button class="btn" id="modalCancel">キャンセル</button>
    <button class="btn primary" id="modalSave">保存</button>
</div>
</div>
</div>

<div class="modal-backdrop" id="storageModal">
<div class="modal">
<div class="modal-head">
    <strong>保存データ</strong>
    <button class="btn small" id="storageClose">閉じる</button>
</div>
<div class="modal-body storage-list" id="storageList"></div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $=id=>document.getElementById(id);
const U={
    video:$('video'),wrap:$('videoWrap'),stage:$('videoStage'),overlay:$('videoOverlay'),
    svg:$('connectionSvg'),empty:$('emptyVideo'),file:$('videoFile'),
    open:$('openVideo'),new:$('newProject'),save:$('saveProject'),load:$('loadProject'),
    export:$('exportProject'),import:$('importProject'),connect:$('connect'),
    disconnect:$('disconnect'),status:$('status'),play:$('play'),stop:$('stop'),
    readout:$('readout'),zoom:$('zoom'),zoomValue:$('zoomValue'),scroll:$('timelineScroll'),
    content:$('timelineContent'),axis:$('axisTrack'),tracks:$('tracks'),playhead:$('playhead'),
    playheadLabel:$('playheadLabel'),menu:$('contextMenu'),modal:$('elementModal'),
    storage:$('storageModal'),storageList:$('storageList'),modalTitle:$('modalTitle'),
    name:$('fieldName'),text:$('fieldText'),shape:$('fieldShape'),start:$('fieldStart'),
    end:$('fieldEnd'),x:$('fieldX'),y:$('fieldY'),w:$('fieldW'),h:$('fieldH'),
    font:$('fieldFont'),colors:$('colorGrid'),target:$('fieldTarget'),
    textField:$('commentTextField'),shapeField:$('shapeField'),fontField:$('fontField'),
    targetField:$('targetField'),modalClose:$('modalClose'),modalDelete:$('modalDelete'),
    modalCancel:$('modalCancel'),modalSave:$('modalSave'),storageClose:$('storageClose'),
    toast:$('toast')
};

const A={
    version:'7.0.0',project:null,duration:0,time:0,videoUrl:'',
    selected:new Set(),modalId:null,contextId:null,contextXY:null,
    dirty:false,drag:null,playheadDrag:false,
    colors:['#60a5fa','#22c55e','#f59e0b','#ef4444','#c084fc','#14b8a6',
            '#f97316','#e879f9','#38bdf8','#a3e635','#fb7185','#facc15']
};

const clamp=(v,min,max)=>Math.max(min,Math.min(max,v));
const uid=p=>p+'-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8);
const typeColor=t=>t==='comment'?'#60a5fa':t==='highlight'?'#22c55e':'#f97316';
const get=id=>A.project?.elements.find(e=>e.id===id)||null;
const fmt=s=>{
    s=Math.max(0,Number(s)||0);
    return String(Math.floor(s/60)).padStart(2,'0')+':'+
        String(Math.floor(s%60)).padStart(2,'0')+'.'+
        String(Math.floor((s%1)*1000)).padStart(3,'0');
};
const shortTime=s=>{
    s=Number(s)||0;
    return s<60?s.toFixed(s%1?1:0)+'s':
        String(Math.floor(s/60)).padStart(2,'0')+':'+String(Math.floor(s%60)).padStart(2,'0');
};
const rgba=(hex,a)=>{
    const n=parseInt(hex.slice(1),16);
    return `rgba(${n>>16},${n>>8&255},${n&255},${a})`;
};
function toast(msg,error=false){
    U.status.textContent=msg;U.toast.textContent=msg;U.toast.style.display='block';
    U.toast.style.borderColor=error?'#ef4444':'#475569';
    clearTimeout(toast.timer);toast.timer=setTimeout(()=>U.toast.style.display='none',2400);
}
function dirty(){A.dirty=true;U.status.textContent='変更あり'}
function clean(){A.dirty=false;U.status.textContent=A.project?'編集中：'+A.project.name:'動画を読み込んでください'}
function editable(){return !!A.project&&A.duration>0}

function newProject(name='新規プロジェクト'){
    return {version:A.version,name,videoName:'',duration:A.duration,elements:[]};
}

function normalizeProject(src){
    if(!src||typeof src!=='object')throw Error('プロジェクト形式が不正です');
    const duration=Number(src.duration)||A.duration;
    const p={
        version:A.version,name:String(src.name||'プロジェクト'),
        videoName:String(src.videoName||''),duration,elements:[]
    };
    for(const r of Array.isArray(src.elements)?src.elements:[]){
        const type=['comment','highlight','skip'].includes(r.type)?r.type:'comment';
        const start=clamp(Number(r.start)||0,0,duration);
        const rawEnd=Number(r.end);
        const end=clamp(Number.isFinite(rawEnd)?rawEnd:start+3,start+.05,duration);
        p.elements.push({
            id:String(r.id||uid('el')),type,
            name:String(r.name||(type==='comment'?'コメント':type==='highlight'?'強調枠':'スキップ')),
            text:type==='comment'?String(r.text||''):'',
            start,end,
            x:clamp(Number(r.x)||10,0,99),y:clamp(Number(r.y)||10,0,99),
            w:clamp(Number(r.w)||30,1,100),h:clamp(Number(r.h)||15,1,100),
            color:/^#[0-9a-f]{6}$/i.test(String(r.color||''))?r.color:typeColor(type),
            fontSize:clamp(Number(r.fontSize)||28,8,100),
            shape:['square','circle','ellipse'].includes(r.shape)?r.shape:'square',
            target:type==='comment'?String(r.target||''):''
        });
    }
    const ids=new Set(p.elements.map(e=>e.id));
    p.elements.forEach(e=>{
        if(e.target&&(!ids.has(e.target)||getFrom(p,e.target)?.type!=='highlight'))e.target='';
    });
    return p;
}
function getFrom(p,id){return p.elements.find(e=>e.id===id)||null}

function loadVideo(file){
    if(!file||!file.type.startsWith('video/'))return toast('動画ファイルを選択してください',true);
    if(A.videoUrl)URL.revokeObjectURL(A.videoUrl);
    A.videoUrl=URL.createObjectURL(file);A.project=newProject(file.name);A.project.videoName=file.name;
    A.duration=0;A.time=0;A.selected.clear();U.video.src=A.videoUrl;U.video.load();
    U.status.textContent='動画を読み込んでいます…';
}
function resetApp(){
    U.video.pause();
    if(A.videoUrl)URL.revokeObjectURL(A.videoUrl);
    U.video.removeAttribute('src');U.video.load();
    Object.assign(A,{project:null,duration:0,time:0,videoUrl:'',modalId:null,contextId:null,dirty:false});
    A.selected.clear();disable();render();
}
function enable(){
    [U.play,U.stop,U.save,U.export,U.connect,U.disconnect].forEach(x=>x.disabled=false);
    U.empty.style.display='none';
}
function disable(){
    [U.play,U.stop,U.save,U.export,U.connect,U.disconnect].forEach(x=>x.disabled=true);
    U.empty.style.display='block';
}

function range(){
    if(!A.duration)return {start:0,end:0};
    const span=A.duration/(Number(U.zoom.value)||1);
    const start=clamp(A.time-span/2,0,Math.max(0,A.duration-span));
    return {start,end:start+span};
}
function timePct(t){
    const r=range();return r.end>r.start?clamp((t-r.start)/(r.end-r.start)*100,0,100):0;
}
function clientTime(x){
    const r=range(),rect=U.axis.getBoundingClientRect();
    return rect.width?clamp(r.start+(x-rect.left)/rect.width*(r.end-r.start),0,A.duration):0;
}

function renderAxis(){
    U.axis.innerHTML='';
    if(!A.duration)return;
    const r=range(),span=r.end-r.start;
    const steps=[.1,.25,.5,1,2,5,10,15,30,60,120,300,600];
    const step=steps.find(v=>v>=span/Math.max(1,U.axis.clientWidth/80))||600;
    for(let t=Math.ceil(r.start/step)*step;t<=r.end+.001;t+=step){
        const n=document.createElement('div');n.className='tick';
        n.style.left=((t-r.start)/span*100)+'%';n.textContent=shortTime(t);U.axis.appendChild(n);
    }
}

function makeBar(e){
    const b=document.createElement('div');b.className='element-bar';b.dataset.id=e.id;
    if(A.selected.has(e.id))b.classList.add('selected');
    b.style.left=timePct(e.start)+'%';b.style.width=Math.max(.7,timePct(e.end)-timePct(e.start))+'%';
    b.style.color=e.color;b.style.background=rgba(e.color,.2);
    const label=document.createElement('span');label.className='bar-label';
    label.textContent=`${e.name} ${fmt(e.start)}～${fmt(e.end)}`;
    const l=document.createElement('span');l.className='handle left';l.dataset.edge='left';
    const r=document.createElement('span');r.className='handle right';r.dataset.edge='right';
    b.append(label,l,r);
    b.addEventListener('pointerdown',startBarDrag);
    b.addEventListener('click',e2=>{e2.stopPropagation();select(e.id,e2.shiftKey)});
    b.addEventListener('dblclick',e2=>{e2.stopPropagation();openElement(e.id)});
    b.addEventListener('contextmenu',e2=>{
        e2.preventDefault();e2.stopPropagation();select(e.id,false);openContext(e2.clientX,e2.clientY,e.id);
    });
    return b;
}

function renderTimeline(){
    U.tracks.innerHTML='';
    if(!editable()){renderAxis();return}
    for(const [type,label] of [['comment','コメント'],['highlight','強調枠'],['skip','スキップ']]){
        const row=document.createElement('div');row.className='track-row';
        const lab=document.createElement('div');lab.className='track-label';
        const dot=document.createElement('span');dot.className='type-dot';dot.style.background=typeColor(type);
        lab.append(dot,document.createTextNode(label));
        const lane=document.createElement('div');lane.className='track-lane';
        const grid=document.createElement('div');grid.className='track-grid';
        grid.style.backgroundImage='linear-gradient(to right,rgba(148,163,184,.09) 1px,transparent 1px)';
        grid.style.backgroundSize=Math.max(10,100/Math.max(1,A.duration))+'% 100%';
        lane.appendChild(grid);
        A.project.elements.filter(e=>e.type===type).forEach(e=>lane.appendChild(makeBar(e)));
        row.append(lab,lane);U.tracks.appendChild(row);
    }
    renderAxis();
}

function select(id,add=false){
    if(!get(id))return;
    if(!add)A.selected.clear();
    if(add&&A.selected.has(id))A.selected.delete(id);else A.selected.add(id);
    render();
}

function syncStage(){
    if(!U.video.videoWidth||!U.video.clientWidth)return;
    const vr=U.video.getBoundingClientRect(),wr=U.wrap.getBoundingClientRect();
    Object.assign(U.stage.style,{
        left:(vr.left-wr.left)+'px',top:(vr.top-wr.top)+'px',
        width:vr.width+'px',height:vr.height+'px'
    });
}

function visible(e){return A.time>=e.start&&A.time<e.end}

function addResizeHandles(node){
    for(const c of ['nw','ne','sw','se']){
        const h=document.createElement('span');h.className='resize-handle rh-'+c;h.dataset.corner=c;
        h.addEventListener('pointerdown',ev=>startOverlayResize(ev,node.dataset.id,c));
        node.appendChild(h);
    }
}

function renderOverlay(){
    U.overlay.querySelectorAll('.overlay-element').forEach(n=>n.remove());
    if(!editable())return;
    A.project.elements.filter(visible).forEach(e=>{
        const n=document.createElement('div');n.className='overlay-element';n.dataset.id=e.id;
        if(A.selected.has(e.id))n.classList.add('selected');
        Object.assign(n.style,{left:e.x+'%',top:e.y+'%',width:e.w+'%',height:e.h+'%',color:e.color});
        if(e.type==='comment'){
            const t=document.createElement('div');t.className='overlay-comment';t.textContent=e.text||e.name;
            t.style.fontSize=e.fontSize+'px';n.appendChild(t);
        }else if(e.type==='highlight'){
            const b=document.createElement('div');b.className='overlay-highlight '+e.shape;n.appendChild(b);
        }else{
            const s=document.createElement('div');s.className='overlay-skip';s.textContent='スキップ';n.appendChild(s);
        }
        addResizeHandles(n);
        n.addEventListener('pointerdown',ev=>{
            if(ev.target.closest('.resize-handle'))return;
            startOverlayDrag(ev,e.id);
        });
        n.addEventListener('click',ev=>{ev.stopPropagation();select(e.id,ev.shiftKey)});
        n.addEventListener('dblclick',ev=>{ev.stopPropagation();openElement(e.id)});
        n.addEventListener('contextmenu',ev=>{
            ev.preventDefault();ev.stopPropagation();select(e.id,false);openContext(ev.clientX,ev.clientY,e.id);
        });
        U.overlay.appendChild(n);
    });
}

function renderConnections(){
    U.svg.innerHTML='';
    if(!editable())return;
    const sr=U.overlay.getBoundingClientRect();
    A.project.elements.filter(e=>e.type==='comment'&&e.target&&visible(e)).forEach(c=>{
        const h=get(e.target);
        if(!h||h.type!=='highlight'||!visible(h))return;
        const a=U.overlay.querySelector(`[data-id="${CSS.escape(c.id)}"]`);
        const b=U.overlay.querySelector(`[data-id="${CSS.escape(h.id)}"]`);
        if(!a||!b)return;
        const ar=a.getBoundingClientRect(),br=b.getBoundingClientRect();
        const ax=ar.left+ar.width/2-sr.left,ay=ar.top+ar.height/2-sr.top;
        const bx=br.left+br.width/2-sr.left,by=br.top+br.height/2-sr.top;
        const dx=bx-ax,bend=Math.max(30,Math.abs(dx)*.25);
        const p=document.createElementNS('http://www.w3.org/2000/svg','path');
        p.classList.add('connection');
        if(A.selected.has(c.id)||A.selected.has(h.id))p.classList.add('active');
        p.setAttribute('d',`M ${ax} ${ay} C ${ax+Math.sign(dx)*bend} ${ay}, ${bx-Math.sign(dx)*bend} ${by}, ${bx} ${by}`);
        U.svg.appendChild(p);
        const d=document.createElementNS('http://www.w3.org/2000/svg','circle');
        d.classList.add('connection-dot');d.setAttribute('cx',bx);d.setAttribute('cy',by);d.setAttribute('r',5);
        U.svg.appendChild(d);
    });
}

function renderPlayhead(){
    if(!editable()){U.playhead.style.display='none';return}
    U.playhead.style.display='block';
    U.playhead.style.left=`calc(var(--label) + (100% - var(--label)) * ${timePct(A.time)/100})`;
    U.playheadLabel.textContent=fmt(A.time);U.readout.textContent=`${fmt(A.time)} / ${fmt(A.duration)}`;
}
function render(){renderTimeline();syncStage();renderOverlay();renderConnections();renderPlayhead();updateButtons()}

function updateButtons(){
    const two=A.selected.size===2;
    let comment=null,highlight=null;
    A.selected.forEach(id=>{const e=get(id);if(e?.type==='comment')comment=e;if(e?.type==='highlight')highlight=e});
    U.connect.disabled=!(two&&comment&&highlight);
    U.disconnect.disabled=!(A.selected.size>=1&&[...A.selected].some(id=>get(id)?.type==='comment'&&get(id).target));
}

function seek(t){
    if(!editable())return;
    A.time=clamp(Number(t)||0,0,A.duration);
    if(Math.abs((U.video.currentTime||0)-A.time)>.001)try{U.video.currentTime=A.time}catch(_){}
    render();
}

/* ---------- overlay move / resize ---------- */

function startOverlayDrag(ev,id){
    if(!editable())return;
    ev.preventDefault();ev.stopPropagation();
    if(!A.selected.has(id))select(id,ev.shiftKey);
    const rect=U.overlay.getBoundingClientRect();
    A.drag={
        kind:'overlay-move',id,startX:ev.clientX,startY:ev.clientY,
        rect,
        items:[...A.selected].map(x=>{const e=get(x);return {id:x,x:e.x,y:e.y}})
    };
    window.addEventListener('pointermove',moveOverlayDrag);
    window.addEventListener('pointerup',stopDrag,{once:true});
}
function moveOverlayDrag(ev){
    const d=A.drag;if(!d||d.kind!=='overlay-move')return;
    const dx=(ev.clientX-d.startX)/d.rect.width*100,dy=(ev.clientY-d.startY)/d.rect.height*100;
    d.items.forEach(i=>{const e=get(i.id);if(!e)return;e.x=clamp(i.x+dx,0,100-e.w);e.y=clamp(i.y+dy,0,100-e.h)});
    dirty();render();
}
function startOverlayResize(ev,id,corner){
    ev.preventDefault();ev.stopPropagation();
    const e=get(id);if(!e)return;
    select(id,false);
    const rect=U.overlay.getBoundingClientRect();
    A.drag={kind:'overlay-resize',id,corner,startX:ev.clientX,startY:ev.clientY,rect,
        x:e.x,y:e.y,w:e.w,h:e.h};
    window.addEventListener('pointermove',moveOverlayResize);
    window.addEventListener('pointerup',stopDrag,{once:true});
}
function moveOverlayResize(ev){
    const d=A.drag,e=get(d.id);if(!d||!e)return;
    const dx=(ev.clientX-d.startX)/d.rect.width*100,dy=(ev.clientY-d.startY)/d.rect.height*100;
    const min=2;
    let {x,y,w,h}=d;
    if(d.corner.includes('e'))w=clamp(d.w+dx,min,100-d.x);
    if(d.corner.includes('s'))h=clamp(d.h+dy,min,100-d.y);
    if(d.corner.includes('w')){const nx=clamp(d.x+dx,0,d.x+d.w-min);x=nx;w=d.w-(nx-d.x)}
    if(d.corner.includes('n')){const ny=clamp(d.y+dy,0,d.y+d.h-min);y=ny;h=d.h-(ny-d.y)}
    Object.assign(e,{x,y,w,h});dirty();render();
}

/* ---------- timeline move / resize ---------- */

function startBarDrag(ev){
    const bar=ev.currentTarget,id=bar.dataset.id,e=get(id);if(!e)return;
    ev.preventDefault();ev.stopPropagation();
    const handle=ev.target.closest('.handle');
    if(!A.selected.has(id))select(id,ev.shiftKey);
    const lane=bar.closest('.track-lane'),r=range();
    A.drag={
        kind:handle?'bar-resize':'bar-move',id,edge:handle?.dataset.edge||'',
        startX:ev.clientX,laneWidth:Math.max(1,lane.getBoundingClientRect().width),
        range:r,items:[...A.selected].map(x=>{const q=get(x);return {id:x,start:q.start,end:q.end}})
    };
    window.addEventListener('pointermove',moveBarDrag);
    window.addEventListener('pointerup',stopDrag,{once:true});
}
function moveBarDrag(ev){
    const d=A.drag;if(!d)return;
    const delta=(ev.clientX-d.startX)/d.laneWidth*(d.range.end-d.range.start);
    if(d.kind==='bar-move'){
        const minStart=Math.min(...d.items.map(i=>i.start)),maxEnd=Math.max(...d.items.map(i=>i.end));
        let shift=delta;
        if(minStart+shift<0)shift=-minStart;
        if(maxEnd+shift>A.duration)shift=A.duration-maxEnd;
        d.items.forEach(i=>{const e=get(i.id);e.start=i.start+shift;e.end=i.end+shift});
    }else{
        const e=get(d.id),min=.05;
        if(d.edge==='left')e.start=clamp(d.items.find(i=>i.id===d.id).start+delta,0,e.end-min);
        else e.end=clamp(d.items.find(i=>i.id===d.id).end+delta,e.start+min,A.duration);
    }
    dirty();render();
}
function stopDrag(){A.drag=null;window.removeEventListener('pointermove',moveOverlayDrag);
window.removeEventListener('pointermove',moveOverlayResize);window.removeEventListener('pointermove',moveBarDrag);render()}

/* ---------- playhead ---------- */

U.playhead.addEventListener('pointerdown',ev=>{
    if(!editable())return;ev.preventDefault();ev.stopPropagation();A.playheadDrag=true;seek(clientTime(ev.clientX));
});
window.addEventListener('pointermove',ev=>{if(A.playheadDrag)seek(clientTime(ev.clientX))});
window.addEventListener('pointerup',()=>A.playheadDrag=false);

/* ---------- add / connection ---------- */

function addElement(type){
    if(!editable())return toast('先に動画を読み込んでください',true);
    const start=clamp(A.time,0,Math.max(0,A.duration-.05)),end=Math.min(A.duration,start+3);
    const xy=A.contextXY||{x:10,y:10};
    const e={
        id:uid('el'),type,name:type==='comment'?'コメント':type==='highlight'?'強調枠':'スキップ',
        text:type==='comment'?'コメント':'',start,end,
        x:clamp(xy.x,0,98),y:clamp(xy.y,0,98),
        w:type==='comment'?30:25,h:type==='comment'?15:20,
        color:typeColor(type),fontSize:28,shape:'square',target:''
    };
    A.project.elements.push(e);A.selected.clear();A.selected.add(e.id);A.contextXY=null;
    dirty();closeContext();render();openElement(e.id);
}

function connectSelected(){
    if(A.selected.size!==2)return toast('コメントと強調枠を2つ選択してください',true);
    let c=null,h=null;
    A.selected.forEach(id=>{const e=get(id);if(e?.type==='comment')c=e;if(e?.type==='highlight')h=e});
    if(!c||!h)return toast('接続にはコメントと強調枠が必要です',true);
    c.target=h.id;dirty();render();toast('接続しました');
}
function disconnectSelected(){
    let count=0;
    A.selected.forEach(id=>{const e=get(id);if(e?.type==='comment'&&e.target){e.target='';count++}});
    if(count){dirty();render();toast('接続を解除しました')}
}
function deleteSelected(){
    if(!A.project||!A.selected.size)return;
    const ids=new Set(A.selected);
    A.project.elements=A.project.elements.filter(e=>!ids.has(e.id));
    A.project.elements.forEach(e=>{if(e.type==='comment'&&ids.has(e.target))e.target=''});
    A.selected.clear();dirty();closeContext();closeModal();render();
}

/* ---------- modal ---------- */

function openElement(id){
    const e=get(id);if(!e)return;
    A.modalId=id;
    U.modalTitle.textContent=e.type==='comment'?'コメント編集':e.type==='highlight'?'強調枠編集':'スキップ編集';
    U.name.value=e.name;U.text.value=e.text;U.shape.value=e.shape;
    U.start.value=e.start;U.end.value=e.end;U.x.value=e.x;U.y.value=e.y;U.w.value=e.w;U.h.value=e.h;U.font.value=e.fontSize;
    U.textField.classList.toggle('hidden',e.type!=='comment');
    U.shapeField.classList.toggle('hidden',e.type!=='highlight');
    U.fontField.classList.toggle('hidden',e.type!=='comment');
    U.targetField.classList.toggle('hidden',e.type!=='comment');
    U.colors.innerHTML='';
    A.colors.forEach(c=>{
        const b=document.createElement('button');b.type='button';b.className='color-choice';b.style.background=c;b.dataset.color=c;
        if(c.toLowerCase()===e.color.toLowerCase())b.classList.add('active');
        b.onclick=()=>{U.colors.querySelectorAll('.active').forEach(x=>x.classList.remove('active'));b.classList.add('active')};
        U.colors.appendChild(b);
    });
    U.target.innerHTML='<option value="">接続しない</option>';
    A.project.elements.filter(x=>x.type==='highlight').forEach(h=>{
        const o=document.createElement('option');o.value=h.id;o.textContent=h.name;o.selected=h.id===e.target;U.target.appendChild(o);
    });
    U.modal.style.display='flex';
}

function closeModal(){U.modal.style.display='none';A.modalId=null}
function saveElement(){
    const e=get(A.modalId);if(!e)return;
    const start=Number(U.start.value),end=Number(U.end.value);
    if(!Number.isFinite(start)||!Number.isFinite(end)||start<0||end<=start||end>A.duration)
        return toast('開始・終了時間が不正です',true);
    const x=Number(U.x.value),y=Number(U.y.value),w=Number(U.w.value),h=Number(U.h.value);
    if(![x,y,w,h].every(Number.isFinite)||x<0||y<0||w<=0||h<=0||x+w>100||y+h>100)
        return toast('位置・サイズが不正です',true);
    Object.assign(e,{name:U.name.value.trim()||'要素',start,end,x,y,w,h});
    e.color=U.colors.querySelector('.active')?.dataset.color||e.color;
    if(e.type==='comment'){
        e.text=U.text.value;e.fontSize=clamp(Number(U.font.value)||28,8,100);
        const t=get(U.target.value);e.target=t?.type==='highlight'?t.id:'';
    }
    if(e.type==='highlight')e.shape=U.shape.value;
    dirty();closeModal();render();
}

/* ---------- context ---------- */

function openContext(x,y,id=null){
    A.contextId=id;U.menu.style.display='block';
    U.menu.style.left=Math.min(x,innerWidth-220)+'px';U.menu.style.top=Math.min(y,innerHeight-260)+'px';
}
function closeContext(){U.menu.style.display='none';A.contextId=null}

U.menu.querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>addElement(b.dataset.add));
$('editContext').onclick=()=>{if(A.contextId)openElement(A.contextId);closeContext()};
$('connectContext').onclick=()=>{connectSelected();closeContext()};
$('disconnectContext').onclick=()=>{disconnectSelected();closeContext()};
$('deleteContext').onclick=()=>{deleteSelected();closeContext()};

U.wrap.addEventListener('contextmenu',ev=>{
    ev.preventDefault();if(!editable())return;
    const r=U.video.getBoundingClientRect();
    if(ev.clientX<r.left||ev.clientX>r.right||ev.clientY<r.top||ev.clientY>r.bottom)return;
    A.contextXY={x:(ev.clientX-r.left)/r.width*100,y:(ev.clientY-r.top)/r.height*100};
    openContext(ev.clientX,ev.clientY,null);
});

U.content.addEventListener('contextmenu',ev=>{
    ev.preventDefault();if(!editable())return;
    const bar=ev.target.closest('.element-bar');
    if(bar){select(bar.dataset.id,false);openContext(ev.clientX,ev.clientY,bar.dataset.id);return}
    if(ev.target.closest('.track-lane,.axis-track')){
        seek(clientTime(ev.clientX));A.contextXY=null;openContext(ev.clientX,ev.clientY,null);
    }
});
U.content.addEventListener('pointerdown',ev=>{
    if(ev.target.closest('.element-bar,.playhead'))return;
    if(ev.target.closest('.track-lane,.axis-track'))seek(clientTime(ev.clientX));
});

/* ---------- playback ---------- */

async function togglePlay(){
    if(!editable())return;
    if(U.video.paused){
        if(A.time>=A.duration-.001)seek(0);
        try{await U.video.play()}catch(e){if(e.name!=='AbortError')toast('動画を再生できません',true)}
    }else U.video.pause();
}
function stopVideo(){U.video.pause();seek(0)}
U.play.onclick=togglePlay;U.stop.onclick=stopVideo;
U.video.addEventListener('timeupdate',()=>{if(!U.video.paused){A.time=U.video.currentTime;render()}});
U.video.addEventListener('play',()=>U.play.textContent='Ⅱ');
U.video.addEventListener('pause',()=>U.play.textContent='▶');
U.video.addEventListener('ended',()=>{A.time=A.duration;render()});

U.video.addEventListener('loadedmetadata',()=>{
    const d=Number(U.video.duration);
    if(!Number.isFinite(d)||d<=0)return toast('動画の長さを取得できません',true);
    A.duration=d;A.time=0;A.project.duration=d;enable();render();clean();
});

U.zoom.oninput=()=>{U.zoomValue.textContent=Number(U.zoom.value).toFixed(1)+'×';render()};
window.addEventListener('resize',render);
U.scroll.addEventListener('scroll',renderConnections);

/* ---------- storage ---------- */

const storageKey=n=>'video-annotation-project:'+n;

function saveLocal(){
    if(!A.project)return;
    try{
        const copy=JSON.parse(JSON.stringify(A.project));
        copy.savedAt=new Date().toISOString();
        localStorage.setItem(storageKey(copy.name),JSON.stringify(copy));
        clean();toast('保存しました');
    }catch(e){toast('保存に失敗しました：'+e.message,true)}
}

function openStorage(){
    U.storageList.innerHTML='';
    const keys=Object.keys(localStorage).filter(k=>k.startsWith('video-annotation-project:'));
    if(!keys.length){
        const e=document.createElement('div');e.style.padding='20px';e.style.color='#94a3b8';
        e.textContent='保存データはありません';U.storageList.appendChild(e);
    }
    keys.sort().forEach(key=>{
        try{
            const p=JSON.parse(localStorage.getItem(key)),row=document.createElement('div');
            row.className='storage-item';
            const info=document.createElement('div');info.className='storage-info';
            const name=document.createElement('div');name.className='storage-name';name.textContent=p.name;
            const meta=document.createElement('div');meta.className='storage-meta';
            meta.textContent=`${p.videoName||'動画未設定'} / ${p.elements?.length||0}要素`;
            info.append(name,meta);
            const load=document.createElement('button');load.className='btn small';load.textContent='読込';
            load.onclick=()=>{loadProject(p);U.storage.style.display='none'};
            const del=document.createElement('button');del.className='btn small danger';del.textContent='削除';
            del.onclick=()=>{if(confirm('この保存データを削除しますか？')){localStorage.removeItem(key);openStorage()}};
            row.append(info,load,del);U.storageList.appendChild(row);
        }catch(_){}
    });
    U.storage.style.display='flex';
}

function loadProject(src){
    try{
        const p=normalizeProject(src);A.project=p;
        const vd=Number(U.video.duration);
        A.duration=vd>0?vd:p.duration;A.project.duration=A.duration;A.time=0;A.selected.clear();
        if(A.duration>0)enable();else disable();
        render();clean();toast('プロジェクトを読み込みました');
    }catch(e){toast('プロジェクトを読み込めません：'+e.message,true)}
}

function exportProject(){
    if(!A.project)return;
    const blob=new Blob([JSON.stringify(A.project,null,2)],{type:'application/json'});
    const url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download=(A.project.name||'video-project')+'.json';document.body.appendChild(a);a.click();a.remove();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
}

U.import.addEventListener('change',async ev=>{
    const f=ev.target.files?.[0];if(!f)return;
    try{loadProject(JSON.parse(await f.text()))}catch(e){toast('JSONを読み込めません：'+e.message,true)}
    ev.target.value='';
});

/* ---------- buttons ---------- */

U.open.onclick=()=>U.file.click();
U.file.onchange=()=>loadVideo(U.file.files?.[0]);
U.new.onclick=resetApp;
U.save.onclick=saveLocal;
U.load.onclick=openStorage;
U.export.onclick=exportProject;
U.connect.onclick=connectSelected;
U.disconnect.onclick=disconnectSelected;

U.modalClose.onclick=closeModal;
U.modalCancel.onclick=closeModal;
U.modalSave.onclick=saveElement;
U.modalDelete.onclick=()=>{deleteSelected()};
U.storageClose.onclick=()=>U.storage.style.display='none';

document.addEventListener('keydown',ev=>{
    const tag=document.activeElement?.tagName;
    if(ev.key==='Escape'){closeContext();closeModal();U.storage.style.display='none'}
    if((ev.key==='Delete'||ev.key==='Backspace')&&!['INPUT','TEXTAREA','SELECT'].includes(tag)){
        ev.preventDefault();deleteSelected();
    }
    if(ev.key===' '&&!['INPUT','TEXTAREA','SELECT'].includes(tag)){
        ev.preventDefault();togglePlay();
    }
    if((ev.ctrlKey||ev.metaKey)&&ev.key.toLowerCase()==='s'){
        ev.preventDefault();saveLocal();
    }
});

/* ---------- initialization ---------- */

U.zoomValue.textContent='1.0×';
disable();
render();
</script>
</body>
</html>

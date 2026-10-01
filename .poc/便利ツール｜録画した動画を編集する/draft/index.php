<?php
declare(strict_types=1);

const APP_VERSION = '6.0.0';

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
        $project = $_POST['project'] ?? '';
        $decoded = json_decode($project, true);

        if ($project === '' || !is_array($decoded)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'invalid json'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="video-editor-project.json"');
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画編集・注釈ツール</title>
<style>
:root{
    --bg:#0b1220;--panel:#111b2a;--panel2:#182438;--line:#334155;
    --text:#e5e7eb;--muted:#94a3b8;--blue:#2563eb;--red:#ef4444;
    --comment:#60a5fa;--box:#22c55e;--skip:#f97316;
    --label:105px;--track:58px;--side:300px
}
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:var(--bg);color:var(--text);
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
.hidden{display:none!important}

.app{height:100vh;display:flex;flex-direction:column}
.topbar{height:52px;flex:none;display:flex;align-items:center;gap:7px;padding:7px 10px;
background:#080f1b;border-bottom:1px solid var(--line)}
.brand{font-weight:700;margin-right:7px;white-space:nowrap}
.btn{border:1px solid #40516a;border-radius:6px;background:#243247;color:var(--text);
padding:7px 11px}
.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:var(--blue);border-color:#3b82f6}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}
.status{margin-left:auto;color:var(--muted);font-size:12px;white-space:nowrap}
.file-input{display:none}

.main{min-height:0;flex:1;display:flex;flex-direction:column}
.video-area{min-height:250px;flex:1;display:flex;align-items:center;justify-content:center;
padding:12px;background:#050a11;overflow:hidden}
.video-wrap{position:relative;width:min(1100px,100%);height:100%;display:flex;
align-items:center;justify-content:center}
#video{display:block;max-width:100%;max-height:100%;background:#000}
.video-overlay{position:absolute;inset:0;pointer-events:none}
.overlay-element{position:absolute;min-width:30px;min-height:20px;pointer-events:auto;
user-select:none}
.overlay-element.selected{outline:2px solid #fff;outline-offset:2px}
.overlay-element.connected{box-shadow:0 0 0 2px #fbbf24,0 0 16px #fbbf2455}

.overlay-comment{
    width:100%;height:100%;display:flex;align-items:center;justify-content:center;
    padding:6px 9px;overflow:hidden;white-space:pre-wrap;word-break:break-word;
    background:#2563ebdd;border:1px solid #93c5fd;border-radius:6px;
    color:#fff;text-shadow:0 1px 2px #000;font-size:18px
}
.overlay-box{width:100%;height:100%;border:3px solid currentColor;background:#22c55e18}
.overlay-skip{
    width:100%;height:100%;display:flex;align-items:center;justify-content:center;
    border:2px dashed currentColor;background:#f973161f;color:currentColor;
    font-size:12px
}
.empty-video{position:absolute;text-align:center;color:#64748b;line-height:1.8;
pointer-events:none}
.empty-video strong{display:block;color:#94a3b8;font-size:18px}

.bottom{height:400px;flex:none;display:flex;min-height:0;border-top:1px solid var(--line);
background:#0e1724}
.timeline-panel{min-width:0;flex:1;display:flex;flex-direction:column}
.timeline-toolbar{height:43px;flex:none;display:flex;align-items:center;gap:7px;padding:5px 8px;
border-bottom:1px solid var(--line)}
.time-readout{min-width:125px;font-variant-numeric:tabular-nums;color:#dbeafe}
.selection-hint{color:#64748b;font-size:11px}
.zoom-control{margin-left:auto;display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12px}
.zoom-control input{width:120px}

.timeline-scroll{position:relative;flex:1;min-height:0;width:100%;overflow:auto}
.timeline-content{position:relative;width:100%;min-width:600px}
.axis-row{position:sticky;top:0;z-index:30;height:34px;width:100%;display:flex;
background:#131d29;border-bottom:1px solid var(--line)}
.axis-label{width:var(--label);min-width:var(--label);flex:none;display:flex;align-items:center;
padding-left:8px;background:#131d29;border-right:1px solid var(--line);font-size:12px;
position:sticky;left:0;z-index:40}
.axis-track{position:relative;height:100%;flex:1;min-width:0}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #334155;padding:5px 0 0 3px;
font-size:10px;color:#64748b;white-space:nowrap;pointer-events:none}

.track-row{height:var(--track);width:100%;display:flex;border-bottom:1px solid #263445}
.track-label{width:var(--label);min-width:var(--label);flex:none;display:flex;align-items:center;
gap:5px;padding:0 8px;background:#111b27;border-right:1px solid var(--line);font-size:12px;
position:sticky;left:0;z-index:20}
.type-dot{width:8px;height:8px;border-radius:50%;flex:none}
.track-lane{position:relative;flex:1;min-width:0}
.track-grid{position:absolute;inset:0;pointer-events:none}

.element-bar{position:absolute;top:9px;height:40px;min-width:12px;border:1px solid currentColor;
border-radius:5px;display:flex;align-items:center;overflow:visible;cursor:grab;
user-select:none;touch-action:none;z-index:5}
.element-bar:active{cursor:grabbing}
.element-bar.selected{box-shadow:0 0 0 2px #60a5fa}
.element-bar.connected{box-shadow:0 0 0 2px #fbbf24}
.bar-label{padding:0 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;
pointer-events:none}
.handle{position:absolute;top:0;width:10px;height:100%;z-index:8}
.handle.left{left:-5px;cursor:ew-resize}
.handle.right{right:-5px;cursor:ew-resize}

.playhead{position:absolute;top:34px;bottom:0;width:3px;z-index:80;pointer-events:auto;
background:var(--red);box-shadow:0 0 5px #ef444499;cursor:ew-resize;touch-action:none}
.playhead::before{content:"";position:absolute;top:-1px;left:-5px;width:13px;height:13px;
background:var(--red);clip-path:polygon(0 0,100% 0,50% 100%)}
.playhead-label{position:absolute;top:12px;left:6px;padding:2px 4px;background:var(--red);
color:#fff;font-size:10px;white-space:nowrap;border-radius:3px}

.connection-svg{position:absolute;left:var(--label);right:0;top:34px;
width:calc(100% - var(--label));height:calc(100% - 34px);z-index:65;
overflow:visible;pointer-events:none}
.connection-path{fill:none;stroke:#fbbf24;stroke-width:2;stroke-dasharray:6 4}
.connection-path.active{stroke-width:4;filter:drop-shadow(0 0 3px #fbbf24)}
.connection-dot{fill:#fbbf24;stroke:#111827;stroke-width:2}

.sidebar{width:var(--side);min-width:var(--side);display:flex;flex-direction:column;
border-left:1px solid var(--line);background:#111b2a}
.sidebar-head{height:43px;display:flex;align-items:center;justify-content:space-between;
padding:6px 9px;border-bottom:1px solid var(--line)}
.sidebar-title{font-weight:600;font-size:13px}
.comments-list{flex:1;min-height:0;overflow:auto;padding:7px}
.annotation{position:relative;padding:8px 9px;margin-bottom:6px;border:1px solid #334155;
border-radius:6px;background:#172235;cursor:pointer}
.annotation:hover{background:#1d2b40}
.annotation.selected{border-color:#60a5fa}
.annotation.active{border-color:#fbbf24;background:#302b1c}
.annotation-head{display:flex;align-items:center;gap:6px;margin-bottom:4px}
.annotation-time{font-size:10px;color:#fbbf24;font-variant-numeric:tabular-nums}
.annotation-text{font-size:12px;line-height:1.45;white-space:pre-wrap;word-break:break-word}
.annotation-target{margin-top:5px;font-size:10px;color:#94a3b8}
.annotation-actions{position:absolute;right:5px;top:4px;display:flex;gap:2px}
.annotation-actions button{border:0;background:transparent;color:#64748b;padding:2px}
.annotation-actions button:hover{color:#fff}

.modal-backdrop{position:fixed;inset:0;z-index:3000;display:none;align-items:center;
justify-content:center;padding:20px;background:#000a}
.modal{width:min(560px,100%);background:#182231;border:1px solid #475569;border-radius:8px;
overflow:hidden}
.modal-head,.modal-foot{padding:12px 15px}
.modal-head{display:flex;justify-content:space-between;border-bottom:1px solid var(--line)}
.modal-body{padding:15px;display:grid;gap:12px;max-height:75vh;overflow:auto}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--line)}
.field{display:grid;gap:5px}
.field label{font-size:12px;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;padding:7px;background:#0f1722;color:var(--text);
border:1px solid #40516a;border-radius:5px}
.field textarea{min-height:90px;resize:vertical}
.color-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:6px}
.color-choice{height:28px;border:2px solid transparent;border-radius:4px}
.color-choice.active{border-color:#fff;box-shadow:0 0 0 1px #60a5fa}

.context-menu{position:fixed;display:none;z-index:2000;min-width:230px;padding:5px;
background:#172235;border:1px solid #475569;border-radius:6px;box-shadow:0 12px 30px #0006}
.context-menu button{display:block;width:100%;padding:8px;border:0;background:transparent;
color:var(--text);text-align:left;border-radius:4px}
.context-menu button:hover{background:#293a52}

.storage-item{display:flex;align-items:center;gap:8px;padding:10px;border-bottom:1px solid #334155}
.storage-item .info{min-width:0;flex:1}
.storage-item .name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.storage-item .meta{font-size:11px;color:#64748b}
.toast{position:fixed;right:15px;bottom:15px;z-index:5000;display:none;padding:10px 14px;
background:#1e293b;border:1px solid #475569;border-radius:6px;box-shadow:0 10px 30px #0006}

@media(max-width:900px){
    :root{--label:90px;--side:250px}
    .brand{display:none}
}
@media(max-width:700px){
    .sidebar{display:none}
}
</style>
</head>
<body>
<div class="app">

<header class="topbar">
    <div class="brand">動画編集・注釈</div>

    <button class="btn primary" id="openVideoBtn">動画を読み込む</button>
    <input class="file-input" id="videoFile" type="file" accept="video/*">

    <button class="btn" id="newProjectBtn">新規</button>
    <button class="btn" id="saveProjectBtn" disabled>保存</button>
    <button class="btn" id="loadProjectBtn">保存データ</button>
    <button class="btn" id="exportProjectBtn">JSON書き出し</button>

    <label class="btn">
        JSON読込
        <input class="file-input" id="importProjectInput" type="file"
               accept=".json,application/json">
    </label>

    <span class="status" id="statusText">動画を読み込んでください</span>
</header>

<main class="main">

<section class="video-area">
    <div class="video-wrap" id="videoWrap">
        <video id="video" preload="metadata" playsinline></video>
        <div class="video-overlay" id="videoOverlay"></div>

        <div class="empty-video" id="emptyVideo">
            <strong>動画を読み込んでください</strong>
            動画上で右クリックするとコメント・強調枠・スキップを追加できます
        </div>
    </div>
</section>

<section class="bottom">

<section class="timeline-panel">

<div class="timeline-toolbar">
    <button class="btn small" id="playBtn" disabled>▶</button>
    <button class="btn small" id="stopBtn" disabled>■</button>
    <span class="time-readout" id="timeReadout">00:00.000 / 00:00.000</span>
    <span class="selection-hint" id="selectionHint"></span>

    <div class="zoom-control">
        <span>時間スケール</span>
        <input id="zoomRange" type="range" min="1" max="5" step=".1" value="1">
        <span id="zoomValue">1.0×</span>
    </div>
</div>

<div class="timeline-scroll" id="timelineScroll">
    <div class="timeline-content" id="timelineContent">

        <div class="axis-row">
            <div class="axis-label">時間</div>
            <div class="axis-track" id="axisTrack"></div>
        </div>

        <div id="tracksContainer"></div>
        <svg class="connection-svg" id="connectionSvg"></svg>

        <div class="playhead" id="playhead">
            <span class="playhead-label" id="playheadLabel">00:00.000</span>
        </div>

    </div>
</div>

</section>

<aside class="sidebar">
    <div class="sidebar-head">
        <span class="sidebar-title">注釈</span>
        <button class="btn small" id="addAnnotationBtn" disabled>＋追加</button>
    </div>
    <div class="comments-list" id="commentsList"></div>
</aside>

</section>
</main>
</div>

<div class="context-menu" id="contextMenu">
    <button id="ctxAddComment">コメントを追加</button>
    <button id="ctxAddBox">強調枠を追加</button>
    <button id="ctxAddSkip">スキップを追加</button>
    <button id="ctxAddAnnotation">この位置に注釈を追加</button>
    <button id="ctxEdit">選択要素を編集</button>
    <button id="ctxConnect">コメントと強調枠を接続</button>
    <button id="ctxDisconnect">接続を解除</button>
    <button id="ctxDelete">削除</button>
</div>

<div class="modal-backdrop" id="elementModal">
<div class="modal">
    <div class="modal-head">
        <strong id="elementModalTitle">要素を編集</strong>
        <button class="btn small" id="elementModalClose">閉じる</button>
    </div>

    <div class="modal-body">

        <div class="field">
            <label>要素名</label>
            <input id="elementName">
        </div>

        <div class="field" id="commentTextField">
            <label>コメント</label>
            <textarea id="elementText" placeholder="動画上に表示するコメントを入力"></textarea>
        </div>

        <div class="field">
            <label>開始時間（秒）</label>
            <input id="elementStart" type="number" min="0" step=".001">
        </div>

        <div class="field">
            <label>終了時間（秒）</label>
            <input id="elementEnd" type="number" min="0" step=".001">
        </div>

        <div class="field">
            <label>横位置（%）</label>
            <input id="elementX" type="number" min="0" max="99" step=".1">
        </div>

        <div class="field">
            <label>縦位置（%）</label>
            <input id="elementY" type="number" min="0" max="99" step=".1">
        </div>

        <div class="field">
            <label>幅（%）</label>
            <input id="elementW" type="number" min="1" max="100" step=".1">
        </div>

        <div class="field">
            <label>高さ（%）</label>
            <input id="elementH" type="number" min="1" max="100" step=".1">
        </div>

        <div class="field" id="fontField">
            <label>文字サイズ</label>
            <input id="elementFontSize" type="number" min="8" max="100">
        </div>

        <div class="field" id="weightField">
            <label>文字太さ</label>
            <select id="elementFontWeight">
                <option value="400">標準</option>
                <option value="700">太字</option>
                <option value="900">極太</option>
            </select>
        </div>

        <div class="field">
            <label>色</label>
            <div class="color-grid" id="colorGrid"></div>
        </div>

    </div>

    <div class="modal-foot">
        <button class="btn" id="elementModalCancel">キャンセル</button>
        <button class="btn primary" id="elementModalSave">保存</button>
    </div>
</div>
</div>

<div class="modal-backdrop" id="annotationModal">
<div class="modal">
    <div class="modal-head">
        <strong>注釈</strong>
        <button class="btn small" id="annotationModalClose">閉じる</button>
    </div>

    <div class="modal-body">

        <div class="field">
            <label>注釈内容</label>
            <textarea id="annotationText" placeholder="この位置についてのメモ"></textarea>
        </div>

        <div class="field">
            <label>時間</label>
            <input id="annotationTime" type="number" min="0" step=".001">
        </div>

        <div class="field">
            <label>関連する動画要素</label>
            <select id="annotationTarget">
                <option value="">なし</option>
            </select>
        </div>

    </div>

    <div class="modal-foot">
        <button class="btn danger" id="annotationDelete">削除</button>
        <button class="btn" id="annotationCancel">キャンセル</button>
        <button class="btn primary" id="annotationSave">保存</button>
    </div>
</div>
</div>

<div class="modal-backdrop" id="storageModal">
<div class="modal">
    <div class="modal-head">
        <strong>保存データ</strong>
        <button class="btn small" id="storageClose">閉じる</button>
    </div>
    <div class="modal-body" id="storageList"></div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $=id=>document.getElementById(id);

const ui={
    video:$('video'),videoWrap:$('videoWrap'),overlay:$('videoOverlay'),
    empty:$('emptyVideo'),file:$('videoFile'),status:$('statusText'),
    open:$('openVideoBtn'),newBtn:$('newProjectBtn'),save:$('saveProjectBtn'),
    load:$('loadProjectBtn'),exportBtn:$('exportProjectBtn'),import:$('importProjectInput'),
    play:$('playBtn'),stop:$('stopBtn'),readout:$('timeReadout'),
    hint:$('selectionHint'),zoom:$('zoomRange'),zoomValue:$('zoomValue'),
    scroll:$('timelineScroll'),content:$('timelineContent'),axis:$('axisTrack'),
    tracks:$('tracksContainer'),svg:$('connectionSvg'),playhead:$('playhead'),
    playheadLabel:$('playheadLabel'),menu:$('contextMenu'),elementModal:$('elementModal'),
    annotationModal:$('annotationModal'),storageModal:$('storageModal'),
    comments:$('commentsList'),addAnnotation:$('addAnnotationBtn')
};

const state={
    project:null,duration:0,currentTime:0,videoUrl:'',
    videoBlob:null,selectedIds:new Set(),selectedAnnotationId:null,
    contextElementId:null,pendingXY:null,drag:null,modalElementId:null,
    modalAnnotationId:null,dirty:false,
    colors:['#60a5fa','#22c55e','#f59e0b','#ef4444','#c084fc',
            '#14b8a6','#f97316','#e879f9','#38bdf8','#a3e635',
            '#fb7185','#facc15']
};

function uid(prefix='id'){
    return prefix+'-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,9);
}

function clamp(v,min,max){return Math.max(min,Math.min(max,v))}

function fmtTime(v){
    v=Math.max(0,Number(v)||0);
    return `${String(Math.floor(v/60)).padStart(2,'0')}:${String(Math.floor(v%60)).padStart(2,'0')}.${String(Math.floor(v%1*1000)).padStart(3,'0')}`;
}

function shortTime(v){
    v=Number(v)||0;
    return v<60?`${v.toFixed(v%1?1:0)}s`:
        `${String(Math.floor(v/60)).padStart(2,'0')}:${String(Math.floor(v%60)).padStart(2,'0')}`;
}

function typeColor(type){
    return type==='comment'?'#60a5fa':type==='box'?'#22c55e':'#f97316';
}

function rgba(hex,a){
    const n=parseInt(hex.slice(1),16);
    return `rgba(${n>>16},${n>>8&255},${n&255},${a})`;
}

function toast(message,error=false){
    ui.status.textContent=message;
    const t=$('toast');
    t.textContent=message;
    t.style.display='block';
    t.style.borderColor=error?'#ef4444':'#475569';
    clearTimeout(toast.timer);
    toast.timer=setTimeout(()=>t.style.display='none',2200);
}

function status(message){ui.status.textContent=message}

function markDirty(){
    state.dirty=true;
    status('変更あり');
}

function markClean(){
    state.dirty=false;
    status(state.project?`編集中：${state.project.name}`:'動画を読み込んでください');
}

function getElement(id){
    return state.project?.elements.find(e=>e.id===id)||null;
}

function getAnnotation(id){
    return state.project?.annotations.find(a=>a.id===id)||null;
}

function canEdit(){
    return !!state.project&&state.duration>0;
}

function isVisible(e){
    return state.currentTime>=e.start&&state.currentTime<e.end;
}

function emptyProject(name='新規プロジェクト'){
    return {
        version:6,
        name,
        videoName:state.videoBlob?.name||'video',
        duration:state.duration,
        elements:[],
        annotations:[]
    };
}

function normalizeProject(source){
    if(!source||typeof source!=='object')throw Error('プロジェクト形式が不正です');

    const duration=Math.max(.05,Number(source.duration)||state.duration||.05);
    const p={
        version:6,
        name:String(source.name||'プロジェクト'),
        videoName:String(source.videoName||'video'),
        duration,
        elements:[],
        annotations:[]
    };

    const rawElements=Array.isArray(source.elements)?source.elements:[];

    p.elements=rawElements.map(raw=>{
        const type=['comment','box','skip'].includes(raw.type)?raw.type:'comment';
        const start=clamp(Number(raw.start)||0,0,duration);
        const end=clamp(Number(raw.end)||Math.min(duration,start+3),start+.05,duration);

        return {
            id:String(raw.id||uid('el')),
            type,
            name:String(raw.name||(type==='comment'?'コメント':type==='box'?'強調枠':'スキップ')),
            text:String(raw.text||''),
            start,
            end,
            x:clamp(Number(raw.x)||5,0,99),
            y:clamp(Number(raw.y)||5,0,99),
            w:clamp(Number(raw.w)||35,1,100),
            h:clamp(Number(raw.h)||18,1,100),
            color:String(raw.color||typeColor(type)),
            fontSize:clamp(Number(raw.fontSize)||28,8,100),
            fontWeight:['400','700','900'].includes(String(raw.fontWeight))?
                String(raw.fontWeight):'700'
        };
    });

    const ids=new Set(p.elements.map(e=>e.id));
    const comments=new Map(p.elements.filter(e=>e.type==='comment').map(e=>[e.id,e.id]));
    const boxes=new Map(p.elements.filter(e=>e.type==='box').map(e=>[e.id,e.id]));

    /*
     * 新形式では commentTarget をコメント要素側に持たせる。
     * 古いデータに接続情報があれば可能な範囲で移行する。
     */
    p.elements.forEach(e=>{
        if(e.type==='comment'){
            const target=String(rawElements.find(x=>String(x.id)===e.id)?.target||'');
            e.target=boxes.has(target)?target:'';
        }else{
            e.target='';
        }
    });

    const rawAnnotations=Array.isArray(source.annotations)?
        source.annotations:
        Array.isArray(source.comments)?source.comments:[];

    p.annotations=rawAnnotations.map(raw=>({
        id:String(raw.id||uid('an')),
        text:String(raw.text||''),
        time:clamp(Number(raw.time)||0,0,duration),
        target:ids.has(String(raw.target))?String(raw.target):''
    })).filter(a=>a.text);

    return p;
}

function visibleRange(){
    if(!state.duration)return {start:0,end:0};

    const zoom=Math.max(1,Number(ui.zoom.value)||1);
    const span=state.duration/zoom;
    let start=state.currentTime-span/2;
    start=clamp(start,0,Math.max(0,state.duration-span));

    return {start,end:start+span};
}

function timeToPercent(t){
    const r=visibleRange();
    if(!r.end||r.end===r.start)return 0;
    return clamp((t-r.start)/(r.end-r.start),0,1)*100;
}

function timelineGeometry(){
    const r=ui.axis.getBoundingClientRect();
    return {left:r.left,width:r.width};
}

function xToTime(clientX){
    const g=timelineGeometry(),r=visibleRange();
    if(!g.width||!r.end)return 0;
    return clamp(r.start+(clientX-g.left)/g.width*(r.end-r.start),0,state.duration);
}

/* ---------- Timeline ---------- */

function renderAxis(){
    ui.axis.innerHTML='';
    if(!state.duration)return;

    const r=visibleRange();
    const width=Math.max(1,ui.axis.clientWidth);
    const approx=(r.end-r.start)/Math.max(1,width/85);
    const steps=[.1,.25,.5,1,2,5,10,15,30,60,120,300,600];
    const step=steps.find(v=>v>=approx)||600;
    const first=Math.ceil(r.start/step)*step;

    for(let t=first;t<=r.end+.0001;t+=step){
        const tick=document.createElement('div');
        tick.className='tick';
        tick.style.left=`${(t-r.start)/(r.end-r.start)*100}%`;
        tick.textContent=shortTime(t);
        ui.axis.appendChild(tick);
    }
}

function renderTimeline(){
    ui.tracks.innerHTML='';
    if(!state.project||!state.duration)return;

    const groups=[
        ['comment','コメント'],
        ['box','強調枠'],
        ['skip','スキップ']
    ];

    groups.forEach(([type,label])=>{
        const row=document.createElement('div');
        row.className='track-row';

        const trackLabel=document.createElement('div');
        trackLabel.className='track-label';

        const dot=document.createElement('span');
        dot.className='type-dot';
        dot.style.background=typeColor(type);

        trackLabel.append(dot,document.createTextNode(label));

        const lane=document.createElement('div');
        lane.className='track-lane';

        const grid=document.createElement('div');
        grid.className='track-grid';
        grid.style.backgroundImage=
            'linear-gradient(to right,rgba(148,163,184,.09) 1px,transparent 1px)';
        grid.style.backgroundSize=
            `${Math.max(10,100/Math.max(1,state.duration))}% 100%`;

        lane.appendChild(grid);

        state.project.elements
            .filter(e=>e.type===type)
            .forEach(e=>lane.appendChild(createBar(e)));

        row.append(trackLabel,lane);
        ui.tracks.appendChild(row);
    });

    renderAxis();
}

function createBar(e){
    const bar=document.createElement('div');
    bar.className='element-bar';
    bar.dataset.id=e.id;
    bar.style.left=`${timeToPercent(e.start)}%`;
    bar.style.width=`${Math.max(.7,timeToPercent(e.end)-timeToPercent(e.start))}%`;
    bar.style.color=e.color;
    bar.style.background=rgba(e.color,.2);

    if(state.selectedIds.has(e.id))bar.classList.add('selected');

    if(e.type==='comment'&&e.target)bar.classList.add('connected');

    const label=document.createElement('span');
    label.className='bar-label';
    label.textContent=`${e.name} ${fmtTime(e.start)} ～ ${fmtTime(e.end)}`;

    const left=document.createElement('span');
    left.className='handle left';
    left.dataset.edge='left';

    const right=document.createElement('span');
    right.className='handle right';
    right.dataset.edge='right';

    bar.append(label,left,right);

    bar.addEventListener('pointerdown',beginDrag);
    bar.addEventListener('click',ev=>{
        ev.stopPropagation();
        selectElement(e.id,ev.shiftKey);
    });
    bar.addEventListener('dblclick',ev=>{
        ev.stopPropagation();
        openElement(e.id);
    });
    bar.addEventListener('contextmenu',ev=>{
        ev.preventDefault();
        ev.stopPropagation();
        selectElement(e.id,ev.shiftKey);
        openMenu(ev.clientX,ev.clientY,e.id);
    });

    return bar;
}

function selectElement(id,additive=false){
    if(!getElement(id))return;

    if(!additive)state.selectedIds.clear();

    if(additive&&state.selectedIds.has(id))
        state.selectedIds.delete(id);
    else
        state.selectedIds.add(id);

    state.contextElementId=id;
    updateHint();
    renderAll();
}

function updateHint(){
    ui.hint.textContent=state.selectedIds.size?
        `${state.selectedIds.size}要素選択中`:'';
}

/* ---------- Video overlay ---------- */

function renderOverlay(){
    ui.overlay.innerHTML='';
    if(!canEdit())return;

    state.project.elements.forEach(e=>{
        if(!isVisible(e))return;

        const node=document.createElement('div');
        node.className='overlay-element';
        node.dataset.id=e.id;
        node.style.left=e.x+'%';
        node.style.top=e.y+'%';
        node.style.width=e.w+'%';
        node.style.height=e.h+'%';
        node.style.color=e.color;

        if(state.selectedIds.has(e.id))node.classList.add('selected');
        if(e.type==='comment'&&e.target)node.classList.add('connected');

        if(e.type==='comment'){
            const text=document.createElement('div');
            text.className='overlay-comment';
            text.textContent=e.text||e.name;
            text.style.fontSize=e.fontSize+'px';
            text.style.fontWeight=e.fontWeight;
            text.style.background=rgba(e.color,.88);
            node.appendChild(text);
        }else if(e.type==='box'){
            const box=document.createElement('div');
            box.className='overlay-box';
            node.appendChild(box);
        }else{
            const skip=document.createElement('div');
            skip.className='overlay-skip';
            skip.textContent='スキップ';
            node.appendChild(skip);
        }

        node.addEventListener('pointerdown',ev=>ev.stopPropagation());
        node.addEventListener('click',ev=>{
            ev.stopPropagation();
            selectElement(e.id,ev.shiftKey);
        });
        node.addEventListener('dblclick',ev=>{
            ev.stopPropagation();
            openElement(e.id);
        });

        ui.overlay.appendChild(node);
    });
}

/* ---------- Connections ---------- */

function getBar(id){
    return ui.tracks.querySelector(`.element-bar[data-id="${CSS.escape(id)}"]`);
}

function getOverlay(id){
    return ui.overlay.querySelector(`.overlay-element[data-id="${CSS.escape(id)}"]`);
}

function elementCenter(id){
    const element=getElement(id);
    if(!element)return null;

    const overlay=getOverlay(id);
    const bar=getBar(id);

    if(overlay){
        const r=overlay.getBoundingClientRect();
        const base=ui.content.getBoundingClientRect();
        return {
            x:r.left+r.width/2-base.left,
            y:r.top+r.height/2-base.top
        };
    }

    if(bar){
        const r=bar.getBoundingClientRect();
        const base=ui.content.getBoundingClientRect();
        return {
            x:r.left+r.width/2-base.left,
            y:r.top+r.height/2-base.top
        };
    }

    return null;
}

function renderConnections(){
    ui.svg.innerHTML='';
    if(!state.project)return;

    const base=ui.content.getBoundingClientRect();
    const height=Math.max(ui.content.scrollHeight,ui.content.clientHeight);

    ui.svg.setAttribute('viewBox',`0 0 ${base.width} ${height}`);
    ui.svg.setAttribute('width',base.width);
    ui.svg.setAttribute('height',height);

    /*
     * 接続線は「コメント要素 -> 強調枠」のみ。
     */
    state.project.elements
        .filter(e=>e.type==='comment'&&e.target)
        .forEach(comment=>{
            const box=getElement(comment.target);
            if(!box||box.type!=='box')return;

            const a=elementCenter(comment.id);
            const b=elementCenter(box.id);
            if(!a||!b)return;

            const path=document.createElementNS('http://www.w3.org/2000/svg','path');
            path.classList.add('connection-path');

            if(state.selectedIds.has(comment.id)||state.selectedIds.has(box.id))
                path.classList.add('active');

            const distance=Math.max(30,Math.abs(a.x-b.x)*.3);

            path.setAttribute(
                'd',
                `M ${a.x} ${a.y}
                 C ${a.x+distance} ${a.y},
                   ${b.x-distance} ${b.y},
                   ${b.x} ${b.y}`
            );

            ui.svg.appendChild(path);

            const dot=document.createElementNS('http://www.w3.org/2000/svg','circle');
            dot.classList.add('connection-dot');
            dot.setAttribute('cx',b.x);
            dot.setAttribute('cy',b.y);
            dot.setAttribute('r',state.selectedIds.has(comment.id)?6:4);

            ui.svg.appendChild(dot);
        });
}

/* ---------- Annotations ---------- */

function renderAnnotations(){
    ui.comments.innerHTML='';
    if(!state.project)return;

    [...state.project.annotations]
        .sort((a,b)=>a.time-b.time)
        .forEach(a=>{
            const item=document.createElement('div');
            item.className='annotation';
            item.dataset.id=a.id;

            if(state.selectedAnnotationId===a.id)item.classList.add('selected');
            if(Math.abs(a.time-state.currentTime)<.15)item.classList.add('active');

            const head=document.createElement('div');
            head.className='annotation-head';

            const time=document.createElement('span');
            time.className='annotation-time';
            time.textContent=fmtTime(a.time);

            const actions=document.createElement('div');
            actions.className='annotation-actions';

            const edit=document.createElement('button');
            edit.textContent='✎';
            edit.title='編集';
            edit.onclick=ev=>{
                ev.stopPropagation();
                openAnnotation(a.id);
            };

            const del=document.createElement('button');
            del.textContent='×';
            del.title='削除';
            del.onclick=ev=>{
                ev.stopPropagation();
                deleteAnnotation(a.id);
            };

            actions.append(edit,del);
            head.appendChild(time);

            const text=document.createElement('div');
            text.className='annotation-text';
            text.textContent=a.text;

            const target=document.createElement('div');
            target.className='annotation-target';
            target.textContent=a.target?
                `対象：${getElement(a.target)?.name||'不明'}`:
                '対象：なし';

            item.append(head,text,target,actions);

            item.onclick=()=>{
                state.selectedAnnotationId=a.id;
                seek(a.time);
                renderAll();
            };

            ui.comments.appendChild(item);
        });
}

function populateAnnotationTargets(){
    const select=$('annotationTarget');
    select.innerHTML='<option value="">なし</option>';

    state.project.elements.forEach(e=>{
        const option=document.createElement('option');
        option.value=e.id;
        option.textContent=`${e.name}（${typeName(e.type)}）`;
        select.appendChild(option);
    });
}

function typeName(type){
    return type==='comment'?'コメント':
        type==='box'?'強調枠':'スキップ';
}

function openAnnotation(id=null){
    if(!canEdit())return;

    state.modalAnnotationId=id;
    populateAnnotationTargets();

    const a=id?getAnnotation(id):null;

    $('annotationText').value=a?.text||'';
    $('annotationTime').value=a?.time??state.currentTime;
    $('annotationTarget').value=a?.target||'';

    $('annotationDelete').style.display=id?'':'none';
    ui.annotationModal.style.display='flex';

    setTimeout(()=>$('annotationText').focus(),0);
}

function saveAnnotation(){
    const text=$('annotationText').value.trim();
    const time=Number($('annotationTime').value);
    const target=$('annotationTarget').value;

    if(!text){
        toast('注釈内容を入力してください',true);
        return;
    }

    if(!Number.isFinite(time)||time<0||time>state.duration){
        toast('注釈時間が不正です',true);
        return;
    }

    if(state.modalAnnotationId){
        const a=getAnnotation(state.modalAnnotationId);
        if(!a)return;
        a.text=text;
        a.time=time;
        a.target=target;
    }else{
        const a={id:uid('an'),text,time,target};
        state.project.annotations.push(a);
        state.selectedAnnotationId=a.id;
    }

    ui.annotationModal.style.display='none';
    state.modalAnnotationId=null;
    markDirty();
    renderAll();
}

function deleteAnnotation(id=state.modalAnnotationId){
    if(!id)return;

    state.project.annotations=
        state.project.annotations.filter(a=>a.id!==id);

    state.selectedAnnotationId=null;
    state.modalAnnotationId=null;
    ui.annotationModal.style.display='none';

    markDirty();
    renderAll();
}

/* ---------- Element editor ---------- */

function buildColors(active){
    const grid=$('colorGrid');
    grid.innerHTML='';

    state.colors.forEach(color=>{
        const button=document.createElement('button');
        button.type='button';
        button.className='color-choice'+
            (color===active?' active':'');
        button.style.background=color;
        button.dataset.color=color;

        button.onclick=()=>{
            grid.querySelectorAll('.active')
                .forEach(x=>x.classList.remove('active'));
            button.classList.add('active');
        };

        grid.appendChild(button);
    });
}

function openElement(id){
    const e=getElement(id);
    if(!e)return;

    state.modalElementId=id;

    $('elementModalTitle').textContent=`${typeName(e.type)}を編集`;
    $('elementName').value=e.name;
    $('elementText').value=e.text;
    $('elementStart').value=e.start;
    $('elementEnd').value=e.end;
    $('elementX').value=e.x;
    $('elementY').value=e.y;
    $('elementW').value=e.w;
    $('elementH').value=e.h;
    $('elementFontSize').value=e.fontSize;
    $('elementFontWeight').value=e.fontWeight;

    const isComment=e.type==='comment';

    $('commentTextField').classList.toggle('hidden',!isComment);
    $('fontField').classList.toggle('hidden',!isComment);
    $('weightField').classList.toggle('hidden',!isComment);

    buildColors(e.color);
    ui.elementModal.style.display='flex';
}

function closeElement(){
    ui.elementModal.style.display='none';
    state.modalElementId=null;
}

function saveElement(){
    const e=getElement(state.modalElementId);
    if(!e)return;

    const start=Number($('elementStart').value);
    const end=Number($('elementEnd').value);

    if(!Number.isFinite(start)||!Number.isFinite(end)||
       start<0||end<=start||end>state.duration){
        toast('開始・終了時間が不正です',true);
        return;
    }

    e.name=$('elementName').value.trim()||typeName(e.type);
    e.text=$('elementText').value;
    e.start=start;
    e.end=end;
    e.x=clamp(Number($('elementX').value)||0,0,99);
    e.y=clamp(Number($('elementY').value)||0,0,99);
    e.w=clamp(Number($('elementW').value)||1,1,100);
    e.h=clamp(Number($('elementH').value)||1,1,100);
    e.fontSize=clamp(Number($('elementFontSize').value)||28,8,100);
    e.fontWeight=$('elementFontWeight').value;
    e.color=$('colorGrid .active')?.dataset.color||e.color;

    if(e.type==='comment'&&e.target){
        const target=getElement(e.target);
        if(!target||target.type!=='box')e.target='';
    }

    markDirty();
    closeElement();
    renderAll();
}

/* ---------- Add/delete ---------- */

function addElement(type){
    if(!canEdit()){
        toast('先に動画を読み込んでください',true);
        return;
    }

    const start=clamp(state.currentTime,0,Math.max(0,state.duration-.05));
    const end=Math.min(state.duration,start+3);

    const xy=state.pendingXY||{x:5,y:5};
    state.pendingXY=null;

    const element={
        id:uid('el'),
        type,
        name:typeName(type),
        text:type==='comment'?'コメント':'',
        start,
        end,
        x:xy.x,
        y:xy.y,
        w:type==='box'?35:35,
        h:type==='box'?25:18,
        color:typeColor(type),
        fontSize:28,
        fontWeight:'700',
        target:''
    };

    state.project.elements.push(element);
    state.selectedIds.clear();
    state.selectedIds.add(element.id);

    markDirty();
    renderAll();
    openElement(element.id);
}

function deleteSelected(){
    if(!state.selectedIds.size)return;

    const ids=new Set(state.selectedIds);

    state.project.elements=
        state.project.elements.filter(e=>!ids.has(e.id));

    /*
     * 削除した強調枠を参照しているコメントは接続解除。
     */
    state.project.elements.forEach(e=>{
        if(e.type==='comment'&&ids.has(e.target))e.target='';
    });

    state.project.annotations.forEach(a=>{
        if(ids.has(a.target))a.target='';
    });

    state.selectedIds.clear();
    state.contextElementId=null;

    markDirty();
    renderAll();
}

function connectCommentToBox(){
    const selected=[...state.selectedIds];

    if(selected.length!==2){
        toast('コメントと強調枠を1つずつ選択してください',true);
        return;
    }

    const a=getElement(selected[0]);
    const b=getElement(selected[1]);

    if(!a||!b){
        toast('要素が見つかりません',true);
        return;
    }

    const comment=a.type==='comment'?a:b;
    const box=a.type==='box'?a:b;

    if(!comment||!box||comment.type!=='comment'||box.type!=='box'){
        toast('接続できるのはコメントと強調枠です',true);
        return;
    }

    /*
     * 既に別の枠へ接続していた場合は付け替える。
     */
    comment.target=box.id;

    markDirty();
    renderAll();
    toast('コメントと強調枠を接続しました');
}

function disconnectSelected(){
    let changed=false;

    state.selectedIds.forEach(id=>{
        const e=getElement(id);
        if(e?.type==='comment'&&e.target){
            e.target='';
            changed=true;
        }
    });

    if(changed){
        markDirty();
        renderAll();
        toast('接続を解除しました');
    }else{
        toast('選択されたコメントに接続はありません',true);
    }
}

/* ---------- Drag ---------- */

function beginDrag(ev){
    const bar=ev.currentTarget;
    const e=getElement(bar.dataset.id);
    if(!e)return;

    ev.preventDefault();
    ev.stopPropagation();

    const handle=ev.target.closest('.handle');
    const lane=bar.parentElement;

    state.drag={
        id:e.id,
        mode:handle?'resize':'move',
        edge:handle?.dataset.edge||'',
        x:ev.clientX,
        laneWidth:lane.getBoundingClientRect().width,
        original:{start:e.start,end:e.end}
    };

    window.addEventListener('pointermove',moveDrag);
    window.addEventListener('pointerup',endDrag,{once:true});
}

function moveDrag(ev){
    const d=state.drag;
    if(!d)return;

    const e=getElement(d.id);
    if(!e)return;

    const range=visibleRange();
    const dt=(ev.clientX-d.x)/Math.max(1,d.laneWidth)*
        (range.end-range.start);
    const min=.05;

    if(d.mode==='move'){
        const length=d.original.end-d.original.start;
        e.start=clamp(d.original.start+dt,0,state.duration-length);
        e.end=e.start+length;
    }else if(d.edge==='left'){
        e.start=clamp(d.original.start+dt,0,d.original.end-min);
    }else{
        e.end=clamp(d.original.end+dt,d.original.start+min,state.duration);
    }

    markDirty();
    renderTimeline();
    renderOverlay();
    renderConnections();
    updatePlayhead();
}

function endDrag(){
    state.drag=null;
    window.removeEventListener('pointermove',moveDrag);
    renderAll();
}

/* ---------- Playhead ---------- */

function seek(value){
    state.currentTime=clamp(Number(value)||0,0,state.duration);

    try{
        ui.video.currentTime=state.currentTime;
    }catch(_){}

    renderAll();
}

let draggingPlayhead=false;

ui.playhead.addEventListener('pointerdown',ev=>{
    if(!canEdit())return;
    ev.preventDefault();
    ev.stopPropagation();
    draggingPlayhead=true;
    seek(xToTime(ev.clientX));
});

window.addEventListener('pointermove',ev=>{
    if(draggingPlayhead)seek(xToTime(ev.clientX));
});

window.addEventListener('pointerup',()=>{
    draggingPlayhead=false;
});

function updatePlayhead(){
    if(!canEdit()){
        ui.playhead.style.display='none';
        return;
    }

    ui.playhead.style.display='block';

    const p=timeToPercent(state.currentTime);
    ui.playhead.style.left=
        `calc(var(--label) + (100% - var(--label)) * ${p/100})`;

    ui.playheadLabel.textContent=fmtTime(state.currentTime);
    ui.readout.textContent=
        `${fmtTime(state.currentTime)} / ${fmtTime(state.duration)}`;
}

/* ---------- Context menu ---------- */

function openMenu(x,y,id=null){
    state.contextElementId=id;

    ui.menu.style.display='block';
    ui.menu.style.left=Math.min(x,innerWidth-245)+'px';
    ui.menu.style.top=Math.min(y,innerHeight-300)+'px';
}

function closeMenu(){
    ui.menu.style.display='none';
}

function createAt(type){
    addElement(type);
    closeMenu();
}

/* ---------- Local storage ---------- */

function saveLocal(){
    if(!state.project)return;

    const data=structuredClone(state.project);
    data.savedAt=new Date().toISOString();

    try{
        localStorage.setItem(
            'video-editor:'+data.name,
            JSON.stringify(data)
        );

        markClean();
        toast('保存しました');
    }catch(e){
        toast('保存容量を超えました',true);
    }
}

function loadProject(project){
    try{
        const normalized=normalizeProject(project);

        state.project=normalized;
        state.duration=normalized.duration;
        state.currentTime=0;
        state.selectedIds.clear();
        state.selectedAnnotationId=null;

        if(ui.video.duration&&Number.isFinite(ui.video.duration)){
            state.duration=ui.video.duration;
            state.project.duration=state.duration;
        }

        enableEditor();
        renderAll();
        markClean();
        toast('プロジェクトを読み込みました');
    }catch(e){
        toast('プロジェクトを読み込めません：'+e.message,true);
    }
}

function showStorage(){
    const list=$('storageList');
    list.innerHTML='';

    const keys=Object.keys(localStorage)
        .filter(k=>k.startsWith('video-editor:'))
        .sort();

    if(!keys.length){
        list.textContent='保存されたプロジェクトはありません。';
        ui.storageModal.style.display='flex';
        return;
    }

    keys.forEach(key=>{
        try{
            const project=JSON.parse(localStorage.getItem(key));

            const row=document.createElement('div');
            row.className='storage-item';

            const info=document.createElement('div');
            info.className='info';

            const name=document.createElement('div');
            name.className='name';
            name.textContent=project.name;

            const meta=document.createElement('div');
            meta.className='meta';
            meta.textContent=
                `${project.videoName||''} / `+
                `${project.elements?.length||0}要素 / `+
                `${project.annotations?.length||0}注釈`;

            info.append(name,meta);

            const load=document.createElement('button');
            load.className='btn small';
            load.textContent='読込';
            load.onclick=()=>{
                loadProject(project);
                ui.storageModal.style.display='none';
            };

            const del=document.createElement('button');
            del.className='btn small danger';
            del.textContent='削除';
            del.onclick=()=>{
                if(!confirm(`「${project.name}」を削除しますか？`))return;
                localStorage.removeItem(key);
                showStorage();
            };

            row.append(info,load,del);
            list.appendChild(row);
        }catch(_){}
    });

    ui.storageModal.style.display='flex';
}

function exportProject(){
    if(!state.project){
        toast('プロジェクトがありません',true);
        return;
    }

    const data=JSON.stringify(state.project,null,2);
    const blob=new Blob([data],{type:'application/json;charset=utf-8'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');

    a.href=url;
    a.download=(state.project.name||'video-editor-project')+'.json';
    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(()=>URL.revokeObjectURL(url),1000);
}

/* ---------- Video ---------- */

function enableEditor(){
    ui.play.disabled=false;
    ui.stop.disabled=false;
    ui.save.disabled=false;
    ui.addAnnotation.disabled=false;
    ui.empty.style.display='none';
}

function disableEditor(){
    ui.play.disabled=true;
    ui.stop.disabled=true;
    ui.save.disabled=true;
    ui.addAnnotation.disabled=true;
    ui.empty.style.display='block';

    ui.overlay.innerHTML='';
    ui.tracks.innerHTML='';
    ui.svg.innerHTML='';
    ui.comments.innerHTML='';
    ui.playhead.style.display='none';
}

function handleVideo(file){
    if(!file||!file.type.startsWith('video/')){
        toast('動画ファイルを選択してください',true);
        return;
    }

    if(state.videoUrl)URL.revokeObjectURL(state.videoUrl);

    state.videoBlob=file;
    state.videoUrl=URL.createObjectURL(file);
    state.project=emptyProject(file.name);
    state.duration=0;
    state.currentTime=0;

    disableEditor();

    ui.video.src=state.videoUrl;
    ui.video.load();

    status('動画を読み込んでいます…');
}

function newProject(){
    if(state.dirty&&!confirm('未保存の変更があります。新規プロジェクトを作成しますか？'))
        return;

    state.project=null;
    state.duration=0;
    state.currentTime=0;
    state.selectedIds.clear();
    state.selectedAnnotationId=null;
    state.contextElementId=null;
    state.dirty=false;

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
        state.videoUrl='';
    }

    ui.video.removeAttribute('src');
    ui.video.load();

    disableEditor();
    status('動画を読み込んでください');
}

/* ---------- Events ---------- */

ui.open.onclick=()=>ui.file.click();

ui.file.onchange=()=>{
    handleVideo(ui.file.files[0]);
    ui.file.value='';
};

ui.newBtn.onclick=newProject;
ui.save.onclick=saveLocal;
ui.load.onclick=showStorage;
ui.exportBtn.onclick=exportProject;

ui.import.onchange=async ev=>{
    const file=ev.target.files[0];
    if(!file)return;

    try{
        const project=JSON.parse(await file.text());
        loadProject(project);
    }catch(e){
        toast('JSONを読み込めません：'+e.message,true);
    }

    ev.target.value='';
};

ui.video.onloadedmetadata=()=>{
    state.duration=Number(ui.video.duration);

    if(!state.project)
        state.project=emptyProject();

    state.project.duration=state.duration;
    state.currentTime=0;

    enableEditor();
    renderAll();
    markClean();
};

ui.video.ontimeupdate=()=>{
    if(!ui.video.paused){
        state.currentTime=ui.video.currentTime;
        renderAll();
    }
};

ui.video.onended=()=>{
    ui.play.textContent='▶';
    state.currentTime=state.duration;
    renderAll();
};

ui.play.onclick=async()=>{
    if(!canEdit())return;

    if(ui.video.paused){
        try{
            await ui.video.play();
            ui.play.textContent='Ⅱ';
        }catch(_){
            toast('動画を再生できません',true);
        }
    }else{
        ui.video.pause();
        ui.play.textContent='▶';
    }
};

ui.stop.onclick=()=>{
    ui.video.pause();
    ui.play.textContent='▶';
    seek(0);
};

ui.zoom.oninput=()=>{
    ui.zoomValue.textContent=
        Number(ui.zoom.value).toFixed(1)+'×';
    renderAll();
};

/*
 * タイムラインをクリックして再生位置を変更。
 */
ui.content.addEventListener('pointerdown',ev=>{
    if(ev.target.closest('.element-bar')||
       ev.target.closest('.playhead'))return;

    if(ev.target.closest('.axis-track')||
       ev.target.closest('.track-lane')){
        seek(xToTime(ev.clientX));
    }
});

/*
 * 動画上で右クリック。
 * クリック位置をパーセント座標として保存し、
 * その場所から要素を追加する。
 */
ui.videoWrap.addEventListener('contextmenu',ev=>{
    ev.preventDefault();

    if(!canEdit())return;

    const rect=ui.video.getBoundingClientRect();
    if(!rect.width||!rect.height)return;

    state.pendingXY={
        x:clamp((ev.clientX-rect.left)/rect.width*100,0,99),
        y:clamp((ev.clientY-rect.top)/rect.height*100,0,99)
    };

    openMenu(ev.clientX,ev.clientY);
});

ui.addAnnotation.onclick=()=>openAnnotation();

$('ctxAddComment').onclick=()=>{
    createAt('comment');
};

$('ctxAddBox').onclick=()=>{
    createAt('box');
};

$('ctxAddSkip').onclick=()=>{
    createAt('skip');
};

$('ctxAddAnnotation').onclick=()=>{
    openAnnotation();
    closeMenu();
};

$('ctxEdit').onclick=()=>{
    if(state.contextElementId)
        openElement(state.contextElementId);
    closeMenu();
};

$('ctxConnect').onclick=()=>{
    connectCommentToBox();
    closeMenu();
};

$('ctxDisconnect').onclick=()=>{
    disconnectSelected();
    closeMenu();
};

$('ctxDelete').onclick=()=>{
    deleteSelected();
    closeMenu();
};

document.addEventListener('click',ev=>{
    if(!ev.target.closest('.context-menu'))
        closeMenu();
});

document.addEventListener('keydown',ev=>{
    const tag=document.activeElement?.tagName;

    if(ev.key==='Escape'){
        closeMenu();
        closeElement();
        ui.annotationModal.style.display='none';
    }

    if((ev.key==='Delete'||ev.key==='Backspace')&&
       !['INPUT','TEXTAREA','SELECT'].includes(tag)){
        deleteSelected();
    }

    if(ev.key===' '&&
       !['INPUT','TEXTAREA'].includes(tag)){
        ev.preventDefault();
        ui.play.click();
    }
});

$('elementModalClose').onclick=closeElement;
$('elementModalCancel').onclick=closeElement;
$('elementModalSave').onclick=saveElement;

$('annotationModalClose').onclick=()=>{
    ui.annotationModal.style.display='none';
};
$('annotationCancel').onclick=()=>{
    ui.annotationModal.style.display='none';
};
$('annotationSave').onclick=saveAnnotation;
$('annotationDelete').onclick=()=>deleteAnnotation();

$('storageClose').onclick=()=>{
    ui.storageModal.style.display='none';
};

window.addEventListener('resize',renderAll);
ui.scroll.addEventListener('scroll',renderConnections);

function renderAll(){
    renderTimeline();
    renderAnnotations();
    renderOverlay();
    renderConnections();
    updatePlayhead();
    updateHint();
}

renderAll();
</script>
</body>
</html>

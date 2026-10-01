<?php
declare(strict_types=1);

/*
 * 動画注釈編集ツール
 * Apache + PHP / DB不要
 * index.php と同じ場所に projects/ を作成してJSONを保存します。
 */

const APP_VERSION = '9.0.0';
const PROJECT_DIR = __DIR__ . '/projects';

if (!is_dir(PROJECT_DIR)) {
    @mkdir(PROJECT_DIR, 0775, true);
}

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function safeName(string $name): string {
    $name = preg_replace('/[^\p{L}\p{N}_\-. ]/u', '_', $name) ?? 'project';
    return trim($name) ?: 'project';
}

function projectPath(string $name): string {
    return PROJECT_DIR . '/' . safeName($name) . '.json';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'health') {
        jsonResponse(['ok' => true, 'version' => APP_VERSION]);
    }

    if ($action === 'save') {
        $raw = $_POST['project'] ?? '';
        $project = json_decode($raw, true);

        if (!is_array($project)) {
            jsonResponse(['ok' => false, 'error' => 'JSONが不正です'], 400);
        }

        $name = safeName((string)($project['name'] ?? 'project'));
        $project['version'] = APP_VERSION;
        $project['savedAt'] = date('c');

        $json = json_encode(
            $project,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false || @file_put_contents(projectPath($name), $json, LOCK_EX) === false) {
            jsonResponse(['ok' => false, 'error' => 'JSONファイルを書き込めません'], 500);
        }

        jsonResponse(['ok' => true, 'name' => $name, 'savedAt' => $project['savedAt']]);
    }

    if ($action === 'list') {
        $items = [];

        foreach (glob(PROJECT_DIR . '/*.json') ?: [] as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (!is_array($data)) continue;

            $items[] = [
                'name' => (string)($data['name'] ?? basename($file, '.json')),
                'videoName' => (string)($data['videoName'] ?? ''),
                'elements' => count($data['elements'] ?? []),
                'savedAt' => (string)($data['savedAt'] ?? ''),
                'file' => basename($file)
            ];
        }

        usort($items, fn($a, $b) => strcmp($b['savedAt'], $a['savedAt']));
        jsonResponse(['ok' => true, 'items' => $items]);
    }

    if ($action === 'load') {
        $name = safeName((string)($_POST['name'] ?? ''));
        $file = projectPath($name);

        if (!is_file($file)) {
            jsonResponse(['ok' => false, 'error' => 'プロジェクトがありません'], 404);
        }

        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            jsonResponse(['ok' => false, 'error' => '保存データが壊れています'], 500);
        }

        jsonResponse(['ok' => true, 'project' => $data]);
    }

    if ($action === 'delete') {
        $name = safeName((string)($_POST['name'] ?? ''));
        $file = projectPath($name);

        if (is_file($file)) @unlink($file);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'export') {
        $project = json_decode($_POST['project'] ?? '', true);
        if (!is_array($project)) {
            jsonResponse(['ok' => false, 'error' => 'JSONが不正です'], 400);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="video-project.json"');
        echo json_encode($project, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
    --bg:#09111d;--panel:#101b2a;--panel2:#172438;--line:#334155;
    --text:#e5e7eb;--muted:#94a3b8;--blue:#2563eb;--red:#ef4444;
    --label:105px;--track:50px
}
*{box-sizing:border-box}
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:var(--bg);color:var(--text);
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
.hidden{display:none!important}

.app{height:100vh;display:flex;flex-direction:column}
.top{
    height:52px;flex:none;display:flex;align-items:center;gap:7px;padding:7px 10px;
    background:#070d17;border-bottom:1px solid var(--line)
}
.brand{font-weight:700;margin-right:8px;white-space:nowrap}
.btn{border:1px solid #40516a;border-radius:6px;background:#243247;color:var(--text);padding:7px 11px}
.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:var(--blue);border-color:#3b82f6}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}
.status{margin-left:auto;color:var(--muted);font-size:12px}
.file{display:none}

.main{flex:1;min-height:0;display:flex;flex-direction:column}
.video-area{
    flex:1;min-height:260px;display:flex;align-items:center;justify-content:center;
    padding:12px;background:#05090f;overflow:hidden
}
.stage{position:relative;line-height:0;max-width:100%;max-height:100%;overflow:hidden}
#video{display:block;max-width:100%;max-height:100%;background:#000;transform-origin:center center}
.overlay{position:absolute;inset:0;overflow:visible}
.svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible}
.empty{
    position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;
    justify-content:center;color:#64748b;line-height:1.8;text-align:center;pointer-events:none
}
.empty strong{color:#94a3b8;font-size:18px}

.element{
    position:absolute;min-width:12px;min-height:12px;z-index:5;
    user-select:none;touch-action:none;cursor:move
}
.element.selected{z-index:20}
.element-body{width:100%;height:100%;position:relative}
.element.selected .element-body{outline:2px solid #60a5fa;outline-offset:2px}
.comment{
    display:flex;align-items:center;justify-content:center;width:100%;height:100%;
    padding:5px 8px;background:#000b;border:1px solid currentColor;border-radius:4px;
    line-height:1.2;text-align:center;white-space:pre-wrap;overflow:hidden
}
.highlight{width:100%;height:100%;border:3px solid currentColor}
.highlight.circle{border-radius:50%}
.highlight.ellipse{border-radius:50%}
.skip{
    display:flex;align-items:center;justify-content:center;width:100%;height:100%;
    border:2px dashed #fb923c;background:#f9731626;color:#fdba74;border-radius:4px;
    font-size:12px;font-weight:700
}
.resize{
    display:none;position:absolute;width:10px;height:10px;background:#fff;
    border:1px solid #2563eb;border-radius:2px;z-index:30
}
.selected .resize{display:block}
.rnw{left:-5px;top:-5px;cursor:nwse-resize}
.rn{left:50%;top:-5px;transform:translateX(-50%);cursor:ns-resize}
.rne{right:-5px;top:-5px;cursor:nesw-resize}
.re{right:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}
.rse{right:-5px;bottom:-5px;cursor:nwse-resize}
.rs{left:50%;bottom:-5px;transform:translateX(-50%);cursor:ns-resize}
.rsw{left:-5px;bottom:-5px;cursor:nesw-resize}
.rw{left:-5px;top:50%;transform:translateY(-50%);cursor:ew-resize}

.bottom{
    height:330px;flex:none;display:flex;border-top:1px solid var(--line);background:#0e1724
}
.timeline{flex:1;min-width:0;display:flex;flex-direction:column}
.toolbar{
    height:43px;display:flex;align-items:center;gap:7px;padding:5px 8px;
    border-bottom:1px solid var(--line)
}
.readout{min-width:145px;color:#dbeafe;font-variant-numeric:tabular-nums}
.info{font-size:11px;color:var(--muted)}
.zoomCtl{margin-left:auto;display:flex;gap:6px;align-items:center;color:var(--muted);font-size:12px}
.zoomCtl input{width:110px}

.scroll{position:relative;flex:1;min-height:0;overflow:auto}
.content{position:relative;min-width:700px;width:100%}
.axis{height:34px;position:sticky;top:0;z-index:50;display:flex;background:#131d29;border-bottom:1px solid var(--line)}
.axis-label,.track-label{
    width:var(--label);min-width:var(--label);background:#111b27;
    border-right:1px solid var(--line);position:sticky;left:0;z-index:60
}
.axis-label{display:flex;align-items:center;padding-left:8px;font-size:12px}
.axis-track{position:relative;flex:1}
.tick{
    position:absolute;top:0;height:100%;border-left:1px solid #334155;
    padding:5px 0 0 3px;color:#64748b;font-size:10px;white-space:nowrap
}
.track{height:var(--track);display:flex;border-bottom:1px solid #263445}
.track-label{display:flex;align-items:center;gap:6px;padding:0 8px;font-size:12px;z-index:20}
.dot{width:8px;height:8px;border-radius:50%;flex:none}
.lane{position:relative;flex:1}
.bar{
    position:absolute;top:8px;height:34px;min-width:8px;
    border:1px solid currentColor;border-radius:5px;display:flex;align-items:center;
    cursor:grab;touch-action:none;user-select:none
}
.bar.selected{box-shadow:0 0 0 2px #60a5fa}
.bar-label{padding:0 6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;pointer-events:none}
.bar-handle{position:absolute;top:0;width:9px;height:100%;z-index:3}
.bar-handle.left{left:-5px;cursor:ew-resize}
.bar-handle.right{right:-5px;cursor:ew-resize}
.playhead{
    position:absolute;top:34px;bottom:0;width:3px;background:var(--red);
    z-index:80;cursor:ew-resize
}
.playhead:before{
    content:"";position:absolute;top:-1px;left:-5px;width:13px;height:13px;
    background:var(--red);clip-path:polygon(0 0,100% 0,50% 100%)
}
.playhead-label{
    position:absolute;top:12px;left:6px;background:var(--red);
    padding:2px 4px;border-radius:3px;color:#fff;font-size:10px;white-space:nowrap
}

.context{
    position:fixed;display:none;z-index:2000;min-width:210px;padding:5px;
    background:#172235;border:1px solid #475569;border-radius:6px;box-shadow:0 12px 30px #0008
}
.context button{
    display:block;width:100%;padding:8px;border:0;background:none;color:var(--text);
    text-align:left;border-radius:4px
}
.context button:hover{background:#293a52}
.context hr{border:0;border-top:1px solid #334155;margin:4px 0}

.backdrop{
    position:fixed;inset:0;z-index:3000;display:none;align-items:center;
    justify-content:center;padding:20px;background:#000a
}
.modal{
    width:min(590px,100%);max-height:90vh;display:flex;flex-direction:column;
    background:#182231;border:1px solid #475569;border-radius:8px;overflow:hidden
}
.head,.foot{padding:12px 15px;border-color:var(--line)}
.head{display:flex;justify-content:space-between;border-bottom:1px solid var(--line)}
.body{padding:15px;display:grid;gap:11px;overflow:auto}
.foot{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--line)}
.field{display:grid;gap:5px}
.field label{font-size:12px;color:var(--muted)}
.field input,.field select,.field textarea{
    width:100%;padding:7px;background:#0f1722;color:var(--text);
    border:1px solid #40516a;border-radius:5px
}
.field textarea{min-height:80px;resize:vertical}
.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.colors{display:grid;grid-template-columns:repeat(8,1fr);gap:6px}
.color{height:28px;border:2px solid transparent;border-radius:4px}
.color.active{border-color:#fff;box-shadow:0 0 0 1px #60a5fa}
.storage{padding:0!important;gap:0!important}
.storage-row{
    display:flex;align-items:center;gap:8px;padding:10px;border-bottom:1px solid #334155
}
.storage-info{flex:1;min-width:0}
.storage-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.storage-meta{color:#64748b;font-size:11px}
.toast{
    position:fixed;right:15px;bottom:15px;z-index:5000;display:none;
    padding:10px 14px;background:#1e293b;border:1px solid #475569;
    border-radius:6px;box-shadow:0 10px 30px #0006
}
@media(max-width:700px){
    :root{--label:88px}
    .brand{display:none}
    .bottom{height:300px}
}
</style>
</head>
<body>
<div class="app">

<header class="top">
    <div class="brand">動画注釈編集</div>
    <button class="btn primary" id="openVideo">動画を読み込む</button>
    <input class="file" id="videoFile" type="file" accept="video/*">
    <button class="btn" id="newProject">新規</button>
    <button class="btn" id="saveProject" disabled>保存</button>
    <button class="btn" id="loadProject">保存データ</button>
    <button class="btn" id="exportProject" disabled>JSON書出し</button>
    <label class="btn">JSON読込<input class="file" id="importProject" type="file" accept=".json,application/json"></label>
    <span class="status" id="status">動画を読み込んでください</span>
</header>

<main class="main">
<section class="video-area">
    <div class="stage" id="stage">
        <video id="video" preload="metadata" playsinline></video>
        <div class="overlay" id="overlay"><svg class="svg" id="svg"></svg></div>
        <div class="empty" id="empty">
            <strong>動画を読み込んでください</strong>
            <span>動画上を右クリックすると注釈を追加できます</span>
        </div>
    </div>
</section>

<section class="bottom">
<section class="timeline">
    <div class="toolbar">
        <button class="btn small" id="play" disabled>▶</button>
        <button class="btn small" id="stop" disabled>■</button>
        <button class="btn small" id="connect" disabled>🔗 接続</button>
        <button class="btn small" id="disconnect" disabled>接続解除</button>
        <span class="info" id="selectionInfo"></span>
        <span class="readout" id="readout">00:00.000 / 00:00.000</span>
        <div class="zoomCtl">
            <span>時間表示</span>
            <input id="timelineZoom" type="range" min="1" max="5" step=".1" value="1">
            <span id="timelineZoomValue">1.0×</span>
        </div>
    </div>

    <div class="scroll" id="scroll">
        <div class="content" id="content">
            <div class="axis">
                <div class="axis-label">時間</div>
                <div class="axis-track" id="axis"></div>
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

<div class="context" id="context">
    <button data-add="comment">＋ コメント</button>
    <button data-add="highlight">＋ 強調枠</button>
    <button data-add="skip">＋ スキップ</button>
    <button data-add="zoom">＋ 動画ズーム</button>
    <hr>
    <button id="contextEdit">編集</button>
    <button id="contextDelete">削除</button>
</div>

<div class="backdrop" id="elementModal">
<div class="modal">
    <div class="head">
        <strong id="modalTitle">要素編集</strong>
        <button class="btn small" id="modalClose">閉じる</button>
    </div>

    <div class="body">
        <div class="field">
            <label>要素名</label>
            <input id="name">
        </div>

        <div class="field" id="textField">
            <label>コメント</label>
            <textarea id="text"></textarea>
        </div>

        <div class="field hidden" id="shapeField">
            <label>強調枠の形</label>
            <select id="shape">
                <option value="square">四角</option>
                <option value="circle">丸</option>
                <option value="ellipse">楕円</option>
            </select>
        </div>

        <div class="two">
            <div class="field"><label>開始時間</label><input id="start" type="number" min="0" step=".001"></div>
            <div class="field"><label>終了時間</label><input id="end" type="number" min="0" step=".001"></div>
        </div>

        <div class="two" id="positionFields">
            <div class="field"><label>横位置 (%)</label><input id="x" type="number" step=".1"></div>
            <div class="field"><label>縦位置 (%)</label><input id="y" type="number" step=".1"></div>
        </div>

        <div class="two" id="sizeFields">
            <div class="field"><label>幅 (%)</label><input id="w" type="number" min="1" step=".1"></div>
            <div class="field"><label>高さ (%)</label><input id="h" type="number" min="1" step=".1"></div>
        </div>

        <div class="field hidden" id="scaleField">
            <label>動画拡大率</label>
            <input id="scale" type="number" min="1" max="5" step=".05">
        </div>

        <div class="field hidden" id="zoomPointField">
            <label>ズーム中心</label>
            <div class="two">
                <input id="zoomX" type="number" min="0" max="100" step=".1" placeholder="X %">
                <input id="zoomY" type="number" min="0" max="100" step=".1" placeholder="Y %">
            </div>
        </div>

        <div class="field" id="fontField">
            <label>文字サイズ</label>
            <input id="font" type="number" min="8" max="100">
        </div>

        <div class="field">
            <label>色</label>
            <div class="colors" id="colors"></div>
        </div>

        <div class="field" id="targetField">
            <label>接続する強調枠</label>
            <select id="target"></select>
        </div>
    </div>

    <div class="foot">
        <button class="btn danger" id="modalDelete">削除</button>
        <button class="btn" id="modalCancel">キャンセル</button>
        <button class="btn primary" id="modalSave">保存</button>
    </div>
</div>
</div>

<div class="backdrop" id="storageModal">
<div class="modal">
    <div class="head">
        <strong>保存データ</strong>
        <button class="btn small" id="storageClose">閉じる</button>
    </div>
    <div class="body storage" id="storageList"></div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
'use strict';

const $ = id => document.getElementById(id);

const UI = {
    video:$('video'), stage:$('stage'), overlay:$('overlay'), svg:$('svg'), empty:$('empty'),
    file:$('videoFile'), open:$('openVideo'), newBtn:$('newProject'),
    save:$('saveProject'), load:$('loadProject'), export:$('exportProject'),
    import:$('importProject'), status:$('status'),
    play:$('play'), stop:$('stop'), connect:$('connect'), disconnect:$('disconnect'),
    selection:$('selectionInfo'), readout:$('readout'),
    timelineZoom:$('timelineZoom'), timelineZoomValue:$('timelineZoomValue'),
    scroll:$('scroll'), content:$('content'), axis:$('axis'), tracks:$('tracks'),
    playhead:$('playhead'), playheadLabel:$('playheadLabel'), context:$('context'),
    modal:$('elementModal'), storage:$('storageModal'), storageList:$('storageList'),
    modalTitle:$('modalTitle'), name:$('name'), text:$('text'), shape:$('shape'),
    start:$('start'), end:$('end'), x:$('x'), y:$('y'), w:$('w'), h:$('h'),
    scale:$('scale'), zoomX:$('zoomX'), zoomY:$('zoomY'), font:$('font'),
    colors:$('colors'), target:$('target'),
    textField:$('textField'), shapeField:$('shapeField'), scaleField:$('scaleField'),
    zoomPointField:$('zoomPointField'), fontField:$('fontField'),
    targetField:$('targetField'), positionFields:$('positionFields'),
    sizeFields:$('sizeFields'), toast:$('toast')
};

const S = {
    version:'9.0.0',
    project:null,
    duration:0,
    time:0,
    url:'',
    selected:new Set(),
    modalId:null,
    contextId:null,
    contextPoint:null,
    drag:null,
    playheadDrag:false,
    dirty:false,
    colors:['#60a5fa','#22c55e','#f59e0b','#ef4444','#c084fc','#14b8a6',
            '#f97316','#e879f9','#38bdf8','#a3e635','#fb7185','#facc15']
};

const clamp=(v,min,max)=>Math.max(min,Math.min(max,v));
const el=id=>S.project?.elements.find(x=>x.id===id)||null;
const uid=()=>`e-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`;

function fmt(v){
    v=Math.max(0,Number(v)||0);
    return `${String(Math.floor(v/60)).padStart(2,'0')}:${String(Math.floor(v%60)).padStart(2,'0')}.${String(Math.floor(v%1*1000)).padStart(3,'0')}`;
}

function colorOf(type){
    return {comment:'#60a5fa',highlight:'#22c55e',skip:'#f97316',zoom:'#a855f7'}[type]||'#60a5fa';
}

function alpha(hex,a){
    const n=parseInt(hex.slice(1),16);
    return `rgba(${n>>16},${n>>8&255},${n&255},${a})`;
}

function toast(message,error=false){
    UI.status.textContent=message;
    UI.toast.textContent=message;
    UI.toast.style.display='block';
    UI.toast.style.borderColor=error?'#ef4444':'#475569';
    clearTimeout(toast.timer);
    toast.timer=setTimeout(()=>UI.toast.style.display='none',2500);
}

function markDirty(){
    S.dirty=true;
    UI.status.textContent='変更あり';
}

function markClean(){
    S.dirty=false;
    UI.status.textContent=S.project?`編集中：${S.project.name}`:'動画を読み込んでください';
}

function ready(){
    return !!S.project && S.duration>0;
}

function emptyProject(name='新規プロジェクト'){
    return {
        version:S.version,
        name,
        videoName:'',
        duration:S.duration,
        elements:[]
    };
}

function normalize(src){
    if(!src || typeof src!=='object') throw Error('プロジェクト形式が不正です');

    const duration=Number(src.duration)||S.duration||0;
    const p={
        version:S.version,
        name:String(src.name||'プロジェクト'),
        videoName:String(src.videoName||''),
        duration,
        elements:[]
    };

    const types=['comment','highlight','skip','zoom'];

    for(const r of Array.isArray(src.elements)?src.elements:[]){
        const type=types.includes(r.type)?r.type:'comment';
        const start=clamp(Number(r.start)||0,0,duration);
        const end=clamp(
            Number(r.end)||Math.min(duration,start+3),
            start+.05,
            Math.max(start+.05,duration)
        );

        p.elements.push({
            id:String(r.id||uid()),
            type,
            name:String(r.name||type),
            text:String(r.text||''),
            start,end,
            x:clamp(Number(r.x)||0,0,99),
            y:clamp(Number(r.y)||0,0,99),
            w:clamp(Number(r.w)||30,1,100),
            h:clamp(Number(r.h)||20,1,100),
            color:/^#[0-9a-f]{6}$/i.test(String(r.color||''))?r.color:colorOf(type),
            fontSize:clamp(Number(r.fontSize)||28,8,100),
            shape:['square','circle','ellipse'].includes(r.shape)?r.shape:'square',
            target:String(r.target||''),
            scale:clamp(Number(r.scale)||1,1,5),
            zoomX:clamp(Number(r.zoomX) || 50,0,100),
            zoomY:clamp(Number(r.zoomY) || 50,0,100)
        });
    }

    const ids=new Set(p.elements.map(x=>x.id));
    for(const e of p.elements){
        if(e.type==='comment'){
            const t=p.elements.find(x=>x.id===e.target);
            if(!ids.has(e.target)||t?.type!=='highlight') e.target='';
        }
    }

    return p;
}

/* ---------- 動画 ---------- */

function loadVideo(file){
    if(!file || !file.type.startsWith('video/')){
        toast('動画ファイルを選択してください',true);
        return;
    }

    if(S.url) URL.revokeObjectURL(S.url);

    S.url=URL.createObjectURL(file);
    S.project=emptyProject(file.name);
    S.project.videoName=file.name;
    S.duration=0;
    S.time=0;
    S.selected.clear();

    UI.video.src=S.url;
    UI.video.load();
    UI.status.textContent='動画を読み込んでいます…';
}

function reset(){
    UI.video.pause();

    if(S.url) URL.revokeObjectURL(S.url);

    S.url='';
    S.project=null;
    S.duration=0;
    S.time=0;
    S.selected.clear();
    S.dirty=false;

    UI.video.removeAttribute('src');
    UI.video.load();
    UI.empty.style.display='flex';

    setButtons(false);
    render();
    UI.status.textContent='動画を読み込んでください';
}

function setButtons(on){
    UI.play.disabled=!on;
    UI.stop.disabled=!on;
    UI.save.disabled=!on;
    UI.export.disabled=!on;
}

UI.video.onloadedmetadata=()=>{
    const d=Number(UI.video.duration);

    if(!Number.isFinite(d)||d<=0){
        toast('動画の長さを取得できません',true);
        return;
    }

    S.duration=d;
    S.time=0;

    if(S.project) S.project.duration=d;

    UI.empty.style.display='none';
    setButtons(true);
    render();
    markClean();
};

UI.video.ontimeupdate=()=>{
    if(!UI.video.paused){
        S.time=UI.video.currentTime;
        render();
    }
};

UI.video.onplay=()=>UI.play.textContent='Ⅱ';
UI.video.onpause=()=>UI.play.textContent='▶';
UI.video.onended=()=>{
    S.time=S.duration;
    render();
};

async function togglePlay(){
    if(!ready()) return;

    if(UI.video.paused){
        if(S.time>=S.duration-.01) seek(0);
        try{await UI.video.play()}
        catch(e){if(e.name!=='AbortError')toast('動画を再生できません',true)}
    }else{
        UI.video.pause();
    }
}

function stop(){
    UI.video.pause();
    seek(0);
}

function seek(t){
    if(!ready()) return;

    S.time=clamp(Number(t)||0,0,S.duration);

    try{
        if(Math.abs(UI.video.currentTime-S.time)>.002)
            UI.video.currentTime=S.time;
    }catch(_){}

    render();
}

/* ---------- タイムライン ---------- */

function range(){
    const scale=Number(UI.timelineZoom.value)||1;
    const span=S.duration/scale;
    const start=clamp(S.time-span/2,0,Math.max(0,S.duration-span));
    return {start,end:start+span};
}

function percentTime(t){
    const r=range();
    return r.end===r.start?0:clamp((t-r.start)/(r.end-r.start)*100,0,100);
}

function timeAt(clientX){
    const r=UI.axis.getBoundingClientRect();
    const q=range();

    if(!r.width) return S.time;

    return clamp(
        q.start+(clientX-r.left)/r.width*(q.end-q.start),
        0,S.duration
    );
}

function renderAxis(){
    UI.axis.innerHTML='';

    if(!S.duration) return;

    const r=range();
    const span=r.end-r.start;
    const width=Math.max(UI.axis.clientWidth,1);
    const approx=span/Math.max(width/80,1);
    const steps=[.1,.25,.5,1,2,5,10,15,30,60,120,300,600];
    const step=steps.find(v=>v>=approx)||600;

    for(let t=Math.ceil(r.start/step)*step;t<=r.end+.001;t+=step){
        const n=document.createElement('div');
        n.className='tick';
        n.style.left=((t-r.start)/span*100)+'%';
        n.textContent=fmt(t).slice(0,8);
        UI.axis.appendChild(n);
    }
}

const trackTypes=[
    ['comment','コメント'],
    ['highlight','強調枠'],
    ['skip','スキップ'],
    ['zoom','動画ズーム']
];

function renderTimeline(){
    UI.tracks.innerHTML='';

    if(!ready()){
        renderAxis();
        return;
    }

    for(const [type,label] of trackTypes){
        const row=document.createElement('div');
        row.className='track';

        const head=document.createElement('div');
        head.className='track-label';

        const dot=document.createElement('span');
        dot.className='dot';
        dot.style.background=colorOf(type);

        head.append(dot,document.createTextNode(label));

        const lane=document.createElement('div');
        lane.className='lane';

        for(const e of S.project.elements.filter(x=>x.type===type)){
            lane.appendChild(makeBar(e));
        }

        row.append(head,lane);
        UI.tracks.appendChild(row);
    }

    renderAxis();
}

function makeBar(e){
    const b=document.createElement('div');

    b.className='bar'+(S.selected.has(e.id)?' selected':'');
    b.dataset.id=e.id;

    const left=percentTime(e.start);
    const right=percentTime(e.end);

    b.style.left=left+'%';
    b.style.width=Math.max(.4,right-left)+'%';
    b.style.color=e.color;
    b.style.background=alpha(e.color,.2);

    const label=document.createElement('span');
    label.className='bar-label';
    label.textContent=`${e.name} ${fmt(e.start)}～${fmt(e.end)}`;

    const lh=document.createElement('span');
    const rh=document.createElement('span');

    lh.className='bar-handle left';
    rh.className='bar-handle right';

    lh.dataset.edge='left';
    rh.dataset.edge='right';

    b.append(label,lh,rh);

    b.onpointerdown=ev=>timelineDown(ev,e.id);
    b.onclick=ev=>{
        ev.stopPropagation();
        select(e.id,ev.ctrlKey||ev.metaKey);
    };
    b.ondblclick=ev=>{
        ev.stopPropagation();
        openElement(e.id);
    };
    b.oncontextmenu=ev=>{
        ev.preventDefault();
        ev.stopPropagation();
        select(e.id);
        showContext(ev.clientX,ev.clientY,e.id);
    };

    return b;
}

function renderPlayhead(){
    if(!ready()){
        UI.playhead.style.display='none';
        return;
    }

    UI.playhead.style.display='block';
    UI.playhead.style.left=
        `calc(var(--label) + (100% - var(--label)) * ${percentTime(S.time)/100})`;

    UI.playheadLabel.textContent=fmt(S.time);
    UI.readout.textContent=`${fmt(S.time)} / ${fmt(S.duration)}`;
}

function select(id,multi=false){
    if(!el(id)) return;

    if(!multi) S.selected.clear();

    if(multi && S.selected.has(id)) S.selected.delete(id);
    else S.selected.add(id);

    render();
}

function renderSelection(){
    UI.selection.textContent=S.selected.size?`${S.selected.size}個選択`:'';
    UI.connect.disabled=S.selected.size!==2;
    UI.disconnect.disabled===false;
    UI.disconnect.disabled=!S.selected.size;
}

/* ---------- 動画上の注釈 ---------- */

function handles(){
    return ['nw','n','ne','e','se','s','sw','w']
        .map(x=>`<span class="resize r${x}" data-resize="${x}"></span>`)
        .join('');
}

function renderOverlay(){
    UI.overlay.querySelectorAll('.element').forEach(n=>n.remove());

    if(!ready()) return;

    for(const e of S.project.elements){
        if(e.type==='zoom') continue;
        if(S.time<e.start || S.time>=e.end) continue;

        const n=document.createElement('div');
        n.className='element'+(S.selected.has(e.id)?' selected':'');
        n.dataset.id=e.id;

        n.style.left=e.x+'%';
        n.style.top=e.y+'%';
        n.style.width=e.w+'%';
        n.style.height=e.h+'%';
        n.style.color=e.color;

        const body=document.createElement('div');

        if(e.type==='comment'){
            body.className='element-body comment';
            body.textContent=e.text||e.name;
            body.style.fontSize=e.fontSize+'px';
        }else if(e.type==='highlight'){
            body.className='element-body highlight '+e.shape;
        }else{
            body.className='element-body skip';
            body.textContent='スキップ';
        }

        n.appendChild(body);

        if(S.selected.has(e.id)) n.insertAdjacentHTML('beforeend',handles());

        n.onpointerdown=ev=>overlayDown(ev,e.id);
        n.onclick=ev=>{
            ev.stopPropagation();
            select(e.id,ev.ctrlKey||ev.metaKey);
        };
        n.ondblclick=ev=>{
            ev.stopPropagation();
            openElement(e.id);
        };
        n.oncontextmenu=ev=>{
            ev.preventDefault();
            ev.stopPropagation();
            select(e.id);
            showContext(ev.clientX,ev.clientY,e.id);
        };

        UI.overlay.appendChild(n);
    }

    applyVideoZoom();
}

function renderConnections(){
    UI.svg.innerHTML='';

    if(!ready()) return;

    const rect=UI.overlay.getBoundingClientRect();

    for(const c of S.project.elements.filter(e=>e.type==='comment'&&e.target)){
        const h=el(c.target);

        if(!h || h.type!=='highlight') continue;
        if(S.time<c.start || S.time>=c.end) continue;
        if(S.time<h.start || S.time>=h.end) continue;

        const cn=UI.overlay.querySelector(`[data-id="${CSS.escape(c.id)}"]`);
        const hn=UI.overlay.querySelector(`[data-id="${CSS.escape(h.id)}"]`);

        if(!cn||!hn) continue;

        const a=cn.getBoundingClientRect();
        const b=hn.getBoundingClientRect();

        const ax=a.left+a.width/2-rect.left;
        const ay=a.top+a.height/2-rect.top;
        const bx=b.left+b.width/2-rect.left;
        const by=b.top+b.height/2-rect.top;

        const dir=Math.sign(bx-ax)||1;
        const bend=Math.max(30,Math.abs(bx-ax)*.25);

        const path=document.createElementNS('http://www.w3.org/2000/svg','path');
        path.setAttribute('d',
            `M${ax} ${ay} C${ax+dir*bend} ${ay},${bx-dir*bend} ${by},${bx} ${by}`
        );
        path.setAttribute('fill','none');
        path.setAttribute('stroke',
            S.selected.has(c.id)||S.selected.has(h.id)?'#fff':'#facc15'
        );
        path.setAttribute('stroke-width',
            S.selected.has(c.id)||S.selected.has(h.id)?4:2.5
        );
        path.setAttribute('stroke-dasharray','7 5');

        UI.svg.appendChild(path);
    }
}

/* ---------- ズーム ---------- */

function activeZoom(){
    if(!ready()) return null;

    return S.project.elements
        .filter(e=>e.type==='zoom'&&S.time>=e.start&&S.time<e.end)
        .sort((a,b)=>a.start-b.start)
        .at(-1)||null;
}

function applyVideoZoom(){
    const z=activeZoom();

    if(!z){
        UI.video.style.transform='scale(1)';
        UI.video.style.transformOrigin='50% 50%';
        return;
    }

    UI.video.style.transform=`scale(${z.scale})`;
    UI.video.style.transformOrigin=`${z.zoomX}% ${z.zoomY}%`;
}

/* ---------- 動画上の移動・リサイズ ---------- */

function overlayDown(ev,id){
    if(ev.button!==0) return;

    ev.preventDefault();
    ev.stopPropagation();

    const e=el(id);
    if(!e) return;

    const resize=ev.target.closest('[data-resize]')?.dataset.resize||'';

    if(ev.ctrlKey||ev.metaKey) select(id,true);
    else select(id);

    const r=UI.overlay.getBoundingClientRect();

    S.drag={
        kind:'overlay',
        id,
        resize,
        sx:ev.clientX,
        sy:ev.clientY,
        x:e.x,y:e.y,w:e.w,h:e.h,
        rw:r.width,rh:r.height
    };

    window.addEventListener('pointermove',overlayMove);
    window.addEventListener('pointerup',overlayUp,{once:true});
}

function overlayMove(ev){
    const d=S.drag;
    const e=el(d?.id);

    if(!d||!e) return;

    const dx=(ev.clientX-d.sx)/d.rw*100;
    const dy=(ev.clientY-d.sy)/d.rh*100;
    const minW=.5,minH=.5;

    if(!d.resize){
        e.x=clamp(d.x+dx,0,100-d.w);
        e.y=clamp(d.y+dy,0,100-d.h);
    }else{
        let x=d.x,y=d.y,w=d.w,h=d.h;

        /*
         * ここが従来の「右端をクリックすると100%まで伸びる」
         * 問題を避ける核心部分。
         *
         * 右ハンドルは現在幅 + 移動量だけを変更し、
         * 最大値は100%-現在X。
         */
        if(d.resize.includes('e')){
            w=clamp(d.w+dx,minW,100-d.x);
        }

        if(d.resize.includes('w')){
            x=clamp(d.x+dx,0,d.x+d.w-minW);
            w=d.w-(x-d.x);
        }

        if(d.resize.includes('s')){
            h=clamp(d.h+dy,minH,100-d.y);
        }

        if(d.resize.includes('n')){
            y=clamp(d.y+dy,0,d.y+d.h-minH);
            h=d.h-(y-d.y);
        }

        e.x=x;e.y=y;e.w=w;e.h=h;
    }

    markDirty();
    render();
}

function overlayUp(){
    S.drag=null;
    window.removeEventListener('pointermove',overlayMove);
    render();
}

/* ---------- タイムライン移動・リサイズ ---------- */

function timelineDown(ev,id){
    if(ev.button!==0) return;

    ev.preventDefault();
    ev.stopPropagation();

    const e=el(id);
    if(!e) return;

    const handle=ev.target.closest('.bar-handle');

    if(ev.ctrlKey||ev.metaKey) select(id,true);
    else select(id);

    const lane=ev.currentTarget.parentElement;
    const r=lane.getBoundingClientRect();
    const q=range();

    S.drag={
        kind:'timeline',
        id,
        mode:handle?'resize':'move',
        edge:handle?.dataset.edge||'',
        sx:ev.clientX,
        start:e.start,
        end:e.end,
        width:r.width,
        span:q.end-q.start
    };

    window.addEventListener('pointermove',timelineMove);
    window.addEventListener('pointerup',timelineUp,{once:true});
}

function timelineMove(ev){
    const d=S.drag;
    const e=el(d?.id);

    if(!d||!e) return;

    const delta=(ev.clientX-d.sx)/d.width*d.span;

    if(d.mode==='move'){
        const length=d.end-d.start;
        e.start=clamp(d.start+delta,0,S.duration-length);
        e.end=e.start+length;
    }else if(d.edge==='left'){
        e.start=clamp(d.start+delta,0,e.end-.05);
    }else{
        e.end=clamp(d.end+delta,e.start+.05,S.duration);
    }

    markDirty();
    render();
}

function timelineUp(){
    S.drag=null;
    window.removeEventListener('pointermove',timelineMove);
    render();
}

/* ---------- 再生ヘッド ---------- */

UI.playhead.onpointerdown=ev=>{
    if(!ready()) return;

    ev.preventDefault();
    S.playheadDrag=true;
    seek(timeAt(ev.clientX));
};

window.addEventListener('pointermove',ev=>{
    if(S.playheadDrag) seek(timeAt(ev.clientX));
});

window.addEventListener('pointerup',()=>S.playheadDrag=false);

/* ---------- 要素追加 ---------- */

function addElement(type){
    if(!ready()){
        toast('先に動画を読み込んでください',true);
        return;
    }

    const p=S.contextPoint||{x:10,y:10};
    const start=S.time;
    const end=Math.min(S.duration,start+3);

    const e={
        id:uid(),
        type,
        name:
            type==='comment'?'コメント':
            type==='highlight'?'強調枠':
            type==='skip'?'スキップ':'動画ズーム',
        text:type==='comment'?'コメント':'',
        start,end,
        x:clamp(p.x,0,99),
        y:clamp(p.y,0,99),
        w:type==='comment'?30:25,
        h:type==='comment'?15:20,
        color:colorOf(type),
        fontSize:28,
        shape:'square',
        target:'',
        scale:1.5,
        zoomX:50,
        zoomY:50
    };

    S.project.elements.push(e);
    S.selected=new Set([e.id]);
    S.contextPoint=null;
    closeContext();
    markDirty();
    render();
    openElement(e.id);
}

/* ---------- 編集モーダル ---------- */

function openElement(id){
    const e=el(id);
    if(!e) return;

    S.modalId=id;

    UI.modalTitle.textContent={
        comment:'コメント編集',
        highlight:'強調枠編集',
        skip:'スキップ編集',
        zoom:'動画ズーム編集'
    }[e.type]||'要素編集';

    UI.name.value=e.name;
    UI.text.value=e.text;
    UI.shape.value=e.shape;
    UI.start.value=e.start;
    UI.end.value=e.end;
    UI.x.value=e.x;
    UI.y.value=e.y;
    UI.w.value=e.w;
    UI.h.value=e.h;
    UI.scale.value=e.scale;
    UI.zoomX.value=e.zoomX;
    UI.zoomY.value=e.zoomY;
    UI.font.value=e.fontSize;

    const comment=e.type==='comment';
    const highlight=e.type==='highlight';
    const zoom=e.type==='zoom';

    UI.textField.classList.toggle('hidden',!comment);
    UI.shapeField.classList.toggle('hidden',!highlight);
    UI.fontField.classList.toggle('hidden',!comment);
    UI.targetField.classList.toggle('hidden',!comment);

    UI.positionFields.classList.toggle('hidden',zoom);
    UI.sizeFields.classList.toggle('hidden',zoom);
    UI.scaleField.classList.toggle('hidden',!zoom);
    UI.zoomPointField.classList.toggle('hidden',!zoom);

    buildColors(e.color);
    buildTargets(e);

    UI.modal.style.display='flex';
}

function buildColors(active){
    UI.colors.innerHTML='';

    for(const c of S.colors){
        const b=document.createElement('button');
        b.type='button';
        b.className='color';
        b.style.background=c;
        b.dataset.color=c;

        if(c.toLowerCase()===String(active).toLowerCase())
            b.classList.add('active');

        b.onclick=()=>{
            UI.colors.querySelectorAll('.active')
                .forEach(x=>x.classList.remove('active'));
            b.classList.add('active');
        };

        UI.colors.appendChild(b);
    }
}

function buildTargets(comment){
    UI.target.innerHTML='<option value="">接続しない</option>';

    for(const e of S.project.elements.filter(x=>x.type==='highlight')){
        const o=document.createElement('option');
        o.value=e.id;
        o.textContent=e.name;
        o.selected=e.id===comment.target;
        UI.target.appendChild(o);
    }
}

function closeModal(){
    UI.modal.style.display='none';
    S.modalId=null;
}

function saveElement(){
    const e=el(S.modalId);
    if(!e) return;

    const start=Number(UI.start.value);
    const end=Number(UI.end.value);

    if(
        !Number.isFinite(start)||
        !Number.isFinite(end)||
        start<0||
        end<=start||
        end>S.duration
    ){
        toast('開始・終了時間が不正です',true);
        return;
    }

    e.name=UI.name.value.trim()||'要素';
    e.start=start;
    e.end=end;
    e.color=UI.colors.querySelector('.active')?.dataset.color||e.color;

    if(e.type!=='zoom'){
        e.x=clamp(Number(UI.x.value)||0,0,99);
        e.y=clamp(Number(UI.y.value)||0,0,99);
        e.w=clamp(Number(UI.w.value)||1,1,100-e.x);
        e.h=clamp(Number(UI.h.value)||1,1,100-e.y);
    }

    if(e.type==='comment'){
        e.text=UI.text.value;
        e.fontSize=clamp(Number(UI.font.value)||28,8,100);

        const t=el(UI.target.value);
        e.target=t?.type==='highlight'?t.id:'';
    }

    if(e.type==='highlight'){
        e.shape=UI.shape.value;
    }

    if(e.type==='zoom'){
        e.scale=clamp(Number(UI.scale.value)||1,1,5);
        e.zoomX=clamp(Number(UI.zoomX.value)||50,0,100);
        e.zoomY=clamp(Number(UI.zoomY.value)||50,0,100);
    }

    closeModal();
    markDirty();
    render();
}

/* ---------- 削除・接続 ---------- */

function deleteSelected(){
    if(!S.project||!S.selected.size) return;

    const ids=new Set(S.selected);

    S.project.elements=S.project.elements.filter(e=>!ids.has(e.id));

    for(const e of S.project.elements){
        if(e.type==='comment'&&ids.has(e.target)) e.target='';
    }

    S.selected.clear();
    closeModal();
    closeContext();
    markDirty();
    render();
}

function connectSelected(){
    if(S.selected.size!==2){
        toast('コメント1個と強調枠1個を選択してください',true);
        return;
    }

    const items=[...S.selected].map(el);
    const comment=items.find(e=>e?.type==='comment');
    const highlight=items.find(e=>e?.type==='highlight');

    if(!comment||!highlight){
        toast('コメントと強調枠を1個ずつ選択してください',true);
        return;
    }

    comment.target=highlight.id;
    markDirty();
    render();
    toast('接続しました');
}

function disconnectSelected(){
    let changed=false;

    for(const e of S.project?.elements||[]){
        if(e.type==='comment'&&S.selected.has(e.id)&&e.target){
            e.target='';
            changed=true;
        }
    }

    if(changed){
        markDirty();
        render();
        toast('接続を解除しました');
    }
}

/* ---------- コンテキストメニュー ---------- */

function showContext(x,y,id=null){
    S.contextId=id;

    UI.context.style.display='block';
    UI.context.style.left=Math.min(x,innerWidth-225)+'px';
    UI.context.style.top=Math.min(y,innerHeight-245)+'px';
}

function closeContext(){
    UI.context.style.display='none';
    S.contextId=null;
}

UI.context.querySelectorAll('[data-add]').forEach(button=>{
    button.onclick=()=>addElement(button.dataset.add);
});

$('contextEdit').onclick=()=>{
    if(S.contextId) openElement(S.contextId);
    closeContext();
};

$('contextDelete').onclick=()=>{
    if(S.contextId){
        S.selected=new Set([S.contextId]);
        deleteSelected();
    }
    closeContext();
};

UI.stage.oncontextmenu=ev=>{
    ev.preventDefault();

    if(!ready()) return;

    const r=UI.video.getBoundingClientRect();

    if(
        ev.clientX<r.left||
        ev.clientX>r.right||
        ev.clientY<r.top||
        ev.clientY>r.bottom
    ) return;

    S.contextPoint={
        x:(ev.clientX-r.left)/r.width*100,
        y:(ev.clientY-r.top)/r.height*100
    };

    showContext(ev.clientX,ev.clientY);
};

UI.content.oncontextmenu=ev=>{
    ev.preventDefault();

    if(!ready()) return;

    const bar=ev.target.closest('.bar');

    if(bar){
        select(bar.dataset.id);
        showContext(ev.clientX,ev.clientY,bar.dataset.id);
        return;
    }

    if(ev.target.closest('.lane')||ev.target.closest('.axis-track')){
        seek(timeAt(ev.clientX));
        showContext(ev.clientX,ev.clientY);
    }
};

UI.content.onpointerdown=ev=>{
    if(ev.target.closest('.bar')||ev.target.closest('.playhead')) return;

    if(ev.target.closest('.lane')||ev.target.closest('.axis-track'))
        seek(timeAt(ev.clientX));
};

UI.overlay.onclick=ev=>{
    if(ev.target===UI.overlay){
        S.selected.clear();
        render();
    }
};

document.addEventListener('click',ev=>{
    if(!ev.target.closest('.context')) closeContext();
});

/* ---------- 保存 ---------- */

async function post(action,data={}){
    const fd=new FormData();
    fd.append('action',action);

    for(const [k,v] of Object.entries(data))
        fd.append(k,typeof v==='string'?v:JSON.stringify(v));

    const r=await fetch(location.href,{method:'POST',body:fd});
    const json=await r.json();

    if(!r.ok||json.ok===false)
        throw Error(json.error||'サーバーエラー');

    return json;
}

async function saveProject(){
    if(!S.project) return;

    try{
        const result=await post('save',{
            project:JSON.stringify(S.project)
        });

        markClean();
        toast(`保存しました：${result.name}`);
    }catch(e){
        toast('保存に失敗しました：'+e.message,true);
    }
}

async function showStorage(){
    UI.storageList.innerHTML='<div style="padding:20px;color:#94a3b8">読み込み中…</div>';
    UI.storage.style.display='flex';

    try{
        const result=await post('list');

        if(!result.items.length){
            UI.storageList.innerHTML=
                '<div style="padding:20px;color:#94a3b8">保存データはありません</div>';
            return;
        }

        UI.storageList.innerHTML='';

        for(const item of result.items){
            const row=document.createElement('div');
            row.className='storage-row';

            const info=document.createElement('div');
            info.className='storage-info';

            const name=document.createElement('div');
            name.className='storage-name';
            name.textContent=item.name;

            const meta=document.createElement('div');
            meta.className='storage-meta';
            meta.textContent=
                `${item.videoName||'動画未設定'} / ${item.elements}要素 / ${item.savedAt||''}`;

            info.append(name,meta);

            const load=document.createElement('button');
            load.className='btn small';
            load.textContent='読込';

            load.onclick=async()=>{
                try{
                    const r=await post('load',{name:item.name});
                    loadProjectData(r.project);
                    UI.storage.style.display='none';
                }catch(e){
                    toast('読み込みに失敗しました：'+e.message,true);
                }
            };

            const del=document.createElement('button');
            del.className='btn small danger';
            del.textContent='削除';

            del.onclick=async()=>{
                if(!confirm(`「${item.name}」を削除しますか？`)) return;

                try{
                    await post('delete',{name:item.name});
                    showStorage();
                }catch(e){
                    toast('削除に失敗しました：'+e.message,true);
                }
            };

            row.append(info,load,del);
            UI.storageList.appendChild(row);
        }
    }catch(e){
        UI.storageList.innerHTML=
            `<div style="padding:20px;color:#f87171">${e.message}</div>`;
    }
}

function loadProjectData(src){
    try{
        const p=normalize(src);

        S.project=p;
        S.duration=Number(UI.video.duration)>0?UI.video.duration:p.duration;
        S.project.duration=S.duration;
        S.time=0;
        S.selected.clear();

        UI.empty.style.display=S.duration>0?'none':'flex';
        setButtons(S.duration>0);
        render();
        markClean();

        if(!S.duration)
            toast('プロジェクトを読み込みました。動画を読み込んでください');
    }catch(e){
        toast('プロジェクトを読み込めません：'+e.message,true);
    }
}

function exportProject(){
    if(!S.project) return;

    const blob=new Blob(
        [JSON.stringify(S.project,null,2)],
        {type:'application/json'}
    );

    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');

    a.href=url;
    a.download=(S.project.name||'video-project')+'.json';

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(()=>URL.revokeObjectURL(url),1000);
}

/* ---------- UIイベント ---------- */

UI.open.onclick=()=>UI.file.click();
UI.file.onchange=()=>loadVideo(UI.file.files?.[0]);
UI.newBtn.onclick=reset;
UI.save.onclick=saveProject;
UI.load.onclick=showStorage;
UI.export.onclick=exportProject;

UI.import.onchange=async ev=>{
    const file=ev.target.files?.[0];
    if(!file) return;

    try{
        loadProjectData(JSON.parse(await file.text()));
        toast('JSONを読み込みました');
    }catch(e){
        toast('JSONを読み込めません：'+e.message,true);
    }

    ev.target.value='';
};

UI.play.onclick=togglePlay;
UI.stop.onclick=stop;
UI.connect.onclick=connectSelected;
UI.disconnect.onclick=disconnectSelected;

UI.timelineZoom.oninput=()=>{
    UI.timelineZoomValue.textContent=
        Number(UI.timelineZoom.value).toFixed(1)+'×';
    render();
};

$('modalClose').onclick=closeModal;
$('modalCancel').onclick=closeModal;
$('modalSave').onclick=saveElement;
$('modalDelete').onclick=deleteSelected;

$('storageClose').onclick=()=>{
    UI.storage.style.display='none';
};

/* ---------- キーボード ---------- */

document.addEventListener('keydown',ev=>{
    const tag=document.activeElement?.tagName;
    const input=['INPUT','TEXTAREA','SELECT'].includes(tag);

    if(ev.key==='Escape'){
        closeContext();
        closeModal();
        UI.storage.style.display='none';
    }

    if((ev.key==='Delete'||ev.key==='Backspace')&&!input){
        ev.preventDefault();
        deleteSelected();
    }

    if(ev.key===' '&&!input){
        ev.preventDefault();
        togglePlay();
    }

    if((ev.ctrlKey||ev.metaKey)&&ev.key.toLowerCase()==='a'&&!input){
        ev.preventDefault();

        if(S.project){
            S.selected=new Set(S.project.elements.map(e=>e.id));
            render();
        }
    }
});

/* ---------- 描画 ---------- */

function render(){
    renderTimeline();
    renderOverlay();
    renderConnections();
    renderPlayhead();
    renderSelection();
}

window.addEventListener('resize',render);
UI.scroll.addEventListener('scroll',renderConnections);

UI.timelineZoomValue.textContent='1.0×';
render();
</script>
</body>
</html>

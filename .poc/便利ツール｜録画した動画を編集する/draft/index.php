<?php
declare(strict_types=1);

/*
 * 動画編集・注釈ツール
 * Apache + PHP / 単一 index.php
 *
 * PHPの役割:
 *   - この画面を配信
 *   - JSONプロジェクトの簡易インポート/エクスポート用エンドポイント
 *
 * 動画本体・プロジェクトの通常保存:
 *   - ブラウザ IndexedDB
 *
 * 注意:
 *   MP4そのものへの焼き込みにはサーバー側ffmpeg等が必要です。
 *   この版では編集データを保持したまま、ブラウザ上での編集・再生・保存を実装します。
 */

const APP_VERSION = '3.0.0';

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
        $json = $_POST['project'] ?? '';
        if ($json === '') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'project is required'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="video-editor-project.json"');
        echo $json;
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
    --bg:#111827;
    --panel:#182231;
    --panel2:#202c3c;
    --line:#334155;
    --text:#e5e7eb;
    --muted:#94a3b8;
    --accent:#2563eb;
    --accent2:#60a5fa;
    --danger:#ef4444;
    --green:#22c55e;
    --yellow:#eab308;
    --timeline-label:120px;
    --track-h:54px;
}
*{box-sizing:border-box}
html,body{margin:0;height:100%;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;background:var(--bg);color:var(--text)}
button,input,select,textarea{font:inherit}
button{cursor:pointer}
button:disabled{opacity:.45;cursor:not-allowed}
.app{height:100vh;display:flex;flex-direction:column}
.topbar{height:54px;flex:none;display:flex;align-items:center;gap:8px;padding:7px 10px;border-bottom:1px solid var(--line);background:#0d1521}
.brand{font-weight:700;margin-right:10px;white-space:nowrap}
.btn{border:1px solid #40516a;background:#253246;color:#e5e7eb;border-radius:6px;padding:7px 11px}
.btn:hover:not(:disabled){background:#304158}
.btn.primary{background:#2563eb;border-color:#3b82f6}
.btn.primary:hover:not(:disabled){background:#1d4ed8}
.btn.danger{background:#7f1d1d;border-color:#991b1b}
.btn.small{padding:5px 8px;font-size:12px}
.status{margin-left:auto;color:var(--muted);font-size:12px;white-space:nowrap}
.main{flex:1;min-height:0;display:flex;flex-direction:column}
.video-area{flex:1;min-height:260px;display:flex;align-items:center;justify-content:center;padding:12px;background:#080d15;overflow:hidden}
.video-wrap{position:relative;max-width:900px;max-height:100%;width:min(900px,100%);height:100%;display:flex;align-items:center;justify-content:center}
#video{display:block;max-width:100%;max-height:100%;background:#000;border-radius:4px}
.video-overlay{position:absolute;inset:0;pointer-events:none}
.overlay-element{position:absolute;pointer-events:auto;user-select:none;touch-action:none;min-width:30px;min-height:20px}
.overlay-element.selected{outline:2px solid #60a5fa;outline-offset:2px}
.overlay-element.multi-selected{outline:2px solid #fbbf24;outline-offset:2px}
.overlay-text{display:flex;align-items:center;justify-content:center;width:100%;height:100%;padding:4px;white-space:pre-wrap;overflow:hidden}
.overlay-box{width:100%;height:100%;border:3px solid currentColor;background:rgba(255,255,255,.04)}
.overlay-skip{width:100%;height:100%;border:2px dashed #f97316;background:rgba(249,115,22,.12);display:flex;align-items:center;justify-content:center;color:#fdba74;font-size:12px}
.resize-handle{position:absolute;width:10px;height:10px;background:#fff;border:1px solid #111827;border-radius:2px;z-index:4}
.resize-handle.br{right:-6px;bottom:-6px;cursor:nwse-resize}
.resize-handle.bl{left:-6px;bottom:-6px;cursor:nesw-resize}
.resize-handle.tr{right:-6px;top:-6px;cursor:nesw-resize}
.resize-handle.tl{left:-6px;top:-6px;cursor:nwse-resize}
.empty-video{position:absolute;color:#64748b;text-align:center;line-height:1.8;pointer-events:none}
.empty-video strong{display:block;color:#94a3b8;font-size:18px}
.timeline-panel{flex:none;height:310px;border-top:1px solid var(--line);background:#101923;display:flex;flex-direction:column;min-height:0}
.timeline-toolbar{height:42px;display:flex;align-items:center;gap:8px;padding:5px 8px;border-bottom:1px solid var(--line)}
.timeline-toolbar .time-readout{font-variant-numeric:tabular-nums;color:#dbeafe;min-width:110px}
.zoom-control{display:flex;align-items:center;gap:5px;margin-left:auto;color:var(--muted);font-size:12px}
.zoom-control input{width:120px}
.timeline-scroll{flex:1;min-height:0;overflow:auto;position:relative}
.timeline-content{position:relative;min-width:700px}
.axis-row{height:34px;display:flex;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20;background:#131d29}
.axis-label{width:var(--timeline-label);flex:none;position:sticky;left:0;z-index:30;background:#131d29;border-right:1px solid var(--line);display:flex;align-items:center;padding-left:8px;color:#94a3b8;font-size:12px}
.axis-track{position:relative;height:100%;flex:1}
.tick{position:absolute;top:0;height:100%;border-left:1px solid #334155;color:#64748b;font-size:10px;padding:5px 0 0 3px;pointer-events:none}
.track-row{height:var(--track-h);display:flex;border-bottom:1px solid #263445;position:relative}
.track-label{width:var(--timeline-label);flex:none;position:sticky;left:0;z-index:15;background:#111b27;border-right:1px solid var(--line);display:flex;align-items:center;gap:5px;padding:0 8px;color:#cbd5e1;font-size:12px;overflow:hidden}
.track-label .type-dot{width:8px;height:8px;border-radius:50%;flex:none}
.track-lane{position:relative;flex:1;min-width:0}
.track-grid{position:absolute;inset:0;background-image:linear-gradient(to right,rgba(148,163,184,.09) 1px,transparent 1px);pointer-events:none}
.element-bar{position:absolute;top:9px;height:36px;border-radius:5px;border:1px solid currentColor;display:flex;align-items:center;overflow:visible;min-width:12px;cursor:grab;z-index:5;user-select:none;touch-action:none}
.element-bar:active{cursor:grabbing}
.element-bar.selected{box-shadow:0 0 0 2px #fbbf24}
.element-bar.multi-selected{box-shadow:0 0 0 2px #60a5fa}
.element-bar .bar-label{padding:0 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;pointer-events:none}
.element-bar .handle{position:absolute;top:0;width:9px;height:100%;z-index:8}
.element-bar .handle.left{left:-4px;cursor:ew-resize}
.element-bar .handle.right{right:-4px;cursor:ew-resize}
.element-bar .handle:hover{background:rgba(255,255,255,.2)}
.playhead{position:absolute;top:0;bottom:0;width:2px;background:#ef4444;z-index:50;pointer-events:none;box-shadow:0 0 4px rgba(239,68,68,.6)}
.playhead::before{content:"";position:absolute;top:0;left:-5px;width:12px;height:12px;background:#ef4444;clip-path:polygon(0 0,100% 0,50% 100%)}
.playhead-label{position:absolute;top:12px;left:5px;background:#ef4444;color:#fff;border-radius:3px;padding:2px 4px;font-size:10px;white-space:nowrap}
.selection-hint{font-size:11px;color:#64748b;margin-left:4px}
.context-menu{position:fixed;display:none;z-index:1000;background:#172235;border:1px solid #475569;border-radius:6px;box-shadow:0 12px 30px rgba(0,0,0,.4);padding:5px;min-width:190px}
.context-menu button{display:block;width:100%;border:0;background:transparent;color:#e5e7eb;text-align:left;padding:8px;border-radius:4px}
.context-menu button:hover{background:#293a52}
.context-menu .danger-item{color:#fca5a5}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1100;display:none;align-items:center;justify-content:center;padding:20px}
.modal{width:min(520px,100%);background:#182231;border:1px solid #475569;border-radius:8px;box-shadow:0 20px 60px rgba(0,0,0,.5);overflow:hidden}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 15px;border-bottom:1px solid var(--line)}
.modal-body{padding:15px;display:grid;gap:12px;max-height:75vh;overflow:auto}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 15px;border-top:1px solid var(--line)}
.field{display:grid;gap:5px}
.field label{font-size:12px;color:#94a3b8}
.field input,.field select,.field textarea{width:100%;background:#0f1722;color:#e5e7eb;border:1px solid #40516a;border-radius:5px;padding:7px}
.field textarea{min-height:80px;resize:vertical}
.color-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:6px}
.color-choice{height:30px;border-radius:4px;border:2px solid transparent}
.color-choice.active{border-color:#fff;box-shadow:0 0 0 1px #60a5fa}
.connection-svg{position:absolute;inset:0;width:100%;height:100%;z-index:40;pointer-events:none;overflow:visible}
.connection-path{fill:none;stroke-width:2;pointer-events:stroke;cursor:pointer}
.connection-path.selected{stroke-width:4}
.connection-endpoint{fill:#fff;stroke-width:2}
.toast{position:fixed;right:15px;bottom:15px;z-index:2000;background:#1e293b;color:#e5e7eb;border:1px solid #475569;border-radius:6px;padding:10px 14px;box-shadow:0 10px 30px rgba(0,0,0,.35);display:none;max-width:400px}
.file-input{display:none}
.import-label{display:inline-flex;align-items:center}
.save-indicator{font-size:11px;color:#64748b}
.hidden{display:none!important}
@media(max-width:800px){
    .brand{display:none}
    .timeline-panel{height:280px}
    .video-area{min-height:220px}
    :root{--timeline-label:95px}
}
</style>
</head>
<body>
<div class="app">
    <header class="topbar">
        <div class="brand">動画編集・注釈</div>
        <button class="btn primary" id="openVideoBtn">動画を読み込む</button>
        <input id="videoFile" class="file-input" type="file" accept="video/*">

        <button class="btn" id="newProjectBtn">新規</button>
        <button class="btn" id="saveProjectBtn" disabled>保存</button>
        <button class="btn" id="loadProjectBtn">保存データ</button>

        <button class="btn" id="exportProjectBtn">プロジェクト書き出し</button>
        <label class="btn import-label">
            プロジェクト読込
            <input id="importProjectInput" class="file-input" type="file" accept=".json,application/json">
        </label>

        <span class="status" id="statusText">動画を読み込んでください</span>
    </header>

    <main class="main">
        <section class="video-area" id="videoArea">
            <div class="video-wrap" id="videoWrap">
                <video id="video" preload="metadata" playsinline></video>
                <div class="video-overlay" id="videoOverlay"></div>
                <div class="empty-video" id="emptyVideo">
                    <strong>動画を読み込んでください</strong>
                    動画の読み込みが完了すると編集できます<br>
                    動画上で右クリックすると要素を追加できます
                </div>
            </div>
        </section>

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
    </main>
</div>

<div class="context-menu" id="contextMenu">
    <button id="ctxAddText">テキストを追加</button>
    <button id="ctxAddBox">強調枠を追加</button>
    <button id="ctxAddSkip">スキップを追加</button>
    <button id="ctxEdit">選択要素を編集</button>
    <button id="ctxConnect">選択した2要素を接続</button>
    <button id="ctxDisconnect">選択した接続を解除</button>
    <button id="ctxDelete" class="danger-item">削除</button>
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
                <input id="elementName" type="text">
            </div>

            <div class="field" id="textField">
                <label>テキスト</label>
                <textarea id="elementText"></textarea>
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
                <input id="elementX" type="number" min="0" max="100" step=".1">
            </div>

            <div class="field">
                <label>縦位置（%）</label>
                <input id="elementY" type="number" min="0" max="100" step=".1">
            </div>

            <div class="field">
                <label>幅（%）</label>
                <input id="elementW" type="number" min="1" max="100" step=".1">
            </div>

            <div class="field">
                <label>高さ（%）</label>
                <input id="elementH" type="number" min="1" max="100" step=".1">
            </div>

            <div class="field">
                <label>色</label>
                <div class="color-grid" id="colorGrid"></div>
            </div>

            <div class="field" id="fontSizeField">
                <label>文字サイズ（px）</label>
                <input id="elementFontSize" type="number" min="8" max="100" step="1">
            </div>

            <div class="field" id="fontWeightField">
                <label>文字書式</label>
                <select id="elementFontWeight">
                    <option value="400">標準</option>
                    <option value="500">やや太い</option>
                    <option value="700">太字</option>
                    <option value="900">極太</option>
                </select>
            </div>

            <div class="field">
                <label>接続線の初期色にも使用する色</label>
                <div style="font-size:12px;color:#64748b">要素の枠線色と接続線色を同じにします。</div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" id="elementModalCancel">キャンセル</button>
            <button class="btn primary" id="elementModalSave">保存</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="storageModal">
    <div class="modal">
        <div class="modal-head">
            <strong>保存データ</strong>
            <button class="btn small" id="storageClose">閉じる</button>
        </div>
        <div class="modal-body">
            <div id="storageList"></div>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="projectNameModal">
    <div class="modal">
        <div class="modal-head">
            <strong>プロジェクト名</strong>
        </div>
        <div class="modal-body">
            <div class="field">
                <label>名前</label>
                <input id="projectNameInput" type="text" maxlength="100" placeholder="例：商品説明動画">
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" id="projectNameCancel">キャンセル</button>
            <button class="btn primary" id="projectNameOk">作成</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
(() => {
'use strict';

const APP_VERSION = '3.0.0';

const COLORS = [
    '#ef4444','#f97316','#eab308','#22c55e','#14b8a6','#06b6d4',
    '#3b82f6','#6366f1','#8b5cf6','#ec4899','#f8fafc','#94a3b8'
];

const DB_NAME = 'VideoAnnotationEditorDB';
const DB_VERSION = 1;
const PROJECT_STORE = 'projects';

const state = {
    project: null,
    videoBlob: null,
    videoUrl: '',
    duration: 0,
    editorReady: false,
    currentTime: 0,
    selectedIds: new Set(),
    selectedConnectionId: null,
    contextTargetId: null,
    contextTargetConnectionId: null,
    contextX: 0,
    contextY: 0,
    modalElementId: null,
    editingColor: COLORS[6],
    dragging: null,
    saveTimer: null
};

const $ = id => document.getElementById(id);

const els = {
    video: $('video'),
    videoWrap: $('videoWrap'),
    videoArea: $('videoArea'),
    videoOverlay: $('videoOverlay'),
    emptyVideo: $('emptyVideo'),
    videoFile: $('videoFile'),
    openVideoBtn: $('openVideoBtn'),
    newProjectBtn: $('newProjectBtn'),
    saveProjectBtn: $('saveProjectBtn'),
    loadProjectBtn: $('loadProjectBtn'),
    exportProjectBtn: $('exportProjectBtn'),
    importProjectInput: $('importProjectInput'),
    statusText: $('statusText'),
    playBtn: $('playBtn'),
    stopBtn: $('stopBtn'),
    timeReadout: $('timeReadout'),
    selectionHint: $('selectionHint'),
    zoomRange: $('zoomRange'),
    zoomValue: $('zoomValue'),
    timelineScroll: $('timelineScroll'),
    timelineContent: $('timelineContent'),
    axisTrack: $('axisTrack'),
    tracksContainer: $('tracksContainer'),
    connectionSvg: $('connectionSvg'),
    playhead: $('playhead'),
    playheadLabel: $('playheadLabel'),
    contextMenu: $('contextMenu'),
    ctxAddText: $('ctxAddText'),
    ctxAddBox: $('ctxAddBox'),
    ctxAddSkip: $('ctxAddSkip'),
    ctxEdit: $('ctxEdit'),
    ctxConnect: $('ctxConnect'),
    ctxDisconnect: $('ctxDisconnect'),
    ctxDelete: $('ctxDelete'),
    elementModal: $('elementModal'),
    elementModalTitle: $('elementModalTitle'),
    elementModalClose: $('elementModalClose'),
    elementModalCancel: $('elementModalCancel'),
    elementModalSave: $('elementModalSave'),
    elementName: $('elementName'),
    elementText: $('elementText'),
    elementStart: $('elementStart'),
    elementEnd: $('elementEnd'),
    elementX: $('elementX'),
    elementY: $('elementY'),
    elementW: $('elementW'),
    elementH: $('elementH'),
    elementFontSize: $('elementFontSize'),
    elementFontWeight: $('elementFontWeight'),
    colorGrid: $('colorGrid'),
    fontSizeField: $('fontSizeField'),
    fontWeightField: $('fontWeightField'),
    textField: $('textField'),
    storageModal: $('storageModal'),
    storageClose: $('storageClose'),
    storageList: $('storageList'),
    projectNameModal: $('projectNameModal'),
    projectNameInput: $('projectNameInput'),
    projectNameCancel: $('projectNameCancel'),
    projectNameOk: $('projectNameOk'),
    toast: $('toast')
};

function uid(prefix = 'id') {
    return prefix + '-' + Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2, 9);
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

function formatTime(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    const minutes = Math.floor(value / 60);
    const secs = Math.floor(value % 60);
    const millis = Math.floor((value - Math.floor(value)) * 1000);
    return String(minutes).padStart(2,'0') + ':' +
        String(secs).padStart(2,'0') + '.' +
        String(millis).padStart(3,'0');
}

function showToast(message, error = false) {
    els.toast.textContent = message;
    els.toast.style.display = 'block';
    els.toast.style.borderColor = error ? '#991b1b' : '#475569';
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => {
        els.toast.style.display = 'none';
    }, 2800);
}

function setStatus(message) {
    els.statusText.textContent = message;
}

function emptyProject(name = '新規プロジェクト') {
    return {
        appVersion: APP_VERSION,
        id: uid('project'),
        name,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        videoName: '',
        duration: 0,
        elements: [],
        connections: []
    };
}

function createElement(type, start, end, overrides = {}) {
    const defaults = {
        id: uid('element'),
        type,
        name: type === 'text' ? 'テキスト' :
            type === 'box' ? '強調枠' : 'スキップ',
        text: type === 'text' ? 'テキスト' : '',
        start,
        end,
        x: 10,
        y: 10,
        w: type === 'text' ? 28 : 32,
        h: type === 'text' ? 12 : 30,
        color: type === 'skip' ? '#f97316' : '#3b82f6',
        fontSize: 24,
        fontWeight: 700
    };

    return Object.assign(defaults, overrides);
}

function normalizeProject(project) {
    const p = emptyProject();
    if (!project || typeof project !== 'object') return p;

    p.id = String(project.id || uid('project'));
    p.name = String(project.name || 'プロジェクト');
    p.createdAt = project.createdAt || p.createdAt;
    p.updatedAt = project.updatedAt || p.updatedAt;
    p.videoName = String(project.videoName || '');
    p.duration = Number(project.duration) || 0;

    p.elements = Array.isArray(project.elements)
        ? project.elements.map(raw => normalizeElement(raw, p.duration))
        : [];

    const ids = new Set(p.elements.map(e => e.id));

    p.connections = Array.isArray(project.connections)
        ? project.connections
            .filter(c => c && ids.has(c.from) && ids.has(c.to) && c.from !== c.to)
            .map(c => ({
                id: String(c.id || uid('connection')),
                from: String(c.from),
                to: String(c.to),
                color: COLORS.includes(c.color) ? c.color : '#64748b',
                width: clamp(Number(c.width) || 2, 1, 8),
                dash: c.dash === 'dashed' ? 'dashed' : 'solid'
            }))
        : [];

    return p;
}

function normalizeElement(raw, duration) {
    const type = ['text','box','skip'].includes(raw?.type) ? raw.type : 'text';

    let start = Number(raw?.start);
    let end = Number(raw?.end);

    if (!Number.isFinite(start)) start = 0;
    if (!Number.isFinite(end)) end = start + 3;

    const max = duration > 0 ? duration : Math.max(end, 3);

    start = clamp(start, 0, max);
    end = clamp(end, start + .05, max);

    if (end <= start) end = Math.min(max, start + .05);

    return {
        id: String(raw?.id || uid('element')),
        type,
        name: String(raw?.name || (type === 'text' ? 'テキスト' : type === 'box' ? '強調枠' : 'スキップ')),
        text: String(raw?.text || ''),
        start,
        end,
        x: clamp(Number(raw?.x) || 0, 0, 100),
        y: clamp(Number(raw?.y) || 0, 0, 100),
        w: clamp(Number(raw?.w) || 20, 1, 100),
        h: clamp(Number(raw?.h) || 15, 1, 100),
        color: COLORS.includes(raw?.color) ? raw.color : '#3b82f6',
        fontSize: clamp(Number(raw?.fontSize) || 24, 8, 100),
        fontWeight: String(raw?.fontWeight || '700')
    };
}

function activeProject() {
    return state.project && state.editorReady ? state.project : null;
}

function getElement(id) {
    return state.project?.elements.find(e => e.id === id) || null;
}

function selectedElements() {
    return [...state.selectedIds].map(getElement).filter(Boolean);
}

function canEdit() {
    return Boolean(state.editorReady && state.project && state.duration > 0);
}

function markDirty() {
    if (!state.project) return;
    state.project.updatedAt = new Date().toISOString();
    els.saveProjectBtn.disabled = !canEdit();
    els.saveProjectBtn.textContent = '保存*';

    clearTimeout(state.saveTimer);
    state.saveTimer = setTimeout(() => {
        if (canEdit()) saveCurrentProject(false);
    }, 1000);
}

function markClean() {
    els.saveProjectBtn.textContent = '保存';
}

function visible(element) {
    return state.currentTime >= element.start - .0005 &&
           state.currentTime <= element.end + .0005;
}

function typeLabel(type) {
    return type === 'text' ? 'テキスト' :
        type === 'box' ? '強調枠' : 'スキップ';
}

function typeColor(type) {
    return type === 'text' ? '#60a5fa' :
        type === 'box' ? '#a78bfa' : '#f97316';
}

function renderAll() {
    renderOverlay();
    renderTimeline();
    renderConnections();
    updatePlayhead();
    updateSelectionHint();
}

function renderOverlay() {
    els.videoOverlay.innerHTML = '';

    if (!canEdit()) return;

    for (const element of state.project.elements) {
        if (!visible(element)) continue;

        const node = document.createElement('div');
        node.className = 'overlay-element';

        if (state.selectedIds.has(element.id)) {
            node.classList.add(
                state.selectedIds.size > 1 ? 'multi-selected' : 'selected'
            );
        }

        node.dataset.id = element.id;

        node.style.left = element.x + '%';
        node.style.top = element.y + '%';
        node.style.width = element.w + '%';
        node.style.height = element.h + '%';
        node.style.color = element.color;

        if (element.type === 'text') {
            const content = document.createElement('div');
            content.className = 'overlay-text';
            content.textContent = element.text || element.name;
            content.style.fontSize = element.fontSize + 'px';
            content.style.fontWeight = element.fontWeight;
            content.style.color = element.color;
            node.appendChild(content);
        } else if (element.type === 'box') {
            const box = document.createElement('div');
            box.className = 'overlay-box';
            box.style.color = element.color;
            node.appendChild(box);
        } else {
            const skip = document.createElement('div');
            skip.className = 'overlay-skip';
            skip.textContent = 'SKIP';
            node.appendChild(skip);
        }

        for (const side of ['tl','tr','bl','br']) {
            const handle = document.createElement('div');
            handle.className = 'resize-handle ' + side;
            handle.dataset.handle = side;
            node.appendChild(handle);
        }

        node.addEventListener('pointerdown', beginOverlayPointer);
        node.addEventListener('click', overlayClick);
        node.addEventListener('contextmenu', overlayContextMenu);

        els.videoOverlay.appendChild(node);
    }

    els.emptyVideo.style.display = 'none';
}

function overlayClick(event) {
    event.stopPropagation();

    const id = event.currentTarget.dataset.id;
    selectElement(id, event.shiftKey);
}

function overlayContextMenu(event) {
    event.preventDefault();
    event.stopPropagation();

    const id = event.currentTarget.dataset.id;

    if (!state.selectedIds.has(id)) {
        selectElement(id, event.shiftKey);
    }

    openContextMenu(event.clientX, event.clientY, id, null);
}

function selectElement(id, additive = false) {
    if (!getElement(id)) return;

    if (!additive) {
        state.selectedIds.clear();
        state.selectedConnectionId = null;
    }

    if (state.selectedIds.has(id) && additive) {
        state.selectedIds.delete(id);
    } else {
        state.selectedIds.add(id);
    }

    const element = getElement(id);

    if (element) {
        seek(element.start);
    }

    renderAll();
}

function beginOverlayPointer(event) {
    if (!canEdit()) return;

    event.preventDefault();
    event.stopPropagation();

    const node = event.currentTarget;
    const id = node.dataset.id;
    const element = getElement(id);

    if (!element) return;

    const handle = event.target.closest('.resize-handle');
    const additive = event.shiftKey;

    if (!state.selectedIds.has(id)) {
        selectElement(id, additive);
    } else if (!additive && state.selectedIds.size > 1) {
        state.selectedIds.clear();
        state.selectedIds.add(id);
    }

    const rect = els.videoOverlay.getBoundingClientRect();

    state.dragging = {
        mode: handle ? 'resize' : 'move',
        handle: handle?.dataset.handle || '',
        id,
        startClientX: event.clientX,
        startClientY: event.clientY,
        rect,
        original: {
            x: element.x,
            y: element.y,
            w: element.w,
            h: element.h
        }
    };

    try {
        node.setPointerCapture(event.pointerId);
    } catch (_) {}

    window.addEventListener('pointermove', moveOverlayPointer);
    window.addEventListener('pointerup', endOverlayPointer, {once:true});
}

function moveOverlayPointer(event) {
    if (!state.dragging) return;

    const d = state.dragging;
    const element = getElement(d.id);
    if (!element) return;

    const dx = ((event.clientX - d.startClientX) / d.rect.width) * 100;
    const dy = ((event.clientY - d.startClientY) / d.rect.height) * 100;

    if (d.mode === 'move') {
        element.x = clamp(d.original.x + dx, 0, 100 - element.w);
        element.y = clamp(d.original.y + dy, 0, 100 - element.h);
    } else {
        resizeElementByHandle(element, d.original, d.handle, dx, dy);
    }

    markDirty();
    renderAll();
}

function resizeElementByHandle(element, original, handle, dx, dy) {
    const minW = 2;
    const minH = 2;

    let x = original.x;
    let y = original.y;
    let w = original.w;
    let h = original.h;

    if (handle.includes('l')) {
        const newX = clamp(original.x + dx, 0, original.x + original.w - minW);
        x = newX;
        w = original.w + (original.x - newX);
    }

    if (handle.includes('r')) {
        w = clamp(original.w + dx, minW, 100 - original.x);
    }

    if (handle.includes('t')) {
        const newY = clamp(original.y + dy, 0, original.y + original.h - minH);
        y = newY;
        h = original.h + (original.y - newY);
    }

    if (handle.includes('b')) {
        h = clamp(original.h + dy, minH, 100 - original.y);
    }

    element.x = clamp(x, 0, 100 - minW);
    element.y = clamp(y, 0, 100 - minH);
    element.w = clamp(w, minW, 100 - element.x);
    element.h = clamp(h, minH, 100 - element.y);
}

function endOverlayPointer() {
    state.dragging = null;
    window.removeEventListener('pointermove', moveOverlayPointer);
}

function renderTimeline() {
    els.tracksContainer.innerHTML = '';

    if (!state.project || state.duration <= 0) {
        els.timelineContent.style.width = '100%';
        return;
    }

    const width = timelineWidth();
    els.timelineContent.style.width = width + 'px';

    renderAxis(width);

    const types = [
        {type:'text', label:'テキスト'},
        {type:'box', label:'強調枠'},
        {type:'skip', label:'スキップ'}
    ];

    for (const group of types) {
        const row = document.createElement('div');
        row.className = 'track-row';

        const label = document.createElement('div');
        label.className = 'track-label';

        const dot = document.createElement('span');
        dot.className = 'type-dot';
        dot.style.background = typeColor(group.type);

        label.appendChild(dot);
        label.appendChild(document.createTextNode(group.label));
        row.appendChild(label);

        const lane = document.createElement('div');
        lane.className = 'track-lane';

        const grid = document.createElement('div');
        grid.className = 'track-grid';
        lane.appendChild(grid);

        for (const element of state.project.elements.filter(e => e.type === group.type)) {
            lane.appendChild(createElementBar(element, width));
        }

        row.appendChild(lane);
        els.tracksContainer.appendChild(row);
    }
}

function timelineWidth() {
    const base = Math.max(700, els.timelineScroll.clientWidth - 1);
    const scale = Number(els.zoomRange.value) || 1;

    /*
     * duration全体を必ず1本の同じX軸に収める。
     * zoomを上げた場合だけ全体が横に広がる。
     */
    return Math.max(base, base * scale);
}

function renderAxis(width) {
    els.axisTrack.innerHTML = '';

    const duration = state.duration;
    const secondsPerTick = chooseTickStep(duration, width);

    for (let t = 0; t <= duration + .0001; t += secondsPerTick) {
        const tick = document.createElement('div');
        tick.className = 'tick';
        tick.style.left = ((t / duration) * 100) + '%';
        tick.textContent = formatShortTime(t);
        els.axisTrack.appendChild(tick);
    }
}

function chooseTickStep(duration, width) {
    const scale = Number(els.zoomRange.value) || 1;

    const targetPixels = 90 / scale;
    const approx = duration / Math.max(1, width / targetPixels);

    const candidates = [
        .1,.25,.5,1,2,5,10,15,30,60,120,300,600
    ];

    return candidates.find(x => x >= approx) || 600;
}

function formatShortTime(seconds) {
    if (seconds < 60) return seconds.toFixed(seconds % 1 ? 1 : 0) + 's';

    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);

    return String(m).padStart(2,'0') + ':' +
        String(s).padStart(2,'0');
}

function createElementBar(element, width) {
    const bar = document.createElement('div');
    bar.className = 'element-bar';

    if (state.selectedIds.has(element.id)) {
        bar.classList.add(
            state.selectedIds.size > 1 ? 'multi-selected' : 'selected'
        );
    }

    bar.dataset.id = element.id;

    const usableWidth = Math.max(1, width - parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--timeline-label')));

    bar.style.left = ((element.start / state.duration) * 100) + '%';
    bar.style.width = (Math.max(.001, (element.end - element.start) / state.duration) * 100) + '%';
    bar.style.color = element.color;
    bar.style.background = hexToRgba(element.color, .2);

    const label = document.createElement('span');
    label.className = 'bar-label';

    label.textContent =
        element.name +
        '  ' +
        formatTime(element.start) +
        ' ～ ' +
        formatTime(element.end);

    bar.appendChild(label);

    const leftHandle = document.createElement('span');
    leftHandle.className = 'handle left';
    leftHandle.dataset.edge = 'left';

    const rightHandle = document.createElement('span');
    rightHandle.className = 'handle right';
    rightHandle.dataset.edge = 'right';

    bar.appendChild(leftHandle);
    bar.appendChild(rightHandle);

    bar.addEventListener('pointerdown', beginBarPointer);
    bar.addEventListener('click', timelineElementClick);
    bar.addEventListener('dblclick', () => openElementModal(element.id));
    bar.addEventListener('contextmenu', timelineElementContextMenu);

    bar.title =
        element.name + '\n' +
        '開始: ' + formatTime(element.start) + '\n' +
        '終了: ' + formatTime(element.end);

    return bar;
}

function timelineElementClick(event) {
    event.stopPropagation();

    const id = event.currentTarget.dataset.id;
    const element = getElement(id);

    if (!element) return;

    selectElement(id, event.shiftKey);
}

function timelineElementContextMenu(event) {
    event.preventDefault();
    event.stopPropagation();

    const id = event.currentTarget.dataset.id;

    if (!state.selectedIds.has(id)) {
        selectElement(id, event.shiftKey);
    }

    openContextMenu(event.clientX, event.clientY, id, null);
}

function beginBarPointer(event) {
    if (!canEdit()) return;

    event.preventDefault();
    event.stopPropagation();

    const bar = event.currentTarget;
    const id = bar.dataset.id;
    const element = getElement(id);

    if (!element) return;

    if (!state.selectedIds.has(id)) {
        selectElement(id, event.shiftKey);
    }

    const lane = bar.parentElement;
    const rect = lane.getBoundingClientRect();

    const edge = event.target.closest('.handle')?.dataset.edge || '';

    state.dragging = {
        mode: edge ? 'timeline-resize' : 'timeline-move',
        edge,
        id,
        rect,
        original: {
            start: element.start,
            end: element.end
        },
        startClientX: event.clientX
    };

    try {
        bar.setPointerCapture(event.pointerId);
    } catch (_) {}

    window.addEventListener('pointermove', moveBarPointer);
    window.addEventListener('pointerup', endBarPointer, {once:true});
}

function moveBarPointer(event) {
    if (!state.dragging || !state.project) return;

    const d = state.dragging;
    const element = getElement(d.id);

    if (!element) return;

    const dx = event.clientX - d.startClientX;
    const deltaTime = dx / d.rect.width * state.duration;

    const minDuration = .05;

    if (d.mode === 'timeline-move') {
        const duration = d.original.end - d.original.start;
        let start = d.original.start + deltaTime;

        start = clamp(start, 0, Math.max(0, state.duration - duration));

        element.start = start;
        element.end = start + duration;
    } else if (d.edge === 'left') {
        const newStart = clamp(
            d.original.start + deltaTime,
            0,
            d.original.end - minDuration
        );

        element.start = newStart;
    } else {
        const newEnd = clamp(
            d.original.end + deltaTime,
            d.original.start + minDuration,
            state.duration
        );

        element.end = newEnd;
    }

    markDirty();
    renderAll();
}

function endBarPointer() {
    state.dragging = null;
    window.removeEventListener('pointermove', moveBarPointer);
}

function renderConnections() {
    const width = els.timelineContent.clientWidth;
    const height = els.timelineContent.scrollHeight;

    els.connectionSvg.setAttribute('width', width);
    els.connectionSvg.setAttribute('height', height);
    els.connectionSvg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    els.connectionSvg.innerHTML = '';

    if (!state.project) return;

    for (const connection of state.project.connections) {
        const from = getElement(connection.from);
        const to = getElement(connection.to);

        if (!from || !to) continue;

        const a = connectionAnchor(from, 'right');
        const b = connectionAnchor(to, 'left');

        const dx = Math.max(30, Math.abs(b.x - a.x) * .45);

        const path = document.createElementNS('http://www.w3.org/2000/svg','path');

        path.setAttribute(
            'd',
            `M ${a.x} ${a.y} C ${a.x + dx} ${a.y}, ${b.x - dx} ${b.y}, ${b.x} ${b.y}`
        );

        path.setAttribute('class','connection-path' +
            (state.selectedConnectionId === connection.id ? ' selected' : ''));

        path.setAttribute('stroke', connection.color || from.color || '#64748b');
        path.setAttribute('stroke-width', connection.width || 2);

        if (connection.dash === 'dashed') {
            path.setAttribute('stroke-dasharray','6 4');
        }

        path.addEventListener('contextmenu', event => {
            event.preventDefault();
            event.stopPropagation();

            state.selectedConnectionId = connection.id;
            state.selectedIds.clear();
            renderAll();

            openContextMenu(
                event.clientX,
                event.clientY,
                null,
                connection.id
            );
        });

        els.connectionSvg.appendChild(path);

        const end = document.createElementNS('http://www.w3.org/2000/svg','circle');
        end.setAttribute('cx', b.x);
        end.setAttribute('cy', b.y);
        end.setAttribute('r', '4');
        end.setAttribute('class','connection-endpoint');
        end.setAttribute('stroke', connection.color || '#64748b');

        els.connectionSvg.appendChild(end);
    }
}

function connectionAnchor(element, side) {
    const labelWidth = 120;
    const width = els.timelineContent.clientWidth;
    const contentWidth = Math.max(1, width - labelWidth);

    const x = labelWidth + (element[side === 'right' ? 'end' : 'start'] / state.duration) * contentWidth;

    const row = [...els.tracksContainer.querySelectorAll('.track-row')].find(row => {
        const type = row.querySelector('.track-label')?.textContent?.trim();
        return type === typeLabel(element.type);
    });

    let y = 60;

    if (row) {
        const rect = row.getBoundingClientRect();
        const timelineRect = els.timelineContent.getBoundingClientRect();
        y = rect.top - timelineRect.top + rect.height / 2;
    }

    return {x,y};
}

function updatePlayhead() {
    if (!state.project || state.duration <= 0) {
        els.playhead.style.display = 'none';
        return;
    }

    els.playhead.style.display = 'block';

    const labelWidth = 120;
    const contentWidth = Math.max(
        1,
        els.timelineContent.clientWidth - labelWidth
    );

    const x = labelWidth + (state.currentTime / state.duration) * contentWidth;

    els.playhead.style.left = x + 'px';
    els.playheadLabel.textContent = formatTime(state.currentTime);

    els.timeReadout.textContent =
        formatTime(state.currentTime) +
        ' / ' +
        formatTime(state.duration);
}

function updateSelectionHint() {
    const count = state.selectedIds.size;

    if (count === 0) {
        els.selectionHint.textContent = '';
    } else if (count === 1) {
        els.selectionHint.textContent = '1要素選択中';
    } else {
        els.selectionHint.textContent =
            count + '要素選択中：Shiftで追加選択 → 右クリックで接続';
    }
}

function seek(time) {
    if (!state.editorReady) return;

    const value = clamp(Number(time) || 0, 0, state.duration);

    state.currentTime = value;

    if (Math.abs(els.video.currentTime - value) > .001) {
        try {
            els.video.currentTime = value;
        } catch (_) {}
    }

    handleSkipAtCurrentTime();
    renderAll();
}

function handleSkipAtCurrentTime() {
    if (!state.project || !state.editorReady) return;

    const skip = state.project.elements.find(e =>
        e.type === 'skip' &&
        state.currentTime >= e.start &&
        state.currentTime < e.end - .02
    );

    if (skip && !state.skipLock) {
        state.skipLock = true;
        seekWithoutSkip(skip.end);
        setTimeout(() => {
            state.skipLock = false;
        }, 100);
    }
}

function seekWithoutSkip(time) {
    const value = clamp(time, 0, state.duration);
    state.currentTime = value;

    try {
        els.video.currentTime = value;
    } catch (_) {}

    renderAll();
}

function togglePlay() {
    if (!canEdit()) return;

    if (els.video.paused) {
        const promise = els.video.play();

        if (promise && typeof promise.catch === 'function') {
            promise.catch(error => {
                if (error.name !== 'AbortError') {
                    showToast('再生を開始できませんでした', true);
                    console.error(error);
                }
            });
        }
    } else {
        els.video.pause();
    }
}

function stopVideo() {
    if (!state.editorReady) return;

    els.video.pause();

    try {
        els.video.currentTime = 0;
    } catch (_) {}

    state.currentTime = 0;
    renderAll();
}

function openContextMenu(x, y, targetId, connectionId) {
    state.contextTargetId = targetId;
    state.contextTargetConnectionId = connectionId;

    els.ctxEdit.style.display = targetId ? 'block' : 'none';
    els.ctxDelete.style.display = targetId || connectionId ? 'block' : 'none';
    els.ctxConnect.style.display =
        state.selectedIds.size === 2 ? 'block' : 'none';
    els.ctxDisconnect.style.display =
        connectionId ? 'block' : 'none';

    els.contextMenu.style.left = Math.min(
        x,
        window.innerWidth - 210
    ) + 'px';

    els.contextMenu.style.top = Math.min(
        y,
        window.innerHeight - 250
    ) + 'px';

    els.contextMenu.style.display = 'block';
}

function closeContextMenu() {
    els.contextMenu.style.display = 'none';
    state.contextTargetId = null;
    state.contextTargetConnectionId = null;
}

function addElement(type, xPercent = 20, yPercent = 20) {
    if (!canEdit()) {
        showToast('先に動画を読み込んでください', true);
        return;
    }

    const start = clamp(state.currentTime, 0, Math.max(0, state.duration - .05));
    const end = Math.min(state.duration, start + Math.min(5, state.duration - start));

    if (end <= start) {
        showToast('動画の終端では要素を追加できません', true);
        return;
    }

    const element = createElement(type, start, end, {
        x: clamp(xPercent, 0, 80),
        y: clamp(yPercent, 0, 80)
    });

    state.project.elements.push(element);
    state.selectedIds.clear();
    state.selectedIds.add(element.id);

    markDirty();
    renderAll();
    openElementModal(element.id);
}

function deleteSelected() {
    if (!state.project) return;

    const ids = new Set(state.selectedIds);

    if (state.contextTargetId) ids.add(state.contextTargetId);

    if (ids.size) {
        state.project.elements =
            state.project.elements.filter(e => !ids.has(e.id));

        state.project.connections =
            state.project.connections.filter(c =>
                !ids.has(c.from) && !ids.has(c.to)
            );

        state.selectedIds.clear();
        markDirty();
        renderAll();
        showToast('要素を削除しました');
        return;
    }

    if (state.contextTargetConnectionId) {
        state.project.connections =
            state.project.connections.filter(
                c => c.id !== state.contextTargetConnectionId
            );

        state.selectedConnectionId = null;
        markDirty();
        renderAll();
        showToast('接続を削除しました');
    }
}

function connectSelected() {
    if (!state.project || state.selectedIds.size !== 2) {
        showToast('Shiftを押しながら2つの要素を選択してください', true);
        return;
    }

    const [from, to] = [...state.selectedIds];

    if (from === to) return;

    const exists = state.project.connections.some(c =>
        (c.from === from && c.to === to) ||
        (c.from === to && c.to === from)
    );

    if (exists) {
        showToast('この2要素はすでに接続されています', true);
        return;
    }

    const fromElement = getElement(from);
    const toElement = getElement(to);

    if (!fromElement || !toElement) return;

    state.project.connections.push({
        id: uid('connection'),
        from,
        to,
        color: fromElement.color,
        width: 2,
        dash: 'solid'
    });

    markDirty();
    renderAll();
    showToast('2要素を接続しました');
}

function disconnectSelected() {
    if (!state.project) return;

    if (state.selectedConnectionId) {
        state.project.connections =
            state.project.connections.filter(
                c => c.id !== state.selectedConnectionId
            );

        state.selectedConnectionId = null;
        markDirty();
        renderAll();
        showToast('接続を解除しました');
        return;
    }

    if (state.selectedIds.size !== 2) {
        showToast('2要素を選択するか、接続線を右クリックしてください', true);
        return;
    }

    const [a,b] = [...state.selectedIds];

    state.project.connections =
        state.project.connections.filter(c =>
            !((c.from === a && c.to === b) ||
              (c.from === b && c.to === a))
        );

    markDirty();
    renderAll();
}

function openElementModal(id) {
    if (!canEdit()) return;

    const element = getElement(id);
    if (!element) return;

    state.modalElementId = id;
    state.editingColor = element.color;

    els.elementModalTitle.textContent =
        typeLabel(element.type) + 'を編集';

    els.elementName.value = element.name;
    els.elementText.value = element.text;
    els.elementStart.value = element.start.toFixed(3);
    els.elementEnd.value = element.end.toFixed(3);
    els.elementX.value = element.x.toFixed(1);
    els.elementY.value = element.y.toFixed(1);
    els.elementW.value = element.w.toFixed(1);
    els.elementH.value = element.h.toFixed(1);
    els.elementFontSize.value = element.fontSize;
    els.elementFontWeight.value = element.fontWeight;

    const textVisible = element.type === 'text';

    els.textField.style.display = textVisible ? 'grid' : 'none';
    els.fontSizeField.style.display = textVisible ? 'grid' : 'none';
    els.fontWeightField.style.display = textVisible ? 'grid' : 'none';

    renderColorChoices();

    els.elementModal.style.display = 'flex';
}

function closeElementModal() {
    els.elementModal.style.display = 'none';
    state.modalElementId = null;
}

function renderColorChoices() {
    els.colorGrid.innerHTML = '';

    for (const color of COLORS) {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'color-choice' +
            (color === state.editingColor ? ' active' : '');

        button.style.background = color;

        button.title = color;

        button.addEventListener('click', () => {
            state.editingColor = color;
            renderColorChoices();
        });

        els.colorGrid.appendChild(button);
    }
}

function saveElementModal() {
    const element = getElement(state.modalElementId);

    if (!element) {
        closeElementModal();
        return;
    }

    let start = Number(els.elementStart.value);
    let end = Number(els.elementEnd.value);

    if (!Number.isFinite(start) || !Number.isFinite(end)) {
        showToast('開始・終了時間を正しく入力してください', true);
        return;
    }

    start = clamp(start, 0, state.duration);
    end = clamp(end, 0, state.duration);

    if (end <= start) {
        showToast('終了時間は開始時間より後にしてください', true);
        return;
    }

    element.name = els.elementName.value.trim() || typeLabel(element.type);
    element.text = els.elementText.value;
    element.start = start;
    element.end = end;
    element.x = clamp(Number(els.elementX.value) || 0, 0, 100);
    element.y = clamp(Number(els.elementY.value) || 0, 0, 100);
    element.w = clamp(Number(els.elementW.value) || 1, 1, 100 - element.x);
    element.h = clamp(Number(els.elementH.value) || 1, 1, 100 - element.y);
    element.color = state.editingColor;
    element.fontSize = clamp(Number(els.elementFontSize.value) || 24, 8, 100);
    element.fontWeight = els.elementFontWeight.value;

    markDirty();
    closeElementModal();
    renderAll();
}

function handleVideoFile(file) {
    if (!file || !file.type.startsWith('video/')) {
        showToast('動画ファイルを選択してください', true);
        return;
    }

    /*
     * 既存Blob URLを破棄する前にvideo.srcを空にする。
     * IndexedDBにはBlob本体を保存し、blob: URLは保存しない。
     */
    if (state.videoUrl) {
        try {
            els.video.pause();
            els.video.removeAttribute('src');
            els.video.load();
            URL.revokeObjectURL(state.videoUrl);
        } catch (_) {}

        state.videoUrl = '';
    }

    state.editorReady = false;
    state.videoBlob = file;
    state.duration = 0;

    disableEditor();

    const url = URL.createObjectURL(file);
    state.videoUrl = url;

    els.video.src = url;
    els.video.load();

    setStatus('動画を読み込んでいます…');

    els.video.addEventListener('loadedmetadata', onVideoMetadata, {once:true});

    els.video.addEventListener('error', () => {
        state.editorReady = false;
        setStatus('動画を読み込めませんでした');
        showToast('動画を読み込めませんでした', true);
    }, {once:true});
}

function onVideoMetadata() {
    const duration = Number(els.video.duration);

    if (!Number.isFinite(duration) || duration <= 0) {
        setStatus('動画時間を取得できません');
        showToast('動画時間を取得できませんでした', true);
        return;
    }

    state.duration = duration;

    if (!state.project) {
        state.project = emptyProject();
    }

    state.project.duration = duration;
    state.project.videoName = state.videoBlob?.name || state.project.videoName || 'video';

    /*
     * 読込完了後にだけ編集可能状態へ遷移。
     */
    state.editorReady = true;

    enableEditor();

    state.currentTime = 0;
    els.video.currentTime = 0;

    els.emptyVideo.style.display = 'none';

    setStatus(
        '編集可能：' +
        state.project.videoName +
        ' / ' +
        formatTime(duration)
    );

    renderAll();

    /*
     * 読み込み直後の動画本体をIndexedDBへ保存。
     * 保存に失敗しても編集自体は継続できる。
     */
    saveCurrentProject(false).catch(error => {
        console.warn('自動保存に失敗:', error);
    });
}

function enableEditor() {
    els.playBtn.disabled = false;
    els.stopBtn.disabled = false;
    els.saveProjectBtn.disabled = false;
}

function disableEditor() {
    els.playBtn.disabled = true;
    els.stopBtn.disabled = true;
    els.saveProjectBtn.disabled = true;
    els.emptyVideo.style.display = 'block';
    els.videoOverlay.innerHTML = '';
    els.tracksContainer.innerHTML = '';
    els.connectionSvg.innerHTML = '';
    els.playhead.style.display = 'none';
}

function resetToNewProject(name) {
    if (state.videoUrl) {
        try {
            els.video.pause();
            els.video.removeAttribute('src');
            els.video.load();
            URL.revokeObjectURL(state.videoUrl);
        } catch (_) {}
    }

    state.videoUrl = '';
    state.videoBlob = null;
    state.project = emptyProject(name);
    state.duration = 0;
    state.currentTime = 0;
    state.editorReady = false;
    state.selectedIds.clear();
    state.selectedConnectionId = null;

    els.videoFile.value = '';
    disableEditor();

    els.timeReadout.textContent = '00:00.000 / 00:00.000';
    els.statusText.textContent = '動画を読み込んでください';

    renderAll();
}

function newProject() {
    openProjectNameModal();
}

function openProjectNameModal() {
    els.projectNameInput.value = '';
    els.projectNameModal.style.display = 'flex';
    setTimeout(() => els.projectNameInput.focus(), 30);
}

function closeProjectNameModal() {
    els.projectNameModal.style.display = 'none';
}

async function createNamedProject() {
    const name = els.projectNameInput.value.trim() || '新規プロジェクト';

    closeProjectNameModal();
    resetToNewProject(name);

    showToast('新規プロジェクトを作成しました');
}

function openStorageModal() {
    listStoredProjects();
    els.storageModal.style.display = 'flex';
}

function closeStorageModal() {
    els.storageModal.style.display = 'none';
}

function openProjectFromStorage(id) {
    loadProject(id)
        .then(() => closeStorageModal())
        .catch(error => {
            console.error(error);
            showToast('保存データを読み込めませんでした', true);
        });
}

/* ---------------- IndexedDB ---------------- */

function openDB() {
    return new Promise((resolve,reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(PROJECT_STORE)) {
                const store = db.createObjectStore(PROJECT_STORE, {keyPath:'id'});
                store.createIndex('updatedAt','updatedAt');
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function idbPut(record) {
    return openDB().then(db => new Promise((resolve,reject) => {
        const tx = db.transaction(PROJECT_STORE,'readwrite');
        const store = tx.objectStore(PROJECT_STORE);

        const request = store.put(record);

        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);

        tx.onabort = () => reject(tx.error);
    }));
}

function idbGet(id) {
    return openDB().then(db => new Promise((resolve,reject) => {
        const tx = db.transaction(PROJECT_STORE,'readonly');
        const request = tx.objectStore(PROJECT_STORE).get(id);

        request.onsuccess = () => resolve(request.result || null);
        request.onerror = () => reject(request.error);
    }));
}

function idbGetAll() {
    return openDB().then(db => new Promise((resolve,reject) => {
        const tx = db.transaction(PROJECT_STORE,'readonly');
        const request = tx.objectStore(PROJECT_STORE).getAll();

        request.onsuccess = () => resolve(request.result || []);
        request.onerror = () => reject(request.error);
    }));
}

function idbDelete(id) {
    return openDB().then(db => new Promise((resolve,reject) => {
        const tx = db.transaction(PROJECT_STORE,'readwrite');
        const request = tx.objectStore(PROJECT_STORE).delete(id);

        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
    }));
}

async function saveCurrentProject(showMessage = true) {
    if (!state.project || !state.editorReady || !state.videoBlob) {
        if (showMessage) {
            showToast('動画を読み込んでから保存してください', true);
        }
        return false;
    }

    const project = JSON.parse(JSON.stringify(state.project));

    project.appVersion = APP_VERSION;
    project.updatedAt = new Date().toISOString();
    project.duration = state.duration;
    project.videoName = state.videoBlob.name || project.videoName;

    const record = {
        id: project.id,
        project,
        videoBlob: state.videoBlob,
        updatedAt: project.updatedAt
    };

    await idbPut(record);

    state.project.updatedAt = project.updatedAt;
    markClean();

    if (showMessage) showToast('保存しました');

    return true;
}

async function listStoredProjects() {
    try {
        const records = await idbGetAll();

        records.sort((a,b) =>
            String(b.updatedAt).localeCompare(String(a.updatedAt))
        );

        els.storageList.innerHTML = '';

        if (!records.length) {
            const empty = document.createElement('div');
            empty.style.color = '#94a3b8';
            empty.textContent = '保存されたプロジェクトはありません。';
            els.storageList.appendChild(empty);
            return;
        }

        for (const record of records) {
            const project = record.project;

            const item = document.createElement('div');
            item.style.display = 'flex';
            item.style.alignItems = 'center';
            item.style.gap = '8px';
            item.style.padding = '10px 0';
            item.style.borderBottom = '1px solid #334155';

            const info = document.createElement('div');
            info.style.flex = '1';

            const title = document.createElement('div');
            title.textContent = project.name;
            title.style.fontWeight = '600';

            const detail = document.createElement('div');
            detail.style.fontSize = '11px';
            detail.style.color = '#94a3b8';
            detail.textContent =
                (project.videoName || '動画なし') +
                ' / ' +
                formatTime(project.duration || 0);

            info.appendChild(title);
            info.appendChild(detail);

            const load = document.createElement('button');
            load.className = 'btn small';
            load.textContent = '開く';
            load.addEventListener('click', () =>
                openProjectFromStorage(record.id)
            );

            const remove = document.createElement('button');
            remove.className = 'btn small danger';
            remove.textContent = '削除';
            remove.addEventListener('click', async () => {
                if (!confirm('この保存データを削除しますか？')) return;

                await idbDelete(record.id);
                listStoredProjects();
            });

            item.appendChild(info);
            item.appendChild(load);
            item.appendChild(remove);

            els.storageList.appendChild(item);
        }
    } catch (error) {
        console.error(error);
        showToast('保存データを取得できませんでした', true);
    }
}

async function loadProject(id) {
    const record = await idbGet(id);

    if (!record || !record.project || !record.videoBlob) {
        throw new Error('保存データまたは動画本体がありません');
    }

    const project = normalizeProject(record.project);

    /*
     * Blob URLは保存データから復元しない。
     * IndexedDBのBlob本体から毎回新しく生成する。
     */
    if (state.videoUrl) {
        try {
            els.video.pause();
            els.video.removeAttribute('src');
            els.video.load();
            URL.revokeObjectURL(state.videoUrl);
        } catch (_) {}
    }

    state.videoBlob = record.videoBlob;
    state.project = project;
    state.duration = Number(project.duration) || 0;
    state.editorReady = false;
    state.selectedIds.clear();
    state.selectedConnectionId = null;

    const url = URL.createObjectURL(state.videoBlob);
    state.videoUrl = url;

    els.video.src = url;
    els.video.load();

    setStatus('保存動画を読み込んでいます…');

    await new Promise((resolve,reject) => {
        const onMeta = () => {
            cleanup();
            resolve();
        };

        const onError = () => {
            cleanup();
            reject(new Error('保存動画を読み込めませんでした'));
        };

        const cleanup = () => {
            els.video.removeEventListener('loadedmetadata', onMeta);
            els.video.removeEventListener('error', onError);
        };

        els.video.addEventListener('loadedmetadata', onMeta);
        els.video.addEventListener('error', onError);
    });

    const actualDuration = Number(els.video.duration);

    if (!Number.isFinite(actualDuration) || actualDuration <= 0) {
        throw new Error('動画時間を取得できません');
    }

    state.duration = actualDuration;
    state.project.duration = actualDuration;

    /*
     * 保存データ側の時間は動画の実時間に合わせて補正。
     */
    for (const element of state.project.elements) {
        element.start = clamp(element.start, 0, actualDuration);
        element.end = clamp(
            element.end,
            element.start + .05,
            actualDuration
        );
    }

    state.editorReady = true;

    enableEditor();

    els.emptyVideo.style.display = 'none';
    state.currentTime = 0;
    els.video.currentTime = 0;

    setStatus(
        '編集可能：' +
        state.project.name +
        ' / ' +
        state.project.videoName
    );

    renderAll();
    showToast('プロジェクトを読み込みました');
}

/* ---------------- JSON import/export ---------------- */

function exportProject() {
    if (!state.project || !state.editorReady) {
        showToast('動画を読み込んでから書き出してください', true);
        return;
    }

    const data = {
        format: 'video-annotation-project',
        version: APP_VERSION,
        exportedAt: new Date().toISOString(),
        project: state.project
    };

    const blob = new Blob(
        [JSON.stringify(data,null,2)],
        {type:'application/json'}
    );

    downloadBlob(
        blob,
        sanitizeFilename(state.project.name || 'project') + '.json'
    );

    showToast('プロジェクト情報を書き出しました');
}

async function importProjectFile(file) {
    try {
        const text = await file.text();
        const data = JSON.parse(text);

        const project = data.project || data;

        if (!project || !Array.isArray(project.elements)) {
            throw new Error('プロジェクト形式が不正です');
        }

        /*
         * JSONには動画Blobを含めない。
         * 既存の動画を使ってプロジェクト情報だけ読み込む。
         */
        if (!state.editorReady || !state.videoBlob) {
            showToast(
                '先に対象動画を読み込んでください。動画を読み込んだ後にプロジェクトを読込できます。',
                true
            );
            return;
        }

        const imported = normalizeProject(project);

        if (
            imported.duration > 0 &&
            Math.abs(imported.duration - state.duration) > .5
        ) {
            const ok = confirm(
                'プロジェクトと現在の動画の長さが異なります。\n' +
                '現在の動画時間に合わせて読み込みますか？'
            );

            if (!ok) return;
        }

        imported.duration = state.duration;
        imported.videoName = state.videoBlob.name || imported.videoName;

        state.project = imported;
        state.selectedIds.clear();
        state.selectedConnectionId = null;

        markDirty();
        renderAll();

        showToast('プロジェクト情報を読み込みました');
    } catch (error) {
        console.error(error);
        showToast('プロジェクトを読み込めませんでした', true);
    }
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');

    a.href = url;
    a.download = filename;

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function sanitizeFilename(name) {
    return String(name)
        .replace(/[\\/:*?"<>|]/g,'_')
        .trim() || 'project';
}

function hexToRgba(hex, alpha) {
    const clean = String(hex).replace('#','');

    if (clean.length !== 6) return `rgba(59,130,246,${alpha})`;

    const r = parseInt(clean.slice(0,2),16);
    const g = parseInt(clean.slice(2,4),16);
    const b = parseInt(clean.slice(4,6),16);

    return `rgba(${r},${g},${b},${alpha})`;
}

/* ---------------- video events ---------------- */

els.video.addEventListener('timeupdate', () => {
    if (!state.editorReady) return;

    state.currentTime = Number(els.video.currentTime) || 0;

    handleSkipAtCurrentTime();

    /*
     * 再生中も動画上の要素とタイムラインのインデックスを同期。
     */
    renderOverlay();
    updatePlayhead();
});

els.video.addEventListener('play', () => {
    els.playBtn.textContent = 'Ⅱ';
});

els.video.addEventListener('pause', () => {
    els.playBtn.textContent = '▶';
});

els.video.addEventListener('ended', () => {
    els.playBtn.textContent = '▶';
    state.currentTime = state.duration;
    renderAll();
});

els.video.addEventListener('loadeddata', () => {
    if (state.editorReady) {
        setStatus(
            '編集可能：' +
            (state.project?.name || '') +
            ' / ' +
            formatTime(state.duration)
        );
    }
});

/* ---------------- timeline interaction ---------------- */

els.timelineContent.addEventListener('pointerdown', event => {
    if (!canEdit()) return;

    if (
        event.target.closest('.element-bar') ||
        event.target.closest('.track-label') ||
        event.target.closest('.connection-path')
    ) return;

    const rect = els.timelineContent.getBoundingClientRect();
    const labelWidth = 120;

    const x = event.clientX - rect.left - labelWidth;
    const contentWidth = rect.width - labelWidth;

    if (contentWidth <= 0) return;

    const time = clamp(
        x / contentWidth * state.duration,
        0,
        state.duration
    );

    seek(time);
});

els.zoomRange.addEventListener('input', () => {
    els.zoomValue.textContent =
        Number(els.zoomRange.value).toFixed(1) + '×';

    renderAll();
});

els.playBtn.addEventListener('click', togglePlay);
els.stopBtn.addEventListener('click', stopVideo);

els.videoArea.addEventListener('contextmenu', event => {
    event.preventDefault();

    if (!canEdit()) {
        showToast('動画の読み込み完了後に編集できます', true);
        return;
    }

    /*
     * 動画上の右クリック位置を動画領域内の%座標へ変換。
     */
    const rect = els.videoOverlay.getBoundingClientRect();

    const x = clamp(
        ((event.clientX - rect.left) / rect.width) * 100,
        0, 80
    );

    const y = clamp(
        ((event.clientY - rect.top) / rect.height) * 100,
        0, 80
    );

    state.contextX = x;
    state.contextY = y;
    state.contextTargetId = null;
    state.contextTargetConnectionId = null;

    els.ctxEdit.style.display = 'none';
    els.ctxDelete.style.display = 'none';
    els.ctxDisconnect.style.display = 'none';
    els.ctxConnect.style.display =
        state.selectedIds.size === 2 ? 'block' : 'none';

    openContextMenu(event.clientX,event.clientY,null,null);
});

/* ---------------- context menu ---------------- */

els.ctxAddText.addEventListener('click', () => {
    closeContextMenu();
    addElement('text',state.contextX,state.contextY);
});

els.ctxAddBox.addEventListener('click', () => {
    closeContextMenu();
    addElement('box',state.contextX,state.contextY);
});

els.ctxAddSkip.addEventListener('click', () => {
    closeContextMenu();
    addElement('skip',state.contextX,state.contextY);
});

els.ctxEdit.addEventListener('click', () => {
    const id = state.contextTargetId;
    closeContextMenu();

    if (id) openElementModal(id);
});

els.ctxConnect.addEventListener('click', () => {
    closeContextMenu();
    connectSelected();
});

els.ctxDisconnect.addEventListener('click', () => {
    const connectionId = state.contextTargetConnectionId;

    closeContextMenu();

    if (connectionId) {
        state.selectedConnectionId = connectionId;
        disconnectSelected();
    } else {
        disconnectSelected();
    }
});

els.ctxDelete.addEventListener('click', () => {
    closeContextMenu();
    deleteSelected();
});

document.addEventListener('click', event => {
    if (!els.contextMenu.contains(event.target)) {
        closeContextMenu();
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        closeContextMenu();
        closeElementModal();
        closeProjectNameModal();
        closeStorageModal();
    }

    if (
        event.key === 'Delete' &&
        !event.target.matches('input,textarea,select')
    ) {
        deleteSelected();
    }

    if (
        event.key === ' ' &&
        !event.target.matches('input,textarea,select')
    ) {
        event.preventDefault();

        if (canEdit()) togglePlay();
    }
});

/* ---------------- modal ---------------- */

els.elementModalClose.addEventListener('click', closeElementModal);
els.elementModalCancel.addEventListener('click', closeElementModal);
els.elementModalSave.addEventListener('click', saveElementModal);

els.elementModal.addEventListener('click', event => {
    if (event.target === els.elementModal) {
        closeElementModal();
    }
});

els.storageClose.addEventListener('click', closeStorageModal);

els.storageModal.addEventListener('click', event => {
    if (event.target === els.storageModal) {
        closeStorageModal();
    }
});

els.projectNameCancel.addEventListener('click', closeProjectNameModal);
els.projectNameOk.addEventListener('click', createNamedProject);

els.projectNameInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') {
        event.preventDefault();
        createNamedProject();
    }
});

/* ---------------- top buttons ---------------- */

els.openVideoBtn.addEventListener('click', () => {
    els.videoFile.click();
});

els.videoFile.addEventListener('change', event => {
    const file = event.target.files?.[0];

    if (!file) return;

    /*
     * 新しい動画を読み込んだら、新規プロジェクトとして扱う。
     * 既存編集データと別動画が混ざることを防ぐ。
     */
    state.project = emptyProject(
        file.name.replace(/\.[^.]+$/,'') || '動画プロジェクト'
    );

    state.project.videoName = file.name;

    state.selectedIds.clear();
    state.selectedConnectionId = null;

    handleVideoFile(file);
});

els.newProjectBtn.addEventListener('click', newProject);

els.saveProjectBtn.addEventListener('click', () => {
    saveCurrentProject(true).catch(error => {
        console.error(error);
        showToast('保存に失敗しました', true);
    });
});

els.loadProjectBtn.addEventListener('click', openStorageModal);

els.exportProjectBtn.addEventListener('click', exportProject);

els.importProjectInput.addEventListener('change', event => {
    const file = event.target.files?.[0];

    if (file) {
        importProjectFile(file);
    }

    event.target.value = '';
});

/* ---------------- connection style editing ---------------- */

function openConnectionStyleModal(connection) {
    /*
     * 接続線専用の簡易モーダルを動的生成。
     * 要素編集モーダルと混ぜない。
     */
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.style.display = 'flex';

    const modal = document.createElement('div');
    modal.className = 'modal';

    const head = document.createElement('div');
    head.className = 'modal-head';
    head.innerHTML = '<strong>接続線の書式</strong>';

    const body = document.createElement('div');
    body.className = 'modal-body';

    const colorField = document.createElement('div');
    colorField.className = 'field';

    const colorLabel = document.createElement('label');
    colorLabel.textContent = '色';

    const colorGrid = document.createElement('div');
    colorGrid.className = 'color-grid';

    let color = connection.color || '#64748b';

    for (const c of COLORS) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'color-choice' +
            (c === color ? ' active' : '');
        button.style.background = c;

        button.addEventListener('click', () => {
            color = c;

            colorGrid.querySelectorAll('.color-choice').forEach(x =>
                x.classList.remove('active')
            );

            button.classList.add('active');
        });

        colorGrid.appendChild(button);
    }

    colorField.appendChild(colorLabel);
    colorField.appendChild(colorGrid);

    const widthField = document.createElement('div');
    widthField.className = 'field';

    const widthLabel = document.createElement('label');
    widthLabel.textContent = '太さ';

    const widthInput = document.createElement('input');
    widthInput.type = 'number';
    widthInput.min = '1';
    widthInput.max = '8';
    widthInput.step = '1';
    widthInput.value = connection.width || 2;

    widthField.appendChild(widthLabel);
    widthField.appendChild(widthInput);

    const dashField = document.createElement('div');
    dashField.className = 'field';

    const dashLabel = document.createElement('label');
    dashLabel.textContent = '線種';

    const dashSelect = document.createElement('select');
    dashSelect.innerHTML =
        '<option value="solid">実線</option>' +
        '<option value="dashed">破線</option>';

    dashSelect.value = connection.dash || 'solid';

    dashField.appendChild(dashLabel);
    dashField.appendChild(dashSelect);

    body.appendChild(colorField);
    body.appendChild(widthField);
    body.appendChild(dashField);

    const foot = document.createElement('div');
    foot.className = 'modal-foot';

    const cancel = document.createElement('button');
    cancel.className = 'btn';
    cancel.textContent = 'キャンセル';

    const save = document.createElement('button');
    save.className = 'btn primary';
    save.textContent = '保存';

    foot.appendChild(cancel);
    foot.appendChild(save);

    modal.appendChild(head);
    modal.appendChild(body);
    modal.appendChild(foot);
    backdrop.appendChild(modal);
    document.body.appendChild(backdrop);

    const close = () => backdrop.remove();

    cancel.addEventListener('click',close);

    backdrop.addEventListener('click',event => {
        if (event.target === backdrop) close();
    });

    save.addEventListener('click',() => {
        connection.color = color;
        connection.width = clamp(Number(widthInput.value) || 2,1,8);
        connection.dash = dashSelect.value === 'dashed'
            ? 'dashed'
            : 'solid';

        markDirty();
        renderAll();
        close();
    });
}

/*
 * 接続線を右クリックした場合のメニューに
 * 「接続線の書式」を追加。
 */
const originalOpenContextMenu = openContextMenu;

function openContextMenuWithConnection(x,y,targetId,connectionId) {
    originalOpenContextMenu(x,y,targetId,connectionId);

    if (connectionId) {
        let styleButton = document.getElementById('ctxConnectionStyle');

        if (!styleButton) {
            styleButton = document.createElement('button');
            styleButton.id = 'ctxConnectionStyle';
            styleButton.textContent = '接続線の書式';
            styleButton.style.display = 'block';

            els.contextMenu.insertBefore(
                styleButton,
                els.ctxDelete
            );

            styleButton.addEventListener('click',() => {
                const id = state.contextTargetConnectionId;
                closeContextMenu();

                const connection = state.project?.connections.find(
                    c => c.id === id
                );

                if (connection) {
                    openConnectionStyleModal(connection);
                }
            });
        }

        styleButton.style.display = 'block';
    } else {
        const styleButton =
            document.getElementById('ctxConnectionStyle');

        if (styleButton) {
            styleButton.style.display = 'none';
        }
    }
}

/*
 * context menu呼び出しを統一。
 */
window.openContextMenu = openContextMenuWithConnection;

/* ---------------- initial state ---------------- */

els.zoomValue.textContent = '1.0×';
disableEditor();

window.addEventListener('resize', () => {
    if (state.editorReady) renderAll();
});

/*
 * ブラウザ終了時にBlob URLだけ解放。
 * IndexedDBにはBlob本体が残る。
 */
window.addEventListener('beforeunload', () => {
    if (state.videoUrl) {
        try {
            URL.revokeObjectURL(state.videoUrl);
        } catch (_) {}
    }
});

})();
</script>
</body>
</html>

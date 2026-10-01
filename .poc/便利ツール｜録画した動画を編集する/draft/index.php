<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * 保存:
 *   サーバー: ./data/projects/*.json
 *   ブラウザ: IndexedDB
 *
 * 注意:
 *   「書き出し」は編集プロジェクト(JSON)の書き出し。
 *   MP4への再エンコードにはFFmpeg等のサーバー処理が別途必要。
 */

const APP_VERSION = 12;
const MAX_SERVER_PROJECTS = 20;

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureProjectDir(): bool {
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true)) return false;
    if (!is_dir(PROJECT_DIR) && !@mkdir(PROJECT_DIR, 0775, true)) return false;
    return is_dir(PROJECT_DIR) && is_writable(PROJECT_DIR);
}

function validProjectId(string $id): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{8,100}$/', $id);
}

function projectPath(string $id): string {
    return PROJECT_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

function readProjects(): array {
    if (!is_dir(PROJECT_DIR)) return [];

    $items = [];
    foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $data = json_decode(@file_get_contents($file) ?: '', true);
        if (is_array($data) && isset($data['projectId'])) $items[] = $data;
    }

    usort($items, static fn($a, $b) =>
        strcmp((string)($b['savedAt'] ?? ''), (string)($a['savedAt'] ?? ''))
    );

    return $items;
}

function summary(array $p): array {
    return [
        'projectId' => (string)($p['projectId'] ?? ''),
        'name' => (string)($p['name'] ?? '名称未設定'),
        'videoName' => (string)($p['videoName'] ?? ''),
        'videoDuration' => (float)($p['videoDuration'] ?? 0),
        'savedAt' => (string)($p['savedAt'] ?? ''),
        'storage' => 'server'
    ];
}

/* =========================================================
 * API
 * ======================================================= */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    if ($api === 'status') {
        jsonResponse([
            'ok' => true,
            'version' => APP_VERSION,
            'serverWritable' => ensureProjectDir(),
            'serverLimit' => MAX_SERVER_PROJECTS,
            'serverCount' => count(readProjects()),
            'php' => PHP_VERSION
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map('summary', readProjects()),
            'limit' => MAX_SERVER_PROJECTS
        ]);
    }

    if ($api === 'load') {
        $id = (string)($_GET['id'] ?? '');

        if (!validProjectId($id)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
        }

        $file = projectPath($id);

        if (!is_file($file)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトが見つかりません。'], 404);
        }

        $project = json_decode(@file_get_contents($file) ?: '', true);

        if (!is_array($project)) {
            jsonResponse(['ok' => false, 'message' => '保存データが壊れています。'], 500);
        }

        jsonResponse(['ok' => true, 'project' => $project]);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        if (!ensureProjectDir()) {
            jsonResponse([
                'ok' => false,
                'message' => 'サーバー側の保存先に書き込めません。'
            ], 500);
        }

        $project = json_decode(file_get_contents('php://input') ?: '', true);

        if (!is_array($project)) {
            jsonResponse(['ok' => false, 'message' => 'JSONが不正です。'], 400);
        }

        $id = trim((string)($project['projectId'] ?? ''));

        if ($id === '') $id = 'project-' . bin2hex(random_bytes(8));

        if (!validProjectId($id)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
        }

        $name = trim((string)($project['name'] ?? ''));

        if ($name === '') {
            jsonResponse(['ok' => false, 'message' => 'プロジェクト名を入力してください。'], 422);
        }

        if (mb_strlen($name) > 120) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクト名は120文字以内です。'], 422);
        }

        $file = projectPath($id);
        $existing = is_file($file);

        if (!$existing && count(readProjects()) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' => 'サーバー保存上限に達しています。ローカル保存を使用してください。'
            ], 409);
        }

        $project['projectId'] = $id;
        $project['name'] = $name;
        $project['version'] = APP_VERSION;
        $project['savedAt'] = date('c');

        $json = json_encode(
            $project,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false || @file_put_contents($file, $json, LOCK_EX) === false) {
            jsonResponse(['ok' => false, 'message' => '保存に失敗しました。'], 500);
        }

        jsonResponse([
            'ok' => true,
            'projectId' => $id,
            'savedAt' => $project['savedAt']
        ]);
    }

    if ($api === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        $payload = json_decode(file_get_contents('php://input') ?: '', true);
        $id = is_array($payload) ? (string)($payload['projectId'] ?? '') : '';

        if (!validProjectId($id)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
        }

        $file = projectPath($id);

        if (is_file($file) && !@unlink($file)) {
            jsonResponse(['ok' => false, 'message' => '削除できませんでした。'], 500);
        }

        jsonResponse(['ok' => true]);
    }

    jsonResponse(['ok' => false, 'message' => 'Unknown API'], 404);
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画直接編集</title>

<style>
:root{
    --bg:#101216;
    --panel:#1b1e23;
    --panel2:#252a30;
    --panel3:#30353c;
    --border:#454c55;
    --text:#f4f6f8;
    --muted:#9da6b0;
    --blue:#1976d2;
    --green:#287c4a;
    --red:#a83b3b;
    --yellow:#ffd447;
    --orange:#e58b27;
    --purple:#a76be5;
}

*{box-sizing:border-box}

html,body{
    width:100%;
    height:100%;
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        sans-serif;
}

body{overflow:hidden}

button,input,select,textarea{font:inherit}

button{
    border:1px solid #555d66;
    background:var(--panel3);
    color:#fff;
    border-radius:6px;
    padding:7px 11px;
    cursor:pointer;
}

button:hover:not(:disabled){background:#414850}

button:disabled{
    opacity:.4;
    cursor:not-allowed;
}

button.primary{background:var(--blue)}
button.success{background:var(--green)}
button.danger{background:var(--red)}

input,select,textarea{
    width:100%;
    color:#fff;
    background:var(--panel2);
    border:1px solid #555d66;
    border-radius:5px;
    padding:7px;
}

textarea{
    min-height:90px;
    resize:vertical;
}

.hidden{display:none!important}

#message{
    position:fixed;
    top:58px;
    left:50%;
    transform:translateX(-50%);
    z-index:10000;
    display:none;
    max-width:90vw;
    padding:9px 15px;
    border-radius:7px;
    background:#a83b3b;
    box-shadow:0 10px 35px #000b;
    white-space:pre-wrap;
}

#message.ok{background:#287c4a}

/* =========================================================
   HEADER
========================================================= */

header{
    height:48px;
    display:flex;
    align-items:center;
    gap:12px;
    padding:0 12px;
    background:#1b1e22;
    border-bottom:1px solid #353a41;
}

header h1{
    margin:0;
    font-size:15px;
    white-space:nowrap;
}

#status{
    margin-left:auto;
    color:#aeb6bf;
    font-size:12px;
}

/* =========================================================
   HOME
========================================================= */

#home{
    height:calc(100vh - 48px);
    display:flex;
    justify-content:center;
    align-items:center;
    padding:20px;
}

.home-card{
    width:min(760px,95vw);
    max-height:90vh;
    overflow:auto;
    padding:25px;
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:10px;
}

.home-card h2{margin:0 0 8px}

.home-card p{
    color:var(--muted);
    font-size:13px;
    line-height:1.6;
}

.home-actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin:16px 0;
}

.project-list{margin-top:20px}

.project{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:10px;
    margin:7px 0;
    border:1px solid var(--border);
    border-radius:7px;
}

.project-info{min-width:0}

.project-name{
    font-weight:600;
}

.project-meta{
    margin-top:3px;
    color:#929aa4;
    font-size:11px;
}

.project-actions{
    display:flex;
    gap:5px;
    flex-shrink:0;
}

/* =========================================================
   EDITOR
========================================================= */

#editor{
    position:fixed;
    inset:48px 0 0;
    display:none;
    flex-direction:column;
    background:#0e1013;
}

.editor-top{
    height:46px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:7px;
    padding:5px 9px;
    background:#1b1e22;
    border-bottom:1px solid #363b42;
}

#editorProjectName{
    width:220px;
    font-weight:600;
}

#editorStatus{
    color:#aeb6bf;
    font-size:12px;
}

.editor-main{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}

/* 動画領域を必要以上に大きくしない */
.video-area{
    flex:1 1 auto;
    min-height:0;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:#050607;
    padding:8px;
}

#videoStage{
    position:relative;
    width:min(78vw,1200px);
    height:min(48vh,675px);
    max-width:100%;
    max-height:100%;
    background:#000;
    overflow:visible;
    line-height:0;
}

#recordedVideo{
    display:block;
    width:100%;
    height:100%;
    object-fit:contain;
}

#objects{
    position:absolute;
    inset:0;
    pointer-events:none;
}

#connectors{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
}

.edit-object{
    position:absolute;
    pointer-events:auto;
    cursor:move;
    user-select:none;
    touch-action:none;
    line-height:normal;
}

.edit-object.selected{
    outline:2px solid #fff;
    outline-offset:2px;
}

.edit-object.multi-selected{
    outline:2px dashed var(--yellow);
    outline-offset:3px;
}

.edit-object.comment{
    display:flex;
    align-items:center;
    padding:5px 8px;
    white-space:pre-wrap;
    word-break:break-word;
    overflow:hidden;
    box-shadow:0 2px 12px #0006;
}

.edit-object.box{
    background:transparent;
}

.edit-object.skip{
    background:#e58b2715;
}

.skip-label{
    position:absolute;
    top:3px;
    left:3px;
    padding:2px 5px;
    border-radius:4px;
    color:#fff;
    background:#c57418;
    font-size:10px;
    pointer-events:none;
}

.resize-handle{
    position:absolute;
    right:-7px;
    bottom:-7px;
    width:14px;
    height:14px;
    border-radius:50%;
    background:#fff;
    border:1px solid #222;
    cursor:nwse-resize;
    display:none;
}

.edit-object.selected .resize-handle{
    display:block;
}

.connection-point{
    position:absolute;
    width:13px;
    height:13px;
    margin:-6.5px;
    border:2px solid #fff;
    background:#2680d9;
    border-radius:50%;
    display:none;
    z-index:20;
    cursor:crosshair;
}

.edit-object.selected .connection-point,
.edit-object.multi-selected .connection-point{
    display:block;
}

.cp-n{left:50%;top:0}
.cp-e{left:100%;top:50%}
.cp-s{left:50%;top:100%}
.cp-w{left:0;top:50%}

.connector{
    fill:none;
    pointer-events:none;
}

.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:14;
    pointer-events:stroke;
    cursor:pointer;
}

/* =========================================================
   TIMELINE
========================================================= */

#timeline{
    flex:0 0 245px;
    min-height:245px;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:7px 10px 9px;
    overflow:hidden;
}

.timeline-control{
    display:flex;
    align-items:center;
    gap:7px;
    height:35px;
}

#playToggle{
    width:42px;
    height:30px;
    padding:0;
    font-size:16px;
    flex-shrink:0;
}

#seek{
    flex:1;
    min-width:0;
    margin:0;
}

#timeReadout{
    width:150px;
    text-align:center;
    font-size:12px;
    font-variant-numeric:tabular-nums;
    flex-shrink:0;
}

.timeline-tools{
    display:flex;
    align-items:center;
    gap:5px;
    margin:3px 0 4px 49px;
}

.timeline-tools label{
    color:#aeb5bd;
    font-size:11px;
}

.timeline-tools select{
    width:90px;
    padding:3px 5px;
    font-size:11px;
}

.timeline-scroll{
    height:160px;
    overflow-x:auto;
    overflow-y:auto;
}

.timeline-content{
    position:relative;
    min-width:100%;
    width:100%;
}

.timeline-scale{
    position:relative;
    height:22px;
    border-left:1px solid #4a5058;
    border-right:1px solid #4a5058;
}

.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    color:#8f969f;
    font-size:9px;
    white-space:nowrap;
}

.timeline-track{
    position:relative;
    height:30px;
    margin:3px 0;
    background:#292d33;
    border:1px solid #3c4249;
    border-radius:4px;
}

.timeline-label{
    position:absolute;
    left:4px;
    top:6px;
    z-index:2;
    color:#b9c0c8;
    font-size:9px;
    pointer-events:none;
}

.track-item{
    position:absolute;
    top:3px;
    height:23px;
    min-width:5px;
    border-radius:3px;
    cursor:grab;
    overflow:visible;
}

.track-item:active{cursor:grabbing}

.track-item.comment{background:#42a5f5}
.track-item.box{background:#ef5350}
.track-item.skip{background:var(--orange)}
.track-item.connection{background:var(--purple)}

.track-item.selected{
    outline:2px solid #fff;
    outline-offset:1px;
}

.track-time,
.track-end-time{
    position:absolute;
    top:-15px;
    color:#e0e5ea;
    font-size:8px;
    white-space:nowrap;
    pointer-events:none;
}

.track-time{left:0}
.track-end-time{right:0}

.track-name{
    position:absolute;
    left:5px;
    right:5px;
    top:4px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:#fff;
    font-size:8px;
    pointer-events:none;
}

.trim-handle{
    position:absolute;
    top:0;
    bottom:0;
    width:8px;
    z-index:3;
    cursor:ew-resize;
}

.trim-handle.left{left:-4px}
.trim-handle.right{right:-4px}

/* 再生インデックス */
#playhead{
    position:absolute;
    top:0;
    bottom:0;
    width:2px;
    background:#ff3b30;
    box-shadow:0 0 4px #ff3b30;
    pointer-events:none;
    z-index:100;
}

#playhead::before{
    content:"";
    position:absolute;
    left:-5px;
    top:-1px;
    width:12px;
    height:8px;
    background:#ff3b30;
    clip-path:polygon(0 0,100% 0,50% 100%);
}

/* =========================================================
   FOOTER
========================================================= */

#editorFooter{
    min-height:40px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:10px;
    padding:5px;
    background:#1b1e22;
    border-top:1px solid #383d44;
}

.connection-status{
    color:#cdd3da;
    font-size:11px;
}

/* =========================================================
   CONTEXT MENU
========================================================= */

#contextMenu{
    position:fixed;
    z-index:7000;
    display:none;
    width:245px;
    padding:5px;
    border:1px solid #555b63;
    border-radius:7px;
    background:#292d33;
    box-shadow:0 12px 40px #000c;
}

#contextMenu button{
    display:block;
    width:100%;
    text-align:left;
    background:transparent;
    border:0;
}

#contextMenu button:hover{background:#3c424a}

.context-separator{
    height:1px;
    margin:5px 0;
    background:#464c54;
}

/* =========================================================
   MODAL
========================================================= */

.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:8000;
    display:none;
    align-items:center;
    justify-content:center;
    background:#0009;
}

.modal{
    width:min(520px,94vw);
    max-height:90vh;
    overflow:auto;
    padding:18px;
    background:#1d2126;
    border:1px solid #4c535c;
    border-radius:9px;
    box-shadow:0 20px 60px #000d;
}

.modal h3{margin:0 0 15px}

.form-row{
    display:grid;
    grid-template-columns:105px 1fr;
    gap:10px;
    align-items:center;
    margin:9px 0;
}

.form-row > label{
    color:#c2c8ce;
    font-size:12px;
}

.palette{
    display:grid;
    grid-template-columns:repeat(6,32px);
    gap:5px;
    margin-bottom:6px;
}

.palette button{
    width:30px;
    height:30px;
    padding:0;
    border-radius:4px;
}

.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:15px;
}

/* 編集ロック */
#editor.locked #videoStage,
#editor.locked #timeline{
    opacity:.55;
}

#editor.locked #videoStage::after{
    content:"動画を読み込むまで編集できません";
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    background:#0007;
    font-size:14px;
    line-height:normal;
    pointer-events:none;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:700px){
    .editor-top{flex-wrap:wrap;height:auto;min-height:46px}
    #editorProjectName{width:150px}
    #timeline{flex-basis:235px;min-height:235px}
    .timeline-tools{margin-left:0}
    #timeReadout{width:110px}
}
</style>
</head>

<body>

<header>
    <h1>動画直接編集</h1>
    <span id="status">準備中</span>
</header>

<div id="message"></div>

<section id="home">
    <div class="home-card">
        <h2>動画編集</h2>
        <p>
            動画を読み込んだあと、動画画面上で直接要素を配置できます。
            要素の追加・移動・サイズ変更・テキスト変更・書式変更は右クリックから操作できます。
        </p>

        <div class="home-actions">
            <button id="newProject">新しい編集を開始</button>
            <button id="openVideo" class="primary">動画を読み込む</button>
            <button id="importProject">編集データを読み込む</button>
            <input id="videoFile" type="file" accept="video/*" class="hidden">
            <input id="projectFile" type="file" accept=".json,application/json" class="hidden">
        </div>

        <div id="projectList" class="project-list"></div>
    </div>
</section>

<section id="editor">

    <div class="editor-top">
        <button id="backHome">← 戻る</button>

        <input
            id="editorProjectName"
            value="新しい編集"
            aria-label="プロジェクト名"
        >

        <button id="loadVideoButton" class="primary">
            動画を読み込む
        </button>

        <button id="saveProject" class="success">
            保存
        </button>

        <button id="exportProject">
            書き出し
        </button>

        <input id="editorVideoFile" type="file" accept="video/*" class="hidden">

        <span id="editorStatus">動画を読み込んでください</span>
    </div>

    <div class="editor-main">

        <div id="videoArea" class="video-area">

            <div id="videoStage">

                <video id="recordedVideo" preload="metadata"></video>

                <svg id="connectors"></svg>

                <div id="objects"></div>

            </div>

        </div>

        <div id="timeline">

            <div class="timeline-control">
                <button id="playToggle" disabled>▶</button>
                <input id="seek" type="range" min="0" max="0" step="0.01" value="0" disabled>
                <div id="timeReadout">00:00.000 / 00:00.000</div>
            </div>

            <div class="timeline-tools">
                <label>時間軸</label>
                <button id="zoomOut">−</button>
                <select id="timelineZoom">
                    <option value="0.5">0.5秒</option>
                    <option value="1" selected>1秒</option>
                    <option value="2">2秒</option>
                    <option value="5">5秒</option>
                    <option value="10">10秒</option>
                    <option value="30">30秒</option>
                </select>
                <button id="zoomIn">＋</button>
                <span style="color:#888;font-size:10px">
                    ※表示間隔
                </span>
            </div>

            <div id="timelineScroll" class="timeline-scroll">
                <div id="timelineContent" class="timeline-content">
                    <div id="timelineScale" class="timeline-scale"></div>
                    <div id="timelineTracks"></div>
                    <div id="playhead"></div>
                </div>
            </div>

        </div>

    </div>

    <div id="editorFooter">
        <span id="connectionStatus" class="connection-status">
            Shift+クリックで2要素を選択 → 右クリックで接続
        </span>
    </div>

</section>

<div id="contextMenu">
    <button data-action="add-comment">＋ テキスト</button>
    <button data-action="add-box">＋ 強調枠</button>
    <button data-action="add-skip">＋ スキップ</button>

    <div class="context-separator"></div>

    <button data-action="edit">編集</button>
    <button data-action="duplicate">複製</button>
    <button data-action="delete">削除</button>

    <div class="context-separator"></div>

    <button data-action="connect">🔗 選択した2要素を接続</button>
</div>

<div id="elementModal" class="modal-backdrop">

    <div class="modal">

        <h3 id="modalTitle">要素を編集</h3>

        <div class="form-row">
            <label>種類</label>
            <div id="modalType"></div>
        </div>

        <div class="form-row" id="textRow">
            <label>テキスト</label>
            <textarea id="elementText"></textarea>
        </div>

        <div class="form-row">
            <label>開始</label>
            <input id="elementStart" type="number" min="0" step="0.01">
        </div>

        <div class="form-row">
            <label>終了</label>
            <input id="elementEnd" type="number" min="0" step="0.01">
        </div>

        <div class="form-row">
            <label>枠線色</label>
            <div>
                <div id="palette" class="palette"></div>
                <input id="elementColor" type="color">
            </div>
        </div>

        <div class="form-row">
            <label>枠線太さ</label>
            <input id="elementBorderWidth" type="number" min="0" max="20" step="0.5">
        </div>

        <div class="form-row" id="fontSizeRow">
            <label>文字サイズ</label>
            <input id="elementFontSize" type="number" min="8" max="200">
        </div>

        <div class="form-row" id="fontWeightRow">
            <label>文字太さ</label>
            <select id="elementFontWeight">
                <option value="400">通常</option>
                <option value="600">太字</option>
                <option value="700">極太</option>
            </select>
        </div>

        <div class="form-row">
            <label>線種</label>
            <select id="elementBorderStyle">
                <option value="solid">実線</option>
                <option value="dashed">破線</option>
                <option value="dotted">点線</option>
            </select>
        </div>

        <div class="modal-actions">
            <button id="modalCancel">キャンセル</button>
            <button id="modalSave" class="primary">保存</button>
        </div>

    </div>

</div>

<script>
'use strict';

/* =========================================================
   基本
========================================================= */

const $ = id => document.getElementById(id);

const COLORS = [
    '#ffffff',
    '#000000',
    '#ff3b30',
    '#ff9500',
    '#ffcc00',
    '#34c759',
    '#00c7be',
    '#007aff',
    '#5856d6',
    '#af52de',
    '#ff2d55',
    '#8e8e93'
];

const DB_NAME = 'video-direct-editor-v12';
const DB_VERSION = 1;
const STORE = 'projects';

const state = {
    project:null,
    selectedId:null,
    selectedType:null,
    multiSelected:[],
    context:null,
    drag:null,
    timelineDrag:null,
    connectionDrag:null,
    db:null,
    videoObjectUrl:null,
    dirty:false,
    zoomStep:1,
    modalId:null,
    skipLock:false
};

function uid(prefix='id'){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,9);
}

function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}

function duration(){
    const d = $('recordedVideo').duration;
    return Number.isFinite(d) ? d : 0;
}

function now(){
    return Number($('recordedVideo').currentTime || 0);
}

function fmt(v){
    v = Math.max(0,Number(v)||0);
    const m = Math.floor(v / 60);
    const s = Math.floor(v % 60);
    const ms = Math.floor((v % 1) * 1000);

    return String(m).padStart(2,'0') + ':' +
        String(s).padStart(2,'0') + '.' +
        String(ms).padStart(3,'0');
}

let messageTimer;

function message(text,ok=false){
    const el = $('message');
    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    clearTimeout(messageTimer);

    messageTimer = setTimeout(()=>{
        el.style.display = 'none';
    },3000);
}

function markDirty(){
    state.dirty = true;
    $('editorStatus').textContent = '変更あり';
}

/* =========================================================
   IndexedDB
========================================================= */

function openDB(){
    return new Promise((resolve,reject)=>{
        const request = indexedDB.open(DB_NAME,DB_VERSION);

        request.onupgradeneeded = ()=>{
            const db = request.result;

            if(!db.objectStoreNames.contains(STORE)){
                db.createObjectStore(STORE,{
                    keyPath:'projectId'
                });
            }
        };

        request.onsuccess = ()=>{
            state.db = request.result;
            resolve(state.db);
        };

        request.onerror = ()=>reject(request.error);
    });
}

function idbPut(project){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            reject(new Error('IndexedDB unavailable'));
            return;
        }

        const tx = state.db.transaction(STORE,'readwrite');
        tx.objectStore(STORE).put(project);

        tx.oncomplete = resolve;
        tx.onerror = ()=>reject(tx.error);
        tx.onabort = ()=>reject(tx.error);
    });
}

function idbGet(id){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(STORE,'readonly');
        const req = tx.objectStore(STORE).get(id);

        req.onsuccess = ()=>resolve(req.result || null);
        req.onerror = ()=>reject(req.error);
    });
}

function idbGetAll(){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(STORE,'readonly');
        const req = tx.objectStore(STORE).getAll();

        req.onsuccess = ()=>resolve(req.result || []);
        req.onerror = ()=>reject(req.error);
    });
}

function idbDelete(id){
    return new Promise((resolve,reject)=>{
        const tx = state.db.transaction(STORE,'readwrite');
        tx.objectStore(STORE).delete(id);

        tx.oncomplete = resolve;
        tx.onerror = ()=>reject(tx.error);
    });
}

/* =========================================================
   Project
========================================================= */

function newProject(){
    state.project = {
        version:12,
        projectId:uid('project'),
        name:'新しい編集',
        videoName:'',
        videoType:'',
        videoSize:0,
        videoDuration:0,
        videoBlob:null,
        elements:[],
        connections:[],
        createdAt:new Date().toISOString(),
        savedAt:''
    };

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];
    state.dirty = false;

    $('editorProjectName').value = state.project.name;

    showEditor();
    setEditorLocked(true);
    renderAll();
}

function showEditor(){
    $('home').style.display = 'none';
    $('editor').style.display = 'flex';
}

function showHome(){
    $('editor').style.display = 'none';
    $('home').style.display = 'flex';

    if(state.videoObjectUrl){
        URL.revokeObjectURL(state.videoObjectUrl);
        state.videoObjectUrl = null;
    }

    $('recordedVideo').pause();
    $('recordedVideo').removeAttribute('src');
    $('recordedVideo').load();

    renderProjectList();
}

function setEditorLocked(locked){
    $('editor').classList.toggle('locked',locked);

    $('playToggle').disabled = locked;
    $('seek').disabled = locked;
}

function makeElement(type,time){
    const d = duration();

    const start = clamp(
        Number.isFinite(time) ? time : now(),
        0,
        Math.max(0,d)
    );

    const end = d
        ? Math.min(d,start + Math.min(5,d-start))
        : start + 5;

    return {
        id:uid('element'),
        type,
        text:type === 'comment' ? 'テキスト' : '',
        start,
        end:Math.max(start + .05,end),
        x:10,
        y:10,
        w:type === 'box' ? 35 : 30,
        h:type === 'box' ? 25 : 15,
        color:type === 'skip' ? '#ff9500' : '#ffffff',
        borderWidth:type === 'skip' ? 2 : 2,
        borderStyle:type === 'skip' ? 'dashed' : 'solid',
        fontSize:24,
        fontWeight:600
    };
}

function getElement(id){
    return state.project?.elements.find(e=>e.id===id) || null;
}

function normalizeElement(e){
    const d = duration() || state.project.videoDuration || 0;

    e.start = clamp(Number(e.start)||0,0,d);
    e.end = clamp(
        Number(e.end)||Math.min(d,e.start+5),
        Math.min(d,e.start+.05),
        d
    );

    e.x = clamp(Number(e.x)||0,0,99);
    e.y = clamp(Number(e.y)||0,0,99);
    e.w = clamp(Number(e.w)||10,1,100-e.x);
    e.h = clamp(Number(e.h)||10,1,100-e.y);
}

/* =========================================================
   Video
========================================================= */

async function handleVideo(file){
    if(!file) return;

    if(!file.type.startsWith('video/')){
        message('動画ファイルを選択してください。');
        return;
    }

    try{
        if(!state.project) newProject();

        const video = $('recordedVideo');

        setEditorLocked(true);
        $('editorStatus').textContent = '動画を読み込んでいます…';

        if(state.videoObjectUrl){
            URL.revokeObjectURL(state.videoObjectUrl);
        }

        state.videoObjectUrl = URL.createObjectURL(file);

        video.src = state.videoObjectUrl;
        video.load();

        state.project.videoName = file.name;
        state.project.videoType = file.type;
        state.project.videoSize = file.size;
        state.project.videoBlob = file;

        showEditor();

    }catch(err){
        console.error(err);
        message('動画の読み込みに失敗しました。');
    }
}

$('recordedVideo').addEventListener('loadedmetadata',async ()=>{
    const d = duration();

    if(!state.project) return;

    state.project.videoDuration = d;

    setEditorLocked(false);
    $('editorStatus').textContent = '編集可能';

    $('seek').min = 0;
    $('seek').max = d;
    $('seek').step = '0.01';

    state.project.elements.forEach(normalizeElement);

    renderAll();

    /*
     * 動画BlobもIndexedDBへ保持。
     * リロード後にも編集データと動画を復元できる。
     */
    try{
        await idbPut(state.project);
    }catch(err){
        console.warn('IndexedDB保存:',err);
    }
});

/* =========================================================
   Rendering
========================================================= */

function isVisible(e,t){
    return t >= e.start && t < e.end;
}

function renderObjects(){
    const wrap = $('objects');
    wrap.innerHTML = '';

    if(!state.project) return;

    const t = now();

    state.project.elements.forEach(e=>{
        if(!isVisible(e,t)) return;

        const el = document.createElement('div');

        el.className =
            'edit-object ' +
            e.type +
            (state.selectedId===e.id ? ' selected' : '') +
            (state.multiSelected.includes(e.id) ? ' multi-selected' : '');

        el.dataset.id = e.id;

        el.style.left = e.x + '%';
        el.style.top = e.y + '%';
        el.style.width = e.w + '%';
        el.style.height = e.h + '%';

        el.style.border =
            `${e.borderWidth}px ${e.borderStyle} ${e.color}`;

        if(e.type==='comment'){
            el.textContent = e.text || 'テキスト';
            el.style.color = e.color;
            el.style.fontSize = e.fontSize + 'px';
            el.style.fontWeight = e.fontWeight;
        }

        if(e.type==='box'){
            el.style.border =
                `${e.borderWidth}px ${e.borderStyle} ${e.color}`;
        }

        if(e.type==='skip'){
            const label = document.createElement('span');
            label.className = 'skip-label';
            label.textContent = 'SKIP';
            el.appendChild(label);
        }

        const resize = document.createElement('span');
        resize.className = 'resize-handle';
        resize.dataset.resize = '1';
        el.appendChild(resize);

        ['n','e','s','w'].forEach(pos=>{
            const cp = document.createElement('span');
            cp.className = `connection-point cp-${pos}`;
            cp.dataset.cp = pos;
            el.appendChild(cp);
        });

        el.addEventListener('pointerdown',beginElementPointer);

        el.addEventListener('click',event=>{
            if(event.shiftKey){
                event.preventDefault();
                toggleMultiSelection(e.id);
                return;
            }

            if(!event.target.closest('.resize-handle')){
                state.selectedId = e.id;
                state.selectedType = 'element';
                state.multiSelected = [e.id];
                updateSelectionUI();
            }
        });

        el.addEventListener('contextmenu',event=>{
            event.preventDefault();
            event.stopPropagation();

            state.selectedId = e.id;
            state.selectedType = 'element';

            if(!state.multiSelected.includes(e.id)){
                state.multiSelected = [e.id];
            }

            updateSelectionUI();

            state.context = {
                type:'element',
                id:e.id
            };

            showContextMenu(event.clientX,event.clientY);
        });

        wrap.appendChild(el);
    });
}

function renderConnectors(){
    const svg = $('connectors');
    svg.innerHTML = '';

    if(!state.project) return;

    const stage = $('videoStage');

    state.project.connections.forEach(c=>{
        const a = getElement(c.from);
        const b = getElement(c.to);

        if(!a || !b) return;

        const x1 = (a.x + a.w) / 100 * stage.clientWidth;
        const y1 = (a.y + a.h/2) / 100 * stage.clientHeight;
        const x2 = b.x / 100 * stage.clientWidth;
        const y2 = (b.y + b.h/2) / 100 * stage.clientHeight;

        const dx = Math.max(40,Math.abs(x2-x1)*.4);

        const path =
            `M ${x1} ${y1} C ${x1+dx} ${y1}, ${x2-dx} ${y2}, ${x2} ${y2}`;

        const visible =
            isVisible(a,now()) || isVisible(b,now());

        if(!visible) return;

        const hit = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        hit.setAttribute('d',path);
        hit.classList.add('connector-hit');

        hit.addEventListener('contextmenu',event=>{
            event.preventDefault();
            event.stopPropagation();

            state.context = {
                type:'connection',
                id:c.id
            };

            showContextMenu(event.clientX,event.clientY);
        });

        svg.appendChild(hit);

        const line = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        line.setAttribute('d',path);
        line.classList.add('connector');

        /*
         * 接続元の枠線色をそのまま使用。
         * 初期値は細い2px。
         */
        line.setAttribute('stroke',a.color || '#fff');
        line.setAttribute('stroke-width',String(c.width || 2));

        svg.appendChild(line);
    });
}

function renderTimeline(){
    if(!state.project) return;

    const d = duration() || state.project.videoDuration || 1;
    const content = $('timelineContent');
    const scale = $('timelineScale');
    const tracks = $('timelineTracks');

    const zoom =
        Number(state.zoomStep) || 1;

    /*
     * 時間そのものとピクセルの対応を固定。
     * 動画時間の途中でバーが切れない。
     */
    const pxPerSecond = 60 / zoom;
    const width = Math.max(
        $('timelineScroll').clientWidth,
        d * pxPerSecond
    );

    content.style.width = width + 'px';
    scale.style.width = width + 'px';

    scale.innerHTML = '';

    const step = Number($('timelineZoom').value) || 1;

    for(let t=0;t<=d;t+=step){
        const span = document.createElement('span');
        span.style.left = (t/d*100) + '%';
        span.textContent = fmt(t);
        scale.appendChild(span);
    }

    tracks.innerHTML = '';

    const groups = [
        ['comment','テキスト'],
        ['box','強調枠'],
        ['skip','スキップ'],
        ['connection','接続']
    ];

    groups.forEach(([type,label])=>{
        const row = document.createElement('div');
        row.className = 'timeline-track';

        const labelEl = document.createElement('div');
        labelEl.className = 'timeline-label';
        labelEl.textContent = label;
        row.appendChild(labelEl);

        if(type==='connection'){
            state.project.connections.forEach(c=>{
                const a = getElement(c.from);
                const b = getElement(c.to);

                if(!a || !b) return;

                const item = document.createElement('div');
                item.className = 'track-item connection';

                const start = Math.min(a.start,b.start);
                const end = Math.max(a.end,b.end);

                item.style.left = (start/d*100) + '%';
                item.style.width =
                    Math.max(.2,(end-start)/d*100) + '%';

                item.title =
                    `${a.text || a.type} → ${b.text || b.type}`;

                row.appendChild(item);
            });
        }else{
            state.project.elements
                .filter(e=>e.type===type)
                .forEach(e=>{
                    const item = document.createElement('div');

                    item.className =
                        `track-item ${e.type}` +
                        (state.selectedId===e.id ? ' selected' : '');

                    item.dataset.id = e.id;

                    item.style.left =
                        (e.start/d*100) + '%';

                    item.style.width =
                        Math.max(.15,(e.end-e.start)/d*100) + '%';

                    const startLabel =
                        document.createElement('span');

                    startLabel.className='track-time';
                    startLabel.textContent=fmt(e.start);

                    const endLabel =
                        document.createElement('span');

                    endLabel.className='track-end-time';
                    endLabel.textContent=fmt(e.end);

                    const name =
                        document.createElement('span');

                    name.className='track-name';
                    name.textContent =
                        e.type==='comment'
                            ? (e.text || 'テキスト')
                            : e.type==='box'
                                ? '強調枠'
                                : 'スキップ';

                    const left =
                        document.createElement('span');

                    left.className='trim-handle left';

                    const right =
                        document.createElement('span');

                    right.className='trim-handle right';

                    item.append(
                        startLabel,
                        endLabel,
                        name,
                        left,
                        right
                    );

                    item.addEventListener(
                        'pointerdown',
                        event=>beginTimelinePointer(event,e)
                    );

                    item.addEventListener(
                        'click',
                        event=>{
                            if(event.shiftKey){
                                event.preventDefault();
                                toggleMultiSelection(e.id);
                            }else{
                                selectElement(e.id);
                            }
                        }
                    );

                    item.addEventListener(
                        'contextmenu',
                        event=>{
                            event.preventDefault();
                            event.stopPropagation();

                            selectElement(e.id);

                            state.context = {
                                type:'element',
                                id:e.id
                            };

                            showContextMenu(
                                event.clientX,
                                event.clientY
                            );
                        }
                    );

                    row.appendChild(item);
                });
        }

        tracks.appendChild(row);
    });

    updatePlayhead();
}

function updatePlayhead(){
    if(!state.project) return;

    const d = duration() || state.project.videoDuration || 1;

    const p = clamp(now()/d,0,1);

    $('playhead').style.left = (p*100) + '%';

    $('seek').value = now();

    $('timeReadout').textContent =
        `${fmt(now())} / ${fmt(d)}`;
}

/* =========================================================
   Selection
========================================================= */

function selectElement(id){
    state.selectedId=id;
    state.selectedType='element';
    state.multiSelected=[id];

    updateSelectionUI();
}

function toggleMultiSelection(id){
    if(state.multiSelected.includes(id)){
        state.multiSelected =
            state.multiSelected.filter(v=>v!==id);
    }else{
        if(state.multiSelected.length>=2){
            state.multiSelected.shift();
        }

        state.multiSelected.push(id);
    }

    state.selectedId =
        state.multiSelected[state.multiSelected.length-1] || null;

    state.selectedType =
        state.selectedId ? 'element' : null;

    updateSelectionUI();
}

function updateSelectionUI(){
    document.querySelectorAll('.edit-object').forEach(el=>{
        const id=el.dataset.id;

        el.classList.toggle(
            'selected',
            id===state.selectedId
        );

        el.classList.toggle(
            'multi-selected',
            state.multiSelected.includes(id)
        );
    });

    document.querySelectorAll('.track-item').forEach(el=>{
        el.classList.toggle(
            'selected',
            el.dataset.id===state.selectedId
        );
    });

    const n=state.multiSelected.length;

    if(n===2){
        $('connectionStatus').textContent =
            '2要素選択中 → 右クリック →「選択した2要素を接続」';
    }else if(n===1){
        $('connectionStatus').textContent =
            '1要素選択中。Shift+クリックで2つ目を選択できます。';
    }else{
        $('connectionStatus').textContent =
            'Shift+クリックで2要素を選択 → 右クリックで接続';
    }
}

/* =========================================================
   Element drag / resize
========================================================= */

function beginElementPointer(event){
    if(event.button!==0) return;
    if(!state.project || !duration()) return;

    const target =
        event.currentTarget.closest('.edit-object');

    if(!target) return;

    const id=target.dataset.id;
    const e=getElement(id);

    if(!e) return;

    if(event.shiftKey){
        toggleMultiSelection(id);
        return;
    }

    selectElement(id);

    const resize =
        event.target.closest('.resize-handle');

    const stage=$('videoStage');
    const rect=stage.getBoundingClientRect();

    state.drag={
        id,
        mode:resize?'resize':'move',
        startX:event.clientX,
        startY:event.clientY,
        x:e.x,
        y:e.y,
        w:e.w,
        h:e.h,
        pointerId:event.pointerId
    };

    try{
        target.setPointerCapture(event.pointerId);
    }catch(err){
        /*
         * DOM再描画などでcapture対象が消えた場合でも
         * 編集全体を止めない。
         */
    }

    event.preventDefault();
}

function moveElementPointer(event){
    const d=state.drag;
    if(!d) return;

    const e=getElement(d.id);
    if(!e) return;

    const rect=$('videoStage').getBoundingClientRect();

    const dx=(event.clientX-d.startX)/rect.width*100;
    const dy=(event.clientY-d.startY)/rect.height*100;

    if(d.mode==='move'){
        e.x=clamp(d.x+dx,0,100-e.w);
        e.y=clamp(d.y+dy,0,100-e.h);
    }else{
        e.w=clamp(d.w+dx,1,100-d.x);
        e.h=clamp(d.h+dy,1,100-d.y);
    }

    markDirty();
    renderObjects();
    renderConnectors();
}

function endElementPointer(){
    if(state.drag){
        state.drag=null;
        renderTimeline();
    }
}

/* =========================================================
   Timeline drag
========================================================= */

function beginTimelinePointer(event,e){
    if(event.button!==0) return;

    const item=event.currentTarget;
    const handle=event.target.closest('.trim-handle');

    selectElement(e.id);

    const rect=item.getBoundingClientRect();

    state.timelineDrag={
        id:e.id,
        mode:handle
            ? handle.classList.contains('left')
                ? 'left'
                : 'right'
            : 'move',
        startX:event.clientX,
        start:e.start,
        end:e.end,
        width:rect.width
    };

    event.preventDefault();
}

function moveTimeline(event){
    const d=state.timelineDrag;
    if(!d) return;

    const e=getElement(d.id);
    if(!e) return;

    const timelineWidth =
        $('timelineContent').getBoundingClientRect().width;

    const videoDuration =
        duration() || state.project.videoDuration || 1;

    const delta =
        (event.clientX-d.startX) /
        timelineWidth *
        videoDuration;

    if(d.mode==='left'){
        e.start=clamp(
            d.start+delta,
            0,
            d.end-.05
        );
    }else if(d.mode==='right'){
        e.end=clamp(
            d.end+delta,
            d.start+.05,
            videoDuration
        );
    }else{
        const len=d.end-d.start;
        const ns=clamp(
            d.start+delta,
            0,
            videoDuration-len
        );

        e.start=ns;
        e.end=ns+len;
    }

    markDirty();

    renderTimeline();
    renderObjects();
}

function endTimelineDrag(){
    state.timelineDrag=null;
}

/* =========================================================
   Add / duplicate / delete
========================================================= */

function addElement(type){
    if(!state.project || !duration()){
        message('先に動画を読み込んでください。');
        return;
    }

    const e=makeElement(type,now());

    state.project.elements.push(e);

    selectElement(e.id);
    markDirty();

    renderAll();

    if(type!=='skip'){
        openElementModal(e);
    }
}

function duplicateSelected(){
    if(state.selectedType!=='element') return;

    const e=getElement(state.selectedId);
    if(!e) return;

    const copy={
        ...e,
        id:uid('element'),
        x:clamp(e.x+3,0,100-e.w),
        y:clamp(e.y+3,0,100-e.h)
    };

    state.project.elements.push(copy);

    selectElement(copy.id);
    markDirty();
    renderAll();
}

function deleteSelected(){
    if(state.context?.type==='connection'){
        const id=state.context.id;

        state.project.connections =
            state.project.connections.filter(c=>c.id!==id);

        state.context=null;
        markDirty();
        renderAll();
        return;
    }

    if(state.multiSelected.length){
        const ids=new Set(state.multiSelected);

        state.project.elements =
            state.project.elements.filter(e=>!ids.has(e.id));

        state.project.connections =
            state.project.connections.filter(c=>
                !ids.has(c.from) &&
                !ids.has(c.to)
            );

        state.selectedId=null;
        state.selectedType=null;
        state.multiSelected=[];

        state.context=null;

        markDirty();
        renderAll();
    }
}

/* =========================================================
   Connection
========================================================= */

function connectSelected(){
    if(state.multiSelected.length!==2){
        message(
            '接続するにはShift+クリックで2つの要素を選択してください。'
        );
        return;
    }

    const [a,b]=state.multiSelected;

    if(a===b) return;

    const exists =
        state.project.connections.some(c=>
            (c.from===a && c.to===b) ||
            (c.from===b && c.to===a)
        );

    if(exists){
        message('その2要素はすでに接続されています。');
        return;
    }

    state.project.connections.push({
        id:uid('connection'),
        from:a,
        to:b,
        width:2
    });

    markDirty();
    renderAll();

    message('2つの要素を接続しました。',true);
}

/* =========================================================
   Context menu
========================================================= */

function showContextMenu(x,y){
    const menu=$('contextMenu');

    const connect =
        menu.querySelector('[data-action="connect"]');

    connect.disabled =
        state.multiSelected.length!==2;

    menu.style.display='block';

    const w=250;
    const h=menu.offsetHeight;

    menu.style.left =
        Math.min(x,window.innerWidth-w-8)+'px';

    menu.style.top =
        Math.min(y,window.innerHeight-h-8)+'px';
}

function hideContextMenu(){
    $('contextMenu').style.display='none';
}

$('contextMenu').addEventListener('click',event=>{
    const btn=event.target.closest('[data-action]');
    if(!btn) return;

    const action=btn.dataset.action;
    const context=state.context;

    hideContextMenu();

    if(action==='add-comment'){
        addElement('comment');
        return;
    }

    if(action==='add-box'){
        addElement('box');
        return;
    }

    if(action==='add-skip'){
        addElement('skip');
        return;
    }

    if(action==='edit'){
        if(context?.type==='element'){
            const e=getElement(context.id);
            if(e) openElementModal(e);
        }
        return;
    }

    if(action==='duplicate'){
        duplicateSelected();
        return;
    }

    if(action==='delete'){
        deleteSelected();
        return;
    }

    if(action==='connect'){
        connectSelected();
    }
});

/* =========================================================
   Element modal
========================================================= */

function openElementModal(e){
    if(!e) return;

    state.modalId=e.id;

    $('modalTitle').textContent =
        e.type==='comment'
            ? 'テキストを編集'
            : e.type==='box'
                ? '強調枠を編集'
                : 'スキップを編集';

    $('modalType').textContent =
        e.type==='comment'
            ? 'テキスト'
            : e.type==='box'
                ? '強調枠'
                : 'スキップ';

    $('elementText').value=e.text || '';

    $('elementStart').value=
        Number(e.start).toFixed(2);

    $('elementEnd').value=
        Number(e.end).toFixed(2);

    $('elementColor').value=e.color || '#ffffff';

    $('elementBorderWidth').value=
        e.borderWidth ?? 2;

    $('elementFontSize').value=
        e.fontSize ?? 24;

    $('elementFontWeight').value=
        String(e.fontWeight ?? 600);

    $('elementBorderStyle').value=
        e.borderStyle || 'solid';

    $('textRow').style.display =
        e.type==='comment' ? '' : 'none';

    $('fontSizeRow').style.display =
        e.type==='comment' ? '' : 'none';

    $('fontWeightRow').style.display =
        e.type==='comment' ? '' : 'none';

    buildPalette(e.color || '#ffffff');

    $('elementModal').style.display='flex';
}

function closeElementModal(){
    state.modalId=null;
    $('elementModal').style.display='none';
}

function buildPalette(current){
    const palette=$('palette');
    palette.innerHTML='';

    COLORS.forEach(color=>{
        const b=document.createElement('button');

        b.type='button';
        b.style.background=color;
        b.title=color;

        if(color===current){
            b.style.outline='2px solid #ffd447';
            b.style.outlineOffset='2px';
        }

        b.addEventListener('click',()=>{
            $('elementColor').value=color;

            palette.querySelectorAll('button')
                .forEach(x=>{
                    x.style.outline='none';
                });

            b.style.outline='2px solid #ffd447';
            b.style.outlineOffset='2px';
        });

        palette.appendChild(b);
    });
}

$('modalCancel').addEventListener(
    'click',
    closeElementModal
);

$('elementModal').addEventListener(
    'click',
    event=>{
        if(event.target===$('elementModal')){
            closeElementModal();
        }
    }
);

$('modalSave').addEventListener('click',()=>{
    const e=getElement(state.modalId);

    if(!e){
        closeElementModal();
        return;
    }

    const d=duration() || state.project.videoDuration || 0;

    const start=Number($('elementStart').value);
    const end=Number($('elementEnd').value);

    if(!Number.isFinite(start) ||
       !Number.isFinite(end) ||
       start<0 ||
       end<=start ||
       (d && end>d)){

        message(
            `開始・終了時間が不正です。\n` +
            `0.00 ～ ${fmt(d)} の範囲で、終了は開始より後にしてください。`
        );

        return;
    }

    e.start=start;
    e.end=end;
    e.text=$('elementText').value;
    e.color=$('elementColor').value;
    e.borderWidth=
        clamp(
            Number($('elementBorderWidth').value)||0,
            0,
            20
        );

    e.fontSize=
        clamp(
            Number($('elementFontSize').value)||24,
            8,
            200
        );

    e.fontWeight=
        Number($('elementFontWeight').value)||600;

    e.borderStyle=
        $('elementBorderStyle').value;

    markDirty();

    closeElementModal();
    renderAll();
});

/* =========================================================
   Video / timeline
========================================================= */

$('playToggle').addEventListener('click',()=>{
    const video=$('recordedVideo');

    if(!video.src || duration()<=0){
        message('先に動画を読み込んでください。');
        return;
    }

    if(video.paused){
        video.play().catch(err=>{
            console.error(err);
            message('動画を再生できませんでした。');
        });
    }else{
        video.pause();
    }
});

$('recordedVideo').addEventListener('play',()=>{
    $('playToggle').textContent='❚❚';
});

$('recordedVideo').addEventListener('pause',()=>{
    $('playToggle').textContent='▶';
});

$('recordedVideo').addEventListener('ended',()=>{
    $('playToggle').textContent='▶';
});

$('seek').addEventListener('input',event=>{
    const t=Number(event.target.value)||0;

    $('recordedVideo').currentTime=t;

    updatePlayhead();
    renderObjects();
    renderConnectors();
});

$('recordedVideo').addEventListener('timeupdate',()=>{
    processSkip();
    updatePlayhead();
    renderObjects();
    renderConnectors();
});

$('recordedVideo').addEventListener('resize',()=>{
    renderConnectors();
});

/* =========================================================
   Skip
========================================================= */

function processSkip(){
    if(state.skipLock || !state.project) return;

    const t=now();

    const skip=
        state.project.elements.find(e=>
            e.type==='skip' &&
            t>=e.start &&
            t<e.end
        );

    if(!skip) return;

    state.skipLock=true;

    $('recordedVideo').currentTime=
        Math.min(skip.end,duration());

    requestAnimationFrame(()=>{
        state.skipLock=false;
    });
}

/* =========================================================
   Timeline zoom
========================================================= */

const zoomValues=[0.5,1,2,5,10,30];

$('timelineZoom').addEventListener('change',event=>{
    state.zoomStep=Number(event.target.value)||1;
    renderTimeline();
});

function changeZoom(dir){
    const current=
        zoomValues.indexOf(
            Number($('timelineZoom').value)
        );

    const next=clamp(
        current+dir,
        0,
        zoomValues.length-1
    );

    $('timelineZoom').value=
        String(zoomValues[next]);

    state.zoomStep=zoomValues[next];

    renderTimeline();
}

$('zoomOut').addEventListener(
    'click',
    ()=>changeZoom(-1)
);

$('zoomIn').addEventListener(
    'click',
    ()=>changeZoom(1)
);

/* =========================================================
   Video context menu
========================================================= */

$('videoArea').addEventListener(
    'contextmenu',
    event=>{
        event.preventDefault();

        if(event.target.closest('.edit-object')){
            return;
        }

        if(event.target.closest('.connector-hit')){
            return;
        }

        if(!state.project || !duration()){
            message('先に動画を読み込んでください。');
            return;
        }

        state.context={type:'empty'};

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

/* =========================================================
   Timeline context menu
========================================================= */

$('timeline').addEventListener(
    'contextmenu',
    event=>{
        event.preventDefault();

        const item=
            event.target.closest('.track-item');

        if(item){
            const id=item.dataset.id;

            if(id){
                selectElement(id);

                state.context={
                    type:'element',
                    id
                };

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }

            return;
        }

        if(!state.project || !duration()) return;

        state.context={type:'empty'};

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

/* =========================================================
   Global pointer
========================================================= */

window.addEventListener('pointermove',event=>{
    if(state.drag){
        moveElementPointer(event);
    }

    if(state.timelineDrag){
        moveTimeline(event);
    }
});

window.addEventListener('pointerup',()=>{
    endElementPointer();
    endTimelineDrag();
});

document.addEventListener('click',event=>{
    if(!event.target.closest('#contextMenu')){
        hideContextMenu();
    }
});

/* =========================================================
   Save
========================================================= */

async function saveLocal(){
    if(!state.project){
        throw new Error('プロジェクトがありません');
    }

    /*
     * Blobを含んだままIndexedDBへ保存。
     * JSON.stringifyではBlobを保存できないのでIndexedDBを使用。
     */
    await idbPut(state.project);

    state.dirty=false;
    $('editorStatus').textContent='ローカル保存済み';

    await renderProjectList();
}

async function saveServer(){
    if(!state.project) return;

    /*
     * videoBlobはサーバーJSONには送らない。
     * サーバー側には編集データのみ保存。
     */
    const copy={
        ...state.project,
        videoBlob:null
    };

    const response=await fetch('?api=save',{
        method:'POST',
        headers:{
            'Content-Type':'application/json'
        },
        body:JSON.stringify(copy)
    });

    const result=await response.json();

    if(!response.ok || !result.ok){
        const error=new Error(
            result.message || 'サーバー保存に失敗しました。'
        );

        error.limit=!!result.limit;

        throw error;
    }

    state.project.projectId=result.projectId;
    state.project.savedAt=result.savedAt;

    state.dirty=false;
    $('editorStatus').textContent='サーバー保存済み';

    await renderProjectList();
}

async function saveProject(){
    if(!state.project){
        message('プロジェクトがありません。');
        return;
    }

    if(!state.project.videoDuration){
        message('先に動画を読み込んでください。');
        return;
    }

    /*
     * まずブラウザへ保存。
     * サーバー保存にも成功すればサーバー側を正式保存先にする。
     */
    try{
        await saveLocal();
    }catch(err){
        console.error(err);
        message('ブラウザへの保存に失敗しました。');
        return;
    }

    try{
        await saveServer();

        message(
            '保存しました。\n' +
            '編集データはサーバーとブラウザの両方に保存されています。',
            true
        );

    }catch(err){
        console.warn(err);

        if(err.limit){
            message(
                'サーバー保存上限に達したため、ローカルに保存しました。',
                true
            );
        }else{
            message(
                'サーバーには保存できませんでしたが、ブラウザには保存されています。',
                true
            );
        }
    }
}

/* =========================================================
   Export JSON
========================================================= */

function exportProject(){
    if(!state.project){
        message('プロジェクトがありません。');
        return;
    }

    const exportData={
        ...state.project,
        videoBlob:null
    };

    const blob=new Blob(
        [JSON.stringify(exportData,null,2)],
        {type:'application/json'}
    );

    const url=URL.createObjectURL(blob);

    const a=document.createElement('a');

    a.href=url;
    a.download=
        (state.project.name || 'video-project')
        .replace(/[\\/:*?"<>|]/g,'_') +
        '.json';

    document.body.appendChild(a);
    a.click();
    a.remove();

    URL.revokeObjectURL(url);

    message(
        '編集データを書き出しました。\n' +
        'これはMP4ではなく、編集内容を保存したJSONです。',
        true
    );
}

/* =========================================================
   Import project
========================================================= */

async function importProjectFile(file){
    if(!file) return;

    try{
        const text=await file.text();
        const project=JSON.parse(text);

        if(!project || typeof project!=='object'){
            throw new Error('不正なプロジェクト');
        }

        project.projectId =
            project.projectId || uid('project');

        project.name =
            project.name || '読み込んだ編集';

        project.elements =
            Array.isArray(project.elements)
                ? project.elements
                : [];

        project.connections =
            Array.isArray(project.connections)
                ? project.connections
                : [];

        project.videoBlob=null;

        state.project=project;
        state.selectedId=null;
        state.selectedType=null;
        state.multiSelected=[];
        state.dirty=false;

        $('editorProjectName').value=
            project.name;

        showEditor();
        setEditorLocked(true);
        renderAll();

        /*
         * JSONには動画本体を含めない。
         * 編集開始には元動画を再度選択する必要がある。
         */
        message(
            '編集データを読み込みました。\n' +
            '続けて元の動画ファイルを読み込んでください。',
            true
        );

    }catch(err){
        console.error(err);
        message('編集データを読み込めませんでした。');
    }
}

/* =========================================================
   Project list
========================================================= */

async function renderProjectList(){
    const list=$('projectList');
    list.innerHTML='';

    let local=[];

    try{
        if(state.db){
            local=await idbGetAll();
        }
    }catch(err){
        console.warn(err);
    }

    let server=[];

    try{
        const response=await fetch('?api=list',{
            cache:'no-store'
        });

        const result=await response.json();

        if(result.ok){
            server=result.projects || [];
        }
    }catch(err){
        console.warn(err);
    }

    const items=[
        ...server.map(p=>({
            ...p,
            storage:'server'
        })),
        ...local
            .filter(p=>
                !server.some(s=>
                    s.projectId===p.projectId
                )
            )
            .map(p=>({
                projectId:p.projectId,
                name:p.name,
                videoName:p.videoName,
                videoDuration:p.videoDuration,
                savedAt:p.savedAt,
                storage:'local'
            }))
    ];

    if(!items.length){
        list.innerHTML=
            '<p style="color:#888;font-size:12px">保存済み編集はありません。</p>';
        return;
    }

    items.forEach(p=>{
        const row=document.createElement('div');
        row.className='project';

        const info=document.createElement('div');
        info.className='project-info';

        const name=document.createElement('div');
        name.className='project-name';
        name.textContent=p.name || '名称未設定';

        const meta=document.createElement('div');
        meta.className='project-meta';

        meta.textContent=
            `${p.storage==='server'?'サーバー':'このブラウザ'} / ` +
            `${p.videoName || '動画未設定'} / ` +
            `${fmt(p.videoDuration || 0)}`;

        info.append(name,meta);

        const actions=document.createElement('div');
        actions.className='project-actions';

        const open=document.createElement('button');
        open.textContent='開く';

        open.addEventListener('click',async()=>{
            try{
                let project=null;

                if(p.storage==='local'){
                    project=await idbGet(p.projectId);
                }else{
                    const response=
                        await fetch(
                            '?api=load&id='+
                            encodeURIComponent(p.projectId),
                            {cache:'no-store'}
                        );

                    const result=await response.json();

                    if(!result.ok){
                        throw new Error(result.message);
                    }

                    project=result.project;

                    /*
                     * サーバーから読み込んだ編集データを
                     * ローカルにも保持。
                     */
                    await idbPut(project);
                }

                if(!project) throw new Error('プロジェクトがありません');

                state.project=project;
                state.selectedId=null;
                state.selectedType=null;
                state.multiSelected=[];
                state.dirty=false;

                $('editorProjectName').value=
                    project.name || '';

                showEditor();
                setEditorLocked(true);
                renderAll();

                message(
                    '編集データを開きました。\n' +
                    '元の動画を読み込むと編集できます。',
                    true
                );

            }catch(err){
                console.error(err);
                message(
                    err.message ||
                    'プロジェクトを開けませんでした。'
                );
            }
        });

        actions.appendChild(open);

        const del=document.createElement('button');
        del.className='danger';
        del.textContent='削除';

        del.addEventListener('click',async()=>{
            if(!confirm('この編集データを削除しますか？')){
                return;
            }

            try{
                if(p.storage==='local'){
                    await idbDelete(p.projectId);
                }else{
                    await fetch('?api=delete',{
                        method:'POST',
                        headers:{
                            'Content-Type':'application/json'
                        },
                        body:JSON.stringify({
                            projectId:p.projectId
                        })
                    });
                }

                await renderProjectList();

            }catch(err){
                console.error(err);
                message('削除できませんでした。');
            }
        });

        actions.appendChild(del);

        row.append(info,actions);
        list.appendChild(row);
    });
}

/* =========================================================
   Header / navigation
========================================================= */

$('newProject').addEventListener('click',()=>{
    newProject();
});

$('openVideo').addEventListener('click',()=>{
    if(!state.project) newProject();
    $('videoFile').click();
});

$('videoFile').addEventListener('change',event=>{
    handleVideo(event.target.files[0]);
    event.target.value='';
});

$('loadVideoButton').addEventListener(
    'click',
    ()=>$('editorVideoFile').click()
);

$('editorVideoFile').addEventListener(
    'change',
    event=>{
        handleVideo(event.target.files[0]);
        event.target.value='';
    }
);

$('importProject').addEventListener(
    'click',
    ()=>$('projectFile').click()
);

$('projectFile').addEventListener(
    'change',
    event=>{
        importProjectFile(event.target.files[0]);
        event.target.value='';
    }
);

$('backHome').addEventListener('click',()=>{
    if(
        state.dirty &&
        !confirm(
            '保存していない変更があります。\n戻りますか？'
        )
    ){
        return;
    }

    showHome();
});

$('saveProject').addEventListener(
    'click',
    saveProject
);

$('exportProject').addEventListener(
    'click',
    exportProject
);

$('editorProjectName').addEventListener(
    'input',
    ()=>{
        if(state.project){
            state.project.name=
                $('editorProjectName').value;

            markDirty();
        }
    }
);

/* =========================================================
   Keyboard
========================================================= */

document.addEventListener('keydown',event=>{
    const tag=
        document.activeElement?.tagName;

    if(event.key==='Escape'){
        hideContextMenu();
        closeElementModal();
        return;
    }

    if(
        event.key==='Delete' &&
        $('elementModal').style.display!=='flex' &&
        tag!=='INPUT' &&
        tag!=='TEXTAREA' &&
        state.selectedId
    ){
        deleteSelected();
        return;
    }

    if(
        event.key===' ' &&
        tag!=='INPUT' &&
        tag!=='TEXTAREA' &&
        state.project &&
        duration()
    ){
        event.preventDefault();
        $('playToggle').click();
    }
});

/* =========================================================
   Resize
========================================================= */

window.addEventListener('resize',()=>{
    renderTimeline();
    renderConnectors();
});

/* =========================================================
   Render all
========================================================= */

function renderAll(){
    renderObjects();
    renderConnectors();
    renderTimeline();
    updatePlayhead();
}

/* =========================================================
   Init
========================================================= */

(async function init(){

    try{
        await openDB();
    }catch(err){
        console.warn('IndexedDB unavailable:',err);
    }

    $('status').textContent=
        '動画を読み込んで編集を開始してください';

    await renderProjectList();

})();
</script>

</body>
</html>

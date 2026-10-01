<?php
declare(strict_types=1);

/*
 * 動画直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * サーバー保存:
 *   ./data/projects/*.json
 *
 * ブラウザ側:
 *   IndexedDB
 *     - 動画本体
 *     - ローカルプロジェクト
 *
 * 編集データ:
 *   テキスト
 *   強調枠
 *   スキップ
 *   要素間接続
 *
 * 「書き出し」は編集プロジェクトJSON。
 * MP4再エンコードはFFmpeg等の別処理が必要。
 */

const APP_VERSION = 20;
const SERVER_PROJECT_LIMIT = 20;

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

function ensureProjectDir(): bool
{
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true)) {
        return false;
    }

    if (!is_dir(PROJECT_DIR) && !@mkdir(PROJECT_DIR, 0775, true)) {
        return false;
    }

    return is_dir(PROJECT_DIR) && is_writable(PROJECT_DIR);
}

function validProjectId(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{8,100}$/', $id);
}

function projectPath(string $id): string
{
    return PROJECT_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

function readProjects(): array
{
    if (!is_dir(PROJECT_DIR)) {
        return [];
    }

    $items = [];

    foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $json = @file_get_contents($file);
        $data = json_decode($json ?: '', true);

        if (is_array($data) && isset($data['projectId'])) {
            $items[] = $data;
        }
    }

    usort(
        $items,
        static fn(array $a, array $b): int =>
            strcmp(
                (string)($b['savedAt'] ?? ''),
                (string)($a['savedAt'] ?? '')
            )
    );

    return $items;
}

function projectSummary(array $p): array
{
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
            'serverLimit' => SERVER_PROJECT_LIMIT,
            'serverCount' => count(readProjects()),
            'php' => PHP_VERSION
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map(
                'projectSummary',
                readProjects()
            ),
            'limit' => SERVER_PROJECT_LIMIT
        ]);
    }

    if ($api === 'load') {
        $id = (string)($_GET['id'] ?? '');

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $file = projectPath($id);

        if (!is_file($file)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトが見つかりません。'
            ], 404);
        }

        $project = json_decode(
            @file_get_contents($file) ?: '',
            true
        );

        if (!is_array($project)) {
            jsonResponse([
                'ok' => false,
                'message' => '保存データが壊れています。'
            ], 500);
        }

        jsonResponse([
            'ok' => true,
            'project' => $project
        ]);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse([
                'ok' => false,
                'message' => 'POST only'
            ], 405);
        }

        if (!ensureProjectDir()) {
            jsonResponse([
                'ok' => false,
                'message' => 'サーバー側の保存先に書き込めません。'
            ], 500);
        }

        $project = json_decode(
            file_get_contents('php://input') ?: '',
            true
        );

        if (!is_array($project)) {
            jsonResponse([
                'ok' => false,
                'message' => 'JSONが不正です。'
            ], 400);
        }

        $id = trim((string)($project['projectId'] ?? ''));

        if ($id === '') {
            $id = 'project-' . bin2hex(random_bytes(8));
        }

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $name = trim((string)($project['name'] ?? ''));

        if ($name === '') {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクト名を入力してください。'
            ], 422);
        }

        if (mb_strlen($name) > 120) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクト名は120文字以内です。'
            ], 422);
        }

        $file = projectPath($id);
        $existing = is_file($file);

        if (!$existing && count(readProjects()) >= SERVER_PROJECT_LIMIT) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' =>
                    'サーバー保存上限に達しています。' .
                    'ローカル保存を使用してください。'
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

        if (
            $json === false ||
            @file_put_contents($file, $json, LOCK_EX) === false
        ) {
            jsonResponse([
                'ok' => false,
                'message' => '保存に失敗しました。'
            ], 500);
        }

        jsonResponse([
            'ok' => true,
            'projectId' => $id,
            'savedAt' => $project['savedAt']
        ]);
    }

    if ($api === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse([
                'ok' => false,
                'message' => 'POST only'
            ], 405);
        }

        $payload = json_decode(
            file_get_contents('php://input') ?: '',
            true
        );

        $id = is_array($payload)
            ? (string)($payload['projectId'] ?? '')
            : '';

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $file = projectPath($id);

        if (is_file($file) && !@unlink($file)) {
            jsonResponse([
                'ok' => false,
                'message' => '削除できませんでした。'
            ], 500);
        }

        jsonResponse(['ok' => true]);
    }

    jsonResponse([
        'ok' => false,
        'message' => 'Unknown API'
    ], 404);
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
    --red:#ef5350;
    --green:#287c4a;
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

button,input,select,textarea{
    font:inherit;
}

button{
    border:1px solid #555d66;
    background:var(--panel3);
    color:#fff;
    border-radius:6px;
    padding:7px 11px;
    cursor:pointer;
}

button:hover:not(:disabled){
    background:#414850;
}

button:disabled{
    opacity:.4;
    cursor:not-allowed;
}

button.primary{background:var(--blue)}
button.success{background:var(--green)}
button.danger{background:#a83b3b}

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
    top:55px;
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

#message.ok{
    background:#287c4a;
}

/* =========================================================
   HEADER
========================================================= */

header{
    height:48px;
    display:flex;
    align-items:center;
    gap:10px;
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

.home-card h2{
    margin:0 0 8px;
}

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

.project-list{
    margin-top:20px;
}

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

.project-info{
    min-width:0;
}

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
    height:44px;
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
    width:min(72vw,1050px);
    height:min(43vh,600px);
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
    stroke-width:12;
    pointer-events:stroke;
    cursor:pointer;
}

/* =========================================================
   TIMELINE
========================================================= */

#timeline{
    flex:0 0 235px;
    min-height:235px;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:6px 10px 8px;
    overflow:hidden;
}

.timeline-control{
    display:flex;
    align-items:center;
    gap:7px;
    height:34px;
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
    margin:2px 0 4px 49px;
}

.timeline-tools label{
    color:#aeb5bd;
    font-size:11px;
}

.timeline-tools select{
    width:100px;
    padding:3px 5px;
    font-size:11px;
}

.timeline-scroll{
    height:158px;
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
    min-width:7px;
    border-radius:3px;
    cursor:grab;
    overflow:visible;
}

.track-item:active{
    cursor:grabbing;
}

.track-item.comment{
    background:#42a5f5;
}

.track-item.box{
    background:#ef5350;
}

.track-item.skip{
    background:var(--orange);
}

.track-item.selected{
    outline:2px solid #fff;
    outline-offset:1px;
}

.track-item.multi-selected{
    outline:2px dashed var(--yellow);
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
    width:10px;
    z-index:3;
    cursor:ew-resize;
}

.trim-handle.left{left:-5px}
.trim-handle.right{right:-5px}

/* 動画と同一の currentTime から描画する再生インデックス */

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

#editorFooter{
    min-height:38px;
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
    width:250px;
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

#contextMenu button:hover{
    background:#3c424a;
}

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
    width:min(540px,94vw);
    max-height:90vh;
    overflow:auto;
    padding:18px;
    background:#1d2126;
    border:1px solid #4c535c;
    border-radius:9px;
    box-shadow:0 20px 60px #000d;
}

.modal h3{
    margin:0 0 15px;
}

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
    background:#0008;
    font-size:15px;
    z-index:1000;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:760px){
    .video-area{
        padding:4px;
    }

    #videoStage{
        width:96vw;
        height:39vh;
    }

    #timeline{
        flex-basis:250px;
    }

    .timeline-scroll{
        height:170px;
    }

    #timeReadout{
        width:105px;
    }

    .editor-top{
        overflow:auto;
    }

    #editorProjectName{
        width:150px;
    }
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
            動画を読み込んだあと、動画画面上で右クリックすると
            テキスト・強調枠・スキップを追加できます。
            要素はドラッグ、サイズ変更、右クリック編集に対応します。
        </p>

        <div class="home-actions">
            <button id="newProjectBtn" class="primary">
                新しい編集を開始
            </button>

            <button id="loadVideoHomeBtn">
                動画を読み込む
            </button>

            <button id="importProjectBtn">
                プロジェクトを読み込む
            </button>

            <button id="exportProjectHomeBtn">
                プロジェクトを書き出す
            </button>
        </div>

        <input
            id="homeVideoInput"
            class="hidden"
            type="file"
            accept="video/*"
        >

        <input
            id="projectImportInput"
            class="hidden"
            type="file"
            accept="application/json,.json"
        >

        <div class="project-list">
            <h3>保存済みプロジェクト</h3>
            <div id="projectList"></div>
        </div>
    </div>
</section>

<section id="editor" class="locked">

    <div class="editor-top">

        <button id="backHomeBtn">
            ← 戻る
        </button>

        <input
            id="editorProjectName"
            type="text"
            placeholder="プロジェクト名"
        >

        <button id="saveProjectBtn" class="success">
            保存
        </button>

        <button id="saveLocalBtn">
            ローカル保存
        </button>

        <button id="exportProjectBtn">
            書き出し
        </button>

        <button id="replaceVideoBtn">
            動画変更
        </button>

        <input
            id="videoInput"
            class="hidden"
            type="file"
            accept="video/*"
        >

        <span id="editorStatus">
            動画未読込
        </span>
    </div>

    <div class="editor-main">

        <div class="video-area">

            <div id="videoStage">

                <video
                    id="recordedVideo"
                    preload="metadata"
                    playsinline
                ></video>

                <svg
                    id="connectors"
                    viewBox="0 0 100 100"
                    preserveAspectRatio="none"
                ></svg>

                <div id="objects"></div>

            </div>

        </div>

        <div id="timeline">

            <div class="timeline-control">

                <button id="playToggle" title="再生 / 停止">
                    ▶
                </button>

                <input
                    id="seek"
                    type="range"
                    min="0"
                    max="0"
                    step="0.01"
                    value="0"
                    disabled
                >

                <span id="timeReadout">
                    00:00.00 / 00:00.00
                </span>

            </div>

            <div class="timeline-tools">

                <label for="timelineScale">
                    タイムスケール
                </label>

                <select id="timelineScale">
                    <option value="0.5">0.5秒</option>
                    <option value="1" selected>1秒</option>
                    <option value="2">2秒</option>
                    <option value="5">5秒</option>
                    <option value="10">10秒</option>
                    <option value="30">30秒</option>
                </select>

                <button id="timelineFitBtn">
                    全体表示
                </button>

            </div>

            <div
                id="timelineScroll"
                class="timeline-scroll"
            >
                <div
                    id="timelineContent"
                    class="timeline-content"
                >

                    <div
                        id="timelineScale"
                        class="timeline-scale"
                    ></div>

                    <div
                        class="timeline-track"
                        data-type="comment"
                    >
                        <span class="timeline-label">
                            テキスト
                        </span>
                        <div id="commentTrack"></div>
                    </div>

                    <div
                        class="timeline-track"
                        data-type="box"
                    >
                        <span class="timeline-label">
                            強調枠
                        </span>
                        <div id="boxTrack"></div>
                    </div>

                    <div
                        class="timeline-track"
                        data-type="skip"
                    >
                        <span class="timeline-label">
                            スキップ
                        </span>
                        <div id="skipTrack"></div>
                    </div>

                    <div id="playhead"></div>

                </div>
            </div>
        </div>
    </div>

    <div id="editorFooter">
        <span id="connectionStatus" class="connection-status">
            要素を1つ選択
        </span>

        <button id="clearSelectionBtn">
            選択解除
        </button>
    </div>

</section>

<div id="contextMenu">

    <button data-action="add-comment">
        ＋ テキストを追加
    </button>

    <button data-action="add-box">
        ＋ 強調枠を追加
    </button>

    <button data-action="add-skip">
        ＋ スキップを追加
    </button>

    <div class="context-separator"></div>

    <button data-action="edit">
        書式・内容を変更
    </button>

    <button data-action="connect">
        選択した2要素を接続
    </button>

    <button data-action="delete">
        削除
    </button>

</div>

<div id="elementModal" class="modal-backdrop">

    <div class="modal">

        <h3 id="modalTitle">
            要素編集
        </h3>

        <div class="form-row">
            <label>種類</label>
            <input id="modalType" type="text" disabled>
        </div>

        <div
            id="modalTextRow"
            class="form-row"
        >
            <label>テキスト</label>
            <textarea id="modalText"></textarea>
        </div>

        <div class="form-row">
            <label>開始秒</label>
            <input
                id="modalStart"
                type="number"
                min="0"
                step="0.01"
            >
        </div>

        <div class="form-row">
            <label>終了秒</label>
            <input
                id="modalEnd"
                type="number"
                min="0"
                step="0.01"
            >
        </div>

        <div class="form-row">
            <label>色</label>
            <div>
                <div id="palette" class="palette"></div>
                <input id="modalColor" type="text">
            </div>
        </div>

        <div class="form-row">
            <label>文字サイズ</label>
            <input
                id="modalFontSize"
                type="number"
                min="8"
                max="200"
                step="1"
            >
        </div>

        <div class="form-row">
            <label>太さ</label>
            <input
                id="modalBorderWidth"
                type="number"
                min="1"
                max="20"
                step="1"
            >
        </div>

        <div class="modal-actions">
            <button id="modalCancelBtn">
                キャンセル
            </button>

            <button
                id="modalSaveBtn"
                class="primary"
            >
                保存
            </button>
        </div>

    </div>
</div>

<script>
(() => {
'use strict';

/* =========================================================
   基本
========================================================= */

const $ = id => document.getElementById(id);

const clamp = (v,min,max) =>
    Math.max(min,Math.min(max,v));

const uid = prefix =>
    prefix + '-' +
    Date.now().toString(36) + '-' +
    Math.random().toString(36).slice(2,9);

const fmt = value => {
    value = Number(value) || 0;

    const m = Math.floor(value / 60);
    const s = value % 60;

    return (
        String(m).padStart(2,'0') +
        ':' +
        s.toFixed(2).padStart(5,'0')
    );
};

const typeLabel = {
    comment:'テキスト',
    box:'強調枠',
    skip:'スキップ'
};

const paletteColors = [
    '#ffffff',
    '#ff5252',
    '#ff9800',
    '#ffd740',
    '#69f0ae',
    '#40c4ff',
    '#448aff',
    '#7c4dff',
    '#e040fb',
    '#ff4081',
    '#00bcd4',
    '#212121'
];

/* =========================================================
   State
========================================================= */

const state = {
    project:null,
    videoUrl:'',
    videoLoaded:false,

    selectedIds:[],
    contextElementId:null,
    contextX:0,
    contextY:0,

    dragging:null,
    resizing:null,
    timelineDrag:null,

    modalMode:'edit',
    modalElementId:null,

    db:null,

    timelineStep:1,

    skipLock:false,

    connectorTemp:null
};

/* =========================================================
   IndexedDB
========================================================= */

const DB_NAME = 'direct-video-editor';
const DB_VERSION = 2;

function openDB(){

    return new Promise((resolve,reject)=>{

        const req = indexedDB.open(
            DB_NAME,
            DB_VERSION
        );

        req.onupgradeneeded = event => {

            const db = event.target.result;

            if(!db.objectStoreNames.contains('videos')){
                db.createObjectStore(
                    'videos',
                    {keyPath:'projectId'}
                );
            }

            if(!db.objectStoreNames.contains('projects')){
                db.createObjectStore(
                    'projects',
                    {keyPath:'projectId'}
                );
            }
        };

        req.onsuccess = () => {
            state.db = req.result;
            resolve(req.result);
        };

        req.onerror = () => {
            reject(req.error);
        };
    });
}

function idbPut(storeName,value){

    return new Promise((resolve,reject)=>{

        if(!state.db){
            reject(new Error('IndexedDB未初期化'));
            return;
        }

        const tx = state.db.transaction(
            storeName,
            'readwrite'
        );

        const store = tx.objectStore(storeName);

        try{
            store.put(value);
        }catch(error){
            reject(error);
            return;
        }

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

function idbGet(storeName,key){

    return new Promise((resolve,reject)=>{

        if(!state.db){
            reject(new Error('IndexedDB未初期化'));
            return;
        }

        const tx = state.db.transaction(
            storeName,
            'readonly'
        );

        const req =
            tx.objectStore(storeName).get(key);

        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error);
    });
}

function idbGetAll(storeName){

    return new Promise((resolve,reject)=>{

        const tx =
            state.db.transaction(
                storeName,
                'readonly'
            );

        const req =
            tx.objectStore(storeName).getAll();

        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => reject(req.error);
    });
}

function idbDelete(storeName,key){

    return new Promise((resolve,reject)=>{

        const tx =
            state.db.transaction(
                storeName,
                'readwrite'
            );

        tx.objectStore(storeName).delete(key);

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

/* =========================================================
   Message
========================================================= */

let messageTimer = null;

function message(text,ok=false){

    const el = $('message');

    el.textContent = text;
    el.classList.toggle('ok',ok);
    el.style.display = 'block';

    clearTimeout(messageTimer);

    messageTimer = setTimeout(()=>{
        el.style.display = 'none';
    },3500);
}

/* =========================================================
   Project
========================================================= */

function emptyProject(){

    return {
        version:20,
        projectId:uid('project'),
        name:'新しい動画編集',
        videoName:'',
        videoDuration:0,
        videoStored:false,
        createdAt:new Date().toISOString(),
        savedAt:'',
        elements:[],
        connectors:[]
    };
}

function getElement(id){

    if(!state.project){
        return null;
    }

    return state.project.elements.find(
        e => e.id === id
    ) || null;
}

function duration(){

    const video = $('recordedVideo');

    return Number.isFinite(video.duration)
        ? video.duration
        : Number(state.project?.videoDuration || 0);
}

function currentTime(){

    return Number(
        $('recordedVideo').currentTime || 0
    );
}

/* =========================================================
   Editor state
========================================================= */

function setEditorLocked(locked){

    $('editor').classList.toggle(
        'locked',
        locked
    );

    $('seek').disabled = locked;
    $('playToggle').disabled = locked;
    $('clearSelectionBtn').disabled = locked;
}

function showEditor(){

    $('home').style.display = 'none';
    $('editor').style.display = 'flex';
}

function showHome(){

    $('editor').style.display = 'none';
    $('home').style.display = 'flex';
}

/* =========================================================
   Project creation
========================================================= */

function createProject(){

    state.project = emptyProject();

    $('editorProjectName').value =
        state.project.name;

    state.selectedIds = [];

    clearVideo();

    showEditor();

    setEditorLocked(true);

    renderAll();

    $('editorStatus').textContent =
        '動画を読み込んでください';
}

async function startWithVideo(file){

    if(!file){
        return;
    }

    if(!file.type.startsWith('video/')){
        message('動画ファイルを選択してください。');
        return;
    }

    if(!state.project){
        state.project = emptyProject();
    }

    showEditor();

    setEditorLocked(true);

    await loadVideoFile(file);

    if(state.project){
        state.project.videoName = file.name;
        state.project.videoStored = true;
    }

    $('editorProjectName').value =
        state.project.name;

    renderAll();
}

function clearVideo(){

    const video = $('recordedVideo');

    video.pause();
    video.removeAttribute('src');
    video.load();

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
        state.videoUrl = '';
    }

    state.videoLoaded = false;

    $('seek').value = 0;
    $('seek').max = 0;
    $('timeReadout').textContent =
        '00:00.00 / 00:00.00';
}

/* =========================================================
   Video
========================================================= */

async function loadVideoFile(file){

    if(!state.project){
        state.project = emptyProject();
    }

    const video = $('recordedVideo');

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoUrl =
        URL.createObjectURL(file);

    state.videoLoaded = false;

    video.src = state.videoUrl;
    video.load();

    await new Promise((resolve,reject)=>{

        const onLoaded = () => {
            cleanup();
            resolve();
        };

        const onError = () => {
            cleanup();
            reject(
                new Error('動画を読み込めませんでした。')
            );
        };

        const cleanup = () => {
            video.removeEventListener(
                'loadedmetadata',
                onLoaded
            );

            video.removeEventListener(
                'error',
                onError
            );
        };

        video.addEventListener(
            'loadedmetadata',
            onLoaded,
            {once:true}
        );

        video.addEventListener(
            'error',
            onError,
            {once:true}
        );
    });

    state.videoLoaded = true;

    state.project.videoDuration =
        video.duration;

    state.project.videoName =
        file.name;

    state.project.videoStored = true;

    try{

        await idbPut(
            'videos',
            {
                projectId:state.project.projectId,
                name:file.name,
                type:file.type,
                size:file.size,
                blob:file,
                savedAt:new Date().toISOString()
            }
        );

    }catch(error){

        console.error(error);

        message(
            '動画のブラウザ保存に失敗しました。' +
            '編集自体は継続できます。'
        );
    }

    $('seek').max =
        String(video.duration);

    $('seek').value =
        String(video.currentTime);

    $('editorStatus').textContent =
        `${file.name} / ${fmt(video.duration)}`;

    setEditorLocked(false);

    renderAll();

    message('動画の読み込みが完了しました。',true);
}

async function restoreVideo(project){

    if(!project){
        return false;
    }

    const stored =
        await idbGet(
            'videos',
            project.projectId
        );

    if(!stored?.blob){
        return false;
    }

    try{

        await loadVideoFile(
            stored.blob
        );

        return true;

    }catch(error){

        console.error(error);
        return false;
    }
}

/* =========================================================
   Seeking
========================================================= */

function seekTo(time){

    if(!state.videoLoaded){
        return;
    }

    const d = duration();

    if(!d){
        return;
    }

    const t =
        clamp(Number(time) || 0,0,d);

    $('recordedVideo').currentTime = t;

    updatePlayhead();
    renderObjects();
    renderTimelineSelection();
    renderConnectors();
}

function seekToElement(id){

    const element = getElement(id);

    if(!element){
        return;
    }

    seekTo(element.start);
}

/* =========================================================
   Video playback
========================================================= */

async function togglePlay(){

    if(!state.videoLoaded){
        message('先に動画を読み込んでください。');
        return;
    }

    const video = $('recordedVideo');

    if(video.paused){

        try{
            await video.play();
        }catch(error){
            message('動画を再生できませんでした。');
        }

    }else{
        video.pause();
    }
}

function updatePlayButton(){

    const video = $('recordedVideo');

    $('playToggle').textContent =
        video.paused ? '▶' : 'Ⅱ';
}

/* =========================================================
   Skip
========================================================= */

function processSkip(){

    if(
        !state.videoLoaded ||
        state.skipLock
    ){
        return;
    }

    const t = currentTime();

    const skip =
        state.project.elements.find(
            e =>
                e.type === 'skip' &&
                t >= e.start &&
                t < e.end - 0.02
        );

    if(!skip){
        return;
    }

    state.skipLock = true;

    seekTo(skip.end);

    requestAnimationFrame(()=>{
        state.skipLock = false;
    });
}

/* =========================================================
   Element visibility
========================================================= */

function isVisible(element,time){

    return (
        time >= Number(element.start) &&
        time <= Number(element.end)
    );
}

/* =========================================================
   Video objects
========================================================= */

function renderObjects(){

    const container = $('objects');

    container.innerHTML = '';

    if(!state.project || !state.videoLoaded){
        return;
    }

    const t = currentTime();

    state.project.elements.forEach(element=>{

        if(!isVisible(element,t)){
            return;
        }

        const el =
            document.createElement('div');

        el.className =
            'edit-object ' +
            element.type;

        if(state.selectedIds[0] === element.id){
            el.classList.add('selected');
        }

        if(state.selectedIds.includes(element.id)){
            el.classList.add('multi-selected');
        }

        el.dataset.id = element.id;

        const x =
            clamp(Number(element.x) || 0,0,100);

        const y =
            clamp(Number(element.y) || 0,0,100);

        const width =
            clamp(Number(element.width) || 20,1,100);

        const height =
            clamp(Number(element.height) || 15,1,100);

        el.style.left = x + '%';
        el.style.top = y + '%';
        el.style.width = width + '%';
        el.style.height = height + '%';

        el.style.border =
            `${Number(element.borderWidth || 2)}px solid ${element.color}`;

        if(element.type === 'comment'){

            el.textContent =
                element.text || 'テキスト';

            el.style.color =
                element.color;

            el.style.fontSize =
                `${Number(element.fontSize || 28)}px`;

            el.style.background =
                element.background ||
                'rgba(0,0,0,.25)';

        }else if(element.type === 'box'){

            el.style.background =
                element.fill ||
                'transparent';

        }else if(element.type === 'skip'){

            const label =
                document.createElement('span');

            label.className =
                'skip-label';

            label.textContent =
                'SKIP';

            el.appendChild(label);
        }

        addConnectionPoints(el,element);

        const resize =
            document.createElement('div');

        resize.className =
            'resize-handle';

        resize.addEventListener(
            'pointerdown',
            event =>
                beginObjectResize(
                    event,
                    element
                )
        );

        el.appendChild(resize);

        el.addEventListener(
            'pointerdown',
            event =>
                beginObjectDrag(
                    event,
                    element
                )
        );

        el.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                openContextMenu(
                    event.clientX,
                    event.clientY,
                    element.id
                );
            }
        );

        container.appendChild(el);
    });
}

/* =========================================================
   Connection points
========================================================= */

function addConnectionPoints(el,element){

    [
        ['n','cp-n'],
        ['e','cp-e'],
        ['s','cp-s'],
        ['w','cp-w']
    ].forEach(([name,cls])=>{

        const point =
            document.createElement('span');

        point.className =
            `connection-point ${cls}`;

        point.dataset.point = name;

        point.addEventListener(
            'pointerdown',
            event=>{
                event.stopPropagation();
                beginConnection(
                    event,
                    element.id,
                    name
                );
            }
        );

        el.appendChild(point);
    });
}

/* =========================================================
   Object dragging
========================================================= */

function beginObjectDrag(event,element){

    if(event.button !== 0){
        return;
    }

    if(
        event.target.closest('.resize-handle') ||
        event.target.closest('.connection-point')
    ){
        return;
    }

    if(event.shiftKey){

        toggleSelection(element.id);

        event.preventDefault();

        return;
    }

    state.selectedIds = [element.id];

    renderObjects();
    renderTimelineSelection();

    seekToElement(element.id);

    const stage =
        $('videoStage');

    const rect =
        stage.getBoundingClientRect();

    state.dragging = {
        id:element.id,
        startX:event.clientX,
        startY:event.clientY,
        originalX:Number(element.x),
        originalY:Number(element.y),
        rect
    };

    if(
        event.currentTarget &&
        event.currentTarget.setPointerCapture
    ){

        try{
            event.currentTarget.setPointerCapture(
                event.pointerId
            );
        }catch(error){
            /* pointer capture不可でも処理継続 */
        }
    }

    event.preventDefault();
}

function moveObject(event){

    if(!state.dragging){
        return;
    }

    const d =
        state.dragging;

    const element =
        getElement(d.id);

    if(!element){
        return;
    }

    const dx =
        (event.clientX - d.startX) /
        d.rect.width *
        100;

    const dy =
        (event.clientY - d.startY) /
        d.rect.height *
        100;

    element.x =
        clamp(
            d.originalX + dx,
            0,
            100 - Number(element.width)
        );

    element.y =
        clamp(
            d.originalY + dy,
            0,
            100 - Number(element.height)
        );

    renderObjects();
    renderConnectors();
}

function endObjectDrag(){

    if(state.dragging){
        state.dragging = null;
    }
}

/* =========================================================
   Object resizing
========================================================= */

function beginObjectResize(event,element){

    if(event.button !== 0){
        return;
    }

    state.selectedIds = [element.id];

    const rect =
        $('videoStage').getBoundingClientRect();

    state.resizing = {
        id:element.id,
        startX:event.clientX,
        startY:event.clientY,
        originalWidth:Number(element.width),
        originalHeight:Number(element.height),
        rect
    };

    event.stopPropagation();
    event.preventDefault();
}

function moveObjectResize(event){

    if(!state.resizing){
        return;
    }

    const r =
        state.resizing;

    const element =
        getElement(r.id);

    if(!element){
        return;
    }

    const dx =
        (event.clientX - r.startX) /
        r.rect.width *
        100;

    const dy =
        (event.clientY - r.startY) /
        r.rect.height *
        100;

    element.width =
        clamp(
            r.originalWidth + dx,
            2,
            100 - Number(element.x)
        );

    element.height =
        clamp(
            r.originalHeight + dy,
            2,
            100 - Number(element.y)
        );

    renderObjects();
    renderConnectors();
}

function endObjectResize(){

    state.resizing = null;
}

/* =========================================================
   Selection
========================================================= */

function toggleSelection(id){

    const index =
        state.selectedIds.indexOf(id);

    if(index >= 0){

        state.selectedIds.splice(
            index,
            1
        );

    }else{

        if(state.selectedIds.length >= 2){
            state.selectedIds.shift();
        }

        state.selectedIds.push(id);
    }

    updateSelection();
}

function updateSelection(){

    renderObjects();
    renderTimelineSelection();

    const n =
        state.selectedIds.length;

    if(n === 0){

        $('connectionStatus').textContent =
            '要素を選択してください';

    }else if(n === 1){

        $('connectionStatus').textContent =
            '1要素選択：Shift＋クリックで2要素目を選択';

    }else{

        $('connectionStatus').textContent =
            '2要素選択：右クリック →「選択した2要素を接続」';
    }
}

function clearSelection(){

    state.selectedIds = [];

    updateSelection();
}

/* =========================================================
   Context menu
========================================================= */

function openContextMenu(x,y,id=null){

    state.contextElementId = id;
    state.contextX = x;
    state.contextY = y;

    const menu =
        $('contextMenu');

    const hasElement =
        !!id;

    const twoSelected =
        state.selectedIds.length === 2;

    menu.querySelector(
        '[data-action="edit"]'
    ).style.display =
        hasElement ? '' : 'none';

    menu.querySelector(
        '[data-action="delete"]'
    ).style.display =
        hasElement ? '' : 'none';

    menu.querySelector(
        '[data-action="connect"]'
    ).style.display =
        twoSelected ? '' : 'none';

    menu.style.left =
        Math.min(
            x,
            window.innerWidth - 260
        ) + 'px';

    menu.style.top =
        Math.min(
            y,
            window.innerHeight - 260
        ) + 'px';

    menu.style.display = 'block';
}

function closeContextMenu(){

    $('contextMenu').style.display =
        'none';
}

/* =========================================================
   Add element
========================================================= */

function addElement(type){

    if(!state.videoLoaded){
        message('先に動画を読み込んでください。');
        return;
    }

    const d = duration();

    const start =
        clamp(
            currentTime(),
            0,
            Math.max(0,d - 0.1)
        );

    let end =
        Math.min(
            d,
            start + (type === 'skip' ? 2 : 4)
        );

    if(end <= start){
        end = Math.min(d,start + .1);
    }

    const element = {
        id:uid('element'),
        type,
        text:type === 'comment'
            ? 'テキスト'
            : '',
        start,
        end,
        x:25,
        y:25,
        width:type === 'box' ? 35 : 30,
        height:type === 'box' ? 25 : 12,
        color:type === 'skip'
            ? '#ff9800'
            : type === 'box'
                ? '#ef5350'
                : '#ffffff',
        background:
            type === 'comment'
                ? 'rgba(0,0,0,.35)'
                : 'transparent',
        fill:'transparent',
        fontSize:28,
        borderWidth:2
    };

    state.project.elements.push(
        element
    );

    state.selectedIds = [element.id];

    renderAll();

    openElementModal(
        element.id
    );
}

/* =========================================================
   Delete
========================================================= */

function deleteSelected(){

    if(!state.project){
        return;
    }

    const ids =
        new Set(state.selectedIds);

    if(state.contextElementId){
        ids.add(state.contextElementId);
    }

    if(!ids.size){
        return;
    }

    state.project.elements =
        state.project.elements.filter(
            e => !ids.has(e.id)
        );

    state.project.connectors =
        state.project.connectors.filter(
            c =>
                !ids.has(c.from) &&
                !ids.has(c.to)
        );

    state.selectedIds = [];

    closeContextMenu();

    renderAll();
}

/* =========================================================
   Element modal
========================================================= */

function openElementModal(id){

    const element =
        getElement(id);

    if(!element){
        return;
    }

    state.modalElementId = id;
    state.modalMode = 'edit';

    $('modalTitle').textContent =
        `${typeLabel[element.type]}の編集`;

    $('modalType').value =
        typeLabel[element.type];

    $('modalTextRow').style.display =
        element.type === 'comment'
            ? ''
            : 'none';

    $('modalText').value =
        element.text || '';

    $('modalStart').value =
        Number(element.start).toFixed(2);

    $('modalEnd').value =
        Number(element.end).toFixed(2);

    $('modalColor').value =
        element.color || '#ffffff';

    $('modalFontSize').value =
        Number(element.fontSize || 28);

    $('modalBorderWidth').value =
        Number(element.borderWidth || 2);

    renderPalette();

    $('elementModal').style.display =
        'flex';
}

function closeElementModal(){

    $('elementModal').style.display =
        'none';

    state.modalElementId = null;
}

function renderPalette(){

    const palette =
        $('palette');

    palette.innerHTML = '';

    paletteColors.forEach(color=>{

        const button =
            document.createElement('button');

        button.type = 'button';

        button.style.background =
            color;

        button.title = color;

        button.addEventListener(
            'click',
            ()=>{
                $('modalColor').value =
                    color;
            }
        );

        palette.appendChild(button);
    });
}

function saveElementModal(){

    const element =
        getElement(state.modalElementId);

    if(!element){
        closeElementModal();
        return;
    }

    const d = duration();

    let start =
        Number($('modalStart').value);

    let end =
        Number($('modalEnd').value);

    if(!Number.isFinite(start)){
        message('開始秒が不正です。');
        return;
    }

    if(!Number.isFinite(end)){
        message('終了秒が不正です。');
        return;
    }

    start =
        clamp(start,0,d);

    end =
        clamp(end,0,d);

    if(end <= start){
        message('終了時間は開始時間より後にしてください。');
        return;
    }

    element.start = start;
    element.end = end;

    if(element.type === 'comment'){
        element.text =
            $('modalText').value ||
            'テキスト';
    }

    element.color =
        $('modalColor').value ||
        '#ffffff';

    element.fontSize =
        clamp(
            Number($('modalFontSize').value) || 28,
            8,
            200
        );

    element.borderWidth =
        clamp(
            Number($('modalBorderWidth').value) || 2,
            1,
            20
        );

    closeElementModal();

    seekTo(start);

    renderAll();

    message('要素を更新しました。',true);
}

/* =========================================================
   Connectors
========================================================= */

function beginConnection(event,fromId,point){

    event.preventDefault();
    event.stopPropagation();

    state.connectorTemp = {
        from:fromId,
        point,
        x:event.clientX,
        y:event.clientY
    };

    document.body.classList.add(
        'connecting'
    );
}

function createConnector(){

    if(state.selectedIds.length !== 2){
        message(
            '接続する要素を2つ選択してください。'
        );
        return;
    }

    const [from,to] =
        state.selectedIds;

    if(from === to){
        return;
    }

    const exists =
        state.project.connectors.some(
            c =>
                (
                    c.from === from &&
                    c.to === to
                ) ||
                (
                    c.from === to &&
                    c.to === from
                )
        );

    if(exists){
        message('その2要素はすでに接続されています。');
        return;
    }

    const source =
        getElement(from);

    state.project.connectors.push({
        id:uid('connection'),
        from,
        to,
        color:source?.color || '#ffffff',
        width:2
    });

    renderConnectors();

    message('2要素を接続しました。',true);
}

function getPoint(element,side){

    const x =
        Number(element.x);

    const y =
        Number(element.y);

    const w =
        Number(element.width);

    const h =
        Number(element.height);

    switch(side){

        case 'n':
            return {
                x:x+w/2,
                y
            };

        case 'e':
            return {
                x:x+w,
                y:y+h/2
            };

        case 's':
            return {
                x:x+w/2,
                y:y+h
            };

        case 'w':
            return {
                x,
                y:y+h/2
            };

        default:
            return {
                x:x+w/2,
                y:y+h/2
            };
    }
}

function bestConnectionPoints(a,b){

    const ax =
        Number(a.x) +
        Number(a.width)/2;

    const ay =
        Number(a.y) +
        Number(a.height)/2;

    const bx =
        Number(b.x) +
        Number(b.width)/2;

    const by =
        Number(b.y) +
        Number(b.height)/2;

    const dx = bx - ax;
    const dy = by - ay;

    if(Math.abs(dx) >= Math.abs(dy)){
        return dx >= 0
            ? ['e','w']
            : ['w','e'];
    }

    return dy >= 0
        ? ['s','n']
        : ['n','s'];
}

function renderConnectors(){

    const svg =
        $('connectors');

    svg.innerHTML = '';

    if(
        !state.project ||
        !state.videoLoaded
    ){
        return;
    }

    const width =
        $('videoStage').clientWidth;

    const height =
        $('videoStage').clientHeight;

    if(!width || !height){
        return;
    }

    svg.setAttribute(
        'viewBox',
        `0 0 ${width} ${height}`
    );

    const ns =
        'http://www.w3.org/2000/svg';

    state.project.connectors.forEach(connection=>{

        const a =
            getElement(connection.from);

        const b =
            getElement(connection.to);

        if(!a || !b){
            return;
        }

        if(
            !isVisible(a,currentTime()) ||
            !isVisible(b,currentTime())
        ){
            return;
        }

        const [sideA,sideB] =
            bestConnectionPoints(a,b);

        const p1 =
            getPoint(a,sideA);

        const p2 =
            getPoint(b,sideB);

        const x1 =
            p1.x / 100 * width;

        const y1 =
            p1.y / 100 * height;

        const x2 =
            p2.x / 100 * width;

        const y2 =
            p2.y / 100 * height;

        const path =
            document.createElementNS(
                ns,
                'path'
            );

        const dx =
            Math.abs(x2-x1) * .45;

        let d;

        if(sideA === 'e' || sideA === 'w'){
            d =
                `M ${x1} ${y1} ` +
                `C ${x1 + (sideA === 'e' ? dx : -dx)} ${y1}, ` +
                `${x2 + (sideB === 'w' ? -dx : dx)} ${y2}, ` +
                `${x2} ${y2}`;
        }else{
            const dy =
                Math.abs(y2-y1) * .45;

            d =
                `M ${x1} ${y1} ` +
                `C ${x1} ${y1 + (sideA === 's' ? dy : -dy)}, ` +
                `${x2} ${y2 + (sideB === 'n' ? -dy : dy)}, ` +
                `${x2} ${y2}`;
        }

        path.setAttribute('d',d);
        path.setAttribute(
            'stroke',
            connection.color || '#fff'
        );
        path.setAttribute(
            'stroke-width',
            String(
                Math.max(
                    1,
                    Number(connection.width || 2)
                )
            )
        );
        path.classList.add('connector');

        svg.appendChild(path);

        const hit =
            document.createElementNS(
                ns,
                'path'
            );

        hit.setAttribute('d',d);
        hit.classList.add('connector-hit');

        hit.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();

                state.selectedIds = [
                    connection.from,
                    connection.to
                ];

                updateSelection();

                openContextMenu(
                    event.clientX,
                    event.clientY,
                    null
                );
            }
        );

        svg.appendChild(hit);
    });
}

/* =========================================================
   Timeline
========================================================= */

function timelineWidth(){

    const d = duration();

    if(!d){
        return 600;
    }

    const step =
        Number(state.timelineStep || 1);

    const pixelsPerSecond =
        55 / step;

    return Math.max(
        600,
        d * pixelsPerSecond
    );
}

function setTimelineWidth(){

    const width =
        timelineWidth();

    $('timelineContent').style.width =
        width + 'px';
}

function renderTimeline(){

    if(!state.project){
        return;
    }

    setTimelineWidth();

    renderTimelineScale();

    const tracks = {
        comment:$('commentTrack'),
        box:$('boxTrack'),
        skip:$('skipTrack')
    };

    Object.values(tracks).forEach(
        track => track.innerHTML = ''
    );

    const d =
        duration();

    if(!d){
        updatePlayhead();
        return;
    }

    state.project.elements.forEach(element=>{

        const track =
            tracks[element.type];

        if(!track){
            return;
        }

        const item =
            document.createElement('div');

        item.className =
            `track-item ${element.type}`;

        if(state.selectedIds[0] === element.id){
            item.classList.add('selected');
        }

        if(state.selectedIds.includes(element.id)){
            item.classList.add('multi-selected');
        }

        const left =
            Number(element.start) / d * 100;

        const width =
            (
                Number(element.end) -
                Number(element.start)
            ) / d * 100;

        item.style.left =
            left + '%';

        item.style.width =
            Math.max(width,.3) + '%';

        item.dataset.id =
            element.id;

        const startLabel =
            document.createElement('span');

        startLabel.className =
            'track-time';

        startLabel.textContent =
            fmt(element.start);

        item.appendChild(startLabel);

        const endLabel =
            document.createElement('span');

        endLabel.className =
            'track-end-time';

        endLabel.textContent =
            fmt(element.end);

        item.appendChild(endLabel);

        const name =
            document.createElement('span');

        name.className =
            'track-name';

        name.textContent =
            element.type === 'comment'
                ? element.text || 'テキスト'
                : typeLabel[element.type];

        item.appendChild(name);

        const leftHandle =
            document.createElement('span');

        leftHandle.className =
            'trim-handle left';

        const rightHandle =
            document.createElement('span');

        rightHandle.className =
            'trim-handle right';

        item.appendChild(leftHandle);
        item.appendChild(rightHandle);

        item.addEventListener(
            'pointerdown',
            event =>
                beginTimelinePointer(
                    event,
                    element
                )
        );

        item.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                openContextMenu(
                    event.clientX,
                    event.clientY,
                    element.id
                );
            }
        );

        track.appendChild(item);
    });

    updatePlayhead();
}

function renderTimelineScale(){

    const scale =
        $('timelineScale');

    scale.innerHTML = '';

    const d =
        duration();

    if(!d){
        return;
    }

    const step =
        Number(state.timelineStep || 1);

    const count =
        Math.ceil(d / step);

    for(let i=0;i<=count;i++){

        const t =
            Math.min(i * step,d);

        const span =
            document.createElement('span');

        span.style.left =
            `${t/d*100}%`;

        span.textContent =
            fmt(t);

        scale.appendChild(span);
    }
}

function beginTimelinePointer(event,element){

    if(event.button !== 0){
        return;
    }

    const handle =
        event.target.closest('.trim-handle');

    /*
     * Shift+クリックは複数選択。
     * この時点でドラッグ状態を作らない。
     */
    if(event.shiftKey && !handle){

        event.preventDefault();
        event.stopPropagation();

        toggleSelection(element.id);

        return;
    }

    /*
     * 通常クリック:
     * 選択して要素開始位置へ移動。
     */
    if(!handle){

        state.selectedIds = [element.id];

        updateSelection();

        seekToElement(element.id);

        return;
    }

    /*
     * 端をドラッグすると開始/終了時間変更。
     */
    const rect =
        event.currentTarget.getBoundingClientRect();

    state.timelineDrag = {
        id:element.id,
        mode:
            handle.classList.contains('left')
                ? 'left'
                : 'right',
        rect
    };

    state.selectedIds = [element.id];

    updateSelection();

    event.preventDefault();
    event.stopPropagation();
}

function moveTimeline(event){

    if(!state.timelineDrag){
        return;
    }

    const d =
        duration();

    if(!d){
        return;
    }

    const item =
        state.timelineDrag;

    const element =
        getElement(item.id);

    if(!element){
        return;
    }

    const contentRect =
        $('timelineContent')
            .getBoundingClientRect();

    const x =
        clamp(
            event.clientX -
            contentRect.left,
            0,
            contentRect.width
        );

    const t =
        clamp(
            x / contentRect.width * d,
            0,
            d
        );

    if(item.mode === 'left'){

        element.start =
            Math.min(
                t,
                Number(element.end) - .05
            );

    }else{

        element.end =
            Math.max(
                t,
                Number(element.start) + .05
            );
    }

    renderTimeline();
    renderObjects();
    renderConnectors();
}

function endTimeline(){

    state.timelineDrag = null;
}

/* =========================================================
   Playhead
========================================================= */

function updatePlayhead(){

    if(!state.project){
        return;
    }

    const d =
        duration();

    if(!d){
        return;
    }

    const t =
        clamp(
            currentTime(),
            0,
            d
        );

    $('seek').max =
        String(d);

    $('seek').value =
        String(t);

    $('timeReadout').textContent =
        `${fmt(t)} / ${fmt(d)}`;

    const p =
        t / d * 100;

    $('playhead').style.left =
        p + '%';
}

/* =========================================================
   Timeline selection
========================================================= */

function renderTimelineSelection(){

    document
        .querySelectorAll('.track-item')
        .forEach(item=>{

            const id =
                item.dataset.id;

            item.classList.toggle(
                'selected',
                state.selectedIds[0] === id
            );

            item.classList.toggle(
                'multi-selected',
                state.selectedIds.includes(id)
            );
        });
}

/* =========================================================
   Global timeline click
========================================================= */

function timelineBackgroundSeek(event){

    if(
        event.target.closest('.track-item') ||
        event.target.closest('.trim-handle')
    ){
        return;
    }

    if(!state.videoLoaded){
        return;
    }

    const rect =
        $('timelineContent')
            .getBoundingClientRect();

    if(!rect.width){
        return;
    }

    const x =
        clamp(
            event.clientX - rect.left,
            0,
            rect.width
        );

    seekTo(
        x / rect.width * duration()
    );
}

/* =========================================================
   Render all
========================================================= */

function renderAll(){

    renderObjects();
    renderTimeline();
    renderConnectors();
    updateSelection();
    updatePlayhead();
}

/* =========================================================
   Save project
========================================================= */

function projectPayload(){

    if(!state.project){
        throw new Error(
            'プロジェクトがありません。'
        );
    }

    state.project.name =
        $('editorProjectName').value.trim() ||
        '名称未設定';

    return structuredClone(
        state.project
    );
}

async function saveLocal(){

    try{

        const project =
            projectPayload();

        project.savedAt =
            new Date().toISOString();

        await idbPut(
            'projects',
            project
        );

        state.project.savedAt =
            project.savedAt;

        message(
            'このブラウザに保存しました。',
            true
        );

    }catch(error){

        console.error(error);

        message(
            'ローカル保存に失敗しました。'
        );
    }
}

async function saveServer(){

    try{

        const project =
            projectPayload();

        const response =
            await fetch(
                '?api=save',
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/json'
                    },
                    body:JSON.stringify(project)
                }
            );

        const result =
            await response.json();

        if(!result.ok){

            if(result.limit){

                message(
                    'サーバー保存上限です。' +
                    'ローカル保存へ切り替えます。'
                );

                await saveLocal();

                return;
            }

            throw new Error(
                result.message ||
                'サーバー保存に失敗しました。'
            );
        }

        state.project.projectId =
            result.projectId;

        state.project.savedAt =
            result.savedAt;

        await saveLocal();

        message(
            'サーバーに保存しました。',
            true
        );

        loadServerProjects();

    }catch(error){

        console.error(error);

        message(
            error.message ||
            '保存に失敗しました。'
        );
    }
}

/* =========================================================
   Load project list
========================================================= */

async function loadServerProjects(){

    const container =
        $('projectList');

    container.innerHTML =
        '<div style="color:#999">読み込み中…</div>';

    try{

        const response =
            await fetch('?api=list');

        const result =
            await response.json();

        const projects =
            result.ok
                ? result.projects
                : [];

        const local =
            await idbGetAll('projects');

        renderProjectList(
            projects,
            local
        );

    }catch(error){

        console.error(error);

        try{

            const local =
                await idbGetAll('projects');

            renderProjectList(
                [],
                local
            );

        }catch(localError){

            container.innerHTML =
                '<div style="color:#f88">読み込み失敗</div>';
        }
    }
}

function renderProjectList(server,local){

    const container =
        $('projectList');

    container.innerHTML = '';

    const map =
        new Map();

    server.forEach(p=>{
        map.set(p.projectId,{
            ...p,
            storage:'server'
        });
    });

    local.forEach(p=>{
        if(!map.has(p.projectId)){
            map.set(
                p.projectId,
                {
                    ...p,
                    storage:'local'
                }
            );
        }
    });

    const projects =
        [...map.values()].sort(
            (a,b)=>
                String(b.savedAt || '')
                .localeCompare(
                    String(a.savedAt || '')
                )
        );

    if(!projects.length){

        container.innerHTML =
            '<div style="color:#888">' +
            '保存済みプロジェクトはありません。' +
            '</div>';

        return;
    }

    projects.forEach(project=>{

        const row =
            document.createElement('div');

        row.className =
            'project';

        const info =
            document.createElement('div');

        info.className =
            'project-info';

        const name =
            document.createElement('div');

        name.className =
            'project-name';

        name.textContent =
            project.name ||
            '名称未設定';

        const meta =
            document.createElement('div');

        meta.className =
            'project-meta';

        meta.textContent =
            [
                project.videoName || '動画なし',
                project.videoDuration
                    ? fmt(project.videoDuration)
                    : '',
                project.storage === 'local'
                    ? 'ブラウザ'
                    : 'サーバー'
            ]
            .filter(Boolean)
            .join(' / ');

        info.appendChild(name);
        info.appendChild(meta);

        const actions =
            document.createElement('div');

        actions.className =
            'project-actions';

        const load =
            document.createElement('button');

        load.textContent =
            '開く';

        load.addEventListener(
            'click',
            ()=>loadProject(project)
        );

        const del =
            document.createElement('button');

        del.textContent =
            '削除';

        del.className =
            'danger';

        del.addEventListener(
            'click',
            ()=>deleteProject(project)
        );

        actions.appendChild(load);
        actions.appendChild(del);

        row.appendChild(info);
        row.appendChild(actions);

        container.appendChild(row);
    });
}

/* =========================================================
   Load project
========================================================= */

async function loadProject(summary){

    let project = null;

    try{

        if(summary.storage === 'server'){

            const response =
                await fetch(
                    '?api=load&id=' +
                    encodeURIComponent(
                        summary.projectId
                    )
                );

            const result =
                await response.json();

            if(result.ok){
                project = result.project;
            }
        }

        if(!project){

            project =
                await idbGet(
                    'projects',
                    summary.projectId
                );
        }

        if(!project){
            throw new Error(
                'プロジェクトを読み込めませんでした。'
            );
        }

        state.project = project;

        state.selectedIds = [];

        $('editorProjectName').value =
            project.name || '';

        showEditor();

        setEditorLocked(true);

        const restored =
            await restoreVideo(project);

        if(!restored){

            $('editorStatus').textContent =
                '動画を読み込んでください';

            message(
                '編集データは読み込めましたが、' +
                '保存されている動画がありません。' +
                '「動画変更」から動画を指定してください。'
            );

            renderAll();

        }else{

            renderAll();

            message(
                'プロジェクトを読み込みました。',
                true
            );
        }

    }catch(error){

        console.error(error);

        message(
            error.message ||
            '読み込みに失敗しました。'
        );
    }
}

/* =========================================================
   Delete project
========================================================= */

async function deleteProject(project){

    if(!confirm(
        `「${project.name}」を削除しますか？`
    )){
        return;
    }

    try{

        await idbDelete(
            'projects',
            project.projectId
        );

        if(project.storage === 'server'){

            await fetch(
                '?api=delete',
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/json'
                    },
                    body:JSON.stringify({
                        projectId:
                            project.projectId
                    })
                }
            );
        }

        await idbDelete(
            'videos',
            project.projectId
        );

        loadServerProjects();

        message(
            '削除しました。',
            true
        );

    }catch(error){

        console.error(error);

        message(
            '削除に失敗しました。'
        );
    }
}

/* =========================================================
   Export
========================================================= */

function exportProject(){

    if(!state.project){
        message('プロジェクトがありません。');
        return;
    }

    const project =
        projectPayload();

    const blob =
        new Blob(
            [
                JSON.stringify(
                    project,
                    null,
                    2
                )
            ],
            {
                type:'application/json'
            }
        );

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href = url;

    a.download =
        (
            project.name ||
            'video-project'
        )
        .replace(/[\\/:*?"<>|]/g,'_') +
        '.json';

    document.body.appendChild(a);
    a.click();
    a.remove();

    URL.revokeObjectURL(url);

    message(
        '編集プロジェクトを書き出しました。',
        true
    );
}

async function importProjectFile(file){

    if(!file){
        return;
    }

    try{

        const text =
            await file.text();

        const project =
            JSON.parse(text);

        validateProject(project);

        state.project = project;

        state.selectedIds = [];

        $('editorProjectName').value =
            project.name || '';

        showEditor();

        setEditorLocked(true);

        const restored =
            await restoreVideo(project);

        renderAll();

        if(restored){

            message(
                'プロジェクトと動画を読み込みました。',
                true
            );

        }else{

            message(
                'プロジェクトを読み込みました。' +
                '動画は「動画変更」から指定してください。'
            );
        }

    }catch(error){

        console.error(error);

        message(
            'プロジェクトの読み込みに失敗しました。' +
            '\n' +
            (error.message || '')
        );
    }
}

function validateProject(project){

    if(
        !project ||
        typeof project !== 'object'
    ){
        throw new Error(
            'プロジェクト形式が不正です。'
        );
    }

    if(!project.projectId){
        project.projectId =
            uid('project');
    }

    if(!Array.isArray(project.elements)){
        project.elements = [];
    }

    if(!Array.isArray(project.connectors)){
        project.connectors = [];
    }

    project.elements =
        project.elements.filter(
            e =>
                e &&
                e.id &&
                ['comment','box','skip']
                    .includes(e.type)
        );

    project.connectors =
        project.connectors.filter(
            c =>
                c &&
                c.id &&
                getElementFromProject(
                    project,
                    c.from
                ) &&
                getElementFromProject(
                    project,
                    c.to
                )
        );
}

function getElementFromProject(project,id){

    return project.elements.find(
        e => e.id === id
    ) || null;
}

/* =========================================================
   Events
========================================================= */

function bindEvents(){

    $('newProjectBtn')
        .addEventListener(
            'click',
            createProject
        );

    $('loadVideoHomeBtn')
        .addEventListener(
            'click',
            ()=>$('homeVideoInput').click()
        );

    $('homeVideoInput')
        .addEventListener(
            'change',
            async event=>{
                const file =
                    event.target.files?.[0];

                if(!file){
                    return;
                }

                state.project =
                    emptyProject();

                await startWithVideo(file);

                event.target.value = '';
            }
        );

    $('replaceVideoBtn')
        .addEventListener(
            'click',
            ()=>$('videoInput').click()
        );

    $('videoInput')
        .addEventListener(
            'change',
            async event=>{

                const file =
                    event.target.files?.[0];

                if(file){
                    await startWithVideo(file);
                }

                event.target.value = '';
            }
        );

    $('backHomeBtn')
        .addEventListener(
            'click',
            ()=>{
                $('recordedVideo').pause();
                showHome();
                loadServerProjects();
            }
        );

    $('playToggle')
        .addEventListener(
            'click',
            togglePlay
        );

    $('seek')
        .addEventListener(
            'input',
            event=>{
                seekTo(
                    Number(event.target.value)
                );
            }
        );

    $('timelineScroll')
        .addEventListener(
            'pointerdown',
            timelineBackgroundSeek
        );

    $('timelineScale')
        .addEventListener(
            'click',
            event=>{
                if(!state.videoLoaded){
                    return;
                }

                const rect =
                    $('timelineScale')
                        .getBoundingClientRect();

                const x =
                    clamp(
                        event.clientX -
                        rect.left,
                        0,
                        rect.width
                    );

                seekTo(
                    x / rect.width *
                    duration()
                );
            }
        );

    $('timelineScale')
        .addEventListener(
            'pointerdown',
            event=>{
                event.stopPropagation();
            }
        );

    $('timelineStep')
        ?.addEventListener(
            'change',
            event=>{
                state.timelineStep =
                    Number(event.target.value) || 1;

                renderTimeline();
            }
        );

    $('timelineScale')
        .addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
            }
        );

    $('timelineFitBtn')
        .addEventListener(
            'click',
            ()=>{
                state.timelineStep = 1;
                renderTimeline();

                $('timelineScroll')
                    .scrollLeft = 0;
            }
        );

    $('timelineScale')
        .addEventListener(
            'wheel',
            event=>{
                if(!event.ctrlKey){
                    return;
                }

                event.preventDefault();

                const values =
                    [.5,1,2,5,10,30];

                const current =
                    values.indexOf(
                        state.timelineStep
                    );

                const next =
                    clamp(
                        current +
                        (event.deltaY > 0 ? 1 : -1),
                        0,
                        values.length - 1
                    );

                state.timelineStep =
                    values[next];

                const select =
                    $('timelineScale');

                if(select){
                    select.value =
                        String(
                            state.timelineStep
                        );
                }

                renderTimeline();
            },
            {passive:false}
        );

    $('timelineScale')
        .addEventListener(
            'change',
            event=>{
                state.timelineStep =
                    Number(event.target.value) || 1;

                renderTimeline();
            }
        );

    $('saveProjectBtn')
        .addEventListener(
            'click',
            saveServer
        );

    $('saveLocalBtn')
        .addEventListener(
            'click',
            saveLocal
        );

    $('exportProjectBtn')
        .addEventListener(
            'click',
            exportProject
        );

    $('exportProjectHomeBtn')
        .addEventListener(
            'click',
            exportProject
        );

    $('importProjectBtn')
        .addEventListener(
            'click',
            ()=>$('projectImportInput').click()
        );

    $('projectImportInput')
        .addEventListener(
            'change',
            async event=>{

                const file =
                    event.target.files?.[0];

                if(file){
                    await importProjectFile(file);
                }

                event.target.value = '';
            }
        );

    $('clearSelectionBtn')
        .addEventListener(
            'click',
            clearSelection
        );

    $('modalCancelBtn')
        .addEventListener(
            'click',
            closeElementModal
        );

    $('modalSaveBtn')
        .addEventListener(
            'click',
            saveElementModal
        );

    $('elementModal')
        .addEventListener(
            'pointerdown',
            event=>{
                if(
                    event.target ===
                    $('elementModal')
                ){
                    closeElementModal();
                }
            }
        );

    document.addEventListener(
        'contextmenu',
        event=>{

            if(
                event.target.closest(
                    '#videoStage'
                ) ||
                event.target.closest(
                    '.track-item'
                )
            ){
                return;
            }

            event.preventDefault();
        }
    );

    $('videoStage')
        .addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();

                if(!state.videoLoaded){
                    message(
                        '動画を読み込んでから編集してください。'
                    );
                    return;
                }

                const object =
                    event.target.closest(
                        '.edit-object'
                    );

                openContextMenu(
                    event.clientX,
                    event.clientY,
                    object?.dataset.id || null
                );
            }
        );

    $('contextMenu')
        .addEventListener(
            'click',
            event=>{

                const button =
                    event.target.closest(
                        'button[data-action]'
                    );

                if(!button){
                    return;
                }

                const action =
                    button.dataset.action;

                const id =
                    state.contextElementId;

                closeContextMenu();

                if(action === 'add-comment'){
                    addElement('comment');
                }

                if(action === 'add-box'){
                    addElement('box');
                }

                if(action === 'add-skip'){
                    addElement('skip');
                }

                if(action === 'edit'){
                    if(id){
                        openElementModal(id);
                    }
                }

                if(action === 'delete'){
                    deleteSelected();
                }

                if(action === 'connect'){
                    createConnector();
                }
            }
        );

    document.addEventListener(
        'pointerdown',
        event=>{
            if(
                !event.target.closest(
                    '#contextMenu'
                )
            ){
                closeContextMenu();
            }
        }
    );

    document.addEventListener(
        'pointermove',
        event=>{

            if(state.dragging){
                moveObject(event);
            }

            if(state.resizing){
                moveObjectResize(event);
            }

            if(state.timelineDrag){
                moveTimeline(event);
            }
        }
    );

    document.addEventListener(
        'pointerup',
        ()=>{
            endObjectDrag();
            endObjectResize();
            endTimeline();
        }
    );

    document.addEventListener(
        'keydown',
        event=>{

            if(
                event.key === 'Escape'
            ){

                closeContextMenu();
                closeElementModal();

                state.dragging = null;
                state.resizing = null;
                state.timelineDrag = null;

                return;
            }

            if(
                event.key === 'Delete' &&
                state.selectedIds.length &&
                !event.target.matches(
                    'input,textarea,select'
                )
            ){
                deleteSelected();
            }
        }
    );

    $('recordedVideo')
        .addEventListener(
            'loadedmetadata',
            ()=>{
                state.videoLoaded = true;

                state.project.videoDuration =
                    $('recordedVideo').duration;

                setEditorLocked(false);

                renderAll();
            }
        );

    $('recordedVideo')
        .addEventListener(
            'timeupdate',
            ()=>{
                processSkip();
                updatePlayhead();
                renderObjects();
                renderTimelineSelection();
                renderConnectors();
            }
        );

    $('recordedVideo')
        .addEventListener(
            'play',
            updatePlayButton
        );

    $('recordedVideo')
        .addEventListener(
            'pause',
            updatePlayButton
        );

    $('recordedVideo')
        .addEventListener(
            'ended',
            updatePlayButton
        );

    window.addEventListener(
        'resize',
        ()=>{
            renderObjects();
            renderConnectors();
            renderTimeline();
        }
    );
}

/* =========================================================
   Startup
========================================================= */

async function init(){

    try{

        await openDB();

    }catch(error){

        console.error(error);

        message(
            'ブラウザ保存機能を初期化できませんでした。'
        );
    }

    bindEvents();

    renderPalette();

    setEditorLocked(true);

    loadServerProjects();

    try{

        const response =
            await fetch('?api=status');

        const status =
            await response.json();

        if(status.ok){

            $('status').textContent =
                `v${status.version} / ` +
                `サーバー保存 ${status.serverCount}/` +
                `${status.serverLimit}`;
        }

    }catch(error){

        $('status').textContent =
            'ローカル編集モード';
    }
}

init();

})();
</script>

</body>
</html>

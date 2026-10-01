<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 完結版
 *
 * 主な仕様
 * - 動画上へ直接要素追加
 * - テキスト / 強調枠 / スキップ
 * - 移動 / リサイズ
 * - 右クリック編集
 * - 同一動画時間軸上のタイムライン
 * - Shift+クリック複数選択
 * - 2要素の接続
 * - 接続線の接点 / 色 / 太さ / 線種 / 矢印 / 時間編集
 * - 再生位置とタイムラインを完全同期
 * - スキップ区間の自動ジャンプ
 * - サーバーJSON保存
 * - IndexedDBローカル保存
 * - 動画BlobのIndexedDB保存
 * - プロジェクトJSON入出力
 *
 * 注意:
 * ブラウザだけで既存MP4へオーバーレイを焼き込んだ
 * 完成MP4を安定して生成する処理は行わない。
 * 「書き出し」は編集プロジェクトJSON。
 */

const APP_VERSION = 30;
const MAX_SERVER_PROJECTS = 20;

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
    return (bool)preg_match('/^[A-Za-z0-9_-]{8,120}$/', $id);
}

function projectPath(string $id): string
{
    return PROJECT_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

function readServerProjects(): array
{
    if (!is_dir(PROJECT_DIR)) {
        return [];
    }

    $result = [];

    foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $data = json_decode(@file_get_contents($file) ?: '', true);

        if (is_array($data) && isset($data['projectId'])) {
            $result[] = $data;
        }
    }

    usort(
        $result,
        static fn(array $a, array $b): int =>
            strcmp(
                (string)($b['savedAt'] ?? ''),
                (string)($a['savedAt'] ?? '')
            )
    );

    return $result;
}

function projectSummary(array $p): array
{
    return [
        'projectId' => (string)($p['projectId'] ?? ''),
        'name' => (string)($p['name'] ?? '名称未設定'),
        'videoName' => (string)($p['videoName'] ?? ''),
        'savedAt' => (string)($p['savedAt'] ?? ''),
        'version' => (int)($p['version'] ?? 1),
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
            'serverCount' => count(readServerProjects())
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map(
                'projectSummary',
                readServerProjects()
            ),
            'limit' => MAX_SERVER_PROJECTS
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

        $data = json_decode(
            @file_get_contents($file) ?: '',
            true
        );

        if (!is_array($data)) {
            jsonResponse([
                'ok' => false,
                'message' => '保存データが壊れています。'
            ], 500);
        }

        jsonResponse([
            'ok' => true,
            'project' => $data
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
                'message' => 'サーバー保存領域へ書き込めません。'
            ], 500);
        }

        $payload = json_decode(
            file_get_contents('php://input') ?: '',
            true
        );

        if (!is_array($payload)) {
            jsonResponse([
                'ok' => false,
                'message' => 'JSONが不正です。'
            ], 400);
        }

        $id = trim((string)($payload['projectId'] ?? ''));

        if ($id === '') {
            $id = 'project-' . bin2hex(random_bytes(10));
        }

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $name = trim((string)($payload['name'] ?? ''));

        if ($name === '') {
            $name = '名称未設定';
        }

        if (mb_strlen($name) > 120) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクト名は120文字以内です。'
            ], 422);
        }

        $existing = is_file(projectPath($id));
        $projects = readServerProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' => 'サーバー保存上限です。ローカル保存を使用してください。'
            ], 409);
        }

        $payload['version'] = APP_VERSION;
        $payload['projectId'] = $id;
        $payload['name'] = $name;
        $payload['savedAt'] = date('c');

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false) {
            jsonResponse([
                'ok' => false,
                'message' => 'JSON生成に失敗しました。'
            ], 500);
        }

        if (@file_put_contents(
            projectPath($id),
            $json,
            LOCK_EX
        ) === false) {
            jsonResponse([
                'ok' => false,
                'message' => '保存できませんでした。'
            ], 500);
        }

        jsonResponse([
            'ok' => true,
            'projectId' => $id,
            'savedAt' => $payload['savedAt'],
            'version' => APP_VERSION
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
<title>動画上直接編集ツール</title>

<style>
:root{
    --bg:#101216;
    --panel:#1b1e23;
    --panel2:#252a30;
    --panel3:#30353c;
    --border:#414750;
    --text:#f5f7fa;
    --muted:#9ca5af;
    --blue:#1976d2;
    --red:#ef4444;
    --green:#287348;
    --orange:#e38b28;
    --yellow:#ffd447;
}

*{box-sizing:border-box}

html,
body{
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

body{
    overflow:hidden;
}

button,
input,
select,
textarea{
    font:inherit;
}

button{
    border:1px solid #565d66;
    background:#30353c;
    color:#fff;
    border-radius:6px;
    padding:7px 10px;
    cursor:pointer;
}

button:hover:not(:disabled){
    background:#424850;
}

button:disabled{
    opacity:.4;
    cursor:not-allowed;
}

button.primary{background:var(--blue)}
button.success{background:var(--green)}
button.danger{background:#a73535}

input,
select,
textarea{
    width:100%;
    color:#fff;
    background:#252a30;
    border:1px solid #555c65;
    border-radius:5px;
    padding:7px;
}

textarea{
    min-height:90px;
    resize:vertical;
}

input[type=color]{
    height:38px;
    padding:3px;
}

.hidden{
    display:none!important;
}

/* =========================================================
 * Header / Home
 * ======================================================= */

header{
    height:48px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 14px;
    background:#1b1e22;
    border-bottom:1px solid #353a41;
}

header h1{
    margin:0;
    font-size:15px;
}

#status{
    color:#b6bec7;
    font-size:12px;
}

#message{
    position:fixed;
    top:55px;
    left:50%;
    transform:translateX(-50%);
    z-index:10000;
    display:none;
    max-width:90vw;
    padding:10px 16px;
    border-radius:7px;
    background:#983838;
    box-shadow:0 10px 35px #000b;
    white-space:pre-wrap;
}

#message.ok{
    background:#287348;
}

#home{
    height:calc(100vh - 48px);
    display:flex;
    justify-content:center;
    align-items:center;
    padding:20px;
    background:#111317;
}

.home-card{
    width:min(760px,95vw);
    max-height:90vh;
    overflow:auto;
    padding:25px;
    background:#1b1e23;
    border:1px solid var(--border);
    border-radius:10px;
    box-shadow:0 15px 50px #0007;
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
    margin-top:15px;
}

.project{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:11px;
    margin:7px 0;
    border:1px solid #414750;
    border-radius:7px;
}

.project-info{
    min-width:0;
}

.project-name{
    font-weight:600;
}

.project-meta{
    color:#929aa4;
    font-size:11px;
    margin-top:3px;
}

.project-actions{
    display:flex;
    gap:5px;
}

/* =========================================================
 * Editor
 * ======================================================= */

#editor{
    position:fixed;
    inset:0;
    z-index:200;
    display:none;
    flex-direction:column;
    background:#111;
}

.editor-top{
    min-height:48px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
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
    margin-left:auto;
}

.editor-main{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}

/* =========================================================
 * Video
 * ======================================================= */

.video-area{
    flex:0 0 auto;
    height:min(48vh,560px);
    min-height:230px;
    display:flex;
    justify-content:center;
    align-items:center;
    overflow:hidden;
    background:#000;
    position:relative;
}

#videoStage{
    position:relative;
    background:#000;
    line-height:0;
    width:min(76vw,1000px);
    height:min(42vh,500px);
    max-height:calc(100% - 16px);
}

#recordedVideo{
    display:block;
    width:100%;
    height:100%;
    object-fit:contain;
    background:#000;
}

#objects,
#connectors{
    position:absolute;
    inset:0;
}

#connectors{
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
    overflow:visible;
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
}

.edit-object.box{
    background:transparent;
}

.edit-object.skip{
    border-style:dashed!important;
    background:#e38b2818;
}

.skip-label{
    position:absolute;
    left:4px;
    top:4px;
    padding:2px 6px;
    color:#fff;
    background:#c87518;
    border-radius:4px;
    font-size:11px;
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

/* =========================================================
 * Seek bar
 * ======================================================= */

.video-controls{
    position:absolute;
    left:50%;
    bottom:8px;
    transform:translateX(-50%);
    width:min(1000px,90%);
    display:flex;
    align-items:center;
    gap:8px;
    padding:7px 9px;
    border-radius:7px;
    background:#111c;
    backdrop-filter:blur(4px);
    z-index:50;
}

#seek{
    flex:1;
    min-width:0;
}

#videoTime{
    min-width:120px;
    color:#fff;
    font-size:11px;
    font-variant-numeric:tabular-nums;
    text-align:right;
}

/* =========================================================
 * Timeline
 * ======================================================= */

.timeline{
    flex:1 1 auto;
    min-height:270px;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:6px 10px 10px;
    overflow:auto;
}

.timeline-toolbar{
    height:38px;
    display:flex;
    align-items:center;
    gap:6px;
    position:sticky;
    top:0;
    z-index:200;
    background:#191c20;
}

#playToggle{
    width:42px;
    padding:5px;
    font-size:16px;
}

#currentTime{
    color:#d8dde2;
    font-variant-numeric:tabular-nums;
    min-width:145px;
}

#timelineZoom{
    width:110px;
}

.connection-status{
    padding:5px 9px;
    border-radius:5px;
    background:#15181c;
    border:1px solid #414750;
    color:#d9dee4;
    font-size:11px;
}

.timeline-scroll{
    position:relative;
    overflow-x:auto;
    overflow-y:visible;
    padding-bottom:20px;
}

.timeline-content{
    position:relative;
    min-width:800px;
}

.timeline-scale{
    position:relative;
    height:32px;
    color:#8f969f;
    font-size:10px;
    border-bottom:1px solid #444;
}

.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    white-space:nowrap;
}

.timeline-scale::after{
    content:"";
    position:absolute;
    left:0;
    right:0;
    bottom:0;
    height:1px;
    background:#1976d2;
}

.timeline-row{
    display:grid;
    grid-template-columns:70px max-content;
    gap:7px;
    margin-top:18px;
    align-items:center;
    font-size:11px;
    color:#b0b7c0;
}

.timeline-label{
    width:70px;
    white-space:nowrap;
}

.track{
    position:relative;
    height:42px;
    min-width:800px;
    background:
        repeating-linear-gradient(
            to right,
            transparent 0,
            transparent calc(10% - 1px),
            #343a41 calc(10% - 1px),
            #343a41 10%
        ),
        #292d33;
    border-radius:4px;
    border:1px solid #3c4249;
    overflow:visible;
}

.track-item{
    position:absolute;
    height:28px;
    top:6px;
    min-width:12px;
    border-radius:3px;
    cursor:grab;
    z-index:10;
    touch-action:none;
    overflow:visible;
}

.track-item:active{
    cursor:grabbing;
}

.track-item.comment{background:#1976d2}
.track-item.box{background:#ef5350}
.track-item.skip{background:#e38b28}
.track-item.connection{background:#805ad5}

.track-item.selected{
    outline:2px solid #fff;
    outline-offset:1px;
}

.track-item.multi{
    outline:2px dashed #ffd447;
    outline-offset:2px;
}

.track-handle{
    position:absolute;
    top:-4px;
    bottom:-4px;
    width:14px;
    z-index:30;
    cursor:ew-resize;
}

.track-handle.left{
    left:-7px;
}

.track-handle.right{
    right:-7px;
}

.track-item:hover .track-handle{
    background:#fff8;
}

.track-time,
.track-end-time{
    position:absolute;
    top:-17px;
    font-size:9px;
    color:#dfe4e9;
    white-space:nowrap;
    pointer-events:none;
}

.track-time{
    left:0;
}

.track-end-time{
    right:0;
}

.track-name{
    position:absolute;
    left:7px;
    right:7px;
    top:6px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:#fff;
    font-size:9px;
    pointer-events:none;
}

.timeline-playhead{
    position:absolute;
    top:32px;
    bottom:0;
    width:2px;
    background:#ef4444;
    box-shadow:0 0 6px #ef4444;
    z-index:150;
    pointer-events:none;
}

.timeline-playhead::before{
    content:"";
    position:absolute;
    top:-4px;
    left:-5px;
    width:12px;
    height:12px;
    border-radius:50%;
    background:#ef4444;
}

.timeline-seek-layer{
    position:absolute;
    left:0;
    right:0;
    top:0;
    height:32px;
    z-index:5;
    cursor:pointer;
}

/* =========================================================
 * Context menu / modal
 * ======================================================= */

#contextMenu{
    position:fixed;
    z-index:7000;
    display:none;
    width:290px;
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

.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:8000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:#000a;
}

.modal{
    width:min(720px,96vw);
    max-height:92vh;
    overflow:auto;
    padding:17px;
    background:#20242a;
    border:1px solid #555b64;
    border-radius:9px;
}

.modal h3{
    margin:0 0 14px;
}

.form-row{
    display:grid;
    grid-template-columns:125px 1fr;
    gap:10px;
    align-items:center;
    margin:9px 0;
}

.form-row label{
    color:#c7cdd3;
    font-size:12px;
}

.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:15px;
}

.palette{
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:5px;
}

.palette button{
    height:28px;
    padding:0;
    border:2px solid transparent;
}

.palette button.active{
    border-color:#fff;
}

#editor.locked .video-area::after{
    content:"動画を読み込むまで編集できません";
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:16px;
    background:#0008;
    z-index:100;
    pointer-events:none;
}

#editor.locked .video-controls{
    z-index:120;
}

/* =========================================================
 * Footer
 * ======================================================= */

#editorFooter{
    min-height:40px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:7px;
    background:#1b1e22;
    border-top:1px solid #383d44;
}

/* =========================================================
 * Mobile
 * ======================================================= */

@media(max-width:800px){
    .editor-top{
        flex-wrap:wrap;
        min-height:78px;
    }

    #editorProjectName{
        width:170px;
    }

    .video-area{
        height:42vh;
    }

    .timeline{
        min-height:330px;
    }

    .form-row{
        grid-template-columns:1fr;
        gap:4px;
    }
}
</style>
</head>

<body>

<header>
    <h1>動画上直接編集ツール</h1>
    <div id="status">初期化中...</div>
</header>

<div id="message"></div>

<section id="home">
    <div class="home-card">
        <h2>動画編集</h2>
        <p>
            動画を読み込んでから編集を開始します。
            編集内容はプロジェクトとして保存できます。
            動画ファイルそのものはブラウザのIndexedDBへ保存されます。
        </p>

        <div class="home-actions">
            <button id="newProject" class="primary">
                新しい編集を開始
            </button>

            <button id="importProject">
                プロジェクトを読み込む
            </button>

            <input
                id="projectFile"
                type="file"
                accept=".json,application/json"
                class="hidden"
            >
        </div>

        <h3>保存済み編集</h3>
        <div id="projectList">
            読み込み中...
        </div>
    </div>
</section>

<section id="editor">

    <div class="editor-top">
        <button id="backHome">戻る</button>

        <input
            id="editorProjectName"
            value="新しい編集"
            maxlength="120"
        >

        <button id="chooseVideo" class="primary">
            動画を読み込む
        </button>

        <input
            id="videoFile"
            type="file"
            accept="video/*"
            class="hidden"
        >

        <button id="saveProject" class="success">
            保存
        </button>

        <button id="exportProject">
            書き出し
        </button>

        <span id="editorStatus">
            動画未読込
        </span>
    </div>

    <main class="editor-main">

        <section class="video-area" id="videoArea">

            <div id="videoStage">

                <video
                    id="recordedVideo"
                    preload="metadata"
                    playsinline
                ></video>

                <svg id="connectors"></svg>

                <div id="objects"></div>

            </div>

            <div class="video-controls">

                <button id="videoPlay" title="再生 / 停止">
                    ▶
                </button>

                <input
                    id="seek"
                    type="range"
                    min="0"
                    max="0"
                    step="0.001"
                    value="0"
                >

                <span id="videoTime">
                    00:00.000 / 00:00.000
                </span>

            </div>

        </section>

        <section class="timeline">

            <div class="timeline-toolbar">

                <button id="playToggle" title="再生 / 停止">
                    ▶
                </button>

                <span id="currentTime">
                    00:00.000 / 00:00.000
                </span>

                <label>
                    時間軸
                    <select id="timelineZoom">
                        <option value="0.5">0.5x</option>
                        <option value="1" selected>1x</option>
                        <option value="2">2x</option>
                        <option value="3">3x</option>
                        <option value="5">5x</option>
                    </select>
                </label>

                <button id="timelineFit">
                    全体表示
                </button>

                <span id="connectionStatus" class="connection-status">
                    Shift＋クリックで2要素を選択
                </span>

            </div>

            <div class="timeline-scroll" id="timelineScroll">

                <div
                    class="timeline-content"
                    id="timelineContent"
                >

                    <div
                        class="timeline-scale"
                        id="timelineScale"
                    ></div>

                    <div
                        class="timeline-playhead"
                        id="timelinePlayhead"
                    ></div>

                    <div
                        class="timeline-seek-layer"
                        id="timelineSeekLayer"
                    ></div>

                    <div id="timelineTracks"></div>

                </div>

            </div>

        </section>

    </main>

    <footer id="editorFooter">
        <button id="quickText">
            ＋テキスト
        </button>

        <button id="quickBox">
            ＋強調枠
        </button>

        <button id="quickSkip">
            ＋スキップ
        </button>
    </footer>

</section>

<!-- =======================================================
     Context menu
======================================================= -->

<div id="contextMenu">

    <button data-action="add-comment">
        ＋テキストを追加
    </button>

    <button data-action="add-box">
        ＋強調枠を追加
    </button>

    <button data-action="add-skip">
        ＋スキップを追加
    </button>

    <div class="context-separator"></div>

    <button data-action="edit">
        書式・内容を編集
    </button>

    <button data-action="duplicate">
        複製
    </button>

    <button data-action="delete">
        削除
    </button>

    <div class="context-separator"></div>

    <button
        id="contextConnect"
        data-action="connect"
    >
        選択した2要素を接続
    </button>

</div>

<!-- =======================================================
     Element modal
======================================================= -->

<div id="elementModal" class="modal-backdrop">

    <div class="modal">

        <h3 id="elementModalTitle">
            要素編集
        </h3>

        <div class="form-row">
            <label>種類</label>
            <select id="elementType">
                <option value="comment">テキスト</option>
                <option value="box">強調枠</option>
                <option value="skip">スキップ</option>
            </select>
        </div>

        <div class="form-row" id="elementTextRow">
            <label>テキスト</label>
            <textarea id="elementText"></textarea>
        </div>

        <div class="form-row">
            <label>開始</label>
            <input
                id="elementStart"
                type="number"
                min="0"
                step="0.001"
            >
        </div>

        <div class="form-row">
            <label>終了</label>
            <input
                id="elementEnd"
                type="number"
                min="0"
                step="0.001"
            >
        </div>

        <div class="form-row">
            <label>X</label>
            <input
                id="elementX"
                type="number"
                min="0"
                max="100"
                step="0.1"
            >
        </div>

        <div class="form-row">
            <label>Y</label>
            <input
                id="elementY"
                type="number"
                min="0"
                max="100"
                step="0.1"
            >
        </div>

        <div class="form-row">
            <label>幅</label>
            <input
                id="elementW"
                type="number"
                min="1"
                max="100"
                step="0.1"
            >
        </div>

        <div class="form-row">
            <label>高さ</label>
            <input
                id="elementH"
                type="number"
                min="1"
                max="100"
                step="0.1"
            >
        </div>

        <div class="form-row">
            <label>色</label>
            <div class="palette" id="elementPalette"></div>
        </div>

        <div class="form-row">
            <label>文字サイズ</label>
            <input
                id="elementFontSize"
                type="number"
                min="8"
                max="96"
                step="1"
            >
        </div>

        <div class="form-row">
            <label>枠線太さ</label>
            <input
                id="elementBorderWidth"
                type="number"
                min="0"
                max="20"
                step="0.5"
            >
        </div>

        <div class="form-row">
            <label>枠線種類</label>
            <select id="elementBorderStyle">
                <option value="solid">実線</option>
                <option value="dashed">破線</option>
                <option value="dotted">点線</option>
            </select>
        </div>

        <div class="modal-actions">
            <button id="elementCancel">
                キャンセル
            </button>

            <button id="elementApply" class="primary">
                適用
            </button>
        </div>

    </div>

</div>

<!-- =======================================================
     Connection modal
======================================================= -->

<div id="connectionModal" class="modal-backdrop">

    <div class="modal">

        <h3>接続線編集</h3>

        <div class="form-row">
            <label>始点</label>
            <select id="connectionFromPoint">
                <option value="n">上</option>
                <option value="e">右</option>
                <option value="s">下</option>
                <option value="w">左</option>
            </select>
        </div>

        <div class="form-row">
            <label>終点</label>
            <select id="connectionToPoint">
                <option value="n">上</option>
                <option value="e">右</option>
                <option value="s">下</option>
                <option value="w">左</option>
            </select>
        </div>

        <div class="form-row">
            <label>開始</label>
            <input
                id="connectionStart"
                type="number"
                min="0"
                step="0.001"
            >
        </div>

        <div class="form-row">
            <label>終了</label>
            <input
                id="connectionEnd"
                type="number"
                min="0"
                step="0.001"
            >
        </div>

        <div class="form-row">
            <label>色</label>
            <input
                id="connectionColor"
                type="color"
            >
        </div>

        <div class="form-row">
            <label>太さ</label>
            <input
                id="connectionWidth"
                type="number"
                min="0.5"
                max="10"
                step="0.5"
            >
        </div>

        <div class="form-row">
            <label>線種</label>
            <select id="connectionDash">
                <option value="">実線</option>
                <option value="6 4">破線</option>
                <option value="2 4">点線</option>
            </select>
        </div>

        <div class="form-row">
            <label>矢印</label>
            <select id="connectionArrow">
                <option value="1">あり</option>
                <option value="0">なし</option>
            </select>
        </div>

        <div class="form-row">
            <label>曲線</label>
            <input
                id="connectionCurve"
                type="number"
                min="0"
                max="100"
                step="1"
            >
        </div>

        <div class="modal-actions">

            <button id="connectionDelete" class="danger">
                接続削除
            </button>

            <button id="connectionCancel">
                キャンセル
            </button>

            <button id="connectionApply" class="primary">
                適用
            </button>

        </div>

    </div>

</div>

<script>
'use strict';

/* =========================================================
 * PHP -> JavaScript
 * ======================================================= */

const APP_VERSION =
    <?= json_encode(APP_VERSION, JSON_UNESCAPED_UNICODE) ?>;

const MAX_SERVER_PROJECTS =
    <?= json_encode(MAX_SERVER_PROJECTS) ?>;

/* =========================================================
 * Utility
 * ======================================================= */

const $ = id => document.getElementById(id);

const COLORS = [
    '#ffffff',
    '#000000',
    '#ef4444',
    '#f97316',
    '#f59e0b',
    '#eab308',
    '#22c55e',
    '#14b8a6',
    '#06b6d4',
    '#3b82f6',
    '#8b5cf6',
    '#ec4899'
];

function uid(prefix){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,10);
}

function clamp(v,min,max){
    return Math.max(
        min,
        Math.min(max,v)
    );
}

function num(v,fallback=0){
    const n = Number(v);
    return Number.isFinite(n)
        ? n
        : fallback;
}

function fmt(sec){
    sec = Math.max(
        0,
        num(sec)
    );

    const m = Math.floor(sec / 60);
    const s = sec - m * 60;

    return String(m).padStart(2,'0') +
        ':' +
        s.toFixed(3).padStart(6,'0');
}

function escapeHtml(value){
    return String(value)
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

/* =========================================================
 * State
 * ======================================================= */

const state = {
    project:null,

    videoObjectUrl:null,

    selectedId:null,
    selectedType:null,

    multiSelected:[],

    context:null,

    drag:null,

    timelineDrag:null,

    zoom:1,

    modalTarget:null,

    connectionModalTarget:null,

    db:null,

    dirty:false,

    messageTimer:null,

    suppressSeek:false
};

/* =========================================================
 * Message
 * ======================================================= */

function message(text,ok=false){
    const el = $('message');

    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    clearTimeout(state.messageTimer);

    state.messageTimer =
        setTimeout(()=>{
            el.style.display = 'none';
        },3000);
}

/* =========================================================
 * Video time
 *
 * 重要:
 * ここだけを時間の基準とする。
 * タイムライン独自の再生位置は持たない。
 * ======================================================= */

function duration(){
    const video = $('recordedVideo');

    const vd = num(video.duration);

    if(Number.isFinite(vd) && vd > 0){
        return vd;
    }

    return num(
        state.project?.videoDuration,
        0
    );
}

function currentTime(){
    return clamp(
        num($('recordedVideo').currentTime),
        0,
        duration() || Infinity
    );
}

function setCurrentTime(time){
    const d = duration();

    if(!d){
        return;
    }

    const t = clamp(
        num(time),
        0,
        d
    );

    $('recordedVideo').currentTime = t;

    updateTimeUI();
    renderObjects();
    renderConnectors();
    updatePlayhead();
}

/* =========================================================
 * Project
 * ======================================================= */

function emptyProject(){
    return {
        version:APP_VERSION,
        projectId:uid('project'),
        name:'新しい編集',
        videoName:'',
        videoDuration:0,
        videoType:'',
        videoSize:0,
        elements:[],
        connections:[],
        createdAt:new Date().toISOString(),
        savedAt:''
    };
}

function newProject(){
    state.project = emptyProject();

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];
    state.context = null;
    state.dirty = false;

    $('editorProjectName').value =
        state.project.name;

    $('editorStatus').textContent =
        '動画未読込';

    clearVideo();

    showEditor();
    setEditorLocked(true);
    renderAll();
}

function normalizeElement(e){
    const d = duration() ||
        num(state.project?.videoDuration);

    e.id =
        e.id ||
        uid('element');

    e.type =
        ['comment','box','skip'].includes(e.type)
            ? e.type
            : 'comment';

    e.start = clamp(
        num(e.start),
        0,
        Math.max(0,d)
    );

    e.end = clamp(
        num(e.end,e.start + 5),
        Math.min(d,e.start + 0.05),
        Math.max(0,d)
    );

    e.x = clamp(
        num(e.x),
        0,
        100
    );

    e.y = clamp(
        num(e.y),
        0,
        100
    );

    e.w = clamp(
        num(e.w,30),
        1,
        100 - e.x
    );

    e.h = clamp(
        num(e.h,15),
        1,
        100 - e.y
    );

    e.text =
        String(e.text ?? 'テキスト');

    e.color =
        e.color || '#ffffff';

    e.fontSize =
        clamp(
            num(e.fontSize,24),
            8,
            96
        );

    e.borderWidth =
        clamp(
            num(e.borderWidth,2),
            0,
            20
        );

    e.borderStyle =
        e.borderStyle || 'solid';

    e.fill =
        e.fill || 'transparent';

    return e;
}

function normalizeConnection(c){
    c.id =
        c.id ||
        uid('connection');

    c.fromPoint =
        ['n','e','s','w'].includes(c.fromPoint)
            ? c.fromPoint
            : 'e';

    c.toPoint =
        ['n','e','s','w'].includes(c.toPoint)
            ? c.toPoint
            : 'w';

    c.color =
        c.color || '#ffffff';

    c.width =
        clamp(
            num(c.width,1.5),
            .5,
            10
        );

    c.dash =
        typeof c.dash === 'string'
            ? c.dash
            : '';

    c.arrow =
        c.arrow !== false;

    c.curve =
        clamp(
            num(c.curve,35),
            0,
            100
        );

    c.start =
        clamp(
            num(c.start),
            0,
            duration()
        );

    c.end =
        clamp(
            num(c.end,duration()),
            c.start,
            duration()
        );

    return c;
}

function normalizeProject(p){
    p = p && typeof p === 'object'
        ? p
        : emptyProject();

    p.version =
        num(p.version,APP_VERSION);

    p.projectId =
        String(
            p.projectId ||
            uid('project')
        );

    p.name =
        String(
            p.name ||
            '名称未設定'
        );

    p.elements =
        Array.isArray(p.elements)
            ? p.elements.map(normalizeElement)
            : [];

    p.connections =
        Array.isArray(p.connections)
            ? p.connections.map(normalizeConnection)
            : [];

    return p;
}

function getElement(id){
    return state.project?.elements.find(
        e=>e.id === id
    ) || null;
}

function getConnection(id){
    return state.project?.connections.find(
        c=>c.id === id
    ) || null;
}

function markDirty(){
    if(!state.project){
        return;
    }

    state.dirty = true;

    $('editorStatus').textContent =
        '未保存';
}

/* =========================================================
 * Editor visibility
 * ======================================================= */

function showEditor(){
    $('home').style.display = 'none';
    $('editor').style.display = 'flex';
}

function showHome(){
    $('editor').style.display = 'none';
    $('home').style.display = 'flex';

    clearVideo();

    renderProjectList();
}

function setEditorLocked(locked){
    $('editor').classList.toggle(
        'locked',
        !!locked
    );

    $('quickText').disabled = locked;
    $('quickBox').disabled = locked;
    $('quickSkip').disabled = locked;
}

/* =========================================================
 * Video loading
 * ======================================================= */

function clearVideo(){
    const video = $('recordedVideo');

    video.pause();

    if(state.videoObjectUrl){
        URL.revokeObjectURL(
            state.videoObjectUrl
        );

        state.videoObjectUrl = null;
    }

    video.removeAttribute('src');
    video.load();

    $('seek').value = '0';
    $('seek').max = '0';

    setEditorLocked(true);
}

async function handleVideoFile(file){
    if(!file){
        return;
    }

    if(!file.type.startsWith('video/')){
        message('動画ファイルを選択してください。');
        return;
    }

    const video = $('recordedVideo');

    clearVideo();

    state.videoObjectUrl =
        URL.createObjectURL(file);

    video.src =
        state.videoObjectUrl;

    state.project.videoName =
        file.name;

    state.project.videoType =
        file.type;

    state.project.videoSize =
        file.size;

    $('editorStatus').textContent =
        '動画を読み込み中...';

    await new Promise((resolve,reject)=>{
        const ok = ()=>{
            cleanup();
            resolve();
        };

        const ng = ()=>{
            cleanup();
            reject(
                new Error(
                    '動画を読み込めませんでした。'
                )
            );
        };

        const cleanup = ()=>{
            video.removeEventListener(
                'loadedmetadata',
                ok
            );

            video.removeEventListener(
                'error',
                ng
            );
        };

        video.addEventListener(
            'loadedmetadata',
            ok,
            {once:true}
        );

        video.addEventListener(
            'error',
            ng,
            {once:true}
        );

        video.load();
    }).then(async ()=>{
        const d = duration();

        state.project.videoDuration =
            d;

        $('seek').max =
            String(d);

        $('seek').value =
            '0';

        setEditorLocked(false);

        $('editorStatus').textContent =
            '編集可能';

        await idbPutVideo(
            state.project.projectId,
            file
        );

        state.dirty = true;

        renderAll();

        message(
            `動画を読み込みました。長さ ${fmt(d)}`,
            true
        );
    }).catch(err=>{
        clearVideo();
        message(
            err.message ||
            '動画の読み込みに失敗しました。'
        );
    });
}

/* =========================================================
 * Element creation
 * ======================================================= */

function makeElement(type,time){
    const d = duration();

    const start =
        clamp(
            num(time,currentTime()),
            0,
            d
        );

    const end =
        Math.min(
            d,
            start + (
                type === 'skip'
                    ? 2
                    : 5
            )
        );

    return normalizeElement({
        id:uid('element'),
        type,
        start,
        end,
        x:10,
        y:10,
        w:type === 'box' ? 40 : 30,
        h:type === 'box' ? 30 : 15,
        text:
            type === 'comment'
                ? 'テキスト'
                : '',
        color:
            type === 'skip'
                ? '#f59e0b'
                : '#ffffff',
        fontSize:24,
        borderWidth:2,
        borderStyle:
            type === 'skip'
                ? 'dashed'
                : 'solid',
        fill:
            type === 'box'
                ? 'transparent'
                : 'transparent'
    });
}

function addElement(type){
    if(!state.project || duration() <= 0){
        message(
            '先に動画を読み込んでください。'
        );
        return;
    }

    const e =
        makeElement(
            type,
            currentTime()
        );

    state.project.elements.push(e);

    selectElement(
        e.id,
        false
    );

    markDirty();

    renderAll();

    openElementModal(e);
}

/* =========================================================
 * Element selection
 * ======================================================= */

function selectElement(id,multi=false){
    const e = getElement(id);

    if(!e){
        return;
    }

    if(multi){
        const i =
            state.multiSelected.indexOf(id);

        if(i >= 0){
            state.multiSelected.splice(i,1);
        }else{
            if(
                state.multiSelected.length >= 2
            ){
                state.multiSelected.shift();
            }

            state.multiSelected.push(id);
        }

        state.selectedId = id;
        state.selectedType = 'element';
    }else{
        state.selectedId = id;
        state.selectedType = 'element';
        state.multiSelected = [id];
    }

    updateConnectionStatus();
    renderObjects();
    renderTimeline();
}

function selectConnection(id){
    state.selectedId = id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    updateConnectionStatus();
    renderConnectors();
    renderTimeline();
}

/* =========================================================
 * Element visibility
 * ======================================================= */

function elementVisible(e,t=currentTime()){
    return (
        t >= e.start &&
        t <= e.end
    );
}

/* =========================================================
 * Render objects
 * ======================================================= */

function renderObjects(){
    const container = $('objects');

    container.innerHTML = '';

    if(!state.project){
        return;
    }

    const t = currentTime();

    for(const e of state.project.elements){

        if(!elementVisible(e,t)){
            continue;
        }

        const el =
            document.createElement('div');

        el.className =
            'edit-object ' +
            e.type +
            (
                state.selectedId === e.id
                    ? ' selected'
                    : ''
            ) +
            (
                state.multiSelected.includes(e.id)
                    ? ' multi-selected'
                    : ''
            );

        el.dataset.id = e.id;

        el.style.left =
            `${e.x}%`;

        el.style.top =
            `${e.y}%`;

        el.style.width =
            `${e.w}%`;

        el.style.height =
            `${e.h}%`;

        el.style.color =
            e.color;

        el.style.border =
            `${e.borderWidth}px ${e.borderStyle} ${e.color}`;

        el.style.fontSize =
            `${e.fontSize}px`;

        if(e.type === 'comment'){
            el.textContent =
                e.text;
        }

        if(e.type === 'box'){
            el.style.background =
                e.fill || 'transparent';
        }

        if(e.type === 'skip'){
            const label =
                document.createElement('span');

            label.className =
                'skip-label';

            label.textContent =
                'SKIP';

            el.appendChild(label);
        }

        const handle =
            document.createElement('div');

        handle.className =
            'resize-handle';

        el.appendChild(handle);

        el.addEventListener(
            'pointerdown',
            event=>{
                beginElementPointer(
                    event,
                    e,
                    event.target === handle
                        ? 'resize'
                        : 'move'
                );
            }
        );

        el.addEventListener(
            'click',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(event.shiftKey){
                    selectElement(
                        e.id,
                        true
                    );
                }else{
                    selectElement(
                        e.id,
                        false
                    );
                }
            }
        );

        el.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                setCurrentTime(
                    e.start
                );

                selectElement(
                    e.id,
                    false
                );

                openElementModal(e);
            }
        );

        el.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(event.shiftKey){
                    selectElement(
                        e.id,
                        true
                    );
                }else if(
                    !state.multiSelected.includes(e.id)
                ){
                    selectElement(
                        e.id,
                        false
                    );
                }

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

        container.appendChild(el);
    }
}

/* =========================================================
 * Element pointer drag
 * ======================================================= */

function beginElementPointer(event,e,mode){
    if(event.button !== 0){
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    const stage =
        $('videoStage').getBoundingClientRect();

    const pointerId =
        event.pointerId;

    const target =
        event.currentTarget;

    try{
        if(
            target &&
            target.setPointerCapture
        ){
            target.setPointerCapture(
                pointerId
            );
        }
    }catch(_){}

    state.drag = {
        pointerId,
        mode,
        id:e.id,

        startX:event.clientX,
        startY:event.clientY,

        stageW:stage.width,
        stageH:stage.height,

        originalX:e.x,
        originalY:e.y,
        originalW:e.w,
        originalH:e.h
    };

    selectElement(
        e.id,
        event.shiftKey
    );
}

function moveElementPointer(event){
    const drag = state.drag;

    if(!drag){
        return;
    }

    if(
        drag.pointerId !== undefined &&
        event.pointerId !== drag.pointerId
    ){
        return;
    }

    const e =
        getElement(drag.id);

    if(!e){
        return;
    }

    const dx =
        (event.clientX - drag.startX) /
        drag.stageW *
        100;

    const dy =
        (event.clientY - drag.startY) /
        drag.stageH *
        100;

    if(drag.mode === 'move'){
        e.x =
            clamp(
                drag.originalX + dx,
                0,
                100 - e.w
            );

        e.y =
            clamp(
                drag.originalY + dy,
                0,
                100 - e.h
            );
    }else{
        e.w =
            clamp(
                drag.originalW + dx,
                1,
                100 - e.x
            );

        e.h =
            clamp(
                drag.originalH + dy,
                1,
                100 - e.y
            );
    }

    markDirty();

    renderObjects();
    renderConnectors();
}

function endElementPointer(){
    state.drag = null;
}

/* =========================================================
 * Connections
 * ======================================================= */

function connectSelected(){
    if(
        state.multiSelected.length !== 2
    ){
        message(
            'Shift＋クリックで2つの要素を選択してください。'
        );
        return;
    }

    const [fromId,toId] =
        state.multiSelected;

    if(fromId === toId){
        message(
            '同じ要素同士は接続できません。'
        );
        return;
    }

    const from =
        getElement(fromId);

    const to =
        getElement(toId);

    if(!from || !to){
        message(
            '接続対象がありません。'
        );
        return;
    }

    const exists =
        state.project.connections.some(
            c =>
                (
                    c.from === fromId &&
                    c.to === toId
                ) ||
                (
                    c.from === toId &&
                    c.to === fromId
                )
        );

    if(exists){
        message(
            'この2要素は既に接続されています。'
        );
        return;
    }

    const c = normalizeConnection({
        id:uid('connection'),

        from:fromId,
        to:toId,

        fromPoint:'e',
        toPoint:'w',

        start:Math.min(
            from.start,
            to.start
        ),

        end:Math.max(
            from.end,
            to.end
        ),

        color:
            from.color || '#ffffff',

        width:1.5,

        dash:'',

        arrow:true,

        curve:35
    });

    state.project.connections.push(c);

    state.selectedId = c.id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    markDirty();
    renderAll();

    message(
        '接続線を作成しました。',
        true
    );
}

function pointOfElement(e,point){
    let x = e.x;
    let y = e.y;

    if(point === 'n'){
        x += e.w / 2;
    }

    if(point === 'e'){
        x += e.w;
        y += e.h / 2;
    }

    if(point === 's'){
        x += e.w / 2;
        y += e.h;
    }

    if(point === 'w'){
        y += e.h / 2;
    }

    return {x,y};
}

function renderConnectors(){
    const svg = $('connectors');

    svg.innerHTML = '';

    if(!state.project){
        return;
    }

    const t = currentTime();

    const defs =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'defs'
        );

    svg.appendChild(defs);

    for(const c of state.project.connections){

        const from =
            getElement(c.from);

        const to =
            getElement(c.to);

        if(!from || !to){
            continue;
        }

        if(
            t < c.start ||
            t > c.end
        ){
            continue;
        }

        const p1 =
            pointOfElement(
                from,
                c.fromPoint
            );

        const p2 =
            pointOfElement(
                to,
                c.toPoint
            );

        const x1 =
            p1.x / 100 * svg.clientWidth;

        const y1 =
            p1.y / 100 * svg.clientHeight;

        const x2 =
            p2.x / 100 * svg.clientWidth;

        const y2 =
            p2.y / 100 * svg.clientHeight;

        const dx =
            Math.abs(x2 - x1);

        const curve =
            Math.max(
                20,
                dx * c.curve / 100
            );

        let d;

        if(c.curve <= 0){
            d =
                `M ${x1} ${y1} L ${x2} ${y2}`;
        }else{
            const dir =
                x2 >= x1
                    ? 1
                    : -1;

            const c1x =
                x1 + curve * dir;

            const c2x =
                x2 - curve * dir;

            d =
                `M ${x1} ${y1} ` +
                `C ${c1x} ${y1}, ` +
                `${c2x} ${y2}, ` +
                `${x2} ${y2}`;
        }

        let markerId = '';

        if(c.arrow){
            markerId =
                'arrow-' + c.id;

            const marker =
                document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'marker'
                );

            marker.setAttribute(
                'id',
                markerId
            );

            marker.setAttribute(
                'viewBox',
                '0 0 10 10'
            );

            marker.setAttribute(
                'refX',
                '9'
            );

            marker.setAttribute(
                'refY',
                '5'
            );

            marker.setAttribute(
                'markerWidth',
                '6'
            );

            marker.setAttribute(
                'markerHeight',
                '6'
            );

            marker.setAttribute(
                'orient',
                'auto-start-reverse'
            );

            const path =
                document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'path'
                );

            path.setAttribute(
                'd',
                'M 0 0 L 10 5 L 0 10 z'
            );

            path.setAttribute(
                'fill',
                c.color
            );

            marker.appendChild(path);
            defs.appendChild(marker);
        }

        const path =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'path'
            );

        path.setAttribute(
            'd',
            d
        );

        path.setAttribute(
            'stroke',
            c.color
        );

        path.setAttribute(
            'stroke-width',
            String(c.width)
        );

        path.setAttribute(
            'fill',
            'none'
        );

        if(c.dash){
            path.setAttribute(
                'stroke-dasharray',
                c.dash
            );
        }

        if(markerId){
            path.setAttribute(
                'marker-end',
                `url(#${markerId})`
            );
        }

        path.classList.add(
            'connector'
        );

        if(
            state.selectedType === 'connection' &&
            state.selectedId === c.id
        ){
            path.classList.add(
                'selected'
            );
        }

        path.style.pointerEvents =
            'stroke';

        path.addEventListener(
            'click',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectConnection(c.id);
            }
        );

        path.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectConnection(c.id);
                openConnectionModal(c);
            }
        );

        path.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectConnection(c.id);

                state.context = {
                    type:'connection',
                    id:c.id
                };

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

        svg.appendChild(path);
    }
}

/* =========================================================
 * Timeline
 *
 * 重要:
 * 全トラックは同じ timelineWidth。
 * timelineWidth は動画の長さだけで決まる。
 * 要素や再生位置には依存しない。
 * ======================================================= */

function timelineWidth(){
    const d = duration();

    if(!d){
        return 800;
    }

    return Math.max(
        800,
        d * 70 * state.zoom
    );
}

function timelineStep(){
    if(state.zoom >= 4){
        return 1;
    }

    if(state.zoom >= 2){
        return 2;
    }

    if(state.zoom >= 1){
        return 5;
    }

    return 10;
}

function renderTimeline(){
    const content =
        $('timelineContent');

    const scale =
        $('timelineScale');

    const tracks =
        $('timelineTracks');

    scale.innerHTML = '';
    tracks.innerHTML = '';

    const d = duration();

    if(!d){
        content.style.width =
            '800px';

        updatePlayhead();

        return;
    }

    const width =
        timelineWidth();

    content.style.width =
        `${width}px`;

    const step =
        timelineStep();

    for(
        let t=0;
        t<=d + .0001;
        t+=step
    ){
        const span =
            document.createElement('span');

        const actual =
            Math.min(t,d);

        span.textContent =
            fmt(actual);

        span.style.left =
            `${actual / d * 100}%`;

        scale.appendChild(span);
    }

    const groups = [
        [
            'テキスト',
            'comment',
            state.project.elements.filter(
                e=>e.type === 'comment'
            )
        ],
        [
            '強調枠',
            'box',
            state.project.elements.filter(
                e=>e.type === 'box'
            )
        ],
        [
            'スキップ',
            'skip',
            state.project.elements.filter(
                e=>e.type === 'skip'
            )
        ],
        [
            '接続',
            'connection',
            state.project.connections
        ]
    ];

    for(const [
        name,
        type,
        items
    ] of groups){
        renderTrack(
            name,
            type,
            items,
            width,
            d
        );
    }

    updatePlayhead();
}

function renderTrack(
    name,
    type,
    items,
    width,
    d
){
    const row =
        document.createElement('div');

    row.className =
        'timeline-row';

    const label =
        document.createElement('div');

    label.className =
        'timeline-label';

    label.textContent =
        name;

    const track =
        document.createElement('div');

    track.className =
        'track';

    track.style.width =
        `${width}px`;

    row.appendChild(label);
    row.appendChild(track);

    /*
     * 同じ種類・同じ時間帯の要素が重ならないよう
     * 見た目だけ縦方向へレーン分けする。
     * X軸・時間軸は全要素で共通。
     */
    const lanes = [];

    const sorted =
        [...items].sort(
            (a,b)=>
                num(a.start) -
                num(b.start)
        );

    sorted.forEach(item=>{
        const start =
            clamp(
                num(item.start),
                0,
                d
            );

        const end =
            clamp(
                num(item.end,d),
                start,
                d
            );

        let lane = 0;

        while(
            lanes[lane] !== undefined &&
            lanes[lane] > start
        ){
            lane++;
        }

        lanes[lane] = end;

        const bar =
            document.createElement('div');

        bar.className =
            `track-item ${type}` +
            (
                item.id === state.selectedId
                    ? ' selected'
                    : ''
            ) +
            (
                state.multiSelected.includes(item.id)
                    ? ' multi'
                    : ''
            );

        bar.dataset.id =
            item.id;

        bar.style.left =
            `${start / d * 100}%`;

        /*
         * ここが重要。
         * widthは必ず end-start。
         * 再生インデックスを幅計算に使わない。
         */
        bar.style.width =
            `${Math.max(
                .2,
                (end-start) / d * 100
            )}%`;

        bar.style.top =
            `${6 + lane * 31}px`;

        if(lane > 0){
            track.style.height =
                `${42 + lane * 31}px`;
        }

        const startText =
            document.createElement('span');

        startText.className =
            'track-time';

        startText.textContent =
            fmt(start);

        const endText =
            document.createElement('span');

        endText.className =
            'track-end-time';

        endText.textContent =
            fmt(end);

        const nameText =
            document.createElement('span');

        nameText.className =
            'track-name';

        if(type === 'comment'){
            nameText.textContent =
                item.text || 'テキスト';
        }else if(type === 'box'){
            nameText.textContent =
                '強調枠';
        }else if(type === 'skip'){
            nameText.textContent =
                'SKIP';
        }else{
            nameText.textContent =
                '接続';
        }

        bar.appendChild(startText);
        bar.appendChild(endText);
        bar.appendChild(nameText);

        if(type !== 'connection'){

            const left =
                document.createElement('div');

            left.className =
                'track-handle left';

            const right =
                document.createElement('div');

            right.className =
                'track-handle right';

            left.addEventListener(
                'pointerdown',
                event=>{
                    event.preventDefault();
                    event.stopPropagation();

                    beginTimelineResize(
                        event,
                        item,
                        'start',
                        track,
                        d
                    );
                }
            );

            right.addEventListener(
                'pointerdown',
                event=>{
                    event.preventDefault();
                    event.stopPropagation();

                    beginTimelineResize(
                        event,
                        item,
                        'end',
                        track,
                        d
                    );
                }
            );

            bar.appendChild(left);
            bar.appendChild(right);
        }

        bar.addEventListener(
            'click',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(event.shiftKey){
                    selectElement(
                        item.id,
                        true
                    );
                }else{
                    if(type === 'connection'){
                        selectConnection(
                            item.id
                        );
                    }else{
                        selectElement(
                            item.id,
                            false
                        );
                    }
                }

                /*
                 * バーを選択したら、その要素の開始時点へ
                 * 動画と赤インデックスを移動。
                 */
                setCurrentTime(
                    num(item.start)
                );
            }
        );

        bar.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                setCurrentTime(
                    num(item.start)
                );

                if(type === 'connection'){
                    openConnectionModal(item);
                }else{
                    openElementModal(item);
                }
            }
        );

        bar.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(type === 'connection'){
                    selectConnection(
                        item.id
                    );

                    state.context = {
                        type:'connection',
                        id:item.id
                    };
                }else{
                    selectElement(
                        item.id,
                        event.shiftKey
                    );

                    state.context = {
                        type:'element',
                        id:item.id
                    };
                }

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

        /*
         * バー本体をドラッグ。
         * 左右ハンドルは時間変更。
         */
        if(type !== 'connection'){
            bar.addEventListener(
                'pointerdown',
                event=>{
                    if(
                        event.target.closest(
                            '.track-handle'
                        )
                    ){
                        return;
                    }

                    beginTimelineMove(
                        event,
                        item,
                        track,
                        d
                    );
                }
            );
        }

        track.appendChild(bar);
    });

    tracks.appendChild(row);
}

/* =========================================================
 * Timeline dragging
 * ======================================================= */

function trackTimeFromPointer(
    event,
    track,
    d
){
    const rect =
        track.getBoundingClientRect();

    const x =
        clamp(
            event.clientX - rect.left,
            0,
            rect.width
        );

    return x / rect.width * d;
}

function beginTimelineMove(
    event,
    item,
    track,
    d
){
    if(event.button !== 0){
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    try{
        track.setPointerCapture?.(
            event.pointerId
        );
    }catch(_){}

    selectElement(
        item.id,
        event.shiftKey
    );

    state.timelineDrag = {
        mode:'move',
        id:item.id,
        pointerId:event.pointerId,
        track,
        d,
        startPointer:
            event.clientX,
        originalStart:
            num(item.start),
        originalEnd:
            num(item.end)
    };
}

function beginTimelineResize(
    event,
    item,
    edge,
    track,
    d
){
    if(event.button !== 0){
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    try{
        track.setPointerCapture?.(
            event.pointerId
        );
    }catch(_){}

    selectElement(
        item.id,
        false
    );

    state.timelineDrag = {
        mode:edge,
        id:item.id,
        pointerId:event.pointerId,
        track,
        d
    };
}

function moveTimeline(event){
    const drag =
        state.timelineDrag;

    if(!drag){
        return;
    }

    if(
        drag.pointerId !== undefined &&
        event.pointerId !== drag.pointerId
    ){
        return;
    }

    const item =
        getElement(drag.id);

    if(!item){
        return;
    }

    const t =
        trackTimeFromPointer(
            event,
            drag.track,
            drag.d
        );

    const minLength =
        Math.min(
            .05,
            drag.d
        );

    if(drag.mode === 'move'){

        const len =
            drag.originalEnd -
            drag.originalStart;

        const delta =
            t -
            trackTimeFromPointer(
                {
                    clientX:
                        event.clientX -
                        (
                            event.clientX -
                            drag.startPointer
                        )
                },
                drag.track,
                drag.d
            );

        /*
         * 上の計算はブラウザ幅変化に影響されるため、
         * 実際にはpointer差分を直接秒換算する。
         */
        const rect =
            drag.track.getBoundingClientRect();

        const secondsPerPixel =
            drag.d / rect.width;

        const pointerDelta =
            (
                event.clientX -
                drag.startPointer
            ) * secondsPerPixel;

        const ns =
            clamp(
                drag.originalStart +
                pointerDelta,
                0,
                drag.d - len
            );

        item.start = ns;
        item.end = ns + len;

    }else if(drag.mode === 'start'){

        item.start =
            clamp(
                t,
                0,
                item.end - minLength
            );

    }else if(drag.mode === 'end'){

        item.end =
            clamp(
                t,
                item.start + minLength,
                drag.d
            );
    }

    markDirty();

    renderTimeline();
    renderObjects();
    renderConnectors();
}

function endTimelineDrag(){
    state.timelineDrag = null;
}

/* =========================================================
 * Playhead
 * ======================================================= */

function updatePlayhead(){
    const d = duration();

    if(!d){
        return;
    }

    const t =
        clamp(
            currentTime(),
            0,
            d
        );

    const ratio =
        t / d;

    const px =
        ratio *
        timelineWidth();

    const playhead =
        $('timelinePlayhead');

    playhead.style.left =
        `${px}px`;
}

function updateTimeUI(){
    const t =
        currentTime();

    const d =
        duration();

    const text =
        `${fmt(t)} / ${fmt(d)}`;

    $('currentTime').textContent =
        text;

    $('videoTime').textContent =
        text;

    if(
        Number.isFinite(d) &&
        d > 0
    ){
        $('seek').max =
            String(d);

        $('seek').value =
            String(t);
    }
}

function handleVideoTimeUpdate(){
    const video =
        $('recordedVideo');

    const t =
        num(video.currentTime);

    /*
     * スキップは再生中のみ実行。
     * 手動シークでスキップ区間へ入った場合には
     * 勝手に飛ばさない。
     */
    if(
        !video.paused &&
        !state.suppressSeek
    ){
        const skip =
            state.project?.elements.find(
                e =>
                    e.type === 'skip' &&
                    t >= e.start &&
                    t < e.end - .01
            );

        if(skip){
            state.suppressSeek = true;

            video.currentTime =
                skip.end;

            setTimeout(()=>{
                state.suppressSeek = false;
            },50);
        }
    }

    updateTimeUI();
    updatePlayhead();
    renderObjects();
    renderConnectors();
}

/* =========================================================
 * Play / Pause
 * ======================================================= */

async function togglePlay(){
    const video =
        $('recordedVideo');

    if(!duration()){
        message(
            '先に動画を読み込んでください。'
        );
        return;
    }

    if(video.paused){
        try{
            await video.play();
        }catch(err){
            message(
                '再生できませんでした。'
            );
        }
    }else{
        video.pause();
    }
}

function updatePlayButtons(){
    const playing =
        !$('recordedVideo').paused;

    $('playToggle').textContent =
        playing ? '⏸' : '▶';

    $('videoPlay').textContent =
        playing ? '⏸' : '▶';
}

/* =========================================================
 * Timeline seek
 * ======================================================= */

function seekFromTimeline(event){
    const d = duration();

    if(!d){
        return;
    }

    const rect =
        $('timelineScale').getBoundingClientRect();

    const x =
        clamp(
            event.clientX - rect.left,
            0,
            rect.width
        );

    const t =
        x / rect.width * d;

    setCurrentTime(t);
}

function seekInput(){
    setCurrentTime(
        num($('seek').value)
    );
}

/* =========================================================
 * Element modal
 * ======================================================= */

function buildPalette(){
    const p =
        $('elementPalette');

    p.innerHTML = '';

    for(const color of COLORS){
        const b =
            document.createElement('button');

        b.type = 'button';

        b.dataset.color =
            color;

        b.style.background =
            color;

        b.addEventListener(
            'click',
            ()=>{
                p.dataset.value =
                    color;

                [...p.children].forEach(
                    x =>
                        x.classList.toggle(
                            'active',
                            x === b
                        )
                );
            }
        );

        p.appendChild(b);
    }
}

function openElementModal(e){
    if(!e){
        return;
    }

    state.modalTarget =
        e.id;

    $('elementModal').style.display =
        'flex';

    $('elementType').value =
        e.type;

    $('elementText').value =
        e.text || '';

    $('elementStart').value =
        e.start;

    $('elementEnd').value =
        e.end;

    $('elementX').value =
        e.x;

    $('elementY').value =
        e.y;

    $('elementW').value =
        e.w;

    $('elementH').value =
        e.h;

    $('elementFontSize').value =
        e.fontSize;

    $('elementBorderWidth').value =
        e.borderWidth;

    $('elementBorderStyle').value =
        e.borderStyle;

    $('elementPalette').dataset.value =
        e.color;

    [...$('elementPalette').children].forEach(
        b =>
            b.classList.toggle(
                'active',
                b.dataset.color === e.color
            )
    );

    updateElementModalFields();
}

function updateElementModalFields(){
    const type =
        $('elementType').value;

    $('elementTextRow').style.display =
        type === 'comment'
            ? 'grid'
            : 'none';
}

function closeElementModal(){
    $('elementModal').style.display =
        'none';

    state.modalTarget =
        null;
}

function applyElementModal(){
    const e =
        getElement(
            state.modalTarget
        );

    if(!e){
        closeElementModal();
        return;
    }

    const d =
        duration();

    const start =
        clamp(
            num($('elementStart').value),
            0,
            d
        );

    const end =
        clamp(
            num($('elementEnd').value),
            start + Math.min(.05,d),
            d
        );

    if(end <= start){
        message(
            '終了時間は開始時間より後にしてください。'
        );
        return;
    }

    e.type =
        $('elementType').value;

    e.text =
        $('elementText').value;

    e.start =
        start;

    e.end =
        end;

    e.x =
        clamp(
            num($('elementX').value),
            0,
            100
        );

    e.y =
        clamp(
            num($('elementY').value),
            0,
            100
        );

    e.w =
        clamp(
            num($('elementW').value,30),
            1,
            100 - e.x
        );

    e.h =
        clamp(
            num($('elementH').value,15),
            1,
            100 - e.y
        );

    e.color =
        $('elementPalette').dataset.value ||
        e.color;

    e.fontSize =
        clamp(
            num($('elementFontSize').value,24),
            8,
            96
        );

    e.borderWidth =
        clamp(
            num($('elementBorderWidth').value,2),
            0,
            20
        );

    e.borderStyle =
        $('elementBorderStyle').value;

    if(e.type === 'skip'){
        e.color =
            e.color || '#f59e0b';
        e.borderStyle =
            'dashed';
    }

    markDirty();

    closeElementModal();

    renderAll();

    message(
        '要素を更新しました。',
        true
    );
}

/* =========================================================
 * Connection modal
 * ======================================================= */

function openConnectionModal(c){
    if(!c){
        return;
    }

    state.connectionModalTarget =
        c.id;

    $('connectionModal').style.display =
        'flex';

    $('connectionFromPoint').value =
        c.fromPoint;

    $('connectionToPoint').value =
        c.toPoint;

    $('connectionStart').value =
        c.start;

    $('connectionEnd').value =
        c.end;

    $('connectionColor').value =
        c.color;

    $('connectionWidth').value =
        c.width;

    $('connectionDash').value =
        c.dash;

    $('connectionArrow').value =
        c.arrow ? '1' : '0';

    $('connectionCurve').value =
        c.curve;
}

function closeConnectionModal(){
    $('connectionModal').style.display =
        'none';

    state.connectionModalTarget =
        null;
}

function applyConnectionModal(){
    const c =
        getConnection(
            state.connectionModalTarget
        );

    if(!c){
        closeConnectionModal();
        return;
    }

    const d =
        duration();

    const start =
        clamp(
            num($('connectionStart').value),
            0,
            d
        );

    const end =
        clamp(
            num($('connectionEnd').value),
            start,
            d
        );

    if(end < start){
        message(
            '接続終了時間が開始時間より前です。'
        );
        return;
    }

    c.fromPoint =
        $('connectionFromPoint').value;

    c.toPoint =
        $('connectionToPoint').value;

    c.start =
        start;

    c.end =
        end;

    c.color =
        $('connectionColor').value;

    c.width =
        clamp(
            num($('connectionWidth').value,1.5),
            .5,
            10
        );

    c.dash =
        $('connectionDash').value;

    c.arrow =
        $('connectionArrow').value === '1';

    c.curve =
        clamp(
            num($('connectionCurve').value,35),
            0,
            100
        );

    markDirty();

    closeConnectionModal();

    renderAll();

    message(
        '接続線を更新しました。',
        true
    );
}

function deleteConnection(id){
    const i =
        state.project.connections.findIndex(
            c=>c.id === id
        );

    if(i < 0){
        return;
    }

    state.project.connections.splice(
        i,
        1
    );

    if(state.selectedId === id){
        state.selectedId = null;
        state.selectedType = null;
    }

    markDirty();
    renderAll();
}

/* =========================================================
 * Delete / duplicate
 * ======================================================= */

function deleteSelected(){
    if(
        state.selectedType === 'connection' &&
        state.selectedId
    ){
        deleteConnection(
            state.selectedId
        );

        message(
            '接続線を削除しました。',
            true
        );

        return;
    }

    const ids =
        state.multiSelected.length
            ? [...state.multiSelected]
            : state.selectedId
                ? [state.selectedId]
                : [];

    if(!ids.length){
        message(
            '削除する要素を選択してください。'
        );
        return;
    }

    state.project.elements =
        state.project.elements.filter(
            e=>!ids.includes(e.id)
        );

    state.project.connections =
        state.project.connections.filter(
            c =>
                !ids.includes(c.from) &&
                !ids.includes(c.to)
        );

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];

    markDirty();
    renderAll();

    message(
        '要素を削除しました。',
        true
    );
}

function duplicateSelected(){
    const e =
        getElement(
            state.selectedId
        );

    if(!e){
        message(
            '複製する要素を選択してください。'
        );
        return;
    }

    const copy =
        JSON.parse(
            JSON.stringify(e)
        );

    copy.id =
        uid('element');

    copy.x =
        clamp(
            copy.x + 3,
            0,
            100 - copy.w
        );

    copy.y =
        clamp(
            copy.y + 3,
            0,
            100 - copy.h
        );

    state.project.elements.push(
        copy
    );

    selectElement(
        copy.id,
        false
    );

    markDirty();
    renderAll();

    message(
        '要素を複製しました。',
        true
    );
}

/* =========================================================
 * Context menu
 * ======================================================= */

function showContextMenu(x,y){
    const menu =
        $('contextMenu');

    const connect =
        $('contextConnect');

    connect.style.display =
        state.multiSelected.length === 2
            ? 'block'
            : 'none';

    menu.style.display =
        'block';

    const rect =
        menu.getBoundingClientRect();

    menu.style.left =
        `${Math.min(
            x,
            window.innerWidth - rect.width - 8
        )}px`;

    menu.style.top =
        `${Math.min(
            y,
            window.innerHeight - rect.height - 8
        )}px`;
}

function hideContextMenu(){
    $('contextMenu').style.display =
        'none';
}

/* =========================================================
 * Connection status
 * ======================================================= */

function updateConnectionStatus(){
    const el =
        $('connectionStatus');

    if(
        state.multiSelected.length === 2
    ){
        el.textContent =
            '2要素選択中 → 右クリック「選択した2要素を接続」';
        return;
    }

    if(
        state.multiSelected.length === 1
    ){
        el.textContent =
            '1要素選択中 → Shift＋クリックでもう1つ選択';
        return;
    }

    if(
        state.selectedType === 'connection'
    ){
        el.textContent =
            '接続線を選択中 → 右クリックまたはダブルクリックで編集';
        return;
    }

    el.textContent =
        'Shift＋クリックで2要素を選択 → 右クリックで接続';
}

/* =========================================================
 * Render all
 * ======================================================= */

function renderAll(){
    renderObjects();
    renderConnectors();
    renderTimeline();
    updateTimeUI();
    updatePlayhead();
    updateConnectionStatus();
    updatePlayButtons();
}

/* =========================================================
 * IndexedDB
 *
 * プロジェクトと動画Blobを別storeにする。
 * keyPath問題を避け、必ずkeyを持たせる。
 * ======================================================= */

function openDB(){
    return new Promise((resolve,reject)=>{
        const req =
            indexedDB.open(
                'VideoDirectEditor',
                3
            );

        req.onupgradeneeded =
            event=>{
                const db =
                    event.target.result;

                if(
                    !db.objectStoreNames.contains(
                        'projects'
                    )
                ){
                    db.createObjectStore(
                        'projects',
                        {
                            keyPath:'projectId'
                        }
                    );
                }

                if(
                    !db.objectStoreNames.contains(
                        'videos'
                    )
                ){
                    db.createObjectStore(
                        'videos',
                        {
                            keyPath:'projectId'
                        }
                    );
                }
            };

        req.onsuccess = ()=>{
            state.db =
                req.result;

            resolve(
                state.db
            );
        };

        req.onerror = ()=>{
            reject(
                req.error ||
                new Error(
                    'IndexedDBを開けません。'
                )
            );
        };
    });
}

function idbPutProject(project){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            reject(
                new Error(
                    'IndexedDB unavailable'
                )
            );
            return;
        }

        const copy =
            JSON.parse(
                JSON.stringify(project)
            );

        if(
            !copy.projectId
        ){
            reject(
                new Error(
                    'projectIdがありません。'
                )
            );
            return;
        }

        const tx =
            state.db.transaction(
                'projects',
                'readwrite'
            );

        tx.objectStore(
            'projects'
        ).put(copy);

        tx.oncomplete =
            ()=>resolve();

        tx.onerror =
            ()=>reject(
                tx.error
            );
    });
}

function idbGetProject(id){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            resolve(null);
            return;
        }

        const tx =
            state.db.transaction(
                'projects',
                'readonly'
            );

        const req =
            tx.objectStore(
                'projects'
            ).get(id);

        req.onsuccess =
            ()=>resolve(
                req.result || null
            );

        req.onerror =
            ()=>reject(
                req.error
            );
    });
}

function idbListProjects(){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            resolve([]);
            return;
        }

        const tx =
            state.db.transaction(
                'projects',
                'readonly'
            );

        const req =
            tx.objectStore(
                'projects'
            ).getAll();

        req.onsuccess =
            ()=>resolve(
                req.result || []
            );

        req.onerror =
            ()=>reject(
                req.error
            );
    });
}

function idbDeleteProject(id){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            resolve();
            return;
        }

        const tx =
            state.db.transaction(
                'projects',
                'readwrite'
            );

        tx.objectStore(
            'projects'
        ).delete(id);

        tx.oncomplete =
            ()=>resolve();

        tx.onerror =
            ()=>reject(
                tx.error
            );
    });
}

function idbPutVideo(id,file){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            reject(
                new Error(
                    'IndexedDB unavailable'
                )
            );
            return;
        }

        if(!id){
            reject(
                new Error(
                    'projectIdがありません。'
                )
            );
            return;
        }

        const tx =
            state.db.transaction(
                'videos',
                'readwrite'
            );

        tx.objectStore(
            'videos'
        ).put({
            projectId:id,
            name:file.name,
            type:file.type,
            size:file.size,
            blob:file,
            savedAt:new Date().toISOString()
        });

        tx.oncomplete =
            ()=>resolve();

        tx.onerror =
            ()=>reject(
                tx.error
            );
    });
}

function idbGetVideo(id){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            resolve(null);
            return;
        }

        const tx =
            state.db.transaction(
                'videos',
                'readonly'
            );

        const req =
            tx.objectStore(
                'videos'
            ).get(id);

        req.onsuccess =
            ()=>resolve(
                req.result || null
            );

        req.onerror =
            ()=>reject(
                req.error
            );
    });
}

/* =========================================================
 * Save
 * ======================================================= */

async function saveProject(){
    if(!state.project){
        message(
            'プロジェクトがありません。'
        );
        return;
    }

    if(duration() <= 0){
        message(
            '先に動画を読み込んでください。'
        );
        return;
    }

    state.project.name =
        $('editorProjectName').value.trim() ||
        '名称未設定';

    state.project.videoDuration =
        duration();

    /*
     * まずサーバー保存を試す。
     * 上限 / サーバー書込不可ならIndexedDB。
     */
    try{
        const response =
            await fetch(
                '?api=save',
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/json'
                    },
                    body:
                        JSON.stringify(
                            state.project
                        )
                }
            );

        const result =
            await response.json();

        if(!result.ok){
            throw new Error(
                result.message ||
                'SERVER_SAVE_FAILED'
            );
        }

        state.project.projectId =
            result.projectId;

        state.project.savedAt =
            result.savedAt;

        await idbPutProject(
            state.project
        );

        state.dirty = false;

        $('editorStatus').textContent =
            'サーバー保存済み';

        message(
            'サーバーへ保存しました。',
            true
        );

        return;

    }catch(err){
        /*
         * サーバー保存失敗時はローカルへ。
         */
        try{
            await idbPutProject(
                state.project
            );

            state.dirty = false;

            $('editorStatus').textContent =
                'ローカル保存済み';

            message(
                'ローカルへ保存しました。',
                true
            );

        }catch(localErr){
            message(
                '保存に失敗しました。\n' +
                (
                    localErr.message ||
                    err.message
                )
            );
        }
    }
}

/* =========================================================
 * Load server project
 * ======================================================= */

async function loadServerProject(id){
    try{
        const response =
            await fetch(
                '?api=load&id=' +
                encodeURIComponent(id),
                {
                    cache:'no-store'
                }
            );

        const result =
            await response.json();

        if(!result.ok){
            throw new Error(
                result.message ||
                '読み込みに失敗しました。'
            );
        }

        state.project =
            normalizeProject(
                result.project
            );

        state.dirty = false;

        $('editorProjectName').value =
            state.project.name;

        /*
         * サーバーのプロジェクトJSONと、
         * ブラウザに残っている動画Blobを紐付ける。
         */
        const video =
            await idbGetVideo(
                state.project.projectId
            );

        showEditor();

        if(video?.blob){
            await attachVideoBlob(
                video.blob
            );

            message(
                'プロジェクトと保存済み動画を読み込みました。',
                true
            );
        }else{
            setEditorLocked(true);

            $('editorStatus').textContent =
                '動画未読込';

            renderAll();

            message(
                'プロジェクトを開きました。動画を選択してください。',
                true
            );
        }

    }catch(err){
        message(
            err.message ||
            '読み込みに失敗しました。'
        );
    }
}

async function attachVideoBlob(blob){
    const video =
        $('recordedVideo');

    if(state.videoObjectUrl){
        URL.revokeObjectURL(
            state.videoObjectUrl
        );
    }

    state.videoObjectUrl =
        URL.createObjectURL(blob);

    video.src =
        state.videoObjectUrl;

    await new Promise((resolve,reject)=>{
        video.addEventListener(
            'loadedmetadata',
            resolve,
            {once:true}
        );

        video.addEventListener(
            'error',
            ()=>{
                reject(
                    new Error(
                        '保存動画を読み込めません。'
                    )
                );
            },
            {once:true}
        );

        video.load();
    });

    state.project.videoDuration =
        duration();

    $('seek').max =
        String(duration());

    $('seek').value =
        '0';

    setEditorLocked(false);

    $('editorStatus').textContent =
        state.dirty
            ? '未保存'
            : '編集可能';

    renderAll();
}

/* =========================================================
 * Project list
 * ======================================================= */

async function renderProjectList(){
    const list =
        $('projectList');

    list.innerHTML =
        '読み込み中...';

    const projects = [];

    try{
        const response =
            await fetch(
                '?api=list',
                {
                    cache:'no-store'
                }
            );

        const result =
            await response.json();

        if(result.ok){
            projects.push(
                ...result.projects
            );
        }
    }catch(_){}

    try{
        const local =
            await idbListProjects();

        for(const p of local){
            if(
                !projects.some(
                    x =>
                        x.projectId ===
                        p.projectId
                )
            ){
                projects.push({
                    projectId:p.projectId,
                    name:p.name,
                    videoName:p.videoName,
                    savedAt:p.savedAt,
                    version:p.version,
                    storage:'local'
                });
            }
        }
    }catch(_){}

    list.innerHTML = '';

    if(!projects.length){
        list.innerHTML =
            '<p>保存済み編集はありません。</p>';
        return;
    }

    projects.sort(
        (a,b)=>
            String(b.savedAt || '')
                .localeCompare(
                    String(a.savedAt || '')
                )
    );

    for(const p of projects){
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
            p.name ||
            '名称未設定';

        const meta =
            document.createElement('div');

        meta.className =
            'project-meta';

        meta.textContent =
            `${p.videoName || '動画未設定'} / ` +
            `${p.storage === 'local' ? 'ローカル' : 'サーバー'} / ` +
            `${p.savedAt || ''}`;

        info.appendChild(name);
        info.appendChild(meta);

        const actions =
            document.createElement('div');

        actions.className =
            'project-actions';

        const open =
            document.createElement('button');

        open.textContent =
            '開く';

        open.addEventListener(
            'click',
            async ()=>{
                if(p.storage === 'local'){
                    const project =
                        await idbGetProject(
                            p.projectId
                        );

                    if(!project){
                        message(
                            'ローカルプロジェクトがありません。'
                        );
                        return;
                    }

                    state.project =
                        normalizeProject(
                            project
                        );

                    state.dirty = false;

                    $('editorProjectName').value =
                        state.project.name;

                    const video =
                        await idbGetVideo(
                            state.project.projectId
                        );

                    showEditor();

                    if(video?.blob){
                        try{
                            await attachVideoBlob(
                                video.blob
                            );
                        }catch(err){
                            setEditorLocked(true);

                            message(
                                err.message
                            );
                        }
                    }else{
                        setEditorLocked(true);

                        $('editorStatus').textContent =
                            '動画未読込';

                        renderAll();
                    }

                }else{
                    await loadServerProject(
                        p.projectId
                    );
                }
            }
        );

        actions.appendChild(open);

        const del =
            document.createElement('button');

        del.className =
            'danger';

        del.textContent =
            '削除';

        del.addEventListener(
            'click',
            async ()=>{
                if(
                    !confirm(
                        'この保存データを削除しますか？'
                    )
                ){
                    return;
                }

                try{
                    if(p.storage === 'server'){
                        await fetch(
                            '?api=delete',
                            {
                                method:'POST',
                                headers:{
                                    'Content-Type':
                                        'application/json'
                                },
                                body:
                                    JSON.stringify({
                                        projectId:
                                            p.projectId
                                    })
                            }
                        );
                    }

                    await idbDeleteProject(
                        p.projectId
                    );

                    renderProjectList();

                }catch(err){
                    message(
                        err.message
                    );
                }
            }
        );

        actions.appendChild(del);

        row.appendChild(info);
        row.appendChild(actions);

        list.appendChild(row);
    }
}

/* =========================================================
 * Project export / import
 * ======================================================= */

function exportProject(){
    if(!state.project){
        message(
            'プロジェクトがありません。'
        );
        return;
    }

    const copy =
        JSON.parse(
            JSON.stringify(
                state.project
            )
        );

    const blob =
        new Blob(
            [
                JSON.stringify(
                    copy,
                    null,
                    2
                )
            ],
            {
                type:
                    'application/json'
            }
        );

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href = url;

    a.download =
        (
            state.project.name ||
            'video-project'
        ) +
        '.json';

    a.click();

    setTimeout(
        ()=>{
            URL.revokeObjectURL(url);
        },
        1000
    );

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
            normalizeProject(
                JSON.parse(text)
            );

        state.project =
            project;

        state.dirty = false;

        $('editorProjectName').value =
            project.name;

        showEditor();

        const video =
            await idbGetVideo(
                project.projectId
            );

        if(video?.blob){
            await attachVideoBlob(
                video.blob
            );
        }else{
            setEditorLocked(true);

            $('editorStatus').textContent =
                '動画未読込';

            renderAll();

            message(
                'プロジェクトを読み込みました。動画を選択してください。',
                true
            );
        }

    }catch(err){
        message(
            'プロジェクトの読み込みに失敗しました。\n' +
            err.message
        );
    }
}

/* =========================================================
 * Events
 * ======================================================= */

function initEvents(){

    $('newProject')
        .addEventListener(
            'click',
            newProject
        );

    $('backHome')
        .addEventListener(
            'click',
            ()=>{
                if(
                    state.dirty &&
                    !confirm(
                        '未保存の変更があります。戻りますか？'
                    )
                ){
                    return;
                }

                showHome();
            }
        );

    $('chooseVideo')
        .addEventListener(
            'click',
            ()=>{
                $('videoFile').click();
            }
        );

    $('videoFile')
        .addEventListener(
            'change',
            async event=>{
                const file =
                    event.target.files?.[0];

                event.target.value =
                    '';

                await handleVideoFile(
                    file
                );
            }
        );

    $('saveProject')
        .addEventListener(
            'click',
            saveProject
        );

    $('exportProject')
        .addEventListener(
            'click',
            exportProject
        );

    $('importProject')
        .addEventListener(
            'click',
            ()=>{
                $('projectFile').click();
            }
        );

    $('projectFile')
        .addEventListener(
            'change',
            async event=>{
                const file =
                    event.target.files?.[0];

                event.target.value =
                    '';

                await importProjectFile(
                    file
                );
            }
        );

    $('videoPlay')
        .addEventListener(
            'click',
            togglePlay
        );

    $('playToggle')
        .addEventListener(
            'click',
            togglePlay
        );

    $('seek')
        .addEventListener(
            'input',
            seekInput
        );

    $('timelineSeekLayer')
        .addEventListener(
            'click',
            seekFromTimeline
        );

    $('timelineZoom')
        .addEventListener(
            'change',
            event=>{
                state.zoom =
                    clamp(
                        num(
                            event.target.value,
                            1
                        ),
                        .5,
                        5
                    );

                renderTimeline();
            }
        );

    $('timelineFit')
        .addEventListener(
            'click',
            ()=>{
                const d =
                    duration();

                if(!d){
                    return;
                }

                const scroll =
                    $('timelineScroll');

                const available =
                    Math.max(
                        500,
                        scroll.clientWidth - 90
                    );

                const target =
                    available /
                    (d * 70);

                const values =
                    [.5,1,2,3,5];

                let closest =
                    values[0];

                for(const value of values){
                    if(
                        Math.abs(
                            value - target
                        ) <
                        Math.abs(
                            closest - target
                        )
                    ){
                        closest = value;
                    }
                }

                state.zoom =
                    closest;

                $('timelineZoom').value =
                    String(closest);

                renderTimeline();
            }
        );

    $('recordedVideo')
        .addEventListener(
            'timeupdate',
            handleVideoTimeUpdate
        );

    $('recordedVideo')
        .addEventListener(
            'seeking',
            ()=>{
                updateTimeUI();
                updatePlayhead();
                renderObjects();
                renderConnectors();
            }
        );

    $('recordedVideo')
        .addEventListener(
            'play',
            updatePlayButtons
        );

    $('recordedVideo')
        .addEventListener(
            'pause',
            updatePlayButtons
        );

    $('recordedVideo')
        .addEventListener(
            'ended',
            updatePlayButtons
        );

    $('recordedVideo')
        .addEventListener(
            'loadedmetadata',
            ()=>{
                const d =
                    duration();

                $('seek').max =
                    String(d);

                $('seek').value =
                    String(
                        currentTime()
                    );

                setEditorLocked(false);

                renderAll();
            }
        );

    $('quickText')
        .addEventListener(
            'click',
            ()=>{
                addElement('comment');
            }
        );

    $('quickBox')
        .addEventListener(
            'click',
            ()=>{
                addElement('box');
            }
        );

    $('quickSkip')
        .addEventListener(
            'click',
            ()=>{
                addElement('skip');
            }
        );

    $('elementType')
        .addEventListener(
            'change',
            updateElementModalFields
        );

    $('elementCancel')
        .addEventListener(
            'click',
            closeElementModal
        );

    $('elementApply')
        .addEventListener(
            'click',
            applyElementModal
        );

    $('connectionCancel')
        .addEventListener(
            'click',
            closeConnectionModal
        );

    $('connectionApply')
        .addEventListener(
            'click',
            applyConnectionModal
        );

    $('connectionDelete')
        .addEventListener(
            'click',
            ()=>{
                if(
                    state.connectionModalTarget
                ){
                    deleteConnection(
                        state.connectionModalTarget
                    );

                    closeConnectionModal();
                }
            }
        );

    $('videoArea')
        .addEventListener(
            'contextmenu',
            event=>{
                if(
                    event.target.closest(
                        '.edit-object'
                    ) ||
                    event.target.closest(
                        '.connector'
                    )
                ){
                    return;
                }

                event.preventDefault();

                state.context = {
                    type:'empty'
                };

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

    $('timelineScroll')
        .addEventListener(
            'contextmenu',
            event=>{
                if(
                    event.target.closest(
                        '.track-item'
                    )
                ){
                    return;
                }

                event.preventDefault();

                state.context = {
                    type:'empty'
                };

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        );

    $('contextMenu')
        .addEventListener(
            'click',
            event=>{
                const button =
                    event.target.closest(
                        '[data-action]'
                    );

                if(!button){
                    return;
                }

                const action =
                    button.dataset.action;

                const context =
                    state.context;

                hideContextMenu();

                if(action === 'add-comment'){
                    addElement('comment');
                    return;
                }

                if(action === 'add-box'){
                    addElement('box');
                    return;
                }

                if(action === 'add-skip'){
                    addElement('skip');
                    return;
                }

                if(action === 'edit'){
                    if(
                        context?.type ===
                        'element'
                    ){
                        openElementModal(
                            getElement(
                                context.id
                            )
                        );
                    }

                    if(
                        context?.type ===
                        'connection'
                    ){
                        openConnectionModal(
                            getConnection(
                                context.id
                            )
                        );
                    }

                    return;
                }

                if(action === 'duplicate'){
                    duplicateSelected();
                    return;
                }

                if(action === 'delete'){
                    deleteSelected();
                    return;
                }

                if(action === 'connect'){
                    connectSelected();
                }
            }
        );

    document.addEventListener(
        'click',
        event=>{
            if(
                !event.target.closest(
                    '#contextMenu'
                )
            ){
                hideContextMenu();
            }
        }
    );

    document.addEventListener(
        'keydown',
        event=>{
            if(
                event.key === 'Delete' &&
                !event.target.matches(
                    'input,textarea,select'
                )
            ){
                deleteSelected();
            }

            if(
                event.key === 'Escape'
            ){
                hideContextMenu();
                closeElementModal();
                closeConnectionModal();
            }
        }
    );

    window.addEventListener(
        'pointermove',
        event=>{
            moveElementPointer(event);
            moveTimeline(event);
        }
    );

    window.addEventListener(
        'pointerup',
        ()=>{
            endElementPointer();
            endTimelineDrag();
        }
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
 * Initialisation
 * ======================================================= */

async function init(){

    buildPalette();

    try{
        await openDB();

        $('status').textContent =
            `準備完了 / v${APP_VERSION}`;

    }catch(err){
        $('status').textContent =
            'ローカル保存なし';

        message(
            'IndexedDBを利用できません。サーバー保存は利用できます。'
        );
    }

    initEvents();

    state.project =
        emptyProject();

    $('editorProjectName').value =
        state.project.name;

    setEditorLocked(true);

    renderAll();

    await renderProjectList();
}

init();
</script>

</body>
</html>

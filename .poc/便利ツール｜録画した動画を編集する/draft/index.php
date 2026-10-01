<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * 必要ディレクトリ:
 *   ./data/projects/
 *
 * サーバー側には編集プロジェクト(JSON)のみ保存します。
 * 動画本体はブラウザ側IndexedDBに保存します。
 *
 * v7
 */

const APP_VERSION = 7;
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
    return (bool)preg_match('/^[A-Za-z0-9_-]{8,100}$/', $id);
}

function projectPath(string $id): string
{
    return PROJECT_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

function getServerProjects(): array
{
    if (!is_dir(PROJECT_DIR)) {
        return [];
    }

    $result = [];

    foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $json = @file_get_contents($file);

        if ($json === false) {
            continue;
        }

        $data = json_decode($json, true);

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

function projectSummary(array $project): array
{
    return [
        'projectId' => (string)($project['projectId'] ?? ''),
        'name' => (string)($project['name'] ?? '名称未設定'),
        'videoName' => (string)($project['videoName'] ?? ''),
        'duration' => (float)($project['duration'] ?? 0),
        'savedAt' => (string)($project['savedAt'] ?? ''),
        'version' => (int)($project['version'] ?? 1),
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
            'serverCount' => count(getServerProjects()),
            'php' => PHP_VERSION
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map(
                'projectSummary',
                getServerProjects()
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

        $json = @file_get_contents($file);
        $project = $json === false ? null : json_decode($json, true);

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
                'message' =>
                    'data/projects に書き込めません。' .
                    'Apache/PHPの書込権限を確認してください。'
            ], 500);
        }

        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            jsonResponse([
                'ok' => false,
                'message' => 'JSONが不正です。'
            ], 400);
        }

        $id = trim((string)($payload['projectId'] ?? ''));

        if ($id === '') {
            try {
                $id = 'project-' . bin2hex(random_bytes(10));
            } catch (Throwable) {
                $id = 'project-' . uniqid('', true);
            }
        }

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $name = trim((string)($payload['name'] ?? ''));

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

        $existing = is_file(projectPath($id));
        $projects = getServerProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' =>
                    'サーバー保存上限に達しています。' .
                    'ローカル保存を使用してください。'
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

        if (
            $json === false ||
            @file_put_contents(
                projectPath($id),
                $json,
                LOCK_EX
            ) === false
        ) {
            jsonResponse([
                'ok' => false,
                'message' => '編集データを保存できませんでした。'
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
            ? trim((string)($payload['projectId'] ?? ''))
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
    --bg:#0e1013;
    --panel:#181c21;
    --panel2:#232830;
    --panel3:#2b3139;
    --border:#414952;
    --text:#f5f7fa;
    --muted:#929ba6;
    --blue:#267bd3;
    --green:#277849;
    --red:#a53b3b;
    --orange:#d78225;
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

body{overflow:hidden}

button,
input,
select,
textarea{
    font:inherit;
}

button{
    border:1px solid #555e68;
    background:#30363d;
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
button.danger{background:var(--red)}
button.orange{background:#8d571d}

input,
select,
textarea{
    width:100%;
    padding:7px 8px;
    color:#fff;
    background:#242a31;
    border:1px solid #555e68;
    border-radius:5px;
}

textarea{
    min-height:100px;
    resize:vertical;
}

input[type=number]{
    font-variant-numeric:tabular-nums;
}

.hidden{
    display:none!important;
}

#message{
    position:fixed;
    left:50%;
    top:60px;
    transform:translateX(-50%);
    z-index:20000;
    display:none;
    max-width:90vw;
    padding:10px 17px;
    border-radius:7px;
    background:#9e3d3d;
    box-shadow:0 12px 40px #000c;
    white-space:pre-wrap;
}

#message.ok{
    background:#277448;
}

header{
    position:absolute;
    left:0;
    right:0;
    top:0;
    height:50px;
    z-index:1000;
    display:flex;
    align-items:center;
    gap:7px;
    padding:0 9px;
    background:#1a1e23;
    border-bottom:1px solid #343a42;
}

header h1{
    margin:0 8px 0 2px;
    font-size:15px;
    white-space:nowrap;
}

#projectName{
    width:210px;
}

#status{
    margin-left:auto;
    color:#adb5be;
    font-size:12px;
    white-space:nowrap;
}

#home{
    position:absolute;
    inset:50px 0 0;
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

.help{
    color:var(--muted);
    font-size:12px;
    line-height:1.65;
}

.home-actions{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
    margin-top:15px;
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
    border:1px solid #414952;
    border-radius:6px;
}

.project-info{
    min-width:0;
}

.project-name{
    font-weight:600;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
}

.project-meta{
    margin-top:3px;
    color:#8d96a0;
    font-size:11px;
}

.project-actions{
    display:flex;
    gap:5px;
    flex-shrink:0;
}

#editor{
    position:absolute;
    inset:50px 0 0;
    display:none;
    flex-direction:column;
    background:#08090b;
}

#videoArea{
    position:relative;
    flex:1;
    min-height:0;
    display:flex;
    justify-content:center;
    align-items:center;
    overflow:hidden;
    background:#000;
}

#videoStage{
    position:relative;
    flex:none;
    background:#000;
    box-shadow:0 0 0 1px #111;
}

#recordedVideo{
    display:block;
    width:100%;
    height:100%;
    object-fit:fill;
}

#objects{
    position:absolute;
    inset:0;
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
    user-select:none;
    touch-action:none;
    cursor:move;
}

.edit-object.selected{
    outline:2px solid #fff;
    outline-offset:2px;
}

.edit-object.multi-selected{
    outline:2px dashed #61c6ff;
    outline-offset:3px;
}

.edit-object.connect-source{
    outline:3px solid var(--yellow);
    outline-offset:4px;
}

.edit-object.comment{
    display:flex;
    align-items:center;
    padding:5px 8px;
    overflow:hidden;
    white-space:pre-wrap;
    word-break:break-word;
}

.edit-object.box{
    background:transparent;
}

.edit-object.skip{
    background:#d7822524;
}

.skip-label{
    position:absolute;
    left:5px;
    top:5px;
    padding:2px 6px;
    color:#fff;
    background:#a96218;
    border-radius:4px;
    font-size:11px;
    pointer-events:none;
}

.resize-handle{
    position:absolute;
    right:-8px;
    bottom:-8px;
    width:16px;
    height:16px;
    border-radius:50%;
    border:1px solid #222;
    background:#fff;
    cursor:nwse-resize;
    display:none;
    z-index:30;
}

.edit-object.selected .resize-handle,
.edit-object.multi-selected .resize-handle{
    display:block;
}

.connection-point{
    position:absolute;
    width:13px;
    height:13px;
    margin:-6.5px;
    border:2px solid #fff;
    background:#247bd3;
    border-radius:50%;
    display:none;
    z-index:50;
    pointer-events:none;
}

.edit-object.selected .connection-point,
.edit-object.multi-selected .connection-point,
.edit-object.connect-source .connection-point{
    display:block;
}

.connection-point.p1{left:0;top:50%}
.connection-point.p2{left:50%;top:0}
.connection-point.p3{right:0;top:50%}
.connection-point.p4{left:50%;bottom:0}

.connector{
    fill:none;
    stroke-width:1.5;
    pointer-events:none;
}

.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:15;
    pointer-events:stroke;
    cursor:pointer;
}

.connector.selected{
    stroke:var(--yellow)!important;
    stroke-width:2.5!important;
}

#timeline{
    flex:none;
    height:235px;
    padding:8px 12px 10px;
    background:#191d22;
    border-top:1px solid #383e46;
    overflow:hidden;
}

.timeline-head{
    display:flex;
    align-items:center;
    gap:8px;
    height:31px;
}

#timeReadout{
    width:150px;
    flex:none;
    font-size:12px;
    font-variant-numeric:tabular-nums;
}

#seek{
    flex:1;
    min-width:0;
}

.timeline-scale{
    position:relative;
    height:20px;
    margin-left:86px;
    margin-right:0;
    color:#89929d;
    font-size:9px;
}

.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    white-space:nowrap;
}

.timeline-row{
    display:grid;
    grid-template-columns:78px 1fr;
    gap:8px;
    margin-top:5px;
    align-items:center;
}

.timeline-name{
    color:#c8ced5;
    font-size:11px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.track{
    position:relative;
    height:31px;
    border:1px solid #3b424a;
    background:#272c32;
    border-radius:4px;
}

.track-item{
    position:absolute;
    top:3px;
    height:23px;
    min-width:12px;
    border-radius:3px;
    cursor:grab;
    user-select:none;
    touch-action:none;
}

.track-item:active{
    cursor:grabbing;
}

.track-item.comment{background:#348fd1}
.track-item.box{background:#c84a4a}
.track-item.skip{background:#c87920}
.track-item.connection{background:#8755b7}

.track-item.selected{
    box-shadow:0 0 0 2px #fff;
}

.track-time{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0 17px;
    pointer-events:none;
    color:#fff;
    font-size:9px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.timeline-handle{
    position:absolute;
    top:0;
    width:12px;
    height:100%;
    z-index:5;
    cursor:ew-resize;
}

.timeline-handle.left{left:-6px}
.timeline-handle.right{right:-6px}

.timeline-handle::after{
    content:"";
    position:absolute;
    left:4px;
    top:3px;
    bottom:3px;
    width:4px;
    background:#fff;
    border-radius:2px;
}

.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:15000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:15px;
    background:#000b;
}

.modal{
    width:min(680px,96vw);
    max-height:92vh;
    overflow:auto;
    padding:18px;
    background:#20252b;
    border:1px solid #565d66;
    border-radius:9px;
    box-shadow:0 20px 70px #000c;
}

.modal h2{
    margin:0 0 13px;
    font-size:17px;
}

.grid2{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 10px;
}

.field{
    display:block;
    margin:8px 0;
    color:#c6cdd5;
    font-size:12px;
}

.full{
    grid-column:1/-1;
}

.modal-footer{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:14px;
}

.palette{
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:6px;
    margin-top:5px;
}

.palette button{
    height:30px;
    padding:0;
    border:2px solid #555;
}

.palette button.active{
    border-color:#fff;
    box-shadow:0 0 0 2px #267bd3;
}

#contextMenu{
    position:fixed;
    z-index:12000;
    display:none;
    width:255px;
    padding:5px;
    background:#292e34;
    border:1px solid #59606a;
    border-radius:7px;
    box-shadow:0 15px 45px #000d;
}

#contextMenu button{
    display:block;
    width:100%;
    text-align:left;
    background:transparent;
    border:0;
}

#contextMenu button:hover{
    background:#40464e;
}

.context-separator{
    height:1px;
    margin:5px 0;
    background:#454b53;
}

#dropHint{
    position:absolute;
    left:50%;
    top:50%;
    transform:translate(-50%,-50%);
    z-index:5;
    padding:12px 18px;
    color:#fff;
    background:#000a;
    border:1px solid #555;
    border-radius:7px;
    pointer-events:none;
}

#loading{
    position:absolute;
    inset:0;
    z-index:100;
    display:none;
    align-items:center;
    justify-content:center;
    background:#000b;
    color:#fff;
    font-size:14px;
}

#connectionHint{
    position:absolute;
    left:10px;
    top:10px;
    z-index:20;
    display:none;
    padding:7px 10px;
    background:#7c5c00dd;
    border:1px solid #e6c54c;
    border-radius:5px;
    font-size:11px;
    pointer-events:none;
}

@media(max-width:800px){
    header h1{
        display:none;
    }

    #projectName{
        width:140px;
    }

    header button{
        padding:6px 7px;
        font-size:11px;
    }

    #timeline{
        height:245px;
    }

    .grid2{
        grid-template-columns:1fr;
    }

    .full{
        grid-column:auto;
    }
}
</style>
</head>

<body>

<div id="message"></div>

<header>
    <h1>動画上直接編集</h1>

    <input
        id="projectName"
        type="text"
        maxlength="120"
        placeholder="プロジェクト名"
        disabled
    >

    <button id="newVideoBtn">動画を読み込む</button>
    <button id="openProjectBtn">保存を開く</button>
    <button id="saveServerBtn" class="success" disabled>保存</button>
    <button id="saveLocalBtn" disabled>ローカル保存</button>
    <button id="exportProjectBtn" disabled>編集データ</button>
    <button id="exportVideoBtn" disabled>動画を書き出す</button>
    <button id="backBtn">戻る</button>

    <span id="status">待機中</span>
</header>

<section id="home">
    <div class="home-card">
        <h2>動画上直接編集ツール</h2>

        <p class="help">
            動画を読み込んだ後、動画画面内で右クリックすると要素を追加できます。
            要素の上で右クリックすると編集・書式変更・削除ができます。
        </p>

        <p class="help">
            Shiftキーを押しながら2つの要素をクリックして選択し、
            右クリック →「選択した2要素を接続」で接続できます。
        </p>

        <div class="home-actions">
            <button id="homeVideoBtn" class="primary">
                動画を読み込んで編集開始
            </button>

            <button id="homeOpenBtn">
                保存済みプロジェクトを開く
            </button>

            <button id="homeImportBtn">
                編集データを読込
            </button>
        </div>

        <div class="project-list">
            <h3>サーバー保存</h3>
            <div id="projectList">
                読み込み中...
            </div>
        </div>
    </div>
</section>

<section id="editor">

    <div id="videoArea">

        <div id="videoStage">

            <video
                id="recordedVideo"
                preload="metadata"
                playsinline
                crossorigin="anonymous"
            ></video>

            <svg id="connectors"></svg>

            <div id="objects"></div>

            <div id="connectionHint">
                Shift+クリックで2要素を選択してください。
            </div>

            <div id="dropHint">
                動画読み込み中...
            </div>

        </div>

        <div id="loading">
            読み込み中...
        </div>
    </div>

    <div id="timeline">

        <div class="timeline-head">

            <span id="timeReadout">
                00:00.000 / 00:00.000
            </span>

            <input
                id="seek"
                type="range"
                min="0"
                max="1"
                step="0.001"
                value="0"
                disabled
            >

            <button id="playBtn" disabled>▶</button>
        </div>

        <div id="timelineScale" class="timeline-scale"></div>

        <div id="timelineRows"></div>
    </div>
</section>

<input
    id="videoFile"
    type="file"
    accept="video/*"
    class="hidden"
>

<input
    id="projectFile"
    type="file"
    accept=".json,application/json"
    class="hidden"
>

<div id="contextMenu">

    <button id="ctxAddText">＋ テキスト</button>
    <button id="ctxAddBox">＋ 強調枠</button>
    <button id="ctxAddSkip">＋ スキップ</button>

    <div class="context-separator"></div>

    <button id="ctxEdit">編集</button>
    <button id="ctxStyle">書式変更</button>
    <button id="ctxDelete">削除</button>

    <div class="context-separator"></div>

    <button id="ctxConnect">
        選択した2要素を接続
    </button>

    <button id="ctxDeleteConnection">
        選択中の接続を削除
    </button>
</div>

<div id="elementModal" class="modal-backdrop">

    <div class="modal">

        <h2 id="elementModalTitle">要素編集</h2>

        <div class="grid2">

            <label class="field">
                種類
                <select id="elementType">
                    <option value="comment">テキスト</option>
                    <option value="box">強調枠</option>
                    <option value="skip">スキップ</option>
                </select>
            </label>

            <label class="field">
                名前
                <input id="elementName" maxlength="80">
            </label>

            <label class="field full">
                テキスト
                <textarea id="elementText"></textarea>
            </label>

            <label class="field">
                開始秒
                <input id="elementStart" type="number" min="0" step="0.001">
            </label>

            <label class="field">
                終了秒
                <input id="elementEnd" type="number" min="0" step="0.001">
            </label>

            <label class="field">
                X (%)
                <input id="elementX" type="number" min="0" max="100" step="0.1">
            </label>

            <label class="field">
                Y (%)
                <input id="elementY" type="number" min="0" max="100" step="0.1">
            </label>

            <label class="field">
                幅 (%)
                <input id="elementW" type="number" min="1" max="100" step="0.1">
            </label>

            <label class="field">
                高さ (%)
                <input id="elementH" type="number" min="1" max="100" step="0.1">
            </label>

            <label class="field">
                フォントサイズ
                <input id="elementFontSize" type="number" min="8" max="200" step="1">
            </label>

            <label class="field">
                線幅
                <input id="elementBorderWidth" type="number" min="0" max="30" step="1">
            </label>

            <label class="field">
                透明度
                <input id="elementOpacity" type="number" min="0" max="1" step="0.05">
            </label>

            <label class="field">
                文字色
                <input id="elementTextColor" type="color">
            </label>

            <label class="field full">
                枠線色
                <div id="elementBorderPalette" class="palette"></div>
            </label>

            <label class="field">
                枠線種類
                <select id="elementBorderStyle">
                    <option value="solid">実線</option>
                    <option value="dashed">破線</option>
                    <option value="dotted">点線</option>
                </select>
            </label>

            <label class="field">
                背景透明度
                <input id="elementBgOpacity" type="number" min="0" max="1" step="0.05">
            </label>
        </div>

        <div class="modal-footer">
            <button id="elementCancel">キャンセル</button>
            <button id="elementApply" class="primary">適用</button>
        </div>

    </div>
</div>

<div id="connectionModal" class="modal-backdrop">

    <div class="modal">

        <h2>接続設定</h2>

        <div class="grid2">

            <label class="field">
                接続元
                <input id="connectionFromName" disabled>
            </label>

            <label class="field">
                接続先
                <input id="connectionToName" disabled>
            </label>

            <label class="field">
                接続元接点
                <select id="connectionFromPoint">
                    <option value="right">右</option>
                    <option value="left">左</option>
                    <option value="top">上</option>
                    <option value="bottom">下</option>
                </select>
            </label>

            <label class="field">
                接続先接点
                <select id="connectionToPoint">
                    <option value="left">左</option>
                    <option value="right">右</option>
                    <option value="top">上</option>
                    <option value="bottom">下</option>
                </select>
            </label>

            <label class="field">
                線幅
                <input id="connectionWidth" type="number" min="1" max="10" step="0.5">
            </label>

            <label class="field">
                線種
                <select id="connectionStyle">
                    <option value="solid">実線</option>
                    <option value="dashed">破線</option>
                    <option value="dotted">点線</option>
                </select>
            </label>

            <label class="field full">
                線色
                <input id="connectionColor" type="color">
            </label>

        </div>

        <div class="modal-footer">
            <button id="connectionCancel">キャンセル</button>
            <button id="connectionApply" class="primary">接続</button>
        </div>
    </div>
</div>

<div id="projectsModal" class="modal-backdrop">

    <div class="modal">

        <h2>保存済みプロジェクト</h2>

        <div id="projectsModalList"></div>

        <div class="modal-footer">
            <button id="projectsClose">閉じる</button>
        </div>

    </div>
</div>

<script>
'use strict';

/* =========================================================
 * DOM
 * ======================================================= */

const $ = id => document.getElementById(id);

const dom = {
    home: $('home'),
    editor: $('editor'),
    videoArea: $('videoArea'),
    stage: $('videoStage'),
    video: $('recordedVideo'),
    objects: $('objects'),
    connectors: $('connectors'),
    timelineRows: $('timelineRows'),
    timelineScale: $('timelineScale'),
    seek: $('seek'),
    play: $('playBtn'),
    readout: $('timeReadout'),
    status: $('status'),
    message: $('message'),
    dropHint: $('dropHint'),
    loading: $('loading'),
    context: $('contextMenu'),
    projectName: $('projectName'),
    projectList: $('projectList'),
    projectModalList: $('projectsModalList')
};

/* =========================================================
 * State
 * ======================================================= */

const state = {
    project: null,
    videoFile: null,
    videoUrl: '',
    ready: false,
    duration: 0,

    selected: new Set(),
    contextElementId: null,
    contextConnectionId: null,
    contextX: 50,
    contextY: 50,

    modalElementId: null,
    modalConnectionId: null,

    pointer: null,
    timelinePointer: null,

    dirty: false,

    db: null,
    dbReady: false
};

const COLORS = [
    '#ffffff',
    '#000000',
    '#ff3b30',
    '#ff9500',
    '#ffcc00',
    '#34c759',
    '#00c7be',
    '#32ade6',
    '#007aff',
    '#5856d6',
    '#af52de',
    '#ff2d55'
];

/* =========================================================
 * Utilities
 * ======================================================= */

function uid(prefix = 'id')
{
    if (window.crypto && crypto.randomUUID) {
        return prefix + '-' + crypto.randomUUID();
    }

    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2);
}

function clamp(value, min, max)
{
    return Math.max(min, Math.min(max, value));
}

function num(value, fallback = 0)
{
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
}

function formatTime(seconds)
{
    seconds = Math.max(0, num(seconds));

    const min = Math.floor(seconds / 60);
    const sec = Math.floor(seconds % 60);
    const ms = Math.floor((seconds % 1) * 1000);

    return (
        String(min).padStart(2, '0') + ':' +
        String(sec).padStart(2, '0') + '.' +
        String(ms).padStart(3, '0')
    );
}

function showMessage(message, ok = false)
{
    dom.message.textContent = message;
    dom.message.className = ok ? 'ok' : '';
    dom.message.style.display = 'block';

    clearTimeout(showMessage.timer);

    showMessage.timer = setTimeout(() => {
        dom.message.style.display = 'none';
    }, 3500);
}

function setStatus(message)
{
    dom.status.textContent = message;
}

function markDirty()
{
    if (!state.project) return;

    state.dirty = true;
    setStatus('未保存');
}

function deepClone(value)
{
    return JSON.parse(JSON.stringify(value));
}

function safeCapturePointer(element, event)
{
    if (!element || !event) return;

    if (
        typeof element.setPointerCapture !== 'function' ||
        typeof event.pointerId !== 'number'
    ) {
        return;
    }

    try {
        if (!element.hasPointerCapture ||
            !element.hasPointerCapture(event.pointerId)) {
            element.setPointerCapture(event.pointerId);
        }
    } catch (_) {
        /* PointerCaptureは補助機能なので失敗しても操作を止めない */
    }
}

function safeReleasePointer(element, event)
{
    if (!element || !event) return;

    try {
        if (
            typeof element.releasePointerCapture === 'function' &&
            typeof element.hasPointerCapture === 'function' &&
            element.hasPointerCapture(event.pointerId)
        ) {
            element.releasePointerCapture(event.pointerId);
        }
    } catch (_) {}
}

function escapeHtml(value)
{
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/* =========================================================
 * Project
 * ======================================================= */

function emptyProject()
{
    return {
        version: <?= APP_VERSION ?>,
        projectId: uid('project'),
        name: '',
        videoName: '',
        videoType: '',
        videoSize: 0,
        duration: 0,
        createdAt: new Date().toISOString(),
        savedAt: '',
        elements: [],
        connections: []
    };
}

function normalizeProject(project)
{
    const p = deepClone(project || emptyProject());

    if (!p.projectId) {
        p.projectId = uid('project');
    }

    if (!Array.isArray(p.elements)) {
        p.elements = [];
    }

    if (!Array.isArray(p.connections)) {
        p.connections = [];
    }

    p.version = <?= APP_VERSION ?>;

    p.elements = p.elements.map(el => ({
        id: el.id || uid('el'),
        type: ['comment', 'box', 'skip'].includes(el.type)
            ? el.type
            : 'comment',
        name: String(el.name || ''),
        text: String(el.text || ''),
        x: clamp(num(el.x, 30), 0, 100),
        y: clamp(num(el.y, 30), 0, 100),
        w: clamp(num(el.w, 25), 1, 100),
        h: clamp(num(el.h, 15), 1, 100),
        start: Math.max(0, num(el.start, 0)),
        end: Math.max(0.01, num(el.end, 5)),
        fontSize: clamp(num(el.fontSize, 28), 8, 200),
        borderWidth: clamp(num(el.borderWidth, 2), 0, 30),
        borderColor: el.borderColor || '#ff3b30',
        borderStyle: el.borderStyle || 'solid',
        textColor: el.textColor || '#ffffff',
        opacity: clamp(num(el.opacity, 1), 0, 1),
        bgOpacity: clamp(num(el.bgOpacity, 0), 0, 1)
    }));

    p.connections = p.connections.filter(c =>
        p.elements.some(e => e.id === c.from) &&
        p.elements.some(e => e.id === c.to)
    ).map(c => ({
        id: c.id || uid('conn'),
        from: c.from,
        to: c.to,
        fromPoint: c.fromPoint || 'right',
        toPoint: c.toPoint || 'left',
        color: c.color || '#ff3b30',
        width: clamp(num(c.width, 1.5), 1, 10),
        style: c.style || 'solid'
    }));

    return p;
}

function getElement(id)
{
    if (!state.project) return null;

    return state.project.elements.find(el => el.id === id) || null;
}

function getConnection(id)
{
    if (!state.project) return null;

    return state.project.connections.find(
        connection => connection.id === id
    ) || null;
}

function createElement(type, x, y)
{
    const duration = state.duration || 60;

    const defaults = {
        comment: {
            name: 'テキスト',
            text: 'ここを右クリックして編集',
            w: 30,
            h: 13,
            borderColor: '#ffffff',
            borderWidth: 2,
            bgOpacity: 0.35
        },
        box: {
            name: '強調枠',
            text: '',
            w: 35,
            h: 25,
            borderColor: '#ff3b30',
            borderWidth: 3,
            bgOpacity: 0
        },
        skip: {
            name: 'スキップ',
            text: 'SKIP',
            w: 28,
            h: 15,
            borderColor: '#ff9500',
            borderWidth: 2,
            bgOpacity: 0.18
        }
    }[type];

    return {
        id: uid('el'),
        type,
        name: defaults.name,
        text: defaults.text,
        x: clamp(x, 0, 100 - defaults.w),
        y: clamp(y, 0, 100 - defaults.h),
        w: defaults.w,
        h: defaults.h,
        start: clamp(state.duration ? dom.video.currentTime : 0, 0, duration),
        end: clamp(
            (state.duration ? dom.video.currentTime : 0) + 5,
            0.01,
            duration
        ),
        fontSize: 28,
        borderWidth: defaults.borderWidth,
        borderColor: defaults.borderColor,
        borderStyle: type === 'skip' ? 'dashed' : 'solid',
        textColor: '#ffffff',
        opacity: 1,
        bgOpacity: defaults.bgOpacity
    };
}

/* =========================================================
 * Video
 * ======================================================= */

function resetVideo()
{
    if (state.videoUrl) {
        URL.revokeObjectURL(state.videoUrl);
        state.videoUrl = '';
    }

    state.videoFile = null;
    state.ready = false;
    state.duration = 0;

    dom.video.removeAttribute('src');
    dom.video.load();

    dom.seek.disabled = true;
    dom.play.disabled = true;
    dom.dropHint.textContent = '動画を読み込んでください';
    dom.dropHint.style.display = 'block';
}

async function loadVideoFile(file)
{
    if (!file) return;

    if (!file.type.startsWith('video/')) {
        showMessage('動画ファイルを選択してください。');
        return;
    }

    state.ready = false;
    state.videoFile = file;

    dom.loading.style.display = 'flex';
    dom.dropHint.style.display = 'none';

    if (state.videoUrl) {
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoUrl = URL.createObjectURL(file);

    dom.video.src = state.videoUrl;
    dom.video.load();

    try {
        await new Promise((resolve, reject) => {
            const onLoaded = () => {
                cleanup();
                resolve();
            };

            const onError = () => {
                cleanup();
                reject(new Error('動画を読み込めませんでした。'));
            };

            const cleanup = () => {
                dom.video.removeEventListener('loadedmetadata', onLoaded);
                dom.video.removeEventListener('error', onError);
            };

            dom.video.addEventListener('loadedmetadata', onLoaded, {
                once: true
            });

            dom.video.addEventListener('error', onError, {
                once: true
            });
        });

        if (!Number.isFinite(dom.video.duration) ||
            dom.video.duration <= 0) {
            throw new Error('動画の長さを取得できませんでした。');
        }

        state.duration = dom.video.duration;
        state.ready = true;

        if (!state.project) {
            state.project = emptyProject();
        }

        state.project.videoName = file.name;
        state.project.videoType = file.type;
        state.project.videoSize = file.size;
        state.project.duration = state.duration;

        if (!state.project.name) {
            state.project.name =
                file.name.replace(/\.[^.]+$/, '');
        }

        dom.projectName.value = state.project.name;

        dom.seek.min = '0';
        dom.seek.max = String(state.duration);
        dom.seek.step = '0.001';
        dom.seek.value = '0';
        dom.seek.disabled = false;
        dom.play.disabled = false;

        dom.dropHint.style.display = 'none';
        setStatus('編集可能');
        markDirty();

        renderAll();
        await saveVideoToIndexedDB(file);

        showMessage(
            '動画の読み込みが完了しました。編集できます。',
            true
        );
    } catch (error) {
        state.ready = false;
        dom.dropHint.textContent = '動画を読み込めませんでした';
        dom.dropHint.style.display = 'block';

        showMessage(error.message || '動画読み込みエラー');
    } finally {
        dom.loading.style.display = 'none';
    }
}

/* =========================================================
 * IndexedDB
 * ======================================================= */

const DB_NAME = 'DirectVideoEditorDB';
const DB_VERSION = 2;

function openDB()
{
    if (state.dbReady && state.db) {
        return Promise.resolve(state.db);
    }

    return new Promise((resolve, reject) => {

        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = event => {
            const db = event.target.result;

            if (!db.objectStoreNames.contains('videos')) {
                db.createObjectStore('videos', {
                    keyPath: 'id'
                });
            }

            if (!db.objectStoreNames.contains('projects')) {
                db.createObjectStore('projects', {
                    keyPath: 'id'
                });
            }
        };

        request.onsuccess = () => {
            state.db = request.result;
            state.dbReady = true;
            resolve(request.result);
        };

        request.onerror = () => {
            reject(
                request.error ||
                new Error('IndexedDBを開けませんでした。')
            );
        };
    });
}

async function idbPut(storeName, data)
{
    const db = await openDB();

    return new Promise((resolve, reject) => {

        if (!data || !data.id) {
            reject(
                new Error(
                    'IndexedDB保存データにidがありません。'
                )
            );
            return;
        }

        const tx = db.transaction(storeName, 'readwrite');
        const store = tx.objectStore(storeName);

        let request;

        try {
            request = store.put(data);
        } catch (error) {
            reject(error);
            return;
        }

        request.onsuccess = () => resolve(request.result);
        request.onerror = () =>
            reject(request.error || new Error('IndexedDB保存失敗'));

        tx.onerror = () =>
            reject(tx.error || new Error('IndexedDB transaction error'));
    });
}

async function idbGet(storeName, id)
{
    const db = await openDB();

    return new Promise((resolve, reject) => {

        const tx = db.transaction(storeName, 'readonly');
        const request = tx.objectStore(storeName).get(id);

        request.onsuccess = () => resolve(request.result || null);
        request.onerror = () =>
            reject(request.error || new Error('IndexedDB読込失敗'));
    });
}

async function saveVideoToIndexedDB(file)
{
    if (!file) return;

    try {
        await idbPut('videos', {
            id: state.project.projectId,
            projectId: state.project.projectId,
            name: file.name,
            type: file.type,
            size: file.size,
            file
        });
    } catch (error) {
        console.warn('video IndexedDB save:', error);
        showMessage(
            '動画本体をブラウザ保存できませんでした。' +
            '編集データ自体は保存できます。'
        );
    }
}

async function loadVideoFromIndexedDB(projectId)
{
    const item = await idbGet('videos', projectId);

    if (!item || !item.file) {
        throw new Error(
            '保存された動画本体がブラウザにありません。' +
            '元の動画を読み込んでください。'
        );
    }

    await loadVideoFile(item.file);
}

/* =========================================================
 * Local project
 * ======================================================= */

async function saveProjectToIndexedDB()
{
    if (!state.project) return;

    await idbPut('projects', {
        id: state.project.projectId,
        projectId: state.project.projectId,
        project: deepClone(state.project),
        savedAt: new Date().toISOString()
    });
}

function downloadBlob(blob, filename)
{
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');

    a.href = url;
    a.download = filename;

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function exportProjectFile()
{
    if (!state.project) return;

    const data = deepClone(state.project);

    const blob = new Blob(
        [JSON.stringify(data, null, 2)],
        {type:'application/json'}
    );

    const filename =
        (state.project.name || 'project')
        .replace(/[\\/:*?"<>|]/g, '_') +
        '.json';

    downloadBlob(blob, filename);
    showMessage('編集データを書き出しました。', true);
}

async function importProjectFile(file)
{
    if (!file) return;

    try {
        const text = await file.text();
        const project = normalizeProject(JSON.parse(text));

        state.project = project;
        state.duration = num(project.duration);

        dom.projectName.value = project.name || '';

        showEditor();

        try {
            await loadVideoFromIndexedDB(project.projectId);
        } catch (_) {
            showMessage(
                '編集データは読み込みましたが、動画本体がありません。' +
                '同じ動画を読み込んでください。'
            );
        }

        renderAll();

    } catch (error) {
        showMessage(
            '編集データを読み込めませんでした。\n' +
            (error.message || '')
        );
    }
}

/* =========================================================
 * Server project
 * ======================================================= */

async function api(url, options = {})
{
    const response = await fetch(url, {
        cache: 'no-store',
        ...options,
        headers: {
            'Cache-Control': 'no-cache',
            ...(options.headers || {})
        }
    });

    const data = await response.json().catch(() => null);

    if (!response.ok || !data || data.ok === false) {
        throw new Error(
            data?.message ||
            `サーバーエラー (${response.status})`
        );
    }

    return data;
}

async function saveServer()
{
    if (!state.project) return;

    const name = dom.projectName.value.trim();

    if (!name) {
        showMessage('プロジェクト名を入力してください。');
        dom.projectName.focus();
        return;
    }

    state.project.name = name;

    try {
        setStatus('保存中...');

        await saveProjectToIndexedDB();

        const result = await api('?api=save', {
            method:'POST',
            headers:{
                'Content-Type':'application/json'
            },
            body:JSON.stringify(state.project)
        });

        state.project.projectId = result.projectId;
        state.project.savedAt = result.savedAt;
        state.dirty = false;

        setStatus('保存済み');

        showMessage('サーバーに保存しました。', true);

        await refreshProjectList();

    } catch (error) {

        if (
            /上限/.test(error.message) ||
            error.message.includes('ローカル保存')
        ) {
            try {
                await saveProjectToIndexedDB();

                state.dirty = false;

                setStatus('ローカル保存');

                showMessage(
                    'サーバー保存上限のためローカル保存しました。',
                    true
                );

            } catch (localError) {
                showMessage(
                    'サーバー保存とローカル保存の両方に失敗しました。\n' +
                    localError.message
                );
            }

            return;
        }

        setStatus('保存失敗');
        showMessage(error.message);
    }
}

async function loadServerProject(id)
{
    try {
        setStatus('読み込み中...');

        const result = await api(
            '?api=load&id=' + encodeURIComponent(id)
        );

        state.project = normalizeProject(result.project);
        state.duration = num(state.project.duration);

        dom.projectName.value = state.project.name || '';

        showEditor();

        try {
            await loadVideoFromIndexedDB(state.project.projectId);
        } catch (_) {
            showMessage(
                '編集データを読み込みました。' +
                '動画本体を読み込むと編集を開始できます。'
            );
            renderAll();
        }

        state.dirty = false;

    } catch (error) {
        showMessage(error.message);
    }
}

async function deleteServerProject(id)
{
    if (!confirm('このプロジェクトを削除しますか？')) {
        return;
    }

    try {
        await api('?api=delete', {
            method:'POST',
            headers:{
                'Content-Type':'application/json'
            },
            body:JSON.stringify({
                projectId:id
            })
        });

        showMessage('削除しました。', true);
        await refreshProjectList();

    } catch (error) {
        showMessage(error.message);
    }
}

async function refreshProjectList()
{
    try {
        const result = await api(
            '?api=list&_=' + Date.now()
        );

        renderProjectList(
            dom.projectList,
            result.projects
        );

        renderProjectList(
            dom.projectModalList,
            result.projects
        );

    } catch (error) {
        dom.projectList.textContent =
            'サーバー保存を取得できませんでした。';
    }
}

function renderProjectList(container, projects)
{
    if (!container) return;

    container.innerHTML = '';

    if (!projects.length) {
        container.innerHTML =
            '<div class="help">保存済みプロジェクトはありません。</div>';
        return;
    }

    projects.forEach(project => {

        const row = document.createElement('div');
        row.className = 'project';

        const info = document.createElement('div');
        info.className = 'project-info';

        const name = document.createElement('div');
        name.className = 'project-name';
        name.textContent = project.name || '名称未設定';

        const meta = document.createElement('div');
        meta.className = 'project-meta';

        meta.textContent =
            (project.videoName || '動画なし') +
            ' / ' +
            formatTime(project.duration || 0) +
            ' / ' +
            (project.savedAt || '');

        info.append(name, meta);

        const actions = document.createElement('div');
        actions.className = 'project-actions';

        const open = document.createElement('button');
        open.textContent = '開く';
        open.onclick = () => {
            $('projectsModal').style.display = 'none';
            loadServerProject(project.projectId);
        };

        const del = document.createElement('button');
        del.textContent = '削除';
        del.className = 'danger';
        del.onclick = () => deleteServerProject(project.projectId);

        actions.append(open, del);
        row.append(info, actions);
        container.append(row);
    });
}

/* =========================================================
 * Editor state
 * ======================================================= */

function showEditor()
{
    dom.home.style.display = 'none';
    dom.editor.style.display = 'flex';

    dom.projectName.disabled = false;
    $('saveServerBtn').disabled = false;
    $('saveLocalBtn').disabled = false;
    $('exportProjectBtn').disabled = false;
    $('exportVideoBtn').disabled = true;

    updateEditorAvailability();
}

function showHome()
{
    dom.editor.style.display = 'none';
    dom.home.style.display = 'flex';

    hideContext();

    state.selected.clear();
    state.contextElementId = null;
    state.contextConnectionId = null;

    refreshProjectList();
}

function updateEditorAvailability()
{
    const enabled = !!state.ready;

    dom.seek.disabled = !enabled;
    dom.play.disabled = !enabled;

    $('exportVideoBtn').disabled = !enabled;
}

function canEdit()
{
    if (!state.ready || !state.project || state.duration <= 0) {
        showMessage(
            '動画の読み込みが完了するまで編集できません。'
        );
        return false;
    }

    return true;
}

/* =========================================================
 * Rendering
 * ======================================================= */

function elementVisible(el)
{
    if (!state.ready) return false;

    const time = dom.video.currentTime;

    return time >= el.start && time <= el.end;
}

function renderAll()
{
    if (!state.project) return;

    renderStage();
    renderConnections();
    renderTimeline();
    updateTime();
}

function renderStage()
{
    dom.objects.innerHTML = '';

    if (!state.project) return;

    state.project.elements.forEach(el => {

        if (!elementVisible(el)) {
            return;
        }

        const node = document.createElement('div');

        node.className = 'edit-object ' + el.type;
        node.dataset.id = el.id;

        if (state.selected.has(el.id)) {
            if (state.selected.size > 1) {
                node.classList.add('multi-selected');
            } else {
                node.classList.add('selected');
            }
        }

        node.style.left = el.x + '%';
        node.style.top = el.y + '%';
        node.style.width = el.w + '%';
        node.style.height = el.h + '%';

        node.style.opacity = el.opacity;
        node.style.border =
            `${el.borderWidth}px ${el.borderStyle} ${el.borderColor}`;

        if (el.bgOpacity > 0) {
            node.style.backgroundColor =
                hexToRgba(el.borderColor, el.bgOpacity);
        } else if (el.type === 'box') {
            node.style.backgroundColor = 'transparent';
        }

        if (el.type === 'comment') {
            node.style.color = el.textColor;
            node.style.fontSize =
                Math.max(8, el.fontSize) + 'px';

            node.textContent = el.text;
        }

        if (el.type === 'box') {
            node.style.backgroundColor =
                hexToRgba(el.borderColor, el.bgOpacity);
        }

        if (el.type === 'skip') {
            const label = document.createElement('div');

            label.className = 'skip-label';
            label.textContent = el.text || 'SKIP';

            node.append(label);
        }

        [
            ['p1','left'],
            ['p2','top'],
            ['p3','right'],
            ['p4','bottom']
        ].forEach(([cls, point]) => {

            const pointNode =
                document.createElement('div');

            pointNode.className =
                'connection-point ' + cls;

            pointNode.dataset.point = point;

            node.append(pointNode);
        });

        const handle =
            document.createElement('div');

        handle.className = 'resize-handle';

        node.append(handle);

        node.addEventListener(
            'pointerdown',
            event => beginElementPointer(event, el.id),
            {passive:false}
        );

        node.addEventListener(
            'dblclick',
            event => {
                event.preventDefault();
                event.stopPropagation();

                if (canEdit()) {
                    openElementModal(el.id);
                }
            }
        );

        node.addEventListener(
            'contextmenu',
            event => {
                event.preventDefault();
                event.stopPropagation();

                if (!state.selected.has(el.id)) {
                    state.selected.clear();
                    state.selected.add(el.id);
                }

                state.contextElementId = el.id;
                state.contextConnectionId = null;

                renderStage();

                showContext(
                    event.clientX,
                    event.clientY
                );
            }
        );

        dom.objects.append(node);
    });
}

function renderConnections()
{
    dom.connectors.innerHTML = '';

    if (!state.project) return;

    state.project.connections.forEach(connection => {

        const from = getElement(connection.from);
        const to = getElement(connection.to);

        if (!from || !to) return;

        if (!elementVisible(from) ||
            !elementVisible(to)) {
            return;
        }

        const a = pointPosition(
            from,
            connection.fromPoint
        );

        const b = pointPosition(
            to,
            connection.toPoint
        );

        const line = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'line'
        );

        line.setAttribute('x1', a.x);
        line.setAttribute('y1', a.y);
        line.setAttribute('x2', b.x);
        line.setAttribute('y2', b.y);

        line.setAttribute(
            'stroke',
            connection.color
        );

        line.setAttribute(
            'stroke-width',
            connection.width
        );

        if (connection.style === 'dashed') {
            line.setAttribute(
                'stroke-dasharray',
                '6 4'
            );
        }

        if (connection.style === 'dotted') {
            line.setAttribute(
                'stroke-dasharray',
                '2 4'
            );
        }

        line.classList.add('connector');

        if (
            state.contextConnectionId === connection.id
        ) {
            line.classList.add('selected');
        }

        const hit = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'line'
        );

        hit.setAttribute('x1', a.x);
        hit.setAttribute('y1', a.y);
        hit.setAttribute('x2', b.x);
        hit.setAttribute('y2', b.y);

        hit.classList.add('connector-hit');

        hit.addEventListener(
            'contextmenu',
            event => {
                event.preventDefault();
                event.stopPropagation();

                state.contextConnectionId =
                    connection.id;

                state.contextElementId = null;

                renderConnections();

                showContext(
                    event.clientX,
                    event.clientY
                );
            }
        );

        dom.connectors.append(line, hit);
    });
}

function pointPosition(el, point)
{
    const stageWidth = dom.stage.clientWidth;
    const stageHeight = dom.stage.clientHeight;

    const x = el.x / 100 * stageWidth;
    const y = el.y / 100 * stageHeight;
    const w = el.w / 100 * stageWidth;
    const h = el.h / 100 * stageHeight;

    switch (point) {
        case 'left':
            return {x, y:y + h / 2};

        case 'top':
            return {x:x + w / 2, y};

        case 'bottom':
            return {x:x + w / 2, y:y + h};

        case 'right':
        default:
            return {x:x + w, y:y + h / 2};
    }
}

function renderTimeline()
{
    dom.timelineRows.innerHTML = '';

    if (!state.project || !state.duration) {
        dom.timelineScale.innerHTML = '';
        return;
    }

    renderTimelineScale();

    const groups = [
        {
            key:'comment',
            name:'テキスト',
            elements:state.project.elements.filter(
                el => el.type === 'comment'
            )
        },
        {
            key:'box',
            name:'強調枠',
            elements:state.project.elements.filter(
                el => el.type === 'box'
            )
        },
        {
            key:'skip',
            name:'スキップ',
            elements:state.project.elements.filter(
                el => el.type === 'skip'
            )
        },
        {
            key:'connection',
            name:'接続',
            elements:state.project.connections
        }
    ];

    groups.forEach(group => {

        if (!group.elements.length) return;

        const row = document.createElement('div');
        row.className = 'timeline-row';

        const name = document.createElement('div');
        name.className = 'timeline-name';
        name.textContent = group.name;

        const track = document.createElement('div');
        track.className = 'track';
        track.dataset.type = group.key;

        row.append(name, track);
        dom.timelineRows.append(row);

        group.elements.forEach(item => {

            if (group.key === 'connection') {
                renderConnectionTimelineItem(
                    track,
                    item
                );
            } else {
                renderElementTimelineItem(
                    track,
                    item
                );
            }
        });
    });
}

function renderTimelineScale()
{
    dom.timelineScale.innerHTML = '';

    const count = 10;

    for (let i = 0; i <= count; i++) {

        const span = document.createElement('span');

        const ratio = i / count;

        span.style.left =
            (ratio * 100) + '%';

        span.textContent =
            formatTime(state.duration * ratio);

        dom.timelineScale.append(span);
    }
}

function renderElementTimelineItem(track, el)
{
    const item = document.createElement('div');

    item.className =
        'track-item ' +
        el.type +
        (state.selected.has(el.id) ? ' selected' : '');

    item.dataset.id = el.id;

    const left =
        clamp(el.start / state.duration * 100, 0, 100);

    const width =
        clamp(
            (el.end - el.start) /
            state.duration * 100,
            0.3,
            100
        );

    item.style.left = left + '%';
    item.style.width = width + '%';

    const label = document.createElement('div');
    label.className = 'track-time';

    label.textContent =
        `${formatTime(el.start)} ～ ${formatTime(el.end)}`;

    item.append(label);

    const leftHandle = document.createElement('div');
    leftHandle.className = 'timeline-handle left';

    const rightHandle = document.createElement('div');
    rightHandle.className = 'timeline-handle right';

    item.append(leftHandle, rightHandle);

    item.addEventListener(
        'pointerdown',
        event => {
            if (
                event.target === leftHandle ||
                event.target === rightHandle
            ) {
                beginTimelineResize(
                    event,
                    el.id,
                    event.target === leftHandle
                        ? 'start'
                        : 'end'
                );
                return;
            }

            beginTimelineMove(event, el.id);
        },
        {passive:false}
    );

    item.addEventListener(
        'contextmenu',
        event => {
            event.preventDefault();
            event.stopPropagation();

            state.contextElementId = el.id;
            state.contextConnectionId = null;

            showContext(
                event.clientX,
                event.clientY
            );
        }
    );

    track.append(item);
}

function renderConnectionTimelineItem(track, connection)
{
    const item = document.createElement('div');

    item.className = 'track-item connection';

    item.style.left = '0%';
    item.style.width = '100%';

    const label = document.createElement('div');
    label.className = 'track-time';

    label.textContent =
        getElement(connection.from)?.name +
        ' → ' +
        getElement(connection.to)?.name;

    item.append(label);

    item.addEventListener(
        'contextmenu',
        event => {
            event.preventDefault();
            event.stopPropagation();

            state.contextConnectionId =
                connection.id;

            state.contextElementId = null;

            showContext(
                event.clientX,
                event.clientY
            );
        }
    );

    track.append(item);
}

function updateTime()
{
    if (!state.duration) {
        dom.readout.textContent =
            '00:00.000 / 00:00.000';
        return;
    }

    const current = dom.video.currentTime || 0;

    dom.readout.textContent =
        `${formatTime(current)} / ${formatTime(state.duration)}`;

    if (
        document.activeElement !== dom.seek
    ) {
        dom.seek.value = String(current);
    }
}

/* =========================================================
 * Stage sizing
 * ======================================================= */

function fitStage()
{
    if (!state.ready ||
        !dom.video.videoWidth ||
        !dom.video.videoHeight) {
        return;
    }

    const areaWidth = dom.videoArea.clientWidth;
    const areaHeight = dom.videoArea.clientHeight;

    const ratio =
        dom.video.videoWidth /
        dom.video.videoHeight;

    let width = areaWidth;
    let height = width / ratio;

    if (height > areaHeight) {
        height = areaHeight;
        width = height * ratio;
    }

    dom.stage.style.width = Math.max(1, width) + 'px';
    dom.stage.style.height = Math.max(1, height) + 'px';

    renderStage();
    renderConnections();
}

/* =========================================================
 * Stage pointer
 * ======================================================= */

function stagePositionFromPointer(event)
{
    const rect =
        dom.stage.getBoundingClientRect();

    return {
        x: clamp(
            (event.clientX - rect.left) /
            rect.width * 100,
            0,
            100
        ),
        y: clamp(
            (event.clientY - rect.top) /
            rect.height * 100,
            0,
            100
        )
    };
}

function beginElementPointer(event, id)
{
    if (!canEdit()) return;

    event.preventDefault();
    event.stopPropagation();

    const el = getElement(id);

    if (!el) return;

    const target = event.currentTarget;

    const resizing =
        event.target.classList.contains(
            'resize-handle'
        );

    if (event.shiftKey) {

        if (state.selected.has(id)) {
            state.selected.delete(id);
        } else {
            state.selected.add(id);
        }

        renderStage();
        renderConnections();
        return;
    }

    if (!state.selected.has(id)) {
        state.selected.clear();
        state.selected.add(id);
    }

    if (state.selected.size > 1) {
        renderStage();
        return;
    }

    const rect =
        dom.stage.getBoundingClientRect();

    state.pointer = {
        id,
        resizing,
        startClientX:event.clientX,
        startClientY:event.clientY,
        startX:el.x,
        startY:el.y,
        startW:el.w,
        startH:el.h,
        rect
    };

    safeCapturePointer(target, event);

    const move = e => updateElementPointer(e);
    const up = e => endElementPointer(e, target, move, up);

    target.addEventListener(
        'pointermove',
        move,
        {passive:false}
    );

    target.addEventListener(
        'pointerup',
        up,
        {once:true}
    );

    target.addEventListener(
        'pointercancel',
        up,
        {once:true}
    );
}

function updateElementPointer(event)
{
    const p = state.pointer;

    if (!p) return;

    event.preventDefault();

    const el = getElement(p.id);

    if (!el) return;

    const dx =
        (event.clientX - p.startClientX) /
        p.rect.width * 100;

    const dy =
        (event.clientY - p.startClientY) /
        p.rect.height * 100;

    if (p.resizing) {

        el.w = clamp(
            p.startW + dx,
            1,
            100 - p.startX
        );

        el.h = clamp(
            p.startH + dy,
            1,
            100 - p.startY
        );

    } else {

        el.x = clamp(
            p.startX + dx,
            0,
            100 - el.w
        );

        el.y = clamp(
            p.startY + dy,
            0,
            100 - el.h
        );
    }

    markDirty();
    renderStage();
    renderConnections();
    renderTimeline();
}

function endElementPointer(
    event,
    target,
    move,
    up
){
    if (!state.pointer) return;

    safeReleasePointer(target, event);

    target.removeEventListener(
        'pointermove',
        move
    );

    state.pointer = null;
}

/* =========================================================
 * Timeline pointer
 * ======================================================= */

function beginTimelineMove(event, id)
{
    if (!canEdit()) return;

    event.preventDefault();
    event.stopPropagation();

    const el = getElement(id);

    if (!el) return;

    state.selected.clear();
    state.selected.add(id);

    const track = event.currentTarget;
    const rect = track.getBoundingClientRect();

    state.timelinePointer = {
        id,
        mode:'move',
        startX:event.clientX,
        originalStart:el.start,
        originalEnd:el.end,
        rect,
        target:event.currentTarget
    };

    safeCapturePointer(track, event);

    const move = e =>
        updateTimelinePointer(e);

    const up = e =>
        endTimelinePointer(e, track, move);

    track.addEventListener(
        'pointermove',
        move,
        {passive:false}
    );

    track.addEventListener(
        'pointerup',
        up,
        {once:true}
    );

    track.addEventListener(
        'pointercancel',
        up,
        {once:true}
    );

    renderStage();
    renderTimeline();
}

function beginTimelineResize(event, id, mode)
{
    if (!canEdit()) return;

    event.preventDefault();
    event.stopPropagation();

    const el = getElement(id);

    if (!el) return;

    const track =
        event.currentTarget.closest('.track');

    if (!track) return;

    const rect = track.getBoundingClientRect();

    state.timelinePointer = {
        id,
        mode,
        startX:event.clientX,
        originalStart:el.start,
        originalEnd:el.end,
        rect,
        target:track
    };

    safeCapturePointer(track, event);

    const move = e =>
        updateTimelinePointer(e);

    const up = e =>
        endTimelinePointer(e, track, move);

    track.addEventListener(
        'pointermove',
        move,
        {passive:false}
    );

    track.addEventListener(
        'pointerup',
        up,
        {once:true}
    );

    track.addEventListener(
        'pointercancel',
        up,
        {once:true}
    );
}

function updateTimelinePointer(event)
{
    const p = state.timelinePointer;

    if (!p) return;

    const el = getElement(p.id);

    if (!el) return;

    event.preventDefault();

    const dx =
        (event.clientX - p.startX) /
        p.rect.width *
        state.duration;

    if (p.mode === 'move') {

        const length =
            p.originalEnd -
            p.originalStart;

        let start =
            p.originalStart + dx;

        start = clamp(
            start,
            0,
            state.duration - length
        );

        el.start = start;
        el.end = start + length;

    } else if (p.mode === 'start') {

        el.start = clamp(
            p.originalStart + dx,
            0,
            p.originalEnd - 0.05
        );

    } else if (p.mode === 'end') {

        el.end = clamp(
            p.originalEnd + dx,
            p.originalStart + 0.05,
            state.duration
        );
    }

    markDirty();
    renderStage();
    renderTimeline();
}

function endTimelinePointer(event, target, move)
{
    safeReleasePointer(target, event);

    target.removeEventListener(
        'pointermove',
        move
    );

    state.timelinePointer = null;
}

/* =========================================================
 * Context menu
 * ======================================================= */

function showContext(x, y)
{
    const menu = dom.context;

    menu.style.display = 'block';

    const width = menu.offsetWidth;
    const height = menu.offsetHeight;

    menu.style.left =
        Math.min(
            x,
            window.innerWidth - width - 8
        ) + 'px';

    menu.style.top =
        Math.min(
            y,
            window.innerHeight - height - 8
        ) + 'px';

    const hasElement =
        !!state.contextElementId;

    const hasConnection =
        !!state.contextConnectionId;

    const twoSelected =
        state.selected.size === 2;

    $('ctxEdit').style.display =
        hasElement ? 'block' : 'none';

    $('ctxStyle').style.display =
        hasElement ? 'block' : 'none';

    $('ctxDelete').style.display =
        hasElement ? 'block' : 'none';

    $('ctxConnect').style.display =
        twoSelected ? 'block' : 'none';

    $('ctxDeleteConnection').style.display =
        hasConnection ? 'block' : 'none';

    $('ctxAddText').style.display =
        state.ready ? 'block' : 'none';

    $('ctxAddBox').style.display =
        state.ready ? 'block' : 'none';

    $('ctxAddSkip').style.display =
        state.ready ? 'block' : 'none';
}

function hideContext()
{
    dom.context.style.display = 'none';
}

/* =========================================================
 * Add element
 * ======================================================= */

function addElement(type)
{
    hideContext();

    if (!canEdit()) return;

    const el = createElement(
        type,
        state.contextX,
        state.contextY
    );

    state.project.elements.push(el);

    state.selected.clear();
    state.selected.add(el.id);

    markDirty();
    renderAll();

    openElementModal(el.id);
}

/* =========================================================
 * Element modal
 * ======================================================= */

function openElementModal(id)
{
    if (!canEdit()) return;

    const el = getElement(id);

    if (!el) return;

    state.modalElementId = id;

    $('elementModalTitle').textContent =
        `${el.name || '要素'}の編集`;

    $('elementType').value = el.type;
    $('elementName').value = el.name;
    $('elementText').value = el.text;

    $('elementStart').value =
        el.start.toFixed(3);

    $('elementEnd').value =
        el.end.toFixed(3);

    $('elementX').value =
        el.x.toFixed(1);

    $('elementY').value =
        el.y.toFixed(1);

    $('elementW').value =
        el.w.toFixed(1);

    $('elementH').value =
        el.h.toFixed(1);

    $('elementFontSize').value =
        el.fontSize;

    $('elementBorderWidth').value =
        el.borderWidth;

    $('elementOpacity').value =
        el.opacity;

    $('elementTextColor').value =
        normalizeColor(el.textColor);

    $('elementBorderStyle').value =
        el.borderStyle;

    $('elementBgOpacity').value =
        el.bgOpacity;

    renderPalette(el.borderColor);

    $('elementModal').style.display = 'flex';
}

function renderPalette(activeColor)
{
    const palette =
        $('elementBorderPalette');

    palette.innerHTML = '';

    COLORS.forEach(color => {

        const button =
            document.createElement('button');

        button.type = 'button';
        button.dataset.color = color;
        button.style.background = color;

        if (
            normalizeColor(activeColor) ===
            normalizeColor(color)
        ) {
            button.classList.add('active');
        }

        button.addEventListener(
            'click',
            () => {
                document
                    .querySelectorAll(
                        '#elementBorderPalette button'
                    )
                    .forEach(
                        b => b.classList.remove('active')
                    );

                button.classList.add('active');
                button.dataset.selected = '1';
            }
        );

        palette.append(button);
    });
}

function selectedPaletteColor()
{
    const active =
        document.querySelector(
            '#elementBorderPalette button.active,' +
            '#elementBorderPalette button[data-selected="1"]'
        );

    return active?.dataset.color || '#ffffff';
}

function applyElementModal()
{
    const el =
        getElement(state.modalElementId);

    if (!el) return;

    const start =
        clamp(
            num($('elementStart').value, 0),
            0,
            state.duration
        );

    const end =
        clamp(
            num($('elementEnd').value, state.duration),
            start + 0.05,
            state.duration
        );

    el.type =
        ['comment','box','skip'].includes(
            $('elementType').value
        )
            ? $('elementType').value
            : 'comment';

    el.name =
        $('elementName').value.trim() ||
        '要素';

    el.text =
        $('elementText').value;

    el.start = start;
    el.end = end;

    el.x =
        clamp(
            num($('elementX').value, el.x),
            0,
            100 - el.w
        );

    el.y =
        clamp(
            num($('elementY').value, el.y),
            0,
            100 - el.h
        );

    el.w =
        clamp(
            num($('elementW').value, el.w),
            1,
            100 - el.x
        );

    el.h =
        clamp(
            num($('elementH').value, el.h),
            1,
            100 - el.y
        );

    el.fontSize =
        clamp(
            num($('elementFontSize').value, 28),
            8,
            200
        );

    el.borderWidth =
        clamp(
            num($('elementBorderWidth').value, 2),
            0,
            30
        );

    el.opacity =
        clamp(
            num($('elementOpacity').value, 1),
            0,
            1
        );

    el.bgOpacity =
        clamp(
            num($('elementBgOpacity').value, 0),
            0,
            1
        );

    el.textColor =
        $('elementTextColor').value ||
        '#ffffff';

    el.borderColor =
        selectedPaletteColor();

    el.borderStyle =
        $('elementBorderStyle').value;

    markDirty();

    $('elementModal').style.display = 'none';

    renderAll();
}

/* =========================================================
 * Connection
 * ======================================================= */

function openConnectionModal(connection = null)
{
    if (!canEdit()) return;

    let from;
    let to;

    if (connection) {
        from = getElement(connection.from);
        to = getElement(connection.to);

        state.modalConnectionId = connection.id;
    } else {
        const ids = [...state.selected];

        if (ids.length !== 2) {
            showMessage(
                'Shiftキーを押しながら2つの要素を選択してください。'
            );
            return;
        }

        from = getElement(ids[0]);
        to = getElement(ids[1]);

        state.modalConnectionId = null;
    }

    if (!from || !to) return;

    $('connectionFromName').value =
        from.name || from.id;

    $('connectionToName').value =
        to.name || to.id;

    $('connectionFromPoint').value =
        connection?.fromPoint || 'right';

    $('connectionToPoint').value =
        connection?.toPoint || 'left';

    $('connectionWidth').value =
        connection?.width || 1.5;

    $('connectionStyle').value =
        connection?.style || 'solid';

    $('connectionColor').value =
        connection?.color ||
        from.borderColor ||
        '#ffffff';

    $('connectionModal').style.display = 'flex';
}

function applyConnectionModal()
{
    const ids = [...state.selected];

    let connection =
        state.modalConnectionId
            ? getConnection(state.modalConnectionId)
            : null;

    if (!connection) {

        if (ids.length !== 2) {
            showMessage(
                '接続には2つの要素を選択してください。'
            );
            return;
        }

        connection = {
            id:uid('conn'),
            from:ids[0],
            to:ids[1],
            fromPoint:'right',
            toPoint:'left',
            color:'#ffffff',
            width:1.5,
            style:'solid'
        };

        state.project.connections.push(connection);
    }

    connection.fromPoint =
        $('connectionFromPoint').value;

    connection.toPoint =
        $('connectionToPoint').value;

    connection.width =
        clamp(
            num($('connectionWidth').value, 1.5),
            1,
            10
        );

    connection.style =
        $('connectionStyle').value;

    connection.color =
        $('connectionColor').value ||
        '#ffffff';

    state.contextConnectionId =
        connection.id;

    state.contextElementId = null;

    markDirty();

    $('connectionModal').style.display = 'none';

    renderAll();
}

/* =========================================================
 * Delete
 * ======================================================= */

function deleteElement(id)
{
    const el = getElement(id);

    if (!el) return;

    if (!confirm(
        `「${el.name || '要素'}」を削除しますか？`
    )) {
        return;
    }

    state.project.elements =
        state.project.elements.filter(
            item => item.id !== id
        );

    state.project.connections =
        state.project.connections.filter(
            connection =>
                connection.from !== id &&
                connection.to !== id
        );

    state.selected.delete(id);
    state.contextElementId = null;

    markDirty();
    hideContext();
    renderAll();
}

function deleteConnection(id)
{
    const connection = getConnection(id);

    if (!connection) return;

    if (!confirm('この接続を削除しますか？')) {
        return;
    }

    state.project.connections =
        state.project.connections.filter(
            c => c.id !== id
        );

    state.contextConnectionId = null;

    markDirty();
    hideContext();
    renderAll();
}

/* =========================================================
 * Video export
 * ======================================================= */

function chooseRecorderMime()
{
    const candidates = [
        'video/mp4;codecs="avc1.42E01E,mp4a.40.2"',
        'video/mp4',
        'video/webm;codecs=vp9,opus',
        'video/webm;codecs=vp8,opus',
        'video/webm'
    ];

    if (!window.MediaRecorder) {
        return '';
    }

    return candidates.find(
        type => MediaRecorder.isTypeSupported(type)
    ) || '';
}

async function exportVideo()
{
    if (!canEdit()) return;

    if (!window.MediaRecorder) {
        showMessage(
            'このブラウザは動画書き出しに対応していません。'
        );
        return;
    }

    const mime = chooseRecorderMime();

    if (!mime) {
        showMessage(
            '利用できる動画エンコード形式がありません。'
        );
        return;
    }

    const canvas =
        document.createElement('canvas');

    const width =
        dom.video.videoWidth || 1280;

    const height =
        dom.video.videoHeight || 720;

    canvas.width = width;
    canvas.height = height;

    const ctx =
        canvas.getContext('2d');

    if (!ctx) {
        showMessage('Canvasを利用できません。');
        return;
    }

    const stream =
        canvas.captureStream(30);

    const chunks = [];

    let recorder;

    try {
        recorder = new MediaRecorder(
            stream,
            {
                mimeType:mime,
                videoBitsPerSecond:8000000
            }
        );
    } catch (error) {
        showMessage(
            '動画エンコーダを開始できませんでした。\n' +
            error.message
        );
        return;
    }

    const originalTime =
        dom.video.currentTime;

    const wasPlaying =
        !dom.video.paused;

    dom.loading.style.display = 'flex';
    setStatus('動画を書き出し中...');

    recorder.ondataavailable = event => {
        if (event.data && event.data.size > 0) {
            chunks.push(event.data);
        }
    };

    const stopped =
        new Promise(resolve => {
            recorder.onstop = resolve;
        });

    recorder.start(200);

    dom.video.pause();
    dom.video.currentTime = 0;

    await waitForSeek();

    const draw = () => {

        if (dom.video.ended) {
            return;
        }

        ctx.clearRect(
            0,
            0,
            width,
            height
        );

        ctx.drawImage(
            dom.video,
            0,
            0,
            width,
            height
        );

        drawElementsToCanvas(
            ctx,
            width,
            height,
            dom.video.currentTime
        );

        if (
            !dom.video.paused &&
            !dom.video.ended
        ) {
            requestAnimationFrame(draw);
        }
    };

    dom.video.play().catch(() => {});

    draw();

    await new Promise(resolve => {

        const check = () => {

            if (
                dom.video.ended ||
                dom.video.currentTime >=
                state.duration - 0.03
            ) {
                resolve();
                return;
            }

            requestAnimationFrame(check);
        };

        check();
    });

    dom.video.pause();

    if (recorder.state !== 'inactive') {
        recorder.stop();
    }

    await stopped;

    dom.video.currentTime = originalTime;

    if (wasPlaying) {
        dom.video.play().catch(() => {});
    }

    const extension =
        mime.includes('mp4')
            ? 'mp4'
            : 'webm';

    const blob = new Blob(
        chunks,
        {type:mime}
    );

    const filename =
        (state.project.name || 'edited-video')
            .replace(/[\\/:*?"<>|]/g, '_') +
        '.' +
        extension;

    downloadBlob(blob, filename);

    dom.loading.style.display = 'none';

    setStatus('編集完了');

    showMessage(
        `動画を書き出しました。\n形式: ${mime}`,
        true
    );
}

function drawElementsToCanvas(
    ctx,
    width,
    height,
    time
)
{
    if (!state.project) return;

    state.project.elements.forEach(el => {

        if (
            time < el.start ||
            time > el.end
        ) {
            return;
        }

        const x =
            el.x / 100 * width;

        const y =
            el.y / 100 * height;

        const w =
            el.w / 100 * width;

        const h =
            el.h / 100 * height;

        ctx.save();

        ctx.globalAlpha = el.opacity;

        ctx.strokeStyle =
            el.borderColor;

        ctx.lineWidth =
            Math.max(
                1,
                el.borderWidth *
                width /
                1280
            );

        if (el.borderStyle === 'dashed') {
            ctx.setLineDash([10, 7]);
        }

        if (el.borderStyle === 'dotted') {
            ctx.setLineDash([2, 5]);
        }

        if (el.bgOpacity > 0) {
            ctx.fillStyle =
                hexToRgba(
                    el.borderColor,
                    el.bgOpacity
                );

            ctx.fillRect(
                x,
                y,
                w,
                h
            );
        }

        ctx.strokeRect(
            x,
            y,
            w,
            h
        );

        if (
            el.type === 'comment' ||
            el.type === 'skip'
        ) {

            ctx.setLineDash([]);

            ctx.fillStyle =
                el.textColor;

            ctx.font =
                `${el.fontSize * width / 1280}px sans-serif`;

            ctx.textBaseline = 'top';

            const text =
                el.type === 'skip'
                    ? (el.text || 'SKIP')
                    : el.text;

            const lines =
                String(text).split('\n');

            const lineHeight =
                el.fontSize *
                width /
                1280 *
                1.25;

            lines.forEach(
                (line, index) => {

                    ctx.fillText(
                        line,
                        x + 8,
                        y + 8 +
                        index * lineHeight
                    );
                }
            );
        }

        ctx.restore();
    });

    state.project.connections.forEach(connection => {

        const from =
            getElement(connection.from);

        const to =
            getElement(connection.to);

        if (!from || !to) return;

        if (
            time < from.start ||
            time > from.end ||
            time < to.start ||
            time > to.end
        ) {
            return;
        }

        const a =
            pointPositionCanvas(
                from,
                connection.fromPoint,
                width,
                height
            );

        const b =
            pointPositionCanvas(
                to,
                connection.toPoint,
                width,
                height
            );

        ctx.save();

        ctx.strokeStyle =
            connection.color;

        ctx.lineWidth =
            connection.width *
            width /
            1280;

        if (connection.style === 'dashed') {
            ctx.setLineDash([8, 5]);
        }

        if (connection.style === 'dotted') {
            ctx.setLineDash([2, 4]);
        }

        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.stroke();

        ctx.restore();
    });
}

function pointPositionCanvas(
    el,
    point,
    width,
    height
)
{
    const x = el.x / 100 * width;
    const y = el.y / 100 * height;
    const w = el.w / 100 * width;
    const h = el.h / 100 * height;

    switch (point) {
        case 'left':
            return {x, y:y + h / 2};

        case 'top':
            return {x:x + w / 2, y};

        case 'bottom':
            return {x:x + w / 2, y:y + h};

        default:
            return {x:x + w, y:y + h / 2};
    }
}

function waitForSeek()
{
    return new Promise(resolve => {

        const check = () => {

            if (
                Math.abs(
                    dom.video.currentTime
                ) < 0.05
            ) {
                resolve();
            } else {
                requestAnimationFrame(check);
            }
        };

        check();
    });
}

/* =========================================================
 * Color
 * ======================================================= */

function normalizeColor(color)
{
    if (!color) return '#ffffff';

    if (/^#[0-9a-f]{6}$/i.test(color)) {
        return color.toLowerCase();
    }

    return '#ffffff';
}

function hexToRgba(hex, alpha)
{
    hex = normalizeColor(hex);

    const r =
        parseInt(hex.slice(1,3),16);

    const g =
        parseInt(hex.slice(3,5),16);

    const b =
        parseInt(hex.slice(5,7),16);

    return `rgba(${r},${g},${b},${alpha})`;
}

/* =========================================================
 * Events
 * ======================================================= */

function bind(id, event, handler)
{
    const element = $(id);

    if (!element) {
        console.warn(
            `イベント対象が存在しません: #${id}`
        );
        return;
    }

    element.addEventListener(
        event,
        handler
    );
}

bind('newVideoBtn','click',() => {
    $('videoFile').click();
});

bind('homeVideoBtn','click',() => {
    $('videoFile').click();
});

bind('openProjectBtn','click',() => {
    $('projectsModal').style.display = 'flex';
    refreshProjectList();
});

bind('homeOpenBtn','click',() => {
    $('projectsModal').style.display = 'flex';
    refreshProjectList();
});

bind('homeImportBtn','click',() => {
    $('projectFile').click();
});

bind('saveServerBtn','click',saveServer);

bind('saveLocalBtn','click',async () => {
    try {
        if (!state.project) return;

        state.project.name =
            dom.projectName.value.trim();

        if (!state.project.name) {
            showMessage(
                'プロジェクト名を入力してください。'
            );
            return;
        }

        await saveProjectToIndexedDB();

        state.dirty = false;

        setStatus('ローカル保存');

        showMessage(
            'ブラウザのIndexedDBへ保存しました。',
            true
        );

    } catch (error) {
        showMessage(
            'ローカル保存に失敗しました。\n' +
            error.message
        );
    }
});

bind('exportProjectBtn','click',exportProjectFile);

bind('exportVideoBtn','click',exportVideo);

bind('backBtn','click',() => {

    if (
        state.dirty &&
        !confirm(
            '未保存の変更があります。戻りますか？'
        )
    ) {
        return;
    }

    showHome();
});

bind('playBtn','click',() => {

    if (!canEdit()) return;

    if (dom.video.paused) {
        dom.video.play().catch(error => {
            showMessage(error.message);
        });
    } else {
        dom.video.pause();
    }
});

bind('videoFile','change',event => {

    const file =
        event.target.files?.[0];

    if (file) {

        if (!state.project) {
            state.project = emptyProject();
        }

        loadVideoFile(file);
    }

    event.target.value = '';
});

bind('projectFile','change',event => {

    const file =
        event.target.files?.[0];

    if (file) {
        importProjectFile(file);
    }

    event.target.value = '';
});

bind('projectsClose','click',() => {
    $('projectsModal').style.display = 'none';
});

bind('elementCancel','click',() => {
    $('elementModal').style.display = 'none';
});

bind('elementApply','click',applyElementModal);

bind('connectionCancel','click',() => {
    $('connectionModal').style.display = 'none';
});

bind(
    'connectionApply',
    'click',
    applyConnectionModal
);

bind('ctxAddText','click',() => {
    addElement('comment');
});

bind('ctxAddBox','click',() => {
    addElement('box');
});

bind('ctxAddSkip','click',() => {
    addElement('skip');
});

bind('ctxEdit','click',() => {

    const id = state.contextElementId;

    hideContext();

    if (id) {
        openElementModal(id);
    }
});

bind('ctxStyle','click',() => {

    const id = state.contextElementId;

    hideContext();

    if (id) {
        openElementModal(id);
    }
});

bind('ctxDelete','click',() => {

    const id = state.contextElementId;

    if (id) {
        deleteElement(id);
    }
});

bind('ctxConnect','click',() => {

    hideContext();

    if (state.selected.size === 2) {
        openConnectionModal();
    }
});

bind('ctxDeleteConnection','click',() => {

    const id =
        state.contextConnectionId;

    if (id) {
        deleteConnection(id);
    }
});

bind('projectName','input',() => {

    if (!state.project) return;

    state.project.name =
        dom.projectName.value;

    markDirty();
});

bind('seek','input',() => {

    if (!canEdit()) return;

    dom.video.currentTime =
        num(dom.seek.value);

    updateTime();
    renderAll();
});

dom.video.addEventListener(
    'timeupdate',
    () => {
        updateTime();
        renderStage();
        renderConnections();
    }
);

dom.video.addEventListener(
    'play',
    () => {
        dom.play.textContent = '⏸';
    }
);

dom.video.addEventListener(
    'pause',
    () => {
        dom.play.textContent = '▶';
    }
);

dom.video.addEventListener(
    'ended',
    () => {
        dom.play.textContent = '▶';
        updateTime();
        renderAll();
    }
);

/* =========================================================
 * Video area context menu
 * ======================================================= */

dom.videoArea.addEventListener(
    'contextmenu',
    event => {

        event.preventDefault();

        if (!state.ready) {
            showMessage(
                '動画の読み込みが完了してから編集してください。'
            );
            return;
        }

        const target =
            event.target.closest('.edit-object');

        if (target) {
            return;
        }

        const connection =
            event.target.closest('.connector-hit');

        if (connection) {
            return;
        }

        const position =
            stagePositionFromPointer(event);

        state.contextElementId = null;
        state.contextConnectionId = null;
        state.contextX = position.x;
        state.contextY = position.y;

        showContext(
            event.clientX,
            event.clientY
        );
    }
);

/* =========================================================
 * Click stage
 * ======================================================= */

dom.videoArea.addEventListener(
    'pointerdown',
    event => {

        if (
            event.target === dom.video ||
            event.target === dom.objects ||
            event.target === dom.videoArea
        ) {
            if (!event.shiftKey) {
                state.selected.clear();
                renderStage();
            }
        }
    }
);

/* =========================================================
 * Context menu outside
 * ======================================================= */

document.addEventListener(
    'pointerdown',
    event => {

        if (
            dom.context.style.display !== 'block'
        ) {
            return;
        }

        if (!dom.context.contains(event.target)) {
            hideContext();
        }
    }
);

/* =========================================================
 * Escape
 * ======================================================= */

document.addEventListener(
    'keydown',
    event => {

        if (event.key === 'Escape') {
            hideContext();

            document
                .querySelectorAll('.modal-backdrop')
                .forEach(
                    modal => modal.style.display = 'none'
                );
        }

        if (
            event.key === 'Delete' &&
            state.selected.size === 1 &&
            !['INPUT','TEXTAREA','SELECT'].includes(
                document.activeElement?.tagName
            )
        ) {
            const id =
                [...state.selected][0];

            deleteElement(id);
        }
    }
);

/* =========================================================
 * Resize
 * ======================================================= */

window.addEventListener(
    'resize',
    fitStage
);

/* =========================================================
 * Drag/drop video
 * ======================================================= */

['dragenter','dragover'].forEach(eventName => {

    document.addEventListener(
        eventName,
        event => {

            event.preventDefault();

            if (
                event.dataTransfer &&
                [...event.dataTransfer.types]
                    .includes('Files')
            ) {
                dom.videoArea.style.outline =
                    '2px solid #267bd3';
            }
        }
    );
});

['dragleave','drop'].forEach(eventName => {

    document.addEventListener(
        eventName,
        event => {

            event.preventDefault();

            dom.videoArea.style.outline = '';

            if (eventName === 'drop') {

                const files =
                    [...(event.dataTransfer?.files || [])];

                const video =
                    files.find(
                        file =>
                            file.type.startsWith('video/')
                    );

                if (video) {
                    if (!state.project) {
                        state.project = emptyProject();
                    }

                    loadVideoFile(video);
                }
            }
        }
    );
});

/* =========================================================
 * Initial
 * ======================================================= */

async function initialize()
{
    state.project = null;

    try {
        await openDB();
    } catch (error) {
        console.warn(
            'IndexedDB unavailable:',
            error
        );
    }

    await refreshProjectList();

    setStatus('待機中');
}

initialize();

</script>

</body>
</html>

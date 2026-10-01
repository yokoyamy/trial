<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * サーバー保存:
 *   ./data/projects/*.json
 *
 * 動画本体:
 *   ブラウザ IndexedDB
 *
 * 編集データ:
 *   サーバーJSON
 *   IndexedDB
 *   JSONエクスポート
 */

const APP_VERSION = 6;
const MAX_SERVER_PROJECTS = 20;

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

function ensureProjectDir(): bool {
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true)) {
        return false;
    }
    if (!is_dir(PROJECT_DIR) && !@mkdir(PROJECT_DIR, 0775, true)) {
        return false;
    }
    return is_dir(PROJECT_DIR) && is_writable(PROJECT_DIR);
}

function validProjectId(string $id): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{8,100}$/', $id);
}

function projectPath(string $id): string {
    return PROJECT_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

function serverProjects(): array {
    if (!is_dir(PROJECT_DIR)) return [];

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

function projectSummary(array $p): array {
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
            'serverCount' => count(serverProjects()),
            'php' => PHP_VERSION
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map('projectSummary', serverProjects()),
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

        $data = json_decode(@file_get_contents($file) ?: '', true);

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
                'message' => 'data/projects に書き込めません。Apache/PHPの書込権限を確認してください。'
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

        $id = (string)($payload['projectId'] ?? '');

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

        $existing = is_file(projectPath($id));
        $projects = serverProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' => 'サーバー保存上限に達しました。ローカル保存を使用してください。'
            ], 409);
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
                'message' => 'プロジェクト名は120文字以内にしてください。'
            ], 422);
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

        if ($json === false || @file_put_contents(
            projectPath($id),
            $json,
            LOCK_EX
        ) === false) {
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
    --panel:#191d22;
    --panel2:#242930;
    --border:#3e454e;
    --text:#f5f7fa;
    --muted:#9da6b0;
    --blue:#247bd3;
    --green:#287b4b;
    --red:#a53a3a;
    --yellow:#ffd447;
    --skip:#e38b28;
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
    border:1px solid #555d67;
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

input,select,textarea{
    width:100%;
    color:#fff;
    background:#242930;
    border:1px solid #555d67;
    border-radius:5px;
    padding:7px;
}

textarea{
    min-height:90px;
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
    top:58px;
    left:50%;
    transform:translateX(-50%);
    z-index:10000;
    display:none;
    max-width:92vw;
    padding:10px 16px;
    border-radius:7px;
    background:#9b3838;
    box-shadow:0 12px 40px #000c;
    white-space:pre-wrap;
}

#message.ok{
    background:#277247;
}

/* HOME */

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
    line-height:1.6;
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
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px;
    margin:7px 0;
    border:1px solid #414851;
    border-radius:6px;
}

.project-info{
    min-width:0;
}

.project-name{
    font-weight:600;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.project-meta{
    color:#8f98a3;
    font-size:11px;
    margin-top:3px;
}

.project-actions{
    display:flex;
    gap:5px;
    flex-shrink:0;
}

/* HEADER */

header{
    position:absolute;
    inset:0 0 auto;
    height:50px;
    z-index:1000;
    display:flex;
    align-items:center;
    gap:8px;
    padding:0 10px;
    background:#1a1e23;
    border-bottom:1px solid #343a42;
}

header h1{
    margin:0 10px 0 3px;
    font-size:15px;
    white-space:nowrap;
}

#headerProjectName{
    width:220px;
}

#status{
    margin-left:auto;
    color:#aeb6bf;
    font-size:12px;
}

/* EDITOR */

#editor{
    position:absolute;
    inset:50px 0 0;
    display:none;
    flex-direction:column;
    background:#090a0c;
}

#videoArea{
    flex:1;
    min-height:0;
    position:relative;
    display:flex;
    justify-content:center;
    align-items:center;
    overflow:hidden;
    background:#000;
}

#videoStage{
    position:relative;
    background:#000;
    flex:none;
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

/* OBJECT */

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
    outline:2px dashed #6ec7ff;
    outline-offset:3px;
}

.edit-object.connect-source{
    outline:3px solid var(--yellow);
    outline-offset:4px;
}

.edit-object.comment{
    display:flex;
    align-items:center;
    padding:6px 9px;
    white-space:pre-wrap;
    overflow:hidden;
    word-break:break-word;
}

.edit-object.box{
    background:transparent;
}

.edit-object.skip{
    border:2px dashed var(--skip);
    background:#e38b2820;
}

.skip-label{
    position:absolute;
    top:4px;
    left:4px;
    padding:2px 6px;
    color:#fff;
    background:#bd7019;
    border-radius:4px;
    font-size:11px;
    pointer-events:none;
}

.resize-handle{
    position:absolute;
    right:-8px;
    bottom:-8px;
    width:15px;
    height:15px;
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
    width:14px;
    height:14px;
    margin:-7px;
    border:2px solid #fff;
    background:#247bd3;
    border-radius:50%;
    display:none;
    z-index:40;
    cursor:crosshair;
}

.edit-object.selected .connection-point,
.edit-object.multi-selected .connection-point,
.edit-object.connect-source .connection-point{
    display:block;
}

.edit-object.connect-source .connection-point{
    background:var(--yellow);
    border-color:#111;
}

/* CONNECTION */

.connector{
    fill:none;
    pointer-events:none;
    stroke-width:2;
}

.connector.selected{
    stroke:var(--yellow)!important;
    stroke-width:3!important;
}

.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:16;
    pointer-events:stroke;
    cursor:pointer;
}

/* TIMELINE */

#timeline{
    flex:none;
    height:205px;
    padding:8px 12px 10px;
    background:#191d22;
    border-top:1px solid #383e46;
}

.timeline-head{
    display:flex;
    align-items:center;
    gap:10px;
    height:27px;
}

#timeReadout{
    min-width:125px;
    font-size:12px;
    font-variant-numeric:tabular-nums;
}

#seek{
    flex:1;
}

.timeline-scale{
    position:relative;
    height:18px;
    margin-left:86px;
    color:#858e99;
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
    color:#c5ccd4;
    font-size:11px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.track{
    position:relative;
    height:30px;
    border:1px solid #3b424a;
    background:#282d33;
    border-radius:4px;
}

.track-item{
    position:absolute;
    top:3px;
    height:22px;
    min-width:8px;
    border-radius:3px;
    cursor:grab;
    user-select:none;
}

.track-item.comment{background:#348fd1}
.track-item.box{background:#d74b4b}
.track-item.skip{background:#c87a20}
.track-item.connection{background:#8d5bc2}

.track-item.selected{
    box-shadow:0 0 0 2px #fff;
}

.track-time{
    position:absolute;
    inset:0;
    display:flex;
    justify-content:center;
    align-items:center;
    pointer-events:none;
    color:#fff;
    font-size:9px;
    white-space:nowrap;
}

.timeline-handle{
    position:absolute;
    top:0;
    width:10px;
    height:100%;
    z-index:5;
    cursor:ew-resize;
}

.timeline-handle.left{
    left:-5px;
}

.timeline-handle.right{
    right:-5px;
}

.timeline-handle::after{
    content:"";
    position:absolute;
    top:3px;
    bottom:3px;
    width:4px;
    left:3px;
    background:#fff;
    border-radius:2px;
}

/* MODAL */

.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:9000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:15px;
    background:#000b;
}

.modal{
    width:min(650px,96vw);
    max-height:92vh;
    overflow:auto;
    padding:18px;
    background:#20252b;
    border:1px solid #565d66;
    border-radius:9px;
    box-shadow:0 20px 70px #000c;
}

.modal h2{
    margin:0 0 14px;
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
    color:#c5ccd4;
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

/* COLOR */

.palette{
    display:grid;
    grid-template-columns:repeat(6,1fr);
    gap:6px;
    margin-top:5px;
}

.palette button{
    height:32px;
    padding:0;
    border:2px solid #555;
}

.palette button.active{
    border-color:#fff;
    box-shadow:0 0 0 2px #247bd3;
}

/* CONTEXT */

#contextMenu{
    position:fixed;
    z-index:8000;
    display:none;
    width:245px;
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

/* RESPONSIVE */

@media(max-width:700px){
    #headerProjectName{
        width:140px;
    }

    header h1{
        display:none;
    }

    #timeline{
        height:220px;
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

<header>
    <h1>動画上直接編集</h1>

    <input
        id="headerProjectName"
        type="text"
        maxlength="120"
        placeholder="プロジェクト名"
        disabled
    >

    <button id="newVideoBtn">動画を読み込む</button>
    <button id="loadProjectBtn">保存を開く</button>
    <button id="saveServerBtn" class="success" disabled>保存</button>
    <button id="saveLocalBtn" disabled>ローカル保存</button>
    <button id="exportBtn" disabled>書き出し</button>
    <button id="importBtn">読込</button>
    <button id="backBtn">戻る</button>

    <span id="status">待機中</span>
</header>

<div id="home">
    <div class="home-card">
        <h2>動画上直接編集ツール</h2>

        <p>
            動画ファイルを読み込んだ後、動画画面上で右クリックすると
            テキスト・強調枠・スキップを追加できます。
            編集対象の動画を読み込み終わるまでは編集操作を開始できません。
        </p>

        <div class="home-actions">
            <button id="homeVideoBtn" class="primary">動画を読み込む</button>
            <button id="homeLoadBtn">保存済みプロジェクト</button>
            <button id="homeImportBtn">JSONを読み込む</button>
        </div>

        <input id="videoFile" class="hidden" type="file" accept="video/*">
        <input id="jsonFile" class="hidden" type="file" accept=".json,application/json">

        <div id="projectList" class="project-list"></div>
    </div>
</div>

<div id="editor">

    <div id="videoArea">
        <div id="videoStage">
            <video
                id="recordedVideo"
                preload="metadata"
                playsinline
            ></video>

            <svg id="connectors"></svg>
            <div id="objects"></div>
        </div>
    </div>

    <div id="timeline">

        <div class="timeline-head">
            <button id="playBtn">▶</button>
            <button id="pauseBtn">Ⅱ</button>

            <span id="timeReadout">00:00.00 / 00:00.00</span>

            <input
                id="seek"
                type="range"
                min="0"
                max="0"
                step="0.01"
                value="0"
                disabled
            >
        </div>

        <div id="timelineScale" class="timeline-scale"></div>

        <div class="timeline-row">
            <div class="timeline-name">テキスト</div>
            <div id="commentTrack" class="track"></div>
        </div>

        <div class="timeline-row">
            <div class="timeline-name">強調枠</div>
            <div id="boxTrack" class="track"></div>
        </div>

        <div class="timeline-row">
            <div class="timeline-name">スキップ</div>
            <div id="skipTrack" class="track"></div>
        </div>

        <div class="timeline-row">
            <div class="timeline-name">接続</div>
            <div id="connectionTrack" class="track"></div>
        </div>
    </div>
</div>

<div id="contextMenu">
    <button data-action="add-text">テキストを追加</button>
    <button data-action="add-box">強調枠を追加</button>
    <button data-action="add-skip">スキップを追加</button>

    <div class="context-separator"></div>

    <button data-action="edit">編集</button>
    <button data-action="duplicate">複製</button>
    <button data-action="delete">削除</button>

    <div class="context-separator"></div>

    <button data-action="connect">選択した2要素を接続</button>
    <button data-action="disconnect">選択した接続を解除</button>
</div>

<div id="objectModal" class="modal-backdrop">
    <div class="modal">

        <h2 id="objectModalTitle">要素設定</h2>

        <div class="grid2">

            <label id="textField" class="field full">
                テキスト
                <textarea id="mText"></textarea>
            </label>

            <label class="field">
                開始秒
                <input id="mStart" type="number" min="0" step="0.01">
            </label>

            <label class="field">
                終了秒
                <input id="mEnd" type="number" min="0" step="0.01">
            </label>

            <label class="field">
                X %
                <input id="mX" type="number" min="0" max="100" step="0.1">
            </label>

            <label class="field">
                Y %
                <input id="mY" type="number" min="0" max="100" step="0.1">
            </label>

            <label class="field">
                幅 %
                <input id="mW" type="number" min=".5" max="100" step="0.1">
            </label>

            <label class="field">
                高さ %
                <input id="mH" type="number" min=".5" max="100" step="0.1">
            </label>

            <label class="field">
                文字色
                <div id="textPalette" class="palette"></div>
            </label>

            <label class="field">
                背景色
                <div id="bgPalette" class="palette"></div>
            </label>

            <label class="field">
                枠線色
                <div id="borderPalette" class="palette"></div>
            </label>

            <label class="field">
                文字サイズ
                <input id="mFontSize" type="number" min="8" max="200" step="1">
            </label>

            <label class="field">
                透明度 %
                <input id="mOpacity" type="number" min="0" max="100" step="1">
            </label>

            <label class="field">
                枠線幅
                <input id="mBorderWidth" type="number" min="0" max="30" step="1">
            </label>

            <label class="field">
                角丸
                <input id="mRadius" type="number" min="0" max="100" step="1">
            </label>

            <label class="field">
                太さ
                <select id="mWeight">
                    <option value="400">標準</option>
                    <option value="500">やや太い</option>
                    <option value="600">太字</option>
                    <option value="700">強調</option>
                    <option value="800">極太</option>
                </select>
            </label>

        </div>

        <div class="modal-footer">
            <button id="objectCancel">キャンセル</button>
            <button id="objectApply" class="primary">適用</button>
        </div>
    </div>
</div>

<div id="connectionModal" class="modal-backdrop">
    <div class="modal">

        <h2>接続線の設定</h2>

        <div class="grid2">

            <label class="field">
                開始秒
                <input id="cStart" type="number" min="0" step=".01">
            </label>

            <label class="field">
                終了秒
                <input id="cEnd" type="number" min="0" step=".01">
            </label>

            <label class="field">
                線幅
                <input id="cWidth" type="number" min="1" max="20" step=".5">
            </label>

            <label class="field">
                曲率
                <input id="cCurve" type="number" min="5" max="200" step="1">
            </label>

            <label class="field full">
                線色
                <div id="connectionPalette" class="palette"></div>
            </label>

            <label class="field">
                始点
                <select id="cFromPoint">
                    <option value="n">上</option>
                    <option value="e">右</option>
                    <option value="s">下</option>
                    <option value="w">左</option>
                    <option value="ne">右上</option>
                    <option value="se">右下</option>
                    <option value="sw">左下</option>
                    <option value="nw">左上</option>
                </select>
            </label>

            <label class="field">
                終点
                <select id="cToPoint">
                    <option value="n">上</option>
                    <option value="e">右</option>
                    <option value="s">下</option>
                    <option value="w">左</option>
                    <option value="ne">右上</option>
                    <option value="se">右下</option>
                    <option value="sw">左下</option>
                    <option value="nw">左上</option>
                </select>
            </label>

        </div>

        <div class="modal-footer">
            <button id="connectionCancel">キャンセル</button>
            <button id="connectionApply" class="primary">適用</button>
        </div>

    </div>
</div>

<div id="message"></div>

<script>
'use strict';

const $ = id => document.getElementById(id);

const PALETTE = [
    '#ffffff',
    '#000000',
    '#ef4444',
    '#f97316',
    '#eab308',
    '#22c55e',
    '#06b6d4',
    '#3b82f6',
    '#6366f1',
    '#a855f7',
    '#ec4899',
    '#64748b'
];

const state = {
    project:null,
    videoBlob:null,
    videoUrl:'',
    selected:new Set(),
    selectedConnection:null,
    context:null,
    drag:null,
    timelineDrag:null,
    modalElement:null,
    modalConnection:null,
    dirty:false
};

/* =========================================================
 * COMMON
 * ======================================================= */

function uid(prefix='id'){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,9);
}

function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}

function num(v,fallback=0){
    const n=Number(v);
    return Number.isFinite(n) ? n : fallback;
}

function clone(v){
    return JSON.parse(JSON.stringify(v));
}

function duration(){
    return num(
        $('recordedVideo').duration,
        state.project?.duration || 0
    );
}

function currentTime(){
    return num($('recordedVideo').currentTime,0);
}

function formatTime(value){
    value=Math.max(0,num(value));
    const m=Math.floor(value/60);
    const s=value-m*60;
    return String(m).padStart(2,'0') + ':' +
        s.toFixed(2).padStart(5,'0');
}

function showMessage(message,ok=false){
    const el=$('message');
    el.textContent=message;
    el.className=ok?'ok':'';
    el.style.display='block';

    clearTimeout(showMessage.timer);
    showMessage.timer=setTimeout(()=>{
        el.style.display='none';
    },3500);
}

function markDirty(){
    state.dirty=true;
    $('status').textContent='未保存';
}

function setClean(message='保存済み'){
    state.dirty=false;
    $('status').textContent=message;
}

function escapeHtml(value){
    return String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

/* =========================================================
 * PROJECT
 * ======================================================= */

function emptyProject(name='新しいプロジェクト',videoName=''){
    return {
        version:6,
        projectId:uid('project'),
        name,
        videoName,
        duration:0,
        videoKey:'',
        elements:[],
        connections:[],
        savedAt:''
    };
}

function normalizeProject(p){
    p=clone(p || emptyProject());

    p.version=6;
    p.elements=Array.isArray(p.elements)?p.elements:[];
    p.connections=Array.isArray(p.connections)?p.connections:[];

    p.elements=p.elements.map(e=>({
        id:e.id || uid('element'),
        type:['comment','box','skip'].includes(e.type)
            ? e.type
            : 'comment',
        text:String(e.text || ''),
        start:Math.max(0,num(e.start,0)),
        end:Math.max(0,num(e.end,5)),
        x:clamp(num(e.x,10),0,99),
        y:clamp(num(e.y,10),0,99),
        w:clamp(num(e.w,30),.5,100),
        h:clamp(num(e.h,15),.5,100),
        style:{
            color:e.style?.color || '#ffffff',
            background:e.style?.background || '#000000',
            borderColor:e.style?.borderColor || '#ffffff',
            borderWidth:num(e.style?.borderWidth,2),
            fontSize:num(e.style?.fontSize,24),
            opacity:num(e.style?.opacity,100),
            radius:num(e.style?.radius,4),
            fontWeight:num(e.style?.fontWeight,600)
        }
    }));

    p.connections=p.connections
        .filter(c=>c.from && c.to)
        .map(c=>({
            id:c.id || uid('connection'),
            from:c.from,
            to:c.to,
            fromPoint:c.fromPoint || 'e',
            toPoint:c.toPoint || 'w',
            start:Math.max(0,num(c.start,0)),
            end:Math.max(0,num(c.end,5)),
            color:c.color || '#ffffff',
            width:clamp(num(c.width,2),1,20),
            curve:clamp(num(c.curve,35),5,200)
        }));

    return p;
}

/* =========================================================
 * INDEXED DB
 * ======================================================= */

const DB_NAME='video_direct_editor_v6';
const DB_VERSION=1;

let dbPromise=null;

function openDB(){
    if(dbPromise) return dbPromise;

    dbPromise=new Promise((resolve,reject)=>{
        const req=indexedDB.open(DB_NAME,DB_VERSION);

        req.onupgradeneeded=()=>{
            const db=req.result;

            if(!db.objectStoreNames.contains('projects')){
                db.createObjectStore(
                    'projects',
                    {keyPath:'projectId'}
                );
            }

            if(!db.objectStoreNames.contains('videos')){
                db.createObjectStore(
                    'videos',
                    {keyPath:'videoKey'}
                );
            }
        };

        req.onsuccess=()=>resolve(req.result);
        req.onerror=()=>reject(req.error);
    });

    return dbPromise;
}

async function idbPut(store,value){
    const db=await openDB();

    return new Promise((resolve,reject)=>{
        const tx=db.transaction(store,'readwrite');

        tx.objectStore(store).put(value);

        tx.oncomplete=()=>resolve();
        tx.onerror=()=>reject(tx.error);
    });
}

async function idbGet(store,key){
    const db=await openDB();

    return new Promise((resolve,reject)=>{
        const tx=db.transaction(store,'readonly');
        const req=tx.objectStore(store).get(key);

        req.onsuccess=()=>resolve(req.result || null);
        req.onerror=()=>reject(req.error);
    });
}

async function idbAll(store){
    const db=await openDB();

    return new Promise((resolve,reject)=>{
        const tx=db.transaction(store,'readonly');
        const req=tx.objectStore(store).getAll();

        req.onsuccess=()=>resolve(req.result || []);
        req.onerror=()=>reject(req.error);
    });
}

async function idbDelete(store,key){
    const db=await openDB();

    return new Promise((resolve,reject)=>{
        const tx=db.transaction(store,'readwrite');
        tx.objectStore(store).delete(key);
        tx.oncomplete=()=>resolve();
        tx.onerror=()=>reject(tx.error);
    });
}

/* =========================================================
 * VIDEO
 * ======================================================= */

async function saveVideo(blob,name){
    const key=uid('video');

    await idbPut('videos',{
        videoKey:key,
        name,
        type:blob.type,
        blob
    });

    return key;
}

async function openVideoBlob(key){
    if(!key) return null;

    const row=await idbGet('videos',key);

    return row?.blob || null;
}

function waitMetadata(video){
    return new Promise((resolve,reject)=>{
        if(video.readyState>=1 && Number.isFinite(video.duration)){
            resolve(video.duration);
            return;
        }

        const loaded=()=>{
            cleanup();
            resolve(video.duration);
        };

        const error=()=>{
            cleanup();
            reject(new Error('動画を読み込めませんでした。'));
        };

        const cleanup=()=>{
            video.removeEventListener('loadedmetadata',loaded);
            video.removeEventListener('error',error);
        };

        video.addEventListener('loadedmetadata',loaded);
        video.addEventListener('error',error);
    });
}

async function loadVideoBlob(blob,name){
    if(!blob) throw new Error('動画データがありません。');

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoBlob=blob;
    state.videoUrl=URL.createObjectURL(blob);

    const video=$('recordedVideo');
    video.src=state.videoUrl;
    video.load();

    $('status').textContent='動画を読み込み中…';

    const d=await waitMetadata(video);

    state.project.duration=d;
    $('seek').min='0';
    $('seek').max=String(d);
    $('seek').value='0';
    $('seek').disabled=false;

    await waitFrame();

    fitStage();
    renderAll();

    $('headerProjectName').disabled=false;
    $('saveServerBtn').disabled=false;
    $('saveLocalBtn').disabled=false;
    $('exportBtn').disabled=false;

    $('status').textContent='編集可能';

    return d;
}

function waitFrame(){
    return new Promise(resolve=>{
        requestAnimationFrame(()=>resolve());
    });
}

async function chooseVideo(){
    $('videoFile').click();
}

async function handleVideoFile(file){
    if(!file) return;

    try{
        const videoKey=await saveVideo(
            file,
            file.name
        );

        const project=emptyProject(
            file.name.replace(/\.[^.]+$/,''),
            file.name
        );

        project.videoKey=videoKey;

        await openEditor(
            project,
            file
        );

        showMessage(
            '動画の読み込みが完了しました。編集できます。',
            true
        );
    }catch(error){
        console.error(error);
        showMessage(
            '動画読み込み失敗：' + error.message
        );
    }
}

/* =========================================================
 * EDITOR
 * ======================================================= */

async function openEditor(project,blob){
    state.project=normalizeProject(project);
    state.selected.clear();
    state.selectedConnection=null;
    state.dirty=false;

    $('home').style.display='none';
    $('editor').style.display='flex';

    $('headerProjectName').value=state.project.name;

    $('status').textContent='動画読み込み中…';

    try{
        await loadVideoBlob(
            blob,
            state.project.videoName
        );
    }catch(error){
        $('editor').style.display='none';
        $('home').style.display='flex';
        throw error;
    }
}

function closeEditor(force=false){
    if(!force && state.dirty){
        if(!confirm('未保存の編集があります。閉じますか？')){
            return;
        }
    }

    $('recordedVideo').pause();

    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
        state.videoUrl='';
    }

    $('recordedVideo').removeAttribute('src');
    $('recordedVideo').load();

    state.project=null;
    state.videoBlob=null;
    state.selected.clear();
    state.selectedConnection=null;
    state.drag=null;
    state.timelineDrag=null;
    state.dirty=false;

    $('editor').style.display='none';
    $('home').style.display='flex';

    $('headerProjectName').value='';
    $('headerProjectName').disabled=true;
    $('saveServerBtn').disabled=true;
    $('saveLocalBtn').disabled=true;
    $('exportBtn').disabled=true;
    $('seek').disabled=true;

    renderProjectList();
}

/* =========================================================
 * STAGE
 * ======================================================= */

function fitStage(){
    const video=$('recordedVideo');
    const area=$('videoArea');

    if(!video.videoWidth || !video.videoHeight){
        return;
    }

    const aw=area.clientWidth;
    const ah=area.clientHeight;

    const ratio=video.videoWidth/video.videoHeight;

    let w=aw;
    let h=w/ratio;

    if(h>ah){
        h=ah;
        w=h*ratio;
    }

    $('videoStage').style.width=Math.max(1,w)+'px';
    $('videoStage').style.height=Math.max(1,h)+'px';
}

function visible(item,time=currentTime()){
    return (
        time >= num(item.start,0)-.001 &&
        time <= num(item.end,duration())+.001
    );
}

function renderObjects(){
    const root=$('objects');

    root.innerHTML='';

    if(!state.project) return;

    const time=currentTime();

    state.project.elements.forEach(element=>{
        if(!visible(element,time)) return;

        const el=document.createElement('div');

        el.className='edit-object ' + element.type;

        if(state.selected.has(element.id)){
            el.classList.add(
                state.selected.size>1
                    ? 'multi-selected'
                    : 'selected'
            );
        }

        el.dataset.id=element.id;

        el.style.left=element.x+'%';
        el.style.top=element.y+'%';
        el.style.width=element.w+'%';
        el.style.height=element.h+'%';

        const s=element.style || {};

        el.style.color=s.color;
        el.style.background=
            element.type==='box'
                ? 'transparent'
                : hexAlpha(
                    s.background,
                    s.opacity
                );

        el.style.borderColor=s.borderColor;
        el.style.borderWidth=(s.borderWidth||0)+'px';
        el.style.borderStyle=
            element.type==='skip'
                ? 'dashed'
                : 'solid';

        el.style.borderRadius=(s.radius||0)+'px';

        el.style.fontSize=(s.fontSize||24)+'px';
        el.style.fontWeight=s.fontWeight||600;

        if(element.type==='comment'){
            el.textContent=element.text || 'テキスト';
        }

        if(element.type==='skip'){
            const label=document.createElement('span');
            label.className='skip-label';
            label.textContent='スキップ';
            el.appendChild(label);
        }

        const resize=document.createElement('span');
        resize.className='resize-handle';
        el.appendChild(resize);

        ['n','ne','e','se','s','sw','w','nw']
            .forEach(point=>{
                const cp=document.createElement('span');
                cp.className='connection-point cp-'+point;
                cp.dataset.point=point;
                el.appendChild(cp);
            });

        root.appendChild(el);
    });
}

function hexAlpha(hex,opacity){
    if(!/^#[0-9a-f]{6}$/i.test(hex || '')){
        return hex || 'transparent';
    }

    const a=clamp(num(opacity,100),0,100)/100;

    if(a>=.999) return hex;

    const n=parseInt(hex.slice(1),16);

    const r=(n>>16)&255;
    const g=(n>>8)&255;
    const b=n&255;

    return `rgba(${r},${g},${b},${a})`;
}

/* =========================================================
 * CONNECTIONS
 * ======================================================= */

function pointPosition(element,point){
    const x=element.x;
    const y=element.y;
    const w=element.w;
    const h=element.h;

    const map={
        n:[x+w/2,y],
        ne:[x+w,y],
        e:[x+w,y+h/2],
        se:[x+w,y+h],
        s:[x+w/2,y+h],
        sw:[x,y+h],
        w:[x,y+h/2],
        nw:[x,y]
    };

    const p=map[point] || map.e;

    return {
        x:p[0],
        y:p[1]
    };
}

function connectionPath(p1,p2,curve){
    const dx=p2.x-p1.x;
    const dy=p2.y-p1.y;
    const c=Math.min(
        20,
        Math.max(
            2,
            num(curve,35)/10
        )
    );

    let c1={x:p1.x,y:p1.y};
    let c2={x:p2.x,y:p2.y};

    if(Math.abs(dx)>=Math.abs(dy)){
        c1.x+=dx>=0?c:-c;
        c2.x-=dx>=0?c:-c;
    }else{
        c1.y+=dy>=0?c:-c;
        c2.y-=dy>=0?c:-c;
    }

    return `
        M ${p1.x} ${p1.y}
        C ${c1.x} ${c1.y},
          ${c2.x} ${c2.y},
          ${p2.x} ${p2.y}
    `;
}

function renderConnections(){
    const svg=$('connectors');

    svg.innerHTML='';

    if(!state.project) return;

    svg.setAttribute('viewBox','0 0 100 100');
    svg.setAttribute('preserveAspectRatio','none');

    const defs=document.createElementNS(
        'http://www.w3.org/2000/svg',
        'defs'
    );

    defs.innerHTML=`
        <marker id="arrow"
            markerWidth="8"
            markerHeight="8"
            refX="7"
            refY="4"
            orient="auto">
            <path d="M0,0 L8,4 L0,8 z"
                fill="context-stroke"/>
        </marker>
    `;

    svg.appendChild(defs);

    const time=currentTime();

    state.project.connections.forEach(connection=>{
        if(!visible(connection,time)) return;

        const from=state.project.elements.find(
            e=>e.id===connection.from
        );

        const to=state.project.elements.find(
            e=>e.id===connection.to
        );

        if(!from || !to) return;

        const p1=pointPosition(
            from,
            connection.fromPoint
        );

        const p2=pointPosition(
            to,
            connection.toPoint
        );

        const d=connectionPath(
            p1,
            p2,
            connection.curve
        );

        const hit=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        hit.setAttribute('d',d);
        hit.setAttribute('class','connector-hit');
        hit.dataset.id=connection.id;

        svg.appendChild(hit);

        const path=document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

        path.setAttribute('d',d);
        path.setAttribute('class','connector');

        const color=connection.color ||
            from.style?.borderColor ||
            '#ffffff';

        path.setAttribute(
            'stroke',
            color
        );

        path.setAttribute(
            'stroke-width',
            String(
                state.selectedConnection===connection.id
                    ? num(connection.width,2)+1
                    : num(connection.width,2)
            )
        );

        path.setAttribute(
            'marker-end',
            'url(#arrow)'
        );

        svg.appendChild(path);
    });
}

/* =========================================================
 * SELECTION
 * ======================================================= */

function selectOnly(id){
    state.selected.clear();

    if(id){
        state.selected.add(id);
    }

    state.selectedConnection=null;

    renderAll();
}

function toggleSelection(id){
    state.selectedConnection=null;

    if(state.selected.has(id)){
        state.selected.delete(id);
    }else{
        state.selected.add(id);
    }

    renderAll();
}

function selectConnection(id){
    state.selected.clear();
    state.selectedConnection=id;
    renderAll();
}

function selectedElements(){
    if(!state.project) return [];

    return state.project.elements.filter(
        e=>state.selected.has(e.id)
    );
}

/* =========================================================
 * ADD / DELETE
 * ======================================================= */

function addElement(type,x,y){
    if(!state.project) return;

    const d=duration();

    const style={
        color:'#ffffff',
        background:
            type==='comment'
                ? '#000000'
                : '#ef4444',
        borderColor:
            type==='box'
                ? '#ef4444'
                : '#ffffff',
        borderWidth:type==='box'?3:1,
        fontSize:24,
        opacity:type==='comment'?88:100,
        radius:4,
        fontWeight:600
    };

    const element={
        id:uid('element'),
        type,
        text:type==='comment'
            ? 'ここにテキスト'
            : '',
        start:clamp(currentTime(),0,d),
        end:clamp(
            currentTime()+5,
            currentTime(),
            d
        ),
        x:clamp(x,0,90),
        y:clamp(y,0,85),
        w:type==='box'?35:28,
        h:type==='box'?25:15,
        style
    };

    if(type==='skip'){
        element.w=35;
        element.h=20;
        element.style.background='#e38b28';
        element.style.borderColor='#e38b28';
        element.style.opacity=25;
    }

    state.project.elements.push(element);

    selectOnly(element.id);
    markDirty();

    openObjectModal(element.id);
}

function deleteSelection(){
    if(!state.project) return;

    if(state.selectedConnection){
        state.project.connections=
            state.project.connections.filter(
                c=>c.id!==state.selectedConnection
            );

        state.selectedConnection=null;
    }

    if(state.selected.size){
        const ids=new Set(state.selected);

        state.project.elements=
            state.project.elements.filter(
                e=>!ids.has(e.id)
            );

        state.project.connections=
            state.project.connections.filter(
                c=>!ids.has(c.from) &&
                !ids.has(c.to)
            );

        state.selected.clear();
    }

    markDirty();
    renderAll();
}

function duplicateSelection(){
    const source=selectedElements()[0];

    if(!source) return;

    const copy=clone(source);

    copy.id=uid('element');

    copy.x=clamp(
        copy.x+3,
        0,
        100-copy.w
    );

    copy.y=clamp(
        copy.y+3,
        0,
        100-copy.h
    );

    state.project.elements.push(copy);

    selectOnly(copy.id);
    markDirty();
    renderAll();
}

/* =========================================================
 * POINTER: VIDEO OBJECT
 * ======================================================= */

function stagePoint(clientX,clientY){
    const rect=$('videoStage').getBoundingClientRect();

    return {
        x:clamp(
            (clientX-rect.left)/rect.width*100,
            0,
            100
        ),
        y:clamp(
            (clientY-rect.top)/rect.height*100,
            0,
            100
        )
    };
}

function beginObjectPointer(event){
    const object=event.target.closest('.edit-object');

    if(!object) return;

    const id=object.dataset.id;

    const element=state.project.elements.find(
        e=>e.id===id
    );

    if(!element) return;

    if(event.target.closest('.connection-point')){
        return;
    }

    if(event.target.closest('.resize-handle')){
        if(!state.selected.has(id)){
            selectOnly(id);
        }

        const rect=$('videoStage').getBoundingClientRect();

        state.drag={
            mode:'resize',
            id,
            pointer:event.pointerId,
            startX:event.clientX,
            startY:event.clientY,
            originalW:element.w,
            originalH:element.h,
            stageW:rect.width,
            stageH:rect.height
        };

        object.setPointerCapture(event.pointerId);

        event.preventDefault();
        return;
    }

    if(event.shiftKey){
        toggleSelection(id);
    }else if(!state.selected.has(id)){
        selectOnly(id);
    }

    if(state.selected.size>1){
        return;
    }

    const rect=$('videoStage').getBoundingClientRect();

    state.drag={
        mode:'move',
        id,
        pointer:event.pointerId,
        startX:event.clientX,
        startY:event.clientY,
        originalX:element.x,
        originalY:element.y,
        stageW:rect.width,
        stageH:rect.height
    };

    object.setPointerCapture(event.pointerId);

    event.preventDefault();
}

function moveObjectPointer(event){
    if(!state.drag) return;

    const drag=state.drag;

    const element=state.project.elements.find(
        e=>e.id===drag.id
    );

    if(!element){
        state.drag=null;
        return;
    }

    const dx=event.clientX-drag.startX;
    const dy=event.clientY-drag.startY;

    if(drag.mode==='move'){
        element.x=clamp(
            drag.originalX+
            dx/drag.stageW*100,
            0,
            100-element.w
        );

        element.y=clamp(
            drag.originalY+
            dy/drag.stageH*100,
            0,
            100-element.h
        );
    }else{
        element.w=clamp(
            drag.originalW+
            dx/drag.stageW*100,
            .5,
            100-element.x
        );

        element.h=clamp(
            drag.originalH+
            dy/drag.stageH*100,
            .5,
            100-element.y
        );
    }

    markDirty();
    renderAll();
}

function endObjectPointer(){
    state.drag=null;
}

/* =========================================================
 * CONNECTION BY SHIFT SELECT + RIGHT CLICK
 * ======================================================= */

function connectSelectedElements(){
    const elements=selectedElements();

    if(elements.length!==2){
        showMessage(
            '接続する要素を Shift+クリックで2つ選択してください。'
        );
        return;
    }

    const [from,to]=elements;
    const d=duration();

    const color=
        from.style?.borderColor ||
        '#ffffff';

    const connection={
        id:uid('connection'),
        from:from.id,
        to:to.id,
        fromPoint:'e',
        toPoint:'w',
        start:clamp(currentTime(),0,d),
        end:clamp(
            currentTime()+5,
            currentTime(),
            d
        ),
        color,
        width:2,
        curve:35
    };

    state.project.connections.push(connection);

    state.selected.clear();
    state.selectedConnection=connection.id;

    markDirty();
    renderAll();

    showMessage(
        '2つの要素を接続しました。',
        true
    );
}

/* =========================================================
 * CONTEXT MENU
 * ======================================================= */

function hideContextMenu(){
    $('contextMenu').style.display='none';
    state.context=null;
}

function showContextMenu(event){
    event.preventDefault();

    hideContextMenu();

    const object=event.target.closest('.edit-object');
    const hit=event.target.closest('.connector-hit');

    if(object){
        const id=object.dataset.id;

        if(!event.shiftKey && !state.selected.has(id)){
            selectOnly(id);
        }

        state.context={
            type:'element',
            id
        };
    }else if(hit){
        state.context={
            type:'connection',
            id:hit.dataset.id
        };

        selectConnection(hit.dataset.id);
    }else{
        const p=stagePoint(
            event.clientX,
            event.clientY
        );

        state.context={
            type:'stage',
            x:p.x,
            y:p.y
        };
    }

    const menu=$('contextMenu');

    menu.style.display='block';

    let x=event.clientX;
    let y=event.clientY;

    x=Math.min(
        x,
        window.innerWidth-menu.offsetWidth-8
    );

    y=Math.min(
        y,
        window.innerHeight-menu.offsetHeight-8
    );

    menu.style.left=Math.max(4,x)+'px';
    menu.style.top=Math.max(4,y)+'px';

    const edit=menu.querySelector(
        '[data-action=edit]'
    );

    const duplicate=menu.querySelector(
        '[data-action=duplicate]'
    );

    const deleteBtn=menu.querySelector(
        '[data-action=delete]'
    );

    const connect=menu.querySelector(
        '[data-action=connect]'
    );

    const disconnect=menu.querySelector(
        '[data-action=disconnect]'
    );

    edit.style.display=
        object?'block':'none';

    duplicate.style.display=
        object?'block':'none';

    deleteBtn.style.display=
        object || hit || state.selected.size
            ? 'block'
            : 'none';

    connect.style.display=
        state.selected.size===2
            ? 'block'
            : 'none';

    disconnect.style.display=
        hit?'block':'none';
}

function contextAction(action){
    const ctx=state.context;

    hideContextMenu();

    if(action==='add-text' && ctx?.type==='stage'){
        addElement(
            'comment',
            ctx.x,
            ctx.y
        );
        return;
    }

    if(action==='add-box' && ctx?.type==='stage'){
        addElement(
            'box',
            ctx.x,
            ctx.y
        );
        return;
    }

    if(action==='add-skip' && ctx?.type==='stage'){
        addElement(
            'skip',
            ctx.x,
            ctx.y
        );
        return;
    }

    if(action==='edit'){
        if(ctx?.type==='element'){
            openObjectModal(ctx.id);
        }else if(ctx?.type==='connection'){
            openConnectionModal(ctx.id);
        }
        return;
    }

    if(action==='duplicate'){
        duplicateSelection();
        return;
    }

    if(action==='delete'){
        deleteSelection();
        return;
    }

    if(action==='connect'){
        connectSelectedElements();
        return;
    }

    if(action==='disconnect'){
        if(ctx?.id){
            state.project.connections=
                state.project.connections.filter(
                    c=>c.id!==ctx.id
                );

            state.selectedConnection=null;

            markDirty();
            renderAll();
        }
    }
}

/* =========================================================
 * OBJECT MODAL
 * ======================================================= */

function openObjectModal(id){
    const element=state.project.elements.find(
        e=>e.id===id
    );

    if(!element) return;

    selectOnly(id);

    state.modalElement=id;

    $('objectModalTitle').textContent=
        element.type==='comment'
            ? 'テキスト設定'
            : element.type==='box'
                ? '強調枠設定'
                : 'スキップ設定';

    $('textField').style.display=
        element.type==='comment'
            ? 'block'
            : 'none';

    $('mText').value=element.text || '';
    $('mStart').value=element.start;
    $('mEnd').value=element.end;
    $('mX').value=element.x;
    $('mY').value=element.y;
    $('mW').value=element.w;
    $('mH').value=element.h;

    const s=element.style || {};

    $('mFontSize').value=s.fontSize ?? 24;
    $('mOpacity').value=s.opacity ?? 100;
    $('mBorderWidth').value=s.borderWidth ?? 2;
    $('mRadius').value=s.radius ?? 4;
    $('mWeight').value=s.fontWeight ?? 600;

    buildPalette(
        'textPalette',
        s.color || '#ffffff',
        color=>{
            element.style.color=color;
            buildPalette(
                'textPalette',
                color,
                arguments.callee
            );
        }
    );

    buildPalette(
        'bgPalette',
        s.background || '#000000',
        color=>{
            element.style.background=color;
            buildPalette(
                'bgPalette',
                color,
                arguments.callee
            );
        }
    );

    buildPalette(
        'borderPalette',
        s.borderColor || '#ffffff',
        color=>{
            element.style.borderColor=color;
            buildPalette(
                'borderPalette',
                color,
                arguments.callee
            );
        }
    );

    $('objectModal').style.display='flex';
}

function buildPalette(id,current,onSelect){
    const root=$(id);

    root.innerHTML='';

    PALETTE.forEach(color=>{
        const button=document.createElement('button');

        button.type='button';
        button.style.background=color;

        if(color.toLowerCase()===
            String(current).toLowerCase()){
            button.classList.add('active');
        }

        button.addEventListener(
            'click',
            ()=>{
                onSelect(color);
                markDirty();
            }
        );

        root.appendChild(button);
    });
}

function applyObjectModal(){
    const id=state.modalElement;

    const element=state.project.elements.find(
        e=>e.id===id
    );

    if(!element) return;

    const d=duration();

    let start=clamp(
        num($('mStart').value,0),
        0,
        d
    );

    let end=clamp(
        num($('mEnd').value,start),
        start,
        d
    );

    element.start=start;
    element.end=end;

    element.x=clamp(
        num($('mX').value,element.x),
        0,
        99
    );

    element.y=clamp(
        num($('mY').value,element.y),
        0,
        99
    );

    element.w=clamp(
        num($('mW').value,element.w),
        .5,
        100-element.x
    );

    element.h=clamp(
        num($('mH').value,element.h),
        .5,
        100-element.y
    );

    if(element.type==='comment'){
        element.text=$('mText').value;
    }

    element.style.fontSize=clamp(
        num($('mFontSize').value,24),
        8,
        200
    );

    element.style.opacity=clamp(
        num($('mOpacity').value,100),
        0,
        100
    );

    element.style.borderWidth=clamp(
        num($('mBorderWidth').value,2),
        0,
        30
    );

    element.style.radius=clamp(
        num($('mRadius').value,4),
        0,
        100
    );

    element.style.fontWeight=num(
        $('mWeight').value,
        600
    );

    $('objectModal').style.display='none';

    markDirty();
    renderAll();
}

function cancelObjectModal(){
    $('objectModal').style.display='none';
    state.modalElement=null;
}

/* =========================================================
 * CONNECTION MODAL
 * ======================================================= */

function openConnectionModal(id){
    const connection=state.project.connections.find(
        c=>c.id===id
    );

    if(!connection) return;

    state.modalConnection=id;

    selectConnection(id);

    $('cStart').value=connection.start;
    $('cEnd').value=connection.end;
    $('cWidth').value=connection.width;
    $('cCurve').value=connection.curve;
    $('cFromPoint').value=connection.fromPoint;
    $('cToPoint').value=connection.toPoint;

    buildPalette(
        'connectionPalette',
        connection.color,
        color=>{
            connection.color=color;
            buildPalette(
                'connectionPalette',
                color,
                arguments.callee
            );
            renderAll();
        }
    );

    $('connectionModal').style.display='flex';
}

function applyConnectionModal(){
    const connection=state.project.connections.find(
        c=>c.id===state.modalConnection
    );

    if(!connection) return;

    const d=duration();

    connection.start=clamp(
        num($('cStart').value,0),
        0,
        d
    );

    connection.end=clamp(
        num($('cEnd').value,connection.start),
        connection.start,
        d
    );

    connection.width=clamp(
        num($('cWidth').value,2),
        1,
        20
    );

    connection.curve=clamp(
        num($('cCurve').value,35),
        5,
        200
    );

    connection.fromPoint=$('cFromPoint').value;
    connection.toPoint=$('cToPoint').value;

    $('connectionModal').style.display='none';

    markDirty();
    renderAll();
}

/* =========================================================
 * TIMELINE
 * ======================================================= */

function timeFromTrackEvent(event){
    const track=event.currentTarget.closest('.track');
    const rect=track.getBoundingClientRect();

    return clamp(
        (event.clientX-rect.left)/
        rect.width*
        duration(),
        0,
        duration()
    );
}

function renderTimeline(){
    const d=duration();

    if(!d){
        return;
    }

    const scale=$('timelineScale');

    scale.innerHTML='';

    const count=Math.min(
        12,
        Math.max(
            2,
            Math.ceil(d/10)+1
        )
    );

    for(let i=0;i<count;i++){
        const ratio=i/(count-1);

        const span=document.createElement('span');

        span.style.left=(ratio*100)+'%';
        span.textContent=
            formatTime(d*ratio);

        scale.appendChild(span);
    }

    renderTrack(
        $('commentTrack'),
        state.project.elements.filter(
            e=>e.type==='comment'
        ),
        'comment'
    );

    renderTrack(
        $('boxTrack'),
        state.project.elements.filter(
            e=>e.type==='box'
        ),
        'box'
    );

    renderTrack(
        $('skipTrack'),
        state.project.elements.filter(
            e=>e.type==='skip'
        ),
        'skip'
    );

    renderTrack(
        $('connectionTrack'),
        state.project.connections,
        'connection'
    );
}

function renderTrack(track,items,type){
    track.innerHTML='';

    const d=duration();

    items.forEach(item=>{
        const start=clamp(
            num(item.start,0),
            0,
            d
        );

        const end=clamp(
            num(item.end,start),
            start,
            d
        );

        const bar=document.createElement('div');

        bar.className=
            'track-item '+
            type;

        if(
            state.selected.has(item.id) ||
            state.selectedConnection===item.id
        ){
            bar.classList.add('selected');
        }

        bar.style.left=
            (start/d*100)+'%';

        bar.style.width=
            (Math.max(
                .15,
                (end-start)/d*100
            ))+'%';

        bar.dataset.id=item.id;

        const time=document.createElement('span');

        time.className='track-time';

        time.textContent=
            formatTime(start)+
            ' – '+
            formatTime(end);

        bar.appendChild(time);

        if(type!=='connection'){
            const left=document.createElement('span');
            left.className='timeline-handle left';

            const right=document.createElement('span');
            right.className='timeline-handle right';

            bar.appendChild(left);
            bar.appendChild(right);

            left.addEventListener(
                'pointerdown',
                e=>beginTimelineResize(
                    e,
                    item,
                    'start',
                    track
                )
            );

            right.addEventListener(
                'pointerdown',
                e=>beginTimelineResize(
                    e,
                    item,
                    'end',
                    track
                )
            );
        }

        bar.addEventListener(
            'pointerdown',
            e=>{
                if(
                    e.target.closest(
                        '.timeline-handle'
                    )
                ){
                    return;
                }

                beginTimelineMove(
                    e,
                    item,
                    type,
                    track
                );
            }
        );

        bar.addEventListener(
            'dblclick',
            e=>{
                e.stopPropagation();

                if(type==='connection'){
                    openConnectionModal(item.id);
                }else{
                    openObjectModal(item.id);
                }
            }
        );

        track.appendChild(bar);
    });
}

function beginTimelineMove(
    event,
    item,
    type,
    track
){
    event.preventDefault();

    const d=duration();
    const rect=track.getBoundingClientRect();

    state.timelineDrag={
        mode:'move',
        item,
        type,
        startX:event.clientX,
        originalStart:item.start,
        originalEnd:item.end,
        width:rect.width
    };

    document.addEventListener(
        'pointermove',
        timelinePointerMove
    );

    document.addEventListener(
        'pointerup',
        timelinePointerUp,
        {once:true}
    );
}

function beginTimelineResize(
    event,
    item,
    edge,
    track
){
    event.preventDefault();
    event.stopPropagation();

    const rect=track.getBoundingClientRect();

    state.timelineDrag={
        mode:edge,
        item,
        startX:event.clientX,
        originalStart:item.start,
        originalEnd:item.end,
        width:rect.width
    };

    document.addEventListener(
        'pointermove',
        timelinePointerMove
    );

    document.addEventListener(
        'pointerup',
        timelinePointerUp,
        {once:true}
    );
}

function timelinePointerMove(event){
    const drag=state.timelineDrag;

    if(!drag) return;

    const d=duration();

    const dt=
        (event.clientX-drag.startX)/
        drag.width*
        d;

    if(drag.mode==='move'){
        const length=
            drag.originalEnd-
            drag.originalStart;

        let start=
            drag.originalStart+dt;

        start=clamp(
            start,
            0,
            Math.max(0,d-length)
        );

        drag.item.start=start;
        drag.item.end=start+length;
    }

    if(drag.mode==='start'){
        drag.item.start=clamp(
            drag.originalStart+dt,
            0,
            drag.originalEnd-.05
        );
    }

    if(drag.mode==='end'){
        drag.item.end=clamp(
            drag.originalEnd+dt,
            drag.originalStart+.05,
            d
        );
    }

    markDirty();
    renderTimeline();
    renderObjects();
    renderConnections();
}

function timelinePointerUp(){
    state.timelineDrag=null;

    document.removeEventListener(
        'pointermove',
        timelinePointerMove
    );
}

/* =========================================================
 * SAVE / LOAD
 * ======================================================= */

async function saveLocal(){
    if(!state.project || !state.videoBlob) return;

    try{
        state.project.name=
            $('headerProjectName').value.trim() ||
            '名称未設定';

        state.project.videoName=
            state.project.videoName ||
            'video';

        state.project.videoKey=
            state.project.videoKey ||
            await saveVideo(
                state.videoBlob,
                state.project.videoName
            );

        state.project.savedAt=new Date().toISOString();

        await idbPut(
            'projects',
            clone(state.project)
        );

        setClean('ローカル保存済み');
        showMessage(
            'ローカルに保存しました。',
            true
        );
    }catch(error){
        console.error(error);
        showMessage(
            'ローカル保存失敗：'+error.message
        );
    }
}

async function saveServer(){
    if(!state.project) return;

    state.project.name=
        $('headerProjectName').value.trim();

    if(!state.project.name){
        showMessage('プロジェクト名を入力してください。');
        $('headerProjectName').focus();
        return;
    }

    try{
        const response=await fetch(
            '?api=save&_='+Date.now(),
            {
                method:'POST',
                cache:'no-store',
                headers:{
                    'Content-Type':'application/json'
                },
                body:JSON.stringify(
                    state.project
                )
            }
        );

        const data=await response.json();

        if(!data.ok){
            if(data.limit){
                await saveLocal();

                showMessage(
                    'サーバー保存上限のためローカルへ保存しました。',
                    true
                );

                return;
            }

            throw new Error(
                data.message ||
                '保存に失敗しました。'
            );
        }

        state.project.projectId=
            data.projectId;

        state.project.savedAt=
            data.savedAt;

        setClean('サーバー保存済み');

        await saveLocal();

        showMessage(
            'サーバーへ保存しました。',
            true
        );
    }catch(error){
        console.error(error);

        try{
            await saveLocal();

            showMessage(
                'サーバー保存に失敗したため、ローカルへ保存しました。',
                true
            );
        }catch(localError){
            showMessage(
                '保存失敗：'+error.message
            );
        }
    }
}

async function loadProject(id,storage){
    try{
        let project;

        if(storage==='local'){
            project=await idbGet(
                'projects',
                id
            );
        }else{
            const response=await fetch(
                '?api=load&id='+
                encodeURIComponent(id)+
                '&_='+Date.now(),
                {
                    cache:'no-store'
                }
            );

            const data=await response.json();

            if(!data.ok){
                throw new Error(data.message);
            }

            project=data.project;
        }

        if(!project){
            throw new Error(
                'プロジェクトが見つかりません。'
            );
        }

        project=normalizeProject(project);

        const blob=await openVideoBlob(
            project.videoKey
        );

        if(!blob){
            throw new Error(
                '動画本体がブラウザ内にありません。先に動画ファイルを読み込んでください。'
            );
        }

        await openEditor(
            project,
            blob
        );

        showMessage(
            'プロジェクトを読み込みました。',
            true
        );
    }catch(error){
        console.error(error);

        showMessage(
            '読み込み失敗：'+error.message
        );
    }
}

async function renderProjectList(){
    const box=$('projectList');

    box.innerHTML=
        '<div class="help">保存データを読み込み中…</div>';

    try{
        const serverResponse=await fetch(
            '?api=list&_='+Date.now(),
            {
                cache:'no-store'
            }
        );

        const server=await serverResponse.json();

        const local=await idbAll(
            'projects'
        );

        const serverIds=new Set(
            (server.projects || [])
                .map(p=>p.projectId)
        );

        const all=[
            ...(server.projects || [])
                .map(p=>({
                    ...p,
                    storage:'server'
                })),
            ...local
                .filter(p=>!serverIds.has(p.projectId))
                .map(p=>({
                    projectId:p.projectId,
                    name:p.name,
                    videoName:p.videoName,
                    savedAt:p.savedAt,
                    storage:'local'
                }))
        ];

        box.innerHTML='';

        const title=document.createElement('h3');
        title.textContent='保存済みプロジェクト';
        box.appendChild(title);

        if(!all.length){
            const empty=document.createElement('div');
            empty.className='help';
            empty.textContent='保存済みデータはありません。';
            box.appendChild(empty);
            return;
        }

        all.forEach(project=>{
            const row=document.createElement('div');
            row.className='project';

            const info=document.createElement('div');
            info.className='project-info';

            const name=document.createElement('div');
            name.className='project-name';
            name.textContent=project.name;

            const meta=document.createElement('div');
            meta.className='project-meta';

            meta.textContent=
                (project.storage==='server'
                    ? 'サーバー'
                    : 'ローカル')+
                ' / '+
                (project.videoName || '動画未設定')+
                ' / '+
                (project.savedAt || '');

            info.appendChild(name);
            info.appendChild(meta);

            const actions=document.createElement('div');
            actions.className='project-actions';

            const open=document.createElement('button');
            open.textContent='開く';

            open.addEventListener(
                'click',
                ()=>loadProject(
                    project.projectId,
                    project.storage
                )
            );

            actions.appendChild(open);

            const del=document.createElement('button');
            del.className='danger';
            del.textContent='削除';

            del.addEventListener(
                'click',
                async()=>{
                    if(!confirm(
                        'このプロジェクトを削除しますか？'
                    )){
                        return;
                    }

                    if(project.storage==='local'){
                        await idbDelete(
                            'projects',
                            project.projectId
                        );
                    }else{
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

                    renderProjectList();
                }
            );

            actions.appendChild(del);

            row.appendChild(info);
            row.appendChild(actions);

            box.appendChild(row);
        });

    }catch(error){
        console.error(error);

        box.innerHTML=
            '<div class="help">'+
            escapeHtml(
                '保存一覧取得失敗：'+error.message
            )+
            '</div>';
    }
}

/* =========================================================
 * JSON EXPORT / IMPORT
 * ======================================================= */

function exportProject(){
    if(!state.project) return;

    const project=clone(state.project);

    project.name=
        $('headerProjectName').value.trim() ||
        project.name ||
        'project';

    const blob=new Blob(
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

    const url=URL.createObjectURL(blob);

    const a=document.createElement('a');

    a.href=url;

    a.download=
        project.name
            .replace(/[\\/:*?"<>|]/g,'_')+
        '.json';

    a.click();

    URL.revokeObjectURL(url);

    showMessage(
        '編集プロジェクトを書き出しました。',
        true
    );
}

async function importProject(file){
    if(!file) return;

    try{
        const text=await file.text();
        const project=normalizeProject(
            JSON.parse(text)
        );

        if(!project.videoKey){
            throw new Error(
                'JSONに動画本体の情報がありません。'
            );
        }

        const blob=await openVideoBlob(
            project.videoKey
        );

        if(!blob){
            throw new Error(
                'このJSONに対応する動画本体がブラウザにありません。動画を先に読み込んでください。'
            );
        }

        await openEditor(
            project,
            blob
        );

        showMessage(
            'JSONプロジェクトを読み込みました。',
            true
        );
    }catch(error){
        console.error(error);

        showMessage(
            'JSON読み込み失敗：'+error.message
        );
    }
}

/* =========================================================
 * RENDER
 * ======================================================= */

function updateReadout(){
    const d=duration();
    const t=currentTime();

    $('timeReadout').textContent=
        formatTime(t)+
        ' / '+
        formatTime(d);

    if(d){
        $('seek').max=String(d);
        $('seek').value=
            clamp(t,0,d);
    }
}

function renderAll(){
    updateReadout();
    renderObjects();
    renderConnections();
    renderTimeline();
}

/* =========================================================
 * EVENTS
 * ======================================================= */

function bindEvents(){

    $('homeVideoBtn')
        .addEventListener(
            'click',
            chooseVideo
        );

    $('newVideoBtn')
        .addEventListener(
            'click',
            chooseVideo
        );

    $('videoFile')
        .addEventListener(
            'change',
            async event=>{
                const file=
                    event.target.files?.[0];

                event.target.value='';

                await handleVideoFile(file);
            }
        );

    $('homeLoadBtn')
        .addEventListener(
            'click',
            renderProjectList
        );

    $('loadProjectBtn')
        .addEventListener(
            'click',
            ()=>{
                $('home').style.display='flex';
                $('editor').style.display='none';
                renderProjectList();
            }
        );

    $('homeImportBtn')
        .addEventListener(
            'click',
            ()=>$('jsonFile').click()
        );

    $('importBtn')
        .addEventListener(
            'click',
            ()=>$('jsonFile').click()
        );

    $('jsonFile')
        .addEventListener(
            'change',
            async event=>{
                const file=
                    event.target.files?.[0];

                event.target.value='';

                await importProject(file);
            }
        );

    $('headerProjectName')
        .addEventListener(
            'input',
            ()=>{
                if(state.project){
                    state.project.name=
                        $('headerProjectName').value;
                    markDirty();
                }
            }
        );

    $('saveServerBtn')
        .addEventListener(
            'click',
            saveServer
        );

    $('saveLocalBtn')
        .addEventListener(
            'click',
            saveLocal
        );

    $('exportBtn')
        .addEventListener(
            'click',
            exportProject
        );

    $('backBtn')
        .addEventListener(
            'click',
            ()=>closeEditor()
        );

    $('playBtn')
        .addEventListener(
            'click',
            ()=>{
                $('recordedVideo')
                    .play()
                    .catch(console.error);
            }
        );

    $('pauseBtn')
        .addEventListener(
            'click',
            ()=>$('recordedVideo').pause()
        );

    $('seek')
        .addEventListener(
            'input',
            event=>{
                const d=duration();

                $('recordedVideo').currentTime=
                    clamp(
                        num(event.target.value),
                        0,
                        d
                    );
            }
        );

    $('recordedVideo')
        .addEventListener(
            'loadedmetadata',
            ()=>{
                state.project &&
                    (state.project.duration=
                        $('recordedVideo').duration);

                $('seek').min='0';
                $('seek').max=
                    String(
                        $('recordedVideo').duration
                    );
                $('seek').step='.01';
                $('seek').disabled=false;

                fitStage();
                renderAll();
            }
        );

    $('recordedVideo')
        .addEventListener(
            'timeupdate',
            ()=>{
                updateReadout();
                renderObjects();
                renderConnections();
                renderTimeline();
            }
        );

    $('recordedVideo')
        .addEventListener(
            'seeked',
            renderAll
        );

    $('objects')
        .addEventListener(
            'pointerdown',
            beginObjectPointer
        );

    $('objects')
        .addEventListener(
            'pointermove',
            moveObjectPointer
        );

    $('objects')
        .addEventListener(
            'pointerup',
            endObjectPointer
        );

    $('objects')
        .addEventListener(
            'pointercancel',
            endObjectPointer
        );

    $('objects')
        .addEventListener(
            'pointerdown',
            event=>{
                const cp=event.target.closest(
                    '.connection-point'
                );

                if(!cp) return;

                const object=cp.closest(
                    '.edit-object'
                );

                if(!object) return;

                const element=
                    state.project.elements.find(
                        e=>e.id===object.dataset.id
                    );

                if(!element) return;

                event.preventDefault();
                event.stopPropagation();

                if(state.selected.size===2){
                    const other=
                        selectedElements()
                            .find(
                                e=>e.id!==element.id
                            );

                    if(other){
                        connectSelectedElements();
                    }
                }
            },
            true
        );

    $('videoStage')
        .addEventListener(
            'contextmenu',
            showContextMenu
        );

    $('connectors')
        .addEventListener(
            'contextmenu',
            event=>{
                const hit=event.target.closest(
                    '.connector-hit'
                );

                if(!hit) return;

                event.preventDefault();

                selectConnection(
                    hit.dataset.id
                );

                showContextMenu(event);
            }
        );

    $('contextMenu')
        .addEventListener(
            'click',
            event=>{
                const button=
                    event.target.closest(
                        'button'
                    );

                if(!button) return;

                contextAction(
                    button.dataset.action
                );
            }
        );

    $('objectCancel')
        .addEventListener(
            'click',
            cancelObjectModal
        );

    $('objectApply')
        .addEventListener(
            'click',
            applyObjectModal
        );

    $('connectionCancel')
        .addEventListener(
            'click',
            ()=>{
                $('connectionModal')
                    .style.display='none';
            }
        );

    $('connectionApply')
        .addEventListener(
            'click',
            applyConnectionModal
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
            if(event.key==='Escape'){
                hideContextMenu();

                $('objectModal')
                    .style.display='none';

                $('connectionModal')
                    .style.display='none';
            }

            if(
                event.key==='Delete' &&
                !$('objectModal').matches(
                    ':not([style*="display: flex"])'
                )
            ){
                if(
                    document.activeElement &&
                    (
                        document.activeElement.tagName==='INPUT' ||
                        document.activeElement.tagName==='TEXTAREA'
                    )
                ){
                    return;
                }

                deleteSelection();
            }
        }
    );

    window.addEventListener(
        'resize',
        ()=>{
            if(
                $('editor').style.display!=='none'
            ){
                fitStage();
                renderAll();
            }
        }
    );

    window.addEventListener(
        'beforeunload',
        event=>{
            if(state.dirty){
                event.preventDefault();
                event.returnValue='';
            }
        }
    );
}

/* =========================================================
 * INIT
 * ======================================================= */

async function init(){
    try{
        await openDB();
    }catch(error){
        console.error(error);

        showMessage(
            'ブラウザ保存領域を初期化できません：'+
            error.message
        );
    }

    bindEvents();
    renderProjectList();
}

init();
</script>

</body>
</html>

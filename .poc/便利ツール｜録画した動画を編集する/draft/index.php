<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * 編集データ:
 *   PHPサーバー data/projects/*.json
 *   IndexedDB
 *
 * 動画:
 *   ブラウザで選択したローカルファイル
 *   ※動画本体はサーバーへ自動アップロードしない
 *
 * 書き出し:
 *   編集プロジェクトJSON
 *
 * MP4再エンコード:
 *   ブラウザ単体では行わない
 */

const APP_VERSION = 30;
const MAX_SERVER_PROJECTS = 20;

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
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
                'message' => 'data/projects に書き込めません。'
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

        $name = trim((string)($payload['name'] ?? '名称未設定'));

        if ($name === '') {
            $name = '名称未設定';
        }

        if (mb_strlen($name) > 120) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクト名は120文字以内にしてください。'
            ], 422);
        }

        $existing = is_file(projectPath($id));
        $projects = readServerProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' =>
                    'サーバー保存上限に達しました。' .
                    'このプロジェクトはローカル保存してください。'
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

        if (
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
    --border:#414750;
    --text:#f5f7fa;
    --muted:#9ca5af;
    --blue:#1976d2;
    --red:#ef4444;
    --green:#277a47;
    --orange:#e38b28;
    --yellow:#ffd447;
}

*{
    box-sizing:border-box;
}

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

body{
    overflow:hidden;
}

button,input,select,textarea{
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

button.primary{
    background:var(--blue);
}

button.success{
    background:var(--green);
}

button.danger{
    background:#a73535;
}

input,select,textarea{
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

.video-area{
    flex:1 1 auto;
    min-height:220px;
    max-height:46vh;
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
    flex:none;
    width:min(70vw,900px);
    height:min(38vh,480px);
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
    justify-content:flex-start;
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

.connector{
    fill:none;
    pointer-events:visibleStroke;
    cursor:pointer;
}

.connector.selected{
    filter:drop-shadow(0 0 4px #ffd447);
}

.timeline{
    flex:0 0 330px;
    min-height:330px;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:7px 12px;
    overflow:auto;
}

.timeline-toolbar{
    height:36px;
    display:flex;
    align-items:center;
    gap:6px;
    position:sticky;
    top:0;
    z-index:30;
    background:#191c20;
}

#playToggle{
    width:42px;
    padding:5px;
}

#currentTime{
    color:#d8dde2;
    font-variant-numeric:tabular-nums;
    min-width:145px;
}

#timelineZoom{
    width:100px;
}

.timeline-scroll{
    position:relative;
    min-width:100%;
    overflow-x:auto;
    overflow-y:visible;
}

.timeline-content{
    position:relative;
    min-width:100%;
}

.timeline-scale{
    position:relative;
    height:30px;
    color:#8f969f;
    font-size:10px;
    border-bottom:1px solid #444;
}

.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    white-space:nowrap;
}

.timeline-row{
    display:grid;
    grid-template-columns:70px max-content;
    gap:7px;
    margin-top:17px;
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
    height:38px;
    min-width:500px;
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
    top:5px;
    height:28px;
    min-width:16px;
    border-radius:3px;
    cursor:grab;
    z-index:5;
    touch-action:none;
}

.track-item:active{
    cursor:grabbing;
}

.track-item.comment{
    background:#1976d2;
}

.track-item.box{
    background:#ef5350;
}

.track-item.skip{
    background:#e38b28;
}

.track-item.connection{
    background:#7050ad;
}

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
    top:-3px;
    bottom:-3px;
    width:13px;
    z-index:10;
    cursor:ew-resize;
}

.track-handle.left{
    left:-6px;
}

.track-handle.right{
    right:-6px;
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

.playhead{
    position:absolute;
    top:0;
    bottom:0;
    width:2px;
    background:#ef4444;
    box-shadow:0 0 6px #ef4444;
    z-index:100;
    pointer-events:none;
}

.playhead:before{
    content:"";
    position:absolute;
    top:-2px;
    left:-5px;
    width:12px;
    height:12px;
    border-radius:50%;
    background:#ef4444;
}

.timeline-seek{
    position:absolute;
    left:0;
    top:0;
    height:30px;
    width:100%;
    cursor:pointer;
    z-index:3;
}

.connection-status{
    padding:5px 9px;
    border-radius:5px;
    background:#15181c;
    border:1px solid #414750;
    color:#d9dee4;
    font-size:11px;
}

#editorFooter{
    min-height:40px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:7px;
    background:#1b1e22;
    border-top:1px solid #383d44;
}

#contextMenu{
    position:fixed;
    z-index:7000;
    display:none;
    width:285px;
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
    width:min(700px,96vw);
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
    grid-template-columns:120px 1fr;
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

#editor.locked .video-area:after{
    content:"動画を読み込んでください";
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#0009;
    color:#fff;
    font-size:18px;
    pointer-events:none;
}

@media(max-height:700px){
    .video-area{
        max-height:40vh;
        min-height:180px;
    }

    .timeline{
        flex-basis:300px;
        min-height:300px;
    }

    #videoStage{
        height:min(31vh,400px);
    }
}
</style>
</head>

<body>

<header>
    <h1>動画上直接編集ツール</h1>
    <div id="status">準備中</div>
</header>

<div id="message"></div>

<section id="home">
    <div class="home-card">
        <h2>動画を編集</h2>

        <p>
            まず動画を読み込んでください。
            動画の読み込みが完了するまで編集操作は開始できません。
            動画画面上で右クリックすると要素を追加できます。
        </p>

        <div class="home-actions">
            <button class="primary" id="openVideo">
                動画を読み込んで編集
            </button>

            <button id="importProject">
                保存データを読み込む
            </button>

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
        </div>

        <h3>保存済み</h3>
        <div id="projectList"></div>
    </div>
</section>

<section id="editor" class="locked">

    <div class="editor-top">

        <button id="backHome">
            ← 戻る
        </button>

        <input
            id="editorProjectName"
            value="名称未設定"
            aria-label="プロジェクト名"
        >

        <button class="success" id="saveProject">
            保存
        </button>

        <button id="exportProject">
            書き出し
        </button>

        <button id="loadVideoButton">
            動画変更
        </button>

        <input
            id="editorVideoFile"
            type="file"
            accept="video/*"
            class="hidden"
        >

        <span id="editorStatus">
            動画未読込
        </span>

    </div>

    <div class="editor-main">

        <div class="video-area" id="videoArea">

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

        <div class="timeline">

            <div class="timeline-toolbar">

                <button id="playToggle" disabled>
                    ▶
                </button>

                <span id="currentTime">
                    00:00.000 / 00:00.000
                </span>

                <button id="timelineZoomOut" disabled>
                    −
                </button>

                <select id="timelineZoom" disabled>
                    <option value="0.5">0.5×</option>
                    <option value="1" selected>1×</option>
                    <option value="2">2×</option>
                    <option value="3">3×</option>
                    <option value="5">5×</option>
                </select>

                <button id="timelineZoomIn" disabled>
                    ＋
                </button>

                <span style="color:#888;font-size:11px">
                    全トラック共通時間軸
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
                        class="timeline-tracks"
                        id="timelineTracks"
                    ></div>

                    <div
                        id="playhead"
                        class="playhead"
                    ></div>

                </div>

            </div>

        </div>

    </div>

    <div id="editorFooter">

        <span
            id="connectionStatus"
            class="connection-status"
        >
            Shift＋クリックで2要素を選択 →
            右クリック「選択した2要素を接続」
        </span>

    </div>

</section>

<div id="contextMenu">

    <button data-action="add-comment">
        ＋ テキスト
    </button>

    <button data-action="add-box">
        ＋ 強調枠
    </button>

    <button data-action="add-skip">
        ＋ スキップ区間
    </button>

    <div class="context-separator"></div>

    <button data-action="edit">
        編集
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
        🔗 選択した2要素を接続
    </button>

</div>

<div
    id="elementModal"
    class="modal-backdrop"
>

    <div class="modal">

        <h3 id="modalTitle">
            要素を編集
        </h3>

        <div class="form-row">
            <label>種類</label>
            <div id="modalType"></div>
        </div>

        <div
            class="form-row"
            id="textRow"
        >
            <label>テキスト</label>

            <textarea
                id="elementText"
            ></textarea>
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
            <label>色</label>

            <div>
                <div
                    id="palette"
                    class="palette"
                ></div>

                <input
                    id="elementColor"
                    type="color"
                >
            </div>
        </div>

        <div class="form-row">
            <label>線幅</label>

            <input
                id="elementBorderWidth"
                type="number"
                min="0"
                max="20"
                step="1"
            >
        </div>

        <div class="form-row">
            <label>角丸</label>

            <input
                id="elementRadius"
                type="number"
                min="0"
                max="100"
                step="1"
            >
        </div>

        <div
            class="form-row"
            id="fontSizeRow"
        >
            <label>文字サイズ</label>

            <input
                id="elementFontSize"
                type="number"
                min="8"
                max="200"
                step="1"
            >
        </div>

        <div
            class="form-row"
            id="fontWeightRow"
        >
            <label>文字太さ</label>

            <select id="elementFontWeight">
                <option value="400">標準</option>
                <option value="500">中</option>
                <option value="600">やや太い</option>
                <option value="700">太字</option>
                <option value="800">極太</option>
            </select>
        </div>

        <div class="modal-actions">

            <button id="modalCancel">
                キャンセル
            </button>

            <button
                class="primary"
                id="modalSave"
            >
                保存
            </button>

        </div>

    </div>

</div>

<div
    id="connectionModal"
    class="modal-backdrop"
>

    <div class="modal">

        <h3>
            接続線を編集
        </h3>

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
                <option value="5 4">破線</option>
                <option value="2 3">点線</option>
            </select>
        </div>

        <div class="form-row">
            <label>矢印</label>

            <select id="connectionArrow">
                <option value="1">あり</option>
                <option value="0">なし</option>
            </select>
        </div>

        <div class="modal-actions">

            <button
                class="danger"
                id="connectionDelete"
            >
                接続を削除
            </button>

            <button id="connectionCancel">
                キャンセル
            </button>

            <button
                class="primary"
                id="connectionSave"
            >
                保存
            </button>

        </div>

    </div>

</div>

<script>
'use strict';

/*
 * PHPの定数をJavaScriptへ確実に渡す。
 * 以前発生していた
 * "APP_VERSION is not defined"
 * をここで防止する。
 */
const APP_VERSION =
    <?= json_encode(APP_VERSION) ?>;

const $ = id =>
    document.getElementById(id);

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

const state = {
    project:null,
    videoObjectUrl:null,
    db:null,

    selectedId:null,
    selectedType:null,
    multiSelected:[],

    context:null,

    modalTarget:null,
    connectionModalTarget:null,

    dirty:false,

    zoom:1,

    drag:null,
    timelineDrag:null,

    messageTimer:null,

    /*
     * 動画の編集可能状態。
     * durationが確定するまではfalse。
     */
    editorReady:false
};

/* =========================================================
 * Common
 * ======================================================= */

function clamp(value,min,max){
    return Math.min(
        Math.max(value,min),
        max
    );
}

function uid(prefix){
    return (
        prefix +
        '-' +
        Date.now().toString(36) +
        '-' +
        Math.random()
            .toString(36)
            .slice(2,10)
    );
}

function fmt(seconds){
    seconds =
        Math.max(
            0,
            Number(seconds) || 0
        );

    const m =
        Math.floor(seconds / 60);

    const s =
        seconds - m * 60;

    return (
        String(m).padStart(2,'0') +
        ':' +
        s.toFixed(3).padStart(6,'0')
    );
}

function duration(){
    const video = $('recordedVideo');

    if(
        video &&
        Number.isFinite(video.duration) &&
        video.duration > 0
    ){
        return video.duration;
    }

    return Number(
        state.project?.videoDuration || 0
    );
}

function currentTime(){
    const video = $('recordedVideo');

    return video
        ? Number(video.currentTime) || 0
        : 0;
}

function message(text,ok=false){
    const el = $('message');

    if(!el){
        return;
    }

    clearTimeout(state.messageTimer);

    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    state.messageTimer =
        setTimeout(
            ()=>{
                el.style.display = 'none';
            },
            3500
        );
}

function markDirty(){
    if(!state.project){
        return;
    }

    state.dirty = true;

    const status = $('editorStatus');

    if(status){
        status.textContent =
            state.editorReady
                ? '未保存'
                : '動画読込中…';
    }
}

function getElement(id){
    return state.project?.elements?.find(
        e => e.id === id
    ) || null;
}

function getConnection(id){
    return state.project?.connections?.find(
        c => c.id === id
    ) || null;
}

/* =========================================================
 * Project
 * ======================================================= */

function createEmptyProject(){
    return {
        version:APP_VERSION,
        projectId:uid('project'),
        name:'新しい編集',

        videoName:'',
        videoType:'',
        videoSize:0,
        videoDuration:0,

        elements:[],
        connections:[],

        createdAt:
            new Date().toISOString(),

        savedAt:''
    };
}

function resetSelection(){
    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];
}

function showHome(){
    $('editor').style.display = 'none';
    $('home').style.display = 'flex';

    const video =
        $('recordedVideo');

    if(video){
        video.pause();
        video.removeAttribute('src');
        video.load();
    }

    if(state.videoObjectUrl){
        URL.revokeObjectURL(
            state.videoObjectUrl
        );

        state.videoObjectUrl = null;
    }

    state.editorReady = false;

    renderProjectList();
}

function showEditor(){
    $('home').style.display = 'none';
    $('editor').style.display = 'flex';
}

function setEditorLocked(locked){
    const editor = $('editor');

    if(editor){
        editor.classList.toggle(
            'locked',
            locked
        );
    }

    const controls = [
        'playToggle',
        'timelineZoomOut',
        'timelineZoom',
        'timelineZoomIn'
    ];

    controls.forEach(id=>{
        const el = $(id);

        if(el){
            el.disabled = locked;
        }
    });
}

function newProject(){
    state.project =
        createEmptyProject();

    resetSelection();

    state.dirty = false;
    state.editorReady = false;

    $('editorProjectName').value =
        state.project.name;

    $('editorStatus').textContent =
        '動画未読込';

    showEditor();
    setEditorLocked(true);

    renderAll();
}

/* =========================================================
 * Element model
 * ======================================================= */

function makeElement(type,start){
    const d = duration();

    const s =
        clamp(
            Number(start) || 0,
            0,
            Math.max(0,d-.05)
        );

    const e =
        Math.min(
            d,
            s + Math.min(5,Math.max(.05,d-s))
        );

    const base = {
        id:uid('element'),
        type,

        start:s,
        end:Math.max(s+.05,e),

        x:10,
        y:10,
        w:type === 'box' ? 35 : 30,
        h:type === 'box' ? 25 : 12,

        color:
            type === 'skip'
                ? '#f59e0b'
                : '#3b82f6',

        borderWidth:
            type === 'box'
                ? 2
                : 1,

        borderStyle:
            type === 'skip'
                ? 'dashed'
                : 'solid',

        radius:5,
        opacity:1,

        text:
            type === 'comment'
                ? 'テキスト'
                : '',

        fontSize:24,
        fontWeight:600
    };

    return normalizeElement(base);
}

function normalizeElement(e){
    const d = duration();

    e.start =
        clamp(
            Number(e.start) || 0,
            0,
            d || Number.MAX_SAFE_INTEGER
        );

    e.end =
        Number(e.end);

    if(!Number.isFinite(e.end)){
        e.end =
            Math.min(
                d || e.start + 5,
                e.start + 5
            );
    }

    if(d > 0){
        e.end =
            clamp(
                e.end,
                Math.min(d,e.start+.05),
                d
            );
    }else{
        e.end =
            Math.max(
                e.start+.05,
                e.end
            );
    }

    e.x = clamp(Number(e.x)||0,0,99);
    e.y = clamp(Number(e.y)||0,0,99);

    e.w = clamp(
        Number(e.w)||20,
        1,
        100-e.x
    );

    e.h = clamp(
        Number(e.h)||15,
        1,
        100-e.y
    );

    e.borderWidth =
        clamp(
            Number(e.borderWidth)||0,
            0,
            20
        );

    e.radius =
        clamp(
            Number(e.radius)||0,
            0,
            100
        );

    e.opacity =
        clamp(
            Number(e.opacity),
            0,
            1
        );

    if(!Number.isFinite(e.opacity)){
        e.opacity = 1;
    }

    return e;
}

/* =========================================================
 * Video loading
 * ======================================================= */

async function handleVideo(file){
    if(!file){
        return;
    }

    if(
        !file.type ||
        !file.type.startsWith('video/')
    ){
        message(
            '動画ファイルを選択してください。'
        );
        return;
    }

    if(!state.project){
        state.project =
            createEmptyProject();
    }

    const video =
        $('recordedVideo');

    if(!video){
        message(
            '動画表示領域を初期化できません。'
        );
        return;
    }

    /*
     * 読込中は必ず編集禁止。
     */
    state.editorReady = false;
    setEditorLocked(true);

    video.pause();

    if(state.videoObjectUrl){
        URL.revokeObjectURL(
            state.videoObjectUrl
        );

        state.videoObjectUrl = null;
    }

    const url =
        URL.createObjectURL(file);

    state.videoObjectUrl = url;

    state.project.videoName =
        file.name;

    state.project.videoType =
        file.type;

    state.project.videoSize =
        file.size;

    state.project.videoDuration = 0;

    resetSelection();

    $('editorProjectName').value =
        state.project.name;

    $('editorStatus').textContent =
        '動画読込中…';

    showEditor();

    /*
     * once listenerを使わず、
     * 古いイベントを確実に置き換える。
     */
    video.onloadedmetadata = null;
    video.oncanplay = null;
    video.onerror = null;

    const loaded = new Promise(
        (resolve,reject)=>{
            video.onloadedmetadata = ()=>{
                const d =
                    Number(video.duration);

                if(
                    !Number.isFinite(d) ||
                    d <= 0
                ){
                    reject(
                        new Error(
                            '動画の長さを取得できませんでした。'
                        )
                    );
                    return;
                }

                state.project.videoDuration =
                    d;

                resolve(d);
            };

            video.onerror = ()=>{
                reject(
                    new Error(
                        '動画を読み込めませんでした。'
                    )
                );
            };
        }
    );

    video.src = url;
    video.load();

    try{
        await loaded;

        /*
         * duration確定後にだけ編集可能。
         */
        state.editorReady = true;

        setEditorLocked(false);

        $('editorStatus').textContent =
            '編集可能';

        /*
         * 既存要素を新しい動画長へ正規化。
         */
        state.project.elements.forEach(
            normalizeElement
        );

        state.project.connections =
            state.project.connections.map(
                normalizeConnection
            );

        renderAll();

        message(
            `動画を読み込みました（${fmt(duration())}）`,
            true
        );

    }catch(error){

        state.editorReady = false;
        setEditorLocked(true);

        $('editorStatus').textContent =
            '動画読込エラー';

        message(
            error.message ||
            '動画を読み込めませんでした。'
        );
    }
}

function requireEditor(){
    if(
        !state.project ||
        !state.editorReady ||
        duration() <= 0
    ){
        message(
            '動画の読み込みが完了してから編集してください。'
        );

        return false;
    }

    return true;
}

/* =========================================================
 * Video object rendering
 * ======================================================= */

function renderObjects(){
    const container =
        $('objects');

    if(!container){
        return;
    }

    container.innerHTML = '';

    if(!state.project || !state.editorReady){
        return;
    }

    const t = currentTime();

    for(const e of state.project.elements){

        if(
            t < e.start ||
            t > e.end
        ){
            continue;
        }

        const el =
            document.createElement('div');

        el.className =
            'edit-object ' +
            e.type;

        if(
            e.id === state.selectedId &&
            state.selectedType === 'element'
        ){
            el.classList.add('selected');
        }

        if(
            state.multiSelected.includes(
                e.id
            )
        ){
            el.classList.add(
                'multi-selected'
            );
        }

        el.dataset.id = e.id;

        el.style.left =
            `${e.x}%`;

        el.style.top =
            `${e.y}%`;

        el.style.width =
            `${e.w}%`;

        el.style.height =
            `${e.h}%`;

        el.style.border =
            `${e.borderWidth}px ${e.borderStyle} ${e.color}`;

        el.style.borderRadius =
            `${e.radius}px`;

        el.style.opacity =
            String(e.opacity);

        if(e.type === 'comment'){

            el.style.fontSize =
                `${e.fontSize}px`;

            el.style.fontWeight =
                String(e.fontWeight);

            el.style.color =
                e.color;

            el.textContent =
                e.text || 'テキスト';
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

        const resize =
            document.createElement('div');

        resize.className =
            'resize-handle';

        resize.addEventListener(
            'pointerdown',
            event=>{
                beginElementResize(
                    event,
                    e
                );
            }
        );

        el.appendChild(resize);

        el.addEventListener(
            'pointerdown',
            event=>{
                if(
                    event.button !== 0 ||
                    event.target === resize
                ){
                    return;
                }

                selectElement(
                    e.id,
                    event.shiftKey
                );

                beginElementMove(
                    event,
                    e
                );
            }
        );

        el.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                openElementModal(e);
            }
        );

        el.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectElement(
                    e.id,
                    event.shiftKey
                );

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
 * Selection
 * ======================================================= */

function selectElement(id,multi=false){
    const e = getElement(id);

    if(!e){
        return;
    }

    if(multi){

        /*
         * Shiftクリックはトグル。
         * 同じ種類・同じ時間でもIDで判定する。
         */
        const index =
            state.multiSelected.indexOf(id);

        if(index >= 0){
            state.multiSelected.splice(
                index,
                1
            );
        }else{
            state.multiSelected.push(id);
        }

        /*
         * 接続対象として扱うのは最大2個。
         */
        if(state.multiSelected.length > 2){
            state.multiSelected =
                state.multiSelected.slice(-2);
        }

        state.selectedId = id;
        state.selectedType = 'element';

    }else{

        state.selectedId = id;
        state.selectedType = 'element';
        state.multiSelected = [id];
    }

    renderAll();
}

function selectConnection(id){
    if(!getConnection(id)){
        return;
    }

    state.selectedId = id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    renderAll();
}

/* =========================================================
 * Add / duplicate / delete
 * ======================================================= */

function addElement(type){
    if(!requireEditor()){
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

function duplicateSelected(){
    if(
        state.selectedType !== 'element'
    ){
        message(
            '複製する要素を選択してください。'
        );
        return;
    }

    const source =
        getElement(
            state.selectedId
        );

    if(!source){
        return;
    }

    const d = duration();

    const copy =
        JSON.parse(
            JSON.stringify(source)
        );

    copy.id =
        uid('element');

    const length =
        Math.max(
            .05,
            copy.end - copy.start
        );

    copy.x =
        clamp(
            copy.x + 3,
            0,
            100-copy.w
        );

    copy.y =
        clamp(
            copy.y + 3,
            0,
            100-copy.h
        );

    copy.start =
        clamp(
            copy.start + .5,
            0,
            Math.max(0,d-length)
        );

    copy.end =
        Math.min(
            d,
            copy.start + length
        );

    state.project.elements.push(
        normalizeElement(copy)
    );

    seek(copy.start);
    selectElement(
        copy.id,
        false
    );

    markDirty();
    renderAll();
}

function deleteSelected(){
    if(!state.project){
        return;
    }

    if(
        state.selectedType ===
        'connection'
    ){

        state.project.connections =
            state.project.connections.filter(
                c =>
                    c.id !==
                    state.selectedId
            );

        resetSelection();

        markDirty();
        renderAll();

        return;
    }

    if(
        state.selectedType ===
        'element'
    ){

        const id =
            state.selectedId;

        state.project.elements =
            state.project.elements.filter(
                e =>
                    e.id !== id
            );

        state.project.connections =
            state.project.connections.filter(
                c =>
                    c.from !== id &&
                    c.to !== id
            );

        resetSelection();

        markDirty();
        renderAll();
    }
}

/* =========================================================
 * Element pointer drag
 * ======================================================= */

function beginElementMove(event,e){
    if(!requireEditor()){
        return;
    }

    const stage =
        $('videoStage')
            .getBoundingClientRect();

    state.drag = {
        mode:'move',
        id:e.id,

        startX:event.clientX,
        startY:event.clientY,

        originalX:e.x,
        originalY:e.y,

        stageW:Math.max(
            1,
            stage.width
        ),

        stageH:Math.max(
            1,
            stage.height
        ),

        pointerId:event.pointerId
    };

    event.preventDefault();
}

function beginElementResize(event,e){
    if(!requireEditor()){
        return;
    }

    const stage =
        $('videoStage')
            .getBoundingClientRect();

    state.drag = {
        mode:'resize',
        id:e.id,

        startX:event.clientX,
        startY:event.clientY,

        originalW:e.w,
        originalH:e.h,

        stageW:Math.max(
            1,
            stage.width
        ),

        stageH:Math.max(
            1,
            stage.height
        ),

        pointerId:event.pointerId
    };

    event.stopPropagation();
    event.preventDefault();
}

function moveElementPointer(event){
    const drag =
        state.drag;

    if(!drag){
        return;
    }

    const e =
        getElement(drag.id);

    if(!e){
        state.drag = null;
        return;
    }

    const dx =
        (
            event.clientX -
            drag.startX
        ) /
        drag.stageW *
        100;

    const dy =
        (
            event.clientY -
            drag.startY
        ) /
        drag.stageH *
        100;

    if(
        drag.mode === 'move'
    ){

        e.x =
            clamp(
                drag.originalX + dx,
                0,
                100-e.w
            );

        e.y =
            clamp(
                drag.originalY + dy,
                0,
                100-e.h
            );

    }else{

        e.w =
            clamp(
                drag.originalW + dx,
                1,
                100-e.x
            );

        e.h =
            clamp(
                drag.originalH + dy,
                1,
                100-e.y
            );
    }

    markDirty();

    renderObjects();
    renderConnectors();
    renderTimeline();
}

function endElementPointer(){
    state.drag = null;
}

/* =========================================================
 * Connections
 * ======================================================= */

function normalizeConnection(c){
    const d = duration();

    c.start =
        clamp(
            Number(c.start) || 0,
            0,
            d
        );

    c.end =
        clamp(
            Number(c.end),
            c.start+.05,
            d
        );

    if(c.end <= c.start){
        c.end =
            Math.min(
                d,
                c.start+.05
            );
    }

    c.fromPoint =
        ['n','e','s','w'].includes(
            c.fromPoint
        )
            ? c.fromPoint
            : 'e';

    c.toPoint =
        ['n','e','s','w'].includes(
            c.toPoint
        )
            ? c.toPoint
            : 'w';

    c.width =
        clamp(
            Number(c.width)||1.5,
            .5,
            10
        );

    c.color =
        c.color ||
        getElement(c.from)?.color ||
        '#ffffff';

    c.arrow =
        Number(c.arrow) ? 1 : 0;

    return c;
}

function connectSelected(){
    if(!requireEditor()){
        return;
    }

    if(
        state.multiSelected.length !== 2
    ){
        message(
            'Shiftキーを押しながら2つの要素を選択してください。'
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
            '接続対象が見つかりません。'
        );
        return;
    }

    const duplicate =
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

    if(duplicate){
        message(
            'その2要素は既に接続されています。'
        );
        return;
    }

    const connection =
        normalizeConnection({
            id:uid('connection'),

            from:fromId,
            to:toId,

            fromPoint:'e',
            toPoint:'w',

            start:
                Math.min(
                    from.start,
                    to.start
                ),

            end:
                Math.max(
                    from.end,
                    to.end
                ),

            /*
             * 初期値は始点要素の枠色。
             */
            color:
                from.color ||
                '#ffffff',

            /*
             * 細い初期値。
             */
            width:1.5,

            dash:'',
            arrow:1
        });

    state.project.connections.push(
        connection
    );

    state.selectedId =
        connection.id;

    state.selectedType =
        'connection';

    state.multiSelected = [];

    markDirty();
    renderAll();

    message(
        '2要素を接続しました。',
        true
    );
}

function pointForElement(e,point){
    const x = e.x;
    const y = e.y;

    if(point === 'n'){
        return {
            x:x + e.w/2,
            y:y
        };
    }

    if(point === 'e'){
        return {
            x:x + e.w,
            y:y + e.h/2
        };
    }

    if(point === 's'){
        return {
            x:x + e.w/2,
            y:y + e.h
        };
    }

    return {
        x:x,
        y:y + e.h/2
    };
}

function renderConnectors(){
    const svg =
        $('connectors');

    if(!svg){
        return;
    }

    svg.innerHTML = '';

    if(
        !state.project ||
        !state.editorReady
    ){
        return;
    }

    const stage =
        $('videoStage')
            .getBoundingClientRect();

    const width =
        Math.max(1,stage.width);

    const height =
        Math.max(1,stage.height);

    svg.setAttribute(
        'viewBox',
        `0 0 ${width} ${height}`
    );

    /*
     * 矢印定義。
     */
    const defs =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'defs'
        );

    const marker =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'marker'
        );

    marker.setAttribute(
        'id',
        'arrowhead'
    );

    marker.setAttribute(
        'markerWidth',
        '7'
    );

    marker.setAttribute(
        'markerHeight',
        '7'
    );

    marker.setAttribute(
        'refX',
        '6'
    );

    marker.setAttribute(
        'refY',
        '3.5'
    );

    marker.setAttribute(
        'orient',
        'auto'
    );

    const arrowPath =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

    arrowPath.setAttribute(
        'd',
        'M0,0 L7,3.5 L0,7 Z'
    );

    arrowPath.setAttribute(
        'fill',
        '#fff'
    );

    marker.appendChild(
        arrowPath
    );

    defs.appendChild(
        marker
    );

    svg.appendChild(
        defs
    );

    const t =
        currentTime();

    for(
        const c of
        state.project.connections
    ){

        /*
         * 接続自体の時間範囲外では表示しない。
         */
        if(
            t < c.start ||
            t > c.end
        ){
            continue;
        }

        const from =
            getElement(c.from);

        const to =
            getElement(c.to);

        if(!from || !to){
            continue;
        }

        /*
         * 現在時刻で両要素が存在しない場合は
         * 線を出せないため非表示。
         */
        if(
            t < from.start ||
            t > from.end ||
            t < to.start ||
            t > to.end
        ){
            continue;
        }

        const p1 =
            pointForElement(
                from,
                c.fromPoint
            );

        const p2 =
            pointForElement(
                to,
                c.toPoint
            );

        const x1 =
            p1.x / 100 * width;

        const y1 =
            p1.y / 100 * height;

        const x2 =
            p2.x / 100 * width;

        const y2 =
            p2.y / 100 * height;

        const line =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'line'
            );

        line.setAttribute(
            'x1',
            String(x1)
        );

        line.setAttribute(
            'y1',
            String(y1)
        );

        line.setAttribute(
            'x2',
            String(x2)
        );

        line.setAttribute(
            'y2',
            String(y2)
        );

        line.setAttribute(
            'stroke',
            c.color || from.color || '#fff'
        );

        line.setAttribute(
            'stroke-width',
            String(
                Number(c.width) || 1.5
            )
        );

        if(c.dash){
            line.setAttribute(
                'stroke-dasharray',
                c.dash
            );
        }

        if(Number(c.arrow)){
            line.setAttribute(
                'marker-end',
                'url(#arrowhead)'
            );
        }

        line.classList.add(
            'connector'
        );

        if(
            c.id === state.selectedId &&
            state.selectedType ===
                'connection'
        ){
            line.classList.add(
                'selected'
            );
        }

        line.dataset.id =
            c.id;

        line.addEventListener(
            'click',
            event=>{
                event.stopPropagation();

                selectConnection(
                    c.id
                );
            }
        );

        line.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectConnection(
                    c.id
                );

                openConnectionModal(c);
            }
        );

        line.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                selectConnection(
                    c.id
                );

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

        svg.appendChild(
            line
        );
    }
}

/* =========================================================
 * Timeline
 * ======================================================= */

function timelineWidth(){
    const d =
        duration();

    if(!d){
        return 800;
    }

    /*
     * すべての時間軸でこの値だけを使用する。
     */
    const pixelsPerSecond =
        70 * state.zoom;

    return Math.max(
        800,
        d * pixelsPerSecond
    );
}

function timeToX(time){
    const d =
        duration();

    const width =
        timelineWidth();

    if(!d){
        return 0;
    }

    return (
        clamp(
            Number(time)||0,
            0,
            d
        ) / d
    ) * width;
}

function xToTime(x){
    const d =
        duration();

    const width =
        timelineWidth();

    if(!d || !width){
        return 0;
    }

    return clamp(
        x / width * d,
        0,
        d
    );
}

function chooseScaleStep(d){
    if(d <= 10) return 1;
    if(d <= 30) return 2;
    if(d <= 60) return 5;
    if(d <= 180) return 10;
    if(d <= 600) return 30;
    return 60;
}

function renderTimeline(){
    const content =
        $('timelineContent');

    const scale =
        $('timelineScale');

    const tracks =
        $('timelineTracks');

    if(
        !content ||
        !scale ||
        !tracks
    ){
        return;
    }

    const d =
        duration();

    const width =
        timelineWidth();

    content.style.width =
        `${width}px`;

    scale.style.width =
        `${width}px`;

    tracks.style.width =
        `${width}px`;

    scale.innerHTML = '';
    tracks.innerHTML = '';

    if(!d){
        updatePlayhead();
        return;
    }

    /*
     * 目盛り。
     */
    const step =
        chooseScaleStep(d);

    for(
        let t = 0;
        t <= d + .0001;
        t += step
    ){

        const label =
            document.createElement(
                'span'
            );

        label.textContent =
            fmt(t);

        label.style.left =
            `${timeToX(t)}px`;

        scale.appendChild(
            label
        );
    }

    /*
     * 再生位置クリック領域。
     */
    scale.addEventListener(
        'click',
        seekFromTimeline
    );

    const rows = [
        {
            type:'comment',
            label:'テキスト'
        },
        {
            type:'box',
            label:'強調枠'
        },
        {
            type:'skip',
            label:'スキップ'
        },
        {
            type:'connection',
            label:'接続'
        }
    ];

    for(const rowInfo of rows){

        const row =
            document.createElement('div');

        row.className =
            'timeline-row';

        const label =
            document.createElement('div');

        label.className =
            'timeline-label';

        label.textContent =
            rowInfo.label;

        const track =
            document.createElement('div');

        track.className =
            'track';

        track.style.width =
            `${width}px`;

        row.appendChild(label);
        row.appendChild(track);

        const items =
            rowInfo.type ===
                'connection'
                ? state.project?.connections || []
                : (
                    state.project?.elements
                        .filter(
                            e =>
                                e.type ===
                                rowInfo.type
                        ) || []
                );

        for(const item of items){

            const start =
                Number(item.start)||0;

            const end =
                Number(item.end)||0;

            const bar =
                document.createElement('div');

            bar.className =
                'track-item ' +
                rowInfo.type;

            if(
                item.id ===
                state.selectedId
            ){
                bar.classList.add(
                    'selected'
                );
            }

            if(
                state.multiSelected.includes(
                    item.id
                )
            ){
                bar.classList.add(
                    'multi'
                );
            }

            /*
             * 必ず全体時間軸から計算。
             * 赤い再生位置などから幅を計算しない。
             */
            bar.style.left =
                `${timeToX(start)}px`;

            bar.style.width =
                `${Math.max(
                    16,
                    timeToX(end) -
                    timeToX(start)
                )}px`;

            const st =
                document.createElement('span');

            st.className =
                'track-time';

            st.textContent =
                fmt(start);

            const et =
                document.createElement('span');

            et.className =
                'track-end-time';

            et.textContent =
                fmt(end);

            const name =
                document.createElement('span');

            name.className =
                'track-name';

            if(rowInfo.type === 'comment'){
                name.textContent =
                    item.text ||
                    'テキスト';
            }else if(rowInfo.type === 'box'){
                name.textContent =
                    '強調枠';
            }else if(rowInfo.type === 'skip'){
                name.textContent =
                    'SKIP';
            }else{
                const from =
                    getElement(item.from);

                const to =
                    getElement(item.to);

                name.textContent =
                    `${from?.text || from?.type || '要素'} → ${to?.text || to?.type || '要素'}`;
            }

            bar.appendChild(st);
            bar.appendChild(et);
            bar.appendChild(name);

            if(
                rowInfo.type !==
                'connection'
            ){

                const left =
                    document.createElement(
                        'div'
                    );

                left.className =
                    'track-handle left';

                const right =
                    document.createElement(
                        'div'
                    );

                right.className =
                    'track-handle right';

                left.addEventListener(
                    'pointerdown',
                    event=>{
                        beginTimelineResize(
                            event,
                            item,
                            'start',
                            track
                        );
                    }
                );

                right.addEventListener(
                    'pointerdown',
                    event=>{
                        beginTimelineResize(
                            event,
                            item,
                            'end',
                            track
                        );
                    }
                );

                bar.appendChild(left);
                bar.appendChild(right);
            }

            /*
             * 要素バーをクリックすると
             * その要素の開始位置へ移動。
             */
            bar.addEventListener(
                'click',
                event=>{
                    if(
                        event.target.closest(
                            '.track-handle'
                        )
                    ){
                        return;
                    }

                    if(
                        rowInfo.type ===
                        'connection'
                    ){
                        selectConnection(
                            item.id
                        );
                    }else{
                        selectElement(
                            item.id,
                            event.shiftKey
                        );
                    }

                    seek(item.start);
                }
            );

            /*
             * ダブルクリックは編集。
             */
            bar.addEventListener(
                'dblclick',
                event=>{
                    event.preventDefault();

                    if(
                        rowInfo.type ===
                        'connection'
                    ){
                        openConnectionModal(
                            item
                        );
                    }else{
                        openElementModal(
                            item
                        );
                    }
                }
            );

            /*
             * タイムライン上でも右クリック可能。
             */
            bar.addEventListener(
                'contextmenu',
                event=>{
                    event.preventDefault();
                    event.stopPropagation();

                    if(
                        rowInfo.type ===
                        'connection'
                    ){
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

                        seek(item.start);

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
             * バー中央ドラッグで時間位置を移動。
             */
            bar.addEventListener(
                'pointerdown',
                event=>{
                    if(
                        event.button !== 0 ||
                        event.target.closest(
                            '.track-handle'
                        )
                    ){
                        return;
                    }

                    beginTimelineMove(
                        event,
                        item,
                        track
                    );
                }
            );

            track.appendChild(
                bar
            );
        }

        tracks.appendChild(
            row
        );
    }

    updatePlayhead();
}

function beginTimelineResize(
    event,
    item,
    side,
    track
){
    if(!requireEditor()){
        return;
    }

    const rect =
        track.getBoundingClientRect();

    state.timelineDrag = {
        item,
        side,

        rect,

        duration:
            duration(),

        originalStart:
            Number(item.start)||0,

        originalEnd:
            Number(item.end)||0,

        startX:
            event.clientX
    };

    event.preventDefault();
    event.stopPropagation();
}

function beginTimelineMove(
    event,
    item,
    track
){
    if(!requireEditor()){
        return;
    }

    const rect =
        track.getBoundingClientRect();

    state.timelineDrag = {
        item,
        side:'move',

        rect,

        duration:
            duration(),

        originalStart:
            Number(item.start)||0,

        originalEnd:
            Number(item.end)||0,

        startX:
            event.clientX
    };

    event.preventDefault();
    event.stopPropagation();
}

function moveTimeline(event){
    const drag =
        state.timelineDrag;

    if(!drag){
        return;
    }

    const delta =
        (
            event.clientX -
            drag.startX
        ) /
        Math.max(
            1,
            drag.rect.width
        ) *
        drag.duration;

    const item =
        drag.item;

    if(
        drag.side ===
        'move'
    ){

        const length =
            drag.originalEnd -
            drag.originalStart;

        let start =
            drag.originalStart +
            delta;

        start =
            clamp(
                start,
                0,
                Math.max(
                    0,
                    drag.duration -
                    length
                )
            );

        item.start =
            start;

        item.end =
            start + length;

    }else if(
        drag.side ===
        'start'
    ){

        item.start =
            clamp(
                drag.originalStart +
                delta,

                0,

                Math.max(
                    0,
                    drag.originalEnd -
                    .05
                )
            );

    }else{

        item.end =
            clamp(
                drag.originalEnd +
                delta,

                Math.min(
                    drag.duration,
                    drag.originalStart +
                    .05
                ),

                drag.duration
            );
    }

    /*
     * 左端0秒でも削除しない。
     */
    item.start =
        Math.max(
            0,
            item.start
        );

    item.end =
        Math.min(
            drag.duration,
            item.end
        );

    markDirty();

    renderTimeline();
    renderObjects();
    renderConnectors();
}

function endTimelineDrag(){
    state.timelineDrag = null;
}

function updatePlayhead(){
    const d =
        duration();

    const playhead =
        $('playhead');

    if(
        !playhead ||
        !d
    ){
        if(playhead){
            playhead.style.left =
                '0px';
        }

        return;
    }

    /*
     * 赤線も必ず同じtimelineWidthを使用。
     */
    playhead.style.left =
        `${timeToX(currentTime())}px`;

    $('currentTime').textContent =
        `${fmt(currentTime())} / ${fmt(d)}`;
}

function seekFromTimeline(event){
    if(!requireEditor()){
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

    seek(
        xToTime(x)
    );
}

function seek(time){
    if(!requireEditor()){
        return;
    }

    const video =
        $('recordedVideo');

    if(!video){
        return;
    }

    video.currentTime =
        clamp(
            Number(time)||0,
            0,
            duration()
        );

    renderObjects();
    renderConnectors();
    updatePlayhead();
}

/* =========================================================
 * Skip
 * ======================================================= */

function processSkip(){
    if(
        !state.editorReady ||
        !state.project
    ){
        return;
    }

    const video =
        $('recordedVideo');

    if(
        !video ||
        video.paused
    ){
        return;
    }

    const t =
        video.currentTime;

    /*
     * 現在時刻を含むスキップ区間を探す。
     */
    const skip =
        state.project.elements.find(
            e =>
                e.type === 'skip' &&
                t >= e.start &&
                t < e.end - .01
        );

    if(skip){

        /*
         * 「再生インデックスがぱっと移動」。
         */
        video.currentTime =
            Math.min(
                skip.end,
                duration()
            );
    }
}

/* =========================================================
 * Element modal
 * ======================================================= */

function renderPalette(){
    const palette =
        $('palette');

    if(!palette){
        return;
    }

    palette.innerHTML = '';

    COLORS.forEach(color=>{

        const button =
            document.createElement('button');

        button.type =
            'button';

        button.style.background =
            color;

        button.dataset.color =
            color;

        button.addEventListener(
            'click',
            ()=>{
                $('elementColor').value =
                    color;

                palette
                    .querySelectorAll(
                        'button'
                    )
                    .forEach(
                        b =>
                            b.classList.toggle(
                                'active',
                                b === button
                            )
                    );
            }
        );

        palette.appendChild(
            button
        );
    });
}

function openElementModal(e){
    if(!e){
        return;
    }

    state.modalTarget =
        e;

    $('modalTitle').textContent =
        e.type === 'comment'
            ? 'テキストを編集'
            : e.type === 'box'
                ? '強調枠を編集'
                : 'スキップ区間を編集';

    $('modalType').textContent =
        e.type === 'comment'
            ? 'テキスト'
            : e.type === 'box'
                ? '強調枠'
                : 'スキップ';

    $('textRow').style.display =
        e.type === 'comment'
            ? 'grid'
            : 'none';

    $('fontSizeRow').style.display =
        e.type === 'comment'
            ? 'grid'
            : 'none';

    $('fontWeightRow').style.display =
        e.type === 'comment'
            ? 'grid'
            : 'none';

    $('elementText').value =
        e.text || '';

    $('elementStart').value =
        Number(e.start).toFixed(3);

    $('elementEnd').value =
        Number(e.end).toFixed(3);

    $('elementColor').value =
        e.color || '#3b82f6';

    $('elementBorderWidth').value =
        Number(e.borderWidth)||0;

    $('elementRadius').value =
        Number(e.radius)||0;

    $('elementFontSize').value =
        Number(e.fontSize)||24;

    $('elementFontWeight').value =
        String(
            Number(e.fontWeight)||600
        );

    $('elementModal').style.display =
        'flex';

    updatePaletteActive(
        e.color
    );
}

function updatePaletteActive(color){
    $('palette')
        ?.querySelectorAll(
            'button'
        )
        .forEach(
            b =>
                b.classList.toggle(
                    'active',
                    b.dataset.color === color
                )
        );
}

function closeElementModal(){
    $('elementModal').style.display =
        'none';

    state.modalTarget = null;
}

function saveElementModal(){
    const e =
        state.modalTarget;

    if(!e){
        closeElementModal();
        return;
    }

    const d =
        duration();

    let start =
        Number(
            $('elementStart').value
        );

    let end =
        Number(
            $('elementEnd').value
        );

    if(!Number.isFinite(start)){
        message(
            '開始時間が不正です。'
        );
        return;
    }

    if(!Number.isFinite(end)){
        message(
            '終了時間が不正です。'
        );
        return;
    }

    start =
        clamp(
            start,
            0,
            d
        );

    end =
        clamp(
            end,
            start+.05,
            d
        );

    if(end <= start){
        message(
            '終了時間は開始時間より後にしてください。'
        );
        return;
    }

    e.start = start;
    e.end = end;

    e.text =
        $('elementText').value;

    e.color =
        $('elementColor').value;

    e.borderWidth =
        clamp(
            Number(
                $('elementBorderWidth').value
            ) || 0,
            0,
            20
        );

    e.radius =
        clamp(
            Number(
                $('elementRadius').value
            ) || 0,
            0,
            100
        );

    e.fontSize =
        clamp(
            Number(
                $('elementFontSize').value
            ) || 24,
            8,
            200
        );

    e.fontWeight =
        Number(
            $('elementFontWeight').value
        ) || 600;

    normalizeElement(e);

    /*
     * 接続範囲は自動追従させる。
     */
    state.project.connections
        .forEach(
            normalizeConnection
        );

    markDirty();

    closeElementModal();
    renderAll();
}

/* =========================================================
 * Connection modal
 * ======================================================= */

function openConnectionModal(c){
    if(!c){
        return;
    }

    state.connectionModalTarget =
        c;

    $('connectionStart').value =
        Number(c.start).toFixed(3);

    $('connectionEnd').value =
        Number(c.end).toFixed(3);

    $('connectionFromPoint').value =
        c.fromPoint || 'e';

    $('connectionToPoint').value =
        c.toPoint || 'w';

    $('connectionColor').value =
        c.color || '#ffffff';

    $('connectionWidth').value =
        Number(c.width)||1.5;

    $('connectionDash').value =
        c.dash || '';

    $('connectionArrow').value =
        Number(c.arrow) ? '1' : '0';

    $('connectionModal').style.display =
        'flex';
}

function closeConnectionModal(){
    $('connectionModal').style.display =
        'none';

    state.connectionModalTarget =
        null;
}

function saveConnectionModal(){
    const c =
        state.connectionModalTarget;

    if(!c){
        closeConnectionModal();
        return;
    }

    const d =
        duration();

    let start =
        Number(
            $('connectionStart').value
        );

    let end =
        Number(
            $('connectionEnd').value
        );

    if(
        !Number.isFinite(start) ||
        !Number.isFinite(end)
    ){
        message(
            '接続時間が不正です。'
        );
        return;
    }

    start =
        clamp(
            start,
            0,
            d
        );

    end =
        clamp(
            end,
            start+.05,
            d
        );

    c.start = start;
    c.end = end;

    c.fromPoint =
        $('connectionFromPoint').value;

    c.toPoint =
        $('connectionToPoint').value;

    c.color =
        $('connectionColor').value;

    c.width =
        clamp(
            Number(
                $('connectionWidth').value
            ) || 1.5,
            .5,
            10
        );

    c.dash =
        $('connectionDash').value;

    c.arrow =
        Number(
            $('connectionArrow').value
        ) ? 1 : 0;

    markDirty();

    closeConnectionModal();
    renderAll();
}

function deleteConnectionModal(){
    const c =
        state.connectionModalTarget;

    if(!c){
        closeConnectionModal();
        return;
    }

    state.project.connections =
        state.project.connections.filter(
            x => x.id !== c.id
        );

    resetSelection();

    markDirty();

    closeConnectionModal();
    renderAll();
}

/* =========================================================
 * Context menu
 * ======================================================= */

function showContextMenu(x,y){
    const menu =
        $('contextMenu');

    const connect =
        $('contextConnect');

    if(!menu){
        return;
    }

    if(connect){
        connect.style.display =
            state.multiSelected.length === 2
                ? 'block'
                : 'none';
    }

    menu.style.display =
        'block';

    const width =
        menu.offsetWidth;

    const height =
        menu.offsetHeight;

    menu.style.left =
        `${Math.min(
            x,
            window.innerWidth -
            width -
            8
        )}px`;

    menu.style.top =
        `${Math.min(
            y,
            window.innerHeight -
            height -
            8
        )}px`;
}

function hideContextMenu(){
    const menu =
        $('contextMenu');

    if(menu){
        menu.style.display =
            'none';
    }
}

function handleContextAction(action){

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

    if(action === 'connect'){
        connectSelected();
        return;
    }

    if(
        context?.type ===
        'element'
    ){

        const e =
            getElement(context.id);

        if(!e){
            return;
        }

        if(action === 'edit'){
            openElementModal(e);
            return;
        }

        if(action === 'duplicate'){
            state.selectedId =
                e.id;

            state.selectedType =
                'element';

            duplicateSelected();
            return;
        }

        if(action === 'delete'){
            state.selectedId =
                e.id;

            state.selectedType =
                'element';

            deleteSelected();
            return;
        }
    }

    if(
        context?.type ===
        'connection'
    ){

        const c =
            getConnection(context.id);

        if(!c){
            return;
        }

        if(action === 'edit'){
            openConnectionModal(c);
            return;
        }

        if(action === 'delete'){
            state.selectedId =
                c.id;

            state.selectedType =
                'connection';

            deleteSelected();
            return;
        }
    }
}

/* =========================================================
 * Save / local DB
 * ======================================================= */

function openDB(){
    return new Promise(
        (resolve,reject)=>{

            if(!window.indexedDB){
                resolve(null);
                return;
            }

            const request =
                indexedDB.open(
                    'VideoEditorDB',
                    4
                );

            request.onupgradeneeded =
                event=>{

                    const db =
                        event.target.result;

                    if(
                        db.objectStoreNames
                            .contains('projects')
                    ){
                        db.deleteObjectStore(
                            'projects'
                        );
                    }

                    db.createObjectStore(
                        'projects',
                        {
                            keyPath:
                                'projectId'
                        }
                    );
                };

            request.onsuccess =
                event=>{
                    state.db =
                        event.target.result;

                    resolve(
                        state.db
                    );
                };

            request.onerror =
                ()=>{
                    reject(
                        request.error
                    );
                };
        }
    );
}

function idbPut(project){
    return new Promise(
        (resolve,reject)=>{

            if(!state.db){
                reject(
                    new Error(
                        'IndexedDB unavailable'
                    )
                );
                return;
            }

            if(
                !project.projectId
            ){
                project.projectId =
                    uid('project');
            }

            const data =
                JSON.parse(
                    JSON.stringify(project)
                );

            const tx =
                state.db.transaction(
                    'projects',
                    'readwrite'
                );

            const store =
                tx.objectStore(
                    'projects'
                );

            try{
                store.put(data);
            }catch(error){
                reject(error);
                return;
            }

            tx.oncomplete =
                resolve;

            tx.onerror =
                ()=>{
                    reject(
                        tx.error
                    );
                };
        }
    );
}

function idbGet(id){
    return new Promise(
        (resolve,reject)=>{

            if(!state.db){
                resolve(null);
                return;
            }

            const tx =
                state.db.transaction(
                    'projects',
                    'readonly'
                );

            const request =
                tx.objectStore(
                    'projects'
                ).get(id);

            request.onsuccess =
                ()=>{
                    resolve(
                        request.result ||
                        null
                    );
                };

            request.onerror =
                ()=>{
                    reject(
                        request.error
                    );
                };
        }
    );
}

function idbList(){
    return new Promise(
        (resolve,reject)=>{

            if(!state.db){
                resolve([]);
                return;
            }

            const tx =
                state.db.transaction(
                    'projects',
                    'readonly'
                );

            const request =
                tx.objectStore(
                    'projects'
                ).getAll();

            request.onsuccess =
                ()=>{
                    resolve(
                        request.result ||
                        []
                    );
                };

            request.onerror =
                ()=>{
                    reject(
                        request.error
                    );
                };
        }
    );
}

function idbDelete(id){
    return new Promise(
        (resolve,reject)=>{

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
                resolve;

            tx.onerror =
                ()=>{
                    reject(
                        tx.error
                    );
                };
        }
    );
}

async function saveProject(){
    if(
        !state.project
    ){
        message(
            'プロジェクトがありません。'
        );
        return;
    }

    if(!requireEditor()){
        return;
    }

    state.project.name =
        $('editorProjectName')
            .value
            .trim() ||
        '名称未設定';

    state.project.videoDuration =
        duration();

    state.project.version =
        APP_VERSION;

    /*
     * サーバー保存を優先。
     * 上限到達ならIndexedDBへ保存。
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
                    body:JSON.stringify(
                        state.project
                    )
                }
            );

        const result =
            await response.json();

        if(result.ok){

            state.project.projectId =
                result.projectId;

            state.project.savedAt =
                result.savedAt;

            state.dirty = false;

            $('editorStatus').textContent =
                '保存済み';

            await idbPut(
                state.project
            );

            await renderProjectList();

            message(
                'サーバーに保存しました。',
                true
            );

            return;
        }

        if(
            result.limit
        ){
            await idbPut(
                state.project
            );

            state.dirty = false;

            $('editorStatus').textContent =
                'ローカル保存済み';

            await renderProjectList();

            message(
                'サーバー保存上限のため、ローカルに保存しました。',
                true
            );

            return;
        }

        throw new Error(
            result.message ||
            '保存に失敗しました。'
        );

    }catch(error){

        try{
            await idbPut(
                state.project
            );

            state.dirty = false;

            $('editorStatus').textContent =
                'ローカル保存済み';

            await renderProjectList();

            message(
                'サーバー保存できなかったため、ローカルに保存しました。',
                true
            );

        }catch(localError){

            message(
                '保存に失敗しました。\n' +
                (
                    localError.message ||
                    error.message ||
                    ''
                )
            );
        }
    }
}

/* =========================================================
 * Project import/export
 * ======================================================= */

function exportProject(){
    if(!state.project){
        message(
            'プロジェクトがありません。'
        );
        return;
    }

    const data =
        JSON.stringify(
            state.project,
            null,
            2
        );

    const blob =
        new Blob(
            [data],
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

        validateImportedProject(
            project
        );

        state.project =
            project;

        state.project.version =
            APP_VERSION;

        resetSelection();

        state.dirty = false;
        state.editorReady = false;

        $('editorProjectName').value =
            state.project.name ||
            '名称未設定';

        showEditor();
        setEditorLocked(true);

        $('editorStatus').textContent =
            '動画未読込';

        renderAll();

        message(
            '保存データを読み込みました。動画を選択してください。',
            true
        );

    }catch(error){

        message(
            '読み込みに失敗しました。\n' +
            (
                error.message ||
                ''
            )
        );
    }
}

function validateImportedProject(p){
    if(
        !p ||
        typeof p !== 'object'
    ){
        throw new Error(
            'プロジェクト形式が不正です。'
        );
    }

    if(!p.projectId){
        p.projectId =
            uid('project');
    }

    if(!Array.isArray(p.elements)){
        p.elements = [];
    }

    if(!Array.isArray(p.connections)){
        p.connections = [];
    }

    p.elements =
        p.elements
            .filter(
                e =>
                    e &&
                    e.id &&
                    ['comment','box','skip']
                        .includes(e.type)
            )
            .map(
                e =>
                    normalizeElement({
                        ...makeElement(
                            e.type,
                            Number(e.start)||0
                        ),
                        ...e
                    })
            );

    const elementIds =
        new Set(
            p.elements.map(
                e => e.id
            )
        );

    p.connections =
        p.connections
            .filter(
                c =>
                    c &&
                    c.id &&
                    elementIds.has(c.from) &&
                    elementIds.has(c.to)
            )
            .map(
                normalizeConnection
            );
}

/* =========================================================
 * Project list
 * ======================================================= */

async function renderProjectList(){
    const list =
        $('projectList');

    if(!list){
        return;
    }

    list.innerHTML =
        '<div style="color:#888">読み込み中…</div>';

    let projects = [];

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
            projects =
                result.projects || [];
        }

    }catch(error){
        console.error(error);
    }

    try{

        const local =
            await idbList();

        local.forEach(
            p=>{
                if(
                    !projects.some(
                        x =>
                            x.projectId ===
                            p.projectId
                    )
                ){
                    projects.push({
                        ...p,
                        storage:'local'
                    });
                }
            }
        );

    }catch(error){
        console.error(error);
    }

    projects.sort(
        (a,b)=>
            String(
                b.savedAt || ''
            ).localeCompare(
                String(
                    a.savedAt || ''
                )
            )
    );

    if(!projects.length){

        list.innerHTML =
            '<div style="color:#888">保存されたプロジェクトはありません。</div>';

        return;
    }

    list.innerHTML = '';

    projects.forEach(
        p=>{

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
                [
                    p.videoName ||
                    '動画未設定',

                    p.savedAt ||
                    '',

                    p.storage === 'local'
                        ? 'ローカル'
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
                async ()=>{
                    try{

                        let project =
                            null;

                        if(
                            p.storage ===
                            'server'
                        ){

                            const response =
                                await fetch(
                                    '?api=load&id=' +
                                    encodeURIComponent(
                                        p.projectId
                                    ),
                                    {
                                        cache:
                                            'no-store'
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

                            project =
                                result.project;

                        }else{

                            project =
                                await idbGet(
                                    p.projectId
                                );
                        }

                        validateImportedProject(
                            project
                        );

                        state.project =
                            project;

                        resetSelection();

                        state.dirty = false;
                        state.editorReady = false;

                        $('editorProjectName').value =
                            state.project.name ||
                            '名称未設定';

                        showEditor();
                        setEditorLocked(true);

                        $('editorStatus').textContent =
                            '動画未読込';

                        renderAll();

                        message(
                            'プロジェクトを開きました。動画を選択してください。',
                            true
                        );

                    }catch(error){

                        message(
                            '読み込みに失敗しました。\n' +
                            (
                                error.message ||
                                ''
                            )
                        );
                    }
                }
            );

            actions.appendChild(
                load
            );

            const del =
                document.createElement('button');

            del.textContent =
                '削除';

            del.className =
                'danger';

            del.addEventListener(
                'click',
                async ()=>{

                    if(
                        !confirm(
                            `「${p.name}」を削除しますか？`
                        )
                    ){
                        return;
                    }

                    try{

                        if(
                            p.storage ===
                            'server'
                        ){

                            const response =
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

                            const result =
                                await response.json();

                            if(!result.ok){
                                throw new Error(
                                    result.message ||
                                    '削除できませんでした。'
                                );
                            }
                        }

                        await idbDelete(
                            p.projectId
                        );

                        await renderProjectList();

                    }catch(error){

                        message(
                            error.message ||
                            '削除できませんでした。'
                        );
                    }
                }
            );

            actions.appendChild(
                del
            );

            row.appendChild(info);
            row.appendChild(actions);

            list.appendChild(row);
        }
    );
}

/* =========================================================
 * Render all
 * ======================================================= */

function renderAll(){
    renderObjects();
    renderConnectors();
    renderTimeline();
    updatePlayhead();
    updateConnectionStatus();
}

function updateConnectionStatus(){
    const el =
        $('connectionStatus');

    if(!el){
        return;
    }

    if(
        state.multiSelected.length === 2
    ){

        el.textContent =
            '2要素を選択中。右クリック →「選択した2要素を接続」';

        return;
    }

    if(
        state.multiSelected.length === 1
    ){

        el.textContent =
            '1要素を選択中。Shift＋クリックでもう1つ選択してください。';

        return;
    }

    if(
        state.selectedType ===
        'connection'
    ){

        el.textContent =
            '接続線を選択中。右クリックまたはダブルクリックで編集できます。';

        return;
    }

    el.textContent =
        'Shift＋クリックで2要素を選択 → 右クリック「選択した2要素を接続」';
}

/* =========================================================
 * Playback
 * ======================================================= */

function updatePlaybackButton(){
    const video =
        $('recordedVideo');

    const button =
        $('playToggle');

    if(
        !video ||
        !button
    ){
        return;
    }

    button.textContent =
        video.paused
            ? '▶'
            : 'Ⅱ';
}

function togglePlayback(){
    if(!requireEditor()){
        return;
    }

    const video =
        $('recordedVideo');

    if(!video){
        return;
    }

    if(video.paused){

        video.play()
            .catch(
                error=>{
                    message(
                        error.message ||
                        '再生できません。'
                    );
                }
            );

    }else{
        video.pause();
    }

    updatePlaybackButton();
}

/* =========================================================
 * Events
 * ======================================================= */

const newProjectButton =
    $('newProject');

if(newProjectButton){
    newProjectButton.addEventListener(
        'click',
        newProject
    );
}

const openVideo =
    $('openVideo');

if(openVideo){
    openVideo.addEventListener(
        'click',
        ()=>{
            $('videoFile').click();
        }
    );
}

const videoFile =
    $('videoFile');

if(videoFile){
    videoFile.addEventListener(
        'change',
        event=>{
            handleVideo(
                event.target.files?.[0]
            );

            event.target.value = '';
        }
    );
}

const editorVideoFile =
    $('editorVideoFile');

if(editorVideoFile){
    editorVideoFile.addEventListener(
        'change',
        event=>{
            handleVideo(
                event.target.files?.[0]
            );

            event.target.value = '';
        }
    );
}

const loadVideoButton =
    $('loadVideoButton');

if(loadVideoButton){
    loadVideoButton.addEventListener(
        'click',
        ()=>{
            if(
                !state.project
            ){
                message(
                    '先にプロジェクトを作成してください。'
                );
                return;
            }

            $('editorVideoFile').click();
        }
    );
}

const importProject =
    $('importProject');

if(importProject){
    importProject.addEventListener(
        'click',
        ()=>{
            $('projectFile').click();
        }
    );
}

const projectFile =
    $('projectFile');

if(projectFile){
    projectFile.addEventListener(
        'change',
        event=>{
            importProjectFile(
                event.target.files?.[0]
            );

            event.target.value = '';
        }
    );
}

const backHome =
    $('backHome');

if(backHome){
    backHome.addEventListener(
        'click',
        ()=>{
            if(
                state.dirty &&
                !confirm(
                    '保存していない変更があります。戻りますか？'
                )
            ){
                return;
            }

            showHome();
        }
    );
}

const saveButton =
    $('saveProject');

if(saveButton){
    saveButton.addEventListener(
        'click',
        saveProject
    );
}

const exportButton =
    $('exportProject');

if(exportButton){
    exportButton.addEventListener(
        'click',
        exportProject
    );
}

const projectName =
    $('editorProjectName');

if(projectName){
    projectName.addEventListener(
        'input',
        ()=>{
            if(state.project){

                state.project.name =
                    projectName.value;

                markDirty();
            }
        }
    );
}

const playToggle =
    $('playToggle');

if(playToggle){
    playToggle.addEventListener(
        'click',
        togglePlayback
    );
}

const video =
    $('recordedVideo');

if(video){

    video.addEventListener(
        'loadedmetadata',
        ()=>{
            if(
                Number.isFinite(
                    video.duration
                ) &&
                video.duration > 0
            ){
                state.project.videoDuration =
                    video.duration;

                renderAll();
            }
        }
    );

    video.addEventListener(
        'timeupdate',
        ()=>{
            processSkip();
            renderObjects();
            renderConnectors();
            updatePlayhead();
            updatePlaybackButton();
        }
    );

    video.addEventListener(
        'play',
        updatePlaybackButton
    );

    video.addEventListener(
        'pause',
        updatePlaybackButton
    );

    video.addEventListener(
        'ended',
        updatePlaybackButton
    );
}

/*
 * 動画領域右クリック。
 */
const videoArea =
    $('videoArea');

if(videoArea){

    videoArea.addEventListener(
        'contextmenu',
        event=>{

            /*
             * 要素上ならrenderObjects側で処理済み。
             */
            if(
                event.target.closest(
                    '.edit-object'
                )
            ){
                return;
            }

            if(
                event.target.closest(
                    '#videoStage'
                )
            ){

                if(!requireEditor()){
                    return;
                }

                event.preventDefault();

                state.context = {
                    type:'stage'
                };

                showContextMenu(
                    event.clientX,
                    event.clientY
                );
            }
        }
    );
}

/*
 * 全画面のコンテキストメニュー。
 */
const contextMenu =
    $('contextMenu');

if(contextMenu){

    contextMenu.addEventListener(
        'click',
        event=>{

            const button =
                event.target.closest(
                    'button[data-action]'
                );

            if(!button){
                return;
            }

            handleContextAction(
                button.dataset.action
            );
        }
    );
}

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

/*
 * ドラッグはwindowで受ける。
 * setPointerCaptureに依存しないため、
 * 左端・iframe等でのInvalidStateErrorを避ける。
 */
window.addEventListener(
    'pointermove',
    event=>{
        if(state.drag){
            moveElementPointer(event);
        }

        if(state.timelineDrag){
            moveTimeline(event);
        }
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
        renderConnectors();
        updatePlayhead();
    }
);

const zoom =
    $('timelineZoom');

if(zoom){

    zoom.addEventListener(
        'change',
        ()=>{
            state.zoom =
                Number(zoom.value) || 1;

            renderTimeline();
            updatePlayhead();
        }
    );
}

const zoomIn =
    $('timelineZoomIn');

if(zoomIn){

    zoomIn.addEventListener(
        'click',
        ()=>{
            const values =
                [.5,1,2,3,5];

            const index =
                values.indexOf(
                    state.zoom
                );

            if(index < values.length-1){

                state.zoom =
                    values[index+1];

                zoom.value =
                    String(state.zoom);

                renderTimeline();
                updatePlayhead();
            }
        }
    );
}

const zoomOut =
    $('timelineZoomOut');

if(zoomOut){

    zoomOut.addEventListener(
        'click',
        ()=>{
            const values =
                [.5,1,2,3,5];

            const index =
                values.indexOf(
                    state.zoom
                );

            if(index > 0){

                state.zoom =
                    values[index-1];

                zoom.value =
                    String(state.zoom);

                renderTimeline();
                updatePlayhead();
            }
        }
    );
}

/*
 * モーダル。
 */
const modalCancel =
    $('modalCancel');

if(modalCancel){
    modalCancel.addEventListener(
        'click',
        closeElementModal
    );
}

const modalSave =
    $('modalSave');

if(modalSave){
    modalSave.addEventListener(
        'click',
        saveElementModal
    );
}

const elementModal =
    $('elementModal');

if(elementModal){
    elementModal.addEventListener(
        'click',
        event=>{
            if(
                event.target ===
                elementModal
            ){
                closeElementModal();
            }
        }
    );
}

const connectionCancel =
    $('connectionCancel');

if(connectionCancel){
    connectionCancel.addEventListener(
        'click',
        closeConnectionModal
    );
}

const connectionSave =
    $('connectionSave');

if(connectionSave){
    connectionSave.addEventListener(
        'click',
        saveConnectionModal
    );
}

const connectionDelete =
    $('connectionDelete');

if(connectionDelete){
    connectionDelete.addEventListener(
        'click',
        deleteConnectionModal
    );
}

const connectionModal =
    $('connectionModal');

if(connectionModal){
    connectionModal.addEventListener(
        'click',
        event=>{
            if(
                event.target ===
                connectionModal
            ){
                closeConnectionModal();
            }
        }
    );
}

/*
 * Delete / Escape / Space
 */
document.addEventListener(
    'keydown',
    event=>{

        const modalOpen =
            (
                $('elementModal') &&
                $('elementModal')
                    .style.display === 'flex'
            ) ||
            (
                $('connectionModal') &&
                $('connectionModal')
                    .style.display === 'flex'
            );

        if(
            event.key === 'Delete' &&
            !modalOpen
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

        if(
            event.key === ' ' &&
            document.activeElement &&
            ![
                'INPUT',
                'TEXTAREA',
                'SELECT'
            ].includes(
                document.activeElement.tagName
            ) &&
            !modalOpen
        ){
            event.preventDefault();
            togglePlayback();
        }
    }
);

/* =========================================================
 * Initialization
 * ======================================================= */

(async function init(){

    try{
        await openDB();
    }catch(error){
        console.error(
            'IndexedDB:',
            error
        );
    }

    renderPalette();

    $('status').textContent =
        `準備完了 / v${APP_VERSION}`;

    await renderProjectList();

})();
</script>

</body>
</html>

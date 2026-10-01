<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * ・動画上で直接要素追加
 * ・移動 / リサイズ
 * ・右クリック編集
 * ・タイムラインで開始 / 終了変更
 * ・Shift + クリックで複数選択
 * ・右クリックで要素間接続
 * ・接続線の編集 / 削除
 * ・スキップ区間
 * ・サーバーJSON保存
 * ・IndexedDBローカル保存
 * ・プロジェクトJSON入出力
 *
 * ※ MP4再エンコードはブラウザ単体では行わない。
 */

const APP_VERSION = 20;
const MAX_SERVER_PROJECTS = 20;

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function projectSummary(array $project): array
{
    return [
        'projectId' => (string)($project['projectId'] ?? ''),
        'name' => (string)($project['name'] ?? '名称未設定'),
        'videoName' => (string)($project['videoName'] ?? ''),
        'savedAt' => (string)($project['savedAt'] ?? ''),
        'version' => (int)($project['version'] ?? 1),
        'storage' => 'server'
    ];
}

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
            'projects' => array_map('projectSummary', readServerProjects()),
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

        $data = json_decode(@file_get_contents($file) ?: '', true);

        if (!is_array($data)) {
            jsonResponse(['ok' => false, 'message' => '保存データが壊れています。'], 500);
        }

        jsonResponse(['ok' => true, 'project' => $data]);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        if (!ensureProjectDir()) {
            jsonResponse([
                'ok' => false,
                'message' => 'data/projects に書き込めません。'
            ], 500);
        }

        $payload = json_decode(file_get_contents('php://input') ?: '', true);

        if (!is_array($payload)) {
            jsonResponse(['ok' => false, 'message' => 'JSONが不正です。'], 400);
        }

        $id = trim((string)($payload['projectId'] ?? ''));

        if ($id === '') {
            $id = 'project-' . bin2hex(random_bytes(10));
        }

        if (!validProjectId($id)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
        }

        $name = trim((string)($payload['name'] ?? ''));

        if ($name === '') {
            jsonResponse(['ok' => false, 'message' => 'プロジェクト名を入力してください。'], 422);
        }

        if (mb_strlen($name) > 120) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクト名は120文字以内にしてください。'], 422);
        }

        $existing = is_file(projectPath($id));
        $projects = readServerProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' => 'サーバー保存上限に達しました。ローカル保存を使用してください。'
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
            jsonResponse(['ok' => false, 'message' => 'JSON生成に失敗しました。'], 500);
        }

        if (@file_put_contents(projectPath($id), $json, LOCK_EX) === false) {
            jsonResponse(['ok' => false, 'message' => '編集データを保存できませんでした。'], 500);
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

*{box-sizing:border-box}

html,body{
    width:100%;
    height:100%;
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;
}

body{overflow:hidden}

button,input,select,textarea{font:inherit}

button{
    border:1px solid #565d66;
    background:#30353c;
    color:#fff;
    border-radius:6px;
    padding:7px 10px;
    cursor:pointer;
}

button:hover:not(:disabled){background:#424850}
button:disabled{opacity:.4;cursor:not-allowed}
button.primary{background:var(--blue)}
button.success{background:var(--green)}
button.danger{background:#a73535}

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

.hidden{display:none!important}

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

#message.ok{background:#287348}

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

.home-card h2{margin:0 0 8px}
.home-card p{color:var(--muted);font-size:13px;line-height:1.6}

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

.project-info{min-width:0}
.project-name{font-weight:600}
.project-meta{color:#929aa4;font-size:11px;margin-top:3px}

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

/*
 * 動画を大きくしすぎない。
 * タイムラインを常時見せる。
 */
.video-area{
    flex:1 1 auto;
    min-height:240px;
    max-height:58vh;
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
    width:min(78vw,1100px);
    height:min(46vh,620px);
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

.edit-object.selected .resize-handle{display:block}

.connector{
    fill:none;
    pointer-events:visibleStroke;
    cursor:pointer;
}

.connector.selected{
    filter:drop-shadow(0 0 4px #ffd447);
}

.timeline{
    flex:0 0 310px;
    min-height:310px;
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
    min-width:140px;
}

#timelineZoom{
    width:105px;
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
    height:28px;
    color:#8f969f;
    font-size:10px;
    border-bottom:1px solid #444;
}

.timeline-scale span{
    position:absolute;
    transform:translateX(-50%);
    white-space:nowrap;
}

.timeline-axis{
    position:absolute;
    left:70px;
    right:0;
    top:0;
    height:28px;
    border-bottom:1px solid #555;
}

.timeline-axis:after{
    content:"";
    position:absolute;
    left:0;
    right:0;
    bottom:0;
    height:1px;
    background:#1976d2;
}

/*
 * 全トラックで同一X軸。
 * 各trackは必ずtimelineWidth()と同じ幅。
 */
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
    min-width:14px;
    border-radius:3px;
    cursor:grab;
    z-index:5;
    touch-action:none;
}

.track-item:active{cursor:grabbing}

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
    top:-3px;
    bottom:-3px;
    width:13px;
    z-index:10;
    cursor:ew-resize;
}

.track-handle.left{left:-6px}
.track-handle.right{right:-6px}

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

.track-time{left:0}
.track-end-time{right:0}

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
    top:28px;
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
    height:28px;
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
    width:280px;
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

#contextConnect[disabled]{display:none}

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

.modal h3{margin:0 0 14px}

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
            動画を読み込んでから編集を開始します。
            動画画面上で右クリックすると要素を追加できます。
            追加した要素はタイムラインでも開始・終了を変更できます。
        </p>

        <div class="home-actions">
            <button class="primary" id="newProject">新しい編集を開始</button>
            <button id="openVideo">動画を読み込む</button>
            <button id="importProject">プロジェクトを読み込む</button>

            <input id="videoFile" type="file" accept="video/*" class="hidden">
            <input id="projectFile" type="file" accept=".json,application/json" class="hidden">
        </div>

        <h3>保存済み</h3>
        <div id="projectList"></div>
    </div>
</section>

<section id="editor" class="locked">

    <div class="editor-top">
        <button id="backHome">← 戻る</button>

        <input id="editorProjectName" value="名称未設定">

        <button class="success" id="saveProject">保存</button>
        <button id="exportProject">書き出し</button>

        <button id="loadVideoButton">動画変更</button>
        <input id="editorVideoFile" type="file" accept="video/*" class="hidden">

        <span id="editorStatus">動画未読込</span>
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

                <button id="playToggle">▶</button>

                <span id="currentTime">
                    00:00.000 / 00:00.000
                </span>

                <button id="timelineZoomOut">−</button>

                <select id="timelineZoom">
                    <option value="0.5">0.5×</option>
                    <option value="1" selected>1×</option>
                    <option value="2">2×</option>
                    <option value="3">3×</option>
                    <option value="5">5×</option>
                </select>

                <button id="timelineZoomIn">＋</button>

                <button id="timelineFit">全体</button>

            </div>

            <div class="timeline-scroll" id="timelineScroll">

                <div id="timelineContent" class="timeline-content">

                    <div id="timelineScale" class="timeline-scale">
                        <div id="timelineSeek" class="timeline-seek"></div>
                    </div>

                    <div id="timelineTracks"></div>

                    <div id="playhead" class="playhead"></div>

                </div>

            </div>

        </div>

    </div>

    <div id="editorFooter">
        <span id="connectionStatus" class="connection-status">
            Shift＋クリックで2要素を選択 → 右クリック「選択した2要素を接続」
        </span>
    </div>

</section>

<div id="contextMenu">

    <button data-action="add-comment">＋ テキスト</button>
    <button data-action="add-box">＋ 強調枠</button>
    <button data-action="add-skip">＋ スキップ区間</button>

    <div class="context-separator"></div>

    <button data-action="edit">編集</button>
    <button data-action="duplicate">複製</button>
    <button data-action="delete">削除</button>

    <div class="context-separator"></div>

    <button id="contextConnect" data-action="connect">
        🔗 選択した2要素を接続
    </button>

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
            <input id="elementStart" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>終了</label>
            <input id="elementEnd" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>色</label>
            <div>
                <div id="palette" class="palette"></div>
                <input id="elementColor" type="color">
            </div>
        </div>

        <div class="form-row">
            <label>線幅</label>
            <input id="elementBorderWidth" type="number" min="0" max="20" step="1">
        </div>

        <div class="form-row" id="fontSizeRow">
            <label>文字サイズ</label>
            <input id="elementFontSize" type="number" min="8" max="200">
        </div>

        <div class="form-row" id="fontWeightRow">
            <label>文字太さ</label>
            <select id="elementFontWeight">
                <option value="400">400</option>
                <option value="500">500</option>
                <option value="600">600</option>
                <option value="700">700</option>
                <option value="800">800</option>
            </select>
        </div>

        <div class="form-row">
            <label>位置 X</label>
            <input id="elementX" type="number" min="0" max="100" step="0.1">
        </div>

        <div class="form-row">
            <label>位置 Y</label>
            <input id="elementY" type="number" min="0" max="100" step="0.1">
        </div>

        <div class="form-row">
            <label>幅</label>
            <input id="elementW" type="number" min="1" max="100" step="0.1">
        </div>

        <div class="form-row">
            <label>高さ</label>
            <input id="elementH" type="number" min="1" max="100" step="0.1">
        </div>

        <div class="modal-actions">
            <button id="modalCancel">キャンセル</button>
            <button class="primary" id="modalSave">保存</button>
        </div>

    </div>

</div>

<div id="connectionModal" class="modal-backdrop">

    <div class="modal">

        <h3>接続線を編集</h3>

        <div class="form-row">
            <label>始点</label>
            <select id="connectionFromPoint">
                <option value="n">上</option>
                <option value="e" selected>右</option>
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
                <option value="w" selected>左</option>
            </select>
        </div>

        <div class="form-row">
            <label>開始</label>
            <input id="connectionStart" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>終了</label>
            <input id="connectionEnd" type="number" min="0" step="0.001">
        </div>

        <div class="form-row">
            <label>色</label>
            <input id="connectionColor" type="color">
        </div>

        <div class="form-row">
            <label>太さ</label>
            <input id="connectionWidth" type="number" min="0.5" max="10" step="0.5">
        </div>

        <div class="form-row">
            <label>矢印</label>
            <select id="connectionArrow">
                <option value="1">あり</option>
                <option value="0">なし</option>
            </select>
        </div>

        <div class="modal-actions">
            <button id="connectionCancel">キャンセル</button>
            <button class="danger" id="connectionDelete">削除</button>
            <button class="primary" id="connectionSave">保存</button>
        </div>

    </div>

</div>

<script>
'use strict';

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
    connectionTarget:null,

    db:null,

    messageTimer:null
};

function uid(prefix){
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2,10);
}

function clamp(value,min,max){
    return Math.max(min,Math.min(max,value));
}

function duration(){
    const d = Number($('recordedVideo').duration);
    return Number.isFinite(d) && d > 0
        ? d
        : Number(state.project?.videoDuration || 0);
}

function currentTime(){
    const t = Number($('recordedVideo').currentTime);
    return Number.isFinite(t) ? t : 0;
}

function fmt(sec){
    sec = Math.max(0,Number(sec)||0);

    const m = Math.floor(sec / 60);
    const s = sec - m * 60;

    return String(m).padStart(2,'0') + ':' +
        s.toFixed(3).padStart(6,'0');
}

function message(text,ok=false){
    const el = $('message');

    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    clearTimeout(state.messageTimer);

    state.messageTimer = setTimeout(()=>{
        el.style.display = 'none';
    },3000);
}

function markDirty(){
    if(!state.project) return;

    state.dirty = true;

    $('editorStatus').textContent = '未保存';
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

    const video = $('recordedVideo');

    video.pause();
    video.removeAttribute('src');
    video.load();

    renderProjectList();
}

function setEditorLocked(locked){
    $('editor').classList.toggle('locked',locked);
}

function newProject(){
    state.project = {
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

    state.selectedId = null;
    state.selectedType = null;
    state.multiSelected = [];
    state.dirty = false;

    $('editorProjectName').value = state.project.name;
    $('editorStatus').textContent = '動画未読込';

    showEditor();
    setEditorLocked(true);
    renderAll();
}

function normalizeElement(e){
    const d = duration() || Number(state.project?.videoDuration || 0);

    if(d > 0){
        e.start = clamp(Number(e.start)||0,0,d);

        const minEnd = Math.min(d,e.start + .05);

        e.end = clamp(
            Number(e.end) || Math.min(d,e.start+5),
            minEnd,
            d
        );
    }

    e.x = clamp(Number(e.x)||0,0,99);
    e.y = clamp(Number(e.y)||0,0,99);

    e.w = clamp(
        Number(e.w)||30,
        1,
        100-e.x
    );

    e.h = clamp(
        Number(e.h)||15,
        1,
        100-e.y
    );
}

function makeElement(type,time){
    const d = duration();

    const start = clamp(
        Number.isFinite(time) ? time : 0,
        0,
        d
    );

    const end = d > 0
        ? clamp(
            start + Math.min(5,Math.max(.05,d-start)),
            Math.min(d,start+.05),
            d
        )
        : start + 5;

    return {
        id:uid('element'),
        type,
        text:type === 'comment' ? 'テキスト' : '',
        start,
        end,
        x:10,
        y:10,
        w:type === 'box' ? 35 : 30,
        h:type === 'box' ? 25 : 15,
        color:type === 'skip' ? '#f97316' : '#ffffff',
        borderWidth:2,
        borderStyle:type === 'skip' ? 'dashed' : 'solid',
        fontSize:24,
        fontWeight:600,
        opacity:1,
        radius:4
    };
}

function getElement(id){
    return state.project?.elements.find(e=>e.id===id) || null;
}

function getConnection(id){
    return state.project?.connections.find(c=>c.id===id) || null;
}

function selectElement(id,add=false){
    const e = getElement(id);

    if(!e) return;

    state.selectedType = 'element';

    if(add){
        if(state.multiSelected.includes(id)){
            state.multiSelected =
                state.multiSelected.filter(x=>x!==id);
        }else{
            if(state.multiSelected.length >= 2){
                state.multiSelected.shift();
            }

            state.multiSelected.push(id);
        }
    }else{
        state.multiSelected = [id];
    }

    state.selectedId = id;

    renderObjects();
    renderTimeline();
    updateConnectionStatus();
}

function selectConnection(id){
    state.selectedId = id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    renderAll();
}

function seek(time){
    const d = duration();

    if(!d) return;

    const t = clamp(Number(time)||0,0,d);

    $('recordedVideo').currentTime = t;

    renderObjects();
    renderConnectors();
    updatePlayhead();
}

function handleVideo(file){
    if(!file || !file.type.startsWith('video/')){
        message('動画ファイルを選択してください。');
        return;
    }

    const video = $('recordedVideo');

    setEditorLocked(true);

    if(state.videoObjectUrl){
        URL.revokeObjectURL(state.videoObjectUrl);
    }

    state.videoObjectUrl = URL.createObjectURL(file);

    video.pause();
    video.src = state.videoObjectUrl;
    video.load();

    if(!state.project){
        newProject();
    }

    state.project.videoName = file.name;
    state.project.videoType = file.type;
    state.project.videoSize = file.size;

    $('editorStatus').textContent = '動画読込中…';

    markDirty();
    showEditor();
}

function addElement(type){
    if(!state.project){
        message('先にプロジェクトを作成してください。');
        return;
    }

    if(duration() <= 0){
        message('先に動画を読み込んでください。');
        return;
    }

    const e = makeElement(type,currentTime());

    state.project.elements.push(e);

    selectElement(e.id,false);

    markDirty();
    renderAll();

    openElementModal(e);
}

function duplicateSelected(){
    if(state.selectedType !== 'element'){
        message('複製する要素を選択してください。');
        return;
    }

    const source = getElement(state.selectedId);

    if(!source) return;

    const e = JSON.parse(JSON.stringify(source));

    e.id = uid('element');

    const d = duration();

    e.x = clamp(e.x + 3,0,100-e.w);
    e.y = clamp(e.y + 3,0,100-e.h);

    e.start = clamp(
        e.start + .5,
        0,
        Math.max(0,d-.05)
    );

    e.end = clamp(
        e.end + .5,
        e.start+.05,
        d
    );

    state.project.elements.push(e);

    selectElement(e.id,false);
    markDirty();
    renderAll();
}

function deleteSelected(){
    if(!state.project) return;

    if(state.selectedType === 'connection'){
        const index =
            state.project.connections.findIndex(
                c=>c.id===state.selectedId
            );

        if(index >= 0){
            state.project.connections.splice(index,1);
        }

        state.selectedId = null;
        state.selectedType = null;

        markDirty();
        renderAll();
        return;
    }

    if(state.selectedType === 'element'){
        const id = state.selectedId;

        state.project.elements =
            state.project.elements.filter(e=>e.id!==id);

        state.project.connections =
            state.project.connections.filter(
                c=>c.from!==id && c.to!==id
            );

        state.selectedId = null;
        state.selectedType = null;
        state.multiSelected =
            state.multiSelected.filter(x=>x!==id);

        markDirty();
        renderAll();
    }
}

/* =========================================================
 * Video objects
 * ======================================================= */

function renderObjects(){
    const container = $('objects');

    container.innerHTML = '';

    if(!state.project) return;

    const t = currentTime();

    for(const e of state.project.elements){

        if(t < e.start || t > e.end){
            continue;
        }

        const el = document.createElement('div');

        el.className =
            'edit-object ' +
            e.type +
            (e.id===state.selectedId ? ' selected' : '') +
            (
                state.multiSelected.includes(e.id)
                    ? ' multi-selected'
                    : ''
            );

        el.dataset.id = e.id;

        el.style.left = `${e.x}%`;
        el.style.top = `${e.y}%`;
        el.style.width = `${e.w}%`;
        el.style.height = `${e.h}%`;

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
            const label = document.createElement('span');

            label.className = 'skip-label';
            label.textContent = 'SKIP';

            el.appendChild(label);
        }

        if(e.type !== 'skip'){
            const handle = document.createElement('div');

            handle.className = 'resize-handle';

            handle.addEventListener(
                'pointerdown',
                event=>{
                    beginElementResize(event,e);
                }
            );

            el.appendChild(handle);
        }

        el.addEventListener(
            'pointerdown',
            event=>{
                if(event.target.closest('.resize-handle')){
                    return;
                }

                const add = event.shiftKey;

                selectElement(e.id,add);

                beginElementMove(event,e);

                event.stopPropagation();
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

function beginElementMove(event,e){
    if(event.button !== 0) return;

    const stage =
        $('videoStage').getBoundingClientRect();

    state.drag = {
        mode:'move',
        id:e.id,
        startX:event.clientX,
        startY:event.clientY,
        originalX:e.x,
        originalY:e.y,
        stageW:Math.max(1,stage.width),
        stageH:Math.max(1,stage.height),
        pointerId:event.pointerId
    };

    const target = event.currentTarget;

    if(
        target &&
        typeof target.setPointerCapture === 'function'
    ){
        try{
            target.setPointerCapture(event.pointerId);
        }catch(err){
            /* captureできない場合はwindow側で継続 */
        }
    }

    event.preventDefault();
}

function beginElementResize(event,e){
    if(event.button !== 0) return;

    const stage =
        $('videoStage').getBoundingClientRect();

    state.drag = {
        mode:'resize',
        id:e.id,
        startX:event.clientX,
        startY:event.clientY,
        originalW:e.w,
        originalH:e.h,
        stageW:Math.max(1,stage.width),
        stageH:Math.max(1,stage.height),
        pointerId:event.pointerId
    };

    const target = event.currentTarget;

    if(
        target &&
        typeof target.setPointerCapture === 'function'
    ){
        try{
            target.setPointerCapture(event.pointerId);
        }catch(err){}
    }

    event.stopPropagation();
    event.preventDefault();
}

function moveElementPointer(event){
    const drag = state.drag;

    if(!drag) return;

    const e = getElement(drag.id);

    if(!e) return;

    const dx =
        (event.clientX-drag.startX) /
        drag.stageW * 100;

    const dy =
        (event.clientY-drag.startY) /
        drag.stageH * 100;

    if(drag.mode === 'move'){
        e.x = clamp(
            drag.originalX + dx,
            0,
            100-e.w
        );

        e.y = clamp(
            drag.originalY + dy,
            0,
            100-e.h
        );
    }

    if(drag.mode === 'resize'){
        e.w = clamp(
            drag.originalW + dx,
            1,
            100-e.x
        );

        e.h = clamp(
            drag.originalH + dy,
            1,
            100-e.y
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
    if(state.multiSelected.length !== 2){
        message(
            'Shiftキーを押しながら2つの要素を選択してください。'
        );
        return;
    }

    const [fromId,toId] =
        state.multiSelected;

    if(fromId === toId){
        message('同じ要素同士は接続できません。');
        return;
    }

    const from = getElement(fromId);
    const to = getElement(toId);

    if(!from || !to){
        message('接続対象が見つかりません。');
        return;
    }

    const duplicate =
        state.project.connections.some(
            c=>(
                c.from===fromId &&
                c.to===toId
            ) ||
            (
                c.from===toId &&
                c.to===fromId
            )
        );

    if(duplicate){
        message('その2要素は既に接続されています。');
        return;
    }

    const connection = {
        id:uid('connection'),
        from:fromId,
        to:toId,
        fromPoint:'e',
        toPoint:'w',
        start:Math.min(from.start,to.start),
        end:Math.max(from.end,to.end),
        color:from.color || '#ffffff',
        width:1.5,
        dash:'',
        arrow:true,
        curve:35
    };

    state.project.connections.push(connection);

    state.selectedId = connection.id;
    state.selectedType = 'connection';
    state.multiSelected = [];

    markDirty();
    renderAll();

    message('要素を接続しました。',true);
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

    if(!state.project) return;

    const t = currentTime();

    const defs =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'defs'
        );

    svg.appendChild(defs);

    for(const c of state.project.connections){

        const from = getElement(c.from);
        const to = getElement(c.to);

        if(!from || !to) continue;

        /*
         * 接続線は接続自身の時間範囲で表示。
         * 要素の時間範囲に引っ張られない。
         */
        if(t < c.start || t > c.end){
            continue;
        }

        const p1 =
            pointOfElement(
                from,
                c.fromPoint || 'e'
            );

        const p2 =
            pointOfElement(
                to,
                c.toPoint || 'w'
            );

        const path =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'path'
            );

        const dx = Math.max(
            30,
            Math.abs(p2.x-p1.x) * .35
        );

        let d;

        if(
            (c.fromPoint || 'e') === 'e' &&
            (c.toPoint || 'w') === 'w'
        ){
            d =
                `M ${p1.x} ${p1.y}
                 C ${p1.x+dx} ${p1.y},
                   ${p2.x-dx} ${p2.y},
                   ${p2.x} ${p2.y}`;
        }else{
            const mx = (p1.x+p2.x)/2;
            const my = (p1.y+p2.y)/2;

            d =
                `M ${p1.x} ${p1.y}
                 Q ${mx} ${my}
                   ${p2.x} ${p2.y}`;
        }

        path.setAttribute('d',d);
        path.setAttribute(
            'stroke',
            c.color || from.color || '#fff'
        );
        path.setAttribute(
            'stroke-width',
            String(c.width || 1.5)
        );
        path.setAttribute(
            'stroke-linecap',
            'round'
        );

        if(c.dash){
            path.setAttribute('stroke-dasharray',c.dash);
        }

        if(c.arrow){
            const markerId =
                'arrow-' + c.id.replace(/[^a-zA-Z0-9_-]/g,'');

            const marker =
                document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'marker'
                );

            marker.setAttribute('id',markerId);
            marker.setAttribute('viewBox','0 0 10 10');
            marker.setAttribute('refX','9');
            marker.setAttribute('refY','5');
            marker.setAttribute('markerWidth','6');
            marker.setAttribute('markerHeight','6');
            marker.setAttribute('orient','auto-start-reverse');

            const polygon =
                document.createElementNS(
                    'http://www.w3.org/2000/svg',
                    'path'
                );

            polygon.setAttribute(
                'd',
                'M 0 0 L 10 5 L 0 10 z'
            );

            polygon.setAttribute(
                'fill',
                c.color || '#fff'
            );

            marker.appendChild(polygon);
            defs.appendChild(marker);

            path.setAttribute(
                'marker-end',
                `url(#${markerId})`
            );
        }

        if(c.id === state.selectedId){
            path.classList.add('connector','selected');
        }else{
            path.classList.add('connector');
        }

        path.dataset.id = c.id;

        path.addEventListener(
            'click',
            event=>{
                event.stopPropagation();
                selectConnection(c.id);
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
 * ======================================================= */

function timelineWidth(){
    const d = duration();

    if(!d){
        return 800;
    }

    /*
     * 全トラック共通。
     * 赤いプレイヘッドや個々の要素幅からは独立。
     */
    const pxPerSecond =
        70 * state.zoom;

    return Math.max(
        800,
        d * pxPerSecond
    );
}

function renderTimeline(){
    const scale = $('timelineScale');
    const tracks = $('timelineTracks');
    const content = $('timelineContent');

    scale.innerHTML = '';
    tracks.innerHTML = '';

    const d = duration();

    if(!d){
        content.style.width = '800px';
        return;
    }

    const width = timelineWidth();

    content.style.width = `${width}px`;

    /*
     * 0～動画長まで必ず同じ軸。
     */
    const step =
        state.zoom >= 4
            ? 1
            : state.zoom >= 2
                ? 2
                : state.zoom >= 1
                    ? 5
                    : 10;

    for(
        let t=0;
        t<=d+.0001;
        t+=step
    ){
        const span =
            document.createElement('span');

        span.textContent = fmt(Math.min(t,d));

        span.style.left =
            `${t/d*100}%`;

        scale.appendChild(span);
    }

    renderTrack(
        'テキスト',
        'comment',
        state.project.elements.filter(
            e=>e.type==='comment'
        ),
        width,
        d
    );

    renderTrack(
        '強調枠',
        'box',
        state.project.elements.filter(
            e=>e.type==='box'
        ),
        width,
        d
    );

    renderTrack(
        'スキップ',
        'skip',
        state.project.elements.filter(
            e=>e.type==='skip'
        ),
        width,
        d
    );

    renderTrack(
        '接続',
        'connection',
        state.project.connections,
        width,
        d
    );

    updatePlayhead();
}

function renderTrack(name,type,items,width,d){
    const row =
        document.createElement('div');

    row.className = 'timeline-row';

    const label =
        document.createElement('div');

    label.className = 'timeline-label';
    label.textContent = name;

    const track =
        document.createElement('div');

    track.className = 'track';
    track.style.width = `${width}px`;

    row.appendChild(label);
    row.appendChild(track);

    /*
     * 全trackが同じwidth。
     * 赤棒の位置は幅計算に一切使わない。
     */
    items.forEach(item=>{
        const bar =
            document.createElement('div');

        const selected =
            item.id === state.selectedId;

        const multi =
            state.multiSelected.includes(item.id);

        bar.className =
            `track-item ${type}` +
            (selected ? ' selected' : '') +
            (multi ? ' multi' : '');

        const start =
            Number(item.start)||0;

        const end =
            Number(item.end)||0;

        const left =
            clamp(start/d*100,0,100);

        const widthPercent =
            clamp(
                (end-start)/d*100,
                .15,
                100-left
            );

        bar.style.left =
            `${left}%`;

        bar.style.width =
            `${widthPercent}%`;

        const startText =
            document.createElement('span');

        startText.className = 'track-time';
        startText.textContent = fmt(start);

        const endText =
            document.createElement('span');

        endText.className = 'track-end-time';
        endText.textContent = fmt(end);

        const nameText =
            document.createElement('span');

        nameText.className = 'track-name';

        nameText.textContent =
            type === 'comment'
                ? item.text || 'テキスト'
                : type === 'box'
                    ? '強調枠'
                    : type === 'skip'
                        ? 'SKIP'
                        : '接続';

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

        /*
         * クリック：
         * その要素の開始時刻へ移動する。
         */
        bar.addEventListener(
            'click',
            event=>{
                if(
                    event.target.closest('.track-handle')
                ){
                    return;
                }

                if(type === 'connection'){
                    selectConnection(item.id);
                    seek(item.start);
                    return;
                }

                selectElement(
                    item.id,
                    event.shiftKey
                );

                seek(item.start);
            }
        );

        /*
         * バーを左右に移動。
         */
        bar.addEventListener(
            'pointerdown',
            event=>{
                if(
                    event.target.closest('.track-handle')
                ){
                    return;
                }

                if(type === 'connection'){
                    return;
                }

                selectElement(
                    item.id,
                    event.shiftKey
                );

                const rect =
                    track.getBoundingClientRect();

                state.timelineDrag = {
                    item,
                    side:'move',
                    rect,
                    duration:d,
                    originalStart:start,
                    originalEnd:end,
                    startX:event.clientX,
                    pointerId:event.pointerId
                };

                const target = event.currentTarget;

                if(
                    target &&
                    typeof target.setPointerCapture === 'function'
                ){
                    try{
                        target.setPointerCapture(event.pointerId);
                    }catch(err){}
                }

                event.preventDefault();
            }
        );

        bar.addEventListener(
            'dblclick',
            event=>{
                event.preventDefault();

                if(type === 'connection'){
                    openConnectionModal(item);
                }else{
                    openElementModal(item);
                }
            }
        );

        /*
         * タイムライン要素上でも右クリック。
         */
        bar.addEventListener(
            'contextmenu',
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(type === 'connection'){
                    selectConnection(item.id);

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

        track.appendChild(bar);
    });

    $('timelineTracks').appendChild(row);
}

function beginTimelineResize(
    event,
    item,
    side,
    track,
    d
){
    if(event.button !== 0) return;

    const rect =
        track.getBoundingClientRect();

    state.timelineDrag = {
        item,
        side,
        rect,
        duration:d,
        originalStart:Number(item.start)||0,
        originalEnd:Number(item.end)||0,
        startX:event.clientX,
        pointerId:event.pointerId
    };

    const target = event.currentTarget;

    if(
        target &&
        typeof target.setPointerCapture === 'function'
    ){
        try{
            target.setPointerCapture(event.pointerId);
        }catch(err){}
    }

    event.preventDefault();
    event.stopPropagation();
}

function moveTimeline(event){
    const drag = state.timelineDrag;

    if(!drag) return;

    const item = drag.item;

    const delta =
        (
            event.clientX -
            drag.startX
        ) /
        Math.max(1,drag.rect.width) *
        drag.duration;

    if(drag.side === 'move'){
        const length =
            drag.originalEnd -
            drag.originalStart;

        let start =
            drag.originalStart + delta;

        start =
            clamp(
                start,
                0,
                drag.duration-length
            );

        item.start = start;
        item.end = start + length;
    }

    if(drag.side === 'start'){
        item.start =
            clamp(
                drag.originalStart + delta,
                0,
                drag.originalEnd-.05
            );
    }

    if(drag.side === 'end'){
        item.end =
            clamp(
                drag.originalEnd + delta,
                drag.originalStart+.05,
                drag.duration
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

function updatePlayhead(){
    const d = duration();

    if(!d){
        return;
    }

    const width = timelineWidth();

    $('playhead').style.left =
        `${currentTime()/d*width + 70}px`;

    $('currentTime').textContent =
        `${fmt(currentTime())} / ${fmt(d)}`;
}

function seekFromTimeline(event){
    const d = duration();

    if(!d) return;

    const rect =
        $('timelineScale').getBoundingClientRect();

    const x =
        clamp(
            event.clientX - rect.left,
            0,
            rect.width
        );

    seek(
        x / rect.width * d
    );
}

/* =========================================================
 * Modal: element
 * ======================================================= */

function openElementModal(e){
    if(!e) return;

    state.modalTarget = e;

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

    $('elementText').value =
        e.text || '';

    $('elementStart').value =
        Number(e.start).toFixed(3);

    $('elementEnd').value =
        Number(e.end).toFixed(3);

    $('elementColor').value =
        e.color || '#ffffff';

    $('elementBorderWidth').value =
        e.borderWidth ?? 2;

    $('elementFontSize').value =
        e.fontSize ?? 24;

    $('elementFontWeight').value =
        e.fontWeight ?? 600;

    $('elementX').value =
        Number(e.x).toFixed(1);

    $('elementY').value =
        Number(e.y).toFixed(1);

    $('elementW').value =
        Number(e.w).toFixed(1);

    $('elementH').value =
        Number(e.h).toFixed(1);

    const textVisible =
        e.type === 'comment';

    $('textRow').style.display =
        textVisible ? 'grid' : 'none';

    $('fontSizeRow').style.display =
        textVisible ? 'grid' : 'none';

    $('fontWeightRow').style.display =
        textVisible ? 'grid' : 'none';

    renderPalette();

    $('elementModal').style.display = 'flex';
}

function closeElementModal(){
    $('elementModal').style.display = 'none';
    state.modalTarget = null;
}

function saveElementModal(){
    const e = state.modalTarget;

    if(!e) return;

    const d =
        duration() ||
        Number(state.project?.videoDuration || 0);

    if(d <= 0){
        message('動画を読み込んでください。');
        return;
    }

    let start =
        Number($('elementStart').value);

    let end =
        Number($('elementEnd').value);

    if(!Number.isFinite(start)){
        start = 0;
    }

    if(!Number.isFinite(end)){
        end = start + .05;
    }

    start =
        clamp(start,0,d);

    end =
        clamp(
            end,
            start+.05,
            d
        );

    e.text =
        $('elementText').value;

    e.start = start;
    e.end = end;

    e.color =
        $('elementColor').value;

    e.borderWidth =
        clamp(
            Number($('elementBorderWidth').value)||0,
            0,
            20
        );

    e.fontSize =
        clamp(
            Number($('elementFontSize').value)||24,
            8,
            200
        );

    e.fontWeight =
        Number($('elementFontWeight').value)||600;

    e.x =
        clamp(
            Number($('elementX').value)||0,
            0,
            99
        );

    e.y =
        clamp(
            Number($('elementY').value)||0,
            0,
            99
        );

    e.w =
        clamp(
            Number($('elementW').value)||30,
            1,
            100-e.x
        );

    e.h =
        clamp(
            Number($('elementH').value)||15,
            1,
            100-e.y
        );

    normalizeElement(e);

    markDirty();
    closeElementModal();
    renderAll();
}

/* =========================================================
 * Palette
 * ======================================================= */

function renderPalette(){
    const p = $('palette');

    p.innerHTML = '';

    COLORS.forEach(color=>{
        const b =
            document.createElement('button');

        b.type = 'button';
        b.style.background = color;

        if(
            state.modalTarget &&
            state.modalTarget.color.toLowerCase() ===
            color.toLowerCase()
        ){
            b.classList.add('active');
        }

        b.addEventListener(
            'click',
            ()=>{
                $('elementColor').value = color;
                renderPalette();
            }
        );

        p.appendChild(b);
    });
}

/* =========================================================
 * Connection modal
 * ======================================================= */

function openConnectionModal(c){
    if(!c) return;

    state.connectionTarget = c;

    $('connectionFromPoint').value =
        c.fromPoint || 'e';

    $('connectionToPoint').value =
        c.toPoint || 'w';

    $('connectionStart').value =
        Number(c.start).toFixed(3);

    $('connectionEnd').value =
        Number(c.end).toFixed(3);

    $('connectionColor').value =
        c.color || '#ffffff';

    $('connectionWidth').value =
        c.width || 1.5;

    $('connectionArrow').value =
        c.arrow ? '1' : '0';

    $('connectionModal').style.display = 'flex';
}

function closeConnectionModal(){
    $('connectionModal').style.display = 'none';
    state.connectionTarget = null;
}

function saveConnectionModal(){
    const c =
        state.connectionTarget;

    if(!c) return;

    const d = duration();

    c.fromPoint =
        $('connectionFromPoint').value;

    c.toPoint =
        $('connectionToPoint').value;

    c.start =
        clamp(
            Number($('connectionStart').value)||0,
            0,
            d
        );

    c.end =
        clamp(
            Number($('connectionEnd').value)||d,
            c.start+.05,
            d
        );

    c.color =
        $('connectionColor').value;

    c.width =
        clamp(
            Number($('connectionWidth').value)||1.5,
            .5,
            10
        );

    c.arrow =
        $('connectionArrow').value === '1';

    markDirty();

    closeConnectionModal();

    renderAll();
}

function deleteConnectionModal(){
    const c =
        state.connectionTarget;

    if(!c) return;

    if(
        !confirm('この接続線を削除しますか？')
    ){
        return;
    }

    state.project.connections =
        state.project.connections.filter(
            x=>x.id!==c.id
        );

    state.selectedId = null;
    state.selectedType = null;

    markDirty();

    closeConnectionModal();
    renderAll();
}

/* =========================================================
 * Context menu
 * ======================================================= */

function showContextMenu(x,y){
    const menu = $('contextMenu');

    const connect =
        $('contextConnect');

    connect.disabled =
        state.multiSelected.length !== 2;

    menu.style.display = 'block';

    const width = menu.offsetWidth;
    const height = menu.offsetHeight;

    menu.style.left =
        `${Math.min(
            x,
            window.innerWidth-width-8
        )}px`;

    menu.style.top =
        `${Math.min(
            y,
            window.innerHeight-height-8
        )}px`;
}

function hideContextMenu(){
    $('contextMenu').style.display = 'none';
}

/* =========================================================
 * Skip
 * ======================================================= */

function processSkip(){
    const video = $('recordedVideo');

    if(video.paused || !state.project){
        return;
    }

    const t = video.currentTime;

    const skip =
        state.project.elements.find(
            e=>
                e.type === 'skip' &&
                t >= e.start &&
                t < e.end-.01
        );

    if(skip){
        video.currentTime =
            Math.min(
                skip.end,
                duration()
            );
    }
}

/* =========================================================
 * Save / load
 * ======================================================= */

function openDB(){
    return new Promise((resolve,reject)=>{
        const request =
            indexedDB.open(
                'VideoEditorDB',
                2
            );

        request.onupgradeneeded = event=>{
            const db =
                event.target.result;

            if(db.objectStoreNames.contains('projects')){
                db.deleteObjectStore('projects');
            }

            db.createObjectStore(
                'projects',
                {keyPath:'projectId'}
            );
        };

        request.onsuccess = event=>{
            state.db =
                event.target.result;

            resolve(state.db);
        };

        request.onerror = ()=>{
            reject(request.error);
        };
    });
}

function idbPut(project){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            reject(new Error('IndexedDB unavailable'));
            return;
        }

        const tx =
            state.db.transaction(
                'projects',
                'readwrite'
            );

        const store =
            tx.objectStore('projects');

        store.put(
            JSON.parse(
                JSON.stringify(project)
            )
        );

        tx.oncomplete = resolve;
        tx.onerror = ()=>{
            reject(tx.error);
        };
    });
}

function idbGet(id){
    return new Promise((resolve,reject)=>{
        if(!state.db){
            reject(new Error('IndexedDB unavailable'));
            return;
        }

        const tx =
            state.db.transaction(
                'projects',
                'readonly'
            );

        const request =
            tx.objectStore('projects').get(id);

        request.onsuccess = ()=>{
            resolve(request.result || null);
        };

        request.onerror = ()=>{
            reject(request.error);
        };
    });
}

function idbList(){
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

        const request =
            tx.objectStore('projects').getAll();

        request.onsuccess = ()=>{
            resolve(request.result || []);
        };

        request.onerror = ()=>{
            reject(request.error);
        };
    });
}

function idbDelete(id){
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

        tx.objectStore('projects').delete(id);

        tx.oncomplete = resolve;
        tx.onerror = ()=>{
            reject(tx.error);
        };
    });
}

async function saveProject(){
    if(!state.project){
        message('プロジェクトがありません。');
        return;
    }

    if(duration() <= 0){
        message('先に動画を読み込んでください。');
        return;
    }

    state.project.name =
        $('editorProjectName').value.trim() ||
        '名称未設定';

    state.project.videoDuration =
        duration();

    try{
        const response =
            await fetch(
                '?api=save',
                {
                    method:'POST',
                    headers:{
                        'Content-Type':'application/json'
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
                'サーバー保存済み';

            message(
                'サーバーに保存しました。',
                true
            );

            return;
        }

        throw new Error(
            result.limit
                ? 'SERVER_LIMIT'
                : result.message ||
                    '保存に失敗しました'
        );

    }catch(err){

        try{
            await idbPut(state.project);

            state.dirty = false;

            $('editorStatus').textContent =
                'ローカル保存済み';

            message(
                err.message === 'SERVER_LIMIT'
                    ? 'サーバー保存上限のためローカルに保存しました。'
                    : 'サーバー保存できなかったためローカルに保存しました。',
                true
            );

        }catch(localError){
            console.error(localError);

            message(
                '保存できませんでした。'
            );
        }
    }
}

function exportProject(){
    if(!state.project){
        message('プロジェクトがありません。');
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
            {type:'application/json'}
        );

    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href = url;

    a.download =
        `${state.project.name || 'project'}.json`;

    a.click();

    setTimeout(
        ()=>URL.revokeObjectURL(url),
        1000
    );

    message(
        'プロジェクトデータを書き出しました。',
        true
    );
}

async function loadServerProject(id){
    try{
        const response =
            await fetch(
                `?api=load&id=${encodeURIComponent(id)}`
            );

        const result =
            await response.json();

        if(!result.ok){
            throw new Error(result.message);
        }

        state.project =
            normalizeProject(result.project);

        $('editorProjectName').value =
            state.project.name || '';

        showEditor();

        setEditorLocked(true);

        state.dirty = false;

        $('editorStatus').textContent =
            '動画未読込';

        renderAll();

        message(
            'プロジェクトを開きました。動画を選択してください。',
            true
        );

    }catch(err){
        message(
            err.message ||
            'プロジェクトを読み込めませんでした。'
        );
    }
}

function normalizeProject(p){
    p.version =
        Number(p.version)||APP_VERSION;

    p.elements =
        Array.isArray(p.elements)
            ? p.elements
            : [];

    p.connections =
        Array.isArray(p.connections)
            ? p.connections
            : [];

    for(const e of p.elements){
        normalizeElement(e);
    }

    return p;
}

async function importProjectFile(file){
    if(!file) return;

    try{
        const text =
            await file.text();

        const project =
            JSON.parse(text);

        if(
            !project ||
            !Array.isArray(project.elements)
        ){
            throw new Error(
                'プロジェクトデータではありません。'
            );
        }

        state.project =
            normalizeProject(project);

        state.project.projectId =
            state.project.projectId ||
            uid('project');

        $('editorProjectName').value =
            state.project.name ||
            '名称未設定';

        showEditor();
        setEditorLocked(true);

        state.dirty = false;

        $('editorStatus').textContent =
            '動画未読込';

        renderAll();

        message(
            'プロジェクトを読み込みました。動画を選択してください。',
            true
        );

    }catch(err){
        message(
            '読み込みに失敗しました。\n' +
            (err.message || '')
        );
    }
}

/* =========================================================
 * Project list
 * ======================================================= */

async function renderProjectList(){
    const list =
        $('projectList');

    list.innerHTML =
        '<div style="color:#888">読み込み中…</div>';

    let projects = [];

    try{
        const response =
            await fetch('?api=list');

        const result =
            await response.json();

        if(result.ok){
            projects =
                result.projects || [];
        }
    }catch(err){
        console.error(err);
    }

    try{
        const local =
            await idbList();

        local.forEach(p=>{
            if(
                !projects.some(
                    x=>x.projectId===p.projectId
                )
            ){
                projects.push({
                    ...p,
                    storage:'local'
                });
            }
        });
    }catch(err){
        console.error(err);
    }

    projects.sort(
        (a,b)=>
            String(b.savedAt||'')
                .localeCompare(
                    String(a.savedAt||'')
                )
    );

    if(!projects.length){
        list.innerHTML =
            '<div style="color:#888">保存されたプロジェクトはありません。</div>';

        return;
    }

    list.innerHTML = '';

    projects.forEach(p=>{
        const row =
            document.createElement('div');

        row.className = 'project';

        const info =
            document.createElement('div');

        info.className = 'project-info';

        const name =
            document.createElement('div');

        name.className = 'project-name';
        name.textContent =
            p.name || '名称未設定';

        const meta =
            document.createElement('div');

        meta.className = 'project-meta';

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

        const load =
            document.createElement('button');

        load.textContent = '開く';

        load.addEventListener(
            'click',
            async ()=>{
                if(p.storage === 'local'){
                    try{
                        const project =
                            await idbGet(
                                p.projectId
                            );

                        if(!project){
                            throw new Error(
                                'ローカル保存データが見つかりません。'
                            );
                        }

                        state.project =
                            normalizeProject(project);

                        $('editorProjectName').value =
                            state.project.name || '';

                        showEditor();
                        setEditorLocked(true);

                        state.dirty = false;

                        $('editorStatus').textContent =
                            '動画未読込';

                        renderAll();

                        message(
                            'ローカルプロジェクトを開きました。動画を選択してください。',
                            true
                        );

                    }catch(err){
                        message(err.message);
                    }

                }else{
                    loadServerProject(
                        p.projectId
                    );
                }
            }
        );

        actions.appendChild(load);

        if(p.storage === 'local'){
            const del =
                document.createElement('button');

            del.className = 'danger';
            del.textContent = '削除';

            del.addEventListener(
                'click',
                async ()=>{
                    if(
                        !confirm(
                            'このローカルプロジェクトを削除しますか？'
                        )
                    ){
                        return;
                    }

                    await idbDelete(
                        p.projectId
                    );

                    renderProjectList();
                }
            );

            actions.appendChild(del);
        }

        row.appendChild(info);
        row.appendChild(actions);

        list.appendChild(row);
    });
}

/* =========================================================
 * Render
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

    if(state.multiSelected.length === 2){
        el.textContent =
            '2要素を選択中。右クリック →「選択した2要素を接続」';
        return;
    }

    if(state.multiSelected.length === 1){
        el.textContent =
            '1要素を選択中。Shift＋クリックでもう1つ選択してください。';
        return;
    }

    if(state.selectedType === 'connection'){
        el.textContent =
            '接続線を選択中。右クリックまたはダブルクリックで編集できます。';
        return;
    }

    el.textContent =
        'Shift＋クリックで2要素を選択 → 右クリック「選択した2要素を接続」';
}

/* =========================================================
 * Events
 * ======================================================= */

$('newProject')?.addEventListener(
    'click',
    newProject
);

$('openVideo')?.addEventListener(
    'click',
    ()=>{
        if(!state.project){
            newProject();
        }

        $('videoFile').click();
    }
);

$('videoFile')?.addEventListener(
    'change',
    event=>{
        handleVideo(
            event.target.files?.[0]
        );

        event.target.value = '';
    }
);

$('loadVideoButton')?.addEventListener(
    'click',
    ()=>{
        $('editorVideoFile').click();
    }
);

$('editorVideoFile')?.addEventListener(
    'change',
    event=>{
        handleVideo(
            event.target.files?.[0]
        );

        event.target.value = '';
    }
);

$('importProject')?.addEventListener(
    'click',
    ()=>{
        $('projectFile').click();
    }
);

$('projectFile')?.addEventListener(
    'change',
    event=>{
        importProjectFile(
            event.target.files?.[0]
        );

        event.target.value = '';
    }
);

$('backHome')?.addEventListener(
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

$('saveProject')?.addEventListener(
    'click',
    saveProject
);

$('exportProject')?.addEventListener(
    'click',
    exportProject
);

$('editorProjectName')?.addEventListener(
    'input',
    ()=>{
        if(state.project){
            state.project.name =
                $('editorProjectName').value;

            markDirty();
        }
    }
);

$('recordedVideo')?.addEventListener(
    'loadedmetadata',
    ()=>{
        const video =
            $('recordedVideo');

        if(!state.project){
            newProject();
        }

        state.project.videoDuration =
            Number(video.duration)||0;

        setEditorLocked(false);

        $('editorStatus').textContent =
            `${video.videoWidth}×${video.videoHeight} / ${fmt(video.duration)}`;

        renderAll();
    }
);

$('recordedVideo')?.addEventListener(
    'timeupdate',
    ()=>{
        processSkip();

        updatePlayhead();
        renderObjects();
        renderConnectors();
    }
);

$('recordedVideo')?.addEventListener(
    'play',
    ()=>{
        $('playToggle').textContent = '❚❚';
    }
);

$('recordedVideo')?.addEventListener(
    'pause',
    ()=>{
        $('playToggle').textContent = '▶';
    }
);

$('recordedVideo')?.addEventListener(
    'ended',
    ()=>{
        $('playToggle').textContent = '▶';
        updatePlayhead();
    }
);

$('recordedVideo')?.addEventListener(
    'durationchange',
    ()=>{
        if(state.project){
            state.project.videoDuration =
                Number($('recordedVideo').duration)||0;
        }

        renderTimeline();
        updatePlayhead();
    }
);

$('playToggle')?.addEventListener(
    'click',
    ()=>{
        const video =
            $('recordedVideo');

        if(!video.src || duration() <= 0){
            message(
                '先に動画を読み込んでください。'
            );

            return;
        }

        if(video.paused){
            video.play().catch(err=>{
                console.error(err);

                message(
                    '動画を再生できませんでした。'
                );
            });
        }else{
            video.pause();
        }
    }
);

$('timelineScale')?.addEventListener(
    'click',
    event=>{
        seekFromTimeline(event);
    }
);

$('timelineZoom')?.addEventListener(
    'change',
    event=>{
        state.zoom =
            Number(event.target.value)||1;

        renderTimeline();
    }
);

function changeZoom(dir){
    const values =
        [.5,1,2,3,5];

    const current =
        values.indexOf(state.zoom);

    const index =
        clamp(
            current+dir,
            0,
            values.length-1
        );

    state.zoom =
        values[index];

    $('timelineZoom').value =
        String(state.zoom);

    renderTimeline();
}

$('timelineZoomOut')?.addEventListener(
    'click',
    ()=>{
        changeZoom(-1);
    }
);

$('timelineZoomIn')?.addEventListener(
    'click',
    ()=>{
        changeZoom(1);
    }
);

$('timelineFit')?.addEventListener(
    'click',
    ()=>{
        const d = duration();

        if(!d) return;

        const scroll =
            $('timelineScroll');

        const available =
            Math.max(
                500,
                scroll.clientWidth - 20
            );

        const target =
            available / (d * 70);

        const values =
            [.5,1,2,3,5];

        let closest =
            values.reduce(
                (a,b)=>
                    Math.abs(b-target) <
                    Math.abs(a-target)
                        ? b
                        : a
            );

        state.zoom = closest;

        $('timelineZoom').value =
            String(closest);

        renderTimeline();
    }
);

/*
 * 動画領域右クリック。
 * 要素や線の上なら、そのイベントを優先。
 */
$('videoArea')?.addEventListener(
    'contextmenu',
    event=>{
        if(
            event.target.closest('.edit-object') ||
            event.target.closest('.connector')
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

/*
 * タイムラインの空白右クリック。
 */
$('timelineScroll')?.addEventListener(
    'contextmenu',
    event=>{
        if(
            event.target.closest('.track-item')
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

$('contextMenu')?.addEventListener(
    'click',
    event=>{
        const button =
            event.target.closest(
                '[data-action]'
            );

        if(!button) return;

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

            if(context?.type === 'element'){
                openElementModal(
                    getElement(context.id)
                );
            }

            if(context?.type === 'connection'){
                openConnectionModal(
                    getConnection(context.id)
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
            !event.target.closest('#contextMenu')
        ){
            hideContextMenu();
        }
    }
);

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

$('modalCancel')?.addEventListener(
    'click',
    closeElementModal
);

$('modalSave')?.addEventListener(
    'click',
    saveElementModal
);

$('elementModal')?.addEventListener(
    'click',
    event=>{
        if(
            event.target === $('elementModal')
        ){
            closeElementModal();
        }
    }
);

$('connectionCancel')?.addEventListener(
    'click',
    closeConnectionModal
);

$('connectionSave')?.addEventListener(
    'click',
    saveConnectionModal
);

$('connectionDelete')?.addEventListener(
    'click',
    deleteConnectionModal
);

$('connectionModal')?.addEventListener(
    'click',
    event=>{
        if(
            event.target === $('connectionModal')
        ){
            closeConnectionModal();
        }
    }
);

/*
 * Delete
 * Escape
 * Space再生
 */
document.addEventListener(
    'keydown',
    event=>{
        const modalOpen =
            $('elementModal').style.display === 'flex' ||
            $('connectionModal').style.display === 'flex';

        if(
            event.key === 'Delete' &&
            !modalOpen
        ){
            deleteSelected();
        }

        if(event.key === 'Escape'){
            hideContextMenu();
            closeElementModal();
            closeConnectionModal();
        }

        if(
            event.key === ' ' &&
            document.activeElement &&
            !['INPUT','TEXTAREA','SELECT'].includes(
                document.activeElement.tagName
            )
        ){
            event.preventDefault();

            $('playToggle').click();
        }
    }
);

/*
 * 初期化
 */
(async function init(){

    try{
        await openDB();
    }catch(err){
        console.error(
            'IndexedDB:',
            err
        );
    }

    $('status').textContent =
        '準備完了';

    await renderProjectList();

})();
</script>

</body>
</html>

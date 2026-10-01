<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * サーバー:
 *   ./data/projects/*.json
 *
 * ブラウザ:
 *   IndexedDB
 *     - 動画本体
 *     - ローカル保存プロジェクト
 */

const APP_VERSION = 4;
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

function readServerProjects(): array {
    if (!is_dir(PROJECT_DIR)) {
        return [];
    }

    $result = [];

    foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        $data = json_decode($raw ?: '', true);

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

function projectSummary(array $project): array {
    return [
        'projectId' => (string)($project['projectId'] ?? ''),
        'name' => (string)($project['name'] ?? '名称未設定'),
        'videoName' => (string)($project['videoName'] ?? ''),
        'savedAt' => (string)($project['savedAt'] ?? ''),
        'version' => (int)($project['version'] ?? 1),
        'storage' => 'server'
    ];
}

/* =========================================================
 * PHP API
 * ======================================================= */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    if ($api === 'status') {
        jsonResponse([
            'ok' => true,
            'version' => APP_VERSION,
            'serverWritable' => ensureProjectDir(),
            'serverLimit' => MAX_SERVER_PROJECTS,
            'serverCount' => count(readServerProjects()),
            'php' => PHP_VERSION
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
                'message' => 'data/projects に書き込めません。Apache/PHPの書込権限を確認してください。'
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

        $id = (string)($payload['projectId'] ?? '');

        if ($id === '') {
            try {
                $id = 'project-' . bin2hex(random_bytes(10));
            } catch (Throwable) {
                $id = 'project-' . str_replace('.', '', uniqid('', true));
            }
        }

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
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

        if ($json === false) {
            jsonResponse([
                'ok' => false,
                'message' => 'JSONの生成に失敗しました。'
            ], 500);
        }

        if (@file_put_contents(
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
    --bg:#101114;
    --panel:#1b1e23;
    --panel2:#24282e;
    --border:#3c424a;
    --text:#f4f5f7;
    --muted:#9ba3ad;
    --blue:#176bb9;
    --green:#287c48;
    --red:#a33131;
    --yellow:#d6a72d;
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
    border:1px solid #515861;
    background:#30353b;
    color:#fff;
    border-radius:6px;
    padding:8px 11px;
    cursor:pointer;
}

button:hover:not(:disabled){
    background:#41474e;
}

button:disabled{
    opacity:.4;
    cursor:not-allowed;
}

button.primary{background:var(--blue)}
button.success{background:var(--green)}
button.danger{background:var(--red)}
button.small{padding:5px 8px;font-size:12px}

input,select,textarea{
    width:100%;
    color:#fff;
    background:#25292f;
    border:1px solid #50565e;
    border-radius:5px;
    padding:7px;
}

input[type=color]{
    height:38px;
    padding:3px;
}

textarea{
    min-height:90px;
    resize:vertical;
}

.hidden{
    display:none!important;
}

header{
    height:50px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:0 14px;
    background:#1b1e22;
    border-bottom:1px solid #33383f;
}

header h1{
    margin:0;
    font-size:16px;
}

#status{
    color:#b7bec7;
    font-size:12px;
}

#message{
    position:fixed;
    top:60px;
    left:50%;
    transform:translateX(-50%);
    z-index:9000;
    display:none;
    max-width:92vw;
    padding:10px 16px;
    border-radius:7px;
    background:#943434;
    box-shadow:0 10px 35px #000b;
    white-space:pre-wrap;
}

#message.ok{
    background:#267043;
}

/* HOME */

#home{
    height:calc(100vh - 50px);
    display:flex;
    justify-content:center;
    align-items:center;
    background:#111317;
    padding:20px;
}

.home-card{
    width:min(760px,94vw);
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

.project-list{
    margin-top:20px;
}

.project{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:11px;
    margin:7px 0;
    border:1px solid #41464e;
    border-radius:7px;
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
    margin-top:3px;
    color:#929aa4;
    font-size:11px;
}

.project-actions{
    display:flex;
    gap:5px;
    flex-shrink:0;
}

/* RECORDER */

#recorder{
    position:absolute;
    inset:50px 0 0;
    z-index:100;
    display:none;
    flex-direction:column;
    background:#000;
}

#previewWrap{
    flex:1;
    min-height:0;
    display:flex;
    justify-content:center;
    align-items:center;
}

#preview{
    max-width:100%;
    max-height:100%;
}

#recordToolbar{
    min-height:60px;
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:8px;
    padding:8px 12px;
    background:#1a1d21;
    border-top:1px solid #33383f;
}

#timer{
    min-width:80px;
    font-variant-numeric:tabular-nums;
}

/* EDITOR */

#editor{
    position:fixed;
    inset:0;
    z-index:200;
    display:none;
    flex-direction:column;
    background:#111;
}

.editor-top{
    height:50px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
    background:#1b1e22;
    border-bottom:1px solid #363b42;
}

#editorProjectName{
    width:230px;
    font-weight:600;
}

#editorStatus{
    color:#9da5ae;
    font-size:12px;
}

.editor-main{
    flex:1;
    min-height:0;
    display:grid;
    grid-template-columns:minmax(0,1fr) 330px;
}

.video-area{
    min-width:0;
    min-height:0;
    display:flex;
    justify-content:center;
    align-items:center;
    overflow:hidden;
    background:#000;
}

#videoStage{
    position:relative;
    background:#000;
    overflow:visible;
}

#recordedVideo{
    display:block;
    width:100%;
    height:100%;
    object-fit:fill;
}

#overlay{
    position:absolute;
    inset:0;
    pointer-events:none;
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
    pointer-events:auto;
    cursor:move;
    user-select:none;
    touch-action:none;
    overflow:visible;
}

.edit-object.selected{
    outline:2px solid #fff;
    outline-offset:2px;
}

.edit-object.comment{
    display:flex;
    align-items:center;
    justify-content:flex-start;
    min-width:40px;
    min-height:25px;
    padding:6px 9px;
    white-space:pre-wrap;
    word-break:break-word;
    overflow:hidden;
    box-shadow:0 2px 12px #0006;
}

.edit-object.box{
    background:transparent;
}

.resize-handle{
    position:absolute;
    right:-7px;
    bottom:-7px;
    width:13px;
    height:13px;
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
    width:12px;
    height:12px;
    margin:-6px;
    border:2px solid #fff;
    background:#257bd3;
    border-radius:50%;
    display:none;
    z-index:20;
    cursor:crosshair;
}

.edit-object.selected .connection-point{
    display:block;
}

.cp-n{left:50%;top:0}
.cp-ne{left:100%;top:0}
.cp-e{left:100%;top:50%}
.cp-se{left:100%;top:100%}
.cp-s{left:50%;top:100%}
.cp-sw{left:0;top:100%}
.cp-w{left:0;top:50%}
.cp-nw{left:0;top:0}

.connector{
    fill:none;
    stroke:#fff;
    stroke-width:2.5;
    pointer-events:stroke;
    cursor:pointer;
}

.connector.selected{
    stroke:#ffd34d;
    stroke-width:5;
}

.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:15;
    pointer-events:stroke;
    cursor:pointer;
}

/* ASIDE */

aside{
    min-width:0;
    overflow:auto;
    padding:11px;
    background:#1b1e22;
    border-left:1px solid #393e45;
}

.panel{
    margin-bottom:13px;
    padding-bottom:13px;
    border-bottom:1px solid #383d44;
}

.panel h3{
    margin:0 0 8px;
    font-size:14px;
}

.help{
    color:#999fa8;
    font-size:12px;
    line-height:1.55;
}

.button-row{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin-top:8px;
}

.field{
    display:block;
    margin:7px 0;
    color:#c5cad0;
    font-size:12px;
}

.grid2{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 7px;
}

#selectionInfo{
    padding:8px;
    background:#15181c;
    border:1px solid #353a40;
    border-radius:5px;
    color:#b8bec7;
    font-size:12px;
}

/* TIMELINE */

#timeline{
    height:88px;
    flex-shrink:0;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:7px 10px;
}

#seek{
    width:100%;
}

.timeline-row{
    display:grid;
    grid-template-columns:60px 1fr;
    gap:6px;
    margin-top:7px;
    align-items:center;
    font-size:10px;
    color:#8f969f;
}

.track{
    position:relative;
    height:13px;
    background:#292d33;
    border-radius:3px;
}

.track-item{
    position:absolute;
    top:2px;
    height:9px;
    min-width:3px;
    border-radius:2px;
}

.track-item.comment{background:#42a5f5}
.track-item.box{background:#ef5350}
.track-item.connection{background:#b77bff}

#editorFooter{
    min-height:48px;
    display:flex;
    justify-content:center;
    align-items:center;
    flex-wrap:wrap;
    gap:7px;
    padding:6px 10px;
    background:#1b1e22;
    border-top:1px solid #383d44;
}

#timeReadout{
    min-width:100px;
    text-align:center;
    font-variant-numeric:tabular-nums;
}

/* CONTEXT */

#contextMenu{
    position:fixed;
    z-index:7000;
    display:none;
    width:205px;
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

/* MODAL */

.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:8000;
    display:none;
    justify-content:center;
    align-items:center;
    padding:15px;
    background:#0009;
}

.modal{
    width:min(560px,96vw);
    max-height:92vh;
    overflow:auto;
    padding:17px;
    background:#20242a;
    border:1px solid #555b64;
    border-radius:9px;
    box-shadow:0 20px 70px #000c;
}

.modal h2{
    margin:0 0 12px;
    font-size:16px;
}

.modal-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 9px;
}

.full{
    grid-column:1/-1;
}

.modal-footer{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:13px;
}

.badge{
    display:inline-block;
    padding:2px 6px;
    border-radius:4px;
    background:#30363d;
    color:#c7cdd4;
    font-size:10px;
}

@media(max-width:850px){
    .editor-main{
        display:flex;
        flex-direction:column;
    }

    .video-area{
        min-height:300px;
        flex:1;
    }

    aside{
        max-height:42vh;
        border-left:0;
        border-top:1px solid #393e45;
    }

    #editorProjectName{
        width:150px;
    }
}
</style>
</head>

<body>

<header>
    <h1>動画上直接編集ツール</h1>
    <div id="status">待機中</div>
</header>

<div id="message"></div>

<!-- HOME -->
<section id="home">
    <div class="home-card">
        <h2>動画を編集</h2>

        <p>
            動画を読み込んでから編集を開始します。
            プロジェクトには明示的な名前を付けて管理できます。
            動画本体はブラウザのIndexedDBへ永続保存し、
            編集データはサーバーまたはローカルへ保存できます。
        </p>

        <div class="home-actions">
            <button id="newVideoBtn" class="primary">
                動画ファイルを読み込む
            </button>

            <button id="recordScreenBtn">
                画面を録画
            </button>

            <button id="manageBtn">
                保存データ管理
            </button>
        </div>

        <input
            id="videoFile"
            type="file"
            accept="video/*"
            class="hidden"
        >

        <div id="projectList" class="project-list"></div>
    </div>
</section>

<!-- RECORDER -->
<section id="recorder">
    <div id="previewWrap">
        <video
            id="preview"
            autoplay
            muted
            playsinline
        ></video>
    </div>

    <div id="recordToolbar">
        <label>
            <input id="systemAudio" type="checkbox">
            画面音声
        </label>

        <label>
            <input id="microphone" type="checkbox">
            マイク
        </label>

        <span id="timer">00:00:00</span>

        <button id="startRecord" class="primary">
            録画開始
        </button>

        <button id="pauseRecord" disabled>
            一時停止
        </button>

        <button id="stopRecord" class="danger" disabled>
            停止
        </button>

        <button id="recordCancel">
            戻る
        </button>
    </div>
</section>

<!-- EDITOR -->
<section id="editor">

    <div class="editor-top">
        <button id="backHome">
            閉じる
        </button>

        <input
            id="editorProjectName"
            maxlength="120"
            placeholder="プロジェクト名"
        >

        <button id="saveServer" class="success">
            保存
        </button>

        <button id="saveLocal">
            ローカル保存
        </button>

        <button id="exportJson">
            書き出し
        </button>

        <button id="importJson">
            読み込み
        </button>

        <input
            id="jsonFile"
            type="file"
            accept=".json,application/json"
            class="hidden"
        >

        <span id="editorStatus"></span>
    </div>

    <div class="editor-main">

        <div class="video-area" id="videoArea">

            <div id="videoStage">

                <video
                    id="recordedVideo"
                    controls
                    playsinline
                ></video>

                <div id="overlay">
                    <svg id="connectors"></svg>
                    <div id="objects"></div>
                </div>

            </div>

        </div>

        <aside>

            <div class="panel">
                <h3>編集</h3>

                <div class="help">
                    動画上へ直接要素を配置します。
                    要素はドラッグ移動・リサイズできます。
                    書式変更は各要素を右クリックしてください。
                    線も右クリックで接点と書式を変更できます。
                </div>

                <div class="button-row">
                    <button id="addComment" class="primary">
                        コメント
                    </button>

                    <button id="addBox" class="primary">
                        強調枠
                    </button>

                    <button id="connectMode">
                        線で接続
                    </button>

                    <button id="deleteSelected" class="danger">
                        削除
                    </button>
                </div>
            </div>

            <div class="panel">
                <h3>選択中</h3>

                <div id="selectionInfo">
                    何も選択されていません
                </div>

                <div id="objectEditor" class="hidden">

                    <label id="textField" class="field">
                        内容
                        <textarea id="objectText"></textarea>
                    </label>

                    <div class="grid2">
                        <label class="field">
                            開始
                            <input
                                id="objectStart"
                                type="number"
                                min="0"
                                step=".1"
                            >
                        </label>

                        <label class="field">
                            終了
                            <input
                                id="objectEnd"
                                type="number"
                                min="0"
                                step=".1"
                            >
                        </label>

                        <label class="field">
                            左 %
                            <input
                                id="objectX"
                                type="number"
                                min="0"
                                max="100"
                                step=".1"
                            >
                        </label>

                        <label class="field">
                            上 %
                            <input
                                id="objectY"
                                type="number"
                                min="0"
                                max="100"
                                step=".1"
                            >
                        </label>

                        <label class="field">
                            幅 %
                            <input
                                id="objectW"
                                type="number"
                                min=".5"
                                max="100"
                                step=".1"
                            >
                        </label>

                        <label class="field">
                            高さ %
                            <input
                                id="objectH"
                                type="number"
                                min=".5"
                                max="100"
                                step=".1"
                            >
                        </label>
                    </div>

                    <button id="applyObject" class="primary">
                        反映
                    </button>
                </div>
            </div>

            <div class="panel">
                <h3>保存</h3>

                <div class="help">
                    サーバー保存には上限があります。
                    上限に達した場合はローカル保存へ切り替えられます。
                    動画本体はブラウザのIndexedDBへ保存されます。
                </div>

                <div class="button-row">
                    <button id="showManage">
                        保存データ管理
                    </button>
                </div>
            </div>

        </aside>
    </div>

    <div id="timeline">
        <input
            id="seek"
            type="range"
            min="0"
            max="0"
            value="0"
            step=".01"
        >

        <div class="timeline-row">
            <span>要素</span>
            <div id="elementTrack" class="track"></div>
        </div>

        <div class="timeline-row">
            <span>接続線</span>
            <div id="connectionTrack" class="track"></div>
        </div>
    </div>

    <div id="editorFooter">

        <button id="playVideo" class="primary">
            再生
        </button>

        <button id="pauseVideo">
            停止
        </button>

        <span id="timeReadout">
            00:00 / 00:00
        </span>

        <button id="startPoint">
            開始を現在位置
        </button>

        <button id="endPoint">
            終了を現在位置
        </button>

    </div>

</section>

<!-- CONTEXT MENU -->
<div id="contextMenu">
    <button data-action="style">
        書式を変更
    </button>

    <button data-action="duplicate">
        複製
    </button>

    <button data-action="delete">
        削除
    </button>
</div>

<!-- OBJECT STYLE MODAL -->
<div id="objectModal" class="modal-backdrop">
    <div class="modal">

        <h2>要素の書式</h2>

        <div class="modal-grid">

            <label class="field full">
                内容
                <textarea id="mText"></textarea>
            </label>

            <label class="field">
                文字色
                <input id="mColor" type="color">
            </label>

            <label class="field">
                背景色
                <input id="mBg" type="color">
            </label>

            <label class="field">
                文字サイズ
                <input
                    id="mFontSize"
                    type="number"
                    min="8"
                    max="200"
                >
            </label>

            <label class="field">
                不透明度 %
                <input
                    id="mOpacity"
                    type="number"
                    min="0"
                    max="100"
                >
            </label>

            <label class="field">
                枠色
                <input id="mBorderColor" type="color">
            </label>

            <label class="field">
                枠幅
                <input
                    id="mBorderWidth"
                    type="number"
                    min="0"
                    max="30"
                >
            </label>

            <label class="field">
                角丸
                <input
                    id="mRadius"
                    type="number"
                    min="0"
                    max="100"
                >
            </label>

            <label class="field">
                太字
                <select id="mWeight">
                    <option value="400">通常</option>
                    <option value="600">中太字</option>
                    <option value="700">太字</option>
                    <option value="900">極太</option>
                </select>
            </label>

        </div>

        <div class="modal-footer">
            <button data-close-modal>
                キャンセル
            </button>

            <button id="applyObjectStyle" class="primary">
                反映
            </button>
        </div>

    </div>
</div>

<!-- CONNECTION MODAL -->
<div id="connectionModal" class="modal-backdrop">
    <div class="modal">

        <h2>線の設定</h2>

        <div class="modal-grid">

            <label class="field">
                接続元
                <select id="cFromPoint">
                    <option value="n">上</option>
                    <option value="ne">右上</option>
                    <option value="e">右</option>
                    <option value="se">右下</option>
                    <option value="s">下</option>
                    <option value="sw">左下</option>
                    <option value="w">左</option>
                    <option value="nw">左上</option>
                </select>
            </label>

            <label class="field">
                接続先
                <select id="cToPoint">
                    <option value="n">上</option>
                    <option value="ne">右上</option>
                    <option value="e">右</option>
                    <option value="se">右下</option>
                    <option value="s">下</option>
                    <option value="sw">左下</option>
                    <option value="w">左</option>
                    <option value="nw">左上</option>
                </select>
            </label>

            <label class="field">
                色
                <input id="cColor" type="color">
            </label>

            <label class="field">
                太さ
                <input
                    id="cWidth"
                    type="number"
                    min="1"
                    max="30"
                    step=".5"
                >
            </label>

            <label class="field">
                線種
                <select id="cDash">
                    <option value="">実線</option>
                    <option value="8 5">破線</option>
                    <option value="2 5">点線</option>
                    <option value="12 5 2 5">一点鎖線</option>
                </select>
            </label>

            <label class="field">
                始点
                <select id="cStartArrow">
                    <option value="none">なし</option>
                    <option value="arrow">矢印</option>
                    <option value="circle">円</option>
                </select>
            </label>

            <label class="field">
                終点
                <select id="cEndArrow">
                    <option value="arrow">矢印</option>
                    <option value="none">なし</option>
                    <option value="circle">円</option>
                </select>
            </label>

            <label class="field">
                曲線
                <select id="cCurve">
                    <option value="straight">直線</option>
                    <option value="smooth">曲線</option>
                </select>
            </label>

        </div>

        <div class="modal-footer">
            <button data-close-modal>
                キャンセル
            </button>

            <button id="applyConnectionStyle" class="primary">
                反映
            </button>
        </div>

    </div>
</div>

<!-- PROJECT MANAGEMENT -->
<div id="manageModal" class="modal-backdrop">
    <div class="modal">

        <h2>保存データ管理</h2>

        <div id="manageInfo" class="help"></div>

        <div id="manageList"></div>

        <div class="modal-footer">
            <button data-close-modal>
                閉じる
            </button>
        </div>

    </div>
</div>

<!-- NAME MODAL -->
<div id="nameModal" class="modal-backdrop">
    <div class="modal">

        <h2>プロジェクト名</h2>

        <label class="field">
            名前
            <input
                id="projectNameInput"
                maxlength="120"
                placeholder="例：営業説明動画_第1版"
            >
        </label>

        <div class="modal-footer">
            <button id="nameCancel">
                キャンセル
            </button>

            <button id="nameOk" class="primary">
                編集を開始
            </button>
        </div>

    </div>
</div>

<script>
/*
 * 重要:
 * PHPのAPP_VERSIONはPHPの定数なのでJavaScriptから直接参照できません。
 * PHPからJSONとして明示的に渡します。
 */
const APP_VERSION = <?= json_encode(APP_VERSION) ?>;
const MAX_SERVER_PROJECTS = <?= json_encode(MAX_SERVER_PROJECTS) ?>;

const API_BASE = location.pathname;

const state = {
    project: null,
    videoBlob: null,
    videoUrl: '',
    selected: null,
    selectedType: null,
    contextTarget: null,
    connectMode: false,
    connectSource: null,
    db: null,
    recorder: null,
    recordStream: null,
    recordTimer: null,
    recordStartedAt: 0,
    recordChunks: [],
    pendingVideoFile: null,
    pendingProjectName: '',
    saving: false,
    dirty: false
};

const $ = id => document.getElementById(id);

function uid(prefix = 'id') {
    if (window.crypto?.randomUUID) {
        return prefix + '-' + crypto.randomUUID();
    }

    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2);
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function number(value, fallback = 0) {
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
}

function nowISO() {
    return new Date().toISOString();
}

function formatTime(seconds) {
    seconds = Math.max(0, number(seconds));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);

    return [
        h ? String(h).padStart(2, '0') : '00',
        String(m).padStart(2, '0'),
        String(s).padStart(2, '0')
    ].join(':');
}

function showMessage(text, ok = false) {
    const el = $('message');
    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    clearTimeout(showMessage.timer);

    showMessage.timer = setTimeout(() => {
        el.style.display = 'none';
    }, 3500);
}

function setStatus(text) {
    $('status').textContent = text;
}

function setEditorStatus(text) {
    $('editorStatus').textContent = text;
}

function markDirty() {
    state.dirty = true;
    setEditorStatus('未保存');
}

function markClean(text = '保存済み') {
    state.dirty = false;
    setEditorStatus(text);
}

function createEmptyProject(name, videoName = '') {
    return {
        version: APP_VERSION,
        projectId: uid('project'),
        name: String(name || '名称未設定').trim(),
        videoName,
        videoKey: '',
        createdAt: nowISO(),
        savedAt: '',
        duration: 0,
        elements: [],
        connections: [],
        metadata: {
            editor: 'video-overlay-editor'
        }
    };
}

/* =========================================================
 * IndexedDB
 * ======================================================= */

function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(
            'videoOverlayEditor',
            2
        );

        request.onupgradeneeded = event => {
            const db = event.target.result;

            if (!db.objectStoreNames.contains('videos')) {
                db.createObjectStore('videos', {
                    keyPath: 'key'
                });
            }

            if (!db.objectStoreNames.contains('projects')) {
                db.createObjectStore('projects', {
                    keyPath: 'projectId'
                });
            }
        };

        request.onsuccess = () => {
            state.db = request.result;
            resolve(state.db);
        };

        request.onerror = () => {
            reject(request.error);
        };
    });
}

function idbPut(store, value) {
    return new Promise((resolve, reject) => {
        const tx = state.db.transaction(
            store,
            'readwrite'
        );

        tx.objectStore(store).put(value);

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

function idbGet(store, key) {
    return new Promise((resolve, reject) => {
        const tx = state.db.transaction(
            store,
            'readonly'
        );

        const req = tx.objectStore(store).get(key);

        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error);
    });
}

function idbGetAll(store) {
    return new Promise((resolve, reject) => {
        const tx = state.db.transaction(
            store,
            'readonly'
        );

        const req = tx.objectStore(store).getAll();

        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => reject(req.error);
    });
}

function idbDelete(store, key) {
    return new Promise((resolve, reject) => {
        const tx = state.db.transaction(
            store,
            'readwrite'
        );

        tx.objectStore(store).delete(key);

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

/* =========================================================
 * API
 * ======================================================= */

async function api(path, options = {}) {
    const response = await fetch(
        API_BASE + path,
        {
            cache: 'no-store',
            ...options,
            headers: {
                ...(options.headers || {}),
                'Cache-Control': 'no-cache'
            }
        }
    );

    let data;

    try {
        data = await response.json();
    } catch {
        throw new Error(
            'サーバーからJSONではない応答が返りました。'
        );
    }

    if (!response.ok || data.ok === false) {
        const error = new Error(
            data.message || 'サーバー処理に失敗しました。'
        );

        error.data = data;
        error.status = response.status;

        throw error;
    }

    return data;
}

async function loadServerList() {
    try {
        return await api('?api=list&_=' + Date.now());
    } catch (error) {
        console.error(error);
        return {
            projects: [],
            limit: MAX_SERVER_PROJECTS
        };
    }
}

/* =========================================================
 * HOME
 * ======================================================= */

async function refreshProjectList() {
    const list = $('projectList');
    list.innerHTML = '<div class="help">読み込み中...</div>';

    const server = await loadServerList();
    const local = await idbGetAll('projects');

    const items = [
        ...server.projects,
        ...local.map(project => ({
            projectId: project.projectId,
            name: project.name,
            videoName: project.videoName,
            savedAt: project.savedAt,
            version: project.version,
            storage: 'local'
        }))
    ];

    if (!items.length) {
        list.innerHTML = `
            <div class="help">
                保存されたプロジェクトはありません。
            </div>
        `;
        return;
    }

    const unique = new Map();

    items.forEach(item => {
        const key =
            item.storage + ':' + item.projectId;

        unique.set(key, item);
    });

    list.innerHTML = [...unique.values()]
        .map(item => `
            <div class="project">
                <div class="project-info">
                    <div class="project-name">
                        ${escapeHtml(item.name)}
                    </div>

                    <div class="project-meta">
                        ${escapeHtml(item.videoName || '動画名なし')}
                        /
                        ${item.storage === 'local'
                            ? 'ローカル'
                            : 'サーバー'}
                        /
                        ${escapeHtml(item.savedAt || '')}
                    </div>
                </div>

                <div class="project-actions">
                    <button
                        class="small"
                        data-load-project="${escapeHtml(item.projectId)}"
                        data-storage="${item.storage}"
                    >
                        開く
                    </button>

                    <button
                        class="small danger"
                        data-delete-project="${escapeHtml(item.projectId)}"
                        data-storage="${item.storage}"
                    >
                        削除
                    </button>
                </div>
            </div>
        `)
        .join('');
}

async function chooseVideo() {
    $('videoFile').click();
}

function showNameModal(file) {
    state.pendingVideoFile = file;

    $('projectNameInput').value =
        file.name.replace(/\.[^.]+$/, '');

    $('nameModal').style.display = 'flex';

    setTimeout(() => {
        $('projectNameInput').focus();
        $('projectNameInput').select();
    }, 50);
}

async function beginVideoProject(name, file) {
    if (!file) {
        throw new Error('動画ファイルが選択されていません。');
    }

    if (!file.type.startsWith('video/')) {
        throw new Error('動画ファイルを選択してください。');
    }

    const videoKey = uid('video');

    await idbPut('videos', {
        key: videoKey,
        name: file.name,
        type: file.type || 'video/mp4',
        size: file.size,
        savedAt: nowISO(),
        blob: file
    });

    const project = createEmptyProject(
        name,
        file.name
    );

    project.videoKey = videoKey;

    await openEditor(project, file);
}

/* =========================================================
 * PROJECT LOAD
 * ======================================================= */

async function loadLocalProject(id) {
    const project = await idbGet(
        'projects',
        id
    );

    if (!project) {
        throw new Error(
            'ローカル保存されたプロジェクトが見つかりません。'
        );
    }

    return project;
}

async function loadProject(id, storage) {
    let project;

    if (storage === 'local') {
        project = await loadLocalProject(id);
    } else {
        const result = await api(
            '?api=load&id=' +
            encodeURIComponent(id) +
            '&_=' + Date.now()
        );

        project = result.project;
    }

    if (!project || typeof project !== 'object') {
        throw new Error('プロジェクトデータが不正です。');
    }

    if (!project.projectId) {
        throw new Error('プロジェクトIDがありません。');
    }

    let videoRecord = null;

    if (project.videoKey) {
        videoRecord = await idbGet(
            'videos',
            project.videoKey
        );
    }

    if (!videoRecord?.blob) {
        const file = await requestMissingVideo(project);

        if (!file) {
            throw new Error(
                '動画が選択されなかったため、編集を開始できません。'
            );
        }

        const videoKey = uid('video');

        await idbPut('videos', {
            key: videoKey,
            name: file.name,
            type: file.type || 'video/mp4',
            size: file.size,
            savedAt: nowISO(),
            blob: file
        });

        project.videoKey = videoKey;
        videoRecord = {
            blob: file
        };
    }

    await openEditor(
        project,
        videoRecord.blob
    );
}

function requestMissingVideo(project) {
    return new Promise(resolve => {
        const input = document.createElement('input');

        input.type = 'file';
        input.accept = 'video/*';

        input.onchange = () => {
            resolve(input.files?.[0] || null);
        };

        input.click();
    });
}

/* =========================================================
 * EDITOR OPEN / VIDEO
 * ======================================================= */

async function openEditor(project, blob) {
    /*
     * 編集開始条件:
     * プロジェクトと動画の両方が準備できてからeditorを表示する。
     */
    if (!project || !blob) {
        throw new Error(
            '動画の読み込みが完了していないため編集できません。'
        );
    }

    state.project = normalizeProject(project);
    state.videoBlob = blob;

    if (state.videoUrl) {
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoUrl = URL.createObjectURL(blob);

    $('home').style.display = 'none';
    $('recorder').style.display = 'none';
    $('editor').style.display = 'flex';

    setStatus('編集モード');
    setEditorStatus('動画読み込み中...');

    const video = $('recordedVideo');

    video.src = state.videoUrl;
    video.load();

    await waitForVideoMetadata(video);

    state.project.duration = video.duration || 0;

    $('seek').max = String(
        video.duration || 0
    );

    updateStageSize();
    renderAll();
    selectNone();

    setEditorStatus(
        state.dirty ? '未保存' : '保存済み'
    );
}

function waitForVideoMetadata(video) {
    return new Promise((resolve, reject) => {
        if (
            video.readyState >= 1 &&
            Number.isFinite(video.duration)
        ) {
            resolve();
            return;
        }

        const done = () => {
            cleanup();
            resolve();
        };

        const fail = () => {
            cleanup();
            reject(
                new Error(
                    '動画を読み込めませんでした。'
                )
            );
        };

        const cleanup = () => {
            video.removeEventListener(
                'loadedmetadata',
                done
            );

            video.removeEventListener(
                'error',
                fail
            );
        };

        video.addEventListener(
            'loadedmetadata',
            done,
            { once:true }
        );

        video.addEventListener(
            'error',
            fail,
            { once:true }
        );
    });
}

function normalizeProject(project) {
    const p = clone(project);

    p.version = APP_VERSION;

    p.elements = Array.isArray(p.elements)
        ? p.elements
        : [];

    p.connections = Array.isArray(p.connections)
        ? p.connections
        : [];

    p.name = String(
        p.name || '名称未設定'
    ).trim();

    p.elements.forEach(element => {
        normalizeElement(element);
    });

    p.connections.forEach(connection => {
        normalizeConnection(connection);
    });

    return p;
}

function normalizeElement(element) {
    element.id ||= uid('element');

    element.type =
        element.type === 'box'
            ? 'box'
            : 'comment';

    element.text =
        String(element.text ?? '');

    element.x = number(element.x, 10);
    element.y = number(element.y, 10);
    element.w = number(element.w, 25);
    element.h = number(element.h, 12);

    element.start = Math.max(
        0,
        number(element.start, 0)
    );

    element.end = number(
        element.end,
        state.project?.duration || 999999
    );

    element.style = {
        color: '#ffffff',
        background: element.type === 'box'
            ? '#ff000000'
            : '#000000cc',
        fontSize: 24,
        opacity: 100,
        borderColor: '#ff3b30',
        borderWidth: element.type === 'box'
            ? 3
            : 0,
        radius: 4,
        fontWeight: 600,
        ...(element.style || {})
    };

    return element;
}

function normalizeConnection(connection) {
    connection.id ||= uid('connection');

    connection.fromPoint ||= 'e';
    connection.toPoint ||= 'w';

    connection.color ||= '#ffffff';
    connection.width =
        number(connection.width, 2.5);

    connection.dash ??= '';
    connection.startArrow ||= 'none';
    connection.endArrow ||= 'arrow';
    connection.curve ||= 'straight';

    connection.start =
        Math.max(0, number(connection.start, 0));

    connection.end =
        number(
            connection.end,
            state.project?.duration || 999999
        );

    return connection;
}

/* =========================================================
 * STAGE
 * ======================================================= */

function updateStageSize() {
    const video = $('recordedVideo');
    const stage = $('videoStage');

    if (!video.videoWidth || !video.videoHeight) {
        return;
    }

    const area = $('videoArea');

    const maxW = area.clientWidth - 20;
    const maxH = area.clientHeight - 20;

    const ratio =
        video.videoWidth / video.videoHeight;

    let width = maxW;
    let height = width / ratio;

    if (height > maxH) {
        height = maxH;
        width = height * ratio;
    }

    stage.style.width =
        Math.max(100, width) + 'px';

    stage.style.height =
        Math.max(100, height) + 'px';
}

function currentTime() {
    return $('recordedVideo').currentTime || 0;
}

function elementVisible(element, time) {
    return (
        time >= element.start &&
        time <= element.end
    );
}

function connectionVisible(connection, time) {
    return (
        time >= connection.start &&
        time <= connection.end
    );
}

function renderAll() {
    renderObjects();
    renderConnections();
    renderTimeline();
    updateTimeReadout();
}

function renderObjects() {
    const container = $('objects');
    const time = currentTime();

    container.innerHTML = '';

    if (!state.project) {
        return;
    }

    state.project.elements.forEach(element => {
        if (!elementVisible(element, time)) {
            return;
        }

        const el = document.createElement('div');

        el.className =
            'edit-object ' +
            element.type +
            (state.selectedType === 'element' &&
             state.selected === element.id
                ? ' selected'
                : '');

        el.dataset.id = element.id;

        el.style.left =
            element.x + '%';

        el.style.top =
            element.y + '%';

        el.style.width =
            element.w + '%';

        el.style.height =
            element.h + '%';

        const style = element.style || {};

        el.style.color =
            style.color || '#fff';

        el.style.background =
            style.background || 'transparent';

        el.style.opacity =
            clamp(number(style.opacity, 100), 0, 100) / 100;

        el.style.border =
            `${number(style.borderWidth, 0)}px solid ${style.borderColor || '#fff'}`;

        el.style.borderRadius =
            number(style.radius, 0) + 'px';

        el.style.fontSize =
            number(style.fontSize, 24) + 'px';

        el.style.fontWeight =
            number(style.fontWeight, 600);

        if (element.type === 'comment') {
            el.textContent = element.text;
        }

        if (element.type === 'box') {
            el.setAttribute(
                'aria-label',
                element.text || '強調枠'
            );
        }

        const resize = document.createElement('div');

        resize.className = 'resize-handle';
        resize.dataset.resize = '1';

        el.appendChild(resize);

        [
            'n',
            'ne',
            'e',
            'se',
            's',
            'sw',
            'w',
            'nw'
        ].forEach(point => {
            const cp =
                document.createElement('div');

            cp.className =
                'connection-point cp-' + point;

            cp.dataset.point = point;

            el.appendChild(cp);
        });

        container.appendChild(el);
    });
}

function pointPosition(element, point) {
    const x = number(element.x);
    const y = number(element.y);
    const w = number(element.w);
    const h = number(element.h);

    const map = {
        n:[x + w / 2, y],
        ne:[x + w, y],
        e:[x + w, y + h / 2],
        se:[x + w, y + h],
        s:[x + w / 2, y + h],
        sw:[x, y + h],
        w:[x, y + h / 2],
        nw:[x, y]
    };

    return map[point] || map.e;
}

function renderConnections() {
    const svg = $('connectors');

    while (svg.firstChild) {
        svg.removeChild(svg.firstChild);
    }

    if (!state.project) {
        return;
    }

    const ns =
        'http://www.w3.org/2000/svg';

    const defs =
        document.createElementNS(ns, 'defs');

    const markerArrow =
        document.createElementNS(ns, 'marker');

    markerArrow.setAttribute('id', 'arrow');
    markerArrow.setAttribute('markerWidth', '8');
    markerArrow.setAttribute('markerHeight', '8');
    markerArrow.setAttribute('refX', '7');
    markerArrow.setAttribute('refY', '4');
    markerArrow.setAttribute('orient', 'auto');
    markerArrow.setAttribute('markerUnits', 'strokeWidth');

    const arrowPath =
        document.createElementNS(ns, 'path');

    arrowPath.setAttribute(
        'd',
        'M0,0 L8,4 L0,8 Z'
    );

    arrowPath.setAttribute(
        'fill',
        'context-stroke'
    );

    markerArrow.appendChild(arrowPath);
    defs.appendChild(markerArrow);

    const markerCircle =
        document.createElementNS(ns, 'marker');

    markerCircle.setAttribute(
        'id',
        'circle'
    );

    markerCircle.setAttribute(
        'markerWidth',
        '7'
    );

    markerCircle.setAttribute(
        'markerHeight',
        '7'
    );

    markerCircle.setAttribute(
        'refX',
        '3.5'
    );

    markerCircle.setAttribute(
        'refY',
        '3.5'
    );

    const circle =
        document.createElementNS(ns, 'circle');

    circle.setAttribute('cx', '3.5');
    circle.setAttribute('cy', '3.5');
    circle.setAttribute('r', '3');
    circle.setAttribute(
        'fill',
        'context-stroke'
    );

    markerCircle.appendChild(circle);
    defs.appendChild(markerCircle);

    svg.appendChild(defs);

    const time = currentTime();

    state.project.connections.forEach(connection => {
        if (!connectionVisible(connection, time)) {
            return;
        }

        const from =
            state.project.elements.find(
                e => e.id === connection.from
            );

        const to =
            state.project.elements.find(
                e => e.id === connection.to
            );

        if (!from || !to) {
            return;
        }

        const p1 =
            pointPosition(
                from,
                connection.fromPoint
            );

        const p2 =
            pointPosition(
                to,
                connection.toPoint
            );

        const path =
            document.createElementNS(ns, 'path');

        const d =
            buildConnectionPath(
                p1,
                p2,
                connection.curve
            );

        path.setAttribute('d', d);
        path.setAttribute(
            'class',
            'connector' +
            (
                state.selectedType === 'connection' &&
                state.selected === connection.id
                    ? ' selected'
                    : ''
            )
        );

        path.setAttribute(
            'stroke',
            connection.color
        );

        path.setAttribute(
            'stroke-width',
            connection.width
        );

        if (connection.dash) {
            path.setAttribute(
                'stroke-dasharray',
                connection.dash
            );
        }

        if (connection.startArrow === 'arrow') {
            path.setAttribute(
                'marker-start',
                'url(#arrow)'
            );
        } else if (connection.startArrow === 'circle') {
            path.setAttribute(
                'marker-start',
                'url(#circle)'
            );
        }

        if (connection.endArrow === 'arrow') {
            path.setAttribute(
                'marker-end',
                'url(#arrow)'
            );
        } else if (connection.endArrow === 'circle') {
            path.setAttribute(
                'marker-end',
                'url(#circle)'
            );
        }

        path.dataset.id =
            connection.id;

        path.dataset.type =
            'connection';

        svg.appendChild(path);

        const hit =
            document.createElementNS(ns, 'path');

        hit.setAttribute(
            'd',
            d
        );

        hit.setAttribute(
            'class',
            'connector-hit'
        );

        hit.dataset.id =
            connection.id;

        hit.dataset.type =
            'connection';

        svg.appendChild(hit);
    });
}

function buildConnectionPath(p1, p2, curve) {
    const x1 = p1[0];
    const y1 = p1[1];
    const x2 = p2[0];
    const y2 = p2[1];

    if (curve !== 'smooth') {
        return `
            M ${x1} ${y1}
            L ${x2} ${y2}
        `;
    }

    const dx = Math.abs(x2 - x1);
    const bend = Math.max(20, dx * .45);

    return `
        M ${x1} ${y1}
        C ${x1 + bend} ${y1},
          ${x2 - bend} ${y2},
          ${x2} ${y2}
    `;
}

function renderTimeline() {
    const elementTrack =
        $('elementTrack');

    const connectionTrack =
        $('connectionTrack');

    elementTrack.innerHTML = '';
    connectionTrack.innerHTML = '';

    const duration =
        Math.max(
            0.01,
            state.project?.duration || 0
        );

    (state.project?.elements || [])
        .forEach(element => {
            const item =
                document.createElement('div');

            item.className =
                'track-item ' + element.type;

            item.style.left =
                clamp(
                    element.start / duration * 100,
                    0,
                    100
                ) + '%';

            item.style.width =
                clamp(
                    (element.end - element.start) /
                    duration * 100,
                    0,
                    100
                ) + '%';

            elementTrack.appendChild(item);
        });

    (state.project?.connections || [])
        .forEach(connection => {
            const item =
                document.createElement('div');

            item.className =
                'track-item connection';

            item.style.left =
                clamp(
                    connection.start / duration * 100,
                    0,
                    100
                ) + '%';

            item.style.width =
                clamp(
                    (connection.end - connection.start) /
                    duration * 100,
                    0,
                    100
                ) + '%';

            connectionTrack.appendChild(item);
        });
}

function updateTimeReadout() {
    const video = $('recordedVideo');

    $('timeReadout').textContent =
        formatTime(video.currentTime) +
        ' / ' +
        formatTime(video.duration || 0);

    $('seek').value =
        video.currentTime || 0;
}

/* =========================================================
 * SELECT
 * ======================================================= */

function selectElement(id) {
    state.selectedType = 'element';
    state.selected = id;
    state.connectSource = null;

    const element =
        state.project.elements.find(
            item => item.id === id
        );

    updateSelectionInfo(element);
    renderAll();
}

function selectConnection(id) {
    state.selectedType = 'connection';
    state.selected = id;
    state.connectSource = null;

    const connection =
        state.project.connections.find(
            item => item.id === id
        );

    updateSelectionInfo(connection);
    renderAll();
}

function selectNone() {
    state.selected = null;
    state.selectedType = null;
    state.connectSource = null;

    $('selectionInfo').textContent =
        '何も選択されていません';

    $('objectEditor').classList.add(
        'hidden'
    );

    renderAll();
}

function updateSelectionInfo(item) {
    const editor =
        $('objectEditor');

    if (!item) {
        selectNone();
        return;
    }

    if (state.selectedType === 'element') {
        $('selectionInfo').innerHTML = `
            <span class="badge">
                ${item.type === 'comment'
                    ? 'コメント'
                    : '強調枠'}
            </span>
            <br>
            ${escapeHtml(
                item.text || '(内容なし)'
            )}
        `;

        $('objectText').value =
            item.text || '';

        $('objectStart').value =
            item.start;

        $('objectEnd').value =
            item.end;

        $('objectX').value =
            item.x;

        $('objectY').value =
            item.y;

        $('objectW').value =
            item.w;

        $('objectH').value =
            item.h;

        $('textField').style.display =
            item.type === 'comment'
                ? 'block'
                : 'none';

        editor.classList.remove(
            'hidden'
        );

        return;
    }

    const from =
        state.project.elements.find(
            e => e.id === item.from
        );

    const to =
        state.project.elements.find(
            e => e.id === item.to
        );

    $('selectionInfo').innerHTML = `
        <span class="badge">接続線</span>
        <br>
        ${escapeHtml(from?.text || from?.type || '?')}
        →
        ${escapeHtml(to?.text || to?.type || '?')}
    `;

    editor.classList.add('hidden');
}

/* =========================================================
 * ELEMENT CREATION
 * ======================================================= */

function addElement(type) {
    if (!state.project) {
        return;
    }

    const time =
        currentTime();

    const duration =
        state.project.duration || 10;

    const element = {
        id: uid('element'),
        type,
        text:
            type === 'comment'
                ? 'ここにコメント'
                : '',
        x: 10,
        y: 10,
        w: type === 'comment'
            ? 30
            : 30,
        h: type === 'comment'
            ? 12
            : 25,
        start: time,
        end: Math.min(
            duration,
            time + 5
        ),
        style: {
            color: '#ffffff',
            background:
                type === 'comment'
                    ? '#000000cc'
                    : 'transparent',
            fontSize: 24,
            opacity: 100,
            borderColor: '#ff3b30',
            borderWidth:
                type === 'box' ? 3 : 0,
            radius: 4,
            fontWeight: 600
        }
    };

    normalizeElement(element);

    state.project.elements.push(
        element
    );

    markDirty();
    selectElement(element.id);
}

function applyObjectFields() {
    if (
        state.selectedType !== 'element' ||
        !state.selected
    ) {
        return;
    }

    const element =
        state.project.elements.find(
            e => e.id === state.selected
        );

    if (!element) {
        return;
    }

    const duration =
        state.project.duration || 999999;

    element.text =
        $('objectText').value;

    element.start =
        clamp(
            number(
                $('objectStart').value,
                0
            ),
            0,
            duration
        );

    element.end =
        clamp(
            number(
                $('objectEnd').value,
                duration
            ),
            element.start,
            duration
        );

    element.x =
        clamp(
            number($('objectX').value, 0),
            0,
            100
        );

    element.y =
        clamp(
            number($('objectY').value, 0),
            0,
            100
        );

    element.w =
        clamp(
            number($('objectW').value, 1),
            .5,
            100
        );

    element.h =
        clamp(
            number($('objectH').value, 1),
            .5,
            100
        );

    element.x =
        Math.min(
            element.x,
            100 - element.w
        );

    element.y =
        Math.min(
            element.y,
            100 - element.h
        );

    markDirty();
    updateSelectionInfo(element);
    renderAll();
}

/* =========================================================
 * DRAG / RESIZE
 * ======================================================= */

let drag = null;

$('objects').addEventListener(
    'pointerdown',
    event => {
        const object =
            event.target.closest('.edit-object');

        if (!object) {
            return;
        }

        const id = object.dataset.id;

        const element =
            state.project.elements.find(
                e => e.id === id
            );

        if (!element) {
            return;
        }

        if (
            event.target.classList.contains(
                'connection-point'
            )
        ) {
            event.preventDefault();
            event.stopPropagation();

            if (!state.connectMode) {
                state.connectMode = true;
            }

            handleConnectionPoint(
                element,
                event.target.dataset.point
            );

            return;
        }

        if (
            event.target.classList.contains(
                'resize-handle'
            )
        ) {
            selectElement(id);

            const rect =
                $('videoStage').getBoundingClientRect();

            drag = {
                mode: 'resize',
                id,
                startX: event.clientX,
                startY: event.clientY,
                originalW: element.w,
                originalH: element.h,
                stageW: rect.width,
                stageH: rect.height
            };

            object.setPointerCapture(
                event.pointerId
            );

            event.preventDefault();
            return;
        }

        selectElement(id);

        if (state.connectMode) {
            return;
        }

        const rect =
            $('videoStage').getBoundingClientRect();

        drag = {
            mode: 'move',
            id,
            startX: event.clientX,
            startY: event.clientY,
            originalX: element.x,
            originalY: element.y,
            stageW: rect.width,
            stageH: rect.height
        };

        object.setPointerCapture(
            event.pointerId
        );

        event.preventDefault();
    }
);

$('objects').addEventListener(
    'pointermove',
    event => {
        if (!drag) {
            return;
        }

        const element =
            state.project.elements.find(
                e => e.id === drag.id
            );

        if (!element) {
            drag = null;
            return;
        }

        const dx =
            event.clientX - drag.startX;

        const dy =
            event.clientY - drag.startY;

        if (drag.mode === 'move') {
            element.x =
                clamp(
                    drag.originalX +
                    dx / drag.stageW * 100,
                    0,
                    100 - element.w
                );

            element.y =
                clamp(
                    drag.originalY +
                    dy / drag.stageH * 100,
                    0,
                    100 - element.h
                );
        } else {
            element.w =
                clamp(
                    drag.originalW +
                    dx / drag.stageW * 100,
                    .5,
                    100 - element.x
                );

            element.h =
                clamp(
                    drag.originalH +
                    dy / drag.stageH * 100,
                    .5,
                    100 - element.y
                );
        }

        markDirty();
        renderAll();
    }
);

$('objects').addEventListener(
    'pointerup',
    () => {
        drag = null;
    }
);

$('objects').addEventListener(
    'contextmenu',
    event => {
        const object =
            event.target.closest('.edit-object');

        if (!object) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        selectElement(object.dataset.id);

        state.contextTarget = {
            type: 'element',
            id: object.dataset.id
        };

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

/* =========================================================
 * CONNECTIONS
 * ======================================================= */

function handleConnectionPoint(element, point) {
    if (!state.connectSource) {
        state.connectSource = {
            id: element.id,
            point
        };

        showMessage(
            '接続先の要素の接点をクリックしてください。',
            true
        );

        return;
    }

    if (
        state.connectSource.id === element.id
    ) {
        state.connectSource = null;
        return;
    }

    const connection = {
        id: uid('connection'),
        from: state.connectSource.id,
        fromPoint: state.connectSource.point,
        to: element.id,
        toPoint: point,
        color: '#ffffff',
        width: 2.5,
        dash: '',
        startArrow: 'none',
        endArrow: 'arrow',
        curve: 'straight',
        start: currentTime(),
        end: Math.min(
            state.project.duration || 999999,
            currentTime() + 5
        )
    };

    normalizeConnection(connection);

    state.project.connections.push(
        connection
    );

    state.connectSource = null;
    state.connectMode = false;

    markDirty();
    selectConnection(connection.id);
}

$('connectors').addEventListener(
    'click',
    event => {
        const target =
            event.target.closest(
                '.connector-hit,.connector'
            );

        if (!target) {
            return;
        }

        event.stopPropagation();

        selectConnection(
            target.dataset.id
        );
    }
);

$('connectors').addEventListener(
    'contextmenu',
    event => {
        const target =
            event.target.closest(
                '.connector-hit,.connector'
            );

        if (!target) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        selectConnection(
            target.dataset.id
        );

        state.contextTarget = {
            type: 'connection',
            id: target.dataset.id
        };

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

/* =========================================================
 * CONTEXT MENU
 * ======================================================= */

function showContextMenu(x, y) {
    const menu = $('contextMenu');

    menu.style.display = 'block';

    const w = 205;
    const h = 110;

    menu.style.left =
        Math.min(
            x,
            window.innerWidth - w - 5
        ) + 'px';

    menu.style.top =
        Math.min(
            y,
            window.innerHeight - h - 5
        ) + 'px';
}

function hideContextMenu() {
    $('contextMenu').style.display =
        'none';
}

document.addEventListener(
    'click',
    event => {
        if (
            !event.target.closest(
                '#contextMenu'
            )
        ) {
            hideContextMenu();
        }
    }
);

$('contextMenu').addEventListener(
    'click',
    event => {
        const button =
            event.target.closest('button');

        if (!button) {
            return;
        }

        const action =
            button.dataset.action;

        hideContextMenu();

        if (
            action === 'style'
        ) {
            openStyleModal();
        }

        if (
            action === 'duplicate'
        ) {
            duplicateSelected();
        }

        if (
            action === 'delete'
        ) {
            deleteSelected();
        }
    }
);

function duplicateSelected() {
    if (
        !state.selected ||
        !state.selectedType
    ) {
        return;
    }

    if (state.selectedType === 'element') {
        const source =
            state.project.elements.find(
                e => e.id === state.selected
            );

        if (!source) {
            return;
        }

        const copy =
            clone(source);

        copy.id = uid('element');
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

        state.project.elements.push(copy);

        markDirty();
        selectElement(copy.id);
    }

    if (
        state.selectedType === 'connection'
    ) {
        const source =
            state.project.connections.find(
                c => c.id === state.selected
            );

        if (!source) {
            return;
        }

        const copy =
            clone(source);

        copy.id =
            uid('connection');

        state.project.connections.push(copy);

        markDirty();
        selectConnection(copy.id);
    }
}

/* =========================================================
 * STYLE MODALS
 * ======================================================= */

function openStyleModal() {
    if (
        !state.selected ||
        !state.selectedType
    ) {
        return;
    }

    if (state.selectedType === 'element') {
        const element =
            state.project.elements.find(
                e => e.id === state.selected
            );

        if (!element) {
            return;
        }

        const style = element.style;

        $('mText').value =
            element.text || '';

        $('mColor').value =
            normalizeColor(style.color, '#ffffff');

        $('mBg').value =
            normalizeColor(
                style.background,
                '#000000'
            );

        $('mFontSize').value =
            number(style.fontSize, 24);

        $('mOpacity').value =
            number(style.opacity, 100);

        $('mBorderColor').value =
            normalizeColor(
                style.borderColor,
                '#ff3b30'
            );

        $('mBorderWidth').value =
            number(style.borderWidth, 0);

        $('mRadius').value =
            number(style.radius, 4);

        $('mWeight').value =
            String(
                number(style.fontWeight, 600)
            );

        $('objectModal').style.display =
            'flex';

        return;
    }

    if (state.selectedType === 'connection') {
        const connection =
            state.project.connections.find(
                c => c.id === state.selected
            );

        if (!connection) {
            return;
        }

        $('cFromPoint').value =
            connection.fromPoint;

        $('cToPoint').value =
            connection.toPoint;

        $('cColor').value =
            normalizeColor(
                connection.color,
                '#ffffff'
            );

        $('cWidth').value =
            connection.width;

        $('cDash').value =
            connection.dash || '';

        $('cStartArrow').value =
            connection.startArrow;

        $('cEndArrow').value =
            connection.endArrow;

        $('cCurve').value =
            connection.curve;

        $('connectionModal').style.display =
            'flex';
    }
}

function normalizeColor(value, fallback) {
    const str =
        String(value || '');

    const match =
        str.match(
            /^#([0-9a-f]{6})/i
        );

    return match
        ? '#' + match[1]
        : fallback;
}

$('applyObjectStyle').addEventListener(
    'click',
    () => {
        const element =
            state.project.elements.find(
                e => e.id === state.selected
            );

        if (!element) {
            return;
        }

        element.text =
            $('mText').value;

        element.style.color =
            $('mColor').value;

        element.style.background =
            $('mBg').value;

        element.style.fontSize =
            clamp(
                number(
                    $('mFontSize').value,
                    24
                ),
                8,
                200
            );

        element.style.opacity =
            clamp(
                number(
                    $('mOpacity').value,
                    100
                ),
                0,
                100
            );

        element.style.borderColor =
            $('mBorderColor').value;

        element.style.borderWidth =
            clamp(
                number(
                    $('mBorderWidth').value,
                    0
                ),
                0,
                30
            );

        element.style.radius =
            clamp(
                number(
                    $('mRadius').value,
                    4
                ),
                0,
                100
            );

        element.style.fontWeight =
            number(
                $('mWeight').value,
                600
            );

        $('objectModal').style.display =
            'none';

        markDirty();
        updateSelectionInfo(element);
        renderAll();
    }
);

$('applyConnectionStyle').addEventListener(
    'click',
    () => {
        const connection =
            state.project.connections.find(
                c => c.id === state.selected
            );

        if (!connection) {
            return;
        }

        connection.fromPoint =
            $('cFromPoint').value;

        connection.toPoint =
            $('cToPoint').value;

        connection.color =
            $('cColor').value;

        connection.width =
            clamp(
                number(
                    $('cWidth').value,
                    2.5
                ),
                1,
                30
            );

        connection.dash =
            $('cDash').value;

        connection.startArrow =
            $('cStartArrow').value;

        connection.endArrow =
            $('cEndArrow').value;

        connection.curve =
            $('cCurve').value;

        $('connectionModal').style.display =
            'none';

        markDirty();
        updateSelectionInfo(connection);
        renderAll();
    }
);

/* =========================================================
 * DELETE
 * ======================================================= */

function deleteSelected() {
    if (
        !state.selected ||
        !state.selectedType
    ) {
        return;
    }

    if (
        !confirm(
            '選択中の項目を削除しますか？'
        )
    ) {
        return;
    }

    if (
        state.selectedType === 'element'
    ) {
        const id =
            state.selected;

        state.project.elements =
            state.project.elements.filter(
                e => e.id !== id
            );

        state.project.connections =
            state.project.connections.filter(
                c =>
                    c.from !== id &&
                    c.to !== id
            );
    } else {
        state.project.connections =
            state.project.connections.filter(
                c => c.id !== state.selected
            );
    }

    markDirty();
    selectNone();
}

/* =========================================================
 * SAVE
 * ======================================================= */

function projectForSave() {
    const project =
        clone(state.project);

    project.version =
        APP_VERSION;

    project.name =
        $('editorProjectName').value.trim();

    if (!project.name) {
        throw new Error(
            'プロジェクト名を入力してください。'
        );
    }

    project.savedAt =
        nowISO();

    return project;
}

async function saveLocal(show = true) {
    const project =
        projectForSave();

    await idbPut(
        'projects',
        project
    );

    state.project =
        normalizeProject(project);

    markClean('ローカル保存済み');

    if (show) {
        showMessage(
            'ローカルに保存しました。',
            true
        );
    }

    return project;
}

async function saveServer() {
    if (state.saving) {
        return;
    }

    state.saving = true;

    try {
        const project =
            projectForSave();

        setEditorStatus(
            'サーバーへ保存中...'
        );

        const result =
            await api(
                '?api=save&_=' + Date.now(),
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/json'
                    },
                    body:JSON.stringify(project)
                }
            );

        project.projectId =
            result.projectId;

        project.savedAt =
            result.savedAt;

        project.version =
            APP_VERSION;

        state.project =
            normalizeProject(project);

        await idbPut(
            'projects',
            project
        );

        markClean(
            'サーバー保存済み'
        );

        showMessage(
            'サーバーへ保存しました。',
            true
        );
    } catch (error) {
        if (
            error.data?.limit ||
            error.status === 409
        ) {
            showMessage(
                'サーバー保存上限に達しました。ローカル保存へ切り替えます。'
            );

            try {
                await saveLocal(false);

                showMessage(
                    'サーバー上限のためローカルへ保存しました。',
                    true
                );
            } catch (localError) {
                showMessage(
                    localError.message
                );
            }
        } else {
            showMessage(
                error.message
            );
        }
    } finally {
        state.saving = false;
    }
}

/* =========================================================
 * JSON EXPORT / IMPORT
 * ======================================================= */

function exportProject() {
    try {
        const project =
            projectForSave();

        const payload = {
            exportedAt: nowISO(),
            version: APP_VERSION,
            project
        };

        const blob =
            new Blob(
                [JSON.stringify(
                    payload,
                    null,
                    2
                )],
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
                project.name ||
                'project'
            ).replace(
                /[\\/:*?"<>|]/g,
                '_'
            ) +
            '.json';

        a.click();

        setTimeout(
            () => URL.revokeObjectURL(url),
            1000
        );
    } catch (error) {
        showMessage(error.message);
    }
}

async function importProjectFile(file) {
    const text =
        await file.text();

    let data;

    try {
        data =
            JSON.parse(text);
    } catch {
        throw new Error(
            'JSONファイルを読み込めません。'
        );
    }

    const project =
        data.project || data;

    if (
        !project ||
        typeof project !== 'object'
    ) {
        throw new Error(
            'プロジェクトデータがありません。'
        );
    }

    project.projectId =
        uid('project');

    project.name =
        String(
            project.name ||
            '読み込んだプロジェクト'
        );

    project.videoKey =
        '';

    const video =
        await requestMissingVideo(project);

    if (!video) {
        throw new Error(
            'JSONには動画本体が含まれていないため、対応する動画を選択してください。'
        );
    }

    const videoKey =
        uid('video');

    await idbPut(
        'videos',
        {
            key:videoKey,
            name:video.name,
            type:video.type,
            size:video.size,
            savedAt:nowISO(),
            blob:video
        }
    );

    project.videoKey =
        videoKey;

    await openEditor(
        project,
        video
    );

    markDirty();

    showMessage(
        'JSONを読み込みました。',
        true
    );
}

/* =========================================================
 * PROJECT MANAGEMENT
 * ======================================================= */

async function openManage() {
    await renderManageList();
    $('manageModal').style.display =
        'flex';
}

async function renderManageList() {
    const box =
        $('manageList');

    box.innerHTML =
        '<div class="help">読み込み中...</div>';

    const server =
        await loadServerList();

    const local =
        await idbGetAll('projects');

    $('manageInfo').innerHTML = `
        サーバー保存：
        ${server.projects.length}
        /
        ${server.limit}
        件<br>
        ローカル保存：
        ${local.length} 件
    `;

    const rows = [];

    server.projects.forEach(project => {
        rows.push({
            ...project,
            storage:'server'
        });
    });

    local.forEach(project => {
        rows.push({
            ...project,
            storage:'local'
        });
    });

    if (!rows.length) {
        box.innerHTML =
            '<div class="help">保存データはありません。</div>';
        return;
    }

    box.innerHTML =
        rows.map(project => `
            <div class="project">
                <div class="project-info">
                    <div class="project-name">
                        ${escapeHtml(project.name)}
                    </div>

                    <div class="project-meta">
                        ${project.storage === 'server'
                            ? 'サーバー'
                            : 'ローカル'}
                        /
                        ${escapeHtml(
                            project.videoName || ''
                        )}
                    </div>
                </div>

                <div class="project-actions">
                    <button
                        class="small"
                        data-manage-open="${escapeHtml(project.projectId)}"
                        data-storage="${project.storage}"
                    >
                        開く
                    </button>

                    <button
                        class="small danger"
                        data-manage-delete="${escapeHtml(project.projectId)}"
                        data-storage="${project.storage}"
                    >
                        削除
                    </button>
                </div>
            </div>
        `).join('');
}

/* =========================================================
 * RECORDER
 * ======================================================= */

async function openRecorder() {
    if (
        !navigator.mediaDevices?.getDisplayMedia
    ) {
        showMessage(
            'このブラウザでは画面録画に対応していません。'
        );
        return;
    }

    $('home').style.display = 'none';
    $('recorder').style.display = 'flex';

    $('preview').srcObject = null;
    $('timer').textContent = '00:00:00';

    state.recordChunks = [];
    state.recordStream = null;
    state.recorder = null;
}

async function startRecording() {
    try {
        const wantAudio =
            $('systemAudio').checked;

        const displayStream =
            await navigator.mediaDevices.getDisplayMedia({
                video:true,
                audio:wantAudio
            });

        let stream =
            displayStream;

        if (
            $('microphone').checked &&
            navigator.mediaDevices.getUserMedia
        ) {
            const mic =
                await navigator.mediaDevices.getUserMedia({
                    audio:true
                });

            const tracks = [
                ...displayStream.getVideoTracks(),
                ...displayStream.getAudioTracks(),
                ...mic.getAudioTracks()
            ];

            stream =
                new MediaStream(tracks);
        }

        state.recordStream =
            stream;

        $('preview').srcObject =
            stream;

        const mimeTypes = [
            'video/webm;codecs=vp9,opus',
            'video/webm;codecs=vp8,opus',
            'video/webm'
        ];

        const mimeType =
            mimeTypes.find(
                type =>
                    MediaRecorder.isTypeSupported(type)
            ) || '';

        state.recordChunks = [];

        state.recorder =
            new MediaRecorder(
                stream,
                mimeType
                    ? { mimeType }
                    : undefined
            );

        state.recorder.ondataavailable =
            event => {
                if (event.data.size) {
                    state.recordChunks.push(
                        event.data
                    );
                }
            };

        state.recorder.onstop =
            finishRecording;

        state.recorder.start(
            250
        );

        state.recordStartedAt =
            Date.now();

        state.recordTimer =
            setInterval(
                updateRecordTimer,
                250
            );

        $('startRecord').disabled =
            true;

        $('pauseRecord').disabled =
            false;

        $('stopRecord').disabled =
            false;

        showMessage(
            '録画を開始しました。',
            true
        );
    } catch (error) {
        showMessage(
            '録画を開始できませんでした：' +
            error.message
        );
    }
}

function updateRecordTimer() {
    const elapsed =
        Date.now() -
        state.recordStartedAt;

    $('timer').textContent =
        formatTime(elapsed / 1000);
}

function pauseRecording() {
    if (!state.recorder) {
        return;
    }

    if (
        state.recorder.state === 'recording'
    ) {
        state.recorder.pause();
        $('pauseRecord').textContent =
            '再開';
        return;
    }

    if (
        state.recorder.state === 'paused'
    ) {
        state.recorder.resume();
        $('pauseRecord').textContent =
            '一時停止';
    }
}

function stopRecording() {
    if (!state.recorder) {
        return;
    }

    if (
        state.recorder.state !== 'inactive'
    ) {
        state.recorder.stop();
    }
}

async function finishRecording() {
    clearInterval(
        state.recordTimer
    );

    state.recordTimer = null;

    state.recordStream?.getTracks()
        .forEach(track => track.stop());

    const blob =
        new Blob(
            state.recordChunks,
            {
                type:
                    state.recorder?.mimeType ||
                    'video/webm'
            }
        );

    if (!blob.size) {
        showMessage(
            '録画データが空です。'
        );
        return;
    }

    const fileName =
        'recording-' +
        new Date()
            .toISOString()
            .replaceAll(':', '-') +
        '.webm';

    const videoKey =
        uid('video');

    await idbPut(
        'videos',
        {
            key:videoKey,
            name:fileName,
            type:blob.type,
            size:blob.size,
            savedAt:nowISO(),
            blob
        }
    );

    const project =
        createEmptyProject(
            '画面録画',
            fileName
        );

    project.videoKey =
        videoKey;

    $('recorder').style.display =
        'none';

    await openEditor(
        project,
        blob
    );

    markDirty();

    showMessage(
        '録画した動画を編集対象として読み込みました。',
        true
    );
}

/* =========================================================
 * VIDEO EVENTS
 * ======================================================= */

$('recordedVideo').addEventListener(
    'loadedmetadata',
    () => {
        updateStageSize();
        renderAll();
    }
);

$('recordedVideo').addEventListener(
    'timeupdate',
    () => {
        updateTimeReadout();
        renderObjects();
        renderConnections();
    }
);

$('recordedVideo').addEventListener(
    'seeked',
    renderAll
);

$('recordedVideo').addEventListener(
    'play',
    () => {
        renderObjects();
        renderConnections();
    }
);

window.addEventListener(
    'resize',
    () => {
        if (
            $('editor').style.display !== 'none'
        ) {
            updateStageSize();
            renderAll();
        }
    }
);

/* =========================================================
 * GENERAL EVENTS
 * ======================================================= */

$('newVideoBtn').addEventListener(
    'click',
    chooseVideo
);

$('videoFile').addEventListener(
    'change',
    () => {
        const file =
            $('videoFile').files?.[0];

        if (!file) {
            return;
        }

        if (
            !file.type.startsWith('video/')
        ) {
            showMessage(
                '動画ファイルを選択してください。'
            );
            return;
        }

        showNameModal(file);
    }
);

$('nameCancel').addEventListener(
    'click',
    () => {
        $('nameModal').style.display =
            'none';

        state.pendingVideoFile = null;
    }
);

$('nameOk').addEventListener(
    'click',
    async () => {
        const name =
            $('projectNameInput').value.trim();

        if (!name) {
            showMessage(
                'プロジェクト名を入力してください。'
            );
            return;
        }

        if (
            name.length > 120
        ) {
            showMessage(
                'プロジェクト名は120文字以内です。'
            );
            return;
        }

        const file =
            state.pendingVideoFile;

        $('nameModal').style.display =
            'none';

        state.pendingVideoFile = null;

        try {
            setStatus(
                '動画を保存しています...'
            );

            await beginVideoProject(
                name,
                file
            );

            setStatus(
                '編集モード'
            );
        } catch (error) {
            showMessage(
                error.message
            );
            $('home').style.display =
                'flex';
        }
    }
);

$('recordScreenBtn').addEventListener(
    'click',
    openRecorder
);

$('startRecord').addEventListener(
    'click',
    startRecording
);

$('pauseRecord').addEventListener(
    'click',
    pauseRecording
);

$('stopRecord').addEventListener(
    'click',
    stopRecording
);

$('recordCancel').addEventListener(
    'click',
    () => {
        state.recordStream?.getTracks()
            .forEach(track => track.stop());

        $('recorder').style.display =
            'none';

        $('home').style.display =
            'flex';

        refreshProjectList();
    }
);

$('addComment').addEventListener(
    'click',
    () => addElement('comment')
);

$('addBox').addEventListener(
    'click',
    () => addElement('box')
);

$('connectMode').addEventListener(
    'click',
    () => {
        state.connectMode =
            !state.connectMode;

        state.connectSource = null;

        $('connectMode').textContent =
            state.connectMode
                ? '接続中：接点を選択'
                : '線で接続';
    }
);

$('deleteSelected').addEventListener(
    'click',
    deleteSelected
);

$('applyObject').addEventListener(
    'click',
    applyObjectFields
);

$('playVideo').addEventListener(
    'click',
    () => {
        $('recordedVideo').play()
            .catch(() => {});
    }
);

$('pauseVideo').addEventListener(
    'click',
    () => {
        $('recordedVideo').pause();
    }
);

$('seek').addEventListener(
    'input',
    () => {
        $('recordedVideo').currentTime =
            number(
                $('seek').value
            );

        renderAll();
    }
);

$('startPoint').addEventListener(
    'click',
    () => {
        if (
            state.selectedType !== 'element'
        ) {
            return;
        }

        const element =
            state.project.elements.find(
                e => e.id === state.selected
            );

        if (!element) {
            return;
        }

        element.start =
            currentTime();

        if (
            element.end < element.start
        ) {
            element.end =
                element.start;
        }

        markDirty();
        renderAll();
    }
);

$('endPoint').addEventListener(
    'click',
    () => {
        if (
            state.selectedType !== 'element'
        ) {
            return;
        }

        const element =
            state.project.elements.find(
                e => e.id === state.selected
            );

        if (!element) {
            return;
        }

        element.end =
            Math.max(
                element.start,
                currentTime()
            );

        markDirty();
        renderAll();
    }
);

$('saveServer').addEventListener(
    'click',
    saveServer
);

$('saveLocal').addEventListener(
    'click',
    () => {
        saveLocal().catch(
            error => showMessage(
                error.message
            )
        );
    }
);

$('exportJson').addEventListener(
    'click',
    exportProject
);

$('importJson').addEventListener(
    'click',
    () => $('jsonFile').click()
);

$('jsonFile').addEventListener(
    'change',
    async () => {
        const file =
            $('jsonFile').files?.[0];

        if (!file) {
            return;
        }

        try {
            await importProjectFile(file);
        } catch (error) {
            showMessage(
                error.message
            );
        } finally {
            $('jsonFile').value = '';
        }
    }
);

$('manageBtn').addEventListener(
    'click',
    openManage
);

$('showManage').addEventListener(
    'click',
    openManage
);

$('backHome').addEventListener(
    'click',
    async () => {
        if (
            state.dirty &&
            !confirm(
                '未保存の変更があります。閉じますか？'
            )
        ) {
            return;
        }

        closeEditor();

        await refreshProjectList();
    }
);

document.querySelectorAll(
    '[data-close-modal]'
).forEach(button => {
    button.addEventListener(
        'click',
        () => {
            button.closest(
                '.modal-backdrop'
            ).style.display = 'none';
        }
    );
});

/* =========================================================
 * MANAGEMENT EVENTS
 * ======================================================= */

$('projectList').addEventListener(
    'click',
    async event => {
        const load =
            event.target.closest(
                '[data-load-project]'
            );

        const del =
            event.target.closest(
                '[data-delete-project]'
            );

        if (load) {
            try {
                await loadProject(
                    load.dataset.loadProject,
                    load.dataset.storage
                );
            } catch (error) {
                showMessage(
                    error.message
                );
            }

            return;
        }

        if (del) {
            await deleteProject(
                del.dataset.deleteProject,
                del.dataset.storage
            );
        }
    }
);

$('manageList').addEventListener(
    'click',
    async event => {
        const load =
            event.target.closest(
                '[data-manage-open]'
            );

        const del =
            event.target.closest(
                '[data-manage-delete]'
            );

        if (load) {
            $('manageModal').style.display =
                'none';

            try {
                await loadProject(
                    load.dataset.manageOpen,
                    load.dataset.storage
                );
            } catch (error) {
                showMessage(
                    error.message
                );
            }

            return;
        }

        if (del) {
            await deleteProject(
                del.dataset.manageDelete,
                del.dataset.storage
            );

            await renderManageList();
        }
    }
);

async function deleteProject(id, storage) {
    if (
        !confirm(
            'この保存データを削除しますか？'
        )
    ) {
        return;
    }

    try {
        if (storage === 'local') {
            const project =
                await idbGet(
                    'projects',
                    id
                );

            if (
                project?.videoKey
            ) {
                await idbDelete(
                    'videos',
                    project.videoKey
                );
            }

            await idbDelete(
                'projects',
                id
            );
        } else {
            await api(
                '?api=delete',
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/json'
                    },
                    body:JSON.stringify({
                        projectId:id
                    })
                }
            );
        }

        showMessage(
            '削除しました。',
            true
        );

        await refreshProjectList();
    } catch (error) {
        showMessage(
            error.message
        );
    }
}

/* =========================================================
 * STAGE BACKGROUND CLICK
 * ======================================================= */

$('videoStage').addEventListener(
    'pointerdown',
    event => {
        if (
            event.target ===
            $('recordedVideo')
        ) {
            selectNone();
        }

        if (
            event.target ===
            $('overlay')
        ) {
            selectNone();
        }
    }
);

$('videoStage').addEventListener(
    'contextmenu',
    event => {
        if (
            event.target ===
            $('recordedVideo') ||
            event.target ===
            $('overlay')
        ) {
            event.preventDefault();
            hideContextMenu();
        }
    }
);

/* =========================================================
 * EDITOR CLOSE
 * ======================================================= */

function closeEditor() {
    $('recordedVideo').pause();

    if (state.videoUrl) {
        URL.revokeObjectURL(
            state.videoUrl
        );
        state.videoUrl = '';
    }

    $('recordedVideo').removeAttribute(
        'src'
    );

    $('recordedVideo').load();

    state.project = null;
    state.videoBlob = null;
    state.selected = null;
    state.selectedType = null;
    state.connectMode = false;
    state.connectSource = null;
    state.dirty = false;

    $('editor').style.display =
        'none';

    $('home').style.display =
        'flex';

    $('contextMenu').style.display =
        'none';

    setStatus('待機中');
}

/* =========================================================
 * AUTOSAVE
 * ======================================================= */

let autosaveTimer = null;

function scheduleAutosave() {
    clearTimeout(
        autosaveTimer
    );

    autosaveTimer =
        setTimeout(
            async () => {
                if (
                    !state.project ||
                    !state.dirty ||
                    state.saving
                ) {
                    return;
                }

                try {
                    await saveLocal(false);
                    setEditorStatus(
                        '自動保存済み'
                    );
                } catch (error) {
                    console.error(
                        error
                    );
                }
            },
            1800
        );
}

const originalMarkDirty =
    markDirty;

window.markDirty =
    function() {
        originalMarkDirty();
        scheduleAutosave();
    };

/*
 * markDirtyを上書きした後も既存コードから
 * 呼び出されるよう、ローカル関数参照を
 * 使用する部分についてはイベント側で
 * scheduleAutosaveも呼ぶ。
 */
document.addEventListener(
    'input',
    event => {
        if (
            event.target.closest(
                '#editor'
            )
        ) {
            if (
                state.project
            ) {
                state.dirty = true;
                setEditorStatus(
                    '未保存'
                );
                scheduleAutosave();
            }
        }
    }
);

/* =========================================================
 * KEYBOARD
 * ======================================================= */

document.addEventListener(
    'keydown',
    event => {
        if (
            event.key === 'Escape'
        ) {
            hideContextMenu();

            document
                .querySelectorAll(
                    '.modal-backdrop'
                )
                .forEach(modal => {
                    modal.style.display =
                        'none';
                });

            return;
        }

        if (
            event.key === 'Delete' &&
            state.selected &&
            !['INPUT','TEXTAREA','SELECT']
                .includes(
                    document.activeElement?.tagName
                )
        ) {
            deleteSelected();
        }

        if (
            (event.ctrlKey ||
             event.metaKey) &&
            event.key.toLowerCase() === 's'
        ) {
            event.preventDefault();

            saveServer().catch(
                error =>
                    showMessage(
                        error.message
                    )
            );
        }
    }
);

/* =========================================================
 * INIT
 * ======================================================= */

async function init() {
    try {
        await openDB();

        /*
         * PHPからJavaScriptへ渡したAPP_VERSIONの
         * 確認。ここでundefinedなら実装ミス。
         */
        if (
            typeof APP_VERSION === 'undefined'
        ) {
            throw new Error(
                'APP_VERSIONの初期化に失敗しました。'
            );
        }

        const status =
            await api(
                '?api=status&_=' + Date.now()
            );

        setStatus(
            `待機中 / v${APP_VERSION} / ` +
            `サーバー ${status.serverCount}/${status.serverLimit}`
        );

        await refreshProjectList();

    } catch (error) {
        console.error(error);

        setStatus(
            '初期化エラー'
        );

        showMessage(
            '初期化できませんでした：' +
            error.message
        );
    }
}

init();
</script>

</body>
</html>

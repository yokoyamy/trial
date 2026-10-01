<?php
declare(strict_types=1);

/*
 * 画面録画・動画上直接編集ツール
 * Apache + PHP 7.4+
 *
 * サーバー保存:
 *   ./data/projects/*.json
 *
 * 編集対象:
 *   ユーザーが指定した「動画名」
 *
 * 保存上限:
 *   MAX_PROJECTS       = 最大保存件数
 *   MAX_STORAGE_BYTES  = JSON合計最大容量
 *
 * 上限を超えた場合:
 *   サーバー保存せず、ブラウザ側からJSONダウンロードを案内。
 */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

const MAX_PROJECTS = 100;
const MAX_STORAGE_BYTES = 50 * 1024 * 1024; // 50MB

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
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

function projectKey(string $name): string
{
    return hash('sha256', trim($name));
}

function projectPath(string $name): string
{
    return PROJECT_DIR . DIRECTORY_SEPARATOR . projectKey($name) . '.json';
}

function validVideoName(string $name): bool
{
    $name = trim($name);
    return $name !== '' && mb_strlen($name) <= 120;
}

function readProject(string $name): ?array
{
    $path = projectPath($name);

    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);

    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function storageInfo(): array
{
    $count = 0;
    $bytes = 0;

    if (is_dir(PROJECT_DIR)) {
        foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (is_file($file)) {
                $count++;
                $bytes += (int)filesize($file);
            }
        }
    }

    return [
        'count' => $count,
        'bytes' => $bytes,
        'maxCount' => MAX_PROJECTS,
        'maxBytes' => MAX_STORAGE_BYTES
    ];
}

function canStoreProject(string $name, int $newBytes): array
{
    $info = storageInfo();
    $path = projectPath($name);
    $existingBytes = is_file($path) ? (int)filesize($path) : 0;
    $newCount = $info['count'] + (is_file($path) ? 0 : 1);
    $newTotal = $info['bytes'] - $existingBytes + $newBytes;

    return [
        'ok' => $newCount <= MAX_PROJECTS && $newTotal <= MAX_STORAGE_BYTES,
        'count' => $newCount,
        'bytes' => $newTotal,
        'maxCount' => MAX_PROJECTS,
        'maxBytes' => MAX_STORAGE_BYTES
    ];
}

/* =========================================================
 * PHP API
 * ======================================================= */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    if ($api === 'status') {
        $info = storageInfo();

        jsonResponse([
            'ok' => true,
            'serverWritable' => ensureProjectDir(),
            'storage' => $info,
            'php' => PHP_VERSION
        ]);
    }

    if ($api === 'list') {
        if (!ensureProjectDir()) {
            jsonResponse([
                'ok' => false,
                'message' => '保存ディレクトリを利用できません。'
            ], 500);
        }

        $projects = [];

        foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $raw = @file_get_contents($file);
            $data = json_decode($raw ?: '', true);

            if (!is_array($data)) {
                continue;
            }

            $projects[] = [
                'videoName' => (string)($data['videoName'] ?? ''),
                'savedAt' => (string)($data['savedAt'] ?? ''),
                'size' => (int)filesize($file)
            ];
        }

        usort(
            $projects,
            static function ($a, $b) {
                return strcmp($b['savedAt'], $a['savedAt']);
            }
        );

        jsonResponse([
            'ok' => true,
            'projects' => $projects,
            'storage' => storageInfo()
        ]);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        if (!ensureProjectDir()) {
            jsonResponse([
                'ok' => false,
                'message' => 'data/projects に書き込めません。Apache/PHPの権限を確認してください。'
            ], 500);
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);

        if (!is_array($payload)) {
            jsonResponse([
                'ok' => false,
                'message' => 'JSONが不正です。'
            ], 400);
        }

        $videoName = trim((string)($payload['videoName'] ?? ''));

        if (!validVideoName($videoName)) {
            jsonResponse([
                'ok' => false,
                'message' => '動画名を入力してください。120文字以内で指定してください。'
            ], 400);
        }

        $payload['version'] = 3;
        $payload['videoName'] = $videoName;
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
                'message' => 'JSON化に失敗しました。'
            ], 500);
        }

        $size = strlen($json);
        $capacity = canStoreProject($videoName, $size);

        if (!$capacity['ok']) {
            jsonResponse([
                'ok' => false,
                'reason' => 'limit',
                'message' =>
                    'サーバー保存上限を超えるため保存できません。',
                'storage' => $capacity,
                'fallback' => 'local'
            ], 507);
        }

        $path = projectPath($videoName);

        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            jsonResponse([
                'ok' => false,
                'message' => '編集データを保存できませんでした。'
            ], 500);
        }

        jsonResponse([
            'ok' => true,
            'videoName' => $videoName,
            'savedAt' => $payload['savedAt'],
            'storage' => storageInfo()
        ]);
    }

    if ($api === 'load') {
        $videoName = trim((string)($_GET['name'] ?? ''));

        if (!validVideoName($videoName)) {
            jsonResponse([
                'ok' => false,
                'message' => '動画名が不正です。'
            ], 400);
        }

        $project = readProject($videoName);

        if (!$project) {
            jsonResponse([
                'ok' => false,
                'message' => '指定した動画名の編集データが見つかりません。'
            ], 404);
        }

        jsonResponse([
            'ok' => true,
            'project' => $project
        ]);
    }

    if ($api === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);
        $videoName = trim((string)($payload['videoName'] ?? ''));

        if (!validVideoName($videoName)) {
            jsonResponse([
                'ok' => false,
                'message' => '動画名が不正です。'
            ], 400);
        }

        $path = projectPath($videoName);

        if (is_file($path) && !@unlink($path)) {
            jsonResponse([
                'ok' => false,
                'message' => '削除できませんでした。'
            ], 500);
        }

        jsonResponse([
            'ok' => true,
            'storage' => storageInfo()
        ]);
    }

    jsonResponse([
        'ok' => false,
        'message' => 'Unknown API'
    ], 404);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画録画・動画上直接編集</title>

<style>
:root{
    --bg:#0f1115;
    --panel:#1a1d22;
    --panel2:#24282f;
    --border:#3d434d;
    --text:#f5f7fa;
    --muted:#9ba3ad;
    --blue:#1677d2;
    --green:#21834a;
    --red:#a83232;
    --yellow:#d6a529;
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

body{
    overflow:hidden;
}

button,input,textarea,select{
    font:inherit;
}

button{
    color:#fff;
    background:#30353d;
    border:1px solid #4c535d;
    border-radius:6px;
    padding:8px 11px;
    cursor:pointer;
}

button:hover:not(:disabled){
    background:#424954;
}

button:disabled{
    opacity:.45;
    cursor:not-allowed;
}

button.primary{background:var(--blue)}
button.success{background:var(--green)}
button.danger{background:var(--red)}

input,textarea,select{
    width:100%;
    color:#fff;
    background:#24282e;
    border:1px solid #505761;
    border-radius:5px;
    padding:7px;
}

input[type=color]{
    height:38px;
    padding:3px;
}

input[type=checkbox]{
    width:auto;
}

header{
    height:50px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:0 14px;
    background:#191c20;
    border-bottom:1px solid #30353c;
}

header h1{
    margin:0;
    font-size:16px;
}

#status{
    color:#b8c0ca;
    font-size:12px;
}

.message{
    position:fixed;
    left:50%;
    top:60px;
    z-index:2000;
    transform:translateX(-50%);
    display:none;
    max-width:90vw;
    padding:10px 16px;
    border-radius:7px;
    background:#963737;
    box-shadow:0 10px 35px #000b;
    white-space:pre-wrap;
}

.message.ok{
    background:#267446;
}

.recorder{
    height:calc(100vh - 106px);
    display:flex;
    align-items:center;
    justify-content:center;
    background:#000;
}

#preview{
    max-width:100%;
    max-height:100%;
    display:none;
}

.placeholder{
    max-width:700px;
    padding:35px;
    text-align:center;
    color:#858d98;
}

.toolbar{
    height:56px;
    display:flex;
    align-items:center;
    gap:8px;
    padding:7px 12px;
    overflow:auto;
    background:#191c20;
    border-top:1px solid #30353c;
}

.toolbar label{
    white-space:nowrap;
    font-size:13px;
}

.timer{
    min-width:82px;
    font-variant-numeric:tabular-nums;
}

#editor{
    position:fixed;
    inset:0;
    z-index:100;
    display:none;
    flex-direction:column;
    background:#111;
}

.editor-main{
    flex:1;
    min-height:0;
    display:grid;
    grid-template-columns:minmax(0,1fr) 370px;
}

.video-area{
    min-width:0;
    min-height:0;
    display:flex;
    align-items:center;
    justify-content:center;
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
    pointer-events:none;
    overflow:visible;
}

.edit-object{
    position:absolute;
    pointer-events:auto;
    cursor:move;
    touch-action:none;
    user-select:none;
    overflow:visible;
}

.edit-object.selected{
    outline:2px solid #fff;
    outline-offset:2px;
}

.edit-object.comment{
    min-width:35px;
    min-height:22px;
    display:flex;
    align-items:center;
    white-space:pre-wrap;
    word-break:break-word;
    overflow:hidden;
    box-shadow:0 2px 12px #0007;
}

.edit-object.box{
    background:transparent;
}

.object-handle{
    position:absolute;
    width:12px;
    height:12px;
    right:-7px;
    bottom:-7px;
    border-radius:50%;
    background:#fff;
    border:1px solid #222;
    cursor:nwse-resize;
}

.connection-point{
    position:absolute;
    width:12px;
    height:12px;
    margin:-6px 0 0 -6px;
    border:2px solid #fff;
    border-radius:50%;
    background:#1677d2;
    display:none;
    z-index:20;
    cursor:crosshair;
}

.edit-object.selected .connection-point{
    display:block;
}

.connection-point[data-point=n]{left:50%;top:0}
.connection-point[data-point=ne]{left:100%;top:0}
.connection-point[data-point=e]{left:100%;top:50%}
.connection-point[data-point=se]{left:100%;top:100%}
.connection-point[data-point=s]{left:50%;top:100%}
.connection-point[data-point=sw]{left:0;top:100%}
.connection-point[data-point=w]{left:0;top:50%}
.connection-point[data-point=nw]{left:0;top:0}

.connector{
    fill:none;
    pointer-events:stroke;
    cursor:pointer;
}

.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:15;
    pointer-events:stroke;
    cursor:pointer;
}

aside{
    min-width:0;
    overflow:auto;
    padding:12px;
    background:#1a1d22;
    border-left:1px solid #383e47;
}

section{
    margin-bottom:14px;
    padding-bottom:14px;
    border-bottom:1px solid #383e47;
}

section h2{
    margin:0 0 9px;
    font-size:15px;
}

.help{
    color:var(--muted);
    font-size:12px;
    line-height:1.55;
}

.field{
    display:block;
    margin:7px 0;
    color:#c9ced5;
    font-size:12px;
}

.field input,
.field textarea,
.field select{
    margin-top:4px;
}

.field textarea{
    min-height:70px;
    resize:vertical;
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 8px;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:5px;
    margin-top:7px;
}

.actions button{
    padding:5px 7px;
    font-size:11px;
}

.list-item{
    margin:6px 0;
    padding:8px;
    border:1px solid #454b54;
    border-radius:6px;
    font-size:12px;
}

.list-item.selected{
    border-color:#2c8ae5;
}

.hidden{
    display:none!important;
}

#timeline{
    padding:7px 12px;
    background:#191c20;
    border-top:1px solid #383e47;
}

#seek{
    width:100%;
}

.tracks{
    display:grid;
    grid-template-columns:60px 1fr;
    gap:5px 8px;
    align-items:center;
    color:#999;
    font-size:11px;
}

.track{
    position:relative;
    height:14px;
    overflow:hidden;
    background:#2a2e35;
    border-radius:3px;
}

.track span{
    position:absolute;
    top:2px;
    height:10px;
    min-width:3px;
    border-radius:2px;
    cursor:pointer;
}

.track.comment span{background:#42a5f5}
.track.box span{background:#ef5350}
.track.connector span{background:#b17cff}
.track.skip span{background:#dca52b}

.footer{
    min-height:48px;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-wrap:wrap;
    gap:7px;
    padding:7px;
    background:#191c20;
    border-top:1px solid #383e47;
}

.time-readout{
    min-width:105px;
    text-align:center;
    font-variant-numeric:tabular-nums;
}

#contextMenu{
    position:fixed;
    z-index:500;
    display:none;
    min-width:210px;
    padding:5px;
    border:1px solid #565c65;
    border-radius:7px;
    background:#282c32;
    box-shadow:0 10px 35px #000c;
}

#contextMenu button{
    width:100%;
    display:block;
    border:0;
    background:transparent;
    text-align:left;
}

#contextMenu button:hover{
    background:#3b414a;
}

#formatPanel{
    position:fixed;
    z-index:600;
    display:none;
    width:340px;
    max-width:calc(100vw - 20px);
    max-height:calc(100vh - 20px);
    overflow:auto;
    padding:14px;
    border:1px solid #555c66;
    border-radius:9px;
    background:#20242a;
    box-shadow:0 15px 50px #000d;
}

#formatPanel h3{
    margin:0 0 10px;
    font-size:15px;
}

.format-actions{
    display:flex;
    justify-content:flex-end;
    gap:6px;
    margin-top:12px;
}

.format-preview{
    margin-bottom:10px;
    padding:12px;
    text-align:center;
    background:#15181c;
    border:1px solid #3f454e;
    border-radius:6px;
}

.checkbox-row{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:12px;
}

.name-box{
    padding:9px;
    background:#22262c;
    border:1px solid #424850;
    border-radius:6px;
}

.name-box strong{
    display:block;
    margin-bottom:6px;
    font-size:13px;
}

.storage{
    margin-top:8px;
    padding:8px;
    color:#adb5bf;
    background:#15181c;
    border-radius:6px;
    font-size:11px;
    line-height:1.5;
}

@media(max-width:900px){
    .editor-main{
        display:flex;
        flex-direction:column;
    }

    .video-area{
        min-height:280px;
        flex:1;
    }

    aside{
        max-height:42vh;
        border-left:0;
        border-top:1px solid #383e47;
    }
}
</style>
</head>

<body>

<header>
    <h1>画面録画・動画上直接編集</h1>
    <span id="status">待機中</span>
</header>

<div id="message" class="message"></div>

<div class="recorder">
    <video id="preview" autoplay muted playsinline></video>

    <div id="placeholder" class="placeholder">
        <h2>画面録画・動画編集</h2>
        <p>
            「録画開始」で画面を録画するか、
            既存の動画ファイルを開いて編集できます。
        </p>
    </div>
</div>

<div class="toolbar">

    <label>
        <input id="systemAudio" type="checkbox">
        画面音声
    </label>

    <label>
        <input id="microphone" type="checkbox">
        マイク
    </label>

    <span id="timer" class="timer">00:00:00</span>

    <button id="start" class="primary">
        録画開始
    </button>

    <button id="pause" disabled>
        一時停止
    </button>

    <button id="stop" class="danger" disabled>
        停止
    </button>

    <button id="reset" disabled>
        リセット
    </button>

    <button id="openFile">
        動画ファイルを開く
    </button>

    <button id="openEditor" disabled>
        編集画面を開く
    </button>

    <input
        id="videoFile"
        type="file"
        accept="video/*"
        hidden
    >

</div>

<div id="editor">

    <div class="editor-main">

        <div class="video-area" id="videoArea">

            <div id="videoStage">

                <video
                    id="recordedVideo"
                    controls
                    playsinline
                ></video>

                <div id="overlay">

                    <svg
                        id="connectors"
                        viewBox="0 0 100 100"
                        preserveAspectRatio="none">
                    </svg>

                    <div id="objects"></div>

                </div>

            </div>

        </div>

        <aside>

            <section>

                <h2>編集対象動画</h2>

                <div class="name-box">

                    <strong>動画名</strong>

                    <input
                        id="videoName"
                        type="text"
                        maxlength="120"
                        placeholder="例：商品紹介_01"
                    >

                    <div class="actions">

                        <button
                            id="setTarget"
                            class="primary">
                            この動画を編集対象にする
                        </button>

                        <button id="newTarget">
                            新しい動画名
                        </button>

                    </div>

                    <div
                        id="targetName"
                        class="help"
                        style="margin-top:7px">
                        編集対象：未設定
                    </div>

                </div>

                <div class="actions">

                    <button id="serverSave"
                            class="success">
                        サーバー保存
                    </button>

                    <button id="serverLoad">
                        サーバー読込
                    </button>

                    <button id="listProjects">
                        保存一覧
                    </button>

                    <button id="localSave">
                        ローカルJSON保存
                    </button>

                    <button id="localLoad">
                        ローカルJSON読込
                    </button>

                    <input
                        id="projectFile"
                        type="file"
                        accept=".json,application/json"
                        hidden>

                </div>

                <div
                    id="storageInfo"
                    class="storage">
                    保存状況を取得中...
                </div>

            </section>

            <section>

                <h2>動画上編集</h2>

                <div class="help">
                    要素は動画画面上に直接配置されます。
                    左クリックで選択、ドラッグで移動、
                    右下の丸でサイズ変更できます。
                    コメントはダブルクリックで直接編集できます。
                    要素・線を右クリックすると書式変更できます。
                </div>

                <div class="actions">

                    <button
                        id="addComment"
                        class="primary">
                        コメント追加
                    </button>

                    <button
                        id="addBox"
                        class="primary">
                        強調枠追加
                    </button>

                    <button id="connect">
                        線でつなぐ
                    </button>

                    <button
                        id="deleteSelected"
                        class="danger">
                        選択削除
                    </button>

                </div>

            </section>

            <section>

                <h2>選択中</h2>

                <div
                    id="selectionHint"
                    class="help">
                    動画上の要素または線を選択してください。
                </div>

                <div id="objectFields" class="hidden">

                    <label
                        id="textField"
                        class="field">
                        コメント内容
                        <textarea id="objectText"></textarea>
                    </label>

                    <div class="grid">

                        <label class="field">
                            開始
                            <input
                                id="objectStart"
                                type="number"
                                min="0"
                                step=".1">
                        </label>

                        <label class="field">
                            終了
                            <input
                                id="objectEnd"
                                type="number"
                                min="0"
                                step=".1">
                        </label>

                        <label class="field">
                            左 %
                            <input
                                id="objectX"
                                type="number"
                                min="0"
                                max="100"
                                step=".1">
                        </label>

                        <label class="field">
                            上 %
                            <input
                                id="objectY"
                                type="number"
                                min="0"
                                max="100"
                                step=".1">
                        </label>

                        <label class="field">
                            幅 %
                            <input
                                id="objectW"
                                type="number"
                                min="1"
                                max="100"
                                step=".1">
                        </label>

                        <label class="field">
                            高さ %
                            <input
                                id="objectH"
                                type="number"
                                min="1"
                                max="100"
                                step=".1">
                        </label>

                    </div>

                    <button
                        id="applyObject"
                        class="primary">
                        変更を反映
                    </button>

                </div>

            </section>

            <section>

                <h2>スキップ</h2>

                <div class="grid">

                    <label class="field">
                        開始
                        <input
                            id="skipStart"
                            type="number"
                            min="0"
                            step=".1"
                            value="0">
                    </label>

                    <label class="field">
                        終了
                        <input
                            id="skipEnd"
                            type="number"
                            min="0"
                            step=".1"
                            value="5">
                    </label>

                </div>

                <div class="actions">

                    <button id="skipFromCurrent">
                        現在位置を入力
                    </button>

                    <button
                        id="addSkip"
                        class="primary">
                        範囲追加
                    </button>

                </div>

                <div id="skipList"></div>

            </section>

            <section>

                <h2>保存済み動画</h2>

                <div
                    id="projectList"
                    class="help">
                    「保存一覧」で取得します。
                </div>

            </section>

            <section>

                <h2>要素・線</h2>

                <div id="objectList"></div>

            </section>

            <section>

                <h2>編集データ</h2>

                <button
                    id="clearEdits"
                    class="danger">
                    編集内容を全削除
                </button>

            </section>

        </aside>

    </div>

    <div id="timeline">

        <input
            id="seek"
            type="range"
            min="0"
            max="0"
            step=".01"
            value="0">

        <div class="tracks">

            <span>コメント</span>
            <div
                class="track comment"
                id="commentTrack">
            </div>

            <span>強調枠</span>
            <div
                class="track box"
                id="boxTrack">
            </div>

            <span>接続線</span>
            <div
                class="track connector"
                id="connectorTrack">
            </div>

            <span>スキップ</span>
            <div
                class="track skip"
                id="skipTrack">
            </div>

        </div>

    </div>

    <div class="footer">

        <span
            id="timeReadout"
            class="time-readout">
            00:00 / 00:00
        </span>

        <button id="back5">
            5秒戻る
        </button>

        <button id="forward5">
            5秒進む
        </button>

        <button id="downloadWebm">
            WebMダウンロード
        </button>

        <button
            id="downloadMp4"
            class="success">
            MP4変換
        </button>

        <button id="closeEditor">
            閉じる
        </button>

    </div>

</div>

<div id="contextMenu">

    <button data-action="edit">
        編集
    </button>

    <button data-action="format">
        書式変更
    </button>

    <button data-action="duplicate">
        複製
    </button>

    <button data-action="connect">
        線でつなぐ
    </button>

    <button data-action="delete">
        削除
    </button>

    <button data-action="comment">
        コメント追加
    </button>

    <button data-action="box">
        強調枠追加
    </button>

</div>

<div id="formatPanel">

    <h3 id="formatTitle">
        書式変更
    </h3>

    <div id="formatObject">

        <div
            id="formatPreview"
            class="format-preview">
            プレビュー
        </div>

        <label class="field">
            文字色
            <input
                id="fmtColor"
                type="color"
                value="#ffffff">
        </label>

        <label class="field">
            背景色
            <input
                id="fmtBackground"
                type="color"
                value="#111111">
        </label>

        <label class="field">
            フォントサイズ
            <input
                id="fmtFontSize"
                type="number"
                min="6"
                max="200"
                value="18">
        </label>

        <label class="field">
            フォント
            <select id="fmtFontFamily">
                <option value="system-ui">
                    システム標準
                </option>
                <option value="sans-serif">
                    Sans Serif
                </option>
                <option value="serif">
                    Serif
                </option>
                <option value="monospace">
                    Monospace
                </option>
                <option value="'Noto Sans JP',sans-serif">
                    Noto Sans JP
                </option>
            </select>
        </label>

        <div class="checkbox-row">

            <label>
                <input
                    id="fmtBold"
                    type="checkbox">
                太字
            </label>

            <label>
                <input
                    id="fmtItalic"
                    type="checkbox">
                斜体
            </label>

        </div>

        <label class="field">
            背景透明度
            <input
                id="fmtBgOpacity"
                type="range"
                min="0"
                max="1"
                step=".01"
                value=".85">
        </label>

        <label class="field">
            枠線色
            <input
                id="fmtBorderColor"
                type="color"
                value="#ffffff">
        </label>

        <label class="field">
            枠線幅
            <input
                id="fmtBorderWidth"
                type="number"
                min="0"
                max="30"
                value="1">
        </label>

        <label class="field">
            角丸
            <input
                id="fmtRadius"
                type="number"
                min="0"
                max="100"
                value="6">
        </label>

        <label class="field">
            内側余白
            <input
                id="fmtPadding"
                type="number"
                min="0"
                max="100"
                value="8">
        </label>

    </div>

    <div id="formatConnector" class="hidden">

        <label class="field">
            線色
            <input
                id="lineColor"
                type="color"
                value="#ffffff">
        </label>

        <label class="field">
            線幅
            <input
                id="lineWidth"
                type="number"
                min="1"
                max="30"
                value="3">
        </label>

        <label class="field">
            線種
            <select id="lineDash">
                <option value="solid">実線</option>
                <option value="dashed">破線</option>
                <option value="dotted">点線</option>
            </select>
        </label>

        <label class="field">
            始点
            <select id="lineStartPoint">
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
            終点
            <select id="lineEndPoint">
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
            始点マーカー
            <select id="lineStartArrow">
                <option value="none">なし</option>
                <option value="arrow">矢印</option>
                <option value="circle">丸</option>
            </select>
        </label>

        <label class="field">
            終点マーカー
            <select id="lineEndArrow">
                <option value="arrow">矢印</option>
                <option value="none">なし</option>
                <option value="circle">丸</option>
            </select>
        </label>

    </div>

    <div class="format-actions">

        <button id="formatCancel">
            キャンセル
        </button>

        <button
            id="formatApply"
            class="primary">
            適用
        </button>

    </div>

</div>

<script>
"use strict";

const $ = id => document.getElementById(id);

const video = $("recordedVideo");
const stage = $("videoStage");
const objectsEl = $("objects");
const connectorsEl = $("connectors");

const API = location.pathname;
const LOCAL_PREFIX = "video-editor-project:";
const GLOBAL_KEY = "video-editor-project-index";

let mediaStream = null;
let recorder = null;
let chunks = [];
let recordedBlob = null;
let recordedUrl = null;
let currentVideoUrl = null;
let timerStarted = 0;
let timerInterval = null;
let recordingPaused = false;

const state = {
    videoName: "",
    targetName: "",
    items: [],
    connectors: [],
    skips: [],
    selected: null,
    connectMode: false,
    connectFrom: null,
    formatTarget: null,
    contextPoint: {x:10,y:10}
};

function uid(){
    return (
        Date.now().toString(36) +
        Math.random().toString(36).slice(2,8)
    );
}

function clamp(value,min,max){
    return Math.max(min,Math.min(max,value));
}

function notify(message,ok=false){
    const el=$("message");
    el.textContent=message;
    el.className="message"+(ok?" ok":"");
    el.style.display="block";

    clearTimeout(notify.timer);

    notify.timer=setTimeout(()=>{
        el.style.display="none";
    },3500);
}

function setStatus(text){
    $("status").textContent=text;
}

function formatTime(value){
    value=Number(value)||0;

    const h=Math.floor(value/3600);
    const m=Math.floor((value%3600)/60);
    const s=Math.floor(value%60);

    return (
        String(h).padStart(2,"0")+":"+
        String(m).padStart(2,"0")+":"+
        String(s).padStart(2,"0")
    );
}

function duration(){
    return Number(video.duration)||0;
}

function saveLocal(){
    if(!state.videoName){
        return;
    }

    try{
        localStorage.setItem(
            LOCAL_PREFIX+state.videoName,
            JSON.stringify(projectData())
        );
    }catch(error){
        console.warn(error);
    }
}

function projectData(){
    return {
        version:3,
        videoName:state.videoName,
        targetName:state.targetName,
        items:structuredClone(state.items),
        connectors:structuredClone(state.connectors),
        skips:structuredClone(state.skips)
    };
}

function validProject(data){
    if(!data || typeof data!=="object"){
        throw new Error("保存データが不正です。");
    }

    if(typeof data.videoName!=="string" || !data.videoName.trim()){
        throw new Error("動画名がありません。");
    }

    if(!Array.isArray(data.items)){
        throw new Error("要素データが不正です。");
    }

    if(!Array.isArray(data.connectors)){
        throw new Error("線データが不正です。");
    }

    if(!Array.isArray(data.skips)){
        throw new Error("スキップデータが不正です。");
    }
}

function applyProject(data){
    validProject(data);

    state.videoName=data.videoName.trim();
    state.targetName=data.targetName || state.videoName;
    state.items=structuredClone(data.items);
    state.connectors=structuredClone(data.connectors);
    state.skips=structuredClone(data.skips);

    state.selected=null;
    state.connectMode=false;
    state.connectFrom=null;

    $("videoName").value=state.videoName;

    updateTargetLabel();
    render();
    saveLocal();
}

function updateTargetLabel(){
    $("targetName").textContent =
        "編集対象："+
        (state.targetName || "未設定");
}

function makeDefaultStyle(type){
    if(type==="comment"){
        return {
            color:"#ffffff",
            background:"#111111",
            bgOpacity:.86,
            fontSize:18,
            fontFamily:"system-ui",
            bold:false,
            italic:false,
            borderColor:"#ffffff",
            borderWidth:1,
            radius:6,
            padding:8
        };
    }

    return {
        color:"#ffffff",
        background:"#111111",
        bgOpacity:0,
        fontSize:18,
        fontFamily:"system-ui",
        bold:false,
        italic:false,
        borderColor:"#ff453a",
        borderWidth:3,
        radius:0,
        padding:0
    };
}

function addItem(type,position={x:10,y:10}){
    const d=duration();
    const start=Math.min(
        Number(video.currentTime)||0,
        Math.max(0,d-.1)
    );

    const end=Math.min(
        d||start+3,
        start+3
    );

    if(end<=start){
        notify("動画の終了位置には要素を追加できません。");
        return;
    }

    const item={
        id:uid(),
        type:type,
        start:start,
        end:end,
        x:clamp(Number(position.x)||10,0,90),
        y:clamp(Number(position.y)||10,0,90),
        w:type==="comment"?28:25,
        h:type==="comment"?13:20,
        text:type==="comment"?"コメント":"",
        style:makeDefaultStyle(type)
    };

    state.items.push(item);
    state.selected=item.id;

    video.pause();

    render();
    saveLocal();
}

function findSelection(){
    return (
        state.items.find(x=>x.id===state.selected) ||
        state.connectors.find(x=>x.id===state.selected) ||
        null
    );
}

function rgba(hex,opacity){
    hex=String(hex||"#000000").replace("#","");

    if(hex.length===3){
        hex=hex.split("").map(x=>x+x).join("");
    }

    const n=parseInt(hex,16);

    if(Number.isNaN(n)){
        return `rgba(0,0,0,${opacity})`;
    }

    return `rgba(${(n>>16)&255},${(n>>8)&255},${n&255},${opacity})`;
}

function objectVisible(item,t){
    return (
        t>=Number(item.start) &&
        t<Number(item.end)
    );
}

function pointPosition(item,point){
    const x=Number(item.x)||0;
    const y=Number(item.y)||0;
    const w=Number(item.w)||1;
    const h=Number(item.h)||1;

    const points={
        n:[x+w/2,y],
        ne:[x+w,y],
        e:[x+w,y+h/2],
        se:[x+w,y+h],
        s:[x+w/2,y+h],
        sw:[x,y+h],
        w:[x,y+h/2],
        nw:[x,y]
    };

    return points[point]||points.e;
}

function dashArray(type,width){
    if(type==="dashed"){
        return `${width*4} ${width*3}`;
    }

    if(type==="dotted"){
        return `1 ${width*3}`;
    }

    return "none";
}

function ensureDefs(){
    let defs=connectorsEl.querySelector("defs");

    if(!defs){
        defs=document.createElementNS(
            "http://www.w3.org/2000/svg",
            "defs"
        );

        connectorsEl.appendChild(defs);
    }

    defs.innerHTML=`
        <marker id="arrowMarker"
                markerWidth="8"
                markerHeight="8"
                refX="7"
                refY="4"
                orient="auto"
                markerUnits="strokeWidth">
            <path d="M0,0 L8,4 L0,8 Z"
                  fill="context-stroke"/>
        </marker>

        <marker id="circleMarker"
                markerWidth="8"
                markerHeight="8"
                refX="4"
                refY="4"
                orient="auto"
                markerUnits="strokeWidth">
            <circle cx="4"
                    cy="4"
                    r="3"
                    fill="context-stroke"/>
        </marker>
    `;
}

function marker(type){
    return type==="circle"
        ? "url(#circleMarker)"
        : "url(#arrowMarker)";
}

function drawConnectors(){
    connectorsEl.replaceChildren();
    ensureDefs();

    const t=video.currentTime||0;

    state.connectors.forEach(line=>{
        if(t<line.start || t>=line.end){
            return;
        }

        const a=state.items.find(x=>x.id===line.from);
        const b=state.items.find(x=>x.id===line.to);

        if(!a || !b){
            return;
        }

        if(
            !objectVisible(a,t) ||
            !objectVisible(b,t)
        ){
            return;
        }

        const p1=pointPosition(
            a,
            line.fromPoint||"e"
        );

        const p2=pointPosition(
            b,
            line.toPoint||"w"
        );

        const style=line.style||{};
        const color=style.color||"#fff";
        const width=Number(style.width)||3;

        const hit=document.createElementNS(
            "http://www.w3.org/2000/svg",
            "line"
        );

        hit.setAttribute("x1",p1[0]);
        hit.setAttribute("y1",p1[1]);
        hit.setAttribute("x2",p2[0]);
        hit.setAttribute("y2",p2[1]);

        hit.classList.add("connector-hit");

        hit.addEventListener("click",e=>{
            e.stopPropagation();
            state.selected=line.id;
            render();
        });

        hit.addEventListener("contextmenu",e=>{
            e.preventDefault();
            e.stopPropagation();

            state.selected=line.id;

            showContextMenu(
                e.clientX,
                e.clientY,
                line,
                "connector"
            );
        });

        connectorsEl.appendChild(hit);

        const node=document.createElementNS(
            "http://www.w3.org/2000/svg",
            "line"
        );

        node.setAttribute("x1",p1[0]);
        node.setAttribute("y1",p1[1]);
        node.setAttribute("x2",p2[0]);
        node.setAttribute("y2",p2[1]);

        node.setAttribute("stroke",color);
        node.setAttribute("stroke-width",width);
        node.setAttribute(
            "stroke-dasharray",
            dashArray(style.dash||"solid",width)
        );

        if(style.startArrow && style.startArrow!=="none"){
            node.setAttribute(
                "marker-start",
                marker(style.startArrow)
            );
        }

        if(style.endArrow && style.endArrow!=="none"){
            node.setAttribute(
                "marker-end",
                marker(style.endArrow)
            );
        }

        node.classList.add("connector");

        if(state.selected===line.id){
            node.setAttribute(
                "stroke-width",
                width+2
            );
            node.setAttribute(
                "stroke",
                "#ffd54f"
            );
        }

        node.addEventListener("click",e=>{
            e.stopPropagation();
            state.selected=line.id;
            render();
        });

        node.addEventListener("contextmenu",e=>{
            e.preventDefault();
            e.stopPropagation();

            state.selected=line.id;

            showContextMenu(
                e.clientX,
                e.clientY,
                line,
                "connector"
            );
        });

        connectorsEl.appendChild(node);
    });
}

function applyObjectStyle(el,item){
    const s=item.style||{};

    if(item.type==="comment"){
        el.style.color=s.color||"#fff";

        el.style.background=rgba(
            s.background||"#111",
            Number.isFinite(Number(s.bgOpacity))
                ? Number(s.bgOpacity)
                : .86
        );

        el.style.fontSize=
            `${Number(s.fontSize)||18}px`;

        el.style.fontFamily=
            s.fontFamily||"system-ui";

        el.style.fontWeight=
            s.bold?"700":"400";

        el.style.fontStyle=
            s.italic?"italic":"normal";

        el.style.border=
            `${Number(s.borderWidth)||0}px solid ${
                s.borderColor||"#fff"
            }`;

        el.style.borderRadius=
            `${Number(s.radius)||0}px`;

        el.style.padding=
            `${Number(s.padding)||0}px`;
    }else{
        el.style.background="transparent";

        el.style.border=
            `${Number(s.borderWidth)||3}px solid ${
                s.borderColor||"#ff453a"
            }`;

        el.style.borderRadius=
            `${Number(s.radius)||0}px`;
    }
}

const POINTS=[
    "n","ne","e","se",
    "s","sw","w","nw"
];

function addConnectionPoints(item,el){
    POINTS.forEach(point=>{
        const p=document.createElement("div");

        p.className="connection-point";
        p.dataset.point=point;

        p.addEventListener("pointerdown",e=>{
            e.preventDefault();
            e.stopPropagation();

            if(
                state.connectMode &&
                state.connectFrom
            ){
                if(state.connectFrom!==item.id){
                    createConnector(
                        state.connectFrom,
                        item.id,
                        state.connectPoint || "e",
                        point
                    );
                }

                return;
            }

            state.connectMode=true;
            state.connectFrom=item.id;
            state.connectPoint=point;
            state.selected=item.id;

            $("connect").textContent=
                "接続先を選択...";
        });

        el.appendChild(p);
    });
}

function renderObjects(){
    objectsEl.replaceChildren();

    const t=video.currentTime||0;

    state.items.forEach(item=>{
        if(!objectVisible(item,t)){
            return;
        }

        const el=document.createElement("div");

        el.className=
            "edit-object "+
            item.type+
            (
                state.selected===item.id
                    ? " selected"
                    : ""
            );

        el.dataset.id=item.id;

        el.style.left=`${item.x}%`;
        el.style.top=`${item.y}%`;
        el.style.width=`${item.w}%`;
        el.style.height=`${item.h}%`;

        if(item.type==="comment"){
            el.textContent=item.text||"";
        }

        applyObjectStyle(el,item);

        el.addEventListener("pointerdown",e=>{
            if(e.button!==0){
                return;
            }

            e.stopPropagation();

            if(
                state.connectMode &&
                state.connectFrom &&
                state.connectFrom!==item.id
            ){
                createConnector(
                    state.connectFrom,
                    item.id,
                    state.connectPoint||"e",
                    "w"
                );

                return;
            }

            state.selected=item.id;
            render();

            if(e.target.classList.contains("object-handle")){
                resizeObject(e,item);
            }else{
                dragObject(e,item);
            }
        });

        el.addEventListener("dblclick",e=>{
            if(item.type==="comment"){
                e.preventDefault();
                e.stopPropagation();
                editCommentDirectly(item,el);
            }
        });

        el.addEventListener("contextmenu",e=>{
            e.preventDefault();
            e.stopPropagation();

            state.selected=item.id;

            showContextMenu(
                e.clientX,
                e.clientY,
                item,
                "object"
            );
        });

        addConnectionPoints(item,el);

        if(state.selected===item.id){
            const handle=document.createElement("div");

            handle.className="object-handle";

            el.appendChild(handle);
        }

        objectsEl.appendChild(el);
    });
}

function dragObject(event,item){
    const rect=stage.getBoundingClientRect();

    const startX=event.clientX;
    const startY=event.clientY;

    const ox=item.x;
    const oy=item.y;

    const move=e=>{
        const dx=(e.clientX-startX)/rect.width*100;
        const dy=(e.clientY-startY)/rect.height*100;

        item.x=clamp(
            ox+dx,
            0,
            100-item.w
        );

        item.y=clamp(
            oy+dy,
            0,
            100-item.h
        );

        render();
    };

    const up=()=>{
        window.removeEventListener(
            "pointermove",
            move
        );

        window.removeEventListener(
            "pointerup",
            up
        );

        saveLocal();
    };

    window.addEventListener(
        "pointermove",
        move
    );

    window.addEventListener(
        "pointerup",
        up
    );
}

function resizeObject(event,item){
    const rect=stage.getBoundingClientRect();

    const startX=event.clientX;
    const startY=event.clientY;

    const ow=item.w;
    const oh=item.h;

    const move=e=>{
        const dw=(e.clientX-startX)/rect.width*100;
        const dh=(e.clientY-startY)/rect.height*100;

        item.w=clamp(
            ow+dw,
            2,
            100-item.x
        );

        item.h=clamp(
            oh+dh,
            2,
            100-item.y
        );

        render();
    };

    const up=()=>{
        window.removeEventListener(
            "pointermove",
            move
        );

        window.removeEventListener(
            "pointerup",
            up
        );

        saveLocal();
    };

    window.addEventListener(
        "pointermove",
        move
    );

    window.addEventListener(
        "pointerup",
        up
    );
}

function editCommentDirectly(item,el){
    const old=item.text||"";

    const textarea=document.createElement("textarea");

    textarea.value=old;

    textarea.style.position="absolute";
    textarea.style.inset="0";
    textarea.style.width="100%";
    textarea.style.height="100%";
    textarea.style.resize="none";
    textarea.style.zIndex="100";

    el.appendChild(textarea);

    textarea.focus();
    textarea.select();

    const finish=()=>{
        item.text=textarea.value;

        textarea.remove();

        render();
        saveLocal();
    };

    textarea.addEventListener("blur",finish,{once:true});

    textarea.addEventListener("keydown",e=>{
        if(e.key==="Escape"){
            textarea.value=old;
            textarea.blur();
        }

        if(
            e.key==="Enter" &&
            (e.ctrlKey||e.metaKey)
        ){
            textarea.blur();
        }
    });
}

function createConnector(from,to,fromPoint="e",toPoint="w"){
    const a=state.items.find(x=>x.id===from);
    const b=state.items.find(x=>x.id===to);

    if(!a || !b){
        return;
    }

    const start=Math.max(a.start,b.start);
    const end=Math.min(a.end,b.end);

    if(end<=start){
        notify(
            "接続する2つの要素の表示時間が重なっていません。"
        );
        return;
    }

    const line={
        id:uid(),
        from:from,
        to:to,
        fromPoint:fromPoint,
        toPoint:toPoint,
        start:start,
        end:end,
        style:{
            color:"#ffffff",
            width:3,
            dash:"solid",
            startArrow:"none",
            endArrow:"arrow"
        }
    };

    state.connectors.push(line);
    state.selected=line.id;
    state.connectMode=false;
    state.connectFrom=null;
    state.connectPoint=null;

    $("connect").textContent="線でつなぐ";

    render();
    saveLocal();
}

function deleteSelected(){
    const id=state.selected;

    if(!id){
        return;
    }

    const itemIndex=state.items.findIndex(
        x=>x.id===id
    );

    if(itemIndex>=0){
        state.items.splice(itemIndex,1);

        state.connectors=
            state.connectors.filter(
                line=>
                    line.from!==id &&
                    line.to!==id
            );
    }else{
        const lineIndex=
            state.connectors.findIndex(
                x=>x.id===id
            );

        if(lineIndex>=0){
            state.connectors.splice(
                lineIndex,
                1
            );
        }
    }

    state.selected=null;

    render();
    saveLocal();
}

function duplicateSelected(){
    const target=findSelection();

    if(!target || !state.items.includes(target)){
        return;
    }

    const copy=structuredClone(target);

    copy.id=uid();

    copy.x=clamp(
        Number(copy.x)+3,
        0,
        100-copy.w
    );

    copy.y=clamp(
        Number(copy.y)+3,
        0,
        100-copy.h
    );

    state.items.push(copy);
    state.selected=copy.id;

    render();
    saveLocal();
}

function renderSelectionPanel(){
    const target=findSelection();

    $("selectionHint").classList.toggle(
        "hidden",
        !!target
    );

    $("objectFields").classList.toggle(
        "hidden",
        !target || !state.items.includes(target)
    );

    if(
        !target ||
        !state.items.includes(target)
    ){
        return;
    }

    $("objectText").value=
        target.text||"";

    $("objectStart").value=
        target.start;

    $("objectEnd").value=
        target.end;

    $("objectX").value=
        target.x;

    $("objectY").value=
        target.y;

    $("objectW").value=
        target.w;

    $("objectH").value=
        target.h;

    $("textField").classList.toggle(
        "hidden",
        target.type!=="comment"
    );
}

function renderLists(){
    const root=$("objectList");

    root.replaceChildren();

    state.items.forEach(item=>{
        const row=document.createElement("div");

        row.className=
            "list-item"+
            (
                state.selected===item.id
                    ? " selected"
                    : ""
            );

        const label=document.createElement("div");

        label.textContent=
            (
                item.type==="comment"
                    ? "コメント："+
                      (item.text||"")
                    : "強調枠"
            )+
            `　${formatTime(item.start)}～${formatTime(item.end)}`;

        row.appendChild(label);

        const actions=document.createElement("div");

        actions.className="actions";

        const select=document.createElement("button");

        select.textContent="選択";

        select.onclick=()=>{
            video.currentTime=item.start;
            state.selected=item.id;
            render();
        };

        const format=document.createElement("button");

        format.textContent="書式";

        format.onclick=()=>{
            state.selected=item.id;
            openFormatPanel(item);
            render();
        };

        const remove=document.createElement("button");

        remove.textContent="削除";

        remove.onclick=()=>{
            state.selected=item.id;
            deleteSelected();
        };

        actions.append(
            select,
            format,
            remove
        );

        row.appendChild(actions);
        root.appendChild(row);
    });

    state.connectors.forEach(line=>{
        const row=document.createElement("div");

        row.className=
            "list-item"+
            (
                state.selected===line.id
                    ? " selected"
                    : ""
            );

        const a=state.items.find(
            x=>x.id===line.from
        );

        const b=state.items.find(
            x=>x.id===line.to
        );

        row.textContent=
            `線：${
                a?.text||
                a?.type||
                "要素"
            } → ${
                b?.text||
                b?.type||
                "要素"
            }`;

        const actions=document.createElement("div");

        actions.className="actions";

        const format=document.createElement("button");

        format.textContent="書式・接点";

        format.onclick=()=>{
            state.selected=line.id;
            openFormatPanel(line);
            render();
        };

        const remove=document.createElement("button");

        remove.textContent="削除";

        remove.onclick=()=>{
            state.selected=line.id;
            deleteSelected();
        };

        actions.append(
            format,
            remove
        );

        row.appendChild(actions);
        root.appendChild(row);
    });

    renderSkipList();
}

function renderSkipList(){
    const root=$("skipList");

    root.replaceChildren();

    state.skips.forEach((range,index)=>{
        const row=document.createElement("div");

        row.className="list-item";

        row.textContent=
            `${formatTime(range.start)}～${formatTime(range.end)} `;

        const button=document.createElement("button");

        button.textContent="削除";

        button.onclick=()=>{
            state.skips.splice(index,1);
            render();
            saveLocal();
        };

        row.appendChild(button);
        root.appendChild(row);
    });
}

function renderTracks(){
    const max=duration()||1;

    const tracks=[
        [
            "commentTrack",
            state.items.filter(
                x=>x.type==="comment"
            )
        ],
        [
            "boxTrack",
            state.items.filter(
                x=>x.type==="box"
            )
        ],
        [
            "connectorTrack",
            state.connectors
        ],
        [
            "skipTrack",
            state.skips
        ]
    ];

    tracks.forEach(([id,list])=>{
        const root=$(id);
        root.replaceChildren();

        list.forEach(item=>{
            const span=document.createElement("span");

            const start=
                Number(item.start)||0;

            const end=
                Number(item.end)||0;

            span.style.left=
                `${start/max*100}%`;

            span.style.width=
                `${Math.max(
                    0.3,
                    (end-start)/max*100
                )}%`;

            span.title=
                `${formatTime(start)}～${formatTime(end)}`;

            span.onclick=()=>{
                video.currentTime=start;

                if(item.id){
                    state.selected=item.id;
                }

                render();
            };

            root.appendChild(span);
        });
    });
}

function render(){
    renderObjects();
    drawConnectors();
    renderSelectionPanel();
    renderLists();
    renderTracks();

    const d=duration();

    $("seek").max=d||0;
    $("seek").value=
        video.currentTime||0;

    $("timeReadout").textContent=
        `${formatTime(video.currentTime)} / ${formatTime(d)}`;

    $("connect").textContent=
        state.connectMode
            ? "接続先を選択..."
            : "線でつなぐ";
}

video.addEventListener("timeupdate",()=>{
    renderObjects();
    drawConnectors();

    $("seek").value=
        video.currentTime||0;

    $("timeReadout").textContent=
        `${formatTime(video.currentTime)} / ${formatTime(duration())}`;

    autoSkip();
});

video.addEventListener("loadedmetadata",()=>{
    fitStage();
    render();
});

window.addEventListener("resize",fitStage);

function fitStage(){
    const area=$("videoArea");

    const vw=video.videoWidth;
    const vh=video.videoHeight;

    if(!vw || !vh){
        return;
    }

    const aw=area.clientWidth;
    const ah=area.clientHeight;

    const scale=Math.min(
        aw/vw,
        ah/vh
    );

    stage.style.width=
        `${Math.max(1,vw*scale)}px`;

    stage.style.height=
        `${Math.max(1,vh*scale)}px`;
}

stage.addEventListener("pointerdown",e=>{
    if(e.target===stage || e.target===objectsEl){
        state.selected=null;
        render();
    }
});

stage.addEventListener("contextmenu",e=>{
    e.preventDefault();

    if(
        e.target.closest(".edit-object") ||
        e.target.closest(".connector")
    ){
        return;
    }

    const rect=stage.getBoundingClientRect();

    state.contextPoint={
        x:clamp(
            (e.clientX-rect.left)/
            rect.width*100,
            0,
            90
        ),
        y:clamp(
            (e.clientY-rect.top)/
            rect.height*100,
            0,
            90
        )
    };

    showContextMenu(
        e.clientX,
        e.clientY,
        null,
        "stage"
    );
});

function showContextMenu(
    x,
    y,
    target,
    type
){
    const menu=$("contextMenu");

    menu.dataset.targetId=
        target?.id||"";

    menu.dataset.targetType=type;

    const hasTarget=!!target;

    menu.querySelectorAll(
        "[data-action]"
    ).forEach(button=>{
        const action=button.dataset.action;

        let hidden=false;

        if(type==="stage"){
            hidden=![
                "comment",
                "box"
            ].includes(action);
        }

        if(type==="connector"){
            hidden=[
                "edit",
                "duplicate"
            ].includes(action);
        }

        if(!hasTarget && ![
            "comment",
            "box"
        ].includes(action)){
            hidden=true;
        }

        button.classList.toggle(
            "hidden",
            hidden
        );
    });

    menu.style.display="block";

    const w=menu.offsetWidth;
    const h=menu.offsetHeight;

    menu.style.left=
        `${clamp(x,5,innerWidth-w-5)}px`;

    menu.style.top=
        `${clamp(y,5,innerHeight-h-5)}px`;
}

$("contextMenu").addEventListener(
    "click",
    e=>{
        const button=e.target.closest(
            "button[data-action]"
        );

        if(!button){
            return;
        }

        const menu=$("contextMenu");

        const action=button.dataset.action;
        const id=menu.dataset.targetId;
        const type=menu.dataset.targetType;

        menu.style.display="none";

        const target=
            type==="object"
                ? state.items.find(
                    x=>x.id===id
                )
                : type==="connector"
                    ? state.connectors.find(
                        x=>x.id===id
                    )
                    : null;

        if(action==="format" && target){
            state.selected=target.id;
            openFormatPanel(target);
            render();
            return;
        }

        if(action==="delete" && target){
            state.selected=target.id;
            deleteSelected();
            return;
        }

        if(action==="duplicate" && target){
            state.selected=target.id;
            duplicateSelected();
            return;
        }

        if(action==="edit" && target){
            state.selected=target.id;
            render();

            if(target.type==="comment"){
                const el=[
                    ...objectsEl.children
                ].find(
                    x=>x.dataset.id===target.id
                );

                if(el){
                    editCommentDirectly(
                        target,
                        el
                    );
                }
            }

            return;
        }

        if(action==="connect"){
            state.connectMode=true;

            if(target){
                state.connectFrom=target.id;
                state.selected=target.id;
            }

            $("connect").textContent=
                "接続先を選択...";

            return;
        }

        if(action==="comment"){
            addItem(
                "comment",
                state.contextPoint
            );
        }

        if(action==="box"){
            addItem(
                "box",
                state.contextPoint
            );
        }
    }
);

document.addEventListener("pointerdown",e=>{
    if(
        !$("contextMenu").contains(e.target)
    ){
        $("contextMenu").style.display="none";
    }
});

function loadObjectFormat(item){
    const s=item.style||{};

    $("fmtColor").value=
        s.color||"#ffffff";

    $("fmtBackground").value=
        s.background||"#111111";

    $("fmtFontSize").value=
        Number(s.fontSize)||18;

    $("fmtFontFamily").value=
        s.fontFamily||"system-ui";

    $("fmtBold").checked=
        !!s.bold;

    $("fmtItalic").checked=
        !!s.italic;

    $("fmtBgOpacity").value=
        Number.isFinite(Number(s.bgOpacity))
            ? s.bgOpacity
            : .85;

    $("fmtBorderColor").value=
        s.borderColor||"#ffffff";

    $("fmtBorderWidth").value=
        Number(s.borderWidth)||0;

    $("fmtRadius").value=
        Number(s.radius)||0;

    $("fmtPadding").value=
        Number(s.padding)||0;
}

function loadConnectorFormat(line){
    const s=line.style||{};

    $("lineColor").value=
        s.color||"#ffffff";

    $("lineWidth").value=
        Number(s.width)||3;

    $("lineDash").value=
        s.dash||"solid";

    $("lineStartPoint").value=
        line.fromPoint||"e";

    $("lineEndPoint").value=
        line.toPoint||"w";

    $("lineStartArrow").value=
        s.startArrow||"none";

    $("lineEndArrow").value=
        s.endArrow||"arrow";
}

function updateFormatPreview(){
    const target=state.formatTarget;

    if(!target){
        return;
    }

    if(state.connectors.includes(target)){
        $("formatPreview").textContent=
            "接続線";
        return;
    }

    const preview=$("formatPreview");

    preview.textContent=
        target.text||"プレビュー";

    preview.style.color=
        $("fmtColor").value;

    preview.style.background=
        rgba(
            $("fmtBackground").value,
            Number($("fmtBgOpacity").value)
        );

    preview.style.fontSize=
        `${Number($("fmtFontSize").value)||18}px`;

    preview.style.fontFamily=
        $("fmtFontFamily").value;

    preview.style.fontWeight=
        $("fmtBold").checked
            ? "700"
            : "400";

    preview.style.fontStyle=
        $("fmtItalic").checked
            ? "italic"
            : "normal";

    preview.style.border=
        `${Number($("fmtBorderWidth").value)||0}px solid ${
            $("fmtBorderColor").value
        }`;

    preview.style.borderRadius=
        `${Number($("fmtRadius").value)||0}px`;

    preview.style.padding=
        `${Number($("fmtPadding").value)||0}px`;
}

function openFormatPanel(target){
    state.formatTarget=target;

    const isConnector=
        state.connectors.includes(target);

    $("formatTitle").textContent=
        isConnector
            ? "接続線の書式・接点変更"
            : target.type==="comment"
                ? "コメントの書式変更"
                : "強調枠の書式変更";

    $("formatObject").classList.toggle(
        "hidden",
        isConnector
    );

    $("formatConnector").classList.toggle(
        "hidden",
        !isConnector
    );

    if(isConnector){
        loadConnectorFormat(target);
    }else{
        loadObjectFormat(target);
    }

    const panel=$("formatPanel");

    panel.style.display="block";

    panel.style.left=
        `${Math.max(
            5,
            innerWidth-panel.offsetWidth-15
        )}px`;

    panel.style.top="70px";

    updateFormatPreview();
}

[
    "fmtColor",
    "fmtBackground",
    "fmtFontSize",
    "fmtFontFamily",
    "fmtBold",
    "fmtItalic",
    "fmtBgOpacity",
    "fmtBorderColor",
    "fmtBorderWidth",
    "fmtRadius",
    "fmtPadding"
].forEach(id=>{
    $(id).addEventListener(
        "input",
        updateFormatPreview
    );

    $(id).addEventListener(
        "change",
        updateFormatPreview
    );
});

$("formatCancel").onclick=()=>{
    $("formatPanel").style.display="none";
    state.formatTarget=null;
};

$("formatApply").onclick=()=>{
    const target=state.formatTarget;

    if(!target){
        return;
    }

    if(state.items.includes(target)){
        target.style={
            color:$("fmtColor").value,
            background:$("fmtBackground").value,
            bgOpacity:Number(
                $("fmtBgOpacity").value
            ),
            fontSize:Number(
                $("fmtFontSize").value
            ),
            fontFamily:
                $("fmtFontFamily").value,
            bold:$("fmtBold").checked,
            italic:$("fmtItalic").checked,
            borderColor:
                $("fmtBorderColor").value,
            borderWidth:Number(
                $("fmtBorderWidth").value
            ),
            radius:Number(
                $("fmtRadius").value
            ),
            padding:Number(
                $("fmtPadding").value
            )
        };
    }

    if(state.connectors.includes(target)){
        target.style={
            color:$("lineColor").value,
            width:Number(
                $("lineWidth").value
            ),
            dash:$("lineDash").value,
            startArrow:
                $("lineStartArrow").value,
            endArrow:
                $("lineEndArrow").value
        };

        target.fromPoint=
            $("lineStartPoint").value;

        target.toPoint=
            $("lineEndPoint").value;
    }

    $("formatPanel").style.display="none";
    state.formatTarget=null;

    render();
    saveLocal();

    notify("書式・接点を変更しました。",true);
};

$("applyObject").onclick=()=>{
    const item=findSelection();

    if(!item || !state.items.includes(item)){
        return;
    }

    if(item.type==="comment"){
        item.text=$("objectText").value;
    }

    const values={
        start:Number($("objectStart").value),
        end:Number($("objectEnd").value),
        x:Number($("objectX").value),
        y:Number($("objectY").value),
        w:Number($("objectW").value),
        h:Number($("objectH").value)
    };

    if(
        !Number.isFinite(values.start) ||
        !Number.isFinite(values.end) ||
        values.end<=values.start
    ){
        notify("開始・終了時間が不正です。");
        return;
    }

    if(values.start<0 || values.end>duration()){
        notify("動画の長さを超えています。");
        return;
    }

    item.start=values.start;
    item.end=values.end;
    item.x=clamp(values.x,0,100);
    item.y=clamp(values.y,0,100);
    item.w=clamp(values.w,1,100-item.x);
    item.h=clamp(values.h,1,100-item.y);

    state.connectors.forEach(line=>{
        if(
            line.from===item.id ||
            line.to===item.id
        ){
            const a=state.items.find(
                x=>x.id===line.from
            );

            const b=state.items.find(
                x=>x.id===line.to
            );

            if(a && b){
                line.start=Math.max(
                    a.start,
                    b.start
                );

                line.end=Math.min(
                    a.end,
                    b.end
                );
            }
        }
    });

    render();
    saveLocal();
};

$("addComment").onclick=()=>{
    addItem("comment");
};

$("addBox").onclick=()=>{
    addItem("box");
};

$("connect").onclick=()=>{
    if(state.connectMode){
        state.connectMode=false;
        state.connectFrom=null;
        state.connectPoint=null;
        $("connect").textContent="線でつなぐ";
        render();
        return;
    }

    const selected=findSelection();

    if(
        selected &&
        state.items.includes(selected)
    ){
        state.connectMode=true;
        state.connectFrom=selected.id;
        state.connectPoint="e";
        $("connect").textContent=
            "接続先を選択...";
    }else{
        notify(
            "最初に接続元の要素を選択してください。"
        );
    }
};

$("deleteSelected").onclick=deleteSelected;

$("skipFromCurrent").onclick=()=>{
    $("skipStart").value=
        (video.currentTime||0).toFixed(1);

    $("skipEnd").value=
        Math.min(
            duration(),
            (video.currentTime||0)+5
        ).toFixed(1);
};

$("addSkip").onclick=()=>{
    const start=Number(
        $("skipStart").value
    );

    const end=Number(
        $("skipEnd").value
    );

    if(
        !Number.isFinite(start) ||
        !Number.isFinite(end) ||
        start<0 ||
        end<=start ||
        end>duration()
    ){
        notify("スキップ範囲が不正です。");
        return;
    }

    state.skips.push({
        start:start,
        end:end
    });

    state.skips.sort(
        (a,b)=>a.start-b.start
    );

    render();
    saveLocal();
};

function autoSkip(){
    const t=video.currentTime;

    const range=state.skips.find(
        x=>t>=x.start && t<x.end-.03
    );

    if(range){
        video.currentTime=range.end;
    }
}

$("seek").oninput=()=>{
    video.currentTime=
        Number($("seek").value)||0;
};

$("back5").onclick=()=>{
    video.currentTime=
        Math.max(
            0,
            video.currentTime-5
        );
};

$("forward5").onclick=()=>{
    video.currentTime=
        Math.min(
            duration(),
            video.currentTime+5
        );
};

$("newTarget").onclick=()=>{
    $("videoName").value="";
    state.videoName="";
    state.targetName="";
    state.items=[];
    state.connectors=[];
    state.skips=[];
    state.selected=null;

    updateTargetLabel();
    render();
};

$("setTarget").onclick=()=>{
    const name=$("videoName").value.trim();

    if(!name){
        notify(
            "編集対象にする動画名を入力してください。"
        );
        $("videoName").focus();
        return;
    }

    if(name.length>120){
        notify(
            "動画名は120文字以内にしてください。"
        );
        return;
    }

    if(
        state.videoName &&
        state.videoName!==name &&
        (
            state.items.length ||
            state.connectors.length ||
            state.skips.length
        )
    ){
        if(
            !confirm(
                "動画名を変更すると現在の編集データは新しい動画名に紐付きます。\n続行しますか？"
            )
        ){
            return;
        }
    }

    state.videoName=name;
    state.targetName=name;

    const local=localStorage.getItem(
        LOCAL_PREFIX+name
    );

    if(local){
        try{
            applyProject(JSON.parse(local));
            notify(
                `「${name}」のローカル編集データを読み込みました。`,
                true
            );
            return;
        }catch(error){
            console.warn(error);
        }
    }

    updateTargetLabel();
    saveLocal();

    notify(
        `「${name}」を編集対象にしました。`,
        true
    );
};

$("videoName").addEventListener(
    "keydown",
    e=>{
        if(e.key==="Enter"){
            $("setTarget").click();
        }
    }
);

$("localSave").onclick=()=>{
    if(!state.videoName){
        notify(
            "先に動画名を設定してください。"
        );
        return;
    }

    const data=projectData();

    const blob=new Blob(
        [JSON.stringify(data,null,2)],
        {type:"application/json"}
    );

    download(
        blob,
        safeFileName(
            state.videoName+
            "-編集データ.json"
        )
    );

    notify(
        "ローカルJSONを保存しました。",
        true
    );
};

$("localLoad").onclick=()=>{
    $("projectFile").click();
};

$("projectFile").onchange=async e=>{
    const file=e.target.files?.[0];

    if(!file){
        return;
    }

    try{
        const data=
            JSON.parse(
                await file.text()
            );

        applyProject(data);

        notify(
            `「${state.videoName}」の編集データを読み込みました。`,
            true
        );
    }catch(error){
        notify(
            "JSON読込に失敗しました。\n"+
            (error.message||"")
        );
    }finally{
        e.target.value="";
    }
};

function safeFileName(name){
    return name.replace(
        /[\\/:*?"<>|]/g,
        "_"
    );
}

function download(blob,name){
    const url=URL.createObjectURL(blob);

    const a=document.createElement("a");

    a.href=url;
    a.download=name;

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(
        ()=>URL.revokeObjectURL(url),
        1000
    );
}

$("serverSave").onclick=async()=>{
    if(!state.videoName){
        notify(
            "先に「動画名」を指定してください。"
        );
        return;
    }

    try{
        setStatus("サーバー保存中...");

        const response=await fetch(
            `${API}?api=save`,
            {
                method:"POST",
                headers:{
                    "Content-Type":
                        "application/json"
                },
                body:JSON.stringify(
                    projectData()
                )
            }
        );

        const result=
            await response.json();

        if(!response.ok || !result.ok){
            if(
                result.reason==="limit"
            ){
                download(
                    new Blob(
                        [
                            JSON.stringify(
                                projectData(),
                                null,
                                2
                            )
                        ],
                        {
                            type:
                                "application/json"
                        }
                    ),
                    safeFileName(
                        state.videoName+
                        "-ローカル保存.json"
                    )
                );

                throw new Error(
                    "サーバー保存上限を超えたため、ローカルJSONとして保存しました。"
                );
            }

            throw new Error(
                result.message||
                "サーバー保存に失敗しました。"
            );
        }

        saveLocal();

        updateStorageInfo(
            result.storage
        );

        notify(
            `「${state.videoName}」をサーバーへ保存しました。`,
            true
        );

        await loadProjectList();

    }catch(error){
        notify(
            error.message||
            "サーバー保存に失敗しました。"
        );
    }finally{
        setStatus("編集中");
    }
};

$("serverLoad").onclick=async()=>{
    if(!state.videoName){
        notify(
            "読み込む動画名を指定してください。"
        );
        return;
    }

    await loadByName(
        state.videoName
    );
};

async function loadByName(name){
    try{
        setStatus("サーバー読込中...");

        const response=await fetch(
            `${API}?api=load&name=${
                encodeURIComponent(name)
            }`
        );

        const result=
            await response.json();

        if(!response.ok || !result.ok){
            throw new Error(
                result.message||
                "サーバー読込に失敗しました。"
            );
        }

        applyProject(result.project);

        notify(
            `「${name}」をサーバーから読み込みました。`,
            true
        );

    }catch(error){
        notify(
            error.message||
            "サーバー読込に失敗しました。"
        );
    }finally{
        setStatus("編集中");
    }
}

function updateStorageInfo(info){
    if(!info){
        return;
    }

    $("storageInfo").textContent=
        `サーバー保存：${info.count}/${info.maxCount}件 `+
        `・${formatBytes(info.bytes)}/${formatBytes(info.maxBytes)}`;
}

function formatBytes(bytes){
    bytes=Number(bytes)||0;

    if(bytes<1024){
        return bytes+" B";
    }

    if(bytes<1024*1024){
        return (
            (bytes/1024).toFixed(1)+
            " KB"
        );
    }

    return (
        (bytes/1024/1024).toFixed(1)+
        " MB"
    );
}

async function loadStorageInfo(){
    try{
        const response=await fetch(
            `${API}?api=status`
        );

        const result=
            await response.json();

        if(result.ok){
            updateStorageInfo(
                result.storage
            );
        }
    }catch(error){
        $("storageInfo").textContent=
            "サーバー状態を取得できません。";
    }
}

$("listProjects").onclick=
    loadProjectList;

async function loadProjectList(){
    const root=$("projectList");

    root.textContent=
        "保存一覧を読み込み中...";

    try{
        const response=await fetch(
            `${API}?api=list`
        );

        const result=
            await response.json();

        if(!response.ok || !result.ok){
            throw new Error(
                result.message||
                "一覧取得に失敗しました。"
            );
        }

        updateStorageInfo(
            result.storage
        );

        root.replaceChildren();

        if(!result.projects.length){
            root.textContent=
                "サーバー保存データはありません。";
            return;
        }

        result.projects.forEach(project=>{
            const row=
                document.createElement("div");

            row.className="list-item";

            const title=
                document.createElement("strong");

            title.textContent=
                project.videoName;

            const info=
                document.createElement("div");

            info.className="help";

            info.textContent=
                `${project.savedAt} / `+
                formatBytes(project.size);

            const actions=
                document.createElement("div");

            actions.className="actions";

            const load=
                document.createElement("button");

            load.textContent="読込";

            load.onclick=()=>{
                $("videoName").value=
                    project.videoName;

                state.videoName=
                    project.videoName;

                state.targetName=
                    project.videoName;

                loadByName(
                    project.videoName
                );
            };

            const remove=
                document.createElement("button");

            remove.textContent="削除";
            remove.className="danger";

            remove.onclick=async()=>{
                if(
                    !confirm(
                        `「${project.videoName}」をサーバーから削除しますか？`
                    )
                ){
                    return;
                }

                try{
                    const response=
                        await fetch(
                            `${API}?api=delete`,
                            {
                                method:"POST",
                                headers:{
                                    "Content-Type":
                                        "application/json"
                                },
                                body:
                                    JSON.stringify({
                                        videoName:
                                            project.videoName
                                    })
                            }
                        );

                    const result=
                        await response.json();

                    if(!response.ok || !result.ok){
                        throw new Error(
                            result.message||
                            "削除に失敗しました。"
                        );
                    }

                    await loadProjectList();

                    notify(
                        "削除しました。",
                        true
                    );

                }catch(error){
                    notify(
                        error.message||
                        "削除に失敗しました。"
                    );
                }
            };

            actions.append(
                load,
                remove
            );

            row.append(
                title,
                info,
                actions
            );

            root.appendChild(row);
        });

    }catch(error){
        root.textContent=
            "保存一覧を取得できません。";

        notify(
            error.message||
            "保存一覧取得に失敗しました。"
        );
    }
}

$("clearEdits").onclick=()=>{
    if(
        !confirm(
            "コメント・強調枠・線・スキップをすべて削除しますか？"
        )
    ){
        return;
    }

    state.items=[];
    state.connectors=[];
    state.skips=[];
    state.selected=null;
    state.connectMode=false;
    state.connectFrom=null;

    render();
    saveLocal();
};

function setRecordedVideo(blob){
    if(recordedUrl){
        URL.revokeObjectURL(recordedUrl);
    }

    recordedBlob=blob;

    recordedUrl=
        URL.createObjectURL(blob);

    video.src=recordedUrl;
    video.load();

    $("openEditor").disabled=false;
    $("reset").disabled=false;

    $("preview").style.display="none";
    $("placeholder").style.display="block";
}

function openVideoBlob(blob,name){
    if(currentVideoUrl){
        URL.revokeObjectURL(
            currentVideoUrl
        );
    }

    currentVideoUrl=
        URL.createObjectURL(blob);

    video.src=currentVideoUrl;
    video.load();

    $("openEditor").disabled=false;
    $("reset").disabled=false;

    if(name){
        $("videoName").value=name;
    }
}

$("openFile").onclick=()=>{
    $("videoFile").click();
};

$("videoFile").onchange=e=>{
    const file=e.target.files?.[0];

    if(!file){
        return;
    }

    openVideoBlob(
        file,
        file.name.replace(/\.[^.]+$/,"")
    );

    notify(
        `動画「${file.name}」を読み込みました。`,
        true
    );

    e.target.value="";
};

$("openEditor").onclick=()=>{
    if(!video.src){
        notify(
            "先に動画を録画するか開いてください。"
        );
        return;
    }

    $("editor").style.display="flex";

    fitStage();

    if(
        !state.videoName &&
        $("videoName").value.trim()
    ){
        state.videoName=
            $("videoName").value.trim();

        state.targetName=
            state.videoName;

        updateTargetLabel();
    }

    render();
};

$("closeEditor").onclick=()=>{
    $("editor").style.display="none";
};

$("reset").onclick=()=>{
    if(
        !confirm(
            "現在の録画・動画をリセットしますか？"
        )
    ){
        return;
    }

    if(recordedUrl){
        URL.revokeObjectURL(recordedUrl);
    }

    if(currentVideoUrl){
        URL.revokeObjectURL(
            currentVideoUrl
        );
    }

    recordedUrl=null;
    currentVideoUrl=null;
    recordedBlob=null;

    video.removeAttribute("src");
    video.load();

    $("openEditor").disabled=true;
    $("reset").disabled=true;

    state.items=[];
    state.connectors=[];
    state.skips=[];
    state.selected=null;

    $("videoName").value="";
    state.videoName="";
    state.targetName="";

    updateTargetLabel();

    $("placeholder").style.display=
        "block";
};

async function startRecording(){
    try{
        const displayStream=
            await navigator.mediaDevices.getDisplayMedia({
                video:{
                    frameRate:{
                        ideal:30,
                        max:60
                    }
                },
                audio:$("systemAudio").checked
            });

        let tracks=[
            ...displayStream.getVideoTracks()
        ];

        if($("microphone").checked){
            try{
                const mic=
                    await navigator.mediaDevices.getUserMedia({
                        audio:true
                    });

                tracks.push(
                    ...mic.getAudioTracks()
                );
            }catch(error){
                displayStream
                    .getTracks()
                    .forEach(t=>t.stop());

                throw new Error(
                    "マイクを取得できませんでした。"
                );
            }
        }

        mediaStream=
            new MediaStream(tracks);

        const mimeCandidates=[
            "video/webm;codecs=vp9,opus",
            "video/webm;codecs=vp8,opus",
            "video/webm"
        ];

        const mimeType=
            mimeCandidates.find(
                type=>
                    MediaRecorder.isTypeSupported(
                        type
                    )
            )||"";

        recorder=new MediaRecorder(
            mediaStream,
            mimeType
                ? {mimeType}
                : undefined
        );

        chunks=[];

        recorder.ondataavailable=e=>{
            if(e.data.size){
                chunks.push(e.data);
            }
        };

        recorder.onstop=()=>{
            const type=
                recorder.mimeType||
                "video/webm";

            setRecordedVideo(
                new Blob(
                    chunks,
                    {type}
                )
            );

            mediaStream
                ?.getTracks()
                .forEach(t=>t.stop());

            mediaStream=null;

            stopTimer();

            setStatus("録画終了");

            $("start").disabled=false;
            $("pause").disabled=true;
            $("stop").disabled=true;

            notify(
                "録画を終了しました。編集画面を開けます。",
                true
            );
        };

        mediaStream
            .getVideoTracks()[0]
            .addEventListener(
                "ended",
                ()=>{
                    if(
                        recorder &&
                        recorder.state!=="inactive"
                    ){
                        recorder.stop();
                    }
                }
            );

        $("preview").srcObject=
            mediaStream;

        $("preview").style.display=
            "block";

        $("placeholder").style.display=
            "none";

        await $("preview").play();

        recorder.start(1000);

        timerStarted=
            Date.now();

        timerInterval=setInterval(
            updateTimer,
            250
        );

        recordingPaused=false;

        $("start").disabled=true;
        $("pause").disabled=false;
        $("stop").disabled=false;

        setStatus("録画中");

    }catch(error){
        console.error(error);

        notify(
            error.message||
            "録画を開始できませんでした。"
        );
    }
}

$("start").onclick=
    startRecording;

$("pause").onclick=()=>{
    if(!recorder){
        return;
    }

    if(
        recorder.state==="recording"
    ){
        recorder.pause();

        recordingPaused=true;

        $("pause").textContent=
            "録画再開";

        setStatus("一時停止");
    }else if(
        recorder.state==="paused"
    ){
        recorder.resume();

        recordingPaused=false;

        $("pause").textContent=
            "一時停止";

        setStatus("録画中");
    }
};

$("stop").onclick=()=>{
    if(
        recorder &&
        recorder.state!=="inactive"
    ){
        recorder.stop();
    }
};

function updateTimer(){
    if(!timerStarted){
        return;
    }

    if(recordingPaused){
        return;
    }

    $("timer").textContent=
        formatTime(
            (Date.now()-timerStarted)/1000
        );
}

function stopTimer(){
    clearInterval(timerInterval);
    timerInterval=null;
}

$("downloadWebm").onclick=()=>{
    if(!recordedBlob){
        notify(
            "WebM録画データがありません。"
        );
        return;
    }

    download(
        recordedBlob,
        safeFileName(
            (
                state.videoName||
                "recording"
            )+".webm"
        )
    );
};

$("downloadMp4").onclick=async()=>{
    if(!recordedBlob){
        notify(
            "録画データがありません。"
        );
        return;
    }

    notify(
        "MP4変換はブラウザ側で行うため、動画サイズによっては時間がかかります。"
    );

    try{
        if(
            !window.FFmpeg ||
            !window.FFmpeg.createFFmpeg
        ){
            throw new Error(
                "FFmpegライブラリを読み込めません。"
            );
        }

        const {
            createFFmpeg,
            fetchFile
        }=window.FFmpeg;

        const ffmpeg=createFFmpeg({
            log:false
        });

        await ffmpeg.load();

        ffmpeg.FS(
            "writeFile",
            "input.webm",
            await fetchFile(recordedBlob)
        );

        await ffmpeg.run(
            "-i",
            "input.webm",
            "-c:v",
            "libx264",
            "-pix_fmt",
            "yuv420p",
            "-c:a",
            "aac",
            "output.mp4"
        );

        const data=
            ffmpeg.FS(
                "readFile",
                "output.mp4"
            );

        download(
            new Blob(
                [data.buffer],
                {type:"video/mp4"}
            ),
            safeFileName(
                (
                    state.videoName||
                    "recording"
                )+".mp4"
            )
        );

        notify(
            "MP4変換が完了しました。",
            true
        );

    }catch(error){
        console.error(error);

        notify(
            "MP4変換に失敗しました。\n"+
            (error.message||"")
        );
    }
};

/*
 * キーボード操作
 */
document.addEventListener("keydown",e=>{
    if(
        $("editor").style.display!=="flex"
    ){
        return;
    }

    const tag=
        document.activeElement?.tagName;

    if(
        tag==="INPUT" ||
        tag==="TEXTAREA" ||
        tag==="SELECT"
    ){
        return;
    }

    if(
        (e.key==="Delete" ||
         e.key==="Backspace") &&
        state.selected
    ){
        e.preventDefault();
        deleteSelected();
    }

    if(e.key==="Escape"){
        $("contextMenu").style.display=
            "none";

        $("formatPanel").style.display=
            "none";

        state.connectMode=false;
        state.connectFrom=null;

        $("connect").textContent=
            "線でつなぐ";
    }
});

/*
 * 初期化
 */
(async function init(){

    await loadStorageInfo();

    updateTargetLabel();

    /*
     * 前回選択した動画名だけ復元。
     * 編集データ自体も動画名単位でlocalStorageから復元する。
     */
    try{
        const last=
            localStorage.getItem(
                "video-editor-last-name"
            );

        if(last){
            $("videoName").value=last;

            const local=
                localStorage.getItem(
                    LOCAL_PREFIX+last
                );

            if(local){
                applyProject(
                    JSON.parse(local)
                );
            }
        }
    }catch(error){
        console.warn(error);
    }

    $("videoName").addEventListener(
        "change",
        ()=>{
            localStorage.setItem(
                "video-editor-last-name",
                $("videoName").value.trim()
            );
        }
    );

    /*
     * CDNからFFmpegをロード。
     * 利用できない場合でも録画・編集・WebM保存は動作する。
     */
    const script=
        document.createElement("script");

    script.src=
        "https://cdn.jsdelivr.net/npm/@ffmpeg/ffmpeg@0.11.6/dist/ffmpeg.min.js";

    script.async=true;

    document.head.appendChild(script);

    setStatus("待機中");
})();
</script>

</body>
</html>

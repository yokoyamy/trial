<?php
declare(strict_types=1);

/*
 * 画面共有録画・動画上直接編集ツール
 *
 * 必要環境:
 * - Apache
 * - PHP 7.4+
 * - HTTPS または localhost
 *
 * サーバー側永続保存:
 *   ./data/projects/*.json
 *
 * Apache/PHPから data ディレクトリを書き込める必要があります。
 */

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
    if (is_dir(PROJECT_DIR)) {
        return is_writable(PROJECT_DIR);
    }

    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true)) {
        return false;
    }

    if (!is_dir(PROJECT_DIR) && !@mkdir(PROJECT_DIR, 0775, true)) {
        return false;
    }

    return is_writable(PROJECT_DIR);
}

function validProjectId(string $id): bool
{
    return (bool)preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $id);
}

function projectPath(string $id): string
{
    return PROJECT_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

/*
 * PHP API
 */
if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    if ($api === 'status') {
        jsonResponse([
            'ok' => true,
            'serverWritable' => ensureProjectDir(),
            'php' => PHP_VERSION
        ]);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        if (!ensureProjectDir()) {
            jsonResponse([
                'ok' => false,
                'message' => 'data/projects に書き込めません。ディレクトリの権限を確認してください。'
            ], 500);
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);

        if (!is_array($payload)) {
            jsonResponse(['ok' => false, 'message' => 'JSONが不正です。'], 400);
        }

        $projectId = isset($payload['projectId']) && is_string($payload['projectId'])
            ? $payload['projectId']
            : '';

        if (!$projectId) {
            $projectId = bin2hex(random_bytes(12));
        }

        if (!validProjectId($projectId)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
        }

        $payload['version'] = 2;
        $payload['projectId'] = $projectId;
        $payload['savedAt'] = date('c');

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false) {
            jsonResponse(['ok' => false, 'message' => 'JSON化に失敗しました。'], 500);
        }

        $path = projectPath($projectId);

        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            jsonResponse(['ok' => false, 'message' => '編集データを保存できませんでした。'], 500);
        }

        jsonResponse([
            'ok' => true,
            'projectId' => $projectId,
            'savedAt' => $payload['savedAt']
        ]);
    }

    if ($api === 'load') {
        $projectId = isset($_GET['id']) ? (string)$_GET['id'] : '';

        if (!validProjectId($projectId)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
        }

        $path = projectPath($projectId);

        if (!is_file($path)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトが見つかりません。'], 404);
        }

        $raw = @file_get_contents($path);
        $data = json_decode($raw ?: '', true);

        if (!is_array($data)) {
            jsonResponse(['ok' => false, 'message' => '保存データが壊れています。'], 500);
        }

        jsonResponse([
            'ok' => true,
            'project' => $data
        ]);
    }

    if ($api === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);
        $projectId = is_array($payload) && isset($payload['projectId'])
            ? (string)$payload['projectId']
            : '';

        if (!validProjectId($projectId)) {
            jsonResponse(['ok' => false, 'message' => 'プロジェクトIDが不正です。'], 400);
        }

        $path = projectPath($projectId);

        if (is_file($path) && !@unlink($path)) {
            jsonResponse(['ok' => false, 'message' => '削除できませんでした。'], 500);
        }

        jsonResponse(['ok' => true]);
    }

    jsonResponse(['ok' => false, 'message' => 'Unknown API'], 404);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>画面共有録画・動画編集</title>

<style>
:root{
    color-scheme:dark;
    --bg:#101114;
    --panel:#1b1d21;
    --panel2:#23262b;
    --border:#3b3f46;
    --text:#f4f5f7;
    --muted:#9da3ad;
    --blue:#2878d7;
    --red:#c93a3a;
    --green:#23864a;
    --yellow:#d9a62e;
}

*{box-sizing:border-box}

html,body{
    margin:0;
    width:100%;
    height:100%;
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
textarea,
select{
    font:inherit;
}

button{
    border:1px solid #4b5058;
    border-radius:6px;
    background:#30343a;
    color:#fff;
    padding:8px 11px;
    cursor:pointer;
}

button:hover:not(:disabled){
    background:#41464e;
}

button:disabled{
    opacity:.45;
    cursor:not-allowed;
}

button.primary{background:#1768b7}
button.success{background:#227b43}
button.danger{background:#9f3030}

input,
textarea,
select{
    color:#fff;
    background:#24272c;
    border:1px solid #50555d;
    border-radius:5px;
    padding:7px;
}

input[type="color"]{
    height:38px;
    padding:3px;
}

header{
    height:48px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 14px;
    border-bottom:1px solid #333;
    background:#1b1d20;
}

header h1{
    font-size:16px;
    margin:0;
}

#status{
    color:#b8bec8;
    font-size:13px;
}

#message{
    position:fixed;
    left:50%;
    top:58px;
    transform:translateX(-50%);
    z-index:1000;
    display:none;
    max-width:min(700px,90vw);
    padding:10px 15px;
    border-radius:7px;
    background:#8d3030;
    box-shadow:0 8px 30px #0008;
    white-space:pre-wrap;
}

#message.ok{
    background:#246b40;
}

.recorder{
    height:calc(100vh - 104px);
    min-height:300px;
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

#placeholder{
    color:#888;
    text-align:center;
    padding:30px;
}

.toolbar{
    height:56px;
    display:flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-top:1px solid #333;
    background:#1b1d20;
    overflow:auto;
}

.toolbar label{
    font-size:13px;
    white-space:nowrap;
}

#timer{
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
    min-height:0;
    flex:1;
    display:grid;
    grid-template-columns:minmax(0,1fr) 360px;
}

.video-area{
    position:relative;
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

#connectors{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
}

#objects{
    position:absolute;
    inset:0;
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
    min-width:30px;
    min-height:20px;
    padding:6px 9px;
    display:flex;
    align-items:center;
    justify-content:flex-start;
    white-space:pre-wrap;
    overflow:hidden;
    word-break:break-word;
    box-shadow:0 2px 10px #0005;
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
    background:#2878d7;
    border-radius:50%;
    z-index:30;
    display:none;
    cursor:crosshair;
}

.edit-object.selected .connection-point{
    display:block;
}

.connection-point[data-point="n"]{left:50%;top:0}
.connection-point[data-point="ne"]{left:100%;top:0}
.connection-point[data-point="e"]{left:100%;top:50%}
.connection-point[data-point="se"]{left:100%;top:100%}
.connection-point[data-point="s"]{left:50%;top:100%}
.connection-point[data-point="sw"]{left:0;top:100%}
.connection-point[data-point="w"]{left:0;top:50%}
.connection-point[data-point="nw"]{left:0;top:0}

.connector{
    stroke:#fff;
    stroke-width:2.5;
    fill:none;
    pointer-events:stroke;
    cursor:pointer;
}

.connector.selected{
    stroke:#ffd54f;
    stroke-width:5;
}

.connector-hit{
    stroke:transparent;
    stroke-width:14;
    fill:none;
    pointer-events:stroke;
    cursor:pointer;
}

.connector-label{
    pointer-events:none;
}

aside{
    overflow:auto;
    min-width:0;
    padding:12px;
    background:#1b1d20;
    border-left:1px solid #383c42;
}

section{
    padding-bottom:13px;
    margin-bottom:13px;
    border-bottom:1px solid #383c42;
}

section h2{
    font-size:15px;
    margin:0 0 9px;
}

.help{
    color:#9da3ad;
    font-size:12px;
    line-height:1.55;
}

.field{
    display:block;
    margin:7px 0;
    color:#c5c9cf;
    font-size:12px;
}

.field input,
.field select,
.field textarea{
    width:100%;
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

.list-item{
    margin:6px 0;
    padding:8px;
    border:1px solid #454a51;
    border-radius:6px;
    font-size:12px;
}

.list-item.selected{
    border-color:#368de8;
}

.actions{
    display:flex;
    gap:5px;
    margin-top:6px;
}

.actions button{
    padding:4px 7px;
    font-size:11px;
}

#timeline{
    padding:7px 12px;
    background:#1b1d20;
    border-top:1px solid #383c42;
}

#seek{
    width:100%;
}

#tracks{
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
    background:#2a2d32;
    border-radius:3px;
    overflow:hidden;
}

.track span{
    position:absolute;
    top:2px;
    height:10px;
    min-width:3px;
    border-radius:2px;
    cursor:pointer;
}

.track .comment{background:#42a5f5}
.track .box{background:#ef5350}
.track .skip{background:#dca52b}
.track .connector{background:#b17cff}

.footer{
    min-height:48px;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-wrap:wrap;
    gap:7px;
    padding:7px 10px;
    border-top:1px solid #383c42;
    background:#1b1d20;
}

#timeReadout{
    min-width:100px;
    text-align:center;
    font-variant-numeric:tabular-nums;
}

#contextMenu{
    position:fixed;
    z-index:500;
    display:none;
    min-width:190px;
    padding:5px;
    border:1px solid #565b63;
    border-radius:7px;
    background:#282b30;
    box-shadow:0 10px 30px #000b;
}

#contextMenu button{
    display:block;
    width:100%;
    text-align:left;
    border:0;
    background:transparent;
}

#contextMenu button:hover{
    background:#3a3f47;
}

#formatPanel{
    position:fixed;
    z-index:450;
    display:none;
    width:330px;
    max-width:calc(100vw - 20px);
    max-height:calc(100vh - 20px);
    overflow:auto;
    padding:14px;
    border:1px solid #555b64;
    border-radius:9px;
    background:#202328;
    box-shadow:0 15px 50px #000c;
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
    padding:10px;
    margin-bottom:10px;
    background:#16181b;
    border:1px solid #3f444b;
    border-radius:6px;
    text-align:center;
}

.checkbox-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}

.hidden{
    display:none!important;
}

@media(max-width:850px){
    .editor-main{
        display:flex;
        flex-direction:column;
    }

    .video-area{
        min-height:280px;
        flex:1;
    }

    aside{
        max-height:40vh;
        border-left:0;
        border-top:1px solid #383c42;
    }
}

@media(max-width:600px){
    .toolbar{
        height:auto;
        min-height:56px;
    }

    .footer button{
        font-size:11px;
        padding:6px 7px;
    }

    #formatPanel{
        width:300px;
    }
}
</style>
</head>

<body>

<header>
    <h1>画面共有録画・動画編集</h1>
    <span id="status">待機中</span>
</header>

<div id="message"></div>

<div class="recorder">
    <video id="preview" autoplay muted playsinline></video>
    <div id="placeholder">
        「録画開始」を押して共有する画面を選択してください
    </div>
</div>

<div class="toolbar">
    <label>
        <input id="systemAudio" type="checkbox">
        画面の音声
    </label>

    <label>
        <input id="microphone" type="checkbox">
        マイク
    </label>

    <span id="timer">00:00:00</span>

    <button id="start" class="primary">録画開始</button>
    <button id="pause" disabled>一時停止</button>
    <button id="stop" class="danger" disabled>停止</button>
    <button id="reset" disabled>リセット</button>
    <button id="openEditor" disabled>編集画面を開く</button>
</div>

<div id="editor">

    <div class="editor-main">

        <div class="video-area" id="videoArea">

            <div id="videoStage">

                <video id="recordedVideo" controls playsinline></video>

                <div id="overlay">

                    <svg id="connectors"
                         viewBox="0 0 100 100"
                         preserveAspectRatio="none"></svg>

                    <div id="objects"></div>

                </div>

            </div>

        </div>

        <aside>

            <section>
                <h2>動画編集</h2>

                <div class="help">
                    動画上に直接要素を配置できます。
                    要素はドラッグ移動・右下のハンドルでサイズ変更できます。
                    コメントはダブルクリックで直接編集できます。
                    要素や線を右クリックすると書式を変更できます。
                </div>

                <div style="margin-top:9px">
                    <button id="addComment" class="primary">
                        コメント追加
                    </button>

                    <button id="addBox" class="primary">
                        強調枠追加
                    </button>

                    <button id="connect">
                        線でつなぐ
                    </button>

                    <button id="deleteSelected" class="danger">
                        選択削除
                    </button>
                </div>
            </section>

            <section>
                <h2>選択中</h2>

                <div id="selectionHint" class="help">
                    動画上の要素または線を選択してください。
                </div>

                <div id="objectFields" class="hidden">

                    <label id="textField" class="field">
                        コメント内容
                        <textarea id="objectText"></textarea>
                    </label>

                    <div class="grid">

                        <label class="field">
                            開始
                            <input id="objectStart"
                                   type="number"
                                   min="0"
                                   step="0.1">
                        </label>

                        <label class="field">
                            終了
                            <input id="objectEnd"
                                   type="number"
                                   min="0"
                                   step="0.1">
                        </label>

                        <label class="field">
                            左 %
                            <input id="objectX"
                                   type="number"
                                   min="0"
                                   max="100"
                                   step="0.1">
                        </label>

                        <label class="field">
                            上 %
                            <input id="objectY"
                                   type="number"
                                   min="0"
                                   max="100"
                                   step="0.1">
                        </label>

                        <label class="field">
                            幅 %
                            <input id="objectW"
                                   type="number"
                                   min="1"
                                   max="100"
                                   step="0.1">
                        </label>

                        <label class="field">
                            高さ %
                            <input id="objectH"
                                   type="number"
                                   min="1"
                                   max="100"
                                   step="0.1">
                        </label>

                    </div>

                    <button id="applyObject" class="primary">
                        変更を反映
                    </button>

                </div>

            </section>

            <section>
                <h2>スキップ</h2>

                <div class="grid">

                    <label class="field">
                        開始
                        <input id="skipStart"
                               type="number"
                               min="0"
                               step="0.1"
                               value="0">
                    </label>

                    <label class="field">
                        終了
                        <input id="skipEnd"
                               type="number"
                               min="0"
                               step="0.1"
                               value="5">
                    </label>

                </div>

                <button id="skipFromCurrent">
                    現在位置を入力
                </button>

                <button id="addSkip" class="primary">
                    範囲追加
                </button>

                <div id="skipList"></div>
            </section>

            <section>
                <h2>要素</h2>
                <div id="objectList"></div>
            </section>

            <section>
                <h2>編集データ</h2>

                <button id="saveProject">
                    JSON保存
                </button>

                <button id="loadProject">
                    JSON読込
                </button>

                <button id="serverSave" class="success">
                    サーバー保存
                </button>

                <button id="serverLoad">
                    サーバー読込
                </button>

                <button id="clearEdits" class="danger">
                    編集内容を全削除
                </button>

                <input id="projectFile"
                       type="file"
                       accept="application/json,.json"
                       hidden>

                <div class="help" style="margin-top:8px">
                    ブラウザのlocalStorageにも自動保存します。
                    サーバー保存を実行すると
                    <code>data/projects/</code>
                    にJSONとして永続保存されます。
                </div>

                <label class="field">
                    プロジェクトID
                    <input id="projectId" readonly>
                </label>
            </section>

        </aside>
    </div>

    <div id="timeline">

        <input id="seek"
               type="range"
               min="0"
               max="0"
               step="0.01"
               value="0">

        <div id="tracks">

            <span>コメント</span>
            <div class="track" id="commentTrack"></div>

            <span>強調枠</span>
            <div class="track" id="boxTrack"></div>

            <span>接続線</span>
            <div class="track" id="connectorTrack"></div>

            <span>スキップ</span>
            <div class="track" id="skipTrack"></div>

        </div>

    </div>

    <div class="footer">

        <span id="timeReadout">
            00:00 / 00:00
        </span>

        <button id="back5">5秒戻る</button>
        <button id="forward5">5秒進む</button>

        <button id="downloadWebm">
            WebMダウンロード
        </button>

        <button id="downloadMp4" class="success">
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

        <div class="format-preview" id="formatPreview">
            プレビュー
        </div>

        <label class="field">
            文字色
            <input id="fmtColor" type="color" value="#ffffff">
        </label>

        <label class="field">
            背景色
            <input id="fmtBackground" type="color" value="#111111">
        </label>

        <label class="field">
            フォントサイズ(px)
            <input id="fmtFontSize"
                   type="number"
                   min="6"
                   max="200"
                   step="1"
                   value="18">
        </label>

        <label class="field">
            フォント
            <select id="fmtFontFamily">
                <option value="system-ui">システム標準</option>
                <option value="sans-serif">Sans Serif</option>
                <option value="serif">Serif</option>
                <option value="monospace">Monospace</option>
                <option value="'Noto Sans JP',sans-serif">Noto Sans JP</option>
            </select>
        </label>

        <div class="checkbox-row">

            <label>
                <input id="fmtBold" type="checkbox">
                太字
            </label>

            <label>
                <input id="fmtItalic" type="checkbox">
                斜体
            </label>

        </div>

        <label class="field">
            背景透明度
            <input id="fmtBgOpacity"
                   type="range"
                   min="0"
                   max="1"
                   step="0.01"
                   value="0.85">
        </label>

        <label class="field">
            枠線色
            <input id="fmtBorderColor"
                   type="color"
                   value="#ffffff">
        </label>

        <label class="field">
            枠線幅(px)
            <input id="fmtBorderWidth"
                   type="number"
                   min="0"
                   max="30"
                   step="1"
                   value="1">
        </label>

        <label class="field">
            角丸(px)
            <input id="fmtRadius"
                   type="number"
                   min="0"
                   max="100"
                   step="1"
                   value="6">
        </label>

        <label class="field">
            内側余白(px)
            <input id="fmtPadding"
                   type="number"
                   min="0"
                   max="100"
                   step="1"
                   value="8">
        </label>

    </div>

    <div id="formatConnector" class="hidden">

        <label class="field">
            線色
            <input id="lineColor"
                   type="color"
                   value="#ffffff">
        </label>

        <label class="field">
            線幅(px)
            <input id="lineWidth"
                   type="number"
                   min="1"
                   max="30"
                   step="1"
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

        <button id="formatApply" class="primary">
            適用
        </button>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/@ffmpeg/ffmpeg@0.12.10/dist/umd/ffmpeg.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@ffmpeg/util@0.12.1/dist/umd/index.js"></script>

<script>
"use strict";

const $ = id => document.getElementById(id);

const video = $("recordedVideo");
const stage = $("videoStage");

const STORAGE_KEY = "screen-recorder-editor-v2";
const API_URL = location.pathname;

const state = {
    displayStream:null,
    microphoneStream:null,
    audioContext:null,
    recorder:null,
    chunks:[],
    blob:null,
    url:null,

    startedAt:0,
    elapsedBeforePause:0,
    timerId:null,

    items:[],
    connectors:[],
    skips:[],

    selected:null,
    connectMode:false,
    connectFrom:null,

    contextPoint:{x:10,y:10},

    projectId:"",
    ffmpeg:null,
    converting:false,
    lastSkipAt:-1,

    formatTarget:null
};

function uid(){
    if(window.crypto && crypto.randomUUID){
        return crypto.randomUUID();
    }

    return "id-" +
        Date.now().toString(36) +
        "-" +
        Math.random().toString(36).slice(2);
}

function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}

function duration(){
    return Number.isFinite(video.duration) ? video.duration : 0;
}

function notify(message,ok=false){
    const box=$("message");

    box.textContent=message;
    box.classList.toggle("ok",ok);
    box.style.display="block";

    clearTimeout(notify.timer);

    notify.timer=setTimeout(()=>{
        box.style.display="none";
    },5000);
}

function formatTime(sec,hours=false){
    sec=Math.max(0,Math.floor(Number(sec)||0));

    const h=Math.floor(sec/3600);
    const m=Math.floor((sec%3600)/60);
    const s=sec%60;

    if(hours){
        return [h,m,s]
            .map(v=>String(v).padStart(2,"0"))
            .join(":");
    }

    return [
        Math.floor(sec/60),
        s
    ]
    .map(v=>String(v).padStart(2,"0"))
    .join(":");
}

function fileStamp(){
    const d=new Date();

    return [
        d.getFullYear(),
        String(d.getMonth()+1).padStart(2,"0"),
        String(d.getDate()).padStart(2,"0"),
        "-",
        String(d.getHours()).padStart(2,"0"),
        String(d.getMinutes()).padStart(2,"0"),
        String(d.getSeconds()).padStart(2,"0")
    ].join("");
}

function download(blob,name){
    const url=URL.createObjectURL(blob);
    const a=document.createElement("a");

    a.href=url;
    a.download=name;

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(()=>{
        URL.revokeObjectURL(url);
    },60000);
}

function updateRecordingButtons(recording=false){
    $("start").disabled=recording;
    $("pause").disabled=!recording;
    $("stop").disabled=!recording;
    $("reset").disabled=recording || !state.blob;
    $("openEditor").disabled=recording || !state.blob;
}

function stopStreams(){
    [
        state.displayStream,
        state.microphoneStream
    ].forEach(stream=>{
        stream?.getTracks().forEach(track=>track.stop());
    });

    state.displayStream=null;
    state.microphoneStream=null;

    $("preview").srcObject=null;

    if(state.audioContext){
        state.audioContext.close().catch(()=>{});
        state.audioContext=null;
    }
}

function updateTimer(){
    const active=state.startedAt
        ? Date.now()-state.startedAt
        : 0;

    $("timer").textContent=
        formatTime(
            (state.elapsedBeforePause+active)/1000,
            true
        );
}

function beginTimer(){
    state.startedAt=Date.now();

    clearInterval(state.timerId);

    state.timerId=setInterval(updateTimer,250);
}

function freezeTimer(){
    if(state.startedAt){
        state.elapsedBeforePause+=
            Date.now()-state.startedAt;
    }

    state.startedAt=0;

    clearInterval(state.timerId);

    state.timerId=null;

    updateTimer();
}

function recordingMime(){
    return [
        "video/webm;codecs=vp9,opus",
        "video/webm;codecs=vp8,opus",
        "video/webm;codecs=vp9",
        "video/webm;codecs=vp8",
        "video/webm"
    ].find(type=>{
        return MediaRecorder.isTypeSupported(type);
    });
}

async function recordingStream(){
    const stream=new MediaStream(
        state.displayStream.getVideoTracks()
    );

    const tracks=[
        ...(
            $("systemAudio").checked
                ? state.displayStream.getAudioTracks()
                : []
        ),
        ...(state.microphoneStream?.getAudioTracks()||[])
    ];

    if(tracks.length===1){
        stream.addTrack(tracks[0]);
    }

    if(tracks.length>1){
        const context=new AudioContext();

        state.audioContext=context;

        const destination=
            context.createMediaStreamDestination();

        tracks.forEach(track=>{
            const source=
                context.createMediaStreamSource(
                    new MediaStream([track])
                );

            source.connect(destination);
        });

        stream.addTrack(
            destination.stream.getAudioTracks()[0]
        );

        await context.resume();
    }

    return stream;
}

$("start").onclick=async()=>{
    try{
        if(
            !navigator.mediaDevices?.getDisplayMedia ||
            !window.MediaRecorder
        ){
            throw new Error(
                "このブラウザでは画面録画を利用できません。HTTPSまたはlocalhostで開いてください。"
            );
        }

        const mimeType=recordingMime();

        if(!mimeType){
            throw new Error(
                "このブラウザはWebM録画に対応していません。"
            );
        }

        $("start").disabled=true;

        state.displayStream=
            await navigator.mediaDevices.getDisplayMedia({
                video:true,
                audio:$("systemAudio").checked
            });

        if($("microphone").checked){
            state.microphoneStream=
                await navigator.mediaDevices.getUserMedia({
                    audio:true
                });
        }

        const stream=await recordingStream();

        state.chunks=[];
        state.blob=null;
        state.elapsedBeforePause=0;

        state.recorder=
            new MediaRecorder(stream,{mimeType});

        state.recorder.ondataavailable=e=>{
            if(e.data?.size){
                state.chunks.push(e.data);
            }
        };

        state.recorder.onstop=()=>{
            freezeTimer();
            stopStreams();

            if(!state.chunks.length){
                notify("録画データがありません。");
                updateRecordingButtons();
                return;
            }

            state.blob=new Blob(
                state.chunks,
                {
                    type:
                        state.recorder.mimeType ||
                        "video/webm"
                }
            );

            if(state.url){
                URL.revokeObjectURL(state.url);
            }

            state.url=
                URL.createObjectURL(state.blob);

            video.src=state.url;
            video.load();

            $("status").textContent="録画終了";

            updateRecordingButtons();

            $("editor").style.display="flex";
        };

        state.recorder.onerror=()=>{
            notify("録画中にエラーが発生しました。");
            stopRecording();
        };

        state.displayStream
            .getVideoTracks()[0]
            .addEventListener(
                "ended",
                stopRecording,
                {once:true}
            );

        $("preview").srcObject=state.displayStream;
        $("preview").style.display="block";
        $("placeholder").style.display="none";

        state.recorder.start(1000);

        beginTimer();

        $("status").textContent="録画中";

        updateRecordingButtons(true);

    }catch(error){
        stopStreams();

        state.recorder=null;

        $("start").disabled=false;

        notify(
            error.message ||
            "録画を開始できませんでした。"
        );
    }
};

function stopRecording(){
    if(
        !state.recorder ||
        state.recorder.state==="inactive"
    ){
        return;
    }

    state.recorder.stop();

    freezeTimer();

    $("pause").disabled=true;
    $("stop").disabled=true;

    $("status").textContent="録画を保存中";
}

$("stop").onclick=stopRecording;

$("pause").onclick=()=>{
    if(!state.recorder) return;

    if(state.recorder.state==="recording"){
        state.recorder.pause();
        freezeTimer();

        $("pause").textContent="再開";
        $("status").textContent="一時停止中";

    }else if(state.recorder.state==="paused"){
        state.recorder.resume();
        beginTimer();

        $("pause").textContent="一時停止";
        $("status").textContent="録画中";
    }
};

$("reset").onclick=()=>{
    if(!confirm(
        "現在の録画と編集内容を削除して新しく録画しますか？"
    )){
        return;
    }

    video.pause();

    if(state.url){
        URL.revokeObjectURL(state.url);
    }

    state.url=null;
    state.blob=null;
    state.chunks=[];
    state.recorder=null;

    video.removeAttribute("src");
    video.load();

    $("preview").style.display="none";
    $("placeholder").style.display="block";
    $("editor").style.display="none";

    $("timer").textContent="00:00:00";
    $("pause").textContent="一時停止";
    $("status").textContent="待機中";

    state.items=[];
    state.connectors=[];
    state.skips=[];
    state.selected=null;
    state.connectMode=false;
    state.connectFrom=null;

    localStorage.removeItem(STORAGE_KEY);

    updateRecordingButtons();

    render();
};

$("openEditor").onclick=()=>{
    $("editor").style.display="flex";
    requestAnimationFrame(render);
};

$("closeEditor").onclick=()=>{
    video.pause();
    $("editor").style.display="none";
};

$("downloadWebm").onclick=()=>{
    if(!state.blob){
        notify("録画データがありません。");
        return;
    }

    download(
        state.blob,
        `screen-recording-${fileStamp()}.webm`
    );
};

async function getFFmpeg(){
    if(state.ffmpeg){
        return state.ffmpeg;
    }

    if(
        !window.FFmpeg?.FFmpeg ||
        !window.FFmpegUtil?.toBlobURL
    ){
        throw new Error(
            "MP4変換用ライブラリを読み込めません。"
        );
    }

    const ffmpeg=
        new window.FFmpeg.FFmpeg();

    const toBlobURL=
        window.FFmpegUtil.toBlobURL;

    const base=
        "https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.6/dist/umd";

    ffmpeg.on("progress",({progress})=>{
        $("status").textContent=
            `MP4変換中 ${Math.round(
                clamp(progress,0,1)*100
            )}%`;
    });

    await ffmpeg.load({
        coreURL:
            await toBlobURL(
                `${base}/ffmpeg-core.js`,
                "text/javascript"
            ),
        wasmURL:
            await toBlobURL(
                `${base}/ffmpeg-core.wasm`,
                "application/wasm"
            ),
        workerURL:
            await toBlobURL(
                `${base}/ffmpeg-core.worker.js`,
                "text/javascript"
            )
    });

    state.ffmpeg=ffmpeg;

    return ffmpeg;
}

$("downloadMp4").onclick=async()=>{
    if(!state.blob || state.converting){
        return;
    }

    state.converting=true;
    $("downloadMp4").disabled=true;

    try{
        $("status").textContent=
            "MP4変換準備中";

        const ffmpeg=await getFFmpeg();

        await ffmpeg.writeFile(
            "input.webm",
            await window.FFmpegUtil.fetchFile(
                state.blob
            )
        );

        const code=await ffmpeg.exec([
            "-i","input.webm",
            "-c:v","libx264",
            "-preset","veryfast",
            "-crf","23",
            "-c:a","aac",
            "-b:a","128k",
            "-movflags","+faststart",
            "output.mp4"
        ]);

        if(code!==0){
            throw new Error(
                "動画変換が完了しませんでした。"
            );
        }

        const data=
            await ffmpeg.readFile("output.mp4");

        download(
            new Blob([data],{
                type:"video/mp4"
            }),
            `screen-recording-${fileStamp()}.mp4`
        );

        await Promise.allSettled([
            ffmpeg.deleteFile("input.webm"),
            ffmpeg.deleteFile("output.mp4")
        ]);

        notify(
            "MP4をダウンロードしました。",
            true
        );

    }catch(error){
        notify(
            `MP4変換に失敗しました。${
                error.message||""
            }`
        );

    }finally{
        $("status").textContent="録画終了";
        $("downloadMp4").disabled=false;
        state.converting=false;
    }
};

/* =========================================================
 * 編集データ
 * ======================================================= */

function newProjectId(){
    return "project-" +
        uid().replace(/-/g,"");
}

function projectData(){
    return {
        version:2,
        projectId:state.projectId,
        duration:duration(),
        items:state.items,
        connectors:state.connectors,
        skips:state.skips
    };
}

function saveLocal(){
    try{
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify(projectData())
        );
    }catch{
        notify(
            "ブラウザへの自動保存に失敗しました。JSONまたはサーバー保存を利用してください。"
        );
    }
}

function restoreLocal(){
    try{
        const raw=
            localStorage.getItem(STORAGE_KEY);

        if(!raw){
            return;
        }

        const data=JSON.parse(raw);

        validateProject(data,false);

        state.projectId=
            data.projectId ||
            newProjectId();

        state.items=
            structuredClone(data.items);

        state.connectors=
            structuredClone(data.connectors);

        state.skips=
            structuredClone(data.skips);

        $("projectId").value=
            state.projectId;

    }catch{
        localStorage.removeItem(STORAGE_KEY);
    }
}

function validateProject(data,strictDuration=true){
    if(!data || typeof data!=="object"){
        throw new Error(
            "編集データの形式が正しくありません。"
        );
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

    const max=duration();

    if(
        strictDuration &&
        max &&
        data.duration &&
        Math.abs(
            Number(data.duration)-max
        )>1
    ){
        throw new Error(
            "保存時と動画の長さが異なります。"
        );
    }

    const ids=new Set();

    for(const item of data.items){

        if(
            !item ||
            typeof item.id!=="string" ||
            ids.has(item.id) ||
            !["comment","box"].includes(item.type)
        ){
            throw new Error(
                "要素データが不正です。"
            );
        }

        const values=[
            item.start,
            item.end,
            item.x,
            item.y,
            item.w,
            item.h
        ];

        if(values.some(v=>!Number.isFinite(Number(v)))){
            throw new Error(
                "要素の数値データが不正です。"
            );
        }

        if(
            Number(item.start)<0 ||
            Number(item.end)<=Number(item.start) ||
            Number(item.x)<0 ||
            Number(item.y)<0 ||
            Number(item.w)<1 ||
            Number(item.h)<1 ||
            Number(item.x)+Number(item.w)>100 ||
            Number(item.y)+Number(item.h)>100
        ){
            throw new Error(
                "要素の位置・サイズ・時間が不正です。"
            );
        }

        if(
            item.type==="comment" &&
            typeof item.text!=="string"
        ){
            throw new Error(
                "コメント内容が不正です。"
            );
        }

        ids.add(item.id);
    }

    for(const line of data.connectors){

        if(
            !line ||
            typeof line.id!=="string" ||
            !ids.has(line.from) ||
            !ids.has(line.to)
        ){
            throw new Error(
                "接続線データが不正です。"
            );
        }
    }

    for(const range of data.skips){
        if(
            !range ||
            !Number.isFinite(Number(range.start)) ||
            !Number.isFinite(Number(range.end)) ||
            Number(range.end)<=Number(range.start)
        ){
            throw new Error(
                "スキップ範囲が不正です。"
            );
        }
    }

    return true;
}

function applyProject(data){
    validateProject(data);

    state.projectId=
        data.projectId ||
        newProjectId();

    state.items=
        structuredClone(data.items);

    state.connectors=
        structuredClone(data.connectors);

    state.skips=
        structuredClone(data.skips);

    state.selected=null;
    state.connectMode=false;
    state.connectFrom=null;

    $("projectId").value=
        state.projectId;

    $("connect").textContent=
        "線でつなぐ";

    render();
    saveLocal();
}

$("saveProject").onclick=()=>{
    const data=projectData();

    download(
        new Blob(
            [JSON.stringify(data,null,2)],
            {type:"application/json"}
        ),
        `screen-recording-edits-${fileStamp()}.json`
    );
};

$("loadProject").onclick=()=>{
    $("projectFile").click();
};

$("projectFile").onchange=async event=>{
    const file=event.target.files?.[0];

    if(!file){
        return;
    }

    try{
        const data=
            JSON.parse(await file.text());

        applyProject(data);

        notify(
            "編集内容を読み込みました。",
            true
        );

    }catch(error){
        notify(
            `読み込みに失敗しました。${
                error.message||""
            }`
        );

    }finally{
        event.target.value="";
    }
};

$("serverSave").onclick=async()=>{
    try{
        if(!state.projectId){
            state.projectId=newProjectId();
        }

        const data=projectData();

        $("status").textContent=
            "サーバー保存中";

        const response=await fetch(
            `${API_URL}?api=save`,
            {
                method:"POST",
                headers:{
                    "Content-Type":
                        "application/json"
                },
                body:JSON.stringify(data)
            }
        );

        const result=
            await response.json();

        if(!response.ok || !result.ok){
            throw new Error(
                result.message ||
                "サーバー保存に失敗しました。"
            );
        }

        state.projectId=result.projectId;

        $("projectId").value=
            state.projectId;

        saveLocal();

        notify(
            `サーバーへ保存しました。\nID: ${state.projectId}`,
            true
        );

    }catch(error){
        notify(
            `サーバー保存に失敗しました。\n${
                error.message||""
            }`
        );

    }finally{
        $("status").textContent="録画終了";
    }
};

$("serverLoad").onclick=async()=>{
    const id=
        $("projectId").value.trim();

    if(!id){
        return notify(
            "プロジェクトIDを入力してください。"
        );
    }

    try{
        $("status").textContent=
            "サーバーから読み込み中";

        const response=await fetch(
            `${API_URL}?api=load&id=${
                encodeURIComponent(id)
            }`
        );

        const result=
            await response.json();

        if(!response.ok || !result.ok){
            throw new Error(
                result.message ||
                "サーバーから読み込めませんでした。"
            );
        }

        applyProject(result.project);

        notify(
            "サーバーから編集内容を読み込みました。",
            true
        );

    }catch(error){
        notify(
            `サーバー読込に失敗しました。\n${
                error.message||""
            }`
        );

    }finally{
        $("status").textContent="録画終了";
    }
};

$("clearEdits").onclick=()=>{
    if(!confirm(
        "コメント・枠・線・スキップをすべて削除しますか？"
    )){
        return;
    }

    state.items=[];
    state.connectors=[];
    state.skips=[];
    state.selected=null;
    state.connectMode=false;
    state.connectFrom=null;

    $("connect").textContent=
        "線でつなぐ";

    render();
    saveLocal();
};

/* =========================================================
 * 要素
 * ======================================================= */

function currentSelection(){
    return state.items.find(
        item=>item.id===state.selected
    ) ||
    state.connectors.find(
        line=>line.id===state.selected
    ) ||
    null;
}

function addItem(
    type,
    position={x:10,y:10}
){
    const start=Math.min(
        video.currentTime||0,
        Math.max(0,duration()-0.1)
    );

    const end=Math.min(
        duration()||start+3,
        start+3
    );

    if(end<=start){
        notify(
            "動画の終了位置には要素を追加できません。"
        );
        return;
    }

    const item={
        id:uid(),
        type,
        start,
        end,
        x:clamp(position.x,0,90),
        y:clamp(position.y,0,90),
        w:type==="comment"?28:25,
        h:type==="comment"?13:20,

        text:type==="comment"
            ? "コメント"
            : "",

        style:{
            color:"#ffffff",
            background:"#111111",
            bgOpacity:0.86,
            fontSize:18,
            fontFamily:"system-ui",
            bold:false,
            italic:false,
            borderColor:
                type==="comment"
                    ? "#ffffff"
                    : "#ff453a",
            borderWidth:
                type==="comment"
                    ? 1
                    : 3,
            radius:
                type==="comment"
                    ? 6
                    : 0,
            padding:
                type==="comment"
                    ? 8
                    : 0
        }
    };

    state.items.push(item);
    state.selected=item.id;

    video.pause();

    render();
    saveLocal();
}

$("addComment").onclick=()=>{
    addItem("comment");
};

$("addBox").onclick=()=>{
    addItem("box");
};

function selectItem(item){
    if(
        state.connectMode &&
        state.items.includes(item)
    ){
        if(!state.connectFrom){
            state.connectFrom=item.id;
            state.selected=item.id;

            notify(
                "次に接続先の要素をクリックしてください。",
                true
            );

        }else if(
            state.connectFrom!==item.id
        ){
            const from=
                state.items.find(
                    x=>x.id===state.connectFrom
                );

            const to=item;

            if(!from){
                state.connectFrom=null;
                return;
            }

            if(
                state.connectors.some(line=>
                    (
                        line.from===from.id &&
                        line.to===to.id
                    ) ||
                    (
                        line.from===to.id &&
                        line.to===from.id
                    )
                )
            ){
                notify(
                    "この2つの要素はすでに接続されています。"
                );
                return;
            }

            const start=
                Math.max(from.start,to.start);

            const end=
                Math.min(from.end,to.end);

            if(end<=start){
                notify(
                    "表示時間が重なる要素同士を接続してください。"
                );
                return;
            }

            const line={
                id:uid(),
                from:from.id,
                to:to.id,
                start,
                end,

                fromPoint:
                    chooseBestPoint(from,to),

                toPoint:
                    chooseBestPoint(to,from),

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
            state.connectFrom=null;
            state.connectMode=false;

            $("connect").textContent=
                "線でつなぐ";

            saveLocal();
        }

    }else{
        state.selected=item.id;
    }

    render();
}

function chooseBestPoint(from,to){
    const fx=from.x+from.w/2;
    const fy=from.y+from.h/2;

    const tx=to.x+to.w/2;
    const ty=to.y+to.h/2;

    const dx=tx-fx;
    const dy=ty-fy;

    if(Math.abs(dx)>Math.abs(dy)){
        return dx>=0 ? "e" : "w";
    }

    return dy>=0 ? "s" : "n";
}

$("connect").onclick=()=>{
    state.connectMode=!state.connectMode;
    state.connectFrom=null;

    $("connect").textContent=
        state.connectMode
            ? "接続をキャンセル"
            : "線でつなぐ";

    notify(
        state.connectMode
            ? "接続元→接続先の順番で要素をクリックしてください。"
            : "接続をキャンセルしました。",
        true
    );
};

function deleteSelected(){
    if(!state.selected){
        notify("削除する項目を選択してください。");
        return;
    }

    const selected=state.selected;

    state.items=
        state.items.filter(
            item=>item.id!==selected
        );

    state.connectors=
        state.connectors.filter(line=>{
            return line.id!==selected &&
                state.items.some(
                    item=>item.id===line.from
                ) &&
                state.items.some(
                    item=>item.id===line.to
                );
        });

    state.selected=null;

    render();
    saveLocal();
}

$("deleteSelected").onclick=
    deleteSelected;

/* =========================================================
 * 位置・サイズ
 * ======================================================= */

function fitStage(){
    const area=$("videoArea");

    const vw=video.videoWidth||16;
    const vh=video.videoHeight||9;

    if(!area.clientWidth || !area.clientHeight){
        return;
    }

    const scale=Math.min(
        area.clientWidth/vw,
        area.clientHeight/vh
    );

    stage.style.width=
        `${Math.max(1,vw*scale)}px`;

    stage.style.height=
        `${Math.max(1,vh*scale)}px`;

    $("connectors")
        .setAttribute(
            "viewBox",
            "0 0 100 100"
        );
}

if(window.ResizeObserver){
    new ResizeObserver(fitStage)
        .observe($("videoArea"));
}

function dragOrResize(
    event,
    item,
    element,
    resizing
){
    if(event.button!==0){
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    video.pause();

    state.selected=item.id;

    const origin={
        clientX:event.clientX,
        clientY:event.clientY,
        x:item.x,
        y:item.y,
        w:item.w,
        h:item.h
    };

    const pointerTarget=event.currentTarget;
    const pointerId=event.pointerId;

    pointerTarget.setPointerCapture(
        pointerId
    );

    const onMove=moveEvent=>{
        if(moveEvent.pointerId!==pointerId){
            return;
        }

        const rect=
            stage.getBoundingClientRect();

        if(!rect.width || !rect.height){
            return;
        }

        const dx=
            (moveEvent.clientX-origin.clientX) /
            rect.width*100;

        const dy=
            (moveEvent.clientY-origin.clientY) /
            rect.height*100;

        if(resizing){

            item.w=clamp(
                origin.w+dx,
                3,
                100-item.x
            );

            item.h=clamp(
                origin.h+dy,
                3,
                100-item.y
            );

            element.style.width=
                `${item.w}%`;

            element.style.height=
                `${item.h}%`;

        }else{

            item.x=clamp(
                origin.x+dx,
                0,
                100-item.w
            );

            item.y=clamp(
                origin.y+dy,
                0,
                100-item.h
            );

            element.style.left=
                `${item.x}%`;

            element.style.top=
                `${item.y}%`;
        }

        drawConnectors();
    };

    const onEnd=endEvent=>{
        if(endEvent.pointerId!==pointerId){
            return;
        }

        pointerTarget.removeEventListener(
            "pointermove",
            onMove
        );

        pointerTarget.removeEventListener(
            "pointerup",
            onEnd
        );

        pointerTarget.removeEventListener(
            "pointercancel",
            onEnd
        );

        render();
        saveLocal();
    };

    pointerTarget.addEventListener(
        "pointermove",
        onMove
    );

    pointerTarget.addEventListener(
        "pointerup",
        onEnd
    );

    pointerTarget.addEventListener(
        "pointercancel",
        onEnd
    );
}

/* =========================================================
 * 接点
 * ======================================================= */

function pointPosition(item,point){
    const x=item.x;
    const y=item.y;
    const w=item.w;
    const h=item.h;

    const positions={
        n:[x+w/2,y],
        ne:[x+w,y],
        e:[x+w,y+h/2],
        se:[x+w,y+h],
        s:[x+w/2,y+h],
        sw:[x,y+h],
        w:[x,y+h/2],
        nw:[x,y]
    };

    return positions[point] ||
        positions.e;
}

/* =========================================================
 * SVGマーカー
 * ======================================================= */

function ensureSvgDefs(){
    const svg=$("connectors");

    let defs=svg.querySelector("defs");

    if(!defs){
        defs=document.createElementNS(
            "http://www.w3.org/2000/svg",
            "defs"
        );

        svg.prepend(defs);
    }

    defs.innerHTML=`
        <marker id="arrow-${CSS.escape(
            state.projectId||"default"
        )}"
                markerWidth="8"
                markerHeight="8"
                refX="7"
                refY="4"
                orient="auto"
                markerUnits="strokeWidth">
            <path d="M0,0 L8,4 L0,8 Z"
                  fill="context-stroke"/>
        </marker>

        <marker id="circle-${CSS.escape(
            state.projectId||"default"
        )}"
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

function markerId(type){
    return `${type}-${CSS.escape(
        state.projectId||"default"
    )}`;
}

/* =========================================================
 * 接続線描画
 * ======================================================= */

function dashArray(type,width){
    if(type==="dashed"){
        return `${width*4} ${width*3}`;
    }

    if(type==="dotted"){
        return `1 ${width*3}`;
    }

    return "none";
}

function drawConnectors(){
    const svg=$("connectors");

    svg.replaceChildren();

    ensureSvgDefs();

    const t=video.currentTime||0;

    state.connectors.forEach(line=>{

        if(
            t<line.start ||
            t>=line.end
        ){
            return;
        }

        const a=
            state.items.find(
                item=>item.id===line.from
            );

        const b=
            state.items.find(
                item=>item.id===line.to
            );

        if(!a || !b){
            return;
        }

        if(
            t<a.start ||
            t>=a.end ||
            t<b.start ||
            t>=b.end
        ){
            return;
        }

        const p1=
            pointPosition(
                a,
                line.fromPoint||"e"
            );

        const p2=
            pointPosition(
                b,
                line.toPoint||"w"
            );

        const visibleStyle={
            color:line.style?.color||"#ffffff",
            width:Number(line.style?.width)||3,
            dash:line.style?.dash||"solid"
        };

        const hit=
            document.createElementNS(
                "http://www.w3.org/2000/svg",
                "line"
            );

        hit.setAttribute("x1",p1[0]);
        hit.setAttribute("y1",p1[1]);
        hit.setAttribute("x2",p2[0]);
        hit.setAttribute("y2",p2[1]);

        hit.classList.add("connector-hit");

        hit.addEventListener(
            "click",
            event=>{
                event.stopPropagation();

                state.selected=line.id;

                render();
            }
        );

        svg.appendChild(hit);

        const node=
            document.createElementNS(
                "http://www.w3.org/2000/svg",
                "line"
            );

        node.setAttribute("x1",p1[0]);
        node.setAttribute("y1",p1[1]);
        node.setAttribute("x2",p2[0]);
        node.setAttribute("y2",p2[1]);

        node.classList.add("connector");

        if(state.selected===line.id){
            node.classList.add("selected");
        }

        node.setAttribute(
            "stroke",
            visibleStyle.color
        );

        node.setAttribute(
            "stroke-width",
            visibleStyle.width
        );

        node.setAttribute(
            "stroke-dasharray",
            dashArray(
                visibleStyle.dash,
                visibleStyle.width
            )
        );

        const startArrow=
            line.style?.startArrow||"none";

        const endArrow=
            line.style?.endArrow||"arrow";

        if(startArrow!=="none"){
            node.setAttribute(
                "marker-start",
                `url(#${markerId(startArrow)})`
            );
        }

        if(endArrow!=="none"){
            node.setAttribute(
                "marker-end",
                `url(#${markerId(endArrow)})`
            );
        }

        node.addEventListener(
            "click",
            event=>{
                event.stopPropagation();

                state.selected=line.id;

                render();
            }
        );

        svg.appendChild(node);
    });
}

/* =========================================================
 * 要素描画
 * ======================================================= */

function rgba(hex,opacity){
    hex=String(hex||"#000000")
        .replace("#","");

    if(hex.length===3){
        hex=hex
            .split("")
            .map(v=>v+v)
            .join("");
    }

    const n=parseInt(hex,16);

    if(Number.isNaN(n)){
        return `rgba(0,0,0,${opacity})`;
    }

    return `rgba(
        ${(n>>16)&255},
        ${(n>>8)&255},
        ${n&255},
        ${opacity}
    )`;
}

function applyObjectStyle(element,item){
    const s=item.style||{};

    if(item.type==="comment"){

        element.style.color=
            s.color||"#ffffff";

        element.style.background=
            rgba(
                s.background||"#111111",
                Number.isFinite(
                    Number(s.bgOpacity)
                )
                    ? Number(s.bgOpacity)
                    : 0.86
            );

        element.style.fontSize=
            `${Number(s.fontSize)||18}px`;

        element.style.fontFamily=
            s.fontFamily||"system-ui";

        element.style.fontWeight=
            s.bold ? "700" : "400";

        element.style.fontStyle=
            s.italic ? "italic" : "normal";

        element.style.border=
            `${Number(s.borderWidth)||0}px solid ${
                s.borderColor||"#ffffff"
            }`;

        element.style.borderRadius=
            `${Number(s.radius)||0}px`;

        element.style.padding=
            `${Number(s.padding)||0}px`;

    }else{

        element.style.background="transparent";

        element.style.border=
            `${Number(s.borderWidth)||3}px solid ${
                s.borderColor||"#ff453a"
            }`;

        element.style.borderRadius=
            `${Number(s.radius)||0}px`;
    }
}

function makeConnectionPoints(item,element){

    [
        "n",
        "ne",
        "e",
        "se",
        "s",
        "sw",
        "w",
        "nw"
    ].forEach(point=>{

        const el=
            document.createElement("div");

        el.className=
            "connection-point";

        el.dataset.point=point;

        el.title=
            `接点 ${point}`;

        el.addEventListener(
            "pointerdown",
            event=>{
                event.preventDefault();
                event.stopPropagation();

                if(
                    state.connectMode &&
                    state.connectFrom
                ){
                    state.selected=item.id;

                    selectItem(item);
                }
            }
        );

        element.appendChild(el);
    });
}

function drawObjects(){
    const container=$("objects");

    container.replaceChildren();

    const t=video.currentTime||0;

    state.items.forEach(item=>{

        if(
            t<item.start ||
            t>=item.end
        ){
            return;
        }

        const element=
            document.createElement("div");

        element.className=
            `edit-object ${item.type}`;

        if(state.selected===item.id){
            element.classList.add("selected");
        }

        element.style.left=
            `${item.x}%`;

        element.style.top=
            `${item.y}%`;

        element.style.width=
            `${item.w}%`;

        element.style.height=
            `${item.h}%`;

        applyObjectStyle(
            element,
            item
        );

        if(item.type==="comment"){
            element.textContent=
                item.text||"";
        }

        const handle=
            document.createElement("div");

        handle.className=
            "object-handle";

        handle.title=
            "ドラッグしてサイズ変更";

        handle.addEventListener(
            "pointerdown",
            event=>{
                dragOrResize(
                    event,
                    item,
                    element,
                    true
                );
            }
        );

        element.appendChild(handle);

        makeConnectionPoints(
            item,
            element
        );

        element.addEventListener(
            "pointerdown",
            event=>{
                if(
                    event.target===handle ||
                    event.target.classList.contains(
                        "connection-point"
                    )
                ){
                    return;
                }

                if(state.connectMode){
                    event.preventDefault();
                    event.stopPropagation();

                    selectItem(item);

                }else{
                    dragOrResize(
                        event,
                        item,
                        element,
                        false
                    );
                }
            }
        );

        element.addEventListener(
            "dblclick",
            event=>{
                if(item.type!=="comment"){
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                state.selected=item.id;

                editCommentDirectly(
                    item,
                    element
                );
            }
        );

        element.addEventListener(
            "contextmenu",
            event=>{
                event.preventDefault();
                event.stopPropagation();

                state.selected=item.id;

                showContextMenu(
                    event.clientX,
                    event.clientY,
                    item
                );
            }
        );

        container.appendChild(element);
    });
}

function editCommentDirectly(item,element){
    const current=item.text||"";

    const textarea=
        document.createElement("textarea");

    textarea.value=current;

    textarea.style.position="absolute";
    textarea.style.left="0";
    textarea.style.top="0";
    textarea.style.width="100%";
    textarea.style.height="100%";
    textarea.style.resize="none";
    textarea.style.border="0";
    textarea.style.outline="2px solid #42a5f5";
    textarea.style.background=
        "rgba(20,20,20,.96)";
    textarea.style.color=
        item.style?.color||"#fff";
    textarea.style.padding=
        `${Number(item.style?.padding)||8}px`;

    element.replaceChildren(textarea);

    textarea.focus();
    textarea.select();

    let finished=false;

    const finish=save=>{
        if(finished){
            return;
        }

        finished=true;

        if(save){
            const text=textarea.value;

            if(!text.trim()){
                notify(
                    "コメントを空にすることはできません。"
                );
            }else{
                item.text=text;
                saveLocal();
            }
        }

        render();
    };

    textarea.addEventListener(
        "keydown",
        event=>{
            if(
                event.key==="Enter" &&
                (event.ctrlKey||event.metaKey)
            ){
                event.preventDefault();
                finish(true);
            }

            if(event.key==="Escape"){
                event.preventDefault();
                finish(false);
            }
        }
    );

    textarea.addEventListener(
        "blur",
        ()=>finish(true)
    );
}

/* =========================================================
 * 選択状態
 * ======================================================= */

function updateSelection(){

    const selected=
        currentSelection();

    const object=
        selected &&
        state.items.includes(selected);

    const line=
        selected &&
        state.connectors.includes(selected);

    if(!selected){

        $("selectionHint").textContent=
            "動画上の要素または線を選択してください。";

        $("objectFields").classList.add(
            "hidden"
        );

        return;
    }

    if(line){

        $("selectionHint").textContent=
            "接続線を選択中。右クリックで線の書式・接点を変更できます。";

        $("objectFields").classList.add(
            "hidden"
        );

        return;
    }

    $("selectionHint").textContent=
        selected.type==="comment"
            ? "コメントを編集中"
            : "強調枠を編集中";

    $("objectFields").classList.remove(
        "hidden"
    );

    $("textField").classList.toggle(
        "hidden",
        selected.type!=="comment"
    );

    $("objectText").value=
        selected.text||"";

    $("objectStart").value=
        Number(selected.start).toFixed(2);

    $("objectEnd").value=
        Number(selected.end).toFixed(2);

    $("objectX").value=
        Number(selected.x).toFixed(2);

    $("objectY").value=
        Number(selected.y).toFixed(2);

    $("objectW").value=
        Number(selected.w).toFixed(2);

    $("objectH").value=
        Number(selected.h).toFixed(2);
}

function validRange(start,end){
    start=Number(start);
    end=Number(end);

    const max=duration();

    if(
        !Number.isFinite(start) ||
        !Number.isFinite(end) ||
        start<0 ||
        end<=start
    ){
        return null;
    }

    if(max && start>=max){
        return null;
    }

    return {
        start,
        end:max
            ? Math.min(end,max)
            : end
    };
}

$("applyObject").onclick=()=>{
    const item=currentSelection();

    if(
        !item ||
        !state.items.includes(item)
    ){
        return;
    }

    const range=validRange(
        $("objectStart").value,
        $("objectEnd").value
    );

    const x=Number($("objectX").value);
    const y=Number($("objectY").value);
    const w=Number($("objectW").value);
    const h=Number($("objectH").value);

    if(
        !range ||
        [x,y,w,h].some(v=>!Number.isFinite(v)) ||
        x<0 ||
        y<0 ||
        w<1 ||
        h<1 ||
        x+w>100 ||
        y+h>100
    ){
        notify(
            "時間・位置・サイズを正しく入力してください。"
        );
        return;
    }

    if(
        item.type==="comment" &&
        !$("objectText").value.trim()
    ){
        notify(
            "コメントを入力してください。"
        );
        return;
    }

    Object.assign(
        item,
        range,
        {x,y,w,h}
    );

    if(item.type==="comment"){
        item.text=
            $("objectText").value;
    }

    updateConnectorRanges(item.id);

    render();
    saveLocal();
};

function updateConnectorRanges(itemId){
    state.connectors=
        state.connectors
            .map(line=>{
                if(
                    line.from!==itemId &&
                    line.to!==itemId
                ){
                    return line;
                }

                const a=
                    state.items.find(
                        item=>item.id===line.from
                    );

                const b=
                    state.items.find(
                        item=>item.id===line.to
                    );

                if(!a || !b){
                    return null;
                }

                return {
                    ...line,
                    start:
                        Math.max(
                            a.start,
                            b.start
                        ),
                    end:
                        Math.min(
                            a.end,
                            b.end
                        )
                };
            })
            .filter(line=>
                line &&
                line.end>line.start
            );
}

/* =========================================================
 * 右クリックメニュー
 * ======================================================= */

function showContextMenu(
    x,
    y,
    target
){
    const menu=$("contextMenu");

    menu.dataset.targetId=
        target?.id||"";

    menu.dataset.targetType=
        state.items.includes(target)
            ? "object"
            : state.connectors.includes(target)
                ? "connector"
                : "stage";

    const isStage=
        menu.dataset.targetType==="stage";

    menu.querySelectorAll(
        "[data-action]"
    ).forEach(button=>{
        const action=button.dataset.action;

        button.classList.toggle(
            "hidden",
            isStage &&
            ![
                "comment",
                "box"
            ].includes(action)
        );

        if(
            !isStage &&
            [
                "comment",
                "box"
            ].includes(action)
        ){
            button.classList.add(
                "hidden"
            );
        }

        if(
            menu.dataset.targetType==="connector" &&
            [
                "edit",
                "duplicate"
            ].includes(action)
        ){
            button.classList.add(
                "hidden"
            );
        }
    });

    menu.style.display="block";

    const width=menu.offsetWidth;
    const height=menu.offsetHeight;

    menu.style.left=
        `${clamp(x,5,innerWidth-width-5)}px`;

    menu.style.top=
        `${clamp(y,5,innerHeight-height-5)}px`;
}

stage.addEventListener(
    "contextmenu",
    event=>{
        event.preventDefault();

        const rect=
            stage.getBoundingClientRect();

        state.contextPoint={
            x:clamp(
                (
                    event.clientX-
                    rect.left
                )/
                rect.width*100,
                0,
                90
            ),
            y:clamp(
                (
                    event.clientY-
                    rect.top
                )/
                rect.height*100,
                0,
                90
            )
        };

        const target=
            currentSelection();

        showContextMenu(
            event.clientX,
            event.clientY,
            target
        );
    }
);

$("contextMenu").onclick=event=>{
    const button=
        event.target.closest(
            "button[data-action]"
        );

    if(!button){
        return;
    }

    const action=
        button.dataset.action;

    const id=
        $("contextMenu").dataset.targetId;

    const type=
        $("contextMenu").dataset.targetType;

    const target=
        type==="object"
            ? state.items.find(
                item=>item.id===id
            )
            : type==="connector"
                ? state.connectors.find(
                    line=>line.id===id
                )
                : null;

    $("contextMenu").style.display=
        "none";

    if(action==="format" && target){
        openFormatPanel(target);
        return;
    }

    if(action==="delete" && target){
        state.selected=target.id;
        deleteSelected();
        return;
    }

    if(action==="edit" && target){
        state.selected=target.id;
        render();

        if(target.type==="comment"){
            const el=
                [...$("objects").children]
                    .find(el=>{
                        return el.classList.contains(
                            "comment"
                        );
                    });

            if(el){
                editCommentDirectly(
                    target,
                    el
                );
            }
        }

        return;
    }

    if(action==="duplicate" && target){
        const copy=
            structuredClone(target);

        copy.id=uid();

        copy.x=
            clamp(
                Number(copy.x)+3,
                0,
                100-copy.w
            );

        copy.y=
            clamp(
                Number(copy.y)+3,
                0,
                100-copy.h
            );

        state.items.push(copy);

        state.selected=copy.id;

        render();
        saveLocal();

        return;
    }

    if(action==="connect"){
        $("connect").click();
        return;
    }

    if(action==="comment"){
        addItem(
            "comment",
            state.contextPoint
        );
        return;
    }

    if(action==="box"){
        addItem(
            "box",
            state.contextPoint
        );
    }
};

document.addEventListener(
    "pointerdown",
    event=>{
        const menu=$("contextMenu");

        if(
            !menu.contains(event.target)
        ){
            menu.style.display="none";
        }
    }
);

/* =========================================================
 * 書式変更パネル
 * ======================================================= */

function openFormatPanel(target){

    state.formatTarget=target;

    const panel=$("formatPanel");

    const isConnector=
        state.connectors.includes(target);

    $("formatTitle").textContent=
        isConnector
            ? "接続線の書式変更"
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

    panel.style.display="block";

    const menu=$("contextMenu");

    const rect=
        menu.getBoundingClientRect();

    panel.style.left=
        `${clamp(
            rect.left,
            5,
            innerWidth-panel.offsetWidth-5
        )}px`;

    panel.style.top=
        `${clamp(
            rect.bottom+5,
            5,
            innerHeight-panel.offsetHeight-5
        )}px`;

    requestAnimationFrame(()=>{
        panel.style.left=
            `${clamp(
                rect.left,
                5,
                innerWidth-panel.offsetWidth-5
            )}px`;

        panel.style.top=
            `${clamp(
                rect.bottom+5,
                5,
                innerHeight-panel.offsetHeight-5
            )}px`;
    });

    updateFormatPreview();
}

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
        Number.isFinite(
            Number(s.bgOpacity)
        )
            ? Number(s.bgOpacity)
            : 0.86;

    $("fmtBorderColor").value=
        s.borderColor||"#ffffff";

    $("fmtBorderWidth").value=
        Number(s.borderWidth)||1;

    $("fmtRadius").value=
        Number(s.radius)||0;

    $("fmtPadding").value=
        Number(s.padding)||8;
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

    const preview=$("formatPreview");

    if(!state.formatTarget){
        return;
    }

    const item=state.formatTarget;

    if(state.connectors.includes(item)){
        return;
    }

    const bg=
        $("fmtBackground").value;

    const opacity=
        Number($("fmtBgOpacity").value);

    preview.textContent=
        item.type==="comment"
            ? (item.text||"コメント")
            : "強調枠";

    preview.style.color=
        $("fmtColor").value;

    preview.style.background=
        rgba(bg,opacity);

    preview.style.fontSize=
        `${Number(
            $("fmtFontSize").value
        )||18}px`;

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
        `${
            Number(
                $("fmtBorderWidth").value
            )||0
        }px solid ${
            $("fmtBorderColor").value
        }`;

    preview.style.borderRadius=
        `${
            Number(
                $("fmtRadius").value
            )||0
        }px`;
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
    const target=
        state.formatTarget;

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

    }else if(
        state.connectors.includes(target)
    ){

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

    $("formatPanel").style.display=
        "none";

    state.formatTarget=null;

    render();
    saveLocal();

    notify(
        "書式を変更しました。",
        true
    );
};

/* =========================================================
 * 一覧
 * ======================================================= */

function renderLists(){

    const objects=
        $("objectList");

    objects.replaceChildren();

    state.items.forEach(item=>{

        const row=
            document.createElement("div");

        row.className=
            "list-item"+
            (
                state.selected===item.id
                    ? " selected"
                    : ""
            );

        const label=
            document.createElement("div");

        label.textContent=
            `${
                item.type==="comment"
                    ? "コメント: "+(
                        item.text||""
                    )
                    : "強調枠"
            }　${
                formatTime(item.start)
            }～${
                formatTime(item.end)
            }`;

        row.appendChild(label);

        const actions=
            document.createElement("div");

        actions.className="actions";

        const jump=
            document.createElement("button");

        jump.textContent="選択・移動";

        jump.onclick=()=>{
            video.currentTime=
                item.start;

            state.selected=item.id;

            render();
        };

        const format=
            document.createElement("button");

        format.textContent="書式";

        format.onclick=()=>{
            state.selected=item.id;
            openFormatPanel(item);
            render();
        };

        const remove=
            document.createElement("button");

        remove.textContent="削除";

        remove.onclick=()=>{
            state.selected=item.id;
            deleteSelected();
        };

        actions.append(
            jump,
            format,
            remove
        );

        row.appendChild(actions);

        objects.appendChild(row);
    });

    state.connectors.forEach(line=>{

        const row=
            document.createElement("div");

        row.className=
            "list-item"+
            (
                state.selected===line.id
                    ? " selected"
                    : ""
            );

        const a=
            state.items.find(
                item=>item.id===line.from
            );

        const b=
            state.items.find(
                item=>item.id===line.to
            );

        row.textContent=
            `線: ${
                a?.text ||
                a?.type ||
                "要素"
            } → ${
                b?.text ||
                b?.type ||
                "要素"
            }`;

        const actions=
            document.createElement("div");

        actions.className="actions";

        const format=
            document.createElement("button");

        format.textContent="書式・接点";

        format.onclick=()=>{
            state.selected=line.id;
            openFormatPanel(line);
            render();
        };

        const remove=
            document.createElement("button");

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

        objects.appendChild(row);
    });

    const skips=$("skipList");

    skips.replaceChildren();

    state.skips.forEach(
        (range,index)=>{

            const row=
                document.createElement("div");

            row.className="list-item";

            row.textContent=
                `${formatTime(
                    range.start
                )}～${formatTime(
                    range.end
                )} `;

            const remove=
                document.createElement("button");

            remove.textContent="削除";

            remove.onclick=()=>{
                state.skips.splice(
                    index,
                    1
                );

                render();
                saveLocal();
            };

            row.appendChild(remove);

            skips.appendChild(row);
        }
    );
}

function renderTracks(){

    const max=duration();

    [
        [
            "commentTrack",
            state.items.filter(
                item=>item.type==="comment"
            ),
            "comment"
        ],
        [
            "boxTrack",
            state.items.filter(
                item=>item.type==="box"
            ),
            "box"
        ],
        [
            "connectorTrack",
            state.connectors,
            "connector"
        ],
        [
            "skipTrack",
            state.skips,
            "skip"
        ]
    ].forEach(
        ([trackId,data,cls])=>{

            const track=$(trackId);

            track.replaceChildren();

            if(!max){
                return;
            }

            data.forEach(entry=>{

                const marker=
                    document.createElement("span");

                marker.className=cls;

                marker.style.left=
                    `${clamp(
                        entry.start/max*100,
                        0,
                        100
                    )}%`;

                marker.style.width=
                    `${clamp(
                        (
                            entry.end-entry.start
                        )/max*100,
                        0,
                        100
                    )}%`;

                marker.title=
                    `${formatTime(
                        entry.start
                    )}～${formatTime(
                        entry.end
                    )}`;

                marker.onclick=()=>{
                    video.currentTime=
                        entry.start;

                    if(entry.id){
                        state.selected=
                            entry.id;
                    }

                    render();
                };

                track.appendChild(marker);
            });
        }
    );
}

/* =========================================================
 * render
 * ======================================================= */

function render(){
    fitStage();
    drawConnectors();
    drawObjects();
    renderLists();
    renderTracks();
    updateSelection();
}

/* =========================================================
 * 再生
 * ======================================================= */

function refreshPlayback(){

    const max=duration();
    const t=video.currentTime||0;

    $("seek").max=max;
    $("seek").value=t;

    $("timeReadout").textContent=
        `${formatTime(t)} / ${
            formatTime(max)
        }`;

    drawConnectors();
    drawObjects();

    if(video.paused){
        return;
    }

    const skip=
        state.skips.find(
            range=>
                t>=range.start &&
                t<range.end
        );

    if(
        skip &&
        Math.abs(
            state.lastSkipAt-t
        )>0.05
    ){
        state.lastSkipAt=t;
        video.currentTime=
            skip.end;
    }
}

video.addEventListener(
    "loadedmetadata",
    ()=>{
        fitStage();
        render();
        refreshPlayback();
    }
);

video.addEventListener(
    "timeupdate",
    refreshPlayback
);

video.addEventListener(
    "seeked",
    refreshPlayback
);

video.addEventListener(
    "play",
    ()=>{
        state.lastSkipAt=-1;
    }
);

$("seek").oninput=event=>{
    video.currentTime=
        Number(event.target.value);
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

$("skipFromCurrent").onclick=()=>{
    $("skipStart").value=
        video.currentTime.toFixed(1);

    $("skipEnd").value=
        Math.min(
            duration(),
            video.currentTime+5
        ).toFixed(1);
};

$("addSkip").onclick=()=>{
    const range=validRange(
        $("skipStart").value,
        $("skipEnd").value
    );

    if(!range){
        notify(
            "スキップの開始・終了時間を正しく入力してください。"
        );
        return;
    }

    state.skips.push(range);

    state.skips.sort(
        (a,b)=>a.start-b.start
    );

    render();
    saveLocal();
};

/* =========================================================
 * キーボード
 * ======================================================= */

document.addEventListener(
    "keydown",
    event=>{

        if(
            $("editor").style.display==="none"
        ){
            return;
        }

        if(event.key==="Escape"){

            $("contextMenu").style.display=
                "none";

            $("formatPanel").style.display=
                "none";

            state.connectMode=false;
            state.connectFrom=null;

            $("connect").textContent=
                "線でつなぐ";

            return;
        }

        const tag=
            document.activeElement?.tagName;

        if(
            (
                event.key==="Delete" ||
                event.key==="Backspace"
            ) &&
            ![
                "INPUT",
                "TEXTAREA",
                "SELECT"
            ].includes(tag) &&
            state.selected
        ){
            event.preventDefault();

            deleteSelected();
        }
    }
);

/* =========================================================
 * 初期化
 * ======================================================= */

(function init(){

    state.projectId=
        newProjectId();

    $("projectId").value=
        state.projectId;

    restoreLocal();

    updateRecordingButtons();

    render();

})();
</script>

</body>
</html>

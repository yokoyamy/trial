<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * PHP + Apache / 1ファイル版
 *
 * サーバー保存:
 *   ./data/projects/*.json
 *
 * 動画本体:
 *   ブラウザ IndexedDB
 *
 * 編集データ:
 *   PHPサーバー + IndexedDB
 */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';
const MAX_SERVER_PROJECTS = 20;
const APP_VERSION = 3;

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

function validId(string $id): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{8,80}$/', $id);
}

function projectPath(string $id): string {
    return PROJECT_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}

function readProjects(): array {
    if (!is_dir(PROJECT_DIR)) return [];
    $result = [];
    foreach (glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        $data = json_decode($raw ?: '', true);
        if (is_array($data) && isset($data['projectId'])) $result[] = $data;
    }
    usort($result, fn($a, $b) => strcmp((string)($b['savedAt'] ?? ''), (string)($a['savedAt'] ?? '')));
    return $result;
}

function projectSummary(array $p): array {
    return [
        'projectId' => (string)($p['projectId'] ?? ''),
        'name' => (string)($p['name'] ?? '名称未設定'),
        'videoName' => (string)($p['videoName'] ?? ''),
        'savedAt' => (string)($p['savedAt'] ?? ''),
        'version' => (int)($p['version'] ?? 1)
    ];
}

/* ---------------- PHP API ---------------- */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    if ($api === 'status') {
        jsonResponse([
            'ok' => true,
            'serverWritable' => ensureProjectDir(),
            'serverLimit' => MAX_SERVER_PROJECTS,
            'serverCount' => count(readProjects()),
            'php' => PHP_VERSION
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => array_map('projectSummary', readProjects()),
            'limit' => MAX_SERVER_PROJECTS
        ]);
    }

    if ($api === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['ok'=>false,'message'=>'POST only'],405);
        if (!ensureProjectDir()) {
            jsonResponse(['ok'=>false,'message'=>'data/projects に書き込めません。PHP/Apacheの書込権限を確認してください。'],500);
        }

        $payload = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($payload)) jsonResponse(['ok'=>false,'message'=>'JSONが不正です。'],400);

        $id = isset($payload['projectId']) ? (string)$payload['projectId'] : '';
        if ($id === '') $id = bin2hex(random_bytes(12));
        if (!validId($id)) jsonResponse(['ok'=>false,'message'=>'プロジェクトIDが不正です。'],400);

        $existing = is_file(projectPath($id));
        $projects = readProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok'=>false,
                'limit'=>true,
                'message'=>'サーバー保存上限に達しました。ローカル保存を使用してください。'
            ],409);
        }

        $payload['version'] = APP_VERSION;
        $payload['projectId'] = $id;
        $payload['savedAt'] = date('c');

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

        if ($json === false || @file_put_contents(projectPath($id), $json, LOCK_EX) === false) {
            jsonResponse(['ok'=>false,'message'=>'編集データを保存できませんでした。'],500);
        }

        jsonResponse([
            'ok'=>true,
            'projectId'=>$id,
            'savedAt'=>$payload['savedAt']
        ]);
    }

    if ($api === 'load') {
        $id = (string)($_GET['id'] ?? '');
        if (!validId($id)) jsonResponse(['ok'=>false,'message'=>'プロジェクトIDが不正です。'],400);

        $file = projectPath($id);
        if (!is_file($file)) jsonResponse(['ok'=>false,'message'=>'プロジェクトが見つかりません。'],404);

        $data = json_decode(@file_get_contents($file) ?: '', true);
        if (!is_array($data)) jsonResponse(['ok'=>false,'message'=>'保存データが壊れています。'],500);

        jsonResponse(['ok'=>true,'project'=>$data]);
    }

    if ($api === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['ok'=>false,'message'=>'POST only'],405);

        $payload = json_decode(file_get_contents('php://input') ?: '', true);
        $id = is_array($payload) ? (string)($payload['projectId'] ?? '') : '';

        if (!validId($id)) jsonResponse(['ok'=>false,'message'=>'プロジェクトIDが不正です。'],400);

        $file = projectPath($id);
        if (is_file($file) && !@unlink($file)) {
            jsonResponse(['ok'=>false,'message'=>'削除できませんでした。'],500);
        }

        jsonResponse(['ok'=>true]);
    }

    jsonResponse(['ok'=>false,'message'=>'Unknown API'],404);
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
    --panel:#1a1d21;
    --panel2:#23272d;
    --border:#3c4149;
    --text:#f4f5f7;
    --muted:#9ca3ad;
    --blue:#287bd5;
    --green:#24854c;
    --red:#ad3838;
    --yellow:#d9a52e;
}
*{box-sizing:border-box}
html,body{width:100%;height:100%;margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}
body{overflow:hidden}
button,input,select,textarea{font:inherit}
button{
    border:1px solid #50565e;
    background:#30343a;
    color:#fff;
    border-radius:6px;
    padding:8px 11px;
    cursor:pointer
}
button:hover:not(:disabled){background:#41464d}
button:disabled{opacity:.42;cursor:not-allowed}
button.primary{background:#176bb9}
button.success{background:#247d46}
button.danger{background:#9d3030}
button.small{padding:5px 8px;font-size:12px}
input,select,textarea{
    width:100%;
    color:#fff;
    background:#25292e;
    border:1px solid #50565e;
    border-radius:5px;
    padding:7px
}
input[type=color]{height:38px;padding:3px}
textarea{min-height:80px;resize:vertical}
.hidden{display:none!important}

header{
    height:50px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:0 14px;
    background:#1b1e22;
    border-bottom:1px solid #33383f
}
header h1{font-size:16px;margin:0}
#status{font-size:12px;color:#b7bec7}
#message{
    position:fixed;
    left:50%;
    top:60px;
    transform:translateX(-50%);
    z-index:5000;
    display:none;
    max-width:90vw;
    padding:10px 16px;
    border-radius:7px;
    background:#8f3030;
    box-shadow:0 10px 35px #000b;
    white-space:pre-wrap
}
#message.ok{background:#246d41}

#home{
    height:calc(100vh - 50px);
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:16px;
    background:#111317
}
.home-card{
    width:min(700px,92vw);
    padding:24px;
    background:#1b1e23;
    border:1px solid #3c4148;
    border-radius:10px;
    box-shadow:0 15px 50px #0007
}
.home-card h2{margin:0 0 8px}
.home-card p{color:var(--muted);font-size:13px;line-height:1.6}
.home-actions{display:flex;flex-wrap:wrap;gap:8px}
.project-list{
    margin-top:14px;
    max-height:300px;
    overflow:auto
}
.project{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    padding:10px;
    margin:6px 0;
    border:1px solid #41464e;
    border-radius:6px
}
.project-info{min-width:0}
.project-name{font-weight:600}
.project-meta{font-size:11px;color:#8f97a1;margin-top:3px}
.project-actions{display:flex;gap:5px;flex-shrink:0}

#recorder{
    position:absolute;
    inset:50px 0 0;
    display:none;
    flex-direction:column;
    background:#000
}
#previewWrap{
    flex:1;
    min-height:0;
    display:flex;
    align-items:center;
    justify-content:center
}
#preview{
    max-width:100%;
    max-height:100%;
    display:none
}
#recordToolbar{
    min-height:58px;
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:8px;
    padding:8px 12px;
    background:#1a1d21;
    border-top:1px solid #33383f
}
#timer{font-variant-numeric:tabular-nums;min-width:80px}
.record-label{font-size:12px;white-space:nowrap}

#editor{
    position:fixed;
    inset:0;
    z-index:100;
    display:none;
    flex-direction:column;
    background:#111
}
.editor-top{
    height:48px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:8px;
    padding:6px 10px;
    background:#1b1e22;
    border-bottom:1px solid #363b42
}
#editorProjectName{
    width:220px;
    font-weight:600
}
.editor-main{
    flex:1;
    min-height:0;
    display:grid;
    grid-template-columns:minmax(0,1fr) 330px
}
.video-area{
    min-width:0;
    min-height:0;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#000;
    overflow:hidden
}
#videoStage{
    position:relative;
    background:#000;
    overflow:visible
}
#recordedVideo{
    display:block;
    width:100%;
    height:100%;
    object-fit:fill
}
#overlay{
    position:absolute;
    inset:0;
    pointer-events:none
}
#objects{
    position:absolute;
    inset:0
}
#connectors{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none
}
.edit-object{
    position:absolute;
    pointer-events:auto;
    cursor:move;
    user-select:none;
    touch-action:none;
    overflow:visible
}
.edit-object.selected{
    outline:2px solid #fff;
    outline-offset:2px
}
.edit-object.comment{
    display:flex;
    align-items:center;
    justify-content:flex-start;
    padding:6px 9px;
    min-width:40px;
    min-height:25px;
    white-space:pre-wrap;
    word-break:break-word;
    overflow:hidden;
    box-shadow:0 2px 12px #0006
}
.edit-object.box{background:transparent}
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
    display:none
}
.edit-object.selected .resize-handle{display:block}
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
    cursor:crosshair
}
.edit-object.selected .connection-point{display:block}
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
    cursor:pointer
}
.connector.selected{
    stroke:#ffd34d;
    stroke-width:5
}
.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:15;
    pointer-events:stroke;
    cursor:pointer
}

aside{
    min-width:0;
    overflow:auto;
    padding:11px;
    background:#1b1e22;
    border-left:1px solid #393e45
}
.panel{
    margin-bottom:13px;
    padding-bottom:13px;
    border-bottom:1px solid #383d44
}
.panel h3{margin:0 0 8px;font-size:14px}
.help{color:#999fa8;font-size:12px;line-height:1.55}
.button-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.field{display:block;margin:7px 0;color:#c5cad0;font-size:12px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 7px}
#selectionInfo{
    padding:8px;
    background:#15181c;
    border:1px solid #353a40;
    border-radius:5px;
    color:#b8bec7;
    font-size:12px
}

#timeline{
    height:88px;
    flex-shrink:0;
    background:#191c20;
    border-top:1px solid #363b42;
    padding:7px 10px
}
#seek{width:100%}
.timeline-row{
    display:grid;
    grid-template-columns:60px 1fr;
    gap:6px;
    margin-top:7px;
    align-items:center;
    font-size:10px;
    color:#8f969f
}
.track{
    height:13px;
    position:relative;
    background:#292d33;
    border-radius:3px
}
.track-item{
    position:absolute;
    top:2px;
    height:9px;
    min-width:3px;
    border-radius:2px
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
    border-top:1px solid #383d44
}
#timeReadout{min-width:100px;text-align:center;font-variant-numeric:tabular-nums}

#contextMenu{
    position:fixed;
    z-index:3000;
    display:none;
    width:200px;
    padding:5px;
    border:1px solid #555b63;
    border-radius:7px;
    background:#292d33;
    box-shadow:0 12px 40px #000c
}
#contextMenu button{
    display:block;
    width:100%;
    text-align:left;
    background:transparent;
    border:0
}
#contextMenu button:hover{background:#3c424a}

.modal-backdrop{
    position:fixed;
    inset:0;
    z-index:4000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:15px;
    background:#0009
}
.modal{
    width:min(520px,96vw);
    max-height:92vh;
    overflow:auto;
    padding:17px;
    background:#20242a;
    border:1px solid #555b64;
    border-radius:9px;
    box-shadow:0 20px 70px #000c
}
.modal h2{margin:0 0 12px;font-size:16px}
.modal-footer{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:13px
}
.modal-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 9px
}
.full{grid-column:1/-1}

@media(max-width:850px){
    .editor-main{display:flex;flex-direction:column}
    .video-area{min-height:300px;flex:1}
    aside{max-height:42vh;border-left:0;border-top:1px solid #393e45}
}
</style>
</head>
<body>

<header>
    <h1>動画上直接編集ツール</h1>
    <div id="status">待機中</div>
</header>

<div id="message"></div>

<!-- ホーム -->
<div id="home">
    <div class="home-card">
        <h2>動画を編集</h2>
        <p>
            編集対象を明示的な名前で管理します。
            動画を読み込んだあとで編集を開始できます。
            動画ファイル自体はブラウザに永続保存され、編集データはサーバーにも保存できます。
        </p>

        <div class="home-actions">
            <button id="newVideoBtn" class="primary">動画ファイルを読み込む</button>
            <button id="manageBtn">動画・データ管理</button>
        </div>

        <div id="projectList" class="project-list"></div>
    </div>
</div>

<!-- 録画 -->
<div id="recorder">
    <div id="previewWrap">
        <video id="preview" autoplay muted playsinline></video>
    </div>

    <div id="recordToolbar">
        <label class="record-label">
            <input id="systemAudio" type="checkbox"> 画面音声
        </label>
        <label class="record-label">
            <input id="microphone" type="checkbox"> マイク
        </label>
        <span id="timer">00:00:00</span>
        <button id="startRecord" class="primary">録画開始</button>
        <button id="pauseRecord" disabled>一時停止</button>
        <button id="stopRecord" class="danger" disabled>停止</button>
        <button id="recordCancel">戻る</button>
    </div>
</div>

<!-- 編集 -->
<div id="editor">
    <div class="editor-top">
        <button id="backHome">閉じる</button>
        <input id="editorProjectName" placeholder="動画名">
        <button id="saveServer" class="success">保存</button>
        <button id="saveLocal">ローカル保存</button>
        <button id="exportJson">書き出し</button>
        <button id="importJson">読み込み</button>
        <input id="jsonFile" type="file" accept=".json,application/json" class="hidden">
        <span id="editorStatus" class="help"></span>
    </div>

    <div class="editor-main">
        <div class="video-area" id="videoArea">
            <div id="videoStage">
                <video id="recordedVideo" controls playsinline></video>
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
                    動画上へ直接要素を配置できます。
                    要素はドラッグ移動、右下の丸でリサイズできます。
                    書式変更は対象を右クリックしてください。
                </div>

                <div class="button-row">
                    <button id="addComment" class="primary">コメント</button>
                    <button id="addBox" class="primary">強調枠</button>
                    <button id="connectMode">線で接続</button>
                    <button id="deleteSelected" class="danger">削除</button>
                </div>
            </div>

            <div class="panel">
                <h3>選択中</h3>
                <div id="selectionInfo">何も選択されていません</div>

                <div id="objectEditor" class="hidden">
                    <label id="textField" class="field">
                        内容
                        <textarea id="objectText"></textarea>
                    </label>

                    <div class="grid2">
                        <label class="field">開始<input id="objectStart" type="number" min="0" step=".1"></label>
                        <label class="field">終了<input id="objectEnd" type="number" min="0" step=".1"></label>
                        <label class="field">左 %<input id="objectX" type="number" min="0" max="100" step=".1"></label>
                        <label class="field">上 %<input id="objectY" type="number" min="0" max="100" step=".1"></label>
                        <label class="field">幅 %<input id="objectW" type="number" min=".5" max="100" step=".1"></label>
                        <label class="field">高さ %<input id="objectH" type="number" min=".5" max="100" step=".1"></label>
                    </div>

                    <button id="applyObject" class="primary">反映</button>
                </div>
            </div>

            <div class="panel">
                <h3>保存</h3>
                <div class="help">
                    サーバー保存には上限があります。
                    上限到達後はローカル保存を使用できます。
                    ローカル保存はこのブラウザのIndexedDBに保持されます。
                </div>
                <div class="button-row">
                    <button id="showManage">保存データ管理</button>
                </div>
            </div>
        </aside>
    </div>

    <div id="timeline">
        <input id="seek" type="range" min="0" max="0" value="0" step=".01">
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
        <button id="playVideo" class="primary">再生</button>
        <button id="pauseVideo">停止</button>
        <span id="timeReadout">00:00 / 00:00</span>
        <button id="startPoint">開始を現在位置</button>
        <button id="endPoint">終了を現在位置</button>
    </div>
</div>

<!-- 右クリック -->
<div id="contextMenu">
    <button id="ctxFormat">書式を変更</button>
    <button id="ctxStart">開始を現在位置</button>
    <button id="ctxEnd">終了を現在位置</button>
    <button id="ctxDelete" class="danger">削除</button>
</div>

<!-- 書式モーダル -->
<div id="formatModal" class="modal-backdrop">
    <div class="modal">
        <h2 id="formatTitle">書式設定</h2>

        <div id="objectFormatFields">
            <div class="modal-grid">
                <label class="field">
                    背景色
                    <input id="fmtBg" type="color" value="#287bd5">
                </label>
                <label class="field">
                    文字色
                    <input id="fmtColor" type="color" value="#ffffff">
                </label>
                <label class="field">
                    文字サイズ
                    <input id="fmtFontSize" type="number" min="8" max="120" value="20">
                </label>
                <label class="field">
                    枠線幅
                    <input id="fmtBorderWidth" type="number" min="0" max="30" value="2">
                </label>
                <label class="field">
                    枠線色
                    <input id="fmtBorderColor" type="color" value="#ffffff">
                </label>
                <label class="field">
                    角丸
                    <input id="fmtRadius" type="number" min="0" max="100" value="6">
                </label>
                <label class="field">
                    不透明度
                    <input id="fmtOpacity" type="number" min="0" max="1" step=".05" value="1">
                </label>
                <label class="field">
                    太字
                    <select id="fmtWeight">
                        <option value="400">標準</option>
                        <option value="600">太字</option>
                        <option value="700">強調</option>
                    </select>
                </label>
            </div>
        </div>

        <div id="connectionFormatFields" class="hidden">
            <div class="modal-grid">
                <label class="field">
                    線色
                    <input id="lineColor" type="color" value="#ffffff">
                </label>
                <label class="field">
                    太さ
                    <input id="lineWidth" type="number" min="1" max="30" step=".5" value="2.5">
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
                    矢印
                    <select id="lineArrow">
                        <option value="none">なし</option>
                        <option value="end">終点</option>
                        <option value="both">両端</option>
                    </select>
                </label>
                <label class="field">
                    始点
                    <select id="lineFromPoint"></select>
                </label>
                <label class="field">
                    終点
                    <select id="lineToPoint"></select>
                </label>
            </div>
        </div>

        <div class="modal-footer">
            <button id="formatCancel">キャンセル</button>
            <button id="formatApply" class="primary">適用</button>
        </div>
    </div>
</div>

<!-- 名前 -->
<div id="nameModal" class="modal-backdrop">
    <div class="modal">
        <h2>動画名を設定</h2>
        <p class="help">この名前が編集対象を識別する名前になります。</p>
        <label class="field">
            動画名
            <input id="newProjectName" maxlength="120" placeholder="例：営業説明動画_2026-10-01">
        </label>
        <div class="modal-footer">
            <button id="nameCancel">キャンセル</button>
            <button id="nameCreate" class="primary">編集を開始</button>
        </div>
    </div>
</div>

<!-- データ管理 -->
<div id="manageModal" class="modal-backdrop">
    <div class="modal">
        <h2>動画・保存データ管理</h2>
        <p class="help">
            動画ファイルはこのブラウザ内に保存されます。
            サーバーには編集データを保存します。
        </p>
        <div id="manageList"></div>
        <div class="modal-footer">
            <button id="manageClose">閉じる</button>
        </div>
    </div>
</div>

<script>
(() => {
'use strict';

const API = location.pathname;
const DB_NAME = 'video_direct_editor';
const DB_VERSION = 1;
const STORE = 'videos';

const state = {
    project: null,
    videoBlob: null,
    videoUrl: '',
    selected: null,
    connectMode: false,
    connectSource: null,
    contextTarget: null,
    db: null,
    drag: null,
    resize: null,
    formatTarget: null,
    saving: false
};

const $ = id => document.getElementById(id);

const video = $('recordedVideo');
const stage = $('videoStage');
const objects = $('objects');
const connectors = $('connectors');

const pointNames = {
    n:'上',
    ne:'右上',
    e:'右',
    se:'右下',
    s:'下',
    sw:'左下',
    w:'左',
    nw:'左上'
};

function uid(prefix='x') {
    return prefix + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2,9);
}

function escapeHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

function msg(text, ok=false) {
    const el = $('message');
    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';
    clearTimeout(msg.timer);
    msg.timer = setTimeout(() => el.style.display='none', 3500);
}

function setStatus(text) {
    $('status').textContent = text;
    $('editorStatus').textContent = text;
}

function fmtTime(sec) {
    sec = Math.max(0, Number(sec) || 0);
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = Math.floor(sec % 60);
    return (h ? String(h).padStart(2,'0')+':' : '') +
           String(m).padStart(2,'0') + ':' +
           String(s).padStart(2,'0');
}

function clone(v) {
    return JSON.parse(JSON.stringify(v));
}

function emptyProject(name, videoName) {
    return {
        version: APP_VERSION,
        projectId: uid('project'),
        name: name.trim(),
        videoName,
        duration: 0,
        elements: [],
        connections: [],
        createdAt: new Date().toISOString(),
        savedAt: null
    };
}

function openDB() {
    return new Promise((resolve,reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);

        req.onupgradeneeded = e => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, {keyPath:'projectId'});
            }
        };

        req.onsuccess = () => {
            state.db = req.result;
            resolve(state.db);
        };

        req.onerror = () => reject(req.error);
    });
}

function dbPut(item) {
    return new Promise((resolve,reject) => {
        const tx = state.db.transaction(STORE,'readwrite');
        tx.objectStore(STORE).put(item);
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
}

function dbGet(id) {
    return new Promise((resolve,reject) => {
        const tx = state.db.transaction(STORE,'readonly');
        const req = tx.objectStore(STORE).get(id);
        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error);
    });
}

function dbAll() {
    return new Promise((resolve,reject) => {
        const tx = state.db.transaction(STORE,'readonly');
        const req = tx.objectStore(STORE).getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => reject(req.error);
    });
}

function dbDelete(id) {
    return new Promise((resolve,reject) => {
        const tx = state.db.transaction(STORE,'readwrite');
        tx.objectStore(STORE).delete(id);
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
}

async function api(path, options={}) {
    const res = await fetch(API + path, {
        credentials:'same-origin',
        ...options
    });

    let data = null;
    try { data = await res.json(); } catch {}

    if (!res.ok || !data?.ok) {
        const err = new Error(data?.message || `HTTP ${res.status}`);
        err.data = data;
        err.status = res.status;
        throw err;
    }

    return data;
}

async function serverList() {
    try {
        return await api('?api=list');
    } catch(e) {
        return {ok:false,projects:[],limit:0,error:e.message};
    }
}

async function allProjects() {
    const local = await dbAll();
    const server = await serverList();

    const map = new Map();

    local.forEach(p => {
        map.set(p.projectId, {
            ...p,
            local:true,
            server:false
        });
    });

    (server.projects || []).forEach(p => {
        const existing = map.get(p.projectId);
        map.set(p.projectId, {
            ...(existing || {}),
            ...p,
            local:!!existing,
            server:true
        });
    });

    return [...map.values()].sort((a,b) =>
        String(b.savedAt || '').localeCompare(String(a.savedAt || ''))
    );
}

function showHome() {
    $('home').style.display='flex';
    $('recorder').style.display='none';
    $('editor').style.display='none';
    loadProjectList();
}

function showRecorder() {
    $('home').style.display='none';
    $('editor').style.display='none';
    $('recorder').style.display='flex';
}

function showEditor() {
    if (!state.project || !state.videoBlob) {
        msg('動画を読み込むまで編集を開始できません。');
        return;
    }

    $('home').style.display='none';
    $('recorder').style.display='none';
    $('editor').style.display='flex';
    $('editorProjectName').value = state.project.name;
    renderAll();
}

async function loadProjectList() {
    const list = $('projectList');
    list.innerHTML = '<div class="help">読み込み中...</div>';

    const items = await allProjects();

    if (!items.length) {
        list.innerHTML = '<div class="help">保存された動画はありません。</div>';
        return;
    }

    list.innerHTML = items.map(p => `
        <div class="project">
            <div class="project-info">
                <div class="project-name">${escapeHtml(p.name || '名称未設定')}</div>
                <div class="project-meta">
                    ${escapeHtml(p.videoName || '')}
                    ${p.local ? ' / ローカル' : ''}
                    ${p.server ? ' / サーバー' : ''}
                    ${p.savedAt ? ' / '+escapeHtml(new Date(p.savedAt).toLocaleString('ja-JP')) : ''}
                </div>
            </div>
            <div class="project-actions">
                <button class="small open-project" data-id="${escapeHtml(p.projectId)}">開く</button>
                <button class="small danger delete-project" data-id="${escapeHtml(p.projectId)}">削除</button>
            </div>
        </div>
    `).join('');
}

async function createFromFile(file) {
    if (!file) return;

    if (!file.type.startsWith('video/')) {
        msg('動画ファイルを選択してください。');
        return;
    }

    state.videoBlob = file;

    $('newProjectName').value =
        file.name.replace(/\.[^.]+$/, '') || '新しい動画';

    $('nameModal').style.display='flex';
}

async function createProject() {
    const name = $('newProjectName').value.trim();

    if (!name) {
        msg('動画名を入力してください。');
        $('newProjectName').focus();
        return;
    }

    if (!state.videoBlob) {
        msg('動画ファイルがありません。');
        return;
    }

    state.project = emptyProject(name, state.videoBlob.name);

    try {
        await dbPut({
            projectId:state.project.projectId,
            name:state.project.name,
            videoName:state.project.videoName,
            blob:state.videoBlob,
            project:clone(state.project),
            savedAt:null
        });

        $('nameModal').style.display='none';
        await attachVideo(state.videoBlob);
        showEditor();
        msg('動画を読み込みました。編集を開始できます。',true);
    } catch(e) {
        msg('動画のローカル保存に失敗しました。\n'+e.message);
    }
}

async function attachVideo(blob) {
    if (state.videoUrl) URL.revokeObjectURL(state.videoUrl);

    state.videoBlob = blob;
    state.videoUrl = URL.createObjectURL(blob);
    video.src = state.videoUrl;

    await new Promise((resolve,reject) => {
        if (video.readyState >= 1) return resolve();

        const ok = () => {
            video.removeEventListener('loadedmetadata',ok);
            video.removeEventListener('error',bad);
            resolve();
        };

        const bad = () => {
            video.removeEventListener('loadedmetadata',ok);
            video.removeEventListener('error',bad);
            reject(new Error('動画を読み込めませんでした。'));
        };

        video.addEventListener('loadedmetadata',ok);
        video.addEventListener('error',bad);
    });

    state.project.duration = Number(video.duration) || 0;
    $('seek').max = state.project.duration;
    setTimeout(fitStage,30);
}

async function openProject(id) {
    try {
        let local = await dbGet(id);
        let project = local?.project || null;
        let blob = local?.blob || null;

        if (!project) {
            const result = await api('?api=load&id='+encodeURIComponent(id));
            project = result.project;
        }

        if (!blob) {
            msg('このプロジェクトの動画ファイルがこのブラウザにありません。\n動画ファイルを再選択してください。');
            const input = document.createElement('input');
            input.type='file';
            input.accept='video/*';

            input.onchange = async () => {
                const file = input.files?.[0];
                if (!file) return;

                state.project = normalizeProject(project);
                state.videoBlob = file;

                await dbPut({
                    projectId:state.project.projectId,
                    name:state.project.name,
                    videoName:file.name,
                    blob:file,
                    project:clone(state.project),
                    savedAt:state.project.savedAt
                });

                await attachVideo(file);
                showEditor();
            };

            input.click();
            return;
        }

        state.project = normalizeProject(project);
        state.videoBlob = blob;

        await attachVideo(blob);
        showEditor();
    } catch(e) {
        msg('プロジェクトを開けませんでした。\n'+e.message);
    }
}

function normalizeProject(p) {
    p = p || {};
    return {
        version:APP_VERSION,
        projectId:p.projectId || uid('project'),
        name:String(p.name || '名称未設定'),
        videoName:String(p.videoName || ''),
        duration:Number(p.duration || 0),
        elements:Array.isArray(p.elements) ? p.elements : [],
        connections:Array.isArray(p.connections) ? p.connections : [],
        createdAt:p.createdAt || new Date().toISOString(),
        savedAt:p.savedAt || null
    };
}

async function saveLocal(show=true) {
    if (!state.project || !state.videoBlob) return;

    state.project.name = $('editorProjectName').value.trim() || state.project.name;

    await dbPut({
        projectId:state.project.projectId,
        name:state.project.name,
        videoName:state.project.videoName,
        blob:state.videoBlob,
        project:clone(state.project),
        savedAt:new Date().toISOString()
    });

    if (show) msg('ローカルに保存しました。',true);
}

async function saveServer() {
    if (!state.project) return;

    state.project.name = $('editorProjectName').value.trim() || state.project.name;

    try {
        state.saving = true;

        const result = await api('?api=save',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(state.project)
        });

        state.project.projectId = result.projectId;
        state.project.savedAt = result.savedAt;

        await saveLocal(false);

        msg('サーバーに保存しました。',true);
        setStatus('保存済み '+new Date(result.savedAt).toLocaleTimeString('ja-JP'));
    } catch(e) {
        if (e.data?.limit || e.status === 409) {
            await saveLocal(false);
            msg('サーバー保存上限に達したため、ローカルへ保存しました。',true);
        } else {
            await saveLocal(false);
            msg('サーバー保存に失敗したため、ローカルへ保存しました。\n'+e.message);
        }
    } finally {
        state.saving = false;
    }
}

let autoSaveTimer = null;

function scheduleSave() {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(() => saveLocal(false).catch(()=>{}),700);
}

function elementDefaults(type) {
    const duration = state.project.duration || 10;
    const start = Math.max(0, Number(video.currentTime || 0));

    return {
        id:uid('el'),
        type,
        text:type==='comment' ? 'コメント' : '',
        start,
        end:Math.min(duration || start+5,start+5),
        x:35,
        y:35,
        w:type==='comment' ? 30 : 30,
        h:type==='comment' ? 12 : 25,
        style:{
            bg:type==='comment' ? '#287bd5' : 'transparent',
            color:'#ffffff',
            fontSize:20,
            borderWidth:2,
            borderColor:type==='comment' ? '#ffffff' : '#ff3d3d',
            radius:6,
            opacity:1,
            weight:600
        }
    };
}

function addElement(type) {
    if (!state.project) return;

    const el = elementDefaults(type);
    state.project.elements.push(el);
    selectElement(el.id);
    renderAll();
    scheduleSave();
}

function getElement(id) {
    return state.project?.elements.find(e => e.id === id) || null;
}

function getConnection(id) {
    return state.project?.connections.find(c => c.id === id) || null;
}

function selectElement(id) {
    state.selected = {type:'element',id};
    state.connectSource = null;
    renderAll();
}

function selectConnection(id) {
    state.selected = {type:'connection',id};
    renderAll();
}

function clearSelection() {
    state.selected = null;
    renderAll();
}

function visible(el) {
    const t = Number(video.currentTime || 0);
    return t >= Number(el.start || 0) && t <= Number(el.end || 0);
}

function renderAll() {
    renderObjects();
    renderConnections();
    renderSelectionPanel();
    renderTimeline();
    fitStage();
}

function renderObjects() {
    objects.innerHTML='';

    if (!state.project) return;

    state.project.elements.forEach(el => {
        if (!visible(el)) return;

        const node = document.createElement('div');
        node.className = `edit-object ${el.type}${state.selected?.type==='element' && state.selected.id===el.id ? ' selected':''}`;
        node.dataset.id = el.id;

        node.style.left = el.x+'%';
        node.style.top = el.y+'%';
        node.style.width = el.w+'%';
        node.style.height = el.h+'%';

        node.style.background = el.style.bg;
        node.style.color = el.style.color;
        node.style.fontSize = el.style.fontSize+'px';
        node.style.fontWeight = el.style.weight;
        node.style.border = `${el.style.borderWidth}px solid ${el.style.borderColor}`;
        node.style.borderRadius = el.style.radius+'px';
        node.style.opacity = el.style.opacity;

        if (el.type === 'comment') {
            node.textContent = el.text;
        }

        const points = Object.keys(pointNames);

        points.forEach(p => {
            const cp = document.createElement('div');
            cp.className='connection-point cp-'+p;
            cp.dataset.point=p;

            cp.addEventListener('pointerdown', e => {
                e.stopPropagation();
                if (!state.connectMode) return;
                handleConnectionPoint(el.id,p);
            });

            node.appendChild(cp);
        });

        const resize = document.createElement('div');
        resize.className='resize-handle';

        resize.addEventListener('pointerdown', e => {
            e.stopPropagation();
            startResize(e,el);
        });

        node.appendChild(resize);

        node.addEventListener('pointerdown', e => {
            if (e.button !== 0) return;
            if (e.target.classList.contains('connection-point') ||
                e.target.classList.contains('resize-handle')) return;

            selectElement(el.id);
            startDrag(e,el);
        });

        node.addEventListener('dblclick', e => {
            e.stopPropagation();

            if (el.type === 'comment') {
                const text = prompt('コメント内容',el.text);
                if (text !== null) {
                    el.text=text;
                    renderAll();
                    scheduleSave();
                }
            }
        });

        node.addEventListener('contextmenu', e => {
            e.preventDefault();
            e.stopPropagation();
            state.contextTarget={type:'element',id:el.id};
            selectElement(el.id);
            showContextMenu(e.clientX,e.clientY);
        });

        objects.appendChild(node);
    });
}

function pointXY(el,point) {
    const x = Number(el.x), y=Number(el.y), w=Number(el.w), h=Number(el.h);

    const map = {
        n:[x+w/2,y],
        ne:[x+w,y],
        e:[x+w,y+h/2],
        se:[x+w,y+h],
        s:[x+w/2,y+h],
        sw:[x,y+h],
        w:[x,y+h/2],
        nw:[x,y]
    };

    return map[point] || map.e;
}

function renderConnections() {
    connectors.innerHTML='';

    if (!state.project) return;

    connectors.setAttribute('viewBox','0 0 100 100');

    state.project.connections.forEach(c => {
        const from = getElement(c.fromElement);
        const to = getElement(c.toElement);
        if (!from || !to) return;

        const a = pointXY(from,c.fromPoint);
        const b = pointXY(to,c.toPoint);

        const selected =
            state.selected?.type==='connection' &&
            state.selected.id===c.id;

        const d = `M ${a[0]} ${a[1]}} L ${b[0]} ${b[1]}`;

        const hit = document.createElementNS('http://www.w3.org/2000/svg','path');
        hit.setAttribute('d',d.replace('100}','100'));
        hit.setAttribute('class','connector-hit');

        const line = document.createElementNS('http://www.w3.org/2000/svg','line');
        line.setAttribute('x1',a[0]);
        line.setAttribute('y1',a[1]);
        line.setAttribute('x2',b[0]);
        line.setAttribute('y2',b[1]);
        line.setAttribute('class','connector'+(selected?' selected':''));
        line.setAttribute('stroke',c.style.color);
        line.setAttribute('stroke-width',c.style.width);

        if (c.style.dash === 'dashed') line.setAttribute('stroke-dasharray','8 6');
        if (c.style.dash === 'dotted') line.setAttribute('stroke-dasharray','2 5');

        const markerId = 'arrow_'+c.id;

        if (c.style.arrow !== 'none') {
            const defs = document.createElementNS('http://www.w3.org/2000/svg','defs');
            const marker = document.createElementNS('http://www.w3.org/2000/svg','marker');

            marker.setAttribute('id',markerId);
            marker.setAttribute('markerWidth','8');
            marker.setAttribute('markerHeight','8');
            marker.setAttribute('refX','7');
            marker.setAttribute('refY','3.5');
            marker.setAttribute('orient','auto');
            marker.setAttribute('markerUnits','strokeWidth');

            const path = document.createElementNS('http://www.w3.org/2000/svg','path');
            path.setAttribute('d','M0,0 L7,3.5 L0,7 Z');
            path.setAttribute('fill',c.style.color);

            marker.appendChild(path);
            defs.appendChild(marker);
            connectors.appendChild(defs);

            if (c.style.arrow==='end' || c.style.arrow==='both') {
                line.setAttribute('marker-end',`url(#${markerId})`);
            }
            if (c.style.arrow==='both') {
                line.setAttribute('marker-start',`url(#${markerId})`);
            }
        }

        const choose = e => {
            e.preventDefault();
            e.stopPropagation();
            selectConnection(c.id);
        };

        hit.addEventListener('click',choose);
        line.addEventListener('click',choose);

        hit.addEventListener('contextmenu',e => {
            e.preventDefault();
            e.stopPropagation();
            state.contextTarget={type:'connection',id:c.id};
            selectConnection(c.id);
            showContextMenu(e.clientX,e.clientY);
        });

        line.addEventListener('contextmenu',e => {
            e.preventDefault();
            e.stopPropagation();
            state.contextTarget={type:'connection',id:c.id};
            selectConnection(c.id);
            showContextMenu(e.clientX,e.clientY);
        });

        connectors.appendChild(hit);
        connectors.appendChild(line);
    });
}

function renderSelectionPanel() {
    const info = $('selectionInfo');
    const editor = $('objectEditor');

    if (!state.selected) {
        info.textContent='何も選択されていません';
        editor.classList.add('hidden');
        return;
    }

    if (state.selected.type === 'connection') {
        const c = getConnection(state.selected.id);
        if (!c) return;

        info.textContent=`接続線: ${c.fromPoint} → ${c.toPoint}`;
        editor.classList.add('hidden');
        return;
    }

    const el = getElement(state.selected.id);

    if (!el) {
        state.selected=null;
        editor.classList.add('hidden');
        return;
    }

    info.textContent =
        `${el.type==='comment'?'コメント':'強調枠'} / ${el.id}`;

    editor.classList.remove('hidden');
    $('textField').classList.toggle('hidden',el.type!=='comment');

    $('objectText').value=el.text || '';
    $('objectStart').value=el.start;
    $('objectEnd').value=el.end;
    $('objectX').value=el.x;
    $('objectY').value=el.y;
    $('objectW').value=el.w;
    $('objectH').value=el.h;
}

function renderTimeline() {
    const duration = Number(state.project?.duration || 0);

    $('elementTrack').innerHTML='';
    $('connectionTrack').innerHTML='';

    if (!duration) return;

    state.project.elements.forEach(el => {
        const span=document.createElement('span');
        span.className='track-item '+el.type;

        span.style.left=(el.start/duration*100)+'%';
        span.style.width=(Math.max(.1,el.end-el.start)/duration*100)+'%';

        span.title=el.type==='comment' ? el.text : '強調枠';

        span.onclick=() => {
            video.currentTime=el.start;
            selectElement(el.id);
        };

        $('elementTrack').appendChild(span);
    });

    state.project.connections.forEach(c => {
        const from=getElement(c.fromElement);
        const to=getElement(c.toElement);
        if (!from || !to) return;

        const start=Math.min(from.start,to.start);
        const end=Math.max(from.end,to.end);

        const span=document.createElement('span');
        span.className='track-item connection';
        span.style.left=(start/duration*100)+'%';
        span.style.width=(Math.max(.1,end-start)/duration*100)+'%';

        span.onclick=()=>selectConnection(c.id);

        $('connectionTrack').appendChild(span);
    });
}

function fitStage() {
    if (!video.videoWidth || !video.videoHeight) return;

    const area=$('videoArea');
    const maxW=Math.max(100,area.clientWidth-20);
    const maxH=Math.max(100,area.clientHeight-20);

    const ratio=video.videoWidth/video.videoHeight;

    let w=maxW;
    let h=w/ratio;

    if (h>maxH) {
        h=maxH;
        w=h*ratio;
    }

    stage.style.width=Math.floor(w)+'px';
    stage.style.height=Math.floor(h)+'px';

    connectors.setAttribute('viewBox','0 0 100 100');
}

function pointerPercent(e) {
    const rect=stage.getBoundingClientRect();

    return {
        x:(e.clientX-rect.left)/rect.width*100,
        y:(e.clientY-rect.top)/rect.height*100
    };
}

function startDrag(e,el) {
    if (state.connectMode) return;

    const p=pointerPercent(e);

    state.drag={
        id:el.id,
        startX:p.x,
        startY:p.y,
        origX:el.x,
        origY:el.y
    };

    window.addEventListener('pointermove',dragMove);
    window.addEventListener('pointerup',dragEnd,{once:true});
}

function dragMove(e) {
    if (!state.drag) return;

    const el=getElement(state.drag.id);
    if (!el) return;

    const p=pointerPercent(e);

    el.x=Math.max(0,Math.min(100-el.w,
        state.drag.origX+(p.x-state.drag.startX)));

    el.y=Math.max(0,Math.min(100-el.h,
        state.drag.origY+(p.y-state.drag.startY)));

    renderAll();
}

function dragEnd() {
    state.drag=null;
    window.removeEventListener('pointermove',dragMove);
    scheduleSave();
}

function startResize(e,el) {
    state.resize={
        id:el.id,
        startX:e.clientX,
        startY:e.clientY,
        origW:el.w,
        origH:el.h
    };

    window.addEventListener('pointermove',resizeMove);
    window.addEventListener('pointerup',resizeEnd,{once:true});
}

function resizeMove(e) {
    if (!state.resize) return;

    const el=getElement(state.resize.id);
    if (!el) return;

    const rect=stage.getBoundingClientRect();

    const dx=(e.clientX-state.resize.startX)/rect.width*100;
    const dy=(e.clientY-state.resize.startY)/rect.height*100;

    el.w=Math.max(1,Math.min(100-el.x,state.resize.origW+dx));
    el.h=Math.max(1,Math.min(100-el.y,state.resize.origH+dy));

    renderAll();
}

function resizeEnd() {
    state.resize=null;
    window.removeEventListener('pointermove',resizeMove);
    scheduleSave();
}

function handleConnectionPoint(elementId,point) {
    if (!state.connectMode) return;

    if (!state.connectSource) {
        state.connectSource={elementId,point};
        msg('接続先の要素の接点をクリックしてください。');
        return;
    }

    if (state.connectSource.elementId === elementId &&
        state.connectSource.point === point) {
        state.connectSource=null;
        return;
    }

    state.project.connections.push({
        id:uid('conn'),
        fromElement:state.connectSource.elementId,
        fromPoint:state.connectSource.point,
        toElement:elementId,
        toPoint:point,
        style:{
            color:'#ffffff',
            width:2.5,
            dash:'solid',
            arrow:'end'
        }
    });

    state.connectSource=null;
    state.connectMode=false;
    $('connectMode').textContent='線で接続';

    renderAll();
    scheduleSave();
}

function deleteSelected() {
    if (!state.selected) return;

    if (state.selected.type==='element') {
        const id=state.selected.id;

        state.project.elements =
            state.project.elements.filter(e=>e.id!==id);

        state.project.connections =
            state.project.connections.filter(c =>
                c.fromElement!==id && c.toElement!==id
            );
    } else {
        state.project.connections =
            state.project.connections.filter(c=>c.id!==state.selected.id);
    }

    state.selected=null;
    renderAll();
    scheduleSave();
}

function showContextMenu(x,y) {
    const menu=$('contextMenu');

    menu.style.display='block';
    menu.style.left=Math.min(x,innerWidth-210)+'px';
    menu.style.top=Math.min(y,innerHeight-170)+'px';
}

function hideContextMenu() {
    $('contextMenu').style.display='none';
}

function openFormat(target=state.selected) {
    hideContextMenu();

    if (!target) return;

    state.formatTarget=clone(target);

    const isConnection=target.type==='connection';

    $('formatTitle').textContent=isConnection
        ? '接続線の書式設定'
        : '要素の書式設定';

    $('objectFormatFields').classList.toggle('hidden',isConnection);
    $('connectionFormatFields').classList.toggle('hidden',!isConnection);

    if (isConnection) {
        const c=getConnection(target.id);
        if (!c) return;

        $('lineColor').value=c.style.color;
        $('lineWidth').value=c.style.width;
        $('lineDash').value=c.style.dash;
        $('lineArrow').value=c.style.arrow;

        fillPointSelect('lineFromPoint',c.fromPoint);
        fillPointSelect('lineToPoint',c.toPoint);
    } else {
        const el=getElement(target.id);
        if (!el) return;

        $('fmtBg').value=toColor(el.style.bg,'#287bd5');
        $('fmtColor').value=toColor(el.style.color,'#ffffff');
        $('fmtFontSize').value=el.style.fontSize;
        $('fmtBorderWidth').value=el.style.borderWidth;
        $('fmtBorderColor').value=toColor(el.style.borderColor,'#ffffff');
        $('fmtRadius').value=el.style.radius;
        $('fmtOpacity').value=el.style.opacity;
        $('fmtWeight').value=el.style.weight;
    }

    $('formatModal').style.display='flex';
}

function toColor(value,fallback) {
    return /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
}

function fillPointSelect(id,value) {
    const select=$(id);

    select.innerHTML=Object.entries(pointNames)
        .map(([key,label]) =>
            `<option value="${key}" ${key===value?'selected':''}>${label} (${key})</option>`
        ).join('');
}

function applyFormat() {
    const target=state.formatTarget;
    if (!target) return;

    if (target.type==='connection') {
        const c=getConnection(target.id);
        if (!c) return;

        c.style.color=$('lineColor').value;
        c.style.width=Math.max(1,Number($('lineWidth').value)||2.5);
        c.style.dash=$('lineDash').value;
        c.style.arrow=$('lineArrow').value;
        c.fromPoint=$('lineFromPoint').value;
        c.toPoint=$('lineToPoint').value;
    } else {
        const el=getElement(target.id);
        if (!el) return;

        el.style.bg=$('fmtBg').value;
        el.style.color=$('fmtColor').value;
        el.style.fontSize=Math.max(8,Number($('fmtFontSize').value)||20);
        el.style.borderWidth=Math.max(0,Number($('fmtBorderWidth').value)||0);
        el.style.borderColor=$('fmtBorderColor').value;
        el.style.radius=Math.max(0,Number($('fmtRadius').value)||0);
        el.style.opacity=Math.max(0,Math.min(1,Number($('fmtOpacity').value)));
        el.style.weight=Number($('fmtWeight').value)||400;
    }

    $('formatModal').style.display='none';
    state.formatTarget=null;
    renderAll();
    scheduleSave();
}

function applyObjectFields() {
    if (!state.selected || state.selected.type!=='element') return;

    const el=getElement(state.selected.id);
    if (!el) return;

    const n=id=>Number($(id).value);

    el.text=$('objectText').value;
    el.start=Math.max(0,n('objectStart'));
    el.end=Math.max(el.start,n('objectEnd'));
    el.x=Math.max(0,Math.min(100,n('objectX')));
    el.y=Math.max(0,Math.min(100,n('objectY')));
    el.w=Math.max(.5,Math.min(100-el.x,n('objectW')));
    el.h=Math.max(.5,Math.min(100-el.y,n('objectH')));

    renderAll();
    scheduleSave();
}

function setStartNow() {
    if (!state.selected || state.selected.type!=='element') return;
    const el=getElement(state.selected.id);
    el.start=Math.min(Number(video.currentTime),el.end);
    renderAll();
    scheduleSave();
}

function setEndNow() {
    if (!state.selected || state.selected.type!=='element') return;
    const el=getElement(state.selected.id);
    el.end=Math.max(Number(video.currentTime),el.start);
    renderAll();
    scheduleSave();
}

function toggleConnectMode() {
    state.connectMode=!state.connectMode;
    state.connectSource=null;
    $('connectMode').textContent=
        state.connectMode ? '接続中…' : '線で接続';
    msg(state.connectMode
        ? '要素の接点をクリックしてください。'
        : '接続モードを終了しました。'
    );
}

function exportProject() {
    if (!state.project) return;

    const data={
        exportedAt:new Date().toISOString(),
        appVersion:APP_VERSION,
        project:state.project
    };

    const blob=new Blob(
        [JSON.stringify(data,null,2)],
        {type:'application/json'}
    );

    const a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download=(state.project.name || 'project')+'.json';
    a.click();

    setTimeout(()=>URL.revokeObjectURL(a.href),1000);
}

function importProjectFile(file) {
    if (!file) return;

    const reader=new FileReader();

    reader.onload=async () => {
        try {
            const raw=JSON.parse(reader.result);

            const project=normalizeProject(raw.project || raw);

            state.project=project;

            const local=await dbGet(project.projectId);

            if (!local) {
                msg(
                    '編集データを読み込みました。\n' +
                    '動画ファイルは含まれていないため、対応する動画を選択してください。'
                );

                const input=document.createElement('input');
                input.type='file';
                input.accept='video/*';

                input.onchange=async () => {
                    const file=input.files?.[0];
                    if (!file) return;

                    state.videoBlob=file;

                    await dbPut({
                        projectId:project.projectId,
                        name:project.name,
                        videoName:file.name,
                        blob:file,
                        project:clone(project),
                        savedAt:project.savedAt
                    });

                    await attachVideo(file);
                    showEditor();
                };

                input.click();
            } else {
                state.videoBlob=local.blob;
                await attachVideo(local.blob);
                showEditor();
            }

        } catch(e) {
            msg('JSONを読み込めませんでした。\n'+e.message);
        }
    };

    reader.readAsText(file);
}

async function showManage() {
    const list=$('manageList');
    list.innerHTML='<div class="help">読み込み中...</div>';
    $('manageModal').style.display='flex';

    const items=await allProjects();

    if (!items.length) {
        list.innerHTML='<div class="help">保存データはありません。</div>';
        return;
    }

    list.innerHTML=items.map(p=>`
        <div class="project">
            <div class="project-info">
                <div class="project-name">${escapeHtml(p.name)}</div>
                <div class="project-meta">
                    ${p.local?'ブラウザ保存':''}
                    ${p.server?' / サーバー保存':''}
                </div>
            </div>
            <div class="project-actions">
                <button class="small manage-open" data-id="${escapeHtml(p.projectId)}">開く</button>
                ${p.server?`<button class="small danger manage-delete-server" data-id="${escapeHtml(p.projectId)}">サーバー削除</button>`:''}
                ${p.local?`<button class="small danger manage-delete-local" data-id="${escapeHtml(p.projectId)}">ローカル削除</button>`:''}
            </div>
        </div>
    `).join('');
}

async function deleteProject(id,server,local) {
    if (!confirm('この保存データを削除しますか？')) return;

    try {
        if (server) {
            await api('?api=delete',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({projectId:id})
            });
        }

        if (local) await dbDelete(id);

        await loadProjectList();
        await showManage();
        msg('削除しました。',true);
    } catch(e) {
        msg('削除できませんでした。\n'+e.message);
    }
}

video.addEventListener('loadedmetadata',()=>{
    if (state.project) {
        state.project.duration=Number(video.duration)||0;
        $('seek').max=state.project.duration;
        fitStage();
        renderAll();
    }
});

video.addEventListener('timeupdate',()=>{
    $('seek').value=video.currentTime;
    $('timeReadout').textContent=
        fmtTime(video.currentTime)+' / '+fmtTime(video.duration);

    renderObjects();
});

video.addEventListener('play',()=>{
    $('playVideo').textContent='再生中';
});

video.addEventListener('pause',()=>{
    $('playVideo').textContent='再生';
});

$('seek').addEventListener('input',()=>{
    video.currentTime=Number($('seek').value);
    renderObjects();
});

$('playVideo').onclick=()=>video.play();
$('pauseVideo').onclick=()=>video.pause();

$('addComment').onclick=()=>addElement('comment');
$('addBox').onclick=()=>addElement('box');
$('deleteSelected').onclick=deleteSelected;
$('connectMode').onclick=toggleConnectMode;

$('applyObject').onclick=applyObjectFields;
$('startPoint').onclick=setStartNow;
$('endPoint').onclick=setEndNow;

$('saveServer').onclick=saveServer;
$('saveLocal').onclick=()=>saveLocal(true);
$('exportJson').onclick=exportProject;

$('importJson').onclick=()=>$('jsonFile').click();
$('jsonFile').onchange=e=>{
    importProjectFile(e.target.files?.[0]);
    e.target.value='';
};

$('backHome').onclick=()=>{
    if (confirm('編集画面を閉じますか？')) {
        saveLocal(false).catch(()=>{});
        showHome();
    }
};

$('newVideoBtn').onclick=()=>{
    const input=document.createElement('input');
    input.type='file';
    input.accept='video/*';
    input.onchange=()=>createFromFile(input.files?.[0]);
    input.click();
};

$('manageBtn').onclick=showManage;
$('showManage').onclick=showManage;

$('manageClose').onclick=()=>$('manageModal').style.display='none';

$('nameCancel').onclick=()=>$('nameModal').style.display='none';
$('nameCreate').onclick=createProject;

$('ctxFormat').onclick=()=>{
    openFormat(state.contextTarget);
};

$('ctxDelete').onclick=()=>{
    hideContextMenu();
    if (state.contextTarget) {
        state.selected=state.contextTarget;
        deleteSelected();
    }
};

$('ctxStart').onclick=()=>{
    hideContextMenu();
    if (state.contextTarget?.type==='element') {
        state.selected=state.contextTarget;
        setStartNow();
    }
};

$('ctxEnd').onclick=()=>{
    hideContextMenu();
    if (state.contextTarget?.type==='element') {
        state.selected=state.contextTarget;
        setEndNow();
    }
};

$('formatCancel').onclick=()=>{
    $('formatModal').style.display='none';
    state.formatTarget=null;
};

$('formatApply').onclick=applyFormat;

$('manageList').addEventListener('click',async e=>{
    const open=e.target.closest('.manage-open');
    if (open) {
        $('manageModal').style.display='none';
        await openProject(open.dataset.id);
        return;
    }

    const sd=e.target.closest('.manage-delete-server');
    if (sd) {
        await deleteProject(sd.dataset.id,true,false);
        return;
    }

    const ld=e.target.closest('.manage-delete-local');
    if (ld) {
        await deleteProject(ld.dataset.id,false,true);
    }
});

$('projectList').addEventListener('click',async e=>{
    const open=e.target.closest('.open-project');
    if (open) {
        await openProject(open.dataset.id);
        return;
    }

    const del=e.target.closest('.delete-project');
    if (del) {
        const all=await allProjects();
        const p=all.find(x=>x.projectId===del.dataset.id);

        if (p) {
            await deleteProject(
                p.projectId,
                !!p.server,
                !!p.local
            );
        }
    }
});

document.addEventListener('click',e=>{
    if (!e.target.closest('#contextMenu')) hideContextMenu();
});

document.addEventListener('keydown',e=>{
    if (e.key==='Escape') {
        hideContextMenu();
        $('formatModal').style.display='none';
        $('nameModal').style.display='none';
        $('manageModal').style.display='none';
    }

    if ((e.key==='Delete' || e.key==='Backspace') &&
        document.activeElement.tagName!=='INPUT' &&
        document.activeElement.tagName!=='TEXTAREA') {
        deleteSelected();
    }
});

$('objects').addEventListener('contextmenu',e=>e.preventDefault());

window.addEventListener('resize',fitStage);

let recorder=null;
let recordChunks=[];
let recordStart=0;
let recordTimer=null;
let recordStream=null;

$('startRecord').onclick=async()=>{
    try {
        const display=await navigator.mediaDevices.getDisplayMedia({
            video:true,
            audio:$('systemAudio').checked
        });

        const tracks=[...display.getVideoTracks()];

        if ($('microphone').checked) {
            const mic=await navigator.mediaDevices.getUserMedia({audio:true});
            tracks.push(...mic.getAudioTracks());
        }

        recordStream=new MediaStream(tracks);

        const mime=
            MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')
            ? 'video/webm;codecs=vp9,opus'
            : 'video/webm';

        recorder=new MediaRecorder(recordStream,{mimeType:mime});
        recordChunks=[];
        recordStart=Date.now();

        recorder.ondataavailable=e=>{
            if (e.data.size) recordChunks.push(e.data);
        };

        recorder.onstop=async()=>{
            clearInterval(recordTimer);

            const blob=new Blob(recordChunks,{type:mime});

            state.videoBlob=blob;

            const name=prompt(
                '録画した動画の名前を入力してください。',
                '録画_'+new Date().toISOString().slice(0,19).replace(/[T:]/g,'-')
            );

            if (!name?.trim()) {
                recordStream?.getTracks().forEach(t=>t.stop());
                return;
            }

            state.project=emptyProject(name.trim(),'recorded.webm');

            await dbPut({
                projectId:state.project.projectId,
                name:state.project.name,
                videoName:state.project.videoName,
                blob,
                project:clone(state.project),
                savedAt:null
            });

            recordStream?.getTracks().forEach(t=>t.stop());

            await attachVideo(blob);
            showEditor();
            msg('録画が完了しました。編集を開始できます。',true);
        };

        recorder.start(250);

        $('startRecord').disabled=true;
        $('pauseRecord').disabled=false;
        $('stopRecord').disabled=false;

        $('preview').srcObject=recordStream;
        $('preview').style.display='block';

        recordTimer=setInterval(()=>{
            $('timer').textContent=fmtTime((Date.now()-recordStart)/1000);
        },200);

        display.getVideoTracks()[0].onended=()=>{
            if (recorder?.state==='recording') recorder.stop();
        };

    } catch(e) {
        msg('録画を開始できませんでした。\n'+e.message);
    }
};

$('pauseRecord').onclick=()=>{
    if (!recorder) return;

    if (recorder.state==='recording') {
        recorder.pause();
        $('pauseRecord').textContent='再開';
    } else if (recorder.state==='paused') {
        recorder.resume();
        $('pauseRecord').textContent='一時停止';
    }
};

$('stopRecord').onclick=()=>{
    if (recorder && recorder.state!=='inactive') recorder.stop();

    $('startRecord').disabled=false;
    $('pauseRecord').disabled=true;
    $('stopRecord').disabled=true;
    $('pauseRecord').textContent='一時停止';
};

$('recordCancel').onclick=()=>{
    if (recorder && recorder.state!=='inactive') recorder.stop();

    recordStream?.getTracks().forEach(t=>t.stop());
    $('recorder').style.display='none';
    $('home').style.display='flex';
};

openDB()
.then(async()=>{
    setStatus('準備完了');
    await loadProjectList();
})
.catch(e=>{
    msg(
        'ブラウザのローカル保存を初期化できませんでした。\n'+
        'プライベートブラウジング等の設定を確認してください。'
    );
});

})();
</script>
</body>
</html>

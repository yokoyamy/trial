<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP / index.php 1ファイル
 *
 * 保存:
 *   サーバー: ./data/projects/*.json
 *   ブラウザ: IndexedDB
 *
 * 動画本体はブラウザ側IndexedDBへ保存。
 * サーバーには編集プロジェクト情報のみ保存。
 */

const APP_VERSION = 5;
const MAX_SERVER_PROJECTS = 20;

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
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

function readServerProjects(): array
{
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

function projectSummary(array $project): array
{
    return [
        'projectId' => (string)($project['projectId'] ?? ''),
        'name' => (string)($project['name'] ?? ''),
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
                $id = 'project-' . str_replace('.', '', uniqid('', true));
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
                'message' => 'プロジェクト名は120文字以内にしてください。'
            ], 422);
        }

        $existing = is_file(projectPath($id));
        $projects = readServerProjects();

        if (!$existing && count($projects) >= MAX_SERVER_PROJECTS) {
            jsonResponse([
                'ok' => false,
                'limit' => true,
                'message' => 'サーバー保存上限に達しています。ローカル保存を使用してください。'
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
    --panel2:#252a31;
    --border:#3c424b;
    --text:#f4f6f8;
    --muted:#9aa3ad;
    --blue:#1672c8;
    --green:#287d49;
    --red:#a93636;
    --yellow:#d6a92d;
}

*{box-sizing:border-box}

html,body{
    width:100%;
    height:100%;
    margin:0;
    overflow:hidden;
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

button,input,select,textarea{
    font:inherit;
}

button{
    border:1px solid #505761;
    border-radius:6px;
    background:#30353c;
    color:#fff;
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
    border:1px solid #4d545e;
    border-radius:5px;
    padding:8px;
    background:#252a30;
    color:#fff;
}

input[type=color]{
    height:38px;
    padding:3px;
}

textarea{
    min-height:100px;
    resize:vertical;
}

.hidden{display:none!important}

#message{
    position:fixed;
    z-index:10000;
    top:60px;
    left:50%;
    transform:translateX(-50%);
    display:none;
    max-width:90vw;
    padding:10px 17px;
    border-radius:7px;
    background:#963838;
    box-shadow:0 10px 40px #000b;
    white-space:pre-wrap;
}

#message.ok{
    background:#287547;
}

/* =========================================================
   HOME
========================================================= */

#home{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#111318;
}

.home-card{
    width:min(720px,94vw);
    max-height:90vh;
    overflow:auto;
    padding:26px;
    border:1px solid var(--border);
    border-radius:10px;
    background:#1b1e23;
    box-shadow:0 20px 70px #0008;
}

.home-card h1{
    margin:0 0 8px;
    font-size:22px;
}

.home-card p{
    color:var(--muted);
    line-height:1.7;
    font-size:13px;
}

.home-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:18px;
}

.project-list{
    margin-top:22px;
}

.project{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin:7px 0;
    padding:10px;
    border:1px solid #41474f;
    border-radius:7px;
}

.project-name{
    font-weight:600;
}

.project-meta{
    margin-top:3px;
    color:var(--muted);
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
    position:absolute;
    inset:0;
    display:none;
    flex-direction:column;
    background:#0b0c0e;
}

.editor-top{
    height:52px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
    background:#1b1e22;
    border-bottom:1px solid #343a42;
}

#projectName{
    width:230px;
    font-weight:600;
}

#editorStatus{
    margin-left:auto;
    color:#9da5ae;
    font-size:12px;
}

.video-area{
    position:relative;
    flex:1;
    min-height:0;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:#000;
}

#videoStage{
    position:relative;
    flex:none;
    background:#000;
}

#video{
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
    pointer-events:none;
}

.edit-object{
    position:absolute;
    pointer-events:auto;
    user-select:none;
    touch-action:none;
    cursor:move;
}

.edit-object.selected{
    outline:2px solid #fff;
    outline-offset:2px;
}

.edit-object.text{
    display:flex;
    align-items:center;
    justify-content:flex-start;
    padding:6px 9px;
    overflow:hidden;
    white-space:pre-wrap;
    word-break:break-word;
}

.edit-object.box{
    background:transparent;
}

.resize{
    position:absolute;
    width:12px;
    height:12px;
    border-radius:50%;
    border:1px solid #222;
    background:#fff;
    display:none;
}

.edit-object.selected .resize{
    display:block;
}

.r-nw{left:-6px;top:-6px;cursor:nwse-resize}
.r-ne{right:-6px;top:-6px;cursor:nesw-resize}
.r-sw{left:-6px;bottom:-6px;cursor:nesw-resize}
.r-se{right:-6px;bottom:-6px;cursor:nwse-resize}

.connection-point{
    position:absolute;
    width:11px;
    height:11px;
    margin:-5.5px;
    border:2px solid #fff;
    border-radius:50%;
    background:#1474ce;
    display:none;
    pointer-events:auto;
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

#connectors{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
}

.connector{
    fill:none;
    stroke:#fff;
    pointer-events:stroke;
    cursor:pointer;
}

.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:16;
    pointer-events:stroke;
    cursor:pointer;
}

.connector.selected{
    stroke:#ffd64b;
}

/* =========================================================
   TIMELINE
========================================================= */

#timeline{
    height:190px;
    flex-shrink:0;
    display:flex;
    flex-direction:column;
    background:#191c20;
    border-top:1px solid #383d45;
}

.timeline-head{
    height:43px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:10px;
    padding:5px 10px;
}

#seek{
    flex:1;
    min-width:0;
    accent-color:#3d9cf0;
}

#time{
    width:105px;
    text-align:right;
    color:#c8ced5;
    font-size:12px;
    font-variant-numeric:tabular-nums;
}

.timeline-scroll{
    flex:1;
    min-height:0;
    overflow:auto;
    padding:0 10px 9px;
}

.track-row{
    display:grid;
    grid-template-columns:72px minmax(300px,1fr);
    gap:8px;
    align-items:center;
    height:34px;
}

.track-label{
    color:#aeb5bd;
    font-size:11px;
}

.track{
    position:relative;
    height:22px;
    border-radius:4px;
    background:#282d33;
    overflow:hidden;
}

.track::after{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        repeating-linear-gradient(
            to right,
            transparent 0,
            transparent calc(10% - 1px),
            #ffffff12 calc(10% - 1px),
            #ffffff12 10%
        );
}

.track-item{
    position:absolute;
    top:3px;
    bottom:3px;
    min-width:4px;
    border-radius:3px;
    cursor:ew-resize;
    z-index:2;
}

.track-item.text{background:#42a5f5}
.track-item.box{background:#ef5350}
.track-item.connection{background:#b77bff}

.track-item.selected{
    box-shadow:0 0 0 2px #fff inset;
}

.track-handle{
    position:absolute;
    top:0;
    bottom:0;
    width:7px;
    cursor:ew-resize;
    z-index:3;
}

.track-handle.left{left:0}
.track-handle.right{right:0}

#editorFooter{
    height:45px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border-top:1px solid #343940;
    background:#1b1e22;
}

/* =========================================================
   CONTEXT MENU
========================================================= */

#contextMenu{
    position:fixed;
    z-index:8000;
    display:none;
    width:220px;
    padding:5px;
    border:1px solid #555c65;
    border-radius:7px;
    background:#292e34;
    box-shadow:0 15px 50px #000d;
}

#contextMenu button{
    width:100%;
    display:block;
    text-align:left;
    border:0;
    background:transparent;
}

#contextMenu button:hover{
    background:#3d444d;
}

.context-separator{
    height:1px;
    margin:5px 0;
    background:#4a5058;
}

/* =========================================================
   MODAL
========================================================= */

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
    width:min(620px,96vw);
    max-height:92vh;
    overflow:auto;
    padding:18px;
    border:1px solid #555c65;
    border-radius:9px;
    background:#20242a;
    box-shadow:0 20px 80px #000d;
}

.modal h2{
    margin:0 0 14px;
    font-size:17px;
}

.modal-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 10px;
}

.field{
    display:block;
    margin:8px 0;
    color:#c8ced5;
    font-size:12px;
}

.full{
    grid-column:1/-1;
}

.modal-footer{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:15px;
}

.help{
    color:#929aa4;
    font-size:12px;
    line-height:1.6;
}

.manage-list{
    margin-top:12px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:700px){
    #projectName{
        width:150px;
    }

    .editor-top{
        overflow-x:auto;
    }

    .modal-grid{
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

<!-- =====================================================
     HOME
====================================================== -->

<section id="home">
    <div class="home-card">
        <h1>動画上直接編集ツール</h1>

        <p>
            動画を読み込んでから編集します。
            編集対象は動画そのものではなく、動画上に配置する
            テキスト・強調枠・接続線です。
            要素の追加、移動、サイズ変更、削除、書式変更は
            動画上で右クリックして操作できます。
        </p>

        <div class="home-actions">
            <button id="openVideo" class="primary">
                動画ファイルを読み込む
            </button>

            <button id="manageProjects">
                保存データ
            </button>
        </div>

        <input
            id="videoFile"
            class="hidden"
            type="file"
            accept="video/*"
        >

        <div id="projectList" class="project-list"></div>
    </div>
</section>

<!-- =====================================================
     EDITOR
====================================================== -->

<section id="editor">

    <header class="editor-top">

        <button id="closeEditor">
            閉じる
        </button>

        <input
            id="projectName"
            maxlength="120"
            placeholder="プロジェクト名"
        >

        <button id="saveServer" class="success">
            保存
        </button>

        <button id="saveLocal">
            ローカル保存
        </button>

        <button id="exportProject">
            書き出し
        </button>

        <button id="importProject">
            読み込み
        </button>

        <input
            id="projectFile"
            type="file"
            accept=".json,application/json"
            class="hidden"
        >

        <span id="editorStatus"></span>

    </header>

    <main class="video-area" id="videoArea">

        <div id="videoStage">

            <video
                id="video"
                playsinline
                controls
            ></video>

            <div id="overlay">

                <svg id="connectors"></svg>

                <div id="objects"></div>

            </div>

        </div>

    </main>

    <section id="timeline">

        <div class="timeline-head">

            <button id="playPause">
                再生
            </button>

            <input
                id="seek"
                type="range"
                min="0"
                max="0"
                step="0.001"
                value="0"
                disabled
            >

            <div id="time">
                00:00 / 00:00
            </div>

        </div>

        <div class="timeline-scroll">

            <div class="track-row">
                <div class="track-label">テキスト</div>
                <div id="textTrack" class="track"></div>
            </div>

            <div class="track-row">
                <div class="track-label">強調枠</div>
                <div id="boxTrack" class="track"></div>
            </div>

            <div class="track-row">
                <div class="track-label">線</div>
                <div id="connectionTrack" class="track"></div>
            </div>

        </div>

    </section>

    <footer id="editorFooter">

        <span class="help">
            動画上で右クリック：追加・編集・削除・書式変更
        </span>

    </footer>

</section>

<!-- =====================================================
     CONTEXT MENU
====================================================== -->

<div id="contextMenu">

    <button data-action="add-text">
        ＋ テキストを追加
    </button>

    <button data-action="add-box">
        ＋ 強調枠を追加
    </button>

    <button data-action="add-connection">
        ＋ 要素間を線で接続
    </button>

    <div class="context-separator"></div>

    <button data-action="edit">
        編集・書式変更
    </button>

    <button data-action="delete">
        削除
    </button>

</div>

<!-- =====================================================
     OBJECT MODAL
====================================================== -->

<div id="objectModal" class="modal-backdrop">

    <div class="modal">

        <h2 id="objectModalTitle">
            要素を編集
        </h2>

        <div id="objectForm">

            <label
                id="textContentField"
                class="field full"
            >
                テキスト
                <textarea id="modalText"></textarea>
            </label>

            <div class="modal-grid">

                <label class="field">
                    開始秒
                    <input
                        id="modalStart"
                        type="number"
                        min="0"
                        step="0.01"
                    >
                </label>

                <label class="field">
                    終了秒
                    <input
                        id="modalEnd"
                        type="number"
                        min="0"
                        step="0.01"
                    >
                </label>

                <label class="field">
                    文字色
                    <input
                        id="modalColor"
                        type="color"
                    >
                </label>

                <label
                    id="backgroundField"
                    class="field"
                >
                    背景色
                    <input
                        id="modalBackground"
                        type="color"
                    >
                </label>

                <label class="field">
                    透明度
                    <input
                        id="modalOpacity"
                        type="number"
                        min="0"
                        max="1"
                        step="0.05"
                    >
                </label>

                <label
                    id="fontSizeField"
                    class="field"
                >
                    文字サイズ
                    <input
                        id="modalFontSize"
                        type="number"
                        min="8"
                        max="200"
                    >
                </label>

                <label
                    id="borderWidthField"
                    class="field"
                >
                    枠線幅
                    <input
                        id="modalBorderWidth"
                        type="number"
                        min="0"
                        max="30"
                        step="0.5"
                    >
                </label>

                <label
                    id="borderColorField"
                    class="field"
                >
                    枠線色
                    <input
                        id="modalBorderColor"
                        type="color"
                    >
                </label>

                <label
                    id="lineWidthField"
                    class="field"
                >
                    線幅
                    <input
                        id="modalLineWidth"
                        type="number"
                        min="0.5"
                        max="30"
                        step="0.5"
                    >
                </label>

                <label
                    id="lineStyleField"
                    class="field"
                >
                    線種
                    <select id="modalLineStyle">
                        <option value="solid">実線</option>
                        <option value="dash">破線</option>
                        <option value="dot">点線</option>
                    </select>
                </label>

                <label
                    id="arrowField"
                    class="field"
                >
                    終点矢印
                    <select id="modalArrow">
                        <option value="none">なし</option>
                        <option value="arrow">矢印</option>
                    </select>
                </label>

            </div>

        </div>

        <div class="modal-footer">

            <button id="modalCancel">
                キャンセル
            </button>

            <button id="modalDelete" class="danger">
                削除
            </button>

            <button id="modalApply" class="primary">
                適用
            </button>

        </div>

    </div>

</div>

<!-- =====================================================
     CONNECTION MODAL
====================================================== -->

<div id="connectionModal" class="modal-backdrop">

    <div class="modal">

        <h2>線を編集</h2>

        <div class="modal-grid">

            <label class="field">
                開始秒
                <input
                    id="connStart"
                    type="number"
                    min="0"
                    step="0.01"
                >
            </label>

            <label class="field">
                終了秒
                <input
                    id="connEnd"
                    type="number"
                    min="0"
                    step="0.01"
                >
            </label>

            <label class="field">
                線色
                <input
                    id="connColor"
                    type="color"
                    value="#ffffff"
                >
            </label>

            <label class="field">
                線幅
                <input
                    id="connWidth"
                    type="number"
                    min="0.5"
                    max="30"
                    step="0.5"
                    value="2.5"
                >
            </label>

            <label class="field">
                線種
                <select id="connStyle">
                    <option value="solid">実線</option>
                    <option value="dash">破線</option>
                    <option value="dot">点線</option>
                </select>
            </label>

            <label class="field">
                終点矢印
                <select id="connArrow">
                    <option value="none">なし</option>
                    <option value="arrow">矢印</option>
                </select>
            </label>

        </div>

        <div class="modal-footer">

            <button id="connCancel">
                キャンセル
            </button>

            <button id="connDelete" class="danger">
                削除
            </button>

            <button id="connApply" class="primary">
                適用
            </button>

        </div>

    </div>

</div>

<!-- =====================================================
     PROJECT MANAGER
====================================================== -->

<div id="manageModal" class="modal-backdrop">

    <div class="modal">

        <h2>保存データ</h2>

        <div id="manageInfo" class="help"></div>

        <div id="manageList" class="manage-list"></div>

        <div class="modal-footer">

            <button id="manageClose">
                閉じる
            </button>

        </div>

    </div>

</div>

<script>
'use strict';

/* =========================================================
   PHPから確実にバージョンを渡す
========================================================= */

const APP_VERSION = <?= json_encode(APP_VERSION) ?>;
const SERVER_PROJECT_LIMIT = <?= json_encode(MAX_SERVER_PROJECTS) ?>;

/* =========================================================
   Utility
========================================================= */

const $ = id => document.getElementById(id);

function uid(prefix = 'id')
{
    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2, 9);
}

function clamp(value, min, max)
{
    return Math.min(max, Math.max(min, value));
}

function nowISO()
{
    return new Date().toISOString();
}

function formatTime(sec)
{
    sec = Math.max(0, Number(sec) || 0);

    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = Math.floor(sec % 60);

    return h > 0
        ? `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
        : `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function escapeHtml(value)
{
    return String(value ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function showMessage(message, ok = false)
{
    const el = $('message');

    el.textContent = message;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    clearTimeout(showMessage.timer);

    showMessage.timer = setTimeout(() => {
        el.style.display = 'none';
    }, 3500);
}

/* =========================================================
   State
========================================================= */

const state = {
    project: null,
    videoBlob: null,
    videoUrl: null,

    selected: null,
    selectedType: null,

    contextTarget: null,

    connectSource: null,

    drag: null,

    dirty: false,

    modalTarget: null,

    timelineDrag: null
};

/* =========================================================
   Project model
========================================================= */

function emptyProject()
{
    return {
        version: APP_VERSION,
        projectId: uid('project'),
        name: '新しい動画編集',
        videoName: '',
        videoType: '',
        videoSize: 0,
        videoKey: '',
        duration: 0,
        createdAt: nowISO(),
        savedAt: '',
        elements: [],
        connections: []
    };
}

function normalizeProject(project)
{
    project = project && typeof project === 'object'
        ? project
        : emptyProject();

    project.version = APP_VERSION;
    project.elements = Array.isArray(project.elements)
        ? project.elements
        : [];
    project.connections = Array.isArray(project.connections)
        ? project.connections
        : [];

    project.elements.forEach(normalizeElement);
    project.connections.forEach(normalizeConnection);

    return project;
}

function normalizeElement(e)
{
    e.id = e.id || uid('element');
    e.type = e.type === 'box' ? 'box' : 'text';

    e.x = Number.isFinite(+e.x) ? +e.x : 30;
    e.y = Number.isFinite(+e.y) ? +e.y : 30;
    e.w = Number.isFinite(+e.w) ? +e.w : 25;
    e.h = Number.isFinite(+e.h) ? +e.h : 12;

    e.x = clamp(e.x,0,99);
    e.y = clamp(e.y,0,99);
    e.w = clamp(e.w,.5,100-e.x);
    e.h = clamp(e.h,.5,100-e.y);

    e.start = Number.isFinite(+e.start) ? +e.start : 0;
    e.end = Number.isFinite(+e.end)
        ? +e.end
        : Math.max(0, state.project?.duration || 5);

    e.text = String(e.text ?? 'テキスト');

    e.color = e.color || '#ffffff';
    e.background = e.background || '#000000';
    e.borderColor = e.borderColor || '#ff3333';

    e.opacity = clamp(
        Number.isFinite(+e.opacity) ? +e.opacity : .9,
        0,
        1
    );

    e.fontSize = Number.isFinite(+e.fontSize)
        ? +e.fontSize
        : 32;

    e.borderWidth = Number.isFinite(+e.borderWidth)
        ? +e.borderWidth
        : 3;

    e.fontWeight = Number.isFinite(+e.fontWeight)
        ? +e.fontWeight
        : 600;
}

function normalizeConnection(c)
{
    c.id = c.id || uid('connection');

    c.from = String(c.from || '');
    c.to = String(c.to || '');

    c.fromPoint = c.fromPoint || 'e';
    c.toPoint = c.toPoint || 'w';

    c.start = Number.isFinite(+c.start)
        ? +c.start
        : 0;

    c.end = Number.isFinite(+c.end)
        ? +c.end
        : Math.max(0, state.project?.duration || 5);

    c.color = c.color || '#ffffff';

    c.width = Number.isFinite(+c.width)
        ? +c.width
        : 2.5;

    c.style = c.style || 'solid';
    c.arrow = c.arrow || 'arrow';
}

/* =========================================================
   IndexedDB
========================================================= */

const DB_NAME = 'video-direct-editor';
const DB_VERSION = 2;

let dbPromise;

function openDB()
{
    if (dbPromise) return dbPromise;

    dbPromise = new Promise((resolve,reject) => {

        const request = indexedDB.open(
            DB_NAME,
            DB_VERSION
        );

        request.onupgradeneeded = event => {

            const db = event.target.result;

            if (!db.objectStoreNames.contains('videos')) {
                db.createObjectStore(
                    'videos',
                    {keyPath:'key'}
                );
            }

            if (!db.objectStoreNames.contains('projects')) {
                db.createObjectStore(
                    'projects',
                    {keyPath:'projectId'}
                );
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);

    });

    return dbPromise;
}

async function idbPut(storeName,value)
{
    const db = await openDB();

    return new Promise((resolve,reject) => {

        const tx = db.transaction(
            storeName,
            'readwrite'
        );

        tx.objectStore(storeName).put(value);

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);

    });
}

async function idbGet(storeName,key)
{
    const db = await openDB();

    return new Promise((resolve,reject) => {

        const tx = db.transaction(
            storeName,
            'readonly'
        );

        const req =
            tx.objectStore(storeName).get(key);

        req.onsuccess = () =>
            resolve(req.result);

        req.onerror = () =>
            reject(req.error);

    });
}

async function idbGetAll(storeName)
{
    const db = await openDB();

    return new Promise((resolve,reject) => {

        const tx = db.transaction(
            storeName,
            'readonly'
        );

        const req =
            tx.objectStore(storeName).getAll();

        req.onsuccess = () =>
            resolve(req.result || []);

        req.onerror = () =>
            reject(req.error);

    });
}

async function idbDelete(storeName,key)
{
    const db = await openDB();

    return new Promise((resolve,reject) => {

        const tx = db.transaction(
            storeName,
            'readwrite'
        );

        tx.objectStore(storeName).delete(key);

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);

    });
}

/* =========================================================
   Video loading
========================================================= */

async function readVideoFile(file)
{
    if (!file) return;

    $('editorStatus').textContent =
        '動画を読み込んでいます…';

    const project = emptyProject();

    project.name =
        file.name.replace(/\.[^.]+$/,'') ||
        '動画編集';

    project.videoName = file.name;
    project.videoType = file.type;
    project.videoSize = file.size;

    const videoKey = uid('video');

    await idbPut('videos',{
        key:videoKey,
        name:file.name,
        type:file.type,
        size:file.size,
        savedAt:nowISO(),
        blob:file
    });

    project.videoKey = videoKey;

    await openEditor(project,file);
}

async function openEditor(project,videoBlob)
{
    if (!videoBlob) {
        throw new Error(
            '動画本体がありません。'
        );
    }

    state.project =
        normalizeProject(project);

    state.videoBlob = videoBlob;

    if (state.videoUrl) {
        URL.revokeObjectURL(state.videoUrl);
    }

    state.videoUrl =
        URL.createObjectURL(videoBlob);

    $('video').src =
        state.videoUrl;

    $('projectName').value =
        state.project.name;

    $('home').style.display = 'none';
    $('editor').style.display = 'flex';

    state.selected = null;
    state.selectedType = null;
    state.connectSource = null;
    state.dirty = false;

    await waitVideoMetadata();

    state.project.duration =
        Number($('video').duration) || 0;

    state.project.elements.forEach(normalizeElement);
    state.project.connections.forEach(normalizeConnection);

    $('seek').disabled =
        state.project.duration <= 0;

    $('seek').min = 0;
    $('seek').max =
        String(state.project.duration);
    $('seek').step = '0.001';
    $('seek').value = '0';

    fitVideoStage();
    renderAll();

    $('editorStatus').textContent =
        '編集可能';

    showMessage(
        '動画の読み込みが完了しました。',
        true
    );
}

function waitVideoMetadata()
{
    return new Promise((resolve,reject) => {

        const video = $('video');

        if (video.readyState >= 1 &&
            Number.isFinite(video.duration)) {
            resolve();
            return;
        }

        const onLoaded = () => {
            cleanup();
            resolve();
        };

        const onError = () => {
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
                onLoaded
            );

            video.removeEventListener(
                'error',
                onError
            );
        };

        video.addEventListener(
            'loadedmetadata',
            onLoaded
        );

        video.addEventListener(
            'error',
            onError
        );
    });
}

/* =========================================================
   Video stage
========================================================= */

function fitVideoStage()
{
    const video = $('video');

    if (!video.videoWidth ||
        !video.videoHeight) {
        return;
    }

    const area =
        $('videoArea').getBoundingClientRect();

    const maxW = area.width;
    const maxH = area.height;

    const ratio =
        video.videoWidth /
        video.videoHeight;

    let width = maxW;
    let height = width / ratio;

    if (height > maxH) {
        height = maxH;
        width = height * ratio;
    }

    $('videoStage').style.width =
        `${Math.max(1,width)}px`;

    $('videoStage').style.height =
        `${Math.max(1,height)}px`;
}

/* =========================================================
   Visibility
========================================================= */

function activeAt(item,time)
{
    return (
        time >= Number(item.start) &&
        time <= Number(item.end)
    );
}

/* =========================================================
   Render
========================================================= */

function renderAll()
{
    if (!state.project) return;

    renderObjects();
    renderConnections();
    renderTimeline();
    updateTime();
}

function renderObjects()
{
    const root = $('objects');
    root.innerHTML = '';

    const time = Number($('video').currentTime) || 0;

    state.project.elements.forEach(item => {

        if (!activeAt(item,time)) {
            return;
        }

        const el =
            document.createElement('div');

        el.className =
            `edit-object ${item.type}`;

        if (
            state.selectedType === 'element' &&
            state.selected === item.id
        ) {
            el.classList.add('selected');
        }

        el.dataset.id = item.id;

        el.style.left = `${item.x}%`;
        el.style.top = `${item.y}%`;
        el.style.width = `${item.w}%`;
        el.style.height = `${item.h}%`;

        el.style.opacity = item.opacity;

        if (item.type === 'text') {

            el.textContent = item.text;

            el.style.color =
                item.color;

            el.style.background =
                item.background;

            el.style.fontSize =
                `${item.fontSize}px`;

            el.style.fontWeight =
                item.fontWeight;

            el.style.border =
                `${item.borderWidth}px solid ${item.borderColor}`;

        } else {

            el.style.border =
                `${item.borderWidth}px solid ${item.borderColor}`;

        }

        [
            'nw',
            'ne',
            'sw',
            'se'
        ].forEach(point => {

            const handle =
                document.createElement('div');

            handle.className =
                `resize r-${point}`;

            handle.dataset.resize =
                point;

            el.appendChild(handle);
        });

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
                `connection-point cp-${point}`;

            cp.dataset.point = point;

            el.appendChild(cp);
        });

        root.appendChild(el);
    });
}

function pointPosition(item,point)
{
    const map = {
        n:[item.x + item.w / 2,item.y],
        ne:[item.x + item.w,item.y],
        e:[item.x + item.w,item.y + item.h / 2],
        se:[item.x + item.w,item.y + item.h],
        s:[item.x + item.w / 2,item.y + item.h],
        sw:[item.x,item.y + item.h],
        w:[item.x,item.y + item.h / 2],
        nw:[item.x,item.y]
    };

    return map[point] || map.e;
}

function renderConnections()
{
    const svg = $('connectors');

    svg.innerHTML = '';

    if (!state.project) return;

    const time =
        Number($('video').currentTime) || 0;

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

    marker.setAttribute('id','arrow');
    marker.setAttribute('markerWidth','8');
    marker.setAttribute('markerHeight','8');
    marker.setAttribute('refX','7');
    marker.setAttribute('refY','3');
    marker.setAttribute('orient','auto');

    const path =
        document.createElementNS(
            'http://www.w3.org/2000/svg',
            'path'
        );

    path.setAttribute(
        'd',
        'M0,0 L0,6 L7,3 z'
    );

    path.setAttribute(
        'fill',
        'context-stroke'
    );

    marker.appendChild(path);
    defs.appendChild(marker);
    svg.appendChild(defs);

    state.project.connections.forEach(c => {

        if (!activeAt(c,time)) return;

        const from =
            state.project.elements.find(
                e => e.id === c.from
            );

        const to =
            state.project.elements.find(
                e => e.id === c.to
            );

        if (!from || !to) return;

        const [x1,y1] =
            pointPosition(
                from,
                c.fromPoint
            );

        const [x2,y2] =
            pointPosition(
                to,
                c.toPoint
            );

        const selected =
            state.selectedType === 'connection' &&
            state.selected === c.id;

        const line =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'path'
            );

        line.dataset.id = c.id;
        line.classList.add('connector');

        if (selected) {
            line.classList.add('selected');
        }

        const dx = Math.abs(x2 - x1);
        const curve =
            Math.max(5,dx * .35);

        let d;

        if (c.style === 'curve') {
            d =
                `M ${x1} ${y1} C ${x1+curve} ${y1}, ${x2-curve} ${y2}, ${x2} ${y2}`;
        } else {
            d =
                `M ${x1} ${y1} L ${x2} ${y2}`;
        }

        line.setAttribute('d',d);
        line.setAttribute('stroke',c.color);
        line.setAttribute('stroke-width',c.width);

        if (c.style === 'dash') {
            line.setAttribute(
                'stroke-dasharray',
                '10 7'
            );
        } else if (c.style === 'dot') {
            line.setAttribute(
                'stroke-dasharray',
                '2 6'
            );
        }

        if (c.arrow === 'arrow') {
            line.setAttribute(
                'marker-end',
                'url(#arrow)'
            );
        }

        const hit =
            line.cloneNode();

        hit.classList.remove('connector');
        hit.classList.add('connector-hit');

        hit.dataset.id = c.id;

        svg.appendChild(hit);
        svg.appendChild(line);
    });
}

/* =========================================================
   Timeline
========================================================= */

function renderTimeline()
{
    ['textTrack','boxTrack','connectionTrack']
        .forEach(id => {
            $(id).innerHTML = '';
        });

    if (!state.project.duration) return;

    const duration =
        state.project.duration;

    const tracks = {
        text:'textTrack',
        box:'boxTrack',
        connection:'connectionTrack'
    };

    state.project.elements.forEach(item => {

        addTimelineItem(
            $(tracks[item.type]),
            item,
            'element'
        );
    });

    state.project.connections.forEach(item => {

        addTimelineItem(
            $('connectionTrack'),
            item,
            'connection'
        );
    });
}

function addTimelineItem(track,item,type)
{
    const duration =
        Math.max(
            0.001,
            state.project.duration
        );

    const bar =
        document.createElement('div');

    bar.className =
        `track-item ${item.type || 'connection'}`;

    if (
        state.selected === item.id &&
        state.selectedType === type
    ) {
        bar.classList.add('selected');
    }

    bar.dataset.id = item.id;
    bar.dataset.type = type;

    bar.style.left =
        `${clamp(item.start / duration * 100,0,100)}%`;

    bar.style.width =
        `${clamp((item.end-item.start) / duration * 100,0,100)}%`;

    const left =
        document.createElement('div');

    left.className =
        'track-handle left';

    const right =
        document.createElement('div');

    right.className =
        'track-handle right';

    bar.appendChild(left);
    bar.appendChild(right);

    track.appendChild(bar);
}

/* =========================================================
   Selection
========================================================= */

function selectElement(id)
{
    state.selected = id;
    state.selectedType = 'element';

    renderAll();
}

function selectConnection(id)
{
    state.selected = id;
    state.selectedType = 'connection';

    renderAll();
}

/* =========================================================
   Object creation
========================================================= */

function currentTime()
{
    return Number($('video').currentTime) || 0;
}

function defaultEnd()
{
    return Math.min(
        state.project.duration,
        currentTime() + 5
    );
}

function addText(x = 25,y = 25)
{
    const item = {
        id:uid('element'),
        type:'text',
        x:clamp(x,0,75),
        y:clamp(y,0,75),
        w:25,
        h:12,
        text:'テキスト',
        start:currentTime(),
        end:defaultEnd(),
        color:'#ffffff',
        background:'#000000',
        borderColor:'#ffffff',
        borderWidth:1,
        fontSize:32,
        fontWeight:600,
        opacity:.9
    };

    state.project.elements.push(item);

    selectElement(item.id);
    markDirty();

    openObjectModal(item);
}

function addBox(x = 20,y = 20)
{
    const item = {
        id:uid('element'),
        type:'box',
        x:clamp(x,0,80),
        y:clamp(y,0,80),
        w:30,
        h:25,
        text:'',
        start:currentTime(),
        end:defaultEnd(),
        color:'#ffffff',
        background:'#000000',
        borderColor:'#ff3333',
        borderWidth:4,
        fontSize:32,
        fontWeight:600,
        opacity:1
    };

    state.project.elements.push(item);

    selectElement(item.id);
    markDirty();

    openObjectModal(item);
}

/* =========================================================
   Context menu
========================================================= */

function showContextMenu(x,y)
{
    const menu = $('contextMenu');

    menu.style.display = 'block';

    const rect =
        menu.getBoundingClientRect();

    menu.style.left =
        `${Math.min(
            x,
            window.innerWidth - rect.width - 5
        )}px`;

    menu.style.top =
        `${Math.min(
            y,
            window.innerHeight - rect.height - 5
        )}px`;

    updateContextMenu();
}

function hideContextMenu()
{
    $('contextMenu').style.display = 'none';
    state.contextTarget = null;
}

function updateContextMenu()
{
    const hasTarget =
        !!state.contextTarget;

    $('contextMenu')
        .querySelector('[data-action="edit"]')
        .style.display =
            hasTarget ? 'block' : 'none';

    $('contextMenu')
        .querySelector('[data-action="delete"]')
        .style.display =
            hasTarget ? 'block' : 'none';
}

/* =========================================================
   Element drag / resize
========================================================= */

$('objects').addEventListener(
    'pointerdown',
    event => {

        const object =
            event.target.closest('.edit-object');

        if (!object) return;

        const item =
            state.project.elements.find(
                e => e.id === object.dataset.id
            );

        if (!item) return;

        selectElement(item.id);

        const resize =
            event.target.closest('.resize');

        const rect =
            $('videoStage').getBoundingClientRect();

        state.drag = {
            id:item.id,
            mode:resize ? 'resize' : 'move',
            handle:resize?.dataset.resize || '',
            startX:event.clientX,
            startY:event.clientY,
            x:item.x,
            y:item.y,
            w:item.w,
            h:item.h,
            stageW:rect.width,
            stageH:rect.height
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

        if (!state.drag) return;

        const d = state.drag;

        const item =
            state.project.elements.find(
                e => e.id === d.id
            );

        if (!item) return;

        const dx =
            (event.clientX - d.startX) /
            d.stageW * 100;

        const dy =
            (event.clientY - d.startY) /
            d.stageH * 100;

        if (d.mode === 'move') {

            item.x =
                clamp(
                    d.x + dx,
                    0,
                    100 - item.w
                );

            item.y =
                clamp(
                    d.y + dy,
                    0,
                    100 - item.h
                );

        } else {

            let x = d.x;
            let y = d.y;
            let w = d.w;
            let h = d.h;

            if (d.handle.includes('e')) {
                w =
                    clamp(
                        d.w + dx,
                        .5,
                        100 - x
                    );
            }

            if (d.handle.includes('s')) {
                h =
                    clamp(
                        d.h + dy,
                        .5,
                        100 - y
                    );
            }

            if (d.handle.includes('w')) {

                const newX =
                    clamp(
                        d.x + dx,
                        0,
                        d.x + d.w - .5
                    );

                w =
                    d.w - (newX - d.x);

                x = newX;
            }

            if (d.handle.includes('n')) {

                const newY =
                    clamp(
                        d.y + dy,
                        0,
                        d.y + d.h - .5
                    );

                h =
                    d.h - (newY - d.y);

                y = newY;
            }

            item.x = x;
            item.y = y;
            item.w = w;
            item.h = h;
        }

        markDirty();
        renderAll();
    }
);

$('objects').addEventListener(
    'pointerup',
    () => {
        state.drag = null;
    }
);

/* =========================================================
   Right click
========================================================= */

$('objects').addEventListener(
    'contextmenu',
    event => {

        const object =
            event.target.closest('.edit-object');

        if (!object) return;

        event.preventDefault();
        event.stopPropagation();

        selectElement(object.dataset.id);

        state.contextTarget = {
            type:'element',
            id:object.dataset.id
        };

        showContextMenu(
            event.clientX,
            event.clientY
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

        if (!target) return;

        event.preventDefault();

        selectConnection(
            target.dataset.id
        );

        state.contextTarget = {
            type:'connection',
            id:target.dataset.id
        };

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

$('videoStage').addEventListener(
    'contextmenu',
    event => {

        if (
            event.target.closest(
                '.edit-object,.connector,.connector-hit,.connection-point,.resize'
            )
        ) {
            return;
        }

        event.preventDefault();

        const rect =
            $('videoStage').getBoundingClientRect();

        state.contextTarget = null;

        state.contextPosition = {
            x:
                (event.clientX - rect.left) /
                rect.width * 100,

            y:
                (event.clientY - rect.top) /
                rect.height * 100
        };

        showContextMenu(
            event.clientX,
            event.clientY
        );
    }
);

/* =========================================================
   Context actions
========================================================= */

$('contextMenu').addEventListener(
    'click',
    event => {

        const button =
            event.target.closest('button');

        if (!button) return;

        const action =
            button.dataset.action;

        const target =
            state.contextTarget;

        const position =
            state.contextPosition || {
                x:25,
                y:25
            };

        hideContextMenu();

        if (action === 'add-text') {
            addText(
                position.x,
                position.y
            );
            return;
        }

        if (action === 'add-box') {
            addBox(
                position.x,
                position.y
            );
            return;
        }

        if (action === 'add-connection') {

            state.connectSource = null;

            showMessage(
                '接続元の要素を選択し、表示された接点をクリックしてください。',
                true
            );

            return;
        }

        if (!target) return;

        if (action === 'edit') {

            if (target.type === 'element') {

                const item =
                    state.project.elements.find(
                        e => e.id === target.id
                    );

                if (item) {
                    openObjectModal(item);
                }

            } else {

                const item =
                    state.project.connections.find(
                        c => c.id === target.id
                    );

                if (item) {
                    openConnectionModal(item);
                }
            }

            return;
        }

        if (action === 'delete') {

            deleteTarget(target);
        }
    }
);

/* =========================================================
   Connection points
========================================================= */

$('objects').addEventListener(
    'click',
    event => {

        const cp =
            event.target.closest(
                '.connection-point'
            );

        if (!cp) return;

        const object =
            cp.closest('.edit-object');

        if (!object) return;

        event.preventDefault();
        event.stopPropagation();

        const item =
            state.project.elements.find(
                e => e.id === object.dataset.id
            );

        if (!item) return;

        if (!state.connectSource) {

            state.connectSource = {
                id:item.id,
                point:cp.dataset.point
            };

            showMessage(
                '接続先の要素の接点をクリックしてください。',
                true
            );

            return;
        }

        if (
            state.connectSource.id === item.id
        ) {
            state.connectSource = null;
            return;
        }

        const connection = {
            id:uid('connection'),
            from:state.connectSource.id,
            fromPoint:state.connectSource.point,
            to:item.id,
            toPoint:cp.dataset.point,
            color:'#ffffff',
            width:2.5,
            style:'solid',
            arrow:'arrow',
            start:currentTime(),
            end:defaultEnd()
        };

        state.project.connections.push(
            connection
        );

        state.connectSource = null;

        selectConnection(connection.id);

        markDirty();
    }
);

/* =========================================================
   Delete
========================================================= */

function deleteTarget(target)
{
    if (!target) return;

    if (target.type === 'element') {

        state.project.elements =
            state.project.elements.filter(
                e => e.id !== target.id
            );

        state.project.connections =
            state.project.connections.filter(
                c =>
                    c.from !== target.id &&
                    c.to !== target.id
            );

    } else {

        state.project.connections =
            state.project.connections.filter(
                c => c.id !== target.id
            );
    }

    state.selected = null;
    state.selectedType = null;

    markDirty();
    renderAll();
}

/* =========================================================
   Object modal
========================================================= */

function openObjectModal(item)
{
    state.modalTarget = item;

    $('objectModalTitle').textContent =
        item.type === 'text'
            ? 'テキストを編集'
            : '強調枠を編集';

    $('textContentField').style.display =
        item.type === 'text'
            ? 'block'
            : 'none';

    $('fontSizeField').style.display =
        item.type === 'text'
            ? 'block'
            : 'none';

    $('modalText').value = item.text;
    $('modalStart').value = item.start;
    $('modalEnd').value = item.end;
    $('modalColor').value = item.color;
    $('modalBackground').value =
        item.background;

    $('modalOpacity').value =
        item.opacity;

    $('modalFontSize').value =
        item.fontSize;

    $('modalBorderWidth').value =
        item.borderWidth;

    $('modalBorderColor').value =
        item.borderColor;

    $('objectModal').style.display =
        'flex';
}

function closeObjectModal()
{
    $('objectModal').style.display =
        'none';

    state.modalTarget = null;
}

$('modalCancel').onclick =
    closeObjectModal;

$('modalApply').onclick = () => {

    const item =
        state.modalTarget;

    if (!item) return;

    const duration =
        state.project.duration;

    const start =
        Number($('modalStart').value);

    const end =
        Number($('modalEnd').value);

    if (
        !Number.isFinite(start) ||
        !Number.isFinite(end) ||
        start < 0 ||
        end <= start ||
        end > duration
    ) {
        showMessage(
            `開始・終了時間が不正です。\n0〜${duration.toFixed(2)}秒の範囲で、終了は開始より後にしてください。`
        );
        return;
    }

    item.start = start;
    item.end = end;

    item.text =
        $('modalText').value;

    item.color =
        $('modalColor').value;

    item.background =
        $('modalBackground').value;

    item.opacity =
        clamp(
            Number($('modalOpacity').value),
            0,
            1
        );

    item.fontSize =
        clamp(
            Number($('modalFontSize').value),
            8,
            200
        );

    item.borderWidth =
        clamp(
            Number($('modalBorderWidth').value),
            0,
            30
        );

    item.borderColor =
        $('modalBorderColor').value;

    markDirty();
    renderAll();
    closeObjectModal();
};

/* =========================================================
   Connection modal
========================================================= */

function openConnectionModal(item)
{
    state.modalTarget = item;

    $('connStart').value =
        item.start;

    $('connEnd').value =
        item.end;

    $('connColor').value =
        item.color;

    $('connWidth').value =
        item.width;

    $('connStyle').value =
        item.style;

    $('connArrow').value =
        item.arrow;

    $('connectionModal').style.display =
        'flex';
}

function closeConnectionModal()
{
    $('connectionModal').style.display =
        'none';

    state.modalTarget = null;
}

$('connCancel').onclick =
    closeConnectionModal;

$('connApply').onclick = () => {

    const item =
        state.modalTarget;

    if (!item) return;

    const start =
        Number($('connStart').value);

    const end =
        Number($('connEnd').value);

    if (
        !Number.isFinite(start) ||
        !Number.isFinite(end) ||
        start < 0 ||
        end <= start ||
        end > state.project.duration
    ) {
        showMessage(
            '線の開始・終了時間が不正です。'
        );
        return;
    }

    item.start = start;
    item.end = end;
    item.color = $('connColor').value;
    item.width =
        clamp(
            Number($('connWidth').value),
            .5,
            30
        );
    item.style = $('connStyle').value;
    item.arrow = $('connArrow').value;

    markDirty();
    renderAll();
    closeConnectionModal();
};

/* =========================================================
   Modal delete
========================================================= */

$('modalDelete').onclick = () => {

    if (!state.modalTarget) return;

    const id =
        state.modalTarget.id;

    const type =
        state.modalTarget.type === 'text' ||
        state.modalTarget.type === 'box'
            ? 'element'
            : 'connection';

    closeObjectModal();

    deleteTarget({
        type,
        id
    });
};

$('connDelete').onclick = () => {

    if (!state.modalTarget) return;

    const id =
        state.modalTarget.id;

    closeConnectionModal();

    deleteTarget({
        type:'connection',
        id
    });
};

/* =========================================================
   Timeline editing
========================================================= */

document.querySelectorAll('.track').forEach(
    track => {

        track.addEventListener(
            'pointerdown',
            event => {

                const bar =
                    event.target.closest(
                        '.track-item'
                    );

                if (!bar) return;

                const id =
                    bar.dataset.id;

                const type =
                    bar.dataset.type;

                const item =
                    type === 'element'
                        ? state.project.elements.find(
                            e => e.id === id
                        )
                        : state.project.connections.find(
                            c => c.id === id
                        );

                if (!item) return;

                selectElementOrConnection(
                    type,
                    id
                );

                const handle =
                    event.target.closest(
                        '.track-handle'
                    );

                const rect =
                    track.getBoundingClientRect();

                state.timelineDrag = {
                    item,
                    type,
                    mode:
                        handle?.classList.contains('left')
                            ? 'start'
                            : handle?.classList.contains('right')
                                ? 'end'
                                : 'move',
                    startX:event.clientX,
                    originalStart:item.start,
                    originalEnd:item.end,
                    width:rect.width
                };

                bar.setPointerCapture?.(
                    event.pointerId
                );

                event.preventDefault();
            }
        );
    }
);

document.addEventListener(
    'pointermove',
    event => {

        const d =
            state.timelineDrag;

        if (!d) return;

        const delta =
            (event.clientX - d.startX) /
            d.width *
            state.project.duration;

        if (d.mode === 'start') {

            d.item.start =
                clamp(
                    d.originalStart + delta,
                    0,
                    d.item.end - .01
                );

        } else if (d.mode === 'end') {

            d.item.end =
                clamp(
                    d.originalEnd + delta,
                    d.item.start + .01,
                    state.project.duration
                );

        } else {

            const length =
                d.originalEnd -
                d.originalStart;

            let start =
                d.originalStart + delta;

            start =
                clamp(
                    start,
                    0,
                    state.project.duration - length
                );

            d.item.start = start;
            d.item.end = start + length;
        }

        markDirty();
        renderAll();
    }
);

document.addEventListener(
    'pointerup',
    () => {
        state.timelineDrag = null;
    }
);

function selectElementOrConnection(type,id)
{
    if (type === 'element') {
        selectElement(id);
    } else {
        selectConnection(id);
    }
}

/* =========================================================
   Video playback / exact slider
========================================================= */

$('video').addEventListener(
    'loadedmetadata',
    () => {

        state.project.duration =
            Number($('video').duration) || 0;

        $('seek').min = '0';
        $('seek').max =
            String(state.project.duration);
        $('seek').value = '0';

        renderAll();
    }
);

$('video').addEventListener(
    'timeupdate',
    () => {

        $('seek').value =
            String(
                Number($('video').currentTime) || 0
            );

        updateTime();
        renderObjects();
        renderConnections();
    }
);

$('video').addEventListener(
    'play',
    () => {
        $('playPause').textContent =
            '一時停止';
    }
);

$('video').addEventListener(
    'pause',
    () => {
        $('playPause').textContent =
            '再生';
    }
);

$('video').addEventListener(
    'ended',
    () => {
        $('playPause').textContent =
            '再生';
    }
);

$('seek').addEventListener(
    'input',
    () => {

        const duration =
            Number($('video').duration) || 0;

        const value =
            clamp(
                Number($('seek').value),
                0,
                duration
            );

        $('video').currentTime = value;

        updateTime();
        renderObjects();
        renderConnections();
    }
);

$('playPause').onclick = () => {

    if ($('video').paused) {
        $('video').play();
    } else {
        $('video').pause();
    }
};

function updateTime()
{
    const current =
        Number($('video').currentTime) || 0;

    const duration =
        Number($('video').duration) || 0;

    $('time').textContent =
        `${formatTime(current)} / ${formatTime(duration)}`;
}

/* =========================================================
   Save state
========================================================= */

function markDirty()
{
    state.dirty = true;

    $('editorStatus').textContent =
        '未保存の変更があります';
}

/* =========================================================
   Server save
========================================================= */

function projectForSave()
{
    return {
        ...state.project,

        version:APP_VERSION,

        name:
            $('projectName').value.trim(),

        videoName:
            state.project.videoName,

        videoType:
            state.project.videoType,

        videoSize:
            state.project.videoSize,

        duration:
            state.project.duration
    };
}

async function saveServer()
{
    const project =
        projectForSave();

    if (!project.name) {
        showMessage(
            'プロジェクト名を入力してください。'
        );
        $('projectName').focus();
        return;
    }

    try {

        $('editorStatus').textContent =
            'サーバーへ保存中…';

        const response =
            await fetch(
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

        const data =
            await response.json();

        if (!response.ok || !data.ok) {

            if (data.limit) {
                throw new Error(
                    'サーバー保存上限に達しました。ローカル保存を使用してください。'
                );
            }

            throw new Error(
                data.message ||
                '保存に失敗しました。'
            );
        }

        state.project.projectId =
            data.projectId;

        state.project.savedAt =
            data.savedAt;

        state.dirty = false;

        $('editorStatus').textContent =
            'サーバー保存済み ' +
            formatSavedAt(data.savedAt);

        showMessage(
            'サーバーへ保存しました。',
            true
        );

    } catch (error) {

        $('editorStatus').textContent =
            '保存失敗';

        showMessage(
            error.message ||
            '保存できませんでした。'
        );
    }
}

function formatSavedAt(value)
{
    if (!value) return '';

    const date =
        new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString('ja-JP');
}

/* =========================================================
   Local save
========================================================= */

async function saveLocal()
{
    const project =
        projectForSave();

    if (!project.name) {
        showMessage(
            'プロジェクト名を入力してください。'
        );
        return;
    }

    try {

        await idbPut(
            'projects',
            {
                ...project,
                savedAt:nowISO(),
                storage:'local'
            }
        );

        state.project.savedAt =
            nowISO();

        state.dirty = false;

        $('editorStatus').textContent =
            'ローカル保存済み';

        showMessage(
            'ブラウザへ保存しました。',
            true
        );

    } catch (error) {

        showMessage(
            'ローカル保存に失敗しました。\n' +
            error.message
        );
    }
}

/* =========================================================
   Export JSON
========================================================= */

function exportProject()
{
    const project =
        projectForSave();

    const blob =
        new Blob(
            [
                JSON.stringify(
                    {
                        app:'動画上直接編集ツール',
                        version:APP_VERSION,
                        project
                    },
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
        `${project.name || 'project'}.json`;

    a.click();

    setTimeout(
        () => URL.revokeObjectURL(url),
        1000
    );
}

/* =========================================================
   Import JSON
========================================================= */

$('projectFile').addEventListener(
    'change',
    async event => {

        const file =
            event.target.files?.[0];

        if (!file) return;

        try {

            const text =
                await file.text();

            const data =
                JSON.parse(text);

            const project =
                normalizeProject(
                    data.project || data
                );

            if (!project.videoKey) {
                throw new Error(
                    'このプロジェクトには動画本体の参照情報がありません。'
                );
            }

            const video =
                await idbGet(
                    'videos',
                    project.videoKey
                );

            if (!video?.blob) {
                throw new Error(
                    '対応する動画本体がこのブラウザにありません。先に動画ファイルを読み込んでください。'
                );
            }

            await openEditor(
                project,
                video.blob
            );

            showMessage(
                'プロジェクトを読み込みました。',
                true
            );

        } catch (error) {

            showMessage(
                error.message ||
                '読み込みに失敗しました。'
            );

        } finally {

            event.target.value = '';
        }
    }
);

/* =========================================================
   Server project manager
========================================================= */

async function loadServerProjects()
{
    const response =
        await fetch(
            '?api=list&_=' + Date.now(),
            {
                cache:'no-store'
            }
        );

    if (!response.ok) {
        throw new Error(
            'サーバー一覧を取得できません。'
        );
    }

    return response.json();
}

async function openProjectManager()
{
    $('manageModal').style.display =
        'flex';

    $('manageList').innerHTML =
        '<div class="help">読み込み中…</div>';

    try {

        const server =
            await loadServerProjects();

        const local =
            await idbGetAll('projects');

        $('manageInfo').innerHTML =
            `サーバー：${server.projects.length} / ${server.limit} 件<br>` +
            `ローカル：${local.length} 件`;

        const rows = [];

        server.projects.forEach(
            project => {

                rows.push({
                    ...project,
                    storage:'server'
                });
            }
        );

        local.forEach(
            project => {

                rows.push({
                    ...project,
                    storage:'local'
                });
            }
        );

        if (!rows.length) {

            $('manageList').innerHTML =
                '<div class="help">保存データはありません。</div>';

            return;
        }

        $('manageList').innerHTML =
            rows.map(project => `

                <div class="project">

                    <div>
                        <div class="project-name">
                            ${escapeHtml(project.name)}
                        </div>

                        <div class="project-meta">
                            ${project.storage === 'server'
                                ? 'サーバー'
                                : 'ローカル'}
                            /
                            ${escapeHtml(project.videoName || '')}
                            /
                            ${escapeHtml(
                                formatSavedAt(project.savedAt)
                            )}
                        </div>
                    </div>

                    <div class="project-actions">

                        <button
                            data-open-project="${escapeHtml(project.projectId)}"
                            data-storage="${project.storage}"
                        >
                            開く
                        </button>

                        <button
                            class="danger"
                            data-delete-project="${escapeHtml(project.projectId)}"
                            data-storage="${project.storage}"
                        >
                            削除
                        </button>

                    </div>

                </div>

            `).join('');

    } catch (error) {

        $('manageList').innerHTML =
            `<div class="help">${escapeHtml(error.message)}</div>`;
    }
}

$('manageList').addEventListener(
    'click',
    async event => {

        const open =
            event.target.closest(
                '[data-open-project]'
            );

        const del =
            event.target.closest(
                '[data-delete-project]'
            );

        if (open) {

            try {

                const id =
                    open.dataset.openProject;

                const storage =
                    open.dataset.storage;

                let project;

                if (storage === 'server') {

                    const response =
                        await fetch(
                            '?api=load&id=' +
                            encodeURIComponent(id) +
                            '&_=' + Date.now(),
                            {
                                cache:'no-store'
                            }
                        );

                    const data =
                        await response.json();

                    if (!response.ok || !data.ok) {
                        throw new Error(
                            data.message ||
                            '読み込みに失敗しました。'
                        );
                    }

                    project = data.project;

                } else {

                    project =
                        await idbGet(
                            'projects',
                            id
                        );

                    if (!project) {
                        throw new Error(
                            'ローカル保存データがありません。'
                        );
                    }
                }

                const video =
                    await idbGet(
                        'videos',
                        project.videoKey
                    );

                if (!video?.blob) {

                    throw new Error(
                        'この保存データに対応する動画本体が、このブラウザにありません。動画ファイルを読み込み直してください。'
                    );
                }

                $('manageModal').style.display =
                    'none';

                await openEditor(
                    project,
                    video.blob
                );

            } catch (error) {

                showMessage(
                    error.message ||
                    '読み込みに失敗しました。'
                );
            }
        }

        if (del) {

            const id =
                del.dataset.deleteProject;

            const storage =
                del.dataset.storage;

            if (!confirm(
                'この保存データを削除しますか？'
            )) {
                return;
            }

            try {

                if (storage === 'server') {

                    const response =
                        await fetch(
                            '?api=delete&_=' + Date.now(),
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

                    const data =
                        await response.json();

                    if (!response.ok || !data.ok) {
                        throw new Error(
                            data.message ||
                            '削除できませんでした。'
                        );
                    }

                } else {

                    await idbDelete(
                        'projects',
                        id
                    );
                }

                await openProjectManager();

            } catch (error) {

                showMessage(
                    error.message ||
                    '削除に失敗しました。'
                );
            }
        }
    }
);

/* =========================================================
   Close editor
========================================================= */

$('closeEditor').onclick = () => {

    if (
        state.dirty &&
        !confirm(
            '保存されていない変更があります。閉じますか？'
        )
    ) {
        return;
    }

    $('editor').style.display =
        'none';

    $('home').style.display =
        'flex';

    $('video').pause();

    renderHomeProjects();
};

/* =========================================================
   Home project list
========================================================= */

async function renderHomeProjects()
{
    const box =
        $('projectList');

    try {

        const server =
            await loadServerProjects();

        const local =
            await idbGetAll('projects');

        const rows = [
            ...server.projects.map(
                p => ({
                    ...p,
                    storage:'server'
                })
            ),

            ...local.map(
                p => ({
                    ...p,
                    storage:'local'
                })
            )
        ];

        if (!rows.length) {

            box.innerHTML =
                '<div class="help">保存済みプロジェクトはありません。</div>';

            return;
        }

        box.innerHTML =
            '<div class="help">最近の保存データ</div>' +
            rows.slice(0,8).map(
                project => `

                    <div class="project">

                        <div>
                            <div class="project-name">
                                ${escapeHtml(project.name)}
                            </div>

                            <div class="project-meta">
                                ${project.storage === 'server'
                                    ? 'サーバー'
                                    : 'ローカル'}
                                /
                                ${escapeHtml(project.videoName || '')}
                            </div>
                        </div>

                        <div class="project-actions">

                            <button
                                data-home-open="${escapeHtml(project.projectId)}"
                                data-home-storage="${project.storage}"
                            >
                                開く
                            </button>

                        </div>

                    </div>

                `
            ).join('');

    } catch {

        box.innerHTML = '';
    }
}

$('projectList').addEventListener(
    'click',
    async event => {

        const button =
            event.target.closest(
                '[data-home-open]'
            );

        if (!button) return;

        const id =
            button.dataset.homeOpen;

        const storage =
            button.dataset.homeStorage;

        try {

            let project;

            if (storage === 'server') {

                const response =
                    await fetch(
                        '?api=load&id=' +
                        encodeURIComponent(id) +
                        '&_=' + Date.now(),
                        {
                            cache:'no-store'
                        }
                    );

                const data =
                    await response.json();

                if (!response.ok || !data.ok) {
                    throw new Error(
                        data.message ||
                        '読み込みに失敗しました。'
                    );
                }

                project = data.project;

            } else {

                project =
                    await idbGet(
                        'projects',
                        id
                    );
            }

            const video =
                await idbGet(
                    'videos',
                    project.videoKey
                );

            if (!video?.blob) {
                throw new Error(
                    '動画本体がありません。'
                );
            }

            await openEditor(
                project,
                video.blob
            );

        } catch (error) {

            showMessage(
                error.message ||
                '読み込みに失敗しました。'
            );
        }
    }
);

/* =========================================================
   File input
========================================================= */

$('openVideo').onclick = () => {
    $('videoFile').click();
};

$('videoFile').addEventListener(
    'change',
    async event => {

        const file =
            event.target.files?.[0];

        if (!file) return;

        try {

            await readVideoFile(file);

        } catch (error) {

            showMessage(
                error.message ||
                '動画の読み込みに失敗しました。'
            );

        } finally {

            event.target.value = '';
        }
    }
);

/* =========================================================
   Buttons
========================================================= */

$('saveServer').onclick =
    saveServer;

$('saveLocal').onclick =
    saveLocal;

$('exportProject').onclick =
    exportProject;

$('importProject').onclick = () => {
    $('projectFile').click();
};

$('manageProjects').onclick =
    openProjectManager;

$('manageClose').onclick = () => {
    $('manageModal').style.display =
        'none';
};

$('projectName').addEventListener(
    'input',
    () => {

        if (!state.project) return;

        state.project.name =
            $('projectName').value;

        markDirty();
    }
);

/* =========================================================
   Modal backdrop
========================================================= */

[
    'objectModal',
    'connectionModal',
    'manageModal'
].forEach(id => {

    $(id).addEventListener(
        'mousedown',
        event => {

            if (event.target !== $(id)) {
                return;
            }

            if (id === 'objectModal') {
                closeObjectModal();
            }

            if (id === 'connectionModal') {
                closeConnectionModal();
            }

            if (id === 'manageModal') {
                $(id).style.display =
                    'none';
            }
        }
    );
});

/* =========================================================
   Global click
========================================================= */

document.addEventListener(
    'click',
    event => {

        if (
            !event.target.closest('#contextMenu')
        ) {
            hideContextMenu();
        }
    }
);

/* =========================================================
   Keyboard
========================================================= */

document.addEventListener(
    'keydown',
    event => {

        if (
            event.key === 'Delete' &&
            !event.target.matches(
                'input,textarea,select'
            )
        ) {

            if (
                state.selected &&
                state.selectedType
            ) {

                deleteTarget({
                    type:state.selectedType,
                    id:state.selected
                });
            }
        }

        if (
            event.key === 'Escape'
        ) {

            hideContextMenu();
            closeObjectModal();
            closeConnectionModal();
        }
    }
);

/* =========================================================
   Resize
========================================================= */

window.addEventListener(
    'resize',
    () => {

        if (
            $('editor').style.display !== 'none'
        ) {
            fitVideoStage();
            renderAll();
        }
    }
);

/* =========================================================
   Initial state
========================================================= */

(async function init()
{
    try {

        await openDB();

        await renderHomeProjects();

        $('editorStatus').textContent =
            `v${APP_VERSION}`;

    } catch (error) {

        showMessage(
            '初期化に失敗しました。\n' +
            error.message
        );
    }
})();
</script>

</body>
</html>

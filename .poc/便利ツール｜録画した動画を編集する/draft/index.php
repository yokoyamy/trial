<?php
declare(strict_types=1);

/*
 * 画面共有録画・動画オーバーレイ編集
 * PHP + Apache / index.php 単体構成
 *
 * サーバー保存:
 *   ./data/editor.sqlite
 *   ./data/videos/
 *
 * Apache/PHPプロセスに data ディレクトリへの書き込み権限が必要です。
 */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const VIDEO_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'videos';
const DB_FILE   = DATA_DIR . DIRECTORY_SEPARATOR . 'editor.sqlite';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureStorage(): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true)) {
        throw new RuntimeException('dataディレクトリを作成できません。');
    }
    if (!is_dir(VIDEO_DIR) && !mkdir(VIDEO_DIR, 0775, true)) {
        throw new RuntimeException('videosディレクトリを作成できません。');
    }

    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(
        'CREATE TABLE IF NOT EXISTS projects (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL DEFAULT "",
            video_file TEXT NOT NULL DEFAULT "",
            data TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
}

function db(): PDO
{
    ensureStorage();
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

function projectId(string $value = ''): string
{
    if (preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $value)) {
        return $value;
    }
    return bin2hex(random_bytes(12));
}

function requestJson(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function sanitizeProject(array $project): array
{
    $project['version'] = 2;
    $project['items'] = is_array($project['items'] ?? null) ? $project['items'] : [];
    $project['connectors'] = is_array($project['connectors'] ?? null) ? $project['connectors'] : [];
    $project['skips'] = is_array($project['skips'] ?? null) ? $project['skips'] : [];
    $project['duration'] = max(0, (float)($project['duration'] ?? 0));

    return $project;
}

/*
 * API
 */
if (isset($_GET['api'])) {
    try {
        $api = (string)$_GET['api'];

        if ($api === 'save') {
            $input = requestJson();
            $id = projectId((string)($input['id'] ?? ''));
            $name = trim((string)($input['name'] ?? '無題'));
            $project = sanitizeProject($input['project'] ?? []);
            $videoFile = basename((string)($input['videoFile'] ?? ''));

            $now = date('c');
            $pdo = db();

            $stmt = $pdo->prepare(
                'INSERT INTO projects
                    (id,name,video_file,data,created_at,updated_at)
                 VALUES
                    (:id,:name,:video_file,:data,:created_at,:updated_at)
                 ON CONFLICT(id) DO UPDATE SET
                    name=excluded.name,
                    video_file=excluded.video_file,
                    data=excluded.data,
                    updated_at=excluded.updated_at'
            );

            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':video_file' => $videoFile,
                ':data' => json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_at' => $now,
                ':updated_at' => $now
            ]);

            jsonResponse([
                'ok' => true,
                'id' => $id,
                'updated_at' => $now
            ]);
        }

        if ($api === 'load') {
            $id = projectId((string)($_GET['id'] ?? ''));
            $stmt = db()->prepare('SELECT * FROM projects WHERE id=:id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                jsonResponse(['ok' => false, 'error' => 'プロジェクトが見つかりません。'], 404);
            }

            jsonResponse([
                'ok' => true,
                'id' => $row['id'],
                'name' => $row['name'],
                'videoFile' => $row['video_file'],
                'project' => json_decode($row['data'], true),
                'createdAt' => $row['created_at'],
                'updatedAt' => $row['updated_at']
            ]);
        }

        if ($api === 'list') {
            $rows = db()->query(
                'SELECT id,name,video_file,created_at,updated_at
                 FROM projects ORDER BY updated_at DESC'
            )->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse(['ok' => true, 'projects' => $rows]);
        }

        if ($api === 'upload') {
            if (empty($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
                jsonResponse(['ok' => false, 'error' => '動画ファイルを受信できませんでした。'], 400);
            }

            $id = projectId((string)($_POST['id'] ?? ''));
            $name = basename($_FILES['video']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($ext, ['webm', 'mp4', 'mov', 'mkv'], true)) {
                $ext = 'webm';
            }

            $filename = $id . '.' . $ext;
            $target = VIDEO_DIR . DIRECTORY_SEPARATOR . $filename;

            if (!move_uploaded_file($_FILES['video']['tmp_name'], $target)) {
                jsonResponse(['ok' => false, 'error' => '動画を保存できませんでした。'], 500);
            }

            jsonResponse([
                'ok' => true,
                'id' => $id,
                'file' => $filename
            ]);
        }

        if ($api === 'video') {
            $file = basename((string)($_GET['file'] ?? ''));
            $path = VIDEO_DIR . DIRECTORY_SEPARATOR . $file;

            if (!$file || !is_file($path)) {
                http_response_code(404);
                exit;
            }

            $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'mp4' => 'video/mp4',
                'webm' => 'video/webm',
                'mov' => 'video/quicktime',
                default => 'application/octet-stream'
            };

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('Accept-Ranges: bytes');
            readfile($path);
            exit;
        }

        if ($api === 'delete') {
            $id = projectId((string)($_POST['id'] ?? ''));
            $pdo = db();

            $stmt = $pdo->prepare('SELECT video_file FROM projects WHERE id=:id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && $row['video_file']) {
                $path = VIDEO_DIR . DIRECTORY_SEPARATOR . basename($row['video_file']);
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM projects WHERE id=:id');
            $stmt->execute([':id' => $id]);

            jsonResponse(['ok' => true]);
        }

        jsonResponse(['ok' => false, 'error' => '未知のAPIです。'], 404);

    } catch (Throwable $e) {
        jsonResponse([
            'ok' => false,
            'error' => $e->getMessage()
        ], 500);
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>画面共有録画・動画編集</title>

<style>
:root{
    color-scheme:dark;
    --bg:#101114;
    --panel:#1b1d21;
    --panel2:#23262b;
    --line:#3a3e45;
    --text:#f3f4f6;
    --muted:#9da3ad;
    --blue:#1683ff;
    --red:#e5484d;
    --yellow:#ffd54a;
}

*{box-sizing:border-box}

html,body{
    width:100%;
    height:100%;
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Noto Sans JP",sans-serif;
}

button,input,textarea,select{font:inherit}

button{
    border:1px solid #50545c;
    background:#2a2d32;
    color:#fff;
    border-radius:7px;
    padding:8px 11px;
    cursor:pointer;
}

button:hover:not(:disabled){background:#383c43}
button:disabled{opacity:.45;cursor:not-allowed}
button.primary{background:#116fd5;border-color:#2788ec}
button.danger{background:#9d3035}
button.success{background:#24763e}

input,textarea,select{
    width:100%;
    color:#fff;
    background:#15171a;
    border:1px solid #4b5058;
    border-radius:6px;
    padding:7px 8px;
}

textarea{resize:vertical;min-height:70px}

header{
    height:48px;
    padding:0 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    background:#181a1e;
    border-bottom:1px solid var(--line);
}

header h1{
    margin:0;
    font-size:16px;
}

#message{
    display:none;
    position:fixed;
    top:58px;
    left:50%;
    transform:translateX(-50%);
    z-index:1000;
    padding:10px 16px;
    border-radius:8px;
    background:#9b3535;
    box-shadow:0 8px 30px #0008;
}

#message.ok{background:#23733c}

#recordPage{
    height:calc(100vh - 48px);
    display:flex;
    flex-direction:column;
}

.recorder{
    flex:1;
    min-height:0;
    background:#050505;
    display:flex;
    align-items:center;
    justify-content:center;
}

#preview{
    display:none;
    max-width:100%;
    max-height:100%;
}

#placeholder{
    color:#858a92;
    text-align:center;
}

.toolbar{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    padding:9px 12px;
    background:#191b1f;
    border-top:1px solid var(--line);
}

#timer{
    min-width:85px;
    font-variant-numeric:tabular-nums;
}

#editor{
    display:none;
    position:fixed;
    inset:0;
    z-index:20;
    background:#0c0d0f;
    flex-direction:column;
}

.editor-main{
    flex:1;
    min-height:0;
    display:grid;
    grid-template-columns:minmax(0,1fr) 360px;
}

.video-area{
    min-width:0;
    min-height:0;
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#000;
    overflow:hidden;
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
    object-fit:contain;
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

.connector-hit{
    fill:none;
    stroke:transparent;
    stroke-width:12;
    pointer-events:stroke;
    cursor:pointer;
}

.connector-line{
    fill:none;
    pointer-events:none;
}

.connector.selected .connector-line{
    filter:drop-shadow(0 0 3px #fff);
}

.connector.selected .connector-hit{
    stroke:rgba(255,213,74,.18);
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

.comment-object{
    border-radius:7px;
    overflow:hidden;
    padding:8px 10px;
    white-space:pre-wrap;
    word-break:break-word;
}

.box-object{
    background:transparent;
}

.resize-handle{
    position:absolute;
    right:-7px;
    bottom:-7px;
    width:14px;
    height:14px;
    border-radius:50%;
    border:2px solid #111;
    background:#fff;
    cursor:nwse-resize;
    z-index:5;
}

.endpoint{
    position:absolute;
    width:13px;
    height:13px;
    margin:-6px 0 0 -6px;
    border-radius:50%;
    background:#ffd54a;
    border:2px solid #111;
    box-shadow:0 0 0 2px #fff8;
    cursor:crosshair;
    pointer-events:auto;
    z-index:10;
}

.endpoint:hover{transform:scale(1.25)}

.endpoint.start{background:#42a5ff}
.endpoint.end{background:#ff5c65}

.direct-edit{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    border:0;
    outline:2px solid #5aa9ff;
    resize:none;
    overflow:auto;
    padding:inherit;
    background:inherit;
    color:inherit;
    font:inherit;
    text-align:inherit;
}

aside{
    min-width:0;
    overflow:auto;
    background:var(--panel);
    border-left:1px solid var(--line);
    padding:12px;
}

section{
    padding-bottom:13px;
    margin-bottom:13px;
    border-bottom:1px solid var(--line);
}

section h2{
    margin:0 0 8px;
    font-size:15px;
}

.help{
    color:var(--muted);
    font-size:12px;
    line-height:1.55;
    margin-bottom:9px;
}

.field{
    display:block;
    margin:7px 0;
    color:#b8bdc6;
    font-size:12px;
}

.field input,
.field textarea,
.field select{
    margin-top:4px;
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 8px;
}

.three{
    display:grid;
    grid-template-columns:1fr 1fr 1fr;
    gap:7px;
}

.actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.list-item{
    padding:8px;
    margin:6px 0;
    border:1px solid #42464d;
    border-radius:7px;
    font-size:12px;
}

.list-item.selected{
    border-color:#4aa3ff;
    background:#17304a;
}

.list-item .buttons{
    display:flex;
    gap:5px;
    margin-top:6px;
}

.list-item button{
    padding:5px 7px;
    font-size:11px;
}

#timeline{
    padding:8px 12px;
    background:#181a1e;
    border-top:1px solid var(--line);
}

#seek{width:100%}

.track-row{
    display:grid;
    grid-template-columns:65px 1fr;
    gap:7px;
    align-items:center;
    margin-top:4px;
    font-size:11px;
    color:#9ea4ad;
}

.track{
    position:relative;
    height:14px;
    background:#2b2e34;
    border-radius:3px;
    overflow:hidden;
}

.track span{
    position:absolute;
    top:2px;
    height:10px;
    min-width:2px;
    border-radius:2px;
    cursor:pointer;
}

.track .comment{background:#4aa3ff}
.track .box{background:#ff4f55}
.track .connector{background:#ffd54a}
.track .skip{background:#ed9d31}

.footer{
    display:flex;
    justify-content:center;
    align-items:center;
    flex-wrap:wrap;
    gap:7px;
    padding:8px;
    background:#181a1e;
    border-top:1px solid var(--line);
}

#timeReadout{
    min-width:110px;
    text-align:center;
    font-variant-numeric:tabular-nums;
}

#contextMenu{
    display:none;
    position:fixed;
    z-index:100;
    background:#292c31;
    border:1px solid #555a62;
    border-radius:8px;
    padding:5px;
    box-shadow:0 10px 35px #0009;
}

#contextMenu button{
    display:block;
    width:100%;
    text-align:left;
    border:0;
    background:transparent;
}

.toolbar-group{
    display:flex;
    gap:5px;
    align-items:center;
}

@media(max-width:900px){
    .editor-main{
        display:flex;
        flex-direction:column;
    }

    .video-area{
        flex:1;
        min-height:260px;
    }

    aside{
        max-height:42vh;
        border-left:0;
        border-top:1px solid var(--line);
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

<div id="recordPage">

    <div class="recorder">
        <video id="preview" autoplay muted playsinline></video>
        <div id="placeholder">
            「録画開始」を押して共有する画面を選択してください
        </div>
    </div>

    <div class="toolbar">

        <label>
            <input type="checkbox" id="systemAudio">
            画面の音声
        </label>

        <label>
            <input type="checkbox" id="microphone">
            マイク
        </label>

        <span id="timer">00:00:00</span>

        <button id="start" class="primary">録画開始</button>
        <button id="pause" disabled>一時停止</button>
        <button id="stop" class="danger" disabled>停止</button>
        <button id="reset" disabled>リセット</button>
        <button id="openEditor" disabled>編集画面を開く</button>

    </div>

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
                    コメント・強調枠を動画上へ直接配置できます。
                    コメントはダブルクリックすると、その場で文字を編集できます。
                    要素間の線は接点を直接ドラッグできます。
                </div>

                <div class="actions">
                    <button id="addComment" class="primary">コメントを追加</button>
                    <button id="addBox" class="primary">強調枠を追加</button>
                    <button id="connect">線でつなぐ</button>
                    <button id="deleteSelected" class="danger">選択項目を削除</button>
                </div>
            </section>

            <section>

                <h2>選択中</h2>

                <div id="selectionHint" class="help">
                    要素または線を選択してください。
                </div>

                <div id="objectFields" hidden>

                    <label id="textField" class="field">
                        コメント
                        <textarea id="objectText"></textarea>
                    </label>

                    <div class="grid">

                        <label class="field">
                            開始
                            <input id="objectStart" type="number" min="0" step="0.1">
                        </label>

                        <label class="field">
                            終了
                            <input id="objectEnd" type="number" min="0" step="0.1">
                        </label>

                        <label class="field">
                            左(%)
                            <input id="objectX" type="number" min="0" max="100" step="0.1">
                        </label>

                        <label class="field">
                            上(%)
                            <input id="objectY" type="number" min="0" max="100" step="0.1">
                        </label>

                        <label class="field">
                            幅(%)
                            <input id="objectW" type="number" min="1" max="100" step="0.1">
                        </label>

                        <label class="field">
                            高さ(%)
                            <input id="objectH" type="number" min="1" max="100" step="0.1">
                        </label>

                    </div>

                    <div id="commentStyleFields">

                        <label class="field">
                            文字サイズ(px)
                            <input id="fontSize" type="number" min="8" max="120" step="1">
                        </label>

                        <div class="grid">

                            <label class="field">
                                文字色
                                <input id="fontColor" type="color">
                            </label>

                            <label class="field">
                                背景色
                                <input id="bgColor" type="color">
                            </label>

                            <label class="field">
                                背景透明度
                                <input id="bgOpacity" type="range" min="0" max="1" step="0.01">
                            </label>

                            <label class="field">
                                太字
                                <select id="fontWeight">
                                    <option value="400">標準</option>
                                    <option value="700">太字</option>
                                </select>
                            </label>

                            <label class="field">
                                横位置
                                <select id="textAlign">
                                    <option value="left">左</option>
                                    <option value="center">中央</option>
                                    <option value="right">右</option>
                                </select>
                            </label>

                        </div>

                    </div>

                    <div id="boxStyleFields">

                        <div class="grid">

                            <label class="field">
                                枠色
                                <input id="borderColor" type="color">
                            </label>

                            <label class="field">
                                枠太さ(px)
                                <input id="borderWidth" type="number" min="1" max="30" step="1">
                            </label>

                            <label class="field">
                                透明度
                                <input id="boxOpacity" type="range" min="0" max="1" step="0.01">
                            </label>

                            <label class="field">
                                塗り
                                <input id="boxFill" type="color">
                            </label>

                        </div>

                    </div>

                    <button id="applyObject" class="primary">
                        変更を反映
                    </button>

                </div>

                <div id="connectorFields" hidden>

                    <div class="help">
                        黄色・青色の接点を動画上でドラッグして位置を変更できます。
                    </div>

                    <div class="grid">

                        <label class="field">
                            開始
                            <input id="connectorStart" type="number" min="0" step="0.1">
                        </label>

                        <label class="field">
                            終了
                            <input id="connectorEnd" type="number" min="0" step="0.1">
                        </label>

                        <label class="field">
                            線色
                            <input id="lineColor" type="color">
                        </label>

                        <label class="field">
                            太さ(px)
                            <input id="lineWidth" type="number" min="1" max="30" step="1">
                        </label>

                        <label class="field">
                            透明度
                            <input id="lineOpacity" type="range" min="0" max="1" step="0.01">
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
                            <select id="startArrow">
                                <option value="none">なし</option>
                                <option value="arrow">矢印</option>
                                <option value="circle">丸</option>
                                <option value="square">四角</option>
                            </select>
                        </label>

                        <label class="field">
                            終点
                            <select id="endArrow">
                                <option value="none">なし</option>
                                <option value="arrow">矢印</option>
                                <option value="circle">丸</option>
                                <option value="square">四角</option>
                            </select>
                        </label>

                        <label class="field">
                            形状
                            <select id="lineShape">
                                <option value="line">直線</option>
                                <option value="orthogonal">折れ線</option>
                            </select>
                        </label>

                    </div>

                    <button id="applyConnector" class="primary">
                        線の変更を反映
                    </button>

                </div>

            </section>

            <section>

                <h2>再生をスキップ</h2>

                <div class="grid">

                    <label class="field">
                        開始
                        <input id="skipStart" type="number" min="0" step="0.1" value="0">
                    </label>

                    <label class="field">
                        終了
                        <input id="skipEnd" type="number" min="0" step="0.1" value="5">
                    </label>

                </div>

                <div class="actions">
                    <button id="skipFromCurrent">現在位置を入力</button>
                    <button id="addSkip" class="primary">範囲を追加</button>
                </div>

                <div id="skipList"></div>

            </section>

            <section>

                <h2>オブジェクト</h2>
                <div id="objectList"></div>

            </section>

            <section>

                <h2>サーバー保存</h2>

                <label class="field">
                    プロジェクト名
                    <input id="projectName" value="無題の動画編集">
                </label>

                <div class="actions">
                    <button id="serverSave" class="success">
                        サーバーへ保存
                    </button>

                    <button id="serverLoad">
                        サーバーから読み込み
                    </button>
                </div>

                <div id="serverProjectList"></div>

                <div class="help">
                    保存先は同じindex.phpのdata/editor.sqliteです。
                    録画動画はdata/videosへ保存されます。
                </div>

            </section>

            <section>

                <h2>編集データ</h2>

                <div class="actions">
                    <button id="saveProject">JSON保存</button>
                    <button id="loadProject">JSON読み込み</button>
                    <button id="clearEdits" class="danger">すべて削除</button>
                </div>

                <input id="projectFile"
                       type="file"
                       accept="application/json,.json"
                       hidden>

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

        <div class="track-row">
            <span>コメント</span>
            <div class="track" id="commentTrack"></div>
        </div>

        <div class="track-row">
            <span>強調枠</span>
            <div class="track" id="boxTrack"></div>
        </div>

        <div class="track-row">
            <span>接続線</span>
            <div class="track" id="connectorTrack"></div>
        </div>

        <div class="track-row">
            <span>スキップ</span>
            <div class="track" id="skipTrack"></div>
        </div>

    </div>

    <div class="footer">

        <span id="timeReadout">00:00 / 00:00</span>

        <button id="back5">5秒戻る</button>
        <button id="forward5">5秒進む</button>

        <button id="downloadWebm">
            WebMをダウンロード
        </button>

        <button id="downloadMp4" class="success">
            MP4に変換
        </button>

        <button id="closeEditor">
            閉じる
        </button>

    </div>

</div>

<div id="contextMenu">

    <button data-action="comment">
        コメントを追加
    </button>

    <button data-action="box">
        強調枠を追加
    </button>

    <button data-action="connect">
        線でつなぐ
    </button>

</div>

<script src="https://cdn.jsdelivr.net/npm/@ffmpeg/ffmpeg@0.12.10/dist/umd/ffmpeg.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@ffmpeg/util@0.12.1/dist/umd/index.js"></script>

<script>
"use strict";

const $ = id => document.getElementById(id);

const video = $("recordedVideo");
const stage = $("videoStage");

const STORAGE_KEY = "screen-recorder-edits-v2";

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

    projectId:null,
    projectName:"無題の動画編集",
    videoFile:"",

    items:[],
    connectors:[],
    skips:[],

    selected:null,
    connectMode:false,
    connectFrom:null,

    contextPoint:{x:10,y:10},

    ffmpeg:null,
    converting:false,
    lastSkipAt:-1,
    editingText:false
};

function notify(message, ok=false){
    const box=$("message");
    box.textContent=message;
    box.classList.toggle("ok",ok);
    box.style.display="block";
    clearTimeout(notify.timer);
    notify.timer=setTimeout(()=>box.style.display="none",5000);
}

function clamp(v,min,max){
    return Math.max(min,Math.min(max,v));
}

function uid(){
    if(window.crypto?.randomUUID) return crypto.randomUUID();
    return Date.now().toString(36)+Math.random().toString(36).slice(2);
}

function formatTime(seconds,hours=false){
    seconds=Math.max(0,Math.floor(Number(seconds)||0));
    const h=Math.floor(seconds/3600);
    const m=Math.floor(seconds%3600/60);
    const s=seconds%60;

    if(hours){
        return [h,m,s].map(v=>String(v).padStart(2,"0")).join(":");
    }

    return [
        Math.floor(seconds/60),
        s
    ].map(v=>String(v).padStart(2,"0")).join(":");
}

function duration(){
    return Number.isFinite(video.duration) ? video.duration : 0;
}

function validRange(start,end){
    start=Number(start);
    end=Number(end);

    const max=duration();

    if(
        !Number.isFinite(start) ||
        !Number.isFinite(end) ||
        start<0 ||
        end<=start ||
        (max && start>=max)
    ){
        return null;
    }

    return {
        start,
        end:max ? Math.min(end,max) : end
    };
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

    setTimeout(()=>URL.revokeObjectURL(url),60000);
}

function stopStreams(){
    [state.displayStream,state.microphoneStream]
        .forEach(stream=>{
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

function updateRecordingButtons(recording=false){
    $("start").disabled=recording;
    $("pause").disabled=!recording;
    $("stop").disabled=!recording;
    $("reset").disabled=recording || !state.blob;
    $("openEditor").disabled=recording || !state.blob;
}

function updateTimer(){
    const active=state.startedAt
        ? Date.now()-state.startedAt
        : 0;

    $("timer").textContent=formatTime(
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
        state.elapsedBeforePause += Date.now()-state.startedAt;
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
    ].find(type=>MediaRecorder.isTypeSupported(type));
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
        ...(state.microphoneStream?.getAudioTracks() || [])
    ];

    if(tracks.length===1){
        stream.addTrack(tracks[0]);
    }

    if(tracks.length>1){
        const context=new AudioContext();

        state.audioContext=context;

        const destination=context.createMediaStreamDestination();

        tracks.forEach(track=>{
            const source=context.createMediaStreamSource(
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

/* ---------------------------
   録画
--------------------------- */

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
                "WebM録画に対応していません。"
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

        state.recorder=new MediaRecorder(stream,{mimeType});

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
                    type:state.recorder.mimeType || "video/webm"
                }
            );

            if(state.url){
                URL.revokeObjectURL(state.url);
            }

            state.url=URL.createObjectURL(state.blob);

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
        "現在の録画と編集内容を削除しますか？"
    )){
        return;
    }

    video.pause();

    if(state.url){
        URL.revokeObjectURL(state.url);
    }

    Object.assign(state,{
        url:null,
        blob:null,
        chunks:[],
        recorder:null,
        items:[],
        connectors:[],
        skips:[],
        selected:null,
        projectId:null,
        videoFile:""
    });

    video.removeAttribute("src");
    video.load();

    $("preview").style.display="none";
    $("placeholder").style.display="block";
    $("editor").style.display="none";

    $("timer").textContent="00:00:00";
    $("pause").textContent="一時停止";
    $("status").textContent="待機中";

    localStorage.removeItem(STORAGE_KEY);

    updateRecordingButtons();

    render();
};

$("openEditor").onclick=()=>{
    $("editor").style.display="flex";
    render();
};

$("closeEditor").onclick=()=>{
    video.pause();
    $("editor").style.display="none";
};

/* ---------------------------
   FFmpeg
--------------------------- */

async function getFFmpeg(){

    if(state.ffmpeg) return state.ffmpeg;

    if(
        !window.FFmpeg?.FFmpeg ||
        !window.FFmpegUtil?.toBlobURL
    ){
        throw new Error(
            "FFmpegを読み込めません。インターネット接続を確認してください。"
        );
    }

    const ffmpeg=new window.FFmpeg.FFmpeg();

    const toBlobURL=window.FFmpegUtil.toBlobURL;

    const base=
        "https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.6/dist/umd";

    ffmpeg.on("progress",({progress})=>{
        $("status").textContent=
            `MP4変換中 ${Math.round(
                clamp(progress,0,1)*100
            )}%`;
    });

    await ffmpeg.load({
        coreURL:await toBlobURL(
            `${base}/ffmpeg-core.js`,
            "text/javascript"
        ),
        wasmURL:await toBlobURL(
            `${base}/ffmpeg-core.wasm`,
            "application/wasm"
        ),
        workerURL:await toBlobURL(
            `${base}/ffmpeg-core.worker.js`,
            "text/javascript"
        )
    });

    state.ffmpeg=ffmpeg;

    return ffmpeg;
}

$("downloadWebm").onclick=()=>{
    if(!state.blob){
        return notify("録画データがありません。");
    }

    download(
        state.blob,
        `screen-recording-${fileStamp()}.webm`
    );
};

$("downloadMp4").onclick=async()=>{

    if(!state.blob || state.converting) return;

    state.converting=true;
    $("downloadMp4").disabled=true;

    try{

        $("status").textContent="MP4変換準備中";

        const ffmpeg=await getFFmpeg();

        await ffmpeg.writeFile(
            "input.webm",
            await window.FFmpegUtil.fetchFile(state.blob)
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
            throw new Error("MP4変換に失敗しました。");
        }

        const data=await ffmpeg.readFile("output.mp4");

        const mp4=new Blob(
            [data],
            {type:"video/mp4"}
        );

        download(
            mp4,
            `screen-recording-${fileStamp()}.mp4`
        );

        await Promise.allSettled([
            ffmpeg.deleteFile("input.webm"),
            ffmpeg.deleteFile("output.mp4")
        ]);

        notify("MP4をダウンロードしました。",true);

    }catch(error){

        notify(
            `MP4変換に失敗しました。${error.message || ""}`
        );

    }finally{

        state.converting=false;
        $("downloadMp4").disabled=false;
        $("status").textContent="録画終了";
    }
};

/* ---------------------------
   保存
--------------------------- */

function projectData(){
    return {
        version:2,
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
            "ブラウザへの自動保存に失敗しました。"
        );
    }
}

/* ---------------------------
   オブジェクト
--------------------------- */

function defaultCommentStyle(){

    return {
        fontSize:24,
        fontColor:"#ffffff",
        bgColor:"#000000",
        bgOpacity:.86,
        fontWeight:"400",
        textAlign:"left"
    };
}

function defaultBoxStyle(){

    return {
        borderColor:"#ff453a",
        borderWidth:3,
        opacity:.95,
        fill:"#ff453a",
        fillOpacity:.08
    };
}

function addItem(
    type,
    position={x:15,y:15}
){

    const start=Math.min(
        video.currentTime || 0,
        Math.max(0,duration()-.1)
    );

    const end=Math.min(
        duration() || start+3,
        start+3
    );

    if(end<=start){
        return notify(
            "動画の終了位置には要素を追加できません。"
        );
    }

    const item={
        id:uid(),
        type,
        start,
        end,
        x:clamp(position.x,0,70),
        y:clamp(position.y,0,70),
        w:type==="comment"?28:25,
        h:type==="comment"?14:20
    };

    if(type==="comment"){
        item.text="コメント";
        item.style=defaultCommentStyle();
    }else{
        item.style=defaultBoxStyle();
    }

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

function findItem(id){
    return state.items.find(item=>item.id===id);
}

function findConnector(id){
    return state.connectors.find(line=>line.id===id);
}

function currentSelection(){

    return (
        findItem(state.selected) ||
        findConnector(state.selected) ||
        null
    );
}

/* ---------------------------
   接続
--------------------------- */

function createConnector(from,to){

    const start=Math.max(
        from.start,
        to.start
    );

    const end=Math.min(
        from.end,
        to.end
    );

    if(end<=start){
        notify(
            "表示時間が重なる要素同士を接続してください。"
        );
        return null;
    }

    return {
        id:uid(),

        from:{
            itemId:from.id,
            x:100,
            y:50
        },

        to:{
            itemId:to.id,
            x:0,
            y:50
        },

        start,
        end,

        shape:"line",

        style:{
            color:"#ffffff",
            width:3,
            opacity:1,
            dash:"solid",
            startArrow:"none",
            endArrow:"arrow"
        }
    };
}

function selectItem(item){

    if(
        state.connectMode &&
        state.items.includes(item)
    ){

        if(!state.connectFrom){

            state.connectFrom=item.id;
            state.selected=item.id;

            notify(
                "接続先の要素をクリックしてください。",
                true
            );

        }else if(
            state.connectFrom!==item.id
        ){

            const from=findItem(state.connectFrom);

            const line=createConnector(
                from,
                item
            );

            if(line){

                state.connectors.push(line);

                state.selected=line.id;

                state.connectFrom=null;
                state.connectMode=false;

                $("connect").textContent="線でつなぐ";

                saveLocal();
            }
        }

    }else{

        state.selected=item.id;
    }

    render();
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
        ? "接続する2つの要素を順番にクリックしてください。"
        : "接続をキャンセルしました。",
        true
    );
};

/* ---------------------------
   SVG矢印
--------------------------- */

function markerDefinition(svg,id,color,type){

    const defs=
        svg.querySelector("defs") ||
        (()=>{

            const d=document.createElementNS(
                "http://www.w3.org/2000/svg",
                "defs"
            );

            svg.appendChild(d);
            return d;

        })();

    const marker=document.createElementNS(
        "http://www.w3.org/2000/svg",
        "marker"
    );

    marker.id=id;
    marker.setAttribute(
        "markerWidth",
        type==="arrow"?"10":"7"
    );
    marker.setAttribute("markerHeight","10");
    marker.setAttribute("refX",type==="arrow"?"8":"5");
    marker.setAttribute("refY","5");
    marker.setAttribute("orient","auto");
    marker.setAttribute("markerUnits","strokeWidth");

    const path=document.createElementNS(
        "http://www.w3.org/2000/svg",
        "path"
    );

    if(type==="arrow"){
        path.setAttribute("d","M 0 0 L 10 5 L 0 10 z");
    }else if(type==="circle"){
        path.setAttribute("d","M 5 0 A 5 5 0 1 1 4.99 0");
    }else{
        path.setAttribute("d","M 1 1 L 9 1 L 9 9 L 1 9 z");
    }

    path.setAttribute("fill",color);

    marker.appendChild(path);
    defs.appendChild(marker);

    return `url(#${id})`;
}

function connectorPoints(line){

    const a=findItem(line.from.itemId);
    const b=findItem(line.to.itemId);

    if(!a || !b) return null;

    const p1={
        x:a.x + a.w*(line.from.x/100),
        y:a.y + a.h*(line.from.y/100)
    };

    const p2={
        x:b.x + b.w*(line.to.x/100),
        y:b.y + b.h*(line.to.y/100)
    };

    return {a,b,p1,p2};
}

function drawConnectorLine(svg,line){

    const points=connectorPoints(line);

    if(!points) return;

    const {
        p1,
        p2
    }=points;

    const group=document.createElementNS(
        "http://www.w3.org/2000/svg",
        "g"
    );

    group.classList.add("connector");

    if(state.selected===line.id){
        group.classList.add("selected");
    }

    const style=line.style || {};

    const color=style.color || "#fff";
    const width=Number(style.width)||3;

    let d;

    if((line.shape||"line")==="orthogonal"){

        const midX=(p1.x+p2.x)/2;

        d=
            `M ${p1.x} ${p1.y}
             L ${midX} ${p1.y}
             L ${midX} ${p2.y}
             L ${p2.x} ${p2.y}`;

    }else{

        d=
            `M ${p1.x} ${p1.y}
             L ${p2.x} ${p2.y}`;
    }

    const visible=document.createElementNS(
        "http://www.w3.org/2000/svg",
        "path"
    );

    visible.setAttribute("d",d);
    visible.classList.add("connector-line");
    visible.setAttribute("stroke",color);
    visible.setAttribute("stroke-width",width);
    visible.setAttribute(
        "stroke-opacity",
        clamp(Number(style.opacity ?? 1),0,1)
    );

    if(style.dash==="dashed"){
        visible.setAttribute("stroke-dasharray","10 7");
    }else if(style.dash==="dotted"){
        visible.setAttribute("stroke-dasharray","2 6");
        visible.setAttribute("stroke-linecap","round");
    }

    if(style.startArrow && style.startArrow!=="none"){
        visible.setAttribute(
            "marker-start",
            markerDefinition(
                svg,
                `${line.id}-start`,
                color,
                style.startArrow
            )
        );
    }

    if(style.endArrow && style.endArrow!=="none"){
        visible.setAttribute(
            "marker-end",
            markerDefinition(
                svg,
                `${line.id}-end`,
                color,
                style.endArrow
            )
        );
    }

    const hit=document.createElementNS(
        "http://www.w3.org/2000/svg",
        "path"
    );

    hit.setAttribute("d",d);
    hit.classList.add("connector-hit");

    hit.addEventListener("click",e=>{
        e.stopPropagation();
        state.selected=line.id;
        render();
    });

    group.appendChild(visible);
    group.appendChild(hit);

    svg.appendChild(group);

    if(state.selected===line.id){

        createEndpoint(
            line,
            "from",
            p1
        );

        createEndpoint(
            line,
            "to",
            p2
        );
    }
}

function createEndpoint(line,side,point){

    const endpoint=document.createElement("div");

    endpoint.className=
        `endpoint ${side==="from"?"start":"end"}`;

    endpoint.style.left=`${point.x}%`;
    endpoint.style.top=`${point.y}%`;

    endpoint.title=
        side==="from"
        ? "始点をドラッグ"
        : "終点をドラッグ";

    endpoint.addEventListener(
        "pointerdown",
        e=>{
            e.preventDefault();
            e.stopPropagation();

            dragEndpoint(
                e,
                line,
                side
            );
        }
    );

    $("overlay").appendChild(endpoint);
}

function dragEndpoint(event,line,side){

    const item=findItem(
        line[side].itemId
    );

    if(!item) return;

    const target=event.currentTarget;

    target.setPointerCapture(event.pointerId);

    const pointerId=event.pointerId;

    const move=e=>{

        if(e.pointerId!==pointerId) return;

        const rect=stage.getBoundingClientRect();

        const sx=
            ((e.clientX-rect.left)/rect.width)*100;

        const sy=
            ((e.clientY-rect.top)/rect.height)*100;

        const localX=
            ((sx-item.x)/item.w)*100;

        const localY=
            ((sy-item.y)/item.h)*100;

        line[side].x=clamp(localX,0,100);
        line[side].y=clamp(localY,0,100);

        renderConnectorsOnly();
    };

    const end=()=>{

        target.removeEventListener(
            "pointermove",
            move
        );

        target.removeEventListener(
            "pointerup",
            end
        );

        target.removeEventListener(
            "pointercancel",
            end
        );

        render();
        saveLocal();
    };

    target.addEventListener(
        "pointermove",
        move
    );

    target.addEventListener(
        "pointerup",
        end
    );

    target.addEventListener(
        "pointercancel",
        end
    );
}

/* ---------------------------
   オブジェクトドラッグ
--------------------------- */

function dragOrResize(
    event,
    item,
    element,
    resizing
){

    if(event.button!==0) return;

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

    const pointerId=event.pointerId;

    element.setPointerCapture(pointerId);

    const move=e=>{

        if(e.pointerId!==pointerId) return;

        const rect=stage.getBoundingClientRect();

        if(!rect.width || !rect.height) return;

        const dx=
            (e.clientX-origin.clientX)/
            rect.width*100;

        const dy=
            (e.clientY-origin.clientY)/
            rect.height*100;

        if(resizing){

            item.w=clamp(
                origin.w+dx,
                5,
                100-item.x
            );

            item.h=clamp(
                origin.h+dy,
                5,
                100-item.y
            );

            element.style.width=`${item.w}%`;
            element.style.height=`${item.h}%`;

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

            element.style.left=`${item.x}%`;
            element.style.top=`${item.y}%`;
        }

        renderConnectorsOnly();
    };

    const end=()=>{

        element.removeEventListener(
            "pointermove",
            move
        );

        element.removeEventListener(
            "pointerup",
            end
        );

        element.removeEventListener(
            "pointercancel",
            end
        );

        render();
        saveLocal();
    };

    element.addEventListener(
        "pointermove",
        move
    );

    element.addEventListener(
        "pointerup",
        end
    );

    element.addEventListener(
        "pointercancel",
        end
    );
}

/* ---------------------------
   直接文字編集
--------------------------- */

function startDirectTextEdit(item,element){

    if(item.type!=="comment") return;

    if(state.editingText) return;

    state.editingText=true;

    const textarea=document.createElement("textarea");

    textarea.className="direct-edit";

    textarea.value=item.text || "";

    textarea.style.fontSize=
        `${item.style?.fontSize || 24}px`;

    textarea.style.fontWeight=
        item.style?.fontWeight || "400";

    textarea.style.color=
        item.style?.fontColor || "#fff";

    textarea.style.textAlign=
        item.style?.textAlign || "left";

    textarea.style.background=
        item.style?.bgColor || "#000";

    textarea.style.opacity=
        item.style?.bgOpacity ?? .86;

    element.replaceChildren(textarea);

    textarea.focus();
    textarea.select();

    const finish=()=>{

        item.text=textarea.value.trim() || "コメント";

        state.editingText=false;

        render();
        saveLocal();
    };

    textarea.addEventListener(
        "blur",
        finish,
        {once:true}
    );

    textarea.addEventListener(
        "keydown",
        e=>{
            if(
                (e.ctrlKey || e.metaKey) &&
                e.key==="Enter"
            ){
                textarea.blur();
            }

            if(e.key==="Escape"){
                state.editingText=false;
                render();
            }
        }
    );
}

/* ---------------------------
   描画
--------------------------- */

function fitStage(){

    const area=$("videoArea");

    const vw=video.videoWidth || 16;
    const vh=video.videoHeight || 9;

    const scale=Math.min(
        area.clientWidth/vw,
        area.clientHeight/vh
    );

    stage.style.width=
        `${Math.max(1,vw*scale)}px`;

    stage.style.height=
        `${Math.max(1,vh*scale)}px`;
}

new ResizeObserver(
    fitStage
).observe($("videoArea"));

function clearEndpointElements(){

    document
        .querySelectorAll(".endpoint")
        .forEach(el=>el.remove());
}

function renderConnectorsOnly(){

    const svg=$("connectors");

    svg.replaceChildren();

    clearEndpointElements();

    const t=video.currentTime || 0;

    state.connectors.forEach(line=>{

        if(
            t<line.start ||
            t>=line.end
        ){
            return;
        }

        const a=findItem(line.from.itemId);
        const b=findItem(line.to.itemId);

        if(
            !a ||
            !b ||
            t<a.start ||
            t>=a.end ||
            t<b.start ||
            t>=b.end
        ){
            return;
        }

        drawConnectorLine(
            svg,
            line
        );
    });
}

function drawObjects(){

    const container=$("objects");

    container.replaceChildren();

    const t=video.currentTime || 0;

    state.items.forEach(item=>{

        if(
            t<item.start ||
            t>=item.end
        ){
            return;
        }

        const element=document.createElement("div");

        element.className=
            `edit-object ${
                item.type==="comment"
                ? "comment-object"
                : "box-object"
            }`;

        if(state.selected===item.id){
            element.classList.add("selected");
        }

        element.style.left=`${item.x}%`;
        element.style.top=`${item.y}%`;
        element.style.width=`${item.w}%`;
        element.style.height=`${item.h}%`;

        if(item.type==="comment"){

            const style=item.style || {};

            element.textContent=item.text || "";

            element.style.fontSize=
                `${style.fontSize || 24}px`;

            element.style.color=
                style.fontColor || "#fff";

            element.style.backgroundColor=
                style.bgColor || "#000";

            element.style.opacity=
                1;

            element.style.fontWeight=
                style.fontWeight || "400";

            element.style.textAlign=
                style.textAlign || "left";

            const bg=hexToRgba(
                style.bgColor || "#000",
                Number(style.bgOpacity ?? .86)
            );

            element.style.backgroundColor=bg;
        }else{

            const style=item.style || {};

            element.style.border=
                `${Number(style.borderWidth || 3)}px solid ${
                    style.borderColor || "#ff453a"
                }`;

            element.style.backgroundColor=
                hexToRgba(
                    style.fill || "#ff453a",
                    Number(style.fillOpacity ?? .08)
                );

            element.style.opacity=
                Number(style.opacity ?? .95);
        }

        const handle=document.createElement("div");

        handle.className="resize-handle";
        handle.title="ドラッグしてサイズ変更";

        handle.addEventListener(
            "pointerdown",
            e=>{
                dragOrResize(
                    e,
                    item,
                    element,
                    true
                );
            }
        );

        element.appendChild(handle);

        element.addEventListener(
            "pointerdown",
            e=>{

                if(e.target===handle) return;

                if(state.connectMode){

                    e.preventDefault();
                    e.stopPropagation();

                    selectItem(item);

                }else{

                    dragOrResize(
                        e,
                        item,
                        element,
                        false
                    );
                }
            }
        );

        element.addEventListener(
            "dblclick",
            e=>{
                e.stopPropagation();

                if(item.type==="comment"){
                    startDirectTextEdit(
                        item,
                        element
                    );
                }
            }
        );

        container.appendChild(element);
    });
}

function render(){

    fitStage();

    drawObjects();

    renderConnectorsOnly();

    renderLists();

    renderTracks();

    updateSelection();
}

function hexToRgba(hex,opacity){

    const value=String(hex).replace("#","");

    const r=parseInt(
        value.length===3
        ? value[0]+value[0]
        : value.slice(0,2),
        16
    );

    const g=parseInt(
        value.length===3
        ? value[1]+value[1]
        : value.slice(2,4),
        16
    );

    const b=parseInt(
        value.length===3
        ? value[2]+value[2]
        : value.slice(4,6),
        16
    );

    return `rgba(${r||0},${g||0},${b||0},${clamp(opacity,0,1)})`;
}

/* ---------------------------
   選択UI
--------------------------- */

function updateSelection(){

    const selected=currentSelection();

    const objectSelected=
        selected &&
        state.items.includes(selected);

    const connectorSelected=
        selected &&
        state.connectors.includes(selected);

    $("objectFields").hidden=!objectSelected;
    $("connectorFields").hidden=!connectorSelected;

    if(!selected){

        $("selectionHint").textContent=
            "要素または線を選択してください。";

        return;
    }

    if(objectSelected){

        $("selectionHint").textContent=
            selected.type==="comment"
            ? "コメントを編集中"
            : "強調枠を編集中";

        $("textField").hidden=
            selected.type!=="comment";

        $("commentStyleFields").hidden=
            selected.type!=="comment";

        $("boxStyleFields").hidden=
            selected.type!=="box";

        $("objectText").value=
            selected.text || "";

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

        const style=selected.style || {};

        if(selected.type==="comment"){

            $("fontSize").value=
                style.fontSize || 24;

            $("fontColor").value=
                style.fontColor || "#ffffff";

            $("bgColor").value=
                style.bgColor || "#000000";

            $("bgOpacity").value=
                style.bgOpacity ?? .86;

            $("fontWeight").value=
                style.fontWeight || "400";

            $("textAlign").value=
                style.textAlign || "left";

        }else{

            $("borderColor").value=
                style.borderColor || "#ff453a";

            $("borderWidth").value=
                style.borderWidth || 3;

            $("boxOpacity").value=
                style.opacity ?? .95;

            $("boxFill").value=
                style.fill || "#ff453a";
        }

    }else{

        $("selectionHint").textContent=
            "接続線を編集中";

        $("objectFields").hidden=true;

        const line=selected;

        const style=line.style || {};

        $("connectorStart").value=
            Number(line.start).toFixed(2);

        $("connectorEnd").value=
            Number(line.end).toFixed(2);

        $("lineColor").value=
            style.color || "#ffffff";

        $("lineWidth").value=
            style.width || 3;

        $("lineOpacity").value=
            style.opacity ?? 1;

        $("lineDash").value=
            style.dash || "solid";

        $("startArrow").value=
            style.startArrow || "none";

        $("endArrow").value=
            style.endArrow || "arrow";

        $("lineShape").value=
            line.shape || "line";
    }
}

/* ---------------------------
   オブジェクト適用
--------------------------- */

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
        ![x,y,w,h].every(Number.isFinite) ||
        x<0 ||
        y<0 ||
        w<5 ||
        h<5 ||
        x+w>100 ||
        y+h>100
    ){
        return notify(
            "時間・位置・サイズを正しく入力してください。"
        );
    }

    if(
        item.type==="comment" &&
        !$("objectText").value.trim()
    ){
        return notify("コメントを入力してください。");
    }

    Object.assign(
        item,
        range,
        {x,y,w,h}
    );

    if(item.type==="comment"){

        item.text=$("objectText").value.trim();

        item.style={
            ...(item.style || {}),
            fontSize:Number($("fontSize").value)||24,
            fontColor:$("fontColor").value,
            bgColor:$("bgColor").value,
            bgOpacity:Number($("bgOpacity").value),
            fontWeight:$("fontWeight").value,
            textAlign:$("textAlign").value
        };

    }else{

        item.style={
            ...(item.style || {}),
            borderColor:$("borderColor").value,
            borderWidth:Number($("borderWidth").value)||3,
            opacity:Number($("boxOpacity").value),
            fill:$("boxFill").value
        };
    }

    state.connectors.forEach(line=>{

        if(
            line.from.itemId===item.id ||
            line.to.itemId===item.id
        ){

            const a=findItem(line.from.itemId);
            const b=findItem(line.to.itemId);

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

    state.connectors=
        state.connectors.filter(
            line=>line.end>line.start
        );

    render();
    saveLocal();
};

/* ---------------------------
   コネクタ適用
--------------------------- */

$("applyConnector").onclick=()=>{

    const line=currentSelection();

    if(
        !line ||
        !state.connectors.includes(line)
    ){
        return;
    }

    const range=validRange(
        $("connectorStart").value,
        $("connectorEnd").value
    );

    if(!range){
        return notify(
            "線の開始・終了時間を正しく入力してください。"
        );
    }

    line.start=range.start;
    line.end=range.end;

    line.shape=$("lineShape").value;

    line.style={
        ...(line.style || {}),

        color:$("lineColor").value,

        width:Number(
            $("lineWidth").value
        )||3,

        opacity:Number(
            $("lineOpacity").value
        ),

        dash:$("lineDash").value,

        startArrow:$("startArrow").value,

        endArrow:$("endArrow").value
    };

    render();
    saveLocal();
};

/* ---------------------------
   削除
--------------------------- */

function deleteSelected(){

    if(!state.selected){
        return notify(
            "削除する項目を選択してください。"
        );
    }

    const id=state.selected;

    state.items=
        state.items.filter(
            item=>item.id!==id
        );

    state.connectors=
        state.connectors.filter(
            line=>
                line.id!==id &&
                findItem(line.from.itemId) &&
                findItem(line.to.itemId)
        );

    state.selected=null;

    render();
    saveLocal();
}

$("deleteSelected").onclick=deleteSelected;

document.addEventListener(
    "keydown",
    event=>{

        if(
            $("editor").style.display==="none"
        ){
            return;
        }

        if(event.key==="Escape"){

            state.connectMode=false;
            state.connectFrom=null;

            $("connect").textContent="線でつなぐ";

            $("contextMenu").style.display="none";

            return;
        }

        const tag=
            document.activeElement?.tagName;

        if(
            (event.key==="Delete" ||
             event.key==="Backspace") &&
            !["INPUT","TEXTAREA","SELECT"].includes(tag) &&
            state.selected
        ){

            event.preventDefault();

            deleteSelected();
        }
    }
);

/* ---------------------------
   リスト
--------------------------- */

function renderLists(){

    const objects=$("objectList");

    objects.replaceChildren();

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
            `${
                item.type==="comment"
                ? `コメント: ${item.text}`
                : "強調枠"
            }　${formatTime(item.start)}～${formatTime(item.end)}`;

        row.appendChild(label);

        const buttons=document.createElement("div");

        buttons.className="buttons";

        const jump=document.createElement("button");

        jump.textContent="選択";

        jump.onclick=()=>{
            video.currentTime=item.start;
            selectItem(item);
        };

        const remove=document.createElement("button");

        remove.textContent="削除";

        remove.onclick=()=>{
            state.selected=item.id;
            deleteSelected();
        };

        buttons.append(
            jump,
            remove
        );

        row.appendChild(buttons);

        objects.appendChild(row);
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

        const from=findItem(
            line.from.itemId
        );

        const to=findItem(
            line.to.itemId
        );

        row.textContent=
            `線: ${
                from?.type==="comment"
                ? from.text
                : "要素"
            } → ${
                to?.type==="comment"
                ? to.text
                : "要素"
            }`;

        const buttons=document.createElement("div");

        buttons.className="buttons";

        const select=document.createElement("button");

        select.textContent="選択";

        select.onclick=()=>{
            video.currentTime=line.start;
            state.selected=line.id;
            render();
        };

        const remove=document.createElement("button");

        remove.textContent="削除";

        remove.onclick=()=>{
            state.selected=line.id;
            deleteSelected();
        };

        buttons.append(
            select,
            remove
        );

        row.appendChild(buttons);

        objects.appendChild(row);
    });

    const skips=$("skipList");

    skips.replaceChildren();

    state.skips.forEach((range,index)=>{

        const row=document.createElement("div");

        row.className="list-item";

        row.textContent=
            `${formatTime(range.start)}～${formatTime(range.end)} `;

        const remove=document.createElement("button");

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
    });
}

/* ---------------------------
   タイムライン
--------------------------- */

function renderTracks(){

    const tracks=[
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
    ];

    const max=duration();

    tracks.forEach(
        ([trackId,data,className])=>{

            const track=$(trackId);

            track.replaceChildren();

            if(!max) return;

            data.forEach(entry=>{

                const marker=document.createElement("span");

                marker.className=className;

                marker.style.left=
                    `${clamp(
                        entry.start/max*100,
                        0,
                        100
                    )}%`;

                marker.style.width=
                    `${clamp(
                        (entry.end-entry.start)/
                        max*100,
                        0,
                        100
                    )}%`;

                marker.title=
                    `${formatTime(entry.start)}～${formatTime(entry.end)}`;

                marker.onclick=()=>{
                    video.currentTime=entry.start;

                    if(entry.id){
                        state.selected=entry.id;
                        render();
                    }
                };

                track.appendChild(marker);
            });
        }
    );
}

/* ---------------------------
   再生
--------------------------- */

function refreshPlayback(){

    const max=duration();
    const t=video.currentTime || 0;

    $("seek").max=max;
    $("seek").value=t;

    $("timeReadout").textContent=
        `${formatTime(t)} / ${formatTime(max)}`;

    drawObjects();
    renderConnectorsOnly();

    if(video.paused) return;

    const skip=state.skips.find(
        range=>
            t>=range.start &&
            t<range.end
    );

    if(
        skip &&
        Math.abs(
            state.lastSkipAt-t
        )>.05
    ){

        state.lastSkipAt=t;

        video.currentTime=skip.end;
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

$("seek").oninput=e=>{
    video.currentTime=Number(e.target.value);
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

/* ---------------------------
   スキップ
--------------------------- */

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
        return notify(
            "スキップの開始・終了時間を正しく入力してください。"
        );
    }

    state.skips.push(range);

    state.skips.sort(
        (a,b)=>a.start-b.start
    );

    render();
    saveLocal();
};

$("clearEdits").onclick=()=>{

    if(!confirm(
        "コメント・強調枠・接続線・スキップ範囲をすべて削除しますか？"
    )){
        return;
    }

    state.items=[];
    state.connectors=[];
    state.skips=[];
    state.selected=null;

    render();
    saveLocal();
};

/* ---------------------------
   JSON
--------------------------- */

function validateProject(project){

    if(
        !project ||
        ![1,2].includes(project.version) ||
        !Array.isArray(project.items) ||
        !Array.isArray(project.connectors) ||
        !Array.isArray(project.skips)
    ){
        throw new Error(
            "編集データの形式が正しくありません。"
        );
    }

    const ids=new Set();

    project.items.forEach(item=>{

        if(
            !item ||
            !["comment","box"].includes(item.type) ||
            typeof item.id!=="string" ||
            ids.has(item.id)
        ){
            throw new Error(
                "編集要素のデータが正しくありません。"
            );
        }

        if(
            !Number.isFinite(Number(item.start)) ||
            !Number.isFinite(Number(item.end)) ||
            Number(item.start)<0 ||
            Number(item.end)<=Number(item.start)
        ){
            throw new Error(
                "編集要素の時間が不正です。"
            );
        }

        ids.add(item.id);
    });

    project.connectors.forEach(line=>{

        const fromId=
            line.from?.itemId ||
            line.from;

        const toId=
            line.to?.itemId ||
            line.to;

        if(
            !line ||
            typeof line.id!=="string" ||
            !ids.has(fromId) ||
            !ids.has(toId)
        ){
            throw new Error(
                "接続線のデータが正しくありません。"
            );
        }
    });

    project.skips.forEach(range=>{

        if(
            !range ||
            Number(range.start)<0 ||
            Number(range.end)<=Number(range.start)
        ){
            throw new Error(
                "スキップ範囲が不正です。"
            );
        }
    });

    return project;
}

function migrateProject(project){

    validateProject(project);

    const result=
        structuredClone(project);

    result.version=2;

    result.items.forEach(item=>{

        if(item.type==="comment"){

            item.style={
                ...defaultCommentStyle(),
                ...(item.style || {})
            };

        }else{

            item.style={
                ...defaultBoxStyle(),
                ...(item.style || {})
            };
        }
    });

    result.connectors=
        result.connectors.map(line=>{

            if(typeof line.from==="string"){

                const a=findItem(line.from);
                const b=findItem(line.to);

                return {
                    id:line.id,
                    from:{
                        itemId:line.from,
                        x:100,
                        y:50
                    },
                    to:{
                        itemId:line.to,
                        x:0,
                        y:50
                    },
                    start:line.start,
                    end:line.end,
                    shape:"line",
                    style:{
                        color:"#ffffff",
                        width:3,
                        opacity:1,
                        dash:"solid",
                        startArrow:"none",
                        endArrow:"arrow"
                    }
                };
            }

            line.from={
                itemId:line.from.itemId,
                x:Number(line.from.x ?? 100),
                y:Number(line.from.y ?? 50)
            };

            line.to={
                itemId:line.to.itemId,
                x:Number(line.to.x ?? 0),
                y:Number(line.to.y ?? 50)
            };

            line.style={
                color:"#ffffff",
                width:3,
                opacity:1,
                dash:"solid",
                startArrow:"none",
                endArrow:"arrow",
                ...(line.style || {})
            };

            line.shape=line.shape || "line";

            return line;
        });

    return result;
}

$("saveProject").onclick=()=>{

    const project=projectData();

    download(
        new Blob(
            [
                JSON.stringify(
                    project,
                    null,
                    2
                )
            ],
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

    if(!file) return;

    try{

        const project=
            JSON.parse(
                await file.text()
            );

        const migrated=
            migrateProject(project);

        state.items=migrated.items;
        state.connectors=migrated.connectors;
        state.skips=migrated.skips;

        state.selected=null;

        render();
        saveLocal();

        notify(
            "編集内容を読み込みました。",
            true
        );

    }catch(error){

        notify(
            `読み込みに失敗しました。${
                error.message || ""
            }`
        );

    }finally{

        event.target.value="";
    }
};

/* ---------------------------
   サーバー保存
--------------------------- */

async function uploadVideo(){

    if(!state.blob){
        throw new Error(
            "録画動画がありません。"
        );
    }

    if(!state.projectId){
        state.projectId=uid();
    }

    const form=new FormData();

    form.append(
        "id",
        state.projectId
    );

    form.append(
        "video",
        state.blob,
        `recording-${fileStamp()}.webm`
    );

    const response=
        await fetch(
            "?api=upload",
            {
                method:"POST",
                body:form
            }
        );

    const data=await response.json();

    if(!data.ok){
        throw new Error(
            data.error ||
            "動画保存に失敗しました。"
        );
    }

    state.videoFile=data.file;

    return data;
}

async function saveServer(){

    if(!state.blob){
        throw new Error(
            "録画動画がありません。"
        );
    }

    if(!state.projectId){
        state.projectId=uid();
    }

    $("status").textContent=
        "動画をサーバーへ保存中";

    await uploadVideo();

    $("status").textContent=
        "編集内容をサーバーへ保存中";

    const response=
        await fetch(
            "?api=save",
            {
                method:"POST",
                headers:{
                    "Content-Type":
                        "application/json"
                },
                body:JSON.stringify({
                    id:state.projectId,
                    name:$("projectName").value.trim() ||
                        "無題の動画編集",
                    videoFile:state.videoFile,
                    project:projectData()
                })
            }
        );

    const data=await response.json();

    if(!data.ok){
        throw new Error(
            data.error ||
            "編集内容の保存に失敗しました。"
        );
    }

    state.projectName=
        $("projectName").value.trim();

    $("status").textContent=
        "サーバー保存完了";

    notify(
        "動画と編集内容をサーバーへ保存しました。",
        true
    );

    await loadServerProjects();
}

$("serverSave").onclick=async()=>{

    try{

        $("serverSave").disabled=true;

        await saveServer();

    }catch(error){

        notify(
            `サーバー保存に失敗しました。${
                error.message || ""
            }`
        );

    }finally{

        $("serverSave").disabled=false;
    }
};

async function loadServerProjects(){

    try{

        const response=
            await fetch("?api=list");

        const data=
            await response.json();

        if(!data.ok) return;

        const container=
            $("serverProjectList");

        container.replaceChildren();

        data.projects.forEach(project=>{

            const row=
                document.createElement("div");

            row.className="list-item";

            const title=
                document.createElement("div");

            title.textContent=
                project.name ||
                "無題の動画編集";

            row.appendChild(title);

            const date=
                document.createElement("div");

            date.className="help";

            date.textContent=
                project.updated_at;

            row.appendChild(date);

            const buttons=
                document.createElement("div");

            buttons.className="buttons";

            const load=
                document.createElement("button");

            load.textContent="読み込む";

            load.onclick=async()=>{

                try{

                    await loadServerProject(
                        project.id
                    );

                }catch(error){

                    notify(
                        error.message ||
                        "読み込みに失敗しました。"
                    );
                }
            };

            buttons.appendChild(load);

            row.appendChild(buttons);

            container.appendChild(row);
        });

    }catch{

        // サーバー保存が使えない場合でも
        // ローカル編集は継続可能。
    }
}

async function loadServerProject(id){

    const response=
        await fetch(
            `?api=load&id=${encodeURIComponent(id)}`
        );

    const data=
        await response.json();

    if(!data.ok){
        throw new Error(
            data.error ||
            "プロジェクトを読み込めません。"
        );
    }

    const project=
        migrateProject(data.project);

    state.projectId=data.id;
    state.projectName=data.name;
    state.videoFile=data.videoFile || "";

    $("projectName").value=
        data.name || "無題の動画編集";

    state.items=project.items;
    state.connectors=project.connectors;
    state.skips=project.skips;
    state.selected=null;

    if(data.videoFile){

        const videoUrl=
            `?api=video&file=${encodeURIComponent(
                data.videoFile
            )}`;

        if(state.url){
            URL.revokeObjectURL(state.url);
            state.url=null;
        }

        state.blob=null;

        video.src=videoUrl;
        video.load();
    }

    render();

    notify(
        "サーバーからプロジェクトを読み込みました。",
        true
    );
}

$("serverLoad").onclick=async()=>{
    await loadServerProjects();
    notify(
        "サーバー上のプロジェクト一覧を更新しました。",
        true
    );
};

/* ---------------------------
   右クリックメニュー
--------------------------- */

stage.addEventListener(
    "contextmenu",
    event=>{

        if(!state.blob && !state.videoFile){
            return;
        }

        event.preventDefault();

        const rect=
            stage.getBoundingClientRect();

        state.contextPoint={
            x:clamp(
                (event.clientX-rect.left)/
                rect.width*100,
                0,
                70
            ),
            y:clamp(
                (event.clientY-rect.top)/
                rect.height*100,
                0,
                70
            )
        };

        const menu=
            $("contextMenu");

        menu.style.left=
            `${clamp(
                event.clientX,
                0,
                innerWidth-190
            )}px`;

        menu.style.top=
            `${clamp(
                event.clientY,
                0,
                innerHeight-150
            )}px`;

        menu.style.display="block";
    }
);

$("contextMenu").onclick=event=>{

    const action=
        event.target
            .closest("button")
            ?.dataset.action;

    if(
        action==="comment" ||
        action==="box"
    ){

        addItem(
            action,
            state.contextPoint
        );
    }

    if(action==="connect"){
        $("connect").click();
    }

    $("contextMenu").style.display="none";
};

document.addEventListener(
    "pointerdown",
    event=>{

        if(
            !$("contextMenu").contains(
                event.target
            )
        ){
            $("contextMenu").style.display="none";
        }
    }
);

/* ---------------------------
   ローカル復元
--------------------------- */

try{

    const saved=
        localStorage.getItem(
            STORAGE_KEY
        );

    if(saved){

        const project=
            migrateProject(
                JSON.parse(saved)
            );

        state.items=project.items;
        state.connectors=project.connectors;
        state.skips=project.skips;
    }

}catch{

    notify(
        "前回の編集内容を復元できませんでした。"
    );
}

/* ---------------------------
   初期化
--------------------------- */

updateRecordingButtons();

loadServerProjects();

</script>

</body>
</html>

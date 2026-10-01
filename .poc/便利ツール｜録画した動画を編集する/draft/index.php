<?php
declare(strict_types=1);

/*
 * 動画上直接編集ツール
 * Apache + PHP
 *
 * 必要:
 *   PHP 7.4+
 *   Apache
 *
 * MP4書き出し:
 *   FFmpeg がサーバーにインストールされていること
 *
 * 保存:
 *   サーバー: ./data/projects/*.json
 *   動画本体: ブラウザ IndexedDB
 *
 * data/projects は Apache/PHP から書き込み可能にしてください。
 */

const APP_VERSION = 6;
const MAX_SERVER_PROJECTS = 20;
const MAX_UPLOAD_BYTES = 1024 * 1024 * 1024; // 1GB
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const PROJECT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'projects';
const EXPORT_DIR = DATA_DIR . DIRECTORY_SEPARATOR . 'exports';

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

function ensureDir(string $dir): bool
{
    if (is_dir($dir)) {
        return is_writable($dir);
    }

    return @mkdir($dir, 0775, true) && is_writable($dir);
}

function ensureProjectDir(): bool
{
    return ensureDir(DATA_DIR) && ensureDir(PROJECT_DIR);
}

function ensureExportDir(): bool
{
    return ensureDir(DATA_DIR) && ensureDir(EXPORT_DIR);
}

function validProjectId(string $id): bool
{
    return (bool)preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $id);
}

function safeFileName(string $name, string $fallback = 'file'): string
{
    $name = trim($name);
    $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name) ?? '';
    $name = trim($name, '._-');

    return $name !== '' ? mb_substr($name, 0, 120) : $fallback;
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

    $files = glob(PROJECT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $result = [];

    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }

        $result[] = [
            'projectId' => (string)($data['projectId'] ?? pathinfo($file, PATHINFO_FILENAME)),
            'name' => (string)($data['name'] ?? '名称未設定'),
            'savedAt' => (string)($data['savedAt'] ?? ''),
            'videoName' => (string)($data['videoName'] ?? ''),
        ];
    }

    usort(
        $result,
        static fn(array $a, array $b): int =>
            strcmp($b['savedAt'], $a['savedAt'])
    );

    return $result;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        jsonResponse([
            'ok' => false,
            'message' => 'JSONデータが不正です。'
        ], 400);
    }

    return $data;
}

function validateProjectPayload(array $payload): array
{
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

    $payload['name'] = $name;

    if (!isset($payload['elements']) || !is_array($payload['elements'])) {
        $payload['elements'] = [];
    }

    if (!isset($payload['connections']) || !is_array($payload['connections'])) {
        $payload['connections'] = [];
    }

    if (!isset($payload['duration'])) {
        $payload['duration'] = 0;
    }

    $payload['duration'] = max(
        0,
        min(86400, (float)$payload['duration'])
    );

    return $payload;
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
            'php' => PHP_VERSION,
            'serverWritable' => ensureProjectDir(),
            'serverLimit' => MAX_SERVER_PROJECTS,
            'serverCount' => count(readServerProjects()),
            'ffmpeg' => (bool)shell_exec('command -v ffmpeg 2>/dev/null')
        ]);
    }

    if ($api === 'list') {
        jsonResponse([
            'ok' => true,
            'projects' => readServerProjects(),
            'limit' => MAX_SERVER_PROJECTS
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

        $payload = validateProjectPayload(readJsonBody());

        $id = trim((string)($payload['projectId'] ?? ''));

        if ($id === '') {
            $id = 'project-' . bin2hex(random_bytes(12));
        }

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $existing = is_file(projectPath($id));

        if (!$existing) {
            $projects = readServerProjects();

            if (count($projects) >= MAX_SERVER_PROJECTS) {
                jsonResponse([
                    'ok' => false,
                    'limit' => true,
                    'message' => 'サーバー保存上限に達しています。'
                ], 409);
            }
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
                'message' => 'プロジェクトを保存できませんでした。'
            ], 500);
        }

        jsonResponse([
            'ok' => true,
            'projectId' => $id,
            'savedAt' => $payload['savedAt'],
            'version' => APP_VERSION
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

        $path = projectPath($id);

        if (!is_file($path)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトが見つかりません。'
            ], 404);
        }

        $raw = @file_get_contents($path);
        $data = json_decode($raw ?: '', true);

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

    if ($api === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        $payload = readJsonBody();
        $id = (string)($payload['projectId'] ?? '');

        if (!validProjectId($id)) {
            jsonResponse([
                'ok' => false,
                'message' => 'プロジェクトIDが不正です。'
            ], 400);
        }

        $path = projectPath($id);

        if (is_file($path) && !@unlink($path)) {
            jsonResponse([
                'ok' => false,
                'message' => '削除できませんでした。'
            ], 500);
        }

        jsonResponse(['ok' => true]);
    }

    /*
     * MP4書き出し
     *
     * multipart/form-data:
     *   video   = 元動画
     *   project = JSON
     */
    if ($api === 'export') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['ok' => false, 'message' => 'POST only'], 405);
        }

        if (!ensureExportDir()) {
            jsonResponse([
                'ok' => false,
                'message' => '書き出しディレクトリを作成できません。'
            ], 500);
        }

        $ffmpeg = trim((string)shell_exec(
            'command -v ffmpeg 2>/dev/null'
        ));

        if ($ffmpeg === '') {
            jsonResponse([
                'ok' => false,
                'message' => 'サーバーにFFmpegがありません。MP4書き出しにはFFmpegが必要です。'
            ], 500);
        }

        if (!isset($_FILES['video'])) {
            jsonResponse([
                'ok' => false,
                'message' => '動画ファイルが送信されていません。'
            ], 400);
        }

        $video = $_FILES['video'];

        if (
            !isset($video['error']) ||
            (int)$video['error'] !== UPLOAD_ERR_OK
        ) {
            jsonResponse([
                'ok' => false,
                'message' => '動画アップロードに失敗しました。'
            ], 400);
        }

        if ((int)$video['size'] > MAX_UPLOAD_BYTES) {
            jsonResponse([
                'ok' => false,
                'message' => '動画ファイルが大きすぎます。'
            ], 413);
        }

        $projectRaw = (string)($_POST['project'] ?? '');
        $project = json_decode($projectRaw, true);

        if (!is_array($project)) {
            jsonResponse([
                'ok' => false,
                'message' => '書き出し用プロジェクトJSONが不正です。'
            ], 400);
        }

        $duration = max(
            0,
            min(86400, (float)($project['duration'] ?? 0))
        );

        if ($duration <= 0) {
            jsonResponse([
                'ok' => false,
                'message' => '動画の長さを取得できません。'
            ], 422);
        }

        $base = 'export_' . bin2hex(random_bytes(10));
        $inputPath = EXPORT_DIR . DIRECTORY_SEPARATOR . $base . '_input';
        $outputPath = EXPORT_DIR . DIRECTORY_SEPARATOR . $base . '.mp4';

        if (!@move_uploaded_file(
            (string)$video['tmp_name'],
            $inputPath
        )) {
            jsonResponse([
                'ok' => false,
                'message' => '動画ファイルを一時保存できませんでした。'
            ], 500);
        }

        /*
         * FFmpeg filter を作る。
         * 座標は編集画面上の % を動画解像度に対して計算。
         *
         * コメント:
         * drawtext
         *
         * 枠:
         * drawbox
         *
         * 線:
         * drawline
         */
        $filters = [];
        $filterIndex = 0;

        $elements = isset($project['elements']) &&
            is_array($project['elements'])
            ? $project['elements']
            : [];

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $type = (string)($element['type'] ?? '');
            $start = max(0, (float)($element['start'] ?? 0));
            $end = min($duration, (float)($element['end'] ?? $duration));

            if ($end <= $start) {
                continue;
            }

            $x = max(0, min(100, (float)($element['x'] ?? 0)));
            $y = max(0, min(100, (float)($element['y'] ?? 0)));
            $w = max(0.1, min(100, (float)($element['w'] ?? 20)));
            $h = max(0.1, min(100, (float)($element['h'] ?? 10)));

            $enable = sprintf(
                "between(t\\,%s\\,%s)",
                number_format($start, 3, '.', ''),
                number_format($end, 3, '.', '')
            );

            if ($type === 'box') {
                $color = (string)($element['style']['borderColor'] ?? '#ff3b30');
                $thickness = max(
                    1,
                    min(
                        30,
                        (int)($element['style']['borderWidth'] ?? 4)
                    )
                );

                $filters[] = sprintf(
                    "drawbox=x=w*%s/100:y=h*%s/100:w=w*%s/100:h=h*%s/100:color=%s@1:t=%d:enable='%s'",
                    $x,
                    $y,
                    $w,
                    $h,
                    ffmpegColor($color),
                    $thickness,
                    $enable
                );
            }

            if ($type === 'text') {
                $text = (string)($element['text'] ?? '');
                if ($text === '') {
                    continue;
                }

                /*
                 * drawtextの文字列は特殊文字をエスケープ。
                 */
                $text = str_replace(
                    ['\\', ':', "'", '%', "\n", "\r"],
                    ['\\\\', '\\:', "\\'", '\\%', '\n', ''],
                    $text
                );

                $fontSize = max(
                    8,
                    min(
                        200,
                        (int)($element['style']['fontSize'] ?? 32)
                    )
                );

                $color = ffmpegColor(
                    (string)($element['style']['color'] ?? '#ffffff')
                );

                $box = !empty($element['style']['background'])
                    ? ':box=1:boxcolor=' .
                      ffmpegColor(
                          (string)$element['style']['background']
                      ) .
                      ':boxborderw=8'
                    : '';

                $fontFile = findFontFile();

                if ($fontFile !== '') {
                    $fontPart = ':fontfile=' .
                        ffmpegFilterEscape($fontFile);
                } else {
                    $fontPart = '';
                }

                $filters[] = sprintf(
                    "drawtext=text='%s':x=w*%s/100:y=h*%s/100:fontsize=%d:fontcolor=%s%s%s:enable='%s'",
                    $text,
                    $x,
                    $y,
                    $fontSize,
                    $color,
                    $box,
                    $fontPart,
                    $enable
                );
            }
        }

        /*
         * 接続線。
         */
        $connections = isset($project['connections']) &&
            is_array($project['connections'])
            ? $project['connections']
            : [];

        $byId = [];

        foreach ($elements as $element) {
            if (is_array($element) && isset($element['id'])) {
                $byId[(string)$element['id']] = $element;
            }
        }

        foreach ($connections as $connection) {
            if (!is_array($connection)) {
                continue;
            }

            $fromId = (string)($connection['fromId'] ?? '');
            $toId = (string)($connection['toId'] ?? '');

            if (
                !isset($byId[$fromId]) ||
                !isset($byId[$toId])
            ) {
                continue;
            }

            $from = $byId[$fromId];
            $to = $byId[$toId];

            $fx = (float)$from['x'] +
                (float)$from['w'] / 2;

            $fy = (float)$from['y'] +
                (float)$from['h'] / 2;

            $tx = (float)$to['x'] +
                (float)$to['w'] / 2;

            $ty = (float)$to['y'] +
                (float)$to['h'] / 2;

            $start = max(
                (float)($connection['start'] ?? 0),
                (float)($from['start'] ?? 0),
                (float)($to['start'] ?? 0)
            );

            $end = min(
                $duration,
                (float)($connection['end'] ?? $duration),
                (float)($from['end'] ?? $duration),
                (float)($to['end'] ?? $duration)
            );

            if ($end <= $start) {
                continue;
            }

            $color = ffmpegColor(
                (string)($connection['style']['color'] ?? '#ffffff')
            );

            $width = max(
                1,
                min(
                    20,
                    (int)($connection['style']['width'] ?? 4)
                )
            );

            $enable = sprintf(
                "between(t\\,%s\\,%s)",
                number_format($start, 3, '.', ''),
                number_format($end, 3, '.', '')
            );

            $filters[] = sprintf(
                "drawline=x1=w*%s/100:y1=h*%s/100:x2=w*%s/100:y2=h*%s/100:color=%s:thickness=%d:enable='%s'",
                $fx,
                $fy,
                $tx,
                $ty,
                $color,
                $width,
                $enable
            );
        }

        $vf = implode(',', $filters);

        /*
         * filterがない場合も正常なMP4として出力。
         */
        $filterArg = $vf !== ''
            ? ' -vf ' . escapeshellarg($vf)
            : '';

        $cmd =
            escapeshellcmd($ffmpeg) .
            ' -y' .
            ' -i ' . escapeshellarg($inputPath) .
            $filterArg .
            ' -c:v libx264' .
            ' -preset veryfast' .
            ' -crf 18' .
            ' -pix_fmt yuv420p' .
            ' -c:a aac' .
            ' -movflags +faststart' .
            ' ' . escapeshellarg($outputPath) .
            ' 2>&1';

        $output = [];
        $returnCode = 0;

        exec($cmd, $output, $returnCode);

        @unlink($inputPath);

        if (
            $returnCode !== 0 ||
            !is_file($outputPath) ||
            filesize($outputPath) < 1024
        ) {
            @unlink($outputPath);

            jsonResponse([
                'ok' => false,
                'message' =>
                    "MP4書き出しに失敗しました。\n" .
                    implode("\n", array_slice($output, -12))
            ], 500);
        }

        $downloadName =
            safeFileName(
                (string)($project['name'] ?? 'edited_video'),
                'edited_video'
            ) .
            '.mp4';

        /*
         * download URL。
         * 一時ファイルなのでダウンロード後も残るが、
         * data/exports の運用で定期削除可能。
         */
        $relative = 'data/exports/' . basename($outputPath);

        jsonResponse([
            'ok' => true,
            'url' => $relative,
            'filename' => $downloadName
        ]);
    }

    jsonResponse([
        'ok' => false,
        'message' => 'Unknown API'
    ], 404);
}

function ffmpegColor(string $color): string
{
    $color = trim($color);

    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return substr($color, 1);
    }

    if (preg_match('/^[a-zA-Z]+$/', $color)) {
        return $color;
    }

    return 'ffffff';
}

function ffmpegFilterEscape(string $value): string
{
    return str_replace(
        ['\\', ':', "'"],
        ['\\\\', '\\:', "\\'"],
        $value
    );
}

function findFontFile(): string
{
    $candidates = [
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/opentype/noto/NotoSansCJKjp-Regular.otf',
        '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            return $file;
        }
    }

    return '';
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画上直接編集</title>

<style>
:root{
    --bg:#0d0f12;
    --panel:#171a1f;
    --panel2:#20242a;
    --border:#353b44;
    --text:#f3f5f7;
    --muted:#9da5af;
    --blue:#1976d2;
    --green:#21884b;
    --red:#b83232;
    --yellow:#d59c20;
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

button,
input,
textarea,
select{
    font:inherit;
}

button{
    border:1px solid #4a515b;
    border-radius:6px;
    background:#292e35;
    color:#fff;
    padding:8px 12px;
    cursor:pointer;
}

button:hover:not(:disabled){
    background:#383f48;
}

button:disabled{
    opacity:.4;
    cursor:not-allowed;
}

button.primary{background:var(--blue)}
button.success{background:var(--green)}
button.danger{background:var(--red)}

input,
textarea,
select{
    width:100%;
    color:#fff;
    background:#22262c;
    border:1px solid #4a515b;
    border-radius:5px;
    padding:8px;
}

input[type=color]{
    height:38px;
    padding:3px;
}

#app{
    width:100%;
    height:100%;
    display:flex;
    flex-direction:column;
}

#topbar{
    height:54px;
    flex-shrink:0;
    display:flex;
    align-items:center;
    gap:7px;
    padding:7px 10px;
    border-bottom:1px solid var(--border);
    background:#17191d;
}

#topbar h1{
    margin:0 12px 0 0;
    font-size:16px;
    white-space:nowrap;
}

#projectName{
    width:210px;
    flex-shrink:1;
}

.spacer{flex:1}

#status{
    color:#aeb5bf;
    font-size:12px;
    white-space:nowrap;
}

#editor{
    min-height:0;
    flex:1;
    display:flex;
    flex-direction:column;
}

#stageArea{
    min-height:0;
    flex:1;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#000;
    overflow:hidden;
    position:relative;
}

#videoStage{
    position:relative;
    background:#000;
    line-height:0;
    box-shadow:0 0 0 1px #222;
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
}

#objects{
    position:absolute;
    inset:0;
}

#connections{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    overflow:visible;
    pointer-events:none;
}

.object{
    position:absolute;
    pointer-events:auto;
    user-select:none;
    touch-action:none;
    cursor:move;
}

.object.selected{
    outline:2px solid #50a7ff;
    outline-offset:2px;
}

.object.text{
    min-width:40px;
    min-height:24px;
    padding:5px 8px;
    white-space:pre-wrap;
    overflow:hidden;
    word-break:break-word;
    line-height:1.2;
}

.object.box{
    background:transparent;
}

.handle{
    display:none;
    position:absolute;
    width:12px;
    height:12px;
    right:-7px;
    bottom:-7px;
    background:#fff;
    border:1px solid #111;
    border-radius:50%;
    cursor:nwse-resize;
}

.object.selected .handle{
    display:block;
}

.point{
    display:none;
    position:absolute;
    width:12px;
    height:12px;
    margin:-6px;
    background:#2088df;
    border:2px solid white;
    border-radius:50%;
    z-index:5;
    cursor:crosshair;
}

.object.selected .point{
    display:block;
}

.point.n{left:50%;top:0}
.point.ne{left:100%;top:0}
.point.e{left:100%;top:50%}
.point.se{left:100%;top:100%}
.point.s{left:50%;top:100%}
.point.sw{left:0;top:100%}
.point.w{left:0;top:50%}
.point.nw{left:0;top:0}

.connection{
    stroke:#fff;
    fill:none;
    pointer-events:stroke;
    cursor:pointer;
}

.connection.selected{
    stroke:#ffd54f;
}

.connection-hit{
    stroke:transparent;
    fill:none;
    stroke-width:16;
    pointer-events:stroke;
    cursor:pointer;
}

#emptyState{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#8f969e;
    pointer-events:none;
    text-align:center;
}

#timeline{
    flex-shrink:0;
    height:104px;
    padding:8px 12px;
    background:#171a1e;
    border-top:1px solid var(--border);
}

#timelineHeader{
    display:flex;
    align-items:center;
    gap:10px;
    height:24px;
    color:#c7cdd5;
    font-size:12px;
}

#currentTime{
    font-variant-numeric:tabular-nums;
}

#durationText{
    color:#8f969f;
    font-variant-numeric:tabular-nums;
}

#seek{
    width:100%;
    margin:3px 0;
    padding:0;
}

#track{
    height:28px;
    position:relative;
    background:#252a30;
    border-radius:4px;
    overflow:hidden;
}

.trackItem{
    position:absolute;
    top:5px;
    height:18px;
    min-width:3px;
    border-radius:3px;
    cursor:pointer;
}

.trackItem.text{background:#42a5f5}
.trackItem.box{background:#ef5350}
.trackItem.line{background:#b477ff}

#bottom{
    flex-shrink:0;
    min-height:50px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    padding:6px;
    background:#17191d;
    border-top:1px solid var(--border);
}

#message{
    position:fixed;
    z-index:9000;
    left:50%;
    top:64px;
    transform:translateX(-50%);
    display:none;
    max-width:90vw;
    padding:11px 16px;
    border-radius:7px;
    background:#9c3232;
    box-shadow:0 12px 40px #000b;
    white-space:pre-wrap;
}

#message.ok{
    background:#227745;
}

.modal{
    position:fixed;
    inset:0;
    z-index:8000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:15px;
    background:#000b;
}

.modalBox{
    width:min(560px,96vw);
    max-height:92vh;
    overflow:auto;
    padding:18px;
    background:#20242a;
    border:1px solid #505760;
    border-radius:9px;
    box-shadow:0 20px 70px #000d;
}

.modal h2{
    margin:0 0 15px;
    font-size:17px;
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
}

.field{
    display:block;
    color:#c6ccd4;
    font-size:12px;
    margin-bottom:9px;
}

.field input,
.field textarea,
.field select{
    margin-top:4px;
}

.full{
    grid-column:1/-1;
}

.actions{
    display:flex;
    justify-content:flex-end;
    gap:7px;
    margin-top:14px;
}

#contextMenu{
    position:fixed;
    z-index:8500;
    display:none;
    width:200px;
    padding:5px;
    background:#292e34;
    border:1px solid #555d66;
    border-radius:7px;
    box-shadow:0 12px 40px #000c;
}

#contextMenu button{
    display:block;
    width:100%;
    border:0;
    background:transparent;
    text-align:left;
}

#contextMenu button:hover{
    background:#3b424b;
}

#manageList{
    max-height:45vh;
    overflow:auto;
}

.projectItem{
    display:flex;
    align-items:center;
    gap:8px;
    padding:9px;
    margin:6px 0;
    border:1px solid #414851;
    border-radius:6px;
}

.projectItem main{
    min-width:0;
    flex:1;
}

.projectItem strong{
    display:block;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.projectItem small{
    color:#9098a2;
}

.hidden{
    display:none!important;
}

@media(max-width:700px){
    #projectName{
        width:130px;
    }

    #topbar h1{
        display:none;
    }

    #topbar{
        overflow:auto;
    }

    #timeline{
        height:94px;
    }

    #bottom{
        overflow:auto;
        justify-content:flex-start;
    }
}
</style>
</head>

<body>

<div id="app">

<header id="topbar">
    <h1>動画上直接編集</h1>

    <input
        id="projectName"
        maxlength="120"
        placeholder="プロジェクト名"
        disabled
    >

    <button id="openVideo" class="primary">
        動画を読み込む
    </button>

    <button id="startEdit" disabled>
        編集開始
    </button>

    <button id="saveProject" class="success" disabled>
        保存
    </button>

    <button id="exportMp4" disabled>
        MP4書き出し
    </button>

    <button id="manageProjects">
        保存データ
    </button>

    <span class="spacer"></span>
    <span id="status">動画未読込</span>
</header>

<main id="editor">

<section id="stageArea">

    <div id="emptyState">
        <div>
            <div style="font-size:18px;margin-bottom:8px">
                動画を読み込んでください
            </div>
            <div>
                読み込み完了後に「編集開始」が有効になります
            </div>
        </div>
    </div>

    <div id="videoStage" class="hidden">

        <video
            id="video"
            playsinline
            preload="metadata"
        ></video>

        <div id="overlay">

            <svg id="connections"
                 viewBox="0 0 100 100"
                 preserveAspectRatio="none"></svg>

            <div id="objects"></div>

        </div>
    </div>

</section>

<section id="timeline">

    <div id="timelineHeader">
        <span id="currentTime">00:00:00</span>
        <span>/</span>
        <span id="durationText">00:00:00</span>
        <span class="spacer"></span>
        <span id="editModeText">編集待機中</span>
    </div>

    <input
        id="seek"
        type="range"
        min="0"
        max="0"
        step="0.001"
        value="0"
        disabled
    >

    <div id="track"></div>

</section>

<footer id="bottom">

    <button id="addText" disabled>
        ＋テキスト
    </button>

    <button id="addBox" disabled>
        ＋強調枠
    </button>

    <button id="lineMode" disabled>
        ＋接続線
    </button>

    <button id="deleteElement" class="danger" disabled>
        削除
    </button>

    <button id="play" disabled>
        ▶ 再生
    </button>

    <button id="pause" disabled>
        ■ 停止
    </button>

    <button id="toStart" disabled>
        現在位置を開始
    </button>

    <button id="toEnd" disabled>
        現在位置を終了
    </button>

    <span id="selectionLabel">選択なし</span>

</footer>

</main>
</div>

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

<div id="message"></div>

<!-- 書式モーダル -->
<div id="styleModal" class="modal">

    <div class="modalBox">

        <h2>要素の書式</h2>

        <div id="styleTextArea" class="field">
            <label>
                内容
                <textarea id="styleText" rows="4"></textarea>
            </label>
        </div>

        <div class="grid">

            <label class="field">
                文字色
                <input id="styleColor" type="color" value="#ffffff">
            </label>

            <label class="field">
                背景色
                <input id="styleBackground" type="color" value="#000000">
            </label>

            <label class="field">
                文字サイズ
                <input id="styleFontSize" type="number" min="8" max="200">
            </label>

            <label class="field">
                枠色
                <input id="styleBorderColor" type="color" value="#ff3b30">
            </label>

            <label class="field">
                枠幅
                <input id="styleBorderWidth" type="number" min="1" max="30">
            </label>

            <label class="field">
                線幅
                <input id="styleLineWidth" type="number" min="1" max="20">
            </label>

        </div>

        <div class="actions">
            <button id="styleCancel">キャンセル</button>
            <button id="styleApply" class="primary">反映</button>
        </div>

    </div>
</div>

<!-- プロジェクト管理 -->
<div id="manageModal" class="modal">

    <div class="modalBox">

        <h2>保存データ</h2>

        <p style="color:#9da5af;font-size:12px">
            サーバー保存は最大 <?= (int)MAX_SERVER_PROJECTS ?> 件です。
            上限に達した場合はローカル保存へ切り替えます。
            動画本体はブラウザのIndexedDBに保存します。
        </p>

        <div style="display:flex;gap:7px;margin-bottom:12px">
            <button id="importProject">
                JSON読込
            </button>
            <button id="refreshProjects">
                更新
            </button>
            <button id="closeManage">
                閉じる
            </button>
        </div>

        <h3>サーバー</h3>
        <div id="serverProjects">
            読み込み中...
        </div>

        <h3>ローカル</h3>
        <div id="localProjects">
            読み込み中...
        </div>

    </div>
</div>

<!-- プロジェクト名 -->
<div id="nameModal" class="modal">

    <div class="modalBox" style="width:min(430px,94vw)">

        <h2>編集を開始</h2>

        <p style="color:#a4abb4;font-size:12px">
            プロジェクト名を指定してください。
        </p>

        <label class="field">
            プロジェクト名
            <input
                id="nameInput"
                maxlength="120"
                placeholder="例：説明動画_第1版"
            >
        </label>

        <div class="actions">
            <button id="nameCancel">キャンセル</button>
            <button id="nameApply" class="primary">
                編集開始
            </button>
        </div>

    </div>
</div>

<!-- 右クリック -->
<div id="contextMenu">
    <button data-action="style">書式を変更</button>
    <button data-action="duplicate">複製</button>
    <button data-action="delete">削除</button>
</div>

<script>
/*
 * PHPのAPP_VERSIONをJSへ明示的に渡す。
 * PHP定数を直接JSから参照しない。
 */
const APP_VERSION = <?= json_encode(APP_VERSION) ?>;
const MAX_SERVER_PROJECTS = <?= json_encode(MAX_SERVER_PROJECTS) ?>;

const API = location.pathname;

const state = {
    project: null,
    videoBlob: null,
    videoUrl: '',
    selectedId: null,
    selectedType: null,
    contextTarget: null,
    editing: false,
    dirty: false,
    connectMode: false,
    connectSource: null,
    drag: null,
    resize: null,
    db: null,
    localOnly: false,
    saving: false
};

const $ = id => document.getElementById(id);

function uid(prefix = 'id'){
    if(window.crypto?.randomUUID){
        return prefix + '-' + crypto.randomUUID();
    }

    return prefix + '-' +
        Date.now().toString(36) + '-' +
        Math.random().toString(36).slice(2);
}

function clone(value){
    return JSON.parse(JSON.stringify(value));
}

function num(value, fallback = 0){
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
}

function clamp(value, min, max){
    return Math.min(max, Math.max(min, value));
}

function escapeHtml(value){
    return String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}

function nowISO(){
    return new Date().toISOString();
}

function timeText(seconds){
    seconds = Math.max(0, num(seconds));

    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);

    return [
        String(h).padStart(2,'0'),
        String(m).padStart(2,'0'),
        String(s).padStart(2,'0')
    ].join(':');
}

function message(text, ok = false){
    const el = $('message');

    el.textContent = text;
    el.className = ok ? 'ok' : '';
    el.style.display = 'block';

    clearTimeout(message.timer);

    message.timer = setTimeout(() => {
        el.style.display = 'none';
    }, 4000);
}

function status(text){
    $('status').textContent = text;
}

function markDirty(){
    state.dirty = true;
    $('editModeText').textContent = '未保存';
}

function markClean(text = '保存済み'){
    state.dirty = false;
    $('editModeText').textContent = text;
}

function createProject(name = '', videoName = ''){
    return {
        version: APP_VERSION,
        projectId: uid('project'),
        name: name.trim(),
        videoName,
        duration: 0,
        elements: [],
        connections: [],
        createdAt: nowISO(),
        savedAt: '',
        metadata: {
            editor: 'video-overlay-editor',
            version: APP_VERSION
        }
    };
}

function normalizeProject(project){
    const p = clone(project || {});

    p.version = APP_VERSION;
    p.projectId =
        typeof p.projectId === 'string' &&
        p.projectId
            ? p.projectId
            : uid('project');

    p.name =
        String(p.name || '名称未設定').trim();

    p.videoName =
        String(p.videoName || '');

    p.duration =
        Math.max(
            0,
            num(p.duration, 0)
        );

    p.elements =
        Array.isArray(p.elements)
            ? p.elements
            : [];

    p.connections =
        Array.isArray(p.connections)
            ? p.connections
            : [];

    p.elements = p.elements.map(e => normalizeElement(e));
    p.connections = p.connections.map(c => normalizeConnection(c));

    return p;
}

function normalizeElement(element){
    const e = clone(element || {});

    e.id =
        typeof e.id === 'string'
            ? e.id
            : uid('element');

    e.type =
        ['text','box'].includes(e.type)
            ? e.type
            : 'text';

    e.text =
        String(e.text ?? '');

    e.x = clamp(num(e.x,10),0,99);
    e.y = clamp(num(e.y,10),0,99);

    e.w = clamp(num(e.w,20),1,100-e.x);
    e.h = clamp(num(e.h,10),1,100-e.y);

    e.start = Math.max(0,num(e.start,0));
    e.end = Math.max(e.start,num(e.end,0));

    e.style = {
        color: e.style?.color || '#ffffff',
        background: e.style?.background || '#000000',
        fontSize: clamp(num(e.style?.fontSize,32),8,200),
        borderColor: e.style?.borderColor || '#ff3b30',
        borderWidth: clamp(num(e.style?.borderWidth,4),1,30),
        lineWidth: clamp(num(e.style?.lineWidth,4),1,20),
        opacity: clamp(num(e.style?.opacity,1),0,1)
    };

    return e;
}

function normalizeConnection(connection){
    const c = clone(connection || {});

    c.id =
        typeof c.id === 'string'
            ? c.id
            : uid('line');

    c.fromId = String(c.fromId || '');
    c.toId = String(c.toId || '');

    c.fromPoint = c.fromPoint || 'e';
    c.toPoint = c.toPoint || 'w';

    c.start = Math.max(0,num(c.start,0));
    c.end = Math.max(c.start,num(c.end,0));

    c.style = {
        color: c.style?.color || '#ffffff',
        lineWidth: clamp(num(c.style?.lineWidth,4),1,20)
    };

    return c;
}

/* =========================================================
 * API
 * ======================================================= */

async function api(url, options = {}){
    const response = await fetch(
        API + url,
        {
            cache:'no-store',
            ...options
        }
    );

    let data;

    try{
        data = await response.json();
    }catch{
        throw new Error(
            'サーバーから正しいJSON応答を取得できませんでした。'
        );
    }

    if(!response.ok || data.ok === false){
        const error = new Error(
            data.message || 'サーバー処理に失敗しました。'
        );

        error.data = data;
        error.status = response.status;

        throw error;
    }

    return data;
}

/* =========================================================
 * IndexedDB
 * ======================================================= */

function openDB(){
    return new Promise((resolve,reject) => {

        const request = indexedDB.open(
            'video-overlay-editor',
            2
        );

        request.onupgradeneeded = () => {
            const db = request.result;

            if(!db.objectStoreNames.contains('projects')){
                db.createObjectStore(
                    'projects',
                    {keyPath:'projectId'}
                );
            }

            if(!db.objectStoreNames.contains('videos')){
                db.createObjectStore(
                    'videos',
                    {keyPath:'projectId'}
                );
            }
        };

        request.onsuccess = () => {
            state.db = request.result;
            resolve(state.db);
        };

        request.onerror = () => {
            reject(
                request.error ||
                new Error('IndexedDBを開けません。')
            );
        };
    });
}

function idbPut(storeName, value){
    return new Promise((resolve,reject) => {

        const tx =
            state.db.transaction(
                storeName,
                'readwrite'
            );

        tx.objectStore(storeName).put(value);

        tx.oncomplete = () => resolve();
        tx.onerror = () =>
            reject(
                tx.error ||
                new Error('ローカル保存に失敗しました。')
            );
    });
}

function idbGet(storeName, key){
    return new Promise((resolve,reject) => {

        const tx =
            state.db.transaction(
                storeName,
                'readonly'
            );

        const request =
            tx.objectStore(storeName).get(key);

        request.onsuccess = () =>
            resolve(request.result || null);

        request.onerror = () =>
            reject(
                request.error ||
                new Error('ローカルデータを読み込めません。')
            );
    });
}

function idbGetAll(storeName){
    return new Promise((resolve,reject) => {

        const tx =
            state.db.transaction(
                storeName,
                'readonly'
            );

        const request =
            tx.objectStore(storeName).getAll();

        request.onsuccess = () =>
            resolve(request.result || []);

        request.onerror = () =>
            reject(
                request.error ||
                new Error('ローカルデータを取得できません。')
            );
    });
}

function idbDelete(storeName,key){
    return new Promise((resolve,reject) => {

        const tx =
            state.db.transaction(
                storeName,
                'readwrite'
            );

        tx.objectStore(storeName).delete(key);

        tx.oncomplete = () => resolve();
        tx.onerror = () =>
            reject(
                tx.error ||
                new Error('ローカルデータを削除できません。')
            );
    });
}

/* =========================================================
 * 動画読み込み
 * ======================================================= */

function revokeVideoUrl(){
    if(state.videoUrl){
        URL.revokeObjectURL(state.videoUrl);
        state.videoUrl = '';
    }
}

async function loadVideoFile(file){
    if(!file){
        return;
    }

    if(!file.type.startsWith('video/')){
        message('動画ファイルを選択してください。');
        return;
    }

    revokeVideoUrl();

    state.videoBlob = file;
    state.videoUrl = URL.createObjectURL(file);

    const video = $('video');

    video.src = state.videoUrl;
    video.load();

    status('動画の読み込み中...');

    await new Promise((resolve,reject) => {

        const loaded = () => {
            video.removeEventListener(
                'loadedmetadata',
                loaded
            );

            video.removeEventListener(
                'error',
                failed
            );

            resolve();
        };

        const failed = () => {
            video.removeEventListener(
                'loadedmetadata',
                loaded
            );

            video.removeEventListener(
                'error',
                failed
            );

            reject(
                new Error(
                    '動画を読み込めませんでした。'
                )
            );
        };

        video.addEventListener(
            'loadedmetadata',
            loaded,
            {once:true}
        );

        video.addEventListener(
            'error',
            failed,
            {once:true}
        );
    });

    const duration = Number(video.duration);

    if(!Number.isFinite(duration) || duration <= 0){
        throw new Error(
            '動画の長さを取得できませんでした。'
        );
    }

    state.project = createProject(
        '',
        file.name
    );

    state.project.duration = duration;

    $('projectName').disabled = false;
    $('projectName').value = '';

    $('startEdit').disabled = false;

    $('videoStage').classList.remove('hidden');
    $('emptyState').classList.add('hidden');

    $('seek').min = '0';
    $('seek').max = String(duration);
    $('seek').step = '0.001';
    $('seek').value = '0';

    updateTimeUI();
    renderTimeline();

    status(
        `読込完了 / ${file.name} / ${timeText(duration)}`
    );

    message(
        '動画の読み込みが完了しました。「編集開始」を押してください。',
        true
    );
}

/* =========================================================
 * 編集開始
 * ======================================================= */

function openNameModal(){
    if(!state.videoBlob || !state.project){
        message('先に動画を読み込んでください。');
        return;
    }

    $('nameInput').value =
        state.project.name ||
        state.videoBlob.name.replace(/\.[^.]+$/,'') ||
        '動画編集';

    $('nameModal').style.display = 'flex';

    setTimeout(
        () => $('nameInput').focus(),
        30
    );
}

function closeNameModal(){
    $('nameModal').style.display = 'none';
}

function startEditing(){
    if(!state.videoBlob || !state.project){
        message('動画を読み込んでください。');
        return;
    }

    const name =
        $('nameInput').value.trim();

    if(!name){
        message('プロジェクト名を入力してください。');
        $('nameInput').focus();
        return;
    }

    if(name.length > 120){
        message('プロジェクト名は120文字以内です。');
        return;
    }

    state.project.name = name;
    state.editing = true;

    $('projectName').value = name;
    $('projectName').disabled = false;

    enableEditingControls(true);

    $('nameModal').style.display = 'none';

    $('editModeText').textContent = '編集可能';
    status(
        `編集中 / ${name} / ${timeText(state.project.duration)}`
    );

    renderAll();
}

/* =========================================================
 * 編集UI
 * ======================================================= */

function enableEditingControls(enabled){
    const ids = [
        'addText',
        'addBox',
        'lineMode',
        'deleteElement',
        'play',
        'pause',
        'toStart',
        'toEnd',
        'saveProject',
        'exportMp4',
        'seek'
    ];

    ids.forEach(id => {
        $(id).disabled = !enabled;
    });
}

function isElementVisible(element){
    const t = $('video').currentTime;

    return (
        t >= element.start &&
        t <= element.end
    );
}

function findElement(id){
    return state.project?.elements.find(
        e => e.id === id
    ) || null;
}

function findConnection(id){
    return state.project?.connections.find(
        c => c.id === id
    ) || null;
}

function selectObject(id, type){
    state.selectedId = id;
    state.selectedType = type;

    const label =
        type === 'element'
            ? '要素を選択中'
            : '線を選択中';

    $('selectionLabel').textContent = label;

    renderAll();
}

function clearSelection(){
    state.selectedId = null;
    state.selectedType = null;
    state.connectSource = null;
    $('selectionLabel').textContent = '選択なし';

    renderAll();
}

/* =========================================================
 * 要素描画
 * ======================================================= */

function pointPosition(element, point){
    const map = {
        n:[50,0],
        ne:[100,0],
        e:[100,50],
        se:[100,100],
        s:[50,100],
        sw:[0,100],
        w:[0,50],
        nw:[0,0]
    };

    const p = map[point] || map.e;

    return {
        x:element.x + element.w * p[0] / 100,
        y:element.y + element.h * p[1] / 100
    };
}

function applyElementStyle(el, element){
    el.style.left = element.x + '%';
    el.style.top = element.y + '%';
    el.style.width = element.w + '%';
    el.style.height = element.h + '%';

    el.style.opacity =
        String(element.style.opacity);

    if(element.type === 'text'){
        el.style.color =
            element.style.color;

        el.style.background =
            element.style.background;

        el.style.fontSize =
            element.style.fontSize + 'px';

        el.textContent =
            element.text;
    }

    if(element.type === 'box'){
        el.style.border =
            element.style.borderWidth +
            'px solid ' +
            element.style.borderColor;

        el.style.background =
            'transparent';

        el.textContent = '';
    }
}

function renderObjects(){
    const container = $('objects');

    container.innerHTML = '';

    if(!state.project){
        return;
    }

    for(const element of state.project.elements){

        const el =
            document.createElement('div');

        el.className =
            'object ' +
            element.type +
            (
                state.selectedType === 'element' &&
                state.selectedId === element.id
                    ? ' selected'
                    : ''
            );

        el.dataset.id = element.id;
        el.dataset.type = 'element';

        applyElementStyle(el,element);

        if(element.type === 'text'){
            el.title = 'ダブルクリックで直接編集';
        }

        [
            'n','ne','e','se',
            's','sw','w','nw'
        ].forEach(point => {

            const p =
                document.createElement('span');

            p.className =
                'point ' + point;

            p.dataset.point = point;

            el.appendChild(p);
        });

        const handle =
            document.createElement('span');

        handle.className = 'handle';
        el.appendChild(handle);

        el.addEventListener(
            'pointerdown',
            objectPointerDown
        );

        el.addEventListener(
            'dblclick',
            objectDoubleClick
        );

        el.addEventListener(
            'contextmenu',
            event => {
                event.preventDefault();
                selectObject(
                    element.id,
                    'element'
                );
                openContextMenu(
                    event.clientX,
                    event.clientY,
                    element.id,
                    'element'
                );
            }
        );

        /*
         * 現在時刻の表示範囲外なら半透明化。
         * 編集自体は可能。
         */
        if(!isElementVisible(element)){
            el.style.opacity =
                String(
                    Math.min(
                        0.25,
                        element.style.opacity
                    )
                );
        }

        container.appendChild(el);
    }
}

function objectDoubleClick(event){
    const id =
        event.currentTarget.dataset.id;

    const element = findElement(id);

    if(!element || element.type !== 'text'){
        return;
    }

    event.stopPropagation();

    selectObject(id,'element');
    openStyleModal();
}

function objectPointerDown(event){
    if(!state.editing){
        return;
    }

    if(event.button !== 0){
        return;
    }

    const el = event.currentTarget;
    const id = el.dataset.id;
    const element = findElement(id);

    if(!element){
        return;
    }

    /*
     * 接点クリック:
     * 線の始点・終点候補を指定。
     */
    if(event.target.classList.contains('point')){
        const point =
            event.target.dataset.point;

        handleConnectionPoint(
            id,
            point
        );

        event.stopPropagation();
        return;
    }

    selectObject(id,'element');

    const rect =
        $('videoStage').getBoundingClientRect();

    const x =
        (event.clientX - rect.left) /
        rect.width *
        100;

    const y =
        (event.clientY - rect.top) /
        rect.height *
        100;

    if(event.target.classList.contains('handle')){

        state.resize = {
            id,
            startX:event.clientX,
            startY:event.clientY,
            x:element.x,
            y:element.y,
            w:element.w,
            h:element.h,
            stageW:rect.width,
            stageH:rect.height
        };

        el.setPointerCapture(event.pointerId);
        event.stopPropagation();
        return;
    }

    state.drag = {
        id,
        offsetX:x - element.x,
        offsetY:y - element.y,
        stageW:rect.width,
        stageH:rect.height
    };

    el.setPointerCapture(event.pointerId);
}

function handlePointerMove(event){
    if(state.drag){

        const element =
            findElement(state.drag.id);

        if(!element){
            return;
        }

        const rect =
            $('videoStage').getBoundingClientRect();

        const x =
            (event.clientX - rect.left) /
            rect.width *
            100 -
            state.drag.offsetX;

        const y =
            (event.clientY - rect.top) /
            rect.height *
            100 -
            state.drag.offsetY;

        element.x =
            clamp(
                x,
                0,
                100 - element.w
            );

        element.y =
            clamp(
                y,
                0,
                100 - element.h
            );

        markDirty();
        renderAll();
        return;
    }

    if(state.resize){

        const element =
            findElement(state.resize.id);

        if(!element){
            return;
        }

        const dx =
            (event.clientX -
                state.resize.startX) /
            state.resize.stageW *
            100;

        const dy =
            (event.clientY -
                state.resize.startY) /
            state.resize.stageH *
            100;

        element.w =
            clamp(
                state.resize.w + dx,
                1,
                100 - state.resize.x
            );

        element.h =
            clamp(
                state.resize.h + dy,
                1,
                100 - state.resize.y
            );

        markDirty();
        renderAll();
    }
}

function handlePointerUp(){
    if(state.drag || state.resize){
        state.drag = null;
        state.resize = null;
    }
}

/* =========================================================
 * 線
 * ======================================================= */

function handleConnectionPoint(id, point){
    if(!state.connectMode){
        return;
    }

    if(!state.connectSource){

        state.connectSource = {
            id,
            point
        };

        message(
            '接続先の要素の接点をクリックしてください。',
            true
        );

        return;
    }

    if(
        state.connectSource.id === id
    ){
        message(
            '同じ要素には接続できません。'
        );
        return;
    }

    const connection =
        normalizeConnection({
            id:uid('line'),
            fromId:state.connectSource.id,
            toId:id,
            fromPoint:state.connectSource.point,
            toPoint:point,
            start:0,
            end:state.project.duration,
            style:{
                color:'#ffffff',
                lineWidth:4
            }
        });

    state.project.connections.push(
        connection
    );

    state.connectMode = false;
    state.connectSource = null;

    $('lineMode').textContent =
        '＋接続線';

    markDirty();
    renderAll();

    message(
        '接続線を追加しました。',
        true
    );
}

function renderConnections(){
    const svg = $('connections');

    svg.innerHTML = '';

    if(!state.project){
        return;
    }

    for(const connection of state.project.connections){

        const from =
            findElement(connection.fromId);

        const to =
            findElement(connection.toId);

        if(!from || !to){
            continue;
        }

        const a =
            pointPosition(
                from,
                connection.fromPoint
            );

        const b =
            pointPosition(
                to,
                connection.toPoint
            );

        const group =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'g'
            );

        const hit =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'line'
            );

        hit.setAttribute(
            'x1',
            a.x
        );

        hit.setAttribute(
            'y1',
            a.y
        );

        hit.setAttribute(
            'x2',
            b.x
        );

        hit.setAttribute(
            'y2',
            b.y
        );

        hit.setAttribute(
            'class',
            'connection-hit'
        );

        hit.addEventListener(
            'click',
            () => {
                selectObject(
                    connection.id,
                    'connection'
                );
            }
        );

        hit.addEventListener(
            'contextmenu',
            event => {
                event.preventDefault();

                selectObject(
                    connection.id,
                    'connection'
                );

                openContextMenu(
                    event.clientX,
                    event.clientY,
                    connection.id,
                    'connection'
                );
            }
        );

        const line =
            document.createElementNS(
                'http://www.w3.org/2000/svg',
                'line'
            );

        line.setAttribute('x1',a.x);
        line.setAttribute('y1',a.y);
        line.setAttribute('x2',b.x);
        line.setAttribute('y2',b.y);

        line.setAttribute(
            'stroke',
            connection.style.color
        );

        line.setAttribute(
            'stroke-width',
            connection.style.lineWidth
        );

        line.setAttribute(
            'class',
            'connection' +
            (
                state.selectedType === 'connection' &&
                state.selectedId === connection.id
                    ? ' selected'
                    : ''
            )
        );

        line.addEventListener(
            'click',
            () => {
                selectObject(
                    connection.id,
                    'connection'
                );
            }
        );

        group.appendChild(hit);
        group.appendChild(line);
        svg.appendChild(group);
    }
}

/* =========================================================
 * タイムライン
 * ======================================================= */

function updateTimeUI(){
    const video = $('video');

    const current =
        Number.isFinite(video.currentTime)
            ? video.currentTime
            : 0;

    const duration =
        Number.isFinite(video.duration) &&
        video.duration > 0
            ? video.duration
            : state.project?.duration || 0;

    $('currentTime').textContent =
        timeText(current);

    $('durationText').textContent =
        timeText(duration);

    /*
     * ここが重要。
     * maxは必ず動画duration。
     * 固定値・画面幅・要素数では決めない。
     */
    if(duration > 0){
        $('seek').min = '0';
        $('seek').max = String(duration);
        $('seek').step = '0.001';
        $('seek').value =
            String(
                clamp(
                    current,
                    0,
                    duration
                )
            );
    }
}

function renderTimeline(){
    const track = $('track');

    track.innerHTML = '';

    const duration =
        state.project?.duration || 0;

    if(duration <= 0){
        return;
    }

    for(const element of state.project.elements){

        const item =
            document.createElement('span');

        item.className =
            'trackItem ' +
            element.type;

        item.style.left =
            (
                element.start /
                duration *
                100
            ) + '%';

        item.style.width =
            (
                Math.max(
                    0,
                    element.end -
                    element.start
                ) /
                duration *
                100
            ) + '%';

        item.title =
            element.type === 'text'
                ? element.text || 'テキスト'
                : '強調枠';

        item.addEventListener(
            'click',
            () => {
                selectObject(
                    element.id,
                    'element'
                );

                $('video').currentTime =
                    element.start;
            }
        );

        track.appendChild(item);
    }

    for(const connection of state.project.connections){

        const item =
            document.createElement('span');

        item.className =
            'trackItem line';

        item.style.left =
            (
                connection.start /
                duration *
                100
            ) + '%';

        item.style.width =
            (
                Math.max(
                    0,
                    connection.end -
                    connection.start
                ) /
                duration *
                100
            ) + '%';

        item.title = '接続線';

        item.addEventListener(
            'click',
            () => {
                selectObject(
                    connection.id,
                    'connection'
                );

                $('video').currentTime =
                    connection.start;
            }
        );

        track.appendChild(item);
    }
}

/* =========================================================
 * 全体描画
 * ======================================================= */

function renderAll(){
    renderObjects();
    renderConnections();
    renderTimeline();
    updateTimeUI();
}

/* =========================================================
 * 追加
 * ======================================================= */

function addText(){
    if(!state.editing){
        return;
    }

    const current =
        $('video').currentTime;

    const element =
        normalizeElement({
            id:uid('element'),
            type:'text',
            text:'ここをダブルクリックして編集',
            x:10,
            y:10,
            w:35,
            h:12,
            start:current,
            end:state.project.duration,
            style:{
                color:'#ffffff',
                background:'#000000',
                fontSize:28,
                borderColor:'#ff3b30',
                borderWidth:4,
                lineWidth:4,
                opacity:1
            }
        });

    state.project.elements.push(element);

    markDirty();
    selectObject(element.id,'element');
}

function addBox(){
    if(!state.editing){
        return;
    }

    const current =
        $('video').currentTime;

    const element =
        normalizeElement({
            id:uid('element'),
            type:'box',
            text:'',
            x:10,
            y:10,
            w:40,
            h:25,
            start:current,
            end:state.project.duration,
            style:{
                color:'#ffffff',
                background:'#000000',
                fontSize:28,
                borderColor:'#ff3b30',
                borderWidth:5,
                lineWidth:5,
                opacity:1
            }
        });

    state.project.elements.push(element);

    markDirty();
    selectObject(element.id,'element');
}

function toggleLineMode(){
    if(!state.editing){
        return;
    }

    state.connectMode =
        !state.connectMode;

    state.connectSource = null;

    $('lineMode').textContent =
        state.connectMode
            ? '接続先を選択中…'
            : '＋接続線';

    message(
        state.connectMode
            ? '動画上の要素を選択し、表示された接点をクリックしてください。'
            : '接続線モードを終了しました。',
        true
    );
}

/* =========================================================
 * 削除
 * ======================================================= */

function deleteSelected(){
    if(!state.selectedId){
        return;
    }

    if(state.selectedType === 'element'){

        state.project.elements =
            state.project.elements.filter(
                e => e.id !== state.selectedId
            );

        /*
         * 要素削除時、その要素を参照する線も削除。
         */
        state.project.connections =
            state.project.connections.filter(
                c =>
                    c.fromId !== state.selectedId &&
                    c.toId !== state.selectedId
            );
    }

    if(state.selectedType === 'connection'){

        state.project.connections =
            state.project.connections.filter(
                c => c.id !== state.selectedId
            );
    }

    clearSelection();
    markDirty();
}

/* =========================================================
 * 右クリック
 * ======================================================= */

function openContextMenu(x,y,id,type){
    state.contextTarget = {
        id,
        type
    };

    const menu = $('contextMenu');

    menu.style.display = 'block';

    const width = 200;
    const height = 120;

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
}

function closeContextMenu(){
    $('contextMenu').style.display = 'none';
}

$('contextMenu').addEventListener(
    'click',
    event => {

        const action =
            event.target.dataset.action;

        if(!action || !state.contextTarget){
            return;
        }

        const target =
            state.contextTarget;

        closeContextMenu();

        if(action === 'style'){
            selectObject(
                target.id,
                target.type
            );
            openStyleModal();
        }

        if(action === 'duplicate'){
            if(target.type !== 'element'){
                return;
            }

            const original =
                findElement(target.id);

            if(!original){
                return;
            }

            const copy =
                clone(original);

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
            selectObject(copy.id,'element');
        }

        if(action === 'delete'){
            selectObject(
                target.id,
                target.type
            );

            deleteSelected();
        }
    }
);

/* =========================================================
 * 書式モーダル
 * ======================================================= */

function openStyleModal(){
    if(!state.selectedId){
        return;
    }

    const isElement =
        state.selectedType === 'element';

    const target =
        isElement
            ? findElement(state.selectedId)
            : findConnection(state.selectedId);

    if(!target){
        return;
    }

    $('styleTextArea').classList.toggle(
        'hidden',
        !isElement ||
        target.type !== 'text'
    );

    if(isElement){

        $('styleText').value =
            target.text || '';

        $('styleColor').value =
            normalizeColor(
                target.style.color,
                '#ffffff'
            );

        $('styleBackground').value =
            normalizeColor(
                target.style.background,
                '#000000'
            );

        $('styleFontSize').value =
            target.style.fontSize;

        $('styleBorderColor').value =
            normalizeColor(
                target.style.borderColor,
                '#ff3b30'
            );

        $('styleBorderWidth').value =
            target.style.borderWidth;

        $('styleLineWidth').value =
            target.style.lineWidth;
    }

    if(!isElement){

        $('styleColor').value =
            normalizeColor(
                target.style.color,
                '#ffffff'
            );

        $('styleLineWidth').value =
            target.style.lineWidth;

        $('styleBorderColor').value =
            '#ff3b30';

        $('styleBorderWidth').value =
            '1';
    }

    $('styleModal').style.display =
        'flex';
}

function normalizeColor(value,fallback){
    const s = String(value || '');

    return /^#[0-9a-fA-F]{6}$/.test(s)
        ? s
        : fallback;
}

function closeStyleModal(){
    $('styleModal').style.display =
        'none';
}

function applyStyle(){
    if(!state.selectedId){
        return;
    }

    if(state.selectedType === 'element'){

        const element =
            findElement(state.selectedId);

        if(!element){
            return;
        }

        if(element.type === 'text'){
            element.text =
                $('styleText').value;
        }

        element.style.color =
            $('styleColor').value;

        element.style.background =
            $('styleBackground').value;

        element.style.fontSize =
            clamp(
                num(
                    $('styleFontSize').value,
                    32
                ),
                8,
                200
            );

        element.style.borderColor =
            $('styleBorderColor').value;

        element.style.borderWidth =
            clamp(
                num(
                    $('styleBorderWidth').value,
                    4
                ),
                1,
                30
            );

        element.style.lineWidth =
            clamp(
                num(
                    $('styleLineWidth').value,
                    4
                ),
                1,
                20
            );
    }

    if(state.selectedType === 'connection'){

        const connection =
            findConnection(
                state.selectedId
            );

        if(!connection){
            return;
        }

        connection.style.color =
            $('styleColor').value;

        connection.style.lineWidth =
            clamp(
                num(
                    $('styleLineWidth').value,
                    4
                ),
                1,
                20
            );
    }

    markDirty();
    closeStyleModal();
    renderAll();
}

/* =========================================================
 * 表示時間
 * ======================================================= */

function setSelectedStart(){
    if(
        state.selectedType !== 'element' ||
        !state.selectedId
    ){
        return;
    }

    const element =
        findElement(state.selectedId);

    if(!element){
        return;
    }

    element.start =
        clamp(
            $('video').currentTime,
            0,
            element.end
        );

    markDirty();
    renderAll();
}

function setSelectedEnd(){
    if(
        state.selectedType !== 'element' ||
        !state.selectedId
    ){
        return;
    }

    const element =
        findElement(state.selectedId);

    if(!element){
        return;
    }

    element.end =
        clamp(
            $('video').currentTime,
            element.start,
            state.project.duration
        );

    markDirty();
    renderAll();
}

/* =========================================================
 * 保存
 * ======================================================= */

function projectForSave(){
    if(!state.project){
        throw new Error(
            'プロジェクトがありません。'
        );
    }

    const name =
        $('projectName').value.trim();

    if(!name){
        throw new Error(
            'プロジェクト名を入力してください。'
        );
    }

    if(name.length > 120){
        throw new Error(
            'プロジェクト名は120文字以内です。'
        );
    }

    const project =
        clone(state.project);

    project.name = name;

    /*
     * 動画durationを保存直前にも確認。
     */
    if(
        Number.isFinite($('video').duration) &&
        $('video').duration > 0
    ){
        project.duration =
            $('video').duration;
    }

    project.version = APP_VERSION;
    project.savedAt = nowISO();

    return project;
}

async function saveProject(){
    if(!state.editing || state.saving){
        return;
    }

    state.saving = true;

    try{

        const project =
            projectForSave();

        /*
         * まずサーバー保存を試す。
         */
        try{

            const result =
                await api(
                    '?api=save&_=' +
                    Date.now(),
                    {
                        method:'POST',
                        headers:{
                            'Content-Type':
                                'application/json'
                        },
                        body:
                            JSON.stringify(project)
                    }
                );

            project.projectId =
                result.projectId;

            project.savedAt =
                result.savedAt;

            state.project =
                normalizeProject(project);

            /*
             * ローカルにもプロジェクトと動画を保持。
             * サーバーが後日使えなくても復旧できる。
             */
            await idbPut(
                'projects',
                state.project
            );

            if(state.videoBlob){
                await idbPut(
                    'videos',
                    {
                        projectId:
                            state.project.projectId,
                        name:
                            state.project.videoName,
                        blob:
                            state.videoBlob,
                        savedAt:
                            nowISO()
                    }
                );
            }

            state.localOnly = false;
            markClean('サーバー保存済み');

            message(
                'サーバーへ保存しました。',
                true
            );

        }catch(error){

            /*
             * サーバー上限またはサーバー障害時、
             * ローカルへ保存。
             */
            await saveLocal(project);

            state.localOnly = true;

            markClean('ローカル保存済み');

            message(
                error.data?.limit
                    ? 'サーバー保存上限のためローカルへ保存しました。'
                    : 'サーバー保存に失敗したためローカルへ保存しました。',
                true
            );
        }

    }catch(error){

        message(
            error.message ||
            '保存に失敗しました。'
        );

    }finally{
        state.saving = false;
    }
}

async function saveLocal(project){
    await idbPut(
        'projects',
        project
    );

    if(state.videoBlob){

        await idbPut(
            'videos',
            {
                projectId:
                    project.projectId,
                name:
                    project.videoName,
                blob:
                    state.videoBlob,
                savedAt:
                    nowISO()
            }
        );
    }
}

/* =========================================================
 * サーバー/ローカル読込
 * ======================================================= */

async function loadServerProject(id){
    const result =
        await api(
            '?api=load&id=' +
            encodeURIComponent(id) +
            '&_=' +
            Date.now()
        );

    const project =
        normalizeProject(
            result.project
        );

    const video =
        await idbGet(
            'videos',
            project.projectId
        );

    if(!video?.blob){

        message(
            'プロジェクトは読み込めましたが、対応する動画本体がブラウザ内にありません。動画を再度読み込んでください。'
        );

        state.project = project;

        state.videoBlob = null;

        return;
    }

    state.project = project;
    state.videoBlob = video.blob;

    await attachStoredVideo();

    beginLoadedProject();
}

async function loadLocalProject(id){
    const project =
        await idbGet(
            'projects',
            id
        );

    if(!project){
        throw new Error(
            'ローカルプロジェクトが見つかりません。'
        );
    }

    const video =
        await idbGet(
            'videos',
            id
        );

    if(!video?.blob){
        throw new Error(
            'ローカルプロジェクトの動画本体がありません。'
        );
    }

    state.project =
        normalizeProject(project);

    state.videoBlob =
        video.blob;

    await attachStoredVideo();

    beginLoadedProject();
}

async function attachStoredVideo(){
    revokeVideoUrl();

    state.videoUrl =
        URL.createObjectURL(
            state.videoBlob
        );

    const video =
        $('video');

    video.src =
        state.videoUrl;

    video.load();

    await new Promise((resolve,reject) => {

        const ok = () => {
            video.removeEventListener(
                'loadedmetadata',
                ok
            );

            video.removeEventListener(
                'error',
                ng
            );

            resolve();
        };

        const ng = () => {
            reject(
                new Error(
                    '保存されていた動画を読み込めません。'
                )
            );
        };

        video.addEventListener(
            'loadedmetadata',
            ok,
            {once:true}
        );

        video.addEventListener(
            'error',
            ng,
            {once:true}
        );
    });

    const duration =
        Number(video.duration);

    if(
        Number.isFinite(duration) &&
        duration > 0
    ){
        state.project.duration =
            duration;
    }
}

function beginLoadedProject(){
    state.editing = true;

    $('projectName').disabled = false;
    $('projectName').value =
        state.project.name;

    $('videoStage').classList.remove('hidden');
    $('emptyState').classList.add('hidden');

    $('seek').max =
        String(state.project.duration);

    enableEditingControls(true);

    markClean('保存済み');

    status(
        `編集準備完了 / ${state.project.name}`
    );

    renderAll();

    message(
        'プロジェクトを読み込みました。',
        true
    );
}

/* =========================================================
 * JSON書き出し
 * ======================================================= */

function exportProjectJson(){
    if(!state.project){
        return;
    }

    const project =
        projectForSave();

    const payload = {
        type:'video-overlay-project',
        version:APP_VERSION,
        exportedAt:nowISO(),
        project
    };

    const blob =
        new Blob(
            [
                JSON.stringify(
                    payload,
                    null,
                    2
                )
            ],
            {
                type:'application/json'
            }
        );

    downloadBlob(
        blob,
        safeDownloadName(
            project.name ||
            'project'
        ) + '.json'
    );
}

async function importProjectJson(file){
    const text =
        await file.text();

    let data;

    try{
        data =
            JSON.parse(text);
    }catch{
        throw new Error(
            'JSONファイルを読み込めません。'
        );
    }

    const project =
        normalizeProject(
            data.project || data
        );

    if(!project.name){
        project.name =
            '読み込んだプロジェクト';
    }

    /*
     * インポートしたプロジェクトは新しいID。
     */
    project.projectId =
        uid('project');

    await idbPut(
        'projects',
        project
    );

    state.project = project;

    $('projectName').value =
        project.name;

    message(
        'プロジェクト設定をローカルへ読み込みました。動画本体を選択してください。',
        true
    );
}

function safeDownloadName(name){
    return String(name)
        .replace(/[\\/:*?"<>|]/g,'_')
        .slice(0,100) ||
        'video';
}

function downloadBlob(blob,name){
    const url =
        URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href = url;
    a.download = name;

    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(
        () => URL.revokeObjectURL(url),
        1000
    );
}

/* =========================================================
 * MP4書き出し
 * ======================================================= */

async function exportMp4(){
    if(!state.project || !state.videoBlob){
        message(
            '動画を読み込んで編集してから書き出してください。'
        );
        return;
    }

    if(state.dirty){
        const ok =
            confirm(
                '未保存の編集があります。\n保存してからMP4を書き出しますか？'
            );

        if(ok){
            await saveProject();

            if(state.dirty){
                message(
                    '保存に失敗したため書き出しを中止しました。'
                );
                return;
            }
        }
    }

    const button =
        $('exportMp4');

    button.disabled = true;
    button.textContent =
        'MP4生成中...';

    try{

        const project =
            projectForSave();

        const form =
            new FormData();

        form.append(
            'video',
            state.videoBlob,
            state.project.videoName ||
            'input.mp4'
        );

        form.append(
            'project',
            JSON.stringify(project)
        );

        const response =
            await fetch(
                API +
                '?api=export&_=' +
                Date.now(),
                {
                    method:'POST',
                    body:form,
                    cache:'no-store'
                }
            );

        const result =
            await response.json();

        if(!response.ok || !result.ok){
            throw new Error(
                result.message ||
                'MP4書き出しに失敗しました。'
            );
        }

        const a =
            document.createElement('a');

        a.href = result.url;
        a.download =
            result.filename ||
            'edited.mp4';

        document.body.appendChild(a);
        a.click();
        a.remove();

        message(
            '編集済みMP4を書き出しました。',
            true
        );

    }catch(error){

        message(
            error.message ||
            'MP4書き出しに失敗しました。'
        );

    }finally{

        button.disabled =
            !state.editing;

        button.textContent =
            'MP4書き出し';
    }
}

/* =========================================================
 * プロジェクト管理
 * ======================================================= */

async function openManage(){
    $('manageModal').style.display =
        'flex';

    await refreshManage();
}

async function refreshManage(){
    await Promise.all([
        refreshServerProjects(),
        refreshLocalProjects()
    ]);
}

async function refreshServerProjects(){
    const container =
        $('serverProjects');

    container.textContent =
        '読み込み中...';

    try{

        const result =
            await api(
                '?api=list&_=' +
                Date.now()
            );

        container.innerHTML = '';

        if(!result.projects.length){

            container.textContent =
                'サーバー保存はありません。';

            return;
        }

        for(const project of result.projects){

            const row =
                document.createElement('div');

            row.className =
                'projectItem';

            row.innerHTML = `
                <main>
                    <strong>
                        ${escapeHtml(project.name)}
                    </strong>
                    <small>
                        ${escapeHtml(project.videoName)}
                        /
                        ${escapeHtml(project.savedAt)}
                    </small>
                </main>
            `;

            const load =
                document.createElement('button');

            load.textContent =
                '読込';

            load.addEventListener(
                'click',
                async () => {
                    try{
                        await loadServerProject(
                            project.projectId
                        );

                        $('manageModal').style.display =
                            'none';

                    }catch(error){
                        message(
                            error.message
                        );
                    }
                }
            );

            const del =
                document.createElement('button');

            del.textContent =
                '削除';

            del.className =
                'danger';

            del.addEventListener(
                'click',
                async () => {

                    if(
                        !confirm(
                            `「${project.name}」を削除しますか？`
                        )
                    ){
                        return;
                    }

                    try{

                        await api(
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
                                            project.projectId
                                    })
                            }
                        );

                        await idbDelete(
                            'projects',
                            project.projectId
                        );

                        await idbDelete(
                            'videos',
                            project.projectId
                        );

                        await refreshManage();

                        message(
                            '削除しました。',
                            true
                        );

                    }catch(error){
                        message(
                            error.message
                        );
                    }
                }
            );

            row.appendChild(load);
            row.appendChild(del);

            container.appendChild(row);
        }

    }catch(error){

        container.textContent =
            'サーバーへ接続できません。';

        message(
            error.message
        );
    }
}

async function refreshLocalProjects(){
    const container =
        $('localProjects');

    container.innerHTML = '';

    try{

        const projects =
            await idbGetAll(
                'projects'
            );

        if(!projects.length){

            container.textContent =
                'ローカル保存はありません。';

            return;
        }

        projects.sort(
            (a,b) =>
                String(b.savedAt || '')
                    .localeCompare(
                        String(a.savedAt || '')
                    )
        );

        for(const project of projects){

            const row =
                document.createElement('div');

            row.className =
                'projectItem';

            row.innerHTML = `
                <main>
                    <strong>
                        ${escapeHtml(project.name)}
                    </strong>
                    <small>
                        ${escapeHtml(project.videoName)}
                        /
                        ローカル
                    </small>
                </main>
            `;

            const load =
                document.createElement('button');

            load.textContent =
                '読込';

            load.addEventListener(
                'click',
                async () => {
                    try{

                        await loadLocalProject(
                            project.projectId
                        );

                        $('manageModal').style.display =
                            'none';

                    }catch(error){

                        message(
                            error.message
                        );
                    }
                }
            );

            const del =
                document.createElement('button');

            del.textContent =
                '削除';

            del.className =
                'danger';

            del.addEventListener(
                'click',
                async () => {

                    if(
                        !confirm(
                            `ローカルの「${project.name}」を削除しますか？`
                        )
                    ){
                        return;
                    }

                    await idbDelete(
                        'projects',
                        project.projectId
                    );

                    await idbDelete(
                        'videos',
                        project.projectId
                    );

                    await refreshLocalProjects();

                    message(
                        'ローカルデータを削除しました。',
                        true
                    );
                }
            );

            row.appendChild(load);
            row.appendChild(del);

            container.appendChild(row);
        }

    }catch(error){

        container.textContent =
            'IndexedDBを読み込めません。';

        message(
            error.message
        );
    }
}

/* =========================================================
 * 動画操作
 * ======================================================= */

function playVideo(){
    $('video').play().catch(
        error => message(error.message)
    );
}

function pauseVideo(){
    $('video').pause();
}

function seekTo(value){
    const duration =
        state.project?.duration || 0;

    if(duration <= 0){
        return;
    }

    $('video').currentTime =
        clamp(
            num(value),
            0,
            duration
        );

    updateTimeUI();
    renderObjects();
}

$('video').addEventListener(
    'loadedmetadata',
    () => {

        const duration =
            Number($('video').duration);

        if(
            Number.isFinite(duration) &&
            duration > 0
        ){
            if(state.project){
                state.project.duration =
                    duration;
            }

            /*
             * duration取得直後に必ずrangeを同期。
             */
            $('seek').min = '0';
            $('seek').max =
                String(duration);
            $('seek').step = '0.001';

            updateTimeUI();
            renderTimeline();
        }
    }
);

$('video').addEventListener(
    'timeupdate',
    () => {

        updateTimeUI();

        if(state.project){
            renderObjects();
        }
    }
);

$('video').addEventListener(
    'durationchange',
    () => {

        const duration =
            Number($('video').duration);

        if(
            Number.isFinite(duration) &&
            duration > 0
        ){
            if(state.project){
                state.project.duration =
                    duration;
            }

            $('seek').min = '0';
            $('seek').max =
                String(duration);
            $('seek').step = '0.001';

            updateTimeUI();
            renderTimeline();
        }
    }
);

/* =========================================================
 * 動画領域クリック
 * ======================================================= */

$('stageArea').addEventListener(
    'pointerdown',
    event => {

        if(
            event.target !== $('video') &&
            event.target !== $('stageArea')
        ){
            return;
        }

        if(state.connectMode){
            return;
        }

        clearSelection();
    }
);

/* =========================================================
 * グローバル操作
 * ======================================================= */

document.addEventListener(
    'pointermove',
    handlePointerMove
);

document.addEventListener(
    'pointerup',
    handlePointerUp
);

document.addEventListener(
    'click',
    event => {

        if(
            !event.target.closest(
                '#contextMenu'
            )
        ){
            closeContextMenu();
        }
    }
);

document.addEventListener(
    'keydown',
    event => {

        const tag =
            document.activeElement?.tagName;

        const editingInput =
            tag === 'INPUT' ||
            tag === 'TEXTAREA' ||
            tag === 'SELECT';

        if(
            editingInput &&
            event.key !== 'Escape'
        ){
            return;
        }

        if(event.key === 'Delete'){
            if(state.selectedId){
                event.preventDefault();
                deleteSelected();
            }
        }

        if(
            event.key === 'Escape'
        ){
            closeContextMenu();
            closeStyleModal();
            closeNameModal();

            state.connectMode = false;
            state.connectSource = null;

            $('lineMode').textContent =
                '＋接続線';
        }

        if(
            event.key === ' '
        ){
            event.preventDefault();

            if($('video').paused){
                playVideo();
            }else{
                pauseVideo();
            }
        }
    }
);

/* =========================================================
 * イベント
 * ======================================================= */

$('openVideo').addEventListener(
    'click',
    () => $('videoFile').click()
);

$('videoFile').addEventListener(
    'change',
    async event => {

        const file =
            event.target.files?.[0];

        try{
            await loadVideoFile(file);
        }catch(error){
            message(
                error.message
            );
        }

        event.target.value = '';
    }
);

$('startEdit').addEventListener(
    'click',
    openNameModal
);

$('nameApply').addEventListener(
    'click',
    startEditing
);

$('nameCancel').addEventListener(
    'click',
    closeNameModal
);

$('nameInput').addEventListener(
    'keydown',
    event => {
        if(event.key === 'Enter'){
            startEditing();
        }
    }
);

$('projectName').addEventListener(
    'change',
    () => {

        if(!state.project){
            return;
        }

        const value =
            $('projectName').value.trim();

        if(!value){
            message(
                'プロジェクト名を空にできません。'
            );

            $('projectName').value =
                state.project.name;

            return;
        }

        if(value.length > 120){
            message(
                'プロジェクト名は120文字以内です。'
            );

            $('projectName').value =
                state.project.name;

            return;
        }

        state.project.name = value;
        markDirty();
    }
);

$('addText').addEventListener(
    'click',
    addText
);

$('addBox').addEventListener(
    'click',
    addBox
);

$('lineMode').addEventListener(
    'click',
    toggleLineMode
);

$('deleteElement').addEventListener(
    'click',
    deleteSelected
);

$('play').addEventListener(
    'click',
    playVideo
);

$('pause').addEventListener(
    'click',
    pauseVideo
);

$('toStart').addEventListener(
    'click',
    setSelectedStart
);

$('toEnd').addEventListener(
    'click',
    setSelectedEnd
);

$('seek').addEventListener(
    'input',
    event => {
        seekTo(event.target.value);
    }
);

$('saveProject').addEventListener(
    'click',
    saveProject
);

$('exportMp4').addEventListener(
    'click',
    exportMp4
);

$('manageProjects').addEventListener(
    'click',
    openManage
);

$('closeManage').addEventListener(
    'click',
    () => {
        $('manageModal').style.display =
            'none';
    }
);

$('refreshProjects').addEventListener(
    'click',
    refreshManage
);

$('importProject').addEventListener(
    'click',
    () => $('projectFile').click()
);

$('projectFile').addEventListener(
    'change',
    async event => {

        const file =
            event.target.files?.[0];

        try{

            if(file){
                await importProjectJson(file);
            }

        }catch(error){

            message(
                error.message
            );

        }finally{
            event.target.value = '';
        }
    }
);

$('styleApply').addEventListener(
    'click',
    applyStyle
);

$('styleCancel').addEventListener(
    'click',
    closeStyleModal
);

/* =========================================================
 * モーダル背景クリック
 * ======================================================= */

$('styleModal').addEventListener(
    'click',
    event => {
        if(event.target === $('styleModal')){
            closeStyleModal();
        }
    }
);

$('manageModal').addEventListener(
    'click',
    event => {
        if(event.target === $('manageModal')){
            $('manageModal').style.display =
                'none';
        }
    }
);

$('nameModal').addEventListener(
    'click',
    event => {
        if(event.target === $('nameModal')){
            closeNameModal();
        }
    }
);

/* =========================================================
 * 初期化
 * ======================================================= */

async function init(){

    try{

        await openDB();

        const statusData =
            await api(
                '?api=status&_=' +
                Date.now()
            );

        status(
            `動画未読込 / v${APP_VERSION} / ` +
            `サーバー ${statusData.serverCount}/${statusData.serverLimit}`
        );

    }catch(error){

        status(
            'サーバー保存機能を確認できません'
        );

        message(
            error.message
        );
    }

    enableEditingControls(false);

    /*
     * 編集開始だけは動画読み込み後。
     */
    $('startEdit').disabled = true;

    $('seek').disabled = true;
}

window.addEventListener(
    'beforeunload',
    event => {

        if(state.dirty){

            event.preventDefault();

            event.returnValue =
                '';
        }
    }
);

init();
</script>

</body>
</html>

<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// アップロード・メモリ制限を動的に拡張
@ini_set('upload_max_filesize', '512M');
@ini_set('post_max_size', '512M');
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');

$storageDir = __DIR__ . '/storage';
$filesDir   = $storageDir . '/files';
$tmpDir     = $storageDir . '/tmp';
$metaFile   = $storageDir . '/metadata.json';
$reqFile    = $storageDir . '/requests.json';
$retryFile  = $storageDir . '/deletion-retry.json';
$htaccess   = $storageDir . '/.htaccess';

function uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

if (!is_dir($storageDir)) @mkdir($storageDir, 0777, true);
if (!is_dir($filesDir))   @mkdir($filesDir, 0777, true);
if (!is_dir($tmpDir))     @mkdir($tmpDir, 0777, true);
if (!file_exists($htaccess))  @file_put_contents($htaccess, "Require all denied\n");
if (!file_exists($retryFile)) @file_put_contents($retryFile, '[]');
if (!file_exists($reqFile))   @file_put_contents($reqFile, '{}');

function getInitialMeta(): array {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return [
        'schemaVersion' => 1,
        'folders' => [['id' => uuid(), 'parentFolderId' => null, 'name' => 'デフォルト', 'createdAt' => $now, 'updatedAt' => $now]],
        'videos' => [],
        'edits' => [],
        'files' => []
    ];
}

if (!file_exists($metaFile)) {
    @file_put_contents($metaFile, json_encode(getInitialMeta(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ---------------- API ----------------
if (isset($_GET['api']) && $_GET['api'] === 'v1') {
    $action = $_GET['action'] ?? '';

    // ストリーミング・ダウンロード
    if ($action === 'file.stream' || $action === 'file.download') {
        $fid = $_GET['id'] ?? '';
        $raw = @file_get_contents($metaFile);
        $meta = json_decode((string)$raw, true) ?: [];
        $file = null;
        foreach ($meta['files'] ?? [] as $f) {
            if ($f['id'] === $fid) { $file = $f; break; }
        }
        if (!$file) { http_response_code(404); exit('File Not Found'); }
        $path = $storageDir . '/' . $file['storagePath'];
        if (!file_exists($path)) { http_response_code(404); exit('File Missing'); }

        $size = filesize($path);
        $mime = !empty($file['mimeType']) ? $file['mimeType'] : 'video/webm';

        if ($action === 'file.download') {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if (!$ext) $ext = strpos($mime, 'mp4') !== false ? 'mp4' : 'webm';
            $baseName = $_GET['filename'] ?? 'video';
            $baseName = preg_replace('/\.(mp4|webm|mov)$/i', '', $baseName);
            $dlName = rawurlencode($baseName . '.' . $ext);
            header('Content-Type: ' . $mime);
            header("Content-Disposition: attachment; filename=\"{$dlName}\"; filename*=UTF-8''{$dlName}");
            header('Content-Length: ' . $size);
            readfile($path);
            exit;
        }

        $start = 0; $end = $size - 1;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            $start = (int)$m[1];
            if (!empty($m[2])) $end = (int)$m[2];
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . ($end - $start + 1));
        $fp = fopen($path, 'rb');
        fseek($fp, $start);
        $rem = $end - $start + 1;
        while ($rem > 0 && !feof($fp)) {
            $chunk = fread($fp, min(1048576, $rem));
            echo $chunk;
            $rem -= strlen($chunk);
        }
        fclose($fp);
        exit;
    }

    header('Content-Type: application/json; charset=UTF-8');

    // 排他制御付き読み込み
    $lockFp = fopen($metaFile, 'c+');
    if ($lockFp) flock($lockFp, LOCK_EX);

    clearstatcache(true, $metaFile);
    $fileContent = '';
    if ($lockFp) {
        rewind($lockFp);
        $fileContent = stream_get_contents($lockFp);
    }
    $meta = json_decode((string)$fileContent, true);
    if (!is_array($meta) || !isset($meta['schemaVersion'])) $meta = getInitialMeta();
    if (empty($meta['folders'])) $meta['folders'] = getInitialMeta()['folders'];

    $reqs = json_decode((string)@file_get_contents($reqFile), true) ?: [];

    $respond = function(bool $ok, $data, ?string $rid, int $code = 200) use ($lockFp, $reqFile, &$meta, &$reqs) {
        if ($ok) {
            if ($rid) $reqs[$rid] = $data;
            if ($lockFp) {
                rewind($lockFp);
                ftruncate($lockFp, 0);
                fwrite($lockFp, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                fflush($lockFp);
            }
            @file_put_contents($reqFile, json_encode($reqs, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        if ($lockFp) { flock($lockFp, LOCK_UN); fclose($lockFp); }
        http_response_code($code);
        echo json_encode($ok ? ['ok' => true, 'requestId' => $rid, 'data' => $data] : ['ok' => false, 'requestId' => $rid, 'error' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
        $safeFiles = array_map(function($f){ unset($f['storagePath']); return $f; }, $meta['files']);
        $respond(true, ['schemaVersion' => 1, 'folders' => $meta['folders'], 'videos' => $meta['videos'], 'edits' => $meta['edits'], 'files' => $safeFiles], null);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'edit.get') {
        $eid = $_GET['id'] ?? '';
        foreach ($meta['edits'] as $e) { if ($e['id'] === $eid) $respond(true, ['edit' => $e], null); }
        $respond(false, ['code' => 'NOT_FOUND', 'message' => '編集データが見つかりません'], null, 404);
    }

    $rid = null; $payload = [];
    if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
        $raw = json_decode($_POST['metadata'] ?? '{}', true) ?: [];
        $rid = $raw['requestId'] ?? null;
        $payload = $raw['payload'] ?? [];
    } else {
        $raw = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $rid = $raw['requestId'] ?? null;
        $payload = $raw['payload'] ?? [];
    }

    if (!$rid) $respond(false, ['code' => 'INVALID_REQ', 'message' => 'requestIdが必要です'], null, 400);
    if (isset($reqs[$rid])) $respond(true, $reqs[$rid], $rid);

    $now = gmdate('Y-m-d\TH:i:s\Z');

    switch ($action) {
        case 'folder.create':
            $item = ['id' => $payload['id'] ?? uuid(), 'parentFolderId' => $payload['parentFolderId'] ?? null, 'name' => $payload['name'] ?? '新規フォルダ', 'createdAt' => $now, 'updatedAt' => $now];
            $meta['folders'][] = $item;
            $respond(true, ['folder' => $item], $rid);

        case 'folder.rename':
            foreach ($meta['folders'] as &$f) { if ($f['id'] === $payload['id']) { $f['name'] = $payload['name']; $f['updatedAt'] = $now; break; } }
            $respond(true, ['id' => $payload['id']], $rid);

        case 'folder.move':
            $targetId = $payload['id'] ?? '';
            $newParentId = $payload['parentFolderId'] ?? null;
            $chk = $newParentId;
            $isCycle = false;
            while ($chk) {
                if ($chk === $targetId) { $isCycle = true; break; }
                $parent = null;
                foreach ($meta['folders'] as $f) { if ($f['id'] === $chk) { $parent = $f['parentFolderId']; break; } }
                $chk = $parent;
            }
            if ($isCycle) {
                $respond(false, ['code' => 'INVALID_MOVE', 'message' => '自身または子孫フォルダへは移動できません'], $rid, 400);
            }
            foreach ($meta['folders'] as &$f) {
                if ($f['id'] === $targetId) { $f['parentFolderId'] = $newParentId; $f['updatedAt'] = $now; break; }
            }
            $respond(true, ['id' => $targetId], $rid);

        case 'folder.delete':
            $del = [$payload['id']]; $chg = true;
            while ($chg) {
                $chg = false;
                foreach ($meta['folders'] as $f) {
                    if (in_array($f['parentFolderId'], $del, true) && !in_array($f['id'], $del, true)) { $del[] = $f['id']; $chg = true; }
                }
            }
            $meta['folders'] = array_values(array_filter($meta['folders'], fn($f) => !in_array($f['id'], $del, true)));
            $meta['videos'] = array_values(array_filter($meta['videos'], fn($v) => !in_array($v['folderId'], $del, true)));
            $respond(true, ['id' => $payload['id']], $rid);

        case 'video.upload':
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errCode = $_FILES['file']['error'] ?? 'NO_FILE';
                $respond(false, ['code' => 'UPLOAD_ERR', 'message' => "ファイルアップロード失敗 (エラーコード: {$errCode})。PHPのupload_max_filesize設定を確認してください。"], $rid, 400);
            }
            $fid = uuid();
            $mime = $_FILES['file']['type'] ?: 'video/webm';
            $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            if (!$ext) $ext = strpos($mime, 'mp4') !== false ? 'mp4' : 'webm';
            $rel = "files/{$fid}.{$ext}";
            $dest = "{$storageDir}/{$rel}";
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                $respond(false, ['code' => 'SAVE_ERR', 'message' => 'ファイルの保存に失敗しました'], $rid, 500);
            }
            $targetFolderId = $payload['folderId'] ?? null;
            if (!$targetFolderId || !array_filter($meta['folders'], fn($f) => $f['id'] === $targetFolderId)) {
                $targetFolderId = $meta['folders'][0]['id'];
            }
            $meta['files'][] = ['id' => $fid, 'storagePath' => $rel, 'mimeType' => $mime, 'size' => filesize($dest), 'createdAt' => $now];
            $vid = ['id' => $payload['id'] ?? uuid(), 'folderId' => $targetFolderId, 'name' => $payload['name'] ?? '動画', 'originalFileId' => $fid, 'createdAt' => $now, 'updatedAt' => $now];
            $meta['videos'][] = $vid;
            $respond(true, ['video' => $vid], $rid);

        case 'video.rename':
            foreach ($meta['videos'] as &$v) { if ($v['id'] === $payload['id']) { $v['name'] = $payload['name']; $v['updatedAt'] = $now; break; } }
            $respond(true, ['id' => $payload['id']], $rid);

        case 'video.move':
            foreach ($meta['videos'] as &$v) { if ($v['id'] === $payload['id']) { $v['folderId'] = $payload['folderId']; $v['updatedAt'] = $now; break; } }
            $respond(true, ['id' => $payload['id']], $rid);

        case 'video.delete':
            $meta['videos'] = array_values(array_filter($meta['videos'], fn($v) => $v['id'] !== $payload['id']));
            $meta['edits']  = array_values(array_filter($meta['edits'],  fn($e) => $e['videoId'] !== $payload['id']));
            $respond(true, ['id' => $payload['id']], $rid);

        case 'edit.save':
            $eid = $payload['id'] ?? uuid();
            $expVer = (int)($payload['expectedVersion'] ?? 0);
            $idx = -1;
            foreach ($meta['edits'] as $i => $e) { if ($e['id'] === $eid) { $idx = $i; break; } }
            if ($idx >= 0 && $meta['edits'][$idx]['version'] !== $expVer) {
                $respond(false, ['code' => 'CONFLICT', 'message' => 'バージョンが競合しています'], $rid, 409);
            }
            $rfid = null;
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $rfid = uuid();
                $mime = $_FILES['file']['type'] ?: 'video/webm';
                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                if (!$ext) $ext = strpos($mime, 'mp4') !== false ? 'mp4' : 'webm';
                $rel = "files/{$rfid}.{$ext}";
                $dest = "{$storageDir}/{$rel}";
                if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                    $meta['files'][] = ['id' => $rfid, 'storagePath' => $rel, 'mimeType' => $mime, 'size' => filesize($dest), 'createdAt' => $now];
                }
            }
            $entry = [
                'id' => $eid, 'videoId' => $payload['videoId'], 'name' => $payload['name'],
                'version' => $expVer + 1, 'editData' => $payload['editData'], 'renderedFileId' => $rfid,
                'createdAt' => $idx >= 0 ? $meta['edits'][$idx]['createdAt'] : $now, 'updatedAt' => $now
            ];
            if ($idx >= 0) $meta['edits'][$idx] = $entry; else $meta['edits'][] = $entry;
            $respond(true, ['edit' => $entry], $rid);

        case 'edit.rename':
            foreach ($meta['edits'] as &$e) { if ($e['id'] === $payload['id']) { $e['name'] = $payload['name']; $e['updatedAt'] = $now; break; } }
            $respond(true, ['id' => $payload['id']], $rid);

        case 'edit.delete':
            $meta['edits'] = array_values(array_filter($meta['edits'], fn($e) => $e['id'] !== $payload['id']));
            $respond(true, ['id' => $payload['id']], $rid);

        default:
            $respond(false, ['code' => 'UNKNOWN_ACTION', 'message' => '不明な操作です'], $rid, 400);
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画撮影編集</title>
<style>
*{box-sizing:border-box}
body{margin:0;font:12px Meiryo,sans-serif;color:#243247;background:#eef2f7}
button,input,select{font:inherit}
button{height:26px;padding:0 8px;border:1px solid #ccd6e3;border-radius:3px;background:#fff;cursor:pointer;white-space:nowrap}
button:hover{background:#edf4ff}
button:disabled{opacity:.45;cursor:default}
header,.toolbar{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
header{padding:6px 10px;background:#fff;border-bottom:1px solid #ccd6e3}
header strong{margin-right:auto;font-size:13px}
main{height:calc(100dvh - 39px);min-height:430px;padding:6px;display:flex;flex-direction:column;gap:6px}
#stage{flex:1;min-height:140px;background:#172235;display:grid;place-items:center;overflow:hidden;position:relative}
#empty{color:#ccd8e8;line-height:2;text-align:center}
#surface{position:relative}
video{display:block;width:100%;height:100%}
#overlay{position:absolute;inset:0;width:100%;height:100%;touch-action:none}
#split{height:5px;background:#cbd5e1;cursor:ns-resize;border-radius:2px}
#split:hover{background:#94a3b8}
#actions{justify-content:center}
#primary{background:#2563eb;color:#fff;font-weight:bold}
#primary.recording{background:#dc2626}
#status{min-height:16px;color:#607089;font-size:11px}
#timeline{background:#fff;border:1px solid #ccd6e3;display:flex;flex-direction:column;height:170px}
#timeline .toolbar{padding:4px 8px;border-bottom:1px solid #ccd6e3}
#timeline small{margin-left:auto;color:#64748b}
#scroll{flex:1;overflow-y:auto;overflow-x:hidden}
#tracks{position:relative;min-height:130px;touch-action:none}
.tick{position:absolute;top:0;height:18px;border-left:1px solid #dde4ed;font-size:9px;color:#64748b}
.clip{position:absolute;height:24px;padding:3px 5px;border:1px solid #91b5e6;background:#e0ecff;border-radius:3px;overflow:hidden;white-space:nowrap;cursor:move;user-select:none;font-size:11px}
.clip.B{background:#fff1bd}.clip.C{background:#d9f3e6}.clip.D{background:#ffe2e6}.clip.F{background:#eee2ff}
.clip.selected{outline:2px solid #2563eb;outline-offset:-2px}
.edge{position:absolute;top:0;bottom:0;width:5px;background:#0002;cursor:ew-resize}
.edge.start{left:0}.edge.end{right:0}
#playhead{position:absolute;top:0;bottom:0;width:2px;background:#e44972;pointer-events:none;z-index:2}
#handle{position:absolute;left:-5px;top:0;width:12px;height:16px;background:#e44972;clip-path:polygon(0 0,100% 0,100% 65%,50% 100%,0 65%);pointer-events:auto;cursor:ew-resize}
#veil{position:fixed;inset:0;background:#0005;z-index:10}
#library{position:fixed;inset:0 auto 0 0;width:min(440px,100vw);background:#fff;z-index:11;display:flex;flex-direction:column;box-shadow:3px 0 15px #0003}
#library .toolbar{padding:6px;border-bottom:1px solid #ccd6e3}
#tree{flex:1;overflow:auto;padding:6px}
.row{display:flex;align-items:center;gap:5px;min-height:26px;padding:2px 4px;border-radius:2px;user-select:none;cursor:default}
.row:hover{background:#edf4ff}
.row.drop{background:#e0ecff;outline:1px dashed #2563eb}
.name{flex:1;cursor:pointer;overflow-wrap:anywhere}
.name[contenteditable=true]{outline:1px solid #2563eb;background:#fff}
.row time{font-size:10px;color:#64748b}
#menu{position:fixed;z-index:30;background:#fff;border:1px solid #ccd6e3;border-radius:3px;padding:6px;max-height:85vh;max-width:90vw;overflow:auto;box-shadow:0 3px 12px #0003}
#menu>button{display:block;width:100%;text-align:left;border:0;background:transparent;height:24px}
#menu>button:hover{background:#edf4ff}
#menu label{display:flex;justify-content:space-between;gap:10px;padding:3px}
.palette{display:grid;grid-template-columns:repeat(6,20px);gap:3px;padding:4px 0}
.palette button{width:20px;height:20px;padding:0;border:1px solid #ccd6e3}
.menu-close-btn{margin-top:6px;width:100%!important;border:1px solid #ccd6e3!important;border-radius:3px;background:#f1f5f9!important;text-align:center!important;height:26px}
.menu-close-btn:hover{background:#e2e8f0!important}
.editor{position:absolute;white-space:pre-wrap;overflow:auto;padding:3px;outline:2px solid #60a5fa;z-index:3;background:#fff}
dialog{max-width:90vw;border:1px solid #ccd6e3;border-radius:4px;padding:14px}
dialog::backdrop{background:#0005}
dialog label{display:block;margin:10px 0}
.dl-link{display:inline-block;margin:12px 0;padding:6px 12px;background:#2563eb;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold}
.dl-link:hover{background:#1d4ed8}
[hidden]{display:none!important}
#printArea{display:none}
@media print{
@page{size:A4 portrait;margin:10mm}
body>*:not(#printArea){display:none!important}
#printArea{display:block}
.sheet{height:270mm;display:flex;flex-direction:column;gap:6mm;break-after:page}
.sheet:last-child{break-after:auto}
.shot{flex:1;min-height:0;display:flex;flex-direction:column;justify-content:center;align-items:center}
.shot img{max-width:100%;object-fit:contain}
}
</style>
</head>
<body>
<header>
  <strong>動画撮影・編集</strong>
  <button id="list">動画一覧</button>
  <button id="upload">動画をアップする</button>
  <label id="zoomLabel" style="margin-left:auto;display:flex;align-items:center;gap:4px">
    <span>倍率:</span>
    <input id="viewZoom" type="range" min="0.5" max="2.0" step="0.05" value="1.0" style="width:90px">
    <span id="zoomVal" style="min-width:38px;text-align:right">100%</span>
  </label>
</header>
<main>
  <section id="stage">
    <div id="empty">録画開始で共有画面を選択してください。<br>一覧から既存動画を開けます</div>
    <div id="surface" hidden><video id="video" playsinline></video><canvas id="overlay"></canvas></div>
  </section>
  <div id="split"></div>
  <div id="actions" class="toolbar">
    <button id="primary">録画開始</button>
    <button id="begin" hidden>編集開始</button>
    <button id="save" hidden title="編集内容を1本の動画に書き出して保存します">編集動画を書き出し保存</button>
    <button id="download" hidden>ダウンロード</button>
    <button id="pdf" hidden>PDF出力</button>
    <button id="finish" hidden>編集作業終了</button>
  </div>
  <div id="status" role="status"></div>
  <section id="timeline" hidden>
    <div class="toolbar">
      <strong id="clock">0.0 / 0.0 秒</strong>
      <label>タイムスケール <input id="scale" type="range" min="1" max="10" step="0.1" value="10"></label>
      <small>編集再生：画面上プレビュー / 保存：ファイル生成</small>
    </div>
    <div id="scroll"><div id="tracks"><div id="playhead"><div id="handle"></div></div></div></div>
  </section>
</main>
<div id="veil" hidden></div>
<aside id="library" hidden>
  <div class="toolbar">
    <button id="new">新たに画面を録画する</button>
    <button id="upload2">動画をアップする</button>
    <button id="folder">＋フォルダ</button>
  </div>
  <div id="tree"></div>
  <div class="toolbar"><button id="closeList">動画一覧を閉じる</button></div>
</aside>
<input id="file" type="file" accept="video/*" hidden>
<div id="menu" hidden></div>

<dialog id="pdfDialog">
  <h3>PDF出力</h3>
  <label><input type="radio" name="layout" value="1" checked> 1ページに1画面</label>
  <label><input type="radio" name="layout" value="2"> 1ページに2画面</label>
  <button id="print">印刷へ進む</button>
  <button id="cancelPDF">キャンセル</button>
</dialog>

<dialog id="dlDialog">
  <h3 style="margin-top:0">動画ダウンロード</h3>
  <p id="dlMessage">以下のリンクをクリックして動画を保存してください：</p>
  <div><a id="dlActionLink" class="dl-link" target="_blank" rel="noopener noreferrer">動画ファイルをダウンロード</a></div>
  <small style="color:#64748b;display:block;margin-bottom:12px">※保存が始まらない場合は、リンクを右クリックして「名前を付けてリンク先を保存」を選択してください。</small>
  <button id="closeDlDialog">閉じる</button>
</dialog>

<div id="printArea"></div>

<script>
'use strict';
(()=>{
const $=id=>document.getElementById(id);
const video=$('video'),canvas=$('overlay'),ctx=canvas.getContext('2d');
const uid=()=>crypto.randomUUID(),clamp=(x,a,b)=>Math.max(a,Math.min(b,x));
const titles={A:'コメント',B:'強調枠',C:'拡大枠',D:'スキップ',E:'接続線',F:'PDF位置'};
const colors=['#ffffff','#111827','#ef4444','#f97316','#eab308','#22c55e','#14b8a6','#2563eb','#8b5cf6','#ec4899','#64748b','transparent'];
const dashes={solid:[],dash:[10,6],dot:[2,5],chain:[12,5,2,5]};

let data={schemaVersion:1,folders:[],videos:[],edits:[],files:[]};
let currentVideo=null,currentEdit=null,elements=[];
let isEditing=false,isDirty=false,isBusy=false,mediaRec=null,screenStream=null;
let selectedIds=new Set(),connectingOwner=null,editorNode=null,renderedPlayback=false;
let displayZoom=1.0,recTimer=null,recStart=0;
let lastTlStart=-1,lastTlSpan=-1,isSkipping=false;
let renderAbortController=null;

const message=msg=>{$('status').textContent=msg;};
const duration=()=>Number.isFinite(video.duration)?video.duration:0;
const isLocked=()=>isBusy||!!screenStream;
const findElem=id=>elements.find(e=>e.id===id);

function getFileMeta(fid){ return data.files.find(f=>f.id===fid)||null; }

function triggerDownload(fid, baseName){
  if(!fid)return;
  const fm=getFileMeta(fid);
  let ext='webm';
  if(fm&&fm.mimeType){
    if(fm.mimeType.includes('mp4'))ext='mp4';
    else if(fm.mimeType.includes('webm'))ext='webm';
  }
  const cleanName=baseName.replace(/\.[^.]+$/,'')+'.'+ext;
  const url=`index.php?api=v1&action=file.download&id=${fid}&filename=${encodeURIComponent(cleanName)}`;
  const a=$('dlActionLink');
  a.href=url;
  a.download=cleanName;
  a.textContent=`${cleanName} をダウンロード`;
  $('dlDialog').showModal();
}
$('closeDlDialog').onclick=()=>$('dlDialog').close();

// 安全なAPI通信ラッパー
async function api(act,payload={},blob=null,fn='file.mp4'){
  const rid=uid();
  let res;
  try {
    if(blob){
      const fd=new FormData();
      fd.append('metadata',JSON.stringify({requestId:rid,payload}));
      fd.append('file',blob,fn);
      res=await fetch(`index.php?api=v1&action=${act}`,{method:'POST',body:fd});
    }else{
      res=await fetch(`index.php?api=v1&action=${act}`,{
        method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({requestId:rid,payload})
      });
    }
  } catch(netErr) {
    throw new Error('ネットワークエラー: サーバーに接続できませんでした');
  }

  const text=await res.text();
  let json;
  try {
    json=JSON.parse(text);
  } catch(parseErr) {
    throw new Error(`サーバーエラー (${res.status}): ${text.substring(0, 100)}`);
  }

  if(!json.ok)throw new Error(json.error?.message||'APIエラー');
  return json.data;
}

async function loadData(){
  const res=await fetch('index.php?api=v1&action=list');
  const json=await res.json();
  if(!json.ok)throw new Error('一覧取得失敗');
  data=json.data;
  renderTree();
}

function commitText(){
  if(!editorNode)return;
  editorNode.item.text=editorNode.innerText;editorNode.remove();editorNode=null;isDirty=true;
}

function fit(){
  const r=$('stage').getBoundingClientRect();
  const vw=video.videoWidth||1280,vh=video.videoHeight||720;
  const s=Math.min(r.width/vw,r.height/vh)*displayZoom;
  const w=Math.round(vw*s),h=Math.round(vh*s);
  $('surface').style.width=w+'px';$('surface').style.height=h+'px';
  if(canvas.width!==vw||canvas.height!==vh){canvas.width=vw;canvas.height=vh;}
}

function updateUI(){
  const hasMedia=!!video.srcObject||(!!video.src&&video.src!==window.location.href);
  $('surface').hidden=!hasMedia;
  $('empty').hidden=hasMedia;
  $('timeline').hidden=!isEditing;
  $('begin').hidden=!currentVideo||isEditing||!!screenStream;
  $('save').hidden=$('finish').hidden=!isEditing;
  $('download').hidden=$('pdf').hidden=!currentVideo||!!screenStream;

  if(screenStream){
    $('primary').textContent='録画停止';
    $('primary').classList.add('recording');
  }else if(isEditing){
    $('primary').textContent=video.paused?'編集再生':'編集再生停止';
    $('primary').classList.remove('recording');
  }else{
    $('primary').textContent='録画開始';
    $('primary').classList.remove('recording');
  }
  $('primary').disabled=isBusy&&!renderAbortController;
  document.querySelectorAll('header button,#actions button,#library button').forEach(b=>{
    if(b!==$('primary')&&b!==$('save'))b.disabled=isLocked();
  });
  fit();tracks();paint();
}

function clearState(){
  commitText();video.pause();video.removeAttribute('src');video.load();
  currentVideo=null;currentEdit=null;elements=[];selectedIds.clear();
  isEditing=false;isDirty=false;renderedPlayback=false;connectingOwner=null;
}

function seek(t){
  return new Promise(res=>{
    let tid=null;
    const done=()=>{
      clearTimeout(tid);
      video.removeEventListener('seeked',done);
      res();
    };
    tid=setTimeout(done, 1500);
    video.addEventListener('seeked',done);
    video.currentTime=clamp(t,0,duration());
  });
}

// 編集中の離脱確認（保存せずに破棄して続行可能）
async function leavePrompt(){
  if(isLocked())return false;
  commitText();
  if(!isDirty)return true;
  const ans=confirm('未保存の編集内容があります。\n\n[OK] 編集動画を書き出し保存して終了\n[キャンセル] 保存せずに編集内容を破棄して終了');
  if(ans){
    const ok=await saveEdit();
    return ok;
  }
  // キャンセル時は変更を破棄して続行
  isDirty=false;
  return true;
}

async function loadTarget(vidObj,editObj=null,asEdit=false){
  if(!await leavePrompt())return;
  isBusy=true;updateUI();
  try{
    clearState();
    currentVideo=vidObj;currentEdit=editObj;isEditing=asEdit;
    if(editObj){
      const res=await fetch(`index.php?api=v1&action=edit.get&id=${editObj.id}`).then(r=>r.json());
      if(res.ok&&res.data.edit?.editData?.elements)elements=res.data.edit.editData.elements;
    }
    const targetFid=(!asEdit&&editObj?.renderedFileId)?editObj.renderedFileId:vidObj.originalFileId;
    renderedPlayback=(targetFid===editObj?.renderedFileId);

    await new Promise((res,rej)=>{
      let tid=null;
      const onData=()=>{
        clearTimeout(tid);
        video.removeEventListener('loadeddata',onData);
        video.removeEventListener('error',onErr);
        res();
      };
      const onErr=()=>{
        clearTimeout(tid);
        video.removeEventListener('loadeddata',onData);
        video.removeEventListener('error',onErr);
        rej(new Error('動画の読み込みに失敗しました'));
      };
      tid=setTimeout(onData, 4000);
      video.addEventListener('loadeddata',onData);
      video.addEventListener('error',onErr);
      video.src=`index.php?api=v1&action=file.stream&id=${targetFid}`;
      video.load();
    });

    await seek(0);
    $('library').hidden=$('veil').hidden=true;
    message(`${vidObj.name} ${editObj?'/ '+editObj.name:'(元動画)'} を読み込みました`);
  }catch(e){
    message(e.message);
  }finally{
    isBusy=false;updateUI();
  }
}

// 幾何計算
function getPort(e,p){
  if(e.shape==='ellipse')return{x:e.x+e.w/2+Math.cos(p.angle||0)*e.w/2,y:e.y+e.h/2+Math.sin(p.angle||0)*e.h/2};
  const r=p.ratio??0.5;
  if(p.side==='left')return{x:e.x,y:e.y+e.h*r};
  if(p.side==='right')return{x:e.x+e.w,y:e.y+e.h*r};
  if(p.side==='top')return{x:e.x+e.w*r,y:e.y};
  return{x:e.x+e.w*r,y:e.y+e.h};
}
function getNearPort(e,q){
  if(e.shape==='ellipse')return{angle:Math.atan2((q.y-e.y-e.h/2)/(e.h/2),(q.x-e.x-e.w/2)/(e.w/2))};
  return[{side:'left',ratio:clamp((q.y-e.y)/e.h,.1,.9)},{side:'right',ratio:clamp((q.y-e.y)/e.h,.1,.9)},
         {side:'top',ratio:clamp((q.x-e.x)/e.w,.1,.9)},{side:'bottom',ratio:clamp((q.x-e.x)/e.w,.1,.9)}]
  .sort((a,b)=>Math.hypot(getPort(e,a).x-q.x,getPort(e,a).y-q.y)-Math.hypot(getPort(e,b).x-q.x,getPort(e,b).y-q.y))[0];
}
function getNorm(e,p){
  if(e.shape==='ellipse'){
    const x=Math.cos(p.angle||0),y=Math.sin(p.angle||0),l=Math.hypot(x,y)||1;
    return{x:x/l,y:y/l};
  }
  return{left:{x:-1,y:0},right:{x:1,y:0},top:{x:0,y:-1},bottom:{x:0,y:1}}[p.side||'bottom'];
}
function getEnds(e){
  const o=findElem(e.ownerId),c=findElem(e.commentId);if(!o||!c)return null;
  const p=getPort(o,e.from),q=getPort(c,e.to),no=getNorm(o,e.from),nc=getNorm(c,e.to);
  return[p,{x:p.x+20*no.x,y:p.y+20*no.y},{x:q.x+20*nc.x,y:q.y+20*nc.y},q];
}
function isVis(e,t){
  const o=e.type==='E'?findElem(e.ownerId):e;
  return o&&!['D','F'].includes(e.type)&&t>=o.start&&t<o.end;
}

const drawOrder={C:0,B:1,A:2,E:3};
function paint(targetCtx=ctx,t=video.currentTime,isOut=false){
  if(!isOut)targetCtx.clearRect(0,0,canvas.width,canvas.height);
  if(!isOut&&(!isEditing||renderedPlayback))return;

  elements.filter(e=>isVis(e,t)).sort((a,b)=>drawOrder[a.type]-drawOrder[b.type]).forEach(e=>{
    targetCtx.save();
    targetCtx.strokeStyle=e.stroke||'#ef4444';targetCtx.lineWidth=e.width||3;
    targetCtx.setLineDash(dashes[e.dash]||[]);
    if(e.type==='E'){
      const ends=getEnds(e);
      if(ends){
        const[p,u,v,q]=ends;
        targetCtx.beginPath();targetCtx.moveTo(p.x,p.y);targetCtx.lineTo(u.x,u.y);
        if(e.line==='orthogonal'){targetCtx.lineTo((u.x+v.x)/2,u.y);targetCtx.lineTo((u.x+v.x)/2,v.y);}
        else if(e.line==='wave'){
          const dx=v.x-u.x,dy=v.y-u.y,len=Math.hypot(dx,dy)||1;
          for(let i=1;i<=60;i++){
            const r=i/60,s=14*Math.sin(2*Math.PI*r);
            targetCtx.lineTo(u.x+dx*r-dy/len*s,u.y+dy*r+dx/len*s);
          }
        }
        targetCtx.lineTo(v.x,v.y);targetCtx.lineTo(q.x,q.y);targetCtx.stroke();
        targetCtx.setLineDash([]);
        if(e.terminal==='arrow'){
          const a=Math.atan2(q.y-v.y,q.x-v.x);
          targetCtx.beginPath();
          targetCtx.moveTo(q.x-10*Math.cos(a-.5),q.y-10*Math.sin(a-.5));
          targetCtx.lineTo(q.x,q.y);
          targetCtx.lineTo(q.x-10*Math.cos(a+.5),q.y-10*Math.sin(a+.5));
          targetCtx.stroke();
        }else if(e.terminal==='circle'){
          targetCtx.beginPath();targetCtx.arc(q.x,q.y,5,0,Math.PI*2);targetCtx.stroke();
        }
        if(!isOut){
          [p,q].forEach((pt,i)=>{
            targetCtx.fillStyle=i?'#22c55e':'#38bdf8';
            targetCtx.beginPath();targetCtx.arc(pt.x,pt.y,selectedIds.has(e.id)?6:4,0,Math.PI*2);targetCtx.fill();
          });
        }
      }
    }else{
      targetCtx.beginPath();
      if(e.shape==='ellipse')targetCtx.ellipse(e.x+e.w/2,e.y+e.h/2,e.w/2,e.h/2,0,0,Math.PI*2);
      else if(e.shape==='round')targetCtx.roundRect(e.x,e.y,e.w,e.h,12);
      else targetCtx.rect(e.x,e.y,e.w,e.h);

      if(e.type==='C'&&video.readyState>=2){
        targetCtx.save();targetCtx.clip();
        const z=e.zoom||2;
        targetCtx.drawImage(video,e.x+(e.w-e.w/z)/2,e.y+(e.h-e.h/z)/2,e.w/z,e.h/z,e.x,e.y,e.w,e.h);
        targetCtx.restore();
      }
      if(e.background&&e.background!=='transparent'){targetCtx.fillStyle=e.background;targetCtx.fill();}
      targetCtx.stroke();

      if(e.type==='A'){
        targetCtx.save();targetCtx.beginPath();targetCtx.rect(e.x,e.y,e.w,e.h);targetCtx.clip();
        targetCtx.fillStyle=e.color||'#ffffff';
        targetCtx.font=`${e.size||20}px ${e.font==='mincho'?'serif':'sans-serif'}`;
        let ly=e.y+(e.size||20)+4;
        for(const line of (e.text||'').split('\n')){
          let row='';
          for(const ch of line){
            if(row&&targetCtx.measureText(row+ch).width>e.w-10){
              targetCtx.fillText(row,e.x+5,ly);ly+=(e.size||20)*1.3;row='';
            }
            row+=ch;
          }
          targetCtx.fillText(row,e.x+5,ly);ly+=(e.size||20)*1.3;
        }
        targetCtx.restore();
      }
      if(!isOut&&selectedIds.has(e.id)){
        targetCtx.strokeStyle='#60a5fa';targetCtx.setLineDash([4,3]);targetCtx.strokeRect(e.x,e.y,e.w,e.h);
        targetCtx.fillStyle='#60a5fa';targetCtx.fillRect(e.x+e.w-8,e.y+e.h-8,16,16);
      }
    }
    targetCtx.restore();
  });
}

function hitElem(q){
  return elements.filter(e=>isVis(e,video.currentTime)).sort((a,b)=>drawOrder[b.type]-drawOrder[a.type]).find(e=>{
    if(e.type==='E'){
      const ends=getEnds(e);
      return ends&&[ends[0],ends[3]].some(p=>Math.hypot(p.x-q.x,p.y-q.y)<16);
    }
    return q.x>=e.x&&q.x<=e.x+e.w&&q.y>=e.y&&q.y<=e.y+e.h;
  });
}
function getPt(ev){
  const r=canvas.getBoundingClientRect();
  return{x:(ev.clientX-r.left)*canvas.width/r.width,y:(ev.clientY-r.top)*canvas.height/r.height};
}
function bindDrag(ev,onMove){
  ev.preventDefault();
  const onEnd=()=>{window.removeEventListener('pointermove',onMove);window.removeEventListener('pointerup',onEnd);};
  window.addEventListener('pointermove',onMove);window.addEventListener('pointerup',onEnd);
}

function getRange(){
  const s=+($('scale').value);
  const d=Math.max(duration(),0.1);
  const minSpan=Math.min(d,Math.max(1,d*0.1));
  const span=minSpan+(d-minSpan)*((s-1)/9);
  const start=clamp(video.currentTime-span/2,0,Math.max(0,d-span));
  const width=Math.max(1,$('tracks').clientWidth-20);
  return{span,start,width};
}

function getTimeAt(ev){
  const r=getRange(),rect=$('tracks').getBoundingClientRect();
  return clamp(r.start+(ev.clientX-rect.left-10)/r.width*r.span,0,duration());
}

function tracks(){
  if(!isEditing)return;
  const root=$('tracks'),r=getRange();
  root.querySelectorAll('.clip,.tick').forEach(n=>n.remove());

  for(let i=0;i<=8;i++){
    const tick=document.createElement('span');tick.className='tick';
    tick.style.left=(10+r.width*i/8)+'px';
    const sec=r.start+(r.span*i/8);
    tick.textContent=sec.toFixed(1)+'s';
    if(i===8)tick.style.transform='translateX(-100%)';
    root.append(tick);
  }

  const rows=[];
  elements.filter(e=>e.type!=='E').sort((a,b)=>(a.start??a.time)-(b.start??b.time)).forEach(e=>{
    const st=e.start??e.time,en=e.end??e.time;
    let ri=rows.findIndex(end=>end<=st);if(ri<0)ri=rows.length;
    rows[ri]=Math.max(en,st+0.001);
    if(en<r.start||st>r.start+r.span)return;

    const clip=document.createElement('div');
    clip.className=`clip ${e.type} ${selectedIds.has(e.id)?'selected':''}`;
    clip.setAttribute('data-element-id',e.id);
    clip.textContent=e.type==='A'?(e.text||titles.A):titles[e.type];

    const left=10+(Math.max(st,r.start)-r.start)/r.span*r.width;
    const w=e.type==='F'?8:Math.max(4,(Math.min(en,r.start+r.span)-Math.max(st,r.start))/r.span*r.width);
    Object.assign(clip.style,{left:left+'px',top:(22+ri*27)+'px',width:w+'px'});

    if(e.type!=='F'){
      const es=document.createElement('span');es.className='edge start';
      const ee=document.createElement('span');ee.className='edge end';
      clip.append(es,ee);
    }
    clip.oncontextmenu=ev=>{ev.stopPropagation();openElemMenu(ev,e,true);};
    clip.onpointerdown=ev=>{
      if(ev.button!==0||isLocked())return;
      ev.stopPropagation();video.pause();
      if(!ev.ctrlKey&&!ev.shiftKey)selectedIds.clear();
      selectedIds.add(e.id);
      const isS=ev.target.classList.contains('start'),isE=ev.target.classList.contains('end');
      const ox=ev.clientX,os=e.start,oe=e.end;
      bindDrag(ev,mev=>{
        const dt=(mev.clientX-ox)/r.width*r.span;
        if(isS)e.start=clamp(os+dt,0,e.end-0.1);
        else if(isE)e.end=clamp(oe+dt,e.start+0.1,duration());
        else{const len=oe-os;e.start=clamp(os+dt,0,duration()-len);e.end=e.start+len;}
        isDirty=true;tracks();paint();
      });
      tracks();paint();
    };
    root.append(clip);
  });
  root.style.height=Math.max(130,rows.length*27+30)+'px';
  updateClock();
}

function updateClock(){
  $('clock').textContent=`${video.currentTime.toFixed(1)} / ${duration().toFixed(1)} 秒`;
  const r=getRange();
  $('playhead').style.left=(10+(video.currentTime-r.start)/r.span*r.width)+'px';
}

function openMenu(ev,items){
  ev.preventDefault();
  const m=$('menu');m.replaceChildren();
  items.forEach(([lbl,act])=>{
    const b=document.createElement('button');b.textContent=lbl;
    b.onclick=()=>{m.hidden=true;act();};m.append(b);
  });
  m.hidden=false;
  m.style.left=clamp(ev.clientX,5,window.innerWidth-m.offsetWidth-10)+'px';
  m.style.top=clamp(ev.clientY,5,window.innerHeight-m.offsetHeight-10)+'px';
}

function openElemMenu(ev,item,isTl){
  if(!isEditing)return;
  commitText();video.pause();
  if(item&&!selectedIds.has(item.id)){selectedIds.clear();selectedIds.add(item.id);}
  const t=isTl?getTimeAt(ev):video.currentTime,q=isTl?{x:canvas.width*0.1,y:canvas.height*0.1}:getPt(ev);
  const items=[];

  const addE=type=>{
    const r=getRange();
    const defaultLen=Math.max(0.5, Math.min(2.0, r.span / 8));
    const ne={id:uid(),type,start:t,end:type==='F'?t:Math.min(duration(),t+defaultLen)};
    if(type==='F')ne.time=t;
    if(['A','B','C'].includes(type)){
      Object.assign(ne,{x:clamp(q.x,0,canvas.width-200),y:clamp(q.y,0,canvas.height-100),w:200,h:80,stroke:'#ef4444',width:3,dash:'solid',shape:'rect',background:'transparent'});
      if(type==='A')Object.assign(ne,{text:'コメント',size:20,font:'gothic',color:'#ffffff'});
      if(type==='C')ne.zoom=2;
    }
    elements.push(ne);selectedIds=new Set([ne.id]);isDirty=true;tracks();paint();
  };

  if(isTl){
    ['A','B','C','D','F'].forEach(k=>items.push([titles[k]+'追加',()=>addE(k)]));
    if(selectedIds.size>1){
      items.push(['開始位置を合わせる',()=>{
        const list=elements.filter(e=>selectedIds.has(e.id)&&e.type!=='E');
        const minS=Math.min(...list.map(e=>e.start??e.time));
        list.forEach(e=>{if(e.end)e.end=minS+(e.end-e.start);e.start=minS;if(e.time)e.time=minS;});
        isDirty=true;tracks();paint();
      }]);
    }
  }else{
    ['A','B','C'].forEach(k=>items.push([titles[k]+'追加',()=>addE(k)]));
    items.push(['接続線追加',()=>{connectingOwner='start';message('接続開始側の要素をクリックしてください');}]);
  }
  if(item){
    if(!['D','F'].includes(item.type))items.push(['書式変更',()=>openFormatMenu(ev,item)]);
    items.push(['削除',()=>{
      elements=elements.filter(e=>!selectedIds.has(e.id)&&!selectedIds.has(e.ownerId)&&!selectedIds.has(e.commentId));
      selectedIds.clear();isDirty=true;tracks();paint();
    }]);
  }
  openMenu(ev,items);
}

function openFormatMenu(ev,item){
  const m=$('menu');m.replaceChildren();
  const fields=[
    ...(item.type==='A'?[['文字サイズ','size',[12,16,20,24,32,40].map(v=>[v,v+'px'])],['フォント','font',[['gothic','ゴシック'],['mincho','明朝']]]]:[]),
    ['線の太さ','width',[[1,'細'],[3,'中'],[5,'太'],[8,'極太']]],
    ['線種','dash',[['solid','実線'],['dash','破線'],['dot','点線'],['chain','一点鎖線']]],
    ...(item.type==='E'?[['線形','line',[['straight','直線'],['orthogonal','折れ線'],['wave','波線']]],['終端','terminal',[['none','なし'],['arrow','矢印'],['circle','丸']]]]:[['図形','shape',[['rect','四角'],['round','角丸四角'],['ellipse','楕円']]]]),
    ...(item.type==='C'?[['倍率','zoom',[[1.5,'1.5倍'],[2,'2倍'],[3,'3倍'],[4,'4倍']]]]:[])
  ];
  fields.forEach(([lbl,key,opts])=>{
    const l=document.createElement('label'),s=document.createElement('select');l.textContent=lbl;
    opts.forEach(([v,t])=>s.add(new Option(t,v)));s.value=item[key];
    s.onchange=()=>{item[key]=['size','width','zoom'].includes(key)?+s.value:s.value;isDirty=true;paint();};
    l.append(s);m.append(l);
  });
  const colorTarget=document.createElement('select');
  colorTarget.add(new Option('線色','stroke'));
  if(item.type!=='E')colorTarget.add(new Option('背景色','background'));
  if(item.type==='A')colorTarget.add(new Option('文字色','color'));
  m.append(colorTarget);

  const pal=document.createElement('div');pal.className='palette';
  colors.forEach(col=>{
    const b=document.createElement('button');
    b.style.background=col==='transparent'?'repeating-linear-gradient(45deg,#fff 0 3px,#ccc 3px 6px)':col;
    b.onclick=()=>{item[colorTarget.value]=col;isDirty=true;paint();};pal.append(b);
  });
  m.append(pal);

  const closeBtn=document.createElement('button');
  closeBtn.textContent='閉じる';
  closeBtn.className='menu-close-btn';
  closeBtn.onclick=()=>m.hidden=true;
  m.append(closeBtn);

  m.hidden=false;
  m.style.left=clamp(ev.clientX,5,window.innerWidth-m.offsetWidth-10)+'px';
  m.style.top=clamp(ev.clientY,5,window.innerHeight-m.offsetHeight-10)+'px';
}

// キャンバス操作
canvas.onpointerdown=ev=>{
  if(ev.button!==0||!isEditing||isLocked())return;
  commitText();video.pause();
  const q=getPt(ev),item=hitElem(q);

  if(connectingOwner){
    if(!item||!['A','B','C'].includes(item.type))return;
    if(connectingOwner==='start'){connectingOwner=item.id;message('接続先の要素を選択してください');return;}
    const o1=findElem(connectingOwner),o2=item;
    if(!o1||(o1.type==='A')===(o2.type==='A')){message('接続はコメントと枠の間で行ってください');return;}
    const comment=o1.type==='A'?o1:o2,owner=o1.type==='A'?o2:o1;
    const fp=getNearPort(owner,{x:comment.x+comment.w/2,y:comment.y+comment.h/2});
    const lineElem={
      id:uid(),type:'E',ownerId:owner.id,commentId:comment.id,
      from:fp,to:getNearPort(comment,getPort(owner,fp)),
      stroke:owner.stroke||'#ef4444',width:owner.width||3,dash:owner.dash||'solid',
      line:'orthogonal',terminal:'arrow'
    };
    elements.push(lineElem);selectedIds=new Set([lineElem.id]);connectingOwner=null;isDirty=true;paint();return;
  }

  if(!ev.ctrlKey&&!ev.shiftKey)selectedIds.clear();
  if(item)selectedIds.add(item.id);
  tracks();paint();
  if(!item)return;

  const old=structuredClone(item);
  const isRes=item.type!=='E'&&q.x>item.x+item.w-18&&q.y>item.y+item.h-18;
  const ends=item.type==='E'?getEnds(item):null;
  const endTarget=ends&&Math.hypot(ends[0].x-q.x,ends[0].y-q.y)<16?'from':'to';

  bindDrag(ev,mev=>{
    const p=getPt(mev);
    if(item.type==='E'){
      const target=findElem(endTarget==='from'?item.ownerId:item.commentId);
      item[endTarget]=getNearPort(target,p);
    }else if(isRes){
      item.w=clamp(old.w+p.x-q.x,30,canvas.width-item.x);
      item.h=clamp(old.h+p.y-q.y,20,canvas.height-item.y);
    }else{
      item.x=clamp(old.x+p.x-q.x,0,canvas.width-item.w);
      item.y=clamp(old.y+p.y-q.y,0,canvas.height-item.h);
    }
    isDirty=true;paint();
  });
};

canvas.ondblclick=ev=>{
  if(!isEditing||isLocked())return;
  const item=hitElem(getPt(ev));
  if(item?.type!=='A')return;
  commitText();video.pause();
  const sr=canvas.clientWidth/canvas.width;
  const div=document.createElement('div');
  div.className='editor';div.contentEditable='true';div.innerText=item.text;
  Object.assign(div.style,{
    left:item.x*sr+'px',top:item.y*sr+'px',width:item.w*sr+'px',height:item.h*sr+'px',
    fontSize:(item.size||20)*sr+'px',fontFamily:item.font==='mincho'?'serif':'sans-serif',
    color:item.color,background:item.background!=='transparent'?item.background:'#fff'
  });
  $('surface').append(div);div.focus();
  editorNode=div;editorNode.item=item;div.onblur=commitText;
};

// スキップ区間
function getSkipTime(t){
  for(let i=0;i<elements.length;i++){
    const sk=elements.find(e=>e.type==='D'&&t>=e.start&&t<e.end);
    if(!sk)break;t=sk.end;
  }
  return Math.min(t,duration());
}

// 編集動画の保存確定
async function saveEdit(){
  if(renderAbortController){
    renderAbortController.aborted=true;
    message('書き出しを中断しています...');
    return false;
  }

  commitText();
  if(!currentVideo||isLocked())return false;
  isBusy=true;
  renderAbortController={aborted:false};
  updateUI();

  $('save').hidden=false;
  $('save').disabled=false;
  $('save').textContent='書き出しを中断';
  $('save').style.background='#f97316';
  $('save').style.color='#fff';

  const origTime=video.currentTime;
  const origMuted=video.muted;
  const origRate=video.playbackRate;

  try{
    message('編集内容を動画ファイルに書き出しています... [書き出しを中断] で中止できます');
    const oc=document.createElement('canvas');oc.width=canvas.width;oc.height=canvas.height;
    const octx=oc.getContext('2d');

    await seek(getSkipTime(0));
    const st=oc.captureStream(30);
    const mime=MediaRecorder.isTypeSupported('video/mp4;codecs=avc1')?'video/mp4':
               MediaRecorder.isTypeSupported('video/mp4')?'video/mp4':
               MediaRecorder.isTypeSupported('video/webm;codecs=vp9')?'video/webm;codecs=vp9':'video/webm';
    const mr=new MediaRecorder(st,{mimeType:mime});
    const chunks=[];
    mr.ondataavailable=e=>{if(e.data&&e.data.size>0)chunks.push(e.data);};

    const fin=new Promise((resolve,reject)=>{
      mr.onstop=()=>resolve(new Blob(chunks,{type:mime}));
      mr.onerror=e=>reject(e.error||new Error('書き出しエラー'));
    });

    mr.start(200);
    video.playbackRate=1;video.muted=false;await video.play();

    let animId=null;
    const loop=async()=>{
      if(renderAbortController.aborted){
        cancelAnimationFrame(animId);
        video.pause();
        if(mr.state==='recording')mr.stop();
        return;
      }
      if(video.paused||video.ended||video.currentTime>=duration()){
        cancelAnimationFrame(animId);
        if(mr.state==='recording')mr.stop();
        return;
      }
      const nxt=getSkipTime(video.currentTime);
      if(nxt>video.currentTime){
        video.pause();await seek(nxt);
        if(nxt>=duration()){if(mr.state==='recording')mr.stop();return;}
        if(!renderAbortController.aborted)await video.play();
      }
      octx.drawImage(video,0,0,oc.width,oc.height);
      paint(octx,video.currentTime,true);
      message(`書き出し中: ${video.currentTime.toFixed(1)}s / ${duration().toFixed(1)}s (中止するには「書き出しを中断」)`);
      animId=requestAnimationFrame(loop);
    };
    animId=requestAnimationFrame(loop);
    const outBlob=await fin;

    if(renderAbortController.aborted){
      message('書き出しを中断しました');
      return false;
    }

    message('サーバーへ動画を保存しています...');
    const eid=currentEdit?.id||uid();
    const ext=mime.includes('mp4')?'mp4':'webm';
    const res=await api('edit.save',{
      id:eid,videoId:currentVideo.id,
      name:currentEdit?.name||`編集 ${data.edits.filter(e=>e.videoId===currentVideo.id).length+1}`,
      expectedVersion:currentEdit?.version||0,
      editData:{schemaVersion:1,coordinateSystem:'source-pixels',sourceWidth:canvas.width,sourceHeight:canvas.height,elements}
    },outBlob,`rendered.${ext}`);

    currentEdit=res.edit;isDirty=false;
    await loadData();
    message('編集動画の書き出し・保存が完了しました');
    return true;
  }catch(e){
    if(!renderAbortController.aborted){
      message('書き出しに失敗しました: '+e.message);
    }
    return false;
  }finally{
    renderAbortController=null;
    video.pause();
    video.muted=origMuted;
    video.playbackRate=origRate;
    await seek(origTime);

    $('save').textContent='編集動画を書き出し保存';
    $('save').style.background='';
    $('save').style.color='';
    isBusy=false;
    updateUI();
  }
}

// 画面録画
async function toggleRecord(){
  if(mediaRec&&mediaRec.state==='recording'){
    message('録画を停止しています...');
    mediaRec.stop();
    return;
  }
  if(!await leavePrompt())return;
  isBusy=true;updateUI();
  try{
    screenStream=await navigator.mediaDevices.getDisplayMedia({video:true,audio:true});
    clearState();
    video.srcObject=screenStream;video.muted=true;
    
    await new Promise(res=>{
      video.onloadedmetadata=()=>{
        video.play();
        fit();
        res();
      };
    });

    const chunks=[];
    const mime=MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')?'video/webm;codecs=vp9,opus':
               MediaRecorder.isTypeSupported('video/mp4')?'video/mp4':'video/webm';

    mediaRec=new MediaRecorder(screenStream,{mimeType:mime});
    mediaRec.ondataavailable=e=>{if(e.data&&e.data.size>0)chunks.push(e.data);};

    const doneProm=new Promise((res,rej)=>{
      mediaRec.onstop=()=>{
        const ext=mime.includes('mp4')?'mp4':'webm';
        res({blob:new Blob(chunks,{type:mime}),ext});
      };
      mediaRec.onerror=e=>rej(e.error||new Error('録画エラー'));
    });

    screenStream.getVideoTracks()[0].onended=()=>{
      if(mediaRec&&mediaRec.state==='recording')mediaRec.stop();
    };

    mediaRec.start(500);
    recStart=Date.now();
    recTimer=setInterval(()=>{
      const sec=Math.floor((Date.now()-recStart)/1000);
      message(`録画中 (${sec}秒) 「録画停止」をクリックで保存します。`);
    },1000);

    isBusy=false;updateUI();
    message('録画中 (0秒) 「録画停止」で保存します。');

    const {blob,ext}=await doneProm;
    clearInterval(recTimer);recTimer=null;

    screenStream.getTracks().forEach(t=>t.stop());
    screenStream=null;mediaRec=null;video.srcObject=null;video.muted=false;

    isBusy=true;updateUI();
    message(`動画データを送信しています (${(blob.size/1024/1024).toFixed(2)} MB)...`);

    const defFolderId=data.folders[0]?.id;
    const name=`録画 ${new Date().toLocaleString('ja-JP')}`;
    const res=await api('video.upload',{id:uid(),folderId:defFolderId,name},blob,`recording.${ext}`);

    await loadData();
    // 録画完了後、先頭フレームを表示して待機
    await loadTarget(res.video,null,false);
    await seek(0);
    message('録画を保存しました。先頭を表示しています。「編集開始」で編集できます。');
  }catch(e){
    clearInterval(recTimer);recTimer=null;
    message('録画が中断または失敗しました: '+e.message);
  }finally{
    if(screenStream)screenStream.getTracks().forEach(t=>t.stop());
    screenStream=null;mediaRec=null;video.srcObject=null;video.muted=false;
    isBusy=false;
    updateUI();
  }
}

// 動画一覧ツリー
function renderTree(){
  const root=$('tree');root.replaceChildren();

  const rootDropZone=document.createElement('div');
  rootDropZone.className='row';
  rootDropZone.style.border='1px dashed #ccd6e3';
  rootDropZone.style.margin='4px 0';
  rootDropZone.style.justifyContent='center';
  rootDropZone.textContent='📂 ルート階層（ここにドロップで最上位へ移動）';
  rootDropZone.ondragover=ev=>{ev.preventDefault();rootDropZone.classList.add('drop');};
  rootDropZone.ondragleave=()=>rootDropZone.classList.remove('drop');
  rootDropZone.ondrop=async ev=>{
    ev.preventDefault();rootDropZone.classList.remove('drop');
    const transferData=ev.dataTransfer.getData('text/plain');
    if(!transferData)return;
    const [type,id]=transferData.split(':');
    if(type==='folder'){
      await api('folder.move',{id,parentFolderId:null});
      await loadData();
    }
  };
  root.append(rootDropZone);

  function makeRow(type,id,lbl,depth,timeText='',onClick=null,onMenu=null,canDrag=false){
    const row=document.createElement('div');
    row.className='row';row.style.paddingLeft=(depth*14+4)+'px';
    row.setAttribute('data-entity-type',type);row.setAttribute('data-entity-id',id);
    const span=document.createElement('span');span.className='name';span.textContent=lbl;
    if(onClick)span.onclick=()=>{if(!span.isContentEditable)onClick();};
    row.append(span);

    if(timeText){
      const t=document.createElement('time');t.textContent=new Date(timeText).toLocaleString('ja-JP');
      row.append(t);
    }
    if(onMenu)row.oncontextmenu=ev=>onMenu(ev,span);
    if(canDrag){
      row.draggable=true;
      row.ondragstart=ev=>{
        ev.dataTransfer.setData('text/plain',`${type}:${id}`);
      };
    }
    return row;
  }

  function renderFolder(f,depth){
    const fRow=makeRow('folder',f.id,'📁 '+f.name,depth,f.createdAt,null,(ev,span)=>openMenu(ev,[
      ['子フォルダ追加',async()=>{await api('folder.create',{name:'新規フォルダ',parentFolderId:f.id});await loadData();}],
      ['最上位階層へ移動',async()=>{await api('folder.move',{id:f.id,parentFolderId:null});await loadData();}],
      ['名前変更',()=>{
        span.contentEditable='true';span.focus();
        span.onblur=async()=>{span.contentEditable='false';await api('folder.rename',{id:f.id,name:span.textContent.trim()||f.name});await loadData();};
      }],
      ['フォルダ削除',async()=>{
        if(confirm(`フォルダ「${f.name}」を削除しますか？配下のデータもすべて削除されます。`)){
          await api('folder.delete',{id:f.id});await loadData();
        }
      }]
    ]),true);

    fRow.ondragover=ev=>{ev.preventDefault();fRow.classList.add('drop');};
    fRow.ondragleave=()=>fRow.classList.remove('drop');
    fRow.ondrop=async ev=>{
      ev.preventDefault();fRow.classList.remove('drop');
      const transferData=ev.dataTransfer.getData('text/plain');
      if(!transferData)return;
      const [type,id]=transferData.split(':');
      if(type==='video'){
        await api('video.move',{id,folderId:f.id});
        await loadData();
      }else if(type==='folder'){
        if(id===f.id)return;
        try{
          await api('folder.move',{id,parentFolderId:f.id});
          await loadData();
        }catch(e){
          message(e.message);
        }
      }
    };
    root.append(fRow);

    data.folders.filter(cf=>cf.parentFolderId===f.id).forEach(cf=>renderFolder(cf,depth+1));

    data.videos.filter(v=>v.folderId===f.id).forEach(v=>{
      const vRow=makeRow('video',v.id,'🎬 '+v.name,depth+1,v.createdAt,()=>loadTarget(v,null,false),(ev,span)=>openMenu(ev,[
        ['元動画を再生',()=>loadTarget(v,null,false)],
        ['新規編集を開始',()=>loadTarget(v,null,true)],
        ['名前変更',()=>{
          span.contentEditable='true';span.focus();
          span.onblur=async()=>{span.contentEditable='false';await api('video.rename',{id:v.id,name:span.textContent.trim()||v.name});await loadData();};
        }],
        ['ダウンロード',()=>triggerDownload(v.originalFileId, v.name)],
        ['PDF出力',async()=>{
          await loadTarget(v,null,true);
          openPdfDialog();
        }],
        ['削除',async()=>{
          if(confirm(`動画「${v.name}」を削除しますか？`)){await api('video.delete',{id:v.id});await loadData();}
        }]
      ]),true);
      root.append(vRow);

      const origRow=makeRow('file',v.originalFileId,' └ 📹 元動画',depth+2,v.createdAt,()=>loadTarget(v,null,false),(ev)=>openMenu(ev,[
        ['ダウンロード',()=>triggerDownload(v.originalFileId, v.name+'_元動画')],
        ['PDF出力',async()=>{
          await loadTarget(v,null,true);
          openPdfDialog();
        }]
      ]));
      root.append(origRow);

      data.edits.filter(ed=>ed.videoId===v.id).forEach(ed=>{
        const edRow=makeRow('edit',ed.id,' └ ✂ '+ed.name,depth+2,ed.createdAt,()=>loadTarget(v,ed,true),(ev,span)=>openMenu(ev,[
          ['編集を再開',()=>loadTarget(v,ed,true)],
          ['保存済み編集動画を再生',async()=>{
            await loadTarget(v,ed,false);
            if(video.paused)await video.play();
          }],
          ['名前変更',()=>{
            span.contentEditable='true';span.focus();
            span.onblur=async()=>{span.contentEditable='false';await api('edit.rename',{id:ed.id,name:span.textContent.trim()||ed.name});await loadData();};
          }],
          ['ダウンロード',()=>triggerDownload(ed.renderedFileId||v.originalFileId, v.name+'_'+ed.name)],
          ['PDF出力',async()=>{
            await loadTarget(v,ed,true);
            openPdfDialog();
          }],
          ['削除',async()=>{
            if(confirm(`編集「${ed.name}」を削除します
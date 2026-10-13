<?php
/**
 * 便利ツール｜動画撮影編集 POC
 * Apache + PHP 1ファイル統合実装 (index.php)
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// 1. サーバサイド処理（API・動画配信・ストレージ管理）
// -----------------------------------------------------------------------------
const SCHEMA_VERSION = 1;
$storageDir = __DIR__ . '/storage';
$filesDir = $storageDir . '/files';
$tmpDir = $storageDir . '/tmp';
$metaFile = $storageDir . '/metadata.json';
$reqFile = $storageDir . '/requests.json';
$retryFile = $storageDir . '/deletion-retry.json';
$htaccessFile = $storageDir . '/.htaccess';

function initStorage(): void {
    global $storageDir, $filesDir, $tmpDir, $metaFile, $reqFile, $retryFile, $htaccessFile;
    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true)) {
        http_response_code(500);
        exit('storage ディレクトリの作成に失敗しました。');
    }
    if (!is_dir($filesDir) && !mkdir($filesDir, 0755, true)) {
        http_response_code(500);
        exit('files ディレクトリの作成に失敗しました。');
    }
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true)) {
        http_response_code(500);
        exit('tmp ディレクトリの作成に失敗しました。');
    }
    if (!file_exists($htaccessFile)) {
        file_put_contents($htaccessFile, "Require all denied\n");
    }
    if (!file_exists($reqFile)) {
        file_put_contents($reqFile, json_encode([], JSON_UNESCAPED_UNICODE));
    }
    if (!file_exists($retryFile)) {
        file_put_contents($retryFile, json_encode([], JSON_UNESCAPED_UNICODE));
    }
    if (!file_exists($metaFile)) {
        $defaultFolder = [
            'id' => uuidv4(),
            'parentFolderId' => null,
            'name' => 'デフォルトフォルダ',
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c')
        ];
        $initialData = [
            'schemaVersion' => SCHEMA_VERSION,
            'folders' => [$defaultFolder],
            'videos' => [],
            'edits' => [],
            'files' => []
        ];
        file_put_contents($metaFile, json_encode($initialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function jsonResponse(bool $ok, ?string $requestId, mixed $dataOrError, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    if ($ok) {
        echo json_encode(['ok' => true, 'requestId' => $requestId, 'data' => $dataOrError], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'requestId' => $requestId, 'error' => $dataOrError], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function withLock(callable $fn) {
    global $storageDir;
    $lockFile = $storageDir . '/.lock';
    $fp = fopen($lockFile, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        throw new RuntimeException('排他制御の取得に失敗しました。');
    }
    try {
        return $fn();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function getMetadata(): array {
    global $metaFile;
    $content = file_get_contents($metaFile);
    $data = json_decode($content, true);
    if (!is_array($data) || ($data['schemaVersion'] ?? 0) !== SCHEMA_VERSION) {
        throw new RuntimeException('未対応のデータスキーマです。');
    }
    return $data;
}

function saveMetadata(array $data): void {
    global $metaFile, $tmpDir;
    $tmpPath = $tmpDir . '/meta_' . uuidv4() . '.json';
    file_put_contents($tmpPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if (!rename($tmpPath, $metaFile)) {
        @unlink($tmpPath);
        throw new RuntimeException('メタデータの更新に失敗しました。');
    }
}

function checkDuplicateRequest(string $requestId, array $payload): ?array {
    global $reqFile;
    $reqs = json_decode(file_get_contents($reqFile), true) ?: [];
    if (isset($reqs[$requestId])) {
        $saved = $reqs[$requestId];
        if ($saved['payloadHash'] !== md5(json_encode($payload))) {
            jsonResponse(false, $requestId, ['code' => 'REQUEST_PAYLOAD_MISMATCH', 'message' => '同一requestIdで異なる内容が送信されました。'], 400);
        }
        return $saved['result'];
    }
    return null;
}

function recordRequestSuccess(string $requestId, array $payload, mixed $result): void {
    global $reqFile, $tmpDir;
    $reqs = json_decode(file_get_contents($reqFile), true) ?: [];
    $reqs[$requestId] = [
        'payloadHash' => md5(json_encode($payload)),
        'result' => $result,
        'time' => gmdate('c')
    ];
    if (count($reqs) > 1000) {
        $reqs = array_slice($reqs, -500, null, true);
    }
    $tmpPath = $tmpDir . '/req_' . uuidv4() . '.json';
    file_put_contents($tmpPath, json_encode($reqs, JSON_UNESCAPED_UNICODE));
    rename($tmpPath, $reqFile);
}

function streamFile(string $fileId, bool $isDownload = false): void {
    global $storageDir;
    $meta = getMetadata();
    $fileInfo = null;
    foreach ($meta['files'] as $f) {
        if ($f['id'] === $fileId) {
            $fileInfo = $f;
            break;
        }
    }
    if (!$fileInfo) {
        http_response_code(404);
        exit('File Not Found');
    }

    $path = $storageDir . '/' . $fileInfo['storagePath'];
    if (!file_exists($path)) {
        http_response_code(404);
        exit('File Not Found On Storage');
    }

    $size = filesize($path);
    $mime = $fileInfo['mimeType'] ?: 'video/mp4';

    if ($isDownload) {
        $filename = 'download_' . $fileId . ($mime === 'video/webm' ? '.webm' : '.mp4');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $size);
        readfile($path);
        exit;
    }

    // HTTP Range Stream
    $start = 0;
    $end = $size - 1;
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');

    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            $start = (int)$matches[1];
            if (!empty($matches[2])) {
                $end = (int)$matches[2];
            }
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
        header('Content-Length: ' . ($end - $start + 1));
    } else {
        header('Content-Length: ' . $size);
    }

    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $chunkSize = 1024 * 64;
    $bytesToRead = $end - $start + 1;
    while (!feof($fp) && $bytesToRead > 0) {
        $read = ($bytesToRead > $chunkSize) ? $chunkSize : $bytesToRead;
        $data = fread($fp, $read);
        echo $data;
        flush();
        $bytesToRead -= strlen($data);
    }
    fclose($fp);
    exit;
}

// APIルーティング
if (isset($_GET['api']) && $_GET['api'] === 'v1') {
    initStorage();
    $action = $_GET['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'list') {
            try {
                $meta = getMetadata();
                // storagePathを非公開にして応答
                $cleanFiles = array_map(function($f) {
                    return ['id' => $f['id'], 'mimeType' => $f['mimeType'], 'size' => $f['size'], 'createdAt' => $f['createdAt']];
                }, $meta['files']);
                jsonResponse(true, null, [
                    'schemaVersion' => $meta['schemaVersion'],
                    'folders' => $meta['folders'],
                    'videos' => $meta['videos'],
                    'edits' => array_map(function($e) {
                        return [
                            'id' => $e['id'],
                            'videoId' => $e['videoId'],
                            'name' => $e['name'],
                            'version' => $e['version'],
                            'renderedFileId' => $e['renderedFileId'],
                            'createdAt' => $e['createdAt'],
                            'updatedAt' => $e['updatedAt']
                        ];
                    }, $meta['edits']),
                    'files' => $cleanFiles
                ]);
            } catch (Throwable $e) {
                jsonResponse(false, null, ['code' => 'ERROR', 'message' => $e->getMessage()], 500);
            }
        } elseif ($action === 'edit.get') {
            $id = $_GET['id'] ?? '';
            try {
                $meta = getMetadata();
                foreach ($meta['edits'] as $e) {
                    if ($e['id'] === $id) {
                        jsonResponse(true, null, [
                            'id' => $e['id'],
                            'videoId' => $e['videoId'],
                            'name' => $e['name'],
                            'version' => $e['version'],
                            'editData' => $e['editData'],
                            'renderedFileId' => $e['renderedFileId']
                        ]);
                    }
                }
                jsonResponse(false, null, ['code' => 'NOT_FOUND', 'message' => '編集データが見つかりません。'], 404);
            } catch (Throwable $e) {
                jsonResponse(false, null, ['code' => 'ERROR', 'message' => $e->getMessage()], 500);
            }
        } elseif ($action === 'file.stream') {
            streamFile($_GET['id'] ?? '', false);
        } elseif ($action === 'file.download') {
            streamFile($_GET['id'] ?? '', true);
        }
        jsonResponse(false, null, ['code' => 'UNKNOWN_ACTION', 'message' => '無効な操作です。'], 400);
    }

    // POST更新
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawPost = file_get_contents('php://input');
        $jsonData = json_decode($rawPost, true);
        $isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');

        if ($isMultipart) {
            $metadataStr = $_POST['metadata'] ?? '{}';
            $parsedMeta = json_decode($metadataStr, true) ?: [];
            $requestId = $parsedMeta['requestId'] ?? null;
            $payload = $parsedMeta['payload'] ?? [];
        } else {
            $requestId = $jsonData['requestId'] ?? null;
            $payload = $jsonData['payload'] ?? [];
        }

        if (!$requestId) {
            jsonResponse(false, null, ['code' => 'MISSING_REQUEST_ID', 'message' => 'requestIdは必須です。'], 400);
        }

        try {
            $result = withLock(function() use ($action, $requestId, $payload, $isMultipart, $filesDir, $tmpDir) {
                if ($cached = checkDuplicateRequest($requestId, $payload)) {
                    return $cached;
                }

                $meta = getMetadata();
                $now = gmdate('c');

                if ($action === 'folder.create') {
                    $id = $payload['id'] ?? uuidv4();
                    $folder = [
                        'id' => $id,
                        'parentFolderId' => $payload['parentFolderId'] ?? null,
                        'name' => trim($payload['name'] ?? '新規フォルダ'),
                        'createdAt' => $now,
                        'updatedAt' => $now
                    ];
                    $meta['folders'][] = $folder;
                    saveMetadata($meta);
                    $res = ['folder' => $folder];
                    recordRequestSuccess($requestId, $payload, $res);
                    return $res;
                }

                if ($action === 'folder.rename') {
                    $id = $payload['id'] ?? '';
                    foreach ($meta['folders'] as &$f) {
                        if ($f['id'] === $id) {
                            $f['name'] = trim($payload['name'] ?? $f['name']);
                            $f['updatedAt'] = $now;
                            saveMetadata($meta);
                            $res = ['folder' => $f];
                            recordRequestSuccess($requestId, $payload, $res);
                            return $res;
                        }
                    }
                    throw new RuntimeException('対象フォルダが見つかりません。');
                }

                if ($action === 'folder.delete') {
                    $id = $payload['id'] ?? '';
                    $toDeleteFolderIds = [$id];
                    // 再帰検索
                    $findChildren = function($pId) use (&$findChildren, &$toDeleteFolderIds, $meta) {
                        foreach ($meta['folders'] as $f) {
                            if ($f['parentFolderId'] === $pId) {
                                $toDeleteFolderIds[] = $f['id'];
                                $findChildren($f['id']);
                            }
                        }
                    };
                    $findChildren($id);

                    // 該当フォルダ内の動画・編集・ファイルを検出
                    $delVideos = array_filter($meta['videos'], fn($v) => in_array($v['folderId'], $toDeleteFolderIds));
                    $delVideoIds = array_column($delVideos, 'id');
                    $delEdits = array_filter($meta['edits'], fn($e) => in_array($e['videoId'], $delVideoIds));
                    $delFileIds = array_merge(array_column($delVideos, 'originalFileId'), array_column($delEdits, 'renderedFileId'));
                    $delFileIds = array_filter(array_unique($delFileIds));

                    // 削除実行
                    $meta['folders'] = array_values(array_filter($meta['folders'], fn($f) => !in_array($f['id'], $toDeleteFolderIds)));
                    $meta['videos'] = array_values(array_filter($meta['videos'], fn($v) => !in_array($v['id'], $delVideoIds)));
                    $meta['edits'] = array_values(array_filter($meta['edits'], fn($e) => !in_array($e['videoId'], $delVideoIds)));

                    // ファイル削除
                    foreach ($delFileIds as $fId) {
                        foreach ($meta['files'] as $k => $f) {
                            if ($f['id'] === $fId) {
                                @unlink(__DIR__ . '/storage/' . $f['storagePath']);
                                unset($meta['files'][$k]);
                            }
                        }
                    }
                    $meta['files'] = array_values($meta['files']);
                    if (empty($meta['folders'])) {
                        $meta['folders'][] = ['id' => uuidv4(), 'parentFolderId' => null, 'name' => 'デフォルトフォルダ', 'createdAt' => $now, 'updatedAt' => $now];
                    }
                    saveMetadata($meta);
                    $res = ['deletedFolderIds' => $toDeleteFolderIds];
                    recordRequestSuccess($requestId, $payload, $res);
                    return $res;
                }

                if ($action === 'video.upload') {
                    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('動画ファイルのアップロードに失敗しました。');
                    }
                    $fileId = uuidv4();
                    $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION) ?: 'mp4';
                    $targetRel = 'files/' . $fileId . '.' . $ext;
                    $targetAbs = __DIR__ . '/storage/' . $targetRel;

                    if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetAbs)) {
                        throw new RuntimeException('ファイルの永続化に失敗しました。');
                    }

                    $mime = mime_content_type($targetAbs) ?: 'video/mp4';
                    $fileRecord = [
                        'id' => $fileId,
                        'storagePath' => $targetRel,
                        'mimeType' => $mime,
                        'size' => filesize($targetAbs),
                        'createdAt' => $now
                    ];
                    $meta['files'][] = $fileRecord;

                    $videoId = $payload['id'] ?? uuidv4();
                    $videoRecord = [
                        'id' => $videoId,
                        'folderId' => $payload['folderId'] ?? $meta['folders'][0]['id'],
                        'name' => trim($payload['name'] ?? '新規動画'),
                        'originalFileId' => $fileId,
                        'createdAt' => $now,
                        'updatedAt' => $now
                    ];
                    $meta['videos'][] = $videoRecord;
                    saveMetadata($meta);
                    $res = ['video' => $videoRecord];
                    recordRequestSuccess($requestId, $payload, $res);
                    return $res;
                }

                if ($action === 'video.rename') {
                    $id = $payload['id'] ?? '';
                    foreach ($meta['videos'] as &$v) {
                        if ($v['id'] === $id) {
                            $v['name'] = trim($payload['name'] ?? $v['name']);
                            $v['updatedAt'] = $now;
                            saveMetadata($meta);
                            $res = ['video' => $v];
                            recordRequestSuccess($requestId, $payload, $res);
                            return $res;
                        }
                    }
                    throw new RuntimeException('対象動画が見つかりません。');
                }

                if ($action === 'video.move') {
                    $id = $payload['id'] ?? '';
                    $folderId = $payload['folderId'] ?? '';
                    foreach ($meta['videos'] as &$v) {
                        if ($v['id'] === $id) {
                            $v['folderId'] = $folderId;
                            $v['updatedAt'] = $now;
                            saveMetadata($meta);
                            $res = ['video' => $v];
                            recordRequestSuccess($requestId, $payload, $res);
                            return $res;
                        }
                    }
                    throw new RuntimeException('対象動画が見つかりません。');
                }

                if ($action === 'video.delete') {
                    $id = $payload['id'] ?? '';
                    $delVideo = null;
                    foreach ($meta['videos'] as $k => $v) {
                        if ($v['id'] === $id) {
                            $delVideo = $v;
                            unset($meta['videos'][$k]);
                            break;
                        }
                    }
                    if (!$delVideo) throw new RuntimeException('対象動画が見つかりません。');
                    $meta['videos'] = array_values($meta['videos']);

                    $delEdits = array_filter($meta['edits'], fn($e) => $e['videoId'] === $id);
                    $meta['edits'] = array_values(array_filter($meta['edits'], fn($e) => $e['videoId'] !== $id));

                    $delFileIds = array_merge([$delVideo['originalFileId']], array_column($delEdits, 'renderedFileId'));
                    $delFileIds = array_filter(array_unique($delFileIds));

                    foreach ($delFileIds as $fId) {
                        foreach ($meta['files'] as $k => $f) {
                            if ($f['id'] === $fId) {
                                @unlink(__DIR__ . '/storage/' . $f['storagePath']);
                                unset($meta['files'][$k]);
                            }
                        }
                    }
                    $meta['files'] = array_values($meta['files']);
                    saveMetadata($meta);
                    $res = ['deletedVideoId' => $id];
                    recordRequestSuccess($requestId, $payload, $res);
                    return $res;
                }

                if ($action === 'edit.save') {
                    $editId = $payload['id'] ?? uuidv4();
                    $videoId = $payload['videoId'] ?? '';
                    $expectedVersion = (int)($payload['expectedVersion'] ?? 0);
                    $editData = $payload['editData'] ?? [];

                    $renderedFileId = null;
                    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                        $renderedFileId = uuidv4();
                        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION) ?: 'webm';
                        $targetRel = 'files/' . $renderedFileId . '.' . $ext;
                        $targetAbs = __DIR__ . '/storage/' . $targetRel;
                        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetAbs)) {
                            throw new RuntimeException('編集動画の保存に失敗しました。');
                        }
                        $meta['files'][] = [
                            'id' => $renderedFileId,
                            'storagePath' => $targetRel,
                            'mimeType' => mime_content_type($targetAbs) ?: 'video/webm',
                            'size' => filesize($targetAbs),
                            'createdAt' => $now
                        ];
                    }

                    $foundIndex = -1;
                    foreach ($meta['edits'] as $idx => $e) {
                        if ($e['id'] === $editId) {
                            $foundIndex = $idx;
                            break;
                        }
                    }

                    if ($foundIndex >= 0) {
                        $targetEdit = &$meta['edits'][$foundIndex];
                        if ($targetEdit['version'] !== $expectedVersion) {
                            throw new RuntimeException('バージョン競合が発生しました。最新のデータを取得してください。');
                        }
                        $oldRenderedFileId = $targetEdit['renderedFileId'];
                        $targetEdit['version'] += 1;
                        $targetEdit['editData'] = $editData;
                        if ($renderedFileId) {
                            $targetEdit['renderedFileId'] = $renderedFileId;
                        }
                        $targetEdit['updatedAt'] = $now;

                        // 旧編集動画の削除
                        if ($renderedFileId && $oldRenderedFileId) {
                            foreach ($meta['files'] as $k => $f) {
                                if ($f['id'] === $oldRenderedFileId) {
                                    @unlink(__DIR__ . '/storage/' . $f['storagePath']);
                                    unset($meta['files'][$k]);
                                }
                            }
                            $meta['files'] = array_values($meta['files']);
                        }
                        $savedEdit = $targetEdit;
                    } else {
                        $savedEdit = [
                            'id' => $editId,
                            'videoId' => $videoId,
                            'name' => trim($payload['name'] ?? '編集 1'),
                            'version' => 1,
                            'editData' => $editData,
                            'renderedFileId' => $renderedFileId,
                            'createdAt' => $now,
                            'updatedAt' => $now
                        ];
                        $meta['edits'][] = $savedEdit;
                    }
                    saveMetadata($meta);
                    $res = ['edit' => $savedEdit];
                    recordRequestSuccess($requestId, $payload, $res);
                    return $res;
                }

                if ($action === 'edit.rename') {
                    $id = $payload['id'] ?? '';
                    foreach ($meta['edits'] as &$e) {
                        if ($e['id'] === $id) {
                            $e['name'] = trim($payload['name'] ?? $e['name']);
                            $e['updatedAt'] = $now;
                            saveMetadata($meta);
                            $res = ['edit' => $e];
                            recordRequestSuccess($requestId, $payload, $res);
                            return $res;
                        }
                    }
                    throw new RuntimeException('対象編集が見つかりません。');
                }

                if ($action === 'edit.delete') {
                    $id = $payload['id'] ?? '';
                    $delEdit = null;
                    foreach ($meta['edits'] as $k => $e) {
                        if ($e['id'] === $id) {
                            $delEdit = $e;
                            unset($meta['edits'][$k]);
                            break;
                        }
                    }
                    if (!$delEdit) throw new RuntimeException('対象編集が見つかりません。');
                    $meta['edits'] = array_values($meta['edits']);

                    if ($delEdit['renderedFileId']) {
                        foreach ($meta['files'] as $k => $f) {
                            if ($f['id'] === $delEdit['renderedFileId']) {
                                @unlink(__DIR__ . '/storage/' . $f['storagePath']);
                                unset($meta['files'][$k]);
                            }
                        }
                        $meta['files'] = array_values($meta['files']);
                    }
                    saveMetadata($meta);
                    $res = ['deletedEditId' => $id];
                    recordRequestSuccess($requestId, $payload, $res);
                    return $res;
                }

                throw new RuntimeException('未定義の操作です: ' . $action);
            });

            jsonResponse(true, $requestId, $result);
        } catch (Throwable $e) {
            jsonResponse(false, $requestId, ['code' => 'OPERATION_FAILED', 'message' => $e->getMessage()], 400);
        }
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>動画撮影・編集</title>
<style>
*{box-sizing:border-box}
body{margin:0;font:12px Meiryo,sans-serif;color:#243247;background:#eef2f7;overflow:hidden;height:100vh;display:flex;flex-direction:column}
button,input,select{font:inherit}
button{height:26px;padding:0 9px;border:1px solid #ccd6e3;border-radius:4px;background:white;cursor:pointer;white-space:nowrap;user-select:none}
button:hover{background:#edf4ff}
button:disabled{opacity:.45;cursor:default}
header,.toolbar{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
header{padding:8px 12px;background:white;border-bottom:1px solid #ccd6e3;height:42px;flex-shrink:0}
header strong{margin-right:auto;font-size:14px}
main{flex:1;display:flex;flex-direction:column;gap:6px;padding:6px;overflow:hidden;position:relative}
#stage{flex:1;min-height:120px;background:#172235;display:grid;place-items:center;overflow:hidden;position:relative}
#empty{color:#ccd8e8;line-height:2;text-align:center;user-select:none}
#surface{position:relative}
video{display:block;width:100%;height:100%}
#overlay{position:absolute;inset:0;width:100%;height:100%;touch-action:none}
#actions{justify-content:center;height:34px;flex-shrink:0}
#primary{background:#2563eb;color:white}
#primary:hover{background:#1d4ed8}
#status{min-height:18px;color:#607089;font-size:11px;text-align:center;flex-shrink:0;user-select:none}
#split{height:6px;background:#d8e2ee;cursor:row-resize;flex-shrink:0;border-radius:3px;margin:1px 0}
#split:hover{background:#93c5fd}
#timeline{background:white;border:1px solid #ccd6e3;display:flex;flex-direction:column;height:210px;flex-shrink:0}
#timeline .toolbar{padding:4px 8px;border-bottom:1px solid #ccd6e3;height:36px;flex-shrink:0}
#timeline small{margin-left:auto}
#scroll{flex:1;overflow-y:auto;overflow-x:hidden;position:relative}
#tracks{position:relative;min-height:120px;touch-action:none;user-select:none}
.tick{position:absolute;top:0;height:20px;border-left:1px solid #dde4ed;font-size:10px;color:#64748b;padding-left:2px;pointer-events:none}
.clip{position:absolute;height:24px;padding:3px 6px;border:1px solid #91b5e6;background:#e0ecff;border-radius:3px;overflow:hidden;white-space:nowrap;cursor:move;user-select:none;font-size:11px;box-shadow:0 1px 2px #0001}
.clip.B{background:#fff1bd;border-color:#eab308}
.clip.C{background:#d9f3e6;border-color:#10b981}
.clip.D{background:#ffe2e6;border-color:#f43f5e}
.clip.F{background:#eee2ff;border-color:#8b5cf6}
.clip.selected{outline:2px solid #2563eb;outline-offset:-1px;z-index:1}
.edge{position:absolute;top:0;bottom:0;width:7px;background:#0002;cursor:ew-resize}
.edge.start{left:0}.edge.end{right:0}
#playhead{position:absolute;top:0;bottom:0;width:2px;background:#e44972;pointer-events:none;z-index:5}
#handle{position:absolute;left:-6px;top:0;width:14px;height:20px;background:#e44972;clip-path:polygon(0 0,100% 0,100% 65%,50% 100%,0 65%);pointer-events:auto;cursor:ew-resize}
#veil{position:fixed;inset:0;background:#0005;z-index:20}
#library{position:fixed;inset:0 auto 0 0;width:min(500px,100vw);background:white;z-index:21;display:flex;flex-direction:column;box-shadow:4px 0 20px #0003}
#library .toolbar{padding:8px;border-bottom:1px solid #ccd6e3;flex-shrink:0}
#tree{flex:1;overflow:auto;padding:8px}
.row{display:flex;align-items:center;gap:6px;min-height:28px;padding:2px 4px;border-radius:3px;cursor:pointer}
.row:hover{background:#edf4ff}
.row.drop{outline:2px dashed #2563eb;background:#eff6ff}
.row button.expander{width:18px;height:18px;padding:0;border:0;background:transparent;cursor:pointer}
.name{flex:1;cursor:pointer;overflow-wrap:anywhere}
.name[contenteditable=true]{outline:1px solid #2563eb;background:white;padding:1px 3px;border-radius:2px}
.row time{font-size:10px;color:#64748b}
#menu{position:fixed;z-index:40;background:white;border:1px solid #ccd6e3;border-radius:4px;padding:5px;max-height:85vh;max-width:95vw;overflow:auto;box-shadow:0 6px 24px #0003}
#menu>button{display:block;width:100%;text-align:left;border:0;border-radius:2px;padding:4px 8px;margin-bottom:2px}
#menu label{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:4px 6px}
.palette{display:grid;grid-template-columns:repeat(6,24px);gap:5px;padding:6px}
.palette button{width:24px;height:24px;padding:0;border:1px solid #ccc;border-radius:3px}
.editor{position:absolute;white-space:pre-wrap;overflow:auto;padding:6px;outline:2px solid #60a5fa;background:rgba(255,255,255,0.95);z-index:10;border-radius:3px}
dialog{max-width:92vw;border:1px solid #ccd6e3;border-radius:6px;padding:16px;box-shadow:0 10px 30px #0004}
dialog::backdrop{background:#0005}
dialog h3{margin:0 0 12px 0}
dialog label{display:block;margin:10px 0;cursor:pointer}
[hidden]{display:none!important}
#printArea{display:none}
@media(max-width:600px){.row time,#timeline small{display:none}main{padding:4px}}
@media print{
@page{size:A4 portrait;margin:10mm}
body>*:not(#printArea){display:none!important}
#printArea{display:block}
.sheet{height:270mm;display:flex;flex-direction:column;gap:8mm;break-after:page;page-break-after:always}
.sheet:last-child{break-after:auto;page-break-after:auto}
.shot{flex:1;min-height:0;display:flex;flex-direction:column;justify-content:center;align-items:center}
.shot img{max-width:100%;max-height:92%;object-fit:contain;border:1px solid #ddd}
.shot p{font-size:10pt;margin:4px 0 0 0;font-weight:bold}
}
</style>
</head>
<body>
<header>
  <strong>便利ツール｜動画撮影編集</strong>
  <button id="list">動画一覧</button>
  <button id="upload">動画をアップする</button>
</header>
<main>
  <section id="stage">
    <div id="empty">録画開始で共有する画面を選択してください。<br>保存済み動画は「動画一覧」から開けます。</div>
    <div id="surface" hidden>
      <video id="video" playsinline></video>
      <canvas id="overlay"></canvas>
    </div>
  </section>

  <div id="split" title="ドラッグして高さを調整"></div>

  <div id="actions" class="toolbar">
    <button id="primary">録画開始</button>
    <button id="begin" hidden>編集開始</button>
    <button id="save" hidden>編集結果保存</button>
    <button id="download" hidden>ダウンロード</button>
    <button id="pdf" hidden>PDF出力</button>
    <button id="finish" hidden>編集作業終了</button>
  </div>

  <div id="status" role="status">動画と編集結果はサーバに安全に保存されます。</div>

  <section id="timeline" hidden>
    <div class="toolbar">
      <strong id="clock">0.0 / 0.0 秒</strong>
      <label id="zoomLabel">表示倍率 <select id="viewZoom"><option value="1">100%</option><option value="1.25">125%</option><option value="1.5">150%</option><option value="2">200%</option></select></label>
      <label>タイムスケール <input id="scale" type="range" min="1" max="10" step="0.1" value="10"></label>
      <small>右クリック：要素追加・書式・削除 ｜ 移動・両端ドラッグで時間変更</small>
    </div>
    <div id="scroll">
      <div id="tracks">
        <div id="playhead"><div id="handle"></div></div>
      </div>
    </div>
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
  <div class="toolbar">
    <button id="closeList">動画一覧を閉じる</button>
    <small>ダブルクリックで名前変更・右クリックで操作</small>
  </div>
</aside>

<input id="file" type="file" accept="video/*" hidden>
<div id="menu" hidden></div>

<dialog id="pdfDialog">
  <h3>PDF出力レイアウト選択</h3>
  <label><input type="radio" name="layout" value="1" checked> 1ページに1画面</label>
  <label><input type="radio" name="layout" value="2"> 1ページに2画面</label>
  <div style="text-align:right;gap:8px;display:flex;justify-content:flex-end;margin-top:16px">
    <button id="cancelPDF">キャンセル</button>
    <button id="print" style="background:#2563eb;color:white">印刷へ進む</button>
  </div>
</dialog>

<div id="printArea"></div>

<script>
'use strict';
(async () => {
const $ = id => document.getElementById(id);
const video = $('video'), canvas = $('overlay'), context = canvas.getContext('2d');
const uid = () => crypto.randomUUID();
const clone = x => structuredClone(x);
const clamp = (x, a, b) => Math.max(a, Math.min(b, x));

const titles = { A: 'コメント', B: '強調枠', C: '拡大枠', D: 'スキップ', E: '接続線', F: 'PDF位置' };
const colors = ['#ffffff', '#111827', '#ef4444', '#f97316', '#eab308', '#22c55e', '#14b8a6', '#2563eb', '#8b5cf6', '#ec4899', '#64748b', 'transparent'];
const dashes = { solid: [], dash: [10, 6], dot: [2, 5], chain: [12, 5, 2, 5] };

let serverData = { schemaVersion: 1, folders: [], videos: [], edits: [], files: [] };
let current = null, revision = null, elements = [];
let editing = false, dirty = false, busy = false, screenStream = null, writer = null;
let renderedPlayback = false, editor = null, connecting = null;
let selected = new Set(), collapsed = new Set();
let audio = null, audioSource = null, audioSink = null;
let displayZoom = 1.0;

const message = text => $('status').textContent = text;
const safe = fn => async (...args) => {
  try {
    await fn(...args);
  } catch (error) {
    console.error(error);
    message(error.message || '操作に失敗しました');
  }
};

const duration = () => Number.isFinite(video.duration) ? video.duration : 0;
const locked = () => busy || !!screenStream;
const find = id => elements.find(e => e.id === id);

// API 通信クライアント
async function api(action, payload = null, file = null, method = 'POST') {
  const reqId = uid();
  let url = `index.php?api=v1&action=${encodeURIComponent(action)}`;
  let options = { method };

  if (method === 'GET') {
    if (payload) {
      const q = new URLSearchParams(payload).toString();
      url += `&${q}`;
    }
  } else {
    if (file) {
      const fd = new FormData();
      fd.append('metadata', JSON.stringify({ requestId: reqId, payload }));
      fd.append('file', file);
      options.body = fd;
    } else {
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify({ requestId: reqId, payload });
    }
  }

  const res = await fetch(url, options);
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.error?.message || `通信エラーが発生しました (${res.status})`);
  }
  const data = await res.json();
  if (!data.ok) throw new Error(data.error?.message || 'API処理に失敗しました');
  return data.data;
}

async function refreshList() {
  const data = await api('list', null, null, 'GET');
  serverData = data;
  tree();
}

function library(open) {
  $('library').hidden = $('veil').hidden = !open;
}

function commitText() {
  if (!editor) return;
  const active = editor;
  editor = null;
  active.item.text = active.node.innerText;
  active.node.remove();
  dirty = true;
}

function fit() {
  const r = $('stage').getBoundingClientRect();
  const w = video.videoWidth || 1280, h = video.videoHeight || 720;
  const scale = Math.min((r.width - 20) / w, (r.height - 20) / h) * displayZoom;
  $('surface').style.width = (w * scale) + 'px';
  $('surface').style.height = (h * scale) + 'px';
  if (canvas.width !== w || canvas.height !== h) {
    canvas.width = w;
    canvas.height = h;
  }
}

function updateUI() {
  $('surface').hidden = !video.src && !video.srcObject;
  $('empty').hidden = !$('surface').hidden;
  $('timeline').hidden = !editing;
  $('begin').hidden = !current || editing || !!screenStream;
  $('save').hidden = $('finish').hidden = !editing;
  $('download').hidden = $('pdf').hidden = !current || !!screenStream;
  $('primary').textContent = screenStream ? '録画停止' : editing ? (video.paused ? '編集再生' : '編集再生停止') : '録画開始';
  document.querySelectorAll('header button, #actions button, #library button').forEach(b => b.disabled = locked());
  $('primary').disabled = busy;
  fit();
  tracks();
  paint();
}

function clearState() {
  commitText();
  video.pause();
  video.srcObject = null;
  video.removeAttribute('src');
  video.load();
  current = revision = null;
  elements = [];
  selected.clear();
  editing = dirty = renderedPlayback = false;
  connecting = null;
  $('scale').value = 10;
}

function waitFor(event, action) {
  return new Promise((resolve, reject) => {
    const clean = () => {
      clearTimeout(timer);
      video.removeEventListener(event, done);
      video.removeEventListener('error', fail);
    };
    const done = () => { clean(); resolve(); };
    const fail = () => { clean(); reject(new Error('動画の読み込みに失敗しました')); };
    const timer = setTimeout(fail, 30000);
    video.addEventListener(event, done);
    video.addEventListener('error', fail);
    try { action(); } catch (err) { clean(); reject(err); }
  });
}

async function seek(time) {
  if (Math.abs(video.currentTime - time) > 0.00001) {
    await waitFor('seeked', () => video.currentTime = time);
  } else if (video.readyState < 2) {
    await waitFor('loadeddata', () => {});
  }
  await new Promise(requestAnimationFrame);
}

async function setSourceURL(url) {
  video.pause();
  await waitFor('loadedmetadata', () => {
    video.src = url;
    video.load();
  });
  await seek(0);
  fit();
}

async function leavePrompt() {
  if (locked()) return false;
  commitText();
  if (!dirty) return true;
  if (confirm('編集結果を保存して続行しますか？')) {
    await saveEdit();
    return true;
  }
  if (confirm('未保存の編集内容を破棄しますか？')) {
    dirty = false;
    return true;
  }
  return false;
}

async function loadEntity(videoItem, editItem = null, startEditing = false) {
  if (!await leavePrompt()) return false;
  busy = true;
  updateUI();
  try {
    const useRendered = !!editItem && !startEditing && !!editItem.renderedFileId;
    const fileId = useRendered ? editItem.renderedFileId : videoItem.originalFileId;
    const streamUrl = `index.php?api=v1&action=file.stream&id=${encodeURIComponent(fileId)}`;

    clearState();
    current = videoItem;
    revision = editItem;

    if (editItem) {
      const fullEdit = await api('edit.get', { id: editItem.id }, null, 'GET');
      elements = clone(fullEdit.editData?.elements || []);
      revision.version = fullEdit.version;
    } else {
      elements = [];
    }

    editing = startEditing;
    renderedPlayback = useRendered;

    await setSourceURL(streamUrl);
    library(false);
    message(`${videoItem.name} ／ ${editItem ? editItem.name : '元動画'}`);
    return true;
  } catch (err) {
    clearState();
    throw err;
  } finally {
    busy = false;
    updateUI();
    tree();
  }
}

// 座標・描画関連
function point(event) {
  const r = canvas.getBoundingClientRect();
  return {
    x: (event.clientX - r.left) * canvas.width / r.width,
    y: (event.clientY - r.top) * canvas.height / r.height
  };
}

function drag(event, move) {
  event.preventDefault();
  const stop = () => {
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', stop);
    window.removeEventListener('pointercancel', stop);
  };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', stop);
  window.addEventListener('pointercancel', stop);
}

function selectElement(item, event) {
  if (!event.ctrlKey && !event.shiftKey) selected.clear();
  if (item) {
    if ((event.ctrlKey || event.shiftKey) && selected.has(item.id)) selected.delete(item.id);
    else selected.add(item.id);
  }
}

function shape(ctx, e) {
  ctx.beginPath();
  if (e.shape === 'ellipse') {
    ctx.ellipse(e.x + e.w / 2, e.y + e.h / 2, Math.max(0.1, e.w / 2), Math.max(0.1, e.h / 2), 0, 0, Math.PI * 2);
  } else if (e.shape === 'round') {
    ctx.roundRect(e.x, e.y, e.w, e.h, Math.min(16, e.w / 4, e.h / 4));
  } else {
    ctx.rect(e.x, e.y, e.w, e.h);
  }
}

function port(e, p) {
  if (e.shape === 'ellipse') {
    return { x: e.x + e.w / 2 + Math.cos(p.angle || 0) * e.w / 2, y: e.y + e.h / 2 + Math.sin(p.angle || 0) * e.h / 2 };
  }
  const r = p.ratio ?? 0.5;
  return p.side === 'left' ? { x: e.x, y: e.y + e.h * r } :
         p.side === 'right' ? { x: e.x + e.w, y: e.y + e.h * r } :
         p.side === 'top' ? { x: e.x + e.w * r, y: e.y } :
         { x: e.x + e.w * r, y: e.y + e.h };
}

function nearest(e, q) {
  if (e.shape === 'ellipse') {
    return { angle: Math.atan2((q.y - e.y - e.h / 2) / (e.h / 2 || 1), (q.x - e.x - e.w / 2) / (e.w / 2 || 1)) };
  }
  return [
    { side: 'left', ratio: clamp((q.y - e.y) / (e.h || 1), 0.05, 0.95) },
    { side: 'right', ratio: clamp((q.y - e.y) / (e.h || 1), 0.05, 0.95) },
    { side: 'top', ratio: clamp((q.x - e.x) / (e.w || 1), 0.05, 0.95) },
    { side: 'bottom', ratio: clamp((q.x - e.x) / (e.w || 1), 0.05, 0.95) }
  ].sort((a, b) => {
    const p1 = port(e, a), p2 = port(e, b);
    return Math.hypot(p1.x - q.x, p1.y - q.y) - Math.hypot(p2.x - q.x, p2.y - q.y);
  })[0];
}

function normal(e, p) {
  if (e.shape === 'ellipse') {
    const x = Math.cos(p.angle || 0) / (e.w || 1), y = Math.sin(p.angle || 0) / (e.h || 1), l = Math.hypot(x, y) || 1;
    return { x: x / l, y: y / l };
  }
  return { left: { x: -1, y: 0 }, right: { x: 1, y: 0 }, top: { x: 0, y: -1 }, bottom: { x: 0, y: 1 } }[p.side || 'bottom'];
}

function endpoints(e) {
  const a = find(e.ownerId), b = find(e.commentId);
  if (!a || !b) return null;
  const p = port(a, e.from), q = port(b, e.to);
  const n = normal(a, e.from), m = normal(b, e.to);
  return [p, { x: p.x + 20 * n.x, y: p.y + 20 * n.y }, { x: q.x + 20 * m.x, y: q.y + 20 * m.y }, q];
}

function isElementVisible(e, t) {
  const owner = e.type === 'E' ? find(e.ownerId) : e;
  return owner && !['D', 'F'].includes(e.type) && t >= owner.start && t < owner.end;
}

const drawOrder = { C: 0, B: 1, A: 2, E: 3 };

function paint(ctx = context, time = video.currentTime, output = false) {
  if (!output) ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (!output && (!editing || renderedPlayback)) return;

  const list = elements.filter(e => isElementVisible(e, time)).sort((a, b) => drawOrder[a.type] - drawOrder[b.type]);
  for (const e of list) {
    ctx.save();
    ctx.strokeStyle = e.stroke;
    ctx.lineWidth = e.width;
    ctx.setLineDash(dashes[e.dash] || []);

    if (e.type === 'E') {
      const ends = endpoints(e);
      if (!ends) { ctx.restore(); continue; }
      const [p, u, v, q] = ends;
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      ctx.lineTo(u.x, u.y);
      if (e.line === 'orthogonal') {
        ctx.lineTo((u.x + v.x) / 2, u.y);
        ctx.lineTo((u.x + v.x) / 2, v.y);
      } else if (e.line === 'wave') {
        const dx = v.x - u.x, dy = v.y - u.y, l = Math.hypot(dx, dy) || 1;
        for (let i = 1; i <= 80; i++) {
          const t = i / 80, s = 16 * Math.sin(2 * Math.PI * t);
          ctx.lineTo(u.x + dx * t - dy / l * s, u.y + dy * t + dx / l * s);
        }
      }
      ctx.lineTo(v.x, v.y);
      ctx.lineTo(q.x, q.y);
      ctx.stroke();
      ctx.setLineDash([]);

      if (e.terminal === 'arrow') {
        const a = Math.atan2(q.y - v.y, q.x - v.x);
        ctx.beginPath();
        ctx.moveTo(q.x - 12 * Math.cos(a - 0.5), q.y - 12 * Math.sin(a - 0.5));
        ctx.lineTo(q.x, q.y);
        ctx.lineTo(q.x - 12 * Math.cos(a + 0.5), q.y - 12 * Math.sin(a + 0.5));
        ctx.stroke();
      } else if (e.terminal === 'circle') {
        ctx.beginPath();
        ctx.arc(q.x, q.y, 5, 0, Math.PI * 2);
        ctx.stroke();
      }

      if (!output) {
        [p, q].forEach((pt, idx) => {
          ctx.fillStyle = idx ? '#22c55e' : '#38bdf8';
          ctx.beginPath();
          ctx.arc(pt.x, pt.y, selected.has(e.id) ? 6 : 4, 0, Math.PI * 2);
          ctx.fill();
        });
      }
    } else {
      shape(ctx, e);
      if (e.type === 'C' && video.readyState >= 2) {
        ctx.save();
        ctx.clip();
        ctx.drawImage(video, e.x + (e.w - e.w / e.zoom) / 2, e.y + (e.h - e.h / e.zoom) / 2, e.w / e.zoom, e.h / e.zoom, e.x, e.y, e.w, e.h);
        ctx.restore();
      }
      if (e.background !== 'transparent') {
        ctx.fillStyle = e.background;
        ctx.fill();
      }
      ctx.stroke();

      if (e.type === 'A') {
        ctx.save();
        shape(ctx, e);
        ctx.clip();
        ctx.fillStyle = e.color;
        const fontName = e.font === 'mincho' ? 'serif' : 'sans-serif';
        ctx.font = `${e.size}px ${fontName}`;
        let y = e.y + e.size + 6;
        for (const line of (e.text || '').split('\n')) {
          let row = '';
          for (const ch of line) {
            if (row && ctx.measureText(row + ch).width > e.w - 12) {
              ctx.fillText(row, e.x + 6, y);
              y += e.size * 1.35;
              row = '';
            }
            row += ch;
          }
          ctx.fillText(row, e.x + 6, y);
          y += e.size * 1.35;
        }
        ctx.restore();
      }

      if (!output && selected.has(e.id)) {
        ctx.strokeStyle = '#60a5fa';
        ctx.setLineDash([4, 3]);
        ctx.strokeRect(e.x, e.y, e.w, e.h);
        ctx.fillStyle = '#60a5fa';
        ctx.fillRect(e.x + e.w - 7, e.y + e.h - 7, 14, 14);
      }
    }
    ctx.restore();
  }
}

function hit(q) {
  return elements.filter(e => isElementVisible(e, video.currentTime)).sort((a, b) => drawOrder[b.type] - drawOrder[a.type]).find(e => {
    if (e.type === 'E') {
      const p = endpoints(e);
      return p && [p[0], p[3]].some(r => Math.hypot(r.x - q.x, r.y - q.y) < 15);
    }
    return q.x >= e.x && q.x <= e.x + e.w && q.y >= e.y && q.y <= e.y + e.h;
  });
}

// キャンバス操作
canvas.onpointerdown = event => {
  if (event.button !== 0 || !editing || locked()) return;
  commitText();
  video.pause();
  const q = point(event), item = hit(q);

  if (connecting) {
    if (!item || !['A', 'B', 'C'].includes(item.type)) return;
    if (connecting === 'start') {
      connecting = item.id;
      message('接続先の要素をクリックしてください。Escで中止。');
      return;
    }
    const first = find(connecting);
    if (!first || (first.type === 'A') === (item.type === 'A')) {
      message('コメントと強調枠、またはコメントと拡大枠を選択してください');
      return;
    }
    const owner = first.type === 'A' ? item : first;
    const comment = first.type === 'A' ? first : item;
    const from = nearest(owner, { x: comment.x + comment.w / 2, y: comment.y + comment.h / 2 });
    const line = {
      id: uid(),
      type: 'E',
      ownerId: owner.id,
      commentId: comment.id,
      from,
      to: nearest(comment, port(owner, from)),
      stroke: owner.stroke,
      width: owner.width,
      dash: owner.dash,
      line: 'orthogonal',
      terminal: 'arrow'
    };
    elements.push(line);
    selected = new Set([line.id]);
    dirty = true;
    connecting = null;
    paint();
    return;
  }

  selectElement(item, event);
  tracks();
  paint();
  if (!item) return;

  const old = clone(item);
  const resize = item.type !== 'E' && q.x > item.x + item.w - 18 && q.y > item.y + item.h - 18;
  const ends = item.type === 'E' ? endpoints(item) : null;
  const endSide = ends && Math.hypot(ends[0].x - q.x, ends[0].y - q.y) < 15 ? 'from' : 'to';

  drag(event, ev => {
    const p = point(ev);
    if (item.type === 'E') {
      const ref = find(endSide === 'from' ? item.ownerId : item.commentId);
      item[endSide] = nearest(ref, p);
    } else if (resize) {
      item.w = clamp(old.w + p.x - q.x, 20, canvas.width - item.x);
      item.h = clamp(old.h + p.y - q.y, 20, canvas.height - item.y);
    } else {
      item.x = clamp(old.x + p.x - q.x, 0, canvas.width - item.w);
      item.y = clamp(old.y + p.y - q.y, 0, canvas.height - item.h);
    }
    dirty = true;
    paint();
  });
};

canvas.ondblclick = event => {
  if (!editing || locked()) return;
  const item = hit(point(event));
  if (item?.type !== 'A') return;
  commitText();
  video.pause();

  const node = document.createElement('div');
  const k = canvas.clientWidth / canvas.width;
  node.className = 'editor';
  node.contentEditable = 'true';
  node.innerText = item.text;
  Object.assign(node.style, {
    left: (item.x * k) + 'px',
    top: (item.y * k) + 'px',
    width: (item.w * k) + 'px',
    height: (item.h * k) + 'px',
    fontSize: (item.size * k) + 'px',
    fontFamily: item.font === 'mincho' ? 'serif' : 'sans-serif',
    color: item.color,
    background: item.background !== 'transparent' ? item.background : 'rgba(255,255,255,0.95)'
  });
  $('surface').append(node);
  editor = { node, item };
  node.oninput = () => { item.text = node.innerText; dirty = true; };
  node.onblur = commitText;
  node.focus();
};

// メニュー管理
function showMenu(event) {
  const menu = $('menu');
  menu.hidden = false;
  menu.style.left = clamp(event.clientX, 4, Math.max(4, window.innerWidth - menu.offsetWidth - 8)) + 'px';
  menu.style.top = clamp(event.clientY, 4, Math.max(4, window.innerHeight - menu.offsetHeight - 8)) + 'px';
}

function openMenu(event, items) {
  event.preventDefault();
  if (locked()) return;
  $('menu').replaceChildren();
  items.forEach(([label, run]) => {
    const btn = document.createElement('button');
    btn.textContent = label;
    btn.onclick = safe(async () => {
      $('menu').hidden = true;
      if (!locked()) await run();
    });
    $('menu').append(btn);
  });
  showMenu(event);
}

// タイムライン
function timelineRange() {
  const span = Math.max(0.001, duration() * (0.1 + 0.9 * (+$('scale').value - 1) / 9));
  return {
    span,
    start: clamp(video.currentTime - span / 2, 0, Math.max(0, duration() - span)),
    width: Math.max(1, $('tracks').clientWidth - 20)
  };
}

function timeAtEvent(event, r = timelineRange()) {
  return clamp(r.start + (event.clientX - $('tracks').getBoundingClientRect().left - 10) / r.width * r.span, 0, duration());
}

function tracks() {
  if (!editing) return;
  const root = $('tracks'), r = timelineRange();
  root.querySelectorAll('.clip, .tick').forEach(n => n.remove());

  for (let i = 0; i <= 8; i++) {
    const tick = document.createElement('span');
    tick.className = 'tick';
    tick.style.left = (10 + r.width * i / 8) + 'px';
    tick.textContent = (r.start + r.span * i / 8).toFixed(1) + 's';
    if (i === 8) tick.style.transform = 'translateX(-100%)';
    root.append(tick);
  }

  const rows = [];
  elements.filter(e => e.type !== 'E').sort((a, b) => a.start - b.start).forEach(e => {
    let row = rows.findIndex(end => end <= e.start);
    if (row < 0) row = rows.length;
    rows[row] = Math.max(e.end, e.start + 0.001);

    if (e.end < r.start || e.start > r.start + r.span) return;
    const node = document.createElement('div');
    node.className = `clip ${e.type}${selected.has(e.id) ? ' selected' : ''}`;
    node.setAttribute('data-element-id', e.id);
    node.textContent = e.type === 'A' ? (e.text || 'コメント') : titles[e.type];

    const left = 10 + (Math.max(e.start, r.start) - r.start) / r.span * r.width;
    const w = Math.max(4, Math.min(root.clientWidth - 10 - left, e.type === 'F' ? 8 : (Math.min(e.end, r.start + r.span) - Math.max(e.start, r.start)) / r.span * r.width));

    Object.assign(node.style, {
      left: left + 'px',
      top: (24 + row * 28) + 'px',
      width: w + 'px'
    });

    if (e.type !== 'F') {
      ['start', 'end'].forEach(side => {
        if (side === 'start' && e.start < r.start) return;
        if (side === 'end' && e.end > r.start + r.span) return;
        const edge = document.createElement('span');
        edge.className = `edge ${side}`;
        node.append(edge);
      });
    }

    node.oncontextmenu = ev => {
      ev.stopPropagation();
      elementMenu(ev, e, true);
    };

    node.onpointerdown = ev => {
      if (ev.button !== 0 || locked()) return;
      ev.stopPropagation();
      video.pause();
      selectElement(e, ev);
      const old = clone(e), startX = ev.clientX;
      const isStartEdge = ev.target.classList.contains('start');
      const isEndEdge = ev.target.classList.contains('end');
      const mode = isStartEdge ? 'start' : isEndEdge ? 'end' : 'move';

      drag(ev, evMove => {
        const delta = (evMove.clientX - startX) / r.width * r.span;
        if (mode === 'start') {
          e.start = clamp(old.start + delta, 0, e.end - 0.001);
        } else if (mode === 'end') {
          e.end = clamp(old.end + delta, e.start + 0.001, duration());
        } else {
          const len = old.end - old.start;
          e.start = clamp(old.start + delta, 0, duration() - len);
          e.end = e.start + len;
        }
        dirty = true;
        tracks();
        paint();
      });
      tracks();
      paint();
    };
    root.append(node);
  });

  root.style.height = Math.max(120, rows.length * 28 + 35) + 'px';
  updateClock();
}

function updateClock() {
  $('clock').textContent = `${video.currentTime.toFixed(1)} / ${duration().toFixed(1)} 秒`;
  const r = timelineRange();
  $('playhead').style.left = (10 + (video.currentTime - r.start) / r.span * r.width) + 'px';
}

function addElement(type, time, q) {
  if (!duration() || time >= duration()) {
    message('動画の再生時間内に追加してください');
    return;
  }
  const e = {
    id: uid(),
    type,
    start: time,
    end: type === 'F' ? time : Math.min(duration(), time + 3)
  };
  if (['A', 'B', 'C'].includes(type)) {
    const w = Math.min(260, canvas.width || 640);
    const h = Math.min(110, canvas.height || 360);
    Object.assign(e, {
      x: clamp(q.x, 0, (canvas.width || 640) - w),
      y: clamp(q.y, 0, (canvas.height || 360) - h),
      w,
      h,
      text: 'コメント',
      size: 24,
      font: 'gothic',
      color: '#ffffff',
      stroke: '#ef4444',
      width: 3,
      dash: 'solid',
      shape: 'rect',
      background: 'transparent',
      zoom: 2
    });
  }
  if (type === 'F') {
    e.time = time;
  }
  elements.push(e);
  selected = new Set([e.id]);
  dirty = true;
  tracks();
  paint();
}

function elementMenu(event, item, isTimeline) {
  if (!editing) return;
  commitText();
  video.pause();
  if (item && !selected.has(item.id)) selected = new Set([item.id]);

  const time = isTimeline ? timeAtEvent(event) : video.currentTime;
  const q = isTimeline ? { x: canvas.width * 0.1, y: canvas.height * 0.1 } : point(event);

  const items = (isTimeline ? ['A', 'B', 'C', 'D', 'F'] : ['A', 'B', 'C']).map(type => [
    `${titles[type]}追加`, () => addElement(type, time, q)
  ]);

  if (!isTimeline) {
    items.push(['接続線追加', () => {
      connecting = 'start';
      message('接続元の要素（強調枠または拡大枠）をクリックしてください。Escで中止。');
    }]);
  }

  if (isTimeline && selected.size > 1) {
    items.push(['開始位置を合わせる', () => {
      const list = elements.filter(e => selected.has(e.id) && e.type !== 'E');
      const start = item ? item.start : Math.min(...list.map(e => e.start));
      if (list.some(e => start + e.end - e.start > duration())) {
        message('動画終了時刻を超えるため変更できません');
        return;
      }
      list.forEach(e => {
        e.end = start + e.end - e.start;
        e.start = start;
      });
      dirty = true;
      tracks();
      paint();
    }]);
  }

  if (item) {
    if (!['D', 'F'].includes(item.type)) {
      items.push(['書式変更', () => formatDialog(item, event)]);
    }
    items.push(['選択要素を削除', () => {
      elements = elements.filter(e => !selected.has(e.id) && !selected.has(e.ownerId) && !selected.has(e.commentId));
      selected.clear();
      dirty = true;
      tracks();
      paint();
    }]);
  }
  openMenu(event, items);
}

function formatDialog(e, event) {
  const root = $('menu');
  root.replaceChildren();

  const fields = [
    ...(e.type === 'A' ? [
      ['文字サイズ', 'size', Array.from({ length: 31 }, (_, i) => [i + 10, i + 10])],
      ['フォント', 'font', [['gothic', 'ゴシック'], ['mincho', '明朝']]]
    ] : []),
    ['線の太さ', 'width', [[1, '━ 1px'], [2, '━━ 2px'], [3, '━━━━ 3px'], [5, '████ 5px'], [8, '██████ 8px']]],
    ['線種', 'dash', [['solid', '実線'], ['dash', '破線'], ['dot', '点線'], ['chain', '一点鎖線']]],
    ...(e.type === 'E' ? [
      ['線形', 'line', [['straight', '直線'], ['orthogonal', '折れ線'], ['wave', '1周期波線']]],
      ['終端', 'terminal', [['none', 'なし'], ['arrow', '矢印'], ['circle', '丸']]]
    ] : [
      ['図形', 'shape', [['rect', '四角'], ['ellipse', '楕円'], ['round', '角丸四角']]]
    ]),
    ...(e.type === 'C' ? [
      ['倍率', 'zoom', [[1, '1倍'], [2, '2倍'], [3, '3倍'], [4, '4倍'], [6, '6倍']]]
    ] : [])
  ];

  fields.forEach(([title, key, opts]) => {
    const label = document.createElement('label');
    const sel = document.createElement('select');
    label.textContent = title;
    opts.forEach(([val, text]) => sel.add(new Option(String(text), String(val))));
    sel.value = String(e[key]);
    sel.onchange = () => {
      e[key] = ['size', 'width', 'zoom'].includes(key) ? +sel.value : sel.value;
      dirty = true;
      paint();
    };
    label.append(sel);
    root.append(label);
  });

  const targetSel = document.createElement('select');
  targetSel.add(new Option('線色', 'stroke'));
  if (e.type !== 'E') targetSel.add(new Option('背景色', 'background'));
  if (e.type === 'A') targetSel.add(new Option('文字色', 'color'));
  root.append(targetSel);

  const palette = document.createElement('div');
  palette.className = 'palette';
  colors.forEach(col => {
    const btn = document.createElement('button');
    btn.title = col === 'transparent' ? '塗り潰しなし' : col;
    btn.style.background = col === 'transparent' ? 'repeating-linear-gradient(45deg,#fff 0 4px,#bbb 4px 8px)' : col;
    btn.onclick = () => {
      e[targetSel.value] = col;
      dirty = true;
      paint();
    };
    palette.append(btn);
  });
  root.append(palette);
  showMenu(event);
}

// スキップ処理
function getNextPlaybackTime(t) {
  let cur = t;
  for (let i = 0; i <= elements.length; i++) {
    const active = elements.filter(e => e.type === 'D' && cur >= e.start && cur < e.end);
    if (!active.length) break;
    cur = Math.max(...active.map(e => e.end));
  }
  return Math.min(cur, duration());
}

// 編集動画レンダリング
function createRecorder(stream) {
  const mimeTypes = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
  const mimeType = mimeTypes.find(t => MediaRecorder.isTypeSupported(t));
  if (!mimeType) throw new Error('対応する録画形式がブラウザにありません');
  return new MediaRecorder(stream, { mimeType });
}

function collectData(recorder) {
  return new Promise((resolve, reject) => {
    const parts = [];
    recorder.ondataavailable = e => { if (e.data.size) parts.push(e.data); };
    recorder.onstop = () => resolve(new Blob(parts, { type: recorder.mimeType }));
    recorder.onerror = e => reject(e.error || new Error('recorder.onerror = e => reject(e.error || new Error('録画データの収集に失敗しました'));
  });
}

async function audioReady() {
  if (!audio) {
    audio = new AudioContext();
    audioSource = audio.createMediaElementSource(video);
    audioSink = audio.createMediaStreamDestination();
    audioSource.connect(audio.destination);
    audioSource.connect(audioSink);
  }
  if (audio.state === 'suspended') {
    await audio.resume();
  }
}

async function renderEditedVideo() {
  if (!canvas.captureStream) throw new Error('このブラウザは編集動画の生成に対応していません');
  if (getNextPlaybackTime(0) >= duration()) throw new Error('全区間がスキップされているため保存できません');

  const originTime = video.currentTime;
  const originMuted = video.muted;
  const originRate = video.playbackRate;

  const outputCanvas = document.createElement('canvas');
  outputCanvas.width = canvas.width;
  outputCanvas.height = canvas.height;
  const outCtx = outputCanvas.getContext('2d');

  let stream, rec, frameId, detached = false;
  video.pause();

  try {
    await audioReady();
    audioSource.disconnect(audio.destination);
    detached = true;

    await seek(getNextPlaybackTime(0));
    const drawFrame = () => {
      outCtx.drawImage(video, 0, 0, outputCanvas.width, outputCanvas.height);
      paint(outCtx, video.currentTime, true);
    };

    drawFrame();
    stream = outputCanvas.captureStream(30);
    audioSink.stream.getAudioTracks().forEach(t => stream.addTrack(t.clone()));

    rec = createRecorder(stream);
    const resultPromise = collectData(rec);
    resultPromise.catch(() => {});

    const finishedPromise = new Promise((resolve, reject) => {
      const loop = async () => {
        try {
          if (video.ended || video.currentTime >= duration()) {
            resolve();
            return;
          }
          const next = getNextPlaybackTime(video.currentTime);
          if (next > video.currentTime) {
            video.pause();
            await seek(next);
            if (next >= duration()) {
              resolve();
              return;
            }
            await video.play();
          }
          if (video.readyState >= 2 && !video.seeking) {
            drawFrame();
          }
          frameId = requestAnimationFrame(loop);
        } catch (err) {
          reject(err);
        }
      };
      frameId = requestAnimationFrame(loop);
    });
    finishedPromise.catch(() => {});

    rec.start(250);
    video.muted = false;
    video.playbackRate = 1;
    await video.play();

    await Promise.race([
      finishedPromise,
      resultPromise.then(() => { throw new Error('書き出しが途中で中断されました'); })
    ]);

    video.pause();
    rec.stop();
    return await resultPromise;
  } finally {
    cancelAnimationFrame(frameId);
    video.pause();
    if (rec && rec.state === 'recording') rec.stop();
    if (stream) stream.getTracks().forEach(t => t.stop());
    if (detached && audioSource && audio) {
      audioSource.connect(audio.destination);
    }
    video.muted = originMuted;
    video.playbackRate = originRate;
    await seek(Math.min(originTime, duration()));
  }
}

// 編集結果の保存
async function saveEdit() {
  commitText();
  if (!current || locked()) return;
  busy = true;
  updateUI();
  try {
    message('編集動画を生成・保存しています。そのままお待ちください...');
    const blob = await renderEditedVideo();
    if (!blob || !blob.size) throw new Error('編集動画の生成に失敗しました');

    const editId = revision ? revision.id : uid();
    const editPayload = {
      id: editId,
      videoId: current.id,
      name: revision ? revision.name : `編集 ${(current.edits ? current.edits.length : 0) + 1}`,
      expectedVersion: revision ? revision.version : 0,
      editData: {
        schemaVersion: 1,
        coordinateSystem: 'source-pixels',
        sourceWidth: canvas.width,
        sourceHeight: canvas.height,
        elements: clone(elements)
      }
    };

    const res = await api('edit.save', editPayload, blob);
    revision = res.edit;
    dirty = false;
    await refreshList();
    message('編集結果と編集動画を正常に保存しました');
  } finally {
    busy = false;
    updateUI();
  }
}

// 録画機能
async function toggleRecord() {
  if (writer && writer.state === 'recording') {
    writer.stop();
    return;
  }
  if (!await leavePrompt()) return;
  if (!navigator.mediaDevices?.getDisplayMedia) {
    throw new Error('画面録画にはHTTPSまたはlocalhost環境が必要です');
  }

  busy = true;
  updateUI();
  try {
    screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true });
    clearState();
    video.srcObject = screenStream;
    video.muted = true;
    await video.play();

    writer = createRecorder(screenStream);
    const resultPromise = collectData(writer);
    resultPromise.catch(() => {});

    screenStream.getVideoTracks()[0].onended = () => {
      if (writer && writer.state === 'recording') writer.stop();
    };

    writer.start(500);
    busy = false;
    updateUI();
    message('録画中... 音声を含める場合は共有ダイアログの「システム音声を含める」を選択してください。');

    const recordedBlob = await resultPromise;
    busy = true;
    updateUI();

    screenStream.getTracks().forEach(t => t.stop());
    screenStream = null;
    video.srcObject = null;
    video.muted = false;

    message('録画動画をアップロード・登録しています...');
    const nowStr = new Date().toLocaleString('ja-JP');
    const defaultFolderId = serverData.folders[0]?.id;
    const uploadRes = await api('video.upload', {
      id: uid(),
      folderId: defaultFolderId,
      name: `録画 ${nowStr}`
    }, recordedBlob);

    await refreshList();
    busy = false;
    await loadEntity(uploadRes.video, null, false);
    message('録画を保存しました。「編集開始」ボタンから編集可能です。');
  } finally {
    if (screenStream) screenStream.getTracks().forEach(t => t.stop());
    screenStream = null;
    writer = null;
    video.srcObject = null;
    video.muted = false;
    busy = false;
    updateUI();
  }
}

// ダウンロード
async function downloadFile() {
  if (editing && (dirty || !revision?.renderedFileId)) {
    await saveEdit();
  }
  const fileId = revision?.renderedFileId ? revision.renderedFileId : current.originalFileId;
  window.location.href = `index.php?api=v1&action=file.download&id=${encodeURIComponent(fileId)}`;
}

// PDF出力
async function openPDFDialog() {
  if (!elements.some(e => e.type === 'F')) {
    message('タイムライン上を右クリックして「PDF位置」を追加してください');
    return;
  }
  if (renderedPlayback) {
    busy = true;
    updateUI();
    try {
      const streamUrl = `index.php?api=v1&action=file.stream&id=${encodeURIComponent(current.originalFileId)}`;
      await setSourceURL(streamUrl);
      renderedPlayback = false;
    } finally {
      busy = false;
      updateUI();
    }
  }
  $('pdfDialog').showModal();
}

async function executePDFPrint() {
  $('pdfDialog').close();
  commitText();
  video.pause();

  const originTime = video.currentTime;
  const layoutCount = +document.querySelector('[name=layout]:checked').value;
  const times = elements.filter(e => e.type === 'F').map(e => e.time ?? e.start).sort((a, b) => a - b);

  busy = true;
  updateUI();
  $('printArea').replaceChildren();

  try {
    let currentSheet = null;
    for (let i = 0; i < times.length; i++) {
      await seek(Math.min(times[i], Math.max(0, duration() - 0.001)));
      const shotCanvas = document.createElement('canvas');
      shotCanvas.width = canvas.width;
      shotCanvas.height = canvas.height;
      const sCtx = shotCanvas.getContext('2d');
      sCtx.drawImage(video, 0, 0, shotCanvas.width, shotCanvas.height);
      paint(sCtx, video.currentTime, true);

      if (i % layoutCount === 0) {
        currentSheet = document.createElement('div');
        currentSheet.className = 'sheet';
        $('printArea').append(currentSheet);
      }

      const shotDiv = document.createElement('div');
      shotDiv.className = 'shot';
      const img = new Image();
      img.src = shotCanvas.toDataURL('image/png');
      img.style.maxHeight = layoutCount === 2 ? '118mm' : '245mm';
      await img.decode();

      const cap = document.createElement('p');
      cap.textContent = `${current.name} ／ ${times[i].toFixed(1)} 秒`;
      shotDiv.append(img, cap);
      currentSheet.append(shotDiv);
    }
    window.print();
  } finally {
    await seek(originTime);
    busy = false;
    updateUI();
  }
}

// 削除処理
async function removeItem(type, id, displayName) {
  if (!await leavePrompt()) return;
  const warn = type === 'edit' ? 'この編集を削除しますか？' : '配下の動画や編集データも完全に削除されます。削除しますか？';
  if (!confirm(`「${displayName}」を削除しますか？\n${warn}`)) return;

  busy = true;
  updateUI();
  try {
    if (type === 'folder') await api('folder.delete', { id });
    else if (type === 'video') await api('video.delete', { id });
    else if (type === 'edit') await api('edit.delete', { id });

    if ((type === 'video' && current?.id === id) || (type === 'edit' && revision?.id === id)) {
      clearState();
    }
    await refreshList();
    message('削除が完了しました');
  } finally {
    busy = false;
    updateUI();
  }
}

// ツリービュー構築
function tree() {
  const root = $('tree');
  root.replaceChildren();

  function makeRow(nameText, depth, isBranch, dateStr, entityType, entityId) {
    const node = document.createElement('div');
    node.className = 'row';
    node.setAttribute('data-entity-type', entityType);
    node.setAttribute('data-entity-id', entityId);
    node.style.marginLeft = (depth * 16) + 'px';

    const expBtn = document.createElement('button');
    expBtn.className = 'expander';
    expBtn.textContent = isBranch ? (collapsed.has(entityId) ? '▸' : '▾') : '·';

    const span = document.createElement('span');
    span.className = 'name';
    span.textContent = nameText;

    const time = document.createElement('time');
    time.textContent = dateStr ? new Date(dateStr).toLocaleString('ja-JP') : '';

    node.append(expBtn, span, time);
    return { node, expBtn, span };
  }

  function renderFolder(folder, depth) {
    const { node, expBtn, span } = makeRow(folder.name, depth, true, folder.createdAt, 'folder', folder.id);
    root.append(node);

    expBtn.onclick = () => {
      if (collapsed.has(folder.id)) collapsed.delete(folder.id);
      else collapsed.add(folder.id);
      tree();
    };

    span.ondblclick = () => {
      if (locked()) return;
      span.contentEditable = 'true';
      span.focus();
    };

    span.onblur = safe(async () => {
      if (!span.isContentEditable) return;
      span.contentEditable = 'false';
      const val = span.textContent.trim() || folder.name;
      if (val !== folder.name) {
        await api('folder.rename', { id: folder.id, name: val });
        await refreshList();
      }
    });

    span.onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); span.blur(); } };

    node.oncontextmenu = ev => {
      openMenu(ev, [
        ['子フォルダ追加', safe(async () => {
          await api('folder.create', { parentFolderId: folder.id, name: '新しいフォルダ' });
          collapsed.delete(folder.id);
          await refreshList();
        })],
        ['フォルダ削除', () => removeItem('folder', folder.id, folder.name)]
      ]);
    };

    node.ondragover = ev => {
      if (!locked()) {
        ev.preventDefault();
        node.classList.add('drop');
      }
    };
    node.ondragleave = () => node.classList.remove('drop');
    node.ondrop = safe(async ev => {
      ev.preventDefault();
      node.classList.remove('drop');
      const vId = ev.dataTransfer.getData('text/plain');
      if (!vId) return;
      await api('video.move', { id: vId, folderId: folder.id });
      collapsed.delete(folder.id);
      await refreshList();
    });

    if (collapsed.has(folder.id)) return;

    // 子フォルダ
    serverData.folders.filter(f => f.parentFolderId === folder.id).forEach(cf => renderFolder(cf, depth + 1));

    // 所属動画
    serverData.videos.filter(v => v.folderId === folder.id).forEach(vid => {
      const edits = serverData.edits.filter(e => e.videoId === vid.id);
      const vRow = makeRow(vid.name, depth + 1, edits.length > 0, vid.createdAt, 'video', vid.id);
      vRow.node.draggable = true;

      vRow.node.ondragstart = ev => {
        if (locked() || vRow.span.isContentEditable) ev.preventDefault();
        else ev.dataTransfer.setData('text/plain', vid.id);
      };

      vRow.expBtn.onclick = () => {
        if (collapsed.has(vid.id)) collapsed.delete(vid.id);
        else collapsed.add(vid.id);
        tree();
      };

      vRow.span.onclick = safe(async () => {
        if (!locked() && !vRow.span.isContentEditable) await loadEntity(vid, null, false);
      });

      vRow.span.ondblclick = () => {
        if (locked()) return;
        vRow.span.contentEditable = 'true';
        vRow.span.focus();
      };

      vRow.span.onblur = safe(async () => {
        if (!vRow.span.isContentEditable) return;
        vRow.span.contentEditable = 'false';
        const val = vRow.span.textContent.trim() || vid.name;
        if (val !== vid.name) {
          await api('video.rename', { id: vid.id, name: val });
          await refreshList();
        }
      });

      vRow.span.onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); vRow.span.blur(); } };

      vRow.node.oncontextmenu = ev => {
        openMenu(ev, [
          ['再生する', () => loadEntity(vid, null, false)],
          ['新規編集を開始', () => loadEntity(vid, null, true)],
          ['ダウンロード', () => {
            window.location.href = `index.php?api=v1&action=file.download&id=${encodeURIComponent(vid.originalFileId)}`;
          }],
          ['動画削除', () => removeItem('video', vid.id, vid.name)]
        ]);
      };
      root.append(vRow.node);

      if (collapsed.has(vid.id)) return;

      // 元動画ノード
      const origRow = makeRow('元動画', depth + 2, false, vid.createdAt, 'original', vid.id);
      origRow.span.onclick = safe(async () => { if (!locked()) await loadEntity(vid, null, false); });
      origRow.node.oncontextmenu = ev => {
        openMenu(ev, [
          ['再生する', () => loadEntity(vid, null, false)],
          ['ダウンロード', () => {
            window.location.href = `index.php?api=v1&action=file.download&id=${encodeURIComponent(vid.originalFileId)}`;
          }]
        ]);
      };
      root.append(origRow.node);

      // 編集一覧ノード
      edits.forEach(ed => {
        const edRow = makeRow(ed.name, depth + 2, false, ed.updatedAt, 'edit', ed.id);
        edRow.span.onclick = safe(async () => {
          if (!locked() && !edRow.span.isContentEditable) await loadEntity(vid, ed, true);
        });
        edRow.span.ondblclick = () => {
          if (locked()) return;
          edRow.span.contentEditable = 'true';
          edRow.span.focus();
        };
        edRow.span.onblur = safe(async () => {
          if (!edRow.span.isContentEditable) return;
          edRow.span.contentEditable = 'false';
          const val = edRow.span.textContent.trim() || ed.name;
          if (val !== ed.name) {
            await api('edit.rename', { id: ed.id, name: val });
            await refreshList();
          }
        });
        edRow.span.onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); edRow.span.blur(); } };

        edRow.node.oncontextmenu = ev => {
          openMenu(ev, [
            ['編集する', () => loadEntity(vid, ed, true)],
            ['保存済み動画を再生', async () => {
              if (await loadEntity(vid, ed, false)) {
                if (audio) await audio.resume();
                await video.play();
              }
            }],
            ['ダウンロード', () => {
              const fid = ed.renderedFileId || vid.originalFileId;
              window.location.href = `index.php?api=v1&action=file.download&id=${encodeURIComponent(fid)}`;
            }],
            ['PDF出力', async () => {
              if (await loadEntity(vid, ed, true)) await openPDFDialog();
            }],
            ['編集削除', () => removeItem('edit', ed.id, ed.name)]
          ]);
        };
        root.append(edRow.node);
      });
    });
  }

  serverData.folders.filter(f => !f.parentFolderId).forEach(rootFolder => renderFolder(rootFolder, 0));
}

// 境界スプリッターのドラッグによる割合調整
$('split').onpointerdown = event => {
  if (event.button !== 0) return;
  event.preventDefault();
  const startY = event.clientY;
  const stageEl = $('stage');
  const tlEl = $('timeline');
  const startStageH = stageEl.offsetHeight;
  const startTlH = tlEl.offsetHeight;

  drag(event, ev => {
    const dy = ev.clientY - startY;
    if (editing) {
      const newTlH = clamp(startTlH - dy, 120, 480);
      tlEl.style.height = newTlH + 'px';
    }
    fit();
  });
};

// UIイベントのバインド
$('list').onclick = () => library(true);
$('closeList').onclick = $('veil').onclick = () => library(false);

$('upload').onclick = $('upload2').onclick = () => $('file').click();
$('file').onchange = safe(async event => {
  const f = event.target.files[0];
  event.target.value = '';
  if (!f || !await leavePrompt()) return;

  busy = true;
  updateUI();
  try {
    message('動画をアップロードしています...');
    const defaultFolderId = serverData.folders[0]?.id;
    const res = await api('video.upload', {
      id: uid(),
      folderId: defaultFolderId,
      name: f.name.replace(/\.[^.]+$/, '')
    }, f);

    await refreshList();
    await loadEntity(res.video, null, false);
    message('動画のアップロードが完了しました');
  } finally {
    busy = false;
    updateUI();
  }
});

$('new').onclick = safe(async () => {
  if (await leavePrompt()) {
    clearState();
    library(false);
    updateUI();
    message('「録画開始」ボタンを押して画面共有を開始してください');
  }
});

$('folder').onclick = safe(async () => {
  await api('folder.create', { parentFolderId: null, name: '新しいフォルダ' });
  await refreshList();
});

$('primary').onclick = safe(async () => {
  if (screenStream || !editing) {
    await toggleRecord();
    return;
  }
  commitText();
  if (video.paused) {
    if (audio) await audio.resume();
    if (video.ended) await seek(0);
    await video.play();
  } else {
    video.pause();
  }
  updateUI();
});

$('begin').onclick = safe(async () => {
  if (renderedPlayback) {
    const streamUrl = `index.php?api=v1&action=file.stream&id=${encodeURIComponent(current.originalFileId)}`;
    await setSourceURL(streamUrl);
    renderedPlayback = false;
  }
  editing = true;
  video.pause();
  await seek(0);
  updateUI();
  message('右クリックで各要素を追加できます。コメントは枠内ダブルクリックで直接編集可能です。');
});

$('save').onclick = safe(saveEdit);

$('finish').onclick = safe(async () => {
  if (!await leavePrompt()) return;
  if (revision?.renderedFileId) {
    const streamUrl = `index.php?api=v1&action=file.stream&id=${encodeURIComponent(revision.renderedFileId)}`;
    await setSourceURL(streamUrl);
    renderedPlayback = true;
  } else {
    await seek(0);
  }
  editing = false;
  updateUI();
});

$('download').onclick = safe(downloadFile);
$('pdf').onclick = safe(openPDFDialog);
$('print').onclick = safe(executePDFPrint);
$('cancelPDF').onclick = () => $('pdfDialog').close();

canvas.oncontextmenu = event => elementMenu(event, hit(point(event)), false);
$('tracks').oncontextmenu = event => elementMenu(event, null, true);

$('tracks').onpointerdown = event => {
  if (event.button === 0 && !locked() && !event.target.closest('.clip')) {
    video.pause();
    video.currentTime = timeAtEvent(event);
  }
};

$('handle').onpointerdown = event => {
  if (locked()) return;
  event.stopPropagation();
  video.pause();
  const r = timelineRange();
  drag(event, ev => video.currentTime = timeAtEvent(ev, r));
};

$('scale').oninput = () => { tracks(); paint(); };
$('viewZoom').onchange = () => {
  displayZoom = +$('viewZoom').value;
  fit();
  paint();
};

video.onseeked = () => { tracks(); paint(); };
video.onplay = video.onpause = video.onended = updateUI;
video.onerror = () => message('動画の再生に失敗しました。対応コーデックをご確認ください。');

document.addEventListener('pointerdown', event => {
  if (!event.target.closest('#menu')) $('menu').hidden = true;
});

document.addEventListener('keydown', event => {
  if (event.key === 'Escape') {
    connecting = null;
    $('menu').hidden = true;
  }
});

window.addEventListener('resize', () => { commitText(); updateUI(); });
window.addEventListener('beforeunload', event => {
  if (dirty || screenStream || busy) {
    event.preventDefault();
    event.returnValue = '';
  }
});

// 再生ループとスキップ制御
let lastStart = -1, isSkipping = false;
async function playbackLoop() {
  if (!busy && editing && !video.paused && !isSkipping) {
    const next = getNextPlaybackTime(video.currentTime);
    if (next > video.currentTime) {
      isSkipping = true;
      try {
        await seek(next);
        if (next < duration()) await video.play();
      } catch (err) {
        message(err.message);
      } finally {
        isSkipping = false;
      }
    }
  }
  paint();
  if (editing) {
    const curStart = timelineRange().start;
    if (Math.abs(curStart - lastStart) > 0.05) {
      lastStart = curStart;
      tracks();
    } else {
      updateClock();
    }
  }
  requestAnimationFrame(playbackLoop);
}

// 初期化実行
try {
  await refreshList();
  updateUI();
  requestAnimationFrame(playbackLoop);
} catch (err) {
  message('初期化に失敗しました: ' + err.message);
  document.querySelectorAll('button').forEach(b => b.disabled = true);
}
})();
</script>
</body>
</html>
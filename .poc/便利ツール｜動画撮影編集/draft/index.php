<?php
declare(strict_types=1);

/*
 * 動画撮影編集 POC
 * PHP 8.1+ / Apache 2.4 / HTTPS または localhost
 *
 * アプリコード: このファイルのみ
 * 正式保存先: __DIR__/storage
 * DB / IndexedDB / LocalStorage は使用しない
 *
 * Apache設定例:
 * <Directory "/実際のアプリディレクトリ/storage">
 *     AllowOverride AuthConfig
 *     Require all granted
 * </Directory>
 *
 * storage/.htaccess の Require all denied が実際に適用されること。
 */

const STORE = __DIR__ . '/storage';
const SCHEMA = 1;

final class Fault extends RuntimeException {
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly int $status = 400
    ) {
        parent::__construct($message);
    }
}

function abortApp(string $kind, string $message, int $status = 400): never {
    throw new Fault($kind, $message, $status);
}

function stamp(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

function uuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.
        substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
}

function encode(mixed $data): string {
    return json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );
}

function readStore(string $name): array {
    $text = @file_get_contents(STORE.'/'.$name);
    if ($text === false) {
        abortApp('STORAGE_ERROR', '管理データを読み込めません。', 500);
    }
    try {
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        abortApp('STORAGE_ERROR', '管理データが破損しています。', 500);
    }
    if (!is_array($data)) {
        abortApp('STORAGE_ERROR', '管理データの形式が不正です。', 500);
    }
    return $data;
}

function writeStore(string $name, array $data): void {
    $temp = tempnam(STORE.'/tmp', 'json-');
    if ($temp === false) {
        abortApp('SAVE_FAILED', '一時ファイルを作成できません。', 500);
    }
    $stream = null;
    try {
        $text = encode($data);
        $stream = @fopen($temp, 'wb');
        if (!$stream) {
            abortApp('SAVE_FAILED', '管理データを書き込めません。', 500);
        }
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length) {
            $written = fwrite($stream, substr($text, $offset));
            if ($written === false || $written === 0) {
                abortApp('SAVE_FAILED', '管理データの書き込みに失敗しました。', 500);
            }
            $offset += $written;
        }
        if (!fflush($stream) || !fsync($stream)) {
            abortApp('SAVE_FAILED', '保存データを同期できません。', 500);
        }
        fclose($stream);
        $stream = null;
        if (!@rename($temp, STORE.'/'.$name)) {
            abortApp('SAVE_FAILED', '管理データを確定できません。', 500);
        }
    } finally {
        if (is_resource($stream)) fclose($stream);
        if (is_file($temp)) @unlink($temp);
    }
}

function validId(mixed $value): string {
    if (!is_string($value) ||
        !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $value)) {
        abortApp('INVALID_INPUT', 'IDが不正です。');
    }
    return $value;
}

function validName(mixed $value): string {
    if (!is_string($value) || trim($value) === '' || strlen($value) > 600) {
        abortApp('INVALID_INPUT', '名前が不正です。');
    }
    return trim($value);
}

function numeric(mixed $value): float {
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
        abortApp('INVALID_INPUT', '数値が不正です。');
    }
    return (float)$value;
}

function locate(array $rows, string $id): int {
    foreach ($rows as $index => $row) {
        if (($row['id'] ?? null) === $id) return $index;
    }
    abortApp('NOT_FOUND', '対象がありません。', 404);
}

function pathOf(array $file): string {
    $path = $file['storagePath'] ?? null;
    if (!is_string($path) ||
        !preg_match('~^files/[a-f0-9-]{36}\.(mp4|webm|mov)$~i', $path)) {
        abortApp('STORAGE_ERROR', '保存ファイル情報が不正です。', 500);
    }
    return STORE.'/'.$path;
}

function normalize(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    foreach ($value as $key => $item) $value[$key] = normalize($item);
    return $value;
}

function enumValue(mixed $value, array $allowed): void {
    if (!in_array($value, $allowed, true)) {
        abortApp('INVALID_INPUT', '書式値が不正です。');
    }
}

function colorValue(mixed $value): void {
    if (!is_string($value) ||
        !preg_match('/^(#[a-f0-9]{6}|transparent)$/i', $value)) {
        abortApp('INVALID_INPUT', '色が不正です。');
    }
}

function validateEdit(mixed $data): array {
    if (!is_array($data) ||
        ($data['schemaVersion'] ?? null) !== SCHEMA ||
        ($data['coordinateSystem'] ?? null) !== 'source-pixels' ||
        !is_array($data['elements'] ?? null) ||
        !array_is_list($data['elements'])) {
        abortApp('INVALID_INPUT', '編集データ形式が不正です。');
    }
    $width = numeric($data['sourceWidth'] ?? null);
    $height = numeric($data['sourceHeight'] ?? null);
    if ($width <= 0 || $height <= 0) {
        abortApp('INVALID_INPUT', '元動画のサイズが不正です。');
    }

    $map = [];
    foreach ($data['elements'] as $element) {
        if (!is_array($element)) abortApp('INVALID_INPUT', '編集要素が不正です。');
        $id = validId($element['id'] ?? null);
        if (isset($map[$id])) abortApp('INVALID_INPUT', '要素IDが重複しています。');
        $type = $element['type'] ?? null;
        enumValue($type, ['A','B','C','D','E','F']);

        if ($type === 'F') {
            if (numeric($element['time'] ?? null) < 0) {
                abortApp('INVALID_INPUT', 'PDF位置が不正です。');
            }
        } elseif ($type !== 'E') {
            $start = numeric($element['start'] ?? null);
            $end = numeric($element['end'] ?? null);
            if ($start < 0 || $end <= $start) {
                abortApp('INVALID_INPUT', '表示区間が不正です。');
            }
        }

        if (in_array($type, ['A','B','C'], true)) {
            foreach (['x','y','w','h'] as $key) {
                if (numeric($element[$key] ?? null) < 0) {
                    abortApp('INVALID_INPUT', '座標が不正です。');
                }
            }
            if ($element['w'] <= 0 || $element['h'] <= 0 ||
                $element['x'] + $element['w'] > $width + .001 ||
                $element['y'] + $element['h'] > $height + .001) {
                abortApp('INVALID_INPUT', '図形が動画領域を超えています。');
            }
            enumValue($element['shape'] ?? null, ['rect','ellipse','round']);
            colorValue($element['background'] ?? null);
        }

        if (in_array($type, ['A','B','C','E'], true)) {
            colorValue($element['stroke'] ?? null);
            if (numeric($element['width'] ?? null) < 0) {
                abortApp('INVALID_INPUT', '線幅が不正です。');
            }
            enumValue($element['dash'] ?? null, ['solid','dash','dot','chain']);
        }

        if ($type === 'A') {
            $size = numeric($element['size'] ?? null);
            if (!is_string($element['text'] ?? null) || $size < 10 || $size > 40) {
                abortApp('INVALID_INPUT', 'コメントが不正です。');
            }
            enumValue($element['font'] ?? null, ['gothic','mincho']);
            colorValue($element['color'] ?? null);
        }
        if ($type === 'C' && numeric($element['zoom'] ?? null) < 1) {
            abortApp('INVALID_INPUT', '倍率は1以上にしてください。');
        }
        if ($type === 'E') {
            validId($element['ownerId'] ?? null);
            validId($element['commentId'] ?? null);
            enumValue($element['line'] ?? null, ['straight','orthogonal','wave']);
            enumValue($element['terminal'] ?? null, ['none','arrow','circle']);
        }
        $map[$id] = $element;
    }

    foreach ($map as $element) {
        if ($element['type'] !== 'E') continue;
        $owner = $map[$element['ownerId']] ?? null;
        $comment = $map[$element['commentId']] ?? null;
        if (!$owner || !$comment ||
            !in_array($owner['type'], ['B','C'], true) || $comment['type'] !== 'A') {
            abortApp('INVALID_INPUT', '接続先が不正です。');
        }
        foreach (['from' => $owner, 'to' => $comment] as $key => $target) {
            $port = $element[$key] ?? null;
            if (!is_array($port)) abortApp('INVALID_INPUT', '接続位置が不正です。');
            if ($target['shape'] === 'ellipse') {
                numeric($port['angle'] ?? null);
            } else {
                enumValue($port['side'] ?? null, ['left','right','top','bottom']);
                $ratio = numeric($port['ratio'] ?? null);
                if ($ratio < 0 || $ratio > 1) {
                    abortApp('INVALID_INPUT', '接続比率が不正です。');
                }
            }
        }
    }
    return $data;
}

function uploadedFile(): array {
    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) ||
        ($upload['error'] ?? -1) !== UPLOAD_ERR_OK ||
        !is_uploaded_file($upload['tmp_name'])) {
        abortApp('UPLOAD_FAILED', '動画を受信できません。容量設定も確認してください。');
    }
    if (!class_exists('finfo')) {
        abortApp('SERVER_CONFIGURATION', 'PHPのfileinfo拡張が必要です。', 500);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extension = match ($mime) {
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        default => null
    };
    if (!$extension) abortApp('INVALID_INPUT', 'MP4、WebM、MOVを指定してください。');
    $id = uuid();
    $temp = STORE.'/tmp/'.$id;
    if (!move_uploaded_file($upload['tmp_name'], $temp)) {
        abortApp('SAVE_FAILED', '動画を一時保存できません。', 500);
    }
    $size = filesize($temp);
    if ($size === false || $size <= 0) {
        @unlink($temp);
        abortApp('INVALID_INPUT', '動画が空です。');
    }
    return [
        'temp' => $temp,
        'file' => [
            'id' => $id,
            'storagePath' => "files/$id.$extension",
            'mimeType' => $mime,
            'size' => $size,
            'createdAt' => stamp()
        ]
    ];
}

function recoverTransaction(): void {
    if (!is_file(STORE.'/transaction.json')) return;
    $transaction = readStore('transaction.json');
    if (($transaction['metadata']['schemaVersion'] ?? null) !== SCHEMA) {
        abortApp('SCHEMA_UNSUPPORTED', '復旧データの形式が未対応です。', 409);
    }
    foreach ([
        'metadata.json' => 'metadata',
        'requests.json' => 'requests',
        'deletion-retry.json' => 'deletions'
    ] as $name => $key) {
        if (!is_array($transaction[$key] ?? null)) {
            abortApp('STORAGE_ERROR', '復旧記録が不正です。', 500);
        }
        writeStore($name, $transaction[$key]);
    }
    if (!@unlink(STORE.'/transaction.json')) {
        abortApp('STORAGE_ERROR', '復旧記録を確定できません。', 500);
    }
}

function cleanFiles(array $metadata): void {
    $used = [];
    foreach ($metadata['files'] as $file) $used[pathOf($file)] = true;
    $retry = [];
    foreach (glob(STORE.'/files/*') ?: [] as $path) {
        if (!is_file($path) || isset($used[$path])) continue;
        if (@unlink($path)) continue;
        if (preg_match('/^([a-f0-9-]{36})\.(mp4|webm|mov)$/i', basename($path), $match)) {
            $retry[] = [
                'id' => $match[1],
                'storagePath' => 'files/'.basename($path),
                'mimeType' => 'application/octet-stream',
                'size' => filesize($path) ?: 0,
                'createdAt' => stamp()
            ];
        }
    }
    foreach (glob(STORE.'/tmp/*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
    }
    writeStore('deletion-retry.json', $retry);
}

function publicData(array $metadata): array {
    foreach ($metadata['files'] as &$file) unset($file['storagePath']);
    unset($file);
    return $metadata;
}

function respond(array $data, ?string $requestId = null): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo encode(['ok' => true, 'requestId' => $requestId, 'data' => $data]);
    exit;
}

$lock = null;
$requestId = null;

try {
    foreach ([STORE, STORE.'/files', STORE.'/tmp'] as $directory) {
        if (!is_dir($directory) &&
            !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            abortApp('STORAGE_ERROR', '保存領域を作成できません。', 500);
        }
        if (!is_readable($directory) || !is_writable($directory)) {
            abortApp('STORAGE_ERROR', '保存領域の読み書き権限が必要です。', 500);
        }
    }

    $deny = "Require all denied\n";
    if (!is_file(STORE.'/.htaccess') &&
        @file_put_contents(STORE.'/.htaccess', $deny) !== strlen($deny)) {
        abortApp('STORAGE_ERROR', 'アクセス制御を作成できません。', 500);
    }
    if (trim((string)@file_get_contents(STORE.'/.htaccess')) !== trim($deny)) {
        abortApp('STORAGE_ERROR', 'storageのアクセス制御が不正です。', 500);
    }

    $lock = @fopen(STORE.'/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        abortApp('STORAGE_ERROR', '排他制御に失敗しました。', 500);
    }

    recoverTransaction();

    if (!is_file(STORE.'/metadata.json')) {
        $time = stamp();
        writeStore('metadata.json', [
            'schemaVersion' => SCHEMA,
            'folders' => [[
                'id' => uuid(),
                'parentFolderId' => null,
                'name' => 'デフォルトフォルダ',
                'createdAt' => $time,
                'updatedAt' => $time
            ]],
            'videos' => [], 'edits' => [], 'files' => []
        ]);
    }
    foreach (['requests.json','deletion-retry.json'] as $name) {
        if (!is_file(STORE.'/'.$name)) writeStore($name, []);
    }

    $metadata = readStore('metadata.json');
    if (($metadata['schemaVersion'] ?? null) !== SCHEMA) {
        abortApp('SCHEMA_UNSUPPORTED', '未対応の保存形式です。上書きしません。', 409);
    }
    foreach (['folders','videos','edits','files'] as $key) {
        if (!is_array($metadata[$key] ?? null) || !array_is_list($metadata[$key])) {
            abortApp('STORAGE_ERROR', '管理データ構造が不正です。', 500);
        }
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (isset($_GET['api'])) {
        if ($_GET['api'] !== 'v1') abortApp('NOT_FOUND', 'APIがありません。', 404);
        $action = (string)($_GET['action'] ?? '');

        if ($method === 'GET') {
            if ($action === 'list') respond(publicData($metadata));
            if ($action === 'edit.get') {
                respond($metadata['edits'][locate(
                    $metadata['edits'], validId($_GET['id'] ?? null)
                )]);
            }
            if (!in_array($action, ['file.stream','file.download'], true)) {
                abortApp('NOT_FOUND', '操作がありません。', 404);
            }

            $file = $metadata['files'][locate(
                $metadata['files'], validId($_GET['id'] ?? null)
            )];
            $path = pathOf($file);
            $stream = @fopen($path, 'rb');
            if (!$stream) abortApp('NOT_FOUND', '動画ファイルがありません。', 404);
            $size = filesize($path);
            if (!$size) abortApp('STORAGE_ERROR', '動画が空です。', 500);
            $start = 0;
            $end = $size - 1;

            if (isset($_SERVER['HTTP_RANGE'])) {
                if (!preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $parts) ||
                    ($parts[1] === '' && $parts[2] === '')) {
                    header("Content-Range: bytes */$size");
                    abortApp('INVALID_RANGE', 'Rangeが不正です。', 416);
                }
                if ($parts[1] === '') {
                    $suffix = (int)$parts[2];
                    $start = max(0, $size - $suffix);
                    if ($suffix <= 0) $start = $size;
                } else {
                    $start = (int)$parts[1];
                    if ($parts[2] !== '') $end = min($end, (int)$parts[2]);
                }
                if ($start > $end || $start >= $size) {
                    header("Content-Range: bytes */$size");
                    abortApp('INVALID_RANGE', 'Rangeが範囲外です。', 416);
                }
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$size");
            }

            header('Content-Type: '.$file['mimeType']);
            header('X-Content-Type-Options: nosniff');
            header('Accept-Ranges: bytes');
            header('Content-Length: '.($end - $start + 1));
            if ($action === 'file.download') {
                $display = '動画';
                foreach ($metadata['videos'] as $item) {
                    if ($item['originalFileId'] === $file['id']) $display = $item['name'];
                }
                foreach ($metadata['edits'] as $item) {
                    if ($item['renderedFileId'] === $file['id']) $display = $item['name'];
                }
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                header(
                    "Content-Disposition: attachment; filename=\"video.$extension\"; ".
                    "filename*=UTF-8''".rawurlencode($display.'.'.$extension)
                );
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            $lock = null;
            fseek($stream, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !connection_aborted()) {
                $chunk = fread($stream, min(1048576, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($stream);
            exit;
        }

        if ($method !== 'POST') {
            abortApp('METHOD_NOT_ALLOWED', 'POSTを使用してください。', 405);
        }
        try {
            $request = json_decode(
                (string)($_POST['metadata'] ?? file_get_contents('php://input')),
                true, 512, JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            abortApp('INVALID_INPUT', '要求JSONが不正です。');
        }
        $requestId = validId($request['requestId'] ?? null);
        $payload = $request['payload'] ?? null;
        if (!is_array($payload)) abortApp('INVALID_INPUT', '要求内容が不正です。');

        $fileHash = null;
        if (isset($_FILES['file']) &&
            ($_FILES['file']['error'] ?? -1) === UPLOAD_ERR_OK &&
            is_uploaded_file($_FILES['file']['tmp_name'])) {
            $fileHash = hash_file('sha256', $_FILES['file']['tmp_name']);
        }
        $hash = hash('sha256', encode(normalize([$action, $payload, $fileHash])));
        $requests = readStore('requests.json');
        if (isset($requests[$requestId])) {
            if ($requests[$requestId]['hash'] !== $hash) {
                abortApp('REQUEST_CONFLICT', '同じ要求IDの内容が異なります。', 409);
            }
            respond($requests[$requestId]['data'], $requestId);
        }

        cleanFiles($metadata);
        $entityId = validId($payload['id'] ?? null);
        $time = stamp();
        $result = ['id' => $entityId];
        $removed = [];
        $incoming = null;

        try {
            if ($action === 'folder.create') {
                foreach ($metadata['folders'] as $folder) {
                    if ($folder['id'] === $entityId) {
                        abortApp('CONFLICT', 'フォルダIDが存在します。', 409);
                    }
                }
                if (!array_key_exists('parentFolderId', $payload)) {
                    abortApp('INVALID_INPUT', '親フォルダ指定が必要です。');
                }
                $parent = $payload['parentFolderId'];
                if ($parent !== null) locate($metadata['folders'], validId($parent));
                $metadata['folders'][] = [
                    'id' => $entityId, 'parentFolderId' => $parent,
                    'name' => validName($payload['name'] ?? null),
                    'createdAt' => $time, 'updatedAt' => $time
                ];
            } elseif (in_array($action, [
                'folder.rename','video.rename','edit.rename'
            ], true)) {
                $collection = explode('.', $action)[0].'s';
                $index = locate($metadata[$collection], $entityId);
                $metadata[$collection][$index]['name'] = validName($payload['name'] ?? null);
                $metadata[$collection][$index]['updatedAt'] = $time;
            } elseif ($action === 'video.move') {
                $index = locate($metadata['videos'], $entityId);
                $folderId = validId($payload['folderId'] ?? null);
                locate($metadata['folders'], $folderId);
                $metadata['videos'][$index]['folderId'] = $folderId;
                $metadata['videos'][$index]['updatedAt'] = $time;
            } elseif ($action === 'video.upload') {
                foreach ($metadata['videos'] as $item) {
                    if ($item['id'] === $entityId) {
                        abortApp('CONFLICT', '動画IDが存在します。', 409);
                    }
                }
                $folderId = validId($payload['folderId'] ?? null);
                locate($metadata['folders'], $folderId);
                $name = validName($payload['name'] ?? null);
                $incoming = uploadedFile();
                $metadata['files'][] = $incoming['file'];
                $metadata['videos'][] = [
                    'id' => $entityId, 'folderId' => $folderId, 'name' => $name,
                    'originalFileId' => $incoming['file']['id'],
                    'createdAt' => $time, 'updatedAt' => $time
                ];
            } elseif ($action === 'edit.save') {
                $videoId = validId($payload['videoId'] ?? null);
                locate($metadata['videos'], $videoId);
                $data = validateEdit($payload['editData'] ?? null);
                $name = validName($payload['name'] ?? null);
                $index = null;
                foreach ($metadata['edits'] as $key => $item) {
                    if ($item['id'] === $entityId) $index = $key;
                }
                $version = $index === null ? 0 : $metadata['edits'][$index]['version'];
                if (($payload['expectedVersion'] ?? null) !== $version ||
                    ($index !== null && $metadata['edits'][$index]['videoId'] !== $videoId)) {
                    abortApp('VERSION_CONFLICT', '編集版が競合しました。再取得してください。', 409);
                }
                $incoming = uploadedFile();
                $metadata['files'][] = $incoming['file'];
                $result = [
                    'id' => $entityId, 'videoId' => $videoId, 'name' => $name,
                    'version' => $version + 1, 'editData' => $data,
                    'renderedFileId' => $incoming['file']['id'],
                    'createdAt' => $index === null ? $time : $metadata['edits'][$index]['createdAt'],
                    'updatedAt' => $time
                ];
                if ($index === null) {
                    $metadata['edits'][] = $result;
                } else {
                    $removed[] = $metadata['edits'][$index]['renderedFileId'];
                    $metadata['edits'][$index] = $result;
                }
            } elseif (in_array($action, [
                'folder.delete','video.delete','edit.delete'
            ], true)) {
                $folders = [];
                $videos = [];
                $edits = [];
                if ($action === 'folder.delete') {
                    locate($metadata['folders'], $entityId);
                    $folders = [$entityId];
                    do {
                        $count = count($folders);
                        foreach ($metadata['folders'] as $folder) {
                            if (in_array($folder['parentFolderId'], $folders, true) &&
                                !in_array($folder['id'], $folders, true)) {
                                $folders[] = $folder['id'];
                            }
                        }
                    } while ($count !== count($folders));
                    foreach ($metadata['videos'] as $item) {
                        if (in_array($item['folderId'], $folders, true)) $videos[] = $item['id'];
                    }
                } elseif ($action === 'video.delete') {
                    locate($metadata['videos'], $entityId);
                    $videos = [$entityId];
                } else {
                    locate($metadata['edits'], $entityId);
                    $edits = [$entityId];
                }
                foreach ($metadata['videos'] as $item) {
                    if (in_array($item['id'], $videos, true)) $removed[] = $item['originalFileId'];
                }
                foreach ($metadata['edits'] as $item) {
                    if (in_array($item['videoId'], $videos, true) ||
                        in_array($item['id'], $edits, true)) {
                        $edits[] = $item['id'];
                        $removed[] = $item['renderedFileId'];
                    }
                }
                foreach (['folders' => $folders, 'videos' => $videos, 'edits' => $edits] as $key => $ids) {
                    $metadata[$key] = array_values(array_filter(
                        $metadata[$key], fn($item) => !in_array($item['id'], $ids, true)
                    ));
                }
            } else {
                abortApp('NOT_FOUND', '操作がありません。', 404);
            }

            $deletions = readStore('deletion-retry.json');
            foreach ($metadata['files'] as $file) {
                if (in_array($file['id'], $removed, true)) $deletions[] = $file;
            }
            $metadata['files'] = array_values(array_filter(
                $metadata['files'], fn($file) => !in_array($file['id'], $removed, true)
            ));
            if ($incoming && !@rename($incoming['temp'], pathOf($incoming['file']))) {
                abortApp('SAVE_FAILED', '動画を確定できません。', 500);
            }
            $requests[$requestId] = ['hash' => $hash, 'data' => $result];

            writeStore('transaction.json', [
                'metadata' => $metadata, 'requests' => $requests, 'deletions' => $deletions
            ]);
            recoverTransaction();
        } catch (Throwable $error) {
            if ($incoming && !is_file(STORE.'/transaction.json')) {
                @unlink($incoming['temp']);
                @unlink(pathOf($incoming['file']));
            }
            throw $error;
        }
        respond($result, $requestId);
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = null;
} catch (Throwable $error) {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log((string)$error);
    http_response_code($error instanceof Fault ? $error->status : 500);
    $message = $error instanceof Fault ? $error->getMessage() : 'サーバ処理に失敗しました。';
    if (isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo encode([
            'ok' => false, 'requestId' => $requestId,
            'error' => [
                'code' => $error instanceof Fault ? $error->kind : 'SERVER_ERROR',
                'message' => $message
            ]
        ]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>起動エラー</h1><p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p>';
    }
    exit;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="data:,">
<title>動画撮影・編集</title>
<style>
*{box-sizing:border-box}
body{margin:0;font:12px Meiryo,sans-serif;color:#243247;background:#eef2f7}
button,input,select{font:inherit}
button{height:26px;padding:0 9px;border:1px solid #ccd6e3;border-radius:4px;background:#fff;cursor:pointer;white-space:nowrap}
button:hover{background:#edf4ff}
button:disabled{opacity:.45;cursor:default}
header,.toolbar{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
header{padding:7px 10px;background:#fff;border-bottom:1px solid #ccd6e3}
header strong{font-size:14px;margin-right:auto}
#workspace{height:calc(100dvh - 42px);min-height:400px;display:flex;flex-direction:column;padding:7px;gap:5px}
#stage{flex:1;min-height:100px;background:#172235;overflow:auto;position:relative}
#empty{position:absolute;inset:0;display:grid;place-content:center;text-align:center;color:#ccd8e8;line-height:2}
#surface{position:relative;margin:auto}
#video{display:block;width:100%;height:100%}
#overlay{position:absolute;inset:0;width:100%;height:100%;touch-action:none}
#actions{justify-content:center}
#primary{background:#2563eb;color:#fff}
#status{min-height:18px;text-align:center;white-space:pre-wrap;color:#607089}
#split{height:7px;flex:none;background:#c3cfdd;cursor:ns-resize;touch-action:none}
#timeline{height:190px;flex:none;background:#fff;border:1px solid #ccd6e3;display:flex;flex-direction:column;min-height:90px}
#timeline .toolbar{padding:5px 8px;border-bottom:1px solid #ccd6e3}
#timeline small{margin-left:auto}
#scroll{flex:1;overflow-y:auto;overflow-x:hidden}
#tracks{position:relative;min-height:100px;touch-action:none}
.tick{position:absolute;top:0;height:22px;border-left:1px solid #dde4ed;font-size:10px;color:#64748b}
.clip{position:absolute;height:24px;border:1px solid #91b5e6;background:#e0ecff;border-radius:3px;padding:3px 8px;overflow:hidden;white-space:nowrap;cursor:move;user-select:none;touch-action:none}
.clip.B{background:#fff1bd}.clip.C{background:#d9f3e6}.clip.D{background:#ffe2e6}.clip.F{background:#eee2ff}
.clip.selected{outline:2px solid #2563eb;outline-offset:-2px}
.edge{position:absolute;top:0;bottom:0;width:7px;background:#0002;cursor:ew-resize}
.edge.start{left:0}.edge.end{right:0}
#playhead{position:absolute;top:0;bottom:0;width:2px;background:#e44972;pointer-events:none;z-index:4}
#handle{position:absolute;left:-6px;width:14px;height:20px;background:#e44972;clip-path:polygon(0 0,100% 0,100% 65%,50% 100%,0 65%);pointer-events:auto;cursor:ew-resize;touch-action:none}
#veil{position:fixed;inset:0;background:#0005;z-index:10}
#library{position:fixed;inset:0 auto 0 0;width:min(550px,100vw);background:#fff;z-index:11;display:flex;flex-direction:column;box-shadow:4px 0 20px #0003}
#library .toolbar{padding:7px;border-bottom:1px solid #ccd6e3}
#tree{flex:1;overflow:auto;padding:7px}
.row{display:flex;align-items:center;gap:5px;min-height:28px;padding:3px;border-radius:3px}
.row:hover,.row.active{background:#edf4ff}
.row.drop{outline:2px dashed #2563eb}
.row button{width:20px;padding:0;border:0}
.name{flex:1;overflow-wrap:anywhere;cursor:pointer}
.name[contenteditable=true]{outline:1px solid #2563eb;background:#fff}
.row time{font-size:10px;color:#64748b}
#menu{position:fixed;z-index:30;background:#fff;border:1px solid #ccd6e3;border-radius:4px;padding:6px;max-height:85vh;max-width:95vw;overflow:auto;box-shadow:0 5px 20px #0003}
#menu>button{display:block;width:100%;text-align:left;border:0}
#menu label{display:flex;justify-content:space-between;gap:12px;padding:5px}
.palette,.weights{display:flex;gap:5px;flex-wrap:wrap;padding:5px}
.palette{max-width:175px}
.palette button{width:23px;height:23px;padding:0}
.weights button{width:35px;padding:0}
.weights span{display:block;width:24px;margin:auto;background:#243247}
.textEditor{position:absolute;white-space:pre-wrap;overflow:auto;outline:2px solid #60a5fa;padding:6px;z-index:6}
dialog{max-width:92vw;border:1px solid #ccd6e3;border-radius:5px}
dialog::backdrop{background:#0005}
dialog label{display:block;margin:12px 0}
[hidden]{display:none!important}
#printArea{display:none}
@media(max-width:600px){.row time,#timeline small{display:none}header strong{width:100%}#workspace{height:calc(100dvh - 70px)}}
@media print{
@page{size:A4 portrait;margin:10mm}
body>*:not(#printArea){display:none!important}
#printArea{display:block}
.sheet{height:275mm;display:flex;flex-direction:column;gap:5mm;break-after:page}
.sheet:last-child{break-after:auto}
.shot{flex:1;min-height:0;display:flex;flex-direction:column;justify-content:center;align-items:center}
.shot img{max-width:100%;object-fit:contain}
.shot p{font-size:10pt;margin:2mm 0}
}
</style>
</head>
<body>
<header>
<strong>動画撮影・編集</strong>
<button id="list" disabled>動画一覧</button>
<button id="upload" disabled>動画をアップする</button>
<span id="zoomLabel">動画倍率</span>
<input id="viewZoom" type="range" min="25" max="200" value="100" aria-label="動画表示倍率">
</header>
<div id="workspace">
<section id="stage">
<div id="empty">録画開始で共有する画面を選択してください。<br>保存済み動画は動画一覧から開けます。</div>
<div id="surface" hidden><video id="video" playsinline preload="auto"></video><canvas id="overlay"></canvas></div>
</section>
<div id="actions" class="toolbar">
<button id="primary" disabled>録画開始</button>
<button id="begin" hidden>編集開始</button>
<button id="save" hidden>編集結果保存</button>
<button id="download" hidden>ダウンロード</button>
<button id="pdf" hidden>PDF出力</button>
<button id="finish" hidden>編集作業終了</button>
</div>
<div id="status" role="status" aria-live="polite">起動確認中です…</div>
<div id="split" hidden></div>
<section id="timeline" hidden>
<div class="toolbar">
<strong id="clock">0.0 / 0.0 秒</strong>
<label>タイムスケール <input id="scale" type="range" min="1" max="100" value="100"></label>
<small>右クリック：追加・書式・削除　Ctrl／Shift：複数選択</small>
</div>
<div id="scroll"><div id="tracks"><div id="playhead"><div id="handle"></div></div></div></div>
</section>
</div>
<div id="veil" hidden></div>
<aside id="library" hidden>
<div class="toolbar">
<button id="new">新たに画面を録画する</button>
<button id="upload2">動画をアップする</button>
<button id="folder">＋フォルダ</button>
</div>
<div id="tree"></div>
<div class="toolbar"><button id="closeList">動画一覧を閉じる</button><small>ダブルクリックで名前変更・右クリックで操作</small></div>
</aside>
<input id="file" type="file" accept="video/mp4,video/webm,video/quicktime" hidden>
<div id="menu" hidden></div>
<dialog id="pdfDialog">
<h3>PDF出力</h3>
<label><input type="radio" name="layout" value="1" checked>1ページに1画面</label>
<label><input type="radio" name="layout" value="2">1ページに2画面</label>
<button id="print">印刷へ進む</button>
<button id="cancelPDF">キャンセル</button>
</dialog>
<div id="printArea"></div>
<script>
'use strict';

const $ = id => document.getElementById(id);
const video = $('video');
const canvas = $('overlay');
const ctx = canvas.getContext('2d');
const copy = value => structuredClone(value);
const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
const uid = () => crypto.randomUUID();
const titles = {A:'コメント',B:'強調枠',C:'拡大枠',D:'スキップ',E:'接続線',F:'PDF位置'};
const colors = ['#FFFFFF','#111827','#EF4444','#F97316','#EAB308','#22C55E','#14B8A6','#2563EB','#8B5CF6','#EC4899','#64748B','transparent'];
const dashPatterns = {solid:[],dash:[10,6],dot:[2,5],chain:[12,5,2,5]};
const fonts = {gothic:'Meiryo,sans-serif',mincho:'"Yu Mincho",serif'};
const order = {C:0,B:1,A:2,E:3};

const state = {
    ready:false,busy:false,model:null,video:null,edit:null,data:null,
    editing:false,dirty:false,rendered:false,duration:0,
    selected:new Set(),collapsed:new Set(),folderId:null,
    recording:null,textEditor:null,connecting:null,pending:null,
    audio:null,source:null,skipping:false,exporting:false
};

function message(text){ $('status').textContent = text; }
function elements(){ return state.data?.elements || []; }
function find(id){ return elements().find(element => element.id === id); }
function locked(){ return !state.ready || state.busy || !!state.recording; }
function apiURL(action,id=null){
    const url = new URL(location.href);
    url.search = '';
    url.searchParams.set('api','v1');
    url.searchParams.set('action',action);
    if(id) url.searchParams.set('id',id);
    return url.href;
}

async function fetchTimed(url,options={},timeout=30000){
    const controller = new AbortController();
    const timer = setTimeout(()=>controller.abort(),timeout);
    try{
        return await fetch(url,{...options,signal:controller.signal});
    }catch(error){
        if(error.name==='AbortError') throw Error('通信がタイムアウトしました。');
        throw error;
    }finally{ clearTimeout(timer); }
}

async function transmit(packet){
    const options = {cache:'no-store'};
    if(packet.request){
        options.method = 'POST';
        if(packet.file){
            const body = new FormData();
            body.append('metadata',JSON.stringify(packet.request));
            body.append('file',packet.file,'video');
            options.body = body;
        }else{
            options.headers = {'Content-Type':'application/json'};
            options.body = JSON.stringify(packet.request);
        }
    }
    const response = await fetchTimed(apiURL(packet.action,packet.id),options,packet.file?120000:30000);
    let result;
    try{ result = await response.json(); }
    catch{ throw Error('サーバ応答がJSONではありません。'); }
    if(!response.ok || !result.ok){
        if(state.pending===packet) state.pending = null;
        const error = Error(result.error?.message || '要求に失敗しました。');
        error.code = result.error?.code;
        throw error;
    }
    if(state.pending===packet) state.pending = null;
    return result.data;
}

async function api(action,payload=null,file=null,id=null){
    if(payload && state.pending){
        throw Error('結果未確認の保存要求があります。画面下部の「同じ要求を再試行」を押してください。');
    }
    const packet = {
        action,id,file,
        request:payload?{requestId:uid(),payload}:null
    };
    if(payload) state.pending = packet;
    return transmit(packet);
}

function update(){
    const disabled = locked();
    const loaded = !!state.video;
    $('list').disabled = $('upload').disabled = disabled;
    $('primary').disabled = !state.ready || state.busy;
    $('primary').textContent = state.recording?'録画停止':
        state.editing?(video.paused?'編集再生':'編集再生停止'):'録画開始';
    $('begin').hidden = !loaded || state.editing || !!state.recording;
    $('save').hidden = $('finish').hidden = !state.editing;
    $('download').hidden = $('pdf').hidden = !loaded || !!state.recording;
    for(const id of ['begin','save','finish','download','pdf','new','upload2','folder']){
        $(id).disabled = disabled;
    }
    $('download').disabled ||= state.dirty;
    $('timeline').hidden = $('split').hidden = !state.editing;
    $('surface').hidden = !video.src && !video.srcObject;
    $('empty').hidden = !$('surface').hidden;
    fit();
    if(state.editing) renderTracks();
}

function recoveryButton(){
    if(!state.pending) return;
    const button = document.createElement('button');
    button.textContent = '同じ要求を再試行';
    button.onclick = ()=>run(async()=>{
        const packet = state.pending;
        if(!packet) return;
        const result = await transmit(packet);
        if(packet.action==='edit.save'){
            state.edit = result;
            state.data = copy(result.editData);
            state.dirty = false;
        }
        await refresh();
        message('要求の保存結果を確認しました。');
    });
    $('status').append(document.createElement('br'),button);
}

async function run(task){
    if(state.busy || !state.ready) return;
    state.busy = true;
    update();
    try{ await task(); }
    catch(error){
        console.error(error);
        message(error.message || '操作に失敗しました。');
        recoveryButton();
    }finally{
        state.busy = false;
        update();
    }
}

async function refresh(){
    state.model = await api('list');
    if(state.model.schemaVersion!==1) throw Error('未対応のデータ形式です。');
    if(!state.model.folders.some(folder=>folder.id===state.folderId)){
        state.folderId = state.model.folders[0]?.id || null;
    }
    renderLibrary();
}

function library(open){
    $('library').hidden = $('veil').hidden = !open;
}

function commitText(){
    if(!state.textEditor) return;
    const editor = state.textEditor;
    state.textEditor = null;
    editor.element.text = editor.node.innerText;
    editor.node.remove();
    state.dirty = true;
}

function mayLeave(){
    commitText();
    if(state.pending) throw Error('結果未確認の要求を先に再試行してください。');
    return !state.dirty || confirm('未保存の編集を破棄しますか？');
}

function reset(){
    commitText();
    video.pause();
    video.srcObject = null;
    video.removeAttribute('src');
    video.load();
    Object.assign(state,{
        video:null,edit:null,data:null,editing:false,
        dirty:false,rendered:false,duration:0,connecting:null
    });
    state.selected.clear();
    $('scale').value = 100;
    update();
}

function mediaWait(events,test,action,timeout=30000){
    return new Promise((resolve,reject)=>{
        const clean = ()=>{
            clearTimeout(timer);
            events.forEach(event=>video.removeEventListener(event,check));
            video.removeEventListener('error',fail);
        };
        const check = ()=>{ if(test()){ clean(); resolve(); } };
        const fail = ()=>{ clean(); reject(Error('動画を読み込めません。')); };
        const timer = setTimeout(()=>{
            clean(); reject(Error('動画処理がタイムアウトしました。'));
        },timeout);
        events.forEach(event=>video.addEventListener(event,check));
        video.addEventListener('error',fail);
        try{ action?.(); check(); }catch(error){ clean(); reject(error); }
    });
}

async function seek(time){
    const target = clamp(time,0,state.duration);
    if(Math.abs(video.currentTime-target)<.0001 && !video.seeking && video.readyState>=2) return;
    await mediaWait(['seeked','loadeddata'],()=>!video.seeking && video.readyState>=2,
        ()=>{ video.currentTime = target; });
}

async function loadSource(fileId){
    video.pause();
    await mediaWait(['loadedmetadata'],()=>video.readyState>=1,()=>{
        video.src = apiURL('file.stream',fileId);
        video.load();
    });
    if(!Number.isFinite(video.duration) || video.duration<=0){
        await mediaWait(['durationchange','seeked'],
            ()=>Number.isFinite(video.duration) && video.duration>0,
            ()=>{ video.currentTime = 1e10; });
    }
    state.duration = video.duration;
    await seek(0);
    fit();
}

async function openVideo(item,edit=null,editing=false){
    if(!mayLeave()) return;
    reset();
    state.video = item;
    state.edit = edit?await api('edit.get',null,null,edit.id):null;
    state.rendered = !!state.edit && !editing;
    await loadSource(state.rendered?state.edit.renderedFileId:item.originalFileId);
    state.data = state.edit?copy(state.edit.editData):{
        schemaVersion:1,coordinateSystem:'source-pixels',
        sourceWidth:video.videoWidth,sourceHeight:video.videoHeight,elements:[]
    };
    state.editing = editing;
    state.folderId = item.folderId;
    state.dirty = false;
    state.selected.clear();
    library(false);
    message(item.name+' ／ '+(state.edit?.name || '元動画'));
    update();
}

function fit(){
    if(!video.videoWidth) return;
    const width = state.data?.sourceWidth || video.videoWidth;
    const height = state.data?.sourceHeight || video.videoHeight;
    const stage = $('stage');
    const factor = Math.min(stage.clientWidth/width,stage.clientHeight/height) *
        Number($('viewZoom').value)/100;
    $('surface').style.width = width*factor+'px';
    $('surface').style.height = height*factor+'px';
    if(canvas.width!==width || canvas.height!==height){
        canvas.width = width;
        canvas.height = height;
    }
    drawOverlay();
}

function pointer(event){
    const rect = canvas.getBoundingClientRect();
    return {
        x:(event.clientX-rect.left)*canvas.width/rect.width,
        y:(event.clientY-rect.top)*canvas.height/rect.height
    };
}

function drag(event,move,done=()=>{}){
    event.preventDefault();
    const stop = ()=>{
        window.removeEventListener('pointermove',move);
        window.removeEventListener('pointerup',stop);
        window.removeEventListener('pointercancel',stop);
        done();
    };
    window.addEventListener('pointermove',move);
    window.addEventListener('pointerup',stop);
    window.addEventListener('pointercancel',stop);
}

function shape(context,element){
    context.beginPath();
    if(element.shape==='ellipse'){
        context.ellipse(element.x+element.w/2,element.y+element.h/2,
            element.w/2,element.h/2,0,0,Math.PI*2);
    }else if(element.shape==='round'){
        context.roundRect(element.x,element.y,element.w,element.h,
            Math.min(16,element.w/4,element.h/4));
    }else context.rect(element.x,element.y,element.w,element.h);
}

function port(element,position){
    if(element.shape==='ellipse'){
        return {
            x:element.x+element.w/2+Math.cos(position.angle)*element.w/2,
            y:element.y+element.h/2+Math.sin(position.angle)*element.h/2
        };
    }
    const r = position.ratio;
    return {
        left:{x:element.x,y:element.y+element.h*r},
        right:{x:element.x+element.w,y:element.y+element.h*r},
        top:{x:element.x+element.w*r,y:element.y},
        bottom:{x:element.x+element.w*r,y:element.y+element.h}
    }[position.side];
}

function nearest(element,point){
    if(element.shape==='ellipse'){
        return {angle:Math.atan2(
            (point.y-element.y-element.h/2)/(element.h/2),
            (point.x-element.x-element.w/2)/(element.w/2)
        )};
    }
    const candidates = [
        {side:'left',ratio:clamp((point.y-element.y)/element.h,0,1)},
        {side:'right',ratio:clamp((point.y-element.y)/element.h,0,1)},
        {side:'top',ratio:clamp((point.x-element.x)/element.w,0,1)},
        {side:'bottom',ratio:clamp((point.x-element.x)/element.w,0,1)}
    ];
    return candidates.sort((a,b)=>{
        const p = port(element,a),q = port(element,b);
        return Math.hypot(p.x-point.x,p.y-point.y)-Math.hypot(q.x-point.x,q.y-point.y);
    })[0];
}

function normal(element,position){
    if(element.shape==='ellipse'){
        const x = Math.cos(position.angle)/element.w;
        const y = Math.sin(position.angle)/element.h;
        const length = Math.hypot(x,y);
        return {x:x/length,y:y/length};
    }
    return {
        left:{x:-1,y:0},right:{x:1,y:0},top:{x:0,y:-1},bottom:{x:0,y:1}
    }[position.side];
}

function endpoints(element){
    const owner = find(element.ownerId),comment = find(element.commentId);
    if(!owner || !comment) return null;
    const a = port(owner,element.from),b = port(comment,element.to);
    const n = normal(owner,element.from),m = normal(comment,element.to);
    return [a,{x:a.x+n.x*20,y:a.y+n.y*20},
        {x:b.x+m.x*20,y:b.y+m.y*20},b];
}

function visible(element,time){
    const owner = element.type==='E'?find(element.ownerId):element;
    return owner && !['D','F'].includes(element.type) &&
        time>=owner.start && time<owner.end;
}

function renderScene(context,time,source,selection=false){
    const list = elements().filter(element=>visible(element,time))
        .sort((a,b)=>order[a.type]-order[b.type]);
    for(const element of list){
        context.save();
        context.strokeStyle = element.stroke;
        context.lineWidth = element.width;
        context.setLineDash(dashPatterns[element.dash]);
        if(element.type==='E'){
            const points = endpoints(element);
            if(!points){ context.restore(); continue; }
            const [a,u,v,b] = points;
            context.beginPath();
            context.moveTo(a.x,a.y);
            context.lineTo(u.x,u.y);
            if(element.line==='orthogonal'){
                const middle = (u.x+v.x)/2;
                context.lineTo(middle,u.y);
                context.lineTo(middle,v.y);
            }else if(element.line==='wave'){
                const dx = v.x-u.x,dy = v.y-u.y,length = Math.hypot(dx,dy)||1;
                for(let i=1;i<=64;i++){
                    const ratio = i/64,sine = Math.sin(ratio*Math.PI*2)*16;
                    context.lineTo(u.x+dx*ratio-dy/length*sine,
                        u.y+dy*ratio+dx/length*sine);
                }
            }
            context.lineTo(v.x,v.y);
            context.lineTo(b.x,b.y);
            context.stroke();
            context.setLineDash([]);
            if(element.terminal==='arrow'){
                const angle = Math.atan2(b.y-v.y,b.x-v.x);
                context.beginPath();
                context.moveTo(b.x-12*Math.cos(angle-.5),b.y-12*Math.sin(angle-.5));
                context.lineTo(b.x,b.y);
                context.lineTo(b.x-12*Math.cos(angle+.5),b.y-12*Math.sin(angle+.5));
                context.stroke();
            }else if(element.terminal==='circle'){
                context.beginPath(); context.arc(b.x,b.y,5,0,Math.PI*2); context.stroke();
            }
            if(selection){
                [a,b].forEach((point,index)=>{
                    context.fillStyle = index?'#22C55E':'#38BDF8';
                    context.beginPath();
                    context.arc(point.x,point.y,state.selected.has(element.id)?6:4,0,Math.PI*2);
                    context.fill();
                });
            }
        }else{
            shape(context,element);
            if(element.background!=='transparent'){
                context.fillStyle = element.background; context.fill();
            }
            if(element.type==='C' && source.readyState>=2){
                context.save(); context.clip();
                context.drawImage(source,
                    element.x+(element.w-element.w/element.zoom)/2,
                    element.y+(element.h-element.h/element.zoom)/2,
                    element.w/element.zoom,element.h/element.zoom,
                    element.x,element.y,element.w,element.h);
                context.restore();
            }
            context.stroke();
            if(element.type==='A'){
                context.save(); shape(context,element); context.clip();
                context.fillStyle = element.color;
                context.font = element.size+'px '+fonts[element.font];
                let y = element.y+element.size+6;
                for(const line of element.text.split('\n')){
                    let row = '';
                    for(const character of line){
                        if(row && context.measureText(row+character).width>element.w-12){
                            context.fillText(row,element.x+6,y);
                            y += element.size*1.35; row = '';
                        }
                        row += character;
                    }
                    context.fillText(row,element.x+6,y); y += element.size*1.35;
                }
                context.restore();
            }
            if(selection && state.selected.has(element.id)){
                context.strokeStyle = '#60A5FA';
                context.setLineDash([4,3]);
                context.strokeRect(element.x,element.y,element.w,element.h);
                context.fillStyle = '#60A5FA';
                for(const [x,y] of [
                    [element.x,element.y],[element.x+element.w,element.y],
                    [element.x,element.y+element.h],[element.x+element.w,element.y+element.h]
                ]) context.fillRect(x-5,y-5,10,10);
            }
        }
        context.restore();
    }
}

function drawOverlay(){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    if(state.editing && !state.rendered && video.readyState>=2){
        renderScene(ctx,video.currentTime,video,true);
    }
}

function snapshot(){
    const output = document.createElement('canvas');
    output.width = state.data.sourceWidth;
    output.height = state.data.sourceHeight;
    const context = output.getContext('2d');
    context.drawImage(video,0,0,output.width,output.height);
    renderScene(context,video.currentTime,video);
    return output;
}

function hit(point){
    return elements().filter(element=>visible(element,video.currentTime))
        .sort((a,b)=>order[b.type]-order[a.type]).find(element=>{
            if(element.type==='E'){
                const ends = endpoints(element);
                return ends && [ends[0],ends[3]].some(end=>
                    Math.hypot(end.x-point.x,end.y-point.y)<14);
            }
            return point.x>=element.x-6 && point.x<=element.x+element.w+6 &&
                point.y>=element.y-6 && point.y<=element.y+element.h+6;
        });
}

function select(element,event){
    if(!event.ctrlKey && !event.shiftKey) state.selected.clear();
    if(element){
        if((event.ctrlKey || event.shiftKey) && state.selected.has(element.id)){
            state.selected.delete(element.id);
        }else state.selected.add(element.id);
    }
}

canvas.addEventListener('pointerdown',event=>{
    if(event.button!==0 || !state.editing || locked()) return;
    commitText(); video.pause();
    const point = pointer(event),element = hit(point);
    if(state.connecting!==null){
        if(!element || !['A','B','C'].includes(element.type)) return;
        if(state.connecting==='first'){
            state.connecting = element.id;
            message('接続先をクリックしてください。Escで中止できます。');
            return;
        }
        const first = find(state.connecting);
        if(!first || (first.type==='A')===(element.type==='A')){
            message('コメントと強調枠、またはコメントと拡大枠を選択してください。');
            return;
        }
        const owner = first.type==='A'?element:first;
        const comment = first.type==='A'?first:element;
        const from = nearest(owner,{x:comment.x+comment.w/2,y:comment.y+comment.h/2});
        const line = {
            id:uid(),type:'E',ownerId:owner.id,commentId:comment.id,
            from,to:nearest(comment,port(owner,from)),
            stroke:owner.stroke,width:owner.width,dash:owner.dash,
            line:'orthogonal',terminal:'arrow'
        };
        elements().push(line);
        state.selected = new Set([line.id]);
        state.connecting = null;
        state.dirty = true;
        drawOverlay(); return;
    }
    select(element,event); renderTracks(); drawOverlay();
    if(!element) return;
    const original = copy(element);
    if(element.type==='E'){
        const ends = endpoints(element);
        const key = Math.hypot(ends[0].x-point.x,ends[0].y-point.y)<14?'from':'to';
        drag(event,movement=>{
            element[key] = nearest(find(key==='from'?element.ownerId:element.commentId),pointer(movement));
            state.dirty = true; drawOverlay();
        });
        return;
    }
    const left = Math.abs(point.x-element.x)<12;
    const right = Math.abs(point.x-element.x-element.w)<12;
    const top = Math.abs(point.y-element.y)<12;
    const bottom = Math.abs(point.y-element.y-element.h)<12;
    const resize = (left||right)&&(top||bottom);
    drag(event,movement=>{
        const next = pointer(movement),dx = next.x-point.x,dy = next.y-point.y;
        if(resize){
            let x1 = original.x,y1 = original.y;
            let x2 = original.x+original.w,y2 = original.y+original.h;
            if(left) x1 = clamp(original.x+dx,0,x2-10);
            if(right) x2 = clamp(x2+dx,x1+10,canvas.width);
            if(top) y1 = clamp(original.y+dy,0,y2-10);
            if(bottom) y2 = clamp(y2+dy,y1+10,canvas.height);
            Object.assign(element,{x:x1,y:y1,w:x2-x1,h:y2-y1});
        }else{
            element.x = clamp(original.x+dx,0,canvas.width-element.w);
            element.y = clamp(original.y+dy,0,canvas.height-element.h);
        }
        state.dirty = true; drawOverlay();
    });
});

canvas.addEventListener('dblclick',event=>{
    if(!state.editing || locked()) return;
    const element = hit(pointer(event));
    if(element?.type!=='A') return;
    commitText(); video.pause();
    const node = document.createElement('div');
    const factor = canvas.clientWidth/canvas.width;
    node.className = 'textEditor';
    node.dataset.elementId = element.id;
    node.contentEditable = 'true';
    node.innerText = element.text;
    Object.assign(node.style,{
        left:element.x*factor+'px',top:element.y*factor+'px',
        width:element.w*factor+'px',height:element.h*factor+'px',
        fontSize:element.size*factor+'px',fontFamily:fonts[element.font],
        color:element.color,background:element.background
    });
    $('surface').append(node);
    state.textEditor = {node,element};
    node.oninput = ()=>{ element.text = node.innerText; state.dirty = true; };
    node.onblur = commitText;
    node.focus();
});

function windowRange(){
    const duration = state.duration || 1;
    const span = duration*(.05+.95*Number($('scale').value)/100);
    return {
        span,start:clamp(video.currentTime-span/2,0,Math.max(0,duration-span)),
        width:Math.max(1,$('tracks').clientWidth-20)
    };
}

function timeAt(event,range=windowRange()){
    return clamp(range.start+
        (event.clientX-$('tracks').getBoundingClientRect().left-10)/range.width*range.span,
        0,state.duration);
}

function startOf(element){ return element.type==='F'?element.time:element.start; }
function endOf(element){ return element.type==='F'?element.time+.000001:element.end; }

function renderTracks(){
    const root = $('tracks');
    root.querySelectorAll('.clip,.tick').forEach(node=>node.remove());
    if(!state.editing) return;
    const range = windowRange();
    for(let i=0;i<=8;i++){
        const tick = document.createElement('span');
        tick.className = 'tick';
        tick.style.left = 10+range.width*i/8+'px';
        tick.textContent = (range.start+range.span*i/8).toFixed(1)+'s';
        if(i===8) tick.style.transform = 'translateX(-100%)';
        root.append(tick);
    }
    const rows = [];
    for(const element of elements().filter(item=>item.type!=='E')
        .sort((a,b)=>startOf(a)-startOf(b))){
        const start = startOf(element),end = endOf(element);
        let row = rows.findIndex(value=>value<=start);
        if(row<0) row = rows.length;
        rows[row] = end;
        if(end<range.start || start>range.start+range.span) continue;
        const node = document.createElement('div');
        node.className = 'clip '+element.type+(state.selected.has(element.id)?' selected':'');
        node.dataset.elementId = element.id;
        node.textContent = element.type==='A'?element.text:titles[element.type];
        const x = 10+(Math.max(start,range.start)-range.start)/range.span*range.width;
        const width = element.type==='F'?8:
            (Math.min(end,range.start+range.span)-Math.max(start,range.start))/range.span*range.width;
        Object.assign(node.style,{
            left:Math.min(root.clientWidth-10,x)+'px',top:26+row*29+'px',
            width:Math.max(2,Math.min(width,root.clientWidth-x-2))+'px'
        });
        if(element.type!=='F'){
            for(const side of ['start','end']){
                if(side==='start' && start<range.start || side==='end' && end>range.start+range.span) continue;
                const edge = document.createElement('span');
                edge.className = 'edge '+side;
                node.append(edge);
            }
        }
        node.oncontextmenu = event=>{
            event.stopPropagation(); elementMenu(event,element,true);
        };
        node.onpointerdown = event=>{
            if(event.button!==0 || locked()) return;
            event.stopPropagation(); video.pause(); select(element,event);
            const original = copy(element),x = event.clientX;
            const side = event.target.classList.contains('start')?'start':
                event.target.classList.contains('end')?'end':'move';
            drag(event,movement=>{
                const delta = (movement.clientX-x)/range.width*range.span;
                if(element.type==='F'){
                    element.time = clamp(original.time+delta,0,Math.max(0,state.duration-.001));
                }else if(side==='start'){
                    element.start = clamp(original.start+delta,0,element.end-.001);
                }else if(side==='end'){
                    element.end = clamp(original.end+delta,element.start+.001,state.duration);
                }else{
                    const length = original.end-original.start;
                    element.start = clamp(original.start+delta,0,state.duration-length);
                    element.end = element.start+length;
                }
                state.dirty = true; renderTracks(); drawOverlay();
            });
            renderTracks(); drawOverlay();
        };
        root.append(node);
    }
    root.style.height = Math.max(100,rows.length*29+28)+'px';
    clock();
}

function clock(){
    $('clock').textContent = video.currentTime.toFixed(1)+' / '+state.duration.toFixed(1)+' 秒';
    const range = windowRange();
    $('playhead').style.left = 10+(video.currentTime-range.start)/range.span*range.width+'px';
}

function placeMenu(event){
    const menu = $('menu');
    menu.hidden = false;
    menu.style.left = clamp(event.clientX,4,Math.max(4,innerWidth-menu.offsetWidth-4))+'px';
    menu.style.top = clamp(event.clientY,4,Math.max(4,innerHeight-menu.offsetHeight-4))+'px';
}

function menu(event,items){
    event.preventDefault();
    if(locked()) return;
    $('menu').replaceChildren();
    for(const [label,task] of items){
        const button = document.createElement('button');
        button.textContent = label;
        button.onclick = ()=>{
            $('menu').hidden = true;
            run(task);
        };
        $('menu').append(button);
    }
    placeMenu(event);
}

function addElement(type,time,point){
    if(time>=state.duration) throw Error('動画終了より前に追加してください。');
    const element = {id:uid(),type};
    if(type==='F') element.time = time;
    else Object.assign(element,{start:time,end:Math.min(state.duration,time+3)});
    if(['A','B','C'].includes(type)){
        const w = Math.min(260,canvas.width),h = Math.min(110,canvas.height);
        Object.assign(element,{
            x:clamp(point.x,0,canvas.width-w),y:clamp(point.y,0,canvas.height-h),
            w,h,stroke:'#EF4444',width:3,dash:'solid',shape:'rect',background:'transparent'
        });
        if(type==='A') Object.assign(element,{text:'コメント',size:24,font:'gothic',color:'#FFFFFF'});
        if(type==='C') element.zoom = 2;
    }
    elements().push(element);
    state.selected = new Set([element.id]);
    state.dirty = true;
    renderTracks(); drawOverlay();
}

function deleteSelected(){
    const selected = state.selected;
    state.data.elements = elements().filter(element=>
        !selected.has(element.id) &&
        !selected.has(element.ownerId) && !selected.has(element.commentId));
    selected.clear(); state.dirty = true;
    renderTracks(); drawOverlay();
}

function elementMenu(event,element,timeline){
    event.preventDefault();
    if(!state.editing || locked()) return;
    commitText(); video.pause();
    if(element && !state.selected.has(element.id)) state.selected = new Set([element.id]);
    const time = timeline?timeAt(event):video.currentTime;
    const point = timeline?{x:canvas.width*.1,y:canvas.height*.1}:pointer(event);
    const items = (timeline?['A','B','C','D','F']:['A','B','C'])
        .map(type=>[titles[type]+'追加',()=>addElement(type,time,point)]);
    if(!timeline){
        items.push(['接続線追加',()=>{
            state.connecting = 'first';
            message('接続開始側をクリックしてください。Escで中止できます。');
        }]);
    }
    if(timeline && state.selected.size>1){
        items.push(['開始位置を合わせる',()=>{
            const list = elements().filter(item=>state.selected.has(item.id) && item.type!=='E');
            const start = element?startOf(element):Math.min(...list.map(startOf));
            if(list.some(item=>item.type!=='F' && start+item.end-item.start>state.duration)){
                throw Error('動画終了を超えるため変更できません。');
            }
            for(const item of list){
                if(item.type==='F') item.time = start;
                else{ const length = item.end-item.start; item.start = start; item.end = start+length; }
            }
            state.dirty = true; renderTracks(); drawOverlay();
        }]);
    }
    if(element){
        if(!['D','F'].includes(element.type)) items.push(['書式変更',()=>format(element,event)]);
        items.push(['選択要素を削除',deleteSelected]);
    }
    menu(event,items);
}

function format(element,event){
    const root = $('menu');
    root.replaceChildren();
    const changed = ()=>{ state.dirty = true; drawOverlay(); };
    const fields = [
        ...(element.type==='A'?[
            ['文字サイズ','size',Array.from({length:31},(_,i)=>[i+10,i+10])],
            ['フォント','font',[['gothic','ゴシック'],['mincho','明朝']]]
        ]:[]),
        ['線種','dash',[['solid','実線'],['dash','破線'],['dot','点線'],['chain','一点鎖線']]],
        ...(element.type==='E'?[
            ['線形','line',[['straight','直線'],['orthogonal','折れ線'],['wave','1周期波線']]],
            ['終端','terminal',[['none','なし'],['arrow','矢印'],['circle','丸']]]
        ]:[
            ['図形','shape',[['rect','四角'],['ellipse','楕円'],['round','角丸四角']]]
        ]),
        ...(element.type==='C'?[
            ['倍率','zoom',[[1,'1倍'],[2,'2倍'],[3,'3倍'],[4,'4倍'],[6,'6倍']]]
        ]:[])
    ];
    for(const [title,key,options] of fields){
        const label = document.createElement('label');
        const select = document.createElement('select');
        label.textContent = title;
        for(const [value,text] of options) select.add(new Option(text,value));
        select.value = element[key];
        select.onchange = ()=>{
            if(key==='shape'){
                const attached = elements().filter(item=>item.type==='E' &&
                    (item.ownerId===element.id || item.commentId===element.id));
                const ports = attached.map(item=>{
                    const endpoint = item.ownerId===element.id?'from':'to';
                    return {item,endpoint,point:port(element,item[endpoint])};
                });
                element.shape = select.value;
                for(const item of ports) item.item[item.endpoint] = nearest(element,item.point);
            }else element[key] = ['size','zoom'].includes(key)?Number(select.value):select.value;
            changed();
        };
        label.append(select); root.append(label);
    }
    const heading = document.createElement('div'); heading.textContent = '線の太さ';
    const weights = document.createElement('div'); weights.className = 'weights';
    for(const width of [1,2,3,5,8]){
        const button = document.createElement('button');
        const line = document.createElement('span');
        line.style.height = width+'px';
        button.title = '線の太さを選択';
        button.append(line);
        button.onclick = ()=>{ element.width = width; changed(); };
        weights.append(button);
    }
    root.append(heading,weights);
    const target = document.createElement('select');
    target.add(new Option('線色','stroke'));
    if(element.type!=='E') target.add(new Option('背景色','background'));
    if(element.type==='A') target.add(new Option('文字色','color'));
    root.append(target);
    const palette = document.createElement('div'); palette.className = 'palette';
    for(const color of colors){
        const button = document.createElement('button');
        button.title = color==='transparent'?'塗り潰しなし':color;
        button.style.background = color==='transparent'?
            'repeating-linear-gradient(45deg,#fff 0 4px,#bbb 4px 8px)':color;
        button.onclick = ()=>{ element[target.value] = color; changed(); };
        palette.append(button);
    }
    root.append(palette); placeMenu(event);
}

function recorderFor(stream){
    if(!window.MediaRecorder) throw Error('このブラウザは録画に対応していません。');
    const mimeType = [
        'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
        'video/mp4',
        'video/webm;codecs=vp9,opus',
        'video/webm;codecs=vp8,opus',
        'video/webm'
    ].find(type=>MediaRecorder.isTypeSupported(type));
    if(!mimeType) throw Error('対応する録画形式がありません。');
    return new MediaRecorder(stream,{mimeType});
}

function collectRecorder(recorder){
    return new Promise((resolve,reject)=>{
        const parts = [];
        recorder.ondataavailable = event=>{ if(event.data.size) parts.push(event.data); };
        recorder.onstop = ()=>resolve(new Blob(parts,{type:recorder.mimeType}));
        recorder.onerror = event=>reject(event.error || Error('録画に失敗しました。'));
    });
}

async function targetFolder(){
    if(!state.model.folders.length){
        const id = uid();
        await api('folder.create',{id,parentFolderId:null,name:'デフォルトフォルダ'});
        await refresh(); state.folderId = id;
    }
    return state.folderId || state.model.folders[0].id;
}

async function uploadBlob(blob,name){
    const id = uid(),folderId = await targetFolder();
    await api('video.upload',{id,folderId,name},blob);
    await refresh();
    await openVideo(state.model.videos.find(item=>item.id===id));
}

async function startRecording(){
    if(!mayLeave()) return;
    if(!navigator.mediaDevices?.getDisplayMedia){
        throw Error('画面録画にはHTTPSまたはlocalhostと対応ブラウザが必要です。');
    }
    const stream = await navigator.mediaDevices.getDisplayMedia({video:true,audio:true});
    let recorder;
    try{
        recorder = recorderFor(stream);
        const result = collectRecorder(recorder);
        reset();
        state.recording = {stream,recorder};
        video.srcObject = stream;
        video.muted = true;
        await video.play();
        recorder.start(500);
        stream.getVideoTracks()[0].onended = ()=>{
            if(recorder.state!=='inactive') recorder.stop();
        };
        message('録画中です。停止するとサーバーへ保存します。');
        result.then(blob=>{
            stream.getTracks().forEach(track=>track.stop());
            state.recording = null;
            video.srcObject = null; video.muted = false;
            run(()=>uploadBlob(blob,'録画 '+new Date().toLocaleString('ja-JP')));
        }).catch(error=>{
            stream.getTracks().forEach(track=>track.stop());
            state.recording = null; video.srcObject = null; video.muted = false;
            message(error.message); update();
        });
    }catch(error){
        stream.getTracks().forEach(track=>track.stop());
        state.recording = null; video.srcObject = null; video.muted = false;
        throw error;
    }
}

function skipTime(time){
    for(let i=0;i<=elements().length;i++){
        const skips = elements().filter(item=>item.type==='D' && time>=item.start && time<item.end);
        if(!skips.length) break;
        time = Math.max(...skips.map(item=>item.end));
    }
    return Math.min(time,state.duration);
}

function validateTimes(){
    for(const element of elements()){
        if(element.type==='F'){
            if(element.time<0 || element.time>=state.duration) throw Error('PDF位置が動画範囲外です。');
        }else if(element.type!=='E'){
            if(element.start<0 || element.start>=element.end || element.end>state.duration+.000001){
                throw Error('編集要素の時間が動画範囲外です。');
            }
        }
    }
}

async function audioGraph(){
    if(!state.audio){
        const context = new AudioContext();
        const source = context.createMediaElementSource(video);
        const sink = context.createMediaStreamDestination();
        source.connect(context.destination);
        source.connect(sink);
        state.audio = {context,source,sink};
    }
    await state.audio.context.resume();
    return state.audio;
}

async function renderVideo(){
    if(!HTMLCanvasElement.prototype.captureStream) throw Error('編集動画生成に未対応のブラウザです。');
    validateTimes();
    if(skipTime(0)>=state.duration) throw Error('全区間がスキップされています。');
    const oldTime = video.currentTime,oldMuted = video.muted,oldRate = video.playbackRate;
    const graph = await audioGraph();
    const output = document.createElement('canvas');
    output.width = state.data.sourceWidth; output.height = state.data.sourceHeight;
    const context = output.getContext('2d');
    let stream,recorder,frame,disconnected=false;
    state.exporting = true;
    try{
        video.pause(); graph.source.disconnect(graph.context.destination); disconnected = true;
        await seek(skipTime(0));
        const draw = ()=>{
            context.clearRect(0,0,output.width,output.height);
            context.drawImage(video,0,0,output.width,output.height);
            renderScene(context,video.currentTime,video);
        };
        draw();
        stream = output.captureStream(30);
        graph.sink.stream.getAudioTracks().forEach(track=>stream.addTrack(track.clone()));
        recorder = recorderFor(stream);
        const result = collectRecorder(recorder);
        result.catch(()=>{});
        video.muted = false; video.playbackRate = 1;
        recorder.start(250);
        await video.play();
        await new Promise((resolve,reject)=>{
            let last = video.currentTime,progress = performance.now();
            const step = async()=>{
                try{
                    if(video.ended || video.currentTime>=state.duration){ resolve(); return; }
                    if(document.hidden) throw Error('書き出し中はこの画面を表示してください。');
                    if(video.currentTime>last+.001){ last = video.currentTime; progress = performance.now(); }
                    if(performance.now()-progress>30000) throw Error('動画の再生が進まないため書き出しを中止しました。');
                    const next = skipTime(video.currentTime);
                    if(next>video.currentTime){
                        video.pause(); recorder.pause();
                        if(next>=state.duration){ resolve(); return; }
                        await seek(next); draw(); recorder.resume(); await video.play();
                        last = next; progress = performance.now();
                    }
                    draw();
                    frame = requestAnimationFrame(step);
                }catch(error){ reject(error); }
            };
            frame = requestAnimationFrame(step);
            result.catch(reject);
        });
        video.pause();
        if(recorder.state!=='inactive') recorder.stop();
        const blob = await result;
        if(!blob.size) throw Error('編集動画を生成できませんでした。');
        return blob;
    }finally{
        cancelAnimationFrame(frame);
        video.pause();
        if(recorder && recorder.state!=='inactive') recorder.stop();
        stream?.getTracks().forEach(track=>track.stop());
        if(disconnected) graph.source.connect(graph.context.destination);
        video.muted = oldMuted; video.playbackRate = oldRate;
        state.exporting = false;
        await seek(Math.min(oldTime,state.duration));
    }
}

async function saveEdit(){
    commitText();
    message('編集動画を生成しています。この画面を表示したままお待ちください。');
    const blob = await renderVideo();
    const id = state.edit?.id || uid();
    const name = state.edit?.name ||
        '編集 '+(state.model.edits.filter(item=>item.videoId===state.video.id).length+1);
    state.edit = await api('edit.save',{
        id,videoId:state.video.id,name,
        expectedVersion:state.edit?.version || 0,
        editData:copy(state.data)
    },blob);
    state.dirty = false;
    await refresh();
    message('編集内容と生成済み編集動画を保存しました。');
}

function downloadFile(id){
    const link = document.createElement('a');
    link.href = apiURL('file.download',id);
    document.body.append(link); link.click(); link.remove();
}

async function openPDF(){
    if(!elements().some(element=>element.type==='F')){
        throw Error('編集画面のタイムラインでPDF位置を追加してください。');
    }
    if(state.rendered){
        await loadSource(state.video.originalFileId);
        state.rendered = false;
        state.editing = true;
    }
    $('pdfDialog').showModal();
}

async function printPDF(){
    commitText(); validateTimes();
    $('pdfDialog').close(); video.pause();
    const oldTime = video.currentTime;
    const count = Number(document.querySelector('[name=layout]:checked').value);
    const times = elements().filter(item=>item.type==='F').map(item=>item.time).sort((a,b)=>a-b);
    $('printArea').replaceChildren();
    try{
        let sheet;
        for(let i=0;i<times.length;i++){
            await seek(times[i]);
            if(i%count===0){
                sheet = document.createElement('div'); sheet.className = 'sheet';
                $('printArea').append(sheet);
            }
            const shot = document.createElement('div'),image = new Image(),label = document.createElement('p');
            shot.className = 'shot';
            image.src = snapshot().toDataURL('image/png');
            image.style.maxHeight = count===2?'125mm':'255mm';
            await image.decode();
            label.textContent = state.video.name+' ／ '+times[i].toFixed(2)+' 秒';
            shot.append(image,label); sheet.append(shot);
        }
        window.print();
    }finally{ await seek(oldTime); }
}

async function removeEntity(type,item){
    if(!mayLeave()) return;
    if(!confirm(type==='edit'?'この編集と編集動画を削除しますか？':
        '配下の動画・編集も削除されます。続行しますか？')) return;
    await api(type+'.delete',{id:item.id});
    await refresh();
    if(state.video && !state.model.videos.some(row=>row.id===state.video.id) ||
        state.edit && !state.model.edits.some(row=>row.id===state.edit.id)){
        reset();
    }
    message('削除しました。');
}

function renderLibrary(){
    const root = $('tree');
    root.replaceChildren();
    if(!state.model) return;

    function row(type,item,depth,branch,open){
        const node = document.createElement('div'),toggle = document.createElement('button');
        const name = document.createElement('span'),time = document.createElement('time');
        node.className = 'row';
        node.dataset.entityType = type;
        node.dataset.entityId = item.id;
        node.style.marginLeft = depth*16+'px';
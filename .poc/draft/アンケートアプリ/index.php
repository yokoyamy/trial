<?php
declare(strict_types=1);

/*
 * アンケートアプリ / POC
 *
 * 業務要件:
 * - 管理者認証なし
 * - 回答者認証なし
 * - アンケート作成・編集・公開・停止・終了
 * - グループ・質問管理
 * - 条件分岐
 * - 回答・確認・完了
 * - 顧客選択・SMTP送信・再送・リマインド
 * - 回答集計・CSV/PDF
 * - kintone顧客同期
 *
 * 実装要件:
 * - Apache 2.4 / PHP 8.5
 * - DBなし
 * - PHP cURLなし
 * - PHP mail()なし
 * - 単一index.php
 * - screenパラメータによる画面切替
 * - サーバー側JSON保存
 * - Sodium secretbox
 * - CSRF対策はPOCでは実装しない
 *
 * 重要:
 * 本POCではCSRFトークン、CSRF hidden field、
 * CSRF検証処理を一切使用しない。
 */

const APP_NAME = 'アンケート管理システム';
const DATA_DIR_NAME = '.survey-data';
const SECRET_RELATIVE = '.secrets/アンケートアプリ/secret.key';

date_default_timezone_set('Asia/Tokyo');

$https =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

$appDir = __DIR__;
$dataDir = dirname($appDir) . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

function e(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uid(): string
{
    return bin2hex(random_bytes(16));
}

function redirectTo(string $screen, array $params = []): never
{
    $params = array_merge(['screen' => $screen], $params);
    header('Location: index.php?' . http_build_query($params));
    exit;
}

function fail(string $message, int $status = 400): never
{
    http_response_code($status);
    renderError($message, $status);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlash(): ?array
{
    $v = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($v) ? $v : null;
}

function h(array $a, string $key, mixed $default = ''): mixed
{
    return $a[$key] ?? $default;
}

/* =========================================================
 * セッション
 *
 * 回答途中状態にのみ使用。
 * CSRFには使用しない。
 * ======================================================= */

session_set_cookie_params([
    'httponly' => true,
    'secure'   => $https,
    'samesite' => 'Lax',
    'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') ?: '/',
]);

session_start();

/* =========================================================
 * 保存領域
 * ======================================================= */

if (!is_dir($dataDir) && !@mkdir($dataDir, 0700, true)) {
    fail('データ保存領域を作成できません。', 500);
}

if (!is_writable($dataDir)) {
    fail('データ保存領域へ書き込めません。', 500);
}

function dataFile(string $name): string
{
    global $dataDir;
    return $dataDir . DIRECTORY_SEPARATOR . $name . '.json';
}

function readJson(string $name, mixed $default = []): mixed
{
    $file = dataFile($name);

    if (!is_file($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || $raw === '') {
        return $default;
    }

    $value = json_decode($raw, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $value
        : $default;
}

function writeJson(string $name, mixed $data): bool
{
    $file = dataFile($name);

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    @chmod($tmp, 0600);

    return @rename($tmp, $file);
}

function appendJson(string $name, array $record): bool
{
    $rows = readJson($name, []);

    if (!is_array($rows)) {
        $rows = [];
    }

    $rows[] = $record;

    return writeJson($name, $rows);
}

/* =========================================================
 * 初期データ
 * ======================================================= */

function defaultSettings(): array
{
    return [
        'kintone' => [
            'subdomain' => '',
            'app_id' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verify_ssl' => false,
            'mapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
            'fields' => [],
        ],
        'smtp' => [
            'server' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
            'status' => '未設定',
        ],
    ];
}

function settings(): array
{
    $v = readJson('settings', defaultSettings());

    if (!is_array($v)) {
        return defaultSettings();
    }

    return array_replace_recursive(defaultSettings(), $v);
}

function surveys(): array
{
    return readJson('surveys', []);
}

function customers(): array
{
    return readJson('customers', []);
}

function answers(): array
{
    return readJson('answers', []);
}

function sendLogs(): array
{
    return readJson('send_logs', []);
}

/* =========================================================
 * Sodium
 * ======================================================= */

function secretKeyPath(): string
{
    global $appDir;

    $paths = [
        dirname($appDir) . DIRECTORY_SEPARATOR . SECRET_RELATIVE,
        $appDir . DIRECTORY_SEPARATOR . SECRET_RELATIVE,
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return $paths[0];
}

function encryptionKey(): string
{
    $file = secretKeyPath();

    if (!is_file($file)) {
        throw new RuntimeException(
            '.secrets/アンケートアプリ/secret.key がありません。'
        );
    }

    $key = @file_get_contents($file);

    if ($key === false ||
        strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('暗号鍵の形式が不正です。');
    }

    return $key;
}

function encryptSecret(string $value): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $cipher = sodium_crypto_secretbox(
        $value,
        $nonce,
        encryptionKey()
    );

    return 'ENC:v1:'
        . base64_encode($nonce)
        . ':'
        . base64_encode($cipher);
}

function decryptSecret(string $value): string
{
    if (!str_starts_with($value, 'ENC:v1:')) {
        throw new RuntimeException('暗号化データの形式が不正です。');
    }

    $parts = explode(':', $value, 4);

    if (count($parts) !== 4) {
        throw new RuntimeException('暗号化データの形式が不正です。');
    }

    $nonce = base64_decode($parts[2], true);
    $cipher = base64_decode($parts[3], true);

    if ($nonce === false || $cipher === false) {
        throw new RuntimeException('暗号化データを復号できません。');
    }

    $plain = sodium_crypto_secretbox_open(
        $cipher,
        $nonce,
        encryptionKey()
    );

    if ($plain === false) {
        throw new RuntimeException('暗号化データを復号できません。');
    }

    return $plain;
}

/*
 * CSRF関連関数は意図的に存在しない。
 * csrfToken()
 * verifyCsrf()
 * $_POST['_csrf']
 * hidden _csrf
 * は使用しない。
 */

/* =========================================================
 * 入力検証
 * ======================================================= */

function postString(string $key, int $max = 5000): string
{
    $value = trim((string)($_POST[$key] ?? ''));

    if (mb_strlen($value) > $max) {
        throw new InvalidArgumentException($key . 'が長すぎます。');
    }

    return $value;
}

function requiredPost(string $key, int $max = 5000): string
{
    $value = postString($key, $max);

    if ($value === '') {
        throw new InvalidArgumentException($key . 'は必須です。');
    }

    return $value;
}

function validEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validSurveyId(string $id): bool
{
    return (bool)preg_match('/^[a-f0-9]{32}$/', $id);
}

/* =========================================================
 * アンケート
 * ======================================================= */

function newQuestion(): array
{
    return [
        'id' => uid(),
        'number' => '',
        'order' => 1,
        'text' => '新しい質問',
        'type' => 'single',
        'required' => true,
        'options' => ['はい', 'いいえ'],
        'branch' => [],
    ];
}

function newGroup(): array
{
    return [
        'id' => uid(),
        'order' => 1,
        'title' => '新しいグループ',
        'questions' => [newQuestion()],
    ];
}

function newSurvey(): array
{
    $survey = [
        'id' => uid(),
        'title' => '',
        'description' => '',
        'start_at' => date('Y-m-d\TH:i'),
        'end_at' => date('Y-m-d\TH:i', strtotime('+30 days')),
        'numbering' => 'global',
        'status' => '下書き',
        'created_at' => now(),
        'updated_at' => now(),
        'groups' => [newGroup()],
    ];

    recalcNumbers($survey);

    return $survey;
}

function recalcNumbers(array &$survey): void
{
    $global = 0;

    foreach ($survey['groups'] as $gi => &$group) {
        $group['order'] = $gi + 1;

        foreach ($group['questions'] as $qi => &$question) {
            $global++;

            $question['order'] = $qi + 1;

            if (($survey['numbering'] ?? 'global') === 'group') {
                $question['number'] =
                    'Q' . ($gi + 1) . '-' . ($qi + 1);
            } else {
                $question['number'] = 'Q' . $global;
            }

            if (($question['type'] ?? '') !== 'single') {
                unset($question['branch']);
            }
        }

        unset($question);
    }

    unset($group);
}

function findSurvey(string $id): ?array
{
    foreach (surveys() as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function saveSurvey(array $survey): void
{
    $all = surveys();
    $found = false;

    foreach ($all as $i => $item) {
        if (($item['id'] ?? '') === $survey['id']) {
            $all[$i] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $all[] = $survey;
    }

    if (!writeJson('surveys', array_values($all))) {
        throw new RuntimeException('アンケートを保存できませんでした。');
    }
}

function deleteSurvey(string $id): void
{
    $all = array_values(array_filter(
        surveys(),
        static fn(array $s): bool => ($s['id'] ?? '') !== $id
    ));

    if (!writeJson('surveys', $all)) {
        throw new RuntimeException('アンケートを削除できませんでした。');
    }
}

function normalizeSurvey(array $survey): array
{
    if (
        ($survey['status'] ?? '') === '公開中' &&
        !empty($survey['end_at']) &&
        strtotime((string)$survey['end_at']) !== false &&
        strtotime((string)$survey['end_at']) < time()
    ) {
        $survey['status'] = '終了';
        $survey['updated_at'] = now();
    }

    return $survey;
}

function refreshStatuses(): void
{
    $all = surveys();
    $changed = false;

    foreach ($all as $i => $survey) {
        $new = normalizeSurvey($survey);

        if ($new !== $survey) {
            $all[$i] = $new;
            $changed = true;
        }
    }

    if ($changed) {
        writeJson('surveys', $all);
    }
}

function transitionAllowed(string $from, string $to): bool
{
    return match ($from) {
        '下書き' => $to === '公開中',
        '公開中' => $to === '停止',
        '停止' => $to === '公開中',
        default => false,
    };
}

/* =========================================================
 * kintone
 * PHP cURLを使用しない。
 * ======================================================= */

function normalizeKintoneHost(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    if (!preg_match('#^https?://#i', $value)) {
        $value = 'https://' . $value;
    }

    $parts = parse_url($value);

    if (!$parts || empty($parts['host'])) {
        throw new InvalidArgumentException(
            'kintoneサブドメインが不正です。'
        );
    }

    return 'https://' . $parts['host'];
}

function httpRequest(
    string $url,
    array $headers,
    ?string $body = null,
    string $method = 'GET',
    bool $verifySsl = true,
    string $proxy = ''
): array {
    $parts = parse_url($url);

    if (!$parts || empty($parts['host'])) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' => 'URLが不正です。',
        ];
    }

    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'];
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $path = ($parts['path'] ?? '/') .
        (!empty($parts['query']) ? '?' . $parts['query'] : '');

    $transport = $scheme === 'https' ? 'ssl' : 'tcp';

    if ($scheme === 'https') {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
                'allow_self_signed' => !$verifySsl,
            ],
        ]);
    } else {
        $context = stream_context_create();
    }

    $targetHost = $host;
    $targetPort = $port;

    if ($proxy !== '') {
        if (!preg_match('/^([^:\s]+):(\d+)$/', $proxy, $m)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Proxy形式が不正です。',
            ];
        }

        $targetHost = $m[1];
        $targetPort = (int)$m[2];
    }

    $socket = @stream_socket_client(
        $transport . '://' . $targetHost . ':' . $targetPort,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' => '接続エラー: ' . $errstr,
        ];
    }

    stream_set_timeout($socket, 20);

    if ($proxy !== '') {
        fwrite(
            $socket,
            'CONNECT ' . $host . ':' . $port .
            " HTTP/1.1\r\nHost: " . $host .
            ':' . $port . "\r\n\r\n"
        );

        $proxyResponse = '';
        while (($line = fgets($socket, 4096)) !== false) {
            $proxyResponse .= $line;
            if (rtrim($line, "\r\n") === '') {
                break;
            }
        }

        if (!preg_match('/^HTTP\/\S+\s+200\b/m', $proxyResponse)) {
            fclose($socket);

            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Proxy接続に失敗しました。',
            ];
        }

        if ($scheme === 'https') {
            if (!stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) {
                fclose($socket);

                return [
                    'ok' => false,
                    'status' => 0,
                    'body' => '',
                    'error' => 'TLS接続を確立できません。',
                ];
            }
        }
    }

    if ($proxy === '' && $scheme === 'https') {
        /*
         * ssl://で接続済み。
         */
    }

    $headerLines = [
        $method . ' ' . $path . ' HTTP/1.1',
        'Host: ' . $host,
        'Connection: close',
    ];

    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    if ($body !== null) {
        $headerLines[] = 'Content-Length: ' . strlen($body);
    }

    fwrite($socket, implode("\r\n", $headerLines) . "\r\n\r\n");

    if ($body !== null) {
        fwrite($socket, $body);
    }

    $raw = '';
    while (!feof($socket)) {
        $chunk = fread($socket, 8192);

        if ($chunk === false) {
            fclose($socket);

            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'レスポンス取得不能です。',
            ];
        }

        if ($chunk === '') {
            break;
        }

        $raw .= $chunk;
    }

    $meta = stream_get_meta_data($socket);
    fclose($socket);

    if (!empty($meta['timed_out'])) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' => 'タイムアウトしました。',
        ];
    }

    $parts = preg_split("/\r\n\r\n|\n\n/", $raw, 2);

    $headerText = $parts[0] ?? '';
    $responseBody = $parts[1] ?? '';

    preg_match(
        '#^HTTP/\S+\s+(\d{3})#m',
        $headerText,
        $match
    );

    $status = isset($match[1]) ? (int)$match[1] : 0;

    $ok = $status >= 200 && $status < 300;

    if (in_array($status, [301, 302, 303, 307, 308], true)) {
        $ok = false;
    }

    return [
        'ok' => $ok,
        'status' => $status,
        'body' => $responseBody,
        'error' => $status === 0
            ? 'レスポンスを取得できませんでした。'
            : null,
    ];
}

function kintoneConfig(): array
{
    return settings()['kintone'];
}

function kintoneAuth(array $cfg): string
{
    if (empty($cfg['username']) || empty($cfg['password'])) {
        throw new RuntimeException(
            'kintoneログイン情報が未設定です。'
        );
    }

    return base64_encode(
        $cfg['username'] . ':' .
        decryptSecret($cfg['password'])
    );
}

function kintoneRequest(
    string $path,
    string $method = 'GET',
    ?string $body = null
): array {
    $cfg = kintoneConfig();

    $host = normalizeKintoneHost(
        (string)$cfg['subdomain']
    );

    $headers = [
        'X-Cybozu-Authorization' => kintoneAuth($cfg),
        'Accept' => 'application/json',
    ];

    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
    }

    return httpRequest(
        $host . $path,
        $headers,
        $body,
        $method,
        (bool)$cfg['verify_ssl'],
        (string)$cfg['proxy']
    );
}

function kintoneError(array $response): string
{
    $json = json_decode(
        (string)$response['body'],
        true
    );

    if (is_array($json)) {
        $code = (string)($json['code'] ?? '');
        $message = (string)($json['message'] ?? '');

        if ($code !== '' || $message !== '') {
            return trim($code . ' ' . $message);
        }
    }

    return match ((int)$response['status']) {
        401, 403 => 'kintone認証に失敗しました。',
        404 => 'kintoneアプリが見つかりません。',
        408 => 'kintoneへの接続がタイムアウトしました。',
        301, 302, 303, 307, 308 =>
            'kintoneからリダイレクトが返されました。',
        default =>
            (string)($response['error']
                ?: 'kintone通信に失敗しました。'),
    };
}

function testKintone(): string
{
    $cfg = kintoneConfig();

    $res = kintoneRequest(
        '/k/v1/app.json?id=' .
        rawurlencode((string)$cfg['app_id'])
    );

    if (!$res['ok']) {
        throw new RuntimeException(kintoneError($res));
    }

    return 'kintoneへの接続・認証に成功しました。';
}

function fetchKintoneFields(): array
{
    $cfg = kintoneConfig();

    $res = kintoneRequest(
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode((string)$cfg['app_id'])
    );

    if (!$res['ok']) {
        throw new RuntimeException(kintoneError($res));
    }

    $json = json_decode($res['body'], true);

    if (!is_array($json) || !isset($json['properties'])) {
        throw new RuntimeException(
            'kintone項目一覧の形式が不正です。'
        );
    }

    return $json['properties'];
}

function kintoneRecords(): array
{
    $cfg = kintoneConfig();
    $result = [];
    $offset = 0;

    do {
        $query = http_build_query([
            'app' => $cfg['app_id'],
            'query' =>
                'order by $id asc limit 500 offset ' . $offset,
        ]);

        $res = kintoneRequest(
            '/k/v1/records.json?' . $query
        );

        if (!$res['ok']) {
            throw new RuntimeException(kintoneError($res));
        }

        $json = json_decode($res['body'], true);

        if (!is_array($json) || !isset($json['records'])) {
            throw new RuntimeException(
                'kintone顧客情報の形式が不正です。'
            );
        }

        $rows = $json['records'];

        $result = array_merge($result, $rows);

        $count = count($rows);
        $offset += $count;
    } while ($count === 500);

    return $result;
}

function kintoneFieldValue(
    array $record,
    string $code
): string {
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (!is_array($value)) {
        return (string)$value;
    }

    $parts = [];

    foreach ($value as $item) {
        if (is_array($item) && isset($item['name'])) {
            $parts[] = (string)$item['name'];
        } elseif (is_array($item) && isset($item['value'])) {
            $parts[] = (string)$item['value'];
        } else {
            $parts[] = (string)$item;
        }
    }

    return implode(' ', $parts);
}

function syncCustomers(): int
{
    $cfg = kintoneConfig();
    $mapping = $cfg['mapping'];

    if (
        empty($mapping['name']) ||
        empty($mapping['email'])
    ) {
        throw new RuntimeException(
            '氏名とメールアドレスのマッピングは必須です。'
        );
    }

    $records = kintoneRecords();
    $result = [];

    foreach ($records as $record) {
        $address = [];

        foreach ($mapping['address'] ?? [] as $code) {
            $value = kintoneFieldValue(
                $record,
                (string)$code
            );

            if ($value !== '') {
                $address[] = $value;
            }
        }

        $result[] = [
            'id' => kintoneFieldValue($record, '$id') ?: uid(),
            'organization' =>
                kintoneFieldValue(
                    $record,
                    (string)($mapping['organization'] ?? '')
                ),
            'name' =>
                kintoneFieldValue(
                    $record,
                    (string)$mapping['name']
                ),
            'email' =>
                kintoneFieldValue(
                    $record,
                    (string)$mapping['email']
                ),
            'department' =>
                kintoneFieldValue(
                    $record,
                    (string)($mapping['department'] ?? '')
                ),
            'phone' =>
                kintoneFieldValue(
                    $record,
                    (string)($mapping['phone'] ?? '')
                ),
            'address' => implode(' ', $address),
            'synced_at' => now(),
        ];
    }

    if (!writeJson('customers', $result)) {
        throw new RuntimeException(
            '顧客情報を保存できませんでした。'
        );
    }

    return count($result);
}

/* =========================================================
 * SMTP
 * ======================================================= */

function smtpConfig(): array
{
    return settings()['smtp'];
}

function smtpRead($socket): array
{
    $lines = [];

    while (($line = fgets($socket, 515)) !== false) {
        $lines[] = rtrim($line, "\r\n");

        if (
            strlen($line) < 4 ||
            ($line[3] ?? '') === ' '
        ) {
            break;
        }
    }

    if (!$lines) {
        return [0, ''];
    }

    $last = end($lines);

    return [
        (int)substr($last, 0, 3),
        implode("\n", $lines),
    ];
}

function smtpCommand($socket, string $command): void
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException(
            'SMTPコマンド送信に失敗しました。'
        );
    }
}

function smtpExpect($socket, array $codes): string
{
    [$code, $text] = smtpRead($socket);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTP応答エラー: ' . $code
        );
    }

    return $text;
}

function smtpConnect()
{
    $cfg = smtpConfig();

    $server = trim((string)$cfg['server']);
    $port = (int)$cfg['port'];
    $encryption = (string)$cfg['encryption'];

    if ($server === '') {
        throw new RuntimeException(
            'SMTPサーバが未設定です。'
        );
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException(
            'SMTPポートが不正です。'
        );
    }

    $scheme = $encryption === 'ssl'
        ? 'ssl'
        : 'tcp';

    $socket = @stream_socket_client(
        $scheme . '://' . $server . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        throw new RuntimeException(
            'SMTPへ接続できません。'
        );
    }

    stream_set_timeout($socket, 20);

    smtpExpect($socket, [220]);

    smtpCommand($socket, 'EHLO localhost');
    smtpExpect($socket, [250]);

    if ($encryption === 'tls') {
        smtpCommand($socket, 'STARTTLS');
        smtpExpect($socket, [220]);

        if (!stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP TLS接続を確立できません。'
            );
        }

        smtpCommand($socket, 'EHLO localhost');
        smtpExpect($socket, [250]);
    }

    if (!empty($cfg['auth'])) {
        if (
            empty($cfg['username']) ||
            empty($cfg['password'])
        ) {
            fclose($socket);

            throw new RuntimeException(
                'SMTP認証情報が未設定です。'
            );
        }

        smtpCommand($socket, 'AUTH LOGIN');
        smtpExpect($socket, [334]);

        smtpCommand(
            $socket,
            base64_encode((string)$cfg['username'])
        );
        smtpExpect($socket, [334]);

        smtpCommand(
            $socket,
            base64_encode(
                decryptSecret((string)$cfg['password'])
            )
        );
        smtpExpect($socket, [235]);
    }

    return $socket;
}

function smtpSend(
    string $to,
    string $subject,
    string $body
): void {
    if (!validEmail($to)) {
        throw new InvalidArgumentException(
            '送信先メールアドレスが不正です。'
        );
    }

    $cfg = smtpConfig();

    if (
        empty($cfg['from_email']) ||
        !validEmail((string)$cfg['from_email'])
    ) {
        throw new RuntimeException(
            '送信元メールアドレスが未設定または不正です。'
        );
    }

    $socket = smtpConnect();

    try {
        smtpCommand(
            $socket,
            'MAIL FROM:<' . $cfg['from_email'] . '>'
        );
        smtpExpect($socket, [250]);

        smtpCommand(
            $socket,
            'RCPT TO:<' . $to . '>'
        );
        smtpExpect($socket, [250, 251]);

        smtpCommand($socket, 'DATA');
        smtpExpect($socket, [354]);

        $headers = [
            'From: ' .
                mb_encode_mimeheader(
                    (string)$cfg['from_name'],
                    'UTF-8'
                ) .
                ' <' . $cfg['from_email'] . '>',
            'To: <' . $to . '>',
            'Subject: ' .
                mb_encode_mimeheader(
                    $subject,
                    'UTF-8'
                ),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (
            !empty($cfg['reply_to']) &&
            validEmail((string)$cfg['reply_to'])
        ) {
            $headers[] =
                'Reply-To: ' . $cfg['reply_to'];
        }

        $body = preg_replace(
            '/^\./m',
            '..',
            $body
        ) ?? $body;

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            $body .
            "\r\n.";

        smtpCommand($socket, $message);
        smtpExpect($socket, [250]);

        smtpCommand($socket, 'QUIT');
        smtpRead($socket);
    } finally {
        fclose($socket);
    }
}

function smtpTest(): void
{
    $socket = smtpConnect();

    smtpCommand($socket, 'QUIT');
    smtpRead($socket);

    fclose($socket);
}

/* =========================================================
 * 回答
 * ======================================================= */

function allQuestions(array $survey): array
{
    $result = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function visibleQuestions(
    array $survey,
    array $answerData
): array {
    $questions = allQuestions($survey);
    $visible = [];

    $skipUntil = null;

    foreach ($questions as $question) {
        if ($skipUntil !== null) {
            if (($question['id'] ?? '') === $skipUntil) {
                $skipUntil = null;
            } else {
                continue;
            }
        }

        $visible[] = $question;
    }

    /*
     * 単一選択のbranchを評価。
     * next_idが指定された場合、その質問へ移動する。
     * 指定がなければ通常順序。
     */
    $result = [];
    $index = 0;

    while ($index < count($questions)) {
        $q = $questions[$index];

        $result[] = $q;

        if (($q['type'] ?? '') === 'single') {
            $answer = $answerData[$q['id']] ?? '';

            $next = $q['branch'][$answer] ?? null;

            if ($next === '__END__') {
                break;
            }

            if (
                is_string($next) &&
                validSurveyId($next)
            ) {
                foreach ($questions as $j => $candidate) {
                    if (($candidate['id'] ?? '') === $next) {
                        $index = $j;
                        continue 2;
                    }
                }
            }
        }

        $index++;
    }

    return $result;
}

/* =========================================================
 * POST処理
 *
 * CSRF検証を行わない。
 * 入力値・ID・業務状態はサーバー側で検証する。
 * ======================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $returnScreen =
        (string)($_POST['return_screen'] ?? 'list');
    $returnId =
        (string)($_POST['return_id'] ?? '');

    try {
        switch ($action) {

            case 'save_survey':
                $id = (string)($_POST['survey_id'] ?? '');

                $survey = $id !== ''
                    ? findSurvey($id)
                    : newSurvey();

                if (!$survey) {
                    throw new InvalidArgumentException(
                        'アンケートがありません。'
                    );
                }

                $survey['title'] =
                    requiredPost('title', 300);

                $survey['description'] =
                    postString('description', 10000);

                $survey['start_at'] =
                    requiredPost('start_at', 30);

                $survey['end_at'] =
                    requiredPost('end_at', 30);

                if (
                    strtotime($survey['start_at']) === false ||
                    strtotime($survey['end_at']) === false
                ) {
                    throw new InvalidArgumentException(
                        '日時が不正です。'
                    );
                }

                if (
                    strtotime($survey['end_at']) <=
                    strtotime($survey['start_at'])
                ) {
                    throw new InvalidArgumentException(
                        '終了日時は開始日時より後にしてください。'
                    );
                }

                $numbering =
                    (string)($_POST['numbering'] ?? 'global');

                if (!in_array(
                    $numbering,
                    ['global', 'group'],
                    true
                )) {
                    throw new InvalidArgumentException(
                        '採番方式が不正です。'
                    );
                }

                $survey['numbering'] = $numbering;
                $survey['updated_at'] = now();

                recalcNumbers($survey);
                saveSurvey($survey);

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                redirectTo('list');
                break;

            case 'transition':
                $id = (string)($_POST['survey_id'] ?? '');
                $to = (string)($_POST['to'] ?? '');

                $survey = findSurvey($id);

                if (!$survey) {
                    throw new InvalidArgumentException(
                        'アンケートがありません。'
                    );
                }

                $survey = normalizeSurvey($survey);

                if (!transitionAllowed(
                    (string)$survey['status'],
                    $to
                )) {
                    throw new InvalidArgumentException(
                        '指定された状態変更はできません。'
                    );
                }

                $survey['status'] = $to;
                $survey['updated_at'] = now();

                saveSurvey($survey);

                flash(
                    'success',
                    '状態を変更しました。'
                );

                redirectTo('list');
                break;

            case 'delete_survey':
                $id = (string)($_POST['survey_id'] ?? '');

                if (!validSurveyId($id)) {
                    throw new InvalidArgumentException(
                        '対象アンケートが不正です。'
                    );
                }

                deleteSurvey($id);

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                redirectTo('list');
                break;

            case 'duplicate_survey':
                $id = (string)($_POST['survey_id'] ?? '');
                $survey = findSurvey($id);

                if (!$survey) {
                    throw new InvalidArgumentException(
                        'アンケートがありません。'
                    );
                }

                $copy = $survey;
                $copy['id'] = uid();
                $copy['title'] .= '（複製）';
                $copy['status'] = '下書き';
                $copy['created_at'] = now();
                $copy['updated_at'] = now();

                foreach ($copy['groups'] as &$group) {
                    $group['id'] = uid();

                    foreach ($group['questions'] as &$question) {
                        $question['id'] = uid();
                    }
                }

                unset($question, $group);

                recalcNumbers($copy);
                saveSurvey($copy);

                flash(
                    'success',
                    'アンケートを複製しました。'
                );

                redirectTo('list');
                break;

            case 'save_kintone':
                $s = settings();

                $host = normalizeKintoneHost(
                    requiredPost('k_subdomain', 255)
                );

                $parts = parse_url($host);

                $appId =
                    (int)($_POST['k_app_id'] ?? 0);

                if ($appId < 1) {
                    throw new InvalidArgumentException(
                        'kintoneアプリIDが不正です。'
                    );
                }

                $s['kintone']['subdomain'] =
                    $host;

                $s['kintone']['app_id'] =
                    $appId;

                $s['kintone']['username'] =
                    requiredPost('k_username', 320);

                $password =
                    postString('k_password', 1000);

                if ($password !== '') {
                    $s['kintone']['password'] =
                        encryptSecret($password);
                }

                $proxy =
                    postString('k_proxy', 255);

                if (
                    $proxy !== '' &&
                    !preg_match(
                        '/^[^:\s]+:\d+$/',
                        $proxy
                    )
                ) {
                    throw new InvalidArgumentException(
                        'Proxyはhost:port形式で指定してください。'
                    );
                }

                $s['kintone']['proxy'] = $proxy;
                $s['kintone']['verify_ssl'] =
                    !empty($_POST['k_verify_ssl']);

                if (!writeJson('settings', $s)) {
                    throw new RuntimeException(
                        'kintone設定を保存できませんでした。'
                    );
                }

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                redirectTo('kintone');
                break;

            case 'test_kintone':
                testKintone();

                flash(
                    'success',
                    'kintoneへの接続・認証に成功しました。'
                );

                redirectTo('kintone');
                break;

            case 'fetch_kintone_fields':
                $s = settings();

                $s['kintone']['fields'] =
                    fetchKintoneFields();

                if (!writeJson('settings', $s)) {
                    throw new RuntimeException(
                        'kintone項目情報を保存できませんでした。'
                    );
                }

                flash(
                    'success',
                    '項目一覧を再取得しました。'
                );

                redirectTo('kintone');
                break;

            case 'save_mapping':
                $s = settings();
                $fields = $s['kintone']['fields'] ?? [];

                $mapping = [
                    'organization' =>
                        postString('map_organization', 100),
                    'name' =>
                        requiredPost('map_name', 100),
                    'email' =>
                        requiredPost('map_email', 100),
                    'department' =>
                        postString('map_department', 100),
                    'phone' =>
                        postString('map_phone', 100),
                    'address' => [],
                ];

                $addresses =
                    $_POST['map_address'] ?? [];

                if (is_array($addresses)) {
                    foreach ($addresses as $code) {
                        $code = (string)$code;

                        if (isset($fields[$code])) {
                            $mapping['address'][] = $code;
                        }
                    }
                }

                $s['kintone']['mapping'] = $mapping;

                if (!writeJson('settings', $s)) {
                    throw new RuntimeException(
                        'マッピングを保存できませんでした。'
                    );
                }

                flash(
                    'success',
                    'マッピングを保存しました。'
                );

                redirectTo('kintone');
                break;

            case 'sync_customers':
                $count = syncCustomers();

                flash(
                    'success',
                    $count . '件の顧客情報を同期しました。'
                );

                redirectTo('kintone');
                break;

            case 'save_smtp':
                $s = settings();

                $server =
                    requiredPost('smtp_server', 255);

                $port =
                    (int)($_POST['smtp_port'] ?? 0);

                if ($port < 1 || $port > 65535) {
                    throw new InvalidArgumentException(
                        'SMTPポートが不正です。'
                    );
                }

                $encryption =
                    (string)($_POST['smtp_encryption'] ?? 'tls');

                if (!in_array(
                    $encryption,
                    ['ssl', 'tls', 'none'],
                    true
                )) {
                    throw new InvalidArgumentException(
                        '暗号化方式が不正です。'
                    );
                }

                $from =
                    requiredPost('smtp_from_email', 320);

                if (!validEmail($from)) {
                    throw new InvalidArgumentException(
                        '送信元メールアドレスが不正です。'
                    );
                }

                $reply =
                    postString('smtp_reply_to', 320);

                if (
                    $reply !== '' &&
                    !validEmail($reply)
                ) {
                    throw new InvalidArgumentException(
                        '返信先メールアドレスが不正です。'
                    );
                }

                $s['smtp']['server'] = $server;
                $s['smtp']['port'] = $port;
                $s['smtp']['encryption'] = $encryption;
                $s['smtp']['auth'] =
                    !empty($_POST['smtp_auth']);
                $s['smtp']['username'] =
                    postString('smtp_username', 320);
                $s['smtp']['from_email'] = $from;
                $s['smtp']['from_name'] =
                    postString('smtp_from_name', 200);
                $s['smtp']['reply_to'] = $reply;
                $s['smtp']['status'] = '未設定';

                $password =
                    postString('smtp_password', 1000);

                if ($password !== '') {
                    $s['smtp']['password'] =
                        encryptSecret($password);
                }

                if (!writeJson('settings', $s)) {
                    throw new RuntimeException(
                        'SMTP設定を保存できませんでした。'
                    );
                }

                flash(
                    'success',
                    'SMTP設定を保存しました。'
                );

                redirectTo('mail');
                break;

            case 'test_smtp':
                smtpTest();

                $s = settings();
                $s['smtp']['status'] = '接続確認済み';
                writeJson('settings', $s);

                flash(
                    'success',
                    'SMTP接続・認証に成功しました。'
                );

                redirectTo('mail');
                break;

            case 'send_test_mail':
                $to =
                    requiredPost('test_mail_to', 320);

                if (!validEmail($to)) {
                    throw new InvalidArgumentException(
                        'テストメール宛先が不正です。'
                    );
                }

                smtpSend(
                    $to,
                    'アンケートアプリ テストメール',
                    'SMTP接続テストメールです。'
                );

                flash(
                    'success',
                    'テストメールを送信しました。'
                );

                redirectTo('mail');
                break;

            case 'send_mails':
                $surveyId =
                    (string)($_POST['survey_id'] ?? '');

                $survey = findSurvey($surveyId);

                if (!$survey) {
                    throw new InvalidArgumentException(
                        '対象アンケートがありません。'
                    );
                }

                $selected =
                    $_POST['customers'] ?? [];

                if (
                    !is_array($selected) ||
                    !$selected
                ) {
                    throw new InvalidArgumentException(
                        '送信対象を選択してください。'
                    );
                }

                $subject =
                    requiredPost('mail_subject', 500);

                $body =
                    requiredPost('mail_body', 10000);

                $scheme = $https ? 'https' : 'http';

                $base =
                    $scheme . '://' .
                    ($_SERVER['HTTP_HOST'] ?? '') .
                    dirname($_SERVER['SCRIPT_NAME'] ?? '/');

                $surveyUrl =
                    rtrim($base, '/') .
                    '/index.php?screen=answer&id=' .
                    rawurlencode($surveyId);

                $map = [];

                foreach (customers() as $customer) {
                    $map[(string)$customer['id']] =
                        $customer;
                }

                $success = 0;
                $failed = 0;

                foreach ($selected as $customerId) {
                    $customerId = (string)$customerId;

                    if (!isset($map[$customerId])) {
                        $failed++;
                        continue;
                    }

                    $customer = $map[$customerId];

                    $replace = [
                        '{顧客名}' =>
                            (string)$customer['name'],
                        '{アンケートURL}' =>
                            $surveyUrl,
                    ];

                    $personalSubject =
                        strtr($subject, $replace);

                    $personalBody =
                        strtr($body, $replace);

                    $status = '送信失敗';

                    try {
                        smtpSend(
                            (string)$customer['email'],
                            $personalSubject,
                            $personalBody
                        );

                        $status = '送信成功';
                        $success++;
                    } catch (Throwable) {
                        $failed++;
                    }

                    appendJson(
                        'send_logs',
                        [
                            'id' => uid(),
                            'survey_id' => $surveyId,
                            'customer_id' => $customerId,
                            'customer_name' =>
                                (string)$customer['name'],
                            'email' =>
                                (string)$customer['email'],
                            'status' => $status,
                            'created_at' => now(),
                        ]
                    );
                }

                flash(
                    $failed ? 'danger' : 'success',
                    "送信結果：成功 {$success}件 / 失敗 {$failed}件"
                );

                redirectTo(
                    'send',
                    ['id' => $surveyId]
                );
                break;

            case 'answer_preview':
                $id =
                    (string)($_POST['survey_id'] ?? '');

                $survey = findSurvey($id);

                if (!$survey) {
                    throw new InvalidArgumentException(
                        'アンケートがありません。'
                    );
                }

                $answerData =
                    is_array($_POST['answer'] ?? null)
                    ? $_POST['answer']
                    : [];

                $survey = normalizeSurvey($survey);

                if ($survey['status'] !== '公開中') {
                    throw new InvalidArgumentException(
                        'このアンケートは現在回答できません。'
                    );
                }

                foreach (allQuestions($survey) as $q) {
                    $value = $answerData[$q['id']] ?? '';

                    if (($q['required'] ?? false)) {
                        $empty =
                            is_array($value)
                            ? count($value) === 0
                            : trim((string)$value) === '';

                        if ($empty) {
                            throw new InvalidArgumentException(
                                $q['number'] . ' は必須です。'
                            );
                        }
                    }
                }

                $_SESSION['answer_' . $id] =
                    $answerData;

                redirectTo(
                    'confirm',
                    ['id' => $id]
                );
                break;

            case 'answer_submit':
                $id =
                    (string)($_POST['survey_id'] ?? '');

                $survey = findSurvey($id);

                if (!$survey) {
                    throw new InvalidArgumentException(
                        'アンケートがありません。'
                    );
                }

                $survey = normalizeSurvey($survey);

                if ($survey['status'] !== '公開中') {
                    throw new InvalidArgumentException(
                        'このアンケートは現在回答できません。'
                    );
                }

                $data =
                    $_SESSION['answer_' . $id] ?? null;

                if (!is_array($data)) {
                    throw new RuntimeException(
                        '回答情報が見つかりません。最初からやり直してください。'
                    );
                }

                if (!appendJson(
                    'answers',
                    [
                        'id' => uid(),
                        'survey_id' => $id,
                        'answers' => $data,
                        'created_at' => now(),
                    ]
                )) {
                    throw new RuntimeException(
                        '回答を保存できませんでした。'
                    );
                }

                unset($_SESSION['answer_' . $id]);

                redirectTo(
                    'complete',
                    ['id' => $id]
                );
                break;

            default:
                throw new InvalidArgumentException(
                    '不正な操作です。'
                );
        }
    } catch (Throwable $ex) {
        flash(
            'danger',
            $ex instanceof InvalidArgumentException
                ? $ex->getMessage()
                : '処理に失敗しました。設定・入力内容・外部サービスの状態を確認してください。'
        );

        if (
            $returnId !== '' &&
            in_array(
                $returnScreen,
                [
                    'list',
                    'edit',
                    'preview',
                    'send',
                    'analytics',
                    'answer',
                    'confirm',
                    'complete'
                ],
                true
            )
        ) {
            redirectTo(
                $returnScreen,
                ['id' => $returnId]
            );
        }

        redirectTo(
            in_array(
                $returnScreen,
                ['kintone', 'mail'],
                true
            )
                ? $returnScreen
                : 'list'
        );
    }
}

/* =========================================================
 * CSV
 * ======================================================= */

function csvDownload(string $id): never
{
    $survey = findSurvey($id);

    if (!$survey) {
        fail('対象アンケートがありません。', 404);
    }

    $rows = array_filter(
        answers(),
        static fn(array $a): bool =>
            ($a['survey_id'] ?? '') === $id
    );

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="answers.csv"'
    );

    $out = fopen('php://output', 'w');

    fwrite($out, "\xEF\xBB\xBF");

    $header = ['回答ID', '回答日時'];

    foreach (allQuestions($survey) as $q) {
        $header[] = $q['number'];
        $header[] = $q['text'];
    }

    fputcsv($out, $header);

    foreach ($rows as $answer) {
        $line = [
            $answer['id'] ?? '',
            $answer['created_at'] ?? '',
        ];

        foreach (allQuestions($survey) as $q) {
            $v =
                $answer['answers'][$q['id']] ?? '';

            if (is_array($v)) {
                $v = implode(', ', $v);
            }

            $line[] = $q['number'];
            $line[] = $v;
        }

        fputcsv($out, $line);
    }

    fclose($out);
    exit;
}

/* =========================================================
 * PDF
 *
 * 外部PDFライブラリに依存しないPOC用簡易出力。
 * ======================================================= */

function pdfDownload(string $id): never
{
    $survey = findSurvey($id);

    if (!$survey) {
        fail('対象アンケートがありません。', 404);
    }

    $text =
        $survey['title'] .
        "\n回答数: " .
        count(array_filter(
            answers(),
            static fn(array $a): bool =>
                ($a['survey_id'] ?? '') === $id
        ));

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="answers.pdf"'
    );

    /*
     * 最小PDF。
     * 日本語本文はPDF標準14フォントの制約があるため、
     * POCではASCII情報を中心に出力する。
     */
    $text = preg_replace(
        '/[^\x20-\x7E]/',
        '?',
        $text
    );

    $stream =
        "BT /F1 12 Tf 50 750 Td (" .
        str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $text
        ) .
        ") Tj ET";

    $objects = [];

    $objects[] =
        "<< /Type /Catalog /Pages 2 0 R >>";

    $objects[] =
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";

    $objects[] =
        "<< /Type /Page /Parent 2 0 R " .
        "/MediaBox [0 0 612 792] " .
        "/Resources << /Font << /F1 4 0 R >> >> " .
        "/Contents 5 0 R >>";

    $objects[] =
        "<< /Type /Font /Subtype /Type1 " .
        "/BaseFont /Helvetica >>";

    $objects[] =
        "<< /Length " . strlen($stream) . " >>\n" .
        "stream\n" .
        $stream .
        "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .=
            ($i + 1) .
            " 0 obj\n" .
            $object .
            "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .=
        "xref\n0 " .
        (count($objects) + 1) .
        "\n0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .=
        "trailer\n<< /Size " .
        (count($objects) + 1) .
        " /Root 1 0 R >>\n" .
        "startxref\n" .
        $xref .
        "\n%%EOF";

    echo $pdf;
    exit;
}

/* =========================================================
 * HTML
 * ======================================================= */

function renderHead(
    string $title,
    bool $admin = true
): void {
    global $https;

    echo '<!doctype html>';
    echo '<html lang="ja">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>' . e($title) . ' - ' .
        APP_NAME . '</title>';

    echo <<<CSS
<style>
:root{
 --primary:#2563eb;
 --primary-dark:#1d4ed8;
 --success:#16a34a;
 --warning:#d97706;
 --danger:#dc2626;
 --gray:#64748b;
 --border:#dbe2ea;
 --text:#1e293b;
 --bg:#f8fafc;
 --white:#fff;
 --shadow:0 4px 18px rgba(15,23,42,.08)
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
 font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
 "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
 color:var(--text);
 background:var(--bg);
}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
a{color:var(--primary);text-decoration:none}
a:hover{text-decoration:underline}
.page{max-width:1500px;margin:auto;padding:28px}
.header{
 min-height:64px;background:#0f172a;color:#fff;
 display:flex;align-items:center;padding:12px 24px;
 gap:22px;flex-wrap:wrap
}
.logo{font-weight:700;font-size:18px;white-space:nowrap}
.nav{display:flex;gap:5px;flex-wrap:wrap}
.nav a{color:#cbd5e1;padding:9px 12px;border-radius:7px}
.nav a:hover,.nav a.active{
 color:#fff;background:#1e293b;text-decoration:none
}
.card{
 background:#fff;border:1px solid var(--border);
 border-radius:12px;box-shadow:var(--shadow);
 margin-bottom:20px
}
.card-header{
 padding:17px 20px;border-bottom:1px solid var(--border);
 display:flex;justify-content:space-between;gap:12px;align-items:center
}
.card-body{padding:20px}
.title{
 display:flex;justify-content:space-between;gap:15px;
 align-items:flex-start;margin-bottom:24px
}
.title h1{margin:0;font-size:26px}
.title p{margin:6px 0;color:var(--gray);font-size:13px}
.grid{
 display:grid;grid-template-columns:repeat(2,minmax(0,1fr));
 gap:18px
}
.full{grid-column:1/-1}
.field{display:flex;flex-direction:column;gap:7px}
label{font-size:13px;font-weight:600}
input[type=text],input[type=email],input[type=password],
input[type=datetime-local],input[type=number],textarea,select{
 width:100%;border:1px solid #cbd5e1;border-radius:7px;
 padding:10px 12px;background:#fff;color:var(--text)
}
textarea{min-height:110px;resize:vertical}
.btn{
 display:inline-flex;align-items:center;justify-content:center;
 border:1px solid var(--border);background:#fff;color:var(--text);
 border-radius:7px;padding:9px 14px;min-height:40px
}
.btn:hover{background:#f8fafc;text-decoration:none}
.primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.success{background:var(--success);color:#fff;border-color:var(--success)}
.danger{background:var(--danger);color:#fff;border-color:var(--danger)}
.warning{background:var(--warning);color:#fff;border-color:var(--warning)}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.alert{
 padding:13px 15px;border-radius:8px;margin-bottom:18px
}
.alert-success{background:#dcfce7;color:#166534}
.alert-danger{background:#fee2e2;color:#991b1b}
.alert-warning{background:#fef3c7;color:#92400e}
.badge{
 display:inline-flex;padding:4px 9px;border-radius:999px;
 font-size:12px;font-weight:600
}
.draft{background:#e2e8f0;color:#475569}
.published{background:#dcfce7;color:#166534}
.stopped{background:#fef3c7;color:#92400e}
.ended{background:#fee2e2;color:#991b1b}
.table-wrap{overflow-x:auto}
table{border-collapse:collapse;width:100%;min-width:900px}
th,td{
 padding:11px 10px;border-bottom:1px solid var(--border);
 text-align:left;vertical-align:top
}
th{background:#f8fafc;white-space:nowrap;font-size:12px}
td{font-size:13px}
.question{
 border:1px solid var(--border);border-radius:9px;
 padding:16px;margin-bottom:12px;background:#fff
}
.group{
 border:1px solid #cbd5e1;border-radius:10px;
 margin-bottom:18px;background:#f8fafc
}
.group-head{
 display:flex;justify-content:space-between;
 align-items:center;padding:14px;gap:10px
}
.question-body{padding:0 14px 14px}
.help{color:var(--gray);font-size:12px}
.answer-page{
 max-width:760px;margin:auto;padding:20px
}
.answer-header{
 background:#0f172a;color:#fff;padding:20px;
 border-radius:12px;margin-bottom:20px
}
.answer-nav{
 display:flex;justify-content:space-between;gap:10px;
 margin-top:20px
}
@media(max-width:800px){
 .grid{grid-template-columns:1fr}
 .full{grid-column:auto}
 .page{padding:16px}
 .header{padding:12px 16px}
 .title h1{font-size:22px}
}
</style>
CSS;

    echo '</head><body>';

    if ($admin) {
        echo '<header class="header">';
        echo '<div class="logo">' . APP_NAME . '</div>';
        echo '<nav class="nav">';
        echo '<a href="index.php?screen=list">アンケート</a>';
        echo '<a href="index.php?screen=kintone">kintone設定</a>';
        echo '<a href="index.php?screen=mail">メール設定</a>';
        echo '</nav>';
        echo '</header>';
    }
}

function renderFoot(): void
{
    echo <<<HTML
<script>
function confirmAction(message){
 return window.confirm(message);
}
function togglePassword(id){
 const e=document.getElementById(id);
 if(!e)return;
 e.type=e.type==="password"?"text":"password";
}
function copyText(text){
 if(!navigator.clipboard ||
    !document.hasFocus()){
   fallbackCopy(text);
   return;
 }
 navigator.clipboard.writeText(text).catch(function(){
   fallbackCopy(text);
 });
}
function fallbackCopy(text){
 const t=document.createElement("textarea");
 t.value=text;
 t.style.position="fixed";
 t.style.left="-9999px";
 document.body.appendChild(t);
 t.focus();
 t.select();
 try{document.execCommand("copy")}catch(e){}
 document.body.removeChild(t);
}
</script>
</body></html>
HTML;
}

function renderFlash(): void
{
    $flash = getFlash();

    if (!$flash) {
        return;
    }

    $class =
        $flash['type'] === 'success'
        ? 'alert-success'
        : 'alert-danger';

    echo '<div class="alert ' . $class . '">' .
        e($flash['message']) .
        '</div>';
}

function renderError(
    string $message,
    int $status = 500
): void {
    renderHead('エラー', true);

    echo '<main class="page">';
    echo '<div class="card">';
    echo '<div class="card-body">';
    echo '<h1>処理エラー</h1>';
    echo '<div class="alert alert-danger">' .
        e($message) .
        '</div>';
    echo '<a class="btn primary" href="index.php?screen=list">';
    echo 'アンケート一覧へ';
    echo '</a>';
    echo '</div></div></main>';

    renderFoot();
}

/* =========================================================
 * 一覧
 * ======================================================= */

function statusClass(string $status): string
{
    return match ($status) {
        '公開中' => 'published',
        '停止' => 'stopped',
        '終了' => 'ended',
        default => 'draft',
    };
}

function renderList(): void
{
    $items = [];

    foreach (surveys() as $survey) {
        $survey = normalizeSurvey($survey);
        $items[] = $survey;
    }

    $keyword =
        trim((string)($_GET['q'] ?? ''));

    $status =
        (string)($_GET['status'] ?? '');

    $sort =
        (string)($_GET['sort'] ?? 'updated_desc');

    if ($keyword !== '') {
        $items = array_filter(
            $items,
            static fn(array $s): bool =>
                mb_stripos(
                    (string)($s['title'] ?? ''),
                    $keyword
                ) !== false
        );
    }

    if (
        $status !== '' &&
        $status !== 'すべて'
    ) {
        $items = array_filter(
            $items,
            static fn(array $s): bool =>
                ($s['status'] ?? '') === $status
        );
    }

    usort(
        $items,
        static function(array $a, array $b) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updated_at'],
                        (string)$b['updated_at']
                    ),
                'answers_desc' =>
                    countAnswers((string)$b['id']) <=>
                    countAnswers((string)$a['id']),
                'answers_asc' =>
                    countAnswers((string)$a['id']) <=>
                    countAnswers((string)$b['id']),
                'start_desc' =>
                    strcmp(
                        (string)$b['start_at'],
                        (string)$a['start_at']
                    ),
                'start_asc' =>
                    strcmp(
                        (string)$a['start_at'],
                        (string)$b['start_at']
                    ),
                default =>
                    strcmp(
                        (string)$b['updated_at'],
                        (string)$a['updated_at']
                    ),
            };
        }
    );

    renderHead('アンケート一覧');

    echo '<main class="page">';
    echo '<div class="title">';
    echo '<div><h1>アンケート一覧</h1>';
    echo '<p>アンケート管理の起点</p></div>';
    echo '<a class="btn primary" href="index.php?screen=edit">';
    echo '新規作成';
    echo '</a>';
    echo '</div>';

    renderFlash();

    echo '<div class="card"><div class="card-body">';
    echo '<form method="get">';
    echo '<input type="hidden" name="screen" value="list">';
    echo '<div class="grid">';
    echo '<div class="field">';
    echo '<label>タイトル検索</label>';
    echo '<input type="text" name="q" value="' .
        e($keyword) . '" placeholder="タイトルを入力">';
    echo '</div>';
    echo '<div class="field">';
    echo '<label>ステータス</label>';
    echo '<select name="status">';
    foreach (
        ['すべて','公開中','下書き','停止','終了']
        as $v
    ) {
        echo '<option value="' . e($v) . '"' .
            ($status === $v ||
             ($status === '' && $v === 'すべて')
                ? ' selected' : '') .
            '>' . e($v) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="field">';
    echo '<label>ソート</label>';
    echo '<select name="sort">';
    $sorts = [
        'updated_desc' => '更新日：新しい順',
        'updated_asc' => '更新日：古い順',
        'answers_desc' => '回答数：多い順',
        'answers_asc' => '回答数：少ない順',
        'start_desc' => '開始日：新しい順',
        'start_asc' => '開始日：古い順',
    ];
    foreach ($sorts as $key => $label) {
        echo '<option value="' . e($key) . '"' .
            ($sort === $key ? ' selected' : '') .
            '>' . e($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';
    echo '<div class="actions" style="margin-top:15px">';
    echo '<button class="btn primary" type="submit">検索</button>';
    echo '</div>';
    echo '</form>';
    echo '</div></div>';

    echo '<div class="card"><div class="card-body">';
    echo '<div class="table-wrap"><table>';
    echo '<thead><tr>';
    echo '<th>タイトル</th><th>作成日</th>';
    echo '<th>更新日</th><th>期間</th>';
    echo '<th>ステータス</th><th>回答数</th>';
    echo '<th>操作</th>';
    echo '</tr></thead><tbody>';

    foreach ($items as $survey) {
        $id = (string)$survey['id'];
        $count = countAnswers($id);

        echo '<tr>';
        echo '<td>' . e($survey['title']) . '</td>';
        echo '<td>' . e($survey['created_at']) . '</td>';
        echo '<td>' . e($survey['updated_at']) . '</td>';
        echo '<td>' .
            e($survey['start_at']) .
            '<br>～<br>' .
            e($survey['end_at']) .
            '</td>';
        echo '<td><span class="badge ' .
            statusClass((string)$survey['status']) .
            '">' .
            e($survey['status']) .
            '</span></td>';
        echo '<td>' . $count . '</td>';
        echo '<td><div class="actions">';
        echo '<a class="btn" href="index.php?screen=edit&id=' .
            rawurlencode($id) . '">確認・編集</a>';
        echo '<a class="btn" href="index.php?screen=preview&id=' .
            rawurlencode($id) . '">プレビュー</a>';
        echo '<a class="btn" href="index.php?screen=send&id=' .
            rawurlencode($id) . '">送信</a>';
        echo '<a class="btn" href="index.php?screen=analytics&id=' .
            rawurlencode($id) . '">集計</a>';

        echo '<form method="post" style="display:inline">';
        echo '<input type="hidden" name="action" value="duplicate_survey">';
        echo '<input type="hidden" name="survey_id" value="' .
            e($id) . '">';
        echo '<button class="btn" type="submit">複製</button>';
        echo '</form>';

        if ($survey['status'] !== '終了') {
            if (
                $survey['status'] === '下書き' ||
                $survey['status'] === '停止'
            ) {
                echo '<form method="post" style="display:inline">';
                echo '<input type="hidden" name="action" value="transition">';
                echo '<input type="hidden" name="survey_id" value="' .
                    e($id) . '">';
                echo '<input type="hidden" name="to" value="公開中">';
                echo '<button class="btn success" type="submit">';
                echo '公開';
                echo '</button></form>';
            }

            if ($survey['status'] === '公開中') {
                echo '<form method="post" style="display:inline">';
                echo '<input type="hidden" name="action" value="transition">';
                echo '<input type="hidden" name="survey_id" value="' .
                    e($id) . '">';
                echo '<input type="hidden" name="to" value="停止">';
                echo '<button class="btn warning" type="submit">';
                echo '停止';
                echo '</button></form>';
            }
        }

        echo '<form method="post" style="display:inline"';
        echo ' onsubmit="return confirmAction(\'削除しますか？\')">';
        echo '<input type="hidden" name="action" value="delete_survey">';
        echo '<input type="hidden" name="survey_id" value="' .
            e($id) . '">';
        echo '<button class="btn danger" type="submit">削除</button>';
        echo '</form>';

        echo '</div></td>';
        echo '</tr>';
    }

    if (!$items) {
        echo '<tr><td colspan="7">アンケートはありません。</td></tr>';
    }

    echo '</tbody></table></div>';
    echo '</div></div>';
    echo '</main>';

    renderFoot();
}

function countAnswers(string $surveyId): int
{
    $count = 0;

    foreach (answers() as $answer) {
        if (($answer['survey_id'] ?? '') === $surveyId) {
            $count++;
        }
    }

    return $count;
}

/* =========================================================
 * 編集
 * ======================================================= */

function renderEdit(string $id = ''): void
{
    $survey = $id !== ''
        ? findSurvey($id)
        : newSurvey();

    if (!$survey) {
        fail('対象アンケートがありません。', 404);
    }

    renderHead(
        $id === '' ? 'アンケート作成' : 'アンケート編集'
    );

    echo '<main class="page">';
    echo '<div class="title">';
    echo '<div><h1>' .
        ($id === '' ? 'アンケート作成' : 'アンケート編集') .
        '</h1></div>';
    echo '</div>';

    renderFlash();

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="save_survey">';
    echo '<input type="hidden" name="survey_id" value="' .
        e($survey['id']) . '">';

    echo '<div class="card"><div class="card-body">';
    echo '<div class="grid">';

    echo '<div class="field full">';
    echo '<label>タイトル *</label>';
    echo '<input type="text" name="title" required value="' .
        e($survey['title']) . '">';
    echo '</div>';

    echo '<div class="field full">';
    echo '<label>説明</label>';
    echo '<textarea name="description">' .
        e($survey['description']) .
        '</textarea>';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>開始日時 *</label>';
    echo '<input type="datetime-local" name="start_at" required value="' .
        e($survey['start_at']) . '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>終了日時 *</label>';
    echo '<input type="datetime-local" name="end_at" required value="' .
        e($survey['end_at']) . '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>質問番号</label>';
    echo '<select name="numbering">';
    echo '<option value="global"' .
        ($survey['numbering'] === 'global'
            ? ' selected' : '') .
        '>全体通番：Q1、Q2...</option>';
    echo '<option value="group"' .
        ($survey['numbering'] === 'group'
            ? ' selected' : '') .
        '>グループ単位：Q1-1、Q1-2...</option>';
    echo '</select>';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>現在の状態</label>';
    echo '<input type="text" readonly value="' .
        e($survey['status']) . '">';
    echo '</div>';

    echo '</div>';
    echo '</div></div>';

    foreach ($survey['groups'] as $gi => $group) {
        echo '<div class="group">';
        echo '<div class="group-head">';
        echo '<strong>グループ ' .
            ($gi + 1) .
            '：' .
            e($group['title']) .
            '</strong>';
        echo '</div>';

        echo '<div class="question-body">';

        foreach ($group['questions'] as $q) {
            echo '<div class="question">';
            echo '<strong>' .
                e($q['number']) .
                '</strong>';

            echo '<div class="field" style="margin-top:10px">';
            echo '<label>質問文</label>';
            echo '<input type="text" value="' .
                e($q['text']) .
                '" readonly>';
            echo '</div>';

            echo '<div class="help" style="margin-top:8px">';
            echo '回答形式：' .
                e(match ($q['type']) {
                    'single' => '単一選択',
                    'multiple' => '複数選択',
                    default => '自由記述',
                }) .
                ' / ' .
                (($q['required'] ?? false)
                    ? '必須' : '任意');
            echo '</div>';

            if (!empty($q['options'])) {
                echo '<div class="help">選択肢：' .
                    e(implode(' / ', $q['options'])) .
                    '</div>';
            }

            echo '</div>';
        }

        echo '</div></div>';
    }

    echo '<div class="actions">';
    echo '<button class="btn primary" type="submit">';
    echo '保存して一覧へ';
    echo '</button>';
    echo '<a class="btn" href="index.php?screen=list">';
    echo 'キャンセル';
    echo '</a>';
    echo '</div>';

    echo '</form>';
    echo '</main>';

    renderFoot();
}

/* =========================================================
 * プレビュー
 * ======================================================= */

function renderPreview(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        fail('対象アンケートがありません。', 404);
    }

    renderHead('プレビュー');

    echo '<main class="answer-page">';
    echo '<div class="answer-header">';
    echo '<h1>' . e($survey['title']) . '</h1>';
    echo '<div>' . nl2br(e($survey['description'])) . '</div>';
    echo '</div>';

    foreach ($survey['groups'] as $group) {
        echo '<div class="card">';
        echo '<div class="card-header"><strong>' .
            e($group['title']) .
            '</strong></div>';
        echo '<div class="card-body">';

        foreach ($group['questions'] as $q) {
            echo '<div class="question">';
            echo '<strong>' . e($q['number']) . '</strong> ';
            echo e($q['text']);

            if (($q['required'] ?? false)) {
                echo ' <span class="badge ended">必須</span>';
            }

            if ($q['type'] === 'single') {
                foreach ($q['options'] as $option) {
                    echo '<label style="display:block;margin-top:9px">';
                    echo '<input type="radio" disabled> ';
                    echo e($option);
                    echo '</label>';
                }
            } elseif ($q['type'] === 'multiple') {
                foreach ($q['options'] as $option) {
                    echo '<label style="display:block;margin-top:9px">';
                    echo '<input type="checkbox" disabled> ';
                    echo e($option);
                    echo '</label>';
                }
            } else {
                echo '<textarea disabled style="margin-top:10px"></textarea>';
            }

            echo '</div>';
        }

        echo '</div></div>';
    }

    echo '<a class="btn" href="index.php?screen=list">';
    echo '一覧へ';
    echo '</a>';

    echo '</main>';

    renderFoot();
}

/* =========================================================
 * 送信
 * ======================================================= */

function renderSend(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        fail('対象アンケートがありません。', 404);
    }

    $customerRows = customers();

    $keyword =
        trim((string)($_GET['q'] ?? ''));

    if ($keyword !== '') {
        $customerRows = array_filter(
            $customerRows,
            static fn(array $c): bool =>
                mb_stripos(
                    implode(' ', [
                        $c['organization'] ?? '',
                        $c['name'] ?? '',
                        $c['email'] ?? '',
                        $c['department'] ?? '',
                    ]),
                    $keyword
                ) !== false
        );
    }

    renderHead('顧客選択・メール送信');

    echo '<main class="page">';
    echo '<div class="title">';
    echo '<div><h1>顧客選択・メール送信</h1>';
    echo '<p>対象：' . e($survey['title']) . '</p></div>';
    echo '</div>';

    renderFlash();

    echo '<div class="card"><div class="card-body">';
    echo '<form method="get">';
    echo '<input type="hidden" name="screen" value="send">';
    echo '<input type="hidden" name="id" value="' .
        e($id) . '">';
    echo '<div class="actions">';
    echo '<input type="text" name="q" value="' .
        e($keyword) .
        '" placeholder="顧客検索" style="max-width:400px">';
    echo '<button class="btn" type="submit">検索</button>';
    echo '</div>';
    echo '</form>';
    echo '</div></div>';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="send_mails">';
    echo '<input type="hidden" name="survey_id" value="' .
        e($id) . '">';

    echo '<div class="card"><div class="card-body">';
    echo '<div class="table-wrap"><table>';
    echo '<thead><tr>';
    echo '<th>選択</th><th>組織名</th>';
    echo '<th>氏名</th><th>メール</th>';
    echo '<th>部署</th><th>電話</th>';
    echo '</tr></thead><tbody>';

    foreach ($customerRows as $customer) {
        echo '<tr>';
        echo '<td><input type="checkbox" name="customers[]" value="' .
            e($customer['id']) . '"></td>';
        echo '<td>' . e($customer['organization'] ?? '') . '</td>';
        echo '<td>' . e($customer['name'] ?? '') . '</td>';
        echo '<td>' . e($customer['email'] ?? '') . '</td>';
        echo '<td>' . e($customer['department'] ?? '') . '</td>';
        echo '<td>' . e($customer['phone'] ?? '') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '</div></div>';

    echo '<div class="card"><div class="card-body">';
    echo '<div class="grid">';

    echo '<div class="field full">';
    echo '<label>件名 *</label>';
    echo '<input type="text" name="mail_subject" required';
    echo ' value="【アンケート】ご回答のお願い">';
    echo '</div>';

    echo '<div class="field full">';
    echo '<label>本文 *</label>';
    echo '<textarea name="mail_body" required>';
    echo e(
        "{顧客名} 様\n\n" .
        "以下のURLよりアンケートへご回答ください。\n" .
        "{アンケートURL}\n"
    );
    echo '</textarea>';
    echo '<div class="help">';
    echo '{顧客名} と {アンケートURL} が使用できます。';
    echo '</div>';
    echo '</div>';

    echo '</div>';
    echo '</div></div>';

    echo '<div class="actions">';
    echo '<button class="btn primary" type="submit">';
    echo '一括送信';
    echo '</button>';
    echo '</div>';

    echo '</form>';

    echo '<div class="card"><div class="card-header">';
    echo '<strong>送信履歴</strong>';
    echo '</div><div class="card-body">';
    echo '<div class="table-wrap"><table>';
    echo '<tr><th>日時</th><th>顧客</th>';
    echo '<th>メール</th><th>状態</th></tr>';

    foreach (array_reverse(sendLogs()) as $log) {
        if (($log['survey_id'] ?? '') !== $id) {
            continue;
        }

        echo '<tr>';
        echo '<td>' . e($log['created_at']) . '</td>';
        echo '<td>' . e($log['customer_name']) . '</td>';
        echo '<td>' . e($log['email']) . '</td>';
        echo '<td>' . e($log['status']) . '</td>';
        echo '</tr>';
    }

    echo '</table></div>';
    echo '</div></div>';

    echo '</main>';

    renderFoot();
}

/* =========================================================
 * 集計
 * ======================================================= */

function renderAnalytics(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        fail('対象アンケートがありません。', 404);
    }

    $rows = array_values(array_filter(
        answers(),
        static fn(array $a): bool =>
            ($a['survey_id'] ?? '') === $id
    ));

    $customersCount = count(array_filter(
        sendLogs(),
        static fn(array $l): bool =>
            ($l['survey_id'] ?? '') === $id
    ));

    renderHead('回答集計・分析');

    echo '<main class="page">';
    echo '<div class="title">';
    echo '<div><h1>回答集計・分析</h1>';
    echo '<p>対象：' . e($survey['title']) . '</p></div>';
    echo '</div>';

    renderFlash();

    echo '<div class="actions" style="margin-bottom:20px">';
    echo '<a class="btn" href="index.php?screen=csv&id=' .
        e($id) . '">CSV出力</a>';
    echo '<a class="btn" href="index.php?screen=pdf&id=' .
        e($id) . '">PDF出力</a>';
    echo '</div>';

    echo '<div class="grid">';

    $stats = [
        '送信対象者数' => $customersCount,
        '回答数' => count($rows),
        '未登録回答数' => 0,
        '未回答数' => max(0, $customersCount - count($rows)),
        '回答率' =>
            $customersCount > 0
            ? round(count($rows) / $customersCount * 100, 1) . '%'
            : '0%',
    ];

    foreach ($stats as $label => $value) {
        echo '<div class="card"><div class="card-body">';
        echo '<div class="help">' . e($label) . '</div>';
        echo '<div style="font-size:28px;font-weight:700">';
        echo e((string)$value);
        echo '</div>';
        echo '</div></div>';
    }

    echo '</div>';

    if (!$rows) {
        echo '<div class="alert alert-warning">';
        echo '現在、回答データはありません';
        echo '</div>';
    } else {
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<strong>設問別集計</strong>';
        echo '</div>';
        echo '<div class="card-body">';

        foreach (allQuestions($survey) as $q) {
            $values = [];

            foreach ($rows as $row) {
                $v =
                    $row['answers'][$q['id']] ?? '';

                if (is_array($v)) {
                    foreach ($v as $item) {
                        $values[] = (string)$item;
                    }
                } else {
                    $values[] = (string)$v;
                }
            }

            $counts = array_count_values(
                array_filter(
                    $values,
                    static fn(string $v): bool =>
                        trim($v) !== ''
                )
            );

            echo '<div class="question">';
            echo '<strong>' . e($q['number']) . '</strong> ';
            echo e($q['text']);

            if (!$counts) {
                echo '<div class="help">回答なし</div>';
            } else {
                echo '<ul>';

                foreach ($counts as $value => $count) {
                    echo '<li>' .
                        e($value) .
                        '：' .
                        $count .
                        '件</li>';
                }

                echo '</ul>';
            }

            echo '</div>';
        }

        echo '</div></div>';

        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<strong>個別回答</strong>';
        echo '</div>';
        echo '<div class="card-body">';

        foreach ($rows as $row) {
            echo '<div class="question">';
            echo '<div class="help">' .
                e($row['created_at'] ?? '') .
                '</div>';

            foreach (allQuestions($survey) as $q) {
                $v =
                    $row['answers'][$q['id']] ?? '';

                if (is_array($v)) {
                    $v = implode(', ', $v);
                }

                echo '<div style="margin-top:8px">';
                echo '<strong>' .
                    e($q['number']) .
                    '</strong> ';
                echo e($v);
                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div></div>';
    }

    echo '</main>';

    renderFoot();
}

/* =========================================================
 * kintone画面
 * ======================================================= */

function renderKintone(): void
{
    $cfg = settings()['kintone'];
    $fields = $cfg['fields'] ?? [];
    $mapping = $cfg['mapping'] ?? [];

    renderHead('kintone設定');

    echo '<main class="page">';
    echo '<div class="title">';
    echo '<div><h1>kintone設定</h1>';
    echo '<p>顧客情報の取得元</p></div>';
    echo '</div>';

    renderFlash();

    echo '<div class="card"><div class="card-body">';
    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="save_kintone">';

    echo '<div class="grid">';

    echo '<div class="field full">';
    echo '<label>サブドメイン *</label>';
    echo '<input type="text" name="k_subdomain" required value="' .
        e($cfg['subdomain']) . '">';
    echo '<div class="help">';
    echo 'https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx';
    echo '</div></div>';

    echo '<div class="field">';
    echo '<label>顧客管理アプリID *</label>';
    echo '<input type="number" name="k_app_id" min="1" required value="' .
        e((string)$cfg['app_id']) . '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>ログイン名 *</label>';
    echo '<input type="text" name="k_username" required value="' .
        e($cfg['username']) . '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>パスワード</label>';
    echo '<input type="password" name="k_password" placeholder="' .
        (!empty($cfg['password'])
            ? '設定済み（変更時のみ入力）'
            : '') .
        '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>Proxy</label>';
    echo '<input type="text" name="k_proxy" value="' .
        e($cfg['proxy']) .
        '" placeholder="host:port">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>SSL証明書検証</label>';
    echo '<label>';
    echo '<input type="checkbox" name="k_verify_ssl"' .
        (!empty($cfg['verify_ssl'])
            ? ' checked' : '') .
        '> 有効';
    echo '</label>';
    echo '<div class="help">POCでは無効を許容</div>';
    echo '</div>';

    echo '</div>';

    echo '<div class="actions" style="margin-top:18px">';
    echo '<button class="btn primary" type="submit">設定保存</button>';
    echo '</div>';
    echo '</form>';

    echo '<hr>';

    foreach (
        [
            'test_kintone' =>
                '接続テスト',
            'fetch_kintone_fields' =>
                '項目一覧を再取得',
            'sync_customers' =>
                '顧客情報を同期',
        ] as $action => $label
    ) {
        echo '<form method="post" style="display:inline">';
        echo '<input type="hidden" name="action" value="' .
            e($action) . '">';
        echo '<button class="btn" type="submit">' .
            e($label) .
            '</button>';
        echo '</form> ';
    }

    echo '</div></div>';

    echo '<div class="card"><div class="card-header">';
    echo '<strong>顧客情報マッピング</strong>';
    echo '</div><div class="card-body">';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="save_mapping">';

    echo '<div class="grid">';

    $map = [
        'map_organization' => ['組織名', 'organization'],
        'map_name' => ['氏名', 'name'],
        'map_email' => ['メールアドレス', 'email'],
        'map_department' => ['部署名', 'department'],
        'map_phone' => ['電話番号', 'phone'],
    ];

    foreach ($map as $field => [$label, $key]) {
        echo '<div class="field">';
        echo '<label>' . e($label) .
            (($key === 'name' || $key === 'email')
                ? ' *' : '') .
            '</label>';
        echo '<select name="' . e($field) . '">';
        echo '<option value="">-- 選択 --</option>';

        foreach ($fields as $code => $definition) {
            echo '<option value="' .
                e($code) . '"' .
                (($mapping[$key] ?? '') === $code
                    ? ' selected' : '') .
                '>' .
                e(
                    $code . ' / ' .
                    ($definition['label'] ?? '')
                ) .
                '</option>';
        }

        echo '</select>';
        echo '</div>';
    }

    echo '<div class="field full">';
    echo '<label>住所（複数選択可）</label>';

    foreach ($fields as $code => $definition) {
        echo '<label style="display:inline-block;margin-right:15px">';
        echo '<input type="checkbox" name="map_address[]" value="' .
            e($code) . '"' .
            (
                in_array(
                    $code,
                    $mapping['address'] ?? [],
                    true
                )
                    ? ' checked' : ''
            ) .
            '> ' .
            e($code) .
            '</label>';
    }

    echo '</div>';

    echo '</div>';

    echo '<button class="btn primary" style="margin-top:15px">';
    echo 'マッピング保存';
    echo '</button>';

    echo '</form>';
    echo '</div></div>';

    echo '</main>';

    renderFoot();
}

/* =========================================================
 * メール設定
 * ======================================================= */

function renderMail(): void
{
    $cfg = settings()['smtp'];

    renderHead('メールサーバ設定');

    echo '<main class="page">';
    echo '<div class="title">';
    echo '<div><h1>メールサーバ設定</h1>';
    echo '<p>SMTP接続・認証・送信設定</p></div>';
    echo '<span class="badge ' .
        (
            $cfg['status'] === '接続確認済み'
                ? 'published'
                : (
                    $cfg['status'] === '接続できません'
                        ? 'ended'
                        : 'draft'
                )
        ) .
        '">' .
        e($cfg['status']) .
        '</span>';
    echo '</div>';

    renderFlash();

    echo '<div class="card"><div class="card-body">';
    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="save_smtp">';

    echo '<div class="grid">';

    echo '<div class="field">';
    echo '<label>SMTPサーバ *</label>';
    echo '<input type="text" name="smtp_server" required value="' .
        e($cfg['server']) . '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>SMTPポート *</label>';
    echo '<input type="number" name="smtp_port" min="1" max="65535" required value="' .
        e((string)$cfg['port']) . '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>暗号化方式</label>';
    echo '<select name="smtp_encryption">';
    foreach (
        [
            'ssl' => 'SSL',
            'tls' => 'TLS',
            'none' => 'なし',
        ] as $key => $label
    ) {
        echo '<option value="' . e($key) . '"' .
            ($cfg['encryption'] === $key
                ? ' selected' : '') .
            '>' . e($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>SMTP認証</label>';
    echo '<label>';
    echo '<input type="checkbox" name="smtp_auth"' .
        (!empty($cfg['auth'])
            ? ' checked' : '') .
        '> 使用する';
    echo '</label>';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>SMTPユーザー名</label>';
    echo '<input type="text" name="smtp_username" value="' .
        e($cfg['username']) . '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>SMTPパスワード</label>';
    echo '<input type="password" name="smtp_password" placeholder="' .
        (!empty($cfg['password'])
            ? '設定済み（変更時のみ入力）'
            : '') .
        '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>送信元メールアドレス *</label>';
    echo '<input type="email" name="smtp_from_email" required value="' .
        e($cfg['from_email']) . '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>送信元名</label>';
    echo '<input type="text" name="smtp_from_name" value="' .
        e($cfg['from_name']) . '">';
    echo '</div>';

    echo '<div class="field">';
    echo '<label>返信先メールアドレス</label>';
    echo '<input type="email" name="smtp_reply_to" value="' .
        e($cfg['reply_to']) . '">';
    echo '</div>';

    echo '</div>';

    echo '<div class="actions" style="margin-top:18px">';
    echo '<button class="btn primary">設定保存</button>';
    echo '</div>';

    echo '</form>';

    echo '<hr>';

    echo '<form method="post" style="display:inline">';
    echo '<input type="hidden" name="action" value="test_smtp">';
    echo '<button class="btn" type="submit">接続テスト</button>';
    echo '</form>';

    echo '<form method="post" style="display:inline;margin-left:8px">';
    echo '<input type="hidden" name="action" value="send_test_mail">';
    echo '<div style="display:inline-flex;gap:8px">';
    echo '<input type="email" name="test_mail_to" required';
    echo ' placeholder="テスト送信先">';
    echo '<button class="btn success" type="submit">';
    echo 'テストメール送信';
    echo '</button>';
    echo '</div>';
    echo '</form>';

    echo '</div></div>';
    echo '</main>';

    renderFoot();
}

/* =========================================================
 * 回答画面
 * ======================================================= */

function renderAnswer(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        renderAnswerMessage('アンケートURLが不正です。');
        return;
    }

    $survey = normalizeSurvey($survey);

    if ($survey['status'] !== '公開中') {
        renderAnswerMessage(
            'このアンケートは現在回答できません。'
        );
        return;
    }

    renderHead('アンケート回答', false);

    echo '<main class="answer-page">';
    echo '<div class="answer-header">';
    echo '<h1>' . e($survey['title']) . '</h1>';
    echo '<div>' .
        nl2br(e($survey['description'])) .
        '</div>';
    echo '</div>';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="answer_preview">';
    echo '<input type="hidden" name="survey_id" value="' .
        e($id) . '">';

    foreach ($survey['groups'] as $group) {
        echo '<div class="card">';
        echo '<div class="card-header"><strong>' .
            e($group['title']) .
            '</strong></div>';
        echo '<div class="card-body">';

        foreach ($group['questions'] as $q) {
            echo '<div class="question">';
            echo '<strong>' .
                e($q['number']) .
                '</strong> ' .
                e($q['text']);

            if ($q['required']) {
                echo ' <span class="badge ended">必須</span>';
            }

            if ($q['type'] === 'single') {
                foreach ($q['options'] as $option) {
                    echo '<label style="display:block;margin-top:12px">';
                    echo '<input type="radio" required="' .
                        ($q['required'] ? 'required' : '') .
                        '" name="answer[' .
                        e($q['id']) .
                        ']" value="' .
                        e($option) .
                        '"> ' .
                        e($option);
                    echo '</label>';
                }
            } elseif ($q['type'] === 'multiple') {
                foreach ($q['options'] as $option) {
                    echo '<label style="display:block;margin-top:12px">';
                    echo '<input type="checkbox" name="answer[' .
                        e($q['id']) .
                        '][]" value="' .
                        e($option) .
                        '"> ' .
                        e($option);
                    echo '</label>';
                }
            } else {
                echo '<textarea name="answer[' .
                    e($q['id']) .
                    ']"' .
                    ($q['required'] ? ' required' : '') .
                    '></textarea>';
            }

            echo '</div>';
        }

        echo '</div></div>';
    }

    echo '<div class="answer-nav">';
    echo '<span></span>';
    echo '<button class="btn primary" type="submit">';
    echo '回答確認';
    echo '</button>';
    echo '</div>';

    echo '</form>';
    echo '</main>';

    renderFoot();
}

function renderConfirm(string $id): void
{
    $survey = findSurvey($id);

    if (!$survey) {
        renderAnswerMessage('アンケートURLが不正です。');
        return;
    }

    $data =
        $_SESSION['answer_' . $id] ?? [];

    if (!is_array($data)) {
        renderAnswerMessage(
            '回答情報が見つかりません。最初からやり直してください。'
        );
        return;
    }

    renderHead('回答確認', false);

    echo '<main class="answer-page">';
    echo '<div class="answer-header">';
    echo '<h1>回答確認</h1>';
    echo '<div>' . e($survey['title']) . '</div>';
    echo '</div>';

    foreach (allQuestions($survey) as $q) {
        $v = $data[$q['id']] ?? '';

        if (is_array($v)) {
            $v = implode(', ', $v);
        }

        echo '<div class="card">';
        echo '<div class="card-body">';
        echo '<strong>' . e($q['number']) . '</strong> ';
        echo e($q['text']);
        echo '<div style="margin-top:10px;white-space:pre-wrap">';
        echo e((string)$v);
        echo '</div>';
        echo '</div></div>';
    }

    echo '<div class="answer-nav">';

    echo '<a class="btn" href="index.php?screen=answer&id=' .
        e($id) .
        '">修正する</a>';

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="answer_submit">';
    echo '<input type="hidden" name="survey_id" value="' .
        e($id) . '">';
    echo '<button class="btn primary" type="submit">';
    echo '回答送信';
    echo '</button>';
    echo '</form>';

    echo '</div>';
    echo '</main>';

    renderFoot();
}

function renderComplete(string $id): void
{
    $survey = findSurvey($id);

    renderHead('回答完了', false);

    echo '<main class="answer-page">';
    echo '<div class="card">';
    echo '<div class="card-body" style="text-align:center">';
    echo '<h1>回答ありがとうございました</h1>';

    if ($survey) {
        echo '<p>' .
            e($survey['title']) .
            '</p>';
    }

    echo '<p>回答は正常に送信されました。</p>';
    echo '<p class="help">';
    echo 'この回答者フローはここで終了します。';
    echo '</p>';
    echo '</div></div>';
    echo '</main>';

    renderFoot();
}

function renderAnswerMessage(string $message): void
{
    renderHead('アンケート', false);

    echo '<main class="answer-page">';
    echo '<div class="card">';
    echo '<div class="card-body">';
    echo '<h1>アンケート</h1>';
    echo '<div class="alert alert-warning">' .
        e($message) .
        '</div>';
    echo '</div></div>';
    echo '</main>';

    renderFoot();
}

/* =========================================================
 * ルーティング
 * ======================================================= */

try {
    refreshStatuses();

    $screen =
        (string)($_GET['screen'] ?? 'list');

    switch ($screen) {

        case 'list':
            renderList();
            break;

        case 'edit':
            renderEdit(
                (string)($_GET['id'] ?? '')
            );
            break;

        case 'preview':
            $id =
                (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                fail(
                    '対象アンケートが指定されていません。',
                    400
                );
            }

            renderPreview($id);
            break;

        case 'send':
            $id =
                (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                fail(
                    '対象アンケートが指定されていません。',
                    400
                );
            }

            renderSend($id);
            break;

        case 'analytics':
            $id =
                (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                fail(
                    '対象アンケートが指定されていません。',
                    400
                );
            }

            renderAnalytics($id);
            break;

        case 'kintone':
            renderKintone();
            break;

        case 'mail':
            renderMail();
            break;

        case 'answer':
            $id =
                (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                renderAnswerMessage(
                    'アンケートURLが不正です。'
                );
                break;
            }

            renderAnswer($id);
            break;

        case 'confirm':
            $id =
                (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                renderAnswerMessage(
                    'アンケートURLが不正です。'
                );
                break;
            }

            renderConfirm($id);
            break;

        case 'complete':
            $id =
                (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                renderAnswerMessage(
                    'アンケートURLが不正です。'
                );
                break;
            }

            renderComplete($id);
            break;

        case 'csv':
            $id =
                (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                fail(
                    '対象アンケートが指定されていません。',
                    400
                );
            }

            csvDownload($id);
            break;

        case 'pdf':
            $id =
                (string)($_GET['id'] ?? '');

            if (!validSurveyId($id)) {
                fail(
                    '対象アンケートが指定されていません。',
                    400
                );
            }

            pdfDownload($id);
            break;

        default:
            redirectTo('list');
    }

} catch (Throwable $ex) {
    /*
     * 秘密情報・内部パス・スタックトレースは表示しない。
     */
    http_response_code(500);

    renderError(
        'システムエラーが発生しました。設定またはシステム管理者へ確認してください。',
        500
    );
}
?>

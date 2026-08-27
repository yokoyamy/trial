<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * Apache 2.4 + PHP 8.5
 *
 * 制約:
 * - DB不使用
 * - PHP cURL不使用
 * - PHP mail()不使用
 * - Canvas不使用
 * - 管理者認証なし
 * - index.php単一エントリーポイント
 * - サーバー側ファイル永続化
 * - kintone実接続
 * - SMTP実接続
 */

const APP_TITLE = 'アンケート管理システム';

const DATA_DIR = __DIR__ . '/data';
const SURVEY_FILE = DATA_DIR . '/surveys.json';
const ANSWER_FILE = DATA_DIR . '/answers.json';
const CUSTOMER_FILE = DATA_DIR . '/customers.json';
const HISTORY_FILE = DATA_DIR . '/mail_history.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';

const HTTP_CONNECT_TIMEOUT = 8;
const HTTP_READ_TIMEOUT = 20;

/* =========================================================
 * Session
 * ======================================================= */

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') ?: '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* =========================================================
 * Storage
 * ======================================================= */

function ensure_storage(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0770, true);
    }

    $files = [
        SURVEY_FILE => [],
        ANSWER_FILE => [],
        CUSTOMER_FILE => [],
        HISTORY_FILE => [],
        SETTINGS_FILE => [
            'kintone' => [
                'subdomain' => '',
                'app_id' => '',
                'username' => '',
                'password' => '',
                'proxy' => '',
                'verify_ssl' => false,
                'mappings' => [
                    'organization' => '',
                    'name' => '',
                    'email' => '',
                    'department' => '',
                    'phone' => '',
                    'address' => [],
                ],
            ],
            'mail' => [
                'smtp_server' => '',
                'smtp_port' => 587,
                'encryption' => 'tls',
                'auth' => true,
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => '',
                'reply_to' => '',
                'status' => 'unset',
            ],
        ],
    ];

    foreach ($files as $file => $default) {
        if (!file_exists($file)) {
            atomic_write_json($file, $default);
        }
    }
}

function atomic_write_json(string $file, mixed $data): bool
{
    $dir = dirname($file);

    if (!is_dir($dir) && !mkdir($dir, 0770, true)) {
        return false;
    }

    $tmp = tempnam($dir, '.tmp_');

    if ($tmp === false) {
        return false;
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        @unlink($tmp);
        return false;
    }

    $ok = file_put_contents($tmp, $json, LOCK_EX) !== false;

    if ($ok) {
        $ok = rename($tmp, $file);
    }

    if (!$ok) {
        @unlink($tmp);
    }

    return $ok;
}

function read_json(string $file, mixed $default = []): mixed
{
    if (!file_exists($file)) {
        return $default;
    }

    $fp = fopen($file, 'rb');

    if (!$fp) {
        return $default;
    }

    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($content === false || trim($content) === '') {
        return $default;
    }

    $data = json_decode($content, true);

    return is_array($data) ? $data : $default;
}

function surveys(): array
{
    return read_json(SURVEY_FILE, []);
}

function answers(): array
{
    return read_json(ANSWER_FILE, []);
}

function customers(): array
{
    return read_json(CUSTOMER_FILE, []);
}

function histories(): array
{
    return read_json(HISTORY_FILE, []);
}

function settings(): array
{
    return read_json(SETTINGS_FILE, [
        'kintone' => [],
        'mail' => [],
    ]);
}

ensure_storage();

/* =========================================================
 * Utilities
 * ======================================================= */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_screen(string $screen, array $params = []): never
{
    $params = array_merge(['screen' => $screen], $params);
    header('Location: index.php?' . http_build_query($params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';

    if (
        !is_string($token)
        || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)
    ) {
        http_response_code(400);
        exit('不正なリクエストです。');
    }
}

function post_string(string $name, string $default = ''): string
{
    $v = $_POST[$name] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function post_int(string $name, int $default = 0): int
{
    $v = $_POST[$name] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function now_iso(): string
{
    return date('c');
}

function local_datetime(string $value): ?DateTimeImmutable
{
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable) {
        return null;
    }
}

function status_label(string $status): string
{
    return match ($status) {
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => $status,
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'badge-published',
        'stopped' => 'badge-stopped',
        'ended' => 'badge-ended',
        default => 'badge-draft',
    };
}

/* =========================================================
 * Survey status
 * ======================================================= */

function normalize_survey_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = local_datetime((string)$survey['endAt']);

        if ($end && $end->getTimestamp() < time()) {
            $survey['status'] = 'ended';
            return true;
        }
    }

    return false;
}

function normalize_all_statuses(): void
{
    $list = surveys();
    $changed = false;

    foreach ($list as &$survey) {
        if (normalize_survey_status($survey)) {
            $survey['updatedAt'] = now_iso();
            $changed = true;
        }
    }
    unset($survey);

    if ($changed) {
        atomic_write_json(SURVEY_FILE, $list);
    }
}

function find_survey(string $id): ?array
{
    $list = surveys();

    foreach ($list as $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function find_survey_index(string $id): int
{
    $list = surveys();

    foreach ($list as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

/* =========================================================
 * Numbering
 * ======================================================= */

function renumber_questions(array &$survey): void
{
    $groups =& $survey['groups'];

    if (($survey['numbering'] ?? 'global') === 'group') {
        foreach ($groups as $gi => &$group) {
            foreach ($group['questions'] as $qi => &$question) {
                $question['number'] = 'Q' . ($gi + 1) . '-' . ($qi + 1);
            }
            unset($question);
        }
        unset($group);
        return;
    }

    $n = 1;

    foreach ($groups as &$group) {
        foreach ($group['questions'] as &$question) {
            $question['number'] = 'Q' . $n++;
        }
        unset($question);
    }
    unset($group);
}

/* =========================================================
 * Validation
 * ======================================================= */

function validate_survey_input(array $input): array
{
    $errors = [];

    $title = trim((string)($input['title'] ?? ''));

    if ($title === '') {
        $errors[] = 'アンケートタイトルは必須です。';
    }

    if (mb_strlen($title) > 200) {
        $errors[] = 'アンケートタイトルは200文字以内で入力してください。';
    }

    $start = local_datetime((string)($input['startAt'] ?? ''));
    $end = local_datetime((string)($input['endAt'] ?? ''));

    if (!$start) {
        $errors[] = '開始日時が正しくありません。';
    }

    if (!$end) {
        $errors[] = '終了日時が正しくありません。';
    }

    if ($start && $end && $start >= $end) {
        $errors[] = '終了日時は開始日時より後にしてください。';
    }

    $numbering = (string)($input['numbering'] ?? 'global');

    if (!in_array($numbering, ['global', 'group'], true)) {
        $errors[] = '質問番号の採番方式が不正です。';
    }

    return $errors;
}

/* =========================================================
 * HTTP client without cURL
 * ======================================================= */

function http_request(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    string $proxy = '',
    bool $verifySsl = false
): array {
    $method = strtoupper($method);

    $urlParts = parse_url($url);

    if (!$urlParts || empty($urlParts['host'])) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' => 'URLが不正です。',
        ];
    }

    $headers[] = 'Connection: close';

    $headerText = implode("\r\n", $headers);

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => $headerText,
            'content' => $body ?? '',
            'timeout' => HTTP_READ_TIMEOUT,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ],
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
        ],
    ];

    if ($proxy !== '') {
        $proxy = trim($proxy);

        if (!preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Proxyは host:port 形式で入力してください。',
            ];
        }

        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $warning = null;

    set_error_handler(
        static function ($severity, $message) use (&$warning): bool {
            $warning = $message;
            return true;
        }
    );

    $response = file_get_contents($url, false, $context);

    restore_error_handler();

    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    if ($response === false) {
        return [
            'ok' => false,
            'status' => $status,
            'body' => '',
            'error' => $warning ?: '外部サービスへの通信に失敗しました。',
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $response,
        'error' => $status >= 200 && $status < 300
            ? ''
            : 'HTTPステータス ' . $status,
    ];
}

/* =========================================================
 * kintone
 * ======================================================= */

function normalize_kintone_domain(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $value = preg_replace('#^https?://#i', '', $value);
    $value = trim($value, '/');

    if (preg_match('/^([A-Za-z0-9_-]+)\.cybozu\.com$/', $value)) {
        return 'https://' . $value;
    }

    if (preg_match('/^([A-Za-z0-9_-]+)$/', $value)) {
        return 'https://' . $value . '.cybozu.com';
    }

    return null;
}

function kintone_auth_header(array $config): string
{
    return 'X-Cybozu-Authorization: ' .
        base64_encode(
            (string)($config['username'] ?? '')
            . ':'
            . (string)($config['password'] ?? '')
        );
}

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $payload = null
): array {
    $domain = normalize_kintone_domain((string)($config['subdomain'] ?? ''));

    if (!$domain) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' => 'kintoneサブドメインが不正です。',
        ];
    }

    $url = $domain . $path;

    $headers = [
        'X-Cybozu-Authorization: ' . base64_encode(
            (string)($config['username'] ?? '')
            . ':'
            . (string)($config['password'] ?? '')
        ),
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $body = $payload === null
        ? null
        : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return http_request(
        $method,
        $url,
        $headers,
        $body,
        (string)($config['proxy'] ?? ''),
        (bool)($config['verify_ssl'] ?? false)
    );
}

function kintone_connection_test(array $config): array
{
    $appId = (int)($config['app_id'] ?? 0);

    if ($appId <= 0) {
        return [
            'ok' => false,
            'message' => '顧客管理アプリIDが正しくありません。',
        ];
    }

    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id=' . rawurlencode((string)$appId)
    );

    if (!$result['ok']) {
        $detail = '';

        $json = json_decode($result['body'], true);

        if (is_array($json)) {
            $detail = (string)($json['message'] ?? $json['id'] ?? '');
        }

        return [
            'ok' => false,
            'message' => 'kintoneへの接続に失敗しました。'
                . ($detail !== '' ? ' ' . $detail : ''),
        ];
    }

    $data = json_decode($result['body'], true);

    return [
        'ok' => true,
        'message' => 'kintoneへの接続に成功しました。'
            . (!empty($data['name']) ? ' アプリ: ' . $data['name'] : ''),
    ];
}

function kintone_get_fields(array $config): array
{
    $appId = (int)($config['app_id'] ?? 0);

    $result = kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app=' . rawurlencode((string)$appId)
    );

    if (!$result['ok']) {
        return [
            'ok' => false,
            'message' => '項目一覧の取得に失敗しました。',
            'fields' => [],
        ];
    }

    $data = json_decode($result['body'], true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'message' => 'kintoneから不正なレスポンスが返されました。',
            'fields' => [],
        ];
    }

    return [
        'ok' => true,
        'message' => '項目一覧を取得しました。',
        'fields' => $data['properties'] ?? [],
    ];
}

function kintone_sync_customers(array $config): array
{
    $appId = (int)($config['app_id'] ?? 0);

    $fields = [
        'organization' => (string)($config['mappings']['organization'] ?? ''),
        'name' => (string)($config['mappings']['name'] ?? ''),
        'email' => (string)($config['mappings']['email'] ?? ''),
        'department' => (string)($config['mappings']['department'] ?? ''),
        'phone' => (string)($config['mappings']['phone'] ?? ''),
    ];

    $address = array_values(array_filter(
        (array)($config['mappings']['address'] ?? ''),
        static fn($v) => is_string($v) && $v !== ''
    ));

    $query = '';
    $allRecords = [];
    $offset = 0;

    do {
        $params = [
            'app' => $appId,
            'query' => trim($query . ' limit 500 offset ' . $offset),
            'totalCount' => true,
        ];

        $result = kintone_request(
            $config,
            'GET',
            '/k/v1/records.json?' . http_build_query($params)
        );

        if (!$result['ok']) {
            return [
                'ok' => false,
                'message' => '顧客情報の同期に失敗しました。',
                'count' => 0,
            ];
        }

        $data = json_decode($result['body'], true);

        if (!is_array($data)) {
            return [
                'ok' => false,
                'message' => 'kintoneの応答を解析できませんでした。',
                'count' => 0,
            ];
        }

        $records = $data['records'] ?? [];

        foreach ($records as $record) {
            $get = static function (string $code) use ($record): string {
                if ($code === '' || !isset($record[$code])) {
                    return '';
                }

                $value = $record[$code]['value'] ?? '';

                if (is_array($value)) {
                    return implode(', ', array_map(
                        static fn($v) => is_scalar($v) ? (string)$v : '',
                        $value
                    ));
                }

                return is_scalar($value) ? (string)$value : '';
            };

            $addressValues = [];

            foreach ($address as $code) {
                $v = $get($code);

                if ($v !== '') {
                    $addressValues[] = $v;
                }
            }

            $allRecords[] = [
                'id' => new_id('customer'),
                'kintoneRecordId' => $get('$id'),
                'organization' => $get($fields['organization']),
                'name' => $get($fields['name']),
                'email' => $get($fields['email']),
                'department' => $get($fields['department']),
                'phone' => $get($fields['phone']),
                'address' => implode(' ', $addressValues),
                'updatedAt' => now_iso(),
            ];
        }

        $count = count($records);
        $offset += $count;
    } while ($count === 500);

    atomic_write_json(CUSTOMER_FILE, $allRecords);

    return [
        'ok' => true,
        'message' => count($allRecords) . '件の顧客情報を同期しました。',
        'count' => count($allRecords),
    ];
}

/* =========================================================
 * SMTP
 * ======================================================= */

function smtp_read($fp): string
{
    $result = '';

    while (($line = fgets($fp, 512)) !== false) {
        $result .= $line;

        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    return $result;
}

function smtp_expect($fp, array $codes): array
{
    $response = smtp_read($fp);

    $code = (int)substr($response, 0, 3);

    return [
        'ok' => in_array($code, $codes, true),
        'code' => $code,
        'response' => $response,
    ];
}

function smtp_command($fp, string $command, array $codes): array
{
    fwrite($fp, $command . "\r\n");
    return smtp_expect($fp, $codes);
}

function smtp_connect_test(array $config): array
{
    $host = trim((string)($config['smtp_server'] ?? ''));
    $port = (int)($config['smtp_port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'tls');

    if ($host === '' || $port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'message' => 'SMTPサーバまたはポートが正しくありません。',
        ];
    }

    $transport = match ($encryption) {
        'ssl' => 'ssl://',
        default => 'tcp://',
    };

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        HTTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        return [
            'ok' => false,
            'message' => 'SMTPサーバへ接続できません。'
                . ($errstr !== '' ? ' ' . $errstr : ''),
        ];
    }

    stream_set_timeout($fp, HTTP_READ_TIMEOUT);

    $hello = smtp_expect($fp, [220]);

    if (!$hello['ok']) {
        fclose($fp);
        return [
            'ok' => false,
            'message' => 'SMTPサーバから正常な応答がありません。',
        ];
    }

    $ehlo = smtp_command($fp, 'EHLO localhost', [250]);

    if (!$ehlo['ok']) {
        fclose($fp);
        return [
            'ok' => false,
            'message' => 'EHLOに失敗しました。',
        ];
    }

    if ($encryption === 'tls') {
        $tls = smtp_command($fp, 'STARTTLS', [220]);

        if (!$tls['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'STARTTLSに失敗しました。',
            ];
        }

        $crypto = stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'TLS接続を確立できませんでした。',
            ];
        }

        $ehlo = smtp_command($fp, 'EHLO localhost', [250]);

        if (!$ehlo['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'TLS後のEHLOに失敗しました。',
            ];
        }
    }

    $auth = (bool)($config['auth'] ?? true);

    if ($auth) {
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');

        if ($username === '' || $password === '') {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'SMTP認証情報が設定されていません。',
            ];
        }

        $r = smtp_command($fp, 'AUTH LOGIN', [334]);

        if (!$r['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'SMTP認証を開始できません。',
            ];
        }

        $r = smtp_command($fp, base64_encode($username), [334]);

        if (!$r['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'SMTPユーザー認証に失敗しました。',
            ];
        }

        $r = smtp_command($fp, base64_encode($password), [235]);

        if (!$r['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'SMTPパスワード認証に失敗しました。',
            ];
        }
    }

    smtp_command($fp, 'QUIT', [221, 250]);
    fclose($fp);

    return [
        'ok' => true,
        'message' => 'SMTPサーバへの接続に成功しました。',
    ];
}

function smtp_send(
    array $config,
    string $to,
    string $subject,
    string $body
): array {
    $test = smtp_connect_socket($config);

    if (!$test['ok']) {
        return $test;
    }

    $fp = $test['fp'];

    $from = (string)($config['from_email'] ?? '');
    $fromName = (string)($config['from_name'] ?? '');
    $replyTo = (string)($config['reply_to'] ?? '');

    $headers = [];

    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = 'From: ' . format_mailbox($from, $fromName);
    $headers[] = 'To: ' . $to;
    $headers[] = 'Subject: ' . '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $mailData = implode("\r\n", $headers)
        . "\r\n\r\n"
        . str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));

    $r = smtp_command($fp, 'MAIL FROM:<' . $from . '>', [250]);

    if (!$r['ok']) {
        fclose($fp);
        return [
            'ok' => false,
            'message' => 'MAIL FROMに失敗しました。',
        ];
    }

    $r = smtp_command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);

    if (!$r['ok']) {
        fclose($fp);
        return [
            'ok' => false,
            'message' => '宛先をSMTPサーバが受け付けませんでした。',
        ];
    }

    $r = smtp_command($fp, 'DATA', [354]);

    if (!$r['ok']) {
        fclose($fp);
        return [
            'ok' => false,
            'message' => 'DATAコマンドに失敗しました。',
        ];
    }

    fwrite($fp, $mailData . "\r\n.\r\n");

    $r = smtp_expect($fp, [250]);

    smtp_command($fp, 'QUIT', [221, 250]);
    fclose($fp);

    return [
        'ok' => $r['ok'],
        'message' => $r['ok']
            ? 'メールを送信しました。'
            : 'SMTPサーバがメール送信を受け付けませんでした。',
    ];
}

function smtp_connect_socket(array $config): array
{
    $host = trim((string)($config['smtp_server'] ?? ''));
    $port = (int)($config['smtp_port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'tls');

    if ($host === '' || $port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'message' => 'SMTP設定が不正です。',
        ];
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        HTTP_CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        return [
            'ok' => false,
            'message' => 'SMTP接続に失敗しました。',
        ];
    }

    stream_set_timeout($fp, HTTP_READ_TIMEOUT);

    $r = smtp_expect($fp, [220]);

    if (!$r['ok']) {
        fclose($fp);
        return [
            'ok' => false,
            'message' => 'SMTPサーバの初期応答が不正です。',
        ];
    }

    $r = smtp_command($fp, 'EHLO localhost', [250]);

    if (!$r['ok']) {
        fclose($fp);
        return [
            'ok' => false,
            'message' => 'EHLOに失敗しました。',
        ];
    }

    if ($encryption === 'tls') {
        $r = smtp_command($fp, 'STARTTLS', [220]);

        if (!$r['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'STARTTLSに失敗しました。',
            ];
        }

        $crypto = stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'TLSを確立できませんでした。',
            ];
        }

        $r = smtp_command($fp, 'EHLO localhost', [250]);

        if (!$r['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'TLS後のEHLOに失敗しました。',
            ];
        }
    }

    if ((bool)($config['auth'] ?? true)) {
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');

        $r = smtp_command($fp, 'AUTH LOGIN', [334]);

        if (!$r['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'SMTP AUTH LOGINに対応していません。',
            ];
        }

        $r = smtp_command($fp, base64_encode($username), [334]);

        if (!$r['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'SMTPユーザー名が拒否されました。',
            ];
        }

        $r = smtp_command($fp, base64_encode($password), [235]);

        if (!$r['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'message' => 'SMTP認証に失敗しました。',
            ];
        }
    }

    return [
        'ok' => true,
        'fp' => $fp,
    ];
}

function format_mailbox(string $email, string $name): string
{
    if ($name === '') {
        return $email;
    }

    return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
}

/* =========================================================
 * CSV / PDF
 * ======================================================= */

function output_csv(string $filename, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="'
        . rawurlencode($filename)
        . '"'
    );

    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'wb');

    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

function pdf_escape(string $text): string
{
    $text = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );
}

function output_simple_pdf(string $filename, array $lines): never
{
    /*
     * 実データをPDFとして出力する最小PDF生成。
     * 日本語フォント埋め込みは環境依存のため、
     * ASCII化できる範囲をPDFへ出力する。
     */

    $content = "BT\n/F1 10 Tf\n50 800 Td\n";

    $y = 800;

    foreach ($lines as $line) {
        $line = preg_replace('/[^\x20-\x7E]/', '?', (string)$line);
        $content .= '(' . pdf_escape($line) . ") Tj\n0 -16 Td\n";
        $y -= 16;

        if ($y < 40) {
            break;
        }
    }

    $content .= "ET";

    $objects = [];

    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] =
        '<< /Type /Page /Parent 2 0 R '
        . '/MediaBox [0 0 595 842] '
        . '/Resources << /Font << /F1 4 0 R >> >> '
        . '/Contents 5 0 R >>';
    $objects[] =
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] =
        '<< /Length ' . strlen($content) . " >>\nstream\n"
        . $content
        . "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $object) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n"
            . $object
            . "\nendobj\n";
    }

    $xref = strlen($pdf);

    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n"
        . "<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n"
        . "startxref\n"
        . $xref
        . "\n%%EOF";

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="'
        . rawurlencode($filename)
        . '"'
    );

    echo $pdf;
    exit;
}

/* =========================================================
 * POST actions
 * ======================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = post_string('action');

    /* -----------------------------------------------------
     * Survey save
     * --------------------------------------------------- */

    if ($action === 'save_survey') {
        $id = post_string('id');
        $title = post_string('title');
        $description = post_string('description');
        $startAt = post_string('startAt');
        $endAt = post_string('endAt');
        $numbering = post_string('numbering', 'global');

        $input = compact(
            'title',
            'description',
            'startAt',
            'endAt',
            'numbering'
        );

        $errors = validate_survey_input($input);

        if ($errors) {
            flash('danger', implode(' ', $errors));
            redirect_screen(
                'edit',
                $id !== '' ? ['id' => $id] : []
            );
        }

        $list = surveys();

        if ($id === '') {
            $survey = [
                'id' => new_id('survey'),
                'createdAt' => now_iso(),
                'updatedAt' => now_iso(),
                'title' => $title,
                'description' => $description,
                'startAt' => $startAt,
                'endAt' => $endAt,
                'status' => 'draft',
                'numbering' => $numbering,
                'groups' => [],
            ];

            $survey['groups'][] = [
                'id' => new_id('group'),
                'title' => 'グループ1',
                'questions' => [],
            ];

            $list[] = $survey;
        } else {
            $index = find_survey_index($id);

            if ($index < 0) {
                flash('danger', 'アンケートが見つかりません。');
                redirect_screen('list');
            }

            $survey =& $list[$index];

            if (($survey['status'] ?? '') === 'ended') {
                flash('danger', '終了したアンケートは編集できません。');
                redirect_screen('list');
            }

            $survey['title'] = $title;
            $survey['description'] = $description;
            $survey['startAt'] = $startAt;
            $survey['endAt'] = $endAt;
            $survey['numbering'] = $numbering;
            $survey['updatedAt'] = now_iso();

            renumber_questions($survey);
            unset($survey);
        }

        atomic_write_json(SURVEY_FILE, $list);
        flash('success', 'アンケートを保存しました。');
        redirect_screen('list');
    }

    /* -----------------------------------------------------
     * Survey status
     * --------------------------------------------------- */

    if ($action === 'change_status') {
        $id = post_string('id');
        $newStatus = post_string('new_status');

        if (!in_array($newStatus, ['draft', 'published', 'stopped'], true)) {
            flash('danger', '不正な状態です。');
            redirect_screen('list');
        }

        $list = surveys();
        $index = find_survey_index($id);

        if ($index < 0) {
            flash('danger', 'アンケートが見つかりません。');
            redirect_screen('list');
        }

        if (($list[$index]['status'] ?? '') === 'ended') {
            flash('danger', '終了したアンケートは状態変更できません。');
            redirect_screen('list');
        }

        $list[$index]['status'] = $newStatus;
        $list[$index]['updatedAt'] = now_iso();

        atomic_write_json(SURVEY_FILE, $list);

        flash('success', '状態を変更しました。');
        redirect_screen('list');
    }

    /* -----------------------------------------------------
     * Duplicate
     * --------------------------------------------------- */

    if ($action === 'duplicate_survey') {
        $id = post_string('id');
        $source = find_survey($id);

        if (!$source) {
            flash('danger', '複製元アンケートが見つかりません。');
            redirect_screen('list');
        }

        $copy = $source;
        $copy['id'] = new_id('survey');
        $copy['title'] .= '（複製）';
        $copy['status'] = 'draft';
        $copy['createdAt'] = now_iso();
        $copy['updatedAt'] = now_iso();

        foreach ($copy['groups'] as &$group) {
            $group['id'] = new_id('group');

            foreach ($group['questions'] as &$question) {
                $question['id'] = new_id('question');
            }
            unset($question);
        }
        unset($group);

        renumber_questions($copy);

        $list = surveys();
        $list[] = $copy;

        atomic_write_json(SURVEY_FILE, $list);

        flash('success', 'アンケートを複製しました。');
        redirect_screen('list');
    }

    /* -----------------------------------------------------
     * Delete
     * --------------------------------------------------- */

    if ($action === 'delete_survey') {
        $id = post_string('id');

        $list = surveys();
        $list = array_values(array_filter(
            $list,
            static fn($survey) => ($survey['id'] ?? '') !== $id
        ));

        atomic_write_json(SURVEY_FILE, $list);

        flash('success', 'アンケートを削除しました。');
        redirect_screen('list');
    }

    /* -----------------------------------------------------
     * Save editor JSON
     * --------------------------------------------------- */

    if ($action === 'save_structure') {
        $id = post_string('id');
        $raw = $_POST['structure'] ?? '';

        $structure = is_string($raw)
            ? json_decode($raw, true)
            : null;

        if (!is_array($structure)) {
            flash('danger', '質問データが不正です。');
            redirect_screen('edit', ['id' => $id]);
        }

        $index = find_survey_index($id);
        $list = surveys();

        if ($index < 0) {
            flash('danger', 'アンケートが見つかりません。');
            redirect_screen('list');
        }

        if (($list[$index]['status'] ?? '') === 'ended') {
            flash('danger', '終了したアンケートは変更できません。');
            redirect_screen('list');
        }

        $list[$index]['groups'] = is_array($structure['groups'] ?? null)
            ? $structure['groups']
            : [];

        renumber_questions($list[$index]);

        $list[$index]['updatedAt'] = now_iso();

        atomic_write_json(SURVEY_FILE, $list);

        flash('success', '質問・グループを保存しました。');
        redirect_screen('edit', ['id' => $id]);
    }

    /* -----------------------------------------------------
     * kintone settings save
     * --------------------------------------------------- */

    if ($action === 'save_kintone') {
        $current = settings();
        $old = $current['kintone'] ?? [];

        $subdomain = post_string('subdomain');
        $appId = post_int('app_id');
        $username = post_string('username');
        $password = post_string('password');
        $proxy = post_string('proxy');
        $verifySsl = isset($_POST['verify_ssl']);

        $domain = normalize_kintone_domain($subdomain);

        $errors = [];

        if (!$domain) {
            $errors[] = 'サブドメインが不正です。';
        }

        if ($appId <= 0) {
            $errors[] = '顧客管理アプリIDが不正です。';
        }

        if ($username === '') {
            $errors[] = 'ログイン名は必須です。';
        }

        if ($password === '' && empty($old['password'])) {
            $errors[] = 'パスワードは必須です。';
        }

        if ($proxy !== '' && !preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
            $errors[] = 'Proxyはhost:port形式で入力してください。';
        }

        if ($errors) {
            flash('danger', implode(' ', $errors));
            redirect_screen('kintone');
        }

        if ($password === '') {
            $password = (string)($old['password'] ?? '');
        }

        $current['kintone'] = [
            'subdomain' => $subdomain,
            'app_id' => $appId,
            'username' => $username,
            'password' => $password,
            'proxy' => $proxy,
            'verify_ssl' => $verifySsl,
            'mappings' => $old['mappings'] ?? [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
        ];

        atomic_write_json(SETTINGS_FILE, $current);

        flash('success', 'kintone設定を保存しました。');
        redirect_screen('kintone');
    }

    /* -----------------------------------------------------
     * kintone connection test
     * --------------------------------------------------- */

    if ($action === 'test_kintone') {
        $cfg = settings()['kintone'] ?? [];

        $result = kintone_connection_test($cfg);

        if ($result['ok']) {
            flash('success', $result['message']);
        } else {
            flash('danger', $result['message']);
        }

        redirect_screen('kintone');
    }

    /* -----------------------------------------------------
     * kintone fields
     * --------------------------------------------------- */

    if ($action === 'get_kintone_fields') {
        $cfg = settings()['kintone'] ?? [];

        $result = kintone_get_fields($cfg);

        if (!$result['ok']) {
            flash('danger', $result['message']);
        } else {
            $_SESSION['kintone_fields'] = $result['fields'];
            flash('success', $result['message']);
        }

        redirect_screen('kintone');
    }

    /* -----------------------------------------------------
     * kintone mapping save
     * --------------------------------------------------- */

    if ($action === 'save_kintone_mapping') {
        $cfg = settings()['kintone'] ?? [];
        $cfg['mappings'] = [
            'organization' => post_string('map_organization'),
            'name' => post_string('map_name'),
            'email' => post_string('map_email'),
            'department' => post_string('map_department'),
            'phone' => post_string('map_phone'),
            'address' => array_values(
                array_filter(
                    (array)($_POST['map_address'] ?? []),
                    static fn($v) => is_string($v) && $v !== ''
                )
            ),
        ];

        $all = settings();
        $all['kintone'] = $cfg;

        atomic_write_json(SETTINGS_FILE, $all);

        flash('success', 'kintone項目マッピングを保存しました。');
        redirect_screen('kintone');
    }

    /* -----------------------------------------------------
     * kintone sync
     * --------------------------------------------------- */

    if ($action === 'sync_kintone') {
        $cfg = settings()['kintone'] ?? [];

        $result = kintone_sync_customers($cfg);

        if ($result['ok']) {
            flash('success', $result['message']);
        } else {
            flash('danger', $result['message']);
        }

        redirect_screen('kintone');
    }

    /* -----------------------------------------------------
     * Mail settings
     * --------------------------------------------------- */

    if ($action === 'save_mail') {
        $all = settings();
        $old = $all['mail'] ?? [];

        $smtpServer = post_string('smtp_server');
        $smtpPort = post_int('smtp_port', 587);
        $encryption = post_string('encryption', 'tls');
        $auth = isset($_POST['auth']);
        $username = post_string('smtp_username');
        $password = post_string('smtp_password');
        $fromEmail = post_string('from_email');
        $fromName = post_string('from_name');
        $replyTo = post_string('reply_to');

        $errors = [];

        if ($smtpServer === '') {
            $errors[] = 'SMTPサーバは必須です。';
        }

        if ($smtpPort < 1 || $smtpPort > 65535) {
            $errors[] = 'SMTPポートが不正です。';
        }

        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            $errors[] = '暗号化方式が不正です。';
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '送信元メールアドレスが不正です。';
        }

        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '返信先メールアドレスが不正です。';
        }

        if ($auth && $username === '') {
            $errors[] = 'SMTPユーザー名は必須です。';
        }

        if ($errors) {
            flash('danger', implode(' ', $errors));
            redirect_screen('mail');
        }

        if ($password === '') {
            $password = (string)($old['password'] ?? '');
        }

        $all['mail'] = [
            'smtp_server' => $smtpServer,
            'smtp_port' => $smtpPort,
            'encryption' => $encryption,
            'auth' => $auth,
            'username' => $username,
            'password' => $password,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to' => $replyTo,
            'status' => $old['status'] ?? 'unset',
        ];

        atomic_write_json(SETTINGS_FILE, $all);

        flash('success', 'メールサーバ設定を保存しました。');
        redirect_screen('mail');
    }

    /* -----------------------------------------------------
     * Mail connection
     * --------------------------------------------------- */

    if ($action === 'test_mail') {
        $all = settings();
        $cfg = $all['mail'] ?? [];

        $result = smtp_connect_test($cfg);

        if ($result['ok']) {
            $all['mail']['status'] = 'connected';
            atomic_write_json(SETTINGS_FILE, $all);
            flash('success', $result['message']);
        } else {
            $all['mail']['status'] = 'failed';
            atomic_write_json(SETTINGS_FILE, $all);
            flash('danger', $result['message']);
        }

        redirect_screen('mail');
    }

    /* -----------------------------------------------------
     * Test mail
     * --------------------------------------------------- */

    if ($action === 'send_test_mail') {
        $all = settings();
        $cfg = $all['mail'] ?? [];

        $to = post_string('test_email');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'テスト送信先メールアドレスが不正です。');
            redirect_screen('mail');
        }

        $result = smtp_send(
            $cfg,
            $to,
            'アンケートアプリ SMTPテスト',
            "これはアンケートアプリからのSMTP接続テストメールです。\n"
            . "送信日時: " . date('Y-m-d H:i:s')
        );

        if ($result['ok']) {
            flash('success', $result['message']);
        } else {
            flash('danger', $result['message']);
        }

        redirect_screen('mail');
    }

    /* -----------------------------------------------------
     * Answer save to session
     * --------------------------------------------------- */

    if ($action === 'answer_next') {
        $id = post_string('id');
        $survey = find_survey($id);

        if (!$survey) {
            redirect_screen('list');
        }

        normalize_survey_status($survey);

        if (($survey['status'] ?? '') !== 'published') {
            http_response_code(404);
            exit('このアンケートは回答できません。');
        }

        $answersPost = $_POST['answer'] ?? [];

        if (!is_array($answersPost)) {
            $answersPost = [];
        }

        $_SESSION['answer_draft'][$id] = $answersPost;

        redirect_screen('confirm', ['id' => $id]);
    }

    /* -----------------------------------------------------
     * Answer submit
     * --------------------------------------------------- */

    if ($action === 'submit_answer') {
        $id = post_string('id');
        $survey = find_survey($id);

        if (!$survey) {
            http_response_code(404);
            exit('アンケートが見つかりません。');
        }

        $draft = $_SESSION['answer_draft'][$id] ?? [];

        if (!is_array($draft)) {
            $draft = [];
        }

        $errors = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                if (!($question['required'] ?? false)) {
                    continue;
                }

                $qid = $question['id'];
                $value = $draft[$qid] ?? null;

                $empty = $value === null
                    || $value === ''
                    || (is_array($value) && count($value) === 0);

                if ($empty) {
                    $errors[] =
                        ($question['number'] ?? '')
                        . ' は必須項目です。';
                }
            }
        }

        if ($errors) {
            flash('danger', implode(' ', $errors));
            redirect_screen('answer', ['id' => $id]);
        }

        $list = answers();

        $list[] = [
            'id' => new_id('answer'),
            'surveyId' => $id,
            'answeredAt' => now_iso(),
            'customerId' => '',
            'answers' => $draft,
        ];

        atomic_write_json(ANSWER_FILE, $list);

        unset($_SESSION['answer_draft'][$id]);

        redirect_screen('complete', ['id' => $id]);
    }

    /* -----------------------------------------------------
     * Send mail
     * --------------------------------------------------- */

    if ($action === 'send_bulk') {
        $surveyId = post_string('survey_id');
        $survey = find_survey($surveyId);

        if (!$survey) {
            flash('danger', '対象アンケートが見つかりません。');
            redirect_screen('list');
        }

        $selected = $_POST['customer'] ?? [];

        if (!is_array($selected) || count($selected) === 0) {
            flash('danger', '送信対象の顧客を選択してください。');
            redirect_screen('send', ['id' => $surveyId]);
        }

        $subject = post_string('subject');
        $body = post_string('body');

        if ($subject === '' || $body === '') {
            flash('danger', '件名と本文を入力してください。');
            redirect_screen('send', ['id' => $surveyId]);
        }

        $cfg = settings()['mail'] ?? [];
        $customerList = customers();
        $history = histories();

        $selectedSet = array_fill_keys(
            array_map('strval', $selected),
            true
        );

        $success = 0;
        $failure = 0;

        foreach ($customerList as $customer) {
            $customerId = (string)($customer['id'] ?? '');

            if (!isset($selectedSet[$customerId])) {
                continue;
            }

            $email = (string)($customer['email'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failure++;

                $history[] = [
                    'id' => new_id('mail'),
                    'surveyId' => $surveyId,
                    'customerId' => $customerId,
                    'type' => 'send',
                    'status' => 'failed',
                    'message' => 'メールアドレスが不正です。',
                    'createdAt' => now_iso(),
                ];

                continue;
            }

            $url = build_answer_url($surveyId);

            $replace = [
                '{顧客名}' => (string)($customer['name'] ?? ''),
                '{アンケートURL}' => $url,
            ];

            $mailBody = strtr($body, $replace);
            $mailSubject = strtr($subject, $replace);

            $result = smtp_send(
                $cfg,
                $email,
                $mailSubject,
                $mailBody
            );

            if ($result['ok']) {
                $success++;
            } else {
                $failure++;
            }

            $history[] = [
                'id' => new_id('mail'),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'type' => 'send',
                'status' => $result['ok'] ? 'success' : 'failed',
                'message' => $result['message'],
                'createdAt' => now_iso(),
            ];
        }

        atomic_write_json(HISTORY_FILE, $history);

        flash(
            $failure > 0 ? 'danger' : 'success',
            "送信完了：成功 {$success} 件 / 失敗 {$failure} 件"
        );

        redirect_screen('send', ['id' => $surveyId]);
    }

    /* -----------------------------------------------------
     * Retry / reminder
     * --------------------------------------------------- */

    if ($action === 'resend_mail' || $action === 'remind_mail') {
        $historyId = post_string('history_id');
        $historyList = histories();
        $target = null;

        foreach ($historyList as $entry) {
            if (($entry['id'] ?? '') === $historyId) {
                $target = $entry;
                break;
            }
        }

        if (!$target) {
            flash('danger', '送信履歴が見つかりません。');
            redirect_screen('list');
        }

        $surveyId = (string)$target['surveyId'];
        $survey = find_survey($surveyId);

        if (!$survey) {
            flash('danger', '対象アンケートが見つかりません。');
            redirect_screen('list');
        }

        $customer = null;

        foreach (customers() as $c) {
            if (($c['id'] ?? '') === ($target['customerId'] ?? '')) {
                $customer = $c;
                break;
            }
        }

        if (!$customer || !filter_var($customer['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            flash('danger', '送信対象の顧客情報が見つかりません。');
            redirect_screen('send', ['id' => $surveyId]);
        }

        $cfg = settings()['mail'] ?? [];

        $type = $action === 'resend_mail'
            ? 'resend'
            : 'reminder';

        $subject = $type === 'reminder'
            ? '【リマインド】' . $survey['title']
            : $survey['title'];

        $body = ($customer['name'] ?? '') . " 様\n\n"
            . ($type === 'reminder'
                ? "アンケートへのご回答をお願いいたします。\n\n"
                : "アンケートを再送いたします。\n\n")
            . build_answer_url($surveyId);

        $result = smtp_send(
            $cfg,
            (string)$customer['email'],
            $subject,
            $body
        );

        $historyList[] = [
            'id' => new_id('mail'),
            'surveyId' => $surveyId,
            'customerId' => $customer['id'],
            'type' => $type,
            'status' => $result['ok'] ? 'success' : 'failed',
            'message' => $result['message'],
            'createdAt' => now_iso(),
        ];

        atomic_write_json(HISTORY_FILE, $historyList);

        flash(
            $result['ok'] ? 'success' : 'danger',
            $result['message']
        );

        redirect_screen('send', ['id' => $surveyId]);
    }
}

/* =========================================================
 * GET output actions
 * ======================================================= */

$screen = (string)($_GET['screen'] ?? 'list');

if ($screen === 'csv') {
    $id = (string)($_GET['id'] ?? '');
    $survey = find_survey($id);

    if (!$survey) {
        redirect_screen('list');
    }

    $answerList = array_values(array_filter(
        answers(),
        static fn($a) => ($a['surveyId'] ?? '') === $id
    ));

    $rows = [];

    $header = [
        '回答ID',
        '回答日時',
    ];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $header[] =
                ($question['number'] ?? '')
                . ' '
                . ($question['text'] ?? '');
        }
    }

    $rows[] = $header;

    foreach ($answerList as $answer) {
        $row = [
            $answer['id'] ?? '',
            $answer['answeredAt'] ?? '',
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $value = $answer['answers'][$question['id']] ?? '';

                if (is_array($value)) {
                    $value = implode(', ', $value);
                }

                $row[] = $value;
            }
        }

        $rows[] = $row;
    }

    output_csv(
        'survey-' . $id . '-answers.csv',
        $rows
    );
}

if ($screen === 'pdf') {
    $id = (string)($_GET['id'] ?? '');
    $survey = find_survey($id);

    if (!$survey) {
        redirect_screen('list');
    }

    $answerList = array_values(array_filter(
        answers(),
        static fn($a) => ($a['surveyId'] ?? '') === $id
    ));

    $lines = [
        'Survey: ' . ($survey['title'] ?? ''),
        'Answers: ' . count($answerList),
        '',
    ];

    foreach ($survey['groups'] as $group) {
        $lines[] = 'Group: ' . ($group['title'] ?? '');

        foreach ($group['questions'] as $question) {
            $lines[] =
                ($question['number'] ?? '')
                . ' '
                . ($question['text'] ?? '');
        }
    }

    output_simple_pdf(
        'survey-' . $id . '-report.pdf',
        $lines
    );
}

/* =========================================================
 * Normalize status before display
 * ======================================================= */

normalize_all_statuses();

/* =========================================================
 * Page helpers
 * ======================================================= */

function build_answer_url(string $surveyId): string
{
    $scheme = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http'
    );

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

    return $scheme
        . '://'
        . $host
        . $script
        . '?screen=answer&id='
        . rawurlencode($surveyId);
}

function render_header(
    string $title,
    string $active = 'list'
): void {
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport"
              content="width=device-width,initial-scale=1">
        <title><?= h($title) ?> - <?= h(APP_TITLE) ?></title>
        <style>
        :root{
            --primary:#2563eb;
            --primary-dark:#1d4ed8;
            --success:#16a34a;
            --warning:#d97706;
            --danger:#dc2626;
            --gray:#64748b;
            --gray-light:#f1f5f9;
            --border:#dbe2ea;
            --text:#1e293b;
            --white:#fff;
            --shadow:0 4px 18px rgba(15,23,42,.08);
        }

        *{box-sizing:border-box}

        html,body{
            margin:0;
            padding:0;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Noto Sans JP",
                "Hiragino Kaku Gothic ProN",
                Meiryo,
                sans-serif;
            color:var(--text);
            background:#f8fafc;
        }

        button,input,textarea,select{
            font:inherit;
        }

        button{
            cursor:pointer;
        }

        .hidden{
            display:none!important;
        }

        .admin-header{
            position:sticky;
            top:0;
            z-index:50;
            min-height:64px;
            background:#0f172a;
            color:#fff;
            display:flex;
            align-items:center;
            padding:0 24px;
            gap:28px;
            box-shadow:0 2px 10px rgba(0,0,0,.12);
        }

        .admin-logo{
            font-weight:700;
            white-space:nowrap;
            font-size:18px;
        }

        .admin-nav{
            display:flex;
            gap:4px;
            height:100%;
            align-items:center;
            overflow-x:auto;
        }

        .admin-nav a{
            height:40px;
            padding:0 14px;
            display:flex;
            align-items:center;
            border-radius:7px;
            color:#cbd5e1;
            text-decoration:none;
            white-space:nowrap;
        }

        .admin-nav a:hover,
        .admin-nav a.active{
            background:#1e293b;
            color:#fff;
        }

        .admin-spacer{
            flex:1;
        }

        .page{
            max-width:1500px;
            margin:0 auto;
            padding:28px;
        }

        .page-title{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            margin-bottom:24px;
        }

        .page-title h1{
            margin:0;
            font-size:26px;
        }

        .page-title p{
            margin:5px 0 0;
            color:var(--gray);
            font-size:13px;
        }

        .card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            box-shadow:var(--shadow);
        }

        .card-header{
            padding:18px 20px;
            border-bottom:1px solid var(--border);
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
        }

        .card-body{
            padding:20px;
        }

        .btn{
            border:1px solid var(--border);
            background:#fff;
            color:var(--text);
            border-radius:7px;
            padding:9px 14px;
            min-height:40px;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:5px;
        }

        .btn:hover{
            background:#f8fafc;
        }

        .btn-primary{
            background:var(--primary);
            color:#fff;
            border-color:var(--primary);
        }

        .btn-primary:hover{
            background:var(--primary-dark);
        }

        .btn-success{
            background:var(--success);
            color:#fff;
            border-color:var(--success);
        }

        .btn-danger{
            color:#fff;
            background:var(--danger);
            border-color:var(--danger);
        }

        .btn-warning{
            color:#fff;
            background:var(--warning);
            border-color:var(--warning);
        }

        .btn-sm{
            min-height:32px;
            padding:5px 9px;
            font-size:12px;
        }

        .badge{
            display:inline-flex;
            align-items:center;
            padding:4px 9px;
            border-radius:999px;
            font-size:12px;
            font-weight:600;
            white-space:nowrap;
        }

        .badge-draft{
            background:#e2e8f0;
            color:#475569;
        }

        .badge-published{
            background:#dcfce7;
            color:#166534;
        }

        .badge-stopped{
            background:#fef3c7;
            color:#92400e;
        }

        .badge-ended{
            background:#fee2e2;
            color:#991b1b;
        }

        .badge-success{
            background:#dcfce7;
            color:#166534;
        }

        .badge-danger{
            background:#fee2e2;
            color:#991b1b;
        }

        .form-grid{
            display:grid;
            grid-template-columns:
                repeat(2,minmax(0,1fr));
            gap:18px;
        }

        .form-group{
            display:flex;
            flex-direction:column;
            gap:7px;
        }

        .form-group.full{
            grid-column:1/-1;
        }

        label{
            font-weight:600;
            font-size:13px;
        }

        input[type=text],
        input[type=email],
        input[type=password],
        input[type=datetime-local],
        input[type=number],
        textarea,
        select{
            width:100%;
            border:1px solid #cbd5e1;
            border-radius:7px;
            padding:10px 12px;
            background:#fff;
            color:var(--text);
        }

        textarea{
            resize:vertical;
            min-height:110px;
        }

        .help{
            color:var(--gray);
            font-size:12px;
        }

        .actions{
            display:flex;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
        }

        .alert{
            border-radius:8px;
            padding:12px 14px;
            margin-bottom:16px;
            font-size:13px;
        }

        .alert-success{
            color:#166534;
            background:#dcfce7;
            border:1px solid #bbf7d0;
        }

        .alert-danger{
            color:#991b1b;
            background:#fee2e2;
            border:1px solid #fecaca;
        }

        .alert-info{
            color:#1e40af;
            background:#dbeafe;
            border:1px solid #bfdbfe;
        }

        .toolbar{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            margin-bottom:16px;
        }

        .search-box{
            display:flex;
            flex:1;
            min-width:260px;
        }

        .search-box input{
            border-radius:7px 0 0 7px;
        }

        .search-box button{
            border:1px solid #cbd5e1;
            border-left:0;
            background:#f8fafc;
            border-radius:0 7px 7px 0;
            padding:0 16px;
        }

        .table-wrap{
            overflow-x:auto;
        }

        table{
            width:100%;
            border-collapse:collapse;
            min-width:1100px;
        }

        th,td{
            padding:13px 12px;
            border-bottom:1px solid #e2e8f0;
            text-align:left;
            vertical-align:middle;
            font-size:13px;
        }

        th{
            background:#f8fafc;
            font-weight:700;
            color:#475569;
        }

        tbody tr:hover{
            background:#f8fafc;
        }

        .action-grid{
            display:flex;
            flex-wrap:wrap;
            gap:5px;
        }

        .editor-topbar{
            background:#fff;
            border:1px solid var(--border);
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:20px;
            display:flex;
            align-items:center;
            gap:12px;
            box-shadow:var(--shadow);
        }

        .editor-topbar .state-area{
            margin-left:auto;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .section{
            margin-bottom:20px;
        }

        .section-title{
            margin:0 0 15px;
            font-size:18px;
        }

        .group{
            margin-bottom:18px;
            border:1px solid #cbd5e1;
            border-radius:12px;
            background:#f8fafc;
        }

        .group-header{
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px;
            background:#f1f5f9;
            border-radius:12px 12px 0 0;
        }

        .group-title{
            flex:1;
            font-weight:700;
        }

        .question-list{
            padding:12px;
            display:flex;
            flex-direction:column;
            gap:10px;
        }

        .question{
            background:#fff;
            border:1px solid var(--border);
            border-radius:9px;
            padding:13px;
        }

        .question-header{
            display:flex;
            align-items:center;
            gap:8px;
            margin-bottom:10px;
        }

        .question-number{
            font-weight:700;
            color:var(--primary);
            min-width:60px;
        }

        .question-text{
            flex:1;
        }

        .question-body{
            display:grid;
            grid-template-columns:
                1fr 180px 110px;
            gap:10px;
            align-items:start;
        }

        .question-options{
            margin-top:10px;
        }

        .option-row{
            display:flex;
            gap:7px;
            margin-bottom:7px;
        }

        .option-row input{
            flex:1;
        }

        .branch-box{
            margin-top:10px;
            padding:10px;
            background:#eff6ff;
            border:1px solid #bfdbfe;
            border-radius:7px;
        }

        .add-area{
            padding:0 12px 12px;
        }

        .preview-device{
            max-width:900px;
            margin:0 auto;
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            box-shadow:var(--shadow);
            padding:30px;
        }

        .preview-device.mobile{
            max-width:390px;
        }

        .preview-question{
            margin:25px 0;
        }

        .preview-option{
            display:block;
            padding:12px;
            border:1px solid #cbd5e1;
            border-radius:8px;
            margin-bottom:8px;
        }

        .target-banner{
            background:#eff6ff;
            border:1px solid #bfdbfe;
            border-radius:10px;
            padding:15px 18px;
            margin-bottom:20px;
        }

        .target-banner .label{
            color:#1d4ed8;
            font-size:12px;
            font-weight:700;
        }

        .target-banner .title{
            font-size:18px;
            font-weight:700;
            margin-top:4px;
        }

        .send-tabs{
            display:flex;
            border-bottom:1px solid var(--border);
            margin-bottom:18px;
        }

        .send-tab{
            border:0;
            background:none;
            padding:12px 18px;
            text-decoration:none;
            color:#64748b;
        }

        .send-tab.active{
            color:var(--primary);
            border-bottom:3px solid var(--primary);
            font-weight:700;
        }

        .template-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
        }

        .mail-preview{
            white-space:pre-wrap;
            background:#f8fafc;
            border:1px solid var(--border);
            border-radius:8px;
            padding:15px;
            min-height:170px;
        }

        .summary-grid{
            display:grid;
            grid-template-columns:
                repeat(5,1fr);
            gap:12px;
            margin-bottom:20px;
        }

        .summary-card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:10px;
            padding:16px;
        }

        .summary-card .number{
            font-size:27px;
            font-weight:700;
            margin-top:5px;
        }

        .bar{
            height:22px;
            border-radius:5px;
            background:#e2e8f0;
            overflow:hidden;
        }

        .bar span{
            display:block;
            height:100%;
            background:var(--primary);
        }

        .settings-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:20px;
        }

        .mapping{
            display:grid;
            grid-template-columns:180px 1fr;
            gap:10px;
            align-items:center;
            margin-bottom:10px;
        }

        .address-checks{
            display:grid;
            grid-template-columns:repeat(2,1fr);
            gap:8px;
        }

        .respondent{
            min-height:100vh;
            background:#f8fafc;
        }

        .respondent-header{
            background:#fff;
            border-bottom:1px solid var(--border);
            padding:20px;
        }

        .respondent-header-inner{
            max-width:760px;
            margin:auto;
        }

        .respondent-main{
            max-width:760px;
            margin:25px auto;
            padding:0 16px 50px;
        }

        .respondent-card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            padding:25px;
            box-shadow:var(--shadow);
        }

        .respondent-question{
            margin:0 0 28px;
        }

        .respondent-option{
            display:block;
            padding:13px;
            border:1px solid #cbd5e1;
            border-radius:8px;
            margin:8px 0;
        }

        .required{
            color:var(--danger);
            font-size:12px;
            margin-left:5px;
        }

        .toast{
            position:fixed;
            right:20px;
            bottom:20px;
            z-index:100;
        }

        @media(max-width:1000px){
            .summary-grid{
                grid-template-columns:repeat(2,1fr);
            }

            .settings-grid,
            .template-grid,
            .form-grid{
                grid-template-columns:1fr;
            }

            .question-body{
                grid-template-columns:1fr;
            }
        }

        @media(max-width:700px){
            .admin-header{
                padding:10px 14px;
                flex-wrap:wrap;
            }

            .admin-nav{
                width:100%;
                order:3;
            }

            .page{
                padding:16px;
            }

            .editor-topbar{
                flex-wrap:wrap;
            }

            .editor-topbar .state-area{
                margin-left:0;
                width:100%;
            }

            .summary-grid{
                grid-template-columns:1fr 1fr;
            }

            .preview-device{
                padding:18px;
            }

            .respondent-card{
                padding:18px;
            }

            .mapping{
                grid-template-columns:1fr;
            }
        }
        </style>
    </head>
    <body>
    <header class="admin-header">
        <div class="admin-logo">
            <?= h(APP_TITLE) ?>
        </div>

        <nav class="admin-nav">
            <a href="index.php?screen=list"
               class="<?= $active === 'list' ? 'active' : '' ?>">
                アンケート一覧
            </a>

            <a href="index.php?screen=kintone"
               class="<?= $active === 'kintone' ? 'active' : '' ?>">
                kintone連携設定
            </a>

            <a href="index.php?screen=mail"
               class="<?= $active === 'mail' ? 'active' : '' ?>">
                メールサーバ設定
            </a>
        </nav>

        <div class="admin-spacer"></div>
    </header>

    <main class="page">
    <?php
}

function render_footer(): void
{
    ?>
    </main>

    <script>
    "use strict";

    function confirmSubmit(form, message) {
        if (!window.confirm(message)) {
            return false;
        }

        const button = form.querySelector(
            'button[type="submit"]'
        );

        if (button) {
            button.disabled = true;
            button.textContent = '処理中...';
        }

        return true;
    }

    document.querySelectorAll('form[data-confirm]').forEach(
        function(form) {
            form.addEventListener('submit', function(e) {
                if (!confirmSubmit(
                    form,
                    form.dataset.confirm
                )) {
                    e.preventDefault();
                }
            });
        }
    );

    document.querySelectorAll('[data-search-enter]').forEach(
        function(input) {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    input.form.submit();
                }
            });
        }
    );

    document.querySelectorAll('[data-preview-body]').forEach(
        function(input) {
            const target = document.getElementById(
                input.dataset.previewBody
            );

            if (!target) return;

            input.addEventListener('input', function() {
                target.textContent = input.value
                    .replaceAll(
                        '{顧客名}',
                        '山田 太郎'
                    )
                    .replaceAll(
                        '{アンケートURL}',
                        location.origin + '/index.php?screen=answer&id=preview'
                    );
            });
        }
    );
    </script>
    </body>
    </html>
    <?php
}

function render_flashes(): void
{
    foreach (flashes() as $flash) {
        $class = ($flash['type'] ?? '') === 'success'
            ? 'alert-success'
            : 'alert-danger';

        echo '<div class="alert ' . h($class) . '">'
            . h($flash['message'] ?? '')
            . '</div>';
    }
}

/* =========================================================
 * Respondent screens
 * ======================================================= */

function render_answer(string $id): never
{
    $survey = find_survey($id);

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    normalize_survey_status($survey);

    if (($survey['status'] ?? '') !== 'published') {
        http_response_code(404);
        exit('このアンケートは現在回答できません。');
    }

    $draft = $_SESSION['answer_draft'][$id] ?? [];

    if (!is_array($draft)) {
        $draft = [];
    }

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport"
              content="width=device-width,initial-scale=1">
        <title><?= h($survey['title']) ?></title>
        <style>
        :root{
            --primary:#2563eb;
            --danger:#dc2626;
            --border:#dbe2ea;
            --text:#1e293b;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            background:#f8fafc;
            color:var(--text);
            font-family:
                -apple-system,BlinkMacSystemFont,
                "Segoe UI","Noto Sans JP",
                "Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
        }
        .header{
            background:#fff;
            border-bottom:1px solid var(--border);
            padding:20px;
        }
        .header-inner{
            max-width:760px;
            margin:auto;
        }
        .main{
            max-width:760px;
            margin:25px auto;
            padding:0 16px 50px;
        }
        .card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:12px;
            padding:25px;
            box-shadow:0 4px 18px rgba(15,23,42,.08);
        }
        .question{
            margin-bottom:28px;
        }
        .option{
            display:block;
            padding:14px;
            border:1px solid #cbd5e1;
            border-radius:8px;
            margin:8px 0;
        }
        input[type=text],textarea{
            width:100%;
            padding:12px;
            border:1px solid #cbd5e1;
            border-radius:8px;
        }
        textarea{
            min-height:130px;
        }
        .required{
            color:var(--danger);
            font-size:12px;
        }
        .actions{
            display:flex;
            justify-content:flex-end;
            margin-top:25px;
        }
        button{
            background:var(--primary);
            color:white;
            border:0;
            border-radius:8px;
            padding:13px 24px;
            font-size:16px;
        }
        </style>
    </head>
    <body>
        <header class="header">
            <div class="header-inner">
                <h1><?= h($survey['title']) ?></h1>
                <?php if (($survey['description'] ?? '') !== ''): ?>
                    <p><?= nl2br(h($survey['description'])) ?></p>
                <?php endif; ?>
            </div>
        </header>

        <main class="main">
            <form method="post"
                  action="index.php?screen=answer&id=<?= h($id) ?>">
                <?= csrf_field() ?>

                <input type="hidden"
                       name="action"
                       value="answer_next">

                <input type="hidden"
                       name="id"
                       value="<?= h($id) ?>">

                <div class="card">
                <?php foreach ($survey['groups'] as $group): ?>
                    <section>
                        <h2><?= h($group['title']) ?></h2>

                        <?php foreach ($group['questions'] as $question): ?>
                            <div class="question">
                                <h3>
                                    <?= h($question['number']) ?>
                                    <?= h($question['text']) ?>

                                    <?php if (!empty($question['required'])): ?>
                                        <span class="required">必須</span>
                                    <?php endif; ?>
                                </h3>

                                <?php
                                $qid = (string)$question['id'];
                                $value = $draft[$qid] ?? '';
                                $type = $question['type'] ?? 'text';
                                ?>

                                <?php if ($type === 'single'): ?>

                                    <?php foreach (($question['options'] ?? []) as $option): ?>
                                        <label class="option">
                                            <input
                                                type="radio"
                                                name="answer[<?= h($qid) ?>]"
                                                value="<?= h($option) ?>"
                                                <?= $value === $option ? 'checked' : '' ?>
                                            >
                                            <?= h($option) ?>
                                        </label>
                                    <?php endforeach; ?>

                                <?php elseif ($type === 'multiple'): ?>

                                    <?php
                                    $values = is_array($value)
                                        ? $value
                                        : [];
                                    ?>

                                    <?php foreach (($question['options'] ?? []) as $option): ?>
                                        <label class="option">
                                            <input
                                                type="checkbox"
                                                name="answer[<?= h($qid) ?>][]"
                                                value="<?= h($option) ?>"
                                                <?= in_array(
                                                    $option,
                                                    $values,
                                                    true
                                                ) ? 'checked' : '' ?>
                                            >
                                            <?= h($option) ?>
                                        </label>
                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <textarea
                                        name="answer[<?= h($qid) ?>]"
                                        <?= !empty($question['required'])
                                            ? 'required'
                                            : '' ?>
                                    ><?= h(is_string($value) ? $value : '') ?></textarea>

                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>

                    <div class="actions">
                        <button type="submit">
                            回答を確認する
                        </button>
                    </div>
                </div>
            </form>
        </main>
    </body>
    </html>
    <?php

    exit;
}

function render_confirm(string $id): never
{
    $survey = find_survey($id);

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    $draft = $_SESSION['answer_draft'][$id] ?? [];

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport"
              content="width=device-width,initial-scale=1">
        <title>回答確認 - <?= h($survey['title']) ?></title>
        <style>
        *{box-sizing:border-box}
        body{
            margin:0;
            background:#f8fafc;
            color:#1e293b;
            font-family:
                -apple-system,BlinkMacSystemFont,
                "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
        }
        .main{
            max-width:760px;
            margin:30px auto;
            padding:16px;
        }
        .card{
            background:#fff;
            border:1px solid #dbe2ea;
            border-radius:12px;
            padding:25px;
        }
        .item{
            padding:16px 0;
            border-bottom:1px solid #e2e8f0;
        }
        .label{
            font-weight:700;
        }
        .value{
            white-space:pre-wrap;
            margin-top:7px;
        }
        .actions{
            display:flex;
            justify-content:space-between;
            gap:10px;
            margin-top:25px;
        }
        button,a{
            padding:12px 20px;
            border-radius:8px;
            text-decoration:none;
            border:1px solid #cbd5e1;
            background:white;
            color:#1e293b;
        }
        button{
            background:#2563eb;
            color:white;
            border-color:#2563eb;
        }
        </style>
    </head>
    <body>
    <main class="main">
        <div class="card">
            <h1>回答確認</h1>
            <p><?= h($survey['title']) ?></p>

            <?php foreach ($survey['groups'] as $group): ?>
                <?php foreach ($group['questions'] as $question): ?>
                    <?php
                    $value = $draft[$question['id']] ?? '';

                    if (is_array($value)) {
                        $value = implode('、', $value);
                    }
                    ?>
                    <div class="item">
                        <div class="label">
                            <?= h($question['number']) ?>
                            <?= h($question['text']) ?>
                        </div>
                        <div class="value">
                            <?= h((string)$value) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <div class="actions">
                <a href="index.php?screen=answer&id=<?= h($id) ?>">
                    修正する
                </a>

                <form method="post"
                      action="index.php?screen=confirm&id=<?= h($id) ?>"
                      data-confirm="回答を送信します。よろしいですか？">
                    <?= csrf_field() ?>

                    <input type="hidden"
                           name="action"
                           value="submit_answer">

                    <input type="hidden"
                           name="id"
                           value="<?= h($id) ?>">

                    <button type="submit">
                        回答を送信する
                    </button>
                </form>
            </div>
        </div>
    </main>
    </body>
    </html>
    <?php

    exit;
}

function render_complete(string $id): never
{
    $survey = find_survey($id);

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport"
              content="width=device-width,initial-scale=1">
        <title>回答完了</title>
        <style>
        body{
            margin:0;
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#f8fafc;
            color:#1e293b;
            font-family:
                -apple-system,BlinkMacSystemFont,
                "Segoe UI","Noto Sans JP",Meiryo,sans-serif;
        }
        .card{
            width:min(680px,calc(100% - 32px));
            background:#fff;
            border:1px solid #dbe2ea;
            border-radius:12px;
            padding:40px 25px;
            text-align:center;
            box-shadow:0 4px 18px rgba(15,23,42,.08);
        }
        .icon{
            color:#16a34a;
            font-size:50px;
        }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">✓</div>
            <h1>回答ありがとうございました</h1>
            <p>
                アンケートの回答を受け付けました。
            </p>
        </div>
    </body>
    </html>
    <?php

    exit;
}

/* =========================================================
 * Admin: list
 * ======================================================= */

function render_list(): void
{
    render_header('アンケート一覧', 'list');

    render_flashes();

    $list = surveys();

    $q = trim((string)($_GET['q'] ?? ''));
    $filter = (string)($_GET['filter'] ?? 'all');
    $sort = (string)($_GET['sort'] ?? 'updated_desc');

    foreach ($list as &$survey) {
        normalize_survey_status($survey);
    }
    unset($survey);

    if ($q !== '') {
        $list = array_values(array_filter(
            $list,
            static fn($s) =>
                mb_stripos(
                    (string)($s['title'] ?? ''),
                    $q
                ) !== false
        ));
    }

    if ($filter !== 'all') {
        $list = array_values(array_filter(
            $list,
            static fn($s) => ($s['status'] ?? '') === $filter
        ));
    }

    $answerList = answers();

    $answerCounts = [];

    foreach ($answerList as $answer) {
        $sid = (string)($answer['surveyId'] ?? '');
        $answerCounts[$sid] = ($answerCounts[$sid] ?? 0) + 1;
    }

    usort(
        $list,
        static function($a, $b) use ($sort, $answerCounts): int {
            if ($sort === 'answers_desc') {
                return ($answerCounts[$b['id']] ?? 0)
                    <=> ($answerCounts[$a['id']] ?? 0);
            }

            if ($sort === 'answers_asc') {
                return ($answerCounts[$a['id']] ?? 0)
                    <=> ($answerCounts[$b['id']] ?? 0);
            }

            if ($sort === 'start_desc') {
                return strcmp(
                    (string)$b['startAt'],
                    (string)$a['startAt']
                );
            }

            if ($sort === 'start_asc') {
                return strcmp(
                    (string)$a['startAt'],
                    (string)$b['startAt']
                );
            }

            if ($sort === 'updated_asc') {
                return strcmp(
                    (string)$a['updatedAt'],
                    (string)$b['updatedAt']
                );
            }

            return strcmp(
                (string)$b['updatedAt'],
                (string)$a['updatedAt']
            );
        }
    );
    ?>

    <div class="page-title">
        <div>
            <h1>アンケート一覧</h1>
            <p>アンケートの作成・公開・送信・集計を管理します。</p>
        </div>

        <a class="btn btn-primary"
           href="index.php?screen=edit">
            ＋ 新規作成
        </a>
    </div>

    <form method="get" class="toolbar">
        <input type="hidden" name="screen" value="list">

        <div class="search-box">
            <input
                type="text"
                name="q"
                value="<?= h($q) ?>"
                placeholder="タイトルで検索"
                data-search-enter
            >
            <button type="submit">検索</button>
        </div>

        <select name="filter" onchange="this.form.submit()">
            <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>
                すべて
            </option>
            <option value="published" <?= $filter === 'published' ? 'selected' : '' ?>>
                公開中
            </option>
            <option value="draft" <?= $filter === 'draft' ? 'selected' : '' ?>>
                下書き
            </option>
            <option value="stopped" <?= $filter === 'stopped' ? 'selected' : '' ?>>
                停止
            </option>
            <option value="ended" <?= $filter === 'ended' ? 'selected' : '' ?>>
                終了
            </option>
        </select>

        <select name="sort" onchange="this.form.submit()">
            <option value="updated_desc"
                <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
                更新日：新しい順
            </option>
            <option value="updated_asc"
                <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
                更新日：古い順
            </option>
            <option value="answers_desc"
                <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
                回答数：多い順
            </option>
            <option value="answers_asc"
                <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
                回答数：少ない順
            </option>
            <option value="start_desc"
                <?= $sort === 'start_desc' ? 'selected' : '' ?>>
                開始日：新しい順
            </option>
            <option value="start_asc"
                <?= $sort === 'start_asc' ? 'selected' : '' ?>>
                開始日：古い順
            </option>
        </select>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>タイトル</th>
                    <th>作成日</th>
                    <th>更新日</th>
                    <th>アンケート期間</th>
                    <th>ステータス</th>
                    <th>回答数</th>
                    <th>操作</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$list): ?>
                    <tr>
                        <td colspan="7">
                            <div style="padding:45px;text-align:center;color:#64748b">
                                アンケートがありません。
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($list as $survey): ?>
                    <?php
                    $count = $answerCounts[$survey['id']] ?? 0;
                    $status = $survey['status'] ?? 'draft';
                    ?>

                    <tr>
                        <td>
                            <strong><?= h($survey['title']) ?></strong>
                        </td>

                        <td><?= h($survey['createdAt'] ?? '') ?></td>

                        <td><?= h($survey['updatedAt'] ?? '') ?></td>

                        <td>
                            <?= h($survey['startAt'] ?? '') ?>
                            <br>
                            ～
                            <br>
                            <?= h($survey['endAt'] ?? '') ?>
                        </td>

                        <td>
                            <span class="badge <?= h(status_class($status)) ?>">
                                <?= h(status_label($status)) ?>
                            </span>
                        </td>

                        <td><?= h($count) ?></td>

                        <td>
                            <div class="action-grid">

                                <a class="btn btn-sm"
                                   href="index.php?screen=edit&id=<?= h($survey['id']) ?>">
                                    確認・編集
                                </a>

                                <a class="btn btn-sm"
                                   href="index.php?screen=preview&id=<?= h($survey['id']) ?>">
                                    プレビュー
                                </a>

                                <a class="btn btn-sm"
                                   href="index.php?screen=analytics&id=<?= h($survey['id']) ?>">
                                    集計
                                </a>

                                <a class="btn btn-sm"
                                   href="index.php?screen=send&id=<?= h($survey['id']) ?>">
                                    送信
                                </a>

                                <form method="post"
                                      style="display:inline"
                                      data-confirm="このアンケートを複製しますか？">
                                    <?= csrf_field() ?>
                                    <input type="hidden"
                                           name="action"
                                           value="duplicate_survey">
                                    <input type="hidden"
                                           name="id"
                                           value="<?= h($survey['id']) ?>">
                                    <button class="btn btn-sm"
                                            type="submit">
                                        複製
                                    </button>
                                </form>

                                <form method="post"
                                      style="display:inline"
                                      data-confirm="このアンケートを削除しますか？">
                                    <?= csrf_field() ?>
                                    <input type="hidden"
                                           name="action"
                                           value="delete_survey">
                                    <input type="hidden"
                                           name="id"
                                           value="<?= h($survey['id']) ?>">
                                    <button class="btn btn-sm btn-danger"
                                            type="submit">
                                        削除
                                    </button>
                                </form>

                                <?php if ($status === 'draft'): ?>
                                    <form method="post"
                                          style="display:inline"
                                          data-confirm="このアンケートを公開しますか？">
                                        <?= csrf_field() ?>
                                        <input type="hidden"
                                               name="action"
                                               value="change_status">
                                        <input type="hidden"
                                               name="id"
                                               value="<?= h($survey['id']) ?>">
                                        <input type="hidden"
                                               name="new_status"
                                               value="published">
                                        <button class="btn btn-sm btn-success"
                                                type="submit">
                                            公開
                                        </button>
                                    </form>
                                <?php elseif ($status === 'published'): ?>
                                    <form method="post"
                                          style="display:inline"
                                          data-confirm="このアンケートを停止しますか？">
                                        <?= csrf_field() ?>
                                        <input type="hidden"
                                               name="action"
                                               value="change_status">
                                        <input type="hidden"
                                               name="id"
                                               value="<?= h($survey['id']) ?>">
                                        <input type="hidden"
                                               name="new_status"
                                               value="stopped">
                                        <button class="btn btn-sm btn-warning"
                                                type="submit">
                                            停止
                                        </button>
                                    </form>
                                <?php elseif ($status === 'stopped'): ?>
                                    <form method="post"
                                          style="display:inline"
                                          data-confirm="このアンケートを再開しますか？">
                                        <?= csrf_field() ?>
                                        <input type="hidden"
                                               name="action"
                                               value="change_status">
                                        <input type="hidden"
                                               name="id"
                                               value="<?= h($survey['id']) ?>">
                                        <input type="hidden"
                                               name="new_status"
                                               value="published">
                                        <button class="btn btn-sm btn-success"
                                                type="submit">
                                            再開
                                        </button>
                                    </form>
                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    render_footer();
}

/* =========================================================
 * Admin: edit
 * ======================================================= */

function render_edit(?string $id): void
{
    render_header(
        $id ? 'アンケート編集' : 'アンケート作成',
        'list'
    );

    render_flashes();

    $survey = $id ? find_survey($id) : null;

    if ($id && !$survey) {
        redirect_screen('list');
    }

    if (!$survey) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => date('Y-m-d\TH:i'),
            'endAt' => date(
                'Y-m-d\TH:i',
                strtotime('+30 days')
            ),
            'status' => 'draft',
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => 'new-group',
                    'title' => 'グループ1',
                    'questions' => [],
                ],
            ],
        ];
    }

    $json = json_encode(
        $survey,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    );
    ?>

    <div class="page-title">
        <div>
            <h1>
                <?= $id ? 'アンケート編集' : 'アンケート作成' ?>
            </h1>
        </div>
    </div>

    <form method="post"
          action="index.php?screen=edit<?= $id ? '&id=' . rawurlencode($id) : '' ?>"
          id="editorForm">
        <?= csrf_field() ?>

        <input type="hidden"
               name="action"
               value="save_survey">

        <input type="hidden"
               name="id"
               value="<?= h($survey['id']) ?>">

        <div class="editor-topbar">
            <a class="btn"
               href="index.php?screen=list"
               onclick="return confirm('編集内容を破棄して戻りますか？')">
                キャンセル
            </a>

            <button class="btn btn-primary"
                    type="submit">
                保存して一覧へ
            </button>

            <?php if ($id): ?>
                <a class="btn"
                   href="index.php?screen=preview&id=<?= h($id) ?>">
                    プレビュー
                </a>
            <?php endif; ?>

            <div class="state-area">
                <span>状態：</span>

                <?php
                $status = $survey['status'] ?? 'draft';
                ?>

                <span class="badge <?= h(status_class($status)) ?>">
                    <?= h(status_label($status)) ?>
                </span>

                <?php if ($id && $status !== 'ended'): ?>
                    <?php if ($status === 'draft'): ?>
                        <button
                            type="button"
                            class="btn btn-sm"
                            onclick="changeStatus('published')">
                            公開
                        </button>
                    <?php elseif ($status === 'published'): ?>
                        <button
                            type="button"
                            class="btn btn-sm"
                            onclick="changeStatus('stopped')">
                            停止
                        </button>
                    <?php elseif ($status === 'stopped'): ?>
                        <button
                            type="button"
                            class="btn btn-sm"
                            onclick="changeStatus('published')">
                            再開
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card section">
            <div class="card-body">
                <div class="form-grid">

                    <div class="form-group full">
                        <label for="title">
                            アンケートタイトル
                        </label>

                        <input
                            id="title"
                            type="text"
                            name="title"
                            maxlength="200"
                            required
                            value="<?= h($survey['title']) ?>"
                        >
                    </div>

                    <div class="form-group full">
                        <label for="description">
                            アンケート説明
                        </label>

                        <textarea
                            id="description"
                            name="description"
                        ><?= h($survey['description']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="startAt">
                            開始日時
                        </label>

                        <input
                            id="startAt"
                            type="datetime-local"
                            name="startAt"
                            required
                            value="<?= h($survey['startAt']) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="endAt">
                            終了日時
                        </label>

                        <input
                            id="endAt"
                            type="datetime-local"
                            name="endAt"
                            required
                            value="<?= h($survey['endAt']) ?>"
                        >
                    </div>

                    <div class="form-group full">
                        <label>
                            質問番号の採番方式
                        </label>

                        <label>
                            <input type="radio"
                                   name="numbering"
                                   value="global"
                                   <?= ($survey['numbering'] ?? 'global') === 'global'
                                       ? 'checked'
                                       : '' ?>>
                            アンケート全体で通番
                            （Q1、Q2、Q3...）
                        </label>

                        <label>
                            <input type="radio"
                                   name="numbering"
                                   value="group"
                                   <?= ($survey['numbering'] ?? '') === 'group'
                                       ? 'checked'
                                       : '' ?>>
                            グループ毎に採番
                            （Q1-1、Q1-2、Q2-1...）
                        </label>
                    </div>

                </div>
            </div>
        </div>

        <div class="card section">
            <div class="card-header">
                <strong>質問・グループ</strong>

                <span class="help">
                    ドラッグ＆ドロップで並び替えできます。
                </span>
            </div>

            <div class="card-body"
                 id="editorRoot">
            </div>

            <div class="card-body">
                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="addGroup()">
                    ＋ グループを追加
                </button>
            </div>
        </div>

        <input type="hidden"
               name="structure"
               id="structure">

    </form>

    <form method="post"
          id="statusForm"
          style="display:none">
        <?= csrf_field() ?>
        <input type="hidden"
               name="action"
               value="change_status">
        <input type="hidden"
               name="id"
               value="<?= h($survey['id']) ?>">
        <input type="hidden"
               name="new_status"
               id="newStatus">
    </form>

    <script>
    const editorState = <?= $json ?: '{}' ?>;

    function esc(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    function renumberClient() {
        const numbering =
            document.querySelector(
                'input[name="numbering"]:checked'
            )?.value || 'global';

        let globalNo = 1;

        editorState.groups.forEach(
            (group, gi) => {
                group.questions.forEach(
                    (q, qi) => {
                        q.number =
                            numbering === 'group'
                                ? `Q${gi + 1}-${qi + 1}`
                                : `Q${globalNo++}`;
                    }
                );
            }
        );
    }

    function renderEditor() {
        renumberClient();

        const root = document.getElementById('editorRoot');
        root.innerHTML = '';

        editorState.groups.forEach(
            (group, gi) => {
                const groupEl = document.createElement('div');

                groupEl.className = 'group';
                groupEl.draggable = true;
                groupEl.dataset.index = gi;

                groupEl.addEventListener(
                    'dragstart',
                    e => {
                        e.dataTransfer.setData(
                            'text/plain',
                            String(gi)
                        );
                    }
                );

                groupEl.addEventListener(
                    'dragover',
                    e => e.preventDefault()
                );

                groupEl.addEventListener(
                    'drop',
                    e => {
                        e.preventDefault();

                        const from =
                            Number(
                                e.dataTransfer.getData(
                                    'text/plain'
                                )
                            );

                        if (from === gi) return;

                        const moved =
                            editorState.groups.splice(
                                from,
                                1
                            )[0];

                        editorState.groups.splice(
                            gi,
                            0,
                            moved
                        );

                        renderEditor();
                    }
                );

                groupEl.innerHTML = `
                    <div class="group-header">
                        <span>☰</span>
                        <input class="group-title"
                               type="text"
                               value="${esc(group.title)}">
                        <button type="button"
                                class="btn btn-sm btn-danger">
                            グループ削除
                        </button>
                    </div>
                    <div class="question-list"></div>
                    <div class="add-area">
                        <button type="button"
                                class="btn btn-sm btn-primary">
                            ＋ 質問を追加
                        </button>
                    </div>
                `;

                const title =
                    groupEl.querySelector('.group-title');

                title.addEventListener(
                    'input',
                    () => {
                        group.title = title.value;
                    }
                );

                const deleteGroup =
                    groupEl.querySelector(
                        '.group-header .btn-danger'
                    );

                deleteGroup.addEventListener(
                    'click',
                    () => {
                        if (
                            !confirm(
                                'このグループを削除しますか？'
                            )
                        ) {
                            return;
                        }

                        editorState.groups.splice(
                            gi,
                            1
                        );

                        if (
                            editorState.groups.length === 0
                        ) {
                            editorState.groups.push({
                                id: crypto.randomUUID(),
                                title: 'グループ1',
                                questions: []
                            });
                        }

                        renderEditor();
                    }
                );

                const addQuestion =
                    groupEl.querySelector(
                        '.add-area .btn'
                    );

                addQuestion.addEventListener(
                    'click',
                    () => {
                        group.questions.push({
                            id: crypto.randomUUID(),
                            number: '',
                            text: '新しい質問',
                            type: 'single',
                            required: false,
                            options: [
                                '選択肢1',
                                '選択肢2'
                            ],
                            branches: {}
                        });

                        renderEditor();
                    }
                );

                const questionList =
                    groupEl.querySelector(
                        '.question-list'
                    );

                group.questions.forEach(
                    (question, qi) => {
                        renderQuestion(
                            questionList,
                            group,
                            gi,
                            question,
                            qi
                        );
                    }
                );

                root.appendChild(groupEl);
            }
        );
    }

    function renderQuestion(
        root,
        group,
        gi,
        question,
        qi
    ) {
        const el = document.createElement('div');

        el.className = 'question';
        el.draggable = true;

        el.innerHTML = `
            <div class="question-header">
                <span class="question-number">
                    ${esc(question.number)}
                </span>

                <input class="question-text"
                       type="text"
                       value="${esc(question.text)}">

                <button type="button"
                        class="btn btn-sm btn-danger">
                    削除
                </button>
            </div>

            <div class="question-body">
                <select class="question-type">
                    <option value="single"
                        ${question.type === 'single' ? 'selected' : ''}>
                        単一選択
                    </option>
                    <option value="multiple"
                        ${question.type === 'multiple' ? 'selected' : ''}>
                        複数選択
                    </option>
                    <option value="text"
                        ${question.type === 'text' ? 'selected' : ''}>
                        自由記述
                    </option>
                </select>

                <label>
                    <input type="checkbox"
                           class="required"
                           ${question.required ? 'checked' : ''}>
                    必須
                </label>

                <select class="move-group">
                    <option value="${gi}">
                        このグループ
                    </option>
                    ${editorState.groups.map(
                        (g, index) => `
                            <option value="${index}">
                                ${esc(g.title)}
                            </option>
                        `
                    ).join('')}
                </select>
            </div>

            <div class="question-options"></div>
            <div class="branch-box"></div>
        `;

        const text =
            el.querySelector('.question-text');

        text.addEventListener(
            'input',
            () => question.text = text.value
        );

        const type =
            el.querySelector('.question-type');

        type.addEventListener(
            'change',
            () => {
                question.type = type.value;

                if (
                    question.type === 'single'
                    || question.type === 'multiple'
                ) {
                    if (!Array.isArray(question.options)) {
                        question.options = [
                            '選択肢1',
                            '選択肢2'
                        ];
                    }
                }

                renderEditor();
            }
        );

        const required =
            el.querySelector('.required');

        required.addEventListener(
            'change',
            () => question.required = required.checked
        );

        const deleteButton =
            el.querySelector('.btn-danger');

        deleteButton.addEventListener(
            'click',
            () => {
                if (
                    !confirm(
                        'この質問を削除しますか？'
                    )
                ) {
                    return;
                }

                group.questions.splice(qi, 1);
                renderEditor();
            }
        );

        const move =
            el.querySelector('.move-group');

        move.addEventListener(
            'change',
            () => {
                const target = Number(move.value);

                if (target === gi) return;

                group.questions.splice(qi, 1);

                editorState.groups[target]
                    .questions.push(question);

                renderEditor();
            }
        );

        const options =
            el.querySelector('.question-options');

        if (
            question.type === 'single'
            || question.type === 'multiple'
        ) {
            options.innerHTML =
                '<strong>選択肢</strong>';

            question.options.forEach(
                (option, oi) => {
                    const row =
                        document.createElement('div');

                    row.className = 'option-row';

                    row.innerHTML = `
                        <input type="text"
                               value="${esc(option)}">
                        <button type="button"
                                class="btn btn-sm">
                            削除
                        </button>
                    `;

                    const input =
                        row.querySelector('input');

                    input.addEventListener(
                        'input',
                        () => {
                            question.options[oi] =
                                input.value;
                            renderBranches(
                                el,
                                question
                            );
                        }
                    );

                    row.querySelector('button')
                        .addEventListener(
                            'click',
                            () => {
                                question.options.splice(
                                    oi,
                                    1
                                );
                                renderEditor();
                            }
                        );

                    options.appendChild(row);
                }
            );

            const add =
                document.createElement('button');

            add.type = 'button';
            add.className = 'btn btn-sm';
            add.textContent = '＋ 選択肢';

            add.addEventListener(
                'click',
                () => {
                    question.options.push(
                        '新しい選択肢'
                    );
                    renderEditor();
                }
            );

            options.appendChild(add);
        }

        renderBranches(el, question);

        root.appendChild(el);
    }

    function renderBranches(el, question) {
        const box =
            el.querySelector('.branch-box');

        if (question.type !== 'single') {
            box.innerHTML = '';
            return;
        }

        if (!question.branches) {
            question.branches = {};
        }

        box.innerHTML =
            '<strong>条件分岐</strong>';

        question.options.forEach(
            option => {
                const row =
                    document.createElement('div');

                row.style.marginTop = '7px';

                const current =
                    question.branches[option] || '';

                const select =
                    document.createElement('select');

                select.innerHTML =
                    '<option value="">次の質問へ（通常）</option>'
                    + editorState.groups.flatMap(
                        g => g.questions
                    )
                    .filter(q => q.id !== question.id)
                    .map(
                        q => `
                            <option value="${esc(q.id)}"
                                ${current === q.id ? 'selected' : ''}>
                                ${esc(q.number)} ${esc(q.text)}
                            </option>
                        `
                    )
                    .join('');

                row.innerHTML =
                    '<span>' +
                    esc(option) +
                    ' → </span>';

                row.appendChild(select);

                select.addEventListener(
                    'change',
                    () => {
                        question.branches[option] =
                            select.value;
                    }
                );

                box.appendChild(row);
            }
        );
    }

    function addGroup() {
        editorState.groups.push({
            id: crypto.randomUUID(),
            title:
                'グループ'
                + (editorState.groups.length + 1),
            questions: []
        });

        renderEditor();
    }

    function saveEditor() {
        renumberClient();

        document.getElementById(
            'structure'
        ).value = JSON.stringify({
            groups: editorState.groups
        });

        return true;
    }

    document.getElementById(
        'editorForm'
    ).addEventListener(
        'submit',
        function(e) {
            if (!saveEditor()) {
                e.preventDefault();
            }
        }
    );

    document.querySelectorAll(
        'input[name="numbering"]'
    ).forEach(
        input => input.addEventListener(
            'change',
            renderEditor
        )
    );

    function changeStatus(status) {
        const label = {
            published: '公開',
            stopped: '停止'
        }[status] || status;

        if (!confirm(
            'アンケートを「' +
            label +
            '」に変更しますか？'
        )) {
            return;
        }

        document.getElementById(
            'newStatus'
        ).value = status;

        document.getElementById(
            'statusForm'
        ).submit();
    }

    renderEditor();
    </script>

    <?php
    render_footer();
}

/* =========================================================
 * Preview
 * ======================================================= */

function render_preview(string $id): void
{
    render_header('プレビュー', 'list');

    $survey = find_survey($id);

    if (!$survey) {
        redirect_screen('list');
    }

    ?>
    <div class="page-title">
        <div>
            <h1>プレビュー</h1>
            <p>実際の送信は行われません。</p>
        </div>

        <a class="btn"
           href="index.php?screen=edit&id=<?= h($id) ?>">
            編集へ戻る
        </a>
    </div>

    <div class="actions" style="justify-content:center;margin-bottom:20px">
        <button class="btn" onclick="previewDevice('pc')">
            PC表示
        </button>

        <button class="btn" onclick="previewDevice('mobile')">
            スマートフォン表示
        </button>
    </div>

    <div id="previewDevice"
         class="preview-device">

        <h1><?= h($survey['title']) ?></h1>

        <?php if (($survey['description'] ?? '') !== ''): ?>
            <p><?= nl2br(h($survey['description'])) ?></p>
        <?php endif; ?>

        <?php foreach ($survey['groups'] as $group): ?>
            <h2><?= h($group['title']) ?></h2>

            <?php foreach ($group['questions'] as $question): ?>
                <div class="preview-question">
                    <h3>
                        <?= h($question['number']) ?>
                        <?= h($question['text']) ?>

                        <?php if (!empty($question['required'])): ?>
                            <span style="color:#dc2626">
                                必須
                            </span>
                        <?php endif; ?>
                    </h3>

                    <?php
                    $type = $question['type'] ?? 'text';
                    ?>

                    <?php if ($type === 'single'): ?>

                        <?php foreach (($question['options'] ?? []) as $option): ?>
                            <div class="preview-option">
                                ◯ <?= h($option) ?>
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ($type === 'multiple'): ?>

                        <?php foreach (($question['options'] ?? []) as $option): ?>
                            <div class="preview-option">
                                □ <?= h($option) ?>
                            </div>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <textarea
                            style="width:100%;min-height:100px"
                            disabled></textarea>

                    <?php endif; ?>

                    <?php if ($type === 'single' && !empty($question['branches'])): ?>
                        <div class="alert alert-info">
                            条件分岐設定あり
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

    </div>

    <script>
    function previewDevice(type) {
        const el =
            document.getElementById('previewDevice');

        el.classList.toggle(
            'mobile',
            type === 'mobile'
        );
    }
    </script>

    <?php
    render_footer();
}

/* =========================================================
 * Send
 * ======================================================= */

function render_send(string $id): void
{
    render_header('顧客選択・メール送信', 'list');

    $survey = find_survey($id);

    if (!$survey) {
        redirect_screen('list');
    }

    render_flashes();

    $q = trim((string)($_GET['q'] ?? ''));
    $customers = customers();

    if ($q !== '') {
        $customers = array_values(array_filter(
            $customers,
            static function($c) use ($q): bool {
                return mb_stripos(
                    implode(' ', [
                        $c['organization'] ?? '',
                        $c['name'] ?? '',
                        $c['email'] ?? '',
                        $c['department'] ?? '',
                    ]),
                    $q
                ) !== false;
            }
        ));
    }

    $history = array_values(array_filter(
        histories(),
        static fn($h) =>
            ($h['surveyId'] ?? '') === $id
    ));

    $history = array_reverse($history);

    ?>
    <div class="page-title">
        <div>
            <h1>顧客選択・メール送信</h1>
        </div>

        <a class="btn"
           href="index.php?screen=list">
            一覧へ戻る
        </a>
    </div>

    <div class="target-banner">
        <div class="label">対象アンケート</div>
        <div class="title">
            <?= h($survey['title']) ?>
        </div>
    </div>

    <div class="card">
        <div class="send-tabs">
            <a class="send-tab active"
               href="#send">
                顧客選択・送信
            </a>

            <a class="send-tab"
               href="#history">
                送信履歴
            </a>
        </div>

        <h2 id="send">顧客検索・選択</h2>

        <form method="get"
              class="toolbar">
            <input type="hidden"
                   name="screen"
                   value="send">

            <input type="hidden"
                   name="id"
                   value="<?= h($id) ?>">

            <div class="search-box">
                <input type="text"
                       name="q"
                       value="<?= h($q) ?>"
                       placeholder="組織名・氏名・メール・部署で検索"
                       data-search-enter>
                <button type="submit">
                    検索
                </button>
            </div>
        </form>

        <form method="post"
              data-confirm="選択した顧客へ一括送信します。よろしいですか？">

            <?= csrf_field() ?>

            <input type="hidden"
                   name="action"
                   value="send_bulk">

            <input type="hidden"
                   name="survey_id"
                   value="<?= h($id) ?>">

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>
                            <input type="checkbox"
                                   onclick="toggleCustomers(this)">
                        </th>
                        <th>組織名</th>
                        <th>氏名</th>
                        <th>メールアドレス</th>
                        <th>部署</th>
                        <th>電話番号</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td>
                                <input
                                    class="customer-check"
                                    type="checkbox"
                                    name="customer[]"
                                    value="<?= h($customer['id']) ?>">
                            </td>
                            <td><?= h($customer['organization'] ?? '') ?></td>
                            <td><?= h($customer['name'] ?? '') ?></td>
                            <td><?= h($customer['email'] ?? '') ?></td>
                            <td><?= h($customer['department'] ?? '') ?></td>
                            <td><?= h($customer['phone'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$customers): ?>
                        <tr>
                            <td colspan="6"
                                style="text-align:center;padding:30px">
                                顧客情報がありません。
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="template-grid"
                 style="margin-top:20px">

                <div>
                    <label>
                        メール件名
                    </label>

                    <input type="text"
                           name="subject"
                           value="<?= h($survey['title']) ?>"
                           required>

                    <br><br>

                    <label>
                        メール本文
                    </label>

                    <textarea
                        name="body"
                        required
                        style="min-height:250px"
                    >{顧客名} 様

アンケートへのご協力をお願いいたします。

以下のURLからご回答ください。

{アンケートURL}

よろしくお願いいたします。</textarea>

                    <div class="help">
                        使用可能な変数：
                        {顧客名} / {アンケートURL}
                    </div>
                </div>

                <div>
                    <label>
                        送信文確認
                    </label>

                    <div class="mail-preview"
                         id="mailPreview">
                        顧客名 様

アンケートへのご協力をお願いいたします。

以下のURLからご回答ください。

<?= h(build_answer_url($id)) ?>

よろしくお願いいたします。
                    </div>
                </div>

            </div>

            <div class="actions"
                 style="margin-top:20px">
                <button type="submit"
                        class="btn btn-primary">
                    選択した顧客へ一括送信
                </button>
            </div>
        </form>
    </div>

    <div class="card"
         style="margin-top:20px"
         id="history">

        <div class="card-header">
            <strong>送信履歴</strong>
            <span><?= count($history) ?> 件</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>日時</th>
                    <th>顧客</th>
                    <th>種別</th>
                    <th>結果</th>
                    <th>メッセージ</th>
                    <th>操作</th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($history as $entry): ?>
                    <?php
                    $customerName = '';

                    foreach (customers() as $customer) {
                        if (
                            ($customer['id'] ?? '')
                            ===
                            ($entry['customerId'] ?? '')
                        ) {
                            $customerName =
                                (string)($customer['name'] ?? '');
                            break;
                        }
                    }
                    ?>

                    <tr>
                        <td><?= h($entry['createdAt'] ?? '') ?></td>

                        <td><?= h($customerName) ?></td>

                        <td>
                            <?= match ($entry['type'] ?? '') {
                                'resend' => '再送',
                                'reminder' => 'リマインド',
                                default => '送信',
                            } ?>
                        </td>

                        <td>
                            <?php if (($entry['status'] ?? '') === 'success'): ?>
                                <span class="badge badge-success">
                                    成功
                                </span>
                            <?php else: ?>
                                <span class="badge badge-danger">
                                    失敗
                                </span>
                            <?php endif; ?>
                        </td>

                        <td><?= h($entry['message'] ?? '') ?></td>

                        <td>
                            <div class="actions">
                                <form method="post"
                                      data-confirm="この顧客へ再送しますか？">
                                    <?= csrf_field() ?>

                                    <input type="hidden"
                                           name="action"
                                           value="resend_mail">

                                    <input type="hidden"
                                           name="history_id"
                                           value="<?= h($entry['id']) ?>">

                                    <button class="btn btn-sm"
                                            type="submit">
                                        再送
                                    </button>
                                </form>

                                <form method="post"
                                      data-confirm="この顧客へリマインドを送信しますか？">
                                    <?= csrf_field() ?>

                                    <input type="hidden"
                                           name="action"
                                           value="remind_mail">

                                    <input type="hidden"
                                           name="history_id"
                                           value="<?= h($entry['id']) ?>">

                                    <button class="btn btn-sm"
                                            type="submit">
                                        リマインド
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$history): ?>
                    <tr>
                        <td colspan="6"
                            style="text-align:center;padding:30px;color:#64748b">
                            送信履歴はありません。
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function toggleCustomers(master) {
        document.querySelectorAll(
            '.customer-check'
        ).forEach(
            checkbox => checkbox.checked = master.checked
        );
    }

    const body =
        document.querySelector(
            'textarea[name="body"]'
        );

    const preview =
        document.getElementById('mailPreview');

    if (body && preview) {
        body.addEventListener(
            'input',
            () => {
                preview.textContent =
                    body.value
                    .replaceAll(
                        '{顧客名}',
                        '山田 太郎'
                    )
                    .replaceAll(
                        '{アンケートURL}',
                        <?= json_encode(build_answer_url($id)) ?>
                    );
            }
        );
    }
    </script>

    <?php
    render_footer();
}

/* =========================================================
 * Analytics
 * ======================================================= */

function render_analytics(string $id): void
{
    render_header('回答集計・分析', 'list');

    $survey = find_survey($id);

    if (!$survey) {
        redirect_screen('list');
    }

    render_flashes();

    $allAnswers = array_values(array_filter(
        answers(),
        static fn($a) =>
            ($a['surveyId'] ?? '') === $id
    ));

    $customerCount = count(customers());

    $answered = count($allAnswers);

    $registered = count(array_filter(
        $allAnswers,
        static fn($a) =>
            ($a['customerId'] ?? '') !== ''
    ));

    $unregistered = $answered - $registered;
    $unanswered = max(
        0,
        $customerCount - $registered
    );

    $rate = $customerCount > 0
        ? round(($answered / $customerCount) * 100, 1)
        : 0;

    ?>
    <div class="page-title">
        <div>
            <h1>回答集計・分析</h1>
        </div>

        <div class="actions">
            <a class="btn"
               href="index.php?screen=csv&id=<?= h($id) ?>">
                CSV出力
            </a>

            <a class="btn"
               href="index.php?screen=pdf&id=<?= h($id) ?>">
                PDF出力
            </a>

            <a class="btn"
               href="index.php?screen=list">
                一覧へ戻る
            </a>
        </div>
    </div>

    <div class="target-banner">
        <div class="label">
            対象アンケート
        </div>
        <div class="title">
            <?= h($survey['title']) ?>
        </div>
    </div>

    <div class="summary-grid">

        <div class="summary-card">
            <div>送信対象者数</div>
            <div class="number">
                <?= h($customerCount) ?>
            </div>
        </div>

        <div class="summary-card">
            <div>回答数</div>
            <div class="number">
                <?= h($answered) ?>
            </div>
        </div>

        <div class="summary-card">
            <div>未登録回答数</div>
            <div class="number">
                <?= h($unregistered) ?>
            </div>
        </div>

        <div class="summary-card">
            <div>未回答数</div>
            <div class="number">
                <?= h($unanswered) ?>
            </div>
        </div>

        <div class="summary-card">
            <div>回答率</div>
            <div class="number">
                <?= h($rate) ?>%
            </div>
        </div>

    </div>

    <?php if ($answered === 0): ?>

        <div class="card">
            <div class="card-body"
                 style="text-align:center;padding:60px">
                現在、回答データはありません
            </div>
        </div>

    <?php else: ?>

        <div class="card">
            <div class="card-header">
                <strong>設問別集計</strong>
            </div>

            <div class="card-body">
                <?php foreach ($survey['groups'] as $group): ?>

                    <h2>
                        <?= h($group['title']) ?>
                    </h2>

                    <?php foreach ($group['questions'] as $question): ?>

                        <?php
                        $type =
                            $question['type'] ?? 'text';

                        $values = [];

                        foreach ($allAnswers as $answer) {
                            $v =
                                $answer['answers'][$question['id']]
                                ?? '';

                            if (is_array($v)) {
                                foreach ($v as $x) {
                                    $values[] = (string)$x;
                                }
                            } elseif ($v !== '') {
                                $values[] = (string)$v;
                            }
                        }
                        ?>

                        <div style="margin:25px 0">
                            <h3>
                                <?= h($question['number']) ?>
                                <?= h($question['text']) ?>
                            </h3>

                            <?php if (
                                $type === 'single'
                                || $type === 'multiple'
                            ): ?>

                                <?php
                                $total =
                                    max(1, count($values));
                                ?>

                                <?php foreach (($question['options'] ?? []) as $option): ?>
                                    <?php
                                    $count = count(
                                        array_filter(
                                            $values,
                                            static fn($v) =>
                                                $v === $option
                                        )
                                    );

                                    $percent =
                                        round(
                                            ($count / $total)
                                            * 100,
                                            1
                                        );
                                    ?>

                                    <div style="margin:12px 0">
                                        <div style="display:flex;justify-content:space-between">
                                            <span><?= h($option) ?></span>
                                            <span>
                                                <?= h($count) ?>
                                                件
                                                /
                                                <?= h($percent) ?>%
                                            </span>
                                        </div>

                                        <div class="bar">
                                            <span style="width:<?= h($percent) ?>%"></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                            <?php else: ?>

                                <div style="color:#64748b">
                                    自由記述回答：
                                    <?= h(count($values)) ?>件
                                </div>

                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                <?php endforeach; ?>
            </div>
        </div>

        <div class="card"
             style="margin-top:20px">

            <div class="card-header">
                <strong>個別回答</strong>
            </div>

            <div class="card-body">
                <?php foreach ($allAnswers as $answer): ?>

                    <div style="
                        border:1px solid #dbe2ea;
                        border-radius:8px;
                        padding:15px;
                        margin-bottom:10px;
                    ">
                        <strong>
                            <?= h($answer['answeredAt'] ?? '') ?>
                        </strong>

                        <?php foreach ($survey['groups'] as $group): ?>
                            <?php foreach ($group['questions'] as $question): ?>

                                <?php
                                $v =
                                    $answer['answers'][$question['id']]
                                    ?? '';

                                if (is_array($v)) {
                                    $v = implode(
                                        '、',
                                        $v
                                    );
                                }
                                ?>

                                <div style="margin-top:10px">
                                    <strong>
                                        <?= h($question['number']) ?>
                                        <?= h($question['text']) ?>
                                    </strong>

                                    <div style="white-space:pre-wrap">
                                        <?= h((string)$v) ?>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        <?php endforeach; ?>

                    </div>

                <?php endforeach; ?>
            </div>

        </div>

    <?php endif; ?>

    <?php
    render_footer();
}

/* =========================================================
 * kintone settings
 * ======================================================= */

function render_kintone(): void
{
    render_header('kintone連携設定', 'kintone');

    render_flashes();

    $cfg = settings()['kintone'] ?? [];

    $fields = $_SESSION['kintone_fields'] ?? [];

    ?>
    <div class="page-title">
        <div>
            <h1>kintone連携設定</h1>
            <p>顧客情報の取得元を設定します。</p>
        </div>

        <a class="btn"
           href="index.php?screen=list">
            アンケート一覧
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <strong>接続設定</strong>
        </div>

        <div class="card-body">

            <form method="post">
                <?= csrf_field() ?>

                <input type="hidden"
                       name="action"
                       value="save_kintone">

                <div class="form-grid">

                    <div class="form-group full">
                        <label>
                            サブドメイン
                        </label>

                        <input type="text"
                               name="subdomain"
                               value="<?= h($cfg['subdomain'] ?? '') ?>"
                               placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
                               required>

                        <div class="help">
                            https://xxxx.cybozu.com、
                            xxxx.cybozu.com、
                            xxxx のいずれも入力できます。
                        </div>
                    </div>

                    <div class="form-group">
                        <label>
                            顧客管理アプリID
                        </label>

                        <input type="number"
                               name="app_id"
                               value="<?= h($cfg['app_id'] ?? '') ?>"
                               min="1"
                               required>
                    </div>

                    <div class="form-group">
                        <label>
                            ログイン名
                        </label>

                        <input type="text"
                               name="username"
                               value="<?= h($cfg['username'] ?? '') ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label>
                            パスワード
                        </label>

                        <input type="password"
                               name="password"
                               placeholder="変更しない場合は空欄">
                    </div>

                    <div class="form-group">
                        <label>
                            Proxy
                        </label>

                        <input type="text"
                               name="proxy"
                               value="<?= h($cfg['proxy'] ?? '') ?>"
                               placeholder="host:port">

                        <div class="help">
                            未入力の場合は直接接続します。
                        </div>
                    </div>

                    <div class="form-group full">
                        <label>
                            <input type="checkbox"
                                   name="verify_ssl"
                                   <?= !empty($cfg['verify_ssl'])
                                       ? 'checked'
                                       : '' ?>>
                            SSL証明書を検証する
                        </label>

                        <div class="help">
                            POCでは無効を初期値とします。
                        </div>
                    </div>

                </div>

                <div class="actions"
                     style="margin-top:20px">

                    <button class="btn btn-primary"
                            type="submit">
                        設定保存
                    </button>
                </div>
            </form>

            <hr style="margin:25px 0;border:0;border-top:1px solid #dbe2ea">

            <div class="actions">

                <form method="post"
                      data-confirm="kintoneへ実際に接続して確認します。実行しますか？">
                    <?= csrf_field() ?>

                    <input type="hidden"
                           name="action"
                           value="test_kintone">

                    <button class="btn"
                            type="submit">
                        接続テスト
                    </button>
                </form>

                <form method="post"
                      data-confirm="kintoneから項目一覧を再取得します。実行しますか？">
                    <?= csrf_field() ?>

                    <input type="hidden"
                           name="action"
                           value="get_kintone_fields">

                    <button class="btn"
                            type="submit">
                        項目一覧を再取得
                    </button>
                </form>

                <form method="post"
                      data-confirm="kintoneから顧客情報を同期します。実行しますか？">
                    <?= csrf_field() ?>

                    <input type="hidden"
                           name="action"
                           value="sync_kintone">

                    <button class="btn btn-primary"
                            type="submit">
                        顧客情報を同期
                    </button>
                </form>

            </div>
        </div>
    </div>

    <div class="card"
         style="margin-top:20px">

        <div class="card-header">
            <strong>顧客項目マッピング</strong>
        </div>

        <div class="card-body">

            <?php
            $mappings = $cfg['mappings'] ?? [];

            $fieldOptions = '';

            foreach ($fields as $code => $field) {
                $label =
                    $field['label']
                    ?? $field['code']
                    ?? $code;

                $fieldOptions .=
                    '<option value="'
                    . h($code)
                    . '">'
                    . h($label)
                    . ' ('
                    . h($code)
                    . ')</option>';
            }
            ?>

            <?php if (!$fields): ?>
                <div class="alert alert-info">
                    「項目一覧を再取得」を実行すると、
                    kintoneの項目を選択できます。
                </div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_field() ?>

                <input type="hidden"
                       name="action"
                       value="save_kintone_mapping">

                <?php
                $mappingRows = [
                    'map_organization' => [
                        '組織名',
                        $mappings['organization'] ?? ''
                    ],
                    'map_name' => [
                        '氏名',
                        $mappings['name'] ?? ''
                    ],
                    'map_email' => [
                        'メールアドレス',
                        $mappings['email'] ?? ''
                    ],
                    'map_department' => [
                        '部署名',
                        $mappings['department'] ?? ''
                    ],
                    'map_phone' => [
                        '電話番号',
                        $mappings['phone'] ?? ''
                    ],
                ];
                ?>

                <?php foreach ($mappingRows as $name => $row): ?>
                    <div class="mapping">
                        <label><?= h($row[0]) ?></label>

                        <select name="<?= h($name) ?>">
                            <option value="">
                                選択してください
                            </option>

                            <?php foreach ($fields as $code => $field): ?>
                                <?php
                                $label =
                                    $field['label']
                                    ?? $field['code']
                                    ?? $code;
                                ?>

                                <option
                                    value="<?= h($code) ?>"
                                    <?= $row[1] === $code
                                        ? 'selected'
                                        : '' ?>>
                                    <?= h($label) ?>
                                    (<?= h($code) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>

                <div class="mapping">
                    <label>
                        住所
                    </label>

                    <div class="address-checks">
                        <?php foreach ($fields as $code => $field): ?>
                            <?php
                            $label =
                                $field['label']
                                ?? $field['code']
                                ?? $code;

                            $checked =
                                in_array(
                                    $code,
                                    (array)($mappings['address'] ?? []),
                                    true
                                );
                            ?>

                            <label>
                                <input type="checkbox"
                                       name="map_address[]"
                                       value="<?= h($code) ?>"
                                       <?= $checked
                                           ? 'checked'
                                           : '' ?>>
                                <?= h($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="actions"
                     style="margin-top:20px">

                    <button class="btn btn-primary"
                            type="submit">
                        マッピングを保存
                    </button>
                </div>
            </form>

        </div>
    </div>

    <?php
    render_footer();
}

/* =========================================================
 * Mail settings
 * ======================================================= */

function render_mail(): void
{
    render_header('メールサーバ設定', 'mail');

    render_flashes();

    $cfg = settings()['mail'] ?? [];

    $status = $cfg['status'] ?? 'unset';

    ?>
    <div class="page-title">
        <div>
            <h1>メールサーバ設定</h1>
            <p>SMTPサーバへ実際に接続してメールを送信します。</p>
        </div>

        <a class="btn"
           href="index.php?screen=list">
            アンケート一覧
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <strong>SMTP設定</strong>

            <span class="badge
                <?= $status === 'connected'
                    ? 'badge-success'
                    : ($status === 'failed'
                        ? 'badge-danger'
                        : 'badge-draft') ?>">
                <?= match ($status) {
                    'connected' => '接続確認済み',
                    'failed' => '接続できません',
                    default => '未設定',
                } ?>
            </span>
        </div>

        <div class="card-body">

            <form method="post">
                <?= csrf_field() ?>

                <input type="hidden"
                       name="action"
                       value="save_mail">

                <div class="form-grid">

                    <div class="form-group">
                        <label>
                            SMTPサーバ
                        </label>

                        <input type="text"
                               name="smtp_server"
                               value="<?= h($cfg['smtp_server'] ?? '') ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label>
                            SMTPポート
                        </label>

                        <input type="number"
                               name="smtp_port"
                               min="1"
                               max="65535"
                               value="<?= h($cfg['smtp_port'] ?? 587) ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label>
                            暗号化方式
                        </label>

                        <select name="encryption">
                            <option value="ssl"
                                <?= ($cfg['encryption'] ?? '') === 'ssl'
                                    ? 'selected'
                                    : '' ?>>
                                SSL
                            </option>

                            <option value="tls"
                                <?= ($cfg['encryption'] ?? 'tls') === 'tls'
                                    ? 'selected'
                                    : '' ?>>
                                TLS
                            </option>

                            <option value="none"
                                <?= ($cfg['encryption'] ?? '') === 'none'
                                    ? 'selected'
                                    : '' ?>>
                                なし
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox"
                                   name="auth"
                                   <?= ($cfg['auth'] ?? true)
                                       ? 'checked'
                                       : '' ?>>
                            SMTP認証を使用
                        </label>
                    </div>

                    <div class="form-group">
                        <label>
                            SMTPユーザー名
                        </label>

                        <input type="text"
                               name="smtp_username"
                               value="<?= h($cfg['username'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>
                            SMTPパスワード
                        </label>

                        <input type="password"
                               name="smtp_password"
                               placeholder="変更しない場合は空欄">
                    </div>

                    <div class="form-group">
                        <label>
                            送信元メールアドレス
                        </label>

                        <input type="email"
                               name="from_email"
                               value="<?= h($cfg['from_email'] ?? '') ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label>
                            送信元名
                        </label>

                        <input type="text"
                               name="from_name"
                               value="<?= h($cfg['from_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>
                            返信先メールアドレス
                        </label>

                        <input type="email"
                               name="reply_to"
                               value="<?= h($cfg['reply_to'] ?? '') ?>">
                    </div>

                </div>

                <div class="actions"
                     style="margin-top:20px">

                    <button class="btn btn-primary"
                            type="submit">
                        設定保存
                    </button>
                </div>
            </form>

            <hr style="margin:25px 0;border:0;border-top:1px solid #dbe2ea">

            <div class="actions">

                <form method="post"
                      data-confirm="SMTPサーバへ実際に接続して確認します。実行しますか？">
                    <?= csrf_field() ?>

                    <input type="hidden"
                           name="action"
                           value="test_mail">

                    <button class="btn"
                            type="submit">
                        接続テスト
                    </button>
                </form>

            </div>
        </div>
    </div>

    <div class="card"
         style="margin-top:20px">

        <div class="card-header">
            <strong>テストメール送信</strong>
        </div>

        <div class="card-body">

            <form method="post"
                  data-confirm="入力したアドレスへ実際にテストメールを送信します。よろしいですか？">

                <?= csrf_field() ?>

                <input type="hidden"
                       name="action"
                       value="send_test_mail">

                <div class="form-group">
                    <label>
                        テスト送信先
                    </label>

                    <input type="email"
                           name="test_email"
                           placeholder="example@example.com"
                           required>
                </div>

                <div class="actions"
                     style="margin-top:15px">

                    <button class="btn btn-primary"
                            type="submit">
                        テストメール送信
                    </button>
                </div>
            </form>

        </div>
    </div>

    <?php
    render_footer();
}

/* =========================================================
 * Routing
 * ======================================================= */

switch ($screen) {

    case 'list':
        render_list();
        break;

    case 'edit':
        render_edit(
            isset($_GET['id'])
                ? (string)$_GET['id']
                : null
        );
        break;

    case 'preview':
        $id = (string)($_GET['id'] ?? '');

        if ($id === '') {
            redirect_screen('list');
        }

        render_preview($id);
        break;

    case 'send':
        $id = (string)($_GET['id'] ?? '');

        if ($id === '' || !find_survey($id)) {
            redirect_screen('list');
        }

        render_send($id);
        break;

    case 'analytics':
        $id = (string)($_GET['id'] ?? '');

        if ($id === '' || !find_survey($id)) {
            redirect_screen('list');
        }

        render_analytics($id);
        break;

    case 'kintone':
        render_kintone();
        break;

    case 'mail':
        render_mail();
        break;

    case 'answer':
        $id = (string)($_GET['id'] ?? '');

        if ($id === '') {
            http_response_code(404);
            exit('アンケートが指定されていません。');
        }

        render_answer($id);
        break;

    case 'confirm':
        $id = (string)($_GET['id'] ?? '');

        if ($id === '') {
            http_response_code(404);
            exit('アンケートが指定されていません。');
        }

        render_confirm($id);
        break;

    case 'complete':
        $id = (string)($_GET['id'] ?? '');

        if ($id === '') {
            http_response_code(404);
            exit('アンケートが指定されていません。');
        }

        render_complete($id);
        break;

    default:
        redirect_screen('list');
}
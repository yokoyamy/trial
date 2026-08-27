<?php
declare(strict_types=1);

/*
 * アンケートアプリ
 * Apache 2.4 / PHP 8.5
 * DBなし / cURLなし / mail()なし / Canvasなし
 * 単一エントリーポイント index.php
 */

date_default_timezone_set('Asia/Tokyo');

/* ============================================================
 * Session
 * ============================================================ */

$https = (
    (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
);

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$cookiePath = rtrim($scriptDir, '/');
if ($cookiePath === '') {
    $cookiePath = '/';
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* GETのたびにsession_regenerate_id()しない。
 * 認証変更もPOCでは存在しないため、ここでは再生成しない。
 */

/* ============================================================
 * Constants / paths
 * ============================================================ */

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SURVEYS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const ANSWERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'answers.json';
const SEND_LOG_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'send_logs.json';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';

const STATUS_DRAFT = 'draft';
const STATUS_PUBLISHED = 'published';
const STATUS_STOPPED = 'stopped';
const STATUS_ENDED = 'ended';

const TYPE_SINGLE = 'single';
const TYPE_MULTI = 'multiple';
const TYPE_TEXT = 'text';

const HTTP_TIMEOUT = 10;
const HTTP_READ_TIMEOUT = 20;

/* ============================================================
 * Basic helpers
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowIso(): string
{
    return date('Y-m-d H:i:s');
}

function uuid(): string
{
    try {
        return bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        return uniqid('', true);
    }
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function currentIndexUrl(array $params = []): string
{
    $base = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    if ($params) {
        return $base . '?' . http_build_query($params);
    }
    return $base;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consumeFlash(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($items) ? $items : [];
}

function postString(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function postArray(string $key): array
{
    $value = $_POST[$key] ?? [];
    return is_array($value) ? $value : [];
}

function validId(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._-]{1,100}$/', $id);
}

/* ============================================================
 * File persistence
 * ============================================================ */

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('データ保存ディレクトリを作成できません。');
        }
    }

    $files = [
        SURVEYS_FILE,
        CUSTOMERS_FILE,
        ANSWERS_FILE,
        SEND_LOG_FILE,
        SETTINGS_FILE,
    ];

    foreach ($files as $file) {
        if (!file_exists($file)) {
            atomicWrite($file, []);
        }
    }
}

function readJson(string $file, mixed $default): mixed
{
    ensureDataDir();

    if (!is_file($file)) {
        return $default;
    }

    $fp = @fopen($file, 'rb');
    if ($fp === false) {
        throw new RuntimeException('データファイルを読み込めません。');
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        throw new RuntimeException('データファイルをロックできません。');
    }

    $contents = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('保存データが壊れています。');
    }

    return $data;
}

function atomicWrite(string $file, mixed $data): void
{
    ensureDataDir();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('データをJSON化できません。');
    }

    $tmp = $file . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);

    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        @unlink($tmp);
        throw new RuntimeException('データファイルをロックできません。');
    }

    $ok = fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($ok === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データを保存できません。');
    }
}

/* ============================================================
 * Data access
 * ============================================================ */

function loadSurveys(): array
{
    $data = readJson(SURVEYS_FILE, []);
    return is_array($data) ? array_values($data) : [];
}

function saveSurveys(array $surveys): void
{
    atomicWrite(SURVEYS_FILE, array_values($surveys));
}

function loadCustomers(): array
{
    $data = readJson(CUSTOMERS_FILE, []);
    return is_array($data) ? array_values($data) : [];
}

function saveCustomers(array $customers): void
{
    atomicWrite(CUSTOMERS_FILE, array_values($customers));
}

function loadAnswers(): array
{
    $data = readJson(ANSWERS_FILE, []);
    return is_array($data) ? array_values($data) : [];
}

function saveAnswers(array $answers): void
{
    atomicWrite(ANSWERS_FILE, array_values($answers));
}

function loadSendLogs(): array
{
    $data = readJson(SEND_LOG_FILE, []);
    return is_array($data) ? array_values($data) : [];
}

function saveSendLogs(array $logs): void
{
    atomicWrite(SEND_LOG_FILE, array_values($logs));
}

function loadSettings(): array
{
    $data = readJson(SETTINGS_FILE, []);
    return is_array($data) ? $data : [];
}

function saveSettings(array $settings): void
{
    atomicWrite(SETTINGS_FILE, $settings);
}

function findSurvey(string $id): ?array
{
    foreach (loadSurveys() as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }
    return null;
}

function findCustomer(string $id): ?array
{
    foreach (loadCustomers() as $customer) {
        if ((string)($customer['id'] ?? '') === $id) {
            return $customer;
        }
    }
    return null;
}

/* ============================================================
 * Survey normalization / status
 * ============================================================ */

function normalizeQuestion(array $question): array
{
    $type = (string)($question['type'] ?? TYPE_TEXT);

    if (!in_array($type, [TYPE_SINGLE, TYPE_MULTI, TYPE_TEXT], true)) {
        $type = TYPE_TEXT;
    }

    $options = [];
    foreach (($question['options'] ?? []) as $option) {
        if (is_array($option)) {
            $options[] = [
                'id' => (string)($option['id'] ?? uuid()),
                'label' => (string)($option['label'] ?? ''),
                'nextQuestionId' => (string)($option['nextQuestionId'] ?? ''),
            ];
        }
    }

    return [
        'id' => (string)($question['id'] ?? uuid()),
        'number' => (string)($question['number'] ?? ''),
        'text' => (string)($question['text'] ?? ''),
        'type' => $type,
        'required' => !empty($question['required']),
        'options' => $options,
    ];
}

function normalizeSurvey(array $survey): array
{
    $groups = [];

    foreach (($survey['groups'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions = [];

        foreach (($group['questions'] ?? []) as $question) {
            if (is_array($question)) {
                $questions[] = normalizeQuestion($question);
            }
        }

        $groups[] = [
            'id' => (string)($group['id'] ?? uuid()),
            'title' => (string)($group['title'] ?? '新しいグループ'),
            'questions' => $questions,
        ];
    }

    if (!$groups) {
        $groups[] = [
            'id' => uuid(),
            'title' => '基本情報',
            'questions' => [],
        ];
    }

    $survey['groups'] = $groups;
    $survey['numbering'] = (($survey['numbering'] ?? 'global') === 'group')
        ? 'group'
        : 'global';

    return $survey;
}

function recalculateQuestionNumbers(array &$survey): void
{
    $counter = 1;

    foreach ($survey['groups'] as $gi => &$group) {
        $groupCounter = 1;

        foreach ($group['questions'] as &$question) {
            if ($survey['numbering'] === 'group') {
                $question['number'] = 'Q' . ($gi + 1) . '-' . $groupCounter;
            } else {
                $question['number'] = 'Q' . $counter;
            }

            $counter++;
            $groupCounter++;
        }

        unset($question);
    }

    unset($group);
}

function applyAutomaticEnd(array &$survey): bool
{
    $status = (string)($survey['status'] ?? STATUS_DRAFT);
    $endAt = (string)($survey['endAt'] ?? '');

    if (
        $status === STATUS_PUBLISHED
        && $endAt !== ''
        && strtotime($endAt) !== false
        && strtotime($endAt) < time()
    ) {
        $survey['status'] = STATUS_ENDED;
        $survey['updatedAt'] = nowIso();
        return true;
    }

    return false;
}

function refreshSurvey(array $survey): array
{
    $changed = applyAutomaticEnd($survey);

    if ($changed) {
        $surveys = loadSurveys();

        foreach ($surveys as $i => $item) {
            if (($item['id'] ?? '') === ($survey['id'] ?? '')) {
                $surveys[$i] = $survey;
                break;
            }
        }

        saveSurveys($surveys);
    }

    return $survey;
}

function statusLabel(string $status): string
{
    return match ($status) {
        STATUS_PUBLISHED => '公開中',
        STATUS_STOPPED => '停止',
        STATUS_ENDED => '終了',
        default => '下書き',
    };
}

function statusClass(string $status): string
{
    return match ($status) {
        STATUS_PUBLISHED => 'success',
        STATUS_STOPPED => 'warning',
        STATUS_ENDED => 'danger',
        default => 'gray',
    };
}

/* ============================================================
 * Validation
 * ============================================================ */

function validateSurvey(array $survey): array
{
    $errors = [];

    $title = trim((string)($survey['title'] ?? ''));

    if ($title === '') {
        $errors[] = 'アンケートタイトルを入力してください。';
    } elseif (mb_strlen($title) > 200) {
        $errors[] = 'アンケートタイトルは200文字以内で入力してください。';
    }

    $startAt = (string)($survey['startAt'] ?? '');
    $endAt = (string)($survey['endAt'] ?? '');

    if ($startAt !== '' && strtotime($startAt) === false) {
        $errors[] = '開始日時が正しくありません。';
    }

    if ($endAt !== '' && strtotime($endAt) === false) {
        $errors[] = '終了日時が正しくありません。';
    }

    if (
        $startAt !== ''
        && $endAt !== ''
        && strtotime($startAt) !== false
        && strtotime($endAt) !== false
        && strtotime($endAt) < strtotime($startAt)
    ) {
        $errors[] = '終了日時は開始日時以降にしてください。';
    }

    return $errors;
}

/* ============================================================
 * kintone
 * PHP cURLは使用しない
 * ============================================================ */

function normalizeKintoneDomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace('#^https?://#i', '', $value);
    $value = trim((string)$value, '/');

    if (preg_match('/^[A-Za-z0-9-]+\.cybozu\.com$/i', $value)) {
        return 'https://' . $value;
    }

    if (preg_match('/^[A-Za-z0-9-]+$/', $value)) {
        return 'https://' . $value . '.cybozu.com';
    }

    throw new InvalidArgumentException('kintoneサブドメインが正しくありません。');
}

function validateProxy(string $proxy): bool
{
    if ($proxy === '') {
        return true;
    }

    return (bool)preg_match(
        '/^[A-Za-z0-9.-]+:\d{1,5}$/',
        $proxy
    );
}

function kintoneSettingsForDisplay(array $settings): array
{
    $k = $settings['kintone'] ?? [];

    return [
        'subdomain' => (string)($k['subdomain'] ?? ''),
        'app_id' => (string)($k['app_id'] ?? ''),
        'username' => (string)($k['username'] ?? ''),
        'proxy' => (string)($k['proxy'] ?? ''),
        'verify_ssl' => !empty($k['verify_ssl']),
        'has_password' => !empty($k['password']),
        'field_map' => is_array($k['field_map'] ?? null)
            ? $k['field_map']
            : [],
        'fields' => is_array($k['fields'] ?? null)
            ? $k['fields']
            : [],
        'status' => (string)($k['status'] ?? '未設定'),
    ];
}

function kintoneRequest(
    string $method,
    string $url,
    string $username,
    string $password,
    string $proxy = '',
    bool $verifySsl = false,
    ?array $jsonBody = null
): array {
    if (!function_exists('stream_context_create')) {
        throw new RuntimeException('PHPのHTTPストリーム機能が利用できません。');
    }

    $authorization = base64_encode($username . ':' . $password);

    $headers = [
        'X-Cybozu-Authorization: ' . $authorization,
        'Accept: application/json',
    ];

    $content = null;

    if ($jsonBody !== null) {
        $content = json_encode(
            $jsonBody,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException('kintoneリクエストを作成できません。');
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $httpOptions = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'content' => $content ?? '',
        'timeout' => HTTP_READ_TIMEOUT,
        'ignore_errors' => true,
    ];

    $sslOptions = [
        'verify_peer' => $verifySsl,
        'verify_peer_name' => $verifySsl,
        'allow_self_signed' => !$verifySsl,
        'SNI_enabled' => true,
    ];

    if ($proxy !== '') {
        if (!validateProxy($proxy)) {
            throw new InvalidArgumentException(
                'Proxyは host:port 形式で入力してください。'
            );
        }

        $parts = explode(':', $proxy, 2);
        $host = $parts[0];
        $port = (int)$parts[1];

        $httpOptions['proxy'] = 'tcp://' . $host . ':' . $port;
        $httpOptions['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl' => $sslOptions,
    ]);

    $body = @file_get_contents($url, false, $context);

    $statusCode = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $statusCode = (int)$m[1];
            break;
        }
    }

    if ($body === false) {
        $error = error_get_last();
        $detail = is_array($error)
            ? (string)($error['message'] ?? '')
            : '';

        throw new RuntimeException(
            'kintoneへ接続できませんでした。' .
            ($detail !== '' ? '通信エラーを確認してください。' : '')
        );
    }

    $decoded = json_decode($body, true);

    return [
        'status' => $statusCode,
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => $body,
    ];
}

function getKintoneConfig(): array
{
    $settings = loadSettings();
    $k = $settings['kintone'] ?? [];

    $subdomain = normalizeKintoneDomain(
        (string)($k['subdomain'] ?? '')
    );

    $appId = (int)($k['app_id'] ?? 0);
    $username = (string)($k['username'] ?? '');
    $password = (string)($k['password'] ?? '');
    $proxy = (string)($k['proxy'] ?? '');
    $verifySsl = !empty($k['verify_ssl']);

    if ($appId <= 0 || $username === '' || $password === '') {
        throw new RuntimeException(
            'kintone接続設定が不足しています。'
        );
    }

    return [
        'base' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => $verifySsl,
    ];
}

function testKintoneConnection(): array
{
    $config = getKintoneConfig();

    $url = $config['base']
        . '/k/v1/app.json?app='
        . rawurlencode((string)$config['app_id']);

    $result = kintoneRequest(
        'GET',
        $url,
        $config['username'],
        $config['password'],
        $config['proxy'],
        $config['verify_ssl']
    );

    if ($result['status'] < 200 || $result['status'] >= 300) {
        $message = (string)($result['body']['message'] ?? '');

        throw new RuntimeException(
            'kintone接続に失敗しました。' .
            ($message !== '' ? ' kintone: ' . $message : '')
        );
    }

    return $result;
}

function fetchKintoneFields(): array
{
    $config = getKintoneConfig();

    $url = $config['base']
        . '/k/v1/app/form/fields.json?app='
        . rawurlencode((string)$config['app_id']);

    $result = kintoneRequest(
        'GET',
        $url,
        $config['username'],
        $config['password'],
        $config['proxy'],
        $config['verify_ssl']
    );

    if ($result['status'] < 200 || $result['status'] >= 300) {
        $message = (string)($result['body']['message'] ?? '');

        throw new RuntimeException(
            'kintone項目一覧の取得に失敗しました。' .
            ($message !== '' ? ' kintone: ' . $message : '')
        );
    }

    return is_array($result['body']['properties'] ?? null)
        ? $result['body']['properties']
        : [];
}

function fetchKintoneCustomers(): array
{
    $config = getKintoneConfig();
    $settings = loadSettings();
    $fieldMap = $settings['kintone']['field_map'] ?? [];

    $query = '';
    $limit = 500;

    $url = $config['base']
        . '/k/v1/records.json?app='
        . rawurlencode((string)$config['app_id'])
        . '&query='
        . rawurlencode($query)
        . '&totalCount=true';

    $result = kintoneRequest(
        'GET',
        $url,
        $config['username'],
        $config['password'],
        $config['proxy'],
        $config['verify_ssl']
    );

    if ($result['status'] < 200 || $result['status'] >= 300) {
        $message = (string)($result['body']['message'] ?? '');

        throw new RuntimeException(
            'kintone顧客情報の取得に失敗しました。' .
            ($message !== '' ? ' kintone: ' . $message : '')
        );
    }

    $records = $result['body']['records'] ?? [];
    $customers = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $getMapped = static function (string $logical) use ($record, $fieldMap): string {
            $field = (string)($fieldMap[$logical] ?? '');

            if ($field === '' || !isset($record[$field])) {
                return '';
            }

            $value = $record[$field]['value'] ?? '';

            if (is_array($value)) {
                $parts = [];

                foreach ($value as $v) {
                    if (is_array($v) && isset($v['name'])) {
                        $parts[] = (string)$v['name'];
                    } elseif (is_array($v) && isset($v['value'])) {
                        $parts[] = (string)$v['value'];
                    } else {
                        $parts[] = (string)$v;
                    }
                }

                return implode(', ', $parts);
            }

            return (string)$value;
        };

        $customers[] = [
            'id' => (string)($record['$id']['value'] ?? uuid()),
            'organization' => $getMapped('organization'),
            'name' => $getMapped('name'),
            'email' => $getMapped('email'),
            'department' => $getMapped('department'),
            'phone' => $getMapped('phone'),
            'address' => $getMapped('address'),
            'updatedAt' => nowIso(),
        ];
    }

    return $customers;
}

/* ============================================================
 * SMTP
 * ============================================================ */

function smtpSettingsForDisplay(array $settings): array
{
    $m = $settings['mail'] ?? [];

    return [
        'server' => (string)($m['server'] ?? ''),
        'port' => (string)($m['port'] ?? '587'),
        'encryption' => (string)($m['encryption'] ?? 'tls'),
        'auth' => !empty($m['auth']),
        'username' => (string)($m['username'] ?? ''),
        'from' => (string)($m['from'] ?? ''),
        'from_name' => (string)($m['from_name'] ?? ''),
        'reply_to' => (string)($m['reply_to'] ?? ''),
        'has_password' => !empty($m['password']),
        'status' => (string)($m['status'] ?? '未設定'),
    ];
}

function smtpOpen(array $config)
{
    $server = (string)($config['server'] ?? '');
    $port = (int)($config['port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'none');

    if ($server === '' || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException('SMTPサーバ設定が正しくありません。');
    }

    $host = $server;

    if ($encryption === 'ssl') {
        $host = 'ssl://' . $server;
    }

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $host . ':' . $port,
        $errno,
        $errstr,
        HTTP_TIMEOUT,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        throw new RuntimeException(
            'SMTPサーバへ接続できませんでした。'
        );
    }

    stream_set_timeout($fp, HTTP_READ_TIMEOUT);

    smtpRead($fp);

    if ($encryption === 'tls') {
        smtpCommand($fp, 'EHLO localhost', [250]);

        smtpCommand($fp, 'STARTTLS', [220]);

        $crypto = stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($fp);
            throw new RuntimeException('SMTP TLS接続を確立できませんでした。');
        }

        smtpCommand($fp, 'EHLO localhost', [250]);
    } else {
        smtpCommand($fp, 'EHLO localhost', [250]);
    }

    if (!empty($config['auth'])) {
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');

        if ($username === '' || $password === '') {
            fclose($fp);
            throw new RuntimeException('SMTP認証情報が不足しています。');
        }

        smtpCommand($fp, 'AUTH LOGIN', [334]);
        smtpCommand($fp, base64_encode($username), [334]);
        smtpCommand($fp, base64_encode($password), [235]);
    }

    return $fp;
}

function smtpRead($fp): string
{
    $response = '';

    while (($line = fgets($fp, 515)) !== false) {
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTPから応答がありません。');
    }

    $code = (int)substr($response, 0, 3);

    if ($code >= 400) {
        throw new RuntimeException('SMTPサーバからエラー応答を受信しました。');
    }

    return $response;
}

function smtpCommand($fp, string $command, array $expectedCodes): string
{
    fwrite($fp, $command . "\r\n");

    $response = smtpRead($fp);
    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP処理に失敗しました。');
    }

    return $response;
}

function smtpSendMail(
    array $config,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('宛先メールアドレスが正しくありません。');
    }

    $fp = smtpOpen($config);

    try {
        $from = (string)$config['from'];
        $fromName = (string)($config['from_name'] ?? '');
        $replyTo = (string)($config['reply_to'] ?? '');

        smtpCommand($fp, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtpCommand($fp, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?'
            . base64_encode($subject)
            . '?=';

        $fromHeader = $from;

        if ($fromName !== '') {
            $fromHeader = '=?UTF-8?B?'
                . base64_encode($fromName)
                . '?= <' . $from . '>';
        }

        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }

        $message = implode("\r\n", $headers)
            . "\r\n\r\n"
            . str_replace(["\r\n", "\r"], "\n", $body);

        $message = str_replace("\n", "\r\n", $message);
        $message = preg_replace('/^\./m', '..', $message);

        fwrite($fp, $message . "\r\n.\r\n");

        smtpRead($fp);
        smtpCommand($fp, 'QUIT', [221, 250]);
    } finally {
        fclose($fp);
    }
}

function getMailConfig(): array
{
    $settings = loadSettings();
    $m = $settings['mail'] ?? [];

    $server = trim((string)($m['server'] ?? ''));
    $port = (int)($m['port'] ?? 0);
    $encryption = (string)($m['encryption'] ?? 'none');
    $auth = !empty($m['auth']);
    $username = (string)($m['username'] ?? '');
    $password = (string)($m['password'] ?? '');
    $from = trim((string)($m['from'] ?? ''));

    if (
        $server === ''
        || $port < 1
        || $port > 65535
        || !in_array($encryption, ['ssl', 'tls', 'none'], true)
        || !filter_var($from, FILTER_VALIDATE_EMAIL)
    ) {
        throw new RuntimeException('SMTP設定が不足しています。');
    }

    return [
        'server' => $server,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'from' => $from,
        'from_name' => (string)($m['from_name'] ?? ''),
        'reply_to' => (string)($m['reply_to'] ?? ''),
    ];
}

function testSmtpConnection(): void
{
    $config = getMailConfig();

    $fp = smtpOpen($config);
    smtpCommand($fp, 'QUIT', [221, 250]);
    fclose($fp);
}

/* ============================================================
 * Screen helpers
 * ============================================================ */

function requireSurveyId(): ?array
{
    $id = isset($_GET['id']) ? (string)$_GET['id'] : '';

    if (!validId($id)) {
        flash('error', '対象アンケートが指定されていません。');
        redirectTo(currentIndexUrl(['screen' => 'list']));
    }

    $survey = findSurvey($id);

    if ($survey === null) {
        flash('error', '指定されたアンケートが存在しません。');
        redirectTo(currentIndexUrl(['screen' => 'list']));
    }

    return refreshSurvey(normalizeSurvey($survey));
}

/* ============================================================
 * POST actions
 * ============================================================ */

function handlePost(): void
{
    $action = postString('action');

    if ($action === '') {
        return;
    }

    try {
        switch ($action) {

            /* ---------------- Survey ---------------- */

            case 'save_survey':
                handleSaveSurvey();
                break;

            case 'change_status':
                handleChangeStatus();
                break;

            case 'delete_survey':
                handleDeleteSurvey();
                break;

            case 'duplicate_survey':
                handleDuplicateSurvey();
                break;

            case 'save_structure':
                handleSaveStructure();
                break;

            /* ---------------- Answer ---------------- */

            case 'answer_next':
                handleAnswerNext();
                break;

            case 'answer_submit':
                handleAnswerSubmit();
                break;

            /* ---------------- Kintone ---------------- */

            case 'save_kintone':
                handleSaveKintone();
                break;

            case 'test_kintone':
                handleTestKintone();
                break;

            case 'fetch_kintone_fields':
                handleFetchKintoneFields();
                break;

            case 'sync_kintone':
                handleSyncKintone();
                break;

            /* ---------------- Mail ---------------- */

            case 'save_mail':
                handleSaveMail();
                break;

            case 'test_mail':
                handleTestMail();
                break;

            case 'send_test_mail':
                handleSendTestMail();
                break;

            /* ---------------- Sending ---------------- */

            case 'send_selected':
                handleSendSelected();
                break;

            case 'resend':
                handleResend();
                break;

            case 'remind':
                handleRemind();
                break;

            default:
                flash('error', '不明な操作です。');
                break;
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        flash('error', '処理中にシステムエラーが発生しました。');
    }
}

/* ============================================================
 * Survey POST
 * ============================================================ */

function handleSaveSurvey(): void
{
    $id = postString('id');
    $title = postString('title');
    $description = postString('description');
    $startAt = postString('startAt');
    $endAt = postString('endAt');
    $numbering = postString('numbering', 'global');

    if ($id !== '' && !validId($id)) {
        throw new InvalidArgumentException('アンケートIDが正しくありません。');
    }

    $surveys = loadSurveys();

    if ($id === '') {
        $survey = [
            'id' => 'survey-' . uuid(),
            'title' => $title,
            'description' => $description,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'numbering' => $numbering === 'group' ? 'group' : 'global',
            'status' => STATUS_DRAFT,
            'createdAt' => nowIso(),
            'updatedAt' => nowIso(),
            'groups' => [
                [
                    'id' => uuid(),
                    'title' => '基本情報',
                    'questions' => [],
                ],
            ],
        ];

        $errors = validateSurvey($survey);

        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        recalculateQuestionNumbers($survey);
        $surveys[] = $survey;
    } else {
        $found = false;

        foreach ($surveys as $i => $item) {
            if (($item['id'] ?? '') !== $id) {
                continue;
            }

            $found = true;

            $currentStatus = (string)($item['status'] ?? STATUS_DRAFT);

            if ($currentStatus === STATUS_ENDED) {
                $newStatus = STATUS_ENDED;
            } else {
                $newStatus = $currentStatus;
            }

            $item['title'] = $title;
            $item['description'] = $description;
            $item['startAt'] = $startAt;
            $item['endAt'] = $endAt;
            $item['numbering'] = $numbering === 'group'
                ? 'group'
                : 'global';
            $item['status'] = $newStatus;
            $item['updatedAt'] = nowIso();

            $item = normalizeSurvey($item);
            recalculateQuestionNumbers($item);

            $errors = validateSurvey($item);

            if ($errors) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }

            $surveys[$i] = $item;
            break;
        }

        if (!$found) {
            throw new RuntimeException('アンケートが存在しません。');
        }
    }

    saveSurveys($surveys);

    flash('success', 'アンケートを保存しました。');
    redirectTo(currentIndexUrl(['screen' => 'list']));
}

function handleChangeStatus(): void
{
    $id = postString('id');
    $newStatus = postString('new_status');

    if (!validId($id)) {
        throw new InvalidArgumentException('アンケートIDが正しくありません。');
    }

    if (!in_array(
        $newStatus,
        [STATUS_DRAFT, STATUS_PUBLISHED, STATUS_STOPPED],
        true
    )) {
        throw new InvalidArgumentException('変更できない状態です。');
    }

    $surveys = loadSurveys();

    foreach ($surveys as $i => $survey) {
        if (($survey['id'] ?? '') !== $id) {
            continue;
        }

        $survey = refreshSurvey(normalizeSurvey($survey));
        $current = (string)($survey['status'] ?? STATUS_DRAFT);

        if ($current === STATUS_ENDED) {
            throw new InvalidArgumentException(
                '終了したアンケートの状態は変更できません。'
            );
        }

        $allowed = [
            STATUS_DRAFT => [STATUS_PUBLISHED],
            STATUS_PUBLISHED => [STATUS_STOPPED],
            STATUS_STOPPED => [STATUS_PUBLISHED],
        ];

        if (!in_array($newStatus, $allowed[$current] ?? [], true)) {
            throw new InvalidArgumentException(
                'この状態変更はできません。'
            );
        }

        $survey['status'] = $newStatus;
        $survey['updatedAt'] = nowIso();

        $surveys[$i] = $survey;
        saveSurveys($surveys);

        flash('success', '状態を変更しました。');
        redirectTo(currentIndexUrl(['screen' => 'edit', 'id' => $id]));
    }

    throw new RuntimeException('アンケートが存在しません。');
}

function handleDeleteSurvey(): void
{
    $id = postString('id');

    if (!validId($id)) {
        throw new InvalidArgumentException('アンケートIDが正しくありません。');
    }

    $surveys = loadSurveys();
    $new = [];
    $found = false;

    foreach ($surveys as $survey) {
        if (($survey['id'] ?? '') === $id) {
            $found = true;
            continue;
        }

        $new[] = $survey;
    }

    if (!$found) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    saveSurveys($new);

    flash('success', 'アンケートを削除しました。');
    redirectTo(currentIndexUrl(['screen' => 'list']));
}

function handleDuplicateSurvey(): void
{
    $id = postString('id');

    $survey = findSurvey($id);

    if ($survey === null) {
        throw new RuntimeException('複製元アンケートが存在しません。');
    }

    $survey = normalizeSurvey($survey);
    $survey['id'] = 'survey-' . uuid();
    $survey['title'] = $survey['title'] . '（コピー）';
    $survey['status'] = STATUS_DRAFT;
    $survey['createdAt'] = nowIso();
    $survey['updatedAt'] = nowIso();

    foreach ($survey['groups'] as &$group) {
        $group['id'] = uuid();

        foreach ($group['questions'] as &$question) {
            $question['id'] = uuid();

            foreach ($question['options'] as &$option) {
                $option['id'] = uuid();
            }

            unset($option);
        }

        unset($question);
    }

    unset($group);

    recalculateQuestionNumbers($survey);

    $surveys = loadSurveys();
    $surveys[] = $survey;
    saveSurveys($surveys);

    flash('success', 'アンケートを複製しました。');
    redirectTo(currentIndexUrl(['screen' => 'list']));
}

function handleSaveStructure(): void
{
    $id = postString('id');
    $json = postString('structure');

    if (!validId($id)) {
        throw new InvalidArgumentException('アンケートIDが正しくありません。');
    }

    $structure = json_decode($json, true);

    if (!is_array($structure)) {
        throw new InvalidArgumentException('質問構成が正しくありません。');
    }

    $survey = findSurvey($id);

    if ($survey === null) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    $survey = normalizeSurvey($survey);
    $survey['groups'] = $structure['groups'] ?? $survey['groups'];
    $survey['numbering'] = (($structure['numbering'] ?? 'global') === 'group')
        ? 'group'
        : 'global';
    $survey['updatedAt'] = nowIso();

    recalculateQuestionNumbers($survey);

    $surveys = loadSurveys();

    foreach ($surveys as $i => $item) {
        if (($item['id'] ?? '') === $id) {
            $surveys[$i] = $survey;
            saveSurveys($surveys);

            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(
                [
                    'ok' => true,
                    'survey' => $survey,
                ],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }
    }

    throw new RuntimeException('アンケートを保存できません。');
}

/* ============================================================
 * Kintone POST
 * ============================================================ */

function handleSaveKintone(): void
{
    $settings = loadSettings();
    $old = $settings['kintone'] ?? [];

    $subdomain = postString('subdomain');
    $appId = postString('app_id');
    $username = postString('username');
    $password = postString('password');
    $proxy = postString('proxy');
    $verifySsl = postString('verify_ssl') === '1';

    if ($subdomain === '') {
        throw new InvalidArgumentException(
            'kintoneサブドメインを入力してください。'
        );
    }

    normalizeKintoneDomain($subdomain);

    if (!ctype_digit($appId) || (int)$appId <= 0) {
        throw new InvalidArgumentException(
            '顧客管理アプリIDが正しくありません。'
        );
    }

    if ($username === '') {
        throw new InvalidArgumentException(
            'ログイン名を入力してください。'
        );
    }

    if ($password === '' && !empty($old['password'])) {
        $password = (string)$old['password'];
    }

    if ($password === '') {
        throw new InvalidArgumentException(
            'パスワードを入力してください。'
        );
    }

    if (!validateProxy($proxy)) {
        throw new InvalidArgumentException(
            'Proxyはhost:port形式で入力してください。'
        );
    }

    $settings['kintone'] = [
        'subdomain' => $subdomain,
        'app_id' => (int)$appId,
        'username' => $username,
        'password' => $password,
        'proxy' => $proxy,
        'verify_ssl' => $verifySsl,
        'field_map' => is_array($old['field_map'] ?? null)
            ? $old['field_map']
            : [],
        'fields' => is_array($old['fields'] ?? null)
            ? $old['fields']
            : [],
        'status' => '未設定',
    ];

    saveSettings($settings);

    flash('success', 'kintone設定を保存しました。');
    redirectTo(currentIndexUrl(['screen' => 'kintone']));
}

function handleTestKintone(): void
{
    $result = testKintoneConnection();

    $settings = loadSettings();

    if (!isset($settings['kintone'])) {
        $settings['kintone'] = [];
    }

    $settings['kintone']['status'] = '接続確認済み';
    $settings['kintone']['lastTestAt'] = nowIso();

    saveSettings($settings);

    flash('success', 'kintone接続成功。実際のkintoneへ接続しました。');
    redirectTo(currentIndexUrl(['screen' => 'kintone']));
}

function handleFetchKintoneFields(): void
{
    $fields = fetchKintoneFields();

    $settings = loadSettings();

    if (!isset($settings['kintone'])) {
        $settings['kintone'] = [];
    }

    $settings['kintone']['fields'] = $fields;
    $settings['kintone']['fieldsFetchedAt'] = nowIso();

    saveSettings($settings);

    flash('success', 'kintoneの項目一覧を再取得しました。');
    redirectTo(currentIndexUrl(['screen' => 'kintone']));
}

function handleSyncKintone(): void
{
    $customers = fetchKintoneCustomers();

    saveCustomers($customers);

    $settings = loadSettings();

    if (!isset($settings['kintone'])) {
        $settings['kintone'] = [];
    }

    $settings['kintone']['lastSyncAt'] = nowIso();
    $settings['kintone']['syncCount'] = count($customers);

    saveSettings($settings);

    flash(
        'success',
        count($customers) . '件の顧客情報をkintoneから同期しました。'
    );

    redirectTo(currentIndexUrl(['screen' => 'kintone']));
}

/* ============================================================
 * Mail POST
 * ============================================================ */

function handleSaveMail(): void
{
    $settings = loadSettings();
    $old = $settings['mail'] ?? [];

    $server = postString('server');
    $port = postString('port');
    $encryption = postString('encryption', 'tls');
    $auth = postString('auth') === '1';
    $username = postString('username');
    $password = postString('password');
    $from = postString('from');
    $fromName = postString('from_name');
    $replyTo = postString('reply_to');

    if ($server === '') {
        throw new InvalidArgumentException('SMTPサーバを入力してください。');
    }

    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        throw new InvalidArgumentException('SMTPポートが正しくありません。');
    }

    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        throw new InvalidArgumentException('暗号化方式が正しくありません。');
    }

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            '送信元メールアドレスが正しくありません。'
        );
    }

    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            '返信先メールアドレスが正しくありません。'
        );
    }

    if ($auth) {
        if ($username === '') {
            throw new InvalidArgumentException(
                'SMTPユーザー名を入力してください。'
            );
        }

        if ($password === '' && !empty($old['password'])) {
            $password = (string)$old['password'];
        }

        if ($password === '') {
            throw new InvalidArgumentException(
                'SMTPパスワードを入力してください。'
            );
        }
    } else {
        $password = '';
    }

    $settings['mail'] = [
        'server' => $server,
        'port' => (int)$port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'from' => $from,
        'from_name' => $fromName,
        'reply_to' => $replyTo,
        'status' => '未設定',
    ];

    saveSettings($settings);

    flash('success', 'メールサーバ設定を保存しました。');
    redirectTo(currentIndexUrl(['screen' => 'mail']));
}

function handleTestMail(): void
{
    testSmtpConnection();

    $settings = loadSettings();

    if (!isset($settings['mail'])) {
        $settings['mail'] = [];
    }

    $settings['mail']['status'] = '接続確認済み';
    $settings['mail']['lastTestAt'] = nowIso();

    saveSettings($settings);

    flash('success', 'SMTP接続確認済みです。');
    redirectTo(currentIndexUrl(['screen' => 'mail']));
}

function handleSendTestMail(): void
{
    $to = postString('test_to');

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            'テスト送信先メールアドレスが正しくありません。'
        );
    }

    $config = getMailConfig();

    smtpSendMail(
        $config,
        $to,
        'アンケートアプリ テストメール',
        "これはアンケートアプリからのSMTPテストメールです。\n"
        . "送信日時: " . nowIso()
    );

    flash('success', 'テストメールを送信しました。');
    redirectTo(currentIndexUrl(['screen' => 'mail']));
}

/* ============================================================
 * Sending
 * ============================================================ */

function replaceMailVariables(
    string $text,
    array $customer,
    array $survey
): string {
    $url = currentIndexUrl([
        'screen' => 'answer',
        'id' => (string)$survey['id'],
    ]);

    return str_replace(
        ['{顧客名}', '{アンケートURL}'],
        [
            (string)($customer['name'] ?? ''),
            $url,
        ],
        $text
    );
}

function handleSendSelected(): void
{
    $surveyId = postString('survey_id');
    $customerIds = postArray('customer_ids');
    $subject = postString('subject');
    $body = postString('body');

    if (!validId($surveyId)) {
        throw new InvalidArgumentException(
            '対象アンケートが指定されていません。'
        );
    }

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        throw new RuntimeException('対象アンケートが存在しません。');
    }

    if (!$customerIds) {
        throw new InvalidArgumentException(
            '送信対象顧客を選択してください。'
        );
    }

    if ($subject === '' || $body === '') {
        throw new InvalidArgumentException(
            'メール件名と本文を入力してください。'
        );
    }

    $config = getMailConfig();
    $customers = loadCustomers();
    $logs = loadSendLogs();

    $sent = 0;
    $failed = 0;

    foreach ($customerIds as $customerId) {
        $customerId = (string)$customerId;

        foreach ($customers as $customer) {
            if ((string)($customer['id'] ?? '') !== $customerId) {
                continue;
            }

            $email = (string)($customer['email'] ?? '');

            $log = [
                'id' => 'send-' . uuid(),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'customerName' => (string)($customer['name'] ?? ''),
                'email' => $email,
                'type' => 'send',
                'status' => 'failed',
                'createdAt' => nowIso(),
                'error' => '',
            ];

            try {
                smtpSendMail(
                    $config,
                    $email,
                    replaceMailVariables($subject, $customer, $survey),
                    replaceMailVariables($body, $customer, $survey)
                );

                $log['status'] = 'sent';
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                $log['error'] = 'メール送信に失敗しました。';
            }

            $logs[] = $log;
            break;
        }
    }

    saveSendLogs($logs);

    flash(
        $failed > 0 ? 'warning' : 'success',
        "送信完了：{$sent}件成功 / {$failed}件失敗"
    );

    redirectTo(currentIndexUrl([
        'screen' => 'send',
        'id' => $surveyId,
    ]));
}

function handleResend(): void
{
    handleSendLogAgain('resend');
}

function handleRemind(): void
{
    handleSendLogAgain('remind');
}

function handleSendLogAgain(string $type): void
{
    $logId = postString('log_id');

    $logs = loadSendLogs();
    $target = null;

    foreach ($logs as $log) {
        if (($log['id'] ?? '') === $logId) {
            $target = $log;
            break;
        }
    }

    if ($target === null) {
        throw new RuntimeException('送信履歴が存在しません。');
    }

    $surveyId = (string)($target['surveyId'] ?? '');
    $survey = findSurvey($surveyId);
    $customer = findCustomer((string)($target['customerId'] ?? ''));

    if ($survey === null || $customer === null) {
        throw new RuntimeException('送信対象データが存在しません。');
    }

    $config = getMailConfig();

    $subject = $type === 'remind'
        ? 'アンケート回答のリマインド'
        : 'アンケートの再送';

    $body = ($customer['name'] ?? '') . " 様\n\n"
        . "アンケートへのご回答をお願いいたします。\n\n"
        . "回答URL:\n"
        . currentIndexUrl([
            'screen' => 'answer',
            'id' => $surveyId,
        ]);

    smtpSendMail(
        $config,
        (string)$customer['email'],
        $subject,
        $body
    );

    $logs[] = [
        'id' => 'send-' . uuid(),
        'surveyId' => $surveyId,
        'customerId' => (string)$customer['id'],
        'customerName' => (string)($customer['name'] ?? ''),
        'email' => (string)($customer['email'] ?? ''),
        'type' => $type,
        'status' => 'sent',
        'createdAt' => nowIso(),
        'error' => '',
    ];

    saveSendLogs($logs);

    flash('success', 'メールを再送しました。');

    redirectTo(currentIndexUrl([
        'screen' => 'send',
        'id' => $surveyId,
    ]));
}

/* ============================================================
 * Answer flow
 * ============================================================ */

function answerSessionKey(string $surveyId): string
{
    return 'answer_' . $surveyId;
}

function getAnswerState(string $surveyId): array
{
    $key = answerSessionKey($surveyId);

    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [
            'answers' => [],
            'startedAt' => nowIso(),
        ];
    }

    return $_SESSION[$key];
}

function setAnswerState(string $surveyId, array $state): void
{
    $_SESSION[answerSessionKey($surveyId)] = $state;
}

function visibleQuestions(array $survey, array $answers): array
{
    $all = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $all[] = $question;
        }
    }

    $visible = [];

    foreach ($all as $question) {
        $isVisible = true;

        foreach ($all as $sourceQuestion) {
            if (($sourceQuestion['type'] ?? '') !== TYPE_SINGLE) {
                continue;
            }

            $sourceAnswer = $answers[$sourceQuestion['id']] ?? null;

            if ($sourceAnswer === null || $sourceAnswer === '') {
                continue;
            }

            foreach ($sourceQuestion['options'] as $option) {
                if (
                    (string)($option['id'] ?? '') === (string)$sourceAnswer
                    && (string)($option['nextQuestionId'] ?? '') !== ''
                    && (string)$option['nextQuestionId']
                        === (string)$question['id']
                ) {
                    $isVisible = true;
                }
            }
        }

        if ($isVisible) {
            $visible[] = $question;
        }
    }

    return $visible;
}

function validateAnswer(array $question, mixed $value): ?string
{
    if (empty($question['required'])) {
        return null;
    }

    if ($question['type'] === TYPE_MULTI) {
        if (!is_array($value) || count($value) === 0) {
            return '必須項目に回答してください。';
        }
    } elseif (is_array($value)) {
        if (!$value) {
            return '必須項目に回答してください。';
        }
    } elseif (trim((string)$value) === '') {
        return '必須項目に回答してください。';
    }

    return null;
}

function handleAnswerNext(): void
{
    $surveyId = postString('survey_id');

    if (!validId($surveyId)) {
        throw new InvalidArgumentException(
            'アンケートIDが正しくありません。'
        );
    }

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    $survey = refreshSurvey(normalizeSurvey($survey));

    if (($survey['status'] ?? '') !== STATUS_PUBLISHED) {
        throw new RuntimeException(
            '現在このアンケートには回答できません。'
        );
    }

    $state = getAnswerState($surveyId);
    $posted = $_POST['answers'] ?? [];

    if (!is_array($posted)) {
        $posted = [];
    }

    foreach ($posted as $questionId => $value) {
        $state['answers'][(string)$questionId] = $value;
    }

    foreach (visibleQuestions($survey, $state['answers']) as $question) {
        $value = $state['answers'][$question['id']] ?? null;
        $error = validateAnswer($question, $value);

        if ($error !== null) {
            setAnswerState($surveyId, $state);
            throw new InvalidArgumentException($error);
        }
    }

    setAnswerState($surveyId, $state);

    redirectTo(currentIndexUrl([
        'screen' => 'confirm',
        'id' => $surveyId,
    ]));
}

function handleAnswerSubmit(): void
{
    $surveyId = postString('survey_id');

    if (!validId($surveyId)) {
        throw new InvalidArgumentException(
            'アンケートIDが正しくありません。'
        );
    }

    $survey = findSurvey($surveyId);

    if ($survey === null) {
        throw new RuntimeException('アンケートが存在しません。');
    }

    $state = getAnswerState($surveyId);
    $answers = $state['answers'] ?? [];

    foreach (visibleQuestions($survey, $answers) as $question) {
        $error = validateAnswer(
            $question,
            $answers[$question['id']] ?? null
        );

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
    }

    $answerData = [
        'id' => 'answer-' . uuid(),
        'surveyId' => $surveyId,
        'answers' => $answers,
        'createdAt' => nowIso(),
        'customerId' => '',
        'registered' => false,
    ];

    $all = loadAnswers();
    $all[] = $answerData;
    saveAnswers($all);

    unset($_SESSION[answerSessionKey($surveyId)]);

    redirectTo(currentIndexUrl([
        'screen' => 'complete',
        'id' => $surveyId,
    ]));
}

/* ============================================================
 * POST dispatch
 * ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost();
}

/* ============================================================
 * Screen selection
 * ============================================================ */

$screen = isset($_GET['screen'])
    ? (string)$_GET['screen']
    : 'list';

$allowedScreens = [
    'list',
    'edit',
    'preview',
    'send',
    'analytics',
    'kintone',
    'mail',
    'answer',
    'confirm',
    'complete',
];

if (!in_array($screen, $allowedScreens, true)) {
    $screen = 'list';
}

$flashMessages = consumeFlash();

/* ============================================================
 * Screen-specific data
 * ============================================================ */

$survey = null;

if (in_array($screen, ['edit', 'preview', 'send', 'analytics', 'answer', 'confirm', 'complete'], true)) {
    $survey = requireSurveyId();
}

$settings = loadSettings();
$customers = loadCustomers();
$answers = loadAnswers();
$sendLogs = loadSendLogs();

/* ============================================================
 * Analytics data
 * ============================================================ */

function surveyAnswers(string $surveyId): array
{
    $result = [];

    foreach (loadAnswers() as $answer) {
        if (($answer['surveyId'] ?? '') === $surveyId) {
            $result[] = $answer;
        }
    }

    return $result;
}

function surveySendLogs(string $surveyId): array
{
    $result = [];

    foreach (loadSendLogs() as $log) {
        if (($log['surveyId'] ?? '') === $surveyId) {
            $result[] = $log;
        }
    }

    return $result;
}

function answerCountForSurvey(string $surveyId): int
{
    return count(surveyAnswers($surveyId));
}

function sentCustomerCount(string $surveyId): int
{
    $ids = [];

    foreach (surveySendLogs($surveyId) as $log) {
        if (($log['status'] ?? '') === 'sent') {
            $ids[(string)($log['customerId'] ?? '')] = true;
        }
    }

    return count(array_filter(array_keys($ids)));
}

function questionStats(array $survey, array $surveyAnswers): array
{
    $stats = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $counts = [];

            foreach ($question['options'] as $option) {
                $counts[(string)$option['id']] = [
                    'label' => (string)$option['label'],
                    'count' => 0,
                ];
            }

            $textCount = 0;

            foreach ($surveyAnswers as $answer) {
                $value = $answer['answers'][$question['id']] ?? null;

                if (is_array($value)) {
                    foreach ($value as $v) {
                        if (isset($counts[(string)$v])) {
                            $counts[(string)$v]['count']++;
                        }
                    }
                } elseif ($value !== null && $value !== '') {
                    if (isset($counts[(string)$value])) {
                        $counts[(string)$value]['count']++;
                    } else {
                        $textCount++;
                    }
                }
            }

            $stats[] = [
                'question' => $question,
                'counts' => $counts,
                'textCount' => $textCount,
            ];
        }
    }

    return $stats;
}

/* ============================================================
 * CSV / PDF output
 * ============================================================ */

if ($screen === 'analytics' && isset($_GET['export'])) {
    $export = (string)$_GET['export'];

    if ($export === 'csv') {
        $surveyAnswers = surveyAnswers((string)$survey['id']);

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="survey-'
            . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$survey['id'])
            . '.csv"'
        );

        echo "\xEF\xBB\xBF";

        $fp = fopen('php://output', 'wb');

        $header = ['回答ID', '回答日時'];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $header[] = $question['number']
                    . ' '
                    . $question['text'];
            }
        }

        fputcsv($fp, $header);

        foreach ($surveyAnswers as $answer) {
            $row = [
                $answer['id'] ?? '',
                $answer['createdAt'] ?? '',
            ];

            foreach ($survey['groups'] as $group) {
                foreach ($group['questions'] as $question) {
                    $value = $answer['answers'][$question['id']] ?? '';

                    if (is_array($value)) {
                        $labels = [];

                        foreach ($question['options'] as $option) {
                            if (in_array(
                                (string)$option['id'],
                                array_map('strval', $value),
                                true
                            )) {
                                $labels[] = $option['label'];
                            }
                        }

                        $value = implode(', ', $labels);
                    } else {
                        foreach ($question['options'] as $option) {
                            if ((string)$option['id'] === (string)$value) {
                                $value = $option['label'];
                                break;
                            }
                        }
                    }

                    $row[] = (string)$value;
                }
            }

            fputcsv($fp, $row);
        }

        fclose($fp);
        exit;
    }

    if ($export === 'pdf') {
        /*
         * 外部PDFライブラリを要求しない最小PDF出力。
         * 日本語フォント埋め込みは行わず、英数字中心の集計情報を出力する。
         */
        $surveyAnswers = surveyAnswers((string)$survey['id']);
        $lines = [
            'Survey Report',
            'Title: ' . (string)$survey['title'],
            'Answers: ' . count($surveyAnswers),
            'Generated: ' . nowIso(),
        ];

        foreach (questionStats($survey, $surveyAnswers) as $stat) {
            $q = $stat['question'];
            $lines[] = $q['number'] . ' ' . $q['text'];

            foreach ($stat['counts'] as $count) {
                $lines[] = '  ' . $count['label'] . ': ' . $count['count'];
            }
        }

        $stream = "BT\n/F1 10 Tf\n50 780 Td\n";

        foreach ($lines as $i => $line) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '?', $line);
            $ascii = str_replace(
                ['\\', '(', ')'],
                ['\\\\', '\\(', '\\)'],
                (string)$ascii
            );

            if ($i > 0) {
                $stream .= "0 -16 Td\n";
            }

            $stream .= '(' . $ascii . ") Tj\n";
        }

        $stream .= "ET";

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
            '<< /Length ' . strlen($stream) . ' >>'
            . "\nstream\n"
            . $stream
            . "\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $number => $object) {
            $offsets[$number + 1] = strlen($pdf);
            $pdf .= ($number + 1) . " 0 obj\n"
                . $object
                . "\nendobj\n";
        }

        $xref = strlen($pdf);

        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf(
                "%010d 00000 n \n",
                $offsets[$i]
            );
        }

        $pdf .= "trailer\n"
            . "<< /Size " . (count($objects) + 1)
            . " /Root 1 0 R >>\n"
            . "startxref\n"
            . $xref
            . "\n%%EOF";

        header('Content-Type: application/pdf');
        header(
            'Content-Disposition: attachment; filename="survey-report.pdf"'
        );

        echo $pdf;
        exit;
    }
}

/* ============================================================
 * HTML
 * ============================================================ */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケートアプリ</title>

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

body{
    margin:0;
    background:#f8fafc;
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",
        "Noto Sans JP","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;
}

button,input,textarea,select{
    font:inherit;
}

button{
    cursor:pointer;
}

a{
    color:var(--primary);
    text-decoration:none;
}

.admin-header{
    background:#0f172a;
    color:#fff;
    min-height:64px;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:24px;
}

.admin-header .brand{
    font-weight:700;
    font-size:19px;
    white-space:nowrap;
}

.admin-nav{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.admin-nav a{
    color:#cbd5e1;
    padding:9px 12px;
    border-radius:7px;
}

.admin-nav a:hover,
.admin-nav a.active{
    color:#fff;
    background:#1e293b;
}

.container{
    max-width:1440px;
    margin:0 auto;
    padding:28px 24px 50px;
}

.page-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:22px;
}

.page-title h1{
    margin:0;
    font-size:27px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:20px;
}

.toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}

.btn{
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    border-radius:8px;
    padding:9px 14px;
    min-height:40px;
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

.btn-warning{
    background:var(--warning);
    color:#fff;
    border-color:var(--warning);
}

.btn-danger{
    background:var(--danger);
    color:#fff;
    border-color:var(--danger);
}

.btn-small{
    min-height:34px;
    padding:6px 10px;
    font-size:13px;
}

input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=datetime-local],
select,
textarea{
    width:100%;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 12px;
    background:#fff;
    color:var(--text);
}

textarea{
    min-height:130px;
    resize:vertical;
}

label{
    display:block;
    font-weight:600;
    margin-bottom:6px;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-grid-3{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:18px;
}

.field{
    margin-bottom:16px;
}

.field.full{
    grid-column:1/-1;
}

.help{
    color:var(--gray);
    font-size:13px;
    margin-top:5px;
}

.alert{
    padding:13px 16px;
    border-radius:9px;
    margin-bottom:14px;
    border:1px solid;
}

.alert-success{
    color:#166534;
    background:#f0fdf4;
    border-color:#bbf7d0;
}

.alert-error{
    color:#991b1b;
    background:#fef2f2;
    border-color:#fecaca;
}

.alert-warning{
    color:#92400e;
    background:#fffbeb;
    border-color:#fde68a;
}

.badge{
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}

.badge-success{
    color:#166534;
    background:#dcfce7;
}

.badge-warning{
    color:#92400e;
    background:#fef3c7;
}

.badge-danger{
    color:#991b1b;
    background:#fee2e2;
}

.badge-gray{
    color:#475569;
    background:#e2e8f0;
}

.table-wrap{
    width:100%;
    overflow-x:auto;
}

table{
    width:100%;
    min-width:1100px;
    border-collapse:collapse;
}

th,td{
    padding:12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    white-space:nowrap;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}

.search-row{
    display:grid;
    grid-template-columns:minmax(250px,1fr) 180px 180px;
    gap:10px;
    margin-bottom:18px;
}

.tabs{
    display:flex;
    gap:4px;
    border-bottom:1px solid var(--border);
    margin-bottom:20px;
}

.tabs a,
.tabs button{
    border:0;
    background:none;
    padding:11px 14px;
    color:var(--gray);
}

.tabs .active{
    color:var(--primary);
    border-bottom:2px solid var(--primary);
}

.editor-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:20px;
}

.group-card{
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    margin-bottom:18px;
    overflow:hidden;
}

.group-head{
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    padding:12px;
    display:flex;
    align-items:center;
    gap:10px;
}

.group-head input{
    flex:1;
}

.question-list{
    padding:12px;
    min-height:30px;
}

.question-card{
    border:1px solid var(--border);
    border-radius:9px;
    padding:14px;
    margin-bottom:10px;
    background:#fff;
}

.question-card.dragging{
    opacity:.5;
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
    user-select:none;
}

.question-grid{
    display:grid;
    grid-template-columns:80px minmax(0,1fr) 180px;
    gap:10px;
    align-items:start;
}

.options{
    margin-top:10px;
}

.option-row{
    display:grid;
    grid-template-columns:30px minmax(0,1fr) 180px auto;
    gap:8px;
    align-items:center;
    margin-bottom:8px;
}

.preview-box{
    max-width:850px;
    margin:0 auto;
}

.preview-question{
    padding:18px 0;
    border-bottom:1px solid var(--border);
}

.preview-question .number{
    font-weight:700;
    color:var(--primary);
}

.choice{
    display:flex;
    gap:9px;
    align-items:center;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-top:8px;
}

.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:17px;
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    font-size:27px;
    font-weight:700;
    margin-top:5px;
}

.customer-grid{
    display:grid;
    grid-template-columns:320px minmax(0,1fr);
    gap:18px;
}

.customer-list{
    max-height:500px;
    overflow:auto;
    border:1px solid var(--border);
    border-radius:8px;
}

.customer-item{
    display:flex;
    gap:9px;
    padding:11px;
    border-bottom:1px solid var(--border);
}

.log-table{
    max-height:400px;
    overflow:auto;
}

.settings-status{
    padding:12px;
    border-radius:8px;
    background:#f8fafc;
    margin-bottom:16px;
}

.answer-container{
    max-width:760px;
    margin:0 auto;
}

.answer-header{
    margin-bottom:25px;
}

.answer-question{
    background:#fff;
    border:1px solid var(--border);
    border-radius:11px;
    padding:18px;
    margin-bottom:16px;
}

.answer-actions{
    display:flex;
    justify-content:space-between;
    gap:12px;
    margin-top:20px;
}

.complete{
    text-align:center;
    padding:70px 20px;
}

.spinner{
    width:18px;
    height:18px;
    display:inline-block;
    border:2px solid rgba(255,255,255,.5);
    border-top-color:#fff;
    border-radius:50%;
    animation:spin .7s linear infinite;
    vertical-align:-4px;
    margin-right:6px;
}

@keyframes spin{
    to{transform:rotate(360deg)}
}

@media(max-width:900px){
    .form-grid,
    .form-grid-3,
    .stat-grid{
        grid-template-columns:1fr;
    }

    .search-row{
        grid-template-columns:1fr;
    }

    .customer-grid{
        grid-template-columns:1fr;
    }

    .question-grid{
        grid-template-columns:1fr;
    }

    .option-row{
        grid-template-columns:30px 1fr auto;
    }

    .option-row select{
        grid-column:2/4;
    }

    .container{
        padding:20px 14px 40px;
    }

    .admin-header{
        padding:12px 14px;
        align-items:flex-start;
        flex-direction:column;
        gap:8px;
    }
}

@media(max-width:600px){
    .page-title{
        align-items:flex-start;
        flex-direction:column;
    }

    .editor-top{
        flex-direction:column;
        align-items:stretch;
    }

    .answer-actions{
        flex-direction:column;
    }

    .answer-actions .btn{
        width:100%;
    }
}
</style>
</head>

<body>

<?php if (!in_array($screen, ['answer','confirm','complete'], true)): ?>

<header class="admin-header">
    <div class="brand">アンケートアプリ</div>

    <nav class="admin-nav">
        <a
            class="<?= $screen === 'list' ? 'active' : '' ?>"
            href="<?= h(currentIndexUrl(['screen' => 'list'])) ?>"
        >アンケート一覧</a>

        <a
            class="<?= $screen === 'kintone' ? 'active' : '' ?>"
            href="<?= h(currentIndexUrl(['screen' => 'kintone'])) ?>"
        >kintone連携設定</a>

        <a
            class="<?= $screen === 'mail' ? 'active' : '' ?>"
            href="<?= h(currentIndexUrl(['screen' => 'mail'])) ?>"
        >メールサーバ設定</a>
    </nav>
</header>

<?php endif; ?>

<main class="container">

<?php foreach ($flashMessages as $message): ?>
    <div class="alert alert-<?= h($message['type'] ?? 'error') ?>">
        <?= h($message['message'] ?? '') ?>
    </div>
<?php endforeach; ?>

<?php
/* ============================================================
 * LIST
 * ============================================================ */
if ($screen === 'list'):

    $surveys = loadSurveys();

    foreach ($surveys as $i => $item) {
        $surveys[$i] = refreshSurvey(normalizeSurvey($item));
    }

    $search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $filter = isset($_GET['filter'])
        ? (string)$_GET['filter']
        : 'all';

    $sort = isset($_GET['sort'])
        ? (string)$_GET['sort']
        : 'updated_desc';

    $filtered = [];

    foreach ($surveys as $item) {
        $title = (string)($item['title'] ?? '');

        if ($search !== '' && mb_stripos($title, $search) === false) {
            continue;
        }

        $status = (string)($item['status'] ?? STATUS_DRAFT);

        if ($filter !== 'all' && $filter !== $status) {
            continue;
        }

        $filtered[] = $item;
    }

    usort($filtered, static function (array $a, array $b) use ($sort): int {
        $av = $a;
        $bv = $b;

        return match ($sort) {
            'updated_asc' => strcmp(
                (string)($av['updatedAt'] ?? ''),
                (string)($bv['updatedAt'] ?? '')
            ),
            'answers_desc' => answerCountForSurvey((string)$bv['id'])
                <=> answerCountForSurvey((string)$av['id']),
            'answers_asc' => answerCountForSurvey((string)$av['id'])
                <=> answerCountForSurvey((string)$bv['id']),
            'start_desc' => strcmp(
                (string)($bv['startAt'] ?? ''),
                (string)($av['startAt'] ?? '')
            ),
            'start_asc' => strcmp(
                (string)($av['startAt'] ?? ''),
                (string)($bv['startAt'] ?? '')
            ),
            default => strcmp(
                (string)($bv['updatedAt'] ?? ''),
                (string)($av['updatedAt'] ?? '')
            ),
        };
    });
?>

<div class="page-title">
    <h1>アンケート一覧</h1>

    <a
        class="btn btn-primary"
        href="<?= h(currentIndexUrl(['screen' => 'edit'])) ?>"
    >＋ 新規作成</a>
</div>

<div class="card">

<form method="get">
    <input type="hidden" name="screen" value="list">

    <div class="search-row">
        <input
            type="text"
            name="q"
            value="<?= h($search) ?>"
            placeholder="タイトルを検索"
        >

        <select name="filter">
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

        <select name="sort">
            <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>
                更新日：新しい順
            </option>
            <option value="updated_asc" <?= $sort === 'updated_asc' ? 'selected' : '' ?>>
                更新日：古い順
            </option>
            <option value="answers_desc" <?= $sort === 'answers_desc' ? 'selected' : '' ?>>
                回答数：多い順
            </option>
            <option value="answers_asc" <?= $sort === 'answers_asc' ? 'selected' : '' ?>>
                回答数：少ない順
            </option>
            <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>
                開始日：新しい順
            </option>
            <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>
                開始日：古い順
            </option>
        </select>
    </div>

    <button class="btn btn-primary" type="submit">
        検索・絞り込み
    </button>
</form>

</div>

<div class="card">
<div class="table-wrap">

<table>
<thead>
<tr>
    <th>タイトル</th>
    <th>作成日</th>
    <th>更新日</th>
    <th>期間</th>
    <th>ステータス</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>

<tbody>

<?php if (!$filtered): ?>
<tr>
    <td colspan="7">アンケートがありません。</td>
</tr>
<?php endif; ?>

<?php foreach ($filtered as $item): ?>

<?php
$status = (string)($item['status'] ?? STATUS_DRAFT);
$id = (string)$item['id'];
$count = answerCountForSurvey($id);
?>

<tr>
<td>
    <strong><?= h($item['title'] ?? '') ?></strong>
</td>

<td><?= h($item['createdAt'] ?? '') ?></td>
<td><?= h($item['updatedAt'] ?? '') ?></td>

<td>
    <?= h($item['startAt'] ?? '') ?>
    ～
    <?= h($item['endAt'] ?? '') ?>
</td>

<td>
    <span class="badge badge-<?= h(statusClass($status)) ?>">
        <?= h(statusLabel($status)) ?>
    </span>
</td>

<td><?= h($count) ?></td>

<td>
<div class="actions">

<a
    class="btn btn-small"
    href="<?= h(currentIndexUrl([
        'screen' => 'edit',
        'id' => $id
    ])) ?>"
>確認・編集</a>

<a
    class="btn btn-small"
    href="<?= h(currentIndexUrl([
        'screen' => 'analytics',
        'id' => $id
    ])) ?>"
>集計</a>

<a
    class="btn btn-small"
    href="<?= h(currentIndexUrl([
        'screen' => 'send',
        'id' => $id
    ])) ?>"
>送信</a>

<form method="post" style="display:inline">
    <input type="hidden" name="action" value="duplicate_survey">
    <input type="hidden" name="id" value="<?= h($id) ?>">
    <button
        class="btn btn-small"
        type="submit"
        data-confirm="このアンケートを複製しますか？"
    >複製</button>
</form>

<form method="post" style="display:inline">
    <input type="hidden" name="action" value="delete_survey">
    <input type="hidden" name="id" value="<?= h($id) ?>">
    <button
        class="btn btn-small btn-danger"
        type="submit"
        data-confirm="このアンケートを削除しますか？"
    >削除</button>
</form>

</div>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>
</div>

<?php
/* ============================================================
 * EDIT
 * ============================================================
 */
elseif ($screen === 'edit'):

    if ($survey === null) {
        exit;
    }

    $survey = normalizeSurvey($survey);
    $id = (string)($survey['id'] ?? '');
    $status = (string)($survey['status'] ?? STATUS_DRAFT);
?>

<div class="page-title">
    <h1>アンケート作成・編集</h1>
</div>

<div class="card">

<div class="editor-top">

<div class="toolbar">

<a
    class="btn"
    href="<?= h(currentIndexUrl(['screen' => 'list'])) ?>"
    data-confirm="編集内容を破棄して一覧へ戻りますか？"
>キャンセル</a>

<button
    class="btn btn-primary"
    type="button"
    id="saveStructureButton"
>保存して一覧へ</button>

</div>

<div class="toolbar">

<span>状態：</span>

<?php if ($status !== STATUS_ENDED): ?>

<?php
$nextStatus = match ($status) {
    STATUS_DRAFT => STATUS_PUBLISHED,
    STATUS_PUBLISHED => STATUS_STOPPED,
    STATUS_STOPPED => STATUS_PUBLISHED,
    default => STATUS_DRAFT,
};

$nextLabel = match ($status) {
    STATUS_DRAFT => '公開中',
    STATUS_PUBLISHED => '停止',
    STATUS_STOPPED => '公開中',
    default => '終了',
};
?>

<form method="post">
    <input type="hidden" name="action" value="change_status">
    <input type="hidden" name="id" value="<?= h($id) ?>">
    <input type="hidden" name="new_status" value="<?= h($nextStatus) ?>">

    <button
        class="btn"
        type="submit"
        data-confirm="<?= h($nextLabel) ?>に変更しますか？"
    ><?= h($nextLabel) ?></button>
</form>

<?php else: ?>

<span class="badge badge-danger">終了</span>

<?php endif; ?>

</div>

</div>

<form
    method="post"
    id="surveyForm"
    action="<?= h(currentIndexUrl(['screen' => 'edit'])) ?>"
>
    <input type="hidden" name="action" value="save_survey">
    <input type="hidden" name="id" value="<?= h($id) ?>">
    <input type="hidden" name="numbering" id="numberingInput" value="<?= h($survey['numbering']) ?>">

    <div class="form-grid">

        <div class="field full">
            <label for="title">アンケートタイトル</label>
            <input
                id="title"
                type="text"
                name="title"
                maxlength="200"
                value="<?= h($survey['title'] ?? '') ?>"
                required
            >
        </div>

        <div class="field full">
            <label for="description">アンケート説明</label>
            <textarea
                id="description"
                name="description"
            ><?= h($survey['description'] ?? '') ?></textarea>
        </div>

        <div class="field">
            <label for="startAt">開始日時</label>
            <input
                id="startAt"
                type="datetime-local"
                name="startAt"
                value="<?= h(
                    $survey['startAt'] !== ''
                        ? date('Y-m-d\TH:i', strtotime((string)$survey['startAt']))
                        : ''
                ) ?>"
            >
        </div>

        <div class="field">
            <label for="endAt">終了日時</label>
            <input
                id="endAt"
                type="datetime-local"
                name="endAt"
                value="<?= h(
                    $survey['endAt'] !== ''
                        ? date('Y-m-d\TH:i', strtotime((string)$survey['endAt']))
                        : ''
                ) ?>"
            >
        </div>

    </div>
</form>

<div class="field">
    <label>質問番号の採番方式</label>

    <div class="toolbar">

        <label style="font-weight:400">
            <input
                type="radio"
                name="numbering_choice"
                value="global"
                <?= $survey['numbering'] === 'global' ? 'checked' : '' ?>
            >
            アンケート全体で通番（Q1、Q2、Q3...）
        </label>

        <label style="font-weight:400">
            <input
                type="radio"
                name="numbering_choice"
                value="group"
                <?= $survey['numbering'] === 'group' ? 'checked' : '' ?>
            >
            グループ毎（Q1-1、Q1-2、Q2-1...）
        </label>

    </div>
</div>

<div id="groups">

<?php foreach ($survey['groups'] as $group): ?>

<div
    class="group-card"
    draggable="true"
    data-group-id="<?= h($group['id']) ?>"
>

<div class="group-head">

<span class="drag-handle">☰</span>

<input
    type="text"
    class="group-title"
    value="<?= h($group['title']) ?>"
>

<button
    type="button"
    class="btn btn-small btn-danger delete-group"
>グループ削除</button>

</div>

<div class="question-list">

<?php foreach ($group['questions'] as $question): ?>

<div
    class="question-card"
    draggable="true"
    data-question-id="<?= h($question['id']) ?>"
>

<div class="question-grid">

<div>
    <span class="drag-handle">↕</span>
    <strong class="question-number">
        <?= h($question['number']) ?>
    </strong>
</div>

<div>

<input
    type="text"
    class="question-text"
    value="<?= h($question['text']) ?>"
    placeholder="質問文"
/>

<div class="options">

<?php foreach ($question['options'] as $option): ?>

<div class="option-row">

<span>○</span>

<input
    type="text"
    class="option-label"
    value="<?= h($option['label']) ?>"
>

<select class="option-next">
    <option value="">次の質問へ</option>

<?php
foreach ($survey['groups'] as $g2):
    foreach ($g2['questions'] as $q2):
?>
<option
    value="<?= h($q2['id']) ?>"
    <?= ($option['nextQuestionId'] ?? '') === $q2['id'] ? 'selected' : '' ?>
>
<?= h($q2['number'] . ' ' . $q2['text']) ?>
</option>
<?php
    endforeach;
endforeach;
?>

</select>

<button
    type="button"
    class="btn btn-small delete-option"
>削除</button>

</div>

<?php endforeach; ?>

</div>

<div class="toolbar" style="margin-top:10px">

<button
    type="button"
    class="btn btn-small add-option"
>選択肢追加</button>

<label style="font-weight:400">
    <input
        type="checkbox"
        class="question-required"
        <?= !empty($question['required']) ? 'checked' : '' ?>
    >
    必須
</label>

</div>

</div>

<div>
<select class="question-type">
    <option
        value="single"
        <?= $question['type'] === TYPE_SINGLE ? 'selected' : '' ?>
    >単一選択</option>

    <option
        value="multiple"
        <?= $question['type'] === TYPE_MULTI ? 'selected' : '' ?>
    >複数選択</option>

    <option
        value="text"
        <?= $question['type'] === TYPE_TEXT ? 'selected' : '' ?>
    >自由記述</option>
</select>

<button
    type="button"
    class="btn btn-small btn-danger delete-question"
    style="margin-top:7px;width:100%"
>質問削除</button>
</div>

</div>

</div>

<?php endforeach; ?>

</div>

<div style="padding:12px">

<button
    type="button"
    class="btn add-question"
>＋ 質問を追加</button>

</div>

</div>

<?php endforeach; ?>

</div>

<div class="card" style="box-shadow:none;margin-top:18px">

<button
    type="button"
    class="btn btn-primary"
    id="addGroup"
>＋ グループを追加</button>

<a
    class="btn"
    href="<?= h(currentIndexUrl([
        'screen' => 'preview',
        'id' => $id
    ])) ?>"
>プレビュー</a>

</div>

<div id="structureHolder"></div>

<?php
/* ============================================================
 * PREVIEW
 * ============================================================
 */
elseif ($screen === 'preview'):

    if ($survey === null) {
        exit;
    }

    $survey = normalizeSurvey($survey);
?>

<div class="page-title">
    <h1>プレビュー</h1>

    <a
        class="btn"
        href="<?= h(currentIndexUrl([
            'screen' => 'edit',
            'id' => $survey['id']
        ])) ?>"
    >編集へ戻る</a>
</div>

<div class="card">
<div class="preview-box">

<h2><?= h($survey['title']) ?></h2>

<p><?= nl2br(h($survey['description'] ?? '')) ?></p>

<?php foreach ($survey['groups'] as $group): ?>

<h3><?= h($group['title']) ?></h3>

<?php foreach ($group['questions'] as $question): ?>

<div class="preview-question">

<div class="number">
    <?= h($question['number']) ?>
</div>

<h4>
    <?= h($question['text']) ?>

    <?php if (!empty($question['required'])): ?>
        <span class="badge badge-danger">必須</span>
    <?php endif; ?>
</h4>

<?php if ($question['type'] === TYPE_TEXT): ?>

<textarea placeholder="自由記述"></textarea>

<?php else: ?>

<?php foreach ($question['options'] as $option): ?>

<label class="choice">

<input
    type="<?= $question['type'] === TYPE_MULTI ? 'checkbox' : 'radio' ?>"
    disabled
>

<span><?= h($option['label']) ?></span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>

<?php
/* ============================================================
 * KINTONE
 * ============================================================
 */
elseif ($screen === 'kintone'):

    $k = kintoneSettingsForDisplay($settings);
?>

<div class="page-title">
    <h1>kintone連携設定</h1>
</div>

<div class="card">

<div class="settings-status">
    接続状態：
    <strong><?= h($k['status']) ?></strong>

    <?php if (!empty($settings['kintone']['lastSyncAt'])): ?>
        ／ 最終同期：
        <?= h($settings['kintone']['lastSyncAt']) ?>
    <?php endif; ?>
</div>

<form method="post">

<input type="hidden" name="action" value="save_kintone">

<div class="form-grid">

<div class="field">
<label>サブドメイン</label>

<input
    type="text"
    name="subdomain"
    value="<?= h($k['subdomain']) ?>"
    placeholder="xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com"
    required
>

<div class="help">
https://xxxx.cybozu.com、xxxx.cybozu.com、xxxx のいずれでも入力可能
</div>
</div>

<div class="field">
<label>顧客管理アプリID</label>

<input
    type="number"
    name="app_id"
    min="1"
    value="<?= h($k['app_id']) ?>"
    required
>
</div>

<div class="field">
<label>ログイン名</label>

<input
    type="text"
    name="username"
    value="<?= h($k['username']) ?>"
    required
>
</div>

<div class="field">
<label>パスワード</label>

<input
    type="password"
    name="password"
    value=""
    placeholder="<?= $k['has_password'] ? '変更しない場合は空欄' : '' ?>"
    autocomplete="new-password"
>
</div>

<div class="field">
<label>Proxy</label>

<input
    type="text"
    name="proxy"
    value="<?= h($k['proxy']) ?>"
    placeholder="host:port"
>

<div class="help">
未入力の場合はProxyを使用せず直接接続
</div>
</div>

<div class="field">
<label>SSL証明書検証</label>

<select name="verify_ssl">
    <option value="0" <?= !$k['verify_ssl'] ? 'selected' : '' ?>>
        無効（POC）
    </option>
    <option value="1" <?= $k['verify_ssl'] ? 'selected' : '' ?>>
        有効
    </option>
</select>
</div>

</div>

<button class="btn btn-primary" type="submit">
    設定保存
</button>

</form>

<hr style="margin:24px 0;border:0;border-top:1px solid var(--border)">

<div class="toolbar">

<form method="post">
    <input type="hidden" name="action" value="test_kintone">

    <button
        class="btn"
        type="submit"
        data-busy="true"
    >接続テスト</button>
</form>

<form method="post">
    <input type="hidden" name="action" value="fetch_kintone_fields">

    <button
        class="btn"
        type="submit"
        data-busy="true"
    >項目一覧を再取得</button>
</form>

<form method="post">
    <input type="hidden" name="action" value="sync_kintone">

    <button
        class="btn btn-primary"
        type="submit"
        data-busy="true"
    >顧客情報を同期</button>
</form>

</div>

</div>

<div class="card">

<h2>顧客項目マッピング</h2>

<form method="post">

<input type="hidden" name="action" value="save_kintone">

<input
    type="hidden"
    name="subdomain"
    value="<?= h($k['subdomain']) ?>"
>

<input
    type="hidden"
    name="app_id"
    value="<?= h($k['app_id']) ?>"
>

<input
    type="hidden"
    name="username"
    value="<?= h($k['username']) ?>"
>

<input
    type="hidden"
    name="password"
    value=""
>

<input
    type="hidden"
    name="proxy"
    value="<?= h($k['proxy']) ?>"
>

<input
    type="hidden"
    name="verify_ssl"
    value="<?= $k['verify_ssl'] ? '1' : '0' ?>"
>

<div class="form-grid-3">

<?php
$logicalFields = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
    'address' => '住所',
];
?>

<?php foreach ($logicalFields as $logical => $label): ?>

<div class="field">
<label><?= h($label) ?></label>

<select name="field_map[<?= h($logical) ?>]">
    <option value="">未設定</option>

<?php foreach ($k['fields'] as $fieldCode => $field): ?>

<?php
$fieldLabel = (string)($field['label'] ?? $fieldCode);
?>

<option
    value="<?= h($fieldCode) ?>"
    <?= (($k['field_map'][$logical] ?? '') === $fieldCode)
        ? 'selected'
        : '' ?>
>
<?= h($fieldLabel . ' [' . $fieldCode . ']') ?>
</option>

<?php endforeach; ?>

</select>
</div>

<?php endforeach; ?>

</div>

</form>

<?php if ($k['fields']): ?>

<h3>取得済み項目</h3>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>フィールドコード</th>
    <th>ラベル</th>
    <th>タイプ</th>
</tr>
</thead>

<tbody>

<?php foreach ($k['fields'] as $code => $field): ?>

<tr>
<td><?= h($code) ?></td>
<td><?= h($field['label'] ?? '') ?></td>
<td><?= h($field['type'] ?? '') ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>

</div>

<?php
/* ============================================================
 * MAIL
 * ============================================================
 */
elseif ($screen === 'mail'):

    $m = smtpSettingsForDisplay($settings);
?>

<div class="page-title">
    <h1>メールサーバ設定</h1>
</div>

<div class="card">

<div class="settings-status">
    接続状態：
    <strong><?= h($m['status']) ?></strong>
</div>

<form method="post">

<input type="hidden" name="action" value="save_mail">

<div class="form-grid">

<div class="field">
<label>SMTPサーバ</label>
<input
    type="text"
    name="server"
    value="<?= h($m['server']) ?>"
    required
>
</div>

<div class="field">
<label>SMTPポート</label>
<input
    type="number"
    name="port"
    min="1"
    max="65535"
    value="<?= h($m['port']) ?>"
    required
>
</div>

<div class="field">
<label>暗号化方式</label>
<select name="encryption">
    <option value="ssl" <?= $m['encryption'] === 'ssl' ? 'selected' : '' ?>>
        SSL
    </option>
    <option value="tls" <?= $m['encryption'] === 'tls' ? 'selected' : '' ?>>
        TLS
    </option>
    <option value="none" <?= $m['encryption'] === 'none' ? 'selected' : '' ?>>
        なし
    </option>
</select>
</div>

<div class="field">
<label>SMTP認証</label>
<select name="auth">
    <option value="1" <?= $m['auth'] ? 'selected' : '' ?>>
        使用する
    </option>
    <option value="0" <?= !$m['auth'] ? 'selected' : '' ?>>
        使用しない
    </option>
</select>
</div>

<div class="field">
<label>SMTPユーザー名</label>
<input
    type="text"
    name="username"
    value="<?= h($m['username']) ?>"
>
</div>

<div class="field">
<label>SMTPパスワード</label>
<input
    type="password"
    name="password"
    placeholder="<?= $m['has_password'] ? '変更しない場合は空欄' : '' ?>"
    autocomplete="new-password"
>
</div>

<div class="field">
<label>送信元メールアドレス</label>
<input
    type="email"
    name="from"
    value="<?= h($m['from']) ?>"
    required
>
</div>

<div class="field">
<label>送信元名</label>
<input
    type="text"
    name="from_name"
    value="<?= h($m['from_name']) ?>"
>
</div>

<div class="field">
<label>返信先メールアドレス</label>
<input
    type="email"
    name="reply_to"
    value="<?= h($m['reply_to']) ?>"
>
</div>

</div>

<button class="btn btn-primary" type="submit">
    設定保存
</button>

</form>

<hr style="margin:24px 0;border:0;border-top:1px solid var(--border)">

<div class="toolbar">

<form method="post">
    <input type="hidden" name="action" value="test_mail">

    <button
        class="btn"
        type="submit"
        data-busy="true"
    >接続テスト</button>
</form>

</div>

</div>

<div class="card">

<h2>テストメール送信</h2>

<form method="post">

<input type="hidden" name="action" value="send_test_mail">

<div class="field">
<label>テスト送信先</label>
<input
    type="email"
    name="test_to"
    required
>
</div>

<button
    class="btn btn-primary"
    type="submit"
    data-confirm="テストメールを送信しますか？"
>
    テストメール送信
</button>

</form>

</div>

<?php
/* ============================================================
 * SEND
 * ============================================================
 */
elseif ($screen === 'send'):

    if ($survey === null) {
        exit;
    }

    $surveyId = (string)$survey['id'];

    $customerSearch = isset($_GET['customer_q'])
        ? trim((string)$_GET['customer_q'])
        : '';

    $sendCustomers = [];

    foreach ($customers as $customer) {
        if (
            $customerSearch !== ''
            && mb_stripos(
                (string)($customer['name'] ?? ''),
                $customerSearch
            ) === false
            && mb_stripos(
                (string)($customer['organization'] ?? ''),
                $customerSearch
            ) === false
            && mb_stripos(
                (string)($customer['email'] ?? ''),
                $customerSearch
            ) === false
        ) {
            continue;
        }

        $sendCustomers[] = $customer;
    }

    $logs = surveySendLogs($surveyId);
?>

<div class="page-title">
    <div>
        <h1>顧客選択・メール送信</h1>
        <div class="help">
            対象アンケート：
            <strong><?= h($survey['title']) ?></strong>
        </div>
    </div>
</div>

<div class="tabs">
    <a class="active" href="#send-area">顧客選択・送信</a>
    <a href="#history-area">送信履歴</a>
</div>

<div id="send-area" class="card">

<div class="customer-grid">

<div>

<form method="get">
    <input type="hidden" name="screen" value="send">
    <input type="hidden" name="id" value="<?= h($surveyId) ?>">

    <div class="field">
        <label>顧客検索</label>
        <input
            type="text"
            name="customer_q"
            value="<?= h($customerSearch) ?>"
            placeholder="氏名・組織名・メール"
        >
    </div>

    <button class="btn" type="submit">検索</button>
</form>

<div class="customer-list" style="margin-top:15px">

<?php foreach ($sendCustomers as $customer): ?>

<label class="customer-item">

<input
    type="checkbox"
    form="sendForm"
    name="customer_ids[]"
    value="<?= h($customer['id']) ?>"
>

<div>
    <strong><?= h($customer['name'] ?? '') ?></strong>
    <div class="help">
        <?= h($customer['organization'] ?? '') ?>
    </div>
    <div class="help">
        <?= h($customer['email'] ?? '') ?>
    </div>
</div>

</label>

<?php endforeach; ?>

<?php if (!$sendCustomers): ?>
<div style="padding:15px">
    顧客がありません。
</div>
<?php endif; ?>

</div>

</div>

<div>

<form
    method="post"
    id="sendForm"
    onsubmit="return confirmSend(this)"
>

<input type="hidden" name="action" value="send_selected">
<input type="hidden" name="survey_id" value="<?= h($surveyId) ?>">

<div class="field">
<label>メール件名</label>
<input
    type="text"
    name="subject"
    value="<?= h($survey['title'] . ' ご回答のお願い') ?>"
    required
>
</div>

<div class="field">
<label>メール本文</label>

<textarea
    name="body"
    required
><?= h("{顧客名} 様\n\nアンケートへのご回答をお願いいたします。\n\n{アンケートURL}") ?></textarea>

<div class="help">
使用可能な変数：{顧客名}、{アンケートURL}
</div>
</div>

<button
    class="btn btn-primary"
    type="submit"
    data-busy="true"
>
    一括送信
</button>

</form>

</div>

</div>

</div>

<div id="history-area" class="card">

<h2>送信履歴</h2>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>日時</th>
    <th>顧客</th>
    <th>メール</th>
    <th>種別</th>
    <th>結果</th>
    <th>操作</th>
</tr>
</thead>

<tbody>

<?php if (!$logs): ?>

<tr>
    <td colspan="6">送信履歴はありません。</td>
</tr>

<?php endif; ?>

<?php foreach (array_reverse($logs) as $log): ?>

<tr>
<td><?= h($log['createdAt'] ?? '') ?></td>
<td><?= h($log['customerName'] ?? '') ?></td>
<td><?= h($log['email'] ?? '') ?></td>
<td><?= h($log['type'] ?? '') ?></td>
<td>
    <span class="badge badge-<?= ($log['status'] ?? '') === 'sent'
        ? 'success'
        : 'danger' ?>">
        <?= ($log['status'] ?? '') === 'sent'
            ? '送信成功'
            : '送信失敗' ?>
    </span>
</td>
<td>
<div class="actions">

<form method="post">
    <input type="hidden" name="action" value="resend">
    <input type="hidden" name="log_id" value="<?= h($log['id']) ?>">

    <button
        class="btn btn-small"
        type="submit"
        data-confirm="この顧客へ再送しますか？"
    >再送</button>
</form>

<form method="post">
    <input type="hidden" name="action" value="remind">
    <input type="hidden" name="log_id" value="<?= h($log['id']) ?>">

    <button
        class="btn btn-small"
        type="submit"
        data-confirm="この顧客へリマインドを送信しますか？"
    >リマインド</button>
</form>

</div>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

</div>

<?php
/* ============================================================
 * ANALYTICS
 * ============================================================
 */
elseif ($screen === 'analytics'):

    if ($survey === null) {
        exit;
    }

    $surveyId = (string)$survey['id'];
    $surveyAnswers = surveyAnswers($surveyId);
    $sentCount = sentCustomerCount($surveyId);
    $answerCount = count($surveyAnswers);
    $unanswered = max(0, $sentCount - $answerCount);
    $rate = $sentCount > 0
        ? round(($answerCount / $sentCount) * 100, 1)
        : 0;

    $stats = questionStats($survey, $surveyAnswers);
?>

<div class="page-title">
    <div>
        <h1>回答集計・分析</h1>
        <div class="help">
            対象アンケート：
            <strong><?= h($survey['title']) ?></strong>
        </div>
    </div>

    <div class="toolbar">
        <a
            class="btn"
            href="<?= h(currentIndexUrl([
                'screen' => 'analytics',
                'id' => $surveyId,
                'export' => 'csv'
            ])) ?>"
        >CSV</a>

        <a
            class="btn"
            href="<?= h(currentIndexUrl([
                'screen' => 'analytics',
                'id' => $surveyId,
                'export' => 'pdf'
            ])) ?>"
        >PDF</a>
    </div>
</div>

<div class="stat-grid">

<div class="stat">
    <div class="stat-label">送信対象者数</div>
    <div class="stat-value"><?= h($sentCount) ?></div>
</div>

<div class="stat">
    <div class="stat-label">回答数</div>
    <div class="stat-value"><?= h($answerCount) ?></div>
</div>

<div class="stat">
    <div class="stat-label">未登録回答数</div>
    <div class="stat-value">
        <?= h(count(array_filter(
            $surveyAnswers,
            static fn(array $a): bool => empty($a['registered'])
        ))) ?>
    </div>
</div>

<div class="stat">
    <div class="stat-label">未回答数 / 回答率</div>
    <div class="stat-value">
        <?= h($unanswered) ?>
        <small style="font-size:13px"><?= h($rate) ?>%</small>
    </div>
</div>

</div>

<?php if (!$surveyAnswers): ?>

<div class="card">
    <strong>現在、回答データはありません</strong>
</div>

<?php else: ?>

<?php foreach ($stats as $stat): ?>

<div class="card">

<h3>
    <?= h($stat['question']['number']) ?>
    <?= h($stat['question']['text']) ?>
</h3>

<?php if ($stat['question']['type'] === TYPE_TEXT): ?>

<p>回答件数：<?= h($stat['textCount']) ?></p>

<?php else: ?>

<?php foreach ($stat['counts'] as $count): ?>

<div style="margin:10px 0">

<div style="display:flex;justify-content:space-between">
    <span><?= h($count['label']) ?></span>
    <strong><?= h($count['count']) ?></strong>
</div>

<div
    style="
        height:8px;
        background:#e2e8f0;
        border-radius:999px;
        overflow:hidden;
    "
>
<div
    style="
        height:100%;
        width:<?= $answerCount > 0
            ? h(min(100, ($count['count'] / $answerCount) * 100))
            : 0 ?>%;
        background:#2563eb;
    "
></div>
</div>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<div class="card">

<h2>個別回答</h2>

<div class="table-wrap">

<table>
<thead>
<tr>
    <th>回答ID</th>
    <th>回答日時</th>

<?php foreach ($survey['groups'] as $group): ?>
<?php foreach ($group['questions'] as $question): ?>
    <th><?= h($question['number']) ?></th>
<?php endforeach; ?>
<?php endforeach; ?>

</tr>
</thead>

<tbody>

<?php foreach ($surveyAnswers as $answer): ?>

<tr>
<td><?= h($answer['id'] ?? '') ?></td>
<td><?= h($answer['createdAt'] ?? '') ?></td>

<?php foreach ($survey['groups'] as $group): ?>
<?php foreach ($group['questions'] as $question): ?>

<?php
$value = $answer['answers'][$question['id']] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach ($question['options'] as $option) {
        if (in_array(
            (string)$option['id'],
            array_map('strval', $value),
            true
        )) {
            $labels[] = $option['label'];
        }
    }

    $value = implode(', ', $labels);
} else {
    foreach ($question['options'] as $option) {
        if ((string)$option['id'] === (string)$value) {
            $value = $option['label'];
            break;
        }
    }
}
?>

<td><?= h($value) ?></td>

<?php endforeach; ?>
<?php endforeach; ?>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

</div>

<?php endif; ?>

<?php
/* ============================================================
 * ANSWER
 * ============================================================
 */
elseif ($screen === 'answer'):

    if ($survey === null) {
        exit;
    }

    $survey = normalizeSurvey($survey);

    if (($survey['status'] ?? '') !== STATUS_PUBLISHED) {
?>

<div class="answer-container">
<div class="card">
    <h1>回答できません</h1>
    <p>現在、このアンケートは回答受付中ではありません。</p>
</div>
</div>

<?php
    } else:

    $state = getAnswerState((string)$survey['id']);
    $currentAnswers = $state['answers'] ?? [];
?>

<div class="answer-container">

<div class="answer-header">
    <h1><?= h($survey['title']) ?></h1>
    <p><?= nl2br(h($survey['description'] ?? '')) ?></p>
</div>

<form method="post">

<input type="hidden" name="action" value="answer_next">
<input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div class="answer-question">

<h3>
    <?= h($question['number']) ?>
    <?= h($question['text']) ?>

<?php if (!empty($question['required'])): ?>
    <span class="badge badge-danger">必須</span>
<?php endif; ?>

</h3>

<?php if ($question['type'] === TYPE_TEXT): ?>

<textarea
    name="answers[<?= h($question['id']) ?>]"
    <?= !empty($question['required']) ? 'required' : '' ?>
><?= h($currentAnswers[$question['id']] ?? '') ?></textarea>

<?php elseif ($question['type'] === TYPE_SINGLE): ?>

<?php foreach ($question['options'] as $option): ?>

<label class="choice">

<input
    type="radio"
    name="answers[<?= h($question['id']) ?>]"
    value="<?= h($option['id']) ?>"
    <?= (string)($currentAnswers[$question['id']] ?? '')
        === (string)$option['id']
        ? 'checked'
        : '' ?>
    <?= !empty($question['required']) ? 'required' : '' ?>
>

<?= h($option['label']) ?>

</label>

<?php endforeach; ?>

<?php else: ?>

<?php
$currentMulti = is_array($currentAnswers[$question['id']] ?? null)
    ? $currentAnswers[$question['id']]
    : [];
?>

<?php foreach ($question['options'] as $option): ?>

<label class="choice">

<input
    type="checkbox"
    name="answers[<?= h($question['id']) ?>][]"
    value="<?= h($option['id']) ?>"
    <?= in_array(
        (string)$option['id'],
        array_map('strval', $currentMulti),
        true
    ) ? 'checked' : '' ?>
>

<?= h($option['label']) ?>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="answer-actions">

<div></div>

<button class="btn btn-primary" type="submit">
    次へ
</button>

</div>

</form>

</div>

<?php
    endif;

/* ============================================================
 * CONFIRM
 * ============================================================
 */
elseif ($screen === 'confirm'):

    if ($survey === null) {
        exit;
    }

    $state = getAnswerState((string)$survey['id']);
    $currentAnswers = $state['answers'] ?? [];
?>

<div class="answer-container">

<div class="page-title">
    <h1>回答確認</h1>
</div>

<div class="card">

<?php foreach ($survey['groups'] as $group): ?>

<h2><?= h($group['title']) ?></h2>

<?php foreach ($group['questions'] as $question): ?>

<div class="preview-question">

<strong>
    <?= h($question['number']) ?>
    <?= h($question['text']) ?>
</strong>

<div style="margin-top:8px">

<?php
$value = $currentAnswers[$question['id']] ?? '';

if (is_array($value)) {
    $labels = [];

    foreach ($question['options'] as $option) {
        if (in_array(
            (string)$option['id'],
            array_map('strval', $value),
            true
        )) {
            $labels[] = $option['label'];
        }
    }

    echo nl2br(h(implode(', ', $labels)));
} else {
    $label = $value;

    foreach ($question['options'] as $option) {
        if ((string)$option['id'] === (string)$value) {
            $label = $option['label'];
            break;
        }
    }

    echo nl2br(h((string)$label));
}
?>

</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="answer-actions">

<a
    class="btn"
    href="<?= h(currentIndexUrl([
        'screen' => 'answer',
        'id' => $survey['id']
    ])) ?>"
>戻って修正</a>

<form method="post">

<input type="hidden" name="action" value="answer_submit">
<input type="hidden" name="survey_id" value="<?= h($survey['id']) ?>">

<button
    class="btn btn-primary"
    type="submit"
    data-confirm="この回答を送信しますか？"
>
    回答を送信
</button>

</form>

</div>

</div>

</div>

<?php
/* ============================================================
 * COMPLETE
 * ============================================================
 */
elseif ($screen === 'complete'):

    if ($survey === null) {
        exit;
    }
?>

<div class="answer-container">

<div class="card complete">

<div style="font-size:48px;color:#16a34a">✓</div>

<h1>回答完了</h1>

<p>
アンケートへのご回答ありがとうございました。
</p>

</div>

</div>

<?php endif; ?>

</main>

<script>
(function(){

'use strict';

/* ------------------------------------------------------------
 * Common confirm
 * ------------------------------------------------------------ */

document.addEventListener('click', function(event){

    var target = event.target.closest('[data-confirm]');

    if (!target) {
        return;
    }

    var message = target.getAttribute('data-confirm');

    if (!window.confirm(message)) {
        event.preventDefault();
    }
});

/* ------------------------------------------------------------
 * Busy buttons
 * ------------------------------------------------------------ */

document.addEventListener('submit', function(event){

    var form = event.target;

    if (!form || !form.querySelector) {
        return;
    }

    var button = form.querySelector('[data-busy="true"]');

    if (!button) {
        return;
    }

    if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }

    form.dataset.submitting = '1';
    button.disabled = true;
    button.innerHTML =
        '<span class="spinner"></span>処理中...';
});

/* ------------------------------------------------------------
 * Editor
 * ------------------------------------------------------------ */

var groups = document.getElementById('groups');

if (groups) {

    var numberingInput =
        document.getElementById('numberingInput');

    function questionTypeDefaults(type) {

        if (type === 'text') {
            return [];
        }

        return [
            {
                id: makeId(),
                label: '選択肢1',
                nextQuestionId: ''
            },
            {
                id: makeId(),
                label: '選択肢2',
                nextQuestionId: ''
            }
        ];
    }

    function makeId() {
        return 'id-' +
            Date.now().toString(36) +
            '-' +
            Math.random().toString(36).slice(2, 9);
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function createOptionRow() {

        var row = document.createElement('div');
        row.className = 'option-row';

        row.innerHTML =
            '<span>○</span>' +
            '<input type="text" class="option-label" value="新しい選択肢">' +
            '<select class="option-next">' +
                '<option value="">次の質問へ</option>' +
            '</select>' +
            '<button type="button" class="btn btn-small delete-option">削除</button>';

        return row;
    }

    function createQuestion() {

        var card = document.createElement('div');

        card.className = 'question-card';
        card.draggable = true;
        card.dataset.questionId = makeId();

        card.innerHTML =
            '<div class="question-grid">' +

                '<div>' +
                    '<span class="drag-handle">↕</span> ' +
                    '<strong class="question-number"></strong>' +
                '</div>' +

                '<div>' +

                    '<input type="text" class="question-text" ' +
                    'placeholder="質問文">' +

                    '<div class="options"></div>' +

                    '<div class="toolbar" style="margin-top:10px">' +
                        '<button type="button" class="btn btn-small add-option">' +
                            '選択肢追加' +
                        '</button>' +
                        '<label style="font-weight:400">' +
                            '<input type="checkbox" class="question-required">' +
                            ' 必須' +
                        '</label>' +
                    '</div>' +

                '</div>' +

                '<div>' +

                    '<select class="question-type">' +
                        '<option value="single">単一選択</option>' +
                        '<option value="multiple">複数選択</option>' +
                        '<option value="text">自由記述</option>' +
                    '</select>' +

                    '<button type="button" ' +
                        'class="btn btn-small btn-danger delete-question" ' +
                        'style="margin-top:7px;width:100%">' +
                        '質問削除' +
                    '</button>' +

                '</div>' +

            '</div>';

        var options =
            card.querySelector('.options');

        var defaults = questionTypeDefaults('single');

        defaults.forEach(function(option){
            var row = createOptionRow();

            row.querySelector('.option-label').value =
                option.label;

            options.appendChild(row);
        });

        return card;
    }

    function createGroup() {

        var card = document.createElement('div');

        card.className = 'group-card';
        card.draggable = true;
        card.dataset.groupId = makeId();

        card.innerHTML =
            '<div class="group-head">' +
                '<span class="drag-handle">☰</span>' +
                '<input type="text" class="group-title" ' +
                    'value="新しいグループ">' +
                '<button type="button" ' +
                    'class="btn btn-small btn-danger delete-group">' +
                    'グループ削除' +
                '</button>' +
            '</div>' +

            '<div class="question-list"></div>' +

            '<div style="padding:12px">' +
                '<button type="button" class="btn add-question">' +
                    '＋ 質問を追加' +
                '</button>' +
            '</div>';

        return card;
    }

    function renumber() {

        var globalNo = 1;

        Array.from(
            groups.querySelectorAll(':scope > .group-card')
        ).forEach(function(group, gi){

            var groupNo = 1;

            Array.from(
                group.querySelectorAll(
                    ':scope .question-list > .question-card'
                )
            ).forEach(function(question){

                var number =
                    numberingInput &&
                    numberingInput.value === 'group'
                        ? 'Q' + (gi + 1) + '-' + groupNo
                        : 'Q' + globalNo;

                var numberNode =
                    question.querySelector('.question-number');

                if (numberNode) {
                    numberNode.textContent = number;
                }

                question.dataset.number = number;

                globalNo++;
                groupNo++;
            });
        });

        rebuildNextQuestionOptions();
    }

    function rebuildNextQuestionOptions() {

        var questions = Array.from(
            groups.querySelectorAll('.question-card')
        );

        questions.forEach(function(question){

            var select =
                question.querySelector('.question-type');

            if (!select || select.value !== 'single') {
                return;
            }

            question
                .querySelectorAll('.option-next')
                .forEach(function(next){

                    var current = next.value;

                    next.innerHTML =
                        '<option value="">次の質問へ</option>';

                    questions.forEach(function(target){

                        if (target === question) {
                            return;
                        }

                        var id = target.dataset.questionId;
                        var number = target.dataset.number || '';
                        var text =
                            target.querySelector('.question-text');

                        var label =
                            number + ' ' +
                            (text ? text.value : '');

                        var option =
                            document.createElement('option');

                        option.value = id;
                        option.textContent = label;

                        if (id === current) {
                            option.selected = true;
                        }

                        next.appendChild(option);
                    });
                });
        });
    }

    function structureJson() {

        var data = {
            numbering:
                numberingInput
                ? numberingInput.value
                : 'global',
            groups: []
        };

        Array.from(
            groups.querySelectorAll(':scope > .group-card')
        ).forEach(function(group){

            var groupData = {
                id: group.dataset.groupId || makeId(),
                title:
                    group.querySelector('.group-title').value,
                questions: []
            };

            Array.from(
                group.querySelectorAll(
                    ':scope .question-list > .question-card'
                )
            ).forEach(function(question){

                var type =
                    question.querySelector('.question-type').value;

                var q = {
                    id: question.dataset.questionId || makeId(),
                    number: question.dataset.number || '',
                    text:
                        question.querySelector('.question-text').value,
                    type: type,
                    required:
                        question.querySelector(
                            '.question-required'
                        ).checked,
                    options: []
                };

                question
                    .querySelectorAll('.option-row')
                    .forEach(function(row){

                        q.options.push({
                            id: row.dataset.optionId || makeId(),
                            label:
                                row.querySelector(
                                    '.option-label'
                                ).value,
                            nextQuestionId:
                                row.querySelector(
                                    '.option-next'
                                ).value
                        });
                    });

                groupData.questions.push(q);
            });

            data.groups.push(groupData);
        });

        return JSON.stringify(data);
    }

    function saveStructure() {

        var formData = new FormData();

        formData.append('action', 'save_structure');

        var id = <?= json_encode(
            $screen === 'edit' && $survey
                ? (string)$survey['id']
                : ''
        ) ?>;

        formData.append('id', id);
        formData.append('structure', structureJson());

        fetch(
            <?= json_encode(currentIndexUrl(['screen' => 'edit'])) ?>,
            {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            }
        )
        .then(function(response){

            if (!response.ok) {
                throw new Error('保存に失敗しました。');
            }

            return response.json();
        })
        .then(function(){

            var form =
                document.getElementById('surveyForm');

            if (!form) {
                return;
            }

            form.submit();
        })
        .catch(function(error){

            alert(
                error.message ||
                '保存に失敗しました。'
            );
        });
    }

    var saveButton =
        document.getElementById('saveStructureButton');

    if (saveButton) {
        saveButton.addEventListener(
            'click',
            saveStructure
        );
    }

    document.addEventListener('change', function(event){

        if (event.target.matches(
            'input[name="numbering_choice"]'
        )) {

            if (numberingInput) {
                numberingInput.value =
                    event.target.value;
            }

            renumber();
        }

        if (event.target.matches('.question-type')) {

            var question =
                event.target.closest('.question-card');

            var options =
                question.querySelector('.options');

            if (event.target.value === 'text') {
                options.innerHTML = '';
            } else if (!options.children.length) {

                questionTypeDefaults(
                    event.target.value
                ).forEach(function(option){

                    var row = createOptionRow();

                    row.querySelector(
                        '.option-label'
                    ).value = option.label;

                    options.appendChild(row);
                });
            }

            renumber();
        }

        if (event.target.matches('.question-text')) {
            renumber();
        }
    });

    document.addEventListener('click', function(event){

        if (event.target.matches('.add-question')) {

            var group =
                event.target.closest('.group-card');

            var list =
                group.querySelector('.question-list');

            list.appendChild(createQuestion());

            renumber();
        }

        if (event.target.matches('.add-option')) {

            var question =
                event.target.closest('.question-card');

            var options =
                question.querySelector('.options');

            options.appendChild(createOptionRow());

            renumber();
        }

        if (event.target.matches('.delete-option')) {

            var row =
                event.target.closest('.option-row');

            if (row) {
                row.remove();
                renumber();
            }
        }

        if (event.target.matches('.delete-question')) {

            if (!window.confirm(
                'この質問を削除しますか？'
            )) {
                return;
            }

            var question =
                event.target.closest('.question-card');

            if (question) {
                question.remove();
                renumber();
            }
        }

        if (event.target.matches('.delete-group')) {

            if (!window.confirm(
                'このグループを削除しますか？'
            )) {
                return;
            }

            var group =
                event.target.closest('.group-card');

            if (group) {
                group.remove();
                renumber();
            }
        }

        if (event.target.id === 'addGroup') {

            groups.appendChild(createGroup());

            renumber();
        }
    });

    /* Drag and Drop */

    var dragElement = null;

    document.addEventListener('dragstart', function(event){

        var question =
            event.target.closest('.question-card');

        var group =
            event.target.closest('.group-card');

        if (question) {
            dragElement = question;
            question.classList.add('dragging');
            return;
        }

        if (group && group.parentElement === groups) {
            dragElement = group;
            group.classList.add('dragging');
        }
    });

    document.addEventListener('dragend', function(event){

        var target =
            event.target.closest('.question-card,.group-card');

        if (target) {
            target.classList.remove('dragging');
        }

        dragElement = null;

        renumber();
    });

    document.addEventListener('dragover', function(event){

        if (!dragElement) {
            return;
        }

        event.preventDefault();

        var questionTarget =
            event.target.closest('.question-card');

        if (
            dragElement.classList.contains('question-card')
            && questionTarget
            && questionTarget !== dragElement
        ) {

            var list =
                questionTarget.closest('.question-list');

            if (list) {

                var rect =
                    questionTarget.getBoundingClientRect();

                var before =
                    event.clientY < rect.top + rect.height / 2;

                list.insertBefore(
                    dragElement,
                    before
                        ? questionTarget
                        : questionTarget.nextSibling
                );
            }

            return;
        }

        var groupTarget =
            event.target.closest('.group-card');

        if (
            dragElement.classList.contains('group-card')
            && groupTarget
            && groupTarget !== dragElement
            && groupTarget.parentElement === groups
        ) {

            var rect =
                groupTarget.getBoundingClientRect();

            var before =
                event.clientY < rect.top + rect.height / 2;

            groups.insertBefore(
                dragElement,
                before
                    ? groupTarget
                    : groupTarget.nextSibling
            );
        }
    });

    renumber();
}

/* ------------------------------------------------------------
 * Send confirmation
 * ------------------------------------------------------------ */

window.confirmSend = function(form) {

    var checked =
        form.querySelectorAll(
            'input[name="customer_ids[]"]:checked'
        );

    if (!checked.length) {
        alert('送信対象顧客を選択してください。');
        return false;
    }

    return window.confirm(
        checked.length +
        '件の顧客へメールを送信します。よろしいですか？'
    );
};

})();
</script>

</body>
</html>
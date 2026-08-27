<?php
declare(strict_types=1);

/**
 * アンケートアプリ
 *
 * 実行環境:
 *   Apache 2.4
 *   PHP 8.5
 *   DBなし
 *   PHP cURLなし
 *
 * 単一エントリーポイント:
 *   index.php
 *
 * 永続データ:
 *   Web公開ディレクトリの外側を優先
 *
 * 必要な環境変数:
 *   SURVEY_DATA_DIR
 *   SURVEY_ADMIN_USER
 *   SURVEY_ADMIN_PASSWORD
 *
 * kintone:
 *   KINTONE_SUBDOMAIN
 *   KINTONE_APP_ID
 *   KINTONE_USERNAME
 *   KINTONE_PASSWORD
 *   KINTONE_PROXY
 *   KINTONE_VERIFY_SSL
 *
 * SMTP:
 *   SMTP_HOST
 *   SMTP_PORT
 *   SMTP_ENCRYPTION      ssl / tls / none
 *   SMTP_AUTH            1 / 0
 *   SMTP_USERNAME
 *   SMTP_PASSWORD
 *   SMTP_FROM
 *   SMTP_FROM_NAME
 *   SMTP_REPLY_TO
 */

const APP_NAME = 'アンケート管理システム';
const DATA_DIR_ENV = 'SURVEY_DATA_DIR';

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Tokyo');

session_name('survey_admin');

/*
 * HTTPS判定はリバースプロキシ構成でもループしないよう、
 * X-Forwarded-Proto を参照するが、無条件リダイレクトは行わない。
 */
$secureCookie = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

/* =========================================================
 * 基本ユーティリティ
 * ======================================================= */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function baseUrl(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    return $script;
}

function url(array $params = []): string
{
    return baseUrl() . ($params ? '?' . http_build_query($params) : '');
}

function redirect(array $params = []): never
{
    $target = url($params);

    /*
     * 自己リダイレクトを防止。
     */
    $currentPath = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $currentQuery = $_SERVER['QUERY_STRING'] ?? '';
    $current = $currentPath . ($currentQuery !== '' ? '?' . $currentQuery : '');

    if ($target === $current) {
        returnToList();
    }

    header('Location: ' . $target, true, 303);
    exit;
}

function returnToList(): never
{
    $target = url(['screen' => 'list']);
    $current = ($_SERVER['SCRIPT_NAME'] ?? '/index.php')
        . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');

    if ($target === $current) {
        http_response_code(400);
        echo 'Invalid navigation.';
        exit;
    }

    header('Location: ' . $target, true, 303);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = $_POST['_csrf'] ?? '';

    if (
        !is_string($sessionToken)
        || !is_string($postedToken)
        || $sessionToken === ''
        || $postedToken === ''
        || !hash_equals($sessionToken, $postedToken)
    ) {
        http_response_code(403);
        renderErrorPage('不正なリクエストです。ページを再読み込みして、もう一度お試しください。');
        exit;
    }
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">';
}

function adminPasswordConfigured(): bool
{
    return envValue('SURVEY_ADMIN_PASSWORD') !== '';
}

function isAdmin(): bool
{
    return !empty($_SESSION['admin_authenticated'])
        && $_SESSION['admin_authenticated'] === true;
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        /*
         * login自身にはrequireAdminを呼ばない。
         * 認証状態による相互リダイレクトを防止。
         */
        redirect(['screen' => 'login']);
    }
}

function requireAnswerSurvey(array $survey): void
{
    if ($survey['status'] !== 'published') {
        renderErrorPage('このアンケートは現在回答できません。');
        exit;
    }

    if (!empty($survey['endAt']) && strtotime($survey['endAt']) < time()) {
        renderErrorPage('このアンケートの回答期間は終了しています。');
        exit;
    }
}

function generateId(string $prefix): string
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
}

/* =========================================================
 * データ保存
 * ======================================================= */

function dataDir(): string
{
    $configured = envValue(DATA_DIR_ENV);

    if ($configured !== '') {
        return rtrim($configured, DIRECTORY_SEPARATOR);
    }

    /*
     * index.phpの親ディレクトリを標準保存先とする。
     * 例:
     * /var/www/html/index.php
     * /var/www/survey-data/
     */
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'survey-data';
}

function ensureDataDir(): void
{
    $dir = dataDir();

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('データ保存ディレクトリを作成できません。');
        }
    }

    @chmod($dir, 0700);
}

function dataFile(string $name): string
{
    ensureDataDir();
    return dataDir() . DIRECTORY_SEPARATOR . $name . '.json';
}

function readJson(string $name, mixed $default): mixed
{
    $file = dataFile($name);

    if (!is_file($file)) {
        return $default;
    }

    $raw = file_get_contents($file);

    if ($raw === false || $raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $decoded
        : $default;
}

function writeJson(string $name, mixed $data): void
{
    $file = dataFile($name);
    $tmp = $file . '.' . bin2hex(random_bytes(5)) . '.tmp';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('データのJSON化に失敗しました。');
    }

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('一時ファイルへの保存に失敗しました。');
    }

    @chmod($tmp, 0600);

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('データ保存に失敗しました。');
    }

    @chmod($file, 0600);
}

function surveys(): array
{
    $data = readJson('surveys', []);

    return is_array($data) ? array_values($data) : [];
}

function saveSurveys(array $surveys): void
{
    writeJson('surveys', array_values($surveys));
}

function responses(): array
{
    $data = readJson('responses', []);

    return is_array($data) ? array_values($data) : [];
}

function saveResponses(array $responses): void
{
    writeJson('responses', array_values($responses));
}

function customers(): array
{
    $data = readJson('customers', []);

    return is_array($data) ? array_values($data) : [];
}

function saveCustomers(array $customers): void
{
    writeJson('customers', array_values($customers));
}

function sendLogs(): array
{
    $data = readJson('send_logs', []);

    return is_array($data) ? array_values($data) : [];
}

function saveSendLogs(array $logs): void
{
    writeJson('send_logs', array_values($logs));
}

function appSettings(): array
{
    $default = [
        'kintone' => [
            'subdomain' => '',
            'appId' => '',
            'username' => '',
            'password' => '',
            'proxy' => '',
            'verifySsl' => true,
            'fieldMapping' => [
                'organization' => '',
                'name' => '',
                'email' => '',
                'department' => '',
                'phone' => '',
                'address' => [],
            ],
        ],
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from' => '',
            'fromName' => '',
            'replyTo' => '',
        ],
    ];

    $saved = readJson('settings', []);

    return array_replace_recursive($default, is_array($saved) ? $saved : []);
}

function saveAppSettings(array $settings): void
{
    writeJson('settings', $settings);
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

function replaceSurvey(array $updated): void
{
    $all = surveys();

    foreach ($all as $index => $survey) {
        if (($survey['id'] ?? '') === $updated['id']) {
            $all[$index] = $updated;
            saveSurveys($all);
            return;
        }
    }

    throw new RuntimeException('アンケートが見つかりません。');
}

function deleteSurvey(string $id): void
{
    $all = array_values(array_filter(
        surveys(),
        static fn(array $survey): bool => ($survey['id'] ?? '') !== $id
    ));

    saveSurveys($all);
}

/* =========================================================
 * アンケート構造
 * ======================================================= */

function emptyQuestion(): array
{
    return [
        'id' => generateId('question'),
        'text' => '',
        'type' => 'single',
        'required' => false,
        'options' => ['選択肢1', '選択肢2'],
        'branching' => [],
        'number' => '',
    ];
}

function emptyGroup(): array
{
    return [
        'id' => generateId('group'),
        'title' => '新しいグループ',
        'questions' => [
            emptyQuestion(),
        ],
    ];
}

function emptySurvey(): array
{
    return [
        'id' => generateId('survey'),
        'title' => '',
        'description' => '',
        'startAt' => date('Y-m-d H:i'),
        'endAt' => date('Y-m-d H:i', strtotime('+30 days')),
        'numbering' => 'global',
        'status' => 'draft',
        'createdAt' => now(),
        'updatedAt' => now(),
        'groups' => [
            [
                'id' => generateId('group'),
                'title' => '基本情報',
                'questions' => [
                    emptyQuestion(),
                ],
            ],
        ],
    ];
}

function recalculateNumbers(array &$survey): void
{
    $counter = 1;
    $groupCounter = 1;

    foreach ($survey['groups'] as &$group) {
        $questionCounter = 1;

        foreach ($group['questions'] as &$question) {
            if ($survey['numbering'] === 'group') {
                $question['number'] = 'Q'
                    . $groupCounter
                    . '-'
                    . $questionCounter;
            } else {
                $question['number'] = 'Q' . $counter;
            }

            $counter++;
            $questionCounter++;
        }

        $groupCounter++;
    }

    unset($group, $question);
}

function updateAutomaticStatus(array &$survey): bool
{
    if (
        ($survey['status'] ?? '') === 'published'
        && !empty($survey['endAt'])
        && strtotime((string)$survey['endAt']) < time()
    ) {
        $survey['status'] = 'ended';
        $survey['updatedAt'] = now();
        return true;
    }

    return false;
}

function refreshAllStatuses(): void
{
    $all = surveys();
    $changed = false;

    foreach ($all as &$survey) {
        if (updateAutomaticStatus($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        saveSurveys($all);
    }
}

function validQuestionType(string $type): bool
{
    return in_array($type, ['single', 'multiple', 'text'], true);
}

/* =========================================================
 * ログイン処理
 * ======================================================= */

function handleLogin(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    verifyCsrf();

    $user = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $configuredUser = envValue('SURVEY_ADMIN_USER', 'admin');
    $configuredPassword = envValue('SURVEY_ADMIN_PASSWORD');

    if ($configuredPassword === '') {
        $_SESSION['flash_error'] =
            'SURVEY_ADMIN_PASSWORD が設定されていません。管理者認証を設定してください。';
        return;
    }

    if (
        hash_equals($configuredUser, $user)
        && hash_equals($configuredPassword, $password)
    ) {
        /*
         * セッション固定攻撃対策。
         */
        session_regenerate_id(true);

        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_user'] = $configuredUser;

        /*
         * CSRFトークンもログイン境界で再生成。
         */
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        redirect(['screen' => 'list']);
    }

    $_SESSION['flash_error'] = 'ユーザー名またはパスワードが正しくありません。';
}

/* =========================================================
 * kintone HTTP
 *
 * PHP cURLは使用しない。
 * stream_context_create + fopenを使用する。
 * ======================================================= */

function validateProxy(string $proxy): bool
{
    if ($proxy === '') {
        return true;
    }

    if (preg_match('/[\r\n\s]/', $proxy)) {
        return false;
    }

    if (!preg_match(
        '/^(?:(?:[a-zA-Z0-9.-]+)|(?:\[[0-9a-fA-F:]+\])):[0-9]{1,5}$/',
        $proxy
    )) {
        return false;
    }

    [$host, $port] = strrpos($proxy, ':') !== false
        ? [
            substr($proxy, 0, strrpos($proxy, ':')),
            substr($proxy, strrpos($proxy, ':') + 1)
        ]
        : ['', ''];

    if ($host === '' || !ctype_digit($port)) {
        return false;
    }

    $portNumber = (int)$port;

    return $portNumber >= 1 && $portNumber <= 65535;
}

function kintoneSettings(): array
{
    return appSettings()['kintone'];
}

function kintoneAuthorization(array $settings): string
{
    $raw = (string)$settings['username']
        . ':'
        . (string)$settings['password'];

    /*
     * X-Cybozu-Authorization は Basic認証値をBase64化したもの。
     * この値はブラウザには渡さない。
     */
    return base64_encode($raw);
}

function kintoneRequest(
    string $method,
    string $path,
    ?array $body = null
): array {
    $settings = kintoneSettings();

    $subdomain = trim((string)$settings['subdomain']);
    $appId = trim((string)$settings['appId']);

    if ($subdomain === '') {
        throw new RuntimeException('kintoneサブドメインが設定されていません。');
    }

    if ($appId === '' || !ctype_digit($appId)) {
        throw new RuntimeException('kintoneアプリIDが正しく設定されていません。');
    }

    if (
        trim((string)$settings['username']) === ''
        || (string)$settings['password'] === ''
    ) {
        throw new RuntimeException('kintoneログイン情報が設定されていません。');
    }

    $base = 'https://' . $subdomain . '.cybozu.com';
    $url = $base . $path;

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' . kintoneAuthorization($settings),
    ];

    $content = null;

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            throw new RuntimeException('kintoneリクエストの生成に失敗しました。');
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $verifySsl = (bool)$settings['verifySsl'];

    $ssl = [
        'verify_peer' => $verifySsl,
        'verify_peer_name' => $verifySsl,
        'allow_self_signed' => !$verifySsl,
        'SNI_enabled' => true,
        'peer_name' => $subdomain . '.cybozu.com',
    ];

    $http = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'content' => $content ?? '',
        'timeout' => 20,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
    ];

    $proxy = trim((string)$settings['proxy']);

    if ($proxy !== '') {
        if (!validateProxy($proxy)) {
            throw new RuntimeException(
                'Proxyは「host:port」形式で指定してください。'
            );
        }

        $http['proxy'] = 'tcp://' . $proxy;
        $http['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $http,
        'ssl' => $ssl,
    ]);

    $error = null;

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$error): bool {
            $error = $message;
            return true;
        }
    );

    try {
        $response = file_get_contents($url, false, $context);
    } finally {
        restore_error_handler();
    }

    if ($response === false) {
        throw new RuntimeException(
            'kintoneへの接続に失敗しました。'
            . ($error ? ' ' . $error : '')
        );
    }

    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) {
            $status = (int)$m[1];
            break;
        }
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'kintoneから不正なレスポンスが返されました。'
        );
    }

    if ($status < 200 || $status >= 300) {
        /*
         * 認証情報やAuthorizationヘッダーは絶対に表示しない。
         */
        $message = isset($decoded['message'])
            ? (string)$decoded['message']
            : 'kintone APIエラー';

        throw new RuntimeException(
            'kintone APIエラー (' . $status . '): ' . $message
        );
    }

    return $decoded;
}

function kintoneTestConnection(): array
{
    $settings = kintoneSettings();

    $appId = (string)$settings['appId'];

    return kintoneRequest(
        'GET',
        '/k/v1/app.json?id=' . rawurlencode($appId)
    );
}

function kintoneFields(): array
{
    $settings = kintoneSettings();
    $appId = (string)$settings['appId'];

    $result = kintoneRequest(
        'GET',
        '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
    );

    return $result['properties'] ?? [];
}

function kintoneRecords(): array
{
    $settings = kintoneSettings();
    $appId = (string)$settings['appId'];

    $records = [];
    $offset = 0;

    while (true) {
        $query = 'limit 500 offset ' . $offset;

        $result = kintoneRequest(
            'GET',
            '/k/v1/records.json?app='
            . rawurlencode($appId)
            . '&query='
            . rawurlencode($query)
        );

        $batch = $result['records'] ?? [];

        if (!is_array($batch)) {
            break;
        }

        $records = array_merge($records, $batch);

        if (count($batch) < 500) {
            break;
        }

        $offset += 500;

        if ($offset >= 10000) {
            break;
        }
    }

    return $records;
}

function kintoneFieldValue(array $record, string $code): string
{
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                if (isset($item['name'])) {
                    $parts[] = (string)$item['name'];
                } elseif (isset($item['code'])) {
                    $parts[] = (string)$item['code'];
                } elseif (isset($item['value'])) {
                    $parts[] = (string)$item['value'];
                }
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(', ', $parts);
    }

    return (string)$value;
}

function normalizeKintoneCustomers(array $records): array
{
    $settings = kintoneSettings();
    $mapping = $settings['fieldMapping'] ?? [];

    $result = [];

    foreach ($records as $record) {
        $addressCodes = $mapping['address'] ?? [];

        if (!is_array($addressCodes)) {
            $addressCodes = [];
        }

        $address = [];

        foreach ($addressCodes as $code) {
            $value = kintoneFieldValue($record, (string)$code);

            if ($value !== '') {
                $address[] = $value;
            }
        }

        $result[] = [
            'id' => kintoneFieldValue($record, '$id')
                ?: generateId('customer'),
            'organization' => kintoneFieldValue(
                $record,
                (string)($mapping['organization'] ?? '')
            ),
            'name' => kintoneFieldValue(
                $record,
                (string)($mapping['name'] ?? '')
            ),
            'email' => kintoneFieldValue(
                $record,
                (string)($mapping['email'] ?? '')
            ),
            'department' => kintoneFieldValue(
                $record,
                (string)($mapping['department'] ?? '')
            ),
            'phone' => kintoneFieldValue(
                $record,
                (string)($mapping['phone'] ?? '')
            ),
            'address' => implode(' ', $address),
            'syncedAt' => now(),
        ];
    }

    return $result;
}

/* =========================================================
 * SMTP
 *
 * PHP mail() は使用しない。
 * fsockopenによるSMTP通信。
 * ======================================================= */

function smtpRead($socket): string
{
    $response = '';

    while (($line = fgets($socket, 8192)) !== false) {
        $response .= $line;

        if (strlen($line) < 4) {
            break;
        }

        if ($line[3] === ' ') {
            break;
        }
    }

    return $response;
}

function smtpExpect($socket, array $codes): string
{
    $response = smtpRead($socket);

    if ($response === '') {
        throw new RuntimeException('SMTPサーバーから応答がありません。');
    }

    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTPサーバーとの通信に失敗しました。'
        );
    }

    return $response;
}

function smtpCommand($socket, string $command, array $codes): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('SMTPコマンド送信に失敗しました。');
    }

    return smtpExpect($socket, $codes);
}

function smtpConnect(array $settings)
{
    $host = trim((string)$settings['host']);
    $port = (int)$settings['port'];
    $encryption = strtolower((string)$settings['encryption']);

    if ($host === '') {
        throw new RuntimeException('SMTPサーバが設定されていません。');
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('SMTPポートが正しくありません。');
    }

    $transport = $encryption === 'ssl'
        ? 'ssl://'
        : 'tcp://';

    $errno = 0;
    $errstr = '';

    $socket = fsockopen(
        $transport . $host,
        $port,
        $errno,
        $errstr,
        15
    );

    if (!is_resource($socket)) {
        throw new RuntimeException(
            'SMTPサーバへ接続できませんでした。'
        );
    }

    stream_set_timeout($socket, 15);

    smtpExpect($socket, [220]);

    $localHost = $_SERVER['SERVER_NAME'] ?? 'localhost';

    smtpCommand(
        $socket,
        'EHLO ' . preg_replace('/[^a-zA-Z0-9.\-]/', '', $localHost),
        [250]
    );

    if ($encryption === 'tls') {
        smtpCommand($socket, 'STARTTLS', [220]);

        $crypto = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);
            throw new RuntimeException('SMTP TLS接続に失敗しました。');
        }

        smtpCommand(
            $socket,
            'EHLO ' . preg_replace('/[^a-zA-Z0-9.\-]/', '', $localHost),
            [250]
        );
    }

    if (!empty($settings['auth'])) {
        $username = (string)$settings['username'];
        $password = (string)$settings['password'];

        if ($username === '' || $password === '') {
            fclose($socket);
            throw new RuntimeException(
                'SMTP認証情報が設定されていません。'
            );
        }

        $auth = smtpCommand(
            $socket,
            'AUTH LOGIN',
            [334, 235]
        );

        if (str_starts_with($auth, '334')) {
            smtpCommand(
                $socket,
                base64_encode($username),
                [334]
            );

            smtpCommand(
                $socket,
                base64_encode($password),
                [235]
            );
        }
    }

    return $socket;
}

function smtpSend(
    array $settings,
    string $to,
    string $subject,
    string $body
): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('メールアドレスが不正です。');
    }

    $socket = smtpConnect($settings);

    try {
        $from = trim((string)$settings['from']);
        $replyTo = trim((string)$settings['replyTo']);
        $fromName = trim((string)$settings['fromName']);

        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                '送信元メールアドレスが不正です。'
            );
        }

        smtpCommand(
            $socket,
            'MAIL FROM:<' . $from . '>',
            [250]
        );

        smtpCommand(
            $socket,
            'RCPT TO:<' . $to . '>',
            [250, 251]
        );

        smtpCommand($socket, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?'
            . base64_encode($subject)
            . '?=';

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . (
                $fromName !== ''
                    ? '=?UTF-8?B?' . base64_encode($fromName)
                        . '?= <' . $from . '>'
                    : $from
            ),
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if ($replyTo !== '') {
            if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    '返信先メールアドレスが不正です。'
                );
            }

            $headers[] = 'Reply-To: ' . $replyTo;
        }

        /*
         * SMTP dot-stuffing
         */
        $body = preg_replace(
            '/^\./m',
            '..',
            str_replace(["\r\n", "\r"], "\n", $body)
        );

        $message =
            implode("\r\n", $headers)
            . "\r\n\r\n"
            . str_replace("\n", "\r\n", $body)
            . "\r\n.";

        smtpCommand($socket, $message, [250]);

        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtpTest(): void
{
    $settings = appSettings()['smtp'];
    $socket = smtpConnect($settings);

    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
}

/* =========================================================
 * PDF
 *
 * 外部ライブラリ不要。
 * PDF標準のHeiseiKakuGo-W5 CIDフォントを利用する。
 * ======================================================= */

function pdfEscape(string $text): string
{
    $text = mb_convert_encoding($text, 'SJIS-win', 'UTF-8');

    return str_replace(
        ['\\', '(', ')', "\r", "\n"],
        ['\\\\', '\\(', '\\)', '', ''],
        $text
    );
}

function createSimplePdf(string $title, array $lines): string
{
    $objects = [];

    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $objects[] =
        '<< /Type /Page'
        . ' /Parent 2 0 R'
        . ' /MediaBox [0 0 595 842]'
        . ' /Resources <<'
        . ' /Font << /F1 5 0 R >>'
        . ' >>'
        . ' /Contents 4 0 R'
        . ' >>';

    $content = "BT\n";
    $content .= "/F1 16 Tf\n";
    $content .= "50 790 Td\n";
    $content .= "(" . pdfEscape($title) . ") Tj\n";
    $content .= "/F1 10 Tf\n";
    $content .= "0 -28 Td\n";

    foreach ($lines as $line) {
        $chunks = preg_split('/\R/u', (string)$line);

        foreach ($chunks as $chunk) {
            $content .= "(" . pdfEscape($chunk) . ") Tj\n";
            $content .= "0 -16 Td\n";
        }
    }

    $content .= "ET\n";

    $objects[] =
        '<< /Length ' . strlen($content) . " >>\n"
        . "stream\n"
        . $content
        . "endstream";

    /*
     * Japanese CID font:
     * Acrobat等のPDFビューアで標準的に利用できる
     * Adobe-Japan1系のフォント定義。
     */
    $objects[] =
        '<<'
        . ' /Type /Font'
        . ' /Subtype /Type0'
        . ' /BaseFont /HeiseiKakuGo-W5'
        . ' /Encoding /90ms-RKSJ-H'
        . ' /DescendantFonts [6 0 R]'
        . ' >>';

    $objects[] =
        '<<'
        . ' /Type /Font'
        . ' /Subtype /CIDFontType0'
        . ' /BaseFont /HeiseiKakuGo-W5'
        . ' /CIDSystemInfo <<'
        . ' /Registry (Adobe)'
        . ' /Ordering (Japan1)'
        . ' /Supplement 2'
        . ' >>'
        . ' /DW 1000'
        . ' >>';

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];

    foreach ($objects as $index => $object) {
        $objectNumber = $index + 1;

        $offsets[$objectNumber] = strlen($pdf);

        $pdf .= $objectNumber . " 0 obj\n";
        $pdf .= $object . "\n";
        $pdf .= "endobj\n";
    }

    $xrefOffset = strlen($pdf);
    $count = count($objects) + 1;

    $pdf .= "xref\n";
    $pdf .= "0 " . $count . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf(
            "%010d 00000 n \n",
            $offsets[$i]
        );
    }

    $pdf .= "trailer\n";
    $pdf .= "<< /Size " . $count . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xrefOffset . "\n";
    $pdf .= "%%EOF\n";

    return $pdf;
}

/* =========================================================
 * フラッシュメッセージ
 * ======================================================= */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][$type] = $message;
}

function consumeFlash(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : [];
}

/* =========================================================
 * 共通HTML
 * ======================================================= */

function renderHeader(
    string $title,
    bool $admin = true
): void {
    $flash = consumeFlash();
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_NAME) ?></title>

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

body{
    min-height:100vh;
}

a{
    color:var(--primary);
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

.header{
    background:#0f172a;
    color:#fff;
    padding:14px 24px;
}

.header-inner{
    max-width:1400px;
    margin:auto;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
}

.brand{
    font-size:18px;
    font-weight:700;
}

.nav{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.nav a{
    color:#cbd5e1;
    padding:8px 10px;
    border-radius:6px;
}

.nav a:hover{
    background:#1e293b;
    color:#fff;
    text-decoration:none;
}

.container{
    max-width:1400px;
    margin:0 auto;
    padding:28px 24px 60px;
}

.narrow{
    max-width:720px;
}

.page-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    margin-bottom:20px;
}

.page-title h1{
    margin:0;
    font-size:26px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
}

.card h2{
    margin:0 0 16px;
    font-size:19px;
}

.grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.field{
    margin-bottom:16px;
}

.field label{
    display:block;
    margin-bottom:7px;
    font-weight:600;
}

input[type=text],
input[type=password],
input[type=email],
input[type=datetime-local],
input[type=number],
textarea,
select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:7px;
    padding:10px 12px;
    font-size:14px;
    background:#fff;
    color:var(--text);
}

textarea{
    min-height:110px;
    resize:vertical;
}

input:focus,
textarea:focus,
select:focus{
    outline:3px solid rgba(37,99,235,.13);
    border-color:var(--primary);
}

.help{
    color:var(--gray);
    font-size:13px;
    margin-top:5px;
}

.actions{
    display:flex;
    gap:9px;
    align-items:center;
    flex-wrap:wrap;
}

.btn{
    display:inline-flex;
    justify-content:center;
    align-items:center;
    gap:5px;
    border:0;
    border-radius:7px;
    padding:9px 14px;
    font-weight:600;
    cursor:pointer;
    text-decoration:none !important;
    font-size:14px;
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
}

.btn-secondary{
    background:#e2e8f0;
    color:#334155;
}

.btn-success{
    background:var(--success);
    color:#fff;
}

.btn-warning{
    background:var(--warning);
    color:#fff;
}

.btn-danger{
    background:var(--danger);
    color:#fff;
}

.btn-light{
    background:#fff;
    color:#334155;
    border:1px solid var(--border);
}

.btn:disabled{
    opacity:.5;
    cursor:not-allowed;
}

.alert{
    padding:13px 15px;
    border-radius:8px;
    margin-bottom:18px;
}

.alert-success{
    background:#dcfce7;
    color:#166534;
}

.alert-error{
    background:#fee2e2;
    color:#991b1b;
}

.alert-info{
    background:#dbeafe;
    color:#1e40af;
}

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:950px;
    border-collapse:collapse;
}

th,td{
    border-bottom:1px solid var(--border);
    padding:12px 10px;
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    white-space:nowrap;
}

.badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:4px 9px;
    font-size:12px;
    font-weight:700;
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

.toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.filters{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.filters a{
    padding:7px 10px;
    border:1px solid var(--border);
    border-radius:7px;
    background:#fff;
}

.filters a.active{
    color:#fff;
    background:var(--primary);
    border-color:var(--primary);
}

.group{
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:18px;
    background:#fff;
}

.group-head{
    padding:14px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    gap:10px;
}

.group-head input{
    flex:1;
}

.question{
    margin:14px;
    padding:16px;
    border:1px solid #e2e8f0;
    border-radius:9px;
    background:#fff;
}

.question-head{
    display:flex;
    gap:10px;
    align-items:center;
    margin-bottom:12px;
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
    font-size:18px;
}

.options{
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

.preview-box{
    max-width:820px;
    margin:auto;
}

.answer-option{
    display:block;
    padding:12px;
    margin:8px 0;
    border:1px solid var(--border);
    border-radius:8px;
    cursor:pointer;
}

.answer-option:hover{
    background:#f8fafc;
}

.login-box{
    max-width:440px;
    margin:80px auto;
}

.stat-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px;
}

.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
}

.stat .label{
    color:var(--gray);
    font-size:13px;
}

.stat .value{
    font-size:26px;
    font-weight:700;
    margin-top:4px;
}

@media(max-width:900px){
    .grid{
        grid-template-columns:1fr;
    }

    .stat-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .header-inner{
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:600px){
    .container{
        padding:18px 12px 40px;
    }

    .header{
        padding:12px;
    }

    .page-title{
        align-items:flex-start;
        flex-direction:column;
    }

    .card{
        padding:15px;
    }

    .stat-grid{
        grid-template-columns:1fr 1fr;
    }

    .btn{
        min-height:42px;
    }

    input[type=text],
    input[type=password],
    input[type=email],
    input[type=datetime-local],
    textarea,
    select{
        font-size:16px;
    }
}
</style>
</head>

<body>

<?php if ($admin): ?>
<header class="header">
    <div class="header-inner">
        <div class="brand"><?= h(APP_NAME) ?></div>

        <?php if (isAdmin()): ?>
        <nav class="nav">
            <a href="<?= h(url(['screen'=>'list'])) ?>">アンケート一覧</a>
            <a href="<?= h(url(['screen'=>'kintone'])) ?>">kintone設定</a>
            <a href="<?= h(url(['screen'=>'mail'])) ?>">メール設定</a>
            <a href="<?= h(url(['screen'=>'logout'])) ?>">ログアウト</a>
        </nav>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>

<div class="container">

<?php foreach ($flash as $type => $message): ?>
    <div class="alert <?= $type === 'error' ? 'alert-error' : 'alert-success' ?>">
        <?= h($message) ?>
    </div>
<?php endforeach; ?>

<?php
}

function renderFooter(): void
{
    ?>
</div>

<script>
function confirmAction(message){
    return window.confirm(message);
}

document.querySelectorAll('[data-confirm]').forEach(function(el){
    el.addEventListener('click', function(e){
        if(!window.confirm(el.getAttribute('data-confirm'))){
            e.preventDefault();
        }
    });
});

document.querySelectorAll('form[data-confirm]').forEach(function(form){
    form.addEventListener('submit', function(e){
        if(!window.confirm(form.getAttribute('data-confirm'))){
            e.preventDefault();
        }
    });
});
</script>

</body>
</html>
<?php
}

function renderErrorPage(string $message): void
{
    renderHeader('エラー', false);
    ?>
    <div class="card">
        <div class="alert alert-error"><?= h($message) ?></div>
        <a class="btn btn-primary" href="<?= h(url(['screen'=>'list'])) ?>">
            戻る
        </a>
    </div>
    <?php
    renderFooter();
}

/* =========================================================
 * 一覧
 * ======================================================= */

function renderList(): void
{
    requireAdmin();
    refreshAllStatuses();

    $all = surveys();

    $keyword = trim((string)($_GET['q'] ?? ''));
    $statusFilter = (string)($_GET['status_filter'] ?? 'all');
    $sort = (string)($_GET['sort'] ?? 'updated_desc');

    $filtered = array_filter(
        $all,
        static function(array $survey) use ($keyword, $statusFilter): bool {
            if (
                $keyword !== ''
                && mb_stripos(
                    (string)($survey['title'] ?? ''),
                    $keyword
                ) === false
            ) {
                return false;
            }

            if (
                $statusFilter !== 'all'
                && ($survey['status'] ?? '') !== $statusFilter
            ) {
                return false;
            }

            return true;
        }
    );

    usort(
        $filtered,
        static function(array $a, array $b) use ($sort): int {
            return match ($sort) {
                'updated_asc' =>
                    strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    ),
                'answers_desc' =>
                    responseCount((string)$b['id'])
                    <=> responseCount((string)$a['id']),
                'answers_asc' =>
                    responseCount((string)$a['id'])
                    <=> responseCount((string)$b['id']),
                'start_desc' =>
                    strcmp(
                        (string)$b['startAt'],
                        (string)$a['startAt']
                    ),
                'start_asc' =>
                    strcmp(
                        (string)$a['startAt'],
                        (string)$b['startAt']
                    ),
                default =>
                    strcmp(
                        (string)$b['updatedAt'],
                        (string)$a['updatedAt']
                    ),
            };
        }
    );

    renderHeader('アンケート一覧');
    ?>

    <div class="page-title">
        <h1>アンケート一覧</h1>

        <a class="btn btn-primary"
           href="<?= h(url(['screen'=>'edit'])) ?>">
            ＋ 新規アンケート
        </a>
    </div>

    <div class="card">
        <form method="get">
            <input type="hidden" name="screen" value="list">

            <div class="grid">
                <div class="field">
                    <label>タイトル検索</label>
                    <input
                        type="text"
                        name="q"
                        value="<?= h($keyword) ?>"
                        placeholder="タイトルを入力してEnter"
                    >
                </div>

                <div class="field">
                    <label>ソート</label>
                    <select name="sort">
                        <option value="updated_desc"
                            <?= $sort==='updated_desc'?'selected':'' ?>>
                            更新日：新しい順
                        </option>
                        <option value="updated_asc"
                            <?= $sort==='updated_asc'?'selected':'' ?>>
                            更新日：古い順
                        </option>
                        <option value="answers_desc"
                            <?= $sort==='answers_desc'?'selected':'' ?>>
                            回答数：多い順
                        </option>
                        <option value="answers_asc"
                            <?= $sort==='answers_asc'?'selected':'' ?>>
                            回答数：少ない順
                        </option>
                        <option value="start_desc"
                            <?= $sort==='start_desc'?'selected':'' ?>>
                            開始日：新しい順
                        </option>
                        <option value="start_asc"
                            <?= $sort==='start_asc'?'selected':'' ?>>
                            開始日：古い順
                        </option>
                    </select>
                </div>
            </div>

            <div class="actions">
                <button class="btn btn-primary" type="submit">
                    検索
                </button>
            </div>
        </form>
    </div>

    <div class="toolbar">
        <div class="filters">
            <?php
            $filters = [
                'all' => 'すべて',
                'published' => '公開中',
                'draft' => '下書き',
                'stopped' => '停止',
                'ended' => '終了',
            ];
            ?>

            <?php foreach ($filters as $key => $label): ?>
                <a class="<?= $statusFilter === $key ? 'active' : '' ?>"
                   href="<?= h(url([
                       'screen'=>'list',
                       'status_filter'=>$key,
                       'q'=>$keyword,
                       'sort'=>$sort,
                   ])) ?>">
                    <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>タイトル</th>
                    <th>期間</th>
                    <th>状態</th>
                    <th>回答数</th>
                    <th>更新日</th>
                    <th>操作</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$filtered): ?>
                    <tr>
                        <td colspan="6">アンケートがありません。</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($filtered as $survey): ?>
                    <tr>
                        <td>
                            <strong><?= h($survey['title'] ?: '無題') ?></strong>
                        </td>

                        <td>
                            <?= h($survey['startAt']) ?><br>
                            ～ <?= h($survey['endAt']) ?>
                        </td>

                        <td>
                            <?= statusBadge((string)$survey['status']) ?>
                        </td>

                        <td>
                            <?= h(responseCount((string)$survey['id'])) ?>
                        </td>

                        <td>
                            <?= h($survey['updatedAt']) ?>
                        </td>

                        <td>
                            <div class="actions">
                                <a class="btn btn-light"
                                   href="<?= h(url([
                                       'screen'=>'edit',
                                       'id'=>$survey['id'],
                                   ])) ?>">
                                    確認・編集
                                </a>

                                <a class="btn btn-light"
                                   href="<?= h(url([
                                       'screen'=>'analytics',
                                       'id'=>$survey['id'],
                                   ])) ?>">
                                    集計
                                </a>

                                <a class="btn btn-light"
                                   href="<?= h(url([
                                       'screen'=>'send',
                                       'id'=>$survey['id'],
                                   ])) ?>">
                                    送信
                                </a>

                                <form method="post"
                                      style="display:inline"
                                      data-confirm="このアンケートを複製しますか？">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"
                                           value="duplicate_survey">
                                    <input type="hidden" name="id"
                                           value="<?= h($survey['id']) ?>">
                                    <button class="btn btn-secondary"
                                            type="submit">
                                        複製
                                    </button>
                                </form>

                                <form method="post"
                                      style="display:inline"
                                      data-confirm="このアンケートを削除しますか？この操作は元に戻せません。">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"
                                           value="delete_survey">
                                    <input type="hidden" name="id"
                                           value="<?= h($survey['id']) ?>">
                                    <button class="btn btn-danger"
                                            type="submit">
                                        削除
                                    </button>
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
    renderFooter();
}

function responseCount(string $surveyId): int
{
    return count(array_filter(
        responses(),
        static fn(array $response): bool =>
            ($response['surveyId'] ?? '') === $surveyId
    ));
}

function statusBadge(string $status): string
{
    $map = [
        'draft' => ['下書き', 'badge-draft'],
        'published' => ['公開中', 'badge-published'],
        'stopped' => ['停止', 'badge-stopped'],
        'ended' => ['終了', 'badge-ended'],
    ];

    $item = $map[$status] ?? ['不明', 'badge-draft'];

    return '<span class="badge ' . h($item[1]) . '">'
        . h($item[0])
        . '</span>';
}

/* =========================================================
 * 編集
 * ======================================================= */

function renderEdit(): void
{
    requireAdmin();

    $id = (string)($_GET['id'] ?? '');

    if ($id === '') {
        $survey = emptySurvey();
        $isNew = true;
    } else {
        $survey = findSurvey($id);

        if ($survey === null) {
            returnToList();
        }

        $isNew = false;
    }

    recalculateNumbers($survey);

    renderHeader($isNew ? 'アンケート作成' : 'アンケート編集');
    ?>

    <div class="page-title">
        <h1><?= $isNew ? 'アンケート作成' : 'アンケート編集' ?></h1>

        <div class="actions">
            <a class="btn btn-secondary"
               href="<?= h(url(['screen'=>'list'])) ?>">
                キャンセル
            </a>

            <button
                form="survey-form"
                class="btn btn-primary"
                type="submit">
                保存して一覧へ
            </button>
        </div>
    </div>

    <form id="survey-form"
          method="post"
          data-confirm="入力内容を保存しますか？">

        <?= csrfField() ?>

        <input type="hidden" name="action" value="save_survey">
        <input type="hidden" name="id" value="<?= h($survey['id']) ?>">

        <div class="card">
            <div class="grid">

                <div class="field">
                    <label>アンケートタイトル *</label>
                    <input
                        type="text"
                        name="title"
                        maxlength="200"
                        required
                        value="<?= h($survey['title']) ?>"
                    >
                </div>

                <div class="field">
                    <label>状態</label>

                    <?php if ($survey['status'] === 'ended'): ?>

                        <input type="text"
                               value="終了"
                               disabled>

                    <?php else: ?>

                        <select name="status">
                            <?php
                            $statuses = [
                                'draft' => '下書き',
                                'published' => '公開中',
                                'stopped' => '停止',
                            ];
                            ?>

                            <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?= h($key) ?>"
                                    <?= $survey['status']===$key?'selected':'' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php endif; ?>
                </div>

                <div class="field">
                    <label>開始日時 *</label>
                    <input
                        type="datetime-local"
                        name="startAt"
                        required
                        value="<?= h(datetimeLocalValue($survey['startAt'])) ?>"
                    >
                </div>

                <div class="field">
                    <label>終了日時 *</label>
                    <input
                        type="datetime-local"
                        name="endAt"
                        required
                        value="<?= h(datetimeLocalValue($survey['endAt'])) ?>"
                    >
                </div>

                <div class="field">
                    <label>質問番号の採番方式</label>

                    <select name="numbering">
                        <option value="global"
                            <?= $survey['numbering']==='global'?'selected':'' ?>>
                            アンケート全体で通番（Q1、Q2…）
                        </option>

                        <option value="group"
                            <?= $survey['numbering']==='group'?'selected':'' ?>>
                            グループ毎（Q1-1、Q1-2…）
                        </option>
                    </select>
                </div>

            </div>

            <div class="field">
                <label>アンケート説明</label>
                <textarea name="description"
                          maxlength="5000"><?= h($survey['description']) ?></textarea>
            </div>
        </div>

        <div class="card">
            <h2>質問・グループ</h2>

            <div id="groups">

            <?php foreach ($survey['groups'] as $groupIndex => $group): ?>

                <div class="group" draggable="true">

                    <div class="group-head">
                        <span class="drag-handle">☰</span>

                        <input
                            type="text"
                            name="groups[<?= $groupIndex ?>][title]"
                            value="<?= h($group['title']) ?>"
                            maxlength="200"
                            required
                        >

                        <input type="hidden"
                               name="groups[<?= $groupIndex ?>][id]"
                               value="<?= h($group['id']) ?>">

                        <button
                            type="button"
                            class="btn btn-danger"
                            onclick="removeGroup(this)">
                            グループ削除
                        </button>
                    </div>

                    <div class="questions">

                    <?php foreach ($group['questions'] as $questionIndex => $question): ?>

                        <div class="question"
                             draggable="true">

                            <div class="question-head">
                                <span class="drag-handle">⠿</span>

                                <strong class="question-number">
                                    <?= h($question['number']) ?>
                                </strong>

                                <button
                                    type="button"
                                    class="btn btn-danger"
                                    onclick="removeQuestion(this)">
                                    質問削除
                                </button>
                            </div>

                            <input
                                type="hidden"
                                name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][id]"
                                value="<?= h($question['id']) ?>"
                            >

                            <div class="field">
                                <label>質問文 *</label>
                                <textarea
                                    name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][text]"
                                    required
                                ><?= h($question['text']) ?></textarea>
                            </div>

                            <div class="grid">

                                <div class="field">
                                    <label>回答形式</label>

                                    <select
                                        name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][type]"
                                        onchange="toggleOptions(this)"
                                    >
                                        <option value="single"
                                            <?= $question['type']==='single'?'selected':'' ?>>
                                            単一選択
                                        </option>

                                        <option value="multiple"
                                            <?= $question['type']==='multiple'?'selected':'' ?>>
                                            複数選択
                                        </option>

                                        <option value="text"
                                            <?= $question['type']==='text'?'selected':'' ?>>
                                            自由記述
                                        </option>
                                    </select>
                                </div>

                                <div class="field">
                                    <label>必須</label>

                                    <label style="font-weight:normal">
                                        <input
                                            type="checkbox"
                                            name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][required]"
                                            value="1"
                                            <?= !empty($question['required'])?'checked':'' ?>
                                        >
                                        必須回答
                                    </label>
                                </div>

                            </div>

                            <div class="options"
                                 style="<?= $question['type']==='text'?'display:none':'' ?>">

                                <label>選択肢</label>

                                <div class="option-list">

                                <?php foreach ($question['options'] as $optionIndex => $option): ?>

                                    <div class="option-row">
                                        <input
                                            type="text"
                                            name="groups[<?= $groupIndex ?>][questions][<?= $questionIndex ?>][options][]"
                                            value="<?= h($option) ?>"
                                        >

                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            onclick="this.parentElement.remove()">
                                            削除
                                        </button>
                                    </div>

                                <?php endforeach; ?>

                                </div>

                                <button
                                    type="button"
                                    class="btn btn-light"
                                    onclick="addOption(this)">
                                    ＋ 選択肢追加
                                </button>
                            </div>

                        </div>

                    <?php endforeach; ?>

                    </div>

                    <div style="padding:0 14px 14px">
                        <button
                            type="button"
                            class="btn btn-light"
                            onclick="addQuestion(this)">
                            ＋ 質問を追加
                        </button>
                    </div>

                </div>

            <?php endforeach; ?>

            </div>

            <button
                type="button"
                class="btn btn-secondary"
                onclick="addGroup()">
                ＋ グループを追加
            </button>
        </div>

        <div class="card">
            <div class="actions">
                <a class="btn btn-secondary"
                   href="<?= h(url(['screen'=>'list'])) ?>">
                    キャンセル
                </a>

                <a class="btn btn-light"
                   href="<?= h(url([
                       'screen'=>'preview',
                       'id'=>$survey['id'],
                   ])) ?>">
                    プレビュー
                </a>

                <button
                    class="btn btn-primary"
                    type="submit">
                    保存して一覧へ
                </button>
            </div>
        </div>

    </form>

<script>
function removeQuestion(button){
    if(confirm('この質問を削除しますか？')){
        button.closest('.question').remove();
    }
}

function removeGroup(button){
    if(confirm('このグループと質問を削除しますか？')){
        button.closest('.group').remove();
    }
}

function addOption(button){
    const options = button.previousElementSibling;

    const row = document.createElement('div');
    row.className = 'option-row';

    row.innerHTML =
        '<input type="text" name="TEMP_OPTIONS[]" value="">'
        + '<button type="button" class="btn btn-secondary"'
        + ' onclick="this.parentElement.remove()">削除</button>';

    options.appendChild(row);

    rebuildNames();
}

function addQuestion(button){
    const group = button.closest('.group');
    const questions = group.querySelector('.questions');

    const q = document.createElement('div');
    q.className = 'question';
    q.draggable = true;

    q.innerHTML = `
        <div class="question-head">
            <span class="drag-handle">⠿</span>
            <strong class="question-number">新規</strong>
            <button type="button"
                    class="btn btn-danger"
                    onclick="removeQuestion(this)">
                質問削除
            </button>
        </div>

        <div class="field">
            <label>質問文 *</label>
            <textarea required data-field="text"></textarea>
        </div>

        <div class="grid">
            <div class="field">
                <label>回答形式</label>
                <select data-field="type" onchange="toggleOptions(this)">
                    <option value="single">単一選択</option>
                    <option value="multiple">複数選択</option>
                    <option value="text">自由記述</option>
                </select>
            </div>

            <div class="field">
                <label>必須</label>
                <label style="font-weight:normal">
                    <input type="checkbox"
                           value="1"
                           data-field="required">
                    必須回答
                </label>
            </div>
        </div>

        <div class="options">
            <label>選択肢</label>

            <div class="option-list">
                <div class="option-row">
                    <input type="text" data-option value="選択肢1">
                    <button type="button"
                            class="btn btn-secondary"
                            onclick="this.parentElement.remove()">
                        削除
                    </button>
                </div>

                <div class="option-row">
                    <input type="text" data-option value="選択肢2">
                    <button type="button"
                            class="btn btn-secondary"
                            onclick="this.parentElement.remove()">
                        削除
                    </button>
                </div>
            </div>

            <button type="button"
                    class="btn btn-light"
                    onclick="addOption(this)">
                ＋ 選択肢追加
            </button>
        </div>
    `;

    questions.appendChild(q);
    rebuildNames();
}

function addGroup(){
    const container = document.getElementById('groups');

    const group = document.createElement('div');
    group.className = 'group';
    group.draggable = true;

    group.innerHTML = `
        <div class="group-head">
            <span class="drag-handle">☰</span>
            <input type="text" value="新しいグループ" required>
            <button type="button"
                    class="btn btn-danger"
                    onclick="removeGroup(this)">
                グループ削除
            </button>
        </div>

        <div class="questions"></div>

        <div style="padding:0 14px 14px">
            <button type="button"
                    class="btn btn-light"
                    onclick="addQuestion(this)">
                ＋ 質問を追加
            </button>
        </div>
    `;

    container.appendChild(group);

    addQuestion(group.querySelector('.btn-light'));

    rebuildNames();
}

function toggleOptions(select){
    const question = select.closest('.question');
    const options = question.querySelector('.options');

    options.style.display =
        select.value === 'text' ? 'none' : '';
}

function rebuildNames(){
    const groups = document.querySelectorAll('#groups > .group');

    groups.forEach(function(group, gi){
        const groupTitle = group.querySelector('.group-head input');

        groupTitle.name =
            `groups[${gi}][title]`;

        let groupId = group.querySelector(
            '.group-head input[type=hidden]'
        );

        if(!groupId){
            groupId = document.createElement('input');
            groupId.type = 'hidden';
            group.querySelector('.group-head').appendChild(groupId);
        }

        groupId.name = `groups[${gi}][id]`;

        if(!groupId.value){
            groupId.value =
                'group-' + Date.now() + '-' + Math.random();
        }

        const questions = group.querySelectorAll('.question');

        questions.forEach(function(question, qi){
            const idInput = question.querySelector(
                'input[type=hidden]'
            );

            if(idInput){
                idInput.name =
                    `groups[${gi}][questions][${qi}][id]`;
            }

            const text = question.querySelector(
                'textarea[data-field="text"]'
            );

            if(text){
                text.name =
                    `groups[${gi}][questions][${qi}][text]`;
            }else{
                const oldText = question.querySelector(
                    'textarea[name*="[text]"]'
                );

                if(oldText){
                    oldText.name =
                        `groups[${gi}][questions][${qi}][text]`;
                }
            }

            const type = question.querySelector(
                'select[data-field="type"]'
            ) || question.querySelector(
                'select[name*="[type]"]'
            );

            if(type){
                type.name =
                    `groups[${gi}][questions][${qi}][type]`;
            }

            const required = question.querySelector(
                'input[data-field="required"]'
            ) || question.querySelector(
                'input[name*="[required]"]'
            );

            if(required){
                required.name =
                    `groups[${gi}][questions][${qi}][required]`;
            }

            question.querySelectorAll(
                '[data-option], input[name*="[options]"]'
            ).forEach(function(option){
                option.name =
                    `groups[${gi}][questions][${qi}][options][]`;
            });
        });
    });
}

document.getElementById('survey-form')
    .addEventListener('submit', function(){
        rebuildNames();
    });

/*
 * HTML5 Drag & Drop
 */
let dragged = null;

document.addEventListener('dragstart', function(e){
    const group = e.target.closest('.group');
    const question = e.target.closest('.question');

    if(group && e.target.classList.contains('drag-handle')){
        dragged = group;
    }else if(question && e.target.classList.contains('drag-handle')){
        dragged = question;
    }
});

document.addEventListener('dragover', function(e){
    if(dragged){
        e.preventDefault();
    }
});

document.addEventListener('drop', function(e){
    if(!dragged){
        return;
    }

    const targetQuestion = e.target.closest('.question');
    const targetGroup = e.target.closest('.group');

    if(
        dragged.classList.contains('question')
        && targetQuestion
        && dragged !== targetQuestion
    ){
        targetQuestion.parentNode.insertBefore(
            dragged,
            targetQuestion
        );
    }else if(
        dragged.classList.contains('question')
        && targetGroup
    ){
        targetGroup.querySelector('.questions')
            .appendChild(dragged);
    }else if(
        dragged.classList.contains('group')
        && targetGroup
        && dragged !== targetGroup
    ){
        targetGroup.parentNode.insertBefore(
            dragged,
            targetGroup
        );
    }

    rebuildNames();
    dragged = null;
});
</script>

    <?php
    renderFooter();
}

function datetimeLocalValue(string $value): string
{
    $time = strtotime($value);

    return $time === false
        ? ''
        : date('Y-m-d\TH:i', $time);
}

/* =========================================================
 * プレビュー
 * ======================================================= */

function renderPreview(): void
{
    requireAdmin();

    $id = (string)($_GET['id'] ?? '');
    $survey = findSurvey($id);

    if ($survey === null) {
        returnToList();
    }

    recalculateNumbers($survey);

    renderHeader('プレビュー');
    ?>

    <div class="page-title">
        <h1>プレビュー</h1>

        <a class="btn btn-secondary"
           href="<?= h(url([
               'screen'=>'edit',
               'id'=>$survey['id'],
           ])) ?>">
            編集へ戻る
        </a>
    </div>

    <div class="card preview-box">

        <h1><?= h($survey['title']) ?></h1>

        <?php if ($survey['description'] !== ''): ?>
            <p><?= nl2br(h($survey['description'])) ?></p>
        <?php endif; ?>

        <?php foreach ($survey['groups'] as $group): ?>

            <div class="card">
                <h2><?= h($group['title']) ?></h2>

                <?php foreach ($group['questions'] as $question): ?>

                    <div class="field">
                        <label>
                            <?= h($question['number']) ?>.
                            <?= h($question['text']) ?>

                            <?php if (!empty($question['required'])): ?>
                                <span style="color:#dc2626">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($question['type'] === 'text'): ?>

                            <textarea disabled
                                      placeholder="自由記述"></textarea>

                        <?php elseif ($question['type'] === 'single'): ?>

                            <?php foreach ($question['options'] as $option): ?>
                                <label class="answer-option">
                                    <input type="radio"
                                           disabled>
                                    <?= h($option) ?>
                                </label>
                            <?php endforeach; ?>

                        <?php else: ?>

                            <?php foreach ($question['options'] as $option): ?>
                                <label class="answer-option">
                                    <input type="checkbox"
                                           disabled>
                                    <?= h($option) ?>
                                </label>
                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>
            </div>

        <?php endforeach; ?>

        <div class="alert alert-info">
            これはプレビューです。実際のメール送信や回答登録は行いません。
        </div>

    </div>

    <?php
    renderFooter();
}

/* =========================================================
 * 送信画面
 * ======================================================= */

function renderSend(): void
{
    requireAdmin();

    $id = (string)($_GET['id'] ?? '');
    $survey = findSurvey($id);

    if ($survey === null) {
        returnToList();
    }

    $customerList = customers();

    $q = trim((string)($_GET['customer_q'] ?? ''));

    if ($q !== '') {
        $customerList = array_values(array_filter(
            $customerList,
            static function(array $customer) use ($q): bool {
                foreach ([
                    'organization',
                    'name',
                    'email',
                    'department',
                    'phone',
                    'address',
                ] as $field) {
                    if (
                        mb_stripos(
                            (string)($customer[$field] ?? ''),
                            $q
                        ) !== false
                    ) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    $logs = array_values(array_filter(
        sendLogs(),
        static fn(array $log): bool =>
            ($log['surveyId'] ?? '') === $survey['id']
    ));

    renderHeader('顧客選択・メール送信');
    ?>

    <div class="page-title">
        <h1>顧客選択・メール送信</h1>

        <a class="btn btn-secondary"
           href="<?= h(url(['screen'=>'list'])) ?>">
            一覧へ戻る
        </a>
    </div>

    <div class="card">
        <h2>対象アンケート</h2>

        <strong><?= h($survey['title']) ?></strong>

        <p class="help">
            対象アンケートは一覧から引き継がれており、
            この画面では変更できません。
        </p>
    </div>

    <div class="card">
        <h2>顧客検索</h2>

        <form method="get">
            <input type="hidden" name="screen" value="send">
            <input type="hidden" name="id"
                   value="<?= h($survey['id']) ?>">

            <div class="field">
                <input
                    type="text"
                    name="customer_q"
                    value="<?= h($q) ?>"
                    placeholder="顧客名、組織、メールアドレス等"
                >
            </div>

            <button class="btn btn-primary">
                検索
            </button>
        </form>
    </div>

    <form method="post"
          data-confirm="選択した顧客へメールを送信します。実際にメールが送信されます。">

        <?= csrfField() ?>

        <input type="hidden" name="action"
               value="send_mail">

        <input type="hidden" name="surveyId"
               value="<?= h($survey['id']) ?>">

        <div class="card">
            <h2>顧客選択</h2>

            <?php if (!$customerList): ?>

                <div class="alert alert-info">
                    顧客データがありません。
                    kintone設定画面から顧客情報を同期してください。
                </div>

            <?php else: ?>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th></th>
                            <th>組織</th>
                            <th>氏名</th>
                            <th>部署</th>
                            <th>メールアドレス</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($customerList as $customer): ?>
                            <tr>
                                <td>
                                    <input type="checkbox"
                                           name="customer_ids[]"
                                           value="<?= h($customer['id']) ?>">
                                </td>
                                <td><?= h($customer['organization']) ?></td>
                                <td><?= h($customer['name']) ?></td>
                                <td><?= h($customer['department']) ?></td>
                                <td><?= h($customer['email']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </div>

        <div class="card">
            <h2>メール内容</h2>

            <div class="field">
                <label>件名 *</label>
                <input
                    type="text"
                    name="subject"
                    required
                    value="<?= h(
                        $survey['title'] . ' 回答のお願い'
                    ) ?>"
                >
            </div>

            <div class="field">
                <label>本文 *</label>

                <textarea name="body"
                          required><?= h(
'{顧客名} 様

アンケートへのご協力をお願いいたします。

回答はこちら：
{アンケートURL}

よろしくお願いいたします。'
                ) ?></textarea>

                <div class="help">
                    使用可能な変数：
                    {顧客名} / {アンケートURL}
                </div>
            </div>

            <button class="btn btn-primary"
                    type="submit">
                選択した顧客へ一括送信
            </button>
        </div>

    </form>

    <div class="card">
        <h2>送信履歴</h2>

        <?php if (!$logs): ?>

            <p>送信履歴はありません。</p>

        <?php else: ?>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>日時</th>
                        <th>顧客</th>
                        <th>メール</th>
                        <th>結果</th>
                        <th>種別</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach (array_reverse($logs) as $log): ?>
                        <tr>
                            <td><?= h($log['createdAt']) ?></td>
                            <td><?= h($log['customerName']) ?></td>
                            <td><?= h($log['email']) ?></td>
                            <td>
                                <?php if ($log['status'] === 'sent'): ?>
                                    <span class="badge badge-published">
                                        送信成功
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-ended">
                                        送信失敗
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($log['type'] ?? 'initial') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>

    <?php
    renderFooter();
}

/* =========================================================
 * 集計
 * ======================================================= */

function renderAnalytics(): void
{
    requireAdmin();

    $id = (string)($_GET['id'] ?? '');
    $survey = findSurvey($id);

    if ($survey === null) {
        returnToList();
    }

    $allResponses = array_values(array_filter(
        responses(),
        static fn(array $response): bool =>
            ($response['surveyId'] ?? '') === $survey['id']
    ));

    $logs = array_values(array_filter(
        sendLogs(),
        static fn(array $log): bool =>
            ($log['surveyId'] ?? '') === $survey['id']
    ));

    $sentEmails = array_values(array_filter(
        $logs,
        static fn(array $log): bool =>
            ($log['status'] ?? '') === 'sent'
    ));

    $registered = count(array_filter(
        $allResponses,
        static fn(array $response): bool =>
            !empty($response['customerId'])
    ));

    $unregistered = count($allResponses) - $registered;

    $sentCount = count($sentEmails);
    $answered = count($allResponses);
    $unanswered = max(0, $sentCount - $answered);

    $rate = $sentCount > 0
        ? round(($answered / $sentCount) * 100, 1)
        : 0;

    renderHeader('回答集計・分析');
    ?>

    <div class="page-title">
        <h1>回答集計・分析</h1>

        <div class="actions">
            <a class="btn btn-light"
               href="<?= h(url([
                   'screen'=>'analytics_csv',
                   'id'=>$survey['id'],
               ])) ?>">
                CSV
            </a>

            <a class="btn btn-light"
               href="<?= h(url([
                   'screen'=>'analytics_pdf',
                   'id'=>$survey['id'],
               ])) ?>">
                PDF
            </a>

            <a class="btn btn-secondary"
               href="<?= h(url(['screen'=>'list'])) ?>">
                一覧へ戻る
            </a>
        </div>
    </div>

    <div class="card">
        <strong><?= h($survey['title']) ?></strong>

        <p class="help">
            対象アンケートは一覧から引き継がれており、
            この画面では変更できません。
        </p>
    </div>

    <div class="stat-grid">

        <div class="stat">
            <div class="label">送信対象者数</div>
            <div class="value"><?= h($sentCount) ?></div>
        </div>

        <div class="stat">
            <div class="label">回答数</div>
            <div class="value"><?= h($answered) ?></div>
        </div>

        <div class="stat">
            <div class="label">未登録回答数</div>
            <div class="value"><?= h($unregistered) ?></div>
        </div>

        <div class="stat">
            <div class="label">未回答数</div>
            <div class="value"><?= h($unanswered) ?></div>
        </div>

        <div class="stat">
            <div class="label">回答率</div>
            <div class="value"><?= h($rate) ?>%</div>
        </div>

    </div>

    <?php if ($answered === 0): ?>

        <div class="card">
            <div class="alert alert-info">
                現在、回答データはありません
            </div>
        </div>

    <?php else: ?>

        <?php
        $stats = buildQuestionStats($survey, $allResponses);
        ?>

        <div class="card">
            <h2>設問別集計</h2>

            <?php foreach ($stats as $stat): ?>

                <div class="card">
                    <h3>
                        <?= h($stat['number']) ?>.
                        <?= h($stat['text']) ?>
                    </h3>

                    <?php if ($stat['type'] === 'text'): ?>

                        <p>
                            自由記述回答：
                            <?= h(count($stat['answers'])) ?>件
                        </p>

                    <?php else: ?>

                        <?php foreach ($stat['counts'] as $option => $count): ?>
                            <div style="margin-bottom:8px">
                                <div style="
                                    display:flex;
                                    justify-content:space-between;
                                    gap:10px;
                                ">
                                    <span><?= h($option) ?></span>
                                    <strong><?= h($count) ?></strong>
                                </div>

                                <div style="
                                    height:8px;
                                    background:#e2e8f0;
                                    border-radius:999px;
                                ">
                                    <div style="
                                        height:8px;
                                        width:<?= $stat['total'] > 0
                                            ? h(round(($count / $stat['total']) * 100, 1))
                                            : 0 ?>%;
                                        background:#2563eb;
                                        border-radius:999px;
                                    "></div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>
        </div>

        <div class="card">
            <h2>個別回答</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>回答日時</th>
                        <th>回答者</th>
                        <th>登録状態</th>
                        <th>回答</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($allResponses as $response): ?>

                        <?php
                        $customerName = '未登録';

                        if (!empty($response['customerId'])) {
                            foreach (customers() as $customer) {
                                if (
                                    ($customer['id'] ?? '')
                                    === $response['customerId']
                                ) {
                                    $customerName =
                                        $customer['name']
                                        ?: $customerName;
                                    break;
                                }
                            }
                        }
                        ?>

                        <tr>
                            <td><?= h($response['createdAt']) ?></td>
                            <td><?= h($customerName) ?></td>
                            <td>
                                <?= !empty($response['customerId'])
                                    ? '登録済み'
                                    : '未登録' ?>
                            </td>
                            <td>
                                <?php foreach (($response['answers'] ?? []) as $qid => $answer): ?>
                                    <div style="margin-bottom:8px">
                                        <strong><?= h($qid) ?></strong>：
                                        <?= h(
                                            is_array($answer)
                                                ? implode(', ', $answer)
                                                : $answer
                                        ) ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

    <?php
    renderFooter();
}

function buildQuestionStats(
    array $survey,
    array $responseList
): array {
    $stats = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {

            $item = [
                'number' => $question['number'],
                'text' => $question['text'],
                'type' => $question['type'],
                'counts' => [],
                'answers' => [],
                'total' => 0,
            ];

            foreach ($question['options'] as $option) {
                $item['counts'][$option] = 0;
            }

            foreach ($responseList as $response) {
                if (!isset($response['answers'][$question['id']])) {
                    continue;
                }

                $answer = $response['answers'][$question['id']];

                if ($question['type'] === 'text') {
                    $item['answers'][] = $answer;
                    $item['total']++;
                    continue;
                }

                $values = is_array($answer)
                    ? $answer
                    : [$answer];

                foreach ($values as $value) {
                    if (isset($item['counts'][$value])) {
                        $item['counts'][$value]++;
                    }
                }

                $item['total']++;
            }

            $stats[] = $item;
        }
    }

    return $stats;
}

/* =========================================================
 * CSV
 * ======================================================= */

function outputAnalyticsCsv(array $survey): never
{
    requireAdmin();

    $rows = [];

    $headers = [
        '回答日時',
        '回答者',
        '登録状態',
    ];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {
            $headers[] =
                $question['number'] . ' ' . $question['text'];
        }
    }

    $rows[] = $headers;

    $customerMap = [];

    foreach (customers() as $customer) {
        $customerMap[$customer['id']] = $customer;
    }

    foreach (responses() as $response) {
        if (($response['surveyId'] ?? '') !== $survey['id']) {
            continue;
        }

        $customer = $customerMap[$response['customerId'] ?? '']
            ?? null;

        $row = [
            $response['createdAt'] ?? '',
            $customer['name'] ?? '未登録',
            $customer ? '登録済み' : '未登録',
        ];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $answer =
                    $response['answers'][$question['id']]
                    ?? '';

                $row[] = is_array($answer)
                    ? implode(' / ', $answer)
                    : $answer;
            }
        }

        $rows[] = $row;
    }

    $filename =
        'survey-' . preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '',
            $survey['id']
        ) . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' . $filename . '"'
    );

    $fp = fopen('php://output', 'w');

    /*
     * Excel等で日本語CSVを開くためのUTF-8 BOM。
     */
    fwrite($fp, "\xEF\xBB\xBF");

    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* =========================================================
 * PDF
 * ======================================================= */

function outputAnalyticsPdf(array $survey): never
{
    requireAdmin();

    $lines = [];

    $lines[] = '回答集計';
    $lines[] = '回答数: ' . responseCount($survey['id']);
    $lines[] = '';

    $stats = buildQuestionStats(
        $survey,
        array_values(array_filter(
            responses(),
            static fn(array $response): bool =>
                ($response['surveyId'] ?? '') === $survey['id']
        ))
    );

    foreach ($stats as $stat) {
        $lines[] =
            $stat['number'] . ' ' . $stat['text'];

        if ($stat['type'] === 'text') {
            foreach ($stat['answers'] as $answer) {
                $lines[] = '・' . (string)$answer;
            }
        } else {
            foreach ($stat['counts'] as $option => $count) {
                $lines[] =
                    '・' . $option . ': ' . $count;
            }
        }

        $lines[] = '';
    }

    $pdf = createSimplePdf(
        $survey['title'],
        $lines
    );

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: attachment; filename="survey-result.pdf"'
    );
    header('Content-Length: ' . strlen($pdf));

    echo $pdf;
    exit;
}

/* =========================================================
 * kintone設定
 * ======================================================= */

function renderKintone(): void
{
    requireAdmin();

    $settings = appSettings()['kintone'];

    $fields = $_SESSION['kintone_fields'] ?? [];

    renderHeader('kintone連携設定');
    ?>

    <div class="page-title">
        <h1>kintone連携設定</h1>

        <a class="btn btn-secondary"
           href="<?= h(url(['screen'=>'list'])) ?>">
            一覧へ戻る
        </a>
    </div>

    <form method="post">

        <?= csrfField() ?>

        <input type="hidden" name="action"
               value="save_kintone">

        <div class="card">
            <h2>接続設定</h2>

            <div class="grid">

                <div class="field">
                    <label>サブドメイン *</label>
                    <input
                        type="text"
                        name="subdomain"
                        required
                        value="<?= h($settings['subdomain']) ?>"
                        placeholder="example"
                    >
                    <div class="help">
                        https://example.cybozu.com の example 部分
                    </div>
                </div>

                <div class="field">
                    <label>顧客管理アプリID *</label>
                    <input
                        type="number"
                        name="appId"
                        min="1"
                        required
                        value="<?= h($settings['appId']) ?>"
                    >
                </div>

                <div class="field">
                    <label>ログイン名 *</label>
                    <input
                        type="text"
                        name="username"
                        required
                        value="<?= h($settings['username']) ?>"
                    >
                </div>

                <div class="field">
                    <label>パスワード *</label>
                    <input
                        type="password"
                        name="password"
                        value=""
                        placeholder="変更する場合のみ入力"
                    >
                    <div class="help">
                        保存済みパスワードを画面へ表示しません。
                    </div>
                </div>

                <div class="field">
                    <label>Proxyサーバ</label>
                    <input
                        type="text"
                        name="proxy"
                        value="<?= h($settings['proxy']) ?>"
                        placeholder="proxy.example.local:8080"
                    >
                    <div class="help">
                        host:port形式。未入力の場合は直接接続します。
                    </div>
                </div>

                <div class="field">
                    <label>SSL証明書検証</label>

                    <label style="font-weight:normal">
                        <input
                            type="checkbox"
                            name="verifySsl"
                            value="1"
                            <?= !empty($settings['verifySsl'])
                                ? 'checked'
                                : '' ?>
                        >
                        SSL証明書を検証する
                    </label>
                </div>

            </div>

            <div class="actions">
                <button class="btn btn-primary"
                        type="submit">
                    設定を保存
                </button>
            </div>
        </div>

    </form>

    <div class="card">
        <h2>接続テスト</h2>

        <p>
            保存済み設定を使用して、
            実際のkintone REST APIへ接続します。
        </p>

        <form method="post"
              data-confirm="実際のkintoneへ接続します。よろしいですか？">

            <?= csrfField() ?>

            <input type="hidden" name="action"
                   value="test_kintone">

            <button class="btn btn-primary">
                接続テスト
            </button>
        </form>
    </div>

    <div class="card">
        <h2>項目一覧</h2>

        <p>
            保存済み設定を使用して、
            実際のkintoneアプリから項目一覧を取得します。
        </p>

        <form method="post">

            <?= csrfField() ?>

            <input type="hidden" name="action"
                   value="fetch_kintone_fields">

            <button class="btn btn-light">
                項目一覧を再取得
            </button>
        </form>

        <?php if ($fields): ?>

            <div class="table-wrap" style="margin-top:18px">
                <table>
                    <thead>
                    <tr>
                        <th>フィールドコード</th>
                        <th>表示名</th>
                        <th>種類</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($fields as $code => $field): ?>
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

    <?php if ($fields): ?>

    <form method="post">

        <?= csrfField() ?>

        <input type="hidden" name="action"
               value="save_kintone_mapping">

        <div class="card">
            <h2>顧客情報マッピング</h2>

            <div class="grid">

                <?php
                $mappingFields = [
                    'organization' => '組織名',
                    'name' => '氏名',
                    'email' => 'メールアドレス',
                    'department' => '部署名',
                    'phone' => '電話番号',
                ];
                ?>

                <?php foreach ($mappingFields as $key => $label): ?>

                    <div class="field">
                        <label><?= h($label) ?></label>

                        <select name="mapping[<?= h($key) ?>]">
                            <option value="">未設定</option>

                            <?php foreach ($fields as $code => $field): ?>

                                <option
                                    value="<?= h($code) ?>"
                                    <?= ($settings['fieldMapping'][$key] ?? '')
                                        === $code
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= h(
                                        ($field['label'] ?? '')
                                        . ' [' . $code . ']'
                                    ) ?>
                                </option>

                            <?php endforeach; ?>
                        </select>
                    </div>

                <?php endforeach; ?>

            </div>

            <div class="field">
                <label>住所</label>

                <div class="grid">

                <?php foreach ($fields as $code => $field): ?>

                    <label style="font-weight:normal">
                        <input
                            type="checkbox"
                            name="mapping[address][]"
                            value="<?= h($code) ?>"
                            <?= in_array(
                                $code,
                                $settings['fieldMapping']['address'] ?? [],
                                true
                            ) ? 'checked' : '' ?>
                        >
                        <?= h(
                            ($field['label'] ?? '')
                            . ' [' . $code . ']'
                        ) ?>
                    </label>

                <?php endforeach; ?>

                </div>

                <div class="help">
                    住所は複数フィールドを連結できます。
                </div>
            </div>

            <button class="btn btn-primary">
                マッピングを保存
            </button>
        </div>

    </form>

    <?php endif; ?>

    <div class="card">
        <h2>顧客情報同期</h2>

        <p>
            実際のkintoneから顧客情報を取得し、
            サーバー側へ保存します。
        </p>

        <form method="post"
              data-confirm="kintoneから顧客情報を同期します。よろしいですか？">

            <?= csrfField() ?>

            <input type="hidden" name="action"
                   value="sync_kintone">

            <button class="btn btn-success">
                顧客情報を同期
            </button>
        </form>
    </div>

    <?php
    renderFooter();
}

/* =========================================================
 * メール設定
 * ======================================================= */

function renderMail(): void
{
    requireAdmin();

    $settings = appSettings()['smtp'];

    renderHeader('メールサーバ設定');
    ?>

    <div class="page-title">
        <h1>メールサーバ設定</h1>

        <a class="btn btn-secondary"
           href="<?= h(url(['screen'=>'list'])) ?>">
            一覧へ戻る
        </a>
    </div>

    <form method="post">

        <?= csrfField() ?>

        <input type="hidden" name="action"
               value="save_smtp">

        <div class="card">
            <h2>SMTP設定</h2>

            <div class="grid">

                <div class="field">
                    <label>SMTPサーバ *</label>
                    <input
                        type="text"
                        name="host"
                        required
                        value="<?= h($settings['host']) ?>"
                    >
                </div>

                <div class="field">
                    <label>SMTPポート *</label>
                    <input
                        type="number"
                        name="port"
                        min="1"
                        max="65535"
                        required
                        value="<?= h($settings['port']) ?>"
                    >
                </div>

                <div class="field">
                    <label>暗号化方式</label>
                    <select name="encryption">
                        <option value="tls"
                            <?= $settings['encryption']==='tls'
                                ? 'selected'
                                : '' ?>>
                            TLS
                        </option>
                        <option value="ssl"
                            <?= $settings['encryption']==='ssl'
                                ? 'selected'
                                : '' ?>>
                            SSL
                        </option>
                        <option value="none"
                            <?= $settings['encryption']==='none'
                                ? 'selected'
                                : '' ?>>
                            なし
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>SMTP認証</label>

                    <label style="font-weight:normal">
                        <input
                            type="checkbox"
                            name="auth"
                            value="1"
                            <?= !empty($settings['auth'])
                                ? 'checked'
                                : '' ?>
                        >
                        SMTP認証を使用する
                    </label>
                </div>

                <div class="field">
                    <label>SMTPユーザー名</label>
                    <input
                        type="text"
                        name="username"
                        value="<?= h($settings['username']) ?>"
                    >
                </div>

                <div class="field">
                    <label>SMTPパスワード</label>
                    <input
                        type="password"
                        name="password"
                        placeholder="変更する場合のみ入力"
                    >
                </div>

                <div class="field">
                    <label>送信元メールアドレス *</label>
                    <input
                        type="email"
                        name="from"
                        required
                        value="<?= h($settings['from']) ?>"
                    >
                </div>

                <div class="field">
                    <label>送信元名</label>
                    <input
                        type="text"
                        name="fromName"
                        value="<?= h($settings['fromName']) ?>"
                    >
                </div>

                <div class="field">
                    <label>返信先メールアドレス</label>
                    <input
                        type="email"
                        name="replyTo"
                        value="<?= h($settings['replyTo']) ?>"
                    >
                </div>

            </div>

            <button class="btn btn-primary">
                設定を保存
            </button>
        </div>

    </form>

    <div class="card">
        <h2>接続テスト</h2>

        <p>
            保存済みSMTP設定で実際にSMTPサーバへ接続します。
        </p>

        <form method="post"
              data-confirm="実際のSMTPサーバへ接続します。よろしいですか？">

            <?= csrfField() ?>

            <input type="hidden" name="action"
                   value="test_smtp">

            <button class="btn btn-light">
                SMTP接続テスト
            </button>
        </form>
    </div>

    <div class="card">
        <h2>テストメール</h2>

        <form method="post">

            <?= csrfField() ?>

            <input type="hidden" name="action"
                   value="send_test_mail">

            <div class="field">
                <label>送信先メールアドレス *</label>
                <input type="email"
                       name="test_to"
                       required>
            </div>

            <button class="btn btn-primary"
                    data-confirm="実際にテストメールを送信します。">
                テストメール送信
            </button>

        </form>
    </div>

    <?php
    renderFooter();
}

/* =========================================================
 * 回答画面
 * ======================================================= */

function renderAnswer(): void
{
    $id = (string)($_GET['id'] ?? '');
    $survey = findSurvey($id);

    if ($survey === null) {
        renderErrorPage('アンケートが見つかりません。');
        return;
    }

    requireAnswerSurvey($survey);

    recalculateNumbers($survey);

    $answers = $_SESSION['answer_draft'][$survey['id']] ?? [];

    renderHeader('アンケート回答', false);
    ?>

    <div class="preview-box">

        <div class="card">
            <h1><?= h($survey['title']) ?></h1>

            <?php if ($survey['description'] !== ''): ?>
                <p><?= nl2br(h($survey['description'])) ?></p>
            <?php endif; ?>
        </div>

        <form method="post">

            <?= csrfField() ?>

            <input type="hidden"
                   name="action"
                   value="save_answer_draft">

            <input type="hidden"
                   name="surveyId"
                   value="<?= h($survey['id']) ?>">

            <?php foreach ($survey['groups'] as $group): ?>

                <div class="card">
                    <h2><?= h($group['title']) ?></h2>

                    <?php foreach ($group['questions'] as $question): ?>

                        <div class="field">

                            <label>
                                <?= h($question['number']) ?>.
                                <?= h($question['text']) ?>

                                <?php if (!empty($question['required'])): ?>
                                    <span style="color:#dc2626">*</span>
                                <?php endif; ?>
                            </label>

                            <?php
                            $current =
                                $answers[$question['id']]
                                ?? '';
                            ?>

                            <?php if ($question['type'] === 'text'): ?>

                                <textarea
                                    name="answers[<?= h($question['id']) ?>]"
                                    <?= !empty($question['required'])
                                        ? 'required'
                                        : '' ?>
                                ><?= h($current) ?></textarea>

                            <?php elseif ($question['type'] === 'single'): ?>

                                <?php foreach ($question['options'] as $option): ?>

                                    <label class="answer-option">
                                        <input
                                            type="radio"
                                            name="answers[<?= h($question['id']) ?>]"
                                            value="<?= h($option) ?>"
                                            <?= $current === $option
                                                ? 'checked'
                                                : '' ?>
                                            <?= !empty($question['required'])
                                                ? 'required'
                                                : '' ?>
                                        >
                                        <?= h($option) ?>
                                    </label>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <?php
                                $selected = is_array($current)
                                    ? $current
                                    : [];
                                ?>

                                <?php foreach ($question['options'] as $option): ?>

                                    <label class="answer-option">
                                        <input
                                            type="checkbox"
                                            name="answers[<?= h($question['id']) ?>][]"
                                            value="<?= h($option) ?>"
                                            <?= in_array(
                                                $option,
                                                $selected,
                                                true
                                            )
                                                ? 'checked'
                                                : '' ?>
                                        >
                                        <?= h($option) ?>
                                    </label>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>
                </div>

            <?php endforeach; ?>

            <div class="card">
                <button class="btn btn-primary"
                        type="submit">
                    回答を確認
                </button>
            </div>

        </form>

    </div>

    <?php
    renderFooter();
}

function renderConfirm(): void
{
    $id = (string)($_GET['id'] ?? '');
    $survey = findSurvey($id);

    if ($survey === null) {
        renderErrorPage('アンケートが見つかりません。');
        return;
    }

    requireAnswerSurvey($survey);

    $answers = $_SESSION['answer_draft'][$survey['id']] ?? [];

    renderHeader('回答確認', false);
    ?>

    <div class="preview-box">

        <div class="card">
            <h1>回答確認</h1>

            <p>
                以下の内容で送信します。
            </p>
        </div>

        <?php foreach ($survey['groups'] as $group): ?>

            <div class="card">
                <h2><?= h($group['title']) ?></h2>

                <?php foreach ($group['questions'] as $question): ?>

                    <div class="field">
                        <label>
                            <?= h($question['number']) ?>.
                            <?= h($question['text']) ?>
                        </label>

                        <?php
                        $answer =
                            $answers[$question['id']]
                            ?? '';

                        $display = is_array($answer)
                            ? implode(', ', $answer)
                            : $answer;
                        ?>

                        <div>
                            <?= nl2br(h($display)) ?>
                        </div>
                    </div>

                <?php endforeach; ?>
            </div>

        <?php endforeach; ?>

        <div class="card">

            <div class="actions">

                <a class="btn btn-secondary"
                   href="<?= h(url([
                       'screen'=>'answer',
                       'id'=>$survey['id'],
                   ])) ?>">
                    修正する
                </a>

                <form method="post"
                      data-confirm="回答を送信します。送信後は修正できません。">

                    <?= csrfField() ?>

                    <input type="hidden"
                           name="action"
                           value="submit_answer">

                    <input type="hidden"
                           name="surveyId"
                           value="<?= h($survey['id']) ?>">

                    <button class="btn btn-primary">
                        回答を送信
                    </button>

                </form>

            </div>

        </div>

    </div>

    <?php
    renderFooter();
}

function renderComplete(): void
{
    renderHeader('回答完了', false);
    ?>

    <div class="preview-box">
        <div class="card">

            <h1>回答ありがとうございました</h1>

            <div class="alert alert-success">
                回答を正常に受け付けました。
            </div>

            <p>
                この画面を閉じてください。
            </p>

        </div>
    </div>

    <?php
    renderFooter();
}

/* =========================================================
 * POST処理
 * ======================================================= */

function handlePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    verifyCsrf();

    $action = (string)($_POST['action'] ?? '');

    /*
     * 管理者POST
     */
    $adminActions = [
        'save_survey',
        'duplicate_survey',
        'delete_survey',
        'save_kintone',
        'test_kintone',
        'fetch_kintone_fields',
        'save_kintone_mapping',
        'sync_kintone',
        'save_smtp',
        'test_smtp',
        'send_test_mail',
        'send_mail',
    ];

    if (in_array($action, $adminActions, true)) {
        requireAdmin();
    }

    switch ($action) {

        case 'login':
            handleLogin();
            break;

        case 'logout':
            unset(
                $_SESSION['admin_authenticated'],
                $_SESSION['admin_user']
            );

            /*
             * セッションIDも再生成。
             */
            session_regenerate_id(true);

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            redirect(['screen'=>'login']);
            break;

        case 'save_survey':
            handleSaveSurvey();
            break;

        case 'duplicate_survey':
            handleDuplicateSurvey();
            break;

        case 'delete_survey':
            handleDeleteSurvey();
            break;

        case 'save_kintone':
            handleSaveKintone();
            break;

        case 'test_kintone':
            handleTestKintone();
            break;

        case 'fetch_kintone_fields':
            handleFetchKintoneFields();
            break;

        case 'save_kintone_mapping':
            handleSaveKintoneMapping();
            break;

        case 'sync_kintone':
            handleSyncKintone();
            break;

        case 'save_smtp':
            handleSaveSmtp();
            break;

        case 'test_smtp':
            handleTestSmtp();
            break;

        case 'send_test_mail':
            handleSendTestMail();
            break;

        case 'send_mail':
            handleSendMail();
            break;

        case 'save_answer_draft':
            handleSaveAnswerDraft();
            break;

        case 'submit_answer':
            handleSubmitAnswer();
            break;

        default:
            flash('error', '不明な操作です。');
            returnToList();
    }
}

function handleSaveSurvey(): void
{
    $id = trim((string)($_POST['id'] ?? ''));

    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $startAt = trim((string)($_POST['startAt'] ?? ''));
    $endAt = trim((string)($_POST['endAt'] ?? ''));
    $numbering = (string)($_POST['numbering'] ?? 'global');

    if ($title === '') {
        flash('error', 'アンケートタイトルは必須です。');
        redirect(
            $id !== ''
                ? ['screen'=>'edit','id'=>$id]
                : ['screen'=>'edit']
        );
    }

    if (!in_array($numbering, ['global','group'], true)) {
        flash('error', '採番方式が不正です。');
        redirect(
            $id !== ''
                ? ['screen'=>'edit','id'=>$id]
                : ['screen'=>'edit']
        );
    }

    $startTime = strtotime($startAt);
    $endTime = strtotime($endAt);

    if ($startTime === false || $endTime === false) {
        flash('error', '日時が正しくありません。');
        redirect(
            $id !== ''
                ? ['screen'=>'edit','id'=>$id]
                : ['screen'=>'edit']
        );
    }

    if ($endTime <= $startTime) {
        flash('error', '終了日時は開始日時より後にしてください。');
        redirect(
            $id !== ''
                ? ['screen'=>'edit','id'=>$id]
                : ['screen'=>'edit']
        );
    }

    $groupsPost = $_POST['groups'] ?? [];

    if (!is_array($groupsPost)) {
        $groupsPost = [];
    }

    $groups = [];

    foreach ($groupsPost as $groupPost) {

        if (!is_array($groupPost)) {
            continue;
        }

        $groupId = trim((string)($groupPost['id'] ?? ''));

        if ($groupId === '') {
            $groupId = generateId('group');
        }

        $groupTitle = trim(
            (string)($groupPost['title'] ?? '')
        );

        if ($groupTitle === '') {
            $groupTitle = '無題のグループ';
        }

        $questionList = [];

        foreach (($groupPost['questions'] ?? []) as $questionPost) {

            if (!is_array($questionPost)) {
                continue;
            }

            $questionId = trim(
                (string)($questionPost['id'] ?? '')
            );

            if ($questionId === '') {
                $questionId = generateId('question');
            }

            $type = (string)($questionPost['type'] ?? 'single');

            if (!validQuestionType($type)) {
                $type = 'single';
            }

            $options = $questionPost['options'] ?? [];

            if (!is_array($options)) {
                $options = [];
            }

            $options = array_values(array_filter(
                array_map(
                    static fn($value): string => trim((string)$value),
                    $options
                ),
                static fn(string $value): bool => $value !== ''
            ));

            if ($type !== 'text' && !$options) {
                $options = ['選択肢1', '選択肢2'];
            }

            $questionList[] = [
                'id' => $questionId,
                'text' => trim(
                    (string)($questionPost['text'] ?? '')
                ),
                'type' => $type,
                'required' => !empty($questionPost['required']),
                'options' => $options,
                'branching' => [],
                'number' => '',
            ];
        }

        $groups[] = [
            'id' => $groupId,
            'title' => $groupTitle,
            'questions' => $questionList,
        ];
    }

    if (!$groups) {
        $groups = [
            [
                'id' => generateId('group'),
                'title' => '基本情報',
                'questions' => [],
            ],
        ];
    }

    if ($id === '') {
        $survey = emptySurvey();
        $survey['id'] = generateId('survey');
        $survey['createdAt'] = now();
        $survey['status'] = 'draft';
    } else {
        $survey = findSurvey($id);

        if ($survey === null) {
            flash('error', 'アンケートが見つかりません。');
            returnToList();
        }

        /*
         * 終了状態は手動変更不可。
         */
        if ($survey['status'] === 'ended') {
            $status = 'ended';
        } else {
            $status = (string)($_POST['status'] ?? 'draft');

            if (!in_array(
                $status,
                ['draft','published','stopped'],
                true
            )) {
                $status = $survey['status'];
            }
        }

        $survey['status'] = $status;
    }

    $survey['title'] = $title;
    $survey['description'] = $description;
    $survey['startAt'] = date(
        'Y-m-d H:i:s',
        $startTime
    );
    $survey['endAt'] = date(
        'Y-m-d H:i:s',
        $endTime
    );
    $survey['numbering'] = $numbering;
    $survey['groups'] = $groups;
    $survey['updatedAt'] = now();

    recalculateNumbers($survey);

    $all = surveys();

    $found = false;

    foreach ($all as $index => $existing) {
        if (($existing['id'] ?? '') === $survey['id']) {
            $all[$index] = $survey;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $all[] = $survey;
    }

    saveSurveys($all);

    flash('success', 'アンケートを保存しました。');
    redirect(['screen'=>'list']);
}

function handleDuplicateSurvey(): void
{
    $id = (string)($_POST['id'] ?? '');
    $survey = findSurvey($id);

    if ($survey === null) {
        flash('error', 'アンケートが見つかりません。');
        returnToList();
    }

    $survey['id'] = generateId('survey');
    $survey['title'] .= '（コピー）';
    $survey['status'] = 'draft';
    $survey['createdAt'] = now();
    $survey['updatedAt'] = now();

    foreach ($survey['groups'] as &$group) {
        $group['id'] = generateId('group');

        foreach ($group['questions'] as &$question) {
            $question['id'] = generateId('question');
        }
    }

    unset($group, $question);

    recalculateNumbers($survey);

    $all = surveys();
    $all[] = $survey;

    saveSurveys($all);

    flash('success', 'アンケートを複製しました。');
    redirect(['screen'=>'list']);
}

function handleDeleteSurvey(): void
{
    $id = (string)($_POST['id'] ?? '');

    if (findSurvey($id) === null) {
        flash('error', 'アンケートが見つかりません。');
        returnToList();
    }

    deleteSurvey($id);

    /*
     * 関連回答も削除。
     */
    saveResponses(array_values(array_filter(
        responses(),
        static fn(array $response): bool =>
            ($response['surveyId'] ?? '') !== $id
    )));

    /*
     * 送信履歴も削除。
     */
    saveSendLogs(array_values(array_filter(
        sendLogs(),
        static fn(array $log): bool =>
            ($log['surveyId'] ?? '') !== $id
    )));

    flash('success', 'アンケートを削除しました。');
    redirect(['screen'=>'list']);
}

/* =========================================================
 * kintone POST
 * ======================================================= */

function handleSaveKintone(): void
{
    $settings = appSettings();

    $subdomain = trim((string)($_POST['subdomain'] ?? ''));
    $appId = trim((string)($_POST['appId'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $proxy = trim((string)($_POST['proxy'] ?? ''));

    if ($subdomain === '') {
        flash('error', 'サブドメインを入力してください。');
        redirect(['screen'=>'kintone']);
    }

    if (!ctype_digit($appId) || (int)$appId < 1) {
        flash('error', 'アプリIDが不正です。');
        redirect(['screen'=>'kintone']);
    }

    if ($username === '') {
        flash('error', 'ログイン名を入力してください。');
        redirect(['screen'=>'kintone']);
    }

    if (!validateProxy($proxy)) {
        flash(
            'error',
            'Proxyはhost:port形式で入力してください。'
        );
        redirect(['screen'=>'kintone']);
    }

    $password = (string)($_POST['password'] ?? '');

    /*
     * パスワード未入力なら既存値を保持。
     */
    if ($password === '') {
        $password = (string)$settings['kintone']['password'];
    }

    if ($password === '') {
        flash('error', 'パスワードを入力してください。');
        redirect(['screen'=>'kintone']);
    }

    $settings['kintone']['subdomain'] = $subdomain;
    $settings['kintone']['appId'] = $appId;
    $settings['kintone']['username'] = $username;
    $settings['kintone']['password'] = $password;
    $settings['kintone']['proxy'] = $proxy;
    $settings['kintone']['verifySsl'] =
        !empty($_POST['verifySsl']);

    saveAppSettings($settings);

    flash('success', 'kintone設定を保存しました。');
    redirect(['screen'=>'kintone']);
}

function handleTestKintone(): void
{
    try {
        kintoneTestConnection();

        flash(
            'success',
            'kintoneへの接続に成功しました。'
        );
    } catch (Throwable $e) {
        /*
         * 認証情報は例外メッセージへ含めない設計。
         */
        flash(
            'error',
            'kintoneへの接続に失敗しました。'
            . '設定、Proxy、SSL証明書、ネットワークを確認してください。'
        );
    }

    redirect(['screen'=>'kintone']);
}

function handleFetchKintoneFields(): void
{
    try {
        $_SESSION['kintone_fields'] = kintoneFields();

        flash(
            'success',
            'kintoneから項目一覧を取得しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'kintoneの項目一覧取得に失敗しました。'
        );
    }

    redirect(['screen'=>'kintone']);
}

function handleSaveKintoneMapping(): void
{
    $settings = appSettings();

    $mapping = $_POST['mapping'] ?? [];

    if (!is_array($mapping)) {
        $mapping = [];
    }

    $settings['kintone']['fieldMapping'] = [
        'organization' => trim(
            (string)($mapping['organization'] ?? '')
        ),
        'name' => trim(
            (string)($mapping['name'] ?? '')
        ),
        'email' => trim(
            (string)($mapping['email'] ?? '')
        ),
        'department' => trim(
            (string)($mapping['department'] ?? '')
        ),
        'phone' => trim(
            (string)($mapping['phone'] ?? '')
        ),
        'address' => is_array($mapping['address'] ?? null)
            ? array_values(array_map(
                'strval',
                $mapping['address']
            ))
            : [],
    ];

    saveAppSettings($settings);

    flash('success', '顧客情報マッピングを保存しました。');
    redirect(['screen'=>'kintone']);
}

function handleSyncKintone(): void
{
    try {
        $records = kintoneRecords();
        $normalized = normalizeKintoneCustomers($records);

        saveCustomers($normalized);

        flash(
            'success',
            count($normalized)
            . '件の顧客情報をkintoneから同期しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'kintoneから顧客情報を同期できませんでした。'
        );
    }

    redirect(['screen'=>'kintone']);
}

/* =========================================================
 * SMTP POST
 * ======================================================= */

function handleSaveSmtp(): void
{
    $settings = appSettings();

    $host = trim((string)($_POST['host'] ?? ''));
    $port = (int)($_POST['port'] ?? 0);
    $encryption = (string)($_POST['encryption'] ?? 'tls');
    $auth = !empty($_POST['auth']);
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $from = trim((string)($_POST['from'] ?? ''));
    $fromName = trim((string)($_POST['fromName'] ?? ''));
    $replyTo = trim((string)($_POST['replyTo'] ?? ''));

    if ($host === '') {
        flash('error', 'SMTPサーバを入力してください。');
        redirect(['screen'=>'mail']);
    }

    if ($port < 1 || $port > 65535) {
        flash('error', 'SMTPポートが不正です。');
        redirect(['screen'=>'mail']);
    }

    if (!in_array(
        $encryption,
        ['ssl','tls','none'],
        true
    )) {
        flash('error', '暗号化方式が不正です。');
        redirect(['screen'=>'mail']);
    }

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        flash(
            'error',
            '送信元メールアドレスが不正です。'
        );
        redirect(['screen'=>'mail']);
    }

    if (
        $replyTo !== ''
        && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)
    ) {
        flash(
            'error',
            '返信先メールアドレスが不正です。'
        );
        redirect(['screen'=>'mail']);
    }

    if ($password === '') {
        $password = (string)$settings['smtp']['password'];
    }

    $settings['smtp'] = [
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'from' => $from,
        'fromName' => $fromName,
        'replyTo' => $replyTo,
    ];

    saveAppSettings($settings);

    flash('success', 'SMTP設定を保存しました。');
    redirect(['screen'=>'mail']);
}

function handleTestSmtp(): void
{
    try {
        smtpTest();

        flash(
            'success',
            'SMTPサーバへの接続に成功しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'SMTPサーバへの接続に失敗しました。'
        );
    }

    redirect(['screen'=>'mail']);
}

function handleSendTestMail(): void
{
    $to = trim((string)($_POST['test_to'] ?? ''));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash(
            'error',
            '送信先メールアドレスが不正です。'
        );
        redirect(['screen'=>'mail']);
    }

    try {
        smtpSend(
            appSettings()['smtp'],
            $to,
            'アンケートアプリ テストメール',
            "SMTP接続テストです。\n\n"
            . "このメールは実際のSMTPサーバから送信されています。"
        );

        flash(
            'success',
            'テストメールを送信しました。'
        );
    } catch (Throwable $e) {
        flash(
            'error',
            'テストメールの送信に失敗しました。'
        );
    }

    redirect(['screen'=>'mail']);
}

/* =========================================================
 * アンケートメール送信
 * ======================================================= */

function publicAnswerUrl(string $surveyId): string
{
    $scheme = $GLOBALS['secureCookie'] ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    /*
     * HostヘッダーをURLにそのまま信用しない。
     * 本番ではAPP_PUBLIC_URLを設定する。
     */
    $configured = envValue('APP_PUBLIC_URL');

    if ($configured !== '') {
        return rtrim($configured, '/')
            . '/index.php?screen=answer&id='
            . rawurlencode($surveyId);
    }

    return $scheme
        . '://'
        . preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $host)
        . baseUrl()
        . '?screen=answer&id='
        . rawurlencode($surveyId);
}

function handleSendMail(): void
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));
    $survey = findSurvey($surveyId);

    if ($survey === null) {
        flash('error', 'アンケートが見つかりません。');
        returnToList();
    }

    /*
     * 送信画面の対象アンケートをPOSTでも再確認。
     */
    if ($survey['id'] !== $surveyId) {
        flash('error', '対象アンケートが不正です。');
        returnToList();
    }

    $customerIds = $_POST['customer_ids'] ?? [];

    if (!is_array($customerIds)) {
        $customerIds = [];
    }

    $subject = trim((string)($_POST['subject'] ?? ''));
    $bodyTemplate = trim((string)($_POST['body'] ?? ''));

    if ($subject === '' || $bodyTemplate === '') {
        flash(
            'error',
            '件名と本文は必須です。'
        );
        redirect([
            'screen'=>'send',
            'id'=>$surveyId,
        ]);
    }

    if (!$customerIds) {
        flash(
            'error',
            '送信対象の顧客を選択してください。'
        );
        redirect([
            'screen'=>'send',
            'id'=>$surveyId,
        ]);
    }

    $customerMap = [];

    foreach (customers() as $customer) {
        $customerMap[(string)$customer['id']] = $customer;
    }

    $logs = sendLogs();

    $success = 0;
    $failure = 0;

    foreach ($customerIds as $customerId) {

        $customerId = (string)$customerId;

        if (!isset($customerMap[$customerId])) {
            continue;
        }

        $customer = $customerMap[$customerId];
        $email = trim((string)($customer['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $logs[] = [
                'id' => generateId('send'),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'customerName' => $customer['name'] ?? '',
                'email' => $email,
                'status' => 'failed',
                'type' => 'initial',
                'createdAt' => now(),
            ];

            $failure++;
            continue;
        }

        $publicUrl = publicAnswerUrl($surveyId);

        $subjectForCustomer = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [
                (string)($customer['name'] ?? ''),
                $publicUrl,
            ],
            $subject
        );

        $bodyForCustomer = str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [
                (string)($customer['name'] ?? ''),
                $publicUrl,
            ],
            $bodyTemplate
        );

        try {
            smtpSend(
                appSettings()['smtp'],
                $email,
                $subjectForCustomer,
                $bodyForCustomer
            );

            $logs[] = [
                'id' => generateId('send'),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'customerName' => $customer['name'] ?? '',
                'email' => $email,
                'status' => 'sent',
                'type' => 'initial',
                'createdAt' => now(),
            ];

            $success++;
        } catch (Throwable $e) {

            $logs[] = [
                'id' => generateId('send'),
                'surveyId' => $surveyId,
                'customerId' => $customerId,
                'customerName' => $customer['name'] ?? '',
                'email' => $email,
                'status' => 'failed',
                'type' => 'initial',
                'createdAt' => now(),
            ];

            $failure++;
        }
    }

    saveSendLogs($logs);

    flash(
        'success',
        '送信処理完了：成功 '
        . $success
        . '件 / 失敗 '
        . $failure
        . '件'
    );

    /*
     * 別画面へ遷移せず同じ送信画面へ戻る。
     */
    redirect([
        'screen'=>'send',
        'id'=>$surveyId,
    ]);
}

/* =========================================================
 * 回答POST
 * ======================================================= */

function handleSaveAnswerDraft(): void
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));
    $survey = findSurvey($surveyId);

    if ($survey === null) {
        renderErrorPage('アンケートが見つかりません。');
        exit;
    }

    requireAnswerSurvey($survey);

    $answers = $_POST['answers'] ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $validated = validateAnswers(
        $survey,
        $answers
    );

    if (!$validated['ok']) {
        flash(
            'error',
            $validated['message']
        );

        $_SESSION['answer_draft'][$surveyId] =
            $answers;

        redirect([
            'screen'=>'answer',
            'id'=>$surveyId,
        ]);
    }

    $_SESSION['answer_draft'][$surveyId] =
        $answers;

    redirect([
        'screen'=>'confirm',
        'id'=>$surveyId,
    ]);
}

function validateAnswers(
    array $survey,
    array $answers
): array {
    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $question) {

            $questionId = $question['id'];
            $answer = $answers[$questionId] ?? '';

            if (!empty($question['required'])) {

                $empty = false;

                if (is_array($answer)) {
                    $empty = count(
                        array_filter(
                            $answer,
                            static fn($v): bool =>
                                trim((string)$v) !== ''
                        )
                    ) === 0;
                } else {
                    $empty = trim((string)$answer) === '';
                }

                if ($empty) {
                    return [
                        'ok' => false,
                        'message' =>
                            $question['number']
                            . '「'
                            . $question['text']
                            . '」は必須です。',
                    ];
                }
            }

            /*
             * 選択肢は定義された値のみ受け付ける。
             */
            if (
                in_array(
                    $question['type'],
                    ['single','multiple'],
                    true
                )
            ) {
                $allowed = $question['options'];

                $values = is_array($answer)
                    ? $answer
                    : [$answer];

                foreach ($values as $value) {
                    if (
                        $value !== ''
                        && !in_array(
                            $value,
                            $allowed,
                            true
                        )
                    ) {
                        return [
                            'ok' => false,
                            'message' =>
                                '不正な選択肢が送信されました。',
                        ];
                    }
                }
            }
        }
    }

    return ['ok'=>true,'message'=>''];
}

function handleSubmitAnswer(): void
{
    $surveyId = trim((string)($_POST['surveyId'] ?? ''));
    $survey = findSurvey($surveyId);

    if ($survey === null) {
        renderErrorPage('アンケートが見つかりません。');
        exit;
    }

    requireAnswerSurvey($survey);

    $answers =
        $_SESSION['answer_draft'][$surveyId]
        ?? [];

    if (!is_array($answers)) {
        $answers = [];
    }

    $validated = validateAnswers(
        $survey,
        $answers
    );

    if (!$validated['ok']) {
        flash(
            'error',
            $validated['message']
        );

        redirect([
            'screen'=>'answer',
            'id'=>$surveyId,
        ]);
    }

    /*
     * 回答者はログインしていないため、
     * 回答そのものに管理者セッションを利用しない。
     */
    $response = [
        'id' => generateId('response'),
        'surveyId' => $surveyId,
        'customerId' => '',
        'createdAt' => now(),
        'answers' => $answers,
    ];

    $all = responses();
    $all[] = $response;

    saveResponses($all);

    unset(
        $_SESSION['answer_draft'][$surveyId]
    );

    /*
     * 完了画面へ一方向に遷移。
     */
    redirect([
        'screen'=>'complete',
        'id'=>$surveyId,
    ]);
}

/* =========================================================
 * ルーティング
 * ======================================================= */

refreshAllStatuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost();
}

$screen = (string)($_GET['screen'] ?? 'list');

/*
 * 回答者画面は管理者認証とは独立。
 */
$publicScreens = [
    'answer',
    'confirm',
    'complete',
];

if (
    !in_array($screen, $publicScreens, true)
    && $screen !== 'login'
    && !isAdmin()
) {
    /*
     * 未認証 → login の一方向のみ。
     * loginからlistへ戻す逆方向は
     * login処理成功時だけ。
     */
    if ($screen !== 'login') {
        redirect(['screen'=>'login']);
    }
}

switch ($screen) {

    case 'login':

        if (isAdmin()) {
            /*
             * ログイン済みでloginに来た場合だけ一覧へ。
             * login → list → login のループにはならない。
             */
            redirect(['screen'=>'list']);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            /*
             * handlePostでlogin処理済み。
             */
        }

        renderHeader('管理者ログイン', false);
        ?>

        <div class="login-box">
            <div class="card">

                <h1>管理者ログイン</h1>

                <?php if (!adminPasswordConfigured()): ?>

                    <div class="alert alert-error">
                        SURVEY_ADMIN_PASSWORD が設定されていません。
                    </div>

                <?php endif; ?>

                <form method="post">

                    <?= csrfField() ?>

                    <input type="hidden"
                           name="action"
                           value="login">

                    <div class="field">
                        <label>ユーザー名</label>
                        <input
                            type="text"
                            name="username"
                            autocomplete="username"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>パスワード</label>
                        <input
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <button
                        class="btn btn-primary"
                        style="width:100%"
                        type="submit">
                        ログイン
                    </button>

                </form>

            </div>
        </div>

        <?php
        renderFooter();
        break;

    case 'list':
        renderList();
        break;

    case 'edit':
        renderEdit();
        break;

    case 'preview':
        renderPreview();
        break;

    case 'send':
        renderSend();
        break;

    case 'analytics':
        renderAnalytics();
        break;

    case 'analytics_csv':

        requireAdmin();

        $survey = findSurvey(
            (string)($_GET['id'] ?? '')
        );

        if ($survey === null) {
            returnToList();
        }

        outputAnalyticsCsv($survey);
        break;

    case 'analytics_pdf':

        requireAdmin();

        $survey = findSurvey(
            (string)($_GET['id'] ?? '')
        );

        if ($survey === null) {
            returnToList();
        }

        outputAnalyticsPdf($survey);
        break;

    case 'kintone':
        renderKintone();
        break;

    case 'mail':
        renderMail();
        break;

    case 'answer':
        renderAnswer();
        break;

    case 'confirm':
        renderConfirm();
        break;

    case 'complete':
        renderComplete();
        break;

    case 'logout':

        requireAdmin();

        /*
         * GETでログアウト操作を行う仕様を避け、
         * 実際にはログアウトリンクをPOSTにしてもよい。
         *
         * 現在は画面上のリンクから来るため、
         * CSRF対策済みのPOST版へ転送する。
         */
        renderHeader('ログアウト');
        ?>

        <div class="card narrow">

            <h1>ログアウト</h1>

            <form method="post">

                <?= csrfField() ?>

                <input type="hidden"
                       name="action"
                       value="logout">

                <button class="btn btn-danger">
                    ログアウト
                </button>

            </form>

        </div>

        <?php
        renderFooter();
        break;

    default:

        /*
         * 不明なscreenを無条件に自己リダイレクトしない。
         * 認証済みなら一覧、未認証ならlogin。
         */
        if (isAdmin()) {
            redirect(['screen'=>'list']);
        }

        redirect(['screen'=>'login']);
}
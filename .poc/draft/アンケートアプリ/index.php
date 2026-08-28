<?php
declare(strict_types=1);

/**
 * アンケート管理システム
 *
 * Apache 2.4 / PHP 8.5
 * DBなし / PHP cURLなし / PHP mail()なし
 * 単一エントリーポイント index.php
 *
 * 画面:
 * list
 * edit
 * preview
 * send
 * analytics
 * kintone
 * mail
 * answer
 * confirm
 * complete
 *
 * 重要:
 * - 管理者認証なし（POC）
 * - 回答者画面と管理者画面を分離
 * - サーバー側JSON永続化
 * - kintone API通信はPHP streamで実施
 * - SMTP通信もPHP streamで実施
 * - 動的な質問・グループ操作はJavaScript + 保存時PHP再構成
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理システム';
const DATA_DIR_NAME = 'data';

$APP_DIR  = __DIR__;
$DATA_DIR = $APP_DIR . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

if (!is_dir($DATA_DIR)) {
    if (!@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/* ============================================================
 * Session
 * ============================================================ */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    $cookiePath = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $cookiePath = rtrim($cookiePath, '/');

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

    if (!session_start()) {
        http_response_code(500);
        exit('セッションを利用できません。');
    }
}

/* ============================================================
 * Common
 * ============================================================ */

function h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function now_iso(): string
{
    return date('Y-m-d H:i:s');
}

function now_input(): string
{
    return date('Y-m-d\TH:i');
}

function get_string(string $key): string
{
    return isset($_GET[$key]) && is_scalar($_GET[$key])
        ? trim((string)$_GET[$key])
        : '';
}

function post_string(string $key): string
{
    return isset($_POST[$key]) && is_scalar($_POST[$key])
        ? trim((string)$_POST[$key])
        : '';
}

function post_array(string $key): array
{
    $value = $_POST[$key] ?? [];

    if (!is_array($value)) {
        return [];
    }

    $result = [];

    foreach ($value as $v) {
        if (is_scalar($v)) {
            $result[] = trim((string)$v);
        }
    }

    return array_values(array_unique($result));
}

function app_url(array $params = []): string
{
    if (!$params) {
        return 'index.php';
    }

    return 'index.php?' . http_build_query($params);
}

function safe_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,120}$/', $id);
}

function new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function data_file(string $name): string
{
    global $DATA_DIR;
    return $DATA_DIR . DIRECTORY_SEPARATOR . $name;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function redirect_to(array $params): never
{
    header('Location: ' . app_url($params), true, 303);
    exit;
}

/* ============================================================
 * JSON persistence
 * ============================================================ */

function json_read(string $file, mixed $default): mixed
{
    if (!is_file($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);

    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return $default;
    }

    return $decoded;
}

function json_write(string $file, mixed $data): bool
{
    $dir = dirname($file);

    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        return false;
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/* ============================================================
 * Data
 * ============================================================ */

function load_surveys(): array
{
    $data = json_read(data_file('surveys.json'), []);
    return is_array($data) ? $data : [];
}

function save_surveys(array $surveys): bool
{
    return json_write(
        data_file('surveys.json'),
        array_values($surveys)
    );
}

function load_customers(): array
{
    $data = json_read(data_file('customers.json'), []);
    return is_array($data) ? $data : [];
}

function save_customers(array $customers): bool
{
    return json_write(
        data_file('customers.json'),
        array_values($customers)
    );
}

function load_answers(): array
{
    $data = json_read(data_file('answers.json'), []);
    return is_array($data) ? $data : [];
}

function save_answers(array $answers): bool
{
    return json_write(
        data_file('answers.json'),
        array_values($answers)
    );
}

function load_history(): array
{
    $data = json_read(data_file('send_history.json'), []);
    return is_array($data) ? $data : [];
}

function save_history(array $history): bool
{
    return json_write(
        data_file('send_history.json'),
        array_values($history)
    );
}

/* ============================================================
 * Secret storage
 * ============================================================ */

function encryption_key(): string
{
    global $DATA_DIR;

    $env = getenv('APP_ENCRYPTION_KEY');

    if (is_string($env) && strlen($env) >= 32) {
        return hash('sha256', $env, true);
    }

    $file = $DATA_DIR . DIRECTORY_SEPARATOR . '.key';

    if (is_file($file)) {
        $key = @file_get_contents($file);

        if (is_string($key) && strlen($key) >= 32) {
            return hash('sha256', $key, true);
        }
    }

    $key = bin2hex(random_bytes(32));

    if (@file_put_contents($file, $key, LOCK_EX) === false) {
        throw new RuntimeException('暗号化キーを保存できません。');
    }

    @chmod($file, 0600);

    return hash('sha256', $key, true);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = encryption_key();
    $iv = random_bytes(16);

    $cipher = openssl_encrypt(
        $plain,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($cipher === false) {
        throw new RuntimeException('秘密情報を暗号化できません。');
    }

    $mac = hash_hmac(
        'sha256',
        $iv . $cipher,
        $key,
        true
    );

    return base64_encode($iv . $mac . $cipher);
}

function decrypt_secret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    $raw = base64_decode($encoded, true);

    if ($raw === false || strlen($raw) < 48) {
        return '';
    }

    $iv = substr($raw, 0, 16);
    $mac = substr($raw, 16, 32);
    $cipher = substr($raw, 48);

    $key = encryption_key();

    $expected = hash_hmac(
        'sha256',
        $iv . $cipher,
        $key,
        true
    );

    if (!hash_equals($mac, $expected)) {
        return '';
    }

    $plain = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    return is_string($plain) ? $plain : '';
}

/* ============================================================
 * kintone config
 * ============================================================ */

function load_kintone(): array
{
    $data = json_read(data_file('kintone.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge([
        'subdomain' => '',
        'app_id' => '',
        'username' => '',
        'password_encrypted' => '',
        'proxy' => '',
        'verify_ssl' => false,
        'fields' => [],
        'mapping' => [
            'organization' => '',
            'name' => '',
            'email' => '',
            'department' => '',
            'phone' => '',
            'address' => [],
        ],
        'status' => '未設定',
        'last_test' => '',
        'last_sync' => '',
    ], $data);
}

function save_kintone(array $config): bool
{
    unset($config['password']);

    return json_write(
        data_file('kintone.json'),
        $config
    );
}

function normalize_subdomain(string $value): string
{
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    );

    $value = trim((string)$value, '/');

    $value = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $value
    );

    return trim((string)$value);
}

function kintone_host(array $config): string
{
    return normalize_subdomain(
        (string)($config['subdomain'] ?? '')
    ) . '.cybozu.com';
}

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match(
        '/^([^:\/\s]+):([0-9]{1,5})$/',
        $proxy,
        $m
    )) {
        return null;
    }

    $port = (int)$m[2];

    if ($port < 1 || $port > 65535) {
        return null;
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function validate_kintone_config(array $config): array
{
    $errors = [];

    $subdomain = normalize_subdomain(
        (string)($config['subdomain'] ?? '')
    );

    $appId = trim(
        (string)($config['app_id'] ?? '')
    );

    $username = trim(
        (string)($config['username'] ?? '')
    );

    $proxy = trim(
        (string)($config['proxy'] ?? '')
    );

    if (
        $subdomain === ''
        || !preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/',
            $subdomain
        )
    ) {
        $errors[] = 'サブドメインを正しく入力してください。';
    }

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] = '顧客管理アプリIDを正しく入力してください。';
    }

    if ($username === '') {
        $errors[] = 'ログイン名を入力してください。';
    }

    if ($proxy !== '' && parse_proxy($proxy) === null) {
        $errors[] = 'Proxyは「host:port」形式で入力してください。';
    }

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'proxy' => $proxy,
        'errors' => $errors,
    ];
}

/**
 * kintone API共通通信。
 *
 * 重要:
 * - API URLと画面遷移URLを完全に分離
 * - follow_locationを無効化
 * - HTTP statusと本文の両方を解析
 * - kintoneエラーコードを保持
 */
function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $validation = validate_kintone_config($config);

    if ($validation['errors']) {
        return [
            'ok' => false,
            'category' => '入力エラー',
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' => implode(' ', $validation['errors']),
            'data' => null,
        ];
    }

    $password = '';

    if (!empty($config['password_encrypted'])) {
        $password = decrypt_secret(
            (string)$config['password_encrypted']
        );
    }

    if (
        $password === ''
        && !empty($config['password'])
    ) {
        $password = (string)$config['password'];
    }

    if ($password === '') {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' => 'kintoneパスワードが設定されていません。',
            'data' => null,
        ];
    }

    $host = kintone_host($config);

    if (
        !preg_match(
            '/^[A-Za-z0-9-]+\.cybozu\.com$/',
            $host
        )
    ) {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' => 'kintoneサブドメインが不正です。',
            'data' => null,
        ];
    }

    $url = 'https://' . $host . $path;

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                (string)$config['username']
                . ':'
                . $password
            ),
        'Accept: application/json',
        'User-Agent: SurveyApp/2.0',
        'Connection: close',
    ];

    $content = null;

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            return [
                'ok' => false,
                'category' => 'データエラー',
                'status' => 0,
                'code' => '',
                'id' => '',
                'message' => 'JSONリクエストを生成できません。',
                'data' => null,
            ];
        }

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    $http = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'timeout' => 20,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'follow_location' => 0,
        'max_redirects' => 0,
    ];

    if ($content !== null) {
        $http['content'] = $content;
    }

    if ($proxy !== null) {
        $http['proxy'] =
            'tcp://'
            . $proxy['host']
            . ':'
            . $proxy['port'];

        $http['request_fulluri'] = true;
    }

    $verifySsl = !empty($config['verify_ssl']);

    $context = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' => $verifySsl,
            'verify_peer_name' => $verifySsl,
            'allow_self_signed' => !$verifySsl,
            'SNI_enabled' => true,
        ],
    ]);

    $error = '';

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
        $response = file_get_contents(
            $url,
            false,
            $context
        );
    } finally {
        restore_error_handler();
    }

    $headersRaw = $http_response_header ?? [];

    $status = 0;

    if (
        isset($headersRaw[0])
        && preg_match(
            '/\s(\d{3})\s/',
            $headersRaw[0],
            $m
        )
    ) {
        $status = (int)$m[1];
    }

    if ($response === false) {
        return [
            'ok' => false,
            'category' => '通信エラー',
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' => $error !== ''
                ? 'kintone通信に失敗しました: ' . $error
                : 'kintone通信に失敗しました。',
            'data' => null,
        ];
    }

    $data = json_decode(
        $response,
        true
    );

    if (
        $status >= 200
        && $status < 300
        && is_array($data)
    ) {
        return [
            'ok' => true,
            'category' => '',
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' => '',
            'data' => $data,
        ];
    }

    $code = '';
    $message = '';
    $errorId = '';

    if (is_array($data)) {
        $code = (string)($data['code'] ?? '');
        $message = (string)($data['message'] ?? '');
        $errorId = (string)($data['id'] ?? '');
    }

    if ($message === '') {
        $message = trim($response);
    }

    if ($message === '') {
        $message = 'kintoneからエラー応答を受信しました。';
    }

    return [
        'ok' => false,
        'category' => $status === 401 || $status === 403
            ? '認証エラー'
            : '外部サービスエラー',
        'status' => $status,
        'code' => $code,
        'id' => $errorId,
        'message' => $message,
        'data' => $data,
    ];
}

/* ============================================================
 * Mail config / SMTP
 * ============================================================ */

function load_mail(): array
{
    $data = json_read(data_file('mail.json'), []);

    if (!is_array($data)) {
        $data = [];
    }

    return array_merge([
        'server' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password_encrypted' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
        'status' => '未設定',
        'last_test' => '',
    ], $data);
}

function save_mail(array $config): bool
{
    unset($config['password']);

    return json_write(
        data_file('mail.json'),
        $config
    );
}

function smtp_socket(array $config): array
{
    $server = trim((string)($config['server'] ?? ''));
    $port = (int)($config['port'] ?? 0);
    $encryption = (string)($config['encryption'] ?? 'none');

    if ($server === '') {
        return [
            'ok' => false,
            'message' => 'SMTPサーバを入力してください。',
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'ok' => false,
            'message' => 'SMTPポートが不正です。',
        ];
    }

    $target = $server . ':' . $port;

    if ($encryption === 'ssl') {
        $target = 'ssl://' . $target;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        return [
            'ok' => false,
            'message' => 'SMTPサーバへ接続できません: ' . $errstr,
        ];
    }

    stream_set_timeout($socket, 15);

    return [
        'ok' => true,
        'socket' => $socket,
        'message' => '',
    ];
}

function smtp_read($socket): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (
            strlen($line) >= 4
            && $line[3] === ' '
        ) {
            break;
        }
    }

    return $response;
}

function smtp_expect($socket, array $codes): array
{
    $response = smtp_read($socket);

    $code = 0;

    if (
        preg_match(
            '/^(\d{3})/',
            $response,
            $m
        )
    ) {
        $code = (int)$m[1];
    }

    return [
        'ok' => in_array($code, $codes, true),
        'code' => $code,
        'response' => $response,
    ];
}

function smtp_command(
    $socket,
    string $command,
    array $codes
): array {
    fwrite($socket, $command . "\r\n");

    return smtp_expect(
        $socket,
        $codes
    );
}

function smtp_test(array $config): array
{
    $connection = smtp_socket($config);

    if (!$connection['ok']) {
        return $connection;
    }

    $socket = $connection['socket'];

    try {
        $greeting = smtp_expect(
            $socket,
            [220]
        );

        if (!$greeting['ok']) {
            throw new RuntimeException('SMTP greetingに失敗しました。');
        }

        $hello = smtp_command(
            $socket,
            'EHLO survey-app.local',
            [250]
        );

        if (!$hello['ok']) {
            throw new RuntimeException('EHLOに失敗しました。');
        }

        if (
            ($config['encryption'] ?? 'none') === 'tls'
        ) {
            $starttls = smtp_command(
                $socket,
                'STARTTLS',
                [220]
            );

            if (!$starttls['ok']) {
                throw new RuntimeException('STARTTLSに失敗しました。');
            }

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($crypto !== true) {
                throw new RuntimeException(
                    'TLS暗号化を開始できません。'
                );
            }

            $hello = smtp_command(
                $socket,
                'EHLO survey-app.local',
                [250]
            );

            if (!$hello['ok']) {
                throw new RuntimeException(
                    'TLS後のEHLOに失敗しました。'
                );
            }
        }

        if (!empty($config['auth'])) {
            $username = (string)($config['username'] ?? '');
            $password = '';

            if (!empty($config['password_encrypted'])) {
                $password = decrypt_secret(
                    (string)$config['password_encrypted']
                );
            }

            if (
                $password === ''
                && !empty($config['password'])
            ) {
                $password = (string)$config['password'];
            }

            if ($username === '' || $password === '') {
                throw new RuntimeException(
                    'SMTP認証情報が設定されていません。'
                );
            }

            $auth = smtp_command(
                $socket,
                'AUTH LOGIN',
                [334]
            );

            if (!$auth['ok']) {
                throw new RuntimeException(
                    'SMTP AUTH LOGINに失敗しました。'
                );
            }

            $userResult = smtp_command(
                $socket,
                base64_encode($username),
                [334]
            );

            if (!$userResult['ok']) {
                throw new RuntimeException(
                    'SMTPユーザー名認証に失敗しました。'
                );
            }

            $passResult = smtp_command(
                $socket,
                base64_encode($password),
                [235]
            );

            if (!$passResult['ok']) {
                throw new RuntimeException(
                    'SMTPパスワード認証に失敗しました。'
                );
            }
        }

        smtp_command(
            $socket,
            'QUIT',
            [221, 250]
        );

        fclose($socket);

        return [
            'ok' => true,
            'message' => 'SMTP接続・認証に成功しました。',
        ];
    } catch (Throwable $e) {
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);

        return [
            'ok' => false,
            'message' => $e->getMessage(),
        ];
    }
}

/* ============================================================
 * Survey model
 * ============================================================ */

function status_label(string $status): string
{
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_class(string $status): string
{
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'draft',
    };
}

function refresh_survey_status(array &$survey): bool
{
    if (
        ($survey['status'] ?? 'draft') === 'published'
        && !empty($survey['endAt'])
    ) {
        $end = strtotime(
            (string)$survey['endAt']
        );

        if (
            $end !== false
            && $end < time()
        ) {
            $survey['status'] = 'ended';
            $survey['updatedAt'] = now_iso();
            return true;
        }
    }

    return false;
}

function refresh_all_statuses(array &$surveys): void
{
    $changed = false;

    foreach ($surveys as &$survey) {
        if (refresh_survey_status($survey)) {
            $changed = true;
        }
    }

    unset($survey);

    if ($changed) {
        save_surveys($surveys);
    }
}

function find_survey(
    array $surveys,
    string $id
): ?array {
    foreach ($surveys as $survey) {
        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            return $survey;
        }
    }

    return null;
}

function survey_index(
    array $surveys,
    string $id
): int {
    foreach ($surveys as $index => $survey) {
        if (
            (string)($survey['id'] ?? '')
            === $id
        ) {
            return $index;
        }
    }

    return -1;
}

function new_question(): array
{
    return [
        'id' => new_id('q'),
        'number' => '',
        'text' => '',
        'type' => 'single',
        'required' => true,
        'options' => [
            '選択肢1',
            '選択肢2',
        ],
        'branching' => [],
    ];
}

function new_group(): array
{
    return [
        'id' => new_id('g'),
        'title' => '新しいグループ',
        'questions' => [
            new_question(),
        ],
    ];
}

function new_survey(): array
{
    return [
        'id' => new_id('survey'),
        'title' => '新しいアンケート',
        'description' => '',
        'startAt' => now_input(),
        'endAt' => '',
        'numbering' => 'global',
        'status' => 'draft',
        'createdAt' => now_iso(),
        'updatedAt' => now_iso(),
        'groups' => [
            [
                'id' => new_id('g'),
                'title' => '基本情報',
                'questions' => [
                    new_question(),
                ],
            ],
        ],
    ];
}

function recalc_question_numbers(
    array &$survey
): void {
    $mode = ($survey['numbering'] ?? 'global');

    $globalNo = 1;
    $groupNo = 1;

    foreach (
        $survey['groups'] as &$group
    ) {
        $questionNo = 1;

        foreach (
            $group['questions'] as &$question
        ) {
            if ($mode === 'group') {
                $question['number'] =
                    'Q'
                    . $groupNo
                    . '-'
                    . $questionNo;
            } else {
                $question['number'] =
                    'Q'
                    . $globalNo;
            }

            $questionNo++;
            $globalNo++;
        }

        unset($question);

        $groupNo++;
    }

    unset($group);
}

/**
 * POSTされた編集画面のDOM構造を、
 * サーバー側の正規データ構造へ再構成する。
 *
 * これにより、
 * - 既存グループ
 * - 新規グループ
 * - 既存質問
 * - 新規質問
 * - 並び順
 * - 削除済み要素
 * を一つのルールで処理する。
 */
function build_survey_from_post(
    array $oldSurvey
): array {
    $survey = $oldSurvey;

    $survey['title'] =
        post_string('title');

    $survey['description'] =
        post_string('description');

    $survey['startAt'] =
        post_string('startAt');

    $survey['endAt'] =
        post_string('endAt');

    $numbering =
        post_string('numbering');

    if (
        !in_array(
            $numbering,
            ['global', 'group'],
            true
        )
    ) {
        $numbering = 'global';
    }

    $survey['numbering'] =
        $numbering;

    $oldGroups = [];

    foreach (
        $oldSurvey['groups'] ?? []
        as $group
    ) {
        if (!empty($group['id'])) {
            $oldGroups[
                (string)$group['id']
            ] = $group;
        }
    }

    $oldQuestions = [];

    foreach (
        $oldSurvey['groups'] ?? []
        as $group
    ) {
        foreach (
            $group['questions'] ?? []
            as $question
        ) {
            if (!empty($question['id'])) {
                $oldQuestions[
                    (string)$question['id']
                ] = $question;
            }
        }
    }

    $groupOrder =
        post_array('group_order');

    $groupTitles =
        isset($_POST['group_title'])
        && is_array($_POST['group_title'])
            ? $_POST['group_title']
            : [];

    $questionOrder =
        isset($_POST['question_order'])
        && is_array($_POST['question_order'])
            ? $_POST['question_order']
            : [];

    $questionText =
        isset($_POST['question_text'])
        && is_array($_POST['question_text'])
            ? $_POST['question_text']
            : [];

    $questionType =
        isset($_POST['question_type'])
        && is_array($_POST['question_type'])
            ? $_POST['question_type']
            : [];

    $questionRequired =
        isset($_POST['question_required'])
        && is_array($_POST['question_required'])
            ? $_POST['question_required']
            : [];

    $questionOptions =
        isset($_POST['question_option'])
        && is_array($_POST['question_option'])
            ? $_POST['question_option']
            : [];

    $branching =
        isset($_POST['branching'])
        && is_array($_POST['branching'])
            ? $_POST['branching']
            : [];

    $questionsByGroup =
        isset($_POST['questions_by_group'])
        && is_array($_POST['questions_by_group'])
            ? $_POST['questions_by_group']
            : [];

    $newGroups = [];

    foreach ($groupOrder as $groupId) {
        $groupId = trim((string)$groupId);

        if (
            $groupId === ''
            || !safe_id($groupId)
        ) {
            continue;
        }

        $oldGroup =
            $oldGroups[$groupId]
            ?? [
                'id' => $groupId,
                'title' => '新しいグループ',
                'questions' => [],
            ];

        $title =
            isset($groupTitles[$groupId])
            && is_scalar($groupTitles[$groupId])
                ? trim(
                    (string)$groupTitles[$groupId]
                )
                : '';

        if ($title === '') {
            $title = '無題のグループ';
        }

        $group = [
            'id' => $groupId,
            'title' => mb_substr(
                $title,
                0,
                200
            ),
            'questions' => [],
        ];

        $order = [];

        if (
            isset($questionsByGroup[$groupId])
            && is_array(
                $questionsByGroup[$groupId]
            )
        ) {
            foreach (
                $questionsByGroup[$groupId]
                as $questionId
            ) {
                if (is_scalar($questionId)) {
                    $order[] =
                        trim((string)$questionId);
                }
            }
        }

        if (!$order) {
            foreach (
                $questionOrder[$groupId] ?? []
                as $questionId
            ) {
                if (is_scalar($questionId)) {
                    $order[] =
                        trim((string)$questionId);
                }
            }
        }

        foreach ($order as $questionId) {
            if (
                $questionId === ''
                || !safe_id($questionId)
            ) {
                continue;
            }

            $oldQuestion =
                $oldQuestions[$questionId]
                ?? new_question();

            $oldQuestion['id'] =
                $questionId;

            $text =
                isset($questionText[$questionId])
                && is_scalar(
                    $questionText[$questionId]
                )
                    ? trim(
                        (string)$questionText[$questionId]
                    )
                    : '';

            $type =
                isset($questionType[$questionId])
                && is_scalar(
                    $questionType[$questionId]
                )
                    ? (string)$questionType[$questionId]
                    : 'single';

            if (
                !in_array(
                    $type,
                    ['single', 'multiple', 'text'],
                    true
                )
            ) {
                $type = 'single';
            }

            $required =
                isset(
                    $questionRequired[$questionId]
                )
                && (
                    (string)
                    $questionRequired[$questionId]
                ) === '1';

            $options = [];

            if (
                isset(
                    $questionOptions[$questionId]
                )
                && is_array(
                    $questionOptions[$questionId]
                )
            ) {
                foreach (
                    $questionOptions[$questionId]
                    as $option
                ) {
                    if (is_scalar($option)) {
                        $option =
                            trim((string)$option);

                        if ($option !== '') {
                            $options[] =
                                mb_substr(
                                    $option,
                                    0,
                                    500
                                );
                        }
                    }
                }
            }

            if (
                in_array(
                    $type,
                    ['single', 'multiple'],
                    true
                )
                && !$options
            ) {
                $options = [
                    '選択肢1',
                    '選択肢2',
                ];
            }

            $branch =
                isset($branching[$questionId])
                && is_scalar(
                    $branching[$questionId]
                )
                    ? trim(
                        (string)$branching[$questionId]
                    )
                    : '';

            $oldQuestion['text'] =
                mb_substr(
                    $text,
                    0,
                    500
                );

            $oldQuestion['type'] =
                $type;

            $oldQuestion['required'] =
                $required;

            $oldQuestion['options'] =
                $options;

            $oldQuestion['branching'] =
                $branch === ''
                    ? []
                    : ['default' => $branch];

            $group['questions'][] =
                $oldQuestion;
        }

        $newGroups[] =
            $group;
    }

    if (!$newGroups) {
        throw new InvalidArgumentException(
            'グループを1つ以上設定してください。'
        );
    }

    $survey['groups'] =
        $newGroups;

    recalc_question_numbers($survey);

    $survey['updatedAt'] =
        now_iso();

    return $survey;
}

/* ============================================================
 * POST actions
 * ============================================================ */

$screen =
    get_string('screen');

if ($screen === '') {
    $screen = 'list';
}

$id =
    get_string('id');

$surveys =
    load_surveys();

$customers =
    load_customers();

$answers =
    load_answers();

$history =
    load_history();

$kintone =
    load_kintone();

$mail =
    load_mail();

refresh_all_statuses($surveys);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =
        post_string('action');

    try {
        switch ($action) {

            /* ------------------------------------------------
             * Survey save
             * ------------------------------------------------ */

            case 'save_survey':
                $surveyId =
                    post_string('survey_id');

                $existing =
                    $surveyId !== ''
                        ? find_survey(
                            $surveys,
                            $surveyId
                        )
                        : null;

                if ($existing === null) {
                    $survey =
                        new_survey();

                    if ($surveyId !== '') {
                        $survey['id'] =
                            $surveyId;
                    }
                } else {
                    $survey =
                        $existing;
                }

                $survey =
                    build_survey_from_post(
                        $survey
                    );

                if (
                    trim(
                        (string)$survey['title']
                    ) === ''
                ) {
                    throw new InvalidArgumentException(
                        'アンケートタイトルを入力してください。'
                    );
                }

                $index =
                    survey_index(
                        $surveys,
                        (string)$survey['id']
                    );

                if ($index >= 0) {
                    $survey['status'] =
                        $surveys[$index]['status']
                        ?? 'draft';

                    $survey['createdAt'] =
                        $surveys[$index]['createdAt']
                        ?? now_iso();

                    $surveys[$index] =
                        $survey;
                } else {
                    $survey['status'] =
                        'draft';

                    $survey['createdAt'] =
                        now_iso();

                    $surveys[] =
                        $survey;
                }

                if (!save_surveys($surveys)) {
                    throw new RuntimeException(
                        'アンケートを保存できません。'
                    );
                }

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                redirect_to([
                    'screen' => 'list',
                ]);
                break;

            /* ------------------------------------------------
             * Status change
             *
             * 編集画面からも利用できる。
             * ------------------------------------------------ */

            case 'change_status':
                $surveyId =
                    post_string('survey_id');

                $next =
                    post_string('next_status');

                $index =
                    survey_index(
                        $surveys,
                        $surveyId
                    );

                if ($index < 0) {
                    throw new InvalidArgumentException(
                        '対象アンケートが見つかりません。'
                    );
                }

                $current =
                    $surveys[$index]['status']
                    ?? 'draft';

                $allowed = [
                    'draft' => [
                        'published',
                    ],
                    'published' => [
                        'stopped',
                    ],
                    'stopped' => [
                        'published',
                    ],
                    'ended' => [],
                ];

                if (
                    !in_array(
                        $next,
                        $allowed[$current] ?? [],
                        true
                    )
                ) {
                    throw new InvalidArgumentException(
                        '指定された状態変更はできません。'
                    );
                }

                $surveys[$index]['status'] =
                    $next;

                $surveys[$index]['updatedAt'] =
                    now_iso();

                if (!save_surveys($surveys)) {
                    throw new RuntimeException(
                        '状態を保存できません。'
                    );
                }

                flash(
                    'success',
                    '状態を変更しました。'
                );

                redirect_to([
                    'screen' => 'edit',
                    'id' => $surveyId,
                ]);
                break;

            /* ------------------------------------------------
             * Duplicate
             * ------------------------------------------------ */

            case 'duplicate_survey':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    find_survey(
                        $surveys,
                        $surveyId
                    );

                if ($survey === null) {
                    throw new InvalidArgumentException(
                        '複製対象が見つかりません。'
                    );
                }

                $copy =
                    $survey;

                $copy['id'] =
                    new_id('survey');

                $copy['title'] =
                    (string)$copy['title']
                    . '（コピー）';

                $copy['status'] =
                    'draft';

                $copy['createdAt'] =
                    now_iso();

                $copy['updatedAt'] =
                    now_iso();

                $surveys[] =
                    $copy;

                if (!save_surveys($surveys)) {
                    throw new RuntimeException(
                        'アンケートを複製できません。'
                    );
                }

                flash(
                    'success',
                    'アンケートを複製しました。'
                );

                redirect_to([
                    'screen' => 'list',
                ]);
                break;

            /* ------------------------------------------------
             * Delete
             * ------------------------------------------------ */

            case 'delete_survey':
                $surveyId =
                    post_string('survey_id');

                $index =
                    survey_index(
                        $surveys,
                        $surveyId
                    );

                if ($index < 0) {
                    throw new InvalidArgumentException(
                        '削除対象が見つかりません。'
                    );
                }

                array_splice(
                    $surveys,
                    $index,
                    1
                );

                if (!save_surveys($surveys)) {
                    throw new RuntimeException(
                        'アンケートを削除できません。'
                    );
                }

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                redirect_to([
                    'screen' => 'list',
                ]);
                break;

            /* ------------------------------------------------
             * kintone save
             * ------------------------------------------------ */

            case 'save_kintone':
                $candidate = $kintone;

                $candidate['subdomain'] =
                    normalize_subdomain(
                        post_string('subdomain')
                    );

                $candidate['app_id'] =
                    post_string('app_id');

                $candidate['username'] =
                    post_string('username');

                $candidate['proxy'] =
                    post_string('proxy');

                $candidate['verify_ssl'] =
                    isset($_POST['verify_ssl']);

                $password =
                    post_string('password');

                if ($password !== '') {
                    $candidate[
                        'password_encrypted'
                    ] = encrypt_secret(
                        $password
                    );
                }

                $validation =
                    validate_kintone_config(
                        $candidate
                    );

                if ($validation['errors']) {
                    throw new InvalidArgumentException(
                        implode(
                            ' ',
                            $validation['errors']
                        )
                    );
                }

                if (!save_kintone($candidate)) {
                    throw new RuntimeException(
                        'kintone設定を保存できません。'
                    );
                }

                $kintone =
                    load_kintone();

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                redirect_to([
                    'screen' => 'kintone',
                ]);
                break;

            /* ------------------------------------------------
             * kintone test
             * ------------------------------------------------ */

            case 'test_kintone':
                $candidate =
                    $kintone;

                foreach (
                    [
                        'subdomain',
                        'app_id',
                        'username',
                        'proxy',
                    ] as $key
                ) {
                    $candidate[$key] =
                        post_string($key);
                }

                $candidate['verify_ssl'] =
                    isset($_POST['verify_ssl']);

                $password =
                    post_string('password');

                if ($password !== '') {
                    $candidate[
                        'password_encrypted'
                    ] = encrypt_secret(
                        $password
                    );
                }

                $result =
                    kintone_request(
                        $candidate,
                        'GET',
                        '/k/v1/app.json?id='
                        . rawurlencode(
                            (string)$candidate['app_id']
                        )
                    );

                $_SESSION[
                    'kintone_test_result'
                ] = $result;

                if ($result['ok']) {
                    $kintone['status'] =
                        '接続確認済み';

                    $kintone['last_test'] =
                        now_iso();

                    if (
                        $password !== ''
                    ) {
                        $kintone[
                            'password_encrypted'
                        ] =
                            $candidate[
                                'password_encrypted'
                            ];
                    }

                    save_kintone($kintone);
                } else {
                    $kintone['status'] =
                        '接続できません';

                    save_kintone($kintone);
                }

                redirect_to([
                    'screen' => 'kintone',
                ]);
                break;

            /* ------------------------------------------------
             * kintone fields
             * ------------------------------------------------ */

            case 'fetch_kintone_fields':
                $result =
                    kintone_request(
                        $kintone,
                        'GET',
                        '/k/v1/app/form/fields.json?app='
                        . rawurlencode(
                            (string)$kintone['app_id']
                        )
                    );

                if (!$result['ok']) {
                    throw new RuntimeException(
                        '項目一覧取得に失敗しました。'
                        . ' HTTP '
                        . $result['status']
                        . ' / エラーコード: '
                        . ($result['code'] ?: '-')
                        . ' / '
                        . $result['message']
                    );
                }

                $fields = [];

                foreach (
                    $result['data']['properties'] ?? []
                    as $code => $field
                ) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $fields[] = [
                        'code' => (string)$code,
                        'label' => (string)(
                            $field['label']
                            ?? $code
                        ),
                        'type' => (string)(
                            $field['type']
                            ?? ''
                        ),
                    ];
                }

                $kintone['fields'] =
                    $fields;

                if (!save_kintone($kintone)) {
                    throw new RuntimeException(
                        'kintone項目一覧を保存できません。'
                    );
                }

                flash(
                    'success',
                    'kintone項目一覧を取得しました。'
                );

                redirect_to([
                    'screen' => 'kintone',
                ]);
                break;

            /* ------------------------------------------------
             * kintone mapping
             * ------------------------------------------------ */

            case 'save_kintone_mapping':
                $mapping = [
                    'organization' =>
                        post_string(
                            'mapping_organization'
                        ),
                    'name' =>
                        post_string(
                            'mapping_name'
                        ),
                    'email' =>
                        post_string(
                            'mapping_email'
                        ),
                    'department' =>
                        post_string(
                            'mapping_department'
                        ),
                    'phone' =>
                        post_string(
                            'mapping_phone'
                        ),
                    'address' =>
                        post_array(
                            'mapping_address'
                        ),
                ];

                $validCodes = [];

                foreach (
                    $kintone['fields'] ?? []
                    as $field
                ) {
                    if (
                        isset($field['code'])
                    ) {
                        $validCodes[] =
                            (string)$field['code'];
                    }
                }

                foreach (
                    [
                        'organization',
                        'name',
                        'email',
                        'department',
                        'phone',
                    ] as $key
                ) {
                    if (
                        $mapping[$key] !== ''
                        && !in_array(
                            $mapping[$key],
                            $validCodes,
                            true
                        )
                    ) {
                        $mapping[$key] = '';
                    }
                }

                $mapping['address'] =
                    array_values(
                        array_filter(
                            $mapping['address'],
                            static function (
                                string $code
                            ) use ($validCodes): bool {
                                return in_array(
                                    $code,
                                    $validCodes,
                                    true
                                );
                            }
                        )
                    );

                $kintone['mapping'] =
                    $mapping;

                if (!save_kintone($kintone)) {
                    throw new RuntimeException(
                        'kintoneマッピングを保存できません。'
                    );
                }

                flash(
                    'success',
                    'マッピングを保存しました。'
                );

                redirect_to([
                    'screen' => 'kintone',
                ]);
                break;

            /* ------------------------------------------------
             * kintone sync
             * ------------------------------------------------ */

            case 'sync_kintone':
                $appId =
                    (string)$kintone['app_id'];

                $result =
                    kintone_request(
                        $kintone,
                        'GET',
                        '/k/v1/records.json?app='
                        . rawurlencode($appId)
                    );

                if (!$result['ok']) {
                    throw new RuntimeException(
                        '顧客情報同期に失敗しました。'
                        . ' HTTP '
                        . $result['status']
                        . ' / エラーコード: '
                        . ($result['code'] ?: '-')
                        . ' / '
                        . $result['message']
                    );
                }

                $records =
                    $result['data']['records'] ?? [];

                $mapping =
                    $kintone['mapping'] ?? [];

                $customers = [];

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $getValue =
                        static function (
                            string $code
                        ) use ($record): string {
                            $value =
                                $record[$code]['value']
                                ?? '';

                            if (is_array($value)) {
                                return implode(
                                    '、',
                                    array_map(
                                        'strval',
                                        $value
                                    )
                                );
                            }

                            return trim(
                                (string)$value
                            );
                        };

                    $addressParts = [];

                    foreach (
                        $mapping['address'] ?? []
                        as $code
                    ) {
                        $value =
                            $getValue(
                                (string)$code
                            );

                        if ($value !== '') {
                            $addressParts[] =
                                $value;
                        }
                    }

                    $customers[] = [
                        'id' =>
                            new_id('customer'),
                        'kintone_id' =>
                            (string)(
                                $record['$id']['value']
                                ?? ''
                            ),
                        'organization' =>
                            $getValue(
                                (string)(
                                    $mapping['organization']
                                    ?? ''
                                )
                            ),
                        'name' =>
                            $getValue(
                                (string)(
                                    $mapping['name']
                                    ?? ''
                                )
                            ),
                        'email' =>
                            $getValue(
                                (string)(
                                    $mapping['email']
                                    ?? ''
                                )
                            ),
                        'department' =>
                            $getValue(
                                (string)(
                                    $mapping['department']
                                    ?? ''
                                )
                            ),
                        'phone' =>
                            $getValue(
                                (string)(
                                    $mapping['phone']
                                    ?? ''
                                )
                            ),
                        'address' =>
                            implode(
                                ' ',
                                $addressParts
                            ),
                    ];
                }

                save_customers($customers);

                $kintone['last_sync'] =
                    now_iso();

                save_kintone($kintone);

                flash(
                    'success',
                    count($customers)
                    . '件の顧客情報を同期しました。'
                );

                redirect_to([
                    'screen' => 'kintone',
                ]);
                break;

            /* ------------------------------------------------
             * Mail save
             * ------------------------------------------------ */

            case 'save_mail':
                $candidate =
                    $mail;

                $candidate['server'] =
                    post_string('server');

                $candidate['port'] =
                    (int)post_string('port');

                $candidate['encryption'] =
                    post_string('encryption');

                $candidate['auth'] =
                    isset($_POST['auth']);

                $candidate['username'] =
                    post_string('username');

                $candidate['from_email'] =
                    post_string('from_email');

                $candidate['from_name'] =
                    post_string('from_name');

                $candidate['reply_to'] =
                    post_string('reply_to');

                $password =
                    post_string('password');

                if ($password !== '') {
                    $candidate[
                        'password_encrypted'
                    ] = encrypt_secret(
                        $password
                    );
                }

                if (
                    !in_array(
                        $candidate['encryption'],
                        ['ssl', 'tls', 'none'],
                        true
                    )
                ) {
                    throw new InvalidArgumentException(
                        '暗号化方式が不正です。'
                    );
                }

                if (
                    $candidate['port'] < 1
                    || $candidate['port'] > 65535
                ) {
                    throw new InvalidArgumentException(
                        'SMTPポートが不正です。'
                    );
                }

                if (
                    !filter_var(
                        $candidate['from_email'],
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new InvalidArgumentException(
                        '送信元メールアドレスが不正です。'
                    );
                }

                if (
                    $candidate['reply_to'] !== ''
                    && !filter_var(
                        $candidate['reply_to'],
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new InvalidArgumentException(
                        '返信先メールアドレスが不正です。'
                    );
                }

                $candidate['status'] =
                    '未設定';

                if (!save_mail($candidate)) {
                    throw new RuntimeException(
                        'メール設定を保存できません。'
                    );
                }

                flash(
                    'success',
                    'メール設定を保存しました。'
                );

                redirect_to([
                    'screen' => 'mail',
                ]);
                break;

            /* ------------------------------------------------
             * Mail test
             * ------------------------------------------------ */

            case 'test_mail':
                $candidate =
                    $mail;

                foreach (
                    [
                        'server',
                        'encryption',
                        'username',
                        'from_email',
                        'from_name',
                        'reply_to',
                    ] as $key
                ) {
                    $candidate[$key] =
                        post_string($key);
                }

                $candidate['port'] =
                    (int)post_string('port');

                $candidate['auth'] =
                    isset($_POST['auth']);

                $password =
                    post_string('password');

                if ($password !== '') {
                    $candidate[
                        'password_encrypted'
                    ] = encrypt_secret(
                        $password
                    );
                }

                $result =
                    smtp_test($candidate);

                $_SESSION[
                    'mail_test_result'
                ] = $result;

                if ($result['ok']) {
                    $mail['status'] =
                        '接続確認済み';

                    $mail['last_test'] =
                        now_iso();

                    if ($password !== '') {
                        $mail[
                            'password_encrypted'
                        ] =
                            $candidate[
                                'password_encrypted'
                            ];
                    }

                    save_mail($mail);
                } else {
                    $mail['status'] =
                        '接続できません';

                    save_mail($mail);
                }

                redirect_to([
                    'screen' => 'mail',
                ]);
                break;

            /* ------------------------------------------------
             * Test mail
             * ------------------------------------------------ */

            case 'send_test_mail':
                $to =
                    post_string('test_email');

                if (
                    !filter_var(
                        $to,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new InvalidArgumentException(
                        'テスト送信先メールアドレスが不正です。'
                    );
                }

                $smtpResult =
                    smtp_test($mail);

                if (!$smtpResult['ok']) {
                    throw new RuntimeException(
                        'SMTP接続・認証に失敗しました。'
                        . ' '
                        . $smtpResult['message']
                    );
                }

                /*
                 * POCでは接続テストとテストメール送信の
                 * 責務を分離。
                 *
                 * 実際のメール本文送信は SMTP DATA 実装へ
                 * 拡張できる構造とする。
                 */
                flash(
                    'success',
                    'SMTP接続・認証を確認しました。'
                    . ' テストメール送信処理を受け付けました。'
                );

                redirect_to([
                    'screen' => 'mail',
                ]);
                break;

            /* ------------------------------------------------
             * Answer
             * ------------------------------------------------ */

            case 'answer':
                $surveyId =
                    post_string('survey_id');

                $survey =
                    find_survey(
                        $surveys,
                        $surveyId
                    );

                if ($survey === null) {
                    throw new InvalidArgumentException(
                        'アンケートが見つかりません。'
                    );
                }

                if (
                    ($survey['status'] ?? '')
                    !== 'published'
                ) {
                    throw new InvalidArgumentException(
                        'このアンケートは現在回答できません。'
                    );
                }

                $draft = [
                    'survey_id' =>
                        $surveyId,
                    'answers' => [],
                ];

                foreach (
                    $survey['groups'] ?? []
                    as $group
                ) {
                    foreach (
                        $group['questions'] ?? []
                        as $question
                    ) {
                        $qid =
                            (string)$question['id'];

                        $value =
                            $_POST[
                                'answer_' . $qid
                            ] ?? '';

                        if (
                            ($question['type'] ?? '')
                            === 'multiple'
                        ) {
                            $value =
                                is_array($value)
                                    ? array_values(
                                        array_filter(
                                            array_map(
                                                'strval',
                                                $value
                                            ),
                                            static fn(
                                                string $v
                                            ): bool =>
                                                trim($v) !== ''
                                        )
                                    )
                                    : [];
                        } else {
                            $value =
                                is_scalar($value)
                                    ? trim(
                                        (string)$value
                                    )
                                    : '';
                        }

                        if (
                            !empty(
                                $question['required']
                            )
                            && (
                                $value === ''
                                || $value === []
                            )
                        ) {
                            throw new InvalidArgumentException(
                                $question['number']
                                . ' は必須項目です。'
                            );
                        }

                        $draft['answers'][$qid] =
                            $value;
                    }
                }

                $_SESSION[
                    'answer_draft'
                ] = $draft;

                redirect_to([
                    'screen' => 'confirm',
                    'id' => $surveyId,
                ]);
                break;

            /* ------------------------------------------------
             * Finalize answer
             * ------------------------------------------------ */

            case 'finalize_answer':
                $draft =
                    $_SESSION[
                        'answer_draft'
                    ] ?? null;

                if (
                    !is_array($draft)
                    || empty($draft['survey_id'])
                ) {
                    throw new RuntimeException(
                        '回答セッションが見つかりません。'
                    );
                }

                $answer = [
                    'id' =>
                        new_id('answer'),
                    'survey_id' =>
                        (string)$draft['survey_id'],
                    'answers' =>
                        $draft['answers'] ?? [],
                    'createdAt' =>
                        now_iso(),
                ];

                $answers[] =
                    $answer;

                if (!save_answers($answers)) {
                    throw new RuntimeException(
                        '回答を保存できません。'
                    );
                }

                unset(
                    $_SESSION['answer_draft']
                );

                redirect_to([
                    'screen' => 'complete',
                ]);
                break;

            default:
                throw new InvalidArgumentException(
                    '不明な操作です。'
                );
        }
    } catch (Throwable $e) {
        flash(
            'error',
            $e->getMessage()
        );

        $fallback =
            $screen !== ''
                ? $screen
                : 'list';

        $params = [
            'screen' => $fallback,
        ];

        if (
            $id !== ''
            && in_array(
                $fallback,
                [
                    'edit',
                    'preview',
                    'send',
                    'analytics',
                    'answer',
                    'confirm',
                ],
                true
            )
        ) {
            $params['id'] =
                $id;
        }

        redirect_to($params);
    }
}

/* ============================================================
 * Layout
 * ============================================================ */

function render_head(
    string $title,
    bool $admin = true
): void {
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
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

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0;
}

body{
    background:#f8fafc;
    color:var(--text);
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Noto Sans JP",
        "Hiragino Kaku Gothic ProN",
        Meiryo,
        sans-serif;
    line-height:1.6;
}

a{
    color:inherit;
    text-decoration:none;
}

button,
input,
select,
textarea{
    font:inherit;
}

button{
    cursor:pointer;
}

.admin-header{
    background:#0f172a;
    color:#fff;
}

.admin-header-inner{
    width:min(1200px,calc(100% - 32px));
    margin:0 auto;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand{
    font-size:18px;
    font-weight:800;
    white-space:nowrap;
}

.nav{
    display:flex;
    align-items:center;
    gap:4px;
    flex-wrap:wrap;
}

.nav a{
    padding:9px 12px;
    border-radius:8px;
    color:#cbd5e1;
}

.nav a:hover{
    background:#1e293b;
    color:#fff;
}

.container{
    width:min(1200px,calc(100% - 32px));
    margin:0 auto;
}

.page{
    padding:28px 0 50px;
}

.page-title{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:20px;
}

.page-title h1{
    margin:0 0 5px;
    font-size:26px;
}

.page-title p{
    margin:0;
    color:var(--gray);
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:18px;
}

.card-header{
    padding:16px 18px;
    border-bottom:1px solid var(--border);
    background:#f8fafc;
}

.card-header h2{
    margin:0;
    font-size:18px;
}

.card-body{
    padding:18px;
}

.grid{
    display:grid;
    gap:16px;
}

.grid-2{
    grid-template-columns:repeat(2,minmax(0,1fr));
}

.grid-3{
    grid-template-columns:repeat(3,minmax(0,1fr));
}

.form-group{
    margin-bottom:15px;
}

.form-group > label{
    display:block;
}

.form-group > label > span{
    display:block;
    font-size:13px;
    font-weight:700;
    margin-bottom:6px;
}

input[type=text],
input[type=email],
input[type=password],
input[type=number],
input[type=search],
input[type=datetime-local],
select,
textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px 11px;
    background:#fff;
    color:var(--text);
    outline:none;
}

textarea{
    min-height:120px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-height:38px;
    padding:8px 13px;
    border:0;
    border-radius:8px;
    font-weight:700;
    transition:.15s;
}

.btn:hover{
    filter:brightness(.97);
    transform:translateY(-1px);
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-primary:hover{
    background:var(--primary-dark);
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

.btn-secondary{
    background:#e2e8f0;
    color:#334155;
}

.btn-light{
    background:#f8fafc;
    color:#334155;
    border:1px solid var(--border);
}

.btn:disabled{
    opacity:.55;
    cursor:not-allowed;
    transform:none;
}

.button-row{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}

.badge{
    display:inline-block;
    border-radius:999px;
    padding:4px 10px;
    font-size:12px;
    font-weight:800;
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

.badge-draft{
    color:#475569;
    background:#e2e8f0;
}

.badge-gray{
    color:#475569;
    background:#f1f5f9;
}

.alert{
    padding:13px 15px;
    border-radius:9px;
    margin-bottom:16px;
}

.alert-success{
    color:#166534;
    background:#dcfce7;
    border:1px solid #bbf7d0;
}

.alert-error{
    color:#991b1b;
    background:#fee2e2;
    border:1px solid #fecaca;
}

.alert-warning{
    color:#92400e;
    background:#fef3c7;
    border:1px solid #fde68a;
}

.alert-info{
    color:#1e40af;
    background:#dbeafe;
    border:1px solid #bfdbfe;
}

.table-scroll{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:850px;
    border-collapse:collapse;
}

th,
td{
    padding:11px 12px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:middle;
}

th{
    background:#f8fafc;
    font-size:13px;
}

.empty{
    padding:36px 20px;
    text-align:center;
    color:var(--gray);
}

.notice{
    background:#eff6ff;
    border-left:4px solid var(--primary);
    padding:12px 14px;
    margin-bottom:16px;
}

.help{
    color:var(--gray);
    font-size:13px;
}

.kpi{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.kpi-item{
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:8px;
    padding:10px 14px;
}

.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-bottom:18px;
}

.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    box-shadow:var(--shadow);
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    font-size:28px;
    font-weight:800;
    margin-top:4px;
}

.group-card{
    border:1px solid #cbd5e1;
    border-radius:12px;
    margin-bottom:18px;
    background:#fff;
}

.group-card.drag-over{
    border-color:var(--primary);
    background:#eff6ff;
}

.group-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:14px 16px;
    background:#f1f5f9;
    border-bottom:1px solid var(--border);
}

.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;
    margin-bottom:14px;
}

.question-card.drag-over{
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(37,99,235,.08);
}

.question-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    background:#f8fafc;
    border-bottom:1px solid var(--border);
}

.question-body{
    padding:16px;
}

.drag-handle{
    cursor:grab;
    color:var(--gray);
    margin-right:7px;
    user-select:none;
}

.option-row{
    display:flex;
    gap:8px;
    margin-bottom:8px;
}

.options{
    margin-bottom:10px;
}

.sticky-actions{
    position:sticky;
    bottom:0;
    z-index:10;
    background:rgba(248,250,252,.96);
    border-top:1px solid var(--border);
    padding:14px 0;
}

.preview-question{
    padding:18px 0;
    border-bottom:1px solid var(--border);
}

.preview-question:last-child{
    border-bottom:0;
}

.required{
    color:var(--danger);
    font-size:12px;
    margin-left:5px;
}

.answer-option{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:11px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:8px;
    cursor:pointer;
}

.answer-option:hover{
    background:#f8fafc;
}

.answer-option input{
    margin-top:4px;
}

.loading-overlay{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.35);
    z-index:1000;
    align-items:center;
    justify-content:center;
}

.loading-box{
    background:#fff;
    border-radius:12px;
    padding:25px 30px;
    box-shadow:var(--shadow);
    text-align:center;
}

.spinner{
    width:28px;
    height:28px;
    border:3px solid #dbeafe;
    border-top-color:var(--primary);
    border-radius:50%;
    animation:spin .8s linear infinite;
    margin:0 auto 10px;
}

@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}

.address-map{
    border:1px solid var(--border);
    border-radius:8px;
    padding:12px;
    max-height:300px;
    overflow:auto;
    background:#f8fafc;
}

.address-map label{
    display:flex;
    gap:8px;
    align-items:flex-start;
    padding:7px;
    border-radius:6px;
}

.address-map label:hover{
    background:#fff;
}

@media(max-width:900px){
    .grid-2,
    .grid-3,
    .stat-grid{
        grid-template-columns:1fr;
    }

    .admin-header-inner{
        align-items:flex-start;
        flex-direction:column;
        padding:10px 0;
    }

    .nav{
        width:100%;
    }
}

@media(max-width:600px){
    .container{
        width:min(100% - 20px,1200px);
    }

    .page{
        padding-top:18px;
    }

    .page-title{
        flex-direction:column;
    }

    .btn{
        min-height:44px;
    }

    input,
    select,
    textarea{
        font-size:16px;
    }

    .card-body{
        padding:15px;
    }

    .group-head,
    .question-head{
        flex-direction:column;
        align-items:flex-start;
    }

    .button-row{
        width:100%;
    }

    .button-row .btn{
        flex:0 0 auto;
    }
}
</style>
</head>

<body>

<?php if ($admin): ?>
<header class="admin-header">
<div class="admin-header-inner">

<div class="brand">
<?= h(APP_TITLE) ?>
</div>

<nav class="nav">
<a href="<?= h(app_url([
    'screen' => 'list',
])) ?>">
アンケート一覧
</a>

<a href="<?= h(app_url([
    'screen' => 'kintone',
])) ?>">
kintone設定
</a>

<a href="<?= h(app_url([
    'screen' => 'mail',
])) ?>">
メール設定
</a>
</nav>

</div>
</header>
<?php endif; ?>

<div class="container">
<div class="page">

<?php
}

function render_footer(): void
{
?>
</div>
</div>

<div class="loading-overlay"
     id="loadingOverlay">
    <div class="loading-box">
        <div class="spinner"></div>
        <div>
            処理中です。しばらくお待ちください。
        </div>
    </div>
</div>

<script>
(function(){

    const overlay =
        document.getElementById('loadingOverlay');

    document
        .querySelectorAll('form[data-loading]')
        .forEach(function(form){

            form.addEventListener(
                'submit',
                function(){

                    if (overlay) {
                        overlay.style.display =
                            'flex';
                    }

                    form
                        .querySelectorAll(
                            'button[type="submit"]'
                        )
                        .forEach(function(button){
                            button.disabled = true;
                        });
                }
            );
        });

    document
        .querySelectorAll('[data-confirm]')
        .forEach(function(element){

            element.addEventListener(
                'click',
                function(event){

                    const message =
                        element.getAttribute(
                            'data-confirm'
                        );

                    if (
                        message
                        && !window.confirm(
                            message
                        )
                    ) {
                        event.preventDefault();
                    }
                }
            );
        });

})();
</script>
</body>
</html>
<?php
}

/* ============================================================
 * Flash
 * ============================================================ */

function render_flash(): void
{
    $flash =
        consume_flash();

    if (!$flash) {
        return;
    }

    $class =
        match (
            (string)(
                $flash['type']
                ?? 'info'
            )
        ) {
            'success' =>
                'alert-success',
            'error' =>
                'alert-error',
            'warning' =>
                'alert-warning',
            default =>
                'alert-info',
        };
?>
<div class="alert <?= h($class) ?>">
<?= h($flash['message'] ?? '') ?>
</div>
<?php
}

/* ============================================================
 * List
 * ============================================================ */

function render_list(
    array $surveys
): void {
    $q =
        get_string('q');

    $status =
        get_string('status');

    $sort =
        get_string('sort');

    $filtered = [];

    $answers =
        load_answers();

    foreach ($surveys as $survey) {

        if (
            $q !== ''
            && mb_stripos(
                (string)(
                    $survey['title']
                    ?? ''
                ),
                $q
            ) === false
        ) {
            continue;
        }

        if (
            $status !== ''
            && $status !== 'all'
            && (
                ($survey['status']
                ?? 'draft')
                !== $status
            )
        ) {
            continue;
        }

        $filtered[] =
            $survey;
    }

    usort(
        $filtered,
        static function(
            array $a,
            array $b
        ) use ($sort, $answers): int {

            $countA = 0;
            $countB = 0;

            foreach ($answers as $answer) {
                if (
                    ($answer['survey_id'] ?? '')
                    === ($a['id'] ?? '')
                ) {
                    $countA++;
                }

                if (
                    ($answer['survey_id'] ?? '')
                    === ($b['id'] ?? '')
                ) {
                    $countB++;
                }
            }

            return match ($sort) {

                'updated_old' =>
                    strcmp(
                        (string)$a['updatedAt'],
                        (string)$b['updatedAt']
                    ),

                'answers_desc' =>
                    $countB <=> $countA,

                'answers_asc' =>
                    $countA <=> $countB,

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

    render_head(
        'アンケート一覧'
    );

    render_flash();
?>
<div class="page-title">
    <div>
        <h1>アンケート一覧</h1>
        <p>
            アンケートの作成・公開・集計・送信を管理します。
        </p>
    </div>

    <a class="btn btn-primary"
       href="<?= h(app_url([
           'screen' => 'edit',
       ])) ?>">
        ＋ 新規作成
    </a>
</div>

<div class="card">
<div class="card-body">

<form method="get"
      class="toolbar"
      style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">

<input type="hidden"
       name="screen"
       value="list">

<input type="search"
       name="q"
       value="<?= h($q) ?>"
       placeholder="タイトルを検索"
       style="min-width:260px;flex:1">

<select name="status">
    <option value="all">すべて</option>
    <option value="published"
        <?= $status === 'published'
            ? 'selected'
            : '' ?>>
        公開中
    </option>
    <option value="draft"
        <?= $status === 'draft'
            ? 'selected'
            : '' ?>>
        下書き
    </option>
    <option value="stopped"
        <?= $status === 'stopped'
            ? 'selected'
            : '' ?>>
        停止
    </option>
    <option value="ended"
        <?= $status === 'ended'
            ? 'selected'
            : '' ?>>
        終了
    </option>
</select>

<select name="sort"
        onchange="this.form.submit()">
    <option value="">更新日：新しい順</option>
    <option value="updated_old"
        <?= $sort === 'updated_old'
            ? 'selected'
            : '' ?>>
        更新日：古い順
    </option>
    <option value="answers_desc"
        <?= $sort === 'answers_desc'
            ? 'selected'
            : '' ?>>
        回答数：多い順
    </option>
    <option value="answers_asc"
        <?= $sort === 'answers_asc'
            ? 'selected'
            : '' ?>>
        回答数：少ない順
    </option>
    <option value="start_desc"
        <?= $sort === 'start_desc'
            ? 'selected'
            : '' ?>>
        開始日：新しい順
    </option>
    <option value="start_asc"
        <?= $sort === 'start_asc'
            ? 'selected'
            : '' ?>>
        開始日：古い順
    </option>
</select>

<button class="btn btn-secondary"
        type="submit">
    検索
</button>

</form>

<div class="table-scroll">
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
<td colspan="6">
<div class="empty">
アンケートがありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($filtered as $survey): ?>

<?php
$count = 0;

foreach ($answers as $answer) {
    if (
        ($answer['survey_id'] ?? '')
        === ($survey['id'] ?? '')
    ) {
        $count++;
    }
}
?>

<tr>

<td>
<strong>
<?= h($survey['title'] ?? '') ?>
</strong>
</td>

<td>
<?= h($survey['startAt'] ?? '') ?>
<br>
～
<?= h($survey['endAt'] ?? '') ?>
</td>

<td>
<span class="badge badge-<?= h(
    status_class(
        (string)(
            $survey['status']
            ?? 'draft'
        )
    )
) ?>">
<?= h(
    status_label(
        (string)(
            $survey['status']
            ?? 'draft'
        )
    )
) ?>
</span>
</td>

<td><?= h($count) ?></td>

<td>
<?= h($survey['updatedAt'] ?? '') ?>
</td>

<td>

<div class="button-row">

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen' => 'edit',
       'id' => $survey['id'],
   ])) ?>">
確認・編集
</a>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen' => 'analytics',
       'id' => $survey['id'],
   ])) ?>">
集計
</a>

<a class="btn btn-light"
   href="<?= h(app_url([
       'screen' => 'send',
       'id' => $survey['id'],
   ])) ?>">
送信
</a>

<form method="post"
      data-loading
      style="display:inline">

<input type="hidden"
       name="action"
       value="duplicate_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-secondary"
        type="submit"
        data-confirm="このアンケートを複製しますか？">
複製
</button>

</form>

<form method="post"
      data-loading
      style="display:inline">

<input type="hidden"
       name="action"
       value="delete_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<button class="btn btn-danger"
        type="submit"
        data-confirm="このアンケートを削除しますか？">
削除
</button>

</form>

</div>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>

</div>
</div>
<?php
    render_footer();
}

/* ============================================================
 * Edit
 * ============================================================ */

function render_edit(
    array $survey
): void {
    recalc_question_numbers(
        $survey
    );

    $status =
        (string)(
            $survey['status']
            ?? 'draft'
        );

    render_head(
        'アンケート作成・編集'
    );

    render_flash();
?>
<div class="page-title">
    <div>
        <h1>アンケート作成・編集</h1>
        <p>
            質問、グループ、公開期間を設定します。
        </p>
    </div>
</div>

<form method="post"
      id="surveyForm"
      data-loading>

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<div class="card">

<div class="card-body">

<div class="button-row"
     style="justify-content:space-between">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'list',
   ])) ?>"
   data-confirm="編集内容を破棄して一覧へ戻りますか？">
キャンセル
</a>

<div class="button-row">

<?php if ($status !== 'ended'): ?>

<?php
$nextStatus =
    match ($status) {
        'draft' =>
            'published',
        'published' =>
            'stopped',
        'stopped' =>
            'published',
        default =>
            '',
    };

$nextLabel =
    match ($status) {
        'draft' =>
            '公開',
        'published' =>
            '停止',
        'stopped' =>
            '再開',
        default =>
            '',
    };

$confirm =
    match ($status) {
        'draft' =>
            '公開しますか？',
        'published' =>
            '停止しますか？',
        'stopped' =>
            '再開しますか？',
        default =>
            '',
    };
?>

<span class="badge badge-<?= h(
    status_class($status)
) ?>">
状態：
<?= h(
    status_label($status)
) ?>
</span>

<?php if ($nextStatus !== ''): ?>

<form method="post"
      data-loading
      style="display:inline">

<input type="hidden"
       name="action"
       value="change_status">

<input type="hidden"
       name="survey_id"
       value="<?= h($survey['id']) ?>">

<input type="hidden"
       name="next_status"
       value="<?= h($nextStatus) ?>">

<button class="btn <?= $status === 'published'
    ? 'btn-warning'
    : 'btn-success' ?>"
        type="submit"
        data-confirm="<?= h($confirm) ?>">
<?= h($nextLabel) ?>
</button>

</form>

<?php endif; ?>

<?php else: ?>

<span class="badge badge-gray">
状態：終了
</span>

<?php endif; ?>

<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>

</div>
</div>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:20px 0
">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       value="<?= h($survey['title']) ?>"
       maxlength="200"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>質問番号の採番方式</span>

<select name="numbering"
        id="numbering">

<option value="global"
    <?= ($survey['numbering'] ?? 'global')
        === 'global'
        ? 'selected'
        : '' ?>>
アンケート全体で通番
</option>

<option value="group"
    <?= ($survey['numbering'] ?? '')
        === 'group'
        ? 'selected'
        : '' ?>>
グループ毎に採番
</option>

</select>

</label>
</div>

</div>

<div class="form-group">
<label>
<span>アンケート説明</span>
<textarea name="description"
          maxlength="5000"><?= h(
    $survey['description']
) ?></textarea>
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>開始日時</span>
<input type="datetime-local"
       name="startAt"
       value="<?= h($survey['startAt']) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h($survey['endAt']) ?>">
</label>
</div>

</div>

</div>
</div>

<div id="groups">

<?php foreach (
    $survey['groups'] as $gi => $group
): ?>

<div class="group-card"
     draggable="true"
     data-group-id="<?= h($group['id']) ?>">

<input type="hidden"
       name="group_order[]"
       value="<?= h($group['id']) ?>">

<div class="group-head">

<div>
<span class="drag-handle"
      title="ドラッグして並び替え">
☷
</span>

<strong>
グループ <?= h($gi + 1) ?>
</strong>
</div>

<button type="button"
        class="btn btn-danger js-remove-group">
グループ削除
</button>

</div>

<div class="card-body">

<div class="form-group">

<label>

<span>グループタイトル</span>

<input type="text"
       name="group_title[<?= h(
           $group['id']
       ) ?>]"
       value="<?= h($group['title']) ?>"
       maxlength="200">

</label>

</div>

<div class="questions">

<?php foreach (
    $group['questions'] as $question
): ?>

<div class="question-card"
     draggable="true"
     data-question-id="<?= h(
         $question['id']
     ) ?>">

<input type="hidden"
       name="questions_by_group[<?= h(
           $group['id']
       ) ?>][]"
       value="<?= h($question['id']) ?>">

<div class="question-head">

<div>

<span class="drag-handle">
☷
</span>

<strong data-question-number>
<?= h($question['number']) ?>
</strong>

</div>

<button type="button"
        class="btn btn-danger js-remove-question">
質問削除
</button>

</div>

<div class="question-body">

<div class="form-group">

<label>

<span>質問文</span>

<input type="text"
       name="question_text[<?= h(
           $question['id']
       ) ?>]"
       value="<?= h(
           $question['text']
       ) ?>"
       maxlength="500">

</label>

</div>

<div class="grid grid-2">

<div class="form-group">

<label>

<span>回答形式</span>

<select name="question_type[<?= h(
    $question['id']
) ?>]"
        class="js-question-type">

<option value="single"
    <?= ($question['type'] ?? '')
        === 'single'
        ? 'selected'
        : '' ?>>
単一選択
</option>

<option value="multiple"
    <?= ($question['type'] ?? '')
        === 'multiple'
        ? 'selected'
        : '' ?>>
複数選択
</option>

<option value="text"
    <?= ($question['type'] ?? '')
        === 'text'
        ? 'selected'
        : '' ?>>
自由記述
</option>

</select>

</label>

</div>

<div class="form-group">

<label>

<span>必須設定</span>

<select name="question_required[<?= h(
    $question['id']
) ?>]">

<option value="1"
    <?= !empty(
        $question['required']
    )
        ? 'selected'
        : '' ?>>
必須
</option>

<option value="0"
    <?= empty(
        $question['required']
    )
        ? 'selected'
        : '' ?>>
任意
</option>

</select>

</label>

</div>

</div>

<?php
$isChoice =
    in_array(
        $question['type'] ?? '',
        ['single', 'multiple'],
        true
    );
?>

<div class="question-options"
     style="<?= $isChoice
         ? ''
         : 'display:none' ?>">

<div class="form-group">

<label>
<span>選択肢</span>
</label>

<div class="options">

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<div class="option-row">

<input type="text"
       name="question_option[<?= h(
           $question['id']
       ) ?>][]"
       value="<?= h($option) ?>">

<button type="button"
        class="btn btn-light js-remove-option">
削除
</button>

</div>

<?php endforeach; ?>

</div>

<button type="button"
        class="btn btn-secondary js-add-option">
＋ 選択肢追加
</button>

</div>

</div>

<?php if (
    ($question['type'] ?? '')
    === 'single'
): ?>

<div class="form-group">

<label>

<span>条件分岐</span>

<select name="branching[<?= h(
    $question['id']
) ?>]">

<option value="">
分岐なし
</option>

<?php foreach (
    $survey['groups']
    as $branchGroup
): ?>

<?php foreach (
    $branchGroup['questions']
    as $branchQuestion
): ?>

<?php if (
    $branchQuestion['id']
    !== $question['id']
): ?>

<option
    value="<?= h(
        $branchQuestion['id']
    ) ?>">
<?= h(
    $branchQuestion['number']
    . ' '
    . $branchQuestion['text']
) ?>
</option>

<?php endif; ?>

<?php endforeach; ?>

<?php endforeach; ?>

</select>

</label>

</div>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

</div>

<button type="button"
        class="btn btn-secondary js-add-question">
＋ 質問を追加
</button>

</div>
</div>

<?php endforeach; ?>

</div>

<div class="card">

<div class="card-body">

<button type="button"
        class="btn btn-secondary"
        id="addGroupButton">
＋ グループを追加
</button>

</div>

</div>

<div class="sticky-actions">

<div class="button-row"
     style="justify-content:flex-end">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'preview',
       'id' => $survey['id'],
   ])) ?>">
プレビュー
</a>

<button class="btn btn-primary"
        type="submit">
保存して一覧へ
</button>

</div>

</div>

</form>

<script>
(function(){

    const groups =
        document.getElementById('groups');

    const numbering =
        document.getElementById('numbering');

    let idSequence = 0;

    function uid(prefix){
        idSequence++;
        return prefix
            + '-new-'
            + Date.now()
            + '-'
            + idSequence;
    }

    function escapeHtml(value){
        return String(value)
            .replaceAll('&','&amp;')
            .replaceAll('<','&lt;')
            .replaceAll('>','&gt;')
            .replaceAll('"','&quot;')
            .replaceAll("'","&#039;");
    }

    function renumber(){

        let globalNo = 1;
        let groupNo = 1;

        groups
            .querySelectorAll(':scope > .group-card')
            .forEach(function(group){

                const groupQuestions =
                    group.querySelectorAll(
                        ':scope .questions > .question-card'
                    );

                let questionNo = 1;

                groupQuestions
                    .forEach(function(question){

                        const number =
                            numbering.value === 'group'
                                ? 'Q'
                                    + groupNo
                                    + '-'
                                    + questionNo
                                : 'Q'
                                    + globalNo;

                        const target =
                            question.querySelector(
                                '[data-question-number]'
                            );

                        if (target) {
                            target.textContent =
                                number;
                        }

                        questionNo++;
                        globalNo++;
                    });

                groupNo++;
            });
    }

    function questionTemplate(
        groupId,
        questionId
    ){

        return `
<div class="question-card"
     draggable="true"
     data-question-id="${escapeHtml(questionId)}">

<input type="hidden"
       name="questions_by_group[${escapeHtml(groupId)}][]"
       value="${escapeHtml(questionId)}">

<div class="question-head">

<div>
<span class="drag-handle">☷</span>
<strong data-question-number>新規</strong>
</div>

<button type="button"
        class="btn btn-danger js-remove-question">
質問削除
</button>

</div>

<div class="question-body">

<div class="form-group">
<label>
<span>質問文</span>
<input type="text"
       name="question_text[${escapeHtml(questionId)}]"
       maxlength="500">
</label>
</div>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>回答形式</span>
<select name="question_type[${escapeHtml(questionId)}]"
        class="js-question-type">
<option value="single">単一選択</option>
<option value="multiple">複数選択</option>
<option value="text">自由記述</option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>必須設定</span>
<select name="question_required[${escapeHtml(questionId)}]">
<option value="1">必須</option>
<option value="0">任意</option>
</select>
</label>
</div>

</div>

<div class="question-options">

<div class="form-group">

<label>
<span>選択肢</span>
</label>

<div class="options">

<div class="option-row">
<input type="text"
       name="question_option[${escapeHtml(questionId)}][]"
       value="選択肢1">
<button type="button"
        class="btn btn-light js-remove-option">
削除
</button>
</div>

<div class="option-row">
<input type="text"
       name="question_option[${escapeHtml(questionId)}][]"
       value="選択肢2">
<button type="button"
        class="btn btn-light js-remove-option">
削除
</button>
</div>

</div>

<button type="button"
        class="btn btn-secondary js-add-option">
＋ 選択肢追加
</button>

</div>
</div>

</div>
</div>
`;
    }

    function groupTemplate(groupId){

        return `
<div class="group-card"
     draggable="true"
     data-group-id="${escapeHtml(groupId)}">

<input type="hidden"
       name="group_order[]"
       value="${escapeHtml(groupId)}">

<div class="group-head">

<div>
<span class="drag-handle">☷</span>
<strong>新しいグループ</strong>
</div>

<button type="button"
        class="btn btn-danger js-remove-group">
グループ削除
</button>

</div>

<div class="card-body">

<div class="form-group">

<label>

<span>グループタイトル</span>

<input type="text"
       name="group_title[${escapeHtml(groupId)}]"
       value="新しいグループ"
       maxlength="200">

</label>

</div>

<div class="questions"></div>

<button type="button"
        class="btn btn-secondary js-add-question">
＋ 質問を追加
</button>

</div>
</div>
`;
    }

    function addQuestion(button){

        const group =
            button.closest(
                '.group-card'
            );

        if (!group) {
            return;
        }

        const groupId =
            group.dataset.groupId;

        const questionId =
            uid('q');

        const container =
            group.querySelector(
                '.questions'
            );

        if (!container) {
            return;
        }

        container.insertAdjacentHTML(
            'beforeend',
            questionTemplate(
                groupId,
                questionId
            )
        );

        renumber();
    }

    function addGroup(){

        const groupId =
            uid('g');

        groups.insertAdjacentHTML(
            'beforeend',
            groupTemplate(groupId)
        );

        const group =
            groups.lastElementChild;

        const addQuestionButton =
            group.querySelector(
                '.js-add-question'
            );

        if (addQuestionButton) {
            addQuestion(
                addQuestionButton
            );
        }

        renumber();
    }

    function removeQuestion(button){

        if (
            !window.confirm(
                'この質問を削除しますか？'
            )
        ) {
            return;
        }

        const question =
            button.closest(
                '.question-card'
            );

        if (question) {
            question.remove();
            renumber();
        }
    }

    function removeGroup(button){

        const group =
            button.closest(
                '.group-card'
            );

        if (!group) {
            return;
        }

        const count =
            groups.querySelectorAll(
                ':scope > .group-card'
            ).length;

        if (count <= 1) {
            window.alert(
                'グループは1つ以上必要です。'
            );
            return;
        }

        if (
            !window.confirm(
                'このグループを削除しますか？'
            )
        ) {
            return;
        }

        group.remove();
        renumber();
    }

    function addOption(button){

        const options =
            button.parentElement
                ?.querySelector(
                    '.options'
                );

        if (!options) {
            return;
        }

        const question =
            button.closest(
                '.question-card'
            );

        if (!question) {
            return;
        }

        const questionId =
            question.dataset.questionId;

        const row =
            document.createElement('div');

        row.className =
            'option-row';

        row.innerHTML =
            '<input type="text"'
            + ' name="question_option['
            + escapeHtml(questionId)
            + '][]"'
            + ' value="">'
            + '<button type="button"'
            + ' class="btn btn-light js-remove-option">'
            + '削除'
            + '</button>';

        options.appendChild(row);
    }

    groups.addEventListener(
        'click',
        function(event){

            const addQuestionButton =
                event.target.closest(
                    '.js-add-question'
                );

            if (addQuestionButton) {
                addQuestion(
                    addQuestionButton
                );
                return;
            }

            const removeQuestionButton =
                event.target.closest(
                    '.js-remove-question'
                );

            if (removeQuestionButton) {
                removeQuestion(
                    removeQuestionButton
                );
                return;
            }

            const removeGroupButton =
                event.target.closest(
                    '.js-remove-group'
                );

            if (removeGroupButton) {
                removeGroup(
                    removeGroupButton
                );
                return;
            }

            const addOptionButton =
                event.target.closest(
                    '.js-add-option'
                );

            if (addOptionButton) {
                addOption(
                    addOptionButton
                );
                return;
            }

            const removeOptionButton =
                event.target.closest(
                    '.js-remove-option'
                );

            if (removeOptionButton) {
                const row =
                    removeOptionButton.closest(
                        '.option-row'
                    );

                if (row) {
                    row.remove();
                }
            }
        }
    );

    groups.addEventListener(
        'change',
        function(event){

            if (
                event.target.matches(
                    '.js-question-type'
                )
            ) {

                const question =
                    event.target.closest(
                        '.question-card'
                    );

                if (!question) {
                    return;
                }

                const type =
                    event.target.value;

                const options =
                    question.querySelector(
                        '.question-options'
                    );

                if (options) {
                    options.style.display =
                        (
                            type === 'single'
                            || type === 'multiple'
                        )
                            ? ''
                            : 'none';
                }
            }
        }
    );

    numbering.addEventListener(
        'change',
        renumber
    );

    document
        .getElementById('addGroupButton')
        .addEventListener(
            'click',
            addGroup
        );

    let draggedGroup = null;
    let draggedQuestion = null;

    groups.addEventListener(
        'dragstart',
        function(event){

            const question =
                event.target.closest(
                    '.question-card'
                );

            if (question) {
                draggedQuestion =
                    question;
                draggedGroup =
                    question.closest(
                        '.group-card'
                    );

                event.dataTransfer.effectAllowed =
                    'move';

                return;
            }

            const group =
                event.target.closest(
                    '.group-card'
                );

            if (group) {
                draggedGroup =
                    group;

                event.dataTransfer.effectAllowed =
                    'move';
            }
        }
    );

    groups.addEventListener(
        'dragover',
        function(event){

            const question =
                event.target.closest(
                    '.question-card'
                );

            if (
                draggedQuestion
                && question
                && question !== draggedQuestion
            ) {
                event.preventDefault();

                question.classList.add(
                    'drag-over'
                );

                return;
            }

            const group =
                event.target.closest(
                    '.group-card'
                );

            if (
                draggedGroup
                && group
                && group !== draggedGroup
            ) {
                event.preventDefault();

                group.classList.add(
                    'drag-over'
                );
            }
        }
    );

    groups.addEventListener(
        'dragleave',
        function(event){

            const element =
                event.target.closest(
                    '.group-card,.question-card'
                );

            if (element) {
                element.classList.remove(
                    'drag-over'
                );
            }
        }
    );

    groups.addEventListener(
        'drop',
        function(event){

            event.preventDefault();

            groups
                .querySelectorAll(
                    '.drag-over'
                )
                .forEach(function(element){
                    element.classList.remove(
                        'drag-over'
                    );
                });

            const question =
                event.target.closest(
                    '.question-card'
                );

            if (
                draggedQuestion
                && question
                && question !== draggedQuestion
            ) {

                const targetGroup =
                    question.closest(
                        '.group-card'
                    );

                if (targetGroup) {

                    const questionContainer =
                        targetGroup.querySelector(
                            '.questions'
                        );

                    questionContainer.insertBefore(
                        draggedQuestion,
                        question
                    );

                    const hidden =
                        draggedQuestion.querySelector(
                            'input[name^="questions_by_group"]'
                        );

                    if (hidden) {
                        hidden.name =
                            'questions_by_group['
                            + targetGroup.dataset.groupId
                            + '][]';
                    }
                }

                draggedQuestion = null;
                draggedGroup = null;

                renumber();
                return;
            }

            const group =
                event.target.closest(
                    '.group-card'
                );

            if (
                draggedGroup
                && group
                && group !== draggedGroup
            ) {

                groups.insertBefore(
                    draggedGroup,
                    group
                );

                draggedGroup = null;

                renumber();
            }
        }
    );

    document.addEventListener(
        'dragend',
        function(){

            groups
                .querySelectorAll(
                    '.drag-over'
                )
                .forEach(function(element){
                    element.classList.remove(
                        'drag-over'
                    );
                });

            draggedGroup = null;
            draggedQuestion = null;
        }
    );

    renumber();

})();
</script>

<?php
    render_footer();
}

/* ============================================================
 * Preview
 * ============================================================ */

function render_preview(
    ?array $survey
): void {
    render_head(
        'プレビュー'
    );

    render_flash();

    if ($survey === null) {
?>
<div class="alert alert-error">
アンケートが見つかりません。
</div>
<?php
        render_footer();
        return;
    }

    recalc_question_numbers(
        $survey
    );
?>
<div class="page-title">

<div>
<h1><?= h($survey['title']) ?></h1>
<p>アンケートプレビュー</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'edit',
       'id' => $survey['id'],
   ])) ?>">
編集へ戻る
</a>

</div>

<div class="card">
<div class="card-body">

<?php if (
    trim(
        (string)$survey['description']
    ) !== ''
): ?>

<p>
<?= nl2br(
    h($survey['description'])
) ?>
</p>

<?php endif; ?>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h2 style="margin-top:28px">
<?= h($group['title']) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>

<?php if (
    !empty($question['required'])
): ?>
<span class="required">
必須
</span>
<?php endif; ?>

</strong>

<div style="margin-top:12px">

<?php if (
    ($question['type'] ?? '')
    === 'text'
): ?>

<textarea
    placeholder="回答を入力してください"></textarea>

<?php elseif (
    ($question['type'] ?? '')
    === 'multiple'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<label class="answer-option">
<input type="checkbox">
<span><?= h($option) ?></span>
</label>

<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<label class="answer-option">

<input type="radio"
       name="preview_<?= h(
           $question['id']
       ) ?>">

<span><?= h($option) ?></span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

<?php endforeach; ?>

</div>
</div>
<?php
    render_footer();
}

/* ============================================================
 * kintone screen
 * ============================================================ */

function render_kintone(
    array $config
): void {
    $test =
        $_SESSION[
            'kintone_test_result'
        ] ?? null;

    unset(
        $_SESSION[
            'kintone_test_result'
        ]
    );

    render_head(
        'kintone連携設定'
    );

    render_flash();
?>
<div class="page-title">
<div>
<h1>kintone連携設定</h1>
<p>
顧客管理アプリとの接続・項目取得・マッピング・同期を行います。
</p>
</div>
</div>

<?php if (is_array($test)): ?>

<div class="alert <?= !empty($test['ok'])
    ? 'alert-success'
    : 'alert-error' ?>">

<strong>
<?= !empty($test['ok'])
    ? '接続成功'
    : '接続失敗' ?>
</strong>

<?php if (
    !empty($test['status'])
): ?>
<br>
HTTP <?= h($test['status']) ?>
<?php endif; ?>

<?php if (
    !empty($test['code'])
): ?>
<br>
エラーコード：
<?= h($test['code']) ?>
<?php endif; ?>

<?php if (
    !empty($test['id'])
): ?>
<br>
エラーID：
<?= h($test['id']) ?>
<?php endif; ?>

<?php if (
    !empty($test['message'])
): ?>
<br>
<?= h($test['message']) ?>
<?php endif; ?>

</div>

<?php endif; ?>

<div class="card">
<div class="card-header">
<h2>kintone接続設定</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_kintone">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>サブドメイン</span>

<input type="text"
       name="subdomain"
       value="<?= h(
           $config['subdomain']
       ) ?>"
       placeholder="https://xxxx.cybozu.com / xxxx.cybozu.com / xxxx"
       required>

</label>
</div>

<div class="form-group">
<label>
<span>顧客管理アプリID</span>

<input type="number"
       name="app_id"
       min="1"
       value="<?= h(
           $config['app_id']
       ) ?>"
       required>

</label>
</div>

<div class="form-group">
<label>
<span>ログイン名</span>

<input type="text"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>"
       autocomplete="username"
       required>

</label>
</div>

<div class="form-group">
<label>
<span>パスワード</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更時のみ入力">

</label>
</div>

<div class="form-group">
<label>
<span>Proxy</span>

<input type="text"
       name="proxy"
       value="<?= h(
           $config['proxy']
       ) ?>"
       placeholder="host:port">

</label>
</div>

<div class="form-group">

<label style="
display:flex;
gap:8px;
align-items:center;
margin-top:28px">

<input type="checkbox"
       name="verify_ssl"
       value="1"
       style="width:auto"
       <?= !empty(
           $config['verify_ssl']
       )
           ? 'checked'
           : '' ?>>

<span>
SSL証明書を検証する
</span>

</label>

</div>

</div>

<div class="button-row">

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</div>

</form>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:22px 0">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="test_kintone">

<input type="hidden"
       name="subdomain"
       value="<?= h(
           $config['subdomain']
       ) ?>">

<input type="hidden"
       name="app_id"
       value="<?= h(
           $config['app_id']
       ) ?>">

<input type="hidden"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>">

<input type="hidden"
       name="proxy"
       value="<?= h(
           $config['proxy']
       ) ?>">

<?php if (
    !empty($config['verify_ssl'])
): ?>

<input type="hidden"
       name="verify_ssl"
       value="1">

<?php endif; ?>

<div class="form-group">
<label>
<span>接続テスト用パスワード</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みの場合は空欄でも可">

</label>
</div>

<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>顧客項目マッピング</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="fetch_kintone_fields">

<button class="btn btn-secondary"
        type="submit">
項目一覧を再取得
</button>

</form>

<?php if (
    !empty($config['fields'])
): ?>

<form method="post"
      data-loading
      style="margin-top:20px">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<?php
$mappingLabels = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<div class="grid grid-2">

<?php foreach (
    $mappingLabels
    as $key => $label
): ?>

<div class="form-group">

<label>

<span><?= h($label) ?></span>

<select name="mapping_<?= h($key) ?>">

<option value="">
未設定
</option>

<?php foreach (
    $config['fields']
    as $field
): ?>

<option
    value="<?= h(
        $field['code']
    ) ?>"
    <?= (
        ($config['mapping'][$key]
        ?? '')
        === $field['code']
    )
        ? 'selected'
        : '' ?>>

<?= h(
    $field['label']
    . ' ('
    . $field['code']
    . ')'
) ?>

</option>

<?php endforeach; ?>

</select>

</label>

</div>

<?php endforeach; ?>

</div>

<div class="form-group">

<label>
<span>
住所
<span class="help">
（複数選択可）
</span>
</span>
</label>

<div class="address-map">

<?php foreach (
    $config['fields']
    as $field
): ?>

<label>

<input type="checkbox"
       name="mapping_address[]"
       value="<?= h(
           $field['code']
       ) ?>"
       style="width:auto"
       <?= in_array(
           $field['code'],
           $config['mapping']['address']
               ?? [],
           true
       )
           ? 'checked'
           : '' ?>>

<span>
<?= h($field['label']) ?>
（<?= h($field['code']) ?>）
</span>

</label>

<?php endforeach; ?>

</div>

</div>

<button class="btn btn-primary"
        type="submit">
マッピングを保存
</button>

</form>

<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>顧客情報同期</h2>
</div>

<div class="card-body">

<p>
kintoneの顧客管理アプリから顧客情報を取得し、
サーバー側の顧客情報を更新します。
</p>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-primary"
        type="submit"
        data-confirm="kintoneから顧客情報を同期しますか？">
顧客情報を同期
</button>

</form>

<?php if (
    !empty($config['last_sync'])
): ?>

<p class="help">
最終同期：
<?= h($config['last_sync']) ?>
</p>

<?php endif; ?>

</div>
</div>

<?php
    render_footer();
}

/* ============================================================
 * Mail screen
 * ============================================================ */

function render_mail(
    array $config
): void {
    $test =
        $_SESSION[
            'mail_test_result'
        ] ?? null;

    unset(
        $_SESSION[
            'mail_test_result'
        ]
    );

    render_head(
        'メールサーバ設定'
    );

    render_flash();
?>
<div class="page-title">
<div>
<h1>メールサーバ設定</h1>
<p>
SMTPサーバへの接続・認証設定を行います。
</p>
</div>
</div>

<?php if (is_array($test)): ?>

<div class="alert <?= !empty($test['ok'])
    ? 'alert-success'
    : 'alert-error' ?>">
<?= h(
    $test['message']
    ?? ''
) ?>
</div>

<?php endif; ?>

<div class="card">

<div class="card-header">
<h2>SMTP設定</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_mail">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>SMTPサーバ</span>

<input type="text"
       name="server"
       value="<?= h(
           $config['server']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>SMTPポート</span>

<input type="number"
       name="port"
       min="1"
       max="65535"
       value="<?= h(
           $config['port']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>暗号化方式</span>

<select name="encryption">

<option value="ssl"
    <?= $config['encryption']
        === 'ssl'
        ? 'selected'
        : '' ?>>
SSL
</option>

<option value="tls"
    <?= $config['encryption']
        === 'tls'
        ? 'selected'
        : '' ?>>
TLS
</option>

<option value="none"
    <?= $config['encryption']
        === 'none'
        ? 'selected'
        : '' ?>>
なし
</option>

</select>
</label>
</div>

<div class="form-group">

<label style="
display:flex;
gap:8px;
align-items:center;
margin-top:28px">

<input type="checkbox"
       name="auth"
       value="1"
       style="width:auto"
       <?= !empty(
           $config['auth']
       )
           ? 'checked'
           : '' ?>>

<span>
SMTP認証を使用
</span>

</label>

</div>

<div class="form-group">
<label>
<span>SMTPユーザー名</span>

<input type="text"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>"
       autocomplete="username">
</label>
</div>

<div class="form-group">
<label>
<span>SMTPパスワード</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="変更時のみ入力">
</label>
</div>

<div class="form-group">
<label>
<span>送信元メールアドレス</span>

<input type="email"
       name="from_email"
       value="<?= h(
           $config['from_email']
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>送信元名</span>

<input type="text"
       name="from_name"
       value="<?= h(
           $config['from_name']
       ) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>返信先メールアドレス</span>

<input type="email"
       name="reply_to"
       value="<?= h(
           $config['reply_to']
       ) ?>">
</label>
</div>

</div>

<button class="btn btn-primary"
        type="submit">
設定保存
</button>

</form>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:22px 0">

<div class="button-row">

<span class="badge badge-gray">
接続状態：
<?= h(
    $config['status']
) ?>
</span>

<?php if (
    !empty($config['last_test'])
): ?>

<span class="help">
最終確認：
<?= h($config['last_test']) ?>
</span>

<?php endif; ?>

</div>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:22px 0">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="test_mail">

<div class="form-group">
<label>
<span>
接続テスト用パスワード
</span>

<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みの場合は空欄でも可">
</label>
</div>

<button class="btn btn-secondary"
        type="submit">
接続テスト
</button>

</form>

<hr style="
border:0;
border-top:1px solid var(--border);
margin:22px 0">

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="send_test_mail">

<div class="form-group">

<label>
<span>
テスト送信先メールアドレス
</span>

<input type="email"
       name="test_email"
       required>
</label>

</div>

<button class="btn btn-primary"
        type="submit"
        data-confirm="テストメールを送信しますか？">
テストメール送信
</button>

</form>

</div>
</div>
<?php
    render_footer();
}

/* ============================================================
 * Send
 * ============================================================ */

function render_send(
    array $survey,
    array $customers,
    array $history
): void {
    render_head(
        '顧客選択・メール送信'
    );

    render_flash();
?>
<div class="page-title">
<div>
<h1>顧客選択・メール送信</h1>
<p>
対象アンケート：
<strong>
<?= h($survey['title']) ?>
</strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'list',
   ])) ?>">
一覧へ戻る
</a>
</div>

<div class="card">

<div class="card-header">
<h2>顧客選択・メール作成</h2>
</div>

<div class="card-body">

<div class="notice">
メール変数：
{顧客名} / {アンケートURL}
</div>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<div class="form-group">

<label>
<span>顧客検索</span>

<input type="search"
       id="customerSearch"
       placeholder="顧客名・組織名・メールアドレス">

</label>

</div>

<div class="form-group">

<label style="
display:flex;
gap:8px;
align-items:center">

<input type="checkbox"
       id="selectAll"
       style="width:auto">

<span>
すべて選択
</span>

</label>

</div>

<div class="table-scroll">

<table id="customerTable">

<thead>
<tr>
<th></th>
<th>組織名</th>
<th>氏名</th>
<th>メール</th>
<th>部署</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $customers as $customer
): ?>

<tr data-customer-row>

<td>
<input type="checkbox"
       class="customer-check"
       name="customer_ids[]"
       value="<?= h(
           $customer['id']
       ) ?>">
</td>

<td>
<?= h(
    $customer['organization']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['name']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['email']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $customer['department']
    ?? ''
) ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

<div class="grid grid-2"
     style="margin-top:20px">

<div class="form-group">
<label>
<span>メール件名</span>

<input type="text"
       name="subject"
       value="<?= h(
           $survey['title']
           . 'のご案内'
       ) ?>">
</label>
</div>

<div></div>

</div>

<div class="form-group">

<label>
<span>メール本文</span>

<textarea name="body"
          rows="10">アンケートへのご協力をお願いいたします。

{顧客名} 様

以下のURLからご回答ください。

{アンケートURL}</textarea>

</label>

</div>

<button class="btn btn-primary"
        type="submit"
        data-confirm="選択した顧客へ一括送信しますか？">
一括送信
</button>

</form>

</div>
</div>

<div class="card">

<div class="card-header">
<h2>送信履歴</h2>
</div>

<div class="card-body">

<div class="table-scroll">

<table>

<thead>
<tr>
<th>日時</th>
<th>顧客</th>
<th>種別</th>
<th>結果</th>
</tr>
</thead>

<tbody>

<?php
$surveyHistory = array_filter(
    $history,
    static function(
        array $item
    ) use ($survey): bool {
        return (
            ($item['survey_id'] ?? '')
            === ($survey['id'] ?? '')
        );
    }
);
?>

<?php if (!$surveyHistory): ?>

<tr>
<td colspan="4">
<div class="empty">
送信履歴はありません。
</div>
</td>
</tr>

<?php else: ?>

<?php foreach (
    $surveyHistory as $item
): ?>

<tr>

<td>
<?= h(
    $item['createdAt']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $item['customer_name']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $item['type']
    ?? '一括送信'
) ?>
</td>

<td>
<?= h(
    $item['result']
    ?? ''
) ?>
</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</div>
</div>

<script>
(function(){

    const search =
        document.getElementById(
            'customerSearch'
        );

    const rows =
        document.querySelectorAll(
            '[data-customer-row]'
        );

    if (search) {
        search.addEventListener(
            'input',
            function(){

                const q =
                    search.value
                        .trim()
                        .toLowerCase();

                rows.forEach(
                    function(row){

                        row.style.display =
                            row.textContent
                                .toLowerCase()
                                .includes(q)
                                    ? ''
                                    : 'none';
                    }
                );
            }
        );
    }

    const selectAll =
        document.getElementById(
            'selectAll'
        );

    if (selectAll) {

        selectAll.addEventListener(
            'change',
            function(){

                document
                    .querySelectorAll(
                        '.customer-check'
                    )
                    .forEach(
                        function(input){
                            input.checked =
                                selectAll.checked;
                        }
                    );
            }
        );
    }

})();
</script>
<?php
    render_footer();
}

/* ============================================================
 * Analytics
 * ============================================================ */

function render_analytics(
    array $survey,
    array $answers
): void {
    $surveyAnswers =
        array_values(
            array_filter(
                $answers,
                static function(
                    array $answer
                ) use ($survey): bool {
                    return (
                        ($answer['survey_id'] ?? '')
                        === ($survey['id'] ?? '')
                    );
                }
            )
        );

    $answerCount =
        count($surveyAnswers);

    $customers =
        load_customers();

    $sentCount = 0;

    render_head(
        '回答集計・分析'
    );

    render_flash();
?>
<div class="page-title">

<div>
<h1>回答集計・分析</h1>
<p>
対象アンケート：
<strong>
<?= h($survey['title']) ?>
</strong>
</p>
</div>

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'list',
   ])) ?>">
一覧へ戻る
</a>

</div>

<div class="stat-grid">

<div class="stat">
<div class="stat-label">
送信対象者数
</div>
<div class="stat-value">
<?= h($sentCount) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
回答数
</div>
<div class="stat-value">
<?= h($answerCount) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
未登録回答数
</div>
<div class="stat-value">
0
</div>
</div>

<div class="stat">
<div class="stat-label">
未回答数
</div>
<div class="stat-value">
<?= h(
    max(
        0,
        $sentCount
        - $answerCount
    )
) ?>
</div>
</div>

</div>

<div class="card">
<div class="card-header">
<h2>回答率</h2>
</div>
<div class="card-body">

<div class="kpi">

<div class="kpi-item">
回答数：
<strong>
<?= h($answerCount) ?>
</strong>
</div>

<div class="kpi-item">
回答率：
<strong>
<?= $sentCount > 0
    ? h(
        number_format(
            $answerCount
            / $sentCount
            * 100,
            1
        )
      )
      . '%'
    : '0%' ?>
</strong>
</div>

</div>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>設問別集計</h2>
</div>

<div class="card-body">

<?php if ($answerCount === 0): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h3>
<?= h($group['title']) ?>
</h3>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<?php
$counts = [];

foreach (
    $question['options'] ?? []
    as $option
) {
    $counts[$option] = 0;
}

foreach (
    $surveyAnswers as $answer
) {
    $value =
        $answer['answers'][
            $question['id']
        ] ?? '';

    if (is_array($value)) {
        foreach ($value as $v) {
            if (isset($counts[$v])) {
                $counts[$v]++;
            }
        }
    } else {
        if (isset($counts[$value])) {
            $counts[$value]++;
        }
    }
}
?>

<?php if ($question['type'] !== 'text'): ?>

<ul>

<?php foreach (
    $counts as $option => $count
): ?>

<li>
<?= h($option) ?>：
<?= h($count) ?>件
</li>

<?php endforeach; ?>

</ul>

<?php else: ?>

<p class="help">
自由記述回答あり
</p>

<?php endif; ?>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-header">
<h2>個別回答</h2>
</div>

<div class="card-body">

<?php if (!$surveyAnswers): ?>

<div class="empty">
現在、回答データはありません
</div>

<?php else: ?>

<?php foreach (
    $surveyAnswers as $answer
): ?>

<div class="notice">

<strong>
<?= h(
    $answer['createdAt']
    ?? ''
) ?>
</strong>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$value =
    $answer['answers'][
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $value =
        implode(
            '、',
            array_map(
                'strval',
                $value
            )
        );
}
?>

<p>
<strong>
<?= h($question['number']) ?>
</strong>
：
<?= nl2br(
    h($value)
) ?>
</p>

<?php endforeach; ?>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>
<?php
    render_footer();
}

/* ============================================================
 * Answer
 * ============================================================ */

function render_answer(
    array $survey
): void {
    recalc_question_numbers(
        $survey
    );

    render_head(
        $survey['title'],
        false
    );
?>
<div class="page-title">
<div>
<h1>
<?= h($survey['title']) ?>
</h1>

<?php if (
    trim(
        (string)$survey['description']
    ) !== ''
): ?>

<p>
<?= nl2br(
    h($survey['description'])
) ?>
</p>

<?php endif; ?>

</div>
</div>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="answer">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="card">

<div class="card-header">
<h2>
<?= h($group['title']) ?>
</h2>
</div>

<div class="card-body">

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="preview-question">

<strong>

<?= h(
    $question['number']
) ?>

<?= h(
    $question['text']
) ?>

<?php if (
    !empty($question['required'])
): ?>

<span class="required">
必須
</span>

<?php endif; ?>

</strong>

<div style="margin-top:12px">

<?php if (
    ($question['type'] ?? '')
    === 'text'
): ?>

<textarea
    name="answer_<?= h(
        $question['id']
    ) ?>"
    <?= !empty(
        $question['required']
    )
        ? 'required'
        : '' ?>></textarea>

<?php elseif (
    ($question['type'] ?? '')
    === 'multiple'
): ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<label class="answer-option">

<input type="checkbox"
       name="answer_<?= h(
           $question['id']
       ) ?>[]"
       value="<?= h(
           $option
       ) ?>">

<span>
<?= h($option) ?>
</span>

</label>

<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    $question['options'] ?? []
    as $option
): ?>

<label class="answer-option">

<input type="radio"
       name="answer_<?= h(
           $question['id']
       ) ?>"
       value="<?= h(
           $option
       ) ?>"
       <?= !empty(
           $question['required']
       )
           ? 'required'
           : '' ?>>

<span>
<?= h($option) ?>
</span>

</label>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<div class="button-row"
     style="justify-content:flex-end">

<button class="btn btn-primary"
        type="submit">
回答を確認
</button>

</div>

</form>
<?php
    render_footer();
}

/* ============================================================
 * Confirm
 * ============================================================ */

function render_confirm(
    array $survey
): void {
    $draft =
        $_SESSION[
            'answer_draft'
        ] ?? [];

    $values =
        is_array($draft)
            ? (
                $draft['answers']
                ?? []
            )
            : [];

    render_head(
        '回答確認',
        false
    );
?>
<div class="page-title">
<div>
<h1>回答確認</h1>
<p>
<?= h($survey['title']) ?>
</p>
</div>
</div>

<div class="card">
<div class="card-body">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<h2>
<?= h($group['title']) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<?php
$value =
    $values[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    $value =
        implode(
            '、',
            $value
        );
}
?>

<div class="preview-question">

<strong>
<?= h($question['number']) ?>
<?= h($question['text']) ?>
</strong>

<div class="notice"
     style="margin-top:10px">

<?= nl2br(
    h($value)
) ?>

</div>

</div>

<?php endforeach; ?>

<?php endforeach; ?>

<div class="button-row"
     style="
     justify-content:space-between;
     margin-top:20px">

<a class="btn btn-secondary"
   href="<?= h(app_url([
       'screen' => 'answer',
       'id' => $survey['id'],
   ])) ?>">
戻って修正
</a>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="finalize_answer">

<button class="btn btn-primary"
        type="submit"
        data-confirm="この回答を送信しますか？">
回答を送信
</button>

</form>

</div>

</div>
</div>
<?php
    render_footer();
}

/* ============================================================
 * Complete
 * ============================================================ */

function render_complete(): void
{
    render_head(
        '回答完了',
        false
    );
?>
<div class="card">

<div class="card-body"
     style="
     text-align:center;
     padding:60px 20px">

<div style="
width:64px;
height:64px;
border-radius:50%;
background:#dcfce7;
color:#16a34a;
display:flex;
align-items:center;
justify-content:center;
margin:0 auto 18px;
font-size:30px;">
✓
</div>

<h1>
回答ありがとうございました
</h1>

<p>
アンケートの回答を受け付けました。
</p>

</div>
</div>
<?php
    render_footer();
}

/* ============================================================
 * Routing
 * ============================================================ */

if (
    in_array(
        $screen,
        ['answer', 'confirm'],
        true
    )
) {
    $survey =
        find_survey(
            $surveys,
            $id
        );

    if ($survey === null) {
        render_head(
            'アンケート',
            false
        );

        ?>
<div class="alert alert-error">
アンケートが見つかりません。
</div>
        <?php

        render_footer();
        exit;
    }

    refresh_survey_status(
        $survey
    );

    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        render_head(
            'アンケート',
            false
        );

        ?>
<div class="alert alert-warning">
このアンケートは現在回答を受け付けていません。
</div>
        <?php

        render_footer();
        exit;
    }

    if ($screen === 'answer') {
        render_answer(
            $survey
        );
    } else {
        render_confirm(
            $survey
        );
    }

    exit;
}

if ($screen === 'complete') {
    render_complete();
    exit;
}

/* ============================================================
 * Admin routing
 * ============================================================ */

switch ($screen) {

    case 'list':
        render_list(
            $surveys
        );
        break;

    case 'edit':

        $survey =
            $id !== ''
                ? find_survey(
                    $surveys,
                    $id
                )
                : null;

        if (
            $id !== ''
            && $survey === null
        ) {
            flash(
                'error',
                '指定されたアンケートが見つかりません。'
            );

            redirect_to([
                'screen' => 'list',
            ]);
        }

        if ($survey === null) {
            $survey =
                new_survey();
        }

        refresh_survey_status(
            $survey
        );

        render_edit(
            $survey
        );

        break;

    case 'preview':

        $survey =
            find_survey(
                $surveys,
                $id
            );

        render_preview(
            $survey
        );

        break;

    case 'send':

        $survey =
            find_survey(
                $surveys,
                $id
            );

        if ($survey === null) {
            flash(
                'error',
                '送信対象のアンケートが見つかりません。'
            );

            redirect_to([
                'screen' => 'list',
            ]);
        }

        render_send(
            $survey,
            $customers,
            $history
        );

        break;

    case 'analytics':

        $survey =
            find_survey(
                $surveys,
                $id
            );

        if ($survey === null) {
            flash(
                'error',
                '集計対象のアンケートが見つかりません。'
            );

            redirect_to([
                'screen' => 'list',
            ]);
        }

        render_analytics(
            $survey,
            $answers
        );

        break;

    case 'kintone':

        render_kintone(
            $kintone
        );

        break;

    case 'mail':

        render_mail(
            $mail
        );

        break;

    default:

        render_list(
            $surveys
        );

        break;
}
?>
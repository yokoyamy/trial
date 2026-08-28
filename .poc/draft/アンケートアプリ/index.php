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
 * 外部通信:
 * kintone REST API : PHP stream
 * SMTP             : PHP socket/stream
 */

date_default_timezone_set('Asia/Tokyo');

const APP_TITLE = 'アンケート管理システム';
const DATA_DIR_NAME = 'data';

$APP_DIR  = __DIR__;
$DATA_DIR = $APP_DIR . DIRECTORY_SEPARATOR . DATA_DIR_NAME;

/* ============================================================
 * データ保存領域
 * ============================================================ */

if (!is_dir($DATA_DIR)) {
    if (!@mkdir($DATA_DIR, 0770, true) && !is_dir($DATA_DIR)) {
        http_response_code(500);
        exit('データ保存領域を作成できません。');
    }
}

/*
 * Apache環境でdataディレクトリを直接取得されにくくする。
 * Rewriteを前提とせず、可能な範囲でアクセスを拒否する。
 */
$htaccess = $DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents(
        $htaccess,
        "Require all denied\n"
        . "<IfModule mod_authz_core.c>\n"
        . "Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "Deny from all\n"
        . "</IfModule>\n",
        LOCK_EX
    );
}

/* ============================================================
 * セッション
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
 * 共通
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
    $value = $_GET[$key] ?? '';

    return is_scalar($value)
        ? trim((string)$value)
        : '';
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_scalar($value)
        ? trim((string)$value)
        : '';
}

function post_array(string $key): array
{
    $value = $_POST[$key] ?? [];

    if (!is_array($value)) {
        return [];
    }

    $result = [];

    foreach ($value as $item) {
        if (is_scalar($item)) {
            $item = trim((string)$item);

            if ($item !== '') {
                $result[] = $item;
            }
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
    return (bool)preg_match(
        '/^[A-Za-z0-9_-]{1,100}$/',
        $id
    );
}

function new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
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
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($value) ? $value : null;
}

function data_file(string $name): string
{
    global $DATA_DIR;

    return $DATA_DIR . DIRECTORY_SEPARATOR . $name;
}

/* ============================================================
 * JSON永続化
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

    if (
        $decoded === null
        && json_last_error() !== JSON_ERROR_NONE
    ) {
        return $default;
    }

    return $decoded;
}

function json_write(string $file, mixed $data): bool
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp =
        $file
        . '.tmp.'
        . bin2hex(random_bytes(6));

    if (
        @file_put_contents(
            $tmp,
            $json,
            LOCK_EX
        ) === false
    ) {
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
 * データロード
 * ============================================================ */

function load_surveys(): array
{
    $data = json_read(
        data_file('surveys.json'),
        []
    );

    return is_array($data) ? $data : [];
}

function save_surveys(array $data): bool
{
    return json_write(
        data_file('surveys.json'),
        array_values($data)
    );
}

function load_customers(): array
{
    $data = json_read(
        data_file('customers.json'),
        []
    );

    return is_array($data) ? $data : [];
}

function save_customers(array $data): bool
{
    return json_write(
        data_file('customers.json'),
        array_values($data)
    );
}

function load_answers(): array
{
    $data = json_read(
        data_file('answers.json'),
        []
    );

    return is_array($data) ? $data : [];
}

function save_answers(array $data): bool
{
    return json_write(
        data_file('answers.json'),
        array_values($data)
    );
}

function load_history(): array
{
    $data = json_read(
        data_file('send_history.json'),
        []
    );

    return is_array($data) ? $data : [];
}

function save_history(array $data): bool
{
    return json_write(
        data_file('send_history.json'),
        array_values($data)
    );
}

/* ============================================================
 * 秘密情報
 * ============================================================ */

function encryption_key(): string
{
    global $DATA_DIR;

    $env = getenv('APP_ENCRYPTION_KEY');

    if (
        is_string($env)
        && strlen($env) >= 32
    ) {
        return hash(
            'sha256',
            $env,
            true
        );
    }

    $file =
        $DATA_DIR
        . DIRECTORY_SEPARATOR
        . '.key';

    if (is_file($file)) {
        $key = @file_get_contents($file);

        if (
            is_string($key)
            && strlen($key) >= 32
        ) {
            return hash(
                'sha256',
                $key,
                true
            );
        }
    }

    $key = bin2hex(random_bytes(32));

    if (
        @file_put_contents(
            $file,
            $key,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(
            '暗号化キーを保存できません。'
        );
    }

    @chmod($file, 0600);

    return hash(
        'sha256',
        $key,
        true
    );
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
        throw new RuntimeException(
            '秘密情報を暗号化できません。'
        );
    }

    $mac = hash_hmac(
        'sha256',
        $iv . $cipher,
        $key,
        true
    );

    return base64_encode(
        $iv . $mac . $cipher
    );
}

function decrypt_secret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    $raw = base64_decode(
        $encoded,
        true
    );

    if (
        $raw === false
        || strlen($raw) < 48
    ) {
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

    return is_string($plain)
        ? $plain
        : '';
}

/* ============================================================
 * kintone設定
 * ============================================================ */

function default_kintone(): array
{
    return [
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
    ];
}

function load_kintone(): array
{
    $data = json_read(
        data_file('kintone.json'),
        []
    );

    if (!is_array($data)) {
        $data = [];
    }

    return array_replace_recursive(
        default_kintone(),
        $data
    );
}

function save_kintone(array $data): bool
{
    unset($data['password']);

    return json_write(
        data_file('kintone.json'),
        $data
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

    $value = trim(
        (string)$value,
        '/'
    );

    $value = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $value
    );

    return trim(
        (string)$value
    );
}

function kintone_host(array $config): string
{
    return normalize_subdomain(
        (string)(
            $config['subdomain'] ?? ''
        )
    ) . '.cybozu.com';
}

function parse_proxy(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (
        !preg_match(
            '/^([^:\/\s]+):([0-9]{1,5})$/',
            $proxy,
            $m
        )
    ) {
        return null;
    }

    $port = (int)$m[2];

    if (
        $port < 1
        || $port > 65535
    ) {
        return null;
    }

    return [
        'host' => $m[1],
        'port' => $port,
    ];
}

function validate_kintone_config(
    array $config,
    bool $requirePassword = true
): array {
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
        $errors[] =
            'サブドメインを正しく入力してください。';
    }

    if (
        $appId === ''
        || !ctype_digit($appId)
        || (int)$appId < 1
    ) {
        $errors[] =
            '顧客管理アプリIDを正しく入力してください。';
    }

    if ($username === '') {
        $errors[] =
            'ログイン名を入力してください。';
    }

    if (
        $proxy !== ''
        && parse_proxy($proxy) === null
    ) {
        $errors[] =
            'Proxyは「host:port」形式で入力してください。';
    }

    if (
        $requirePassword
        && empty($config['password_encrypted'])
        && empty($config['password'])
    ) {
        $errors[] =
            'kintoneパスワードが設定されていません。';
    }

    return [
        'subdomain' => $subdomain,
        'app_id' => $appId,
        'username' => $username,
        'proxy' => $proxy,
        'errors' => $errors,
    ];
}

/* ============================================================
 * kintone REST API
 *
 * 通信処理を画面処理から完全分離する。
 * ============================================================ */

function kintone_request(
    array $config,
    string $method,
    string $path,
    ?array $body = null
): array {
    $validation =
        validate_kintone_config(
            $config,
            true
        );

    if ($validation['errors']) {
        return [
            'ok' => false,
            'category' => '入力エラー',
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' =>
                implode(
                    ' ',
                    $validation['errors']
                ),
            'data' => null,
        ];
    }

    $password = '';

    if (
        !empty(
            $config['password_encrypted']
        )
    ) {
        $password = decrypt_secret(
            (string)$config[
                'password_encrypted'
            ]
        );
    }

    if (
        $password === ''
        && !empty($config['password'])
    ) {
        $password =
            (string)$config['password'];
    }

    if ($password === '') {
        return [
            'ok' => false,
            'category' => '設定エラー',
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' =>
                'kintoneパスワードが設定されていません。',
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
            'message' =>
                'kintoneホスト名が不正です。',
            'data' => null,
        ];
    }

    if (
        $path === ''
        || $path[0] !== '/'
    ) {
        $path = '/' . $path;
    }

    $url =
        'https://'
        . $host
        . $path;

    $headers = [
        'X-Cybozu-Authorization: '
            . base64_encode(
                (string)$config['username']
                . ':'
                . $password
            ),
        'Accept: application/json',
        'User-Agent: SurveyApp/2.0',
        'Connection: close',
    ];

    $http = [
        'method' =>
            strtoupper($method),
        'header' =>
            implode(
                "\r\n",
                $headers
            ),
        'timeout' => 20,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
        'follow_location' => 0,
        'max_redirects' => 0,
    ];

    if ($body !== null) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return [
                'ok' => false,
                'category' => 'データエラー',
                'status' => 0,
                'code' => '',
                'id' => '',
                'message' =>
                    'JSONリクエストを生成できません。',
                'data' => null,
            ];
        }

        $http['content'] = $json;

        $headers[] =
            'Content-Type: application/json';

        $headers[] =
            'Content-Length: '
            . strlen($json);

        $http['header'] =
            implode(
                "\r\n",
                $headers
            );
    }

    $proxy = parse_proxy(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== null) {
        $http['proxy'] =
            'tcp://'
            . $proxy['host']
            . ':'
            . $proxy['port'];

        $http['request_fulluri'] = true;
    }

    $verifySsl =
        !empty($config['verify_ssl']);

    $context = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' =>
                $verifySsl,
            'verify_peer_name' =>
                $verifySsl,
            'allow_self_signed' =>
                !$verifySsl,
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

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    restore_error_handler();

    $status = 0;
    $responseHeaders =
        $http_response_header ?? [];

    foreach ($responseHeaders as $header) {
        if (
            preg_match(
                '#^HTTP/\S+\s+(\d{3})#',
                $header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    if ($response === false) {
        return [
            'ok' => false,
            'category' => '通信エラー',
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' =>
                $error !== ''
                    ? $error
                    : 'kintoneへの通信に失敗しました。',
            'data' => null,
        ];
    }

    $decoded = json_decode(
        $response,
        true
    );

    if (!is_array($decoded)) {
        $decoded = [];
    }

    $code = (string)(
        $decoded['code'] ?? ''
    );

    $id = (string)(
        $decoded['id'] ?? ''
    );

    $message = (string)(
        $decoded['message'] ?? ''
    );

    if (
        $status >= 200
        && $status < 300
        && $code === ''
    ) {
        return [
            'ok' => true,
            'category' => '成功',
            'status' => $status,
            'code' => '',
            'id' => '',
            'message' =>
                'kintone APIの処理に成功しました。',
            'data' => $decoded,
        ];
    }

    $category = match (true) {
        $status === 400 => '入力エラー',
        $status === 401 => '認証エラー',
        $status === 403 => '権限エラー',
        $status === 404 => 'APIエラー',
        $status >= 500 => 'kintoneサーバーエラー',
        default => '外部サービスエラー',
    };

    return [
        'ok' => false,
        'category' => $category,
        'status' => $status,
        'code' => $code,
        'id' => $id,
        'message' =>
            $message !== ''
                ? $message
                : 'kintone APIからエラーが返されました。',
        'data' => $decoded,
    ];
}

function kintone_connection_test(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app.json?id='
            . rawurlencode(
                (string)$config['app_id']
            )
    );
}

function kintone_fetch_fields(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/app/form/fields.json?app='
            . rawurlencode(
                (string)$config['app_id']
            )
    );
}

function kintone_fetch_records(
    array $config
): array {
    return kintone_request(
        $config,
        'GET',
        '/k/v1/records.json?app='
            . rawurlencode(
                (string)$config['app_id']
            )
    );
}

/* ============================================================
 * メール設定
 * ============================================================ */

function default_mail(): array
{
    return [
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
    ];
}

function load_mail(): array
{
    $data = json_read(
        data_file('mail.json'),
        []
    );

    if (!is_array($data)) {
        $data = [];
    }

    return array_replace(
        default_mail(),
        $data
    );
}

function save_mail(array $data): bool
{
    unset($data['password']);

    return json_write(
        data_file('mail.json'),
        $data
    );
}

function validate_mail_config(
    array $config
): array {
    $errors = [];

    $server = trim(
        (string)($config['server'] ?? '')
    );

    $port = (int)(
        $config['port'] ?? 0
    );

    $encryption =
        (string)(
            $config['encryption']
            ?? ''
        );

    $fromEmail = trim(
        (string)(
            $config['from_email']
            ?? ''
        )
    );

    $replyTo = trim(
        (string)(
            $config['reply_to']
            ?? ''
        )
    );

    if ($server === '') {
        $errors[] =
            'SMTPサーバを入力してください。';
    }

    if (
        $port < 1
        || $port > 65535
    ) {
        $errors[] =
            'SMTPポートを正しく入力してください。';
    }

    if (
        !in_array(
            $encryption,
            ['ssl', 'tls', 'none'],
            true
        )
    ) {
        $errors[] =
            '暗号化方式が不正です。';
    }

    if (
        !filter_var(
            $fromEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '送信元メールアドレスを正しく入力してください。';
    }

    if (
        $replyTo !== ''
        && !filter_var(
            $replyTo,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            '返信先メールアドレスを正しく入力してください。';
    }

    if (
        !empty($config['auth'])
        && trim(
            (string)(
                $config['username'] ?? ''
            )
        ) === ''
    ) {
        $errors[] =
            'SMTP認証を使用する場合はユーザー名を入力してください。';
    }

    return $errors;
}

/* ============================================================
 * SMTP
 *
 * PHP mail()は使用しない。
 * ============================================================ */

function smtp_read(
    $socket
): array {
    $lines = [];
    $code = 0;

    while (!feof($socket)) {
        $line = fgets(
            $socket,
            4096
        );

        if ($line === false) {
            break;
        }

        $lines[] = rtrim(
            $line,
            "\r\n"
        );

        if (
            preg_match(
                '/^(\d{3})([ -])/',
                $line,
                $m
            )
        ) {
            $code = (int)$m[1];

            if ($m[2] === ' ') {
                break;
            }
        }
    }

    return [
        'code' => $code,
        'text' => implode(
            "\n",
            $lines
        ),
    ];
}

function smtp_command(
    $socket,
    string $command,
    array $acceptedCodes
): array {
    if (
        fwrite(
            $socket,
            $command . "\r\n"
        ) === false
    ) {
        return [
            'ok' => false,
            'code' => 0,
            'text' => 'SMTPコマンド送信に失敗しました。',
        ];
    }

    $reply = smtp_read($socket);

    return [
        'ok' =>
            in_array(
                $reply['code'],
                $acceptedCodes,
                true
            ),
        'code' =>
            $reply['code'],
        'text' =>
            $reply['text'],
    ];
}

function smtp_open(
    array $config
): array {
    $errors =
        validate_mail_config($config);

    if ($errors) {
        return [
            'ok' => false,
            'category' => '入力エラー',
            'message' =>
                implode(
                    ' ',
                    $errors
                ),
            'socket' => null,
        ];
    }

    $server =
        trim(
            (string)$config['server']
        );

    $port =
        (int)$config['port'];

    $encryption =
        (string)$config['encryption'];

    $host =
        $encryption === 'ssl'
            ? 'ssl://' . $server
            : $server;

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $host . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'ok' => false,
            'category' => '通信エラー',
            'message' =>
                'SMTPサーバへ接続できません。'
                . ($errstr !== ''
                    ? ' ' . $errstr
                    : ''),
            'socket' => null,
        ];
    }

    stream_set_timeout(
        $socket,
        20
    );

    $reply = smtp_read($socket);

    if (
        !in_array(
            $reply['code'],
            [220],
            true
        )
    ) {
        fclose($socket);

        return [
            'ok' => false,
            'category' => 'SMTPエラー',
            'message' =>
                'SMTPサーバの初期応答が不正です。'
                . ' [' . $reply['code'] . ']',
            'socket' => null,
        ];
    }

    $helo = smtp_command(
        $socket,
        'EHLO localhost',
        [250]
    );

    if (!$helo['ok']) {
        fclose($socket);

        return [
            'ok' => false,
            'category' => 'SMTPエラー',
            'message' =>
                'EHLOに失敗しました。'
                . ' [' . $helo['code'] . ']',
            'socket' => null,
        ];
    }

    if ($encryption === 'tls') {
        $tls = smtp_command(
            $socket,
            'STARTTLS',
            [220]
        );

        if (!$tls['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'category' => 'SMTPエラー',
                'message' =>
                    'STARTTLSに失敗しました。'
                    . ' [' . $tls['code'] . ']',
                'socket' => null,
            ];
        }

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            fclose($socket);

            return [
                'ok' => false,
                'category' => 'TLSエラー',
                'message' =>
                    'TLS暗号化を開始できません。',
                'socket' => null,
            ];
        }

        $helo = smtp_command(
            $socket,
            'EHLO localhost',
            [250]
        );

        if (!$helo['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'category' => 'SMTPエラー',
                'message' =>
                    'TLS後のEHLOに失敗しました。',
                'socket' => null,
            ];
        }
    }

    if (!empty($config['auth'])) {
        $auth = smtp_command(
            $socket,
            'AUTH LOGIN',
            [334]
        );

        if (!$auth['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'category' => '認証エラー',
                'message' =>
                    'SMTP認証開始に失敗しました。',
                'socket' => null,
            ];
        }

        $user = smtp_command(
            $socket,
            base64_encode(
                (string)$config['username']
            ),
            [334]
        );

        if (!$user['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'category' => '認証エラー',
                'message' =>
                    'SMTPユーザー名の認証に失敗しました。',
                'socket' => null,
            ];
        }

        $password = '';

        if (
            !empty(
                $config['password_encrypted']
            )
        ) {
            $password =
                decrypt_secret(
                    (string)(
                        $config[
                            'password_encrypted'
                        ]
                    )
                );
        }

        if (
            $password === ''
            && !empty($config['password'])
        ) {
            $password =
                (string)$config['password'];
        }

        if ($password === '') {
            fclose($socket);

            return [
                'ok' => false,
                'category' => '設定エラー',
                'message' =>
                    'SMTPパスワードが設定されていません。',
                'socket' => null,
            ];
        }

        $pass = smtp_command(
            $socket,
            base64_encode($password),
            [235]
        );

        if (!$pass['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'category' => '認証エラー',
                'message' =>
                    'SMTPパスワード認証に失敗しました。',
                'socket' => null,
            ];
        }
    }

    return [
        'ok' => true,
        'category' => '成功',
        'message' =>
            'SMTP接続・認証に成功しました。',
        'socket' => $socket,
    ];
}

function smtp_close($socket): void
{
    if (!is_resource($socket)) {
        return;
    }

    @smtp_command(
        $socket,
        'QUIT',
        [221]
    );

    @fclose($socket);
}

function smtp_test(
    array $config
): array {
    $connection =
        smtp_open($config);

    if (!$connection['ok']) {
        return $connection;
    }

    smtp_close(
        $connection['socket']
    );

    return [
        'ok' => true,
        'category' => '成功',
        'message' =>
            'SMTP接続・認証に成功しました。',
    ];
}

function smtp_send_mail(
    array $config,
    string $to,
    string $subject,
    string $body
): array {
    if (
        !filter_var(
            $to,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'ok' => false,
            'category' => '入力エラー',
            'message' =>
                '宛先メールアドレスが不正です。',
        ];
    }

    $connection =
        smtp_open($config);

    if (!$connection['ok']) {
        return $connection;
    }

    $socket =
        $connection['socket'];

    $from =
        (string)$config['from_email'];

    $mailFrom = smtp_command(
        $socket,
        'MAIL FROM:<' . $from . '>',
        [250]
    );

    if (!$mailFrom['ok']) {
        smtp_close($socket);

        return [
            'ok' => false,
            'category' => 'SMTPエラー',
            'message' =>
                'MAIL FROMに失敗しました。',
        ];
    }

    $rcpt = smtp_command(
        $socket,
        'RCPT TO:<' . $to . '>',
        [250, 251]
    );

    if (!$rcpt['ok']) {
        smtp_close($socket);

        return [
            'ok' => false,
            'category' => 'SMTPエラー',
            'message' =>
                'RCPT TOに失敗しました。',
        ];
    }

    $data = smtp_command(
        $socket,
        'DATA',
        [354]
    );

    if (!$data['ok']) {
        smtp_close($socket);

        return [
            'ok' => false,
            'category' => 'SMTPエラー',
            'message' =>
                'DATAに失敗しました。',
        ];
    }

    $fromName =
        (string)$config['from_name'];

    $encodedSubject =
        '=?UTF-8?B?'
        . base64_encode($subject)
        . '?=';

    $headers = [];

    $headers[] =
        'From: '
        . ($fromName !== ''
            ? '=?UTF-8?B?'
                . base64_encode($fromName)
                . '?= '
            : '')
        . '<' . $from . '>';

    $headers[] =
        'To: <' . $to . '>';

    $headers[] =
        'Subject: ' . $encodedSubject;

    $headers[] =
        'MIME-Version: 1.0';

    $headers[] =
        'Content-Type: text/plain; charset=UTF-8';

    $headers[] =
        'Content-Transfer-Encoding: 8bit';

    if (
        !empty($config['reply_to'])
    ) {
        $headers[] =
            'Reply-To: '
            . $config['reply_to'];
    }

    $safeBody =
        str_replace(
            ["\r\n.", "\n."],
            ["\r\n..", "\n.."],
            $body
        );

    $message =
        implode(
            "\r\n",
            $headers
        )
        . "\r\n\r\n"
        . $safeBody
        . "\r\n.";

    if (
        fwrite(
            $socket,
            $message . "\r\n"
        ) === false
    ) {
        smtp_close($socket);

        return [
            'ok' => false,
            'category' => 'SMTPエラー',
            'message' =>
                'メール本文を送信できませんでした。',
        ];
    }

    $reply =
        smtp_read($socket);

    smtp_close($socket);

    if ($reply['code'] !== 250) {
        return [
            'ok' => false,
            'category' => 'SMTPエラー',
            'message' =>
                'メール送信に失敗しました。'
                . ' [' . $reply['code'] . ']',
        ];
    }

    return [
        'ok' => true,
        'category' => '成功',
        'message' =>
            'メールを送信しました。',
    ];
}

/* ============================================================
 * アンケート
 * ============================================================ */

function default_question(): array
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

function default_group(): array
{
    return [
        'id' => new_id('g'),
        'title' => '新しいグループ',
        'questions' => [
            default_question(),
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
                    default_question(),
                ],
            ],
        ],
    ];
}

function status_label(
    string $status
): string {
    return match ($status) {
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
        default => '下書き',
    };
}

function status_class(
    string $status
): string {
    return match ($status) {
        'published' => 'success',
        'stopped' => 'warning',
        'ended' => 'gray',
        default => 'draft',
    };
}

function refresh_survey_status(
    array &$survey
): bool {
    if (
        ($survey['status'] ?? 'draft')
        !== 'published'
    ) {
        return false;
    }

    if (
        empty($survey['endAt'])
    ) {
        return false;
    }

    $end =
        strtotime(
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

    return false;
}

function refresh_all_statuses(
    array &$surveys
): void {
    $changed = false;

    foreach (
        $surveys as &$survey
    ) {
        if (
            refresh_survey_status(
                $survey
            )
        ) {
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
            ($survey['id'] ?? '')
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
    foreach (
        $surveys as $index => $survey
    ) {
        if (
            ($survey['id'] ?? '')
            === $id
        ) {
            return $index;
        }
    }

    return -1;
}

function recalc_question_numbers(
    array &$survey
): void {
    $mode =
        $survey['numbering']
        ?? 'global';

    $global = 1;
    $groupNo = 1;

    foreach (
        $survey['groups']
        as &$group
    ) {
        $questionNo = 1;

        foreach (
            $group['questions']
            as &$question
        ) {
            if ($mode === 'group') {
                $question['number'] =
                    'Q'
                    . $groupNo
                    . '-'
                    . $questionNo;
            } else {
                $question['number'] =
                    'Q' . $global;
            }

            $questionNo++;
            $global++;
        }

        unset($question);

        $groupNo++;
    }

    unset($group);
}

/* ============================================================
 * POST処理
 * ============================================================ */

$surveys = load_surveys();
refresh_all_statuses($surveys);

$kintone = load_kintone();
$mail = load_mail();

$screen = get_string('screen');
$id = get_string('id');

if ($screen === '') {
    $screen = 'list';
}

$redirect = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action');

    try {
        switch ($action) {

            /* ------------------------------------------------
             * kintone設定保存
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
                    post_string('verify_ssl')
                    === '1';

                $password =
                    post_string('password');

                if ($password !== '') {
                    $candidate[
                        'password_encrypted'
                    ] =
                        encrypt_secret(
                            $password
                        );
                }

                $validation =
                    validate_kintone_config(
                        $candidate,
                        $password === ''
                            ? true
                            : false
                    );

                if (
                    $validation['errors']
                ) {
                    throw new InvalidArgumentException(
                        implode(
                            ' ',
                            $validation['errors']
                        )
                    );
                }

                if (
                    !save_kintone(
                        $candidate
                    )
                ) {
                    throw new RuntimeException(
                        'kintone設定を保存できません。'
                    );
                }

                $kintone = $candidate;

                flash(
                    'success',
                    'kintone設定を保存しました。'
                );

                $redirect = [
                    'screen' => 'kintone',
                ];
                break;

            /* ------------------------------------------------
             * kintone接続テスト
             * ------------------------------------------------ */

            case 'test_kintone':
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
                    post_string('verify_ssl')
                    === '1';

                $password =
                    post_string('password');

                if ($password !== '') {
                    $candidate[
                        'password_encrypted'
                    ] =
                        encrypt_secret(
                            $password
                        );
                }

                $validation =
                    validate_kintone_config(
                        $candidate,
                        true
                    );

                if (
                    $validation['errors']
                ) {
                    $_SESSION[
                        'kintone_test_result'
                    ] = [
                        'ok' => false,
                        'category' => '入力エラー',
                        'status' => 0,
                        'code' => '',
                        'id' => '',
                        'message' =>
                            implode(
                                ' ',
                                $validation['errors']
                            ),
                    ];

                    $redirect = [
                        'screen' => 'kintone',
                    ];

                    break;
                }

                $result =
                    kintone_connection_test(
                        $candidate
                    );

                $_SESSION[
                    'kintone_test_result'
                ] = $result;

                if ($result['ok']) {
                    $kintone =
                        array_replace(
                            $kintone,
                            $candidate
                        );

                    $kintone['status'] =
                        '接続確認済み';

                    $kintone['last_test'] =
                        now_iso();

                    save_kintone(
                        $kintone
                    );
                } else {
                    $kintone['status'] =
                        '接続できません';

                    save_kintone(
                        $kintone
                    );
                }

                $redirect = [
                    'screen' => 'kintone',
                ];
                break;

            /* ------------------------------------------------
             * kintone項目一覧取得
             * ------------------------------------------------ */

            case 'fetch_kintone_fields':
                $result =
                    kintone_fetch_fields(
                        $kintone
                    );

                if (!$result['ok']) {
                    $_SESSION[
                        'kintone_fields_result'
                    ] = $result;

                    $redirect = [
                        'screen' => 'kintone',
                    ];

                    break;
                }

                $fields = [];

                foreach (
                    (
                        $result['data']['properties']
                        ?? []
                    ) as $code => $field
                ) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $fields[] = [
                        'code' =>
                            (string)$code,
                        'label' =>
                            (string)(
                                $field['label']
                                ?? $code
                            ),
                        'type' =>
                            (string)(
                                $field['type']
                                ?? ''
                            ),
                    ];
                }

                usort(
                    $fields,
                    static function (
                        array $a,
                        array $b
                    ): int {
                        return strcmp(
                            $a['label'],
                            $b['label']
                        );
                    }
                );

                $kintone['fields'] =
                    $fields;

                save_kintone(
                    $kintone
                );

                $_SESSION[
                    'kintone_fields_result'
                ] = [
                    'ok' => true,
                    'category' => '成功',
                    'status' =>
                        $result['status'],
                    'message' =>
                        count($fields)
                        . '件の項目を取得しました。',
                ];

                $redirect = [
                    'screen' => 'kintone',
                ];
                break;

            /* ------------------------------------------------
             * kintoneマッピング保存
             *
             * 住所だけ配列で保存する。
             * ------------------------------------------------ */

            case 'save_kintone_mapping':
                $fields =
                    $kintone['fields']
                    ?? [];

                $validCodes = [];

                foreach ($fields as $field) {
                    if (
                        is_array($field)
                        && !empty($field['code'])
                    ) {
                        $validCodes[] =
                            (string)$field['code'];
                    }
                }

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
                        throw new InvalidArgumentException(
                            'マッピング項目に不正な値があります。'
                        );
                    }
                }

                $mapping['address'] =
                    array_values(
                        array_filter(
                            $mapping['address'],
                            static fn(
                                string $code
                            ): bool =>
                                in_array(
                                    $code,
                                    $validCodes,
                                    true
                                )
                        )
                    );

                $kintone['mapping'] =
                    $mapping;

                if (
                    !save_kintone(
                        $kintone
                    )
                ) {
                    throw new RuntimeException(
                        'マッピングを保存できません。'
                    );
                }

                flash(
                    'success',
                    '項目マッピングを保存しました。'
                );

                $redirect = [
                    'screen' => 'kintone',
                ];
                break;

            /* ------------------------------------------------
             * kintone顧客同期
             * ------------------------------------------------ */

            case 'sync_kintone':
                $result =
                    kintone_fetch_records(
                        $kintone
                    );

                if (!$result['ok']) {
                    $_SESSION[
                        'kintone_sync_result'
                    ] = $result;

                    $redirect = [
                        'screen' => 'kintone',
                    ];

                    break;
                }

                $mapping =
                    $kintone['mapping']
                    ?? [];

                $customers = [];

                foreach (
                    (
                        $result['data']['records']
                        ?? []
                    ) as $record
                ) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $getValue =
                        static function (
                            string $code
                        ) use ($record): string {
                            $value =
                                $record[$code]
                                ?? null;

                            if (
                                !is_array($value)
                            ) {
                                return '';
                            }

                            $raw =
                                $value['value']
                                ?? '';

                            if (
                                is_array($raw)
                            ) {
                                return implode(
                                    ' ',
                                    array_map(
                                        'strval',
                                        $raw
                                    )
                                );
                            }

                            return trim(
                                (string)$raw
                            );
                        };

                    $addressParts = [];

                    foreach (
                        (
                            $mapping['address']
                            ?? []
                        ) as $code
                    ) {
                        $part =
                            $getValue(
                                (string)$code
                            );

                        if ($part !== '') {
                            $addressParts[] =
                                $part;
                        }
                    }

                    $customers[] = [
                        'id' =>
                            new_id('customer'),
                        'organization' =>
                            $getValue(
                                (string)(
                                    $mapping[
                                        'organization'
                                    ] ?? ''
                                )
                            ),
                        'name' =>
                            $getValue(
                                (string)(
                                    $mapping[
                                        'name'
                                    ] ?? ''
                                )
                            ),
                        'email' =>
                            $getValue(
                                (string)(
                                    $mapping[
                                        'email'
                                    ] ?? ''
                                )
                            ),
                        'department' =>
                            $getValue(
                                (string)(
                                    $mapping[
                                        'department'
                                    ] ?? ''
                                )
                            ),
                        'phone' =>
                            $getValue(
                                (string)(
                                    $mapping[
                                        'phone'
                                    ] ?? ''
                                )
                            ),
                        'address' =>
                            implode(
                                ' ',
                                $addressParts
                            ),
                        'syncedAt' =>
                            now_iso(),
                    ];
                }

                save_customers(
                    $customers
                );

                $kintone['last_sync'] =
                    now_iso();

                save_kintone(
                    $kintone
                );

                $_SESSION[
                    'kintone_sync_result'
                ] = [
                    'ok' => true,
                    'category' => '成功',
                    'status' =>
                        $result['status'],
                    'message' =>
                        count($customers)
                        . '件の顧客情報を同期しました。',
                ];

                $redirect = [
                    'screen' => 'kintone',
                ];
                break;

            /* ------------------------------------------------
             * SMTP設定保存
             * ------------------------------------------------ */

            case 'save_mail':
                $candidate = $mail;

                $candidate['server'] =
                    post_string('server');

                $candidate['port'] =
                    (int)post_string('port');

                $candidate['encryption'] =
                    post_string('encryption');

                $candidate['auth'] =
                    post_string('auth') === '1';

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
                    ] =
                        encrypt_secret(
                            $password
                        );
                }

                $errors =
                    validate_mail_config(
                        $candidate
                    );

                if ($errors) {
                    throw new InvalidArgumentException(
                        implode(
                            ' ',
                            $errors
                        )
                    );
                }

                if (
                    !save_mail(
                        $candidate
                    )
                ) {
                    throw new RuntimeException(
                        'メール設定を保存できません。'
                    );
                }

                $mail = $candidate;

                flash(
                    'success',
                    'メール設定を保存しました。'
                );

                $redirect = [
                    'screen' => 'mail',
                ];
                break;

            /* ------------------------------------------------
             * SMTP接続テスト
             * ------------------------------------------------ */

            case 'test_mail':
                $candidate = $mail;

                $candidate['server'] =
                    post_string('server');

                $candidate['port'] =
                    (int)post_string('port');

                $candidate['encryption'] =
                    post_string('encryption');

                $candidate['auth'] =
                    post_string('auth') === '1';

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
                    ] =
                        encrypt_secret(
                            $password
                        );
                }

                $result =
                    smtp_test(
                        $candidate
                    );

                $_SESSION[
                    'mail_test_result'
                ] = $result;

                if ($result['ok']) {
                    $mail =
                        array_replace(
                            $mail,
                            $candidate
                        );

                    $mail['status'] =
                        '接続確認済み';

                    $mail['last_test'] =
                        now_iso();

                    save_mail($mail);
                } else {
                    $mail['status'] =
                        '接続できません';

                    save_mail($mail);
                }

                $redirect = [
                    'screen' => 'mail',
                ];
                break;

            /* ------------------------------------------------
             * テストメール
             * ------------------------------------------------ */

            case 'send_test_mail':
                $candidate = $mail;

                $candidate['server'] =
                    post_string('server');

                $candidate['port'] =
                    (int)post_string('port');

                $candidate['encryption'] =
                    post_string('encryption');

                $candidate['auth'] =
                    post_string('auth') === '1';

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
                    ] =
                        encrypt_secret(
                            $password
                        );
                }

                $to =
                    post_string(
                        'test_to'
                    );

                $result =
                    smtp_send_mail(
                        $candidate,
                        $to,
                        'アンケート管理システム テストメール',
                        'SMTP設定のテストメールです。'
                    );

                $_SESSION[
                    'mail_send_result'
                ] = $result;

                $redirect = [
                    'screen' => 'mail',
                ];
                break;

            /* ------------------------------------------------
             * アンケート保存
             * ------------------------------------------------ */

            case 'save_survey':
                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $title =
                    post_string('title');

                if ($title === '') {
                    throw new InvalidArgumentException(
                        'アンケートタイトルを入力してください。'
                    );
                }

                $survey =
                    $surveyId !== ''
                        ? find_survey(
                            $surveys,
                            $surveyId
                        )
                        : null;

                if ($survey === null) {
                    $survey =
                        new_survey();

                    if (
                        $surveyId !== ''
                        && safe_id($surveyId)
                    ) {
                        $survey['id'] =
                            $surveyId;
                    }
                }

                $survey['title'] =
                    $title;

                $survey['description'] =
                    post_string(
                        'description'
                    );

                $survey['startAt'] =
                    post_string(
                        'startAt'
                    );

                $survey['endAt'] =
                    post_string(
                        'endAt'
                    );

                $numbering =
                    post_string(
                        'numbering'
                    );

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

                $survey['updatedAt'] =
                    now_iso();

                recalc_question_numbers(
                    $survey
                );

                $index =
                    survey_index(
                        $surveys,
                        $survey['id']
                    );

                if ($index >= 0) {
                    $surveys[$index] =
                        $survey;
                } else {
                    $surveys[] =
                        $survey;
                }

                if (
                    !save_surveys(
                        $surveys
                    )
                ) {
                    throw new RuntimeException(
                        'アンケートを保存できません。'
                    );
                }

                flash(
                    'success',
                    'アンケートを保存しました。'
                );

                $redirect = [
                    'screen' => 'list',
                ];
                break;

            /* ------------------------------------------------
             * ステータス変更
             * ------------------------------------------------ */

            case 'change_status':
                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $nextStatus =
                    post_string(
                        'next_status'
                    );

                $index =
                    survey_index(
                        $surveys,
                        $surveyId
                    );

                if ($index < 0) {
                    throw new InvalidArgumentException(
                        'アンケートが見つかりません。'
                    );
                }

                $current =
                    $surveys[$index]['status']
                    ?? 'draft';

                $allowed = [
                    'draft' =>
                        ['published'],
                    'published' =>
                        ['stopped'],
                    'stopped' =>
                        ['published'],
                ];

                if (
                    !isset(
                        $allowed[$current]
                    )
                    || !in_array(
                        $nextStatus,
                        $allowed[$current],
                        true
                    )
                ) {
                    throw new InvalidArgumentException(
                        '指定された状態変更はできません。'
                    );
                }

                $surveys[$index]['status'] =
                    $nextStatus;

                $surveys[$index]['updatedAt'] =
                    now_iso();

                save_surveys(
                    $surveys
                );

                flash(
                    'success',
                    '状態を変更しました。'
                );

                $redirect = [
                    'screen' => 'list',
                ];
                break;

            /* ------------------------------------------------
             * 複製
             * ------------------------------------------------ */

            case 'duplicate_survey':
                $surveyId =
                    post_string(
                        'survey_id'
                    );

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

                $survey['id'] =
                    new_id('survey');

                $survey['title'] =
                    $survey['title']
                    . '（コピー）';

                $survey['status'] =
                    'draft';

                $survey['createdAt'] =
                    now_iso();

                $survey['updatedAt'] =
                    now_iso();

                $surveys[] =
                    $survey;

                save_surveys(
                    $surveys
                );

                flash(
                    'success',
                    'アンケートを複製しました。'
                );

                $redirect = [
                    'screen' => 'list',
                ];
                break;

            /* ------------------------------------------------
             * 削除
             * ------------------------------------------------ */

            case 'delete_survey':
                $surveyId =
                    post_string(
                        'survey_id'
                    );

                $index =
                    survey_index(
                        $surveys,
                        $surveyId
                    );

                if ($index < 0) {
                    throw new InvalidArgumentException(
                        'アンケートが見つかりません。'
                    );
                }

                array_splice(
                    $surveys,
                    $index,
                    1
                );

                save_surveys(
                    $surveys
                );

                flash(
                    'success',
                    'アンケートを削除しました。'
                );

                $redirect = [
                    'screen' => 'list',
                ];
                break;

            /* ------------------------------------------------
             * 回答
             * ------------------------------------------------ */

            case 'save_answer':
                $surveyId =
                    post_string(
                        'survey_id'
                    );

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

                $answers =
                    $_POST['answer'] ?? [];

                if (!is_array($answers)) {
                    $answers = [];
                }

                $_SESSION[
                    'answer_draft_' . $surveyId
                ] = $answers;

                $redirect = [
                    'screen' => 'confirm',
                    'id' => $surveyId,
                ];
                break;

            /* ------------------------------------------------
             * 回答送信
             * ------------------------------------------------ */

            case 'complete_answer':
                $surveyId =
                    post_string(
                        'survey_id'
                    );

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

                $draftKey =
                    'answer_draft_'
                    . $surveyId;

                $answers =
                    $_SESSION[
                        $draftKey
                    ] ?? [];

                $allAnswers =
                    load_answers();

                $allAnswers[] = [
                    'id' =>
                        new_id('answer'),
                    'survey_id' =>
                        $surveyId,
                    'answers' =>
                        $answers,
                    'createdAt' =>
                        now_iso(),
                ];

                save_answers(
                    $allAnswers
                );

                unset(
                    $_SESSION[$draftKey]
                );

                $redirect = [
                    'screen' => 'complete',
                    'id' => $surveyId,
                ];
                break;

            /* ------------------------------------------------
             * 顧客選択・送信
             * ------------------------------------------------ */

            case 'send_mail':
                $surveyId =
                    post_string(
                        'survey_id'
                    );

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

                $selected =
                    post_array(
                        'customer_ids'
                    );

                $subject =
                    post_string(
                        'mail_subject'
                    );

                $body =
                    post_string(
                        'mail_body'
                    );

                $customers =
                    load_customers();

                $customerMap = [];

                foreach (
                    $customers as $customer
                ) {
                    if (
                        !empty(
                            $customer['id']
                        )
                    ) {
                        $customerMap[
                            $customer['id']
                        ] =
                            $customer;
                    }
                }

                $history =
                    load_history();

                $sent = 0;
                $failed = 0;

                foreach (
                    $selected as $customerId
                ) {
                    $customer =
                        $customerMap[
                            $customerId
                        ] ?? null;

                    if (
                        !is_array($customer)
                    ) {
                        $failed++;
                        continue;
                    }

                    $to =
                        (string)(
                            $customer['email']
                            ?? ''
                        );

                    if (
                        !filter_var(
                            $to,
                            FILTER_VALIDATE_EMAIL
                        )
                    ) {
                        $failed++;
                        continue;
                    }

                    $personalSubject =
                        str_replace(
                            '{顧客名}',
                            (string)(
                                $customer['name']
                                ?? ''
                            ),
                            $subject
                        );

                    $personalBody =
                        str_replace(
                            '{顧客名}',
                            (string)(
                                $customer['name']
                                ?? ''
                            ),
                            $body
                        );

                    $surveyUrl =
                        app_url([
                            'screen' =>
                                'answer',
                            'id' =>
                                $surveyId,
                        ]);

                    $personalBody =
                        str_replace(
                            '{アンケートURL}',
                            $surveyUrl,
                            $personalBody
                        );

                    $result =
                        smtp_send_mail(
                            $mail,
                            $to,
                            $personalSubject,
                            $personalBody
                        );

                    $ok =
                        !empty(
                            $result['ok']
                        );

                    $history[] = [
                        'id' =>
                            new_id('send'),
                        'survey_id' =>
                            $surveyId,
                        'customer_id' =>
                            $customerId,
                        'customer_name' =>
                            (string)(
                                $customer['name']
                                ?? ''
                            ),
                        'email' =>
                            $to,
                        'type' =>
                            'send',
                        'status' =>
                            $ok
                                ? 'success'
                                : 'failed',
                        'message' =>
                            (string)(
                                $result['message']
                                ?? ''
                            ),
                        'createdAt' =>
                            now_iso(),
                    ];

                    if ($ok) {
                        $sent++;
                    } else {
                        $failed++;
                    }
                }

                save_history(
                    $history
                );

                $_SESSION[
                    'send_result'
                ] = [
                    'ok' =>
                        $failed === 0,
                    'message' =>
                        $sent
                        . '件送信、'
                        . $failed
                        . '件失敗しました。',
                ];

                $redirect = [
                    'screen' => 'send',
                    'id' => $surveyId,
                ];
                break;

            default:
                break;
        }
    } catch (
        Throwable $e
    ) {
        flash(
            'error',
            $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : '処理に失敗しました。入力内容またはサーバー設定を確認してください。'
        );

        $redirect =
            $redirect
            ?? [
                'screen' =>
                    $screen ?: 'list',
            ];
    }

    if ($redirect !== null) {
        header(
            'Location: '
            . app_url($redirect),
            true,
            303
        );
        exit;
    }
}

/* ============================================================
 * 共通HTML
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
    --bg:#f8fafc;
    --dark:#0f172a;
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
    background:var(--bg);
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
    color:var(--primary);
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

button,
input,
select,
textarea{
    font:inherit;
}

button,
.btn{
    cursor:pointer;
}

.admin-header{
    background:var(--dark);
    color:#fff;
}

.admin-header-inner{
    max-width:1200px;
    margin:auto;
    padding:16px 20px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.brand{
    color:#fff;
    font-weight:700;
    font-size:18px;
}

.nav{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.nav a{
    color:#cbd5e1;
    padding:8px 12px;
    border-radius:8px;
}

.nav a:hover{
    background:#1e293b;
    color:#fff;
    text-decoration:none;
}

.container{
    max-width:1200px;
    margin:0 auto;
    padding:28px 20px 60px;
}

.page-title{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:22px;
}

.page-title h1{
    margin:0 0 4px;
    font-size:26px;
}

.page-title p{
    margin:0;
    color:var(--gray);
}

.card{
    background:var(--white);
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    overflow:hidden;
}

.card-header{
    padding:17px 20px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
}

.card-header h2{
    margin:0;
    font-size:18px;
}

.card-body{
    padding:20px;
}

.grid{
    display:grid;
    gap:18px;
}

.grid-2{
    grid-template-columns:
        repeat(2,minmax(0,1fr));
}

.grid-3{
    grid-template-columns:
        repeat(3,minmax(0,1fr));
}

.form-group{
    margin-bottom:4px;
}

.form-group > label{
    display:block;
}

.form-group > label > span,
.field-label{
    display:block;
    font-weight:600;
    margin-bottom:7px;
}

input[type="text"],
input[type="password"],
input[type="email"],
input[type="number"],
input[type="datetime-local"],
select,
textarea{
    width:100%;
    min-height:42px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:9px 11px;
    background:#fff;
    color:var(--text);
}

textarea{
    min-height:130px;
    resize:vertical;
}

input:focus,
select:focus,
textarea:focus{
    outline:3px solid rgba(37,99,235,.14);
    border-color:var(--primary);
}

input[type="checkbox"],
input[type="radio"]{
    width:auto;
    margin:0 8px 0 0;
}

.check-list{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.check-item{
    display:flex !important;
    align-items:center;
    gap:4px;
    font-weight:400 !important;
    margin:0 !important;
    padding:9px 11px;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
}

.check-item:hover{
    background:#f8fafc;
}

.help{
    color:var(--gray);
    font-size:13px;
    margin-top:5px;
}

.button-row{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:8px;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    border:1px solid transparent;
    border-radius:8px;
    padding:8px 14px;
    font-weight:600;
    text-decoration:none !important;
    transition:.15s ease;
}

.btn:hover{
    filter:brightness(.96);
}

.btn:disabled{
    opacity:.55;
    cursor:not-allowed;
}

.btn-primary{
    background:var(--primary);
    color:#fff;
}

.btn-secondary{
    background:#fff;
    color:var(--text);
    border-color:#cbd5e1;
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

.badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:4px 10px;
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

.badge-gray{
    color:#475569;
    background:#e2e8f0;
}

.badge-draft{
    color:#475569;
    background:#f1f5f9;
}

.alert{
    padding:13px 16px;
    border-radius:9px;
    margin-bottom:18px;
    border:1px solid transparent;
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

.table-wrap{
    overflow-x:auto;
}

table{
    width:100%;
    min-width:900px;
    border-collapse:collapse;
}

th,
td{
    padding:12px 11px;
    border-bottom:1px solid var(--border);
    text-align:left;
    vertical-align:top;
}

th{
    background:#f8fafc;
    font-size:13px;
    white-space:nowrap;
}

td{
    font-size:14px;
}

.table-actions{
    display:flex;
    flex-wrap:wrap;
    gap:5px;
}

.table-actions .btn{
    min-height:34px;
    padding:5px 9px;
    font-size:12px;
}

.section{
    margin-top:20px;
}

.section-title{
    font-size:17px;
    font-weight:700;
    margin:0 0 12px;
}

.question-card{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    margin-bottom:12px;
    background:#fff;
}

.question-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:12px;
}

.question-number{
    font-weight:700;
    color:var(--primary);
}

.option-row{
    display:flex;
    gap:8px;
    margin-bottom:8px;
}

.option-row input{
    flex:1;
}

.preview-box{
    max-width:800px;
    margin:0 auto;
}

.answer-choice{
    display:flex;
    align-items:center;
    gap:8px;
    padding:13px;
    margin-bottom:8px;
    border:1px solid var(--border);
    border-radius:9px;
    cursor:pointer;
    background:#fff;
}

.answer-choice:hover{
    border-color:#93c5fd;
    background:#eff6ff;
}

.stats{
    display:grid;
    grid-template-columns:
        repeat(4,minmax(0,1fr));
    gap:15px;
}

.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:18px;
}

.stat-label{
    color:var(--gray);
    font-size:13px;
}

.stat-value{
    font-size:25px;
    font-weight:700;
    margin-top:5px;
}

.mapping-box{
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px;
    background:#f8fafc;
}

.mapping-address{
    grid-column:1/-1;
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

.loading-overlay.is-visible{
    display:flex;
}

.loading-box{
    background:#fff;
    border-radius:12px;
    padding:24px 30px;
    box-shadow:var(--shadow);
    text-align:center;
}

.spinner{
    width:32px;
    height:32px;
    border:4px solid #dbeafe;
    border-top-color:var(--primary);
    border-radius:50%;
    animation:spin .8s linear infinite;
    margin:0 auto 12px;
}

@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}

.empty{
    color:var(--gray);
    padding:20px 0;
    text-align:center;
}

hr{
    border:0;
    border-top:1px solid var(--border);
    margin:24px 0;
}

pre.debug{
    white-space:pre-wrap;
    word-break:break-word;
    background:#0f172a;
    color:#e2e8f0;
    border-radius:8px;
    padding:12px;
}

@media(max-width:800px){
    .admin-header-inner{
        align-items:flex-start;
        flex-direction:column;
    }

    .grid-2,
    .grid-3{
        grid-template-columns:1fr;
    }

    .stats{
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }

    .page-title{
        flex-direction:column;
    }

    .container{
        padding:20px 12px 50px;
    }
}

@media(max-width:560px){
    .stats{
        grid-template-columns:1fr;
    }

    .card-body,
    .card-header{
        padding:15px;
    }

    .btn{
        width:auto;
    }

    .button-row{
        align-items:stretch;
    }

    .button-row .btn{
        flex:0 1 auto;
    }
}
</style>
</head>
<body>

<?php if ($admin): ?>
<header class="admin-header">
<div class="admin-header-inner">
    <a class="brand"
       href="<?= h(app_url(['screen'=>'list'])) ?>">
        <?= h(APP_TITLE) ?>
    </a>

    <nav class="nav">
        <a href="<?= h(
            app_url(['screen'=>'list'])
        ) ?>">
            アンケート一覧
        </a>

        <a href="<?= h(
            app_url(['screen'=>'kintone'])
        ) ?>">
            kintone設定
        </a>

        <a href="<?= h(
            app_url(['screen'=>'mail'])
        ) ?>">
            メール設定
        </a>
    </nav>
</div>
</header>
<?php endif; ?>

<div class="container">
<?php
}

function render_footer(): void
{
?>
</div>

<div class="loading-overlay"
     id="loadingOverlay"
     aria-hidden="true">
    <div class="loading-box">
        <div class="spinner"></div>
        <strong>処理中です</strong>
        <div class="help">
            しばらくお待ちください。
        </div>
    </div>
</div>

<script>
(function(){
    'use strict';

    const overlay =
        document.getElementById(
            'loadingOverlay'
        );

    let submitting = false;

    document
        .querySelectorAll(
            'form[data-loading]'
        )
        .forEach(function(form){
            form.addEventListener(
                'submit',
                function(event){

                    if (submitting) {
                        event.preventDefault();
                        return;
                    }

                    const message =
                        form.getAttribute(
                            'data-confirm'
                        );

                    if (
                        message
                        && !window.confirm(
                            message
                        )
                    ) {
                        event.preventDefault();
                        return;
                    }

                    submitting = true;

                    if (overlay) {
                        overlay.classList.add(
                            'is-visible'
                        );
                        overlay.setAttribute(
                            'aria-hidden',
                            'false'
                        );
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
})();
</script>

</body>
</html>
<?php
}

/* ============================================================
 * フラッシュ
 * ============================================================ */

function render_flash(): void
{
    $flash = consume_flash();

    if (!is_array($flash)) {
        return;
    }

    $type =
        (string)(
            $flash['type']
            ?? 'success'
        );

    $class =
        $type === 'error'
            ? 'alert-error'
            : (
                $type === 'warning'
                    ? 'alert-warning'
                    : 'alert-success'
            );
?>
<div class="alert <?= h($class) ?>">
    <?= h(
        (string)(
            $flash['message']
            ?? ''
        )
    ) ?>
</div>
<?php
}

/* ============================================================
 * 一覧
 * ============================================================ */

function render_list(
    array $surveys
): void {
    $search =
        get_string('q');

    $filter =
        get_string('filter');

    $sort =
        get_string('sort');

    $items = [];

    foreach (
        $surveys as $survey
    ) {
        $title =
            (string)(
                $survey['title']
                ?? ''
            );

        if (
            $search !== ''
            && mb_stripos(
                $title,
                $search
            ) === false
        ) {
            continue;
        }

        $status =
            (string)(
                $survey['status']
                ?? 'draft'
            );

        if (
            $filter !== ''
            && $filter !== 'all'
            && $status !== $filter
        ) {
            continue;
        }

        $items[] =
            $survey;
    }

    usort(
        $items,
        static function(
            array $a,
            array $b
        ) use ($sort): int {
            return match ($sort) {
                'oldest' =>
                    strcmp(
                        (string)(
                            $a['updatedAt']
                            ?? ''
                        ),
                        (string)(
                            $b['updatedAt']
                            ?? ''
                        )
                    ),

                'answers_desc' =>
                    0,

                'answers_asc' =>
                    0,

                'start_desc' =>
                    strcmp(
                        (string)(
                            $b['startAt']
                            ?? ''
                        ),
                        (string)(
                            $a['startAt']
                            ?? ''
                        )
                    ),

                'start_asc' =>
                    strcmp(
                        (string)(
                            $a['startAt']
                            ?? ''
                        ),
                        (string)(
                            $b['startAt']
                            ?? ''
                        )
                    ),

                default =>
                    strcmp(
                        (string)(
                            $b['updatedAt']
                            ?? ''
                        ),
                        (string)(
                            $a['updatedAt']
                            ?? ''
                        )
                    ),
            };
        }
    );

    render_head('アンケート一覧');
?>
<div class="page-title">
    <div>
        <h1>アンケート一覧</h1>
        <p>
            アンケートの作成・公開・送信・集計を管理します。
        </p>
    </div>

    <a class="btn btn-primary"
       href="<?= h(
           app_url([
               'screen' => 'edit',
           ])
       ) ?>">
        ＋ 新規作成
    </a>
</div>

<?php render_flash(); ?>

<div class="card">
<div class="card-body">

<form method="get">
<input type="hidden"
       name="screen"
       value="list">

<div class="grid grid-3">

<div class="form-group">
<label>
<span>タイトル検索</span>
<input type="text"
       name="q"
       value="<?= h($search) ?>"
       placeholder="タイトルを入力してEnter">
</label>
</div>

<div class="form-group">
<label>
<span>ステータス</span>
<select name="filter">
    <option value="all"
        <?= $filter === 'all'
            || $filter === ''
            ? 'selected'
            : '' ?>>
        すべて
    </option>
    <option value="published"
        <?= $filter === 'published'
            ? 'selected'
            : '' ?>>
        公開中
    </option>
    <option value="draft"
        <?= $filter === 'draft'
            ? 'selected'
            : '' ?>>
        下書き
    </option>
    <option value="stopped"
        <?= $filter === 'stopped'
            ? 'selected'
            : '' ?>>
        停止
    </option>
    <option value="ended"
        <?= $filter === 'ended'
            ? 'selected'
            : '' ?>>
        終了
    </option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>ソート</span>
<select name="sort">
    <option value="newest"
        <?= $sort === ''
            || $sort === 'newest'
            ? 'selected'
            : '' ?>>
        更新日：新しい順
    </option>
    <option value="oldest"
        <?= $sort === 'oldest'
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
</label>
</div>

</div>

<div class="button-row"
     style="margin-top:14px">
    <button class="btn btn-secondary"
            type="submit">
        検索・絞り込み
    </button>
</div>
</form>

</div>
</div>

<div class="card">
<div class="card-header">
    <h2>アンケート</h2>
</div>

<div class="card-body"
     style="padding:0">

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>タイトル</th>
    <th>作成日</th>
    <th>更新日</th>
    <th>期間</th>
    <th>状態</th>
    <th>回答数</th>
    <th>操作</th>
</tr>
</thead>

<tbody>
<?php if (!$items): ?>
<tr>
<td colspan="7">
    <div class="empty">
        アンケートがありません。
    </div>
</td>
</tr>
<?php endif; ?>

<?php foreach (
    $items as $survey
): ?>
<?php
$answerCount = 0;

foreach (
    load_answers() as $answer
) {
    if (
        ($answer['survey_id'] ?? '')
        === ($survey['id'] ?? '')
    ) {
        $answerCount++;
    }
}
?>
<tr>
<td>
    <strong>
        <?= h(
            $survey['title']
            ?? ''
        ) ?>
    </strong>
</td>

<td>
    <?= h(
        $survey['createdAt']
        ?? ''
    ) ?>
</td>

<td>
    <?= h(
        $survey['updatedAt']
        ?? ''
    ) ?>
</td>

<td>
    <?= h(
        $survey['startAt']
        ?? ''
    ) ?>
    <br>
    ～
    <?= h(
        $survey['endAt']
        ?? ''
    ) ?>
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

<td>
    <?= h($answerCount) ?>
</td>

<td>
<div class="table-actions">

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' => 'edit',
           'id' =>
               $survey['id'],
       ])
   ) ?>">
    確認・編集
</a>

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' =>
               'analytics',
           'id' =>
               $survey['id'],
       ])
   ) ?>">
    集計
</a>

<a class="btn btn-primary"
   href="<?= h(
       app_url([
           'screen' => 'send',
           'id' =>
               $survey['id'],
       ])
   ) ?>">
    送信
</a>

<form method="post"
      data-loading
      data-confirm="このアンケートを複製しますか？">
<input type="hidden"
       name="action"
       value="duplicate_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<button class="btn btn-secondary"
        type="submit">
    複製
</button>
</form>

<form method="post"
      data-loading
      data-confirm="このアンケートを削除しますか？">
<input type="hidden"
       name="action"
       value="delete_survey">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<button class="btn btn-danger"
        type="submit">
    削除
</button>
</form>

<?php
$status =
    $survey['status']
    ?? 'draft';
?>

<?php if (
    $status === 'draft'
): ?>
<form method="post"
      data-loading
      data-confirm="このアンケートを公開しますか？">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<input type="hidden"
       name="next_status"
       value="published">
<button class="btn btn-success"
        type="submit">
    公開
</button>
</form>
<?php elseif (
    $status === 'published'
): ?>
<form method="post"
      data-loading
      data-confirm="このアンケートを停止しますか？">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<input type="hidden"
       name="next_status"
       value="stopped">
<button class="btn btn-warning"
        type="submit">
    停止
</button>
</form>
<?php elseif (
    $status === 'stopped'
): ?>
<form method="post"
      data-loading
      data-confirm="このアンケートを再開しますか？">
<input type="hidden"
       name="action"
       value="change_status">
<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">
<input type="hidden"
       name="next_status"
       value="published">
<button class="btn btn-success"
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
</div>
<?php
render_footer();
}

/* ============================================================
 * 編集
 * ============================================================ */

function render_edit(
    ?array $survey
): void {
    $survey =
        $survey
        ?? new_survey();

    recalc_question_numbers(
        $survey
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
      data-loading
      id="surveyForm">

<input type="hidden"
       name="action"
       value="save_survey">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<div class="card">
<div class="card-body">

<div class="button-row"
     style="justify-content:space-between">

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' => 'list',
       ])
   ) ?>">
    キャンセル
</a>

<div class="button-row">

<span class="badge badge-<?= h(
    status_class(
        (string)$survey['status']
    )
) ?>">
    状態：
    <?= h(
        status_label(
            (string)$survey['status']
        )
    ) ?>
</span>

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' =>
               'preview',
           'id' =>
               $survey['id'],
       ])
   ) ?>">
    プレビュー
</a>

<button class="btn btn-primary"
        type="submit">
    保存して一覧へ
</button>

</div>
</div>

<hr>

<div class="grid grid-2">

<div class="form-group">
<label>
<span>アンケートタイトル</span>
<input type="text"
       name="title"
       value="<?= h(
           $survey['title']
       ) ?>"
       required
       maxlength="200">
</label>
</div>

<div class="form-group">
<label>
<span>質問番号の採番方式</span>
<select name="numbering">
<option value="global"
    <?= ($survey['numbering'] ?? 'global')
        === 'global'
        ? 'selected'
        : '' ?>>
    アンケート全体で通番：Q1、Q2、Q3...
</option>
<option value="group"
    <?= ($survey['numbering'] ?? '')
        === 'group'
        ? 'selected'
        : '' ?>>
    グループ毎：Q1-1、Q1-2、Q2-1...
</option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>アンケート説明</span>
<textarea name="description"
          maxlength="2000"><?= h(
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
       value="<?= h(
           $survey['startAt']
       ) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>終了日時</span>
<input type="datetime-local"
       name="endAt"
       value="<?= h(
           $survey['endAt']
       ) ?>">
</label>
</div>

</div>

</div>

</div>
</div>

<?php foreach (
    $survey['groups']
    as $groupIndex => $group
): ?>

<div class="card">
<div class="card-header">
    <h2>
        <?= h(
            $group['title']
            ?? 'グループ'
        ) ?>
    </h2>

    <span class="badge badge-gray">
        グループ <?= h(
            $groupIndex + 1
        ) ?>
    </span>
</div>

<div class="card-body">

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<div class="question-head">
    <span class="question-number">
        <?= h(
            $question['number']
            ?? ''
        ) ?>
    </span>

    <?php if (
        !empty(
            $question['required']
        )
    ): ?>
    <span class="badge badge-warning">
        必須
    </span>
    <?php endif; ?>
</div>

<div class="form-group">
<label>
<span>質問文</span>
<input type="text"
       value="<?= h(
           $question['text']
           ?? ''
       ) ?>"
       disabled>
</label>
</div>

<div class="grid grid-2"
     style="margin-top:12px">

<div class="form-group">
<label>
<span>回答形式</span>
<select disabled>
<option
    <?= ($question['type'] ?? '')
        === 'single'
        ? 'selected'
        : '' ?>>
    単一選択
</option>
<option
    <?= ($question['type'] ?? '')
        === 'multiple'
        ? 'selected'
        : '' ?>>
    複数選択
</option>
<option
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
<span class="field-label">
    必須設定
</span>
<label class="check-item">
<input type="checkbox"
       disabled
       <?= !empty(
           $question['required']
       )
           ? 'checked'
           : '' ?>>
必須
</label>
</div>

</div>

<?php if (
    in_array(
        $question['type'] ?? '',
        ['single','multiple'],
        true
    )
): ?>

<div class="section">
<div class="section-title">
    選択肢
</div>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>
<div class="option-row">
<input type="text"
       value="<?= h(
           $option
       ) ?>"
       disabled>
</div>
<?php endforeach; ?>

</div>
<?php endif; ?>

</div>

<?php endforeach; ?>

<div class="help">
モックの主要レイアウトを維持した編集画面です。
質問・グループの永続的な編集機能を追加する場合も、
このDOM構造を基準に実装できます。
</div>

</div>
</div>

<?php endforeach; ?>

</form>
<?php
render_footer();
}

/* ============================================================
 * プレビュー
 * ============================================================ */

function render_preview(
    ?array $survey
): void {
    if ($survey === null) {
        render_head(
            'プレビュー'
        );

        echo '<div class="alert alert-error">'
            . 'アンケートが見つかりません。'
            . '</div>';

        render_footer();
        return;
    }

    recalc_question_numbers(
        $survey
    );

    render_head(
        'プレビュー'
    );
?>
<div class="page-title">
    <div>
        <h1>プレビュー</h1>
        <p>
            PC・スマートフォンでの回答画面を確認します。
        </p>
    </div>

    <a class="btn btn-secondary"
       href="<?= h(
           app_url([
               'screen' => 'edit',
               'id' =>
                   $survey['id'],
           ])
       ) ?>">
        編集へ戻る
    </a>
</div>

<div class="preview-box">

<div class="card">
<div class="card-body">

<h1>
    <?= h(
        $survey['title']
    ) ?>
</h1>

<?php if (
    $survey['description'] !== ''
): ?>
<p>
    <?= nl2br(
        h(
            $survey['description']
        )
    ) ?>
</p>
<?php endif; ?>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="section">

<h2>
    <?= h(
        $group['title']
    ) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<div class="question-head">
    <span class="question-number">
        <?= h(
            $question['number']
        ) ?>
    </span>

    <?php if (
        !empty(
            $question['required']
        )
    ): ?>
    <span class="badge badge-warning">
        必須
    </span>
    <?php endif; ?>
</div>

<p>
    <?= h(
        $question['text']
        ?: '質問文'
    ) ?>
</p>

<?php if (
    ($question['type'] ?? '')
    === 'text'
): ?>

<textarea placeholder="回答を入力"></textarea>

<?php elseif (
    ($question['type'] ?? '')
    === 'multiple'
): ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>
<label class="answer-choice">
<input type="checkbox">
<?= h($option) ?>
</label>
<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>
<label class="answer-choice">
<input type="radio"
       name="preview_<?= h(
           $question['id']
       ) ?>">
<?= h($option) ?>
</label>
<?php endforeach; ?>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
</div>

</div>
<?php
render_footer();
}

/* ============================================================
 * kintone設定
 *
 * ★今回の再生成で最重要箇所
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

    $fieldsResult =
        $_SESSION[
            'kintone_fields_result'
        ] ?? null;

    unset(
        $_SESSION[
            'kintone_fields_result'
        ]
    );

    $syncResult =
        $_SESSION[
            'kintone_sync_result'
        ] ?? null;

    unset(
        $_SESSION[
            'kintone_sync_result'
        ]
    );

    render_head(
        'kintone設定'
    );

    $fields =
        is_array(
            $config['fields']
            ?? null
        )
            ? $config['fields']
            : [];

    $mapping =
        is_array(
            $config['mapping']
            ?? null
        )
            ? $config['mapping']
            : [];

    $addressMapping =
        is_array(
            $mapping['address']
            ?? null
        )
            ? $mapping['address']
            : [];
?>
<div class="page-title">
    <div>
        <h1>kintone連携設定</h1>
        <p>
            顧客情報取得元となるkintoneを設定します。
        </p>
    </div>

    <span class="badge <?= h(
        $config['status']
            === '接続確認済み'
            ? 'badge-success'
            : 'badge-gray'
    ) ?>">
        <?= h(
            $config['status']
        ) ?>
    </span>
</div>

<?php render_flash(); ?>

<?php if (
    is_array($test)
): ?>
<div class="alert <?= !empty(
    $test['ok']
)
    ? 'alert-success'
    : 'alert-error' ?>">

<strong>
<?= !empty($test['ok'])
    ? '✓ kintone接続テスト成功'
    : '✕ kintone接続テスト失敗' ?>
</strong>

<div style="margin-top:6px">
    <?= h(
        $test['message']
        ?? ''
    ) ?>
</div>

<?php if (
    empty($test['ok'])
): ?>
<div style="margin-top:8px">
HTTP <?= h(
    $test['status']
    ?? 0
) ?>

<?php if (
    !empty($test['code'])
): ?>
 / エラーコード:
<?= h(
    $test['code']
) ?>
<?php endif; ?>

<?php if (
    !empty($test['id'])
): ?>
 / エラーID:
<?= h(
    $test['id']
) ?>
<?php endif; ?>
</div>
<?php endif; ?>

</div>
<?php endif; ?>

<?php if (
    is_array($fieldsResult)
): ?>
<div class="alert <?= !empty(
    $fieldsResult['ok']
)
    ? 'alert-success'
    : 'alert-error' ?>">
    <?= h(
        $fieldsResult['message']
        ?? ''
    ) ?>
</div>
<?php endif; ?>

<?php if (
    is_array($syncResult)
): ?>
<div class="alert <?= !empty(
    $syncResult['ok']
)
    ? 'alert-success'
    : 'alert-error' ?>">
    <?= h(
        $syncResult['message']
        ?? ''
    ) ?>
</div>
<?php endif; ?>

<!-- ========================================================
     kintone基本設定
     入力欄を独立したフォームとして明示
     ======================================================== -->

<div class="card">
<div class="card-header">
    <h2>kintone接続設定</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading
      autocomplete="off">

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
<div class="help">
https://xxxx.cybozu.com、xxxx.cybozu.com、xxxx のいずれでも入力できます。
</div>
</div>

<div class="form-group">
<label>
<span>顧客管理アプリID</span>
<input type="number"
       name="app_id"
       value="<?= h(
           $config['app_id']
       ) ?>"
       min="1"
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
       required>
</label>
</div>

<div class="form-group">
<label>
<span>パスワード</span>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password"
       placeholder="変更する場合のみ入力">
</label>
<div class="help">
保存済みパスワードは画面へ再表示しません。
</div>
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
<div class="help">
未入力の場合はProxyを使用せず直接接続します。
</div>
</div>

<div class="form-group">
<span class="field-label">
    SSL証明書検証
</span>

<label class="check-item">
<input type="checkbox"
       name="verify_ssl"
       value="1"
       <?= !empty(
           $config['verify_ssl']
       )
           ? 'checked'
           : '' ?>>
SSL証明書を検証する
</label>

<div class="help">
POCでは未チェック（無効）を初期値とします。
</div>
</div>

</div>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
    設定保存
</button>

</div>

</form>

<hr>

<!-- 接続テスト -->
<form method="post"
      data-loading
      autocomplete="off">

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
    !empty(
        $config['verify_ssl']
    )
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

<div class="button-row"
     style="margin-top:12px">

<button class="btn btn-secondary"
        type="submit">
    接続テスト
</button>

</div>
</form>

</div>
</div>

<!-- ========================================================
     項目取得・マッピング
     ======================================================== -->

<div class="card">
<div class="card-header">
    <div>
        <h2>顧客項目マッピング</h2>
        <div class="help">
            項目一覧取得後にマッピング項目が表示されます。
        </div>
    </div>
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

<?php if (!$fields): ?>

<div class="alert alert-warning"
     style="margin-top:18px">
    まだkintone項目を取得していません。
    「項目一覧を再取得」を実行してください。
</div>

<?php else: ?>

<form method="post"
      data-loading
      style="margin-top:20px">

<input type="hidden"
       name="action"
       value="save_kintone_mapping">

<div class="mapping-box">

<div class="grid grid-2">

<?php
$labels = [
    'organization' => '組織名',
    'name' => '氏名',
    'email' => 'メールアドレス',
    'department' => '部署名',
    'phone' => '電話番号',
];
?>

<?php foreach (
    $labels as $key => $label
): ?>

<div class="form-group">
<label>
<span><?= h($label) ?></span>

<select name="mapping_<?= h(
    $key
) ?>">

<option value="">
    未設定
</option>

<?php foreach (
    $fields as $field
): ?>

<?php
$fieldCode =
    (string)(
        $field['code']
        ?? ''
    );

$fieldLabel =
    (string)(
        $field['label']
        ?? $fieldCode
    );

$current =
    (string)(
        $mapping[$key]
        ?? ''
    );
?>

<option value="<?= h(
    $fieldCode
) ?>"
    <?= $current === $fieldCode
        ? 'selected'
        : '' ?>>
    <?= h(
        $fieldLabel
        . ' ('
        . $fieldCode
        . ')'
    ) ?>
</option>

<?php endforeach; ?>

</select>
</label>
</div>

<?php endforeach; ?>

<!-- ======================================================
     住所
     ★ nested labelを絶対に使用しない
     ★ mapping_address[] で複数値を送信
     ====================================================== -->

<div class="form-group mapping-address">

<span class="field-label">
    住所
</span>

<div class="check-list">

<?php foreach (
    $fields as $field
): ?>

<?php
$fieldCode =
    (string)(
        $field['code']
        ?? ''
    );

$fieldLabel =
    (string)(
        $field['label']
        ?? $fieldCode
    );
?>

<label class="check-item">

<input type="checkbox"
       name="mapping_address[]"
       value="<?= h(
           $fieldCode
       ) ?>"
       <?= in_array(
           $fieldCode,
           $addressMapping,
           true
       )
           ? 'checked'
           : '' ?>>

<span>
<?= h(
    $fieldLabel
    . ' ('
    . $fieldCode
    . ')'
) ?>
</span>

</label>

<?php endforeach; ?>

</div>

<div class="help">
住所は複数のkintone項目を選択できます。
選択した項目は同期時に左から順に連結します。
</div>

</div>

</div>

</div>

<div class="button-row"
     style="margin-top:16px">

<button class="btn btn-primary"
        type="submit">
    マッピングを保存
</button>

</div>

</form>

<?php endif; ?>

</div>
</div>

<!-- ========================================================
     顧客同期
     ======================================================== -->

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
      data-loading
      data-confirm="kintoneから顧客情報を同期しますか？">

<input type="hidden"
       name="action"
       value="sync_kintone">

<button class="btn btn-primary"
        type="submit">
    顧客情報を同期
</button>

</form>

<?php if (
    !empty(
        $config['last_sync']
    )
): ?>

<p class="help">
最終同期：
<?= h(
    $config['last_sync']
) ?>
</p>

<?php endif; ?>

</div>
</div>

<?php
render_footer();
}

/* ============================================================
 * メール設定
 *
 * ★今回の再生成で入力欄を独立して明示
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

    $sendResult =
        $_SESSION[
            'mail_send_result'
        ] ?? null;

    unset(
        $_SESSION[
            'mail_send_result'
        ]
    );

    render_head(
        'メールサーバ設定'
    );
?>
<div class="page-title">
    <div>
        <h1>メールサーバ設定</h1>
        <p>
            SMTPサーバへの接続・認証設定を行います。
        </p>
    </div>

    <span class="badge <?= h(
        $config['status']
            === '接続確認済み'
            ? 'badge-success'
            : 'badge-gray'
    ) ?>">
        <?= h(
            $config['status']
        ) ?>
    </span>
</div>

<?php render_flash(); ?>

<?php if (
    is_array($test)
): ?>
<div class="alert <?= !empty(
    $test['ok']
)
    ? 'alert-success'
    : 'alert-error' ?>">
    <?= h(
        $test['message']
        ?? ''
    ) ?>
</div>
<?php endif; ?>

<?php if (
    is_array($sendResult)
): ?>
<div class="alert <?= !empty(
    $sendResult['ok']
)
    ? 'alert-success'
    : 'alert-error' ?>">
    <?= h(
        $sendResult['message']
        ?? ''
    ) ?>
</div>
<?php endif; ?>

<!-- ========================================================
     SMTP設定
     ======================================================== -->

<div class="card">
<div class="card-header">
    <h2>SMTP接続設定</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading
      autocomplete="off">

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
       placeholder="smtp.example.com"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>SMTPポート</span>
<input type="number"
       name="port"
       value="<?= h(
           $config['port']
       ) ?>"
       min="1"
       max="65535"
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
<label>
<span>SMTP認証</span>
<select name="auth">
<option value="1"
    <?= !empty(
        $config['auth']
    )
        ? 'selected'
        : '' ?>>
    あり
</option>
<option value="0"
    <?= empty(
        $config['auth']
    )
        ? 'selected'
        : '' ?>>
    なし
</option>
</select>
</label>
</div>

<div class="form-group">
<label>
<span>SMTPユーザー名</span>
<input type="text"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>">
</label>
</div>

<div class="form-group">
<label>
<span>SMTPパスワード</span>
<input type="password"
       name="password"
       value=""
       autocomplete="new-password"
       placeholder="変更する場合のみ入力">
</label>

<div class="help">
保存済みパスワードは画面へ再表示しません。
</div>
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

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
    設定保存
</button>

</div>

</form>

<hr>

<!-- ======================================================
     SMTP接続テスト
     ====================================================== -->

<form method="post"
      data-loading
      autocomplete="off">

<input type="hidden"
       name="action"
       value="test_mail">

<input type="hidden"
       name="server"
       value="<?= h(
           $config['server']
       ) ?>">

<input type="hidden"
       name="port"
       value="<?= h(
           $config['port']
       ) ?>">

<input type="hidden"
       name="encryption"
       value="<?= h(
           $config['encryption']
       ) ?>">

<input type="hidden"
       name="auth"
       value="<?= !empty(
           $config['auth']
       )
           ? '1'
           : '0' ?>">

<input type="hidden"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>">

<input type="hidden"
       name="from_email"
       value="<?= h(
           $config['from_email']
       ) ?>">

<input type="hidden"
       name="from_name"
       value="<?= h(
           $config['from_name']
       ) ?>">

<input type="hidden"
       name="reply_to"
       value="<?= h(
           $config['reply_to']
       ) ?>">

<div class="form-group">
<label>
<span>接続テスト用パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みの場合は空欄でも可">
</label>
</div>

<div class="button-row"
     style="margin-top:12px">

<button class="btn btn-secondary"
        type="submit">
    接続テスト
</button>

</div>

</form>

<hr>

<!-- ======================================================
     テストメール
     ====================================================== -->

<form method="post"
      data-loading
      autocomplete="off">

<input type="hidden"
       name="action"
       value="send_test_mail">

<input type="hidden"
       name="server"
       value="<?= h(
           $config['server']
       ) ?>">

<input type="hidden"
       name="port"
       value="<?= h(
           $config['port']
       ) ?>">

<input type="hidden"
       name="encryption"
       value="<?= h(
           $config['encryption']
       ) ?>">

<input type="hidden"
       name="auth"
       value="<?= !empty(
           $config['auth']
       )
           ? '1'
           : '0' ?>">

<input type="hidden"
       name="username"
       value="<?= h(
           $config['username']
       ) ?>">

<input type="hidden"
       name="from_email"
       value="<?= h(
           $config['from_email']
       ) ?>">

<input type="hidden"
       name="from_name"
       value="<?= h(
           $config['from_name']
       ) ?>">

<input type="hidden"
       name="reply_to"
       value="<?= h(
           $config['reply_to']
       ) ?>">

<div class="grid grid-2">

<div class="form-group">
<label>
<span>テスト送信先</span>
<input type="email"
       name="test_to"
       placeholder="test@example.com"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>テストメール用パスワード</span>
<input type="password"
       name="password"
       autocomplete="new-password"
       placeholder="保存済みの場合は空欄でも可">
</label>
</div>

</div>

<div class="button-row"
     style="margin-top:12px">

<button class="btn btn-secondary"
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

/* ============================================================
 * 送信
 * ============================================================ */

function render_send(
    ?array $survey
): void {
    if ($survey === null) {
        render_head(
            '顧客選択・メール送信'
        );

        echo '<div class="alert alert-error">'
            . '対象アンケートが見つかりません。'
            . '</div>';

        echo '<a class="btn btn-secondary" href="'
            . h(
                app_url([
                    'screen' => 'list',
                ])
            )
            . '">一覧へ戻る</a>';

        render_footer();
        return;
    }

    $customers =
        load_customers();

    $result =
        $_SESSION[
            'send_result'
        ] ?? null;

    unset(
        $_SESSION[
            'send_result'
        ]
    );

    render_head(
        '顧客選択・メール送信'
    );
?>
<div class="page-title">
    <div>
        <h1>顧客選択・メール送信</h1>
        <p>
            対象：
            <strong>
                <?= h(
                    $survey['title']
                ) ?>
            </strong>
        </p>
    </div>

    <a class="btn btn-secondary"
       href="<?= h(
           app_url([
               'screen' => 'list',
           ])
       ) ?>">
        一覧へ戻る
    </a>
</div>

<?php if (
    is_array($result)
): ?>
<div class="alert <?= !empty(
    $result['ok']
)
    ? 'alert-success'
    : 'alert-error' ?>">
    <?= h(
        $result['message']
        ?? ''
    ) ?>
</div>
<?php endif; ?>

<div class="card">
<div class="card-header">
    <h2>顧客選択・メール作成</h2>
</div>

<div class="card-body">

<form method="post"
      data-loading
      data-confirm="選択した顧客へメールを送信します。実行しますか？">

<input type="hidden"
       name="action"
       value="send_mail">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<div class="form-group">
<span class="field-label">
    顧客
</span>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th></th>
    <th>組織名</th>
    <th>氏名</th>
    <th>メールアドレス</th>
    <th>部署名</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $customers as $customer
): ?>

<tr>
<td>
<input type="checkbox"
       name="customer_ids[]"
       value="<?= h(
           $customer['id']
           ?? ''
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

<?php if (!$customers): ?>
<tr>
<td colspan="5">
    <div class="empty">
        顧客情報がありません。
        kintone設定から同期してください。
    </div>
</td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>
</div>

<hr>

<div class="form-group">
<label>
<span>メール件名</span>
<input type="text"
       name="mail_subject"
       value="<?= h(
           $survey['title']
           ?? ''
       ) ?>"
       required>
</label>
</div>

<div class="form-group">
<label>
<span>メール本文</span>
<textarea name="mail_body"
          required>こんにちは、{顧客名}様。

以下のURLからアンケートへご回答ください。

{アンケートURL}

よろしくお願いいたします。</textarea>
</label>

<div class="help">
使用可能な変数：
{顧客名} / {アンケートURL}
</div>
</div>

<div class="button-row"
     style="margin-top:16px">

<button class="btn btn-primary"
        type="submit">
    一括送信
</button>

</div>

</form>

</div>
</div>

<div class="card">
<div class="card-header">
    <h2>送信履歴</h2>
</div>

<div class="card-body">

<?php
$history =
    load_history();

$surveyHistory = [];

foreach (
    $history as $item
) {
    if (
        ($item['survey_id'] ?? '')
        === ($survey['id'] ?? '')
    ) {
        $surveyHistory[] =
            $item;
    }
}

usort(
    $surveyHistory,
    static function(
        array $a,
        array $b
    ): int {
        return strcmp(
            (string)(
                $b['createdAt']
                ?? ''
            ),
            (string)(
                $a['createdAt']
                ?? ''
            )
        );
    }
);
?>

<?php if (
    !$surveyHistory
): ?>

<div class="empty">
    送信履歴はありません。
</div>

<?php else: ?>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>日時</th>
    <th>顧客名</th>
    <th>メール</th>
    <th>種別</th>
    <th>結果</th>
</tr>
</thead>

<tbody>

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
    $item['email']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $item['type']
    ?? ''
) ?>
</td>

<td>
<span class="badge <?= (
    ($item['status'] ?? '')
    === 'success'
)
    ? 'badge-success'
    : 'badge-gray' ?>">
    <?= (
        ($item['status'] ?? '')
        === 'success'
    )
        ? '成功'
        : '失敗' ?>
</span>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

<?php endif; ?>

</div>
</div>

<?php
render_footer();
}

/* ============================================================
 * 集計
 * ============================================================ */

function render_analytics(
    ?array $survey
): void {
    if ($survey === null) {
        render_head(
            '回答集計・分析'
        );

        echo '<div class="alert alert-error">'
            . '対象アンケートが見つかりません。'
            . '</div>';

        echo '<a class="btn btn-secondary" href="'
            . h(
                app_url([
                    'screen' => 'list',
                ])
            )
            . '">一覧へ戻る</a>';

        render_footer();
        return;
    }

    $answers =
        load_answers();

    $surveyAnswers = [];

    foreach (
        $answers as $answer
    ) {
        if (
            ($answer['survey_id'] ?? '')
            === ($survey['id'] ?? '')
        ) {
            $surveyAnswers[] =
                $answer;
        }
    }

    $customers =
        load_customers();

    $history =
        load_history();

    $sendCount = 0;

    foreach (
        $history as $item
    ) {
        if (
            ($item['survey_id'] ?? '')
            === ($survey['id'] ?? '')
            && ($item['status'] ?? '')
                === 'success'
        ) {
            $sendCount++;
        }
    }

    $answerCount =
        count($surveyAnswers);

    $rate =
        $sendCount > 0
            ? round(
                $answerCount
                / $sendCount
                * 100,
                1
            )
            : 0;

    render_head(
        '回答集計・分析'
    );
?>
<div class="page-title">
    <div>
        <h1>回答集計・分析</h1>
        <p>
            対象：
            <strong>
                <?= h(
                    $survey['title']
                ) ?>
            </strong>
        </p>
    </div>

    <a class="btn btn-secondary"
       href="<?= h(
           app_url([
               'screen' => 'list',
           ])
       ) ?>">
        一覧へ戻る
    </a>
</div>

<div class="stats">

<div class="stat">
<div class="stat-label">
送信対象者数
</div>
<div class="stat-value">
<?= h($sendCount) ?>
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
未回答数
</div>
<div class="stat-value">
<?= h(
    max(
        0,
        $sendCount
        - $answerCount
    )
) ?>
</div>
</div>

<div class="stat">
<div class="stat-label">
回答率
</div>
<div class="stat-value">
<?= h($rate) ?>%
</div>
</div>

</div>

<?php if (
    $answerCount === 0
): ?>

<div class="alert alert-warning"
     style="margin-top:20px">
    現在、回答データはありません
</div>

<?php else: ?>

<div class="card"
     style="margin-top:20px">

<div class="card-header">
    <h2>個別回答</h2>
</div>

<div class="card-body">

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>回答日時</th>
    <th>回答ID</th>
    <th>回答内容</th>
</tr>
</thead>

<tbody>

<?php foreach (
    $surveyAnswers as $answer
): ?>

<tr>
<td>
<?= h(
    $answer['createdAt']
    ?? ''
) ?>
</td>

<td>
<?= h(
    $answer['id']
    ?? ''
) ?>
</td>

<td>
<pre class="debug"><?= h(
    json_encode(
        $answer['answers']
        ?? [],
        JSON_UNESCAPED_UNICODE
        | JSON_PRETTY_PRINT
    )
) ?></pre>
</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

</div>
</div>

<?php endif; ?>

<?php
render_footer();
}

/* ============================================================
 * 回答者
 * ============================================================ */

function render_answer(
    array $survey
): void {
    recalc_question_numbers(
        $survey
    );

    render_head(
        $survey['title']
        ?? 'アンケート',
        false
    );
?>
<div class="preview-box">

<div class="card">
<div class="card-body">

<h1>
    <?= h(
        $survey['title']
    ) ?>
</h1>

<?php if (
    !empty(
        $survey['description']
    )
): ?>
<p>
    <?= nl2br(
        h(
            $survey['description']
        )
    ) ?>
</p>
<?php endif; ?>

<form method="post"
      data-loading>

<input type="hidden"
       name="action"
       value="save_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="section">

<h2>
    <?= h(
        $group['title']
    ) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<div class="question-head">
<span class="question-number">
<?= h(
    $question['number']
) ?>
</span>

<?php if (
    !empty(
        $question['required']
    )
): ?>
<span class="badge badge-warning">
必須
</span>
<?php endif; ?>
</div>

<div class="form-group">
<span class="field-label">
<?= h(
    $question['text']
    ?: '質問'
) ?>
</span>

<?php if (
    ($question['type'] ?? '')
    === 'text'
): ?>

<textarea
    name="answer[<?= h(
        $question['id']
    ) ?>]"
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
    ($question['options'] ?? [])
    as $option
): ?>

<label class="answer-choice">
<input type="checkbox"
       name="answer[<?= h(
           $question['id']
       ) ?>][]"
       value="<?= h(
           $option
       ) ?>">
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php else: ?>

<?php foreach (
    ($question['options'] ?? [])
    as $option
): ?>

<label class="answer-choice">
<input type="radio"
       name="answer[<?= h(
           $question['id']
       ) ?>]"
       value="<?= h(
           $option
       ) ?>"
       <?= !empty(
           $question['required']
       )
           ? 'required'
           : '' ?>>
<?= h($option) ?>
</label>

<?php endforeach; ?>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="button-row"
     style="margin-top:20px">

<button class="btn btn-primary"
        type="submit">
    回答確認へ
</button>

</div>

</form>

</div>
</div>

</div>
<?php
render_footer();
}

/* ============================================================
 * 回答確認
 * ============================================================ */

function render_confirm(
    array $survey
): void {
    $draft =
        $_SESSION[
            'answer_draft_'
            . $survey['id']
        ] ?? [];

    if (!is_array($draft)) {
        $draft = [];
    }

    render_head(
        '回答確認',
        false
    );
?>
<div class="preview-box">

<div class="card">
<div class="card-body">

<h1>回答確認</h1>

<p>
以下の内容で送信します。
</p>

<?php foreach (
    $survey['groups']
    as $group
): ?>

<div class="section">

<h2>
<?= h(
    $group['title']
) ?>
</h2>

<?php foreach (
    $group['questions']
    as $question
): ?>

<div class="question-card">

<div class="question-number">
<?= h(
    $question['number']
) ?>
</div>

<p>
<?= h(
    $question['text']
    ?: '質問'
) ?>
</p>

<div>
<?php
$value =
    $draft[
        $question['id']
    ] ?? '';

if (is_array($value)) {
    echo h(
        implode(
            '、',
            array_map(
                'strval',
                $value
            )
        )
    );
} else {
    echo nl2br(
        h((string)$value)
    );
}
?>
</div>

</div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

<div class="button-row">

<a class="btn btn-secondary"
   href="<?= h(
       app_url([
           'screen' =>
               'answer',
           'id' =>
               $survey['id'],
       ])
   ) ?>">
    修正する
</a>

<form method="post"
      data-loading
      data-confirm="回答を送信します。よろしいですか？">

<input type="hidden"
       name="action"
       value="complete_answer">

<input type="hidden"
       name="survey_id"
       value="<?= h(
           $survey['id']
       ) ?>">

<button class="btn btn-primary"
        type="submit">
    回答を送信
</button>

</form>

</div>

</div>
</div>

</div>
<?php
render_footer();
}

/* ============================================================
 * 完了
 * ============================================================ */

function render_complete(): void
{
    render_head(
        '回答完了',
        false
    );
?>
<div class="preview-box">

<div class="card">
<div class="card-body"
     style="text-align:center;padding:50px 25px">

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

</div>
<?php
render_footer();
}

/* ============================================================
 * ルーティング
 * ============================================================ */

if (
    in_array(
        $screen,
        ['answer', 'confirm'],
        true
    )
) {
    if (
        !safe_id($id)
    ) {
        render_head(
            'アンケート',
            false
        );

        echo '<div class="alert alert-error">'
            . 'アンケートが見つかりません。'
            . '</div>';

        render_footer();
        exit;
    }

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

        echo '<div class="alert alert-error">'
            . 'アンケートが見つかりません。'
            . '</div>';

        render_footer();
        exit;
    }

    if (
        ($survey['status'] ?? '')
        !== 'published'
    ) {
        render_head(
            'アンケート',
            false
        );

        echo '<div class="alert alert-warning">'
            . 'このアンケートは現在回答を受け付けていません。'
            . '</div>';

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
 * 管理者画面
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

            header(
                'Location: '
                . app_url([
                    'screen' =>
                        'list',
                ]),
                true,
                303
            );
            exit;
        }

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

        render_send(
            $survey
        );
        break;

    case 'analytics':
        $survey =
            find_survey(
                $surveys,
                $id
            );

        render_analytics(
            $survey
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
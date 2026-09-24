<?php
declare(strict_types=1);

namespace yokoyamy\trial\newapp;

/**
 * アンケート業務運営アプリ
 *
 * 第1回：
 * - PHP共通処理
 * - セッション
 * - CSRF
 * - JSONデータ管理
 * - 設定管理
 * - 共通API基盤
 * - kintone通信基盤
 * - HTML/CSSの先頭
 *
 * Apache 2.4 + PHP 8.4 / 8.5
 * データベースなし
 * cURL不使用
 */

/* =========================================================
 * 基本設定
 * ========================================================= */

const APP_SESSION_KEY = 'yokoyamy_newapp';
const DATA_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'settings.json';
const CUSTOMERS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'customers.json';
const SURVEYS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'surveys.json';
const RESPONSES_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'responses.json';
const MAIL_LOGS_FILE = DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'mail_logs.json';

const MAX_TEXT_LENGTH = 20000;
const MAX_NAME_LENGTH = 200;
const MAX_DESCRIPTION_LENGTH = 5000;
const MAX_OPTION_LENGTH = 500;
const MAX_OPTIONS = 100;
const MAX_GROUPS = 100;
const MAX_QUESTIONS = 500;

/* =========================================================
 * HTTPヘッダー
 * ========================================================= */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/*
 * 本アプリは同一Origin通信を前提とする。
 *
 * Access-Control-Allow-Origin は設定しない。
 * Origin:null を許可するCORS実装もしない。
 */

/* =========================================================
 * セッション
 * ========================================================= */

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

if (!isset($_SESSION[APP_SESSION_KEY])
    || !is_array($_SESSION[APP_SESSION_KEY])) {
    $_SESSION[APP_SESSION_KEY] = [];
}

if (
    !isset($_SESSION[APP_SESSION_KEY]['csrf_token'])
    || !is_string($_SESSION[APP_SESSION_KEY]['csrf_token'])
    || $_SESSION[APP_SESSION_KEY]['csrf_token'] === ''
) {
    $_SESSION[APP_SESSION_KEY]['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================================
 * 共通エスケープ
 * ========================================================= */

/**
 * 安全な文字列エスケープ
 *
 * @param string|null $str
 * @return string
 */
function h(?string $str): string
{
    return htmlspecialchars(
        $str ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* =========================================================
 * 共通レスポンス
 * ========================================================= */

/**
 * JSON APIレスポンスを返して終了する。
 *
 * @param array<string,mixed> $response
 * @param int $statusCode
 * @return never
 */
function json_response(array $response, int $statusCode = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    $json = json_encode(
        $response,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        $json = '{"success":false,"message":"JSONレスポンスを生成できませんでした。"}';
        http_response_code(500);
    }

    echo $json;
    exit;
}

/**
 * API成功レスポンス。
 *
 * @param string $message
 * @param array<string,mixed> $data
 * @return never
 */
function api_success(
    string $message = '処理が完了しました。',
    array $data = []
): never {
    $response = [
        'success' => true,
        'message' => $message,
    ];

    if ($data !== []) {
        $response['data'] = $data;
    }

    json_response($response, 200);
}

/**
 * APIエラーレスポンス。
 *
 * @param string $message
 * @param int $statusCode
 * @param array<string,mixed> $errors
 * @return never
 */
function api_error(
    string $message,
    int $statusCode = 400,
    array $errors = []
): never {
    $response = [
        'success' => false,
        'message' => $message,
    ];

    if ($errors !== []) {
        $response['errors'] = $errors;
    }

    json_response($response, $statusCode);
}

/* =========================================================
 * 入力取得
 * ========================================================= */

/**
 * リクエストJSON本文を取得する。
 *
 * @return array<string,mixed>
 */
function get_json_request_body(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (
        $contentType !== ''
        && stripos($contentType, 'application/json') === false
    ) {
        api_error(
            'JSON形式のリクエストが必要です。',
            400
        );
    }

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        api_error(
            'リクエスト内容がありません。',
            400
        );
    }

    try {
        $data = json_decode(
            $raw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\JsonException) {
        api_error(
            '送信されたデータを読み取れませんでした。',
            400
        );
    }

    if (!is_array($data)) {
        api_error(
            '送信されたデータの形式が正しくありません。',
            400
        );
    }

    return $data;
}

/**
 * 文字列入力を取得する。
 *
 * @param array<string,mixed> $data
 * @param string $key
 * @param string $default
 * @return string
 */
function input_string(
    array $data,
    string $key,
    string $default = ''
): string {
    $value = $data[$key] ?? $default;

    if (!is_string($value)) {
        return $default;
    }

    return trim($value);
}

/**
 * 整数入力を取得する。
 *
 * @param array<string,mixed> $data
 * @param string $key
 * @param int $default
 * @return int
 */
function input_int(
    array $data,
    string $key,
    int $default = 0
): int {
    $value = $data[$key] ?? $default;

    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
        return (int)$value;
    }

    return $default;
}

/**
 * 真偽値入力を取得する。
 *
 * @param array<string,mixed> $data
 * @param string $key
 * @param bool $default
 * @return bool
 */
function input_bool(
    array $data,
    string $key,
    bool $default = false
): bool {
    $value = $data[$key] ?? $default;

    if (is_bool($value)) {
        return $value;
    }

    if ($value === 1 || $value === '1' || $value === 'true') {
        return true;
    }

    if ($value === 0 || $value === '0' || $value === 'false') {
        return false;
    }

    return $default;
}

/* =========================================================
 * セッション・CSRF
 * ========================================================= */

/**
 * CSRFトークン取得。
 *
 * @return string
 */
function get_csrf_token(): string
{
    $token = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';

    if (!is_string($token) || $token === '') {
        api_error(
            'セキュリティ情報を取得できませんでした。',
            500
        );
    }

    return $token;
}

/**
 * CSRF検証。
 *
 * @param array<string,mixed> $data
 * @return void
 */
function verify_csrf(array $data): void
{
    $requestToken = '';

    if (isset($data['csrf_token']) && is_string($data['csrf_token'])) {
        $requestToken = $data['csrf_token'];
    }

    if ($requestToken === '') {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (is_string($headerToken)) {
            $requestToken = $headerToken;
        }
    }

    $sessionToken = $_SESSION[APP_SESSION_KEY]['csrf_token'] ?? '';

    if (
        !is_string($sessionToken)
        || $sessionToken === ''
        || $requestToken === ''
        || !hash_equals($sessionToken, $requestToken)
    ) {
        api_error(
            'セキュリティ確認に失敗しました。画面を再読み込みして再度お試しください。',
            403
        );
    }
}

/* =========================================================
 * HTTPメソッド
 * ========================================================= */

/**
 * 現在のHTTPメソッド。
 *
 * @return string
 */
function request_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

/**
 * APIリクエストかどうか。
 *
 * @return bool
 */
function is_api_request(): bool
{
    return isset($_GET['action'])
        && is_string($_GET['action'])
        && trim($_GET['action']) !== '';
}

/* =========================================================
 * データディレクトリ
 * ========================================================= */

/**
 * データディレクトリを準備する。
 *
 * @return void
 */
function ensure_data_directory(): void
{
    if (is_dir(DATA_DIRECTORY)) {
        return;
    }

    if (!mkdir(DATA_DIRECTORY, 0700, true) && !is_dir(DATA_DIRECTORY)) {
        api_error(
            'データ保存領域を準備できませんでした。',
            500
        );
    }
}

/**
 * 初期JSONを準備する。
 *
 * @return void
 */
function ensure_data_files(): void
{
    ensure_data_directory();

    $defaults = [
        SETTINGS_FILE => [
            'version' => 1,
            'mail' => [
                'smtp_server' => '',
                'smtp_port' => 587,
                'security' => 'STARTTLS',
                'authentication' => false,
                'username' => '',
                'password' => '',
                'from_address' => '',
                'from_name' => '',
                'configured' => false,
                'verified' => false,
                'updated_at' => '',
                'version' => 1,
            ],
            'kintone' => [
                'domain' => '',
                'app_id' => '',
                'login_name' => '',
                'password' => '',
                'proxy_host_port' => '',
                'proxy_enabled' => false,
                'configured' => false,
                'verified' => false,
                'updated_at' => '',
                'version' => 1,
            ],
        ],
        CUSTOMERS_FILE => [
            'version' => 1,
            'updated_at' => '',
            'customers' => [],
        ],
        SURVEYS_FILE => [
            'version' => 1,
            'updated_at' => '',
            'surveys' => [],
        ],
        RESPONSES_FILE => [
            'version' => 1,
            'updated_at' => '',
            'responses' => [],
        ],
        MAIL_LOGS_FILE => [
            'version' => 1,
            'updated_at' => '',
            'logs' => [],
        ],
    ];

    foreach ($defaults as $file => $default) {
        if (file_exists($file)) {
            continue;
        }

        write_json_file($file, $default);
    }
}

/* =========================================================
 * JSON読み込み
 * ========================================================= */

/**
 * JSONファイルを読み込む。
 *
 * @param string $file
 * @return array<string,mixed>
 */
function read_json_file(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        api_error(
            'データを読み込めませんでした。管理者へ確認してください。',
            500
        );
    }

    $fp = @fopen($file, 'rb');

    if ($fp === false) {
        api_error(
            'データを読み込めませんでした。管理者へ確認してください。',
            500
        );
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);

        api_error(
            'データを読み込めませんでした。管理者へ確認してください。',
            500
        );
    }

    $contents = stream_get_contents($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($contents === false || trim($contents) === '') {
        api_error(
            'データを読み込めませんでした。管理者へ確認してください。',
            500
        );
    }

    try {
        $data = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\JsonException) {
        api_error(
            'データを読み込めませんでした。管理者へ確認してください。',
            500
        );
    }

    if (!is_array($data)) {
        api_error(
            'データを読み込めませんでした。管理者へ確認してください。',
            500
        );
    }

    return $data;
}

/* =========================================================
 * JSON保存
 * ========================================================= */

/**
 * JSONファイルへ安全に保存する。
 *
 * 一時ファイルへ書き込み後、renameする。
 *
 * @param string $file
 * @param array<string,mixed> $data
 * @return void
 */
function write_json_file(
    string $file,
    array $data
): void {
    ensure_data_directory();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        api_error(
            'データを保存できませんでした。',
            500
        );
    }

    $directory = dirname($file);

    $tempFile = $directory
        . DIRECTORY_SEPARATOR
        . '.'
        . basename($file)
        . '.tmp.'
        . bin2hex(random_bytes(8));

    $fp = @fopen($tempFile, 'xb');

    if ($fp === false) {
        api_error(
            'データを保存できませんでした。',
            500
        );
    }

    $success = false;

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new \RuntimeException('lock');
        }

        $written = fwrite($fp, $json . PHP_EOL);

        if ($written === false || $written < strlen($json)) {
            throw new \RuntimeException('write');
        }

        fflush($fp);
        flock($fp, LOCK_UN);

        fclose($fp);
        $fp = null;

        if (!@rename($tempFile, $file)) {
            throw new \RuntimeException('rename');
        }

        $success = true;
    } catch (\Throwable) {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        @unlink($tempFile);

        api_error(
            'データを保存できませんでした。既存データは保持されています。',
            500
        );
    }

    if (!$success) {
        @unlink($tempFile);

        api_error(
            'データを保存できませんでした。既存データは保持されています。',
            500
        );
    }
}

/* =========================================================
 * データ整合性
 * ========================================================= */

/**
 * 設定データを正規化する。
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function normalize_settings(array $data): array
{
    $mail = isset($data['mail']) && is_array($data['mail'])
        ? $data['mail']
        : [];

    $kintone = isset($data['kintone']) && is_array($data['kintone'])
        ? $data['kintone']
        : [];

    return [
        'version' => 1,
        'mail' => [
            'smtp_server' => is_string($mail['smtp_server'] ?? null)
                ? $mail['smtp_server']
                : '',
            'smtp_port' => is_int($mail['smtp_port'] ?? null)
                ? $mail['smtp_port']
                : 587,
            'security' => is_string($mail['security'] ?? null)
                ? $mail['security']
                : 'STARTTLS',
            'authentication' => is_bool($mail['authentication'] ?? null)
                ? $mail['authentication']
                : false,
            'username' => is_string($mail['username'] ?? null)
                ? $mail['username']
                : '',
            'password' => is_string($mail['password'] ?? null)
                ? $mail['password']
                : '',
            'from_address' => is_string($mail['from_address'] ?? null)
                ? $mail['from_address']
                : '',
            'from_name' => is_string($mail['from_name'] ?? null)
                ? $mail['from_name']
                : '',
            'configured' => is_bool($mail['configured'] ?? null)
                ? $mail['configured']
                : false,
            'verified' => is_bool($mail['verified'] ?? null)
                ? $mail['verified']
                : false,
            'updated_at' => is_string($mail['updated_at'] ?? null)
                ? $mail['updated_at']
                : '',
            'version' => is_int($mail['version'] ?? null)
                ? $mail['version']
                : 1,
        ],
        'kintone' => [
            'domain' => is_string($kintone['domain'] ?? null)
                ? $kintone['domain']
                : '',
            'app_id' => is_string($kintone['app_id'] ?? null)
                ? $kintone['app_id']
                : '',
            'login_name' => is_string($kintone['login_name'] ?? null)
                ? $kintone['login_name']
                : '',
            'password' => is_string($kintone['password'] ?? null)
                ? $kintone['password']
                : '',
            'proxy_host_port' => is_string($kintone['proxy_host_port'] ?? null)
                ? $kintone['proxy_host_port']
                : '',
            'proxy_enabled' => is_bool($kintone['proxy_enabled'] ?? null)
                ? $kintone['proxy_enabled']
                : false,
            'configured' => is_bool($kintone['configured'] ?? null)
                ? $kintone['configured']
                : false,
            'verified' => is_bool($kintone['verified'] ?? null)
                ? $kintone['verified']
                : false,
            'updated_at' => is_string($kintone['updated_at'] ?? null)
                ? $kintone['updated_at']
                : '',
            'version' => is_int($kintone['version'] ?? null)
                ? $kintone['version']
                : 1,
        ],
    ];
}

/**
 * 現在日時を取得する。
 *
 * @return string
 */
function current_datetime(): string
{
    $date = new \DateTimeImmutable(
        'now',
        new \DateTimeZone('Asia/Tokyo')
    );

    return $date->format('c');
}

/* =========================================================
 * パスワードを除いた設定表示用データ
 * ========================================================= */

/**
 * 画面表示用設定を生成する。
 *
 * パスワード・認証情報は返さない。
 *
 * @param array<string,mixed> $settings
 * @return array<string,mixed>
 */
function settings_for_display(
    array $settings
): array {
    $normalized = normalize_settings($settings);

    $mail = $normalized['mail'];
    $kintone = $normalized['kintone'];

    if (is_array($mail)) {
        unset($mail['password']);
    }

    if (is_array($kintone)) {
        unset($kintone['password']);
    }

    return [
        'version' => 1,
        'mail' => $mail,
        'kintone' => $kintone,
    ];
}

/* =========================================================
 * メール設定検証
 * ========================================================= */

/**
 * メール設定を検証する。
 *
 * @param array<string,mixed> $data
 * @param array<string,mixed> $existingMail
 * @return array<string,mixed>
 */
function validate_mail_settings(
    array $data,
    array $existingMail
): array {
    $errors = [];

    $smtpServer = input_string($data, 'smtp_server');
    $smtpPort = input_int($data, 'smtp_port', 0);
    $security = input_string($data, 'security');
    $authentication = input_bool($data, 'authentication');
    $username = input_string($data, 'username');
    $password = input_string($data, 'password');
    $fromAddress = input_string($data, 'from_address');
    $fromName = input_string($data, 'from_name');

    if ($smtpServer === '') {
        $errors['smtp_server'] = 'SMTPサーバを入力してください。';
    } elseif (mb_strlen($smtpServer, 'UTF-8') > 255) {
        $errors['smtp_server'] = 'SMTPサーバは255文字以内で入力してください。';
    } elseif (preg_match('/[\r\n\x00-\x1F\x7F]/u', $smtpServer) === 1) {
        $errors['smtp_server'] = 'SMTPサーバに使用できない文字が含まれています。';
    }

    if ($smtpPort < 1 || $smtpPort > 65535) {
        $errors['smtp_port'] = 'ポート番号は1～65535で入力してください。';
    }

    $allowedSecurity = [
        'NONE',
        'STARTTLS',
        'SSL_TLS',
    ];

    if (!in_array($security, $allowedSecurity, true)) {
        $errors['security'] = '接続方式が正しくありません。';
    }

    if ($authentication) {
        if ($username === '') {
            $errors['username'] = 'SMTP認証を使用する場合は認証ユーザー名を入力してください。';
        } elseif (mb_strlen($username, 'UTF-8') > 255) {
            $errors['username'] = '認証ユーザー名は255文字以内で入力してください。';
        }

        if ($password === '') {
            $existingPassword = $existingMail['password'] ?? '';

            if (
                !is_string($existingPassword)
                || $existingPassword === ''
            ) {
                $errors['password'] = 'SMTP認証を使用する場合は認証パスワードを入力してください。';
            } else {
                $password = $existingPassword;
            }
        }
    } else {
        $username = '';
        $password = '';
    }

    if ($fromAddress === '') {
        $errors['from_address'] = '送信元メールアドレスを入力してください。';
    } elseif (
        preg_match(
            '/[\r\n\x00-\x1F\x7F]/u',
            $fromAddress
        ) === 1
    ) {
        $errors['from_address'] = '送信元メールアドレスに使用できない文字が含まれています。';
    } elseif (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        $errors['from_address'] = '送信元メールアドレスの形式が正しくありません。';
    } elseif (mb_strlen($fromAddress, 'UTF-8') > 320) {
        $errors['from_address'] = '送信元メールアドレスが長すぎます。';
    }

    if (
        preg_match(
            '/[\r\n\x00-\x1F\x7F]/u',
            $fromName
        ) === 1
    ) {
        $errors['from_name'] = '送信元名に使用できない文字が含まれています。';
    } elseif (mb_strlen($fromName, 'UTF-8') > 255) {
        $errors['from_name'] = '送信元名は255文字以内で入力してください。';
    }

    if ($errors !== []) {
        api_error(
            '入力内容を確認してください。',
            400,
            $errors
        );
    }

    return [
        'smtp_server' => $smtpServer,
        'smtp_port' => $smtpPort,
        'security' => $security,
        'authentication' => $authentication,
        'username' => $username,
        'password' => $password,
        'from_address' => $fromAddress,
        'from_name' => $fromName,
    ];
}

/* =========================================================
 * kintone URL
 * ========================================================= */

/**
 * kintone URLを安全に生成する。
 *
 * @param string $domain
 * @param string $endpoint
 * @return string
 */
function kintone_build_url(
    string $domain,
    string $endpoint
): string {
    $domain = trim($domain);

    $domain = preg_replace(
        '/^https?:\/\//i',
        '',
        $domain
    ) ?? '';

    $domain = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $domain
    ) ?? '';

    $domain = rtrim($domain, '/');

    if ($domain === '') {
        api_error(
            'kintoneの利用先を入力してください。',
            400
        );
    }

    if (
        preg_match(
            '/[^a-zA-Z0-9.-]/',
            $domain
        ) === 1
    ) {
        api_error(
            'kintoneの利用先の形式が正しくありません。',
            400
        );
    }

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://'
        . $domain
        . '.cybozu.com'
        . $endpoint;
}

/* =========================================================
 * kintoneレスポンスヘッダー
 * ========================================================= */

/**
 * PHP 8.4 / 8.5対応のレスポンスヘッダー取得。
 *
 * @return array<int,string>
 */
function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();

        if (is_array($headers)) {
            return $headers;
        }
    }

    return [];
}

/* =========================================================
 * kintone認証
 * ========================================================= */

/**
 * X-Cybozu-Authorizationヘッダーを生成する。
 *
 * @param string $loginName
 * @param string $password
 * @return string
 */
function make_cybozu_auth_header(
    string $loginName,
    string $password
): string {
    $loginName = trim($loginName);
    $password = trim($password);

    $authString = base64_encode(
        $loginName . ':' . $password
    );

    return 'X-Cybozu-Authorization: ' . $authString;
}

/* =========================================================
 * プロキシ検証
 * ========================================================= */

/**
 * プロキシ設定を解析する。
 *
 * @param string $proxyHostPort
 * @return array{enabled:bool,address:string}
 */
function parse_proxy_host_port(
    string $proxyHostPort
): array {
    $proxyHostPort = trim($proxyHostPort);

    if ($proxyHostPort === '') {
        return [
            'enabled' => false,
            'address' => '',
        ];
    }

    if (
        preg_match(
            '/^([a-zA-Z0-9.-]+):([0-9]{1,5})$/',
            $proxyHostPort,
            $matches
        ) !== 1
    ) {
        api_error(
            'プロキシは「ホスト名:ポート番号」の形式で入力してください。',
            400,
            [
                'proxy_host_port' =>
                    '例：proxy.example.local:8080'
            ]
        );
    }

    $host = $matches[1];
    $port = (int)$matches[2];

    if ($port < 1 || $port > 65535) {
        api_error(
            'プロキシのポート番号が正しくありません。',
            400,
            [
                'proxy_host_port' =>
                    'ポート番号は1～65535で入力してください。'
            ]
        );
    }

    return [
        'enabled' => true,
        'address' => 'tcp://' . $host . ':' . $port,
    ];
}

/* =========================================================
 * kintone API通信
 * ========================================================= */

/**
 * kintone REST APIリクエスト。
 *
 * cURLは使用しない。
 *
 * @param string $method
 * @param string $url
 * @param array<int,string> $headers
 * @param array<string,mixed>|string|null $payload
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    array|string|null $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 15,
    ];

    /*
     * GETではcontentを設定しない。
     */
    if (
        $method !== 'GET'
        && $payload !== null
    ) {
        if (is_array($payload)) {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
            );

            if ($encoded === false) {
                return [
                    'success' => false,
                    'status' => 500,
                    'message' =>
                        'kintoneへ送信するデータを作成できませんでした。',
                ];
            }

            $httpOptions['content'] = $encoded;
        } else {
            $httpOptions['content'] = $payload;
        }
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    /*
     * プロキシ設定。
     *
     * 設定がある場合は必ず、
     * proxy
     * request_fulluri
     * を適用する。
     */
    $proxyHostPort = '';

    if (
        isset($config['proxy_host_port'])
        && is_string($config['proxy_host_port'])
    ) {
        $proxyHostPort = trim($config['proxy_host_port']);
    }

    if ($proxyHostPort !== '') {
        $proxy = parse_proxy_host_port($proxyHostPort);

        if ($proxy['enabled']) {
            $contextOptions['http']['proxy'] =
                $proxy['address'];

            $contextOptions['http']['request_fulluri'] = true;
        }
    }

    $context = stream_context_create($contextOptions);

    $responseBody = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders = get_safe_response_headers();

    $statusCode = 0;

    foreach ($responseHeaders as $headerLine) {
        if (
            preg_match(
                '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i',
                $headerLine,
                $matches
            ) === 1
        ) {
            $statusCode = (int)$matches[1];
        }
    }

    $responseData = null;

    if (
        is_string($responseBody)
        && $responseBody !== ''
    ) {
        $decoded = json_decode(
            $responseBody,
            true
        );

        if (is_array($decoded)) {
            $responseData = $decoded;
        }
    }

    if (
        $statusCode >= 200
        && $statusCode < 300
    ) {
        return [
            'success' => true,
            'status' => $statusCode,
            'data' => $responseData,
        ];
    }

    $message =
        'kintone APIとの通信に失敗しました。';

    if (
        is_array($responseData)
        && isset($responseData['message'])
        && is_string($responseData['message'])
    ) {
        $message = $responseData['message'];
    }

    /*
     * errorsは利用者に原因を判断できる範囲だけ返す。
     *
     * 認証情報やAuthorization等は絶対に返さない。
     */
    $safeErrors = [];

    if (
        is_array($responseData)
        && isset($responseData['errors'])
        && is_array($responseData['errors'])
    ) {
        foreach (
            $responseData['errors']
            as $field => $error
        ) {
            if (!is_string($field)) {
                continue;
            }

            if (!is_array($error)) {
                continue;
            }

            $messages = $error['messages'] ?? [];

            if (!is_array($messages)) {
                continue;
            }

            $safeMessages = [];

            foreach ($messages as $errorMessage) {
                if (!is_string($errorMessage)) {
                    continue;
                }

                $safeMessages[] =
                    $errorMessage;
            }

            if ($safeMessages !== []) {
                $safeErrors[$field] =
                    $safeMessages;
            }
        }
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => $message,
        'errors' => $safeErrors,
    ];
}

/* =========================================================
 * kintone設定
 * ========================================================= */

/**
 * kintone設定を検証する。
 *
 * @param array<string,mixed> $data
 * @param array<string,mixed> $existing
 * @return array<string,mixed>
 */
function validate_kintone_settings(
    array $data,
    array $existing
): array {
    $errors = [];

    $domain = input_string(
        $data,
        'domain'
    );

    $appId = input_string(
        $data,
        'app_id'
    );

    $loginName = input_string(
        $data,
        'login_name'
    );

    $password = input_string(
        $data,
        'password'
    );

    $proxyHostPort = input_string(
        $data,
        'proxy_host_port'
    );

    $proxyEnabled = input_bool(
        $data,
        'proxy_enabled'
    );

    if ($domain === '') {
        $errors['domain'] =
            'kintoneの利用先を入力してください。';
    }

    if ($appId !== '') {
        if (
            preg_match(
                '/^\d+$/',
                $appId
            ) !== 1
        ) {
            $errors['app_id'] =
                'アプリIDは数字で入力してください。';
        }
    }

    if ($loginName === '') {
        $errors['login_name'] =
            'ログイン名を入力してください。';
    }

    if ($password === '') {
        $existingPassword =
            $existing['password'] ?? '';

        if (
            !is_string($existingPassword)
            || $existingPassword === ''
        ) {
            $errors['password'] =
                'ログインパスワードを入力してください。';
        } else {
            $password = $existingPassword;
        }
    }

    if ($proxyEnabled && $proxyHostPort === '') {
        $errors['proxy_host_port'] =
            'プロキシを使用する場合はホスト名:ポート番号を入力してください。';
    }

    if ($proxyHostPort !== '') {
        /*
         * 入力値検証のみをここで行う。
         * 実通信は接続確認時に行う。
         */
        parse_proxy_host_port(
            $proxyHostPort
        );
    }

    if ($errors !== []) {
        api_error(
            '入力内容を確認してください。',
            400,
            $errors
        );
    }

    return [
        'domain' => $domain,
        'app_id' => $appId,
        'login_name' => $loginName,
        'password' => $password,
        'proxy_host_port' => $proxyHostPort,
        'proxy_enabled' => $proxyEnabled,
    ];
}

/* =========================================================
 * 初期データ
 * ========================================================= */

ensure_data_files();

/* =========================================================
 * API処理
 * ========================================================= */

if (is_api_request()) {
    $action = trim(
        (string)$_GET['action']
    );

    /*
     * 状態変更APIはPOST限定。
     */
    $postActions = [
        'save_mail_settings',
        'test_mail_settings',
        'save_kintone_settings',
        'test_kintone_connection',
        'refresh_customers',
        'save_survey',
        'delete_survey',
        'publish_survey',
        'close_survey',
        'prepare_survey_send',
        'send_survey',
        'prepare_resend',
        'resend_survey',
        'save_response',
    ];

    /*
     * 参照API。
     */
    $getActions = [
        'load_settings',
        'load_customers',
        'load_surveys',
        'load_survey',
        'load_public_survey',
        'load_mail_logs',
        'aggregate_responses',
    ];

    if (
        in_array($action, $postActions, true)
        && request_method() !== 'POST'
    ) {
        api_error(
            'この操作にはPOST通信が必要です。',
            405
        );
    }

    if (
        in_array($action, $getActions, true)
        && request_method() !== 'GET'
    ) {
        api_error(
            'この操作にはGET通信が必要です。',
            405
        );
    }

    /*
     * 未定義APIを受け付けない。
     */
    if (
        !in_array($action, $postActions, true)
        && !in_array($action, $getActions, true)
    ) {
        api_error(
            '指定された操作は利用できません。',
            404
        );
    }

    /*
     * 状態変更APIでは必ずCSRF検証。
     */
    $requestData = [];

    if (in_array($action, $postActions, true)) {
        $requestData = get_json_request_body();
        verify_csrf($requestData);
    }

    /* -----------------------------------------------------
     * load_settings
     * ----------------------------------------------------- */
    if ($action === 'load_settings') {
        $settings =
            normalize_settings(
                read_json_file(
                    SETTINGS_FILE
                )
            );

        api_success(
            '設定を読み込みました。',
            [
                'settings' =>
                    settings_for_display(
                        $settings
                    ),
                'csrf_token' =>
                    get_csrf_token(),
            ]
        );
    }

    /* -----------------------------------------------------
     * save_mail_settings
     * ----------------------------------------------------- */
    if ($action === 'save_mail_settings') {
        $settings =
            normalize_settings(
                read_json_file(
                    SETTINGS_FILE
                )
            );

        $existingMail =
            is_array($settings['mail'])
                ? $settings['mail']
                : [];

        $mail =
            validate_mail_settings(
                $requestData,
                $existingMail
            );

        $currentVersion =
            is_int($existingMail['version'] ?? null)
                ? $existingMail['version']
                : 1;

        /*
         * メール設定だけを更新する。
         * kintone設定には触れない。
         */
        $settings['mail'] = [
            'smtp_server' =>
                $mail['smtp_server'],
            'smtp_port' =>
                $mail['smtp_port'],
            'security' =>
                $mail['security'],
            'authentication' =>
                $mail['authentication'],
            'username' =>
                $mail['username'],
            'password' =>
                $mail['password'],
            'from_address' =>
                $mail['from_address'],
            'from_name' =>
                $mail['from_name'],
            'configured' => true,
            'verified' => false,
            'updated_at' =>
                current_datetime(),
            'version' =>
                $currentVersion + 1,
        ];

        write_json_file(
            SETTINGS_FILE,
            $settings
        );

        /*
         * 保存後に再読み込みして確認する。
         */
        $savedSettings =
            normalize_settings(
                read_json_file(
                    SETTINGS_FILE
                )
            );

        if (
            !isset($savedSettings['mail'])
            || !is_array($savedSettings['mail'])
        ) {
            api_error(
                'メール設定を保存できませんでした。',
                500
            );
        }

        api_success(
            'メール送信設定を保存しました。接続確認を行ってください。',
            [
                'settings' =>
                    settings_for_display(
                        $savedSettings
                    ),
            ]
        );
    }

    /* -----------------------------------------------------
     * save_kintone_settings
     * ----------------------------------------------------- */
    if ($action === 'save_kintone_settings') {
        $settings =
            normalize_settings(
                read_json_file(
                    SETTINGS_FILE
                )
            );

        $existing =
            is_array($settings['kintone'])
                ? $settings['kintone']
                : [];

        $kintone =
            validate_kintone_settings(
                $requestData,
                $existing
            );

        $currentVersion =
            is_int($existing['version'] ?? null)
                ? $existing['version']
                : 1;

        $settings['kintone'] = [
            'domain' =>
                $kintone['domain'],
            'app_id' =>
                $kintone['app_id'],
            'login_name' =>
                $kintone['login_name'],
            'password' =>
                $kintone['password'],
            'proxy_host_port' =>
                $kintone['proxy_host_port'],
            'proxy_enabled' =>
                $kintone['proxy_enabled'],
            'configured' => true,
            'verified' => false,
            'updated_at' =>
                current_datetime(),
            'version' =>
                $currentVersion + 1,
        ];

        write_json_file(
            SETTINGS_FILE,
            $settings
        );

        $savedSettings =
            normalize_settings(
                read_json_file(
                    SETTINGS_FILE
                )
            );

        api_success(
            'kintone設定を保存しました。接続確認を行ってください。',
            [
                'settings' =>
                    settings_for_display(
                        $savedSettings
                    ),
            ]
        );
    }

    /* -----------------------------------------------------
     * test_kintone_connection
     * ----------------------------------------------------- */
    if ($action === 'test_kintone_connection') {
        $settings =
            normalize_settings(
                read_json_file(
                    SETTINGS_FILE
                )
            );

        $kintone =
            $settings['kintone'];

        if (!is_array($kintone)) {
            api_error(
                'kintone設定を読み込めませんでした。',
                500
            );
        }

        $domain =
            is_string($kintone['domain'] ?? null)
                ? $kintone['domain']
                : '';

        $appId =
            is_string($kintone['app_id'] ?? null)
                ? $kintone['app_id']
                : '';

        $loginName =
            is_string($kintone['login_name'] ?? null)
                ? $kintone['login_name']
                : '';

        $password =
            is_string($kintone['password'] ?? null)
                ? $kintone['password']
                : '';

        $proxyHostPort =
            is_string($kintone['proxy_host_port'] ?? null)
                ? $kintone['proxy_host_port']
                : '';

        if (
            $domain === ''
            || $loginName === ''
            || $password === ''
        ) {
            api_error(
                'kintone接続設定が未完了です。',
                400
            );
        }

        if ($appId !== '') {
            $query = http_build_query(
                ['id' => $appId],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

            $endpoint =
                '/k/v1/app.json?' . $query;
        } else {
            $query = http_build_query(
                ['limit' => 1],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

            $endpoint =
                '/k/v1/apps.json?' . $query;
        }

        $url =
            kintone_build_url(
                $domain,
                $endpoint
            );

        $headers = [
            make_cybozu_auth_header(
                $loginName,
                $password
            ),
            'Accept: application/json',
        ];

        $result =
            kintone_api_request(
                'GET',
                $url,
                $headers,
                null,
                [
                    'proxy_host_port' =>
                        $proxyHostPort,
                ]
            );

        if (!$result['success']) {
            $status =
                is_int($result['status'] ?? null)
                    ? $result['status']
                    : 502;

            if ($status < 400) {
                $status = 502;
            }

            $errors =
                is_array($result['errors'] ?? null)
                    ? $result['errors']
                    : [];

            api_error(
                'kintoneへの接続を確認できませんでした。設定内容と接続環境を確認してください。',
                $status >= 400 && $status <= 599
                    ? $status
                    : 502,
                $errors
            );
        }

        $settings['kintone']['verified'] = true;
        $settings['kintone']['configured'] = true;
        $settings['kintone']['updated_at'] =
            current_datetime();

        write_json_file(
            SETTINGS_FILE,
            $settings
        );

        api_success(
            'kintoneへの接続を確認しました。',
            [
                'settings' =>
                    settings_for_display(
                        $settings
                    ),
            ]
        );
    }

    /* -----------------------------------------------------
     * load_customers
     * ----------------------------------------------------- */
    if ($action === 'load_customers') {
        $customers =
            read_json_file(
                CUSTOMERS_FILE
            );

        api_success(
            '顧客一覧を読み込みました。',
            [
                'customers' =>
                    is_array($customers['customers'] ?? null)
                        ? $customers['customers']
                        : [],
            ]
        );
    }

    /* -----------------------------------------------------
     * load_surveys
     * ----------------------------------------------------- */
    if ($action === 'load_surveys') {
        $surveys =
            read_json_file(
                SURVEYS_FILE
            );

        api_success(
            'アンケート一覧を読み込みました。',
            [
                'surveys' =>
                    is_array($surveys['surveys'] ?? null)
                        ? $surveys['surveys']
                        : [],
            ]
        );
    }

    /*
     * 第2回以降で以下を実装する。
     *
     * - test_mail_settings
     * - refresh_customers
     * - load_survey
     * - save_survey
     * - delete_survey
     * - publish_survey
     * - close_survey
     * - prepare_survey_send
     * - send_survey
     * - load_mail_logs
     * - prepare_resend
     * - resend_survey
     * - load_public_survey
     * - save_response
     * - aggregate_responses
     */

    api_error(
        '指定された操作はまだ実装されていません。',
        501
    );
}

/* =========================================================
 * 画面用データ
 * ========================================================= */

$initialSettings = [
    'mail' => [
        'smtp_server' => '',
        'smtp_port' => 587,
        'security' => 'STARTTLS',
        'authentication' => false,
        'username' => '',
        'from_address' => '',
        'from_name' => '',
        'configured' => false,
        'verified' => false,
    ],
    'kintone' => [
        'domain' => '',
        'app_id' => '',
        'login_name' => '',
        'proxy_host_port' => '',
        'proxy_enabled' => false,
        'configured' => false,
        'verified' => false,
    ],
];

try {
    $loadedSettings =
        normalize_settings(
            read_json_file(
                SETTINGS_FILE
            )
        );

    $initialSettings =
        settings_for_display(
            $loadedSettings
        );
} catch (\Throwable) {
    /*
     * 画面初期表示では秘密情報を含まない初期値を使用する。
     * API処理側では安全にエラーを返す。
     */
}

/* =========================================================
 * HTML開始
 * ========================================================= */

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>アンケート業務運営</title>

    <style>
        :root {
            --color-primary: #2563eb;
            --color-primary-dark: #1d4ed8;
            --color-primary-light: #eff6ff;

            --color-success: #15803d;
            --color-success-light: #f0fdf4;

            --color-warning: #b45309;
            --color-warning-light: #fffbeb;

            --color-danger: #dc2626;
            --color-danger-light: #fef2f2;

            --color-text: #1f2937;
            --color-text-sub: #6b7280;

            --color-border: #d1d5db;
            --color-border-light: #e5e7eb;

            --color-background: #f3f4f6;
            --color-surface: #ffffff;

            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 12px;

            --shadow-sm:
                0 1px 2px rgba(0, 0, 0, 0.05);

            --shadow-md:
                0 4px 12px rgba(0, 0, 0, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Yu Gothic",
                "Hiragino Kaku Gothic ProN",
                Meiryo,
                sans-serif;

            color: var(--color-text);
            background: var(--color-background);
            line-height: 1.6;
        }

        button,
        input,
        textarea,
        select {
            font: inherit;
        }

        button {
            cursor: pointer;
        }

        button:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }

        .app-header {
            position: sticky;
            top: 0;
            z-index: 100;

            background: var(--color-surface);
            border-bottom: 1px solid var(--color-border-light);
            box-shadow: var(--shadow-sm);
        }

        .app-header-inner {
            max-width: 1440px;
            margin: 0 auto;
            min-height: 64px;
            padding: 0 24px;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .app-title {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            white-space: nowrap;
        }

        .global-nav {
            display: flex;
            align-items: center;
            gap: 4px;
            overflow-x: auto;
        }

        .global-nav-button {
            appearance: none;
            border: 0;
            background: transparent;
            color: var(--color-text-sub);

            padding: 10px 14px;
            border-radius: var(--radius-sm);

            white-space: nowrap;
            transition:
                background-color 0.15s ease,
                color 0.15s ease;
        }

        .global-nav-button:hover {
            background: var(--color-background);
            color: var(--color-text);
        }

        .global-nav-button.is-active {
            background: var(--color-primary-light);
            color: var(--color-primary);
            font-weight: 700;
        }

        .app-main {
            width: 100%;
            max-width: 1440px;
            margin: 0 auto;
            padding: 28px 24px 60px;
        }

        .page {
            display: none;
        }

        .page.is-active {
            display: block;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 24px;
        }

        .page-title {
            margin: 0;
            font-size: 26px;
            line-height: 1.3;
        }

        .page-description {
            margin: 8px 0 0;
            color: var(--color-text-sub);
        }

        .panel {
            background: var(--color-surface);
            border: 1px solid var(--color-border-light);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            padding: 24px;
        }

        .panel + .panel {
            margin-top: 20px;
        }

        .panel-title {
            margin: 0 0 18px;
            font-size: 18px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;

            min-height: 40px;
            padding: 8px 16px;

            border: 1px solid transparent;
            border-radius: var(--radius-sm);

            font-weight: 600;
            line-height: 1.4;

            transition:
                background-color 0.15s ease,
                border-color 0.15s ease,
                color 0.15s ease,
                opacity 0.15s ease;
        }

        .button-primary {
            background: var(--color-primary);
            color: #fff;
        }

        .button-primary:hover:not(:disabled) {
            background: var(--color-primary-dark);
        }

        .button-secondary {
            background: #fff;
            color: var(--color-text);
            border-color: var(--color-border);
        }

        .button-secondary:hover:not(:disabled) {
            background: var(--color-background);
        }

        .button-danger {
            background: var(--color-danger);
            color: #fff;
        }

        .button-danger:hover:not(:disabled) {
            background: #b91c1c;
        }

        .button-success {
            background: var(--color-success);
            color: #fff;
        }

        .button-success:hover:not(:disabled) {
            background: #166534;
        }

        .button-loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }

        .button-loading::after {
            content: "";
            position: absolute;

            width: 16px;
            height: 16px;

            border: 2px solid rgba(255, 255, 255, 0.45);
            border-top-color: #fff;

            border-radius: 50%;

            animation:
                button-spin 0.7s linear infinite;
        }

        .button-secondary.button-loading::after {
            border-color: rgba(37, 99, 235, 0.25);
            border-top-color: var(--color-primary);
        }

        @keyframes button-spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .status-message {
            display: none;
            padding: 12px 16px;
            margin-bottom: 20px;

            border-radius: var(--radius-sm);
            border: 1px solid transparent;
        }

        .status-message.is-visible {
            display: block;
        }

        .status-message.is-success {
            color: var(--color-success);
            background: var(--color-success-light);
            border-color: #bbf7d0;
        }

        .status-message.is-error {
            color: var(--color-danger);
            background: var(--color-danger-light);
            border-color: #fecaca;
        }

        .status-message.is-warning {
            color: var(--color-warning);
            background: var(--color-warning-light);
            border-color: #fde68a;
        }

        .form-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .form-field {
            min-width: 0;
        }

        .form-field-full {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .required-mark {
            color: var(--color-danger);
            margin-left: 4px;
        }

        .form-control {
            width: 100%;
            min-height: 42px;

            padding: 9px 12px;

            border: 1px solid var(--color-border);
            border-radius: var(--radius-sm);

            background: #fff;
            color: var(--color-text);

            outline: none;
        }

        .form-control:focus {
            border-color: var(--color-primary);
            box-shadow:
                0 0 0 3px
                rgba(37, 99, 235, 0.12);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-help {
            margin: 6px 0 0;
            color: var(--color-text-sub);
            font-size: 13px;
        }

        .form-error {
            margin: 6px 0 0;
            color: var(--color-danger);
            font-size: 13px;
        }

        .field-error {
            border-color: var(--color-danger);
            background: var(--color-danger-light);
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }

        .toolbar-left,
        .toolbar-right {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--color-border-light);
            border-radius: var(--radius-md);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        .data-table th,
        .data-table td {
            padding: 12px 14px;
            text-align: left;
            vertical-align: middle;
            border-bottom: 1px solid var(--color-border-light);
        }

        .data-table th {
            background: #f9fafb;
            font-weight: 700;
            white-space: nowrap;
        }

        .data-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .data-table tbody tr:hover {
            background: #fafafa;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 3px 9px;

            border-radius: 999px;

            font-size: 12px;
            font-weight: 700;
        }

        .badge-draft {
            color: #374151;
            background: #f3f4f6;
        }

        .badge-public {
            color: #166534;
            background: #dcfce7;
        }

        .badge-closed {
            color: #7f1d1d;
            background: #fee2e2;
        }

        .empty-state {
            padding: 48px 24px;
            text-align: center;
            color: var(--color-text-sub);
        }

        .empty-state-title {
            margin: 0 0 8px;
            color: var(--color-text);
            font-size: 18px;
            font-weight: 700;
        }

        .empty-state-description {
            margin: 0;
        }

        .settings-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 28px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .settings-status.is-configured {
            color: #166534;
            background: #dcfce7;
        }

        .settings-status.is-unconfigured {
            color: #92400e;
            background: #fef3c7;
        }

        .settings-status.is-verified {
            color: #1d4ed8;
            background: #dbeafe;
        }

        .settings-section {
            margin-top: 28px;
            padding-top: 28px;
            border-top: 1px solid var(--color-border-light);
        }

        .settings-section:first-child {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }

        .settings-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }

        .settings-section-title {
            margin: 0;
            font-size: 20px;
        }

        .card-grid {
            display: grid;
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .summary-card {
            padding: 18px;
            border: 1px solid var(--color-border-light);
            border-radius: var(--radius-md);
            background: #fff;
        }

        .summary-card-label {
            margin: 0;
            color: var(--color-text-sub);
            font-size: 13px;
        }

        .summary-card-value {
            margin: 6px 0 0;
            font-size: 28px;
            font-weight: 700;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1000;

            display: none;
            align-items: center;
            justify-content: center;

            padding: 20px;

            background:
                rgba(17, 24, 39, 0.48);
        }

        .modal-backdrop.is-open {
            display: flex;
        }

        .modal {
            width: min(760px, 100%);
            max-height: calc(100vh - 40px);
            overflow-y: auto;

            background: #fff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;

            padding: 18px 20px;
            border-bottom: 1px solid var(--color-border-light);
        }

        .modal-title {
            margin: 0;
            font-size: 18px;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;

            padding: 16px 20px;
            border-top: 1px solid var(--color-border-light);
        }

        .icon-button {
            width: 36px;
            height: 36px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border: 0;
            border-radius: 50%;

            background: transparent;
            color: var(--color-text-sub);
        }

        .icon-button:hover {
            background: var(--color-background);
            color: var(--color-text);
        }

        .screen-reader-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        @media (max-width: 960px) {
            .app-header-inner {
                align-items: flex-start;
                flex-direction: column;
                padding-top: 12px;
                padding-bottom: 12px;
            }

            .global-nav {
                width: 100%;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-field-full {
                grid-column: auto;
            }

            .card-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .app-main {
                padding:
                    20px 14px 40px;
            }

            .page-header {
                flex-direction: column;
            }

            .panel {
                padding: 18px;
            }

            .card-grid {
                grid-template-columns: 1fr;
            }

            .toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .toolbar-left,
            .toolbar-right {
                width: 100%;
            }

            .toolbar .button {
                flex: 1;
            }
        }
    </style>
</head>
<body>

    <!-- =====================================================
         アプリヘッダー
         ===================================================== -->

    <header class="app-header">
        <div class="app-header-inner">

            <h1 class="app-title">
                アンケート業務運営
            </h1>

            <nav
                class="global-nav"
                aria-label="メインメニュー"
            >
                <button
                    type="button"
                    class="global-nav-button is-active"
                    id="nav-dashboard"
                    data-page="dashboard"
                >
                    ダッシュボード
                </button>

                <button
                    type="button"
                    class="global-nav-button"
                    id="nav-surveys"
                    data-page="surveys"
                >
                    アンケート
                </button>

                <button
                    type="button"
                    class="global-nav-button"
                    id="nav-customers"
                    data-page="customers"
                >
                    顧客
                </button>

                <button
                    type="button"
                    class="global-nav-button"
                    id="nav-mail-logs"
                    data-page="mail-logs"
                >
                    送信履歴
                </button>

                <button
                    type="button"
                    class="global-nav-button"
                    id="nav-settings"
                    data-page="settings"
                >
                    設定
                </button>
            </nav>

        </div>
    </header>


    <!-- =====================================================
         メイン
         ===================================================== -->

    <main class="app-main">

        <!-- 共通メッセージ -->
        <div
            id="global-status"
            class="status-message"
            role="status"
            aria-live="polite"
        ></div>


        <!-- =================================================
             ダッシュボード
             ================================================= -->

        <section
            class="page is-active"
            id="page-dashboard"
            data-page-content="dashboard"
        >

            <div class="page-header">

                <div>
                    <h2 class="page-title">
                        ダッシュボード
                    </h2>

                    <p class="page-description">
                        アンケートの運営状況を確認できます。
                    </p>
                </div>

            </div>


            <div class="card-grid">

                <div class="summary-card">
                    <p class="summary-card-label">
                        アンケート数
                    </p>

                    <p
                        class="summary-card-value"
                        id="dashboard-survey-count"
                    >
                        0
                    </p>
                </div>


                <div class="summary-card">
                    <p class="summary-card-label">
                        公開中
                    </p>

                    <p
                        class="summary-card-value"
                        id="dashboard-public-count"
                    >
                        0
                    </p>
                </div>


                <div class="summary-card">
                    <p class="summary-card-label">
                        顧客数
                    </p>

                    <p
                        class="summary-card-value"
                        id="dashboard-customer-count"
                    >
                        0
                    </p>
                </div>

            </div>


            <div class="panel">

                <h3 class="panel-title">
                    最近のアンケート
                </h3>

                <div
                    class="table-wrap"
                    id="dashboard-survey-table-wrap"
                >

                    <table class="data-table">

                        <thead>
                            <tr>
                                <th>アンケート名</th>
                                <th>状態</th>
                                <th>回答数</th>
                                <th>更新日時</th>
                            </tr>
                        </thead>

                        <tbody
                            id="dashboard-survey-table-body"
                        ></tbody>

                    </table>

                </div>

                <div
                    class="empty-state"
                    id="dashboard-survey-empty"
                    hidden
                >
                    <p class="empty-state-title">
                        アンケートはありません
                    </p>

                    <p class="empty-state-description">
                        「アンケート」から新しいアンケートを作成できます。
                    </p>
                </div>

            </div>

        </section>


        <!-- =================================================
             アンケート一覧
             ================================================= -->

        <section
            class="page"
            id="page-surveys"
            data-page-content="surveys"
        >

            <div class="page-header">

                <div>
                    <h2 class="page-title">
                        アンケート
                    </h2>

                    <p class="page-description">
                        アンケートの作成、公開、回答状況の確認を行います。
                    </p>
                </div>

                <div>
                    <button
                        type="button"
                        class="button button-primary"
                        id="btn-create-survey"
                    >
                        新しいアンケートを作成
                    </button>
                </div>

            </div>


            <div class="panel">

                <div class="toolbar">

                    <div class="toolbar-left">

                        <label
                            for="survey-status-filter"
                            class="screen-reader-only"
                        >
                            状態で絞り込む
                        </label>

                        <select
                            id="survey-status-filter"
                            class="form-control"
                        >
                            <option value="all">
                                すべて
                            </option>

                            <option value="draft">
                                下書き
                            </option>

                            <option value="public">
                                公開中
                            </option>

                            <option value="closed">
                                終了
                            </option>
                        </select>

                    </div>

                    <div class="toolbar-right">

                        <button
                            type="button"
                            class="button button-secondary"
                            id="btn-refresh-surveys"
                        >
                            再読み込み
                        </button>

                    </div>

                </div>


                <div
                    class="table-wrap"
                    id="survey-table-wrap"
                >

                    <table class="data-table">

                        <thead>
                            <tr>
                                <th>アンケート名</th>
                                <th>状態</th>
                                <th>質問数</th>
                                <th>回答数</th>
                                <th>作成日時</th>
                                <th>更新日時</th>
                                <th>操作</th>
                            </tr>
                        </thead>

                        <tbody id="survey-table-body"></tbody>

                    </table>

                </div>


                <div
                    class="empty-state"
                    id="survey-empty"
                    hidden
                >
                    <p class="empty-state-title">
                        アンケートはありません
                    </p>

                    <p class="empty-state-description">
                        「新しいアンケートを作成」から作成できます。
                    </p>
                </div>

            </div>

        </section>


        <!-- =================================================
             アンケート編集
             ================================================= -->

        <section
            class="page"
            id="page-survey-editor"
            data-page-content="survey-editor"
        >

            <div class="page-header">

                <div>

                    <h2
                        class="page-title"
                        id="survey-editor-title"
                    >
                        新しいアンケート
                    </h2>

                    <p class="page-description">
                        アンケートの内容を設定します。
                    </p>

                </div>

                <div class="toolbar-right">

                    <button
                        type="button"
                        class="button button-secondary"
                        id="btn-cancel-survey-editor"
                    >
                        戻る
                    </button>

                    <button
                        type="button"
                        class="button button-primary"
                        id="btn-save-survey"
                    >
                        保存
                    </button>

                </div>

            </div>


            <div class="panel">

                <div class="form-grid">

                    <div class="form-field form-field-full">

                        <label
                            class="form-label"
                            for="survey-name"
                        >
                            アンケート名
                            <span class="required-mark">
                                *
                            </span>
                        </label>

                        <input
                            type="text"
                            id="survey-name"
                            class="form-control"
                            maxlength="200"
                            autocomplete="off"
                        >

                        <p
                            class="form-error"
                            id="survey-name-error"
                            hidden
                        ></p>

                    </div>


                    <div class="form-field form-field-full">

                        <label
                            class="form-label"
                            for="survey-description"
                        >
                            説明
                        </label>

                        <textarea
                            id="survey-description"
                            class="form-control"
                            maxlength="5000"
                        ></textarea>

                        <p class="form-help">
                            回答者に表示するアンケートの説明です。
                        </p>

                    </div>

                </div>

            </div>


            <div class="panel">

                <div class="settings-section-header">

                    <div>
                        <h3 class="settings-section-title">
                            質問
                        </h3>

                        <p class="form-help">
                            回答してもらう質問を登録します。
                        </p>
                    </div>

                    <button
                        type="button"
                        class="button button-secondary"
                        id="btn-add-question"
                    >
                        質問を追加
                    </button>

                </div>


                <div
                    id="question-list"
                ></div>


                <div
                    class="empty-state"
                    id="question-empty"
                >
                    <p class="empty-state-title">
                        質問がありません
                    </p>

                    <p class="empty-state-description">
                        「質問を追加」から質問を登録してください。
                    </p>
                </div>

            </div>


            <div
                class="panel"
                id="survey-editor-actions"
            >

                <div class="toolbar">

                    <div class="toolbar-left">

                        <button
                            type="button"
                            class="button button-danger"
                            id="btn-delete-survey"
                            hidden
                        >
                            削除
                        </button>

                    </div>

                    <div class="toolbar-right">

                        <button
                            type="button"
                            class="button button-secondary"
                            id="btn-preview-survey"
                        >
                            プレビュー
                        </button>

                        <button
                            type="button"
                            class="button button-success"
                            id="btn-publish-survey"
                            hidden
                        >
                            公開する
                        </button>

                        <button
                            type="button"
                            class="button button-danger"
                            id="btn-close-survey"
                            hidden
                        >
                            終了する
                        </button>

                    </div>

                </div>

            </div>

        </section>


        <!-- =================================================
             顧客一覧
             ================================================= -->

        <section
            class="page"
            id="page-customers"
            data-page-content="customers"
        >

            <div class="page-header">

                <div>

                    <h2 class="page-title">
                        顧客
                    </h2>

                    <p class="page-description">
                        kintoneから取得した顧客情報を確認できます。
                    </p>

                </div>

                <div>

                    <button
                        type="button"
                        class="button button-primary"
                        id="btn-refresh-customers"
                    >
                        kintoneから更新
                    </button>

                </div>

            </div>


            <div class="panel">

                <div class="toolbar">

                    <div class="toolbar-left">

                        <label
                            for="customer-search"
                            class="screen-reader-only"
                        >
                            顧客を検索
                        </label>

                        <input
                            type="search"
                            id="customer-search"
                            class="form-control"
                            placeholder="顧客名・メールアドレスなどで検索"
                            autocomplete="off"
                        >

                    </div>

                </div>


                <div
                    class="table-wrap"
                    id="customer-table-wrap"
                >

                    <table class="data-table">

                        <thead>
                            <tr>
                                <th>顧客ID</th>
                                <th>顧客名</th>
                                <th>メールアドレス</th>
                                <th>取得日時</th>
                            </tr>
                        </thead>

                        <tbody
                            id="customer-table-body"
                        ></tbody>

                    </table>

                </div>


                <div
                    class="empty-state"
                    id="customer-empty"
                    hidden
                >
                    <p class="empty-state-title">
                        顧客情報がありません
                    </p>

                    <p class="empty-state-description">
                        kintone設定を確認し、「kintoneから更新」を実行してください。
                    </p>
                </div>

            </div>

        </section>


        <!-- =================================================
             送信履歴
             ================================================= -->

        <section
            class="page"
            id="page-mail-logs"
            data-page-content="mail-logs"
        >

            <div class="page-header">

                <div>

                    <h2 class="page-title">
                        送信履歴
                    </h2>

                    <p class="page-description">
                        アンケートメールの送信状況を確認できます。
                    </p>

                </div>

                <div>

                    <button
                        type="button"
                        class="button button-secondary"
                        id="btn-refresh-mail-logs"
                    >
                        再読み込み
                    </button>

                </div>

            </div>


            <div class="panel">

                <div class="toolbar">

                    <div class="toolbar-left">

                        <label
                            for="mail-log-survey-filter"
                            class="screen-reader-only"
                        >
                            アンケートで絞り込む
                        </label>

                        <select
                            id="mail-log-survey-filter"
                            class="form-control"
                        >
                            <option value="">
                                すべてのアンケート
                            </option>
                        </select>


                        <label
                            for="mail-log-status-filter"
                            class="screen-reader-only"
                        >
                            送信状態で絞り込む
                        </label>

                        <select
                            id="mail-log-status-filter"
                            class="form-control"
                        >
                            <option value="all">
                                すべての状態
                            </option>

                            <option value="sent">
                                送信済み
                            </option>

                            <option value="failed">
                                送信失敗
                            </option>

                            <option value="pending">
                                送信待ち
                            </option>
                        </select>

                    </div>

                </div>


                <div class="table-wrap">

                    <table class="data-table">

                        <thead>
                            <tr>
                                <th>日時</th>
                                <th>アンケート</th>
                                <th>顧客</th>
                                <th>メールアドレス</th>
                                <th>状態</th>
                                <th>操作</th>
                            </tr>
                        </thead>

                        <tbody
                            id="mail-log-table-body"
                        ></tbody>

                    </table>

                </div>


                <div
                    class="empty-state"
                    id="mail-log-empty"
                    hidden
                >
                    <p class="empty-state-title">
                        送信履歴がありません
                    </p>

                    <p class="empty-state-description">
                        アンケートメールを送信すると、ここに履歴が表示されます。
                    </p>
                </div>

            </div>

        </section>


        <!-- =================================================
             設定
             ================================================= -->

        <section
            class="page"
            id="page-settings"
            data-page-content="settings"
        >

            <div class="page-header">

                <div>

                    <h2 class="page-title">
                        設定
                    </h2>

                    <p class="page-description">
                        アンケート運営に使用する接続情報を設定します。
                    </p>

                </div>

            </div>


            <!-- ---------------------------------------------
                 メール設定
                 --------------------------------------------- -->

            <div class="panel">

                <div class="settings-section">

                    <div class="settings-section-header">

                        <div>
                            <h3 class="settings-section-title">
                                メール送信設定
                            </h3>

                            <p class="form-help">
                                アンケート案内メールを送信するための設定です。
                            </p>
                        </div>

                        <div>

                            <span
                                id="mail-settings-status"
                                class="settings-status is-unconfigured"
                            >
                                未設定
                            </span>

                        </div>

                    </div>


                    <form
                        id="mail-settings-form"
                        novalidate
                    >

                        <div class="form-grid">

                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="mail-smtp-server"
                                >
                                    SMTPサーバ
                                    <span class="required-mark">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    id="mail-smtp-server"
                                    name="smtp_server"
                                    class="form-control"
                                    maxlength="255"
                                    autocomplete="off"
                                >

                                <p
                                    class="form-error"
                                    id="mail-smtp-server-error"
                                    hidden
                                ></p>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="mail-smtp-port"
                                >
                                    SMTPポート
                                    <span class="required-mark">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="number"
                                    id="mail-smtp-port"
                                    name="smtp_port"
                                    class="form-control"
                                    min="1"
                                    max="65535"
                                    value="587"
                                >

                                <p
                                    class="form-error"
                                    id="mail-smtp-port-error"
                                    hidden
                                ></p>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="mail-security"
                                >
                                    接続方式
                                </label>

                                <select
                                    id="mail-security"
                                    name="security"
                                    class="form-control"
                                >
                                    <option value="NONE">
                                        暗号化なし
                                    </option>

                                    <option
                                        value="STARTTLS"
                                        selected
                                    >
                                        STARTTLS
                                    </option>

                                    <option value="SSL_TLS">
                                        SSL/TLS
                                    </option>
                                </select>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="mail-authentication"
                                >
                                    SMTP認証
                                </label>

                                <select
                                    id="mail-authentication"
                                    name="authentication"
                                    class="form-control"
                                >
                                    <option value="false">
                                        使用しない
                                    </option>

                                    <option value="true">
                                        使用する
                                    </option>
                                </select>

                            </div>


                            <div
                                class="form-field"
                                id="mail-username-field"
                            >

                                <label
                                    class="form-label"
                                    for="mail-username"
                                >
                                    認証ユーザー名
                                </label>

                                <input
                                    type="text"
                                    id="mail-username"
                                    name="username"
                                    class="form-control"
                                    maxlength="255"
                                    autocomplete="username"
                                >

                                <p
                                    class="form-error"
                                    id="mail-username-error"
                                    hidden
                                ></p>

                            </div>


                            <div
                                class="form-field"
                                id="mail-password-field"
                            >

                                <label
                                    class="form-label"
                                    for="mail-password"
                                >
                                    認証パスワード
                                </label>

                                <input
                                    type="password"
                                    id="mail-password"
                                    name="password"
                                    class="form-control"
                                    autocomplete="new-password"
                                >

                                <p class="form-help">
                                    変更しない場合は空欄のままにしてください。
                                </p>

                                <p
                                    class="form-error"
                                    id="mail-password-error"
                                    hidden
                                ></p>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="mail-from-address"
                                >
                                    送信元メールアドレス
                                    <span class="required-mark">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="email"
                                    id="mail-from-address"
                                    name="from_address"
                                    class="form-control"
                                    maxlength="320"
                                    autocomplete="email"
                                >

                                <p
                                    class="form-error"
                                    id="mail-from-address-error"
                                    hidden
                                ></p>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="mail-from-name"
                                >
                                    送信元名
                                </label>

                                <input
                                    type="text"
                                    id="mail-from-name"
                                    name="from_name"
                                    class="form-control"
                                    maxlength="255"
                                >

                                <p
                                    class="form-error"
                                    id="mail-from-name-error"
                                    hidden
                                ></p>

                            </div>

                        </div>


                        <div class="toolbar">

                            <div class="toolbar-left">
                            </div>

                            <div class="toolbar-right">

                                <button
                                    type="button"
                                    class="button button-secondary"
                                    id="btn-test-mail-settings"
                                >
                                    接続確認
                                </button>

                                <button
                                    type="submit"
                                    class="button button-primary"
                                    id="btn-save-mail-settings"
                                >
                                    メール設定を保存
                                </button>

                            </div>

                        </div>

                    </form>

                </div>


                <!-- -----------------------------------------
                     kintone設定
                     ----------------------------------------- -->

                <div class="settings-section">

                    <div class="settings-section-header">

                        <div>
                            <h3 class="settings-section-title">
                                kintone設定
                            </h3>

                            <p class="form-help">
                                顧客情報の取得に使用するkintoneを設定します。
                            </p>
                        </div>

                        <div>

                            <span
                                id="kintone-settings-status"
                                class="settings-status is-unconfigured"
                            >
                                未設定
                            </span>

                        </div>

                    </div>


                    <form
                        id="kintone-settings-form"
                        novalidate
                    >

                        <div class="form-grid">

                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="kintone-domain"
                                >
                                    kintone利用先
                                    <span class="required-mark">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    id="kintone-domain"
                                    name="domain"
                                    class="form-control"
                                    maxlength="255"
                                    placeholder="example"
                                    autocomplete="off"
                                >

                                <p class="form-help">
                                    「example」または「https://example.cybozu.com」のいずれでも入力できます。
                                </p>

                                <p
                                    class="form-error"
                                    id="kintone-domain-error"
                                    hidden
                                ></p>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="kintone-app-id"
                                >
                                    顧客アプリID
                                </label>

                                <input
                                    type="text"
                                    id="kintone-app-id"
                                    name="app_id"
                                    class="form-control"
                                    inputmode="numeric"
                                    maxlength="20"
                                    autocomplete="off"
                                >

                                <p class="form-help">
                                    接続確認時に指定したアプリを確認します。
                                </p>

                                <p
                                    class="form-error"
                                    id="kintone-app-id-error"
                                    hidden
                                ></p>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="kintone-login-name"
                                >
                                    ログイン名
                                    <span class="required-mark">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="text"
                                    id="kintone-login-name"
                                    name="login_name"
                                    class="form-control"
                                    maxlength="255"
                                    autocomplete="username"
                                >

                                <p
                                    class="form-error"
                                    id="kintone-login-name-error"
                                    hidden
                                ></p>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="kintone-password"
                                >
                                    ログインパスワード
                                    <span class="required-mark">
                                        *
                                    </span>
                                </label>

                                <input
                                    type="password"
                                    id="kintone-password"
                                    name="password"
                                    class="form-control"
                                    autocomplete="new-password"
                                >

                                <p class="form-help">
                                    変更しない場合は空欄のままにしてください。
                                </p>

                                <p
                                    class="form-error"
                                    id="kintone-password-error"
                                    hidden
                                ></p>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="kintone-proxy-enabled"
                                >
                                    プロキシ
                                </label>

                                <select
                                    id="kintone-proxy-enabled"
                                    name="proxy_enabled"
                                    class="form-control"
                                >
                                    <option value="false">
                                        使用しない
                                    </option>

                                    <option value="true">
                                        使用する
                                    </option>
                                </select>

                            </div>


                            <div class="form-field">

                                <label
                                    class="form-label"
                                    for="kintone-proxy-host-port"
                                >
                                    プロキシ
                                </label>

                                <input
                                    type="text"
                                    id="kintone-proxy-host-port"
                                    name="proxy_host_port"
                                    class="form-control"
                                    maxlength="255"
                                    placeholder="proxy.example.local:8080"
                                    autocomplete="off"
                                >

                                <p class="form-help">
                                    使用する場合は「ホスト名:ポート番号」で入力してください。
                                </p>

                                <p
                                    class="form-error"
                                    id="kintone-proxy-host-port-error"
                                    hidden
                                ></p>

                            </div>

                        </div>


                        <div class="toolbar">

                            <div class="toolbar-left">
                            </div>

                            <div class="toolbar-right">

                                <button
                                    type="button"
                                    class="button button-secondary"
                                    id="btn-test-kintone"
                                >
                                    接続確認
                                </button>

                                <button
                                    type="submit"
                                    class="button button-primary"
                                    id="btn-save-kintone"
                                >
                                    kintone設定を保存
                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

        </section>

    </main>


    <!-- =====================================================
         質問編集テンプレート
         ===================================================== -->

    <template id="question-template">

        <div
            class="panel question-item"
            data-question-index=""
        >

            <div class="settings-section-header">

                <div>
                    <h4
                        class="panel-title question-number"
                    >
                        質問
                    </h4>
                </div>

                <div>

                    <button
                        type="button"
                        class="button button-secondary btn-remove-question"
                    >
                        質問を削除
                    </button>

                </div>

            </div>


            <div class="form-grid">

                <div class="form-field form-field-full">

                    <label class="form-label">
                        質問文
                        <span class="required-mark">
                            *
                        </span>
                    </label>

                    <textarea
                        class="form-control question-text"
                        maxlength="2000"
                    ></textarea>

                    <p class="form-error question-text-error" hidden></p>

                </div>


                <div class="form-field">

                    <label class="form-label">
                        回答形式
                    </label>

                    <select
                        class="form-control question-type"
                    >

                        <option value="text">
                            自由記述
                        </option>

                        <option value="single">
                            単一選択
                        </option>

                        <option value="multiple">
                            複数選択
                        </option>

                        <option value="rating">
                            評価
                        </option>

                    </select>

                </div>


                <div class="form-field">

                    <label class="form-label">
                        必須回答
                    </label>

                    <select
                        class="form-control question-required"
                    >

                        <option value="false">
                            任意
                        </option>

                        <option value="true">
                            必須
                        </option>

                    </select>

                </div>


                <div
                    class="form-field form-field-full question-options-field"
                    hidden
                >

                    <label class="form-label">
                        選択肢
                    </label>

                    <textarea
                        class="form-control question-options"
                        placeholder="1行に1つずつ入力してください。"
                    ></textarea>

                    <p class="form-help">
                        選択肢を1行に1つずつ入力してください。
                    </p>

                    <p
                        class="form-error question-options-error"
                        hidden
                    ></p>

                </div>

            </div>

        </div>

    </template>


    <!-- =====================================================
         プレビューモーダル
         ===================================================== -->

    <div
        class="modal-backdrop"
        id="survey-preview-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="survey-preview-title"
        hidden
    >

        <div class="modal">

            <div class="modal-header">

                <h2
                    class="modal-title"
                    id="survey-preview-title"
                >
                    アンケートプレビュー
                </h2>

                <button
                    type="button"
                    class="icon-button"
                    id="btn-close-survey-preview"
                    aria-label="閉じる"
                >
                    ×
                </button>

            </div>


            <div
                class="modal-body"
                id="survey-preview-body"
            >
            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="button button-secondary"
                    id="btn-close-survey-preview-footer"
                >
                    閉じる
                </button>

            </div>

        </div>

    </div>


    <!-- =====================================================
         削除確認モーダル
         ===================================================== -->

    <div
        class="modal-backdrop"
        id="confirm-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-modal-title"
        hidden
    >

        <div class="modal">

            <div class="modal-header">

                <h2
                    class="modal-title"
                    id="confirm-modal-title"
                >
                    確認
                </h2>

                <button
                    type="button"
                    class="icon-button"
                    id="btn-close-confirm"
                    aria-label="閉じる"
                >
                    ×
                </button>

            </div>


            <div
                class="modal-body"
                id="confirm-modal-body"
            >
            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="button button-secondary"
                    id="btn-cancel-confirm"
                >
                    キャンセル
                </button>

                <button
                    type="button"
                    class="button button-danger"
                    id="btn-execute-confirm"
                >
                    実行する
                </button>

            </div>

        </div>

    </div>


    <!-- =====================================================
         送信対象選択モーダル
         ===================================================== -->

    <div
        class="modal-backdrop"
        id="send-survey-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="send-survey-title"
        hidden
    >

        <div class="modal">

            <div class="modal-header">

                <h2
                    class="modal-title"
                    id="send-survey-title"
                >
                    アンケート送信
                </h2>

                <button
                    type="button"
                    class="icon-button"
                    id="btn-close-send-survey"
                    aria-label="閉じる"
                >
                    ×
                </button>

            </div>


            <div class="modal-body">

                <div
                    class="status-message"
                    id="send-survey-status"
                ></div>


                <p>
                    送信対象の顧客を選択してください。
                </p>


                <div
                    class="table-wrap"
                    id="send-customer-table-wrap"
                >

                    <table class="data-table">

                        <thead>
                            <tr>

                                <th>
                                    <label>
                                        <input
                                            type="checkbox"
                                            id="send-select-all-customers"
                                        >
                                        <span class="screen-reader-only">
                                            すべて選択
                                        </span>
                                    </label>
                                </th>

                                <th>
                                    顧客名
                                </th>

                                <th>
                                    メールアドレス
                                </th>

                                <th>
                                    状態
                                </th>

                            </tr>
                        </thead>

                        <tbody
                            id="send-customer-table-body"
                        ></tbody>

                    </table>

                </div>


                <div
                    class="empty-state"
                    id="send-customer-empty"
                    hidden
                >
                    <p class="empty-state-title">
                        送信可能な顧客がありません
                    </p>

                    <p class="empty-state-description">
                        顧客情報を確認してください。
                    </p>
                </div>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="button button-secondary"
                    id="btn-cancel-send-survey"
                >
                    キャンセル
                </button>

                <button
                    type="button"
                    class="button button-primary"
                    id="btn-execute-send-survey"
                >
                    選択した顧客へ送信
                </button>

            </div>

        </div>

    </div>


    <!-- =====================================================
         初期状態
         ===================================================== -->

    <script type="application/json" id="initial-state">
<?php
echo json_encode(
    [
        'settings' => $initialSettings,
        'csrf_token' => get_csrf_token(),
    ],
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_INVALID_UTF8_SUBSTITUTE
);
?>
    </script>


    <!-- =====================================================
         JavaScript
         =====================================================

         第3回で実装します。

         重要：
         - すべてDOMContentLoaded内
         - イベント対象のNullチェック
         - fetch開始前にdisabled=true
         - loadingクラスを先に付与
         - API URLは固定ホストを使用せず、
           現在のindex.phpを基準に生成
         - CSRFトークン送信
         - innerHTMLを原則使用しない
    -->

<script>
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    /*
     * =========================================================
     * アプリケーション共通状態
     * =========================================================
     */

    const state = {
        currentPage: 'dashboard',
        currentSurveyId: null,
        surveys: [],
        customers: [],
        mailLogs: [],
        settings: {},
        confirmAction: null
    };


    /*
     * =========================================================
     * DOM取得
     * =========================================================
     */

    const initialStateElement =
        document.getElementById('initial-state');

    let initialState = {};

    if (initialStateElement) {
        try {
            initialState = JSON.parse(
                initialStateElement.textContent || '{}'
            );
        } catch (error) {
            initialState = {};
        }
    }

    state.settings = initialState?.settings || {};


    /*
     * =========================================================
     * 共通関数
     * =========================================================
     */

    const getElement = (id) => {
        return document.getElementById(id);
    };


    const setText = (element, value) => {
        if (!element) {
            return;
        }

        element.textContent =
            value === null || value === undefined
                ? ''
                : String(value);
    };


    const showElement = (element) => {
        if (!element) {
            return;
        }

        element.hidden = false;
    };


    const hideElement = (element) => {
        if (!element) {
            return;
        }

        element.hidden = true;
    };


    const showStatus = (message, type = 'info') => {
        const element = getElement('global-status');

        if (!element) {
            return;
        }

        element.textContent = message || '';
        element.className = 'status-message';

        if (type) {
            element.classList.add(`is-${type}`);
        }

        if (message) {
            showElement(element);
        } else {
            hideElement(element);
        }
    };


    const setLoading = (button, loading) => {
        if (!button) {
            return;
        }

        if (loading) {
            button.disabled = true;
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
        } else {
            button.disabled = false;
            button.classList.remove('is-loading');
            button.removeAttribute('aria-busy');
        }
    };


    const getCsrfToken = () => {
        return String(
            initialState?.csrf_token || ''
        );
    };


    /*
     * =========================================================
     * API通信
     *
     * 重要：
     * 外部ホストを直接指定しない。
     * 現在表示している index.php を通信先とする。
     *
     * これにより、
     *
     *     origin: null
     *     ↓
     *     https://n11-1041/...
     *
     * のようなブラウザ側クロスオリジン通信を発生させない。
     * =========================================================
     */

    const api = async (
        action,
        method = 'POST',
        data = {},
        button = null
    ) => {

        if (button) {
            /*
             * 非同期通信開始より前に必ず無効化。
             */
            setLoading(button, true);
        }

        try {

            const currentUrl =
                new URL(
                    window.location.href
                );

            /*
             * クエリを安全に作り直す。
             * _t等の既存パラメータは通信APIには不要なので除去。
             */
            currentUrl.search = '';

            currentUrl.searchParams.set(
                'action',
                action
            );

            const requestOptions = {
                method: method,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            };


            /*
             * CSRFトークン。
             */
            const csrfToken = getCsrfToken();

            if (csrfToken) {
                requestOptions.headers['X-CSRF-Token'] =
                    csrfToken;
            }


            if (
                method.toUpperCase() !== 'GET'
                && method.toUpperCase() !== 'HEAD'
            ) {

                requestOptions.headers['Content-Type'] =
                    'application/json; charset=utf-8';

                requestOptions.body =
                    JSON.stringify(data || {});
            }


            const response =
                await fetch(
                    currentUrl.toString(),
                    requestOptions
                );


            /*
             * JSON以外が返った場合にも、
             * JSON.parseでJavaScriptエラーにしない。
             */
            const responseText =
                await response.text();

            let result = {};

            if (responseText) {
                try {
                    result = JSON.parse(responseText);
                } catch (error) {
                    throw new Error(
                        'サーバーから正しい応答を取得できませんでした。'
                    );
                }
            }


            if (!response.ok) {

                const message =
                    result?.message
                    || `サーバーエラーが発生しました。（HTTP ${response.status}）`;

                throw new Error(message);
            }


            if (
                result
                && result.success === false
            ) {
                throw new Error(
                    result.message
                    || '処理に失敗しました。'
                );
            }


            return result;

        } catch (error) {

            /*
             * ネットワークエラー等はここで統一。
             */
            const message =
                error instanceof Error
                    ? error.message
                    : 'サーバーとの通信に失敗しました。';

            throw new Error(message);

        } finally {

            if (button) {
                setLoading(button, false);
            }
        }
    };


    /*
     * =========================================================
     * ページ切り替え
     * =========================================================
     */

    const showPage = (pageName) => {

        const pages =
            document.querySelectorAll(
                '[data-page-content]'
            );

        pages.forEach((page) => {

            if (!page) {
                return;
            }

            const isTarget =
                page.dataset.pageContent === pageName;

            page.classList.toggle(
                'is-active',
                isTarget
            );

            page.hidden = !isTarget;
        });


        const navigationButtons =
            document.querySelectorAll(
                '.global-nav-button'
            );

        navigationButtons.forEach((button) => {

            if (!button) {
                return;
            }

            const isTarget =
                button.dataset.page === pageName;

            button.classList.toggle(
                'is-active',
                isTarget
            );
        });


        state.currentPage = pageName;


        if (pageName === 'dashboard') {
            loadDashboard();
        }

        if (pageName === 'surveys') {
            loadSurveys();
        }

        if (pageName === 'customers') {
            loadCustomers();
        }

        if (pageName === 'mail-logs') {
            loadMailLogs();
        }

        if (pageName === 'settings') {
            loadSettings();
        }
    };


    /*
     * =========================================================
     * ナビゲーション
     * =========================================================
     */

    const navigationButtons =
        document.querySelectorAll(
            '.global-nav-button'
        );

    navigationButtons.forEach((button) => {

        if (!button) {
            return;
        }

        button.addEventListener('click', () => {

            const pageName =
                button.dataset.page || '';

            if (!pageName) {
                return;
            }

            showPage(pageName);
        });
    });


    /*
     * =========================================================
     * ダッシュボード
     * =========================================================
     */

    const loadDashboard = async () => {

        try {

            const result =
                await api(
                    'get_dashboard',
                    'GET'
                );

            const data =
                result?.data || {};

            const surveys =
                Array.isArray(data?.surveys)
                    ? data.surveys
                    : [];

            const customerCount =
                Number(data?.customer_count || 0);

            const publicCount =
                Number(data?.public_count || 0);


            setText(
                getElement('dashboard-survey-count'),
                surveys.length
            );

            setText(
                getElement('dashboard-public-count'),
                publicCount
            );

            setText(
                getElement('dashboard-customer-count'),
                customerCount
            );


            state.surveys = surveys;

            renderDashboardSurveys(surveys);

        } catch (error) {

            /*
             * 初回表示時にAPIがまだ存在しない場合でも
             * 画面自体は壊さない。
             */
            renderDashboardSurveys([]);

            if (state.currentPage === 'dashboard') {
                showStatus(
                    error.message,
                    'error'
                );
            }
        }
    };


    const renderDashboardSurveys = (surveys) => {

        const body =
            getElement(
                'dashboard-survey-table-body'
            );

        const empty =
            getElement(
                'dashboard-survey-empty'
            );

        if (!body) {
            return;
        }

        body.textContent = '';


        if (!Array.isArray(surveys) || surveys.length === 0) {

            if (empty) {
                showElement(empty);
            }

            return;
        }


        if (empty) {
            hideElement(empty);
        }


        surveys.slice(0, 10).forEach((survey) => {

            if (!survey) {
                return;
            }

            const row =
                document.createElement('tr');

            const nameCell =
                document.createElement('td');

            const statusCell =
                document.createElement('td');

            const countCell =
                document.createElement('td');

            const updatedCell =
                document.createElement('td');


            setText(
                nameCell,
                survey?.name || ''
            );

            setText(
                statusCell,
                getSurveyStatusLabel(
                    survey?.status
                )
            );

            setText(
                countCell,
                Number(
                    survey?.response_count || 0
                )
            );

            setText(
                updatedCell,
                formatDateTime(
                    survey?.updated_at
                )
            );


            row.appendChild(nameCell);
            row.appendChild(statusCell);
            row.appendChild(countCell);
            row.appendChild(updatedCell);

            body.appendChild(row);
        });
    };


    /*
     * =========================================================
     * アンケート
     * =========================================================
     */

    const loadSurveys = async () => {

        const button =
            getElement('btn-refresh-surveys');

        try {

            const result =
                await api(
                    'get_surveys',
                    'GET',
                    {},
                    button
                );

            const surveys =
                Array.isArray(result?.data?.surveys)
                    ? result.data.surveys
                    : [];

            state.surveys = surveys;

            renderSurveys();

        } catch (error) {

            renderSurveys();

            showStatus(
                error.message,
                'error'
            );
        }
    };


    const renderSurveys = () => {

        const body =
            getElement('survey-table-body');

        const empty =
            getElement('survey-empty');

        if (!body) {
            return;
        }

        body.textContent = '';


        let surveys =
            Array.isArray(state.surveys)
                ? [...state.surveys]
                : [];


        const filter =
            getElement('survey-status-filter');

        const selectedStatus =
            filter?.value || 'all';


        if (selectedStatus !== 'all') {

            surveys =
                surveys.filter((survey) => {

                    return (
                        survey?.status
                        === selectedStatus
                    );
                });
        }


        if (surveys.length === 0) {

            if (empty) {
                showElement(empty);
            }

            return;
        }


        if (empty) {
            hideElement(empty);
        }


        surveys.forEach((survey) => {

            if (!survey) {
                return;
            }

            const row =
                document.createElement('tr');


            const nameCell =
                document.createElement('td');

            const statusCell =
                document.createElement('td');

            const questionCell =
                document.createElement('td');

            const responseCell =
                document.createElement('td');

            const createdCell =
                document.createElement('td');

            const updatedCell =
                document.createElement('td');

            const actionCell =
                document.createElement('td');


            setText(
                nameCell,
                survey?.name || ''
            );

            setText(
                statusCell,
                getSurveyStatusLabel(
                    survey?.status
                )
            );

            setText(
                questionCell,
                Number(
                    survey?.question_count || 0
                )
            );

            setText(
                responseCell,
                Number(
                    survey?.response_count || 0
                )
            );

            setText(
                createdCell,
                formatDateTime(
                    survey?.created_at
                )
            );

            setText(
                updatedCell,
                formatDateTime(
                    survey?.updated_at
                )
            );


            /*
             * 編集ボタン
             */
            const editButton =
                document.createElement('button');

            editButton.type = 'button';
            editButton.className =
                'button button-secondary button-small';

            editButton.textContent =
                '編集';

            editButton.addEventListener(
                'click',
                () => {
                    openSurveyEditor(
                        survey?.id || null
                    );
                }
            );


            actionCell.appendChild(
                editButton
            );


            /*
             * 公開中なら送信ボタンを表示。
             */
            if (survey?.status === 'public') {

                const sendButton =
                    document.createElement('button');

                sendButton.type = 'button';
                sendButton.className =
                    'button button-primary button-small';

                sendButton.textContent =
                    '送信';

                sendButton.addEventListener(
                    'click',
                    () => {
                        openSendSurveyModal(
                            survey?.id || null
                        );
                    }
                );

                actionCell.appendChild(
                    sendButton
                );
            }


            row.appendChild(nameCell);
            row.appendChild(statusCell);
            row.appendChild(questionCell);
            row.appendChild(responseCell);
            row.appendChild(createdCell);
            row.appendChild(updatedCell);
            row.appendChild(actionCell);

            body.appendChild(row);
        });
    };


    const getSurveyStatusLabel = (status) => {

        const labels = {
            draft: '下書き',
            public: '公開中',
            closed: '終了'
        };

        return labels?.[status]
            || '未設定';
    };


    const openSurveyEditor = async (surveyId) => {

        state.currentSurveyId =
            surveyId || null;


        clearSurveyEditor();


        if (surveyId) {

            const title =
                getElement(
                    'survey-editor-title'
                );

            setText(
                title,
                'アンケートを編集'
            );


            const deleteButton =
                getElement(
                    'btn-delete-survey'
                );

            const publishButton =
                getElement(
                    'btn-publish-survey'
                );

            if (deleteButton) {
                showElement(deleteButton);
            }

            if (publishButton) {
                showElement(publishButton);
            }


            try {

                const result =
                    await api(
                        'get_survey',
                        'GET',
                        {
                            id: surveyId
                        }
                    );

                populateSurveyEditor(
                    result?.data?.survey || {}
                );

            } catch (error) {

                showStatus(
                    error.message,
                    'error'
                );

                return;
            }

        } else {

            const title =
                getElement(
                    'survey-editor-title'
                );

            setText(
                title,
                '新しいアンケート'
            );
        }


        showPage('survey-editor');
    };


    const clearSurveyEditor = () => {

        const name =
            getElement('survey-name');

        const description =
            getElement('survey-description');

        const questionList =
            getElement('question-list');

        const questionEmpty =
            getElement('question-empty');

        if (name) {
            name.value = '';
        }

        if (description) {
            description.value = '';
        }

        if (questionList) {
            questionList.textContent = '';
        }

        if (questionEmpty) {
            showElement(questionEmpty);
        }


        const deleteButton =
            getElement('btn-delete-survey');

        const publishButton =
            getElement('btn-publish-survey');

        const closeButton =
            getElement('btn-close-survey');

        if (deleteButton) {
            hideElement(deleteButton);
        }

        if (publishButton) {
            hideElement(publishButton);
        }

        if (closeButton) {
            hideElement(closeButton);
        }
    };


    const populateSurveyEditor = (survey) => {

        if (!survey) {
            return;
        }

        const name =
            getElement('survey-name');

        const description =
            getElement('survey-description');

        if (name) {
            name.value =
                survey?.name || '';
        }

        if (description) {
            description.value =
                survey?.description || '';
        }


        const questions =
            Array.isArray(
                survey?.questions
            )
                ? survey.questions
                : [];


        questions.forEach((question) => {

            addQuestion(
                question || {}
            );
        });


        const closeButton =
            getElement('btn-close-survey');

        if (
            closeButton
            && survey?.status === 'public'
        ) {
            showElement(closeButton);
        }
    };


    const addQuestion = (question = {}) => {

        const template =
            getElement('question-template');

        const list =
            getElement('question-list');

        const empty =
            getElement('question-empty');


        if (!template || !list) {
            return;
        }


        const fragment =
            template.content.cloneNode(true);

        const item =
            fragment.querySelector(
                '.question-item'
            );

        if (!item) {
            return;
        }


        const questionIndex =
            list.children.length + 1;

        item.dataset.questionIndex =
            String(questionIndex);


        const number =
            item.querySelector(
                '.question-number'
            );

        const text =
            item.querySelector(
                '.question-text'
            );

        const type =
            item.querySelector(
                '.question-type'
            );

        const required =
            item.querySelector(
                '.question-required'
            );

        const options =
            item.querySelector(
                '.question-options'
            );

        const optionsField =
            item.querySelector(
                '.question-options-field'
            );

        const removeButton =
            item.querySelector(
                '.btn-remove-question'
            );


        if (number) {
            number.textContent =
                `質問 ${questionIndex}`;
        }

        if (text) {
            text.value =
                question?.text || '';
        }

        if (type) {
            type.value =
                question?.type || 'text';
        }

        if (required) {
            required.value =
                question?.required === true
                    ? 'true'
                    : 'false';
        }

        if (options) {

            const optionValues =
                Array.isArray(
                    question?.options
                )
                    ? question.options
                    : [];

            options.value =
                optionValues.join('\n');
        }


        const updateOptionsVisibility =
            () => {

                if (!type || !optionsField) {
                    return;
                }

                const needsOptions =
                    type.value === 'single'
                    || type.value === 'multiple';

                optionsField.hidden =
                    !needsOptions;
            };


        if (type) {

            type.addEventListener(
                'change',
                updateOptionsVisibility
            );
        }


        if (removeButton) {

            removeButton.addEventListener(
                'click',
                () => {

                    item.remove();

                    renumberQuestions();
                }
            );
        }


        list.appendChild(
            fragment
        );


        updateOptionsVisibility();


        if (empty) {
            hideElement(empty);
        }
    };


    const renumberQuestions = () => {

        const list =
            getElement('question-list');

        const empty =
            getElement('question-empty');

        if (!list) {
            return;
        }


        const items =
            list.querySelectorAll(
                '.question-item'
            );


        items.forEach(
            (item, index) => {

                if (!item) {
                    return;
                }

                item.dataset.questionIndex =
                    String(index + 1);


                const number =
                    item.querySelector(
                        '.question-number'
                    );

                if (number) {
                    number.textContent =
                        `質問 ${index + 1}`;
                }
            }
        );


        if (empty) {

            if (items.length === 0) {
                showElement(empty);
            } else {
                hideElement(empty);
            }
        }
    };


    const collectSurveyData = () => {

        const name =
            getElement('survey-name');

        const description =
            getElement('survey-description');

        const list =
            getElement('question-list');


        const questions = [];


        if (list) {

            const items =
                list.querySelectorAll(
                    '.question-item'
                );


            items.forEach((item) => {

                if (!item) {
                    return;
                }

                const text =
                    item.querySelector(
                        '.question-text'
                    );

                const type =
                    item.querySelector(
                        '.question-type'
                    );

                const required =
                    item.querySelector(
                        '.question-required'
                    );

                const options =
                    item.querySelector(
                        '.question-options'
                    );


                const optionValues =
                    options
                        ? options.value
                            .split(/\r?\n/)
                            .map(
                                (value) =>
                                    value.trim()
                            )
                            .filter(
                                (value) =>
                                    value !== ''
                            )
                        : [];


                questions.push({
                    text: text?.value?.trim()
                        || '',
                    type: type?.value
                        || 'text',
                    required:
                        required?.value === 'true',
                    options: optionValues
                });
            });
        }


        return {
            id: state.currentSurveyId,
            name: name?.value?.trim() || '',
            description:
                description?.value?.trim() || '',
            questions: questions
        };
    };


    /*
     * =========================================================
     * 顧客
     * =========================================================
     */

    const loadCustomers = async () => {

        const button =
            getElement(
                'btn-refresh-customers'
            );

        try {

            const result =
                await api(
                    'get_customers',
                    'GET',
                    {},
                    button
                );

            state.customers =
                Array.isArray(
                    result?.data?.customers
                )
                    ? result.data.customers
                    : [];

            renderCustomers();

        } catch (error) {

            renderCustomers();

            showStatus(
                error.message,
                'error'
            );
        }
    };


    const renderCustomers = () => {

        const body =
            getElement(
                'customer-table-body'
            );

        const empty =
            getElement(
                'customer-empty'
            );

        if (!body) {
            return;
        }

        body.textContent = '';


        const search =
            getElement('customer-search');

        const keyword =
            search?.value?.trim()
                .toLowerCase()
                || '';


        const customers =
            Array.isArray(state.customers)
                ? state.customers.filter(
                    (customer) => {

                        if (!keyword) {
                            return true;
                        }

                        const text = [
                            customer?.id,
                            customer?.name,
                            customer?.email
                        ]
                            .filter(
                                (value) =>
                                    value !== null
                                    && value !== undefined
                            )
                            .join(' ')
                            .toLowerCase();

                        return text.includes(keyword);
                    }
                )
                : [];


        if (customers.length === 0) {

            if (empty) {
                showElement(empty);
            }

            return;
        }


        if (empty) {
            hideElement(empty);
        }


        customers.forEach((customer) => {

            if (!customer) {
                return;
            }

            const row =
                document.createElement('tr');

            const idCell =
                document.createElement('td');

            const nameCell =
                document.createElement('td');

            const emailCell =
                document.createElement('td');

            const fetchedCell =
                document.createElement('td');


            setText(
                idCell,
                customer?.id || ''
            );

            setText(
                nameCell,
                customer?.name || ''
            );

            setText(
                emailCell,
                customer?.email || ''
            );

            setText(
                fetchedCell,
                formatDateTime(
                    customer?.fetched_at
                )
            );


            row.appendChild(idCell);
            row.appendChild(nameCell);
            row.appendChild(emailCell);
            row.appendChild(fetchedCell);

            body.appendChild(row);
        });
    };


    /*
     * =========================================================
     * 送信履歴
     * =========================================================
     */

    const loadMailLogs = async () => {

        const button =
            getElement(
                'btn-refresh-mail-logs'
            );

        try {

            const result =
                await api(
                    'get_mail_logs',
                    'GET',
                    {},
                    button
                );

            state.mailLogs =
                Array.isArray(
                    result?.data?.logs
                )
                    ? result.data.logs
                    : [];

            renderMailLogs();

        } catch (error) {

            renderMailLogs();

            showStatus(
                error.message,
                'error'
            );
        }
    };


    const renderMailLogs = () => {

        const body =
            getElement(
                'mail-log-table-body'
            );

        const empty =
            getElement(
                'mail-log-empty'
            );

        if (!body) {
            return;
        }

        body.textContent = '';


        if (
            !Array.isArray(state.mailLogs)
            || state.mailLogs.length === 0
        ) {

            if (empty) {
                showElement(empty);
            }

            return;
        }


        if (empty) {
            hideElement(empty);
        }


        state.mailLogs.forEach((log) => {

            if (!log) {
                return;
            }

            const row =
                document.createElement('tr');

            const dateCell =
                document.createElement('td');

            const surveyCell =
                document.createElement('td');

            const customerCell =
                document.createElement('td');

            const emailCell =
                document.createElement('td');

            const statusCell =
                document.createElement('td');

            const actionCell =
                document.createElement('td');


            setText(
                dateCell,
                formatDateTime(
                    log?.sent_at
                )
            );

            setText(
                surveyCell,
                log?.survey_name || ''
            );

            setText(
                customerCell,
                log?.customer_name || ''
            );

            setText(
                emailCell,
                log?.email || ''
            );

            setText(
                statusCell,
                getMailStatusLabel(
                    log?.status
                )
            );


            if (log?.message) {

                const detailButton =
                    document.createElement('button');

                detailButton.type = 'button';
                detailButton.className =
                    'button button-secondary button-small';

                detailButton.textContent =
                    '詳細';

                detailButton.addEventListener(
                    'click',
                    () => {

                        showStatus(
                            String(log.message),
                            log?.status === 'failed'
                                ? 'error'
                                : 'info'
                        );
                    }
                );

                actionCell.appendChild(
                    detailButton
                );
            }


            row.appendChild(dateCell);
            row.appendChild(surveyCell);
            row.appendChild(customerCell);
            row.appendChild(emailCell);
            row.appendChild(statusCell);
            row.appendChild(actionCell);

            body.appendChild(row);
        });
    };


    const getMailStatusLabel = (status) => {

        const labels = {
            sent: '送信済み',
            failed: '送信失敗',
            pending: '送信待ち'
        };

        return labels?.[status]
            || '不明';
    };


    /*
     * =========================================================
     * 設定
     * =========================================================
     */

    const loadSettings = async () => {

        try {

            const result =
                await api(
                    'get_settings',
                    'GET'
                );

            state.settings =
                result?.data?.settings || {};

            populateSettings();

        } catch (error) {

            /*
             * 初期設定が存在しない場合でも、
             * 入力画面はそのまま使用可能にする。
             */
            populateSettings();

            showStatus(
                error.message,
                'error'
            );
        }
    };


    const populateSettings = () => {

        const mail =
            state.settings?.mail || {};

        const kintone =
            state.settings?.kintone || {};


        const smtpServer =
            getElement('mail-smtp-server');

        const smtpPort =
            getElement('mail-smtp-port');

        const security =
            getElement('mail-security');

        const authentication =
            getElement('mail-authentication');

        const username =
            getElement('mail-username');

        const fromAddress =
            getElement('mail-from-address');

        const fromName =
            getElement('mail-from-name');


        if (smtpServer) {
            smtpServer.value =
                mail?.smtp_server || '';
        }

        if (smtpPort) {
            smtpPort.value =
                mail?.smtp_port || 587;
        }

        if (security) {
            security.value =
                mail?.security || 'STARTTLS';
        }

        if (authentication) {
            authentication.value =
                mail?.authentication === true
                    ? 'true'
                    : 'false';
        }

        if (username) {
            username.value =
                mail?.username || '';
        }

        if (fromAddress) {
            fromAddress.value =
                mail?.from_address || '';
        }

        if (fromName) {
            fromName.value =
                mail?.from_name || '';
        }


        const domain =
            getElement('kintone-domain');

        const appId =
            getElement('kintone-app-id');

        const loginName =
            getElement('kintone-login-name');

        const proxyEnabled =
            getElement('kintone-proxy-enabled');

        const proxyHostPort =
            getElement(
                'kintone-proxy-host-port'
            );


        if (domain) {
            domain.value =
                kintone?.domain || '';
        }

        if (appId) {
            appId.value =
                kintone?.app_id || '';
        }

        if (loginName) {
            loginName.value =
                kintone?.login_name || '';
        }

        if (proxyEnabled) {
            proxyEnabled.value =
                kintone?.proxy_enabled === true
                    ? 'true'
                    : 'false';
        }

        if (proxyHostPort) {
            proxyHostPort.value =
                kintone?.proxy_host_port || '';
        }


        updateMailAuthenticationFields();

        updateMailSettingsStatus(
            mail?.configured === true
        );

        updateKintoneSettingsStatus(
            kintone?.configured === true
        );
    };


    const updateMailAuthenticationFields = () => {

        const authentication =
            getElement(
                'mail-authentication'
            );

        const usernameField =
            getElement(
                'mail-username-field'
            );

        const passwordField =
            getElement(
                'mail-password-field'
            );


        if (!authentication) {
            return;
        }


        const enabled =
            authentication.value === 'true';


        if (usernameField) {
            usernameField.hidden =
                !enabled;
        }

        if (passwordField) {
            passwordField.hidden =
                !enabled;
        }
    };


    const updateMailSettingsStatus = (configured) => {

        const element =
            getElement(
                'mail-settings-status'
            );

        if (!element) {
            return;
        }

        element.classList.remove(
            'is-configured',
            'is-unconfigured'
        );


        if (configured) {

            element.classList.add(
                'is-configured'
            );

            element.textContent =
                '設定済み';

        } else {

            element.classList.add(
                'is-unconfigured'
            );

            element.textContent =
                '未設定';
        }
    };


    const updateKintoneSettingsStatus = (configured) => {

        const element =
            getElement(
                'kintone-settings-status'
            );

        if (!element) {
            return;
        }

        element.classList.remove(
            'is-configured',
            'is-unconfigured'
        );


        if (configured) {

            element.classList.add(
                'is-configured'
            );

            element.textContent =
                '設定済み';

        } else {

            element.classList.add(
                'is-unconfigured'
            );

            element.textContent =
                '未設定';
        }
    };


    /*
     * =========================================================
     * メール設定保存
     *
     * 今回のCORSエラーの対象処理。
     *
     * ここでは外部URLへfetchしない。
     * api() が現在の index.php を送信先として使用する。
     * =========================================================
     */

    const saveMailSettings = async () => {

        const button =
            getElement(
                'btn-save-mail-settings'
            );

        const smtpServer =
            getElement('mail-smtp-server');

        const smtpPort =
            getElement('mail-smtp-port');

        const security =
            getElement('mail-security');

        const authentication =
            getElement('mail-authentication');

        const username =
            getElement('mail-username');

        const password =
            getElement('mail-password');

        const fromAddress =
            getElement('mail-from-address');

        const fromName =
            getElement('mail-from-name');


        clearFormErrors(
            'mail-settings-form'
        );


        const data = {
            smtp_server:
                smtpServer?.value?.trim() || '',

            smtp_port:
                Number(
                    smtpPort?.value || 0
                ),

            security:
                security?.value || 'STARTTLS',

            authentication:
                authentication?.value === 'true',

            username:
                username?.value?.trim() || '',

            password:
                password?.value || '',

            from_address:
                fromAddress?.value?.trim() || '',

            from_name:
                fromName?.value?.trim() || ''
        };


        try {

            const result =
                await api(
                    'save_mail_settings',
                    'POST',
                    data,
                    button
                );


            state.settings =
                result?.data?.settings
                || state.settings;


            /*
             * パスワードは画面・JavaScript状態に保持しない。
             */
            if (password) {
                password.value = '';
            }


            updateMailSettingsStatus(true);

            showStatus(
                'メール設定を保存しました。',
                'success'
            );

        } catch (error) {

            showStatus(
                error.message,
                'error'
            );
        }
    };


    /*
     * =========================================================
     * kintone設定保存
     * =========================================================
     */

    const saveKintoneSettings = async () => {

        const button =
            getElement('btn-save-kintone');

        const domain =
            getElement('kintone-domain');

        const appId =
            getElement('kintone-app-id');

        const loginName =
            getElement('kintone-login-name');

        const password =
            getElement('kintone-password');

        const proxyEnabled =
            getElement(
                'kintone-proxy-enabled'
            );

        const proxyHostPort =
            getElement(
                'kintone-proxy-host-port'
            );


        clearFormErrors(
            'kintone-settings-form'
        );


        const data = {

            domain:
                domain?.value?.trim() || '',

            app_id:
                appId?.value?.trim() || '',

            login_name:
                loginName?.value?.trim() || '',

            password:
                password?.value || '',

            proxy_enabled:
                proxyEnabled?.value === 'true',

            proxy_host_port:
                proxyHostPort?.value?.trim() || ''
        };


        try {

            const result =
                await api(
                    'save_kintone_settings',
                    'POST',
                    data,
                    button
                );


            state.settings =
                result?.data?.settings
                || state.settings;


            /*
             * パスワードを保持しない。
             */
            if (password) {
                password.value = '';
            }


            updateKintoneSettingsStatus(
                true
            );

            showStatus(
                'kintone設定を保存しました。',
                'success'
            );

        } catch (error) {

            showStatus(
                error.message,
                'error'
            );
        }
    };


    /*
     * =========================================================
     * kintone接続確認
     * =========================================================
     */

    const testKintoneConnection = async () => {

        const button =
            getElement(
                'btn-test-kintone'
            );

        const domain =
            getElement(
                'kintone-domain'
            );

        const appId =
            getElement(
                'kintone-app-id'
            );

        const loginName =
            getElement(
                'kintone-login-name'
            );

        const password =
            getElement(
                'kintone-password'
            );

        const proxyEnabled =
            getElement(
                'kintone-proxy-enabled'
            );

        const proxyHostPort =
            getElement(
                'kintone-proxy-host-port'
            );


        const data = {

            domain:
                domain?.value?.trim() || '',

            app_id:
                appId?.value?.trim() || '',

            login_name:
                loginName?.value?.trim() || '',

            password:
                password?.value || '',

            proxy_enabled:
                proxyEnabled?.value === 'true',

            proxy_host_port:
                proxyHostPort?.value?.trim() || ''
        };


        try {

            const result =
                await api(
                    'test_kintone_connection',
                    'POST',
                    data,
                    button
                );


            showStatus(
                result?.message
                || 'kintoneへの接続を確認しました。',
                'success'
            );

        } catch (error) {

            showStatus(
                error.message,
                'error'
            );
        }
    };


    /*
     * =========================================================
     * メール接続確認
     * =========================================================
     */

    const testMailSettings = async () => {

        const button =
            getElement(
                'btn-test-mail-settings'
            );

        const smtpServer =
            getElement(
                'mail-smtp-server'
            );

        const smtpPort =
            getElement(
                'mail-smtp-port'
            );

        const security =
            getElement(
                'mail-security'
            );

        const authentication =
            getElement(
                'mail-authentication'
            );

        const username =
            getElement(
                'mail-username'
            );

        const password =
            getElement(
                'mail-password'
            );


        const data = {

            smtp_server:
                smtpServer?.value?.trim() || '',

            smtp_port:
                Number(
                    smtpPort?.value || 0
                ),

            security:
                security?.value || 'STARTTLS',

            authentication:
                authentication?.value === 'true',

            username:
                username?.value?.trim() || '',

            password:
                password?.value || ''
        };


        try {

            const result =
                await api(
                    'test_mail_settings',
                    'POST',
                    data,
                    button
                );


            showStatus(
                result?.message
                || 'メールサーバーへの接続を確認しました。',
                'success'
            );

        } catch (error) {

            showStatus(
                error.message,
                'error'
            );
        }
    };


    /*
     * =========================================================
     * フォームエラー
     * =========================================================
     */

    const clearFormErrors = (formId) => {

        const form =
            getElement(formId);

        if (!form) {
            return;
        }


        const errors =
            form.querySelectorAll(
                '.form-error'
            );


        errors.forEach((error) => {

            if (!error) {
                return;
            }

            error.textContent = '';
            error.hidden = true;
        });
    };


    /*
     * =========================================================
     * プレビュー
     * =========================================================
     */

    const openSurveyPreview = () => {

        const modal =
            getElement(
                'survey-preview-modal'
            );

        const body =
            getElement(
                'survey-preview-body'
            );


        if (!modal || !body) {
            return;
        }


        const survey =
            collectSurveyData();


        body.textContent = '';


        const title =
            document.createElement('h3');

        title.textContent =
            survey?.name
            || 'アンケート';


        const description =
            document.createElement('p');

        description.textContent =
            survey?.description || '';


        body.appendChild(title);
        body.appendChild(description);


        const questions =
            Array.isArray(
                survey?.questions
            )
                ? survey.questions
                : [];


        questions.forEach(
            (question, index) => {

                const wrapper =
                    document.createElement('div');

                wrapper.className =
                    'preview-question';


                const label =
                    document.createElement('p');

                label.textContent =
                    `${index + 1}. ${question?.text || ''}`;


                wrapper.appendChild(label);


                if (
                    question?.type === 'single'
                    || question?.type === 'multiple'
                ) {

                    const list =
                        document.createElement('ul');


                    const options =
                        Array.isArray(
                            question?.options
                        )
                            ? question.options
                            : [];


                    options.forEach((option) => {

                        const li =
                            document.createElement('li');

                        li.textContent =
                            option || '';

                        list.appendChild(li);
                    });


                    wrapper.appendChild(list);
                }


                body.appendChild(wrapper);
            }
        );


        showElement(modal);
    };


    const closeSurveyPreview = () => {

        const modal =
            getElement(
                'survey-preview-modal'
            );

        if (!modal) {
            return;
        }

        hideElement(modal);
    };


    /*
     * =========================================================
     * 送信モーダル
     * =========================================================
     */

    const openSendSurveyModal = async (
        surveyId
    ) => {

        const modal =
            getElement(
                'send-survey-modal'
            );

        const body =
            getElement(
                'send-customer-table-body'
            );


        if (!modal || !body) {
            return;
        }


        state.currentSurveyId =
            surveyId || null;


        body.textContent = '';


        try {

            const result =
                await api(
                    'get_send_customers',
                    'GET',
                    {
                        survey_id:
                            surveyId
                    }
                );


            const customers =
                Array.isArray(
                    result?.data?.customers
                )
                    ? result.data.customers
                    : [];


            renderSendCustomers(
                customers
            );


            showElement(modal);

        } catch (error) {

            showStatus(
                error.message,
                'error'
            );
        }
    };


    const renderSendCustomers = (
        customers
    ) => {

        const body =
            getElement(
                'send-customer-table-body'
            );

        const empty =
            getElement(
                'send-customer-empty'
            );


        if (!body) {
            return;
        }

        body.textContent = '';


        if (
            !Array.isArray(customers)
            || customers.length === 0
        ) {

            if (empty) {
                showElement(empty);
            }

            return;
        }


        if (empty) {
            hideElement(empty);
        }


        customers.forEach((customer) => {

            if (!customer) {
                return;
            }


            const row =
                document.createElement('tr');


            const checkCell =
                document.createElement('td');

            const nameCell =
                document.createElement('td');

            const emailCell =
                document.createElement('td');

            const statusCell =
                document.createElement('td');


            const checkbox =
                document.createElement(
                    'input'
                );

            checkbox.type =
                'checkbox';

            checkbox.className =
                'send-customer-checkbox';

            checkbox.value =
                String(
                    customer?.id || ''
                );


            checkCell.appendChild(
                checkbox
            );


            setText(
                nameCell,
                customer?.name || ''
            );

            setText(
                emailCell,
                customer?.email || ''
            );

            setText(
                statusCell,
                customer?.status_label
                || '送信可能'
            );


            row.appendChild(checkCell);
            row.appendChild(nameCell);
            row.appendChild(emailCell);
            row.appendChild(statusCell);

            body.appendChild(row);
        });
    };


    const closeSendSurveyModal = () => {

        const modal =
            getElement(
                'send-survey-modal'
            );

        if (!modal) {
            return;
        }

        hideElement(modal);
    };


    const executeSendSurvey = async () => {

        const button =
            getElement(
                'btn-execute-send-survey'
            );


        const selected =
            document.querySelectorAll(
                '.send-customer-checkbox:checked'
            );


        const customerIds =
            Array.from(selected)
                .map(
                    (checkbox) =>
                        checkbox?.value || ''
                )
                .filter(
                    (value) =>
                        value !== ''
                );


        if (customerIds.length === 0) {

            showStatus(
                '送信対象の顧客を選択してください。',
                'error'
            );

            return;
        }


        try {

            const result =
                await api(
                    'send_survey',
                    'POST',
                    {
                        survey_id:
                            state.currentSurveyId,

                        customer_ids:
                            customerIds
                    },
                    button
                );


            closeSendSurveyModal();

            showStatus(
                result?.message
                || 'アンケートメールを送信しました。',
                'success'
            );


            if (
                state.currentPage ===
                'mail-logs'
            ) {
                loadMailLogs();
            }

        } catch (error) {

            showStatus(
                error.message,
                'error'
            );
        }
    };


    /*
     * =========================================================
     * アンケート保存
     * =========================================================
     */

    const saveSurvey = async () => {

        const button =
            getElement(
                'btn-save-survey'
            );


        const data =
            collectSurveyData();


        if (!data.name) {

            showStatus(
                'アンケート名を入力してください。',
                'error'
            );

            return;
        }


        try {

            const result =
                await api(
                    'save_survey',
                    'POST',
                    data,
                    button
                );


            state.currentSurveyId =
                result?.data?.survey?.id
                || state.currentSurveyId;


            showStatus(
                'アンケートを保存しました。',
                'success'
            );


            await loadSurveys();

            showPage('surveys');

        } catch (error) {

            showStatus(
                error.message,
                'error'
            );
        }
    };


    /*
     * =========================================================
     * アンケート削除
     * =========================================================
     */

    const requestDeleteSurvey = () => {

        if (!state.currentSurveyId) {
            return;
        }


        openConfirmModal(
            'アンケートを削除しますか？',
            async () => {

                const button =
                    getElement(
                        'btn-execute-confirm'
                    );


                try {

                    await api(
                        'delete_survey',
                        'POST',
                        {
                            id:
                                state.currentSurveyId
                        },
                        button
                    );


                    closeConfirmModal();

                    state.currentSurveyId =
                        null;

                    showStatus(
                        'アンケートを削除しました。',
                        'success'
                    );


                    await loadSurveys();

                    showPage('surveys');

                } catch (error) {

                    showStatus(
                        error.message,
                        'error'
                    );
                }
            }
        );
    };


    /*
     * =========================================================
     * アンケート公開
     * =========================================================
     */

    const publishSurvey = () => {

        if (!state.currentSurveyId) {
            return;
        }


        openConfirmModal(
            'このアンケートを公開しますか？',
            async () => {

                const button =
                    getElement(
                        'btn-execute-confirm'
                    );


                try {

                    await api(
                        'publish_survey',
                        'POST',
                        {
                            id:
                                state.currentSurveyId
                        },
                        button
                    );


                    closeConfirmModal();

                    showStatus(
                        'アンケートを公開しました。',
                        'success'
                    );


                    await loadSurveys();

                    showPage('surveys');

                } catch (error) {

                    showStatus(
                        error.message,
                        'error'
                    );
                }
            }
        );
    };


    /*
     * =========================================================
     * アンケート終了
     * =========================================================
     */

    const closeSurvey = () => {

        if (!state.currentSurveyId) {
            return;
        }


        openConfirmModal(
            'このアンケートを終了しますか？',
            async () => {

                const button =
                    getElement(
                        'btn-execute-confirm'
                    );


                try {

                    await api(
                        'close_survey',
                        'POST',
                        {
                            id:
                                state.currentSurveyId
                        },
                        button
                    );


                    closeConfirmModal();

                    showStatus(
                        'アンケートを終了しました。',
                        'success'
                    );


                    await loadSurveys();

                    showPage('surveys');

                } catch (error) {

                    showStatus(
                        error.message,
                        'error'
                    );
                }
            }
        );
    };


    /*
     * =========================================================
     * 確認モーダル
     * =========================================================
     */

    const openConfirmModal = (
        message,
        action
    ) => {

        const modal =
            getElement('confirm-modal');

        const body =
            getElement(
                'confirm-modal-body'
            );


        if (!modal || !body) {
            return;
        }


        body.textContent =
            message || '実行しますか？';


        state.confirmAction =
            typeof action === 'function'
                ? action
                : null;


        showElement(modal);
    };


    const closeConfirmModal = () => {

        const modal =
            getElement('confirm-modal');

        if (!modal) {
            return;
        }


        hideElement(modal);

        state.confirmAction =
            null;
    };


    /*
     * =========================================================
     * 日付表示
     * =========================================================
     */

    const formatDateTime = (value) => {

        if (!value) {
            return '';
        }


        const date =
            new Date(value);


        if (
            Number.isNaN(
                date.getTime()
            )
        ) {
            return String(value);
        }


        return new Intl.DateTimeFormat(
            'ja-JP',
            {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }
        ).format(date);
    };


    /*
     * =========================================================
     * イベント登録
     *
     * すべてNullチェック付き。
     * =========================================================
     */

    const createSurveyButton =
        getElement(
            'btn-create-survey'
        );

    if (createSurveyButton) {

        createSurveyButton.addEventListener(
            'click',
            () => {
                openSurveyEditor(null);
            }
        );
    }


    const refreshSurveysButton =
        getElement(
            'btn-refresh-surveys'
        );

    if (refreshSurveysButton) {

        refreshSurveysButton.addEventListener(
            'click',
            () => {
                loadSurveys();
            }
        );
    }


    const surveyStatusFilter =
        getElement(
            'survey-status-filter'
        );

    if (surveyStatusFilter) {

        surveyStatusFilter.addEventListener(
            'change',
            () => {
                renderSurveys();
            }
        );
    }


    const cancelSurveyEditorButton =
        getElement(
            'btn-cancel-survey-editor'
        );

    if (cancelSurveyEditorButton) {

        cancelSurveyEditorButton.addEventListener(
            'click',
            () => {
                showPage('surveys');
            }
        );
    }


    const saveSurveyButton =
        getElement(
            'btn-save-survey'
        );

    if (saveSurveyButton) {

        saveSurveyButton.addEventListener(
            'click',
            () => {
                saveSurvey();
            }
        );
    }


    const addQuestionButton =
        getElement(
            'btn-add-question'
        );

    if (addQuestionButton) {

        addQuestionButton.addEventListener(
            'click',
            () => {
                addQuestion({});
            }
        );
    }


    const previewSurveyButton =
        getElement(
            'btn-preview-survey'
        );

    if (previewSurveyButton) {

        previewSurveyButton.addEventListener(
            'click',
            () => {
                openSurveyPreview();
            }
        );
    }


    const deleteSurveyButton =
        getElement(
            'btn-delete-survey'
        );

    if (deleteSurveyButton) {

        deleteSurveyButton.addEventListener(
            'click',
            () => {
                requestDeleteSurvey();
            }
        );
    }


    const publishSurveyButton =
        getElement(
            'btn-publish-survey'
        );

    if (publishSurveyButton) {

        publishSurveyButton.addEventListener(
            'click',
            () => {
                publishSurvey();
            }
        );
    }


    const closeSurveyButton =
        getElement(
            'btn-close-survey'
        );

    if (closeSurveyButton) {

        closeSurveyButton.addEventListener(
            'click',
            () => {
                closeSurvey();
            }
        );
    }


    const refreshCustomersButton =
        getElement(
            'btn-refresh-customers'
        );

    if (refreshCustomersButton) {

        refreshCustomersButton.addEventListener(
            'click',
            () => {
                loadCustomers();
            }
        );
    }


    const customerSearch =
        getElement(
            'customer-search'
        );

    if (customerSearch) {

        customerSearch.addEventListener(
            'input',
            () => {
                renderCustomers();
            }
        );
    }


    const refreshMailLogsButton =
        getElement(
            'btn-refresh-mail-logs'
        );

    if (refreshMailLogsButton) {

        refreshMailLogsButton.addEventListener(
            'click',
            () => {
                loadMailLogs();
            }
        );
    }


    const mailAuthentication =
        getElement(
            'mail-authentication'
        );

    if (mailAuthentication) {

        mailAuthentication.addEventListener(
            'change',
            () => {
                updateMailAuthenticationFields();
            }
        );
    }


    const mailSettingsForm =
        getElement(
            'mail-settings-form'
        );

    if (mailSettingsForm) {

        mailSettingsForm.addEventListener(
            'submit',
            (event) => {

                event.preventDefault();

                saveMailSettings();
            }
        );
    }


    const saveMailSettingsButton =
        getElement(
            'btn-save-mail-settings'
        );

    if (saveMailSettingsButton) {

        /*
         * submitイベントとの二重実行を避けるため、
         * ここではclickイベントを登録しない。
         */
    }


    const testMailSettingsButton =
        getElement(
            'btn-test-mail-settings'
        );

    if (testMailSettingsButton) {

        testMailSettingsButton.addEventListener(
            'click',
            () => {
                testMailSettings();
            }
        );
    }


    const kintoneSettingsForm =
        getElement(
            'kintone-settings-form'
        );

    if (kintoneSettingsForm) {

        kintoneSettingsForm.addEventListener(
            'submit',
            (event) => {

                event.preventDefault();

                saveKintoneSettings();
            }
        );
    }


    const testKintoneButton =
        getElement(
            'btn-test-kintone'
        );

    if (testKintoneButton) {

        testKintoneButton.addEventListener(
            'click',
            () => {
                testKintoneConnection();
            }
        );
    }


    const closeSurveyPreviewButton =
        getElement(
            'btn-close-survey-preview'
        );

    if (closeSurveyPreviewButton) {

        closeSurveyPreviewButton.addEventListener(
            'click',
            () => {
                closeSurveyPreview();
            }
        );
    }


    const closeSurveyPreviewFooterButton =
        getElement(
            'btn-close-survey-preview-footer'
        );

    if (closeSurveyPreviewFooterButton) {

        closeSurveyPreviewFooterButton.addEventListener(
            'click',
            () => {
                closeSurveyPreview();
            }
        );
    }


    const closeConfirmButton =
        getElement(
            'btn-close-confirm'
        );

    if (closeConfirmButton) {

        closeConfirmButton.addEventListener(
            'click',
            () => {
                closeConfirmModal();
            }
        );
    }


    const cancelConfirmButton =
        getElement(
            'btn-cancel-confirm'
        );

    if (cancelConfirmButton) {

        cancelConfirmButton.addEventListener(
            'click',
            () => {
                closeConfirmModal();
            }
        );
    }


    const executeConfirmButton =
        getElement(
            'btn-execute-confirm'
        );

    if (executeConfirmButton) {

        executeConfirmButton.addEventListener(
            'click',
            async () => {

                if (
                    typeof state.confirmAction
                    !== 'function'
                ) {
                    closeConfirmModal();
                    return;
                }

                await state.confirmAction();
            }
        );
    }


    const closeSendSurveyButton =
        getElement(
            'btn-close-send-survey'
        );

    if (closeSendSurveyButton) {

        closeSendSurveyButton.addEventListener(
            'click',
            () => {
                closeSendSurveyModal();
            }
        );
    }


    const cancelSendSurveyButton =
        getElement(
            'btn-cancel-send-survey'
        );

    if (cancelSendSurveyButton) {

        cancelSendSurveyButton.addEventListener(
            'click',
            () => {
                closeSendSurveyModal();
            }
        );
    }


    const executeSendSurveyButton =
        getElement(
            'btn-execute-send-survey'
        );

    if (executeSendSurveyButton) {

        executeSendSurveyButton.addEventListener(
            'click',
            () => {
                executeSendSurvey();
            }
        );
    }


    const selectAllCustomers =
        getElement(
            'send-select-all-customers'
        );

    if (selectAllCustomers) {

        selectAllCustomers.addEventListener(
            'change',
            () => {

                const checked =
                    selectAllCustomers.checked;


                const checkboxes =
                    document.querySelectorAll(
                        '.send-customer-checkbox'
                    );


                checkboxes.forEach(
                    (checkbox) => {

                        if (!checkbox) {
                            return;
                        }

                        checkbox.checked =
                            checked;
                    }
                );
            }
        );
    }


    /*
     * =========================================================
     * 初期表示
     * =========================================================
     */

    showPage('dashboard');

});
</script>


</body>
</html>

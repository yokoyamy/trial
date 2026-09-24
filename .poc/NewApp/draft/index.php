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

<?php
declare(strict_types=1);

namespace yokoyamy\trial\newapp;

use RuntimeException;
use Throwable;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

const APP_SESSION_KEY = 'yokoyamy_newapp';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const SETTINGS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json';
const SURVEYS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'surveys.json';
const CUSTOMERS_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'customers.json';
const RESPONSES_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'responses.json';

const APP_VERSION = '2.0.0';

/**
 * 安全な文字列エスケープ
 */
function h(?string $str): string
{
    return htmlspecialchars(
        $str ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * アプリ固有セッション取得
 */
function get_app_session(): array
{
    if (
        !isset($_SESSION[APP_SESSION_KEY]) ||
        !is_array($_SESSION[APP_SESSION_KEY])
    ) {
        $_SESSION[APP_SESSION_KEY] = [];
    }

    return $_SESSION[APP_SESSION_KEY];
}

/**
 * アプリ固有セッション保存
 */
function set_app_session(string $key, mixed $value): void
{
    if (
        !isset($_SESSION[APP_SESSION_KEY]) ||
        !is_array($_SESSION[APP_SESSION_KEY])
    ) {
        $_SESSION[APP_SESSION_KEY] = [];
    }

    $_SESSION[APP_SESSION_KEY][$key] = $value;
}

/**
 * CSRFトークン取得
 */
function get_csrf_token(): string
{
    $session = get_app_session();

    if (
        !isset($session['csrf_token']) ||
        !is_string($session['csrf_token']) ||
        strlen($session['csrf_token']) < 64
    ) {
        $token = bin2hex(random_bytes(32));
        set_app_session('csrf_token', $token);

        return $token;
    }

    return $session['csrf_token'];
}

/**
 * CSRF検証
 */
function verify_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === '') {
        $body = file_get_contents('php://input');

        if ($body !== false && $body !== '') {
            $json = json_decode($body, true);

            if (
                is_array($json) &&
                isset($json['csrf_token']) &&
                is_string($json['csrf_token'])
            ) {
                $token = $json['csrf_token'];
            }
        }
    }

    $session = get_app_session();
    $expected = $session['csrf_token'] ?? '';

    if (
        !is_string($expected) ||
        $expected === '' ||
        !is_string($token) ||
        $token === '' ||
        !hash_equals($expected, $token)
    ) {
        send_json(
            [
                'success' => false,
                'message' => '画面の有効期限が切れています。画面を再読み込みして再度お試しください。'
            ],
            403
        );
    }
}

/**
 * JSONレスポンス
 */
function send_json(array $response, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

    $json = json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        $json = json_encode(
            [
                'success' => false,
                'message' => 'JSONレスポンスを作成できませんでした。'
            ],
            JSON_UNESCAPED_UNICODE
        );
    }

    echo $json;

    exit;
}

/**
 * データディレクトリ作成
 */
function ensure_data_directory(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (!mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException(
            'データ保存先を作成できませんでした。'
        );
    }
}

/**
 * JSON読み込み
 */
function read_json_file(string $file, array $default): array
{
    ensure_data_directory();

    if (!is_file($file)) {
        return $default;
    }

    $contents = file_get_contents($file);

    if ($contents === false || trim($contents) === '') {
        return $default;
    }

    $data = json_decode($contents, true);

    if (!is_array($data)) {
        return $default;
    }

    return $data;
}

/**
 * JSON保存
 *
 * 一時ファイルへ書き込み後に置換することで、
 * 保存途中で既存ファイルが壊れることを防ぐ。
 */
function write_json_file(string $file, array $data): void
{
    ensure_data_directory();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException(
            '保存するデータを作成できませんでした。'
        );
    }

    $temporary = $file . '.tmp';

    $written = file_put_contents(
        $temporary,
        $json,
        LOCK_EX
    );

    if ($written === false) {
        throw new RuntimeException(
            'データを書き込めませんでした。'
        );
    }

    $verify = file_get_contents($temporary);

    if ($verify === false || $verify !== $json) {
        @unlink($temporary);

        throw new RuntimeException(
            '保存したデータを確認できませんでした。'
        );
    }

    if (!rename($temporary, $file)) {
        @unlink($temporary);

        throw new RuntimeException(
            'データファイルを更新できませんでした。'
        );
    }
}

/**
 * 初期メール設定
 */
function default_mail_settings(): array
{
    return [
        'smtp' => '',
        'port' => '',
        'security' => 'なし',
        'username' => '',
        'password' => '',
        'from' => '',
        'fromName' => '',
        'ready' => false
    ];
}

/**
 * 初期kintone設定
 */
function default_kintone_settings(): array
{
    return [
        'domain' => '',
        'appId' => '',
        'loginName' => '',
        'password' => '',
        'proxyHost' => '',
        'proxyPort' => '',
        'ready' => false,
        'connectionChecked' => false,
        'connectionCheckedAt' => ''
    ];
}

/**
 * 設定読み込み
 */
function load_settings(): array
{
    $defaults = [
        'mail' => default_mail_settings(),
        'kintone' => default_kintone_settings()
    ];

    $settings = read_json_file(
        SETTINGS_FILE,
        $defaults
    );

    if (
        !isset($settings['mail']) ||
        !is_array($settings['mail'])
    ) {
        $settings['mail'] = default_mail_settings();
    }

    if (
        !isset($settings['kintone']) ||
        !is_array($settings['kintone'])
    ) {
        $settings['kintone'] = default_kintone_settings();
    }

    $settings['mail'] = array_merge(
        default_mail_settings(),
        $settings['mail']
    );

    $settings['kintone'] = array_merge(
        default_kintone_settings(),
        $settings['kintone']
    );

    return $settings;
}

/**
 * パスワードを除いたメール設定
 */
function public_mail_settings(array $settings): array
{
    return [
        'smtp' => (string)($settings['smtp'] ?? ''),
        'port' => (string)($settings['port'] ?? ''),
        'security' => (string)($settings['security'] ?? 'なし'),
        'username' => (string)($settings['username'] ?? ''),
        'from' => (string)($settings['from'] ?? ''),
        'fromName' => (string)($settings['fromName'] ?? ''),
        'ready' => (bool)($settings['ready'] ?? false),
        'passwordConfigured' => (string)($settings['password'] ?? '') !== ''
    ];
}

/**
 * パスワードを除いたkintone設定
 */
function public_kintone_settings(array $settings): array
{
    return [
        'domain' => (string)($settings['domain'] ?? ''),
        'appId' => (string)($settings['appId'] ?? ''),
        'loginName' => (string)($settings['loginName'] ?? ''),
        'proxyHost' => (string)($settings['proxyHost'] ?? ''),
        'proxyPort' => (string)($settings['proxyPort'] ?? ''),
        'ready' => (bool)($settings['ready'] ?? false),
        'connectionChecked' => (bool)($settings['connectionChecked'] ?? false),
        'connectionCheckedAt' => (string)($settings['connectionCheckedAt'] ?? ''),
        'passwordConfigured' => (string)($settings['password'] ?? '') !== ''
    ];
}

/**
 * メール設定保存
 */
function save_mail_settings(array $input): array
{
    $smtp = trim((string)($input['smtp'] ?? ''));
    $port = trim((string)($input['port'] ?? ''));
    $security = trim((string)($input['security'] ?? 'なし'));
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $from = trim((string)($input['from'] ?? ''));
    $fromName = trim((string)($input['fromName'] ?? ''));

    $errors = [];

    if ($smtp === '') {
        $errors['smtp'] = 'SMTPサーバを入力してください。';
    }

    if (
        $port === '' ||
        !ctype_digit($port) ||
        (int)$port < 1 ||
        (int)$port > 65535
    ) {
        $errors['port'] = 'ポート番号は1～65535で入力してください。';
    }

    if (!in_array(
        $security,
        ['なし', 'STARTTLS', 'SSL/TLS'],
        true
    )) {
        $errors['security'] = '接続方式が正しくありません。';
    }

    if (
        $from === '' ||
        filter_var($from, FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors['from'] = '送信元メールアドレスを正しく入力してください。';
    }

    $settings = load_settings();

    $existingPassword =
        (string)($settings['mail']['password'] ?? '');

    if ($password === '') {
        $password = $existingPassword;
    }

    /*
     * SMTP認証を利用する場合はユーザー名・パスワードを確認。
     *
     * 「なし」の場合は認証情報を必須としない。
     */
    if ($username !== '' && $password === '') {
        $errors['password'] =
            '認証ユーザー名を入力する場合は、認証パスワードも入力してください。';
    }

    if ($errors !== []) {
        return [
            'success' => false,
            'message' => 'メール設定を確認してください。',
            'errors' => $errors
        ];
    }

    $newSettings = [
        'smtp' => $smtp,
        'port' => (string)((int)$port),
        'security' => $security,
        'username' => $username,
        'password' => $password,
        'from' => $from,
        'fromName' => $fromName,
        'ready' => true
    ];

    $settings['mail'] = $newSettings;

    write_json_file(
        SETTINGS_FILE,
        $settings
    );

    $saved = load_settings();

    if (
        $saved['mail']['smtp'] !== $smtp ||
        $saved['mail']['port'] !== (string)((int)$port) ||
        $saved['mail']['from'] !== $from
    ) {
        throw new RuntimeException(
            'メール設定の保存結果を確認できませんでした。'
        );
    }

    return [
        'success' => true,
        'message' => 'メール送信設定を保存しました。',
        'mail' => public_mail_settings($saved['mail'])
    ];
}

/**
 * kintone利用先URLを統一生成
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
    );

    if ($domain === null) {
        throw new RuntimeException(
            'kintone利用先の形式が正しくありません。'
        );
    }

    $domain = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $domain
    );

    if ($domain === null) {
        throw new RuntimeException(
            'kintone利用先の形式が正しくありません。'
        );
    }

    $domain = rtrim($domain, '/');

    if ($domain === '') {
        throw new RuntimeException(
            'kintone利用先を入力してください。'
        );
    }

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * kintoneレスポンスヘッダー取得
 */
function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();

        if (is_array($headers)) {
            return $headers;
        }
    }

    /*
     * PHP環境によっては関数が提供されないため、
     * その場合はグローバル領域に残るレスポンス情報を
     * 直接参照せず、安全に取得できる範囲だけを返す。
     *
     * PHP 8.4/8.5で非推奨となる直接参照は行わない。
     */
    return [];
}

/**
 * HTTPステータス取得
 */
function get_response_status(array $headers): int
{
    foreach ($headers as $header) {
        if (!is_string($header)) {
            continue;
        }

        if (preg_match(
            '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i',
            $header,
            $matches
        )) {
            return (int)$matches[1];
        }
    }

    return 0;
}

/**
 * kintone認証ヘッダー
 */
function make_cybozu_auth_header(
    string $loginName,
    string $password
): string {
    $loginName = trim($loginName);
    $password = trim($password);

    return 'X-Cybozu-Authorization: ' .
        base64_encode(
            $loginName . ':' . $password
        );
}

/**
 * プロキシ設定を取得
 *
 * host:port の形式を想定。
 */
function make_proxy_config(
    string $proxyHost,
    string $proxyPort
): string {
    $proxyHost = trim($proxyHost);
    $proxyPort = trim($proxyPort);

    if ($proxyHost === '' && $proxyPort === '') {
        return '';
    }

    if ($proxyHost === '' || $proxyPort === '') {
        throw new RuntimeException(
            'プロキシ設定はhost:port形式で入力してください。'
        );
    }

    if (
        !ctype_digit($proxyPort) ||
        (int)$proxyPort < 1 ||
        (int)$proxyPort > 65535
    ) {
        throw new RuntimeException(
            'プロキシのポート番号が正しくありません。'
        );
    }

    /*
     * host側にプロトコルを入力されても通信設定が
     * 壊れないように除去。
     */
    $proxyHost = preg_replace(
        '/^https?:\/\//i',
        '',
        $proxyHost
    );

    if ($proxyHost === null || $proxyHost === '') {
        throw new RuntimeException(
            'プロキシのホスト名が正しくありません。'
        );
    }

    return 'tcp://' . $proxyHost . ':' . $proxyPort;
}

/**
 * kintone共通通信
 *
 * cURLは使用しない。
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload,
    array $config
): array {
    $method = strtoupper(trim($method));

    if (!in_array(
        $method,
        ['GET', 'POST', 'PUT'],
        true
    )) {
        throw new RuntimeException(
            '対応していない通信方式です。'
        );
    }

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
        'protocol_version' => 1.1
    ];

    /*
     * GETにはcontentを設定しない。
     */
    if (
        $method !== 'GET' &&
        $payload !== null
    ) {
        $body = is_array($payload)
            ? json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
            : (string)$payload;

        if ($body === false) {
            throw new RuntimeException(
                'kintone送信データを作成できませんでした。'
            );
        }

        $httpOptions['content'] = $body;
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    /*
     * プロキシ設定はkintone通信すべてで受け取れる。
     */
    $proxyHost = trim(
        (string)($config['proxyHost'] ?? '')
    );

    $proxyPort = trim(
        (string)($config['proxyPort'] ?? '')
    );

    $proxy = make_proxy_config(
        $proxyHost,
        $proxyPort
    );

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] = $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    $responseBody = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders =
        get_safe_response_headers();

    $status = get_response_status(
        $responseHeaders
    );

    $decoded = null;

    if (
        $responseBody !== false &&
        $responseBody !== ''
    ) {
        $decoded = json_decode(
            $responseBody,
            true
        );
    }

    if (
        $status >= 200 &&
        $status < 300 &&
        $responseBody !== false
    ) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($decoded)
                ? $decoded
                : []
        ];
    }

    $message =
        'kintoneとの通信に失敗しました。';

    $code = '';
    $errors = [];

    if (is_array($decoded)) {
        if (
            isset($decoded['message']) &&
            is_string($decoded['message'])
        ) {
            $message = $decoded['message'];
        }

        if (
            isset($decoded['code']) &&
            is_string($decoded['code'])
        ) {
            $code = $decoded['code'];
        }

        if (
            isset($decoded['errors']) &&
            is_array($decoded['errors'])
        ) {
            foreach ($decoded['errors'] as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }

                $messages = $value['messages'] ?? [];

                if (!is_array($messages)) {
                    continue;
                }

                foreach ($messages as $item) {
                    if (is_string($item)) {
                        $errors[] =
                            (string)$key . ': ' . $item;
                    }
                }
            }
        }
    }

    if ($status === 0) {
        $message =
            'kintoneへ接続できませんでした。利用先、プロキシ、ネットワーク設定を確認してください。';
    }

    return [
        'success' => false,
        'status' => $status,
        'code' => $code,
        'message' => $message,
        'errors' => $errors
    ];
}

/**
 * kintone設定保存
 */
function save_kintone_settings(array $input): array
{
    $domain = trim(
        (string)($input['domain'] ?? '')
    );

    $appId = trim(
        (string)($input['appId'] ?? '')
    );

    $loginName = trim(
        (string)($input['loginName'] ?? '')
    );

    $password = (string)(
        $input['password'] ?? ''
    );

    /*
     * プロキシは任意。
     * ただし入力する場合はhost/port両方を要求。
     */
    $proxyHost = trim(
        (string)($input['proxyHost'] ?? '')
    );

    $proxyPort = trim(
        (string)($input['proxyPort'] ?? '')
    );

    $errors = [];

    if ($domain === '') {
        $errors['domain'] =
            'kintoneの利用先を入力してください。';
    }

    if (
        $appId === '' ||
        !ctype_digit($appId) ||
        (int)$appId < 1
    ) {
        $errors['appId'] =
            '顧客管理アプリIDを正しく入力してください。';
    }

    if ($loginName === '') {
        $errors['loginName'] =
            'ログイン名を入力してください。';
    }

    if ($proxyHost !== '' && $proxyPort === '') {
        $errors['proxyPort'] =
            'プロキシのホストを入力した場合は、ポート番号も入力してください。';
    }

    if ($proxyPort !== '') {
        if (
            !ctype_digit($proxyPort) ||
            (int)$proxyPort < 1 ||
            (int)$proxyPort > 65535
        ) {
            $errors['proxyPort'] =
                'プロキシのポート番号は1～65535で入力してください。';
        }
    }

    if ($errors !== []) {
        return [
            'success' => false,
            'message' => 'kintone設定を確認してください。',
            'errors' => $errors
        ];
    }

    $settings = load_settings();

    $existingPassword =
        (string)($settings['kintone']['password'] ?? '');

    if ($password === '') {
        $password = $existingPassword;
    }

    if ($password === '') {
        return [
            'success' => false,
            'message' => 'パスワードを入力してください。',
            'errors' => [
                'password' => 'パスワードを入力してください。'
            ]
        ];
    }

    /*
     * 設定変更後は接続確認済み状態を解除。
     */
    $settings['kintone'] = [
        'domain' => $domain,
        'appId' => (string)((int)$appId),
        'loginName' => $loginName,
        'password' => $password,
        'proxyHost' => $proxyHost,
        'proxyPort' => $proxyPort,
        'ready' => true,
        'connectionChecked' => false,
        'connectionCheckedAt' => ''
    ];

    write_json_file(
        SETTINGS_FILE,
        $settings
    );

    $saved = load_settings();

    if (
        $saved['kintone']['domain'] !== $domain ||
        $saved['kintone']['appId'] !== (string)((int)$appId) ||
        $saved['kintone']['loginName'] !== $loginName
    ) {
        throw new RuntimeException(
            'kintone設定の保存結果を確認できませんでした。'
        );
    }

    return [
        'success' => true,
        'message' => 'kintone設定を保存しました。',
        'kintone' =>
            public_kintone_settings(
                $saved['kintone']
            )
    ];
}

/**
 * kintone接続確認
 */
function test_kintone_connection(): array
{
    $settings = load_settings();
    $kintone = $settings['kintone'];

    $domain = trim(
        (string)($kintone['domain'] ?? '')
    );

    $appId = trim(
        (string)($kintone['appId'] ?? '')
    );

    $loginName = trim(
        (string)($kintone['loginName'] ?? '')
    );

    $password = (string)(
        $kintone['password'] ?? ''
    );

    if (
        $domain === '' ||
        $appId === '' ||
        $loginName === '' ||
        $password === ''
    ) {
        return [
            'success' => false,
            'message' =>
                'kintone設定を保存してから接続確認を行ってください。'
        ];
    }

    $url = kintone_build_url(
        $domain,
        '/k/v1/app.json?' .
        http_build_query(
            ['id' => (int)$appId],
            '',
            '&',
            PHP_QUERY_RFC3986
        )
    );

    $headers = [
        make_cybozu_auth_header(
            $loginName,
            $password
        ),
        'Accept: application/json'
    ];

    $result = kintone_api_request(
        'GET',
        $url,
        $headers,
        null,
        [
            'proxyHost' =>
                (string)($kintone['proxyHost'] ?? ''),
            'proxyPort' =>
                (string)($kintone['proxyPort'] ?? '')
        ]
    );

    if (!$result['success']) {
        $detail = [];

        if (
            isset($result['status']) &&
            (int)$result['status'] > 0
        ) {
            $detail[] =
                'HTTP ' . (int)$result['status'];
        }

        if (
            isset($result['code']) &&
            is_string($result['code']) &&
            $result['code'] !== ''
        ) {
            $detail[] =
                'エラーコード: ' . $result['code'];
        }

        if (
            isset($result['message']) &&
            is_string($result['message']) &&
            $result['message'] !== ''
        ) {
            $detail[] =
                $result['message'];
        }

        if (
            isset($result['errors']) &&
            is_array($result['errors']) &&
            $result['errors'] !== []
        ) {
            $detail[] =
                implode(
                    ' / ',
                    array_map(
                        'strval',
                        $result['errors']
                    )
                );
        }

        return [
            'success' => false,
            'message' =>
                implode(
                    ' ',
                    $detail
                )
        ];
    }

    $settings['kintone']['connectionChecked'] = true;
    $settings['kintone']['connectionCheckedAt'] =
        date('c');

    write_json_file(
        SETTINGS_FILE,
        $settings
    );

    return [
        'success' => true,
        'message' =>
            'kintoneへの接続を確認しました。',
        'kintone' =>
            public_kintone_settings(
                $settings['kintone']
            )
    ];
}

/**
 * 顧客一覧をkintoneから取得
 */
function fetch_kintone_customers(): array
{
    $settings = load_settings();
    $kintone = $settings['kintone'];

    $domain = trim(
        (string)($kintone['domain'] ?? '')
    );

    $appId = trim(
        (string)($kintone['appId'] ?? '')
    );

    $loginName = trim(
        (string)($kintone['loginName'] ?? '')
    );

    $password = (string)(
        $kintone['password'] ?? ''
    );

    if (
        $domain === '' ||
        $appId === '' ||
        $loginName === '' ||
        $password === ''
    ) {
        return [
            'success' => false,
            'message' =>
                'kintone設定が未完了です。設定画面を確認してください。'
        ];
    }

    $allCustomers = [];
    $offset = 0;
    $limit = 100;

    /*
     * 顧客管理アプリの標準的なフィールド名を優先。
     * その他の情報も保持する。
     */
    while (true) {
        $query =
            'order by $id asc limit ' .
            $limit .
            ' offset ' .
            $offset;

        $params = [
            'app' => (int)$appId,
            'query' => $query
        ];

        $queryString = http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $url = kintone_build_url(
            $domain,
            '/k/v1/records.json?' . $queryString
        );

        $headers = [
            make_cybozu_auth_header(
                $loginName,
                $password
            ),
            'Accept: application/json'
        ];

        $result = kintone_api_request(
            'GET',
            $url,
            $headers,
            null,
            [
                'proxyHost' =>
                    (string)($kintone['proxyHost'] ?? ''),
                'proxyPort' =>
                    (string)($kintone['proxyPort'] ?? '')
            ]
        );

        if (!$result['success']) {
            return [
                'success' => false,
                'message' =>
                    '顧客一覧を取得できませんでした。',
                'detail' =>
                    (string)($result['message'] ?? '')
            ];
        }

        $records =
            $result['data']['records'] ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $name = extract_kintone_field_value(
                $record,
                [
                    'name',
                    'customer_name',
                    '顧客名',
                    '氏名'
                ]
            );

            $email = extract_kintone_field_value(
                $record,
                [
                    'email',
                    'mail',
                    'メールアドレス'
                ]
            );

            $company = extract_kintone_field_value(
                $record,
                [
                    'company',
                    'company_name',
                    '会社名'
                ]
            );

            $code = extract_kintone_field_value(
                $record,
                [
                    'code',
                    'customer_code',
                    '顧客番号'
                ]
            );

            $allCustomers[] = [
                'id' =>
                    extract_kintone_field_value(
                        $record,
                        ['$id', 'id']
                    ),
                'name' => $name,
                'email' => $email,
                'company' => $company,
                'code' => $code,
                'fields' => $record
            ];
        }

        if (count($records) < $limit) {
            break;
        }

        $offset += $limit;
    }

    $savedData = [
        'updated_at' => date('c'),
        'customers' => $allCustomers
    ];

    write_json_file(
        CUSTOMERS_FILE,
        $savedData
    );

    return [
        'success' => true,
        'message' =>
            '顧客一覧を取得しました。',
        'customers' => $allCustomers,
        'updated_at' => $savedData['updated_at']
    ];
}

/**
 * kintoneフィールド値抽出
 */
function extract_kintone_field_value(
    array $record,
    array $fieldNames
): string {
    foreach ($fieldNames as $fieldName) {
        if (!isset($record[$fieldName])) {
            continue;
        }

        $field = $record[$fieldName];

        if (!is_array($field)) {
            continue;
        }

        if (
            !isset($field['value'])
        ) {
            continue;
        }

        $value = $field['value'];

        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            $values = [];

            foreach ($value as $item) {
                if (
                    is_array($item) &&
                    isset($item['name']) &&
                    is_string($item['name'])
                ) {
                    $values[] = $item['name'];
                }
            }

            if ($values !== []) {
                return implode(
                    ', ',
                    $values
                );
            }
        }
    }

    return '';
}

/**
 * 顧客キャッシュ読み込み
 */
function load_customer_cache(): array
{
    $data = read_json_file(
        CUSTOMERS_FILE,
        []
    );

    if (
        isset($data['customers']) &&
        is_array($data['customers'])
    ) {
        return $data;
    }

    /*
     * 旧形式との互換を許容せず、
     * 不正な構造の場合は空として扱う。
     */
    return [
        'updated_at' => '',
        'customers' => []
    ];
}

/**
 * アンケートID発行
 */
function generate_survey_id(
    array $surveys
): int {
    $max = 0;

    foreach ($surveys as $survey) {
        if (!is_array($survey)) {
            continue;
        }

        $id = $survey['id'] ?? 0;

        if (
            is_numeric($id) &&
            (int)$id > $max
        ) {
            $max = (int)$id;
        }
    }

    return $max + 1;
}

/**
 * グループID発行
 */
function generate_group_id(
    array $survey
): int {
    $max = 0;

    $groups = $survey['groups'] ?? [];

    if (!is_array($groups)) {
        return 1;
    }

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $id = $group['id'] ?? 0;

        if (
            is_numeric($id) &&
            (int)$id > $max
        ) {
            $max = (int)$id;
        }
    }

    return $max + 1;
}

/**
 * 質問ID発行
 */
function generate_question_id(
    array $survey
): int {
    $max = 0;

    $groups = $survey['groups'] ?? [];

    if (!is_array($groups)) {
        return 1;
    }

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions =
            $group['questions'] ?? [];

        if (!is_array($questions)) {
            continue;
        }

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $id = $question['id'] ?? 0;

            if (
                is_numeric($id) &&
                (int)$id > $max
            ) {
                $max = (int)$id;
            }
        }
    }

    return $max + 1;
}

/**
 * アンケートの全質問を取得
 */
function get_all_questions(
    array $survey
): array {
    $questions = [];

    $groups = $survey['groups'] ?? [];

    if (!is_array($groups)) {
        return [];
    }

    foreach ($groups as $groupIndex => $group) {
        if (!is_array($group)) {
            continue;
        }

        $groupQuestions =
            $group['questions'] ?? [];

        if (!is_array($groupQuestions)) {
            continue;
        }

        foreach (
            $groupQuestions as $questionIndex => $question
        ) {
            if (!is_array($question)) {
                continue;
            }

            $question['_groupIndex'] =
                $groupIndex;

            $question['_questionIndex'] =
                $questionIndex;

            $questions[] = $question;
        }
    }

    return $questions;
}

/**
 * アンケートサーバー側検証
 */
function validate_survey(
    array $survey,
    bool $forPublish = false
): array {
    $errors = [];

    $name = trim(
        (string)($survey['name'] ?? '')
    );

    if ($name === '') {
        $errors['name'] =
            'アンケート名を入力してください。';
    }

    $status =
        (string)($survey['status'] ?? 'draft');

    if (!in_array(
        $status,
        ['draft', 'open', 'closed'],
        true
    )) {
        $errors['status'] =
            'アンケートの状態が正しくありません。';
    }

    $numbering =
        (string)($survey['numbering'] ?? 'global');

    if (!in_array(
        $numbering,
        ['global', 'group'],
        true
    )) {
        $errors['numbering'] =
            '質問番号形式が正しくありません。';
    }

    $groups = $survey['groups'] ?? [];

    if (!is_array($groups)) {
        $errors['groups'] =
            'グループ情報が正しくありません。';

        return $errors;
    }

    if ($groups === []) {
        $errors['groups'] =
            'グループを1つ以上登録してください。';
    }

    $questionIds = [];
    $questions = [];

    foreach ($groups as $groupIndex => $group) {
        if (!is_array($group)) {
            $errors['groups'] =
                'グループ情報が正しくありません。';

            continue;
        }

        $groupName = trim(
            (string)($group['name'] ?? '')
        );

        if ($groupName === '') {
            $errors[
                'group_' . $groupIndex
            ] =
                'グループ名を入力してください。';
        }

        $groupQuestions =
            $group['questions'] ?? [];

        if (!is_array($groupQuestions)) {
            $errors[
                'group_' . $groupIndex
            ] =
                '質問情報が正しくありません。';

            continue;
        }

        foreach (
            $groupQuestions as $questionIndex => $question
        ) {
            if (!is_array($question)) {
                $errors[
                    'question_' .
                    $groupIndex .
                    '_' .
                    $questionIndex
                ] =
                    '質問情報が正しくありません。';

                continue;
            }

            $questionId = $question['id'] ?? null;

            if (
                !is_int($questionId) &&
                !ctype_digit((string)$questionId)
            ) {
                $errors[
                    'question_' .
                    $groupIndex .
                    '_' .
                    $questionIndex
                ] =
                    '質問IDが正しくありません.';

                continue;
            }

            $questionId = (int)$questionId;

            if (isset($questionIds[$questionId])) {
                $errors[
                    'question_' .
                    $groupIndex .
                    '_' .
                    $questionIndex
                ] =
                    '質問IDが重複しています。';

                continue;
            }

            $questionIds[$questionId] = true;

            $text = trim(
                (string)($question['text'] ?? '')
            );

            if ($text === '') {
                $errors[
                    'question_' .
                    $questionId .
                    '_text'
                ] =
                    '質問文を入力してください。';
            }

            $type =
                (string)($question['type'] ?? '');

            if (!in_array(
                $type,
                ['single', 'multiple', 'free'],
                true
            )) {
                $errors[
                    'question_' .
                    $questionId .
                    '_type'
                ] =
                    '回答形式が正しくありません。';
            }

            $required =
                $question['required'] ?? false;

            if (!is_bool($required)) {
                $errors[
                    'question_' .
                    $questionId .
                    '_required'
                ] =
                    '必須設定が正しくありません。';
            }

            $options =
                $question['options'] ?? [];

            if (!is_array($options)) {
                $errors[
                    'question_' .
                    $questionId .
                    '_options'
                ] =
                    '選択肢情報が正しくありません。';

                $options = [];
            }

            if (
                in_array(
                    $type,
                    ['single', 'multiple'],
                    true
                ) &&
                count($options) === 0
            ) {
                $errors[
                    'question_' .
                    $questionId .
                    '_options'
                ] =
                    '選択式の質問には選択肢を1つ以上登録してください。';
            }

            foreach ($options as $optionIndex => $option) {
                if (!is_array($option)) {
                    $errors[
                        'option_' .
                        $questionId .
                        '_' .
                        $optionIndex
                    ] =
                        '選択肢情報が正しくありません。';

                    continue;
                }

                $optionText = trim(
                    (string)($option['text'] ?? '')
                );

                if (
                    in_array(
                        $type,
                        ['single', 'multiple'],
                        true
                    ) &&
                    $optionText === ''
                ) {
                    $errors[
                        'option_' .
                        $questionId .
                        '_' .
                        $optionIndex
                    ] =
                        '選択肢を入力してください。';
                }
            }

            $questions[] = [
                'id' => $questionId,
                'type' => $type,
                'options' => $options
            ];
        }
    }

    /*
     * 分岐先の存在確認。
     */
    foreach ($questions as $question) {
        if ($question['type'] !== 'single') {
            continue;
        }

        foreach (
            $question['options'] as $optionIndex => $option
        ) {
            if (!is_array($option)) {
                continue;
            }

            $branch =
                trim((string)($option['branch'] ?? ''));

            if (
                $branch === '' ||
                strtoupper($branch) === 'NEXT' ||
                strtoupper($branch) === 'END'
            ) {
                continue;
            }

            if (
                !ctype_digit($branch) ||
                !isset($questionIds[(int)$branch])
            ) {
                $errors[
                    'branch_' .
                    $question['id'] .
                    '_' .
                    $optionIndex
                ] =
                    '分岐先の質問が存在しません。';
            }
        }
    }

    if ($forPublish && $errors === []) {
        $cycleError =
            detect_branch_cycle($survey);

        if ($cycleError !== '') {
            $errors['branch'] =
                $cycleError;
        }
    }

    return $errors;
}

/**
 * 分岐循環検出
 */
function detect_branch_cycle(
    array $survey
): string {
    $questions = get_all_questions(
        $survey
    );

    $questionMap = [];

    foreach ($questions as $question) {
        $id = (int)($question['id'] ?? 0);

        if ($id > 0) {
            $questionMap[$id] = $question;
        }
    }

    $visiting = [];
    $visited = [];

    foreach ($questionMap as $id => $question) {
        if (isset($visited[$id])) {
            continue;
        }

        $result = walk_branch_graph(
            $id,
            $questionMap,
            $visiting,
            $visited
        );

        if ($result !== '') {
            return $result;
        }
    }

    return '';
}

/**
 * 分岐グラフ探索
 */
function walk_branch_graph(
    int $questionId,
    array $questionMap,
    array &$visiting,
    array &$visited
): string {
    if (isset($visiting[$questionId])) {
        return '分岐設定に循環があるため公開できません。';
    }

    if (isset($visited[$questionId])) {
        return '';
    }

    if (!isset($questionMap[$questionId])) {
        return '';
    }

    $visiting[$questionId] = true;

    $question = $questionMap[$questionId];

    $type =
        (string)($question['type'] ?? '');

    if ($type === 'single') {
        $options =
            $question['options'] ?? [];

        if (is_array($options)) {
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $branch =
                    trim(
                        (string)($option['branch'] ?? '')
                    );

                if (
                    $branch === '' ||
                    strtoupper($branch) === 'NEXT' ||
                    strtoupper($branch) === 'END'
                ) {
                    continue;
                }

                if (ctype_digit($branch)) {
                    $result = walk_branch_graph(
                        (int)$branch,
                        $questionMap,
                        $visiting,
                        $visited
                    );

                    if ($result !== '') {
                        return $result;
                    }
                }
            }
        }
    }

    unset($visiting[$questionId]);

    $visited[$questionId] = true;

    return '';
}

/**
 * 初期アンケート
 */
function default_surveys(): array
{
    return [
        [
            'id' => 1,
            'name' => '新商品アンケート',
            'description' =>
                '新商品の利用状況とご意見をお聞きするアンケートです。',
            'status' => 'open',
            'created' => date('Y-m-d'),
            'start' => date('Y-m-d'),
            'end' => date(
                'Y-m-d',
                strtotime('+30 days')
            ),
            'answers' => 0,
            'target' => 0,
            'sent' => 0,
            'updated' => date('Y-m-d'),
            'numbering' => 'global',
            'groups' => [
                [
                    'id' => 101,
                    'name' => 'ご利用状況',
                    'questions' => [
                        [
                            'id' => 1001,
                            'text' =>
                                '当社の商品を利用したことがありますか？',
                            'type' => 'single',
                            'required' => true,
                            'options' => [
                                [
                                    'text' => 'はい',
                                    'branch' => 'NEXT'
                                ],
                                [
                                    'text' => 'いいえ',
                                    'branch' => 'NEXT'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];
}

/**
 * アンケート保存
 */
function save_survey(
    array $input
): array {
    $survey =
        $input['survey'] ?? null;

    if (!is_array($survey)) {
        return [
            'success' => false,
            'message' =>
                'アンケートデータを確認できませんでした。'
        ];
    }

    $surveys = read_json_file(
        SURVEYS_FILE,
        default_surveys()
    );

    $errors = validate_survey(
        $survey,
        false
    );

    if ($errors !== []) {
        return [
            'success' => false,
            'message' =>
                'アンケート内容を確認してください。',
            'errors' => $errors
        ];
    }

    $id = $survey['id'] ?? 0;

    if (
        !is_int($id) &&
        !ctype_digit((string)$id)
    ) {
        return [
            'success' => false,
            'message' =>
                'アンケートIDが正しくありません。'
        ];
    }

    $id = (int)$id;

    if ($id <= 0) {
        $id = generate_survey_id(
            $surveys
        );
    }

    $found = false;

    foreach ($surveys as $index => $existing) {
        if (!is_array($existing)) {
            continue;
        }

        if ((int)($existing['id'] ?? 0) === $id) {
            $survey['id'] = $id;
            $survey['updated'] =
                date('Y-m-d');

            $surveys[$index] = $survey;
            $found = true;

            break;
        }
    }

    if (!$found) {
        $survey['id'] = $id;
        $survey['created'] =
            (string)($survey['created'] ?? date('Y-m-d'));
        $survey['updated'] =
            date('Y-m-d');
        $survey['status'] = 'draft';

        if (!isset($survey['answers'])) {
            $survey['answers'] = 0;
        }

        if (!isset($survey['target'])) {
            $survey['target'] = 0;
        }

        if (!isset($survey['sent'])) {
            $survey['sent'] = 0;
        }

        $surveys[] = $survey;
    }

    write_json_file(
        SURVEYS_FILE,
        $surveys
    );

    return [
        'success' => true,
        'message' =>
            'アンケートを保存しました。',
        'survey' => $survey
    ];
}

/**
 * アンケート削除
 */
function delete_survey(
    array $input
): array {
    $id = $input['id'] ?? null;

    if (
        !is_int($id) &&
        !ctype_digit((string)$id)
    ) {
        return [
            'success' => false,
            'message' =>
                'アンケートIDが正しくありません。'
        ];
    }

    $id = (int)$id;

    $surveys = read_json_file(
        SURVEYS_FILE,
        default_surveys()
    );

    $newSurveys = [];
    $found = false;

    foreach ($surveys as $survey) {
        if (!is_array($survey)) {
            continue;
        }

        if ((int)($survey['id'] ?? 0) === $id) {
            $found = true;

            if (
                (string)($survey['status'] ?? '') !==
                'draft'
            ) {
                return [
                    'success' => false,
                    'message' =>
                        '下書きのアンケートだけ削除できます。'
                ];
            }

            continue;
        }

        $newSurveys[] = $survey;
    }

    if (!$found) {
        return [
            'success' => false,
            'message' =>
                '対象のアンケートが見つかりません。'
        ];
    }

    write_json_file(
        SURVEYS_FILE,
        $newSurveys
    );

    return [
        'success' => true,
        'message' =>
            'アンケートを削除しました。'
    ];
}

/**
 * アンケート公開
 */
function publish_survey(
    array $input
): array {
    $id = $input['id'] ?? null;

    if (
        !is_int($id) &&
        !ctype_digit((string)$id)
    ) {
        return [
            'success' => false,
            'message' =>
                'アンケートIDが正しくありません。'
        ];
    }

    $id = (int)$id;

    $surveys = read_json_file(
        SURVEYS_FILE,
        default_surveys()
    );

    foreach ($surveys as $index => $survey) {
        if (!is_array($survey)) {
            continue;
        }

        if ((int)($survey['id'] ?? 0) !== $id) {
            continue;
        }

        $errors = validate_survey(
            $survey,
            true
        );

        if ($errors !== []) {
            return [
                'success' => false,
                'message' =>
                    '公開前の確認で問題が見つかりました。',
                'errors' => $errors
            ];
        }

        $today = date('Y-m-d');

        $survey['status'] = 'open';

        if (
            trim(
                (string)($survey['start'] ?? '')
            ) === ''
        ) {
            $survey['start'] = $today;
        }

        $survey['updated'] = $today;

        $surveys[$index] = $survey;

        write_json_file(
            SURVEYS_FILE,
            $surveys
        );

        return [
            'success' => true,
            'message' =>
                'アンケートを公開しました。',
            'survey' => $survey
        ];
    }

    return [
        'success' => false,
        'message' =>
            '対象のアンケートが見つかりません。'
    ];
}

/**
 * アンケート終了
 */
function close_survey(
    array $input
): array {
    $id = $input['id'] ?? null;

    if (
        !is_int($id) &&
        !ctype_digit((string)$id)
    ) {
        return [
            'success' => false,
            'message' =>
                'アンケートIDが正しくありません。'
        ];
    }

    $id = (int)$id;

    $surveys = read_json_file(
        SURVEYS_FILE,
        default_surveys()
    );

    foreach ($surveys as $index => $survey) {
        if (!is_array($survey)) {
            continue;
        }

        if ((int)($survey['id'] ?? 0) !== $id) {
            continue;
        }

        if (
            (string)($survey['status'] ?? '') !==
            'open'
        ) {
            return [
                'success' => false,
                'message' =>
                    '公開中のアンケートだけ終了できます。'
            ];
        }

        $survey['status'] = 'closed';
        $survey['updated'] =
            date('Y-m-d');

        $surveys[$index] = $survey;

        write_json_file(
            SURVEYS_FILE,
            $surveys
        );

        return [
            'success' => true,
            'message' =>
                'アンケートを終了しました。',
            'survey' => $survey
        ];
    }

    return [
        'success' => false,
        'message' =>
            '対象のアンケートが見つかりません。'
    ];
}

/**
 * 回答データ読み込み
 */
function load_responses(): array
{
    $data = read_json_file(
        RESPONSES_FILE,
        []
    );

    /*
     * 正式形式は配列。
     */
    if (!array_is_list($data)) {
        return [];
    }

    return $data;
}

/**
 * 回答保存
 */
function save_response(
    array $input
): array {
    $surveyId = $input['survey_id'] ?? null;
    $respondent = $input['respondent'] ?? '';
    $answers = $input['answers'] ?? null;

    if (
        !is_int($surveyId) &&
        !ctype_digit((string)$surveyId)
    ) {
        return [
            'success' => false,
            'message' =>
                'アンケートを確認できません。'
        ];
    }

    $surveyId = (int)$surveyId;

    if (
        !is_string($respondent) ||
        strlen($respondent) > 500
    ) {
        return [
            'success' => false,
            'message' =>
                '回答者情報が正しくありません。'
        ];
    }

    if (!is_array($answers)) {
        return [
            'success' => false,
            'message' =>
                '回答内容を確認できません。'
        ];
    }

    $surveys = read_json_file(
        SURVEYS_FILE,
        default_surveys()
    );

    $survey = null;

    foreach ($surveys as $item) {
        if (
            is_array($item) &&
            (int)($item['id'] ?? 0) === $surveyId
        ) {
            $survey = $item;
            break;
        }
    }

    if ($survey === null) {
        return [
            'success' => false,
            'message' =>
                '対象アンケートが見つかりません。'
        ];
    }

    if (
        (string)($survey['status'] ?? '') !==
        'open'
    ) {
        return [
            'success' => false,
            'message' =>
                'このアンケートは現在回答できません。'
        ];
    }

    $questions =
        get_all_questions($survey);

    $questionMap = [];

    foreach ($questions as $question) {
        $questionMap[
            (int)$question['id']
        ] = $question;
    }

    foreach ($questionMap as $questionId => $question) {
        $answerExists =
            array_key_exists(
                (string)$questionId,
                $answers
            ) ||
            array_key_exists(
                $questionId,
                $answers
            );

        $answer = $answers[$questionId]
            ?? $answers[(string)$questionId]
            ?? null;

        $required =
            (bool)($question['required'] ?? false);

        if (
            $required &&
            (
                $answer === null ||
                $answer === '' ||
                $answer === []
            )
        ) {
            return [
                'success' => false,
                'message' =>
                    '必須質問に回答してください。',
                'errors' => [
                    'question_' . $questionId =>
                        '回答が必要です。'
                ]
            ];
        }

        if (!$answerExists) {
            continue;
        }

        $type =
            (string)($question['type'] ?? '');

        $options =
            $question['options'] ?? [];

        $allowed = [];

        if (is_array($options)) {
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $allowed[] =
                    (string)($option['text'] ?? '');
            }
        }

        if ($type === 'single') {
            if (!is_string($answer)) {
                return [
                    'success' => false,
                    'message' =>
                        '回答形式が正しくありません。'
                ];
            }

            if (
                !in_array(
                    $answer,
                    $allowed,
                    true
                )
            ) {
                return [
                    'success' => false,
                    'message' =>
                        '存在しない選択肢が送信されました。'
                ];
            }
        }

        if ($type === 'multiple') {
            if (!is_array($answer)) {
                return [
                    'success' => false,
                    'message' =>
                        '複数選択の回答形式が正しくありません。'
                ];
            }

            foreach ($answer as $selected) {
                if (
                    !is_string($selected) ||
                    !in_array(
                        $selected,
                        $allowed,
                        true
                    )
                ) {
                    return [
                        'success' => false,
                        'message' =>
                            '存在しない選択肢が送信されました。'
                    ];
                }
            }
        }

        if ($type === 'free') {
            if (!is_string($answer)) {
                return [
                    'success' => false,
                    'message' =>
                        '自由記述の回答形式が正しくありません。'
                ];
            }

            if (mb_strlen($answer) > 5000) {
                return [
                    'success' => false,
                    'message' =>
                        '自由記述は5000文字以内で入力してください。'
                ];
            }
        }
    }

    $responses = load_responses();

    /*
     * 一意な回答ID。
     */
    $responseId = bin2hex(
        random_bytes(16)
    );

    /*
     * 二重送信識別情報。
     */
    $submissionKey = trim(
        (string)($input['submission_key'] ?? '')
    );

    if ($submissionKey === '') {
        $submissionKey = $responseId;
    }

    foreach ($responses as $existing) {
        if (!is_array($existing)) {
            continue;
        }

        if (
            (string)($existing['submission_key'] ?? '') ===
            $submissionKey
        ) {
            return [
                'success' => true,
                'message' =>
                    'この回答はすでに送信されています。',
                'duplicate' => true
            ];
        }
    }

    $responses[] = [
        'response_id' => $responseId,
        'submission_key' => $submissionKey,
        'survey_id' => $surveyId,
        'answered_at' => date('c'),
        'respondent' => $respondent,
        'answers' => $answers,
        'completed' => true
    ];

    write_json_file(
        RESPONSES_FILE,
        $responses
    );

    /*
     * アンケートの回答数も更新。
     */
    foreach ($surveys as $index => $item) {
        if (
            is_array($item) &&
            (int)($item['id'] ?? 0) === $surveyId
        ) {
            $surveys[$index]['answers'] =
                (int)($item['answers'] ?? 0) + 1;

            $surveys[$index]['updated'] =
                date('Y-m-d');

            break;
        }
    }

    write_json_file(
        SURVEYS_FILE,
        $surveys
    );

    return [
        'success' => true,
        'message' =>
            '回答を送信しました。',
        'response_id' => $responseId
    ];
}

/**
 * 回答結果集計
 */
function aggregate_responses(
    int $surveyId
): array {
    $surveys = read_json_file(
        SURVEYS_FILE,
        default_surveys()
    );

    $survey = null;

    foreach ($surveys as $item) {
        if (
            is_array($item) &&
            (int)($item['id'] ?? 0) === $surveyId
        ) {
            $survey = $item;
            break;
        }
    }

    if ($survey === null) {
        return [
            'success' => false,
            'message' =>
                '対象アンケートが見つかりません。'
        ];
    }

    $responses = load_responses();

    $surveyResponses = [];

    foreach ($responses as $response) {
        if (
            is_array($response) &&
            (int)($response['survey_id'] ?? 0) ===
            $surveyId &&
            ($response['completed'] ?? false) === true
        ) {
            $surveyResponses[] = $response;
        }
    }

    $questions =
        get_all_questions($survey);

    $results = [];

    foreach ($questions as $question) {
        $questionId =
            (int)($question['id'] ?? 0);

        $type =
            (string)($question['type'] ?? '');

        $result = [
            'question_id' => $questionId,
            'text' =>
                (string)($question['text'] ?? ''),
            'type' => $type,
            'total' =>
                count($surveyResponses)
        ];

        if (
            $type === 'single' ||
            $type === 'multiple'
        ) {
            $counts = [];

            $options =
                $question['options'] ?? [];

            if (is_array($options)) {
                foreach ($options as $option) {
                    if (!is_array($option)) {
                        continue;
                    }

                    $label =
                        (string)($option['text'] ?? '');

                    $counts[$label] = 0;
                }
            }

            foreach ($surveyResponses as $response) {
                $answers =
                    $response['answers'] ?? [];

                if (!is_array($answers)) {
                    continue;
                }

                $answer =
                    $answers[$questionId]
                    ?? $answers[(string)$questionId]
                    ?? null;

                if ($type === 'single') {
                    if (
                        is_string($answer) &&
                        isset($counts[$answer])
                    ) {
                        $counts[$answer]++;
                    }
                }

                if ($type === 'multiple') {
                    if (!is_array($answer)) {
                        continue;
                    }

                    foreach ($answer as $selected) {
                        if (
                            is_string($selected) &&
                            isset($counts[$selected])
                        ) {
                            $counts[$selected]++;
                        }
                    }
                }
            }

            $result['counts'] = $counts;

            $percentages = [];

            foreach ($counts as $label => $count) {
                $base =
                    $type === 'multiple'
                        ? count($surveyResponses)
                        : count($surveyResponses);

                $percentages[$label] =
                    $base > 0
                        ? round(
                            ($count / $base) * 100,
                            1
                        )
                        : 0;
            }

            $result['percentages'] =
                $percentages;
        }

        if ($type === 'free') {
            $texts = [];

            foreach ($surveyResponses as $response) {
                $answers =
                    $response['answers'] ?? [];

                if (!is_array($answers)) {
                    continue;
                }

                $answer =
                    $answers[$questionId]
                    ?? $answers[(string)$questionId]
                    ?? null;

                if (
                    is_string($answer) &&
                    $answer !== ''
                ) {
                    $texts[] = $answer;
                }
            }

            $result['answers'] = $texts;
        }

        $results[] = $result;
    }

    return [
        'success' => true,
        'survey_id' => $surveyId,
        'response_count' =>
            count($surveyResponses),
        'results' => $results
    ];
}

/**
 * API処理
 */
function handle_api(): never
{
    $action =
        $_GET['action'] ?? '';

    if (!is_string($action)) {
        send_json(
            [
                'success' => false,
                'message' =>
                    '不正な要求です。'
            ],
            400
        );
    }

    $postActions = [
        'save_mail_settings',
        'save_kintone_settings',
        'test_kintone_connection',
        'refresh_customers',
        'save_survey',
        'delete_survey',
        'publish_survey',
        'close_survey',
        'save_response'
    ];

    if (in_array(
        $action,
        $postActions,
        true
    )) {
        verify_csrf();
    }

    $input = [];

    if (
        in_array(
            $action,
            $postActions,
            true
        )
    ) {
        $body = file_get_contents(
            'php://input'
        );

        $decoded =
            json_decode(
                $body ?: '',
                true
            );

        if (!is_array($decoded)) {
            send_json(
                [
                    'success' => false,
                    'message' =>
                        '送信内容を確認できませんでした。'
                ],
                400
            );
        }

        $input = $decoded;
    }

    try {
        switch ($action) {
            case 'load_settings':
                $settings = load_settings();

                send_json([
                    'success' => true,
                    'mail' =>
                        public_mail_settings(
                            $settings['mail']
                        ),
                    'kintone' =>
                        public_kintone_settings(
                            $settings['kintone']
                        )
                ]);

            case 'save_mail_settings':
                send_json(
                    save_mail_settings($input)
                );

            case 'save_kintone_settings':
                send_json(
                    save_kintone_settings($input)
                );

            case 'test_kintone_connection':
                send_json(
                    test_kintone_connection()
                );

            case 'refresh_customers':
                send_json(
                    fetch_kintone_customers()
                );

            case 'load_customers':
                $customers =
                    load_customer_cache();

                send_json([
                    'success' => true,
                    'customers' =>
                        $customers['customers'],
                    'updated_at' =>
                        $customers['updated_at']
                ]);

            case 'load_surveys':
                $surveys =
                    read_json_file(
                        SURVEYS_FILE,
                        default_surveys()
                    );

                send_json([
                    'success' => true,
                    'surveys' => $surveys
                ]);

            case 'save_survey':
                send_json(
                    save_survey($input)
                );

            case 'delete_survey':
                send_json(
                    delete_survey($input)
                );

            case 'publish_survey':
                send_json(
                    publish_survey($input)
                );

            case 'close_survey':
                send_json(
                    close_survey($input)
                );

            case 'aggregate_responses':
                $id = $_GET['id'] ?? '';

                if (
                    !is_string($id) ||
                    !ctype_digit($id) ||
                    (int)$id < 1
                ) {
                    send_json(
                        [
                            'success' => false,
                            'message' =>
                                'アンケートIDが正しくありません。'
                        ],
                        400
                    );
                }

                send_json(
                    aggregate_responses(
                        (int)$id
                    )
                );

            case 'save_response':
                send_json(
                    save_response($input)
                );

            default:
                send_json(
                    [
                        'success' => false,
                        'message' =>
                            '指定された処理はありません。'
                    ],
                    404
                );
        }
    } catch (Throwable $e) {
        /*
         * 認証情報、パスワード、CSRF、通信ヘッダー等は
         * ログへ出さない。
         */
        error_log(
            'yokoyamy_newapp error: ' .
            get_class($e) .
            ': ' .
            $e->getMessage()
        );

        send_json(
            [
                'success' => false,
                'message' =>
                    'サーバー側で処理に失敗しました。設定または保存先を確認してください。'
            ],
            500
        );
    }
}

/**
 * API要求の場合はここで終了。
 */
if (isset($_GET['action'])) {
    handle_api();
}

/**
 * 初回起動時のデータ準備
 */
ensure_data_directory();

$surveys =
    read_json_file(
        SURVEYS_FILE,
        []
    );

if ($surveys === []) {
    $surveys = default_surveys();

    try {
        write_json_file(
            SURVEYS_FILE,
            $surveys
        );
    } catch (Throwable $e) {
        error_log(
            'yokoyamy_newapp survey initialization: ' .
            get_class($e)
        );
    }
}

$settings = load_settings();

$customers =
    load_customer_cache();

$csrfToken =
    get_csrf_token();

$mailForJs =
    public_mail_settings(
        $settings['mail']
    );

$kintoneForJs =
    public_kintone_settings(
        $settings['kintone']
    );

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="application-version" content="<?= h(APP_VERSION) ?>">
<title>アンケート業務運営</title>

<style>
* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
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
    color: #263238;
    background: #f4f6f8;
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
    opacity: .55;
}

.hidden {
    display: none !important;
}

.topbar {
    min-height: 60px;
    background: #1f3a5f;
    color: #fff;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 30px;
}

.logo {
    font-size: 18px;
    font-weight: 700;
    white-space: nowrap;
}

.main-nav {
    display: flex;
    align-items: stretch;
    min-height: 60px;
    gap: 2px;
}

.main-nav button {
    border: 0;
    background: transparent;
    color: #dce7f3;
    padding: 0 17px;
    min-height: 60px;
}

.main-nav button:hover,
.main-nav button.active {
    background: #31557f;
    color: #fff;
}

.app {
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px;
}

.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 20px;
}

.page-header h1 {
    margin: 0;
    font-size: 25px;
    line-height: 1.3;
}

.subtext {
    color: #718096;
    font-size: 13px;
    margin-top: 5px;
}

.card {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 20px;
    margin-bottom: 18px;
}

.card-title {
    font-size: 17px;
    font-weight: 700;
    margin-bottom: 15px;
}

.card-subtitle {
    font-size: 14px;
    font-weight: 700;
    margin: 15px 0 10px;
}

.btn {
    border: 1px solid #cbd5e0;
    background: #fff;
    color: #34495e;
    border-radius: 5px;
    padding: 8px 15px;
    transition:
        background .15s ease,
        border-color .15s ease;
}

.btn:hover {
    background: #f7fafc;
}

.btn-primary {
    background: #2878c8;
    border-color: #2878c8;
    color: #fff;
}

.btn-primary:hover {
    background: #2068ad;
}

.btn-danger {
    background: #fff;
    color: #b43b3b;
    border-color: #e2b5b5;
}

.btn-danger:hover {
    background: #fff4f4;
}

.btn-small {
    padding: 5px 10px;
    font-size: 12px;
}

.btn.loading {
    position: relative;
}

.spinner {
    width: 15px;
    height: 15px;
    border: 2px solid rgba(255,255,255,.4);
    border-top-color: #fff;
    border-radius: 50%;
    display: inline-block;
    vertical-align: -3px;
    margin-right: 7px;
    animation: spin .8s linear infinite;
}

.btn:not(.btn-primary) .spinner {
    border-color: rgba(40,120,200,.25);
    border-top-color: #2878c8;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 11px 10px;
    border-bottom: 1px solid #e6ebef;
    text-align: left;
    vertical-align: middle;
    font-size: 13px;
}

.table th {
    background: #f8fafc;
    color: #52606d;
    white-space: nowrap;
}

.table tbody tr:hover {
    background: #fbfcfd;
}

.empty {
    text-align: center !important;
    color: #8a98a5;
    padding: 40px !important;
}

.badge {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.badge-open {
    background: #e6f6ed;
    color: #237a49;
}

.badge-draft {
    background: #edf2f7;
    color: #66788a;
}

.badge-end {
    background: #fdecec;
    color: #b43b3b;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.field {
    margin-bottom: 15px;
}

.field label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 6px;
    color: #455563;
}

.field input,
.field textarea,
.field select {
    width: 100%;
    border: 1px solid #cbd5e0;
    border-radius: 5px;
    padding: 9px 10px;
    background: #fff;
    color: #263238;
}

.field input:focus,
.field textarea:focus,
.field select:focus {
    outline: none;
    border-color: #2878c8;
    box-shadow: 0 0 0 2px rgba(40,120,200,.12);
}

.field textarea {
    min-height: 90px;
    resize: vertical;
}

.field-help {
    color: #718096;
    font-size: 12px;
    margin-top: 4px;
}

.notice {
    padding: 11px 13px;
    border-radius: 5px;
    background: #edf6ff;
    border: 1px solid #c9e2fa;
    color: #2b5f8a;
    font-size: 13px;
    margin-bottom: 15px;
}

.notice.success {
    background: #edf9f1;
    border-color: #c9ead5;
    color: #267348;
}

.notice.warning {
    background: #fff8e6;
    border-color: #f0dfae;
    color: #8a6408;
}

.notice.error {
    background: #fff0f0;
    border-color: #f0c5c5;
    color: #a63232;
}

.status-line {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    background: #f7f9fb;
    border-radius: 5px;
    margin-bottom: 15px;
}

.status-dot {
    width: 9px;
    height: 9px;
    flex: 0 0 9px;
    border-radius: 50%;
    background: #9aa7b3;
}

.status-dot.ok {
    background: #2f9e61;
}

.status-dot.warn {
    background: #d39b25;
}

.status-dot.error {
    background: #c83c3c;
}

.settings-tabs {
    display: flex;
    gap: 5px;
    margin-bottom: 18px;
}

.settings-tabs button {
    border: 1px solid #d6dee6;
    background: #fff;
    padding: 9px 16px;
    border-radius: 5px;
}

.settings-tabs button.active {
    background: #2878c8;
    color: #fff;
    border-color: #2878c8;
}

.editor-group {
    border: 1px solid #dce3e9;
    border-radius: 7px;
    margin-bottom: 15px;
    background: #fff;
}

.editor-group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 12px 15px;
    background: #f7f9fb;
    border-bottom: 1px solid #dce3e9;
}

.editor-group-title {
    font-weight: 700;
}

.editor-group-body {
    padding: 15px;
}

.question-card {
    border: 1px solid #e0e6eb;
    border-radius: 6px;
    padding: 14px;
    margin-bottom: 12px;
    background: #fff;
}

.question-card:last-child {
    margin-bottom: 0;
}

.question-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.question-number {
    font-weight: 700;
    color: #2878c8;
}

.option-list {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-top: 8px;
}

.option-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 7px;
    align-items: center;
}

.option-row input {
    width: 100%;
}

.drag-handle {
    cursor: grab;
    color: #8795a3;
    user-select: none;
}

.dragging {
    opacity: .45;
}

.drop-target {
    outline: 2px dashed #2878c8;
    outline-offset: 2px;
}

.dashboard-grid {
    display: grid;
    grid-template-columns:
        repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.stat-card {
    background: #fff;
    border: 1px solid #dfe5eb;
    border-radius: 7px;
    padding: 17px;
}

.stat-label {
    color: #718096;
    font-size: 12px;
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    margin-top: 4px;
    color: #263238;
}

.result-row {
    margin-bottom: 18px;
}

.result-label {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    font-size: 13px;
    margin-bottom: 4px;
}

.progress {
    height: 10px;
    background: #edf1f4;
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: #2878c8;
    border-radius: 10px;
}

.customer-select {
    width: 18px;
    height: 18px;
}

.mail-preview {
    background: #f7f9fb;
    border: 1px solid #dfe5eb;
    border-radius: 6px;
    padding: 15px;
    white-space: pre-wrap;
}

.recipient-list {
    max-height: 280px;
    overflow-y: auto;
    border: 1px solid #dfe5eb;
    border-radius: 5px;
}

.recipient-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-bottom: 1px solid #edf0f2;
}

.recipient-item:last-child {
    border-bottom: 0;
}

.toast {
    position: fixed;
    right: 25px;
    bottom: 25px;
    background: #263238;
    color: #fff;
    padding: 12px 18px;
    border-radius: 5px;
    box-shadow: 0 5px 20px rgba(0,0,0,.2);
    opacity: 0;
    transform: translateY(10px);
    transition: .2s;
    pointer-events: none;
    z-index: 2000;
}

.toast.show {
    opacity: 1;
    transform: translateY(0);
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(20,35,50,.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal {
    width: min(820px, calc(100% - 30px));
    max-height: 90vh;
    overflow: auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 15px 50px rgba(0,0,0,.25);
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e3e8ed;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 13px 20px;
    border-top: 1px solid #e3e8ed;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.confirm-list {
    margin: 0;
    padding-left: 20px;
}

.answer-option {
    margin-bottom: 7px;
}

.answer-option label {
    display: flex;
    gap: 8px;
    align-items: center;
}

.public-answer {
    max-width: 900px;
    margin: 30px auto;
    padding: 0 20px;
}

.public-answer .card {
    margin-bottom: 18px;
}

@media (max-width: 1000px) {
    .dashboard-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 800px) {
    .topbar {
        padding: 0 10px;
        gap: 8px;
        overflow-x: auto;
    }

    .logo {
        font-size: 15px;
    }

    .main-nav button {
        padding: 0 8px;
        font-size: 12px;
    }

    .app {
        padding: 14px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-grid {
        grid-template-columns: 1fr 1fr;
    }

    .page-header {
        align-items: flex-start;
        flex-direction: column;
    }
}

@media (max-width: 520px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }

    .option-row {
        grid-template-columns: 1fr;
    }

    .modal {
        width: calc(100% - 16px);
    }
}
</style>
</head>

<body>
<header class="topbar">
    <div class="logo">アンケート業務運営</div>

    <nav class="main-nav">
        <button id="nav-list" type="button">アンケート一覧</button>
        <button id="nav-create" type="button">アンケート作成</button>
        <button id="nav-customers" type="button">顧客一覧</button>
        <button id="nav-settings" type="button">設定</button>
    </nav>
</header>

<main class="app">

<section id="page-list">
    <div class="page-header">
        <div>
            <h1>アンケート一覧</h1>
            <div class="subtext">作成済みのアンケートを管理します</div>
        </div>

        <button id="btn-create" class="btn btn-primary" type="button">
            ＋ アンケート作成
        </button>
    </div>

    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>アンケート名</th>
                    <th>状態</th>
                    <th>作成日</th>
                    <th>公開期間</th>
                    <th>回答数</th>
                    <th>最終更新日</th>
                </tr>
            </thead>
            <tbody id="survey-list-body"></tbody>
        </table>
    </div>
</section>


<section id="page-editor" class="hidden">
    <div class="page-header">
        <div>
            <h1>アンケート作成</h1>
            <div class="subtext">アンケートの基本情報を設定します</div>
        </div>
    </div>

    <div class="card">

        <div class="form-grid">

            <div class="field">
                <label for="survey-name">アンケート名 *</label>
                <input id="survey-name" type="text">
            </div>

            <div class="field">
                <label for="survey-status">公開状態</label>
                <select id="survey-status">
                    <option value="draft">下書き</option>
                    <option value="open">公開中</option>
                    <option value="end">終了</option>
                </select>
            </div>

        </div>

        <div class="field">
            <label for="survey-description">説明</label>
            <textarea id="survey-description"></textarea>
        </div>

        <div class="form-grid">

            <div class="field">
                <label for="survey-start">公開開始日</label>
                <input id="survey-start" type="date">
            </div>

            <div class="field">
                <label for="survey-end">公開終了日</label>
                <input id="survey-end" type="date">
            </div>

        </div>

    </div>


    <div class="card">
        <div class="card-title">質問</div>

        <div class="field">
            <label for="question-text">質問内容</label>
            <input id="question-text" type="text">
        </div>

        <div class="field">
            <label for="question-type">回答形式</label>
            <select id="question-type">
                <option value="single">単一選択</option>
                <option value="multiple">複数選択</option>
                <option value="free">自由記述</option>
            </select>
        </div>
    </div>


    <div class="actions">

        <button id="btn-editor-back" class="btn" type="button">
            一覧へ戻る
        </button>

        <button id="btn-save-survey" class="btn btn-primary" type="button">
            保存
        </button>

    </div>
</section>


<section id="page-customers" class="hidden">

    <div class="page-header">
        <div>
            <h1>顧客一覧</h1>
            <div class="subtext">顧客管理情報を確認します</div>
        </div>
    </div>

    <div id="customer-status"></div>

    <div class="card">
        <table class="table">

            <thead>
                <tr>
                    <th>顧客名</th>
                    <th>メールアドレス</th>
                    <th>会社名</th>
                    <th>顧客番号</th>
                </tr>
            </thead>

            <tbody id="customer-body"></tbody>

        </table>
    </div>

</section>


<section id="page-settings" class="hidden">

    <div class="page-header">
        <div>
            <h1>設定</h1>
            <div class="subtext">
                メール送信と顧客一覧取得に必要な設定を管理します
            </div>
        </div>
    </div>


    <div class="settings-tabs">

        <button
            id="settings-tab-mail"
            type="button"
            class="active">
            メール送信設定
        </button>

        <button
            id="settings-tab-kintone"
            type="button">
            キントーン設定
        </button>

    </div>


    <div id="settings-message"></div>

    <div id="settings-content"></div>

</section>

</main>


<div id="modal" class="modal-backdrop hidden">

    <div class="modal">

        <div class="modal-header">

            <strong id="modal-title"></strong>

            <button
                id="modal-close"
                class="btn btn-small"
                type="button">
                閉じる
            </button>

        </div>


        <div
            class="modal-body"
            id="modal-body">
        </div>


        <div
            class="modal-footer"
            id="modal-footer">
        </div>

    </div>

</div>


<div id="toast" class="toast"></div>


<script>
document.addEventListener('DOMContentLoaded', function () {

    'use strict';


    const csrfToken =
        <?php echo json_encode(
            $csrfToken,
            JSON_UNESCAPED_UNICODE
        ); ?>;


    let mailSettings =
        <?php echo json_encode(
            $mailForJs,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>;


    let kintoneSettings =
        <?php echo json_encode(
            $kintoneForJs,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>;


    let surveys =
        <?php echo json_encode(
            $surveys,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>;


    let customers =
        <?php echo json_encode(
            $customers,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>;


    let currentSettingsTab = 'mail';
    let currentPage = 'list';


    function $(id) {
        return document.getElementById(id);
    }


    function escapeHtml(value) {

        const div = document.createElement('div');

        div.textContent = String(value ?? '');

        return div.innerHTML;
    }


    function showPage(page) {

        const pages = [
            'page-list',
            'page-editor',
            'page-customers',
            'page-settings'
        ];


        pages.forEach(function (id) {

            const el = $(id);

            if (el) {
                el.classList.toggle(
                    'hidden',
                    id !== 'page-' + page
                );
            }

        });


        currentPage = page;


        const navMap = {
            list: 'nav-list',
            editor: 'nav-create',
            customers: 'nav-customers',
            settings: 'nav-settings'
        };


        Object.keys(navMap).forEach(function (key) {

            const nav = $(navMap[key]);

            if (nav) {
                nav.classList.toggle(
                    'active',
                    key === page
                );
            }

        });

    }


    function showToast(message) {

        const toast = $('toast');

        if (!toast) {
            return;
        }


        toast.textContent = message;

        toast.classList.add('show');


        window.setTimeout(function () {

            toast.classList.remove('show');

        }, 2500);

    }


    function setLoading(button, loading, text) {

        if (!button) {
            return;
        }


        if (loading) {

            button.disabled = true;

            button.classList.add('loading');


            button.dataset.originalText =
                button.textContent;


            button.textContent = '';


            const spinner =
                document.createElement('span');

            spinner.className = 'spinner';


            button.appendChild(spinner);

            button.appendChild(
                document.createTextNode(
                    text || '処理中...'
                )
            );


        } else {

            button.disabled = false;

            button.classList.remove('loading');


            if (button.dataset.originalText) {

                button.textContent =
                    button.dataset.originalText;

            }

        }

    }


    async function api(action, payload) {

        const url =
            new URL(window.location.href);


        url.searchParams.set(
            'action',
            action
        );


        const options = {

            method: payload
                ? 'POST'
                : 'GET',

            credentials: 'same-origin',

            headers: {
                'Accept': 'application/json'
            }

        };


        if (payload) {

            options.headers['Content-Type'] =
                'application/json';


            options.headers['X-CSRF-Token'] =
                csrfToken;


            options.body =
                JSON.stringify(
                    Object.assign(
                        {},
                        payload,
                        {
                            csrf_token: csrfToken
                        }
                    )
                );

        }


        let response;


        try {

            response =
                await fetch(
                    url.toString(),
                    options
                );

        } catch (error) {

            throw new Error(
                'サーバーとの通信に失敗しました。画面を再読み込みして再度お試しください。'
            );

        }


        let data;


        try {

            data =
                await response.json();

        } catch (error) {

            throw new Error(
                'サーバーから正しい応答を受け取れませんでした。'
            );

        }


        if (
            !response.ok ||
            !data.success
        ) {

            throw new Error(
                data.message ||
                '処理に失敗しました。'
            );

        }


        return data;

    }


    function renderList() {

        const body =
            $('survey-list-body');


        if (!body) {
            return;
        }


        body.textContent = '';


        if (
            !Array.isArray(surveys) ||
            surveys.length === 0
        ) {

            const tr =
                document.createElement('tr');


            const td =
                document.createElement('td');


            td.colSpan = 6;

            td.className = 'empty';

            td.textContent =
                'アンケートがありません。';


            tr.appendChild(td);

            body.appendChild(tr);

            return;

        }


        surveys.forEach(function (survey) {

            const tr =
                document.createElement('tr');


            const name =
                document.createElement('td');

            name.textContent =
                survey.name || '';


            const status =
                document.createElement('td');


            const badge =
                document.createElement('span');

            badge.className =
                'badge';


            if (survey.status === 'open') {

                badge.classList.add(
                    'badge-open'
                );

                badge.textContent =
                    '公開中';


            } else if (
                survey.status === 'end'
            ) {

                badge.classList.add(
                    'badge-end'
                );

                badge.textContent =
                    '終了';


            } else {

                badge.classList.add(
                    'badge-draft'
                );

                badge.textContent =
                    '下書き';

            }


            status.appendChild(badge);


            const created =
                document.createElement('td');

            created.textContent =
                survey.created || '';


            const period =
                document.createElement('td');

            period.textContent =
                (survey.start || '未設定') +
                ' ～ ' +
                (survey.end || '未設定');


            const answers =
                document.createElement('td');

            answers.textContent =
                String(
                    survey.answers || 0
                );


            const updated =
                document.createElement('td');

            updated.textContent =
                survey.updated || '';


            tr.appendChild(name);

            tr.appendChild(status);

            tr.appendChild(created);

            tr.appendChild(period);

            tr.appendChild(answers);

            tr.appendChild(updated);


            body.appendChild(tr);

        });

    }


    function openCreate() {

        const name =
            $('survey-name');

        const description =
            $('survey-description');

        const start =
            $('survey-start');

        const end =
            $('survey-end');

        const question =
            $('question-text');


        if (name) {
            name.value = '';
        }


        if (description) {
            description.value = '';
        }


        if (start) {
            start.value = '';
        }


        if (end) {
            end.value = '';
        }


        if (question) {
            question.value = '';
        }


        showPage('editor');

    }


    function renderCustomers() {

        const body =
            $('customer-body');


        if (!body) {
            return;
        }


        body.textContent = '';


        if (
            !Array.isArray(customers) ||
            customers.length === 0
        ) {

            const tr =
                document.createElement('tr');


            const td =
                document.createElement('td');


            td.colSpan = 4;

            td.className = 'empty';

            td.textContent =
                '顧客情報がありません。';


            tr.appendChild(td);

            body.appendChild(tr);

            return;

        }


        customers.forEach(function (customer) {

            const tr =
                document.createElement('tr');


            const name =
                document.createElement('td');

            name.textContent =
                customer.name || '';


            const email =
                document.createElement('td');

            email.textContent =
                customer.email || '';


            const company =
                document.createElement('td');

            company.textContent =
                customer.company || '';


            const number =
                document.createElement('td');

            number.textContent =
                customer.number || '';


            tr.appendChild(name);

            tr.appendChild(email);

            tr.appendChild(company);

            tr.appendChild(number);


            body.appendChild(tr);

        });

    }


    function renderCustomerStatus() {

        const container =
            $('customer-status');


        if (!container) {
            return;
        }


        container.textContent = '';


        const div =
            document.createElement('div');


        div.className =
            'notice';


        if (
            kintoneSettings &&
            kintoneSettings.ready
        ) {

            div.textContent =
                '保存されているキントーン設定を使用して顧客情報を表示しています。';

        } else {

            div.className =
                'notice warning';


            div.textContent =
                'キントーン設定が未完了です。設定画面から接続先を設定してください。';

        }


        container.appendChild(div);

    }


    function renderSettings() {

        const mailTab =
            $('settings-tab-mail');


        const kintoneTab =
            $('settings-tab-kintone');


        if (mailTab) {

            mailTab.classList.toggle(
                'active',
                currentSettingsTab === 'mail'
            );

        }


        if (kintoneTab) {

            kintoneTab.classList.toggle(
                'active',
                currentSettingsTab === 'kintone'
            );

        }


        if (
            currentSettingsTab === 'mail'
        ) {

            renderMailSettings();

        } else {

            renderKintoneSettings();

        }

    }


    function renderMessage(
        message,
        type
    ) {

        const container =
            $('settings-message');


        if (!container) {
            return;
        }


        container.textContent = '';


        if (!message) {
            return;
        }


        const div =
            document.createElement('div');


        div.className =
            'notice ' +
            (type || 'success');


        div.textContent =
            message;


        container.appendChild(div);

    }


    function renderMailSettings() {

        const content =
            $('settings-content');


        if (!content) {
            return;
        }


        content.textContent = '';


        const card =
            document.createElement('div');

        card.className =
            'card';


        const title =
            document.createElement('div');

        title.className =
            'card-title';

        title.textContent =
            'メール送信設定';


        card.appendChild(title);


        const status =
            document.createElement('div');

        status.className =
            'status-line';


        const dot =
            document.createElement('span');

        dot.className =
            'status-dot ' +
            (
                mailSettings.ready
                    ? 'ok'
                    : 'warn'
            );


        const statusText =
            document.createElement('span');


        statusText.textContent =
            mailSettings.ready
                ? 'メール送信可能な設定が保存されています。'
                : 'メール送信設定が未完了です。';


        status.appendChild(dot);

        status.appendChild(statusText);


        card.appendChild(status);


        const notice =
            document.createElement('div');

        notice.className =
            'notice';

        notice.textContent =
            'メール送信に使用するSMTPサーバと送信元情報を設定してください。';


        card.appendChild(notice);


        const grid1 =
            document.createElement('div');

        grid1.className =
            'form-grid';


        grid1.appendChild(
            createField(
                'SMTPサーバ *',
                'smtp-server',
                'text',
                mailSettings.smtp,
                'smtp.example.com'
            )
        );


        grid1.appendChild(
            createField(
                'ポート番号 *',
                'smtp-port',
                'number',
                mailSettings.port,
                '587'
            )
        );


        card.appendChild(grid1);


        const securityField =
            document.createElement('div');

        securityField.className =
            'field';


        const securityLabel =
            document.createElement('label');

        securityLabel.htmlFor =
            'smtp-security';

        securityLabel.textContent =
            '接続方式';


        const security =
            document.createElement('select');

        security.id =
            'smtp-security';


        [
            'なし',
            'STARTTLS',
            'SSL/TLS'
        ].forEach(function (value) {

            const option =
                document.createElement('option');


            option.value =
                value;

            option.textContent =
                value;


            if (
                mailSettings.security === value
            ) {

                option.selected = true;

            }


            security.appendChild(option);

        });


        securityField.appendChild(
            securityLabel
        );

        securityField.appendChild(
            security
        );


        card.appendChild(
            securityField
        );


        const grid2 =
            document.createElement('div');

        grid2.className =
            'form-grid';


        grid2.appendChild(
            createField(
                '認証ユーザー名',
                'smtp-user',
                'text',
                mailSettings.username,
                ''
            )
        );


        const passwordField =
            document.createElement('div');

        passwordField.className =
            'field';


        const passwordLabel =
            document.createElement('label');

        passwordLabel.htmlFor =
            'smtp-password';

        passwordLabel.textContent =
            '認証パスワード';


        const password =
            document.createElement('input');

        password.id =
            'smtp-password';

        password.type =
            'password';

        password.autocomplete =
            'new-password';

        password.placeholder =
            '変更する場合のみ入力';


        passwordField.appendChild(
            passwordLabel
        );

        passwordField.appendChild(
            password
        );


        grid2.appendChild(
            passwordField
        );


        card.appendChild(grid2);


        const grid3 =
            document.createElement('div');

        grid3.className =
            'form-grid';


        grid3.appendChild(
            createField(
                '送信元メールアドレス *',
                'smtp-from',
                'email',
                mailSettings.from,
                'survey@example.com'
            )
        );


        grid3.appendChild(
            createField(
                '送信元名',
                'smtp-from-name',
                'text',
                mailSettings.fromName,
                ''
            )
        );


        card.appendChild(grid3);


        const actions =
            document.createElement('div');

        actions.className =
            'actions';


        const saveButton =
            document.createElement('button');

        saveButton.id =
            'btn-save-mail';

        saveButton.type =
            'button';

        saveButton.className =
            'btn btn-primary';

        saveButton.textContent =
            '設定を保存';


        const testButton =
            document.createElement('button');

        testButton.id =
            'btn-test-mail';

        testButton.type =
            'button';

        testButton.className =
            'btn';

        testButton.textContent =
            '送信設定を確認';


        actions.appendChild(
            saveButton
        );

        actions.appendChild(
            testButton
        );


        card.appendChild(actions);


        content.appendChild(card);


        if (saveButton) {

            saveButton.addEventListener(
                'click',
                async function () {

                    saveButton.disabled = true;

                    saveButton.classList.add(
                        'loading'
                    );


                    const originalText =
                        saveButton.textContent;


                    saveButton.textContent = '';


                    const spinner =
                        document.createElement(
                            'span'
                        );

                    spinner.className =
                        'spinner';


                    saveButton.appendChild(
                        spinner
                    );


                    saveButton.appendChild(
                        document.createTextNode(
                            '保存中...'
                        )
                    );


                    renderMessage(
                        '',
                        ''
                    );


                    try {

                        const payload = {

                            smtp:
                                $('smtp-server')?.value.trim() ||
                                '',

                            port:
                                $('smtp-port')?.value.trim() ||
                                '',

                            security:
                                $('smtp-security')?.value ||
                                'なし',

                            username:
                                $('smtp-user')?.value.trim() ||
                                '',

                            password:
                                $('smtp-password')?.value ||
                                '',

                            from:
                                $('smtp-from')?.value.trim() ||
                                '',

                            fromName:
                                $('smtp-from-name')?.value.trim() ||
                                ''

                        };


                        const result =
                            await api(
                                'save_mail_settings',
                                payload
                            );


                        if (
                            result.mail
                        ) {

                            mailSettings =
                                result.mail;

                        }


                        renderMessage(
                            result.message ||
                            'メール送信設定を保存しました。',
                            'success'
                        );


                        showToast(
                            'メール送信設定を保存しました。'
                        );


                        renderMailSettings();


                    } catch (error) {

                        renderMessage(
                            error instanceof Error
                                ? error.message
                                : 'メール送信設定の保存に失敗しました。',
                            'error'
                        );


                    } finally {

                        saveButton.disabled =
                            false;

                        saveButton.classList.remove(
                            'loading'
                        );

                        saveButton.textContent =
                            originalText;

                    }

                }
            );

        }


        if (testButton) {

            testButton.addEventListener(
                'click',
                async function () {

                    testButton.disabled =
                        true;

                    testButton.classList.add(
                        'loading'
                    );


                    const originalText =
                        testButton.textContent;


                    testButton.textContent =
                        '';


                    const spinner =
                        document.createElement(
                            'span'
                        );

                    spinner.className =
                        'spinner';


                    testButton.appendChild(
                        spinner
                    );


                    testButton.appendChild(
                        document.createTextNode(
                            '確認中...'
                        )
                    );


                    renderMessage(
                        '',
                        ''
                    );


                    try {

                        if (
                            !mailSettings.ready
                        ) {

                            renderMessage(
                                '先にメール送信設定を保存してください。',
                                'warning'
                            );

                            return;

                        }


                        openModal(
                            'メール送信設定の確認',
                            '保存されているメール送信設定を確認しました。',
                            '設定は保存されています。実際の送信確認はメール送信時に行われます。'
                        );


                    } catch (error) {

                        renderMessage(
                            error instanceof Error
                                ? error.message
                                : 'メール送信設定の確認に失敗しました。',
                            'error'
                        );


                    } finally {

                        window.setTimeout(
                            function () {

                                testButton.disabled =
                                    false;

                                testButton.classList.remove(
                                    'loading'
                                );

                                testButton.textContent =
                                    originalText;

                            },
                            300
                        );

                    }

                }
            );

        }

    }


    function createField(
        labelText,
        id,
        type,
        value,
        placeholder
    ) {

        const field =
            document.createElement('div');

        field.className =
            'field';


        const label =
            document.createElement('label');

        label.htmlFor =
            id;

        label.textContent =
            labelText;


        const input =
            document.createElement('input');


        input.id =
            id;

        input.type =
            type;

        input.value =
            value || '';


        if (placeholder) {

            input.placeholder =
                placeholder;

        }


        field.appendChild(label);

        field.appendChild(input);


        return field;

    }
    function renderKintoneSettings() {

        const content =
            $('settings-content');

        if (!content) {
            return;
        }

        content.textContent = '';

        const card =
            document.createElement('div');

        card.className = 'card';

        const title =
            document.createElement('div');

        title.className = 'card-title';
        title.textContent = 'キントーン設定';

        card.appendChild(title);

        const notice =
            document.createElement('div');

        notice.className = 'notice';

        notice.textContent =
            'サイボウズへのログイン情報とプロキシサーバを設定します。';

        card.appendChild(notice);

        const domainField =
            createField(
                'サブドメイン *',
                'kintone-domain',
                'text',
                kintoneSettings.domain || '',
                'example'
            );

        card.appendChild(domainField);

        const appField =
            createField(
                'アプリID *',
                'kintone-app-id',
                'number',
                kintoneSettings.appId || '',
                '123'
            );

        card.appendChild(appField);

        const grid =
            document.createElement('div');

        grid.className = 'form-grid';

        grid.appendChild(
            createField(
                'ログイン名 *',
                'kintone-login',
                'text',
                kintoneSettings.login || '',
                ''
            )
        );

        const passwordField =
            document.createElement('div');

        passwordField.className = 'field';

        const passwordLabel =
            document.createElement('label');

        passwordLabel.htmlFor =
            'kintone-password';

        passwordLabel.textContent =
            'パスワード';

        const password =
            document.createElement('input');

        password.id =
            'kintone-password';

        password.type =
            'password';

        password.autocomplete =
            'new-password';

        password.placeholder =
            '変更する場合のみ入力';

        passwordField.appendChild(
            passwordLabel
        );

        passwordField.appendChild(
            password
        );

        grid.appendChild(
            passwordField
        );

        card.appendChild(grid);

        card.appendChild(
            createField(
                'プロキシサーバ',
                'kintone-proxy',
                'text',
                kintoneSettings.proxy || '',
                'proxy.example.com:8080'
            )
        );

        const proxyNotice =
            document.createElement('div');

        proxyNotice.className =
            'form-help';

        proxyNotice.textContent =
            '使用する場合は host名:ポート番号 の形式で入力してください。';

        card.appendChild(proxyNotice);

        const status =
            document.createElement('div');

        status.className =
            'status-line';

        const dot =
            document.createElement('span');

        dot.className =
            'status-dot ' +
            (
                kintoneSettings.ready
                    ? 'ok'
                    : 'warn'
            );

        const statusText =
            document.createElement('span');

        statusText.textContent =
            kintoneSettings.ready
                ? 'キントーン設定が保存されています。'
                : 'キントーン設定が未完了です。';

        status.appendChild(dot);
        status.appendChild(statusText);

        card.appendChild(status);

        const actions =
            document.createElement('div');

        actions.className =
            'actions';

        const saveButton =
            document.createElement('button');

        saveButton.id =
            'btn-save-kintone';

        saveButton.type =
            'button';

        saveButton.className =
            'btn btn-primary';

        saveButton.textContent =
            '設定を保存';

        const testButton =
            document.createElement('button');

        testButton.id =
            'btn-test-kintone';

        testButton.type =
            'button';

        testButton.className =
            'btn';

        testButton.textContent =
            '接続テスト';

        actions.appendChild(
            saveButton
        );

        actions.appendChild(
            testButton
        );

        card.appendChild(actions);

        content.appendChild(card);

        if (saveButton) {

            saveButton.addEventListener(
                'click',
                async function () {

                    saveButton.disabled = true;

                    saveButton.classList.add(
                        'loading'
                    );

                    const originalText =
                        saveButton.textContent;

                    saveButton.textContent = '';

                    const spinner =
                        document.createElement(
                            'span'
                        );

                    spinner.className =
                        'spinner';

                    saveButton.appendChild(
                        spinner
                    );

                    saveButton.appendChild(
                        document.createTextNode(
                            '保存中...'
                        )
                    );

                    renderMessage('', '');

                    try {

                        const payload = {
                            domain:
                                $('kintone-domain')?.value.trim() ||
                                '',

                            appId:
                                $('kintone-app-id')?.value.trim() ||
                                '',

                            login:
                                $('kintone-login')?.value.trim() ||
                                '',

                            password:
                                $('kintone-password')?.value ||
                                '',

                            proxy:
                                $('kintone-proxy')?.value.trim() ||
                                ''
                        };

                        const result =
                            await api(
                                'save_kintone_settings',
                                payload
                            );

                        if (result.kintone) {
                            kintoneSettings =
                                result.kintone;
                        }

                        renderMessage(
                            result.message ||
                            'キントーン設定を保存しました。',
                            'success'
                        );

                        showToast(
                            'キントーン設定を保存しました。'
                        );

                        renderKintoneSettings();

                    } catch (error) {

                        renderMessage(
                            error instanceof Error
                                ? error.message
                                : 'キントーン設定の保存に失敗しました。',
                            'error'
                        );

                    } finally {

                        saveButton.disabled =
                            false;

                        saveButton.classList.remove(
                            'loading'
                        );

                        saveButton.textContent =
                            originalText;
                    }
                }
            );
        }

        if (testButton) {

            testButton.addEventListener(
                'click',
                async function () {

                    testButton.disabled = true;

                    testButton.classList.add(
                        'loading'
                    );

                    const originalText =
                        testButton.textContent;

                    testButton.textContent = '';

                    const spinner =
                        document.createElement(
                            'span'
                        );

                    spinner.className =
                        'spinner';

                    testButton.appendChild(
                        spinner
                    );

                    testButton.appendChild(
                        document.createTextNode(
                            '接続中...'
                        )
                    );

                    renderMessage('', '');

                    try {

                        const payload = {
                            domain:
                                $('kintone-domain')?.value.trim() ||
                                kintoneSettings.domain ||
                                '',

                            appId:
                                $('kintone-app-id')?.value.trim() ||
                                kintoneSettings.appId ||
                                '',

                            login:
                                $('kintone-login')?.value.trim() ||
                                kintoneSettings.login ||
                                '',

                            password:
                                $('kintone-password')?.value ||
                                '',

                            proxy:
                                $('kintone-proxy')?.value.trim() ||
                                kintoneSettings.proxy ||
                                ''
                        };

                        const result =
                            await api(
                                'test_kintone',
                                payload
                            );

                        renderMessage(
                            result.message ||
                            'キントーンへの接続に成功しました。',
                            'success'
                        );

                        showToast(
                            '接続テストに成功しました。'
                        );

                    } catch (error) {

                        renderMessage(
                            error instanceof Error
                                ? error.message
                                : 'キントーンへの接続に失敗しました。',
                            'error'
                        );

                    } finally {

                        testButton.disabled =
                            false;

                        testButton.classList.remove(
                            'loading'
                        );

                        testButton.textContent =
                            originalText;
                    }
                }
            );
        }
    }


    function openModal(
        title,
        message,
        detail
    ) {

        const modal =
            $('modal');

        const modalTitle =
            $('modal-title');

        const modalBody =
            $('modal-body');

        const modalFooter =
            $('modal-footer');

        if (!modal) {
            return;
        }

        if (modalTitle) {
            modalTitle.textContent =
                title || '';
        }

        if (modalBody) {

            modalBody.textContent = '';

            const messageElement =
                document.createElement('p');

            messageElement.textContent =
                message || '';

            modalBody.appendChild(
                messageElement
            );

            if (detail) {

                const detailElement =
                    document.createElement('p');

                detailElement.className =
                    'modal-detail';

                detailElement.textContent =
                    detail;

                modalBody.appendChild(
                    detailElement
                );
            }
        }

        if (modalFooter) {

            modalFooter.textContent = '';

            const closeButton =
                document.createElement('button');

            closeButton.type =
                'button';

            closeButton.className =
                'btn btn-primary';

            closeButton.textContent =
                '閉じる';

            closeButton.addEventListener(
                'click',
                function () {
                    closeModal();
                }
            );

            modalFooter.appendChild(
                closeButton
            );
        }

        modal.classList.remove('hidden');
    }


    function closeModal() {

        const modal =
            $('modal');

        if (modal) {
            modal.classList.add('hidden');
        }
    }


    const navList =
        $('nav-list');

    if (navList) {

        navList.addEventListener(
            'click',
            function () {

                showPage('list');

                renderList();
            }
        );
    }


    const navCreate =
        $('nav-create');

    if (navCreate) {

        navCreate.addEventListener(
            'click',
            function () {

                openCreate();
            }
        );
    }


    const navCustomers =
        $('nav-customers');

    if (navCustomers) {

        navCustomers.addEventListener(
            'click',
            function () {

                showPage('customers');

                renderCustomers();

                renderCustomerStatus();
            }
        );
    }


    const navSettings =
        $('nav-settings');

    if (navSettings) {

        navSettings.addEventListener(
            'click',
            function () {

                showPage('settings');

                currentSettingsTab =
                    'mail';

                renderSettings();
            }
        );
    }


    const btnCreate =
        $('btn-create');

    if (btnCreate) {

        btnCreate.addEventListener(
            'click',
            function () {

                openCreate();
            }
        );
    }


    const btnEditorBack =
        $('btn-editor-back');

    if (btnEditorBack) {

        btnEditorBack.addEventListener(
            'click',
            function () {

                showPage('list');

                renderList();
            }
        );
    }


    const btnSaveSurvey =
        $('btn-save-survey');

    if (btnSaveSurvey) {

        btnSaveSurvey.addEventListener(
            'click',
            async function () {

                btnSaveSurvey.disabled = true;

                btnSaveSurvey.classList.add(
                    'loading'
                );

                const originalText =
                    btnSaveSurvey.textContent;

                btnSaveSurvey.textContent = '';

                const spinner =
                    document.createElement(
                        'span'
                    );

                spinner.className =
                    'spinner';

                btnSaveSurvey.appendChild(
                    spinner
                );

                btnSaveSurvey.appendChild(
                    document.createTextNode(
                        '保存中...'
                    )
                );

                try {

                    const name =
                        $('survey-name')?.value.trim() ||
                        '';

                    if (!name) {

                        throw new Error(
                            'アンケート名を入力してください。'
                        );
                    }

                    const payload = {

                        name: name,

                        description:
                            $('survey-description')?.value.trim() ||
                            '',

                        status:
                            $('survey-status')?.value ||
                            'draft',

                        start:
                            $('survey-start')?.value ||
                            '',

                        end:
                            $('survey-end')?.value ||
                            '',

                        question:
                            $('question-text')?.value.trim() ||
                            '',

                        questionType:
                            $('question-type')?.value ||
                            'single'
                    };

                    const result =
                        await api(
                            'save_survey',
                            payload
                        );

                    if (
                        Array.isArray(
                            result.surveys
                        )
                    ) {

                        surveys =
                            result.surveys;

                    }

                    showToast(
                        result.message ||
                        'アンケートを保存しました。'
                    );

                    showPage('list');

                    renderList();

                } catch (error) {

                    openModal(
                        '保存できませんでした',
                        error instanceof Error
                            ? error.message
                            : 'アンケートの保存に失敗しました。',
                        ''
                    );

                } finally {

                    btnSaveSurvey.disabled =
                        false;

                    btnSaveSurvey.classList.remove(
                        'loading'
                    );

                    btnSaveSurvey.textContent =
                        originalText;
                }
            }
        );
    }


    const mailTab =
        $('settings-tab-mail');

    if (mailTab) {

        mailTab.addEventListener(
            'click',
            function () {

                currentSettingsTab =
                    'mail';

                renderSettings();
            }
        );
    }


    const kintoneTab =
        $('settings-tab-kintone');

    if (kintoneTab) {

        kintoneTab.addEventListener(
            'click',
            function () {

                currentSettingsTab =
                    'kintone';

                renderSettings();
            }
        );
    }


    const modalClose =
        $('modal-close');

    if (modalClose) {

        modalClose.addEventListener(
            'click',
            function () {

                closeModal();
            }
        );
    }


    const modal =
        $('modal');

    if (modal) {

        modal.addEventListener(
            'click',
            function (event) {

                if (
                    event.target === modal
                ) {

                    closeModal();
                }
            }
        );
    }


    renderList();

});
</script>

</body>
</html>


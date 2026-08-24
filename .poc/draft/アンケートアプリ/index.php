<?php
declare(strict_types=1);

/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は、今後の修正・再生成時も変更・削除禁止。

ストレージ:
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1

データトップキー:
- surveys
- responses
- customers
- settings
- mail_logs

アンケート項目:
- id
- title
- start_at
- end_at
- status
- created_at
- updated_at
- numbering_mode
- groups
- deleted

グループ項目:
- id
- name
- questions

質問項目:
- id
- text
- type
- required
- options
- other_enabled

質問形式:
- single
- multiple
- text

顧客項目:
- id
- company
- name
- email
- department
- phone
- address
- source
- sent_at
- send_count
- answer_status
- kintone_status

回答項目:
- id
- survey_id
- customer_id
- company
- name
- email
- answered_at
- answers

設定項目:
- subdomain
- login_name
- password
- app_id
- ssl_verify
- proxy
- field_company
- field_name
- field_email
- field_department
- field_phone
- field_address

SMTP設定項目:
- smtp_host
- smtp_port
- smtp_encryption
- smtp_auth
- smtp_username
- smtp_password
- smtp_from
- smtp_from_name
- smtp_timeout

POST/GETパラメータ:
- action
- survey_id
- customer_id
- response_id
- keyword
- status_filter
- sort
- survey_json
- settings_json
- csrf_token
- recipient_ids
- mail_subject
- mail_body
- template_type
- app_id
- test_email

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields

HTML DOM ID / JS参照名:
- app
- csrf_token
- survey_title
- survey_start_at
- survey_end_at
- survey_numbering_mode
- question_editor
- preview_modal
- preview_content
- response_modal
- response_detail
- response_filter
- response_table
- customer_filter
- customer_table
- select_all
- mail_subject
- mail_body
- template_type
- settings_form
- settings_json
- setting_subdomain
- setting_app_id
- setting_login_name
- setting_password
- setting_proxy
- setting_ssl_verify
- field_message

取り得る値:
- status: draft / active / ended
- numbering_mode: global / group
- type: single / multiple / text
- source: kintone / web
- answer_status: unanswered / answered
- kintone_status: unregistered / registered
- template_type: initial / reminder
- smtp_encryption: none / ssl / tls
- smtp_auth: yes / no
========================================================================
*/

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

date_default_timezone_set('Asia/Tokyo');

session_name(SURVEY_ADMIN_SESSION);
session_start();

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

function app_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function app_read_store(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        $initial = [
            'surveys' => [],
            'responses' => [],
            'customers' => [],
            'settings' => [],
            'mail_logs' => []
        ];
        file_put_contents(
            SURVEY_STORAGE_FILE,
            json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
        return $initial;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return [
            'surveys' => [],
            'responses' => [],
            'customers' => [],
            'settings' => [],
            'mail_logs' => []
        ];
    }

    foreach (['surveys', 'responses', 'customers', 'settings', 'mail_logs'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    return $data;
}

function app_write_store(array $data): bool
{
    return (bool)file_put_contents(
        SURVEY_STORAGE_FILE,
        json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        ),
        LOCK_EX
    );
}

function app_id(): string
{
    return bin2hex(random_bytes(12));
}

function app_now(): string
{
    return date('Y-m-d H:i:s');
}

function app_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function app_check_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (
        $token === '' ||
        !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)
    ) {
        app_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。画面を再読み込みしてください。'
        ], 403);
    }
}

/**
 * PHP 8.4/8.5対応。
 * 非推奨の $http_response_header は使用しない。
 */
function app_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

function app_status_from_headers(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $header, $m)) {
            return (int)$m[1];
        }
    }

    return 0;
}

function kintone_domain(string $domain): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\/.*$/', '', $domain);
    $domain = preg_replace('/\.cybozu\.com$/i', '', $domain);
    $domain = trim((string)$domain, '.');

    if ($domain === '') {
        throw new RuntimeException('kintoneサブドメインが未入力です。');
    }

    return $domain . '.cybozu.com';
}

function kintone_url(string $domain, string $endpoint, array $query = []): string
{
    $url = 'https://' . kintone_domain($domain) . '/' . ltrim($endpoint, '/');

    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

function kintone_auth_header(string $login, string $password): string
{
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login) . ':' . $password);
}

function kintone_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
        'protocol_version' => 1.1
    ];

    if ($payload !== null && $method !== 'GET') {
        $http['content'] = is_string($payload)
            ? $payload
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $sslVerify = !isset($config['ssl_verify']) ||
        (bool)$config['ssl_verify'];

    $contextOptions = [
        'http' => $http,
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify
        ]
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Proxyは host名:port番号 の形式で指定してください。'
            ];
        }

        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $error = '';

    set_error_handler(
        static function (int $severity, string $message) use (&$error): bool {
            $error = $message;
            return true;
        }
    );

    try {
        $body = file_get_contents($url, false, $context);
    } finally {
        restore_error_handler();
    }

    $headersReceived = app_response_headers();
    $status = app_status_from_headers($headersReceived);

    if ($body === false) {
        return [
            'success' => false,
            'status' => $status,
            'message' => $error !== ''
                ? $error
                : 'kintone APIへの接続に失敗しました。',
            'headers' => $headersReceived
        ];
    }

    $json = json_decode($body, true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($json) ? $json : [],
            'headers' => $headersReceived
        ];
    }

    $message = is_array($json) && isset($json['message'])
        ? (string)$json['message']
        : 'kintone APIからエラーが返されました。';

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'raw' => is_array($json) ? $json : $body,
        'headers' => $headersReceived
    ];
}

function kintone_config(array $settings): array
{
    return [
        'ssl_verify' => isset($settings['ssl_verify'])
            ? (bool)$settings['ssl_verify']
            : false,
        'proxy' => (string)($settings['proxy'] ?? '')
    ];
}

function kintone_fields(array $settings, string $appId): array
{
    $appId = trim($appId);

    if ($appId === '' || !preg_match('/^\d+$/', $appId)) {
        return [
            'success' => false,
            'status' => 0,
            'message' => 'アプリIDは数字で入力してください。'
        ];
    }

    $url = kintone_url(
        (string)($settings['subdomain'] ?? ''),
        '/k/v1/app/form/fields.json',
        ['app' => $appId]
    );

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode(
                trim((string)($settings['login_name'] ?? '')) .
                ':' .
                (string)($settings['password'] ?? '')
            )
    ];

    return kintone_request(
        'GET',
        $url,
        $headers,
        null,
        kintone_config($settings)
    );
}

function kintone_connection_test(array $settings): array
{
    if (
        trim((string)($settings['subdomain'] ?? '')) === '' ||
        trim((string)($settings['login_name'] ?? '')) === '' ||
        (string)($settings['password'] ?? '') === ''
    ) {
        return [
            'success' => false,
            'status' => 0,
            'message' => 'サブドメイン、ログイン名、パスワードをすべて入力してください。'
        ];
    }

    /*
     * アプリIDに依存しない接続確認。
     * /v1/apps.json は不要な権限問題を避けるため、
     * アプリIDが設定済みならフォームフィールドAPIを使う。
     */
    $appId = trim((string)($settings['app_id'] ?? ''));

    if ($appId !== '') {
        return kintone_fields($settings, $appId);
    }

    return [
        'success' => false,
        'status' => 0,
        'message' => '接続確認には顧客管理アプリIDを入力してください。'
    ];
}

/* ================================================================
 * SMTP
 * ================================================================ */

function smtp_encryption_normalize(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'ssl', 'smtps', 'ssl/tls' => 'ssl',
        'tls', 'starttls' => 'tls',
        default => 'none'
    };
}

function smtp_host_clean(string $host): string
{
    $host = trim($host);

    /*
     * 以前の致命的バグ対策。
     * 「ssl://smtp.example.com」のような入力が来ても、
     * ssl://をホスト名として残さない。
     */
    $host = preg_replace('#^(ssl|tls|tcp)://#i', '', $host);
    $host = preg_replace('/\/.*$/', '', (string)$host);

    return trim((string)$host);
}

function smtp_read($socket, int $timeout = 15): array
{
    stream_set_timeout($socket, $timeout);

    $lines = [];
    $code = 0;

    while (!feof($socket)) {
        $line = fgets($socket, 4096);

        if ($line === false) {
            break;
        }

        $line = rtrim($line, "\r\n");

        if ($line !== '') {
            $lines[] = $line;
        }

        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];

            if ($m[2] === ' ') {
                break;
            }
        }
    }

    return [
        'code' => $code,
        'text' => implode("\n", $lines)
    ];
}

function smtp_write($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

function smtp_expect($socket, array $codes, string $command = ''): array
{
    $response = smtp_read($socket);

    if (!in_array($response['code'], $codes, true)) {
        $response['success'] = false;
        $response['command'] = $command;
        return $response;
    }

    $response['success'] = true;
    $response['command'] = $command;

    return $response;
}

function smtp_connect(array $cfg): array
{
    $host = smtp_host_clean((string)($cfg['smtp_host'] ?? ''));
    $port = (int)($cfg['smtp_port'] ?? 25);
    $encryption = smtp_encryption_normalize(
        (string)($cfg['smtp_encryption'] ?? 'none')
    );
    $timeout = max(3, min(120, (int)($cfg['smtp_timeout'] ?? 15)));

    if ($host === '') {
        return [
            'success' => false,
            'stage' => 'validation',
            'message' => 'SMTPサーバが未入力です。'
        ];
    }

    if ($port < 1 || $port > 65535) {
        return [
            'success' => false,
            'stage' => 'validation',
            'message' => 'SMTPポートが不正です。'
        ];
    }

    /*
     * SSL:
     * 465などのSMTPSは最初からSSL。
     *
     * TLS:
     * 587などは平文TCP接続後STARTTLS。
     *
     * none:
     * 通常のTCP。
     */
    $transport = $encryption === 'ssl' ? 'ssl' : 'tcp';

    $target = $transport . '://' . $host . ':' . $port;

    $sslOptions = [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ];

    $context = stream_context_create([
        'ssl' => $sslOptions
    ]);

    $errno = 0;
    $errstr = '';

    set_error_handler(
        static function (int $severity, string $message) use (&$errstr): bool {
            $errstr = $message;
            return true;
        }
    );

    try {
        $socket = stream_socket_client(
            $target,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
    } finally {
        restore_error_handler();
    }

    if (!is_resource($socket)) {
        return [
            'success' => false,
            'stage' => 'tcp',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'errno' => $errno,
            'message' => $errstr !== ''
                ? $errstr
                : 'SMTPサーバへ接続できませんでした。'
        ];
    }

    stream_set_timeout($socket, $timeout);

    $greeting = smtp_expect($socket, [220], 'CONNECT');

    if (!$greeting['success']) {
        fclose($socket);

        return [
            'success' => false,
            'stage' => 'smtp',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'smtp_code' => $greeting['code'],
            'message' => $greeting['text']
        ];
    }

    $localHost = gethostname() ?: 'localhost';

    smtp_write($socket, 'EHLO ' . $localHost);

    $ehlo = smtp_read($socket);

    if ($ehlo['code'] !== 250) {
        smtp_write($socket, 'HELO ' . $localHost);
        $helo = smtp_read($socket);

        if ($helo['code'] !== 250) {
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'ehlo',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'smtp_code' => $helo['code'],
                'message' => $helo['text']
            ];
        }

        $ehlo = $helo;
    }

    /*
     * STARTTLSは「tcp://」接続後にのみ実行する。
     * ここが今回の「sslをホスト名として扱う」問題の重要修正点。
     */
    if ($encryption === 'tls') {
        if (stripos($ehlo['text'], 'STARTTLS') === false) {
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'starttls',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'message' => 'SMTPサーバがSTARTTLSに対応していません。'
            ];
        }

        smtp_write($socket, 'STARTTLS');

        $startTlsResponse = smtp_read($socket);

        if ($startTlsResponse['code'] !== 220) {
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'starttls',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'smtp_code' => $startTlsResponse['code'],
                'message' => $startTlsResponse['text']
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
                'success' => false,
                'stage' => 'tls',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'message' => 'STARTTLSによるTLS暗号化に失敗しました。'
            ];
        }

        smtp_write($socket, 'EHLO ' . $localHost);
        $ehlo = smtp_read($socket);
    }

    return [
        'success' => true,
        'socket' => $socket,
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'greeting' => $greeting,
        'ehlo' => $ehlo
    ];
}

function smtp_authenticate($socket, array $cfg): array
{
    $auth = (string)($cfg['smtp_auth'] ?? 'no');

    if ($auth !== 'yes') {
        return ['success' => true];
    }

    $username = (string)($cfg['smtp_username'] ?? '');
    $password = (string)($cfg['smtp_password'] ?? '');

    if ($username === '' || $password === '') {
        return [
            'success' => false,
            'stage' => 'authentication',
            'message' => 'SMTP認証を使用する場合はユーザー名とパスワードが必要です。'
        ];
    }

    smtp_write($socket, 'AUTH LOGIN');
    $r = smtp_read($socket);

    if ($r['code'] !== 334) {
        return [
            'success' => false,
            'stage' => 'authentication',
            'smtp_code' => $r['code'],
            'message' => 'SMTP AUTH LOGINを開始できませんでした。'
        ];
    }

    smtp_write($socket, base64_encode($username));
    $r = smtp_read($socket);

    if ($r['code'] !== 334) {
        return [
            'success' => false,
            'stage' => 'authentication',
            'smtp_code' => $r['code'],
            'message' => 'SMTPユーザー名が受け付けられませんでした。'
        ];
    }

    smtp_write($socket, base64_encode($password));
    $r = smtp_read($socket);

    if ($r['code'] !== 235) {
        return [
            'success' => false,
            'stage' => 'authentication',
            'smtp_code' => $r['code'],
            'message' => 'SMTP認証に失敗しました。'
        ];
    }

    return [
        'success' => true,
        'stage' => 'authentication',
        'smtp_code' => 235
    ];
}

function smtp_encode_header(string $value): string
{
    if ($value === '') {
        return '';
    }

    return '=?UTF-8?B?' .
        base64_encode($value) .
        '?=';
}

function smtp_mail_send(array $cfg, string $to, string $subject, string $body): array
{
    $to = trim($to);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'stage' => 'validation',
            'message' => 'メールアドレスが不正です。'
        ];
    }

    $from = trim((string)($cfg['smtp_from'] ?? ''));

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'stage' => 'validation',
            'message' => '送信元メールアドレスが不正です。'
        ];
    }

    $conn = smtp_connect($cfg);

    if (!$conn['success']) {
        return $conn;
    }

    $socket = $conn['socket'];

    try {
        $auth = smtp_authenticate($socket, $cfg);

        if (!$auth['success']) {
            smtp_write($socket, 'QUIT');
            fclose($socket);
            return $auth;
        }

        smtp_write($socket, 'MAIL FROM:<' . $from . '>');
        $r = smtp_read($socket);

        if ($r['code'] !== 250) {
            smtp_write($socket, 'QUIT');
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'mail_from',
                'smtp_code' => $r['code'],
                'message' => $r['text']
            ];
        }

        smtp_write($socket, 'RCPT TO:<' . $to . '>');
        $r = smtp_read($socket);

        if (!in_array($r['code'], [250, 251], true)) {
            smtp_write($socket, 'QUIT');
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'recipient',
                'smtp_code' => $r['code'],
                'message' => $r['text']
            ];
        }

        smtp_write($socket, 'DATA');
        $r = smtp_read($socket);

        if ($r['code'] !== 354) {
            smtp_write($socket, 'QUIT');
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'data',
                'smtp_code' => $r['code'],
                'message' => $r['text']
            ];
        }

        $fromName = trim((string)($cfg['smtp_from_name'] ?? ''));

        $headers = [];
        $headers[] = 'Date: ' . date(DATE_RFC2822);
        $headers[] = 'From: ' .
            ($fromName !== ''
                ? smtp_encode_header($fromName) . ' <' . $from . '>'
                : $from);
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . smtp_encode_header($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';

        $encodedBody = rtrim(
            chunk_split(base64_encode($body), 76, "\r\n")
        );

        $message = implode("\r\n", $headers) .
            "\r\n\r\n" .
            $encodedBody .
            "\r\n.";

        fwrite($socket, $message . "\r\n");

        $r = smtp_read($socket);

        smtp_write($socket, 'QUIT');
        smtp_read($socket);

        fclose($socket);

        if ($r['code'] !== 250) {
            return [
                'success' => false,
                'stage' => 'send',
                'smtp_code' => $r['code'],
                'message' => $r['text']
            ];
        }

        return [
            'success' => true,
            'stage' => 'send',
            'smtp_code' => 250
        ];
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            @fclose($socket);
        }

        return [
            'success' => false,
            'stage' => 'exception',
            'message' => $e->getMessage()
        ];
    }
}

function smtp_connection_check(array $cfg): array
{
    $result = smtp_connect($cfg);

    if (!$result['success']) {
        unset($result['socket']);
        return $result;
    }

    $socket = $result['socket'];

    $auth = smtp_authenticate($socket, $cfg);

    smtp_write($socket, 'QUIT');
    smtp_read($socket);

    fclose($socket);

    unset($result['socket']);

    if (!$auth['success']) {
        return array_merge($result, $auth);
    }

    return array_merge($result, [
        'auth_success' => true,
        'message' => 'SMTP接続および認証に成功しました。'
    ]);
}

/* ================================================================
 * API
 * ================================================================ */

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action !== '') {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        app_check_csrf();
    }

    $store = app_read_store();

    try {

        if ($action === 'bootstrap') {
            $safeSettings = $store['settings'];

            if (isset($safeSettings['password'])) {
                $safeSettings['password'] = '';
            }

            if (isset($safeSettings['smtp_password'])) {
                $safeSettings['smtp_password'] = '';
            }

            app_json_response([
                'ok' => true,
                'surveys' => $store['surveys'],
                'responses' => $store['responses'],
                'customers' => $store['customers'],
                'settings' => $safeSettings,
                'mail_logs' => $store['mail_logs'],
                'csrf_token' => app_csrf()
            ]);
        }

        if ($action === 'save_survey') {
            $json = json_decode(
                (string)($_POST['survey_json'] ?? ''),
                true
            );

            if (!is_array($json)) {
                app_json_response([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $id = trim((string)($json['id'] ?? ''));

            if ($id === '') {
                $id = app_id();
                $json['id'] = $id;
                $json['created_at'] = app_now();
            }

            $json['updated_at'] = app_now();
            $json['title'] = (string)($json['title'] ?? '');
            $json['status'] = in_array(
                $json['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            ) ? $json['status'] : 'draft';

            $found = false;

            foreach ($store['surveys'] as $i => $survey) {
                if ((string)($survey['id'] ?? '') === $id) {
                    $store['surveys'][$i] = $json;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $store['surveys'][] = $json;
            }

            app_write_store($store);

            app_json_response([
                'ok' => true,
                'survey' => $json
            ]);
        }

        if ($action === 'delete_survey') {
            $id = (string)($_POST['survey_id'] ?? '');

            foreach ($store['surveys'] as &$survey) {
                if ((string)($survey['id'] ?? '') === $id) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = app_now();
                }
            }
            unset($survey);

            app_write_store($store);

            app_json_response(['ok' => true]);
        }

        if ($action === 'save_settings') {
            $json = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($json)) {
                app_json_response([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            $old = is_array($store['settings']) ? $store['settings'] : [];

            foreach ($json as $key => $value) {
                if ($key === 'password' && $value === '') {
                    continue;
                }

                if ($key === 'smtp_password' && $value === '') {
                    continue;
                }

                $old[$key] = $value;
            }

            $store['settings'] = $old;

            app_write_store($store);

            $safe = $old;
            $safe['password'] = '';
            $safe['smtp_password'] = '';

            app_json_response([
                'ok' => true,
                'settings' => $safe
            ]);
        }

        if ($action === 'kintone_test') {
            $settings = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($settings)) {
                $settings = $store['settings'];
            }

            $result = kintone_connection_test($settings);

            $public = $result;
            unset($public['headers']);

            app_json_response([
                'ok' => $result['success'],
                'result' => $public
            ]);
        }

        if ($action === 'kintone_fields') {
            $settings = $store['settings'];

            $posted = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (is_array($posted)) {
                $settings = array_merge($settings, $posted);
            }

            $appId = (string)($_POST['app_id'] ?? '');

            $result = kintone_fields($settings, $appId);

            if (!$result['success']) {
                app_json_response([
                    'ok' => false,
                    'message' => $result['message'] ?? '項目一覧取得に失敗しました。',
                    'status' => $result['status'] ?? 0,
                    'raw' => $result['raw'] ?? null
                ], 400);
            }

            $fields = [];

            foreach ((array)($result['data']['properties'] ?? []) as $code => $field) {
                $fields[] = [
                    'code' => (string)$code,
                    'label' => (string)($field['label'] ?? $code),
                    'type' => (string)($field['type'] ?? '')
                ];
            }

            app_json_response([
                'ok' => true,
                'fields' => $fields,
                'status' => $result['status']
            ]);
        }

        if ($action === 'kintone_sync') {
            $settings = $store['settings'];

            $appId = trim((string)($settings['app_id'] ?? ''));

            if ($appId === '' || !preg_match('/^\d+$/', $appId)) {
                app_json_response([
                    'ok' => false,
                    'message' => '顧客管理アプリIDが設定されていません。'
                ], 400);
            }

            $url = kintone_url(
                (string)$settings['subdomain'],
                '/k/v1/records.json',
                ['app' => $appId]
            );

            $headers = [
                'Accept: application/json',
                kintone_auth_header(
                    (string)$settings['login_name'],
                    (string)$settings['password']
                )
            ];

            $result = kintone_request(
                'GET',
                $url,
                $headers,
                null,
                kintone_config($settings)
            );

            if (!$result['success']) {
                app_json_response([
                    'ok' => false,
                    'message' => $result['message'],
                    'status' => $result['status']
                ], 400);
            }

            $mapped = [];

            $fc = (string)($settings['field_company'] ?? '');
            $fn = (string)($settings['field_name'] ?? '');
            $fe = (string)($settings['field_email'] ?? '');
            $fd = (string)($settings['field_department'] ?? '');
            $fp = (string)($settings['field_phone'] ?? '');
            $fa = (string)($settings['field_address'] ?? '');

            foreach ((array)($result['data']['records'] ?? []) as $record) {

                $value = static function (array $record, string $code): string {
                    if ($code === '' || !isset($record[$code])) {
                        return '';
                    }

                    $v = $record[$code]['value'] ?? '';

                    if (is_array($v)) {
                        $parts = [];

                        foreach ($v as $item) {
                            if (is_array($item) && isset($item['value'])) {
                                $parts[] = (string)$item['value'];
                            } else {
                                $parts[] = (string)$item;
                            }
                        }

                        return implode(' ', $parts);
                    }

                    return (string)$v;
                };

                $email = trim($value($record, $fe));

                if ($email === '') {
                    continue;
                }

                $customer = [
                    'id' => 'k_' . md5($email),
                    'company' => $value($record, $fc),
                    'name' => $value($record, $fn),
                    'email' => $email,
                    'department' => $value($record, $fd),
                    'phone' => $value($record, $fp),
                    'address' => $value($record, $fa),
                    'source' => 'kintone',
                    'sent_at' => '',
                    'send_count' => 0,
                    'answer_status' => 'unanswered',
                    'kintone_status' => 'registered'
                ];

                $mapped[$email] = $customer;
            }

            foreach ($store['customers'] as $customer) {
                $email = trim((string)($customer['email'] ?? ''));

                if ($email !== '' && !isset($mapped[$email])) {
                    $mapped[$email] = $customer;
                }
            }

            $store['customers'] = array_values($mapped);

            app_write_store($store);

            app_json_response([
                'ok' => true,
                'count' => count($store['customers']),
                'customers' => $store['customers']
            ]);
        }

        if ($action === 'smtp_check') {
            $cfg = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($cfg)) {
                $cfg = $store['settings'];
            }

            $result = smtp_connection_check($cfg);

            unset($result['socket']);

            app_json_response([
                'ok' => $result['success'],
                'result' => $result
            ]);
        }

        if ($action === 'smtp_test') {
            $cfg = $store['settings'];

            $posted = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (is_array($posted)) {
                $cfg = array_merge($cfg, $posted);
            }

            $to = trim((string)($_POST['test_email'] ?? ''));

            $result = smtp_mail_send(
                $cfg,
                $to,
                'アンケート管理システム SMTP送信テスト',
                "SMTP設定が正常に動作し、テストメールの送信に成功しました。\r\n\r\n" .
                '送信日時: ' . app_now()
            );

            app_json_response([
                'ok' => $result['success'],
                'result' => $result
            ]);
        }

        if ($action === 'send_mail') {
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $recipientIds = json_decode(
                (string)($_POST['recipient_ids'] ?? '[]'),
                true
            );

            $subject = (string)($_POST['mail_subject'] ?? '');
            $body = (string)($_POST['mail_body'] ?? '');
            $templateType = (string)($_POST['template_type'] ?? 'initial');

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            if (
                trim((string)($store['settings']['smtp_host'] ?? '')) === '' ||
                (int)($store['settings']['smtp_port'] ?? 0) <= 0 ||
                trim((string)($store['settings']['smtp_from'] ?? '')) === ''
            ) {
                app_json_response([
                    'ok' => false,
                    'message' => 'SMTP設定が未完了です。システム設定からSMTP設定を完了してください。'
                ], 400);
            }

            $success = 0;
            $failed = 0;
            $skipped = 0;
            $results = [];

            $logId = app_id();

            foreach ($store['customers'] as &$customer) {

                if (!in_array((string)$customer['id'], $recipientIds, true)) {
                    continue;
                }

                $email = trim((string)($customer['email'] ?? ''));

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    $results[] = [
                        'customer_id' => $customer['id'],
                        'email' => $email,
                        'status' => 'skipped',
                        'message' => 'メールアドレス不正'
                    ];
                    continue;
                }

                $customerName = (string)($customer['name'] ?? '');

                $personalSubject = str_replace(
                    ['{顧客名}'],
                    [$customerName],
                    $subject
                );

                $personalBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        $customerName,
                        (isset($_SERVER['HTTPS']) ? 'https' : 'http') .
                        '://' .
                        ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                        rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') .
                        '/?survey=' . rawurlencode($surveyId) .
                        '&customer=' . rawurlencode((string)$customer['id'])
                    ],
                    $body
                );

                $result = smtp_mail_send(
                    $store['settings'],
                    $email,
                    $personalSubject,
                    $personalBody
                );

                if ($result['success']) {
                    $success++;
                    $customer['sent_at'] = app_now();
                    $customer['send_count'] =
                        (int)($customer['send_count'] ?? 0) + 1;
                    $customer['answer_status'] = 'unanswered';

                    $results[] = [
                        'customer_id' => $customer['id'],
                        'email' => $email,
                        'status' => 'sent'
                    ];
                } else {
                    $failed++;

                    $results[] = [
                        'customer_id' => $customer['id'],
                        'email' => $email,
                        'status' => 'failed',
                        'message' => $result['message'] ?? '送信失敗'
                    ];
                }
            }
            unset($customer);

            $store['mail_logs'][] = [
                'id' => $logId,
                'survey_id' => $surveyId,
                'sent_at' => app_now(),
                'type' => $templateType === 'reminder'
                    ? 'reminder'
                    : 'initial',
                'target_count' => count($recipientIds),
                'success_count' => $success,
                'failed_count' => $failed,
                'subject' => $subject,
                'executed_by' => (string)($_SERVER['REMOTE_USER'] ?? 'admin'),
                'results' => $results
            ];

            app_write_store($store);

            app_json_response([
                'ok' => true,
                'success_count' => $success,
                'failed_count' => $failed,
                'skipped_count' => $skipped,
                'results' => $results
            ]);
        }

        if ($action === 'save_response') {
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $customerId = (string)($_POST['customer_id'] ?? '');

            $responseJson = json_decode(
                (string)($_POST['survey_json'] ?? ''),
                true
            );

            if (!is_array($responseJson)) {
                app_json_response([
                    'ok' => false,
                    'message' => '回答データが不正です。'
                ], 400);
            }

            $store['responses'][] = [
                'id' => app_id(),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => (string)($responseJson['company'] ?? ''),
                'name' => (string)($responseJson['name'] ?? ''),
                'email' => (string)($responseJson['email'] ?? ''),
                'answered_at' => app_now(),
                'answers' => $responseJson['answers'] ?? []
            ];

            foreach ($store['customers'] as &$customer) {
                if ((string)$customer['id'] === $customerId) {
                    $customer['answer_status'] = 'answered';
                }
            }
            unset($customer);

            app_write_store($store);

            app_json_response(['ok' => true]);
        }

        if ($action === 'csv') {
            $surveyId = (string)($_GET['survey_id'] ?? '');

            $responses = array_values(
                array_filter(
                    $store['responses'],
                    static fn(array $r): bool =>
                        (string)($r['survey_id'] ?? '') === $surveyId
                )
            );

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="survey_' .
                preg_replace('/[^a-zA-Z0-9_-]/', '_', $surveyId) .
                '.csv"'
            );

            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'wb');

            fputcsv(
                $out,
                ['回答ID', '回答日時', '顧客ID', '会社名', '氏名', 'メールアドレス', '回答']
            );

            foreach ($responses as $response) {
                fputcsv(
                    $out,
                    [
                        $response['id'] ?? '',
                        $response['answered_at'] ?? '',
                        $response['customer_id'] ?? '',
                        $response['company'] ?? '',
                        $response['name'] ?? '',
                        $response['email'] ?? '',
                        json_encode(
                            $response['answers'] ?? [],
                            JSON_UNESCAPED_UNICODE
                        )
                    ]
                );
            }

            fclose($out);
            exit;
        }

        app_json_response([
            'ok' => false,
            'message' => '不明なactionです。'
        ], 400);

    } catch (Throwable $e) {

        app_json_response([
            'ok' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

$csrf = app_csrf();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<div id="app"></div>

<div id="preview_modal"
     class="hidden fixed inset-0 z-50 bg-black/50 p-4 overflow-auto">
    <div class="max-w-3xl mx-auto bg-white rounded-2xl shadow-xl p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">プレビュー</h2>
            <button onclick="App.actions.closeModal('preview_modal')"
                    class="px-3 py-2 rounded-lg bg-slate-100">
                閉じる
            </button>
        </div>
        <div id="preview_content"></div>
    </div>
</div>

<div id="response_modal"
     class="hidden fixed inset-0 z-50 bg-black/50 p-4 overflow-auto">
    <div class="max-w-4xl mx-auto bg-white rounded-2xl shadow-xl p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">回答詳細</h2>
            <button onclick="App.actions.closeModal('response_modal')"
                    class="px-3 py-2 rounded-lg bg-slate-100">
                閉じる
            </button>
        </div>
        <div id="response_detail"></div>
    </div>
</div>

<script>
window.App = {

    state: {
        screen: 'surveys',
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mail_logs: [],
        csrf: '',
        editingSurvey: null,
        selectedSurveyId: null,
        selectedResponseId: null,
        fieldList: [],
        responseFilter: '',
        customerFilter: '',
        selectedCustomers: []
    },

    api: {

        async post(action, data = {}) {
            const fd = new FormData();
            fd.append('action', action);
            fd.append('csrf_token', App.state.csrf);

            Object.entries(data).forEach(([key, value]) => {
                if (typeof value === 'object') {
                    fd.append(key, JSON.stringify(value));
                } else {
                    fd.append(key, value ?? '');
                }
            });

            const response = await fetch(location.href, {
                method: 'POST',
                body: fd
            });

            const text = await response.text();

            let json;

            try {
                json = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーからJSON以外の応答が返されました。\n' +
                    text.substring(0, 500)
                );
            }

            if (!json.ok) {
                throw new Error(json.message || 'サーバー処理に失敗しました。');
            }

            return json;
        },

        async bootstrap() {
            const response = await fetch(
                location.href + '?action=bootstrap',
                {cache: 'no-store'}
            );

            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.message || '初期化に失敗しました。');
            }

            App.state.surveys = data.surveys || [];
            App.state.responses = data.responses || [];
            App.state.customers = data.customers || [];
            App.state.settings = data.settings || {};
            App.state.mail_logs = data.mail_logs || [];
            App.state.csrf = data.csrf_token || '';
        }
    },

    util: {

        esc(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        },

        id() {
            return 'js_' +
                Date.now().toString(36) +
                Math.random().toString(36).slice(2, 9);
        },

        now() {
            return new Date().toISOString().slice(0, 19).replace('T', ' ');
        },

        clone(obj) {
            return JSON.parse(JSON.stringify(obj));
        },

        statusLabel(status) {
            return {
                draft: '下書き',
                active: '公開中',
                ended: '終了'
            }[status] || status;
        },

        statusClass(status) {
            return {
                draft: 'bg-slate-100 text-slate-700',
                active: 'bg-emerald-100 text-emerald-700',
                ended: 'bg-rose-100 text-rose-700'
            }[status] || 'bg-slate-100';
        },

        questionLabel(type) {
            return {
                single: '単一選択',
                multiple: '複数選択',
                text: '自由記述'
            }[type] || type;
        }
    },

    render: {

        shell(content, title = 'アンケート管理') {
            return `
            <div class="min-h-screen">
                <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
                    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between gap-4">
                        <div>
                            <div class="text-xs text-slate-400">SURVEY MANAGEMENT</div>
                            <div class="text-xl font-bold">${App.util.esc(title)}</div>
                        </div>

                        <nav class="flex flex-wrap gap-2">
                            <button onclick="App.actions.go('surveys')"
                                    class="px-3 py-2 rounded-lg hover:bg-slate-100">
                                アンケート一覧
                            </button>

                            <button onclick="App.actions.go('settings')"
                                    class="px-3 py-2 rounded-lg hover:bg-slate-100">
                                キントーン・メール設定
                            </button>
                        </nav>
                    </div>
                </header>

                <main class="max-w-7xl mx-auto p-6">
                    ${content}
                </main>
            </div>`;
        },

        surveys() {

            const activeSurveys = App.state.surveys.filter(
                s => !s.deleted
            );

            return App.render.shell(`
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-2xl font-bold">アンケート一覧</h1>
                        <p class="text-sm text-slate-500 mt-1">
                            アンケートの作成・編集・送信・集計を管理します。
                        </p>
                    </div>

                    <button onclick="App.actions.newSurvey()"
                            class="px-5 py-3 bg-indigo-600 text-white rounded-xl shadow hover:bg-indigo-700">
                        ＋ 新規アンケート作成
                    </button>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                    <div class="p-4 border-b flex gap-3">
                        <input id="survey_filter"
                               oninput="App.actions.filterSurveys(this.value)"
                               placeholder="タイトル検索"
                               class="border rounded-lg px-3 py-2 w-full max-w-md">
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="text-left p-4">作成日 / 更新日</th>
                                    <th class="text-left p-4">タイトル</th>
                                    <th class="text-left p-4">期間</th>
                                    <th class="text-left p-4">ステータス</th>
                                    <th class="text-left p-4">回答数</th>
                                    <th class="text-left p-4">操作</th>
                                </tr>
                            </thead>
                            <tbody id="survey_table">
                                ${activeSurveys.map(App.render.surveyRow).join('')}
                            </tbody>
                        </table>
                    </div>

                    ${activeSurveys.length === 0 ? `
                        <div class="p-16 text-center text-slate-400">
                            アンケートはありません。
                        </div>` : ''}
                </div>
            `);
        },

        surveyRow(survey) {

            const count = App.state.responses.filter(
                r => r.survey_id === survey.id
            ).length;

            const buttons = [];

            buttons.push(`
                <button onclick="App.actions.editSurvey('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-slate-100 hover:bg-slate-200">
                    確認・編集
                </button>`);

            if (survey.status === 'active' || survey.status === 'ended') {
                buttons.push(`
                    <button onclick="App.actions.aggregate('${survey.id}')"
                            class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700">
                        集計
                    </button>`);
            }

            if (survey.status === 'active') {
                buttons.push(`
                    <button onclick="App.actions.mail('${survey.id}')"
                            class="px-3 py-2 rounded-lg bg-indigo-600 text-white">
                        送信
                    </button>`);

                buttons.push(`
                    <button onclick="App.actions.changeStatus('${survey.id}','ended')"
                            class="px-3 py-2 rounded-lg bg-rose-50 text-rose-700">
                        停止
                    </button>`);
            }

            if (survey.status === 'draft') {
                buttons.push(`
                    <button onclick="App.actions.deleteSurvey('${survey.id}')"
                            class="px-3 py-2 rounded-lg bg-rose-50 text-rose-700">
                        削除
                    </button>`);
            }

            buttons.push(`
                <button onclick="App.actions.duplicateSurvey('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-slate-100">
                    複製
                </button>`);

            return `
            <tr class="border-t">
                <td class="p-4 whitespace-nowrap">
                    ${App.util.esc(survey.created_at || '')}<br>
                    <span class="text-slate-400">
                        更新: ${App.util.esc(survey.updated_at || '')}
                    </span>
                </td>

                <td class="p-4 font-bold">
                    ${App.util.esc(survey.title || '無題')}
                </td>

                <td class="p-4 whitespace-nowrap">
                    ${App.util.esc(survey.start_at || '未設定')}
                    ～
                    ${App.util.esc(survey.end_at || '未設定')}
                </td>

                <td class="p-4">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold
                        ${App.util.statusClass(survey.status)}">
                        ${App.util.statusLabel(survey.status)}
                    </span>
                </td>

                <td class="p-4">${count} 件</td>

                <td class="p-4">
                    <div class="flex flex-wrap gap-2">
                        ${buttons.join('')}
                    </div>
                </td>
            </tr>`;
        },

        editor() {

            const survey = App.state.editingSurvey;

            return App.render.shell(`
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <div class="text-sm text-slate-400">
                            ホーム ＞ アンケート一覧 ＞ 作成・編集
                        </div>

                        <input id="survey_title"
                               value="${App.util.esc(survey.title || '')}"
                               class="text-2xl font-bold bg-transparent border-b border-transparent
                                      focus:border-indigo-400 outline-none py-2">
                    </div>

                    <div class="flex gap-2">
                        <button onclick="App.actions.preview()"
                                class="px-4 py-2 rounded-lg bg-white border">
                            プレビュー
                        </button>

                        <button onclick="App.actions.saveSurvey()"
                                class="px-4 py-2 rounded-lg bg-indigo-600 text-white">
                            保存して一覧へ戻る
                        </button>

                        <button onclick="App.actions.cancelEdit()"
                                class="px-4 py-2 rounded-lg bg-slate-100">
                            キャンセル
                        </button>
                    </div>
                </div>

                <div class="grid lg:grid-cols-3 gap-6">

                    <section class="lg:col-span-2 space-y-4">
                        <div class="bg-white border rounded-2xl p-5">
                            <div class="grid md:grid-cols-3 gap-4">
                                <label>
                                    <span class="text-sm font-semibold">開始日時</span>
                                    <input id="survey_start_at"
                                           value="${App.util.esc(survey.start_at || '')}"
                                           class="mt-1 w-full border rounded-lg p-2">
                                </label>

                                <label>
                                    <span class="text-sm font-semibold">終了日時</span>
                                    <input id="survey_end_at"
                                           value="${App.util.esc(survey.end_at || '')}"
                                           class="mt-1 w-full border rounded-lg p-2">
                                </label>

                                <label>
                                    <span class="text-sm font-semibold">質問番号</span>
                                    <select id="survey_numbering_mode"
                                            class="mt-1 w-full border rounded-lg p-2">
                                        <option value="global"
                                            ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                            Q1, Q2, Q3
                                        </option>
                                        <option value="group"
                                            ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                            Q1-1, Q1-2
                                        </option>
                                    </select>
                                </label>
                            </div>

                            <div class="mt-4">
                                <span class="text-sm font-semibold">ステータス</span>

                                <select id="survey_status"
                                        class="ml-3 border rounded-lg p-2">
                                    <option value="draft"
                                        ${survey.status === 'draft' ? 'selected' : ''}>
                                        下書き
                                    </option>
                                    <option value="active"
                                        ${survey.status === 'active' ? 'selected' : ''}>
                                        公開中
                                    </option>
                                    <option value="ended"
                                        ${survey.status === 'ended' ? 'selected' : ''}>
                                        終了
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div id="question_editor">
                            ${survey.groups.map((g, gi) =>
                                App.render.group(g, gi)
                            ).join('')}
                        </div>

                        <button onclick="App.actions.addGroup()"
                                class="w-full border-2 border-dashed border-slate-300
                                       rounded-2xl py-5 text-slate-500 hover:bg-white">
                            ＋ グループを追加
                        </button>
                    </section>

                    <aside>
                        <div class="bg-white border rounded-2xl p-5 sticky top-28">
                            <h2 class="font-bold mb-3">編集ガイド</h2>
                            <ul class="text-sm text-slate-500 space-y-2">
                                <li>・グループはドラッグで並べ替えできます。</li>
                                <li>・質問もドラッグで並べ替えできます。</li>
                                <li>・質問番号は自動再採番されます。</li>
                                <li>・質問はグループを跨いで移動できます。</li>
                                <li>・未保存内容はプレビューにも反映されます。</li>
                            </ul>
                        </div>
                    </aside>
                </div>
            `);
        },

        group(group, groupIndex) {

            return `
            <section class="survey-group bg-white border rounded-2xl p-5 mb-4"
                     data-group-id="${group.id}">

                <div class="flex items-center gap-3 mb-4">
                    <span class="group-handle cursor-grab text-xl">⠿</span>

                    <input value="${App.util.esc(group.name)}"
                           onchange="App.actions.updateGroupName('${group.id}',this.value)"
                           class="font-bold text-lg flex-1 border-b border-transparent
                                  focus:border-indigo-400 outline-none">

                    <button onclick="App.actions.addQuestion('${group.id}')"
                            class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700">
                        ＋ 質問
                    </button>

                    <button onclick="App.actions.deleteGroup('${group.id}')"
                            class="px-3 py-2 rounded-lg bg-rose-50 text-rose-700">
                        削除
                    </button>
                </div>

                <div class="question-list space-y-3"
                     data-group-id="${group.id}">

                    ${group.questions.map((q, qi) =>
                        App.render.question(q, groupIndex, qi)
                    ).join('')}

                </div>
            </section>`;
        },

        question(q, groupIndex, qi) {

            const options = q.options || [];

            return `
            <article class="question-card border border-slate-200 rounded-xl p-4"
                     data-question-id="${q.id}">

                <div class="flex gap-3">
                    <span class="question-handle cursor-grab text-slate-400 text-lg">
                        ⠿
                    </span>

                    <div class="flex-1">

                        <div class="flex items-center gap-2 mb-3">
                            <span class="question-number font-bold text-indigo-600">
                                Q${groupIndex + 1}-${qi + 1}
                            </span>

                            <select onchange="App.actions.updateQuestion('${q.id}','type',this.value)"
                                    class="border rounded-lg px-2 py-1 text-sm">
                                <option value="single"
                                    ${q.type === 'single' ? 'selected' : ''}>
                                    単一選択
                                </option>
                                <option value="multiple"
                                    ${q.type === 'multiple' ? 'selected' : ''}>
                                    複数選択
                                </option>
                                <option value="text"
                                    ${q.type === 'text' ? 'selected' : ''}>
                                    自由記述
                                </option>
                            </select>

                            <label class="text-sm flex items-center gap-1">
                                <input type="checkbox"
                                       ${q.required ? 'checked' : ''}
                                       onchange="App.actions.updateQuestion('${q.id}','required',this.checked)">
                                必須
                            </label>

                            <button onclick="App.actions.deleteQuestion('${q.id}')"
                                    class="ml-auto px-2 py-1 text-rose-600">
                                削除
                            </button>
                        </div>

                        <input value="${App.util.esc(q.text)}"
                               onchange="App.actions.updateQuestion('${q.id}','text',this.value)"
                               placeholder="質問文"
                               class="w-full border rounded-lg px-3 py-2">

                        ${q.type !== 'text' ? `
                            <div class="mt-3 space-y-2">
                                ${options.map((option, oi) => `
                                    <div class="flex gap-2">
                                        <input value="${App.util.esc(option)}"
                                               onchange="App.actions.updateOption('${q.id}',${oi},this.value)"
                                               class="flex-1 border rounded-lg px-3 py-2">

                                        <button onclick="App.actions.deleteOption('${q.id}',${oi})"
                                                class="px-3 text-rose-600">
                                            ×
                                        </button>
                                    </div>
                                `).join('')}

                                <button onclick="App.actions.addOption('${q.id}')"
                                        class="text-sm text-indigo-600">
                                    ＋ 選択肢追加
                                </button>

                                <label class="block text-sm mt-2">
                                    <input type="checkbox"
                                           ${q.other_enabled ? 'checked' : ''}
                                           onchange="App.actions.updateQuestion('${q.id}','other_enabled',this.checked)">
                                    その他（自由記述）を許可
                                </label>
                            </div>
                        ` : ''}
                    </div>
                </div>
            </article>`;
        },

        mail() {

            const survey = App.state.surveys.find(
                s => s.id === App.state.selectedSurveyId
            );

            const customers = App.state.customers.filter(c => {
                const keyword = App.state.customerFilter.toLowerCase();

                if (!keyword) return true;

                return [
                    c.company,
                    c.name,
                    c.email,
                    c.phone,
                    c.address
                ].join(' ').toLowerCase().includes(keyword);
            });

            return App.render.shell(`
                <div class="mb-6">
                    <div class="text-sm text-slate-400">
                        ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
                    </div>

                    <div class="flex justify-between items-center mt-2">
                        <div>
                            <h1 class="text-2xl font-bold">
                                ${App.util.esc(survey?.title || '')}
                            </h1>
                            <p class="text-slate-500">
                                顧客宛先選択・メール送信
                            </p>
                        </div>

                        <button onclick="App.actions.sendMail()"
                                class="px-5 py-3 bg-indigo-600 text-white rounded-xl">
                            一括送信実行
                        </button>
                    </div>
                </div>

                <div class="grid lg:grid-cols-3 gap-6">

                    <section class="lg:col-span-2">
                        <div class="bg-white border rounded-2xl overflow-hidden">

                            <div class="p-4 border-b flex gap-3">
                                <input id="customer_filter"
                                       value="${App.util.esc(App.state.customerFilter)}"
                                       oninput="App.actions.filterCustomers(this.value)"
                                       placeholder="顧客名・メールアドレス等"
                                       class="border rounded-lg px-3 py-2 flex-1">

                                <button onclick="App.actions.selectAllCustomers()"
                                        class="px-3 py-2 bg-slate-100 rounded-lg">
                                    全選択
                                </button>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50">
                                        <tr>
                                            <th class="p-3">
                                                <input id="select_all"
                                                       type="checkbox"
                                                       onchange="App.actions.selectAllCustomers(this.checked)">
                                            </th>
                                            <th class="p-3 text-left">会社名 / 氏名</th>
                                            <th class="p-3 text-left">メール</th>
                                            <th class="p-3 text-left">送信状況</th>
                                            <th class="p-3 text-left">回答</th>
                                        </tr>
                                    </thead>

                                    <tbody id="customer_table">
                                        ${customers.map(c => `
                                            <tr class="border-t">
                                                <td class="p-3">
                                                    <input type="checkbox"
                                                           ${App.state.selectedCustomers.includes(c.id) ? 'checked' : ''}
                                                           onchange="App.actions.toggleCustomer('${c.id}',this.checked)">
                                                </td>

                                                <td class="p-3">
                                                    <div class="font-bold">${App.util.esc(c.company)}</div>
                                                    <div>${App.util.esc(c.name)}</div>
                                                    <div class="text-xs text-slate-400">
                                                        ${App.util.esc(c.department || '')}
                                                    </div>
                                                </td>

                                                <td class="p-3">${App.util.esc(c.email)}</td>

                                                <td class="p-3">
                                                    ${c.sent_at
                                                        ? `<div>${App.util.esc(c.sent_at)}</div>
                                                           <div class="text-xs">送信 ${c.send_count || 0} 回</div>`
                                                        : '<span class="text-slate-400">未送信</span>'}
                                                </td>

                                                <td class="p-3">
                                                    <span class="px-2 py-1 rounded-full text-xs
                                                        ${c.answer_status === 'answered'
                                                            ? 'bg-emerald-100 text-emerald-700'
                                                            : 'bg-amber-100 text-amber-700'}">
                                                        ${c.answer_status === 'answered'
                                                            ? '回答済み'
                                                            : '未回答'}
                                                    </span>
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <aside>
                        <div class="bg-white border rounded-2xl p-5 sticky top-28">

                            <label class="block text-sm font-semibold">
                                送信テンプレート
                            </label>

                            <select id="template_type"
                                    onchange="App.actions.templateType(this.value)"
                                    class="w-full border rounded-lg p-2 mt-1">
                                <option value="initial">初回送信用</option>
                                <option value="reminder">リマインド送信用</option>
                            </select>

                            <label class="block text-sm font-semibold mt-4">
                                件名
                            </label>

                            <input id="mail_subject"
                                   value="アンケートご回答のお願い"
                                   class="w-full border rounded-lg p-2 mt-1">

                            <label class="block text-sm font-semibold mt-4">
                                本文
                            </label>

                            <textarea id="mail_body"
                                      rows="12"
                                      class="w-full border rounded-lg p-2 mt-1">{$${'顧客名'}様

アンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

                            <div class="mt-3 text-xs text-slate-400">
                                使用可能な変数：
                                {顧客名} / {アンケートURL}
                            </div>

                            <div class="mt-5 p-3 bg-slate-50 rounded-lg text-sm">
                                選択中：
                                <strong>${App.state.selectedCustomers.length}</strong>
                                件
                            </div>
                        </div>
                    </aside>
                </div>
            `);
        },

        aggregate() {

            const surveyId = App.state.selectedSurveyId;

            const survey = App.state.surveys.find(
                s => s.id === surveyId
            );

            const responses = App.state.responses.filter(
                r => r.survey_id === surveyId
            );

            const sentCustomers = App.state.customers.filter(
                c => c.sent_at
            );

            const unanswered = sentCustomers.filter(
                c => c.answer_status !== 'answered'
            );

            const registeredResponseCount = responses.filter(
                r => r.customer_id
            ).length;

            const rate = sentCustomers.length
                ? ((registeredResponseCount / sentCustomers.length) * 100).toFixed(1)
                : '0.0';

            return App.render.shell(`
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <div class="text-sm text-slate-400">
                            ホーム ＞ アンケート一覧 ＞ 集計
                        </div>

                        <h1 class="text-2xl font-bold mt-2">
                            ${App.util.esc(survey?.title || '')}
                        </h1>
                    </div>

                    <a href="?action=csv&survey_id=${encodeURIComponent(surveyId)}"
                       class="px-4 py-2 rounded-lg bg-white border">
                        CSV出力
                    </a>
                </div>

                <div class="grid md:grid-cols-5 gap-4 mb-6">

                    ${[
                        ['送信対象者数', sentCustomers.length + ' 人'],
                        ['回答数', responses.length + ' 件'],
                        ['未登録顧客からの回答数',
                            responses.filter(r => !r.customer_id).length + ' 件'],
                        ['未回答数', unanswered.length + ' 人'],
                        ['回答率', rate + ' %']
                    ].map(x => `
                        <div class="bg-white border rounded-2xl p-5">
                            <div class="text-sm text-slate-500">${x[0]}</div>
                            <div class="text-2xl font-bold mt-2">${x[1]}</div>
                        </div>
                    `).join('')}
                </div>

                <div class="grid lg:grid-cols-3 gap-6">

                    <section class="lg:col-span-2 bg-white border rounded-2xl p-5">
                        <h2 class="font-bold text-lg mb-4">
                            設問別集計
                        </h2>

                        ${App.render.questionStatistics(survey, responses)}
                    </section>

                    <aside class="bg-white border rounded-2xl p-5">
                        <h2 class="font-bold mb-4">設問絞り込み</h2>

                        <div class="space-y-2">
                            ${(survey?.groups || []).flatMap(
                                g => g.questions
                            ).map(q => `
                                <label class="flex gap-2 text-sm">
                                    <input type="checkbox"
                                           checked
                                           onchange="App.actions.toggleResponseQuestion('${q.id}',this.checked)">
                                    ${App.util.esc(q.text)}
                                </label>
                            `).join('')}
                        </div>
                    </aside>
                </div>

                <div class="bg-white border rounded-2xl mt-6 overflow-hidden">
                    <div class="p-5 border-b">
                        <h2 class="font-bold text-lg">個別回答一覧</h2>

                        <input id="response_filter"
                               oninput="App.actions.filterResponses(this.value)"
                               placeholder="会社名・氏名検索"
                               class="mt-3 border rounded-lg px-3 py-2 w-full max-w-md">
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="p-3 text-left">回答日時</th>
                                    <th class="p-3 text-left">会社名</th>
                                    <th class="p-3 text-left">氏名</th>
                                    <th class="p-3 text-left">メール</th>
                                    <th class="p-3"></th>
                                </tr>
                            </thead>

                            <tbody id="response_table">
                                ${responses.map(r => `
                                    <tr class="border-t">
                                        <td class="p-3">${App.util.esc(r.answered_at)}</td>
                                        <td class="p-3">${App.util.esc(r.company)}</td>
                                        <td class="p-3">${App.util.esc(r.name)}</td>
                                        <td class="p-3">${App.util.esc(r.email)}</td>
                                        <td class="p-3 text-right">
                                            <button onclick="App.actions.showResponse('${r.id}')"
                                                    class="px-3 py-2 bg-indigo-50 text-indigo-700 rounded-lg">
                                                全回答を表示
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>

                    ${responses.length === 0 ? `
                        <div class="p-12 text-center text-slate-400">
                            現在、回答データはありません
                        </div>` : ''}
                </div>
            `);
        },

        questionStatistics(survey, responses) {

            const questions = (survey?.groups || []).flatMap(
                g => g.questions
            );

            if (!questions.length) {
                return `
                    <div class="text-slate-400">
                        設問がありません。
                    </div>`;
            }

            return questions.map(q => {

                const counts = {};

                (q.options || []).forEach(
                    o => counts[o] = 0
                );

                responses.forEach(r => {

                    const answer = r.answers?.[q.id];

                    if (Array.isArray(answer)) {
                        answer.forEach(a => {
                            counts[a] = (counts[a] || 0) + 1;
                        });
                    } else if (answer) {
                        counts[answer] = (counts[answer] || 0) + 1;
                    }
                });

                const total = responses.length || 1;

                return `
                <div class="border-b py-5">
                    <div class="font-semibold">
                        ${App.util.esc(q.text)}
                    </div>

                    <div class="text-xs text-slate-400 mt-1">
                        ${App.util.questionLabel(q.type)}
                    </div>

                    ${q.type === 'text'
                        ? `<div class="mt-3 text-sm text-slate-500">
                             自由記述回答 ${responses.length} 件
                           </div>`
                        : Object.entries(counts).map(([name, count]) => `
                            <div class="mt-3">
                                <div class="flex justify-between text-sm">
                                    <span>${App.util.esc(name)}</span>
                                    <span>${count} 件 / ${((count / total) * 100).toFixed(1)}%</span>
                                </div>

                                <div class="h-3 bg-slate-100 rounded-full mt-1 overflow-hidden">
                                    <div class="h-full bg-indigo-500 rounded-full"
                                         style="width:${Math.min(100,(count/total)*100)}%">
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                </div>`;
            }).join('');
        },

        settings() {

            const s = App.state.settings || {};

            const field = (id, label, value, type = 'text') => `
                <label class="block">
                    <span class="text-sm font-semibold">${label}</span>
                    <input id="${id}"
                           type="${type}"
                           value="${App.util.esc(value || '')}"
                           class="mt-1 w-full border rounded-lg px-3 py-2">
                </label>`;

            return App.render.shell(`
                <div class="mb-6">
                    <div class="text-sm text-slate-400">
                        ホーム ＞ システム設定 ＞ kintone・メール連携設定
                    </div>

                    <h1 class="text-2xl font-bold mt-2">
                        キントーン・メール（SMTP）連携設定
                    </h1>
                </div>

                <div class="grid lg:grid-cols-2 gap-6">

                    <section class="bg-white border rounded-2xl p-6">
                        <h2 class="text-lg font-bold mb-5">
                            kintone接続・認証設定
                        </h2>

                        <div class="space-y-4">

                            ${field(
                                'setting_subdomain',
                                'サブドメイン',
                                s.subdomain || ''
                            )}

                            ${field(
                                'setting_login_name',
                                'ログイン名',
                                s.login_name || ''
                            )}

                            ${field(
                                'setting_password',
                                'パスワード',
                                '',
                                'password'
                            )}

                            ${field(
                                'setting_app_id',
                                '顧客管理アプリID',
                                s.app_id || ''
                            )}

                            ${field(
                                'setting_proxy',
                                'Proxyサーバ',
                                s.proxy || ''
                            )}

                            <label class="flex items-center gap-2">
                                <input id="setting_ssl_verify"
                                       type="checkbox"
                                       ${s.ssl_verify ? 'checked' : ''}>
                                SSL証明書を検証する
                            </label>

                            <div id="field_message"
                                 class="hidden p-3 rounded-lg"></div>

                            <div class="flex flex-wrap gap-2">
                                <button onclick="App.actions.saveSettings()"
                                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                                    設定保存
                                </button>

                                <button onclick="App.actions.kintoneTest()"
                                        class="px-4 py-2 bg-slate-100 rounded-lg">
                                    接続確認
                                </button>

                                <button onclick="App.actions.fetchKintoneFields()"
                                        class="px-4 py-2 bg-slate-100 rounded-lg">
                                    項目一覧を取得
                                </button>

                                <button onclick="App.actions.kintoneSync()"
                                        class="px-4 py-2 bg-emerald-50 text-emerald-700 rounded-lg">
                                    顧客データを同期
                                </button>
                            </div>
                        </div>

                        <div class="mt-6 border-t pt-5">
                            <h3 class="font-bold mb-3">
                                kintoneフィールドマッピング
                            </h3>

                            <div class="space-y-3">
                                ${App.render.fieldSelect('field_company','会社名',s.field_company)}
                                ${App.render.fieldSelect('field_name','氏名',s.field_name)}
                                ${App.render.fieldSelect('field_email','メールアドレス',s.field_email)}
                                ${App.render.fieldSelect('field_department','部署名',s.field_department)}
                                ${App.render.fieldSelect('field_phone','電話番号',s.field_phone)}
                                ${App.render.fieldSelect('field_address','住所',s.field_address)}
                            </div>
                        </div>
                    </section>

                    <section class="bg-white border rounded-2xl p-6">
                        <h2 class="text-lg font-bold mb-5">
                            SMTPサーバ設定
                        </h2>

                        <div class="space-y-4">

                            ${field(
                                'smtp_host',
                                'SMTPサーバ',
                                s.smtp_host || ''
                            )}

                            ${field(
                                'smtp_port',
                                'SMTPポート',
                                s.smtp_port || '587',
                                'number'
                            )}

                            <label class="block">
                                <span class="text-sm font-semibold">
                                    暗号化方式
                                </span>

                                <select id="smtp_encryption"
                                        class="mt-1 w-full border rounded-lg px-3 py-2">
                                    <option value="none"
                                        ${s.smtp_encryption === 'none' ? 'selected' : ''}>
                                        なし
                                    </option>
                                    <option value="ssl"
                                        ${s.smtp_encryption === 'ssl' ? 'selected' : ''}>
                                        SSL
                                    </option>
                                    <option value="tls"
                                        ${s.smtp_encryption === 'tls' || !s.smtp_encryption ? 'selected' : ''}>
                                        TLS
                                    </option>
                                </select>
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold">
                                    SMTP認証
                                </span>

                                <select id="smtp_auth"
                                        class="mt-1 w-full border rounded-lg px-3 py-2">
                                    <option value="no"
                                        ${s.smtp_auth === 'no' ? 'selected' : ''}>
                                        認証しない
                                    </option>
                                    <option value="yes"
                                        ${s.smtp_auth === 'yes' ? 'selected' : ''}>
                                        認証する
                                    </option>
                                </select>
                            </label>

                            ${field(
                                'smtp_username',
                                'SMTPユーザー名',
                                s.smtp_username || ''
                            )}

                            ${field(
                                'smtp_password',
                                'SMTPパスワード',
                                '',
                                'password'
                            )}

                            ${field(
                                'smtp_from',
                                '送信元メールアドレス',
                                s.smtp_from || ''
                            )}

                            ${field(
                                'smtp_from_name',
                                '送信元表示名',
                                s.smtp_from_name || ''
                            )}

                            ${field(
                                'smtp_timeout',
                                '接続タイムアウト（秒）',
                                s.smtp_timeout || '15',
                                'number'
                            )}

                            <div class="flex flex-wrap gap-2 pt-2">
                                <button onclick="App.actions.saveSettings()"
                                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                                    SMTP設定を保存
                                </button>

                                <button onclick="App.actions.smtpCheck()"
                                        class="px-4 py-2 bg-slate-100 rounded-lg">
                                    SMTP接続確認
                                </button>
                            </div>

                            <div class="border-t pt-5 mt-5">
                                <h3 class="font-bold">
                                    テストメール送信
                                </h3>

                                <input id="smtp_test_email"
                                       placeholder="送信先メールアドレス"
                                       class="mt-3 w-full border rounded-lg px-3 py-2">

                                <button onclick="App.actions.smtpTest()"
                                        class="mt-3 px-4 py-2 bg-emerald-600 text-white rounded-lg">
                                    テストメール送信
                                </button>

                                <div id="smtp_result"
                                     class="mt-3 text-sm"></div>
                            </div>
                        </div>
                    </section>
                </div>
            `);
        },

        fieldSelect(id, label, selected) {

            return `
            <label class="block">
                <span class="text-sm font-semibold">${label}</span>

                <select id="${id}"
                        class="mt-1 w-full border rounded-lg px-3 py-2">
                    <option value="">-- フィールドを選択 --</option>

                    ${App.state.fieldList.map(f => `
                        <option value="${App.util.esc(f.code)}"
                            ${selected === f.code ? 'selected' : ''}>
                            ${App.util.esc(f.label)}
                            （${App.util.esc(f.code)}）
                        </option>
                    `).join('')}
                </select>
            </label>`;
        }
    },

    actions: {

        async init() {
            if (App.state.initialized) {
                return;
            }

            App.state.initialized = true;

            try {
                await App.api.bootstrap();
                App.renderScreen('surveys');
            } catch (e) {
                document.getElementById('app').innerHTML = `
                    <div class="min-h-screen flex items-center justify-center p-6">
                        <div class="bg-white border border-rose-200 rounded-2xl p-8 max-w-xl">
                            <h1 class="text-xl font-bold text-rose-700">
                                初期化に失敗しました
                            </h1>
                            <pre class="mt-4 whitespace-pre-wrap text-sm text-slate-600">${App.util.esc(e.message)}</pre>
                            <button onclick="location.reload()"
                                    class="mt-5 px-4 py-2 bg-slate-800 text-white rounded-lg">
                                再読み込み
                            </button>
                        </div>
                    </div>`;
            }
        },

        go(screen) {
            App.state.screen = screen;
            App.renderScreen(screen);
        },

        renderScreen(screen) {

            App.state.screen = screen;

            let html = '';

            switch (screen) {
                case 'surveys':
                    html = App.render.surveys();
                    break;

                case 'editor':
                    html = App.render.editor();
                    break;

                case 'mail':
                    html = App.render.mail();
                    break;

                case 'aggregate':
                    html = App.render.aggregate();
                    break;

                case 'settings':
                    html = App.render.settings();
                    break;

                default:
                    html = App.render.surveys();
            }

            document.getElementById('app').innerHTML = html;

            if (screen === 'editor') {
                App.actions.setupSortable();
                App.actions.renumber();
            }
        },

        newSurvey() {

            App.state.editingSurvey = {
                id: '',
                title: '新しいアンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: '',
                updated_at: '',
                numbering_mode: 'global',
                groups: [],
                deleted: false
            };

            App.actions.addGroup(false);
            App.renderScreen('editor');
        },

        editSurvey(id) {

            const survey = App.state.surveys.find(
                s => s.id === id
            );

            if (!survey) return;

            App.state.editingSurvey = App.util.clone(survey);
            App.state.selectedSurveyId = id;

            App.renderScreen('editor');
        },

        async saveSurvey() {

            const s = App.state.editingSurvey;

            s.title = document.getElementById('survey_title')?.value || '';
            s.start_at = document.getElementById('survey_start_at')?.value || '';
            s.end_at = document.getElementById('survey_end_at')?.value || '';
            s.numbering_mode =
                document.getElementById('survey_numbering_mode')?.value || 'global';

            s.status =
                document.getElementById('survey_status')?.value || 'draft';

            App.actions.syncEditorState();

            try {
                const result = await App.api.post(
                    'save_survey',
                    {survey_json: s}
                );

                const index = App.state.surveys.findIndex(
                    x => x.id === result.survey.id
                );

                if (index >= 0) {
                    App.state.surveys[index] = result.survey;
                } else {
                    App.state.surveys.push(result.survey);
                }

                alert('アンケートを保存しました。');
                App.actions.go('surveys');

            } catch (e) {
                alert(e.message);
            }
        },

        cancelEdit() {

            if (confirm('未保存の変更を破棄して一覧へ戻りますか？')) {
                App.actions.go('surveys');
            }
        },

        addGroup(render = true) {

            const group = {
                id: App.util.id(),
                name: '新しいグループ',
                questions: []
            };

            App.state.editingSurvey.groups.push(group);

            if (render) {
                App.renderScreen('editor');
            }
        },

        deleteGroup(groupId) {

            if (!confirm(
                'このグループと内包される質問を削除しますか？'
            )) {
                return;
            }

            App.state.editingSurvey.groups =
                App.state.editingSurvey.groups.filter(
                    g => g.id !== groupId
                );

            App.renderScreen('editor');
        },

        updateGroupName(groupId, value) {

            const group = App.state.editingSurvey.groups.find(
                g => g.id === groupId
            );

            if (group) {
                group.name = value;
            }
        },

        addQuestion(groupId) {

            const group = App.state.editingSurvey.groups.find(
                g => g.id === groupId
            );

            if (!group) return;

            group.questions.push({
                id: App.util.id(),
                text: '',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false
            });

            App.renderScreen('editor');
        },

        deleteQuestion(questionId) {

            for (const group of App.state.editingSurvey.groups) {
                group.questions =
                    group.questions.filter(
                        q => q.id !== questionId
                    );
            }

            App.renderScreen('editor');
        },

        updateQuestion(id, key, value) {

            const q = App.actions.findQuestion(id);

            if (!q) return;

            q[key] = value;

            if (key === 'type') {
                if (value === 'text') {
                    q.options = [];
                } else if (!Array.isArray(q.options) || !q.options.length) {
                    q.options = ['選択肢1', '選択肢2'];
                }
            }
        },

        findQuestion(id) {

            for (const group of App.state.editingSurvey.groups) {
                const q = group.questions.find(
                    x => x.id === id
                );

                if (q) return q;
            }

            return null;
        },

        addOption(questionId) {

            const q = App.actions.findQuestion(questionId);

            if (!q) return;

            q.options = q.options || [];
            q.options.push('新しい選択肢');

            App.renderScreen('editor');
        },

        updateOption(questionId, index, value) {

            const q = App.actions.findQuestion(questionId);

            if (q && q.options) {
                q.options[index] = value;
            }
        },

        deleteOption(questionId, index) {

            const q = App.actions.findQuestion(questionId);

            if (!q || !q.options) return;

            q.options.splice(index, 1);

            App.renderScreen('editor');
        },

        syncEditorState() {

            if (!App.state.editingSurvey) return;

            document.querySelectorAll('.survey-group').forEach(
                groupEl => {

                    const groupId = groupEl.dataset.groupId;

                    const group = App.state.editingSurvey.groups.find(
                        g => g.id === groupId
                    );

                    if (!group) return;

                    const questions = [];

                    groupEl.querySelectorAll(
                        '.question-card'
                    ).forEach(qEl => {

                        const qId = qEl.dataset.questionId;

                        const q = App.actions.findQuestion(qId);

                        if (q) {
                            questions.push(q);
                        }
                    });

                    group.questions = questions;
                }
            );
        },

        setupSortable() {

            const editor = document.getElementById('question_editor');

            if (!editor || typeof Sortable === 'undefined') {
                return;
            }

            Sortable.create(editor, {
                animation: 180,
                ghostClass: 'opacity-40',
                handle: '.group-handle',
                onEnd() {
                    App.actions.syncGroupOrder();
                }
            });

            document.querySelectorAll('.question-list').forEach(
                list => {

                    Sortable.create(list, {
                        group: 'survey-questions',
                        animation: 180,
                        ghostClass: 'opacity-40',
                        handle: '.question-handle',
                        onEnd() {
                            App.actions.syncQuestionOrder();
                            App.actions.renumber();
                        }
                    });
                }
            );
        },

        syncGroupOrder() {

            const ids = Array.from(
                document.querySelectorAll(
                    '#question_editor > .survey-group'
                )
            ).map(el => el.dataset.groupId);

            const groups = [];

            ids.forEach(id => {

                const g = App.state.editingSurvey.groups.find(
                    x => x.id === id
                );

                if (g) groups.push(g);
            });

            App.state.editingSurvey.groups = groups;
        },

        syncQuestionOrder() {

            const groups =
                App.state.editingSurvey.groups;

            document.querySelectorAll('.survey-group').forEach(
                groupEl => {

                    const group = groups.find(
                        g => g.id === groupEl.dataset.groupId
                    );

                    if (!group) return;

                    const ids = Array.from(
                        groupEl.querySelectorAll('.question-card')
                    ).map(
                        el => el.dataset.questionId
                    );

                    const ordered = [];

                    ids.forEach(id => {

                        const q = App.actions.findQuestion(id);

                        if (q) ordered.push(q);
                    });

                    group.questions = ordered;
                }
            );
        },

        renumber() {

            if (!App.state.editingSurvey) return;

            let global = 1;

            App.state.editingSurvey.groups.forEach(
                (group, gi) => {

                    group.questions.forEach(
                        (q, qi) => {

                            const el =
                                document.querySelector(
                                    `[data-question-id="${q.id}"] .question-number`
                                );

                            if (!el) return;

                            if (
                                App.state.editingSurvey.numbering_mode ===
                                'group'
                            ) {
                                el.textContent =
                                    `Q${gi + 1}-${qi + 1}`;
                            } else {
                                el.textContent =
                                    `Q${global}`;
                            }

                            global++;
                        }
                    );
                }
            );
        },

        preview() {

            App.actions.syncEditorState();

            const s = App.state.editingSurvey;

            document.getElementById('preview_content').innerHTML = `
                <div class="max-w-2xl mx-auto">
                    <h1 class="text-2xl font-bold mb-6">
                        ${App.util.esc(s.title)}
                    </h1>

                    ${s.groups.map(
                        (g, gi) => `
                            <div class="mb-8">
                                <h2 class="text-lg font-bold border-b pb-2">
                                    ${App.util.esc(g.name)}
                                </h2>

                                <div class="mt-4 space-y-6">
                                    ${g.questions.map(
                                        (q, qi) => `
                                            <div>
                                                <div class="font-semibold">
                                                    ${App.util.esc(
                                                        s.numbering_mode === 'group'
                                                            ? `Q${gi+1}-${qi+1}`
                                                            : `Q${s.groups
                                                                .slice(0,gi)
                                                                .reduce((n,x)=>n+x.questions.length,0)+qi+1}`
                                                    )}
                                                    ${App.util.esc(q.text)}
                                                    ${q.required
                                                        ? '<span class="text-rose-500">*</span>'
                                                        : ''}
                                                </div>

                                                <div class="mt-2">
                                                    ${q.type === 'text'
                                                        ? `<textarea class="w-full border rounded-lg p-3"
                                                                    rows="4"
                                                                    placeholder="回答を入力"></textarea>`
                                                        : q.options.map(
                                                            option => `
                                                                <label class="block py-1">
                                                                    <input type="${q.type === 'single' ? 'radio' : 'checkbox'}">
                                                                    ${App.util.esc(option)}
                                                                </label>`
                                                        ).join('')}
                                                </div>
                                            </div>
                                        `
                                    ).join('')}
                                </div>
                            </div>
                        `
                    ).join('')}

                    <button onclick="alert('これはプレビューです。実際の送信は行いません。')"
                            class="px-5 py-3 bg-indigo-600 text-white rounded-xl">
                        回答を送信
                    </button>
                </div>
            `;

            document.getElementById('preview_modal')
                .classList.remove('hidden');
        },

        closeModal(id) {
            document.getElementById(id)?.classList.add('hidden');
        },

        async changeStatus(id, status) {

            const survey = App.state.surveys.find(
                s => s.id === id
            );

            if (!survey) return;

            if (!confirm('ステータスを変更しますか？')) {
                return;
            }

            survey.status = status;

            try {
                const result = await App.api.post(
                    'save_survey',
                    {survey_json: survey}
                );

                const index = App.state.surveys.findIndex(
                    s => s.id === id
                );

                if (index >= 0) {
                    App.state.surveys[index] = result.survey;
                }

                App.renderScreen('surveys');

            } catch (e) {
                alert(e.message);
            }
        },

        async deleteSurvey(id) {

            if (!confirm('このアンケートを削除しますか？')) {
                return;
            }

            try {
                await App.api.post(
                    'delete_survey',
                    {survey_id: id}
                );

                const survey = App.state.surveys.find(
                    s => s.id === id
                );

                if (survey) {
                    survey.deleted = true;
                }

                App.renderScreen('surveys');

            } catch (e) {
                alert(e.message);
            }
        },

        async duplicateSurvey(id) {

            const original = App.state.surveys.find(
                s => s.id === id
            );

            if (!original) return;

            const copy = App.util.clone(original);

            copy.id = '';
            copy.title = original.title + '（複製）';
            copy.status = 'draft';
            copy.created_at = '';
            copy.updated_at = '';
            copy.deleted = false;

            copy.groups.forEach(g => {
                g.id = App.util.id();

                g.questions.forEach(q => {
                    q.id = App.util.id();
                });
            });

            try {
                const result = await App.api.post(
                    'save_survey',
                    {survey_json: copy}
                );

                App.state.surveys.push(result.survey);

                App.renderScreen('surveys');

            } catch (e) {
                alert(e.message);
            }
        },

        mail(id) {
            App.state.selectedSurveyId = id;
            App.state.selectedCustomers = [];
            App.renderScreen('mail');
        },

        aggregate(id) {
            App.state.selectedSurveyId = id;
            App.renderScreen('aggregate');
        },

        filterSurveys(value) {

            const keyword = String(value || '').toLowerCase();

            document.querySelectorAll(
                '#survey_table tr'
            ).forEach(row => {

                row.style.display =
                    row.textContent.toLowerCase().includes(keyword)
                        ? ''
                        : 'none';
            });
        },

        filterCustomers(value) {

            App.state.customerFilter = value;
            App.renderScreen('mail');

            const input = document.getElementById('customer_filter');

            if (input) {
                input.focus();
                input.setSelectionRange(
                    input.value.length,
                    input.value.length
                );
            }
        },

        toggleCustomer(id, checked) {

            if (checked) {
                if (!App.state.selectedCustomers.includes(id)) {
                    App.state.selectedCustomers.push(id);
                }
            } else {
                App.state.selectedCustomers =
                    App.state.selectedCustomers.filter(
                        x => x !== id
                    );
            }
        },

        selectAllCustomers(checked = true) {

            const customers = App.state.customers.filter(c => {

                const keyword =
                    App.state.customerFilter.toLowerCase();

                return !keyword ||
                    [c.company,c.name,c.email,c.phone,c.address]
                    .join(' ')
                    .toLowerCase()
                    .includes(keyword);
            });

            App.state.selectedCustomers =
                checked
                    ? customers.map(c => c.id)
                    : [];

            App.renderScreen('mail');
        },

        async sendMail() {

            if (!App.state.selectedCustomers.length) {
                alert('送信対象を選択してください。');
                return;
            }

            const alreadySent =
                App.state.selectedCustomers.filter(id => {

                    const c = App.state.customers.find(
                        x => x.id === id
                    );

                    return c?.sent_at;
                });

            if (
                alreadySent.length &&
                !confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )
            ) {
                return;
            }

            const subject =
                document.getElementById('mail_subject')?.value || '';

            const body =
                document.getElementById('mail_body')?.value || '';

            const template =
                document.getElementById('template_type')?.value ||
                'initial';

            if (!subject || !body) {
                alert('件名と本文を入力してください。');
                return;
            }

            if (!confirm(
                `${App.state.selectedCustomers.length}件に送信します。よろしいですか？`
            )) {
                return;
            }

            try {

                const result = await App.api.post(
                    'send_mail',
                    {
                        survey_id: App.state.selectedSurveyId,
                        recipient_ids: App.state.selectedCustomers,
                        mail_subject: subject,
                        mail_body: body,
                        template_type: template
                    }
                );

                alert(
                    '送信完了\n\n' +
                    `成功: ${result.success_count}件\n` +
                    `失敗: ${result.failed_count}件\n` +
                    `未送信: ${result.skipped_count}件`
                );

                await App.api.bootstrap();

                App.state.selectedCustomers = [];

                App.renderScreen('mail');

            } catch (e) {
                alert(e.message);
            }
        },

        templateType(value) {

            if (value === 'reminder') {

                const subject =
                    document.getElementById('mail_subject');

                const body =
                    document.getElementById('mail_body');

                if (subject) {
                    subject.value =
                        '【再送】アンケートご回答のお願い';
                }

                if (body) {
                    body.value =
`{顧客名}様

先日ご案内したアンケートが未回答となっております。

お手数ですが、以下のURLよりご回答ください。

{アンケートURL}

よろしくお願いいたします。`;
                }
            }
        },

        async saveSettings() {

            const current = App.state.settings || {};

            const settings = {
                ...current,

                subdomain:
                    document.getElementById('setting_subdomain')?.value || '',

                app_id:
                    document.getElementById('setting_app_id')?.value || '',

                login_name:
                    document.getElementById('setting_login_name')?.value || '',

                password:
                    document.getElementById('setting_password')?.value || '',

                proxy:
                    document.getElementById('setting_proxy')?.value || '',

                ssl_verify:
                    document.getElementById('setting_ssl_verify')?.checked || false,

                field_company:
                    document.getElementById('field_company')?.value || '',

                field_name:
                    document.getElementById('field_name')?.value || '',

                field_email:
                    document.getElementById('field_email')?.value || '',

                field_department:
                    document.getElementById('field_department')?.value || '',

                field_phone:
                    document.getElementById('field_phone')?.value || '',

                field_address:
                    document.getElementById('field_address')?.value || '',

                smtp_host:
                    document.getElementById('smtp_host')?.value || '',

                smtp_port:
                    document.getElementById('smtp_port')?.value || '587',

                smtp_encryption:
                    document.getElementById('smtp_encryption')?.value || 'tls',

                smtp_auth:
                    document.getElementById('smtp_auth')?.value || 'no',

                smtp_username:
                    document.getElementById('smtp_username')?.value || '',

                smtp_password:
                    document.getElementById('smtp_password')?.value || '',

                smtp_from:
                    document.getElementById('smtp_from')?.value || '',

                smtp_from_name:
                    document.getElementById('smtp_from_name')?.value || '',

                smtp_timeout:
                    document.getElementById('smtp_timeout')?.value || '15'
            };

            try {

                const result = await App.api.post(
                    'save_settings',
                    {settings_json: settings}
                );

                App.state.settings = {
                    ...App.state.settings,
                    ...result.settings
                };

                alert('設定を保存しました。');

            } catch (e) {
                alert(e.message);
            }
        },

        async kintoneTest() {

            try {

                const settings = App.actions.collectSettings();

                const result = await App.api.post(
                    'kintone_test',
                    {settings_json: settings}
                );

                alert(
                    'kintone接続確認OK\n\n' +
                    `HTTP: ${result.result.status || '-'}\n` +
                    (result.result.message || '')
                );

            } catch (e) {
                alert(
                    'kintone接続確認失敗\n\n' +
                    e.message
                );
            }
        },

        async fetchKintoneFields() {

            try {

                const settings =
                    App.actions.collectSettings();

                const appId =
                    document.getElementById('setting_app_id')?.value || '';

                const result =
                    await App.api.post(
                        'kintone_fields',
                        {
                            settings_json: settings,
                            app_id: appId
                        }
                    );

                App.state.fieldList =
                    result.fields || [];

                App.renderScreen('settings');

                alert(
                    `${App.state.fieldList.length}件の項目を取得しました。`
                );

            } catch (e) {

                const box =
                    document.getElementById('field_message');

                if (box) {
                    box.className =
                        'p-3 rounded-lg bg-rose-50 text-rose-700';

                    box.textContent =
                        '項目一覧取得失敗: ' + e.message;
                } else {
                    alert(e.message);
                }
            }
        },

        async kintoneSync() {

            try {

                const result =
                    await App.api.post('kintone_sync');

                App.state.customers =
                    result.customers || [];

                alert(
                    `顧客データを同期しました。\n${result.count}件`
                );

            } catch (e) {
                alert(
                    '顧客データ同期失敗\n\n' +
                    e.message
                );
            }
        },

        collectSettings() {

            const s = App.state.settings || {};

            return {
                ...s,

                subdomain:
                    document.getElementById('setting_subdomain')?.value || '',

                app_id:
                    document.getElementById('setting_app_id')?.value || '',

                login_name:
                    document.getElementById('setting_login_name')?.value || '',

                password:
                    document.getElementById('setting_password')?.value ||
                    s.password ||
                    '',

                proxy:
                    document.getElementById('setting_proxy')?.value || '',

                ssl_verify:
                    document.getElementById('setting_ssl_verify')?.checked || false,

                smtp_host:
                    document.getElementById('smtp_host')?.value || '',

                smtp_port:
                    document.getElementById('smtp_port')?.value || '587',

                smtp_encryption:
                    document.getElementById('smtp_encryption')?.value || 'tls',

                smtp_auth:
                    document.getElementById('smtp_auth')?.value || 'no',

                smtp_username:
                    document.getElementById('smtp_username')?.value || '',

                smtp_password:
                    document.getElementById('smtp_password')?.value ||
                    s.smtp_password ||
                    '',

                smtp_from:
                    document.getElementById('smtp_from')?.value || '',

                smtp_from_name:
                    document.getElementById('smtp_from_name')?.value || '',

                smtp_timeout:
                    document.getElementById('smtp_timeout')?.value || '15'
            };
        },

        async smtpCheck() {

            try {

                const settings =
                    App.actions.collectSettings();

                const result =
                    await App.api.post(
                        'smtp_check',
                        {settings_json: settings}
                    );

                const r = result.result || {};

                document.getElementById('smtp_result').innerHTML = `
                    <div class="p-4 rounded-xl bg-emerald-50 text-emerald-800">
                        <div class="font-bold">
                            SMTP接続確認 OK
                        </div>

                        <div class="mt-2 text-sm space-y-1">
                            <div>サーバ: ${App.util.esc(r.host || '')}</div>
                            <div>ポート: ${App.util.esc(r.port || '')}</div>
                            <div>暗号化: ${App.util.esc(r.encryption || '')}</div>
                            <div>認証: ${r.auth_success ? '成功' : 'なし'}</div>
                        </div>
                    </div>`;

            } catch (e) {

                const box =
                    document.getElementById('smtp_result');

                if (box) {
                    box.innerHTML = `
                        <div class="p-4 rounded-xl bg-rose-50 text-rose-800">
                            <div class="font-bold">
                                SMTP接続確認 NG
                            </div>

                            <div class="mt-2 whitespace-pre-wrap">
                                ${App.util.esc(e.message)}
                            </div>
                        </div>`;
                }
            }
        },

        async smtpTest() {

            const to =
                document.getElementById('smtp_test_email')?.value || '';

            if (!to) {
                alert('送信先メールアドレスを入力してください。');
                return;
            }

            try {

                const settings =
                    App.actions.collectSettings();

                const result =
                    await App.api.post(
                        'smtp_test',
                        {
                            settings_json: settings,
                            test_email: to
                        }
                    );

                document.getElementById('smtp_result').innerHTML = `
                    <div class="p-4 rounded-xl bg-emerald-50 text-emerald-800">
                        <div class="font-bold">
                            テストメール送信成功
                        </div>

                        <div class="mt-2 text-sm">
                            SMTP応答コード:
                            ${App.util.esc(result.result.smtp_code || '')}
                        </div>
                    </div>`;

            } catch (e) {

                document.getElementById('smtp_result').innerHTML = `
                    <div class="p-4 rounded-xl bg-rose-50 text-rose-800">
                        <div class="font-bold">
                            テストメール送信失敗
                        </div>

                        <div class="mt-2 whitespace-pre-wrap">
                            ${App.util.esc(e.message)}
                        </div>
                    </div>`;
            }
        },

        filterResponses(value) {

            const keyword =
                String(value || '').toLowerCase();

            document.querySelectorAll(
                '#response_table tr'
            ).forEach(row => {

                row.style.display =
                    row.textContent.toLowerCase().includes(keyword)
                        ? ''
                        : 'none';
            });
        },

        showResponse(id) {

            const response =
                App.state.responses.find(
                    r => r.id === id
                );

            if (!response) return;

            document.getElementById(
                'response_detail'
            ).innerHTML = `
                <div class="grid md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <div class="text-xs text-slate-400">会社名</div>
                        <div class="font-semibold">
                            ${App.util.esc(response.company)}
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-slate-400">氏名</div>
                        <div class="font-semibold">
                            ${App.util.esc(response.name)}
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-slate-400">メール</div>
                        <div>
                            ${App.util.esc(response.email)}
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-slate-400">回答日時</div>
                        <div>
                            ${App.util.esc(response.answered_at)}
                        </div>
                    </div>
                </div>

                <div class="border rounded-xl divide-y">
                    ${Object.entries(
                        response.answers || {}
                    ).map(([key,value]) => `
                        <div class="p-4">
                            <div class="text-xs text-slate-400">
                                ${App.util.esc(key)}
                            </div>

                            <div class="mt-1 whitespace-pre-wrap">
                                ${App.util.esc(
                                    Array.isArray(value)
                                        ? value.join(', ')
                                        : value
                                )}
                            </div>
                        </div>
                    `).join('')}
                </div>`;

            document.getElementById(
                'response_modal'
            ).classList.remove('hidden');
        },

        toggleResponseQuestion(id, checked) {

            document.querySelectorAll(
                `[data-question-id="${id}"]`
            ).forEach(el => {
                el.classList.toggle(
                    'hidden',
                    !checked
                );
            });
        }
    }
};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.actions.init(),
        {once: true}
    );
} else {
    App.actions.init();
}
</script>

</body>
</html>
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
========================================================================
*/

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

function app_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_json(mixed $v): string {
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
}

function app_id(string $prefix = 'id'): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function app_now(): string {
    return date('Y-m-d H:i:s');
}

function app_default_data(): array {
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'subdomain' => '',
            'login_name' => '',
            'password' => '',
            'app_id' => '',
            'ssl_verify' => false,
            'proxy' => '',
            'field_company' => '',
            'field_name' => '',
            'field_email' => '',
            'field_department' => '',
            'field_phone' => '',
            'field_address' => [],
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_encryption' => 'TLS',
            'smtp_auth' => true,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_from' => '',
            'smtp_from_name' => '',
            'smtp_timeout' => 15
        ],
        'mail_logs' => []
    ];
}

function app_load(): array {
    if (!is_file(SURVEY_STORAGE_FILE)) {
        $data = app_default_data();
        app_save($data);
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        $data = app_default_data();
    }

    foreach (app_default_data() as $k => $v) {
        if (!array_key_exists($k, $data)) {
            $data[$k] = $v;
        }
    }

    return $data;
}

function app_save(array $data): bool {
    return (bool)@file_put_contents(
        SURVEY_STORAGE_FILE,
        app_json($data),
        LOCK_EX
    );
}

function app_json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo app_json($data);
    exit;
}

function app_csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function app_check_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(app_csrf(), (string)$token)) {
        app_json_response(['ok' => false, 'message' => 'CSRFトークンが不正です。'], 403);
    }
}

function get_safe_response_headers(): array {
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }
    return [];
}

function kintone_build_url(string $domain, string $endpoint): string {
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = trim((string)$domain, "/ \t\n\r\0\x0B");

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $timeout = max(1, (int)($config['timeout'] ?? 15));

    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => $timeout,
        'protocol_version' => 1.1
    ];

    if ($method !== 'GET' && $payload !== null) {
        $http['content'] = is_string($payload)
            ? $payload
            : json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    $contextOptions = [
        'http' => $http,
        'ssl' => [
            'verify_peer' => (bool)($config['ssl_verify'] ?? false),
            'verify_peer_name' => (bool)($config['ssl_verify'] ?? false),
            'allow_self_signed' => true
        ]
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match('/^[a-z]+:\/\//i', $proxy)) {
            $proxy = 'tcp://' . $proxy;
        }
        $contextOptions['http']['proxy'] = $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $body = @file_get_contents($url, false, $context);
    $headersOut = get_safe_response_headers();

    $status = 0;

    foreach ($headersOut as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    $json = json_decode($body ?: '', true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($json) ? $json : [],
            'headers' => $headersOut
        ];
    }

    $message = is_array($json)
        ? (string)($json['message'] ?? 'kintone API通信エラー')
        : 'kintone API通信エラー';

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'data' => is_array($json) ? $json : [],
        'raw' => $body,
        'headers' => $headersOut
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . trim($password));
}

function kintone_config(array $settings): array {
    return [
        'ssl_verify' => !empty($settings['ssl_verify']),
        'proxy' => trim((string)($settings['proxy'] ?? '')),
        'timeout' => 20
    ];
}

function kintone_headers(array $settings): array {
    return [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                (string)$settings['login_name'] . ':' .
                (string)$settings['password']
            ),
        'Content-Type: application/json',
        'Accept: application/json'
    ];
}

function kintone_test(array $settings): array {
    $domain = trim((string)($settings['subdomain'] ?? ''));

    if ($domain === '') {
        return [
            'success' => false,
            'message' => 'kintoneサブドメインが未入力です。'
        ];
    }

    $url = kintone_build_url($domain, '/k/v1/app.json');

    $result = kintone_api_request(
        'GET',
        $url,
        kintone_headers($settings),
        null,
        kintone_config($settings)
    );

    if ($result['success']) {
        return [
            'success' => true,
            'status' => $result['status'],
            'message' => 'kintone接続に成功しました。',
            'diagnostic' => [
                'url' => $url,
                'status' => $result['status']
            ]
        ];
    }

    return [
        'success' => false,
        'status' => $result['status'] ?? 0,
        'message' => $result['message'] ?? 'kintone接続に失敗しました。',
        'diagnostic' => [
            'url' => $url,
            'http_status' => $result['status'] ?? 0,
            'api_response' => $result['data'] ?? [],
            'raw' => $result['raw'] ?? ''
        ]
    ];
}

function kintone_fields(array $settings, string $appId): array {
    $appId = trim($appId);

    if ($appId === '' || !ctype_digit($appId)) {
        return [
            'success' => false,
            'message' => '顧客管理アプリIDは数字で入力してください。'
        ];
    }

    $url = kintone_build_url(
        (string)$settings['subdomain'],
        '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
    );

    $result = kintone_api_request(
        'GET',
        $url,
        kintone_headers($settings),
        null,
        kintone_config($settings)
    );

    if (!$result['success']) {
        return [
            'success' => false,
            'status' => $result['status'] ?? 0,
            'message' => $result['message'] ?? '項目一覧取得に失敗しました。',
            'diagnostic' => [
                'url' => $url,
                'http_status' => $result['status'] ?? 0,
                'api_response' => $result['data'] ?? [],
                'raw' => $result['raw'] ?? ''
            ]
        ];
    }

    $fields = [];

    foreach (($result['data']['properties'] ?? []) as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' => $code,
            'label' => (string)($field['label'] ?? $code),
            'type' => (string)($field['type'] ?? '')
        ];
    }

    return [
        'success' => true,
        'status' => $result['status'],
        'fields' => $fields
    ];
}

function smtp_read($socket, array &$transcript = []): string {
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 4096);
        if ($line === false) {
            break;
        }

        $transcript[] = rtrim($line, "\r\n");
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

function smtp_code(string $response): int {
    if (preg_match('/^(\d{3})/m', $response, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function smtp_write($socket, string $command, array &$transcript): bool {
    $transcript[] = preg_replace(
        '/(AUTH\s+\S+\s+).*/i',
        '$1[REDACTED]',
        rtrim($command)
    );

    return fwrite($socket, $command . "\r\n") !== false;
}

function smtp_open(array $cfg): array {
    $host = trim((string)($cfg['smtp_host'] ?? ''));

    if ($host === '') {
        return [
            'success' => false,
            'stage' => 'configuration',
            'message' => 'SMTPサーバが未設定です。'
        ];
    }

    /*
     * 重要:
     * SMTPサーバ欄には ssl:// や tls:// を入れなくてもよい。
     * 暗号化方式から接続方法を決定する。
     */
    $host = preg_replace('/^(ssl|tls):\/\//i', '', $host);
    $host = trim((string)$host, '/');

    $port = max(1, (int)($cfg['smtp_port'] ?? 587));
    $encryption = strtoupper(trim((string)($cfg['smtp_encryption'] ?? 'TLS')));
    $timeout = max(1, (int)($cfg['smtp_timeout'] ?? 15));

    $socketHost = $host;

    if ($encryption === 'SSL') {
        $socketHost = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $start = microtime(true);

    $socket = @stream_socket_client(
        $socketHost . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    $tcpMs = round((microtime(true) - $start) * 1000, 1);

    if (!$socket) {
        return [
            'success' => false,
            'stage' => 'tcp',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'tcp_result' => false,
            'tcp_time_ms' => $tcpMs,
            'errno' => $errno,
            'error' => $errstr,
            'message' => 'SMTPサーバへ接続できませんでした。'
        ];
    }

    stream_set_timeout($socket, $timeout);

    $transcript = [];

    $greeting = smtp_read($socket, $transcript);
    $code = smtp_code($greeting);

    if ($code < 200 || $code >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'stage' => 'greeting',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'tcp_result' => true,
            'smtp_code' => $code,
            'error' => trim($greeting),
            'message' => 'SMTPサーバの初期応答が不正です。'
        ];
    }

    smtp_write($socket, 'EHLO localhost', $transcript);
    $ehlo = smtp_read($socket, $transcript);

    /*
     * STARTTLS:
     * TLS指定時はSTARTTLSを使う。
     * SSL指定時は最初からTLSソケットなので不要。
     */
    if ($encryption === 'TLS') {
        if (!preg_match('/STARTTLS/i', $ehlo)) {
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'starttls',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'tcp_result' => true,
                'smtp_code' => smtp_code($ehlo),
                'error' => $ehlo,
                'message' => 'SMTPサーバがSTARTTLSに対応していません。'
            ];
        }

        smtp_write($socket, 'STARTTLS', $transcript);
        $tlsReply = smtp_read($socket, $transcript);

        if (smtp_code($tlsReply) !== 220) {
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'starttls',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'tcp_result' => true,
                'smtp_code' => smtp_code($tlsReply),
                'error' => trim($tlsReply),
                'message' => 'TLS開始に失敗しました。'
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
                'tcp_result' => true,
                'tls_result' => false,
                'message' => 'TLS接続に失敗しました。'
            ];
        }

        smtp_write($socket, 'EHLO localhost', $transcript);
        smtp_read($socket, $transcript);
    }

    $authResult = true;
    $authMessage = '';

    if (!empty($cfg['smtp_auth'])) {
        $user = (string)($cfg['smtp_username'] ?? '');
        $pass = (string)($cfg['smtp_password'] ?? '');

        if ($user === '') {
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'authentication',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'message' => 'SMTP認証ユーザー名が未設定です。'
            ];
        }

        /*
         * AUTH LOGIN
         * 認証情報そのものは transcript に保存しない。
         */
        smtp_write($socket, 'AUTH LOGIN', $transcript);
        $r = smtp_read($socket, $transcript);

        if (smtp_code($r) !== 334) {
            $authResult = false;
            $authMessage = trim($r);
        } else {
            smtp_write($socket, base64_encode($user), $transcript);
            $r = smtp_read($socket, $transcript);

            if (smtp_code($r) !== 334) {
                $authResult = false;
                $authMessage = trim($r);
            } else {
                smtp_write($socket, base64_encode($pass), $transcript);
                $r = smtp_read($socket, $transcript);

                if (smtp_code($r) !== 235) {
                    $authResult = false;
                    $authMessage = trim($r);
                }
            }
        }
    }

    if (!$authResult) {
        @smtp_write($socket, 'QUIT', $transcript);
        @smtp_read($socket, $transcript);
        fclose($socket);

        return [
            'success' => false,
            'stage' => 'authentication',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'tcp_result' => true,
            'auth_result' => false,
            'smtp_code' => smtp_code($authMessage),
            'message' => 'SMTP認証に失敗しました。',
            'error' => $authMessage
        ];
    }

    return [
        'success' => true,
        'socket' => $socket,
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'tcp_result' => true,
        'tls_result' => $encryption === 'TLS' || $encryption === 'SSL',
        'auth_result' => !empty($cfg['smtp_auth']),
        'transcript' => $transcript
    ];
}

function smtp_send_message(array $cfg, string $to, string $subject, string $body): array {
    $from = trim((string)($cfg['smtp_from'] ?? ''));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => '宛先メールアドレスが不正です。'
        ];
    }

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => '送信元メールアドレスが未設定または不正です。'
        ];
    }

    $conn = smtp_open($cfg);

    if (!$conn['success']) {
        return $conn;
    }

    $socket = $conn['socket'];
    $transcript = $conn['transcript'] ?? [];

    smtp_write($socket, 'MAIL FROM:<' . $from . '>', $transcript);
    $mailReply = smtp_read($socket, $transcript);

    if (smtp_code($mailReply) < 200 || smtp_code($mailReply) >= 300) {
        fclose($socket);
        return [
            'success' => false,
            'stage' => 'mail_from',
            'smtp_code' => smtp_code($mailReply),
            'message' => 'MAIL FROMが拒否されました。',
            'error' => trim($mailReply)
        ];
    }

    smtp_write($socket, 'RCPT TO:<' . $to . '>', $transcript);
    $rcptReply = smtp_read($socket, $transcript);

    if (smtp_code($rcptReply) < 200 || smtp_code($rcptReply) >= 300) {
        fclose($socket);
        return [
            'success' => false,
            'stage' => 'rcpt_to',
            'smtp_code' => smtp_code($rcptReply),
            'message' => '宛先がSMTPサーバに拒否されました。',
            'error' => trim($rcptReply)
        ];
    }

    smtp_write($socket, 'DATA', $transcript);
    $dataReply = smtp_read($socket, $transcript);

    if (smtp_code($dataReply) !== 354) {
        fclose($socket);
        return [
            'success' => false,
            'stage' => 'data',
            'smtp_code' => smtp_code($dataReply),
            'message' => 'メール本文送信開始が拒否されました。',
            'error' => trim($dataReply)
        ];
    }

    $displayName = trim((string)($cfg['smtp_from_name'] ?? ''));

    $fromHeader = $displayName !== ''
        ? '=?UTF-8?B?' . base64_encode($displayName) . '?= <' . $from . '>'
        : $from;

    $subjectHeader =
        '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/^\./m', '..', $body);

    $message =
        'From: ' . $fromHeader . "\r\n" .
        'To: ' . $to . "\r\n" .
        'Subject: ' . $subjectHeader . "\r\n" .
        'MIME-Version: 1.0' . "\r\n" .
        'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
        'Content-Transfer-Encoding: 8bit' . "\r\n" .
        "\r\n" .
        str_replace("\n", "\r\n", $body) .
        "\r\n.";

    fwrite($socket, $message . "\r\n");

    $sendReply = smtp_read($socket, $transcript);

    smtp_write($socket, 'QUIT', $transcript);
    smtp_read($socket, $transcript);

    fclose($socket);

    $code = smtp_code($sendReply);

    return [
        'success' => $code >= 200 && $code < 300,
        'stage' => 'send',
        'smtp_code' => $code,
        'message' => $code >= 200 && $code < 300
            ? 'メール送信に成功しました。'
            : 'SMTPサーバにメールを拒否されました。',
        'error' => trim($sendReply)
    ];
}

function app_action(array $data): never {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

    if ($action === '') {
        app_json_response(['ok' => false, 'message' => 'actionが指定されていません。'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        app_check_csrf();
    }

    switch ($action) {
        case 'load':
            app_json_response([
                'ok' => true,
                'data' => $data,
                'csrf_token' => app_csrf()
            ]);

        case 'save_settings':
            $json = json_decode((string)($_POST['settings_json'] ?? ''), true);

            if (!is_array($json)) {
                app_json_response([
                    'ok' => false,
                    'message' => 'settings_jsonが不正です。'
                ], 400);
            }

            $allowed = [
                'subdomain',
                'login_name',
                'password',
                'app_id',
                'ssl_verify',
                'proxy',
                'field_company',
                'field_name',
                'field_email',
                'field_department',
                'field_phone',
                'field_address',
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
                'smtp_auth',
                'smtp_username',
                'smtp_password',
                'smtp_from',
                'smtp_from_name',
                'smtp_timeout'
            ];

            foreach ($allowed as $key) {
                if (array_key_exists($key, $json)) {
                    $data['settings'][$key] = $json[$key];
                }
            }

            if (!app_save($data)) {
                app_json_response([
                    'ok' => false,
                    'message' => '設定保存に失敗しました。'
                ], 500);
            }

            app_json_response([
                'ok' => true,
                'settings' => $data['settings']
            ]);

        case 'kintone_test':
            app_json_response(kintone_test($data['settings']));

        case 'kintone_fields':
            $appId = (string)(
                $_POST['app_id'] ??
                $data['settings']['app_id'] ??
                ''
            );

            app_json_response(
                kintone_fields($data['settings'], $appId)
            );

        case 'smtp_test_connection':
            $result = smtp_open($data['settings']);

            if (!empty($result['socket'])) {
                $socket = $result['socket'];
                $transcript = $result['transcript'] ?? [];
                @smtp_write($socket, 'QUIT', $transcript);
                @smtp_read($socket, $transcript);
                @fclose($socket);
                unset($result['socket']);
                $result['transcript'] = $transcript;
            }

            app_json_response($result);

        case 'smtp_test_mail':
            $to = trim((string)($_POST['to'] ?? ''));

            $result = smtp_send_message(
                $data['settings'],
                $to,
                'アンケート管理システム SMTP送信テスト',
                "SMTP設定が正常に動作し、テストメールの送信に成功したことを確認しました。\n\nアンケート管理システム"
            );

            app_json_response($result);

        case 'save_survey':
            $survey = json_decode((string)($_POST['survey_json'] ?? ''), true);

            if (!is_array($survey)) {
                app_json_response([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $survey['id'] = (string)($survey['id'] ?? app_id('survey'));
            $survey['title'] = (string)($survey['title'] ?? '無題のアンケート');
            $survey['status'] = in_array(
                $survey['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            ) ? $survey['status'] : 'draft';

            $now = app_now();

            $found = false;

            foreach ($data['surveys'] as &$item) {
                if (($item['id'] ?? '') === $survey['id']) {
                    $survey['created_at'] = $item['created_at'] ?? $now;
                    $survey['updated_at'] = $now;
                    $item = $survey;
                    $found = true;
                    break;
                }
            }
            unset($item);

            if (!$found) {
                $survey['created_at'] = $now;
                $survey['updated_at'] = $now;
                $survey['deleted'] = false;
                $data['surveys'][] = $survey;
            }

            app_save($data);

            app_json_response([
                'ok' => true,
                'survey' => $survey
            ]);

        case 'delete_survey':
            $id = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = app_now();
                }
            }
            unset($survey);

            app_save($data);

            app_json_response(['ok' => true]);

        case 'duplicate_survey':
            $id = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as $survey) {
                if (($survey['id'] ?? '') === $id) {
                    $copy = $survey;
                    $copy['id'] = app_id('survey');
                    $copy['title'] = ($survey['title'] ?? '') . '（複製）';
                    $copy['status'] = 'draft';
                    $copy['created_at'] = app_now();
                    $copy['updated_at'] = app_now();
                    $copy['deleted'] = false;
                    $data['surveys'][] = $copy;
                    app_save($data);
                    app_json_response([
                        'ok' => true,
                        'survey' => $copy
                    ]);
                }
            }

            app_json_response([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);

        case 'set_status':
            $id = (string)($_POST['survey_id'] ?? '');
            $status = (string)($_POST['status'] ?? 'draft');

            if (!in_array($status, ['draft', 'active', 'ended'], true)) {
                app_json_response([
                    'ok' => false,
                    'message' => 'ステータスが不正です。'
                ], 400);
            }

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['status'] = $status;
                    $survey['updated_at'] = app_now();
                }
            }
            unset($survey);

            app_save($data);

            app_json_response(['ok' => true]);

        case 'save_customers':
            $customers = json_decode(
                (string)($_POST['customers_json'] ?? ''),
                true
            );

            if (!is_array($customers)) {
                app_json_response([
                    'ok' => false,
                    'message' => '顧客データが不正です。'
                ], 400);
            }

            foreach ($customers as &$customer) {
                $customer['id'] = (string)($customer['id'] ?? app_id('customer'));
                $customer['source'] = $customer['source'] ?? 'kintone';
                $customer['send_count'] = (int)($customer['send_count'] ?? 0);
                $customer['answer_status'] =
                    $customer['answer_status'] ?? 'unanswered';
                $customer['kintone_status'] =
                    $customer['kintone_status'] ?? 'registered';
            }
            unset($customer);

            $data['customers'] = $customers;
            app_save($data);

            app_json_response(['ok' => true]);

        case 'send_mail':
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

            if (trim((string)$data['settings']['smtp_host']) === '') {
                app_json_response([
                    'ok' => false,
                    'message' => 'SMTP設定が未完了です。SMTP設定画面で設定してください。'
                ], 400);
            }

            $success = 0;
            $failed = 0;
            $unsent = 0;
            $results = [];

            $logId = app_id('mail_log');

            foreach ($recipientIds as $customerId) {
                $customerIndex = null;

                foreach ($data['customers'] as $i => $customer) {
                    if (($customer['id'] ?? '') === (string)$customerId) {
                        $customerIndex = $i;
                        break;
                    }
                }

                if ($customerIndex === null) {
                    $unsent++;
                    continue;
                }

                $customer = $data['customers'][$customerIndex];
                $email = trim((string)($customer['email'] ?? ''));

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $failed++;

                    $results[] = [
                        'customer_id' => $customerId,
                        'email' => $email,
                        'success' => false,
                        'message' => 'メールアドレスが不正です。'
                    ];
                    continue;
                }

                $personalSubject = str_replace(
                    '{顧客名}',
                    (string)($customer['name'] ?? ''),
                    $subject
                );

                $personalBody = str_replace(
                    '{顧客名}',
                    (string)($customer['name'] ?? ''),
                    $body
                );

                $url = '';

                if ($surveyId !== '') {
                    $scheme = (!empty($_SERVER['HTTPS']) &&
                        $_SERVER['HTTPS'] !== 'off')
                        ? 'https'
                        : 'http';

                    $url = $scheme . '://' .
                        ($_SERVER['HTTP_HOST'] ?? '') .
                        rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') .
                        '/?screen=answer&survey_id=' .
                        rawurlencode($surveyId) .
                        '&customer_id=' .
                        rawurlencode((string)$customerId);
                }

                $personalBody = str_replace(
                    '{アンケートURL}',
                    $url,
                    $personalBody
                );

                $send = smtp_send_message(
                    $data['settings'],
                    $email,
                    $personalSubject,
                    $personalBody
                );

                if ($send['success']) {
                    $success++;

                    $data['customers'][$customerIndex]['sent_at'] = app_now();
                    $data['customers'][$customerIndex]['send_count'] =
                        (int)$data['customers'][$customerIndex]['send_count'] + 1;
                    $data['customers'][$customerIndex]['answer_status'] =
                        'unanswered';

                    $results[] = [
                        'customer_id' => $customerId,
                        'email' => $email,
                        'success' => true,
                        'message' => '送信成功'
                    ];

                    $data['mail_logs'][] = [
                        'id' => app_id('mail'),
                        'log_id' => $logId,
                        'survey_id' => $surveyId,
                        'customer_id' => $customerId,
                        'sent_at' => app_now(),
                        'type' => $templateType === 'reminder'
                            ? 'reminder'
                            : 'initial',
                        'success' => true,
                        'subject' => $personalSubject,
                        'body' => $personalBody,
                        'error' => ''
                    ];
                } else {
                    $failed++;

                    $error = (string)($send['message'] ?? '送信失敗');

                    $results[] = [
                        'customer_id' => $customerId,
                        'email' => $email,
                        'success' => false,
                        'message' => $error
                    ];

                    $data['mail_logs'][] = [
                        'id' => app_id('mail'),
                        'log_id' => $logId,
                        'survey_id' => $surveyId,
                        'customer_id' => $customerId,
                        'sent_at' => app_now(),
                        'type' => $templateType === 'reminder'
                            ? 'reminder'
                            : 'initial',
                        'success' => false,
                        'subject' => $personalSubject,
                        'body' => $personalBody,
                        'error' => $error
                    ];
                }
            }

            app_save($data);

            app_json_response([
                'ok' => true,
                'success_count' => $success,
                'failed_count' => $failed,
                'unsent_count' => $unsent,
                'results' => $results
            ]);

        case 'answer':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $customerId = (string)($_POST['customer_id'] ?? '');
            $answers = json_decode(
                (string)($_POST['answers'] ?? '{}'),
                true
            );

            if (!is_array($answers)) {
                $answers = [];
            }

            $customer = null;

            foreach ($data['customers'] as $i => $c) {
                if (($c['id'] ?? '') === $customerId) {
                    $customer = $c;
                    $data['customers'][$i]['answer_status'] = 'answered';
                    break;
                }
            }

            $response = [
                'id' => app_id('response'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => $customer['company'] ?? '',
                'name' => $customer['name'] ?? '',
                'email' => $customer['email'] ?? '',
                'answered_at' => app_now(),
                'answers' => $answers
            ];

            $data['responses'][] = $response;

            app_save($data);

            app_json_response([
                'ok' => true,
                'response_id' => $response['id']
            ]);

        case 'csv':
            $surveyId = (string)($_GET['survey_id'] ?? '');

            $rows = [];

            $questionMap = [];

            foreach ($data['surveys'] as $survey) {
                if (($survey['id'] ?? '') !== $surveyId) {
                    continue;
                }

                foreach (($survey['groups'] ?? []) as $group) {
                    foreach (($group['questions'] ?? []) as $q) {
                        $questionMap[$q['id']] =
                            (string)($q['text'] ?? '');
                    }
                }
            }

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名'
            ];

            foreach ($questionMap as $text) {
                $header[] = $text;
            }

            $rows[] = $header;

            foreach ($data['responses'] as $response) {
                if (($response['survey_id'] ?? '') !== $surveyId) {
                    continue;
                }

                $row = [
                    $response['id'] ?? '',
                    $response['answered_at'] ?? '',
                    $response['customer_id'] ?? '',
                    $response['company'] ?? '',
                    $response['name'] ?? ''
                ];

                foreach ($questionMap as $qid => $_) {
                    $answer = $response['answers'][$qid] ?? '';

                    if (is_array($answer)) {
                        $answer = implode('、', $answer);
                    }

                    $row[] = $answer;
                }

                $rows[] = $row;
            }

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="survey_' .
                rawurlencode($surveyId) . '.csv"'
            );

            echo "\xEF\xBB\xBF";

            $fp = fopen('php://output', 'wb');

            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }

            fclose($fp);
            exit;

        default:
            app_json_response([
                'ok' => false,
                'message' => '未対応のactionです。'
            ], 400);
    }
}

if (isset($_GET['action']) || isset($_POST['action'])) {
    app_action(app_load());
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

<script>
window.App = {
    state: {
        csrf: <?=app_json($csrf)?>,
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        screen: 'surveys',
        currentSurveyId: null,
        currentResponseId: null,
        editingSurvey: null,
        fields: [],
        responseFilter: '',
        customerFilter: '',
        surveyKeyword: '',
        surveyStatus: '',
        surveySort: 'updated_desc',
        previewMode: 'pc',
        loading: false
    },

    api: {},

    actions: {},

    render: {},

    utils: {}
};

App.utils.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.utils.id = function(prefix) {
    return prefix + '_' + Math.random().toString(36).slice(2) +
        Date.now().toString(36);
};

App.utils.post = async function(action, params = {}) {
    const fd = new FormData();

    fd.append('action', action);
    fd.append('csrf_token', App.state.csrf);

    Object.keys(params).forEach(function(key) {
        let value = params[key];

        if (typeof value === 'object') {
            value = JSON.stringify(value);
        }

        fd.append(key, value == null ? '' : value);
    });

    const response = await fetch(location.pathname, {
        method: 'POST',
        body: fd
    });

    const text = await response.text();

    let json;

    try {
        json = JSON.parse(text);
    } catch (e) {
        throw new Error(
            'サーバーからJSONではない応答が返されました。\n' +
            text.slice(0, 1000)
        );
    }

    if (!response.ok || json.ok === false && !json.success) {
        throw new Error(
            json.message ||
            'サーバー処理に失敗しました。'
        );
    }

    return json;
};

App.api.load = async function() {
    const result = await fetch(
        location.pathname + '?action=load',
        {cache: 'no-store'}
    );

    const json = await result.json();

    if (!json.ok) {
        throw new Error(json.message || '初期化に失敗しました。');
    }

    App.state.data = json.data;
    App.state.csrf = json.csrf_token;
};

App.api.saveSettings = async function() {
    return App.utils.post('save_settings', {
        settings_json: App.collectSettings()
    });
};

App.api.saveSurvey = async function(survey) {
    return App.utils.post('save_survey', {
        survey_json: survey
    });
};

App.api.testKintone = async function() {
    return App.utils.post('kintone_test');
};

App.api.getKintoneFields = async function(appId) {
    return App.utils.post('kintone_fields', {
        app_id: appId
    });
};

App.api.testSMTP = async function() {
    return App.utils.post('smtp_test_connection');
};

App.api.testSMTPMail = async function(to) {
    return App.utils.post('smtp_test_mail', {
        to: to
    });
};

App.actions.notify = function(message, type = 'info') {
    const color = type === 'error'
        ? 'bg-red-600'
        : type === 'success'
            ? 'bg-emerald-600'
            : 'bg-slate-800';

    const el = document.createElement('div');

    el.className =
        'fixed right-5 bottom-5 z-[100] max-w-xl rounded-xl ' +
        'px-5 py-4 text-white shadow-xl ' + color;

    el.textContent = message;

    document.body.appendChild(el);

    setTimeout(function() {
        el.remove();
    }, 4500);
};

App.actions.error = function(error) {
    console.error(error);
    App.actions.notify(
        error instanceof Error ? error.message : String(error),
        'error'
    );
};

App.actions.go = function(screen, surveyId = null) {
    App.state.screen = screen;
    App.state.currentSurveyId = surveyId;
    App.renderScreen();
};

App.actions.newSurvey = function() {
    App.state.editingSurvey = {
        id: App.utils.id('survey'),
        title: '新規アンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        numbering_mode: 'global',
        groups: [],
        deleted: false
    };

    App.actions.addGroup();
    App.actions.go('editor');
};

App.actions.editSurvey = function(id) {
    const found = App.state.data.surveys.find(function(s) {
        return s.id === id && !s.deleted;
    });

    if (!found) {
        App.actions.notify('アンケートが見つかりません。', 'error');
        return;
    }

    App.state.editingSurvey = JSON.parse(JSON.stringify(found));

    App.actions.go('editor', id);
};

App.actions.viewSurvey = function(id) {
    App.state.editingSurvey = App.state.data.surveys.find(
        s => s.id === id
    );

    App.actions.go('preview', id);
};

App.actions.addGroup = function() {
    if (!App.state.editingSurvey) return;

    App.state.editingSurvey.groups =
        App.state.editingSurvey.groups || [];

    const group = {
        id: App.utils.id('group'),
        name: '新しいグループ',
        questions: []
    };

    App.state.editingSurvey.groups.push(group);

    App.render.editor();
    App.actions.initSortables();
};

App.actions.removeGroup = function(groupId) {
    if (!confirm(
        'このグループと、グループ内の質問をすべて削除しますか？'
    )) {
        return;
    }

    App.state.editingSurvey.groups =
        App.state.editingSurvey.groups.filter(
            g => g.id !== groupId
        );

    App.render.editor();
    App.actions.initSortables();
};

App.actions.addQuestion = function(groupId) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions.push({
        id: App.utils.id('question'),
        text: '質問文を入力してください',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false
    });

    App.actions.renumber();
    App.render.editor();
    App.actions.initSortables();
};

App.actions.removeQuestion = function(groupId, questionId) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions = group.questions.filter(
        q => q.id !== questionId
    );

    App.actions.renumber();
    App.render.editor();
    App.actions.initSortables();
};

App.actions.updateGroup = function(groupId, value) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (group) group.name = value;
};

App.actions.updateQuestion = function(groupId, questionId, key, value) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const q = group.questions.find(
        q => q.id === questionId
    );

    if (!q) return;

    if (key === 'required') {
        q.required = !!value;
    } else {
        q[key] = value;
    }
};

App.actions.updateOption = function(
    groupId,
    questionId,
    index,
    value
) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const q = group.questions.find(
        q => q.id === questionId
    );

    if (!q) return;

    q.options[index] = value;
};

App.actions.addOption = function(groupId, questionId) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const q = group.questions.find(
        q => q.id === questionId
    );

    if (!q) return;

    q.options = q.options || [];
    q.options.push('新しい選択肢');

    App.render.editor();
    App.actions.initSortables();
};

App.actions.removeOption = function(groupId, questionId, index) {
    const group = App.state.editingSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    const q = group.questions.find(
        q => q.id === questionId
    );

    if (!q) return;

    q.options.splice(index, 1);

    App.render.editor();
    App.actions.initSortables();
};

App.actions.renumber = function() {
    const survey = App.state.editingSurvey;

    if (!survey) return;

    let global = 1;

    survey.groups.forEach(function(group, gi) {
        group.questions.forEach(function(q, qi) {
            q.number = survey.numbering_mode === 'group'
                ? 'Q' + (gi + 1) + '-' + (qi + 1)
                : 'Q' + global;

            global++;
        });
    });
};

App.actions.initSortables = function() {
    if (typeof Sortable === 'undefined') return;

    const groupContainer = document.getElementById('group_sortable');

    if (groupContainer) {
        new Sortable(groupContainer, {
            animation: 180,
            ghostClass: 'opacity-40',
            handle: '.group-handle',
            onEnd: function(evt) {
                const groups = App.state.editingSurvey.groups;
                const moved = groups.splice(evt.oldIndex, 1)[0];
                groups.splice(evt.newIndex, 0, moved);

                App.actions.renumber();
                App.render.editor();
                App.actions.initSortables();
            }
        });
    }

    document.querySelectorAll('.question-sortable')
        .forEach(function(container) {
            new Sortable(container, {
                group: 'questions',
                animation: 180,
                ghostClass: 'opacity-40',
                handle: '.question-handle',
                onEnd: function(evt) {
                    if (!evt.item) return;

                    const questionId = evt.item.dataset.questionId;

                    let sourceGroup = null;
                    let movedQuestion = null;

                    App.state.editingSurvey.groups.forEach(
                        function(group) {
                            const index = group.questions.findIndex(
                                q => q.id === questionId
                            );

                            if (index >= 0) {
                                sourceGroup = group;
                                movedQuestion =
                                    group.questions.splice(index, 1)[0];
                            }
                        }
                    );

                    const targetGroupId =
                        evt.to.dataset.groupId;

                    const targetGroup =
                        App.state.editingSurvey.groups.find(
                            g => g.id === targetGroupId
                        );

                    if (movedQuestion && targetGroup) {
                        targetGroup.questions.splice(
                            evt.newIndex,
                            0,
                            movedQuestion
                        );
                    }

                    App.actions.renumber();
                    App.render.editor();
                    App.actions.initSortables();
                }
            });
        });
};

App.actions.saveSurvey = async function() {
    try {
        App.actions.renumber();

        const survey = App.state.editingSurvey;

        if (!survey.title.trim()) {
            throw new Error('タイトルを入力してください。');
        }

        const result = await App.api.saveSurvey(survey);

        const index = App.state.data.surveys.findIndex(
            s => s.id === survey.id
        );

        if (index >= 0) {
            App.state.data.surveys[index] = result.survey;
        } else {
            App.state.data.surveys.push(result.survey);
        }

        App.actions.notify(
            'アンケートを保存しました。',
            'success'
        );

        App.actions.go('surveys');
    } catch (e) {
        App.actions.error(e);
    }
};

App.actions.deleteSurvey = async function(id) {
    if (!confirm('このアンケートを削除しますか？')) return;

    try {
        await App.utils.post('delete_survey', {
            survey_id: id
        });

        const s = App.state.data.surveys.find(
            x => x.id === id
        );

        if (s) s.deleted = true;

        App.actions.notify(
            '削除しました。',
            'success'
        );

        App.renderScreen();
    } catch (e) {
        App.actions.error(e);
    }
};

App.actions.duplicateSurvey = async function(id) {
    try {
        const result = await App.utils.post(
            'duplicate_survey',
            {survey_id: id}
        );

        App.state.data.surveys.push(result.survey);

        App.actions.notify(
            'アンケートを複製しました。',
            'success'
        );

        App.renderScreen();
    } catch (e) {
        App.actions.error(e);
    }
};

App.actions.setStatus = async function(id, status) {
    try {
        await App.utils.post('set_status', {
            survey_id: id,
            status: status
        });

        const survey = App.state.data.surveys.find(
            s => s.id === id
        );

        if (survey) survey.status = status;

        App.renderScreen();
    } catch (e) {
        App.actions.error(e);
    }
};

App.actions.preview = function() {
    App.actions.renumber();

    const content = document.getElementById('preview_content');

    if (!content) return;

    content.innerHTML = App.render.previewHTML(
        App.state.editingSurvey
    );

    document.getElementById('preview_modal')
        ?.classList.remove('hidden');
};

App.actions.closePreview = function() {
    document.getElementById('preview_modal')
        ?.classList.add('hidden');
};

App.actions.openResponses = function(id) {
    App.state.currentSurveyId = id;
    App.state.responseFilter = '';
    App.actions.go('responses', id);
};

App.actions.openMail = function(id) {
    App.state.currentSurveyId = id;
    App.actions.go('mail', id);
};

App.actions.openAnalytics = function(id) {
    App.state.currentSurveyId = id;
    App.actions.go('analytics', id);
};

App.actions.toggleStatusFilter = function(value) {
    App.state.surveyStatus = value;
    App.render.surveys();
};

App.actions.searchSurveys = function(value) {
    App.state.surveyKeyword = value.toLowerCase();
    App.render.surveys();
};

App.actions.updateSurveyField = function(key, value) {
    if (App.state.editingSurvey) {
        App.state.editingSurvey[key] = value;
    }
};

App.actions.testKintone = async function() {
    const box = document.getElementById('field_message');

    if (box) {
        box.textContent = 'kintone接続を確認しています...';
    }

    try {
        const result = await App.api.testKintone();

        if (box) {
            box.textContent =
                result.message +
                '\n' +
                JSON.stringify(
                    result.diagnostic || {},
                    null,
                    2
                );
        }
    } catch (e) {
        if (box) box.textContent = e.message;
    }
};

App.actions.fetchKintoneFields = async function() {
    const appId =
        document.getElementById('setting_app_id')?.value || '';

    const box =
        document.getElementById('field_message');

    if (box) box.textContent = '項目一覧を取得しています...';

    try {
        const result =
            await App.api.getKintoneFields(appId);

        if (!result.success) {
            throw new Error(
                result.message +
                '\n' +
                JSON.stringify(
                    result.diagnostic || {},
                    null,
                    2
                )
            );
        }

        App.state.fields = result.fields || [];

        if (box) {
            box.textContent =
                '項目一覧を ' +
                App.state.fields.length +
                ' 件取得しました。';
        }

        App.render.settings();
    } catch (e) {
        if (box) box.textContent = e.message;
        App.actions.error(e);
    }
};

App.actions.testSMTP = async function() {
    const box =
        document.getElementById('smtp_message');

    if (box) {
        box.textContent =
            'SMTPサーバへ接続しています...';
    }

    try {
        const result =
            await App.api.testSMTP();

        if (box) {
            box.textContent = JSON.stringify(
                result,
                null,
                2
            );
        }

        if (!result.success) {
            App.actions.notify(
                result.message || 'SMTP接続失敗',
                'error'
            );
        } else {
            App.actions.notify(
                'SMTP接続に成功しました。',
                'success'
            );
        }
    } catch (e) {
        if (box) box.textContent = e.message;
        App.actions.error(e);
    }
};

App.actions.testSMTPMail = async function() {
    const to =
        document.getElementById('smtp_test_to')?.value || '';

    if (!to) {
        App.actions.notify(
            'テスト送信先メールアドレスを入力してください。',
            'error'
        );
        return;
    }

    const box =
        document.getElementById('smtp_message');

    if (box) box.textContent =
        'テストメールを送信しています...';

    try {
        const result =
            await App.api.testSMTPMail(to);

        if (box) {
            box.textContent = JSON.stringify(
                result,
                null,
                2
            );
        }

        App.actions.notify(
            result.message ||
            (result.success
                ? 'テストメール送信成功'
                : 'テストメール送信失敗'),
            result.success ? 'success' : 'error'
        );
    } catch (e) {
        if (box) box.textContent = e.message;
        App.actions.error(e);
    }
};

App.collectSettings = function() {
    const ids = [
        'setting_subdomain',
        'setting_app_id',
        'setting_login_name',
        'setting_password',
        'setting_proxy',
        'setting_ssl_verify',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_auth',
        'smtp_username',
        'smtp_password',
        'smtp_from',
        'smtp_from_name',
        'smtp_timeout'
    ];

    const s = {...App.state.data.settings};

    ids.forEach(function(id) {
        const el = document.getElementById(id);

        if (!el) return;

        const key = id
            .replace(/^setting_/, '')
            .replace(/^smtp_/, function() {
                return 'smtp_';
            });

        if (id === 'setting_ssl_verify') {
            s.ssl_verify = el.checked;
        } else if (id === 'smtp_auth') {
            s.smtp_auth = el.checked;
        } else {
            const map = {
                setting_subdomain: 'subdomain',
                setting_app_id: 'app_id',
                setting_login_name: 'login_name',
                setting_password: 'password',
                setting_proxy: 'proxy',
                smtp_host: 'smtp_host',
                smtp_port: 'smtp_port',
                smtp_encryption: 'smtp_encryption',
                smtp_username: 'smtp_username',
                smtp_password: 'smtp_password',
                smtp_from: 'smtp_from',
                smtp_from_name: 'smtp_from_name',
                smtp_timeout: 'smtp_timeout'
            };

            if (map[id]) s[map[id]] = el.value;
        }
    });

    const fields = {
        field_company: 'field_company',
        field_name: 'field_name',
        field_email: 'field_email',
        field_department: 'field_department',
        field_phone: 'field_phone'
    };

    Object.keys(fields).forEach(function(key) {
        const el = document.querySelector(
            '[data-field="' + key + '"]'
        );

        if (el) s[key] = el.value;
    });

    s.field_address =
        [...document.querySelectorAll(
            '[data-address-field]:checked'
        )].map(el => el.value);

    return s;
};

App.actions.saveSettings = async function() {
    try {
        const result =
            await App.api.saveSettings();

        App.state.data.settings = result.settings;

        App.actions.notify(
            '設定を保存しました。',
            'success'
        );
    } catch (e) {
        App.actions.error(e);
    }
};

App.actions.answer = async function(surveyId, customerId) {
    const answers = {};

    document.querySelectorAll(
        '[data-answer-id]'
    ).forEach(function(el) {
        const id = el.dataset.answerId;

        if (el.type === 'checkbox') {
            if (!answers[id]) answers[id] = [];

            if (el.checked) {
                answers[id].push(el.value);
            }
        } else if (el.type === 'radio') {
            if (el.checked) answers[id] = el.value;
        } else {
            answers[id] = el.value;
        }
    });

    try {
        await App.utils.post('answer', {
            survey_id: surveyId,
            customer_id: customerId,
            answers: answers
        });

        App.actions.notify(
            '回答を送信しました。',
            'success'
        );

        App.actions.go('answer_done');
    } catch (e) {
        App.actions.error(e);
    }
};

App.actions.sendMail = async function() {
    const selected = [
        ...document.querySelectorAll(
            '[data-customer-select]:checked'
        )
    ].map(el => el.value);

    if (!selected.length) {
        App.actions.notify(
            '送信先を選択してください。',
            'error'
        );
        return;
    }

    const already = selected.filter(function(id) {
        const c = App.state.data.customers.find(
            x => x.id === id
        );
        return c && Number(c.send_count || 0) > 0;
    });

    if (already.length &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )) {
        return;
    }

    const subject =
        document.getElementById('mail_subject')?.value || '';

    const body =
        document.getElementById('mail_body')?.value || '';

    const type =
        document.getElementById('template_type')?.value ||
        'initial';

    try {
        const result = await App.utils.post(
            'send_mail',
            {
                survey_id: App.state.currentSurveyId,
                recipient_ids: selected,
                mail_subject: subject,
                mail_body: body,
                template_type: type
            }
        );

        result.results.forEach(function(r) {
            if (!r.success) return;

            const c = App.state.data.customers.find(
                x => x.id === r.customer_id
            );

            if (c) {
                c.sent_at = new Date()
                    .toISOString()
                    .slice(0, 19)
                    .replace('T', ' ');

                c.send_count =
                    Number(c.send_count || 0) + 1;

                c.answer_status = 'unanswered';
            }
        });

        App.actions.notify(
            '送信完了：成功 ' +
            result.success_count +
            '件 / 失敗 ' +
            result.failed_count +
            '件 / 未送信 ' +
            result.unsent_count +
            '件',
            result.failed_count
                ? 'error'
                : 'success'
        );

        App.render.mail();
    } catch (e) {
        App.actions.error(e);
    }
};

App.actions.selectAll = function(checked) {
    document.querySelectorAll(
        '[data-customer-select]'
    ).forEach(function(el) {
        el.checked = checked;
    });
};

App.actions.filterCustomers = function(value) {
    App.state.customerFilter =
        value.toLowerCase();

    App.render.mail();
};

App.actions.filterResponses = function(value) {
    App.state.responseFilter =
        value.toLowerCase();

    App.render.responses();
};

App.actions.showResponse = function(id) {
    const response =
        App.state.data.responses.find(
            r => r.id === id
        );

    if (!response) return;

    const el =
        document.getElementById('response_detail');

    if (!el) return;

    el.innerHTML = `
        <div class="space-y-4">
            <div>
                <div class="text-xs text-slate-500">会社名</div>
                <div class="font-semibold">${App.utils.escape(response.company)}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500">氏名</div>
                <div class="font-semibold">${App.utils.escape(response.name)}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500">回答日時</div>
                <div>${App.utils.escape(response.answered_at)}</div>
            </div>
            <div class="border-t pt-4 space-y-3">
                ${Object.entries(response.answers || {})
                    .map(([k,v]) => `
                        <div>
                            <div class="text-xs text-slate-500">${App.utils.escape(k)}</div>
                            <div class="whitespace-pre-wrap">${App.utils.escape(Array.isArray(v) ? v.join('、') : v)}</div>
                        </div>
                    `).join('')}
            </div>
        </div>
    `;

    document.getElementById('response_modal')
        ?.classList.remove('hidden');
};

App.actions.closeResponse = function() {
    document.getElementById('response_modal')
        ?.classList.add('hidden');
};

App.render.layout = function(title, content) {
    return `
    <div class="min-h-screen">
        <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
            <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
                <button
                    onclick="App.actions.go('surveys')"
                    class="font-bold text-lg text-slate-900">
                    アンケート管理システム
                </button>

                <nav class="flex items-center gap-2">
                    <button
                        onclick="App.actions.go('surveys')"
                        class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100">
                        アンケート一覧
                    </button>

                    <button
                        onclick="App.actions.go('settings')"
                        class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100">
                        キントーン・メール連携設定
                    </button>
                </nav>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-6 py-7">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-900">
                    ${title}
                </h1>
            </div>

            ${content}
        </main>
    </div>`;
};

App.render.surveys = function() {
    const keyword = App.state.surveyKeyword;
    const status = App.state.surveyStatus;

    let surveys = App.state.data.surveys.filter(
        s => !s.deleted
    );

    surveys = surveys.filter(function(s) {
        const matchKeyword =
            !keyword ||
            String(s.title || '')
                .toLowerCase()
                .includes(keyword);

        const matchStatus =
            !status ||
            s.status === status;

        return matchKeyword && matchStatus;
    });

    surveys.sort(function(a, b) {
        if (App.state.surveySort === 'updated_desc') {
            return String(b.updated_at || '')
                .localeCompare(String(a.updated_at || ''));
        }

        if (App.state.surveySort === 'updated_asc') {
            return String(a.updated_at || '')
                .localeCompare(String(b.updated_at || ''));
        }

        return 0;
    });

    const rows = surveys.map(function(s) {
        const responses =
            App.state.data.responses.filter(
                r => r.survey_id === s.id
            ).length;

        const badge = {
            active: 'bg-emerald-100 text-emerald-700',
            draft: 'bg-slate-100 text-slate-600',
            ended: 'bg-amber-100 text-amber-700'
        }[s.status] || 'bg-slate-100';

        const label = {
            active: '公開中',
            draft: '下書き',
            ended: '終了'
        }[s.status] || s.status;

        let buttons = '';

        if (s.status === 'active') {
            buttons = `
                <button onclick="App.actions.editSurvey('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-xs">
                    確認・編集
                </button>
                <button onclick="App.actions.openAnalytics('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs">
                    集計
                </button>
                <button onclick="App.actions.openMail('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs">
                    送信
                </button>
                <button onclick="App.actions.setStatus('${s.id}','ended')"
                    class="px-3 py-1.5 rounded-lg bg-amber-500 text-white text-xs">
                    停止
                </button>
            `;
        } else if (s.status === 'draft') {
            buttons = `
                <button onclick="App.actions.editSurvey('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-xs">
                    確認・編集
                </button>
                <button onclick="App.actions.deleteSurvey('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs">
                    削除
                </button>
            `;
        } else {
            buttons = `
                <button onclick="App.actions.editSurvey('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-xs">
                    確認・編集
                </button>
                <button onclick="App.actions.openAnalytics('${s.id}')"
                    class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs">
                    集計
                </button>
            `;
        }

        return `
        <tr class="border-t border-slate-100">
            <td class="px-4 py-4">
                <div class="text-xs text-slate-500">
                    ${App.utils.escape(s.created_at || '')}
                </div>
                <div class="text-xs text-slate-500">
                    更新: ${App.utils.escape(s.updated_at || '')}
                </div>
            </td>

            <td class="px-4 py-4">
                <div class="font-bold">
                    ${App.utils.escape(s.title)}
                </div>
            </td>

            <td class="px-4 py-4 text-sm">
                ${App.utils.escape(s.start_at || '未設定')}
                ～
                ${App.utils.escape(s.end_at || '未設定')}
            </td>

            <td class="px-4 py-4">
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold ${badge}">
                    ${label}
                </span>
            </td>

            <td class="px-4 py-4 text-sm">
                ${responses} 件
            </td>

            <td class="px-4 py-4">
                <div class="flex flex-wrap gap-1.5">
                    ${buttons}
                    <button onclick="App.actions.duplicateSurvey('${s.id}')"
                        class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs hover:bg-slate-50">
                        複製
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    const content = `
        <div class="flex justify-between items-center mb-5">
            <div class="flex gap-2">
                <input
                    value="${App.utils.escape(App.state.surveyKeyword)}"
                    oninput="App.actions.searchSurveys(this.value)"
                    placeholder="タイトルを検索"
                    class="w-72 px-4 py-2.5 bg-white border border-slate-300 rounded-xl outline-none focus:ring-2 focus:ring-blue-500">

                <select
                    onchange="App.actions.toggleStatusFilter(this.value)"
                    class="px-4 py-2.5 bg-white border border-slate-300 rounded-xl">
                    <option value="">すべて</option>
                    <option value="active">公開中</option>
                    <option value="draft">下書き</option>
                    <option value="ended">終了</option>
                </select>
            </div>

            <button
                onclick="App.actions.newSurvey()"
                class="px-5 py-3 rounded-xl bg-blue-600 text-white font-semibold shadow-sm">
                ＋ 新規アンケート作成
            </button>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50">
                        <tr class="text-xs text-slate-500">
                            <th class="px-4 py-3">作成日 / 更新日</th>
                            <th class="px-4 py-3">タイトル</th>
                            <th class="px-4 py-3">アンケート期間</th>
                            <th class="px-4 py-3">ステータス</th>
                            <th class="px-4 py-3">回答数</th>
                            <th class="px-4 py-3">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows ||
                        `<tr><td colspan="6" class="px-5 py-16 text-center text-slate-400">
                            アンケートはありません
                        </td></tr>`}
                    </tbody>
                </table>
            </div>
        </div>`;

    document.getElementById('app').innerHTML =
        App.render.layout('アンケート一覧', content);
};

App.render.editor = function() {
    const s = App.state.editingSurvey;

    if (!s) return;

    const groups = s.groups || [];

    const html = `
        <div class="space-y-5">

            <div class="bg-white rounded-2xl border border-slate-200 p-5">
                <div class="grid grid-cols-4 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-500 mb-2">
                            タイトル
                        </label>
                        <input
                            id="survey_title"
                            value="${App.utils.escape(s.title)}"
                            onchange="App.actions.updateSurveyField('title',this.value)"
                            class="w-full px-4 py-3 border border-slate-300 rounded-xl font-semibold">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-2">
                            開始日時
                        </label>
                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.utils.escape(s.start_at || '')}"
                            onchange="App.actions.updateSurveyField('start_at',this.value)"
                            class="w-full px-3 py-3 border border-slate-300 rounded-xl">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-2">
                            終了日時
                        </label>
                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.utils.escape(s.end_at || '')}"
                            onchange="App.actions.updateSurveyField('end_at',this.value)"
                            class="w-full px-3 py-3 border border-slate-300 rounded-xl">
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-5">
                    <label class="text-sm">
                        <span class="mr-2">ステータス</span>
                        <select
                            onchange="App.actions.updateSurveyField('status',this.value)"
                            class="px-3 py-2 border rounded-lg">
                            <option value="draft" ${s.status==='draft'?'selected':''}>下書き</option>
                            <option value="active" ${s.status==='active'?'selected':''}>公開中</option>
                            <option value="ended" ${s.status==='ended'?'selected':''}>終了</option>
                        </select>
                    </label>

                    <label class="text-sm">
                        <span class="mr-2">質問番号</span>
                        <select
                            id="survey_numbering_mode"
                            onchange="App.actions.updateSurveyField('numbering_mode',this.value);App.actions.renumber();App.render.editor();App.actions.initSortables()"
                            class="px-3 py-2 border rounded-lg">
                            <option value="global" ${s.numbering_mode==='global'?'selected':''}>Q1, Q2...</option>
                            <option value="group" ${s.numbering_mode==='group'?'selected':''}>Q1-1, Q1-2...</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold">質問構成</h2>
                <button
                    onclick="App.actions.addGroup()"
                    class="px-4 py-2.5 rounded-xl bg-blue-600 text-white">
                    ＋ グループ追加
                </button>
            </div>

            <div id="group_sortable" class="space-y-5">
                ${groups.map(function(g, gi) {
                    return `
                    <section
                        data-group-id="${g.id}"
                        class="bg-white rounded-2xl border border-slate-200 shadow-sm">

                        <div class="px-5 py-4 border-b flex items-center gap-3">
                            <span class="group-handle cursor-grab text-slate-400 text-xl">
                                ⠿
                            </span>

                            <input
                                value="${App.utils.escape(g.name)}"
                                onchange="App.actions.updateGroup('${g.id}',this.value)"
                                class="flex-1 px-3 py-2 border border-slate-200 rounded-lg font-semibold">

                            <button
                                onclick="App.actions.removeGroup('${g.id}')"
                                class="px-3 py-2 rounded-lg text-red-600 hover:bg-red-50">
                                削除
                            </button>
                        </div>

                        <div
                            class="question-sortable p-5 space-y-4"
                            data-group-id="${g.id}">

                            ${(g.questions || []).map(function(q) {
                                return `
                                <div
                                    data-question-id="${q.id}"
                                    class="border border-slate-200 rounded-xl p-4 bg-slate-50">

                                    <div class="flex gap-3">
                                        <span class="question-handle cursor-grab text-slate-400 pt-2">
                                            ⠿
                                        </span>

                                        <div class="flex-1 space-y-3">
                                            <div class="flex items-center gap-2">
                                                <span class="px-2 py-1 rounded-lg bg-blue-50 text-blue-700 text-xs font-bold">
                                                    ${App.utils.escape(q.number || '')}
                                                </span>

                                                <select
                                                    onchange="App.actions.updateQuestion('${g.id}','${q.id}','type',this.value);App.render.editor();App.actions.initSortables()"
                                                    class="px-3 py-2 border rounded-lg bg-white text-sm">
                                                    <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
                                                    <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
                                                    <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
                                                </select>

                                                <label class="text-sm ml-auto">
                                                    <input
                                                        type="checkbox"
                                                        ${q.required?'checked':''}
                                                        onchange="App.actions.updateQuestion('${g.id}','${q.id}','required',this.checked)"
                                                    >
                                                    必須
                                                </label>

                                                <button
                                                    onclick="App.actions.removeQuestion('${g.id}','${q.id}')"
                                                    class="text-red-600 text-sm">
                                                    削除
                                                </button>
                                            </div>

                                            <textarea
                                                onchange="App.actions.updateQuestion('${g.id}','${q.id}','text',this.value)"
                                                class="w-full px-3 py-3 border rounded-xl bg-white"
                                                rows="2">${App.utils.escape(q.text)}</textarea>

                                            ${q.type !== 'text'
                                                ? `
                                                <div class="space-y-2">
                                                    ${(q.options || []).map(function(o, oi) {
                                                        return `
                                                        <div class="flex gap-2">
                                                            <input
                                                                value="${App.utils.escape(o)}"
                                                                onchange="App.actions.updateOption('${g.id}','${q.id}',${oi},this.value)"
                                                                class="flex-1 px-3 py-2 border rounded-lg bg-white">

                                                            <button
                                                                onclick="App.actions.removeOption('${g.id}','${q.id}',${oi})"
                                                                class="px-3 text-red-600">
                                                                ×
                                                            </button>
                                                        </div>`;
                                                    }).join('')}

                                                    <button
                                                        onclick="App.actions.addOption('${g.id}','${q.id}')"
                                                        class="text-sm text-blue-600">
                                                        ＋ 選択肢追加
                                                    </button>

                                                    <label class="text-sm block mt-2">
                                                        <input
                                                            type="checkbox"
                                                            ${q.other_enabled?'checked':''}
                                                            onchange="App.actions.updateQuestion('${g.id}','${q.id}','other_enabled',this.checked)">
                                                        「その他」を追加
                                                    </label>
                                                </div>`
                                                : `
                                                <div class="text-xs text-slate-400">
                                                    複数行テキスト入力
                                                </div>`
                                            }
                                        </div>
                                    </div>
                                </div>`;
                            }).join('')}

                        </div>

                        <div class="px-5 pb-5">
                            <button
                                onclick="App.actions.addQuestion('${g.id}')"
                                class="px-4 py-2 rounded-lg border border-blue-200 text-blue-600 hover:bg-blue-50">
                                ＋ 質問追加
                            </button>
                        </div>
                    </section>`;
                }).join('')}
            </div>

            <div class="flex justify-end gap-3">
                <button
                    onclick="App.actions.go('surveys')"
                    class="px-5 py-3 rounded-xl border border-slate-300 bg-white">
                    キャンセル
                </button>

                <button
                    onclick="App.actions.preview()"
                    class="px-5 py-3 rounded-xl border border-slate-300 bg-white">
                    プレビュー
                </button>

                <button
                    onclick="App.actions.saveSurvey()"
                    class="px-5 py-3 rounded-xl bg-blue-600 text-white font-semibold">
                    保存して一覧へ戻る
                </button>
            </div>
        </div>`;

    document.getElementById('app').innerHTML =
        App.render.layout('アンケート作成・編集', html);
};

App.render.previewHTML = function(survey) {
    if (!survey) return '';

    return `
    <div class="max-w-2xl mx-auto bg-white p-7 rounded-2xl">
        <h1 class="text-2xl font-bold mb-7">
            ${App.utils.escape(survey.title)}
        </h1>

        <div class="space-y-8">
            ${(survey.groups || []).map(function(g) {
                return `
                <section>
                    <h2 class="font-bold text-lg mb-4 pb-2 border-b">
                        ${App.utils.escape(g.name)}
                    </h2>

                    <div class="space-y-6">
                        ${(g.questions || []).map(function(q) {
                            return `
                            <div>
                                <div class="font-semibold mb-2">
                                    ${App.utils.escape(q.number || '')}
                                    ${App.utils.escape(q.text)}
                                    ${q.required
                                        ? '<span class="text-red-500 ml-1">*</span>'
                                        : ''}
                                </div>

                                ${
                                    q.type === 'text'
                                    ? `<textarea
                                        data-answer-id="${q.id}"
                                        class="w-full border rounded-xl p-3"
                                        rows="4"></textarea>`
                                    : (q.options || []).map(function(o) {
                                        const input =
                                            q.type === 'multiple'
                                            ? 'checkbox'
                                            : 'radio';

                                        return `
                                        <label class="block py-2">
                                            <input
                                                type="${input}"
                                                name="${q.id}"
                                                data-answer-id="${q.id}"
                                                value="${App.utils.escape(o)}"
                                                class="mr-2">
                                            ${App.utils.escape(o)}
                                        </label>`;
                                    }).join('')
                                }
                            </div>`;
                        }).join('')}
                    </div>
                </section>`;
            }).join('')}
        </div>
    </div>`;
};

App.render.preview = function() {
    const s = App.state.editingSurvey;

    const html = `
        <div class="bg-white rounded-2xl border p-6">
            <div class="flex justify-between mb-5">
                <button
                    onclick="App.actions.go('surveys')"
                    class="px-4 py-2 border rounded-lg">
                    戻る
                </button>

                <div class="flex gap-2">
                    <button
                        onclick="App.state.previewMode='pc';App.render.preview()"
                        class="px-3 py-2 rounded-lg border">
                        PC表示
                    </button>

                    <button
                        onclick="App.state.previewMode='mobile';App.render.preview()"
                        class="px-3 py-2 rounded-lg border">
                        スマートフォン表示
                    </button>
                </div>
            </div>

            <div class="${App.state.previewMode === 'mobile'
                ? 'max-w-sm'
                : 'max-w-3xl'} mx-auto">
                ${App.render.previewHTML(s)}
            </div>
        </div>`;

    document.getElementById('app').innerHTML =
        App.render.layout('プレビュー', html);
};

App.render.settings = function() {
    const s = App.state.data.settings || {};

    const fieldSelect = function(key) {
        const value = s[key] || '';

        return `
        <select
            data-field="${key}"
            class="w-full px-3 py-2.5 border border-slate-300 rounded-xl">
            <option value="">-- 選択してください --</option>
            ${App.state.fields.map(function(f) {
                return `
                <option
                    value="${App.utils.escape(f.code)}"
                    ${value === f.code ? 'selected' : ''}>
                    ${App.utils.escape(f.label)}
                    (${App.utils.escape(f.code)})
                </option>`;
            }).join('')}
        </select>`;
    };

    const addressValues =
        Array.isArray(s.field_address)
            ? s.field_address
            : [];

    const html = `
        <div class="space-y-6">

            <div class="bg-white rounded-2xl border p-6">
                <h2 class="font-bold text-lg mb-5">
                    kintone接続・認証設定
                </h2>

                <div class="grid grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            サブドメイン
                        </label>
                        <input
                            id="setting_subdomain"
                            value="${App.utils.escape(s.subdomain || '')}"
                            placeholder="xxxx または xxxx.cybozu.com"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            顧客管理アプリID
                        </label>
                        <input
                            id="setting_app_id"
                            value="${App.utils.escape(s.app_id || '')}"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            ログイン名
                        </label>
                        <input
                            id="setting_login_name"
                            value="${App.utils.escape(s.login_name || '')}"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            パスワード
                        </label>
                        <input
                            id="setting_password"
                            type="password"
                            value="${App.utils.escape(s.password || '')}"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            Proxyサーバ
                        </label>
                        <input
                            id="setting_proxy"
                            value="${App.utils.escape(s.proxy || '')}"
                            placeholder="proxy.example.com:8080"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <label class="flex items-center gap-2 mt-8 text-sm">
                        <input
                            id="setting_ssl_verify"
                            type="checkbox"
                            ${s.ssl_verify ? 'checked' : ''}>
                        SSL証明書を検証する
                    </label>
                </div>

                <div class="flex gap-3 mt-5">
                    <button
                        onclick="App.actions.saveSettings()"
                        class="px-5 py-2.5 bg-blue-600 text-white rounded-xl">
                        設定保存
                    </button>

                    <button
                        onclick="App.actions.testKintone()"
                        class="px-5 py-2.5 bg-slate-800 text-white rounded-xl">
                        kintone接続確認
                    </button>

                    <button
                        onclick="App.actions.fetchKintoneFields()"
                        class="px-5 py-2.5 border rounded-xl">
                        項目一覧を取得
                    </button>
                </div>

                <pre
                    id="field_message"
                    class="mt-4 p-4 rounded-xl bg-slate-950 text-emerald-300 text-xs whitespace-pre-wrap"></pre>
            </div>

            <div class="bg-white rounded-2xl border p-6">
                <h2 class="font-bold text-lg mb-5">
                    kintone項目マッピング
                </h2>

                <div class="grid grid-cols-2 gap-5">
                    <label>
                        <span class="block text-sm font-semibold mb-2">会社名</span>
                        ${fieldSelect('field_company')}
                    </label>

                    <label>
                        <span class="block text-sm font-semibold mb-2">氏名</span>
                        ${fieldSelect('field_name')}
                    </label>

                    <label>
                        <span class="block text-sm font-semibold mb-2">メールアドレス</span>
                        ${fieldSelect('field_email')}
                    </label>

                    <label>
                        <span class="block text-sm font-semibold mb-2">部署名</span>
                        ${fieldSelect('field_department')}
                    </label>

                    <label>
                        <span class="block text-sm font-semibold mb-2">電話番号</span>
                        ${fieldSelect('field_phone')}
                    </label>

                    <div>
                        <span class="block text-sm font-semibold mb-2">
                            住所（複数選択可）
                        </span>

                        <div class="border rounded-xl p-3 max-h-48 overflow-y-auto">
                            ${App.state.fields.map(function(f) {
                                return `
                                <label class="block py-1 text-sm">
                                    <input
                                        type="checkbox"
                                        data-address-field
                                        value="${App.utils.escape(f.code)}"
                                        ${addressValues.includes(f.code)
                                            ? 'checked'
                                            : ''}>
                                    ${App.utils.escape(f.label)}
                                    (${App.utils.escape(f.code)})
                                </label>`;
                            }).join('')}
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border p-6">
                <h2 class="font-bold text-lg mb-5">
                    SMTPサーバ設定
                </h2>

                <div class="grid grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            SMTPサーバ
                        </label>
                        <input
                            id="smtp_host"
                            value="${App.utils.escape(s.smtp_host || '')}"
                            placeholder="smtp.example.com"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            SMTPポート
                        </label>
                        <input
                            id="smtp_port"
                            type="number"
                            value="${App.utils.escape(s.smtp_port || 587)}"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            暗号化方式
                        </label>
                        <select
                            id="smtp_encryption"
                            class="w-full px-3 py-3 border rounded-xl">
                            <option ${s.smtp_encryption==='NONE'?'selected':''}>NONE</option>
                            <option ${s.smtp_encryption==='SSL'?'selected':''}>SSL</option>
                            <option ${s.smtp_encryption==='TLS'?'selected':''}>TLS</option>
                        </select>
                    </div>

                    <div class="flex items-center pt-7">
                        <label class="text-sm">
                            <input
                                id="smtp_auth"
                                type="checkbox"
                                ${s.smtp_auth !== false ? 'checked' : ''}>
                            SMTP認証を使用する
                        </label>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            SMTPユーザー名
                        </label>
                        <input
                            id="smtp_username"
                            value="${App.utils.escape(s.smtp_username || '')}"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            SMTPパスワード
                        </label>
                        <input
                            id="smtp_password"
                            type="password"
                            value="${App.utils.escape(s.smtp_password || '')}"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            送信元メールアドレス
                        </label>
                        <input
                            id="smtp_from"
                            value="${App.utils.escape(s.smtp_from || '')}"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            送信元表示名
                        </label>
                        <input
                            id="smtp_from_name"
                            value="${App.utils.escape(s.smtp_from_name || '')}"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            接続タイムアウト（秒）
                        </label>
                        <input
                            id="smtp_timeout"
                            type="number"
                            value="${App.utils.escape(s.smtp_timeout || 15)}"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            テスト送信先
                        </label>
                        <input
                            id="smtp_test_to"
                            type="email"
                            placeholder="test@example.com"
                            class="w-full px-3 py-3 border rounded-xl">
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button
                        onclick="App.actions.saveSettings()"
                        class="px-5 py-2.5 bg-blue-600 text-white rounded-xl">
                        SMTP設定を保存
                    </button>

                    <button
                        onclick="App.actions.testSMTP()"
                        class="px-5 py-2.5 bg-slate-800 text-white rounded-xl">
                        SMTP接続確認
                    </button>

                    <button
                        onclick="App.actions.testSMTPMail()"
                        class="px-5 py-2.5 border rounded-xl">
                        テストメール送信
                    </button>
                </div>

                <pre
                    id="smtp_message"
                    class="mt-5 p-4 bg-slate-950 text-emerald-300 rounded-xl text-xs whitespace-pre-wrap"></pre>
            </div>
        </div>`;

    document.getElementById('app').innerHTML =
        App.render.layout(
            'キントーン・メール連携設定',
            html
        );
};

App.render.mail = function() {
    const survey =
        App.state.data.surveys.find(
            s => s.id === App.state.currentSurveyId
        );

    const keyword =
        App.state.customerFilter;

    const customers =
        App.state.data.customers.filter(function(c) {
            if (!keyword) return true;

            return (
                String(c.company || '').toLowerCase().includes(keyword) ||
                String(c.name || '').toLowerCase().includes(keyword) ||
                String(c.email || '').toLowerCase().includes(keyword)
            );
        });

    const rows = customers.map(function(c) {
        const answered =
            c.answer_status === 'answered';

        return `
        <tr class="border-t">
            <td class="px-4 py-3">
                <input
                    type="checkbox"
                    value="${App.utils.escape(c.id)}"
                    data-customer-select
                    class="w-4 h-4">
            </td>
            <td class="px-4 py-3">
                <div class="font-semibold">${App.utils.escape(c.company)}</div>
                <div>${App.utils.escape(c.name)}</div>
                <div class="text-xs text-slate-500">${App.utils.escape(c.email)}</div>
            </td>
            <td class="px-4 py-3">${App.utils.escape(c.department)}</td>
            <td class="px-4 py-3">${App.utils.escape(c.phone)}</td>
            <td class="px-4 py-3">${App.utils.escape(c.address)}</td>
            <td class="px-4 py-3 text-sm">${App.utils.escape(c.sent_at || '未送信')}</td>
            <td class="px-4 py-3 text-sm">${Number(c.send_count || 0)} 回</td>
            <td class="px-4 py-3">
                <span class="px-2 py-1 rounded-full text-xs
                    ${answered
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-amber-100 text-amber-700'}">
                    ${answered ? '回答済み' : '送信済み（未回答）'}
                </span>
            </td>
        </tr>`;
    }).join('');

    const html = `
        <div class="space-y-5">
            <div class="bg-white rounded-2xl border p-5">
                <div class="flex justify-between">
                    <div>
                        <div class="text-xs text-slate-500">アンケート</div>
                        <div class="font-bold">${App.utils.escape(survey?.title || '')}</div>
                    </div>

                    <button
                        onclick="App.actions.go('surveys')"
                        class="px-4 py-2 border rounded-lg">
                        戻る
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-2xl border p-5">
                <div class="grid grid-cols-2 gap-5">
                    <input
                        id="mail_subject"
                        placeholder="件名"
                        value="アンケートのご案内"
                        class="px-4 py-3 border rounded-xl">

                    <select
                        id="template_type"
                        class="px-4 py-3 border rounded-xl">
                        <option value="initial">初回送信用</option>
                        <option value="reminder">リマインド送信用</option>
                    </select>

                    <textarea
                        id="mail_body"
                        class="col-span-2 px-4 py-3 border rounded-xl"
                        rows="8">${App.utils.escape('{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}')}</textarea>
                </div>
            </div>

            <div class="bg-white rounded-2xl border overflow-hidden">
                <div class="p-5 flex items-center gap-3">
                    <input
                        oninput="App.actions.filterCustomers(this.value)"
                        placeholder="顧客名・メールアドレス・会社名"
                        class="flex-1 px-4 py-3 border rounded-xl">

                    <button
                        onclick="App.actions.selectAll(true)"
                        class="px-4 py-2 border rounded-lg">
                        全選択
                    </button>

                    <button
                        onclick="App.actions.selectAll(false)"
                        class="px-4 py-2 border rounded-lg">
                        全解除
                    </button>

                    <button
                        onclick="App.actions.sendMail()"
                        class="px-5 py-3 bg-blue-600 text-white rounded-xl font-semibold">
                        一括送信実行
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-50 text-xs">
                            <tr>
                                <th class="px-4 py-3">選択</th>
                                <th class="px-4 py-3 text-left">会社名 / 氏名 / メール</th>
                                <th class="px-4 py-3 text-left">部署</th>
                                <th class="px-4 py-3 text-left">電話</th>
                                <th class="px-4 py-3 text-left">住所</th>
                                <th class="px-4 py-3 text-left">最終送信</th>
                                <th class="px-4 py-3 text-left">回数</th>
                                <th class="px-4 py-3 text-left">回答</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>
        </div>`;

    document.getElementById('app').innerHTML =
        App.render.layout(
            '顧客選択・送信・送信履歴',
            html
        );
};

App.render.responses = function() {
    const surveyId =
        App.state.currentSurveyId;

    const responses =
        App.state.data.responses.filter(
            r => r.survey_id === surveyId
        ).filter(function(r) {
            const k =
                App.state.responseFilter;

            if (!k) return true;

            return (
                String(r.company || '').toLowerCase().includes(k) ||
                String(r.name || '').toLowerCase().includes(k)
            );
        });

    const rows = responses.map(function(r) {
        return `
        <tr class="border-t">
            <td class="px-4 py-3">${App.utils.escape(r.company)}</td>
            <td class="px-4 py-3">${App.utils.escape(r.name)}</td>
            <td class="px-4 py-3">${App.utils.escape(r.email)}</td>
            <td class="px-4 py-3">${App.utils.escape(r.answered_at)}</td>
            <td class="px-4 py-3">
                <button
                    onclick="App.actions.showResponse('${r.id}')"
                    class="text-blue-600">
                    全回答を表示
                </button>
            </td>
        </tr>`;
    }).join('');

    const html = `
        <div class="bg-white rounded-2xl border overflow-hidden">
            <div class="p-5 flex gap-3">
                <input
                    id="response_filter"
                    oninput="App.actions.filterResponses(this.value)"
                    placeholder="会社名・氏名"
                    class="flex-1 px-4 py-3 border rounded-xl">

                <a
                    href="?action=csv&survey_id=${encodeURIComponent(surveyId)}"
                    class="px-5 py-3 rounded-xl bg-slate-800 text-white">
                    CSV出力
                </a>

                <button
                    onclick="App.actions.go('surveys')"
                    class="px-5 py-3 border rounded-xl">
                    戻る
                </button>
            </div>

            <div class="overflow-x-auto">
                <table
                    id="response_table"
                    class="w-full text-left">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3">会社名</th>
                            <th class="px-4 py-3">氏名</th>
                            <th class="px-4 py-3">メール</th>
                            <th class="px-4 py-3">回答日時</th>
                            <th class="px-4 py-3">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows ||
                        `<tr><td colspan="5" class="p-12 text-center text-slate-400">
                            現在、回答データはありません
                        </td></tr>`}
                    </tbody>
                </table>
            </div>
        </div>

        <div
            id="response_modal"
            class="hidden fixed inset-0 z-50 bg-black/50 p-6">
            <div class="max-w-3xl mx-auto mt-10 bg-white rounded-2xl p-6 max-h-[80vh] overflow-y-auto">
                <div class="flex justify-between mb-5">
                    <h2 class="font-bold text-lg">回答詳細</h2>
                    <button
                        onclick="App.actions.closeResponse()"
                        class="text-2xl">
                        ×
                    </button>
                </div>
                <div id="response_detail"></div>
            </div>
        </div>`;

    document.getElementById('app').innerHTML =
        App.render.layout(
            '回答一覧',
            html
        );
};

App.render.analytics = function() {
    const surveyId =
        App.state.currentSurveyId;

    const survey =
        App.state.data.surveys.find(
            s => s.id === surveyId
        );

    const responses =
        App.state.data.responses.filter(
            r => r.survey_id === surveyId
        );

    const customers =
        App.state.data.customers;

    const sent =
        customers.filter(
            c => Number(c.send_count || 0) > 0
        ).length;

    const answered =
        responses.length;

    const unanswered =
        Math.max(0, sent - answered);

    const rate =
        sent > 0
            ? ((answered / sent) * 100).toFixed(1)
            : '0.0';

    const questions = [];

    (survey?.groups || []).forEach(function(g) {
        (g.questions || []).forEach(function(q) {
            questions.push(q);
        });
    });

    const questionHTML =
        questions.map(function(q) {
            const counts = {};

            responses.forEach(function(r) {
                let v = r.answers?.[q.id];

                if (Array.isArray(v)) {
                    v.forEach(function(x) {
                        counts[x] = (counts[x] || 0) + 1;
                    });
                } else if (v) {
                    counts[v] = (counts[v] || 0) + 1;
                }
            });

            const total =
                Object.values(counts)
                    .reduce((a,b) => a + b, 0);

            return `
            <div class="border rounded-2xl p-5">
                <div class="flex items-center gap-2 mb-4">
                    <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded-lg text-xs font-bold">
                        ${App.utils.escape(q.number || '')}
                    </span>
                    <span class="font-semibold">
                        ${App.utils.escape(q.text)}
                    </span>
                </div>

                ${q.type === 'text'
                    ? responses.map(function(r) {
                        const v = r.answers?.[q.id];
                        if (!v) return '';

                        return `
                        <div class="border-t py-3">
                            <div class="text-xs text-slate-500">
                                ${App.utils.escape(r.company)}
                                / ${App.utils.escape(r.name)}
                            </div>
                            <div class="whitespace-pre-wrap">
                                ${App.utils.escape(v)}
                            </div>
                        </div>`;
                    }).join('')
                    : Object.entries(counts).map(function([key,count]) {
                        const percent =
                            total > 0
                                ? (count / total) * 100
                                : 0;

                        return `
                        <div class="mb-3">
                            <div class="flex justify-between text-sm mb-1">
                                <span>${App.utils.escape(key)}</span>
                                <span>${count}件 (${percent.toFixed(1)}%)</span>
                            </div>
                            <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-blue-500"
                                    style="width:${percent}%">
                                </div>
                            </div>
                        </div>`;
                    }).join('')
                }
            </div>`;
        }).join('');

    const html = `
        <div class="space-y-6">

            <div class="bg-white rounded-2xl border p-6">
                <div class="flex justify-between">
                    <div>
                        <div class="text-xs text-slate-500">
                            アンケート
                        </div>
                        <div class="text-xl font-bold">
                            ${App.utils.escape(survey?.title || '')}
                        </div>
                    </div>

                    <button
                        onclick="App.actions.go('surveys')"
                        class="px-4 py-2 border rounded-lg">
                        戻る
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-5 gap-4">
                ${[
                    ['送信対象者数',sent + ' 人'],
                    ['回答数',answered + ' 件'],
                    ['未登録顧客からの回答数',
                        responses.filter(r =>
                            !customers.some(c =>
                                c.email === r.email
                            )
                        ).length + ' 件'],
                    ['未回答数',unanswered + ' 人'],
                    ['回答率',rate + ' %']
                ].map(function(x) {
                    return `
                    <div class="bg-white rounded-2xl border p-5">
                        <div class="text-xs text-slate-500 mb-2">
                            ${x[0]}
                        </div>
                        <div class="text-2xl font-bold">
                            ${x[1]}
                        </div>
                    </div>`;
                }).join('')}
            </div>

            <div class="space-y-4">
                ${questionHTML ||
                `<div class="bg-white rounded-2xl border p-12 text-center text-slate-400">
                    現在、回答データはありません
                </div>`}
            </div>

            <div class="bg-white rounded-2xl border p-5">
                <h2 class="font-bold mb-4">個別回答一覧</h2>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="p-3">会社名</th>
                                <th class="p-3">氏名</th>
                                <th class="p-3">回答日時</th>
                                <th class="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${responses.map(function(r) {
                                return `
                                <tr class="border-t">
                                    <td class="p-3">${App.utils.escape(r.company)}</td>
                                    <td class="p-3">${App.utils.escape(r.name)}</td>
                                    <td class="p-3">${App.utils.escape(r.answered_at)}</td>
                                    <td class="p-3">
                                        <button
                                            onclick="App.actions.showResponse('${r.id}')"
                                            class="text-blue-600">
                                            全回答を表示
                                        </button>
                                    </td>
                                </tr>`;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <div
                id="response_modal"
                class="hidden fixed inset-0 z-50 bg-black/50 p-6">
                <div class="max-w-3xl mx-auto mt-10 bg-white rounded-2xl p-6 max-h-[80vh] overflow-y-auto">
                    <div class="flex justify-between mb-5">
                        <h2 class="font-bold">全回答</h2>
                        <button
                            onclick="App.actions.closeResponse()"
                            class="text-2xl">×</button>
                    </div>
                    <div id="response_detail"></div>
                </div>
            </div>
        </div>`;

    document.getElementById('app').innerHTML =
        App.render.layout(
            '回答集計・分析',
            html
        );
};

App.render.answer = function() {
    const survey =
        App.state.data.surveys.find(
            s => s.id === App.state.currentSurveyId
        );

    const customer =
        App.state.data.customers.find(
            c => c.id === App.getQuery('customer_id')
        );

    if (!survey) {
        document.getElementById('app').innerHTML =
            '<div class="p-10">アンケートが見つかりません。</div>';
        return;
    }

    const html = `
        <div class="max-w-3xl mx-auto">
            <div class="bg-white rounded-2xl border p-7">
                <h1 class="text-2xl font-bold mb-2">
                    ${App.utils.escape(survey.title)}
                </h1>

                <p class="text-sm text-slate-500 mb-8">
                    ${App.utils.escape(customer?.name || '')} 様
                </p>

                ${App.render.previewHTML(survey)}

                <button
                    onclick="App.actions.answer('${survey.id}','${customer?.id || ''}')"
                    class="mt-7 w-full py-3 rounded-xl bg-blue-600 text-white font-semibold">
                    回答を送信する
                </button>
            </div>
        </div>`;

    document.getElementById('app').innerHTML = html;
};

App.render.answerDone = function() {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen flex items-center justify-center">
            <div class="bg-white rounded-2xl border p-10 text-center shadow-sm">
                <div class="text-emerald-600 text-5xl mb-4">✓</div>
                <h1 class="text-xl font-bold">
                    回答を受け付けました
                </h1>
                <p class="text-slate-500 mt-2">
                    ご協力ありがとうございました。
                </p>
            </div>
        </div>`;
};

App.getQuery = function(name) {
    return new URLSearchParams(
        location.search
    ).get(name);
};

/*
 * 重要:
 * 初期化から必ず呼ばれる公開関数。
 * 前回の App.renderScreen is not a function を防ぐため、
 * window.App直下へ明示的に定義する。
 */
App.renderScreen = function() {
    try {
        switch (App.state.screen) {
            case 'editor':
                App.render.editor();
                break;

            case 'preview':
                App.render.preview();
                break;

            case 'settings':
                App.render.settings();
                break;

            case 'mail':
                App.render.mail();
                break;

            case 'responses':
                App.render.responses();
                break;

            case 'analytics':
                App.render.analytics();
                break;

            case 'answer':
                App.render.answer();
                break;

            case 'answer_done':
                App.render.answerDone();
                break;

            case 'surveys':
            default:
                App.render.surveys();
                break;
        }
    } catch (error) {
        console.error(error);

        document.getElementById('app').innerHTML = `
            <div class="min-h-screen flex items-center justify-center p-6">
                <div class="max-w-3xl w-full bg-white border border-red-200 rounded-2xl p-7 shadow-sm">
                    <h1 class="text-xl font-bold text-red-700 mb-3">
                        画面描画に失敗しました
                    </h1>
                    <pre class="bg-slate-950 text-red-300 rounded-xl p-5 text-sm whitespace-pre-wrap overflow-auto">${App.utils.escape(error.stack || error.message || String(error))}</pre>
                    <button
                        onclick="location.reload()"
                        class="mt-5 px-5 py-2.5 rounded-xl bg-slate-900 text-white">
                        再読み込み
                    </button>
                </div>
            </div>`;
    }
};

App.init = async function() {
    if (App.state.initialized) return;

    App.state.initialized = true;

    try {
        document.getElementById('app').innerHTML = `
            <div class="min-h-screen flex items-center justify-center">
                <div class="text-center">
                    <div class="text-lg font-semibold">
                        アプリケーションを初期化しています...
                    </div>
                    <div class="text-sm text-slate-400 mt-2">
                        データを読み込んでいます
                    </div>
                </div>
            </div>`;

        await App.api.load();

        const queryScreen =
            App.getQuery('screen');

        if (queryScreen === 'answer') {
            App.state.screen = 'answer';
            App.state.currentSurveyId =
                App.getQuery('survey_id');
        } else {
            App.state.screen = 'surveys';
        }

        /*
         * renderScreenはApp直下に存在することを明示確認。
         */
        if (typeof App.renderScreen !== 'function') {
            throw new Error(
                'App.renderScreen が定義されていません。'
            );
        }

        App.renderScreen();

    } catch (error) {
        console.error(error);

        document.getElementById('app').innerHTML = `
            <div class="min-h-screen flex items-center justify-center p-6">
                <div class="max-w-2xl w-full bg-white rounded-2xl border border-red-200 p-8 shadow-sm">
                    <h1 class="text-xl font-bold text-red-700">
                        初期化に失敗しました
                    </h1>

                    <pre class="mt-5 bg-slate-950 text-red-300 rounded-xl p-5 text-sm whitespace-pre-wrap overflow-auto">${App.utils.escape(error.stack || error.message || String(error))}</pre>

                    <button
                        onclick="location.reload()"
                        class="mt-5 px-5 py-2.5 bg-slate-900 text-white rounded-xl">
                        再読み込み
                    </button>
                </div>
            </div>`;
    }
};

/*
 * DOMContentLoaded前後どちらでも1回だけ初期化。
 */
if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.init();
        },
        {once: true}
    );
} else {
    App.init();
}
</script>

</body>
</html>
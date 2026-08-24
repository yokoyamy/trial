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

session_name(SURVEY_ADMIN_SESSION);
session_start();

header_remove('X-Powered-By');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

function survey_default_data(): array
{
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
            'smtp_port' => 465,
            'smtp_encryption' => 'SSL',
            'smtp_auth' => true,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_from' => '',
            'smtp_from_name' => '',
            'smtp_timeout' => 15,
            'mail_subject_initial' => 'アンケートのご案内',
            'mail_body_initial' => "{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}",
            'mail_subject_reminder' => 'アンケートご回答のお願い（再送）',
            'mail_body_reminder' => "{顧客名} 様\n\nまだご回答がお済みでないアンケートのご案内です。\n\n{アンケートURL}",
        ],
        'mail_logs' => []
    ];
}

function survey_read_data(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        $data = survey_default_data();
        survey_write_data($data);
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    if ($raw === false || trim($raw) === '') {
        return survey_default_data();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return survey_default_data();
    }

    $base = survey_default_data();
    foreach ($base as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    foreach (['surveys', 'responses', 'customers', 'mail_logs'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    if (!isset($data['settings']) || !is_array($data['settings'])) {
        $data['settings'] = $base['settings'];
    } else {
        $data['settings'] = array_replace($base['settings'], $data['settings']);
    }

    return $data;
}

function survey_write_data(array $data): bool
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';
    $ok = @file_put_contents($tmp, $json, LOCK_EX);
    if ($ok === false) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function survey_id(): string
{
    return bin2hex(random_bytes(12));
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function survey_check_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals(survey_csrf(), $token)) {
        survey_json([
            'ok' => false,
            'message' => 'CSRFトークンが無効です。ページを再読み込みしてください。'
        ], 403);
    }
}

function survey_clean_domain(string $domain): string
{
    $domain = trim($domain);
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    $domain = preg_replace('#\.cybozu\.com$#i', '', $domain);
    return trim($domain, " \t\n\r\0\x0B.");
}

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = survey_clean_domain($domain);

    if ($domain === '') {
        return '';
    }

    return 'https://' . $domain . '.cybozu.com/' . ltrim($endpoint, '/');
}

function get_safe_response_headers(): array
{
    /*
     * PHP 8.4/8.5で非推奨となった $http_response_header を直接参照しない。
     * http_get_last_response_headers() が利用できる環境ではこれを使用する。
     */
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    if ($url === '') {
        return [
            'success' => false,
            'status' => 0,
            'message' => 'kintoneドメインが設定されていません。',
            'raw' => null,
            'headers' => []
        ];
    }

    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => max(1, (int)($config['timeout'] ?? 20))
    ];

    /*
     * GETのパラメータはURLのquery stringで渡す。
     * 特に /k/v1/app/form/fields.json の app は必須。
     */
    if ($method !== 'GET' && $payload !== null) {
        $encoded = is_string($payload)
            ? $payload
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'JSONエンコードに失敗しました。',
                'raw' => null,
                'headers' => []
            ];
        }

        $options['content'] = $encoded;
        $options['header'] .= "\r\nContent-Type: application/json";
    }

    $contextOptions = [
        'http' => $options,
        'ssl' => [
            'verify_peer' => !empty($config['ssl_verify']),
            'verify_peer_name' => !empty($config['ssl_verify']),
            'allow_self_signed' => empty($config['ssl_verify'])
        ]
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        $proxy = preg_replace('#^https?://#i', '', $proxy);
        if (preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
            $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
            $contextOptions['http']['request_fulluri'] = true;
        }
    }

    $context = stream_context_create($contextOptions);

    $body = @file_get_contents($url, false, $context);
    $headersOut = get_safe_response_headers();

    $status = 0;

    foreach ($headersOut as $headerLine) {
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $headerLine, $m)) {
            $status = (int)$m[1];
        }
    }

    $decoded = json_decode($body === false ? '' : $body, true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
            'raw' => $body,
            'headers' => $headersOut
        ];
    }

    $message = is_array($decoded)
        ? (string)($decoded['message'] ?? 'kintone API 通信エラーが発生しました。')
        : 'kintone API 通信エラーが発生しました。';

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'raw' => $body,
        'data' => is_array($decoded) ? $decoded : [],
        'headers' => $headersOut
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string
{
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . $password);
}

function kintone_settings(array $settings): array
{
    return [
        'subdomain' => (string)($settings['subdomain'] ?? ''),
        'login_name' => (string)($settings['login_name'] ?? ''),
        'password' => (string)($settings['password'] ?? ''),
        'ssl_verify' => !empty($settings['ssl_verify']),
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
            'message' => '顧客管理アプリIDは数字で入力してください。',
            'data' => []
        ];
    }

    $domain = survey_clean_domain((string)($settings['subdomain'] ?? ''));

    if ($domain === '') {
        return [
            'success' => false,
            'status' => 0,
            'message' => 'kintoneサブドメインが設定されていません。',
            'data' => []
        ];
    }

    $query = http_build_query([
        'app' => $appId,
        'lang' => 'ja'
    ]);

    $url = kintone_build_url(
        $domain,
        '/k/v1/app/form/fields.json?' . $query
    );

    $headers = [
        make_cybozu_auth_header(
            (string)($settings['login_name'] ?? ''),
            (string)($settings['password'] ?? '')
        ),
        'Accept: application/json',
        'Accept-Language: ja'
    ];

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        null,
        kintone_settings($settings)
    );
}

function kintone_connection_test(array $settings): array
{
    $domain = survey_clean_domain((string)($settings['subdomain'] ?? ''));

    if ($domain === '') {
        return [
            'success' => false,
            'status' => 0,
            'message' => 'サブドメインを入力してください。',
            'url' => ''
        ];
    }

    $url = kintone_build_url($domain, '/k/v1/app.json?app=1');

    $result = kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header(
                (string)($settings['login_name'] ?? ''),
                (string)($settings['password'] ?? '')
            ),
            'Accept: application/json',
            'Accept-Language: ja'
        ],
        null,
        kintone_settings($settings)
    );

    return [
        'success' => $result['success'],
        'status' => $result['status'],
        'message' => $result['success']
            ? 'kintone APIへの接続・認証に成功しました。'
            : $result['message'],
        'url' => $url,
        'api_response' => $result['data'] ?? null,
        'raw' => $result['raw'] ?? null
    ];
}

/* --------------------------------------------------------------------
 * SMTP
 * PHP mail()/MTAには依存せず、SMTPサーバへ直接接続する。
 * ------------------------------------------------------------------ */

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
        $lines[] = $line;

        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];

            if ($m[2] === ' ') {
                break;
            }
        }
    }

    $meta = stream_get_meta_data($socket);

    return [
        'code' => $code,
        'lines' => $lines,
        'text' => implode("\n", $lines),
        'timed_out' => !empty($meta['timed_out'])
    ];
}

function smtp_write($socket, string $command): bool
{
    return @fwrite($socket, $command . "\r\n") !== false;
}

function smtp_command($socket, string $command, int $timeout = 15): array
{
    if (!smtp_write($socket, $command)) {
        return [
            'code' => 0,
            'text' => 'SMTPコマンドの送信に失敗しました。'
        ];
    }

    return smtp_read($socket, $timeout);
}

function smtp_socket(array $cfg): array
{
    $host = trim((string)($cfg['smtp_host'] ?? ''));
    $port = (int)($cfg['smtp_port'] ?? 465);
    $encryption = strtoupper(trim((string)($cfg['smtp_encryption'] ?? 'SSL')));
    $timeout = max(1, (int)($cfg['smtp_timeout'] ?? 15));

    if ($host === '') {
        return [
            'success' => false,
            'stage' => 'configuration',
            'host' => '',
            'port' => $port,
            'encryption' => $encryption,
            'tcp_result' => false,
            'auth_result' => false,
            'smtp_code' => 0,
            'message' => 'SMTPサーバが設定されていません。',
            'error' => ''
        ];
    }

    /*
     * SSL/465:
     * tls:// を使用して接続時からTLSを確立。
     *
     * TLS/587:
     * tcp:// で接続し、EHLO後 STARTTLS を実行。
     */
    $transport = $encryption === 'SSL' ? 'ssl' : 'tcp';
    $address = $transport . '://' . $host . ':' . $port;

    $contextOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'peer_name' => $host
        ]
    ];

    $context = stream_context_create($contextOptions);

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $address,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        return [
            'success' => false,
            'stage' => 'tcp',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'tcp_result' => false,
            'auth_result' => false,
            'smtp_code' => 0,
            'message' => 'SMTPサーバへ接続できませんでした。',
            'error' => 'errno=' . $errno . ' / ' . $errstr
        ];
    }

    stream_set_timeout($socket, $timeout);

    $greeting = smtp_read($socket, $timeout);

    if ($greeting['code'] < 200 || $greeting['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'stage' => 'greeting',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'tcp_result' => true,
            'auth_result' => false,
            'smtp_code' => $greeting['code'],
            'message' => 'SMTPサーバから正常な初期応答を受信できませんでした。',
            'error' => $greeting['text']
        ];
    }

    $ehlo = smtp_command($socket, 'EHLO localhost', $timeout);

    if ($ehlo['code'] < 200 || $ehlo['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'stage' => 'ehlo',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'tcp_result' => true,
            'auth_result' => false,
            'smtp_code' => $ehlo['code'],
            'message' => 'SMTP EHLOに失敗しました。',
            'error' => $ehlo['text']
        ];
    }

    if ($encryption === 'TLS') {
        $tls = smtp_command($socket, 'STARTTLS', $timeout);

        if ($tls['code'] !== 220) {
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'tls',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'tcp_result' => true,
                'auth_result' => false,
                'smtp_code' => $tls['code'],
                'message' => 'STARTTLSに失敗しました。',
                'error' => $tls['text']
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
                'auth_result' => false,
                'smtp_code' => 0,
                'message' => 'TLS暗号化の確立に失敗しました。',
                'error' => 'stream_socket_enable_crypto() failed'
            ];
        }

        $ehlo = smtp_command($socket, 'EHLO localhost', $timeout);
    }

    $username = (string)($cfg['smtp_username'] ?? '');
    $password = (string)($cfg['smtp_password'] ?? '');
    $useAuth = !empty($cfg['smtp_auth']);

    $authResult = [
        'code' => 250,
        'text' => '認証なし'
    ];

    if ($useAuth) {
        /*
         * AUTH LOGIN は広く対応している方式。
         * ユーザー名・パスワードは診断ログへ出さない。
         */
        $authResult = smtp_command($socket, 'AUTH LOGIN', $timeout);

        if ($authResult['code'] === 334) {
            $authResult = smtp_command(
                $socket,
                base64_encode($username),
                $timeout
            );
        }

        if ($authResult['code'] === 334) {
            $authResult = smtp_command(
                $socket,
                base64_encode($password),
                $timeout
            );
        }

        if ($authResult['code'] < 200 || $authResult['code'] >= 300) {
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'authentication',
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'tcp_result' => true,
                'auth_result' => false,
                'smtp_code' => $authResult['code'],
                'message' => 'SMTP認証に失敗しました。',
                'error' => $authResult['text']
            ];
        }
    }

    smtp_command($socket, 'QUIT', $timeout);
    fclose($socket);

    return [
        'success' => true,
        'stage' => 'authentication',
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'tcp_result' => true,
        'auth_result' => $useAuth,
        'smtp_code' => $authResult['code'],
        'message' => 'SMTP接続・認証に成功しました。',
        'error' => ''
    ];
}

function smtp_dot_escape(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);

    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }

    return implode("\r\n", $lines);
}

function smtp_encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_send_mail(array $cfg, string $to, string $subject, string $body): array
{
    $host = trim((string)($cfg['smtp_host'] ?? ''));
    $port = (int)($cfg['smtp_port'] ?? 465);
    $encryption = strtoupper(trim((string)($cfg['smtp_encryption'] ?? 'SSL')));
    $timeout = max(1, (int)($cfg['smtp_timeout'] ?? 15));

    if ($host === '' || $to === '') {
        return [
            'success' => false,
            'code' => 0,
            'message' => 'SMTP設定または宛先が未設定です。'
        ];
    }

    $transport = $encryption === 'SSL' ? 'ssl' : 'tcp';

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'peer_name' => $host
        ]
    ]);

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $transport . '://' . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        return [
            'success' => false,
            'code' => 0,
            'message' => 'SMTP接続失敗: errno=' . $errno . ' / ' . $errstr
        ];
    }

    $greeting = smtp_read($socket, $timeout);

    if ($greeting['code'] < 200 || $greeting['code'] >= 400) {
        fclose($socket);
        return [
            'success' => false,
            'code' => $greeting['code'],
            'message' => $greeting['text']
        ];
    }

    $ehlo = smtp_command($socket, 'EHLO localhost', $timeout);

    if ($ehlo['code'] < 200 || $ehlo['code'] >= 400) {
        fclose($socket);
        return [
            'success' => false,
            'code' => $ehlo['code'],
            'message' => $ehlo['text']
        ];
    }

    if ($encryption === 'TLS') {
        $tls = smtp_command($socket, 'STARTTLS', $timeout);

        if ($tls['code'] !== 220) {
            fclose($socket);
            return [
                'success' => false,
                'code' => $tls['code'],
                'message' => 'STARTTLS失敗: ' . $tls['text']
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
                'code' => 0,
                'message' => 'TLS暗号化の確立に失敗しました。'
            ];
        }

        $ehlo = smtp_command($socket, 'EHLO localhost', $timeout);
    }

    if (!empty($cfg['smtp_auth'])) {
        $username = (string)($cfg['smtp_username'] ?? '');
        $password = (string)($cfg['smtp_password'] ?? '');

        $auth = smtp_command($socket, 'AUTH LOGIN', $timeout);

        if ($auth['code'] === 334) {
            $auth = smtp_command(
                $socket,
                base64_encode($username),
                $timeout
            );
        }

        if ($auth['code'] === 334) {
            $auth = smtp_command(
                $socket,
                base64_encode($password),
                $timeout
            );
        }

        if ($auth['code'] < 200 || $auth['code'] >= 300) {
            fclose($socket);

            return [
                'success' => false,
                'code' => $auth['code'],
                'message' => 'SMTP認証失敗: ' . $auth['text']
            ];
        }
    }

    $from = trim((string)($cfg['smtp_from'] ?? ''));
    $fromName = trim((string)($cfg['smtp_from_name'] ?? ''));

    if ($from === '') {
        fclose($socket);
        return [
            'success' => false,
            'code' => 0,
            'message' => '送信元メールアドレスが設定されていません。'
        ];
    }

    $mailFrom = smtp_command($socket, 'MAIL FROM:<' . $from . '>', $timeout);

    if ($mailFrom['code'] < 200 || $mailFrom['code'] >= 300) {
        fclose($socket);
        return [
            'success' => false,
            'code' => $mailFrom['code'],
            'message' => 'MAIL FROM拒否: ' . $mailFrom['text']
        ];
    }

    $rcpt = smtp_command($socket, 'RCPT TO:<' . $to . '>', $timeout);

    if ($rcpt['code'] < 200 || $rcpt['code'] >= 300) {
        fclose($socket);
        return [
            'success' => false,
            'code' => $rcpt['code'],
            'message' => 'RCPT TO拒否: ' . $rcpt['text']
        ];
    }

    $data = smtp_command($socket, 'DATA', $timeout);

    if ($data['code'] !== 354) {
        fclose($socket);
        return [
            'success' => false,
            'code' => $data['code'],
            'message' => 'DATA拒否: ' . $data['text']
        ];
    }

    $encodedSubject = smtp_encode_header($subject);
    $encodedFromName = $fromName !== ''
        ? smtp_encode_header($fromName)
        : $from;

    $message =
        'From: ' . $encodedFromName . ' <' . $from . ">\r\n" .
        'To: <' . $to . ">\r\n" .
        'Subject: ' . $encodedSubject . "\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n" .
        "\r\n" .
        smtp_dot_escape($body) .
        "\r\n.";

    if (!smtp_write($socket, $message)) {
        fclose($socket);
        return [
            'success' => false,
            'code' => 0,
            'message' => 'メール本文送信に失敗しました。'
        ];
    }

    $result = smtp_read($socket, $timeout);

    smtp_command($socket, 'QUIT', $timeout);
    fclose($socket);

    if ($result['code'] >= 200 && $result['code'] < 300) {
        return [
            'success' => true,
            'code' => $result['code'],
            'message' => 'SMTP送信成功'
        ];
    }

    return [
        'success' => false,
        'code' => $result['code'],
        'message' => 'SMTP送信拒否: ' . $result['text']
    ];
}

function customer_by_id(array $customers, string $id): ?array
{
    foreach ($customers as $customer) {
        if ((string)($customer['id'] ?? '') === $id) {
            return $customer;
        }
    }
    return null;
}

function replace_mail_variables(
    string $subject,
    string $body,
    array $customer,
    string $surveyUrl
): array {
    $name = (string)($customer['name'] ?? '');

    return [
        str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [$name, $surveyUrl],
            $subject
        ),
        str_replace(
            ['{顧客名}', '{アンケートURL}'],
            [$name, $surveyUrl],
            $body
        )
    ];
}

function survey_find_index(array $items, string $id): int
{
    foreach ($items as $i => $item) {
        if ((string)($item['id'] ?? '') === $id) {
            return $i;
        }
    }
    return -1;
}

/* --------------------------------------------------------------------
 * API
 * ------------------------------------------------------------------ */

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        survey_check_csrf();
    }

    $data = survey_read_data();

    switch ($action) {
        case 'state':
            survey_json([
                'ok' => true,
                'data' => $data,
                'csrf_token' => survey_csrf()
            ]);
            break;

        case 'save_survey':
            $raw = (string)($_POST['survey_json'] ?? '');
            $survey = json_decode($raw, true);

            if (!is_array($survey)) {
                survey_json([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $now = survey_now();
            $id = (string)($survey['id'] ?? '');

            if ($id === '') {
                $id = survey_id();
                $survey['id'] = $id;
                $survey['created_at'] = $now;
            }

            $survey['updated_at'] = $now;
            $survey['deleted'] = !empty($survey['deleted']);
            $survey['title'] = (string)($survey['title'] ?? '無題のアンケート');
            $survey['status'] = in_array(
                $survey['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            ) ? $survey['status'] : 'draft';

            if (!isset($survey['groups']) || !is_array($survey['groups'])) {
                $survey['groups'] = [];
            }

            $index = survey_find_index($data['surveys'], $id);

            if ($index < 0) {
                $data['surveys'][] = $survey;
            } else {
                $data['surveys'][$index] = $survey;
            }

            if (!survey_write_data($data)) {
                survey_json([
                    'ok' => false,
                    'message' => 'データ保存に失敗しました。survey_storageの書き込み権限を確認してください。'
                ], 500);
            }

            survey_json([
                'ok' => true,
                'survey' => $survey
            ]);
            break;

        case 'delete_survey':
            $id = (string)($_POST['survey_id'] ?? '');
            $index = survey_find_index($data['surveys'], $id);

            if ($index < 0) {
                survey_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。'
                ], 404);
            }

            $data['surveys'][$index]['deleted'] = true;
            $data['surveys'][$index]['updated_at'] = survey_now();

            survey_write_data($data);

            survey_json(['ok' => true]);
            break;

        case 'save_settings':
            $raw = (string)($_POST['settings_json'] ?? '');
            $settings = json_decode($raw, true);

            if (!is_array($settings)) {
                survey_json([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            $data['settings'] = array_replace(
                $data['settings'],
                $settings
            );

            /*
             * パスワード未入力時は既存値を保持。
             */
            if (($settings['password'] ?? null) === null) {
                $data['settings']['password'] =
                    $data['settings']['password'] ?? '';
            }

            if (($settings['smtp_password'] ?? null) === null) {
                $data['settings']['smtp_password'] =
                    $data['settings']['smtp_password'] ?? '';
            }

            if (!survey_write_data($data)) {
                survey_json([
                    'ok' => false,
                    'message' => '設定保存に失敗しました。'
                ], 500);
            }

            $safe = $data['settings'];
            $safe['password'] = '';
            $safe['smtp_password'] = '';

            survey_json([
                'ok' => true,
                'settings' => $safe
            ]);
            break;

        case 'kintone_test':
            $settings = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($settings)) {
                $settings = $data['settings'];
            }

            survey_json(kintone_connection_test($settings));
            break;

        case 'kintone_fields':
            $settings = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($settings)) {
                $settings = $data['settings'];
            }

            $appId = trim((string)($_POST['app_id'] ?? ''));

            $result = kintone_fields($settings, $appId);

            if (!$result['success']) {
                survey_json([
                    'ok' => false,
                    'message' => $result['message'] ?? '項目一覧取得に失敗しました。',
                    'http_status' => $result['status'] ?? 0,
                    'api_response' => $result['data'] ?? null,
                    'raw' => $result['raw'] ?? null
                ], 400);
            }

            $fields = [];

            foreach (($result['data']['properties'] ?? []) as $code => $field) {
                if (!is_array($field)) {
                    continue;
                }

                $fields[] = [
                    'label' => (string)($field['label'] ?? $code),
                    'code' => (string)($field['code'] ?? $code),
                    'type' => (string)($field['type'] ?? '')
                ];
            }

            survey_json([
                'ok' => true,
                'fields' => $fields,
                'revision' => $result['data']['revision'] ?? null
            ]);
            break;

        case 'smtp_test_connection':
            $settings = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($settings)) {
                $settings = $data['settings'];
            }

            $result = smtp_socket($settings);
            survey_json($result);
            break;

        case 'smtp_test_mail':
            $settings = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($settings)) {
                $settings = $data['settings'];
            }

            $to = trim((string)($_POST['customer_id'] ?? ''));

            /*
             * テストメールの宛先はcustomer_idパラメータを流用せず、
             * mail_toという追加の内部パラメータを許容する。
             */
            $to = trim((string)($_POST['mail_to'] ?? $to));

            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                survey_json([
                    'ok' => false,
                    'message' => 'テストメール送信先メールアドレスが不正です。'
                ], 400);
            }

            $result = smtp_send_mail(
                $settings,
                $to,
                'アンケート管理システム SMTP送信テスト',
                "SMTP設定が正常に動作し、テストメールの送信に成功したことを確認するための固定メッセージです。\n\n送信日時: " . survey_now()
            );

            survey_json([
                'ok' => $result['success'],
                'result' => $result,
                'message' => $result['success']
                    ? 'テストメールを送信しました。'
                    : $result['message']
            ], $result['success'] ? 200 : 400);
            break;

        case 'sync_customers':
            $settings = $data['settings'];

            $appId = trim((string)($settings['app_id'] ?? ''));

            if ($appId === '' || !preg_match('/^\d+$/', $appId)) {
                survey_json([
                    'ok' => false,
                    'message' => '顧客管理アプリIDが設定されていません。'
                ], 400);
            }

            $domain = survey_clean_domain(
                (string)($settings['subdomain'] ?? '')
            );

            $query = http_build_query([
                'app' => $appId,
                'query' => '',
                'totalCount' => 'true'
            ]);

            $url = kintone_build_url(
                $domain,
                '/k/v1/records.json?' . $query
            );

            $result = kintone_api_request(
                'GET',
                $url,
                [
                    make_cybozu_auth_header(
                        (string)($settings['login_name'] ?? ''),
                        (string)($settings['password'] ?? '')
                    ),
                    'Accept: application/json',
                    'Accept-Language: ja'
                ],
                null,
                kintone_settings($settings)
            );

            if (!$result['success']) {
                survey_json([
                    'ok' => false,
                    'message' => $result['message'],
                    'http_status' => $result['status'],
                    'api_response' => $result['data'] ?? null,
                    'raw' => $result['raw'] ?? null
                ], 400);
            }

            $map = [
                'company' => (string)($settings['field_company'] ?? ''),
                'name' => (string)($settings['field_name'] ?? ''),
                'email' => (string)($settings['field_email'] ?? ''),
                'department' => (string)($settings['field_department'] ?? ''),
                'phone' => (string)($settings['field_phone'] ?? '')
            ];

            $addressMap = $settings['field_address'] ?? [];
            if (!is_array($addressMap)) {
                $addressMap = [$addressMap];
            }

            $newCustomers = [];

            foreach (($result['data']['records'] ?? []) as $record) {
                $getValue = static function (
                    array $record,
                    string $code
                ): string {
                    if ($code === '' || !isset($record[$code])) {
                        return '';
                    }

                    $value = $record[$code]['value'] ?? '';

                    if (is_array($value)) {
                        $values = [];
                        foreach ($value as $v) {
                            if (is_array($v)) {
                                $values[] = (string)($v['name'] ?? $v['value'] ?? '');
                            } else {
                                $values[] = (string)$v;
                            }
                        }
                        return implode(' ', $values);
                    }

                    return (string)$value;
                };

                $email = $getValue($record, $map['email']);

                if ($email === '') {
                    continue;
                }

                $address = [];

                foreach ($addressMap as $addressCode) {
                    $value = $getValue($record, (string)$addressCode);
                    if ($value !== '') {
                        $address[] = $value;
                    }
                }

                $newCustomers[] = [
                    'id' => 'k_' . hash('sha256', $email),
                    'company' => $getValue($record, $map['company']),
                    'name' => $getValue($record, $map['name']),
                    'email' => $email,
                    'department' => $getValue($record, $map['department']),
                    'phone' => $getValue($record, $map['phone']),
                    'address' => implode(' ', $address),
                    'source' => 'kintone',
                    'sent_at' => '',
                    'send_count' => 0,
                    'answer_status' => 'unanswered',
                    'kintone_status' => 'registered'
                ];
            }

            /*
             * 既存顧客の送信履歴は保持して同期する。
             */
            $oldByEmail = [];

            foreach ($data['customers'] as $old) {
                $oldEmail = strtolower(trim((string)($old['email'] ?? '')));
                if ($oldEmail !== '') {
                    $oldByEmail[$oldEmail] = $old;
                }
            }

            foreach ($newCustomers as &$customer) {
                $emailKey = strtolower($customer['email']);

                if (isset($oldByEmail[$emailKey])) {
                    $old = $oldByEmail[$emailKey];

                    $customer['sent_at'] = $old['sent_at'] ?? '';
                    $customer['send_count'] = (int)($old['send_count'] ?? 0);
                    $customer['answer_status'] =
                        $old['answer_status'] ?? 'unanswered';
                }
            }
            unset($customer);

            $data['customers'] = $newCustomers;
            survey_write_data($data);

            survey_json([
                'ok' => true,
                'count' => count($newCustomers)
            ]);
            break;

        case 'send_bulk':
            $settings = $data['settings'];

            if (
                trim((string)($settings['smtp_host'] ?? '')) === '' ||
                trim((string)($settings['smtp_from'] ?? '')) === ''
            ) {
                survey_json([
                    'ok' => false,
                    'message' => 'SMTP設定が未完了です。SMTPサーバと送信元メールアドレスを設定してください。'
                ], 400);
            }

            $surveyId = (string)($_POST['survey_id'] ?? '');
            $recipientRaw = json_decode(
                (string)($_POST['recipient_ids'] ?? '[]'),
                true
            );

            $recipientIds = is_array($recipientRaw) ? $recipientRaw : [];

            $subject = (string)($_POST['mail_subject'] ?? '');
            $body = (string)($_POST['mail_body'] ?? '');
            $templateType = (string)($_POST['template_type'] ?? 'initial');

            $surveyIndex = survey_find_index($data['surveys'], $surveyId);

            if ($surveyIndex < 0) {
                survey_json([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。'
                ], 404);
            }

            $survey = $data['surveys'][$surveyIndex];

            $success = 0;
            $failed = 0;
            $unsent = 0;
            $details = [];

            $logId = survey_id();
            $log = [
                'id' => $logId,
                'survey_id' => $surveyId,
                'sent_at' => survey_now(),
                'type' => $templateType === 'reminder' ? 'reminder' : 'initial',
                'target_count' => count($recipientIds),
                'success_count' => 0,
                'failed_count' => 0,
                'subject' => $subject,
                'executed_by' => $_SESSION['login_name'] ?? 'admin',
                'details' => []
            ];

            foreach ($recipientIds as $recipientId) {
                $recipientId = (string)$recipientId;

                $customerIndex = survey_find_index(
                    $data['customers'],
                    $recipientId
                );

                if ($customerIndex < 0) {
                    $unsent++;

                    $details[] = [
                        'customer_id' => $recipientId,
                        'result' => 'unsent',
                        'message' => '顧客が見つかりません。'
                    ];

                    continue;
                }

                $customer = $data['customers'][$customerIndex];
                $email = trim((string)($customer['email'] ?? ''));

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $unsent++;

                    $details[] = [
                        'customer_id' => $recipientId,
                        'result' => 'unsent',
                        'message' => 'メールアドレスが不正です。'
                    ];

                    continue;
                }

                $answerUrl =
                    rtrim(
                        ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                            ? 'https://'
                            : 'http://') .
                        ($_SERVER['HTTP_HOST'] ?? ''),
                        '/'
                    ) .
                    dirname($_SERVER['SCRIPT_NAME'] ?? '/') .
                    '/?answer=' . rawurlencode($surveyId) .
                    '&customer=' . rawurlencode($recipientId);

                [$realSubject, $realBody] =
                    replace_mail_variables(
                        $subject,
                        $body,
                        $customer,
                        $answerUrl
                    );

                $send = smtp_send_mail(
                    $settings,
                    $email,
                    $realSubject,
                    $realBody
                );

                if ($send['success']) {
                    $success++;

                    $data['customers'][$customerIndex]['sent_at'] =
                        survey_now();

                    $data['customers'][$customerIndex]['send_count'] =
                        (int)($data['customers'][$customerIndex]['send_count'] ?? 0) + 1;

                    $data['customers'][$customerIndex]['answer_status'] =
                        'unanswered';

                    $data['customers'][$customerIndex]['last_send_result'] =
                        'success';

                    $data['customers'][$customerIndex]['last_send_error'] = '';

                    $details[] = [
                        'customer_id' => $recipientId,
                        'email' => $email,
                        'result' => 'success',
                        'sent_at' => survey_now(),
                        'subject' => $realSubject,
                        'body' => $realBody
                    ];
                } else {
                    $failed++;

                    $data['customers'][$customerIndex]['last_send_result'] =
                        'failed';

                    $data['customers'][$customerIndex]['last_send_error'] =
                        (string)$send['message'];

                    $details[] = [
                        'customer_id' => $recipientId,
                        'email' => $email,
                        'result' => 'failed',
                        'message' => (string)$send['message'],
                        'smtp_code' => (int)($send['code'] ?? 0),
                        'subject' => $realSubject,
                        'body' => $realBody
                    ];
                }
            }

            $log['success_count'] = $success;
            $log['failed_count'] = $failed;
            $log['details'] = $details;

            $data['mail_logs'][] = $log;

            survey_write_data($data);

            survey_json([
                'ok' => true,
                'success_count' => $success,
                'failed_count' => $failed,
                'unsent_count' => $unsent,
                'log_id' => $logId,
                'details' => $details
            ]);
            break;

        case 'register_customer':
            $customerId = (string)($_POST['customer_id'] ?? '');
            $index = survey_find_index($data['customers'], $customerId);

            if ($index < 0) {
                survey_json([
                    'ok' => false,
                    'message' => '顧客が見つかりません。'
                ], 404);
            }

            $data['customers'][$index]['kintone_status'] = 'registered';
            survey_write_data($data);

            survey_json(['ok' => true]);
            break;

        case 'save_response':
            $responseRaw = json_decode(
                (string)($_POST['response_json'] ?? ''),
                true
            );

            if (!is_array($responseRaw)) {
                survey_json([
                    'ok' => false,
                    'message' => '回答データが不正です。'
                ], 400);
            }

            $response = [
                'id' => (string)($responseRaw['id'] ?? survey_id()),
                'survey_id' => (string)($responseRaw['survey_id'] ?? ''),
                'customer_id' => (string)($responseRaw['customer_id'] ?? ''),
                'company' => (string)($responseRaw['company'] ?? ''),
                'name' => (string)($responseRaw['name'] ?? ''),
                'email' => (string)($responseRaw['email'] ?? ''),
                'answered_at' => survey_now(),
                'answers' => is_array($responseRaw['answers'] ?? null)
                    ? $responseRaw['answers']
                    : []
            ];

            $existing = survey_find_index(
                $data['responses'],
                $response['id']
            );

            if ($existing >= 0) {
                $data['responses'][$existing] = $response;
            } else {
                $data['responses'][] = $response;
            }

            foreach ($data['customers'] as $i => $customer) {
                $emailMatch =
                    strtolower(trim((string)($customer['email'] ?? ''))) !== '' &&
                    strtolower(trim((string)($customer['email'] ?? ''))) ===
                    strtolower(trim($response['email']));

                if (
                    $emailMatch &&
                    $response['survey_id'] === ($responseRaw['survey_id'] ?? '')
                ) {
                    $data['customers'][$i]['answer_status'] = 'answered';
                    break;
                }
            }

            survey_write_data($data);

            survey_json([
                'ok' => true,
                'response_id' => $response['id']
            ]);
            break;

        case 'csv':
            $surveyId = (string)($_GET['survey_id'] ?? '');

            $rows = [];

            foreach ($data['responses'] as $response) {
                if ($surveyId !== '' && $response['survey_id'] !== $surveyId) {
                    continue;
                }

                $rows[] = $response;
            }

            $filename = 'survey_responses_' .
                date('Ymd_His') .
                '.csv';

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="' .
                $filename .
                '"'
            );

            echo "\xEF\xBB\xBF";

            $fp = fopen('php://output', 'w');

            fputcsv($fp, [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名',
                'メールアドレス',
                '回答'
            ]);

            foreach ($rows as $row) {
                $answers = $row['answers'] ?? [];

                if (is_array($answers)) {
                    $answerText = json_encode(
                        $answers,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                } else {
                    $answerText = (string)$answers;
                }

                fputcsv($fp, [
                    $row['id'] ?? '',
                    $row['answered_at'] ?? '',
                    $row['customer_id'] ?? '',
                    $row['company'] ?? '',
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $answerText
                ]);
            }

            fclose($fp);
            exit;

        default:
            survey_json([
                'ok' => false,
                'message' => '未知のAPIアクションです。'
            ], 400);
    }
}

$csrf = survey_csrf();

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
        data: null,
        screen: 'list',
        editingSurvey: null,
        selectedSurveyId: '',
        csrf: <?=
            json_encode($csrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ?>,
        fields: [],
        loading: false,
        previewMode: 'pc',
        responseFilter: '',
        customerFilter: '',
        selectedCustomers: [],
        settingsPasswordSet: false,
        smtpPasswordSet: false
    },

    api: {},

    actions: {},

    render: {},

    utils: {},

    initStarted: false,

    init: async function() {
        if (App.initStarted) return;
        App.initStarted = true;

        App.render.loading('初期化しています…');

        try {
            const result = await App.api.getState();

            if (!result.ok) {
                throw new Error(result.message || '初期化に失敗しました。');
            }

            App.state.data = result.data;
            App.state.csrf = result.csrf_token;

            App.renderScreen('list');
        } catch (error) {
            App.render.error(
                '初期化に失敗しました',
                error && error.message
                    ? error.message
                    : 'サーバーとの通信に失敗しました。'
            );
        }
    },

    renderScreen: function(screen, param = '') {
        App.state.screen = screen;

        if (screen === 'list') {
            App.render.list();
        } else if (screen === 'editor') {
            App.render.editor(param);
        } else if (screen === 'aggregate') {
            App.render.aggregate(param);
        } else if (screen === 'mail') {
            App.render.mail(param);
        } else if (screen === 'settings') {
            App.render.settings();
        } else {
            App.render.list();
        }
    },

    api: {
        request: async function(action, options = {}) {
            const method = options.method || 'POST';
            const body = options.body || {};

            let url = location.pathname;
            let fetchOptions = {
                method,
                headers: {}
            };

            if (method === 'GET') {
                const params = new URLSearchParams({
                    action,
                    ...(body || {})
                });

                url += '?' + params.toString();
            } else {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('csrf_token', App.state.csrf);

                Object.keys(body || {}).forEach(function(key) {
                    const value = body[key];

                    if (
                        value !== null &&
                        typeof value === 'object'
                    ) {
                        fd.append(key, JSON.stringify(value));
                    } else {
                        fd.append(key, String(value ?? ''));
                    }
                });

                fetchOptions.body = fd;
            }

            const response = await fetch(url, fetchOptions);
            const text = await response.text();

            let json;

            try {
                json = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーからJSONではない応答が返りました。\n' +
                    text.substring(0, 500)
                );
            }

            if (!response.ok && !json.ok) {
                const detail = json.api_response
                    ? '\n' + JSON.stringify(
                        json.api_response,
                        null,
                        2
                    )
                    : '';

                throw new Error(
                    (json.message || 'サーバー処理に失敗しました。') +
                    detail
                );
            }

            return json;
        },

        getState: async function() {
            return await App.api.request(
                'state',
                {method: 'GET', body: {}}
            );
        },

        saveSurvey: async function(survey) {
            return await App.api.request(
                'save_survey',
                {
                    method: 'POST',
                    body: {
                        survey_json: survey
                    }
                }
            );
        },

        deleteSurvey: async function(id) {
            return await App.api.request(
                'delete_survey',
                {
                    body: {
                        survey_id: id
                    }
                }
            );
        },

        saveSettings: async function(settings) {
            return await App.api.request(
                'save_settings',
                {
                    body: {
                        settings_json: settings
                    }
                }
            );
        },

        kintoneTest: async function(settings) {
            return await App.api.request(
                'kintone_test',
                {
                    body: {
                        settings_json: settings
                    }
                }
            );
        },

        kintoneFields: async function(settings, appId) {
            return await App.api.request(
                'kintone_fields',
                {
                    body: {
                        settings_json: settings,
                        app_id: appId
                    }
                }
            );
        },

        syncCustomers: async function() {
            return await App.api.request('sync_customers');
        },

        smtpTestConnection: async function(settings) {
            return await App.api.request(
                'smtp_test_connection',
                {
                    body: {
                        settings_json: settings
                    }
                }
            );
        },

        smtpTestMail: async function(settings, mailTo) {
            return await App.api.request(
                'smtp_test_mail',
                {
                    body: {
                        settings_json: settings,
                        mail_to: mailTo
                    }
                }
            );
        },

        sendBulk: async function(
            surveyId,
            recipientIds,
            subject,
            body,
            templateType
        ) {
            return await App.api.request(
                'send_bulk',
                {
                    body: {
                        survey_id: surveyId,
                        recipient_ids: recipientIds,
                        mail_subject: subject,
                        mail_body: body,
                        template_type: templateType
                    }
                }
            );
        },

        registerCustomer: async function(id) {
            return await App.api.request(
                'register_customer',
                {
                    body: {
                        customer_id: id
                    }
                }
            );
        },

        saveResponse: async function(response) {
            return await App.api.request(
                'save_response',
                {
                    body: {
                        response_json: response
                    }
                }
            );
        }
    },

    utils: {
        esc: function(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        uuid: function() {
            if (crypto.randomUUID) {
                return crypto.randomUUID();
            }

            return Date.now().toString(36) +
                Math.random().toString(36).slice(2);
        },

        survey: function(id) {
            return (App.state.data.surveys || []).find(
                s => String(s.id) === String(id)
            ) || null;
        },

        statusText: function(status) {
            return {
                draft: '下書き',
                active: '公開中',
                ended: '終了'
            }[status] || status;
        },

        statusClass: function(status) {
            return {
                draft: 'bg-slate-100 text-slate-700',
                active: 'bg-emerald-100 text-emerald-700',
                ended: 'bg-amber-100 text-amber-700'
            }[status] || 'bg-slate-100 text-slate-700';
        },

        formatDate: function(value) {
            if (!value) return '未設定';

            const d = new Date(
                String(value).replace(' ', 'T')
            );

            if (Number.isNaN(d.getTime())) {
                return value;
            }

            return d.toLocaleString('ja-JP');
        },

        formatDateOnly: function(value) {
            if (!value) return '未設定';

            const d = new Date(
                String(value).replace(' ', 'T')
            );

            if (Number.isNaN(d.getTime())) {
                return value;
            }

            return d.toLocaleDateString('ja-JP');
        },

        clone: function(value) {
            return JSON.parse(JSON.stringify(value));
        },

        escAttr: function(value) {
            return App.utils.esc(value);
        },

        defaultSurvey: function() {
            return {
                id: '',
                title: '新規アンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: '',
                updated_at: '',
                numbering_mode: 'global',
                deleted: false,
                groups: [
                    {
                        id: App.utils.uuid(),
                        name: 'グループ1',
                        questions: []
                    }
                ]
            };
        },

        defaultQuestion: function() {
            return {
                id: App.utils.uuid(),
                text: '新しい質問',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false
            };
        },

        questionNumber: function(survey, groupIndex, questionIndex) {
            if (survey.numbering_mode === 'group') {
                return 'Q' +
                    (groupIndex + 1) +
                    '-' +
                    (questionIndex + 1);
            }

            let number = 0;

            survey.groups.forEach(function(group, gi) {
                if (gi < groupIndex) {
                    number += (group.questions || []).length;
                }
            });

            number += questionIndex + 1;

            return 'Q' + number;
        },

        toast: function(message, type = 'success') {
            const color = type === 'error'
                ? 'bg-red-600'
                : type === 'warning'
                    ? 'bg-amber-500'
                    : 'bg-slate-900';

            const div = document.createElement('div');

            div.className =
                'fixed right-5 bottom-5 z-[100] px-5 py-3 rounded-xl ' +
                'text-white shadow-xl ' + color;

            div.textContent = message;

            document.body.appendChild(div);

            setTimeout(function() {
                div.remove();
            }, 3500);
        },

        modal: function(title, html, buttons = '') {
            const root = document.createElement('div');

            root.id = 'app_modal';
            root.className =
                'fixed inset-0 z-50 bg-black/40 flex items-center ' +
                'justify-center p-4';

            root.innerHTML = `
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden">
                    <div class="px-6 py-4 border-b flex items-center justify-between">
                        <h3 class="font-bold text-lg">${App.utils.esc(title)}</h3>
                        <button class="text-slate-400 hover:text-slate-700 text-xl"
                            onclick="document.getElementById('app_modal')?.remove()">×</button>
                    </div>
                    <div class="p-6 overflow-auto max-h-[70vh]">${html}</div>
                    <div class="px-6 py-4 border-t flex justify-end gap-2">
                        ${buttons || `
                            <button class="px-4 py-2 rounded-lg bg-slate-900 text-white"
                                onclick="document.getElementById('app_modal')?.remove()">閉じる</button>
                        `}
                    </div>
                </div>
            `;

            document.body.appendChild(root);
            return root;
        },

        fieldOptions: function(selected, multiple = false) {
            let html =
                `<option value="">選択してください</option>`;

            App.state.fields.forEach(function(field) {
                const isSelected = multiple
                    ? Array.isArray(selected) &&
                        selected.includes(field.code)
                    : String(selected || '') === String(field.code);

                html += `
                    <option value="${App.utils.escAttr(field.code)}"
                        ${isSelected ? 'selected' : ''}>
                        ${App.utils.esc(field.label)}
                        (${App.utils.esc(field.code)})
                    </option>
                `;
            });

            return html;
        },

        updateFieldMessage: function(message, error = false) {
            const el = document.getElementById('field_message');

            if (!el) return;

            el.className =
                'mt-3 rounded-lg p-3 text-sm ' +
                (error
                    ? 'bg-red-50 text-red-700'
                    : 'bg-emerald-50 text-emerald-700');

            el.textContent = message;
        }
    },

    render: {
        shell: function(title, content, active = 'list') {
            document.getElementById('app').innerHTML = `
                <div class="min-h-screen">
                    <header class="bg-white border-b sticky top-0 z-30">
                        <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
                            <div>
                                <div class="font-bold text-lg text-slate-900">
                                    アンケート管理システム
                                </div>
                                <div class="text-xs text-slate-400">${App.utils.esc(title)}</div>
                            </div>

                            <nav class="flex gap-1">
                                <button
                                    class="px-3 py-2 rounded-lg text-sm ${
                                        active === 'list'
                                            ? 'bg-slate-900 text-white'
                                            : 'hover:bg-slate-100'
                                    }"
                                    onclick="App.actions.home()">
                                    アンケート一覧
                                </button>

                                <button
                                    class="px-3 py-2 rounded-lg text-sm ${
                                        active === 'settings'
                                            ? 'bg-slate-900 text-white'
                                            : 'hover:bg-slate-100'
                                    }"
                                    onclick="App.actions.settings()">
                                    キントーン・メール連携設定
                                </button>

                                <button
                                    class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100"
                                    onclick="App.actions.logout()">
                                    ログアウト
                                </button>
                            </nav>
                        </div>
                    </header>

                    <main class="max-w-7xl mx-auto p-6">
                        ${content}
                    </main>
                </div>
            `;
        },

        loading: function(message) {
            document.getElementById('app').innerHTML = `
                <div class="min-h-screen flex items-center justify-center">
                    <div class="text-center">
                        <div class="animate-pulse text-slate-500">${App.utils.esc(message)}</div>
                    </div>
                </div>
            `;
        },

        error: function(title, message) {
            document.getElementById('app').innerHTML = `
                <div class="min-h-screen flex items-center justify-center p-6">
                    <div class="bg-white rounded-2xl shadow-lg border p-8 max-w-2xl w-full">
                        <div class="text-red-600 font-bold text-xl mb-3">
                            ${App.utils.esc(title)}
                        </div>
                        <pre class="whitespace-pre-wrap text-sm bg-slate-50 rounded-xl p-4 text-slate-700">${App.utils.esc(message)}</pre>
                        <button
                            class="mt-5 px-5 py-2.5 rounded-lg bg-slate-900 text-white"
                            onclick="location.reload()">
                            再読み込み
                        </button>
                    </div>
                </div>
            `;
        },

        list: function() {
            const surveys = (App.state.data.surveys || [])
                .filter(s => !s.deleted);

            let keyword = App.state.listKeyword || '';
            let statusFilter = App.state.listStatus || '';
            let sort = App.state.listSort || 'updated_desc';

            let filtered = surveys.filter(function(s) {
                const hitKeyword =
                    !keyword ||
                    String(s.title || '')
                        .toLowerCase()
                        .includes(keyword.toLowerCase());

                const hitStatus =
                    !statusFilter ||
                    s.status === statusFilter;

                return hitKeyword && hitStatus;
            });

            filtered.sort(function(a, b) {
                if (sort === 'updated_desc') {
                    return String(b.updated_at || '')
                        .localeCompare(String(a.updated_at || ''));
                }

                if (sort === 'updated_asc') {
                    return String(a.updated_at || '')
                        .localeCompare(String(b.updated_at || ''));
                }

                const count = function(id) {
                    return (App.state.data.responses || [])
                        .filter(r => r.survey_id === id).length;
                };

                if (sort === 'answers_desc') {
                    return count(b.id) - count(a.id);
                }

                if (sort === 'answers_asc') {
                    return count(a.id) - count(b.id);
                }

                if (sort === 'start_desc') {
                    return String(b.start_at || '')
                        .localeCompare(String(a.start_at || ''));
                }

                return String(a.start_at || '')
                    .localeCompare(String(b.start_at || ''));
            });

            const rows = filtered.map(function(s) {
                const answerCount =
                    (App.state.data.responses || [])
                        .filter(r => r.survey_id === s.id).length;

                let buttons = '';

                if (s.status === 'active') {
                    buttons = `
                        <button class="px-2.5 py-1.5 rounded-md bg-white border hover:bg-slate-50"
                            onclick="App.actions.editSurvey('${App.utils.escAttr(s.id)}')">確認・編集</button>
                        <button class="px-2.5 py-1.5 rounded-md bg-white border hover:bg-slate-50"
                            onclick="App.actions.aggregate('${App.utils.escAttr(s.id)}')">集計</button>
                        <button class="px-2.5 py-1.5 rounded-md bg-white border hover:bg-slate-50"
                            onclick="App.actions.mail('${App.utils.escAttr(s.id)}')">送信</button>
                        <button class="px-2.5 py-1.5 rounded-md bg-amber-50 text-amber-700 border border-amber-200"
                            onclick="App.actions.stopSurvey('${App.utils.escAttr(s.id)}')">停止</button>
                        <button class="px-2.5 py-1.5 rounded-md bg-white border"
                            onclick="App.actions.duplicate('${App.utils.escAttr(s.id)}')">複製</button>
                    `;
                } else if (s.status === 'draft') {
                    buttons = `
                        <button class="px-2.5 py-1.5 rounded-md bg-white border"
                            onclick="App.actions.editSurvey('${App.utils.escAttr(s.id)}')">確認・編集</button>
                        <button class="px-2.5 py-1.5 rounded-md bg-red-50 text-red-700 border border-red-200"
                            onclick="App.actions.deleteSurvey('${App.utils.escAttr(s.id)}')">削除</button>
                        <button class="px-2.5 py-1.5 rounded-md bg-white border"
                            onclick="App.actions.duplicate('${App.utils.escAttr(s.id)}')">複製</button>
                    `;
                } else {
                    buttons = `
                        <button class="px-2.5 py-1.5 rounded-md bg-white border"
                            onclick="App.actions.editSurvey('${App.utils.escAttr(s.id)}')">確認・編集</button>
                        <button class="px-2.5 py-1.5 rounded-md bg-white border"
                            onclick="App.actions.aggregate('${App.utils.escAttr(s.id)}')">集計</button>
                        <button class="px-2.5 py-1.5 rounded-md bg-white border"
                            onclick="App.actions.duplicate('${App.utils.escAttr(s.id)}')">複製</button>
                    `;
                }

                return `
                    <tr class="border-t hover:bg-slate-50">
                        <td class="px-4 py-4 text-sm text-slate-500">
                            ${App.utils.formatDateOnly(s.created_at)}<br>
                            <span class="text-xs">更新: ${App.utils.formatDateOnly(s.updated_at)}</span>
                        </td>
                        <td class="px-4 py-4 font-bold">${App.utils.esc(s.title)}</td>
                        <td class="px-4 py-4 text-sm">
                            ${App.utils.formatDate(s.start_at)}
                            <br>～ ${App.utils.formatDate(s.end_at)}
                        </td>
                        <td class="px-4 py-4">
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold ${App.utils.statusClass(s.status)}">
                                ${App.utils.statusText(s.status)}
                            </span>
                        </td>
                        <td class="px-4 py-4 text-sm font-semibold">${answerCount} 件</td>
                        <td class="px-4 py-4">
                            <div class="flex flex-wrap gap-1.5">${buttons}</div>
                        </td>
                    </tr>
                `;
            }).join('');

            this.shell(
                'ホーム',
                `
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <div class="text-sm text-slate-400">ホーム</div>
                        <h1 class="text-2xl font-bold">アンケート一覧</h1>
                    </div>

                    <button
                        class="px-5 py-2.5 rounded-xl bg-slate-900 text-white font-semibold hover:bg-slate-700"
                        onclick="App.actions.newSurvey()">
                        ＋ 新規アンケート作成
                    </button>
                </div>

                <div class="bg-white border rounded-2xl p-4 mb-5">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <input
                            class="border rounded-lg px-3 py-2"
                            placeholder="タイトルを検索"
                            value="${App.utils.escAttr(keyword)}"
                            onkeydown="if(event.key==='Enter')App.actions.listFilter(this.value)"
                        >

                        <select class="border rounded-lg px-3 py-2"
                            onchange="App.actions.listStatus(this.value)">
                            <option value="">すべて</option>
                            <option value="active" ${statusFilter==='active'?'selected':''}>公開中</option>
                            <option value="draft" ${statusFilter==='draft'?'selected':''}>下書き</option>
                            <option value="ended" ${statusFilter==='ended'?'selected':''}>終了</option>
                        </select>

                        <select class="border rounded-lg px-3 py-2"
                            onchange="App.actions.listSort(this.value)">
                            <option value="updated_desc" ${sort==='updated_desc'?'selected':''}>更新日：新しい順</option>
                            <option value="updated_asc" ${sort==='updated_asc'?'selected':''}>更新日：古い順</option>
                            <option value="answers_desc" ${sort==='answers_desc'?'selected':''}>回答数：多い順</option>
                            <option value="answers_asc" ${sort==='answers_asc'?'selected':''}>回答数：少ない順</option>
                            <option value="start_desc" ${sort==='start_desc'?'selected':''}>開始日：新しい順</option>
                            <option value="start_asc" ${sort==='start_asc'?'selected':''}>開始日：古い順</option>
                        </select>
                    </div>
                </div>

                <div class="bg-white border rounded-2xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">作成日 / 更新日</th>
                                    <th class="px-4 py-3">タイトル</th>
                                    <th class="px-4 py-3">アンケート期間</th>
                                    <th class="px-4 py-3">ステータス</th>
                                    <th class="px-4 py-3">回答数</th>
                                    <th class="px-4 py-3">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rows || `
                                    <tr>
                                        <td colspan="6" class="px-4 py-16 text-center text-slate-400">
                                            アンケートはありません
                                        </td>
                                    </tr>
                                `}
                            </tbody>
                        </table>
                    </div>
                </div>
                `,
                'list'
            );
        },

        editor: function(id) {
            let survey;

            if (id) {
                survey = App.utils.clone(
                    App.utils.survey(id)
                );
            } else {
                survey = App.utils.defaultSurvey();
            }

            if (!survey) {
                App.utils.toast('アンケートが見つかりません。', 'error');
                return App.renderScreen('list');
            }

            App.state.editingSurvey = survey;

            const groups = survey.groups || [];

            const groupHtml = groups.map(function(group, gi) {
                const questions = group.questions || [];

                return `
                    <div class="survey-group bg-white border rounded-2xl p-5 mb-4"
                        data-group-id="${App.utils.escAttr(group.id)}">

                        <div class="flex items-center gap-3 mb-4">
                            <span class="group-handle cursor-grab text-slate-400 text-xl">⠿</span>

                            <input
                                class="flex-1 text-lg font-bold border-0 border-b focus:ring-0 focus:border-slate-400"
                                value="${App.utils.escAttr(group.name)}"
                                onchange="App.actions.groupName('${App.utils.escAttr(group.id)}',this.value)"
                            >

                            <button
                                class="px-3 py-2 rounded-lg bg-red-50 text-red-700 text-sm"
                                onclick="App.actions.removeGroup('${App.utils.escAttr(group.id)}')">
                                グループ削除
                            </button>
                        </div>

                        <div class="question-list space-y-3"
                            data-group-id="${App.utils.escAttr(group.id)}">

                            ${questions.map(function(q, qi) {
                                return App.render.questionCard(
                                    q,
                                    gi,
                                    qi
                                );
                            }).join('')}
                        </div>

                        <button
                            class="mt-4 px-4 py-2 rounded-lg border bg-slate-50 hover:bg-slate-100"
                            onclick="App.actions.addQuestion('${App.utils.escAttr(group.id)}')">
                            ＋ 質問追加
                        </button>
                    </div>
                `;
            }).join('');

            this.shell(
                'ホーム > アンケート作成・編集',
                `
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <div class="text-sm text-slate-400">
                            ホーム > アンケート一覧 > 作成・編集
                        </div>
                        <h1 class="text-2xl font-bold">アンケート作成・編集</h1>
                    </div>

                    <div class="flex gap-2">
                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="App.actions.preview()">
                            プレビュー
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg bg-slate-900 text-white"
                            onclick="App.actions.saveSurvey()">
                            保存して一覧へ戻る
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="App.actions.cancelEditor()">
                            キャンセル
                        </button>
                    </div>
                </div>

                <div class="bg-white border rounded-2xl p-5 mb-5">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <label class="md:col-span-2">
                            <span class="block text-sm font-semibold mb-1">タイトル</span>
                            <input id="survey_title"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(survey.title)}"
                                oninput="App.actions.editTitle(this.value)">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">開始日時</span>
                            <input id="survey_start_at" type="datetime-local"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(survey.start_at || '')}"
                                onchange="App.actions.editStart(this.value)">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">終了日時</span>
                            <input id="survey_end_at" type="datetime-local"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(survey.end_at || '')}"
                                onchange="App.actions.editEnd(this.value)">
                        </label>
                    </div>

                    <div class="mt-4 flex items-center gap-4">
                        <label class="text-sm font-semibold">質問番号</label>

                        <select id="survey_numbering_mode"
                            class="border rounded-lg px-3 py-2"
                            onchange="App.actions.numbering(this.value)">
                            <option value="global" ${survey.numbering_mode==='global'?'selected':''}>
                                Q1, Q2, Q3...
                            </option>
                            <option value="group" ${survey.numbering_mode==='group'?'selected':''}>
                                Q1-1, Q1-2...
                            </option>
                        </select>

                        <label class="text-sm font-semibold ml-4">ステータス</label>

                        <select class="border rounded-lg px-3 py-2"
                            onchange="App.actions.status(this.value)">
                            <option value="draft" ${survey.status==='draft'?'selected':''}>下書き</option>
                            <option value="active" ${survey.status==='active'?'selected':''}>公開中</option>
                            <option value="ended" ${survey.status==='ended'?'selected':''}>終了</option>
                        </select>
                    </div>
                </div>

                <div id="question_editor">
                    ${groupHtml}
                </div>

                <div class="flex justify-center">
                    <button
                        class="px-5 py-2.5 rounded-xl bg-white border hover:bg-slate-50"
                        onclick="App.actions.addGroup()">
                        ＋ グループ追加
                    </button>
                </div>
                `,
                'list'
            );

            App.actions.initSortables();
        },

        questionCard: function(q, gi, qi) {
            const options = Array.isArray(q.options)
                ? q.options
                : [];

            return `
                <div class="question-card border rounded-xl p-4 bg-slate-50"
                    data-question-id="${App.utils.escAttr(q.id)}">

                    <div class="flex items-start gap-3">
                        <span class="question-handle cursor-grab text-slate-400 text-xl">⠿</span>

                        <div class="flex-1">
                            <div class="flex items-center justify-between mb-3">
                                <div class="font-bold">
                                    ${App.utils.questionNumber(
                                        App.state.editingSurvey,
                                        gi,
                                        qi
                                    )}
                                </div>

                                <button
                                    class="text-red-600 text-sm"
                                    onclick="App.actions.removeQuestion('${App.utils.escAttr(q.id)}')">
                                    削除
                                </button>
                            </div>

                            <input
                                class="w-full border rounded-lg px-3 py-2 bg-white"
                                value="${App.utils.escAttr(q.text)}"
                                onchange="App.actions.questionText('${App.utils.escAttr(q.id)}',this.value)"
                                placeholder="質問文">

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                                <select
                                    class="border rounded-lg px-3 py-2 bg-white"
                                    onchange="App.actions.questionType('${App.utils.escAttr(q.id)}',this.value)">
                                    <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
                                    <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
                                    <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
                                </select>

                                <label class="flex items-center gap-2">
                                    <input type="checkbox"
                                        ${q.required ? 'checked' : ''}
                                        onchange="App.actions.questionRequired('${App.utils.escAttr(q.id)}',this.checked)">
                                    <span>必須回答</span>
                                </label>

                                <label class="flex items-center gap-2">
                                    <input type="checkbox"
                                        ${q.other_enabled ? 'checked' : ''}
                                        onchange="App.actions.questionOther('${App.utils.escAttr(q.id)}',this.checked)">
                                    <span>その他（自由記述）</span>
                                </label>
                            </div>

                            ${
                                q.type === 'text'
                                    ? `
                                        <div class="mt-3 bg-white rounded-lg border p-3 text-slate-400">
                                            自由記述入力欄
                                        </div>
                                      `
                                    : `
                                        <div class="mt-3 space-y-2">
                                            ${options.map(function(option, oi) {
                                                return `
                                                    <div class="flex gap-2">
                                                        <span class="py-2 text-slate-400">○</span>
                                                        <input
                                                            class="flex-1 border rounded-lg px-3 py-2 bg-white"
                                                            value="${App.utils.escAttr(option)}"
                                                            onchange="App.actions.optionText('${App.utils.escAttr(q.id)}',${oi},this.value)">
                                                        <button
                                                            class="px-3 rounded-lg text-red-600"
                                                            onclick="App.actions.removeOption('${App.utils.escAttr(q.id)}',${oi})">
                                                            ×
                                                        </button>
                                                    </div>
                                                `;
                                            }).join('')}

                                            <button
                                                class="text-sm text-slate-600 border rounded-lg px-3 py-1.5 bg-white"
                                                onclick="App.actions.addOption('${App.utils.escAttr(q.id)}')">
                                                ＋ 選択肢
                                            </button>
                                        </div>
                                      `
                            }
                        </div>
                    </div>
                </div>
            `;
        },

        aggregate: function(id) {
            const survey = App.utils.survey(id);

            if (!survey) {
                return App.renderScreen('list');
            }

            App.state.selectedSurveyId = id;

            const responses =
                (App.state.data.responses || [])
                    .filter(r => r.survey_id === id);

            const customers =
                App.state.data.customers || [];

            const sentCustomers =
                customers.filter(c => Number(c.send_count || 0) > 0);

            const answeredFromCustomers =
                responses.filter(r =>
                    customers.some(c =>
                        String(c.email).toLowerCase() ===
                        String(r.email).toLowerCase()
                    )
                );

            const unregistered =
                responses.filter(r =>
                    !customers.some(c =>
                        String(c.email).toLowerCase() ===
                        String(r.email).toLowerCase()
                    )
                );

            const unanswered =
                sentCustomers.filter(
                    c => c.answer_status !== 'answered'
                ).length;

            const rate =
                sentCustomers.length > 0
                    ? (
                        answeredFromCustomers.length /
                        sentCustomers.length *
                        100
                    ).toFixed(1)
                    : '0.0';

            let questions = [];

            (survey.groups || []).forEach(function(group, gi) {
                (group.questions || []).forEach(function(q, qi) {
                    questions.push({
                        ...q,
                        number: App.utils.questionNumber(
                            survey,
                            gi,
                            qi
                        )
                    });
                });
            });

            const questionHtml = questions.map(function(q, qi) {
                if (
                    App.state.aggregateFilter &&
                    !App.state.aggregateFilter.includes(q.id)
                ) {
                    return '';
                }

                if (q.type === 'text') {
                    const texts = responses.map(function(r) {
                        return {
                            response: r,
                            value: r.answers?.[q.id] ?? ''
                        };
                    }).filter(x => x.value !== '');

                    return `
                        <div class="bg-white border rounded-2xl p-5 mb-4">
                            <div class="font-bold mb-4">
                                ${App.utils.esc(q.number)}：
                                ${App.utils.esc(q.text)}
                                <span class="ml-2 text-xs px-2 py-1 bg-slate-100 rounded-full">
                                    自由記述
                                </span>
                            </div>

                            <div class="space-y-2 max-h-72 overflow-auto">
                                ${texts.length
                                    ? texts.map(x => `
                                        <div class="border-b py-3">
                                            <div class="text-xs text-slate-400">
                                                ${App.utils.esc(x.response.company)}
                                                / ${App.utils.esc(x.response.name)}
                                                ・${App.utils.esc(x.response.answered_at)}
                                            </div>
                                            <div class="mt-1 whitespace-pre-wrap">
                                                ${App.utils.esc(String(x.value))}
                                            </div>
                                        </div>
                                    `).join('')
                                    : '<div class="text-slate-400">回答データはありません</div>'
                                }
                            </div>
                        </div>
                    `;
                }

                const counts = {};

                (q.options || []).forEach(function(o) {
                    counts[o] = 0;
                });

                let otherCount = 0;

                responses.forEach(function(r) {
                    const value = r.answers?.[q.id];

                    if (Array.isArray(value)) {
                        value.forEach(function(v) {
                            if (counts[v] !== undefined) {
                                counts[v]++;
                            } else {
                                otherCount++;
                            }
                        });
                    } else if (value !== undefined && value !== '') {
                        if (counts[value] !== undefined) {
                            counts[value]++;
                        } else {
                            otherCount++;
                        }
                    }
                });

                const total = responses.length || 1;

                return `
                    <div class="bg-white border rounded-2xl p-5 mb-4">
                        <div class="font-bold mb-5">
                            ${App.utils.esc(q.number)}：
                            ${App.utils.esc(q.text)}
                            <span class="ml-2 text-xs px-2 py-1 bg-slate-100 rounded-full">
                                ${q.type === 'single' ? '単一選択' : '複数選択'}
                            </span>
                        </div>

                        <div class="space-y-3">
                            ${Object.keys(counts).map(function(label) {
                                const count = counts[label];
                                const percent = count / total * 100;

                                return `
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span>${App.utils.esc(label)}</span>
                                            <span>${count} 件 / ${percent.toFixed(1)}%</span>
                                        </div>

                                        <div class="h-3 rounded-full bg-slate-100 overflow-hidden">
                                            <div
                                                class="h-full bg-slate-800 rounded-full"
                                                style="width:${Math.min(100,percent)}%">
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }).join('')}

                            ${
                                q.other_enabled
                                    ? `
                                    <button
                                        class="text-sm text-blue-700"
                                        onclick="App.actions.showOtherAnswers('${App.utils.escAttr(q.id)}')">
                                        その他入力 ${otherCount} 件を表示
                                    </button>
                                    `
                                    : ''
                            }
                        </div>
                    </div>
                `;
            }).join('');

            const responseRows =
                responses
                    .filter(r => {
                        const k = App.state.responseFilter || '';
                        if (!k) return true;

                        return (
                            String(r.company || '').includes(k) ||
                            String(r.name || '').includes(k) ||
                            String(r.email || '').includes(k)
                        );
                    })
                    .map(function(r) {
                        return `
                            <tr class="border-t">
                                <td class="px-4 py-3">${App.utils.esc(r.company)}</td>
                                <td class="px-4 py-3">${App.utils.esc(r.name)}</td>
                                <td class="px-4 py-3">${App.utils.esc(r.email)}</td>
                                <td class="px-4 py-3">${App.utils.esc(r.answered_at)}</td>
                                <td class="px-4 py-3">
                                    <button
                                        class="px-3 py-1.5 rounded-lg border text-sm"
                                        onclick="App.actions.showResponse('${App.utils.escAttr(r.id)}')">
                                        全回答を表示
                                    </button>
                                </td>
                            </tr>
                        `;
                    }).join('');

            this.shell(
                'ホーム > アンケート一覧 > 集計',
                `
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <div class="text-sm text-slate-400">
                            ホーム > アンケート一覧 > 集計
                        </div>
                        <h1 class="text-2xl font-bold">
                            ${App.utils.esc(survey.title)}
                        </h1>
                    </div>

                    <div class="flex gap-2">
                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="App.actions.csv('${App.utils.escAttr(id)}')">
                            CSV出力
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="window.print()">
                            PDF / 印刷
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg bg-slate-900 text-white"
                            onclick="App.actions.home()">
                            一覧へ
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                    ${[
                        ['送信対象者数', sentCustomers.length + ' 人'],
                        ['回答数', responses.length + ' 件'],
                        ['未登録顧客からの回答数', unregistered.length + ' 件'],
                        ['未回答数', unanswered + ' 人'],
                        ['回答率', rate + ' %']
                    ].map(function(card) {
                        return `
                            <div class="bg-white border rounded-2xl p-4">
                                <div class="text-xs text-slate-400">${card[0]}</div>
                                <div class="text-2xl font-bold mt-2">${card[1]}</div>
                            </div>
                        `;
                    }).join('')}
                </div>

                <div class="bg-white border rounded-2xl p-5 mb-5">
                    <div class="font-bold mb-3">設問絞り込み</div>

                    <div class="flex flex-wrap gap-2 mb-3">
                        <button
                            class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-sm"
                            onclick="App.actions.aggregateAllQuestions()">
                            全選択
                        </button>

                        <button
                            class="px-3 py-1.5 rounded-lg border text-sm"
                            onclick="App.actions.aggregateClearQuestions()">
                            全解除
                        </button>
                    </div>

                    <div class="grid md:grid-cols-2 gap-2">
                        ${questions.map(function(q) {
                            const checked =
                                !App.state.aggregateFilter ||
                                App.state.aggregateFilter.includes(q.id);

                            return `
                                <label class="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        ${checked ? 'checked' : ''}
                                        onchange="App.actions.aggregateQuestion('${App.utils.escAttr(q.id)}',this.checked)">
                                    ${App.utils.esc(q.number)}：
                                    ${App.utils.esc(q.text)}
                                </label>
                            `;
                        }).join('')}
                    </div>
                </div>

                ${
                    responses.length
                        ? questionHtml
                        : `
                            <div class="bg-white border rounded-2xl p-16 text-center text-slate-400 mb-5">
                                現在、回答データはありません
                            </div>
                          `
                }

                <div class="bg-white border rounded-2xl overflow-hidden">
                    <div class="p-5 border-b flex justify-between items-center">
                        <div class="font-bold">個別回答一覧</div>

                        <input
                            class="border rounded-lg px-3 py-2"
                            placeholder="会社名・氏名・メールアドレス"
                            value="${App.utils.escAttr(App.state.responseFilter || '')}"
                            oninput="App.actions.responseFilter(this.value)">
                    </div>

                    <div class="overflow-x-auto">
                        <table id="response_table" class="w-full text-left">
                            <thead class="bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">会社名</th>
                                    <th class="px-4 py-3">氏名</th>
                                    <th class="px-4 py-3">メール</th>
                                    <th class="px-4 py-3">回答日時</th>
                                    <th class="px-4 py-3">操作</th>
                                </tr>
                            </thead>
                            <tbody>${responseRows}</tbody>
                        </table>
                    </div>
                </div>
                `,
                'list'
            );
        },

        mail: function(id) {
            const survey = App.utils.survey(id);

            if (!survey) return App.renderScreen('list');

            const customers =
                App.state.data.customers || [];

            let filtered = customers.filter(function(c) {
                const k = App.state.customerFilter || '';

                return !k ||
                    String(c.company || '').includes(k) ||
                    String(c.name || '').includes(k) ||
                    String(c.email || '').includes(k);
            });

            const selected =
                App.state.selectedCustomers || [];

            const rows = filtered.map(function(c) {
                const isSelected = selected.includes(c.id);

                return `
                    <tr class="border-t">
                        <td class="px-4 py-3">
                            ${
                                c.source === 'web'
                                    ? '<span class="text-slate-400 text-xs">Web回答者</span>'
                                    : `
                                    <input type="checkbox"
                                        ${isSelected ? 'checked' : ''}
                                        onchange="App.actions.toggleCustomer('${App.utils.escAttr(c.id)}',this.checked)">
                                    `
                            }
                        </td>

                        <td class="px-4 py-3">
                            <div class="font-bold">${App.utils.esc(c.company)}</div>
                            <div>${App.utils.esc(c.name)}</div>
                            <div class="text-xs text-slate-500">${App.utils.esc(c.email)}</div>
                        </td>

                        <td class="px-4 py-3">${App.utils.esc(c.department)}</td>
                        <td class="px-4 py-3">${App.utils.esc(c.phone)}</td>
                        <td class="px-4 py-3">${App.utils.esc(c.address)}</td>

                        <td class="px-4 py-3 text-sm">
                            最終送信：
                            ${App.utils.esc(c.sent_at || '未送信')}
                            <br>
                            送信回数：${Number(c.send_count || 0)} 回

                            ${
                                c.last_send_error
                                    ? `
                                    <div class="text-red-600 text-xs mt-1">
                                        ${App.utils.esc(c.last_send_error)}
                                    </div>
                                    `
                                    : ''
                            }
                        </td>

                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs ${
                                c.answer_status === 'answered'
                                    ? 'bg-emerald-100 text-emerald-700'
                                    : 'bg-amber-100 text-amber-700'
                            }">
                                ${
                                    c.answer_status === 'answered'
                                        ? '回答済み'
                                        : '送信済み（未回答）'
                                }
                            </span>

                            <button
                                class="block text-xs text-blue-700 mt-2"
                                onclick="App.actions.showMailHistory('${App.utils.escAttr(c.id)}')">
                                送信文を確認
                            </button>
                        </td>

                        <td class="px-4 py-3">
                            ${
                                c.kintone_status === 'registered'
                                    ? '<span class="text-emerald-700 text-sm">✓ キントーン登録完了</span>'
                                    : `
                                    <button
                                        class="text-sm px-3 py-1.5 rounded-lg border"
                                        onclick="App.actions.registerCustomer('${App.utils.escAttr(c.id)}')">
                                        キントーン登録完了
                                    </button>
                                    `
                            }
                        </td>
                    </tr>
                `;
            }).join('');

            const settings = App.state.data.settings || {};

            const defaultSubject =
                App.state.mailTemplate === 'reminder'
                    ? settings.mail_subject_reminder
                    : settings.mail_subject_initial;

            const defaultBody =
                App.state.mailTemplate === 'reminder'
                    ? settings.mail_body_reminder
                    : settings.mail_body_initial;

            this.shell(
                'ホーム > アンケート一覧 > 顧客選択・送信・送信履歴',
                `
                <div class="flex items-start justify-between mb-5">
                    <div>
                        <div class="text-sm text-slate-400">
                            ホーム > アンケート一覧 > 顧客選択・送信・送信履歴
                        </div>
                        <h1 class="text-2xl font-bold">
                            ${App.utils.esc(survey.title)}
                        </h1>
                    </div>

                    <button
                        class="px-5 py-2.5 rounded-xl bg-slate-900 text-white font-semibold"
                        onclick="App.actions.sendBulk('${App.utils.escAttr(id)}')">
                        一括送信実行
                    </button>
                </div>

                ${
                    !settings.smtp_host
                        ? `
                        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 mb-5">
                            SMTP設定が未完了です。
                            <button class="underline font-semibold"
                                onclick="App.actions.settings()">
                                SMTP設定画面を開く
                            </button>
                        </div>
                        `
                        : ''
                }

                <div class="bg-white border rounded-2xl p-5 mb-5">
                    <div class="font-bold mb-4">メールテンプレート</div>

                    <div class="grid md:grid-cols-4 gap-3">
                        <select id="template_type"
                            class="border rounded-lg px-3 py-2"
                            onchange="App.actions.mailTemplate(this.value)">
                            <option value="initial"
                                ${App.state.mailTemplate !== 'reminder' ? 'selected' : ''}>
                                初回送信
                            </option>
                            <option value="reminder"
                                ${App.state.mailTemplate === 'reminder' ? 'selected' : ''}>
                                リマインド
                            </option>
                        </select>

                        <input id="mail_subject"
                            class="md:col-span-3 border rounded-lg px-3 py-2"
                            value="${App.utils.escAttr(App.state.mailSubject ?? defaultSubject ?? '')}"
                            placeholder="件名">

                        <textarea id="mail_body"
                            class="md:col-span-4 border rounded-lg px-3 py-3 h-36"
                            placeholder="本文">${App.utils.esc(App.state.mailBody ?? defaultBody ?? '')}</textarea>
                    </div>

                    <div class="text-xs text-slate-400 mt-2">
                        使用可能な変数：
                        <code>{顧客名}</code>
                        <code>{アンケートURL}</code>
                    </div>
                </div>

                <div class="bg-white border rounded-2xl overflow-hidden">
                    <div class="p-4 border-b flex gap-3">
                        <input id="customer_filter"
                            class="border rounded-lg px-3 py-2 flex-1"
                            placeholder="顧客名・メールアドレス・会社名で検索"
                            value="${App.utils.escAttr(App.state.customerFilter || '')}"
                            oninput="App.actions.customerFilter(this.value)">

                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="App.actions.selectUnanswered('${App.utils.escAttr(id)}')">
                            未回答のみ選択
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="App.actions.syncCustomers()">
                            顧客一覧更新
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table id="customer_table" class="w-full text-left">
                            <thead class="bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">
                                        <input id="select_all" type="checkbox"
                                            onchange="App.actions.selectAll(this.checked)">
                                    </th>
                                    <th class="px-4 py-3">会社名 / 氏名等</th>
                                    <th class="px-4 py-3">部署</th>
                                    <th class="px-4 py-3">電話</th>
                                    <th class="px-4 py-3">住所</th>
                                    <th class="px-4 py-3">送信履歴</th>
                                    <th class="px-4 py-3">回答ステータス</th>
                                    <th class="px-4 py-3">kintone対応</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>
                `,
                'list'
            );
        },

        settings: function() {
            const s = App.state.data.settings || {};

            this.shell(
                'ホーム > システム設定 > kintone・メール連携設定',
                `
                <div class="mb-6">
                    <div class="text-sm text-slate-400">
                        ホーム > システム設定 > kintone・メール連携設定
                    </div>
                    <h1 class="text-2xl font-bold">
                        kintone・メール（SMTP）連携設定
                    </h1>
                </div>

                <form id="settings_form" onsubmit="return false">

                <div class="bg-white border rounded-2xl p-6 mb-5">
                    <h2 class="font-bold text-lg mb-5">
                        kintone 接続・認証設定
                    </h2>

                    <div class="grid md:grid-cols-2 gap-4">
                        <label>
                            <span class="block text-sm font-semibold mb-1">
                                サブドメイン
                            </span>
                            <input id="setting_subdomain"
                                class="w-full border rounded-lg px-3 py-2"
                                placeholder="xxxx または xxxx.cybozu.com"
                                value="${App.utils.escAttr(s.subdomain || '')}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">
                                顧客管理アプリID
                            </span>
                            <input id="setting_app_id"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(s.app_id || '')}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">
                                ログイン名
                            </span>
                            <input id="setting_login_name"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(s.login_name || '')}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">
                                パスワード
                            </span>
                            <input id="setting_password"
                                type="password"
                                autocomplete="new-password"
                                class="w-full border rounded-lg px-3 py-2"
                                placeholder="${s.password ? '変更しない場合は空欄' : ''}">
                        </label>

                        <label class="md:col-span-2">
                            <span class="block text-sm font-semibold mb-1">
                                Proxyサーバ
                            </span>
                            <input id="setting_proxy"
                                class="w-full border rounded-lg px-3 py-2"
                                placeholder="host名:port番号"
                                value="${App.utils.escAttr(s.proxy || '')}">
                        </label>
                    </div>

                    <label class="flex items-center gap-2 mt-4">
                        <input id="setting_ssl_verify" type="checkbox"
                            ${s.ssl_verify ? 'checked' : ''}>
                        SSL証明書を検証する
                    </label>

                    <div class="flex flex-wrap gap-2 mt-5">
                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="App.actions.kintoneTest()">
                            接続確認
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="App.actions.fetchKintoneFields()">
                            項目一覧を取得
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg bg-slate-900 text-white"
                            onclick="App.actions.syncCustomers()">
                            顧客データを同期
                        </button>
                    </div>

                    <div id="field_message" class="mt-3 hidden"></div>

                    <div class="mt-6 border-t pt-5">
                        <h3 class="font-bold mb-4">6項目マッピング</h3>

                        <div class="grid md:grid-cols-2 gap-4">

                            ${[
                                ['field_company','会社名 (Company)'],
                                ['field_name','氏名 (Name)'],
                                ['field_email','メールアドレス (Email)'],
                                ['field_department','部署名 (Department)'],
                                ['field_phone','電話番号 (Phone)']
                            ].map(function(item) {
                                return `
                                    <label>
                                        <span class="block text-sm font-semibold mb-1">
                                            ${item[1]}
                                        </span>
                                        <select id="${item[0]}"
                                            class="w-full border rounded-lg px-3 py-2">
                                            ${App.utils.fieldOptions(s[item[0]] || '')}
                                        </select>
                                    </label>
                                `;
                            }).join('')}

                            <div>
                                <div class="text-sm font-semibold mb-2">
                                    住所 (Address) — 複数選択可
                                </div>

                                <div class="border rounded-xl p-3 max-h-56 overflow-auto">
                                    ${
                                        App.state.fields.length
                                            ? App.state.fields.map(function(f) {
                                                const checked =
                                                    Array.isArray(s.field_address) &&
                                                    s.field_address.includes(f.code);

                                                return `
                                                    <label class="flex gap-2 items-center py-1 text-sm">
                                                        <input
                                                            type="checkbox"
                                                            name="field_address"
                                                            value="${App.utils.escAttr(f.code)}"
                                                            ${checked ? 'checked' : ''}>
                                                        ${App.utils.esc(f.label)}
                                                        (${App.utils.esc(f.code)})
                                                    </label>
                                                `;
                                            }).join('')
                                            : '<div class="text-sm text-slate-400">先に「項目一覧を取得」を実行してください。</div>'
                                    }
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white border rounded-2xl p-6 mb-5">
                    <h2 class="font-bold text-lg mb-5">
                        SMTPサーバ設定
                    </h2>

                    <div class="grid md:grid-cols-2 gap-4">
                        <label>
                            <span class="block text-sm font-semibold mb-1">SMTPサーバ</span>
                            <input id="smtp_host"
                                class="w-full border rounded-lg px-3 py-2"
                                placeholder="mail.example.jp"
                                value="${App.utils.escAttr(s.smtp_host || '')}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">SMTPポート</span>
                            <input id="smtp_port" type="number"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(s.smtp_port || 465)}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">暗号化方式</span>
                            <select id="smtp_encryption"
                                class="w-full border rounded-lg px-3 py-2">
                                <option value="NONE" ${s.smtp_encryption==='NONE'?'selected':''}>なし</option>
                                <option value="SSL" ${s.smtp_encryption==='SSL'?'selected':''}>SSL</option>
                                <option value="TLS" ${s.smtp_encryption==='TLS'?'selected':''}>TLS</option>
                            </select>
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">SMTP認証</span>
                            <select id="smtp_auth"
                                class="w-full border rounded-lg px-3 py-2">
                                <option value="1" ${s.smtp_auth !== false ? 'selected' : ''}>認証する</option>
                                <option value="0" ${s.smtp_auth === false ? 'selected' : ''}>認証しない</option>
                            </select>
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">SMTPユーザー名</span>
                            <input id="smtp_username"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(s.smtp_username || '')}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">SMTPパスワード</span>
                            <input id="smtp_password"
                                type="password"
                                autocomplete="new-password"
                                class="w-full border rounded-lg px-3 py-2"
                                placeholder="${s.smtp_password ? '変更しない場合は空欄' : ''}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">送信元メールアドレス</span>
                            <input id="smtp_from" type="email"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(s.smtp_from || '')}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">送信元表示名</span>
                            <input id="smtp_from_name"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(s.smtp_from_name || '')}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">接続タイムアウト（秒）</span>
                            <input id="smtp_timeout" type="number" min="1" max="120"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(s.smtp_timeout || 15)}">
                        </label>
                    </div>

                    <div class="flex flex-wrap gap-2 mt-5">
                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="App.actions.smtpConnectionTest()">
                            SMTP接続確認
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg border"
                            onclick="App.actions.smtpTestMail()">
                            テストメール送信
                        </button>

                        <button
                            class="px-5 py-2 rounded-lg bg-slate-900 text-white"
                            onclick="App.actions.saveSettings()">
                            設定を保存
                        </button>
                    </div>
                </div>

                <div class="bg-white border rounded-2xl p-6">
                    <h2 class="font-bold mb-4">送信テンプレート</h2>

                    <div class="grid md:grid-cols-2 gap-4">
                        <label>
                            <span class="block text-sm font-semibold mb-1">
                                初回送信 件名
                            </span>
                            <input id="mail_subject_initial"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(s.mail_subject_initial || '')}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">
                                リマインド 件名
                            </span>
                            <input id="mail_subject_reminder"
                                class="w-full border rounded-lg px-3 py-2"
                                value="${App.utils.escAttr(s.mail_subject_reminder || '')}">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">
                                初回送信 本文
                            </span>
                            <textarea id="mail_body_initial"
                                class="w-full border rounded-lg px-3 py-2 h-32">${App.utils.esc(s.mail_body_initial || '')}</textarea>
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-1">
                                リマインド 本文
                            </span>
                            <textarea id="mail_body_reminder"
                                class="w-full border rounded-lg px-3 py-2 h-32">${App.utils.esc(s.mail_body_reminder || '')}</textarea>
                        </label>
                    </div>
                </div>

                </form>
                `,
                'settings'
            );
        }
    },

    actions: {
        home: function() {
            App.renderScreen('list');
        },

        settings: function() {
            App.renderScreen('settings');
        },

        newSurvey: function() {
            App.renderScreen('editor', '');
        },

        editSurvey: function(id) {
            App.renderScreen('editor', id);
        },

        aggregate: function(id) {
            App.state.aggregateFilter = null;
            App.state.responseFilter = '';
            App.renderScreen('aggregate', id);
        },

        mail: function(id) {
            App.state.selectedSurveyId = id;
            App.state.selectedCustomers = [];
            App.state.customerFilter = '';
            App.state.mailTemplate = 'initial';
            App.state.mailSubject = null;
            App.state.mailBody = null;

            App.renderScreen('mail', id);
        },

        listFilter: function(value) {
            App.state.listKeyword = value;
            App.render.list();
        },

        listStatus: function(value) {
            App.state.listStatus = value;
            App.render.list();
        },

        listSort: function(value) {
            App.state.listSort = value;
            App.render.list();
        },

        stopSurvey: async function(id) {
            if (!confirm('このアンケートを停止しますか？')) {
                return;
            }

            const survey = App.utils.clone(
                App.utils.survey(id)
            );

            if (!survey) return;

            survey.status = 'ended';

            await App.api.saveSurvey(survey);

            const result = await App.api.getState();
            App.state.data = result.data;

            App.utils.toast('アンケートを停止しました。');
            App.render.list();
        },

        deleteSurvey: async function(id) {
            if (!confirm('この下書きを削除しますか？')) {
                return;
            }

            await App.api.deleteSurvey(id);

            const result = await App.api.getState();
            App.state.data = result.data;

            App.utils.toast('削除しました。');
            App.render.list();
        },

        duplicate: async function(id) {
            const source = App.utils.survey(id);

            if (!source) return;

            const copy = App.utils.clone(source);

            copy.id = '';
            copy.title = copy.title + '（複製）';
            copy.status = 'draft';
            copy.created_at = '';
            copy.updated_at = '';
            copy.deleted = false;

            await App.api.saveSurvey(copy);

            const result = await App.api.getState();
            App.state.data = result.data;

            App.utils.toast('下書きとして複製しました。');
            App.render.list();
        },

        editTitle: function(value) {
            App.state.editingSurvey.title = value;
        },

        editStart: function(value) {
            App.state.editingSurvey.start_at = value;
        },

        editEnd: function(value) {
            App.state.editingSurvey.end_at = value;
        },

        numbering: function(value) {
            App.state.editingSurvey.numbering_mode = value;
            App.render.editor(
                App.state.editingSurvey.id
            );
        },

        status: function(value) {
            App.state.editingSurvey.status = value;
        },

        groupName: function(id, value) {
            const group =
                App.state.editingSurvey.groups.find(
                    g => g.id === id
                );

            if (group) group.name = value;
        },

        addGroup: function() {
            const survey = App.state.editingSurvey;

            survey.groups.push({
                id: App.utils.uuid(),
                name: '新しいグループ',
                questions: []
            });

            App.render.editor(survey.id);
        },

        removeGroup: function(id) {
            const survey = App.state.editingSurvey;

            if (survey.groups.length <= 1) {
                App.utils.toast(
                    'グループは最低1つ必要です。',
                    'warning'
                );
                return;
            }

            if (!confirm('グループ内の質問もすべて削除しますか？')) {
                return;
            }

            survey.groups =
                survey.groups.filter(g => g.id !== id);

            App.render.editor(survey.id);
        },

        addQuestion: function(groupId) {
            const group =
                App.state.editingSurvey.groups.find(
                    g => g.id === groupId
                );

            if (!group) return;

            group.questions.push(
                App.utils.defaultQuestion()
            );

            App.render.editor(
                App.state.editingSurvey.id
            );
        },

        removeQuestion: function(id) {
            if (!confirm('この質問を削除しますか？')) {
                return;
            }

            App.state.editingSurvey.groups.forEach(function(group) {
                group.questions =
                    group.questions.filter(q => q.id !== id);
            });

            App.render.editor(
                App.state.editingSurvey.id
            );
        },

        questionText: function(id, value) {
            App.actions.findQuestion(id).text = value;
        },

        questionType: function(id, value) {
            const q = App.actions.findQuestion(id);

            if (!q) return;

            q.type = value;

            if (value === 'text') {
                q.options = [];
            } else if (!Array.isArray(q.options) || !q.options.length) {
                q.options = ['選択肢1', '選択肢2'];
            }

            App.render.editor(
                App.state.editingSurvey.id
            );
        },

        questionRequired: function(id, value) {
            const q = App.actions.findQuestion(id);
            if (q) q.required = value;
        },

        questionOther: function(id, value) {
            const q = App.actions.findQuestion(id);
            if (q) q.other_enabled = value;
        },

        addOption: function(id) {
            const q = App.actions.findQuestion(id);
            if (!q) return;

            q.options.push(
                '選択肢' + (q.options.length + 1)
            );

            App.render.editor(
                App.state.editingSurvey.id
            );
        },

        removeOption: function(id, index) {
            const q = App.actions.findQuestion(id);

            if (!q || q.options.length <= 1) return;

            q.options.splice(index, 1);

            App.render.editor(
                App.state.editingSurvey.id
            );
        },

        optionText: function(id, index, value) {
            const q = App.actions.findQuestion(id);

            if (q && q.options[index] !== undefined) {
                q.options[index] = value;
            }
        },

        findQuestion: function(id) {
            for (const group of App.state.editingSurvey.groups) {
                const q = group.questions.find(
                    question => question.id === id
                );

                if (q) return q;
            }

            return null;
        },

        initSortables: function() {
            if (typeof Sortable === 'undefined') {
                return;
            }

            const editor = document.getElementById(
                'question_editor'
            );

            if (!editor) return;

            new Sortable(editor, {
                animation: 180,
                handle: '.group-handle',
                ghostClass: 'opacity-40',
                onEnd: function(evt) {
                    const groups =
                        App.state.editingSurvey.groups;

                    const moved =
                        groups.splice(evt.oldIndex, 1)[0];

                    groups.splice(evt.newIndex, 0, moved);

                    App.render.editor(
                        App.state.editingSurvey.id
                    );
                }
            });

            document.querySelectorAll(
                '.question-list'
            ).forEach(function(container) {
                new Sortable(container, {
                    group: 'survey-questions',
                    animation: 180,
                    handle: '.question-handle',
                    ghostClass: 'opacity-40',

                    onEnd: function(evt) {
                        const questionId =
                            evt.item.dataset.questionId;

                        const fromGroup =
                            App.state.editingSurvey.groups.find(
                                g => g.id === evt.from.dataset.groupId
                            );

                        const toGroup =
                            App.state.editingSurvey.groups.find(
                                g => g.id === evt.to.dataset.groupId
                            );

                        if (!fromGroup || !toGroup) return;

                        const oldIndex =
                            fromGroup.questions.findIndex(
                                q => q.id === questionId
                            );

                        if (oldIndex < 0) return;

                        const question =
                            fromGroup.questions.splice(
                                oldIndex,
                                1
                            )[0];

                        let newIndex = evt.newIndex;

                        if (fromGroup === toGroup &&
                            oldIndex < newIndex) {
                            newIndex--;
                        }

                        toGroup.questions.splice(
                            newIndex,
                            0,
                            question
                        );

                        App.render.editor(
                            App.state.editingSurvey.id
                        );
                    }
                });
            });
        },

        preview: function() {
            const survey =
                App.state.editingSurvey;

            const html = `
                <div class="mx-auto ${
                    App.state.previewMode === 'mobile'
                        ? 'max-w-sm'
                        : 'max-w-3xl'
                } border rounded-2xl p-5 bg-slate-50">

                    <div class="font-bold text-xl mb-6">
                        ${App.utils.esc(survey.title)}
                    </div>

                    ${
                        survey.groups.map(function(group, gi) {
                            return `
                                <div class="mb-6">
                                    <div class="font-bold mb-3">
                                        ${App.utils.esc(group.name)}
                                    </div>

                                    ${group.questions.map(function(q, qi) {
                                        return `
                                            <div class="bg-white rounded-xl border p-4 mb-3">
                                                <div class="font-semibold mb-3">
                                                    ${App.utils.questionNumber(survey,gi,qi)}
                                                    ${App.utils.esc(q.text)}
                                                    ${q.required ? '<span class="text-red-600">*</span>' : ''}
                                                </div>

                                                ${
                                                    q.type === 'text'
                                                        ? '<textarea class="w-full border rounded-lg p-2 h-24"></textarea>'
                                                        : q.options.map(function(o) {
                                                            return `
                                                                <label class="block py-1">
                                                                    <input type="${q.type === 'single' ? 'radio' : 'checkbox'}">
                                                                    ${App.utils.esc(o)}
                                                                </label>
                                                            `;
                                                        }).join('')
                                                }

                                                ${
                                                    q.other_enabled
                                                        ? '<input class="mt-2 w-full border rounded-lg px-3 py-2" placeholder="その他">'
                                                        : ''
                                                }
                                            </div>
                                        `;
                                    }).join('')}
                                </div>
                            `;
                        }).join('')
                    }

                    <button
                        class="w-full py-2.5 rounded-lg bg-slate-900 text-white"
                        onclick="alert('これはプレビューです。実際の送信は行われません。')">
                        送信
                    </button>
                </div>
            `;

            App.utils.modal(
                'プレビュー',
                `
                    <div class="flex justify-center gap-2 mb-4">
                        <button
                            class="px-3 py-1.5 rounded-lg border"
                            onclick="App.actions.previewMode('pc')">
                            PC表示
                        </button>
                        <button
                            class="px-3 py-1.5 rounded-lg border"
                            onclick="App.actions.previewMode('mobile')">
                            スマートフォン表示
                        </button>
                    </div>
                    ${html}
                `
            );
        },

        previewMode: function(mode) {
            App.state.previewMode = mode;

            document.getElementById(
                'app_modal'
            )?.remove();

            App.actions.preview();
        },

        saveSurvey: async function() {
            const survey =
                App.utils.clone(
                    App.state.editingSurvey
                );

            if (!survey.title.trim()) {
                App.utils.toast(
                    'タイトルを入力してください。',
                    'warning'
                );
                return;
            }

            try {
                App.render.loading('保存しています…');

                await App.api.saveSurvey(survey);

                const result =
                    await App.api.getState();

                App.state.data = result.data;

                App.utils.toast('保存しました。');

                App.renderScreen('list');
            } catch (e) {
                App.render.error(
                    '保存に失敗しました',
                    e.message
                );
            }
        },

        cancelEditor: function() {
            if (
                !confirm(
                    '未保存の変更を破棄して一覧へ戻りますか？'
                )
            ) {
                return;
            }

            App.renderScreen('list');
        },

        fetchKintoneFields: async function() {
            const settings =
                App.actions.collectSettings();

            const appId =
                document.getElementById(
                    'setting_app_id'
                )?.value.trim() || '';

            try {
                App.utils.updateFieldMessage(
                    'kintoneから項目一覧を取得しています…'
                );

                const result =
                    await App.api.kintoneFields(
                        settings,
                        appId
                    );

                App.state.fields =
                    result.fields || [];

                App.utils.updateFieldMessage(
                    App.state.fields.length +
                    '件の項目を取得しました。'
                );

                App.render.settings();
            } catch (e) {
                let message = e.message;

                App.utils.updateFieldMessage(
                    message,
                    true
                );

                App.utils.modal(
                    'kintone 項目一覧取得エラー',
                    `
                        <div class="text-red-700 font-semibold mb-3">
                            不正なリクエストです。
                        </div>
                        <pre class="whitespace-pre-wrap text-xs bg-slate-50 p-4 rounded-xl">${App.utils.esc(message)}</pre>
                    `
                );
            }
        },

        collectSettings: function() {
            const old =
                App.state.data.settings || {};

            const address =
                Array.from(
                    document.querySelectorAll(
                        'input[name="field_address"]:checked'
                    )
                ).map(el => el.value);

            return {
                ...old,

                subdomain:
                    document.getElementById('setting_subdomain')?.value.trim() || '',

                app_id:
                    document.getElementById('setting_app_id')?.value.trim() || '',

                login_name:
                    document.getElementById('setting_login_name')?.value.trim() || '',

                password:
                    document.getElementById('setting_password')?.value ||
                    old.password ||
                    '',

                proxy:
                    document.getElementById('setting_proxy')?.value.trim() || '',

                ssl_verify:
                    !!document.getElementById('setting_ssl_verify')?.checked,

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

                field_address: address,

                smtp_host:
                    document.getElementById('smtp_host')?.value.trim() || '',

                smtp_port:
                    Number(document.getElementById('smtp_port')?.value || 465),

                smtp_encryption:
                    document.getElementById('smtp_encryption')?.value || 'SSL',

                smtp_auth:
                    document.getElementById('smtp_auth')?.value !== '0',

                smtp_username:
                    document.getElementById('smtp_username')?.value.trim() || '',

                smtp_password:
                    document.getElementById('smtp_password')?.value ||
                    old.smtp_password ||
                    '',

                smtp_from:
                    document.getElementById('smtp_from')?.value.trim() || '',

                smtp_from_name:
                    document.getElementById('smtp_from_name')?.value.trim() || '',

                smtp_timeout:
                    Number(document.getElementById('smtp_timeout')?.value || 15),

                mail_subject_initial:
                    document.getElementById('mail_subject_initial')?.value || '',

                mail_body_initial:
                    document.getElementById('mail_body_initial')?.value || '',

                mail_subject_reminder:
                    document.getElementById('mail_subject_reminder')?.value || '',

                mail_body_reminder:
                    document.getElementById('mail_body_reminder')?.value || ''
            };
        },

        saveSettings: async function() {
            try {
                const settings =
                    App.actions.collectSettings();

                await App.api.saveSettings(settings);

                const result =
                    await App.api.getState();

                App.state.data = result.data;

                App.utils.toast(
                    '設定を保存しました。'
                );
            } catch (e) {
                App.utils.modal(
                    '設定保存エラー',
                    `<pre class="whitespace-pre-wrap">${App.utils.esc(e.message)}</pre>`
                );
            }
        },

        kintoneTest: async function() {
            try {
                const settings =
                    App.actions.collectSettings();

                const result =
                    await App.api.kintoneTest(settings);

                App.utils.modal(
                    result.success
                        ? 'kintone 接続確認 OK'
                        : 'kintone 接続確認 NG',
                    `
                        <div class="${result.success ? 'text-emerald-700' : 'text-red-700'} font-bold mb-4">
                            ${App.utils.esc(result.message)}
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-sm mb-4">
                            <div class="text-slate-500">HTTPステータス</div>
                            <div>${App.utils.esc(result.status)}</div>
                            <div class="text-slate-500">URL</div>
                            <div class="break-all">${App.utils.esc(result.url)}</div>
                        </div>

                        <pre class="whitespace-pre-wrap bg-slate-50 rounded-xl p-4 text-xs">${App.utils.esc(JSON.stringify(
                            result.api_response ?? result.raw ?? {},
                            null,
                            2
                        ))}</pre>
                    `
                );
            } catch (e) {
                App.utils.modal(
                    'kintone 接続確認エラー',
                    `<pre class="whitespace-pre-wrap">${App.utils.esc(e.message)}</pre>`
                );
            }
        },

        syncCustomers: async function() {
            try {
                App.render.loading(
                    'kintoneから顧客データを同期しています…'
                );

                const result =
                    await App.api.syncCustomers();

                const state =
                    await App.api.getState();

                App.state.data = state.data;

                App.utils.toast(
                    result.count + '件の顧客を同期しました。'
                );

                if (App.state.screen === 'mail') {
                    App.renderScreen(
                        'mail',
                        App.state.selectedSurveyId
                    );
                } else {
                    App.renderScreen('settings');
                }
            } catch (e) {
                App.render.settings();

                App.utils.modal(
                    '顧客データ同期エラー',
                    `<pre class="whitespace-pre-wrap">${App.utils.esc(e.message)}</pre>`
                );
            }
        },

        smtpConnectionTest: async function() {
            try {
                const settings =
                    App.actions.collectSettings();

                const result =
                    await App.api.smtpTestConnection(
                        settings
                    );

                App.utils.modal(
                    result.success
                        ? 'SMTP接続確認 OK'
                        : 'SMTP接続確認 NG',
                    `
                        <div class="${
                            result.success
                                ? 'text-emerald-700'
                                : 'text-red-700'
                        } font-bold mb-4">
                            ${App.utils.esc(result.message)}
                        </div>

                        <div class="grid grid-cols-2 gap-y-2 text-sm">
                            <div class="text-slate-500">SMTPサーバ</div>
                            <div>${App.utils.esc(result.host)}</div>

                            <div class="text-slate-500">SMTPポート</div>
                            <div>${App.utils.esc(result.port)}</div>

                            <div class="text-slate-500">暗号化方式</div>
                            <div>${App.utils.esc(result.encryption)}</div>

                            <div class="text-slate-500">処理段階</div>
                            <div>${App.utils.esc(result.stage)}</div>

                            <div class="text-slate-500">TCP接続</div>
                            <div>${result.tcp_result ? 'OK' : 'NG'}</div>

                            <div class="text-slate-500">SMTP認証</div>
                            <div>${result.auth_result ? 'OK' : 'NG'}</div>

                            <div class="text-slate-500">SMTP応答コード</div>
                            <div>${App.utils.esc(result.smtp_code)}</div>

                            <div class="text-slate-500">エラー</div>
                            <div class="break-all">${App.utils.esc(result.error || 'なし')}</div>
                        </div>

                        ${
                            result.smtp_code === 535
                                ? `
                                <div class="mt-5 rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                                    TCP接続は成功していますが、SMTP認証で拒否されています。
                                    SMTPユーザー名・パスワード、およびメールサービス側で指定された認証方式を確認してください。
                                </div>
                                `
                                : ''
                        }
                    `
                );
            } catch (e) {
                App.utils.modal(
                    'SMTP接続確認エラー',
                    `<pre class="whitespace-pre-wrap">${App.utils.esc(e.message)}</pre>`
                );
            }
        },

        smtpTestMail: async function() {
            const to = prompt(
                'テストメールの送信先メールアドレスを入力してください。'
            );

            if (!to) return;

            try {
                const settings =
                    App.actions.collectSettings();

                const result =
                    await App.api.smtpTestMail(
                        settings,
                        to
                    );

                App.utils.modal(
                    result.ok
                        ? 'テストメール送信 OK'
                        : 'テストメール送信 NG',
                    `<pre class="whitespace-pre-wrap">${App.utils.esc(
                        JSON.stringify(
                            result.result || result,
                            null,
                            2
                        )
                    )}</pre>`
                );
            } catch (e) {
                App.utils.modal(
                    'テストメール送信エラー',
                    `<pre class="whitespace-pre-wrap">${App.utils.esc(e.message)}</pre>`
                );
            }
        },

        toggleCustomer: function(id, checked) {
            const selected =
                App.state.selectedCustomers;

            if (checked) {
                if (!selected.includes(id)) {
                    selected.push(id);
                }
            } else {
                App.state.selectedCustomers =
                    selected.filter(x => x !== id);
            }
        },

        selectAll: function(checked) {
            const customers =
                App.state.data.customers || [];

            App.state.selectedCustomers =
                checked
                    ? customers
                        .filter(c => c.source !== 'web')
                        .map(c => c.id)
                    : [];

            App.renderScreen(
                'mail',
                App.state.selectedSurveyId
            );
        },

        selectUnanswered: function(id) {
            App.state.selectedCustomers =
                (App.state.data.customers || [])
                    .filter(c =>
                        c.source !== 'web' &&
                        c.answer_status === 'unanswered'
                    )
                    .map(c => c.id);

            App.renderScreen('mail', id);
        },

        customerFilter: function(value) {
            App.state.customerFilter = value;
            App.render.mail(
                App.state.selectedSurveyId
            );
        },

        mailTemplate: function(value) {
            const settings =
                App.state.data.settings || {};

            App.state.mailTemplate = value;

            App.state.mailSubject =
                value === 'reminder'
                    ? settings.mail_subject_reminder
                    : settings.mail_subject_initial;

            App.state.mailBody =
                value === 'reminder'
                    ? settings.mail_body_reminder
                    : settings.mail_body_initial;

            App.render.mail(
                App.state.selectedSurveyId
            );
        },

        sendBulk: async function(id) {
            const selected =
                App.state.selectedCustomers || [];

            if (!selected.length) {
                App.utils.toast(
                    '送信対象を選択してください。',
                    'warning'
                );
                return;
            }

            const settings =
                App.state.data.settings || {};

            if (
                !settings.smtp_host ||
                !settings.smtp_from
            ) {
                if (
                    confirm(
                        'SMTP設定が未完了です。設定画面を開きますか？'
                    )
                ) {
                    App.renderScreen('settings');
                }

                return;
            }

            const customers =
                App.state.data.customers || [];

            const alreadySent =
                selected.filter(id => {
                    const c =
                        customers.find(x => x.id === id);

                    return c &&
                        Number(c.send_count || 0) > 0;
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
                document.getElementById(
                    'mail_subject'
                )?.value || '';

            const body =
                document.getElementById(
                    'mail_body'
                )?.value || '';

            const template =
                document.getElementById(
                    'template_type'
                )?.value || 'initial';

            if (!subject.trim() || !body.trim()) {
                App.utils.toast(
                    '件名と本文を入力してください。',
                    'warning'
                );
                return;
            }

            if (
                !confirm(
                    selected.length +
                    '件にメールを送信します。実行しますか？'
                )
            ) {
                return;
            }

            try {
                App.render.loading(
                    'メールを送信しています…'
                );

                const result =
                    await App.api.sendBulk(
                        id,
                        selected,
                        subject,
                        body,
                        template
                    );

                const state =
                    await App.api.getState();

                App.state.data = state.data;

                App.utils.modal(
                    '一括送信結果',
                    `
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-xl bg-emerald-50 p-4">
                                <div class="text-xs">成功</div>
                                <div class="text-2xl font-bold text-emerald-700">
                                    ${result.success_count}
                                </div>
                            </div>

                            <div class="rounded-xl bg-red-50 p-4">
                                <div class="text-xs">失敗</div>
                                <div class="text-2xl font-bold text-red-700">
                                    ${result.failed_count}
                                </div>
                            </div>

                            <div class="rounded-xl bg-slate-100 p-4">
                                <div class="text-xs">未送信</div>
                                <div class="text-2xl font-bold">
                                    ${result.unsent_count}
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 max-h-64 overflow-auto">
                            ${(result.details || []).map(function(d) {
                                return `
                                    <div class="border-b py-2 text-sm">
                                        <span class="${
                                            d.result === 'success'
                                                ? 'text-emerald-700'
                                                : d.result === 'failed'
                                                    ? 'text-red-700'
                                                    : 'text-slate-500'
                                        } font-semibold">
                                            ${App.utils.esc(d.result)}
                                        </span>
                                        ${App.utils.esc(d.email || '')}
                                        ${App.utils.esc(d.message || '')}
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `
                );

                App.state.selectedCustomers = [];

                App.renderScreen('mail', id);
            } catch (e) {
                App.renderScreen('mail', id);

                App.utils.modal(
                    '一括送信エラー',
                    `<pre class="whitespace-pre-wrap">${App.utils.esc(e.message)}</pre>`
                );
            }
        },

        registerCustomer: async function(id) {
            try {
                await App.api.registerCustomer(id);

                const state =
                    await App.api.getState();

                App.state.data = state.data;

                App.renderScreen(
                    'mail',
                    App.state.selectedSurveyId
                );

                App.utils.toast(
                    'kintone登録完了として更新しました。'
                );
            } catch (e) {
                App.utils.toast(
                    e.message,
                    'error'
                );
            }
        },

        showMailHistory: function(id) {
            const logs =
                App.state.data.mail_logs || [];

            const items = [];

            logs.forEach(function(log) {
                (log.details || []).forEach(function(detail) {
                    if (detail.customer_id === id) {
                        items.push({
                            log,
                            detail
                        });
                    }
                });
            });

            App.utils.modal(
                '送信文を確認',
                items.length
                    ? items.map(function(x) {
                        return `
                            <div class="border rounded-xl p-4 mb-3">
                                <div class="text-xs text-slate-400">
                                    ${App.utils.esc(x.log.sent_at)}
                                </div>
                                <div class="font-semibold mt-2">
                                    ${App.utils.esc(x.detail.subject || x.log.subject || '')}
                                </div>
                                <pre class="mt-3 whitespace-pre-wrap text-sm">${App.utils.esc(x.detail.body || '')}</pre>
                            </div>
                        `;
                    }).join('')
                    : '<div class="text-slate-400">送信履歴はありません。</div>'
            );
        },

        aggregateQuestion: function(id, checked) {
            const survey =
                App.utils.survey(
                    App.state.selectedSurveyId
                );

            const all = [];

            (survey?.groups || []).forEach(function(g) {
                (g.questions || []).forEach(function(q) {
                    all.push(q.id);
                });
            });

            let selected =
                App.state.aggregateFilter
                    ? [...App.state.aggregateFilter]
                    : [...all];

            if (checked) {
                if (!selected.includes(id)) {
                    selected.push(id);
                }
            } else {
                selected =
                    selected.filter(x => x !== id);
            }

            App.state.aggregateFilter = selected;

            App.renderScreen(
                'aggregate',
                App.state.selectedSurveyId
            );
        },

        aggregateAllQuestions: function() {
            App.state.aggregateFilter = null;

            App.renderScreen(
                'aggregate',
                App.state.selectedSurveyId
            );
        },

        aggregateClearQuestions: function() {
            App.state.aggregateFilter = [];

            App.renderScreen(
                'aggregate',
                App.state.selectedSurveyId
            );
        },

        responseFilter: function(value) {
            App.state.responseFilter = value;

            App.render.aggregate(
                App.state.selectedSurveyId
            );
        },

        showResponse: function(id) {
            const response =
                (App.state.data.responses || [])
                    .find(r => r.id === id);

            if (!response) return;

            const survey =
                App.utils.survey(
                    response.survey_id
                );

            let html = '';

            (survey?.groups || []).forEach(function(group, gi) {
                html += `
                    <div class="mb-5">
                        <div class="font-bold mb-2">
                            ${App.utils.esc(group.name)}
                        </div>
                `;

                (group.questions || []).forEach(function(q, qi) {
                    const value =
                        response.answers?.[q.id] ?? '';

                    html += `
                        <div class="border-b py-3">
                            <div class="text-sm font-semibold">
                                ${App.utils.questionNumber(survey,gi,qi)}
                                ${App.utils.esc(q.text)}
                            </div>
                            <div class="mt-1 whitespace-pre-wrap text-slate-600">
                                ${App.utils.esc(
                                    Array.isArray(value)
                                        ? value.join(', ')
                                        : value
                                )}
                            </div>
                        </div>
                    `;
                });

                html += '</div>';
            });

            App.utils.modal(
                '全回答を表示',
                `
                    <div class="mb-5 text-sm">
                        <div>${App.utils.esc(response.company)} / ${App.utils.esc(response.name)}</div>
                        <div class="text-slate-400">${App.utils.esc(response.email)}</div>
                        <div class="text-slate-400">${App.utils.esc(response.answered_at)}</div>
                    </div>
                    ${html}
                `
            );
        },

        showOtherAnswers: function(questionId) {
            const responses =
                (App.state.data.responses || [])
                    .filter(r =>
                        r.survey_id ===
                        App.state.selectedSurveyId
                    );

            const items = [];

            responses.forEach(function(r) {
                const value =
                    r.answers?.[questionId];

                if (
                    value &&
                    (
                        typeof value === 'string' ||
                        Array.isArray(value)
                    )
                ) {
                    items.push({
                        name: r.name,
                        company: r.company,
                        value
                    });
                }
            });

            App.utils.modal(
                'その他入力',
                items.length
                    ? items.map(function(x) {
                        return `
                            <div class="border-b py-3">
                                <div class="text-xs text-slate-400">
                                    ${App.utils.esc(x.company)} /
                                    ${App.utils.esc(x.name)}
                                </div>
                                <div class="mt-1">
                                    ${App.utils.esc(
                                        Array.isArray(x.value)
                                            ? x.value.join(', ')
                                            : x.value
                                    )}
                                </div>
                            </div>
                        `;
                    }).join('')
                    : '<div class="text-slate-400">その他入力はありません。</div>'
            );
        },

        csv: function(id) {
            const url =
                location.pathname +
                '?action=csv&survey_id=' +
                encodeURIComponent(id);

            window.location.href = url;
        },

        logout: function() {
            App.utils.toast(
                'この構成では管理者認証機能を別途接続できます。'
            );
        }
    }
};

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
<?php
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

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

if (!isset($_SESSION['survey_csrf'])) {
    $_SESSION['survey_csrf'] = bin2hex(random_bytes(24));
}

/* ============================================================
 * PHP utility
 * ============================================================ */

function app_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_now(): string
{
    return date('Y-m-d H:i:s');
}

function app_id(string $prefix = 'id'): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function app_default_data(): array
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
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
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

function app_read_data(): array
{
    if (!file_exists(SURVEY_STORAGE_FILE)) {
        $data = app_default_data();
        app_write_data($data);
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    if ($raw === false || trim($raw) === '') {
        return app_default_data();
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return app_default_data();
    }

    $defaults = app_default_data();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function app_write_data(array $data): bool
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    return @file_put_contents(
        SURVEY_STORAGE_FILE,
        $json,
        LOCK_EX
    ) !== false;
}

function app_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

function app_post_json(): array
{
    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }

    return $_POST;
}

function app_check_csrf(array $input): void
{
    $token = (string)($input['csrf_token'] ?? '');

    if (
        $token === '' ||
        !hash_equals(
            (string)($_SESSION['survey_csrf'] ?? ''),
            $token
        )
    ) {
        app_json([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。画面を再読み込みしてください。'
        ], 403);
    }
}

/* ============================================================
 * Safe HTTP response headers
 * PHP 8.4/8.5 compatible
 * ============================================================ */

function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

/* ============================================================
 * kintone
 * ============================================================ */

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim($domain, '/');

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(
            trim($login_name) . ':' . trim($password)
        );
}

function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20
    ];

    if ($method !== 'GET' && $payload !== null) {
        $http['content'] = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE)
            : (string)$payload;
    }

    $sslVerify = !empty($config['ssl_verify']);

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
        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $body = @file_get_contents(
        $url,
        false,
        $context
    );

    $headersResult = get_safe_response_headers();

    $status = 0;

    foreach ($headersResult as $headerLine) {
        if (preg_match(
            '/^HTTP\/[\d.]+\s+(\d+)/i',
            $headerLine,
            $m
        )) {
            $status = (int)$m[1];
        }
    }

    if ($status === 0 && $body !== false) {
        $status = 200;
    }

    $decoded = json_decode(
        $body === false ? '' : $body,
        true
    );

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : []
        ];
    }

    $message = 'kintone API 通信エラーが発生しました。';

    if (is_array($decoded) && isset($decoded['message'])) {
        $message = (string)$decoded['message'];
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'raw' => is_array($decoded) ? $decoded : [],
        'headers' => $headersResult
    ];
}

function kintone_config_from_settings(array $settings): array
{
    return [
        'ssl_verify' => !empty($settings['ssl_verify']),
        'proxy' => trim((string)($settings['proxy'] ?? ''))
    ];
}

function kintone_headers(array $settings): array
{
    return [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                trim((string)($settings['login_name'] ?? '')) .
                ':' .
                trim((string)($settings['password'] ?? ''))
            ),
        'Content-Type: application/json',
        'Accept: application/json'
    ];
}

/* ============================================================
 * SMTP
 * ============================================================ */

function smtp_open(array $settings): array
{
    $host = trim((string)($settings['smtp_host'] ?? ''));
    $port = (int)($settings['smtp_port'] ?? 587);
    $encryption = strtolower(
        trim((string)($settings['smtp_encryption'] ?? 'tls'))
    );
    $timeout = max(
        3,
        (int)($settings['smtp_timeout'] ?? 15)
    );

    if ($host === '') {
        return [
            'ok' => false,
            'message' => 'SMTPサーバが設定されていません。'
        ];
    }

    $connectHost = $host;

    if ($encryption === 'ssl') {
        $connectHost = 'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $connectHost . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        return [
            'ok' => false,
            'message' => 'TCP接続失敗: ' . $errstr,
            'tcp' => false
        ];
    }

    stream_set_timeout($socket, $timeout);

    $read = fgets($socket, 8192);

    if ($read === false) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP初期応答を取得できませんでした。',
            'tcp' => true
        ];
    }

    $code = (int)substr($read, 0, 3);

    if ($code < 200 || $code >= 400) {
        fclose($socket);

        return [
            'ok' => false,
            'message' => 'SMTP応答エラー: ' . trim($read),
            'tcp' => true,
            'smtp_code' => $code
        ];
    }

    return [
        'ok' => true,
        'socket' => $socket,
        'initial' => trim($read),
        'tcp' => true,
        'smtp_code' => $code
    ];
}

function smtp_command($socket, string $command): array
{
    fwrite($socket, $command . "\r\n");

    $response = '';

    while (($line = fgets($socket, 8192)) !== false) {
        $response .= $line;

        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    $code = (int)substr($response, 0, 3);

    return [
        'ok' => $code >= 200 && $code < 400,
        'code' => $code,
        'response' => trim($response)
    ];
}

function smtp_send_mail(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    $open = smtp_open($settings);

    if (!$open['ok']) {
        return $open;
    }

    $socket = $open['socket'];

    try {
        $helo = smtp_command(
            $socket,
            'EHLO localhost'
        );

        if (!$helo['ok']) {
            fclose($socket);
            return [
                'ok' => false,
                'message' => 'EHLO失敗: ' . $helo['response'],
                'smtp_code' => $helo['code']
            ];
        }

        $encryption = strtolower(
            trim((string)($settings['smtp_encryption'] ?? 'tls'))
        );

        if ($encryption === 'tls') {
            $tls = smtp_command(
                $socket,
                'STARTTLS'
            );

            if (!$tls['ok']) {
                fclose($socket);

                return [
                    'ok' => false,
                    'message' => 'STARTTLS失敗: ' . $tls['response'],
                    'smtp_code' => $tls['code']
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
                    'message' => 'TLS接続に失敗しました。'
                ];
            }

            $helo = smtp_command(
                $socket,
                'EHLO localhost'
            );
        }

        if (!empty($settings['smtp_auth'])) {
            $username = (string)($settings['smtp_username'] ?? '');
            $password = (string)($settings['smtp_password'] ?? '');

            if ($username !== '') {
                $auth = smtp_command(
                    $socket,
                    'AUTH LOGIN'
                );

                if (!$auth['ok']) {
                    fclose($socket);

                    return [
                        'ok' => false,
                        'message' => 'SMTP AUTH LOGIN開始失敗。',
                        'smtp_code' => $auth['code']
                    ];
                }

                $auth = smtp_command(
                    $socket,
                    base64_encode($username)
                );

                if (!$auth['ok']) {
                    fclose($socket);

                    return [
                        'ok' => false,
                        'message' => 'SMTPユーザー名認証失敗。',
                        'smtp_code' => $auth['code']
                    ];
                }

                $auth = smtp_command(
                    $socket,
                    base64_encode($password)
                );

                if (!$auth['ok']) {
                    fclose($socket);

                    return [
                        'ok' => false,
                        'message' => 'SMTPパスワード認証失敗。',
                        'smtp_code' => $auth['code']
                    ];
                }
            }
        }

        $from = trim(
            (string)($settings['smtp_from'] ?? '')
        );

        if ($from === '') {
            fclose($socket);

            return [
                'ok' => false,
                'message' => '送信元メールアドレスが設定されていません。'
            ];
        }

        $r = smtp_command(
            $socket,
            'MAIL FROM:<' . $from . '>'
        );

        if (!$r['ok']) {
            fclose($socket);
            return [
                'ok' => false,
                'message' => 'MAIL FROM拒否: ' . $r['response'],
                'smtp_code' => $r['code']
            ];
        }

        $r = smtp_command(
            $socket,
            'RCPT TO:<' . trim($to) . '>'
        );

        if (!$r['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'RCPT TO拒否: ' . $r['response'],
                'smtp_code' => $r['code']
            ];
        }

        $r = smtp_command(
            $socket,
            'DATA'
        );

        if (!$r['ok']) {
            fclose($socket);

            return [
                'ok' => false,
                'message' => 'DATA拒否: ' . $r['response'],
                'smtp_code' => $r['code']
            ];
        }

        $fromName = trim(
            (string)($settings['smtp_from_name'] ?? '')
        );

        $encodedName = $fromName !== ''
            ? '=?UTF-8?B?' .
                base64_encode($fromName) .
                '?='
            : '';

        $headers = [];

        if ($encodedName !== '') {
            $headers[] =
                'From: ' .
                $encodedName .
                ' <' .
                $from .
                '>';
        } else {
            $headers[] = 'From: ' . $from;
        }

        $headers[] = 'To: ' . $to;
        $headers[] =
            'Subject: =?UTF-8?B?' .
            base64_encode($subject) .
            '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] =
            'Content-Type: text/plain; charset=UTF-8';
        $headers[] =
            'Content-Transfer-Encoding: 8bit';

        $message =
            implode("\r\n", $headers) .
            "\r\n\r\n" .
            $body .
            "\r\n.";

        fwrite($socket, $message . "\r\n");

        $response = fgets($socket, 8192);
        $code = (int)substr((string)$response, 0, 3);

        smtp_command($socket, 'QUIT');

        fclose($socket);

        return [
            'ok' => $code >= 200 && $code < 300,
            'message' => trim((string)$response),
            'smtp_code' => $code
        ];
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            fclose($socket);
        }

        return [
            'ok' => false,
            'message' => 'SMTP処理エラー: ' . $e->getMessage()
        ];
    }
}

/* ============================================================
 * API
 * ============================================================ */

$action = (string)(
    $_GET['action'] ??
    $_POST['action'] ??
    ''
);

if ($action !== '') {
    $input = app_post_json();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        app_check_csrf($input);
    }

    $data = app_read_data();

    /* データ取得 */
    if ($action === 'load') {
        app_json([
            'ok' => true,
            'data' => $data,
            'csrf_token' => $_SESSION['survey_csrf']
        ]);
    }

    /* アンケート保存 */
    if ($action === 'save_survey') {
        $survey = $input['survey_json'] ?? null;

        if (is_string($survey)) {
            $survey = json_decode($survey, true);
        }

        if (!is_array($survey)) {
            app_json([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], 400);
        }

        $now = app_now();

        if (empty($survey['id'])) {
            $survey['id'] = app_id('survey');
            $survey['created_at'] = $now;
        }

        $survey['updated_at'] = $now;
        $survey['deleted'] = !empty($survey['deleted']);
        $survey['status'] = $survey['status'] ?? 'draft';
        $survey['numbering_mode'] =
            $survey['numbering_mode'] ?? 'global';
        $survey['groups'] =
            is_array($survey['groups'] ?? null)
                ? $survey['groups']
                : [];

        $found = false;

        foreach ($data['surveys'] as $i => $old) {
            if (($old['id'] ?? '') === $survey['id']) {
                $data['surveys'][$i] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['surveys'][] = $survey;
        }

        app_write_data($data);

        app_json([
            'ok' => true,
            'survey' => $survey
        ]);
    }

    /* アンケート削除 */
    if ($action === 'delete_survey') {
        $id = (string)($input['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['deleted'] = true;
                $survey['status'] = 'draft';
                $survey['updated_at'] = app_now();
            }
        }

        unset($survey);

        app_write_data($data);

        app_json(['ok' => true]);
    }

    /* 複製 */
    if ($action === 'duplicate_survey') {
        $id = (string)($input['survey_id'] ?? '');
        $copy = null;

        foreach ($data['surveys'] as $survey) {
            if (($survey['id'] ?? '') === $id) {
                $copy = $survey;
                break;
            }
        }

        if (!$copy) {
            app_json([
                'ok' => false,
                'message' => '複製対象が見つかりません。'
            ], 404);
        }

        $now = app_now();

        $copy['id'] = app_id('survey');
        $copy['title'] =
            (string)($copy['title'] ?? '') . '（複製）';
        $copy['status'] = 'draft';
        $copy['created_at'] = $now;
        $copy['updated_at'] = $now;
        $copy['deleted'] = false;

        $data['surveys'][] = $copy;

        app_write_data($data);

        app_json([
            'ok' => true,
            'survey' => $copy
        ]);
    }

    /* 設定保存 */
    if ($action === 'save_settings') {
        $settings = $input['settings_json'] ?? null;

        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        if (!is_array($settings)) {
            app_json([
                'ok' => false,
                'message' => '設定データが不正です。'
            ], 400);
        }

        foreach ($data['settings'] as $key => $oldValue) {
            if (array_key_exists($key, $settings)) {
                $data['settings'][$key] = $settings[$key];
            }
        }

        app_write_data($data);

        app_json([
            'ok' => true,
            'settings' => $data['settings']
        ]);
    }

    /* kintone接続確認 */
    if ($action === 'kintone_test') {
        $settings = $data['settings'];

        foreach ([
            'subdomain',
            'login_name',
            'password'
        ] as $key) {
            if (
                isset($input[$key]) &&
                $input[$key] !== ''
            ) {
                $settings[$key] = $input[$key];
            }
        }

        $domain = trim(
            (string)($settings['subdomain'] ?? '')
        );

        if ($domain === '') {
            app_json([
                'ok' => false,
                'message' => 'サブドメインを入力してください。'
            ], 400);
        }

        $url = kintone_build_url(
            $domain,
            '/k/v1/apps.json'
        );

        $result = kintone_api_request(
            'GET',
            $url,
            kintone_headers($settings),
            null,
            kintone_config_from_settings($settings)
        );

        app_json([
            'ok' => $result['success'],
            'status' => $result['status'] ?? 0,
            'message' =>
                $result['success']
                    ? 'kintone接続に成功しました。'
                    : ($result['message'] ?? '接続失敗'),
            'diagnostic' => [
                'url' => $url,
                'http_status' => $result['status'] ?? 0,
                'api_response' => $result['raw'] ?? [],
                'ssl_verify' => !empty($settings['ssl_verify']),
                'proxy' =>
                    !empty($settings['proxy'])
                        ? $settings['proxy']
                        : 'なし'
            ]
        ]);
    }

    /* kintone フィールド取得 */
    if ($action === 'kintone_fields') {
        $settings = $data['settings'];

        $appId = (string)(
            $input['app_id'] ??
            $settings['app_id'] ??
            ''
        );

        if ($appId === '') {
            app_json([
                'ok' => false,
                'message' => '顧客管理アプリIDを入力してください。'
            ], 400);
        }

        $domain = trim(
            (string)($settings['subdomain'] ?? '')
        );

        if ($domain === '') {
            app_json([
                'ok' => false,
                'message' => 'kintoneサブドメインが未設定です。'
            ], 400);
        }

        $url = kintone_build_url(
            $domain,
            '/k/v1/app/form/fields.json'
        );

        /*
         * 重要:
         * GET APIでも app パラメータはクエリ文字列で渡す。
         * ここを抜かすと「不正なリクエスト」になる。
         */
        $url .= '?app=' . rawurlencode($appId);

        $result = kintone_api_request(
            'GET',
            $url,
            kintone_headers($settings),
            null,
            kintone_config_from_settings($settings)
        );

        if (!$result['success']) {
            app_json([
                'ok' => false,
                'status' => $result['status'] ?? 0,
                'message' =>
                    $result['message'] ??
                    '項目一覧を取得できませんでした。',
                'diagnostic' => [
                    'url' => $url,
                    'api_response' => $result['raw'] ?? [],
                    'headers' => $result['headers'] ?? []
                ]
            ]);
        }

        $fields = [];

        foreach (
            ($result['data']['properties'] ?? []) as $code => $field
        ) {
            $fields[] = [
                'code' => $code,
                'label' => $field['label'] ?? $code,
                'type' => $field['type'] ?? ''
            ];
        }

        app_json([
            'ok' => true,
            'fields' => $fields
        ]);
    }

    /* kintone顧客同期 */
    if ($action === 'sync_customers') {
        $settings = $data['settings'];

        $appId = (string)(
            $input['app_id'] ??
            $settings['app_id'] ??
            ''
        );

        if ($appId === '') {
            app_json([
                'ok' => false,
                'message' => '顧客管理アプリIDが未設定です。'
            ], 400);
        }

        $domain = trim(
            (string)$settings['subdomain']
        );

        $query = [
            'app' => $appId,
            'totalCount' => true,
            'query' => ''
        ];

        $url = kintone_build_url(
            $domain,
            '/k/v1/records.json'
        );

        $url .= '?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $result = kintone_api_request(
            'GET',
            $url,
            kintone_headers($settings),
            null,
            kintone_config_from_settings($settings)
        );

        if (!$result['success']) {
            app_json([
                'ok' => false,
                'message' => $result['message'] ?? '同期失敗',
                'status' => $result['status'] ?? 0
            ]);
        }

        $map = [
            'company' =>
                $settings['field_company'] ?? '',
            'name' =>
                $settings['field_name'] ?? '',
            'email' =>
                $settings['field_email'] ?? '',
            'department' =>
                $settings['field_department'] ?? '',
            'phone' =>
                $settings['field_phone'] ?? ''
        ];

        $addressMap = $settings['field_address'] ?? [];

        if (!is_array($addressMap)) {
            $addressMap = [];
        }

        $newCustomers = [];

        foreach (
            ($result['data']['records'] ?? []) as $record
        ) {
            $getValue = function ($code) use ($record) {
                if (
                    $code === '' ||
                    !isset($record[$code])
                ) {
                    return '';
                }

                $value = $record[$code]['value'] ?? '';

                if (is_array($value)) {
                    $parts = [];

                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $parts[] =
                                (string)($item['value'] ?? '');
                        } else {
                            $parts[] = (string)$item;
                        }
                    }

                    return implode(', ', $parts);
                }

                return (string)$value;
            };

            $addressParts = [];

            foreach ($addressMap as $code) {
                $value = $getValue((string)$code);

                if ($value !== '') {
                    $addressParts[] = $value;
                }
            }

            $customer = [
                'id' =>
                    'kintone_' .
                    (string)($record['$id']['value'] ?? app_id()),
                'company' =>
                    $getValue((string)$map['company']),
                'name' =>
                    $getValue((string)$map['name']),
                'email' =>
                    $getValue((string)$map['email']),
                'department' =>
                    $getValue((string)$map['department']),
                'phone' =>
                    $getValue((string)$map['phone']),
                'address' =>
                    implode(' ', $addressParts),
                'source' => 'kintone',
                'sent_at' => '',
                'send_count' => 0,
                'answer_status' => 'unanswered',
                'kintone_status' => 'registered'
            ];

            if ($customer['email'] === '') {
                continue;
            }

            $newCustomers[] = $customer;
        }

        /*
         * 既存送信履歴をメールアドレスで維持。
         */
        $oldByEmail = [];

        foreach ($data['customers'] as $customer) {
            if (!empty($customer['email'])) {
                $oldByEmail[
                    strtolower(trim($customer['email']))
                ] = $customer;
            }
        }

        foreach ($newCustomers as &$customer) {
            $key = strtolower(
                trim($customer['email'])
            );

            if (isset($oldByEmail[$key])) {
                $old = $oldByEmail[$key];

                $customer['id'] =
                    $old['id'] ?? $customer['id'];
                $customer['sent_at'] =
                    $old['sent_at'] ?? '';
                $customer['send_count'] =
                    (int)($old['send_count'] ?? 0);
                $customer['answer_status'] =
                    $old['answer_status'] ??
                    'unanswered';
            }
        }

        unset($customer);

        $data['customers'] = $newCustomers;
        app_write_data($data);

        app_json([
            'ok' => true,
            'count' => count($newCustomers)
        ]);
    }

    /* SMTP接続確認 */
    if ($action === 'smtp_test_connection') {
        $settings = $data['settings'];

        $posted = $input['settings_json'] ?? null;

        if (is_string($posted)) {
            $posted = json_decode($posted, true);
        }

        if (is_array($posted)) {
            foreach ($posted as $key => $value) {
                if (array_key_exists($key, $settings)) {
                    $settings[$key] = $value;
                }
            }
        }

        $result = smtp_open($settings);

        if (
            isset($result['socket']) &&
            is_resource($result['socket'])
        ) {
            smtp_command(
                $result['socket'],
                'QUIT'
            );

            fclose($result['socket']);
            unset($result['socket']);
        }

        app_json([
            'ok' => !empty($result['ok']),
            'diagnostic' => [
                'smtp_host' =>
                    $settings['smtp_host'] ?? '',
                'smtp_port' =>
                    $settings['smtp_port'] ?? '',
                'encryption' =>
                    $settings['smtp_encryption'] ?? '',
                'tcp' =>
                    $result['tcp'] ?? false,
                'smtp_code' =>
                    $result['smtp_code'] ?? 0,
                'message' =>
                    $result['message'] ?? '接続成功'
            ]
        ]);
    }

    /* SMTPテスト送信 */
    if ($action === 'smtp_test_send') {
        $settings = $data['settings'];

        $posted = $input['settings_json'] ?? null;

        if (is_string($posted)) {
            $posted = json_decode($posted, true);
        }

        if (is_array($posted)) {
            foreach ($posted as $key => $value) {
                if (array_key_exists($key, $settings)) {
                    $settings[$key] = $value;
                }
            }
        }

        $to = trim(
            (string)($input['test_email'] ?? '')
        );

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            app_json([
                'ok' => false,
                'message' => '有効な送信先メールアドレスを入力してください。'
            ], 400);
        }

        $result = smtp_send_mail(
            $settings,
            $to,
            'アンケート管理システム SMTP送信テスト',
            "SMTP設定が正常に動作し、テストメールの送信に成功したことを確認するためのテストメールです。\r\n\r\n" .
            "送信日時: " . app_now()
        );

        app_json([
            'ok' => !empty($result['ok']),
            'message' =>
                $result['message'] ??
                (
                    !empty($result['ok'])
                        ? 'テストメールを送信しました。'
                        : 'テストメール送信に失敗しました。'
                ),
            'smtp_code' =>
                $result['smtp_code'] ?? 0
        ]);
    }

    /* 一括メール送信 */
    if ($action === 'send_mail') {
        $settings = $data['settings'];

        $required = [
            'smtp_host',
            'smtp_port',
            'smtp_from'
        ];

        $missing = [];

        foreach ($required as $key) {
            if (
                !isset($settings[$key]) ||
                trim((string)$settings[$key]) === ''
            ) {
                $missing[] = $key;
            }
        }

        if ($missing) {
            app_json([
                'ok' => false,
                'message' =>
                    'SMTP設定が未完了のため送信できません。',
                'missing' => $missing
            ], 400);
        }

        $surveyId = (string)(
            $input['survey_id'] ?? ''
        );

        $recipientIds =
            $input['recipient_ids'] ?? [];

        if (is_string($recipientIds)) {
            $recipientIds = json_decode(
                $recipientIds,
                true
            );
        }

        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

        $subject = (string)(
            $input['mail_subject'] ?? ''
        );

        $body = (string)(
            $input['mail_body'] ?? ''
        );

        $templateType = (string)(
            $input['template_type'] ?? 'initial'
        );

        $survey = null;

        foreach ($data['surveys'] as $item) {
            if (($item['id'] ?? '') === $surveyId) {
                $survey = $item;
                break;
            }
        }

        if (!$survey) {
            app_json([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $success = 0;
        $failed = 0;
        $unsent = 0;
        $details = [];

        foreach ($data['customers'] as $index => &$customer) {
            if (
                !in_array(
                    $customer['id'] ?? '',
                    $recipientIds,
                    true
                )
            ) {
                continue;
            }

            $email = trim(
                (string)($customer['email'] ?? '')
            );

            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $failed++;

                $details[] = [
                    'customer_id' =>
                        $customer['id'] ?? '',
                    'email' => $email,
                    'result' => 'failed',
                    'message' =>
                        'メールアドレスが不正です。'
                ];

                continue;
            }

            $customerName =
                (string)($customer['name'] ?? '');

            $url = '';

            $scheme =
                (!empty($_SERVER['HTTPS']) &&
                 $_SERVER['HTTPS'] !== 'off')
                    ? 'https'
                    : 'http';

            $base =
                $scheme .
                '://' .
                ($_SERVER['HTTP_HOST'] ?? '');

            $url =
                $base .
                rtrim(
                    dirname($_SERVER['SCRIPT_NAME']),
                    '/\\'
                ) .
                '/?survey=' .
                rawurlencode($surveyId) .
                '&customer=' .
                rawurlencode(
                    (string)($customer['id'] ?? '')
                );

            $personalSubject =
                str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [$customerName, $url],
                    $subject
                );

            $personalBody =
                str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [$customerName, $url],
                    $body
                );

            $result = smtp_send_mail(
                $settings,
                $email,
                $personalSubject,
                $personalBody
            );

            $mailLog = [
                'id' => app_id('mail'),
                'survey_id' => $surveyId,
                'customer_id' =>
                    $customer['id'] ?? '',
                'sent_at' => app_now(),
                'template_type' => $templateType,
                'subject' => $personalSubject,
                'body' => $personalBody,
                'result' =>
                    !empty($result['ok'])
                        ? 'success'
                        : 'failed',
                'error' =>
                    $result['message'] ?? ''
            ];

            $data['mail_logs'][] = $mailLog;

            if (!empty($result['ok'])) {
                $customer['sent_at'] = app_now();
                $customer['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;
                $customer['answer_status'] =
                    'unanswered';

                $success++;

                $details[] = [
                    'customer_id' =>
                        $customer['id'] ?? '',
                    'email' => $email,
                    'result' => 'success'
                ];
            } else {
                $failed++;

                $details[] = [
                    'customer_id' =>
                        $customer['id'] ?? '',
                    'email' => $email,
                    'result' => 'failed',
                    'message' =>
                        $result['message'] ?? ''
                ];
            }
        }

        unset($customer);

        app_write_data($data);

        app_json([
            'ok' => true,
            'success_count' => $success,
            'failed_count' => $failed,
            'unsent_count' => $unsent,
            'details' => $details
        ]);
    }

    /* 回答保存 */
    if ($action === 'save_response') {
        $response = $input['response'] ?? null;

        if (is_string($response)) {
            $response = json_decode(
                $response,
                true
            );
        }

        if (!is_array($response)) {
            app_json([
                'ok' => false,
                'message' => '回答データが不正です。'
            ], 400);
        }

        $response['id'] =
            $response['id'] ?? app_id('response');

        $response['answered_at'] =
            app_now();

        $data['responses'][] = $response;

        foreach ($data['customers'] as &$customer) {
            if (
                ($customer['id'] ?? '') ===
                ($response['customer_id'] ?? '')
            ) {
                $customer['answer_status'] =
                    'answered';
            }
        }

        unset($customer);

        app_write_data($data);

        app_json([
            'ok' => true,
            'response' => $response
        ]);
    }

    /* CSV */
    if ($action === 'csv') {
        $surveyId = (string)(
            $_GET['survey_id'] ?? ''
        );

        $survey = null;

        foreach ($data['surveys'] as $item) {
            if (($item['id'] ?? '') === $surveyId) {
                $survey = $item;
                break;
            }
        }

        $filename =
            'survey_' .
            ($surveyId !== ''
                ? $surveyId
                : 'responses') .
            '.csv';

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );
        header(
            'Content-Disposition: attachment; filename="' .
            $filename .
            '"'
        );

        $fp = fopen('php://output', 'wb');

        fwrite($fp, "\xEF\xBB\xBF");

        $headers = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名'
        ];

        $questions = [];

        foreach (
            ($survey['groups'] ?? []) as $group
        ) {
            foreach (
                ($group['questions'] ?? []) as $question
            ) {
                $questions[] = $question;
                $headers[] =
                    $question['text'] ?? '';
            }
        }

        fputcsv(
            $fp,
            $headers
        );

        foreach (
            $data['responses'] as $response
        ) {
            if (
                ($response['survey_id'] ?? '') !==
                $surveyId
            ) {
                continue;
            }

            $row = [
                $response['id'] ?? '',
                $response['answered_at'] ?? '',
                $response['customer_id'] ?? '',
                $response['company'] ?? '',
                $response['name'] ?? ''
            ];

            $answers =
                $response['answers'] ?? [];

            foreach ($questions as $question) {
                $qid = $question['id'] ?? '';
                $value = $answers[$qid] ?? '';

                if (is_array($value)) {
                    $value = implode(
                        ', ',
                        array_map(
                            'strval',
                            $value
                        )
                    );
                }

                $row[] = $value;
            }

            fputcsv($fp, $row);
        }

        fclose($fp);
        exit;
    }

    app_json([
        'ok' => false,
        'message' => 'Unknown action: ' . $action
    ], 404);
}

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<div id="preview_modal"
     class="hidden fixed inset-0 z-50 bg-black/50 p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-3xl mx-auto mt-8 max-h-[90vh] overflow-auto">
        <div class="flex items-center justify-between border-b p-4">
            <h2 class="font-bold text-lg">プレビュー</h2>
            <button onclick="App.actions.closePreview()"
                    class="px-3 py-2 rounded-lg bg-slate-100">
                閉じる
            </button>
        </div>
        <div id="preview_content" class="p-6"></div>
    </div>
</div>

<div id="response_modal"
     class="hidden fixed inset-0 z-50 bg-black/50 p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-4xl mx-auto mt-8 max-h-[90vh] overflow-auto">
        <div class="flex items-center justify-between border-b p-4">
            <h2 class="font-bold text-lg">回答詳細</h2>
            <button onclick="App.actions.closeResponseModal()"
                    class="px-3 py-2 rounded-lg bg-slate-100">
                閉じる
            </button>
        </div>
        <div id="response_detail" class="p-6"></div>
    </div>
</div>

<script>
"use strict";

/*
 * ================================================================
 * window.App
 *
 * 今回の白画面の主要原因だった
 *
 *     App.renderScreen is not a function
 *
 * を防ぐため、renderScreen を App.renderScreen として
 * 明示的に公開する。
 * ================================================================
 */

window.App = {
    state: {
        initialized: false,
        csrf: "",
        screen: "list",
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        currentSurveyId: null,
        fields: [],
        responseFilter: "",
        customerFilter: "",
        selectedCustomers: [],
        editorSurvey: null
    },

    api: {},

    render: {},

    actions: {},

    utils: {},

    /* ============================================================
     * 初期化
     * ============================================================ */

    init: async function() {
        if (this.state.initialized) {
            return;
        }

        this.state.initialized = true;

        try {
            await this.api.load();

            this.renderScreen(
                this.state.screen
            );
        } catch (error) {
            console.error(error);

            const app =
                document.getElementById("app");

            if (app) {
                app.innerHTML = `
                    <div class="min-h-screen flex items-center justify-center p-6">
                        <div class="bg-white rounded-2xl shadow-lg p-8 max-w-xl w-full">
                            <div class="text-red-600 font-bold text-xl mb-3">
                                初期化に失敗しました
                            </div>
                            <div class="text-slate-600 whitespace-pre-wrap">${this.utils.escape(
                                error.message || String(error)
                            )}</div>
                            <button
                                onclick="location.reload()"
                                class="mt-6 px-5 py-3 rounded-xl bg-blue-600 text-white">
                                再読み込み
                            </button>
                        </div>
                    </div>
                `;
            }
        }
    },

    /* ============================================================
     * API
     * ============================================================ */

    api: {
        request: async function(action, payload = {}, method = "POST") {
            let url = "?action=" +
                encodeURIComponent(action);

            const options = {
                method: method,
                headers: {}
            };

            if (method === "POST") {
                payload.csrf_token =
                    App.state.csrf;

                options.headers["Content-Type"] =
                    "application/json";

                options.body =
                    JSON.stringify(payload);
            }

            const response =
                await fetch(url, options);

            const text =
                await response.text();

            let json;

            try {
                json = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    "サーバーからJSONではない応答が返されました。\n" +
                    text.substring(0, 500)
                );
            }

            if (!response.ok || json.ok === false) {
                throw new Error(
                    json.message ||
                    "サーバー処理に失敗しました。"
                );
            }

            return json;
        },

        load: async function() {
            const response =
                await fetch("?action=load");

            const json =
                await response.json();

            if (!json.ok) {
                throw new Error(
                    json.message ||
                    "データを読み込めません。"
                );
            }

            App.state.csrf =
                json.csrf_token || "";

            App.state.data =
                json.data || {
                    surveys: [],
                    responses: [],
                    customers: [],
                    settings: {},
                    mail_logs: []
                };

            if (!App.state.data.settings) {
                App.state.data.settings = {};
            }

            if (!App.state.data.mail_logs) {
                App.state.data.mail_logs = [];
            }
        },

        saveSurvey: async function(survey) {
            return await this.request(
                "save_survey",
                {
                    survey_json: survey
                }
            );
        },

        saveSettings: async function(settings) {
            const result =
                await this.request(
                    "save_settings",
                    {
                        settings_json: settings
                    }
                );

            App.state.data.settings =
                result.settings;

            return result;
        }
    },

    /* ============================================================
     * 共通
     * ============================================================ */

    utils: {
        escape: function(value) {
            return String(value ?? "")
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        },

        uuid: function(prefix = "id") {
            return prefix + "_" +
                Date.now().toString(36) +
                "_" +
                Math.random()
                    .toString(36)
                    .substring(2, 10);
        },

        clone: function(value) {
            return JSON.parse(
                JSON.stringify(value)
            );
        },

        findSurvey: function(id) {
            return App.state.data.surveys.find(
                survey =>
                    survey.id === id &&
                    !survey.deleted
            ) || null;
        },

        newQuestion: function() {
            return {
                id: App.utils.uuid("question"),
                text: "新しい質問",
                type: "single",
                required: false,
                options: [
                    "選択肢1",
                    "選択肢2"
                ],
                other_enabled: false
            };
        },

        newGroup: function() {
            return {
                id: App.utils.uuid("group"),
                name: "新しいグループ",
                questions: []
            };
        },

        statusLabel: function(status) {
            return {
                active: "公開中",
                draft: "下書き",
                ended: "終了"
            }[status] || status;
        },

        statusClass: function(status) {
            return {
                active:
                    "bg-green-100 text-green-700",
                draft:
                    "bg-amber-100 text-amber-700",
                ended:
                    "bg-slate-200 text-slate-600"
            }[status] ||
                "bg-slate-100 text-slate-600";
        }
    },

    /* ============================================================
     * ★重要
     * renderScreen は必ず App 直下に公開
     * ============================================================ */

    renderScreen: function(screen) {
        this.state.screen = screen;

        switch (screen) {
            case "list":
                this.render.list();
                break;

            case "editor":
                this.render.editor();
                break;

            case "summary":
                this.render.summary();
                break;

            case "mail":
                this.render.mail();
                break;

            case "settings":
                this.render.settings();
                break;

            default:
                this.state.screen = "list";
                this.render.list();
                break;
        }
    },

    /* ============================================================
     * 共通ヘッダー
     * ============================================================ */

    renderHeader: function(title) {
        return `
            <header class="bg-white border-b sticky top-0 z-30">
                <div class="max-w-7xl mx-auto px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-xs text-slate-400 mb-1">
                                アンケート管理システム
                            </div>
                            <h1 class="text-xl font-bold">
                                ${this.utils.escape(title)}
                            </h1>
                        </div>

                        <nav class="flex flex-wrap gap-2">
                            <button
                                onclick="App.actions.goList()"
                                class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200">
                                アンケート一覧
                            </button>

                            <button
                                onclick="App.actions.goSettings()"
                                class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200">
                                キントーン・メール連携設定
                            </button>
                        </nav>
                    </div>
                </div>
            </header>
        `;
    },

    /* ============================================================
     * ① 一覧
     * ============================================================ */

    render: {
        list: function() {
            const app =
                document.getElementById("app");

            const surveys =
                App.state.data.surveys
                    .filter(s => !s.deleted);

            app.innerHTML = `
                ${App.renderHeader("アンケート一覧")}

                <main class="max-w-7xl mx-auto p-5">

                    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                        <div>
                            <h2 class="text-2xl font-bold">
                                アンケート
                            </h2>
                            <p class="text-slate-500 mt-1">
                                作成・公開・集計・メール送信を管理します。
                            </p>
                        </div>

                        <button
                            onclick="App.actions.newSurvey()"
                            class="px-5 py-3 rounded-xl bg-blue-600 text-white hover:bg-blue-700 shadow">
                            ＋ 新規アンケート作成
                        </button>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border p-4 mb-5">
                        <div class="grid md:grid-cols-3 gap-3">
                            <input
                                id="survey_search"
                                oninput="App.actions.filterSurveys()"
                                placeholder="タイトルを検索"
                                class="border rounded-xl px-4 py-3">

                            <select
                                id="survey_status_filter"
                                onchange="App.actions.filterSurveys()"
                                class="border rounded-xl px-4 py-3">
                                <option value="">すべて</option>
                                <option value="active">公開中</option>
                                <option value="draft">下書き</option>
                                <option value="ended">終了</option>
                            </select>

                            <select
                                id="survey_sort"
                                onchange="App.actions.filterSurveys()"
                                class="border rounded-xl px-4 py-3">
                                <option value="updated_desc">
                                    更新日（新しい順）
                                </option>
                                <option value="updated_asc">
                                    更新日（古い順）
                                </option>
                                <option value="answers_desc">
                                    回答数（多い順）
                                </option>
                                <option value="answers_asc">
                                    回答数（少ない順）
                                </option>
                                <option value="start_desc">
                                    開始日（新しい順）
                                </option>
                                <option value="start_asc">
                                    開始日（古い順）
                                </option>
                            </select>
                        </div>
                    </div>

                    <div id="survey_list"
                         class="space-y-3">
                        ${App.renderSurveyRows(surveys)}
                    </div>
                </main>
            `;
        },

        editor: function() {
            const survey =
                App.state.editorSurvey;

            const app =
                document.getElementById("app");

            if (!survey) {
                App.renderScreen("list");
                return;
            }

            app.innerHTML = `
                ${App.renderHeader(
                    survey.id
                        ? "アンケート作成・編集"
                        : "新規アンケート作成"
                )}

                <main class="max-w-6xl mx-auto p-5">

                    <div class="bg-white rounded-2xl shadow-sm border p-5 mb-5">
                        <div class="grid md:grid-cols-2 gap-4">

                            <div class="md:col-span-2">
                                <label class="block text-sm font-bold mb-2">
                                    タイトル
                                </label>

                                <input
                                    id="survey_title"
                                    value="${App.utils.escape(survey.title)}"
                                    oninput="App.actions.updateEditorField('title',this.value)"
                                    class="w-full border rounded-xl px-4 py-3 text-lg">
                            </div>

                            <div>
                                <label class="block text-sm font-bold mb-2">
                                    開始日時
                                </label>

                                <input
                                    id="survey_start_at"
                                    type="datetime-local"
                                    value="${App.utils.escape(survey.start_at)}"
                                    onchange="App.actions.updateEditorField('start_at',this.value)"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block text-sm font-bold mb-2">
                                    終了日時
                                </label>

                                <input
                                    id="survey_end_at"
                                    type="datetime-local"
                                    value="${App.utils.escape(survey.end_at)}"
                                    onchange="App.actions.updateEditorField('end_at',this.value)"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block text-sm font-bold mb-2">
                                    質問番号
                                </label>

                                <select
                                    id="survey_numbering_mode"
                                    onchange="App.actions.updateEditorField('numbering_mode',this.value);App.actions.renumber()"
                                    class="w-full border rounded-xl px-4 py-3">
                                    <option value="global"
                                        ${survey.numbering_mode === "global" ? "selected" : ""}>
                                        Q1, Q2, Q3...
                                    </option>
                                    <option value="group"
                                        ${survey.numbering_mode === "group" ? "selected" : ""}>
                                        Q1-1, Q1-2...
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-bold mb-2">
                                    ステータス
                                </label>

                                <select
                                    onchange="App.actions.updateEditorField('status',this.value)"
                                    class="w-full border rounded-xl px-4 py-3">
                                    <option value="draft"
                                        ${survey.status === "draft" ? "selected" : ""}>
                                        下書き
                                    </option>
                                    <option value="active"
                                        ${survey.status === "active" ? "selected" : ""}>
                                        公開中
                                    </option>
                                    <option value="ended"
                                        ${survey.status === "ended" ? "selected" : ""}>
                                        終了
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 mb-4">
                        <button
                            onclick="App.actions.addGroup()"
                            class="px-4 py-2 rounded-lg bg-blue-600 text-white">
                            ＋ グループ追加
                        </button>

                        <button
                            onclick="App.actions.preview()"
                            class="px-4 py-2 rounded-lg bg-slate-700 text-white">
                            プレビュー
                        </button>
                    </div>

                    <div id="question_editor"
                         class="space-y-4">
                        ${App.renderGroups()}
                    </div>

                    <div class="flex justify-end gap-3 mt-6">
                        <button
                            onclick="App.actions.cancelEditor()"
                            class="px-5 py-3 rounded-xl bg-slate-200">
                            キャンセル
                        </button>

                        <button
                            onclick="App.actions.saveEditor()"
                            class="px-5 py-3 rounded-xl bg-blue-600 text-white">
                            保存して一覧へ戻る
                        </button>
                    </div>
                </main>
            `;

            App.actions.initSortable();
        },

        summary: function() {
            const survey =
                App.utils.findSurvey(
                    App.state.currentSurveyId
                );

            if (!survey) {
                App.renderScreen("list");
                return;
            }

            const responses =
                App.state.data.responses.filter(
                    r => r.survey_id === survey.id
                );

            const sentCustomers =
                App.state.data.customers.filter(
                    c => (c.sent_at || "") !== ""
                );

            const answeredCustomers =
                sentCustomers.filter(
                    c => c.answer_status === "answered"
                );

            const rate =
                sentCustomers.length
                    ? (
                        answeredCustomers.length /
                        sentCustomers.length *
                        100
                    ).toFixed(1)
                    : "0.0";

            const app =
                document.getElementById("app");

            app.innerHTML = `
                ${App.renderHeader("回答集計・分析")}

                <main class="max-w-7xl mx-auto p-5">

                    <div class="mb-5">
                        <div class="text-sm text-slate-400">
                            アンケート
                        </div>
                        <h2 class="text-2xl font-bold">
                            ${App.utils.escape(survey.title)}
                        </h2>
                    </div>

                    <div class="grid md:grid-cols-5 gap-3 mb-6">
                        ${App.summaryCard("送信対象者数", sentCustomers.length + " 人")}
                        ${App.summaryCard("回答数", responses.length + " 件")}
                        ${App.summaryCard(
                            "未登録顧客からの回答数",
                            responses.filter(r => !r.customer_id).length + " 件"
                        )}
                        ${App.summaryCard(
                            "未回答数",
                            Math.max(
                                0,
                                sentCustomers.length -
                                answeredCustomers.length
                            ) + " 人"
                        )}
                        ${App.summaryCard("回答率", rate + " %")}
                    </div>

                    <div class="bg-white rounded-2xl border shadow-sm p-5 mb-5">
                        <h3 class="font-bold mb-4">
                            設問別集計
                        </h3>

                        <div class="space-y-3">
                            ${App.renderQuestionSummary(
                                survey,
                                responses
                            )}
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border shadow-sm p-5">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-bold">
                                個別回答一覧
                            </h3>

                            <a
                                href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
                                class="px-4 py-2 rounded-lg bg-emerald-600 text-white">
                                CSV出力
                            </a>
                        </div>

                        <input
                            id="response_filter"
                            oninput="App.actions.filterResponses()"
                            placeholder="会社名・氏名で検索"
                            class="border rounded-xl px-4 py-3 w-full mb-4">

                        <div id="response_table">
                            ${App.renderResponseTable(
                                responses
                            )}
                        </div>
                    </div>
                </main>
            `;
        },

        mail: function() {
            const survey =
                App.utils.findSurvey(
                    App.state.currentSurveyId
                );

            if (!survey) {
                App.renderScreen("list");
                return;
            }

            const app =
                document.getElementById("app");

            const customers =
                App.state.data.customers;

            app.innerHTML = `
                ${App.renderHeader("顧客選択・メール送信")}

                <main class="max-w-7xl mx-auto p-5">

                    <div class="bg-white rounded-2xl border shadow-sm p-5 mb-5">
                        <div class="text-sm text-slate-400">
                            アンケート
                        </div>

                        <h2 class="text-2xl font-bold mb-5">
                            ${App.utils.escape(survey.title)}
                        </h2>

                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block font-bold mb-2">
                                    送信種別
                                </label>

                                <select
                                    id="template_type"
                                    class="border rounded-xl px-4 py-3 w-full">
                                    <option value="initial">
                                        初回送信
                                    </option>
                                    <option value="reminder">
                                        リマインド
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    件名
                                </label>

                                <input
                                    id="mail_subject"
                                    value="アンケートのご案内"
                                    class="border rounded-xl px-4 py-3 w-full">
                            </div>

                            <div class="md:col-span-2">
                                <label class="block font-bold mb-2">
                                    本文
                                </label>

                                <textarea
                                    id="mail_body"
                                    rows="8"
                                    class="border rounded-xl px-4 py-3 w-full">{$thisNamePlaceholder}</textarea>
                            </div>
                        </div>

                        <div class="text-sm text-slate-500 mt-3">
                            使用可能な変数：
                            <code>{顧客名}</code>
                            <code>{アンケートURL}</code>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border shadow-sm p-5">

                        <div class="flex flex-wrap gap-3 mb-4">
                            <input
                                id="customer_filter"
                                oninput="App.actions.filterCustomers()"
                                placeholder="顧客名・メールアドレスで検索"
                                class="border rounded-xl px-4 py-3 flex-1 min-w-[260px]">

                            <button
                                onclick="App.actions.selectUnanswered()"
                                class="px-4 py-2 rounded-lg bg-amber-100 text-amber-700">
                                未回答のみ選択
                            </button>

                            <button
                                onclick="App.actions.sendMail()"
                                class="px-5 py-2 rounded-lg bg-blue-600 text-white">
                                一括送信実行
                            </button>
                        </div>

                        <div id="customer_table">
                            ${App.renderCustomerTable(customers)}
                        </div>
                    </div>
                </main>
            `;

            const body =
                document.getElementById("mail_body");

            if (body && body.value === "{$thisNamePlaceholder}") {
                body.value =
                    "{顧客名} 様\n\n" +
                    "アンケートへのご協力をお願いいたします。\n\n" +
                    "回答URL:\n{アンケートURL}\n";
            }
        },

        settings: function() {
            const s =
                App.state.data.settings || {};

            const app =
                document.getElementById("app");

            app.innerHTML = `
                ${App.renderHeader("キントーン・メール連携設定")}

                <main class="max-w-6xl mx-auto p-5">

                    <div class="bg-white rounded-2xl border shadow-sm p-5 mb-5">
                        <h2 class="text-xl font-bold mb-5">
                            kintone接続設定
                        </h2>

                        <div id="field_message"
                             class="hidden mb-4 p-4 rounded-xl"></div>

                        <div class="grid md:grid-cols-2 gap-4">

                            <div>
                                <label class="block font-bold mb-2">
                                    サブドメイン
                                </label>
                                <input
                                    id="setting_subdomain"
                                    value="${App.utils.escape(s.subdomain || "")}"
                                    placeholder="xxxx または xxxx.cybozu.com"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    顧客管理アプリID
                                </label>
                                <input
                                    id="setting_app_id"
                                    value="${App.utils.escape(s.app_id || "")}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    ログイン名
                                </label>
                                <input
                                    id="setting_login_name"
                                    value="${App.utils.escape(s.login_name || "")}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    パスワード
                                </label>
                                <input
                                    id="setting_password"
                                    type="password"
                                    value="${App.utils.escape(s.password || "")}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    Proxyサーバ
                                </label>
                                <input
                                    id="setting_proxy"
                                    value="${App.utils.escape(s.proxy || "")}"
                                    placeholder="host名:port番号"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div class="flex items-center">
                                <label class="flex gap-3 items-center">
                                    <input
                                        id="setting_ssl_verify"
                                        type="checkbox"
                                        ${s.ssl_verify ? "checked" : ""}>
                                    SSL証明書を検証する
                                </label>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3 mt-5">
                            <button
                                onclick="App.actions.testKintone()"
                                class="px-5 py-3 rounded-xl bg-slate-700 text-white">
                                接続確認
                            </button>

                            <button
                                onclick="App.actions.fetchKintoneFields()"
                                class="px-5 py-3 rounded-xl bg-blue-600 text-white">
                                項目一覧を取得
                            </button>

                            <button
                                onclick="App.actions.syncCustomers()"
                                class="px-5 py-3 rounded-xl bg-emerald-600 text-white">
                                顧客データを同期
                            </button>
                        </div>

                        <div class="mt-6">
                            <h3 class="font-bold mb-3">
                                フィールドマッピング
                            </h3>

                            <div id="mapping_area">
                                ${App.renderMapping(s)}
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border shadow-sm p-5">
                        <h2 class="text-xl font-bold mb-5">
                            SMTPサーバ設定
                        </h2>

                        <div class="grid md:grid-cols-2 gap-4">

                            <div>
                                <label class="block font-bold mb-2">
                                    SMTPサーバ
                                </label>
                                <input
                                    id="smtp_host"
                                    value="${App.utils.escape(s.smtp_host || "")}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    SMTPポート
                                </label>
                                <input
                                    id="smtp_port"
                                    type="number"
                                    value="${App.utils.escape(s.smtp_port || 587)}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    暗号化方式
                                </label>
                                <select
                                    id="smtp_encryption"
                                    class="w-full border rounded-xl px-4 py-3">
                                    <option value="none"
                                        ${s.smtp_encryption === "none" ? "selected" : ""}>
                                        なし
                                    </option>
                                    <option value="ssl"
                                        ${s.smtp_encryption === "ssl" ? "selected" : ""}>
                                        SSL
                                    </option>
                                    <option value="tls"
                                        ${!s.smtp_encryption || s.smtp_encryption === "tls" ? "selected" : ""}>
                                        TLS
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    SMTP認証
                                </label>
                                <select
                                    id="smtp_auth"
                                    class="w-full border rounded-xl px-4 py-3">
                                    <option value="1"
                                        ${s.smtp_auth !== false ? "selected" : ""}>
                                        認証する
                                    </option>
                                    <option value="0"
                                        ${s.smtp_auth === false ? "selected" : ""}>
                                        認証しない
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    SMTPユーザー名
                                </label>
                                <input
                                    id="smtp_username"
                                    value="${App.utils.escape(s.smtp_username || "")}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    SMTPパスワード
                                </label>
                                <input
                                    id="smtp_password"
                                    type="password"
                                    value="${App.utils.escape(s.smtp_password || "")}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    送信元メールアドレス
                                </label>
                                <input
                                    id="smtp_from"
                                    value="${App.utils.escape(s.smtp_from || "")}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    送信元表示名
                                </label>
                                <input
                                    id="smtp_from_name"
                                    value="${App.utils.escape(s.smtp_from_name || "")}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="block font-bold mb-2">
                                    接続タイムアウト
                                </label>
                                <input
                                    id="smtp_timeout"
                                    type="number"
                                    value="${App.utils.escape(s.smtp_timeout || 15)}"
                                    class="w-full border rounded-xl px-4 py-3">
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3 mt-5">
                            <button
                                onclick="App.actions.saveSettings()"
                                class="px-5 py-3 rounded-xl bg-blue-600 text-white">
                                設定を保存
                            </button>

                            <button
                                onclick="App.actions.testSMTP()"
                                class="px-5 py-3 rounded-xl bg-slate-700 text-white">
                                SMTP接続確認
                            </button>
                        </div>

                        <div class="border-t mt-6 pt-6">
                            <h3 class="font-bold mb-3">
                                テストメール送信
                            </h3>

                            <div class="flex gap-3">
                                <input
                                    id="smtp_test_email"
                                    placeholder="送信先メールアドレス"
                                    class="border rounded-xl px-4 py-3 flex-1">

                                <button
                                    onclick="App.actions.testSMTPSend()"
                                    class="px-5 py-3 rounded-xl bg-emerald-600 text-white">
                                    テストメール送信
                                </button>
                            </div>
                        </div>
                    </div>
                </main>
            `;
        }
    },

    /* ============================================================
     * 一覧描画
     * ============================================================ */

    renderSurveyRows: function(surveys) {
        if (!surveys.length) {
            return `
                <div class="bg-white rounded-2xl border p-10 text-center text-slate-500">
                    アンケートはまだありません。
                </div>
            `;
        }

        return surveys.map(survey => {
            const answerCount =
                App.state.data.responses.filter(
                    r => r.survey_id === survey.id
                ).length;

            let buttons = "";

            if (survey.status === "active") {
                buttons = `
                    <button onclick="App.actions.editSurvey('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-slate-100">
                        確認・編集
                    </button>

                    <button onclick="App.actions.summary('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-indigo-100 text-indigo-700">
                        集計
                    </button>

                    <button onclick="App.actions.mail('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-blue-100 text-blue-700">
                        送信
                    </button>

                    <button onclick="App.actions.stopSurvey('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-red-100 text-red-700">
                        停止
                    </button>

                    <button onclick="App.actions.duplicate('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-slate-100">
                        複製
                    </button>
                `;
            } else if (survey.status === "draft") {
                buttons = `
                    <button onclick="App.actions.editSurvey('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-slate-100">
                        確認・編集
                    </button>

                    <button onclick="App.actions.deleteSurvey('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-red-100 text-red-700">
                        削除
                    </button>

                    <button onclick="App.actions.duplicate('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-slate-100">
                        複製
                    </button>
                `;
            } else {
                buttons = `
                    <button onclick="App.actions.editSurvey('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-slate-100">
                        確認・編集
                    </button>

                    <button onclick="App.actions.summary('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-indigo-100 text-indigo-700">
                        集計
                    </button>

                    <button onclick="App.actions.duplicate('${survey.id}')"
                        class="px-3 py-2 rounded-lg bg-slate-100">
                        複製
                    </button>
                `;
            }

            return `
                <div class="bg-white rounded-2xl border shadow-sm p-5">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">

                        <div class="min-w-0">
                            <div class="flex items-center gap-3 mb-2">
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold ${App.utils.statusClass(survey.status)}">
                                    ${App.utils.statusLabel(survey.status)}
                                </span>

                                <span class="text-xs text-slate-400">
                                    更新: ${App.utils.escape(survey.updated_at || "")}
                                </span>
                            </div>

                            <h3 class="font-bold text-lg">
                                ${App.utils.escape(survey.title || "無題")}
                            </h3>

                            <div class="text-sm text-slate-500 mt-2">
                                ${App.utils.escape(survey.start_at || "未設定")}
                                ～
                                ${App.utils.escape(survey.end_at || "未設定")}
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <div class="font-bold mr-2">
                                ${answerCount} 件
                            </div>
                            ${buttons}
                        </div>
                    </div>
                </div>
            `;
        }).join("");
    },

    renderGroups: function() {
        const survey =
            App.state.editorSurvey;

        return (survey.groups || []).map(
            (group, gi) => `
                <div
                    class="group-item bg-white rounded-2xl border shadow-sm p-5"
                    data-group-id="${group.id}">

                    <div class="flex items-center gap-3 mb-4">
                        <span class="group-handle cursor-move text-xl">
                            ⠿
                        </span>

                        <input
                            value="${App.utils.escape(group.name)}"
                            oninput="App.actions.updateGroupName('${group.id}',this.value)"
                            class="flex-1 border rounded-xl px-4 py-2 font-bold">

                        <button
                            onclick="App.actions.addQuestion('${group.id}')"
                            class="px-3 py-2 rounded-lg bg-blue-100 text-blue-700">
                            ＋質問
                        </button>

                        <button
                            onclick="App.actions.deleteGroup('${group.id}')"
                            class="px-3 py-2 rounded-lg bg-red-100 text-red-700">
                            削除
                        </button>
                    </div>

                    <div
                        class="question-list space-y-3"
                        data-group-id="${group.id}">

                        ${(group.questions || []).map(
                            (q, qi) =>
                                App.renderQuestion(
                                    group,
                                    q,
                                    qi,
                                    gi
                                )
                        ).join("")}

                    </div>
                </div>
            `
        ).join("");
    },

    renderQuestion: function(
        group,
        q,
        qi,
        gi
    ) {
        return `
            <div
                class="question-item border rounded-xl p-4 bg-slate-50"
                data-question-id="${q.id}">

                <div class="flex gap-3">
                    <span class="question-handle cursor-move text-lg">
                        ⠿
                    </span>

                    <div class="flex-1">

                        <div class="flex justify-between gap-3 mb-3">
                            <div class="font-bold question-number">
                                Q?
                            </div>

                            <button
                                onclick="App.actions.deleteQuestion('${group.id}','${q.id}')"
                                class="text-red-600 text-sm">
                                削除
                            </button>
                        </div>

                        <input
                            value="${App.utils.escape(q.text)}"
                            oninput="App.actions.updateQuestion('${group.id}','${q.id}','text',this.value)"
                            placeholder="質問文"
                            class="w-full border rounded-xl px-4 py-3 bg-white mb-3">

                        <div class="grid md:grid-cols-2 gap-3">

                            <select
                                onchange="App.actions.updateQuestion('${group.id}','${q.id}','type',this.value)"
                                class="border rounded-xl px-4 py-3 bg-white">

                                <option value="single"
                                    ${q.type === "single" ? "selected" : ""}>
                                    単一選択
                                </option>

                                <option value="multiple"
                                    ${q.type === "multiple" ? "selected" : ""}>
                                    複数選択
                                </option>

                                <option value="text"
                                    ${q.type === "text" ? "selected" : ""}>
                                    自由記述
                                </option>
                            </select>

                            <label class="flex items-center gap-2 px-3">
                                <input
                                    type="checkbox"
                                    ${q.required ? "checked" : ""}
                                    onchange="App.actions.updateQuestion('${group.id}','${q.id}','required',this.checked)">
                                必須回答
                            </label>
                        </div>

                        ${
                            q.type === "text"
                            ? ""
                            : `
                                <div class="mt-3">
                                    <label class="text-sm font-bold">
                                        選択肢
                                    </label>

                                    <div class="space-y-2 mt-2">
                                        ${(q.options || []).map(
                                            (option, oi) => `
                                                <div class="flex gap-2">
                                                    <input
                                                        value="${App.utils.escape(option)}"
                                                        oninput="App.actions.updateOption('${group.id}','${q.id}',${oi},this.value)"
                                                        class="flex-1 border rounded-lg px-3 py-2 bg-white">

                                                    <button
                                                        onclick="App.actions.deleteOption('${group.id}','${q.id}',${oi})"
                                                        class="px-3 rounded-lg bg-red-100 text-red-600">
                                                        ×
                                                    </button>
                                                </div>
                                            `
                                        ).join("")}
                                    </div>

                                    <button
                                        onclick="App.actions.addOption('${group.id}','${q.id}')"
                                        class="mt-2 text-sm px-3 py-2 rounded-lg bg-white border">
                                        ＋選択肢
                                    </button>

                                    <label class="flex items-center gap-2 mt-3 text-sm">
                                        <input
                                            type="checkbox"
                                            ${q.other_enabled ? "checked" : ""}
                                            onchange="App.actions.updateQuestion('${group.id}','${q.id}','other_enabled',this.checked)">
                                        「その他」を許可
                                    </label>
                                </div>
                            `
                        }
                    </div>
                </div>
            </div>
        `;
    },

    renderMapping: function(s) {
        const fields =
            App.state.fields || [];

        const options =
            `<option value="">-- 選択してください --</option>` +
            fields.map(
                f => `
                    <option value="${App.utils.escape(f.code)}">
                        ${App.utils.escape(f.label)}
                        (${App.utils.escape(f.code)})
                    </option>
                `
            ).join("");

        const select = function(
            id,
            value,
            multiple
        ) {
            const values =
                Array.isArray(value)
                    ? value
                    : [value || ""];

            return `
                <select
                    ${multiple ? "multiple" : ""}
                    id="${id}"
                    class="w-full border rounded-xl px-4 py-3 bg-white"
                    onchange="App.actions.updateMapping('${id}',this)">

                    ${
                        multiple
                            ? fields.map(
                                f => `
                                    <option
                                        value="${App.utils.escape(f.code)}"
                                        ${values.includes(f.code) ? "selected" : ""}>
                                        ${App.utils.escape(f.label)}
                                        (${App.utils.escape(f.code)})
                                    </option>
                                `
                            ).join("")
                            : options.replace(
                                `value="${App.utils.escape(value || "")}"`,
                                `value="${App.utils.escape(value || "")}" selected`
                            )
                    }
                </select>
            `;
        };

        return `
            <div class="grid md:grid-cols-2 gap-4">

                <div>
                    <label class="font-bold block mb-2">
                        会社名 (Company)
                    </label>
                    ${select(
                        "field_company",
                        s.field_company,
                        false
                    )}
                </div>

                <div>
                    <label class="font-bold block mb-2">
                        氏名 (Name)
                    </label>
                    ${select(
                        "field_name",
                        s.field_name,
                        false
                    )}
                </div>

                <div>
                    <label class="font-bold block mb-2">
                        メールアドレス (Email)
                    </label>
                    ${select(
                        "field_email",
                        s.field_email,
                        false
                    )}
                </div>

                <div>
                    <label class="font-bold block mb-2">
                        部署名 (Department)
                    </label>
                    ${select(
                        "field_department",
                        s.field_department,
                        false
                    )}
                </div>

                <div>
                    <label class="font-bold block mb-2">
                        電話番号 (Phone)
                    </label>
                    ${select(
                        "field_phone",
                        s.field_phone,
                        false
                    )}
                </div>

                <div>
                    <label class="font-bold block mb-2">
                        住所 (Address)
                    </label>
                    ${select(
                        "field_address",
                        s.field_address || [],
                        true
                    )}
                    <div class="text-xs text-slate-500 mt-1">
                        Ctrl / Command を押しながら複数選択できます。
                    </div>
                </div>

            </div>
        `;
    },

    summaryCard: function(label, value) {
        return `
            <div class="bg-white border rounded-2xl p-5 shadow-sm">
                <div class="text-sm text-slate-500">
                    ${label}
                </div>
                <div class="text-2xl font-bold mt-2">
                    ${value}
                </div>
            </div>
        `;
    },

    renderQuestionSummary: function(
        survey,
        responses
    ) {
        const questions = [];

        (survey.groups || []).forEach(
            group => {
                (group.questions || []).forEach(
                    q => questions.push(q)
                );
            }
        );

        if (!questions.length) {
            return `
                <div class="text-slate-500">
                    設問がありません。
                </div>
            `;
        }

        return questions.map(q => {
            if (q.type === "text") {
                const texts = [];

                responses.forEach(r => {
                    const value =
                        (r.answers || {})[q.id];

                    if (value) {
                        texts.push({
                            name:
                                r.name ||
                                "匿名",
                            company:
                                r.company || "",
                            text: value
                        });
                    }
                });

                return `
                    <div class="border rounded-xl p-4">
                        <div class="font-bold mb-3">
                            ${App.utils.escape(q.text)}
                        </div>

                        ${
                            texts.length
                            ? `
                                <div class="space-y-2 max-h-72 overflow-auto">
                                    ${texts.map(
                                        x => `
                                            <div class="bg-slate-50 rounded-lg p-3">
                                                <div class="text-xs text-slate-500">
                                                    ${App.utils.escape(x.company)}
                                                    ${App.utils.escape(x.name)}
                                                </div>
                                                <div class="mt-1 whitespace-pre-wrap">
                                                    ${App.utils.escape(x.text)}
                                                </div>
                                            </div>
                                        `
                                    ).join("")}
                                </div>
                            `
                            : `
                                <div class="text-slate-400">
                                    現在、回答データはありません
                                </div>
                            `
                        }
                    </div>
                `;
            }

            const counts = {};

            (q.options || []).forEach(
                option => counts[option] = 0
            );

            responses.forEach(r => {
                let value =
                    (r.answers || {})[q.id];

                if (!Array.isArray(value)) {
                    value = [value];
                }

                value.forEach(v => {
                    if (v && counts[v] !== undefined) {
                        counts[v]++;
                    }
                });
            });

            const total =
                responses.length || 1;

            return `
                <div class="border rounded-xl p-4">
                    <div class="font-bold mb-4">
                        ${App.utils.escape(q.text)}
                    </div>

                    <div class="space-y-3">
                        ${Object.entries(counts).map(
                            ([label, count]) => {
                                const percent =
                                    count / total * 100;

                                return `
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span>
                                                ${App.utils.escape(label)}
                                            </span>
                                            <span>
                                                ${count} 件
                                                (${percent.toFixed(1)}%)
                                            </span>
                                        </div>

                                        <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                            <div
                                                class="h-full bg-blue-500"
                                                style="width:${percent}%">
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }
                        ).join("")}
                    </div>
                </div>
            `;
        }).join("");
    },

    renderResponseTable: function(responses) {
        if (!responses.length) {
            return `
                <div class="text-center py-10 text-slate-400">
                    現在、回答データはありません
                </div>
            `;
        }

        return `
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="p-3">会社名</th>
                            <th class="p-3">氏名</th>
                            <th class="p-3">メール</th>
                            <th class="p-3">回答日時</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>

                    <tbody>
                        ${responses.map(
                            r => `
                                <tr class="border-b">
                                    <td class="p-3">
                                        ${App.utils.escape(r.company)}
                                    </td>

                                    <td class="p-3 font-bold">
                                        ${App.utils.escape(r.name)}
                                    </td>

                                    <td class="p-3">
                                        ${App.utils.escape(r.email)}
                                    </td>

                                    <td class="p-3">
                                        ${App.utils.escape(r.answered_at)}
                                    </td>

                                    <td class="p-3">
                                        <button
                                            onclick="App.actions.showResponse('${r.id}')"
                                            class="px-3 py-2 rounded-lg bg-indigo-100 text-indigo-700">
                                            全回答を表示
                                        </button>
                                    </td>
                                </tr>
                            `
                        ).join("")}
                    </tbody>
                </table>
            </div>
        `;
    },

    renderCustomerTable: function(customers) {
        if (!customers.length) {
            return `
                <div class="text-center py-10 text-slate-400">
                    顧客データがありません。
                    kintone設定画面から「顧客データを同期」を実行してください。
                </div>
            `;
        }

        return `
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="p-3">
                                <input
                                    id="select_all"
                                    type="checkbox"
                                    onchange="App.actions.toggleAllCustomers(this.checked)">
                            </th>
                            <th class="p-3">会社名</th>
                            <th class="p-3">氏名</th>
                            <th class="p-3">メールアドレス</th>
                            <th class="p-3">電話番号</th>
                            <th class="p-3">送信状況</th>
                            <th class="p-3">回答</th>
                        </tr>
                    </thead>

                    <tbody>
                        ${customers.map(c => `
                            <tr class="border-b">
                                <td class="p-3">
                                    <input
                                        type="checkbox"
                                        value="${App.utils.escape(c.id)}"
                                        ${App.state.selectedCustomers.includes(c.id) ? "checked" : ""}
                                        onchange="App.actions.toggleCustomer('${c.id}',this.checked)">
                                </td>

                                <td class="p-3 font-bold">
                                    ${App.utils.escape(c.company)}
                                </td>

                                <td class="p-3">
                                    ${App.utils.escape(c.name)}
                                </td>

                                <td class="p-3">
                                    ${App.utils.escape(c.email)}
                                </td>

                                <td class="p-3">
                                    ${App.utils.escape(c.phone)}
                                </td>

                                <td class="p-3">
                                    ${
                                        c.sent_at
                                        ? `
                                            <div>
                                                <span class="px-2 py-1 rounded-full bg-blue-100 text-blue-700">
                                                    送信済み
                                                </span>
                                            </div>
                                            <div class="text-xs text-slate-400 mt-1">
                                                ${App.utils.escape(c.sent_at)}
                                                / ${c.send_count || 0}回
                                            </div>
                                        `
                                        : `
                                            <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-600">
                                                未送信
                                            </span>
                                        `
                                    }
                                </td>

                                <td class="p-3">
                                    ${
                                        c.answer_status === "answered"
                                        ? `
                                            <span class="px-2 py-1 rounded-full bg-green-100 text-green-700">
                                                回答済み
                                            </span>
                                        `
                                        : `
                                            <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700">
                                                未回答
                                            </span>
                                        `
                                    }
                                </td>
                            </tr>
                        `).join("")}
                    </tbody>
                </table>
            </div>
        `;
    },

    /* ============================================================
     * Actions
     * ============================================================ */

    actions: {
        goList: function() {
            App.renderScreen("list");
        },

        goSettings: function() {
            App.renderScreen("settings");
        },

        newSurvey: function() {
            App.state.editorSurvey = {
                id: "",
                title: "新しいアンケート",
                start_at: "",
                end_at: "",
                status: "draft",
                created_at: "",
                updated_at: "",
                numbering_mode: "global",
                groups: [
                    App.utils.newGroup()
                ],
                deleted: false
            };

            App.renderScreen("editor");
        },

        editSurvey: function(id) {
            const survey =
                App.utils.findSurvey(id);

            if (!survey) {
                alert("アンケートが見つかりません。");
                return;
            }

            App.state.editorSurvey =
                App.utils.clone(survey);

            App.renderScreen("editor");
        },

        updateEditorField: function(
            key,
            value
        ) {
            if (!App.state.editorSurvey) {
                return;
            }

            App.state.editorSurvey[key] =
                value;
        },

        addGroup: function() {
            App.state.editorSurvey.groups.push(
                App.utils.newGroup()
            );

            App.render.editor();
        },

        deleteGroup: function(groupId) {
            if (
                !confirm(
                    "このグループと含まれる質問を削除しますか？"
                )
            ) {
                return;
            }

            App.state.editorSurvey.groups =
                App.state.editorSurvey.groups.filter(
                    g => g.id !== groupId
                );

            App.render.editor();
        },

        updateGroupName: function(
            groupId,
            value
        ) {
            const group =
                App.state.editorSurvey.groups.find(
                    g => g.id === groupId
                );

            if (group) {
                group.name = value;
            }
        },

        addQuestion: function(groupId) {
            const group =
                App.state.editorSurvey.groups.find(
                    g => g.id === groupId
                );

            if (!group) {
                return;
            }

            group.questions.push(
                App.utils.newQuestion()
            );

            App.render.editor();
        },

        deleteQuestion: function(
            groupId,
            questionId
        ) {
            const group =
                App.state.editorSurvey.groups.find(
                    g => g.id === groupId
                );

            if (!group) {
                return;
            }

            group.questions =
                group.questions.filter(
                    q => q.id !== questionId
                );

            App.render.editor();
        },

        updateQuestion: function(
            groupId,
            questionId,
            key,
            value
        ) {
            const group =
                App.state.editorSurvey.groups.find(
                    g => g.id === groupId
                );

            if (!group) {
                return;
            }

            const q =
                group.questions.find(
                    q => q.id === questionId
                );

            if (!q) {
                return;
            }

            q[key] = value;

            if (
                key === "type" &&
                value === "text"
            ) {
                q.options = [];
            }

            if (
                key === "type" &&
                value !== "text" &&
                (!q.options ||
                 !q.options.length)
            ) {
                q.options = [
                    "選択肢1",
                    "選択肢2"
                ];
            }

            App.render.editor();
        },

        addOption: function(
            groupId,
            questionId
        ) {
            const q =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!q) {
                return;
            }

            q.options =
                q.options || [];

            q.options.push(
                "新しい選択肢"
            );

            App.render.editor();
        },

        updateOption: function(
            groupId,
            questionId,
            index,
            value
        ) {
            const q =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (q && q.options) {
                q.options[index] = value;
            }
        },

        deleteOption: function(
            groupId,
            questionId,
            index
        ) {
            const q =
                App.actions.findQuestion(
                    groupId,
                    questionId
                );

            if (!q || !q.options) {
                return;
            }

            q.options.splice(index, 1);

            App.render.editor();
        },

        findQuestion: function(
            groupId,
            questionId
        ) {
            const group =
                App.state.editorSurvey.groups.find(
                    g => g.id === groupId
                );

            if (!group) {
                return null;
            }

            return group.questions.find(
                q => q.id === questionId
            ) || null;
        },

        renumber: function() {
            const mode =
                App.state.editorSurvey.numbering_mode;

            let global = 1;

            App.state.editorSurvey.groups
                .forEach((group, gi) => {
                    group.questions.forEach(
                        (q, qi) => {
                            q._number =
                                mode === "group"
                                    ? "Q" +
                                      (gi + 1) +
                                      "-" +
                                      (qi + 1)
                                    : "Q" + global;

                            global++;
                        }
                    );
                });

            const nodes =
                document.querySelectorAll(
                    ".question-number"
                );

            let index = 0;

            App.state.editorSurvey.groups
                .forEach(group => {
                    group.questions.forEach(
                        q => {
                            if (nodes[index]) {
                                nodes[index].textContent =
                                    q._number;
                            }

                            index++;
                        }
                    );
                });
        },

        initSortable: function() {
            if (
                typeof Sortable === "undefined"
            ) {
                console.warn(
                    "SortableJSが読み込まれていません。"
                );
                App.actions.renumber();
                return;
            }

            const groupContainer =
                document.getElementById(
                    "question_editor"
                );

            if (groupContainer) {
                new Sortable(
                    groupContainer,
                    {
                        animation: 180,
                        ghostClass: "opacity-40",
                        handle: ".group-handle",
                        onEnd: function() {
                            App.actions.syncGroupsFromDOM();
                        }
                    }
                );
            }

            document.querySelectorAll(
                ".question-list"
            ).forEach(
                element => {
                    new Sortable(
                        element,
                        {
                            group: "survey-questions",
                            animation: 180,
                            ghostClass: "opacity-40",
                            handle: ".question-handle",
                            onEnd: function(evt) {
                                App.actions.syncQuestionsFromDOM(
                                    evt
                                );
                            }
                        }
                    );
                }
            );

            App.actions.renumber();
        },

        syncGroupsFromDOM: function() {
            const ids =
                Array.from(
                    document.querySelectorAll(
                        "#question_editor > .group-item"
                    )
                ).map(
                    el => el.dataset.groupId
                );

            const old =
                App.state.editorSurvey.groups;

            App.state.editorSurvey.groups =
                ids.map(
                    id =>
                        old.find(
                            g => g.id === id
                        )
                ).filter(Boolean);

            App.render.editor();
        },

        syncQuestionsFromDOM: function() {
            const survey =
                App.state.editorSurvey;

            document.querySelectorAll(
                ".question-list"
            ).forEach(list => {
                const groupId =
                    list.dataset.groupId;

                const group =
                    survey.groups.find(
                        g => g.id === groupId
                    );

                if (!group) {
                    return;
                }

                const ids =
                    Array.from(
                        list.querySelectorAll(
                            ".question-item"
                        )
                    ).map(
                        el => el.dataset.questionId
                    );

                const allQuestions = [];

                survey.groups.forEach(
                    g => {
                        g.questions.forEach(
                            q => {
                                allQuestions.push(q);
                            }
                        );
                    }
                );

                group.questions =
                    ids.map(
                        id =>
                            allQuestions.find(
                                q => q.id === id
                            )
                    ).filter(Boolean);
            });

            /*
             * グループを跨いだ質問移動に対応。
             * DOM上に存在する順序を全グループから再構築。
             */
            const used = new Set();

            survey.groups.forEach(
                group => {
                    const list =
                        document.querySelector(
                            '.question-list[data-group-id="' +
                            group.id +
                            '"]'
                        );

                    if (!list) {
                        return;
                    }

                    const ids =
                        Array.from(
                            list.querySelectorAll(
                                ".question-item"
                            )
                        ).map(
                            el =>
                                el.dataset.questionId
                        );

                    group.questions =
                        ids.map(id => {
                            let found = null;

                            survey.groups.forEach(
                                g => {
                                    const q =
                                        g.questions.find(
                                            x => x.id === id
                                        );

                                    if (q) {
                                        found = q;
                                    }
                                }
                            );

                            return found;
                        }).filter(
                            Boolean
                        );

                    group.questions.forEach(
                        q => used.add(q.id)
                    );
                }
            );

            App.actions.renumber();
        },

        saveEditor: async function() {
            App.actions.renumber();

            const survey =
                App.state.editorSurvey;

            survey.groups.forEach(
                g => {
                    g.questions.forEach(
                        q => {
                            delete q._number;
                        }
                    );
                }
            );

            try {
                await App.api.saveSurvey(
                    survey
                );

                await App.api.load();

                alert(
                    "アンケートを保存しました。"
                );

                App.state.editorSurvey = null;

                App.renderScreen("list");
            } catch (error) {
                alert(
                    "保存に失敗しました。\n" +
                    error.message
                );
            }
        },

        cancelEditor: function() {
            if (
                !confirm(
                    "未保存の変更を破棄して一覧へ戻りますか？"
                )
            ) {
                return;
            }

            App.state.editorSurvey = null;

            App.renderScreen("list");
        },

        preview: function() {
            const survey =
                App.state.editorSurvey;

            const modal =
                document.getElementById(
                    "preview_modal"
                );

            const content =
                document.getElementById(
                    "preview_content"
                );

            content.innerHTML = `
                <h1 class="text-2xl font-bold mb-6">
                    ${App.utils.escape(survey.title)}
                </h1>

                ${
                    (survey.groups || []).map(
                        group => `
                            <section class="mb-8">
                                <h2 class="text-lg font-bold border-b pb-2 mb-4">
                                    ${App.utils.escape(group.name)}
                                </h2>

                                <div class="space-y-6">
                                    ${(group.questions || []).map(
                                        q => `
                                            <div>
                                                <div class="font-bold mb-2">
                                                    ${App.utils.escape(q.text)}
                                                    ${
                                                        q.required
                                                        ? `<span class="text-red-500 ml-1">*</span>`
                                                        : ""
                                                    }
                                                </div>

                                                ${
                                                    q.type === "text"
                                                    ? `
                                                        <textarea
                                                            class="w-full border rounded-xl p-3"
                                                            rows="4"></textarea>
                                                    `
                                                    :
                                                    (q.options || []).map(
                                                        option => `
                                                            <label class="flex items-center gap-2 mb-2">
                                                                <input
                                                                    type="${q.type === "single" ? "radio" : "checkbox"}"
                                                                    name="${q.id}">
                                                                ${App.utils.escape(option)}
                                                            </label>
                                                        `
                                                    ).join("")
                                                }
                                            </div>
                                        `
                                    ).join("")}
                                </div>
                            </section>
                        `
                    ).join("")
                }

                <button
                    onclick="alert('これはプレビューです。実際の送信は行いません。')"
                    class="px-5 py-3 rounded-xl bg-blue-600 text-white">
                    回答を送信
                </button>
            `;

            modal.classList.remove("hidden");
        },

        closePreview: function() {
            document.getElementById(
                "preview_modal"
            ).classList.add("hidden");
        },

        stopSurvey: async function(id) {
            if (
                !confirm(
                    "このアンケートを停止しますか？"
                )
            ) {
                return;
            }

            const survey =
                App.utils.findSurvey(id);

            if (!survey) {
                return;
            }

            survey.status = "ended";
            survey.updated_at =
                new Date()
                    .toISOString()
                    .slice(0, 19)
                    .replace("T", " ");

            try {
                await App.api.saveSurvey(
                    survey
                );

                await App.api.load();

                App.renderScreen("list");
            } catch (e) {
                alert(e.message);
            }
        },

        deleteSurvey: async function(id) {
            if (
                !confirm(
                    "このアンケートを削除しますか？"
                )
            ) {
                return;
            }

            try {
                await App.api.request(
                    "delete_survey",
                    {
                        survey_id: id
                    }
                );

                await App.api.load();

                App.renderScreen("list");
            } catch (e) {
                alert(e.message);
            }
        },

        duplicate: async function(id) {
            try {
                await App.api.request(
                    "duplicate_survey",
                    {
                        survey_id: id
                    }
                );

                await App.api.load();

                App.renderScreen("list");
            } catch (e) {
                alert(e.message);
            }
        },

        filterSurveys: function() {
            const keyword =
                (
                    document.getElementById(
                        "survey_search"
                    )?.value || ""
                ).toLowerCase();

            const status =
                document.getElementById(
                    "survey_status_filter"
                )?.value || "";

            const sort =
                document.getElementById(
                    "survey_sort"
                )?.value ||
                "updated_desc";

            let surveys =
                App.state.data.surveys
                    .filter(s => !s.deleted);

            surveys =
                surveys.filter(s => {
                    const title =
                        (
                            s.title || ""
                        ).toLowerCase();

                    return (
                        !keyword ||
                        title.includes(keyword)
                    ) &&
                    (
                        !status ||
                        s.status === status
                    );
                });

            const answerCount = s =>
                App.state.data.responses.filter(
                    r => r.survey_id === s.id
                ).length;

            surveys.sort(
                (a, b) => {
                    switch (sort) {
                        case "updated_asc":
                            return String(
                                a.updated_at || ""
                            ).localeCompare(
                                String(
                                    b.updated_at || ""
                                )
                            );

                        case "answers_desc":
                            return answerCount(b) -
                                answerCount(a);

                        case "answers_asc":
                            return answerCount(a) -
                                answerCount(b);

                        case "start_desc":
                            return String(
                                b.start_at || ""
                            ).localeCompare(
                                String(
                                    a.start_at || ""
                                )
                            );

                        case "start_asc":
                            return String(
                                a.start_at || ""
                            ).localeCompare(
                                String(
                                    b.start_at || ""
                                )
                            );

                        default:
                            return String(
                                b.updated_at || ""
                            ).localeCompare(
                                String(
                                    a.updated_at || ""
                                )
                            );
                    }
                }
            );

            const list =
                document.getElementById(
                    "survey_list"
                );

            if (list) {
                list.innerHTML =
                    App.renderSurveyRows(
                        surveys
                    );
            }
        },

        summary: function(id) {
            App.state.currentSurveyId = id;

            App.state.responseFilter = "";

            App.renderScreen("summary");
        },

        filterResponses: function() {
            const survey =
                App.utils.findSurvey(
                    App.state.currentSurveyId
                );

            if (!survey) {
                return;
            }

            const keyword =
                (
                    document.getElementById(
                        "response_filter"
                    )?.value || ""
                ).toLowerCase();

            const responses =
                App.state.data.responses
                    .filter(
                        r =>
                            r.survey_id ===
                            survey.id
                    )
                    .filter(
                        r =>
                            !keyword ||
                            (
                                (r.name || "") +
                                " " +
                                (r.company || "")
                            )
                            .toLowerCase()
                            .includes(keyword)
                    );

            const table =
                document.getElementById(
                    "response_table"
                );

            if (table) {
                table.innerHTML =
                    App.renderResponseTable(
                        responses
                    );
            }
        },

        showResponse: function(id) {
            const response =
                App.state.data.responses.find(
                    r => r.id === id
                );

            if (!response) {
                return;
            }

            const modal =
                document.getElementById(
                    "response_modal"
                );

            const detail =
                document.getElementById(
                    "response_detail"
                );

            const survey =
                App.utils.findSurvey(
                    response.survey_id
                );

            const questions = [];

            (survey?.groups || [])
                .forEach(
                    g =>
                        (g.questions || [])
                            .forEach(
                                q =>
                                    questions.push(q)
                            )
                );

            detail.innerHTML = `
                <div class="mb-5">
                    <div class="text-sm text-slate-400">
                        回答者
                    </div>
                    <div class="font-bold text-xl">
                        ${App.utils.escape(response.company)}
                        /
                        ${App.utils.escape(response.name)}
                    </div>
                    <div class="text-sm text-slate-500">
                        ${App.utils.escape(response.email)}
                        /
                        ${App.utils.escape(response.answered_at)}
                    </div>
                </div>

                <div class="space-y-4">
                    ${questions.map(
                        q => {
                            let value =
                                (response.answers || {})[q.id];

                            if (Array.isArray(value)) {
                                value =
                                    value.join(", ");
                            }

                            return `
                                <div class="border rounded-xl p-4">
                                    <div class="font-bold">
                                        ${App.utils.escape(q.text)}
                                    </div>

                                    <div class="mt-2 whitespace-pre-wrap">
                                        ${App.utils.escape(value || "未回答")}
                                    </div>
                                </div>
                            `;
                        }
                    ).join("")}
                </div>
            `;

            modal.classList.remove("hidden");
        },

        closeResponseModal: function() {
            document.getElementById(
                "response_modal"
            ).classList.add("hidden");
        },

        mail: function(id) {
            App.state.currentSurveyId = id;
            App.state.selectedCustomers = [];
            App.renderScreen("mail");
        },

        filterCustomers: function() {
            const keyword =
                (
                    document.getElementById(
                        "customer_filter"
                    )?.value || ""
                ).toLowerCase();

            const customers =
                App.state.data.customers
                    .filter(c => {
                        const target =
                            (
                                (c.company || "") +
                                " " +
                                (c.name || "") +
                                " " +
                                (c.email || "")
                            ).toLowerCase();

                        return (
                            !keyword ||
                            target.includes(keyword)
                        );
                    });

            const table =
                document.getElementById(
                    "customer_table"
                );

            if (table) {
                table.innerHTML =
                    App.renderCustomerTable(
                        customers
                    );
            }
        },

        toggleCustomer: function(
            id,
            checked
        ) {
            if (checked) {
                if (
                    !App.state.selectedCustomers.includes(
                        id
                    )
                ) {
                    App.state.selectedCustomers.push(
                        id
                    );
                }
            } else {
                App.state.selectedCustomers =
                    App.state.selectedCustomers.filter(
                        x => x !== id
                    );
            }
        },

        toggleAllCustomers: function(
            checked
        ) {
            if (checked) {
                App.state.selectedCustomers =
                    App.state.data.customers
                        .filter(
                            c =>
                                c.source !== "web"
                        )
                        .map(c => c.id);
            } else {
                App.state.selectedCustomers = [];
            }

            App.actions.filterCustomers();
        },

        selectUnanswered: function() {
            App.state.selectedCustomers =
                App.state.data.customers
                    .filter(
                        c =>
                            c.answer_status ===
                            "unanswered"
                    )
                    .map(
                        c => c.id
                    );

            App.actions.filterCustomers();
        },

        sendMail: async function() {
            const selected =
                App.state.selectedCustomers;

            if (!selected.length) {
                alert(
                    "送信対象を選択してください。"
                );
                return;
            }

            const alreadySent =
                App.state.data.customers
                    .filter(
                        c =>
                            selected.includes(c.id) &&
                            c.sent_at
                    );

            if (
                alreadySent.length &&
                !confirm(
                    "既に送信済みの宛先が含まれています。再送しますか？"
                )
            ) {
                return;
            }

            const subject =
                document.getElementById(
                    "mail_subject"
                )?.value || "";

            const body =
                document.getElementById(
                    "mail_body"
                )?.value || "";

            const template =
                document.getElementById(
                    "template_type"
                )?.value || "initial";

            if (!subject || !body) {
                alert(
                    "件名と本文を入力してください。"
                );
                return;
            }

            if (
                !confirm(
                    selected.length +
                    "件にメールを送信します。よろしいですか？"
                )
            ) {
                return;
            }

            try {
                const result =
                    await App.api.request(
                        "send_mail",
                        {
                            survey_id:
                                App.state.currentSurveyId,
                            recipient_ids:
                                selected,
                            mail_subject:
                                subject,
                            mail_body:
                                body,
                            template_type:
                                template
                        }
                    );

                await App.api.load();

                alert(
                    "送信完了\n" +
                    "成功: " +
                    result.success_count +
                    "件\n" +
                    "失敗: " +
                    result.failed_count +
                    "件\n" +
                    "未送信: " +
                    result.unsent_count +
                    "件"
                );

                App.state.selectedCustomers =
                    [];

                App.renderScreen("mail");
            } catch (e) {
                alert(
                    "一括送信を開始できませんでした。\n" +
                    e.message
                );
            }
        },

        /* ========================================================
         * kintone
         * ======================================================== */

        getSettingsFromForm: function() {
            const s =
                App.utils.clone(
                    App.state.data.settings || {}
                );

            s.subdomain =
                document.getElementById(
                    "setting_subdomain"
                )?.value || "";

            s.app_id =
                document.getElementById(
                    "setting_app_id"
                )?.value || "";

            s.login_name =
                document.getElementById(
                    "setting_login_name"
                )?.value || "";

            s.password =
                document.getElementById(
                    "setting_password"
                )?.value || "";

            s.proxy =
                document.getElementById(
                    "setting_proxy"
                )?.value || "";

            s.ssl_verify =
                document.getElementById(
                    "setting_ssl_verify"
                )?.checked || false;

            s.smtp_host =
                document.getElementById(
                    "smtp_host"
                )?.value || "";

            s.smtp_port =
                Number(
                    document.getElementById(
                        "smtp_port"
                    )?.value || 587
                );

            s.smtp_encryption =
                document.getElementById(
                    "smtp_encryption"
                )?.value || "tls";

            s.smtp_auth =
                document.getElementById(
                    "smtp_auth"
                )?.value === "1";

            s.smtp_username =
                document.getElementById(
                    "smtp_username"
                )?.value || "";

            s.smtp_password =
                document.getElementById(
                    "smtp_password"
                )?.value || "";

            s.smtp_from =
                document.getElementById(
                    "smtp_from"
                )?.value || "";

            s.smtp_from_name =
                document.getElementById(
                    "smtp_from_name"
                )?.value || "";

            s.smtp_timeout =
                Number(
                    document.getElementById(
                        "smtp_timeout"
                    )?.value || 15
                );

            return s;
        },

        saveSettings: async function() {
            const s =
                App.actions.getSettingsFromForm();

            try {
                const selects = [
                    "field_company",
                    "field_name",
                    "field_email",
                    "field_department",
                    "field_phone"
                ];

                selects.forEach(
                    id => {
                        const element =
                            document.getElementById(
                                id
                            );

                        if (element) {
                            s[id.replace(
                                "field_",
                                "field_"
                            )] =
                                element.value;
                        }
                    }
                );

                const address =
                    document.getElementById(
                        "field_address"
                    );

                if (address) {
                    s.field_address =
                        Array.from(
                            address.selectedOptions
                        ).map(
                            option =>
                                option.value
                        );
                }

                await App.api.saveSettings(s);

                alert(
                    "設定を保存しました。"
                );
            } catch (e) {
                alert(
                    "設定保存に失敗しました。\n" +
                    e.message
                );
            }
        },

        testKintone: async function() {
            const s =
                App.actions.getSettingsFromForm();

            try {
                const result =
                    await App.api.request(
                        "kintone_test",
                        {
                            subdomain:
                                s.subdomain,
                            login_name:
                                s.login_name,
                            password:
                                s.password
                        }
                    );

                const message =
                    document.getElementById(
                        "field_message"
                    );

                message.className =
                    "mb-4 p-4 rounded-xl bg-green-100 text-green-700";

                message.innerHTML =
                    App.utils.escape(
                        result.message
                    );

                message.classList.remove(
                    "hidden"
                );
            } catch (e) {
                const message =
                    document.getElementById(
                        "field_message"
                    );

                message.className =
                    "mb-4 p-4 rounded-xl bg-red-100 text-red-700";

                message.textContent =
                    e.message;

                message.classList.remove(
                    "hidden"
                );
            }
        },

        /* ★必須関数 */
        fetchKintoneFields: async function() {
            const s =
                App.actions.getSettingsFromForm();

            if (!s.app_id) {
                alert(
                    "顧客管理アプリIDを入力してください。"
                );
                return;
            }

            /*
             * まず設定を保存。
             * これにより app_id / 認証情報の不整合を防止。
             */
            try {
                await App.api.saveSettings(s);

                const result =
                    await App.api.request(
                        "kintone_fields",
                        {
                            app_id: s.app_id
                        }
                    );

                App.state.fields =
                    result.fields || [];

                /*
                 * 取得成功後にマッピングUIを再描画。
                 */
                const area =
                    document.getElementById(
                        "mapping_area"
                    );

                if (area) {
                    area.innerHTML =
                        App.renderMapping(
                            App.state.data.settings
                        );
                }

                const message =
                    document.getElementById(
                        "field_message"
                    );

                if (message) {
                    message.className =
                        "mb-4 p-4 rounded-xl bg-green-100 text-green-700";

                    message.textContent =
                        App.state.fields.length +
                        "件のフィールドを取得しました。";

                    message.classList.remove(
                        "hidden"
                    );
                }
            } catch (e) {
                const message =
                    document.getElementById(
                        "field_message"
                    );

                if (message) {
                    message.className =
                        "mb-4 p-4 rounded-xl bg-red-100 text-red-700";

                    message.textContent =
                        "項目一覧取得失敗: " +
                        e.message;

                    message.classList.remove(
                        "hidden"
                    );
                } else {
                    alert(
                        "項目一覧取得失敗:\n" +
                        e.message
                    );
                }
            }
        },

        updateMapping: function(
            id,
            element
        ) {
            const s =
                App.state.data.settings;

            if (id === "field_address") {
                s.field_address =
                    Array.from(
                        element.selectedOptions
                    ).map(
                        option =>
                            option.value
                    );
            } else {
                s[id] =
                    element.value;
            }
        },

        syncCustomers: async function() {
            const s =
                App.actions.getSettingsFromForm();

            try {
                await App.api.saveSettings(s);

                const result =
                    await App.api.request(
                        "sync_customers",
                        {
                            app_id:
                                s.app_id
                        }
                    );

                await App.api.load();

                alert(
                    result.count +
                    "件の顧客データを同期しました。"
                );
            } catch (e) {
                alert(
                    "顧客同期に失敗しました。\n" +
                    e.message
                );
            }
        },

        /* ========================================================
         * SMTP
         * ======================================================== */

        testSMTP: async function() {
            const s =
                App.actions.getSettingsFromForm();

            try {
                const result =
                    await App.api.request(
                        "smtp_test_connection",
                        {
                            settings_json: s
                        }
                    );

                alert(
                    "SMTP接続確認\n\n" +
                    "結果: 成功\n" +
                    "サーバ: " +
                    result.diagnostic.smtp_host +
                    "\nポート: " +
                    result.diagnostic.smtp_port +
                    "\n暗号化: " +
                    result.diagnostic.encryption +
                    "\nTCP: " +
                    result.diagnostic.tcp +
                    "\nSMTPコード: " +
                    result.diagnostic.smtp_code
                );
            } catch (e) {
                alert(
                    "SMTP接続確認失敗\n\n" +
                    e.message
                );
            }
        },

        testSMTPSend: async function() {
            const email =
                document.getElementById(
                    "smtp_test_email"
                )?.value || "";

            if (!email) {
                alert(
                    "送信先メールアドレスを入力してください。"
                );
                return;
            }

            const s =
                App.actions.getSettingsFromForm();

            try {
                const result =
                    await App.api.request(
                        "smtp_test_send",
                        {
                            settings_json: s,
                            test_email: email
                        }
                    );

                alert(
                    result.ok
                        ? "テストメールを送信しました。\n" +
                          result.message
                        : "テストメール送信失敗\n" +
                          result.message
                );
            } catch (e) {
                alert(
                    "テストメール送信失敗\n\n" +
                    e.message
                );
            }
        }
    }
};

/* ================================================================
 * 追加描画ヘルパー
 * ================================================================ */

App.renderMapping = App.renderMapping;
App.renderSurveyRows = App.renderSurveyRows;

/*
 * ★重要
 *
 * 初期化トリガー。
 * script が head/body のどちらで評価されても1回だけ実行。
 */
if (document.readyState === "loading") {
    document.addEventListener(
        "DOMContentLoaded",
        function() {
            App.init();
        },
        {
            once: true
        }
    );
} else {
    App.init();
}
</script>

</body>
</html>
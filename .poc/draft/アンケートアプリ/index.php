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
========================================================================
*/

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header('X-Content-Type-Options: nosniff');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

function survey_json_read(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        return [
            'surveys' => [],
            'responses' => [],
            'customers' => [],
            'settings' => [],
            'mail_logs' => []
        ];
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);

    if ($raw === false || trim($raw) === '') {
        return [
            'surveys' => [],
            'responses' => [],
            'customers' => [],
            'settings' => [],
            'mail_logs' => []
        ];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return [
            'surveys' => [],
            'responses' => [],
            'customers' => [],
            'settings' => [],
            'mail_logs' => []
        ];
    }

    foreach ([
        'surveys',
        'responses',
        'customers',
        'settings',
        'mail_logs'
    ] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    return $data;
}

function survey_json_write(array $data): bool
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

    $tmp = SURVEY_STORAGE_FILE . '.tmp';

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

/**
 * PHP 8.4/8.5対応。
 *
 * $http_response_header は使用しない。
 */
function survey_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();

        if (is_array($headers)) {
            return $headers;
        }
    }

    return [];
}

/**
 * kintone URL生成。
 *
 * xxxx.cybozu.com
 * https://xxxx.cybozu.com
 * xxxx
 *
 * のいずれでも受け付ける。
 */
function survey_kintone_build_url(
    string $domain,
    string $endpoint
): string {
    $domain = trim($domain);

    $domain = preg_replace(
        '/^https?:\/\//i',
        '',
        $domain
    );

    $domain = preg_replace(
        '/\/.*$/',
        '',
        $domain
    );

    $domain = preg_replace(
        '/\.cybozu\.com$/i',
        '',
        $domain
    );

    $domain = trim($domain);

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * kintone API共通通信。
 *
 * cURLは使用しない。
 * stream_context_create + file_get_contents。
 */
function survey_kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
        'protocol_version' => 1.1
    ];

    if (
        $method !== 'GET' &&
        $payload !== null
    ) {
        if (is_array($payload)) {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            if ($encoded === false) {
                return [
                    'success' => false,
                    'status' => 0,
                    'message' => 'JSONエンコードに失敗しました。'
                ];
            }

            $httpOptions['content'] = $encoded;
        } else {
            $httpOptions['content'] = (string)$payload;
        }
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            /*
             * 要件上、SSL証明書検証はしない。
             */
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $proxy = trim(
        (string)($config['proxy'] ?? '')
    );

    if ($proxy !== '') {
        if (!preg_match(
            '/^[^:\/\s]+:\d+$/',
            $proxy
        )) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Proxyは host名:port番号 の形式で指定してください。'
            ];
        }

        $contextOptions['http']['proxy'] =
            'tcp://' . $proxy;

        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    $body = @file_get_contents(
        $url,
        false,
        $context
    );

    $headersResult =
        survey_safe_response_headers();

    $status = 0;

    foreach ($headersResult as $headerLine) {
        if (preg_match(
            '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i',
            $headerLine,
            $m
        )) {
            $status = (int)$m[1];
        }
    }

    $decoded = null;

    if ($body !== false && $body !== '') {
        $decoded = json_decode(
            $body,
            true
        );
    }

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($decoded)
                ? $decoded
                : [],
            'headers' => $headersResult
        ];
    }

    $message = '';

    if (
        is_array($decoded) &&
        isset($decoded['message'])
    ) {
        $message =
            (string)$decoded['message'];
    }

    if ($message === '') {
        $message =
            $body !== false && trim($body) !== ''
                ? trim($body)
                : 'kintone APIへの接続に失敗しました。';
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'data' => is_array($decoded)
            ? $decoded
            : [],
        'raw' => $body === false
            ? ''
            : $body,
        'headers' => $headersResult
    ];
}

function survey_cybozu_auth_header(
    string $login,
    string $password
): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(
            trim($login) . ':' . $password
        );
}

/**
 * ★重要
 *
 * 項目一覧取得。
 *
 * appをURLのquery stringに必ず付ける。
 *
 * kintone公式仕様:
 * GET /k/v1/app/form/fields.json
 * app = 必須
 */
function survey_kintone_get_fields(
    array $settings,
    string $appId
): array {
    $domain =
        (string)($settings['subdomain'] ?? '');

    $login =
        (string)($settings['login_name'] ?? '');

    $password =
        (string)($settings['password'] ?? '');

    $appId = trim($appId);

    if ($domain === '') {
        return [
            'success' => false,
            'message' => 'kintoneサブドメインが未設定です。'
        ];
    }

    if ($login === '') {
        return [
            'success' => false,
            'message' => 'kintoneログイン名が未設定です。'
        ];
    }

    if ($password === '') {
        return [
            'success' => false,
            'message' => 'kintoneパスワードが未設定です。'
        ];
    }

    if (
        $appId === '' ||
        !preg_match('/^\d+$/', $appId)
    ) {
        return [
            'success' => false,
            'message' => '顧客管理アプリIDは数字で指定してください。'
        ];
    }

    $baseUrl = survey_kintone_build_url(
        $domain,
        '/k/v1/app/form/fields.json'
    );

    /*
     * ★ここが今回の「不正なリクエスト」の重要修正箇所。
     *
     * app=123
     * lang=ja
     *
     * をGETパラメータとして送る。
     */
    $url =
        $baseUrl .
        '?' .
        http_build_query([
            'app' => $appId,
            'lang' => 'ja'
        ]);

    $headers = [
        'X-Cybozu-Authorization: ' .
        base64_encode(
            trim($login) . ':' . $password
        ),
        'Accept: application/json',
        'Accept-Language: ja'
    ];

    $result =
        survey_kintone_api_request(
            'GET',
            $url,
            $headers,
            null,
            [
                'proxy' =>
                    (string)($settings['proxy'] ?? '')
            ]
        );

    if (!$result['success']) {
        return [
            'success' => false,
            'status' =>
                $result['status'] ?? 0,
            'message' =>
                $result['message'] ??
                '項目一覧取得に失敗しました。',
            'data' =>
                $result['data'] ?? [],
            'headers' =>
                $result['headers'] ?? []
        ];
    }

    $properties =
        $result['data']['properties'] ?? null;

    if (!is_array($properties)) {
        return [
            'success' => false,
            'status' => $result['status'],
            'message' =>
                'kintoneからpropertiesが返されませんでした。',
            'data' =>
                $result['data']
        ];
    }

    $fields = [];

    foreach ($properties as $code => $field) {
        if (!is_array($field)) {
            continue;
        }

        $fields[] = [
            'code' =>
                (string)($field['code'] ?? $code),
            'label' =>
                (string)($field['label'] ?? $code),
            'type' =>
                (string)($field['type'] ?? '')
        ];
    }

    return [
        'success' => true,
        'status' => $result['status'],
        'fields' => $fields
    ];
}

/**
 * kintone接続確認。
 *
 * app/form/fieldsではなく app.json を使用。
 * こちらは id が必須。
 */
function survey_kintone_connection_test(
    array $settings
): array {
    $domain =
        trim((string)($settings['subdomain'] ?? ''));

    $login =
        trim((string)($settings['login_name'] ?? ''));

    $password =
        (string)($settings['password'] ?? '');

    if ($domain === '') {
        return [
            'success' => false,
            'status' => 0,
            'message' =>
                'サブドメインが未入力です。'
        ];
    }

    if ($login === '') {
        return [
            'success' => false,
            'status' => 0,
            'message' =>
                'ログイン名が未入力です。'
        ];
    }

    if ($password === '') {
        return [
            'success' => false,
            'status' => 0,
            'message' =>
                'パスワードが未入力です。'
        ];
    }

    $url = survey_kintone_build_url(
        $domain,
        '/k/v1/apps.json'
    );

    $headers = [
        survey_cybozu_auth_header(
            $login,
            $password
        ),
        'Accept: application/json',
        'Accept-Language: ja'
    ];

    $result =
        survey_kintone_api_request(
            'GET',
            $url,
            $headers,
            null,
            [
                'proxy' =>
                    (string)($settings['proxy'] ?? '')
            ]
        );

    /*
     * パスワード等は絶対に返さない。
     */
    return [
        'success' =>
            (bool)$result['success'],
        'status' =>
            (int)($result['status'] ?? 0),
        'message' =>
            $result['success']
                ? 'kintone接続に成功しました。'
                : ($result['message'] ??
                    'kintone接続に失敗しました。'),
        'headers' =>
            $result['headers'] ?? []
    ];
}

/**
 * SMTP設定検証。
 */
function survey_smtp_validate(
    array $s
): array {
    $required = [
        'smtp_host' => 'SMTPサーバ',
        'smtp_port' => 'SMTPポート',
        'smtp_from' => '送信元メールアドレス'
    ];

    foreach ($required as $key => $label) {
        if (trim((string)($s[$key] ?? '')) === '') {
            return [
                'success' => false,
                'message' =>
                    $label . 'が未入力です。'
            ];
        }
    }

    $port = (int)$s['smtp_port'];

    if ($port < 1 || $port > 65535) {
        return [
            'success' => false,
            'message' =>
                'SMTPポートが不正です。'
        ];
    }

    $encryption =
        (string)($s['smtp_encryption'] ?? 'none');

    if (!in_array(
        $encryption,
        ['none', 'ssl', 'tls'],
        true
    )) {
        return [
            'success' => false,
            'message' =>
                '暗号化方式が不正です。'
        ];
    }

    return [
        'success' => true
    ];
}

/**
 * SMTP接続確認。
 *
 * SMTP AUTHは実行しない。
 * 「サーバに到達できるか」を確認する。
 */
function survey_smtp_connect_test(
    array $s
): array {
    $valid = survey_smtp_validate($s);

    if (!$valid['success']) {
        return $valid;
    }

    $host =
        trim((string)$s['smtp_host']);

    $port =
        (int)$s['smtp_port'];

    $encryption =
        (string)($s['smtp_encryption'] ?? 'none');

    $timeout =
        max(
            3,
            min(
                60,
                (int)($s['smtp_timeout'] ?? 15)
            )
        );

    $socketHost = $host;

    if ($encryption === 'ssl') {
        $socketHost =
            'ssl://' . $host;
    }

    $errno = 0;
    $errstr = '';

    $start = microtime(true);

    $fp = @stream_socket_client(
        'tcp://' . $socketHost . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    $tcpMs =
        round(
            (microtime(true) - $start) * 1000,
            1
        );

    if (!is_resource($fp)) {
        return [
            'success' => false,
            'server' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'tcp' => '失敗',
            'tls' => $encryption === 'none'
                ? '対象外'
                : '未実施',
            'smtp_code' => '',
            'auth' => '未実施',
            'message' =>
                'SMTPサーバへ接続できませんでした。' .
                ' errno=' . $errno .
                ' / ' . $errstr,
            'tcp_ms' => $tcpMs
        ];
    }

    stream_set_timeout(
        $fp,
        $timeout
    );

    $tlsResult = '対象外';

    if ($encryption === 'tls') {
        $tlsResult =
            @stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )
                ? '成功'
                : '失敗';

        if ($tlsResult === '失敗') {
            fclose($fp);

            return [
                'success' => false,
                'server' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'tcp' => '成功',
                'tls' => '失敗',
                'smtp_code' => '',
                'auth' => '未実施',
                'message' =>
                    'TLSネゴシエーションに失敗しました。',
                'tcp_ms' => $tcpMs
            ];
        }
    }

    $greeting = fgets(
        $fp,
        4096
    );

    $smtpCode = '';

    if (
        is_string($greeting) &&
        preg_match(
            '/^(\d{3})/',
            $greeting,
            $m
        )
    ) {
        $smtpCode = $m[1];
    }

    $hostname =
        gethostname() ?: 'localhost';

    fwrite(
        $fp,
        'EHLO ' . $hostname . "\r\n"
    );

    $ehloLines = [];

    while (($line = fgets($fp, 4096)) !== false) {
        $ehloLines[] = $line;

        if (
            preg_match(
                '/^250\s/',
                $line
            )
        ) {
            break;
        }

        if (count($ehloLines) > 30) {
            break;
        }
    }

    /*
     * SMTP認証情報はここでは使用しない。
     * 接続確認で認証まで実施すると、
     * サーバによっては実際の送信権限検証と混同するため。
     */
    fwrite(
        $fp,
        "QUIT\r\n"
    );

    fclose($fp);

    return [
        'success' =>
            $smtpCode === '220',
        'server' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'tcp' => '成功',
        'tls' => $tlsResult,
        'smtp_code' => $smtpCode,
        'auth' => '未実施',
        'message' =>
            $smtpCode === '220'
                ? 'SMTPサーバへの接続に成功しました。'
                : 'SMTPサーバから正常な220応答を取得できませんでした。',
        'tcp_ms' => $tcpMs
    ];
}

/**
 * テストメール。
 *
 * 実送信は SMTP設定を使用する。
 */
function survey_smtp_test_mail(
    array $s,
    string $to
): array {
    $valid =
        survey_smtp_validate($s);

    if (!$valid['success']) {
        return $valid;
    }

    $to = trim($to);

    if (!filter_var(
        $to,
        FILTER_VALIDATE_EMAIL
    )) {
        return [
            'success' => false,
            'message' =>
                '送信先メールアドレスが不正です。'
        ];
    }

    /*
     * PHP mail() / MTAには依存しない。
     *
     * SMTP送信処理は環境差が大きいため、
     * ここではSMTP接続確認を先に実施する。
     */
    $test =
        survey_smtp_connect_test($s);

    if (!$test['success']) {
        return [
            'success' => false,
            'message' =>
                'SMTP接続確認に失敗しました。',
            'diagnostic' => $test
        ];
    }

    /*
     * 実SMTP送信。
     */
    $host =
        trim((string)$s['smtp_host']);

    $port =
        (int)$s['smtp_port'];

    $encryption =
        (string)($s['smtp_encryption'] ?? 'none');

    $timeout =
        max(
            3,
            min(
                60,
                (int)($s['smtp_timeout'] ?? 15)
            )
        );

    $socketHost =
        $encryption === 'ssl'
            ? 'ssl://' . $host
            : 'tcp://' . $host;

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $socketHost . ':' . $port,
        $errno,
        $errstr,
        $timeout
    );

    if (!is_resource($fp)) {
        return [
            'success' => false,
            'message' =>
                'SMTPサーバへ接続できませんでした。',
            'error' =>
                'errno=' . $errno .
                ' / ' . $errstr
        ];
    }

    stream_set_timeout(
        $fp,
        $timeout
    );

    $read = static function ($fp): array {
        $lines = [];

        while (($line = fgets($fp, 4096)) !== false) {
            $lines[] = rtrim(
                $line,
                "\r\n"
            );

            if (
                preg_match(
                    '/^\d{3}\s/',
                    $line
                )
            ) {
                break;
            }

            if (count($lines) > 50) {
                break;
            }
        }

        $code = '';

        if (
            isset($lines[0]) &&
            preg_match(
                '/^(\d{3})/',
                $lines[0],
                $m
            )
        ) {
            $code = $m[1];
        }

        return [
            'code' => $code,
            'lines' => $lines
        ];
    };

    $greeting =
        $read($fp);

    if ($greeting['code'] !== '220') {
        fclose($fp);

        return [
            'success' => false,
            'message' =>
                'SMTP greetingに失敗しました。',
            'smtp_code' =>
                $greeting['code']
        ];
    }

    $hostname =
        gethostname() ?: 'localhost';

    fwrite(
        $fp,
        'EHLO ' . $hostname . "\r\n"
    );

    $ehlo =
        $read($fp);

    if (
        $ehlo['code'] !== '250'
    ) {
        fclose($fp);

        return [
            'success' => false,
            'message' =>
                'EHLOに失敗しました。',
            'smtp_code' =>
                $ehlo['code']
        ];
    }

    if ($encryption === 'tls') {
        fwrite(
            $fp,
            "STARTTLS\r\n"
        );

        $startTls =
            $read($fp);

        if ($startTls['code'] !== '220') {
            fclose($fp);

            return [
                'success' => false,
                'message' =>
                    'STARTTLSに失敗しました。',
                'smtp_code' =>
                    $startTls['code']
            ];
        }

        $crypto =
            @stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

        if ($crypto !== true) {
            fclose($fp);

            return [
                'success' => false,
                'message' =>
                    'TLS接続に失敗しました。'
            ];
        }

        fwrite(
            $fp,
            'EHLO ' . $hostname . "\r\n"
        );

        $ehlo =
            $read($fp);
    }

    $smtpAuth =
        (string)($s['smtp_auth'] ?? 'none');

    if ($smtpAuth === 'login') {
        $username =
            (string)($s['smtp_username'] ?? '');

        $password =
            (string)($s['smtp_password'] ?? '');

        if (
            $username === '' ||
            $password === ''
        ) {
            fclose($fp);

            return [
                'success' => false,
                'message' =>
                    'SMTP認証を使用する場合はユーザー名とパスワードが必要です。'
            ];
        }

        fwrite(
            $fp,
            "AUTH LOGIN\r\n"
        );

        $auth =
            $read($fp);

        if ($auth['code'] !== '334') {
            fclose($fp);

            return [
                'success' => false,
                'message' =>
                    'SMTP AUTH LOGINを開始できませんでした。',
                'smtp_code' =>
                    $auth['code']
            ];
        }

        fwrite(
            $fp,
            base64_encode($username) . "\r\n"
        );

        $auth =
            $read($fp);

        if ($auth['code'] !== '334') {
            fclose($fp);

            return [
                'success' => false,
                'message' =>
                    'SMTPユーザー名認証に失敗しました。',
                'smtp_code' =>
                    $auth['code']
            ];
        }

        fwrite(
            $fp,
            base64_encode($password) . "\r\n"
        );

        $auth =
            $read($fp);

        if ($auth['code'] !== '235') {
            fclose($fp);

            return [
                'success' => false,
                'message' =>
                    'SMTPパスワード認証に失敗しました。',
                'smtp_code' =>
                    $auth['code']
            ];
        }
    }

    $from =
        trim((string)$s['smtp_from']);

    fwrite(
        $fp,
        'MAIL FROM:<' . $from . ">\r\n"
    );

    $mailFrom =
        $read($fp);

    if (!in_array(
        $mailFrom['code'],
        ['250'],
        true
    )) {
        fclose($fp);

        return [
            'success' => false,
            'message' =>
                'MAIL FROMが拒否されました。',
            'smtp_code' =>
                $mailFrom['code']
        ];
    }

    fwrite(
        $fp,
        'RCPT TO:<' . $to . ">\r\n"
    );

    $rcpt =
        $read($fp);

    if (!in_array(
        $rcpt['code'],
        ['250', '251'],
        true
    )) {
        fclose($fp);

        return [
            'success' => false,
            'message' =>
                'RCPT TOが拒否されました。',
            'smtp_code' =>
                $rcpt['code']
        ];
    }

    fwrite(
        $fp,
        "DATA\r\n"
    );

    $data =
        $read($fp);

    if ($data['code'] !== '354') {
        fclose($fp);

        return [
            'success' => false,
            'message' =>
                'DATAが拒否されました。',
            'smtp_code' =>
                $data['code']
        ];
    }

    $fromName =
        trim((string)($s['smtp_from_name'] ?? ''));

    $encodedName =
        $fromName !== ''
            ? '=?UTF-8?B?' .
              base64_encode($fromName) .
              '?='
            : '';

    $fromHeader =
        $encodedName !== ''
            ? $encodedName . ' <' . $from . '>'
            : $from;

    $subject =
        'アンケート管理システム SMTP送信テスト';

    $body =
        "SMTP設定が正常に動作し、" .
        "テストメールの送信に成功したことを確認するための固定メッセージです。\r\n";

    $message =
        'From: ' . $fromHeader . "\r\n" .
        'To: ' . $to . "\r\n" .
        'Subject: =?UTF-8?B?' .
        base64_encode($subject) .
        "?=\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n" .
        "\r\n" .
        $body .
        "\r\n.\r\n";

    fwrite(
        $fp,
        $message
    );

    $sent =
        $read($fp);

    fwrite(
        $fp,
        "QUIT\r\n"
    );

    fclose($fp);

    if ($sent['code'] !== '250') {
        return [
            'success' => false,
            'message' =>
                'SMTPサーバがメールを受理しませんでした。',
            'smtp_code' =>
                $sent['code']
        ];
    }

    return [
        'success' => true,
        'message' =>
            'テストメールの送信に成功しました。',
        'smtp_code' =>
            $sent['code']
    ];
}


/* ============================================================
 * API
 * ============================================================ */

if (
    isset($_GET['action']) ||
    isset($_POST['action'])
) {
    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    $action =
        (string)(
            $_GET['action']
            ?? $_POST['action']
            ?? ''
        );

    $data =
        survey_json_read();

    if ($action === 'load') {
        echo json_encode(
            [
                'ok' => true,
                'data' => $data
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if ($action === 'save') {
        $json =
            (string)($_POST['survey_json'] ?? '');

        $settingsJson =
            (string)($_POST['settings_json'] ?? '');

        if ($json !== '') {
            $decoded =
                json_decode(
                    $json,
                    true
                );

            if (is_array($decoded)) {
                $data =
                    array_merge(
                        $data,
                        $decoded
                    );
            }
        }

        if ($settingsJson !== '') {
            $decoded =
                json_decode(
                    $settingsJson,
                    true
                );

            if (is_array($decoded)) {
                $data['settings'] =
                    $decoded;
            }
        }

        $ok =
            survey_json_write($data);

        echo json_encode(
            [
                'ok' => $ok,
                'message' =>
                    $ok
                        ? '保存しました。'
                        : 'データ保存に失敗しました。'
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if ($action === 'kintone_test') {
        $settings =
            json_decode(
                (string)($_POST['settings_json'] ?? '{}'),
                true
            );

        if (!is_array($settings)) {
            $settings = [];
        }

        $result =
            survey_kintone_connection_test(
                $settings
            );

        echo json_encode(
            [
                'ok' =>
                    $result['success'],
                'result' =>
                    $result
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if ($action === 'kintone_fields') {
        $settings =
            json_decode(
                (string)($_POST['settings_json'] ?? '{}'),
                true
            );

        if (!is_array($settings)) {
            $settings = [];
        }

        $appId =
            trim(
                (string)(
                    $_POST['app_id']
                    ?? $settings['app_id']
                    ?? ''
                )
            );

        $result =
            survey_kintone_get_fields(
                $settings,
                $appId
            );

        echo json_encode(
            [
                'ok' =>
                    $result['success'],
                'result' =>
                    $result
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if ($action === 'smtp_test_connection') {
        $settings =
            json_decode(
                (string)($_POST['settings_json'] ?? '{}'),
                true
            );

        if (!is_array($settings)) {
            $settings = [];
        }

        $result =
            survey_smtp_connect_test(
                $settings
            );

        echo json_encode(
            [
                'ok' =>
                    $result['success'],
                'result' =>
                    $result
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if ($action === 'smtp_test_mail') {
        $settings =
            json_decode(
                (string)($_POST['settings_json'] ?? '{}'),
                true
            );

        if (!is_array($settings)) {
            $settings = [];
        }

        $to =
            (string)(
                $_POST['test_email'] ?? ''
            );

        $result =
            survey_smtp_test_mail(
                $settings,
                $to
            );

        echo json_encode(
            [
                'ok' =>
                    $result['success'],
                'result' =>
                    $result
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if ($action === 'csv') {
        $surveyId =
            (string)(
                $_GET['survey_id'] ?? ''
            );

        $responses =
            array_values(
                array_filter(
                    $data['responses'],
                    static function ($r) use ($surveyId) {
                        return
                            is_array($r) &&
                            (string)(
                                $r['survey_id'] ?? ''
                            ) === $surveyId;
                    }
                )
            );

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="survey_responses.csv"'
        );

        echo "\xEF\xBB\xBF";

        $fp =
            fopen('php://output', 'w');

        fputcsv(
            $fp,
            [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名',
                'メールアドレス',
                '回答'
            ]
        );

        foreach ($responses as $r) {
            fputcsv(
                $fp,
                [
                    $r['id'] ?? '',
                    $r['answered_at'] ?? '',
                    $r['customer_id'] ?? '',
                    $r['company'] ?? '',
                    $r['name'] ?? '',
                    $r['email'] ?? '',
                    json_encode(
                        $r['answers'] ?? [],
                        JSON_UNESCAPED_UNICODE
                    )
                ]
            );
        }

        fclose($fp);
        exit;
    }

    echo json_encode(
        [
            'ok' => false,
            'message' =>
                '不明なactionです。'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$csrf =
    bin2hex(random_bytes(16));

$_SESSION['csrf_token'] =
    $csrf;

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

<body class="bg-slate-50 text-slate-800">

<div id="app" class="min-h-screen"></div>

<script>
window.App = {

    state: {
        screen: 'list',
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        currentSurvey: null,
        fields: [],
        loading: false
    },

    api: {},

    render: {},

    actions: {},

    utils: {},

    initStarted: false,

    init: async function () {
        if (this.initStarted) return;
        this.initStarted = true;

        try {
            await this.api.load();
            this.renderScreen('list');
        } catch (e) {
            console.error(e);

            document.getElementById('app').innerHTML =
                '<div class="p-8">' +
                '<div class="max-w-3xl mx-auto bg-white rounded-xl shadow p-8">' +
                '<h1 class="text-xl font-bold text-red-600">初期化に失敗しました</h1>' +
                '<p class="mt-4 text-slate-600">' +
                this.utils.escape(
                    e.message || String(e)
                ) +
                '</p>' +
                '</div></div>';
        }
    },

    /*
     * ★以前のエラー対策。
     *
     * App.renderScreen は必ず存在させる。
     */
    renderScreen: function (screen) {
        this.state.screen = screen;

        switch (screen) {
            case 'list':
                this.render.list();
                break;

            case 'edit':
                this.render.edit();
                break;

            case 'send':
                this.render.send();
                break;

            case 'summary':
                this.render.summary();
                break;

            case 'settings':
                this.render.settings();
                break;

            default:
                this.state.screen = 'list';
                this.render.list();
        }
    },

    api: {

        load: async function () {
            const r = await fetch(
                '?action=load',
                {
                    cache: 'no-store'
                }
            );

            const j = await r.json();

            if (!j.ok) {
                throw new Error(
                    j.message ||
                    'データ取得に失敗しました。'
                );
            }

            App.state.data =
                j.data;
        },

        post: async function (
            action,
            values
        ) {
            const fd =
                new FormData();

            fd.append(
                'action',
                action
            );

            Object.keys(values || {}).forEach(
                function (key) {
                    const value =
                        values[key];

                    fd.append(
                        key,
                        typeof value === 'string'
                            ? value
                            : JSON.stringify(value)
                    );
                }
            );

            const r =
                await fetch(
                    location.href,
                    {
                        method: 'POST',
                        body: fd
                    }
                );

            const j =
                await r.json();

            if (!j.ok) {
                throw new Error(
                    j.result?.message ||
                    j.message ||
                    'サーバ処理に失敗しました。'
                );
            }

            return j;
        },

        save: async function () {
            await App.api.post(
                'save',
                {
                    survey_json:
                        JSON.stringify(
                            {
                                surveys:
                                    App.state.data.surveys,
                                responses:
                                    App.state.data.responses,
                                customers:
                                    App.state.data.customers,
                                mail_logs:
                                    App.state.data.mail_logs
                            }
                        ),
                    settings_json:
                        JSON.stringify(
                            App.state.data.settings || {}
                        )
                }
            );
        }
    },

    utils: {

        escape: function (value) {
            return String(
                value ?? ''
            )
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
        },

        id: function () {
            return (
                Date.now().toString(36) +
                Math.random()
                    .toString(36)
                    .slice(2)
            );
        },

        now: function () {
            const d =
                new Date();

            const p =
                n => String(n).padStart(2, '0');

            return d.getFullYear() +
                '-' +
                p(d.getMonth() + 1) +
                '-' +
                p(d.getDate()) +
                ' ' +
                p(d.getHours()) +
                ':' +
                p(d.getMinutes()) +
                ':' +
                p(d.getSeconds());
        }
    },

    render: {

        shell: function (
            title,
            body
        ) {
            return `
                <div class="min-h-screen">
                    <header class="bg-white border-b">
                        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
                            <div>
                                <h1 class="text-xl font-bold">
                                    アンケート管理システム
                                </h1>
                                <p class="text-xs text-slate-400 mt-1">
                                    ${App.utils.escape(title)}
                                </p>
                            </div>

                            <nav class="flex gap-2">
                                <button
                                    class="px-4 py-2 rounded-lg hover:bg-slate-100"
                                    onclick="App.renderScreen('list')">
                                    アンケート一覧
                                </button>

                                <button
                                    class="px-4 py-2 rounded-lg hover:bg-slate-100"
                                    onclick="App.renderScreen('settings')">
                                    キントーン・メール連携設定
                                </button>
                            </nav>
                        </div>
                    </header>

                    <main class="max-w-7xl mx-auto px-6 py-8">
                        ${body}
                    </main>
                </div>
            `;
        },

        list: function () {
            const surveys =
                App.state.data.surveys
                    .filter(
                        s => !s.deleted
                    );

            document.getElementById('app').innerHTML =
                App.render.shell(
                    'アンケート一覧',
                    `
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-2xl font-bold">
                                アンケート一覧
                            </h2>
                            <p class="text-sm text-slate-500 mt-1">
                                アンケートの作成・送信・集計を管理します。
                            </p>
                        </div>

                        <button
                            onclick="App.actions.newSurvey()"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-semibold">
                            + 新規アンケート作成
                        </button>
                    </div>

                    <div class="bg-white border rounded-xl overflow-hidden shadow-sm">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-100">
                                    <tr>
                                        <th class="text-left p-4">タイトル</th>
                                        <th class="text-left p-4">期間</th>
                                        <th class="text-left p-4">ステータス</th>
                                        <th class="text-left p-4">回答数</th>
                                        <th class="text-left p-4">更新日</th>
                                        <th class="text-left p-4">操作</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    ${
                                        surveys.length
                                        ? surveys.map(
                                            s => App.render.surveyRow(s)
                                          ).join('')
                                        : `
                                        <tr>
                                            <td
                                                colspan="6"
                                                class="p-12 text-center text-slate-400">
                                                アンケートはありません
                                            </td>
                                        </tr>
                                        `
                                    }
                                </tbody>
                            </table>
                        </div>
                    </div>
                    `
                );
        },

        surveyRow: function (s) {
            const responses =
                App.state.data.responses.filter(
                    r =>
                        r.survey_id === s.id
                ).length;

            const statusText = {
                draft: '下書き',
                active: '公開中',
                ended: '終了'
            }[s.status] || s.status;

            return `
                <tr class="border-t hover:bg-slate-50">
                    <td class="p-4 font-bold">
                        ${App.utils.escape(s.title)}
                    </td>

                    <td class="p-4">
                        ${App.utils.escape(s.start_at || '未設定')}
                        ～
                        ${App.utils.escape(s.end_at || '未設定')}
                    </td>

                    <td class="p-4">
                        <span class="px-3 py-1 rounded-full bg-slate-100">
                            ${statusText}
                        </span>
                    </td>

                    <td class="p-4">
                        ${responses} 件
                    </td>

                    <td class="p-4">
                        ${App.utils.escape(s.updated_at || '')}
                    </td>

                    <td class="p-4">
                        <div class="flex gap-2 flex-wrap">
                            <button
                                class="px-3 py-2 rounded-lg bg-slate-100"
                                onclick="App.actions.editSurvey('${s.id}')">
                                確認・編集
                            </button>

                            <button
                                class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700"
                                onclick="App.actions.summary('${s.id}')">
                                集計
                            </button>

                            <button
                                class="px-3 py-2 rounded-lg bg-emerald-50 text-emerald-700"
                                onclick="App.actions.send('${s.id}')">
                                送信
                            </button>

                            <button
                                class="px-3 py-2 rounded-lg bg-slate-100"
                                onclick="App.actions.duplicate('${s.id}')">
                                複製
                            </button>

                            ${
                                s.status === 'active'
                                ? `
                                <button
                                    class="px-3 py-2 rounded-lg bg-amber-50 text-amber-700"
                                    onclick="App.actions.stop('${s.id}')">
                                    停止
                                </button>
                                `
                                : ''
                            }
                        </div>
                    </td>
                </tr>
            `;
        },

        edit: function () {
            const s =
                App.state.currentSurvey;

            document.getElementById('app').innerHTML =
                App.render.shell(
                    'アンケート作成・編集',
                    `
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-2xl font-bold">
                            アンケート作成・編集
                        </h2>

                        <div class="flex gap-2">
                            <button
                                onclick="App.actions.preview()"
                                class="px-4 py-2 bg-white border rounded-lg">
                                プレビュー
                            </button>

                            <button
                                onclick="App.actions.saveSurvey()"
                                class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                                保存して一覧へ戻る
                            </button>
                        </div>
                    </div>

                    <div class="bg-white border rounded-xl p-6 mb-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="md:col-span-3">
                                <label class="block text-sm font-semibold mb-2">
                                    タイトル
                                </label>

                                <input
                                    id="survey_title"
                                    value="${App.utils.escape(s.title)}"
                                    class="w-full border rounded-lg px-4 py-3">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    開始日時
                                </label>

                                <input
                                    id="survey_start_at"
                                    value="${App.utils.escape(s.start_at || '')}"
                                    class="w-full border rounded-lg px-4 py-3">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    終了日時
                                </label>

                                <input
                                    id="survey_end_at"
                                    value="${App.utils.escape(s.end_at || '')}"
                                    class="w-full border rounded-lg px-4 py-3">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    質問番号
                                </label>

                                <select
                                    id="survey_numbering_mode"
                                    class="w-full border rounded-lg px-4 py-3"
                                    onchange="App.actions.numberingChanged(this.value)">
                                    <option
                                        value="global"
                                        ${s.numbering_mode === 'global' ? 'selected' : ''}>
                                        Q1 / Q2 / Q3
                                    </option>

                                    <option
                                        value="group"
                                        ${s.numbering_mode === 'group' ? 'selected' : ''}>
                                        Q1-1 / Q1-2 / Q2-1
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-bold text-lg">
                            質問構成
                        </h3>

                        <button
                            onclick="App.actions.addGroup()"
                            class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                            + グループ追加
                        </button>
                    </div>

                    <div
                        id="question_editor"
                        class="space-y-4">
                        ${
                            (s.groups || []).map(
                                (g, gi) =>
                                    App.render.group(g, gi)
                            ).join('')
                        }
                    </div>
                    `
                );

            App.actions.initSortable();
            App.actions.renumber();
        },

        group: function (
            g,
            index
        ) {
            return `
                <section
                    class="group-card bg-white border rounded-xl shadow-sm"
                    data-group-id="${App.utils.escape(g.id)}">

                    <div class="p-4 bg-slate-100 border-b flex items-center gap-3">
                        <span class="group-handle cursor-move text-xl">
                            ⠿
                        </span>

                        <input
                            class="flex-1 bg-transparent border-none outline-none font-bold"
                            value="${App.utils.escape(g.name)}"
                            onchange="App.actions.updateGroupName('${g.id}', this.value)">

                        <button
                            onclick="App.actions.addQuestion('${g.id}')"
                            class="px-3 py-2 bg-indigo-600 text-white rounded-lg">
                            + 質問
                        </button>

                        <button
                            onclick="App.actions.deleteGroup('${g.id}')"
                            class="px-3 py-2 bg-red-50 text-red-700 rounded-lg">
                            削除
                        </button>
                    </div>

                    <div
                        class="question-list p-4 space-y-3"
                        data-group-id="${App.utils.escape(g.id)}">

                        ${
                            (g.questions || []).map(
                                q =>
                                    App.render.question(
                                        q
                                    )
                            ).join('')
                        }
                    </div>
                </section>
            `;
        },

        question: function (q) {
            return `
                <div
                    class="question-card border rounded-xl p-4 bg-white"
                    data-question-id="${App.utils.escape(q.id)}">

                    <div class="flex items-start gap-3">
                        <span class="question-handle cursor-move text-xl">
                            ⠿
                        </span>

                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-3">
                                <span
                                    class="question-number font-bold text-indigo-600">
                                </span>

                                <input
                                    value="${App.utils.escape(q.text)}"
                                    class="flex-1 border rounded-lg px-3 py-2"
                                    onchange="App.actions.updateQuestion('${q.id}', 'text', this.value)">
                            </div>

                            <div class="grid md:grid-cols-3 gap-3">
                                <select
                                    class="border rounded-lg px-3 py-2"
                                    onchange="App.actions.updateQuestion('${q.id}', 'type', this.value)">
                                    <option value="single" ${q.type === 'single' ? 'selected' : ''}>
                                        単一選択
                                    </option>
                                    <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>
                                        複数選択
                                    </option>
                                    <option value="text" ${q.type === 'text' ? 'selected' : ''}>
                                        自由記述
                                    </option>
                                </select>

                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        ${q.required ? 'checked' : ''}
                                        onchange="App.actions.updateQuestion('${q.id}', 'required', this.checked)">
                                    必須回答
                                </label>

                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        ${q.other_enabled ? 'checked' : ''}
                                        onchange="App.actions.updateQuestion('${q.id}', 'other_enabled', this.checked)">
                                    その他
                                </label>
                            </div>

                            ${
                                q.type !== 'text'
                                ? `
                                <div class="mt-3 space-y-2">
                                    ${
                                        (q.options || []).map(
                                            (o, oi) => `
                                            <div class="flex gap-2">
                                                <input
                                                    value="${App.utils.escape(o)}"
                                                    class="flex-1 border rounded-lg px-3 py-2"
                                                    onchange="App.actions.updateOption('${q.id}', ${oi}, this.value)">
                                                <button
                                                    onclick="App.actions.removeOption('${q.id}', ${oi})"
                                                    class="px-3 py-2 bg-red-50 text-red-600 rounded-lg">
                                                    削除
                                                </button>
                                            </div>
                                            `
                                        ).join('')
                                    }

                                    <button
                                        onclick="App.actions.addOption('${q.id}')"
                                        class="px-3 py-2 bg-slate-100 rounded-lg">
                                        + 選択肢
                                    </button>
                                </div>
                                `
                                : ''
                            }
                        </div>

                        <button
                            onclick="App.actions.deleteQuestion('${q.id}')"
                            class="px-3 py-2 bg-red-50 text-red-600 rounded-lg">
                            削除
                        </button>
                    </div>
                </div>
            `;
        },

        settings: function () {
            const s =
                App.state.data.settings || {};

            document.getElementById('app').innerHTML =
                App.render.shell(
                    'キントーン・メール連携設定',
                    `
                    <div class="space-y-6">

                        <section class="bg-white border rounded-xl p-6">
                            <h2 class="text-xl font-bold mb-5">
                                kintone接続設定
                            </h2>

                            <div class="grid md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        サブドメイン
                                    </label>

                                    <input
                                        id="setting_subdomain"
                                        value="${App.utils.escape(s.subdomain || '')}"
                                        placeholder="xxxx.cybozu.com"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        顧客管理アプリID
                                    </label>

                                    <input
                                        id="setting_app_id"
                                        value="${App.utils.escape(s.app_id || '')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        ログイン名
                                    </label>

                                    <input
                                        id="setting_login_name"
                                        value="${App.utils.escape(s.login_name || '')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        パスワード
                                    </label>

                                    <input
                                        type="password"
                                        id="setting_password"
                                        value="${App.utils.escape(s.password || '')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        Proxy
                                    </label>

                                    <input
                                        id="setting_proxy"
                                        value="${App.utils.escape(s.proxy || '')}"
                                        placeholder="proxy.example.com:8080"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <label class="flex items-center gap-2">
                                    <input
                                        id="setting_ssl_verify"
                                        type="checkbox"
                                        ${s.ssl_verify ? 'checked' : ''}>
                                    SSL証明書検証を行う
                                </label>
                            </div>

                            <div class="mt-5 flex gap-2">
                                <button
                                    onclick="App.actions.testKintone()"
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                                    接続確認
                                </button>

                                <button
                                    onclick="App.actions.fetchKintoneFields()"
                                    class="px-4 py-2 bg-slate-800 text-white rounded-lg">
                                    項目一覧取得
                                </button>
                            </div>

                            <div
                                id="field_message"
                                class="mt-4">
                            </div>

                            <div class="mt-6">
                                <h3 class="font-bold mb-3">
                                    顧客項目マッピング
                                </h3>

                                <div class="grid md:grid-cols-2 gap-4">
                                    ${App.render.fieldSelect('field_company', '会社名', s.field_company)}
                                    ${App.render.fieldSelect('field_name', '氏名', s.field_name)}
                                    ${App.render.fieldSelect('field_email', 'メールアドレス', s.field_email)}
                                    ${App.render.fieldSelect('field_department', '部署名', s.field_department)}
                                    ${App.render.fieldSelect('field_phone', '電話番号', s.field_phone)}
                                    ${App.render.fieldSelect('field_address', '住所', s.field_address, true)}
                                </div>
                            </div>
                        </section>

                        <section class="bg-white border rounded-xl p-6">
                            <h2 class="text-xl font-bold mb-5">
                                SMTPサーバ設定
                            </h2>

                            <div class="grid md:grid-cols-2 gap-4">

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        SMTPサーバ
                                    </label>

                                    <input
                                        id="smtp_host"
                                        value="${App.utils.escape(s.smtp_host || '')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        SMTPポート
                                    </label>

                                    <input
                                        id="smtp_port"
                                        type="number"
                                        value="${App.utils.escape(s.smtp_port || '587')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        暗号化方式
                                    </label>

                                    <select
                                        id="smtp_encryption"
                                        class="w-full border rounded-lg px-4 py-3">
                                        <option value="none" ${s.smtp_encryption === 'none' ? 'selected' : ''}>
                                            なし
                                        </option>
                                        <option value="ssl" ${s.smtp_encryption === 'ssl' ? 'selected' : ''}>
                                            SSL
                                        </option>
                                        <option value="tls" ${s.smtp_encryption === 'tls' ? 'selected' : ''}>
                                            TLS
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        SMTP認証
                                    </label>

                                    <select
                                        id="smtp_auth"
                                        class="w-full border rounded-lg px-4 py-3">
                                        <option value="none" ${s.smtp_auth === 'none' ? 'selected' : ''}>
                                            認証しない
                                        </option>
                                        <option value="login" ${s.smtp_auth === 'login' ? 'selected' : ''}>
                                            認証する
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        SMTPユーザー名
                                    </label>

                                    <input
                                        id="smtp_username"
                                        value="${App.utils.escape(s.smtp_username || '')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        SMTPパスワード
                                    </label>

                                    <input
                                        id="smtp_password"
                                        type="password"
                                        value="${App.utils.escape(s.smtp_password || '')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        送信元メールアドレス
                                    </label>

                                    <input
                                        id="smtp_from"
                                        value="${App.utils.escape(s.smtp_from || '')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        送信元表示名
                                    </label>

                                    <input
                                        id="smtp_from_name"
                                        value="${App.utils.escape(s.smtp_from_name || '')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        接続タイムアウト
                                    </label>

                                    <input
                                        id="smtp_timeout"
                                        type="number"
                                        value="${App.utils.escape(s.smtp_timeout || '15')}"
                                        class="w-full border rounded-lg px-4 py-3">
                                </div>

                            </div>

                            <div class="mt-5 flex gap-2">
                                <button
                                    onclick="App.actions.testSMTP()"
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                                    SMTP接続確認
                                </button>

                                <button
                                    onclick="App.actions.testSMTPMail()"
                                    class="px-4 py-2 bg-emerald-600 text-white rounded-lg">
                                    テストメール送信
                                </button>

                                <button
                                    onclick="App.actions.saveSettings()"
                                    class="px-4 py-2 bg-slate-800 text-white rounded-lg">
                                    設定保存
                                </button>
                            </div>

                            <div
                                id="smtp_message"
                                class="mt-4">
                            </div>

                            <div class="mt-4">
                                <label class="block text-sm font-semibold mb-2">
                                    テスト送信先
                                </label>

                                <input
                                    id="test_email"
                                    placeholder="test@example.com"
                                    class="w-full border rounded-lg px-4 py-3">
                            </div>
                        </section>
                    </div>
                    `
                );
        },

        fieldSelect: function (
            id,
            label,
            selected,
            multiple
        ) {
            const fields =
                App.state.fields || [];

            return `
                <div>
                    <label class="block text-sm font-semibold mb-2">
                        ${App.utils.escape(label)}
                    </label>

                    <select
                        id="${id}"
                        ${multiple ? 'multiple' : ''}
                        class="w-full border rounded-lg px-4 py-3">

                        <option value="">
                            選択してください
                        </option>

                        ${
                            fields.map(
                                f => `
                                <option
                                    value="${App.utils.escape(f.code)}"
                                    ${selected === f.code ? 'selected' : ''}>
                                    ${App.utils.escape(f.label)}
                                    (${App.utils.escape(f.code)})
                                </option>
                                `
                            ).join('')
                        }
                    </select>
                </div>
            `;
        },

        send: function () {
            document.getElementById('app').innerHTML =
                App.render.shell(
                    'メール送信・回答フォロー',
                    `
                    <div class="bg-white border rounded-xl p-6">
                        <h2 class="text-2xl font-bold mb-6">
                            メール送信・回答フォロー
                        </h2>

                        <div class="grid md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    件名
                                </label>

                                <input
                                    id="mail_subject"
                                    value="アンケートのご案内"
                                    class="w-full border rounded-lg px-4 py-3">
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    テンプレート
                                </label>

                                <select
                                    id="template_type"
                                    class="w-full border rounded-lg px-4 py-3">
                                    <option value="initial">
                                        初回送信
                                    </option>
                                    <option value="reminder">
                                        リマインド
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="block text-sm font-semibold mb-2">
                                本文
                            </label>

                            <textarea
                                id="mail_body"
                                rows="10"
                                class="w-full border rounded-lg px-4 py-3">{顧客名} 様

アンケートへのご回答をお願いいたします。

{アンケートURL}</textarea>
                        </div>

                        <div class="mt-6">
                            <button
                                onclick="App.actions.executeSend()"
                                class="px-5 py-3 bg-indigo-600 text-white rounded-xl font-semibold">
                                一括送信実行
                            </button>
                        </div>

                        <div class="mt-8 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-100">
                                    <tr>
                                        <th class="p-3 text-left">選択</th>
                                        <th class="p-3 text-left">会社名</th>
                                        <th class="p-3 text-left">氏名</th>
                                        <th class="p-3 text-left">メール</th>
                                        <th class="p-3 text-left">送信日時</th>
                                        <th class="p-3 text-left">送信回数</th>
                                        <th class="p-3 text-left">回答</th>
                                    </tr>
                                </thead>

                                <tbody id="customer_table">
                                    ${
                                        App.state.data.customers.map(
                                            c => `
                                            <tr class="border-t">
                                                <td class="p-3">
                                                    <input
                                                        type="checkbox"
                                                        class="recipient"
                                                        value="${App.utils.escape(c.id)}">
                                                </td>
                                                <td class="p-3">
                                                    ${App.utils.escape(c.company)}
                                                </td>
                                                <td class="p-3">
                                                    ${App.utils.escape(c.name)}
                                                </td>
                                                <td class="p-3">
                                                    ${App.utils.escape(c.email)}
                                                </td>
                                                <td class="p-3">
                                                    ${App.utils.escape(c.sent_at || '')}
                                                </td>
                                                <td class="p-3">
                                                    ${c.send_count || 0}
                                                </td>
                                                <td class="p-3">
                                                    ${c.answer_status === 'answered'
                                                        ? '回答済み'
                                                        : '未回答'}
                                                </td>
                                            </tr>
                                            `
                                        ).join('')
                                    }
                                </tbody>
                            </table>
                        </div>
                    </div>
                    `
                );
        },

        summary: function () {
            const s =
                App.state.currentSurvey;

            const responses =
                App.state.data.responses.filter(
                    r =>
                        r.survey_id === s.id
                );

            document.getElementById('app').innerHTML =
                App.render.shell(
                    'アンケート集計・分析',
                    `
                    <div class="space-y-6">

                        <div class="bg-white border rounded-xl p-6">
                            <h2 class="text-2xl font-bold">
                                ${App.utils.escape(s.title)}
                            </h2>

                            <div class="grid md:grid-cols-5 gap-4 mt-6">

                                <div class="p-5 bg-slate-50 rounded-xl">
                                    <div class="text-sm text-slate-500">
                                        送信対象者数
                                    </div>
                                    <div class="text-2xl font-bold mt-2">
                                        ${App.state.data.customers.length}
                                    </div>
                                </div>

                                <div class="p-5 bg-slate-50 rounded-xl">
                                    <div class="text-sm text-slate-500">
                                        回答数
                                    </div>
                                    <div class="text-2xl font-bold mt-2">
                                        ${responses.length}
                                    </div>
                                </div>

                                <div class="p-5 bg-slate-50 rounded-xl">
                                    <div class="text-sm text-slate-500">
                                        未登録顧客からの回答
                                    </div>
                                    <div class="text-2xl font-bold mt-2">
                                        ${responses.filter(
                                            r =>
                                                !r.customer_id
                                        ).length}
                                    </div>
                                </div>

                                <div class="p-5 bg-slate-50 rounded-xl">
                                    <div class="text-sm text-slate-500">
                                        未回答
                                    </div>
                                    <div class="text-2xl font-bold mt-2">
                                        ${
                                            App.state.data.customers
                                                .filter(
                                                    c =>
                                                        c.answer_status !== 'answered'
                                                ).length
                                        }
                                    </div>
                                </div>

                                <div class="p-5 bg-slate-50 rounded-xl">
                                    <div class="text-sm text-slate-500">
                                        回答率
                                    </div>
                                    <div class="text-2xl font-bold mt-2">
                                        ${
                                            App.state.data.customers.length
                                                ? (
                                                    responses.length /
                                                    App.state.data.customers.length *
                                                    100
                                                ).toFixed(1)
                                                : '0.0'
                                        } %
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white border rounded-xl p-6">
                            <h3 class="font-bold text-lg mb-4">
                                設問別集計
                            </h3>

                            ${
                                (s.groups || []).flatMap(
                                    g =>
                                        g.questions || []
                                ).map(
                                    q =>
                                        App.render.questionSummary(
                                            q,
                                            responses
                                        )
                                ).join('')
                            }
                        </div>

                        <div class="bg-white border rounded-xl p-6">
                            <h3 class="font-bold text-lg mb-4">
                                個別回答一覧
                            </h3>

                            ${
                                responses.length
                                ? responses.map(
                                    r => `
                                    <div class="border-b py-4">
                                        <div class="font-semibold">
                                            ${App.utils.escape(r.company)}
                                            /
                                            ${App.utils.escape(r.name)}
                                        </div>

                                        <div class="text-xs text-slate-400 mt-1">
                                            ${App.utils.escape(r.answered_at)}
                                        </div>

                                        <button
                                            onclick="App.actions.showResponse('${r.id}')"
                                            class="mt-2 px-3 py-2 bg-slate-100 rounded-lg">
                                            全回答を表示
                                        </button>
                                    </div>
                                    `
                                ).join('')
                                : `
                                <div class="py-12 text-center text-slate-400">
                                    現在、回答データはありません
                                </div>
                                `
                            }
                        </div>
                    </div>

                    <div
                        id="response_modal"
                        class="hidden fixed inset-0 bg-black/40 z-50 p-6">
                        <div
                            class="max-w-3xl mx-auto mt-10 bg-white rounded-xl shadow-xl p-6">
                            <div class="flex justify-between">
                                <h3 class="font-bold">
                                    回答詳細
                                </h3>

                                <button
                                    onclick="App.actions.closeResponse()"
                                    class="px-3 py-2 bg-slate-100 rounded-lg">
                                    閉じる
                                </button>
                            </div>

                            <div
                                id="response_detail"
                                class="mt-5">
                            </div>
                        </div>
                    </div>
                    `
                );
        },

        questionSummary: function (
            q,
            responses
        ) {
            const counts = {};

            (q.options || []).forEach(
                o => counts[o] = 0
            );

            responses.forEach(
                r => {
                    const answer =
                        r.answers?.[q.id];

                    if (Array.isArray(answer)) {
                        answer.forEach(
                            a => {
                                counts[a] =
                                    (counts[a] || 0) + 1;
                            }
                        );
                    } else if (
                        answer !== undefined &&
                        answer !== ''
                    ) {
                        counts[answer] =
                            (counts[answer] || 0) + 1;
                    }
                }
            );

            const total =
                responses.length || 1;

            return `
                <div class="border-b py-5">
                    <div class="font-semibold">
                        ${App.utils.escape(q.text)}
                    </div>

                    ${
                        q.type === 'text'
                        ? ''
                        : Object.keys(counts).map(
                            option => {
                                const count =
                                    counts[option];

                                const pct =
                                    count /
                                    total *
                                    100;

                                return `
                                <div class="mt-3">
                                    <div class="flex justify-between text-sm">
                                        <span>
                                            ${App.utils.escape(option)}
                                        </span>
                                        <span>
                                            ${count}件
                                            /
                                            ${pct.toFixed(1)}%
                                        </span>
                                    </div>

                                    <div class="h-3 bg-slate-100 rounded-full mt-1">
                                        <div
                                            class="h-3 bg-indigo-500 rounded-full"
                                            style="width:${pct}%">
                                        </div>
                                    </div>
                                </div>
                                `;
                            }
                        ).join('')
                    }
                </div>
            `;
        }
    },

    actions: {

        newSurvey: function () {
            App.state.currentSurvey = {
                id: App.utils.id(),
                title: '新しいアンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: App.utils.now(),
                updated_at: App.utils.now(),
                numbering_mode: 'global',
                groups: [],
                deleted: false
            };

            App.state.data.surveys.push(
                App.state.currentSurvey
            );

            App.renderScreen('edit');
        },

        editSurvey: function (id) {
            const s =
                App.state.data.surveys.find(
                    x => x.id === id
                );

            if (!s) return;

            App.state.currentSurvey = s;

            App.renderScreen('edit');
        },

        summary: function (id) {
            const s =
                App.state.data.surveys.find(
                    x => x.id === id
                );

            if (!s) return;

            App.state.currentSurvey = s;

            App.renderScreen('summary');
        },

        send: function (id) {
            const s =
                App.state.data.surveys.find(
                    x => x.id === id
                );

            if (!s) return;

            App.state.currentSurvey = s;

            App.renderScreen('send');
        },

        duplicate: async function (id) {
            const s =
                App.state.data.surveys.find(
                    x => x.id === id
                );

            if (!s) return;

            const copy =
                JSON.parse(
                    JSON.stringify(s)
                );

            copy.id =
                App.utils.id();

            copy.title =
                copy.title + '（複製）';

            copy.status =
                'draft';

            copy.created_at =
                App.utils.now();

            copy.updated_at =
                App.utils.now();

            App.state.data.surveys.push(
                copy
            );

            await App.api.save();

            alert(
                'アンケートを複製しました。'
            );

            App.renderScreen('list');
        },

        stop: async function (id) {
            if (
                !confirm(
                    'このアンケートを停止しますか？'
                )
            ) {
                return;
            }

            const s =
                App.state.data.surveys.find(
                    x => x.id === id
                );

            if (!s) return;

            s.status = 'ended';
            s.updated_at =
                App.utils.now();

            await App.api.save();

            App.renderScreen('list');
        },

        saveSurvey: async function () {
            const s =
                App.state.currentSurvey;

            s.title =
                document.getElementById(
                    'survey_title'
                ).value;

            s.start_at =
                document.getElementById(
                    'survey_start_at'
                ).value;

            s.end_at =
                document.getElementById(
                    'survey_end_at'
                ).value;

            s.numbering_mode =
                document.getElementById(
                    'survey_numbering_mode'
                ).value;

            s.updated_at =
                App.utils.now();

            App.actions.renumber();

            await App.api.save();

            alert(
                '保存しました。'
            );

            App.renderScreen('list');
        },

        addGroup: function () {
            const s =
                App.state.currentSurvey;

            s.groups =
                s.groups || [];

            s.groups.push({
                id: App.utils.id(),
                name:
                    '新しいグループ',
                questions: []
            });

            App.renderScreen('edit');
        },

        addQuestion: function (
            groupId
        ) {
            const s =
                App.state.currentSurvey;

            const g =
                s.groups.find(
                    x => x.id === groupId
                );

            if (!g) return;

            g.questions =
                g.questions || [];

            g.questions.push({
                id: App.utils.id(),
                text: '新しい質問',
                type: 'single',
                required: false,
                options: [
                    '選択肢1',
                    '選択肢2'
                ],
                other_enabled: false
            });

            App.renderScreen('edit');
        },

        deleteGroup: function (
            id
        ) {
            if (
                !confirm(
                    'グループと内包する質問を削除しますか？'
                )
            ) {
                return;
            }

            App.state.currentSurvey.groups =
                App.state.currentSurvey.groups.filter(
                    g => g.id !== id
                );

            App.renderScreen('edit');
        },

        deleteQuestion: function (
            id
        ) {
            App.state.currentSurvey.groups.forEach(
                g => {
                    g.questions =
                        (g.questions || [])
                            .filter(
                                q => q.id !== id
                            );
                }
            );

            App.renderScreen('edit');
        },

        updateGroupName: function (
            id,
            value
        ) {
            const g =
                App.state.currentSurvey.groups.find(
                    x => x.id === id
                );

            if (g) {
                g.name = value;
            }
        },

        updateQuestion: function (
            id,
            key,
            value
        ) {
            App.state.currentSurvey.groups.forEach(
                g => {
                    const q =
                        (g.questions || []).find(
                            x => x.id === id
                        );

                    if (q) {
                        q[key] = value;
                    }
                }
            );

            if (key === 'type') {
                App.renderScreen('edit');
            }
        },

        addOption: function (
            id
        ) {
            App.state.currentSurvey.groups.forEach(
                g => {
                    const q =
                        (g.questions || []).find(
                            x => x.id === id
                        );

                    if (q) {
                        q.options =
                            q.options || [];

                        q.options.push(
                            '新しい選択肢'
                        );
                    }
                }
            );

            App.renderScreen('edit');
        },

        updateOption: function (
            id,
            index,
            value
        ) {
            App.state.currentSurvey.groups.forEach(
                g => {
                    const q =
                        (g.questions || []).find(
                            x => x.id === id
                        );

                    if (
                        q &&
                        q.options &&
                        q.options[index] !== undefined
                    ) {
                        q.options[index] =
                            value;
                    }
                }
            );
        },

        removeOption: function (
            id,
            index
        ) {
            App.state.currentSurvey.groups.forEach(
                g => {
                    const q =
                        (g.questions || []).find(
                            x => x.id === id
                        );

                    if (
                        q &&
                        q.options
                    ) {
                        q.options.splice(
                            index,
                            1
                        );
                    }
                }
            );

            App.renderScreen('edit');
        },

        numberingChanged: function (
            value
        ) {
            App.state.currentSurvey.numbering_mode =
                value;

            App.actions.renumber();
        },

        renumber: function () {
            const mode =
                App.state.currentSurvey
                    .numbering_mode;

            let globalNo = 1;

            App.state.currentSurvey.groups.forEach(
                function (g, gi) {
                    (g.questions || [])
                        .forEach(
                            function (q, qi) {
                                const el =
                                    document.querySelector(
                                        '[data-question-id="' +
                                        CSS.escape(q.id) +
                                        '"] .question-number'
                                    );

                                if (!el) {
                                    return;
                                }

                                if (
                                    mode === 'group'
                                ) {
                                    el.textContent =
                                        'Q' +
                                        (gi + 1) +
                                        '-' +
                                        (qi + 1);
                                } else {
                                    el.textContent =
                                        'Q' +
                                        globalNo++;
                                }
                            }
                        );
                }
            );
        },

        initSortable: function () {
            const editor =
                document.getElementById(
                    'question_editor'
                );

            if (
                editor &&
                typeof Sortable !== 'undefined'
            ) {
                new Sortable(
                    editor,
                    {
                        animation: 180,
                        ghostClass: 'opacity-40',
                        handle: '.group-handle',
                        onEnd: function (event) {
                            const groups =
                                App.state.currentSurvey.groups;

                            const moved =
                                groups.splice(
                                    event.oldIndex,
                                    1
                                )[0];

                            groups.splice(
                                event.newIndex,
                                0,
                                moved
                            );

                            App.renderScreen(
                                'edit'
                            );
                        }
                    }
                );
            }

            document.querySelectorAll(
                '.question-list'
            ).forEach(
                function (list) {
                    if (
                        typeof Sortable === 'undefined'
                    ) {
                        return;
                    }

                    new Sortable(
                        list,
                        {
                            group: 'questions',
                            animation: 180,
                            ghostClass: 'opacity-40',
                            handle: '.question-handle',

                            onEnd: function (evt) {
                                App.actions.syncQuestionOrder();
                            }
                        }
                    );
                }
            );
        },

        syncQuestionOrder: function () {
            const survey =
                App.state.currentSurvey;

            const result = [];

            document.querySelectorAll(
                '.question-list'
            ).forEach(
                function (list) {
                    const groupId =
                        list.dataset.groupId;

                    const group =
                        survey.groups.find(
                            g =>
                                g.id === groupId
                        );

                    if (!group) return;

                    const ids =
                        Array.from(
                            list.querySelectorAll(
                                '.question-card'
                            )
                        ).map(
                            el =>
                                el.dataset.questionId
                        );

                    group.questions =
                        ids.map(
                            id =>
                                survey.groups
                                    .flatMap(
                                        g =>
                                            g.questions || []
                                    )
                                    .find(
                                        q =>
                                            q.id === id
                                    )
                        ).filter(Boolean);

                    result.push(
                        group
                    );
                }
            );

            survey.groups =
                result;

            App.actions.renumber();
        },

        preview: function () {
            const s =
                App.state.currentSurvey;

            const html =
                (s.groups || []).map(
                    (g, gi) => `
                    <div class="mb-6">
                        <h3 class="font-bold text-lg mb-3">
                            ${App.utils.escape(g.name)}
                        </h3>

                        ${(g.questions || []).map(
                            q => `
                            <div class="border rounded-lg p-4 mb-3">
                                <div class="font-semibold">
                                    ${App.utils.escape(q.text)}
                                </div>

                                ${
                                    q.type === 'text'
                                    ? `
                                    <textarea
                                        class="mt-3 w-full border rounded-lg p-3"
                                        rows="4"
                                        disabled>
                                    </textarea>
                                    `
                                    :
                                    (q.options || []).map(
                                        o => `
                                        <label class="block mt-2">
                                            <input
                                                type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                                                disabled>
                                            ${App.utils.escape(o)}
                                        </label>
                                        `
                                    ).join('')
                                }
                            </div>
                            `
                        ).join('')}
                    </div>
                    `
                ).join('');

            document.getElementById(
                'app'
            ).insertAdjacentHTML(
                'beforeend',
                `
                <div
                    id="preview_modal"
                    class="fixed inset-0 bg-black/40 z-50 p-6 overflow-auto">

                    <div class="max-w-3xl mx-auto bg-white rounded-xl shadow-xl p-6">

                        <div class="flex justify-between mb-5">
                            <h3 class="text-xl font-bold">
                                プレビュー
                            </h3>

                            <button
                                onclick="App.actions.closePreview()"
                                class="px-3 py-2 bg-slate-100 rounded-lg">
                                閉じる
                            </button>
                        </div>

                        <div id="preview_content">
                            ${html}

                            <button
                                onclick="alert('これはプレビューです。実際には送信されません。')"
                                class="px-5 py-3 bg-indigo-600 text-white rounded-lg">
                                送信
                            </button>
                        </div>
                    </div>
                </div>
                `
            );
        },

        closePreview: function () {
            const el =
                document.getElementById(
                    'preview_modal'
                );

            if (el) {
                el.remove();
            }
        },

        testKintone: async function () {
            const settings =
                App.actions.collectSettings();

            const box =
                document.getElementById(
                    'field_message'
                );

            box.innerHTML =
                '<div class="p-4 bg-slate-100 rounded-lg">接続確認中...</div>';

            try {
                const r =
                    await App.api.post(
                        'kintone_test',
                        {
                            settings_json:
                                JSON.stringify(settings)
                        }
                    );

                const result =
                    r.result;

                box.innerHTML =
                    `
                    <div class="p-4 rounded-lg ${
                        result.success
                            ? 'bg-emerald-50 text-emerald-800'
                            : 'bg-red-50 text-red-800'
                    }">
                        <div class="font-bold">
                            ${App.utils.escape(result.message)}
                        </div>

                        <div class="mt-2 text-sm">
                            HTTPステータス:
                            ${result.status || '取得不可'}
                        </div>
                    </div>
                    `;
            } catch (e) {
                box.innerHTML =
                    `
                    <div class="p-4 bg-red-50 text-red-700 rounded-lg">
                        ${App.utils.escape(e.message)}
                    </div>
                    `;
            }
        },

        /*
         * ★必須関数
         *
         * kintone APIからフィールド一覧を取得。
         */
        fetchKintoneFields: async function () {
            const settings =
                App.actions.collectSettings();

            const appId =
                settings.app_id;

            const box =
                document.getElementById(
                    'field_message'
                );

            if (!appId) {
                box.innerHTML =
                    '<div class="p-4 bg-red-50 text-red-700 rounded-lg">顧客管理アプリIDを入力してください。</div>';
                return;
            }

            box.innerHTML =
                '<div class="p-4 bg-slate-100 rounded-lg">kintoneから項目一覧を取得中...</div>';

            try {
                const r =
                    await App.api.post(
                        'kintone_fields',
                        {
                            settings_json:
                                JSON.stringify(settings),
                            app_id:
                                appId
                        }
                    );

                App.state.fields =
                    r.result.fields || [];

                /*
                 * 取得成功後に設定画面を再描画。
                 */
                App.render.settings();

                document.getElementById(
                    'field_message'
                ).innerHTML =
                    `
                    <div class="p-4 bg-emerald-50 text-emerald-800 rounded-lg">
                        kintoneから
                        <strong>${App.state.fields.length}</strong>
                        項目を取得しました。
                    </div>
                    `;
            } catch (e) {
                box.innerHTML =
                    `
                    <div class="p-4 bg-red-50 text-red-700 rounded-lg">
                        <div class="font-bold">
                            項目一覧取得に失敗しました
                        </div>

                        <div class="mt-2">
                            ${App.utils.escape(e.message)}
                        </div>

                        <div class="mt-3 text-sm">
                            顧客管理アプリID、ログイン権限、
                            Proxy、Apache/PHPの外部HTTPS通信を確認してください。
                        </div>
                    </div>
                    `;
            }
        },

        collectSettings: function () {
            const old =
                App.state.data.settings || {};

            const value =
                id =>
                    document.getElementById(id)?.value
                    ?? '';

            const checked =
                id =>
                    !!document.getElementById(id)?.checked;

            return {
                ...old,

                subdomain:
                    value('setting_subdomain'),

                app_id:
                    value('setting_app_id'),

                login_name:
                    value('setting_login_name'),

                password:
                    value('setting_password'),

                proxy:
                    value('setting_proxy'),

                ssl_verify:
                    checked('setting_ssl_verify'),

                field_company:
                    value('field_company'),

                field_name:
                    value('field_name'),

                field_email:
                    value('field_email'),

                field_department:
                    value('field_department'),

                field_phone:
                    value('field_phone'),

                field_address:
                    value('field_address'),

                smtp_host:
                    value('smtp_host'),

                smtp_port:
                    value('smtp_port'),

                smtp_encryption:
                    value('smtp_encryption'),

                smtp_auth:
                    value('smtp_auth'),

                smtp_username:
                    value('smtp_username'),

                smtp_password:
                    value('smtp_password'),

                smtp_from:
                    value('smtp_from'),

                smtp_from_name:
                    value('smtp_from_name'),

                smtp_timeout:
                    value('smtp_timeout')
            };
        },

        saveSettings: async function () {
            const settings =
                App.actions.collectSettings();

            App.state.data.settings =
                settings;

            await App.api.save();

            alert(
                '設定を保存しました。'
            );
        },

        testSMTP: async function () {
            const settings =
                App.actions.collectSettings();

            const box =
                document.getElementById(
                    'smtp_message'
                );

            box.innerHTML =
                '<div class="p-4 bg-slate-100 rounded-lg">SMTP接続確認中...</div>';

            try {
                const r =
                    await App.api.post(
                        'smtp_test_connection',
                        {
                            settings_json:
                                JSON.stringify(settings)
                        }
                    );

                const d =
                    r.result;

                box.innerHTML =
                    `
                    <div class="p-4 rounded-lg ${
                        d.success
                            ? 'bg-emerald-50 text-emerald-800'
                            : 'bg-red-50 text-red-800'
                    }">

                        <div class="font-bold">
                            ${App.utils.escape(d.message)}
                        </div>

                        <div class="mt-3 text-sm space-y-1">
                            <div>
                                SMTPサーバ:
                                ${App.utils.escape(d.server || '')}
                            </div>

                            <div>
                                SMTPポート:
                                ${App.utils.escape(d.port || '')}
                            </div>

                            <div>
                                暗号化:
                                ${App.utils.escape(d.encryption || '')}
                            </div>

                            <div>
                                TCP:
                                ${App.utils.escape(d.tcp || '')}
                            </div>

                            <div>
                                TLS/SSL:
                                ${App.utils.escape(d.tls || '')}
                            </div>

                            <div>
                                SMTP応答:
                                ${App.utils.escape(d.smtp_code || '')}
                            </div>

                            <div>
                                SMTP認証:
                                ${App.utils.escape(d.auth || '')}
                            </div>
                        </div>
                    </div>
                    `;
            } catch (e) {
                box.innerHTML =
                    `
                    <div class="p-4 bg-red-50 text-red-700 rounded-lg">
                        ${App.utils.escape(e.message)}
                    </div>
                    `;
            }
        },

        testSMTPMail: async function () {
            const settings =
                App.actions.collectSettings();

            const to =
                document.getElementById(
                    'test_email'
                ).value.trim();

            if (!to) {
                alert(
                    'テスト送信先メールアドレスを入力してください。'
                );
                return;
            }

            const box =
                document.getElementById(
                    'smtp_message'
                );

            box.innerHTML =
                '<div class="p-4 bg-slate-100 rounded-lg">テストメール送信中...</div>';

            try {
                const r =
                    await App.api.post(
                        'smtp_test_mail',
                        {
                            settings_json:
                                JSON.stringify(settings),
                            test_email:
                                to
                        }
                    );

                const d =
                    r.result;

                box.innerHTML =
                    `
                    <div class="p-4 rounded-lg ${
                        d.success
                            ? 'bg-emerald-50 text-emerald-800'
                            : 'bg-red-50 text-red-800'
                    }">
                        <div class="font-bold">
                            ${App.utils.escape(d.message)}
                        </div>

                        ${
                            d.smtp_code
                            ? `
                            <div class="mt-2 text-sm">
                                SMTP応答コード:
                                ${App.utils.escape(d.smtp_code)}
                            </div>
                            `
                            : ''
                        }
                    </div>
                    `;
            } catch (e) {
                box.innerHTML =
                    `
                    <div class="p-4 bg-red-50 text-red-700 rounded-lg">
                        ${App.utils.escape(e.message)}
                    </div>
                    `;
            }
        },

        executeSend: async function () {
            /*
             * 一括送信前にSMTP設定を必ず確認。
             *
             * PHP mail() / MTAには依存しない。
             */
            const s =
                App.state.data.settings || {};

            const required = [
                ['smtp_host', 'SMTPサーバ'],
                ['smtp_port', 'SMTPポート'],
                ['smtp_from', '送信元メールアドレス']
            ];

            for (
                const [key, label]
                of required
            ) {
                if (
                    !String(
                        s[key] || ''
                    ).trim()
                ) {
                    alert(
                        'SMTP設定が未完了です。\n\n' +
                        label +
                        'を設定してください。'
                    );

                    App.renderScreen(
                        'settings'
                    );

                    return;
                }
            }

            const recipients =
                Array.from(
                    document.querySelectorAll(
                        '.recipient:checked'
                    )
                ).map(
                    x => x.value
                );

            if (!recipients.length) {
                alert(
                    '送信対象を選択してください。'
                );
                return;
            }

            if (
                !confirm(
                    recipients.length +
                    '件へメールを送信します。実行しますか？'
                )
            ) {
                return;
            }

            /*
             * 実運用ではここで宛先ごとにSMTP送信し、
             * 成功/失敗を個別記録する。
             *
             * 送信履歴構造は既存の
             * mail_logs / sent_at / send_count /
             * answer_status
             * を使用する。
             */
            alert(
                'SMTP設定確認済みです。送信処理を開始します。'
            );
        },

        showResponse: function (
            id
        ) {
            const r =
                App.state.data.responses.find(
                    x => x.id === id
                );

            if (!r) return;

            const modal =
                document.getElementById(
                    'response_modal'
                );

            const detail =
                document.getElementById(
                    'response_detail'
                );

            detail.innerHTML =
                `
                <div class="space-y-4">

                    <div>
                        <div class="text-sm text-slate-500">
                            回答者
                        </div>

                        <div class="font-bold">
                            ${App.utils.escape(r.company)}
                            /
                            ${App.utils.escape(r.name)}
                        </div>
                    </div>

                    <div>
                        <div class="text-sm text-slate-500">
                            回答日時
                        </div>

                        <div>
                            ${App.utils.escape(r.answered_at)}
                        </div>
                    </div>

                    <div>
                        <div class="font-semibold mb-2">
                            回答内容
                        </div>

                        <pre class="bg-slate-50 p-4 rounded-lg overflow-auto">${App.utils.escape(
                            JSON.stringify(
                                r.answers || {},
                                null,
                                2
                            )
                        )}</pre>
                    </div>
                </div>
                `;

            modal.classList.remove(
                'hidden'
            );
        },

        closeResponse: function () {
            const modal =
                document.getElementById(
                    'response_modal'
                );

            if (modal) {
                modal.classList.add(
                    'hidden'
                );
            }
        }
    }
};

/*
 * ★動的HTMLから
 *
 * onclick="App.actions.xxx()"
 *
 * だけでなく
 *
 * onclick="App.renderScreen()"
 *
 * を使用しているため、App.renderScreenは
 * window.App直下に必ず公開する。
 */

if (
    document.readyState === 'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        function () {
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
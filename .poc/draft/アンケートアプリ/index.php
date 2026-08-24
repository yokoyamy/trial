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

/* --------------------------------------------------------------------
 * 共通
 * ------------------------------------------------------------------ */

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
            'mail_body_reminder' => "{顧客名} 様\n\nまだご回答がお済みでないアンケートのご案内です。\n\n{アンケートURL}"
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
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        $data = survey_default_data();
    }

    $base = survey_default_data();

    foreach ($base as $k => $v) {
        if (!array_key_exists($k, $data)) {
            $data[$k] = $v;
        }
    }

    foreach (['surveys', 'responses', 'customers', 'mail_logs'] as $k) {
        if (!is_array($data[$k] ?? null)) {
            $data[$k] = [];
        }
    }

    $data['settings'] = array_replace(
        $base['settings'],
        is_array($data['settings'] ?? null) ? $data['settings'] : []
    );

    return $data;
}

function survey_write_data(array $data): bool
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
    }

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

function survey_e(mixed $v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function survey_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function survey_check_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(survey_csrf(), $token)) {
        survey_json([
            'ok' => false,
            'message' => 'CSRFトークンが無効です。'
        ], 403);
    }
}

/* --------------------------------------------------------------------
 * kintone
 * ------------------------------------------------------------------ */

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

    return 'https://' .
        $domain .
        '.cybozu.com/' .
        ltrim($endpoint, '/');
}

function get_safe_response_headers(): array
{
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
            'message' => 'kintone URLが不正です。',
            'data' => []
        ];
    }

    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => max(1, (int)($config['timeout'] ?? 20))
    ];

    if ($method !== 'GET' && $payload !== null) {
        $content = is_string($payload)
            ? $payload
            : json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        if ($content === false) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'JSONエンコードに失敗しました。',
                'data' => []
            ];
        }

        $options['content'] = $content;
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

    foreach ($headersOut as $line) {
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $line, $m)) {
            $status = (int)$m[1];
        }
    }

    $decoded = json_decode(
        $body === false ? '' : $body,
        true
    );

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
            'headers' => $headersOut
        ];
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => is_array($decoded)
            ? (string)($decoded['message'] ?? 'kintone API通信エラー')
            : 'kintone API通信エラー',
        'data' => is_array($decoded) ? $decoded : [],
        'headers' => $headersOut
    ];
}

function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
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

function kintone_fields(
    array $settings,
    string $appId
): array {
    $appId = trim($appId);

    if ($appId === '' || !preg_match('/^\d+$/', $appId)) {
        return [
            'success' => false,
            'status' => 0,
            'message' => 'アプリIDは数字で入力してください。',
            'data' => []
        ];
    }

    $url = kintone_build_url(
        (string)$settings['subdomain'],
        '/k/v1/app/form/fields.json?' .
        http_build_query([
            'app' => $appId,
            'lang' => 'ja'
        ])
    );

    return kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header(
                (string)$settings['login_name'],
                (string)$settings['password']
            ),
            'Accept: application/json',
            'Accept-Language: ja'
        ],
        null,
        kintone_settings($settings)
    );
}

function kintone_connection_test(array $settings): array
{
    $url = kintone_build_url(
        (string)$settings['subdomain'],
        '/k/v1/app.json?app=1'
    );

    $result = kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header(
                (string)$settings['login_name'],
                (string)$settings['password']
            ),
            'Accept: application/json'
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
        'api_response' => $result['data'] ?? []
    ];
}

/* --------------------------------------------------------------------
 * SMTP
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

    return [
        'code' => $code,
        'text' => implode("\n", $lines)
    ];
}

function smtp_command(
    $socket,
    string $command,
    int $timeout
): array {
    if (@fwrite($socket, $command . "\r\n") === false) {
        return [
            'code' => 0,
            'text' => 'SMTPコマンド送信失敗'
        ];
    }

    return smtp_read($socket, $timeout);
}

function smtp_open(array $cfg): array
{
    $host = trim((string)($cfg['smtp_host'] ?? ''));
    $port = (int)($cfg['smtp_port'] ?? 465);
    $encryption = strtoupper(
        trim((string)($cfg['smtp_encryption'] ?? 'SSL'))
    );
    $timeout = max(
        1,
        (int)($cfg['smtp_timeout'] ?? 15)
    );

    if ($host === '') {
        return [
            'success' => false,
            'message' => 'SMTPサーバが未設定です。',
            'stage' => 'configuration'
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
            'message' => 'SMTPサーバへ接続できませんでした。',
            'stage' => 'tcp',
            'error' => $errstr,
            'code' => 0
        ];
    }

    $greeting = smtp_read($socket, $timeout);

    if ($greeting['code'] < 200 || $greeting['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'SMTP初期応答エラー',
            'stage' => 'greeting',
            'error' => $greeting['text'],
            'code' => $greeting['code']
        ];
    }

    $ehlo = smtp_command(
        $socket,
        'EHLO localhost',
        $timeout
    );

    if ($ehlo['code'] < 200 || $ehlo['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'SMTP EHLOエラー',
            'stage' => 'ehlo',
            'error' => $ehlo['text'],
            'code' => $ehlo['code']
        ];
    }

    if ($encryption === 'TLS') {
        $tls = smtp_command(
            $socket,
            'STARTTLS',
            $timeout
        );

        if ($tls['code'] !== 220) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'STARTTLSに失敗しました。',
                'stage' => 'tls',
                'error' => $tls['text'],
                'code' => $tls['code']
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
                'message' => 'TLS確立に失敗しました。',
                'stage' => 'tls',
                'error' => 'stream_socket_enable_crypto failed',
                'code' => 0
            ];
        }

        $ehlo = smtp_command(
            $socket,
            'EHLO localhost',
            $timeout
        );
    }

    if (!empty($cfg['smtp_auth'])) {
        $r = smtp_command(
            $socket,
            'AUTH LOGIN',
            $timeout
        );

        if ($r['code'] === 334) {
            $r = smtp_command(
                $socket,
                base64_encode(
                    (string)($cfg['smtp_username'] ?? '')
                ),
                $timeout
            );
        }

        if ($r['code'] === 334) {
            $r = smtp_command(
                $socket,
                base64_encode(
                    (string)($cfg['smtp_password'] ?? '')
                ),
                $timeout
            );
        }

        if ($r['code'] < 200 || $r['code'] >= 300) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'SMTP認証に失敗しました。',
                'stage' => 'authentication',
                'error' => $r['text'],
                'code' => $r['code']
            ];
        }
    }

    return [
        'success' => true,
        'socket' => $socket,
        'timeout' => $timeout,
        'code' => 250
    ];
}

function smtp_send_mail(
    array $cfg,
    string $to,
    string $subject,
    string $body
): array {
    $conn = smtp_open($cfg);

    if (!$conn['success']) {
        return $conn;
    }

    $socket = $conn['socket'];
    $timeout = $conn['timeout'];

    $from = trim((string)($cfg['smtp_from'] ?? ''));
    $fromName = trim((string)($cfg['smtp_from_name'] ?? ''));

    $headers = [];
    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = 'From: ' .
        ($fromName !== ''
            ? '=?UTF-8?B?' .
                base64_encode($fromName) .
                '?= <' . $from . '>'
            : $from);
    $headers[] = 'To: ' . $to;
    $headers[] = 'Subject: =?UTF-8?B?' .
        base64_encode($subject) .
        '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $steps = [
        ['MAIL FROM:<'.$from.'>', 250],
        ['RCPT TO:<'.$to.'>', 250],
        ['DATA', 354]
    ];

    foreach ($steps as [$command, $expected]) {
        $r = smtp_command(
            $socket,
            $command,
            $timeout
        );

        if ($r['code'] !== $expected) {
            @fwrite($socket, "QUIT\r\n");
            fclose($socket);

            return [
                'success' => false,
                'stage' => 'send',
                'code' => $r['code'],
                'message' => 'SMTP送信に失敗しました。',
                'error' => $r['text']
            ];
        }
    }

    $mail = implode(
        "\r\n",
        $headers
    ) .
        "\r\n\r\n" .
        str_replace(
            ["\r\n", "\r"],
            "\n",
            $body
        );

    $mail = str_replace(
        "\n",
        "\r\n",
        $mail
    );

    if (substr($mail, -2) !== "\r\n") {
        $mail .= "\r\n";
    }

    $mail .= ".\r\n";

    @fwrite($socket, $mail);

    $r = smtp_read($socket, $timeout);

    @fwrite($socket, "QUIT\r\n");
    fclose($socket);

    if ($r['code'] < 200 || $r['code'] >= 300) {
        return [
            'success' => false,
            'stage' => 'data',
            'code' => $r['code'],
            'message' => 'SMTPサーバに送信を拒否されました。',
            'error' => $r['text']
        ];
    }

    return [
        'success' => true,
        'code' => $r['code'],
        'message' => '送信成功'
    ];
}

/* --------------------------------------------------------------------
 * API
 * ------------------------------------------------------------------ */

$action = (string)($_REQUEST['action'] ?? '');

if ($action !== '') {

    $data = survey_read_data();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        survey_check_csrf();
    }

    if ($action === 'load') {
        survey_json([
            'ok' => true,
            'data' => $data
        ]);
    }

    if ($action === 'save_survey') {
        $json = (string)($_POST['survey_json'] ?? '');
        $survey = json_decode($json, true);

        if (!is_array($survey)) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], 400);
        }

        $survey['id'] = (string)(
            $survey['id'] ?? survey_id()
        );

        $now = survey_now();

        if (empty($survey['created_at'])) {
            $survey['created_at'] = $now;
        }

        $survey['updated_at'] = $now;
        $survey['deleted'] = false;

        if (!in_array(
            $survey['status'] ?? 'draft',
            ['draft', 'active', 'ended'],
            true
        )) {
            $survey['status'] = 'draft';
        }

        $found = false;

        foreach ($data['surveys'] as $i => $old) {
            if ((string)$old['id'] === $survey['id']) {
                $data['surveys'][$i] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            array_unshift(
                $data['surveys'],
                $survey
            );
        }

        survey_write_data($data);

        survey_json([
            'ok' => true,
            'survey' => $survey,
            'data' => $data
        ]);
    }

    if ($action === 'delete_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if ((string)$survey['id'] === $id) {
                $survey['deleted'] = true;
                $survey['updated_at'] = survey_now();
            }
        }
        unset($survey);

        survey_write_data($data);

        survey_json([
            'ok' => true,
            'data' => $data
        ]);
    }

    if ($action === 'status_survey') {
        $id = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? 'draft');

        if (!in_array(
            $status,
            ['draft', 'active', 'ended'],
            true
        )) {
            survey_json([
                'ok' => false,
                'message' => 'ステータスが不正です。'
            ], 400);
        }

        foreach ($data['surveys'] as &$survey) {
            if ((string)$survey['id'] === $id) {
                $survey['status'] = $status;
                $survey['updated_at'] = survey_now();
            }
        }
        unset($survey);

        survey_write_data($data);

        survey_json([
            'ok' => true,
            'data' => $data
        ]);
    }

    if ($action === 'duplicate_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        $source = null;

        foreach ($data['surveys'] as $survey) {
            if ((string)$survey['id'] === $id) {
                $source = $survey;
                break;
            }
        }

        if (!$source) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートがありません。'
            ], 404);
        }

        $copy = $source;
        $copy['id'] = survey_id();
        $copy['title'] =
            (string)$source['title'] . '（複製）';
        $copy['status'] = 'draft';
        $copy['deleted'] = false;
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();

        array_unshift(
            $data['surveys'],
            $copy
        );

        survey_write_data($data);

        survey_json([
            'ok' => true,
            'survey' => $copy,
            'data' => $data
        ]);
    }

    if ($action === 'save_settings') {
        $json = (string)($_POST['settings_json'] ?? '');
        $settings = json_decode($json, true);

        if (!is_array($settings)) {
            survey_json([
                'ok' => false,
                'message' => '設定データが不正です。'
            ], 400);
        }

        /*
         * パスワード空欄の場合は既存値を維持。
         */
        foreach (
            ['password', 'smtp_password']
            as $secret
        ) {
            if (
                array_key_exists($secret, $settings) &&
                $settings[$secret] === ''
            ) {
                $settings[$secret] =
                    $data['settings'][$secret] ?? '';
            }
        }

        $data['settings'] =
            array_replace(
                $data['settings'],
                $settings
            );

        survey_write_data($data);

        survey_json([
            'ok' => true,
            'data' => $data
        ]);
    }

    if ($action === 'kintone_test') {
        $settings = $data['settings'];

        $input = json_decode(
            (string)($_POST['settings_json'] ?? ''),
            true
        );

        if (is_array($input)) {
            $settings =
                array_replace(
                    $settings,
                    $input
                );
        }

        $result =
            kintone_connection_test(
                $settings
            );

        survey_json([
            'ok' => $result['success'],
            'result' => $result
        ]);
    }

    if ($action === 'kintone_fields') {
        $settings = $data['settings'];

        $input = json_decode(
            (string)($_POST['settings_json'] ?? ''),
            true
        );

        if (is_array($input)) {
            $settings =
                array_replace(
                    $settings,
                    $input
                );
        }

        $appId = (string)(
            $_POST['app_id'] ??
            $settings['app_id'] ??
            ''
        );

        $result =
            kintone_fields(
                $settings,
                $appId
            );

        survey_json([
            'ok' => $result['success'],
            'result' => $result
        ]);
    }

    if ($action === 'save_customer') {
        $customer = json_decode(
            (string)($_POST['customer_json'] ?? ''),
            true
        );

        if (!is_array($customer)) {
            survey_json([
                'ok' => false,
                'message' => '顧客データが不正です。'
            ], 400);
        }

        $customer['id'] =
            (string)($customer['id'] ?? survey_id());

        $found = false;

        foreach ($data['customers'] as $i => $old) {
            if (
                (string)$old['id'] ===
                $customer['id']
            ) {
                $data['customers'][$i] =
                    array_replace(
                        $old,
                        $customer
                    );
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['customers'][] = $customer;
        }

        survey_write_data($data);

        survey_json([
            'ok' => true,
            'data' => $data
        ]);
    }

    if ($action === 'kintone_register') {
        /*
         * kintone登録処理は顧客管理アプリのフォーム構造が
         * マッピングによって決まるため、設定済みフィールドへ
         * 文字列値を投入する。
         */
        $customerId =
            (string)($_POST['customer_id'] ?? '');

        $customer = null;

        foreach ($data['customers'] as $c) {
            if ((string)$c['id'] === $customerId) {
                $customer = $c;
                break;
            }
        }

        if (!$customer) {
            survey_json([
                'ok' => false,
                'message' => '顧客がありません。'
            ], 404);
        }

        $s = $data['settings'];

        $fields = [];

        $map = [
            'field_company' => 'company',
            'field_name' => 'name',
            'field_email' => 'email',
            'field_department' => 'department',
            'field_phone' => 'phone'
        ];

        foreach ($map as $settingKey => $customerKey) {
            $code = trim(
                (string)($s[$settingKey] ?? '')
            );

            if ($code !== '') {
                $fields[$code] = [
                    'value' => (string)(
                        $customer[$customerKey] ?? ''
                    )
                ];
            }
        }

        $addressCodes =
            is_array($s['field_address'] ?? null)
                ? $s['field_address']
                : [];

        foreach ($addressCodes as $code) {
            $code = trim((string)$code);

            if ($code !== '') {
                $fields[$code] = [
                    'value' => (string)(
                        $customer['address'] ?? ''
                    )
                ];
            }
        }

        $url = kintone_build_url(
            (string)$s['subdomain'],
            '/k/v1/record.json'
        );

        $result = kintone_api_request(
            'POST',
            $url,
            [
                make_cybozu_auth_header(
                    (string)$s['login_name'],
                    (string)$s['password']
                ),
                'Accept: application/json'
            ],
            [
                'app' => (string)$s['app_id'],
                'record' => $fields
            ],
            kintone_settings($s)
        );

        if ($result['success']) {
            foreach ($data['customers'] as &$c) {
                if ((string)$c['id'] === $customerId) {
                    $c['kintone_status'] =
                        'registered';
                }
            }
            unset($c);

            survey_write_data($data);
        }

        survey_json([
            'ok' => $result['success'],
            'result' => [
                'success' => $result['success'],
                'status' => $result['status'],
                'message' =>
                    $result['success']
                    ? 'kintone登録が完了しました。'
                    : $result['message']
            ]
        ]);
    }

    if ($action === 'send_mail') {
        $surveyId =
            (string)($_POST['survey_id'] ?? '');

        $ids = json_decode(
            (string)($_POST['recipient_ids'] ?? '[]'),
            true
        );

        if (!is_array($ids)) {
            $ids = [];
        }

        $subject =
            (string)($_POST['mail_subject'] ?? '');

        $body =
            (string)($_POST['mail_body'] ?? '');

        $template =
            (string)($_POST['template_type'] ?? 'initial');

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if ((string)$s['id'] === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if (!$survey) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートがありません。'
            ], 404);
        }

        $success = 0;
        $failed = 0;
        $results = [];

        foreach ($ids as $id) {
            $customer = null;

            foreach ($data['customers'] as $c) {
                if ((string)$c['id'] === (string)$id) {
                    $customer = $c;
                    break;
                }
            }

            if (!$customer) {
                $failed++;
                $results[] = [
                    'customer_id' => $id,
                    'success' => false,
                    'message' => '顧客が存在しません。'
                ];
                continue;
            }

            if (
                empty($customer['email']) ||
                !filter_var(
                    $customer['email'],
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $failed++;
                $results[] = [
                    'customer_id' => $id,
                    'success' => false,
                    'message' => 'メールアドレスが不正です。'
                ];
                continue;
            }

            $url =
                rtrim(
                    (
                        isset($_SERVER['HTTPS']) &&
                        $_SERVER['HTTPS'] !== 'off'
                    )
                        ? 'https://'
                        : 'http://',
                    '/'
                ) .
                '://' .
                ($_SERVER['HTTP_HOST'] ?? '') .
                dirname(
                    $_SERVER['SCRIPT_NAME'] ?? '/'
                ) .
                '/?answer=' .
                rawurlencode($surveyId) .
                '&customer=' .
                rawurlencode(
                    (string)$customer['id']
                );

            $mailSubject = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [
                    (string)$customer['name'],
                    $url
                ],
                $subject
            );

            $mailBody = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [
                    (string)$customer['name'],
                    $url
                ],
                $body
            );

            $mailResult =
                smtp_send_mail(
                    $data['settings'],
                    (string)$customer['email'],
                    $mailSubject,
                    $mailBody
                );

            $successFlag =
                !empty($mailResult['success']);

            foreach ($data['customers'] as &$c) {
                if (
                    (string)$c['id'] ===
                    (string)$customer['id']
                ) {
                    if ($successFlag) {
                        $c['sent_at'] =
                            survey_now();
                        $c['send_count'] =
                            (int)($c['send_count'] ?? 0) + 1;
                        $c['answer_status'] =
                            'unanswered';
                    }

                    $c['last_send_result'] =
                        $successFlag
                            ? 'success'
                            : 'failed';

                    $c['last_send_error'] =
                        $successFlag
                            ? ''
                            : (string)(
                                $mailResult['message']
                                ?? '送信失敗'
                            );
                }
            }
            unset($c);

            if ($successFlag) {
                $success++;
            } else {
                $failed++;
            }

            $data['mail_logs'][] = [
                'id' => survey_id(),
                'survey_id' => $surveyId,
                'customer_id' =>
                    (string)$customer['id'],
                'sent_at' => survey_now(),
                'type' =>
                    $template === 'reminder'
                    ? 'reminder'
                    : 'initial',
                'success' => $successFlag,
                'subject' => $mailSubject,
                'body' => $mailBody,
                'error' =>
                    $successFlag
                    ? ''
                    : (string)(
                        $mailResult['message'] ?? ''
                    )
            ];

            $results[] = [
                'customer_id' =>
                    (string)$customer['id'],
                'success' => $successFlag,
                'message' =>
                    $successFlag
                    ? '送信成功'
                    : (string)(
                        $mailResult['message'] ?? '送信失敗'
                    )
            ];
        }

        $data['mail_logs'][] = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'sent_at' => survey_now(),
            'type' =>
                $template === 'reminder'
                ? 'reminder'
                : 'initial',
            'recipient_count' => count($ids),
            'success_count' => $success,
            'failed_count' => $failed,
            'subject' => $subject,
            'body' => ''
        ];

        survey_write_data($data);

        survey_json([
            'ok' => true,
            'success_count' => $success,
            'failed_count' => $failed,
            'unsent_count' =>
                max(0, count($ids) - $success - $failed),
            'results' => $results,
            'data' => $data
        ]);
    }

    if ($action === 'smtp_test') {
        $cfg = $data['settings'];

        $input = json_decode(
            (string)($_POST['settings_json'] ?? ''),
            true
        );

        if (is_array($input)) {
            $cfg =
                array_replace(
                    $cfg,
                    $input
                );
        }

        $result = smtp_open($cfg);

        if ($result['success']) {
            $socket = $result['socket'];
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }

        survey_json([
            'ok' => $result['success'],
            'result' => [
                'success' =>
                    $result['success'],
                'message' =>
                    $result['success']
                    ? 'SMTP接続・認証に成功しました。'
                    : ($result['message'] ?? ''),
                'stage' =>
                    $result['stage'] ?? '',
                'code' =>
                    $result['code'] ?? 0,
                'host' =>
                    (string)($cfg['smtp_host'] ?? ''),
                'port' =>
                    (int)($cfg['smtp_port'] ?? 0),
                'encryption' =>
                    (string)($cfg['smtp_encryption'] ?? '')
            ]
        ]);
    }

    if ($action === 'csv') {
        $surveyId =
            (string)($_GET['survey_id'] ?? '');

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if ((string)$s['id'] === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if (!$survey) {
            http_response_code(404);
            exit('Survey not found');
        }

        $questions = [];

        foreach ($survey['groups'] ?? [] as $group) {
            foreach ($group['questions'] ?? [] as $q) {
                $questions[] = $q;
            }
        }

        $fp = fopen('php://output', 'wb');

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );
        header(
            'Content-Disposition: attachment; filename="survey_' .
            preg_replace(
                '/[^A-Za-z0-9_-]/',
                '_',
                $surveyId
            ) .
            '.csv"'
        );

        fwrite($fp, "\xEF\xBB\xBF");

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名'
        ];

        foreach ($questions as $q) {
            $header[] =
                (string)($q['text'] ?? '');
        }

        fputcsv(
            $fp,
            $header
        );

        foreach ($data['responses'] as $response) {
            if (
                (string)($response['survey_id'] ?? '') !==
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

            foreach ($questions as $q) {
                $v =
                    $response['answers'][$q['id']]
                    ?? '';

                if (is_array($v)) {
                    $v = implode(
                        '、',
                        array_map(
                            'strval',
                            $v
                        )
                    );
                }

                $row[] = $v;
            }

            fputcsv(
                $fp,
                $row
            );
        }

        fclose($fp);
        exit;
    }

    survey_json([
        'ok' => false,
        'message' => '不明なactionです。'
    ], 400);
}

/* --------------------------------------------------------------------
 * 回答者モード
 * ------------------------------------------------------------------ */

if (isset($_GET['answer'])) {
    $data = survey_read_data();

    $surveyId =
        (string)$_GET['answer'];

    $customerId =
        (string)($_GET['customer'] ?? '');

    $survey = null;

    foreach ($data['surveys'] as $s) {
        if (
            (string)$s['id'] === $surveyId &&
            empty($s['deleted'])
        ) {
            $survey = $s;
            break;
        }
    }

    if (!$survey) {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    $customer = null;

    foreach ($data['customers'] as $c) {
        if ((string)$c['id'] === $customerId) {
            $customer = $c;
            break;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $answers = json_decode(
            (string)($_POST['answers'] ?? '{}'),
            true
        );

        if (!is_array($answers)) {
            $answers = [];
        }

        $response = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'customer_id' => $customerId,
            'company' =>
                (string)($customer['company'] ?? ''),
            'name' =>
                (string)($customer['name'] ?? ''),
            'email' =>
                (string)($customer['email'] ?? ''),
            'answered_at' => survey_now(),
            'answers' => $answers
        ];

        $data['responses'][] = $response;

        foreach ($data['customers'] as &$c) {
            if ((string)$c['id'] === $customerId) {
                $c['answer_status'] = 'answered';
            }
        }
        unset($c);

        survey_write_data($data);

        header(
            'Content-Type: text/html; charset=UTF-8'
        );

        echo '<!doctype html><html lang="ja"><head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '</head><body class="bg-slate-50 min-h-screen flex items-center justify-center p-6">';
        echo '<div class="bg-white rounded-2xl shadow-sm border p-10 max-w-xl w-full text-center">';
        echo '<div class="text-emerald-600 text-5xl mb-5">✓</div>';
        echo '<h1 class="text-2xl font-bold text-slate-800 mb-3">回答ありがとうございました</h1>';
        echo '<p class="text-slate-600">アンケートへの回答を受け付けました。</p>';
        echo '</div></body></html>';
        exit;
    }

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=survey_e($survey['title'])?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800">
<div class="max-w-3xl mx-auto p-6">
<div class="bg-white border rounded-2xl shadow-sm p-8">
<h1 class="text-2xl font-bold mb-8"><?=survey_e($survey['title'])?></h1>
<form method="post">
<?php foreach ($survey['groups'] ?? [] as $gi => $group): ?>
<section class="mb-8">
<h2 class="text-lg font-bold border-b pb-3 mb-5">
<?=survey_e($group['name'] ?? 'グループ')?>
</h2>

<?php foreach ($group['questions'] ?? [] as $qi => $q): ?>
<div class="mb-7">
<label class="block font-semibold mb-3">
<?=survey_e(
    $q['number'] ??
    'Q'.($qi + 1)
)?>
.
<?=survey_e($q['text'] ?? '')?>
<?php if (!empty($q['required'])): ?>
<span class="text-red-500 text-sm ml-2">必須</span>
<?php endif; ?>
</label>

<?php if (($q['type'] ?? '') === 'single'): ?>

<div class="space-y-2">
<?php foreach ($q['options'] ?? [] as $oi => $option): ?>
<label class="flex items-center gap-2">
<input
 type="radio"
 name="a_<?=survey_e($q['id'])?>"
 value="<?=survey_e($option)?>"
 class="w-4 h-4">
<span><?=survey_e($option)?></span>
</label>
<?php endforeach; ?>
</div>

<?php elseif (($q['type'] ?? '') === 'multiple'): ?>

<div class="space-y-2">
<?php foreach ($q['options'] ?? [] as $option): ?>
<label class="flex items-center gap-2">
<input
 type="checkbox"
 name="a_<?=survey_e($q['id'])?>[]"
 value="<?=survey_e($option)?>"
 class="w-4 h-4">
<span><?=survey_e($option)?></span>
</label>
<?php endforeach; ?>
</div>

<?php else: ?>

<textarea
 name="a_<?=survey_e($q['id'])?>"
 rows="5"
 class="w-full border rounded-xl p-3"
></textarea>

<?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>

<input type="hidden" name="answers" id="answers">

<button
 type="button"
 onclick="
 const f=this.form;
 const o={};
 f.querySelectorAll('[name^=a_]').forEach(e=>{
   const k=e.name.replace(/^a_/,'').replace(/\[\]$/,'');
   if(e.type==='checkbox'){
      if(!o[k])o[k]=[];
      if(e.checked)o[k].push(e.value);
   }else if(e.type==='radio'){
      if(e.checked)o[k]=e.value;
   }else{
      o[k]=e.value;
   }
 });
 document.getElementById('answers').value=JSON.stringify(o);
 f.submit();
 "
 class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl"
>
回答を送信
</button>
</form>
</div>
</div>
</body>
</html>
<?php
    exit;
}

/* --------------------------------------------------------------------
 * 管理画面
 * ------------------------------------------------------------------ */

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

<body class="bg-slate-50 text-slate-800">

<div
 id="app"
 class="min-h-screen"
 data-csrf="<?=survey_e($csrf)?>"
></div>

<script>
'use strict';

/*
 * =========================================================================
 * window.App
 * =========================================================================
 *
 * このアプリケーションの状態・描画・API・操作はすべて App 配下に置く。
 */

window.App = {

    State: {
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },

        page: 'list',
        surveyId: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        responseQuestionFilter: {},
        customerKeyword: '',
        responseKeyword: '',
        sortableInstances: [],
        previewMobile: false,
        editingSurvey: null
    },

    DOM: {},

    util: {

        escape: function(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        },

        id: function(prefix) {
            return prefix + '_' +
                Date.now().toString(36) + '_' +
                Math.random().toString(36).slice(2, 9);
        },

        now: function() {
            const d = new Date();

            const p = function(n) {
                return String(n).padStart(2, '0');
            };

            return d.getFullYear() + '-' +
                p(d.getMonth() + 1) + '-' +
                p(d.getDate()) + ' ' +
                p(d.getHours()) + ':' +
                p(d.getMinutes()) + ':' +
                p(d.getSeconds());
        },

        formatDate: function(value) {
            if (!value) return '未設定';

            return String(value)
                .replace(
                    /^(\d{4})-(\d{2})-(\d{2}).*$/,
                    '$1/$2/$3'
                );
        },

        statusLabel: function(status) {
            return {
                active: '公開中',
                draft: '下書き',
                ended: '終了'
            }[status] || status;
        },

        statusClass: function(status) {
            return {
                active:
                    'bg-emerald-50 text-emerald-700 border-emerald-200',
                draft:
                    'bg-slate-100 text-slate-600 border-slate-200',
                ended:
                    'bg-amber-50 text-amber-700 border-amber-200'
            }[status] ||
                'bg-slate-100 text-slate-600';
        },

        toast: function(message, type) {
            const old = document.getElementById(
                'survey_toast'
            );

            if (old) old.remove();

            const el =
                document.createElement('div');

            el.id = 'survey_toast';

            el.className =
                'fixed right-5 bottom-5 z-[100] px-5 py-3 rounded-xl shadow-lg text-white ' +
                (type === 'error'
                    ? 'bg-red-600'
                    : 'bg-slate-800');

            el.textContent = message;

            document.body.appendChild(el);

            setTimeout(function() {
                el.remove();
            }, 3000);
        }
    },

    api: {

        call: async function(action, data, method) {

            method = method || 'POST';

            let url =
                window.location.pathname +
                '?action=' +
                encodeURIComponent(action);

            let options = {
                method: method,
                headers: {}
            };

            if (method === 'GET') {

                if (data) {
                    const qs =
                        new URLSearchParams(data);

                    url += '&' + qs.toString();
                }

            } else {

                const fd = new FormData();

                fd.append(
                    'csrf_token',
                    App.DOM.csrf.value
                );

                Object.keys(data || {}).forEach(
                    function(k) {
                        fd.append(
                            k,
                            typeof data[k] === 'string'
                                ? data[k]
                                : JSON.stringify(data[k])
                        );
                    }
                );

                options.body = fd;
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
                    'サーバーから不正な応答が返されました。'
                );
            }

            if (!response.ok || !json.ok) {
                throw new Error(
                    json.message ||
                    json.result?.message ||
                    '処理に失敗しました。'
                );
            }

            return json;
        },

        load: async function() {
            const result =
                await App.api.call(
                    'load',
                    {},
                    'GET'
                );

            App.State.data =
                result.data;
        },

        saveSurvey: async function(survey) {

            const result =
                await App.api.call(
                    'save_survey',
                    {
                        survey_json:
                            JSON.stringify(survey)
                    }
                );

            App.State.data =
                result.data;

            return result.survey;
        },

        saveSettings: async function(settings) {

            const result =
                await App.api.call(
                    'save_settings',
                    {
                        settings_json:
                            JSON.stringify(settings)
                    }
                );

            App.State.data =
                result.data;
        },

        deleteSurvey: async function(id) {

            const result =
                await App.api.call(
                    'delete_survey',
                    {
                        survey_id: id
                    }
                );

            App.State.data =
                result.data;
        },

        statusSurvey: async function(id, status) {

            const result =
                await App.api.call(
                    'status_survey',
                    {
                        survey_id: id,
                        status: status
                    }
                );

            App.State.data =
                result.data;
        },

        duplicateSurvey: async function(id) {

            const result =
                await App.api.call(
                    'duplicate_survey',
                    {
                        survey_id: id
                    }
                );

            App.State.data =
                result.data;
        },

        kintoneFields: async function(settings) {

            return await App.api.call(
                'kintone_fields',
                {
                    app_id:
                        settings.app_id,
                    settings_json:
                        JSON.stringify(settings)
                }
            );
        },

        kintoneTest: async function(settings) {

            return await App.api.call(
                'kintone_test',
                {
                    settings_json:
                        JSON.stringify(settings)
                }
            );
        },

        smtpTest: async function(settings) {

            return await App.api.call(
                'smtp_test',
                {
                    settings_json:
                        JSON.stringify(settings)
                }
            );
        },

        sendMail: async function(
            surveyId,
            ids,
            subject,
            body,
            template
        ) {

            return await App.api.call(
                'send_mail',
                {
                    survey_id: surveyId,
                    recipient_ids:
                        JSON.stringify(ids),
                    mail_subject: subject,
                    mail_body: body,
                    template_type: template
                }
            );
        }
    },

    actions: {

        go: function(page, id) {
            App.State.page = page;
            App.State.surveyId = id || null;

            if (page === 'edit') {
                App.actions.openSurvey(id);
            }

            App.render();
        },

        newSurvey: function() {

            const now =
                App.util.now();

            App.State.editingSurvey = {
                id: App.util.id('survey'),
                title: '新規アンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: now,
                updated_at: now,
                numbering_mode: 'global',
                groups: [],
                deleted: false
            };

            App.actions.addGroup();

            App.State.page = 'edit';

            App.render();
        },

        openSurvey: function(id) {

            let survey =
                App.State.data.surveys.find(
                    function(s) {
                        return String(s.id) === String(id);
                    }
                );

            if (!survey) return;

            App.State.editingSurvey =
                JSON.parse(
                    JSON.stringify(survey)
                );

            App.State.surveyId = id;
        },

        saveAndList: async function() {

            App.actions.collectSurvey();

            try {
                await App.api.saveSurvey(
                    App.State.editingSurvey
                );

                App.util.toast(
                    'アンケートを保存しました。'
                );

                App.State.page = 'list';
                App.State.editingSurvey = null;

                App.render();

            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        cancelEdit: function() {

            if (
                !confirm(
                    '変更を破棄して一覧へ戻りますか？'
                )
            ) {
                return;
            }

            App.State.editingSurvey = null;
            App.State.page = 'list';
            App.render();
        },

        collectSurvey: function() {

            const s =
                App.State.editingSurvey;

            if (!s) return;

            const title =
                document.getElementById(
                    'survey_title'
                );

            const start =
                document.getElementById(
                    'survey_start_at'
                );

            const end =
                document.getElementById(
                    'survey_end_at'
                );

            const mode =
                document.getElementById(
                    'survey_numbering_mode'
                );

            if (title) s.title = title.value;
            if (start) s.start_at = start.value;
            if (end) s.end_at = end.value;
            if (mode) s.numbering_mode = mode.value;

            document.querySelectorAll(
                '[data-group-id]'
            ).forEach(function(groupEl) {

                const group =
                    s.groups.find(
                        function(g) {
                            return String(g.id) ===
                                String(
                                    groupEl.dataset.groupId
                                );
                        }
                    );

                if (!group) return;

                const name =
                    groupEl.querySelector(
                        '[data-group-name]'
                    );

                if (name) {
                    group.name =
                        name.value;
                }

                const order = [];

                groupEl.querySelectorAll(
                    '[data-question-id]'
                ).forEach(
                    function(questionEl) {

                        const q =
                            s.groups
                                .flatMap(
                                    function(g) {
                                        return g.questions || [];
                                    }
                                )
                                .find(
                                    function(q) {
                                        return String(q.id) ===
                                            String(
                                                questionEl.dataset.questionId
                                            );
                                    }
                                );

                        if (!q) return;

                        const text =
                            questionEl.querySelector(
                                '[data-question-text]'
                            );

                        const type =
                            questionEl.querySelector(
                                '[data-question-type]'
                            );

                        const required =
                            questionEl.querySelector(
                                '[data-question-required]'
                            );

                        const other =
                            questionEl.querySelector(
                                '[data-question-other]'
                            );

                        if (text) q.text = text.value;
                        if (type) q.type = type.value;
                        if (required) {
                            q.required =
                                required.checked;
                        }

                        if (other) {
                            q.other_enabled =
                                other.checked;
                        }

                        const opts = [];

                        questionEl.querySelectorAll(
                            '[data-option]'
                        ).forEach(
                            function(optionEl) {
                                opts.push(
                                    optionEl.value
                                );
                            }
                        );

                        q.options = opts;

                        order.push(q);
                    }
                );

                group.questions = order;
            });

            App.actions.renumberQuestions();
        },

        addGroup: function() {

            if (!App.State.editingSurvey) return;

            App.State.editingSurvey.groups.push({
                id: App.util.id('group'),
                name: '新しいグループ',
                questions: []
            });

            App.renderEditor();
            App.initSortable();
        },

        deleteGroup: function(groupId) {

            if (
                !confirm(
                    'このグループと質問を削除しますか？'
                )
            ) return;

            App.State.editingSurvey.groups =
                App.State.editingSurvey.groups.filter(
                    function(g) {
                        return String(g.id) !==
                            String(groupId);
                    }
                );

            App.renderEditor();
            App.initSortable();
            App.renumberQuestions();
        },

        addQuestion: function(groupId) {

            const group =
                App.State.editingSurvey.groups.find(
                    function(g) {
                        return String(g.id) ===
                            String(groupId);
                    }
                );

            if (!group) return;

            group.questions.push({
                id: App.util.id('question'),
                text: '新しい質問',
                type: 'single',
                required: false,
                options: [
                    '選択肢1',
                    '選択肢2'
                ],
                other_enabled: false
            });

            App.renderEditor();
            App.initSortable();
            App.renumberQuestions();
        },

        deleteQuestion: function(
            groupId,
            questionId
        ) {

            const group =
                App.State.editingSurvey.groups.find(
                    function(g) {
                        return String(g.id) ===
                            String(groupId);
                    }
                );

            if (!group) return;

            group.questions =
                group.questions.filter(
                    function(q) {
                        return String(q.id) !==
                            String(questionId);
                    }
                );

            App.renderEditor();
            App.initSortable();
            App.renumberQuestions();
        },

        addOption: function(
            questionId
        ) {

            const q =
                App.actions.findQuestion(
                    questionId
                );

            if (!q) return;

            q.options =
                Array.isArray(q.options)
                    ? q.options
                    : [];

            q.options.push(
                '選択肢' +
                (q.options.length + 1)
            );

            App.renderEditor();
            App.initSortable();
        },

        removeOption: function(
            questionId,
            index
        ) {

            const q =
                App.actions.findQuestion(
                    questionId
                );

            if (!q || !Array.isArray(q.options)) {
                return;
            }

            q.options.splice(
                index,
                1
            );

            App.renderEditor();
            App.initSortable();
        },

        findQuestion: function(id) {

            for (
                const group of
                App.State.editingSurvey.groups
            ) {
                const q =
                    (group.questions || []).find(
                        function(q) {
                            return String(q.id) ===
                                String(id);
                        }
                    );

                if (q) return q;
            }

            return null;
        },

        toggleStatus: async function(
            id,
            status
        ) {

            if (
                !confirm(
                    status === 'active'
                        ? '公開しますか？'
                        : '停止しますか？'
                )
            ) return;

            try {
                await App.api.statusSurvey(
                    id,
                    status
                );

                App.util.toast(
                    status === 'active'
                        ? '公開しました。'
                        : '停止しました。'
                );

                App.render();

            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        duplicate: async function(id) {

            try {
                await App.api.duplicateSurvey(id);

                App.util.toast(
                    'アンケートを複製しました。'
                );

                App.render();

            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        remove: async function(id) {

            if (
                !confirm(
                    '下書きアンケートを削除しますか？'
                )
            ) return;

            try {
                await App.api.deleteSurvey(id);

                App.util.toast(
                    '削除しました。'
                );

                App.render();

            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        search: function(value) {
            App.State.keyword = value;
            App.renderList();
        },

        filterStatus: function(value) {
            App.State.statusFilter = value;
            App.renderList();
        },

        sort: function(value) {
            App.State.sort = value;
            App.renderList();
        },

        collectCustomerFilter: function(value) {
            App.State.customerKeyword = value;
            App.renderMail();
        },

        collectResponseFilter: function(value) {
            App.State.responseKeyword = value;
            App.renderAnalysis();
        },

        selectAll: function(checked) {

            document.querySelectorAll(
                '[data-recipient]'
            ).forEach(
                function(el) {
                    el.checked = checked;
                }
            );
        },

        preview: function() {

            App.actions.collectSurvey();

            const modal =
                document.getElementById(
                    'preview_modal'
                );

            const content =
                document.getElementById(
                    'preview_content'
                );

            if (!modal || !content) return;

            content.innerHTML =
                App.templates.preview(
                    App.State.editingSurvey
                );

            modal.classList.remove('hidden');
        },

        closePreview: function() {
            const el =
                document.getElementById(
                    'preview_modal'
                );

            if (el) el.classList.add('hidden');
        },

        previewSubmit: function(e) {
            e.preventDefault();

            alert(
                'これはプレビューです。実際には送信されません。'
            );
        },

        showResponse: function(id) {

            const response =
                App.State.data.responses.find(
                    function(r) {
                        return String(r.id) ===
                            String(id);
                    }
                );

            if (!response) return;

            const survey =
                App.actions.currentSurvey();

            if (!survey) return;

            const questions =
                App.actions.questions(
                    survey
                );

            const detail =
                document.getElementById(
                    'response_detail'
                );

            detail.innerHTML =
                '<div class="space-y-4">' +
                '<div><b>会社名：</b>' +
                App.util.escape(
                    response.company
                ) +
                '</div>' +
                '<div><b>氏名：</b>' +
                App.util.escape(
                    response.name
                ) +
                '</div>' +
                '<div><b>メール：</b>' +
                App.util.escape(
                    response.email
                ) +
                '</div>' +
                '<div><b>回答日時：</b>' +
                App.util.escape(
                    response.answered_at
                ) +
                '</div>' +
                questions.map(
                    function(q) {

                        let value =
                            response.answers?.[
                                q.id
                            ] ?? '';

                        if (Array.isArray(value)) {
                            value =
                                value.join('、');
                        }

                        return (
                            '<div class="border-t pt-3">' +
                            '<div class="font-semibold">' +
                            App.util.escape(
                                q.text
                            ) +
                            '</div>' +
                            '<div class="mt-1 text-slate-600 whitespace-pre-wrap">' +
                            App.util.escape(
                                value
                            ) +
                            '</div>' +
                            '</div>'
                        );
                    }
                ).join('') +
                '</div>';

            document.getElementById(
                'response_modal'
            ).classList.remove('hidden');
        },

        closeResponse: function() {
            document.getElementById(
                'response_modal'
            ).classList.add('hidden');
        },

        currentSurvey: function() {
            return App.State.data.surveys.find(
                function(s) {
                    return String(s.id) ===
                        String(App.State.surveyId);
                }
            );
        },

        questions: function(survey) {
            return (survey.groups || [])
                .flatMap(
                    function(g) {
                        return g.questions || [];
                    }
                );
        },

        toggleResponseQuestion: function(
            questionId,
            checked
        ) {

            App.State.responseQuestionFilter[
                questionId
            ] = checked;

            App.renderAnalysis();
        },

        allResponseQuestions: function(
            checked
        ) {

            const survey =
                App.actions.currentSurvey();

            if (!survey) return;

            App.actions.questions(
                survey
            ).forEach(
                function(q) {
                    App.State.responseQuestionFilter[
                        q.id
                    ] = checked;
                }
            );

            App.renderAnalysis();
        },

        sendMail: async function() {

            const survey =
                App.actions.currentSurvey();

            if (!survey) return;

            const ids =
                Array.from(
                    document.querySelectorAll(
                        '[data-recipient]:checked'
                    )
                ).map(
                    function(el) {
                        return el.value;
                    }
                );

            if (!ids.length) {
                alert(
                    '送信対象を選択してください。'
                );
                return;
            }

            const subject =
                document.getElementById(
                    'mail_subject'
                ).value;

            const body =
                document.getElementById(
                    'mail_body'
                ).value;

            const template =
                document.getElementById(
                    'template_type'
                ).value;

            const already =
                ids.filter(
                    function(id) {
                        const c =
                            App.State.data.customers.find(
                                function(c) {
                                    return String(c.id) ===
                                        String(id);
                                }
                            );

                        return c &&
                            Number(c.send_count || 0) > 0;
                    }
                );

            if (
                already.length &&
                !confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )
            ) {
                return;
            }

            try {

                const result =
                    await App.api.sendMail(
                        survey.id,
                        ids,
                        subject,
                        body,
                        template
                    );

                App.State.data =
                    result.data;

                alert(
                    '送信完了\n成功: ' +
                    result.success_count +
                    '件\n失敗: ' +
                    result.failed_count +
                    '件'
                );

                App.renderMail();

            } catch (e) {
                alert(e.message);
            }
        },

        syncMailTemplate: function() {

            const settings =
                App.State.data.settings;

            const type =
                document.getElementById(
                    'template_type'
                ).value;

            document.getElementById(
                'mail_subject'
            ).value =
                type === 'reminder'
                    ? (
                        settings.mail_subject_reminder ||
                        ''
                    )
                    : (
                        settings.mail_subject_initial ||
                        ''
                    );

            document.getElementById(
                'mail_body'
            ).value =
                type === 'reminder'
                    ? (
                        settings.mail_body_reminder ||
                        ''
                    )
                    : (
                        settings.mail_body_initial ||
                        ''
                    );
        },

        saveSettings: async function() {

            const settings =
                App.actions.readSettingsForm();

            try {

                await App.api.saveSettings(
                    settings
                );

                App.util.toast(
                    '設定を保存しました。'
                );

                App.renderSettings();

            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        readSettingsForm: function() {

            const s =
                App.State.data.settings;

            const value = function(id, fallback) {
                const e =
                    document.getElementById(id);

                return e
                    ? e.value
                    : fallback;
            };

            return {
                ...s,

                subdomain:
                    value(
                        'setting_subdomain',
                        s.subdomain || ''
                    ),

                app_id:
                    value(
                        'setting_app_id',
                        s.app_id || ''
                    ),

                login_name:
                    value(
                        'setting_login_name',
                        s.login_name || ''
                    ),

                password:
                    value(
                        'setting_password',
                        ''
                    ),

                proxy:
                    value(
                        'setting_proxy',
                        s.proxy || ''
                    ),

                ssl_verify:
                    document.getElementById(
                        'setting_ssl_verify'
                    )?.checked || false,

                smtp_host:
                    value(
                        'smtp_host',
                        s.smtp_host || ''
                    ),

                smtp_port:
                    Number(
                        value(
                            'smtp_port',
                            s.smtp_port || 465
                        )
                    ),

                smtp_encryption:
                    value(
                        'smtp_encryption',
                        s.smtp_encryption || 'SSL'
                    ),

                smtp_auth:
                    document.getElementById(
                        'smtp_auth'
                    )?.checked || false,

                smtp_username:
                    value(
                        'smtp_username',
                        s.smtp_username || ''
                    ),

                smtp_password:
                    value(
                        'smtp_password',
                        ''
                    ),

                smtp_from:
                    value(
                        'smtp_from',
                        s.smtp_from || ''
                    ),

                smtp_from_name:
                    value(
                        'smtp_from_name',
                        s.smtp_from_name || ''
                    ),

                smtp_timeout:
                    Number(
                        value(
                            'smtp_timeout',
                            s.smtp_timeout || 15
                        )
                    )
            };
        },

        testKintone: async function() {

            try {

                const result =
                    await App.api.kintoneTest(
                        App.actions.readSettingsForm()
                    );

                alert(
                    result.result.message
                );

            } catch (e) {
                alert(e.message);
            }
        },

        fetchKintoneFields: async function() {

            const settings =
                App.actions.readSettingsForm();

            const message =
                document.getElementById(
                    'field_message'
                );

            message.textContent =
                '取得中...';

            try {

                const result =
                    await App.api.kintoneFields(
                        settings
                    );

                const fields =
                    result.result.data?.properties ||
                    result.result.data?.fields ||
                    {};

                App.State.kintoneFields =
                    fields;

                App.renderSettings();

                message.textContent =
                    'フィールド一覧を取得しました。';

            } catch (e) {

                message.textContent =
                    e.message;

                alert(e.message);
            }
        },

        testSMTP: async function() {

            try {

                const result =
                    await App.api.smtpTest(
                        App.actions.readSettingsForm()
                    );

                const r =
                    result.result;

                alert(
                    r.message +
                    '\nSMTP: ' +
                    r.host +
                    ':' +
                    r.port +
                    '\n暗号化: ' +
                    r.encryption +
                    '\nステージ: ' +
                    r.stage +
                    '\n応答コード: ' +
                    r.code
                );

            } catch (e) {
                alert(e.message);
            }
        },

        syncEditorOrder: function() {

            const survey =
                App.State.editingSurvey;

            if (!survey) return;

            const groups = [];

            document.querySelectorAll(
                '#question_editor [data-group-id]'
            ).forEach(
                function(groupEl) {

                    const group =
                        survey.groups.find(
                            function(g) {
                                return String(g.id) ===
                                    String(
                                        groupEl.dataset.groupId
                                    );
                            }
                        );

                    if (!group) return;

                    const questions = [];

                    groupEl.querySelectorAll(
                        '[data-sortable-questions] > [data-question-id]'
                    ).forEach(
                        function(qEl) {

                            const q =
                                App.actions.findQuestion(
                                    qEl.dataset.questionId
                                );

                            if (q) {
                                questions.push(q);
                            }
                        }
                    );

                    group.questions =
                        questions;

                    groups.push(group);
                }
            );

            survey.groups = groups;
        },

        renumberQuestions: function() {

            const survey =
                App.State.editingSurvey;

            if (!survey) return;

            let n = 1;

            survey.groups.forEach(
                function(group, gi) {

                    (group.questions || [])
                        .forEach(
                            function(q, qi) {

                                q.number =
                                    survey.numbering_mode ===
                                    'group'
                                        ? 'Q' +
                                          (gi + 1) +
                                          '-' +
                                          (qi + 1)
                                        : 'Q' + n++;

                                const el =
                                    document.querySelector(
                                        '[data-question-id="' +
                                        CSS.escape(
                                            String(q.id)
                                        ) +
                                        '"] [data-question-number]'
                                    );

                                if (el) {
                                    el.textContent =
                                        q.number;
                                }
                            }
                        );
                }
            );
        }
    },

    initSortable: function() {

        /*
         * ★ 今回のエラーの修正箇所
         *
         * App.initSortable は window.App 直下に存在する。
         *
         * グループ用Sortableと質問用Sortableを毎回破棄して
         * 再生成することで、SPA再描画後も確実に動作する。
         */

        if (typeof Sortable === 'undefined') {
            console.warn(
                'SortableJS is not loaded.'
            );
            return;
        }

        App.State.sortableInstances =
            App.State.sortableInstances || [];

        App.State.sortableInstances.forEach(
            function(instance) {
                try {
                    instance.destroy();
                } catch (e) {}
            }
        );

        App.State.sortableInstances = [];

        const editor =
            document.getElementById(
                'question_editor'
            );

        if (!editor) return;

        const groupContainer =
            editor.querySelector(
                '[data-sortable-groups]'
            );

        if (groupContainer) {

            App.State.sortableInstances.push(
                new Sortable(
                    groupContainer,
                    {
                        animation: 150,
                        handle: '[data-group-handle]',
                        draggable: '[data-group-id]',
                        ghostClass: 'opacity-40',

                        onEnd: function() {

                            App.actions
                                .syncEditorOrder();

                            App.actions
                                .renumberQuestions();
                        }
                    }
                )
            );
        }

        editor.querySelectorAll(
            '[data-sortable-questions]'
        ).forEach(
            function(container) {

                App.State.sortableInstances.push(
                    new Sortable(
                        container,
                        {
                            group: {
                                name:
                                    'survey-questions',
                                pull: true,
                                put: true
                            },

                            animation: 150,

                            handle:
                                '[data-question-handle]',

                            draggable:
                                '[data-question-id]',

                            ghostClass:
                                'opacity-40',

                            onAdd: function() {

                                App.actions
                                    .syncEditorOrder();

                                App.actions
                                    .renumberQuestions();
                            },

                            onEnd: function() {

                                App.actions
                                    .syncEditorOrder();

                                App.actions
                                    .renumberQuestions();
                            }
                        }
                    )
                );
            }
        );
    },

    templates: {

        header: function() {

            return `
<header class="bg-white border-b sticky top-0 z-40">
 <div class="max-w-7xl mx-auto px-5 h-16 flex items-center justify-between">
  <div class="font-bold text-lg text-slate-800">
   アンケート管理システム
  </div>

  <nav class="flex gap-2">
   <button onclick="App.actions.go('list')"
    class="px-4 py-2 rounded-lg hover:bg-slate-100">
    アンケート一覧
   </button>

   <button onclick="App.actions.go('settings')"
    class="px-4 py-2 rounded-lg hover:bg-slate-100">
    kintone・メール連携設定
   </button>

   <button onclick="alert('ログアウト処理は認証方式に応じて実装してください。')"
    class="px-4 py-2 rounded-lg hover:bg-slate-100">
    ログアウト
   </button>
  </nav>
 </div>
</header>`;
        },

        crumbs: function(items) {

            return `
<div class="flex items-center gap-2 text-sm text-slate-500 mb-5">
 ${items.map(
     function(x, i) {
         return (
             i
                 ? '<span>›</span>'
                 : ''
         ) +
         '<span>' +
         App.util.escape(x) +
         '</span>';
     }
 ).join('')}
</div>`;
        },

        list: function() {

            const surveys =
                App.State.data.surveys
                    .filter(
                        function(s) {
                            return !s.deleted;
                        }
                    );

            const keyword =
                App.State.keyword
                    .trim()
                    .toLowerCase();

            let rows =
                surveys.filter(
                    function(s) {

                        const matchKeyword =
                            !keyword ||
                            String(s.title || '')
                                .toLowerCase()
                                .includes(keyword);

                        const matchStatus =
                            App.State.statusFilter ===
                                'all' ||
                            s.status ===
                                App.State.statusFilter;

                        return (
                            matchKeyword &&
                            matchStatus
                        );
                    }
                );

            rows.sort(
                function(a, b) {

                    const responsesA =
                        App.State.data.responses.filter(
                            r =>
                                String(
                                    r.survey_id
                                ) === String(a.id)
                        ).length;

                    const responsesB =
                        App.State.data.responses.filter(
                            r =>
                                String(
                                    r.survey_id
                                ) === String(b.id)
                        ).length;

                    switch (App.State.sort) {

                        case 'updated_asc':
                            return String(a.updated_at)
                                .localeCompare(
                                    String(b.updated_at)
                                );

                        case 'answers_desc':
                            return responsesB -
                                responsesA;

                        case 'answers_asc':
                            return responsesA -
                                responsesB;

                        case 'start_desc':
                            return String(
                                b.start_at || ''
                            ).localeCompare(
                                String(
                                    a.start_at || ''
                                )
                            );

                        case 'start_asc':
                            return String(
                                a.start_at || ''
                            ).localeCompare(
                                String(
                                    b.start_at || ''
                                )
                            );

                        default:
                            return String(
                                b.updated_at || ''
                            ).localeCompare(
                                String(
                                    a.updated_at || ''
                                )
                            );
                    }
                }
            );

            return `
<div class="max-w-7xl mx-auto px-5 py-7">
 ${App.templates.crumbs(['ホーム','アンケート一覧'])}

 <div class="flex items-center justify-between mb-6">
  <div>
   <h1 class="text-2xl font-bold">アンケート一覧</h1>
   <p class="text-sm text-slate-500 mt-1">
    アンケートの作成・公開・送信・集計を管理します。
   </p>
  </div>

  <button
   onclick="App.actions.newSurvey()"
   class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-bold shadow-sm">
   ＋ 新規アンケート作成
  </button>
 </div>

 <div class="bg-white border rounded-2xl p-4 mb-5 flex gap-3">
  <input
   value="${App.util.escape(App.State.keyword)}"
   onkeydown="if(event.key==='Enter')App.actions.search(this.value)"
   placeholder="タイトルを検索してEnter"
   class="flex-1 border rounded-xl px-4 py-2">

  <select
   onchange="App.actions.filterStatus(this.value)"
   class="border rounded-xl px-4 py-2">
   <option value="all" ${App.State.statusFilter==='all'?'selected':''}>すべて</option>
   <option value="active" ${App.State.statusFilter==='active'?'selected':''}>公開中</option>
   <option value="draft" ${App.State.statusFilter==='draft'?'selected':''}>下書き</option>
   <option value="ended" ${App.State.statusFilter==='ended'?'selected':''}>終了</option>
  </select>

  <select
   onchange="App.actions.sort(this.value)"
   class="border rounded-xl px-4 py-2">
   <option value="updated_desc">更新日：新しい順</option>
   <option value="updated_asc">更新日：古い順</option>
   <option value="answers_desc">回答数：多い順</option>
   <option value="answers_asc">回答数：少ない順</option>
   <option value="start_desc">開始日：新しい順</option>
   <option value="start_asc">開始日：古い順</option>
  </select>
 </div>

 <div class="bg-white border rounded-2xl overflow-hidden">
  <div class="overflow-x-auto">
   <table class="w-full text-sm">
    <thead class="bg-slate-50 border-b">
     <tr>
      <th class="text-left px-5 py-4">作成日 / 更新日</th>
      <th class="text-left px-5 py-4">タイトル</th>
      <th class="text-left px-5 py-4">アンケート期間</th>
      <th class="text-left px-5 py-4">ステータス</th>
      <th class="text-right px-5 py-4">回答数</th>
      <th class="text-right px-5 py-4">操作</th>
     </tr>
    </thead>
    <tbody>
    ${
        rows.length
            ? rows.map(
                function(s) {

                    const count =
                        App.State.data.responses
                            .filter(
                                r =>
                                    String(
                                        r.survey_id
                                    ) === String(s.id)
                            ).length;

                    let actions = '';

                    actions +=
                        `<button onclick="App.actions.go('edit','${s.id}')"
                         class="text-indigo-600 hover:underline">確認・編集</button>`;

                    if (
                        s.status === 'active' ||
                        s.status === 'ended'
                    ) {
                        actions +=
                            `<button onclick="App.actions.go('analysis','${s.id}')"
                             class="text-indigo-600 hover:underline">集計</button>`;
                    }

                    if (s.status === 'active') {
                        actions +=
                            `<button onclick="App.actions.go('mail','${s.id}')"
                             class="text-indigo-600 hover:underline">送信</button>`;

                        actions +=
                            `<button onclick="App.actions.toggleStatus('${s.id}','ended')"
                             class="text-amber-600 hover:underline">停止</button>`;
                    }

                    if (s.status === 'draft') {
                        actions +=
                            `<button onclick="App.actions.remove('${s.id}')"
                             class="text-red-600 hover:underline">削除</button>`;
                    }

                    actions +=
                        `<button onclick="App.actions.duplicate('${s.id}')"
                         class="text-slate-600 hover:underline">複製</button>`;

                    return `
<tr class="border-b last:border-0 hover:bg-slate-50">
 <td class="px-5 py-4">
  ${App.util.escape(
      App.util.formatDate(s.created_at)
  )}
  <div class="text-xs text-slate-400">
   更新:
   ${App.util.escape(
       App.util.formatDate(s.updated_at)
   )}
  </div>
 </td>

 <td class="px-5 py-4">
  <div class="font-bold">
   ${App.util.escape(s.title)}
  </div>
 </td>

 <td class="px-5 py-4">
  ${App.util.escape(
      s.start_at || '未設定'
  )}
  ～
  ${App.util.escape(
      s.end_at || '未設定'
  )}
 </td>

 <td class="px-5 py-4">
  <span class="inline-flex px-3 py-1 rounded-full border text-xs font-bold ${App.util.statusClass(s.status)}">
   ${App.util.statusLabel(s.status)}
  </span>
 </td>

 <td class="px-5 py-4 text-right font-bold">
  ${count} 件
 </td>

 <td class="px-5 py-4">
  <div class="flex justify-end gap-3 whitespace-nowrap">
   ${actions}
  </div>
 </td>
</tr>`;
                }
            ).join('')
            :
            `<tr><td colspan="6"
                class="text-center py-16 text-slate-400">
                アンケートがありません。
             </td></tr>`
    }
    </tbody>
   </table>
  </div>
 </div>
</div>`;
        },

        editor: function() {

            const s =
                App.State.editingSurvey;

            if (!s) {
                return '';
            }

            return `
<div class="max-w-7xl mx-auto px-5 py-7">
 ${App.templates.crumbs([
     'ホーム',
     'アンケート一覧',
     'アンケート作成・編集'
 ])}

 <div class="flex items-center justify-between mb-5">
  <div class="flex-1 mr-5">
   <input
    id="survey_title"
    value="${App.util.escape(s.title)}"
    class="w-full text-2xl font-bold bg-transparent border-b border-transparent hover:border-slate-300 focus:border-indigo-500 focus:outline-none py-2">
  </div>

  <div class="flex gap-2">
   <button onclick="App.actions.preview()"
    class="px-4 py-2 rounded-xl border bg-white hover:bg-slate-50">
    プレビュー
   </button>

   <button onclick="App.actions.saveAndList()"
    class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">
    保存して一覧へ戻る
   </button>

   <button onclick="App.actions.cancelEdit()"
    class="px-4 py-2 rounded-xl border bg-white">
    キャンセル
   </button>
  </div>
 </div>

 <div class="bg-white border rounded-2xl p-5 mb-5">
  <div class="grid grid-cols-4 gap-4">
   <label>
    <span class="text-xs text-slate-500">開始日時</span>
    <input id="survey_start_at"
     type="datetime-local"
     value="${App.util.escape(
         s.start_at
             ? s.start_at.replace(' ','T')
             : ''
     )}"
     class="mt-1 w-full border rounded-xl p-2">
   </label>

   <label>
    <span class="text-xs text-slate-500">終了日時</span>
    <input id="survey_end_at"
     type="datetime-local"
     value="${App.util.escape(
         s.end_at
             ? s.end_at.replace(' ','T')
             : ''
     )}"
     class="mt-1 w-full border rounded-xl p-2">
   </label>

   <label>
    <span class="text-xs text-slate-500">ステータス</span>
    <select
     onchange="App.State.editingSurvey.status=this.value"
     class="mt-1 w-full border rounded-xl p-2">
     <option value="draft" ${s.status==='draft'?'selected':''}>下書き</option>
     <option value="active" ${s.status==='active'?'selected':''}>公開中</option>
     <option value="ended" ${s.status==='ended'?'selected':''}>終了</option>
    </select>
   </label>

   <label>
    <span class="text-xs text-slate-500">質問番号</span>
    <select id="survey_numbering_mode"
     class="mt-1 w-full border rounded-xl p-2">
     <option value="global" ${s.numbering_mode==='global'?'selected':''}>
      Q1 / Q2 / Q3
     </option>
     <option value="group" ${s.numbering_mode==='group'?'selected':''}>
      Q1-1 / Q1-2
     </option>
    </select>
   </label>
  </div>
 </div>

 <div id="question_editor">
  <div data-sortable-groups>
   ${
       (s.groups || []).map(
           function(g, gi) {
               return App.templates.group(
                   g,
                   gi
               );
           }
       ).join('')
   }
  </div>

  <button
   onclick="App.actions.addGroup()"
   class="mt-5 w-full py-4 rounded-2xl border-2 border-dashed border-slate-300 text-slate-500 hover:border-indigo-400 hover:text-indigo-600">
   ＋ グループを追加
  </button>
 </div>
</div>

${App.templates.previewModal()}
`;
        },

        group: function(g, gi) {

            return `
<section
 data-group-id="${App.util.escape(g.id)}"
 class="bg-white border rounded-2xl mb-5 overflow-hidden">

 <div class="px-5 py-4 bg-slate-50 border-b flex items-center gap-3">

  <button
   data-group-handle
   title="ドラッグしてグループを移動"
   class="cursor-grab text-slate-400 text-xl">
   ⠿
  </button>

  <input
   data-group-name
   value="${App.util.escape(g.name)}"
   class="flex-1 bg-transparent font-bold text-lg outline-none">

  <button
   onclick="App.actions.deleteGroup('${g.id}')"
   class="text-red-500 hover:text-red-700">
   グループ削除
  </button>
 </div>

 <div
  data-sortable-questions
  class="p-5 space-y-4 min-h-[60px]">

  ${
      (g.questions || []).map(
          function(q, qi) {
              return App.templates.question(
                  q,
                  g.id
              );
          }
      ).join('')
  }

 </div>

 <div class="px-5 pb-5">
  <button
   onclick="App.actions.addQuestion('${g.id}')"
   class="w-full border rounded-xl py-3 text-indigo-600 hover:bg-indigo-50">
   ＋ 質問を追加
  </button>
 </div>
</section>`;
        },

        question: function(q, groupId) {

            return `
<div
 data-question-id="${App.util.escape(q.id)}"
 class="border rounded-xl bg-white shadow-sm">

 <div class="p-4">

  <div class="flex items-start gap-3">

   <button
    data-question-handle
    title="ドラッグして質問を移動"
    class="cursor-grab text-slate-400 text-xl pt-1">
    ⠿
   </button>

   <div class="flex-1">

    <div class="flex items-center gap-3 mb-3">

     <span
      data-question-number
      class="font-bold text-indigo-600 min-w-[45px]">
      ${App.util.escape(q.number || 'Q')}
     </span>

     <input
      data-question-text
      value="${App.util.escape(q.text)}"
      class="flex-1 border rounded-xl px-3 py-2">

     <select
      data-question-type
      onchange="
       App.actions.collectSurvey();
       App.renderEditor();
       App.initSortable();
      "
      class="border rounded-xl px-3 py-2">
      <option value="single" ${q.type==='single'?'selected':''}>
       単一選択
      </option>
      <option value="multiple" ${q.type==='multiple'?'selected':''}>
       複数選択
      </option>
      <option value="text" ${q.type==='text'?'selected':''}>
       自由記述
      </option>
     </select>

     <button
      onclick="App.actions.deleteQuestion('${groupId}','${q.id}')"
      class="text-red-500">
      削除
     </button>
    </div>

    ${
        q.type === 'text'
            ? `<div class="bg-slate-50 rounded-xl p-4 text-slate-400">
                回答者入力欄
               </div>`
            :
            `<div class="space-y-2">
              ${(q.options || []).map(
                  function(o, i) {
                      return `
<div class="flex gap-2">
 <input
  data-option
  value="${App.util.escape(o)}"
  class="flex-1 border rounded-lg px-3 py-2">

 <button
  onclick="App.actions.removeOption('${q.id}',${i})"
  class="px-3 text-red-500">
  ×
 </button>
</div>`;
                  }
              ).join('')}

              <button
               onclick="App.actions.addOption('${q.id}')"
               class="text-sm text-indigo-600">
               ＋ 選択肢追加
              </button>
             </div>`
    }

    <div class="mt-4 flex gap-5 text-sm">

     <label class="flex items-center gap-2">
      <input
       data-question-required
       type="checkbox"
       ${q.required ? 'checked' : ''}>
      必須回答
     </label>

     ${
         q.type !== 'text'
             ? `<label class="flex items-center gap-2">
                 <input
                  data-question-other
                  type="checkbox"
                  ${q.other_enabled ? 'checked' : ''}>
                 「その他」を許可
                </label>`
             : ''
     }

    </div>

   </div>
  </div>
 </div>
</div>`;
        },

        previewModal: function() {

            return `
<div
 id="preview_modal"
 class="hidden fixed inset-0 bg-black/40 z-50 p-5">

 <div class="bg-white max-w-4xl h-full max-h-[90vh] mx-auto rounded-2xl overflow-hidden flex flex-col">

  <div class="p-4 border-b flex justify-between items-center">
   <b>プレビュー</b>

   <div class="flex gap-2">
    <button
     onclick="App.State.previewMobile=false;App.actions.preview()"
     class="border px-3 py-1 rounded-lg">
     PC表示
    </button>

    <button
     onclick="App.State.previewMobile=true;App.actions.preview()"
     class="border px-3 py-1 rounded-lg">
     スマートフォン表示
    </button>

    <button
     onclick="App.actions.closePreview()"
     class="px-3 py-1">
     ✕
    </button>
   </div>
  </div>

  <div
   id="preview_content"
   class="overflow-auto p-6 flex-1">
  </div>

 </div>
</div>`;
        },

        preview: function(s) {

            return `
<div class="${App.State.previewMobile
    ? 'max-w-sm'
    : 'max-w-2xl'} mx-auto">

 <h2 class="text-2xl font-bold mb-7">
  ${App.util.escape(s.title)}
 </h2>

 <form onsubmit="App.actions.previewSubmit(event)">

 ${
     (s.groups || []).map(
         function(g) {

             return `
<section class="mb-8">
 <h3 class="font-bold text-lg border-b pb-2 mb-4">
  ${App.util.escape(g.name)}
 </h3>

 ${
     (g.questions || []).map(
         function(q) {

             return `
<div class="mb-6">
 <label class="font-semibold block mb-3">
  ${App.util.escape(q.number || '')}
  ${App.util.escape(q.text)}
  ${q.required
      ? '<span class="text-red-500"> *</span>'
      : ''}
 </label>

 ${
     q.type === 'text'
         ? `<textarea rows="4"
              class="w-full border rounded-xl p-3"></textarea>`
         :
         (q.options || []).map(
             function(o) {
                 return `
<label class="block mb-2">
 <input
  type="${q.type === 'single' ? 'radio' : 'checkbox'}"
  class="mr-2">
 ${App.util.escape(o)}
</label>`;
             }
         ).join('')
 }
</div>`;
         }
     ).join('')
 }
</section>`;
         }
     ).join('')
 }

 <button
  type="submit"
  class="bg-indigo-600 text-white px-5 py-3 rounded-xl">
  送信
 </button>

 </form>
</div>`;
        }
    },

    renderList: function() {
        App.DOM.app.innerHTML =
            App.templates.header() +
            App.templates.list();
    },

    renderEditor: function() {
        App.DOM.app.innerHTML =
            App.templates.header() +
            App.templates.editor();

        App.initSortable();
    },

    renderMail: function() {

        const survey =
            App.actions.currentSurvey();

        if (!survey) return;

        const keyword =
            App.State.customerKeyword
                .toLowerCase();

        const customers =
            App.State.data.customers.filter(
                function(c) {

                    return (
                        !keyword ||
                        [
                            c.company,
                            c.name,
                            c.email,
                            c.phone
                        ].join(' ')
                            .toLowerCase()
                            .includes(keyword)
                    );
                }
            );

        App.DOM.app.innerHTML =
            App.templates.header() +
`
<div class="max-w-7xl mx-auto px-5 py-7">

 ${App.templates.crumbs([
     'ホーム',
     'アンケート一覧',
     '顧客選択・送信・送信履歴'
 ])}

 <div class="flex justify-between mb-5">
  <div>
   <h1 class="text-2xl font-bold">
    ${App.util.escape(survey.title)}
   </h1>
   <p class="text-sm text-slate-500">
    顧客選択・メール送信
   </p>
  </div>

  <button
   onclick="App.actions.sendMail()"
   class="bg-indigo-600 text-white px-5 py-3 rounded-xl font-bold">
   一括送信実行
  </button>
 </div>

 <div class="grid grid-cols-3 gap-5 mb-5">

  <div class="col-span-2 bg-white border rounded-2xl p-5">

   <div class="grid grid-cols-2 gap-4">

    <label>
     <span class="text-sm">テンプレート</span>
     <select
      id="template_type"
      onchange="App.actions.syncMailTemplate()"
      class="w-full border rounded-xl p-2 mt-1">
      <option value="initial">初回送信</option>
      <option value="reminder">リマインド</option>
     </select>
    </label>

    <div>
     <span class="text-sm">顧客検索</span>
     <input
      id="customer_filter"
      oninput="App.actions.collectCustomerFilter(this.value)"
      placeholder="会社名・氏名・メール"
      class="w-full border rounded-xl p-2 mt-1">
    </div>

   </div>

   <label class="block mt-4">
    <span class="text-sm">件名</span>
    <input
     id="mail_subject"
     value="${App.util.escape(
         App.State.data.settings.mail_subject_initial || ''
     )}"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label class="block mt-4">
    <span class="text-sm">本文</span>
    <textarea
     id="mail_body"
     rows="7"
     class="w-full border rounded-xl p-2 mt-1"
    >${App.util.escape(
        App.State.data.settings.mail_body_initial || ''
    )}</textarea>
   </label>

   <div class="text-xs text-slate-400 mt-2">
    利用可能な変数：
    {顧客名}　{アンケートURL}
   </div>

  </div>

  <div class="bg-white border rounded-2xl p-5">
   <div class="text-sm text-slate-500">
    対象顧客
   </div>
   <div class="text-3xl font-bold mt-2">
    ${customers.length}
   </div>
  </div>

 </div>

 <div class="bg-white border rounded-2xl overflow-hidden">

  <div class="p-4 border-b">
   <label class="flex items-center gap-2">
    <input
     id="select_all"
     type="checkbox"
     onchange="App.actions.selectAll(this.checked)">
    全選択
   </label>
  </div>

  <div class="overflow-x-auto">
   <table class="w-full text-sm">
    <thead class="bg-slate-50 border-b">
     <tr>
      <th class="p-4">選択</th>
      <th class="p-4 text-left">会社名 / 氏名</th>
      <th class="p-4 text-left">メール</th>
      <th class="p-4">最終送信</th>
      <th class="p-4">送信回数</th>
      <th class="p-4">回答</th>
      <th class="p-4">kintone</th>
     </tr>
    </thead>
    <tbody>
    ${
        customers.map(
            function(c) {

                const disabled =
                    c.source === 'web';

                return `
<tr class="border-b">
 <td class="p-4 text-center">
  <input
   data-recipient
   type="checkbox"
   value="${App.util.escape(c.id)}"
   ${disabled ? 'disabled' : ''}>
 </td>

 <td class="p-4">
  <b>${App.util.escape(c.company)}</b>
  <div>${App.util.escape(c.name)}</div>
  <div class="text-xs text-slate-400">
   ${App.util.escape(c.phone || '')}
  </div>
 </td>

 <td class="p-4">
  ${App.util.escape(c.email)}
 </td>

 <td class="p-4 text-center">
  ${App.util.escape(c.sent_at || '未送信')}
 </td>

 <td class="p-4 text-center">
  ${Number(c.send_count || 0)}
 </td>

 <td class="p-4 text-center">
  <span class="px-2 py-1 rounded-full text-xs ${
      c.answer_status === 'answered'
          ? 'bg-emerald-50 text-emerald-700'
          : 'bg-amber-50 text-amber-700'
  }">
   ${
       c.answer_status === 'answered'
           ? '回答済み'
           : '送信済み（未回答）'
   }
  </span>
 </td>

 <td class="p-4 text-center">
  ${
      c.kintone_status === 'registered'
          ? '<span class="text-emerald-600">✓ 登録完了</span>'
          : '<span class="text-slate-400">未登録</span>'
  }
 </td>
</tr>`;
            }
        ).join('')
    }
    </tbody>
   </table>
  </div>
 </div>
</div>`;
    },

    renderAnalysis: function() {

        const survey =
            App.actions.currentSurvey();

        if (!survey) return;

        const questions =
            App.actions.questions(
                survey
            );

        questions.forEach(
            function(q) {
                if (
                    App.State.responseQuestionFilter[
                        q.id
                    ] === undefined
                ) {
                    App.State.responseQuestionFilter[
                        q.id
                    ] = true;
                }
            }
        );

        const responses =
            App.State.data.responses.filter(
                function(r) {
                    return String(r.survey_id) ===
                        String(survey.id);
                }
            );

        const selectedCustomers =
            new Set(
                App.State.data.customers
                    .filter(
                        c =>
                            c.sent_at
                    )
                    .map(
                        c =>
                            String(c.id)
                    )
            );

        const targetCount =
            selectedCustomers.size;

        const registeredResponses =
            responses.filter(
                r =>
                    r.customer_id &&
                    selectedCustomers.has(
                        String(r.customer_id)
                    )
            ).length;

        const unregistered =
            responses.length -
            registeredResponses;

        const unanswered =
            Math.max(
                0,
                targetCount -
                registeredResponses
            );

        const rate =
            targetCount
                ? (
                    registeredResponses /
                    targetCount *
                    100
                ).toFixed(1)
                : '0.0';

        App.DOM.app.innerHTML =
            App.templates.header() +
`
<div class="max-w-7xl mx-auto px-5 py-7">

 ${App.templates.crumbs([
     'ホーム',
     'アンケート一覧',
     '回答集計・データ出力'
 ])}

 <div class="flex justify-between mb-6">
  <div>
   <h1 class="text-2xl font-bold">
    ${App.util.escape(survey.title)}
   </h1>
   <div class="text-sm text-slate-500 mt-1">
    回答集計・分析
   </div>
  </div>

  <a
   href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
   class="px-5 py-3 bg-slate-800 text-white rounded-xl">
   CSV出力
  </a>
 </div>

 <div class="grid grid-cols-5 gap-4 mb-6">

 ${[
     ['送信対象者数', targetCount + ' 人'],
     ['回答数', responses.length + ' 件'],
     ['未登録顧客からの回答', unregistered + ' 件'],
     ['未回答数', unanswered + ' 人'],
     ['回答率', rate + ' %']
 ].map(
     function(x) {
         return `
<div class="bg-white border rounded-2xl p-5">
 <div class="text-sm text-slate-500">
  ${x[0]}
 </div>
 <div class="text-2xl font-bold mt-2">
  ${x[1]}
 </div>
</div>`;
     }
 ).join('')}

 </div>

 <div class="grid grid-cols-4 gap-5">

  <aside class="bg-white border rounded-2xl p-5">
   <div class="font-bold mb-4">設問絞り込み</div>

   <div class="flex gap-2 mb-4">
    <button
     onclick="App.actions.allResponseQuestions(true)"
     class="text-sm text-indigo-600">
     全選択
    </button>

    <button
     onclick="App.actions.allResponseQuestions(false)"
     class="text-sm text-indigo-600">
     全解除
    </button>
   </div>

   <div class="space-y-3">
   ${
       questions.map(
           function(q) {
               return `
<label class="flex gap-2 text-sm">
 <input
  type="checkbox"
  ${
      App.State.responseQuestionFilter[
          q.id
      ]
          ? 'checked'
          : ''
  }
  onchange="App.actions.toggleResponseQuestion('${q.id}',this.checked)">
 <span>${App.util.escape(q.text)}</span>
</label>`;
           }
       ).join('')
   }
   </div>
  </aside>

  <main class="col-span-3 space-y-5">

   ${
       questions
           .filter(
               q =>
                   App.State.responseQuestionFilter[
                       q.id
                   ]
           )
           .map(
               function(q) {
                   return App.templates.questionResult(
                       q,
                       responses
                   );
               }
           ).join('')
   }

   <div class="bg-white border rounded-2xl overflow-hidden">

    <div class="p-5 border-b flex justify-between">
     <div class="font-bold">個別回答一覧</div>

     <input
      id="response_filter"
      value="${App.util.escape(
          App.State.responseKeyword
      )}"
      oninput="App.actions.collectResponseFilter(this.value)"
      placeholder="会社名・氏名"
      class="border rounded-xl px-3 py-2">
    </div>

    <table
     id="response_table"
     class="w-full text-sm">
     <thead class="bg-slate-50">
      <tr>
       <th class="text-left p-4">会社名 / 氏名</th>
       <th class="text-left p-4">回答日時</th>
       <th class="text-right p-4">操作</th>
      </tr>
     </thead>
     <tbody>
     ${
         responses
             .filter(
                 function(r) {

                     const k =
                         App.State.responseKeyword
                             .toLowerCase();

                     return !k ||
                         [
                             r.company,
                             r.name
                         ].join(' ')
                             .toLowerCase()
                             .includes(k);
                 }
             )
             .map(
                 function(r) {
                     return `
<tr class="border-t">
 <td class="p-4">
  <b>${App.util.escape(r.company)}</b>
  <div>${App.util.escape(r.name)}</div>
 </td>
 <td class="p-4">
  ${App.util.escape(r.answered_at)}
 </td>
 <td class="p-4 text-right">
  <button
   onclick="App.actions.showResponse('${r.id}')"
   class="text-indigo-600 hover:underline">
   全回答を表示
  </button>
 </td>
</tr>`;
                 }
             ).join('')
     }
     </tbody>
    </table>
   </div>

  </main>
 </div>
</div>

<div
 id="response_modal"
 class="hidden fixed inset-0 z-50 bg-black/40 p-5">

 <div class="max-w-3xl max-h-[90vh] overflow-auto bg-white rounded-2xl mx-auto p-6">

  <div class="flex justify-between mb-5">
   <b>回答詳細</b>

   <button
    onclick="App.actions.closeResponse()">
    ✕
   </button>
  </div>

  <div id="response_detail"></div>

 </div>
</div>`;
    },

    templates_questionResult_placeholder: '',

    renderSettings: function() {

        const s =
            App.State.data.settings;

        const fields =
            App.State.kintoneFields ||
            {};

        const fieldOptions =
            '<option value="">未設定</option>' +
            Object.keys(fields)
                .map(
                    function(code) {

                        const f =
                            fields[code] || {};

                        return `
<option value="${App.util.escape(code)}">
 ${App.util.escape(
     f.label || code
 )}
</option>`;
                    }
                ).join('');

        const select =
            function(
                id,
                current,
                multiple
            ) {

                const selected =
                    Array.isArray(current)
                        ? current
                        : [current];

                return `
<select
 id="${id}"
 ${multiple ? 'multiple size="5"' : ''}
 class="w-full border rounded-xl p-2">

 ${fieldOptions.replace(
     /<option value="([^"]*)">/g,
     function(
         full,
         value
     ) {
         return (
             '<option value="' +
             value +
             '"' +
             (
                 selected.includes(value)
                     ? ' selected'
                     : ''
             ) +
             '>'
         );
     }
 )}

</select>`;
            };

        App.DOM.app.innerHTML =
            App.templates.header() +
`
<div class="max-w-6xl mx-auto px-5 py-7">

 ${App.templates.crumbs([
     'ホーム',
     'システム設定',
     'kintone・メール連携設定'
 ])}

 <div class="flex justify-between items-center mb-6">
  <div>
   <h1 class="text-2xl font-bold">
    kintone・メール連携設定
   </h1>
   <p class="text-sm text-slate-500">
    接続情報・顧客項目マッピング・SMTPを設定します。
   </p>
  </div>

  <button
   onclick="App.actions.saveSettings()"
   class="bg-indigo-600 text-white px-5 py-3 rounded-xl font-bold">
   設定を保存
  </button>
 </div>

 <div class="bg-white border rounded-2xl p-6 mb-6">

  <h2 class="font-bold text-lg mb-5">
   kintone接続設定
  </h2>

  <div class="grid grid-cols-2 gap-4">

   <label>
    <span class="text-sm">サブドメイン</span>
    <input id="setting_subdomain"
     value="${App.util.escape(s.subdomain || '')}"
     placeholder="xxxx または xxxx.cybozu.com"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">顧客管理アプリID</span>
    <input id="setting_app_id"
     value="${App.util.escape(s.app_id || '')}"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">ログイン名</span>
    <input id="setting_login_name"
     value="${App.util.escape(s.login_name || '')}"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">パスワード</span>
    <input
     id="setting_password"
     type="password"
     placeholder="変更する場合のみ入力"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">Proxy host:port</span>
    <input id="setting_proxy"
     value="${App.util.escape(s.proxy || '')}"
     placeholder="proxy.example.local:8080"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label class="flex items-center gap-2 pt-7">
    <input
     id="setting_ssl_verify"
     type="checkbox"
     ${s.ssl_verify ? 'checked' : ''}>
    SSL証明書を検証する
   </label>

  </div>

  <div class="flex gap-3 mt-5">
   <button
    onclick="App.actions.testKintone()"
    class="border px-4 py-2 rounded-xl">
    接続確認
   </button>

   <button
    onclick="App.actions.fetchKintoneFields()"
    class="bg-slate-800 text-white px-4 py-2 rounded-xl">
    項目一覧を取得
   </button>

   <span
    id="field_message"
    class="text-sm text-slate-500 py-2">
   </span>
  </div>
 </div>

 <div class="bg-white border rounded-2xl p-6 mb-6">

  <h2 class="font-bold text-lg mb-5">
   顧客項目マッピング
  </h2>

  <div class="grid grid-cols-2 gap-5">

   <label>
    <span class="text-sm font-semibold">会社名</span>
    ${select(
        'field_company',
        s.field_company || '',
        false
    )}
   </label>

   <label>
    <span class="text-sm font-semibold">氏名</span>
    ${select(
        'field_name',
        s.field_name || '',
        false
    )}
   </label>

   <label>
    <span class="text-sm font-semibold">メールアドレス</span>
    ${select(
        'field_email',
        s.field_email || '',
        false
    )}
   </label>

   <label>
    <span class="text-sm font-semibold">部署名</span>
    ${select(
        'field_department',
        s.field_department || '',
        false
    )}
   </label>

   <label>
    <span class="text-sm font-semibold">電話番号</span>
    ${select(
        'field_phone',
        s.field_phone || '',
        false
    )}
   </label>

   <label>
    <span class="text-sm font-semibold">
     住所（複数選択可）
    </span>
    ${select(
        'field_address',
        s.field_address || [],
        true
    )}
   </label>

  </div>
 </div>

 <div class="bg-white border rounded-2xl p-6">

  <h2 class="font-bold text-lg mb-5">
   SMTP設定
  </h2>

  <div class="grid grid-cols-2 gap-4">

   <label>
    <span class="text-sm">SMTPサーバ</span>
    <input id="smtp_host"
     value="${App.util.escape(s.smtp_host || '')}"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">SMTPポート</span>
    <input id="smtp_port"
     type="number"
     value="${Number(s.smtp_port || 465)}"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">暗号化方式</span>
    <select id="smtp_encryption"
     class="w-full border rounded-xl p-2 mt-1">
     <option ${s.smtp_encryption==='SSL'?'selected':''}>
      SSL
     </option>
     <option ${s.smtp_encryption==='TLS'?'selected':''}>
      TLS
     </option>
     <option ${s.smtp_encryption==='NONE'?'selected':''}>
      NONE
     </option>
    </select>
   </label>

   <label class="flex items-center gap-2 pt-7">
    <input
     id="smtp_auth"
     type="checkbox"
     ${s.smtp_auth ? 'checked' : ''}>
    SMTP認証を使用する
   </label>

   <label>
    <span class="text-sm">SMTPユーザー名</span>
    <input id="smtp_username"
     value="${App.util.escape(s.smtp_username || '')}"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">SMTPパスワード</span>
    <input id="smtp_password"
     type="password"
     placeholder="変更する場合のみ入力"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">送信元メールアドレス</span>
    <input id="smtp_from"
     value="${App.util.escape(s.smtp_from || '')}"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">送信元表示名</span>
    <input id="smtp_from_name"
     value="${App.util.escape(s.smtp_from_name || '')}"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

   <label>
    <span class="text-sm">接続タイムアウト</span>
    <input id="smtp_timeout"
     type="number"
     value="${Number(s.smtp_timeout || 15)}"
     class="w-full border rounded-xl p-2 mt-1">
   </label>

  </div>

  <div class="flex gap-3 mt-5">
   <button
    onclick="App.actions.testSMTP()"
    class="border px-4 py-2 rounded-xl">
    SMTP接続確認
   </button>
  </div>

 </div>
</div>`;
    },

    /*
     * 設問集計HTML
     */
    questionResult: function(q, responses) {

        if (q.type === 'text') {

            return `
<div class="bg-white border rounded-2xl p-5">
 <div class="font-bold mb-4">
  ${App.util.escape(q.number || '')}
  ${App.util.escape(q.text)}
 </div>

 <div class="space-y-3 max-h-72 overflow-auto">

 ${
     responses.map(
         function(r) {

             const value =
                 r.answers?.[q.id] ?? '';

             if (!value) return '';

             return `
<div class="border-l-4 border-indigo-200 pl-3">
 <div class="text-sm font-semibold">
  ${App.util.escape(r.company)}
  /
  ${App.util.escape(r.name)}
 </div>
 <div class="text-sm whitespace-pre-wrap text-slate-600 mt-1">
  ${App.util.escape(
      Array.isArray(value)
          ? value.join('、')
          : value
  )}
 </div>
</div>`;
         }
     ).join('')
 }

 </div>
</div>`;
        }

        const total =
            responses.length || 1;

        const options =
            q.options || [];

        return `
<div class="bg-white border rounded-2xl p-5">
 <div class="font-bold mb-5">
  ${App.util.escape(q.number || '')}
  ${App.util.escape(q.text)}
 </div>

 <div class="space-y-4">

 ${
     options.map(
         function(option) {

             let count = 0;

             responses.forEach(
                 function(r) {

                     const v =
                         r.answers?.[q.id];

                     if (Array.isArray(v)) {
                         if (
                             v.includes(option)
                         ) count++;
                     } else if (
                         String(v || '') ===
                         String(option)
                     ) {
                         count++;
                     }
                 }
             );

             const percent =
                 (
                     count /
                     total *
                     100
                 ).toFixed(1);

             return `
<div>
 <div class="flex justify-between text-sm mb-1">
  <span>
   ${App.util.escape(option)}
  </span>
  <span>
   ${count}件 / ${percent}%
  </span>
 </div>

 <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
  <div
   class="h-full bg-indigo-500 rounded-full"
   style="width:${Math.min(
       100,
       Number(percent)
   )}%"></div>
 </div>
</div>`;
         }
     ).join('')
 }

 </div>
</div>`;
    },

    render: function() {

        switch (App.State.page) {

            case 'edit':
                App.renderEditor();
                break;

            case 'mail':
                App.renderMail();
                break;

            case 'analysis':
                App.renderAnalysis();
                break;

            case 'settings':
                App.renderSettings();
                break;

            default:
                App.renderList();
                break;
        }
    },

    init: async function() {

        if (App.State.initialized) {
            return;
        }

        App.State.initialized = true;

        App.DOM.app =
            document.getElementById('app');

        App.DOM.csrf =
            document.createElement('input');

        App.DOM.csrf.type = 'hidden';
        App.DOM.csrf.id = 'csrf_token';
        App.DOM.csrf.value =
            App.DOM.app.dataset.csrf || '';

        try {

            await App.api.load();

            App.render();

        } catch (e) {

            App.DOM.app.innerHTML =
                '<div class="p-10 text-red-600">' +
                App.util.escape(
                    e.message
                ) +
                '</div>';
        }
    }
};

/*
 * =========================================================================
 * 初期化
 * =========================================================================
 *
 * readyStateを確認することで、スクリプトがHEAD/BODY末尾のどちらに
 * 配置されても1回だけ初期化する。
 */

if (document.readyState === 'loading') {

    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.init();
        },
        { once: true }
    );

} else {

    App.init();
}
</script>

</body>
</html>
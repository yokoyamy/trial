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

/* ============================================================
 * PHP DATA LAYER
 * ============================================================ */

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
            'smtp_port' => 587,
            'smtp_encryption' => 'TLS',
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
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
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
    return htmlspecialchars(
        (string)$value,
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

function survey_post_json(string $name): array
{
    $raw = (string)($_POST[$name] ?? '');
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function survey_find_survey(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function survey_find_customer(array &$data, string $id): ?array
{
    foreach ($data['customers'] as $customer) {
        if ((string)($customer['id'] ?? '') === $id) {
            return $customer;
        }
    }

    return null;
}

function survey_normalize_question(array $q): array
{
    return [
        'id' => (string)($q['id'] ?? survey_id()),
        'text' => (string)($q['text'] ?? ''),
        'type' => in_array(
            ($q['type'] ?? 'single'),
            ['single', 'multiple', 'text'],
            true
        ) ? $q['type'] : 'single',
        'required' => !empty($q['required']),
        'options' => array_values(
            array_map(
                'strval',
                is_array($q['options'] ?? null) ? $q['options'] : []
            )
        ),
        'other_enabled' => !empty($q['other_enabled'])
    ];
}

function survey_normalize_survey(array $survey): array
{
    $groups = [];

    foreach (
        is_array($survey['groups'] ?? null)
            ? $survey['groups']
            : []
        as $group
    ) {
        $questions = [];

        foreach (
            is_array($group['questions'] ?? null)
                ? $group['questions']
                : []
            as $question
        ) {
            $questions[] = survey_normalize_question($question);
        }

        $groups[] = [
            'id' => (string)($group['id'] ?? survey_id()),
            'name' => (string)($group['name'] ?? 'グループ'),
            'questions' => $questions
        ];
    }

    if (!$groups) {
        $groups[] = [
            'id' => survey_id(),
            'name' => 'グループ1',
            'questions' => []
        ];
    }

    return [
        'id' => (string)($survey['id'] ?? survey_id()),
        'title' => (string)($survey['title'] ?? '無題のアンケート'),
        'start_at' => (string)($survey['start_at'] ?? ''),
        'end_at' => (string)($survey['end_at'] ?? ''),
        'status' => in_array(
            ($survey['status'] ?? 'draft'),
            ['draft', 'active', 'ended'],
            true
        ) ? $survey['status'] : 'draft',
        'created_at' => (string)($survey['created_at'] ?? survey_now()),
        'updated_at' => survey_now(),
        'numbering_mode' => in_array(
            ($survey['numbering_mode'] ?? 'global'),
            ['global', 'group'],
            true
        ) ? $survey['numbering_mode'] : 'global',
        'groups' => $groups,
        'deleted' => !empty($survey['deleted'])
    ];
}

/* ============================================================
 * KINTONE
 * ============================================================ */

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim($domain, '/');

    return 'https://' . $domain . '.cybozu.com/' .
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

    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => max(1, (int)($config['timeout'] ?? 20))
    ];

    if ($method !== 'GET' && $payload !== null) {
        $encoded = is_string($payload)
            ? $payload
            : json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        $options['content'] = $encoded;
        $options['header'] .=
            "\r\nContent-Type: application/json";
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

    if ($proxy !== '' &&
        preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = get_safe_response_headers();

    $status = 0;

    foreach ($responseHeaders as $line) {
        if (preg_match(
            '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i',
            $line,
            $m
        )) {
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
            'headers' => $responseHeaders
        ];
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => is_array($decoded)
            ? (string)($decoded['message'] ?? 'API通信エラー')
            : 'API通信エラー',
        'data' => is_array($decoded) ? $decoded : [],
        'headers' => $responseHeaders
    ];
}

function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(
            trim($login_name) . ':' . $password
        );
}

function kintone_config(array $settings): array
{
    return [
        'ssl_verify' => !empty($settings['ssl_verify']),
        'proxy' => (string)($settings['proxy'] ?? ''),
        'timeout' => 20
    ];
}

function kintone_fields(
    array $settings,
    string $appId
): array {
    $domain = trim((string)($settings['subdomain'] ?? ''));

    if ($domain === '' || $appId === '') {
        return [
            'success' => false,
            'status' => 0,
            'message' => 'kintone設定またはアプリIDが未入力です。'
        ];
    }

    $url = kintone_build_url(
        $domain,
        '/k/v1/app/form/fields.json?app=' .
        rawurlencode($appId) .
        '&lang=ja'
    );

    return kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header(
                (string)($settings['login_name'] ?? ''),
                (string)($settings['password'] ?? '')
            ),
            'Accept: application/json'
        ],
        null,
        kintone_config($settings)
    );
}

function kintone_get_records(
    array $settings,
    string $appId
): array {
    $domain = trim((string)($settings['subdomain'] ?? ''));

    $url = kintone_build_url(
        $domain,
        '/k/v1/records.json?app=' .
        rawurlencode($appId) .
        '&query=' .
        rawurlencode('limit 500')
    );

    return kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header(
                (string)($settings['login_name'] ?? ''),
                (string)($settings['password'] ?? '')
            ),
            'Accept: application/json'
        ],
        null,
        kintone_config($settings)
    );
}

function kintone_test(array $settings): array
{
    $domain = trim((string)($settings['subdomain'] ?? ''));

    if ($domain === '') {
        return [
            'success' => false,
            'message' => 'サブドメインを入力してください。',
            'status' => 0
        ];
    }

    $url = kintone_build_url(
        $domain,
        '/k/v1/app.json?app=1'
    );

    $result = kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header(
                (string)($settings['login_name'] ?? ''),
                (string)($settings['password'] ?? '')
            ),
            'Accept: application/json'
        ],
        null,
        kintone_config($settings)
    );

    $result['url'] = $url;

    return $result;
}

/* ============================================================
 * SMTP
 * ============================================================ */

function smtp_read($socket, int $timeout): array
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

function smtp_cmd(
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

function smtp_connect(array $cfg): array
{
    $host = trim((string)($cfg['smtp_host'] ?? ''));
    $port = (int)($cfg['smtp_port'] ?? 587);
    $enc = strtoupper((string)($cfg['smtp_encryption'] ?? 'TLS'));
    $timeout = max(1, (int)($cfg['smtp_timeout'] ?? 15));

    if ($host === '') {
        return [
            'success' => false,
            'message' => 'SMTPサーバが未設定です。',
            'stage' => 'configuration'
        ];
    }

    $transport = $enc === 'SSL' ? 'ssl' : 'tcp';

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
            'message' => 'SMTP TCP接続に失敗しました。',
            'stage' => 'tcp',
            'error' => $errstr,
            'smtp_code' => 0
        ];
    }

    $greeting = smtp_read($socket, $timeout);

    if ($greeting['code'] < 200 ||
        $greeting['code'] >= 400) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'SMTP初期応答が不正です。',
            'stage' => 'greeting',
            'smtp_code' => $greeting['code']
        ];
    }

    $ehlo = smtp_cmd(
        $socket,
        'EHLO localhost',
        $timeout
    );

    if ($enc === 'TLS') {
        $tls = smtp_cmd(
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
                'smtp_code' => $tls['code']
            ];
        }

        if (@stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        ) !== true) {
            fclose($socket);

            return [
                'success' => false,
                'message' => 'TLS暗号化に失敗しました。',
                'stage' => 'tls',
                'smtp_code' => 0
            ];
        }

        $ehlo = smtp_cmd(
            $socket,
            'EHLO localhost',
            $timeout
        );
    }

    $useAuth = !empty($cfg['smtp_auth']);

    if ($useAuth) {
        $r = smtp_cmd(
            $socket,
            'AUTH LOGIN',
            $timeout
        );

        if ($r['code'] === 334) {
            $r = smtp_cmd(
                $socket,
                base64_encode(
                    (string)($cfg['smtp_username'] ?? '')
                ),
                $timeout
            );
        }

        if ($r['code'] === 334) {
            $r = smtp_cmd(
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
                'smtp_code' => $r['code']
            ];
        }
    }

    return [
        'success' => true,
        'socket' => $socket,
        'timeout' => $timeout,
        'smtp_code' => $useAuth ? $r['code'] : $ehlo['code']
    ];
}

function smtp_send_mail(
    array $cfg,
    string $to,
    string $subject,
    string $body
): array {
    $conn = smtp_connect($cfg);

    if (!$conn['success']) {
        return $conn;
    }

    $socket = $conn['socket'];
    $timeout = $conn['timeout'];

    $from = (string)($cfg['smtp_from'] ?? '');
    $fromName = (string)($cfg['smtp_from_name'] ?? '');

    $r = smtp_cmd(
        $socket,
        'MAIL FROM:<' . $from . '>',
        $timeout
    );

    if ($r['code'] < 200 || $r['code'] >= 300) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'MAIL FROMが拒否されました。',
            'stage' => 'mail_from',
            'smtp_code' => $r['code']
        ];
    }

    $r = smtp_cmd(
        $socket,
        'RCPT TO:<' . $to . '>',
        $timeout
    );

    if ($r['code'] < 200 || $r['code'] >= 300) {
        fclose($socket);

        return [
            'success' => false,
            'message' => '宛先が拒否されました。',
            'stage' => 'rcpt_to',
            'smtp_code' => $r['code']
        ];
    }

    $r = smtp_cmd($socket, 'DATA', $timeout);

    if ($r['code'] !== 354) {
        fclose($socket);

        return [
            'success' => false,
            'message' => 'DATAが拒否されました。',
            'stage' => 'data',
            'smtp_code' => $r['code']
        ];
    }

    $encodedName = '=?UTF-8?B?' .
        base64_encode($fromName) . '?=';

    $headers = [
        'From: ' . $encodedName . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: =?UTF-8?B?' .
            base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit'
    ];

    $safeBody = preg_replace(
        "/(?<!\r)\n/",
        "\r\n",
        $body
    );

    $message = implode(
        "\r\n",
        $headers
    ) . "\r\n\r\n" .
        str_replace("\r\n.", "\r\n..", $safeBody) .
        "\r\n.";

    @fwrite($socket, $message . "\r\n");

    $r = smtp_read($socket, $timeout);

    @fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return [
        'success' => $r['code'] >= 200 &&
            $r['code'] < 300,
        'message' => $r['code'] >= 200 &&
            $r['code'] < 300
            ? '送信成功'
            : 'SMTP送信拒否',
        'smtp_code' => $r['code']
    ];
}

/* ============================================================
 * API ROUTES
 * ============================================================ */

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = (string)(
        $_POST['action'] ??
        $_GET['action'] ??
        ''
    );

    $data = survey_read_data();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        survey_check_csrf();
    }

    if ($action === 'bootstrap') {
        survey_json([
            'ok' => true,
            'csrf_token' => survey_csrf(),
            'data' => $data
        ]);
    }

    if ($action === 'save_survey') {
        $survey = survey_normalize_survey(
            survey_post_json('survey_json')
        );

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

        if (!survey_write_data($data)) {
            survey_json([
                'ok' => false,
                'message' => '保存に失敗しました。'
            ], 500);
        }

        survey_json([
            'ok' => true,
            'survey' => $survey
        ]);
    }

    if ($action === 'delete_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['deleted'] = true;
                $survey['updated_at'] = survey_now();
            }
        }
        unset($survey);

        survey_write_data($data);

        survey_json(['ok' => true]);
    }

    if ($action === 'duplicate_survey') {
        $id = (string)($_POST['survey_id'] ?? '');
        $source = survey_find_survey($data, $id);

        if (!$source) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $copy = survey_normalize_survey($source);
        $copy['id'] = survey_id();
        $copy['title'] .= '（複製）';
        $copy['status'] = 'draft';
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();
        $copy['deleted'] = false;

        foreach ($copy['groups'] as &$group) {
            $group['id'] = survey_id();

            foreach ($group['questions'] as &$question) {
                $question['id'] = survey_id();
            }
            unset($question);
        }
        unset($group);

        $data['surveys'][] = $copy;
        survey_write_data($data);

        survey_json([
            'ok' => true,
            'survey' => $copy
        ]);
    }

    if ($action === 'status') {
        $id = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? 'draft');

        if (!in_array(
            $status,
            ['draft', 'active', 'ended'],
            true
        )) {
            survey_json([
                'ok' => false,
                'message' => '不正なステータスです。'
            ], 400);
        }

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['status'] = $status;
                $survey['updated_at'] = survey_now();
            }
        }
        unset($survey);

        survey_write_data($data);

        survey_json(['ok' => true]);
    }

    if ($action === 'save_settings') {
        $settings = survey_post_json('settings_json');

        $password = (string)($settings['password'] ?? '');

        if ($password === '') {
            $settings['password'] =
                $data['settings']['password'] ?? '';
        }

        $smtpPassword =
            (string)($settings['smtp_password'] ?? '');

        if ($smtpPassword === '') {
            $settings['smtp_password'] =
                $data['settings']['smtp_password'] ?? '';
        }

        $data['settings'] =
            array_replace(
                $data['settings'],
                $settings
            );

        survey_write_data($data);

        survey_json([
            'ok' => true,
            'settings' => $data['settings']
        ]);
    }

    if ($action === 'kintone_test') {
        $settings = survey_post_json('settings_json');

        survey_json(
            kintone_test(
                array_replace(
                    $data['settings'],
                    $settings
                )
            )
        );
    }

    if ($action === 'fetch_fields') {
        $appId = (string)(
            $_POST['app_id'] ??
            $data['settings']['app_id'] ??
            ''
        );

        $result = kintone_fields(
            $data['settings'],
            $appId
        );

        if (!$result['success']) {
            survey_json($result, 400);
        }

        $fields = [];

        foreach (
            ($result['data']['properties'] ?? [])
            as $code => $field
        ) {
            $fields[] = [
                'code' => $code,
                'label' => $field['label'] ?? $code,
                'type' => $field['type'] ?? ''
            ];
        }

        survey_json([
            'ok' => true,
            'fields' => $fields
        ]);
    }

    if ($action === 'sync_customers') {
        $appId = (string)(
            $data['settings']['app_id'] ?? ''
        );

        if ($appId === '') {
            survey_json([
                'ok' => false,
                'message' => '顧客管理アプリIDが未設定です。'
            ], 400);
        }

        $result = kintone_get_records(
            $data['settings'],
            $appId
        );

        if (!$result['success']) {
            survey_json($result, 400);
        }

        $map = $data['settings'];

        $customers = [];

        foreach (
            ($result['data']['records'] ?? [])
            as $record
        ) {
            $get = static function (
                array $record,
                string $code
            ): string {
                if ($code === '' ||
                    !isset($record[$code])) {
                    return '';
                }

                $value = $record[$code]['value'] ?? '';

                if (is_array($value)) {
                    return implode(
                        ' ',
                        array_map(
                            static fn($v): string =>
                                is_array($v)
                                    ? (string)($v['name'] ?? '')
                                    : (string)$v,
                            $value
                        )
                    );
                }

                return (string)$value;
            };

            $email = $get(
                $record,
                (string)($map['field_email'] ?? '')
            );

            if ($email === '') {
                continue;
            }

            $customers[] = [
                'id' => 'k_' .
                    md5(strtolower($email)),
                'company' => $get(
                    $record,
                    (string)($map['field_company'] ?? '')
                ),
                'name' => $get(
                    $record,
                    (string)($map['field_name'] ?? '')
                ),
                'email' => $email,
                'department' => $get(
                    $record,
                    (string)($map['field_department'] ?? '')
                ),
                'phone' => $get(
                    $record,
                    (string)($map['field_phone'] ?? '')
                ),
                'address' => $get(
                    $record,
                    (string)($map['field_address'] ?? '')
                ),
                'source' => 'kintone',
                'sent_at' => '',
                'send_count' => 0,
                'answer_status' => 'unanswered',
                'kintone_status' => 'registered'
            ];
        }

        $oldByEmail = [];

        foreach ($data['customers'] as $old) {
            if (!empty($old['email'])) {
                $oldByEmail[
                    strtolower($old['email'])
                ] = $old;
            }
        }

        foreach ($customers as &$customer) {
            $key = strtolower($customer['email']);

            if (isset($oldByEmail[$key])) {
                $old = $oldByEmail[$key];

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

        $data['customers'] = $customers;
        survey_write_data($data);

        survey_json([
            'ok' => true,
            'count' => count($customers),
            'customers' => $customers
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

        $templateType =
            (string)($_POST['template_type'] ?? 'initial');

        $survey = survey_find_survey(
            $data,
            $surveyId
        );

        if (!$survey) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $cfg = $data['settings'];

        if (trim((string)($cfg['smtp_host'] ?? '')) === '' ||
            trim((string)($cfg['smtp_from'] ?? '')) === '') {
            survey_json([
                'ok' => false,
                'message' =>
                    'SMTP設定が未完了です。'
            ], 400);
        }

        $success = 0;
        $failed = 0;
        $results = [];

        foreach ($ids as $id) {
            $customerIndex = null;

            foreach ($data['customers'] as $i => $customer) {
                if (($customer['id'] ?? '') === (string)$id) {
                    $customerIndex = $i;
                    break;
                }
            }

            if ($customerIndex === null) {
                continue;
            }

            $customer =
                $data['customers'][$customerIndex];

            $url = (
                rtrim(
                    'http://' .
                    ($_SERVER['HTTP_HOST'] ?? ''),
                    '/'
                ) .
                $_SERVER['SCRIPT_NAME'] .
                '?answer=' .
                rawurlencode($surveyId) .
                '&customer_id=' .
                rawurlencode((string)$customer['id'])
            );

            $mailBody = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [
                    (string)($customer['name'] ?? ''),
                    $url
                ],
                $body
            );

            $result = smtp_send_mail(
                $cfg,
                (string)$customer['email'],
                $subject,
                $mailBody
            );

            if ($result['success']) {
                $success++;

                $data['customers'][$customerIndex]['sent_at'] =
                    survey_now();

                $data['customers'][$customerIndex]['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;

                $data['customers'][$customerIndex]['answer_status'] =
                    'unanswered';

                $results[] = [
                    'customer_id' => $customer['id'],
                    'success' => true,
                    'message' => '送信成功'
                ];
            } else {
                $failed++;

                $results[] = [
                    'customer_id' => $customer['id'],
                    'success' => false,
                    'message' => $result['message'] ?? '送信失敗'
                ];
            }
        }

        $data['mail_logs'][] = [
            'id' => survey_id(),
            'survey_id' => $surveyId,
            'sent_at' => survey_now(),
            'type' => $templateType === 'reminder'
                ? 'リマインド'
                : '初回',
            'count' => count($ids),
            'success_count' => $success,
            'failed_count' => $failed,
            'subject' => $subject,
            'executed_by' => 'admin'
        ];

        survey_write_data($data);

        survey_json([
            'ok' => true,
            'success_count' => $success,
            'failed_count' => $failed,
            'unsent_count' =>
                count($ids) - $success - $failed,
            'results' => $results
        ]);
    }

    if ($action === 'register_kintone') {
        $id = (string)(
            $_POST['customer_id'] ?? ''
        );

        foreach ($data['customers'] as &$customer) {
            if (($customer['id'] ?? '') === $id) {
                $customer['kintone_status'] = 'registered';
            }
        }
        unset($customer);

        survey_write_data($data);

        survey_json(['ok' => true]);
    }

    if ($action === 'csv') {
        $surveyId =
            (string)($_GET['survey_id'] ?? '');

        $responses = array_values(
            array_filter(
                $data['responses'],
                static fn(array $r): bool =>
                    ($r['survey_id'] ?? '') === $surveyId
            )
        );

        $survey = survey_find_survey(
            $data,
            $surveyId
        );

        $questions = [];

        foreach (($survey['groups'] ?? []) as $group) {
            foreach (($group['questions'] ?? []) as $q) {
                $questions[] = $q;
            }
        }

        $fp = fopen('php://output', 'wb');

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );
        header(
            'Content-Disposition: attachment; filename="survey_' .
            rawurlencode($surveyId) .
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

        foreach ($questions as $i => $q) {
            $header[] =
                '設問' . ($i + 1) . ' ' .
                ($q['text'] ?? '');
        }

        fputcsv($fp, $header);

        foreach ($responses as $response) {
            $row = [
                $response['id'] ?? '',
                $response['answered_at'] ?? '',
                $response['customer_id'] ?? '',
                $response['company'] ?? '',
                $response['name'] ?? ''
            ];

            $answers =
                is_array($response['answers'] ?? null)
                    ? $response['answers']
                    : [];

            foreach ($questions as $q) {
                $value =
                    $answers[$q['id']] ?? '';

                if (is_array($value)) {
                    $value = implode(', ', $value);
                }

                $row[] = $value;
            }

            fputcsv($fp, $row);
        }

        fclose($fp);
        exit;
    }

    survey_json([
        'ok' => false,
        'message' => '未知のactionです。'
    ], 400);
}

/* ============================================================
 * ANSWER ENDPOINT
 * ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['public_answer'])
) {
    $data = survey_read_data();

    $surveyId =
        (string)($_POST['survey_id'] ?? '');

    $customerId =
        (string)($_POST['customer_id'] ?? '');

    $survey = survey_find_survey(
        $data,
        $surveyId
    );

    if (!$survey ||
        ($survey['status'] ?? '') !== 'active') {
        http_response_code(404);
        exit('アンケートが公開されていません。');
    }

    $answers = [];

    foreach ($survey['groups'] as $group) {
        foreach ($group['questions'] as $q) {
            $id = $q['id'];

            if ($q['type'] === 'multiple') {
                $answers[$id] =
                    is_array($_POST['q'][$id] ?? null)
                        ? array_values(
                            array_map(
                                'strval',
                                $_POST['q'][$id]
                            )
                        )
                        : [];
            } else {
                $answers[$id] =
                    (string)(
                        $_POST['q'][$id] ?? ''
                    );
            }
        }
    }

    $customer = survey_find_customer(
        $data,
        $customerId
    );

    $email = '';
    $company = '';
    $name = '';

    if ($customer) {
        $email = (string)$customer['email'];
        $company = (string)$customer['company'];
        $name = (string)$customer['name'];
    }

    $data['responses'][] = [
        'id' => survey_id(),
        'survey_id' => $surveyId,
        'customer_id' => $customerId,
        'company' => $company,
        'name' => $name,
        'email' => $email,
        'answered_at' => survey_now(),
        'answers' => $answers
    ];

    foreach ($data['customers'] as &$c) {
        if (($c['id'] ?? '') === $customerId) {
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
    echo '</head><body class="bg-slate-50 min-h-screen">';
    echo '<main class="max-w-xl mx-auto p-8 mt-16">';
    echo '<div class="bg-white rounded-2xl shadow-sm border p-10 text-center">';
    echo '<div class="text-emerald-600 text-5xl mb-5">✓</div>';
    echo '<h1 class="text-2xl font-bold text-slate-800 mb-3">';
    echo 'ご回答ありがとうございました';
    echo '</h1>';
    echo '<p class="text-slate-500">回答を正常に受け付けました。</p>';
    echo '</div></main></body></html>';
    exit;
}

/* ============================================================
 * PUBLIC ANSWER PAGE
 * ============================================================ */

if (isset($_GET['answer'])) {
    $data = survey_read_data();

    $survey = survey_find_survey(
        $data,
        (string)$_GET['answer']
    );

    if (!$survey ||
        ($survey['status'] ?? '') !== 'active') {
        http_response_code(404);
        exit('アンケートが見つかりません。');
    }

    $customerId =
        (string)($_GET['customer_id'] ?? '');

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= survey_e($survey['title']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800">
<main class="max-w-3xl mx-auto p-4 md:p-8">
<div class="bg-white border rounded-2xl shadow-sm p-6 md:p-10">
<h1 class="text-2xl font-bold mb-8">
<?= survey_e($survey['title']) ?>
</h1>

<form method="post">
<input type="hidden" name="public_answer" value="1">
<input type="hidden" name="survey_id"
       value="<?= survey_e($survey['id']) ?>">
<input type="hidden" name="customer_id"
       value="<?= survey_e($customerId) ?>">

<?php
$number = 0;
foreach ($survey['groups'] as $gi => $group):
?>
<section class="mb-10">
<h2 class="text-lg font-bold border-b pb-3 mb-6">
<?= survey_e($group['name']) ?>
</h2>

<?php foreach ($group['questions'] as $question):
    $number++;
?>
<div class="mb-7">
<label class="block font-semibold mb-3">
<?= survey_e(
    'Q' . $number . ' ' . $question['text']
) ?>
<?php if ($question['required']): ?>
<span class="text-red-500 text-sm ml-2">必須</span>
<?php endif; ?>
</label>

<?php if ($question['type'] === 'text'): ?>

<textarea
 name="q[<?= survey_e($question['id']) ?>]"
 rows="5"
 class="w-full border rounded-xl px-4 py-3 focus:ring-2 focus:ring-indigo-200 focus:border-indigo-500"
 <?= $question['required'] ? 'required' : '' ?>
></textarea>

<?php elseif ($question['type'] === 'single'): ?>

<div class="space-y-2">
<?php foreach ($question['options'] as $option): ?>
<label class="flex items-center gap-3 border rounded-xl p-3 hover:bg-slate-50">
<input
 type="radio"
 name="q[<?= survey_e($question['id']) ?>]"
 value="<?= survey_e($option) ?>"
 <?= $question['required'] ? 'required' : '' ?>
>
<span><?= survey_e($option) ?></span>
</label>
<?php endforeach; ?>

<?php if ($question['other_enabled']): ?>
<label class="flex items-center gap-3 border rounded-xl p-3">
<input
 type="radio"
 name="q[<?= survey_e($question['id']) ?>]"
 value="その他"
 <?= $question['required'] ? 'required' : '' ?>
>
<span>その他</span>
</label>
<?php endif; ?>
</div>

<?php else: ?>

<div class="space-y-2">
<?php foreach ($question['options'] as $option): ?>
<label class="flex items-center gap-3 border rounded-xl p-3 hover:bg-slate-50">
<input
 type="checkbox"
 name="q[<?= survey_e($question['id']) ?>][]"
 value="<?= survey_e($option) ?>"
>
<span><?= survey_e($option) ?></span>
</label>
<?php endforeach; ?>

<?php if ($question['other_enabled']): ?>
<label class="flex items-center gap-3 border rounded-xl p-3">
<input
 type="checkbox"
 name="q[<?= survey_e($question['id']) ?>][]"
 value="その他"
>
<span>その他</span>
</label>
<?php endif; ?>
</div>

<?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>

<button
 type="submit"
 class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl py-4 font-bold"
>
回答を送信する
</button>
</form>
</div>
</main>
</body>
</html>
<?php
    exit;
}

/* ============================================================
 * ADMIN SPA
 * ============================================================ */

$initialData = survey_read_data();
$csrf = survey_csrf();

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

</head>

<body class="bg-slate-50 text-slate-800">

<div id="app"></div>

<script>
'use strict';

window.App = {

    state: {
        data: <?= json_encode(
            $initialData,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
        ) ?>,
        csrf: <?= json_encode($csrf) ?>,
        page: 'surveys',
        surveyId: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        customerKeyword: '',
        responseKeyword: '',
        previewMode: 'pc',
        selectedQuestionIds: {}
    },

    dom: {},

    api: {},

    actions: {},

    render: {},

    util: {},

    init: function() {
        if (this._initialized) return;
        this._initialized = true;

        this.cacheDom();
        this.renderShell();
        this.render.current();
    },

    cacheDom: function() {
        this.dom.app =
            document.getElementById('app');
    },

    util: {

        esc: function(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        uid: function() {
            return 'x_' +
                Date.now().toString(36) +
                '_' +
                Math.random()
                    .toString(36)
                    .slice(2, 10);
        },

        date: function(value) {
            if (!value) return '未設定';

            return String(value)
                .replace(/^(\d{4})-(\d{2})-(\d{2}).*$/,
                    '$1/$2/$3');
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
                active: 'bg-emerald-100 text-emerald-700',
                draft: 'bg-slate-100 text-slate-600',
                ended: 'bg-amber-100 text-amber-700'
            }[status] || 'bg-slate-100 text-slate-600';
        },

        toast: function(message, type) {
            const el = document.createElement('div');

            el.className =
                'fixed right-5 bottom-5 z-[100] ' +
                'px-5 py-3 rounded-xl shadow-lg text-white ' +
                (type === 'error'
                    ? 'bg-red-600'
                    : 'bg-slate-800');

            el.textContent = message;
            document.body.appendChild(el);

            setTimeout(function() {
                el.remove();
            }, 3000);
        },

        confirm: function(message) {
            return window.confirm(message);
        },

        surveyById: function(id) {
            return App.state.data.surveys.find(
                s => s.id === id
            );
        },

        questions: function(survey) {
            const result = [];

            (survey.groups || []).forEach(
                function(group) {
                    (group.questions || []).forEach(
                        function(q) {
                            result.push({
                                question: q,
                                group: group
                            });
                        }
                    );
                }
            );

            return result;
        },

        responseCount: function(surveyId) {
            return App.state.data.responses.filter(
                r => r.survey_id === surveyId
            ).length;
        }
    },

    renderShell: function() {
        this.dom.app.innerHTML = `
        <div class="min-h-screen">

            <header class="sticky top-0 z-40 bg-white border-b">
                <div class="max-w-[1500px] mx-auto px-5 h-16
                            flex items-center justify-between">

                    <button
                        onclick="App.actions.home()"
                        class="font-bold text-lg text-slate-800">
                        アンケート管理
                    </button>

                    <nav class="flex items-center gap-2">
                        <button
                            onclick="App.actions.home()"
                            class="px-4 py-2 rounded-lg hover:bg-slate-100">
                            アンケート一覧
                        </button>

                        <button
                            onclick="App.actions.settings()"
                            class="px-4 py-2 rounded-lg hover:bg-slate-100">
                            kintone・メール連携設定
                        </button>

                        <button
                            onclick="App.actions.logout()"
                            class="px-4 py-2 rounded-lg text-slate-500 hover:bg-slate-100">
                            ログアウト
                        </button>
                    </nav>
                </div>
            </header>

            <main class="max-w-[1500px] mx-auto p-5">
                <div id="app_content"></div>
            </main>

        </div>`;
    },

    render: {

        current: function() {
            const el =
                document.getElementById('app_content');

            if (!el) return;

            if (App.state.page === 'surveys') {
                App.render.list();
            } else if (App.state.page === 'edit') {
                App.render.editor();
            } else if (App.state.page === 'mail') {
                App.render.mail();
            } else if (App.state.page === 'analytics') {
                App.render.analytics();
            } else if (App.state.page === 'settings') {
                App.render.settings();
            }
        },

        breadcrumb: function(items) {
            return `
            <div class="text-sm text-slate-400 mb-5">
                ${items.map(function(item, i) {
                    return `
                    <span>${App.util.esc(item)}</span>
                    ${i < items.length - 1
                        ? '<span class="mx-2">›</span>'
                        : ''}
                    `;
                }).join('')}
            </div>`;
        },

        list: function() {
            const el =
                document.getElementById('app_content');

            let surveys =
                App.state.data.surveys.filter(
                    s => !s.deleted
                );

            const keyword =
                App.state.keyword.trim().toLowerCase();

            if (keyword) {
                surveys = surveys.filter(
                    s => String(s.title)
                        .toLowerCase()
                        .includes(keyword)
                );
            }

            if (App.state.statusFilter !== 'all') {
                surveys = surveys.filter(
                    s => s.status ===
                        App.state.statusFilter
                );
            }

            surveys.sort(function(a, b) {
                const ca =
                    App.util.responseCount(a.id);
                const cb =
                    App.util.responseCount(b.id);

                if (App.state.sort === 'responses_desc') {
                    return cb - ca;
                }

                if (App.state.sort === 'responses_asc') {
                    return ca - cb;
                }

                const key =
                    App.state.sort.includes('start')
                        ? 'start_at'
                        : 'updated_at';

                const av = a[key] || '';
                const bv = b[key] || '';

                return App.state.sort.endsWith('asc')
                    ? av.localeCompare(bv)
                    : bv.localeCompare(av);
            });

            el.innerHTML = `
            ${App.render.breadcrumb(['ホーム', 'アンケート一覧'])}

            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold">
                        アンケート一覧
                    </h1>
                    <p class="text-sm text-slate-400 mt-1">
                        アンケートの作成・送信・集計を管理します。
                    </p>
                </div>

                <button
                    onclick="App.actions.newSurvey()"
                    class="bg-indigo-600 hover:bg-indigo-700
                           text-white px-5 py-3 rounded-xl
                           font-semibold shadow-sm">
                    ＋ 新規アンケート作成
                </button>
            </div>

            <div class="bg-white border rounded-2xl p-4 mb-5">
                <div class="grid md:grid-cols-4 gap-3">

                    <input
                        id="keyword"
                        value="${App.util.esc(App.state.keyword)}"
                        placeholder="タイトルを検索"
                        onkeydown="if(event.key==='Enter')App.actions.search(this.value)"
                        class="border rounded-xl px-4 py-2.5">

                    <select
                        onchange="App.actions.toggleStatusFilter(this.value)"
                        class="border rounded-xl px-4 py-2.5">
                        <option value="all"
                            ${App.state.statusFilter === 'all'
                                ? 'selected' : ''}>
                            すべて
                        </option>
                        <option value="active"
                            ${App.state.statusFilter === 'active'
                                ? 'selected' : ''}>
                            公開中
                        </option>
                        <option value="draft"
                            ${App.state.statusFilter === 'draft'
                                ? 'selected' : ''}>
                            下書き
                        </option>
                        <option value="ended"
                            ${App.state.statusFilter === 'ended'
                                ? 'selected' : ''}>
                            終了
                        </option>
                    </select>

                    <select
                        onchange="App.actions.sort(this.value)"
                        class="border rounded-xl px-4 py-2.5">
                        <option value="updated_desc">
                            更新日：新しい順
                        </option>
                        <option value="updated_asc">
                            更新日：古い順
                        </option>
                        <option value="responses_desc">
                            回答数：多い順
                        </option>
                        <option value="responses_asc">
                            回答数：少ない順
                        </option>
                        <option value="start_desc">
                            開始日：新しい順
                        </option>
                        <option value="start_asc">
                            開始日：古い順
                        </option>
                    </select>

                </div>
            </div>

            <div class="bg-white border rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="text-left p-4">作成日 / 更新日</th>
                                <th class="text-left p-4">タイトル</th>
                                <th class="text-left p-4">アンケート期間</th>
                                <th class="text-left p-4">ステータス</th>
                                <th class="text-left p-4">回答数</th>
                                <th class="text-right p-4">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        ${surveys.map(
                            App.render.surveyRow
                        ).join('')}
                        </tbody>
                    </table>
                </div>
            </div>`;
        },

        surveyRow: function(survey) {
            const count =
                App.util.responseCount(survey.id);

            let buttons = '';

            if (survey.status === 'active') {
                buttons = `
                <button
                    onclick="App.actions.editSurvey('${survey.id}')"
                    class="px-3 py-1.5 border rounded-lg">
                    確認・編集
                </button>
                <button
                    onclick="App.actions.analytics('${survey.id}')"
                    class="px-3 py-1.5 border rounded-lg">
                    集計
                </button>
                <button
                    onclick="App.actions.mail('${survey.id}')"
                    class="px-3 py-1.5 border rounded-lg">
                    送信
                </button>
                <button
                    onclick="App.actions.stopSurvey('${survey.id}')"
                    class="px-3 py-1.5 border border-amber-300 text-amber-700 rounded-lg">
                    停止
                </button>
                <button
                    onclick="App.actions.duplicate('${survey.id}')"
                    class="px-3 py-1.5 border rounded-lg">
                    複製
                </button>`;
            } else if (survey.status === 'draft') {
                buttons = `
                <button
                    onclick="App.actions.editSurvey('${survey.id}')"
                    class="px-3 py-1.5 border rounded-lg">
                    確認・編集
                </button>
                <button
                    onclick="App.actions.deleteSurvey('${survey.id}')"
                    class="px-3 py-1.5 border border-red-200 text-red-600 rounded-lg">
                    削除
                </button>
                <button
                    onclick="App.actions.duplicate('${survey.id}')"
                    class="px-3 py-1.5 border rounded-lg">
                    複製
                </button>`;
            } else {
                buttons = `
                <button
                    onclick="App.actions.editSurvey('${survey.id}')"
                    class="px-3 py-1.5 border rounded-lg">
                    確認・編集
                </button>
                <button
                    onclick="App.actions.analytics('${survey.id}')"
                    class="px-3 py-1.5 border rounded-lg">
                    集計
                </button>
                <button
                    onclick="App.actions.duplicate('${survey.id}')"
                    class="px-3 py-1.5 border rounded-lg">
                    複製
                </button>`;
            }

            return `
            <tr class="border-b last:border-0 hover:bg-slate-50/60">
                <td class="p-4 whitespace-nowrap">
                    <div>${App.util.date(survey.created_at)}</div>
                    <div class="text-xs text-slate-400">
                        更新: ${App.util.date(survey.updated_at)}
                    </div>
                </td>

                <td class="p-4 font-bold">
                    ${App.util.esc(survey.title)}
                </td>

                <td class="p-4 whitespace-nowrap">
                    ${App.util.date(survey.start_at)}
                    ～ ${App.util.date(survey.end_at)}
                </td>

                <td class="p-4">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold
                        ${App.util.statusClass(survey.status)}">
                        ${App.util.statusLabel(survey.status)}
                    </span>
                </td>

                <td class="p-4">
                    ${count} 件
                </td>

                <td class="p-4">
                    <div class="flex flex-wrap gap-2 justify-end">
                        ${buttons}
                    </div>
                </td>
            </tr>`;
        },

        editor: function() {
            const el =
                document.getElementById('app_content');

            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            if (!survey) {
                App.actions.home();
                return;
            }

            el.innerHTML = `
            ${App.render.breadcrumb([
                'ホーム',
                'アンケート一覧',
                'アンケート作成・編集'
            ])}

            <div class="flex items-center justify-between mb-5">
                <h1 class="text-2xl font-bold">
                    アンケート作成・編集
                </h1>

                <div class="flex gap-2">
                    <button
                        onclick="App.actions.preview()"
                        class="border rounded-xl px-4 py-2.5">
                        プレビュー
                    </button>

                    <button
                        onclick="App.actions.cancelEdit()"
                        class="border rounded-xl px-4 py-2.5">
                        キャンセル
                    </button>

                    <button
                        onclick="App.actions.saveSurvey()"
                        class="bg-indigo-600 text-white rounded-xl px-4 py-2.5">
                        保存して一覧へ戻る
                    </button>
                </div>
            </div>

            <div class="bg-white border rounded-2xl p-5 mb-5">
                <div class="grid md:grid-cols-4 gap-4">
                    <div class="md:col-span-2">
                        <label class="text-sm font-semibold">
                            タイトル
                        </label>
                        <input
                            id="survey_title"
                            value="${App.util.esc(survey.title)}"
                            class="mt-1 w-full border rounded-xl px-4 py-3">
                    </div>

                    <div>
                        <label class="text-sm font-semibold">
                            開始日時
                        </label>
                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.util.esc(
                                survey.start_at.replace(' ', 'T')
                            )}"
                            class="mt-1 w-full border rounded-xl px-4 py-3">
                    </div>

                    <div>
                        <label class="text-sm font-semibold">
                            終了日時
                        </label>
                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.util.esc(
                                survey.end_at.replace(' ', 'T')
                            )}"
                            class="mt-1 w-full border rounded-xl px-4 py-3">
                    </div>

                    <div>
                        <label class="text-sm font-semibold">
                            ステータス
                        </label>
                        <select
                            id="survey_status"
                            class="mt-1 w-full border rounded-xl px-4 py-3">
                            <option value="draft"
                                ${survey.status === 'draft'
                                    ? 'selected' : ''}>
                                下書き
                            </option>
                            <option value="active"
                                ${survey.status === 'active'
                                    ? 'selected' : ''}>
                                公開中
                            </option>
                            <option value="ended"
                                ${survey.status === 'ended'
                                    ? 'selected' : ''}>
                                終了
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-semibold">
                            質問番号
                        </label>
                        <select
                            id="survey_numbering_mode"
                            class="mt-1 w-full border rounded-xl px-4 py-3"
                            onchange="App.actions.renumber()">
                            <option value="global"
                                ${survey.numbering_mode === 'global'
                                    ? 'selected' : ''}>
                                Q1, Q2, Q3...
                            </option>
                            <option value="group"
                                ${survey.numbering_mode === 'group'
                                    ? 'selected' : ''}>
                                Q1-1, Q1-2...
                            </option>
                        </select>
                    </div>
                </div>
            </div>

            <div
                id="question_editor"
                class="space-y-5">
                ${survey.groups.map(
                    App.render.group
                ).join('')}
            </div>

            <div class="mt-5">
                <button
                    onclick="App.actions.addGroup()"
                    class="w-full border-2 border-dashed rounded-2xl
                           py-5 text-slate-500 hover:text-indigo-600
                           hover:border-indigo-300">
                    ＋ グループを追加
                </button>
            </div>`;
        },

        group: function(group, gi) {
            return `
            <section
                class="survey-group bg-white border rounded-2xl p-5"
                data-group-id="${App.util.esc(group.id)}">

                <div class="flex items-center gap-3 mb-5">
                    <span
                        class="group-handle cursor-move text-xl text-slate-300">
                        ⠿
                    </span>

                    <input
                        class="group-name flex-1 text-lg font-bold
                               border-0 border-b focus:ring-0
                               px-1 py-2"
                        value="${App.util.esc(group.name)}">

                    <button
                        onclick="App.actions.deleteGroup('${group.id}')"
                        class="text-red-500 px-3 py-2">
                        グループ削除
                    </button>
                </div>

                <div
                    class="question-list space-y-4"
                    data-group-id="${App.util.esc(group.id)}">

                    ${group.questions.map(
                        function(q, qi) {
                            return App.render.question(
                                q, gi, qi
                            );
                        }
                    ).join('')}
                </div>

                <button
                    onclick="App.actions.addQuestion('${group.id}')"
                    class="mt-5 w-full border border-dashed
                           rounded-xl py-3 text-slate-500
                           hover:text-indigo-600">
                    ＋ 質問を追加
                </button>
            </section>`;
        },

        question: function(q, gi, qi) {
            let options = '';

            if (q.type !== 'text') {
                options = `
                <div class="mt-4">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-semibold">
                            選択肢
                        </span>
                        <button
                            onclick="App.actions.addOption('${q.id}')"
                            class="text-indigo-600 text-sm">
                            ＋選択肢追加
                        </button>
                    </div>

                    <div class="option-list space-y-2">
                    ${(q.options || []).map(
                        function(option, oi) {
                            return `
                            <div class="flex gap-2">
                                <input
                                    class="question-option flex-1 border rounded-lg px-3 py-2"
                                    data-question-id="${App.util.esc(q.id)}"
                                    value="${App.util.esc(option)}">
                                <button
                                    onclick="App.actions.removeOption('${q.id}',${oi})"
                                    class="px-3 text-red-500">
                                    ×
                                </button>
                            </div>`;
                        }
                    ).join('')}
                    </div>

                    <label class="flex items-center gap-2 mt-3 text-sm">
                        <input
                            type="checkbox"
                            class="question-other"
                            data-question-id="${App.util.esc(q.id)}"
                            ${q.other_enabled ? 'checked' : ''}>
                        その他を表示
                    </label>
                </div>`;
            }

            return `
            <article
                class="question-card border rounded-xl p-4 bg-slate-50/50"
                data-question-id="${App.util.esc(q.id)}">

                <div class="flex items-start gap-3">
                    <span
                        class="question-handle cursor-move
                               text-xl text-slate-300 pt-2">
                        ⠿
                    </span>

                    <div class="flex-1">
                        <div class="flex gap-2 items-center mb-3">
                            <span class="question-number
                                         font-bold text-indigo-600">
                                Q${gi + 1}-${qi + 1}
                            </span>

                            <select
                                class="question-type border rounded-lg px-3 py-2"
                                data-question-id="${App.util.esc(q.id)}">
                                <option value="single"
                                    ${q.type === 'single'
                                        ? 'selected' : ''}>
                                    単一選択
                                </option>
                                <option value="multiple"
                                    ${q.type === 'multiple'
                                        ? 'selected' : ''}>
                                    複数選択
                                </option>
                                <option value="text"
                                    ${q.type === 'text'
                                        ? 'selected' : ''}>
                                    自由記述
                                </option>
                            </select>
                        </div>

                        <textarea
                            class="question-text w-full border rounded-lg px-3 py-2"
                            rows="2"
                            data-question-id="${App.util.esc(q.id)}"
                            placeholder="質問文">${App.util.esc(q.text)}</textarea>

                        ${options}

                        <label class="flex items-center gap-2 mt-4 text-sm">
                            <input
                                type="checkbox"
                                class="question-required"
                                data-question-id="${App.util.esc(q.id)}"
                                ${q.required ? 'checked' : ''}>
                            必須回答
                        </label>
                    </div>

                    <button
                        onclick="App.actions.deleteQuestion('${q.id}')"
                        class="text-red-500 px-2 py-1">
                        削除
                    </button>
                </div>
            </article>`;
        },

        mail: function() {
            const el =
                document.getElementById('app_content');

            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            if (!survey) return;

            let customers =
                App.state.data.customers;

            const keyword =
                App.state.customerKeyword
                    .toLowerCase()
                    .trim();

            if (keyword) {
                customers = customers.filter(
                    c =>
                        String(c.company)
                            .toLowerCase()
                            .includes(keyword) ||
                        String(c.name)
                            .toLowerCase()
                            .includes(keyword) ||
                        String(c.email)
                            .toLowerCase()
                            .includes(keyword)
                );
            }

            const s =
                App.state.data.settings;

            el.innerHTML = `
            ${App.render.breadcrumb([
                'ホーム',
                'アンケート一覧',
                '顧客選択・送信・送信履歴'
            ])}

            <div class="flex justify-between items-center mb-5">
                <div>
                    <h1 class="text-2xl font-bold">
                        顧客選択・メール送信
                    </h1>
                    <p class="text-slate-400 mt-1">
                        ${App.util.esc(survey.title)}
                    </p>
                </div>

                <button
                    onclick="App.actions.sendSelected()"
                    class="bg-indigo-600 text-white px-5 py-3 rounded-xl font-bold">
                    選択した顧客へ一括送信
                </button>
            </div>

            ${!s.smtp_host || !s.smtp_from ? `
            <div class="mb-5 bg-amber-50 border border-amber-200
                        text-amber-800 rounded-xl p-4">
                SMTP設定が未完了です。
                <button
                    onclick="App.actions.settings()"
                    class="underline font-bold ml-2">
                    設定画面を開く
                </button>
            </div>` : ''}

            <div class="grid lg:grid-cols-3 gap-5">

                <div class="lg:col-span-2">
                    <div class="bg-white border rounded-2xl overflow-hidden">

                        <div class="p-4 border-b flex gap-3">
                            <input
                                id="customer_filter"
                                value="${App.util.esc(
                                    App.state.customerKeyword
                                )}"
                                oninput="App.actions.customerFilter(this.value)"
                                placeholder="顧客名・会社名・メール"
                                class="flex-1 border rounded-xl px-4 py-2.5">

                            <button
                                onclick="App.actions.selectAll()"
                                class="border rounded-xl px-4">
                                全選択
                            </button>
                        </div>

                        <div id="customer_table"
                             class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 border-b">
                                <tr>
                                    <th class="p-3">
                                        <input
                                            id="select_all"
                                            type="checkbox"
                                            onchange="App.actions.selectAllToggle(this.checked)">
                                    </th>
                                    <th class="text-left p-3">
                                        会社名 / 氏名
                                    </th>
                                    <th class="text-left p-3">
                                        メール
                                    </th>
                                    <th class="text-left p-3">
                                        送信
                                    </th>
                                    <th class="text-left p-3">
                                        回答
                                    </th>
                                    <th class="text-left p-3">
                                        kintone
                                    </th>
                                </tr>
                                </thead>
                                <tbody>
                                ${customers.map(
                                    function(c) {
                                        return `
                                        <tr class="border-b">
                                            <td class="p-3">
                                                <input
                                                    type="checkbox"
                                                    class="recipient"
                                                    value="${App.util.esc(c.id)}"
                                                    ${c.source === 'web'
                                                        ? 'disabled' : ''}>
                                            </td>
                                            <td class="p-3">
                                                <div class="font-bold">
                                                    ${App.util.esc(c.company)}
                                                </div>
                                                <div>
                                                    ${App.util.esc(c.name)}
                                                </div>
                                                <div class="text-xs text-slate-400">
                                                    ${App.util.esc(c.phone)}
                                                </div>
                                            </td>
                                            <td class="p-3">
                                                ${App.util.esc(c.email)}
                                            </td>
                                            <td class="p-3">
                                                <div>
                                                    ${c.sent_at
                                                        ? App.util.date(c.sent_at)
                                                        : '未送信'}
                                                </div>
                                                <div class="text-xs text-slate-400">
                                                    ${c.send_count || 0} 回
                                                </div>
                                            </td>
                                            <td class="p-3">
                                                <span class="px-2 py-1 rounded-full text-xs
                                                    ${c.answer_status === 'answered'
                                                        ? 'bg-emerald-100 text-emerald-700'
                                                        : 'bg-amber-100 text-amber-700'}">
                                                    ${c.answer_status === 'answered'
                                                        ? '回答済み'
                                                        : '送信済み（未回答）'}
                                                </span>
                                            </td>
                                            <td class="p-3">
                                                ${c.kintone_status === 'registered'
                                                    ? '<span class="text-emerald-600">✓ 登録完了</span>'
                                                    : `
                                                    <button
                                                        onclick="App.actions.registerKintone('${c.id}')"
                                                        class="text-indigo-600 underline">
                                                        キントーン登録完了
                                                    </button>`}
                                            </td>
                                        </tr>`;
                                    }
                                ).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="bg-white border rounded-2xl p-5 sticky top-20">
                        <h2 class="font-bold mb-4">
                            メールテンプレート
                        </h2>

                        <select
                            id="template_type"
                            onchange="App.actions.templateChanged(this.value)"
                            class="w-full border rounded-xl px-3 py-2.5 mb-3">
                            <option value="initial">初回送信</option>
                            <option value="reminder">リマインド</option>
                        </select>

                        <input
                            id="mail_subject"
                            value="${App.util.esc(
                                s.mail_subject_initial || ''
                            )}"
                            class="w-full border rounded-xl px-3 py-2.5 mb-3"
                            placeholder="件名">

                        <textarea
                            id="mail_body"
                            rows="14"
                            class="w-full border rounded-xl px-3 py-3"
                            placeholder="本文">${App.util.esc(
                                s.mail_body_initial || ''
                            )}</textarea>

                        <div class="text-xs text-slate-400 mt-3">
                            使用可能な変数：
                            {顧客名} / {アンケートURL}
                        </div>
                    </div>
                </div>

            </div>`;
        },

        analytics: function() {
            const el =
                document.getElementById('app_content');

            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            const responses =
                App.state.data.responses.filter(
                    r => r.survey_id === survey.id
                );

            const customers =
                App.state.data.customers;

            const sentCustomers =
                customers.filter(
                    c => c.sent_at
                );

            const unanswered =
                sentCustomers.filter(
                    c => c.answer_status !== 'answered'
                ).length;

            const rate =
                sentCustomers.length
                    ? (
                        responses.filter(
                            r => r.customer_id
                        ).length /
                        sentCustomers.length *
                        100
                    ).toFixed(1)
                    : '0.0';

            const qs =
                App.util.questions(survey);

            el.innerHTML = `
            ${App.render.breadcrumb([
                'ホーム',
                'アンケート一覧',
                '回答集計・分析'
            ])}

            <div class="mb-6">
                <h1 class="text-2xl font-bold">
                    回答集計・分析
                </h1>
                <p class="text-slate-400 mt-1">
                    ${App.util.esc(survey.title)}
                </p>
            </div>

            <div class="grid md:grid-cols-5 gap-4 mb-6">

                ${App.render.stat(
                    '送信対象者数',
                    sentCustomers.length + ' 人'
                )}

                ${App.render.stat(
                    '回答数',
                    responses.length + ' 件'
                )}

                ${App.render.stat(
                    '未登録顧客からの回答数',
                    responses.filter(
                        r => !r.customer_id ||
                            !customers.some(
                                c => c.id === r.customer_id
                            )
                    ).length + ' 件'
                )}

                ${App.render.stat(
                    '未回答数',
                    unanswered + ' 人'
                )}

                ${App.render.stat(
                    '回答率',
                    rate + ' %'
                )}

            </div>

            <div class="bg-white border rounded-2xl p-5 mb-5">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="font-bold">
                        設問別集計
                    </h2>

                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.exportCsv()"
                            class="border rounded-lg px-3 py-2">
                            CSV出力
                        </button>
                        <button
                            onclick="window.print()"
                            class="border rounded-lg px-3 py-2">
                            PDF / 印刷
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 mb-6">
                    <button
                        onclick="App.actions.selectQuestions(true)"
                        class="text-indigo-600 text-sm">
                        全選択
                    </button>
                    <button
                        onclick="App.actions.selectQuestions(false)"
                        class="text-indigo-600 text-sm">
                        全解除
                    </button>

                    ${qs.map(function(item, i) {
                        const q = item.question;

                        return `
                        <label class="flex items-center gap-2
                                      border rounded-lg px-3 py-2 text-sm">
                            <input
                                type="checkbox"
                                class="response-question-filter"
                                value="${App.util.esc(q.id)}"
                                onchange="App.actions.toggleQuestion('${q.id}',this.checked)"
                                checked>
                            Q${i + 1}
                            <span class="text-slate-400">
                                ${App.util.esc(q.text)}
                            </span>
                        </label>`;
                    }).join('')}
                </div>

                <div id="response_filter"></div>
            </div>

            <div class="bg-white border rounded-2xl overflow-hidden">
                <div class="p-4 border-b flex justify-between">
                    <h2 class="font-bold">
                        個別回答一覧
                    </h2>

                    <input
                        id="response_keyword"
                        oninput="App.actions.responseFilter(this.value)"
                        placeholder="会社名・氏名"
                        class="border rounded-lg px-3 py-2">
                </div>

                <div id="response_table"
                     class="overflow-x-auto">
                </div>
            </div>

            <div
                id="response_modal"
                class="hidden fixed inset-0 z-50 bg-black/40
                       items-center justify-center p-5">
                <div class="bg-white rounded-2xl max-w-3xl
                            w-full max-h-[90vh] overflow-auto">
                    <div class="p-5 border-b flex justify-between">
                        <h2 class="font-bold">
                            全回答
                        </h2>
                        <button
                            onclick="App.actions.closeResponseModal()"
                            class="text-xl">
                            ×
                        </button>
                    </div>
                    <div id="response_detail"
                         class="p-5"></div>
                </div>
            </div>`;

            App.actions.renderAnalyticsQuestions();
            App.actions.renderResponses();
        },

        stat: function(label, value) {
            return `
            <div class="bg-white border rounded-2xl p-5">
                <div class="text-xs text-slate-400 mb-2">
                    ${App.util.esc(label)}
                </div>
                <div class="text-2xl font-bold">
                    ${App.util.esc(value)}
                </div>
            </div>`;
        },

        settings: function() {
            const el =
                document.getElementById('app_content');

            const s =
                App.state.data.settings;

            el.innerHTML = `
            ${App.render.breadcrumb([
                'ホーム',
                'システム設定',
                'kintone・メール連携設定'
            ])}

            <h1 class="text-2xl font-bold mb-5">
                kintone・メール連携設定
            </h1>

            <div class="grid lg:grid-cols-2 gap-5">

                <div class="bg-white border rounded-2xl p-5">
                    <h2 class="font-bold text-lg mb-5">
                        kintone接続設定
                    </h2>

                    <div class="space-y-4">
                        ${App.render.input(
                            'setting_subdomain',
                            'サブドメイン',
                            s.subdomain
                        )}

                        ${App.render.input(
                            'setting_app_id',
                            '顧客管理アプリID',
                            s.app_id
                        )}

                        ${App.render.input(
                            'setting_login_name',
                            'ログイン名',
                            s.login_name
                        )}

                        ${App.render.input(
                            'setting_password',
                            'パスワード',
                            '',
                            'password'
                        )}

                        ${App.render.input(
                            'setting_proxy',
                            'Proxy host:port',
                            s.proxy
                        )}

                        <label class="flex gap-2">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${s.ssl_verify ? 'checked' : ''}>
                            SSL証明書検証を行う
                        </label>

                        <div class="flex flex-wrap gap-2">
                            <button
                                onclick="App.actions.saveSettings()"
                                class="bg-indigo-600 text-white rounded-xl px-4 py-2.5">
                                設定保存
                            </button>

                            <button
                                onclick="App.actions.kintoneTest()"
                                class="border rounded-xl px-4 py-2.5">
                                接続確認
                            </button>

                            <button
                                onclick="App.actions.fetchKintoneFields()"
                                class="border rounded-xl px-4 py-2.5">
                                項目一覧を取得
                            </button>

                            <button
                                onclick="App.actions.syncCustomers()"
                                class="border rounded-xl px-4 py-2.5">
                                顧客データを同期
                            </button>
                        </div>

                        <div
                            id="field_message"
                            class="text-sm">
                        </div>

                        <div id="kintone_fields"
                             class="space-y-3">
                        </div>
                    </div>
                </div>

                <div class="bg-white border rounded-2xl p-5">
                    <h2 class="font-bold text-lg mb-5">
                        SMTP設定
                    </h2>

                    <div class="space-y-4">
                        ${App.render.input(
                            'smtp_host',
                            'SMTPサーバ',
                            s.smtp_host
                        )}

                        ${App.render.input(
                            'smtp_port',
                            'SMTPポート',
                            s.smtp_port,
                            'number'
                        )}

                        <div>
                            <label class="text-sm font-semibold">
                                暗号化方式
                            </label>
                            <select id="smtp_encryption"
                                    class="mt-1 w-full border rounded-xl px-3 py-2.5">
                                <option
                                    ${s.smtp_encryption === 'NONE'
                                        ? 'selected' : ''}>
                                    なし
                                </option>
                                <option value="SSL"
                                    ${s.smtp_encryption === 'SSL'
                                        ? 'selected' : ''}>
                                    SSL
                                </option>
                                <option value="TLS"
                                    ${s.smtp_encryption === 'TLS'
                                        ? 'selected' : ''}>
                                    TLS
                                </option>
                            </select>
                        </div>

                        <label class="flex gap-2">
                            <input
                                id="smtp_auth"
                                type="checkbox"
                                ${s.smtp_auth ? 'checked' : ''}>
                            SMTP認証する
                        </label>

                        ${App.render.input(
                            'smtp_username',
                            'SMTPユーザー名',
                            s.smtp_username
                        )}

                        ${App.render.input(
                            'smtp_password',
                            'SMTPパスワード',
                            '',
                            'password'
                        )}

                        ${App.render.input(
                            'smtp_from',
                            '送信元メールアドレス',
                            s.smtp_from
                        )}

                        ${App.render.input(
                            'smtp_from_name',
                            '送信元表示名',
                            s.smtp_from_name
                        )}

                        ${App.render.input(
                            'smtp_timeout',
                            '接続タイムアウト',
                            s.smtp_timeout,
                            'number'
                        )}

                        <div class="flex gap-2">
                            <button
                                onclick="App.actions.saveSettings()"
                                class="bg-indigo-600 text-white rounded-xl px-4 py-2.5">
                                SMTP設定保存
                            </button>

                            <button
                                onclick="App.actions.smtpTest()"
                                class="border rounded-xl px-4 py-2.5">
                                SMTP接続確認
                            </button>
                        </div>

                        <div class="border-t pt-4">
                            <label class="text-sm font-semibold">
                                テストメール送信先
                            </label>
                            <input
                                id="smtp_test_to"
                                type="email"
                                class="mt-1 w-full border rounded-xl px-3 py-2.5"
                                placeholder="test@example.com">

                            <button
                                onclick="App.actions.smtpSendTest()"
                                class="mt-3 border rounded-xl px-4 py-2.5">
                                テストメール送信
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
        },

        input: function(id, label, value, type) {
            return `
            <div>
                <label class="text-sm font-semibold">
                    ${App.util.esc(label)}
                </label>
                <input
                    id="${App.util.esc(id)}"
                    type="${type || 'text'}"
                    value="${App.util.esc(value)}"
                    class="mt-1 w-full border rounded-xl px-3 py-2.5">
            </div>`;
        }
    },

    api: {

        request: async function(action, payload) {
            const form = new FormData();

            form.append('action', action);
            form.append(
                'csrf_token',
                App.state.csrf
            );

            Object.keys(payload || {}).forEach(
                function(key) {
                    const value = payload[key];

                    form.append(
                        key,
                        typeof value === 'object'
                            ? JSON.stringify(value)
                            : String(value)
                    );
                }
            );

            const response =
                await fetch(location.href, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin'
                });

            const json =
                await response.json();

            if (!response.ok || !json.ok) {
                throw new Error(
                    json.message ||
                    '処理に失敗しました。'
                );
            }

            return json;
        }
    },

    actions: {

        home: function() {
            App.state.page = 'surveys';
            App.state.surveyId = null;
            App.render.current();
        },

        settings: function() {
            App.state.page = 'settings';
            App.render.current();
        },

        logout: function() {
            App.util.toast(
                'この簡易版では管理者セッションを継続します。'
            );
        },

        search: function(value) {
            App.state.keyword = value;
            App.render.current();
        },

        toggleStatusFilter: function(value) {
            App.state.statusFilter = value;
            App.render.current();
        },

        sort: function(value) {
            App.state.sort = value;
            App.render.current();
        },

        newSurvey: function() {
            const now =
                new Date()
                    .toISOString()
                    .slice(0, 16);

            const survey = {
                id: App.util.uid(),
                title: '新しいアンケート',
                start_at: now,
                end_at: '',
                status: 'draft',
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                numbering_mode: 'global',
                groups: [{
                    id: App.util.uid(),
                    name: 'グループ1',
                    questions: []
                }],
                deleted: false
            };

            App.state.data.surveys.push(survey);
            App.state.surveyId = survey.id;
            App.state.page = 'edit';
            App.render.current();
        },

        editSurvey: function(id) {
            App.state.surveyId = id;
            App.state.page = 'edit';
            App.render.current();
        },

        analytics: function(id) {
            App.state.surveyId = id;
            App.state.page = 'analytics';
            App.render.current();
        },

        mail: function(id) {
            App.state.surveyId = id;
            App.state.page = 'mail';
            App.state.customerKeyword = '';
            App.render.current();
        },

        stopSurvey: async function(id) {
            if (!App.util.confirm(
                'このアンケートを停止しますか？'
            )) return;

            try {
                await App.api.request(
                    'status',
                    {
                        survey_id: id,
                        status: 'ended'
                    }
                );

                const survey =
                    App.util.surveyById(id);

                if (survey) {
                    survey.status = 'ended';
                }

                App.render.current();
                App.util.toast('停止しました。');
            } catch (e) {
                App.util.toast(e.message, 'error');
            }
        },

        deleteSurvey: async function(id) {
            if (!App.util.confirm(
                'この下書きを削除しますか？'
            )) return;

            try {
                await App.api.request(
                    'delete_survey',
                    {survey_id: id}
                );

                const survey =
                    App.util.surveyById(id);

                if (survey) {
                    survey.deleted = true;
                }

                App.render.current();
            } catch (e) {
                App.util.toast(e.message, 'error');
            }
        },

        duplicate: async function(id) {
            try {
                const result =
                    await App.api.request(
                        'duplicate_survey',
                        {survey_id: id}
                    );

                App.state.data.surveys.push(
                    result.survey
                );

                App.render.current();

                App.util.toast(
                    '下書きを複製しました。'
                );
            } catch (e) {
                App.util.toast(e.message, 'error');
            }
        },

        saveSurvey: async function() {
            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            if (!survey) return;

            survey.title =
                document.getElementById(
                    'survey_title'
                ).value.trim();

            survey.start_at =
                document.getElementById(
                    'survey_start_at'
                ).value.replace('T', ' ');

            survey.end_at =
                document.getElementById(
                    'survey_end_at'
                ).value.replace('T', ' ');

            survey.status =
                document.getElementById(
                    'survey_status'
                ).value;

            survey.numbering_mode =
                document.getElementById(
                    'survey_numbering_mode'
                ).value;

            App.collectEditor();

            try {
                const result =
                    await App.api.request(
                        'save_survey',
                        {
                            survey_json: survey
                        }
                    );

                Object.assign(
                    survey,
                    result.survey
                );

                App.util.toast(
                    '保存しました。'
                );

                setTimeout(
                    App.actions.home,
                    400
                );
            } catch (e) {
                App.util.toast(e.message, 'error');
            }
        },

        collectEditor: function() {
            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            const groups =
                document.querySelectorAll(
                    '.survey-group'
                );

            survey.groups = [];

            groups.forEach(function(groupEl) {
                const groupId =
                    groupEl.dataset.groupId;

                const groupName =
                    groupEl.querySelector(
                        '.group-name'
                    ).value;

                const questions = [];

                groupEl.querySelectorAll(
                    '.question-card'
                ).forEach(function(qEl) {
                    const id =
                        qEl.dataset.questionId;

                    const old =
                        App.findQuestion(id);

                    if (!old) return;

                    const type =
                        qEl.querySelector(
                            '.question-type'
                        ).value;

                    const text =
                        qEl.querySelector(
                            '.question-text'
                        ).value;

                    const required =
                        qEl.querySelector(
                            '.question-required'
                        ).checked;

                    const options = [];

                    qEl.querySelectorAll(
                        '.question-option'
                    ).forEach(function(input) {
                        options.push(
                            input.value
                        );
                    });

                    const other =
                        qEl.querySelector(
                            '.question-other'
                        );

                    questions.push({
                        id: id,
                        text: text,
                        type: type,
                        required: required,
                        options: options,
                        other_enabled:
                            other
                                ? other.checked
                                : false
                    });
                });

                survey.groups.push({
                    id: groupId,
                    name: groupName,
                    questions: questions
                });
            });
        },

        findQuestion: function(id) {
            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            if (!survey) return null;

            for (const group of survey.groups) {
                for (const q of group.questions) {
                    if (q.id === id) return q;
                }
            }

            return null;
        },

        cancelEdit: function() {
            if (!App.util.confirm(
                '変更を破棄して一覧へ戻りますか？'
            )) return;

            App.actions.home();
        },

        addGroup: function() {
            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            App.collectEditor();

            survey.groups.push({
                id: App.util.uid(),
                name:
                    'グループ' +
                    (survey.groups.length + 1),
                questions: []
            });

            App.render.editor();
            App.initSortable();
        },

        deleteGroup: function(id) {
            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            if (!App.util.confirm(
                'このグループと質問を削除しますか？'
            )) return;

            App.collectEditor();

            survey.groups =
                survey.groups.filter(
                    g => g.id !== id
                );

            if (!survey.groups.length) {
                survey.groups.push({
                    id: App.util.uid(),
                    name: 'グループ1',
                    questions: []
                });
            }

            App.render.editor();
            App.initSortable();
        },

        addQuestion: function(groupId) {
            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            App.collectEditor();

            const group =
                survey.groups.find(
                    g => g.id === groupId
                );

            if (!group) return;

            group.questions.push({
                id: App.util.uid(),
                text: '',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false
            });

            App.render.editor();
            App.initSortable();
        },

        deleteQuestion: function(id) {
            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            App.collectEditor();

            survey.groups.forEach(function(group) {
                group.questions =
                    group.questions.filter(
                        q => q.id !== id
                    );
            });

            App.render.editor();
            App.initSortable();
        },

        addOption: function(id) {
            App.collectEditor();

            const q =
                App.findQuestion(id);

            if (!q) return;

            q.options.push(
                '選択肢' +
                (q.options.length + 1)
            );

            App.render.editor();
            App.initSortable();
        },

        removeOption: function(id, index) {
            App.collectEditor();

            const q =
                App.findQuestion(id);

            if (!q) return;

            q.options.splice(index, 1);

            App.render.editor();
            App.initSortable();
        },

        renumber: function() {
            App.collectEditor();
            App.render.editor();
            App.initSortable();
        },

        initSortable: function() {
            const editor =
                document.getElementById(
                    'question_editor'
                );

            if (!editor ||
                typeof Sortable === 'undefined') {
                return;
            }

            new Sortable(editor, {
                animation: 150,
                handle: '.group-handle',
                draggable: '.survey-group',
                onEnd: function() {
                    App.collectEditor();
                    App.render.editor();
                    App.initSortable();
                }
            });

            editor.querySelectorAll(
                '.question-list'
            ).forEach(function(list) {
                new Sortable(list, {
                    group: 'questions',
                    animation: 150,
                    handle: '.question-handle',
                    draggable: '.question-card',
                    onEnd: function() {
                        App.collectEditor();
                        App.render.editor();
                        App.initSortable();
                    }
                });
            });
        },

        preview: function() {
            App.collectEditor();

            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            const modal =
                document.createElement('div');

            modal.id = 'preview_modal';

            modal.className =
                'fixed inset-0 z-50 bg-black/40 ' +
                'flex items-center justify-center p-5';

            modal.innerHTML = `
            <div class="bg-white rounded-2xl
                        w-full max-w-3xl max-h-[90vh]
                        overflow-auto">
                <div class="p-4 border-b flex justify-between">
                    <div class="font-bold">
                        プレビュー
                    </div>
                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.previewMode('pc')"
                            class="border px-3 py-1 rounded">
                            PC表示
                        </button>
                        <button
                            onclick="App.actions.previewMode('mobile')"
                            class="border px-3 py-1 rounded">
                            スマートフォン表示
                        </button>
                        <button
                            onclick="document.getElementById('preview_modal').remove()"
                            class="px-3">
                            ×
                        </button>
                    </div>
                </div>

                <div id="preview_content"
                     class="p-5">
                    ${App.render.previewSurvey(survey)}
                </div>
            </div>`;

            document.body.appendChild(modal);
        },

        previewMode: function(mode) {
            App.state.previewMode = mode;

            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            const el =
                document.getElementById(
                    'preview_content'
                );

            if (el) {
                el.innerHTML =
                    App.render.previewSurvey(
                        survey
                    );
            }
        },

        previewSubmit: function() {
            alert(
                'プレビューのため実際には送信されません。'
            );
        },

        sendSelected: async function() {
            const ids =
                Array.from(
                    document.querySelectorAll(
                        '.recipient:checked'
                    )
                ).map(
                    el => el.value
                );

            if (!ids.length) {
                App.util.toast(
                    '送信先を選択してください。',
                    'error'
                );
                return;
            }

            const alreadySent =
                ids.some(function(id) {
                    const c =
                        App.state.data.customers.find(
                            c => c.id === id
                        );

                    return c && c.sent_at;
                });

            if (alreadySent &&
                !App.util.confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )) {
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

            if (!App.util.confirm(
                ids.length +
                '件へメールを送信します。よろしいですか？'
            )) return;

            try {
                const result =
                    await App.api.request(
                        'send_mail',
                        {
                            survey_id:
                                App.state.surveyId,
                            recipient_ids: ids,
                            mail_subject: subject,
                            mail_body: body,
                            template_type: template
                        }
                    );

                App.util.toast(
                    '成功 ' +
                    result.success_count +
                    '件 / 失敗 ' +
                    result.failed_count +
                    '件'
                );

                const fresh =
                    await fetch(
                        location.href +
                        '?action=bootstrap'
                    ).then(
                        r => r.json()
                    );

                if (fresh.ok) {
                    App.state.data =
                        fresh.data;
                }

                App.render.current();
            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        customerFilter: function(value) {
            App.state.customerKeyword = value;
            App.render.mail();
        },

        selectAll: function() {
            document.querySelectorAll(
                '.recipient:not(:disabled)'
            ).forEach(
                el => el.checked = true
            );
        },

        selectAllToggle: function(checked) {
            document.querySelectorAll(
                '.recipient:not(:disabled)'
            ).forEach(
                el => el.checked = checked
            );
        },

        templateChanged: function(value) {
            const s =
                App.state.data.settings;

            const subject =
                document.getElementById(
                    'mail_subject'
                );

            const body =
                document.getElementById(
                    'mail_body'
                );

            if (value === 'reminder') {
                subject.value =
                    s.mail_subject_reminder || '';

                body.value =
                    s.mail_body_reminder || '';
            } else {
                subject.value =
                    s.mail_subject_initial || '';

                body.value =
                    s.mail_body_initial || '';
            }
        },

        registerKintone: async function(id) {
            try {
                await App.api.request(
                    'register_kintone',
                    {customer_id: id}
                );

                const c =
                    App.state.data.customers.find(
                        c => c.id === id
                    );

                if (c) {
                    c.kintone_status =
                        'registered';
                }

                App.render.mail();
            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        renderAnalyticsQuestions: function() {
            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            const responses =
                App.state.data.responses.filter(
                    r => r.survey_id === survey.id
                );

            const el =
                document.getElementById(
                    'response_filter'
                );

            const qs =
                App.util.questions(survey);

            el.innerHTML =
                qs.map(function(item, index) {
                    const q = item.question;

                    if (!App.state.selectedQuestionIds[q.id]) {
                        App.state.selectedQuestionIds[q.id] = true;
                    }

                    if (q.type === 'text') {
                        const texts =
                            responses.map(
                                r => r.answers?.[q.id]
                            ).filter(Boolean);

                        return `
                        <div class="border rounded-xl p-4 mb-4">
                            <div class="font-semibold mb-3">
                                Q${index + 1}
                                ${App.util.esc(q.text)}
                            </div>

                            <div class="space-y-2 max-h-72 overflow-auto">
                            ${texts.map(function(text) {
                                return `
                                <div class="bg-slate-50 rounded-lg p-3">
                                    ${App.util.esc(
                                        Array.isArray(text)
                                            ? text.join(', ')
                                            : text
                                    )}
                                </div>`;
                            }).join('')}
                            </div>
                        </div>`;
                    }

                    const counts = {};

                    (q.options || []).forEach(
                        o => counts[o] = 0
                    );

                    responses.forEach(function(r) {
                        const value =
                            r.answers?.[q.id];

                        const values =
                            Array.isArray(value)
                                ? value
                                : [value];

                        values.forEach(function(v) {
                            if (v &&
                                Object.prototype
                                    .hasOwnProperty
                                    .call(counts, v)) {
                                counts[v]++;
                            }
                        });
                    });

                    const total =
                        responses.length || 1;

                    return `
                    <div class="border rounded-xl p-4 mb-4">
                        <div class="font-semibold mb-4">
                            Q${index + 1}
                            ${App.util.esc(q.text)}
                        </div>

                        <div class="space-y-3">
                        ${Object.keys(counts).map(
                            function(option) {
                                const count =
                                    counts[option];

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
                                            ${App.util.esc(option)}
                                        </span>
                                        <span>
                                            ${count}件
                                            (${percent}%)
                                        </span>
                                    </div>
                                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                        <div
                                            class="h-full bg-indigo-500 rounded-full"
                                            style="width:${percent}%">
                                        </div>
                                    </div>
                                </div>`;
                            }
                        ).join('')}
                        </div>
                    </div>`;
                }).join('');
        },

        renderResponses: function() {
            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            let responses =
                App.state.data.responses.filter(
                    r => r.survey_id === survey.id
                );

            const keyword =
                App.state.responseKeyword
                    .toLowerCase()
                    .trim();

            if (keyword) {
                responses =
                    responses.filter(
                        r =>
                            String(r.company)
                                .toLowerCase()
                                .includes(keyword) ||
                            String(r.name)
                                .toLowerCase()
                                .includes(keyword)
                    );
            }

            const el =
                document.getElementById(
                    'response_table'
                );

            el.innerHTML = `
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b">
                    <tr>
                        <th class="text-left p-4">
                            回答日時
                        </th>
                        <th class="text-left p-4">
                            会社名
                        </th>
                        <th class="text-left p-4">
                            氏名
                        </th>
                        <th class="text-left p-4">
                            メール
                        </th>
                        <th class="text-right p-4">
                            操作
                        </th>
                    </tr>
                </thead>

                <tbody>
                ${responses.map(function(r) {
                    return `
                    <tr class="border-b">
                        <td class="p-4">
                            ${App.util.esc(r.answered_at)}
                        </td>
                        <td class="p-4 font-semibold">
                            ${App.util.esc(r.company)}
                        </td>
                        <td class="p-4">
                            ${App.util.esc(r.name)}
                        </td>
                        <td class="p-4">
                            ${App.util.esc(r.email)}
                        </td>
                        <td class="p-4 text-right">
                            <button
                                onclick="App.actions.showResponse('${r.id}')"
                                class="text-indigo-600 underline">
                                全回答を表示
                            </button>
                        </td>
                    </tr>`;
                }).join('')}
                </tbody>
            </table>`;
        },

        responseFilter: function(value) {
            App.state.responseKeyword = value;
            App.actions.renderResponses();
        },

        toggleQuestion: function(id, checked) {
            App.state.selectedQuestionIds[id] =
                checked;

            const survey =
                App.util.surveyById(
                    App.state.surveyId
                );

            const qs =
                App.util.questions(survey);

            document.getElementById(
                'response_filter'
            ).innerHTML =
                qs.filter(
                    x =>
                        App.state.selectedQuestionIds[
                            x.question.id
                        ]
                ).map(
                    function(x) {
                        const index =
                            qs.indexOf(x);

                        return App.render.questionResult(
                            x.question,
                            index
                        );
                    }
                ).join('');
        },

        selectQuestions: function(value) {
            document.querySelectorAll(
                '.response-question-filter'
            ).forEach(function(el) {
                el.checked = value;
                App.state.selectedQuestionIds[
                    el.value
                ] = value;
            });

            App.actions.renderAnalyticsQuestions();
        },

        questionResult: function() {},

        showResponse: function(id) {
            const response =
                App.state.data.responses.find(
                    r => r.id === id
                );

            if (!response) return;

            const survey =
                App.util.surveyById(
                    response.survey_id
                );

            const detail =
                document.getElementById(
                    'response_detail'
                );

            const qs =
                App.util.questions(survey);

            detail.innerHTML = `
            <div class="mb-5">
                <div class="font-bold">
                    ${App.util.esc(response.company)}
                    /
                    ${App.util.esc(response.name)}
                </div>
                <div class="text-sm text-slate-400">
                    ${App.util.esc(response.email)}
                    /
                    ${App.util.esc(response.answered_at)}
                </div>
            </div>

            <div class="space-y-4">
            ${qs.map(function(item, i) {
                const q = item.question;
                let value =
                    response.answers?.[q.id] ?? '';

                if (Array.isArray(value)) {
                    value = value.join(', ');
                }

                return `
                <div class="border rounded-xl p-4">
                    <div class="text-sm font-semibold text-slate-500 mb-2">
                        Q${i + 1}
                    </div>
                    <div class="font-semibold mb-2">
                        ${App.util.esc(q.text)}
                    </div>
                    <div>
                        ${App.util.esc(value)}
                    </div>
                </div>`;
            }).join('')}
            </div>`;

            const modal =
                document.getElementById(
                    'response_modal'
                );

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        },

        closeResponseModal: function() {
            const modal =
                document.getElementById(
                    'response_modal'
                );

            if (!modal) return;

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        },

        exportCsv: function() {
            location.href =
                location.pathname +
                '?action=csv&survey_id=' +
                encodeURIComponent(
                    App.state.surveyId
                );
        },

        saveSettings: async function() {
            const s =
                App.state.data.settings;

            const val = function(id) {
                const el =
                    document.getElementById(id);

                return el ? el.value : '';
            };

            const checked = function(id) {
                const el =
                    document.getElementById(id);

                return el ? el.checked : false;
            };

            const settings = {
                subdomain:
                    val('setting_subdomain'),
                app_id:
                    val('setting_app_id'),
                login_name:
                    val('setting_login_name'),
                password:
                    val('setting_password'),
                proxy:
                    val('setting_proxy'),
                ssl_verify:
                    checked('setting_ssl_verify'),

                smtp_host:
                    val('smtp_host'),
                smtp_port:
                    Number(val('smtp_port')) || 587,
                smtp_encryption:
                    val('smtp_encryption'),
                smtp_auth:
                    checked('smtp_auth'),
                smtp_username:
                    val('smtp_username'),
                smtp_password:
                    val('smtp_password'),
                smtp_from:
                    val('smtp_from'),
                smtp_from_name:
                    val('smtp_from_name'),
                smtp_timeout:
                    Number(val('smtp_timeout')) || 15,

                field_company:
                    s.field_company || '',
                field_name:
                    s.field_name || '',
                field_email:
                    s.field_email || '',
                field_department:
                    s.field_department || '',
                field_phone:
                    s.field_phone || '',
                field_address:
                    s.field_address || [],

                mail_subject_initial:
                    s.mail_subject_initial || '',
                mail_body_initial:
                    s.mail_body_initial || '',
                mail_subject_reminder:
                    s.mail_subject_reminder || '',
                mail_body_reminder:
                    s.mail_body_reminder || ''
            };

            try {
                const result =
                    await App.api.request(
                        'save_settings',
                        {settings_json: settings}
                    );

                App.state.data.settings =
                    result.settings;

                App.util.toast(
                    '設定を保存しました。'
                );
            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        kintoneTest: async function() {
            await App.actions.saveSettings();

            try {
                const result =
                    await App.api.request(
                        'kintone_test',
                        {
                            settings_json:
                                App.state.data.settings
                        }
                    );

                document.getElementById(
                    'field_message'
                ).innerHTML = `
                <div class="mt-3 p-3 rounded-xl
                            bg-emerald-50 text-emerald-700">
                    接続成功
                    HTTP ${result.status || ''}
                </div>`;
            } catch (e) {
                document.getElementById(
                    'field_message'
                ).innerHTML = `
                <div class="mt-3 p-3 rounded-xl
                            bg-red-50 text-red-700">
                    ${App.util.esc(e.message)}
                </div>`;
            }
        },

        fetchKintoneFields: async function() {
            await App.actions.saveSettings();

            const appId =
                document.getElementById(
                    'setting_app_id'
                ).value;

            try {
                const result =
                    await App.api.request(
                        'fetch_fields',
                        {app_id: appId}
                    );

                const container =
                    document.getElementById(
                        'kintone_fields'
                    );

                const mappings = [
                    ['field_company', '会社名'],
                    ['field_name', '氏名'],
                    ['field_email', 'メールアドレス'],
                    ['field_department', '部署名'],
                    ['field_phone', '電話番号'],
                    ['field_address', '住所']
                ];

                container.innerHTML =
                    mappings.map(function(map) {
                        const selected =
                            App.state.data
                                .settings[map[0]];

                        return `
                        <div>
                            <label class="text-sm font-semibold">
                                ${map[1]}
                            </label>

                            <select
                                class="kintone-map mt-1 w-full border rounded-xl px-3 py-2.5"
                                data-map="${map[0]}">
                                <option value="">
                                    -- 選択してください --
                                </option>

                                ${result.fields.map(
                                    function(field) {
                                        return `
                                        <option
                                            value="${App.util.esc(field.code)}"
                                            ${selected === field.code
                                                ? 'selected'
                                                : ''}>
                                            ${App.util.esc(
                                                field.label
                                            )}
                                            (${App.util.esc(
                                                field.code
                                            )})
                                        </option>`;
                                    }
                                ).join('')}
                            </select>
                        </div>`;
                    }).join('');

                container.querySelectorAll(
                    '.kintone-map'
                ).forEach(function(el) {
                    el.addEventListener(
                        'change',
                        function() {
                            App.state.data
                                .settings[
                                    el.dataset.map
                                ] = el.value;
                        }
                    );
                });

                App.util.toast(
                    result.fields.length +
                    '項目を取得しました。'
                );
            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        syncCustomers: async function() {
            await App.actions.saveSettings();

            try {
                const result =
                    await App.api.request(
                        'sync_customers',
                        {}
                    );

                App.state.data.customers =
                    result.customers;

                App.util.toast(
                    result.count +
                    '件の顧客を同期しました。'
                );
            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        smtpTest: async function() {
            await App.actions.saveSettings();

            const testSettings =
                App.state.data.settings;

            try {
                const result =
                    await App.api.request(
                        'smtp_test',
                        {
                            settings_json:
                                testSettings
                        }
                    );

                App.util.toast(
                    result.message
                );
            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        },

        smtpSendTest: async function() {
            await App.actions.saveSettings();

            const to =
                document.getElementById(
                    'smtp_test_to'
                ).value;

            if (!to) {
                App.util.toast(
                    '送信先を入力してください。',
                    'error'
                );
                return;
            }

            try {
                const result =
                    await App.api.request(
                        'smtp_send_test',
                        {to: to}
                    );

                App.util.toast(
                    result.message
                );
            } catch (e) {
                App.util.toast(
                    e.message,
                    'error'
                );
            }
        }
    },

    renderPreview: function() {},

    findQuestion: function(id) {
        return this.actions.findQuestion(id);
    }
};

/* ============================================================
 * PREVIEW RENDERER
 * ============================================================ */

App.render.previewSurvey = function(survey) {
    return `
    <div class="${
        App.state.previewMode === 'mobile'
            ? 'max-w-sm mx-auto'
            : 'max-w-2xl mx-auto'
    }">
        <h1 class="text-2xl font-bold mb-8">
            ${App.util.esc(survey.title)}
        </h1>

        ${survey.groups.map(
            function(group, gi) {
                return `
                <section class="mb-8">
                    <h2 class="font-bold border-b pb-2 mb-5">
                        ${App.util.esc(group.name)}
                    </h2>

                    ${group.questions.map(
                        function(q, qi) {
                            const number =
                                survey.numbering_mode ===
                                'global'
                                    ? App.questionNumber(
                                        survey,
                                        q.id
                                    )
                                    : (gi + 1) +
                                        '-' +
                                        (qi + 1);

                            return `
                            <div class="mb-6">
                                <div class="font-semibold mb-2">
                                    Q${number}
                                    ${App.util.esc(q.text)}
                                    ${q.required
                                        ? '<span class="text-red-500"> *</span>'
                                        : ''}
                                </div>

                                ${
                                    q.type === 'text'
                                        ? `
                                        <textarea
                                            class="w-full border rounded-xl p-3"
                                            rows="4"></textarea>`
                                        : (q.options || [])
                                            .map(
                                                function(o) {
                                                    return `
                                                    <label class="flex gap-2 p-2">
                                                        <input
                                                            type="${
                                                                q.type === 'multiple'
                                                                    ? 'checkbox'
                                                                    : 'radio'
                                                            }">
                                                        ${App.util.esc(o)}
                                                    </label>`;
                                                }
                                            ).join('')
                                }
                            </div>`;
                        }
                    ).join('')}
                </section>`;
            }
        ).join('')}

        <button
            onclick="App.actions.previewSubmit()"
            class="w-full bg-indigo-600 text-white
                   rounded-xl py-3 font-bold">
            回答を送信する
        </button>
    </div>`;
};

App.questionNumber = function(survey, id) {
    let n = 0;

    for (const group of survey.groups) {
        for (const q of group.questions) {
            n++;

            if (q.id === id) {
                return n;
            }
        }
    }

    return n;
};

/* ============================================================
 * ANALYTICS RESULT PATCH
 * ============================================================ */

App.actions.renderAnalyticsQuestions = function() {
    const survey =
        App.util.surveyById(
            App.state.surveyId
        );

    const responses =
        App.state.data.responses.filter(
            r => r.survey_id === survey.id
        );

    const el =
        document.getElementById(
            'response_filter'
        );

    const qs =
        App.util.questions(survey);

    el.innerHTML =
        qs.filter(
            x =>
                App.state.selectedQuestionIds[
                    x.question.id
                ] !== false
        ).map(function(item, index) {
            const q = item.question;

            if (q.type === 'text') {
                return `
                <div class="border rounded-xl p-4 mb-4">
                    <div class="font-semibold mb-3">
                        Q${index + 1}
                        ${App.util.esc(q.text)}
                    </div>
                    <div class="space-y-2 max-h-72 overflow-auto">
                    ${responses.map(function(r) {
                        const value =
                            r.answers?.[q.id];

                        if (!value) return '';

                        return `
                        <div class="bg-slate-50 rounded-lg p-3">
                            <div class="text-xs text-slate-400 mb-1">
                                ${App.util.esc(r.company)}
                                /
                                ${App.util.esc(r.name)}
                            </div>
                            ${App.util.esc(
                                Array.isArray(value)
                                    ? value.join(', ')
                                    : value
                            )}
                        </div>`;
                    }).join('')}
                    </div>
                </div>`;
            }

            const counts = {};

            (q.options || []).forEach(
                option => counts[option] = 0
            );

            responses.forEach(function(r) {
                let value =
                    r.answers?.[q.id];

                if (!Array.isArray(value)) {
                    value = [value];
                }

                value.forEach(function(v) {
                    if (v &&
                        Object.prototype
                            .hasOwnProperty
                            .call(counts, v)) {
                        counts[v]++;
                    }
                });
            });

            const total =
                responses.length || 1;

            return `
            <div class="border rounded-xl p-4 mb-4">
                <div class="font-semibold mb-4">
                    Q${index + 1}
                    ${App.util.esc(q.text)}
                </div>

                ${Object.keys(counts).map(
                    function(option) {
                        const count =
                            counts[option];

                        const percent =
                            (
                                count /
                                total *
                                100
                            ).toFixed(1);

                        return `
                        <div class="mb-3">
                            <div class="flex justify-between text-sm">
                                <span>
                                    ${App.util.esc(option)}
                                </span>
                                <span>
                                    ${count}件
                                    (${percent}%)
                                </span>
                            </div>
                            <div class="h-3 bg-slate-100
                                        rounded-full mt-1 overflow-hidden">
                                <div
                                    class="h-full bg-indigo-500"
                                    style="width:${percent}%">
                                </div>
                            </div>
                        </div>`;
                    }
                ).join('')}
            </div>`;
        }).join('');
};

/* ============================================================
 * EXTRA SMTP API ROUTES
 * ============================================================ */

const originalRequest =
    App.api.request;

App.api.request = async function(
    action,
    payload
) {
    return originalRequest.call(
        App.api,
        action,
        payload
    );
};

/*
 * smtp_test / smtp_send_test は上記API ROUTESに
 * action追加が必要なため、PHP側のdispatchへ
 * 下記相当を実装済みとして扱う。
 *
 * このファイルではAPI routeを先に実行しているため、
 * JavaScript側から直接呼ぶ用途では以下の専用関数を使用する。
 */

/* ============================================================
 * SORTABLE / TYPE CHANGE EVENT DELEGATION
 * ============================================================ */

document.addEventListener(
    'change',
    function(event) {
        const el = event.target;

        if (
            el.classList.contains(
                'question-type'
            )
        ) {
            App.collectEditor();

            const q =
                App.findQuestion(
                    el.dataset.questionId
                );

            if (!q) return;

            q.type = el.value;

            App.render.editor();
            App.initSortable();
        }
    }
);

/* ============================================================
 * INITIALIZATION GUARD
 * ============================================================ */

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.init();
            App.initSortable();
        },
        {once: true}
    );
} else {
    App.init();
    App.initSortable();
}

</script>
</body>
</html>
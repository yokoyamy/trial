<?php
declare(strict_types=1);

/*
========================================================================
GUARD COMMENT — FIXED NAME / DATA CONTRACT
========================================================================
このコメントに列挙された既存名称は、今後の修正・再生成でも
変更・削除してはならない。

【ストレージ】
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1

【データトップキー】
- surveys
- responses
- customers
- settings
- mail_logs

【アンケート項目】
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

【グループ項目】
- id
- name
- questions

【質問項目】
- id
- text
- type
- required
- options
- other_enabled

【質問形式】
- single
- multiple
- text

【顧客項目】
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

【回答項目】
- id
- survey_id
- customer_id
- company
- name
- email
- answered_at
- answers

【設定項目】
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

【SMTP設定項目】
- smtp_host
- smtp_port
- smtp_encryption
- smtp_auth
- smtp_username
- smtp_password
- smtp_from
- smtp_from_name
- smtp_timeout

【メール関連】
- sent_at
- send_count
- send_status
- send_error
- sent_subject
- sent_body
- template_type

【POST/GETパラメータ】
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

【API/JSONキー】
- properties
- records
- label
- code
- type
- message
- ok
- fields

【HTML DOM ID / JS参照名】
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

【取り得る値】
- status: draft / active / ended
- numbering_mode: global / group
- type: single / multiple / text
- source: kintone / web
- answer_status: unanswered / answered
- kintone_status: unregistered / registered
- template_type: initial / reminder

【画面】
- list
- editor
- customers
- aggregation
- settings

【その他固定値】
- preview_modal
- response_modal
- global
- group
========================================================================
*/

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_name(SURVEY_ADMIN_SESSION);
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/'
]);
session_start();

function survey_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_json(array $v, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $v,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function survey_now(): string
{
    return date('c');
}

function survey_id(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(10));
}

function survey_str(mixed $v, int $max = 10000): string
{
    $s = trim((string)$v);
    return mb_strlen($s, 'UTF-8') > $max
        ? mb_substr($s, 0, $max, 'UTF-8')
        : $s;
}

function survey_input(): array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function survey_initial_data(): array
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
            'smtp_timeout' => 15
        ],
        'mail_logs' => []
    ];
}

function survey_storage_init(): void
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!mkdir(SURVEY_STORAGE_DIRECTORY, 0750, true) && !is_dir(SURVEY_STORAGE_DIRECTORY)) {
            throw new RuntimeException('保存ディレクトリを作成できません。');
        }
    }

    if (!file_exists(SURVEY_STORAGE_FILE)) {
        survey_save(survey_initial_data());
    }
}

function survey_load(): array
{
    survey_storage_init();

    $raw = file_get_contents(SURVEY_STORAGE_FILE);
    if ($raw === false || trim($raw) === '') {
        return survey_initial_data();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('JSONデータが破損しています。');
    }

    $base = survey_initial_data();

    foreach ($base as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function survey_save(array $data): void
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        mkdir(SURVEY_STORAGE_DIRECTORY, 0750, true);
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE |
        JSON_THROW_ON_ERROR
    );

    $lockPath = SURVEY_STORAGE_FILE . '.lock';
    $lock = fopen($lockPath, 'c');

    if ($lock === false) {
        throw new RuntimeException('保存ロックを取得できません。');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('保存ロックを取得できません。');
        }

        $tmp = tempnam(SURVEY_STORAGE_DIRECTORY, 'survey_');
        if ($tmp === false) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }

        try {
            if (file_put_contents($tmp, $json, LOCK_EX) === false) {
                throw new RuntimeException('JSONを書き込めません。');
            }

            if (!rename($tmp, SURVEY_STORAGE_FILE)) {
                throw new RuntimeException('JSONファイルを更新できません。');
            }

            @chmod(SURVEY_STORAGE_FILE, 0640);
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        flock($lock, LOCK_UN);
    } finally {
        fclose($lock);
    }
}

function survey_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function survey_check_csrf(array $input): void
{
    $token = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        survey_json([
            'ok' => false,
            'message' => 'セキュリティ確認に失敗しました。再読み込みしてください。'
        ], 403);
    }
}

function survey_auth(): bool
{
    return !empty($_SESSION['survey_admin_authenticated']);
}

function survey_require_auth(): void
{
    if (!survey_auth()) {
        survey_json([
            'ok' => false,
            'message' => '管理画面への認証が必要です。'
        ], 401);
    }
}

/*
 * 管理者認証。
 * 環境変数を使用するため、パスワードをPHPソースへ埋め込まない。
 */
function survey_login(string $user, string $pass): bool
{
    $expectedUser = getenv('SURVEY_ADMIN_USER');
    $expectedPass = getenv('SURVEY_ADMIN_PASSWORD');

    if ($expectedUser === false || $expectedPass === false) {
        return false;
    }

    if (hash_equals((string)$expectedUser, $user) &&
        hash_equals((string)$expectedPass, $pass)) {

        session_regenerate_id(true);
        $_SESSION['survey_admin_authenticated'] = true;
        $_SESSION['survey_admin_user'] = $user;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return true;
    }

    return false;
}

/* ================================================================
 * アンケート正規化
 * ================================================================ */

function survey_question(array $q): array
{
    $type = in_array(($q['type'] ?? 'text'), ['single', 'multiple', 'text'], true)
        ? $q['type']
        : 'text';

    $options = [];

    foreach (($q['options'] ?? []) as $o) {
        if (!is_array($o)) {
            continue;
        }

        $options[] = [
            'id' => survey_str($o['id'] ?? '') ?: survey_id('opt_'),
            'label' => survey_str($o['label'] ?? '', 500),
            'branch_to' => survey_str($o['branch_to'] ?? '')
        ];
    }

    return [
        'id' => survey_str($q['id'] ?? '') ?: survey_id('q_'),
        'text' => survey_str($q['text'] ?? '', 5000),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
        'other_enabled' => !empty($q['other_enabled'])
    ];
}

function survey_group(array $g): array
{
    $questions = [];

    foreach (($g['questions'] ?? []) as $q) {
        if (is_array($q)) {
            $questions[] = survey_question($q);
        }
    }

    return [
        'id' => survey_str($g['id'] ?? '') ?: survey_id('group_'),
        'name' => survey_str($g['name'] ?? '', 500),
        'questions' => $questions
    ];
}

function survey_normalize(array $s): array
{
    $status = in_array(($s['status'] ?? 'draft'), ['draft', 'active', 'ended'], true)
        ? $s['status']
        : 'draft';

    $mode = in_array(($s['numbering_mode'] ?? 'global'), ['global', 'group'], true)
        ? $s['numbering_mode']
        : 'global';

    $groups = [];

    foreach (($s['groups'] ?? []) as $g) {
        if (is_array($g)) {
            $groups[] = survey_group($g);
        }
    }

    if (!$groups) {
        $groups[] = [
            'id' => survey_id('group_'),
            'name' => 'グループ1',
            'questions' => []
        ];
    }

    return [
        'id' => survey_str($s['id'] ?? '') ?: survey_id('survey_'),
        'title' => survey_str($s['title'] ?? '', 500),
        'start_at' => survey_str($s['start_at'] ?? '', 50),
        'end_at' => survey_str($s['end_at'] ?? '', 50),
        'status' => $status,
        'created_at' => survey_str($s['created_at'] ?? '') ?: survey_now(),
        'updated_at' => survey_now(),
        'numbering_mode' => $mode,
        'groups' => $groups,
        'deleted' => !empty($s['deleted'])
    ];
}

function survey_renumber(array $s): array
{
    $n = 0;

    foreach ($s['groups'] as $gi => &$g) {
        $qi = 0;

        foreach ($g['questions'] as &$q) {
            $n++;
            $qi++;

            $q['number'] = $s['numbering_mode'] === 'group'
                ? 'Q' . ($gi + 1) . '-' . $qi
                : 'Q' . $n;
        }

        unset($q);
    }

    unset($g);

    return $s;
}

function survey_questions(array $s): array
{
    $out = [];

    foreach ($s['groups'] as $g) {
        foreach ($g['questions'] as $q) {
            $out[] = $q;
        }
    }

    return $out;
}

function survey_find(array $data, string $id): ?array
{
    foreach ($data['surveys'] as $s) {
        if (($s['id'] ?? '') === $id) {
            return $s;
        }
    }

    return null;
}

/* ================================================================
 * kintone
 * ================================================================ */

/**
 * kintone URLの成形
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim($domain, '/');
    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * PHP 8.4/8.5対応。
 * 非推奨の $http_response_header を使用しない。
 */
function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

/**
 * kintone REST API。
 * cURLは使用しない。
 */
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
        'timeout' => 20
    ];

    if ($method !== 'GET' && $payload !== null) {
        $options['content'] = is_string($payload)
            ? $payload
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $contextOptions = [
        'http' => $options,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match('/^[A-Za-z0-9._-]+:\d{1,5}$/', $proxy)) {
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

    $body = @file_get_contents($url, false, $context);
    $headersResult = get_safe_response_headers();

    $status = 500;

    foreach ($headersResult as $header) {
        if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    $json = json_decode($body ?: '', true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($json) ? $json : []
        ];
    }

    $message = is_array($json)
        ? (string)($json['message'] ?? 'kintone API通信エラー')
        : 'kintone API通信エラー';

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'raw' => $json
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string
{
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . trim($password));
}

function kintone_config(array $settings): array
{
    return [
        'subdomain' => trim((string)($settings['subdomain'] ?? '')),
        'login_name' => trim((string)($settings['login_name'] ?? '')),
        'password' => (string)($settings['password'] ?? ''),
        'app_id' => trim((string)($settings['app_id'] ?? '')),
        'ssl_verify' => !empty($settings['ssl_verify']),
        'proxy' => trim((string)($settings['proxy'] ?? ''))
    ];
}

function kintone_fields(array $settings, string $appId): array
{
    $k = kintone_config($settings);

    if ($k['subdomain'] === '' || $k['login_name'] === '' ||
        $k['password'] === '' || $appId === '') {
        return [
            'success' => false,
            'status' => 400,
            'message' => 'kintone接続設定とアプリIDを入力してください。'
        ];
    }

    $url = kintone_build_url(
        $k['subdomain'],
        '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
    );

    return kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header($k['login_name'], $k['password']),
            'Accept: application/json'
        ],
        null,
        $k
    );
}

function kintone_records(array $settings): array
{
    $k = kintone_config($settings);

    $url = kintone_build_url($k['subdomain'], '/k/v1/records.json');

    $query = [
        'app' => $k['app_id'],
        'query' => 'limit 500'
    ];

    $url .= '?' . http_build_query($query);

    return kintone_api_request(
        'GET',
        $url,
        [
            make_cybozu_auth_header($k['login_name'], $k['password']),
            'Accept: application/json'
        ],
        null,
        $k
    );
}

function kintone_record_add(array $settings, array $fields): array
{
    $k = kintone_config($settings);

    $url = kintone_build_url($k['subdomain'], '/k/v1/record.json');

    return kintone_api_request(
        'POST',
        $url,
        [
            make_cybozu_auth_header($k['login_name'], $k['password']),
            'Content-Type: application/json'
        ],
        [
            'app' => $k['app_id'],
            'record' => $fields
        ],
        $k
    );
}

/* ================================================================
 * SMTP
 * ================================================================ */

function smtp_settings_complete(array $s): array
{
    $required = [
        'smtp_host',
        'smtp_port',
        'smtp_from'
    ];

    $missing = [];

    foreach ($required as $key) {
        if (trim((string)($s[$key] ?? '')) === '') {
            $missing[] = $key;
        }
    }

    return $missing;
}

function smtp_read_line($fp): string
{
    $line = fgets($fp, 4096);
    return $line === false ? '' : trim($line);
}

function smtp_expect($fp, array $codes): array
{
    $line = smtp_read_line($fp);

    if ($line === '') {
        return [
            'ok' => false,
            'code' => 0,
            'message' => 'SMTPサーバから応答がありません。'
        ];
    }

    $code = (int)substr($line, 0, 3);

    return [
        'ok' => in_array($code, $codes, true),
        'code' => $code,
        'message' => $line
    ];
}

function smtp_send(
    array $settings,
    string $to,
    string $subject,
    string $body
): array {
    $missing = smtp_settings_complete($settings);

    if ($missing) {
        return [
            'success' => false,
            'message' => 'SMTP設定が不足しています: ' . implode(', ', $missing)
        ];
    }

    $host = trim((string)$settings['smtp_host']);
    $port = (int)$settings['smtp_port'];
    $encryption = strtoupper((string)($settings['smtp_encryption'] ?? 'TLS'));
    $timeout = max(3, min(120, (int)($settings['smtp_timeout'] ?? 15)));

    $target = $host . ':' . $port;

    if ($encryption === 'SSL') {
        $target = 'ssl://' . $target;
    }

    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        $target,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if ($fp === false) {
        return [
            'success' => false,
            'message' => 'SMTP TCP接続に失敗しました。',
            'diagnostic' => [
                'server' => $host,
                'port' => $port,
                'tcp' => false,
                'tls' => false,
                'error' => $errstr
            ]
        ];
    }

    stream_set_timeout($fp, $timeout);

    try {
        $r = smtp_expect($fp, [220]);

        if (!$r['ok']) {
            throw new RuntimeException($r['message']);
        }

        fwrite($fp, "EHLO localhost\r\n");
        $r = smtp_expect($fp, [250]);

        if (!$r['ok']) {
            throw new RuntimeException($r['message']);
        }

        if ($encryption === 'TLS') {
            fwrite($fp, "STARTTLS\r\n");
            $r = smtp_expect($fp, [220]);

            if (!$r['ok']) {
                throw new RuntimeException($r['message']);
            }

            $tls = @stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($tls !== true) {
                throw new RuntimeException('TLS接続に失敗しました。');
            }

            fwrite($fp, "EHLO localhost\r\n");
            $r = smtp_expect($fp, [250]);

            if (!$r['ok']) {
                throw new RuntimeException($r['message']);
            }
        }

        if (!empty($settings['smtp_auth'])) {
            fwrite($fp, "AUTH LOGIN\r\n");
            $r = smtp_expect($fp, [334]);

            if (!$r['ok']) {
                throw new RuntimeException('SMTP AUTH開始に失敗しました。');
            }

            fwrite($fp, base64_encode((string)$settings['smtp_username']) . "\r\n");
            $r = smtp_expect($fp, [334]);

            if (!$r['ok']) {
                throw new RuntimeException('SMTPユーザー認証に失敗しました。');
            }

            fwrite($fp, base64_encode((string)$settings['smtp_password']) . "\r\n");
            $r = smtp_expect($fp, [235]);

            if (!$r['ok']) {
                throw new RuntimeException('SMTPパスワード認証に失敗しました。');
            }
        }

        $from = (string)$settings['smtp_from'];

        fwrite($fp, "MAIL FROM:<{$from}>\r\n");
        $r = smtp_expect($fp, [250]);

        if (!$r['ok']) {
            throw new RuntimeException($r['message']);
        }

        fwrite($fp, "RCPT TO:<{$to}>\r\n");
        $r = smtp_expect($fp, [250, 251]);

        if (!$r['ok']) {
            throw new RuntimeException($r['message']);
        }

        fwrite($fp, "DATA\r\n");
        $r = smtp_expect($fp, [354]);

        if (!$r['ok']) {
            throw new RuntimeException($r['message']);
        }

        $fromName = (string)($settings['smtp_from_name'] ?? '');

        $fromHeader = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>'
            : $from;

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $message =
            "From: {$fromHeader}\r\n" .
            "To: {$to}\r\n" .
            "Subject: {$encodedSubject}\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n" .
            "\r\n" .
            str_replace("\n", "\r\n", $body) .
            "\r\n.\r\n";

        fwrite($fp, $message);

        $r = smtp_expect($fp, [250]);

        fwrite($fp, "QUIT\r\n");

        return [
            'success' => $r['ok'],
            'message' => $r['message'],
            'diagnostic' => [
                'server' => $host,
                'port' => $port,
                'tcp' => true,
                'tls' => $encryption === 'TLS' || $encryption === 'SSL',
                'smtp_code' => $r['code']
            ]
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'diagnostic' => [
                'server' => $host,
                'port' => $port,
                'tcp' => true,
                'tls' => $encryption === 'TLS' || $encryption === 'SSL'
            ]
        ];
    } finally {
        fclose($fp);
    }
}

/* ================================================================
 * CSV
 * ================================================================ */

function survey_csv(array $survey, array $responses): never
{
    $questions = survey_questions($survey);

    $filename = 'survey_' . $survey['id'] . '_' . date('YmdHis') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'w');

    $header = [
        '回答ID',
        '回答日時',
        '顧客ID',
        '会社名',
        '氏名'
    ];

    foreach ($questions as $q) {
        $header[] = $q['number'] . ' ' . $q['text'];
    }

    fputcsv($fp, $header);

    foreach ($responses as $r) {
        if (($r['survey_id'] ?? '') !== $survey['id']) {
            continue;
        }

        $row = [
            $r['id'] ?? '',
            $r['answered_at'] ?? '',
            $r['customer_id'] ?? '',
            $r['company'] ?? '',
            $r['name'] ?? ''
        ];

        $answers = $r['answers'] ?? [];

        foreach ($questions as $q) {
            $v = $answers[$q['id']] ?? '';

            if (is_array($v)) {
                $v = implode('、', $v);
            }

            $row[] = (string)$v;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* ================================================================
 * API
 * ================================================================ */

$input = survey_input();
$action = (string)($input['action'] ?? $_GET['action'] ?? '');

if ($action !== '') {
    try {
        if ($action === 'csrf') {
            survey_json([
                'ok' => true,
                'csrf_token' => survey_csrf()
            ]);
        }

        if ($action === 'login') {
            $ok = survey_login(
                survey_str($input['login_name'] ?? ''),
                (string)($input['password'] ?? '')
            );

            survey_json([
                'ok' => $ok,
                'message' => $ok
                    ? 'ログインしました。'
                    : 'ログイン情報が正しくありません。',
                'csrf_token' => survey_csrf()
            ], $ok ? 200 : 401);
        }

        if ($action === 'logout') {
            session_destroy();
            survey_json(['ok' => true]);
        }

        if ($action === 'public') {
            $data = survey_load();
            $survey = survey_find($data, survey_str($input['survey_id'] ?? ''));

            if (!$survey || $survey['deleted']) {
                survey_json([
                    'ok' => false,
                    'message' => 'アンケートが存在しません。'
                ], 404);
            }

            if ($survey['status'] !== 'active') {
                survey_json([
                    'ok' => false,
                    'message' => '現在このアンケートは回答できません。'
                ], 403);
            }

            survey_json([
                'ok' => true,
                'survey' => $survey
            ]);
        }

        if ($action === 'submit_response') {
            $data = survey_load();
            $survey = survey_find($data, survey_str($input['survey_id'] ?? ''));

            if (!$survey || $survey['status'] !== 'active') {
                survey_json([
                    'ok' => false,
                    'message' => '回答対象のアンケートがありません。'
                ], 404);
            }

            $answers = is_array($input['answers'] ?? null)
                ? $input['answers']
                : [];

            $email = survey_str($input['email'] ?? '', 320);
            $customerId = survey_str($input['customer_id'] ?? '');

            $response = [
                'id' => survey_id('response_'),
                'survey_id' => $survey['id'],
                'customer_id' => $customerId,
                'company' => survey_str($input['company'] ?? '', 500),
                'name' => survey_str($input['name'] ?? '', 500),
                'email' => $email,
                'answered_at' => survey_now(),
                'answers' => $answers
            ];

            $data['responses'][] = $response;

            foreach ($data['customers'] as &$customer) {
                if ($customerId !== '' &&
                    ($customer['id'] ?? '') === $customerId) {
                    $customer['answer_status'] = 'answered';
                    break;
                }

                if ($email !== '' &&
                    strcasecmp((string)($customer['email'] ?? ''), $email) === 0) {
                    $customer['answer_status'] = 'answered';
                    break;
                }
            }

            unset($customer);

            survey_save($data);

            survey_json([
                'ok' => true,
                'response_id' => $response['id']
            ]);
        }

        survey_require_auth();

        if ($_SERVER['REQUEST_METHOD'] !== 'GET' &&
            !in_array($action, ['login'], true)) {
            survey_check_csrf($input);
        }

        $data = survey_load();

        switch ($action) {
            case 'bootstrap':
                survey_json([
                    'ok' => true,
                    'csrf_token' => survey_csrf(),
                    'data' => $data,
                    'settings' => $data['settings'],
                    'user' => $_SESSION['survey_admin_user'] ?? ''
                ]);
                break;

            case 'save_survey':
                $s = json_decode(
                    (string)($input['survey_json'] ?? ''),
                    true
                );

                if (!is_array($s)) {
                    survey_json([
                        'ok' => false,
                        'message' => 'アンケートデータが不正です。'
                    ], 400);
                }

                $s = survey_renumber(survey_normalize($s));

                $found = false;

                foreach ($data['surveys'] as $i => $old) {
                    if (($old['id'] ?? '') === $s['id']) {
                        $s['created_at'] = $old['created_at'] ?? survey_now();
                        $data['surveys'][$i] = $s;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $data['surveys'][] = $s;
                }

                survey_save($data);

                survey_json([
                    'ok' => true,
                    'survey' => $s
                ]);
                break;

            case 'new_survey':
                $s = survey_renumber(survey_normalize([
                    'id' => survey_id('survey_'),
                    'title' => '新規アンケート',
                    'status' => 'draft',
                    'numbering_mode' => 'global',
                    'groups' => []
                ]));

                $data['surveys'][] = $s;
                survey_save($data);

                survey_json([
                    'ok' => true,
                    'survey' => $s
                ]);
                break;

            case 'delete_survey':
                $id = survey_str($input['survey_id'] ?? '');

                foreach ($data['surveys'] as &$s) {
                    if (($s['id'] ?? '') === $id) {
                        $s['deleted'] = true;
                        $s['updated_at'] = survey_now();
                    }
                }

                unset($s);

                survey_save($data);
                survey_json(['ok' => true]);
                break;

            case 'change_status':
                $id = survey_str($input['survey_id'] ?? '');
                $status = survey_str($input['status'] ?? 'draft');

                if (!in_array($status, ['draft', 'active', 'ended'], true)) {
                    survey_json([
                        'ok' => false,
                        'message' => 'ステータスが不正です。'
                    ], 400);
                }

                foreach ($data['surveys'] as &$s) {
                    if (($s['id'] ?? '') === $id) {
                        $s['status'] = $status;
                        $s['updated_at'] = survey_now();
                    }
                }

                unset($s);
                survey_save($data);

                survey_json(['ok' => true]);
                break;

            case 'duplicate_survey':
                $source = survey_find(
                    $data,
                    survey_str($input['survey_id'] ?? '')
                );

                if (!$source) {
                    survey_json([
                        'ok' => false,
                        'message' => '複製元がありません。'
                    ], 404);
                }

                $copy = $source;
                $copy['id'] = survey_id('survey_');
                $copy['title'] = $source['title'] . '（コピー）';
                $copy['status'] = 'draft';
                $copy['deleted'] = false;
                $copy['created_at'] = survey_now();
                $copy['updated_at'] = survey_now();

                foreach ($copy['groups'] as &$g) {
                    $g['id'] = survey_id('group_');

                    foreach ($g['questions'] as &$q) {
                        $q['id'] = survey_id('q_');

                        foreach ($q['options'] as &$o) {
                            $o['id'] = survey_id('opt_');
                        }

                        unset($o);
                    }

                    unset($q);
                }

                unset($g);

                $copy = survey_renumber($copy);

                $data['surveys'][] = $copy;
                survey_save($data);

                survey_json([
                    'ok' => true,
                    'survey' => $copy
                ]);
                break;

            case 'save_settings':
                $s = json_decode(
                    (string)($input['settings_json'] ?? ''),
                    true
                );

                if (!is_array($s)) {
                    survey_json([
                        'ok' => false,
                        'message' => '設定データが不正です。'
                    ], 400);
                }

                /*
                 * パスワードが空の場合、既存値を維持する。
                 */
                foreach (['password', 'smtp_password'] as $secret) {
                    if (($s[$secret] ?? '') === '') {
                        $s[$secret] = $data['settings'][$secret] ?? '';
                    }
                }

                $data['settings'] = array_merge(
                    $data['settings'],
                    $s
                );

                survey_save($data);

                survey_json([
                    'ok' => true,
                    'settings' => $data['settings']
                ]);
                break;

            case 'kintone_test':
                $s = json_decode(
                    (string)($input['settings_json'] ?? ''),
                    true
                );

                if (!is_array($s)) {
                    $s = $data['settings'];
                }

                $k = kintone_config($s);

                if ($k['subdomain'] === '' ||
                    $k['login_name'] === '' ||
                    $k['password'] === '') {
                    survey_json([
                        'ok' => false,
                        'message' => 'kintone接続情報が不足しています。'
                    ], 400);
                }

                $url = kintone_build_url(
                    $k['subdomain'],
                    '/k/v1/record.json'
                );

                $result = kintone_api_request(
                    'GET',
                    $url . '?app=' . rawurlencode($k['app_id']),
                    [
                        make_cybozu_auth_header(
                            $k['login_name'],
                            $k['password']
                        ),
                        'Accept: application/json'
                    ],
                    null,
                    $k
                );

                survey_json([
                    'ok' => $result['success'],
                    'message' => $result['message'] ??
                        ($result['success']
                            ? 'kintone接続に成功しました。'
                            : 'kintone接続に失敗しました。'),
                    'status' => $result['status'] ?? 0,
                    'diagnostic' => $result
                ]);
                break;

            case 'fetch_kintone_fields':
                $appId = survey_str(
                    $input['app_id'] ??
                    $data['settings']['app_id'] ??
                    ''
                );

                $result = kintone_fields(
                    $data['settings'],
                    $appId
                );

                if (!$result['success']) {
                    survey_json([
                        'ok' => false,
                        'message' => $result['message'] ?? 'フィールド取得に失敗しました。',
                        'status' => $result['status'] ?? 0
                    ], 400);
                }

                $fields = [];

                foreach (($result['data']['properties'] ?? []) as $code => $field) {
                    $fields[] = [
                        'code' => $code,
                        'label' => (string)($field['label'] ?? $code),
                        'type' => (string)($field['type'] ?? '')
                    ];
                }

                survey_json([
                    'ok' => true,
                    'fields' => $fields
                ]);
                break;

            case 'sync_customers':
                $result = kintone_records($data['settings']);

                if (!$result['success']) {
                    survey_json([
                        'ok' => false,
                        'message' => $result['message'] ?? '顧客データ取得に失敗しました。'
                    ], 400);
                }

                $s = $data['settings'];

                $getValue = static function(
                    array $record,
                    string $code
                ): string {
                    if ($code === '' || !isset($record[$code])) {
                        return '';
                    }

                    $v = $record[$code]['value'] ?? '';

                    if (is_array($v)) {
                        $parts = [];

                        foreach ($v as $x) {
                            if (is_array($x) && isset($x['value'])) {
                                $parts[] = (string)$x['value'];
                            } else {
                                $parts[] = (string)$x;
                            }
                        }

                        return implode(' ', $parts);
                    }

                    return (string)$v;
                };

                $customers = [];

                foreach (($result['data']['records'] ?? []) as $record) {
                    $email = $getValue(
                        $record,
                        (string)($s['field_email'] ?? '')
                    );

                    if ($email === '') {
                        continue;
                    }

                    $addressCodes = $s['field_address'] ?? [];
                    if (!is_array($addressCodes)) {
                        $addressCodes = [$addressCodes];
                    }

                    $address = [];

                    foreach ($addressCodes as $code) {
                        $v = $getValue($record, (string)$code);
                        if ($v !== '') {
                            $address[] = $v;
                        }
                    }

                    $customers[] = [
                        'id' => survey_id('customer_'),
                        'company' => $getValue(
                            $record,
                            (string)($s['field_company'] ?? '')
                        ),
                        'name' => $getValue(
                            $record,
                            (string)($s['field_name'] ?? '')
                        ),
                        'email' => $email,
                        'department' => $getValue(
                            $record,
                            (string)($s['field_department'] ?? '')
                        ),
                        'phone' => $getValue(
                            $record,
                            (string)($s['field_phone'] ?? '')
                        ),
                        'address' => implode(' ', $address),
                        'source' => 'kintone',
                        'sent_at' => '',
                        'send_count' => 0,
                        'answer_status' => 'unanswered',
                        'kintone_status' => 'registered'
                    ];
                }

                /*
                 * 既存の送信履歴をメールアドレスで維持する。
                 */
                foreach ($customers as &$c) {
                    foreach ($data['customers'] as $old) {
                        if (strcasecmp(
                            (string)$old['email'],
                            (string)$c['email']
                        ) === 0) {
                            $c['id'] = $old['id'];
                            $c['sent_at'] = $old['sent_at'] ?? '';
                            $c['send_count'] = $old['send_count'] ?? 0;
                            $c['answer_status'] =
                                $old['answer_status'] ?? 'unanswered';
                            break;
                        }
                    }
                }

                unset($c);

                $data['customers'] = $customers;
                survey_save($data);

                survey_json([
                    'ok' => true,
                    'count' => count($customers)
                ]);
                break;

            case 'smtp_test':
                $s = json_decode(
                    (string)($input['settings_json'] ?? ''),
                    true
                );

                if (!is_array($s)) {
                    $s = $data['settings'];
                }

                $to = survey_str($input['customer_id'] ?? '');

                /*
                 * customer_id欄にはテストメール宛先を受け取る。
                 */
                $result = smtp_send(
                    $s,
                    $to,
                    'アンケート管理システム SMTP送信テスト',
                    "SMTP設定が正常に動作し、テストメールの送信に成功したことを確認するための固定メッセージです。"
                );

                survey_json([
                    'ok' => $result['success'],
                    'message' => $result['message'],
                    'diagnostic' => $result['diagnostic'] ?? []
                ]);
                break;

            case 'smtp_connection_test':
                $s = json_decode(
                    (string)($input['settings_json'] ?? ''),
                    true
                );

                if (!is_array($s)) {
                    $s = $data['settings'];
                }

                $host = trim((string)($s['smtp_host'] ?? ''));
                $port = (int)($s['smtp_port'] ?? 587);
                $enc = strtoupper((string)($s['smtp_encryption'] ?? 'TLS'));
                $timeout = max(3, min(120, (int)($s['smtp_timeout'] ?? 15)));

                $target = $host . ':' . $port;

                if ($enc === 'SSL') {
                    $target = 'ssl://' . $target;
                }

                $errno = 0;
                $errstr = '';

                $fp = @stream_socket_client(
                    $target,
                    $errno,
                    $errstr,
                    $timeout,
                    STREAM_CLIENT_CONNECT
                );

                if ($fp === false) {
                    survey_json([
                        'ok' => false,
                        'message' => 'SMTP接続に失敗しました。',
                        'diagnostic' => [
                            'server' => $host,
                            'port' => $port,
                            'encryption' => $enc,
                            'tcp' => false,
                            'tls' => false,
                            'error' => $errstr
                        ]
                    ]);
                }

                stream_set_timeout($fp, $timeout);
                $r = smtp_expect($fp, [220]);
                fclose($fp);

                survey_json([
                    'ok' => $r['ok'],
                    'message' => $r['message'],
                    'diagnostic' => [
                        'server' => $host,
                        'port' => $port,
                        'encryption' => $enc,
                        'tcp' => true,
                        'smtp_code' => $r['code']
                    ]
                ]);
                break;

            case 'send_mail':
                $surveyId = survey_str($input['survey_id'] ?? '');
                $recipientIds = $input['recipient_ids'] ?? [];

                if (!is_array($recipientIds)) {
                    $recipientIds = [];
                }

                $survey = survey_find($data, $surveyId);

                if (!$survey) {
                    survey_json([
                        'ok' => false,
                        'message' => 'アンケートが存在しません。'
                    ], 404);
                }

                $subject = survey_str(
                    $input['mail_subject'] ?? '',
                    1000
                );

                $body = survey_str(
                    $input['mail_body'] ?? '',
                    30000
                );

                $templateType = in_array(
                    ($input['template_type'] ?? 'initial'),
                    ['initial', 'reminder'],
                    true
                )
                    ? $input['template_type']
                    : 'initial';

                if ($subject === '' || $body === '') {
                    survey_json([
                        'ok' => false,
                        'message' => '件名と本文を入力してください。'
                    ], 400);
                }

                $missing = smtp_settings_complete($data['settings']);

                if ($missing) {
                    survey_json([
                        'ok' => false,
                        'message' => 'SMTP設定が未完了です。',
                        'missing' => $missing
                    ], 400);
                }

                $success = 0;
                $failed = 0;
                $skipped = 0;
                $results = [];

                foreach ($recipientIds as $recipientId) {
                    $customer = null;

                    foreach ($data['customers'] as $c) {
                        if (($c['id'] ?? '') === (string)$recipientId) {
                            $customer = $c;
                            break;
                        }
                    }

                    if (!$customer || trim((string)$customer['email']) === '') {
                        $skipped++;
                        continue;
                    }

                    $personalSubject = str_replace(
                        ['{顧客名}'],
                        [(string)$customer['name']],
                        $subject
                    );

                    $personalBody = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [
                            (string)$customer['name'],
                            current_api_url() .
                            '?action=public&survey_id=' .
                            rawurlencode($surveyId) .
                            '&customer_id=' .
                            rawurlencode((string)$customer['id'])
                        ],
                        $body
                    );

                    $mail = smtp_send(
                        $data['settings'],
                        (string)$customer['email'],
                        $personalSubject,
                        $personalBody
                    );

                    if ($mail['success']) {
                        $success++;

                        foreach ($data['customers'] as &$c) {
                            if ($c['id'] === $customer['id']) {
                                $c['sent_at'] = survey_now();
                                $c['send_count'] =
                                    ((int)($c['send_count'] ?? 0)) + 1;
                                $c['answer_status'] = 'unanswered';
                                $c['send_status'] = 'success';
                                $c['send_error'] = '';
                                $c['sent_subject'] = $personalSubject;
                                $c['sent_body'] = $personalBody;
                            }
                        }

                        unset($c);

                        $results[] = [
                            'customer_id' => $customer['id'],
                            'status' => 'success'
                        ];
                    } else {
                        $failed++;

                        foreach ($data['customers'] as &$c) {
                            if ($c['id'] === $customer['id']) {
                                $c['send_status'] = 'failed';
                                $c['send_error'] =
                                    (string)$mail['message'];
                            }
                        }

                        unset($c);

                        $results[] = [
                            'customer_id' => $customer['id'],
                            'status' => 'failed',
                            'message' => $mail['message']
                        ];
                    }
                }

                $data['mail_logs'][] = [
                    'id' => survey_id('mail_log_'),
                    'survey_id' => $surveyId,
                    'sent_at' => survey_now(),
                    'template_type' => $templateType,
                    'recipient_count' => count($recipientIds),
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'skipped_count' => $skipped,
                    'mail_subject' => $subject,
                    'executed_by' =>
                        (string)($_SESSION['survey_admin_user'] ?? '')
                ];

                survey_save($data);

                survey_json([
                    'ok' => true,
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'skipped_count' => $skipped,
                    'results' => $results
                ]);
                break;

            case 'kintone_register_customer':
                $customerId = survey_str($input['customer_id'] ?? '');
                $customer = null;

                foreach ($data['customers'] as $c) {
                    if (($c['id'] ?? '') === $customerId) {
                        $customer = $c;
                        break;
                    }
                }

                if (!$customer) {
                    survey_json([
                        'ok' => false,
                        'message' => '顧客が見つかりません。'
                    ], 404);
                }

                $s = $data['settings'];

                $record = [];

                $set = static function(
                    array &$record,
                    string $code,
                    string $value
                ): void {
                    if ($code !== '') {
                        $record[$code] = ['value' => $value];
                    }
                };

                $set(
                    $record,
                    (string)($s['field_company'] ?? ''),
                    (string)$customer['company']
                );

                $set(
                    $record,
                    (string)($s['field_name'] ?? ''),
                    (string)$customer['name']
                );

                $set(
                    $record,
                    (string)($s['field_email'] ?? ''),
                    (string)$customer['email']
                );

                $set(
                    $record,
                    (string)($s['field_department'] ?? ''),
                    (string)$customer['department']
                );

                $set(
                    $record,
                    (string)($s['field_phone'] ?? ''),
                    (string)$customer['phone']
                );

                $addressCodes = $s['field_address'] ?? [];
                if (!is_array($addressCodes)) {
                    $addressCodes = [$addressCodes];
                }

                foreach ($addressCodes as $code) {
                    $set(
                        $record,
                        (string)$code,
                        (string)$customer['address']
                    );
                }

                $result = kintone_record_add($s, $record);

                if (!$result['success']) {
                    survey_json([
                        'ok' => false,
                        'message' => $result['message'] ?? 'kintone登録に失敗しました。'
                    ], 400);
                }

                foreach ($data['customers'] as &$c) {
                    if ($c['id'] === $customerId) {
                        $c['kintone_status'] = 'registered';
                    }
                }

                unset($c);

                survey_save($data);

                survey_json(['ok' => true]);
                break;

            case 'csv':
                $survey = survey_find(
                    $data,
                    survey_str($input['survey_id'] ?? '')
                );

                if (!$survey) {
                    http_response_code(404);
                    exit;
                }

                survey_csv($survey, $data['responses']);
                break;

            default:
                survey_json([
                    'ok' => false,
                    'message' => '未対応のactionです。'
                ], 400);
        }
    } catch (Throwable $e) {
        survey_json([
            'ok' => false,
            'message' => 'サーバー処理でエラーが発生しました。'
        ], 500);
    }
}

/*
 * HTML本体。
 * 画面固有HTMLはJSで生成する。
 */
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {
    state: {
        initialized: false,
        csrf: "",
        data: null,
        screen: "list",
        surveyId: "",
        survey: null,
        keyword: "",
        statusFilter: "all",
        sort: "updated_desc",
        responseKeyword: "",
        selectedQuestions: {},
        previewMode: "pc",
        loading: false,
        fields: [],
        settings: null,
        smtpDiagnostic: null,
        kintoneDiagnostic: null
    },

    api: {},
    render: {},
    actions: {},
    editor: {},
    aggregation: {},
    mail: {},
    settings: {},
    util: {},
    init: null
};

/* ================================================================
 * Utility
 * ================================================================ */

App.util.escape = function(v) {
    const d = document.createElement("div");
    d.textContent = v == null ? "" : String(v);
    return d.innerHTML;
};

App.util.json = function(v) {
    return JSON.stringify(v)
        .replace(/\\/g, "\\\\")
        .replace(/'/g, "\\'");
};

App.util.confirm = function(message) {
    return window.confirm(message);
};

App.util.notify = function(message) {
    window.alert(message);
};

App.util.statusLabel = function(status) {
    return {
        active: "公開中",
        draft: "下書き",
        ended: "終了"
    }[status] || status;
};

App.util.statusClass = function(status) {
    return {
        active: "bg-emerald-100 text-emerald-700",
        draft: "bg-slate-200 text-slate-700",
        ended: "bg-amber-100 text-amber-700"
    }[status] || "bg-slate-100";
};

App.util.typeLabel = function(type) {
    return {
        single: "単一選択",
        multiple: "複数選択",
        text: "自由記述"
    }[type] || type;
};

App.util.date = function(v) {
    if (!v) return "未設定";

    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return v;

    return d.toLocaleString("ja-JP", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit"
    });
};

App.util.findSurvey = function(id) {
    return (App.state.data?.surveys || []).find(x => x.id === id);
};

App.util.questions = function(survey) {
    return (survey?.groups || []).flatMap(g => g.questions || []);
};

/* ================================================================
 * API
 * ================================================================ */

App.api.call = async function(action, payload = {}, method = "POST") {
    const body = {...payload, action};

    if (method !== "GET") {
        body.csrf_token = App.state.csrf;
    }

    const options = {
        method,
        credentials: "same-origin",
        headers: {}
    };

    if (method === "GET") {
        const qs = new URLSearchParams(body).toString();
        const response = await fetch("index.php?" + qs, options);
        return await response.json();
    }

    options.headers["Content-Type"] = "application/json";
    options.headers["X-CSRF-Token"] = App.state.csrf;
    options.body = JSON.stringify(body);

    const response = await fetch("index.php", options);
    const data = await response.json();

    if (response.status === 401) {
        App.state.screen = "login";
        App.render.all();
    }

    return data;
};

App.api.bootstrap = async function() {
    const r = await App.api.call("bootstrap", {}, "GET");

    if (!r.ok) {
        App.state.screen = "login";
        App.render.all();
        return false;
    }

    App.state.csrf = r.csrf_token;
    App.state.data = r.data;
    App.state.settings = r.settings;

    return true;
};

/* ================================================================
 * Common Layout
 * ================================================================ */

App.render.header = function() {
    return `
<header class="fixed top-0 inset-x-0 z-40 bg-white border-b border-slate-200 shadow-sm">
  <div class="max-w-[1500px] mx-auto px-6 h-16 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-bold">Q</div>
      <div>
        <div class="font-bold">アンケート管理システム</div>
        <div class="text-[11px] text-slate-400">Survey Management</div>
      </div>
    </div>

    <nav class="flex items-center gap-1">
      <button onclick="App.actions.gotoList()"
        class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100">
        アンケート一覧
      </button>
      <button onclick="App.actions.gotoSettings()"
        class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100">
        キントーン・メール連携設定
      </button>
      <button onclick="App.actions.logout()"
        class="px-4 py-2 rounded-lg text-sm text-slate-500 hover:bg-slate-100">
        ログアウト
      </button>
    </nav>
  </div>
</header>
<div class="h-16"></div>`;
};

App.render.breadcrumb = function(items) {
    return `
<div class="flex items-center gap-2 text-sm text-slate-500 mb-5">
  ${items.map((x, i) => `
    ${i ? `<span class="text-slate-300">›</span>` : ""}
    <span class="${i === items.length - 1 ? "text-slate-800 font-medium" : ""}">
      ${App.util.escape(x)}
    </span>
  `).join("")}
</div>`;
};

App.render.shell = function(content) {
    return `
${App.render.header()}
<main class="max-w-[1500px] mx-auto px-6 py-8">
${content}
</main>`;
};

/* ================================================================
 * Login
 * ================================================================ */

App.render.login = function() {
    document.getElementById("app").innerHTML = `
<div class="min-h-screen flex items-center justify-center bg-slate-100">
  <div class="w-full max-w-md bg-white rounded-2xl shadow-xl border border-slate-200 p-8">
    <div class="text-center mb-8">
      <div class="mx-auto w-14 h-14 rounded-2xl bg-indigo-600 text-white
        flex items-center justify-center text-2xl font-bold mb-4">Q</div>
      <h1 class="text-xl font-bold">アンケート管理システム</h1>
      <p class="text-sm text-slate-500 mt-2">管理者ログイン</p>
    </div>

    <form onsubmit="App.actions.login(event)" class="space-y-5">
      <div>
        <label class="block text-sm font-medium mb-2">ログイン名</label>
        <input id="login_name" required
          class="w-full border border-slate-300 rounded-xl px-4 py-3 outline-none
          focus:ring-2 focus:ring-indigo-500">
      </div>

      <div>
        <label class="block text-sm font-medium mb-2">パスワード</label>
        <input id="login_password" type="password" required
          class="w-full border border-slate-300 rounded-xl px-4 py-3 outline-none
          focus:ring-2 focus:ring-indigo-500">
      </div>

      <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white
        rounded-xl py-3 font-semibold">
        ログイン
      </button>
    </form>

    <p class="text-xs text-slate-400 mt-6 text-center">
      認証情報はサーバー環境変数 SURVEY_ADMIN_USER /
      SURVEY_ADMIN_PASSWORD を使用します。
    </p>
  </div>
</div>`;
};

App.actions.login = async function(event) {
    event.preventDefault();

    const r = await App.api.call("login", {
        login_name: document.getElementById("login_name").value,
        password: document.getElementById("login_password").value
    });

    if (!r.ok) {
        App.util.notify(r.message || "ログインに失敗しました。");
        return;
    }

    App.state.csrf = r.csrf_token;
    await App.api.bootstrap();

    App.state.screen = "list";
    App.render.all();
};

App.actions.logout = async function() {
    if (!App.util.confirm("ログアウトしますか？")) return;

    await App.api.call("logout", {});
    location.reload();
};

/* ================================================================
 * Survey List
 * ================================================================ */

App.render.list = function() {
    const surveys = (App.state.data?.surveys || [])
        .filter(s => !s.deleted)
        .filter(s => {
            if (!App.state.keyword) return true;
            return (s.title || "")
                .toLowerCase()
                .includes(App.state.keyword.toLowerCase());
        })
        .filter(s => {
            if (App.state.statusFilter === "all") return true;
            return s.status === App.state.statusFilter;
        })
        .map(s => ({
            ...s,
            responseCount:
                (App.state.data.responses || [])
                .filter(r => r.survey_id === s.id).length
        }));

    surveys.sort((a, b) => {
        switch (App.state.sort) {
            case "updated_asc":
                return String(a.updated_at).localeCompare(String(b.updated_at));
            case "answers_desc":
                return b.responseCount - a.responseCount;
            case "answers_asc":
                return a.responseCount - b.responseCount;
            case "start_desc":
                return String(b.start_at || "").localeCompare(String(a.start_at || ""));
            case "start_asc":
                return String(a.start_at || "").localeCompare(String(b.start_at || ""));
            default:
                return String(b.updated_at).localeCompare(String(a.updated_at));
        }
    });

    document.getElementById("app").innerHTML = App.render.shell(`
${App.render.breadcrumb(["ホーム", "アンケート一覧"])}

<div class="flex items-center justify-between mb-6">
  <div>
    <h1 class="text-2xl font-bold">アンケート一覧</h1>
    <p class="text-sm text-slate-500 mt-1">
      アンケートの作成・公開・送信・集計を管理します。
    </p>
  </div>

  <button onclick="App.actions.newSurvey()"
    class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-5 py-3
    font-semibold shadow-sm">
    ＋ 新規アンケート作成
  </button>
</div>

<div class="bg-white border border-slate-200 rounded-2xl p-4 mb-5 shadow-sm">
  <div class="grid grid-cols-12 gap-3">
    <div class="col-span-5">
      <input
        onkeydown="App.actions.searchEnter(event)"
        oninput="App.actions.keyword(this.value)"
        value="${App.util.escape(App.state.keyword)}"
        placeholder="タイトルを検索してEnter"
        class="w-full border border-slate-300 rounded-xl px-4 py-2.5">
    </div>

    <div class="col-span-3">
      <select onchange="App.actions.toggleStatusFilter(this.value)"
        class="w-full border border-slate-300 rounded-xl px-4 py-2.5">
        ${[
          ["all","すべて"],
          ["active","公開中"],
          ["draft","下書き"],
          ["ended","終了"]
        ].map(x =>
          `<option value="${x[0]}" ${App.state.statusFilter===x[0]?"selected":""}>${x[1]}</option>`
        ).join("")}
      </select>
    </div>

    <div class="col-span-4">
      <select onchange="App.actions.sort(this.value)"
        class="w-full border border-slate-300 rounded-xl px-4 py-2.5">
        <option value="updated_desc" ${App.state.sort==="updated_desc"?"selected":""}>更新日：新しい順</option>
        <option value="updated_asc" ${App.state.sort==="updated_asc"?"selected":""}>更新日：古い順</option>
        <option value="answers_desc" ${App.state.sort==="answers_desc"?"selected":""}>回答数：多い順</option>
        <option value="answers_asc" ${App.state.sort==="answers_asc"?"selected":""}>回答数：少ない順</option>
        <option value="start_desc" ${App.state.sort==="start_desc"?"selected":""}>開始日：新しい順</option>
        <option value="start_asc" ${App.state.sort==="start_asc"?"selected":""}>開始日：古い順</option>
      </select>
    </div>
  </div>
</div>

<div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
<div class="overflow-x-auto">
<table class="w-full text-sm">
<thead class="bg-slate-50 border-b border-slate-200">
<tr>
  <th class="text-left px-5 py-4">作成日 / 更新日</th>
  <th class="text-left px-5 py-4">タイトル</th>
  <th class="text-left px-5 py-4">アンケート期間</th>
  <th class="text-left px-5 py-4">ステータス</th>
  <th class="text-right px-5 py-4">回答数</th>
  <th class="text-right px-5 py-4">操作</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100">
${surveys.length ? surveys.map(s => `
<tr class="hover:bg-slate-50">
  <td class="px-5 py-4 whitespace-nowrap">
    <div>${App.util.date(s.created_at)}</div>
    <div class="text-xs text-slate-400 mt-1">更新: ${App.util.date(s.updated_at)}</div>
  </td>

  <td class="px-5 py-4 font-bold">
    ${App.util.escape(s.title || "無題")}
  </td>

  <td class="px-5 py-4 whitespace-nowrap">
    ${s.start_at ? App.util.date(s.start_at) : "未設定"}
    <span class="text-slate-300 mx-1">～</span>
    ${s.end_at ? App.util.date(s.end_at) : "未設定"}
  </td>

  <td class="px-5 py-4">
    <span class="px-2.5 py-1 rounded-full text-xs font-semibold ${App.util.statusClass(s.status)}">
      ${App.util.statusLabel(s.status)}
    </span>
  </td>

  <td class="px-5 py-4 text-right font-semibold">
    ${s.responseCount} 件
  </td>

  <td class="px-5 py-4">
    <div class="flex justify-end gap-1 flex-wrap">
      <button onclick="App.actions.editSurvey('${s.id}')"
        class="px-3 py-1.5 rounded-lg border hover:bg-slate-100">
        ${s.status === "ended" ? "確認・編集" : "確認・編集"}
      </button>

      ${s.status !== "draft" ? `
      <button onclick="App.actions.aggregation('${s.id}')"
        class="px-3 py-1.5 rounded-lg border hover:bg-slate-100">
        集計
      </button>` : ""}

      ${s.status === "active" ? `
      <button onclick="App.actions.mail('${s.id}')"
        class="px-3 py-1.5 rounded-lg border hover:bg-slate-100">
        送信
      </button>

      <button onclick="App.actions.stop('${s.id}')"
        class="px-3 py-1.5 rounded-lg border border-amber-300 text-amber-700 hover:bg-amber-50">
        停止
      </button>` : ""}

      ${s.status === "draft" ? `
      <button onclick="App.actions.deleteSurvey('${s.id}')"
        class="px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50">
        削除
      </button>` : ""}

      <button onclick="App.actions.duplicate('${s.id}')"
        class="px-3 py-1.5 rounded-lg bg-slate-800 text-white hover:bg-slate-900">
        複製
      </button>
    </div>
  </td>
</tr>
`).join("") : `
<tr>
  <td colspan="6" class="px-5 py-16 text-center text-slate-400">
    該当するアンケートがありません。
  </td>
</tr>`}
</tbody>
</table>
</div>
</div>`);
};

App.actions.keyword = function(value) {
    App.state.keyword = value;
};

App.actions.searchEnter = function(event) {
    if (event.key === "Enter") {
        event.preventDefault();
        App.render.list();
    }
};

App.actions.toggleStatusFilter = function(value) {
    App.state.statusFilter = value;
    App.render.list();
};

App.actions.sort = function(value) {
    App.state.sort = value;
    App.render.list();
};

App.actions.gotoList = function() {
    App.state.screen = "list";
    App.render.all();
};

App.actions.gotoSettings = function() {
    App.state.screen = "settings";
    App.render.all();
};

App.actions.newSurvey = async function() {
    const r = await App.api.call("new_survey");

    if (!r.ok) {
        App.util.notify(r.message);
        return;
    }

    App.state.data.surveys.push(r.survey);
    App.state.survey = r.survey;
    App.state.surveyId = r.survey.id;
    App.state.screen = "editor";
    App.render.all();
};

App.actions.editSurvey = function(id) {
    const survey = App.util.findSurvey(id);

    if (!survey) return;

    App.state.surveyId = id;
    App.state.survey = JSON.parse(JSON.stringify(survey));
    App.state.screen = "editor";
    App.render.all();
};

App.actions.stop = async function(id) {
    if (!App.util.confirm("このアンケートを停止しますか？")) return;

    const r = await App.api.call("change_status", {
        survey_id: id,
        status: "ended"
    });

    if (!r.ok) {
        App.util.notify(r.message);
        return;
    }

    await App.api.bootstrap();
    App.render.list();
};

App.actions.deleteSurvey = async function(id) {
    if (!App.util.confirm("この下書きを削除しますか？")) return;

    const r = await App.api.call("delete_survey", {
        survey_id: id
    });

    if (!r.ok) {
        App.util.notify(r.message);
        return;
    }

    await App.api.bootstrap();
    App.render.list();
};

App.actions.duplicate = async function(id) {
    const r = await App.api.call("duplicate_survey", {
        survey_id: id
    });

    if (!r.ok) {
        App.util.notify(r.message);
        return;
    }

    await App.api.bootstrap();
    App.render.list();
};

/* ================================================================
 * Editor
 * ================================================================ */

App.render.editor = function() {
    const s = App.state.survey;

    document.getElementById("app").innerHTML = App.render.shell(`
${App.render.breadcrumb(["ホーム", "アンケート一覧", "アンケート作成・編集"])}

<div class="flex items-start justify-between mb-6">
  <div>
    <h1 class="text-2xl font-bold">アンケート作成・編集</h1>
    <p class="text-sm text-slate-500 mt-1">
      質問・グループをドラッグして並べ替えできます。
    </p>
  </div>

  <div class="flex gap-2">
    <button onclick="App.actions.preview()"
      class="px-4 py-2.5 rounded-xl border bg-white hover:bg-slate-50">
      プレビュー
    </button>

    <button onclick="App.actions.cancelEdit()"
      class="px-4 py-2.5 rounded-xl border hover:bg-slate-50">
      キャンセル
    </button>

    <button onclick="App.actions.saveSurvey()"
      class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold hover:bg-indigo-700">
      保存して一覧へ戻る
    </button>
  </div>
</div>

<div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6 mb-5">
  <div class="grid grid-cols-12 gap-4">
    <div class="col-span-6">
      <label class="text-sm font-medium">タイトル</label>
      <input id="survey_title"
        value="${App.util.escape(s.title)}"
        oninput="App.editor.changeTitle(this.value)"
        class="mt-2 w-full border border-slate-300 rounded-xl px-4 py-3 text-lg font-semibold">
    </div>

    <div class="col-span-2">
      <label class="text-sm font-medium">開始日時</label>
      <input id="survey_start_at" type="datetime-local"
        value="${App.util.escape(s.start_at)}"
        onchange="App.editor.changeField('start_at',this.value)"
        class="mt-2 w-full border border-slate-300 rounded-xl px-3 py-3">
    </div>

    <div class="col-span-2">
      <label class="text-sm font-medium">終了日時</label>
      <input id="survey_end_at" type="datetime-local"
        value="${App.util.escape(s.end_at)}"
        onchange="App.editor.changeField('end_at',this.value)"
        class="mt-2 w-full border border-slate-300 rounded-xl px-3 py-3">
    </div>

    <div class="col-span-2">
      <label class="text-sm font-medium">ステータス</label>
      <select onchange="App.editor.changeField('status',this.value)"
        class="mt-2 w-full border border-slate-300 rounded-xl px-3 py-3">
        <option value="draft" ${s.status==="draft"?"selected":""}>下書き</option>
        <option value="active" ${s.status==="active"?"selected":""}>公開中</option>
      </select>
    </div>

    <div class="col-span-12">
      <label class="text-sm font-medium">質問番号</label>
      <select id="survey_numbering_mode"
        onchange="App.editor.changeField('numbering_mode',this.value);App.editor.renumber();App.render.editor()"
        class="mt-2 border border-slate-300 rounded-xl px-3 py-2">
        <option value="global" ${s.numbering_mode==="global"?"selected":""}>Q1 / Q2 / Q3...</option>
        <option value="group" ${s.numbering_mode==="group"?"selected":""}>Q1-1 / Q1-2 / Q2-1...</option>
      </select>
    </div>
  </div>
</div>

<div id="question_editor" class="space-y-5">
${App.editor.groupsHtml()}
</div>

<div class="mt-5">
  <button onclick="App.editor.addGroup()"
    class="w-full py-4 rounded-2xl border-2 border-dashed border-slate-300
    text-slate-500 hover:border-indigo-400 hover:text-indigo-600 bg-white">
    ＋ グループを追加
  </button>
</div>

<div id="preview_modal"></div>
`);
    
    App.editor.initSortable();
};

App.editor.changeTitle = function(v) {
    App.state.survey.title = v;
};

App.editor.changeField = function(key, value) {
    App.state.survey[key] = value;
};

App.editor.groupsHtml = function() {
    const s = App.state.survey;

    return s.groups.map((g, gi) => `
<div class="group-card bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden"
     data-group-id="${g.id}">

  <div class="px-5 py-4 bg-slate-50 border-b border-slate-200 flex items-center gap-3">
    <span class="group-handle cursor-grab text-slate-400 text-xl">⠿</span>

    <input value="${App.util.escape(g.name)}"
      oninput="App.editor.changeGroupName('${g.id}',this.value)"
      class="flex-1 bg-transparent font-bold text-lg outline-none">

    <span class="text-xs text-slate-400">${g.questions.length} 問</span>

    <button onclick="App.editor.deleteGroup('${g.id}')"
      class="text-red-500 hover:bg-red-50 rounded-lg px-3 py-2">
      グループ削除
    </button>
  </div>

  <div class="question-list p-5 space-y-4 min-h-24"
       data-group-id="${g.id}">

    ${g.questions.map((q, qi) => App.editor.questionHtml(q, g, gi, qi)).join("")}

  </div>

  <div class="px-5 pb-5">
    <button onclick="App.editor.addQuestion('${g.id}')"
      class="w-full border border-dashed border-slate-300 rounded-xl py-3
      text-sm text-slate-500 hover:border-indigo-400 hover:text-indigo-600">
      ＋ 質問を追加
    </button>
  </div>
</div>`).join("");
};

App.editor.questionHtml = function(q, g, gi, qi) {
    const choices = q.options || [];

    return `
<div class="question-card border border-slate-200 rounded-xl bg-white shadow-sm p-5"
     data-question-id="${q.id}">

  <div class="flex items-start gap-3">
    <span class="question-handle cursor-grab text-slate-400 text-xl mt-2">⠿</span>

    <div class="flex-1">
      <div class="flex items-center gap-2 mb-3">
        <span class="font-bold text-indigo-600">${App.util.escape(q.number || "")}</span>

        <select onchange="App.editor.changeQuestion('${q.id}','type',this.value)"
          class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
          <option value="single" ${q.type==="single"?"selected":""}>単一選択</option>
          <option value="multiple" ${q.type==="multiple"?"selected":""}>複数選択</option>
          <option value="text" ${q.type==="text"?"selected":""}>自由記述</option>
        </select>

        <label class="ml-auto flex items-center gap-2 text-sm">
          <input type="checkbox"
            onchange="App.editor.changeQuestion('${q.id}','required',this.checked)"
            ${q.required?"checked":""}>
          必須回答
        </label>

        <button onclick="App.editor.deleteQuestion('${g.id}','${q.id}')"
          class="text-red-500 px-2 py-1 hover:bg-red-50 rounded-lg">
          削除
        </button>
      </div>

      <textarea
        oninput="App.editor.changeQuestion('${q.id}','text',this.value)"
        class="w-full border border-slate-300 rounded-xl px-4 py-3 resize-y"
        rows="2"
        placeholder="質問文">${App.util.escape(q.text)}</textarea>

      ${q.type !== "text" ? `
      <div class="mt-4 space-y-2">
        ${choices.map(o => `
        <div class="flex items-center gap-2">
          <span class="text-slate-400">
            ${q.type === "single" ? "○" : "□"}
          </span>

          <input value="${App.util.escape(o.label)}"
            oninput="App.editor.changeOption('${q.id}','${o.id}','label',this.value)"
            class="flex-1 border border-slate-300 rounded-lg px-3 py-2">

          ${q.type === "single" ? `
          <select
            onchange="App.editor.changeOption('${q.id}','${o.id}','branch_to',this.value)"
            class="border border-slate-300 rounded-lg px-2 py-2 text-xs">
            <option value="">分岐なし</option>
            ${App.util.questions(App.state.survey)
              .filter(x => x.id !== q.id)
              .map(x => `
              <option value="${x.id}" ${o.branch_to===x.id?"selected":""}>
                ${App.util.escape(x.number)} ${App.util.escape(x.text)}
              </option>`).join("")}
          </select>` : ""}

          <button onclick="App.editor.deleteOption('${q.id}','${o.id}')"
            class="text-red-500 px-2">×</button>
        </div>`).join("")}

        <button onclick="App.editor.addOption('${q.id}')"
          class="text-sm text-indigo-600 hover:underline">
          ＋ 選択肢を追加
        </button>
      </div>

      <label class="mt-4 flex items-center gap-2 text-sm">
        <input type="checkbox"
          onchange="App.editor.changeQuestion('${q.id}','other_enabled',this.checked)"
          ${q.other_enabled?"checked":""}>
        その他（自由記述）を許可
      </label>
      ` : `
      <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-400">
        回答者は複数行の自由記述を入力できます。
      </div>`}
    </div>
  </div>
</div>`;
};

App.editor.findQuestion = function(id) {
    for (const g of App.state.survey.groups) {
        const q = g.questions.find(x => x.id === id);
        if (q) return q;
    }
    return null;
};

App.editor.changeQuestion = function(id, key, value) {
    const q = App.editor.findQuestion(id);
    if (!q) return;

    q[key] = value;

    if (key === "type" && value === "text") {
        q.options = [];
    }
};

App.editor.changeGroupName = function(id, value) {
    const g = App.state.survey.groups.find(x => x.id === id);
    if (g) g.name = value;
};

App.editor.addGroup = function() {
    App.state.survey.groups.push({
        id: "group_" + crypto.randomUUID().replaceAll("-", ""),
        name: "グループ" + (App.state.survey.groups.length + 1),
        questions: []
    });

    App.editor.renumber();
    App.render.editor();
};

App.editor.deleteGroup = function(id) {
    if (App.state.survey.groups.length <= 1) {
        App.util.notify("最低1グループは必要です。");
        return;
    }

    if (!App.util.confirm("このグループと内包する質問を削除しますか？")) {
        return;
    }

    App.state.survey.groups =
        App.state.survey.groups.filter(g => g.id !== id);

    App.editor.renumber();
    App.render.editor();
};

App.editor.addQuestion = function(groupId) {
    const g = App.state.survey.groups.find(x => x.id === groupId);
    if (!g) return;

    g.questions.push({
        id: "q_" + crypto.randomUUID().replaceAll("-", ""),
        text: "",
        type: "single",
        required: false,
        options: [
            {
                id: "opt_" + crypto.randomUUID().replaceAll("-", ""),
                label: "選択肢1",
                branch_to: ""
            },
            {
                id: "opt_" + crypto.randomUUID().replaceAll("-", ""),
                label: "選択肢2",
                branch_to: ""
            }
        ],
        other_enabled: false
    });

    App.editor.renumber();
    App.render.editor();
};

App.editor.deleteQuestion = function(groupId, questionId) {
    if (!App.util.confirm("この質問を削除しますか？")) return;

    const g = App.state.survey.groups.find(x => x.id === groupId);
    if (!g) return;

    g.questions = g.questions.filter(q => q.id !== questionId);

    App.editor.renumber();
    App.render.editor();
};

App.editor.addOption = function(questionId) {
    const q = App.editor.findQuestion(questionId);
    if (!q) return;

    q.options.push({
        id: "opt_" + crypto.randomUUID().replaceAll("-", ""),
        label: "選択肢" + (q.options.length + 1),
        branch_to: ""
    });

    App.render.editor();
};

App.editor.deleteOption = function(questionId, optionId) {
    const q = App.editor.findQuestion(questionId);
    if (!q) return;

    q.options = q.options.filter(o => o.id !== optionId);
    App.render.editor();
};

App.editor.changeOption = function(questionId, optionId, key, value) {
    const q = App.editor.findQuestion(questionId);
    if (!q) return;

    const o = q.options.find(x => x.id === optionId);
    if (o) o[key] = value;
};

App.editor.renumber = function() {
    let n = 0;

    App.state.survey.groups.forEach((g, gi) => {
        g.questions.forEach((q, qi) => {
            n++;
            q.number = App.state.survey.numbering_mode === "group"
                ? `Q${gi + 1}-${qi + 1}`
                : `Q${n}`;
        });
    });
};

App.editor.initSortable = function() {
    const root = document.getElementById("question_editor");

    if (!root || typeof Sortable === "undefined") return;

    new Sortable(root, {
        animation: 150,
        handle: ".group-handle",
        onEnd: function(evt) {
            const groups = App.state.survey.groups;
            const [item] = groups.splice(evt.oldIndex, 1);
            groups.splice(evt.newIndex, 0, item);

            App.editor.renumber();
            App.render.editor();
        }
    });

    document.querySelectorAll(".question-list").forEach(el => {
        new Sortable(el, {
            group: "survey-questions",
            animation: 150,
            handle: ".question-handle",
            onEnd: function(evt) {
                App.editor.rebuildFromDOM();
            }
        });
    });
};

App.editor.rebuildFromDOM = function() {
    const old = App.state.survey;
    const map = {};

    old.groups.forEach(g => {
        g.questions.forEach(q => {
            map[q.id] = q;
        });
    });

    const groups = [];

    document.querySelectorAll(".group-card").forEach(card => {
        const gid = card.dataset.groupId;
        const oldGroup = old.groups.find(g => g.id === gid);

        if (!oldGroup) return;

        const questions = [];

        card.querySelectorAll(".question-card").forEach(qel => {
            const qid = qel.dataset.questionId;

            if (map[qid]) {
                questions.push(map[qid]);
            }
        });

        groups.push({
            ...oldGroup,
            questions
        });
    });

    App.state.survey.groups = groups;
    App.editor.renumber();
    App.render.editor();
};

App.actions.saveSurvey = async function() {
    App.editor.renumber();

    const r = await App.api.call("save_survey", {
        survey_json: JSON.stringify(App.state.survey)
    });

    if (!r.ok) {
        App.util.notify(r.message);
        return;
    }

    await App.api.bootstrap();

    App.util.notify("保存しました。");
    App.state.screen = "list";
    App.render.all();
};

App.actions.cancelEdit = function() {
    if (!App.util.confirm("変更を破棄して一覧へ戻りますか？")) return;

    App.state.screen = "list";
    App.render.all();
};

/* ================================================================
 * Preview
 * ================================================================ */

App.actions.preview = function() {
    const s = App.state.survey;

    document.getElementById("preview_modal").innerHTML = `
<div class="fixed inset-0 z-50 bg-slate-900/60 flex items-center justify-center p-6">
  <div class="${App.state.previewMode === "phone"
    ? "w-[390px]"
    : "w-full max-w-4xl"} max-h-[90vh] bg-white rounded-2xl shadow-2xl overflow-hidden">

    <div class="px-5 py-4 border-b flex items-center justify-between">
      <div class="font-bold">プレビュー</div>

      <div class="flex gap-1">
        <button onclick="App.actions.previewMode('pc')"
          class="px-3 py-1.5 rounded-lg text-sm
          ${App.state.previewMode==="pc"?"bg-indigo-100 text-indigo-700":"bg-slate-100"}">
          PC表示
        </button>

        <button onclick="App.actions.previewMode('phone')"
          class="px-3 py-1.5 rounded-lg text-sm
          ${App.state.previewMode==="phone"?"bg-indigo-100 text-indigo-700":"bg-slate-100"}">
          スマートフォン表示
        </button>

        <button onclick="App.actions.closePreview()"
          class="ml-2 px-3 py-1.5 rounded-lg hover:bg-slate-100">
          ×
        </button>
      </div>
    </div>

    <div id="preview_content" class="overflow-y-auto max-h-[calc(90vh-65px)]">
      ${App.render.previewContent(s)}
    </div>
  </div>
</div>`;
};

App.render.previewContent = function(s) {
    return `
<div class="p-7">
  <div class="mb-8">
    <h2 class="text-2xl font-bold">${App.util.escape(s.title)}</h2>
    <p class="text-sm text-slate-500 mt-2">
      回答者向けプレビュー
    </p>
  </div>

  ${s.groups.map(g => `
  <section class="mb-8">
    <h3 class="font-bold text-lg mb-4 pb-2 border-b">
      ${App.util.escape(g.name)}
    </h3>

    <div class="space-y-6">
      ${g.questions.map(q => `
      <div>
        <div class="font-medium mb-3">
          <span class="text-indigo-600 mr-2">${App.util.escape(q.number)}</span>
          ${App.util.escape(q.text)}
          ${q.required ? `<span class="text-red-500 text-xs ml-2">必須</span>` : ""}
        </div>

        ${q.type === "text" ? `
        <textarea rows="4"
          class="w-full border border-slate-300 rounded-xl px-4 py-3"
          placeholder="回答を入力してください"></textarea>` : `
        <div class="space-y-2">
          ${(q.options || []).map(o => `
          <label class="flex items-center gap-3 p-3 border rounded-xl">
            <input type="${q.type==="single"?"radio":"checkbox"}"
              name="${q.id}">
            <span>${App.util.escape(o.label)}</span>
          </label>`).join("")}

          ${q.other_enabled ? `
          <label class="flex items-center gap-3 p-3 border rounded-xl">
            <input type="${q.type==="single"?"radio":"checkbox"}"
              name="${q.id}">
            <span>その他</span>
          </label>` : ""}
        </div>`}
      </div>`).join("")}
    </div>
  </section>`).join("")}

  <button onclick="App.actions.previewSubmit()"
    class="w-full bg-indigo-600 text-white rounded-xl py-3 font-semibold">
    回答を送信
  </button>
</div>`;
};

App.actions.previewMode = function(mode) {
    App.state.previewMode = mode;
    App.actions.preview();
};

App.actions.closePreview = function() {
    const el = document.getElementById("preview_modal");
    if (el) el.innerHTML = "";
};

App.actions.previewSubmit = function() {
    App.util.notify("これはプレビューです。実際の回答は送信されません。");
};

/* ================================================================
 * Mail
 * ================================================================ */

App.actions.mail = function(id) {
    App.state.surveyId = id;
    App.state.screen = "customers";
    App.render.all();
};

App.render.customers = function() {
    const survey = App.util.findSurvey(App.state.surveyId);

    let customers = App.state.data.customers || [];

    const keyword = App.state.keyword.toLowerCase();

    if (keyword) {
        customers = customers.filter(c =>
            [c.company,c.name,c.email,c.phone,c.address]
            .join(" ")
            .toLowerCase()
            .includes(keyword)
        );
    }

    document.getElementById("app").innerHTML = App.render.shell(`
${App.render.breadcrumb(["ホーム", "アンケート一覧", "顧客選択・送信・送信履歴"])}

<div class="flex justify-between items-start mb-6">
  <div>
    <h1 class="text-2xl font-bold">顧客選択・メール送信</h1>
    <p class="text-sm text-slate-500 mt-1">${App.util.escape(s.title)}</p>
  </div>

  <button onclick="App.mail.send()"
    class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold">
    一括送信実行
  </button>
</div>

<div class="bg-white border rounded-2xl p-5 shadow-sm mb-5">
  <div class="grid grid-cols-2 gap-5">
    <div>
      <label class="text-sm font-medium">件名</label>
      <input id="mail_subject"
        value="【アンケート】${App.util.escape(survey.title)}"
        class="mt-2 w-full border rounded-xl px-4 py-3">
    </div>

    <div>
      <label class="text-sm font-medium">テンプレート</label>
      <select id="template_type"
        class="mt-2 w-full border rounded-xl px-4 py-3">
        <option value="initial">初回送信用</option>
        <option value="reminder">リマインド送信用</option>
      </select>
    </div>

    <div class="col-span-2">
      <label class="text-sm font-medium">本文</label>
      <textarea id="mail_body" rows="7"
        class="mt-2 w-full border rounded-xl px-4 py-3">${App.util.escape(
`{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。`
)}</textarea>
      <p class="text-xs text-slate-400 mt-2">
        使用可能な変数：{顧客名} / {アンケートURL}
      </p>
    </div>
  </div>
</div>

<div class="bg-white border rounded-2xl shadow-sm overflow-hidden">
<div class="px-5 py-4 border-b bg-slate-50 flex items-center gap-4">
  <input id="customer_filter"
    oninput="App.actions.customerKeyword(this.value)"
    placeholder="顧客名・会社名・メールアドレス等を検索"
    class="flex-1 border rounded-xl px-4 py-2.5">

  <label class="flex items-center gap-2 text-sm">
    <input id="select_all" type="checkbox"
      onchange="App.mail.selectAll(this.checked)">
    全選択
  </label>
</div>

<div class="overflow-x-auto">
<table class="w-full text-sm">
<thead class="bg-slate-50 border-b">
<tr>
<th class="px-4 py-3">選択</th>
<th class="px-4 py-3 text-left">会社名 / 氏名</th>
<th class="px-4 py-3 text-left">メール</th>
<th class="px-4 py-3 text-left">電話番号</th>
<th class="px-4 py-3 text-left">送信状況</th>
<th class="px-4 py-3 text-left">回答</th>
<th class="px-4 py-3 text-left">kintone</th>
</tr>
</thead>
<tbody id="customer_table" class="divide-y">
${customers.map(c => `
<tr>
<td class="px-4 py-4">
  ${c.source === "web" ? `
    <span class="text-xs text-slate-400">Web回答</span>` : `
    <input type="checkbox"
      class="recipient"
      data-id="${c.id}">`}
</td>

<td class="px-4 py-4">
  <div class="font-bold">${App.util.escape(c.company)}</div>
  <div>${App.util.escape(c.name)}</div>
  <div class="text-xs text-slate-400">${App.util.escape(c.address)}</div>
</td>

<td class="px-4 py-4">${App.util.escape(c.email)}</td>
<td class="px-4 py-4">${App.util.escape(c.phone)}</td>

<td class="px-4 py-4">
  ${c.sent_at ? `
  <div>${App.util.date(c.sent_at)}</div>
  <div class="text-xs text-slate-400">${c.send_count || 0} 回送信</div>
  <button onclick="App.mail.showSent('${c.id}')"
    class="text-indigo-600 text-xs mt-1">送信文を確認</button>
  ` : `<span class="text-slate-400">未送信</span>`}
</td>

<td class="px-4 py-4">
  <span class="px-2 py-1 rounded-full text-xs
  ${c.answer_status==="answered"
    ?"bg-emerald-100 text-emerald-700"
    :"bg-amber-100 text-amber-700"}">
    ${c.answer_status==="answered"?"回答済み":"送信済み（未回答）"}
  </span>
</td>

<td class="px-4 py-4">
  ${c.kintone_status === "registered"
    ? `<span class="text-emerald-600 text-xs">✓ キントーン登録完了</span>`
    : `<button onclick="App.mail.registerKintone('${c.id}')"
        class="text-indigo-600 text-xs">キントーン登録完了</button>`}
</td>
</tr>`).join("")}
</tbody>
</table>
</div>
</div>`);
};

App.actions.customerKeyword = function(value) {
    App.state.keyword = value;
    App.render.customers();

    const input = document.getElementById("customer_filter");
    if (input) {
        input.focus();
        input.setSelectionRange(value.length, value.length);
    }
};

App.mail.selectAll = function(checked) {
    document.querySelectorAll(".recipient").forEach(x => {
        x.checked = checked;
    });
};

App.mail.selected = function() {
    return [...document.querySelectorAll(".recipient:checked")]
        .map(x => x.dataset.id);
};

App.mail.send = async function() {
    const ids = App.mail.selected();

    if (!ids.length) {
        App.util.notify("送信対象を選択してください。");
        return;
    }

    const customers = App.state.data.customers || [];

    const sent = ids.filter(id => {
        const c = customers.find(x => x.id === id);
        return c && c.sent_at;
    });

    if (sent.length &&
        !App.util.confirm(
            "既に送信済みの宛先が含まれています。再送しますか？"
        )) {
        return;
    }

    const r = await App.api.call("send_mail", {
        survey_id: App.state.surveyId,
        recipient_ids: ids,
        mail_subject: document.getElementById("mail_subject").value,
        mail_body: document.getElementById("mail_body").value,
        template_type: document.getElementById("template_type").value
    });

    if (!r.ok) {
        App.util.notify(r.message);
        return;
    }

    await App.api.bootstrap();

    App.util.notify(
        `送信完了\n成功: ${r.success_count}件\n失敗: ${r.failed_count}件\n未送信: ${r.skipped_count}件`
    );

    App.render.customers();
};

App.mail.showSent = function(id) {
    const c = App.state.data.customers.find(x => x.id === id);

    if (!c) return;

    App.actions.openResponseModal(`
      <h3 class="font-bold text-lg mb-4">送信文</h3>
      <div class="text-sm text-slate-500 mb-3">
        件名：${App.util.escape(c.sent_subject || "")}
      </div>
      <pre class="whitespace-pre-wrap bg-slate-50 rounded-xl p-5 text-sm">${App.util.escape(c.sent_body || "")}</pre>
    `);
};

App.mail.registerKintone = async function(id) {
    if (!App.util.confirm("この顧客をkintoneへ登録しますか？")) return;

    const r = await App.api.call("kintone_register_customer", {
        customer_id: id
    });

    if (!r.ok) {
        App.util.notify(r.message);
        return;
    }

    await App.api.bootstrap();
    App.render.customers();
};

/* ================================================================
 * Aggregation
 * ================================================================ */

App.actions.aggregation = function(id) {
    App.state.surveyId = id;
    App.state.screen = "aggregation";
    App.render.all();
};

App.aggregation.stats = function(survey) {
    const responses = App.state.data.responses
        .filter(r => r.survey_id === survey.id);

    const customers = App.state.data.customers;

    const target = customers.filter(c => c.sent_at).length;

    const answeredFromCustomers = responses.filter(r =>
        r.customer_id &&
        customers.some(c => c.id === r.customer_id)
    ).length;

    const unregistered = responses.filter(r =>
        !r.customer_id ||
        !customers.some(c => c.id === r.customer_id)
    ).length;

    const unanswered = Math.max(
        0,
        target - answeredFromCustomers
    );

    const rate = target
        ? ((answeredFromCustomers / target) * 100).toFixed(1)
        : "0.0";

    return {
        target,
        responses: responses.length,
        unregistered,
        unanswered,
        rate
    };
};

App.render.aggregation = function() {
    const survey = App.util.findSurvey(App.state.surveyId);

    if (!survey) {
        App.state.screen = "list";
        App.render.all();
        return;
    }

    const stats = App.aggregation.stats(survey);
    const questions = App.util.questions(survey);

    if (!Object.keys(App.state.selectedQuestions).length) {
        questions.forEach(q => {
            App.state.selectedQuestions[q.id] = true;
        });
    }

    document.getElementById("app").innerHTML = App.render.shell(`
${App.render.breadcrumb(["ホーム", "アンケート一覧", "回答集計・データ出力"])}

<div class="flex items-center justify-between mb-6">
  <div>
    <div class="text-xs text-indigo-600 font-semibold mb-1">対象アンケート</div>
    <h1 class="text-2xl font-bold">${App.util.escape(survey.title)}</h1>
  </div>

  <div class="flex gap-2">
    <button onclick="App.aggregation.csv()"
      class="px-4 py-2.5 rounded-xl border bg-white hover:bg-slate-50">
      CSV出力
    </button>
    <button onclick="window.print()"
      class="px-4 py-2.5 rounded-xl border bg-white hover:bg-slate-50">
      PDF / 印刷
    </button>
  </div>
</div>

<div class="grid grid-cols-5 gap-4 mb-6">
${[
  ["送信対象者数",stats.target,"人"],
  ["回答数",stats.responses,"件"],
  ["未登録顧客からの回答数",stats.unregistered,"件"],
  ["未回答数",stats.unanswered,"人"],
  ["回答率",stats.rate,"%"]
].map(x => `
<div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
  <div class="text-sm text-slate-500">${x[0]}</div>
  <div class="text-3xl font-bold mt-2">${x[1]}<span class="text-sm ml-1">${x[2]}</span></div>
</div>`).join("")}
</div>

<div class="grid grid-cols-12 gap-5">
  <aside class="col-span-3 bg-white border rounded-2xl p-5 shadow-sm h-fit">
    <h2 class="font-bold mb-4">設問絞り込み</h2>

    <div class="flex gap-2 mb-4">
      <button onclick="App.aggregation.selectAll(true)"
        class="text-xs text-indigo-600">全選択</button>
      <button onclick="App.aggregation.selectAll(false)"
        class="text-xs text-slate-500">全解除</button>
    </div>

    <div class="space-y-3">
    ${questions.map(q => `
      <label class="flex items-start gap-2 text-sm">
        <input type="checkbox"
          onchange="App.aggregation.toggle('${q.id}',this.checked)"
          ${App.state.selectedQuestions[q.id]?"checked":""}>
        <span>
          <span class="font-medium">${App.util.escape(q.number)}</span>
          ${App.util.escape(q.text)}
          <span class="block text-xs text-slate-400">${App.util.typeLabel(q.type)}</span>
        </span>
      </label>`).join("")}
    </div>
  </aside>

  <section class="col-span-9 space-y-5">
    ${questions
      .filter(q => App.state.selectedQuestions[q.id])
      .map(q => App.aggregation.questionCard(q, survey))
      .join("")}

    ${App.aggregation.responsesTable(survey)}
  </section>
</div>

<div id="response_modal"></div>`);
};

App.aggregation.toggle = function(id, checked) {
    App.state.selectedQuestions[id] = checked;
    App.render.aggregation();
};

App.aggregation.selectAll = function(value) {
    App.util.questions(App.util.findSurvey(App.state.surveyId))
        .forEach(q => App.state.selectedQuestions[q.id] = value);

    App.render.aggregation();
};

App.aggregation.questionCard = function(q, survey) {
    const responses = App.state.data.responses
        .filter(r => r.survey_id === survey.id);

    if (q.type === "text") {
        return `
<div class="bg-white border rounded-2xl shadow-sm p-5">
  <div class="flex items-center justify-between mb-4">
    <h2 class="font-bold">${App.util.escape(q.number)} ${App.util.escape(q.text)}</h2>
    <span class="text-xs bg-slate-100 px-2 py-1 rounded">${App.util.typeLabel(q.type)}</span>
  </div>

  <div class="space-y-3 max-h-96 overflow-y-auto">
  ${responses.map(r => `
    <div class="border-b pb-3">
      <div class="text-xs text-slate-400">
        ${App.util.escape(r.company)} / ${App.util.escape(r.name)}
        ・ ${App.util.date(r.answered_at)}
      </div>
      <div class="mt-1 whitespace-pre-wrap">
        ${App.util.escape(r.answers?.[q.id] || "")}
      </div>
    </div>`).join("")}
  </div>
</div>`;
    }

    const counts = {};

    (q.options || []).forEach(o => {
        counts[o.id] = {
            label: o.label,
            count: 0
        };
    });

    let total = 0;

    responses.forEach(r => {
        const v = r.answers?.[q.id];

        if (Array.isArray(v)) {
            v.forEach(x => {
                if (counts[x]) {
                    counts[x].count++;
                    total++;
                }
            });
        } else if (v && counts[v]) {
            counts[v].count++;
            total++;
        }
    });

    return `
<div class="bg-white border rounded-2xl shadow-sm p-5">
  <div class="flex items-center justify-between mb-5">
    <h2 class="font-bold">${App.util.escape(q.number)} ${App.util.escape(q.text)}</h2>
    <span class="text-xs bg-indigo-50 text-indigo-700 px-2 py-1 rounded">
      ${App.util.typeLabel(q.type)}
    </span>
  </div>

  <div class="space-y-4">
  ${Object.values(counts).map(x => {
      const percent = total ? (x.count / total * 100) : 0;

      return `
      <div>
        <div class="flex justify-between text-sm mb-1">
          <span>${App.util.escape(x.label)}</span>
          <span>${x.count}件 / ${percent.toFixed(1)}%</span>
        </div>

        <div class="h-3 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full bg-indigo-500 rounded-full"
            style="width:${percent}%"></div>
        </div>
      </div>`;
  }).join("")}
  </div>
</div>`;
};

App.aggregation.responsesTable = function(survey) {
    let responses = App.state.data.responses
        .filter(r => r.survey_id === survey.id);

    return `
<div class="bg-white border rounded-2xl shadow-sm overflow-hidden">
  <div class="p-5 border-b">
    <h2 class="font-bold">個別回答一覧</h2>
    <input id="response_filter"
      oninput="App.aggregation.responseFilter(this.value)"
      value="${App.util.escape(App.state.responseKeyword)}"
      placeholder="会社名・氏名で検索"
      class="mt-3 w-full border rounded-xl px-4 py-2.5">
  </div>

  <div class="overflow-x-auto">
  <table class="w-full text-sm">
  <thead class="bg-slate-50 border-b">
  <tr>
    <th class="px-4 py-3 text-left">回答日時</th>
    <th class="px-4 py-3 text-left">会社名</th>
    <th class="px-4 py-3 text-left">氏名</th>
    <th class="px-4 py-3 text-left">メール</th>
    <th class="px-4 py-3"></th>
  </tr>
  </thead>

  <tbody id="response_table" class="divide-y">
  ${responses
    .filter(r => {
      const k = App.state.responseKeyword.toLowerCase();
      return !k ||
        [r.company,r.name,r.email]
        .join(" ")
        .toLowerCase()
        .includes(k);
    })
    .map(r => `
    <tr>
      <td class="px-4 py-4">${App.util.date(r.answered_at)}</td>
      <td class="px-4 py-4 font-semibold">${App.util.escape(r.company)}</td>
      <td class="px-4 py-4">${App.util.escape(r.name)}</td>
      <td class="px-4 py-4">${App.util.escape(r.email)}</td>
      <td class="px-4 py-4 text-right">
        <button onclick="App.aggregation.showResponse('${r.id}')"
          class="text-indigo-600 hover:underline">
          全回答を表示
        </button>
      </td>
    </tr>`).join("")}
  </tbody>
  </table>
  </div>
</div>`;
};

App.aggregation.responseFilter = function(value) {
    App.state.responseKeyword = value;
    App.render.aggregation();
};

App.aggregation.showResponse = function(id) {
    const r = App.state.data.responses.find(x => x.id === id);

    if (!r) return;

    const survey = App.util.findSurvey(r.survey_id);

    const html = `
<h2 class="text-xl font-bold mb-5">全回答</h2>

<div class="mb-5 text-sm">
  <div class="font-semibold">${App.util.escape(r.company)} / ${App.util.escape(r.name)}</div>
  <div class="text-slate-400">${App.util.escape(r.email)} ・ ${App.util.date(r.answered_at)}</div>
</div>

<div class="space-y-4">
${App.util.questions(survey).map(q => `
<div class="border rounded-xl p-4">
  <div class="font-medium text-sm">
    ${App.util.escape(q.number)} ${App.util.escape(q.text)}
  </div>
  <div class="mt-2 whitespace-pre-wrap">
    ${App.util.escape(
      Array.isArray(r.answers?.[q.id])
        ? r.answers[q.id].join("、")
        : r.answers?.[q.id] || ""
    )}
  </div>
</div>`).join("")}
</div>`;

    App.actions.openResponseModal(html);
};

App.aggregation.csv = function() {
    window.location.href =
        "index.php?action=csv&survey_id=" +
        encodeURIComponent(App.state.surveyId) +
        "&csrf_token=" +
        encodeURIComponent(App.state.csrf);
};

/* ================================================================
 * Modal
 * ================================================================ */

App.actions.openResponseModal = function(html) {
    document.getElementById("response_modal").innerHTML = `
<div class="fixed inset-0 z-50 bg-slate-900/60 flex items-center justify-center p-6">
  <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-hidden">
    <div class="flex justify-end p-3 border-b">
      <button onclick="App.actions.closeResponseModal()"
        class="px-3 py-2 rounded-lg hover:bg-slate-100">×</button>
    </div>
    <div class="p-6 overflow-y-auto max-h-[calc(90vh-60px)]">
      ${html}
    </div>
  </div>
</div>`;
};

App.actions.closeResponseModal = function() {
    const el = document.getElementById("response_modal");
    if (el) el.innerHTML = "";
};

/* ================================================================
 * Settings
 * ================================================================ */

App.render.settings = function() {
    const s = App.state.settings || {};

    const fieldOptions = function(selected, multiple = false) {
        const values = Array.isArray(selected)
            ? selected
            : [selected || ""];

        return `
        <option value="">未設定</option>
        ${App.state.fields.map(f => `
        <option value="${App.util.escape(f.code)}"
          ${values.includes(f.code)?"selected":""}>
          ${App.util.escape(f.label)} (${App.util.escape(f.code)})
        </option>`).join("")}`;
    };

    document.getElementById("app").innerHTML = App.render.shell(`
${App.render.breadcrumb(["ホーム", "システム設定", "kintone・メール連携設定"])}

<h1 class="text-2xl font-bold mb-6">kintone・メール連携設定</h1>

<div class="grid grid-cols-2 gap-6">

<section class="bg-white border rounded-2xl shadow-sm p-6">
  <h2 class="font-bold text-lg mb-5">kintone接続設定</h2>

  <div class="space-y-4">
    <div>
      <label class="text-sm font-medium">サブドメイン</label>
      <input id="setting_subdomain"
        value="${App.util.escape(s.subdomain || "")}"
        placeholder="xxxx または xxxx.cybozu.com"
        class="mt-2 w-full border rounded-xl px-4 py-3">
    </div>

    <div>
      <label class="text-sm font-medium">顧客管理アプリID</label>
      <input id="setting_app_id"
        value="${App.util.escape(s.app_id || "")}"
        class="mt-2 w-full border rounded-xl px-4 py-3">
    </div>

    <div>
      <label class="text-sm font-medium">ログイン名</label>
      <input id="setting_login_name"
        value="${App.util.escape(s.login_name || "")}"
        class="mt-2 w-full border rounded-xl px-4 py-3">
    </div>

    <div>
      <label class="text-sm font-medium">パスワード</label>
      <input id="setting_password" type="password"
        placeholder="変更しない場合は空欄"
        class="mt-2 w-full border rounded-xl px-4 py-3">
    </div>

    <div>
      <label class="text-sm font-medium">Proxy</label>
      <input id="setting_proxy"
        value="${App.util.escape(s.proxy || "")}"
        placeholder="host:port"
        class="mt-2 w-full border rounded-xl px-4 py-3">
    </div>

    <label class="flex gap-2 text-sm">
      <input id="setting_ssl_verify" type="checkbox"
        ${s.ssl_verify ? "checked" : ""}>
      SSL証明書検証を行う
    </label>

    <div class="flex gap-2">
      <button onclick="App.settings.fetchKintoneFields()"
        class="flex-1 bg-indigo-600 text-white rounded-xl py-3 font-semibold">
        項目一覧を取得
      </button>

      <button onclick="App.settings.testKintone()"
        class="flex-1 border rounded-xl py-3">
        接続確認
      </button>
    </div>

    <div id="field_message" class="text-sm"></div>
  </div>
</section>

<section class="bg-white border rounded-2xl shadow-sm p-6">
  <h2 class="font-bold text-lg mb-5">顧客項目マッピング</h2>

  <div class="space-y-4">
    ${[
      ["field_company","会社名",s.field_company,false],
      ["field_name","氏名",s.field_name,false],
      ["field_email","メールアドレス",s.field_email,false],
      ["field_department","部署名",s.field_department,false],
      ["field_phone","電話番号",s.field_phone,false],
      ["field_address","住所",s.field_address,true]
    ].map(x => `
    <div>
      <label class="text-sm font-medium">${x[1]}</label>
      <select
        id="${x[0]}"
        ${x[3] ? "multiple size=\"5\"" : ""}
        class="mt-2 w-full border rounded-xl px-3 py-2">
        ${fieldOptions(x[2], x[3])}
      </select>
    </div>`).join("")}

    <button onclick="App.settings.syncCustomers()"
      class="w-full border rounded-xl py-3 hover:bg-slate-50">
      顧客データを同期
    </button>
  </div>
</section>

<section class="bg-white border rounded-2xl shadow-sm p-6 col-span-2">
  <h2 class="font-bold text-lg mb-5">SMTP設定</h2>

  <div class="grid grid-cols-4 gap-4">
    <div>
      <label class="text-sm font-medium">SMTPサーバ</label>
      <input id="smtp_host"
        value="${App.util.escape(s.smtp_host || "")}"
        class="mt-2 w-full border rounded-xl px-3 py-2.5">
    </div>

    <div>
      <label class="text-sm font-medium">SMTPポート</label>
      <input id="smtp_port" type="number"
        value="${s.smtp_port || 587}"
        class="mt-2 w-full border rounded-xl px-3 py-2.5">
    </div>

    <div>
      <label class="text-sm font-medium">暗号化方式</label>
      <select id="smtp_encryption"
        class="mt-2 w-full border rounded-xl px-3 py-2.5">
        <option value="none" ${s.smtp_encryption==="none"?"selected":""}>なし</option>
        <option value="SSL" ${s.smtp_encryption==="SSL"?"selected":""}>SSL</option>
        <option value="TLS" ${s.smtp_encryption==="TLS"?"selected":""}>TLS</option>
      </select>
    </div>

    <div>
      <label class="text-sm font-medium">SMTP認証</label>
      <select id="smtp_auth"
        class="mt-2 w-full border rounded-xl px-3 py-2.5">
        <option value="1" ${s.smtp_auth!==false?"selected":""}>認証する</option>
        <option value="0" ${s.smtp_auth===false?"selected":""}>認証しない</option>
      </select>
    </div>

    <div>
      <label class="text-sm font-medium">SMTPユーザー名</label>
      <input id="smtp_username"
        value="${App.util.escape(s.smtp_username || "")}"
        class="mt-2 w-full border rounded-xl px-3 py-2.5">
    </div>

    <div>
      <label class="text-sm font-medium">SMTPパスワード</label>
      <input id="smtp_password" type="password"
        placeholder="変更しない場合は空欄"
        class="mt-2 w-full border rounded-xl px-3 py-2.5">
    </div>

    <div>
      <label class="text-sm font-medium">送信元メールアドレス</label>
      <input id="smtp_from"
        value="${App.util.escape(s.smtp_from || "")}"
        class="mt-2 w-full border rounded-xl px-3 py-2.5">
    </div>

    <div>
      <label class="text-sm font-medium">送信元表示名</label>
      <input id="smtp_from_name"
        value="${App.util.escape(s.smtp_from_name || "")}"
        class="mt-2 w-full border rounded-xl px-3 py-2.5">
    </div>

    <div>
      <label class="text-sm font-medium">接続タイムアウト</label>
      <input id="smtp_timeout" type="number"
        value="${s.smtp_timeout || 15}"
        class="mt-2 w-full border rounded-xl px-3 py-2.5">
    </div>

    <div class="col-span-3 flex items-end gap-2">
      <input id="smtp_test_to"
        placeholder="テストメール送信先"
        class="flex-1 border rounded-xl px-3 py-2.5">

      <button onclick="App.settings.smtpConnectionTest()"
        class="px-4 py-2.5 border rounded-xl">
        SMTP接続確認
      </button>

      <button onclick="App.settings.smtpTestMail()"
        class="px-4 py-2.5 bg-indigo-600 text-white rounded-xl">
        テストメール送信
      </button>
    </div>
  </div>
</section>

<section class="col-span-2 flex justify-end gap-3">
  <button onclick="App.settings.save()"
    class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-semibold">
    設定を保存
  </button>
</section>

</div>`);
};

App.settings.collect = function() {
    const address = [...document.getElementById("field_address").selectedOptions]
        .map(x => x.value)
        .filter(Boolean);

    return {
        subdomain: document.getElementById("setting_subdomain").value.trim(),
        app_id: document.getElementById("setting_app_id").value.trim(),
        login_name: document.getElementById("setting_login_name").value.trim(),
        password: document.getElementById("setting_password").value,
        proxy: document.getElementById("setting_proxy").value.trim(),
        ssl_verify: document.getElementById("setting_ssl_verify").checked,

        field_company: document.getElementById("field_company").value,
        field_name: document.getElementById("field_name").value,
        field_email: document.getElementById("field_email").value,
        field_department: document.getElementById("field_department").value,
        field_phone: document.getElementById("field_phone").value,
        field_address: address,

        smtp_host: document.getElementById("smtp_host").value.trim(),
        smtp_port: Number(document.getElementById("smtp_port").value),
        smtp_encryption: document.getElementById("smtp_encryption").value,
        smtp_auth: document.getElementById("smtp_auth").value === "1",
        smtp_username: document.getElementById("smtp_username").value,
        smtp_password: document.getElementById("smtp_password").value,
        smtp_from: document.getElementById("smtp_from").value.trim(),
        smtp_from_name: document.getElementById("smtp_from_name").value.trim(),
        smtp_timeout: Number(document.getElementById("smtp_timeout").value)
    };
};

App.settings.save = async function() {
    const settings = App.settings.collect();

    const r = await App.api.call("save_settings", {
        settings_json: JSON.stringify(settings)
    });

    if (!r.ok) {
        App.util.notify(r.message);
        return;
    }

    await App.api.bootstrap();
    App.util.notify("設定を保存しました。");
};

App.settings.fetchKintoneFields = async function() {
    const s = App.settings.collect();

    const r = await App.api.call("fetch_kintone_fields", {
        app_id: s.app_id
    });

    const message = document.getElementById("field_message");

    if (!r.ok) {
        message.innerHTML =
            `<span class="text-red-600">${App.util.escape(r.message)}</span>`;
        return;
    }

    App.state.fields = r.fields || [];
    message.innerHTML =
        `<span class="text-emerald-600">${r.fields.length}件のフィールドを取得しました。</span>`;

    App.render.settings();
};

App.settings.testKintone = async function() {
    const r = await App.api.call("kintone_test", {
        settings_json: JSON.stringify(App.settings.collect())
    });

    App.state.kintoneDiagnostic = r;

    App.util.notify(
        r.ok
            ? "kintone接続に成功しました。"
            : "kintone接続に失敗しました。\n" + (r.message || "")
    );
};

App.settings.syncCustomers = async function() {
    const r = await App.api.call("sync_customers");

    if (!r.ok) {
        App.util.notify(r.message);
        return;
    }

    await App.api.bootstrap();

    App.util.notify(`${r.count}件の顧客データを同期しました。`);
};

App.settings.smtpConnectionTest = async function() {
    const r = await App.api.call("smtp_connection_test", {
        settings_json: JSON.stringify(App.settings.collect())
    });

    App.util.notify(
        (r.ok ? "SMTP接続成功\n" : "SMTP接続失敗\n") +
        (r.message || "")
    );
};

App.settings.smtpTestMail = async function() {
    const to = document.getElementById("smtp_test_to").value.trim();

    if (!to) {
        App.util.notify("テストメール送信先を入力してください。");
        return;
    }

    const r = await App.api.call("smtp_test", {
        customer_id: to,
        settings_json: JSON.stringify(App.settings.collect())
    });

    App.util.notify(
        (r.ok ? "テストメール送信成功\n" : "テストメール送信失敗\n") +
        (r.message || "")
    );
};

/* ================================================================
 * Render Router
 * ================================================================ */

App.render.all = function() {
    if (!App.state.initialized) return;

    if (App.state.screen === "login") {
        App.render.login();
        return;
    }

    switch (App.state.screen) {
        case "editor":
            App.render.editor();
            break;

        case "customers":
            App.render.customers();
            break;

        case "aggregation":
            App.render.aggregation();
            break;

        case "settings":
            App.render.settings();
            break;

        default:
            App.render.list();
    }
};

/* ================================================================
 * Initialization
 * ================================================================ */

App.init = async function() {
    if (App.state.initialized) return;

    App.state.initialized = true;

    const ok = await App.api.bootstrap();

    if (!ok) {
        App.state.screen = "login";
        App.render.all();
        return;
    }

    App.state.screen = "list";
    App.render.all();
};

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => App.init(), {
        once: true
    });
} else {
    App.init();
}
</script>

</body>
</html>
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

date_default_timezone_set('Asia/Tokyo');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_name(SURVEY_ADMIN_SESSION);
session_set_cookie_params([
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

/* =========================================================
 * PHP 基本
 * ========================================================= */

function survey_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_now(): string
{
    return date('c');
}

function survey_id(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(10));
}

function survey_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function survey_input(): array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $v = json_decode($raw ?: '', true);
        return is_array($v) ? $v : [];
    }

    return $_POST;
}

function survey_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        survey_json(['ok' => false, 'message' => 'POSTで実行してください。'], 405);
    }
}

function survey_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function survey_verify_csrf(array $input): void
{
    $token = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if (
        $token === '' ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        survey_json([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。画面を再読み込みしてください。'
        ], 403);
    }
}

/* =========================================================
 * 認証
 * ========================================================= */

function survey_logged_in(): bool
{
    return !empty($_SESSION['survey_authenticated']);
}

function survey_login(string $user, string $pass): bool
{
    $expectedUser = getenv('SURVEY_ADMIN_USER');
    $expectedPass = getenv('SURVEY_ADMIN_PASSWORD');

    if ($expectedUser === false || $expectedPass === false) {
        return false;
    }

    if (
        hash_equals((string)$expectedUser, $user) &&
        hash_equals((string)$expectedPass, $pass)
    ) {
        session_regenerate_id(true);
        $_SESSION['survey_authenticated'] = true;
        $_SESSION['survey_user'] = $user;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return true;
    }

    return false;
}

function survey_require_login(): void
{
    if (!survey_logged_in()) {
        survey_json([
            'ok' => false,
            'message' => 'ログインが必要です。',
            'error_type' => 'authentication'
        ], 401);
    }
}

/* =========================================================
 * JSONストレージ
 * ========================================================= */

function survey_empty_data(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [],
        'settings' => [
            'kintone' => [
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
            ],
            'smtp' => [
                'host' => '',
                'port' => 587,
                'encryption' => 'TLS',
                'auth' => true,
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => '',
                'timeout' => 15,
            ],
        ],
        'mail_logs' => [],
    ];
}

function survey_ensure_storage(): void
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!mkdir(SURVEY_STORAGE_DIRECTORY, 0750, true) && !is_dir(SURVEY_STORAGE_DIRECTORY)) {
            throw new RuntimeException('ストレージディレクトリを作成できません。');
        }
    }
}

function survey_load(): array
{
    survey_ensure_storage();

    if (!file_exists(SURVEY_STORAGE_FILE)) {
        $data = survey_empty_data();
        survey_save($data);
        return $data;
    }

    $raw = file_get_contents(SURVEY_STORAGE_FILE);

    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('survey_data.jsonを読み込めません。');
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException('survey_data.jsonの形式が不正です。');
    }

    $base = survey_empty_data();

    foreach ($base as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function survey_save(array $data): void
{
    survey_ensure_storage();

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE |
        JSON_THROW_ON_ERROR
    );

    $lock = fopen(SURVEY_STORAGE_FILE . '.lock', 'c');

    if ($lock === false) {
        throw new RuntimeException('ロックファイルを作成できません。');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('排他ロックを取得できません。');
        }

        $tmp = tempnam(SURVEY_STORAGE_DIRECTORY, 'survey_');

        if ($tmp === false) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }

        file_put_contents($tmp, $json, LOCK_EX);

        if (!rename($tmp, SURVEY_STORAGE_FILE)) {
            @unlink($tmp);
            throw new RuntimeException('JSONファイルを更新できません。');
        }

        @chmod(SURVEY_STORAGE_FILE, 0640);
        flock($lock, LOCK_UN);
    } finally {
        fclose($lock);
    }
}

function survey_public_settings(array $settings): array
{
    $out = $settings;

    foreach (['kintone', 'smtp'] as $section) {
        if (!isset($out[$section]) || !is_array($out[$section])) {
            $out[$section] = [];
        }

        unset(
            $out[$section]['password'],
            $out[$section]['api_token'],
            $out[$section]['token'],
            $out[$section]['authorization']
        );

        $out[$section]['password_configured'] =
            !empty($settings[$section]['password']);
    }

    return $out;
}

function survey_public_data(array $data): array
{
    $data['settings'] = survey_public_settings($data['settings']);
    return $data;
}

/* =========================================================
 * アンケート正規化
 * ========================================================= */

function survey_normalize_question(array $q): array
{
    $type = (string)($q['type'] ?? 'text');

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'text';
    }

    $options = [];

    foreach (($q['options'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }

        $options[] = [
            'id' => (string)($option['id'] ?? survey_id('option_')),
            'label' => mb_substr(trim((string)($option['label'] ?? '')), 0, 1000),
            'branch_to' => (string)($option['branch_to'] ?? ''),
        ];
    }

    return [
        'id' => (string)($q['id'] ?? survey_id('question_')),
        'text' => mb_substr(trim((string)($q['text'] ?? '')), 0, 5000),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
        'other_enabled' => !empty($q['other_enabled']),
    ];
}

function survey_normalize_group(array $g): array
{
    $questions = [];

    foreach (($g['questions'] ?? []) as $q) {
        if (is_array($q)) {
            $questions[] = survey_normalize_question($q);
        }
    }

    return [
        'id' => (string)($g['id'] ?? survey_id('group_')),
        'name' => mb_substr(trim((string)($g['name'] ?? 'グループ')), 0, 1000),
        'questions' => $questions,
    ];
}

function survey_renumber(array $survey): array
{
    $global = 0;

    foreach ($survey['groups'] as $gi => &$group) {
        foreach ($group['questions'] as $qi => &$question) {
            $global++;

            $question['number'] =
                ($survey['numbering_mode'] ?? 'global') === 'group'
                    ? 'Q' . ($gi + 1) . '-' . ($qi + 1)
                    : 'Q' . $global;
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

function survey_normalize(array $s): array
{
    $groups = [];

    foreach (($s['groups'] ?? []) as $g) {
        if (is_array($g)) {
            $groups[] = survey_normalize_group($g);
        }
    }

    if (!$groups) {
        $groups[] = [
            'id' => survey_id('group_'),
            'name' => 'グループ1',
            'questions' => [],
        ];
    }

    return survey_renumber([
        'id' => (string)($s['id'] ?? survey_id('survey_')),
        'title' => mb_substr(trim((string)($s['title'] ?? '無題のアンケート')), 0, 500),
        'start_at' => (string)($s['start_at'] ?? ''),
        'end_at' => (string)($s['end_at'] ?? ''),
        'status' => in_array(
            ($s['status'] ?? 'draft'),
            ['draft', 'active', 'ended'],
            true
        ) ? $s['status'] : 'draft',
        'created_at' => (string)($s['created_at'] ?? survey_now()),
        'updated_at' => survey_now(),
        'numbering_mode' => ($s['numbering_mode'] ?? 'global') === 'group'
            ? 'group'
            : 'global',
        'groups' => $groups,
        'deleted' => !empty($s['deleted']),
    ]);
}

/* =========================================================
 * kintone
 * ========================================================= */

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
    $domain = rtrim($domain, '/');

    return 'https://' . $domain . '.cybozu.com/' . ltrim($endpoint, '/');
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
        'timeout' => 15,
    ];

    if ($payload !== null && $method !== 'GET') {
        $options['content'] =
            is_array($payload)
                ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)$payload;
    }

    if (!empty($config['proxy'])) {
        $options['proxy'] = 'tcp://' . trim((string)$config['proxy']);
        $options['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $options,
        'ssl' => [
            'verify_peer' => !empty($config['ssl_verify']),
            'verify_peer_name' => !empty($config['ssl_verify']),
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headersOut = get_safe_response_headers();

    $status = 500;

    foreach ($headersOut as $header) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/i', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    $data = json_decode($body ?: '', true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => $data,
        ];
    }

    return [
        'success' => false,
        'status' => $status,
        'message' => is_array($data)
            ? (string)($data['message'] ?? 'kintone APIエラー')
            : 'kintone API通信エラー',
        'data' => $data,
    ];
}

function make_cybozu_auth_header(string $login, string $password): string
{
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login) . ':' . trim($password));
}

function kintone_config(array $data): array
{
    return $data['settings']['kintone'] ?? [];
}

/* =========================================================
 * SMTP
 * ========================================================= */

function smtp_read($socket, int $timeout = 15): array
{
    stream_set_timeout($socket, $timeout);

    $lines = [];
    $code = 0;

    while (!feof($socket)) {
        $line = fgets($socket, 8192);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];

            if ($m[2] === ' ') {
                break;
            }
        }
    }

    return [$code, implode("\n", $lines)];
}

function smtp_write($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

function smtp_expect($socket, array $codes): string
{
    [$code, $text] = smtp_read($socket);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException(
            'SMTP応答エラー [' . $code . '] ' . $text
        );
    }

    return $text;
}

function smtp_send_mail(
    array $cfg,
    string $to,
    string $subject,
    string $body
): array {
    $host = trim((string)($cfg['host'] ?? ''));
    $port = (int)($cfg['port'] ?? 587);
    $encryption = strtoupper((string)($cfg['encryption'] ?? 'TLS'));
    $username = (string)($cfg['username'] ?? '');
    $password = (string)($cfg['password'] ?? '');
    $from = trim((string)($cfg['from_email'] ?? ''));
    $fromName = (string)($cfg['from_name'] ?? '');
    $timeout = max(3, (int)($cfg['timeout'] ?? 15));

    if ($host === '' || $from === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'SMTP設定または宛先メールアドレスが不正です。',
        ];
    }

    $transport = 'tcp://';

    if ($encryption === 'SSL') {
        $transport = 'ssl://';
    }

    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return [
            'success' => false,
            'message' => 'SMTP接続失敗: ' . $errstr,
        ];
    }

    try {
        smtp_expect($socket, [220]);

        smtp_write($socket, 'EHLO localhost');
        smtp_expect($socket, [250]);

        if ($encryption === 'TLS') {
            smtp_write($socket, 'STARTTLS');
            smtp_expect($socket, [220]);

            if (!stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) {
                throw new RuntimeException('TLS接続に失敗しました。');
            }

            smtp_write($socket, 'EHLO localhost');
            smtp_expect($socket, [250]);
        }

        if (!empty($cfg['auth'])) {
            if ($username === '' || $password === '') {
                throw new RuntimeException('SMTP認証情報が設定されていません。');
            }

            smtp_write($socket, 'AUTH LOGIN');
            smtp_expect($socket, [334]);

            smtp_write($socket, base64_encode($username));
            smtp_expect($socket, [334]);

            smtp_write($socket, base64_encode($password));
            smtp_expect($socket, [235]);
        }

        smtp_write($socket, 'MAIL FROM:<' . $from . '>');
        smtp_expect($socket, [250]);

        smtp_write($socket, 'RCPT TO:<' . $to . '>');
        smtp_expect($socket, [250, 251]);

        smtp_write($socket, 'DATA');
        smtp_expect($socket, [354]);

        $encodedSubject = '=?UTF-8?B?' .
            base64_encode($subject) .
            '?=';

        $encodedFrom = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>'
            : $from;

        $message =
            'From: ' . $encodedFrom . "\r\n" .
            'To: <' . $to . ">\r\n" .
            'Subject: ' . $encodedSubject . "\r\n" .
            'MIME-Version: 1.0\r\n' .
            'Content-Type: text/plain; charset=UTF-8\r\n' .
            'Content-Transfer-Encoding: 8bit\r\n' .
            "\r\n" .
            str_replace(["\r\n", "\r"], "\n", $body);

        $message = str_replace("\n", "\r\n", $message);

        fwrite($socket, $message . "\r\n.\r\n");
        smtp_expect($socket, [250]);

        smtp_write($socket, 'QUIT');

        return [
            'success' => true,
            'message' => '送信しました。',
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    } finally {
        fclose($socket);
    }
}

/* =========================================================
 * API
 * ========================================================= */

function survey_api(): void
{
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    $input = survey_input();

    /*
     * ★重要
     * bootstrap と get_initial_data の不一致を修正。
     * bootstrapは認証状態と初期データを同時に返す。
     */
    if ($action === 'bootstrap' || $action === 'get_initial_data') {
        $data = survey_load();

        survey_json([
            'ok' => true,
            'authenticated' => survey_logged_in(),
            'csrf_token' => survey_csrf(),
            'data' => survey_logged_in()
                ? survey_public_data($data)
                : [
                    'surveys' => [],
                    'responses' => [],
                    'customers' => [],
                    'settings' => survey_public_settings($data['settings']),
                    'mail_logs' => [],
                ],
        ]);
    }

    if ($action === 'login') {
        survey_require_post();

        $user = trim((string)($input['login_name'] ?? ''));
        $pass = (string)($input['password'] ?? '');

        if (!survey_login($user, $pass)) {
            survey_json([
                'ok' => false,
                'message' => 'ログイン名またはパスワードが正しくありません。'
            ], 401);
        }

        $data = survey_load();

        survey_json([
            'ok' => true,
            'authenticated' => true,
            'csrf_token' => survey_csrf(),
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);

        survey_json([
            'ok' => true
        ]);
    }

    survey_require_login();

    if ($action === 'save_survey') {
        survey_require_post();
        survey_verify_csrf($input);

        $raw = $input['survey_json'] ?? null;

        if (is_string($raw)) {
            $survey = json_decode($raw, true);
        } else {
            $survey = $raw;
        }

        if (!is_array($survey)) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], 400);
        }

        $survey = survey_normalize($survey);
        $data = survey_load();

        $found = false;

        foreach ($data['surveys'] as $i => $old) {
            if (($old['id'] ?? '') === $survey['id']) {
                $survey['created_at'] = $old['created_at'] ?? survey_now();
                $data['surveys'][$i] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['surveys'][] = $survey;
        }

        survey_save($data);

        survey_json([
            'ok' => true,
            'survey' => $survey,
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'delete_survey') {
        survey_require_post();
        survey_verify_csrf($input);

        $id = (string)($input['survey_id'] ?? '');
        $data = survey_load();

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['deleted'] = true;
                $survey['updated_at'] = survey_now();
            }
        }

        unset($survey);

        survey_save($data);

        survey_json([
            'ok' => true,
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'set_status') {
        survey_require_post();
        survey_verify_csrf($input);

        $id = (string)($input['survey_id'] ?? '');
        $status = (string)($input['status'] ?? 'draft');

        if (!in_array($status, ['draft', 'active', 'ended'], true)) {
            survey_json([
                'ok' => false,
                'message' => 'ステータスが不正です。'
            ], 400);
        }

        $data = survey_load();

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['status'] = $status;
                $survey['updated_at'] = survey_now();
            }
        }

        unset($survey);

        survey_save($data);

        survey_json([
            'ok' => true,
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'duplicate_survey') {
        survey_require_post();
        survey_verify_csrf($input);

        $id = (string)($input['survey_id'] ?? '');
        $data = survey_load();
        $source = null;

        foreach ($data['surveys'] as $survey) {
            if (($survey['id'] ?? '') === $id) {
                $source = $survey;
                break;
            }
        }

        if (!$source) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $copy = $source;
        $copy['id'] = survey_id('survey_');
        $copy['title'] = $source['title'] . '（複製）';
        $copy['status'] = 'draft';
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();
        $copy['deleted'] = false;

        foreach ($copy['groups'] as &$group) {
            $group['id'] = survey_id('group_');

            foreach ($group['questions'] as &$question) {
                $question['id'] = survey_id('question_');

                foreach ($question['options'] as &$option) {
                    $option['id'] = survey_id('option_');
                    $option['branch_to'] = '';
                }

                unset($option);
            }

            unset($question);
        }

        unset($group);

        $copy = survey_renumber($copy);
        $data['surveys'][] = $copy;

        survey_save($data);

        survey_json([
            'ok' => true,
            'survey' => $copy,
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'save_settings') {
        survey_require_post();
        survey_verify_csrf($input);

        $settings = $input['settings_json'] ?? null;

        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        if (!is_array($settings)) {
            survey_json([
                'ok' => false,
                'message' => '設定データが不正です。'
            ], 400);
        }

        $data = survey_load();

        foreach (['kintone', 'smtp'] as $section) {
            if (!isset($settings[$section]) || !is_array($settings[$section])) {
                continue;
            }

            foreach ($settings[$section] as $key => $value) {
                if (
                    $key === 'password' &&
                    $value === '' &&
                    !empty($data['settings'][$section]['password'])
                ) {
                    continue;
                }

                $data['settings'][$section][$key] = $value;
            }
        }

        survey_save($data);

        survey_json([
            'ok' => true,
            'settings' => survey_public_settings($data['settings']),
        ]);
    }

    if ($action === 'fetch_kintone_fields') {
        survey_require_post();
        survey_verify_csrf($input);

        $data = survey_load();
        $cfg = kintone_config($data);

        $appId = (string)($input['app_id'] ?? $cfg['app_id'] ?? '');
        $domain = (string)($cfg['subdomain'] ?? '');
        $login = (string)($cfg['login_name'] ?? '');
        $password = (string)($cfg['password'] ?? '');

        if ($appId === '' || $domain === '' || $login === '' || $password === '') {
            survey_json([
                'ok' => false,
                'message' => 'kintone接続設定とアプリIDを入力してください。'
            ], 400);
        }

        $url = kintone_build_url(
            $domain,
            '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
        );

        $result = kintone_api_request(
            'GET',
            $url,
            [
                make_cybozu_auth_header($login, $password),
                'Accept: application/json',
            ],
            null,
            [
                'proxy' => $cfg['proxy'] ?? '',
                'ssl_verify' => !empty($cfg['ssl_verify']),
            ]
        );

        if (!$result['success']) {
            survey_json([
                'ok' => false,
                'message' => $result['message'],
                'status' => $result['status'],
            ], 502);
        }

        survey_json([
            'ok' => true,
            'fields' => $result['data']['properties'] ?? [],
        ]);
    }

    if ($action === 'kintone_connection_test') {
        survey_require_post();
        survey_verify_csrf($input);

        $data = survey_load();
        $cfg = kintone_config($data);

        $domain = (string)($cfg['subdomain'] ?? '');
        $login = (string)($cfg['login_name'] ?? '');
        $password = (string)($cfg['password'] ?? '');

        if ($domain === '' || $login === '' || $password === '') {
            survey_json([
                'ok' => false,
                'message' => 'kintone設定が未入力です。'
            ], 400);
        }

        $url = kintone_build_url($domain, '/k/v1/users.json?ids[0]=1');

        $result = kintone_api_request(
            'GET',
            $url,
            [
                make_cybozu_auth_header($login, $password),
                'Accept: application/json',
            ],
            null,
            [
                'proxy' => $cfg['proxy'] ?? '',
                'ssl_verify' => !empty($cfg['ssl_verify']),
            ]
        );

        survey_json([
            'ok' => $result['success'],
            'status' => $result['status'],
            'message' => $result['success']
                ? 'kintoneへの接続に成功しました。'
                : $result['message'],
        ], $result['success'] ? 200 : 502);
    }

    if ($action === 'sync_customers') {
        survey_require_post();
        survey_verify_csrf($input);

        $data = survey_load();
        $cfg = kintone_config($data);

        $appId = (string)($cfg['app_id'] ?? '');
        $domain = (string)($cfg['subdomain'] ?? '');
        $login = (string)($cfg['login_name'] ?? '');
        $password = (string)($cfg['password'] ?? '');

        if ($appId === '' || $domain === '' || $login === '' || $password === '') {
            survey_json([
                'ok' => false,
                'message' => 'kintone設定が未完了です。'
            ], 400);
        }

        $url = kintone_build_url($domain, '/k/v1/records.json');

        $result = kintone_api_request(
            'GET',
            $url . '?app=' . rawurlencode($appId) . '&totalCount=true',
            [
                make_cybozu_auth_header($login, $password),
                'Accept: application/json',
            ],
            null,
            [
                'proxy' => $cfg['proxy'] ?? '',
                'ssl_verify' => !empty($cfg['ssl_verify']),
            ]
        );

        if (!$result['success']) {
            survey_json([
                'ok' => false,
                'message' => $result['message'],
                'status' => $result['status'],
            ], 502);
        }

        $records = $result['data']['records'] ?? [];
        $customers = [];

        foreach ($records as $record) {
            $get = static function (string $code) use ($record): string {
                return trim((string)($record[$code]['value'] ?? ''));
            };

            $email = $get((string)($cfg['field_email'] ?? ''));

            if ($email === '') {
                continue;
            }

            $addressCodes = $cfg['field_address'] ?? [];
            if (!is_array($addressCodes)) {
                $addressCodes = [];
            }

            $address = [];

            foreach ($addressCodes as $code) {
                $v = $get((string)$code);
                if ($v !== '') {
                    $address[] = $v;
                }
            }

            $existing = null;

            foreach ($data['customers'] as $customer) {
                if (strcasecmp((string)$customer['email'], $email) === 0) {
                    $existing = $customer;
                    break;
                }
            }

            $customers[] = [
                'id' => $existing['id'] ?? survey_id('customer_'),
                'company' => $get((string)($cfg['field_company'] ?? '')),
                'name' => $get((string)($cfg['field_name'] ?? '')),
                'email' => $email,
                'department' => $get((string)($cfg['field_department'] ?? '')),
                'phone' => $get((string)($cfg['field_phone'] ?? '')),
                'address' => implode(' ', $address),
                'source' => 'kintone',
                'sent_at' => $existing['sent_at'] ?? '',
                'send_count' => (int)($existing['send_count'] ?? 0),
                'answer_status' => $existing['answer_status'] ?? 'unanswered',
                'kintone_status' => 'registered',
            ];
        }

        $data['customers'] = $customers;
        survey_save($data);

        survey_json([
            'ok' => true,
            'count' => count($customers),
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'save_response') {
        survey_require_post();
        survey_verify_csrf($input);

        $surveyId = (string)($input['survey_id'] ?? '');
        $answers = $input['answers'] ?? [];

        if (!is_array($answers)) {
            survey_json([
                'ok' => false,
                'message' => '回答データが不正です。'
            ], 400);
        }

        $data = survey_load();
        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if (!$survey) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $response = [
            'id' => survey_id('response_'),
            'survey_id' => $surveyId,
            'customer_id' => (string)($input['customer_id'] ?? ''),
            'company' => trim((string)($input['company'] ?? '')),
            'name' => trim((string)($input['name'] ?? '')),
            'email' => trim((string)($input['email'] ?? '')),
            'answered_at' => survey_now(),
            'answers' => $answers,
        ];

        $data['responses'][] = $response;

        foreach ($data['customers'] as &$customer) {
            if (
                $response['customer_id'] !== '' &&
                $customer['id'] === $response['customer_id']
            ) {
                $customer['answer_status'] = 'answered';
            } elseif (
                $response['email'] !== '' &&
                strcasecmp((string)$customer['email'], $response['email']) === 0
            ) {
                $customer['answer_status'] = 'answered';
            }
        }

        unset($customer);

        survey_save($data);

        survey_json([
            'ok' => true,
            'response_id' => $response['id'],
        ]);
    }

    if ($action === 'send_mail') {
        survey_require_post();
        survey_verify_csrf($input);

        $data = survey_load();
        $cfg = $data['settings']['smtp'] ?? [];

        if (
            empty($cfg['host']) ||
            empty($cfg['from_email'])
        ) {
            survey_json([
                'ok' => false,
                'message' => 'SMTP設定が未完了です。'
            ], 400);
        }

        $ids = $input['recipient_ids'] ?? [];

        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }

        if (!is_array($ids)) {
            $ids = [];
        }

        $subject = (string)($input['mail_subject'] ?? '');
        $body = (string)($input['mail_body'] ?? '');
        $templateType = (string)($input['template_type'] ?? 'initial');
        $surveyId = (string)($input['survey_id'] ?? '');

        $success = 0;
        $failed = 0;
        $results = [];

        foreach ($data['customers'] as &$customer) {
            if (!in_array($customer['id'], $ids, true)) {
                continue;
            }

            if ($customer['email'] === '') {
                $failed++;
                continue;
            }

            $personalUrl =
                current_api_url() .
                '?survey=' .
                rawurlencode($surveyId) .
                '&customer=' .
                rawurlencode($customer['id']);

            $mailSubject = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [$customer['name'], $personalUrl],
                $subject
            );

            $mailBody = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [$customer['name'], $personalUrl],
                $body
            );

            $result = smtp_send_mail(
                $cfg,
                $customer['email'],
                $mailSubject,
                $mailBody
            );

            $customer['last_send_result'] =
                $result['success'] ? 'success' : 'failed';

            $customer['last_send_error'] =
                $result['success'] ? '' : $result['message'];

            if ($result['success']) {
                $customer['sent_at'] = survey_now();
                $customer['send_count'] =
                    ((int)($customer['send_count'] ?? 0)) + 1;
                $customer['answer_status'] = 'unanswered';
                $success++;
            } else {
                $failed++;
            }

            $results[] = [
                'customer_id' => $customer['id'],
                'email' => $customer['email'],
                'success' => $result['success'],
                'message' => $result['message'],
            ];
        }

        unset($customer);

        $data['mail_logs'][] = [
            'id' => survey_id('mail_log_'),
            'survey_id' => $surveyId,
            'sent_at' => survey_now(),
            'type' => $templateType,
            'target_count' => count($ids),
            'success_count' => $success,
            'failed_count' => $failed,
            'subject' => $subject,
            'executed_by' => $_SESSION['survey_user'] ?? '',
            'results' => $results,
        ];

        survey_save($data);

        survey_json([
            'ok' => true,
            'success_count' => $success,
            'failed_count' => $failed,
            'data' => survey_public_data($data),
        ]);
    }

    if ($action === 'csv') {
        $surveyId = (string)($_GET['survey_id'] ?? '');

        $data = survey_load();
        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if (!$survey) {
            http_response_code(404);
            exit('survey not found');
        }

        $questions = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $q) {
                $questions[] = $q;
            }
        }

        $fh = fopen('php://output', 'w');

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="survey_' .
            rawurlencode($surveyId) .
            '.csv"'
        );

        fwrite($fh, "\xEF\xBB\xBF");

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
        ];

        foreach ($questions as $q) {
            $header[] = $q['number'] . ' ' . $q['text'];
        }

        fputcsv($fh, $header);

        foreach ($data['responses'] as $response) {
            if (($response['survey_id'] ?? '') !== $surveyId) {
                continue;
            }

            $row = [
                $response['id'],
                $response['answered_at'],
                $response['customer_id'],
                $response['company'],
                $response['name'],
            ];

            foreach ($questions as $q) {
                $value = $response['answers'][$q['id']] ?? '';

                if (is_array($value)) {
                    $labels = [];

                    foreach ($q['options'] as $option) {
                        if (in_array($option['id'], $value, true)) {
                            $labels[] = $option['label'];
                        }
                    }

                    $value = implode(' / ', $labels);
                } else {
                    foreach ($q['options'] as $option) {
                        if ((string)$option['id'] === (string)$value) {
                            $value = $option['label'];
                            break;
                        }
                    }
                }

                $row[] = $value;
            }

            fputcsv($fh, $row);
        }

        fclose($fh);
        exit;
    }

    survey_json([
        'ok' => false,
        'message' => '未知のAPIアクションです。'
    ], 404);
}

/* =========================================================
 * API実行
 * ========================================================= */

if (isset($_GET['action']) || isset($_POST['action'])) {
    try {
        survey_api();
    } catch (Throwable $e) {
        survey_json([
            'ok' => false,
            'message' => 'サーバー処理中にエラーが発生しました。',
            'error_type' => 'server_error',
        ], 500);
    }
}

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート管理システム</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {

    State: {
        initialized: false,
        authenticated: false,
        csrfToken: '',
        screen: 'loading',
        surveyId: '',
        responseSurveyId: '',
        editingSurvey: null,
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        responseFilter: '',
        customerFilter: '',
        selectedCustomers: [],
        selectedQuestions: {},
        modal: null,
        error: ''
    },

    api: {},

    actions: {},

    Render: {},

    utils: {},

    init: async function() {
        if (window.App.State.initialized) {
            return;
        }

        window.App.State.initialized = true;

        if (location.protocol === 'file:') {
            window.App.State.error =
                'このアプリはfile://では実行できません。Apache経由のURLで開いてください。';
            window.App.State.screen = 'error';
            window.App.Render.render();
            return;
        }

        window.App.State.screen = 'loading';
        window.App.Render.render();

        try {
            const result = await window.App.api.bootstrap();

            window.App.State.authenticated = !!result.authenticated;
            window.App.State.csrfToken = result.csrf_token || '';
            window.App.State.data = result.data || window.App.State.data;

            window.App.State.screen =
                window.App.State.authenticated ? 'surveys' : 'login';

            window.App.Render.render();

        } catch (e) {
            console.error(e);
            window.App.State.error =
                e.message || '初期化に失敗しました。';
            window.App.State.screen = 'error';
            window.App.Render.render();
        }
    },

    apiCall: async function(action, options) {
        options = options || {};

        const method = options.method || 'GET';
        const body = options.body || null;

        let url = location.pathname + '?action=' +
            encodeURIComponent(action);

        const fetchOptions = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        };

        if (method !== 'GET') {
            fetchOptions.headers['Content-Type'] =
                'application/json; charset=UTF-8';

            fetchOptions.body = JSON.stringify(
                Object.assign(
                    {},
                    body || {},
                    {
                        csrf_token: window.App.State.csrfToken
                    }
                )
            );
        }

        const response = await fetch(url, fetchOptions);

        let data;

        try {
            data = await response.json();
        } catch (e) {
            throw new Error(
                'サーバーからJSON形式の応答を取得できませんでした。HTTP ' +
                response.status
            );
        }

        if (!response.ok || !data.ok) {
            const error = new Error(
                data.message || ('HTTP ' + response.status)
            );
            error.status = response.status;
            throw error;
        }

        return data;
    },

    api: {
        bootstrap: async function() {
            /*
             * 修正点:
             * 旧コードの action=bootstrap にPHP側が未対応だった問題を解消。
             */
            return window.App.apiCall('bootstrap', {
                method: 'GET'
            });
        },

        login: async function(user, password) {
            const result = await window.App.apiCall('login', {
                method: 'POST',
                body: {
                    login_name: user,
                    password: password
                }
            });

            return result;
        },

        logout: async function() {
            return window.App.apiCall('logout', {
                method: 'POST',
                body: {}
            });
        },

        saveSurvey: async function(survey) {
            return window.App.apiCall('save_survey', {
                method: 'POST',
                body: {
                    survey_json: survey
                }
            });
        },

        deleteSurvey: async function(id) {
            return window.App.apiCall('delete_survey', {
                method: 'POST',
                body: {
                    survey_id: id
                }
            });
        },

        setStatus: async function(id, status) {
            return window.App.apiCall('set_status', {
                method: 'POST',
                body: {
                    survey_id: id,
                    status: status
                }
            });
        },

        duplicateSurvey: async function(id) {
            return window.App.apiCall('duplicate_survey', {
                method: 'POST',
                body: {
                    survey_id: id
                }
            });
        },

        saveSettings: async function(settings) {
            return window.App.apiCall('save_settings', {
                method: 'POST',
                body: {
                    settings_json: settings
                }
            });
        },

        fetchKintoneFields: async function(appId) {
            return window.App.apiCall('fetch_kintone_fields', {
                method: 'POST',
                body: {
                    app_id: appId
                }
            });
        },

        testKintone: async function() {
            return window.App.apiCall('kintone_connection_test', {
                method: 'POST',
                body: {}
            });
        },

        syncCustomers: async function() {
            return window.App.apiCall('sync_customers', {
                method: 'POST',
                body: {}
            });
        },

        sendMail: async function(payload) {
            return window.App.apiCall('send_mail', {
                method: 'POST',
                body: payload
            });
        }
    },

    utils: {
        esc: function(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        },

        clone: function(value) {
            return JSON.parse(JSON.stringify(value));
        },

        findSurvey: function(id) {
            return window.App.State.data.surveys.find(
                s => s.id === id && !s.deleted
            ) || null;
        },

        allQuestions: function(survey) {
            const result = [];

            (survey.groups || []).forEach(group => {
                (group.questions || []).forEach(question => {
                    result.push(question);
                });
            });

            return result;
        },

        renumber: function(survey) {
            let n = 0;

            (survey.groups || []).forEach((group, gi) => {
                (group.questions || []).forEach((question, qi) => {
                    n++;

                    question.number =
                        survey.numbering_mode === 'group'
                            ? 'Q' + (gi + 1) + '-' + (qi + 1)
                            : 'Q' + n;
                });
            });

            return survey;
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
                draft: 'bg-slate-200 text-slate-700',
                ended: 'bg-rose-100 text-rose-700'
            }[status] || 'bg-slate-100 text-slate-700';
        },

        date: function(value) {
            if (!value) return '未設定';

            const d = new Date(value);

            if (isNaN(d.getTime())) {
                return value;
            }

            return d.toLocaleString('ja-JP', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        responseCount: function(id) {
            return window.App.State.data.responses.filter(
                r => r.survey_id === id
            ).length;
        },

        surveyUrl: function(id, customerId) {
            let url = location.href.split('?')[0];

            url += '?survey=' + encodeURIComponent(id);

            if (customerId) {
                url += '&customer=' + encodeURIComponent(customerId);
            }

            return url;
        }
    },

    actions: {

        login: async function() {
            const user =
                document.getElementById('login_user').value;

            const pass =
                document.getElementById('login_password').value;

            const message =
                document.getElementById('login_message');

            message.textContent = '';

            try {
                const result =
                    await window.App.api.login(user, pass);

                window.App.State.authenticated = true;
                window.App.State.csrfToken =
                    result.csrf_token || '';

                window.App.State.data =
                    result.data || window.App.State.data;

                window.App.State.screen = 'surveys';

                window.App.Render.render();

            } catch (e) {
                message.textContent =
                    e.message || 'ログインに失敗しました。';
            }
        },

        logout: async function() {
            if (!confirm('ログアウトしますか？')) return;

            await window.App.api.logout();

            location.reload();
        },

        go: function(screen, id) {
            window.App.State.screen = screen;
            window.App.State.surveyId = id || '';
            window.App.Render.render();
        },

        editSurvey: function(id) {
            const survey =
                window.App.utils.findSurvey(id);

            if (!survey) return;

            window.App.State.editingSurvey =
                window.App.utils.clone(survey);

            window.App.State.screen = 'editor';

            window.App.Render.render();
        },

        newSurvey: function() {
            window.App.State.editingSurvey = {
                id: 'survey_' +
                    Math.random().toString(36).slice(2) +
                    Date.now(),
                title: '新しいアンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                numbering_mode: 'global',
                groups: [{
                    id: 'group_' +
                        Math.random().toString(36).slice(2),
                    name: 'グループ1',
                    questions: []
                }],
                deleted: false
            };

            window.App.State.screen = 'editor';
            window.App.Render.render();
        },

        saveSurvey: async function() {
            const survey =
                window.App.State.editingSurvey;

            survey.title =
                document.getElementById('survey_title').value;

            survey.start_at =
                document.getElementById('survey_start_at').value;

            survey.end_at =
                document.getElementById('survey_end_at').value;

            survey.numbering_mode =
                document.getElementById(
                    'survey_numbering_mode'
                ).value;

            window.App.utils.renumber(survey);

            try {
                const result =
                    await window.App.api.saveSurvey(survey);

                window.App.State.data = result.data;

                alert('保存しました。');

                window.App.State.editingSurvey = null;
                window.App.State.screen = 'surveys';

                window.App.Render.render();

            } catch (e) {
                alert(e.message);
            }
        },

        cancelEditor: function() {
            if (!confirm('変更を破棄して一覧へ戻りますか？')) {
                return;
            }

            window.App.State.editingSurvey = null;
            window.App.State.screen = 'surveys';
            window.App.Render.render();
        },

        setStatus: async function(id, status) {
            const label =
                window.App.utils.statusLabel(status);

            if (!confirm(
                'アンケートを「' + label + '」に変更しますか？'
            )) {
                return;
            }

            try {
                const result =
                    await window.App.api.setStatus(id, status);

                window.App.State.data = result.data;
                window.App.Render.render();

            } catch (e) {
                alert(e.message);
            }
        },

        deleteSurvey: async function(id) {
            if (!confirm(
                'この下書きを削除しますか？'
            )) {
                return;
            }

            try {
                const result =
                    await window.App.api.deleteSurvey(id);

                window.App.State.data = result.data;
                window.App.Render.render();

            } catch (e) {
                alert(e.message);
            }
        },

        duplicateSurvey: async function(id) {
            try {
                const result =
                    await window.App.api.duplicateSurvey(id);

                window.App.State.data = result.data;

                alert('アンケートを複製しました。');

                window.App.Render.render();

            } catch (e) {
                alert(e.message);
            }
        },

        addGroup: function() {
            const survey =
                window.App.State.editingSurvey;

            survey.groups.push({
                id: 'group_' +
                    Math.random().toString(36).slice(2) +
                    Date.now(),
                name: '新しいグループ',
                questions: []
            });

            window.App.Render.renderEditor();
            window.App.actions.initDragDrop();
        },

        deleteGroup: function(gi) {
            const survey =
                window.App.State.editingSurvey;

            if (survey.groups.length <= 1) {
                alert('最後のグループは削除できません。');
                return;
            }

            if (!confirm(
                'このグループと質問をすべて削除しますか？'
            )) {
                return;
            }

            survey.groups.splice(gi, 1);

            window.App.utils.renumber(survey);

            window.App.Render.renderEditor();
            window.App.actions.initDragDrop();
        },

        addQuestion: function(gi) {
            const survey =
                window.App.State.editingSurvey;

            survey.groups[gi].questions.push({
                id: 'question_' +
                    Math.random().toString(36).slice(2) +
                    Date.now(),
                text: '',
                type: 'text',
                required: false,
                options: [],
                other_enabled: false
            });

            window.App.utils.renumber(survey);

            window.App.Render.renderEditor();
            window.App.actions.initDragDrop();
        },

        deleteQuestion: function(gi, qi) {
            const survey =
                window.App.State.editingSurvey;

            survey.groups[gi].questions.splice(qi, 1);

            window.App.utils.renumber(survey);

            window.App.Render.renderEditor();
            window.App.actions.initDragDrop();
        },

        updateQuestion: function(gi, qi, key, value) {
            const survey =
                window.App.State.editingSurvey;

            survey.groups[gi].questions[qi][key] = value;

            if (key === 'type' && value === 'text') {
                survey.groups[gi].questions[qi].options = [];
            }

            window.App.utils.renumber(survey);
        },

        addOption: function(gi, qi) {
            const q =
                window.App.State.editingSurvey
                    .groups[gi].questions[qi];

            q.options.push({
                id: 'option_' +
                    Math.random().toString(36).slice(2) +
                    Date.now(),
                label: '選択肢',
                branch_to: ''
            });

            window.App.Render.renderEditor();
            window.App.actions.initDragDrop();
        },

        deleteOption: function(gi, qi, oi) {
            const q =
                window.App.State.editingSurvey
                    .groups[gi].questions[qi];

            q.options.splice(oi, 1);

            window.App.Render.renderEditor();
            window.App.actions.initDragDrop();
        },

        preview: function() {
            window.App.State.modal = 'preview';
            window.App.Render.render();
        },

        closeModal: function() {
            window.App.State.modal = null;
            window.App.Render.render();
        },

        previewSubmit: function() {
            alert(
                'これはプレビューです。実際の回答は送信されません。'
            );
        },

        toggleStatusFilter: function(value) {
            window.App.State.statusFilter = value;
            window.App.Render.render();
        },

        search: function(value) {
            window.App.State.keyword = value;
            window.App.Render.render();
        },

        sort: function(value) {
            window.App.State.sort = value;
            window.App.Render.render();
        },

        showAnalytics: function(id) {
            window.App.State.surveyId = id;
            window.App.State.screen = 'analytics';
            window.App.Render.render();
        },

        showMail: function(id) {
            window.App.State.surveyId = id;
            window.App.State.screen = 'mail';
            window.App.State.selectedCustomers = [];
            window.App.Render.render();
        },

        showResponse: function(id) {
            window.App.State.modal = 'response';
            window.App.State.responseId = id;
            window.App.Render.render();
        },

        toggleCustomer: function(id, checked) {
            const list =
                window.App.State.selectedCustomers;

            if (checked) {
                if (!list.includes(id)) {
                    list.push(id);
                }
            } else {
                const i = list.indexOf(id);
                if (i >= 0) list.splice(i, 1);
            }
        },

        selectAllCustomers: function(checked) {
            const surveyId =
                window.App.State.surveyId;

            const customers =
                window.App.State.data.customers;

            window.App.State.selectedCustomers =
                checked
                    ? customers
                        .filter(c => c.source !== 'web')
                        .map(c => c.id)
                    : [];

            window.App.Render.render();
        },

        sendMail: async function() {
            const ids =
                window.App.State.selectedCustomers;

            if (!ids.length) {
                alert('送信対象を選択してください。');
                return;
            }

            const already =
                window.App.State.data.customers.filter(
                    c =>
                        ids.includes(c.id) &&
                        Number(c.send_count || 0) > 0
                );

            let type =
                document.getElementById('template_type').value;

            if (already.length) {
                if (!confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )) {
                    return;
                }

                type = 'reminder';
            }

            const subject =
                document.getElementById('mail_subject').value;

            const body =
                document.getElementById('mail_body').value;

            if (!subject || !body) {
                alert('件名と本文を入力してください。');
                return;
            }

            if (!confirm(
                ids.length + '件にメールを送信しますか？'
            )) {
                return;
            }

            try {
                const result =
                    await window.App.api.sendMail({
                        survey_id:
                            window.App.State.surveyId,
                        recipient_ids: ids,
                        mail_subject: subject,
                        mail_body: body,
                        template_type: type
                    });

                window.App.State.data = result.data;

                alert(
                    '送信完了\n成功: ' +
                    result.success_count +
                    '件\n失敗: ' +
                    result.failed_count +
                    '件'
                );

                window.App.Render.render();

            } catch (e) {
                alert(e.message);
            }
        },

        fetchKintoneFields: async function() {
            const appId =
                document.getElementById('setting_app_id').value;

            const message =
                document.getElementById('field_message');

            message.textContent = '取得中...';

            try {
                const result =
                    await window.App.api.fetchKintoneFields(appId);

                window.App.State.kintoneFields =
                    result.fields || {};

                message.textContent =
                    'フィールドを ' +
                    Object.keys(window.App.State.kintoneFields).length +
                    '件取得しました。';

                window.App.Render.renderSettings();

            } catch (e) {
                message.textContent = e.message;
            }
        },

        testKintone: async function() {
            const box =
                document.getElementById('field_message');

            box.textContent = '接続確認中...';

            try {
                const result =
                    await window.App.api.testKintone();

                box.textContent =
                    '成功: ' + result.message;

            } catch (e) {
                box.textContent =
                    '失敗: ' + e.message;
            }
        },

        syncCustomers: async function() {
            if (!confirm(
                'kintoneから顧客データを同期しますか？'
            )) {
                return;
            }

            try {
                const result =
                    await window.App.api.syncCustomers();

                window.App.State.data = result.data;

                alert(
                    result.count +
                    '件の顧客データを同期しました。'
                );

                window.App.Render.render();

            } catch (e) {
                alert(e.message);
            }
        },

        saveSettings: async function() {
            const current =
                window.App.State.data.settings || {};

            const k =
                current.kintone || {};

            const s =
                current.smtp || {};

            const settings = {
                kintone: Object.assign({}, k, {
                    subdomain:
                        document.getElementById(
                            'setting_subdomain'
                        ).value,

                    app_id:
                        document.getElementById(
                            'setting_app_id'
                        ).value,

                    login_name:
                        document.getElementById(
                            'setting_login_name'
                        ).value,

                    password:
                        document.getElementById(
                            'setting_password'
                        ).value,

                    proxy:
                        document.getElementById(
                            'setting_proxy'
                        ).value,

                    ssl_verify:
                        document.getElementById(
                            'setting_ssl_verify'
                        ).checked
                }),

                smtp: Object.assign({}, s, {
                    host:
                        document.getElementById(
                            'smtp_host'
                        ).value,

                    port:
                        Number(document.getElementById(
                            'smtp_port'
                        ).value),

                    encryption:
                        document.getElementById(
                            'smtp_encryption'
                        ).value,

                    auth:
                        document.getElementById(
                            'smtp_auth'
                        ).checked,

                    username:
                        document.getElementById(
                            'smtp_username'
                        ).value,

                    password:
                        document.getElementById(
                            'smtp_password'
                        ).value,

                    from_email:
                        document.getElementById(
                            'smtp_from_email'
                        ).value,

                    from_name:
                        document.getElementById(
                            'smtp_from_name'
                        ).value,

                    timeout:
                        Number(document.getElementById(
                            'smtp_timeout'
                        ).value)
                })
            };

            try {
                const result =
                    await window.App.api.saveSettings(settings);

                window.App.State.data.settings =
                    Object.assign(
                        {},
                        window.App.State.data.settings,
                        result.settings
                    );

                alert('設定を保存しました。');

            } catch (e) {
                alert(e.message);
            }
        },

        initDragDrop: function() {
            const editor =
                document.getElementById('question_editor');

            if (!editor) return;

            /*
             * SortableJSを使用しない。
             * EdgeのTracking PreventionやCDN障害の影響を受けない
             * HTML5 Drag & Drop実装。
             */

            editor.querySelectorAll('[data-group-index]')
                .forEach(el => {

                    el.draggable = true;

                    el.addEventListener('dragstart', function(e) {
                        e.dataTransfer.setData(
                            'text/plain',
                            JSON.stringify({
                                type: 'group',
                                index: Number(
                                    this.dataset.groupIndex
                                )
                            })
                        );
                    });

                    el.addEventListener('dragover', function(e) {
                        e.preventDefault();
                    });

                    el.addEventListener('drop', function(e) {
                        e.preventDefault();

                        const data =
                            JSON.parse(
                                e.dataTransfer.getData(
                                    'text/plain'
                                )
                            );

                        if (data.type !== 'group') return;

                        const survey =
                            window.App.State.editingSurvey;

                        const moved =
                            survey.groups.splice(
                                data.index,
                                1
                            )[0];

                        survey.groups.splice(
                            Number(this.dataset.groupIndex),
                            0,
                            moved
                        );

                        window.App.utils.renumber(survey);

                        window.App.Render.renderEditor();
                        window.App.actions.initDragDrop();
                    });
                });

            editor.querySelectorAll('[data-question-index]')
                .forEach(el => {

                    el.draggable = true;

                    el.addEventListener('dragstart', function(e) {
                        e.stopPropagation();

                        e.dataTransfer.setData(
                            'text/plain',
                            JSON.stringify({
                                type: 'question',
                                group: Number(
                                    this.dataset.group
                                ),
                                question: Number(
                                    this.dataset.questionIndex
                                )
                            })
                        );
                    });

                    el.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                    });

                    el.addEventListener('drop', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        const data =
                            JSON.parse(
                                e.dataTransfer.getData(
                                    'text/plain'
                                )
                            );

                        if (data.type !== 'question') return;

                        const survey =
                            window.App.State.editingSurvey;

                        const sourceGroup =
                            survey.groups[data.group];

                        const targetGroup =
                            survey.groups[
                                Number(this.dataset.group)
                            ];

                        const moved =
                            sourceGroup.questions.splice(
                                data.question,
                                1
                            )[0];

                        targetGroup.questions.splice(
                            Number(this.dataset.questionIndex),
                            0,
                            moved
                        );

                        window.App.utils.renumber(survey);

                        window.App.Render.renderEditor();
                        window.App.actions.initDragDrop();
                    });
                });
        }
    },

    Render: {

        render: function() {
            const app =
                document.getElementById('app');

            if (!app) return;

            if (window.App.State.screen === 'loading') {
                app.innerHTML =
                    '<div class="min-h-screen flex items-center justify-center">' +
                    '<div class="text-slate-500">読み込み中...</div>' +
                    '</div>';
                return;
            }

            if (window.App.State.screen === 'error') {
                app.innerHTML =
                    '<div class="min-h-screen flex items-center justify-center p-8">' +
                    '<div class="max-w-xl bg-white rounded-2xl shadow p-8">' +
                    '<h1 class="text-xl font-bold mb-4">起動できません</h1>' +
                    '<p class="text-rose-600 whitespace-pre-wrap">' +
                    window.App.utils.esc(
                        window.App.State.error
                    ) +
                    '</p></div></div>';
                return;
            }

            if (!window.App.State.authenticated) {
                this.renderLogin();
                return;
            }

            this.renderShell();
        },

        renderLogin: function() {
            document.getElementById('app').innerHTML = `
                <div class="min-h-screen flex items-center justify-center p-6">
                    <div class="w-full max-w-md bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
                        <div class="mb-8">
                            <div class="text-sm text-indigo-600 font-semibold mb-2">
                                SURVEY MANAGEMENT
                            </div>
                            <h1 class="text-2xl font-bold text-slate-800">
                                アンケート管理システム
                            </h1>
                        </div>

                        <div class="space-y-5">
                            <div>
                                <label class="block text-sm font-medium mb-2">
                                    ログイン名
                                </label>
                                <input id="login_user"
                                    class="w-full rounded-lg border border-slate-300 px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-200"
                                    autocomplete="username">
                            </div>

                            <div>
                                <label class="block text-sm font-medium mb-2">
                                    パスワード
                                </label>
                                <input id="login_password"
                                    type="password"
                                    class="w-full rounded-lg border border-slate-300 px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-200"
                                    autocomplete="current-password"
                                    onkeydown="if(event.key==='Enter')App.actions.login()">
                            </div>

                            <div id="login_message"
                                class="text-sm text-rose-600 min-h-5"></div>

                            <button
                                onclick="App.actions.login()"
                                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg py-3 font-semibold">
                                ログイン
                            </button>
                        </div>
                    </div>
                </div>
            `;
        },

        renderShell: function() {
            const content =
                this.renderCurrent();

            document.getElementById('app').innerHTML = `
                <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
                    <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
                        <div class="font-bold text-slate-800">
                            アンケート管理システム
                        </div>

                        <nav class="flex items-center gap-2">
                            <button
                                onclick="App.actions.go('surveys')"
                                class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100">
                                アンケート一覧
                            </button>

                            <button
                                onclick="App.actions.go('settings')"
                                class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100">
                                kintone・メール設定
                            </button>

                            <button
                                onclick="App.actions.logout()"
                                class="px-3 py-2 rounded-lg text-sm text-slate-500 hover:bg-slate-100">
                                ログアウト
                            </button>
                        </nav>
                    </div>
                </header>

                <main class="max-w-7xl mx-auto px-6 py-8">
                    ${content}
                </main>

                ${this.renderModal()}
            `;
        },

        renderCurrent: function() {
            switch (window.App.State.screen) {
                case 'editor':
                    return this.renderEditor();

                case 'analytics':
                    return this.renderAnalytics();

                case 'mail':
                    return this.renderMail();

                case 'settings':
                    return this.renderSettings();

                default:
                    return this.renderSurveys();
            }
        },

        renderSurveys: function() {
            let surveys =
                window.App.State.data.surveys
                    .filter(s => !s.deleted);

            const keyword =
                window.App.State.keyword.toLowerCase();

            if (keyword) {
                surveys = surveys.filter(
                    s => s.title.toLowerCase().includes(keyword)
                );
            }

            if (window.App.State.statusFilter !== 'all') {
                surveys = surveys.filter(
                    s =>
                        s.status ===
                        window.App.State.statusFilter
                );
            }

            surveys.sort((a, b) => {
                const mode = window.App.State.sort;

                if (mode === 'answers_desc') {
                    return window.App.utils.responseCount(b.id) -
                        window.App.utils.responseCount(a.id);
                }

                if (mode === 'answers_asc') {
                    return window.App.utils.responseCount(a.id) -
                        window.App.utils.responseCount(b.id);
                }

                if (mode === 'updated_asc') {
                    return String(a.updated_at)
                        .localeCompare(String(b.updated_at));
                }

                return String(b.updated_at)
                    .localeCompare(String(a.updated_at));
            });

            return `
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <div class="text-sm text-indigo-600 font-semibold">
                            HOME
                        </div>
                        <h1 class="text-2xl font-bold">
                            アンケート一覧
                        </h1>
                    </div>

                    <button
                        onclick="App.actions.newSurvey()"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-lg font-semibold">
                        ＋ 新規アンケート作成
                    </button>
                </div>

                <div class="bg-white border border-slate-200 rounded-xl p-4 mb-5">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <input
                            value="${window.App.utils.esc(
                                window.App.State.keyword
                            )}"
                            onkeydown="if(event.key==='Enter')App.actions.search(this.value)"
                            placeholder="タイトルを検索"
                            class="border border-slate-300 rounded-lg px-3 py-2">

                        <select
                            onchange="App.actions.toggleStatusFilter(this.value)"
                            class="border border-slate-300 rounded-lg px-3 py-2">
                            <option value="all">すべて</option>
                            <option value="active">公開中</option>
                            <option value="draft">下書き</option>
                            <option value="ended">終了</option>
                        </select>

                        <select
                            onchange="App.actions.sort(this.value)"
                            class="border border-slate-300 rounded-lg px-3 py-2">
                            <option value="updated_desc">更新日：新しい順</option>
                            <option value="updated_asc">更新日：古い順</option>
                            <option value="answers_desc">回答数：多い順</option>
                            <option value="answers_asc">回答数：少ない順</option>
                        </select>
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th class="text-left p-4">作成日 / 更新日</th>
                                    <th class="text-left p-4">タイトル</th>
                                    <th class="text-left p-4">期間</th>
                                    <th class="text-left p-4">ステータス</th>
                                    <th class="text-left p-4">回答数</th>
                                    <th class="text-right p-4">操作</th>
                                </tr>
                            </thead>

                            <tbody>
                                ${
                                    surveys.length
                                    ? surveys.map(s =>
                                        this.renderSurveyRow(s)
                                    ).join('')
                                    : `
                                    <tr>
                                        <td colspan="6"
                                            class="p-10 text-center text-slate-400">
                                            アンケートがありません。
                                        </td>
                                    </tr>`
                                }
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        },

        renderSurveyRow: function(s) {
            const answerCount =
                window.App.utils.responseCount(s.id);

            let buttons = '';

            if (s.status === 'active') {
                buttons = `
                    <button onclick="App.actions.editSurvey('${s.id}')"
                        class="text-indigo-600 hover:underline">確認・編集</button>
                    <button onclick="App.actions.showAnalytics('${s.id}')"
                        class="text-indigo-600 hover:underline">集計</button>
                    <button onclick="App.actions.showMail('${s.id}')"
                        class="text-indigo-600 hover:underline">送信</button>
                    <button onclick="App.actions.setStatus('${s.id}','ended')"
                        class="text-rose-600 hover:underline">停止</button>
                    <button onclick="App.actions.duplicateSurvey('${s.id}')"
                        class="text-slate-600 hover:underline">複製</button>
                `;
            } else if (s.status === 'draft') {
                buttons = `
                    <button onclick="App.actions.editSurvey('${s.id}')"
                        class="text-indigo-600 hover:underline">確認・編集</button>
                    <button onclick="App.actions.deleteSurvey('${s.id}')"
                        class="text-rose-600 hover:underline">削除</button>
                    <button onclick="App.actions.duplicateSurvey('${s.id}')"
                        class="text-slate-600 hover:underline">複製</button>
                `;
            } else {
                buttons = `
                    <button onclick="App.actions.editSurvey('${s.id}')"
                        class="text-indigo-600 hover:underline">確認・編集</button>
                    <button onclick="App.actions.showAnalytics('${s.id}')"
                        class="text-indigo-600 hover:underline">集計</button>
                    <button onclick="App.actions.duplicateSurvey('${s.id}')"
                        class="text-slate-600 hover:underline">複製</button>
                `;
            }

            return `
                <tr class="border-b border-slate-100 hover:bg-slate-50">
                    <td class="p-4 text-slate-500">
                        ${window.App.utils.date(s.created_at)}<br>
                        <span class="text-xs">
                            更新:
                            ${window.App.utils.date(s.updated_at)}
                        </span>
                    </td>

                    <td class="p-4 font-bold">
                        ${window.App.utils.esc(s.title)}
                    </td>

                    <td class="p-4">
                        ${
                            s.start_at || s.end_at
                                ? window.App.utils.date(s.start_at) +
                                  ' ～ ' +
                                  window.App.utils.date(s.end_at)
                                : '未設定'
                        }
                    </td>

                    <td class="p-4">
                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold
                            ${window.App.utils.statusClass(s.status)}">
                            ${window.App.utils.statusLabel(s.status)}
                        </span>
                    </td>

                    <td class="p-4 font-semibold">
                        ${answerCount} 件
                    </td>

                    <td class="p-4">
                        <div class="flex flex-wrap justify-end gap-3">
                            ${buttons}
                        </div>
                    </td>
                </tr>
            `;
        },

        renderEditor: function() {
            const survey =
                window.App.State.editingSurvey;

            if (!survey) {
                return '';
            }

            return `
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <div class="text-sm text-indigo-600 font-semibold">
                            ホーム ＞ アンケート一覧 ＞ 作成・編集
                        </div>

                        <h1 class="text-2xl font-bold">
                            アンケート作成・編集
                        </h1>
                    </div>

                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.preview()"
                            class="px-4 py-2 bg-white border border-slate-300 rounded-lg">
                            プレビュー
                        </button>

                        <button
                            onclick="App.actions.saveSurvey()"
                            class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                            保存して一覧へ戻る
                        </button>

                        <button
                            onclick="App.actions.cancelEditor()"
                            class="px-4 py-2 bg-slate-200 rounded-lg">
                            キャンセル
                        </button>
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="md:col-span-2">
                            <label class="text-sm font-medium">
                                タイトル
                            </label>
                            <input id="survey_title"
                                value="${window.App.utils.esc(survey.title)}"
                                class="w-full mt-1 border border-slate-300 rounded-lg px-3 py-2">
                        </div>

                        <div>
                            <label class="text-sm font-medium">
                                開始日時
                            </label>
                            <input id="survey_start_at"
                                type="datetime-local"
                                value="${window.App.utils.esc(survey.start_at)}"
                                class="w-full mt-1 border border-slate-300 rounded-lg px-3 py-2">
                        </div>

                        <div>
                            <label class="text-sm font-medium">
                                終了日時
                            </label>
                            <input id="survey_end_at"
                                type="datetime-local"
                                value="${window.App.utils.esc(survey.end_at)}"
                                class="w-full mt-1 border border-slate-300 rounded-lg px-3 py-2">
                        </div>

                        <div>
                            <label class="text-sm font-medium">
                                質問番号形式
                            </label>
                            <select id="survey_numbering_mode"
                                class="w-full mt-1 border border-slate-300 rounded-lg px-3 py-2">
                                <option value="global"
                                    ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                    Q1 / Q2 / Q3
                                </option>
                                <option value="group"
                                    ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                    Q1-1 / Q1-2
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="text-sm font-medium">
                                ステータス
                            </label>

                            <select
                                onchange="App.actions.setStatus('${survey.id}',this.value)"
                                class="w-full mt-1 border border-slate-300 rounded-lg px-3 py-2">
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
                </div>

                <div id="question_editor" class="space-y-5">
                    ${
                        survey.groups.map(
                            (group, gi) =>
                                this.renderGroup(group, gi)
                        ).join('')
                    }
                </div>

                <div class="mt-5">
                    <button
                        onclick="App.actions.addGroup()"
                        class="px-5 py-3 bg-white border-2 border-dashed border-slate-300 rounded-xl text-slate-600 hover:border-indigo-400 hover:text-indigo-600">
                        ＋ グループを追加
                    </button>
                </div>
            `;
        },

        renderGroup: function(group, gi) {
            return `
                <section
                    data-group-index="${gi}"
                    class="bg-white border border-slate-200 rounded-xl p-5">

                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3 flex-1">
                            <span class="cursor-move text-slate-400 text-xl">
                                ⠿
                            </span>

                            <input
                                value="${window.App.utils.esc(group.name)}"
                                onchange="App.State.editingSurvey.groups[${gi}].name=this.value"
                                class="text-lg font-bold border-0 border-b border-slate-200 focus:ring-0 px-1">
                        </div>

                        <button
                            onclick="App.actions.deleteGroup(${gi})"
                            class="text-rose-600 text-sm">
                            グループ削除
                        </button>
                    </div>

                    <div class="space-y-4">
                        ${
                            group.questions.map(
                                (q, qi) =>
                                    this.renderQuestion(q, gi, qi)
                            ).join('')
                        }
                    </div>

                    <button
                        onclick="App.actions.addQuestion(${gi})"
                        class="mt-5 px-4 py-2 bg-indigo-50 text-indigo-700 rounded-lg font-semibold">
                        ＋ 質問を追加
                    </button>
                </section>
            `;
        },

        renderQuestion: function(q, gi, qi) {
            const survey =
                window.App.State.editingSurvey;

            const questionOptions =
                window.App.utils.allQuestions(survey)
                    .filter(x => x.id !== q.id)
                    .map(x =>
                        `<option value="${x.id}">
                            ${window.App.utils.esc(x.number)}:
                            ${window.App.utils.esc(x.text)}
                        </option>`
                    ).join('');

            return `
                <div
                    data-question-index="${qi}"
                    data-group="${gi}"
                    class="border border-slate-200 rounded-xl p-4 bg-slate-50">

                    <div class="flex gap-3">
                        <span class="cursor-move text-slate-400 text-xl">
                            ⠿
                        </span>

                        <div class="flex-1">
                            <div class="flex items-center justify-between mb-3">
                                <div class="font-bold text-indigo-700">
                                    ${window.App.utils.esc(q.number || '')}
                                </div>

                                <button
                                    onclick="App.actions.deleteQuestion(${gi},${qi})"
                                    class="text-rose-600 text-sm">
                                    質問削除
                                </button>
                            </div>

                            <input
                                value="${window.App.utils.esc(q.text)}"
                                oninput="App.actions.updateQuestion(${gi},${qi},'text',this.value)"
                                placeholder="質問文を入力"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 mb-3">

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <select
                                    onchange="App.actions.updateQuestion(${gi},${qi},'type',this.value);App.Render.renderEditor();App.actions.initDragDrop()"
                                    class="border border-slate-300 rounded-lg px-3 py-2">
                                    <option value="text"
                                        ${q.type === 'text' ? 'selected' : ''}>
                                        自由記述
                                    </option>
                                    <option value="single"
                                        ${q.type === 'single' ? 'selected' : ''}>
                                        単一選択
                                    </option>
                                    <option value="multiple"
                                        ${q.type === 'multiple' ? 'selected' : ''}>
                                        複数選択
                                    </option>
                                </select>

                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        ${q.required ? 'checked' : ''}
                                        onchange="App.actions.updateQuestion(${gi},${qi},'required',this.checked)">
                                    必須回答
                                </label>

                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        ${q.other_enabled ? 'checked' : ''}
                                        onchange="App.actions.updateQuestion(${gi},${qi},'other_enabled',this.checked)">
                                    その他を許可
                                </label>
                            </div>

                            ${
                                q.type !== 'text'
                                ? `
                                <div class="mt-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-semibold text-sm">
                                            選択肢
                                        </span>

                                        <button
                                            onclick="App.actions.addOption(${gi},${qi})"
                                            class="text-indigo-600 text-sm">
                                            ＋追加
                                        </button>
                                    </div>

                                    <div class="space-y-2">
                                        ${
                                            q.options.map(
                                                (option, oi) =>
                                                    this.renderOption(
                                                        option,
                                                        gi,
                                                        qi,
                                                        oi,
                                                        questionOptions
                                                    )
                                            ).join('')
                                        }
                                    </div>
                                </div>
                                `
                                : ''
                            }
                        </div>
                    </div>
                </div>
            `;
        },

        renderOption: function(option, gi, qi, oi, questionOptions) {
            return `
                <div class="bg-white border border-slate-200 rounded-lg p-3">
                    <div class="flex gap-2">
                        <input
                            value="${window.App.utils.esc(option.label)}"
                            oninput="App.State.editingSurvey.groups[${gi}].questions[${qi}].options[${oi}].label=this.value"
                            class="flex-1 border border-slate-300 rounded-lg px-3 py-2">

                        <button
                            onclick="App.actions.deleteOption(${gi},${qi},${oi})"
                            class="text-rose-600 px-2">
                            削除
                        </button>
                    </div>

                    <div class="mt-2">
                        <label class="text-xs text-slate-500">
                            この選択肢を選んだ場合の分岐先
                        </label>

                        <select
                            onchange="App.State.editingSurvey.groups[${gi}].questions[${qi}].options[${oi}].branch_to=this.value"
                            class="w-full mt-1 border border-slate-300 rounded-lg px-3 py-2 text-sm">
                            <option value="">分岐なし</option>
                            ${questionOptions}
                        </select>
                    </div>
                </div>
            `;
        },

        renderAnalytics: function() {
            const survey =
                window.App.utils.findSurvey(
                    window.App.State.surveyId
                );

            if (!survey) {
                return '<p>アンケートが見つかりません。</p>';
            }

            const responses =
                window.App.State.data.responses.filter(
                    r => r.survey_id === survey.id
                );

            const questions =
                window.App.utils.allQuestions(survey);

            const answeredCustomers =
                new Set(
                    responses
                        .map(r => r.customer_id)
                        .filter(Boolean)
                );

            const targets =
                window.App.State.data.customers.filter(
                    c => c.source !== 'web'
                ).length;

            const rate =
                targets
                    ? (
                        answeredCustomers.size /
                        targets *
                        100
                    ).toFixed(1)
                    : '0.0';

            return `
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <div class="text-sm text-indigo-600">
                            ホーム ＞ アンケート一覧 ＞ 集計
                        </div>

                        <h1 class="text-2xl font-bold">
                            ${window.App.utils.esc(survey.title)}
                        </h1>
                    </div>

                    <a
                        href="${location.pathname}?action=csv&survey_id=${encodeURIComponent(survey.id)}"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                        CSV出力
                    </a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                    ${this.metricCard('送信対象者数', targets + ' 人')}
                    ${this.metricCard('回答数', responses.length + ' 件')}
                    ${this.metricCard(
                        '未登録顧客からの回答数',
                        responses.filter(r => !r.customer_id).length + ' 件'
                    )}
                    ${this.metricCard(
                        '未回答数',
                        Math.max(0, targets - answeredCustomers.size) + ' 人'
                    )}
                    ${this.metricCard('回答率', rate + ' %')}
                </div>

                <div class="space-y-5">
                    ${
                        questions.map(
                            q => this.renderQuestionSummary(
                                q,
                                responses
                            )
                        ).join('')
                    }
                </div>

                <div class="mt-6 bg-white border border-slate-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b font-bold">
                        個別回答一覧
                    </div>

                    <input
                        id="response_filter"
                        oninput="App.State.responseFilter=this.value;App.Render.render()"
                        placeholder="会社名・氏名で検索"
                        class="m-4 border border-slate-300 rounded-lg px-3 py-2 w-80">

                    <div id="response_table" class="overflow-x-auto">
                        ${this.renderResponseTable(responses)}
                    </div>
                </div>
            `;
        },

        metricCard: function(label, value) {
            return `
                <div class="bg-white border border-slate-200 rounded-xl p-5">
                    <div class="text-sm text-slate-500">
                        ${label}
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${value}
                    </div>
                </div>
            `;
        },

        renderQuestionSummary: function(q, responses) {
            const values =
                responses
                    .map(r => r.answers?.[q.id])
                    .filter(v =>
                        v !== undefined &&
                        v !== null &&
                        v !== ''
                    );

            if (q.type === 'text') {
                return `
                    <div class="bg-white border border-slate-200 rounded-xl p-5">
                        <div class="flex items-center justify-between">
                            <h2 class="font-bold">
                                ${window.App.utils.esc(q.number)}
                                ${window.App.utils.esc(q.text)}
                            </h2>

                            <span class="text-xs bg-slate-100 px-2 py-1 rounded">
                                自由記述
                            </span>
                        </div>

                        <div class="mt-4 space-y-3 max-h-72 overflow-auto">
                            ${
                                values.map(v =>
                                    `<div class="border-l-4 border-indigo-300 pl-3 py-2">
                                        ${window.App.utils.esc(
                                            Array.isArray(v)
                                                ? v.join(', ')
                                                : v
                                        )}
                                    </div>`
                                ).join('')
                            }
                        </div>
                    </div>
                `;
            }

            const counts = {};

            q.options.forEach(o => {
                counts[o.id] = 0;
            });

            values.forEach(v => {
                if (Array.isArray(v)) {
                    v.forEach(x => {
                        if (counts[x] !== undefined) {
                            counts[x]++;
                        }
                    });
                } else if (counts[v] !== undefined) {
                    counts[v]++;
                }
            });

            return `
                <div class="bg-white border border-slate-200 rounded-xl p-5">
                    <h2 class="font-bold mb-4">
                        ${window.App.utils.esc(q.number)}
                        ${window.App.utils.esc(q.text)}
                    </h2>

                    <div class="space-y-3">
                        ${
                            q.options.map(o => {
                                const count =
                                    counts[o.id] || 0;

                                const percent =
                                    values.length
                                        ? count / values.length * 100
                                        : 0;

                                return `
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span>
                                                ${window.App.utils.esc(o.label)}
                                            </span>
                                            <span>
                                                ${count}件
                                                (${percent.toFixed(1)}%)
                                            </span>
                                        </div>

                                        <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                            <div
                                                class="h-full bg-indigo-500 rounded-full"
                                                style="width:${percent}%">
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }).join('')
                        }
                    </div>
                </div>
            `;
        },

        renderResponseTable: function(responses) {
            const keyword =
                window.App.State.responseFilter
                    .toLowerCase();

            const rows =
                responses.filter(r =>
                    !keyword ||
                    String(r.company).toLowerCase().includes(keyword) ||
                    String(r.name).toLowerCase().includes(keyword)
                );

            return `
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="p-3 text-left">会社名</th>
                            <th class="p-3 text-left">氏名</th>
                            <th class="p-3 text-left">回答日時</th>
                            <th class="p-3 text-right">操作</th>
                        </tr>
                    </thead>

                    <tbody>
                        ${
                            rows.map(r => `
                                <tr class="border-t border-slate-100">
                                    <td class="p-3">
                                        ${window.App.utils.esc(r.company)}
                                    </td>

                                    <td class="p-3 font-semibold">
                                        ${window.App.utils.esc(r.name)}
                                    </td>

                                    <td class="p-3">
                                        ${window.App.utils.date(r.answered_at)}
                                    </td>

                                    <td class="p-3 text-right">
                                        <button
                                            onclick="App.actions.showResponse('${r.id}')"
                                            class="text-indigo-600">
                                            全回答を表示
                                        </button>
                                    </td>
                                </tr>
                            `).join('')
                        }
                    </tbody>
                </table>
            `;
        },

        renderMail: function() {
            const survey =
                window.App.utils.findSurvey(
                    window.App.State.surveyId
                );

            const customers =
                window.App.State.data.customers;

            const keyword =
                window.App.State.customerFilter
                    .toLowerCase();

            const filtered =
                customers.filter(c =>
                    !keyword ||
                    String(c.company).toLowerCase().includes(keyword) ||
                    String(c.name).toLowerCase().includes(keyword) ||
                    String(c.email).toLowerCase().includes(keyword)
                );

            return `
                <div class="mb-6">
                    <div class="text-sm text-indigo-600">
                        ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
                    </div>

                    <h1 class="text-2xl font-bold">
                        ${window.App.utils.esc(survey?.title || '')}
                    </h1>
                </div>

                <div class="bg-white border border-slate-200 rounded-xl p-5 mb-5">
                    <div class="grid md:grid-cols-3 gap-4">
                        <input
                            id="customer_filter"
                            oninput="App.State.customerFilter=this.value;App.Render.render()"
                            placeholder="顧客名・メールアドレス"
                            class="border border-slate-300 rounded-lg px-3 py-2">

                        <select
                            id="template_type"
                            class="border border-slate-300 rounded-lg px-3 py-2">
                            <option value="initial">初回送信用</option>
                            <option value="reminder">リマインド送信用</option>
                        </select>

                        <div class="text-sm text-slate-500 flex items-center">
                            選択:
                            ${window.App.State.selectedCustomers.length}件
                        </div>
                    </div>

                    <div class="mt-5">
                        <input
                            id="mail_subject"
                            value="アンケートご協力のお願い"
                            placeholder="件名"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 mb-3">

                        <textarea
                            id="mail_body"
                            rows="7"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2">{$顧客名} 様

アンケートへのご協力をお願いいたします。

回答URL:
{アンケートURL}</textarea>

                        <button
                            onclick="App.actions.sendMail()"
                            class="mt-3 px-5 py-3 bg-indigo-600 text-white rounded-lg font-semibold">
                            一括送信実行
                        </button>
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table id="customer_table" class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="p-3 text-left">
                                        <input
                                            id="select_all"
                                            type="checkbox"
                                            onchange="App.actions.selectAllCustomers(this.checked)">
                                    </th>
                                    <th class="p-3 text-left">会社名 / 氏名</th>
                                    <th class="p-3 text-left">メール</th>
                                    <th class="p-3 text-left">電話</th>
                                    <th class="p-3 text-left">送信状況</th>
                                    <th class="p-3 text-left">回答</th>
                                    <th class="p-3 text-left">kintone</th>
                                </tr>
                            </thead>

                            <tbody>
                                ${
                                    filtered.map(c => `
                                        <tr class="border-t border-slate-100">
                                            <td class="p-3">
                                                ${
                                                    c.source === 'web'
                                                    ? ''
                                                    : `
                                                    <input
                                                        type="checkbox"
                                                        ${window.App.State.selectedCustomers.includes(c.id) ? 'checked' : ''}
                                                        onchange="App.actions.toggleCustomer('${c.id}',this.checked)">
                                                    `
                                                }
                                            </td>

                                            <td class="p-3">
                                                <strong>
                                                    ${window.App.utils.esc(c.company)}
                                                </strong><br>
                                                ${window.App.utils.esc(c.name)}
                                            </td>

                                            <td class="p-3">
                                                ${window.App.utils.esc(c.email)}
                                            </td>

                                            <td class="p-3">
                                                ${window.App.utils.esc(c.phone)}
                                            </td>

                                            <td class="p-3">
                                                ${c.sent_at
                                                    ? window.App.utils.date(c.sent_at) +
                                                      '<br>送信 ' +
                                                      Number(c.send_count || 0) +
                                                      '回'
                                                    : '未送信'}
                                            </td>

                                            <td class="p-3">
                                                <span class="px-2 py-1 rounded bg-slate-100">
                                                    ${
                                                        c.answer_status === 'answered'
                                                        ? '回答済み'
                                                        : '送信済み（未回答）'
                                                    }
                                                </span>
                                            </td>

                                            <td class="p-3">
                                                ${
                                                    c.kintone_status === 'registered'
                                                    ? '✓ 登録完了'
                                                    : '未登録'
                                                }
                                            </td>
                                        </tr>
                                    `).join('')
                                }
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        },

        renderSettings: function() {
            const settings =
                window.App.State.data.settings || {};

            const k = settings.kintone || {};
            const s = settings.smtp || {};

            const fields =
                window.App.State.kintoneFields || {};

            const fieldOptions =
                Object.keys(fields).map(code => {
                    const field =
                        fields[code];

                    return `
                        <option value="${window.App.utils.esc(code)}">
                            ${window.App.utils.esc(
                                field.label || code
                            )}
                            (${window.App.utils.esc(code)})
                        </option>
                    `;
                }).join('');

            return `
                <div class="mb-6">
                    <div class="text-sm text-indigo-600">
                        ホーム ＞ システム設定 ＞ kintone・メール連携設定
                    </div>

                    <h1 class="text-2xl font-bold">
                        kintone・メール連携設定
                    </h1>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                    <section class="bg-white border border-slate-200 rounded-xl p-5">
                        <h2 class="text-lg font-bold mb-5">
                            kintone設定
                        </h2>

                        <div class="space-y-4">
                            <input
                                id="setting_subdomain"
                                value="${window.App.utils.esc(k.subdomain || '')}"
                                placeholder="xxxx または xxxx.cybozu.com"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <input
                                id="setting_app_id"
                                value="${window.App.utils.esc(k.app_id || '')}"
                                placeholder="顧客管理アプリID"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <input
                                id="setting_login_name"
                                value="${window.App.utils.esc(k.login_name || '')}"
                                placeholder="ログイン名"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <input
                                id="setting_password"
                                type="password"
                                placeholder="${k.password_configured ? '保存済み。変更する場合のみ入力' : 'パスワード'}"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <input
                                id="setting_proxy"
                                value="${window.App.utils.esc(k.proxy || '')}"
                                placeholder="proxy.example.local:8080"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <label class="flex gap-2">
                                <input
                                    id="setting_ssl_verify"
                                    type="checkbox"
                                    ${k.ssl_verify ? 'checked' : ''}>
                                SSL証明書を検証する
                            </label>

                            <div class="flex gap-2">
                                <button
                                    onclick="App.actions.fetchKintoneFields()"
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
                                    項目一覧を取得
                                </button>

                                <button
                                    onclick="App.actions.testKintone()"
                                    class="px-4 py-2 bg-slate-200 rounded-lg">
                                    接続確認
                                </button>

                                <button
                                    onclick="App.actions.syncCustomers()"
                                    class="px-4 py-2 bg-slate-200 rounded-lg">
                                    顧客データを同期
                                </button>
                            </div>

                            <div id="field_message"
                                class="text-sm text-slate-500"></div>

                            ${
                                Object.keys(fields).length
                                ? `
                                <div class="border-t pt-4 space-y-3">
                                    <div class="font-semibold">
                                        フィールドマッピング
                                    </div>

                                    ${this.fieldSelect(
                                        'field_company',
                                        '会社名',
                                        k.field_company || '',
                                        fieldOptions
                                    )}

                                    ${this.fieldSelect(
                                        'field_name',
                                        '氏名',
                                        k.field_name || '',
                                        fieldOptions
                                    )}

                                    ${this.fieldSelect(
                                        'field_email',
                                        'メールアドレス',
                                        k.field_email || '',
                                        fieldOptions
                                    )}

                                    ${this.fieldSelect(
                                        'field_department',
                                        '部署名',
                                        k.field_department || '',
                                        fieldOptions
                                    )}

                                    ${this.fieldSelect(
                                        'field_phone',
                                        '電話番号',
                                        k.field_phone || '',
                                        fieldOptions
                                    )}
                                </div>
                                `
                                : ''
                            }
                        </div>
                    </section>

                    <section class="bg-white border border-slate-200 rounded-xl p-5">
                        <h2 class="text-lg font-bold mb-5">
                            SMTP設定
                        </h2>

                        <div class="space-y-4">
                            <input id="smtp_host"
                                value="${window.App.utils.esc(s.host || '')}"
                                placeholder="SMTPサーバ"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <input id="smtp_port"
                                type="number"
                                value="${Number(s.port || 587)}"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <select id="smtp_encryption"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">
                                <option ${s.encryption === 'NONE' ? 'selected' : ''}>
                                    NONE
                                </option>
                                <option ${s.encryption === 'SSL' ? 'selected' : ''}>
                                    SSL
                                </option>
                                <option ${s.encryption === 'TLS' || !s.encryption ? 'selected' : ''}>
                                    TLS
                                </option>
                            </select>

                            <label class="flex gap-2">
                                <input id="smtp_auth"
                                    type="checkbox"
                                    ${s.auth !== false ? 'checked' : ''}>
                                SMTP認証する
                            </label>

                            <input id="smtp_username"
                                value="${window.App.utils.esc(s.username || '')}"
                                placeholder="SMTPユーザー名"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <input id="smtp_password"
                                type="password"
                                placeholder="${s.password_configured ? '保存済み。変更する場合のみ入力' : 'SMTPパスワード'}"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <input id="smtp_from_email"
                                value="${window.App.utils.esc(s.from_email || '')}"
                                placeholder="送信元メールアドレス"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <input id="smtp_from_name"
                                value="${window.App.utils.esc(s.from_name || '')}"
                                placeholder="送信元表示名"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">

                            <input id="smtp_timeout"
                                type="number"
                                value="${Number(s.timeout || 15)}"
                                placeholder="タイムアウト秒"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2">
                        </div>
                    </section>
                </div>

                <div class="mt-5">
                    <button
                        onclick="App.actions.saveSettings()"
                        class="px-5 py-3 bg-indigo-600 text-white rounded-lg font-semibold">
                        設定を保存
                    </button>
                </div>
            `;
        },

        fieldSelect: function(id, label, value, options) {
            return `
                <label class="block">
                    <span class="text-sm text-slate-600">
                        ${label}
                    </span>

                    <select
                        onchange="App.State.data.settings.kintone.${id}=this.value"
                        class="w-full mt-1 border border-slate-300 rounded-lg px-3 py-2">
                        <option value="">未選択</option>
                        ${options.replace(
                            'value="' + value + '"',
                            'value="' + value + '" selected'
                        )}
                    </select>
                </label>
            `;
        },

        renderModal: function() {
            const modal =
                window.App.State.modal;

            if (!modal) return '';

            if (modal === 'preview') {
                return this.renderPreviewModal();
            }

            if (modal === 'response') {
                return this.renderResponseModal();
            }

            return '';
        },

        renderPreviewModal: function() {
            const survey =
                window.App.State.editingSurvey;

            if (!survey) return '';

            return `
                <div id="preview_modal"
                    class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-5">

                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[90vh] overflow-auto">

                        <div class="p-5 border-b flex items-center justify-between">
                            <h2 class="font-bold text-lg">
                                プレビュー
                            </h2>

                            <button
                                onclick="App.actions.closeModal()"
                                class="text-slate-500">
                                ✕
                            </button>
                        </div>

                        <div id="preview_content" class="p-6">
                            <h1 class="text-2xl font-bold mb-6">
                                ${window.App.utils.esc(survey.title)}
                            </h1>

                            ${
                                survey.groups.map(group => `
                                    <section class="mb-8">
                                        <h2 class="font-bold text-lg mb-4">
                                            ${window.App.utils.esc(group.name)}
                                        </h2>

                                        ${
                                            group.questions.map(q => `
                                                <div class="mb-6">
                                                    <label class="font-semibold block mb-2">
                                                        ${window.App.utils.esc(q.number)}
                                                        ${window.App.utils.esc(q.text)}
                                                        ${q.required
                                                            ? '<span class="text-rose-500 ml-1">必須</span>'
                                                            : ''}
                                                    </label>

                                                    ${
                                                        q.type === 'text'
                                                        ? `
                                                        <textarea
                                                            class="w-full border border-slate-300 rounded-lg p-3"
                                                            rows="4"></textarea>
                                                        `
                                                        : q.options.map(o => `
                                                            <label class="block py-2">
                                                                <input
                                                                    type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                                                                    name="preview_${q.id}">
                                                                ${window.App.utils.esc(o.label)}
                                                            </label>
                                                        `).join('')
                                                    }
                                                </div>
                                            `).join('')
                                        }
                                    </section>
                                `).join('')
                            }

                            <button
                                onclick="App.actions.previewSubmit()"
                                class="px-6 py-3 bg-indigo-600 text-white rounded-lg">
                                回答を送信
                            </button>
                        </div>
                    </div>
                </div>
            `;
        },

        renderResponseModal: function() {
            const response =
                window.App.State.data.responses.find(
                    r => r.id === window.App.State.responseId
                );

            if (!response) return '';

            const survey =
                window.App.utils.findSurvey(
                    response.survey_id
                );

            if (!survey) return '';

            const questions =
                window.App.utils.allQuestions(survey);

            return `
                <div id="response_modal"
                    class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-5">

                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-auto">

                        <div class="p-5 border-b flex justify-between">
                            <div>
                                <h2 class="font-bold text-lg">
                                    全回答
                                </h2>

                                <div class="text-sm text-slate-500">
                                    ${window.App.utils.esc(response.company)}
                                    /
                                    ${window.App.utils.esc(response.name)}
                                </div>
                            </div>

                            <button
                                onclick="App.actions.closeModal()">
                                ✕
                            </button>
                        </div>

                        <div class="p-6 space-y-5">
                            ${
                                questions.map(q => {
                                    let value =
                                        response.answers?.[q.id] ?? '';

                                    if (Array.isArray(value)) {
                                        value = value.map(id => {
                                            const option =
                                                q.options.find(
                                                    o => o.id === id
                                                );
                                            return option
                                                ? option.label
                                                : id;
                                        }).join(' / ');
                                    } else {
                                        const option =
                                            q.options.find(
                                                o => o.id === value
                                            );

                                        if (option) {
                                            value = option.label;
                                        }
                                    }

                                    return `
                                        <div class="border-b pb-4">
                                            <div class="font-semibold">
                                                ${window.App.utils.esc(q.number)}
                                                ${window.App.utils.esc(q.text)}
                                            </div>

                                            <div class="mt-2 whitespace-pre-wrap">
                                                ${window.App.utils.esc(value)}
                                            </div>
                                        </div>
                                    `;
                                }).join('')
                            }
                        </div>
                    </div>
                </div>
            `;
        }
    }
};

window.App.api.bootstrap = window.App.api.bootstrap;

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => window.App.init(),
        {once: true}
    );
} else {
    window.App.init();
}
</script>

</body>
</html>
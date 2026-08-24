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

/* ================================================================
 * 固定ストレージ名称
 * ================================================================ */
const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

/* ================================================================
 * PHP 共通処理
 * ================================================================ */
declare(strict_types=1);

session_name(SURVEY_ADMIN_SESSION);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

function survey_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function survey_now(): string {
    return date('Y-m-d H:i:s');
}

function survey_id(string $prefix = 'id'): string {
    try {
        return $prefix . '_' . bin2hex(random_bytes(10));
    } catch (Throwable) {
        return $prefix . '_' . uniqid('', true);
    }
}

function survey_default_data(): array {
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
            'field_address' => []
        ],
        'mail_logs' => []
    ];
}

function survey_storage_init(): bool {
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true) && !is_dir(SURVEY_STORAGE_DIRECTORY)) {
            return false;
        }
    }

    if (!file_exists(SURVEY_STORAGE_FILE)) {
        $data = survey_default_data();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents(SURVEY_STORAGE_FILE, $json, LOCK_EX) === false) {
            return false;
        }
    }

    return is_readable(SURVEY_STORAGE_FILE) && is_writable(SURVEY_STORAGE_DIRECTORY);
}

function survey_read(): array {
    if (!survey_storage_init()) {
        throw new RuntimeException('データファイルへのアクセス権限、survey_storageディレクトリの権限、PHP設定などを確認してください。');
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    if ($raw === false || trim($raw) === '') {
        return survey_default_data();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $backup = SURVEY_STORAGE_FILE . '.broken-' . date('YmdHis');
        @copy(SURVEY_STORAGE_FILE, $backup);
        return survey_default_data();
    }

    $base = survey_default_data();
    foreach ($base as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function survey_write(array $data): bool {
    if (!survey_storage_init()) {
        return false;
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $tmp = SURVEY_STORAGE_FILE . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    if (!@rename($tmp, SURVEY_STORAGE_FILE)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function survey_csrf(): string {
    if (empty($_SESSION['survey_csrf_token'])) {
        try {
            $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable) {
            $_SESSION['survey_csrf_token'] = hash('sha256', uniqid('', true));
        }
    }
    return $_SESSION['survey_csrf_token'];
}

function survey_check_csrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals(survey_csrf(), $token)) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが無効です。ページを再読み込みしてください。'
        ], 403);
    }
}

/* ================================================================
 * kintone
 * ================================================================ */

function get_safe_response_headers(): array {
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

function kintone_build_url(string $domain, string $endpoint): string {
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain) ?? $domain;
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain) ?? $domain;
    $domain = rtrim($domain, '/');
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

    $http_options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
        'protocol_version' => 1.1
    ];

    if ($method !== 'GET' && $payload !== null) {
        $http_options['content'] = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string)$payload;
    }

    $context_options = ['http' => $http_options];

    /*
     * 要件に従い、SSL証明書検証は既定でスキップ可能。
     * ssl_verify=true の場合のみ検証する。
     */
    $ssl_verify = (bool)($config['ssl_verify'] ?? false);

    $context_options['ssl'] = [
        'verify_peer' => $ssl_verify,
        'verify_peer_name' => $ssl_verify,
        'allow_self_signed' => !$ssl_verify
    ];

    $proxy_host_port = trim((string)($config['proxy'] ?? ''));
    if ($proxy_host_port !== '') {
        $proxy_address = 'tcp://' . $proxy_host_port;
        $context_options['http']['proxy'] = $proxy_address;
        $context_options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($context_options);

    $response_body = @file_get_contents($url, false, $context);
    $response_headers = get_safe_response_headers();

    $status_code = 500;

    foreach (array_reverse($response_headers) as $header_line) {
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/i', $header_line, $m)) {
            $status_code = (int)$m[1];
            break;
        }
    }

    $result_data = json_decode($response_body ?? '', true);

    if ($status_code >= 200 && $status_code < 300) {
        return [
            'success' => true,
            'status' => $status_code,
            'data' => is_array($result_data) ? $result_data : []
        ];
    }

    $error_msg = is_array($result_data)
        ? (string)($result_data['message'] ?? 'kintone API 通信エラーが発生しました。')
        : 'kintone API 通信エラーが発生しました。';

    return [
        'success' => false,
        'status' => $status_code,
        'message' => $error_msg,
        'raw' => $result_data
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . trim($password));
}

function kintone_config_from_data(array $settings): array {
    return [
        'ssl_verify' => (bool)($settings['ssl_verify'] ?? false),
        'proxy' => trim((string)($settings['proxy'] ?? ''))
    ];
}

function kintone_headers(array $settings): array {
    return [
        make_cybozu_auth_header(
            (string)($settings['login_name'] ?? ''),
            (string)($settings['password'] ?? '')
        ),
        'Content-Type: application/json',
        'Accept: application/json'
    ];
}

function kintone_validate_settings(array $settings): array {
    if (trim((string)($settings['subdomain'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'サブドメインを入力してください。'];
    }

    if (trim((string)($settings['login_name'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'ログイン名を入力してください。'];
    }

    if ((string)($settings['password'] ?? '') === '') {
        return ['ok' => false, 'message' => 'パスワードを入力してください。'];
    }

    if (trim((string)($settings['app_id'] ?? '')) === '') {
        return ['ok' => false, 'message' => '顧客管理アプリIDを入力してください。'];
    }

    return ['ok' => true];
}

/* ================================================================
 * データ整形
 * ================================================================ */

function survey_normalize(array $s): array {
    $s['id'] = (string)($s['id'] ?? survey_id('survey'));
    $s['title'] = (string)($s['title'] ?? '');
    $s['start_at'] = (string)($s['start_at'] ?? '');
    $s['end_at'] = (string)($s['end_at'] ?? '');
    $s['status'] = in_array(($s['status'] ?? 'draft'), ['draft', 'active', 'ended'], true)
        ? $s['status'] : 'draft';
    $s['created_at'] = (string)($s['created_at'] ?? survey_now());
    $s['updated_at'] = (string)($s['updated_at'] ?? survey_now());
    $s['numbering_mode'] = ($s['numbering_mode'] ?? 'global') === 'group' ? 'group' : 'global';
    $s['deleted'] = (bool)($s['deleted'] ?? false);
    $s['groups'] = is_array($s['groups'] ?? null) ? $s['groups'] : [];

    foreach ($s['groups'] as &$g) {
        $g['id'] = (string)($g['id'] ?? survey_id('group'));
        $g['name'] = (string)($g['name'] ?? 'グループ');
        $g['questions'] = is_array($g['questions'] ?? null) ? $g['questions'] : [];

        foreach ($g['questions'] as &$q) {
            $q['id'] = (string)($q['id'] ?? survey_id('question'));
            $q['text'] = (string)($q['text'] ?? '');
            $q['type'] = in_array(($q['type'] ?? 'single'), ['single', 'multiple', 'text'], true)
                ? $q['type'] : 'single';
            $q['required'] = (bool)($q['required'] ?? false);
            $q['options'] = is_array($q['options'] ?? null) ? array_values($q['options']) : [];
            $q['other_enabled'] = (bool)($q['other_enabled'] ?? false);
        }
        unset($q);
    }
    unset($g);

    return $s;
}

function survey_find(array $data, string $id): ?array {
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id && empty($survey['deleted'])) {
            return $survey;
        }
    }
    return null;
}

function survey_response_count(array $data, string $survey_id): int {
    $n = 0;
    foreach ($data['responses'] as $r) {
        if (($r['survey_id'] ?? '') === $survey_id) {
            $n++;
        }
    }
    return $n;
}

function survey_status_auto(array $survey): array {
    if (($survey['status'] ?? '') === 'draft') {
        return $survey;
    }

    $end = trim((string)($survey['end_at'] ?? ''));
    if ($end !== '') {
        $ts = strtotime($end);
        if ($ts !== false && $ts < time()) {
            $survey['status'] = 'ended';
        }
    }

    return $survey;
}

/* ================================================================
 * API
 * ================================================================ */

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

    try {
        if ($action !== 'bootstrap') {
            survey_check_csrf();
        }

        $data = survey_read();

        switch ($action) {
            case 'bootstrap':
                survey_json_response([
                    'ok' => true,
                    'csrf_token' => survey_csrf(),
                    'data' => $data
                ]);
                break;

            case 'save_survey':
                $json = (string)($_POST['survey_json'] ?? '');
                $survey = json_decode($json, true);

                if (!is_array($survey)) {
                    survey_json_response(['ok' => false, 'message' => 'アンケートデータが不正です。'], 422);
                }

                $survey = survey_normalize($survey);

                if (trim($survey['title']) === '') {
                    survey_json_response(['ok' => false, 'message' => 'タイトルを入力してください。'], 422);
                }

                $found = false;

                foreach ($data['surveys'] as $i => $old) {
                    if (($old['id'] ?? '') === $survey['id']) {
                        $survey['created_at'] = $old['created_at'] ?? $survey['created_at'];
                        $survey['updated_at'] = survey_now();
                        $data['surveys'][$i] = $survey;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $survey['created_at'] = survey_now();
                    $survey['updated_at'] = survey_now();
                    $data['surveys'][] = $survey;
                }

                if (!survey_write($data)) {
                    survey_json_response(['ok' => false, 'message' => '保存に失敗しました。'], 500);
                }

                survey_json_response([
                    'ok' => true,
                    'message' => 'アンケートを保存しました。',
                    'survey' => $survey
                ]);
                break;

            case 'status':
                $id = (string)($_POST['survey_id'] ?? '');
                $status = (string)($_POST['status'] ?? '');

                if (!in_array($status, ['draft', 'active', 'ended'], true)) {
                    survey_json_response(['ok' => false, 'message' => 'ステータスが不正です。'], 422);
                }

                $changed = false;
                foreach ($data['surveys'] as &$s) {
                    if (($s['id'] ?? '') === $id && empty($s['deleted'])) {
                        $s['status'] = $status;
                        $s['updated_at'] = survey_now();
                        $changed = true;
                        break;
                    }
                }
                unset($s);

                if (!$changed || !survey_write($data)) {
                    survey_json_response(['ok' => false, 'message' => 'ステータス変更に失敗しました。'], 500);
                }

                survey_json_response(['ok' => true]);
                break;

            case 'delete_survey':
                $id = (string)($_POST['survey_id'] ?? '');

                foreach ($data['surveys'] as &$s) {
                    if (($s['id'] ?? '') === $id) {
                        $s['deleted'] = true;
                        $s['updated_at'] = survey_now();
                        break;
                    }
                }
                unset($s);

                survey_write($data);
                survey_json_response(['ok' => true]);
                break;

            case 'duplicate_survey':
                $id = (string)($_POST['survey_id'] ?? '');
                $source = survey_find($data, $id);

                if ($source === null) {
                    survey_json_response(['ok' => false, 'message' => '複製元が見つかりません。'], 404);
                }

                $copy = $source;
                $copy['id'] = survey_id('survey');
                $copy['title'] = $source['title'] . '（複製）';
                $copy['status'] = 'draft';
                $copy['created_at'] = survey_now();
                $copy['updated_at'] = survey_now();
                $copy['deleted'] = false;

                foreach ($copy['groups'] as &$g) {
                    $g['id'] = survey_id('group');
                    foreach ($g['questions'] as &$q) {
                        $q['id'] = survey_id('question');
                    }
                    unset($q);
                }
                unset($g);

                $data['surveys'][] = $copy;
                survey_write($data);

                survey_json_response([
                    'ok' => true,
                    'survey' => $copy
                ]);
                break;

            case 'save_settings':
                $json = (string)($_POST['settings_json'] ?? '');
                $settings = json_decode($json, true);

                if (!is_array($settings)) {
                    survey_json_response(['ok' => false, 'message' => '設定データが不正です。'], 422);
                }

                $settings['subdomain'] = trim((string)($settings['subdomain'] ?? ''));
                $settings['login_name'] = trim((string)($settings['login_name'] ?? ''));
                $settings['password'] = (string)($settings['password'] ?? '');
                $settings['app_id'] = trim((string)($settings['app_id'] ?? ''));
                $settings['ssl_verify'] = (bool)($settings['ssl_verify'] ?? false);
                $settings['proxy'] = trim((string)($settings['proxy'] ?? ''));

                foreach ([
                    'field_company',
                    'field_name',
                    'field_email',
                    'field_department',
                    'field_phone'
                ] as $key) {
                    $settings[$key] = trim((string)($settings[$key] ?? ''));
                }

                $settings['field_address'] = is_array($settings['field_address'] ?? null)
                    ? array_values(array_filter(array_map('strval', $settings['field_address'])))
                    : [];

                $data['settings'] = array_merge($data['settings'], $settings);

                if (!survey_write($data)) {
                    survey_json_response(['ok' => false, 'message' => '設定の保存に失敗しました。'], 500);
                }

                survey_json_response(['ok' => true, 'settings' => $data['settings']]);
                break;

            case 'kintone_test':
                $settings = $data['settings'];

                $incoming = json_decode((string)($_POST['settings_json'] ?? ''), true);
                if (is_array($incoming)) {
                    $settings = array_merge($settings, $incoming);
                }

                $check = kintone_validate_settings($settings);
                if (!$check['ok']) {
                    survey_json_response($check, 422);
                }

                $url = kintone_build_url(
                    (string)$settings['subdomain'],
                    '/k/v1/app.json?id=' . rawurlencode((string)$settings['app_id'])
                );

                $result = kintone_api_request(
                    'GET',
                    $url,
                    kintone_headers($settings),
                    null,
                    kintone_config_from_data($settings)
                );

                survey_json_response([
                    'ok' => $result['success'],
                    'status' => $result['status'] ?? 500,
                    'message' => $result['success']
                        ? 'kintoneへの接続に成功しました。'
                        : ($result['message'] ?? '接続に失敗しました。'),
                    'diagnostic' => [
                        'url' => preg_replace('/\/\/[^\/]+/', '//***', $url),
                        'http_status' => $result['status'] ?? 500
                    ]
                ], $result['success'] ? 200 : 502);
                break;

            case 'kintone_fields':
                $settings = $data['settings'];

                $incoming_app = trim((string)($_POST['app_id'] ?? ''));
                if ($incoming_app !== '') {
                    $settings['app_id'] = $incoming_app;
                }

                $check = kintone_validate_settings($settings);
                if (!$check['ok']) {
                    survey_json_response($check, 422);
                }

                $url = kintone_build_url(
                    (string)$settings['subdomain'],
                    '/k/v1/app/form/fields.json?app=' . rawurlencode((string)$settings['app_id'])
                );

                $result = kintone_api_request(
                    'GET',
                    $url,
                    kintone_headers($settings),
                    null,
                    kintone_config_from_data($settings)
                );

                if (!$result['success']) {
                    survey_json_response([
                        'ok' => false,
                        'message' => $result['message'] ?? '項目一覧を取得できませんでした。',
                        'status' => $result['status'] ?? 500
                    ], 502);
                }

                $fields = [];

                foreach (($result['data']['properties'] ?? []) as $code => $field) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $type = (string)($field['type'] ?? '');

                    /* 顧客マッピングに使いやすい一般フィールドだけ表示 */
                    if (in_array($type, [
                        'SINGLE_LINE_TEXT',
                        'MULTI_LINE_TEXT',
                        'RICH_TEXT',
                        'DROP_DOWN',
                        'RADIO_BUTTON',
                        'NUMBER',
                        'LINK'
                    ], true)) {
                        $fields[] = [
                            'code' => (string)$code,
                            'label' => (string)($field['label'] ?? $code),
                            'type' => $type
                        ];
                    }
                }

                survey_json_response([
                    'ok' => true,
                    'fields' => $fields
                ]);
                break;

            case 'kintone_customers':
                $settings = $data['settings'];

                $check = kintone_validate_settings($settings);
                if (!$check['ok']) {
                    survey_json_response($check, 422);
                }

                $mapping = [
                    'company' => (string)($settings['field_company'] ?? ''),
                    'name' => (string)($settings['field_name'] ?? ''),
                    'email' => (string)($settings['field_email'] ?? ''),
                    'department' => (string)($settings['field_department'] ?? ''),
                    'phone' => (string)($settings['field_phone'] ?? '')
                ];

                $address = $settings['field_address'] ?? [];
                if (!is_array($address)) {
                    $address = [];
                }

                if ($mapping['email'] === '') {
                    survey_json_response([
                        'ok' => false,
                        'message' => 'メールアドレスのマッピングを設定してください。'
                    ], 422);
                }

                $url = kintone_build_url(
                    (string)$settings['subdomain'],
                    '/k/v1/records.json?app=' . rawurlencode((string)$settings['app_id']) .
                    '&query=' . rawurlencode('order by $id asc limit 500')
                );

                $result = kintone_api_request(
                    'GET',
                    $url,
                    kintone_headers($settings),
                    null,
                    kintone_config_from_data($settings)
                );

                if (!$result['success']) {
                    survey_json_response([
                        'ok' => false,
                        'message' => $result['message'] ?? '顧客情報を取得できませんでした。'
                    ], 502);
                }

                $customers = [];

                foreach (($result['data']['records'] ?? []) as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $value = static function (array $record, string $code): string {
                        if ($code === '' || !isset($record[$code])) {
                            return '';
                        }

                        $v = $record[$code]['value'] ?? '';
                        if (is_array($v)) {
                            return implode(' ', array_map(
                                static fn($x): string => is_array($x) ? (string)($x['value'] ?? '') : (string)$x,
                                $v
                            ));
                        }

                        return (string)$v;
                    };

                    $email = $value($record, $mapping['email']);
                    if ($email === '') {
                        continue;
                    }

                    $address_values = [];
                    foreach ($address as $code) {
                        $v = $value($record, (string)$code);
                        if ($v !== '') {
                            $address_values[] = $v;
                        }
                    }

                    $customer = [
                        'id' => 'kt_' . md5(strtolower(trim($email))),
                        'company' => $value($record, $mapping['company']),
                        'name' => $value($record, $mapping['name']),
                        'email' => $email,
                        'department' => $value($record, $mapping['department']),
                        'phone' => $value($record, $mapping['phone']),
                        'address' => implode(' ', $address_values),
                        'source' => 'kintone',
                        'sent_at' => '',
                        'send_count' => 0,
                        'answer_status' => 'unanswered',
                        'kintone_status' => 'registered'
                    ];

                    foreach ($data['customers'] as $old) {
                        if (strtolower((string)($old['email'] ?? '')) === strtolower($email)) {
                            $customer['id'] = $old['id'] ?? $customer['id'];
                            $customer['sent_at'] = $old['sent_at'] ?? '';
                            $customer['send_count'] = (int)($old['send_count'] ?? 0);
                            $customer['answer_status'] = $old['answer_status'] ?? 'unanswered';
                            break;
                        }
                    }

                    $customers[$customer['id']] = $customer;
                }

                $data['customers'] = array_values($customers);
                survey_write($data);

                survey_json_response([
                    'ok' => true,
                    'customers' => $data['customers'],
                    'count' => count($data['customers'])
                ]);
                break;

            case 'register_kintone':
                $customer_id = (string)($_POST['customer_id'] ?? '');

                foreach ($data['customers'] as &$customer) {
                    if (($customer['id'] ?? '') === $customer_id) {
                        $customer['kintone_status'] = 'registered';
                        break;
                    }
                }
                unset($customer);

                survey_write($data);
                survey_json_response(['ok' => true]);
                break;

            case 'send_mail':
                $survey_id = (string)($_POST['survey_id'] ?? '');
                $recipient_ids = json_decode((string)($_POST['recipient_ids'] ?? '[]'), true);
                $subject = trim((string)($_POST['mail_subject'] ?? ''));
                $body = (string)($_POST['mail_body'] ?? '');
                $template_type = (string)($_POST['template_type'] ?? 'initial');

                if (!is_array($recipient_ids) || count($recipient_ids) === 0) {
                    survey_json_response(['ok' => false, 'message' => '送信対象を選択してください。'], 422);
                }

                if ($subject === '' || trim($body) === '') {
                    survey_json_response(['ok' => false, 'message' => '件名と本文を入力してください。'], 422);
                }

                $survey = survey_find($data, $survey_id);
                if ($survey === null) {
                    survey_json_response(['ok' => false, 'message' => 'アンケートが見つかりません。'], 404);
                }

                $already = [];
                foreach ($data['customers'] as $c) {
                    if (
                        in_array((string)($c['id'] ?? ''), $recipient_ids, true) &&
                        !empty($c['sent_at'])
                    ) {
                        $already[] = $c['id'];
                    }
                }

                $force_resend = ($_POST['force_resend'] ?? '') === '1';

                if ($already !== [] && !$force_resend) {
                    survey_json_response([
                        'ok' => false,
                        'confirm_resend' => true,
                        'message' => '既に送信済みの宛先が含まれています。再送しますか？',
                        'already' => $already
                    ], 409);
                }

                $sent = 0;
                $failed = 0;
                $sent_details = [];

                foreach ($data['customers'] as &$customer) {
                    if (!in_array((string)($customer['id'] ?? ''), $recipient_ids, true)) {
                        continue;
                    }

                    $personal_url = (
                        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        ? 'https://' : 'http://'
                    ) . ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                    dirname($_SERVER['SCRIPT_NAME'] ?? '/') .
                    '/?action=answer&survey_id=' . rawurlencode($survey_id) .
                    '&customer_id=' . rawurlencode((string)$customer['id']);

                    $personal_subject = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [(string)$customer['name'], $personal_url],
                        $subject
                    );

                    $personal_body = str_replace(
                        ['{顧客名}', '{アンケートURL}'],
                        [(string)$customer['name'], $personal_url],
                        $body
                    );

                    $headers = [
                        'MIME-Version: 1.0',
                        'Content-Type: text/plain; charset=UTF-8',
                        'From: ' . (string)($_SERVER['SERVER_ADMIN'] ?? 'webmaster@localhost')
                    ];

                    $ok = false;
                    if (filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
                        $ok = @mail(
                            $customer['email'],
                            $personal_subject,
                            $personal_body,
                            implode("\r\n", $headers)
                        );
                    }

                    /*
                     * mail() が利用できない開発環境でも送信履歴は残す。
                     * 本番ではSMTP等をApache/PHP環境側に設定する。
                     */
                    if ($ok || filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
                        $customer['sent_at'] = survey_now();
                        $customer['send_count'] = (int)($customer['send_count'] ?? 0) + 1;
                        $customer['answer_status'] = 'unanswered';
                        $sent++;

                        $sent_details[] = [
                            'customer_id' => $customer['id'],
                            'email' => $customer['email'],
                            'subject' => $personal_subject,
                            'body' => $personal_body,
                            'sent_at' => survey_now()
                        ];
                    } else {
                        $failed++;
                    }
                }
                unset($customer);

                $data['mail_logs'][] = [
                    'id' => survey_id('mail'),
                    'survey_id' => $survey_id,
                    'sent_at' => survey_now(),
                    'type' => $template_type === 'reminder' ? 'リマインド' : '初回',
                    'count' => $sent,
                    'subject' => $subject,
                    'executor' => (string)($_SESSION['survey_admin_name'] ?? '管理者'),
                    'details' => $sent_details
                ];

                survey_write($data);

                survey_json_response([
                    'ok' => true,
                    'sent' => $sent,
                    'failed' => $failed,
                    'message' => $sent . '件の送信処理を完了しました。'
                ]);
                break;

            case 'csv':
                $survey_id = (string)($_GET['survey_id'] ?? '');
                $survey = survey_find($data, $survey_id);

                if ($survey === null) {
                    http_response_code(404);
                    exit('Survey not found');
                }

                $questions = [];
                foreach ($survey['groups'] as $g) {
                    foreach ($g['questions'] as $q) {
                        $questions[] = $q;
                    }
                }

                $fp = fopen('php://temp', 'w+');

                $header = ['回答ID', '回答日時', '顧客ID', '会社名', '氏名'];

                foreach ($questions as $index => $q) {
                    $header[] = '設問' . ($index + 1);
                }

                fputcsv($fp, $header);

                foreach ($data['responses'] as $r) {
                    if (($r['survey_id'] ?? '') !== $survey_id) {
                        continue;
                    }

                    $answers = is_array($r['answers'] ?? null) ? $r['answers'] : [];
                    $row = [
                        $r['id'] ?? '',
                        $r['answered_at'] ?? '',
                        $r['customer_id'] ?? '',
                        $r['company'] ?? '',
                        $r['name'] ?? ''
                    ];

                    foreach ($questions as $q) {
                        $value = $answers[$q['id']] ?? '';
                        if (is_array($value)) {
                            $value = implode(' / ', array_map('strval', $value));
                        }
                        $row[] = $value;
                    }

                    fputcsv($fp, $row);
                }

                rewind($fp);
                $csv = (string)stream_get_contents($fp);
                fclose($fp);

                $csv = "\xEF\xBB\xBF" . $csv;

                header('Content-Type: text/csv; charset=UTF-8');
                header(
                    'Content-Disposition: attachment; filename="survey-' .
                    preg_replace('/[^A-Za-z0-9_-]/', '_', $survey_id) .
                    '.csv"'
                );
                echo $csv;
                exit;

            default:
                survey_json_response([
                    'ok' => false,
                    'message' => '不明なアクションです。'
                ], 400);
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());

        survey_json_response([
            'ok' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

/* ================================================================
 * 公開回答画面
 * ================================================================ */
if (($_GET['action'] ?? '') === 'answer') {
    try {
        $data = survey_read();
        $survey = survey_find($data, (string)($_GET['survey_id'] ?? ''));

        if ($survey === null || ($survey['status'] ?? '') !== 'active') {
            http_response_code(404);
            echo '<!doctype html><meta charset="UTF-8"><body style="font-family:sans-serif;padding:40px">このアンケートは現在回答できません。</body>';
            exit;
        }

        $customer_id = (string)($_GET['customer_id'] ?? '');
        $customer = null;

        foreach ($data['customers'] as $c) {
            if (($c['id'] ?? '') === $customer_id) {
                $customer = $c;
                break;
            }
        }

        ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= survey_h($survey['title']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="max-w-3xl mx-auto px-5 py-10">
<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 md:p-10">
<h1 class="text-2xl font-bold mb-8"><?= survey_h($survey['title']) ?></h1>
<form method="post" action="?action=submit_answer" class="space-y-8">
<input type="hidden" name="survey_id" value="<?= survey_h($survey['id']) ?>">
<input type="hidden" name="customer_id" value="<?= survey_h($customer_id) ?>">
<input type="hidden" name="answer_csrf" value="<?= survey_h(survey_csrf()) ?>">

<?php
$qnum = 0;
foreach ($survey['groups'] as $g):
?>
<section class="border-t border-slate-200 pt-6">
<h2 class="text-lg font-bold mb-5"><?= survey_h($g['name']) ?></h2>

<?php foreach ($g['questions'] as $q):
$qnum++;
?>
<div class="mb-7">
<label class="block font-semibold mb-3">
<span class="text-blue-600 mr-2">Q<?= $qnum ?></span>
<?= survey_h($q['text']) ?>
<?php if (!empty($q['required'])): ?>
<span class="text-red-500 text-sm ml-2">必須</span>
<?php endif; ?>
</label>

<?php if ($q['type'] === 'text'): ?>
<textarea
name="answers[<?= survey_h($q['id']) ?>]"
rows="5"
class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500"
<?= !empty($q['required']) ? 'required' : '' ?>
></textarea>

<?php elseif ($q['type'] === 'multiple'): ?>
<div class="space-y-2">
<?php foreach ($q['options'] as $option): ?>
<label class="flex items-center gap-3">
<input
type="checkbox"
name="answers[<?= survey_h($q['id']) ?>][]"
value="<?= survey_h((string)$option) ?>"
class="w-4 h-4 text-blue-600"
>
<span><?= survey_h((string)$option) ?></span>
</label>
<?php endforeach; ?>
</div>

<?php else: ?>
<div class="space-y-2">
<?php foreach ($q['options'] as $option): ?>
<label class="flex items-center gap-3">
<input
type="radio"
name="answers[<?= survey_h($q['id']) ?>]"
value="<?= survey_h((string)$option) ?>"
class="w-4 h-4 text-blue-600"
<?= !empty($q['required']) ? 'required' : '' ?>
>
<span><?= survey_h((string)$option) ?></span>
</label>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>

<button class="w-full py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold">
回答を送信する
</button>
</form>
</div>
</div>
</body>
</html>
<?php
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo survey_h($e->getMessage());
        exit;
    }
}

/* ================================================================
 * 公開回答登録
 * ================================================================ */
if (($_GET['action'] ?? '') === 'submit_answer') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    $token = (string)($_POST['answer_csrf'] ?? '');
    if (!hash_equals(survey_csrf(), $token)) {
        http_response_code(403);
        exit('Invalid token');
    }

    try {
        $data = survey_read();
        $survey_id = (string)($_POST['survey_id'] ?? '');
        $customer_id = (string)($_POST['customer_id'] ?? '');
        $survey = survey_find($data, $survey_id);

        if ($survey === null || ($survey['status'] ?? '') !== 'active') {
            throw new RuntimeException('このアンケートは回答できません。');
        }

        $customer = null;

        foreach ($data['customers'] as $c) {
            if (($c['id'] ?? '') === $customer_id) {
                $customer = $c;
                break;
            }
        }

        $answers = $_POST['answers'] ?? [];
        if (!is_array($answers)) {
            $answers = [];
        }

        $response = [
            'id' => survey_id('response'),
            'survey_id' => $survey_id,
            'customer_id' => $customer_id,
            'company' => (string)($customer['company'] ?? ''),
            'name' => (string)($customer['name'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'answered_at' => survey_now(),
            'answers' => $answers
        ];

        $data['responses'][] = $response;

        foreach ($data['customers'] as &$c) {
            if (($c['id'] ?? '') === $customer_id) {
                $c['answer_status'] = 'answered';
                break;
            }
        }
        unset($c);

        survey_write($data);

        ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>回答完了</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-6">
<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-10 text-center max-w-lg w-full">
<div class="text-5xl mb-5">✓</div>
<h1 class="text-2xl font-bold mb-3">回答ありがとうございました</h1>
<p class="text-slate-600">アンケートの回答を受け付けました。</p>
</div>
</body>
</html>
<?php
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo survey_h($e->getMessage());
        exit;
    }
}

/* ================================================================
 * 管理SPA
 * ================================================================ */
try {
    survey_storage_init();
    $initial_csrf = survey_csrf();
} catch (Throwable $e) {
    $initial_csrf = '';
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= survey_h($initial_csrf) ?>">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
/*
========================================================================
CLIENT GUARD
既存名称変更・削除禁止。
App 配下に状態・描画・操作・APIを集約する。
========================================================================
*/

window.App = {
    state: {
        initialized: false,
        screen: 'list',
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mail_logs: [],
        csrf_token: '',
        currentSurvey: null,
        currentSurveyId: '',
        keyword: '',
        status_filter: 'all',
        sort: 'updated_desc',
        customer_filter: '',
        response_filter: '',
        selectedRecipients: [],
        selectedQuestions: {},
        previewMode: 'pc',
        modal: null,
        loading: false,
        fields: []
    },

    utils: {
        esc(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        },

        uid(prefix) {
            return prefix + '_' + Date.now().toString(36) + '_' +
                Math.random().toString(36).slice(2, 10);
        },

        fmtDate(value) {
            if (!value) return '未設定';
            const d = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return String(value);
            return d.toLocaleDateString('ja-JP');
        },

        fmtDateTime(value) {
            if (!value) return '未設定';
            const d = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return String(value);
            return d.toLocaleString('ja-JP');
        },

        clone(value) {
            return JSON.parse(JSON.stringify(value));
        },

        statusLabel(status) {
            return {
                active: '公開中',
                draft: '下書き',
                ended: '終了'
            }[status] || status;
        },

        statusClass(status) {
            return {
                active: 'bg-emerald-50 text-emerald-700 border-emerald-200',
                draft: 'bg-amber-50 text-amber-700 border-amber-200',
                ended: 'bg-slate-100 text-slate-600 border-slate-200'
            }[status] || 'bg-slate-100 text-slate-600';
        },

        typeLabel(type) {
            return {
                single: '単一選択',
                multiple: '複数選択',
                text: '自由記述'
            }[type] || type;
        },

        notify(message, type = 'success') {
            const old = document.getElementById('app-toast');
            if (old) old.remove();

            const color = type === 'error'
                ? 'bg-red-600'
                : type === 'warning'
                    ? 'bg-amber-500'
                    : 'bg-slate-800';

            const div = document.createElement('div');
            div.id = 'app-toast';
            div.className =
                'fixed z-[100] right-5 bottom-5 max-w-md px-5 py-3 rounded-xl shadow-xl text-white ' +
                color + ' transition-opacity';
            div.textContent = message;
            document.body.appendChild(div);

            setTimeout(() => {
                div.classList.add('opacity-0');
                setTimeout(() => div.remove(), 300);
            }, 3000);
        },

        confirm(message) {
            return window.confirm(message);
        },

        questionList(survey) {
            const list = [];
            (survey.groups || []).forEach((group, gi) => {
                (group.questions || []).forEach((question, qi) => {
                    list.push({
                        ...question,
                        groupIndex: gi,
                        questionIndex: qi
                    });
                });
            });
            return list;
        },

        renumber(survey) {
            let global = 0;

            (survey.groups || []).forEach((group, gi) => {
                (group.questions || []).forEach((question, qi) => {
                    global++;
                    question.number = survey.numbering_mode === 'group'
                        ? `Q${gi + 1}-${qi + 1}`
                        : `Q${global}`;
                });
            });

            return survey;
        },

        answerValue(value) {
            if (Array.isArray(value)) return value.join(' / ');
            return value == null ? '' : String(value);
        }
    },

    api: {
        async request(action, payload = {}, method = 'POST') {
            App.state.loading = true;
            App.render.loading();

            try {
                let url = location.pathname;
                let options = {
                    method,
                    headers: {}
                };

                if (method === 'GET') {
                    const params = new URLSearchParams({action, ...payload});
                    url += '?' + params.toString();
                } else {
                    const body = new FormData();
                    body.append('action', action);
                    body.append('csrf_token', App.state.csrf_token);

                    Object.entries(payload).forEach(([key, value]) => {
                        body.append(
                            key,
                            typeof value === 'object'
                                ? JSON.stringify(value)
                                : String(value ?? '')
                        );
                    });

                    options.body = body;
                }

                const response = await fetch(url, options);
                const text = await response.text();

                let data;

                try {
                    data = JSON.parse(text);
                } catch {
                    throw new Error(
                        'サーバーからJSONではない応答が返されました。HTTP ' +
                        response.status
                    );
                }

                if (!response.ok || data.ok === false) {
                    const error = new Error(
                        data.message || '通信に失敗しました。'
                    );
                    error.data = data;
                    error.status = response.status;
                    throw error;
                }

                return data;
            } finally {
                App.state.loading = false;
                App.render.loading();
            }
        },

        async bootstrap() {
            const data = await App.api.request('bootstrap', {}, 'GET');

            App.state.csrf_token = data.csrf_token || '';
            App.state.surveys = data.data.surveys || [];
            App.state.responses = data.data.responses || [];
            App.state.customers = data.data.customers || [];
            App.state.settings = data.data.settings || {};
            App.state.mail_logs = data.data.mail_logs || [];
        },

        async saveSurvey(survey) {
            const data = await App.api.request('save_survey', {
                survey_json: survey
            });

            const index = App.state.surveys.findIndex(
                x => x.id === data.survey.id
            );

            if (index >= 0) {
                App.state.surveys[index] = data.survey;
            } else {
                App.state.surveys.push(data.survey);
            }

            return data.survey;
        },

        async changeStatus(id, status) {
            await App.api.request('status', {
                survey_id: id,
                status
            });

            const survey = App.state.surveys.find(x => x.id === id);
            if (survey) survey.status = status;
        },

        async deleteSurvey(id) {
            await App.api.request('delete_survey', {
                survey_id: id
            });

            const survey = App.state.surveys.find(x => x.id === id);
            if (survey) survey.deleted = true;
        },

        async duplicateSurvey(id) {
            const data = await App.api.request('duplicate_survey', {
                survey_id: id
            });

            App.state.surveys.push(data.survey);
        },

        async saveSettings(settings) {
            const data = await App.api.request('save_settings', {
                settings_json: settings
            });

            App.state.settings = data.settings;
        },

        async testKintone(settings) {
            return await App.api.request('kintone_test', {
                settings_json: settings
            });
        },

        async fields(appId) {
            return await App.api.request('kintone_fields', {
                app_id: appId
            });
        },

        async syncCustomers() {
            const data = await App.api.request('kintone_customers');
            App.state.customers = data.customers || [];
            return data;
        },

        async registerKintone(customerId) {
            await App.api.request('register_kintone', {
                customer_id: customerId
            });

            const customer = App.state.customers.find(
                x => x.id === customerId
            );

            if (customer) customer.kintone_status = 'registered';
        },

        async sendMail(payload) {
            return await App.api.request('send_mail', payload);
        }
    },

    actions: {
        goList() {
            App.state.screen = 'list';
            App.state.currentSurvey = null;
            App.state.currentSurveyId = '';
            App.render.all();
        },

        goSettings() {
            App.state.screen = 'settings';
            App.render.all();
        },

        newSurvey() {
            const survey = {
                id: App.utils.uid('survey'),
                title: '',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: '',
                updated_at: '',
                numbering_mode: 'global',
                groups: [],
                deleted: false
            };

            App.state.currentSurvey = survey;
            App.state.currentSurveyId = survey.id;
            App.state.screen = 'editor';

            App.actions.addGroup(false);
            App.render.all();
            App.actions.initSortable();
        },

        editSurvey(id) {
            const survey = App.state.surveys.find(x => x.id === id);
            if (!survey) return;

            App.state.currentSurvey = App.utils.renumber(
                App.utils.clone(survey)
            );
            App.state.currentSurveyId = id;
            App.state.screen = 'editor';
            App.render.all();
            App.actions.initSortable();
        },

        viewSurvey(id) {
            App.actions.editSurvey(id);
        },

        aggregate(id) {
            App.state.currentSurveyId = id;
            App.state.currentSurvey = App.utils.clone(
                App.state.surveys.find(x => x.id === id)
            );
            App.state.screen = 'aggregate';
            App.state.response_filter = '';

            const questions = App.utils.questionList(App.state.currentSurvey);
            App.state.selectedQuestions = {};
            questions.forEach(q => {
                App.state.selectedQuestions[q.id] = true;
            });

            App.render.all();
        },

        sendScreen(id) {
            App.state.currentSurveyId = id;
            App.state.currentSurvey = App.utils.clone(
                App.state.surveys.find(x => x.id === id)
            );
            App.state.screen = 'send';
            App.state.selectedRecipients = [];
            App.state.customer_filter = '';
            App.render.all();
        },

        async stopSurvey(id) {
            if (!App.utils.confirm('このアンケートを停止しますか？')) return;

            try {
                await App.api.changeStatus(id, 'ended');
                App.utils.notify('アンケートを停止しました。');
                App.render.all();
            } catch (e) {
                App.utils.notify(e.message, 'error');
            }
        },

        async resumeSurvey(id) {
            if (!App.utils.confirm('このアンケートを再開しますか？')) return;

            try {
                await App.api.changeStatus(id, 'active');
                App.utils.notify('アンケートを再開しました。');
                App.render.all();
            } catch (e) {
                App.utils.notify(e.message, 'error');
            }
        },

        async deleteSurvey(id) {
            if (!App.utils.confirm(
                'この下書きを削除しますか？\n削除後は一覧から表示されません。'
            )) return;

            try {
                await App.api.deleteSurvey(id);
                App.utils.notify('削除しました。');
                App.render.all();
            } catch (e) {
                App.utils.notify(e.message, 'error');
            }
        },

        async duplicateSurvey(id) {
            try {
                await App.api.duplicateSurvey(id);
                App.utils.notify('下書きを複製しました。');
                App.render.all();
            } catch (e) {
                App.utils.notify(e.message, 'error');
            }
        },

        updateListKeyword(value) {
            App.state.keyword = value;
            App.render.list();
        },

        keySearch(event) {
            if (event.key === 'Enter') {
                App.state.keyword = event.target.value;
                App.render.list();
            }
        },

        toggleStatusFilter(value) {
            App.state.status_filter = value;
            App.render.list();
        },

        changeSort(value) {
            App.state.sort = value;
            App.render.list();
        },

        updateEditorTitle(value) {
            App.state.currentSurvey.title = value;
        },

        updateEditorStart(value) {
            App.state.currentSurvey.start_at = value;
        },

        updateEditorEnd(value) {
            App.state.currentSurvey.end_at = value;
        },

        updateNumberingMode(value) {
            App.state.currentSurvey.numbering_mode = value;
            App.utils.renumber(App.state.currentSurvey);
            App.render.editor();
            App.actions.initSortable();
        },

        addGroup(render = true) {
            const survey = App.state.currentSurvey;

            if (!survey) return;

            survey.groups.push({
                id: App.utils.uid('group'),
                name: '新しいグループ',
                questions: []
            });

            if (render) {
                App.render.editor();
                App.actions.initSortable();
            }
        },

        removeGroup(groupId) {
            const survey = App.state.currentSurvey;
            if (!survey) return;

            if (!App.utils.confirm(
                'このグループと、含まれる質問をすべて削除しますか？'
            )) return;

            survey.groups = survey.groups.filter(g => g.id !== groupId);
            App.utils.renumber(survey);
            App.render.editor();
            App.actions.initSortable();
        },

        updateGroupName(groupId, value) {
            const group = App.state.currentSurvey.groups.find(
                g => g.id === groupId
            );

            if (group) group.name = value;
        },

        addQuestion(groupId) {
            const group = App.state.currentSurvey.groups.find(
                g => g.id === groupId
            );

            if (!group) return;

            group.questions.push({
                id: App.utils.uid('question'),
                text: '新しい質問',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false
            });

            App.utils.renumber(App.state.currentSurvey);
            App.render.editor();
            App.actions.initSortable();
        },

        removeQuestion(groupId, questionId) {
            const group = App.state.currentSurvey.groups.find(
                g => g.id === groupId
            );

            if (!group) return;

            group.questions = group.questions.filter(
                q => q.id !== questionId
            );

            App.utils.renumber(App.state.currentSurvey);
            App.render.editor();
            App.actions.initSortable();
        },

        updateQuestion(groupId, questionId, key, value) {
            const group = App.state.currentSurvey.groups.find(
                g => g.id === groupId
            );

            const q = group?.questions.find(
                x => x.id === questionId
            );

            if (!q) return;

            q[key] = value;

            if (key === 'type') {
                if (value === 'text') q.options = [];
                if (value !== 'text' && (!q.options || !q.options.length)) {
                    q.options = ['選択肢1', '選択肢2'];
                }
            }
        },

        updateOption(groupId, questionId, index, value) {
            const q = App.actions.getQuestion(groupId, questionId);
            if (!q) return;
            q.options[index] = value;
        },

        addOption(groupId, questionId) {
            const q = App.actions.getQuestion(groupId, questionId);
            if (!q) return;

            q.options = q.options || [];
            q.options.push('選択肢' + (q.options.length + 1));

            App.render.editor();
            App.actions.initSortable();
        },

        removeOption(groupId, questionId, index) {
            const q = App.actions.getQuestion(groupId, questionId);
            if (!q) return;

            q.options.splice(index, 1);
            App.render.editor();
            App.actions.initSortable();
        },

        getQuestion(groupId, questionId) {
            const group = App.state.currentSurvey.groups.find(
                g => g.id === groupId
            );

            return group?.questions.find(q => q.id === questionId);
        },

        async saveEditor() {
            try {
                const survey = App.utils.renumber(
                    App.state.currentSurvey
                );

                if (!survey.title.trim()) {
                    App.utils.notify('タイトルを入力してください。', 'warning');
                    return;
                }

                await App.api.saveSurvey(survey);
                App.utils.notify('保存しました。');

                setTimeout(() => App.actions.goList(), 500);
            } catch (e) {
                App.utils.notify(e.message, 'error');
            }
        },

        cancelEditor() {
            if (!App.utils.confirm(
                '変更を破棄して一覧へ戻りますか？'
            )) return;

            App.actions.goList();
        },

        setPreviewMode(mode) {
            App.state.previewMode = mode;
            App.render.preview();
        },

        showPreview() {
            App.state.modal = 'preview';
            App.render.modal();
        },

        closeModal() {
            App.state.modal = null;
            App.render.modal();
        },

        previewSubmit() {
            window.alert('これはプレビューです。実際の回答は送信されません。');
        },

        initSortable() {
            if (typeof Sortable === 'undefined') return;

            const groups = document.getElementById('question_editor');
            if (!groups) return;

            new Sortable(groups, {
                animation: 180,
                handle: '.group-handle',
                ghostClass: 'opacity-40',
                onEnd() {
                    const ids = [...groups.children]
                        .map(el => el.dataset.groupId);

                    App.state.currentSurvey.groups.sort(
                        (a, b) => ids.indexOf(a.id) - ids.indexOf(b.id)
                    );

                    App.utils.renumber(App.state.currentSurvey);
                    App.render.editor();
                    App.actions.initSortable();
                }
            });

            document.querySelectorAll('.question-list').forEach(list => {
                new Sortable(list, {
                    group: 'survey-questions',
                    animation: 180,
                    handle: '.question-handle',
                    ghostClass: 'opacity-40',

                    onEnd(event) {
                        App.actions.syncQuestionOrder();
                    }
                });
            });
        },

        syncQuestionOrder() {
            const survey = App.state.currentSurvey;

            document.querySelectorAll('.question-list')
                .forEach(list => {
                    const groupId = list.dataset.groupId;
                    const group = survey.groups.find(
                        g => g.id === groupId
                    );

                    if (!group) return;

                    const ids = [...list.children]
                        .map(el => el.dataset.questionId);

                    const map = {};

                    survey.groups.forEach(g => {
                        g.questions.forEach(q => {
                            map[q.id] = q;
                        });
                    });

                    group.questions = ids
                        .map(id => map[id])
                        .filter(Boolean);
                });

            const allIds = [];

            document.querySelectorAll('.question-list')
                .forEach(list => {
                    const groupId = list.dataset.groupId;
                    const group = survey.groups.find(
                        g => g.id === groupId
                    );

                    if (!group) return;

                    const ids = [...list.children]
                        .map(el => el.dataset.questionId);

                    const map = {};

                    survey.groups.forEach(g => {
                        g.questions.forEach(q => map[q.id] = q);
                    });

                    group.questions = ids
                        .map(id => map[id])
                        .filter(Boolean);

                    allIds.push(...ids);
                });

            /* グループ間移動のため、DOMの各リストから全質問を再構築 */
            const questionMap = {};
            survey.groups.forEach(g => {
                g.questions.forEach(q => questionMap[q.id] = q);
            });

            survey.groups.forEach(g => {
                const list = document.querySelector(
                    `.question-list[data-group-id="${CSS.escape(g.id)}"]`
                );

                if (!list) return;

                g.questions = [...list.children]
                    .map(el => questionMap[el.dataset.questionId])
                    .filter(Boolean);
            });

            App.utils.renumber(survey);
            App.render.editor();
            App.actions.initSortable();
        },

        async syncCustomers() {
            try {
                const data = await App.api.syncCustomers();
                App.utils.notify(
                    `${data.count}件の顧客データを取得しました。`
                );
                App.render.send();
            } catch (e) {
                App.utils.notify(e.message, 'error');
            }
        },

        updateCustomerFilter(value) {
            App.state.customer_filter = value;
            App.render.send();
        },

        toggleCustomer(id, checked) {
            if (checked) {
                if (!App.state.selectedRecipients.includes(id)) {
                    App.state.selectedRecipients.push(id);
                }
            } else {
                App.state.selectedRecipients =
                    App.state.selectedRecipients.filter(x => x !== id);
            }

            App.render.sendTable();
        },

        toggleAllCustomers(checked) {
            const list = App.actions.filteredCustomers();

            App.state.selectedRecipients = checked
                ? list.filter(c => c.source !== 'web').map(c => c.id)
                : [];

            App.render.sendTable();
        },

        filteredCustomers() {
            const keyword = App.state.customer_filter.trim().toLowerCase();

            return App.state.customers.filter(c => {
                if (!keyword) return true;

                return [
                    c.company,
                    c.name,
                    c.email,
                    c.department,
                    c.phone,
                    c.address
                ].join(' ').toLowerCase().includes(keyword);
            });
        },

        setMailTemplateType(value) {
            document.getElementById('template_type').value = value;

            if (value === 'reminder') {
                const body = document.getElementById('mail_body');
                const subject = document.getElementById('mail_subject');

                if (subject) {
                    subject.value = '【再送】アンケートご回答のお願い';
                }

                if (body) {
                    body.value =
                        '{顧客名} 様\n\n' +
                        '先日ご案内したアンケートが未回答となっております。\n' +
                        'お手数ですが、下記URLよりご回答ください。\n\n' +
                        '{アンケートURL}\n\n' +
                        'よろしくお願いいたします。';
                }
            }
        },

        async sendMail(forceResend = false) {
            const selected = App.state.selectedRecipients;

            if (!selected.length) {
                App.utils.notify('送信対象を選択してください。', 'warning');
                return;
            }

            const subject =
                document.getElementById('mail_subject')?.value || '';

            const body =
                document.getElementById('mail_body')?.value || '';

            const template =
                document.getElementById('template_type')?.value || 'initial';

            try {
                const data = await App.api.sendMail({
                    survey_id: App.state.currentSurveyId,
                    recipient_ids: selected,
                    mail_subject: subject,
                    mail_body: body,
                    template_type: template,
                    force_resend: forceResend ? '1' : '0'
                });

                App.utils.notify(data.message || '送信しました。');

                await App.api.bootstrap();

                App.state.selectedRecipients = [];
                App.render.send();
            } catch (e) {
                if (
                    e.data &&
                    e.data.confirm_resend &&
                    App.utils.confirm(e.data.message)
                ) {
                    return App.actions.sendMail(true);
                }

                App.utils.notify(e.message, 'error');
            }
        },

        showMailDetail(logId, customerId) {
            const log = App.state.mail_logs.find(x => x.id === logId);
            if (!log) return;

            const detail = (log.details || []).find(
                x => x.customer_id === customerId
            );

            if (!detail) return;

            App.state.mailDetail = detail;
            App.state.modal = 'mail';
            App.render.modal();
        },

        markKintoneRegistered(id) {
            App.api.registerKintone(id)
                .then(() => {
                    App.utils.notify('kintone登録済みに更新しました。');
                    App.render.send();
                })
                .catch(e => App.utils.notify(e.message, 'error'));
        },

        updateResponseFilter(value) {
            App.state.response_filter = value;
            App.render.aggregate();
        },

        toggleQuestion(id, checked) {
            App.state.selectedQuestions[id] = checked;
            App.render.aggregate();
        },

        selectAllQuestions(value) {
            App.utils.questionList(App.state.currentSurvey)
                .forEach(q => {
                    App.state.selectedQuestions[q.id] = value;
                });

            App.render.aggregate();
        },

        showResponse(responseId) {
            const response = App.state.responses.find(
                x => x.id === responseId
            );

            if (!response) return;

            App.state.responseDetail = response;
            App.state.modal = 'response';
            App.render.modal();
        },

        printAggregate() {
            window.print();
        },

        csvExport() {
            const url =
                location.pathname +
                '?action=csv&survey_id=' +
                encodeURIComponent(App.state.currentSurveyId) +
                '&csrf_token=' +
                encodeURIComponent(App.state.csrf_token);

            window.location.href = url;
        },

        updateSettings(key, value) {
            App.state.settings[key] = value;
        },

        async testConnection() {
            try {
                const settings = App.actions.collectSettings();
                await App.api.testKintone(settings);
                App.utils.notify('kintoneへの接続に成功しました。');
            } catch (e) {
                App.utils.notify(e.message, 'error');
            }
        },

        async fetchKintoneFields() {
            const appId =
                document.getElementById('setting_app_id')?.value || '';

            if (!appId.trim()) {
                App.utils.notify('顧客管理アプリIDを入力してください。', 'warning');
                return;
            }

            const settings = App.actions.collectSettings();
            App.state.settings = settings;

            try {
                const data = await App.api.fields(appId);
                App.state.fields = data.fields || [];

                App.render.settings();

                App.utils.notify(
                    `${App.state.fields.length}件の項目を取得しました。`
                );
            } catch (e) {
                App.utils.notify(e.message, 'error');
            }
        },

        collectSettings() {
            const s = App.state.settings;

            const address = [];

            document.querySelectorAll(
                'select[data-address-field="1"]'
            ).forEach(el => {
                if (el.value) address.push(el.value);
            });

            return {
                ...s,
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
                field_address: address
            };
        },

        async saveSettings() {
            try {
                const settings = App.actions.collectSettings();
                await App.api.saveSettings(settings);
                App.utils.notify('kintone連携設定を保存しました。');
            } catch (e) {
                App.utils.notify(e.message, 'error');
            }
        }
    },

    render: {
        loading() {
            const el = document.getElementById('app-loading');

            if (!el) return;

            el.classList.toggle(
                'hidden',
                !App.state.loading
            );
        },

        all() {
            const app = document.getElementById('app');

            if (!app) return;

            app.innerHTML = `
                ${App.render.header()}
                <main class="max-w-[1500px] mx-auto px-5 py-6">
                    <div id="screen"></div>
                </main>
                <div id="app-loading"
                     class="hidden fixed inset-0 z-[90] bg-white/60 backdrop-blur-[1px] items-center justify-center">
                    <div class="bg-white rounded-xl shadow-xl border border-slate-200 px-5 py-4 flex items-center gap-3">
                        <div class="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                        <span>処理中...</span>
                    </div>
                </div>
                <div id="modal-root"></div>
            `;

            App.render.screen();
            App.render.modal();
        },

        header() {
            return `
<header class="sticky top-0 z-40 bg-white border-b border-slate-200">
    <div class="max-w-[1500px] mx-auto px-5 h-16 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold">
                Q
            </div>
            <div>
                <div class="font-bold text-slate-900">アンケート管理</div>
                <div class="text-[11px] text-slate-400">Survey Management System</div>
            </div>
        </div>

        <nav class="flex items-center gap-1">
            <button
                onclick="App.actions.goList()"
                class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100
                ${App.state.screen === 'list' ? 'bg-slate-100 font-semibold' : ''}">
                アンケート一覧
            </button>

            <button
                onclick="App.actions.goSettings()"
                class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100
                ${App.state.screen === 'settings' ? 'bg-slate-100 font-semibold' : ''}">
                kintone連携設定
            </button>

            <button
                onclick="window.location.reload()"
                class="px-3 py-2 rounded-lg text-sm text-slate-500 hover:bg-slate-100">
                ログアウト
            </button>
        </nav>
    </div>
</header>`;
        },

        screen() {
            const el = document.getElementById('screen');
            if (!el) return;

            if (App.state.screen === 'list') {
                App.render.list();
            } else if (App.state.screen === 'editor') {
                App.render.editor();
            } else if (App.state.screen === 'send') {
                App.render.send();
            } else if (App.state.screen === 'aggregate') {
                App.render.aggregate();
            } else if (App.state.screen === 'settings') {
                App.render.settings();
            } else {
                App.state.screen = 'list';
                App.render.list();
            }
        },

        list() {
            const el = document.getElementById('screen');
            if (!el) return;

            const keyword = App.state.keyword.trim().toLowerCase();

            let surveys = App.state.surveys
                .filter(s => !s.deleted)
                .map(App.utils.clone)
                .map(App.utils.status_auto || (x => x));

            surveys = surveys.map(s => App.utils.renumber(s));

            surveys = surveys.filter(s => {
                if (
                    App.state.status_filter !== 'all' &&
                    s.status !== App.state.status_filter
                ) return false;

                if (
                    keyword &&
                    !String(s.title || '').toLowerCase().includes(keyword)
                ) return false;

                return true;
            });

            const answerCount = id =>
                App.state.responses.filter(
                    r => r.survey_id === id
                ).length;

            const sort = App.state.sort;

            surveys.sort((a, b) => {
                if (sort === 'updated_desc') {
                    return String(b.updated_at).localeCompare(String(a.updated_at));
                }

                if (sort === 'updated_asc') {
                    return String(a.updated_at).localeCompare(String(b.updated_at));
                }

                if (sort === 'answers_desc') {
                    return answerCount(b.id) - answerCount(a.id);
                }

                if (sort === 'answers_asc') {
                    return answerCount(a.id) - answerCount(b.id);
                }

                if (sort === 'start_desc') {
                    return String(b.start_at).localeCompare(String(a.start_at));
                }

                if (sort === 'start_asc') {
                    return String(a.start_at).localeCompare(String(b.start_at));
                }

                return 0;
            });

            el.innerHTML = `
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">アンケート一覧</h1>
        <p class="text-sm text-slate-500 mt-1">アンケートの作成・送信・集計を一元管理します。</p>
    </div>

    <button
        onclick="App.actions.newSurvey()"
        class="px-5 py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold shadow-sm">
        ＋ 新規アンケート作成
    </button>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-5">
    <div class="grid grid-cols-1 md:grid-cols-[1fr_180px_220px] gap-3">
        <input
            value="${App.utils.esc(App.state.keyword)}"
            onkeyup="App.actions.keySearch(event)"
            oninput="App.actions.updateListKeyword(this.value)"
            placeholder="タイトルを検索（Enter）"
            class="rounded-xl border border-slate-300 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500">

        <select
            onchange="App.actions.toggleStatusFilter(this.value)"
            class="rounded-xl border border-slate-300 px-4 py-2.5">
            <option value="all" ${App.state.status_filter === 'all' ? 'selected' : ''}>すべて</option>
            <option value="active" ${App.state.status_filter === 'active' ? 'selected' : ''}>公開中</option>
            <option value="draft" ${App.state.status_filter === 'draft' ? 'selected' : ''}>下書き</option>
            <option value="ended" ${App.state.status_filter === 'ended' ? 'selected' : ''}>終了</option>
        </select>

        <select
            onchange="App.actions.changeSort(this.value)"
            class="rounded-xl border border-slate-300 px-4 py-2.5">
            <option value="updated_desc" ${App.state.sort === 'updated_desc' ? 'selected' : ''}>更新日：新しい順</option>
            <option value="updated_asc" ${App.state.sort === 'updated_asc' ? 'selected' : ''}>更新日：古い順</option>
            <option value="answers_desc" ${App.state.sort === 'answers_desc' ? 'selected' : ''}>回答数：多い順</option>
            <option value="answers_asc" ${App.state.sort === 'answers_asc' ? 'selected' : ''}>回答数：少ない順</option>
            <option value="start_desc" ${App.state.sort === 'start_desc' ? 'selected' : ''}>開始日：新しい順</option>
            <option value="start_asc" ${App.state.sort === 'start_asc' ? 'selected' : ''}>開始日：古い順</option>
        </select>
    </div>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
<div class="overflow-x-auto">
<table class="w-full min-w-[1150px] text-sm">
<thead class="bg-slate-50 border-b border-slate-200">
<tr>
    <th class="text-left px-5 py-4 font-semibold">作成日 / 更新日</th>
    <th class="text-left px-5 py-4 font-semibold">タイトル</th>
    <th class="text-left px-5 py-4 font-semibold">アンケート期間</th>
    <th class="text-left px-5 py-4 font-semibold">ステータス</th>
    <th class="text-right px-5 py-4 font-semibold">回答数</th>
    <th class="text-left px-5 py-4 font-semibold">操作</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100">
${surveys.length ? surveys.map(s => {
    const count = answerCount(s.id);

    let buttons = '';

    if (s.status === 'active') {
        buttons = `
            <button onclick="App.actions.editSurvey('${s.id}')" class="btn-sm">確認・編集</button>
            <button onclick="App.actions.aggregate('${s.id}')" class="btn-sm">集計</button>
            <button onclick="App.actions.sendScreen('${s.id}')" class="btn-sm">送信</button>
            <button onclick="App.actions.stopSurvey('${s.id}')" class="btn-sm danger">停止</button>
            <button onclick="App.actions.duplicateSurvey('${s.id}')" class="btn-sm">複製</button>`;
    } else if (s.status === 'draft') {
        buttons = `
            <button onclick="App.actions.editSurvey('${s.id}')" class="btn-sm">確認・編集</button>
            <button onclick="App.actions.deleteSurvey('${s.id}')" class="btn-sm danger">削除</button>
            <button onclick="App.actions.duplicateSurvey('${s.id}')" class="btn-sm">複製</button>`;
    } else {
        buttons = `
            <button onclick="App.actions.editSurvey('${s.id}')" class="btn-sm">確認・編集</button>
            <button onclick="App.actions.aggregate('${s.id}')" class="btn-sm">集計</button>
            <button onclick="App.actions.duplicateSurvey('${s.id}')" class="btn-sm">複製</button>`;
    }

    return `
<tr class="hover:bg-slate-50">
<td class="px-5 py-4 whitespace-nowrap">
    <div>${App.utils.fmtDate(s.created_at)}</div>
    <div class="text-xs text-slate-400 mt-1">更新: ${App.utils.fmtDate(s.updated_at)}</div>
</td>
<td class="px-5 py-4">
    <div class="font-bold text-slate-900">${App.utils.esc(s.title || '無題')}</div>
</td>
<td class="px-5 py-4 whitespace-nowrap">
    ${App.utils.fmtDateTime(s.start_at)}
    <span class="text-slate-400 mx-1">～</span>
    ${App.utils.fmtDateTime(s.end_at)}
</td>
<td class="px-5 py-4">
    <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold ${App.utils.statusClass(s.status)}">
        ${App.utils.statusLabel(s.status)}
    </span>
</td>
<td class="px-5 py-4 text-right font-semibold">${count} 件</td>
<td class="px-5 py-4">
    <div class="flex flex-wrap gap-1.5">${buttons}</div>
</td>
</tr>`;
}).join('') : `
<tr>
<td colspan="6" class="px-5 py-16 text-center text-slate-400">
    <div class="text-4xl mb-3">□</div>
    アンケートがありません。
</td>
</tr>`}
</tbody>
</table>
</div>
</div>

<div class="mt-4 text-xs text-slate-400">
${surveys.length} 件表示
</div>

<style>
/* Tailwind CDNを使用し、レイアウトCSSは追加しない。
   ボタンの最小限の見た目はTailwindクラスへ変換可能な
   class名として扱うため、下記styleは使わない。
*/
</style>
`;

            /* styleタグによるCSSは禁止要件のため即座に除去 */
            const style = el.querySelector('style');
            if (style) style.remove();

            el.querySelectorAll('.btn-sm').forEach(btn => {
                btn.className =
                    'px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-100 text-xs font-medium';
                if (btn.classList.contains('danger')) {
                    btn.className =
                        'px-2.5 py-1.5 rounded-lg border border-red-200 bg-white hover:bg-red-50 text-red-600 text-xs font-medium';
                }
            });
        },

        editor() {
            const el = document.getElementById('screen');
            if (!el || !App.state.currentSurvey) return;

            const s = App.state.currentSurvey;
            App.utils.renumber(s);

            const readonly = s.status === 'ended';

            el.innerHTML = `
<div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4 mb-5">
    <div class="flex items-center gap-3">
        <button onclick="App.actions.goList()" class="text-slate-500 hover:text-slate-900">← 一覧</button>
        <div>
            <div class="text-xs text-slate-400">アンケート編集</div>
            <h1 class="text-xl font-bold">${App.utils.esc(s.title || '新規アンケート')}</h1>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        <button onclick="App.actions.showPreview()" class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50">
            プレビュー
        </button>

        ${!readonly ? `
        <button onclick="App.actions.cancelEditor()" class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white">
            キャンセル
        </button>
        <button onclick="App.actions.saveEditor()" class="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold">
            保存して一覧へ戻る
        </button>` : ''}
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[1fr_330px] gap-5">
<div class="space-y-4">
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="md:col-span-2">
                <span class="block text-sm font-semibold mb-2">タイトル</span>
                <input
                    id="survey_title"
                    value="${App.utils.esc(s.title)}"
                    ${readonly ? 'readonly' : ''}
                    oninput="App.actions.updateEditorTitle(this.value)"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-lg font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500">
            </label>

            <label>
                <span class="block text-sm font-semibold mb-2">開始日時</span>
                <input
                    id="survey_start_at"
                    type="datetime-local"
                    value="${App.utils.esc(s.start_at)}"
                    ${readonly ? 'disabled' : ''}
                    onchange="App.actions.updateEditorStart(this.value)"
                    class="w-full rounded-xl border border-slate-300 px-4 py-2.5">
            </label>

            <label>
                <span class="block text-sm font-semibold mb-2">終了日時</span>
                <input
                    id="survey_end_at"
                    type="datetime-local"
                    value="${App.utils.esc(s.end_at)}"
                    ${readonly ? 'disabled' : ''}
                    onchange="App.actions.updateEditorEnd(this.value)"
                    class="w-full rounded-xl border border-slate-300 px-4 py-2.5">
            </label>
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-5">
            <div>
                <h2 class="font-bold">質問構成</h2>
                <p class="text-xs text-slate-400 mt-1">グループ・質問はドラッグ＆ドロップで並べ替えできます。</p>
            </div>

            ${!readonly ? `
            <button onclick="App.actions.addGroup()" class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm">
                ＋ グループ追加
            </button>` : ''}
        </div>

        <div id="question_editor" class="space-y-4">
            ${(s.groups || []).map((g, gi) => `
            <div
                data-group-id="${g.id}"
                class="border border-slate-200 rounded-2xl bg-slate-50 overflow-hidden">

                <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-200 bg-white">
                    ${!readonly ? '<span class="group-handle cursor-grab text-slate-400 text-xl">⠿</span>' : ''}
                    <input
                        value="${App.utils.esc(g.name)}"
                        ${readonly ? 'readonly' : ''}
                        oninput="App.actions.updateGroupName('${g.id}', this.value)"
                        class="flex-1 font-bold bg-transparent outline-none">

                    <span class="text-xs text-slate-400">${g.questions.length}問</span>

                    ${!readonly ? `
                    <button onclick="App.actions.removeGroup('${g.id}')"
                        class="text-red-500 hover:bg-red-50 rounded-lg px-2 py-1">
                        削除
                    </button>` : ''}
                </div>

                <div
                    class="question-list p-4 space-y-3 min-h-[50px]"
                    data-group-id="${g.id}">

                    ${(g.questions || []).map((q, qi) => `
                    <div
                        data-question-id="${q.id}"
                        class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">

                        <div class="flex items-start gap-3">
                            ${!readonly ? '<span class="question-handle cursor-grab text-slate-400 text-xl pt-1">⠿</span>' : ''}

                            <div class="flex-1">
                                <div class="flex flex-wrap items-center gap-2 mb-3">
                                    <span class="text-xs font-bold text-blue-600">${App.utils.esc(q.number || '')}</span>

                                    <select
                                        ${readonly ? 'disabled' : ''}
                                        onchange="App.actions.updateQuestion('${g.id}','${q.id}','type',this.value)"
                                        class="text-xs border border-slate-200 rounded-lg px-2 py-1">
                                        <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択</option>
                                        <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                                        <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述</option>
                                    </select>

                                    <label class="flex items-center gap-1.5 text-xs">
                                        <input
                                            type="checkbox"
                                            ${q.required ? 'checked' : ''}
                                            ${readonly ? 'disabled' : ''}
                                            onchange="App.actions.updateQuestion('${g.id}','${q.id}','required',this.checked)"
                                            class="rounded">
                                        必須回答
                                    </label>
                                </div>

                                <input
                                    value="${App.utils.esc(q.text)}"
                                    ${readonly ? 'readonly' : ''}
                                    oninput="App.actions.updateQuestion('${g.id}','${q.id}','text',this.value)"
                                    class="w-full border border-slate-200 rounded-xl px-3 py-2.5 font-medium focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder="質問文">

                                ${q.type !== 'text' ? `
                                <div class="mt-4 space-y-2">
                                    ${(q.options || []).map((option, oi) => `
                                    <div class="flex items-center gap-2">
                                        <span class="text-slate-300">
                                            ${q.type === 'single' ? '○' : '□'}
                                        </span>
                                        <input
                                            value="${App.utils.esc(option)}"
                                            ${readonly ? 'readonly' : ''}
                                            oninput="App.actions.updateOption('${g.id}','${q.id}',${oi},this.value)"
                                            class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                        ${!readonly ? `
                                        <button onclick="App.actions.removeOption('${g.id}','${q.id}',${oi})"
                                            class="text-slate-400 hover:text-red-500">×</button>` : ''}
                                    </div>`).join('')}

                                    ${!readonly ? `
                                    <button onclick="App.actions.addOption('${g.id}','${q.id}')"
                                        class="text-sm text-blue-600 hover:text-blue-700">
                                        ＋ 選択肢追加
                                    </button>` : ''}

                                    <label class="flex items-center gap-2 text-sm mt-2">
                                        <input
                                            type="checkbox"
                                            ${q.other_enabled ? 'checked' : ''}
                                            ${readonly ? 'disabled' : ''}
                                            onchange="App.actions.updateQuestion('${g.id}','${q.id}','other_enabled',this.checked)"
                                            class="rounded">
                                        「その他」を表示
                                    </label>
                                </div>` : ''}

                                ${!readonly ? `
                                <div class="mt-4 flex justify-end">
                                    <button onclick="App.actions.removeQuestion('${g.id}','${q.id}')"
                                        class="text-xs text-red-500 hover:bg-red-50 px-3 py-1.5 rounded-lg">
                                        この質問を削除
                                    </button>
                                </div>` : ''}
                            </div>
                        </div>
                    </div>`).join('')}

                </div>

                ${!readonly ? `
                <div class="px-4 pb-4">
                    <button onclick="App.actions.addQuestion('${g.id}')"
                        class="w-full py-2.5 rounded-xl border border-dashed border-slate-300 text-sm text-slate-500 hover:bg-white">
                        ＋ 質問を追加
                    </button>
                </div>` : ''}
            </div>`).join('')}
        </div>
    </div>
</div>

<aside class="space-y-4">
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
        <h3 class="font-bold mb-4">公開設定</h3>

        <div class="space-y-2">
            <button
                ${readonly ? 'disabled' : ''}
                onclick="App.actions.setEditorStatus('draft')"
                class="w-full py-2.5 rounded-xl border ${s.status === 'draft' ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-slate-200'}">
                下書き
            </button>

            <button
                ${readonly ? 'disabled' : ''}
                onclick="App.actions.setEditorStatus('active')"
                class="w-full py-2.5 rounded-xl border ${s.status === 'active' ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-slate-200'}">
                公開中
            </button>

            ${s.status === 'ended' ? `
            <div class="text-center text-sm text-slate-500 py-2">
                このアンケートは終了しています。
            </div>` : ''}
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
        <h3 class="font-bold mb-3">質問番号</h3>

        <select
            id="survey_numbering_mode"
            ${readonly ? 'disabled' : ''}
            onchange="App.actions.updateNumberingMode(this.value)"
            class="w-full rounded-xl border border-slate-300 px-3 py-2.5">
            <option value="global" ${s.numbering_mode === 'global' ? 'selected' : ''}>
                Q1 / Q2 / Q3
            </option>
            <option value="group" ${s.numbering_mode === 'group' ? 'selected' : ''}>
                Q1-1 / Q1-2 / Q2-1
            </option>
        </select>
    </div>
</aside>
</div>`;

            App.actions.initSortable();
        },

        setEditorStatus(status) {
            if (!App.state.currentSurvey) return;

            App.state.currentSurvey.status = status;
            App.render.editor();
            App.actions.initSortable();
        },

        preview() {
            const el = document.getElementById('preview_content');
            if (!el || !App.state.currentSurvey) return;

            const s = App.state.currentSurvey;

            el.innerHTML = `
<div class="${App.state.previewMode === 'mobile'
    ? 'max-w-[390px]'
    : 'max-w-[850px]'} mx-auto bg-slate-50 rounded-2xl p-5">
<div class="bg-white rounded-2xl border border-slate-200 p-6">
<h1 class="text-2xl font-bold mb-8">${App.utils.esc(s.title || 'アンケート')}</h1>

${s.groups.map((g, gi) => `
<section class="mb-8">
<h2 class="text-lg font-bold border-b border-slate-200 pb-3 mb-5">
${App.utils.esc(g.name)}
</h2>

${g.questions.map(q => `
<div class="mb-7">
<div class="font-semibold mb-3">
<span class="text-blue-600 mr-2">${App.utils.esc(q.number || '')}</span>
${App.utils.esc(q.text)}
${q.required ? '<span class="text-red-500 text-xs ml-2">必須</span>' : ''}
</div>

${q.type === 'text' ? `
<textarea rows="4" class="w-full border border-slate-300 rounded-xl p-3" placeholder="回答を入力"></textarea>
` : q.options.map((o, oi) => `
<label class="flex items-center gap-3 mb-2">
<input type="${q.type === 'single' ? 'radio' : 'checkbox'}" name="preview_${q.id}">
<span>${App.utils.esc(o)}</span>
</label>`).join('')}

${q.other_enabled ? `
<label class="flex items-center gap-3">
<input type="${q.type === 'single' ? 'radio' : 'checkbox'}">
<span>その他</span>
</label>` : ''}
</div>`).join('')}
</section>`).join('')}

<button
onclick="App.actions.previewSubmit()"
class="w-full py-3 rounded-xl bg-blue-600 text-white font-semibold">
送信する
</button>
</div>
</div>`;
        },

        modal() {
            const root = document.getElementById('modal-root');

            if (!root) return;

            if (!App.state.modal) {
                root.innerHTML = '';
                return;
            }

            if (App.state.modal === 'preview') {
                root.innerHTML = `
<div id="preview_modal"
class="fixed inset-0 z-[80] bg-slate-900/50 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl max-h-[94vh] overflow-hidden flex flex-col">
<div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
<div>
<h2 class="font-bold">プレビュー</h2>
<p class="text-xs text-slate-400">未保存の内容もそのまま表示します。</p>
</div>
<div class="flex gap-2">
<button onclick="App.actions.setPreviewMode('pc')"
class="px-3 py-2 rounded-lg text-sm ${App.state.previewMode === 'pc' ? 'bg-slate-900 text-white' : 'bg-slate-100'}">
PC表示
</button>
<button onclick="App.actions.setPreviewMode('mobile')"
class="px-3 py-2 rounded-lg text-sm ${App.state.previewMode === 'mobile' ? 'bg-slate-900 text-white' : 'bg-slate-100'}">
スマートフォン表示
</button>
<button onclick="App.actions.closeModal()"
class="px-3 py-2 rounded-lg bg-slate-100">
閉じる
</button>
</div>
</div>

<div class="overflow-auto p-5">
<div id="preview_content"></div>
</div>
</div>
</div>`;

                App.render.preview();
                return;
            }

            if (App.state.modal === 'response') {
                const r = App.state.responseDetail;
                const s = App.state.currentSurvey;

                const questionMap = {};
                App.utils.questionList(s).forEach(q => {
                    questionMap[q.id] = q;
                });

                root.innerHTML = `
<div id="response_modal"
class="fixed inset-0 z-[80] bg-slate-900/50 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden">
<div class="px-5 py-4 border-b border-slate-200 flex justify-between">
<div>
<h2 class="font-bold">全回答</h2>
<p class="text-sm text-slate-500">
${App.utils.esc(r.company)} / ${App.utils.esc(r.name)}
</p>
</div>
<button onclick="App.actions.closeModal()" class="px-3 py-2 rounded-lg bg-slate-100">閉じる</button>
</div>

<div id="response_detail" class="p-5 overflow-auto max-h-[75vh] space-y-4">
${Object.entries(r.answers || {}).map(([qid, value]) => `
<div class="border-b border-slate-100 pb-4">
<div class="text-xs text-blue-600 font-semibold mb-1">
${App.utils.esc(questionMap[qid]?.number || '')}
</div>
<div class="font-semibold mb-2">
${App.utils.esc(questionMap[qid]?.text || '設問')}
</div>
<div class="rounded-xl bg-slate-50 p-3 whitespace-pre-wrap">
${App.utils.esc(App.utils.answerValue(value))}
</div>
</div>`).join('')}
</div>
</div>
</div>`;
                return;
            }

            if (App.state.modal === 'mail') {
                const d = App.state.mailDetail;

                root.innerHTML = `
<div class="fixed inset-0 z-[80] bg-slate-900/50 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl">
<div class="px-5 py-4 border-b border-slate-200 flex justify-between">
<h2 class="font-bold">送信文を確認</h2>
<button onclick="App.actions.closeModal()" class="px-3 py-2 rounded-lg bg-slate-100">閉じる</button>
</div>

<div class="p-5 space-y-4">
<div>
<div class="text-xs text-slate-400 mb-1">送信先</div>
<div>${App.utils.esc(d.email)}</div>
</div>
<div>
<div class="text-xs text-slate-400 mb-1">件名</div>
<div class="font-semibold">${App.utils.esc(d.subject)}</div>
</div>
<div>
<div class="text-xs text-slate-400 mb-1">本文</div>
<pre class="whitespace-pre-wrap bg-slate-50 rounded-xl p-4 text-sm">${App.utils.esc(d.body)}</pre>
</div>
</div>
</div>
</div>`;
            }
        },

        send() {
            const el = document.getElementById('screen');
            if (!el || !App.state.currentSurvey) return;

            el.innerHTML = `
<div class="flex items-center gap-3 mb-5">
<button onclick="App.actions.goList()" class="text-slate-500 hover:text-slate-900">← 一覧</button>
<div>
<div class="text-xs text-slate-400">ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴</div>
<h1 class="text-2xl font-bold mt-1">
${App.utils.esc(App.state.currentSurvey.title)}
</h1>
</div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[1fr_390px] gap-5">
<div class="space-y-5">
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
<div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4">
<div>
<h2 class="font-bold">顧客宛先</h2>
<p class="text-xs text-slate-400 mt-1">kintone顧客管理アプリから手動同期できます。</p>
</div>

<button
onclick="App.actions.syncCustomers()"
class="px-4 py-2 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-sm">
顧客一覧を更新
</button>
</div>

<input
id="customer_filter"
value="${App.utils.esc(App.state.customer_filter)}"
oninput="App.actions.updateCustomerFilter(this.value)"
placeholder="顧客名・メールアドレス・会社名で検索"
class="w-full rounded-xl border border-slate-300 px-4 py-2.5 mb-4">

<div id="customer_table"></div>
</div>

${App.render.mailLogsHtml()}
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 h-fit sticky top-20">
<h2 class="font-bold mb-4">メール送信</h2>

<div class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800 mb-4">
kintone未登録の回答者が存在する場合は、回答後に登録状態を管理できます。
</div>

<label class="block text-sm font-semibold mb-2">テンプレート</label>
<select
id="template_type"
onchange="App.actions.setMailTemplateType(this.value)"
class="w-full rounded-xl border border-slate-300 px-3 py-2.5 mb-4">
<option value="initial">初回送信</option>
<option value="reminder">再送・リマインド</option>
</select>

<label class="block text-sm font-semibold mb-2">件名</label>
<input
id="mail_subject"
value="【ご依頼】アンケートご回答のお願い"
class="w-full rounded-xl border border-slate-300 px-3 py-2.5 mb-4">

<label class="block text-sm font-semibold mb-2">本文</label>
<textarea
id="mail_body"
rows="12"
class="w-full rounded-xl border border-slate-300 px-3 py-2.5 mb-4">{顧客名} 様

アンケートへのご回答をお願いいたします。

下記URLよりご回答ください。

{アンケートURL}

よろしくお願いいたします。</textarea>

<div class="text-xs text-slate-400 mb-4">
使用可能な変数：
<strong>{顧客名}</strong>、
<strong>{アンケートURL}</strong>
</div>

<button
onclick="App.actions.sendMail()"
class="w-full py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold">
選択した宛先へ一括送信
</button>

<div class="mt-3 text-center text-sm text-slate-500">
選択中：${App.state.selectedRecipients.length} 件
</div>
</div>
</div>`;

            App.render.sendTable();
        },

        sendTable() {
            const el = document.getElementById('customer_table');
            if (!el) return;

            const list = App.actions.filteredCustomers();

            el.innerHTML = `
<div class="overflow-x-auto">
<table class="w-full min-w-[1000px] text-sm">
<thead class="bg-slate-50">
<tr>
<th class="px-3 py-3 text-left">
<input
id="select_all"
type="checkbox"
onchange="App.actions.toggleAllCustomers(this.checked)"
${list.length && list.every(c => c.source === 'web' || App.state.selectedRecipients.includes(c.id)) ? 'checked' : ''}>
</th>
<th class="px-3 py-3 text-left">会社名 / 氏名</th>
<th class="px-3 py-3 text-left">メール</th>
<th class="px-3 py-3 text-left">送信</th>
<th class="px-3 py-3 text-left">回答</th>
<th class="px-3 py-3 text-left">kintone</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100">
${list.length ? list.map(c => {
    const logs = App.state.mail_logs.filter(
        l => l.survey_id === App.state.currentSurveyId
    );

    let latestDetail = null;
    let latestLogId = null;

    for (let i = logs.length - 1; i >= 0; i--) {
        const detail = (logs[i].details || []).find(
            d => d.customer_id === c.id
        );

        if (detail) {
            latestDetail = detail;
            latestLogId = logs[i].id;
            break;
        }
    }

    return `
<tr>
<td class="px-3 py-3">
${c.source === 'web' ? `
<span class="text-xs text-slate-400">Web回答</span>
` : `
<input
type="checkbox"
${App.state.selectedRecipients.includes(c.id) ? 'checked' : ''}
onchange="App.actions.toggleCustomer('${c.id}',this.checked)">
`}
</td>

<td class="px-3 py-3">
<div class="font-bold">${App.utils.esc(c.company || '-')}</div>
<div>${App.utils.esc(c.name || '-')}</div>
<div class="text-xs text-slate-400">${App.utils.esc(c.department || '')}</div>
</td>

<td class="px-3 py-3">
<div>${App.utils.esc(c.email || '-')}</div>
<div class="text-xs text-slate-400">${App.utils.esc(c.phone || '')}</div>
</td>

<td class="px-3 py-3 whitespace-nowrap">
<div>${App.utils.fmtDateTime(c.sent_at)}</div>
<div class="text-xs text-slate-400">${c.send_count || 0} 回</div>
${latestDetail ? `
<button
onclick="App.actions.showMailDetail('${latestLogId}','${c.id}')"
class="text-xs text-blue-600 mt-1">
送信文を確認
</button>` : ''}
</td>

<td class="px-3 py-3">
<span class="inline-flex px-2 py-1 rounded-full text-xs ${
    c.answer_status === 'answered'
        ? 'bg-emerald-50 text-emerald-700'
        : 'bg-amber-50 text-amber-700'
}">
${c.answer_status === 'answered' ? '回答済み' : '送信済み（未回答）'}
</span>
</td>

<td class="px-3 py-3">
${c.kintone_status === 'registered'
    ? '<span class="text-emerald-600 text-xs font-semibold">✓ kintone登録完了</span>'
    : `
<button
onclick="App.actions.markKintoneRegistered('${c.id}')"
class="text-xs px-2.5 py-1.5 rounded-lg bg-blue-50 text-blue-700">
kintone登録完了
</button>`}
</td>
</tr>`;
}).join('') : `
<tr>
<td colspan="6" class="px-4 py-10 text-center text-slate-400">
顧客データがありません。kintone連携設定を確認して「顧客一覧を更新」を実行してください。
</td>
</tr>`}
</tbody>
</table>
</div>`;
        },

        mailLogsHtml() {
            const logs = App.state.mail_logs.filter(
                x => x.survey_id === App.state.currentSurveyId
            ).slice().reverse();

            return `
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
<h2 class="font-bold mb-4">一括送信履歴</h2>

<div class="overflow-x-auto">
<table class="w-full min-w-[700px] text-sm">
<thead class="bg-slate-50">
<tr>
<th class="text-left px-3 py-3">日時</th>
<th class="text-left px-3 py-3">種別</th>
<th class="text-right px-3 py-3">件数</th>
<th class="text-left px-3 py-3">件名</th>
<th class="text-left px-3 py-3">実行者</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100">
${logs.length ? logs.map(l => `
<tr>
<td class="px-3 py-3">${App.utils.fmtDateTime(l.sent_at)}</td>
<td class="px-3 py-3">${App.utils.esc(l.type)}</td>
<td class="px-3 py-3 text-right">${l.count || 0}</td>
<td class="px-3 py-3">${App.utils.esc(l.subject)}</td>
<td class="px-3 py-3">${App.utils.esc(l.executor)}</td>
</tr>`).join('') : `
<tr>
<td colspan="5" class="px-3 py-8 text-center text-slate-400">
送信履歴はありません。
</td>
</tr>`}
</tbody>
</table>
</div>
</div>`;
        },

        aggregate() {
            const el = document.getElementById('screen');
            if (!el || !App.state.currentSurvey) return;

            const survey = App.state.currentSurvey;

            const surveyResponses = App.state.responses.filter(
                r => r.survey_id === survey.id
            );

            const customers = App.state.customers;

            const sentCustomerIds = new Set();

            customers.forEach(c => {
                if (Number(c.send_count || 0) > 0) {
                    sentCustomerIds.add(c.id);
                }
            });

            const sentCount = sentCustomerIds.size;

            const registeredResponses =
                surveyResponses.filter(r =>
                    sentCustomerIds.has(r.customer_id)
                ).length;

            const unregisteredResponses =
                surveyResponses.filter(r =>
                    !sentCustomerIds.has(r.customer_id)
                ).length;

            const unanswered = Math.max(
                0,
                sentCount - registeredResponses
            );

            const rate = sentCount
                ? ((registeredResponses / sentCount) * 100).toFixed(1)
                : '0.0';

            const questions = App.utils.questionList(survey);

            const visibleQuestions = questions.filter(
                q => App.state.selectedQuestions[q.id] !== false
            );

            const filter = App.state.response_filter.trim().toLowerCase();

            const filteredResponses = surveyResponses.filter(r => {
                if (!filter) return true;

                return [
                    r.company,
                    r.name,
                    r.email
                ].join(' ').toLowerCase().includes(filter);
            });

            el.innerHTML = `
<div class="flex items-center gap-3 mb-5">
<button onclick="App.actions.goList()" class="text-slate-500 hover:text-slate-900">← 一覧</button>
<div>
<div class="text-xs text-slate-400">回答集計・分析</div>
<h1 class="text-2xl font-bold">${App.utils.esc(survey.title)}</h1>
</div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
${[
    ['送信対象者数', sentCount + ' 人'],
    ['回答数', surveyResponses.length + ' 件'],
    ['未登録顧客からの回答数', unregisteredResponses + ' 件'],
    ['未回答数', unanswered + ' 人'],
    ['回答率', rate + ' %']
].map(x => `
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
<div class="text-xs text-slate-400">${x[0]}</div>
<div class="text-2xl font-bold mt-2">${x[1]}</div>
</div>`).join('')}
</div>

<div class="grid grid-cols-1 xl:grid-cols-[300px_1fr] gap-5">
<aside class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 h-fit xl:sticky xl:top-20">
<h2 class="font-bold mb-4">集計対象設問</h2>

<div class="flex gap-2 mb-4">
<button onclick="App.actions.selectAllQuestions(true)"
class="text-xs text-blue-600">
全選択
</button>
<button onclick="App.actions.selectAllQuestions(false)"
class="text-xs text-slate-500">
全解除
</button>
</div>

<div class="space-y-2">
${questions.map(q => `
<label class="flex items-start gap-2 p-2 rounded-lg hover:bg-slate-50">
<input
type="checkbox"
${App.state.selectedQuestions[q.id] !== false ? 'checked' : ''}
onchange="App.actions.toggleQuestion('${q.id}',this.checked)"
class="mt-1 rounded">
<span class="text-sm">
<span class="font-semibold">${App.utils.esc(q.number)}</span>
${App.utils.esc(q.text)}
<span class="block text-[10px] text-slate-400 mt-0.5">${App.utils.typeLabel(q.type)}</span>
</span>
</label>`).join('')}
</div>
</aside>

<div class="space-y-5">
<div class="flex flex-wrap gap-2 justify-end">
<button onclick="App.actions.csvExport()"
class="px-4 py-2 rounded-xl border border-slate-300 bg-white">
CSV出力
</button>
<button onclick="App.actions.printAggregate()"
class="px-4 py-2 rounded-xl border border-slate-300 bg-white">
PDF / 印刷
</button>
</div>

${surveyResponses.length === 0 ? `
<div class="bg-white rounded-2xl border border-slate-200 p-12 text-center text-slate-400">
<div class="text-4xl mb-3">□</div>
現在、回答データはありません
</div>` : ''}

${visibleQuestions.map(q =>
    App.render.questionAggregate(q, surveyResponses)
).join('')}

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
<div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4">
<h2 class="font-bold">個別回答一覧</h2>
<input
id="response_filter"
value="${App.utils.esc(App.state.response_filter)}"
oninput="App.actions.updateResponseFilter(this.value)"
placeholder="会社名・氏名で検索"
class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
</div>

<div id="response_table" class="overflow-x-auto">
<table class="w-full min-w-[800px] text-sm">
<thead class="bg-slate-50">
<tr>
<th class="text-left px-3 py-3">回答日時</th>
<th class="text-left px-3 py-3">会社名</th>
<th class="text-left px-3 py-3">氏名</th>
<th class="text-left px-3 py-3">メール</th>
<th class="text-left px-3 py-3">操作</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100">
${filteredResponses.map(r => `
<tr>
<td class="px-3 py-3">${App.utils.fmtDateTime(r.answered_at)}</td>
<td class="px-3 py-3 font-semibold">${App.utils.esc(r.company)}</td>
<td class="px-3 py-3">${App.utils.esc(r.name)}</td>
<td class="px-3 py-3">${App.utils.esc(r.email)}</td>
<td class="px-3 py-3">
<button
onclick="App.actions.showResponse('${r.id}')"
class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 text-xs">
全回答を表示
</button>
</td>
</tr>`).join('')}
</tbody>
</table>
</div>
</div>
</div>
</div>`;

            App.render.aggregateCharts();
        },

        questionAggregate(q, responses) {
            if (q.type === 'text') {
                const items = responses
                    .map(r => ({
                        r,
                        value: r.answers?.[q.id] || ''
                    }))
                    .filter(x => App.utils.answerValue(x.value).trim() !== '');

                return `
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
<div class="flex items-center gap-2 mb-4">
<span class="text-xs font-bold text-blue-600">${App.utils.esc(q.number)}</span>
<h2 class="font-bold">${App.utils.esc(q.text)}</h2>
<span class="px-2 py-1 rounded-full bg-slate-100 text-slate-500 text-xs">自由記述</span>
</div>

<div class="max-h-[360px] overflow-auto space-y-3">
${items.length ? items.map(x => `
<div class="border-l-2 border-blue-200 pl-4">
<div class="text-xs text-slate-400">
${App.utils.esc(x.r.company)} / ${App.utils.esc(x.r.name)}
</div>
<div class="mt-1 whitespace-pre-wrap">${App.utils.esc(App.utils.answerValue(x.value))}</div>
</div>`).join('') : `
<div class="text-slate-400 text-sm py-5">
回答データがありません。
</div>`}
</div>
</div>`;
            }

            const counts = {};

            (q.options || []).forEach(o => {
                counts[o] = 0;
            });

            let total = 0;

            responses.forEach(r => {
                let value = r.answers?.[q.id];

                if (value == null || value === '') return;

                if (!Array.isArray(value)) value = [value];

                value.forEach(v => {
                    if (!counts[v]) counts[v] = 0;
                    counts[v]++;
                    total++;
                });
            });

            return `
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
<div class="flex flex-wrap items-center gap-2 mb-5">
<span class="text-xs font-bold text-blue-600">${App.utils.esc(q.number)}</span>
<h2 class="font-bold">${App.utils.esc(q.text)}</h2>
<span class="px-2 py-1 rounded-full bg-slate-100 text-slate-500 text-xs">
${App.utils.typeLabel(q.type)}
</span>
</div>

<div class="space-y-4">
${Object.entries(counts).map(([label, count]) => {
    const pct = total ? ((count / total) * 100).toFixed(1) : '0.0';

    return `
<div>
<div class="flex justify-between text-sm mb-1.5">
<span>${App.utils.esc(label)}</span>
<span class="text-slate-500">${count} 件 / ${pct}%</span>
</div>
<div class="h-3 bg-slate-100 rounded-full overflow-hidden">
<div class="h-full bg-blue-500 rounded-full" style="width:${pct}%"></div>
</div>
</div>`;
}).join('')}
</div>
</div>`;
        },

        aggregateCharts() {
            /* グラフはquestionAggregate内で即時描画 */
        },

        settings() {
            const el = document.getElementById('screen');
            if (!el) return;

            const s = App.state.settings || {};
            const fields = App.state.fields || [];

            const option = (key, value = '', empty = '未選択') => `
<select id="${key}"
class="w-full rounded-xl border border-slate-300 px-3 py-2.5">
<option value="">${empty}</option>
${fields.map(f => `
<option value="${App.utils.esc(f.code)}"
${String(value) === String(f.code) ? 'selected' : ''}>
${App.utils.esc(f.label)} [${App.utils.esc(f.code)}]
</option>`).join('')}
</select>`;

            const address = Array.isArray(s.field_address)
                ? s.field_address : [];

            el.innerHTML = `
<div class="mb-6">
<div class="text-xs text-slate-400">ホーム ＞ システム設定 ＞ kintone連携設定</div>
<h1 class="text-2xl font-bold mt-1">kintone連携設定</h1>
<p class="text-sm text-slate-500 mt-1">
顧客管理アプリとの接続情報と項目マッピングを設定します。
</p>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
<h2 class="font-bold mb-5">接続・認証設定</h2>

<form id="settings_form" onsubmit="return false" class="space-y-4">
<label class="block">
<span class="text-sm font-semibold">サブドメイン</span>
<div class="flex mt-2">
<input
id="setting_subdomain"
value="${App.utils.esc(s.subdomain || '')}"
placeholder="xxxx または xxxx.cybozu.com"
class="flex-1 rounded-l-xl border border-slate-300 px-3 py-2.5">
<span class="px-3 flex items-center bg-slate-100 border-y border-r border-slate-300 rounded-r-xl text-sm">
.cybozu.com
</span>
</div>
</label>

<label class="block">
<span class="text-sm font-semibold">顧客管理アプリID</span>
<input
id="setting_app_id"
value="${App.utils.esc(s.app_id || '')}"
class="w-full mt-2 rounded-xl border border-slate-300 px-3 py-2.5">
</label>

<label class="block">
<span class="text-sm font-semibold">ログイン名</span>
<input
id="setting_login_name"
value="${App.utils.esc(s.login_name || '')}"
class="w-full mt-2 rounded-xl border border-slate-300 px-3 py-2.5">
</label>

<label class="block">
<span class="text-sm font-semibold">パスワード</span>
<input
id="setting_password"
type="password"
value="${App.utils.esc(s.password || '')}"
class="w-full mt-2 rounded-xl border border-slate-300 px-3 py-2.5">
</label>

<label class="block">
<span class="text-sm font-semibold">Proxyサーバ</span>
<input
id="setting_proxy"
value="${App.utils.esc(s.proxy || '')}"
placeholder="host名:port番号"
class="w-full mt-2 rounded-xl border border-slate-300 px-3 py-2.5">
</label>

<label class="flex items-center gap-3 p-3 rounded-xl bg-amber-50 border border-amber-200">
<input
id="setting_ssl_verify"
type="checkbox"
${s.ssl_verify ? 'checked' : ''}>
<span class="text-sm">
SSL証明書検証を有効にする
<span class="block text-xs text-amber-700 mt-0.5">
要件上の既定値は検証なしです。
</span>
</span>
</label>

<div class="flex flex-wrap gap-2 pt-2">
<button
onclick="App.actions.testConnection()"
class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white">
接続確認
</button>

<button
onclick="App.actions.fetchKintoneFields()"
class="px-4 py-2.5 rounded-xl bg-slate-900 text-white">
項目一覧を取得
</button>

<button
onclick="App.actions.saveSettings()"
class="px-4 py-2.5 rounded-xl bg-blue-600 text-white font-semibold">
設定を保存
</button>
</div>
</form>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
<h2 class="font-bold mb-1">項目マッピング</h2>
<p class="text-xs text-slate-400 mb-5">
「項目一覧を取得」でkintoneの日本語フィールド名を読み込みます。
</p>

${fields.length === 0 ? `
<div id="field_message"
class="rounded-xl bg-slate-50 border border-slate-200 p-5 text-sm text-slate-500 mb-5">
まだフィールド一覧を取得していません。
</div>` : ''}

<div class="space-y-4">
<div>
<label class="text-sm font-semibold block mb-2">会社名 (Company)</label>
${option('field_company', s.field_company)}
</div>

<div>
<label class="text-sm font-semibold block mb-2">氏名 (Name)</label>
${option('field_name', s.field_name)}
</div>

<div>
<label class="text-sm font-semibold block mb-2">メールアドレス (Email)</label>
${option('field_email', s.field_email)}
</div>

<div>
<label class="text-sm font-semibold block mb-2">部署名 (Department)</label>
${option('field_department', s.field_department)}
</div>

<div>
<label class="text-sm font-semibold block mb-2">電話番号 (Phone)</label>
${option('field_phone', s.field_phone)}
</div>

<div>
<label class="text-sm font-semibold block mb-2">
住所 (Address)
<span class="text-xs text-slate-400">複数選択可</span>
</label>

${[0,1,2,3,4].map(i => `
<select
data-address-field="1"
class="w-full rounded-xl border border-slate-300 px-3 py-2.5 mb-2">
<option value="">${i === 0 ? '都道府県・市区町村・番地等を選択' : '追加の住所フィールド（任意）'}</option>
${fields.map(f => `
<option value="${App.utils.esc(f.code)}"
${String(address[i] || '') === String(f.code) ? 'selected' : ''}>
${App.utils.esc(f.label)} [${App.utils.esc(f.code)}]
</option>`).join('')}
</select>`).join('')}
</div>
</div>
</div>
</div>

<div class="mt-5 bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
<h2 class="font-bold mb-2">連携方式</h2>
<div class="grid md:grid-cols-3 gap-3 text-sm">
<div class="p-4 rounded-xl bg-slate-50">
<div class="font-semibold">手動同期</div>
<div class="text-slate-500 mt-1">顧客情報は「顧客一覧を更新」で取得します。</div>
</div>
<div class="p-4 rounded-xl bg-slate-50">
<div class="font-semibold">メール照合</div>
<div class="text-slate-500 mt-1">メールアドレスを顧客照合キーとして使用します。</div>
</div>
<div class="p-4 rounded-xl bg-slate-50">
<div class="font-semibold">回答管理</div>
<div class="text-slate-500 mt-1">アンケートごとに回答を独立管理します。</div>
</div>
</div>
</div>`;
        }
    },

    init() {
        if (App.state.initialized) return;
        App.state.initialized = true;

        App.state.csrf_token =
            document.querySelector('meta[name="csrf-token"]')?.content || '';

        /*
         * 初期化失敗を「白画面」にしない。
         */
        try {
            App.render.all();

            App.api.bootstrap()
                .then(() => {
                    App.render.all();
                    App.utils.notify('アプリケーションの初期描画に成功しました。');
                })
                .catch(error => {
                    console.error(error);
                    App.utils.notify(
                        '初期化に失敗しました: ' + error.message,
                        'error'
                    );

                    const screen = document.getElementById('screen');

                    if (screen) {
                        screen.innerHTML = `
<div class="bg-white border border-red-200 rounded-2xl p-8">
<h1 class="text-xl font-bold text-red-600 mb-3">
初期化に失敗しました
</h1>
<p class="text-slate-700 mb-4">${App.utils.esc(error.message)}</p>
<div class="text-sm text-slate-500">
データファイルへのアクセス権限、survey_storageディレクトリの権限、
PHP設定などを確認してください。
</div>
<button
onclick="location.reload()"
class="mt-5 px-4 py-2 rounded-xl bg-slate-900 text-white">
再読み込み
</button>
</div>`;
                    }
                });
        } catch (error) {
            console.error(error);

            const app = document.getElementById('app');

            if (app) {
                app.innerHTML = `
<div class="max-w-3xl mx-auto mt-12 bg-white border border-red-200 rounded-2xl p-8">
<h1 class="text-xl font-bold text-red-600 mb-3">初期化に失敗しました</h1>
<p class="text-slate-700">${App.utils.esc(error.message)}</p>
</div>`;
            }
        }
    }
};

/*
 * document.readyState ガード付き初期化。
 * DOMContentLoaded前後のどちらでも1回だけ実行する。
 */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => App.init(), {
        once: true
    });
} else {
    App.init();
}
</script>

</body>
</html>
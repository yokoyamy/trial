<?php
declare(strict_types=1);

/*
========================================================================
GUARD COMMENT — 固定名称一覧
※以下の名称は、今後の修正・再生成時も変更・削除禁止。

【重要な業務ルール】
- アンケートの一意識別子は survey.id。
- メールアドレス、顧客ID、タイトルをアンケートの重複判定・一意キーに使用しない。
- 同一メールアドレス・同一顧客・同一タイトルでも survey.id が異なれば別アンケート。
- 回答、送信履歴、集計、顧客との関連付けは survey_id 単位で分離する。
- アンケート複製時は必ず新しい survey.id を発行する。
- 顧客のメールアドレスは顧客照合用情報であり、アンケート識別子ではない。

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

function survey_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_now(): string
{
    return date('c');
}

function survey_uuid(string $prefix = ''): string
{
    try {
        $r = bin2hex(random_bytes(12));
    } catch (Throwable) {
        $r = uniqid('', true);
    }
    return $prefix . date('YmdHis') . '_' . $r;
}

function survey_read(): array
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

    foreach (['surveys','responses','customers','settings','mail_logs'] as $k) {
        if (!isset($data[$k]) || !is_array($data[$k])) {
            $data[$k] = [];
        }
    }

    return $data;
}

function survey_write(array $data): bool
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

function survey_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf_token'];
}

function survey_check_csrf(): bool
{
    return isset($_POST['csrf_token'])
        && hash_equals(survey_csrf(), (string)$_POST['csrf_token']);
}

function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $h = http_get_last_response_headers();
        return is_array($h) ? $h : [];
    }

    return [];
}

/**
 * kintone URLの成形。
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
 * kintone REST API。
 * cURLを使用せずstream_context_create/file_get_contentsで実装。
 */
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
    ];

    if ($method !== 'GET' && $payload !== null) {
        $http_options['content'] = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string)$payload;
    }

    $context_options = [
        'http' => $http_options,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));
    if ($proxy !== '') {
        $context_options['http']['proxy'] = 'tcp://' . $proxy;
        $context_options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($context_options);
    $body = @file_get_contents($url, false, $context);
    $headers_result = get_safe_response_headers();

    $status = 500;

    foreach ($headers_result as $header) {
        if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/i', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    $data = json_decode($body ?? '', true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($data) ? $data : []
        ];
    }

    $message = is_array($data)
        ? (string)($data['message'] ?? 'kintone API 通信エラーが発生しました。')
        : 'kintone API 通信エラーが発生しました。';

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'raw' => $data
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string
{
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . trim($password));
}

function survey_settings_from_data(array $data): array
{
    $s = $data['settings'];

    return [
        'subdomain' => (string)($s['subdomain'] ?? ''),
        'login_name' => (string)($s['login_name'] ?? ''),
        'password' => (string)($s['password'] ?? ''),
        'app_id' => (string)($s['app_id'] ?? ''),
        'ssl_verify' => (bool)($s['ssl_verify'] ?? false),
        'proxy' => (string)($s['proxy'] ?? ''),
        'field_company' => (string)($s['field_company'] ?? ''),
        'field_name' => (string)($s['field_name'] ?? ''),
        'field_email' => (string)($s['field_email'] ?? ''),
        'field_department' => (string)($s['field_department'] ?? ''),
        'field_phone' => (string)($s['field_phone'] ?? ''),
        'field_address' => $s['field_address'] ?? [],
    ];
}

function survey_kintone_headers(array $settings): array
{
    return [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                trim($settings['login_name']) . ':' .
                trim($settings['password'])
            ),
        'Content-Type: application/json',
        'Accept: application/json'
    ];
}

function survey_kintone_fields(array $settings, string $appId): array
{
    if (trim($settings['subdomain']) === '' ||
        trim($settings['login_name']) === '' ||
        trim($settings['password']) === '' ||
        trim($appId) === '') {
        return [
            'success' => false,
            'message' => 'サブドメイン、ログイン名、パスワード、アプリIDを入力してください。'
        ];
    }

    $url = kintone_build_url(
        $settings['subdomain'],
        '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
    );

    return kintone_api_request(
        'GET',
        $url,
        survey_kintone_headers($settings),
        null,
        ['proxy' => $settings['proxy']]
    );
}

function survey_kintone_records(array $settings, string $appId): array
{
    if ($appId === '') {
        return ['success' => false, 'message' => 'アプリIDが未指定です。'];
    }

    $url = kintone_build_url($settings['subdomain'], '/k/v1/records.json');

    return kintone_api_request(
        'GET',
        $url . '?app=' . rawurlencode($appId) . '&totalCount=true',
        survey_kintone_headers($settings),
        null,
        ['proxy' => $settings['proxy']]
    );
}

/* ================================================================
 * API / POST
 * ================================================================ */

$isApi = isset($_GET['action']) || isset($_POST['action']);

if ($isApi) {
    header('Content-Type: application/json; charset=UTF-8');

    $action = (string)($_REQUEST['action'] ?? '');
    $data = survey_read();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !survey_check_csrf()) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'load') {
        echo json_encode([
            'ok' => true,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_survey') {
        $json = (string)($_POST['survey_json'] ?? '');
        $survey = json_decode($json, true);

        if (!is_array($survey)) {
            echo json_encode([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $id = trim((string)($survey['id'] ?? ''));

        /*
         * survey.idだけをアンケート識別子として使用。
         * title/email/customer_idによる既存判定は絶対に行わない。
         */
        if ($id === '') {
            $id = survey_uuid('survey_');
        }

        $survey['id'] = $id;
        $survey['title'] = (string)($survey['title'] ?? '無題のアンケート');
        $survey['status'] = in_array(
            ($survey['status'] ?? 'draft'),
            ['draft', 'active', 'ended'],
            true
        ) ? $survey['status'] : 'draft';

        $survey['numbering_mode'] = in_array(
            ($survey['numbering_mode'] ?? 'global'),
            ['global', 'group'],
            true
        ) ? $survey['numbering_mode'] : 'global';

        $survey['created_at'] = (string)(
            $survey['created_at'] ?? survey_now()
        );
        $survey['updated_at'] = survey_now();
        $survey['deleted'] = false;

        $found = false;

        foreach ($data['surveys'] as $i => $old) {
            if ((string)($old['id'] ?? '') === $id) {
                $survey['created_at'] = $old['created_at'] ?? $survey['created_at'];
                $data['surveys'][$i] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['surveys'][] = $survey;
        }

        survey_write($data);

        echo json_encode([
            'ok' => true,
            'survey' => $survey
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as $i => $survey) {
            if ((string)($survey['id'] ?? '') === $id) {
                $data['surveys'][$i]['deleted'] = true;
                $data['surveys'][$i]['status'] = 'draft';
                $data['surveys'][$i]['updated_at'] = survey_now();
            }
        }

        survey_write($data);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'change_status') {
        $id = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? 'draft');

        if (!in_array($status, ['draft', 'active', 'ended'], true)) {
            echo json_encode([
                'ok' => false,
                'message' => 'ステータスが不正です。'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        foreach ($data['surveys'] as $i => $survey) {
            if ((string)($survey['id'] ?? '') === $id) {
                $data['surveys'][$i]['status'] = $status;
                $data['surveys'][$i]['updated_at'] = survey_now();
            }
        }

        survey_write($data);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'duplicate_survey') {
        $id = (string)($_POST['survey_id'] ?? '');
        $copy = null;

        foreach ($data['surveys'] as $survey) {
            if ((string)($survey['id'] ?? '') === $id &&
                empty($survey['deleted'])) {
                $copy = $survey;
                break;
            }
        }

        if ($copy === null) {
            echo json_encode([
                'ok' => false,
                'message' => '複製元アンケートがありません。'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        /*
         * 複製時は必ず新しいsurvey.id。
         * タイトルは識別に使用しない。
         */
        $copy['id'] = survey_uuid('survey_');
        $copy['status'] = 'draft';
        $copy['deleted'] = false;
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();

        $data['surveys'][] = $copy;
        survey_write($data);

        echo json_encode([
            'ok' => true,
            'survey' => $copy
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_settings') {
        $json = (string)($_POST['settings_json'] ?? '');
        $settings = json_decode($json, true);

        if (!is_array($settings)) {
            echo json_encode([
                'ok' => false,
                'message' => '設定データが不正です。'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        foreach ([
            'subdomain','login_name','password','app_id',
            'proxy','field_company','field_name','field_email',
            'field_department','field_phone'
        ] as $key) {
            $settings[$key] = (string)($settings[$key] ?? '');
        }

        $settings['ssl_verify'] = false;
        $settings['field_address'] =
            is_array($settings['field_address'] ?? null)
            ? array_values($settings['field_address'])
            : [];

        $data['settings'] = $settings;
        survey_write($data);

        echo json_encode([
            'ok' => true,
            'settings' => $settings
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'kintone_fields') {
        $settings = survey_settings_from_data($data);

        $appId = (string)(
            $_POST['app_id'] ??
            $settings['app_id']
        );

        $result = survey_kintone_fields($settings, $appId);

        if ($result['success'] ?? false) {
            $fields = [];

            foreach (($result['data']['properties'] ?? []) as $code => $field) {
                $fields[] = [
                    'code' => $code,
                    'label' => (string)($field['label'] ?? $code),
                    'type' => (string)($field['type'] ?? '')
                ];
            }

            echo json_encode([
                'ok' => true,
                'fields' => $fields
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'ok' => false,
                'message' => $result['message'] ?? 'フィールド取得に失敗しました。'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'kintone_test') {
        $settings = survey_settings_from_data($data);
        $appId = (string)($_POST['app_id'] ?? $settings['app_id']);

        if ($appId === '') {
            echo json_encode([
                'ok' => false,
                'message' => 'アプリIDを入力してください。'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $result = survey_kintone_fields($settings, $appId);

        echo json_encode([
            'ok' => (bool)($result['success'] ?? false),
            'message' => ($result['success'] ?? false)
                ? 'kintoneへの接続に成功しました。'
                : ($result['message'] ?? '接続に失敗しました。'),
            'status' => $result['status'] ?? 500
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'sync_customers') {
        $settings = survey_settings_from_data($data);
        $result = survey_kintone_records(
            $settings,
            (string)$settings['app_id']
        );

        if (!($result['success'] ?? false)) {
            echo json_encode([
                'ok' => false,
                'message' => $result['message'] ?? '顧客取得に失敗しました。'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fields = $result['data']['records'] ?? [];
        $count = 0;

        foreach ($fields as $record) {
            $value = static function(array $r, string $code): string {
                if ($code === '' || !isset($r[$code])) {
                    return '';
                }

                $v = $r[$code]['value'] ?? '';

                if (is_array($v)) {
                    $parts = [];
                    foreach ($v as $item) {
                        if (is_array($item)) {
                            $parts[] = (string)($item['name'] ?? $item['value'] ?? '');
                        } else {
                            $parts[] = (string)$item;
                        }
                    }
                    return implode(' ', $parts);
                }

                return (string)$v;
            };

            $email = $value($record, $settings['field_email']);
            if ($email === '') {
                continue;
            }

            /*
             * 顧客データ自身の同期ではemailを照合キーとして使用可能。
             * これは「アンケートの一意識別子」とは別の業務処理。
             */
            $existing = -1;

            foreach ($data['customers'] as $i => $customer) {
                if ((string)($customer['email'] ?? '') === $email) {
                    $existing = $i;
                    break;
                }
            }

            $address = [];
            foreach ($settings['field_address'] as $code) {
                $v = $value($record, (string)$code);
                if ($v !== '') {
                    $address[] = $v;
                }
            }

            $customer = [
                'id' => $existing >= 0
                    ? $data['customers'][$existing]['id']
                    : survey_uuid('customer_'),
                'company' => $value($record, $settings['field_company']),
                'name' => $value($record, $settings['field_name']),
                'email' => $email,
                'department' => $value($record, $settings['field_department']),
                'phone' => $value($record, $settings['field_phone']),
                'address' => implode(' ', $address),
                'source' => 'kintone',
                'sent_at' => $existing >= 0
                    ? ($data['customers'][$existing]['sent_at'] ?? '')
                    : '',
                'send_count' => $existing >= 0
                    ? (int)($data['customers'][$existing]['send_count'] ?? 0)
                    : 0,
                'answer_status' => $existing >= 0
                    ? ($data['customers'][$existing]['answer_status'] ?? 'unanswered')
                    : 'unanswered',
                'kintone_status' => 'registered'
            ];

            if ($existing >= 0) {
                $data['customers'][$existing] = $customer;
            } else {
                $data['customers'][] = $customer;
            }

            $count++;
        }

        survey_write($data);

        echo json_encode([
            'ok' => true,
            'count' => $count
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'send_mail') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $recipientIds = json_decode(
            (string)($_POST['recipient_ids'] ?? '[]'),
            true
        );

        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

        $subject = (string)($_POST['mail_subject'] ?? '');
        $body = (string)($_POST['mail_body'] ?? '');
        $template = (string)($_POST['template_type'] ?? 'initial');

        if (!in_array($template, ['initial','reminder'], true)) {
            $template = 'initial';
        }

        $surveyIndex = null;

        foreach ($data['surveys'] as $i => $survey) {
            if ((string)($survey['id'] ?? '') === $surveyId) {
                $surveyIndex = $i;
                break;
            }
        }

        if ($surveyIndex === null) {
            echo json_encode([
                'ok' => false,
                'message' => 'アンケートが存在しません。'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sent = 0;
        $messages = [];

        foreach ($recipientIds as $customerId) {
            foreach ($data['customers'] as $i => $customer) {
                if ((string)($customer['id'] ?? '') !== (string)$customerId) {
                    continue;
                }

                if ((string)($customer['source'] ?? '') === 'web') {
                    continue;
                }

                $name = (string)($customer['name'] ?? '');
                $personalUrl =
                    rtrim(
                        (string)(
                            ($_SERVER['HTTPS'] ?? '') !== ''
                            ? 'https://' . $_SERVER['HTTP_HOST']
                            : 'http://' . $_SERVER['HTTP_HOST']
                        ),
                        '/'
                    ) .
                    $_SERVER['SCRIPT_NAME'] .
                    '?respond=1&survey_id=' .
                    rawurlencode($surveyId) .
                    '&customer_id=' .
                    rawurlencode((string)$customer['id']);

                $realSubject = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [$name, $personalUrl],
                    $subject
                );

                $realBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [$name, $personalUrl],
                    $body
                );

                /*
                 * PHP mail()が利用可能な環境では実メール送信。
                 * メール機能が無効な環境でも送信履歴は保持する。
                 */
                $mailSent = false;

                if (filter_var($customer['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    $headers = [
                        'MIME-Version: 1.0',
                        'Content-Type: text/plain; charset=UTF-8',
                        'From: ' . ($_SERVER['SERVER_ADMIN'] ?? 'no-reply@localhost')
                    ];

                    $mailSent = @mail(
                        (string)$customer['email'],
                        '=?UTF-8?B?' .
                            base64_encode($realSubject) .
                            '?=',
                        $realBody,
                        implode("\r\n", $headers)
                    );
                }

                $data['customers'][$i]['sent_at'] = survey_now();
                $data['customers'][$i]['send_count'] =
                    (int)($data['customers'][$i]['send_count'] ?? 0) + 1;

                $data['mail_logs'][] = [
                    'id' => survey_uuid('mail_'),
                    'survey_id' => $surveyId,
                    'customer_id' => $customer['id'],
                    'sent_at' => survey_now(),
                    'template_type' => $template,
                    'subject' => $realSubject,
                    'body' => $realBody,
                    'sent' => $mailSent,
                    'executed_by' => $_SESSION['user'] ?? 'admin'
                ];

                $messages[] = [
                    'customer_id' => $customer['id'],
                    'subject' => $realSubject,
                    'body' => $realBody,
                    'sent' => $mailSent
                ];

                $sent++;
                break;
            }
        }

        survey_write($data);

        echo json_encode([
            'ok' => true,
            'sent' => $sent,
            'messages' => $messages
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'register_customer') {
        $customerId = (string)($_POST['customer_id'] ?? '');

        foreach ($data['customers'] as $i => $customer) {
            if ((string)($customer['id'] ?? '') === $customerId) {
                $data['customers'][$i]['kintone_status'] = 'registered';
            }
        }

        survey_write($data);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_response') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $customerId = (string)($_POST['customer_id'] ?? '');
        $answers = json_decode(
            (string)($_POST['answers'] ?? '{}'),
            true
        );

        if (!is_array($answers)) {
            $answers = [];
        }

        $surveyExists = false;

        foreach ($data['surveys'] as $survey) {
            if ((string)($survey['id'] ?? '') === $surveyId &&
                empty($survey['deleted'])) {
                $surveyExists = true;
                break;
            }
        }

        if (!$surveyExists) {
            echo json_encode([
                'ok' => false,
                'message' => 'アンケートが存在しません。'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $customer = null;

        foreach ($data['customers'] as $i => $c) {
            if ((string)($c['id'] ?? '') === $customerId) {
                $customer = $c;
                $data['customers'][$i]['answer_status'] = 'answered';
                break;
            }
        }

        if ($customer === null) {
            $customer = [
                'id' => survey_uuid('customer_'),
                'company' => (string)($_POST['company'] ?? ''),
                'name' => (string)($_POST['name'] ?? ''),
                'email' => (string)($_POST['email'] ?? ''),
                'department' => '',
                'phone' => '',
                'address' => '',
                'source' => 'web',
                'sent_at' => '',
                'send_count' => 0,
                'answer_status' => 'answered',
                'kintone_status' => 'unregistered'
            ];
            $data['customers'][] = $customer;
            $customerId = $customer['id'];
        }

        $data['responses'][] = [
            'id' => survey_uuid('response_'),
            'survey_id' => $surveyId,
            'customer_id' => $customerId,
            'company' => $customer['company'],
            'name' => $customer['name'],
            'email' => $customer['email'],
            'answered_at' => survey_now(),
            'answers' => $answers
        ];

        survey_write($data);

        echo json_encode([
            'ok' => true
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'csv') {
        $surveyId = (string)($_GET['survey_id'] ?? '');

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if ((string)($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if ($survey === null) {
            http_response_code(404);
            exit;
        }

        $questions = [];

        foreach ($survey['groups'] ?? [] as $group) {
            foreach ($group['questions'] ?? [] as $q) {
                $questions[] = $q;
            }
        }

        $filename = 'survey_' . $surveyId . '_responses.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="' .
            rawurlencode($filename) . '"'
        );

        $fp = fopen('php://output', 'wb');

        /* UTF-8 BOM */
        fwrite($fp, "\xEF\xBB\xBF");

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名'
        ];

        foreach ($questions as $q) {
            $header[] = (string)($q['text'] ?? '');
        }

        fputcsv($fp, $header);

        foreach ($data['responses'] as $response) {
            if ((string)($response['survey_id'] ?? '') !== $surveyId) {
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
                $qid = (string)($q['id'] ?? '');
                $value = $response['answers'][$qid] ?? '';

                if (is_array($value)) {
                    $value = implode(', ', array_map(
                        static fn($v): string => (string)$v,
                        $value
                    ));
                }

                $row[] = (string)$value;
            }

            fputcsv($fp, $row);
        }

        fclose($fp);
        exit;
    }

    echo json_encode([
        'ok' => false,
        'message' => '不明なactionです。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = survey_csrf();

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

<body class="bg-slate-50 text-slate-800 min-h-screen">

<div id="app"></div>

<input
    type="hidden"
    id="csrf_token"
    value="<?= survey_h($csrf) ?>"
>

<script>
window.App = {
    State: {
        initialized: false,
        loading: false,
        view: 'surveys',
        surveyId: null,
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        draftSurvey: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        customerFilter: '',
        responseFilter: '',
        selectedRecipients: [],
        selectedQuestions: [],
        previewMobile: false,
        dirty: false,
        kintoneFields: [],
        notice: null
    },

    Render: {},
    actions: {},
    API: {},
    Utils: {}
};

App.Utils.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.Utils.clone = function(value) {
    return JSON.parse(JSON.stringify(value));
};

App.Utils.uid = function(prefix) {
    return prefix + Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 10);
};

App.Utils.statusLabel = function(status) {
    return {
        active: '公開中',
        draft: '下書き',
        ended: '終了'
    }[status] || status;
};

App.Utils.statusClass = function(status) {
    return {
        active: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        draft: 'bg-amber-50 text-amber-700 border-amber-200',
        ended: 'bg-slate-100 text-slate-600 border-slate-200'
    }[status] || 'bg-slate-100 text-slate-600';
};

App.Utils.formatDate = function(value) {
    if (!value) return '未設定';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('ja-JP', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
};

App.Utils.notify = function(message, type='success') {
    App.State.notice = {message, type};
    App.Render.all();
    setTimeout(function() {
        App.State.notice = null;
        App.Render.all();
    }, 3000);
};

App.API.request = async function(action, params={}, method='POST') {
    const fd = new FormData();

    fd.append('action', action);
    fd.append(
        'csrf_token',
        document.getElementById('csrf_token').value
    );

    Object.entries(params).forEach(function([key, value]) {
        if (value === undefined || value === null) return;

        if (typeof value === 'object') {
            fd.append(key, JSON.stringify(value));
        } else {
            fd.append(key, String(value));
        }
    });

    let url = location.pathname;

    if (method === 'GET') {
        const query = new URLSearchParams();
        query.set('action', action);

        Object.entries(params).forEach(function([key, value]) {
            query.set(key, String(value));
        });

        url += '?' + query.toString();

        const response = await fetch(url, {
            credentials: 'same-origin'
        });

        return response;
    }

    const response = await fetch(url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    });

    if (!response.ok) {
        throw new Error('HTTP ' + response.status);
    }

    return response.json();
};

App.API.load = async function() {
    const result = await App.API.request('load', {}, 'GET');

    if (!result.ok) {
        throw new Error(result.message || '読み込みに失敗しました。');
    }

    App.State.data = result.data;
};

App.API.saveSurvey = async function(survey) {
    return await App.API.request('save_survey', {
        survey_json: survey
    });
};

App.API.saveSettings = async function(settings) {
    return await App.API.request('save_settings', {
        settings_json: settings
    });
};

App.actions.navigate = function(view) {
    if (App.State.dirty &&
        !confirm('未保存の変更があります。移動しますか？')) {
        return;
    }

    App.State.dirty = false;
    App.State.view = view;
    App.State.surveyId = null;
    App.State.draftSurvey = null;
    App.Render.all();
};

App.actions.newSurvey = function() {
    App.State.draftSurvey = {
        id: App.Utils.uid('survey_'),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        numbering_mode: 'global',
        groups: [
            {
                id: App.Utils.uid('group_'),
                name: '基本情報',
                questions: []
            }
        ],
        deleted: false
    };

    App.State.surveyId = App.State.draftSurvey.id;
    App.State.view = 'editor';
    App.State.dirty = false;
    App.Render.all();
    setTimeout(App.actions.initSortable, 0);
};

App.actions.editSurvey = function(id) {
    const survey = App.State.data.surveys.find(
        s => String(s.id) === String(id) && !s.deleted
    );

    if (!survey) return;

    App.State.draftSurvey = App.Utils.clone(survey);
    App.State.surveyId = survey.id;
    App.State.view = 'editor';
    App.State.dirty = false;
    App.Render.all();
    setTimeout(App.actions.initSortable, 0);
};

App.actions.viewResponses = function(id) {
    App.State.surveyId = id;
    App.State.view = 'analytics';

    const survey = App.State.data.surveys.find(
        s => String(s.id) === String(id)
    );

    const questions = App.actions.getQuestions(survey);
    App.State.selectedQuestions = questions.map(q => q.id);

    App.Render.all();
};

App.actions.openSend = function(id) {
    App.State.surveyId = id;
    App.State.view = 'send';
    App.State.selectedRecipients = [];
    App.Render.all();
};

App.actions.changeStatus = async function(id, status) {
    const label = status === 'active' ? '公開' : '停止';

    if (!confirm(label + 'しますか？')) return;

    const result = await App.API.request('change_status', {
        survey_id: id,
        status: status
    });

    if (result.ok) {
        await App.API.load();
        App.Render.all();
        App.Utils.notify('ステータスを変更しました。');
    } else {
        App.Utils.notify(result.message || '変更に失敗しました。', 'error');
    }
};

App.actions.deleteSurvey = async function(id) {
    if (!confirm('この下書きを削除しますか？')) return;

    const result = await App.API.request('delete_survey', {
        survey_id: id
    });

    if (result.ok) {
        await App.API.load();
        App.Render.all();
        App.Utils.notify('削除しました。');
    }
};

App.actions.duplicateSurvey = async function(id) {
    const result = await App.API.request('duplicate_survey', {
        survey_id: id
    });

    if (result.ok) {
        await App.API.load();
        App.Render.all();
        App.Utils.notify('新しい下書きを作成しました。');
    }
};

App.actions.updateEditor = function() {
    const s = App.State.draftSurvey;
    if (!s) return;

    s.title = document.getElementById('survey_title')?.value || '';
    s.start_at = document.getElementById('survey_start_at')?.value || '';
    s.end_at = document.getElementById('survey_end_at')?.value || '';
    s.numbering_mode =
        document.getElementById('survey_numbering_mode')?.value || 'global';

    App.State.dirty = true;
};

App.actions.saveSurvey = async function() {
    App.actions.updateEditor();

    const result = await App.API.saveSurvey(
        App.State.draftSurvey
    );

    if (!result.ok) {
        App.Utils.notify(result.message || '保存に失敗しました。', 'error');
        return;
    }

    App.State.dirty = false;
    await App.API.load();
    App.State.view = 'surveys';
    App.State.surveyId = null;
    App.State.draftSurvey = null;
    App.Render.all();

    App.Utils.notify('アンケートを保存しました。');
};

App.actions.cancelEditor = function() {
    if (App.State.dirty &&
        !confirm('変更を破棄して一覧へ戻りますか？')) {
        return;
    }

    App.State.dirty = false;
    App.State.view = 'surveys';
    App.State.draftSurvey = null;
    App.Render.all();
};

App.actions.addGroup = function() {
    App.State.draftSurvey.groups.push({
        id: App.Utils.uid('group_'),
        name: '新しいグループ',
        questions: []
    });

    App.State.dirty = true;
    App.Render.editor();
    setTimeout(App.actions.initSortable, 0);
};

App.actions.deleteGroup = function(groupId) {
    if (!confirm('グループと含まれる質問を削除しますか？')) {
        return;
    }

    App.State.draftSurvey.groups =
        App.State.draftSurvey.groups.filter(
            g => g.id !== groupId
        );

    App.State.dirty = true;
    App.Render.editor();
    setTimeout(App.actions.initSortable, 0);
};

App.actions.addQuestion = function(groupId) {
    const group = App.State.draftSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions.push({
        id: App.Utils.uid('question_'),
        text: '新しい質問',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false
    });

    App.State.dirty = true;
    App.Render.editor();
    setTimeout(App.actions.initSortable, 0);
};

App.actions.deleteQuestion = function(groupId, questionId) {
    const group = App.State.draftSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions = group.questions.filter(
        q => q.id !== questionId
    );

    App.State.dirty = true;
    App.Render.editor();
    setTimeout(App.actions.initSortable, 0);
};

App.actions.updateGroup = function(groupId, value) {
    const group = App.State.draftSurvey.groups.find(
        g => g.id === groupId
    );

    if (group) {
        group.name = value;
        App.State.dirty = true;
    }
};

App.actions.updateQuestion = function(groupId, questionId, key, value) {
    const group = App.State.draftSurvey.groups.find(
        g => g.id === groupId
    );

    const q = group?.questions.find(
        x => x.id === questionId
    );

    if (!q) return;

    if (key === 'required') {
        q.required = value === true || value === 'true';
    } else {
        q[key] = value;
    }

    App.State.dirty = true;
};

App.actions.addOption = function(groupId, questionId) {
    const group = App.State.draftSurvey.groups.find(
        g => g.id === groupId
    );

    const q = group?.questions.find(
        x => x.id === questionId
    );

    if (!q) return;

    q.options = Array.isArray(q.options) ? q.options : [];
    q.options.push('新しい選択肢');

    App.State.dirty = true;
    App.Render.editor();
    setTimeout(App.actions.initSortable, 0);
};

App.actions.removeOption = function(groupId, questionId, index) {
    const group = App.State.draftSurvey.groups.find(
        g => g.id === groupId
    );

    const q = group?.questions.find(
        x => x.id === questionId
    );

    if (!q || !Array.isArray(q.options)) return;

    q.options.splice(index, 1);
    App.State.dirty = true;
    App.Render.editor();
    setTimeout(App.actions.initSortable, 0);
};

App.actions.updateOption = function(
    groupId,
    questionId,
    index,
    value
) {
    const group = App.State.draftSurvey.groups.find(
        g => g.id === groupId
    );

    const q = group?.questions.find(
        x => x.id === questionId
    );

    if (!q || !Array.isArray(q.options)) return;

    q.options[index] = value;
    App.State.dirty = true;
};

App.actions.getQuestions = function(survey) {
    if (!survey) return [];

    const result = [];

    (survey.groups || []).forEach(function(group) {
        (group.questions || []).forEach(function(q) {
            result.push({
                ...q,
                group_id: group.id,
                group_name: group.name
            });
        });
    });

    return result;
};

App.actions.questionNumber = function(
    survey,
    groupIndex,
    questionIndex
) {
    if (survey.numbering_mode === 'group') {
        return 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1);
    }

    let n = 0;

    for (let i = 0; i <= groupIndex; i++) {
        n += (survey.groups[i].questions || []).length;

        if (i === groupIndex) {
            return 'Q' + n;
        }
    }

    return 'Q1';
};

App.actions.initSortable = function() {
    if (typeof Sortable === 'undefined') return;

    const editor = document.getElementById('question_editor');
    if (!editor) return;

    if (editor._sortable) {
        editor._sortable.destroy();
    }

    editor._sortable = new Sortable(editor, {
        animation: 180,
        handle: '.group-handle',
        ghostClass: 'opacity-30',
        onEnd: function(evt) {
            const groups = App.State.draftSurvey.groups;
            const item = groups.splice(evt.oldIndex, 1)[0];
            groups.splice(evt.newIndex, 0, item);

            App.State.dirty = true;
            App.Render.editor();
            setTimeout(App.actions.initSortable, 0);
        }
    });

    document.querySelectorAll('.question-list').forEach(
        function(el) {
            if (el._sortable) {
                el._sortable.destroy();
            }

            el._sortable = new Sortable(el, {
                group: 'surveyQuestions',
                animation: 180,
                handle: '.question-handle',
                ghostClass: 'opacity-30',
                onEnd: function(evt) {
                    const fromId = evt.from.dataset.groupId;
                    const toId = evt.to.dataset.groupId;

                    const from =
                        App.State.draftSurvey.groups.find(
                            g => g.id === fromId
                        );

                    const to =
                        App.State.draftSurvey.groups.find(
                            g => g.id === toId
                        );

                    if (!from || !to) return;

                    const moved = from.questions.splice(
                        evt.oldIndex,
                        1
                    )[0];

                    if (!moved) return;

                    to.questions.splice(
                        evt.newIndex,
                        0,
                        moved
                    );

                    App.State.dirty = true;

                    App.Render.editor();
                    setTimeout(App.actions.initSortable, 0);
                }
            });
        }
    );
};

App.actions.preview = function() {
    App.Render.preview();
    document.getElementById('preview_modal').classList.remove('hidden');
};

App.actions.closePreview = function() {
    document.getElementById('preview_modal').classList.add('hidden');
};

App.actions.togglePreviewMode = function() {
    App.State.previewMobile = !App.State.previewMobile;
    App.Render.preview();
};

App.actions.previewSubmit = function() {
    alert('これはプレビューです。実際の回答送信は行われません。');
};

App.actions.toggleQuestion = function(id, checked) {
    if (checked) {
        if (!App.State.selectedQuestions.includes(id)) {
            App.State.selectedQuestions.push(id);
        }
    } else {
        App.State.selectedQuestions =
            App.State.selectedQuestions.filter(x => x !== id);
    }

    App.Render.analytics();
};

App.actions.selectAllQuestions = function(flag) {
    const survey = App.State.data.surveys.find(
        s => String(s.id) === String(App.State.surveyId)
    );

    const questions = App.actions.getQuestions(survey);

    App.State.selectedQuestions = flag
        ? questions.map(q => q.id)
        : [];

    App.Render.analytics();
};

App.actions.showResponse = function(id) {
    const response = App.State.data.responses.find(
        r => String(r.id) === String(id)
    );

    if (!response) return;

    const el = document.getElementById('response_detail');

    const survey = App.State.data.surveys.find(
        s => String(s.id) === String(response.survey_id)
    );

    const questions = App.actions.getQuestions(survey);

    el.innerHTML = `
        <div class="space-y-4">
            <div>
                <div class="text-xs text-slate-400">回答者</div>
                <div class="font-semibold">
                    ${App.Utils.escape(response.company)}
                    /
                    ${App.Utils.escape(response.name)}
                </div>
            </div>
            ${questions.map(q => `
                <div class="border-b border-slate-100 pb-3">
                    <div class="text-sm font-medium">
                        ${App.Utils.escape(q.text)}
                    </div>
                    <div class="mt-1 text-slate-600 whitespace-pre-wrap">
                        ${App.Utils.escape(
                            Array.isArray(response.answers?.[q.id])
                                ? response.answers[q.id].join(', ')
                                : response.answers?.[q.id] ?? ''
                        )}
                    </div>
                </div>
            `).join('')}
        </div>
    `;

    document.getElementById('response_modal')
        .classList.remove('hidden');
};

App.actions.closeResponse = function() {
    document.getElementById('response_modal')
        .classList.add('hidden');
};

App.actions.toggleRecipient = function(id, checked) {
    if (checked) {
        if (!App.State.selectedRecipients.includes(id)) {
            App.State.selectedRecipients.push(id);
        }
    } else {
        App.State.selectedRecipients =
            App.State.selectedRecipients.filter(x => x !== id);
    }
};

App.actions.toggleAllRecipients = function(checked) {
    const customers = App.actions.filteredCustomers();

    App.State.selectedRecipients = checked
        ? customers
            .filter(c => c.source !== 'web')
            .map(c => c.id)
        : [];

    App.Render.send();
};

App.actions.filteredCustomers = function() {
    const keyword =
        App.State.customerFilter.trim().toLowerCase();

    return App.State.data.customers.filter(function(c) {
        if (!keyword) return true;

        return [
            c.company,
            c.name,
            c.email,
            c.phone,
            c.address
        ].join(' ').toLowerCase().includes(keyword);
    });
};

App.actions.sendMail = async function() {
    const ids = App.State.selectedRecipients;

    if (!ids.length) {
        alert('送信対象を選択してください。');
        return;
    }

    const alreadySent = ids.some(function(id) {
        const c = App.State.data.customers.find(
            x => String(x.id) === String(id)
        );
        return Number(c?.send_count || 0) > 0;
    });

    let templateType =
        document.getElementById('template_type')?.value || 'initial';

    if (alreadySent) {
        if (!confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )) {
            return;
        }

        templateType = 'reminder';
        document.getElementById('template_type').value =
            'reminder';
    }

    const subject =
        document.getElementById('mail_subject').value;

    const body =
        document.getElementById('mail_body').value;

    if (!subject.trim() || !body.trim()) {
        alert('件名と本文を入力してください。');
        return;
    }

    const result = await App.API.request('send_mail', {
        survey_id: App.State.surveyId,
        recipient_ids: ids,
        mail_subject: subject,
        mail_body: body,
        template_type: templateType
    });

    if (result.ok) {
        await App.API.load();
        App.State.selectedRecipients = [];
        App.Render.send();
        App.Utils.notify(
            result.sent + '件の送信処理を実行しました。'
        );
    } else {
        App.Utils.notify(
            result.message || '送信に失敗しました。',
            'error'
        );
    }
};

App.actions.remindUnanswered = function() {
    const customers = App.actions.filteredCustomers()
        .filter(c =>
            Number(c.send_count || 0) > 0 &&
            c.answer_status !== 'answered' &&
            c.source !== 'web'
        );

    App.State.selectedRecipients = customers.map(c => c.id);

    App.Render.send();

    const template = document.getElementById('template_type');
    if (template) template.value = 'reminder';

    App.Utils.notify(
        customers.length + '件をリマインド対象にしました。'
    );
};

App.actions.registerCustomer = async function(id) {
    const result = await App.API.request(
        'register_customer',
        {customer_id: id}
    );

    if (result.ok) {
        await App.API.load();
        App.Render.send();
        App.Utils.notify('登録完了状態に変更しました。');
    }
};

App.actions.syncCustomers = async function() {
    if (!confirm(
        'kintoneから顧客管理アプリのデータを取得しますか？'
    )) return;

    const result = await App.API.request(
        'sync_customers',
        {}
    );

    if (result.ok) {
        await App.API.load();
        App.Render.send();
        App.Utils.notify(
            result.count + '件の顧客データを同期しました。'
        );
    } else {
        App.Utils.notify(
            result.message || '同期に失敗しました。',
            'error'
        );
    }
};

App.actions.fetchKintoneFields = async function() {
    const appId =
        document.getElementById('setting_app_id')?.value || '';

    const settings = {
        ...App.State.data.settings,
        app_id: appId,
        subdomain:
            document.getElementById('setting_subdomain')?.value || '',
        login_name:
            document.getElementById('setting_login_name')?.value || '',
        password:
            document.getElementById('setting_password')?.value || '',
        proxy:
            document.getElementById('setting_proxy')?.value || ''
    };

    const result = await App.API.request(
        'kintone_fields',
        {app_id: appId}
    );

    const message = document.getElementById('field_message');

    if (!result.ok) {
        if (message) {
            message.textContent =
                result.message || '取得に失敗しました。';
            message.className =
                'mt-2 text-sm text-red-600';
        }
        return;
    }

    App.State.kintoneFields = result.fields || [];

    if (message) {
        message.textContent =
            App.State.kintoneFields.length +
            '項目を取得しました。';
        message.className =
            'mt-2 text-sm text-emerald-600';
    }

    App.Render.settings();
};

App.actions.saveSettings = async function() {
    const address =
        [...document.querySelectorAll(
            'input[name="field_address[]"]:checked'
        )].map(x => x.value);

    const settings = {
        subdomain:
            document.getElementById('setting_subdomain').value,
        login_name:
            document.getElementById('setting_login_name').value,
        password:
            document.getElementById('setting_password').value,
        app_id:
            document.getElementById('setting_app_id').value,
        ssl_verify: false,
        proxy:
            document.getElementById('setting_proxy').value,
        field_company:
            document.getElementById('field_company').value,
        field_name:
            document.getElementById('field_name').value,
        field_email:
            document.getElementById('field_email').value,
        field_department:
            document.getElementById('field_department').value,
        field_phone:
            document.getElementById('field_phone').value,
        field_address: address
    };

    const result =
        await App.API.saveSettings(settings);

    if (result.ok) {
        App.State.data.settings = settings;
        App.Utils.notify('設定を保存しました。');
    } else {
        App.Utils.notify(
            result.message || '保存に失敗しました。',
            'error'
        );
    }
};

App.actions.testKintone = async function() {
    const appId =
        document.getElementById('setting_app_id').value;

    const result = await App.API.request(
        'kintone_test',
        {app_id: appId}
    );

    alert(result.message || '接続結果を取得できませんでした。');
};

App.actions.answer = function() {
    const surveyId =
        new URLSearchParams(location.search).get('survey_id');

    App.State.surveyId = surveyId;
    App.State.view = 'answer';
    App.Render.all();
};

App.actions.submitAnswer = async function() {
    const survey =
        App.State.data.surveys.find(
            s => String(s.id) === String(App.State.surveyId)
        );

    if (!survey) return;

    const answers = {};

    for (const group of survey.groups || []) {
        for (const q of group.questions || []) {
            const nodes =
                document.querySelectorAll(
                    `[data-answer="${q.id}"]`
                );

            if (q.type === 'multiple') {
                answers[q.id] =
                    [...nodes]
                    .filter(n => n.checked)
                    .map(n => n.value);
            } else if (q.type === 'single') {
                const selected =
                    [...nodes].find(n => n.checked);

                answers[q.id] =
                    selected ? selected.value : '';
            } else {
                answers[q.id] =
                    nodes[0]?.value || '';
            }
        }
    }

    const customerId =
        new URLSearchParams(location.search)
            .get('customer_id') || '';

    const result = await App.API.request(
        'save_response',
        {
            survey_id: App.State.surveyId,
            customer_id: customerId,
            answers: answers
        }
    );

    if (result.ok) {
        alert('回答を送信しました。');
        location.href = location.pathname;
    } else {
        alert(result.message || '回答送信に失敗しました。');
    }
};

App.Render.layout = function(content, title='') {
    const notice = App.State.notice;

    return `
        <div class="min-h-screen">
            <header class="sticky top-0 z-40 border-b
                border-slate-200 bg-white/95 backdrop-blur">
                <div class="max-w-7xl mx-auto px-6 h-16
                    flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-indigo-600
                            text-white flex items-center justify-center
                            font-bold">
                            A
                        </div>
                        <div>
                            <div class="font-bold text-slate-900">
                                アンケート管理
                            </div>
                            ${title ? `
                            <div class="text-xs text-slate-400">
                                ${App.Utils.escape(title)}
                            </div>` : ''}
                        </div>
                    </div>

                    <nav class="flex gap-1">
                        <button
                            onclick="App.actions.navigate('surveys')"
                            class="px-4 py-2 rounded-lg text-sm
                                hover:bg-slate-100">
                            アンケート一覧
                        </button>
                        <button
                            onclick="App.actions.navigate('settings')"
                            class="px-4 py-2 rounded-lg text-sm
                                hover:bg-slate-100">
                            kintone連携設定
                        </button>
                    </nav>
                </div>
            </header>

            ${notice ? `
            <div class="fixed right-6 top-20 z-50 px-5 py-3
                rounded-xl shadow-lg text-sm
                ${notice.type === 'error'
                    ? 'bg-red-600 text-white'
                    : 'bg-slate-900 text-white'}">
                ${App.Utils.escape(notice.message)}
            </div>` : ''}

            <main class="max-w-7xl mx-auto px-6 py-8">
                ${content}
            </main>
        </div>
    `;
};

App.Render.surveys = function() {
    const state = App.State;

    let surveys = state.data.surveys.filter(
        s => !s.deleted
    );

    if (state.keyword) {
        surveys = surveys.filter(s =>
            String(s.title || '')
            .toLowerCase()
            .includes(state.keyword.toLowerCase())
        );
    }

    if (state.statusFilter !== 'all') {
        surveys = surveys.filter(
            s => s.status === state.statusFilter
        );
    }

    surveys.sort(function(a,b) {
        const responsesA =
            state.data.responses.filter(
                r => String(r.survey_id) === String(a.id)
            ).length;

        const responsesB =
            state.data.responses.filter(
                r => String(r.survey_id) === String(b.id)
            ).length;

        if (state.sort === 'updated_desc') {
            return String(b.updated_at).localeCompare(
                String(a.updated_at)
            );
        }

        if (state.sort === 'updated_asc') {
            return String(a.updated_at).localeCompare(
                String(b.updated_at)
            );
        }

        if (state.sort === 'answers_desc') {
            return responsesB - responsesA;
        }

        if (state.sort === 'answers_asc') {
            return responsesA - responsesB;
        }

        if (state.sort === 'start_desc') {
            return String(b.start_at || '')
                .localeCompare(String(a.start_at || ''));
        }

        return String(a.start_at || '')
            .localeCompare(String(b.start_at || ''));
    });

    const rows = surveys.map(function(s) {
        const count = state.data.responses.filter(
            r => String(r.survey_id) === String(s.id)
        ).length;

        let buttons = '';

        if (s.status === 'active') {
            buttons = `
                <button
                    onclick="App.actions.editSurvey('${s.id}')"
                    class="text-indigo-600">確認・編集</button>
                <button
                    onclick="App.actions.viewResponses('${s.id}')"
                    class="text-indigo-600">集計</button>
                <button
                    onclick="App.actions.openSend('${s.id}')"
                    class="text-indigo-600">送信</button>
                <button
                    onclick="App.actions.changeStatus('${s.id}','ended')"
                    class="text-slate-600">停止</button>
                <button
                    onclick="App.actions.duplicateSurvey('${s.id}')"
                    class="text-slate-600">複製</button>
            `;
        } else if (s.status === 'draft') {
            buttons = `
                <button
                    onclick="App.actions.editSurvey('${s.id}')"
                    class="text-indigo-600">確認・編集</button>
                <button
                    onclick="App.actions.deleteSurvey('${s.id}')"
                    class="text-red-600">削除</button>
                <button
                    onclick="App.actions.duplicateSurvey('${s.id}')"
                    class="text-slate-600">複製</button>
            `;
        } else {
            buttons = `
                <button
                    onclick="App.actions.editSurvey('${s.id}')"
                    class="text-indigo-600">確認・編集</button>
                <button
                    onclick="App.actions.viewResponses('${s.id}')"
                    class="text-indigo-600">集計</button>
                <button
                    onclick="App.actions.duplicateSurvey('${s.id}')"
                    class="text-slate-600">複製</button>
            `;
        }

        return `
        <tr class="border-t border-slate-100 hover:bg-slate-50">
            <td class="px-5 py-4">
                <div class="text-sm">
                    ${App.Utils.formatDate(s.created_at)}
                </div>
                <div class="text-xs text-slate-400 mt-1">
                    更新: ${App.Utils.formatDate(s.updated_at)}
                </div>
            </td>
            <td class="px-5 py-4 font-semibold">
                ${App.Utils.escape(s.title)}
            </td>
            <td class="px-5 py-4 text-sm text-slate-600">
                ${App.Utils.formatDate(s.start_at)}
                <br>～
                ${App.Utils.formatDate(s.end_at)}
            </td>
            <td class="px-5 py-4">
                <span class="inline-flex px-2.5 py-1 rounded-full
                    border text-xs font-medium
                    ${App.Utils.statusClass(s.status)}">
                    ${App.Utils.statusLabel(s.status)}
                </span>
            </td>
            <td class="px-5 py-4 text-right font-semibold">
                ${count} 件
            </td>
            <td class="px-5 py-4">
                <div class="flex flex-wrap gap-3 text-sm">
                    ${buttons}
                </div>
            </td>
        </tr>`;
    }).join('');

    return App.Render.layout(`
        <div class="flex items-end justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">
                    アンケート一覧
                </h1>
                <p class="text-sm text-slate-500 mt-1">
                    アンケートを独立した業務単位として管理します。
                </p>
            </div>

            <button
                onclick="App.actions.newSurvey()"
                class="px-5 py-3 rounded-xl bg-indigo-600
                    hover:bg-indigo-700 text-white font-semibold
                    shadow-sm">
                ＋ 新規アンケート作成
            </button>
        </div>

        <div class="bg-white rounded-2xl border
            border-slate-200 shadow-sm p-4 mb-5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <input
                    value="${App.Utils.escape(state.keyword)}"
                    onkeydown="
                        if(event.key==='Enter'){
                            App.State.keyword=this.value;
                            App.Render.surveys();
                        }
                    "
                    onchange="
                        App.State.keyword=this.value;
                        App.Render.surveys();
                    "
                    placeholder="タイトルを検索"
                    class="border border-slate-200 rounded-xl
                        px-4 py-2.5 outline-none
                        focus:ring-2 focus:ring-indigo-200">

                <select
                    onchange="
                        App.State.statusFilter=this.value;
                        App.Render.surveys();
                    "
                    class="border border-slate-200 rounded-xl px-4 py-2.5">
                    <option value="all"
                        ${state.statusFilter==='all'?'selected':''}>
                        すべて
                    </option>
                    <option value="active"
                        ${state.statusFilter==='active'?'selected':''}>
                        公開中
                    </option>
                    <option value="draft"
                        ${state.statusFilter==='draft'?'selected':''}>
                        下書き
                    </option>
                    <option value="ended"
                        ${state.statusFilter==='ended'?'selected':''}>
                        終了
                    </option>
                </select>

                <select
                    onchange="
                        App.State.sort=this.value;
                        App.Render.surveys();
                    "
                    class="border border-slate-200 rounded-xl px-4 py-2.5">
                    <option value="updated_desc">更新日：新しい順</option>
                    <option value="updated_asc">更新日：古い順</option>
                    <option value="answers_desc">回答数：多い順</option>
                    <option value="answers_asc">回答数：少ない順</option>
                    <option value="start_desc">期間開始：新しい順</option>
                    <option value="start_asc">期間開始：古い順</option>
                </select>
            </div>
        </div>

        <div class="bg-white rounded-2xl border
            border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1100px] text-left">
                    <thead class="bg-slate-50 text-xs
                        text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-3">作成日 / 更新日</th>
                            <th class="px-5 py-3">タイトル</th>
                            <th class="px-5 py-3">アンケート期間</th>
                            <th class="px-5 py-3">ステータス</th>
                            <th class="px-5 py-3 text-right">回答数</th>
                            <th class="px-5 py-3">操作</th>
                        </tr>
                    </thead>
                    <tbody>${rows || `
                        <tr>
                            <td colspan="6"
                                class="px-5 py-16 text-center
                                text-slate-400">
                                アンケートがありません。
                            </td>
                        </tr>`}
                    </tbody>
                </table>
            </div>
        </div>
    `);
};

App.Render.editor = function() {
    const s = App.State.draftSurvey;

    if (!s) return;

    const groups = s.groups.map(function(group, gi) {
        const questions = group.questions.map(
            function(q, qi) {
                const number =
                    App.actions.questionNumber(
                        s, gi, qi
                    );

                const options =
                    Array.isArray(q.options)
                        ? q.options
                        : [];

                return `
                <div
                    class="question-card bg-white border
                        border-slate-200 rounded-xl p-4"
                    data-question-id="${q.id}">

                    <div class="flex gap-3">
                        <div class="question-handle cursor-grab
                            text-slate-300 text-xl pt-2">
                            ⠿
                        </div>

                        <div class="flex-1 space-y-3">
                            <div class="flex items-center
                                justify-between gap-3">
                                <span class="text-xs font-bold
                                    text-indigo-600">
                                    ${number}
                                </span>

                                <button
                                    onclick="
                                    App.actions.deleteQuestion(
                                        '${group.id}','${q.id}'
                                    )"
                                    class="text-xs text-red-500">
                                    削除
                                </button>
                            </div>

                            <input
                                value="${App.Utils.escape(q.text)}"
                                onchange="
                                    App.actions.updateQuestion(
                                        '${group.id}',
                                        '${q.id}',
                                        'text',
                                        this.value
                                    )
                                "
                                class="w-full border border-slate-200
                                    rounded-lg px-3 py-2">

                            <select
                                onchange="
                                    App.actions.updateQuestion(
                                        '${group.id}',
                                        '${q.id}',
                                        'type',
                                        this.value
                                    );
                                    App.Render.editor();
                                    setTimeout(
                                        App.actions.initSortable,0
                                    );
                                "
                                class="border border-slate-200
                                    rounded-lg px-3 py-2">
                                <option value="single"
                                    ${q.type==='single'?'selected':''}>
                                    単一選択
                                </option>
                                <option value="multiple"
                                    ${q.type==='multiple'?'selected':''}>
                                    複数選択
                                </option>
                                <option value="text"
                                    ${q.type==='text'?'selected':''}>
                                    自由記述
                                </option>
                            </select>

                            <label class="flex items-center gap-2
                                text-sm">
                                <input type="checkbox"
                                    ${q.required?'checked':''}
                                    onchange="
                                        App.actions.updateQuestion(
                                            '${group.id}',
                                            '${q.id}',
                                            'required',
                                            this.checked
                                        )
                                    ">
                                必須回答
                            </label>

                            ${q.type !== 'text' ? `
                            <div class="space-y-2">
                                <div class="text-xs font-semibold
                                    text-slate-500">
                                    選択肢
                                </div>

                                ${options.map(
                                    function(opt, oi) {
                                        return `
                                        <div class="flex gap-2">
                                            <input
                                                value="${App.Utils.escape(opt)}"
                                                onchange="
                                                App.actions.updateOption(
                                                    '${group.id}',
                                                    '${q.id}',
                                                    ${oi},
                                                    this.value
                                                )"
                                                class="flex-1 border
                                                    border-slate-200
                                                    rounded-lg px-3 py-2">
                                            <button
                                                onclick="
                                                App.actions.removeOption(
                                                    '${group.id}',
                                                    '${q.id}',
                                                    ${oi}
                                                )"
                                                class="px-3 text-red-500">
                                                ×
                                            </button>
                                        </div>`;
                                    }
                                ).join('')}

                                <button
                                    onclick="
                                    App.actions.addOption(
                                        '${group.id}','${q.id}'
                                    )"
                                    class="text-sm text-indigo-600">
                                    ＋ 選択肢を追加
                                </button>

                                <label class="flex items-center gap-2
                                    text-sm">
                                    <input type="checkbox"
                                        ${q.other_enabled?'checked':''}
                                        onchange="
                                        App.actions.updateQuestion(
                                            '${group.id}',
                                            '${q.id}',
                                            'other_enabled',
                                            this.checked
                                        )">
                                    「その他」を許可
                                </label>
                            </div>` : ''}
                        </div>
                    </div>
                </div>`;
            }
        ).join('');

        return `
        <section class="group-card bg-slate-100/70
            rounded-2xl p-4" data-group-id="${group.id}">

            <div class="flex items-center gap-3 mb-4">
                <span class="group-handle cursor-grab
                    text-slate-400 text-xl">
                    ⠿
                </span>

                <input
                    value="${App.Utils.escape(group.name)}"
                    onchange="
                        App.actions.updateGroup(
                            '${group.id}',this.value
                        )
                    "
                    class="flex-1 bg-transparent border-0
                        border-b border-slate-200
                        focus:ring-0 px-1 py-2 font-bold text-lg">

                <button
                    onclick="
                    App.actions.deleteGroup('${group.id}')
                    "
                    class="text-sm text-red-500">
                    グループ削除
                </button>
            </div>

            <div
                class="question-list space-y-3"
                data-group-id="${group.id}">
                ${questions || `
                <div class="border border-dashed
                    border-slate-300 rounded-xl p-8
                    text-center text-slate-400 text-sm">
                    質問を追加してください
                </div>`}
            </div>

            <button
                onclick="
                App.actions.addQuestion('${group.id}')
                "
                class="mt-4 px-4 py-2 bg-white
                    border border-slate-200 rounded-lg
                    text-sm text-indigo-600">
                ＋ 質問を追加
            </button>
        </section>`;
    }).join('');

    App.app.innerHTML = App.Render.layout(`
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">
                    アンケート作成・編集
                </h1>
                <p class="text-sm text-slate-500 mt-1">
                    survey.id:
                    ${App.Utils.escape(s.id)}
                </p>
            </div>

            <div class="flex gap-2">
                <button
                    onclick="App.actions.preview()"
                    class="px-4 py-2 rounded-lg border
                        border-slate-200 bg-white">
                    プレビュー
                </button>
                <button
                    onclick="App.actions.cancelEditor()"
                    class="px-4 py-2 rounded-lg border
                        border-slate-200 bg-white">
                    キャンセル
                </button>
                <button
                    onclick="App.actions.saveSurvey()"
                    class="px-4 py-2 rounded-lg bg-indigo-600
                        text-white">
                    保存して一覧へ戻る
                </button>
            </div>
        </div>

        <div class="bg-white border border-slate-200
            rounded-2xl p-6 mb-5 shadow-sm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="text-sm font-semibold">
                        タイトル
                    </label>
                    <input
                        id="survey_title"
                        value="${App.Utils.escape(s.title)}"
                        onchange="App.actions.updateEditor()"
                        class="mt-2 w-full border border-slate-200
                            rounded-xl px-4 py-3 text-lg font-semibold">
                </div>

                <div>
                    <label class="text-sm font-semibold">
                        開始日時
                    </label>
                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        value="${App.Utils.escape(s.start_at)}"
                        onchange="App.actions.updateEditor()"
                        class="mt-2 w-full border
                            border-slate-200 rounded-xl px-4 py-2.5">
                </div>

                <div>
                    <label class="text-sm font-semibold">
                        終了日時
                    </label>
                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        value="${App.Utils.escape(s.end_at)}"
                        onchange="App.actions.updateEditor()"
                        class="mt-2 w-full border
                            border-slate-200 rounded-xl px-4 py-2.5">
                </div>

                <div>
                    <label class="text-sm font-semibold">
                        ステータス
                    </label>
                    <select
                        onchange="
                            App.State.draftSurvey.status=this.value;
                            App.State.dirty=true;
                        "
                        class="mt-2 w-full border
                            border-slate-200 rounded-xl px-4 py-2.5">
                        <option value="draft"
                            ${s.status==='draft'?'selected':''}>
                            下書き
                        </option>
                        <option value="active"
                            ${s.status==='active'?'selected':''}>
                            公開中
                        </option>
                        <option value="ended"
                            ${s.status==='ended'?'selected':''}>
                            終了
                        </option>
                    </select>
                </div>

                <div>
                    <label class="text-sm font-semibold">
                        質問番号形式
                    </label>
                    <select
                        id="survey_numbering_mode"
                        onchange="App.actions.updateEditor()"
                        class="mt-2 w-full border
                            border-slate-200 rounded-xl px-4 py-2.5">
                        <option value="global"
                            ${s.numbering_mode==='global'?'selected':''}>
                            Q1, Q2, Q3...
                        </option>
                        <option value="group"
                            ${s.numbering_mode==='group'?'selected':''}>
                            Q1-1, Q1-2...
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold">質問構成</h2>
            <button
                onclick="App.actions.addGroup()"
                class="px-4 py-2 rounded-lg bg-indigo-50
                    text-indigo-700 font-medium">
                ＋ グループ追加
            </button>
        </div>

        <div id="question_editor" class="space-y-5">
            ${groups}
        </div>
    `);

    App.app = document.getElementById('app');
};

App.Render.preview = function() {
    const s = App.State.draftSurvey;

    const content = (s.groups || []).map(function(group) {
        return `
            <section class="mb-8">
                <h3 class="font-bold text-lg mb-4">
                    ${App.Utils.escape(group.name)}
                </h3>

                ${(group.questions || []).map(function(q) {
                    return `
                    <div class="mb-6">
                        <label class="block font-semibold mb-2">
                            ${App.Utils.escape(q.text)}
                            ${q.required
                                ? '<span class="text-red-500"> *</span>'
                                : ''}
                        </label>

                        ${q.type === 'text' ? `
                            <textarea
                                data-answer="${q.id}"
                                class="w-full border border-slate-200
                                    rounded-xl px-3 py-3"
                                rows="4"></textarea>
                        ` : (q.options || []).map(function(opt) {
                            return `
                            <label class="flex items-center gap-2 mb-2">
                                <input
                                    data-answer="${q.id}"
                                    value="${App.Utils.escape(opt)}"
                                    type="${q.type==='multiple'
                                        ? 'checkbox'
                                        : 'radio'}"
                                    name="preview_${q.id}">
                                ${App.Utils.escape(opt)}
                            </label>`;
                        }).join('')}
                    </div>`;
                }).join('')}
            </section>`;
    }).join('');

    const modal = document.getElementById('preview_modal');

    modal.innerHTML = `
        <div class="fixed inset-0 z-50 bg-black/40
            flex items-center justify-center p-6">
            <div class="${App.State.previewMobile
                ? 'w-[390px]'
                : 'w-full max-w-3xl'}
                max-h-[90vh] overflow-auto bg-white
                rounded-2xl shadow-2xl">

                <div class="sticky top-0 bg-white border-b
                    border-slate-200 px-5 py-4 flex
                    items-center justify-between">
                    <div class="font-bold">
                        プレビュー
                    </div>
                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.togglePreviewMode()"
                            class="px-3 py-1.5 rounded-lg
                                bg-slate-100 text-sm">
                            ${App.State.previewMobile
                                ? 'PC表示'
                                : 'スマートフォン表示'}
                        </button>
                        <button
                            onclick="App.actions.closePreview()"
                            class="px-3 py-1.5 rounded-lg
                                bg-slate-100">
                            閉じる
                        </button>
                    </div>
                </div>

                <div id="preview_content" class="p-6">
                    <h1 class="text-2xl font-bold mb-8">
                        ${App.Utils.escape(s.title)}
                    </h1>

                    ${content}

                    <button
                        onclick="App.actions.previewSubmit()"
                        class="w-full py-3 rounded-xl
                            bg-indigo-600 text-white">
                        回答を送信
                    </button>
                </div>
            </div>
        </div>`;
};

App.Render.send = function() {
    const survey =
        App.State.data.surveys.find(
            s => String(s.id) === String(App.State.surveyId)
        );

    if (!survey) return;

    const customers = App.actions.filteredCustomers();

    const unregistered =
        customers.filter(
            c => c.kintone_status === 'unregistered'
        ).length;

    const rows = customers.map(function(c) {
        const selected =
            App.State.selectedRecipients.includes(c.id);

        const sent =
            Number(c.send_count || 0) > 0;

        return `
        <tr class="border-t border-slate-100">
            <td class="px-4 py-4">
                <input
                    type="checkbox"
                    ${selected?'checked':''}
                    ${c.source==='web'?'disabled':''}
                    onchange="
                        App.actions.toggleRecipient(
                            '${c.id}',this.checked
                        )
                    ">
            </td>
            <td class="px-4 py-4">
                <div class="font-semibold">
                    ${App.Utils.escape(c.company)}
                </div>
                <div>
                    ${App.Utils.escape(c.name)}
                </div>
            </td>
            <td class="px-4 py-4 text-sm">
                ${App.Utils.escape(c.email)}
            </td>
            <td class="px-4 py-4 text-sm">
                ${App.Utils.escape(c.phone)}
            </td>
            <td class="px-4 py-4 text-sm">
                ${App.Utils.escape(c.address)}
            </td>
            <td class="px-4 py-4 text-sm">
                ${c.sent_at
                    ? App.Utils.formatDate(c.sent_at)
                    : '未送信'}
                <br>
                <span class="text-slate-400">
                    ${c.send_count || 0}回
                </span>
            </td>
            <td class="px-4 py-4">
                <span class="px-2 py-1 rounded-full text-xs
                    ${c.answer_status==='answered'
                        ? 'bg-emerald-50 text-emerald-700'
                        : 'bg-amber-50 text-amber-700'}">
                    ${c.answer_status==='answered'
                        ? '回答済み'
                        : sent
                            ? '送信済み（未回答）'
                            : '未送信'}
                </span>
            </td>
            <td class="px-4 py-4">
                ${c.kintone_status==='registered'
                    ? `<span class="text-emerald-600 text-sm">
                        ✓ kintone登録完了
                       </span>`
                    : `<button
                        onclick="
                        App.actions.registerCustomer('${c.id}')
                        "
                        class="text-indigo-600 text-sm">
                        kintone登録完了
                       </button>`}
            </td>
        </tr>`;
    }).join('');

    const logs =
        App.State.data.mail_logs.filter(
            l => String(l.survey_id) === String(survey.id)
        ).slice().reverse().slice(0, 20);

    App.app.innerHTML = App.Render.layout(`
        <div class="mb-6">
            <button
                onclick="App.actions.navigate('surveys')"
                class="text-sm text-indigo-600 mb-3">
                ← アンケート一覧
            </button>
            <h1 class="text-2xl font-bold">
                顧客選択・送信・送信履歴
            </h1>
            <p class="text-slate-500 mt-1">
                ${App.Utils.escape(survey.title)}
            </p>
        </div>

        ${unregistered ? `
        <div class="mb-5 rounded-xl bg-amber-50
            border border-amber-200 p-4 text-amber-800">
            kintone未登録の回答者・顧客が
            ${unregistered}件あります。
        </div>` : ''}

        <div class="bg-white border border-slate-200
            rounded-2xl p-5 mb-5">
            <div class="flex gap-3 flex-wrap">
                <input
                    id="customer_filter"
                    value="${App.Utils.escape(
                        App.State.customerFilter
                    )}"
                    oninput="
                        App.State.customerFilter=this.value;
                        App.Render.send();
                    "
                    placeholder="顧客名・メール・電話・住所"
                    class="flex-1 min-w-[280px]
                        border border-slate-200 rounded-xl px-4 py-2.5">

                <button
                    onclick="App.actions.syncCustomers()"
                    class="px-4 py-2.5 rounded-xl
                        border border-slate-200">
                    kintone顧客更新
                </button>

                <button
                    onclick="App.actions.remindUnanswered()"
                    class="px-4 py-2.5 rounded-xl
                        bg-amber-50 text-amber-700">
                    未回答をリマインド
                </button>
            </div>
        </div>

        <div class="bg-white border border-slate-200
            rounded-2xl p-5 mb-5">
            <h2 class="font-bold mb-4">メールテンプレート</h2>

            <div class="space-y-3">
                <select
                    id="template_type"
                    class="border border-slate-200
                        rounded-xl px-4 py-2.5">
                    <option value="initial">初回送信</option>
                    <option value="reminder">再送・リマインド</option>
                </select>

                <input
                    id="mail_subject"
                    placeholder="件名"
                    value="【アンケートのお願い】{顧客名}様"
                    class="w-full border border-slate-200
                        rounded-xl px-4 py-2.5">

                <textarea
                    id="mail_body"
                    rows="6"
                    class="w-full border border-slate-200
                        rounded-xl px-4 py-3"
                    placeholder="本文">{顧客名}様

アンケートへのご協力をお願いいたします。

回答はこちら：
{アンケートURL}</textarea>

                <div class="text-xs text-slate-400">
                    使用可能な変数：
                    {顧客名} / {アンケートURL}
                </div>

                <button
                    onclick="App.actions.sendMail()"
                    class="px-5 py-3 rounded-xl
                        bg-indigo-600 text-white font-semibold">
                    選択した顧客へ一括送信
                </button>
            </div>
        </div>

        <div class="bg-white border border-slate-200
            rounded-2xl shadow-sm overflow-hidden mb-8">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1250px]">
                    <thead class="bg-slate-50 text-left text-xs
                        text-slate-500">
                        <tr>
                            <th class="px-4 py-3">
                                <input
                                    id="select_all"
                                    type="checkbox"
                                    onchange="
                                    App.actions.toggleAllRecipients(
                                        this.checked
                                    )">
                            </th>
                            <th class="px-4 py-3">会社 / 氏名</th>
                            <th class="px-4 py-3">メール</th>
                            <th class="px-4 py-3">電話</th>
                            <th class="px-4 py-3">住所</th>
                            <th class="px-4 py-3">送信履歴</th>
                            <th class="px-4 py-3">回答</th>
                            <th class="px-4 py-3">kintone</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows || `
                        <tr>
                            <td colspan="8"
                                class="p-10 text-center
                                text-slate-400">
                                顧客データがありません。
                            </td>
                        </tr>`}
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white border border-slate-200
            rounded-2xl p-5">
            <h2 class="font-bold mb-4">一括送信ログ</h2>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100">
                            <th class="text-left p-3">日時</th>
                            <th class="text-left p-3">種別</th>
                            <th class="text-left p-3">件名</th>
                            <th class="text-left p-3">送信</th>
                        </tr>
                    </thead>
                    <tbody>
                    ${logs.map(l => `
                        <tr class="border-b border-slate-100">
                            <td class="p-3">
                                ${App.Utils.formatDate(l.sent_at)}
                            </td>
                            <td class="p-3">
                                ${l.template_type==='reminder'
                                    ? 'リマインド'
                                    : '初回'}
                            </td>
                            <td class="p-3">
                                ${App.Utils.escape(l.subject)}
                            </td>
                            <td class="p-3">
                                ${l.sent ? '成功' : '処理記録'}
                            </td>
                        </tr>
                    `).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `);
};

App.Render.analytics = function() {
    const survey =
        App.State.data.surveys.find(
            s => String(s.id) === String(App.State.surveyId)
        );

    if (!survey) return;

    const questions =
        App.actions.getQuestions(survey);

    const responses =
        App.State.data.responses.filter(
            r => String(r.survey_id) === String(survey.id)
        );

    const sentCustomers =
        App.State.data.customers.filter(
            c =>
                Number(c.send_count || 0) > 0 &&
                c.source !== 'web'
        );

    const answeredCustomerIds =
        new Set(
            responses
            .filter(r => r.customer_id)
            .map(r => String(r.customer_id))
        );

    const sentAnswered =
        sentCustomers.filter(
            c => answeredCustomerIds.has(String(c.id))
        ).length;

    const unanswered =
        Math.max(0, sentCustomers.length - sentAnswered);

    const webResponses =
        responses.filter(function(r) {
            const c =
                App.State.data.customers.find(
                    x => String(x.id) === String(r.customer_id)
                );

            return c?.source === 'web';
        }).length;

    const rate =
        sentCustomers.length
            ? ((sentAnswered / sentCustomers.length) * 100)
                .toFixed(1)
            : '0.0';

    const selectedQuestions =
        questions.filter(q =>
            App.State.selectedQuestions.includes(q.id)
        );

    const questionCharts =
        selectedQuestions.map(function(q) {
            if (q.type === 'text') {
                const texts = responses
                    .map(r => ({
                        response: r,
                        value: r.answers?.[q.id]
                    }))
                    .filter(x =>
                        x.value !== undefined &&
                        String(x.value).trim() !== ''
                    );

                return `
                <section class="bg-white border
                    border-slate-200 rounded-2xl p-5">
                    <div class="flex items-center
                        justify-between mb-4">
                        <div>
                            <h3 class="font-bold">
                                ${App.Utils.escape(q.text)}
                            </h3>
                            <span class="text-xs
                                text-slate-400">
                                自由記述
                            </span>
                        </div>
                    </div>

                    <div class="max-h-80 overflow-auto space-y-3">
                    ${texts.map(x => `
                        <div class="border-l-2 border-indigo-400
                            pl-4 py-1">
                            <div class="text-xs text-slate-400">
                                ${App.Utils.escape(
                                    x.response.company
                                )}
                                /
                                ${App.Utils.escape(
                                    x.response.name
                                )}
                            </div>
                            <div class="mt-1 whitespace-pre-wrap">
                                ${App.Utils.escape(
                                    Array.isArray(x.value)
                                        ? x.value.join(', ')
                                        : x.value
                                )}
                            </div>
                        </div>
                    `).join('') || `
                        <div class="text-slate-400">
                            回答データがありません。
                        </div>`}
                    </div>
                </section>`;
            }

            const values = [];

            responses.forEach(function(r) {
                const v = r.answers?.[q.id];

                if (Array.isArray(v)) {
                    v.forEach(x => values.push(String(x)));
                } else if (v !== undefined && v !== '') {
                    values.push(String(v));
                }
            });

            const total = values.length || 0;

            return `
            <section class="bg-white border
                border-slate-200 rounded-2xl p-5">
                <div class="flex items-center
                    justify-between mb-5">
                    <div>
                        <h3 class="font-bold">
                            ${App.Utils.escape(q.text)}
                        </h3>
                        <span class="text-xs text-slate-400">
                            ${q.type==='single'
                                ? '単一選択'
                                : '複数選択'}
                        </span>
                    </div>
                </div>

                <div class="space-y-3">
                ${(q.options || []).map(function(opt) {
                    const count =
                        values.filter(v => v === opt).length;

                    const percent =
                        total
                            ? ((count / total) * 100).toFixed(1)
                            : '0.0';

                    return `
                    <div>
                        <div class="flex justify-between
                            text-sm mb-1">
                            <span>
                                ${App.Utils.escape(opt)}
                            </span>
                            <span>
                                ${count}件 /
                                ${percent}%
                            </span>
                        </div>

                        <div class="h-3 bg-slate-100
                            rounded-full overflow-hidden">
                            <div
                                class="h-full bg-indigo-500
                                    rounded-full"
                                style="width:${percent}%">
                            </div>
                        </div>
                    </div>`;
                }).join('')}
                </div>
            </section>`;
        }).join('');

    const filteredResponses =
        responses.filter(function(r) {
            const k = App.State.responseFilter
                .trim()
                .toLowerCase();

            if (!k) return true;

            return [
                r.company,
                r.name
            ].join(' ').toLowerCase().includes(k);
        });

    const responseRows =
        filteredResponses.map(function(r) {
            return `
            <tr class="border-t border-slate-100">
                <td class="px-4 py-3">
                    ${App.Utils.escape(r.company)}
                </td>
                <td class="px-4 py-3">
                    ${App.Utils.escape(r.name)}
                </td>
                <td class="px-4 py-3">
                    ${App.Utils.formatDate(r.answered_at)}
                </td>
                <td class="px-4 py-3">
                    <button
                        onclick="
                        App.actions.showResponse('${r.id}')
                        "
                        class="text-indigo-600">
                        全回答を表示
                    </button>
                </td>
            </tr>`;
        }).join('');

    App.app.innerHTML = App.Render.layout(`
        <div class="flex items-center justify-between mb-6">
            <div>
                <button
                    onclick="App.actions.navigate('surveys')"
                    class="text-sm text-indigo-600 mb-3">
                    ← アンケート一覧
                </button>

                <h1 class="text-2xl font-bold">
                    回答集計・分析
                </h1>

                <p class="mt-1 text-slate-500">
                    ${App.Utils.escape(survey.title)}
                </p>
            </div>

            <div class="flex gap-2">
                <a
                    href="?action=csv&survey_id=${encodeURIComponent(
                        survey.id
                    )}"
                    class="px-4 py-2 rounded-lg
                        bg-emerald-600 text-white">
                    CSV出力
                </a>

                <button
                    onclick="window.print()"
                    class="px-4 py-2 rounded-lg
                        border border-slate-200 bg-white">
                    PDF / 印刷
                </button>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5
            gap-4 mb-6">
            ${[
                ['送信対象者数', sentCustomers.length + ' 人'],
                ['回答数', responses.length + ' 件'],
                ['未登録顧客からの回答数', webResponses + ' 件'],
                ['未回答数', unanswered + ' 人'],
                ['回答率', rate + ' %']
            ].map(x => `
                <div class="bg-white border
                    border-slate-200 rounded-2xl p-5">
                    <div class="text-xs text-slate-400">
                        ${x[0]}
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${x[1]}
                    </div>
                </div>
            `).join('')}
        </div>

        <div class="bg-white border border-slate-200
            rounded-2xl p-5 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-bold">設問絞り込み</h2>
                <div class="flex gap-2">
                    <button
                        onclick="App.actions.selectAllQuestions(true)"
                        class="text-sm text-indigo-600">
                        全選択
                    </button>
                    <button
                        onclick="App.actions.selectAllQuestions(false)"
                        class="text-sm text-slate-500">
                        全解除
                    </button>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-2">
                ${questions.map(q => `
                    <label class="flex items-center gap-2
                        p-2 rounded-lg hover:bg-slate-50">
                        <input
                            type="checkbox"
                            ${App.State.selectedQuestions
                                .includes(q.id) ? 'checked' : ''}
                            onchange="
                            App.actions.toggleQuestion(
                                '${q.id}',this.checked
                            )">
                        <span class="text-sm">
                            ${App.Utils.escape(q.text)}
                        </span>
                    </label>
                `).join('')}
            </div>
        </div>

        ${responses.length === 0 ? `
            <div class="bg-white border
                border-slate-200 rounded-2xl p-16
                text-center text-slate-400 mb-6">
                現在、回答データはありません
            </div>
        ` : `
            <div class="space-y-5 mb-8">
                ${questionCharts}
            </div>
        `}

        <div class="bg-white border border-slate-200
            rounded-2xl overflow-hidden">
            <div class="p-5 flex items-center justify-between">
                <h2 class="font-bold">個別回答一覧</h2>
                <input
                    id="response_filter"
                    value="${App.Utils.escape(
                        App.State.responseFilter
                    )}"
                    oninput="
                        App.State.responseFilter=this.value;
                        App.Render.analytics();
                    "
                    placeholder="会社名・氏名"
                    class="border border-slate-200
                        rounded-lg px-3 py-2">
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 text-left text-xs">
                        <tr>
                            <th class="px-4 py-3">会社名</th>
                            <th class="px-4 py-3">氏名</th>
                            <th class="px-4 py-3">回答日時</th>
                            <th class="px-4 py-3">詳細</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${responseRows}
                    </tbody>
                </table>
            </div>
        </div>

        <div id="response_modal"
            class="hidden">
            <div class="fixed inset-0 z-50 bg-black/40
                flex items-center justify-center p-6">
                <div class="bg-white rounded-2xl
                    shadow-2xl w-full max-w-2xl
                    max-h-[85vh] overflow-auto">
                    <div class="p-5 border-b
                        border-slate-100 flex justify-between">
                        <h2 class="font-bold">
                            回答詳細
                        </h2>
                        <button
                            onclick="App.actions.closeResponse()">
                            ✕
                        </button>
                    </div>
                    <div id="response_detail" class="p-5"></div>
                </div>
            </div>
        </div>
    `);
};

App.Render.settings = function() {
    const s = {
        subdomain: '',
        login_name: '',
        password: '',
        app_id: '',
        proxy: '',
        field_company: '',
        field_name: '',
        field_email: '',
        field_department: '',
        field_phone: '',
        field_address: [],
        ...App.State.data.settings
    };

    const opts = function(selected, multiple=false) {
        const fields = App.State.kintoneFields;

        return `
            <option value="">選択してください</option>
            ${fields.map(function(f) {
                return `
                <option value="${App.Utils.escape(f.code)}"
                    ${selected === f.code ? 'selected' : ''}>
                    ${App.Utils.escape(f.label)}
                    (${App.Utils.escape(f.code)})
                </option>`;
            }).join('')}
        `;
    };

    const addressFields = App.State.kintoneFields.map(
        function(f) {
            const checked =
                Array.isArray(s.field_address) &&
                s.field_address.includes(f.code);

            return `
            <label class="flex items-center gap-2">
                <input
                    type="checkbox"
                    name="field_address[]"
                    value="${App.Utils.escape(f.code)}"
                    ${checked?'checked':''}>
                ${App.Utils.escape(f.label)}
            </label>`;
        }
    ).join('');

    App.app.innerHTML = App.Render.layout(`
        <div class="mb-6">
            <button
                onclick="App.actions.navigate('surveys')"
                class="text-sm text-indigo-600 mb-3">
                ← アンケート一覧
            </button>
            <h1 class="text-2xl font-bold">
                kintone連携設定
            </h1>
            <p class="text-sm text-slate-500 mt-1">
                顧客管理アプリとの接続・項目マッピングを設定します。
            </p>
        </div>

        <div class="bg-white border border-slate-200
            rounded-2xl p-6 shadow-sm mb-6">
            <h2 class="font-bold mb-5">接続設定</h2>

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-semibold">
                        サブドメイン
                    </label>
                    <input
                        id="setting_subdomain"
                        value="${App.Utils.escape(s.subdomain)}"
                        placeholder="xxxx.cybozu.com または xxxx"
                        class="mt-2 w-full border
                            border-slate-200 rounded-xl px-4 py-2.5">
                </div>

                <div>
                    <label class="text-sm font-semibold">
                        顧客管理アプリID
                    </label>
                    <input
                        id="setting_app_id"
                        value="${App.Utils.escape(s.app_id)}"
                        class="mt-2 w-full border
                            border-slate-200 rounded-xl px-4 py-2.5">
                </div>

                <div>
                    <label class="text-sm font-semibold">
                        ログイン名
                    </label>
                    <input
                        id="setting_login_name"
                        value="${App.Utils.escape(s.login_name)}"
                        class="mt-2 w-full border
                            border-slate-200 rounded-xl px-4 py-2.5">
                </div>

                <div>
                    <label class="text-sm font-semibold">
                        パスワード
                    </label>
                    <input
                        id="setting_password"
                        type="password"
                        value="${App.Utils.escape(s.password)}"
                        class="mt-2 w-full border
                            border-slate-200 rounded-xl px-4 py-2.5">
                </div>

                <div>
                    <label class="text-sm font-semibold">
                        Proxyサーバ
                    </label>
                    <input
                        id="setting_proxy"
                        value="${App.Utils.escape(s.proxy)}"
                        placeholder="host名:port番号"
                        class="mt-2 w-full border
                            border-slate-200 rounded-xl px-4 py-2.5">
                </div>

                <div class="flex items-end gap-2">
                    <button
                        onclick="App.actions.testKintone()"
                        class="px-4 py-2.5 rounded-xl
                            border border-slate-200">
                        接続確認
                    </button>
                    <button
                        onclick="App.actions.fetchKintoneFields()"
                        class="px-4 py-2.5 rounded-xl
                            bg-indigo-600 text-white">
                        項目一覧を取得
                    </button>
                </div>
            </div>

            <div class="mt-3 text-xs text-slate-400">
                SSL証明書検証は仕様に従い無効化されています。
            </div>

            <div id="field_message"></div>
        </div>

        <div class="bg-white border border-slate-200
            rounded-2xl p-6 shadow-sm mb-6">
            <h2 class="font-bold mb-5">
                顧客項目マッピング
            </h2>

            <div class="grid md:grid-cols-2 gap-4">
                ${[
                    ['field_company','会社名'],
                    ['field_name','氏名'],
                    ['field_email','メールアドレス'],
                    ['field_department','部署名'],
                    ['field_phone','電話番号']
                ].map(function(x) {
                    return `
                    <div>
                        <label class="text-sm font-semibold">
                            ${x[1]}
                        </label>
                        <select
                            id="${x[0]}"
                            class="mt-2 w-full border
                                border-slate-200 rounded-xl
                                px-4 py-2.5">
                            ${opts(s[x[0]])}
                        </select>
                    </div>`;
                }).join('')}
            </div>

            <div class="mt-5">
                <label class="text-sm font-semibold">
                    住所（複数フィールド結合可）
                </label>
                <div class="grid md:grid-cols-3
                    gap-2 mt-3">
                    ${addressFields || `
                    <div class="text-sm text-slate-400">
                        項目一覧を取得してください。
                    </div>`}
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button
                onclick="App.actions.saveSettings()"
                class="px-6 py-3 rounded-xl
                    bg-indigo-600 text-white font-semibold">
                設定を保存
            </button>
        </div>
    `);
};

App.Render.answer = function() {
    const survey =
        App.State.data.surveys.find(
            s => String(s.id) === String(App.State.surveyId)
        );

    if (!survey) {
        App.app.innerHTML = `
            <div class="min-h-screen flex items-center
                justify-center">
                <div class="text-center">
                    <h1 class="text-xl font-bold">
                        アンケートが見つかりません。
                    </h1>
                </div>
            </div>`;
        return;
    }

    const content =
        (survey.groups || []).map(function(group) {
            return `
            <section class="mb-8">
                <h2 class="text-lg font-bold mb-5">
                    ${App.Utils.escape(group.name)}
                </h2>

                ${(group.questions || []).map(
                    function(q) {
                        const name = 'answer_' + q.id;

                        return `
                        <div class="mb-7">
                            <label class="block font-semibold mb-3">
                                ${App.Utils.escape(q.text)}
                                ${q.required
                                    ? '<span class="text-red-500"> *</span>'
                                    : ''}
                            </label>

                            ${q.type === 'text' ? `
                                <textarea
                                    data-answer="${q.id}"
                                    ${q.required?'required':''}
                                    rows="5"
                                    class="w-full border
                                        border-slate-200 rounded-xl
                                        px-4 py-3"></textarea>
                            ` : `
                                <div class="space-y-2">
                                ${(q.options || []).map(
                                    function(opt) {
                                        return `
                                        <label class="flex items-center
                                            gap-3 p-3 rounded-lg
                                            hover:bg-slate-50">
                                            <input
                                                data-answer="${q.id}"
                                                name="${name}"
                                                value="${App.Utils.escape(opt)}"
                                                type="${q.type==='multiple'
                                                    ? 'checkbox'
                                                    : 'radio'}"
                                                ${q.required?'required':''}>
                                            ${App.Utils.escape(opt)}
                                        </label>`;
                                    }
                                ).join('')}
                                </div>
                            `}
                        </div>`;
                    }
                ).join('')}
            </section>`;
        }).join('');

    App.app.innerHTML = `
        <div class="min-h-screen bg-slate-50 py-10 px-4">
            <div class="max-w-3xl mx-auto">
                <div class="bg-white rounded-2xl
                    border border-slate-200 p-7 shadow-sm">

                    <h1 class="text-2xl font-bold mb-8">
                        ${App.Utils.escape(survey.title)}
                    </h1>

                    ${content}

                    <button
                        onclick="App.actions.submitAnswer()"
                        class="w-full py-3 rounded-xl
                            bg-indigo-600 text-white font-semibold">
                        回答を送信
                    </button>
                </div>
            </div>
        </div>`;
};

App.Render.all = function() {
    const app = document.getElementById('app');

    if (!app) return;

    App.app = app;

    if (App.State.view === 'surveys') {
        App.Render.surveys();
    } else if (App.State.view === 'editor') {
        App.Render.editor();
    } else if (App.State.view === 'send') {
        App.Render.send();
    } else if (App.State.view === 'analytics') {
        App.Render.analytics();
    } else if (App.State.view === 'settings') {
        App.Render.settings();
    } else if (App.State.view === 'answer') {
        App.Render.answer();
    }
};

App.init = async function() {
    if (App.State.initialized) return;

    App.State.initialized = true;
    App.app = document.getElementById('app');

    const params = new URLSearchParams(location.search);

    if (params.get('respond') === '1') {
        App.State.view = 'answer';
        App.State.surveyId = params.get('survey_id');
    }

    App.app.innerHTML = `
        <div class="min-h-screen flex items-center
            justify-center">
            <div class="text-slate-500">
                読み込み中...
            </div>
        </div>`;

    try {
        await App.API.load();

        /*
         * 回答画面以外は一覧を起点とする。
         */
        if (App.State.view !== 'answer') {
            App.State.view = 'surveys';
        }

        App.Render.all();

        if (App.State.view === 'settings' &&
            App.State.kintoneFields.length === 0) {
            App.Render.settings();
        }
    } catch (e) {
        console.error(e);

        App.app.innerHTML = `
            <div class="min-h-screen flex items-center
                justify-center p-6">
                <div class="bg-white border
                    border-red-200 rounded-2xl p-8
                    max-w-xl text-center">
                    <h1 class="font-bold text-red-600 mb-3">
                        初期化に失敗しました
                    </h1>
                    <p class="text-sm text-slate-500">
                        データファイルへのアクセス権限、
                        survey_storageディレクトリの権限、
                        PHP設定などを確認してください。
                    </p>
                </div>
            </div>`;
    }
};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        {once: true}
    );
} else {
    App.init();
}
</script>

</body>
</html>
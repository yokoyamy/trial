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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

function survey_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function survey_storage_init(): array
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true) && !is_dir(SURVEY_STORAGE_DIRECTORY)) {
            return ['success' => false, 'message' => 'survey_storage ディレクトリを作成できません。'];
        }
    }

    if (!file_exists(SURVEY_STORAGE_FILE)) {
        $initial = [
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

        $json = json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (@file_put_contents(SURVEY_STORAGE_FILE, $json, LOCK_EX) === false) {
            return ['success' => false, 'message' => 'データファイルを作成できません。'];
        }
    }

    return ['success' => true];
}

function survey_read_data(): array
{
    $init = survey_storage_init();
    if (!$init['success']) {
        return [
            'surveys' => [],
            'responses' => [],
            'customers' => [],
            'settings' => [],
            'mail_logs' => []
        ];
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        $data = [];
    }

    $data['surveys'] = is_array($data['surveys'] ?? null) ? $data['surveys'] : [];
    $data['responses'] = is_array($data['responses'] ?? null) ? $data['responses'] : [];
    $data['customers'] = is_array($data['customers'] ?? null) ? $data['customers'] : [];
    $data['settings'] = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    $data['mail_logs'] = is_array($data['mail_logs'] ?? null) ? $data['mail_logs'] : [];

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

    return @file_put_contents(SURVEY_STORAGE_FILE, $json, LOCK_EX) !== false;
}

function survey_id(string $prefix = 'id'): string
{
    try {
        return $prefix . '_' . bin2hex(random_bytes(8));
    } catch (Throwable) {
        return $prefix . '_' . str_replace('.', '', uniqid('', true));
    }
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_csrf(): string
{
    if (empty($_SESSION['survey_csrf_token'])) {
        try {
            $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable) {
            $_SESSION['survey_csrf_token'] = hash('sha256', uniqid('', true));
        }
    }

    return $_SESSION['survey_csrf_token'];
}

function survey_check_csrf(): bool
{
    $token = (string)($_POST['csrf_token'] ?? '');
    return $token !== '' && hash_equals(survey_csrf(), $token);
}

function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain);
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
        'timeout' => 20
    ];

    if ($method !== 'GET' && $payload !== null) {
        $http_options['content'] = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $payload;
    }

    $context_options = [
        'http' => $http_options,
        'ssl' => [
            'verify_peer' => !empty($config['ssl_verify']),
            'verify_peer_name' => !empty($config['ssl_verify']),
            'allow_self_signed' => true
        ]
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        $context_options['http']['proxy'] = 'tcp://' . $proxy;
        $context_options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($context_options);
    $response_body = @file_get_contents($url, false, $context);
    $response_headers = get_safe_response_headers();

    $status_code = 500;

    foreach ($response_headers as $header) {
        if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/i', $header, $m)) {
            $status_code = (int)$m[1];
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

    $message = is_array($result_data)
        ? (string)($result_data['message'] ?? 'kintone API 通信エラーが発生しました。')
        : 'kintone API 通信エラーが発生しました。';

    return [
        'success' => false,
        'status' => $status_code,
        'message' => $message,
        'raw' => $result_data
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string
{
    return 'X-Cybozu-Authorization: ' . base64_encode(
        trim($login_name) . ':' . trim($password)
    );
}

/* --------------------------------------------------------------------
 * API
 * ------------------------------------------------------------------ */

$storage_init = survey_storage_init();

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

    if ($action !== 'csv') {
        if (!$storage_init['success']) {
            survey_json_response($storage_init, 500);
        }
    }

    if (
        in_array(
            $action,
            [
                'save_survey',
                'delete_survey',
                'change_status',
                'duplicate_survey',
                'save_settings',
                'save_customer',
                'send_mail',
                'kintone_register',
                'sync_customers'
            ],
            true
        )
        && !survey_check_csrf()
    ) {
        survey_json_response([
            'success' => false,
            'message' => 'CSRFトークンが不正です。ページを再読み込みしてください。'
        ], 403);
    }

    $data = survey_read_data();

    switch ($action) {
        case 'init':
            survey_json_response([
                'success' => true,
                'csrf_token' => survey_csrf(),
                'data' => $data
            ]);

        case 'save_survey':
            $json = (string)($_POST['survey_json'] ?? '');
            $survey = json_decode($json, true);

            if (!is_array($survey)) {
                survey_json_response([
                    'success' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $now = survey_now();

            $survey['id'] = (string)($survey['id'] ?? survey_id('survey'));
            $survey['title'] = trim((string)($survey['title'] ?? '無題のアンケート'));
            $survey['start_at'] = (string)($survey['start_at'] ?? '');
            $survey['end_at'] = (string)($survey['end_at'] ?? '');
            $survey['status'] = in_array(
                $survey['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            ) ? $survey['status'] : 'draft';

            $survey['numbering_mode'] = in_array(
                $survey['numbering_mode'] ?? 'global',
                ['global', 'group'],
                true
            ) ? $survey['numbering_mode'] : 'global';

            $survey['groups'] = is_array($survey['groups'] ?? null)
                ? $survey['groups']
                : [];

            $survey['deleted'] = false;

            $found = false;

            foreach ($data['surveys'] as &$existing) {
                if ((string)($existing['id'] ?? '') === $survey['id']) {
                    $survey['created_at'] = $existing['created_at'] ?? $now;
                    $survey['updated_at'] = $now;
                    $existing = $survey;
                    $found = true;
                    break;
                }
            }
            unset($existing);

            if (!$found) {
                $survey['created_at'] = $now;
                $survey['updated_at'] = $now;
                $data['surveys'][] = $survey;
            }

            if (!survey_write_data($data)) {
                survey_json_response([
                    'success' => false,
                    'message' => '保存に失敗しました。survey_storage の書込権限を確認してください。'
                ], 500);
            }

            survey_json_response([
                'success' => true,
                'survey' => $survey,
                'data' => $data
            ]);

        case 'delete_survey':
            $id = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = survey_now();
                }
            }
            unset($survey);

            survey_write_data($data);

            survey_json_response([
                'success' => true,
                'data' => $data
            ]);

        case 'change_status':
            $id = (string)($_POST['survey_id'] ?? '');
            $status = (string)($_POST['status'] ?? 'draft');

            if (!in_array($status, ['draft', 'active', 'ended'], true)) {
                survey_json_response([
                    'success' => false,
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

            survey_json_response([
                'success' => true,
                'data' => $data
            ]);

        case 'duplicate_survey':
            $id = (string)($_POST['survey_id'] ?? '');
            $copy = null;

            foreach ($data['surveys'] as $survey) {
                if (($survey['id'] ?? '') === $id) {
                    $copy = $survey;
                    break;
                }
            }

            if (!$copy) {
                survey_json_response([
                    'success' => false,
                    'message' => '複製対象が見つかりません。'
                ], 404);
            }

            $copy['id'] = survey_id('survey');
            $copy['title'] = (string)$copy['title'] . '（複製）';
            $copy['status'] = 'draft';
            $copy['created_at'] = survey_now();
            $copy['updated_at'] = survey_now();
            $copy['deleted'] = false;

            $data['surveys'][] = $copy;
            survey_write_data($data);

            survey_json_response([
                'success' => true,
                'survey' => $copy,
                'data' => $data
            ]);

        case 'save_settings':
            $settings = json_decode((string)($_POST['settings_json'] ?? ''), true);

            if (!is_array($settings)) {
                survey_json_response([
                    'success' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            $data['settings'] = array_merge($data['settings'], [
                'subdomain' => trim((string)($settings['subdomain'] ?? '')),
                'login_name' => trim((string)($settings['login_name'] ?? '')),
                'password' => (string)($settings['password'] ?? ''),
                'app_id' => trim((string)($settings['app_id'] ?? '')),
                'ssl_verify' => !empty($settings['ssl_verify']),
                'proxy' => trim((string)($settings['proxy'] ?? '')),
                'field_company' => (string)($settings['field_company'] ?? ''),
                'field_name' => (string)($settings['field_name'] ?? ''),
                'field_email' => (string)($settings['field_email'] ?? ''),
                'field_department' => (string)($settings['field_department'] ?? ''),
                'field_phone' => (string)($settings['field_phone'] ?? ''),
                'field_address' => is_array($settings['field_address'] ?? null)
                    ? $settings['field_address']
                    : []
            ]);

            survey_write_data($data);

            survey_json_response([
                'success' => true,
                'data' => $data
            ]);

        case 'kintone_test':
        case 'fetch_kintone_fields':
        case 'sync_customers':
            $settings = $data['settings'];

            if ($action === 'fetch_kintone_fields') {
                $app_id = trim((string)($_POST['app_id'] ?? $settings['app_id'] ?? ''));
            } else {
                $app_id = trim((string)($settings['app_id'] ?? ''));
            }

            $domain = trim((string)($settings['subdomain'] ?? ''));
            $login = (string)($settings['login_name'] ?? '');
            $password = (string)($settings['password'] ?? '');

            if ($domain === '' || $login === '' || $password === '' || $app_id === '') {
                survey_json_response([
                    'success' => false,
                    'message' => 'kintone接続情報またはアプリIDが不足しています。'
                ], 400);
            }

            $url = kintone_build_url(
                $domain,
                '/k/v1/app/form/fields.json?app=' . rawurlencode($app_id)
            );

            $result = kintone_api_request(
                'GET',
                $url,
                [
                    make_cybozu_auth_header($login, $password),
                    'Accept: application/json'
                ],
                null,
                $settings
            );

            if ($action === 'kintone_test') {
                survey_json_response([
                    'success' => $result['success'],
                    'message' => $result['success']
                        ? 'kintoneへの接続に成功しました。'
                        : ($result['message'] ?? '接続に失敗しました。'),
                    'status' => $result['status'] ?? 0
                ]);
            }

            if (!$result['success']) {
                survey_json_response($result, 400);
            }

            $fields = [];

            foreach (($result['data']['properties'] ?? []) as $code => $field) {
                $fields[] = [
                    'code' => $code,
                    'label' => (string)($field['label'] ?? $code),
                    'type' => (string)($field['type'] ?? '')
                ];
            }

            survey_json_response([
                'success' => true,
                'fields' => $fields
            ]);

        case 'sync_customers':
            $settings = $data['settings'];
            $app_id = trim((string)($settings['app_id'] ?? ''));

            $url = kintone_build_url(
                (string)$settings['subdomain'],
                '/k/v1/records.json?app=' . rawurlencode($app_id) . '&query=' . rawurlencode('limit 500')
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
                $settings
            );

            if (!$result['success']) {
                survey_json_response($result, 400);
            }

            $fields = [
                'company' => (string)($settings['field_company'] ?? ''),
                'name' => (string)($settings['field_name'] ?? ''),
                'email' => (string)($settings['field_email'] ?? ''),
                'department' => (string)($settings['field_department'] ?? ''),
                'phone' => (string)($settings['field_phone'] ?? ''),
                'address' => is_array($settings['field_address'] ?? null)
                    ? $settings['field_address']
                    : []
            ];

            $count = 0;

            foreach (($result['data']['records'] ?? []) as $record) {
                $email = '';

                if ($fields['email'] !== '' && isset($record[$fields['email']]['value'])) {
                    $email = trim((string)$record[$fields['email']]['value']);
                }

                if ($email === '') {
                    continue;
                }

                $customer = [
                    'id' => survey_id('customer'),
                    'company' => '',
                    'name' => '',
                    'email' => $email,
                    'department' => '',
                    'phone' => '',
                    'address' => '',
                    'source' => 'kintone',
                    'sent_at' => '',
                    'send_count' => 0,
                    'answer_status' => 'unanswered',
                    'kintone_status' => 'registered'
                ];

                foreach (['company', 'name', 'department', 'phone'] as $key) {
                    $code = $fields[$key];
                    if ($code !== '' && isset($record[$code]['value'])) {
                        $customer[$key] = (string)$record[$code]['value'];
                    }
                }

                if (!empty($fields['address'])) {
                    $parts = [];
                    foreach ($fields['address'] as $code) {
                        if (isset($record[$code]['value']) && trim((string)$record[$code]['value']) !== '') {
                            $parts[] = trim((string)$record[$code]['value']);
                        }
                    }
                    $customer['address'] = implode(' ', $parts);
                }

                $existingIndex = null;

                foreach ($data['customers'] as $index => $old) {
                    if (strcasecmp((string)($old['email'] ?? ''), $email) === 0) {
                        $existingIndex = $index;
                        break;
                    }
                }

                if ($existingIndex !== null) {
                    $old = $data['customers'][$existingIndex];
                    $customer['id'] = $old['id'] ?? $customer['id'];
                    $customer['sent_at'] = $old['sent_at'] ?? '';
                    $customer['send_count'] = (int)($old['send_count'] ?? 0);
                    $customer['answer_status'] = $old['answer_status'] ?? 'unanswered';
                    $data['customers'][$existingIndex] = array_merge($old, $customer);
                } else {
                    $data['customers'][] = $customer;
                }

                $count++;
            }

            survey_write_data($data);

            survey_json_response([
                'success' => true,
                'count' => $count,
                'data' => $data
            ]);

        case 'mark_kintone':
            $customer_id = (string)($_POST['customer_id'] ?? '');

            foreach ($data['customers'] as &$customer) {
                if (($customer['id'] ?? '') === $customer_id) {
                    $customer['kintone_status'] = 'registered';
                }
            }
            unset($customer);

            survey_write_data($data);

            survey_json_response([
                'success' => true,
                'data' => $data
            ]);

        case 'send_mail':
            $survey_id = (string)($_POST['survey_id'] ?? '');
            $recipient_ids = json_decode((string)($_POST['recipient_ids'] ?? '[]'), true);

            if (!is_array($recipient_ids)) {
                $recipient_ids = [];
            }

            $subject = (string)($_POST['mail_subject'] ?? '');
            $body = (string)($_POST['mail_body'] ?? '');
            $template_type = in_array(
                $_POST['template_type'] ?? 'initial',
                ['initial', 'reminder'],
                true
            ) ? $_POST['template_type'] : 'initial';

            $sent = 0;

            foreach ($data['customers'] as &$customer) {
                if (!in_array($customer['id'] ?? '', $recipient_ids, true)) {
                    continue;
                }

                if (($customer['source'] ?? '') === 'web') {
                    continue;
                }

                $customer['sent_at'] = survey_now();
                $customer['send_count'] = (int)($customer['send_count'] ?? 0) + 1;
                $customer['answer_status'] = 'unanswered';
                $sent++;

                $personalSubject = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        (string)($customer['name'] ?? ''),
                        '?survey=' . rawurlencode($survey_id) . '&customer=' . rawurlencode((string)$customer['id'])
                    ],
                    $subject
                );

                $personalBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        (string)($customer['name'] ?? ''),
                        '?survey=' . rawurlencode($survey_id) . '&customer=' . rawurlencode((string)$customer['id'])
                    ],
                    $body
                );

                $customer['_last_mail_subject'] = $personalSubject;
                $customer['_last_mail_body'] = $personalBody;
            }
            unset($customer);

            $data['mail_logs'][] = [
                'id' => survey_id('mail'),
                'survey_id' => $survey_id,
                'sent_at' => survey_now(),
                'template_type' => $template_type,
                'count' => $sent,
                'subject' => $subject,
                'body' => $body,
                'executor' => (string)($_SESSION['survey_admin_name'] ?? '管理者')
            ];

            survey_write_data($data);

            survey_json_response([
                'success' => true,
                'count' => $sent,
                'data' => $data
            ]);

        case 'csv':
            $data = survey_read_data();
            $survey_id = (string)($_GET['survey_id'] ?? '');

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if (($item['id'] ?? '') === $survey_id) {
                    $survey = $item;
                    break;
                }
            }

            if (!$survey) {
                http_response_code(404);
                exit('Survey not found');
            }

            $questions = [];

            foreach (($survey['groups'] ?? []) as $group) {
                foreach (($group['questions'] ?? []) as $question) {
                    $questions[] = $question;
                }
            }

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="survey_' .
                rawurlencode($survey_id) . '.csv"'
            );

            echo "\xEF\xBB\xBF";

            $fp = fopen('php://output', 'w');

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名'
            ];

            foreach ($questions as $index => $question) {
                $header[] = '設問' . ($index + 1);
            }

            fputcsv($fp, $header);

            foreach ($data['responses'] as $response) {
                if (($response['survey_id'] ?? '') !== $survey_id) {
                    continue;
                }

                $row = [
                    $response['id'] ?? '',
                    $response['answered_at'] ?? '',
                    $response['customer_id'] ?? '',
                    $response['company'] ?? '',
                    $response['name'] ?? ''
                ];

                $answers = is_array($response['answers'] ?? null)
                    ? $response['answers']
                    : [];

                foreach ($questions as $question) {
                    $qid = $question['id'] ?? '';
                    $value = $answers[$qid] ?? '';

                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }

                    $row[] = (string)$value;
                }

                fputcsv($fp, $row);
            }

            fclose($fp);
            exit;

        default:
            survey_json_response([
                'success' => false,
                'message' => 'Unknown action'
            ], 400);
    }
}

$csrf = survey_csrf();

?>
<!DOCTYPE html>
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

<script>
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
        customerKeyword: '',
        responseKeyword: '',
        selectedQuestions: {},
        previewMobile: false,
        dirty: false
    },

    cache: {},

    api: {
        async request(action, data = {}) {
            const body = new URLSearchParams();
            body.set('action', action);

            const csrf = App.state.csrf_token;
            if (csrf) body.set('csrf_token', csrf);

            Object.keys(data).forEach(key => {
                const value = data[key];
                if (Array.isArray(value) || (typeof value === 'object' && value !== null)) {
                    body.set(key, JSON.stringify(value));
                } else {
                    body.set(key, value == null ? '' : String(value));
                }
            });

            const response = await fetch(location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body
            });

            const text = await response.text();

            let json;
            try {
                json = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーからJSON以外の応答が返されました。\n' + text.substring(0, 500)
                );
            }

            if (!response.ok || json.success === false) {
                throw new Error(json.message || '通信に失敗しました。');
            }

            return json;
        }
    },

    init: async function() {
        if (App.state.initialized) return;

        App.state.initialized = true;

        try {
            const result = await App.api.request('init');

            App.state.csrf_token = result.csrf_token || '';
            const data = result.data || {};

            App.state.surveys = Array.isArray(data.surveys) ? data.surveys : [];
            App.state.responses = Array.isArray(data.responses) ? data.responses : [];
            App.state.customers = Array.isArray(data.customers) ? data.customers : [];
            App.state.settings = data.settings || {};
            App.state.mail_logs = Array.isArray(data.mail_logs) ? data.mail_logs : [];

            App.render.layout();
            App.render.list();
        } catch (error) {
            App.state.initialized = false;
            App.render.error(error.message);
        }
    },

    util: {
        esc: function(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        },

        uid: function(prefix) {
            return prefix + '_' + Date.now().toString(36) +
                '_' + Math.random().toString(36).substring(2, 9);
        },

        date: function(value) {
            if (!value) return '未設定';

            const d = new Date(String(value).replace(' ', 'T'));

            if (Number.isNaN(d.getTime())) {
                return String(value);
            }

            return d.getFullYear() + '/' +
                String(d.getMonth() + 1).padStart(2, '0') + '/' +
                String(d.getDate()).padStart(2, '0');
        },

        datetime: function(value) {
            if (!value) return '未設定';
            return String(value).replace('T', ' ');
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
                draft: 'bg-amber-100 text-amber-700',
                ended: 'bg-slate-200 text-slate-600'
            }[status] || 'bg-slate-100 text-slate-600';
        },

        typeLabel: function(type) {
            return {
                single: '単一選択',
                multiple: '複数選択',
                text: '自由記述'
            }[type] || type;
        },

        clone: function(value) {
            return JSON.parse(JSON.stringify(value));
        },

        allQuestions: function(survey) {
            const result = [];

            (survey.groups || []).forEach((group, gi) => {
                (group.questions || []).forEach((question, qi) => {
                    result.push({
                        question,
                        group,
                        gi,
                        qi
                    });
                });
            });

            return result;
        },

        answerCount: function(surveyId) {
            return App.state.responses.filter(
                r => r.survey_id === surveyId
            ).length;
        }
    },

    render: {
        layout: function() {
            document.getElementById('app').innerHTML = `
                <div class="min-h-screen">
                    <header class="sticky top-0 z-30 bg-white border-b border-slate-200 shadow-sm">
                        <div class="max-w-[1600px] mx-auto px-6 h-16 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-bold">
                                    Q
                                </div>
                                <div>
                                    <div class="font-bold text-slate-900">アンケート管理</div>
                                    <div class="text-[11px] text-slate-400">Survey Management System</div>
                                </div>
                            </div>

                            <nav class="flex items-center gap-2">
                                <button onclick="App.actions.goList()"
                                    class="px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-100">
                                    アンケート一覧
                                </button>
                                <button onclick="App.actions.settings()"
                                    class="px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-100">
                                    キントーン連携設定
                                </button>
                                <button onclick="App.actions.logout()"
                                    class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:bg-slate-100">
                                    ログアウト
                                </button>
                            </nav>
                        </div>
                    </header>

                    <main id="main_content" class="max-w-[1600px] mx-auto p-6"></main>
                </div>

                <div id="preview_modal"></div>
                <div id="response_modal"></div>
            `;
        },

        error: function(message) {
            document.getElementById('app').innerHTML = `
                <div class="min-h-screen flex items-center justify-center bg-slate-50 p-6">
                    <div class="bg-white rounded-2xl shadow-lg border border-red-100 p-8 max-w-xl w-full">
                        <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center text-xl mb-5">
                            !
                        </div>
                        <h1 class="text-xl font-bold text-red-700 mb-3">
                            初期化に失敗しました
                        </h1>
                        <div class="bg-red-50 rounded-lg p-4 text-sm text-red-700 whitespace-pre-wrap">
                            ${App.util.esc(message)}
                        </div>
                        <p class="mt-5 text-sm text-slate-500">
                            データファイルへのアクセス権限、survey_storageディレクトリの権限、
                            PHP設定などを確認してください。
                        </p>
                        <button onclick="location.reload()"
                            class="mt-5 px-5 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                            再読み込み
                        </button>
                    </div>
                </div>
            `;
        },

        list: function() {
            App.state.screen = 'list';

            const keyword = App.state.keyword.toLowerCase();
            const filter = App.state.status_filter;

            let surveys = App.state.surveys.filter(s => !s.deleted);

            if (keyword) {
                surveys = surveys.filter(s =>
                    String(s.title || '').toLowerCase().includes(keyword)
                );
            }

            if (filter !== 'all') {
                surveys = surveys.filter(s => s.status === filter);
            }

            surveys.sort((a, b) => {
                if (App.state.sort === 'updated_desc') {
                    return String(b.updated_at).localeCompare(String(a.updated_at));
                }
                if (App.state.sort === 'updated_asc') {
                    return String(a.updated_at).localeCompare(String(b.updated_at));
                }
                if (App.state.sort === 'answers_desc') {
                    return App.util.answerCount(b.id) - App.util.answerCount(a.id);
                }
                if (App.state.sort === 'answers_asc') {
                    return App.util.answerCount(a.id) - App.util.answerCount(b.id);
                }
                if (App.state.sort === 'start_desc') {
                    return String(b.start_at || '').localeCompare(String(a.start_at || ''));
                }
                return String(a.start_at || '').localeCompare(String(b.start_at || ''));
            });

            document.getElementById('main_content').innerHTML = `
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
                    <div>
                        <div class="text-sm text-indigo-600 font-semibold mb-1">HOME</div>
                        <h1 class="text-2xl font-bold text-slate-900">アンケート一覧</h1>
                        <p class="text-sm text-slate-500 mt-1">
                            アンケートの作成・公開・送信・集計を管理します。
                        </p>
                    </div>

                    <button onclick="App.actions.newSurvey()"
                        class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold shadow-sm hover:bg-indigo-700">
                        ＋ 新規アンケート作成
                    </button>
                </div>

                <section class="bg-white rounded-2xl border border-slate-200 shadow-sm mb-5">
                    <div class="p-4 flex flex-col lg:flex-row gap-3">
                        <input
                            id="survey_list_keyword"
                            value="${App.util.esc(App.state.keyword)}"
                            onkeydown="if(event.key==='Enter')App.actions.searchSurveys(this.value)"
                            placeholder="タイトルを検索してEnter"
                            class="flex-1 min-w-0 border border-slate-300 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-500"
                        >

                        <select onchange="App.actions.toggleStatusFilter(this.value)"
                            class="border border-slate-300 rounded-xl px-4 py-2.5 bg-white">
                            <option value="all" ${App.state.status_filter === 'all' ? 'selected' : ''}>すべて</option>
                            <option value="active" ${App.state.status_filter === 'active' ? 'selected' : ''}>公開中</option>
                            <option value="draft" ${App.state.status_filter === 'draft' ? 'selected' : ''}>下書き</option>
                            <option value="ended" ${App.state.status_filter === 'ended' ? 'selected' : ''}>終了</option>
                        </select>

                        <select onchange="App.actions.sortSurveys(this.value)"
                            class="border border-slate-300 rounded-xl px-4 py-2.5 bg-white">
                            <option value="updated_desc" ${App.state.sort === 'updated_desc' ? 'selected' : ''}>更新日：新しい順</option>
                            <option value="updated_asc" ${App.state.sort === 'updated_asc' ? 'selected' : ''}>更新日：古い順</option>
                            <option value="answers_desc" ${App.state.sort === 'answers_desc' ? 'selected' : ''}>回答数：多い順</option>
                            <option value="answers_asc" ${App.state.sort === 'answers_asc' ? 'selected' : ''}>回答数：少ない順</option>
                            <option value="start_desc" ${App.state.sort === 'start_desc' ? 'selected' : ''}>開始日：新しい順</option>
                            <option value="start_asc" ${App.state.sort === 'start_asc' ? 'selected' : ''}>開始日：古い順</option>
                        </select>
                    </div>
                </section>

                <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1100px] text-sm">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr class="text-left text-slate-500">
                                    <th class="px-5 py-4">作成日 / 更新日</th>
                                    <th class="px-5 py-4">タイトル</th>
                                    <th class="px-5 py-4">アンケート期間</th>
                                    <th class="px-5 py-4">ステータス</th>
                                    <th class="px-5 py-4 text-right">回答数</th>
                                    <th class="px-5 py-4">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${
                                    surveys.length
                                    ? surveys.map(s => App.render.surveyRow(s)).join('')
                                    : `
                                    <tr>
                                        <td colspan="6" class="px-5 py-16 text-center text-slate-400">
                                            アンケートがありません。
                                        </td>
                                    </tr>
                                    `
                                }
                            </tbody>
                        </table>
                    </div>
                </section>
            `;
        },

        surveyRow: function(survey) {
            const count = App.util.answerCount(survey.id);

            let buttons = '';

            if (survey.status === 'active') {
                buttons = `
                    <button onclick="App.actions.editSurvey('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200">確認・編集</button>
                    <button onclick="App.actions.analytics('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100">集計</button>
                    <button onclick="App.actions.mail('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100">送信</button>
                    <button onclick="App.actions.stopSurvey('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 hover:bg-rose-100">停止</button>
                    <button onclick="App.actions.duplicate('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200">複製</button>
                `;
            } else if (survey.status === 'draft') {
                buttons = `
                    <button onclick="App.actions.editSurvey('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200">確認・編集</button>
                    <button onclick="App.actions.deleteSurvey('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 hover:bg-rose-100">削除</button>
                    <button onclick="App.actions.duplicate('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200">複製</button>
                `;
            } else {
                buttons = `
                    <button onclick="App.actions.editSurvey('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200">確認・編集</button>
                    <button onclick="App.actions.analytics('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100">集計</button>
                    <button onclick="App.actions.duplicate('${survey.id}')"
                        class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200">複製</button>
                `;
            }

            return `
                <tr class="border-b border-slate-100 hover:bg-slate-50">
                    <td class="px-5 py-4 text-slate-500 whitespace-nowrap">
                        <div>${App.util.date(survey.created_at)}</div>
                        <div class="text-xs mt-1">更新: ${App.util.date(survey.updated_at)}</div>
                    </td>
                    <td class="px-5 py-4">
                        <div class="font-bold text-slate-900">${App.util.esc(survey.title)}</div>
                        <div class="text-xs text-slate-400 mt-1">${App.util.esc(survey.id)}</div>
                    </td>
                    <td class="px-5 py-4 whitespace-nowrap text-slate-600">
                        ${survey.start_at ? App.util.datetime(survey.start_at) : '未設定'}
                        <span class="mx-1">～</span>
                        ${survey.end_at ? App.util.datetime(survey.end_at) : '未設定'}
                    </td>
                    <td class="px-5 py-4">
                        <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold ${App.util.statusClass(survey.status)}">
                            ${App.util.statusLabel(survey.status)}
                        </span>
                    </td>
                    <td class="px-5 py-4 text-right font-semibold">${count} 件</td>
                    <td class="px-5 py-4">
                        <div class="flex flex-wrap gap-1.5">${buttons}</div>
                    </td>
                </tr>
            `;
        },

        editor: function(survey) {
            App.state.screen = 'editor';
            App.state.currentSurvey = App.util.clone(survey);
            App.state.dirty = false;

            document.getElementById('main_content').innerHTML = `
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <div class="text-sm text-indigo-600 font-semibold mb-1">SURVEY EDITOR</div>
                        <h1 class="text-2xl font-bold">アンケート作成・編集</h1>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="App.actions.preview()"
                            class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50">
                            プレビュー
                        </button>
                        <button onclick="App.actions.cancelEdit()"
                            class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50">
                            キャンセル
                        </button>
                        <button onclick="App.actions.saveSurvey()"
                            class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold hover:bg-indigo-700">
                            保存して一覧へ戻る
                        </button>
                    </div>
                </div>

                <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <label class="lg:col-span-2">
                            <span class="block text-sm font-semibold mb-2">タイトル</span>
                            <input id="survey_title"
                                value="${App.util.esc(survey.title)}"
                                oninput="App.actions.editorTitle(this.value)"
                                class="w-full border border-slate-300 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-200">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-2">開始日時</span>
                            <input id="survey_start_at" type="datetime-local"
                                value="${App.util.esc(survey.start_at)}"
                                onchange="App.actions.editorField('start_at',this.value)"
                                class="w-full border border-slate-300 rounded-xl px-3 py-3">
                        </label>

                        <label>
                            <span class="block text-sm font-semibold mb-2">終了日時</span>
                            <input id="survey_end_at" type="datetime-local"
                                value="${App.util.esc(survey.end_at)}"
                                onchange="App.actions.editorField('end_at',this.value)"
                                class="w-full border border-slate-300 rounded-xl px-3 py-3">
                        </label>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center gap-5">
                        <label class="flex items-center gap-2">
                            <span class="text-sm font-semibold">ステータス</span>
                            <select onchange="App.actions.editorField('status',this.value)"
                                class="border border-slate-300 rounded-lg px-3 py-2">
                                <option value="draft" ${survey.status === 'draft' ? 'selected' : ''}>下書き</option>
                                <option value="active" ${survey.status === 'active' ? 'selected' : ''}>公開中</option>
                                <option value="ended" ${survey.status === 'ended' ? 'selected' : ''}>終了</option>
                            </select>
                        </label>

                        <label class="flex items-center gap-2">
                            <span class="text-sm font-semibold">質問番号</span>
                            <select id="survey_numbering_mode"
                                onchange="App.actions.editorField('numbering_mode',this.value);App.render.editorBody()"
                                class="border border-slate-300 rounded-lg px-3 py-2">
                                <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>Q1, Q2, Q3...</option>
                                <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>Q1-1, Q1-2...</option>
                            </select>
                        </label>
                    </div>
                </section>

                <div id="question_editor"></div>
            `;

            App.render.editorBody();
        },

        editorBody: function() {
            const survey = App.state.currentSurvey;
            const editor = document.getElementById('question_editor');

            if (!editor) return;

            editor.innerHTML = `
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="font-bold text-lg">質問構成</h2>
                        <p class="text-sm text-slate-500">ドラッグ＆ドロップで並び替えできます。</p>
                    </div>

                    <button onclick="App.actions.addGroup()"
                        class="px-4 py-2.5 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700">
                        ＋ グループ追加
                    </button>
                </div>

                <div id="groups_container" class="space-y-5">
                    ${
                        (survey.groups || []).map((group, gi) =>
                            App.render.group(group, gi)
                        ).join('')
                    }
                </div>

                ${
                    survey.groups.length === 0
                    ? `
                    <div class="bg-white border border-dashed border-slate-300 rounded-2xl p-12 text-center">
                        <div class="text-slate-400 mb-3">まだグループがありません。</div>
                        <button onclick="App.actions.addGroup()"
                            class="px-4 py-2 rounded-lg bg-indigo-600 text-white">
                            最初のグループを追加
                        </button>
                    </div>
                    `
                    : ''
                }
            `;

            App.actions.setupSortable();
        },

        group: function(group, gi) {
            return `
                <section class="survey-group bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden"
                    data-group-id="${App.util.esc(group.id)}">

                    <div class="group-handle px-5 py-4 bg-slate-50 border-b border-slate-200 flex items-center gap-3 cursor-move">
                        <span class="text-xl text-slate-400">⠿</span>
                        <input
                            value="${App.util.esc(group.name)}"
                            onchange="App.actions.groupName('${group.id}',this.value)"
                            onclick="event.stopPropagation()"
                            class="flex-1 bg-transparent font-bold outline-none"
                        >
                        <button onclick="App.actions.deleteGroup('${group.id}')"
                            class="text-sm text-rose-600 hover:bg-rose-50 px-3 py-1.5 rounded-lg">
                            グループ削除
                        </button>
                    </div>

                    <div class="question-list p-5 space-y-4 min-h-[80px]"
                        data-group-id="${App.util.esc(group.id)}">

                        ${
                            (group.questions || []).map((q, qi) =>
                                App.render.question(q, gi, qi)
                            ).join('')
                        }

                        <button onclick="App.actions.addQuestion('${group.id}')"
                            class="w-full py-3 rounded-xl border-2 border-dashed border-slate-300 text-slate-500 hover:border-indigo-400 hover:text-indigo-600">
                            ＋ 質問を追加
                        </button>
                    </div>
                </section>
            `;
        },

        question: function(q, gi, qi) {
            const number = App.actions.questionNumber(q.id);

            return `
                <article class="question-card border border-slate-200 rounded-xl p-5 bg-white shadow-sm"
                    data-question-id="${App.util.esc(q.id)}">

                    <div class="flex items-start gap-3">
                        <div class="question-handle cursor-move text-xl text-slate-400 pt-1">⠿</div>

                        <div class="flex-1">
                            <div class="flex items-center justify-between gap-3 mb-4">
                                <div class="font-bold text-indigo-600">${number}</div>

                                <div class="flex items-center gap-3">
                                    <label class="text-sm flex items-center gap-2">
                                        <input type="checkbox"
                                            ${q.required ? 'checked' : ''}
                                            onchange="App.actions.questionRequired('${q.id}',this.checked)"
                                            class="w-4 h-4 accent-indigo-600">
                                        必須回答
                                    </label>

                                    <button onclick="App.actions.deleteQuestion('${q.id}')"
                                        class="text-sm text-rose-600 hover:bg-rose-50 px-2 py-1 rounded">
                                        削除
                                    </button>
                                </div>
                            </div>

                            <input
                                value="${App.util.esc(q.text)}"
                                oninput="App.actions.questionText('${q.id}',this.value)"
                                placeholder="質問文を入力してください"
                                class="w-full border border-slate-300 rounded-xl px-4 py-3 mb-3 outline-none focus:ring-2 focus:ring-indigo-200"
                            >

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <select onchange="App.actions.questionType('${q.id}',this.value)"
                                    class="border border-slate-300 rounded-lg px-3 py-2">
                                    <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択</option>
                                    <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                                    <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述</option>
                                </select>

                                ${
                                    q.type !== 'text'
                                    ? `
                                    <label class="flex items-center gap-2 border border-slate-200 rounded-lg px-3">
                                        <input type="checkbox"
                                            ${q.other_enabled ? 'checked' : ''}
                                            onchange="App.actions.questionOther('${q.id}',this.checked)"
                                            class="accent-indigo-600">
                                        その他を許可
                                    </label>
                                    `
                                    : ''
                                }
                            </div>

                            ${
                                q.type !== 'text'
                                ? `
                                <div class="mt-4 space-y-2">
                                    ${(q.options || []).map((option, oi) => `
                                        <div class="flex items-center gap-2">
                                            <span class="text-slate-400">${q.type === 'single' ? '○' : '□'}</span>
                                            <input
                                                value="${App.util.esc(option)}"
                                                oninput="App.actions.optionText('${q.id}',${oi},this.value)"
                                                class="flex-1 border border-slate-200 rounded-lg px-3 py-2"
                                            >
                                            <button onclick="App.actions.deleteOption('${q.id}',${oi})"
                                                class="text-slate-400 hover:text-rose-600 px-2">×</button>
                                        </div>
                                    `).join('')}

                                    <button onclick="App.actions.addOption('${q.id}')"
                                        class="text-sm text-indigo-600 hover:text-indigo-800 mt-2">
                                        ＋ 選択肢を追加
                                    </button>
                                </div>
                                `
                                : `
                                <div class="mt-4 bg-slate-50 rounded-xl p-4 text-sm text-slate-500">
                                    回答者が複数行のテキストを入力します。
                                </div>
                                `
                            }
                        </div>
                    </div>
                </article>
            `;
        },

        preview: function() {
            const survey = App.state.currentSurvey;
            const mobile = App.state.previewMobile;

            document.getElementById('preview_modal').innerHTML = `
                <div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                    <div class="bg-slate-100 rounded-2xl shadow-2xl w-full max-w-5xl max-h-[95vh] overflow-hidden">
                        <div class="bg-white border-b px-5 py-4 flex items-center justify-between">
                            <div class="font-bold">回答者プレビュー</div>

                            <div class="flex items-center gap-2">
                                <button onclick="App.actions.previewMode(false)"
                                    class="px-3 py-1.5 rounded-lg ${!mobile ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100'}">
                                    PC表示
                                </button>
                                <button onclick="App.actions.previewMode(true)"
                                    class="px-3 py-1.5 rounded-lg ${mobile ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100'}">
                                    スマートフォン表示
                                </button>
                                <button onclick="App.actions.closePreview()"
                                    class="ml-3 text-slate-500 text-xl">×</button>
                            </div>
                        </div>

                        <div class="p-8 overflow-y-auto max-h-[calc(95vh-70px)]">
                            <div class="${mobile ? 'max-w-[390px]' : 'max-w-3xl'} mx-auto bg-white rounded-2xl shadow-sm border p-6">
                                <h1 class="text-2xl font-bold mb-2">${App.util.esc(survey.title)}</h1>
                                <p class="text-sm text-slate-400 mb-7">
                                    ${survey.start_at || ''} ${survey.end_at ? '～ ' + survey.end_at : ''}
                                </p>

                                ${
                                    (survey.groups || []).map(group => `
                                        <div class="mb-8">
                                            <h2 class="text-lg font-bold border-b pb-2 mb-5">
                                                ${App.util.esc(group.name)}
                                            </h2>

                                            <div class="space-y-6">
                                                ${(group.questions || []).map(q => `
                                                    <div>
                                                        <div class="font-semibold mb-3">
                                                            ${App.util.esc(q.text)}
                                                            ${q.required ? '<span class="text-rose-500 ml-1">*</span>' : ''}
                                                        </div>

                                                        ${
                                                            q.type === 'text'
                                                            ? `<textarea class="w-full border rounded-xl p-3 h-28" placeholder="回答を入力"></textarea>`
                                                            : (q.options || []).map(o => `
                                                                <label class="flex gap-2 items-center py-1.5">
                                                                    <input type="${q.type === 'single' ? 'radio' : 'checkbox'}">
                                                                    ${App.util.esc(o)}
                                                                </label>
                                                            `).join('') +
                                                            (q.other_enabled ? `
                                                                <label class="flex gap-2 items-center py-1.5">
                                                                    <input type="${q.type === 'single' ? 'radio' : 'checkbox'}">
                                                                    その他
                                                                </label>
                                                            ` : '')
                                                        }
                                                    </div>
                                                `).join('')}
                                            </div>
                                        </div>
                                    `).join('')
                                }

                                <button onclick="App.actions.previewSubmit()"
                                    class="w-full py-3 bg-indigo-600 text-white rounded-xl font-semibold">
                                    回答を送信
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        analytics: function(surveyId) {
            App.state.screen = 'analytics';
            App.state.currentSurveyId = surveyId;

            const survey = App.state.surveys.find(s => s.id === surveyId);

            if (!survey) {
                App.actions.goList();
                return;
            }

            const responses = App.state.responses.filter(r => r.survey_id === surveyId);
            const sentCustomers = App.state.customers.filter(c =>
                c.sent_at && c.source !== 'web'
            );

            const answeredCustomers = new Set(
                responses
                    .filter(r => r.customer_id)
                    .map(r => r.customer_id)
            );

            const unregistered = responses.filter(r =>
                !r.customer_id ||
                !App.state.customers.some(c => c.id === r.customer_id)
            ).length;

            const unanswered = Math.max(
                0,
                sentCustomers.length - answeredCustomers.size
            );

            const rate = sentCustomers.length
                ? ((answeredCustomers.size / sentCustomers.length) * 100).toFixed(1)
                : '0.0';

            const questions = App.util.allQuestions(survey);

            if (!Object.keys(App.state.selectedQuestions).length) {
                questions.forEach(x => {
                    App.state.selectedQuestions[x.question.id] = true;
                });
            }

            document.getElementById('main_content').innerHTML = `
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <div class="text-sm text-indigo-600 font-semibold mb-1">ANALYTICS</div>
                        <h1 class="text-2xl font-bold">${App.util.esc(survey.title)}</h1>
                    </div>

                    <div class="flex gap-2">
                        <button onclick="App.actions.csv('${surveyId}')"
                            class="px-4 py-2.5 rounded-xl border bg-white hover:bg-slate-50">
                            CSV出力
                        </button>
                        <button onclick="App.actions.printAnalytics()"
                            class="px-4 py-2.5 rounded-xl border bg-white hover:bg-slate-50">
                            PDF / 印刷
                        </button>
                        <button onclick="App.actions.goList()"
                            class="px-4 py-2.5 rounded-xl bg-indigo-600 text-white">
                            一覧へ戻る
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                    ${[
                        ['送信対象者数', sentCustomers.length + ' 人'],
                        ['回答数', responses.length + ' 件'],
                        ['未登録顧客からの回答数', unregistered + ' 件'],
                        ['未回答数', unanswered + ' 人'],
                        ['回答率', rate + ' %']
                    ].map(card => `
                        <div class="bg-white border rounded-2xl p-5 shadow-sm">
                            <div class="text-xs text-slate-500 mb-2">${card[0]}</div>
                            <div class="text-2xl font-bold">${card[1]}</div>
                        </div>
                    `).join('')}
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-[280px_1fr] gap-5">
                    <aside class="bg-white border rounded-2xl shadow-sm p-5 h-fit">
                        <div class="font-bold mb-4">設問絞り込み</div>

                        <div class="flex gap-2 mb-4">
                            <button onclick="App.actions.selectAllQuestions(true)"
                                class="text-xs px-2 py-1 bg-slate-100 rounded">全選択</button>
                            <button onclick="App.actions.selectAllQuestions(false)"
                                class="text-xs px-2 py-1 bg-slate-100 rounded">全解除</button>
                        </div>

                        <div class="space-y-2">
                            ${questions.map((x, i) => `
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox"
                                        ${App.state.selectedQuestions[x.question.id] ? 'checked' : ''}
                                        onchange="App.actions.toggleQuestion('${x.question.id}',this.checked)"
                                        class="mt-1 accent-indigo-600">
                                    <span>
                                        Q${i + 1}
                                        <span class="text-xs text-slate-400 block">
                                            ${App.util.esc(x.question.text)}
                                        </span>
                                    </span>
                                </label>
                            `).join('')}
                        </div>
                    </aside>

                    <div class="space-y-5">
                        ${
                            responses.length === 0
                            ? `
                            <div class="bg-white border rounded-2xl p-16 text-center text-slate-400">
                                現在、回答データはありません
                            </div>
                            `
                            : questions
                                .filter(x => App.state.selectedQuestions[x.question.id])
                                .map(x => App.render.questionAnalytics(x.question, responses))
                                .join('')
                        }

                        <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                            <div class="p-5 border-b">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <h2 class="font-bold">個別回答一覧</h2>
                                        <p class="text-xs text-slate-400 mt-1">回答者単位で詳細を確認できます。</p>
                                    </div>

                                    <input id="response_filter"
                                        value="${App.util.esc(App.state.responseKeyword)}"
                                        oninput="App.actions.responseFilter(this.value)"
                                        placeholder="会社名・氏名で検索"
                                        class="border rounded-lg px-3 py-2 w-64">
                                </div>
                            </div>

                            <div id="response_table">
                                ${App.render.responseTable(responses, questions)}
                            </div>
                        </section>
                    </div>
                </div>
            `;
        },

        questionAnalytics: function(question, responses) {
            const counts = {};
            const total = responses.length;

            (question.options || []).forEach(o => counts[o] = 0);
            counts['その他'] = 0;

            responses.forEach(response => {
                let value = response.answers?.[question.id];

                if (Array.isArray(value)) {
                    value.forEach(v => {
                        if (Object.prototype.hasOwnProperty.call(counts, v)) {
                            counts[v]++;
                        } else if (v) {
                            counts['その他']++;
                        }
                    });
                } else if (value) {
                    if (Object.prototype.hasOwnProperty.call(counts, value)) {
                        counts[value]++;
                    } else {
                        counts['その他']++;
                    }
                }
            });

            if (question.type === 'text') {
                const texts = responses
                    .map(r => ({
                        r,
                        value: r.answers?.[question.id]
                    }))
                    .filter(x => x.value);

                return `
                    <section class="bg-white border rounded-2xl shadow-sm p-5">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <div class="font-bold">${App.util.esc(question.text)}</div>
                                <span class="inline-flex mt-2 text-xs px-2 py-1 rounded bg-slate-100">
                                    自由記述
                                </span>
                            </div>
                        </div>

                        <div class="max-h-80 overflow-y-auto space-y-3">
                            ${
                                texts.length
                                ? texts.map(x => `
                                    <div class="border-l-4 border-indigo-200 pl-4 py-2">
                                        <div class="text-xs text-slate-400">
                                            ${App.util.esc(x.r.company || '')}
                                            ${App.util.esc(x.r.name || '')}
                                            ・${App.util.datetime(x.r.answered_at)}
                                        </div>
                                        <div class="mt-1 whitespace-pre-wrap">
                                            ${App.util.esc(x.value)}
                                        </div>
                                    </div>
                                `).join('')
                                : '<div class="text-slate-400">回答なし</div>'
                            }
                        </div>
                    </section>
                `;
            }

            return `
                <section class="bg-white border rounded-2xl shadow-sm p-5">
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <div class="font-bold">${App.util.esc(question.text)}</div>
                            <span class="inline-flex mt-2 text-xs px-2 py-1 rounded bg-slate-100">
                                ${App.util.typeLabel(question.type)}
                            </span>
                        </div>
                    </div>

                    <div class="space-y-4">
                        ${Object.entries(counts).map(([label, count]) => {
                            const percent = total ? (count / total * 100) : 0;

                            return `
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span>${App.util.esc(label)}</span>
                                        <span>${count} 件 / ${percent.toFixed(1)}%</span>
                                    </div>
                                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                        <div class="h-full bg-indigo-500 rounded-full"
                                            style="width:${Math.min(percent,100)}%"></div>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </section>
            `;
        },

        responseTable: function(responses, questions) {
            const keyword = App.state.responseKeyword.toLowerCase();

            const filtered = responses.filter(r =>
                !keyword ||
                String(r.company || '').toLowerCase().includes(keyword) ||
                String(r.name || '').toLowerCase().includes(keyword)
            );

            return `
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-left">会社名</th>
                                <th class="px-5 py-3 text-left">氏名</th>
                                <th class="px-5 py-3 text-left">回答日時</th>
                                <th class="px-5 py-3 text-left">回答</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${
                                filtered.map(r => `
                                    <tr class="border-t">
                                        <td class="px-5 py-4">${App.util.esc(r.company || '')}</td>
                                        <td class="px-5 py-4 font-semibold">${App.util.esc(r.name || '')}</td>
                                        <td class="px-5 py-4">${App.util.datetime(r.answered_at)}</td>
                                        <td class="px-5 py-4">
                                            <button onclick="App.actions.showResponse('${r.id}')"
                                                class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100">
                                                全回答を表示
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')
                                || `
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center text-slate-400">
                                        該当する回答がありません。
                                    </td>
                                </tr>
                                `
                            }
                        </tbody>
                    </table>
                </div>
            `;
        },

        mail: function(surveyId) {
            App.state.screen = 'mail';
            App.state.currentSurveyId = surveyId;

            const survey = App.state.surveys.find(s => s.id === surveyId);

            const customers = App.state.customers;

            document.getElementById('main_content').innerHTML = `
                <div class="mb-6">
                    <div class="text-sm text-indigo-600 font-semibold mb-1">
                        HOME ＞ アンケート一覧 ＞ 顧客選択・送信
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold">顧客選択・メール送信</h1>
                            <p class="text-slate-500 mt-1">${App.util.esc(survey?.title || '')}</p>
                        </div>

                        <button onclick="App.actions.goList()"
                            class="px-4 py-2.5 rounded-xl border bg-white">
                            一覧へ戻る
                        </button>
                    </div>
                </div>

                ${
                    customers.some(c => c.kintone_status === 'unregistered')
                    ? `
                    <div class="mb-5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3">
                        kintone未登録の回答者が存在します。
                    </div>
                    `
                    : ''
                }

                <section class="bg-white border rounded-2xl shadow-sm p-5 mb-5">
                    <div class="grid md:grid-cols-3 gap-4">
                        <label>
                            <span class="text-sm font-semibold">件名</span>
                            <input id="mail_subject"
                                value="【アンケート】ご回答をお願いします"
                                class="w-full border rounded-xl px-3 py-2.5 mt-2">
                        </label>

                        <label>
                            <span class="text-sm font-semibold">送信種別</span>
                            <select id="template_type"
                                onchange="App.actions.templateType(this.value)"
                                class="w-full border rounded-xl px-3 py-2.5 mt-2">
                                <option value="initial">初回送信</option>
                                <option value="reminder">リマインド</option>
                            </select>
                        </label>

                        <div class="flex items-end">
                            <button onclick="App.actions.sendSelected()"
                                class="w-full py-2.5 rounded-xl bg-indigo-600 text-white font-semibold hover:bg-indigo-700">
                                選択した顧客へ一括送信
                            </button>
                        </div>
                    </div>

                    <label class="block mt-4">
                        <span class="text-sm font-semibold">本文</span>
                        <textarea id="mail_body" rows="6"
                            class="w-full border rounded-xl px-3 py-2.5 mt-2">${App.util.esc('{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}\n\nよろしくお願いいたします。')}</textarea>
                    </label>

                    <div class="mt-3 text-xs text-slate-400">
                        使用可能な変数：{顧客名}　{アンケートURL}
                    </div>
                </section>

                <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                    <div class="p-4 border-b flex flex-wrap gap-3">
                        <input id="customer_filter"
                            oninput="App.actions.customerFilter(this.value)"
                            placeholder="顧客名・メールアドレスで検索"
                            class="flex-1 min-w-[250px] border rounded-xl px-3 py-2.5">

                        <button onclick="App.actions.selectUnanswered()"
                            class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200">
                            未回答のみ
                        </button>

                        <button onclick="App.actions.syncCustomers()"
                            class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200">
                            顧客一覧を更新
                        </button>
                    </div>

                    <div id="customer_table">
                        ${App.render.customerTable(customers)}
                    </div>
                </section>

                <section class="bg-white border rounded-2xl shadow-sm p-5 mt-5">
                    <h2 class="font-bold mb-4">一括送信履歴</h2>
                    ${
                        App.state.mail_logs.filter(x => x.survey_id === surveyId).length
                        ? `
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left border-b">
                                        <th class="py-3">送信日時</th>
                                        <th>種別</th>
                                        <th>件数</th>
                                        <th>件名</th>
                                        <th>実行者</th>
                                    </tr>
                                </thead>
                                <tbody>
                                ${App.state.mail_logs
                                    .filter(x => x.survey_id === surveyId)
                                    .slice()
                                    .reverse()
                                    .map(x => `
                                        <tr class="border-b">
                                            <td class="py-3">${App.util.datetime(x.sent_at)}</td>
                                            <td>${x.template_type === 'reminder' ? 'リマインド' : '初回'}</td>
                                            <td>${x.count}</td>
                                            <td>${App.util.esc(x.subject)}</td>
                                            <td>${App.util.esc(x.executor)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        `
                        : '<div class="text-sm text-slate-400">送信履歴はありません。</div>'
                    }
                </section>
            `;

            App.actions.refreshCustomerTable();
        },

        customerTable: function(customers) {
            const keyword = App.state.customerKeyword.toLowerCase();

            const filtered = customers.filter(c =>
                !keyword ||
                String(c.name || '').toLowerCase().includes(keyword) ||
                String(c.email || '').toLowerCase().includes(keyword) ||
                String(c.company || '').toLowerCase().includes(keyword)
            );

            return `
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1100px] text-sm">
                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="px-4 py-3">
                                    <input id="select_all" type="checkbox"
                                        onchange="App.actions.selectAllCustomers(this.checked)"
                                        class="accent-indigo-600">
                                </th>
                                <th class="px-4 py-3 text-left">会社名 / 氏名</th>
                                <th class="px-4 py-3 text-left">メール</th>
                                <th class="px-4 py-3 text-left">電話番号</th>
                                <th class="px-4 py-3 text-left">住所</th>
                                <th class="px-4 py-3 text-left">送信状況</th>
                                <th class="px-4 py-3 text-left">回答状況</th>
                                <th class="px-4 py-3">kintone</th>
                            </tr>
                        </thead>
                        <tbody>
                        ${filtered.map(c => `
                            <tr class="border-b hover:bg-slate-50">
                                <td class="px-4 py-4">
                                    ${
                                        c.source === 'web'
                                        ? '<span class="text-xs text-slate-400">Web回答</span>'
                                        : `
                                        <input
                                            type="checkbox"
                                            data-customer-select
                                            value="${App.util.esc(c.id)}"
                                            class="accent-indigo-600 w-4 h-4">
                                        `
                                    }
                                </td>

                                <td class="px-4 py-4">
                                    <div class="font-bold">${App.util.esc(c.company)}</div>
                                    <div>${App.util.esc(c.name)}</div>
                                </td>

                                <td class="px-4 py-4">${App.util.esc(c.email)}</td>
                                <td class="px-4 py-4">${App.util.esc(c.phone)}</td>
                                <td class="px-4 py-4">${App.util.esc(c.address)}</td>

                                <td class="px-4 py-4">
                                    ${
                                        c.sent_at
                                        ? `
                                        <div class="text-xs">
                                            最終送信: ${App.util.datetime(c.sent_at)}
                                        </div>
                                        <div class="text-xs text-slate-400 mt-1">
                                            ${c.send_count || 0} 回送信
                                        </div>
                                        `
                                        : '<span class="text-slate-400">未送信</span>'
                                    }
                                </td>

                                <td class="px-4 py-4">
                                    <span class="px-2 py-1 rounded-full text-xs ${
                                        c.answer_status === 'answered'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-amber-100 text-amber-700'
                                    }">
                                        ${
                                            c.answer_status === 'answered'
                                            ? '回答済み'
                                            : c.sent_at
                                                ? '送信済み（未回答）'
                                                : '未送信'
                                        }
                                    </span>
                                </td>

                                <td class="px-4 py-4">
                                    ${
                                        c.kintone_status === 'registered'
                                        ? '<span class="text-emerald-600 text-xs font-semibold">✓ キントーン登録完了</span>'
                                        : `
                                        <button onclick="App.actions.markKintone('${c.id}')"
                                            class="text-xs px-2.5 py-1.5 rounded-lg bg-amber-50 text-amber-700">
                                            キントーン登録完了
                                        </button>
                                        `
                                    }
                                </td>
                            </tr>
                        `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        },

        settings: function() {
            App.state.screen = 'settings';

            const s = App.state.settings || {};

            document.getElementById('main_content').innerHTML = `
                <div class="mb-6">
                    <div class="text-sm text-indigo-600 font-semibold mb-1">
                        HOME ＞ システム設定 ＞ kintone連携設定
                    </div>
                    <h1 class="text-2xl font-bold">kintone連携設定</h1>
                </div>

                <form id="settings_form"
                    onsubmit="event.preventDefault();App.actions.saveSettings()"
                    class="space-y-5">

                    <section class="bg-white border rounded-2xl shadow-sm p-6">
                        <h2 class="font-bold text-lg mb-5">接続・認証設定</h2>

                        <div class="grid md:grid-cols-2 gap-4">
                            <label>
                                <span class="text-sm font-semibold">サブドメイン</span>
                                <input id="setting_subdomain"
                                    value="${App.util.esc(s.subdomain || '')}"
                                    placeholder="xxxx.cybozu.com"
                                    class="w-full border rounded-xl px-3 py-3 mt-2">
                            </label>

                            <label>
                                <span class="text-sm font-semibold">顧客管理アプリID</span>
                                <input id="setting_app_id"
                                    value="${App.util.esc(s.app_id || '')}"
                                    class="w-full border rounded-xl px-3 py-3 mt-2">
                            </label>

                            <label>
                                <span class="text-sm font-semibold">ログイン名</span>
                                <input id="setting_login_name"
                                    value="${App.util.esc(s.login_name || '')}"
                                    class="w-full border rounded-xl px-3 py-3 mt-2">
                            </label>

                            <label>
                                <span class="text-sm font-semibold">パスワード</span>
                                <input id="setting_password"
                                    type="password"
                                    value="${App.util.esc(s.password || '')}"
                                    class="w-full border rounded-xl px-3 py-3 mt-2">
                            </label>

                            <label class="md:col-span-2">
                                <span class="text-sm font-semibold">Proxyサーバ</span>
                                <input id="setting_proxy"
                                    value="${App.util.esc(s.proxy || '')}"
                                    placeholder="host名:port番号"
                                    class="w-full border rounded-xl px-3 py-3 mt-2">
                            </label>
                        </div>

                        <label class="flex items-center gap-2 mt-5 text-sm">
                            <input id="setting_ssl_verify"
                                type="checkbox"
                                ${s.ssl_verify ? 'checked' : ''}
                                class="accent-indigo-600">
                            SSL証明書を検証する
                        </label>

                        <div class="flex gap-3 mt-6">
                            <button type="button" onclick="App.actions.testKintone()"
                                class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white">
                                接続確認
                            </button>

                            <button type="submit"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold">
                                設定を保存
                            </button>

                            <button type="button" onclick="App.actions.fetchKintoneFields()"
                                class="px-4 py-2.5 rounded-xl bg-slate-100">
                                項目一覧を取得
                            </button>

                            <button type="button" onclick="App.actions.syncCustomers()"
                                class="px-4 py-2.5 rounded-xl bg-slate-100">
                                顧客データを手動同期
                            </button>
                        </div>

                        <div id="field_message" class="mt-4 text-sm"></div>
                    </section>

                    <section class="bg-white border rounded-2xl shadow-sm p-6">
                        <h2 class="font-bold text-lg mb-5">項目マッピング</h2>

                        <div id="field_mapping" class="space-y-4">
                            ${App.render.mapping(s)}
                        </div>
                    </section>
                </form>
            `;
        },

        mapping: function(s) {
            const options = App.cache.kintoneFields || [];

            function select(name, label, multiple = false) {
                const selected = multiple
                    ? (Array.isArray(s[name]) ? s[name] : [])
                    : [s[name] || ''];

                return `
                    <label class="block">
                        <span class="text-sm font-semibold">${label}</span>
                        <select
                            ${multiple ? 'multiple size="4"' : ''}
                            data-setting-field="${name}"
                            class="w-full border rounded-xl px-3 py-3 mt-2 bg-white">
                            ${
                                !multiple
                                ? '<option value="">-- 選択してください --</option>'
                                : ''
                            }

                            ${options.map(f => `
                                <option value="${App.util.esc(f.code)}"
                                    ${selected.includes(f.code) ? 'selected' : ''}>
                                    ${App.util.esc(f.label)} (${App.util.esc(f.code)})
                                </option>
                            `).join('')}
                        </select>
                    </label>
                `;
            }

            return `
                <div class="grid md:grid-cols-2 gap-5">
                    ${select('field_company', '会社名 (Company)')}
                    ${select('field_name', '氏名 (Name)')}
                    ${select('field_email', 'メールアドレス (Email)')}
                    ${select('field_department', '部署名 (Department)')}
                    ${select('field_phone', '電話番号 (Phone)')}
                    ${select('field_address', '住所 (Address)', true)}
                </div>
            `;
        }
    },

    actions: {
        goList: function() {
            App.state.screen = 'list';
            App.render.list();
        },

        newSurvey: function() {
            const survey = {
                id: App.util.uid('survey'),
                title: '新しいアンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: '',
                updated_at: '',
                numbering_mode: 'global',
                groups: [
                    {
                        id: App.util.uid('group'),
                        name: 'グループ1',
                        questions: []
                    }
                ],
                deleted: false
            };

            App.render.editor(survey);
        },

        editSurvey: function(id) {
            const survey = App.state.surveys.find(s => s.id === id);

            if (!survey) {
                alert('アンケートが見つかりません。');
                return;
            }

            App.render.editor(survey);
        },

        editorTitle: function(value) {
            if (!App.state.currentSurvey) return;
            App.state.currentSurvey.title = value;
            App.state.dirty = true;
        },

        editorField: function(field, value) {
            if (!App.state.currentSurvey) return;
            App.state.currentSurvey[field] = value;
            App.state.dirty = true;
        },

        groupName: function(id, value) {
            const group = App.state.currentSurvey.groups.find(g => g.id === id);
            if (group) {
                group.name = value;
                App.state.dirty = true;
            }
        },

        addGroup: function() {
            const survey = App.state.currentSurvey;

            if (!survey) return;

            survey.groups.push({
                id: App.util.uid('group'),
                name: 'グループ' + (survey.groups.length + 1),
                questions: []
            });

            App.state.dirty = true;
            App.render.editorBody();
        },

        deleteGroup: function(id) {
            const survey = App.state.currentSurvey;
            const group = survey.groups.find(g => g.id === id);

            if (!group) return;

            if (!confirm(
                'このグループを削除しますか？\n内包されている質問もすべて削除されます。'
            )) return;

            survey.groups = survey.groups.filter(g => g.id !== id);
            App.state.dirty = true;
            App.render.editorBody();
        },

        addQuestion: function(groupId) {
            const survey = App.state.currentSurvey;
            const group = survey.groups.find(g => g.id === groupId);

            if (!group) return;

            group.questions.push({
                id: App.util.uid('question'),
                text: '',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false
            });

            App.state.dirty = true;
            App.render.editorBody();
        },

        findQuestion: function(id) {
            const survey = App.state.currentSurvey;

            for (const group of survey.groups) {
                const index = group.questions.findIndex(q => q.id === id);

                if (index >= 0) {
                    return {
                        group,
                        question: group.questions[index],
                        index
                    };
                }
            }

            return null;
        },

        questionNumber: function(id) {
            const survey = App.state.currentSurvey;
            let global = 0;

            for (let gi = 0; gi < survey.groups.length; gi++) {
                const group = survey.groups[gi];

                for (let qi = 0; qi < group.questions.length; qi++) {
                    global++;

                    if (group.questions[qi].id === id) {
                        return survey.numbering_mode === 'group'
                            ? 'Q' + (gi + 1) + '-' + (qi + 1)
                            : 'Q' + global;
                    }
                }
            }

            return '';
        },

        questionText: function(id, value) {
            const found = App.actions.findQuestion(id);
            if (found) {
                found.question.text = value;
                App.state.dirty = true;
            }
        },

        questionType: function(id, value) {
            const found = App.actions.findQuestion(id);
            if (!found) return;

            found.question.type = value;

            if (value === 'text') {
                found.question.options = [];
                found.question.other_enabled = false;
            } else if (!Array.isArray(found.question.options) || !found.question.options.length) {
                found.question.options = ['選択肢1', '選択肢2'];
            }

            App.state.dirty = true;
            App.render.editorBody();
        },

        questionRequired: function(id, value) {
            const found = App.actions.findQuestion(id);
            if (found) {
                found.question.required = !!value;
                App.state.dirty = true;
            }
        },

        questionOther: function(id, value) {
            const found = App.actions.findQuestion(id);
            if (found) {
                found.question.other_enabled = !!value;
                App.state.dirty = true;
            }
        },

        deleteQuestion: function(id) {
            const found = App.actions.findQuestion(id);

            if (!found) return;

            if (!confirm('この質問を削除しますか？')) return;

            found.group.questions.splice(found.index, 1);
            App.state.dirty = true;
            App.render.editorBody();
        },

        addOption: function(id) {
            const found = App.actions.findQuestion(id);

            if (!found) return;

            found.question.options.push(
                '選択肢' + (found.question.options.length + 1)
            );

            App.state.dirty = true;
            App.render.editorBody();
        },

        deleteOption: function(id, index) {
            const found = App.actions.findQuestion(id);

            if (!found) return;

            found.question.options.splice(index, 1);
            App.state.dirty = true;
            App.render.editorBody();
        },

        optionText: function(id, index, value) {
            const found = App.actions.findQuestion(id);

            if (found && found.question.options[index] !== undefined) {
                found.question.options[index] = value;
                App.state.dirty = true;
            }
        },

        setupSortable: function() {
            if (typeof Sortable === 'undefined') return;

            const container = document.getElementById('groups_container');

            if (container) {
                Sortable.create(container, {
                    animation: 180,
                    handle: '.group-handle',
                    ghostClass: 'opacity-40',
                    onEnd: function(evt) {
                        const survey = App.state.currentSurvey;

                        if (evt.oldIndex === evt.newIndex) return;

                        const moved = survey.groups.splice(evt.oldIndex, 1)[0];
                        survey.groups.splice(evt.newIndex, 0, moved);

                        App.state.dirty = true;
                        App.render.editorBody();
                    }
                });
            }

            document.querySelectorAll('.question-list').forEach(list => {
                Sortable.create(list, {
                    group: 'survey-questions',
                    animation: 180,
                    handle: '.question-handle',
                    draggable: '.question-card',
                    ghostClass: 'opacity-40',
                    filter: 'button',
                    onEnd: function(evt) {
                        const survey = App.state.currentSurvey;
                        const sourceGroupId = evt.from.dataset.groupId;
                        const targetGroupId = evt.to.dataset.groupId;

                        const sourceGroup = survey.groups.find(
                            g => g.id === sourceGroupId
                        );
                        const targetGroup = survey.groups.find(
                            g => g.id === targetGroupId
                        );

                        if (!sourceGroup || !targetGroup) return;

                        const questionId = evt.item.dataset.questionId;

                        const sourceIndex = sourceGroup.questions.findIndex(
                            q => q.id === questionId
                        );

                        if (sourceIndex < 0) return;

                        const moved = sourceGroup.questions.splice(sourceIndex, 1)[0];

                        targetGroup.questions.splice(evt.newIndex, 0, moved);

                        App.state.dirty = true;
                        App.render.editorBody();
                    }
                });
            }
        },

        saveSurvey: async function() {
            const survey = App.state.currentSurvey;

            if (!survey) return;

            if (!String(survey.title || '').trim()) {
                alert('タイトルを入力してください。');
                return;
            }

            try {
                const result = await App.api.request('save_survey', {
                    survey_json: survey
                });

                App.state.surveys = result.data.surveys || [];
                App.state.dirty = false;

                alert('アンケートを保存しました。');
                App.actions.goList();
            } catch (error) {
                alert(error.message);
            }
        },

        cancelEdit: function() {
            if (
                App.state.dirty &&
                !confirm('未保存の変更を破棄して一覧へ戻りますか？')
            ) {
                return;
            }

            App.actions.goList();
        },

        stopSurvey: async function(id) {
            if (!confirm('このアンケートを停止しますか？')) return;

            try {
                const result = await App.api.request('change_status', {
                    survey_id: id,
                    status: 'ended'
                });

                App.state.surveys = result.data.surveys || [];
                App.render.list();
            } catch (error) {
                alert(error.message);
            }
        },

        deleteSurvey: async function(id) {
            if (!confirm('この下書きを削除しますか？')) return;

            try {
                const result = await App.api.request('delete_survey', {
                    survey_id: id
                });

                App.state.surveys = result.data.surveys || [];
                App.render.list();
            } catch (error) {
                alert(error.message);
            }
        },

        duplicate: async function(id) {
            try {
                const result = await App.api.request('duplicate_survey', {
                    survey_id: id
                });

                App.state.surveys = result.data.surveys || [];
                App.render.list();

                alert('下書きとして複製しました。');
            } catch (error) {
                alert(error.message);
            }
        },

        preview: function() {
            App.state.previewMobile = false;
            App.render.preview();
        },

        previewMode: function(mobile) {
            App.state.previewMobile = mobile;
            App.render.preview();
        },

        closePreview: function() {
            document.getElementById('preview_modal').innerHTML = '';
        },

        previewSubmit: function() {
            alert('これはプレビューです。実際の回答は送信されません。');
        },

        analytics: function(id) {
            App.state.selectedQuestions = {};
            App.render.analytics(id);
        },

        toggleQuestion: function(id, checked) {
            App.state.selectedQuestions[id] = checked;
            App.render.analytics(App.state.currentSurveyId);
        },

        selectAllQuestions: function(flag) {
            const survey = App.state.surveys.find(
                s => s.id === App.state.currentSurveyId
            );

            if (!survey) return;

            App.util.allQuestions(survey).forEach(x => {
                App.state.selectedQuestions[x.question.id] = flag;
            });

            App.render.analytics(App.state.currentSurveyId);
        },

        responseFilter: function(value) {
            App.state.responseKeyword = value;

            const surveyResponses = App.state.responses.filter(
                r => r.survey_id === App.state.currentSurveyId
            );

            const survey = App.state.surveys.find(
                s => s.id === App.state.currentSurveyId
            );

            const table = document.getElementById('response_table');

            if (table && survey) {
                table.innerHTML = App.render.responseTable(
                    surveyResponses,
                    App.util.allQuestions(survey)
                );
            }
        },

        showResponse: function(responseId) {
            const response = App.state.responses.find(r => r.id === responseId);

            if (!response) return;

            const survey = App.state.surveys.find(
                s => s.id === response.survey_id
            );

            const questions = survey
                ? App.util.allQuestions(survey)
                : [];

            document.getElementById('response_modal').innerHTML = `
                <div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                    <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-hidden">
                        <div class="px-5 py-4 border-b flex justify-between">
                            <div>
                                <div class="font-bold">全回答</div>
                                <div class="text-xs text-slate-400 mt-1">
                                    ${App.util.esc(response.company || '')}
                                    ${App.util.esc(response.name || '')}
                                </div>
                            </div>
                            <button onclick="App.actions.closeResponse()"
                                class="text-2xl text-slate-400">×</button>
                        </div>

                        <div id="response_detail" class="p-6 overflow-y-auto max-h-[75vh] space-y-5">
                            ${questions.map((x, i) => `
                                <div class="border-b pb-4">
                                    <div class="text-xs text-indigo-600 font-semibold">
                                        Q${i + 1}
                                    </div>
                                    <div class="font-semibold mt-1">
                                        ${App.util.esc(x.question.text)}
                                    </div>
                                    <div class="mt-2 bg-slate-50 rounded-lg p-3 whitespace-pre-wrap">
                                        ${App.util.esc(
                                            Array.isArray(response.answers?.[x.question.id])
                                                ? response.answers[x.question.id].join(', ')
                                                : response.answers?.[x.question.id] ?? '未回答'
                                        )}
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `;
        },

        closeResponse: function() {
            document.getElementById('response_modal').innerHTML = '';
        },

        csv: function(id) {
            location.href =
                location.pathname +
                '?action=csv&survey_id=' +
                encodeURIComponent(id);
        },

        printAnalytics: function() {
            window.print();
        },

        mail: function(id) {
            App.state.customerKeyword = '';
            App.render.mail(id);
        },

        customerFilter: function(value) {
            App.state.customerKeyword = value;
            App.actions.refreshCustomerTable();
        },

        refreshCustomerTable: function() {
            const table = document.getElementById('customer_table');

            if (table) {
                table.innerHTML = App.render.customerTable(
                    App.state.customers
                );
            }
        },

        selectAllCustomers: function(checked) {
            document.querySelectorAll('[data-customer-select]').forEach(el => {
                el.checked = checked;
            });
        },

        selectUnanswered: function() {
            document.querySelectorAll('[data-customer-select]').forEach(el => {
                const customer = App.state.customers.find(
                    c => c.id === el.value
                );

                el.checked = !!customer &&
                    customer.answer_status !== 'answered';
            });
        },

        templateType: function(value) {
            const body = document.getElementById('mail_body');

            if (!body) return;

            if (value === 'reminder') {
                body.value =
                    '{顧客名} 様\n\n先日ご案内したアンケートが未回答となっております。\n' +
                    'お手数ですが、以下よりご回答をお願いいたします。\n\n{アンケートURL}';
            } else {
                body.value =
                    '{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}';
            }
        },

        sendSelected: async function() {
            const ids = Array.from(
                document.querySelectorAll('[data-customer-select]:checked')
            ).map(el => el.value);

            if (!ids.length) {
                alert('送信先を選択してください。');
                return;
            }

            const alreadySent = ids.some(id => {
                const c = App.state.customers.find(x => x.id === id);
                return c && Number(c.send_count || 0) > 0;
            });

            let templateType =
                document.getElementById('template_type')?.value || 'initial';

            if (alreadySent) {
                const again = confirm(
                    '既に送信済みの宛先が含まれています。\n再送しますか？'
                );

                if (!again) return;

                templateType = 'reminder';

                const selector = document.getElementById('template_type');
                if (selector) selector.value = 'reminder';
            }

            const subject =
                document.getElementById('mail_subject')?.value || '';

            const body =
                document.getElementById('mail_body')?.value || '';

            if (!confirm(ids.length + '件の宛先へ送信処理を実行しますか？')) {
                return;
            }

            try {
                const result = await App.api.request('send_mail', {
                    survey_id: App.state.currentSurveyId,
                    recipient_ids: ids,
                    mail_subject: subject,
                    mail_body: body,
                    template_type: templateType
                });

                App.state.customers = result.data.customers || [];
                App.state.mail_logs = result.data.mail_logs || [];

                alert(
                    result.count +
                    '件の送信処理を記録しました。\n' +
                    '※実際のメール配送にはサーバー側メール環境の設定が必要です。'
                );

                App.render.mail(App.state.currentSurveyId);
            } catch (error) {
                alert(error.message);
            }
        },

        markKintone: async function(id) {
            try {
                const result = await App.api.request('mark_kintone', {
                    customer_id: id
                });

                App.state.customers = result.data.customers || [];
                App.actions.refreshCustomerTable();
            } catch (error) {
                alert(error.message);
            }
        },

        syncCustomers: async function() {
            if (!confirm('kintoneから顧客データを取得しますか？')) return;

            try {
                const result = await App.api.request('sync_customers');

                App.state.customers = result.data.customers || [];

                alert(result.count + '件の顧客データを同期しました。');

                if (App.state.screen === 'settings') {
                    App.render.settings();
                } else {
                    App.render.mail(App.state.currentSurveyId);
                }
            } catch (error) {
                alert(error.message);
            }
        },

        settings: function() {
            App.render.settings();
        },

        collectSettings: function() {
            const mapping = {};

            document.querySelectorAll('[data-setting-field]').forEach(el => {
                const name = el.dataset.settingField;

                if (el.multiple) {
                    mapping[name] = Array.from(el.selectedOptions)
                        .map(o => o.value)
                        .filter(Boolean);
                } else {
                    mapping[name] = el.value;
                }
            });

            return {
                subdomain: document.getElementById('setting_subdomain')?.value || '',
                app_id: document.getElementById('setting_app_id')?.value || '',
                login_name: document.getElementById('setting_login_name')?.value || '',
                password: document.getElementById('setting_password')?.value || '',
                proxy: document.getElementById('setting_proxy')?.value || '',
                ssl_verify: !!document.getElementById('setting_ssl_verify')?.checked,
                ...mapping
            };
        },

        saveSettings: async function() {
            const settings = App.actions.collectSettings();

            try {
                const result = await App.api.request('save_settings', {
                    settings_json: settings
                });

                App.state.settings = result.data.settings || settings;

                alert('設定を保存しました。');
            } catch (error) {
                alert(error.message);
            }
        },

        testKintone: async function() {
            const settings = App.actions.collectSettings();

            try {
                await App.api.request('save_settings', {
                    settings_json: settings
                });

                App.state.settings = settings;

                const result = await App.api.request('kintone_test');

                const message =
                    result.message +
                    '\nHTTP Status: ' +
                    (result.status || '-');

                document.getElementById('field_message').textContent = message;
            } catch (error) {
                document.getElementById('field_message').textContent =
                    '接続エラー: ' + error.message;
            }
        },

        fetchKintoneFields: async function() {
            const settings = App.actions.collectSettings();

            if (!settings.app_id) {
                alert('顧客管理アプリIDを入力してください。');
                return;
            }

            try {
                await App.api.request('save_settings', {
                    settings_json: settings
                });

                App.state.settings = settings;

                const result = await App.api.request(
                    'fetch_kintone_fields',
                    { app_id: settings.app_id }
                );

                App.cache.kintoneFields = result.fields || [];

                document.getElementById('field_message').textContent =
                    App.cache.kintoneFields.length +
                    '件のフィールドを取得しました。';

                App.render.settings();
            } catch (error) {
                const message = document.getElementById('field_message');

                if (message) {
                    message.textContent =
                        'フィールド取得エラー: ' + error.message;
                } else {
                    alert(error.message);
                }
            }
        },

        searchSurveys: function(value) {
            App.state.keyword = value;
            App.render.list();
        },

        toggleStatusFilter: function(value) {
            App.state.status_filter = value;
            App.render.list();
        },

        sortSurveys: function(value) {
            App.state.sort = value;
            App.render.list();
        },

        logout: function() {
            alert('ログアウト機能は単一管理者構成のため、現在はセッション破棄のみ実装されています。');
        }
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        App.init();
    }, { once: true });
} else {
    App.init();
}
</script>

</body>
</html>
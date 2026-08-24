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
- branching

分岐項目:
- option
- target_question_id

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
- status
- answers
- email
- name
- company

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields
- data
- csrf_token
- status
- error_code
- endpoint

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
- field_company
- field_name
- field_email
- field_department
- field_phone
- field_address
- survey_list
- editor_groups
- editor_group_list
- kintone_status
- branching_editor

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

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* ================================================================
 * PHP共通
 * ================================================================ */

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
            'field_address' => []
        ],
        'mail_logs' => []
    ];
}

function survey_load_data(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        $data = survey_default_data();
        survey_save_data($data);
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        $data = survey_default_data();
    }

    $defaults = survey_default_data();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    $data['settings'] = array_merge(
        $defaults['settings'],
        is_array($data['settings'] ?? null) ? $data['settings'] : []
    );

    return $data;
}

function survey_save_data(array $data): bool
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

    if (is_file(SURVEY_STORAGE_FILE)) {
        @copy(SURVEY_STORAGE_FILE, SURVEY_STORAGE_FILE . '.bak');
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_json_response(array $payload, int $status = 200): never
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

function survey_id(string $prefix = 'id'): string
{
    try {
        return $prefix . '_' . bin2hex(random_bytes(10));
    } catch (Throwable) {
        return $prefix . '_' . uniqid('', true);
    }
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_check_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(survey_csrf(), $token)) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

function survey_normalize_kintone_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('#^\s*https?://#i', '', $value);
    $value = preg_replace('#/.*$#', '', (string)$value);
    $value = trim((string)$value, ". \t\n\r\0\x0B");

    if ($value === '') {
        return '';
    }

    if (preg_match('/\.cybozu\.com$/i', $value)) {
        return 'https://' . $value;
    }

    return 'https://' . $value . '.cybozu.com';
}

/* PHP 8.4/8.5対応 */
function survey_http_status(): int
{
    if (!function_exists('http_get_last_response_headers')) {
        return 0;
    }

    $headers = http_get_last_response_headers();

    if (!is_array($headers)) {
        return 0;
    }

    foreach ($headers as $header) {
        if (preg_match(
            '/^HTTP\/[\d.]+\s+(\d+)/i',
            (string)$header,
            $match
        )) {
            return (int)$match[1];
        }
    }

    return 0;
}

function survey_kintone_request(
    string $method,
    string $path,
    array $settings,
    ?array $body = null
): array {
    $baseUrl = survey_normalize_kintone_host(
        (string)($settings['subdomain'] ?? '')
    );

    if ($baseUrl === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' => 'kintoneのサブドメイン/FQDNが設定されていません。'
        ];
    }

    $url = $baseUrl . '/' . ltrim($path, '/');

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' => 'kintoneのログイン名とパスワードを入力してください。',
            'endpoint' => $url
        ];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password)
    ];

    $verify = !empty($settings['ssl_verify']);

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 30
        ],
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify
        ]
    ];

    if ($body !== null) {
        $encoded = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'JSON',
                'message' => 'JSON生成に失敗しました。',
                'endpoint' => $url
            ];
        }

        $options['http']['content'] = $encoded;
    }

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'PROXY',
                'message' => 'Proxyサーバは host:port 形式で入力してください。',
                'endpoint' => $url
            ];
        }

        $options['http']['proxy'] = 'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $result = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = survey_http_status();

    $decoded = json_decode((string)$result, true);

    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'status' => $status,
            'data' => $decoded,
            'endpoint' => $url
        ];
    }

    return [
        'ok' => false,
        'status' => $status,
        'error_code' => (string)(
            $decoded['code'] ??
            $decoded['error_code'] ??
            ''
        ),
        'message' => (string)(
            $decoded['message'] ??
            'kintone API通信に失敗しました。'
        ),
        'data' => $decoded,
        'endpoint' => $url
    ];
}

function survey_public_url(
    string $surveyId,
    string $customerId = ''
): string {
    $scheme =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');

    return $scheme . '://' . $host . $path . '?' .
        http_build_query([
            'public' => '1',
            'survey_id' => $surveyId,
            'customer_id' => $customerId
        ]);
}

/* ================================================================
 * API
 * ================================================================ */

$action = (string)($_REQUEST['action'] ?? '');

if ($action !== '') {
    $data = survey_load_data();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        survey_check_csrf();
    }

    switch ($action) {
        case 'load':
            survey_json_response([
                'ok' => true,
                'data' => $data,
                'csrf_token' => survey_csrf()
            ]);
            break;

        case 'save_survey':
            $survey = json_decode(
                (string)($_POST['survey_json'] ?? ''),
                true
            );

            if (!is_array($survey) || empty($survey['id'])) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $defaults = [
                'id' => survey_id('survey'),
                'title' => '新しいアンケート',
                'start_at' => '',
                'end_at' => '',
                'status' => 'draft',
                'created_at' => '',
                'updated_at' => '',
                'numbering_mode' => 'global',
                'groups' => [],
                'deleted' => false
            ];

            $survey = array_merge($defaults, $survey);

            if (!in_array(
                $survey['status'],
                ['draft', 'active', 'ended'],
                true
            )) {
                $survey['status'] = 'draft';
            }

            if (!in_array(
                $survey['numbering_mode'],
                ['global', 'group'],
                true
            )) {
                $survey['numbering_mode'] = 'global';
            }

            if (!is_array($survey['groups'])) {
                $survey['groups'] = [];
            }

            foreach ($survey['groups'] as &$group) {
                $group['id'] =
                    (string)($group['id'] ?? survey_id('group'));

                $group['name'] =
                    (string)($group['name'] ?? 'グループ');

                if (!is_array($group['questions'] ?? null)) {
                    $group['questions'] = [];
                }

                foreach ($group['questions'] as &$question) {
                    $question['id'] =
                        (string)($question['id'] ?? survey_id('question'));

                    $question['text'] =
                        (string)($question['text'] ?? '');

                    $question['type'] =
                        in_array(
                            $question['type'] ?? '',
                            ['single', 'multiple', 'text'],
                            true
                        )
                            ? $question['type']
                            : 'single';

                    $question['required'] =
                        !empty($question['required']);

                    $question['other_enabled'] =
                        !empty($question['other_enabled']);

                    $question['options'] =
                        is_array($question['options'] ?? null)
                            ? array_values($question['options'])
                            : [];

                    $question['branching'] =
                        is_array($question['branching'] ?? null)
                            ? array_values($question['branching'])
                            : [];

                    if ($question['type'] !== 'single') {
                        $question['branching'] = [];
                    }
                }

                unset($question);
            }

            unset($group);

            $now = survey_now();
            $found = false;

            foreach ($data['surveys'] as $index => $existing) {
                if (
                    (string)($existing['id'] ?? '') ===
                    (string)$survey['id']
                ) {
                    $survey['created_at'] =
                        (string)($existing['created_at'] ?? $now);
                    $survey['updated_at'] = $now;
                    $data['surveys'][$index] = $survey;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $survey['created_at'] = $now;
                $survey['updated_at'] = $now;
                $survey['deleted'] = false;
                $data['surveys'][] = $survey;
            }

            if (!survey_save_data($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true,
                'survey' => $survey
            ]);
            break;

        case 'status':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $status = (string)($_POST['status'] ?? '');

            if (!in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )) {
                survey_json_response([
                    'ok' => false,
                    'message' => '不正なステータスです。'
                ], 400);
            }

            foreach ($data['surveys'] as &$survey) {
                if ((string)$survey['id'] === $surveyId) {
                    $survey['status'] = $status;
                    $survey['updated_at'] = survey_now();
                    break;
                }
            }

            unset($survey);

            survey_save_data($data);
            survey_json_response(['ok' => true]);
            break;

        case 'delete_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {
                if ((string)$survey['id'] === $surveyId) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = survey_now();
                    break;
                }
            }

            unset($survey);

            survey_save_data($data);
            survey_json_response(['ok' => true]);
            break;

        case 'save_settings':
            $settings = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            if (
                ($settings['password'] ?? '') === '' &&
                !empty($data['settings']['password'])
            ) {
                $settings['password'] =
                    $data['settings']['password'];
            }

            $settings = array_merge(
                survey_default_data()['settings'],
                $settings
            );

            $data['settings'] = $settings;

            if (!survey_save_data($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '設定保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true,
                'settings' => $settings
            ]);
            break;

        case 'kintone_fields':
            $appId = trim(
                (string)(
                    $_POST['app_id'] ??
                    $data['settings']['app_id'] ??
                    ''
                )
            );

            if ($appId === '' || !ctype_digit($appId)) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アプリIDは数字で入力してください。'
                ], 400);
            }

            $settings = $data['settings'];
            $settings['app_id'] = $appId;

            $result = survey_kintone_request(
                'GET',
                '/k/v1/app/form/fields.json?app=' .
                    rawurlencode($appId),
                $settings
            );

            if (!$result['ok']) {
                survey_json_response([
                    'ok' => false,
                    'message' => $result['message'],
                    'status' => $result['status'],
                    'error_code' => $result['error_code'],
                    'endpoint' => $result['endpoint']
                ], 400);
            }

            $fields = [];

            foreach (
                ($result['data']['properties'] ?? [])
                as $code => $field
            ) {
                $fields[] = [
                    'label' => (string)(
                        $field['label'] ?? $code
                    ),
                    'code' => (string)$code,
                    'type' => (string)(
                        $field['type'] ?? ''
                    )
                ];
            }

            survey_json_response([
                'ok' => true,
                'fields' => $fields
            ]);
            break;

        case 'register_customer':
            $customerId =
                (string)($_POST['customer_id'] ?? '');

            foreach ($data['customers'] as &$customer) {
                if ((string)$customer['id'] === $customerId) {
                    $customer['kintone_status'] = 'registered';
                    break;
                }
            }

            unset($customer);

            survey_save_data($data);

            survey_json_response(['ok' => true]);
            break;

        case 'send_mail':
            $surveyId =
                (string)($_POST['survey_id'] ?? '');

            $recipientIds =
                json_decode(
                    (string)($_POST['recipient_ids'] ?? '[]'),
                    true
                );

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            $subject =
                trim((string)($_POST['mail_subject'] ?? ''));

            $body =
                (string)($_POST['mail_body'] ?? '');

            $templateType =
                (string)($_POST['template_type'] ?? 'initial');

            if (
                !in_array(
                    $templateType,
                    ['initial', 'reminder'],
                    true
                )
            ) {
                $templateType = 'initial';
            }

            if ($subject === '' || $body === '') {
                survey_json_response([
                    'ok' => false,
                    'message' => '件名と本文を入力してください。'
                ], 400);
            }

            $count = 0;
            $sentMessages = [];

            foreach ($data['customers'] as &$customer) {
                if (
                    !in_array(
                        (string)$customer['id'],
                        $recipientIds,
                        true
                    )
                ) {
                    continue;
                }

                if (
                    ($customer['source'] ?? 'kintone') === 'web'
                ) {
                    continue;
                }

                $email =
                    trim((string)($customer['email'] ?? ''));

                if ($email === '') {
                    continue;
                }

                $url = survey_public_url(
                    $surveyId,
                    (string)$customer['id']
                );

                $finalSubject = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        (string)($customer['name'] ?? ''),
                        $url
                    ],
                    $subject
                );

                $finalBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        (string)($customer['name'] ?? ''),
                        $url
                    ],
                    $body
                );

                /*
                 * PHP mail() を利用。
                 * SMTPが別途設定されている環境ではそのまま送信可能。
                 */
                $headers = [
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                    'From: ' . $email
                ];

                @mail(
                    $email,
                    '=?UTF-8?B?' .
                    base64_encode($finalSubject) .
                    '?=',
                    $finalBody,
                    implode("\r\n", $headers)
                );

                $customer['sent_at'] = survey_now();
                $customer['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;

                if (
                    ($customer['answer_status'] ?? 'unanswered')
                    !== 'answered'
                ) {
                    $customer['answer_status'] = 'unanswered';
                }

                $count++;

                $sentMessages[] = [
                    'customer_id' => $customer['id'],
                    'email' => $email,
                    'subject' => $finalSubject,
                    'body' => $finalBody
                ];
            }

            unset($customer);

            $data['mail_logs'][] = [
                'id' => survey_id('mail'),
                'survey_id' => $surveyId,
                'sent_at' => survey_now(),
                'template_type' => $templateType,
                'count' => $count,
                'subject' => $subject,
                'messages' => $sentMessages
            ];

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'count' => $count
            ]);
            break;

        case 'csv':
            $surveyId =
                (string)($_GET['survey_id'] ?? '');

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if ((string)$item['id'] === $surveyId) {
                    $survey = $item;
                    break;
                }
            }

            if (!$survey) {
                http_response_code(404);
                exit;
            }

            $questions = [];

            foreach ($survey['groups'] ?? [] as $group) {
                foreach ($group['questions'] ?? [] as $question) {
                    $questions[] = $question;
                }
            }

            $fp = fopen('php://temp', 'w+');

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名'
            ];

            foreach ($questions as $question) {
                $header[] =
                    (string)($question['text'] ?? '');
            }

            fputcsv($fp, $header);

            foreach ($data['responses'] as $response) {
                if (
                    (string)($response['survey_id'] ?? '')
                    !== $surveyId
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

                foreach ($questions as $question) {
                    $answer =
                        $response['answers'][$question['id']]
                        ?? '';

                    if (is_array($answer)) {
                        $answer = implode('、', $answer);
                    }

                    $row[] = $answer;
                }

                fputcsv($fp, $row);
            }

            rewind($fp);
            $csv = stream_get_contents($fp);
            fclose($fp);

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="survey_' .
                rawurlencode($surveyId) .
                '.csv"'
            );

            echo "\xEF\xBB\xBF" . $csv;
            exit;

        case 'public_answer':
            $surveyId =
                (string)($_POST['survey_id'] ?? '');

            $customerId =
                (string)($_POST['customer_id'] ?? '');

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if (
                    (string)$item['id'] === $surveyId &&
                    empty($item['deleted'])
                ) {
                    $survey = $item;
                    break;
                }
            }

            if (
                !$survey ||
                ($survey['status'] ?? '') !== 'active'
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'このアンケートは現在回答できません。'
                ], 400);
            }

            $answers = json_decode(
                (string)($_POST['answers'] ?? '{}'),
                true
            );

            if (!is_array($answers)) {
                $answers = [];
            }

            $company = '';
            $name = '';
            $email = '';
            $found = false;

            foreach ($data['customers'] as &$customer) {
                if (
                    $customerId !== '' &&
                    (string)$customer['id'] === $customerId
                ) {
                    $company =
                        (string)($customer['company'] ?? '');

                    $name =
                        (string)($customer['name'] ?? '');

                    $email =
                        (string)($customer['email'] ?? '');

                    $customer['answer_status'] = 'answered';
                    $found = true;
                    break;
                }
            }

            unset($customer);

            if (!$found) {
                $email =
                    trim((string)($_POST['email'] ?? ''));

                $name =
                    trim((string)($_POST['name'] ?? ''));

                $company =
                    trim((string)($_POST['company'] ?? ''));

                $customerId = survey_id('customer');

                $data['customers'][] = [
                    'id' => $customerId,
                    'company' => $company,
                    'name' => $name,
                    'email' => $email,
                    'department' => '',
                    'phone' => '',
                    'address' => '',
                    'source' => 'web',
                    'sent_at' => '',
                    'send_count' => 0,
                    'answer_status' => 'answered',
                    'kintone_status' => 'unregistered'
                ];
            }

            $response = [
                'id' => survey_id('response'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => $company,
                'name' => $name,
                'email' => $email,
                'answered_at' => survey_now(),
                'answers' => $answers
            ];

            $data['responses'][] = $response;

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'response_id' => $response['id']
            ]);
            break;

        default:
            survey_json_response([
                'ok' => false,
                'message' => '不明なactionです。'
            ], 400);
    }
}

/* ================================================================
 * HTML
 * ================================================================ */
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

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<div
    id="preview_modal"
    class="hidden fixed inset-0 z-50 bg-black/50 p-5 overflow-auto"
>
    <div class="max-w-4xl mx-auto bg-white rounded-2xl shadow-xl">
        <div class="p-4 border-b flex justify-between items-center">
            <strong>プレビュー</strong>
            <div class="flex gap-2">
                <button
                    onclick="App.actions.previewSize(false)"
                    class="px-3 py-2 border rounded-lg"
                >PC</button>
                <button
                    onclick="App.actions.previewSize(true)"
                    class="px-3 py-2 border rounded-lg"
                >スマートフォン</button>
                <button
                    onclick="App.actions.closePreview()"
                    class="px-3 py-2 bg-slate-800 text-white rounded-lg"
                >閉じる</button>
            </div>
        </div>
        <div id="preview_content" class="p-6 bg-slate-100"></div>
    </div>
</div>

<div
    id="response_modal"
    class="hidden fixed inset-0 z-50 bg-black/50 p-5 overflow-auto"
>
    <div class="max-w-3xl mx-auto bg-white rounded-2xl shadow-xl">
        <div class="p-4 border-b flex justify-between">
            <strong>回答詳細</strong>
            <button
                onclick="App.actions.closeResponse()"
                class="px-3 py-2 bg-slate-800 text-white rounded-lg"
            >閉じる</button>
        </div>
        <div id="response_detail" class="p-6"></div>
    </div>
</div>

<script>
window.App = {
    State: {
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        csrf_token: '',
        page: 'surveys',
        surveyId: null,
        editSurvey: null,
        dirty: false,
        keyword: '',
        status_filter: 'all',
        sort: 'updated_desc',
        customerFilter: '',
        responseFilter: '',
        selectedCustomers: [],
        selectedQuestions: [],
        kintoneFields: [],
        previewMobile: false,
        initialized: false,
        sortableInstances: []
    },

    Util: {},
    API: {},
    actions: {},
    Render: {}
};

/* ================================================================
 * Util
 * ================================================================ */

App.Util.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
};

App.Util.escapeAttr = function(value) {
    return App.Util.escape(value)
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

App.Util.id = function(prefix) {
    return prefix + '_' +
        Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 10);
};

App.Util.findSurvey = function(id) {
    return App.State.data.surveys.find(
        survey =>
            String(survey.id) === String(id) &&
            !survey.deleted
    ) || null;
};

App.Util.allQuestions = function(survey) {
    const result = [];

    (survey?.groups || []).forEach(group => {
        (group.questions || []).forEach(question => {
            result.push(question);
        });
    });

    return result;
};

App.Util.findQuestion = function(id) {
    return App.Util.allQuestions(
        App.State.editSurvey
    ).find(
        question =>
            String(question.id) === String(id)
    ) || null;
};

App.Util.questionLocation = function(survey, questionId) {
    for (
        let groupIndex = 0;
        groupIndex < (survey.groups || []).length;
        groupIndex++
    ) {
        const group = survey.groups[groupIndex];

        for (
            let questionIndex = 0;
            questionIndex < (group.questions || []).length;
            questionIndex++
        ) {
            if (
                String(group.questions[questionIndex].id) ===
                String(questionId)
            ) {
                return {
                    groupIndex,
                    questionIndex
                };
            }
        }
    }

    return null;
};

App.Util.questionNumber = function(
    survey,
    groupIndex,
    questionIndex
) {
    if (survey.numbering_mode === 'group') {
        return 'Q' +
            (groupIndex + 1) +
            '-' +
            (questionIndex + 1);
    }

    let number = 1;

    for (let i = 0; i < groupIndex; i++) {
        number +=
            (survey.groups[i].questions || []).length;
    }

    return 'Q' + (number + questionIndex);
};

App.Util.statusLabel = function(status) {
    if (status === 'active') return '公開中';
    if (status === 'ended') return '終了';
    return '下書き';
};

App.Util.statusClass = function(status) {
    if (status === 'active') {
        return 'bg-emerald-100 text-emerald-700';
    }

    if (status === 'ended') {
        return 'bg-slate-200 text-slate-600';
    }

    return 'bg-amber-100 text-amber-700';
};

App.Util.syncBranching = function(question) {
    if (question.type !== 'single') {
        question.branching = [];
        return;
    }

    const old = Array.isArray(question.branching)
        ? question.branching
        : [];

    question.branching =
        (question.options || []).map(option => {
            const existing = old.find(
                item =>
                    String(item.option) ===
                    String(option)
            );

            return {
                option: String(option),
                target_question_id:
                    existing
                        ? String(
                            existing.target_question_id || ''
                        )
                        : ''
            };
        });
};

/* ================================================================
 * API
 * ================================================================ */

App.API.request = async function(
    action,
    params = {}
) {
    const form = new URLSearchParams();

    form.append('action', action);
    form.append(
        'csrf_token',
        App.State.csrf_token
    );

    Object.entries(params).forEach(
        ([key, value]) => {
            if (
                Array.isArray(value) ||
                (
                    value !== null &&
                    typeof value === 'object'
                )
            ) {
                form.append(
                    key,
                    JSON.stringify(value)
                );
            } else {
                form.append(
                    key,
                    String(value ?? '')
                );
            }
        }
    );

    const response = await fetch(
        location.pathname,
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: form.toString()
        }
    );

    const json = await response.json();

    if (!response.ok || !json.ok) {
        throw new Error(
            json.message ||
            'サーバー処理に失敗しました。'
        );
    }

    return json;
};

App.API.load = async function() {
    const response = await fetch(
        location.pathname + '?action=load',
        {
            credentials: 'same-origin',
            cache: 'no-store'
        }
    );

    const json = await response.json();

    if (!response.ok || !json.ok) {
        throw new Error(
            json.message ||
            'データ読み込みに失敗しました。'
        );
    }

    App.State.data = json.data;
    App.State.csrf_token = json.csrf_token;
};

/* ================================================================
 * Navigation
 * ================================================================ */

App.actions.goSurveys = function() {
    if (
        App.State.page === 'edit' &&
        App.State.dirty
    ) {
        if (
            !confirm(
                '未保存の変更があります。破棄して一覧へ戻りますか？'
            )
        ) {
            return;
        }
    }

    App.State.page = 'surveys';
    App.State.editSurvey = null;
    App.State.surveyId = null;
    App.State.dirty = false;

    App.Render.main();
};

App.actions.goSettings = function() {
    App.State.page = 'settings';
    App.Render.main();
};

App.actions.newSurvey = function() {
    App.State.editSurvey = {
        id: App.Util.id('survey'),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [
            {
                id: App.Util.id('group'),
                name: 'グループ1',
                questions: []
            }
        ],
        deleted: false
    };

    App.State.surveyId =
        App.State.editSurvey.id;

    App.State.page = 'edit';
    App.State.dirty = false;

    App.Render.main();
};

App.actions.editSurvey = function(id) {
    const survey = App.Util.findSurvey(id);

    if (!survey) return;

    App.State.editSurvey =
        JSON.parse(JSON.stringify(survey));

    App.State.surveyId = id;
    App.State.page = 'edit';
    App.State.dirty = false;

    App.Render.main();
};

App.actions.openResults = function(id) {
    const survey = App.Util.findSurvey(id);

    if (!survey) return;

    App.State.surveyId = id;
    App.State.page = 'results';
    App.State.selectedQuestions =
        App.Util.allQuestions(survey)
            .map(q => String(q.id));

    App.Render.main();
};

App.actions.openMail = function(id) {
    App.State.surveyId = id;
    App.State.page = 'mail';
    App.State.selectedCustomers = [];

    App.Render.main();
};

/* ================================================================
 * 一覧
 * ================================================================ */

App.actions.searchSurveys = function(value) {
    App.State.keyword = value;
    App.Render.surveys();
};

App.actions.toggleStatusFilter = function(value) {
    App.State.status_filter = value;
    App.Render.surveys();
};

App.actions.sortSurveys = function(value) {
    App.State.sort = value;
    App.Render.surveys();
};

App.actions.cloneSurvey = async function(id) {
    const source = App.Util.findSurvey(id);

    if (!source) return;

    const copy =
        JSON.parse(JSON.stringify(source));

    copy.id = App.Util.id('survey');
    copy.title += '（複製）';
    copy.status = 'draft';
    copy.created_at = '';
    copy.updated_at = '';
    copy.deleted = false;

    copy.groups.forEach(group => {
        group.id = App.Util.id('group');

        group.questions.forEach(question => {
            question.id =
                App.Util.id('question');

            if (Array.isArray(question.branching)) {
                question.branching =
                    question.branching.map(item => ({
                        option: item.option,
                        target_question_id: ''
                    }));
            }
        });
    });

    try {
        await App.API.request(
            'save_survey',
            { survey_json: copy }
        );

        await App.API.load();

        App.Render.surveys();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.deleteSurvey = async function(id) {
    if (
        !confirm(
            'この下書きを削除しますか？'
        )
    ) {
        return;
    }

    try {
        await App.API.request(
            'delete_survey',
            { survey_id: id }
        );

        await App.API.load();
        App.Render.surveys();

    } catch (error) {
        alert(error.message);
    }
};

/* ================================================================
 * Editor
 * ================================================================ */

App.actions.updateSurveyField =
function(key, value) {
    if (!App.State.editSurvey) return;

    App.State.editSurvey[key] = value;
    App.State.dirty = true;
};

App.actions.updateNumbering =
function(value) {
    if (!App.State.editSurvey) return;

    App.State.editSurvey.numbering_mode =
        value;

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();
};

App.actions.addGroup = function() {
    const survey = App.State.editSurvey;

    if (!survey) return;

    survey.groups.push({
        id: App.Util.id('group'),
        name:
            'グループ' +
            (survey.groups.length + 1),
        questions: []
    });

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();

    /*
     * グループ追加ボタンは常に画面末尾に
     * Render.editor() が生成する。
     */
};

App.actions.renameGroup =
function(groupId, value) {
    const survey = App.State.editSurvey;

    if (!survey) return;

    const group = survey.groups.find(
        item =>
            String(item.id) ===
            String(groupId)
    );

    if (!group) return;

    group.name = value;
    App.State.dirty = true;
};

App.actions.deleteGroup =
function(groupId) {
    const survey = App.State.editSurvey;

    if (!survey) return;

    if (survey.groups.length <= 1) {
        alert(
            '最低1グループは必要です。'
        );
        return;
    }

    const group = survey.groups.find(
        item =>
            String(item.id) ===
            String(groupId)
    );

    if (!group) return;

    if (
        !confirm(
            '「' +
            group.name +
            '」と、その中の質問をすべて削除しますか？'
        )
    ) {
        return;
    }

    const deletedIds =
        new Set(
            (group.questions || [])
                .map(q => String(q.id))
        );

    survey.groups =
        survey.groups.filter(
            item =>
                String(item.id) !==
                String(groupId)
        );

    App.actions.removeBrokenBranching(
        deletedIds
    );

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();
};

App.actions.addQuestion =
function(groupId) {
    const survey = App.State.editSurvey;

    if (!survey) return;

    const group = survey.groups.find(
        item =>
            String(item.id) ===
            String(groupId)
    );

    if (!group) return;

    const question = {
        id: App.Util.id('question'),
        text: '新しい質問',
        type: 'single',
        required: false,
        options: [
            '選択肢1',
            '選択肢2'
        ],
        other_enabled: false,
        branching: [
            {
                option: '選択肢1',
                target_question_id: ''
            },
            {
                option: '選択肢2',
                target_question_id: ''
            }
        ]
    };

    group.questions.push(question);

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();
};

App.actions.updateQuestion =
function(questionId, key, value) {
    const question =
        App.Util.findQuestion(questionId);

    if (!question) return;

    if (
        key === 'required' ||
        key === 'other_enabled'
    ) {
        question[key] = Boolean(value);
    } else {
        question[key] = value;
    }

    if (key === 'type') {
        if (value === 'single') {
            App.Util.syncBranching(question);
        } else {
            question.branching = [];
        }

        App.State.dirty = true;

        App.Render.editor();
        App.actions.initSortables();
        return;
    }

    App.State.dirty = true;
};

App.actions.updateOption =
function(questionId, index, value) {
    const question =
        App.Util.findQuestion(questionId);

    if (!question) return;

    question.options[index] = value;

    App.Util.syncBranching(question);

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();
};

App.actions.addOption =
function(questionId) {
    const question =
        App.Util.findQuestion(questionId);

    if (!question) return;

    question.options.push(
        '選択肢' +
        (question.options.length + 1)
    );

    App.Util.syncBranching(question);

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();
};

App.actions.removeOption =
function(questionId, index) {
    const question =
        App.Util.findQuestion(questionId);

    if (!question) return;

    question.options.splice(index, 1);

    App.Util.syncBranching(question);

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();
};

App.actions.deleteQuestion =
function(questionId) {
    const survey = App.State.editSurvey;

    if (!survey) return;

    if (
        !confirm(
            'この質問を削除しますか？'
        )
    ) {
        return;
    }

    survey.groups.forEach(group => {
        group.questions =
            group.questions.filter(
                question =>
                    String(question.id) !==
                    String(questionId)
            );
    });

    App.actions.removeBrokenBranching(
        new Set([String(questionId)])
    );

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();
};

App.actions.updateBranching =
function(questionId, optionIndex, targetId) {
    const question =
        App.Util.findQuestion(questionId);

    if (!question) return;

    App.Util.syncBranching(question);

    if (question.branching[optionIndex]) {
        question.branching[
            optionIndex
        ].target_question_id = targetId;
    }

    App.State.dirty = true;
};

App.actions.removeBrokenBranching =
function(deletedIds) {
    const survey = App.State.editSurvey;

    if (!survey) return;

    survey.groups.forEach(group => {
        group.questions.forEach(question => {
            (question.branching || [])
                .forEach(branch => {
                    if (
                        branch.target_question_id &&
                        deletedIds.has(
                            String(
                                branch.target_question_id
                            )
                        )
                    ) {
                        branch.target_question_id =
                            '';
                    }
                });
        });
    });
};

/* ================================================================
 * SortableJS
 * ================================================================ */

App.actions.destroySortables =
function() {
    App.State.sortableInstances.forEach(
        instance => {
            try {
                instance.destroy();
            } catch (error) {}
        }
    );

    App.State.sortableInstances = [];
};

App.actions.initSortables =
function() {
    if (typeof Sortable === 'undefined') {
        return;
    }

    const editor =
        document.getElementById(
            'question_editor'
        );

    if (!editor) return;

    App.actions.destroySortables();

    const groupList =
        editor.querySelector(
            '[data-group-list]'
        );

    if (groupList) {
        App.State.sortableInstances.push(
            new Sortable(
                groupList,
                {
                    animation: 180,
                    handle: '.group-handle',
                    ghostClass: 'opacity-40',

                    onEnd: function() {
                        const ids =
                            Array.from(
                                groupList.children
                            )
                            .map(
                                element =>
                                    element.dataset.groupId
                            )
                            .filter(Boolean);

                        const survey =
                            App.State.editSurvey;

                        if (!survey) return;

                        survey.groups.sort(
                            (a, b) =>
                                ids.indexOf(
                                    String(a.id)
                                ) -
                                ids.indexOf(
                                    String(b.id)
                                )
                        );

                        App.State.dirty = true;

                        App.Render.editor();
                        App.actions.initSortables();
                    }
                }
            )
        );
    }

    editor.querySelectorAll(
        '[data-question-list]'
    ).forEach(list => {
        App.State.sortableInstances.push(
            new Sortable(
                list,
                {
                    group: {
                        name: 'survey_questions',
                        pull: true,
                        put: true
                    },

                    animation: 180,
                    handle: '.question-handle',
                    ghostClass: 'opacity-40',

                    onEnd: function() {
                        const survey =
                            App.State.editSurvey;

                        if (!survey) return;

                        const allQuestions =
                            App.Util.allQuestions(
                                survey
                            );

                        const map = new Map();

                        allQuestions.forEach(
                            question => {
                                map.set(
                                    String(
                                        question.id
                                    ),
                                    question
                                );
                            }
                        );

                        const newGroups = [];

                        editor.querySelectorAll(
                            '[data-group-id]'
                        ).forEach(
                            groupElement => {
                                const group =
                                    survey.groups.find(
                                        item =>
                                            String(
                                                item.id
                                            ) ===
                                            String(
                                                groupElement.dataset.groupId
                                            )
                                    );

                                if (!group) return;

                                const questionList =
                                    groupElement.querySelector(
                                        '[data-question-list]'
                                    );

                                const ids =
                                    Array.from(
                                        questionList?.children || []
                                    )
                                    .map(
                                        element =>
                                            element.dataset.questionId
                                    )
                                    .filter(Boolean);

                                group.questions =
                                    ids.map(
                                        id =>
                                            map.get(
                                                String(id)
                                            )
                                    )
                                    .filter(Boolean);

                                newGroups.push(group);
                            }
                        );

                        survey.groups = newGroups;

                        App.State.dirty = true;

                        App.Render.editor();
                        App.actions.initSortables();
                    }
                }
            )
        );
    });
};

/* ================================================================
 * 保存・ステータス
 * ================================================================ */

App.actions.saveSurvey =
async function() {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    if (
        !String(survey.title || '').trim()
    ) {
        alert(
            'タイトルを入力してください。'
        );
        return;
    }

    try {
        await App.API.request(
            'save_survey',
            {
                survey_json: survey
            }
        );

        await App.API.load();

        App.State.dirty = false;
        App.State.page = 'surveys';
        App.State.editSurvey = null;

        alert(
            'アンケートを保存しました。'
        );

        App.Render.main();

    } catch (error) {
        alert(error.message);
    }
};

/*
 * ステータス変更は編集画面だけ。
 * 一覧には停止/再開ボタンを置かない。
 */
App.actions.changeStatusFromEditor =
async function(status) {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    const label =
        status === 'active'
            ? '公開'
            : status === 'ended'
                ? '終了'
                : '下書き';

    if (
        !confirm(
            'ステータスを「' +
            label +
            '」に変更しますか？'
        )
    ) {
        return;
    }

    try {
        await App.API.request(
            'status',
            {
                survey_id: survey.id,
                status: status
            }
        );

        survey.status = status;
        App.State.dirty = false;

        await App.API.load();

        App.Render.editor();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.cancelEdit =
function() {
    if (
        App.State.dirty &&
        !confirm(
            '未保存の変更を破棄しますか？'
        )
    ) {
        return;
    }

    App.State.page = 'surveys';
    App.State.editSurvey = null;
    App.State.dirty = false;

    App.Render.main();
};

/* ================================================================
 * Preview
 * ================================================================ */

App.actions.openPreview =
function() {
    if (!App.State.editSurvey) return;

    App.actions.renderPreview();

    document
        .getElementById('preview_modal')
        ?.classList.remove('hidden');
};

App.actions.closePreview =
function() {
    document
        .getElementById('preview_modal')
        ?.classList.add('hidden');
};

App.actions.previewSize =
function(mobile) {
    App.State.previewMobile = mobile;
    App.actions.renderPreview();
};

App.actions.renderPreview =
function() {
    const survey =
        App.State.editSurvey;

    const target =
        document.getElementById(
            'preview_content'
        );

    if (!survey || !target) return;

    const questions =
        App.Util.allQuestions(survey);

    target.innerHTML = `
        <div class="${
            App.State.previewMobile
                ? 'max-w-sm'
                : 'max-w-3xl'
        } mx-auto bg-white rounded-2xl p-6 shadow">
            <h1 class="text-2xl font-bold mb-6">
                ${App.Util.escape(survey.title)}
            </h1>

            ${
                questions.map(
                    (question, index) => {
                        const location =
                            App.Util.questionLocation(
                                survey,
                                question.id
                            );

                        const number =
                            App.Util.questionNumber(
                                survey,
                                location.groupIndex,
                                location.questionIndex
                            );

                        let control = '';

                        if (
                            question.type === 'text'
                        ) {
                            control = `
                                <textarea
                                    class="w-full border rounded-xl p-3"
                                    rows="4"
                                    placeholder="回答を入力"
                                ></textarea>
                            `;
                        } else {
                            control =
                                (question.options || [])
                                .map(option => `
                                    <label class="flex gap-2 items-center">
                                        <input
                                            type="${
                                                question.type === 'single'
                                                    ? 'radio'
                                                    : 'checkbox'
                                            }"
                                            name="preview_${question.id}"
                                        >
                                        <span>
                                            ${App.Util.escape(option)}
                                        </span>
                                    </label>
                                `)
                                .join('');
                        }

                        return `
                            <div class="mb-7">
                                <div class="font-semibold mb-3">
                                    ${number}.
                                    ${App.Util.escape(question.text)}
                                    ${
                                        question.required
                                            ? '<span class="text-rose-500 ml-1">必須</span>'
                                            : ''
                                    }
                                </div>
                                <div class="space-y-2">
                                    ${control}
                                </div>
                            </div>
                        `;
                    }
                ).join('')
            }

            <button
                onclick="App.actions.previewSubmit()"
                class="w-full px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold"
            >
                回答を送信
            </button>
        </div>
    `;
};

App.actions.previewSubmit =
function() {
    alert(
        'これはプレビューです。実際には送信されません。'
    );
};

/* ================================================================
 * Results
 * ================================================================ */

App.actions.toggleQuestion =
function(questionId, checked) {
    const id = String(questionId);

    if (checked) {
        if (
            !App.State.selectedQuestions.includes(id)
        ) {
            App.State.selectedQuestions.push(id);
        }
    } else {
        App.State.selectedQuestions =
            App.State.selectedQuestions.filter(
                item => item !== id
            );
    }

    App.Render.results();
};

App.actions.selectAllQuestions =
function(checked) {
    const survey =
        App.Util.findSurvey(
            App.State.surveyId
        );

    if (!survey) return;

    App.State.selectedQuestions =
        checked
            ? App.Util.allQuestions(survey)
                .map(q => String(q.id))
            : [];

    App.Render.results();
};

App.actions.responseSearch =
function(value) {
    App.State.responseFilter = value;
    App.Render.results();
};

App.actions.showResponse =
function(responseId) {
    const response =
        App.State.data.responses.find(
            item =>
                String(item.id) ===
                String(responseId)
        );

    if (!response) return;

    const survey =
        App.Util.findSurvey(
            response.survey_id
        );

    if (!survey) return;

    const detail =
        document.getElementById(
            'response_detail'
        );

    if (!detail) return;

    const rows =
        App.Util.allQuestions(survey)
            .map(question => {
                let answer =
                    response.answers?.[
                        question.id
                    ] ?? '';

                if (Array.isArray(answer)) {
                    answer =
                        answer.join('、');
                }

                return `
                    <div class="border-b py-4">
                        <div class="text-xs text-slate-500">
                            ${App.Util.escape(question.text)}
                        </div>
                        <div class="mt-1 whitespace-pre-wrap font-medium">
                            ${App.Util.escape(answer)}
                        </div>
                    </div>
                `;
            })
            .join('');

    detail.innerHTML = `
        <div class="mb-5">
            <div class="font-bold">
                ${App.Util.escape(response.company)}
            </div>
            <div>
                ${App.Util.escape(response.name)}
            </div>
            <div class="text-sm text-slate-500">
                ${App.Util.escape(response.email)}
            </div>
            <div class="text-xs text-slate-400 mt-1">
                ${App.Util.escape(response.answered_at)}
            </div>
        </div>

        ${rows}
    `;

    document
        .getElementById('response_modal')
        ?.classList.remove('hidden');
};

App.actions.closeResponse =
function() {
    document
        .getElementById('response_modal')
        ?.classList.add('hidden');
};

/* ================================================================
 * Mail
 * ================================================================ */

App.actions.toggleCustomer =
function(id, checked) {
    id = String(id);

    if (checked) {
        if (
            !App.State.selectedCustomers.includes(id)
        ) {
            App.State.selectedCustomers.push(id);
        }
    } else {
        App.State.selectedCustomers =
            App.State.selectedCustomers.filter(
                item => item !== id
            );
    }
};

App.actions.selectAllCustomers =
function(checked) {
    const surveyId =
        App.State.surveyId;

    const customers =
        App.State.data.customers.filter(
            customer =>
                customer.source !== 'web' &&
                customer.email &&
                (
                    !App.State.customerFilter ||
                    (
                        String(customer.name)
                            .includes(
                                App.State.customerFilter
                            ) ||
                        String(customer.email)
                            .includes(
                                App.State.customerFilter
                            ) ||
                        String(customer.company)
                            .includes(
                                App.State.customerFilter
                            )
                    )
                )
        );

    App.State.selectedCustomers =
        checked
            ? customers.map(c => String(c.id))
            : [];

    App.Render.mail();
};

App.actions.searchCustomers =
function(value) {
    App.State.customerFilter = value;
    App.Render.mail();
};

App.actions.sendMail =
async function() {
    const ids =
        App.State.selectedCustomers;

    if (!ids.length) {
        alert(
            '送信先を選択してください。'
        );
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

    const templateType =
        document.getElementById(
            'template_type'
        )?.value || 'initial';

    const alreadySent =
        App.State.data.customers.filter(
            customer =>
                ids.includes(
                    String(customer.id)
                ) &&
                Number(customer.send_count || 0) > 0
        );

    if (
        alreadySent.length &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )
    ) {
        return;
    }

    try {
        const result =
            await App.API.request(
                'send_mail',
                {
                    survey_id:
                        App.State.surveyId,
                    recipient_ids: ids,
                    mail_subject: subject,
                    mail_body: body,
                    template_type:
                        templateType
                }
            );

        await App.API.load();

        alert(
            result.count +
            '件の送信処理を実行しました。'
        );

        App.Render.mail();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.registerCustomer =
async function(id) {
    try {
        await App.API.request(
            'register_customer',
            {
                customer_id: id
            }
        );

        await App.API.load();
        App.Render.mail();

    } catch (error) {
        alert(error.message);
    }
};

/* ================================================================
 * kintone
 * ================================================================ */

App.actions.readSettings =
function() {
    const current =
        App.State.data.settings || {};

    const address =
        Array.from(
            document.querySelectorAll(
                '#field_address option:checked'
            )
        ).map(option => option.value);

    return {
        subdomain:
            document.getElementById(
                'setting_subdomain'
            )?.value ||
            current.subdomain ||
            '',

        login_name:
            document.getElementById(
                'setting_login_name'
            )?.value ||
            current.login_name ||
            '',

        password:
            document.getElementById(
                'setting_password'
            )?.value || '',

        app_id:
            document.getElementById(
                'setting_app_id'
            )?.value ||
            current.app_id ||
            '',

        ssl_verify:
            document.getElementById(
                'setting_ssl_verify'
            )?.checked || false,

        proxy:
            document.getElementById(
                'setting_proxy'
            )?.value ||
            current.proxy ||
            '',

        field_company:
            document.getElementById(
                'field_company'
            )?.value || '',

        field_name:
            document.getElementById(
                'field_name'
            )?.value || '',

        field_email:
            document.getElementById(
                'field_email'
            )?.value || '',

        field_department:
            document.getElementById(
                'field_department'
            )?.value || '',

        field_phone:
            document.getElementById(
                'field_phone'
            )?.value || '',

        field_address:
            address.length
                ? address
                : (
                    Array.isArray(
                        current.field_address
                    )
                        ? current.field_address
                        : []
                )
    };
};

App.actions.fetchKintoneFields =
async function() {
    const appId =
        document.getElementById(
            'setting_app_id'
        )?.value.trim() || '';

    if (!appId) {
        alert(
            'アプリIDを入力してください。'
        );
        return;
    }

    const message =
        document.getElementById(
            'field_message'
        );

    if (message) {
        message.textContent =
            'kintoneから項目一覧を取得中...';
    }

    try {
        const settings =
            App.actions.readSettings();

        settings.app_id = appId;

        await App.API.request(
            'save_settings',
            {
                settings_json: settings
            }
        );

        const result =
            await App.API.request(
                'kintone_fields',
                {
                    app_id: appId
                }
            );

        App.State.kintoneFields =
            result.fields || [];

        if (message) {
            message.textContent =
                App.State.kintoneFields.length +
                '項目を取得しました。';
        }

        App.Render.settings();

    } catch (error) {
        if (message) {
            message.textContent =
                error.message;
        }

        alert(error.message);
    }
};

App.actions.saveSettings =
async function() {
    const settings =
        App.actions.readSettings();

    try {
        await App.API.request(
            'save_settings',
            {
                settings_json: settings
            }
        );

        await App.API.load();

        alert(
            'kintone連携設定を保存しました。'
        );

        App.Render.settings();

    } catch (error) {
        alert(error.message);
    }
};

/* ================================================================
 * Render Header
 * ================================================================ */

App.Render.header =
function() {
    return `
        <header class="sticky top-0 z-30 bg-white border-b">
            <div class="max-w-[1500px] mx-auto px-5 h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-bold">
                        A
                    </div>
                    <div>
                        <div class="font-bold">
                            アンケート管理
                        </div>
                        <div class="text-[11px] text-slate-400">
                            Survey Management System
                        </div>
                    </div>
                </div>

                <nav class="flex gap-1">
                    <button
                        onclick="App.actions.goSurveys()"
                        class="px-4 py-2 rounded-lg hover:bg-slate-100"
                    >
                        アンケート一覧
                    </button>

                    <button
                        onclick="App.actions.goSettings()"
                        class="px-4 py-2 rounded-lg hover:bg-slate-100"
                    >
                        kintone連携設定
                    </button>
                </nav>
            </div>
        </header>
    `;
};

/* ================================================================
 * Render Surveys
 * ================================================================ */

App.Render.surveys =
function() {
    const keyword =
        App.State.keyword
            .trim()
            .toLowerCase();

    let surveys =
        App.State.data.surveys
            .filter(
                survey => !survey.deleted
            );

    if (keyword) {
        surveys =
            surveys.filter(
                survey =>
                    String(
                        survey.title || ''
                    )
                    .toLowerCase()
                    .includes(keyword)
            );
    }

    if (
        App.State.status_filter !== 'all'
    ) {
        surveys =
            surveys.filter(
                survey =>
                    survey.status ===
                    App.State.status_filter
            );
    }

    const responseCount =
        survey =>
            App.State.data.responses.filter(
                response =>
                    String(response.survey_id) ===
                    String(survey.id)
            ).length;

    surveys.sort((a, b) => {
        if (
            App.State.sort ===
            'responses_desc'
        ) {
            return responseCount(b) -
                responseCount(a);
        }

        if (
            App.State.sort ===
            'responses_asc'
        ) {
            return responseCount(a) -
                responseCount(b);
        }

        if (
            App.State.sort ===
            'updated_asc'
        ) {
            return String(a.updated_at)
                .localeCompare(
                    String(b.updated_at)
                );
        }

        return String(b.updated_at)
            .localeCompare(
                String(a.updated_at)
            );
    });

    const rows =
        surveys.map(survey => {
            let actions = `
                <button
                    onclick="App.actions.editSurvey('${App.Util.escapeAttr(survey.id)}')"
                    class="px-3 py-1.5 rounded-lg bg-white border text-sm"
                >
                    確認・編集
                </button>
            `;

            if (
                survey.status === 'active' ||
                survey.status === 'ended'
            ) {
                actions += `
                    <button
                        onclick="App.actions.openResults('${App.Util.escapeAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg bg-white border text-sm"
                    >
                        集計
                    </button>
                `;
            }

            if (survey.status === 'active') {
                actions += `
                    <button
                        onclick="App.actions.openMail('${App.Util.escapeAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg bg-white border text-sm"
                    >
                        送信
                    </button>
                `;
            }

            if (survey.status === 'draft') {
                actions += `
                    <button
                        onclick="App.actions.deleteSurvey('${App.Util.escapeAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-sm"
                    >
                        削除
                    </button>
                `;
            }

            actions += `
                <button
                    onclick="App.actions.cloneSurvey('${App.Util.escapeAttr(survey.id)}')"
                    class="px-3 py-1.5 rounded-lg bg-white border text-sm"
                >
                    複製
                </button>
            `;

            return `
                <tr class="border-t">
                    <td class="px-4 py-4 text-sm">
                        ${App.Util.escape(
                            survey.created_at || ''
                        )}
                        <div class="text-xs text-slate-400">
                            更新:
                            ${App.Util.escape(
                                survey.updated_at || ''
                            )}
                        </div>
                    </td>

                    <td class="px-4 py-4 font-bold">
                        ${App.Util.escape(
                            survey.title
                        )}
                    </td>

                    <td class="px-4 py-4 text-sm">
                        ${App.Util.escape(
                            survey.start_at ||
                            '未設定'
                        )}
                        ～
                        ${App.Util.escape(
                            survey.end_at ||
                            '未設定'
                        )}
                    </td>

                    <td class="px-4 py-4">
                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold ${App.Util.statusClass(survey.status)}">
                            ${App.Util.statusLabel(survey.status)}
                        </span>
                    </td>

                    <td class="px-4 py-4">
                        ${responseCount(survey)} 件
                    </td>

                    <td class="px-4 py-4">
                        <div class="flex flex-wrap gap-2">
                            ${actions}
                        </div>
                    </td>
                </tr>
            `;
        })
        .join('');

    return `
        <section>
            <div class="flex items-center justify-between mb-6">
                <div>
                    <div class="text-sm text-indigo-600 font-semibold">
                        SURVEYS
                    </div>
                    <h1 class="text-2xl font-bold">
                        アンケート一覧
                    </h1>
                </div>

                <button
                    onclick="App.actions.newSurvey()"
                    class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold"
                >
                    ＋ 新規アンケート作成
                </button>
            </div>

            <div class="bg-white border rounded-2xl p-4 mb-5">
                <div class="flex flex-wrap gap-3">
                    <input
                        value="${App.Util.escapeAttr(App.State.keyword)}"
                        oninput="App.actions.searchSurveys(this.value)"
                        placeholder="タイトルを検索"
                        class="border rounded-xl px-4 py-2.5 w-72"
                    >

                    <select
                        onchange="App.actions.toggleStatusFilter(this.value)"
                        class="border rounded-xl px-4 py-2.5"
                    >
                        <option value="all">すべて</option>
                        <option value="active" ${App.State.status_filter === 'active' ? 'selected' : ''}>公開中</option>
                        <option value="draft" ${App.State.status_filter === 'draft' ? 'selected' : ''}>下書き</option>
                        <option value="ended" ${App.State.status_filter === 'ended' ? 'selected' : ''}>終了</option>
                    </select>

                    <select
                        onchange="App.actions.sortSurveys(this.value)"
                        class="border rounded-xl px-4 py-2.5"
                    >
                        <option value="updated_desc">更新日：新しい順</option>
                        <option value="updated_asc">更新日：古い順</option>
                        <option value="responses_desc">回答数：多い順</option>
                        <option value="responses_asc">回答数：少ない順</option>
                    </select>
                </div>
            </div>

            <div class="bg-white border rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1100px] text-left">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3">作成日 / 更新日</th>
                                <th class="px-4 py-3">タイトル</th>
                                <th class="px-4 py-3">アンケート期間</th>
                                <th class="px-4 py-3">ステータス</th>
                                <th class="px-4 py-3">回答数</th>
                                <th class="px-4 py-3">操作</th>
                            </tr>
                        </thead>

                        <tbody id="survey_list">
                            ${
                                rows ||
                                `
                                    <tr>
                                        <td colspan="6"
                                            class="px-5 py-12 text-center text-slate-400">
                                            アンケートがありません。
                                        </td>
                                    </tr>
                                `
                            }
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    `;
};

/* ================================================================
 * Render Editor
 * ================================================================ */

App.Render.editor =
function() {
    const survey =
        App.State.editSurvey;

    if (!survey) return '';

    let groupsHtml = '';

    survey.groups.forEach(
        (group, groupIndex) => {
            let questionsHtml = '';

            (group.questions || [])
                .forEach(
                    (question, questionIndex) => {
                        const number =
                            App.Util.questionNumber(
                                survey,
                                groupIndex,
                                questionIndex
                            );

                        App.Util.syncBranching(
                            question
                        );

                        let optionsHtml = '';

                        if (
                            question.type === 'single' ||
                            question.type === 'multiple'
                        ) {
                            optionsHtml = `
                                <div class="mt-4">
                                    <div class="text-xs font-bold text-slate-500 mb-2">
                                        選択肢
                                    </div>

                                    <div class="space-y-2">
                                        ${
                                            question.options
                                                .map(
                                                    (option, index) => `
                                                        <div class="flex gap-2">
                                                            <input
                                                                value="${App.Util.escapeAttr(option)}"
                                                                oninput="App.actions.updateOption('${App.Util.escapeAttr(question.id)}',${index},this.value)"
                                                                class="flex-1 border rounded-lg px-3 py-2"
                                                            >

                                                            <button
                                                                onclick="App.actions.removeOption('${App.Util.escapeAttr(question.id)}',${index})"
                                                                class="px-3 rounded-lg bg-slate-100"
                                                            >
                                                                ×
                                                            </button>
                                                        </div>
                                                    `
                                                )
                                                .join('')
                                        }
                                    </div>

                                    <button
                                        onclick="App.actions.addOption('${App.Util.escapeAttr(question.id)}')"
                                        class="mt-2 text-sm text-indigo-600 font-semibold"
                                    >
                                        ＋ 選択肢を追加
                                    </button>
                                </div>
                            `;
                        }

                        let branchingHtml = '';

                        if (
                            question.type === 'single'
                        ) {
                            const allQuestions =
                                App.Util.allQuestions(
                                    survey
                                );

                            branchingHtml = `
                                <div
                                    id="branching_editor"
                                    class="mt-5 border border-indigo-100 bg-indigo-50 rounded-xl p-4"
                                >
                                    <div class="font-bold text-sm text-indigo-800 mb-2">
                                        質問の分岐
                                    </div>

                                    <div class="text-xs text-indigo-700 mb-4">
                                        選択肢ごとに次に表示する質問を指定できます。
                                    </div>

                                    <div class="space-y-3">
                                        ${
                                            question.branching
                                                .map(
                                                    (branch, branchIndex) => {
                                                        return `
                                                            <div class="grid md:grid-cols-2 gap-3 items-center">
                                                                <div class="text-sm font-medium">
                                                                    ${App.Util.escape(branch.option)}
                                                                </div>

                                                                <select
                                                                    onchange="App.actions.updateBranching('${App.Util.escapeAttr(question.id)}',${branchIndex},this.value)"
                                                                    class="border rounded-lg px-3 py-2 bg-white"
                                                                >
                                                                    <option value="">
                                                                        指定なし
                                                                    </option>

                                                                    ${
                                                                        allQuestions
                                                                            .filter(
                                                                                target =>
                                                                                    String(target.id) !==
                                                                                    String(question.id)
                                                                            )
                                                                            .map(
                                                                                target => {
                                                                                    const location =
                                                                                        App.Util.questionLocation(
                                                                                            survey,
                                                                                            target.id
                                                                                        );

                                                                                    const targetNumber =
                                                                                        location
                                                                                            ? App.Util.questionNumber(
                                                                                                survey,
                                                                                                location.groupIndex,
                                                                                                location.questionIndex
                                                                                            )
                                                                                            : '';

                                                                                    return `
                                                                                        <option
                                                                                            value="${App.Util.escapeAttr(target.id)}"
                                                                                            ${
                                                                                                String(
                                                                                                    branch.target_question_id || ''
                                                                                                ) ===
                                                                                                String(target.id)
                                                                                                    ? 'selected'
                                                                                                    : ''
                                                                                            }
                                                                                        >
                                                                                            ${App.Util.escape(targetNumber)}
                                                                                            ：
                                                                                            ${App.Util.escape(target.text)}
                                                                                        </option>
                                                                                    `;
                                                                                }
                                                                            )
                                                                            .join('')
                                                                    }
                                                                </select>
                                                            </div>
                                                        `;
                                                    }
                                                )
                                                .join('')
                                        }
                                    </div>
                                </div>
                            `;
                        }

                        questionsHtml += `
                            <article
                                data-question-id="${App.Util.escapeAttr(question.id)}"
                                class="bg-white border rounded-xl p-4 shadow-sm"
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        class="question-handle cursor-grab text-slate-400 text-xl"
                                        title="ドラッグして移動"
                                    >
                                        ⠿
                                    </div>

                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-3">
                                            <span class="px-2 py-1 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-bold">
                                                ${number}
                                            </span>

                                            <input
                                                value="${App.Util.escapeAttr(question.text)}"
                                                oninput="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','text',this.value)"
                                                class="flex-1 border rounded-lg px-3 py-2 font-medium"
                                            >

                                            <button
                                                onclick="App.actions.deleteQuestion('${App.Util.escapeAttr(question.id)}')"
                                                class="px-3 py-2 rounded-lg bg-rose-50 text-rose-600"
                                            >
                                                削除
                                            </button>
                                        </div>

                                        <div class="grid md:grid-cols-2 gap-3">
                                            <select
                                                onchange="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','type',this.value)"
                                                class="border rounded-lg px-3 py-2"
                                            >
                                                <option value="single" ${question.type === 'single' ? 'selected' : ''}>
                                                    単一選択
                                                </option>
                                                <option value="multiple" ${question.type === 'multiple' ? 'selected' : ''}>
                                                    複数選択
                                                </option>
                                                <option value="text" ${question.type === 'text' ? 'selected' : ''}>
                                                    自由記述
                                                </option>
                                            </select>

                                            <label class="flex items-center gap-2 border rounded-lg px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    ${question.required ? 'checked' : ''}
                                                    onchange="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','required',this.checked)"
                                                >
                                                必須回答
                                            </label>
                                        </div>

                                        ${optionsHtml}

                                        ${
                                            (
                                                question.type === 'single' ||
                                                question.type === 'multiple'
                                            )
                                                ? `
                                                    <label class="flex items-center gap-2 mt-4 text-sm">
                                                        <input
                                                            type="checkbox"
                                                            ${question.other_enabled ? 'checked' : ''}
                                                            onchange="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','other_enabled',this.checked)"
                                                        >
                                                        「その他」を許可
                                                    </label>
                                                `
                                                : ''
                                        }

                                        ${branchingHtml}
                                    </div>
                                </div>
                            </article>
                        `;
                    }
                )
                .join('');

            groupsHtml += `
                <section
                    data-group-id="${App.Util.escapeAttr(group.id)}"
                    class="bg-slate-50 border rounded-2xl p-4"
                >
                    <div class="flex items-center gap-3 mb-4">
                        <div class="group-handle cursor-grab text-xl text-slate-400">
                            ⠿
                        </div>

                        <input
                            value="${App.Util.escapeAttr(group.name)}"
                            oninput="App.actions.renameGroup('${App.Util.escapeAttr(group.id)}',this.value)"
                            class="flex-1 border rounded-lg px-3 py-2 font-bold bg-white"
                        >

                        <button
                            onclick="App.actions.deleteGroup('${App.Util.escapeAttr(group.id)}')"
                            class="px-3 py-2 rounded-lg bg-rose-50 text-rose-600"
                        >
                            グループ削除
                        </button>
                    </div>

                    <div
                        data-question-list
                        class="space-y-3 min-h-[70px]"
                    >
                        ${questionsHtml}

                        <div class="border-2 border-dashed border-slate-200 rounded-xl p-5 text-center text-slate-400 text-sm">
                            質問をここへドラッグ
                        </div>
                    </div>

                    <button
                        onclick="App.actions.addQuestion('${App.Util.escapeAttr(group.id)}')"
                        class="mt-4 px-4 py-2 rounded-lg bg-indigo-50 text-indigo-700 font-semibold"
                    >
                        ＋ 質問を追加
                    </button>
                </section>
            `;
        }
    );

    /*
     * 重要:
     * グループ追加ボタンは必ず全グループの末尾。
     */
    groupsHtml += `
        <div class="flex justify-center py-4">
            <button
                onclick="App.actions.addGroup()"
                class="px-6 py-3 rounded-xl border-2 border-dashed border-indigo-300 bg-white text-indigo-700 font-semibold hover:bg-indigo-50"
            >
                ＋ グループを追加
            </button>
        </div>
    `;

    return `
        <section id="question_editor">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <div class="text-sm text-indigo-600 font-semibold">
                        EDITOR
                    </div>
                    <h1 class="text-2xl font-bold">
                        アンケート編集
                    </h1>
                </div>

                <div class="flex gap-2">
                    <button
                        onclick="App.actions.openPreview()"
                        class="px-4 py-2 border rounded-lg"
                    >
                        プレビュー
                    </button>

                    <button
                        onclick="App.actions.cancelEdit()"
                        class="px-4 py-2 border rounded-lg"
                    >
                        キャンセル
                    </button>

                    <button
                        onclick="App.actions.saveSurvey()"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg"
                    >
                        保存して一覧へ戻る
                    </button>
                </div>
            </div>

            <div class="bg-white border rounded-2xl p-5 mb-5">
                <div class="grid md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold mb-2">
                            アンケートタイトル
                        </label>

                        <input
                            id="survey_title"
                            value="${App.Util.escapeAttr(survey.title)}"
                            oninput="App.actions.updateSurveyField('title',this.value)"
                            class="w-full border rounded-xl px-4 py-3 text-lg font-semibold"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            開始日時
                        </label>

                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.Util.escapeAttr(survey.start_at)}"
                            onchange="App.actions.updateSurveyField('start_at',this.value)"
                            class="w-full border rounded-xl px-4 py-2.5"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            終了日時
                        </label>

                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.Util.escapeAttr(survey.end_at)}"
                            onchange="App.actions.updateSurveyField('end_at',this.value)"
                            class="w-full border rounded-xl px-4 py-2.5"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            質問番号形式
                        </label>

                        <select
                            id="survey_numbering_mode"
                            onchange="App.actions.updateNumbering(this.value)"
                            class="w-full border rounded-xl px-4 py-2.5"
                        >
                            <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                Q1, Q2, Q3...
                            </option>
                            <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                Q1-1, Q1-2...
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            ステータス
                        </label>

                        <div class="flex gap-2">
                            <span class="px-3 py-2 rounded-lg ${App.Util.statusClass(survey.status)}">
                                ${App.Util.statusLabel(survey.status)}
                            </span>

                            ${
                                survey.status !== 'active'
                                    ? `
                                        <button
                                            onclick="App.actions.changeStatusFromEditor('active')"
                                            class="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm"
                                        >
                                            公開
                                        </button>
                                    `
                                    : ''
                            }

                            ${
                                survey.status === 'active'
                                    ? `
                                        <button
                                            onclick="App.actions.changeStatusFromEditor('ended')"
                                            class="px-3 py-2 rounded-lg bg-rose-600 text-white text-sm"
                                        >
                                            停止
                                        </button>
                                    `
                                    : ''
                            }

                            ${
                                survey.status === 'ended'
                                    ? `
                                        <button
                                            onclick="App.actions.changeStatusFromEditor('active')"
                                            class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm"
                                        >
                                            再開
                                        </button>
                                    `
                                    : ''
                            }
                        </div>
                    </div>
                </div>
            </div>

            <div
                id="editor_group_list"
                data-group-list
                class="space-y-5"
            >
                ${groupsHtml}
            </div>
        </section>
    `;
};

/* ================================================================
 * Render Results
 * ================================================================ */

App.Render.results =
function() {
    const survey =
        App.Util.findSurvey(
            App.State.surveyId
        );

    if (!survey) return '';

    const responses =
        App.State.data.responses.filter(
            response =>
                String(response.survey_id) ===
                String(survey.id)
        );

    const customers =
        App.State.data.customers.filter(
            customer =>
                customer.source !== 'web'
        );

    const sent =
        customers.filter(
            customer =>
                Number(customer.send_count || 0) > 0
        ).length;

    const webResponses =
        responses.filter(
            response => {
                const customer =
                    App.State.data.customers.find(
                        c =>
                            String(c.id) ===
                            String(response.customer_id)
                    );

                return (
                    customer &&
                    customer.source === 'web'
                );
            }
        ).length;

    const answeredCustomers =
        customers.filter(
            customer =>
                customer.answer_status === 'answered'
        ).length;

    const unanswered =
        Math.max(
            0,
            sent - answeredCustomers
        );

    const rate =
        sent > 0
            ? (
                answeredCustomers /
                sent *
                100
            ).toFixed(1)
            : '0.0';

    const questions =
        App.Util.allQuestions(survey);

    const questionList =
        questions.map(
            question => `
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        ${
                            App.State.selectedQuestions.includes(
                                String(question.id)
                            )
                                ? 'checked'
                                : ''
                        }
                        onchange="App.actions.toggleQuestion('${App.Util.escapeAttr(question.id)}',this.checked)"
                    >
                    ${App.Util.escape(question.text)}
                    <span class="text-xs text-slate-400">
                        ${question.type}
                    </span>
                </label>
            `
        ).join('');

    const charts =
        questions
            .filter(
                question =>
                    App.State.selectedQuestions.includes(
                        String(question.id)
                    )
            )
            .map(question => {
                const counts = {};

                (question.options || [])
                    .forEach(
                        option => {
                            counts[option] = 0;
                        }
                    );

                responses.forEach(
                    response => {
                        let answer =
                            response.answers?.[
                                question.id
                            ];

                        if (
                            Array.isArray(answer)
                        ) {
                            answer.forEach(
                                item => {
                                    counts[item] =
                                        (
                                            counts[item] ||
                                            0
                                        ) + 1;
                                }
                            );
                        } else if (
                            answer &&
                            counts.hasOwnProperty(
                                answer
                            )
                        ) {
                            counts[answer]++;
                        }
                    }
                );

                if (
                    question.type === 'text'
                ) {
                    const texts =
                        responses
                            .map(
                                response => ({
                                    response,
                                    answer:
                                        response.answers?.[
                                            question.id
                                        ] ?? ''
                                })
                            )
                            .filter(
                                item =>
                                    String(
                                        item.answer
                                    ).trim() !== ''
                            );

                    return `
                        <div class="bg-white border rounded-2xl p-5">
                            <h3 class="font-bold mb-4">
                                ${App.Util.escape(question.text)}
                            </h3>

                            <div class="space-y-3 max-h-80 overflow-auto">
                                ${
                                    texts.length
                                        ? texts.map(
                                            item => `
                                                <div class="border-b pb-3">
                                                    <div class="text-xs text-slate-400">
                                                        ${App.Util.escape(item.response.company)}
                                                        /
                                                        ${App.Util.escape(item.response.name)}
                                                    </div>
                                                    <div class="mt-1 whitespace-pre-wrap">
                                                        ${App.Util.escape(item.answer)}
                                                    </div>
                                                </div>
                                            `
                                        ).join('')
                                        : `
                                            <div class="text-slate-400">
                                                回答データはありません。
                                            </div>
                                        `
                                }
                            </div>
                        </div>
                    `;
                }

                const total =
                    responses.length || 1;

                return `
                    <div class="bg-white border rounded-2xl p-5">
                        <h3 class="font-bold mb-5">
                            ${App.Util.escape(question.text)}
                        </h3>

                        <div class="space-y-4">
                            ${
                                Object.entries(counts)
                                    .map(
                                        ([option, count]) => {
                                            const percent =
                                                Math.round(
                                                    count /
                                                    total *
                                                    100
                                                );

                                            return `
                                                <div>
                                                    <div class="flex justify-between text-sm mb-1">
                                                        <span>
                                                            ${App.Util.escape(option)}
                                                        </span>
                                                        <span>
                                                            ${count}件
                                                            (${percent}%)
                                                        </span>
                                                    </div>

                                                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                                        <div
                                                            class="h-full bg-indigo-500 rounded-full"
                                                            style="width:${percent}%"
                                                        ></div>
                                                    </div>
                                                </div>
                                            `;
                                        }
                                    )
                                    .join('')
                            }
                        </div>
                    </div>
                `;
            })
            .join('');

    const filteredResponses =
        responses.filter(response => {
            const keyword =
                App.State.responseFilter
                    .trim()
                    .toLowerCase();

            if (!keyword) return true;

            return (
                String(response.company || '')
                    .toLowerCase()
                    .includes(keyword) ||
                String(response.name || '')
                    .toLowerCase()
                    .includes(keyword)
            );
        });

    return `
        <section>
            <div class="flex items-center justify-between mb-6">
                <div>
                    <div class="text-sm text-indigo-600">
                        RESULTS
                    </div>

                    <h1 class="text-2xl font-bold">
                        ${App.Util.escape(survey.title)}
                    </h1>
                </div>

                <div class="flex gap-2">
                    <a
                        href="${location.pathname}?action=csv&survey_id=${encodeURIComponent(survey.id)}"
                        class="px-4 py-2 rounded-lg bg-indigo-600 text-white"
                    >
                        CSV出力
                    </a>

                    <button
                        onclick="window.print()"
                        class="px-4 py-2 rounded-lg border"
                    >
                        PDF / 印刷
                    </button>
                </div>
            </div>

            <div class="grid md:grid-cols-5 gap-4 mb-6">
                ${[
                    ['送信対象者数', sent + ' 人'],
                    ['回答数', responses.length + ' 件'],
                    ['未登録顧客からの回答', webResponses + ' 件'],
                    ['未回答数', unanswered + ' 人'],
                    ['回答率', rate + ' %']
                ].map(item => `
                    <div class="bg-white border rounded-2xl p-5">
                        <div class="text-sm text-slate-500">
                            ${item[0]}
                        </div>
                        <div class="text-2xl font-bold mt-2">
                            ${item[1]}
                        </div>
                    </div>
                `).join('')}
            </div>

            <div class="grid lg:grid-cols-[300px_1fr] gap-5">
                <aside class="bg-white border rounded-2xl p-5 h-fit">
                    <div class="font-bold mb-4">
                        設問絞り込み
                    </div>

                    <label class="flex items-center gap-2 mb-4 text-sm">
                        <input
                            type="checkbox"
                            onchange="App.actions.selectAllQuestions(this.checked)"
                            ${
                                App.State.selectedQuestions.length ===
                                questions.length
                                    ? 'checked'
                                    : ''
                            }
                        >
                        全選択
                    </label>

                    <div class="space-y-3">
                        ${questionList}
                    </div>
                </aside>

                <main class="space-y-5">
                    ${
                        charts ||
                        `
                            <div class="bg-white border rounded-2xl p-10 text-center text-slate-400">
                                現在、回答データはありません
                            </div>
                        `
                    }

                    <div class="bg-white border rounded-2xl overflow-hidden">
                        <div class="p-5 border-b">
                            <input
                                id="response_filter"
                                value="${App.Util.escapeAttr(App.State.responseFilter)}"
                                oninput="App.actions.responseSearch(this.value)"
                                placeholder="会社名・氏名で検索"
                                class="border rounded-xl px-4 py-2.5 w-full"
                            >
                        </div>

                        <div class="overflow-x-auto">
                            <table
                                id="response_table"
                                class="w-full min-w-[800px]"
                            >
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="p-3 text-left">会社名</th>
                                        <th class="p-3 text-left">氏名</th>
                                        <th class="p-3 text-left">回答日時</th>
                                        <th class="p-3">操作</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    ${
                                        filteredResponses.map(
                                            response => `
                                                <tr class="border-t">
                                                    <td class="p-3">
                                                        ${App.Util.escape(response.company)}
                                                    </td>
                                                    <td class="p-3">
                                                        ${App.Util.escape(response.name)}
                                                    </td>
                                                    <td class="p-3">
                                                        ${App.Util.escape(response.answered_at)}
                                                    </td>
                                                    <td class="p-3 text-center">
                                                        <button
                                                            onclick="App.actions.showResponse('${App.Util.escapeAttr(response.id)}')"
                                                            class="px-3 py-2 border rounded-lg text-sm"
                                                        >
                                                            全回答を表示
                                                        </button>
                                                    </td>
                                                </tr>
                                            `
                                        ).join('')
                                    }
                                </tbody>
                            </table>
                        </div>
                    </div>
                </main>
            </div>
        </section>
    `;
};

/* ================================================================
 * Render Mail
 * ================================================================ */

App.Render.mail =
function() {
    const survey =
        App.Util.findSurvey(
            App.State.surveyId
        );

    if (!survey) return '';

    const customers =
        App.State.data.customers.filter(
            customer => {
                if (
                    customer.source === 'web'
                ) {
                    return false;
                }

                if (
                    !App.State.customerFilter
                ) {
                    return true;
                }

                const keyword =
                    App.State.customerFilter
                        .toLowerCase();

                return (
                    String(customer.company)
                        .toLowerCase()
                        .includes(keyword) ||
                    String(customer.name)
                        .toLowerCase()
                        .includes(keyword) ||
                    String(customer.email)
                        .toLowerCase()
                        .includes(keyword)
                );
            }
        );

    const rows =
        customers.map(customer => `
            <tr class="border-t">
                <td class="p-3">
                    <input
                        type="checkbox"
                        ${
                            App.State.selectedCustomers.includes(
                                String(customer.id)
                            )
                                ? 'checked'
                                : ''
                        }
                        onchange="App.actions.toggleCustomer('${App.Util.escapeAttr(customer.id)}',this.checked)"
                    >
                </td>

                <td class="p-3">
                    <div class="font-bold">
                        ${App.Util.escape(customer.company)}
                    </div>
                    <div>
                        ${App.Util.escape(customer.name)}
                    </div>
                    <div class="text-xs text-slate-500">
                        ${App.Util.escape(customer.email)}
                    </div>
                </td>

                <td class="p-3">
                    ${App.Util.escape(customer.sent_at || '未送信')}
                </td>

                <td class="p-3">
                    ${customer.send_count || 0} 回
                </td>

                <td class="p-3">
                    <span class="px-2 py-1 rounded-full text-xs ${
                        customer.answer_status === 'answered'
                            ? 'bg-emerald-100 text-emerald-700'
                            : 'bg-amber-100 text-amber-700'
                    }">
                        ${
                            customer.answer_status === 'answered'
                                ? '回答済み'
                                : '送信済み（未回答）'
                        }
                    </span>
                </td>

                <td class="p-3">
                    ${
                        customer.kintone_status === 'registered'
                            ? `
                                <span class="text-emerald-600 text-sm">
                                    ✓ kintone登録完了
                                </span>
                            `
                            : `
                                <button
                                    onclick="App.actions.registerCustomer('${App.Util.escapeAttr(customer.id)}')"
                                    class="px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg text-sm"
                                >
                                    kintone登録完了
                                </button>
                            `
                    }
                </td>
            </tr>
        `).join('');

    return `
        <section>
            <div class="mb-6">
                <div class="text-sm text-indigo-600">
                    MAIL
                </div>

                <h1 class="text-2xl font-bold">
                    顧客選択・メール送信
                </h1>

                <div class="text-sm text-slate-500 mt-1">
                    ${App.Util.escape(survey.title)}
                </div>
            </div>

            <div class="bg-white border rounded-2xl p-5 mb-5">
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            テンプレート
                        </label>

                        <select
                            id="template_type"
                            class="w-full border rounded-xl px-3 py-2.5"
                        >
                            <option value="initial">
                                初回送信
                            </option>
                            <option value="reminder">
                                リマインド
                            </option>
                        </select>
                    </div>

                    <div class="flex items-end">
                        <button
                            onclick="App.actions.sendMail()"
                            class="px-5 py-3 bg-indigo-600 text-white rounded-xl font-semibold"
                        >
                            選択先へ一括送信
                        </button>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold mb-2">
                            件名
                        </label>

                        <input
                            id="mail_subject"
                            value="アンケートのご案内"
                            class="w-full border rounded-xl px-3 py-2.5"
                        >
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold mb-2">
                            本文
                        </label>

                        <textarea
                            id="mail_body"
                            rows="8"
                            class="w-full border rounded-xl px-3 py-3"
                        >{顧客名} 様

アンケートへのご協力をお願いいたします。

回答URL:
{アンケートURL}</textarea>

                        <div class="text-xs text-slate-400 mt-2">
                            使用可能な変数：
                            {顧客名} / {アンケートURL}
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white border rounded-2xl overflow-hidden">
                <div class="p-4 border-b flex gap-3">
                    <input
                        id="customer_filter"
                        value="${App.Util.escapeAttr(App.State.customerFilter)}"
                        oninput="App.actions.searchCustomers(this.value)"
                        placeholder="顧客名・会社名・メールアドレス"
                        class="border rounded-xl px-4 py-2.5 flex-1"
                    >

                    <button
                        onclick="App.actions.selectAllCustomers(true)"
                        class="px-4 py-2 border rounded-xl"
                    >
                        全選択
                    </button>

                    <button
                        onclick="App.actions.selectAllCustomers(false)"
                        class="px-4 py-2 border rounded-xl"
                    >
                        全解除
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table
                        id="customer_table"
                        class="w-full min-w-[1100px]"
                    >
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="p-3">
                                    選択
                                </th>
                                <th class="p-3 text-left">
                                    会社名 / 氏名
                                </th>
                                <th class="p-3 text-left">
                                    最終送信日時
                                </th>
                                <th class="p-3">
                                    送信回数
                                </th>
                                <th class="p-3">
                                    回答ステータス
                                </th>
                                <th class="p-3">
                                    kintone
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            ${rows}
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    `;
};

/* ================================================================
 * Render Settings
 * ================================================================ */

App.Render.settings =
function() {
    const settings =
        App.State.data.settings || {};

    const fields =
        App.State.kintoneFields || [];

    const select =
        (
            id,
            current,
            multiple = false
        ) => `
            <select
                id="${id}"
                ${multiple ? 'multiple' : ''}
                class="w-full border rounded-xl px-3 py-2.5 ${
                    multiple ? 'h-32' : ''
                }"
            >
                ${
                    !multiple
                        ? '<option value="">未選択</option>'
                        : ''
                }

                ${
                    fields.map(
                        field => `
                            <option
                                value="${App.Util.escapeAttr(field.code)}"
                                ${
                                    multiple
                                        ? (
                                            Array.isArray(current) &&
                                            current.includes(field.code)
                                                ? 'selected'
                                                : ''
                                        )
                                        : (
                                            String(current || '') ===
                                            String(field.code)
                                                ? 'selected'
                                                : ''
                                        )
                                }
                            >
                                ${App.Util.escape(field.label)}
                                (${App.Util.escape(field.code)})
                            </option>
                        `
                    ).join('')
                }
            </select>
        `;

    return `
        <section>
            <div class="mb-6">
                <div class="text-sm text-indigo-600">
                    SYSTEM SETTINGS
                </div>

                <h1 class="text-2xl font-bold">
                    kintone連携設定
                </h1>
            </div>

            <div
                id="settings_form"
                class="bg-white border rounded-2xl p-6"
            >
                <div class="grid md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            サブドメイン / FQDN
                        </label>

                        <input
                            id="setting_subdomain"
                            value="${App.Util.escapeAttr(settings.subdomain || '')}"
                            placeholder="xxxx または xxxx.cybozu.com"
                            class="w-full border rounded-xl px-3 py-2.5"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            アプリID
                        </label>

                        <div class="flex gap-2">
                            <input
                                id="setting_app_id"
                                value="${App.Util.escapeAttr(settings.app_id || '')}"
                                class="flex-1 border rounded-xl px-3 py-2.5"
                            >

                            <button
                                onclick="App.actions.fetchKintoneFields()"
                                class="px-4 py-2 bg-indigo-600 text-white rounded-xl"
                            >
                                項目取得
                            </button>
                        </div>

                        <div
                            id="field_message"
                            class="text-xs text-slate-500 mt-2"
                        ></div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            ログイン名
                        </label>

                        <input
                            id="setting_login_name"
                            value="${App.Util.escapeAttr(settings.login_name || '')}"
                            class="w-full border rounded-xl px-3 py-2.5"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            パスワード
                        </label>

                        <input
                            id="setting_password"
                            type="password"
                            placeholder="変更しない場合は空欄"
                            class="w-full border rounded-xl px-3 py-2.5"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            Proxy
                        </label>

                        <input
                            id="setting_proxy"
                            value="${App.Util.escapeAttr(settings.proxy || '')}"
                            placeholder="host:port"
                            class="w-full border rounded-xl px-3 py-2.5"
                        >
                    </div>

                    <div class="flex items-center">
                        <label class="flex items-center gap-2">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${settings.ssl_verify ? 'checked' : ''}
                            >
                            SSL証明書を検証する
                        </label>
                    </div>
                </div>

                <div class="border-t mt-6 pt-6">
                    <h2 class="font-bold mb-4">
                        kintoneフィールドマッピング
                    </h2>

                    <div class="grid md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                会社名
                            </label>
                            ${select(
                                'field_company',
                                settings.field_company
                            )}
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                氏名
                            </label>
                            ${select(
                                'field_name',
                                settings.field_name
                            )}
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                メールアドレス
                            </label>
                            ${select(
                                'field_email',
                                settings.field_email
                            )}
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                部署名
                            </label>
                            ${select(
                                'field_department',
                                settings.field_department
                            )}
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                電話番号
                            </label>
                            ${select(
                                'field_phone',
                                settings.field_phone
                            )}
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                住所
                            </label>
                            ${select(
                                'field_address',
                                settings.field_address,
                                true
                            )}
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button
                        onclick="App.actions.saveSettings()"
                        class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-semibold"
                    >
                        設定を保存
                    </button>
                </div>
            </div>
        </section>
    `;
};

/* ================================================================
 * Main Render
 * ================================================================ */

App.Render.main =
function() {
    const app =
        document.getElementById('app');

    if (!app) return;

    let content = '';

    switch (App.State.page) {
        case 'edit':
            content =
                App.Render.editor();
            break;

        case 'results':
            content =
                App.Render.results();
            break;

        case 'mail':
            content =
                App.Render.mail();
            break;

        case 'settings':
            content =
                App.Render.settings();
            break;

        default:
            content =
                App.Render.surveys();
            break;
    }

    app.innerHTML = `
        ${App.Render.header()}

        <main class="max-w-[1500px] mx-auto p-5">
            ${content}
        </main>
    `;

    if (App.State.page === 'edit') {
        App.actions.initSortables();
    }
};

/* ================================================================
 * Public Answer
 * ================================================================ */

App.actions.renderPublic =
function() {
    const params =
        new URLSearchParams(
            location.search
        );

    const surveyId =
        params.get('survey_id') || '';

    const customerId =
        params.get('customer_id') || '';

    const survey =
        App.State.data.surveys.find(
            item =>
                String(item.id) ===
                surveyId &&
                !item.deleted
        );

    const app =
        document.getElementById('app');

    if (!app) return;

    if (
        !survey ||
        survey.status !== 'active'
    ) {
        app.innerHTML = `
            <div class="max-w-xl mx-auto p-8">
                <div class="bg-white rounded-2xl p-8 text-center">
                    このアンケートは現在回答できません。
                </div>
            </div>
        `;
        return;
    }

    const questions =
        App.Util.allQuestions(survey);

    app.innerHTML = `
        <main class="max-w-3xl mx-auto p-5">
            <div class="bg-white rounded-2xl p-6 shadow-sm">
                <h1 class="text-2xl font-bold mb-8">
                    ${App.Util.escape(survey.title)}
                </h1>

                <form id="public_answer_form">
                    <div class="space-y-8">
                        ${
                            questions.map(
                                (question, index) => `
                                    <section
                                        data-public-question="${App.Util.escapeAttr(question.id)}"
                                    >
                                        <div class="font-semibold mb-3">
                                            Q${index + 1}.
                                            ${App.Util.escape(question.text)}

                                            ${
                                                question.required
                                                    ? '<span class="text-rose-500"> *</span>'
                                                    : ''
                                            }
                                        </div>

                                        ${
                                            question.type === 'text'
                                                ? `
                                                    <textarea
                                                        name="answer_${App.Util.escapeAttr(question.id)}"
                                                        rows="5"
                                                        class="w-full border rounded-xl p-3"
                                                        ${
                                                            question.required
                                                                ? 'required'
                                                                : ''
                                                        }
                                                    ></textarea>
                                                `
                                                :
                                                    `
                                                        <div class="space-y-2">
                                                            ${
                                                                question.options.map(
                                                                    option => `
                                                                        <label class="flex gap-2 items-center">
                                                                            <input
                                                                                type="${
                                                                                    question.type === 'single'
                                                                                        ? 'radio'
                                                                                        : 'checkbox'
                                                                                }"
                                                                                name="answer_${App.Util.escapeAttr(question.id)}${question.type === 'multiple' ? '[]' : ''}"
                                                                                value="${App.Util.escapeAttr(option)}"
                                                                                ${
                                                                                    question.required &&
                                                                                    question.type === 'single'
                                                                                        ? 'required'
                                                                                        : ''
                                                                                }
                                                                            >
                                                                            ${App.Util.escape(option)}
                                                                        </label>
                                                                    `
                                                                ).join('')
                                                            }

                                                            ${
                                                                question.other_enabled
                                                                    ? `
                                                                        <label class="flex gap-2 items-center">
                                                                            <input
                                                                                type="text"
                                                                                name="other_${App.Util.escapeAttr(question.id)}"
                                                                                class="border rounded-lg px-3 py-2"
                                                                                placeholder="その他"
                                                                            >
                                                                        </label>
                                                                    `
                                                                    : ''
                                                            }
                                                        </div>
                                                    `
                                        }
                                    </section>
                                `
                            ).join('')
                        }
                    </div>

                    <div class="mt-8">
                        <button
                            type="button"
                            onclick="App.actions.submitPublic('${App.Util.escapeAttr(surveyId)}','${App.Util.escapeAttr(customerId)}')"
                            class="w-full px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold"
                        >
                            回答を送信
                        </button>
                    </div>
                </form>
            </div>
        </main>
    `;
};

App.actions.submitPublic =
async function(
    surveyId,
    customerId
) {
    const form =
        document.getElementById(
            'public_answer_form'
        );

    if (!form) return;

    const questions =
        App.Util.allQuestions(
            App.State.data.surveys.find(
                survey =>
                    String(survey.id) ===
                    String(surveyId)
            )
        );

    const answers = {};

    for (const question of questions) {
        const name =
            'answer_' + question.id;

        if (
            question.type === 'multiple'
        ) {
            answers[question.id] =
                Array.from(
                    form.querySelectorAll(
                        `input[name="${CSS.escape(name)}[]"]:checked`
                    )
                ).map(
                    element => element.value
                );
        } else if (
            question.type === 'single'
        ) {
            answers[question.id] =
                form.querySelector(
                    `input[name="${CSS.escape(name)}"]:checked`
                )?.value || '';
        } else {
            answers[question.id] =
                form.querySelector(
                    `[name="${CSS.escape(name)}"]`
                )?.value || '';
        }

        if (
            question.required &&
            (
                answers[question.id] === '' ||
                (
                    Array.isArray(
                        answers[question.id]
                    ) &&
                    !answers[question.id].length
                )
            )
        ) {
            alert(
                question.text +
                ' は必須です。'
            );
            return;
        }
    }

    try {
        await App.API.request(
            'public_answer',
            {
                survey_id: surveyId,
                customer_id: customerId,
                answers: answers
            }
        );

        const app =
            document.getElementById('app');

        if (app) {
            app.innerHTML = `
                <div class="max-w-xl mx-auto p-8">
                    <div class="bg-white rounded-2xl p-10 text-center shadow-sm">
                        <div class="text-emerald-600 text-4xl mb-4">
                            ✓
                        </div>

                        <h1 class="text-xl font-bold">
                            ご回答ありがとうございました
                        </h1>

                        <p class="text-slate-500 mt-3">
                            回答を正常に受け付けました。
                        </p>
                    </div>
                </div>
            `;
        }

    } catch (error) {
        alert(error.message);
    }
};

/* ================================================================
 * Initialization
 * ================================================================ */

App.init =
async function() {
    if (App.State.initialized) {
        return;
    }

    App.State.initialized = true;

    try {
        await App.API.load();

        const params =
            new URLSearchParams(
                location.search
            );

        if (
            params.get('public') === '1'
        ) {
            App.actions.renderPublic();
            return;
        }

        App.Render.main();

    } catch (error) {
        const app =
            document.getElementById('app');

        if (app) {
            app.innerHTML = `
                <div class="max-w-xl mx-auto p-8">
                    <div class="bg-white border border-rose-200 rounded-2xl p-6">
                        <div class="font-bold text-rose-700 mb-2">
                            初期化に失敗しました
                        </div>

                        <div class="text-sm whitespace-pre-wrap">
                            ${App.Util.escape(error.message)}
                        </div>
                    </div>
                </div>
            `;
        }
    }
};

/*
 * DOMContentLoaded前後の両方を安全に処理。
 * 初期化はApp.init()のみ。
 */
if (
    document.readyState === 'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        { once: true }
    );
} else {
    App.init();
}
</script>

</body>
</html>
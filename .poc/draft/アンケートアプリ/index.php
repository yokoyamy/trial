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

質問形式:
- single
- multiple
- text

分岐項目:
- option
- target_question_id

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

追加DOM ID:
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
 * PHP: 共通
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
        @unlink(SURVEY_STORAGE_FILE . '.bak');
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

/* ================================================================
 * PHP: kintone
 *
 * 重要:
 * xxxx
 * xxxx.cybozu.com
 * https://xxxx.cybozu.com
 * https://xxxx.cybozu.com/
 *
 * すべてを
 *
 * https://xxxx.cybozu.com
 *
 * に正規化する。
 * ================================================================ */

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

function survey_http_status(): int
{
    $headers = [];

    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
    }

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

    $path = '/' . ltrim($path, '/');
    $url = $baseUrl . $path;

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

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => !empty($settings['ssl_verify']),
            'verify_peer_name' => !empty($settings['ssl_verify']),
            'allow_self_signed' => empty($settings['ssl_verify'])
        ]
    ];

    if ($body !== null) {
        $encodedBody = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($encodedBody === false) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'JSON',
                'message' => 'kintone送信用JSONの生成に失敗しました。',
                'endpoint' => $url
            ];
        }

        $options['http']['content'] = $encodedBody;
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

    $message = (string)(
        $decoded['message'] ??
        'kintone API通信に失敗しました。'
    );

    $errorCode = (string)(
        $decoded['code'] ??
        $decoded['error_code'] ??
        ''
    );

    if ($status === 400 && $errorCode === '') {
        $errorCode = '400';
    }

    return [
        'ok' => false,
        'status' => $status,
        'error_code' => $errorCode,
        'message' => $message,
        'data' => $decoded,
        'endpoint' => $url
    ];
}

/* ================================================================
 * PHP: public URL
 * ================================================================ */

function survey_public_url(
    string $surveyId,
    string $customerId = ''
): string {
    $scheme = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http'
    );

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');

    $query = http_build_query([
        'public' => '1',
        'survey_id' => $surveyId,
        'customer_id' => $customerId
    ]);

    return $scheme . '://' . $host . $path . '?' . $query;
}

/* ================================================================
 * PHP: API
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
            $json = (string)($_POST['survey_json'] ?? '');
            $survey = json_decode($json, true);

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

            foreach ($survey['groups'] as &$group) {
                if (!isset($group['questions']) || !is_array($group['questions'])) {
                    $group['questions'] = [];
                }

                foreach ($group['questions'] as &$question) {
                    $question['options'] =
                        isset($question['options']) &&
                        is_array($question['options'])
                            ? array_values($question['options'])
                            : [];

                    $question['branching'] =
                        isset($question['branching']) &&
                        is_array($question['branching'])
                            ? $question['branching']
                            : [];

                    if (($question['type'] ?? '') !== 'single') {
                        $question['branching'] = [];
                    }
                }

                unset($question);
            }

            unset($group);

            $now = survey_now();
            $found = false;

            foreach ($data['surveys'] as $index => $existing) {
                if ((string)($existing['id'] ?? '') === (string)$survey['id']) {
                    $survey['created_at'] =
                        $existing['created_at'] ?? $now;
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
                    'message' => 'データ保存に失敗しました。'
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
            $json = (string)($_POST['settings_json'] ?? '');
            $settings = json_decode($json, true);

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            $oldPassword =
                (string)($data['settings']['password'] ?? '');

            if (
                ($settings['password'] ?? '') === '' &&
                $oldPassword !== ''
            ) {
                $settings['password'] = $oldPassword;
            }

            $settings['subdomain'] =
                trim((string)($settings['subdomain'] ?? ''));

            $data['settings'] = array_merge(
                survey_default_data()['settings'],
                $settings
            );

            if (!survey_save_data($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '設定保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true,
                'settings' => $data['settings']
            ]);
            break;

        case 'kintone_fields':
            $settings = $data['settings'];

            $appId = trim(
                (string)($_POST['app_id'] ?? $settings['app_id'] ?? '')
            );

            if ($appId === '' || !ctype_digit($appId)) {
                survey_json_response([
                    'ok' => false,
                    'status' => 400,
                    'error_code' => 'APP_ID',
                    'message' => 'アプリIDは数字で入力してください。'
                ], 400);
            }

            $settings['app_id'] = $appId;

            /*
             * GET /k/v1/app/form/fields.json?app=xxx
             *
             * GETなのでbodyは送らない。
             */
            $result = survey_kintone_request(
                'GET',
                '/k/v1/app/form/fields.json?app=' .
                    rawurlencode($appId),
                $settings
            );

            if (!$result['ok']) {
                survey_json_response([
                    'ok' => false,
                    'status' => $result['status'] ?? 0,
                    'error_code' => $result['error_code'] ?? '',
                    'message' => $result['message'] ?? 'kintone API通信に失敗しました。',
                    'endpoint' => $result['endpoint'] ?? ''
                ], 400);
            }

            $fields = [];

            foreach (
                ($result['data']['properties'] ?? [])
                as $code => $field
            ) {
                $type = (string)($field['type'] ?? '');

                /*
                 * 顧客情報として利用しやすいフィールドを取得。
                 * 取得自体はkintoneが返す全フィールドを対象にする。
                 */
                $fields[] = [
                    'label' => (string)(
                        $field['label'] ?? $code
                    ),
                    'code' => (string)$code,
                    'type' => $type
                ];
            }

            survey_json_response([
                'ok' => true,
                'status' => $result['status'] ?? 200,
                'fields' => $fields,
                'endpoint' => $result['endpoint'] ?? ''
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

            $recipientJson =
                (string)($_POST['recipient_ids'] ?? '[]');

            $recipientIds =
                json_decode($recipientJson, true);

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            $subject =
                trim((string)($_POST['mail_subject'] ?? ''));

            $body =
                (string)($_POST['mail_body'] ?? '');

            $templateType =
                (string)($_POST['template_type'] ?? 'initial');

            if ($subject === '' || $body === '') {
                survey_json_response([
                    'ok' => false,
                    'message' => '件名と本文を入力してください。'
                ], 400);
            }

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if ((string)$item['id'] === $surveyId) {
                    $survey = $item;
                    break;
                }
            }

            if (!$survey) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。'
                ], 404);
            }

            $count = 0;
            $messages = [];

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
                    (string)($customer['source'] ?? 'kintone')
                    === 'web'
                ) {
                    continue;
                }

                if (empty($customer['email'])) {
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

                $sent = @mail(
                    (string)$customer['email'],
                    '=?UTF-8?B?' .
                        base64_encode($finalSubject) .
                        '?=',
                    $finalBody,
                    "Content-Type: text/plain; charset=UTF-8\r\n" .
                    "From: survey-system@localhost\r\n"
                );

                $customer['sent_at'] = survey_now();
                $customer['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;
                $customer['answer_status'] =
                    'unanswered';

                $messages[] = [
                    'customer_id' => $customer['id'],
                    'subject' => $finalSubject,
                    'body' => $finalBody,
                    'sent' => $sent
                ];

                $count++;
            }

            unset($customer);

            $data['mail_logs'][] = [
                'id' => survey_id('mail'),
                'survey_id' => $surveyId,
                'sent_at' => survey_now(),
                'template_type' => $templateType,
                'count' => $count,
                'subject' => $subject,
                'messages' => $messages,
                'executor' => 'admin'
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
                exit('Survey not found');
            }

            $questions = [];

            foreach (($survey['groups'] ?? []) as $group) {
                foreach (($group['questions'] ?? []) as $question) {
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

                $answers =
                    $response['answers'] ?? [];

                foreach ($questions as $question) {
                    $answer =
                        $answers[$question['id']] ?? '';

                    if (is_array($answer)) {
                        $answer =
                            implode('、', $answer);
                    }

                    $row[] = $answer;
                }

                fputcsv($fp, $row);
            }

            rewind($fp);

            $csv = stream_get_contents($fp);

            fclose($fp);

            header_remove('Content-Type');
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

            if (!$survey || $survey['status'] !== 'active') {
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
            $customerFound = false;

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

                    $customer['answer_status'] =
                        'answered';

                    $customerFound = true;
                    break;
                }
            }

            unset($customer);

            if (!$customerFound) {
                $email =
                    trim((string)($_POST['email'] ?? ''));

                $name =
                    trim((string)($_POST['name'] ?? ''));

                $company =
                    trim((string)($_POST['company'] ?? ''));

                $customerId =
                    survey_id('customer');

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
 * PHP: 公開回答画面
 * ================================================================ */

if (isset($_GET['public'])) {
    header('Content-Type: text/html; charset=UTF-8');
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <div class="max-w-4xl mx-auto bg-white rounded-2xl shadow-xl overflow-hidden">
        <div class="px-5 py-4 border-b flex items-center justify-between">
            <div class="font-bold">プレビュー</div>
            <div class="flex gap-2">
                <button
                    onclick="App.actions.previewSize(false)"
                    class="px-3 py-2 rounded-lg border text-sm"
                >PC</button>
                <button
                    onclick="App.actions.previewSize(true)"
                    class="px-3 py-2 rounded-lg border text-sm"
                >スマートフォン</button>
                <button
                    onclick="App.actions.closePreview()"
                    class="px-3 py-2 rounded-lg bg-slate-800 text-white"
                >閉じる</button>
            </div>
        </div>
        <div
            id="preview_content"
            class="p-6 bg-slate-100"
        ></div>
    </div>
</div>

<div
    id="response_modal"
    class="hidden fixed inset-0 z-50 bg-black/50 p-5 overflow-auto"
>
    <div class="max-w-3xl mx-auto bg-white rounded-2xl shadow-xl">
        <div class="px-5 py-4 border-b flex justify-between">
            <div class="font-bold">回答詳細</div>
            <button
                onclick="App.actions.closeResponse()"
                class="px-3 py-2 rounded-lg bg-slate-800 text-white"
            >閉じる</button>
        </div>
        <div
            id="response_detail"
            class="p-6"
        ></div>
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
    );
};

App.Util.findQuestion = function(id) {
    const survey = App.State.editSurvey;

    if (!survey) {
        return null;
    }

    for (const group of survey.groups || []) {
        const question = (group.questions || []).find(
            item =>
                String(item.id) === String(id)
        );

        if (question) {
            return question;
        }
    }

    return null;
};

App.Util.findQuestionGroup = function(id) {
    const survey = App.State.editSurvey;

    if (!survey) {
        return null;
    }

    return (survey.groups || []).find(
        group =>
            (group.questions || []).some(
                question =>
                    String(question.id) === String(id)
            )
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

    let number = 0;

    for (let i = 0; i <= groupIndex; i++) {
        number +=
            (survey.groups[i].questions || []).length;
    }

    return 'Q' + (
        number -
        (survey.groups[groupIndex].questions || []).length +
        questionIndex +
        1
    );
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

/* ================================================================
 * API
 * ================================================================ */

App.API.request = async function(action, params = {}) {
    const form = new URLSearchParams();

    form.append('action', action);
    form.append(
        'csrf_token',
        App.State.csrf_token
    );

    Object.entries(params).forEach(([key, value]) => {
        if (Array.isArray(value) || typeof value === 'object') {
            form.append(key, JSON.stringify(value));
        } else {
            form.append(key, String(value ?? ''));
        }
    });

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
        let message =
            json.message ||
            'サーバー処理に失敗しました。';

        if (json.status) {
            message +=
                '\nHTTPステータス: ' +
                json.status;
        }

        if (json.error_code) {
            message +=
                '\nエラーコード: ' +
                json.error_code;
        }

        if (json.endpoint) {
            message +=
                '\n接続先: ' +
                json.endpoint;
        }

        throw new Error(message);
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

    if (!App.State.kintoneFields.length) {
        App.State.kintoneFields = [];
    }
};

App.API.saveSurvey = function(survey) {
    return App.API.request(
        'save_survey',
        {
            survey_json: survey
        }
    );
};

App.API.saveSettings = function(settings) {
    return App.API.request(
        'save_settings',
        {
            settings_json: settings
        }
    );
};

App.API.sendMail = function(
    surveyId,
    ids,
    subject,
    body,
    templateType
) {
    return App.API.request(
        'send_mail',
        {
            survey_id: surveyId,
            recipient_ids: ids,
            mail_subject: subject,
            mail_body: body,
            template_type: templateType
        }
    );
};

/* ================================================================
 * Navigation
 * ================================================================ */

App.actions.goSurveys = function() {
    App.State.page = 'surveys';
    App.State.surveyId = null;
    App.State.editSurvey = null;
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

    App.State.page = 'edit';
    App.State.surveyId =
        App.State.editSurvey.id;
    App.State.dirty = false;

    App.Render.main();
};

App.actions.editSurvey = function(id) {
    const survey =
        App.Util.findSurvey(id);

    if (!survey) {
        return;
    }

    App.State.editSurvey =
        JSON.parse(JSON.stringify(survey));

    App.State.page = 'edit';
    App.State.surveyId = id;
    App.State.dirty = false;

    App.Render.main();
};

App.actions.openResults = function(id) {
    App.State.surveyId = id;
    App.State.page = 'results';

    const survey =
        App.Util.findSurvey(id);

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
 * Survey list
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

App.actions.changeStatus = async function(
    id,
    status
) {
    const label =
        status === 'active'
            ? '公開'
            : '停止';

    if (!confirm(
        'アンケートを' +
        label +
        'しますか？'
    )) {
        return;
    }

    try {
        await App.API.request(
            'status',
            {
                survey_id: id,
                status: status
            }
        );

        await App.API.load();
        App.Render.surveys();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.deleteSurvey = async function(id) {
    if (!confirm(
        'この下書きを削除しますか？'
    )) {
        return;
    }

    try {
        await App.API.request(
            'delete_survey',
            {
                survey_id: id
            }
        );

        await App.API.load();
        App.Render.surveys();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.cloneSurvey = async function(id) {
    const source =
        App.Util.findSurvey(id);

    if (!source) {
        return;
    }

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
        await App.API.saveSurvey(copy);
        await App.API.load();
        App.Render.surveys();
    } catch (error) {
        alert(error.message);
    }
};

/* ================================================================
 * Editor
 *
 * 重要:
 * DOMは正本ではない。
 * Stateが正本。
 *
 * addGroup / addQuestion の前にsyncEditorしない。
 * ================================================================ */

App.actions.markDirty = function() {
    App.State.dirty = true;
};

App.actions.updateSurveyField = function(
    key,
    value
) {
    if (!App.State.editSurvey) {
        return;
    }

    App.State.editSurvey[key] = value;
    App.State.dirty = true;
};

App.actions.updateQuestionNumbering =
    function(value) {
        if (!App.State.editSurvey) {
            return;
        }

        App.State.editSurvey.numbering_mode =
            value;

        App.State.dirty = true;
        App.Render.editor();
    };

App.actions.addGroup = function() {
    const survey =
        App.State.editSurvey;

    if (!survey) {
        return;
    }

    survey.groups.push({
        id: App.Util.id('group'),
        name:
            'グループ' +
            (survey.groups.length + 1),
        questions: []
    });

    App.State.dirty = true;

    App.Render.editor();

    /*
     * 新しく生成されたDOMに対してだけ
     * Sortableを初期化。
     */
    App.actions.initSortables();
};

App.actions.deleteGroup = function(groupId) {
    const survey =
        App.State.editSurvey;

    if (!survey) {
        return;
    }

    const group =
        survey.groups.find(
            item =>
                String(item.id) ===
                String(groupId)
        );

    if (!group) {
        return;
    }

    if (!confirm(
        '「' +
        group.name +
        '」と、その中の質問をすべて削除しますか？'
    )) {
        return;
    }

    const deletedQuestionIds =
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

    if (!survey.groups.length) {
        survey.groups.push({
            id: App.Util.id('group'),
            name: 'グループ1',
            questions: []
        });
    }

    App.actions.removeBrokenBranching(
        deletedQuestionIds
    );

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();
};

App.actions.renameGroup = function(
    groupId,
    value
) {
    const group =
        App.State.editSurvey?.groups.find(
            item =>
                String(item.id) ===
                String(groupId)
        );

    if (!group) {
        return;
    }

    group.name = value;
    App.State.dirty = true;
};

App.actions.addQuestion = function(groupId) {
    const survey =
        App.State.editSurvey;

    if (!survey) {
        return;
    }

    const group =
        survey.groups.find(
            item =>
                String(item.id) ===
                String(groupId)
        );

    if (!group) {
        return;
    }

    group.questions.push({
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
    });

    App.State.dirty = true;

    App.Render.editor();
    App.actions.initSortables();
};

App.actions.deleteQuestion =
    function(questionId) {
        const survey =
            App.State.editSurvey;

        if (!survey) {
            return;
        }

        if (!confirm(
            'この質問を削除しますか？'
        )) {
            return;
        }

        const deleted =
            new Set([String(questionId)]);

        survey.groups.forEach(group => {
            group.questions =
                group.questions.filter(
                    question =>
                        String(question.id) !==
                        String(questionId)
                );
        });

        App.actions.removeBrokenBranching(
            deleted
        );

        App.State.dirty = true;

        App.Render.editor();
        App.actions.initSortables();
    };

App.actions.updateQuestion =
    function(
        questionId,
        key,
        value
    ) {
        const question =
            App.Util.findQuestion(questionId);

        if (!question) {
            return;
        }

        if (
            key === 'required' ||
            key === 'other_enabled'
        ) {
            question[key] =
                Boolean(value);
        } else {
            question[key] = value;
        }

        if (key === 'type') {

            if (value === 'single') {
                if (!Array.isArray(
                    question.branching
                )) {
                    question.branching = [];
                }

                App.actions.syncBranchingOptions(
                    question
                );
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
    function(
        questionId,
        index,
        value
    ) {
        const question =
            App.Util.findQuestion(questionId);

        if (!question) {
            return;
        }

        question.options[index] =
            value;

        if (question.type === 'single') {
            App.actions.syncBranchingOptions(
                question
            );
        }

        App.State.dirty = true;

        /*
         * 選択肢名変更時は分岐設定表示も
         * 即時更新。
         */
        App.Render.editor();
        App.actions.initSortables();
    };

App.actions.addOption =
    function(questionId) {
        const question =
            App.Util.findQuestion(questionId);

        if (!question) {
            return;
        }

        question.options.push(
            '選択肢' +
            (question.options.length + 1)
        );

        App.actions.syncBranchingOptions(
            question
        );

        App.State.dirty = true;

        App.Render.editor();
        App.actions.initSortables();
    };

App.actions.removeOption =
    function(
        questionId,
        index
    ) {
        const question =
            App.Util.findQuestion(questionId);

        if (!question) {
            return;
        }

        question.options.splice(index, 1);

        App.actions.syncBranchingOptions(
            question
        );

        App.State.dirty = true;

        App.Render.editor();
        App.actions.initSortables();
    };

/*
 * 選択肢とbranchingを同期。
 * 既存の分岐先は可能な限り保持する。
 */
App.actions.syncBranchingOptions =
    function(question) {
        const old =
            Array.isArray(question.branching)
                ? question.branching
                : [];

        question.branching =
            question.options.map(option => {
                const existing =
                    old.find(
                        item =>
                            String(item.option) ===
                            String(option)
                    );

                return {
                    option: option,
                    target_question_id:
                        existing
                            ? String(
                                existing.target_question_id || ''
                              )
                            : ''
                };
            });
    };

/*
 * 削除された質問を分岐先に残さない。
 */
App.actions.removeBrokenBranching =
    function(deletedIds) {
        const survey =
            App.State.editSurvey;

        if (!survey) {
            return;
        }

        survey.groups.forEach(group => {
            group.questions.forEach(question => {
                if (!Array.isArray(
                    question.branching
                )) {
                    return;
                }

                question.branching.forEach(item => {
                    if (
                        item.target_question_id &&
                        deletedIds.has(
                            String(
                                item.target_question_id
                            )
                        )
                    ) {
                        item.target_question_id =
                            '';
                    }
                });
            });
        });
    };

App.actions.updateBranching =
    function(
        questionId,
        optionIndex,
        targetId
    ) {
        const question =
            App.Util.findQuestion(questionId);

        if (!question) {
            return;
        }

        if (!Array.isArray(
            question.branching
        )) {
            question.branching = [];
        }

        App.actions.syncBranchingOptions(
            question
        );

        if (question.branching[optionIndex]) {
            question.branching[
                optionIndex
            ].target_question_id =
                targetId;
        }

        App.State.dirty = true;
    };

/* ================================================================
 * SortableJS
 * ================================================================ */

App.actions.destroySortables = function() {
    App.State.sortableInstances
        .forEach(instance => {
            try {
                instance.destroy();
            } catch (error) {
                /* ignore */
            }
        });

    App.State.sortableInstances = [];
};

App.actions.initSortables = function() {
    if (typeof Sortable === 'undefined') {
        return;
    }

    const editor =
        document.getElementById(
            'question_editor'
        );

    if (!editor) {
        return;
    }

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

                    onEnd: function(event) {
                        const ids =
                            Array.from(
                                groupList.children
                            )
                            .map(
                                element =>
                                    element.dataset.groupId
                            )
                            .filter(Boolean);

                        const groups =
                            App.State.editSurvey.groups;

                        groups.sort(
                            (a, b) =>
                                ids.indexOf(String(a.id)) -
                                ids.indexOf(String(b.id))
                        );

                        App.State.dirty = true;

                        /*
                         * DOM再描画はしない。
                         * Sortableが動かしたDOMを
                         * Stateの順序へ反映するだけ。
                         */
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

                        if (!survey) {
                            return;
                        }

                        const newGroups = [];

                        editor.querySelectorAll(
                            '[data-group-id]'
                        ).forEach(
                            groupElement => {
                                const groupId =
                                    groupElement.dataset.groupId;

                                const group =
                                    survey.groups.find(
                                        item =>
                                            String(item.id) ===
                                            String(groupId)
                                    );

                                if (!group) {
                                    return;
                                }

                                const ids =
                                    Array.from(
                                        groupElement
                                            .querySelector(
                                                '[data-question-list]'
                                            )?.children || []
                                    )
                                    .map(
                                        element =>
                                            element.dataset.questionId
                                    )
                                    .filter(Boolean);

                                const questionMap =
                                    new Map();

                                survey.groups.forEach(
                                    sourceGroup => {
                                        sourceGroup.questions
                                            .forEach(question => {
                                                questionMap.set(
                                                    String(question.id),
                                                    question
                                                );
                                            });
                                    }
                                );

                                group.questions =
                                    ids
                                    .map(
                                        id =>
                                            questionMap.get(
                                                String(id)
                                            )
                                    )
                                    .filter(Boolean);

                                newGroups.push(group);
                            }
                        );

                        survey.groups =
                            newGroups;

                        App.State.dirty = true;

                        /*
                         * DOMを再描画しない。
                         * ドラッグ後のDOMを維持する。
                         * 次回Render時にStateから再生成する。
                         */
                    }
                }
            )
        );
    });
};

/* ================================================================
 * Save / Cancel
 * ================================================================ */

App.actions.saveSurvey = async function() {
    const survey =
        App.State.editSurvey;

    if (!survey) {
        return;
    }

    const title =
        String(survey.title || '').trim();

    if (!title) {
        alert('タイトルを入力してください。');
        return;
    }

    try {
        await App.API.saveSurvey(survey);

        App.State.dirty = false;

        await App.API.load();

        alert('保存しました。');

        App.actions.goSurveys();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.cancelEdit = function() {
    if (
        App.State.dirty &&
        !confirm(
            '変更内容を破棄して一覧へ戻りますか？'
        )
    ) {
        return;
    }

    App.actions.goSurveys();
};

/* ================================================================
 * Preview
 * ================================================================ */

App.actions.openPreview = function() {
    const modal =
        document.getElementById(
            'preview_modal'
        );

    if (!modal) {
        return;
    }

    modal.classList.remove('hidden');

    App.Render.preview();
};

App.actions.closePreview = function() {
    document.getElementById(
        'preview_modal'
    )?.classList.add('hidden');
};

App.actions.previewSize =
    function(mobile) {
        App.State.previewMobile =
            Boolean(mobile);

        App.Render.preview();
    };

/* ================================================================
 * Mail
 * ================================================================ */

App.actions.filterCustomers =
    function(value) {
        App.State.customerFilter =
            value;

        App.Render.mail();
    };

App.actions.toggleCustomer =
    function(
        id,
        checked
    ) {
        id = String(id);

        if (checked) {
            if (
                !App.State.selectedCustomers
                    .includes(id)
            ) {
                App.State.selectedCustomers
                    .push(id);
            }
        } else {
            App.State.selectedCustomers =
                App.State.selectedCustomers
                    .filter(
                        item => item !== id
                    );
        }
    };

App.actions.selectAllCustomers =
    function(checked) {
        const customers =
            App.actions.filteredCustomers();

        App.State.selectedCustomers =
            checked
                ? customers
                    .filter(
                        c =>
                            c.source !== 'web'
                    )
                    .map(
                        c => String(c.id)
                    )
                : [];

        App.Render.mail();
    };

App.actions.filteredCustomers =
    function() {
        const keyword =
            App.State.customerFilter
                .trim()
                .toLowerCase();

        return App.State.data.customers
            .filter(customer => {

                if (!keyword) {
                    return true;
                }

                return [
                    customer.company,
                    customer.name,
                    customer.email,
                    customer.phone,
                    customer.address
                ]
                .some(value =>
                    String(value || '')
                        .toLowerCase()
                        .includes(keyword)
                );
            });
    };

App.actions.templateChanged =
    function(type) {
        const subject =
            document.getElementById(
                'mail_subject'
            );

        const body =
            document.getElementById(
                'mail_body'
            );

        if (!subject || !body) {
            return;
        }

        if (type === 'reminder') {
            subject.value =
                'アンケートご回答のお願い（再送）';

            body.value =
                '{顧客名} 様\n\n' +
                '先日ご案内したアンケートが未回答となっております。\n' +
                '以下のURLよりご回答ください。\n\n' +
                '{アンケートURL}\n';
        } else {
            subject.value =
                'アンケートご回答のお願い';

            body.value =
                '{顧客名} 様\n\n' +
                'アンケートへのご協力をお願いいたします。\n\n' +
                '{アンケートURL}\n';
        }
    };

App.actions.sendMail = async function() {
    const ids =
        App.State.selectedCustomers;

    if (!ids.length) {
        alert(
            '送信対象を選択してください。'
        );
        return;
    }

    const subject =
        document.getElementById(
            'mail_subject'
        )?.value.trim() || '';

    const body =
        document.getElementById(
            'mail_body'
        )?.value || '';

    const templateType =
        document.getElementById(
            'template_type'
        )?.value || 'initial';

    if (!subject || !body) {
        alert(
            '件名と本文を入力してください。'
        );
        return;
    }

    const already =
        App.State.data.customers
            .filter(
                customer =>
                    ids.includes(
                        String(customer.id)
                    ) &&
                    Number(
                        customer.send_count || 0
                    ) > 0
            );

    if (already.length) {
        if (!confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )) {
            return;
        }
    }

    if (!confirm(
        ids.length +
        '件にメールを送信します。よろしいですか？'
    )) {
        return;
    }

    try {
        const result =
            await App.API.sendMail(
                App.State.surveyId,
                ids,
                subject,
                body,
                templateType
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

App.actions.registerKintone =
    async function(id) {
        if (!confirm(
            'kintone登録完了として更新しますか？'
        )) {
            return;
        }

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
 * Results
 * ================================================================ */

App.actions.toggleQuestion =
    function(
        id,
        checked
    ) {
        id = String(id);

        if (checked) {
            if (
                !App.State.selectedQuestions
                    .includes(id)
            ) {
                App.State.selectedQuestions
                    .push(id);
            }
        } else {
            App.State.selectedQuestions =
                App.State.selectedQuestions
                    .filter(
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

        if (!survey) {
            return;
        }

        App.State.selectedQuestions =
            checked
                ? App.Util.allQuestions(survey)
                    .map(q => String(q.id))
                : [];

        App.Render.results();
    };

App.actions.responseSearch =
    function(value) {
        App.State.responseFilter =
            value;

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

        if (!response) {
            return;
        }

        const survey =
            App.Util.findSurvey(
                response.survey_id
            );

        if (!survey) {
            return;
        }

        const detail =
            document.getElementById(
                'response_detail'
            );

        if (!detail) {
            return;
        }

        let html = '';

        App.Util.allQuestions(
            survey
        ).forEach(question => {

            let answer =
                response.answers?.[
                    question.id
                ] ?? '';

            if (Array.isArray(answer)) {
                answer =
                    answer.join('、');
            }

            html += `
                <div class="border-b py-4">
                    <div class="text-xs text-slate-500">
                        ${App.Util.escape(question.text)}
                    </div>
                    <div class="mt-1 font-medium whitespace-pre-wrap">
                        ${App.Util.escape(answer)}
                    </div>
                </div>
            `;
        });

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
            ${html}
        `;

        document.getElementById(
            'response_modal'
        )?.classList.remove('hidden');
    };

App.actions.closeResponse =
    function() {
        document.getElementById(
            'response_modal'
        )?.classList.add('hidden');
    };

/* ================================================================
 * kintone settings
 * ================================================================ */

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
                'kintoneから項目一覧を取得しています...';
        }

        try {

            /*
             * 取得前に現在入力中の接続設定を
             * Stateへ反映する。
             */
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

App.actions.readSettings =
    function() {
        const current =
            App.State.data.settings || {};

        const selectedAddress =
            Array.from(
                document.querySelectorAll(
                    '#field_address option:checked'
                )
            ).map(
                option => option.value
            );

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
                )?.value ||
                '',

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
                selectedAddress.length
                    ? selectedAddress
                    : (
                        Array.isArray(
                            current.field_address
                        )
                            ? current.field_address
                            : []
                    )
        };
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
 * Render: Header
 * ================================================================ */

App.Render.header = function() {
    return `
        <header class="sticky top-0 z-30 bg-white border-b border-slate-200">
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

                <nav class="flex items-center gap-1">
                    <button
                        onclick="App.actions.goSurveys()"
                        class="px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-100"
                    >
                        アンケート一覧
                    </button>

                    <button
                        onclick="App.actions.goSettings()"
                        class="px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-100"
                    >
                        kintone連携設定
                    </button>
                </nav>
            </div>
        </header>
    `;
};

/* ================================================================
 * Render: Surveys
 * ================================================================ */

App.Render.surveys = function() {
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
                    String(survey.title || '')
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

    surveys.sort((a, b) => {
        if (
            App.State.sort ===
            'updated_asc'
        ) {
            return String(a.updated_at)
                .localeCompare(
                    String(b.updated_at)
                );
        }

        if (
            App.State.sort ===
            'responses_desc'
        ) {
            return (
                App.State.data.responses
                    .filter(
                        r =>
                            String(r.survey_id) ===
                            String(b.id)
                    ).length
                -
                App.State.data.responses
                    .filter(
                        r =>
                            String(r.survey_id) ===
                            String(a.id)
                    ).length
            );
        }

        if (
            App.State.sort ===
            'responses_asc'
        ) {
            return (
                App.State.data.responses
                    .filter(
                        r =>
                            String(r.survey_id) ===
                            String(a.id)
                    ).length
                -
                App.State.data.responses
                    .filter(
                        r =>
                            String(r.survey_id) ===
                            String(b.id)
                    ).length
            );
        }

        return String(b.updated_at)
            .localeCompare(
                String(a.updated_at)
            );
    });

    const rows =
        surveys.map(survey => {

            const responseCount =
                App.State.data.responses
                    .filter(
                        response =>
                            String(
                                response.survey_id
                            ) ===
                            String(survey.id)
                    )
                    .length;

            let actions = `
                <button
                    onclick="App.actions.editSurvey('${App.Util.escapeAttr(survey.id)}')"
                    class="px-3 py-1.5 rounded-lg bg-white border text-sm"
                >
                    確認・編集
                </button>
            `;

            if (survey.status === 'active') {
                actions += `
                    <button
                        onclick="App.actions.openResults('${App.Util.escapeAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg bg-white border text-sm"
                    >集計</button>

                    <button
                        onclick="App.actions.openMail('${App.Util.escapeAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg bg-white border text-sm"
                    >送信</button>

                    <button
                        onclick="App.actions.changeStatus('${App.Util.escapeAttr(survey.id)}','ended')"
                        class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-sm"
                    >停止</button>
                `;
            }

            if (survey.status === 'draft') {
                actions += `
                    <button
                        onclick="App.actions.deleteSurvey('${App.Util.escapeAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-sm"
                    >削除</button>
                `;
            }

            if (survey.status === 'ended') {
                actions += `
                    <button
                        onclick="App.actions.openResults('${App.Util.escapeAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg bg-white border text-sm"
                    >集計</button>
                `;
            }

            actions += `
                <button
                    onclick="App.actions.cloneSurvey('${App.Util.escapeAttr(survey.id)}')"
                    class="px-3 py-1.5 rounded-lg bg-white border text-sm"
                >複製</button>
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

                    <td class="px-4 py-4">
                        <div class="font-bold">
                            ${App.Util.escape(
                                survey.title
                            )}
                        </div>
                    </td>

                    <td class="px-4 py-4 text-sm">
                        ${App.Util.escape(
                            survey.start_at || '未設定'
                        )}
                        ～
                        ${App.Util.escape(
                            survey.end_at || '未設定'
                        )}
                    </td>

                    <td class="px-4 py-4">
                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold ${App.Util.statusClass(survey.status)}">
                            ${App.Util.statusLabel(survey.status)}
                        </span>
                    </td>

                    <td class="px-4 py-4">
                        ${responseCount} 件
                    </td>

                    <td class="px-4 py-4">
                        <div class="flex flex-wrap gap-2">
                            ${actions}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

    return `
        <section>
            <div class="flex items-center justify-between mb-6">
                <div>
                    <div class="text-sm text-indigo-600 font-semibold">
                        SURVEYS
                    </div>
                    <h1 class="text-2xl font-bold mt-1">
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
                        <option value="all" ${App.State.status_filter === 'all' ? 'selected' : ''}>
                            すべて
                        </option>
                        <option value="active" ${App.State.status_filter === 'active' ? 'selected' : ''}>
                            公開中
                        </option>
                        <option value="draft" ${App.State.status_filter === 'draft' ? 'selected' : ''}>
                            下書き
                        </option>
                        <option value="ended" ${App.State.status_filter === 'ended' ? 'selected' : ''}>
                            終了
                        </option>
                    </select>

                    <select
                        onchange="App.actions.sortSurveys(this.value)"
                        class="border rounded-xl px-4 py-2.5"
                    >
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
                    </select>
                </div>
            </div>

            <div class="bg-white border rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1100px] text-left">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-xs text-slate-500">
                                    作成日 / 更新日
                                </th>
                                <th class="px-4 py-3 text-xs text-slate-500">
                                    タイトル
                                </th>
                                <th class="px-4 py-3 text-xs text-slate-500">
                                    アンケート期間
                                </th>
                                <th class="px-4 py-3 text-xs text-slate-500">
                                    ステータス
                                </th>
                                <th class="px-4 py-3 text-xs text-slate-500">
                                    回答数
                                </th>
                                <th class="px-4 py-3 text-xs text-slate-500">
                                    操作
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            ${rows || `
                                <tr>
                                    <td
                                        colspan="6"
                                        class="px-5 py-12 text-center text-slate-400"
                                    >
                                        アンケートがありません。
                                    </td>
                                </tr>
                            `}
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    `;
};

/* ================================================================
 * Render: Editor
 * ================================================================ */

App.Render.editor = function() {
    const survey =
        App.State.editSurvey;

    if (!survey) {
        return '';
    }

    let groupHtml = '';

    survey.groups.forEach(
        (group, groupIndex) => {

            let questionHtml = '';

            (group.questions || [])
                .forEach(
                    (question, questionIndex) => {

                        const number =
                            App.Util.questionNumber(
                                survey,
                                groupIndex,
                                questionIndex
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
                                        ${(question.options || [])
                                            .map(
                                                (option, optionIndex) => `
                                                    <div class="flex gap-2">
                                                        <input
                                                            value="${App.Util.escapeAttr(option)}"
                                                            oninput="App.actions.updateOption('${App.Util.escapeAttr(question.id)}',${optionIndex},this.value)"
                                                            class="flex-1 border rounded-lg px-3 py-2"
                                                        >

                                                        <button
                                                            onclick="App.actions.removeOption('${App.Util.escapeAttr(question.id)}',${optionIndex})"
                                                            class="px-3 rounded-lg bg-slate-100 hover:bg-rose-50"
                                                        >
                                                            ×
                                                        </button>
                                                    </div>
                                                `
                                            )
                                            .join('')}
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
                            App.actions.syncBranchingOptions(
                                question
                            );

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
                                        単一選択の分岐先設定
                                    </div>

                                    <div class="text-xs text-indigo-700 mb-3">
                                        選択肢ごとに、次に表示する質問を指定できます。
                                        「指定なし」は通常どおり次の質問へ進みます。
                                    </div>

                                    <div class="space-y-3">
                                        ${(question.branching || [])
                                            .map(
                                                (branch, branchIndex) => `
                                                    <div class="grid md:grid-cols-[1fr_1.5fr] gap-2 items-center">
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

                                                            ${allQuestions
                                                                .filter(
                                                                    target =>
                                                                        String(target.id) !==
                                                                        String(question.id)
                                                                )
                                                                .map(
                                                                    target => {
                                                                        const targetIndex =
                                                                            allQuestions.indexOf(
                                                                                target
                                                                            );

                                                                        const targetNumber =
                                                                            App.Util.questionNumber(
                                                                                survey,
                                                                                survey.groups.findIndex(
                                                                                    g =>
                                                                                        g.questions.includes(
                                                                                            target
                                                                                        )
                                                                                ),
                                                                                survey.groups.find(
                                                                                    g =>
                                                                                        g.questions.includes(
                                                                                            target
                                                                                        )
                                                                                )?.questions.indexOf(
                                                                                    target
                                                                                ) ?? 0
                                                                            );

                                                                        return `
                                                                            <option
                                                                                value="${App.Util.escapeAttr(target.id)}"
                                                                                ${String(branch.target_question_id || '') === String(target.id) ? 'selected' : ''}
                                                                            >
                                                                                ${App.Util.escape(targetNumber)}
                                                                                ：
                                                                                ${App.Util.escape(target.text)}
                                                                            </option>
                                                                        `;
                                                                    }
                                                                )
                                                                .join('')}
                                                        </select>
                                                    </div>
                                                `
                                            )
                                            .join('')}
                                    </div>
                                </div>
                            `;
                        }

                        questionHtml += `
                            <article
                                data-question-id="${App.Util.escapeAttr(question.id)}"
                                class="bg-white border rounded-xl p-4 shadow-sm"
                            >
                                <div class="flex items-start gap-3">

                                    <div
                                        class="question-handle cursor-grab text-slate-400 text-xl pt-1 select-none"
                                        title="ドラッグして移動"
                                    >
                                        ⠿
                                    </div>

                                    <div class="flex-1 min-w-0">

                                        <div class="flex flex-wrap items-center gap-2 mb-3">

                                            <span class="text-xs font-bold text-indigo-600">
                                                ${number}
                                            </span>

                                            <select
                                                onchange="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','type',this.value)"
                                                class="border rounded-lg px-2.5 py-1.5 text-sm"
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

                                        </div>

                                        <input
                                            value="${App.Util.escapeAttr(question.text)}"
                                            oninput="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','text',this.value)"
                                            class="w-full border rounded-lg px-3 py-2 font-semibold"
                                            placeholder="質問文"
                                        >

                                        ${optionsHtml}

                                        ${branchingHtml}

                                        <div class="mt-4 flex flex-wrap items-center gap-5 text-sm">

                                            <label class="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    ${question.required ? 'checked' : ''}
                                                    onchange="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','required',this.checked)"
                                                    class="w-4 h-4"
                                                >
                                                必須回答
                                            </label>

                                            ${
                                                question.type !== 'text'
                                                    ? `
                                                        <label class="flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                ${question.other_enabled ? 'checked' : ''}
                                                                onchange="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','other_enabled',this.checked)"
                                                                class="w-4 h-4"
                                                            >
                                                            その他を許可
                                                        </label>
                                                    `
                                                    : ''
                                            }

                                        </div>
                                    </div>

                                    <button
                                        onclick="App.actions.deleteQuestion('${App.Util.escapeAttr(question.id)}')"
                                        class="text-slate-400 hover:text-rose-600 text-lg"
                                        title="削除"
                                    >
                                        ×
                                    </button>
                                </div>
                            </article>
                        `;
                    }
                );

            groupHtml += `
                <section
                    data-group-id="${App.Util.escapeAttr(group.id)}"
                    class="bg-slate-50 border rounded-2xl p-4"
                >

                    <div class="flex items-center gap-3 mb-4">

                        <div
                            class="group-handle cursor-grab text-slate-400 text-xl select-none"
                            title="グループをドラッグ"
                        >
                            ⠿
                        </div>

                        <input
                            value="${App.Util.escapeAttr(group.name)}"
                            onchange="App.actions.renameGroup('${App.Util.escapeAttr(group.id)}',this.value)"
                            class="flex-1 bg-transparent text-lg font-bold outline-none border-b border-transparent focus:border-indigo-400"
                        >

                        <button
                            onclick="App.actions.addQuestion('${App.Util.escapeAttr(group.id)}')"
                            class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm"
                        >
                            ＋ 質問
                        </button>

                        <button
                            onclick="App.actions.deleteGroup('${App.Util.escapeAttr(group.id)}')"
                            class="px-3 py-2 rounded-lg bg-white border text-sm hover:text-rose-600"
                        >
                            グループ削除
                        </button>

                    </div>

                    <div
                        data-question-list
                        class="space-y-3 min-h-[60px]"
                    >
                        ${
                            questionHtml ||
                            `
                                <div class="border border-dashed rounded-xl p-7 text-center text-sm text-slate-400">
                                    質問がありません。
                                    「＋ 質問」から追加してください。
                                </div>
                            `
                        }
                    </div>
                </section>
            `;
        }
    );

    return `
        <section>

            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-6">

                <div>
                    <div class="text-sm text-indigo-600 font-semibold">
                        EDITOR
                    </div>

                    <h1 class="text-2xl font-bold mt-1">
                        アンケート作成・編集
                    </h1>
                </div>

                <div class="flex flex-wrap gap-2">

                    <button
                        onclick="App.actions.cancelEdit()"
                        class="px-4 py-2.5 rounded-xl border bg-white"
                    >
                        キャンセル
                    </button>

                    <button
                        onclick="App.actions.openPreview()"
                        class="px-4 py-2.5 rounded-xl border border-indigo-200 text-indigo-700 bg-indigo-50"
                    >
                        プレビュー
                    </button>

                    <button
                        onclick="App.actions.saveSurvey()"
                        class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold"
                    >
                        保存して一覧へ戻る
                    </button>

                </div>
            </div>

            <div class="bg-white border rounded-2xl p-5 mb-5 shadow-sm">

                <div class="grid md:grid-cols-4 gap-4">

                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">
                            タイトル
                        </label>

                        <input
                            id="survey_title"
                            value="${App.Util.escapeAttr(survey.title)}"
                            oninput="App.actions.updateSurveyField('title',this.value)"
                            class="w-full border rounded-xl px-4 py-3 text-lg font-semibold"
                        >
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">
                            開始日時
                        </label>

                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.Util.escapeAttr(survey.start_at)}"
                            onchange="App.actions.updateSurveyField('start_at',this.value)"
                            class="w-full border rounded-xl px-3 py-3"
                        >
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">
                            終了日時
                        </label>

                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.Util.escapeAttr(survey.end_at)}"
                            onchange="App.actions.updateSurveyField('end_at',this.value)"
                            class="w-full border rounded-xl px-3 py-3"
                        >
                    </div>

                </div>

                <div class="mt-4 flex items-center gap-3">
                    <label class="text-sm font-semibold">
                        質問番号
                    </label>

                    <select
                        id="survey_numbering_mode"
                        onchange="App.actions.updateQuestionNumbering(this.value)"
                        class="border rounded-lg px-3 py-2"
                    >
                        <option
                            value="global"
                            ${survey.numbering_mode === 'global' ? 'selected' : ''}
                        >
                            Q1, Q2, Q3...
                        </option>

                        <option
                            value="group"
                            ${survey.numbering_mode === 'group' ? 'selected' : ''}
                        >
                            Q1-1, Q1-2...
                        </option>
                    </select>
                </div>
            </div>

            <div
                id="question_editor"
                class="space-y-4"
            >

                <div
                    id="editor_group_list"
                    data-group-list
                    class="space-y-4"
                >
                    ${groupHtml}
                </div>

                <!-- 必ず末尾 -->
                <button
                    onclick="App.actions.addGroup()"
                    class="w-full border-2 border-dashed border-slate-300 hover:border-indigo-400 hover:text-indigo-600 rounded-2xl py-5 font-semibold bg-white"
                >
                    ＋ グループを追加
                </button>

            </div>
        </section>
    `;
};

/* ================================================================
 * Render: Settings
 * ================================================================ */

App.Render.fieldOptions =
    function(
        selected,
        multiple = false
    ) {
        const fields =
            App.State.kintoneFields || [];

        const values =
            Array.isArray(selected)
                ? selected
                : [selected || ''];

        return fields
            .map(field => `
                <option
                    value="${App.Util.escapeAttr(field.code)}"
                    ${values.includes(field.code) ? 'selected' : ''}
                >
                    ${App.Util.escape(field.label)}
                    (${App.Util.escape(field.code)})
                </option>
            `)
            .join('');
    };

App.Render.settings = function() {
    const settings =
        App.State.data.settings || {};

    return `
        <section>

            <div class="mb-6">
                <div class="text-sm text-indigo-600 font-semibold">
                    SETTINGS
                </div>

                <h1 class="text-2xl font-bold mt-1">
                    kintone連携設定
                </h1>
            </div>

            <div
                id="settings_form"
                class="bg-white border rounded-2xl p-6"
            >

                <div class="grid md:grid-cols-2 gap-5">

                    <div>
                        <label class="block text-sm font-semibold mb-1">
                            サブドメイン / FQDN
                        </label>

                        <input
                            id="setting_subdomain"
                            value="${App.Util.escapeAttr(settings.subdomain || '')}"
                            placeholder="xxxx または xxxx.cybozu.com"
                            class="w-full border rounded-xl px-4 py-3"
                        >

                        <p class="text-xs text-slate-500 mt-1">
                            https:// を付けても入力できます。
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1">
                            アプリID
                        </label>

                        <input
                            id="setting_app_id"
                            value="${App.Util.escapeAttr(settings.app_id || '')}"
                            inputmode="numeric"
                            class="w-full border rounded-xl px-4 py-3"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1">
                            ログイン名
                        </label>

                        <input
                            id="setting_login_name"
                            value="${App.Util.escapeAttr(settings.login_name || '')}"
                            class="w-full border rounded-xl px-4 py-3"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1">
                            パスワード
                        </label>

                        <input
                            id="setting_password"
                            type="password"
                            placeholder="変更しない場合は空欄"
                            class="w-full border rounded-xl px-4 py-3"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1">
                            Proxyサーバ
                        </label>

                        <input
                            id="setting_proxy"
                            value="${App.Util.escapeAttr(settings.proxy || '')}"
                            placeholder="host:port"
                            class="w-full border rounded-xl px-4 py-3"
                        >
                    </div>

                    <div class="flex items-center">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${settings.ssl_verify ? 'checked' : ''}
                                class="w-4 h-4"
                            >
                            SSL証明書を検証する
                        </label>
                    </div>

                </div>

                <div class="mt-6 flex items-center gap-3">

                    <button
                        onclick="App.actions.fetchKintoneFields()"
                        class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold"
                    >
                        項目一覧を再取得
                    </button>

                    <button
                        onclick="App.actions.saveSettings()"
                        class="px-5 py-3 rounded-xl border bg-white font-semibold"
                    >
                        設定を保存
                    </button>

                    <span
                        id="field_message"
                        class="text-sm text-slate-500"
                    ></span>

                </div>

                <div class="mt-8 border-t pt-6">

                    <h2 class="font-bold mb-4">
                        フィールドマッピング
                    </h2>

                    <div class="grid md:grid-cols-2 gap-5">

                        ${[
                            ['field_company','会社名','field_company'],
                            ['field_name','氏名','field_name'],
                            ['field_email','メールアドレス','field_email'],
                            ['field_department','部署名','field_department'],
                            ['field_phone','電話番号','field_phone']
                        ].map(item => `
                            <div>
                                <label class="block text-sm font-semibold mb-1">
                                    ${item[1]}
                                </label>

                                <select
                                    id="${item[0]}"
                                    class="w-full border rounded-xl px-4 py-3"
                                >
                                    <option value="">
                                        選択してください
                                    </option>

                                    ${App.Render.fieldOptions(
                                        settings[item[2]] || ''
                                    )}
                                </select>
                            </div>
                        `).join('')}

                        <div>
                            <label class="block text-sm font-semibold mb-1">
                                住所
                            </label>

                            <select
                                id="field_address"
                                multiple
                                class="w-full border rounded-xl px-4 py-3 min-h-[130px]"
                            >
                                ${App.Render.fieldOptions(
                                    settings.field_address || [],
                                    true
                                )}
                            </select>

                            <p class="text-xs text-slate-500 mt-1">
                                Ctrlキー等で複数項目を選択できます。
                            </p>
                        </div>

                    </div>

                </div>

            </div>
        </section>
    `;
};

/* ================================================================
 * Render: Mail
 * ================================================================ */

App.Render.mail = function() {
    const survey =
        App.Util.findSurvey(
            App.State.surveyId
        );

    if (!survey) {
        return `
            <div class="bg-white border rounded-2xl p-10 text-center">
                アンケートが見つかりません。
            </div>
        `;
    }

    const customers =
        App.actions.filteredCustomers();

    return `
        <section>

            <div class="flex items-center justify-between mb-6">
                <div>
                    <div class="text-sm text-indigo-600 font-semibold">
                        MAIL
                    </div>

                    <h1 class="text-2xl font-bold mt-1">
                        顧客選択・メール送信
                    </h1>

                    <div class="text-sm text-slate-500 mt-1">
                        ${App.Util.escape(survey.title)}
                    </div>
                </div>
            </div>

            <div class="grid lg:grid-cols-[420px_1fr] gap-5">

                <div class="bg-white border rounded-2xl p-5">

                    <label class="block text-sm font-semibold mb-1">
                        テンプレート
                    </label>

                    <select
                        id="template_type"
                        onchange="App.actions.templateChanged(this.value)"
                        class="w-full border rounded-xl px-3 py-2.5 mb-4"
                    >
                        <option value="initial">
                            初回送信
                        </option>
                        <option value="reminder">
                            リマインド
                        </option>
                    </select>

                    <label class="block text-sm font-semibold mb-1">
                        件名
                    </label>

                    <input
                        id="mail_subject"
                        value="アンケートご回答のお願い"
                        class="w-full border rounded-xl px-3 py-2.5 mb-4"
                    >

                    <label class="block text-sm font-semibold mb-1">
                        本文
                    </label>

                    <textarea
                        id="mail_body"
                        rows="12"
                        class="w-full border rounded-xl px-3 py-2.5"
                    >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}
</textarea>

                    <button
                        onclick="App.actions.sendMail()"
                        class="w-full mt-4 px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold"
                    >
                        選択した顧客へ一括送信
                    </button>

                </div>

                <div class="bg-white border rounded-2xl overflow-hidden">

                    <div class="p-4 border-b">
                        <div class="flex items-center gap-3">
                            <input
                                id="customer_filter"
                                value="${App.Util.escapeAttr(App.State.customerFilter)}"
                                oninput="App.actions.filterCustomers(this.value)"
                                placeholder="顧客名・メール等で検索"
                                class="flex-1 border rounded-xl px-4 py-2.5"
                            >

                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    id="select_all"
                                    type="checkbox"
                                    onchange="App.actions.selectAllCustomers(this.checked)"
                                    class="w-4 h-4"
                                >
                                全選択
                            </label>
                        </div>
                    </div>

                    <div
                        id="customer_table"
                        class="overflow-auto max-h-[650px]"
                    >
                        <table class="w-full min-w-[900px]">
                            <thead class="bg-slate-50 sticky top-0">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs">
                                        選択
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs">
                                        顧客
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs">
                                        メール
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs">
                                        回答
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs">
                                        kintone
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                ${customers.map(customer => `
                                    <tr class="border-t">
                                        <td class="px-4 py-3">
                                            ${
                                                customer.source === 'web'
                                                    ? '<span class="text-xs text-slate-400">Web</span>'
                                                    : `
                                                        <input
                                                            type="checkbox"
                                                            ${App.State.selectedCustomers.includes(String(customer.id)) ? 'checked' : ''}
                                                            onchange="App.actions.toggleCustomer('${App.Util.escapeAttr(customer.id)}',this.checked)"
                                                            class="w-4 h-4"
                                                        >
                                                    `
                                            }
                                        </td>

                                        <td class="px-4 py-3">
                                            <div class="font-semibold">
                                                ${App.Util.escape(customer.company)}
                                            </div>
                                            <div class="text-sm">
                                                ${App.Util.escape(customer.name)}
                                            </div>
                                        </td>

                                        <td class="px-4 py-3 text-sm">
                                            ${App.Util.escape(customer.email)}
                                        </td>

                                        <td class="px-4 py-3">
                                            <span class="px-2 py-1 rounded-full text-xs ${
                                                customer.answer_status === 'answered'
                                                    ? 'bg-emerald-100 text-emerald-700'
                                                    : 'bg-amber-100 text-amber-700'
                                            }">
                                                ${
                                                    customer.answer_status === 'answered'
                                                        ? '回答済み'
                                                        : '未回答'
                                                }
                                            </span>
                                        </td>

                                        <td class="px-4 py-3">
                                            ${
                                                customer.kintone_status === 'registered'
                                                    ? `
                                                        <span class="text-emerald-600 text-sm">
                                                            ✓ 登録完了
                                                        </span>
                                                    `
                                                    : `
                                                        <button
                                                            onclick="App.actions.registerKintone('${App.Util.escapeAttr(customer.id)}')"
                                                            class="px-2.5 py-1.5 rounded-lg bg-slate-100 text-xs"
                                                        >
                                                            登録完了
                                                        </button>
                                                    `
                                            }
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </section>
    `;
};

/* ================================================================
 * Render: Results
 * ================================================================ */

App.Render.results = function() {
    const survey =
        App.Util.findSurvey(
            App.State.surveyId
        );

    if (!survey) {
        return '';
    }

    const questions =
        App.Util.allQuestions(survey);

    const responses =
        App.State.data.responses
            .filter(
                response =>
                    String(response.survey_id) ===
                    String(survey.id)
            )
            .filter(response => {

                const keyword =
                    App.State.responseFilter
                        .trim()
                        .toLowerCase();

                if (!keyword) {
                    return true;
                }

                return [
                    response.company,
                    response.name
                ].some(
                    value =>
                        String(value || '')
                            .toLowerCase()
                            .includes(keyword)
                );
            });

    return `
        <section>

            <div class="mb-6">
                <div class="text-sm text-indigo-600 font-semibold">
                    RESULTS
                </div>

                <h1 class="text-2xl font-bold mt-1">
                    ${App.Util.escape(survey.title)}
                </h1>
            </div>

            <div class="grid md:grid-cols-5 gap-3 mb-6">

                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-xs text-slate-500">
                        送信対象者数
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${
                            App.State.data.customers
                                .filter(
                                    c =>
                                        Number(
                                            c.send_count || 0
                                        ) > 0
                                )
                                .length
                        }
                    </div>
                </div>

                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-xs text-slate-500">
                        回答数
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${responses.length}
                    </div>
                </div>

                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-xs text-slate-500">
                        未登録顧客からの回答
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${
                            responses.filter(
                                r => {
                                    const customer =
                                        App.State.data.customers
                                            .find(
                                                c =>
                                                    String(c.id) ===
                                                    String(r.customer_id)
                                            );

                                    return customer?.source ===
                                        'web';
                                }
                            ).length
                        }
                    </div>
                </div>

                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-xs text-slate-500">
                        未回答数
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${
                            App.State.data.customers
                                .filter(
                                    c =>
                                        Number(
                                            c.send_count || 0
                                        ) > 0 &&
                                        c.answer_status !==
                                            'answered'
                                )
                                .length
                        }
                    </div>
                </div>

                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-xs text-slate-500">
                        回答率
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${
                            (() => {
                                const sent =
                                    App.State.data.customers
                                        .filter(
                                            c =>
                                                Number(
                                                    c.send_count || 0
                                                ) > 0
                                        )
                                        .length;

                                const answered =
                                    App.State.data.responses
                                        .filter(
                                            r =>
                                                String(
                                                    r.survey_id
                                                ) ===
                                                String(survey.id)
                                        )
                                        .length;

                                return sent
                                    ? (
                                        answered /
                                        sent *
                                        100
                                    ).toFixed(1) +
                                    '%'
                                    : '0.0%';
                            })()
                        }
                    </div>
                </div>

            </div>

            <div class="bg-white border rounded-2xl p-5 mb-5">

                <div class="flex items-center justify-between mb-4">
                    <div>
                        <div class="font-bold">
                            設問別集計
                        </div>

                        <div class="text-xs text-slate-500">
                            表示する設問を選択してください。
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.selectAllQuestions(true)"
                            class="px-3 py-2 rounded-lg border text-sm"
                        >
                            全選択
                        </button>

                        <button
                            onclick="App.actions.selectAllQuestions(false)"
                            class="px-3 py-2 rounded-lg border text-sm"
                        >
                            全解除
                        </button>
                    </div>
                </div>

                <div class="space-y-2">
                    ${questions.map(question => `
                        <label class="flex items-center gap-3">
                            <input
                                type="checkbox"
                                ${App.State.selectedQuestions.includes(String(question.id)) ? 'checked' : ''}
                                onchange="App.actions.toggleQuestion('${App.Util.escapeAttr(question.id)}',this.checked)"
                                class="w-4 h-4"
                            >

                            <span class="font-medium">
                                ${App.Util.escape(question.text)}
                            </span>

                            <span class="px-2 py-1 rounded bg-slate-100 text-xs">
                                ${App.Util.escape(question.type)}
                            </span>
                        </label>
                    `).join('')}
                </div>
            </div>

            <div class="space-y-5">

                ${
                    questions
                        .filter(
                            q =>
                                App.State.selectedQuestions
                                    .includes(
                                        String(q.id)
                                    )
                        )
                        .map(question =>
                            App.Render.questionSummary(
                                question,
                                responses
                            )
                        )
                        .join('')
                }

            </div>

            <div class="bg-white border rounded-2xl p-5 mt-5">

                <div class="flex items-center justify-between mb-4">
                    <div class="font-bold">
                        個別回答一覧
                    </div>

                    <div class="flex gap-2">
                        <input
                            id="response_filter"
                            value="${App.Util.escapeAttr(App.State.responseFilter)}"
                            oninput="App.actions.responseSearch(this.value)"
                            placeholder="会社名・氏名"
                            class="border rounded-xl px-4 py-2"
                        >

                        <a
                            href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
                            class="px-4 py-2 rounded-xl bg-slate-800 text-white text-sm"
                        >
                            CSV出力
                        </a>

                        <button
                            onclick="window.print()"
                            class="px-4 py-2 rounded-xl border text-sm"
                        >
                            PDF / 印刷
                        </button>
                    </div>
                </div>

                <div
                    id="response_table"
                    class="overflow-auto"
                >
                    <table class="w-full min-w-[850px]">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs">
                                    回答日時
                                </th>
                                <th class="px-4 py-3 text-left text-xs">
                                    会社名
                                </th>
                                <th class="px-4 py-3 text-left text-xs">
                                    氏名
                                </th>
                                <th class="px-4 py-3 text-left text-xs">
                                    メール
                                </th>
                                <th class="px-4 py-3 text-left text-xs">
                                    操作
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            ${
                                responses.map(response => `
                                    <tr class="border-t">
                                        <td class="px-4 py-3 text-sm">
                                            ${App.Util.escape(response.answered_at)}
                                        </td>

                                        <td class="px-4 py-3">
                                            ${App.Util.escape(response.company)}
                                        </td>

                                        <td class="px-4 py-3">
                                            ${App.Util.escape(response.name)}
                                        </td>

                                        <td class="px-4 py-3 text-sm">
                                            ${App.Util.escape(response.email)}
                                        </td>

                                        <td class="px-4 py-3">
                                            <button
                                                onclick="App.actions.showResponse('${App.Util.escapeAttr(response.id)}')"
                                                class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 text-sm"
                                            >
                                                全回答を表示
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')
                            }
                        </tbody>
                    </table>
                </div>
            </div>

        </section>
    `;
};

App.Render.questionSummary =
    function(
        question,
        responses
    ) {
        if (
            question.type === 'text'
        ) {
            return `
                <div class="bg-white border rounded-2xl p-5">
                    <div class="font-bold mb-4">
                        ${App.Util.escape(question.text)}
                    </div>

                    <div class="space-y-3 max-h-80 overflow-auto">
                        ${
                            responses
                                .map(
                                    response => `
                                        <div class="border-b pb-3">
                                            <div class="text-xs text-slate-400">
                                                ${App.Util.escape(response.company)}
                                                /
                                                ${App.Util.escape(response.name)}
                                            </div>

                                            <div class="mt-1 whitespace-pre-wrap">
                                                ${App.Util.escape(
                                                    response.answers?.[
                                                        question.id
                                                    ] || ''
                                                )}
                                            </div>
                                        </div>
                                    `
                                )
                                .join('')
                        }
                    </div>
                </div>
            `;
        }

        const counts = {};

        (question.options || [])
            .forEach(option => {
                counts[option] = 0;
            });

        responses.forEach(response => {

            let answer =
                response.answers?.[
                    question.id
                ];

            if (Array.isArray(answer)) {
                answer.forEach(value => {
                    counts[value] =
                        (counts[value] || 0) + 1;
                });
            } else if (
                answer !== undefined &&
                answer !== ''
            ) {
                counts[answer] =
                    (counts[answer] || 0) + 1;
            }
        });

        const total =
            responses.length || 1;

        return `
            <div class="bg-white border rounded-2xl p-5">

                <div class="font-bold mb-4">
                    ${App.Util.escape(question.text)}
                </div>

                <div class="space-y-3">

                    ${Object.entries(counts)
                        .map(
                            ([label, count]) => {

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
                                                ${App.Util.escape(label)}
                                            </span>

                                            <span>
                                                ${count}件
                                                /
                                                ${percent}%
                                            </span>
                                        </div>

                                        <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                            <div
                                                class="h-full bg-indigo-500"
                                                style="width:${percent}%"
                                            ></div>
                                        </div>
                                    </div>
                                `;
                            }
                        )
                        .join('')}

                </div>
            </div>
        `;
    };

/* ================================================================
 * Render: Preview
 * ================================================================ */

App.Render.preview = function() {
    const survey =
        App.State.editSurvey;

    const content =
        document.getElementById(
            'preview_content'
        );

    if (!survey || !content) {
        return;
    }

    const width =
        App.State.previewMobile
            ? 'max-w-[390px]'
            : 'max-w-3xl';

    let number = 0;

    content.innerHTML = `
        <div class="${width} mx-auto bg-white rounded-2xl p-6 shadow">

            <h1 class="text-2xl font-bold mb-6">
                ${App.Util.escape(survey.title)}
            </h1>

            ${
                survey.groups
                    .map(
                        (group, groupIndex) => `
                            <div class="mb-8">

                                <h2 class="text-lg font-bold border-b pb-2 mb-4">
                                    ${App.Util.escape(group.name)}
                                </h2>

                                ${
                                    group.questions
                                        .map(
                                            (question, questionIndex) => {

                                                number++;

                                                return `
                                                    <div class="mb-7">

                                                        <div class="font-semibold mb-3">
                                                            ${
                                                                App.Util.questionNumber(
                                                                    survey,
                                                                    groupIndex,
                                                                    questionIndex
                                                                )
                                                            }.
                                                            ${App.Util.escape(question.text)}

                                                            ${
                                                                question.required
                                                                    ? '<span class="text-rose-500">*</span>'
                                                                    : ''
                                                            }
                                                        </div>

                                                        ${
                                                            question.type === 'text'
                                                                ? `
                                                                    <textarea
                                                                        rows="4"
                                                                        class="w-full border rounded-xl px-3 py-2"
                                                                        placeholder="回答を入力"
                                                                    ></textarea>
                                                                `
                                                                :
                                                            question.options
                                                                .map(
                                                                    option => `
                                                                        <label class="flex items-center gap-2 mb-2">
                                                                            <input
                                                                                type="${question.type === 'single' ? 'radio' : 'checkbox'}"
                                                                                name="preview_${question.id}"
                                                                            >
                                                                            ${App.Util.escape(option)}
                                                                        </label>
                                                                    `
                                                                )
                                                                .join('')
                                                        }

                                                        ${
                                                            question.other_enabled
                                                                ? `
                                                                    <input
                                                                        class="mt-2 w-full border rounded-xl px-3 py-2"
                                                                        placeholder="その他"
                                                                    >
                                                                `
                                                                : ''
                                                        }

                                                    </div>
                                                `;
                                            }
                                        )
                                        .join('')
                                }

                            </div>
                        `
                    )
                    .join('')
            }

            <button
                onclick="alert('プレビューでは送信されません。')"
                class="w-full px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold"
            >
                回答を送信
            </button>

        </div>
    `;
};

/* ================================================================
 * Main render
 * ================================================================ */

App.Render.main = function() {
    const app =
        document.getElementById('app');

    if (!app) {
        return;
    }

    let content = '';

    if (App.State.page === 'surveys') {
        content =
            App.Render.surveys();
    } else if (App.State.page === 'edit') {
        content =
            App.Render.editor();
    } else if (App.State.page === 'settings') {
        content =
            App.Render.settings();
    } else if (App.State.page === 'mail') {
        content =
            App.Render.mail();
    } else if (App.State.page === 'results') {
        content =
            App.Render.results();
    }

    app.innerHTML = `
        ${App.Render.header()}

        <main class="max-w-[1500px] mx-auto px-5 py-7">
            ${content}
        </main>
    `;

    if (App.State.page === 'edit') {
        App.actions.initSortables();
    }
};

/* ================================================================
 * Initialization
 * ================================================================ */

App.init = async function() {
    if (App.State.initialized) {
        return;
    }

    App.State.initialized = true;

    try {
        await App.API.load();

        /*
         * 公開回答URLの場合は簡易回答画面を生成。
         */
        const publicMode =
            new URLSearchParams(
                location.search
            ).get('public');

        if (publicMode === '1') {
            App.Render.publicAnswer();
            return;
        }

        App.Render.main();

    } catch (error) {
        document.getElementById(
            'app'
        ).innerHTML = `
            <div class="min-h-screen flex items-center justify-center p-5">
                <div class="bg-white border rounded-2xl p-8 max-w-xl">
                    <div class="text-rose-600 font-bold mb-2">
                        初期化エラー
                    </div>

                    <div class="whitespace-pre-wrap text-sm">
                        ${App.Util.escape(error.message)}
                    </div>

                    <button
                        onclick="location.reload()"
                        class="mt-5 px-4 py-2 rounded-lg bg-slate-800 text-white"
                    >
                        再読み込み
                    </button>
                </div>
            </div>
        `;
    }
};

/* ================================================================
 * Public answer
 * ================================================================ */

App.Render.publicAnswer = function() {
    const params =
        new URLSearchParams(
            location.search
        );

    const surveyId =
        params.get('survey_id') || '';

    const customerId =
        params.get('customer_id') || '';

    const survey =
        App.Util.findSurvey(
            surveyId
        );

    const app =
        document.getElementById('app');

    if (!app) {
        return;
    }

    if (!survey) {
        app.innerHTML = `
            <div class="min-h-screen flex items-center justify-center">
                <div class="bg-white p-8 rounded-2xl border">
                    アンケートが見つかりません。
                </div>
            </div>
        `;
        return;
    }

    let fields = '';

    survey.groups.forEach(
        (group, groupIndex) => {

            group.questions.forEach(
                (question, questionIndex) => {

                    const number =
                        App.Util.questionNumber(
                            survey,
                            groupIndex,
                            questionIndex
                        );

                    fields += `
                        <div class="mb-7">

                            <div class="font-bold mb-3">
                                ${number}.
                                ${App.Util.escape(question.text)}
                                ${
                                    question.required
                                        ? '<span class="text-rose-500">*</span>'
                                        : ''
                                }
                            </div>

                            ${
                                question.type === 'text'
                                    ? `
                                        <textarea
                                            data-answer="${App.Util.escapeAttr(question.id)}"
                                            rows="5"
                                            class="w-full border rounded-xl px-4 py-3"
                                        ></textarea>
                                    `
                                    :
                                    (question.options || [])
                                        .map(
                                            option => `
                                                <label class="flex items-center gap-3 mb-3">
                                                    <input
                                                        data-answer="${App.Util.escapeAttr(question.id)}"
                                                        data-option="${App.Util.escapeAttr(option)}"
                                                        type="${question.type === 'single' ? 'radio' : 'checkbox'}"
                                                        name="answer_${App.Util.escapeAttr(question.id)}"
                                                        value="${App.Util.escapeAttr(option)}"
                                                        class="w-4 h-4"
                                                    >

                                                    ${App.Util.escape(option)}
                                                </label>
                                            `
                                        )
                                        .join('')
                            }

                            ${
                                question.other_enabled
                                    ? `
                                        <input
                                            data-other="${App.Util.escapeAttr(question.id)}"
                                            class="w-full border rounded-xl px-4 py-3 mt-2"
                                            placeholder="その他"
                                        >
                                    `
                                    : ''
                            }

                        </div>
                    `;
                }
            );
        }
    );

    app.innerHTML = `
        <div class="min-h-screen bg-slate-100 py-10 px-5">

            <div class="max-w-3xl mx-auto bg-white border rounded-2xl shadow-sm p-7">

                <h1 class="text-2xl font-bold mb-7">
                    ${App.Util.escape(survey.title)}
                </h1>

                ${fields}

                <div class="grid md:grid-cols-3 gap-3 mb-5">

                    <input
                        id="public_company"
                        placeholder="会社名"
                        class="border rounded-xl px-4 py-3"
                    >

                    <input
                        id="public_name"
                        placeholder="氏名"
                        class="border rounded-xl px-4 py-3"
                    >

                    <input
                        id="public_email"
                        type="email"
                        placeholder="メールアドレス"
                        class="border rounded-xl px-4 py-3"
                    >

                </div>

                <button
                    onclick="App.actions.submitPublicAnswer('${App.Util.escapeAttr(survey.id)}','${App.Util.escapeAttr(customerId)}')"
                    class="w-full px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold"
                >
                    回答を送信
                </button>

            </div>
        </div>
    `;
};

App.actions.submitPublicAnswer =
    async function(
        surveyId,
        customerId
    ) {
        const survey =
            App.Util.findSurvey(
                surveyId
            );

        if (!survey) {
            return;
        }

        const answers = {};

        survey.groups.forEach(
            group => {
                group.questions.forEach(
                    question => {

                        if (
                            question.type ===
                            'text'
                        ) {
                            const element =
                                document.querySelector(
                                    `[data-answer="${CSS.escape(String(question.id))}"]`
                                );

                            answers[question.id] =
                                element?.value || '';

                            return;
                        }

                        const selected =
                            Array.from(
                                document.querySelectorAll(
                                    `[data-answer="${CSS.escape(String(question.id))}"]:checked`
                                )
                            )
                            .map(
                                element =>
                                    element.value
                            );

                        answers[question.id] =
                            question.type === 'single'
                                ? (
                                    selected[0] || ''
                                )
                                : selected;
                    }
                );
            }
        );

        try {

            await App.API.request(
                'public_answer',
                {
                    survey_id: surveyId,
                    customer_id: customerId,
                    company:
                        document.getElementById(
                            'public_company'
                        )?.value || '',
                    name:
                        document.getElementById(
                            'public_name'
                        )?.value || '',
                    email:
                        document.getElementById(
                            'public_email'
                        )?.value || '',
                    answers: answers
                }
            );

            document.getElementById(
                'app'
            ).innerHTML = `
                <div class="min-h-screen flex items-center justify-center p-5">
                    <div class="bg-white border rounded-2xl p-10 text-center max-w-xl">
                        <div class="text-emerald-600 text-4xl mb-4">
                            ✓
                        </div>

                        <h1 class="text-xl font-bold mb-2">
                            回答ありがとうございました
                        </h1>

                        <p class="text-slate-500">
                            回答を正常に受け付けました。
                        </p>
                    </div>
                </div>
            `;

        } catch (error) {
            alert(error.message);
        }
    };

/* ================================================================
 * Lifecycle
 * ================================================================ */

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
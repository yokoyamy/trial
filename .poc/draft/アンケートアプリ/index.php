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
 * PHP 共通
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
        is_array($data['settings'] ?? null)
            ? $data['settings']
            : []
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

function survey_normalize_status(mixed $value): string
{
    $value = (string)$value;

    return in_array(
        $value,
        ['draft', 'active', 'ended'],
        true
    ) ? $value : 'draft';
}

/* ================================================================
 * PHP kintone
 * ================================================================ */

function survey_normalize_kintone_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace(
        '#^\s*https?://#i',
        '',
        $value
    );

    $value = preg_replace(
        '#/.*$#',
        '',
        (string)$value
    );

    $value = trim(
        (string)$value,
        ". \t\n\r\0\x0B"
    );

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
            $m
        )) {
            return (int)$m[1];
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
    $base = survey_normalize_kintone_host(
        (string)($settings['subdomain'] ?? '')
    );

    if ($base === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' => 'kintoneのサブドメインを設定してください。'
        ];
    }

    $url = $base . '/' . ltrim($path, '/');

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' => 'kintoneのログイン名とパスワードを設定してください。',
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
            'timeout' => 30
        ],
        'ssl' => [
            'verify_peer' => !empty($settings['ssl_verify']),
            'verify_peer_name' => !empty($settings['ssl_verify']),
            'allow_self_signed' => empty($settings['ssl_verify'])
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
                'message' => 'Proxyは host:port 形式で入力してください。',
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

    $decoded = json_decode(
        (string)$result,
        true
    );

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

/* ================================================================
 * PHP 公開回答URL
 * ================================================================ */

function survey_public_url(
    string $surveyId,
    string $customerId = ''
): string {
    $scheme = (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ) ? 'https' : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    $path = (string)(
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    return $scheme . '://' . $host . $path . '?' .
        http_build_query([
            'public' => '1',
            'survey_id' => $surveyId,
            'customer_id' => $customerId
        ]);
}

/* ================================================================
 * PHP アンケート整形
 * ================================================================ */

function survey_normalize_question(array $question): array
{
    $type = (string)($question['type'] ?? 'single');

    if (!in_array(
        $type,
        ['single', 'multiple', 'text'],
        true
    )) {
        $type = 'single';
    }

    $options = [];

    foreach (
        (is_array($question['options'] ?? null)
            ? $question['options']
            : [])
        as $option
    ) {
        if (is_array($option)) {
            $text = (string)(
                $option['text'] ??
                $option['label'] ??
                ''
            );
            $id = (string)(
                $option['id'] ??
                survey_id('option')
            );
        } else {
            $text = (string)$option;
            $id = survey_id('option');
        }

        $options[] = [
            'id' => $id,
            'text' => $text
        ];
    }

    $branching = [];

    if ($type === 'single') {
        foreach (
            (is_array($question['branching'] ?? null)
                ? $question['branching']
                : [])
            as $branch
        ) {
            if (!is_array($branch)) {
                continue;
            }

            $option = (string)($branch['option'] ?? '');
            $target = (string)(
                $branch['target_question_id'] ?? ''
            );

            if ($option !== '') {
                $branching[] = [
                    'option' => $option,
                    'target_question_id' => $target
                ];
            }
        }
    }

    return [
        'id' => (string)(
            $question['id'] ?? survey_id('question')
        ),
        'text' => (string)(
            $question['text'] ?? ''
        ),
        'type' => $type,
        'required' => !empty($question['required']),
        'options' => $options,
        'other_enabled' => !empty(
            $question['other_enabled']
        ),
        'branching' => $branching
    ];
}

function survey_normalize_survey(array $survey): array
{
    $groups = [];

    foreach (
        (is_array($survey['groups'] ?? null)
            ? $survey['groups']
            : [])
        as $group
    ) {
        if (!is_array($group)) {
            continue;
        }

        $questions = [];

        foreach (
            (is_array($group['questions'] ?? null)
                ? $group['questions']
                : [])
            as $question
        ) {
            if (is_array($question)) {
                $questions[] =
                    survey_normalize_question($question);
            }
        }

        $groups[] = [
            'id' => (string)(
                $group['id'] ?? survey_id('group')
            ),
            'name' => (string)(
                $group['name'] ?? 'グループ'
            ),
            'questions' => $questions
        ];
    }

    return [
        'id' => (string)(
            $survey['id'] ?? survey_id('survey')
        ),
        'title' => (string)(
            $survey['title'] ?? '新しいアンケート'
        ),
        'start_at' => (string)(
            $survey['start_at'] ?? ''
        ),
        'end_at' => (string)(
            $survey['end_at'] ?? ''
        ),
        'status' => survey_normalize_status(
            $survey['status'] ?? 'draft'
        ),
        'created_at' => (string)(
            $survey['created_at'] ?? ''
        ),
        'updated_at' => (string)(
            $survey['updated_at'] ?? ''
        ),
        'numbering_mode' => (
            ($survey['numbering_mode'] ?? 'global')
            === 'group'
        ) ? 'group' : 'global',
        'groups' => $groups,
        'deleted' => !empty($survey['deleted'])
    ];
}

/* ================================================================
 * PHP API
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
            $raw = (string)(
                $_POST['survey_json'] ?? ''
            );

            $survey = json_decode($raw, true);

            if (!is_array($survey)) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $survey = survey_normalize_survey($survey);

            $now = survey_now();
            $found = false;

            foreach ($data['surveys'] as $index => $existing) {
                if (
                    (string)($existing['id'] ?? '') ===
                    $survey['id']
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
            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $status = survey_normalize_status(
                $_POST['status'] ?? ''
            );

            foreach ($data['surveys'] as &$survey) {
                if (
                    (string)($survey['id'] ?? '') ===
                    $surveyId
                ) {
                    $survey['status'] = $status;
                    $survey['updated_at'] = survey_now();
                    break;
                }
            }

            unset($survey);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'delete_survey':
            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            foreach ($data['surveys'] as &$survey) {
                if (
                    (string)($survey['id'] ?? '') ===
                    $surveyId
                ) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = survey_now();
                    break;
                }
            }

            unset($survey);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
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

            $oldPassword =
                (string)($data['settings']['password'] ?? '');

            if (
                (string)($settings['password'] ?? '') === '' &&
                $oldPassword !== ''
            ) {
                $settings['password'] = $oldPassword;
            }

            $settings['subdomain'] = trim(
                (string)($settings['subdomain'] ?? '')
            );

            $settings['app_id'] = trim(
                (string)($settings['app_id'] ?? '')
            );

            $settings['ssl_verify'] =
                !empty($settings['ssl_verify']);

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
                (string)(
                    $_POST['app_id'] ??
                    $settings['app_id'] ??
                    ''
                )
            );

            if (
                $appId === '' ||
                !ctype_digit($appId)
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アプリIDは数字で入力してください。'
                ], 400);
            }

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
                    'status' => $result['status'] ?? 0,
                    'error_code' => $result['error_code'] ?? '',
                    'message' => $result['message'] ?? '',
                    'endpoint' => $result['endpoint'] ?? ''
                ], 400);
            }

            $fields = [];

            foreach (
                ($result['data']['properties'] ?? [])
                as $code => $field
            ) {
                if (!is_array($field)) {
                    continue;
                }

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
            $customerId = (string)(
                $_POST['customer_id'] ?? ''
            );

            foreach ($data['customers'] as &$customer) {
                if (
                    (string)($customer['id'] ?? '') ===
                    $customerId
                ) {
                    $customer['kintone_status'] =
                        'registered';
                    break;
                }
            }

            unset($customer);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'send_mail':
            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $recipientIds = json_decode(
                (string)(
                    $_POST['recipient_ids'] ?? '[]'
                ),
                true
            );

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            $subject = trim(
                (string)(
                    $_POST['mail_subject'] ?? ''
                )
            );

            $body = (string)(
                $_POST['mail_body'] ?? ''
            );

            $templateType = (string)(
                $_POST['template_type'] ?? 'initial'
            );

            if (
                $subject === '' ||
                $body === ''
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' => '件名と本文を入力してください。'
                ], 400);
            }

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if (
                    (string)($item['id'] ?? '') ===
                    $surveyId
                ) {
                    $survey = $item;
                    break;
                }
            }

            if ($survey === null) {
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
                        (string)($customer['id'] ?? ''),
                        $recipientIds,
                        true
                    )
                ) {
                    continue;
                }

                if (
                    (string)(
                        $customer['source'] ?? 'kintone'
                    ) === 'web'
                ) {
                    continue;
                }

                $email = trim(
                    (string)(
                        $customer['email'] ?? ''
                    )
                );

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
                        (string)(
                            $customer['name'] ?? ''
                        ),
                        $url
                    ],
                    $subject
                );

                $finalBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        (string)(
                            $customer['name'] ?? ''
                        ),
                        $url
                    ],
                    $body
                );

                /*
                 * 実運用ではここをSMTP等へ接続。
                 * モック環境では送信ログとして保存。
                 */
                $customer['sent_at'] = survey_now();
                $customer['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;

                if (
                    (string)(
                        $customer['answer_status'] ?? ''
                    ) !== 'answered'
                ) {
                    $customer['answer_status'] =
                        'unanswered';
                }

                $count++;

                $messages[] = [
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
                'messages' => $messages,
                'executed_by' => (string)(
                    $_SESSION['survey_admin_user'] ?? 'admin'
                )
            ];

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'count' => $count
            ]);
            break;

        case 'save_response':
            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $answers = json_decode(
                (string)(
                    $_POST['answers'] ?? '{}'
                ),
                true
            );

            if (!is_array($answers)) {
                $answers = [];
            }

            $email = trim(
                (string)($_POST['email'] ?? '')
            );

            $name = trim(
                (string)($_POST['name'] ?? '')
            );

            $company = trim(
                (string)($_POST['company'] ?? '')
            );

            $customerId = (string)(
                $_POST['customer_id'] ?? ''
            );

            $responseId = survey_id('response');

            $data['responses'][] = [
                'id' => $responseId,
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => $company,
                'name' => $name,
                'email' => $email,
                'answered_at' => survey_now(),
                'answers' => $answers
            ];

            foreach ($data['customers'] as &$customer) {
                if (
                    $customerId !== '' &&
                    (string)($customer['id'] ?? '') ===
                    $customerId
                ) {
                    $customer['answer_status'] =
                        'answered';
                    break;
                }

                if (
                    $customerId === '' &&
                    $email !== '' &&
                    strcasecmp(
                        (string)($customer['email'] ?? ''),
                        $email
                    ) === 0
                ) {
                    $customer['answer_status'] =
                        'answered';
                    $customerId =
                        (string)$customer['id'];
                    break;
                }
            }

            unset($customer);

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'response_id' => $responseId
            ]);
            break;

        case 'csv':
            $surveyId = (string)(
                $_GET['survey_id'] ?? ''
            );

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if (
                    (string)($item['id'] ?? '') ===
                    $surveyId
                ) {
                    $survey = $item;
                    break;
                }
            }

            if ($survey === null) {
                http_response_code(404);
                exit('Survey not found');
            }

            $questions = [];

            foreach ($survey['groups'] as $group) {
                foreach (
                    ($group['questions'] ?? [])
                    as $question
                ) {
                    $questions[] = $question;
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

            foreach (
                $questions as $index => $question
            ) {
                $header[] = '設問' . ($index + 1);
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

                foreach ($questions as $question) {
                    $qid = (string)(
                        $question['id'] ?? ''
                    );

                    $answer =
                        $response['answers'][$qid] ?? '';

                    if (is_array($answer)) {
                        $answer = implode(
                            '、',
                            array_map(
                                'strval',
                                $answer
                            )
                        );
                    }

                    $row[] = (string)$answer;
                }

                fputcsv($fp, $row);
            }

            fclose($fp);
            exit;

        default:
            survey_json_response([
                'ok' => false,
                'message' => '不明なAPIです。'
            ], 404);
    }
}

/* ================================================================
 * 公開回答画面
 * ================================================================ */

if (
    isset($_GET['public']) &&
    (string)$_GET['public'] === '1'
) {
    $data = survey_load_data();

    $surveyId = (string)(
        $_GET['survey_id'] ?? ''
    );

    $customerId = (string)(
        $_GET['customer_id'] ?? ''
    );

    $survey = null;

    foreach ($data['surveys'] as $item) {
        if (
            (string)($item['id'] ?? '') ===
            $surveyId &&
            empty($item['deleted'])
        ) {
            $survey = survey_normalize_survey($item);
            break;
        }
    }

    if ($survey === null) {
        http_response_code(404);
        echo 'アンケートが見つかりません。';
        exit;
    }

    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($survey['title'], ENT_QUOTES, 'UTF-8') ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800">
<div class="max-w-3xl mx-auto p-5 md:p-10">
<div class="bg-white rounded-3xl shadow-sm p-6 md:p-10">
<h1 class="text-2xl font-bold mb-8">
<?= htmlspecialchars($survey['title'], ENT_QUOTES, 'UTF-8') ?>
</h1>

<form id="publicForm" class="space-y-8">

<?php
$questionIndex = 0;

foreach ($survey['groups'] as $group):
?>
<section class="space-y-5">
<h2 class="text-lg font-bold border-b pb-3">
<?= htmlspecialchars(
    $group['name'],
    ENT_QUOTES,
    'UTF-8'
) ?>
</h2>

<?php foreach ($group['questions'] as $question): ?>
<?php
$questionIndex++;
$qid = $question['id'];
?>
<div
    id="question_<?= htmlspecialchars($qid, ENT_QUOTES, 'UTF-8') ?>"
    data-question-id="<?= htmlspecialchars($qid, ENT_QUOTES, 'UTF-8') ?>"
    class="question-block border border-slate-200 rounded-2xl p-5"
>
<div class="font-semibold mb-4">
<span class="text-indigo-600 mr-2">
Q<?= $questionIndex ?>
</span>
<?= htmlspecialchars(
    $question['text'],
    ENT_QUOTES,
    'UTF-8'
) ?>
<?php if ($question['required']): ?>
<span class="text-red-500 text-sm ml-2">必須</span>
<?php endif; ?>
</div>

<?php if ($question['type'] === 'single'): ?>

<div class="space-y-3">
<?php foreach ($question['options'] as $option): ?>
<label class="flex items-center gap-3 cursor-pointer">
<input
    type="radio"
    name="answers[<?= htmlspecialchars($qid, ENT_QUOTES, 'UTF-8') ?>]"
    value="<?= htmlspecialchars($option['id'], ENT_QUOTES, 'UTF-8') ?>"
    data-option-id="<?= htmlspecialchars($option['id'], ENT_QUOTES, 'UTF-8') ?>"
    class="w-5 h-5"
    <?= $question['required'] ? 'required' : '' ?>
>
<span>
<?= htmlspecialchars(
    $option['text'],
    ENT_QUOTES,
    'UTF-8'
) ?>
</span>
</label>
<?php endforeach; ?>

<?php if ($question['other_enabled']): ?>
<label class="flex items-center gap-3">
<input
    type="radio"
    name="answers[<?= htmlspecialchars($qid, ENT_QUOTES, 'UTF-8') ?>]"
    value="__other__"
    class="w-5 h-5"
>
<span>その他</span>
</label>
<input
    type="text"
    name="other[<?= htmlspecialchars($qid, ENT_QUOTES, 'UTF-8') ?>]"
    class="w-full border rounded-xl px-4 py-3"
    placeholder="その他の内容"
>
<?php endif; ?>
</div>

<?php elseif ($question['type'] === 'multiple'): ?>

<div class="space-y-3">
<?php foreach ($question['options'] as $option): ?>
<label class="flex items-center gap-3">
<input
    type="checkbox"
    name="answers[<?= htmlspecialchars($qid, ENT_QUOTES, 'UTF-8') ?>][]"
    value="<?= htmlspecialchars($option['id'], ENT_QUOTES, 'UTF-8') ?>"
    class="w-5 h-5"
>
<span>
<?= htmlspecialchars(
    $option['text'],
    ENT_QUOTES,
    'UTF-8'
) ?>
</span>
</label>
<?php endforeach; ?>
</div>

<?php else: ?>

<textarea
    name="answers[<?= htmlspecialchars($qid, ENT_QUOTES, 'UTF-8') ?>]"
    rows="5"
    class="w-full border rounded-xl px-4 py-3"
    <?= $question['required'] ? 'required' : '' ?>
></textarea>

<?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>

<div class="grid md:grid-cols-3 gap-4 pt-4">
<input
    name="company"
    placeholder="会社名"
    class="border rounded-xl px-4 py-3"
>
<input
    name="name"
    placeholder="氏名"
    class="border rounded-xl px-4 py-3"
    required
>
<input
    name="email"
    type="email"
    placeholder="メールアドレス"
    class="border rounded-xl px-4 py-3"
    required
>
</div>

<button
    type="submit"
    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl py-4"
>
回答を送信する
</button>
</form>

<div
    id="complete"
    class="hidden text-center py-16"
>
<div class="text-5xl mb-5">✓</div>
<h2 class="text-2xl font-bold">
回答ありがとうございました
</h2>
</div>
</div>
</div>

<script>
const PUBLIC_CONFIG = <?= json_encode(
    [
        'survey_id' => $surveyId,
        'customer_id' => $customerId,
        'csrf_token' => survey_csrf()
    ],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

const publicForm = document.getElementById('publicForm');

publicForm.addEventListener('change', function(event) {
    const input = event.target;

    if (
        !input.matches('input[type="radio"]')
    ) {
        return;
    }

    const block = input.closest('.question-block');

    if (!block) {
        return;
    }

    const optionId =
        input.dataset.optionId || input.value;

    document
        .querySelectorAll('.question-block')
        .forEach(function(item) {
            item.classList.remove('hidden');
        });

    const branches = <?= json_encode(
        array_reduce(
            $survey['groups'],
            static function(array $carry, array $group): array {
                foreach ($group['questions'] as $question) {
                    if (
                        $question['type'] === 'single' &&
                        !empty($question['branching'])
                    ) {
                        $carry[$question['id']] =
                            $question['branching'];
                    }
                }
                return $carry;
            },
            []
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;

    const list =
        branches[block.dataset.questionId] || [];

    const branch =
        list.find(function(item) {
            return item.option === optionId;
        });

    if (!branch || !branch.target_question_id) {
        return;
    }

    let hide = false;

    document
        .querySelectorAll('.question-block')
        .forEach(function(item) {
            if (
                hide &&
                item.dataset.questionId !==
                    branch.target_question_id
            ) {
                item.classList.add('hidden');
            }

            if (
                item.dataset.questionId ===
                branch.target_question_id
            ) {
                hide = true;
            }
        });
});

publicForm.addEventListener('submit', async function(event) {
    event.preventDefault();

    const formData = new FormData(publicForm);

    const answers = {};

    formData.forEach(function(value, key) {
        const match =
            key.match(/^answers\[([^\]]+)\](?:\[\])?$/);

        if (!match) {
            return;
        }

        const qid = match[1];

        if (key.endsWith('[]')) {
            if (!Array.isArray(answers[qid])) {
                answers[qid] = [];
            }
            answers[qid].push(value);
        } else {
            answers[qid] = value;
        }
    });

    const params = new URLSearchParams();

    params.set('action', 'save_response');
    params.set('csrf_token', PUBLIC_CONFIG.csrf_token);
    params.set('survey_id', PUBLIC_CONFIG.survey_id);
    params.set('customer_id', PUBLIC_CONFIG.customer_id);
    params.set('answers', JSON.stringify(answers));
    params.set(
        'company',
        formData.get('company') || ''
    );
    params.set(
        'name',
        formData.get('name') || ''
    );
    params.set(
        'email',
        formData.get('email') || ''
    );

    const response = await fetch(
        location.pathname,
        {
            method: 'POST',
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded'
            },
            body: params
        }
    );

    const json = await response.json();

    if (!json.ok) {
        alert(
            json.message ||
            '回答を送信できませんでした。'
        );
        return;
    }

    publicForm.classList.add('hidden');

    document
        .getElementById('complete')
        .classList.remove('hidden');
});
</script>
</body>
</html>
<?php
    exit;
}

/* ================================================================
 * 管理画面
 * ================================================================ */

$csrf = survey_csrf();

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-800">

<div id="app"></div>

<div
    id="preview_modal"
    class="hidden fixed inset-0 z-50 bg-black/50 p-4"
>
<div class="bg-white rounded-3xl max-w-5xl w-full mx-auto h-full max-h-[90vh] overflow-auto">
<div class="sticky top-0 bg-white border-b px-6 py-4 flex justify-between items-center">
<h2 class="font-bold">プレビュー</h2>
<button
    onclick="App.actions.closePreview()"
    class="px-4 py-2 rounded-xl bg-slate-100"
>
閉じる
</button>
</div>
<div id="preview_content" class="p-6"></div>
</div>
</div>

<div
    id="response_modal"
    class="hidden fixed inset-0 z-50 bg-black/50 p-4"
>
<div class="bg-white rounded-3xl max-w-4xl w-full mx-auto max-h-[90vh] overflow-auto">
<div class="sticky top-0 bg-white border-b px-6 py-4 flex justify-between">
<h2 class="font-bold">回答詳細</h2>
<button
    onclick="App.actions.closeResponseModal()"
    class="px-4 py-2 rounded-xl bg-slate-100"
>
閉じる
</button>
</div>
<div id="response_detail" class="p-6"></div>
</div>
</div>

<script>
window.App = {

State: {
    data: null,
    csrf: <?= json_encode($csrf) ?>,
    page: 'list',
    survey: null,
    dirty: false,
    selectedSurveyId: null,
    filters: {
        keyword: '',
        status_filter: '',
        sort: 'updated_desc'
    },
    responseFilter: '',
    customerFilter: '',
    selectedQuestionIds: [],
    previewMobile: false,
    settingsFields: []
},

Util: {

    esc: function(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    },

    attr: function(value) {
        return this.esc(value)
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    id: function(prefix) {
        return prefix + '_' +
            Math.random()
                .toString(36)
                .slice(2, 12);
    },

    statusLabel: function(status) {
        return {
            draft: '下書き',
            active: '公開中',
            ended: '終了'
        }[status] || status;
    },

    statusClass: function(status) {
        return {
            draft:
                'bg-slate-100 text-slate-600',
            active:
                'bg-emerald-100 text-emerald-700',
            ended:
                'bg-amber-100 text-amber-700'
        }[status] ||
            'bg-slate-100 text-slate-600';
    },

    typeLabel: function(type) {
        return {
            single: '単一選択',
            multiple: '複数選択',
            text: '自由記述'
        }[type] || type;
    },

    clone: function(value) {
        return JSON.parse(
            JSON.stringify(value)
        );
    },

    allQuestions: function(survey) {
        const result = [];

        if (!survey) {
            return result;
        }

        survey.groups.forEach(function(group) {
            group.questions.forEach(function(question) {
                result.push(question);
            });
        });

        return result;
    },

    answerCount: function(surveyId) {
        return App.State.data.responses.filter(
            function(response) {
                return String(
                    response.survey_id
                ) === String(surveyId);
            }
        ).length;
    },

    formatDate: function(value) {
        if (!value) {
            return '未設定';
        }

        return String(value)
            .replace(' ', ' / ');
    }
},

API: {

    call: async function(action, params = {}, method = 'POST') {
        const body = new URLSearchParams();

        body.set('action', action);

        if (method === 'POST') {
            body.set(
                'csrf_token',
                App.State.csrf
            );
        }

        Object.keys(params).forEach(function(key) {
            let value = params[key];

            if (
                typeof value === 'object' &&
                value !== null
            ) {
                value = JSON.stringify(value);
            }

            body.set(key, value);
        });

        const url =
            location.pathname +
            (method === 'GET'
                ? '?' + body.toString()
                : '');

        const response = await fetch(
            url,
            method === 'POST'
                ? {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    },
                    body
                }
                : {
                    method: 'GET'
                }
        );

        const json =
            await response.json();

        if (!json.ok) {
            throw new Error(
                json.message ||
                'APIエラー'
            );
        }

        return json;
    },

    load: async function() {
        const result =
            await App.API.call(
                'load',
                {},
                'GET'
            );

        App.State.data = result.data;
        App.State.csrf =
            result.csrf_token ||
            App.State.csrf;
    },

    saveSurvey: async function() {
        const result =
            await App.API.call(
                'save_survey',
                {
                    survey_json:
                        JSON.stringify(
                            App.State.survey
                        )
                }
            );

        App.State.survey =
            result.survey;

        App.State.dirty = false;

        await App.API.load();

        return result;
    },

    deleteSurvey: async function(id) {
        return App.API.call(
            'delete_survey',
            {
                survey_id: id
            }
        );
    },

    saveSettings: async function(settings) {
        return App.API.call(
            'save_settings',
            {
                settings_json:
                    JSON.stringify(settings)
            }
        );
    },

    fetchKintoneFields: async function(appId) {
        return App.API.call(
            'kintone_fields',
            {
                app_id: appId
            }
        );
    },

    sendMail: async function(params) {
        return App.API.call(
            'send_mail',
            params
        );
    },

    registerCustomer: async function(id) {
        return App.API.call(
            'register_customer',
            {
                customer_id: id
            }
        );
    }
},

Render: {

    shell: function(content, title) {
        return `
<div class="min-h-screen">

<header class="sticky top-0 z-30 bg-white border-b">
<div class="max-w-[1500px] mx-auto px-5 py-4 flex items-center justify-between gap-4">
<div>
<div class="text-xl font-bold text-slate-900">
アンケート管理
</div>
<div class="text-xs text-slate-400">
Survey Management System
</div>
</div>

<nav class="flex flex-wrap gap-2">
<button
    onclick="App.actions.goList()"
    class="px-4 py-2 rounded-xl text-sm font-semibold ${
        App.State.page === 'list'
            ? 'bg-indigo-600 text-white'
            : 'bg-slate-100'
    }"
>
アンケート一覧
</button>

<button
    onclick="App.actions.goSettings()"
    class="px-4 py-2 rounded-xl text-sm font-semibold ${
        App.State.page === 'settings'
            ? 'bg-indigo-600 text-white'
            : 'bg-slate-100'
    }"
>
キントーン連携設定
</button>

<button
    onclick="App.actions.logout()"
    class="px-4 py-2 rounded-xl text-sm font-semibold bg-slate-100"
>
ログアウト
</button>
</nav>
</div>
</header>

<main class="max-w-[1500px] mx-auto p-5 md:p-8">
${title ? `
<div class="mb-6">
<h1 class="text-2xl font-bold">${App.Util.esc(title)}</h1>
</div>
` : ''}
${content}
</main>
</div>
`;
    },

    list: function() {

        let surveys =
            App.State.data.surveys
                .filter(function(survey) {
                    return !survey.deleted;
                })
                .map(function(survey) {
                    return App.Util.clone(survey);
                });

        const f =
            App.State.filters;

        if (f.keyword) {
            surveys =
                surveys.filter(
                    function(survey) {
                        return survey.title
                            .toLowerCase()
                            .includes(
                                f.keyword.toLowerCase()
                            );
                    }
                );
        }

        if (f.status_filter) {
            surveys =
                surveys.filter(
                    function(survey) {
                        return survey.status ===
                            f.status_filter;
                    }
                );
        }

        surveys.sort(function(a, b) {

            if (
                f.sort ===
                'answers_desc'
            ) {
                return App.Util.answerCount(b.id) -
                    App.Util.answerCount(a.id);
            }

            if (
                f.sort ===
                'answers_asc'
            ) {
                return App.Util.answerCount(a.id) -
                    App.Util.answerCount(b.id);
            }

            if (
                f.sort ===
                'start_desc'
            ) {
                return String(b.start_at)
                    .localeCompare(
                        String(a.start_at)
                    );
            }

            if (
                f.sort ===
                'start_asc'
            ) {
                return String(a.start_at)
                    .localeCompare(
                        String(b.start_at)
                    );
            }

            if (
                f.sort ===
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

        const rows = surveys.map(
            function(survey) {

                const count =
                    App.Util.answerCount(
                        survey.id
                    );

                const actions = [];

                actions.push(`
<button
    onclick="App.actions.editSurvey('${App.Util.attr(survey.id)}')"
    class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"
>
確認・編集
</button>
`);

                if (
                    survey.status ===
                    'active' ||
                    survey.status ===
                    'ended'
                ) {
                    actions.push(`
<button
    onclick="App.actions.goResults('${App.Util.attr(survey.id)}')"
    class="px-3 py-2 rounded-lg bg-slate-100 text-xs font-semibold"
>
集計
</button>
`);
                }

                if (
                    survey.status ===
                    'active'
                ) {
                    actions.push(`
<button
    onclick="App.actions.goMail('${App.Util.attr(survey.id)}')"
    class="px-3 py-2 rounded-lg bg-slate-100 text-xs font-semibold"
>
送信
</button>
`);
                }

                if (
                    survey.status ===
                    'draft'
                ) {
                    actions.push(`
<button
    onclick="App.actions.deleteSurvey('${App.Util.attr(survey.id)}')"
    class="px-3 py-2 rounded-lg bg-red-50 text-red-600 text-xs font-semibold"
>
削除
</button>
`);
                }

                actions.push(`
<button
    onclick="App.actions.duplicateSurvey('${App.Util.attr(survey.id)}')"
    class="px-3 py-2 rounded-lg bg-slate-100 text-xs font-semibold"
>
複製
</button>
`);

                return `
<tr class="border-b hover:bg-slate-50">
<td class="px-4 py-5">
<div class="text-xs text-slate-500">
${App.Util.esc(
    survey.created_at || '未設定'
)}
</div>
<div class="text-xs text-slate-400">
更新:
${App.Util.esc(
    survey.updated_at || '未設定'
)}
</div>
</td>

<td class="px-4 py-5">
<div class="font-bold">
${App.Util.esc(survey.title)}
</div>
</td>

<td class="px-4 py-5 text-sm">
${App.Util.esc(
    survey.start_at || '未設定'
)}
～
${App.Util.esc(
    survey.end_at || '未設定'
)}
</td>

<td class="px-4 py-5">
<span class="px-3 py-1 rounded-full text-xs font-bold ${
    App.Util.statusClass(
        survey.status
    )
}">
${App.Util.statusLabel(
    survey.status
)}
</span>
</td>

<td class="px-4 py-5 text-sm font-semibold">
${count} 件
</td>

<td class="px-4 py-5">
<div class="flex flex-wrap gap-2">
${actions.join('')}
</div>
</td>
</tr>
`;
            }
        ).join('');

        return App.Render.shell(`
<div class="flex flex-col md:flex-row justify-between gap-4 mb-6">
<div>
<h1 class="text-2xl font-bold">
アンケート一覧
</h1>
<p class="text-sm text-slate-500 mt-1">
作成・編集・集計・送信をここから管理します。
</p>
</div>

<button
    onclick="App.actions.createSurvey()"
    class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-bold shadow-sm"
>
＋ 新規アンケート作成
</button>
</div>

<div class="bg-white rounded-2xl border p-4 mb-5">
<div class="grid md:grid-cols-3 gap-3">

<input
    id="survey_filter_keyword"
    value="${App.Util.attr(f.keyword)}"
    onkeydown="if(event.key==='Enter')App.actions.searchSurveys()"
    placeholder="タイトルを検索"
    class="border rounded-xl px-4 py-3"
/>

<select
    onchange="App.actions.toggleStatusFilter(this.value)"
    class="border rounded-xl px-4 py-3"
>
<option value="">すべて</option>
<option value="active" ${f.status_filter === 'active' ? 'selected' : ''}>
公開中
</option>
<option value="draft" ${f.status_filter === 'draft' ? 'selected' : ''}>
下書き
</option>
<option value="ended" ${f.status_filter === 'ended' ? 'selected' : ''}>
終了
</option>
</select>

<select
    onchange="App.actions.changeSort(this.value)"
    class="border rounded-xl px-4 py-3"
>
<option value="updated_desc" ${f.sort === 'updated_desc' ? 'selected' : ''}>
更新日：新しい順
</option>
<option value="updated_asc" ${f.sort === 'updated_asc' ? 'selected' : ''}>
更新日：古い順
</option>
<option value="answers_desc" ${f.sort === 'answers_desc' ? 'selected' : ''}>
回答数：多い順
</option>
<option value="answers_asc" ${f.sort === 'answers_asc' ? 'selected' : ''}>
回答数：少ない順
</option>
<option value="start_desc" ${f.sort === 'start_desc' ? 'selected' : ''}>
開始日：新しい順
</option>
<option value="start_asc" ${f.sort === 'start_asc' ? 'selected' : ''}>
開始日：古い順
</option>
</select>

</div>
</div>

<div class="bg-white rounded-2xl border overflow-hidden">
<div class="overflow-x-auto">
<table class="w-full text-left min-w-[1100px]">
<thead class="bg-slate-50 text-xs text-slate-500">
<tr>
<th class="px-4 py-3">作成日 / 更新日</th>
<th class="px-4 py-3">タイトル</th>
<th class="px-4 py-3">アンケート期間</th>
<th class="px-4 py-3">ステータス</th>
<th class="px-4 py-3">回答数</th>
<th class="px-4 py-3">操作</th>
</tr>
</thead>
<tbody>
${rows || `
<tr>
<td colspan="6" class="text-center py-20 text-slate-400">
アンケートがありません。
</td>
</tr>
`}
</tbody>
</table>
</div>
</div>
`, '');
    },

    editor: function() {

        const survey =
            App.State.survey;

        const groups =
            survey.groups.map(
                function(group, groupIndex) {

                    const questions =
                        group.questions.map(
                            function(question, questionIndex) {

                                return App.Render.question(
                                    question,
                                    group,
                                    groupIndex,
                                    questionIndex
                                );
                            }
                        ).join('');

                    return `
<div
    class="group-card bg-white rounded-2xl border p-5"
    data-group-id="${App.Util.attr(group.id)}"
>
<div class="flex items-center gap-3 mb-5">
<span
    class="group-drag cursor-grab text-slate-400 text-xl"
>
⠿
</span>

<input
    value="${App.Util.attr(group.name)}"
    onchange="App.actions.updateGroupName('${App.Util.attr(group.id)}',this.value)"
    class="flex-1 text-lg font-bold border-0 border-b border-slate-200 focus:ring-0"
/>

<button
    onclick="App.actions.deleteGroup('${App.Util.attr(group.id)}')"
    class="px-3 py-2 rounded-lg bg-red-50 text-red-600 text-xs font-semibold"
>
グループ削除
</button>
</div>

<div
    id="question_editor_${App.Util.attr(group.id)}"
    class="question-editor space-y-4 min-h-[30px]"
    data-group-id="${App.Util.attr(group.id)}"
>
${questions}

<button
    onclick="App.actions.addQuestion('${App.Util.attr(group.id)}')"
    class="w-full border-2 border-dashed border-slate-200 hover:border-indigo-300 hover:bg-indigo-50 rounded-xl py-3 text-sm font-semibold text-slate-500"
>
＋ 質問を追加
</button>
</div>
</div>
`;
                }
            ).join('');

        return App.Render.shell(`

<div class="flex flex-col lg:flex-row justify-between gap-4 mb-6">
<div class="flex items-center gap-3">
<button
    onclick="App.actions.backFromEditor()"
    class="px-4 py-2 rounded-xl bg-white border"
>
← 一覧
</button>

<div>
<h1 class="text-2xl font-bold">
アンケート編集
</h1>
<p class="text-xs text-slate-400">
変更内容は保存するまで反映されません。
</p>
</div>
</div>

<div class="flex flex-wrap gap-2">
<button
    onclick="App.actions.preview()"
    class="px-4 py-3 rounded-xl bg-slate-100 font-semibold"
>
プレビュー
</button>

<button
    onclick="App.actions.saveSurvey()"
    class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-bold"
>
保存して一覧へ戻る
</button>

<button
    onclick="App.actions.cancelEdit()"
    class="px-5 py-3 rounded-xl bg-slate-200 font-semibold"
>
キャンセル
</button>
</div>
</div>

<div class="bg-white rounded-2xl border p-5 mb-5">

<div class="grid md:grid-cols-2 lg:grid-cols-4 gap-4">

<div class="lg:col-span-2">
<label class="block text-xs font-bold text-slate-500 mb-2">
アンケートタイトル
</label>
<input
    id="survey_title"
    value="${App.Util.attr(survey.title)}"
    onchange="App.actions.updateSurveyField('title',this.value)"
    class="w-full border rounded-xl px-4 py-3 text-lg font-semibold"
/>
</div>

<div>
<label class="block text-xs font-bold text-slate-500 mb-2">
ステータス
</label>
<select
    id="survey_status"
    onchange="App.actions.updateSurveyField('status',this.value)"
    class="w-full border rounded-xl px-4 py-3 font-semibold"
>
<option value="draft" ${survey.status === 'draft' ? 'selected' : ''}>
下書き
</option>
<option value="active" ${survey.status === 'active' ? 'selected' : ''}>
公開中
</option>
<option value="ended" ${survey.status === 'ended' ? 'selected' : ''}>
終了
</option>
</select>
</div>

<div>
<label class="block text-xs font-bold text-slate-500 mb-2">
質問番号形式
</label>
<select
    id="survey_numbering_mode"
    onchange="App.actions.updateSurveyField('numbering_mode',this.value)"
    class="w-full border rounded-xl px-4 py-3"
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
<label class="block text-xs font-bold text-slate-500 mb-2">
開始日時
</label>
<input
    id="survey_start_at"
    type="datetime-local"
    value="${App.Util.attr(survey.start_at)}"
    onchange="App.actions.updateSurveyField('start_at',this.value)"
    class="w-full border rounded-xl px-4 py-3"
/>
</div>

<div>
<label class="block text-xs font-bold text-slate-500 mb-2">
終了日時
</label>
<input
    id="survey_end_at"
    type="datetime-local"
    value="${App.Util.attr(survey.end_at)}"
    onchange="App.actions.updateSurveyField('end_at',this.value)"
    class="w-full border rounded-xl px-4 py-3"
/>
</div>

</div>
</div>

<div
    id="editor_groups"
    class="space-y-5"
>
${groups}
</div>

<div class="mt-5">
<button
    onclick="App.actions.addGroup()"
    class="w-full border-2 border-dashed border-indigo-200 hover:bg-indigo-50 rounded-2xl py-5 text-indigo-600 font-bold"
>
＋ グループを追加
</button>
</div>

`, 'アンケート編集');
    },

    question: function(
        question,
        group,
        groupIndex,
        questionIndex
    ) {

        const type =
            question.type;

        const options =
            (question.options || [])
                .map(
                    function(option) {

                        const branch =
                            (question.branching || [])
                                .find(
                                    function(item) {
                                        return item.option ===
                                            option.id;
                                    }
                                );

                        const targets =
                            App.Util.allQuestions(
                                App.State.survey
                            ).filter(
                                function(item) {
                                    return item.id !==
                                        question.id;
                                }
                            );

                        return `
<div class="option-row flex flex-col lg:flex-row gap-2 items-stretch lg:items-center">

<span class="text-slate-400 cursor-grab">
⠿
</span>

<input
    value="${App.Util.attr(option.text)}"
    onchange="App.actions.updateOption('${App.Util.attr(question.id)}','${App.Util.attr(option.id)}',this.value)"
    class="flex-1 border rounded-lg px-3 py-2"
/>

${type === 'single' ? `
<select
    onchange="App.actions.updateBranching('${App.Util.attr(question.id)}','${App.Util.attr(option.id)}',this.value)"
    class="lg:w-64 border rounded-lg px-3 py-2 text-sm"
>
<option value="">
分岐なし
</option>
${targets.map(
    function(target) {
        return `
<option
    value="${App.Util.attr(target.id)}"
    ${branch &&
        branch.target_question_id ===
        target.id
        ? 'selected'
        : ''}
>
${App.Util.esc(
    target.text || '無題の質問'
)}
</option>
`;
    }
).join('')}
</select>
` : ''}

<button
    onclick="App.actions.deleteOption('${App.Util.attr(question.id)}','${App.Util.attr(option.id)}')"
    class="px-3 py-2 rounded-lg bg-slate-100 text-slate-500"
>
削除
</button>

</div>
`;
                    }
                ).join('');

        return `
<div
    class="question-card border border-slate-200 rounded-2xl p-5"
    data-question-id="${App.Util.attr(question.id)}"
>
<div class="flex gap-3">

<div class="question-drag cursor-grab text-slate-400 text-xl pt-1">
⠿
</div>

<div class="flex-1">

<div class="flex flex-col lg:flex-row gap-3 mb-4">

<input
    value="${App.Util.attr(question.text)}"
    onchange="App.actions.updateQuestion('${App.Util.attr(question.id)}','text',this.value)"
    placeholder="質問文"
    class="flex-1 border rounded-xl px-4 py-3 font-semibold"
/>

<select
    onchange="App.actions.updateQuestion('${App.Util.attr(question.id)}','type',this.value)"
    class="lg:w-40 border rounded-xl px-3 py-3"
>
<option value="single" ${type === 'single' ? 'selected' : ''}>
単一選択
</option>
<option value="multiple" ${type === 'multiple' ? 'selected' : ''}>
複数選択
</option>
<option value="text" ${type === 'text' ? 'selected' : ''}>
自由記述
</option>
</select>

<label class="flex items-center gap-2 px-3 py-3">
<input
    type="checkbox"
    ${question.required ? 'checked' : ''}
    onchange="App.actions.updateQuestion('${App.Util.attr(question.id)}','required',this.checked)"
    class="w-5 h-5"
/>
必須
</label>

<button
    onclick="App.actions.deleteQuestion('${App.Util.attr(question.id)}')"
    class="px-4 py-2 rounded-xl bg-red-50 text-red-600 text-sm"
>
削除
</button>

</div>

<div class="text-xs font-bold text-slate-400 mb-2">
${App.Util.typeLabel(type)}
${type === 'single' ? ' / 選択肢ごとの質問分岐を設定できます' : ''}
</div>

${type === 'text' ? `
<div class="bg-slate-50 rounded-xl p-4 text-sm text-slate-400">
自由記述回答欄
</div>
` : `
<div class="space-y-2">
${options}

<button
    onclick="App.actions.addOption('${App.Util.attr(question.id)}')"
    class="text-sm font-semibold text-indigo-600 mt-2"
>
＋ 選択肢を追加
</button>

<label class="flex items-center gap-2 mt-3 text-sm">
<input
    type="checkbox"
    ${question.other_enabled ? 'checked' : ''}
    onchange="App.actions.updateQuestion('${App.Util.attr(question.id)}','other_enabled',this.checked)"
>
「その他」を追加
</label>
</div>
`}

</div>
</div>
</div>
`;
    },

    results: function() {

        const survey =
            App.State.survey;

        const responses =
            App.State.data.responses.filter(
                function(response) {
                    return String(
                        response.survey_id
                    ) === String(survey.id);
                }
            );

        const questions =
            App.Util.allQuestions(
                survey
            );

        const customers =
            App.State.data.customers.filter(
                function(customer) {
                    return !App.State.customerFilter ||
                        [
                            customer.company,
                            customer.name,
                            customer.email
                        ].join(' ')
                            .toLowerCase()
                            .includes(
                                App.State.customerFilter
                                    .toLowerCase()
                            );
                }
            );

        const answerCount =
            responses.length;

        const sent =
            customers.filter(
                function(customer) {
                    return Number(
                        customer.send_count || 0
                    ) > 0;
                }
            ).length;

        const unanswered =
            Math.max(
                sent - answerCount,
                0
            );

        const rate =
            sent > 0
                ? (
                    answerCount /
                    sent *
                    100
                ).toFixed(1)
                : '0.0';

        const charts =
            questions.map(
                function(question, qIndex) {

                    if (
                        App.State.selectedQuestionIds.length &&
                        !App.State.selectedQuestionIds.includes(
                            question.id
                        )
                    ) {
                        return '';
                    }

                    if (
                        !App.State.selectedQuestionIds.length &&
                        qIndex > 4
                    ) {
                        return '';
                    }

                    const counts = {};

                    question.options.forEach(
                        function(option) {
                            counts[option.id] = 0;
                        }
                    );

                    responses.forEach(
                        function(response) {
                            let answer =
                                response.answers?.[
                                    question.id
                                ];

                            if (!Array.isArray(answer)) {
                                answer =
                                    answer === undefined
                                        ? []
                                        : [answer];
                            }

                            answer.forEach(
                                function(value) {
                                    if (
                                        counts[value] !==
                                        undefined
                                    ) {
                                        counts[value]++;
                                    }
                                }
                            );
                        }
                    );

                    const total =
                        Math.max(
                            responses.length,
                            1
                        );

                    return `
<div class="bg-white rounded-2xl border p-5">
<div class="flex justify-between gap-3 mb-5">
<div>
<div class="text-xs text-slate-400">
設問 ${qIndex + 1}
</div>
<h3 class="font-bold">
${App.Util.esc(question.text)}
</h3>
</div>

<span class="px-2 py-1 rounded-lg bg-slate-100 text-xs">
${App.Util.typeLabel(question.type)}
</span>
</div>

${
question.type === 'text'
? `
<div class="space-y-3 max-h-72 overflow-auto">
${responses.map(
    function(response) {
        const value =
            response.answers?.[
                question.id
            ] ?? '';

        return `
<div class="border-b pb-3">
<div class="text-xs text-slate-400">
${App.Util.esc(
    response.company || ''
)}
 / 
${App.Util.esc(
    response.name || ''
)}
</div>
<div class="mt-1">
${App.Util.esc(
    Array.isArray(value)
        ? value.join('、')
        : value
)}
</div>
</div>
`;
    }
).join('')}
</div>
`
: `
<div class="space-y-4">
${question.options.map(
    function(option) {

        const count =
            counts[option.id] || 0;

        const percent =
            count /
            total *
            100;

        return `
<div>
<div class="flex justify-between text-sm mb-1">
<span>
${App.Util.esc(option.text)}
</span>
<span>
${count} 件 / ${percent.toFixed(1)}%
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
).join('')}
</div>
`
}
</div>
`;
                }
            ).join('');

        const responseRows =
            responses.filter(
                function(response) {
                    if (
                        !App.State.responseFilter
                    ) {
                        return true;
                    }

                    return [
                        response.company,
                        response.name,
                        response.email
                    ].join(' ')
                        .toLowerCase()
                        .includes(
                            App.State.responseFilter
                                .toLowerCase()
                        );
                }
            )
            .map(
                function(response) {
                    return `
<tr class="border-b">
<td class="px-4 py-3">
${App.Util.esc(
    response.company || ''
)}
</td>
<td class="px-4 py-3">
${App.Util.esc(
    response.name || ''
)}
</td>
<td class="px-4 py-3">
${App.Util.esc(
    response.answered_at || ''
)}
</td>
<td class="px-4 py-3">
<button
    onclick="App.actions.showResponse('${App.Util.attr(response.id)}')"
    class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"
>
全回答を表示
</button>
</td>
</tr>
`;
                }
            ).join('');

        return App.Render.shell(`

<div class="flex flex-col md:flex-row justify-between gap-4 mb-6">
<div>
<button
    onclick="App.actions.goList()"
    class="text-sm text-indigo-600 mb-2"
>
← アンケート一覧
</button>
<h1 class="text-2xl font-bold">
${App.Util.esc(survey.title)}
</h1>
</div>

<a
    href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
    class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-bold text-center"
>
CSV出力
</a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">

${[
    ['送信対象者数', sent + ' 人'],
    ['回答数', answerCount + ' 件'],
    ['未登録顧客からの回答数',
        responses.filter(
            r => !r.customer_id
        ).length + ' 件'
    ],
    ['未回答数', unanswered + ' 人'],
    ['回答率', rate + ' %']
].map(
    function(item) {
        return `
<div class="bg-white rounded-2xl border p-5">
<div class="text-xs text-slate-400 mb-2">
${item[0]}
</div>
<div class="text-2xl font-bold">
${item[1]}
</div>
</div>
`;
    }
).join('')}

</div>

<div class="bg-white rounded-2xl border p-5 mb-5">

<div class="flex flex-wrap justify-between gap-3 mb-4">
<h2 class="font-bold">
設問絞り込み
</h2>

<div class="flex gap-2">
<button
    onclick="App.actions.selectAllQuestions()"
    class="px-3 py-2 rounded-lg bg-slate-100 text-xs"
>
全選択
</button>

<button
    onclick="App.actions.clearQuestionSelection()"
    class="px-3 py-2 rounded-lg bg-slate-100 text-xs"
>
全解除
</button>
</div>
</div>

<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-2">
${questions.map(
    function(question, index) {
        return `
<label class="flex gap-2 items-center p-2 rounded-lg hover:bg-slate-50">
<input
    type="checkbox"
    ${
        !App.State.selectedQuestionIds.length ||
        App.State.selectedQuestionIds.includes(
            question.id
        )
            ? 'checked'
            : ''
    }
    onchange="App.actions.toggleQuestion('${App.Util.attr(question.id)}',this.checked)"
>
<span class="text-sm">
Q${index + 1}
 ${App.Util.esc(question.text)}
</span>
</label>
`;
    }
).join('')}
</div>

</div>

<div class="space-y-5">
${charts}
</div>

<div class="bg-white rounded-2xl border mt-6 overflow-hidden">

<div class="p-5 border-b">
<h2 class="font-bold mb-3">
個別回答一覧
</h2>

<input
    id="response_filter"
    value="${App.Util.attr(App.State.responseFilter)}"
    oninput="App.actions.filterResponses(this.value)"
    placeholder="会社名・氏名で検索"
    class="w-full md:w-96 border rounded-xl px-4 py-3"
/>
</div>

<div class="overflow-x-auto">
<table id="response_table" class="w-full min-w-[700px] text-left">
<thead class="bg-slate-50 text-xs">
<tr>
<th class="px-4 py-3">会社名</th>
<th class="px-4 py-3">氏名</th>
<th class="px-4 py-3">回答日時</th>
<th class="px-4 py-3">操作</th>
</tr>
</thead>
<tbody>
${responseRows || `
<tr>
<td colspan="4" class="text-center py-10 text-slate-400">
現在、回答データはありません
</td>
</tr>
`}
</tbody>
</table>
</div>

</div>

`, '回答集計・分析');
    },

    mail: function() {

        const survey =
            App.State.survey;

        let customers =
            App.State.data.customers.filter(
                function(customer) {
                    if (
                        customer.source ===
                        'web'
                    ) {
                        return false;
                    }

                    if (
                        !App.State.customerFilter
                    ) {
                        return true;
                    }

                    return [
                        customer.company,
                        customer.name,
                        customer.email,
                        customer.answer_status
                    ].join(' ')
                        .toLowerCase()
                        .includes(
                            App.State.customerFilter
                                .toLowerCase()
                        );
                }
            );

        return App.Render.shell(`

<div class="flex justify-between mb-6">
<div>
<button
    onclick="App.actions.goList()"
    class="text-sm text-indigo-600 mb-2"
>
← アンケート一覧
</button>
<h1 class="text-2xl font-bold">
顧客選択・メール送信
</h1>
</div>
</div>

<div class="grid lg:grid-cols-3 gap-5">

<div class="lg:col-span-2 bg-white rounded-2xl border overflow-hidden">

<div class="p-5 border-b">
<div class="flex justify-between gap-3 mb-3">
<h2 class="font-bold">
送信対象者
</h2>

<label class="flex items-center gap-2 text-sm">
<input
    id="select_all"
    type="checkbox"
    onchange="App.actions.selectAllCustomers(this.checked)"
>
全選択
</label>
</div>

<input
    id="customer_filter"
    value="${App.Util.attr(App.State.customerFilter)}"
    oninput="App.actions.filterCustomers(this.value)"
    placeholder="顧客名・メール・ステータスで検索"
    class="w-full border rounded-xl px-4 py-3"
/>
</div>

<div class="overflow-x-auto">
<table id="customer_table" class="w-full min-w-[950px]">
<thead class="bg-slate-50 text-xs text-left">
<tr>
<th class="px-4 py-3">選択</th>
<th class="px-4 py-3">会社名 / 氏名</th>
<th class="px-4 py-3">メール</th>
<th class="px-4 py-3">送信状況</th>
<th class="px-4 py-3">回答</th>
<th class="px-4 py-3">kintone</th>
</tr>
</thead>
<tbody>
${customers.map(
    function(customer) {

        const selected =
            App.State.selectedCustomers?.includes(
                customer.id
            );

        return `
<tr class="border-b">
<td class="px-4 py-3">
<input
    type="checkbox"
    ${selected ? 'checked' : ''}
    onchange="App.actions.toggleCustomer('${App.Util.attr(customer.id)}',this.checked)"
    class="customer-check"
>
</td>

<td class="px-4 py-3">
<div class="font-bold">
${App.Util.esc(
    customer.company || ''
)}
</div>
<div class="text-sm">
${App.Util.esc(
    customer.name || ''
)}
</div>
</td>

<td class="px-4 py-3 text-sm">
${App.Util.esc(
    customer.email || ''
)}
</td>

<td class="px-4 py-3 text-sm">
${
customer.sent_at
    ? `
<div>最終送信: ${App.Util.esc(customer.sent_at)}</div>
<div>送信回数: ${customer.send_count || 0}</div>
`
    : '未送信'
}
</td>

<td class="px-4 py-3">
<span class="px-2 py-1 rounded-lg text-xs ${
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
<span class="text-emerald-600 text-xs font-semibold">
✓ 登録完了
</span>
`
        : `
<button
    onclick="App.actions.registerCustomer('${App.Util.attr(customer.id)}')"
    class="px-2 py-1 rounded-lg bg-indigo-50 text-indigo-700 text-xs"
>
キントーン登録完了
</button>
`
}
</td>
</tr>
`;
    }
).join('')}
</tbody>
</table>
</div>
</div>

<div class="bg-white rounded-2xl border p-5 h-fit">

<h2 class="font-bold mb-5">
メールテンプレート
</h2>

<label class="block text-xs font-bold text-slate-500 mb-2">
テンプレート
</label>

<select
    id="template_type"
    onchange="App.actions.changeTemplate(this.value)"
    class="w-full border rounded-xl px-3 py-3 mb-4"
>
<option value="initial">
初回送信用
</option>
<option value="reminder">
再送・リマインド用
</option>
</select>

<label class="block text-xs font-bold text-slate-500 mb-2">
件名
</label>

<input
    id="mail_subject"
    value="【アンケート】ご回答をお願いします"
    class="w-full border rounded-xl px-4 py-3 mb-4"
/>

<label class="block text-xs font-bold text-slate-500 mb-2">
本文
</label>

<textarea
    id="mail_body"
    rows="12"
    class="w-full border rounded-xl px-4 py-3 mb-4"
>${App.Util.esc('${顧客名} 様

アンケートへのご協力をお願いいたします。

回答URL：
${'${アンケートURL}'}

よろしくお願いいたします。')}</textarea>

<div class="bg-slate-50 rounded-xl p-3 text-xs text-slate-500 mb-4">
利用可能な変数：
{顧客名}
{アンケートURL}
</div>

<button
    onclick="App.actions.sendMail('${App.Util.attr(survey.id)}')"
    class="w-full py-4 rounded-xl bg-indigo-600 text-white font-bold"
>
選択した顧客へ送信
</button>

</div>
</div>

`, '顧客選択・メール送信');
    },

    settings: function() {

        const settings =
            App.State.data.settings;

        const fields =
            App.State.settingsFields;

        function selectField(
            id,
            value,
            multiple
        ) {
            return `
<select
    id="${id}"
    ${multiple ? 'multiple' : ''}
    class="w-full border rounded-xl px-3 py-3"
>
<option value="">
-- 選択してください --
</option>
${fields.map(
    function(field) {
        return `
<option
    value="${App.Util.attr(field.code)}"
    ${value === field.code ? 'selected' : ''}
>
${App.Util.esc(field.label)}
 (${App.Util.esc(field.code)})
</option>
`;
    }
).join('')}
</select>
`;
        }

        return App.Render.shell(`

<div class="flex justify-between mb-6">
<div>
<h1 class="text-2xl font-bold">
キントーン連携設定
</h1>
<p class="text-sm text-slate-500 mt-1">
kintone APIとの接続情報と顧客項目のマッピングを設定します。
</p>
</div>
</div>

<div class="grid lg:grid-cols-2 gap-5">

<div class="bg-white rounded-2xl border p-6">

<h2 class="font-bold mb-5">
接続・認証設定
</h2>

<form
    id="settings_form"
    onsubmit="App.actions.saveSettings(event)"
    class="space-y-4"
>

<div>
<label class="text-xs font-bold text-slate-500">
サブドメイン / FQDN
</label>
<input
    id="setting_subdomain"
    value="${App.Util.attr(settings.subdomain || '')}"
    placeholder="xxxx または xxxx.cybozu.com"
    class="w-full border rounded-xl px-4 py-3 mt-2"
/>
</div>

<div>
<label class="text-xs font-bold text-slate-500">
アプリID
</label>
<input
    id="setting_app_id"
    value="${App.Util.attr(settings.app_id || '')}"
    class="w-full border rounded-xl px-4 py-3 mt-2"
/>
</div>

<div>
<label class="text-xs font-bold text-slate-500">
ログイン名
</label>
<input
    id="setting_login_name"
    value="${App.Util.attr(settings.login_name || '')}"
    class="w-full border rounded-xl px-4 py-3 mt-2"
/>
</div>

<div>
<label class="text-xs font-bold text-slate-500">
パスワード
</label>
<input
    id="setting_password"
    type="password"
    placeholder="変更しない場合は空欄"
    class="w-full border rounded-xl px-4 py-3 mt-2"
/>
</div>

<div>
<label class="text-xs font-bold text-slate-500">
Proxyサーバ
</label>
<input
    id="setting_proxy"
    value="${App.Util.attr(settings.proxy || '')}"
    placeholder="host:port"
    class="w-full border rounded-xl px-4 py-3 mt-2"
/>
</div>

<label class="flex items-center gap-3">
<input
    id="setting_ssl_verify"
    type="checkbox"
    ${settings.ssl_verify ? 'checked' : ''}
    class="w-5 h-5"
/>
SSL証明書を検証する
</label>

<button
    type="submit"
    class="w-full py-3 rounded-xl bg-indigo-600 text-white font-bold"
>
設定を保存
</button>

</form>

</div>

<div class="bg-white rounded-2xl border p-6">

<h2 class="font-bold mb-5">
kintone項目マッピング
</h2>

<button
    onclick="App.actions.fetchKintoneFields()"
    class="w-full py-3 rounded-xl bg-slate-100 font-semibold mb-4"
>
項目一覧を再取得
</button>

<div
    id="field_message"
    class="text-sm text-slate-500 mb-4"
></div>

<div class="space-y-4">

<div>
<label class="text-xs font-bold text-slate-500">
会社名
</label>
${selectField(
    'field_company',
    settings.field_company || '',
    false
)}
</div>

<div>
<label class="text-xs font-bold text-slate-500">
氏名
</label>
${selectField(
    'field_name',
    settings.field_name || '',
    false
)}
</div>

<div>
<label class="text-xs font-bold text-slate-500">
メールアドレス
</label>
${selectField(
    'field_email',
    settings.field_email || '',
    false
)}
</div>

<div>
<label class="text-xs font-bold text-slate-500">
部署名
</label>
${selectField(
    'field_department',
    settings.field_department || '',
    false
)}
</div>

<div>
<label class="text-xs font-bold text-slate-500">
電話番号
</label>
${selectField(
    'field_phone',
    settings.field_phone || '',
    false
)}
</div>

<div>
<label class="text-xs font-bold text-slate-500">
住所
</label>
${selectField(
    'field_address',
    Array.isArray(settings.field_address)
        ? ''
        : settings.field_address || '',
    true
)}
</div>

</div>

<button
    onclick="App.actions.saveMapping()"
    class="w-full mt-5 py-3 rounded-xl bg-indigo-600 text-white font-bold"
>
マッピングを保存
</button>

</div>

</div>

`, 'キントーン連携設定');
    },

    preview: function() {

        const survey =
            App.State.survey;

        let number = 0;

        const groups =
            survey.groups.map(
                function(group) {

                    const questions =
                        group.questions.map(
                            function(question) {

                                number++;

                                return `
<div class="border rounded-2xl p-5 mb-4">
<div class="font-bold mb-4">
<span class="text-indigo-600 mr-2">
${
survey.numbering_mode === 'group'
    ? ''
    : 'Q' + number
}
</span>
${App.Util.esc(question.text)}
</div>

${
question.type === 'text'
? `
<textarea
    class="w-full border rounded-xl p-3"
    rows="4"
    placeholder="回答欄"
></textarea>
`
: `
<div class="space-y-3">
${question.options.map(
    function(option) {
        return `
<label class="flex gap-3">
<input
    type="${
        question.type === 'single'
            ? 'radio'
            : 'checkbox'
    }"
    name="preview_${App.Util.attr(question.id)}"
>
<span>
${App.Util.esc(option.text)}
</span>
</label>
`;
    }
).join('')}
</div>
`
}
</div>
`;
                            }
                        ).join('');

                    return `
<section class="mb-8">
<h3 class="text-lg font-bold border-b pb-2 mb-4">
${App.Util.esc(group.name)}
</h3>
${questions}
</section>
`;
                }
            ).join('');

        document.getElementById(
            'preview_content'
        ).innerHTML = `
<div class="${
    App.State.previewMobile
        ? 'max-w-sm'
        : 'max-w-3xl'
} mx-auto">

<div class="flex justify-end mb-5">
<button
    onclick="App.actions.togglePreviewDevice()"
    class="px-3 py-2 rounded-lg bg-slate-100 text-sm"
>
${
    App.State.previewMobile
        ? 'PC表示'
        : 'スマートフォン表示'
}
</button>
</div>

<h1 class="text-2xl font-bold mb-8">
${App.Util.esc(survey.title)}
</h1>

${groups}

<button
    onclick="alert('プレビューでは実際の送信は行われません。')"
    class="w-full py-4 rounded-xl bg-indigo-600 text-white font-bold"
>
回答を送信する
</button>

</div>
`;

        document.getElementById(
            'preview_modal'
        ).classList.remove('hidden');
    }
},

actions: {

    init: async function() {
        await App.API.load();

        App.State.selectedCustomers = [];

        App.actions.render();

        window.addEventListener(
            'beforeunload',
            function(event) {
                if (App.State.dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            }
        );
    },

    render: function() {

        let html = '';

        if (App.State.page === 'list') {
            html =
                App.Render.list();
        } else if (
            App.State.page === 'editor'
        ) {
            html =
                App.Render.editor();
        } else if (
            App.State.page === 'results'
        ) {
            html =
                App.Render.results();
        } else if (
            App.State.page === 'mail'
        ) {
            html =
                App.Render.mail();
        } else if (
            App.State.page === 'settings'
        ) {
            html =
                App.Render.settings();
        }

        document.getElementById(
            'app'
        ).innerHTML = html;

        if (
            App.State.page === 'editor'
        ) {
            App.actions.initSortables();
        }
    },

    goList: function() {
        if (App.State.dirty) {
            if (
                !confirm(
                    '未保存の変更があります。破棄して一覧へ戻りますか？'
                )
            ) {
                return;
            }
        }

        App.State.dirty = false;
        App.State.page = 'list';
        App.State.survey = null;
        App.actions.render();
    },

    goSettings: function() {
        if (App.State.dirty) {
            if (
                !confirm(
                    '未保存の変更があります。破棄しますか？'
                )
            ) {
                return;
            }
        }

        App.State.dirty = false;
        App.State.page = 'settings';
        App.actions.render();
    },

    createSurvey: function() {

        App.State.survey = {
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

        App.State.dirty = false;
        App.State.page = 'editor';

        App.actions.render();
    },

    editSurvey: function(id) {

        const survey =
            App.State.data.surveys.find(
                function(item) {
                    return String(item.id) ===
                        String(id);
                }
            );

        if (!survey) {
            alert(
                'アンケートが見つかりません。'
            );
            return;
        }

        App.State.survey =
            App.Util.clone(survey);

        App.State.dirty = false;
        App.State.page = 'editor';

        App.actions.render();
    },

    updateSurveyField: function(
        field,
        value
    ) {
        if (!App.State.survey) {
            return;
        }

        App.State.survey[field] =
            value;

        App.State.dirty = true;

        if (
            field === 'numbering_mode'
        ) {
            App.actions.render();
        }
    },

    addGroup: function() {

        App.State.survey.groups.push({
            id: App.Util.id('group'),
            name:
                'グループ' +
                (
                    App.State.survey.groups.length +
                    1
                ),
            questions: []
        });

        App.State.dirty = true;

        App.actions.render();
    },

    deleteGroup: function(groupId) {

        const group =
            App.State.survey.groups.find(
                function(item) {
                    return item.id === groupId;
                }
            );

        if (!group) {
            return;
        }

        if (
            !confirm(
                'このグループと内包する質問を削除しますか？'
            )
        ) {
            return;
        }

        const ids =
            group.questions.map(
                function(question) {
                    return question.id;
                }
            );

        App.State.survey.groups =
            App.State.survey.groups.filter(
                function(item) {
                    return item.id !== groupId;
                }
            );

        App.State.survey.groups.forEach(
            function(groupItem) {
                groupItem.questions.forEach(
                    function(question) {
                        question.branching =
                            (question.branching || [])
                                .filter(
                                    function(branch) {
                                        return !ids.includes(
                                            branch.target_question_id
                                        );
                                    }
                                );
                    }
                );
            }
        );

        App.State.dirty = true;

        App.actions.render();
    },

    updateGroupName: function(
        groupId,
        value
    ) {
        const group =
            App.State.survey.groups.find(
                function(item) {
                    return item.id === groupId;
                }
            );

        if (!group) {
            return;
        }

        group.name = value;
        App.State.dirty = true;
    },

    addQuestion: function(groupId) {

        const group =
            App.State.survey.groups.find(
                function(item) {
                    return item.id === groupId;
                }
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
                {
                    id: App.Util.id('option'),
                    text: '選択肢1'
                },
                {
                    id: App.Util.id('option'),
                    text: '選択肢2'
                }
            ],
            other_enabled: false,
            branching: []
        });

        App.State.dirty = true;

        App.actions.render();
    },

    deleteQuestion: function(
        questionId
    ) {

        if (
            !confirm(
                'この質問を削除しますか？'
            )
        ) {
            return;
        }

        App.State.survey.groups.forEach(
            function(group) {
                group.questions =
                    group.questions.filter(
                        function(question) {
                            return question.id !==
                                questionId;
                        }
                    );
            }
        );

        App.State.survey.groups.forEach(
            function(group) {
                group.questions.forEach(
                    function(question) {
                        question.branching =
                            (question.branching || [])
                                .filter(
                                    function(branch) {
                                        return branch.target_question_id !==
                                            questionId;
                                    }
                                );
                    }
                );
            }
        );

        App.State.dirty = true;

        App.actions.render();
    },

    updateQuestion: function(
        questionId,
        field,
        value
    ) {
        const question =
            App.Util.allQuestions(
                App.State.survey
            ).find(
                function(item) {
                    return item.id ===
                        questionId;
                }
            );

        if (!question) {
            return;
        }

        question[field] = value;

        if (field === 'type') {
            if (value !== 'single') {
                question.branching = [];
            }
        }

        App.State.dirty = true;

        App.actions.render();
    },

    addOption: function(questionId) {

        const question =
            App.Util.allQuestions(
                App.State.survey
            ).find(
                function(item) {
                    return item.id ===
                        questionId;
                }
            );

        if (!question) {
            return;
        }

        question.options.push({
            id: App.Util.id('option'),
            text:
                '選択肢' +
                (
                    question.options.length +
                    1
                )
        });

        App.State.dirty = true;

        App.actions.render();
    },

    updateOption: function(
        questionId,
        optionId,
        value
    ) {
        const question =
            App.Util.allQuestions(
                App.State.survey
            ).find(
                function(item) {
                    return item.id ===
                        questionId;
                }
            );

        if (!question) {
            return;
        }

        const option =
            question.options.find(
                function(item) {
                    return item.id ===
                        optionId;
                }
            );

        if (option) {
            option.text = value;
            App.State.dirty = true;
        }
    },

    deleteOption: function(
        questionId,
        optionId
    ) {

        const question =
            App.Util.allQuestions(
                App.State.survey
            ).find(
                function(item) {
                    return item.id ===
                        questionId;
                }
            );

        if (!question) {
            return;
        }

        question.options =
            question.options.filter(
                function(option) {
                    return option.id !==
                        optionId;
                }
            );

        question.branching =
            (question.branching || [])
                .filter(
                    function(branch) {
                        return branch.option !==
                            optionId;
                    }
                );

        App.State.dirty = true;

        App.actions.render();
    },

    updateBranching: function(
        questionId,
        optionId,
        targetId
    ) {

        const question =
            App.Util.allQuestions(
                App.State.survey
            ).find(
                function(item) {
                    return item.id ===
                        questionId;
                }
            );

        if (!question) {
            return;
        }

        if (targetId === questionId) {
            alert(
                '自分自身への分岐は設定できません。'
            );
            App.actions.render();
            return;
        }

        if (!Array.isArray(
            question.branching
        )) {
            question.branching = [];
        }

        question.branching =
            question.branching.filter(
                function(branch) {
                    return branch.option !==
                        optionId;
                }
            );

        if (targetId) {
            question.branching.push({
                option: optionId,
                target_question_id:
                    targetId
            });
        }

        App.State.dirty = true;

        App.actions.render();
    },

    initSortables: function() {

        const groupContainer =
            document.getElementById(
                'editor_groups'
            );

        if (groupContainer) {

            new Sortable(
                groupContainer,
                {
                    animation: 180,
                    handle: '.group-drag',
                    ghostClass:
                        'opacity-40',
                    onEnd: function(event) {

                        if (
                            event.oldIndex ===
                            event.newIndex
                        ) {
                            return;
                        }

                        const groups =
                            App.State.survey.groups;

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

                        App.State.dirty = true;

                        App.actions.render();
                    }
                }
            );
        }

        document
            .querySelectorAll(
                '.question-editor'
            )
            .forEach(
                function(container) {

                    new Sortable(
                        container,
                        {
                            group:
                                'survey-questions',
                            animation: 180,
                            handle:
                                '.question-drag',
                            ghostClass:
                                'opacity-40',
                            filter:
                                'button',
                            onEnd:
                                function(event) {

                                    if (
                                        event.oldIndex ===
                                        event.newIndex &&
                                        event.from ===
                                        event.to
                                    ) {
                                        return;
                                    }

                                    const fromGroup =
                                        App.State.survey.groups
                                            .find(
                                                function(group) {
                                                    return group.id ===
                                                        event.from.dataset.groupId;
                                                }
                                            );

                                    const toGroup =
                                        App.State.survey.groups
                                            .find(
                                                function(group) {
                                                    return group.id ===
                                                        event.to.dataset.groupId;
                                                }
                                            );

                                    if (
                                        !fromGroup ||
                                        !toGroup
                                    ) {
                                        return;
                                    }

                                    const question =
                                        fromGroup.questions
                                            .find(
                                                function(item) {
                                                    return item.id ===
                                                        event.item.dataset.questionId;
                                                }
                                            );

                                    if (!question) {
                                        return;
                                    }

                                    fromGroup.questions =
                                        fromGroup.questions
                                            .filter(
                                                function(item) {
                                                    return item.id !==
                                                        question.id;
                                                }
                                            );

                                    toGroup.questions.splice(
                                        event.newIndex,
                                        0,
                                        question
                                    );

                                    App.State.dirty = true;

                                    App.actions.render();
                                }
                        }
                    );
                }
            );
    },

    searchSurveys: function() {
        const input =
            document.getElementById(
                'survey_filter_keyword'
            );

        App.State.filters.keyword =
            input
                ? input.value
                : '';

        App.actions.render();
    },

    toggleStatusFilter: function(value) {
        App.State.filters.status_filter =
            value;

        App.actions.render();
    },

    changeSort: function(value) {
        App.State.filters.sort =
            value;

        App.actions.render();
    },

    duplicateSurvey: async function(id) {

        const source =
            App.State.data.surveys.find(
                function(item) {
                    return item.id === id;
                }
            );

        if (!source) {
            return;
        }

        const copy =
            App.Util.clone(source);

        copy.id =
            App.Util.id('survey');

        copy.title =
            source.title +
            '（複製）';

        copy.status = 'draft';
        copy.created_at = '';
        copy.updated_at = '';
        copy.deleted = false;

        copy.groups.forEach(
            function(group) {

                group.id =
                    App.Util.id('group');

                group.questions.forEach(
                    function(question) {

                        question.id =
                            App.Util.id('question');

                        question.options =
                            (question.options || [])
                                .map(
                                    function(option) {
                                        return {
                                            id:
                                                App.Util.id('option'),
                                            text:
                                                option.text
                                        };
                                    }
                                );

                        question.branching = [];
                    }
                );
            }
        );

        try {

            await App.API.call(
                'save_survey',
                {
                    survey_json:
                        JSON.stringify(copy)
                }
            );

            await App.API.load();

            alert(
                '下書きとして複製しました。'
            );

            App.actions.render();

        } catch (error) {
            alert(error.message);
        }
    },

    deleteSurvey: async function(id) {

        if (
            !confirm(
                'この下書きを削除しますか？'
            )
        ) {
            return;
        }

        try {

            await App.API.deleteSurvey(id);

            await App.API.load();

            App.actions.render();

        } catch (error) {
            alert(error.message);
        }
    },

    saveSurvey: async function() {

        try {

            await App.API.saveSurvey();

            alert(
                'アンケートを保存しました。'
            );

            App.State.page = 'list';
            App.State.survey = null;

            App.actions.render();

        } catch (error) {
            alert(error.message);
        }
    },

    cancelEdit: function() {
        App.actions.backFromEditor();
    },

    backFromEditor: function() {

        if (App.State.dirty) {
            if (
                !confirm(
                    '未保存の変更を破棄して一覧へ戻りますか？'
                )
            ) {
                return;
            }
        }

        App.State.dirty = false;
        App.State.page = 'list';
        App.State.survey = null;

        App.actions.render();
    },

    preview: function() {
        App.Render.preview();
    },

    closePreview: function() {
        document
            .getElementById('preview_modal')
            .classList.add('hidden');
    },

    togglePreviewDevice: function() {
        App.State.previewMobile =
            !App.State.previewMobile;

        App.Render.preview();
    },

    goResults: function(id) {

        const survey =
            App.State.data.surveys.find(
                function(item) {
                    return item.id === id;
                }
            );

        if (!survey) {
            return;
        }

        App.State.survey =
            App.Util.clone(survey);

        App.State.page = 'results';
        App.State.responseFilter = '';
        App.State.selectedQuestionIds = [];

        App.actions.render();
    },

    filterResponses: function(value) {
        App.State.responseFilter =
            value;

        App.actions.render();
    },

    toggleQuestion: function(
        questionId,
        checked
    ) {

        if (!App.State.selectedQuestionIds.length) {
            App.State.selectedQuestionIds =
                App.Util.allQuestions(
                    App.State.survey
                ).map(
                    function(q) {
                        return q.id;
                    }
                );
        }

        if (checked) {
            if (
                !App.State.selectedQuestionIds.includes(
                    questionId
                )
            ) {
                App.State.selectedQuestionIds.push(
                    questionId
                );
            }
        } else {
            App.State.selectedQuestionIds =
                App.State.selectedQuestionIds.filter(
                    function(id) {
                        return id !== questionId;
                    }
                );
        }

        App.actions.render();
    },

    selectAllQuestions: function() {
        App.State.selectedQuestionIds =
            App.Util.allQuestions(
                App.State.survey
            ).map(
                function(q) {
                    return q.id;
                }
            );

        App.actions.render();
    },

    clearQuestionSelection: function() {
        App.State.selectedQuestionIds =
            [];

        App.actions.render();
    },

    showResponse: function(id) {

        const response =
            App.State.data.responses.find(
                function(item) {
                    return item.id === id;
                }
            );

        if (!response) {
            return;
        }

        const questions =
            App.Util.allQuestions(
                App.State.survey
            );

        document.getElementById(
            'response_detail'
        ).innerHTML = `

<div class="grid md:grid-cols-3 gap-4 mb-6">
<div class="bg-slate-50 rounded-xl p-4">
<div class="text-xs text-slate-400">
会社名
</div>
<div class="font-bold">
${App.Util.esc(
    response.company || ''
)}
</div>
</div>

<div class="bg-slate-50 rounded-xl p-4">
<div class="text-xs text-slate-400">
氏名
</div>
<div class="font-bold">
${App.Util.esc(
    response.name || ''
)}
</div>
</div>

<div class="bg-slate-50 rounded-xl p-4">
<div class="text-xs text-slate-400">
回答日時
</div>
<div class="font-bold">
${App.Util.esc(
    response.answered_at || ''
)}
</div>
</div>
</div>

<div class="space-y-4">
${questions.map(
    function(question, index) {

        let answer =
            response.answers?.[
                question.id
            ] ?? '';

        if (Array.isArray(answer)) {
            answer =
                answer.join('、');
        }

        return `
<div class="border rounded-xl p-4">
<div class="text-xs text-slate-400 mb-1">
Q${index + 1}
</div>
<div class="font-semibold mb-2">
${App.Util.esc(question.text)}
</div>
<div>
${App.Util.esc(String(answer))}
</div>
</div>
`;
    }
).join('')}
</div>
`;

        document.getElementById(
            'response_modal'
        ).classList.remove('hidden');
    },

    closeResponseModal: function() {
        document
            .getElementById(
                'response_modal'
            )
            .classList.add('hidden');
    },

    goMail: function(id) {

        const survey =
            App.State.data.surveys.find(
                function(item) {
                    return item.id === id;
                }
            );

        if (!survey) {
            return;
        }

        App.State.survey =
            App.Util.clone(survey);

        App.State.page = 'mail';
        App.State.customerFilter = '';
        App.State.selectedCustomers =
            [];

        App.actions.render();
    },

    filterCustomers: function(value) {
        App.State.customerFilter =
            value;

        App.actions.render();
    },

    toggleCustomer: function(
        id,
        checked
    ) {

        if (
            !Array.isArray(
                App.State.selectedCustomers
            )
        ) {
            App.State.selectedCustomers =
                [];
        }

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
                        function(item) {
                            return item !== id;
                        }
                    );
        }
    },

    selectAllCustomers: function(
        checked
    ) {

        if (!checked) {
            App.State.selectedCustomers =
                [];
        } else {

            App.State.selectedCustomers =
                App.State.data.customers
                    .filter(
                        function(customer) {
                            return customer.source !==
                                'web';
                        }
                    )
                    .map(
                        function(customer) {
                            return customer.id;
                        }
                    );
        }

        App.actions.render();
    },

    registerCustomer: async function(
        id
    ) {

        try {

            await App.API.registerCustomer(id);

            await App.API.load();

            App.actions.render();

        } catch (error) {
            alert(error.message);
        }
    },

    changeTemplate: function(value) {

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

        if (value === 'reminder') {
            subject.value =
                '【再送】アンケートご回答のお願い';

            body.value =
                '${顧客名} 様\n\n' +
                '先日ご案内したアンケートが未回答となっております。\n\n' +
                '回答URL：\n' +
                '${アンケートURL}\n\n' +
                'ご協力をお願いいたします。';
        } else {
            subject.value =
                '【アンケート】ご回答をお願いします';

            body.value =
                '${顧客名} 様\n\n' +
                'アンケートへのご協力をお願いいたします。\n\n' +
                '回答URL：\n' +
                '${アンケートURL}\n\n' +
                'よろしくお願いいたします。';
        }
    },

    sendMail: async function(
        surveyId
    ) {

        const ids =
            App.State.selectedCustomers ||
            [];

        if (!ids.length) {
            alert(
                '送信対象者を選択してください。'
            );
            return;
        }

        const alreadySent =
            App.State.data.customers.filter(
                function(customer) {
                    return ids.includes(
                        customer.id
                    ) &&
                    Number(
                        customer.send_count || 0
                    ) > 0;
                }
            );

        if (
            alreadySent.length &&
            !confirm(
                '既に送信済みの宛先が含まれています。再送しますか？'
            )
        ) {
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

        const type =
            document.getElementById(
                'template_type'
            ).value;

        try {

            const result =
                await App.API.sendMail({
                    survey_id: surveyId,
                    recipient_ids:
                        JSON.stringify(ids),
                    mail_subject: subject,
                    mail_body: body,
                    template_type: type
                });

            alert(
                result.count +
                '件の送信処理を記録しました。'
            );

            await App.API.load();

            App.State.page = 'list';
            App.State.survey = null;

            App.actions.render();

        } catch (error) {
            alert(error.message);
        }
    },

    saveSettings: async function(
        event
    ) {

        event.preventDefault();

        const current =
            App.State.data.settings;

        const settings = {
            ...current,
            subdomain:
                document.getElementById(
                    'setting_subdomain'
                ).value.trim(),
            app_id:
                document.getElementById(
                    'setting_app_id'
                ).value.trim(),
            login_name:
                document.getElementById(
                    'setting_login_name'
                ).value.trim(),
            password:
                document.getElementById(
                    'setting_password'
                ).value,
            proxy:
                document.getElementById(
                    'setting_proxy'
                ).value.trim(),
            ssl_verify:
                document.getElementById(
                    'setting_ssl_verify'
                ).checked
        };

        try {

            const result =
                await App.API.saveSettings(
                    settings
                );

            App.State.data.settings =
                result.settings;

            alert(
                '設定を保存しました。'
            );

            App.actions.render();

        } catch (error) {
            alert(error.message);
        }
    },

    fetchKintoneFields: async function() {

        const appId =
            document.getElementById(
                'setting_app_id'
            ).value.trim();

        const message =
            document.getElementById(
                'field_message'
            );

        if (!appId) {
            message.textContent =
                'アプリIDを入力してください。';
            return;
        }

        message.textContent =
            '項目一覧を取得しています…';

        try {

            const result =
                await App.API.fetchKintoneFields(
                    appId
                );

            App.State.settingsFields =
                result.fields || [];

            message.textContent =
                App.State.settingsFields.length +
                '項目を取得しました。';

            App.actions.render();

        } catch (error) {
            message.textContent =
                error.message;
        }
    },

    saveMapping: async function() {

        const current =
            App.State.data.settings;

        const address =
            Array.from(
                document.getElementById(
                    'field_address'
                ).selectedOptions
            ).map(
                function(option) {
                    return option.value;
                }
            )
            .filter(Boolean);

        const settings = {
            ...current,
            field_company:
                document.getElementById(
                    'field_company'
                ).value,
            field_name:
                document.getElementById(
                    'field_name'
                ).value,
            field_email:
                document.getElementById(
                    'field_email'
                ).value,
            field_department:
                document.getElementById(
                    'field_department'
                ).value,
            field_phone:
                document.getElementById(
                    'field_phone'
                ).value,
            field_address:
                address
        };

        try {

            const result =
                await App.API.saveSettings(
                    settings
                );

            App.State.data.settings =
                result.settings;

            alert(
                'マッピングを保存しました。'
            );

        } catch (error) {
            alert(error.message);
        }
    },

    logout: function() {
        alert(
            'デモ環境ではログアウト処理を省略しています。'
        );
    }
}

};

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.actions.init();
        },
        { once: true }
    );
} else {
    App.actions.init();
}
</script>

</body>
</html>
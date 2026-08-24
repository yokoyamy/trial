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

重要な内部実装名称:
- survey_storage_directory
- survey_storage_file
- survey_admin_session_v1
- App
- App.State
- App.API
- App.Render
- App.actions
- App.Util
- fetchKintoneFields
- addGroup
- addQuestion
========================================================================
*/

declare(strict_types=1);

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

header('Content-Type: text/html; charset=UTF-8');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* ================================================================
 * PHP utility
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
        is_array($data['settings']) ? $data['settings'] : []
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
        return $prefix . '_' . bin2hex(random_bytes(12));
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

    if (
        $token === '' ||
        !hash_equals(survey_csrf(), $token)
    ) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

/*
 * kintone接続先を正規化する。
 *
 * 許容:
 *   xxxx
 *   xxxx.cybozu.com
 *   https://xxxx.cybozu.com
 *   https://xxxx.cybozu.com/
 *
 * 重要:
 * 旧実装ではFQDNに対して
 *   xxxx.cybozu.com.cybozu.com
 * となる可能性があったため、この関数では必ずFQDNを完成形にする。
 */
function survey_normalize_kintone_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('#^\s*https?://#i', '', $value);
    $value = preg_replace('#[/?#].*$#', '', $value);
    $value = trim((string)$value, ". \t\r\n");

    if ($value === '') {
        return '';
    }

    if (
        str_ends_with(
            strtolower($value),
            '.cybozu.com'
        )
    ) {
        return $value;
    }

    return $value . '.cybozu.com';
}

function survey_header_status(): int
{
    if (
        function_exists('http_get_last_response_headers')
    ) {
        $headers = http_get_last_response_headers();

        if (is_array($headers)) {
            foreach ($headers as $header) {
                if (
                    preg_match(
                        '/^HTTP\/[\d.]+\s+(\d+)/i',
                        (string)$header,
                        $match
                    )
                ) {
                    return (int)$match[1];
                }
            }
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
    $host = survey_normalize_kintone_host(
        (string)($settings['subdomain'] ?? '')
    );

    if ($host === '') {
        return [
            'ok' => false,
            'message' => 'kintoneのサブドメインを入力してください。'
        ];
    }

    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    $url = 'https://' . $host . $path;

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'message' => 'kintoneのログイン名とパスワードを入力してください。'
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
        $encodedBody = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($encodedBody === false) {
            return [
                'ok' => false,
                'message' => 'JSON生成に失敗しました。'
            ];
        }

        $options['http']['content'] = $encodedBody;
    }

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        if (
            !preg_match(
                '/^[a-zA-Z0-9.\-]+:\d{1,5}$/',
                $proxy
            )
        ) {
            return [
                'ok' => false,
                'message' => 'Proxyサーバは host名:port番号 の形式で入力してください。'
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

    $status = survey_header_status();

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
            'data' => $decoded
        ];
    }

    $message = (string)(
        $decoded['message']
        ?? 'kintone API通信に失敗しました。'
    );

    return [
        'ok' => false,
        'status' => $status,
        'message' => $message,
        'data' => $decoded
    ];
}

function survey_public_url(
    string $surveyId,
    string $customerId
): string {
    $https =
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off';

    $scheme = $https ? 'https' : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    $path = strtok(
        (string)($_SERVER['REQUEST_URI'] ?? '/'),
        '?'
    );

    return $scheme .
        '://' .
        $host .
        $path .
        '?respond=' .
        rawurlencode($surveyId) .
        '&customer_id=' .
        rawurlencode($customerId);
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
            $surveyJson = (string)(
                $_POST['survey_json'] ?? ''
            );

            $survey = json_decode(
                $surveyJson,
                true
            );

            if (
                !is_array($survey) ||
                empty($survey['id'])
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $survey['id'] = (string)$survey['id'];
            $survey['title'] = trim(
                (string)($survey['title'] ?? '')
            );

            $survey['status'] = in_array(
                $survey['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            )
                ? $survey['status']
                : 'draft';

            $survey['numbering_mode'] = in_array(
                $survey['numbering_mode'] ?? 'global',
                ['global', 'group'],
                true
            )
                ? $survey['numbering_mode']
                : 'global';

            $survey['groups'] =
                is_array($survey['groups'] ?? null)
                    ? $survey['groups']
                    : [];

            foreach ($survey['groups'] as &$group) {
                $group['id'] = (string)(
                    $group['id'] ?? survey_id('group')
                );

                $group['name'] = (string)(
                    $group['name'] ?? 'グループ'
                );

                $group['questions'] =
                    is_array($group['questions'] ?? null)
                        ? $group['questions']
                        : [];

                foreach ($group['questions'] as &$question) {
                    $question['id'] = (string)(
                        $question['id'] ??
                        survey_id('question')
                    );

                    $question['text'] = (string)(
                        $question['text'] ?? ''
                    );

                    $question['type'] = in_array(
                        $question['type'] ?? 'single',
                        ['single', 'multiple', 'text'],
                        true
                    )
                        ? $question['type']
                        : 'single';

                    $question['required'] =
                        !empty($question['required']);

                    $question['options'] =
                        is_array($question['options'] ?? null)
                            ? array_values(
                                array_map(
                                    static fn($v) => (string)$v,
                                    $question['options']
                                )
                            )
                            : [];

                    $question['other_enabled'] =
                        !empty($question['other_enabled']);
                }

                unset($question);
            }

            unset($group);

            $now = survey_now();
            $found = false;

            foreach ($data['surveys'] as $index => $existing) {
                if (
                    (string)($existing['id'] ?? '') ===
                    $survey['id']
                ) {
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
            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $status = (string)(
                $_POST['status'] ?? ''
            );

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
            $settingsJson = (string)(
                $_POST['settings_json'] ?? ''
            );

            $settings = json_decode(
                $settingsJson,
                true
            );

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            $oldPassword = (string)(
                $data['settings']['password'] ?? ''
            );

            if (
                empty($settings['password']) &&
                $oldPassword !== ''
            ) {
                $settings['password'] = $oldPassword;
            }

            $settings['subdomain'] = trim(
                (string)($settings['subdomain'] ?? '')
            );

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
                'ok' => true
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

            if ($appId === '') {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アプリIDを入力してください。'
                ], 400);
            }

            $settings['app_id'] = $appId;

            /*
             * ここが重要。
             *
             * subdomainが
             *   xxxx
             *   xxxx.cybozu.com
             *   https://xxxx.cybozu.com
             * のいずれでも正しく接続する。
             */
            $result = survey_kintone_request(
                'GET',
                '/k/v1/app/form/fields.json?app=' .
                    rawurlencode($appId),
                $settings
            );

            if (!$result['ok']) {
                $message = $result['message'] ?? '';

                if (
                    str_contains(
                        $message,
                        'サブドメイン'
                    )
                ) {
                    $message .=
                        "\n入力値: " .
                        (string)($settings['subdomain'] ?? '') .
                        "\n接続先: " .
                        survey_normalize_kintone_host(
                            (string)($settings['subdomain'] ?? '')
                        );
                }

                survey_json_response([
                    'ok' => false,
                    'message' => $message,
                    'status' => $result['status'] ?? 0
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

            usort(
                $fields,
                static function(array $a, array $b): int {
                    return strcmp(
                        $a['label'],
                        $b['label']
                    );
                }
            );

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
                trim($body) === ''
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
                        (string)($customer['id'] ?? ''),
                        $recipientIds,
                        true
                    )
                ) {
                    continue;
                }

                if (
                    ($customer['source'] ?? 'kintone') ===
                    'web'
                ) {
                    continue;
                }

                $email = trim(
                    (string)($customer['email'] ?? '')
                );

                if ($email === '') {
                    continue;
                }

                $url = survey_public_url(
                    $surveyId,
                    (string)$customer['id']
                );

                $finalSubject = str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}'
                    ],
                    [
                        (string)($customer['name'] ?? ''),
                        $url
                    ],
                    $subject
                );

                $finalBody = str_replace(
                    [
                        '{顧客名}',
                        '{アンケートURL}'
                    ],
                    [
                        (string)($customer['name'] ?? ''),
                        $url
                    ],
                    $body
                );

                $mailResult = @mail(
                    $email,
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
                    'sent' => $mailResult
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

            if (!$survey) {
                http_response_code(404);
                exit('Survey not found');
            }

            $questions = [];

            foreach (
                ($survey['groups'] ?? [])
                as $group
            ) {
                foreach (
                    ($group['questions'] ?? [])
                    as $question
                ) {
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

                $answers =
                    is_array($response['answers'] ?? null)
                        ? $response['answers']
                        : [];

                foreach ($questions as $question) {
                    $answer =
                        $answers[$question['id']] ?? '';

                    if (is_array($answer)) {
                        $answer =
                            implode('、', $answer);
                    }

                    $row[] = $answer;
                }

                fputcsv(
                    $fp,
                    $row
                );
            }

            rewind($fp);

            $csv = stream_get_contents($fp);

            fclose($fp);

            header_remove('Content-Type');
            header(
                'Content-Type: text/csv; charset=UTF-8'
            );

            header(
                'Content-Disposition: attachment; filename="survey_' .
                rawurlencode($surveyId) .
                '.csv"'
            );

            echo "\xEF\xBB\xBF" . $csv;
            exit;

        default:
            survey_json_response([
                'ok' => false,
                'message' => 'Unknown action.'
            ], 400);
    }
}

/* ================================================================
 * 公開回答画面
 * ================================================================ */

$publicSurveyId = (string)(
    $_GET['respond'] ?? ''
);

if ($publicSurveyId !== '') {
    $data = survey_load_data();
    $publicSurvey = null;

    foreach ($data['surveys'] as $item) {
        if (
            (string)($item['id'] ?? '') ===
            $publicSurveyId &&
            empty($item['deleted'])
        ) {
            $publicSurvey = $item;
            break;
        }
    }

    if (
        !$publicSurvey ||
        ($publicSurvey['status'] ?? '') !== 'active'
    ) {
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>アンケート</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-100 text-slate-800">
        <div class="max-w-3xl mx-auto p-6">
            <div class="bg-white rounded-2xl border p-10 text-center">
                <h1 class="text-xl font-bold">アンケート</h1>
                <p class="mt-4 text-slate-500">
                    このアンケートは現在回答できません。
                </p>
            </div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    $customerId = (string)(
        $_GET['customer_id'] ?? ''
    );

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= htmlspecialchars(
            (string)$publicSurvey['title'],
            ENT_QUOTES,
            'UTF-8'
        ) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-100 text-slate-800">

    <div id="public_app" class="max-w-3xl mx-auto p-5 md:p-8">
        <div class="bg-white border rounded-2xl shadow-sm p-6 md:p-8">

            <h1 class="text-2xl font-bold">
                <?= htmlspecialchars(
                    (string)$publicSurvey['title'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </h1>

            <form id="public_form" class="mt-8 space-y-8">

            <?php
            foreach (
                ($publicSurvey['groups'] ?? [])
                as $group
            ):
            ?>

                <section class="space-y-5">
                    <h2 class="text-lg font-bold border-b pb-2">
                        <?= htmlspecialchars(
                            (string)($group['name'] ?? ''),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </h2>

                    <?php
                    foreach (
                        ($group['questions'] ?? [])
                        as $question
                    ):
                    ?>

                        <div class="space-y-3">

                            <label class="block font-semibold">
                                <?= htmlspecialchars(
                                    (string)($question['text'] ?? ''),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                                <?php if (!empty($question['required'])): ?>
                                    <span class="text-red-500">*</span>
                                <?php endif; ?>
                            </label>

                            <?php if (
                                ($question['type'] ?? '') ===
                                'single'
                            ): ?>

                                <div class="space-y-2">
                                <?php foreach (
                                    ($question['options'] ?? [])
                                    as $option
                                ): ?>
                                    <label class="flex gap-2 items-center">
                                        <input
                                            type="radio"
                                            name="q_<?= htmlspecialchars(
                                                (string)$question['id'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            value="<?= htmlspecialchars(
                                                (string)$option,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            <?= !empty($question['required'])
                                                ? 'required'
                                                : '' ?>
                                        >
                                        <span>
                                            <?= htmlspecialchars(
                                                (string)$option,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                                </div>

                            <?php elseif (
                                ($question['type'] ?? '') ===
                                'multiple'
                            ): ?>

                                <div class="space-y-2">
                                <?php foreach (
                                    ($question['options'] ?? [])
                                    as $option
                                ): ?>
                                    <label class="flex gap-2 items-center">
                                        <input
                                            type="checkbox"
                                            name="q_<?= htmlspecialchars(
                                                (string)$question['id'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>[]"
                                            value="<?= htmlspecialchars(
                                                (string)$option,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >
                                        <span>
                                            <?= htmlspecialchars(
                                                (string)$option,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                                </div>

                            <?php else: ?>

                                <textarea
                                    name="q_<?= htmlspecialchars(
                                        (string)$question['id'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    rows="5"
                                    class="w-full border rounded-xl p-3"
                                    <?= !empty($question['required'])
                                        ? 'required'
                                        : '' ?>
                                ></textarea>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                </section>

            <?php endforeach; ?>

            <?php if ($customerId === ''): ?>
                <div class="grid md:grid-cols-3 gap-3 border-t pt-6">
                    <input
                        id="public_company"
                        placeholder="会社名"
                        class="border rounded-xl p-3"
                    >
                    <input
                        id="public_name"
                        placeholder="氏名"
                        class="border rounded-xl p-3"
                    >
                    <input
                        id="public_email"
                        type="email"
                        placeholder="メールアドレス"
                        class="border rounded-xl p-3"
                    >
                </div>
            <?php endif; ?>

            <input
                type="hidden"
                id="public_csrf"
                value="<?= htmlspecialchars(
                    survey_csrf(),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <button
                type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl py-3 font-semibold"
            >
                回答を送信する
            </button>

            </form>
        </div>
    </div>

    <script>
    window.PublicSurvey = {
        submit: async function(event) {
            event.preventDefault();

            const form =
                document.getElementById('public_form');

            if (!form.reportValidity()) {
                return;
            }

            const fd = new FormData(form);
            const answers = {};

            <?php
            foreach (
                ($publicSurvey['groups'] ?? [])
                as $group
            ):
                foreach (
                    ($group['questions'] ?? [])
                    as $question
                ):
            ?>
            {
                const name =
                    'q_<?= htmlspecialchars(
                        (string)$question['id'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>';

                const values = fd.getAll(name);

                answers[
                    '<?= htmlspecialchars(
                        (string)$question['id'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>'
                ] =
                    values.length > 1
                        ? values
                        : (values[0] || '');
            }
            <?php
                endforeach;
            endforeach;
            ?>

            const body = new URLSearchParams();

            body.set('action', 'public_answer');
            body.set(
                'csrf_token',
                document.getElementById(
                    'public_csrf'
                ).value
            );

            body.set(
                'survey_id',
                <?= json_encode(
                    $publicSurveyId,
                    JSON_UNESCAPED_UNICODE
                ) ?>
            );

            body.set(
                'customer_id',
                <?= json_encode(
                    $customerId,
                    JSON_UNESCAPED_UNICODE
                ) ?>
            );

            body.set(
                'answers',
                JSON.stringify(answers)
            );

            const company =
                document.getElementById(
                    'public_company'
                );

            const person =
                document.getElementById(
                    'public_name'
                );

            const email =
                document.getElementById(
                    'public_email'
                );

            if (company) {
                body.set('company', company.value);
            }

            if (person) {
                body.set('name', person.value);
            }

            if (email) {
                body.set('email', email.value);
            }

            try {
                const response = await fetch(
                    location.pathname,
                    {
                        method: 'POST',
                        body: body
                    }
                );

                const result =
                    await response.json();

                if (!result.ok) {
                    alert(
                        result.message ||
                        '送信に失敗しました。'
                    );
                    return;
                }

                document.getElementById(
                    'public_app'
                ).innerHTML = `
                    <div class="bg-white border rounded-2xl p-10 text-center">
                        <div class="text-emerald-600 text-4xl">✓</div>
                        <h1 class="text-2xl font-bold mt-5">
                            回答ありがとうございました
                        </h1>
                        <p class="text-slate-500 mt-3">
                            回答を正常に受け付けました。
                        </p>
                    </div>
                `;
            } catch (error) {
                alert(
                    '通信エラーが発生しました。'
                );
            }
        }
    };

    document.getElementById(
        'public_form'
    ).addEventListener(
        'submit',
        window.PublicSurvey.submit
    );
    </script>

    </body>
    </html>
    <?php
    exit;
}

/* ================================================================
 * 管理画面
 * ================================================================ */
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

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {
    State: {
        initialized: false,

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

        status_filter: '',

        sort: 'updated_desc',

        customerFilter: '',

        responseFilter: '',

        selectedCustomers: [],

        selectedQuestions: [],

        previewMobile: false,

        kintoneFields: []
    },

    API: {},

    Render: {},

    actions: {},

    Util: {}
};

/* ================================================================
 * Util
 * ================================================================ */

App.Util.escape = function(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

App.Util.escapeAttr = App.Util.escape;

App.Util.id = function(prefix) {
    if (
        window.crypto &&
        typeof window.crypto.randomUUID ===
            'function'
    ) {
        return prefix + '_' +
            window.crypto.randomUUID()
                .replace(/-/g, '');
    }

    return prefix + '_' +
        Date.now() +
        Math.random()
            .toString(16)
            .slice(2);
};

App.Util.findSurvey = function(id) {
    return App.State.data.surveys.find(
        survey =>
            String(survey.id) ===
            String(id)
    );
};

App.Util.allQuestions = function(survey) {
    const result = [];

    (survey?.groups || []).forEach(
        group => {
            (group.questions || []).forEach(
                question => {
                    result.push(question);
                }
            );
        }
    );

    return result;
};

App.Util.findQuestion = function(id) {
    const survey =
        App.State.editSurvey;

    if (!survey) {
        return null;
    }

    for (
        const group of
        survey.groups || []
    ) {
        const question =
            (group.questions || [])
                .find(
                    item =>
                        String(item.id) ===
                        String(id)
                );

        if (question) {
            return question;
        }
    }

    return null;
};

App.Util.questionNumber = function(
    survey,
    groupIndex,
    questionIndex
) {
    if (
        survey.numbering_mode ===
        'group'
    ) {
        return 'Q' +
            (groupIndex + 1) +
            '-' +
            (questionIndex + 1);
    }

    let number = 0;

    for (
        let i = 0;
        i < groupIndex;
        i++
    ) {
        number +=
            (
                survey.groups[i]
                    .questions || []
            ).length;
    }

    return 'Q' +
        (number + questionIndex + 1);
};

App.Util.statusText = function(status) {
    return {
        active: '公開中',
        draft: '下書き',
        ended: '終了'
    }[status] || status;
};

App.Util.statusClass = function(status) {
    return {
        active:
            'bg-emerald-100 text-emerald-700',
        draft:
            'bg-slate-100 text-slate-600',
        ended:
            'bg-amber-100 text-amber-700'
    }[status] ||
        'bg-slate-100 text-slate-600';
};

App.Util.typeText = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || type;
};

/* ================================================================
 * API
 * ================================================================ */

App.API.request = async function(
    action,
    params = {}
) {
    const body =
        new URLSearchParams();

    body.set(
        'action',
        action
    );

    body.set(
        'csrf_token',
        App.State.csrf_token
    );

    Object.entries(params)
        .forEach(
            ([key, value]) => {
                if (
                    typeof value ===
                    'object'
                ) {
                    body.set(
                        key,
                        JSON.stringify(value)
                    );
                } else {
                    body.set(
                        key,
                        String(value)
                    );
                }
            }
        );

    const response =
        await fetch(
            location.pathname,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body
            }
        );

    const result =
        await response.json();

    if (!result.ok) {
        throw new Error(
            result.message ||
            '処理に失敗しました。'
        );
    }

    return result;
};

App.API.load = async function() {
    const response =
        await fetch(
            location.pathname +
            '?action=load',
            {
                credentials:
                    'same-origin'
            }
        );

    const result =
        await response.json();

    if (!result.ok) {
        throw new Error(
            result.message ||
            '読み込みに失敗しました。'
        );
    }

    App.State.data =
        result.data;

    App.State.csrf_token =
        result.csrf_token;
};

App.API.saveSurvey =
    async function(survey) {
        return App.API.request(
            'save_survey',
            {
                survey_json: survey
            }
        );
    };

App.API.saveSettings =
    async function(settings) {
        return App.API.request(
            'save_settings',
            {
                settings_json:
                    settings
            }
        );
    };

/* ================================================================
 * navigation
 * ================================================================ */

App.actions.goSurveys =
    function() {
        App.State.page =
            'surveys';

        App.State.surveyId =
            null;

        App.State.editSurvey =
            null;

        App.State.dirty =
            false;

        App.Render.main();
    };

App.actions.goSettings =
    function() {
        App.State.page =
            'settings';

        App.Render.main();
    };

App.actions.newSurvey =
    function() {

        /*
         * 新規作成時点で必ず空のグループを
         * 1つ作成する。
         *
         * さらに question_editor が存在する状態で
         * addQuestion が呼ばれても正常に動作する。
         */
        App.State.editSurvey = {
            id:
                App.Util.id(
                    'survey'
                ),

            title:
                '新しいアンケート',

            start_at: '',

            end_at: '',

            status:
                'draft',

            created_at: '',

            updated_at: '',

            numbering_mode:
                'global',

            groups: [
                {
                    id:
                        App.Util.id(
                            'group'
                        ),

                    name:
                        'グループ1',

                    questions: []
                }
            ],

            deleted: false
        };

        App.State.surveyId =
            App.State.editSurvey.id;

        App.State.page =
            'edit';

        App.State.dirty =
            false;

        App.Render.main();
    };

App.actions.editSurvey =
    function(id) {
        const survey =
            App.Util.findSurvey(id);

        if (!survey) {
            return;
        }

        App.State.editSurvey =
            JSON.parse(
                JSON.stringify(survey)
            );

        /*
         * 古いJSONや壊れたデータでも
         * 編集画面が落ちないよう補正。
         */
        if (
            !Array.isArray(
                App.State.editSurvey.groups
            ) ||
            App.State.editSurvey.groups.length === 0
        ) {
            App.State.editSurvey.groups = [
                {
                    id:
                        App.Util.id(
                            'group'
                        ),
                    name:
                        'グループ1',
                    questions: []
                }
            ];
        }

        App.State.editSurvey.groups
            .forEach(group => {
                if (
                    !Array.isArray(
                        group.questions
                    )
                ) {
                    group.questions = [];
                }
            });

        App.State.surveyId =
            id;

        App.State.page =
            'edit';

        App.State.dirty =
            false;

        App.Render.main();
    };

App.actions.cloneSurvey =
    async function(id) {
        const survey =
            App.Util.findSurvey(id);

        if (!survey) {
            return;
        }

        const copy =
            JSON.parse(
                JSON.stringify(
                    survey
                )
            );

        copy.id =
            App.Util.id('survey');

        copy.title =
            String(copy.title || '') +
            '（複製）';

        copy.status =
            'draft';

        copy.created_at = '';
        copy.updated_at = '';
        copy.deleted = false;

        (copy.groups || [])
            .forEach(group => {
                group.id =
                    App.Util.id(
                        'group'
                    );

                (group.questions || [])
                    .forEach(question => {
                        question.id =
                            App.Util.id(
                                'question'
                            );
                    });
            });

        try {
            await App.API.saveSurvey(
                copy
            );

            await App.API.load();

            App.Render.surveys();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.changeStatus =
    async function(
        id,
        status
    ) {
        const text =
            status === 'active'
                ? '公開'
                : '停止';

        if (
            !confirm(
                'アンケートを' +
                text +
                'しますか？'
            )
        ) {
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

App.actions.deleteSurvey =
    async function(id) {
        if (
            !confirm(
                'このアンケートを削除しますか？'
            )
        ) {
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

/* ================================================================
 * editor
 * ================================================================ */

App.actions.markDirty =
    function() {
        App.State.dirty =
            true;
    };

App.actions.syncEditor =
    function() {
        const survey =
            App.State.editSurvey;

        if (!survey) {
            return;
        }

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

        const numbering =
            document.getElementById(
                'survey_numbering_mode'
            );

        if (title) {
            survey.title =
                title.value;
        }

        if (start) {
            survey.start_at =
                start.value;
        }

        if (end) {
            survey.end_at =
                end.value;
        }

        if (numbering) {
            survey.numbering_mode =
                numbering.value;
        }

        App.actions
            .collectQuestionsFromDOM();
    };

/*
 * ★重要修正
 *
 * 旧実装では
 *
 *   group.questions.sort(...)
 *
 * だけを行っていたため、
 * SortableJSで質問を別グループへ移動しても
 * question object自体は元グループに残っていた。
 *
 * 新実装ではDOM上の質問IDを基準に
 * 全質問を一度フラット化し、
 * DOM上の所属グループへ再配置する。
 *
 * これにより、
 *
 * - 同一グループ内移動
 * - グループ間移動
 * - グループ並び替え
 *
 * を正しくStateへ反映する。
 */
App.actions.collectQuestionsFromDOM =
    function() {

        const survey =
            App.State.editSurvey;

        if (!survey) {
            return;
        }

        const editor =
            document.getElementById(
                'question_editor'
            );

        if (!editor) {
            return;
        }

        const originalQuestions =
            [];

        (
            survey.groups || []
        ).forEach(group => {
            (
                group.questions || []
            ).forEach(question => {
                originalQuestions.push(
                    question
                );
            });
        });

        const questionMap =
            new Map();

        originalQuestions
            .forEach(question => {
                questionMap.set(
                    String(question.id),
                    question
                );
            });

        const newGroups =
            [];

        editor.querySelectorAll(
            '[data-group-id]'
        ).forEach(
            groupElement => {

                const groupId =
                    String(
                        groupElement.dataset.groupId
                    );

                const originalGroup =
                    survey.groups.find(
                        group =>
                            String(group.id) ===
                            groupId
                    );

                if (!originalGroup) {
                    return;
                }

                const newGroup = {
                    id:
                        originalGroup.id,

                    name:
                        originalGroup.name,

                    questions: []
                };

                const questionElements =
                    groupElement
                        .querySelectorAll(
                            '[data-question-id]'
                        );

                questionElements
                    .forEach(
                        questionElement => {

                            const questionId =
                                String(
                                    questionElement
                                        .dataset
                                        .questionId
                                );

                            const question =
                                questionMap.get(
                                    questionId
                                );

                            if (question) {
                                newGroup
                                    .questions
                                    .push(
                                        question
                                    );
                            }
                        }
                    );

                newGroups.push(
                    newGroup
                );
            }
        );

        if (newGroups.length) {
            survey.groups =
                newGroups;
        }
    };

/*
 * 新規グループ追加
 */
App.actions.addGroup =
    function() {

        /*
         * 現在編集中のDOM状態を
         * Stateへ反映してから追加。
         */
        App.actions
            .syncEditor();

        if (!App.State.editSurvey) {
            return;
        }

        App.State.editSurvey.groups
            .push({
                id:
                    App.Util.id(
                        'group'
                    ),

                name:
                    '新しいグループ',

                questions: []
            });

        App.State.dirty =
            true;

        App.Render.editor();

        App.actions.initSortables();
    };

/*
 * ★新規質問追加
 *
 * 新規アンケート直後でも必ず
 * 最初のグループへ追加できる。
 */
App.actions.addQuestion =
    function(groupId) {

        const survey =
            App.State.editSurvey;

        if (!survey) {
            alert(
                '編集対象のアンケートがありません。'
            );
            return;
        }

        /*
         * DOMからStateへの同期を先に行う。
         */
        App.actions
            .collectQuestionsFromDOM();

        let group =
            survey.groups.find(
                item =>
                    String(item.id) ===
                    String(groupId)
            );

        /*
         * 念のため対象グループが存在しない場合は
         * 自動生成。
         */
        if (!group) {

            group = {
                id:
                    String(groupId || App.Util.id('group')),

                name:
                    '新しいグループ',

                questions: []
            };

            survey.groups.push(
                group
            );
        }

        if (
            !Array.isArray(
                group.questions
            )
        ) {
            group.questions = [];
        }

        group.questions.push({
            id:
                App.Util.id(
                    'question'
                ),

            text:
                '新しい質問',

            type:
                'single',

            required:
                false,

            options: [
                '選択肢1',
                '選択肢2'
            ],

            other_enabled:
                false
        });

        App.State.dirty =
            true;

        App.Render.editor();

        App.actions.initSortables();

        /*
         * 追加直後に質問入力欄へフォーカス。
         */
        const editor =
            document.getElementById(
                'question_editor'
            );

        if (editor) {
            const inputs =
                editor.querySelectorAll(
                    'input[data-question-text]'
                );

            if (inputs.length) {
                inputs[
                    inputs.length - 1
                ].focus();

                inputs[
                    inputs.length - 1
                ].select();
            }
        }
    };

App.actions.deleteGroup =
    function(groupId) {

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

        if (
            !confirm(
                '「' +
                group.name +
                '」と、その中の質問をすべて削除しますか？'
            )
        ) {
            return;
        }

        survey.groups =
            survey.groups.filter(
                item =>
                    String(item.id) !==
                    String(groupId)
            );

        if (
            survey.groups.length ===
            0
        ) {
            survey.groups.push({
                id:
                    App.Util.id(
                        'group'
                    ),

                name:
                    'グループ1',

                questions: []
            });
        }

        App.State.dirty =
            true;

        App.Render.editor();

        App.actions.initSortables();
    };

App.actions.renameGroup =
    function(
        groupId,
        value
    ) {
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

        if (group) {
            group.name =
                value;

            App.State.dirty =
                true;
        }
    };

App.actions.deleteQuestion =
    function(questionId) {

        if (
            !confirm(
                'この質問を削除しますか？'
            )
        ) {
            return;
        }

        App.actions
            .collectQuestionsFromDOM();

        const survey =
            App.State.editSurvey;

        if (!survey) {
            return;
        }

        survey.groups.forEach(
            group => {
                group.questions =
                    group.questions.filter(
                        question =>
                            String(
                                question.id
                            ) !==
                            String(
                                questionId
                            )
                    );
            }
        );

        App.State.dirty =
            true;

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
            App.Util.findQuestion(
                questionId
            );

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
            question[key] =
                value;
        }

        App.State.dirty =
            true;

        if (key === 'type') {

            if (
                question.type ===
                'text'
            ) {
                question.options =
                    [];
            } else if (
                !Array.isArray(
                    question.options
                ) ||
                question.options.length === 0
            ) {
                question.options = [
                    '選択肢1',
                    '選択肢2'
                ];
            }

            App.Render.editor();

            App.actions
                .initSortables();
        }
    };

App.actions.updateOption =
    function(
        questionId,
        index,
        value
    ) {
        const question =
            App.Util.findQuestion(
                questionId
            );

        if (!question) {
            return;
        }

        question.options[
            index
        ] = value;

        App.State.dirty =
            true;
    };

App.actions.addOption =
    function(questionId) {

        const question =
            App.Util.findQuestion(
                questionId
            );

        if (!question) {
            return;
        }

        question.options =
            Array.isArray(
                question.options
            )
                ? question.options
                : [];

        question.options.push(
            '選択肢' +
            (question.options.length + 1)
        );

        App.State.dirty =
            true;

        App.Render.editor();

        App.actions
            .initSortables();
    };

App.actions.removeOption =
    function(
        questionId,
        index
    ) {
        const question =
            App.Util.findQuestion(
                questionId
            );

        if (!question) {
            return;
        }

        question.options.splice(
            index,
            1
        );

        App.State.dirty =
            true;

        App.Render.editor();

        App.actions
            .initSortables();
    };

App.actions.updateQuestionNumbering =
    function(value) {

        if (
            App.State.editSurvey
        ) {
            App.State.editSurvey
                .numbering_mode =
                value;

            App.State.dirty =
                true;

            App.Render.editor();

            App.actions
                .initSortables();
        }
    };

/*
 * SortableJS初期化
 *
 * 質問リストは全グループ共通の
 * group名を設定し、グループ間移動を許可。
 */
App.actions.initSortables =
    function() {

        if (
            typeof Sortable ===
            'undefined'
        ) {
            return;
        }

        const editor =
            document.getElementById(
                'question_editor'
            );

        if (!editor) {
            return;
        }

        const groupList =
            editor.querySelector(
                '[data-group-list]'
            );

        if (groupList) {

            new Sortable(
                groupList,
                {
                    animation: 180,

                    handle:
                        '.group-handle',

                    ghostClass:
                        'opacity-40',

                    onEnd:
                        function() {

                            App.actions
                                .syncEditor();

                            App.State.dirty =
                                true;

                            App.Render
                                .editor();

                            App.actions
                                .initSortables();
                        }
                }
            );
        }

        editor.querySelectorAll(
            '[data-question-list]'
        ).forEach(
            list => {

                new Sortable(
                    list,
                    {
                        group: {
                            name:
                                'survey_questions',

                            pull:
                                true,

                            put:
                                true
                        },

                        animation:
                            180,

                        handle:
                            '.question-handle',

                        ghostClass:
                            'opacity-40',

                        onEnd:
                            function() {

                            /*
                             * ここでStateへ
                             * グループ間移動を反映。
                             */
                            App.actions
                                .collectQuestionsFromDOM();

                            App.State.dirty =
                                true;

                            /*
                             * 再採番のため再描画。
                             */
                            App.Render
                                .editor();

                            App.actions
                                .initSortables();
                        }
                    }
                );
            }
        );
    };

App.actions.saveSurvey =
    async function() {

        App.actions
            .syncEditor();

        const survey =
            App.State.editSurvey;

        if (!survey) {
            return;
        }

        if (
            !String(
                survey.title || ''
            ).trim()
        ) {
            alert(
                'タイトルを入力してください。'
            );
            return;
        }

        /*
         * 空グループは許可する。
         * 質問0件でも保存可能。
         */
        try {

            await App.API
                .saveSurvey(
                    survey
                );

            App.State.dirty =
                false;

            await App.API.load();

            alert(
                'アンケートを保存しました。'
            );

            App.actions
                .goSurveys();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.cancelEdit =
    function() {

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
 * preview
 * ================================================================ */

App.actions.openPreview =
    function() {

        App.actions
            .syncEditor();

        const modal =
            document.getElementById(
                'preview_modal'
            );

        if (!modal) {
            return;
        }

        modal.classList.remove(
            'hidden'
        );

        App.Render.preview();
    };

App.actions.closePreview =
    function() {

        const modal =
            document.getElementById(
                'preview_modal'
            );

        if (modal) {
            modal.classList.add(
                'hidden'
            );
        }
    };

App.actions.previewSize =
    function(mobile) {

        App.State.previewMobile =
            Boolean(mobile);

        App.Render.preview();
    };

/* ================================================================
 * surveys
 * ================================================================ */

App.actions.searchSurveys =
    function(value) {

        App.State.keyword =
            value;

        App.Render.surveys();
    };

App.actions.toggleStatusFilter =
    function(value) {

        App.State.status_filter =
            value;

        App.Render.surveys();
    };

App.actions.sortSurveys =
    function(value) {

        App.State.sort =
            value;

        App.Render.surveys();
    };

App.actions.responseCount =
    function(surveyId) {

        return App.State.data
            .responses
            .filter(
                response =>
                    String(
                        response.survey_id
                    ) ===
                    String(
                        surveyId
                    )
            )
            .length;
    };

/* ================================================================
 * mail
 * ================================================================ */

App.actions.openMail =
    function(id) {

        App.State.surveyId =
            id;

        App.State.page =
            'mail';

        App.State.selectedCustomers =
            [];

        App.Render.main();
    };

App.actions.openResults =
    function(id) {

        App.State.surveyId =
            id;

        App.State.page =
            'results';

        App.State.selectedQuestions =
            [];

        App.Render.main();
    };

App.actions.filteredCustomers =
    function() {

        const keyword =
            App.State.customerFilter
                .toLowerCase()
                .trim();

        return App.State.data
            .customers
            .filter(
                customer => {

                    if (!keyword) {
                        return true;
                    }

                    return [
                        customer.company,
                        customer.name,
                        customer.email,
                        customer.phone,
                        customer.address,
                        customer.answer_status
                    ].some(
                        value =>
                            String(
                                value || ''
                            )
                            .toLowerCase()
                            .includes(
                                keyword
                            )
                    );
                }
            );
    };

App.actions.filterCustomers =
    function(value) {

        App.State.customerFilter =
            value;

        App.Render.mail();
    };

App.actions.selectCustomer =
    function(
        id,
        checked
    ) {

        id = String(id);

        if (checked) {

            if (
                !App.State
                    .selectedCustomers
                    .includes(id)
            ) {
                App.State
                    .selectedCustomers
                    .push(id);
            }

        } else {

            App.State
                .selectedCustomers =
                App.State
                    .selectedCustomers
                    .filter(
                        value =>
                            value !== id
                    );
        }
    };

App.actions.selectAllCustomers =
    function(checked) {

        const visible =
            App.actions
                .filteredCustomers()
                .filter(
                    customer =>
                        customer.source !==
                        'web'
                );

        if (checked) {

            visible.forEach(
                customer => {

                    const id =
                        String(
                            customer.id
                        );

                    if (
                        !App.State
                            .selectedCustomers
                            .includes(id)
                    ) {
                        App.State
                            .selectedCustomers
                            .push(id);
                    }
                }
            );

        } else {

            const visibleIds =
                new Set(
                    visible.map(
                        customer =>
                            String(
                                customer.id
                            )
                    )
                );

            App.State
                .selectedCustomers =
                App.State
                    .selectedCustomers
                    .filter(
                        id =>
                            !visibleIds.has(
                                id
                            )
                    );
        }

        App.Render.mail();
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

        if (
            type ===
            'reminder'
        ) {

            subject.value =
                'アンケートご回答のお願い（再送）';

            body.value =
                '{顧客名} 様\n\n' +
                '先日ご案内したアンケートが未回答となっております。\n' +
                'お手数ですが、以下のURLよりご回答ください。\n\n' +
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

App.actions.sendMail =
    async function() {

        const ids =
            App.State.selectedCustomers;

        if (!ids.length) {
            alert(
                '送信対象を選択してください。'
            );
            return;
        }

        const already =
            App.State.data
                .customers
                .filter(
                    customer =>
                        ids.includes(
                            String(
                                customer.id
                            )
                        ) &&
                        Number(
                            customer.send_count ||
                            0
                        ) > 0
                );

        if (already.length) {

            if (
                !confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )
            ) {
                return;
            }

            const type =
                document.getElementById(
                    'template_type'
                );

            if (type) {
                type.value =
                    'reminder';

                App.actions
                    .templateChanged(
                        'reminder'
                    );
            }
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

        if (
            !subject ||
            !body
        ) {
            alert(
                '件名と本文を入力してください。'
            );
            return;
        }

        if (
            !confirm(
                ids.length +
                '件にメールを送信します。よろしいですか？'
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

                        recipient_ids:
                            ids,

                        mail_subject:
                            subject,

                        mail_body:
                            body,

                        template_type:
                            templateType
                    }
                );

            alert(
                result.count +
                '件の送信処理を実行しました。'
            );

            await App.API.load();

            App.Render.mail();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.registerKintone =
    async function(id) {

        if (
            !confirm(
                'kintone登録完了として更新しますか？'
            )
        ) {
            return;
        }

        try {

            await App.API.request(
                'register_customer',
                {
                    customer_id:
                        id
                }
            );

            await App.API.load();

            App.Render.mail();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.showMail =
    function(
        logId,
        customerId
    ) {

        const log =
            App.State.data.mail_logs
                .find(
                    item =>
                        String(item.id) ===
                        String(logId)
                );

        if (!log) {
            return;
        }

        const message =
            (log.messages || [])
                .find(
                    item =>
                        String(
                            item.customer_id
                        ) ===
                        String(
                            customerId
                        )
                );

        if (!message) {
            return;
        }

        alert(
            '件名:\n' +
            message.subject +
            '\n\n本文:\n' +
            message.body
        );
    };

/* ================================================================
 * kintone
 * ================================================================ */

App.actions.fetchKintoneFields =
    async function() {

        const input =
            document.getElementById(
                'setting_app_id'
            );

        const appId =
            input?.value.trim() || '';

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
             * 現在画面に入力されている
             * サブドメイン・ログイン情報を保存せず
             * 一時設定として送信する。
             */
            const settings = {
                subdomain:
                    document.getElementById(
                        'setting_subdomain'
                    )?.value || '',

                login_name:
                    document.getElementById(
                        'setting_login_name'
                    )?.value || '',

                password:
                    document.getElementById(
                        'setting_password'
                    )?.value || '',

                app_id:
                    appId,

                ssl_verify:
                    document.getElementById(
                        'setting_ssl_verify'
                    )?.checked || false,

                proxy:
                    document.getElementById(
                        'setting_proxy'
                    )?.value || ''
            };

            /*
             * パスワードを空欄にした場合、
             * サーバ側保存済みパスワードを利用できる。
             */
            if (
                !settings.password &&
                App.State.data.settings
                    ?.password
            ) {
                settings.password =
                    App.State.data.settings
                        .password;
            }

            /*
             * fetchKintoneFieldsの重要ポイント。
             * PHP側でFQDNを正規化するため、
             * xxxx / xxxx.cybozu.com /
             * https://xxxx.cybozu.com
             * の全形式に対応。
             */
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

        const address =
            document.getElementById(
                'field_address'
            );

        const settings = {
            subdomain:
                document.getElementById(
                    'setting_subdomain'
                )?.value || '',

            login_name:
                document.getElementById(
                    'setting_login_name'
                )?.value || '',

            password:
                document.getElementById(
                    'setting_password'
                )?.value || '',

            app_id:
                document.getElementById(
                    'setting_app_id'
                )?.value || '',

            ssl_verify:
                document.getElementById(
                    'setting_ssl_verify'
                )?.checked || false,

            proxy:
                document.getElementById(
                    'setting_proxy'
                )?.value || '',

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
                address
                    ? Array.from(
                        address.options
                    )
                    .filter(
                        option =>
                            option.selected
                    )
                    .map(
                        option =>
                            option.value
                    )
                    : []
        };

        try {

            await App.API.saveSettings(
                settings
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
 * results
 * ================================================================ */

App.actions.toggleQuestion =
    function(
        id,
        checked
    ) {

        id = String(id);

        if (checked) {

            if (
                !App.State
                    .selectedQuestions
                    .includes(id)
            ) {
                App.State
                    .selectedQuestions
                    .push(id);
            }

        } else {

            App.State
                .selectedQuestions =
                App.State
                    .selectedQuestions
                    .filter(
                        value =>
                            value !== id
                    );
        }

        App.Render.results();
    };

App.actions.selectAllQuestions =
    function(select) {

        const survey =
            App.Util.findSurvey(
                App.State.surveyId
            );

        if (!survey) {
            return;
        }

        const questions =
            App.Util.allQuestions(
                survey
            );

        App.State.selectedQuestions =
            select
                ? questions.map(
                    q => String(q.id)
                )
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
            App.State.data.responses
                .find(
                    item =>
                        String(
                            item.id
                        ) ===
                        String(
                            responseId
                        )
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

        const modal =
            document.getElementById(
                'response_modal'
            );

        if (!detail || !modal) {
            return;
        }

        let html = `
            <div class="mb-5">
                <div class="font-bold">
                    ${App.Util.escape(
                        response.company
                    )}
                </div>
                <div>
                    ${App.Util.escape(
                        response.name
                    )}
                </div>
                <div class="text-sm text-slate-500">
                    ${App.Util.escape(
                        response.email
                    )}
                </div>
                <div class="text-xs text-slate-400 mt-1">
                    ${App.Util.escape(
                        response.answered_at
                    )}
                </div>
            </div>
        `;

        App.Util
            .allQuestions(
                survey
            )
            .forEach(
                question => {

                    let answer =
                        response
                            .answers?.[
                                question.id
                            ] ?? '';

                    if (
                        Array.isArray(
                            answer
                        )
                    ) {
                        answer =
                            answer.join(
                                '、'
                            );
                    }

                    html += `
                        <div class="border-t py-4">
                            <div class="text-xs text-slate-500">
                                ${App.Util.escape(
                                    question.text
                                )}
                            </div>
                            <div class="mt-1 whitespace-pre-wrap">
                                ${App.Util.escape(
                                    answer
                                )}
                            </div>
                        </div>
                    `;
                }
            );

        detail.innerHTML =
            html;

        modal.classList.remove(
            'hidden'
        );
    };

App.actions.closeResponse =
    function() {

        document.getElementById(
            'response_modal'
        )?.classList.add(
            'hidden'
        );
    };

/* ================================================================
 * render header
 * ================================================================ */

App.Render.header =
    function() {
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
 * render main
 * ================================================================ */

App.Render.main =
    function() {

        const app =
            document.getElementById(
                'app'
            );

        if (!app) {
            return;
        }

        let content = '';

        switch (
            App.State.page
        ) {

            case 'edit':
                content =
                    App.Render.editor();
                break;

            case 'mail':
                content =
                    App.Render.mail();
                break;

            case 'results':
                content =
                    App.Render.results();
                break;

            case 'settings':
                content =
                    App.Render.settings();
                break;

            default:
                content =
                    App.Render.surveys();
        }

        app.innerHTML =
            App.Render.header() +
            `
                <main class="max-w-[1500px] mx-auto p-5 md:p-7">
                    ${content}
                </main>
            `;

        if (
            App.State.page ===
            'edit'
        ) {
            App.actions
                .initSortables();
        }
    };

/* ================================================================
 * survey list
 * ================================================================ */

App.Render.surveys =
    function() {

        App.State.page =
            'surveys';

        let surveys =
            App.State.data.surveys
                .filter(
                    survey =>
                        !survey.deleted
                );

        const keyword =
            App.State.keyword
                .trim()
                .toLowerCase();

        if (keyword) {
            surveys =
                surveys.filter(
                    survey =>
                        String(
                            survey.title ||
                            ''
                        )
                        .toLowerCase()
                        .includes(
                            keyword
                        )
                );
        }

        if (
            App.State.status_filter
        ) {
            surveys =
                surveys.filter(
                    survey =>
                        survey.status ===
                        App.State.status_filter
                );
        }

        surveys.sort(
            (a, b) => {

                switch (
                    App.State.sort
                ) {

                    case 'updated_asc':
                        return String(
                            a.updated_at || ''
                        ).localeCompare(
                            String(
                                b.updated_at || ''
                            )
                        );

                    case 'answers_desc':
                        return (
                            App.actions.responseCount(
                                b.id
                            ) -
                            App.actions.responseCount(
                                a.id
                            )
                        );

                    case 'answers_asc':
                        return (
                            App.actions.responseCount(
                                a.id
                            ) -
                            App.actions.responseCount(
                                b.id
                            )
                        );

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

        const rows =
            surveys.map(
                survey => {

                    const count =
                        App.actions
                            .responseCount(
                                survey.id
                            );

                    let buttons = `
                        <button
                            onclick="App.actions.editSurvey('${App.Util.escapeAttr(survey.id)}')"
                            class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs font-medium"
                        >
                            確認・編集
                        </button>
                    `;

                    if (
                        survey.status ===
                        'active'
                    ) {
                        buttons += `
                            <button
                                onclick="App.actions.openResults('${App.Util.escapeAttr(survey.id)}')"
                                class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs"
                            >
                                集計
                            </button>

                            <button
                                onclick="App.actions.openMail('${App.Util.escapeAttr(survey.id)}')"
                                class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs"
                            >
                                送信
                            </button>

                            <button
                                onclick="App.actions.changeStatus('${App.Util.escapeAttr(survey.id)}','ended')"
                                class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-xs"
                            >
                                停止
                            </button>
                        `;
                    }

                    if (
                        survey.status ===
                        'draft'
                    ) {
                        buttons += `
                            <button
                                onclick="App.actions.deleteSurvey('${App.Util.escapeAttr(survey.id)}')"
                                class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-xs"
                            >
                                削除
                            </button>

                            <button
                                onclick="App.actions.changeStatus('${App.Util.escapeAttr(survey.id)}','active')"
                                class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 text-xs"
                            >
                                公開
                            </button>
                        `;
                    }

                    if (
                        survey.status ===
                        'ended'
                    ) {
                        buttons += `
                            <button
                                onclick="App.actions.openResults('${App.Util.escapeAttr(survey.id)}')"
                                class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs"
                            >
                                集計
                            </button>
                        `;
                    }

                    buttons += `
                        <button
                            onclick="App.actions.cloneSurvey('${App.Util.escapeAttr(survey.id)}')"
                            class="px-3 py-1.5 rounded-lg bg-slate-100 text-xs"
                        >
                            複製
                        </button>
                    `;

                    return `
                        <tr class="border-b border-slate-100 hover:bg-slate-50">

                            <td class="px-4 py-4 whitespace-nowrap text-xs text-slate-500">
                                ${App.Util.escape(
                                    String(
                                        survey.created_at ||
                                        ''
                                    ).slice(0,10)
                                )}
                                <br>
                                <span class="text-slate-400">
                                    更新:
                                    ${App.Util.escape(
                                        String(
                                            survey.updated_at ||
                                            ''
                                        ).slice(0,10)
                                    )}
                                </span>
                            </td>

                            <td class="px-4 py-4 min-w-[250px]">
                                <div class="font-bold">
                                    ${App.Util.escape(
                                        survey.title
                                    )}
                                </div>
                            </td>

                            <td class="px-4 py-4 whitespace-nowrap text-sm">
                                ${App.Util.escape(
                                    survey.start_at ||
                                    '未設定'
                                )}
                                <span class="text-slate-400 mx-1">
                                    ～
                                </span>
                                ${App.Util.escape(
                                    survey.end_at ||
                                    '未設定'
                                )}
                            </td>

                            <td class="px-4 py-4">
                                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold ${App.Util.statusClass(survey.status)}">
                                    ${App.Util.statusText(
                                        survey.status
                                    )}
                                </span>
                            </td>

                            <td class="px-4 py-4 font-semibold">
                                ${count} 件
                            </td>

                            <td class="px-4 py-4">
                                <div class="flex flex-wrap gap-1.5 min-w-[400px]">
                                    ${buttons}
                                </div>
                            </td>

                        </tr>
                    `;
                }
            ).join('');

        return `
            <section>

                <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">

                    <div>
                        <div class="text-sm text-indigo-600 font-semibold">
                            SURVEYS
                        </div>

                        <h1 class="text-2xl font-bold mt-1">
                            アンケート一覧
                        </h1>

                        <p class="text-sm text-slate-500 mt-1">
                            アンケートの作成・公開・集計・送信を一元管理します。
                        </p>
                    </div>

                    <button
                        onclick="App.actions.newSurvey()"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-5 py-3 font-semibold"
                    >
                        ＋ 新規アンケート作成
                    </button>

                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm">

                    <div class="p-4 border-b flex flex-col lg:flex-row gap-3">

                        <input
                            value="${App.Util.escapeAttr(App.State.keyword)}"
                            oninput="App.actions.searchSurveys(this.value)"
                            onkeydown="if(event.key==='Enter'){App.actions.searchSurveys(this.value)}"
                            placeholder="タイトルを検索"
                            class="flex-1 border border-slate-300 rounded-xl px-4 py-2.5"
                        >

                        <select
                            onchange="App.actions.toggleStatusFilter(this.value)"
                            class="border border-slate-300 rounded-xl px-4 py-2.5 bg-white"
                        >
                            <option value="">すべて</option>
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
                            class="border border-slate-300 rounded-xl px-4 py-2.5 bg-white"
                        >
                            <option value="updated_desc">
                                更新日：新しい順
                            </option>
                            <option value="updated_asc">
                                更新日：古い順
                            </option>
                            <option value="answers_desc">
                                回答数：多い順
                            </option>
                            <option value="answers_asc">
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

                    <div class="overflow-x-auto">

                        <table class="w-full text-left">

                            <thead class="bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">
                                        作成日 / 更新日
                                    </th>
                                    <th class="px-4 py-3">
                                        タイトル
                                    </th>
                                    <th class="px-4 py-3">
                                        アンケート期間
                                    </th>
                                    <th class="px-4 py-3">
                                        ステータス
                                    </th>
                                    <th class="px-4 py-3">
                                        回答数
                                    </th>
                                    <th class="px-4 py-3">
                                        操作
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                ${
                                    rows ||
                                    `
                                    <tr>
                                        <td
                                            colspan="6"
                                            class="p-12 text-center text-slate-400"
                                        >
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
 * editor render
 * ================================================================ */

App.Render.editor =
    function() {

        const survey =
            App.State.editSurvey;

        if (!survey) {
            return '';
        }

        let groupsHtml = '';

        (
            survey.groups || []
        ).forEach(
            (group, groupIndex) => {

                let questionsHtml = '';

                (
                    group.questions || []
                ).forEach(
                    (
                        question,
                        questionIndex
                    ) => {

                        const number =
                            App.Util
                                .questionNumber(
                                    survey,
                                    groupIndex,
                                    questionIndex
                                );

                        let optionsHtml = '';

                        if (
                            question.type ===
                                'single' ||
                            question.type ===
                                'multiple'
                        ) {

                            optionsHtml = `
                                <div class="mt-4">

                                    <div class="text-xs font-semibold text-slate-500 mb-2">
                                        選択肢
                                    </div>

                                    <div class="space-y-2">

                                        ${
                                            (
                                                question.options ||
                                                []
                                            )
                                            .map(
                                                (
                                                    option,
                                                    optionIndex
                                                ) => `
                                                    <div class="flex gap-2">

                                                        <input
                                                            value="${App.Util.escapeAttr(option)}"
                                                            oninput="App.actions.updateOption('${App.Util.escapeAttr(question.id)}',${optionIndex},this.value)"
                                                            class="flex-1 border border-slate-300 rounded-lg px-3 py-2"
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
                                            .join('')
                                        }

                                    </div>

                                    <button
                                        onclick="App.actions.addOption('${App.Util.escapeAttr(question.id)}')"
                                        class="mt-2 text-sm text-indigo-600 font-medium"
                                    >
                                        ＋ 選択肢を追加
                                    </button>

                                </div>
                            `;
                        }

                        questionsHtml += `
                            <article
                                data-question-id="${App.Util.escapeAttr(question.id)}"
                                class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm"
                            >

                                <div class="flex items-start gap-3">

                                    <div class="question-handle cursor-grab text-slate-400 text-xl pt-1">
                                        ⠿
                                    </div>

                                    <div class="flex-1 min-w-0">

                                        <div class="flex flex-wrap items-center gap-2 mb-3">

                                            <span class="text-xs font-bold text-indigo-600">
                                                ${number}
                                            </span>

                                            <select
                                                onchange="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','type',this.value)"
                                                class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-sm"
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
                                            data-question-text="1"
                                            value="${App.Util.escapeAttr(question.text)}"
                                            oninput="App.actions.updateQuestion('${App.Util.escapeAttr(question.id)}','text',this.value)"
                                            class="w-full text-base font-semibold border border-slate-300 rounded-lg px-3 py-2"
                                            placeholder="質問文"
                                        >

                                        ${optionsHtml}

                                        <div class="mt-4 flex flex-wrap gap-5 text-sm">

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
                                                question.type !==
                                                'text'
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
                                    >
                                        ×
                                    </button>

                                </div>

                            </article>
                        `;
                    }
                );

                groupsHtml += `
                    <section
                        data-group-id="${App.Util.escapeAttr(group.id)}"
                        class="bg-slate-50 border border-slate-200 rounded-2xl p-4"
                    >

                        <div class="flex items-center gap-3 mb-4">

                            <div class="group-handle cursor-grab text-slate-400 text-xl">
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
                                class="px-3 py-2 rounded-lg bg-white border text-sm"
                            >
                                グループ削除
                            </button>

                        </div>

                        <div
                            data-question-list
                            class="space-y-3 min-h-[80px]"
                        >
                            ${
                                questionsHtml ||
                                `
                                <div class="border border-dashed border-slate-300 rounded-xl p-7 text-center text-sm text-slate-400">
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

                <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-5">

                    <div class="grid md:grid-cols-4 gap-4">

                        <div class="md:col-span-2">

                            <label class="block text-xs font-semibold text-slate-500 mb-1">
                                タイトル
                            </label>

                            <input
                                id="survey_title"
                                value="${App.Util.escapeAttr(survey.title)}"
                                oninput="App.actions.markDirty()"
                                class="w-full border border-slate-300 rounded-xl px-4 py-3 text-lg font-semibold"
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
                                onchange="App.actions.markDirty()"
                                class="w-full border border-slate-300 rounded-xl px-3 py-3"
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
                                onchange="App.actions.markDirty()"
                                class="w-full border border-slate-300 rounded-xl px-3 py-3"
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
                            class="border border-slate-300 rounded-lg px-3 py-2"
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
                        data-group-list
                        class="space-y-4"
                    >
                        ${groupsHtml}
                    </div>

                    <button
                        onclick="App.actions.addGroup()"
                        class="w-full border-2 border-dashed border-slate-300 hover:border-indigo-400 hover:text-indigo-600 rounded-2xl py-5 font-semibold"
                    >
                        ＋ グループを追加
                    </button>

                </div>

            </section>

            <div
                id="preview_modal"
                class="hidden fixed inset-0 z-50 bg-slate-900/50 p-4 md:p-8"
            >
                <div class="max-w-5xl mx-auto bg-white rounded-2xl shadow-xl h-full max-h-full overflow-hidden flex flex-col">

                    <div class="p-4 border-b flex items-center justify-between">

                        <div class="font-bold">
                            プレビュー
                        </div>

                        <div class="flex gap-2">

                            <button
                                onclick="App.actions.previewSize(false)"
                                class="px-3 py-1.5 rounded-lg bg-slate-100 text-sm"
                            >
                                PC
                            </button>

                            <button
                                onclick="App.actions.previewSize(true)"
                                class="px-3 py-1.5 rounded-lg bg-slate-100 text-sm"
                            >
                                スマートフォン
                            </button>

                            <button
                                onclick="App.actions.closePreview()"
                                class="px-3 py-1.5 rounded-lg bg-slate-200 text-sm"
                            >
                                閉じる
                            </button>

                        </div>

                    </div>

                    <div
                        id="preview_content"
                        class="flex-1 overflow-auto p-5 bg-slate-100"
                    ></div>

                </div>
            </div>
        `;
    };

/* ================================================================
 * preview render
 * ================================================================ */

App.Render.preview =
    function() {

        const container =
            document.getElementById(
                'preview_content'
            );

        const survey =
            App.State.editSurvey;

        if (!container || !survey) {
            return;
        }

        let html = '';

        (
            survey.groups || []
        ).forEach(
            group => {

                html += `
                    <section class="mb-8">

                        <h2 class="font-bold text-lg border-b pb-2 mb-5">
                            ${App.Util.escape(
                                group.name
                            )}
                        </h2>
                `;

                (
                    group.questions || []
                ).forEach(
                    question => {

                        html += `
                            <div class="mb-7">

                                <div class="font-semibold mb-3">
                                    ${App.Util.escape(
                                        question.text
                                    )}

                                    ${
                                        question.required
                                            ? '<span class="text-red-500">*</span>'
                                            : ''
                                    }
                                </div>
                        `;

                        if (
                            question.type ===
                            'text'
                        ) {

                            html += `
                                <textarea
                                    disabled
                                    rows="4"
                                    class="w-full border rounded-xl p-3 bg-white"
                                ></textarea>
                            `;

                        } else {

                            (
                                question.options ||
                                []
                            ).forEach(
                                option => {

                                    const type =
                                        question.type ===
                                        'multiple'
                                            ? 'checkbox'
                                            : 'radio';

                                    html += `
                                        <label class="flex items-center gap-2 mb-2">
                                            <input
                                                type="${type}"
                                                disabled
                                            >
                                            <span>
                                                ${App.Util.escape(
                                                    option
                                                )}
                                            </span>
                                        </label>
                                    `;
                                }
                            );

                            if (
                                question.other_enabled
                            ) {
                                html += `
                                    <label class="flex items-center gap-2 mt-2">
                                        <input
                                            type="radio"
                                            disabled
                                        >
                                        <span>
                                            その他
                                        </span>
                                    </label>
                                `;
                            }
                        }

                        html += `
                            </div>
                        `;
                    }
                );

                html += `
                    </section>
                `;
            }
        );

        const width =
            App.State.previewMobile
                ? 'max-w-sm'
                : 'max-w-3xl';

        container.innerHTML = `
            <div class="${width} mx-auto bg-white rounded-2xl p-6 shadow-sm">

                <h1 class="text-2xl font-bold mb-8">
                    ${App.Util.escape(
                        survey.title
                    )}
                </h1>

                ${html}

                <button
                    onclick="alert('これはプレビューです。実際の回答は送信されません。')"
                    class="w-full bg-indigo-600 text-white rounded-xl py-3 font-semibold"
                >
                    回答を送信する
                </button>

            </div>
        `;
    };

/* ================================================================
 * mail render
 * ================================================================ */

App.Render.mail =
    function() {

        const survey =
            App.Util.findSurvey(
                App.State.surveyId
            );

        if (!survey) {
            return '';
        }

        const customers =
            App.actions
                .filteredCustomers();

        const rows =
            customers.map(
                customer => {

                    const id =
                        String(
                            customer.id
                        );

                    const selectable =
                        customer.source !==
                        'web';

                    const checked =
                        App.State
                            .selectedCustomers
                            .includes(id);

                    const status =
                        customer.answer_status ===
                        'answered'
                            ? '回答済み'
                            : customer.send_count
                                ? '送信済み（未回答）'
                                : '未送信';

                    return `
                        <tr class="border-b hover:bg-slate-50">

                            <td class="px-4 py-3">
                                ${
                                    selectable
                                        ? `
                                        <input
                                            type="checkbox"
                                            ${checked ? 'checked' : ''}
                                            onchange="App.actions.selectCustomer('${App.Util.escapeAttr(id)}',this.checked)"
                                            class="w-4 h-4"
                                        >
                                        `
                                        : '<span class="text-slate-400 text-xs">Web</span>'
                                }
                            </td>

                            <td class="px-4 py-3">
                                <div class="font-bold">
                                    ${App.Util.escape(
                                        customer.company
                                    )}
                                </div>
                                <div>
                                    ${App.Util.escape(
                                        customer.name
                                    )}
                                </div>
                                <div class="text-xs text-slate-500">
                                    ${App.Util.escape(
                                        customer.email
                                    )}
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                ${App.Util.escape(
                                    customer.department
                                )}
                            </td>

                            <td class="px-4 py-3">
                                ${App.Util.escape(
                                    customer.phone
                                )}
                            </td>

                            <td class="px-4 py-3">
                                ${App.Util.escape(
                                    customer.address
                                )}
                            </td>

                            <td class="px-4 py-3 text-sm">
                                ${
                                    customer.sent_at
                                        ? `
                                            ${App.Util.escape(
                                                customer.sent_at
                                            )}
                                            <br>
                                            ${customer.send_count || 0}回
                                        `
                                        : '未送信'
                                }
                            </td>

                            <td class="px-4 py-3">
                                <span class="px-2 py-1 rounded-full text-xs ${
                                    customer.answer_status ===
                                    'answered'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-amber-100 text-amber-700'
                                }">
                                    ${status}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                ${
                                    customer.source ===
                                    'web'
                                        ? (
                                            customer.kintone_status ===
                                            'registered'
                                                ? `
                                                    <span class="text-emerald-600 text-xs">
                                                        ✓ 登録完了
                                                    </span>
                                                `
                                                : `
                                                    <button
                                                        onclick="App.actions.registerKintone('${App.Util.escapeAttr(id)}')"
                                                        class="text-xs px-2 py-1 rounded bg-indigo-50 text-indigo-700"
                                                    >
                                                        kintone登録完了
                                                    </button>
                                                `
                                        )
                                        : '<span class="text-slate-400 text-xs">kintone</span>'
                                }
                            </td>

                        </tr>
                    `;
                }
            ).join('');

        const logs =
            App.State.data.mail_logs
                .filter(
                    log =>
                        String(
                            log.survey_id
                        ) ===
                        String(
                            survey.id
                        )
                )
                .slice()
                .reverse()
                .map(
                    log => `
                        <tr class="border-b">

                            <td class="px-4 py-3">
                                ${App.Util.escape(
                                    log.sent_at
                                )}
                            </td>

                            <td class="px-4 py-3">
                                ${log.template_type === 'reminder'
                                    ? 'リマインド'
                                    : '初回'}
                            </td>

                            <td class="px-4 py-3">
                                ${log.count || 0}件
                            </td>

                            <td class="px-4 py-3">
                                ${App.Util.escape(
                                    log.subject
                                )}
                            </td>

                            <td class="px-4 py-3">
                                ${App.Util.escape(
                                    log.executor
                                )}
                            </td>

                        </tr>
                    `
                )
                .join('');

        return `
            <section>

                <div class="mb-6">

                    <button
                        onclick="App.actions.goSurveys()"
                        class="text-sm text-indigo-600"
                    >
                        ← アンケート一覧
                    </button>

                    <div class="text-sm text-indigo-600 font-semibold mt-3">
                        MAIL
                    </div>

                    <h1 class="text-2xl font-bold mt-1">
                        ${App.Util.escape(
                            survey.title
                        )}
                    </h1>

                </div>

                <div class="bg-white border rounded-2xl p-5 mb-5">

                    <div class="grid md:grid-cols-3 gap-4">

                        <div>
                            <label class="block text-sm font-semibold mb-1">
                                テンプレート
                            </label>

                            <select
                                id="template_type"
                                onchange="App.actions.templateChanged(this.value)"
                                class="w-full border rounded-xl px-3 py-2.5"
                            >
                                <option value="initial">
                                    初回送信
                                </option>

                                <option value="reminder">
                                    再送・リマインド
                                </option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold mb-1">
                                件名
                            </label>

                            <input
                                id="mail_subject"
                                value="アンケートご回答のお願い"
                                class="w-full border rounded-xl px-3 py-2.5"
                            >
                        </div>

                    </div>

                    <div class="mt-4">

                        <label class="block text-sm font-semibold mb-1">
                            本文
                        </label>

                        <textarea
                            id="mail_body"
                            rows="8"
                            class="w-full border rounded-xl p-3"
                        >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}
</textarea>

                        <p class="text-xs text-slate-400 mt-2">
                            使用可能な変数：
                            {顧客名} / {アンケートURL}
                        </p>

                    </div>

                    <div class="mt-5 flex justify-end">

                        <button
                            onclick="App.actions.sendMail()"
                            class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-semibold"
                        >
                            選択した顧客へ一括送信
                        </button>

                    </div>

                </div>

                <div class="bg-white border rounded-2xl overflow-hidden">

                    <div class="p-5 border-b">

                        <div class="flex items-center gap-3">

                            <input
                                id="select_all"
                                type="checkbox"
                                onchange="App.actions.selectAllCustomers(this.checked)"
                                class="w-4 h-4"
                            >

                            <label for="select_all" class="text-sm">
                                全選択
                            </label>

                        </div>

                        <input
                            id="customer_filter"
                            value="${App.Util.escapeAttr(App.State.customerFilter)}"
                            oninput="App.actions.filterCustomers(this.value)"
                            placeholder="顧客名・メールアドレス・電話番号等で検索"
                            class="mt-4 w-full border rounded-xl px-4 py-2.5"
                        >

                    </div>

                    <div
                        id="customer_table"
                        class="overflow-x-auto"
                    >

                        <table class="w-full text-left min-w-[1100px]">

                            <thead class="bg-slate-50 text-xs text-slate-500">

                                <tr>
                                    <th class="px-4 py-3">
                                        選択
                                    </th>
                                    <th class="px-4 py-3">
                                        会社名 / 氏名 / メール
                                    </th>
                                    <th class="px-4 py-3">
                                        部署
                                    </th>
                                    <th class="px-4 py-3">
                                        電話番号
                                    </th>
                                    <th class="px-4 py-3">
                                        住所
                                    </th>
                                    <th class="px-4 py-3">
                                        送信履歴
                                    </th>
                                    <th class="px-4 py-3">
                                        回答
                                    </th>
                                    <th class="px-4 py-3">
                                        kintone
                                    </th>
                                </tr>

                            </thead>

                            <tbody>
                                ${
                                    rows ||
                                    `
                                    <tr>
                                        <td colspan="8" class="p-12 text-center text-slate-400">
                                            顧客データがありません。
                                        </td>
                                    </tr>
                                    `
                                }
                            </tbody>

                        </table>

                    </div>
                </div>

                <div class="bg-white border rounded-2xl mt-5 overflow-hidden">

                    <div class="p-5 border-b">
                        <h2 class="font-bold">
                            一括送信ログ
                        </h2>
                    </div>

                    <div class="overflow-x-auto">

                        <table class="w-full text-left">

                            <thead class="bg-slate-50 text-xs text-slate-500">

                                <tr>
                                    <th class="px-4 py-3">
                                        送信日時
                                    </th>
                                    <th class="px-4 py-3">
                                        種別
                                    </th>
                                    <th class="px-4 py-3">
                                        件数
                                    </th>
                                    <th class="px-4 py-3">
                                        件名
                                    </th>
                                    <th class="px-4 py-3">
                                        実行者
                                    </th>
                                </tr>

                            </thead>

                            <tbody>
                                ${
                                    logs ||
                                    `
                                    <tr>
                                        <td
                                            colspan="5"
                                            class="p-10 text-center text-slate-400"
                                        >
                                            送信履歴はありません。
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
 * results render
 * ================================================================ */

App.Render.results =
    function() {

        const survey =
            App.Util.findSurvey(
                App.State.surveyId
            );

        if (!survey) {
            return '';
        }

        const questions =
            App.Util.allQuestions(
                survey
            );

        if (
            !App.State.selectedQuestions
                .length
        ) {
            App.State
                .selectedQuestions =
                questions.map(
                    question =>
                        String(
                            question.id
                        )
                );
        }

        const responses =
            App.State.data.responses
                .filter(
                    response =>
                        String(
                            response.survey_id
                        ) ===
                        String(
                            survey.id
                        )
                );

        const targets =
            App.State.data.customers
                .filter(
                    customer =>
                        customer.source !==
                        'web' &&
                        Number(
                            customer.send_count ||
                            0
                        ) > 0
                );

        const answeredTargetIds =
            new Set(
                responses
                    .map(
                        response =>
                            String(
                                response.customer_id ||
                                ''
                            )
                    )
            );

        const answeredTargets =
            targets.filter(
                customer =>
                    answeredTargetIds
                        .has(
                            String(
                                customer.id
                            )
                        )
            ).length;

        const webResponses =
            responses.filter(
                response => {

                    const customer =
                        App.State.data
                            .customers
                            .find(
                                item =>
                                    String(
                                        item.id
                                    ) ===
                                    String(
                                        response.customer_id
                                    )
                            );

                    return (
                        customer?.source ===
                        'web'
                    );
                }
            ).length;

        const unanswered =
            Math.max(
                0,
                targets.length -
                answeredTargets
            );

        const rate =
            targets.length
                ? (
                    answeredTargets /
                    targets.length *
                    100
                ).toFixed(1)
                : '0.0';

        const questionChecks =
            questions.map(
                question => `
                    <label class="flex items-center gap-2 text-sm">

                        <input
                            type="checkbox"
                            ${
                                App.State
                                    .selectedQuestions
                                    .includes(
                                        String(
                                            question.id
                                        )
                                    )
                                    ? 'checked'
                                    : ''
                            }
                            onchange="App.actions.toggleQuestion('${App.Util.escapeAttr(question.id)}',this.checked)"
                            class="w-4 h-4"
                        >

                        <span>
                            ${App.Util.escape(
                                question.text
                            )}
                        </span>

                        <span class="text-[10px] px-1.5 py-0.5 bg-slate-100 rounded">
                            ${App.Util.typeText(
                                question.type
                            )}
                        </span>

                    </label>
                `
            ).join('');

        let charts = '';

        questions.forEach(
            question => {

                if (
                    !App.State
                        .selectedQuestions
                        .includes(
                            String(
                                question.id
                            )
                        )
                ) {
                    return;
                }

                if (
                    question.type ===
                    'text'
                ) {

                    const posts =
                        responses
                            .map(
                                response => ({
                                    response:
                                        response,

                                    answer:
                                        response
                                            .answers
                                            ?.[
                                                question.id
                                            ] || ''
                                })
                            )
                            .filter(
                                item =>
                                    item.answer !==
                                    ''
                            );

                    charts += `
                        <div class="bg-white border rounded-2xl p-5">

                            <div class="font-bold">
                                ${App.Util.escape(
                                    question.text
                                )}
                            </div>

                            <div class="text-xs text-slate-400 mt-1">
                                自由記述
                            </div>

                            <div class="mt-5 max-h-80 overflow-auto space-y-3">

                                ${
                                    posts.length
                                        ? posts.map(
                                            item => `
                                                <div class="border-l-4 border-indigo-200 pl-4">

                                                    <div class="text-xs text-slate-500">
                                                        ${App.Util.escape(
                                                            item.response.company
                                                        )}
                                                        /
                                                        ${App.Util.escape(
                                                            item.response.name
                                                        )}
                                                    </div>

                                                    <div class="mt-1 whitespace-pre-wrap">
                                                        ${App.Util.escape(
                                                            Array.isArray(
                                                                item.answer
                                                            )
                                                                ? item.answer.join('、')
                                                                : item.answer
                                                        )}
                                                    </div>

                                                </div>
                                            `
                                        ).join('')
                                        : `
                                            <div class="text-slate-400 text-sm">
                                                回答データはありません。
                                            </div>
                                        `
                                }

                            </div>
                        </div>
                    `;

                    return;
                }

                const counts = {};

                (
                    question.options ||
                    []
                ).forEach(
                    option => {
                        counts[
                            option
                        ] = 0;
                    }
                );

                responses.forEach(
                    response => {

                        const answer =
                            response.answers
                                ?.[
                                    question.id
                                ];

                        if (
                            Array.isArray(
                                answer
                            )
                        ) {

                            answer.forEach(
                                value => {

                                    if (
                                        counts[
                                            value
                                        ] !==
                                        undefined
                                    ) {
                                        counts[
                                            value
                                        ]++;
                                    }
                                }
                            );

                        } else if (
                            answer
                        ) {

                            if (
                                counts[
                                    answer
                                ] !==
                                undefined
                            ) {
                                counts[
                                    answer
                                ]++;
                            }
                        }
                    }
                );

                const total =
                    responses.length ||
                    1;

                charts += `
                    <div class="bg-white border rounded-2xl p-5">

                        <div class="font-bold">
                            ${App.Util.escape(
                                question.text
                            )}
                        </div>

                        <div class="text-xs text-slate-400 mt-1">
                            ${App.Util.typeText(
                                question.type
                            )}
                        </div>

                        <div class="mt-5 space-y-4">

                            ${
                                Object.entries(
                                    counts
                                )
                                .map(
                                    ([option, count]) => {

                                        const percent =
                                            count /
                                            total *
                                            100;

                                        return `
                                            <div>

                                                <div class="flex justify-between text-sm mb-1">
                                                    <span>
                                                        ${App.Util.escape(
                                                            option
                                                        )}
                                                    </span>

                                                    <span class="font-semibold">
                                                        ${count}件 /
                                                        ${percent.toFixed(1)}%
                                                    </span>
                                                </div>

                                                <div class="h-3 bg-slate-100 rounded-full overflow-hidden">

                                                    <div
                                                        class="h-full bg-indigo-500"
                                                        style="width:${Math.min(percent,100)}%"
                                                    ></div>

                                                </div>

                                            </div>
                                        `;
                                    }
                                ).join('')
                            }

                        </div>

                    </div>
                `;
            }
        );

        const filter =
            App.State.responseFilter
                .toLowerCase()
                .trim();

        const responseRows =
            responses
                .filter(
                    response => {

                        if (!filter) {
                            return true;
                        }

                        return (
                            String(
                                response.company ||
                                ''
                            )
                            .toLowerCase()
                            .includes(
                                filter
                            ) ||
                            String(
                                response.name ||
                                ''
                            )
                            .toLowerCase()
                            .includes(
                                filter
                            )
                        );
                    }
                )
                .map(
                    response => `
                        <tr class="border-b">

                            <td class="px-4 py-3">
                                ${App.Util.escape(
                                    response.answered_at
                                )}
                            </td>

                            <td class="px-4 py-3">
                                <div class="font-semibold">
                                    ${App.Util.escape(
                                        response.company
                                    )}
                                </div>
                                <div>
                                    ${App.Util.escape(
                                        response.name
                                    )}
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                ${App.Util.escape(
                                    response.email
                                )}
                            </td>

                            <td class="px-4 py-3">
                                <button
                                    onclick="App.actions.showResponse('${App.Util.escapeAttr(response.id)}')"
                                    class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs"
                                >
                                    全回答を表示
                                </button>
                            </td>

                        </tr>
                    `
                )
                .join('');

        return `
            <section>

                <div class="flex items-start justify-between gap-4 mb-6">

                    <div>

                        <button
                            onclick="App.actions.goSurveys()"
                            class="text-sm text-indigo-600"
                        >
                            ← アンケート一覧
                        </button>

                        <div class="text-sm text-indigo-600 font-semibold mt-3">
                            ANALYTICS
                        </div>

                        <h1 class="text-2xl font-bold mt-1">
                            ${App.Util.escape(
                                survey.title
                            )}
                        </h1>

                    </div>

                    <div class="flex gap-2">

                        <a
                            href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
                            class="px-4 py-2.5 rounded-xl bg-white border text-sm font-semibold"
                        >
                            CSV出力
                        </a>

                        <button
                            onclick="window.print()"
                            class="px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold"
                        >
                            PDF / 印刷
                        </button>

                    </div>

                </div>

                <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">

                    ${[
                        [
                            '送信対象者数',
                            targets.length,
                            '人'
                        ],
                        [
                            '回答数',
                            responses.length,
                            '件'
                        ],
                        [
                            '未登録顧客からの回答数',
                            webResponses,
                            '件'
                        ],
                        [
                            '未回答数',
                            unanswered,
                            '人'
                        ],
                        [
                            '回答率',
                            rate,
                            '%'
                        ]
                    ]
                    .map(
                        card => `
                            <div class="bg-white border rounded-2xl p-5">

                                <div class="text-xs text-slate-500">
                                    ${card[0]}
                                </div>

                                <div class="text-2xl font-bold mt-2">
                                    ${card[1]}
                                    <span class="text-sm font-medium">
                                        ${card[2]}
                                    </span>
                                </div>

                            </div>
                        `
                    )
                    .join('')}

                </div>

                <div class="bg-white border rounded-2xl p-5 mb-5">

                    <div class="flex items-center justify-between mb-4">

                        <h2 class="font-bold">
                            設問絞り込み
                        </h2>

                        <div class="flex gap-3">

                            <button
                                onclick="App.actions.selectAllQuestions(true)"
                                class="text-xs text-indigo-600"
                            >
                                全選択
                            </button>

                            <button
                                onclick="App.actions.selectAllQuestions(false)"
                                class="text-xs text-slate-500"
                            >
                                全解除
                            </button>

                        </div>

                    </div>

                    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-3">
                        ${questionChecks}
                    </div>

                </div>

                ${
                    responses.length === 0
                        ? `
                            <div class="bg-white border rounded-2xl p-12 text-center text-slate-400 mb-5">
                                現在、回答データはありません
                            </div>
                        `
                        : `
                            <div class="grid lg:grid-cols-2 gap-5 mb-5">
                                ${charts}
                            </div>
                        `
                }

                <div class="bg-white border rounded-2xl overflow-hidden">

                    <div class="p-5 border-b">

                        <h2 class="font-bold">
                            個別回答一覧
                        </h2>

                        <input
                            id="response_filter"
                            value="${App.Util.escapeAttr(App.State.responseFilter)}"
                            oninput="App.actions.responseSearch(this.value)"
                            placeholder="会社名・氏名で検索"
                            class="mt-3 w-full border rounded-xl px-4 py-2.5"
                        >

                    </div>

                    <div class="overflow-x-auto">

                        <table class="w-full text-left">

                            <thead class="bg-slate-50 text-xs text-slate-500">

                                <tr>
                                    <th class="px-4 py-3">
                                        回答日時
                                    </th>
                                    <th class="px-4 py-3">
                                        会社名 / 氏名
                                    </th>
                                    <th class="px-4 py-3">
                                        メール
                                    </th>
                                    <th class="px-4 py-3">
                                        詳細
                                    </th>
                                </tr>

                            </thead>

                            <tbody>
                                ${
                                    responseRows ||
                                    `
                                    <tr>
                                        <td colspan="4" class="p-10 text-center text-slate-400">
                                            回答データがありません。
                                        </td>
                                    </tr>
                                    `
                                }
                            </tbody>

                        </table>

                    </div>

                </div>

            </section>

            <div
                id="response_modal"
                class="hidden fixed inset-0 z-50 bg-slate-900/50 p-4 md:p-8"
            >

                <div class="max-w-3xl mx-auto bg-white rounded-2xl shadow-xl max-h-full overflow-hidden flex flex-col">

                    <div class="p-5 border-b flex justify-between">

                        <h2 class="font-bold">
                            回答詳細
                        </h2>

                        <button
                            onclick="App.actions.closeResponse()"
                            class="px-3 py-1.5 rounded-lg bg-slate-100"
                        >
                            閉じる
                        </button>

                    </div>

                    <div
                        id="response_detail"
                        class="p-5 overflow-auto"
                    ></div>

                </div>

            </div>
        `;
    };

/* ================================================================
 * settings
 * ================================================================ */

App.Render.settings =
    function() {

        const defaults = {
            subdomain: '',
            login_name: '',
            password: '',
            app_id: '',
            ssl_verify: false,
            proxy: '',
            field_company: '',
            field_name: '',
            field_email: '',
            field_department: '',
            field_phone: '',
            field_address: []
        };

        const settings =
            Object.assign(
                {},
                defaults,
                App.State.data.settings ||
                {}
            );

        const fields =
            App.State.kintoneFields ||
            [];

        const optionHtml =
            function(
                selected
            ) {

                return `
                    <option value="">
                        -- 選択してください --
                    </option>

                    ${
                        fields.map(
                            field => `
                                <option
                                    value="${App.Util.escapeAttr(field.code)}"
                                    ${selected === field.code ? 'selected' : ''}
                                >
                                    ${App.Util.escape(
                                        field.label
                                    )}
                                    (${App.Util.escape(
                                        field.code
                                    )})
                                </option>
                            `
                        ).join('')
                    }
                `;
            };

        return `
            <section>

                <div class="mb-6">

                    <div class="text-sm text-indigo-600 font-semibold">
                        SYSTEM SETTINGS
                    </div>

                    <h1 class="text-2xl font-bold mt-1">
                        kintone連携設定
                    </h1>

                    <p class="text-sm text-slate-500 mt-1">
                        kintoneの顧客管理アプリとアンケート顧客情報を連携します。
                    </p>

                </div>

                <div class="bg-white border rounded-2xl shadow-sm p-6">

                    <div id="settings_form" class="space-y-6">

                        <div class="grid md:grid-cols-2 gap-5">

                            <div>

                                <label class="block text-sm font-semibold mb-1">
                                    サブドメイン
                                </label>

                                <input
                                    id="setting_subdomain"
                                    value="${App.Util.escapeAttr(settings.subdomain)}"
                                    placeholder="xxxx または xxxx.cybozu.com"
                                    class="w-full border rounded-xl px-4 py-3"
                                >

                                <p class="text-xs text-slate-400 mt-1">
                                    xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com のいずれも入力可能です。
                                </p>

                            </div>

                            <div>

                                <label class="block text-sm font-semibold mb-1">
                                    顧客管理アプリID
                                </label>

                                <input
                                    id="setting_app_id"
                                    value="${App.Util.escapeAttr(settings.app_id)}"
                                    class="w-full border rounded-xl px-4 py-3"
                                >

                            </div>

                            <div>

                                <label class="block text-sm font-semibold mb-1">
                                    ログイン名
                                </label>

                                <input
                                    id="setting_login_name"
                                    value="${App.Util.escapeAttr(settings.login_name)}"
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
                                    value="${App.Util.escapeAttr(settings.proxy)}"
                                    placeholder="proxy.example.local:8080"
                                    class="w-full border rounded-xl px-4 py-3"
                                >

                            </div>

                            <div class="flex items-end pb-3">

                                <label class="flex items-center gap-2 text-sm">

                                    <input
                                        id="setting_ssl_verify"
                                        type="checkbox"
                                        ${settings.ssl_verify ? 'checked' : ''}
                                        class="w-4 h-4"
                                    >

                                    SSL証明書検証を有効にする

                                </label>

                            </div>

                        </div>

                        <div class="border-t pt-6">

                            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">

                                <div>

                                    <h2 class="font-bold">
                                        kintone項目マッピング
                                    </h2>

                                    <p
                                        id="field_message"
                                        class="text-xs text-slate-400 mt-1"
                                    >
                                        ${
                                            fields.length
                                                ? fields.length +
                                                  '項目を取得済み'
                                                : '項目一覧を取得してください。'
                                        }
                                    </p>

                                </div>

                                <button
                                    onclick="App.actions.fetchKintoneFields()"
                                    class="px-4 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold"
                                >
                                    項目一覧を再取得
                                </button>

                            </div>

                            <div class="grid md:grid-cols-2 gap-5 mt-5">

                                ${[
                                    [
                                        'field_company',
                                        '会社名 (Company)',
                                        settings.field_company
                                    ],
                                    [
                                        'field_name',
                                        '氏名 (Name)',
                                        settings.field_name
                                    ],
                                    [
                                        'field_email',
                                        'メールアドレス (Email)',
                                        settings.field_email
                                    ],
                                    [
                                        'field_department',
                                        '部署名 (Department)',
                                        settings.field_department
                                    ],
                                    [
                                        'field_phone',
                                        '電話番号 (Phone)',
                                        settings.field_phone
                                    ]
                                ]
                                .map(
                                    item => `
                                        <div>

                                            <label class="block text-sm font-semibold mb-1">
                                                ${item[1]}
                                            </label>

                                            <select
                                                id="${item[0]}"
                                                class="w-full border rounded-xl px-4 py-3 bg-white"
                                            >
                                                ${optionHtml(
                                                    item[2]
                                                )}
                                            </select>

                                        </div>
                                    `
                                )
                                .join('')}

                                <div>

                                    <label class="block text-sm font-semibold mb-1">
                                        住所 (Address)
                                    </label>

                                    <select
                                        id="field_address"
                                        multiple
                                        class="w-full border rounded-xl px-4 py-3 bg-white min-h-[160px]"
                                    >

                                        ${
                                            fields.map(
                                                field => `
                                                    <option
                                                        value="${App.Util.escapeAttr(field.code)}"
                                                        ${
                                                            (
                                                                settings.field_address ||
                                                                []
                                                            )
                                                            .includes(
                                                                field.code
                                                            )
                                                                ? 'selected'
                                                                : ''
                                                        }
                                                    >
                                                        ${App.Util.escape(
                                                            field.label
                                                        )}
                                                        (${App.Util.escape(
                                                            field.code
                                                        )})
                                                    </option>
                                                `
                                            ).join('')
                                        }

                                    </select>

                                    <p class="text-xs text-slate-400 mt-1">
                                        Ctrl / Command を使用して複数フィールドを選択できます。
                                    </p>

                                </div>

                            </div>

                        </div>

                        <div class="border-t pt-5 flex justify-end">

                            <button
                                onclick="App.actions.saveSettings()"
                                class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-semibold"
                            >
                                設定を保存
                            </button>

                        </div>

                    </div>

                </div>

            </section>
        `;
    };

/* ================================================================
 * initialization
 * ================================================================ */

App.init =
    async function() {

        if (
            App.State.initialized
        ) {
            return;
        }

        App.State.initialized =
            true;

        const app =
            document.getElementById(
                'app'
            );

        if (app) {
            app.innerHTML = `
                <div class="min-h-screen flex items-center justify-center">

                    <div class="text-center">

                        <div class="w-10 h-10 border-4 border-slate-200 border-t-indigo-600 rounded-full animate-spin mx-auto"></div>

                        <div class="mt-4 text-sm text-slate-500">
                            読み込み中...
                        </div>

                    </div>

                </div>
            `;
        }

        try {

            await App.API.load();

            App.Render.main();

        } catch (error) {

            if (app) {

                app.innerHTML = `
                    <div class="min-h-screen flex items-center justify-center p-6">

                        <div class="bg-white border rounded-2xl p-8 max-w-lg text-center shadow-sm">

                            <div class="text-rose-600 font-bold text-lg">
                                読み込みエラー
                            </div>

                            <p class="text-slate-500 mt-2">
                                ${App.Util.escape(
                                    error.message
                                )}
                            </p>

                            <button
                                onclick="location.reload()"
                                class="mt-5 px-5 py-2.5 bg-indigo-600 text-white rounded-xl"
                            >
                                再読み込み
                            </button>

                        </div>

                    </div>
                `;
            }
        }
    };

/*
 * PHP/JSの評価タイミングに関係なく
 * 初期化を1回だけ実行。
 */
if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.init();
        },
        {
            once: true
        }
    );

} else {

    App.init();
}
</script>

</body>
</html>
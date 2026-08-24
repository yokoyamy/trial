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

declare(strict_types=1);

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

function surveyapp_default_data(): array
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

function surveyapp_load(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        $data = surveyapp_default_data();
        surveyapp_save($data);
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        $data = surveyapp_default_data();
    }

    $defaults = surveyapp_default_data();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    if (!is_array($data['surveys'])) {
        $data['surveys'] = [];
    }
    if (!is_array($data['responses'])) {
        $data['responses'] = [];
    }
    if (!is_array($data['customers'])) {
        $data['customers'] = [];
    }
    if (!is_array($data['mail_logs'])) {
        $data['mail_logs'] = [];
    }

    return $data;
}

function surveyapp_save(array $data): bool
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
        @unlink(SURVEY_STORAGE_FILE);
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function surveyapp_json(array $payload, int $status = 200): never
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

function surveyapp_id(string $prefix = 'id'): string
{
    try {
        return $prefix . '_' . bin2hex(random_bytes(10));
    } catch (Throwable) {
        return $prefix . '_' . uniqid('', true);
    }
}

function surveyapp_now(): string
{
    return date('Y-m-d H:i:s');
}

function surveyapp_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function surveyapp_check_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals(surveyapp_csrf(), $token)) {
        surveyapp_json([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

/*
 * kintoneの入力を必ずFQDNへ正規化する。
 *
 * 対応:
 * xxxx
 * xxxx.cybozu.com
 * https://xxxx.cybozu.com
 * https://xxxx.cybozu.com/
 */
function surveyapp_kintone_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('#^\s*https?://#i', '', $value) ?? $value;

    $value = preg_replace('#[/?#].*$#', '', $value) ?? $value;

    $value = trim($value, " \t\n\r\0\x0B.");

    if ($value === '') {
        return '';
    }

    if (preg_match('/\.cybozu\.com$/i', $value)) {
        return strtolower($value);
    }

    return strtolower($value . '.cybozu.com');
}

function surveyapp_http_status(): int
{
    $headers = [];

    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
    }

    if (!is_array($headers)) {
        return 0;
    }

    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/i', $header, $m)) {
            return (int)$m[1];
        }
    }

    return 0;
}

function surveyapp_kintone_request(
    string $method,
    string $path,
    array $settings,
    ?array $body = null
): array {
    $rawHost = (string)($settings['subdomain'] ?? '');
    $host = surveyapp_kintone_host($rawHost);

    if ($host === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'kintoneのサブドメインを入力してください。',
            'endpoint' => ''
        ];
    }

    $path = '/' . ltrim($path, '/');
    $endpoint = 'https://' . $host . $path;

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'kintoneのログイン名とパスワードを入力してください。',
            'endpoint' => $endpoint
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
                'status' => 0,
                'message' => 'kintoneリクエストのJSON生成に失敗しました。',
                'endpoint' => $endpoint
            ];
        }

        $options['http']['content'] = $encodedBody;
    }

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match('/^[^:\s]+:\d+$/', $proxy)) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'Proxyサーバは「host名:port番号」形式で入力してください。',
                'endpoint' => $endpoint
            ];
        }

        $options['http']['proxy'] = 'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $result = @file_get_contents(
        $endpoint,
        false,
        $context
    );

    $status = surveyapp_http_status();

    $decoded = json_decode((string)$result, true);

    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'status' => $status,
            'message' => '',
            'endpoint' => $endpoint,
            'data' => $decoded
        ];
    }

    $message = (string)($decoded['message'] ?? '');

    if ($message === '') {
        if ($status === 401) {
            $message = 'kintone認証に失敗しました。ログイン名、パスワード、接続先を確認してください。';
        } elseif ($status === 403) {
            $message = 'kintone APIへのアクセスが拒否されました。権限を確認してください。';
        } elseif ($status === 404) {
            $message = 'kintone APIエンドポイントが見つかりません。接続先を確認してください。';
        } elseif ($status === 0) {
            $message = 'kintoneへ接続できませんでした。サーバのネットワーク、Proxy、SSL設定を確認してください。';
        } else {
            $message = 'kintone API通信に失敗しました。';
        }
    }

    return [
        'ok' => false,
        'status' => $status,
        'message' => $message,
        'endpoint' => $endpoint,
        'data' => $decoded
    ];
}

function surveyapp_find_survey(array $data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }

    return null;
}

function surveyapp_public_url(string $surveyId, string $customerId = ''): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    $base = rtrim(
        str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')),
        '/'
    );

    $url = $scheme . '://' . $host .
        ($base === '/' ? '' : $base) .
        '/?public=1&survey_id=' .
        rawurlencode($surveyId);

    if ($customerId !== '') {
        $url .= '&customer_id=' . rawurlencode($customerId);
    }

    return $url;
}

/* ================================================================
 * API
 * ================================================================ */

$action = (string)($_REQUEST['action'] ?? '');

if ($action !== '') {
    $data = surveyapp_load();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        surveyapp_check_csrf();
    }

    switch ($action) {

        case 'load':
            surveyapp_json([
                'ok' => true,
                'data' => $data,
                'csrf_token' => surveyapp_csrf()
            ]);
            break;

        case 'save_survey':
            $survey = json_decode(
                (string)($_POST['survey_json'] ?? ''),
                true
            );

            if (!is_array($survey) || empty($survey['id'])) {
                surveyapp_json([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $now = surveyapp_now();
            $found = false;

            foreach ($data['surveys'] as $index => $existing) {
                if ((string)($existing['id'] ?? '') === (string)$survey['id']) {
                    $survey['created_at'] =
                        $existing['created_at'] ?? $now;

                    $survey['updated_at'] = $now;
                    $survey['deleted'] =
                        !empty($existing['deleted']);

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

            if (!surveyapp_save($data)) {
                surveyapp_json([
                    'ok' => false,
                    'message' => 'データ保存に失敗しました。'
                ], 500);
            }

            surveyapp_json([
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
                surveyapp_json([
                    'ok' => false,
                    'message' => '不正なステータスです。'
                ], 400);
            }

            foreach ($data['surveys'] as &$survey) {
                if ((string)($survey['id'] ?? '') === $surveyId) {
                    $survey['status'] = $status;
                    $survey['updated_at'] = surveyapp_now();
                    break;
                }
            }

            unset($survey);

            surveyapp_save($data);
            surveyapp_json(['ok' => true]);
            break;

        case 'delete_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {
                if ((string)($survey['id'] ?? '') === $surveyId) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = surveyapp_now();
                    break;
                }
            }

            unset($survey);

            surveyapp_save($data);
            surveyapp_json(['ok' => true]);
            break;

        case 'save_settings':
            $settings = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($settings)) {
                surveyapp_json([
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

            /*
             * 保存時点でも正規化する。
             * これにより「FQDNで保存したら次回接続時に二重付加」
             * という問題も防止する。
             */
            $settings['subdomain'] =
                surveyapp_kintone_host(
                    (string)($settings['subdomain'] ?? '')
                );

            $data['settings'] = array_merge(
                surveyapp_default_data()['settings'],
                $settings
            );

            if (!surveyapp_save($data)) {
                surveyapp_json([
                    'ok' => false,
                    'message' => '設定保存に失敗しました。'
                ], 500);
            }

            surveyapp_json([
                'ok' => true,
                'settings' => $data['settings']
            ]);
            break;

        case 'kintone_fields':
            $settings = $data['settings'];

            if (
                isset($_POST['app_id']) &&
                trim((string)$_POST['app_id']) !== ''
            ) {
                $settings['app_id'] =
                    trim((string)$_POST['app_id']);
            }

            $appId = trim(
                (string)($settings['app_id'] ?? '')
            );

            if ($appId === '') {
                surveyapp_json([
                    'ok' => false,
                    'message' => 'アプリIDを入力してください。'
                ], 400);
            }

            $result = surveyapp_kintone_request(
                'GET',
                '/k/v1/app/form/fields.json?app=' .
                rawurlencode($appId),
                $settings
            );

            if (!$result['ok']) {
                surveyapp_json([
                    'ok' => false,
                    'status' => $result['status'] ?? 0,
                    'message' => $result['message'] ?? '',
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
                 * 顧客情報として使える一般的な文字列系を取得。
                 * ADDRESSについては複数フィールドを選択可能にするため、
                 * JS側で複数選択UIを提供する。
                 */
                if (in_array($type, [
                    'SINGLE_LINE_TEXT',
                    'MULTI_LINE_TEXT',
                    'LINK',
                    'NUMBER',
                    'DROP_DOWN',
                    'RADIO_BUTTON',
                    'DATE',
                    'DATETIME',
                    'TIME'
                ], true)) {
                    $fields[] = [
                        'label' =>
                            (string)($field['label'] ?? $code),
                        'code' => (string)$code,
                        'type' => $type
                    ];
                }
            }

            surveyapp_json([
                'ok' => true,
                'fields' => $fields
            ]);
            break;

        case 'kintone_customers':
            $settings = $data['settings'];
            $appId = trim(
                (string)($settings['app_id'] ?? '')
            );

            if ($appId === '') {
                surveyapp_json([
                    'ok' => false,
                    'message' => '顧客管理アプリIDを設定してください。'
                ], 400);
            }

            $fields = [
                $settings['field_company'] ?? '',
                $settings['field_name'] ?? '',
                $settings['field_email'] ?? '',
                $settings['field_department'] ?? '',
                $settings['field_phone'] ?? ''
            ];

            foreach (($settings['field_address'] ?? []) as $field) {
                $fields[] = $field;
            }

            $fields = array_values(
                array_unique(
                    array_filter(
                        array_map('strval', $fields)
                    )
                )
            );

            if (!$fields) {
                surveyapp_json([
                    'ok' => false,
                    'message' => 'kintone項目マッピングを設定してください。'
                ], 400);
            }

            $query = [
                'app' => $appId,
                'fields' => $fields,
                'totalCount' => true
            ];

            $result = surveyapp_kintone_request(
                'GET',
                '/k/v1/records.json?' .
                http_build_query(
                    $query,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                ),
                $settings
            );

            if (!$result['ok']) {
                surveyapp_json([
                    'ok' => false,
                    'status' => $result['status'] ?? 0,
                    'message' => $result['message'] ?? '',
                    'endpoint' => $result['endpoint'] ?? ''
                ], 400);
            }

            $records = $result['data']['records'] ?? [];

            foreach ($records as $record) {
                $get = static function (
                    array $record,
                    string $code
                ): string {
                    if ($code === '') {
                        return '';
                    }

                    $value = $record[$code]['value'] ?? '';

                    if (is_array($value)) {
                        return implode(
                            '、',
                            array_map(
                                static fn($v): string =>
                                    is_array($v)
                                        ? (string)($v['value'] ?? '')
                                        : (string)$v,
                                $value
                            )
                        );
                    }

                    return (string)$value;
                };

                $email = $get(
                    $record,
                    (string)($settings['field_email'] ?? '')
                );

                if ($email === '') {
                    continue;
                }

                $customer = [
                    'id' => surveyapp_id('customer'),
                    'company' => $get(
                        $record,
                        (string)($settings['field_company'] ?? '')
                    ),
                    'name' => $get(
                        $record,
                        (string)($settings['field_name'] ?? '')
                    ),
                    'email' => $email,
                    'department' => $get(
                        $record,
                        (string)($settings['field_department'] ?? '')
                    ),
                    'phone' => $get(
                        $record,
                        (string)($settings['field_phone'] ?? '')
                    ),
                    'address' => '',
                    'source' => 'kintone',
                    'sent_at' => '',
                    'send_count' => 0,
                    'answer_status' => 'unanswered',
                    'kintone_status' => 'registered'
                ];

                $addressValues = [];

                foreach (
                    ($settings['field_address'] ?? [])
                    as $addressCode
                ) {
                    $v = $get($record, (string)$addressCode);

                    if ($v !== '') {
                        $addressValues[] = $v;
                    }
                }

                $customer['address'] =
                    implode(' ', $addressValues);

                $found = false;

                foreach ($data['customers'] as $idx => $old) {
                    if (
                        strtolower(
                            (string)($old['email'] ?? '')
                        ) === strtolower($email)
                    ) {
                        $customer['id'] =
                            $old['id'] ?? $customer['id'];
                        $customer['sent_at'] =
                            $old['sent_at'] ?? '';
                        $customer['send_count'] =
                            $old['send_count'] ?? 0;
                        $customer['answer_status'] =
                            $old['answer_status'] ?? 'unanswered';

                        $data['customers'][$idx] =
                            $customer;

                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $data['customers'][] = $customer;
                }
            }

            surveyapp_save($data);

            surveyapp_json([
                'ok' => true,
                'count' => count($records),
                'data' => $data
            ]);
            break;

        case 'send_mail':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            $recipientIds = json_decode(
                (string)($_POST['recipient_ids'] ?? '[]'),
                true
            );

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            $subject = trim(
                (string)($_POST['mail_subject'] ?? '')
            );

            $body = (string)($_POST['mail_body'] ?? '');

            $templateType =
                (string)($_POST['template_type'] ?? 'initial');

            if ($subject === '' || $body === '') {
                surveyapp_json([
                    'ok' => false,
                    'message' => '件名と本文を入力してください。'
                ], 400);
            }

            $survey =
                surveyapp_find_survey($data, $surveyId);

            if ($survey === null) {
                surveyapp_json([
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
                        array_map('strval', $recipientIds),
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

                $email = trim(
                    (string)($customer['email'] ?? '')
                );

                if ($email === '') {
                    continue;
                }

                $url = surveyapp_public_url(
                    $surveyId,
                    (string)$customer['id']
                );

                $replace = [
                    '{顧客名}' =>
                        (string)($customer['name'] ?? ''),
                    '{アンケートURL}' => $url
                ];

                $finalSubject =
                    strtr($subject, $replace);

                $finalBody =
                    strtr($body, $replace);

                $sent = @mail(
                    $email,
                    '=?UTF-8?B?' .
                    base64_encode($finalSubject) .
                    '?=',
                    $finalBody,
                    "Content-Type: text/plain; charset=UTF-8\r\n" .
                    "From: survey-system@localhost\r\n"
                );

                $customer['sent_at'] = surveyapp_now();

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
                'id' => surveyapp_id('mail'),
                'survey_id' => $surveyId,
                'sent_at' => surveyapp_now(),
                'template_type' => $templateType,
                'count' => $count,
                'subject' => $subject,
                'messages' => $messages,
                'executor' => 'admin'
            ];

            surveyapp_save($data);

            surveyapp_json([
                'ok' => true,
                'count' => $count
            ]);
            break;

        case 'register_customer':
            $customerId =
                (string)($_POST['customer_id'] ?? '');

            foreach ($data['customers'] as &$customer) {
                if (
                    (string)($customer['id'] ?? '')
                    === $customerId
                ) {
                    $customer['kintone_status'] =
                        'registered';
                    break;
                }
            }

            unset($customer);

            surveyapp_save($data);

            surveyapp_json(['ok' => true]);
            break;

        case 'csv':
            $surveyId =
                (string)($_GET['survey_id'] ?? '');

            $survey =
                surveyapp_find_survey($data, $surveyId);

            if ($survey === null) {
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
                    is_array($response['answers'] ?? null)
                        ? $response['answers']
                        : [];

                foreach ($questions as $question) {
                    $qid =
                        (string)($question['id'] ?? '');

                    $answer = $answers[$qid] ?? '';

                    if (is_array($answer)) {
                        $answer = implode('、', $answer);
                    }

                    $row[] = (string)$answer;
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
                    (string)($item['id'] ?? '') === $surveyId &&
                    empty($item['deleted'])
                ) {
                    $survey = $item;
                    break;
                }
            }

            if (
                $survey === null ||
                ($survey['status'] ?? '') !== 'active'
            ) {
                surveyapp_json([
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
                    (string)($customer['id'] ?? '')
                    === $customerId
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
                $email = trim(
                    (string)($_POST['email'] ?? '')
                );

                $name = trim(
                    (string)($_POST['name'] ?? '')
                );

                $company = trim(
                    (string)($_POST['company'] ?? '')
                );

                $customerId =
                    surveyapp_id('customer');

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
                'id' => surveyapp_id('response'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => $company,
                'name' => $name,
                'email' => $email,
                'answered_at' => surveyapp_now(),
                'answers' => $answers
            ];

            $data['responses'][] = $response;

            surveyapp_save($data);

            surveyapp_json([
                'ok' => true,
                'response_id' => $response['id']
            ]);
            break;

        default:
            surveyapp_json([
                'ok' => false,
                'message' => '不明なAPIです。'
            ], 400);
    }
}

/* ================================================================
 * Public answer page
 * ================================================================ */

if (isset($_GET['public'])) {
    $data = surveyapp_load();

    $surveyId =
        (string)($_GET['survey_id'] ?? '');

    $customerId =
        (string)($_GET['customer_id'] ?? '');

    $survey =
        surveyapp_find_survey($data, $surveyId);

    if (
        $survey === null ||
        !empty($survey['deleted']) ||
        ($survey['status'] ?? '') !== 'active'
    ) {
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-100 min-h-screen flex items-center justify-center p-6">
            <div class="bg-white rounded-2xl shadow-sm p-10 max-w-lg w-full text-center">
                <h1 class="text-xl font-bold text-slate-800">
                    アンケートは現在回答できません
                </h1>
                <p class="mt-3 text-slate-500">
                    公開期間または公開状態をご確認ください。
                </p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    $customer = null;

    foreach ($data['customers'] as $item) {
        if (
            $customerId !== '' &&
            (string)($item['id'] ?? '') === $customerId
        ) {
            $customer = $item;
            break;
        }
    }

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= htmlspecialchars((string)$survey['title'], ENT_QUOTES, 'UTF-8') ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-100 min-h-screen text-slate-800">
    <div class="max-w-3xl mx-auto px-4 py-10">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 md:p-10">
            <h1 class="text-2xl font-bold">
                <?= htmlspecialchars((string)$survey['title'], ENT_QUOTES, 'UTF-8') ?>
            </h1>

            <div class="mt-8 space-y-8" id="public_questions"></div>

            <button
                id="public_submit"
                type="button"
                class="mt-8 w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-xl"
            >
                回答を送信する
            </button>
        </div>
    </div>

    <script>
    window.PublicSurvey = <?= json_encode(
        $survey,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    window.PublicCustomer = <?= json_encode(
        $customer,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    window.PublicCsrf = <?= json_encode(
        surveyapp_csrf(),
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

    window.addEventListener('DOMContentLoaded', function () {
        const survey = window.PublicSurvey;
        const root = document.getElementById('public_questions');

        let number = 0;

        (survey.groups || []).forEach(function (group) {
            const groupBox = document.createElement('div');
            groupBox.className = 'space-y-5';

            const heading = document.createElement('h2');
            heading.className =
                'text-lg font-bold border-b border-slate-200 pb-2';
            heading.textContent = group.name || 'グループ';

            groupBox.appendChild(heading);

            (group.questions || []).forEach(function (q) {
                number++;

                const box = document.createElement('div');
                box.className =
                    'border border-slate-200 rounded-xl p-5';

                const label = document.createElement('div');
                label.className = 'font-semibold mb-4';
                label.textContent =
                    'Q' + number + '　' + (q.text || '質問');

                box.appendChild(label);

                if (q.type === 'text') {
                    const input = document.createElement('textarea');
                    input.className =
                        'w-full min-h-28 border border-slate-300 rounded-lg p-3';
                    input.dataset.qid = q.id;
                    input.dataset.required = q.required ? '1' : '0';
                    box.appendChild(input);
                } else {
                    (q.options || []).forEach(function (option, i) {
                        const label2 =
                            document.createElement('label');
                        label2.className =
                            'flex gap-3 items-center py-2';

                        const input =
                            document.createElement('input');

                        input.type =
                            q.type === 'multiple'
                                ? 'checkbox'
                                : 'radio';

                        input.name =
                            'q_' + q.id;

                        input.value = option;
                        input.dataset.qid = q.id;
                        input.className =
                            'w-4 h-4 text-indigo-600';

                        label2.appendChild(input);

                        const text =
                            document.createElement('span');

                        text.textContent = option;

                        label2.appendChild(text);

                        box.appendChild(label2);
                    });
                }

                root.appendChild(groupBox);
                groupBox.appendChild(box);
            });
        });

        document
            .getElementById('public_submit')
            .addEventListener('click', async function () {
                const answers = {};

                document
                    .querySelectorAll('[data-qid]')
                    .forEach(function (input) {
                        const qid = input.dataset.qid;

                        if (input.type === 'checkbox') {
                            if (!answers[qid]) {
                                answers[qid] = [];
                            }

                            if (input.checked) {
                                answers[qid].push(input.value);
                            }
                        } else if (
                            input.type === 'radio'
                        ) {
                            if (input.checked) {
                                answers[qid] =
                                    input.value;
                            }
                        } else {
                            answers[qid] =
                                input.value;
                        }
                    });

                const fd = new FormData();

                fd.append('action', 'public_answer');
                fd.append('csrf_token', window.PublicCsrf);
                fd.append('survey_id', survey.id);
                fd.append(
                    'customer_id',
                    window.PublicCustomer
                        ? (window.PublicCustomer.id || '')
                        : ''
                );
                fd.append('answers', JSON.stringify(answers));

                if (window.PublicCustomer) {
                    fd.append(
                        'company',
                        window.PublicCustomer.company || ''
                    );
                    fd.append(
                        'name',
                        window.PublicCustomer.name || ''
                    );
                    fd.append(
                        'email',
                        window.PublicCustomer.email || ''
                    );
                }

                try {
                    const response =
                        await fetch(
                            location.pathname,
                            {
                                method: 'POST',
                                body: fd
                            }
                        );

                    const json =
                        await response.json();

                    if (!json.ok) {
                        alert(json.message || '送信に失敗しました。');
                        return;
                    }

                    root.innerHTML =
                        '<div class="text-center py-16">' +
                        '<div class="text-4xl">✓</div>' +
                        '<h2 class="text-xl font-bold mt-4">' +
                        '回答ありがとうございました' +
                        '</h2>' +
                        '<p class="text-slate-500 mt-2">' +
                        '回答を正常に受け付けました。' +
                        '</p>' +
                        '</div>';

                    document
                        .getElementById('public_submit')
                        .remove();
                } catch (error) {
                    alert(
                        '通信に失敗しました。ページを再読み込みしてお試しください。'
                    );
                }
            });
    });
    </script>
    </body>
    </html>
    <?php
    exit;
}

/* ================================================================
 * SPA
 * ================================================================ */

header('Content-Type: text/html; charset=UTF-8');

$csrf = surveyapp_csrf();
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

<input
    type="hidden"
    id="csrf_token"
    value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"
>

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

        csrf: <?= json_encode(
            $csrf,
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
        ) ?>,

        screen: 'list',

        editSurvey: null,

        editDirty: false,

        editingSurveyId: '',

        listKeyword: '',

        listStatus: 'all',

        listSort: 'updated_desc',

        responseSurveyId: '',

        responseKeyword: '',

        selectedQuestions: {},

        customerSurveyId: '',

        customerKeyword: '',

        customerStatus: 'all',

        fields: [],

        loading: false,

        previewMode: 'pc',

        modal: null
    },

    api: {},

    render: {},

    actions: {},

    utils: {},

    initDone: false
};


/* ================================================================
 * Utility
 * ================================================================ */

App = window.App;

App.utils.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.utils.clone = function(value) {
    return JSON.parse(JSON.stringify(value));
};

App.utils.id = function(prefix) {
    return prefix + '_' +
        Math.random().toString(36).slice(2) +
        Date.now().toString(36);
};

App.utils.date = function(value) {
    if (!value) return '未設定';

    const d = new Date(
        String(value).replace(' ', 'T')
    );

    if (Number.isNaN(d.getTime())) {
        return String(value);
    }

    return d.toLocaleString('ja-JP', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
};

App.utils.questionCount = function(survey) {
    return (survey.groups || []).reduce(
        function(total, group) {
            return total +
                (group.questions || []).length;
        },
        0
    );
};

App.utils.responsesFor = function(surveyId) {
    return App.State.data.responses.filter(
        function(response) {
            return String(response.survey_id) ===
                String(surveyId);
        }
    );
};

App.utils.findSurvey = function(id) {
    return App.State.data.surveys.find(
        function(survey) {
            return String(survey.id) === String(id);
        }
    );
};

App.utils.allQuestions = function(survey) {
    const result = [];

    (survey.groups || []).forEach(
        function(group) {
            (group.questions || []).forEach(
                function(question) {
                    result.push({
                        question: question,
                        group: group
                    });
                }
            );
        }
    );

    return result;
};

App.utils.statusLabel = function(status) {
    if (status === 'active') return '公開中';
    if (status === 'ended') return '終了';
    return '下書き';
};

App.utils.statusClass = function(status) {
    if (status === 'active') {
        return 'bg-emerald-100 text-emerald-700';
    }

    if (status === 'ended') {
        return 'bg-slate-200 text-slate-600';
    }

    return 'bg-amber-100 text-amber-700';
};

App.utils.questionLabel = function(survey, groupIndex, questionIndex, question) {
    if (survey.numbering_mode === 'group') {
        return 'Q' +
            (groupIndex + 1) +
            '-' +
            (questionIndex + 1);
    }

    let number = 0;

    for (let i = 0; i < groupIndex; i++) {
        number +=
            (survey.groups[i].questions || []).length;
    }

    number += questionIndex + 1;

    return 'Q' + number;
};

App.utils.ensureStructure = function(survey) {
    survey.groups =
        Array.isArray(survey.groups)
            ? survey.groups
            : [];

    survey.groups.forEach(function(group) {
        group.questions =
            Array.isArray(group.questions)
                ? group.questions
                : [];

        group.name =
            group.name || '新しいグループ';
    });

    if (!survey.numbering_mode) {
        survey.numbering_mode = 'global';
    }

    return survey;
};

App.utils.newSurvey = function() {
    return {
        id: App.utils.id('survey'),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [{
            id: App.utils.id('group'),
            name: '基本情報',
            questions: []
        }],
        deleted: false
    };
};

App.utils.newQuestion = function() {
    return {
        id: App.utils.id('question'),
        text: '',
        type: 'single',
        required: false,
        options: [
            '選択肢1',
            '選択肢2'
        ],
        other_enabled: false
    };
};


/* ================================================================
 * API
 * ================================================================ */

App.api.request = async function(action, data, method) {
    method = method || 'POST';

    const options = {
        method: method,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    };

    if (method === 'GET') {
        const params = new URLSearchParams(data || {});
        const url =
            location.pathname +
            '?action=' +
            encodeURIComponent(action) +
            '&' +
            params.toString();

        const response =
            await fetch(url, options);

        return response.json();
    }

    const fd = new FormData();

    fd.append('action', action);
    fd.append(
        'csrf_token',
        App.State.csrf
    );

    Object.keys(data || {}).forEach(
        function(key) {
            let value = data[key];

            if (
                typeof value === 'object' &&
                value !== null
            ) {
                value = JSON.stringify(value);
            }

            fd.append(key, value);
        }
    );

    options.body = fd;

    const response =
        await fetch(location.pathname, options);

    const json =
        await response.json();

    if (!json.ok) {
        throw new Error(
            json.message ||
            'サーバー処理に失敗しました。'
        );
    }

    return json;
};

App.api.load = async function() {
    const result =
        await App.api.request(
            'load',
            {},
            'GET'
        );

    App.State.data = result.data;
    App.State.csrf =
        result.csrf_token ||
        App.State.csrf;
};

App.api.saveSurvey = async function(survey) {
    return App.api.request(
        'save_survey',
        {
            survey_json:
                JSON.stringify(survey)
        }
    );
};

App.api.saveSettings = async function(settings) {
    return App.api.request(
        'save_settings',
        {
            settings_json:
                JSON.stringify(settings)
        }
    );
};


/* ================================================================
 * Layout
 * ================================================================ */

App.render.layout = function(content) {
    return `
        <div class="min-h-screen">
            <header class="sticky top-0 z-30 bg-white border-b border-slate-200">
                <div class="max-w-[1500px] mx-auto px-5 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-bold">
                            A
                        </div>
                        <div>
                            <div class="font-bold">アンケート管理</div>
                            <div class="text-xs text-slate-400">Survey Management System</div>
                        </div>
                    </div>

                    <nav class="flex items-center gap-2">
                        <button
                            class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100"
                            onclick="App.actions.goList()"
                        >
                            アンケート一覧
                        </button>

                        <button
                            class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100"
                            onclick="App.actions.goSettings()"
                        >
                            kintone連携設定
                        </button>

                        <button
                            class="px-3 py-2 rounded-lg text-sm text-slate-500 hover:bg-slate-100"
                            onclick="App.actions.logout()"
                        >
                            ログアウト
                        </button>
                    </nav>
                </div>
            </header>

            <main class="max-w-[1500px] mx-auto p-5">
                ${content}
            </main>
        </div>
    `;
};


/* ================================================================
 * List
 * ================================================================ */

App.render.list = function() {
    let surveys =
        App.State.data.surveys.filter(
            function(survey) {
                return !survey.deleted;
            }
        );

    const keyword =
        App.State.listKeyword.trim().toLowerCase();

    if (keyword) {
        surveys = surveys.filter(
            function(survey) {
                return String(
                    survey.title || ''
                ).toLowerCase().includes(keyword);
            }
        );
    }

    if (App.State.listStatus !== 'all') {
        surveys = surveys.filter(
            function(survey) {
                return survey.status ===
                    App.State.listStatus;
            }
        );
    }

    surveys.sort(
        function(a, b) {
            if (
                App.State.listSort ===
                'updated_asc'
            ) {
                return String(
                    a.updated_at || ''
                ).localeCompare(
                    String(b.updated_at || '')
                );
            }

            if (
                App.State.listSort ===
                'answers_desc'
            ) {
                return App.utils.responsesFor(b.id).length -
                    App.utils.responsesFor(a.id).length;
            }

            if (
                App.State.listSort ===
                'answers_asc'
            ) {
                return App.utils.responsesFor(a.id).length -
                    App.utils.responsesFor(b.id).length;
            }

            if (
                App.State.listSort ===
                'start_desc'
            ) {
                return String(
                    b.start_at || ''
                ).localeCompare(
                    String(a.start_at || '')
                );
            }

            if (
                App.State.listSort ===
                'start_asc'
            ) {
                return String(
                    a.start_at || ''
                ).localeCompare(
                    String(b.start_at || '')
                );
            }

            return String(
                b.updated_at || ''
            ).localeCompare(
                String(a.updated_at || '')
            );
        }
    );

    const rows = surveys.map(
        function(survey) {
            const count =
                App.utils.responsesFor(survey.id).length;

            let buttons = '';

            if (survey.status === 'active') {
                buttons = `
                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-slate-100 hover:bg-slate-200"
                        onclick="App.actions.editSurvey('${survey.id}')">
                        確認・編集
                    </button>

                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100"
                        onclick="App.actions.openResults('${survey.id}')">
                        集計
                    </button>

                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-indigo-600 text-white hover:bg-indigo-700"
                        onclick="App.actions.openMail('${survey.id}')">
                        送信
                    </button>

                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-rose-50 text-rose-700 hover:bg-rose-100"
                        onclick="App.actions.changeStatus('${survey.id}','ended')">
                        停止
                    </button>

                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-slate-100 hover:bg-slate-200"
                        onclick="App.actions.duplicateSurvey('${survey.id}')">
                        複製
                    </button>
                `;
            } else if (survey.status === 'draft') {
                buttons = `
                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-slate-100 hover:bg-slate-200"
                        onclick="App.actions.editSurvey('${survey.id}')">
                        確認・編集
                    </button>

                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-rose-50 text-rose-700 hover:bg-rose-100"
                        onclick="App.actions.deleteSurvey('${survey.id}')">
                        削除
                    </button>

                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-slate-100 hover:bg-slate-200"
                        onclick="App.actions.duplicateSurvey('${survey.id}')">
                        複製
                    </button>
                `;
            } else {
                buttons = `
                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-slate-100 hover:bg-slate-200"
                        onclick="App.actions.editSurvey('${survey.id}')">
                        確認・編集
                    </button>

                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100"
                        onclick="App.actions.openResults('${survey.id}')">
                        集計
                    </button>

                    <button class="px-2.5 py-1.5 text-xs rounded-lg bg-slate-100 hover:bg-slate-200"
                        onclick="App.actions.duplicateSurvey('${survey.id}')">
                        複製
                    </button>
                `;
            }

            return `
                <tr class="border-t border-slate-100 hover:bg-slate-50">
                    <td class="p-4">
                        <div class="text-sm text-slate-700">
                            ${App.utils.escape(
                                survey.created_at || '未保存'
                            )}
                        </div>
                        <div class="text-xs text-slate-400 mt-1">
                            更新:
                            ${App.utils.escape(
                                survey.updated_at || '未保存'
                            )}
                        </div>
                    </td>

                    <td class="p-4">
                        <div class="font-bold">
                            ${App.utils.escape(
                                survey.title || ''
                            )}
                        </div>
                    </td>

                    <td class="p-4 text-sm">
                        ${App.utils.escape(
                            survey.start_at || '未設定'
                        )}
                        <span class="text-slate-400">～</span>
                        ${App.utils.escape(
                            survey.end_at || '未設定'
                        )}
                    </td>

                    <td class="p-4">
                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold ${App.utils.statusClass(survey.status)}">
                            ${App.utils.statusLabel(survey.status)}
                        </span>
                    </td>

                    <td class="p-4 font-semibold">
                        ${count} 件
                    </td>

                    <td class="p-4">
                        <div class="flex flex-wrap gap-1.5">
                            ${buttons}
                        </div>
                    </td>
                </tr>
            `;
        }
    ).join('');

    return App.render.layout(`
        <div class="flex items-center justify-between mb-5">
            <div>
                <h1 class="text-2xl font-bold">アンケート一覧</h1>
                <p class="text-sm text-slate-500 mt-1">
                    アンケートの作成・公開・集計・送信を管理します。
                </p>
            </div>

            <button
                class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-xl shadow-sm"
                onclick="App.actions.newSurvey()"
            >
                ＋ 新規アンケート作成
            </button>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <input
                    class="border border-slate-300 rounded-xl px-3 py-2.5"
                    placeholder="タイトルを検索"
                    value="${App.utils.escape(App.State.listKeyword)}"
                    onkeydown="if(event.key==='Enter') App.actions.searchList(this.value)"
                >

                <select
                    class="border border-slate-300 rounded-xl px-3 py-2.5"
                    onchange="App.actions.toggleStatusFilter(this.value)"
                >
                    <option value="all" ${App.State.listStatus === 'all' ? 'selected' : ''}>すべて</option>
                    <option value="active" ${App.State.listStatus === 'active' ? 'selected' : ''}>公開中</option>
                    <option value="draft" ${App.State.listStatus === 'draft' ? 'selected' : ''}>下書き</option>
                    <option value="ended" ${App.State.listStatus === 'ended' ? 'selected' : ''}>終了</option>
                </select>

                <select
                    class="border border-slate-300 rounded-xl px-3 py-2.5"
                    onchange="App.actions.sortList(this.value)"
                >
                    <option value="updated_desc" ${App.State.listSort === 'updated_desc' ? 'selected' : ''}>更新日：新しい順</option>
                    <option value="updated_asc" ${App.State.listSort === 'updated_asc' ? 'selected' : ''}>更新日：古い順</option>
                    <option value="answers_desc" ${App.State.listSort === 'answers_desc' ? 'selected' : ''}>回答数：多い順</option>
                    <option value="answers_asc" ${App.State.listSort === 'answers_asc' ? 'selected' : ''}>回答数：少ない順</option>
                    <option value="start_desc" ${App.State.listSort === 'start_desc' ? 'selected' : ''}>期間開始：新しい順</option>
                    <option value="start_asc" ${App.State.listSort === 'start_asc' ? 'selected' : ''}>期間開始：古い順</option>
                </select>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="p-4">作成日 / 更新日</th>
                            <th class="p-4">タイトル</th>
                            <th class="p-4">アンケート期間</th>
                            <th class="p-4">ステータス</th>
                            <th class="p-4">回答数</th>
                            <th class="p-4">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows || `
                            <tr>
                                <td colspan="6" class="p-16 text-center text-slate-400">
                                    アンケートはありません。
                                </td>
                            </tr>
                        `}
                    </tbody>
                </table>
            </div>
        </div>
    `);
};


/* ================================================================
 * Editor
 *
 * 重要:
 * ここではDOMをStateへ逆同期しない。
 * Stateが常に正であり、操作はStateを直接変更して再描画する。
 * ================================================================ */

App.render.editor = function() {
    const survey =
        App.State.editSurvey;

    if (!survey) {
        return App.render.list();
    }

    App.utils.ensureStructure(survey);

    const groups =
        survey.groups.map(
            function(group, gi) {
                const questions =
                    group.questions.map(
                        function(q, qi) {
                            return `
                                <div
                                    class="question-item bg-white border border-slate-200 rounded-xl p-4"
                                    data-question-id="${q.id}"
                                >
                                    <div class="flex items-start gap-3">
                                        <div class="drag-question cursor-grab text-slate-400 text-xl pt-1"
                                            title="ドラッグして移動">
                                            ⠿
                                        </div>

                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between gap-3 mb-3">
                                                <div class="flex items-center gap-2">
                                                    <span class="question-number text-xs font-bold px-2 py-1 bg-indigo-50 text-indigo-700 rounded-lg">
                                                        ${App.utils.questionLabel(survey, gi, qi, q)}
                                                    </span>

                                                    <span class="text-xs px-2 py-1 bg-slate-100 rounded-lg">
                                                        ${q.type === 'single' ? '単一選択' : q.type === 'multiple' ? '複数選択' : '自由記述'}
                                                    </span>
                                                </div>

                                                <button
                                                    class="text-rose-500 hover:text-rose-700 text-sm"
                                                    onclick="App.actions.removeQuestion('${q.id}')"
                                                >
                                                    削除
                                                </button>
                                            </div>

                                            <input
                                                class="w-full border border-slate-300 rounded-lg px-3 py-2"
                                                value="${App.utils.escape(q.text || '')}"
                                                placeholder="質問文を入力"
                                                onchange="App.actions.changeQuestionText('${q.id}', this.value)"
                                            >

                                            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <select
                                                    class="border border-slate-300 rounded-lg px-3 py-2"
                                                    onchange="App.actions.changeQuestionType('${q.id}', this.value)"
                                                >
                                                    <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択</option>
                                                    <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                                                    <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述</option>
                                                </select>

                                                <label class="flex items-center gap-2 border border-slate-200 rounded-lg px-3">
                                                    <input
                                                        type="checkbox"
                                                        ${q.required ? 'checked' : ''}
                                                        onchange="App.actions.toggleRequired('${q.id}', this.checked)"
                                                    >
                                                    <span class="text-sm">必須回答</span>
                                                </label>
                                            </div>

                                            ${
                                                q.type !== 'text'
                                                    ? `
                                                        <div class="mt-4">
                                                            <div class="text-xs font-semibold text-slate-500 mb-2">
                                                                選択肢
                                                            </div>

                                                            <div class="space-y-2">
                                                                ${(q.options || []).map(
                                                                    function(option, oi) {
                                                                        return `
                                                                            <div class="flex gap-2">
                                                                                <input
                                                                                    class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                                                                    value="${App.utils.escape(option)}"
                                                                                    onchange="App.actions.changeOption('${q.id}', ${oi}, this.value)"
                                                                                >

                                                                                <button
                                                                                    class="px-3 text-rose-500"
                                                                                    onclick="App.actions.removeOption('${q.id}', ${oi})"
                                                                                >
                                                                                    ×
                                                                                </button>
                                                                            </div>
                                                                        `;
                                                                    }
                                                                ).join('')}
                                                            </div>

                                                            <button
                                                                class="mt-2 text-sm text-indigo-600 hover:text-indigo-800"
                                                                onclick="App.actions.addOption('${q.id}')"
                                                            >
                                                                ＋ 選択肢を追加
                                                            </button>

                                                            <label class="flex items-center gap-2 mt-3 text-sm">
                                                                <input
                                                                    type="checkbox"
                                                                    ${q.other_enabled ? 'checked' : ''}
                                                                    onchange="App.actions.toggleOther('${q.id}', this.checked)"
                                                                >
                                                                その他（自由記述）を許可
                                                            </label>
                                                        </div>
                                                    `
                                                    : ''
                                            }
                                        </div>
                                    </div>
                                </div>
                            `;
                        }
                    ).join('');

                return `
                    <section
                        class="group-item bg-slate-50 border border-slate-200 rounded-2xl p-4"
                        data-group-id="${group.id}"
                    >
                        <div class="flex items-center gap-3 mb-4">
                            <div class="drag-group cursor-grab text-slate-400 text-xl">
                                ⠿
                            </div>

                            <input
                                class="flex-1 bg-transparent border-0 border-b border-slate-300 focus:ring-0 px-1 py-2 font-bold text-lg"
                                value="${App.utils.escape(group.name || '')}"
                                onchange="App.actions.changeGroupName('${group.id}', this.value)"
                            >

                            <button
                                class="text-rose-500 text-sm"
                                onclick="App.actions.removeGroup('${group.id}')"
                            >
                                グループ削除
                            </button>
                        </div>

                        <div
                            class="question-list space-y-3 min-h-12"
                            data-group-id="${group.id}"
                        >
                            ${questions}

                            ${
                                questions === ''
                                    ? `
                                        <div class="empty-question border-2 border-dashed border-slate-200 rounded-xl p-6 text-center text-slate-400 text-sm">
                                            質問がありません。
                                            「質問を追加」から作成してください。
                                        </div>
                                    `
                                    : ''
                            }
                        </div>

                        <button
                            class="mt-4 px-3 py-2 rounded-lg bg-white border border-slate-300 hover:bg-slate-100 text-sm font-semibold"
                            onclick="App.actions.addQuestion('${group.id}')"
                        >
                            ＋ 質問を追加
                        </button>
                    </section>
                `;
            }
        ).join('');

    return App.render.layout(`
        <div class="flex items-center justify-between mb-5 gap-4">
            <div class="flex-1">
                <div class="flex items-center gap-3">
                    <button
                        class="text-slate-500 hover:text-slate-800"
                        onclick="App.actions.cancelEditor()"
                    >
                        ← 一覧へ
                    </button>

                    <input
                        id="survey_title"
                        class="text-2xl font-bold bg-transparent border-0 border-b border-transparent focus:border-indigo-300 focus:ring-0 w-full max-w-2xl"
                        value="${App.utils.escape(survey.title || '')}"
                        onchange="App.actions.changeTitle(this.value)"
                    >
                </div>
            </div>

            <div class="flex gap-2 shrink-0">
                <button
                    class="px-3 py-2 rounded-lg border border-slate-300 bg-white"
                    onclick="App.actions.preview()"
                >
                    プレビュー
                </button>

                <button
                    class="px-4 py-2 rounded-lg bg-indigo-600 text-white font-semibold"
                    onclick="App.actions.saveEditor()"
                >
                    保存して一覧へ戻る
                </button>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label>
                    <div class="text-xs font-semibold text-slate-500 mb-1">
                        開始日時
                    </div>
                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                        value="${App.utils.escape(survey.start_at || '')}"
                        onchange="App.actions.changeStart(this.value)"
                    >
                </label>

                <label>
                    <div class="text-xs font-semibold text-slate-500 mb-1">
                        終了日時
                    </div>
                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                        value="${App.utils.escape(survey.end_at || '')}"
                        onchange="App.actions.changeEnd(this.value)"
                    >
                </label>

                <label>
                    <div class="text-xs font-semibold text-slate-500 mb-1">
                        質問番号
                    </div>

                    <select
                        id="survey_numbering_mode"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                        onchange="App.actions.changeNumberingMode(this.value)"
                    >
                        <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                            Q1, Q2, Q3...
                        </option>
                        <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                            Q1-1, Q1-2...
                        </option>
                    </select>
                </label>
            </div>
        </div>

        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-bold">質問構成</h2>

            <button
                class="px-3 py-2 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 font-semibold text-sm"
                onclick="App.actions.addGroup()"
            >
                ＋ グループ追加
            </button>
        </div>

        <div
            id="question_editor"
            class="space-y-4"
        >
            ${groups}
        </div>
    `);
};


/* ================================================================
 * Editor actions
 * ================================================================ */

App.actions.newSurvey = function() {
    App.State.editSurvey =
        App.utils.newSurvey();

    App.State.editDirty = true;
    App.State.screen = 'editor';

    App.render.current();
    App.actions.initSortables();
};

App.actions.editSurvey = function(id) {
    const survey =
        App.utils.findSurvey(id);

    if (!survey) return;

    App.State.editSurvey =
        App.utils.clone(survey);

    App.utils.ensureStructure(
        App.State.editSurvey
    );

    App.State.editingSurveyId = id;
    App.State.editDirty = false;
    App.State.screen = 'editor';

    App.render.current();
    App.actions.initSortables();
};

App.actions.changeTitle = function(value) {
    if (!App.State.editSurvey) return;

    App.State.editSurvey.title = value;
    App.State.editDirty = true;
};

App.actions.changeStart = function(value) {
    if (!App.State.editSurvey) return;

    App.State.editSurvey.start_at = value;
    App.State.editDirty = true;
};

App.actions.changeEnd = function(value) {
    if (!App.State.editSurvey) return;

    App.State.editSurvey.end_at = value;
    App.State.editDirty = true;
};

App.actions.changeNumberingMode = function(value) {
    if (!App.State.editSurvey) return;

    App.State.editSurvey.numbering_mode =
        value === 'group'
            ? 'group'
            : 'global';

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();
};

App.actions.addGroup = function() {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    /*
     * DOMを読む処理は一切しない。
     * Stateへ直接追加する。
     */
    survey.groups.push({
        id: App.utils.id('group'),
        name: '新しいグループ',
        questions: []
    });

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();

    requestAnimationFrame(function() {
        const editor =
            document.getElementById(
                'question_editor'
            );

        if (editor) {
            editor.lastElementChild
                ?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
        }
    });
};

App.actions.addQuestion = function(groupId) {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    /*
     * 重要修正:
     *
     * 以前はここでDOM→State同期を行っていたため、
     * 新規アンケートの初期状態で質問追加が失敗する
     * ケースがあった。
     *
     * 現在はStateを直接変更する。
     */
    const group =
        survey.groups.find(
            function(item) {
                return String(item.id) ===
                    String(groupId);
            }
        );

    if (!group) {
        alert('追加先グループが見つかりません。');
        return;
    }

    if (!Array.isArray(group.questions)) {
        group.questions = [];
    }

    group.questions.push(
        App.utils.newQuestion()
    );

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();

    requestAnimationFrame(function() {
        const list =
            document.querySelector(
                '.question-list[data-group-id="' +
                CSS.escape(String(groupId)) +
                '"]'
            );

        if (list && list.lastElementChild) {
            list.lastElementChild
                .scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
        }
    });
};

App.actions.removeGroup = function(groupId) {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    const group =
        survey.groups.find(
            function(item) {
                return String(item.id) ===
                    String(groupId);
            }
        );

    if (!group) return;

    if (
        group.questions &&
        group.questions.length > 0 &&
        !confirm(
            'このグループ内の質問もすべて削除されます。よろしいですか？'
        )
    ) {
        return;
    }

    survey.groups =
        survey.groups.filter(
            function(item) {
                return String(item.id) !==
                    String(groupId);
            }
        );

    if (survey.groups.length === 0) {
        survey.groups.push({
            id: App.utils.id('group'),
            name: '基本情報',
            questions: []
        });
    }

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();
};

App.actions.removeQuestion = function(questionId) {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    survey.groups.forEach(
        function(group) {
            group.questions =
                (group.questions || []).filter(
                    function(q) {
                        return String(q.id) !==
                            String(questionId);
                    }
                );
        }
    );

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();
};

App.actions.changeGroupName = function(groupId, value) {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    const group =
        survey.groups.find(
            function(item) {
                return String(item.id) ===
                    String(groupId);
            }
        );

    if (!group) return;

    group.name = value;
    App.State.editDirty = true;
};

App.actions.findQuestion = function(questionId) {
    const survey =
        App.State.editSurvey;

    if (!survey) return null;

    for (const group of survey.groups) {
        const question =
            (group.questions || []).find(
                function(q) {
                    return String(q.id) ===
                        String(questionId);
                }
            );

        if (question) {
            return {
                question: question,
                group: group
            };
        }
    }

    return null;
};

App.actions.changeQuestionText = function(
    questionId,
    value
) {
    const result =
        App.actions.findQuestion(questionId);

    if (!result) return;

    result.question.text = value;
    App.State.editDirty = true;
};

App.actions.changeQuestionType = function(
    questionId,
    value
) {
    const result =
        App.actions.findQuestion(questionId);

    if (!result) return;

    result.question.type =
        ['single', 'multiple', 'text']
            .includes(value)
            ? value
            : 'single';

    if (
        result.question.type !== 'text' &&
        (!Array.isArray(result.question.options) ||
            result.question.options.length === 0)
    ) {
        result.question.options = [
            '選択肢1',
            '選択肢2'
        ];
    }

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();
};

App.actions.toggleRequired = function(
    questionId,
    value
) {
    const result =
        App.actions.findQuestion(questionId);

    if (!result) return;

    result.question.required =
        Boolean(value);

    App.State.editDirty = true;
};

App.actions.toggleOther = function(
    questionId,
    value
) {
    const result =
        App.actions.findQuestion(questionId);

    if (!result) return;

    result.question.other_enabled =
        Boolean(value);

    App.State.editDirty = true;
};

App.actions.changeOption = function(
    questionId,
    index,
    value
) {
    const result =
        App.actions.findQuestion(questionId);

    if (!result) return;

    if (!Array.isArray(result.question.options)) {
        result.question.options = [];
    }

    result.question.options[index] =
        value;

    App.State.editDirty = true;
};

App.actions.addOption = function(questionId) {
    const result =
        App.actions.findQuestion(questionId);

    if (!result) return;

    if (!Array.isArray(result.question.options)) {
        result.question.options = [];
    }

    result.question.options.push(
        '選択肢' +
        (result.question.options.length + 1)
    );

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();
};

App.actions.removeOption = function(
    questionId,
    index
) {
    const result =
        App.actions.findQuestion(questionId);

    if (!result) return;

    if (
        result.question.options.length <= 1
    ) {
        alert(
            '選択肢は最低1つ必要です。'
        );
        return;
    }

    result.question.options.splice(
        index,
        1
    );

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();
};


/* ================================================================
 * SortableJS
 * ================================================================ */

App.actions.sortableInstances = [];

App.actions.destroySortables = function() {
    App.actions.sortableInstances
        .forEach(
            function(instance) {
                try {
                    instance.destroy();
                } catch (e) {}
            }
        );

    App.actions.sortableInstances = [];
};

App.actions.initSortables = function() {
    if (
        typeof Sortable === 'undefined' ||
        !App.State.editSurvey
    ) {
        return;
    }

    App.actions.destroySortables();

    const editor =
        document.getElementById(
            'question_editor'
        );

    if (!editor) return;

    const groupSortable =
        new Sortable(
            editor,
            {
                animation: 180,
                handle: '.drag-group',
                ghostClass: 'opacity-40',
                onEnd: function(event) {
                    if (
                        event.oldIndex ===
                        event.newIndex
                    ) {
                        return;
                    }

                    const groups =
                        App.State.editSurvey.groups;

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

                    App.State.editDirty = true;

                    App.render.current();
                    App.actions.initSortables();
                }
            }
        );

    App.actions.sortableInstances
        .push(groupSortable);

    document
        .querySelectorAll('.question-list')
        .forEach(
            function(list) {
                const groupId =
                    list.dataset.groupId;

                const instance =
                    new Sortable(
                        list,
                        {
                            group: {
                                name: 'survey_questions',
                                pull: true,
                                put: true
                            },

                            animation: 180,

                            handle: '.drag-question',

                            ghostClass: 'opacity-40',

                            onAdd: function(event) {
                                App.actions.moveQuestionByDom(
                                    event.item.dataset.questionId,
                                    groupId
                                );
                            },

                            onEnd: function(event) {
                                if (
                                    event.from !==
                                    event.to
                                ) {
                                    return;
                                }

                                App.actions.reorderQuestion(
                                    groupId,
                                    event.oldIndex,
                                    event.newIndex
                                );
                            }
                        }
                    );

                App.actions.sortableInstances
                    .push(instance);
            }
        );
};

App.actions.moveQuestionByDom = function(
    questionId,
    targetGroupId
) {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    let question = null;

    survey.groups.forEach(
        function(group) {
            const index =
                (group.questions || []).findIndex(
                    function(q) {
                        return String(q.id) ===
                            String(questionId);
                    }
                );

            if (index >= 0) {
                question =
                    group.questions.splice(
                        index,
                        1
                    )[0];
            }
        }
    );

    if (!question) return;

    const target =
        survey.groups.find(
            function(group) {
                return String(group.id) ===
                    String(targetGroupId);
            }
        );

    if (!target) return;

    /*
     * DOM上での追加位置を取得。
     * ただしDOMをState同期の根拠にはせず、
     * 移動した質問だけをStateへ反映する。
     */
    const list =
        document.querySelector(
            '.question-list[data-group-id="' +
            CSS.escape(String(targetGroupId)) +
            '"]'
        );

    let targetIndex =
        target.questions.length;

    if (list) {
        const ids =
            Array.from(
                list.querySelectorAll(
                    '.question-item'
                )
            ).map(
                function(item) {
                    return item.dataset.questionId;
                }
            );

        const index =
            ids.indexOf(String(questionId));

        if (index >= 0) {
            targetIndex = index;
        }
    }

    target.questions.splice(
        targetIndex,
        0,
        question
    );

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();
};

App.actions.reorderQuestion = function(
    groupId,
    oldIndex,
    newIndex
) {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    const group =
        survey.groups.find(
            function(item) {
                return String(item.id) ===
                    String(groupId);
            }
        );

    if (!group) return;

    if (
        oldIndex < 0 ||
        newIndex < 0 ||
        oldIndex >= group.questions.length ||
        newIndex >= group.questions.length
    ) {
        return;
    }

    const moved =
        group.questions.splice(
            oldIndex,
            1
        )[0];

    group.questions.splice(
        newIndex,
        0,
        moved
    );

    App.State.editDirty = true;

    App.render.current();
    App.actions.initSortables();
};


/* ================================================================
 * Save / cancel
 * ================================================================ */

App.actions.saveEditor = async function() {
    const survey =
        App.State.editSurvey;

    if (!survey) return;

    if (
        !String(survey.title || '').trim()
    ) {
        alert('アンケートタイトルを入力してください。');
        return;
    }

    App.State.loading = true;

    try {
        const result =
            await App.api.saveSurvey(
                App.utils.clone(survey)
            );

        const saved =
            result.survey;

        const index =
            App.State.data.surveys.findIndex(
                function(item) {
                    return String(item.id) ===
                        String(saved.id);
                }
            );

        if (index >= 0) {
            App.State.data.surveys[index] =
                saved;
        } else {
            App.State.data.surveys.push(
                saved
            );
        }

        App.State.editSurvey = null;
        App.State.editDirty = false;
        App.State.screen = 'list';

        alert('アンケートを保存しました。');

        App.render.current();
    } catch (error) {
        alert(error.message);
    } finally {
        App.State.loading = false;
    }
};

App.actions.cancelEditor = function() {
    if (
        App.State.editDirty &&
        !confirm(
            '未保存の変更があります。破棄して一覧へ戻りますか？'
        )
    ) {
        return;
    }

    App.State.editSurvey = null;
    App.State.editDirty = false;
    App.State.screen = 'list';

    App.render.current();
};


/* ================================================================
 * List actions
 * ================================================================ */

App.actions.goList = function() {
    if (
        App.State.screen === 'editor' &&
        App.State.editDirty
    ) {
        if (
            !confirm(
                '未保存の変更があります。破棄して一覧へ戻りますか？'
            )
        ) {
            return;
        }
    }

    App.State.editSurvey = null;
    App.State.editDirty = false;
    App.State.screen = 'list';

    App.render.current();
};

App.actions.searchList = function(value) {
    App.State.listKeyword = value || '';
    App.render.current();
};

App.actions.toggleStatusFilter = function(value) {
    App.State.listStatus = value;
    App.render.current();
};

App.actions.sortList = function(value) {
    App.State.listSort = value;
    App.render.current();
};

App.actions.changeStatus = async function(
    surveyId,
    status
) {
    const survey =
        App.utils.findSurvey(surveyId);

    if (!survey) return;

    const message =
        status === 'ended'
            ? 'アンケートを停止しますか？'
            : 'アンケートを再開しますか？';

    if (!confirm(message)) return;

    try {
        await App.api.request(
            'status',
            {
                survey_id: surveyId,
                status: status
            }
        );

        survey.status = status;
        survey.updated_at =
            new Date().toISOString()
                .slice(0, 19)
                .replace('T', ' ');

        App.render.current();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.deleteSurvey = async function(id) {
    if (
        !confirm(
            'このアンケートを削除しますか？'
        )
    ) {
        return;
    }

    try {
        await App.api.request(
            'delete_survey',
            {
                survey_id: id
            }
        );

        const survey =
            App.utils.findSurvey(id);

        if (survey) {
            survey.deleted = true;
        }

        App.render.current();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.duplicateSurvey = async function(id) {
    const survey =
        App.utils.findSurvey(id);

    if (!survey) return;

    const copy =
        App.utils.clone(survey);

    copy.id =
        App.utils.id('survey');

    copy.title =
        (copy.title || 'アンケート') +
        '（複製）';

    copy.status = 'draft';
    copy.created_at = '';
    copy.updated_at = '';
    copy.deleted = false;

    copy.groups =
        (copy.groups || []).map(
            function(group) {
                const newGroup =
                    App.utils.clone(group);

                newGroup.id =
                    App.utils.id('group');

                newGroup.questions =
                    (newGroup.questions || [])
                        .map(
                            function(question) {
                                const q =
                                    App.utils.clone(
                                        question
                                    );

                                q.id =
                                    App.utils.id(
                                        'question'
                                    );

                                return q;
                            }
                        );

                return newGroup;
            }
        );

    try {
        const result =
            await App.api.saveSurvey(copy);

        App.State.data.surveys.push(
            result.survey
        );

        App.render.current();
    } catch (error) {
        alert(error.message);
    }
};


/* ================================================================
 * Preview
 * ================================================================ */

App.actions.preview = function() {
    App.State.modal = 'preview';
    App.render.current();
};

App.render.previewModal = function() {
    const survey =
        App.State.editSurvey;

    if (!survey) return '';

    let content = '';

    survey.groups.forEach(
        function(group, gi) {
            content += `
                <div class="mb-7">
                    <h3 class="font-bold text-lg border-b pb-2 mb-4">
                        ${App.utils.escape(group.name)}
                    </h3>
            `;

            (group.questions || []).forEach(
                function(q, qi) {
                    const label =
                        App.utils.questionLabel(
                            survey,
                            gi,
                            qi,
                            q
                        );

                    content += `
                        <div class="border border-slate-200 rounded-xl p-4 mb-3">
                            <div class="font-semibold mb-3">
                                ${label}
                                ${q.required
                                    ? '<span class="text-rose-500 ml-1">必須</span>'
                                    : ''}
                                <div class="mt-1">
                                    ${App.utils.escape(q.text || '質問文未入力')}
                                </div>
                            </div>
                    `;

                    if (q.type === 'text') {
                        content += `
                            <textarea
                                class="w-full border border-slate-300 rounded-lg p-3"
                                rows="4"
                                placeholder="回答を入力"
                            ></textarea>
                        `;
                    } else {
                        (q.options || []).forEach(
                            function(option) {
                                content += `
                                    <label class="flex items-center gap-2 py-1.5">
                                        <input
                                            type="${q.type === 'multiple' ? 'checkbox' : 'radio'}"
                                            name="preview_${q.id}"
                                        >
                                        ${App.utils.escape(option)}
                                    </label>
                                `;
                            }
                        );

                        if (q.other_enabled) {
                            content += `
                                <label class="flex items-center gap-2 py-1.5">
                                    <input
                                        type="${q.type === 'multiple' ? 'checkbox' : 'radio'}"
                                        name="preview_${q.id}"
                                    >
                                    その他
                                </label>
                                <input
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 mt-1"
                                    placeholder="その他の内容"
                                >
                            `;
                        }
                    }

                    content += `
                        </div>
                    `;
                }
            );

            content += `</div>`;
        }
    );

    return `
        <div
            id="preview_modal"
            class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-5"
        >
            <div class="${
                App.State.previewMode === 'mobile'
                    ? 'w-[390px]'
                    : 'w-full max-w-3xl'
            } max-h-[90vh] overflow-auto bg-white rounded-2xl shadow-2xl">

                <div class="sticky top-0 bg-white border-b p-4 flex items-center justify-between">
                    <div class="font-bold">
                        プレビュー
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            class="px-2.5 py-1.5 text-xs rounded-lg ${App.State.previewMode === 'pc' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100'}"
                            onclick="App.actions.previewMode('pc')"
                        >
                            PC表示
                        </button>

                        <button
                            class="px-2.5 py-1.5 text-xs rounded-lg ${App.State.previewMode === 'mobile' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100'}"
                            onclick="App.actions.previewMode('mobile')"
                        >
                            スマートフォン表示
                        </button>

                        <button
                            class="ml-2 text-slate-500"
                            onclick="App.actions.closeModal()"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <div
                    id="preview_content"
                    class="p-6"
                >
                    <h2 class="text-2xl font-bold mb-6">
                        ${App.utils.escape(survey.title)}
                    </h2>

                    ${content}

                    <button
                        onclick="alert('これはプレビューです。実際には送信されません。')"
                        class="w-full bg-indigo-600 text-white py-3 rounded-xl font-semibold"
                    >
                        回答を送信する
                    </button>
                </div>
            </div>
        </div>
    `;
};

App.actions.previewMode = function(mode) {
    App.State.previewMode =
        mode === 'mobile'
            ? 'mobile'
            : 'pc';

    App.render.current();
};

App.actions.closeModal = function() {
    App.State.modal = null;
    App.render.current();
};


/* ================================================================
 * Settings
 * ================================================================ */

App.actions.goSettings = function() {
    App.State.screen = 'settings';
    App.render.current();
};

App.render.settings = function() {
    const settings =
        App.State.data.settings || {};

    const options =
        App.State.fields.map(
            function(field) {
                return `
                    <option value="${App.utils.escape(field.code)}">
                        ${App.utils.escape(field.label)}
                        [${App.utils.escape(field.code)}]
                    </option>
                `;
            }
        ).join('');

    const select =
        function(id, value, multiple) {
            return `
                <select
                    id="${id}"
                    ${multiple ? 'multiple' : ''}
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 ${multiple ? 'min-h-32' : ''}"
                >
                    ${multiple ? '' : '<option value="">-- 未設定 --</option>'}
                    ${options}
                </select>
            `;
        };

    return App.render.layout(`
        <div class="max-w-5xl">
            <div class="mb-5">
                <h1 class="text-2xl font-bold">
                    kintone連携設定
                </h1>
                <p class="text-sm text-slate-500 mt-1">
                    kintoneの顧客管理アプリとアンケートを連携します。
                </p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-6">
                <form
                    id="settings_form"
                    onsubmit="event.preventDefault(); App.actions.saveSettings()"
                    class="space-y-5"
                >
                    <div>
                        <label class="block text-sm font-semibold mb-2">
                            サブドメイン / FQDN
                        </label>

                        <input
                            id="setting_subdomain"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                            value="${App.utils.escape(settings.subdomain || '')}"
                            placeholder="xxxx または xxxx.cybozu.com"
                        >

                        <p class="text-xs text-slate-400 mt-1">
                            xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com のいずれも入力できます。
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                ログイン名
                            </label>

                            <input
                                id="setting_login_name"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                                value="${App.utils.escape(settings.login_name || '')}"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                パスワード
                            </label>

                            <input
                                id="setting_password"
                                type="password"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                                placeholder="変更しない場合は空欄"
                            >
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                顧客管理アプリID
                            </label>

                            <div class="flex gap-2">
                                <input
                                    id="setting_app_id"
                                    class="flex-1 border border-slate-300 rounded-lg px-3 py-2.5"
                                    value="${App.utils.escape(settings.app_id || '')}"
                                >

                                <button
                                    type="button"
                                    class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold"
                                    onclick="App.actions.fetchKintoneFields()"
                                >
                                    項目一覧を再取得
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold mb-2">
                                Proxyサーバ
                            </label>

                            <input
                                id="setting_proxy"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                                value="${App.utils.escape(settings.proxy || '')}"
                                placeholder="host名:port番号"
                            >
                        </div>
                    </div>

                    <label class="flex items-center gap-2">
                        <input
                            id="setting_ssl_verify"
                            type="checkbox"
                            ${settings.ssl_verify ? 'checked' : ''}
                        >
                        <span class="text-sm">
                            SSL証明書を検証する
                        </span>
                    </label>

                    <div
                        id="field_message"
                        class="text-sm"
                    ></div>

                    <div class="border-t border-slate-200 pt-5">
                        <h2 class="font-bold mb-4">
                            kintone項目マッピング
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    会社名 (Company)
                                </label>
                                ${select(
                                    'field_company',
                                    settings.field_company || '',
                                    false
                                )}
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    氏名 (Name)
                                </label>
                                ${select(
                                    'field_name',
                                    settings.field_name || '',
                                    false
                                )}
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    メールアドレス (Email)
                                </label>
                                ${select(
                                    'field_email',
                                    settings.field_email || '',
                                    false
                                )}
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    部署名 (Department)
                                </label>
                                ${select(
                                    'field_department',
                                    settings.field_department || '',
                                    false
                                )}
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    電話番号 (Phone)
                                </label>
                                ${select(
                                    'field_phone',
                                    settings.field_phone || '',
                                    false
                                )}
                            </div>

                            <div>
                                <label class="block text-sm font-semibold mb-2">
                                    住所 (Address)
                                </label>

                                ${select(
                                    'field_address',
                                    '',
                                    true
                                )}

                                <p class="text-xs text-slate-400 mt-1">
                                    Ctrl / Commandを押しながら複数選択できます。
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-3">
                        <button
                            type="submit"
                            class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-semibold"
                        >
                            設定を保存
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `);
};

App.actions.fetchKintoneFields = async function() {
    const message =
        document.getElementById(
            'field_message'
        );

    const appId =
        document.getElementById(
            'setting_app_id'
        )?.value.trim() || '';

    const subdomain =
        document.getElementById(
            'setting_subdomain'
        )?.value.trim() || '';

    const login =
        document.getElementById(
            'setting_login_name'
        )?.value.trim() || '';

    const password =
        document.getElementById(
            'setting_password'
        )?.value || '';

    if (!subdomain) {
        if (message) {
            message.className =
                'text-sm text-rose-600';
            message.textContent =
                'サブドメインを入力してください。';
        }
        return;
    }

    if (!appId) {
        if (message) {
            message.className =
                'text-sm text-rose-600';
            message.textContent =
                'アプリIDを入力してください。';
        }
        return;
    }

    /*
     * パスワード欄が空の場合は保存済みパスワードを使用する。
     */
    const settings =
        App.utils.clone(
            App.State.data.settings || {}
        );

    settings.subdomain = subdomain;
    settings.app_id = appId;
    settings.login_name = login;

    if (password !== '') {
        settings.password = password;
    }

    settings.proxy =
        document.getElementById(
            'setting_proxy'
        )?.value.trim() || '';

    settings.ssl_verify =
        Boolean(
            document.getElementById(
                'setting_ssl_verify'
            )?.checked
        );

    if (message) {
        message.className =
            'text-sm text-indigo-600';
        message.textContent =
            'kintoneから項目一覧を取得しています...';
    }

    try {
        /*
         * fetchKintoneFields のサーバー側実装。
         */
        const result =
            await App.api.request(
                'kintone_fields',
                {
                    app_id: appId
                }
            );

        App.State.fields =
            Array.isArray(result.fields)
                ? result.fields
                : [];

        App.State.data.settings =
            Object.assign(
                {},
                App.State.data.settings,
                settings
            );

        if (message) {
            message.className =
                'text-sm text-emerald-600';
            message.textContent =
                App.State.fields.length +
                '件の項目を取得しました。';
        }

        /*
         * selectを再描画。
         */
        App.render.current();

        /*
         * 再描画後に、選択済み設定を復元。
         */
        App.actions.restoreFieldSelections();

    } catch (error) {
        if (message) {
            message.className =
                'text-sm text-rose-600 whitespace-pre-line';

            message.textContent =
                'kintone接続に失敗しました。\n' +
                error.message;
        }
    }
};

App.actions.restoreFieldSelections = function() {
    const settings =
        App.State.data.settings || {};

    [
        ['field_company', settings.field_company || ''],
        ['field_name', settings.field_name || ''],
        ['field_email', settings.field_email || ''],
        ['field_department', settings.field_department || ''],
        ['field_phone', settings.field_phone || '']
    ].forEach(
        function(pair) {
            const element =
                document.getElementById(pair[0]);

            if (element) {
                element.value = pair[1];
            }
        }
    );

    const address =
        document.getElementById(
            'field_address'
        );

    if (address) {
        const selected =
            Array.isArray(settings.field_address)
                ? settings.field_address
                : [];

        Array.from(address.options)
            .forEach(
                function(option) {
                    option.selected =
                        selected.includes(
                            option.value
                        );
                }
            );
    }
};

App.actions.saveSettings = async function() {
    const oldSettings =
        App.State.data.settings || {};

    const password =
        document.getElementById(
            'setting_password'
        )?.value || '';

    const settings = {
        subdomain:
            document.getElementById(
                'setting_subdomain'
            )?.value.trim() || '',

        login_name:
            document.getElementById(
                'setting_login_name'
            )?.value.trim() || '',

        password:
            password !== ''
                ? password
                : oldSettings.password || '',

        app_id:
            document.getElementById(
                'setting_app_id'
            )?.value.trim() || '',

        ssl_verify:
            Boolean(
                document.getElementById(
                    'setting_ssl_verify'
                )?.checked
            ),

        proxy:
            document.getElementById(
                'setting_proxy'
            )?.value.trim() || '',

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
            Array.from(
                document.getElementById(
                    'field_address'
                )?.selectedOptions || []
            ).map(
                function(option) {
                    return option.value;
                }
            )
    };

    try {
        const result =
            await App.api.saveSettings(
                settings
            );

        App.State.data.settings =
            result.settings;

        alert(
            'kintone連携設定を保存しました。'
        );

        App.render.current();

        App.actions.restoreFieldSelections();
    } catch (error) {
        alert(error.message);
    }
};


/* ================================================================
 * Mail
 * ================================================================ */

App.actions.openMail = function(surveyId) {
    App.State.customerSurveyId =
        surveyId;

    App.State.screen = 'mail';

    App.render.current();
};

App.render.mail = function() {
    const survey =
        App.utils.findSurvey(
            App.State.customerSurveyId
        );

    if (!survey) {
        return App.render.list();
    }

    let customers =
        App.State.data.customers
            .filter(
                function(customer) {
                    return customer.source !== 'web';
                }
            );

    const keyword =
        App.State.customerKeyword
            .trim()
            .toLowerCase();

    if (keyword) {
        customers =
            customers.filter(
                function(customer) {
                    return [
                        customer.company,
                        customer.name,
                        customer.email
                    ].join(' ')
                        .toLowerCase()
                        .includes(keyword);
                }
            );
    }

    if (
        App.State.customerStatus !== 'all'
    ) {
        customers =
            customers.filter(
                function(customer) {
                    return customer.answer_status ===
                        App.State.customerStatus;
                }
            );
    }

    const rows =
        customers.map(
            function(customer) {
                return `
                    <tr class="border-t border-slate-100">
                        <td class="p-3">
                            <input
                                type="checkbox"
                                class="customer-check w-4 h-4"
                                value="${App.utils.escape(customer.id)}"
                                ${customer.source === 'web' ? 'disabled' : ''}
                            >
                        </td>

                        <td class="p-3">
                            <div class="font-semibold">
                                ${App.utils.escape(customer.company || '')}
                            </div>
                            <div class="text-sm">
                                ${App.utils.escape(customer.name || '')}
                            </div>
                            <div class="text-xs text-slate-400">
                                ${App.utils.escape(customer.email || '')}
                            </div>
                        </td>

                        <td class="p-3 text-sm">
                            ${App.utils.escape(customer.department || '')}
                        </td>

                        <td class="p-3 text-sm">
                            ${App.utils.escape(customer.phone || '')}
                        </td>

                        <td class="p-3">
                            <div class="text-xs">
                                最終送信:
                                ${App.utils.escape(customer.sent_at || '未送信')}
                            </div>
                            <div class="text-xs text-slate-400">
                                ${customer.send_count || 0}回
                            </div>
                        </td>

                        <td class="p-3">
                            <span class="px-2 py-1 rounded-full text-xs ${customer.answer_status === 'answered' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}">
                                ${customer.answer_status === 'answered' ? '回答済み' : '未回答'}
                            </span>
                        </td>

                        <td class="p-3">
                            ${
                                customer.kintone_status === 'registered'
                                    ? '<span class="text-emerald-600 text-sm">✓ kintone登録完了</span>'
                                    : `
                                        <button
                                            class="text-xs px-2 py-1 rounded bg-slate-100"
                                            onclick="App.actions.registerCustomer('${customer.id}')"
                                        >
                                            kintone登録完了
                                        </button>
                                    `
                            }
                        </td>
                    </tr>
                `;
            }
        ).join('');

    return App.render.layout(`
        <div class="mb-5">
            <button
                class="text-sm text-slate-500 hover:text-slate-800"
                onclick="App.actions.goList()"
            >
                ← アンケート一覧
            </button>

            <div class="flex items-center justify-between mt-2">
                <div>
                    <h1 class="text-2xl font-bold">
                        顧客選択・メール送信
                    </h1>
                    <p class="text-sm text-slate-500 mt-1">
                        ${App.utils.escape(survey.title)}
                    </p>
                </div>

                <button
                    class="bg-indigo-600 text-white px-4 py-2.5 rounded-xl font-semibold"
                    onclick="App.actions.sendMail()"
                >
                    一括送信
                </button>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <input
                    id="customer_filter"
                    class="border border-slate-300 rounded-lg px-3 py-2"
                    placeholder="顧客名・会社名・メール"
                    value="${App.utils.escape(App.State.customerKeyword)}"
                    oninput="App.actions.filterCustomers(this.value)"
                >

                <select
                    class="border border-slate-300 rounded-lg px-3 py-2"
                    onchange="App.actions.filterCustomerStatus(this.value)"
                >
                    <option value="all">すべて</option>
                    <option value="unanswered">未回答</option>
                    <option value="answered">回答済み</option>
                </select>

                <select
                    id="template_type"
                    class="border border-slate-300 rounded-lg px-3 py-2"
                >
                    <option value="initial">初回送信</option>
                    <option value="reminder">リマインド</option>
                </select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                <input
                    id="mail_subject"
                    class="border border-slate-300 rounded-lg px-3 py-2"
                    value="【アンケートのお願い】"
                    placeholder="件名"
                >

                <textarea
                    id="mail_body"
                    class="border border-slate-300 rounded-lg px-3 py-2 min-h-24"
                    placeholder="本文"
                >${App.utils.escape(
                    '{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n{アンケートURL}'
                )}</textarea>
            </div>

            <div class="text-xs text-slate-400 mt-2">
                使用可能な変数：
                {顧客名} / {アンケートURL}
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table
                    id="customer_table"
                    class="w-full text-left"
                >
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="p-3">
                                <input
                                    id="select_all"
                                    type="checkbox"
                                    onchange="App.actions.selectAll(this.checked)"
                                >
                            </th>
                            <th class="p-3">会社名 / 氏名</th>
                            <th class="p-3">部署</th>
                            <th class="p-3">電話</th>
                            <th class="p-3">送信履歴</th>
                            <th class="p-3">回答</th>
                            <th class="p-3">kintone</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </div>
        </div>
    `);
};

App.actions.filterCustomers = function(value) {
    App.State.customerKeyword = value;
    App.render.current();
};

App.actions.filterCustomerStatus = function(value) {
    App.State.customerStatus = value;
    App.render.current();
};

App.actions.selectAll = function(checked) {
    document
        .querySelectorAll('.customer-check:not(:disabled)')
        .forEach(
            function(input) {
                input.checked = checked;
            }
        );
};

App.actions.registerCustomer = async function(id) {
    try {
        await App.api.request(
            'register_customer',
            {
                customer_id: id
            }
        );

        const customer =
            App.State.data.customers.find(
                function(item) {
                    return String(item.id) ===
                        String(id);
                }
            );

        if (customer) {
            customer.kintone_status =
                'registered';
        }

        App.render.current();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.sendMail = async function() {
    const surveyId =
        App.State.customerSurveyId;

    const selected =
        Array.from(
            document.querySelectorAll(
                '.customer-check:checked'
            )
        ).map(
            function(input) {
                return input.value;
            }
        );

    if (!selected.length) {
        alert('送信先を選択してください。');
        return;
    }

    const alreadySent =
        selected.filter(
            function(id) {
                const customer =
                    App.State.data.customers.find(
                        function(item) {
                            return String(item.id) ===
                                String(id);
                        }
                    );

                return customer &&
                    Number(customer.send_count || 0) > 0;
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
        )?.value || '';

    const body =
        document.getElementById(
            'mail_body'
        )?.value || '';

    const templateType =
        document.getElementById(
            'template_type'
        )?.value || 'initial';

    try {
        const result =
            await App.api.request(
                'send_mail',
                {
                    survey_id: surveyId,
                    recipient_ids:
                        JSON.stringify(selected),
                    mail_subject: subject,
                    mail_body: body,
                    template_type: templateType
                }
            );

        alert(
            result.count +
            '件の送信処理を実行しました。'
        );

        await App.api.load();

        App.render.current();
    } catch (error) {
        alert(error.message);
    }
};


/* ================================================================
 * Results
 * ================================================================ */

App.actions.openResults = function(surveyId) {
    App.State.responseSurveyId =
        surveyId;

    App.State.responseKeyword = '';

    const survey =
        App.utils.findSurvey(surveyId);

    if (survey) {
        App.State.selectedQuestions = {};

        App.utils.allQuestions(survey)
            .forEach(
                function(item) {
                    App.State.selectedQuestions[
                        item.question.id
                    ] = true;
                }
            );
    }

    App.State.screen = 'results';

    App.render.current();
};

App.render.results = function() {
    const survey =
        App.utils.findSurvey(
            App.State.responseSurveyId
        );

    if (!survey) {
        return App.render.list();
    }

    const responses =
        App.utils.responsesFor(
            survey.id
        );

    const customers =
        App.State.data.customers;

    const sentCount =
        customers.filter(
            function(customer) {
                return (
                    customer.source !== 'web' &&
                    Number(customer.send_count || 0) > 0
                );
            }
        ).length;

    const webResponses =
        responses.filter(
            function(response) {
                const customer =
                    customers.find(
                        function(item) {
                            return String(item.id) ===
                                String(response.customer_id);
                        }
                    );

                return !customer ||
                    customer.source === 'web';
            }
        ).length;

    const answeredSent =
        responses.length - webResponses;

    const unanswered =
        Math.max(
            sentCount - answeredSent,
            0
        );

    const rate =
        sentCount > 0
            ? (
                answeredSent /
                sentCount *
                100
            ).toFixed(1)
            : '0.0';

    const allQuestions =
        App.utils.allQuestions(
            survey
        );

    const filters =
        allQuestions.map(
            function(item) {
                const q =
                    item.question;

                return `
                    <label class="flex items-center gap-2 py-1.5">
                        <input
                            type="checkbox"
                            ${App.State.selectedQuestions[q.id] ? 'checked' : ''}
                            onchange="App.actions.toggleResultQuestion('${q.id}', this.checked)"
                        >
                        <span class="text-sm">
                            ${App.utils.escape(q.text || '質問')}
                        </span>
                        <span class="text-xs px-2 py-0.5 bg-slate-100 rounded">
                            ${q.type === 'single' ? '単一' : q.type === 'multiple' ? '複数' : '自由記述'}
                        </span>
                    </label>
                `;
            }
        ).join('');

    const charts =
        allQuestions
            .filter(
                function(item) {
                    return Boolean(
                        App.State.selectedQuestions[
                            item.question.id
                        ]
                    );
                }
            )
            .map(
                function(item) {
                    return App.render.questionResult(
                        survey,
                        item.question,
                        responses
                    );
                }
            ).join('');

    let filteredResponses =
        responses;

    const keyword =
        App.State.responseKeyword
            .trim()
            .toLowerCase();

    if (keyword) {
        filteredResponses =
            responses.filter(
                function(response) {
                    return [
                        response.company,
                        response.name
                    ].join(' ')
                        .toLowerCase()
                        .includes(keyword);
                }
            );
    }

    const responseRows =
        filteredResponses.map(
            function(response) {
                return `
                    <tr class="border-t border-slate-100">
                        <td class="p-3">
                            ${App.utils.escape(response.company || '')}
                        </td>

                        <td class="p-3">
                            ${App.utils.escape(response.name || '')}
                        </td>

                        <td class="p-3">
                            ${App.utils.escape(response.email || '')}
                        </td>

                        <td class="p-3">
                            ${App.utils.escape(response.answered_at || '')}
                        </td>

                        <td class="p-3">
                            <button
                                class="text-indigo-600 text-sm"
                                onclick="App.actions.showResponse('${response.id}')"
                            >
                                全回答を表示
                            </button>
                        </td>
                    </tr>
                `;
            }
        ).join('');

    return App.render.layout(`
        <div class="mb-5">
            <button
                class="text-sm text-slate-500"
                onclick="App.actions.goList()"
            >
                ← アンケート一覧
            </button>

            <div class="flex items-center justify-between mt-2">
                <div>
                    <h1 class="text-2xl font-bold">
                        回答集計・分析
                    </h1>

                    <p class="text-sm text-slate-500 mt-1">
                        ${App.utils.escape(survey.title)}
                    </p>
                </div>

                <a
                    class="px-4 py-2 bg-slate-800 text-white rounded-xl text-sm"
                    href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
                >
                    CSV出力
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
            ${[
                ['送信対象者数', sentCount + ' 人'],
                ['回答数', responses.length + ' 件'],
                ['未登録顧客からの回答数', webResponses + ' 件'],
                ['未回答数', unanswered + ' 人'],
                ['回答率', rate + ' %']
            ].map(
                function(item) {
                    return `
                        <div class="bg-white border border-slate-200 rounded-xl p-4">
                            <div class="text-xs text-slate-400">
                                ${item[0]}
                            </div>
                            <div class="text-xl font-bold mt-2">
                                ${item[1]}
                            </div>
                        </div>
                    `;
                }
            ).join('')}
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-4">
            <aside class="bg-white border border-slate-200 rounded-2xl p-4 h-fit">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="font-bold">設問絞り込み</h2>

                    <div class="flex gap-1">
                        <button
                            class="text-xs text-indigo-600"
                            onclick="App.actions.selectAllResults(true)"
                        >
                            全選択
                        </button>

                        <button
                            class="text-xs text-slate-500"
                            onclick="App.actions.selectAllResults(false)"
                        >
                            全解除
                        </button>
                    </div>
                </div>

                ${filters}
            </aside>

            <div class="space-y-4">
                ${
                    responses.length === 0
                        ? `
                            <div class="bg-white border border-slate-200 rounded-2xl p-16 text-center text-slate-400">
                                現在、回答データはありません。
                            </div>
                        `
                        : charts
                }

                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                    <div class="p-4 flex items-center justify-between">
                        <h2 class="font-bold">
                            個別回答一覧
                        </h2>

                        <input
                            id="response_filter"
                            class="border border-slate-300 rounded-lg px-3 py-2 text-sm"
                            placeholder="会社名・氏名で検索"
                            value="${App.utils.escape(App.State.responseKeyword)}"
                            oninput="App.actions.filterResponses(this.value)"
                        >
                    </div>

                    <div class="overflow-x-auto">
                        <table
                            id="response_table"
                            class="w-full text-left"
                        >
                            <thead class="bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="p-3">会社名</th>
                                    <th class="p-3">氏名</th>
                                    <th class="p-3">メール</th>
                                    <th class="p-3">回答日時</th>
                                    <th class="p-3">詳細</th>
                                </tr>
                            </thead>

                            <tbody>
                                ${responseRows}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    `);
};

App.render.questionResult = function(
    survey,
    question,
    responses
) {
    if (question.type === 'text') {
        const texts =
            responses.map(
                function(response) {
                    return {
                        response: response,
                        value:
                            response.answers?.[
                                question.id
                            ] || ''
                    };
                }
            ).filter(
                function(item) {
                    return String(item.value).trim() !== '';
                }
            );

        return `
            <div class="bg-white border border-slate-200 rounded-2xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold">
                        ${App.utils.escape(question.text || '自由記述')}
                    </h2>

                    <span class="text-xs bg-slate-100 px-2 py-1 rounded">
                        自由記述
                    </span>
                </div>

                <div class="space-y-3 max-h-96 overflow-auto">
                    ${
                        texts.length
                            ? texts.map(
                                function(item) {
                                    return `
                                        <div class="border-l-4 border-indigo-200 pl-4">
                                            <div class="text-xs text-slate-400">
                                                ${App.utils.escape(item.response.company || '')}
                                                /
                                                ${App.utils.escape(item.response.name || '')}
                                            </div>

                                            <div class="mt-1 whitespace-pre-wrap">
                                                ${App.utils.escape(String(item.value))}
                                            </div>
                                        </div>
                                    `;
                                }
                            ).join('')
                            : '<div class="text-slate-400 text-sm">回答はありません。</div>'
                    }
                </div>
            </div>
        `;
    }

    const counts = {};

    (question.options || []).forEach(
        function(option) {
            counts[option] = 0;
        }
    );

    let total = 0;

    responses.forEach(
        function(response) {
            let value =
                response.answers?.[
                    question.id
                ];

            if (Array.isArray(value)) {
                value.forEach(
                    function(item) {
                        counts[item] =
                            (counts[item] || 0) + 1;
                        total++;
                    }
                );
            } else if (
                value !== undefined &&
                value !== ''
            ) {
                counts[value] =
                    (counts[value] || 0) + 1;
                total++;
            }
        }
    );

    return `
        <div class="bg-white border border-slate-200 rounded-2xl p-5">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-bold">
                    ${App.utils.escape(question.text || '質問')}
                </h2>

                <span class="text-xs bg-indigo-50 text-indigo-700 px-2 py-1 rounded">
                    ${question.type === 'single' ? '単一選択' : '複数選択'}
                </span>
            </div>

            <div class="space-y-3">
                ${(question.options || []).map(
                    function(option) {
                        const count =
                            counts[option] || 0;

                        const percentage =
                            total > 0
                                ? count / total * 100
                                : 0;

                        return `
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span>
                                        ${App.utils.escape(option)}
                                    </span>

                                    <span class="text-slate-500">
                                        ${count}件
                                        (${percentage.toFixed(1)}%)
                                    </span>
                                </div>

                                <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                    <div
                                        class="h-full bg-indigo-500 rounded-full"
                                        style="width:${Math.min(100, percentage)}%"
                                    ></div>
                                </div>
                            </div>
                        `;
                    }
                ).join('')}
            </div>
        </div>
    `;
};

App.actions.toggleResultQuestion = function(
    id,
    checked
) {
    App.State.selectedQuestions[id] =
        checked;

    App.render.current();
};

App.actions.selectAllResults = function(
    checked
) {
    const survey =
        App.utils.findSurvey(
            App.State.responseSurveyId
        );

    if (!survey) return;

    App.utils.allQuestions(survey)
        .forEach(
            function(item) {
                App.State.selectedQuestions[
                    item.question.id
                ] = checked;
            }
        );

    App.render.current();
};

App.actions.filterResponses = function(
    value
) {
    App.State.responseKeyword =
        value;

    App.render.current();
};

App.actions.showResponse = function(
    responseId
) {
    const response =
        App.State.data.responses.find(
            function(item) {
                return String(item.id) ===
                    String(responseId);
            }
        );

    if (!response) return;

    App.State.modal =
        'response';

    App.State.modalResponse =
        response;

    App.render.current();
};

App.render.responseModal = function() {
    const response =
        App.State.modalResponse;

    if (!response) return '';

    const survey =
        App.utils.findSurvey(
            response.survey_id
        );

    if (!survey) return '';

    let html = '';

    App.utils.allQuestions(survey)
        .forEach(
            function(item) {
                const q =
                    item.question;

                let answer =
                    response.answers?.[
                        q.id
                    ] ?? '';

                if (Array.isArray(answer)) {
                    answer =
                        answer.join('、');
                }

                html += `
                    <div class="border-b border-slate-100 py-4">
                        <div class="text-xs text-slate-400 mb-1">
                            ${App.utils.escape(q.type)}
                        </div>

                        <div class="font-semibold">
                            ${App.utils.escape(q.text || '')}
                        </div>

                        <div class="mt-2 whitespace-pre-wrap">
                            ${App.utils.escape(String(answer))}
                        </div>
                    </div>
                `;
            }
        );

    return `
        <div
            id="response_modal"
            class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-5"
        >
            <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-auto">
                <div class="sticky top-0 bg-white border-b p-4 flex justify-between">
                    <div>
                        <div class="font-bold">
                            回答詳細
                        </div>
                        <div class="text-xs text-slate-400 mt-1">
                            ${App.utils.escape(response.company || '')}
                            /
                            ${App.utils.escape(response.name || '')}
                        </div>
                    </div>

                    <button
                        onclick="App.actions.closeModal()"
                    >
                        ✕
                    </button>
                </div>

                <div
                    id="response_detail"
                    class="p-5"
                >
                    ${html}
                </div>
            </div>
        </div>
    `;
};


/* ================================================================
 * Render
 * ================================================================ */

App.render.current = function() {
    const root =
        document.getElementById('app');

    if (!root) return;

    if (App.State.screen === 'editor') {
        root.innerHTML =
            App.render.editor();
    } else if (
        App.State.screen === 'settings'
    ) {
        root.innerHTML =
            App.render.settings();
    } else if (
        App.State.screen === 'mail'
    ) {
        root.innerHTML =
            App.render.mail();
    } else if (
        App.State.screen === 'results'
    ) {
        root.innerHTML =
            App.render.results();
    } else {
        root.innerHTML =
            App.render.list();
    }

    if (
        App.State.screen === 'settings' &&
        App.State.fields.length
    ) {
        App.actions.restoreFieldSelections();
    }

    if (App.State.modal === 'preview') {
        root.insertAdjacentHTML(
            'beforeend',
            App.render.previewModal()
        );
    }

    if (App.State.modal === 'response') {
        root.insertAdjacentHTML(
            'beforeend',
            App.render.responseModal()
        );
    }
};


/* ================================================================
 * Misc
 * ================================================================ */

App.actions.logout = function() {
    alert(
        'この簡易構成では管理者セッションによるログイン画面は実装していません。'
    );
};

App.actions.loadCustomers = async function() {
    try {
        const result =
            await App.api.request(
                'kintone_customers',
                {}
            );

        if (result.data) {
            App.State.data =
                result.data;
        }

        App.render.current();
    } catch (error) {
        alert(error.message);
    }
};


/* ================================================================
 * Initialization
 * ================================================================ */

App.init = async function() {
    if (App.initDone) {
        return;
    }

    App.initDone = true;

    const root =
        document.getElementById('app');

    if (root) {
        root.innerHTML = `
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
        await App.api.load();
        App.render.current();
    } catch (error) {
        if (root) {
            root.innerHTML = `
                <div class="min-h-screen flex items-center justify-center p-6">
                    <div class="bg-white border border-rose-200 rounded-2xl p-8 max-w-xl">
                        <h1 class="font-bold text-rose-700">
                            初期化に失敗しました
                        </h1>

                        <p class="mt-3 text-sm text-slate-600">
                            ${App.utils.escape(error.message)}
                        </p>

                        <button
                            class="mt-5 px-4 py-2 bg-indigo-600 text-white rounded-lg"
                            onclick="location.reload()"
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
 * readyStateガード。
 *
 * スクリプト評価時点がDOMContentLoaded前後のどちらでも
 * App.init()を1回だけ実行する。
 */
if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.init();
        },
        { once: true }
    );
} else {
    App.init();
}
</script>

</body>
</html>
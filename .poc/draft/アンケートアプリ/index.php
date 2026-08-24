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

session_name(SURVEY_ADMIN_SESSION);
session_start();

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function survey_default_data(): array
{
    return [
        'surveys' => [
            [
                'id' => 'survey_demo_001',
                'title' => '顧客満足度アンケート',
                'start_at' => '2026-08-01T09:00',
                'end_at' => '2026-09-30T18:00',
                'status' => 'active',
                'created_at' => '2026-07-25T10:00:00',
                'updated_at' => '2026-08-20T15:00:00',
                'numbering_mode' => 'global',
                'deleted' => false,
                'groups' => [
                    [
                        'id' => 'group_demo_001',
                        'name' => '基本情報',
                        'questions' => [
                            [
                                'id' => 'question_demo_001',
                                'text' => '今回のサービスにどの程度満足していますか？',
                                'type' => 'single',
                                'required' => true,
                                'options' => ['非常に満足', '満足', '普通', '不満', '非常に不満'],
                                'other_enabled' => false
                            ],
                            [
                                'id' => 'question_demo_002',
                                'text' => '今後も弊社サービスを利用したいと思いますか？',
                                'type' => 'single',
                                'required' => true,
                                'options' => ['ぜひ利用したい', '利用したい', 'どちらともいえない', '利用したくない'],
                                'other_enabled' => false
                            ]
                        ]
                    ],
                    [
                        'id' => 'group_demo_002',
                        'name' => 'ご意見',
                        'questions' => [
                            [
                                'id' => 'question_demo_003',
                                'text' => 'サービスについてご意見・ご要望があればお聞かせください。',
                                'type' => 'text',
                                'required' => false,
                                'options' => [],
                                'other_enabled' => false
                            ]
                        ]
                    ]
                ]
            ],
            [
                'id' => 'survey_demo_002',
                'title' => '新サービス企画アンケート',
                'start_at' => '',
                'end_at' => '',
                'status' => 'draft',
                'created_at' => '2026-08-21T10:00:00',
                'updated_at' => '2026-08-21T10:00:00',
                'numbering_mode' => 'group',
                'deleted' => false,
                'groups' => [
                    [
                        'id' => 'group_demo_003',
                        'name' => '新サービスについて',
                        'questions' => [
                            [
                                'id' => 'question_demo_004',
                                'text' => '興味のあるサービスを教えてください。',
                                'type' => 'multiple',
                                'required' => true,
                                'options' => ['プランA', 'プランB', 'プランC'],
                                'other_enabled' => true
                            ]
                        ]
                    ]
                ]
            ]
        ],
        'responses' => [
            [
                'id' => 'response_demo_001',
                'survey_id' => 'survey_demo_001',
                'customer_id' => 'customer_demo_001',
                'company' => '株式会社サンプル',
                'name' => '山田 太郎',
                'email' => 'yamada@example.com',
                'answered_at' => '2026-08-18T11:30:00',
                'answers' => [
                    'question_demo_001' => '満足',
                    'question_demo_002' => 'ぜひ利用したい',
                    'question_demo_003' => '今後もよろしくお願いします。'
                ]
            ],
            [
                'id' => 'response_demo_002',
                'survey_id' => 'survey_demo_001',
                'customer_id' => 'customer_demo_002',
                'company' => 'テスト商事株式会社',
                'name' => '佐藤 花子',
                'email' => 'sato@example.com',
                'answered_at' => '2026-08-19T14:10:00',
                'answers' => [
                    'question_demo_001' => '非常に満足',
                    'question_demo_002' => '利用したい',
                    'question_demo_003' => '回答しやすかったです。'
                ]
            ]
        ],
        'customers' => [
            [
                'id' => 'customer_demo_001',
                'company' => '株式会社サンプル',
                'name' => '山田 太郎',
                'email' => 'yamada@example.com',
                'department' => '営業部',
                'phone' => '03-1234-5678',
                'address' => '東京都港区',
                'source' => 'kintone',
                'sent_at' => '2026-08-10T10:00:00',
                'send_count' => 1,
                'answer_status' => 'answered',
                'kintone_status' => 'registered'
            ],
            [
                'id' => 'customer_demo_002',
                'company' => 'テスト商事株式会社',
                'name' => '佐藤 花子',
                'email' => 'sato@example.com',
                'department' => '企画部',
                'phone' => '03-9876-5432',
                'address' => '東京都千代田区',
                'source' => 'kintone',
                'sent_at' => '2026-08-10T10:01:00',
                'send_count' => 1,
                'answer_status' => 'answered',
                'kintone_status' => 'registered'
            ],
            [
                'id' => 'customer_demo_003',
                'company' => '未回答株式会社',
                'name' => '鈴木 一郎',
                'email' => 'suzuki@example.com',
                'department' => '総務部',
                'phone' => '03-1111-2222',
                'address' => '東京都新宿区',
                'source' => 'kintone',
                'sent_at' => '2026-08-11T09:30:00',
                'send_count' => 1,
                'answer_status' => 'unanswered',
                'kintone_status' => 'registered'
            ],
            [
                'id' => 'customer_demo_004',
                'company' => 'Web回答者',
                'name' => '田中 次郎',
                'email' => 'tanaka@example.com',
                'department' => '',
                'phone' => '',
                'address' => '',
                'source' => 'web',
                'sent_at' => '',
                'send_count' => 0,
                'answer_status' => 'answered',
                'kintone_status' => 'unregistered'
            ]
        ],
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

function survey_read_data(): array
{
    if (!file_exists(SURVEY_STORAGE_FILE)) {
        $data = survey_default_data();
        survey_write_data($data);
        return $data;
    }

    $json = @file_get_contents(SURVEY_STORAGE_FILE);
    if ($json === false || trim($json) === '') {
        return survey_default_data();
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        return survey_default_data();
    }

    foreach (['surveys', 'responses', 'customers', 'settings', 'mail_logs'] as $key) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = survey_default_data()[$key];
        }
    }

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

    if ($json === false) {
        return false;
    }

    return @file_put_contents(SURVEY_STORAGE_FILE, $json, LOCK_EX) !== false;
}

function survey_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function survey_csrf_valid(): bool
{
    $token = (string)($_POST['csrf_token'] ?? '');
    return $token !== '' &&
        isset($_SESSION['csrf_token']) &&
        hash_equals((string)$_SESSION['csrf_token'], $token);
}

function survey_clean_text(mixed $value, int $max = 1000): string
{
    $text = trim((string)$value);

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }

    return substr($text, 0, $max);
}

function survey_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function survey_now(): string
{
    return date('Y-m-d\TH:i:s');
}

function survey_kintone_request(
    array $settings,
    string $method,
    string $path,
    ?array $body = null
): array {
    $subdomain = trim((string)($settings['subdomain'] ?? ''));

    if ($subdomain === '') {
        return [
            'ok' => false,
            'message' => 'kintoneのサブドメインが設定されていません。',
            'status' => 0,
            'data' => null
        ];
    }

    if (str_contains($subdomain, '.cybozu.com')) {
        $host = $subdomain;
    } else {
        $host = $subdomain . '.cybozu.com';
    }

    $url = 'https://' . $host . $path;

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    $headers = [
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' . base64_encode($login . ':' . $password)
    ];

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 20,
            'protocol_version' => 1.1
        ],
        'ssl' => [
            'verify_peer' => !empty($settings['ssl_verify']),
            'verify_peer_name' => !empty($settings['ssl_verify']),
            'allow_self_signed' => true
        ]
    ];

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        $options['http']['proxy'] = 'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    if ($body !== null) {
        $encoded = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            return [
                'ok' => false,
                'message' => 'JSON生成に失敗しました。',
                'status' => 0,
                'data' => null
            ];
        }

        $options['http']['content'] = $encoded;
    }

    $context = stream_context_create($options);

    $result = @file_get_contents($url, false, $context);

    /*
     * PHP 8.4/8.5以降の非推奨警告を避けるため、
     * レスポンスヘッダー取得は http_get_last_response_headers() を優先。
     */
    $responseHeaders = [];

    if (function_exists('http_get_last_response_headers')) {
        $responseHeaders = http_get_last_response_headers();
    } elseif (isset($http_response_header) && is_array($http_response_header)) {
        $responseHeaders = $http_response_header;
    }

    $status = 0;

    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/', $header, $m)) {
            $status = (int)$m[1];
            break;
        }
    }

    $decoded = null;

    if ($result !== false && trim($result) !== '') {
        $decoded = json_decode($result, true);
    }

    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'message' => 'OK',
            'status' => $status,
            'data' => $decoded
        ];
    }

    $message = 'kintone API通信に失敗しました。';

    if (is_array($decoded) && isset($decoded['message'])) {
        $message = (string)$decoded['message'];
    }

    return [
        'ok' => false,
        'message' => $message,
        'status' => $status,
        'data' => $decoded
    ];
}

$action = (string)($_REQUEST['action'] ?? '');

if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !survey_csrf_valid()) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。ページを再読み込みしてください。'
        ], 403);
    }

    $data = survey_read_data();

    if ($action === 'load') {
        survey_json_response([
            'ok' => true,
            'data' => $data
        ]);
    }

    if ($action === 'save_survey') {
        $json = (string)($_POST['survey_json'] ?? '');
        $survey = json_decode($json, true);

        if (!is_array($survey)) {
            survey_json_response([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], 400);
        }

        $survey['title'] = survey_clean_text($survey['title'] ?? '', 200);
        $survey['start_at'] = survey_clean_text($survey['start_at'] ?? '', 30);
        $survey['end_at'] = survey_clean_text($survey['end_at'] ?? '', 30);

        if ($survey['title'] === '') {
            survey_json_response([
                'ok' => false,
                'message' => 'アンケートタイトルを入力してください。'
            ], 400);
        }

        $survey['updated_at'] = survey_now();

        $found = false;

        foreach ($data['surveys'] as $i => $existing) {
            if (($existing['id'] ?? '') === ($survey['id'] ?? '')) {
                $survey['created_at'] = $existing['created_at'] ?? survey_now();
                $survey['deleted'] = false;
                $data['surveys'][$i] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['id'] = survey_id('survey');
            $survey['created_at'] = survey_now();
            $survey['status'] = 'draft';
            $survey['deleted'] = false;
            $data['surveys'][] = $survey;
        }

        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'message' => '保存しました。',
            'survey' => $survey,
            'data' => $data
        ]);
    }

    if ($action === 'change_status') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? '');

        if (!in_array($status, ['draft', 'active', 'ended'], true)) {
            survey_json_response([
                'ok' => false,
                'message' => '不正なステータスです。'
            ], 400);
        }

        $found = false;

        foreach ($data['surveys'] as $i => $survey) {
            if (($survey['id'] ?? '') === $surveyId && empty($survey['deleted'])) {
                $data['surveys'][$i]['status'] = $status;
                $data['surveys'][$i]['updated_at'] = survey_now();
                $found = true;
                break;
            }
        }

        if (!$found) {
            survey_json_response([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'message' => $status === 'active'
                ? 'アンケートを公開しました。'
                : ($status === 'ended' ? 'アンケートを停止しました。' : 'ステータスを変更しました。'),
            'data' => $data
        ]);
    }

    if ($action === 'delete_survey') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as $i => $survey) {
            if (($survey['id'] ?? '') === $surveyId) {
                $data['surveys'][$i]['deleted'] = true;
                $data['surveys'][$i]['updated_at'] = survey_now();
                survey_write_data($data);

                survey_json_response([
                    'ok' => true,
                    'message' => 'アンケートを削除しました。',
                    'data' => $data
                ]);
            }
        }

        survey_json_response([
            'ok' => false,
            'message' => 'アンケートが見つかりません。'
        ], 404);
    }

    if ($action === 'duplicate_survey') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $source = null;

        foreach ($data['surveys'] as $survey) {
            if (($survey['id'] ?? '') === $surveyId) {
                $source = $survey;
                break;
            }
        }

        if ($source === null) {
            survey_json_response([
                'ok' => false,
                'message' => '複製元が見つかりません。'
            ], 404);
        }

        $source['id'] = survey_id('survey');
        $source['title'] = (string)$source['title'] . '（コピー）';
        $source['status'] = 'draft';
        $source['created_at'] = survey_now();
        $source['updated_at'] = survey_now();
        $source['deleted'] = false;

        foreach ($source['groups'] as &$group) {
            $group['id'] = survey_id('group');

            foreach ($group['questions'] as &$question) {
                $question['id'] = survey_id('question');
            }
            unset($question);
        }
        unset($group);

        $data['surveys'][] = $source;
        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'message' => 'アンケートを複製しました。',
            'survey' => $source,
            'data' => $data
        ]);
    }

    if ($action === 'save_settings') {
        $json = (string)($_POST['settings_json'] ?? '');
        $settings = json_decode($json, true);

        if (!is_array($settings)) {
            survey_json_response([
                'ok' => false,
                'message' => '設定データが不正です。'
            ], 400);
        }

        $data['settings'] = array_merge($data['settings'], [
            'subdomain' => survey_clean_text($settings['subdomain'] ?? '', 200),
            'login_name' => survey_clean_text($settings['login_name'] ?? '', 200),
            'password' => (string)($settings['password'] ?? ''),
            'app_id' => survey_clean_text($settings['app_id'] ?? '', 50),
            'ssl_verify' => !empty($settings['ssl_verify']),
            'proxy' => survey_clean_text($settings['proxy'] ?? '', 200),
            'field_company' => survey_clean_text($settings['field_company'] ?? '', 100),
            'field_name' => survey_clean_text($settings['field_name'] ?? '', 100),
            'field_email' => survey_clean_text($settings['field_email'] ?? '', 100),
            'field_department' => survey_clean_text($settings['field_department'] ?? '', 100),
            'field_phone' => survey_clean_text($settings['field_phone'] ?? '', 100),
            'field_address' => is_array($settings['field_address'] ?? null)
                ? array_values(array_map(
                    static fn($v) => survey_clean_text($v, 100),
                    $settings['field_address']
                ))
                : []
        ]);

        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'message' => 'kintone連携設定を保存しました。',
            'data' => $data
        ]);
    }

    if ($action === 'kintone_fields') {
        $settings = $data['settings'];

        $appId = survey_clean_text(
            $_POST['app_id'] ?? ($settings['app_id'] ?? ''),
            50
        );

        if ($appId === '') {
            survey_json_response([
                'ok' => false,
                'message' => 'アプリIDを入力してください。'
            ], 400);
        }

        $result = survey_kintone_request(
            $settings,
            'GET',
            '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
        );

        if (!$result['ok']) {
            survey_json_response($result, 400);
        }

        $fields = [];

        if (isset($result['data']['properties']) && is_array($result['data']['properties'])) {
            foreach ($result['data']['properties'] as $code => $property) {
                $fields[] = [
                    'label' => (string)($property['label'] ?? $code),
                    'code' => (string)$code,
                    'type' => (string)($property['type'] ?? '')
                ];
            }
        }

        survey_json_response([
            'ok' => true,
            'message' => '項目一覧を取得しました。',
            'fields' => $fields
        ]);
    }

    if ($action === 'send_mail') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $recipientIdsRaw = $_POST['recipient_ids'] ?? [];

        if (is_string($recipientIdsRaw)) {
            $recipientIds = json_decode($recipientIdsRaw, true);
        } else {
            $recipientIds = $recipientIdsRaw;
        }

        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

        $subjectTemplate = survey_clean_text($_POST['mail_subject'] ?? '', 300);
        $bodyTemplate = survey_clean_text($_POST['mail_body'] ?? '', 10000);
        $templateType = (string)($_POST['template_type'] ?? 'initial');

        if (!in_array($templateType, ['initial', 'reminder'], true)) {
            $templateType = 'initial';
        }

        if ($subjectTemplate === '' || $bodyTemplate === '') {
            survey_json_response([
                'ok' => false,
                'message' => '件名と本文を入力してください。'
            ], 400);
        }

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if ($survey === null) {
            survey_json_response([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        if (($survey['status'] ?? '') !== 'active') {
            survey_json_response([
                'ok' => false,
                'message' => '公開中のアンケートのみ送信できます。'
            ], 400);
        }

        $sent = 0;
        $failed = 0;
        $now = survey_now();

        foreach ($data['customers'] as $i => $customer) {
            $customerId = (string)($customer['id'] ?? '');

            if (!in_array($customerId, $recipientIds, true)) {
                continue;
            }

            if (($customer['source'] ?? '') === 'web') {
                continue;
            }

            $email = trim((string)($customer['email'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                continue;
            }

            $personalUrl = '';

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                ? 'https'
                : 'http';

            $host = (string)($_SERVER['HTTP_HOST'] ?? '');

            if ($host !== '') {
                $personalUrl =
                    $scheme .
                    '://' .
                    $host .
                    strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?') .
                    '?answer=' .
                    rawurlencode($surveyId) .
                    '&customer=' .
                    rawurlencode($customerId);
            } else {
                $personalUrl = '/?answer=' . rawurlencode($surveyId);
            }

            $subject = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [(string)$customer['name'], $personalUrl],
                $subjectTemplate
            );

            $body = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [(string)$customer['name'], $personalUrl],
                $bodyTemplate
            );

            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . ((string)($_SERVER['SERVER_ADMIN'] ?? 'noreply@localhost'))
            ];

            /*
             * サーバーのPHP mail環境が有効なら実メール送信。
             * mail() が利用できない環境でも履歴処理自体は継続。
             */
            $mailResult = false;

            if (function_exists('mail')) {
                $mailResult = @mail(
                    $email,
                    '=?UTF-8?B?' . base64_encode($subject) . '?=',
                    $body,
                    implode("\r\n", $headers)
                );
            }

            /*
             * mail() が falseでも、開発環境での画面確認を可能にするため
             * 送信履歴には処理結果を保存。
             */
            $sent++;

            $data['customers'][$i]['sent_at'] = $now;
            $data['customers'][$i]['send_count'] =
                (int)($data['customers'][$i]['send_count'] ?? 0) + 1;

            $data['customers'][$i]['answer_status'] = 'unanswered';

            $data['mail_logs'][] = [
                'id' => survey_id('mail'),
                'survey_id' => $surveyId,
                'sent_at' => $now,
                'template_type' => $templateType,
                'count' => 1,
                'subject' => $subject,
                'recipient_id' => $customerId,
                'recipient_email' => $email,
                'recipient_name' => (string)$customer['name'],
                'body' => $body,
                'executor' => (string)($_SESSION['admin_name'] ?? '管理者'),
                'mail_result' => $mailResult ? 'accepted' : 'not_confirmed'
            ];
        }

        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'message' => $sent . '件の送信処理を実行しました。',
            'sent' => $sent,
            'failed' => $failed,
            'data' => $data
        ]);
    }

    if ($action === 'register_kintone_customer') {
        $customerId = (string)($_POST['customer_id'] ?? '');

        foreach ($data['customers'] as $i => $customer) {
            if (($customer['id'] ?? '') === $customerId) {
                $data['customers'][$i]['kintone_status'] = 'registered';
                survey_write_data($data);

                survey_json_response([
                    'ok' => true,
                    'message' => 'kintone登録済みに変更しました。',
                    'data' => $data
                ]);
            }
        }

        survey_json_response([
            'ok' => false,
            'message' => '顧客が見つかりません。'
        ], 404);
    }

    if ($action === 'csv') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
                break;
            }
        }

        if ($survey === null) {
            survey_json_response([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $questions = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $questions[] = $question;
            }
        }

        $filename = 'survey_' . $surveyId . '_' . date('YmdHis') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="' .
            $filename .
            '"'
        );

        $fp = fopen('php://output', 'wb');

        /*
         * UTF-8 BOM
         */
        fwrite($fp, "\xEF\xBB\xBF");

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
            if (($response['survey_id'] ?? '') !== $surveyId) {
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
                $qid = (string)($question['id'] ?? '');
                $value = $response['answers'][$qid] ?? '';

                if (is_array($value)) {
                    $value = implode('、', $value);
                }

                $row[] = $value;
            }

            fputcsv($fp, $row);
        }

        fclose($fp);
        exit;
    }

    survey_json_response([
        'ok' => false,
        'message' => '未知のアクションです。'
    ], 400);
}

$csrf = htmlspecialchars(
    (string)$_SESSION['csrf_token'],
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
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

<input type="hidden" id="csrf_token" value="<?= $csrf ?>">

<div id="app"></div>

<script>
window.App = {

    state: {
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },

        page: 'list',
        editingSurvey: null,
        currentSurveyId: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        responseKeyword: '',
        customerKeyword: '',
        selectedCustomers: [],
        selectedQuestions: {},
        previewMode: 'pc',
        loading: false
    },

    util: {

        esc(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        id(prefix) {
            return prefix + '_' + Math.random().toString(36).slice(2) + Date.now();
        },

        now() {
            const d = new Date();
            const pad = n => String(n).padStart(2, '0');

            return d.getFullYear() +
                '-' + pad(d.getMonth() + 1) +
                '-' + pad(d.getDate()) +
                'T' + pad(d.getHours()) +
                ':' + pad(d.getMinutes()) +
                ':' + pad(d.getSeconds());
        },

        formatDate(value) {
            if (!value) return '未設定';

            const s = String(value).replace('T', ' ');

            if (s.length >= 16) {
                return s.substring(0, 16);
            }

            return s;
        },

        statusLabel(status) {
            return {
                draft: '下書き',
                active: '公開中',
                ended: '終了'
            }[status] || status;
        },

        statusClass(status) {
            if (status === 'active') {
                return 'bg-emerald-50 text-emerald-700 border-emerald-200';
            }

            if (status === 'ended') {
                return 'bg-slate-100 text-slate-600 border-slate-200';
            }

            return 'bg-amber-50 text-amber-700 border-amber-200';
        },

        typeLabel(type) {
            return {
                single: '単一選択',
                multiple: '複数選択',
                text: '自由記述'
            }[type] || type;
        },

        survey(id) {
            return App.state.data.surveys.find(
                s => s.id === id && !s.deleted
            );
        },

        questions(survey) {
            if (!survey) return [];

            const result = [];

            (survey.groups || []).forEach(group => {
                (group.questions || []).forEach(question => {
                    result.push(question);
                });
            });

            return result;
        },

        responseCount(surveyId) {
            return App.state.data.responses.filter(
                r => r.survey_id === surveyId
            ).length;
        },

        showToast(message, type = 'success') {
            const color = type === 'error'
                ? 'bg-rose-600'
                : 'bg-slate-900';

            const div = document.createElement('div');

            div.className =
                'fixed bottom-6 right-6 z-[100] ' +
                color +
                ' text-white px-5 py-3 rounded-xl shadow-xl';

            div.textContent = message;

            document.body.appendChild(div);

            setTimeout(() => div.remove(), 2600);
        },

        confirm(message) {
            return window.confirm(message);
        }
    },

    api: {

        async post(action, params = {}) {

            const form = new FormData();

            form.append(
                'action',
                action
            );

            form.append(
                'csrf_token',
                document.getElementById('csrf_token').value
            );

            Object.keys(params).forEach(key => {
                let value = params[key];

                if (
                    typeof value === 'object' &&
                    value !== null
                ) {
                    value = JSON.stringify(value);
                }

                form.append(key, value);
            });

            const response = await fetch(
                window.location.href,
                {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin'
                }
            );

            const text = await response.text();

            let result;

            try {
                result = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーからJSON以外のレスポンスが返されました。'
                );
            }

            if (!result.ok) {
                throw new Error(
                    result.message || '処理に失敗しました。'
                );
            }

            return result;
        },

        async load() {
            const result = await App.api.post('load');

            App.state.data = result.data;

            return result;
        }
    },

    render: {

        shell(content, title = 'アンケート管理システム') {

            return `
            <div class="min-h-screen">

                <header class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-sm">

                    <div class="max-w-[1500px] mx-auto px-6 h-16 flex items-center justify-between">

                        <div class="flex items-center gap-8">

                            <button
                                class="font-bold text-lg text-slate-900"
                                onclick="App.actions.goList()">
                                Survey Manager
                            </button>

                            <nav class="flex gap-1">

                                <button
                                    class="px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-100"
                                    onclick="App.actions.goList()">
                                    アンケート一覧
                                </button>

                                <button
                                    class="px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-100"
                                    onclick="App.actions.goSettings()">
                                    キントーン連携設定
                                </button>

                            </nav>

                        </div>

                        <div class="text-xs text-slate-400">
                            管理者
                        </div>

                    </div>

                </header>

                <main class="max-w-[1500px] mx-auto px-6 py-8">

                    <div class="mb-7">

                        <div class="text-xs text-slate-400 mb-2">
                            ${App.util.esc(title)}
                        </div>

                        <h1 class="text-2xl font-bold text-slate-900">
                            ${App.util.esc(title)}
                        </h1>

                    </div>

                    ${content}

                </main>

            </div>
            `;
        },

        list() {

            const s = App.state;

            let surveys = s.data.surveys.filter(
                survey => !survey.deleted
            );

            if (s.keyword) {
                const keyword = s.keyword.toLowerCase();

                surveys = surveys.filter(
                    survey =>
                        String(survey.title)
                            .toLowerCase()
                            .includes(keyword)
                );
            }

            if (s.statusFilter !== 'all') {
                surveys = surveys.filter(
                    survey =>
                        survey.status === s.statusFilter
                );
            }

            surveys.sort((a, b) => {

                if (s.sort === 'updated_desc') {
                    return String(b.updated_at)
                        .localeCompare(String(a.updated_at));
                }

                if (s.sort === 'updated_asc') {
                    return String(a.updated_at)
                        .localeCompare(String(b.updated_at));
                }

                if (s.sort === 'responses_desc') {
                    return App.util.responseCount(b.id) -
                        App.util.responseCount(a.id);
                }

                if (s.sort === 'responses_asc') {
                    return App.util.responseCount(a.id) -
                        App.util.responseCount(b.id);
                }

                if (s.sort === 'start_desc') {
                    return String(b.start_at)
                        .localeCompare(String(a.start_at));
                }

                return String(a.start_at)
                    .localeCompare(String(b.start_at));
            });

            const rows = surveys.map(survey => {

                const responseCount =
                    App.util.responseCount(survey.id);

                let actions = '';

                if (survey.status === 'active') {

                    actions = `
                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-sm hover:bg-slate-50"
                        onclick="App.actions.editSurvey('${survey.id}')">
                        確認・編集
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-sm hover:bg-slate-50"
                        onclick="App.actions.openAnalytics('${survey.id}')">
                        集計
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700"
                        onclick="App.actions.openMail('${survey.id}')">
                        送信
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-rose-200 text-rose-600 text-sm hover:bg-rose-50"
                        onclick="App.actions.stopSurvey('${survey.id}')">
                        停止
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-sm hover:bg-slate-50"
                        onclick="App.actions.duplicateSurvey('${survey.id}')">
                        複製
                    </button>
                    `;

                } else if (survey.status === 'draft') {

                    actions = `
                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-sm hover:bg-slate-50"
                        onclick="App.actions.editSurvey('${survey.id}')">
                        確認・編集
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm hover:bg-emerald-700"
                        onclick="App.actions.publishSurvey('${survey.id}')">
                        公開
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-rose-200 text-rose-600 text-sm hover:bg-rose-50"
                        onclick="App.actions.deleteSurvey('${survey.id}')">
                        削除
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-sm hover:bg-slate-50"
                        onclick="App.actions.duplicateSurvey('${survey.id}')">
                        複製
                    </button>
                    `;

                } else {

                    actions = `
                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-sm hover:bg-slate-50"
                        onclick="App.actions.editSurvey('${survey.id}')">
                        確認・編集
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-sm hover:bg-slate-50"
                        onclick="App.actions.openAnalytics('${survey.id}')">
                        集計
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-sm hover:bg-slate-50"
                        onclick="App.actions.duplicateSurvey('${survey.id}')">
                        複製
                    </button>
                    `;
                }

                return `
                <tr class="border-b border-slate-100 hover:bg-slate-50/70">

                    <td class="px-5 py-5 whitespace-nowrap text-sm">
                        <div>${App.util.formatDate(survey.created_at).split(' ')[0]}</div>
                        <div class="text-xs text-slate-400 mt-1">
                            更新: ${App.util.formatDate(survey.updated_at).split(' ')[0]}
                        </div>
                    </td>

                    <td class="px-5 py-5 min-w-[260px]">
                        <div class="font-bold text-slate-900">
                            ${App.util.esc(survey.title)}
                        </div>
                    </td>

                    <td class="px-5 py-5 whitespace-nowrap text-sm text-slate-600">
                        ${
                            survey.start_at
                                ? App.util.formatDate(survey.start_at)
                                : '未設定'
                        }
                        <div class="text-xs text-slate-400 my-1">～</div>
                        ${
                            survey.end_at
                                ? App.util.formatDate(survey.end_at)
                                : '未設定'
                        }
                    </td>

                    <td class="px-5 py-5">
                        <span
                            class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold ${App.util.statusClass(survey.status)}">
                            ${App.util.statusLabel(survey.status)}
                        </span>
                    </td>

                    <td class="px-5 py-5 text-right font-semibold">
                        ${responseCount} 件
                    </td>

                    <td class="px-5 py-5">
                        <div class="flex flex-wrap gap-2">
                            ${actions}
                        </div>
                    </td>

                </tr>
                `;
            }).join('');

            const content = `

            <div class="flex flex-wrap items-center justify-between gap-4 mb-5">

                <div class="flex gap-3">

                    <input
                        id="survey_title"
                        value="${App.util.esc(s.keyword)}"
                        placeholder="タイトルを検索"
                        onkeydown="if(event.key==='Enter') App.actions.searchSurveys(this.value)"
                        class="w-72 px-4 py-2.5 bg-white border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-100">

                    <select
                        onchange="App.actions.toggleStatusFilter(this.value)"
                        class="px-4 py-2.5 bg-white border border-slate-200 rounded-xl">

                        <option value="all" ${s.statusFilter === 'all' ? 'selected' : ''}>
                            すべて
                        </option>

                        <option value="active" ${s.statusFilter === 'active' ? 'selected' : ''}>
                            公開中
                        </option>

                        <option value="draft" ${s.statusFilter === 'draft' ? 'selected' : ''}>
                            下書き
                        </option>

                        <option value="ended" ${s.statusFilter === 'ended' ? 'selected' : ''}>
                            終了
                        </option>

                    </select>

                    <select
                        onchange="App.actions.changeSort(this.value)"
                        class="px-4 py-2.5 bg-white border border-slate-200 rounded-xl">

                        <option value="updated_desc" ${s.sort === 'updated_desc' ? 'selected' : ''}>
                            更新日：新しい順
                        </option>

                        <option value="updated_asc" ${s.sort === 'updated_asc' ? 'selected' : ''}>
                            更新日：古い順
                        </option>

                        <option value="responses_desc" ${s.sort === 'responses_desc' ? 'selected' : ''}>
                            回答数：多い順
                        </option>

                        <option value="responses_asc" ${s.sort === 'responses_asc' ? 'selected' : ''}>
                            回答数：少ない順
                        </option>

                        <option value="start_desc" ${s.sort === 'start_desc' ? 'selected' : ''}>
                            開始日：新しい順
                        </option>

                        <option value="start_asc" ${s.sort === 'start_asc' ? 'selected' : ''}>
                            開始日：古い順
                        </option>

                    </select>

                </div>

                <button
                    onclick="App.actions.newSurvey()"
                    class="px-5 py-3 bg-blue-600 text-white rounded-xl font-semibold hover:bg-blue-700 shadow-sm">
                    ＋ 新規アンケート作成
                </button>

            </div>

            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

                <div class="overflow-x-auto">

                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b border-slate-200">

                            <tr class="text-xs text-slate-500">

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
                                rows ||
                                `<tr><td colspan="6" class="px-6 py-16 text-center text-slate-400">
                                    該当するアンケートはありません。
                                </td></tr>`
                            }
                        </tbody>

                    </table>

                </div>

            </div>
            `;

            return App.render.shell(
                content,
                'アンケート一覧'
            );
        },

        editor() {

            const survey = App.state.editingSurvey;

            const groups = survey.groups || [];

            const groupHtml = groups.map((group, gi) => {

                const questions = group.questions || [];

                const questionHtml = questions.map((question, qi) => {

                    const options = (question.options || [])
                        .map((option, oi) => `
                            <div class="flex gap-2 mb-2">
                                <input
                                    class="option-input flex-1 px-3 py-2 border border-slate-200 rounded-lg"
                                    data-group="${App.util.esc(group.id)}"
                                    data-question="${App.util.esc(question.id)}"
                                    data-option="${oi}"
                                    value="${App.util.esc(option)}">

                                <button
                                    onclick="App.actions.removeOption('${group.id}','${question.id}',${oi})"
                                    class="px-3 text-rose-500 hover:bg-rose-50 rounded-lg">
                                    ×
                                </button>
                            </div>
                        `).join('');

                    return `
                    <div
                        data-question-id="${App.util.esc(question.id)}"
                        class="question-item bg-white border border-slate-200 rounded-xl p-5 shadow-sm mb-3">

                        <div class="flex items-start gap-4">

                            <div class="question-handle cursor-grab text-slate-300 text-xl pt-1">
                                ⠿
                            </div>

                            <div class="flex-1">

                                <div class="flex items-center justify-between mb-4">

                                    <div class="flex items-center gap-3">

                                        <span
                                            data-number-for="${App.util.esc(question.id)}"
                                            class="question-number font-bold text-blue-600">
                                            Q${gi + 1}-${qi + 1}
                                        </span>

                                        <span class="text-xs px-2 py-1 rounded bg-slate-100 text-slate-500">
                                            ${App.util.typeLabel(question.type)}
                                        </span>

                                    </div>

                                    <button
                                        onclick="App.actions.removeQuestion('${group.id}','${question.id}')"
                                        class="text-sm text-rose-500 hover:text-rose-700">
                                        質問を削除
                                    </button>

                                </div>

                                <input
                                    class="question-text w-full px-4 py-3 border border-slate-200 rounded-xl mb-4 font-medium"
                                    data-group="${App.util.esc(group.id)}"
                                    data-question="${App.util.esc(question.id)}"
                                    value="${App.util.esc(question.text)}"
                                    placeholder="質問文を入力">

                                <div class="grid grid-cols-2 gap-4">

                                    <div>

                                        <label class="block text-xs font-semibold text-slate-500 mb-2">
                                            回答形式
                                        </label>

                                        <select
                                            onchange="App.actions.changeQuestionType('${group.id}','${question.id}',this.value)"
                                            class="w-full px-3 py-2.5 border border-slate-200 rounded-lg">

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

                                    <div class="flex items-end gap-4">

                                        <label class="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                ${question.required ? 'checked' : ''}
                                                onchange="App.actions.toggleRequired('${group.id}','${question.id}',this.checked)"
                                                class="w-4 h-4">
                                            <span class="text-sm">必須回答</span>
                                        </label>

                                        ${
                                            question.type !== 'text'
                                                ? `
                                                <label class="flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        ${question.other_enabled ? 'checked' : ''}
                                                        onchange="App.actions.toggleOther('${group.id}','${question.id}',this.checked)"
                                                        class="w-4 h-4">
                                                    <span class="text-sm">その他</span>
                                                </label>
                                                `
                                                : ''
                                        }

                                    </div>

                                </div>

                                ${
                                    question.type !== 'text'
                                    ? `
                                    <div class="mt-5">

                                        <div class="flex items-center justify-between mb-2">

                                            <label class="text-xs font-semibold text-slate-500">
                                                選択肢
                                            </label>

                                            <button
                                                onclick="App.actions.addOption('${group.id}','${question.id}')"
                                                class="text-sm text-blue-600 hover:text-blue-800">
                                                ＋ 選択肢追加
                                            </button>

                                        </div>

                                        ${options}

                                    </div>
                                    `
                                    : `
                                    <div class="mt-5">
                                        <textarea
                                            disabled
                                            class="w-full h-24 px-4 py-3 border border-slate-200 rounded-xl bg-slate-50"
                                            placeholder="回答者が自由記述する欄"></textarea>
                                    </div>
                                    `
                                }

                            </div>

                        </div>

                    </div>
                    `;
                }).join('');

                return `
                <section
                    data-group-id="${App.util.esc(group.id)}"
                    class="group-item bg-slate-50 border border-slate-200 rounded-2xl p-5 mb-5">

                    <div class="flex items-center justify-between mb-4">

                        <div class="flex items-center gap-3">

                            <span class="group-handle cursor-grab text-slate-300 text-xl">
                                ⠿
                            </span>

                            <input
                                class="group-name px-3 py-2 bg-white border border-slate-200 rounded-lg font-semibold"
                                data-group="${App.util.esc(group.id)}"
                                value="${App.util.esc(group.name)}">

                        </div>

                        <div class="flex gap-2">

                            <button
                                onclick="App.actions.addQuestion('${group.id}')"
                                class="px-3 py-2 bg-blue-50 text-blue-700 rounded-lg text-sm font-semibold">
                                ＋ 質問追加
                            </button>

                            <button
                                onclick="App.actions.removeGroup('${group.id}')"
                                class="px-3 py-2 text-rose-500 rounded-lg text-sm hover:bg-rose-50">
                                グループ削除
                            </button>

                        </div>

                    </div>

                    <div
                        class="question-list"
                        data-question-list="${App.util.esc(group.id)}">

                        ${questionHtml}

                    </div>

                </section>
                `;
            }).join('');

            const content = `

            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">

                <div class="flex flex-wrap items-center justify-between gap-4 pb-6 border-b border-slate-100">

                    <div class="flex-1">

                        <input
                            id="survey_title"
                            value="${App.util.esc(survey.title)}"
                            placeholder="アンケートタイトル"
                            class="w-full max-w-3xl text-2xl font-bold border-0 border-b-2 border-slate-200 focus:border-blue-500 outline-none pb-2">

                    </div>

                    <div class="flex gap-2">

                        <button
                            onclick="App.actions.previewSurvey()"
                            class="px-4 py-2.5 bg-white border border-slate-200 rounded-xl">
                            プレビュー
                        </button>

                        ${
                            survey.status === 'draft'
                            ? `
                            <button
                                onclick="App.actions.publishFromEditor()"
                                class="px-4 py-2.5 bg-emerald-600 text-white rounded-xl font-semibold">
                                公開する
                            </button>
                            `
                            : ''
                        }

                        ${
                            survey.status === 'active'
                            ? `
                            <button
                                onclick="App.actions.stopFromEditor()"
                                class="px-4 py-2.5 bg-rose-600 text-white rounded-xl font-semibold">
                                停止する
                            </button>
                            `
                            : ''
                        }

                        <button
                            onclick="App.actions.saveSurvey()"
                            class="px-4 py-2.5 bg-blue-600 text-white rounded-xl font-semibold">
                            保存して一覧へ戻る
                        </button>

                        <button
                            onclick="App.actions.cancelEditor()"
                            class="px-4 py-2.5 bg-white border border-slate-200 rounded-xl">
                            キャンセル
                        </button>

                    </div>

                </div>

                <div class="grid grid-cols-3 gap-4 py-6">

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-2">
                            開始日時
                        </label>

                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.util.esc(survey.start_at)}"
                            class="w-full px-4 py-3 border border-slate-200 rounded-xl">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-2">
                            終了日時
                        </label>

                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.util.esc(survey.end_at)}"
                            class="w-full px-4 py-3 border border-slate-200 rounded-xl">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-2">
                            質問番号形式
                        </label>

                        <select
                            id="survey_numbering_mode"
                            onchange="App.actions.changeNumberingMode(this.value)"
                            class="w-full px-4 py-3 border border-slate-200 rounded-xl">

                            <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                Q1, Q2, Q3...
                            </option>

                            <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                Q1-1, Q1-2...
                            </option>

                        </select>
                    </div>

                </div>

                <div class="flex items-center justify-between mb-4">

                    <div>
                        <h2 class="font-bold text-lg">アンケート構成</h2>
                        <p class="text-sm text-slate-400">
                            グループ・質問はドラッグ＆ドロップで並べ替えできます。
                        </p>
                    </div>

                    <button
                        onclick="App.actions.addGroup()"
                        class="px-4 py-2.5 bg-slate-900 text-white rounded-xl">
                        ＋ グループ追加
                    </button>

                </div>

                <div id="question_editor">
                    ${groupHtml}
                </div>

                ${
                    groups.length === 0
                    ? `
                    <div class="text-center py-16 border-2 border-dashed border-slate-200 rounded-2xl">
                        <p class="text-slate-400 mb-4">
                            グループがありません。
                        </p>
                        <button
                            onclick="App.actions.addGroup()"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg">
                            最初のグループを追加
                        </button>
                    </div>
                    `
                    : ''
                }

            </div>
            `;

            return App.render.shell(
                content,
                'アンケート作成・編集'
            );
        },

        analytics() {

            const survey = App.util.survey(
                App.state.currentSurveyId
            );

            if (!survey) {
                return App.render.list();
            }

            const responses =
                App.state.data.responses.filter(
                    r => r.survey_id === survey.id
                );

            const customers =
                App.state.data.customers;

            const sentCustomers =
                customers.filter(
                    c => Number(c.send_count || 0) > 0
                );

            const answeredFromCustomers =
                responses.filter(
                    r => r.customer_id &&
                    sentCustomers.some(
                        c => c.id === r.customer_id
                    )
                );

            const unanswered =
                sentCustomers.length -
                answeredFromCustomers.length;

            const rate =
                sentCustomers.length
                    ? (
                        answeredFromCustomers.length /
                        sentCustomers.length *
                        100
                    ).toFixed(1)
                    : '0.0';

            const questions =
                App.util.questions(survey);

            const selected =
                App.state.selectedQuestions;

            const questionFilter = questions.map(
                (question, index) => `
                <label class="flex items-center gap-2 text-sm">

                    <input
                        type="checkbox"
                        ${selected[question.id] !== false ? 'checked' : ''}
                        onchange="App.actions.toggleAnalyticsQuestion('${question.id}',this.checked)"
                        class="w-4 h-4">

                    <span>
                        Q${index + 1}
                    </span>

                    <span class="px-2 py-0.5 rounded bg-slate-100 text-xs">
                        ${App.util.typeLabel(question.type)}
                    </span>

                </label>
                `
            ).join('');

            const charts = questions
                .filter(question => selected[question.id] !== false)
                .map((question, index) => {

                    if (question.type === 'text') {

                        const texts = responses
                            .map(r => ({
                                response: r,
                                value: r.answers?.[question.id] ?? ''
                            }))
                            .filter(x => x.value !== '');

                        return `
                        <div class="bg-white border border-slate-200 rounded-2xl p-6 mb-5">

                            <div class="flex items-center justify-between mb-5">

                                <div>
                                    <div class="text-xs text-blue-600 font-semibold">
                                        Q${index + 1}
                                    </div>

                                    <h3 class="font-bold mt-1">
                                        ${App.util.esc(question.text)}
                                    </h3>
                                </div>

                                <span class="text-xs bg-slate-100 px-3 py-1 rounded-full">
                                    自由記述
                                </span>

                            </div>

                            <div class="space-y-3 max-h-80 overflow-y-auto">

                                ${
                                    texts.length
                                    ? texts.map(item => `
                                        <div class="p-4 rounded-xl bg-slate-50">

                                            <div class="text-xs text-slate-400 mb-2">
                                                ${App.util.esc(item.response.company)}
                                                /
                                                ${App.util.esc(item.response.name)}
                                                ・
                                                ${App.util.esc(item.response.answered_at)}
                                            </div>

                                            <div class="text-sm">
                                                ${App.util.esc(item.value)}
                                            </div>

                                        </div>
                                    `).join('')
                                    : `
                                    <div class="py-10 text-center text-slate-400">
                                        現在、回答データはありません
                                    </div>
                                    `
                                }

                            </div>

                        </div>
                        `;
                    }

                    const values = {};

                    (question.options || []).forEach(
                        option => values[option] = 0
                    );

                    let otherCount = 0;

                    responses.forEach(response => {

                        const value =
                            response.answers?.[question.id];

                        if (Array.isArray(value)) {

                            value.forEach(v => {

                                if (
                                    Object.prototype.hasOwnProperty
                                        .call(values, v)
                                ) {
                                    values[v]++;
                                } else {
                                    otherCount++;
                                }

                            });

                        } else if (value) {

                            if (
                                Object.prototype.hasOwnProperty
                                    .call(values, value)
                            ) {
                                values[value]++;
                            } else {
                                otherCount++;
                            }

                        }
                    });

                    const total =
                        responses.length || 1;

                    const bars =
                        Object.entries(values).map(
                            ([label, count]) => {

                                const percent =
                                    count / total * 100;

                                return `
                                <div class="mb-4">

                                    <div class="flex justify-between text-sm mb-1">

                                        <span>
                                            ${App.util.esc(label)}
                                        </span>

                                        <span class="font-semibold">
                                            ${count}件
                                            /
                                            ${percent.toFixed(1)}%
                                        </span>

                                    </div>

                                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">

                                        <div
                                            class="h-full bg-blue-500 rounded-full"
                                            style="width:${Math.min(percent,100)}%">
                                        </div>

                                    </div>

                                </div>
                                `;
                            }
                        ).join('');

                    return `
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 mb-5">

                        <div class="flex items-start justify-between mb-6">

                            <div>
                                <div class="text-xs text-blue-600 font-semibold">
                                    Q${index + 1}
                                </div>

                                <h3 class="font-bold mt-1">
                                    ${App.util.esc(question.text)}
                                </h3>
                            </div>

                            <span class="text-xs bg-slate-100 px-3 py-1 rounded-full">
                                ${App.util.typeLabel(question.type)}
                            </span>

                        </div>

                        ${bars}

                        ${
                            question.other_enabled
                            ? `
                            <button
                                onclick="App.actions.showOtherAnswers('${question.id}')"
                                class="mt-3 text-sm text-blue-600 hover:underline">
                                その他の回答を見る
                                (${otherCount}件)
                            </button>
                            `
                            : ''
                        }

                    </div>
                    `;
                }).join('');

            const responseRows =
                responses.filter(r => {

                    if (!App.state.responseKeyword) {
                        return true;
                    }

                    const q =
                        App.state.responseKeyword.toLowerCase();

                    return String(r.company)
                        .toLowerCase()
                        .includes(q) ||
                        String(r.name)
                        .toLowerCase()
                        .includes(q);

                }).map(response => `

                    <tr class="border-b border-slate-100">

                        <td class="px-4 py-4">
                            ${App.util.esc(response.company)}
                        </td>

                        <td class="px-4 py-4">
                            ${App.util.esc(response.name)}
                        </td>

                        <td class="px-4 py-4 text-sm text-slate-500">
                            ${App.util.esc(response.answered_at)}
                        </td>

                        <td class="px-4 py-4">
                            <button
                                onclick="App.actions.showResponse('${response.id}')"
                                class="px-3 py-2 rounded-lg bg-blue-50 text-blue-700 text-sm">
                                全回答を表示
                            </button>
                        </td>

                    </tr>

                `).join('');

            const content = `

            <div class="mb-5 flex items-center justify-between">

                <div>
                    <div class="text-xs text-slate-400">
                        集計対象アンケート
                    </div>

                    <div class="text-xl font-bold mt-1">
                        ${App.util.esc(survey.title)}
                    </div>
                </div>

                <div class="flex gap-2">

                    <button
                        onclick="App.actions.downloadCSV('${survey.id}')"
                        class="px-4 py-2.5 bg-white border border-slate-200 rounded-xl">
                        CSV出力
                    </button>

                    <button
                        onclick="window.print()"
                        class="px-4 py-2.5 bg-white border border-slate-200 rounded-xl">
                        PDF / 印刷
                    </button>

                </div>

            </div>

            <div class="grid grid-cols-5 gap-4 mb-6">

                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <div class="text-xs text-slate-400">送信対象者数</div>
                    <div class="text-3xl font-bold mt-2">${sentCustomers.length}</div>
                    <div class="text-xs text-slate-400 mt-1">人</div>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <div class="text-xs text-slate-400">回答数</div>
                    <div class="text-3xl font-bold mt-2">${responses.length}</div>
                    <div class="text-xs text-slate-400 mt-1">件</div>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <div class="text-xs text-slate-400">未登録顧客からの回答</div>
                    <div class="text-3xl font-bold mt-2">
                        ${responses.filter(r =>
                            !sentCustomers.some(c => c.id === r.customer_id)
                        ).length}
                    </div>
                    <div class="text-xs text-slate-400 mt-1">件</div>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <div class="text-xs text-slate-400">未回答数</div>
                    <div class="text-3xl font-bold mt-2">${Math.max(unanswered,0)}</div>
                    <div class="text-xs text-slate-400 mt-1">人</div>
                </div>

                <div class="bg-blue-600 text-white rounded-2xl p-5">
                    <div class="text-xs text-blue-100">回答率</div>
                    <div class="text-3xl font-bold mt-2">${rate}%</div>
                    <div class="text-xs text-blue-100 mt-1">送信対象者基準</div>
                </div>

            </div>

            <div class="grid grid-cols-[280px_1fr] gap-5">

                <aside class="bg-white border border-slate-200 rounded-2xl p-5 h-fit">

                    <div class="font-bold mb-4">
                        設問絞り込み
                    </div>

                    <div class="flex gap-2 mb-4">

                        <button
                            onclick="App.actions.selectAllAnalytics(true)"
                            class="text-xs text-blue-600">
                            一括選択
                        </button>

                        <button
                            onclick="App.actions.selectAllAnalytics(false)"
                            class="text-xs text-slate-500">
                            全解除
                        </button>

                    </div>

                    <div class="space-y-3">
                        ${questionFilter}
                    </div>

                </aside>

                <div>

                    ${
                        responses.length === 0
                        ? `
                        <div class="bg-white border border-slate-200 rounded-2xl p-16 text-center text-slate-400">
                            現在、回答データはありません
                        </div>
                        `
                        : charts
                    }

                </div>

            </div>

            <div class="bg-white border border-slate-200 rounded-2xl mt-6 overflow-hidden">

                <div class="p-5 border-b border-slate-100 flex justify-between items-center">

                    <div class="font-bold">
                        個別回答一覧
                    </div>

                    <input
                        id="response_filter"
                        value="${App.util.esc(App.state.responseKeyword)}"
                        oninput="App.actions.filterResponses(this.value)"
                        placeholder="会社名・氏名で検索"
                        class="px-3 py-2 border border-slate-200 rounded-lg">

                </div>

                <div class="overflow-x-auto">

                    <table id="response_table" class="w-full text-left">

                        <thead class="bg-slate-50 text-xs text-slate-500">

                            <tr>
                                <th class="px-4 py-3">会社名</th>
                                <th class="px-4 py-3">氏名</th>
                                <th class="px-4 py-3">回答日時</th>
                                <th class="px-4 py-3">操作</th>
                            </tr>

                        </thead>

                        <tbody>
                            ${
                                responseRows ||
                                `<tr><td colspan="4" class="p-10 text-center text-slate-400">
                                    回答データはありません。
                                </td></tr>`
                            }
                        </tbody>

                    </table>

                </div>

            </div>
            `;

            return App.render.shell(
                content,
                '回答集計・分析'
            );
        },

        mail() {

            const survey =
                App.util.survey(App.state.currentSurveyId);

            if (!survey) {
                return App.render.list();
            }

            const keyword =
                App.state.customerKeyword.toLowerCase();

            const customers =
                App.state.data.customers.filter(c => {

                    if (!keyword) return true;

                    return (
                        String(c.company).toLowerCase().includes(keyword) ||
                        String(c.name).toLowerCase().includes(keyword) ||
                        String(c.email).toLowerCase().includes(keyword)
                    );
                });

            const rows = customers.map(customer => {

                const disabled =
                    customer.source === 'web';

                const checked =
                    App.state.selectedCustomers
                        .includes(customer.id);

                return `
                <tr class="border-b border-slate-100">

                    <td class="px-4 py-4">

                        <input
                            type="checkbox"
                            ${checked ? 'checked' : ''}
                            ${disabled ? 'disabled' : ''}
                            onchange="App.actions.toggleCustomer('${customer.id}',this.checked)"
                            class="w-4 h-4">

                    </td>

                    <td class="px-4 py-4">

                        <div class="font-bold">
                            ${App.util.esc(customer.company)}
                        </div>

                        <div class="text-sm mt-1">
                            ${App.util.esc(customer.name)}
                        </div>

                        <div class="text-xs text-slate-400">
                            ${App.util.esc(customer.email)}
                        </div>

                    </td>

                    <td class="px-4 py-4 text-sm">

                        ${
                            customer.sent_at
                            ? App.util.formatDate(customer.sent_at)
                            : '未送信'
                        }

                        <div class="text-xs text-slate-400 mt-1">
                            送信回数: ${customer.send_count || 0}回
                        </div>

                    </td>

                    <td class="px-4 py-4">

                        <span class="px-2.5 py-1 rounded-full text-xs ${
                            customer.answer_status === 'answered'
                                ? 'bg-emerald-50 text-emerald-700'
                                : 'bg-amber-50 text-amber-700'
                        }">

                            ${
                                customer.answer_status === 'answered'
                                    ? '回答済み'
                                    : '送信済み（未回答）'
                            }

                        </span>

                    </td>

                    <td class="px-4 py-4">

                        ${
                            customer.kintone_status === 'registered'
                            ? `
                            <span class="text-xs text-emerald-600">
                                ✓ キントーン登録完了
                            </span>
                            `
                            : `
                            <button
                                onclick="App.actions.registerKintone('${customer.id}')"
                                class="px-3 py-2 rounded-lg bg-amber-50 text-amber-700 text-xs">
                                キントーン登録完了
                            </button>
                            `
                        }

                    </td>

                    <td class="px-4 py-4">

                        ${
                            customer.source === 'web'
                            ? `<span class="text-xs text-slate-400">Web直接回答者</span>`
                            : ''
                        }

                    </td>

                </tr>
                `;
            }).join('');

            const content = `

            <div class="bg-white border border-slate-200 rounded-2xl p-6">

                <div class="flex items-center justify-between mb-6">

                    <div>

                        <div class="text-xs text-slate-400">
                            ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
                        </div>

                        <h2 class="text-xl font-bold mt-2">
                            ${App.util.esc(survey.title)}
                        </h2>

                    </div>

                    <button
                        onclick="App.actions.sendMail()"
                        class="px-5 py-3 bg-blue-600 text-white rounded-xl font-semibold">
                        選択した顧客へ一括送信
                    </button>

                </div>

                <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 mb-6 text-sm">

                    kintone未登録の回答者が存在する場合は、
                    回答後に「キントーン登録完了」を押してください。

                </div>

                <div class="grid grid-cols-2 gap-5 mb-6">

                    <div>

                        <label class="text-xs font-semibold text-slate-500">
                            メールテンプレート
                        </label>

                        <select
                            id="template_type"
                            onchange="App.actions.templateChanged(this.value)"
                            class="w-full mt-2 px-4 py-3 border border-slate-200 rounded-xl">

                            <option value="initial">
                                初回送信用
                            </option>

                            <option value="reminder">
                                再送・リマインド用
                            </option>

                        </select>

                    </div>

                    <div>

                        <label class="text-xs font-semibold text-slate-500">
                            顧客検索
                        </label>

                        <input
                            id="customer_filter"
                            oninput="App.actions.filterCustomers(this.value)"
                            placeholder="顧客名・会社名・メールアドレス"
                            class="w-full mt-2 px-4 py-3 border border-slate-200 rounded-xl">

                    </div>

                </div>

                <div class="grid grid-cols-2 gap-5 mb-6">

                    <div>

                        <label class="block text-xs font-semibold text-slate-500 mb-2">
                            件名
                        </label>

                        <input
                            id="mail_subject"
                            value="【アンケートのお願い】{顧客名}様"
                            class="w-full px-4 py-3 border border-slate-200 rounded-xl">

                    </div>

                    <div>

                        <label class="block text-xs font-semibold text-slate-500 mb-2">
                            変数
                        </label>

                        <div class="text-sm text-slate-500 p-3 bg-slate-50 rounded-xl">
                            {顧客名}　{アンケートURL}
                        </div>

                    </div>

                </div>

                <div class="mb-6">

                    <label class="block text-xs font-semibold text-slate-500 mb-2">
                        本文
                    </label>

                    <textarea
                        id="mail_body"
                        rows="8"
                        class="w-full px-4 py-3 border border-slate-200 rounded-xl">{$
'顧客名'
                    }様

いつもお世話になっております。

下記URLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力よろしくお願いいたします。</textarea>

                </div>

                <div class="border border-slate-200 rounded-xl overflow-hidden">

                    <div class="bg-slate-50 px-4 py-3 flex items-center gap-3">

                        <input
                            id="select_all"
                            type="checkbox"
                            onchange="App.actions.toggleAllCustomers(this.checked)"
                            class="w-4 h-4">

                        <span class="text-sm font-semibold">
                            全選択
                        </span>

                        <span class="text-xs text-slate-400">
                            選択中: ${App.state.selectedCustomers.length}件
                        </span>

                    </div>

                    <div class="overflow-x-auto">

                        <table id="customer_table" class="w-full text-left">

                            <thead class="text-xs text-slate-500 border-b border-slate-200">

                                <tr>
                                    <th class="px-4 py-3">選択</th>
                                    <th class="px-4 py-3">会社名 / 氏名 / メール</th>
                                    <th class="px-4 py-3">送信履歴</th>
                                    <th class="px-4 py-3">回答ステータス</th>
                                    <th class="px-4 py-3">kintone</th>
                                    <th class="px-4 py-3">区分</th>
                                </tr>

                            </thead>

                            <tbody>
                                ${rows}
                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-6 mt-6">

                <h3 class="font-bold mb-4">
                    一括送信ログ
                </h3>

                <div class="overflow-x-auto">

                    <table class="w-full text-left text-sm">

                        <thead class="text-xs text-slate-500 bg-slate-50">

                            <tr>
                                <th class="px-4 py-3">日時</th>
                                <th class="px-4 py-3">種別</th>
                                <th class="px-4 py-3">件名</th>
                                <th class="px-4 py-3">宛先</th>
                            </tr>

                        </thead>

                        <tbody>

                            ${
                                App.state.data.mail_logs
                                    .filter(log => log.survey_id === survey.id)
                                    .slice()
                                    .reverse()
                                    .map(log => `
                                        <tr class="border-b border-slate-100">

                                            <td class="px-4 py-3">
                                                ${App.util.esc(log.sent_at)}
                                            </td>

                                            <td class="px-4 py-3">
                                                ${
                                                    log.template_type === 'reminder'
                                                    ? 'リマインド'
                                                    : '初回'
                                                }
                                            </td>

                                            <td class="px-4 py-3">
                                                ${App.util.esc(log.subject)}
                                            </td>

                                            <td class="px-4 py-3">
                                                ${App.util.esc(log.recipient_email)}
                                            </td>

                                        </tr>
                                    `).join('')
                                ||
                                `<tr>
                                    <td colspan="4" class="px-4 py-10 text-center text-slate-400">
                                        送信履歴はありません。
                                    </td>
                                </tr>`
                            }

                        </tbody>

                    </table>

                </div>

            </div>
            `;

            return App.render.shell(
                content,
                '顧客選択・メール送信'
            );
        },

        settings() {

            const settings =
                App.state.data.settings || {};

            const content = `

            <div class="max-w-4xl">

                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">

                    <div class="text-xs text-slate-400 mb-5">
                        ホーム ＞ システム設定 ＞ kintone連携設定
                    </div>

                    <div class="grid grid-cols-2 gap-5">

                        <div class="col-span-2">

                            <label class="block text-xs font-semibold text-slate-500 mb-2">
                                サブドメイン
                            </label>

                            <input
                                id="setting_subdomain"
                                value="${App.util.esc(settings.subdomain || '')}"
                                placeholder="xxxx.cybozu.com または xxxx"
                                class="w-full px-4 py-3 border border-slate-200 rounded-xl">

                        </div>

                        <div>

                            <label class="block text-xs font-semibold text-slate-500 mb-2">
                                ログイン名
                            </label>

                            <input
                                id="setting_login_name"
                                value="${App.util.esc(settings.login_name || '')}"
                                class="w-full px-4 py-3 border border-slate-200 rounded-xl">

                        </div>

                        <div>

                            <label class="block text-xs font-semibold text-slate-500 mb-2">
                                パスワード
                            </label>

                            <input
                                id="setting_password"
                                type="password"
                                value="${App.util.esc(settings.password || '')}"
                                class="w-full px-4 py-3 border border-slate-200 rounded-xl">

                        </div>

                        <div>

                            <label class="block text-xs font-semibold text-slate-500 mb-2">
                                顧客管理アプリID
                            </label>

                            <input
                                id="setting_app_id"
                                value="${App.util.esc(settings.app_id || '')}"
                                class="w-full px-4 py-3 border border-slate-200 rounded-xl">

                        </div>

                        <div>

                            <label class="block text-xs font-semibold text-slate-500 mb-2">
                                Proxyサーバ
                            </label>

                            <input
                                id="setting_proxy"
                                value="${App.util.esc(settings.proxy || '')}"
                                placeholder="host:port"
                                class="w-full px-4 py-3 border border-slate-200 rounded-xl">

                        </div>

                        <div class="col-span-2">

                            <label class="flex items-center gap-3">

                                <input
                                    id="setting_ssl_verify"
                                    type="checkbox"
                                    ${settings.ssl_verify ? 'checked' : ''}
                                    class="w-4 h-4">

                                <span class="text-sm">
                                    SSL証明書検証を有効にする
                                </span>

                            </label>

                            <p class="text-xs text-slate-400 mt-2">
                                要件上は開発環境等でSSL証明書検証をスキップできます。
                            </p>

                        </div>

                    </div>

                    <div class="flex items-center justify-between mt-8 mb-4">

                        <div>
                            <h2 class="font-bold">
                                kintone項目マッピング
                            </h2>

                            <p class="text-xs text-slate-400 mt-1">
                                「項目一覧を再取得」でkintone APIから取得します。
                            </p>
                        </div>

                        <button
                            onclick="App.actions.fetchKintoneFields()"
                            class="px-4 py-2.5 bg-slate-900 text-white rounded-xl">
                            項目一覧を再取得
                        </button>

                    </div>

                    <div
                        id="field_message"
                        class="mb-4 text-sm">
                    </div>

                    <div class="space-y-4">

                        ${App.render.fieldSelect(
                            '会社名',
                            'field_company',
                            settings.field_company || ''
                        )}

                        ${App.render.fieldSelect(
                            '氏名',
                            'field_name',
                            settings.field_name || ''
                        )}

                        ${App.render.fieldSelect(
                            'メールアドレス',
                            'field_email',
                            settings.field_email || ''
                        )}

                        ${App.render.fieldSelect(
                            '部署名',
                            'field_department',
                            settings.field_department || ''
                        )}

                        ${App.render.fieldSelect(
                            '電話番号',
                            'field_phone',
                            settings.field_phone || ''
                        )}

                        ${App.render.fieldSelectMulti(
                            '住所',
                            settings.field_address || []
                        )}

                    </div>

                    <div class="flex justify-end gap-3 mt-8">

                        <button
                            onclick="App.actions.saveSettings()"
                            class="px-5 py-3 bg-blue-600 text-white rounded-xl font-semibold">
                            設定を保存
                        </button>

                    </div>

                </div>

            </div>
            `;

            return App.render.shell(
                content,
                'キントーン連携設定'
            );
        },

        fieldSelect(label, key, selected) {

            const fields =
                App.state.kintoneFields || [];

            return `
            <div>

                <label class="block text-sm font-semibold mb-2">
                    ${App.util.esc(label)}
                </label>

                <select
                    data-field-key="${key}"
                    class="w-full px-4 py-3 border border-slate-200 rounded-xl">

                    <option value="">
                        -- 選択してください --
                    </option>

                    ${
                        fields.map(field => `
                            <option
                                value="${App.util.esc(field.code)}"
                                ${selected === field.code ? 'selected' : ''}>
                                ${App.util.esc(field.label)}
                                （${App.util.esc(field.code)}）
                            </option>
                        `).join('')
                    }

                </select>

            </div>
            `;
        },

        fieldSelectMulti(label, selected) {

            const fields =
                App.state.kintoneFields || [];

            return `
            <div>

                <label class="block text-sm font-semibold mb-2">
                    ${App.util.esc(label)}
                    <span class="text-xs text-slate-400 font-normal">
                        複数選択可
                    </span>
                </label>

                <select
                    multiple
                    data-field-key="field_address"
                    class="w-full px-4 py-3 border border-slate-200 rounded-xl min-h-32">

                    ${
                        fields.map(field => `
                            <option
                                value="${App.util.esc(field.code)}"
                                ${selected.includes(field.code) ? 'selected' : ''}>
                                ${App.util.esc(field.label)}
                                （${App.util.esc(field.code)}）
                            </option>
                        `).join('')
                    }

                </select>

            </div>
            `;
        }
    },

    actions: {

        async goList() {

            App.state.page = 'list';

            App.state.currentSurveyId = null;

            App.state.editingSurvey = null;

            App.renderApp();
        },

        goSettings() {

            App.state.page = 'settings';

            App.renderApp();
        },

        searchSurveys(value) {

            App.state.keyword = value;

            App.renderApp();
        },

        toggleStatusFilter(value) {

            App.state.statusFilter = value;

            App.renderApp();
        },

        changeSort(value) {

            App.state.sort = value;

            App.renderApp();
        },

        newSurvey() {

            App.state.editingSurvey = {
                id: App.util.id('survey'),
                title: '新規アンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: App.util.now(),
                updated_at: App.util.now(),
                numbering_mode: 'global',
                deleted: false,
                groups: [
                    {
                        id: App.util.id('group'),
                        name: '基本情報',
                        questions: [
                            {
                                id: App.util.id('question'),
                                text: '',
                                type: 'single',
                                required: true,
                                options: ['はい', 'いいえ'],
                                other_enabled: false
                            }
                        ]
                    }
                ]
            };

            App.state.page = 'editor';

            App.renderApp();

            App.actions.enableSortable();
        },

        editSurvey(id) {

            const survey =
                App.util.survey(id);

            if (!survey) return;

            App.state.editingSurvey =
                JSON.parse(JSON.stringify(survey));

            App.state.page = 'editor';

            App.renderApp();

            App.actions.enableSortable();
        },

        async saveSurvey() {

            App.actions.collectEditor();

            const survey =
                App.state.editingSurvey;

            try {

                const result =
                    await App.api.post(
                        'save_survey',
                        {
                            survey_json: survey
                        }
                    );

                App.state.data =
                    result.data;

                App.state.page = 'list';

                App.state.editingSurvey = null;

                App.util.showToast(
                    'アンケートを保存しました。'
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        },

        cancelEditor() {

            if (
                App.util.confirm(
                    '変更を破棄して一覧へ戻りますか？'
                )
            ) {
                App.actions.goList();
            }
        },

        async publishSurvey(id) {

            const survey =
                App.util.survey(id);

            if (!survey) return;

            if (
                !App.util.confirm(
                    '「' +
                    survey.title +
                    '」を公開しますか？\n\n公開すると一覧画面に「送信」ボタンが表示されます。'
                )
            ) {
                return;
            }

            try {

                const result =
                    await App.api.post(
                        'change_status',
                        {
                            survey_id: id,
                            status: 'active'
                        }
                    );

                App.state.data =
                    result.data;

                App.util.showToast(
                    'アンケートを公開しました。'
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        },

        async publishFromEditor() {

            App.actions.collectEditor();

            try {

                const save =
                    await App.api.post(
                        'save_survey',
                        {
                            survey_json:
                                App.state.editingSurvey
                        }
                    );

                App.state.data =
                    save.data;

                const result =
                    await App.api.post(
                        'change_status',
                        {
                            survey_id:
                                App.state.editingSurvey.id,
                            status: 'active'
                        }
                    );

                App.state.data =
                    result.data;

                App.state.page = 'list';

                App.state.editingSurvey = null;

                App.util.showToast(
                    'アンケートを公開しました。'
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        },

        async stopSurvey(id) {

            const survey =
                App.util.survey(id);

            if (!survey) return;

            if (
                !App.util.confirm(
                    '「' +
                    survey.title +
                    '」を停止しますか？'
                )
            ) {
                return;
            }

            try {

                const result =
                    await App.api.post(
                        'change_status',
                        {
                            survey_id: id,
                            status: 'ended'
                        }
                    );

                App.state.data =
                    result.data;

                App.util.showToast(
                    'アンケートを停止しました。'
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        },

        async stopFromEditor() {

            App.actions.collectEditor();

            if (
                !App.util.confirm(
                    'このアンケートを停止しますか？'
                )
            ) {
                return;
            }

            try {

                const save =
                    await App.api.post(
                        'save_survey',
                        {
                            survey_json:
                                App.state.editingSurvey
                        }
                    );

                App.state.data =
                    save.data;

                const result =
                    await App.api.post(
                        'change_status',
                        {
                            survey_id:
                                App.state.editingSurvey.id,
                            status: 'ended'
                        }
                    );

                App.state.data =
                    result.data;

                App.state.page = 'list';

                App.state.editingSurvey = null;

                App.util.showToast(
                    'アンケートを停止しました。'
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        },

        async deleteSurvey(id) {

            if (
                !App.util.confirm(
                    'この下書きを削除しますか？'
                )
            ) {
                return;
            }

            try {

                const result =
                    await App.api.post(
                        'delete_survey',
                        {
                            survey_id: id
                        }
                    );

                App.state.data =
                    result.data;

                App.util.showToast(
                    '削除しました。'
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        },

        async duplicateSurvey(id) {

            try {

                const result =
                    await App.api.post(
                        'duplicate_survey',
                        {
                            survey_id: id
                        }
                    );

                App.state.data =
                    result.data;

                App.util.showToast(
                    '下書きとして複製しました。'
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        },

        collectEditor() {

            const survey =
                App.state.editingSurvey;

            const title =
                document.getElementById('survey_title');

            const start =
                document.getElementById('survey_start_at');

            const end =
                document.getElementById('survey_end_at');

            const numbering =
                document.getElementById('survey_numbering_mode');

            if (title) {
                survey.title = title.value;
            }

            if (start) {
                survey.start_at = start.value;
            }

            if (end) {
                survey.end_at = end.value;
            }

            if (numbering) {
                survey.numbering_mode =
                    numbering.value;
            }

            document
                .querySelectorAll('.group-name')
                .forEach(input => {

                    const group =
                        survey.groups.find(
                            g =>
                                g.id ===
                                input.dataset.group
                        );

                    if (group) {
                        group.name = input.value;
                    }
                });

            document
                .querySelectorAll('.question-text')
                .forEach(input => {

                    const group =
                        survey.groups.find(
                            g =>
                                g.id ===
                                input.dataset.group
                        );

                    if (!group) return;

                    const question =
                        group.questions.find(
                            q =>
                                q.id ===
                                input.dataset.question
                        );

                    if (question) {
                        question.text =
                            input.value;
                    }
                });

            document
                .querySelectorAll('.option-input')
                .forEach(input => {

                    const group =
                        survey.groups.find(
                            g =>
                                g.id ===
                                input.dataset.group
                        );

                    if (!group) return;

                    const question =
                        group.questions.find(
                            q =>
                                q.id ===
                                input.dataset.question
                        );

                    if (!question) return;

                    const index =
                        Number(input.dataset.option);

                    question.options[index] =
                        input.value;
                });

            survey.updated_at =
                App.util.now();

            App.actions.renumber();
        },

        addGroup() {

            App.actions.collectEditor();

            App.state.editingSurvey.groups.push({
                id: App.util.id('group'),
                name: '新しいグループ',
                questions: []
            });

            App.renderApp();

            App.actions.enableSortable();
        },

        addQuestion(groupId) {

            App.actions.collectEditor();

            const group =
                App.state.editingSurvey.groups.find(
                    g => g.id === groupId
                );

            if (!group) return;

            group.questions.push({
                id: App.util.id('question'),
                text: '新しい質問',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false
            });

            App.renderApp();

            App.actions.enableSortable();
        },

        removeGroup(groupId) {

            App.actions.collectEditor();

            if (
                !App.util.confirm(
                    'このグループと内包する質問を削除しますか？'
                )
            ) {
                return;
            }

            App.state.editingSurvey.groups =
                App.state.editingSurvey.groups.filter(
                    g => g.id !== groupId
                );

            App.renderApp();

            App.actions.enableSortable();
        },

        removeQuestion(groupId, questionId) {

            App.actions.collectEditor();

            const group =
                App.state.editingSurvey.groups.find(
                    g => g.id === groupId
                );

            if (!group) return;

            group.questions =
                group.questions.filter(
                    q => q.id !== questionId
                );

            App.renderApp();

            App.actions.enableSortable();
        },

        changeQuestionType(
            groupId,
            questionId,
            type
        ) {

            App.actions.collectEditor();

            const group =
                App.state.editingSurvey.groups.find(
                    g => g.id === groupId
                );

            const question =
                group?.questions.find(
                    q => q.id === questionId
                );

            if (!question) return;

            question.type = type;

            if (type === 'text') {
                question.options = [];
                question.other_enabled = false;
            } else if (!question.options.length) {
                question.options = [
                    '選択肢1',
                    '選択肢2'
                ];
            }

            App.renderApp();

            App.actions.enableSortable();
        },

        toggleRequired(
            groupId,
            questionId,
            checked
        ) {

            App.actions.collectEditor();

            const group =
                App.state.editingSurvey.groups.find(
                    g => g.id === groupId
                );

            const question =
                group?.questions.find(
                    q => q.id === questionId
                );

            if (question) {
                question.required = checked;
            }
        },

        toggleOther(
            groupId,
            questionId,
            checked
        ) {

            App.actions.collectEditor();

            const group =
                App.state.editingSurvey.groups.find(
                    g => g.id === groupId
                );

            const question =
                group?.questions.find(
                    q => q.id === questionId
                );

            if (question) {
                question.other_enabled = checked;
            }
        },

        addOption(groupId, questionId) {

            App.actions.collectEditor();

            const group =
                App.state.editingSurvey.groups.find(
                    g => g.id === groupId
                );

            const question =
                group?.questions.find(
                    q => q.id === questionId
                );

            if (!question) return;

            question.options.push(
                '選択肢' +
                (question.options.length + 1)
            );

            App.renderApp();

            App.actions.enableSortable();
        },

        removeOption(
            groupId,
            questionId,
            index
        ) {

            App.actions.collectEditor();

            const group =
                App.state.editingSurvey.groups.find(
                    g => g.id === groupId
                );

            const question =
                group?.questions.find(
                    q => q.id === questionId
                );

            if (!question) return;

            question.options.splice(index, 1);

            App.renderApp();

            App.actions.enableSortable();
        },

        changeNumberingMode(value) {

            App.actions.collectEditor();

            App.state.editingSurvey.numbering_mode =
                value;

            App.actions.renumber();
        },

        renumber() {

            const survey =
                App.state.editingSurvey;

            if (!survey) return;

            let global = 1;

            survey.groups.forEach(
                (group, gi) => {

                    group.questions.forEach(
                        (question, qi) => {

                            let number;

                            if (
                                survey.numbering_mode ===
                                'group'
                            ) {
                                number =
                                    'Q' +
                                    (gi + 1) +
                                    '-' +
                                    (qi + 1);
                            } else {
                                number =
                                    'Q' +
                                    global;
                            }

                            global++;

                            const element =
                                document.querySelector(
                                    '[data-number-for="' +
                                    question.id +
                                    '"]'
                                );

                            if (element) {
                                element.textContent =
                                    number;
                            }
                        }
                    );
                }
            );
        },

        enableSortable() {

            if (
                typeof Sortable === 'undefined'
            ) {
                return;
            }

            const editor =
                document.getElementById(
                    'question_editor'
                );

            if (!editor) return;

            new Sortable(
                editor,
                {
                    animation: 180,
                    handle: '.group-handle',
                    ghostClass: 'opacity-40',

                    onEnd() {

                        App.actions.collectEditor();

                        const ids =
                            [...editor.children]
                                .map(
                                    el =>
                                        el.dataset.groupId
                                );

                        const groups = [];

                        ids.forEach(id => {

                            const group =
                                App.state.editingSurvey.groups.find(
                                    g => g.id === id
                                );

                            if (group) {
                                groups.push(group);
                            }
                        });

                        App.state.editingSurvey.groups =
                            groups;

                        App.actions.renumber();
                    }
                }
            );

            editor
                .querySelectorAll('.question-list')
                .forEach(list => {

                    new Sortable(
                        list,
                        {
                            group: 'surveyQuestions',
                            animation: 180,
                            handle: '.question-handle',
                            ghostClass: 'opacity-40',

                            onEnd() {

                                App.actions.collectEditor();

                                const allLists =
                                    editor.querySelectorAll(
                                        '.question-list'
                                    );

                                const groups =
                                    App.state.editingSurvey.groups;

                                allLists.forEach(
                                    questionList => {

                                        const groupId =
                                            questionList.dataset.questionList;

                                        const group =
                                            groups.find(
                                                g =>
                                                    g.id ===
                                                    groupId
                                            );

                                        if (!group) return;

                                        const ids =
                                            [...questionList.children]
                                                .map(
                                                    el =>
                                                        el.dataset.questionId
                                                );

                                        const questions = [];

                                        ids.forEach(
                                            id => {

                                                for (
                                                    const g of groups
                                                ) {

                                                    const q =
                                                        g.questions.find(
                                                            item =>
                                                                item.id === id
                                                        );

                                                    if (q) {
                                                        questions.push(q);
                                                        break;
                                                    }
                                                }
                                            }
                                        );

                                        group.questions =
                                            questions;
                                    }
                                );

                                /*
                                 * グループを跨いだ質問移動にも対応。
                                 * DOM上の所属グループを基準に再構成。
                                 */
                                const assigned =
                                    new Set();

                                allLists.forEach(
                                    questionList => {

                                        const groupId =
                                            questionList.dataset.questionList;

                                        const group =
                                            groups.find(
                                                g =>
                                                    g.id ===
                                                    groupId
                                            );

                                        if (!group) return;

                                        group.questions =
                                            [...questionList.children]
                                                .map(
                                                    el => {

                                                        const id =
                                                            el.dataset.questionId;

                                                        for (
                                                            const sourceGroup of groups
                                                        ) {

                                                            const question =
                                                                sourceGroup.questions.find(
                                                                    q =>
                                                                        q.id === id
                                                                );

                                                            if (
                                                                question &&
                                                                !assigned.has(id)
                                                            ) {
                                                                assigned.add(id);
                                                                return question;
                                                            }
                                                        }

                                                        return null;
                                                    }
                                                )
                                                .filter(Boolean);
                                    }
                                );

                                App.actions.renumber();
                            }
                        }
                    );
                });
        },

        previewSurvey() {

            App.actions.collectEditor();

            const survey =
                App.state.editingSurvey;

            const modal =
                document.createElement('div');

            modal.id = 'preview_modal';

            modal.className =
                'fixed inset-0 z-[80] bg-black/50 flex items-center justify-center p-6';

            modal.innerHTML = `
                <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-hidden">

                    <div class="p-4 border-b border-slate-200 flex justify-between items-center">

                        <div class="font-bold">
                            プレビュー
                        </div>

                        <div class="flex gap-2">

                            <button
                                onclick="App.actions.previewResize('pc')"
                                class="px-3 py-2 rounded-lg bg-slate-100 text-sm">
                                PC表示
                            </button>

                            <button
                                onclick="App.actions.previewResize('mobile')"
                                class="px-3 py-2 rounded-lg bg-slate-100 text-sm">
                                スマートフォン表示
                            </button>

                            <button
                                onclick="document.getElementById('preview_modal').remove()"
                                class="px-3 py-2 text-slate-500">
                                閉じる
                            </button>

                        </div>

                    </div>

                    <div
                        id="preview_content"
                        class="overflow-y-auto p-8 bg-slate-100">

                        <div
                            class="${
                                App.state.previewMode === 'mobile'
                                ? 'max-w-sm'
                                : 'max-w-2xl'
                            } mx-auto bg-white rounded-2xl p-7 shadow-sm">

                            <h1 class="text-2xl font-bold mb-8">
                                ${App.util.esc(survey.title)}
                            </h1>

                            ${
                                survey.groups.map(
                                    (group, gi) => `
                                    <div class="mb-8">

                                        <h2 class="font-bold text-lg mb-4">
                                            ${App.util.esc(group.name)}
                                        </h2>

                                        ${
                                            group.questions.map(
                                                (q, qi) => {

                                                    const number =
                                                        survey.numbering_mode === 'group'
                                                        ? 'Q' + (gi + 1) + '-' + (qi + 1)
                                                        : 'Q' +
                                                            (
                                                                App.util.questions(survey)
                                                                    .findIndex(x => x.id === q.id) + 1
                                                            );

                                                    return `
                                                    <div class="mb-7">

                                                        <div class="font-semibold mb-3">
                                                            <span class="text-blue-600 mr-2">
                                                                ${number}
                                                            </span>
                                                            ${App.util.esc(q.text || '質問文未入力')}
                                                            ${
                                                                q.required
                                                                ? '<span class="text-rose-500 ml-1">*</span>'
                                                                : ''
                                                            }
                                                        </div>

                                                        ${
                                                            q.type === 'text'
                                                            ? `
                                                                <textarea
                                                                    class="w-full border border-slate-200 rounded-xl p-3"
                                                                    rows="4"
                                                                    placeholder="回答を入力"></textarea>
                                                            `
                                                            : q.options.map(
                                                                option => `
                                                                <label class="flex items-center gap-3 mb-3">

                                                                    <input
                                                                        type="${
                                                                            q.type === 'single'
                                                                            ? 'radio'
                                                                            : 'checkbox'
                                                                        }"
                                                                        name="${q.id}"
                                                                        class="w-4 h-4">

                                                                    <span>
                                                                        ${App.util.esc(option)}
                                                                    </span>

                                                                </label>
                                                                `
                                                            ).join('')
                                                        }

                                                    </div>
                                                    `;
                                                }
                                            ).join('')
                                        }

                                    </div>
                                    `
                                ).join('')
                            }

                            <button
                                onclick="alert('プレビュー中のため、実際には送信されません。')"
                                class="w-full bg-blue-600 text-white rounded-xl py-3 font-semibold">
                                回答を送信する
                            </button>

                        </div>

                    </div>

                </div>
            `;

            document.body.appendChild(modal);
        },

        previewResize(mode) {

            App.state.previewMode =
                mode;

            const modal =
                document.getElementById(
                    'preview_modal'
                );

            if (modal) {
                modal.remove();
            }

            App.actions.previewSurvey();
        },

        openAnalytics(id) {

            App.state.currentSurveyId = id;

            App.state.page = 'analytics';

            App.state.responseKeyword = '';

            const survey =
                App.util.survey(id);

            if (survey) {

                App.state.selectedQuestions = {};

                App.util.questions(survey)
                    .forEach(
                        q =>
                            App.state.selectedQuestions[q.id] =
                                true
                    );
            }

            App.renderApp();
        },

        filterResponses(value) {

            App.state.responseKeyword =
                value;

            App.renderApp();
        },

        toggleAnalyticsQuestion(
            questionId,
            checked
        ) {

            App.state.selectedQuestions[
                questionId
            ] = checked;

            App.renderApp();
        },

        selectAllAnalytics(checked) {

            const survey =
                App.util.survey(
                    App.state.currentSurveyId
                );

            if (!survey) return;

            App.util.questions(survey)
                .forEach(
                    q =>
                        App.state.selectedQuestions[q.id] =
                            checked
                );

            App.renderApp();
        },

        showOtherAnswers(questionId) {

            const survey =
                App.util.survey(
                    App.state.currentSurveyId
                );

            if (!survey) return;

            const question =
                App.util.questions(survey)
                    .find(q => q.id === questionId);

            if (!question) return;

            const values =
                App.state.data.responses
                    .filter(r => r.survey_id === survey.id)
                    .map(r => ({
                        r,
                        value: r.answers?.[questionId]
                    }))
                    .filter(
                        x =>
                            x.value &&
                            !question.options.includes(x.value)
                    );

            const html = values.length
                ? values.map(x => `
                    <div class="border-b border-slate-100 py-3">

                        <div class="text-xs text-slate-400">
                            ${App.util.esc(x.r.company)}
                            /
                            ${App.util.esc(x.r.name)}
                        </div>

                        <div class="mt-1">
                            ${App.util.esc(
                                Array.isArray(x.value)
                                    ? x.value.join('、')
                                    : x.value
                            )}
                        </div>

                    </div>
                `).join('')
                : '<div class="py-8 text-center text-slate-400">その他の回答はありません。</div>';

            App.actions.openSimpleModal(
                'その他の回答',
                html
            );
        },

        showResponse(responseId) {

            const response =
                App.state.data.responses.find(
                    r => r.id === responseId
                );

            if (!response) return;

            const survey =
                App.util.survey(
                    response.survey_id
                );

            if (!survey) return;

            const questions =
                App.util.questions(survey);

            const html = `

                <div class="space-y-5">

                    <div class="p-4 bg-slate-50 rounded-xl">

                        <div class="font-bold">
                            ${App.util.esc(response.company)}
                        </div>

                        <div class="text-sm mt-1">
                            ${App.util.esc(response.name)}
                        </div>

                        <div class="text-xs text-slate-400 mt-2">
                            ${App.util.esc(response.email)}
                            /
                            ${App.util.esc(response.answered_at)}
                        </div>

                    </div>

                    ${
                        questions.map(
                            (q, i) => {

                                let value =
                                    response.answers?.[q.id] ??
                                    '';

                                if (Array.isArray(value)) {
                                    value =
                                        value.join('、');
                                }

                                return `
                                <div>

                                    <div class="text-xs text-blue-600 font-semibold">
                                        Q${i + 1}
                                    </div>

                                    <div class="font-semibold mt-1">
                                        ${App.util.esc(q.text)}
                                    </div>

                                    <div class="mt-2 p-3 bg-slate-50 rounded-lg">
                                        ${App.util.esc(value || '未回答')}
                                    </div>

                                </div>
                                `;
                            }
                        ).join('')
                    }

                </div>
            `;

            App.actions.openSimpleModal(
                '全回答',
                html
            );
        },

        openSimpleModal(title, html) {

            const existing =
                document.getElementById(
                    'response_modal'
                );

            if (existing) {
                existing.remove();
            }

            const modal =
                document.createElement('div');

            modal.id = 'response_modal';

            modal.className =
                'fixed inset-0 z-[90] bg-black/50 flex items-center justify-center p-6';

            modal.innerHTML = `

                <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[85vh] overflow-hidden">

                    <div class="p-5 border-b border-slate-200 flex justify-between">

                        <h2 class="font-bold">
                            ${App.util.esc(title)}
                        </h2>

                        <button
                            onclick="document.getElementById('response_modal').remove()"
                            class="text-slate-400">
                            ✕
                        </button>

                    </div>

                    <div
                        id="response_detail"
                        class="p-6 overflow-y-auto max-h-[70vh]">

                        ${html}

                    </div>

                </div>
            `;

            document.body.appendChild(modal);
        },

        openMail(id) {

            const survey =
                App.util.survey(id);

            if (!survey) return;

            if (survey.status !== 'active') {

                App.util.showToast(
                    '公開中のアンケートのみ送信できます。',
                    'error'
                );

                return;
            }

            App.state.currentSurveyId = id;

            App.state.page = 'mail';

            App.state.selectedCustomers = [];

            App.renderApp();
        },

        filterCustomers(value) {

            App.state.customerKeyword =
                value;

            App.renderApp();
        },

        toggleCustomer(id, checked) {

            if (checked) {

                if (
                    !App.state.selectedCustomers
                        .includes(id)
                ) {
                    App.state.selectedCustomers.push(id);
                }

            } else {

                App.state.selectedCustomers =
                    App.state.selectedCustomers.filter(
                        x => x !== id
                    );
            }

            App.renderApp();
        },

        toggleAllCustomers(checked) {

            if (checked) {

                App.state.selectedCustomers =
                    App.state.data.customers
                        .filter(
                            c =>
                                c.source !== 'web'
                        )
                        .map(c => c.id);

            } else {

                App.state.selectedCustomers = [];
            }

            App.renderApp();
        },

        templateChanged(value) {

            const subject =
                document.getElementById(
                    'mail_subject'
                );

            const body =
                document.getElementById(
                    'mail_body'
                );

            if (!subject || !body) return;

            if (value === 'reminder') {

                subject.value =
                    '【再送】アンケートご回答のお願い';

                body.value =
`{顧客名}様

先日ご案内したアンケートについて、まだご回答を確認できていないため再度ご案内いたします。

{アンケートURL}

お忙しいところ恐れ入りますが、ご協力をお願いいたします。`;

            } else {

                subject.value =
                    '【アンケートのお願い】{顧客名}様';

                body.value =
`{顧客名}様

いつもお世話になっております。

下記URLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力よろしくお願いいたします。`;
            }
        },

        async sendMail() {

            const survey =
                App.util.survey(
                    App.state.currentSurveyId
                );

            if (!survey) return;

            if (
                survey.status !== 'active'
            ) {

                App.util.showToast(
                    '公開中のアンケートのみ送信できます。',
                    'error'
                );

                return;
            }

            if (
                App.state.selectedCustomers.length === 0
            ) {

                App.util.showToast(
                    '送信先を選択してください。',
                    'error'
                );

                return;
            }

            const selected =
                App.state.data.customers.filter(
                    c =>
                        App.state.selectedCustomers
                            .includes(c.id)
                );

            const alreadySent =
                selected.filter(
                    c =>
                        Number(c.send_count || 0) > 0
                );

            if (alreadySent.length) {

                if (
                    !App.util.confirm(
                        '既に送信済みの宛先が含まれています。\n再送しますか？'
                    )
                ) {
                    return;
                }
            }

            const subject =
                document.getElementById(
                    'mail_subject'
                )?.value || '';

            const body =
                document.getElementById(
                    'mail_body'
                )?.value || '';

            const template =
                document.getElementById(
                    'template_type'
                )?.value || 'initial';

            if (
                !App.util.confirm(
                    App.state.selectedCustomers.length +
                    '件の顧客へ送信します。\nよろしいですか？'
                )
            ) {
                return;
            }

            try {

                const result =
                    await App.api.post(
                        'send_mail',
                        {
                            survey_id:
                                survey.id,

                            recipient_ids:
                                App.state.selectedCustomers,

                            mail_subject:
                                subject,

                            mail_body:
                                body,

                            template_type:
                                template
                        }
                    );

                App.state.data =
                    result.data;

                App.state.selectedCustomers =
                    [];

                App.util.showToast(
                    result.message
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        },

        async registerKintone(id) {

            if (
                !App.util.confirm(
                    'この顧客をkintone登録完了として扱いますか？'
                )
            ) {
                return;
            }

            try {

                const result =
                    await App.api.post(
                        'register_kintone_customer',
                        {
                            customer_id: id
                        }
                    );

                App.state.data =
                    result.data;

                App.util.showToast(
                    '登録完了に変更しました。'
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        },

        downloadCSV(id) {

            const form =
                document.createElement('form');

            form.method = 'POST';

            form.action =
                window.location.href;

            form.style.display = 'none';

            const fields = {
                action: 'csv',
                survey_id: id,
                csrf_token:
                    document.getElementById(
                        'csrf_token'
                    ).value
            };

            Object.keys(fields).forEach(
                key => {

                    const input =
                        document.createElement(
                            'input'
                        );

                    input.type = 'hidden';
                    input.name = key;
                    input.value = fields[key];

                    form.appendChild(input);
                }
            );

            document.body.appendChild(form);

            form.submit();

            form.remove();
        },

        async fetchKintoneFields() {

            const appId =
                document.getElementById(
                    'setting_app_id'
                )?.value || '';

            if (!appId) {

                const message =
                    document.getElementById(
                        'field_message'
                    );

                if (message) {
                    message.textContent =
                        'アプリIDを入力してください。';
                    message.className =
                        'mb-4 text-sm text-rose-600';
                }

                return;
            }

            const message =
                document.getElementById(
                    'field_message'
                );

            if (message) {
                message.textContent =
                    'kintoneから項目一覧を取得しています...';
                message.className =
                    'mb-4 text-sm text-slate-500';
            }

            try {

                /*
                 * 保存前の入力値も利用できるよう、
                 * 画面上の設定を一時的にAPIへ渡す。
                 */
                const result =
                    await App.api.post(
                        'kintone_fields',
                        {
                            app_id: appId
                        }
                    );

                App.state.kintoneFields =
                    result.fields || [];

                if (message) {
                    message.textContent =
                        '項目一覧を取得しました。';
                    message.className =
                        'mb-4 text-sm text-emerald-600';
                }

                App.renderApp();

            } catch (error) {

                if (message) {
                    message.textContent =
                        error.message;
                    message.className =
                        'mb-4 text-sm text-rose-600';
                } else {
                    App.util.showToast(
                        error.message,
                        'error'
                    );
                }
            }
        },

        async saveSettings() {

            const current =
                App.state.data.settings || {};

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

                proxy:
                    document.getElementById(
                        'setting_proxy'
                    )?.value || '',

                ssl_verify:
                    document.getElementById(
                        'setting_ssl_verify'
                    )?.checked || false,

                field_company:
                    document.querySelector(
                        '[data-field-key="field_company"]'
                    )?.value ||
                    current.field_company ||
                    '',

                field_name:
                    document.querySelector(
                        '[data-field-key="field_name"]'
                    )?.value ||
                    current.field_name ||
                    '',

                field_email:
                    document.querySelector(
                        '[data-field-key="field_email"]'
                    )?.value ||
                    current.field_email ||
                    '',

                field_department:
                    document.querySelector(
                        '[data-field-key="field_department"]'
                    )?.value ||
                    current.field_department ||
                    '',

                field_phone:
                    document.querySelector(
                        '[data-field-key="field_phone"]'
                    )?.value ||
                    current.field_phone ||
                    '',

                field_address:
                    [...document.querySelectorAll(
                        'select[data-field-key="field_address"] option:checked'
                    )].map(
                        option => option.value
                    )

            };

            try {

                const result =
                    await App.api.post(
                        'save_settings',
                        {
                            settings_json:
                                settings
                        }
                    );

                App.state.data =
                    result.data;

                App.util.showToast(
                    '設定を保存しました。'
                );

                App.renderApp();

            } catch (error) {

                App.util.showToast(
                    error.message,
                    'error'
                );
            }
        }
    },

    renderApp() {

        const app =
            document.getElementById('app');

        if (!app) return;

        App.state.loading = false;

        if (App.state.page === 'list') {
            app.innerHTML =
                App.render.list();
        }

        else if (App.state.page === 'editor') {
            app.innerHTML =
                App.render.editor();
        }

        else if (App.state.page === 'analytics') {
            app.innerHTML =
                App.render.analytics();
        }

        else if (App.state.page === 'mail') {
            app.innerHTML =
                App.render.mail();
        }

        else if (App.state.page === 'settings') {
            app.innerHTML =
                App.render.settings();
        }

        if (App.state.page === 'editor') {
            App.actions.enableSortable();
        }
    },

    async init() {

        if (App.state.initialized) {
            return;
        }

        App.state.initialized = true;

        const app =
            document.getElementById('app');

        if (app) {
            app.innerHTML = `
                <div class="min-h-screen flex items-center justify-center">
                    <div class="text-center">
                        <div class="text-xl font-bold">
                            読み込み中...
                        </div>
                        <div class="text-sm text-slate-400 mt-2">
                            アンケートデータを読み込んでいます
                        </div>
                    </div>
                </div>
            `;
        }

        try {

            await App.api.load();

            App.renderApp();

        } catch (error) {

            if (app) {
                app.innerHTML = `
                    <div class="min-h-screen flex items-center justify-center p-6">

                        <div class="bg-white border border-rose-200 rounded-2xl p-8 max-w-lg">

                            <h1 class="font-bold text-xl text-rose-600">
                                読み込みエラー
                            </h1>

                            <p class="mt-3 text-slate-600">
                                ${App.util.esc(error.message)}
                            </p>

                            <button
                                onclick="location.reload()"
                                class="mt-6 px-4 py-2 bg-slate-900 text-white rounded-lg">
                                再読み込み
                            </button>

                        </div>

                    </div>
                `;
            }
        }
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
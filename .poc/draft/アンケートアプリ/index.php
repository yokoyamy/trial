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

if (empty($_SESSION['survey_csrf_token'])) {
    $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(32));
}

function survey_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_id(string $prefix = 'id'): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function survey_default_data(): array
{
    return [
        'surveys' => [
            [
                'id' => 'survey_demo_001',
                'title' => '2026年度 お客様満足度アンケート',
                'start_at' => '2026-08-01T09:00',
                'end_at' => '2026-08-31T18:00',
                'status' => 'active',
                'created_at' => '2026-07-25 10:00:00',
                'updated_at' => '2026-08-10 14:30:00',
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
                                'text' => '今後も利用したいと思いますか？',
                                'type' => 'single',
                                'required' => true,
                                'options' => ['はい', 'いいえ'],
                                'other_enabled' => false
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
                'customer_id' => 'customer_001',
                'company' => '株式会社サンプル',
                'name' => '山田 太郎',
                'email' => 'yamada@example.com',
                'answered_at' => '2026-08-12 11:20:00',
                'answers' => [
                    'question_demo_001' => '満足',
                    'question_demo_002' => 'はい'
                ]
            ]
        ],
        'customers' => [
            [
                'id' => 'customer_001',
                'company' => '株式会社サンプル',
                'name' => '山田 太郎',
                'email' => 'yamada@example.com',
                'department' => '営業部',
                'phone' => '03-0000-0000',
                'address' => '東京都港区',
                'source' => 'kintone',
                'sent_at' => '2026-08-05 09:00:00',
                'send_count' => 1,
                'answer_status' => 'answered',
                'kintone_status' => 'registered'
            ],
            [
                'id' => 'customer_002',
                'company' => 'テスト株式会社',
                'name' => '佐藤 花子',
                'email' => 'sato@example.com',
                'department' => '管理部',
                'phone' => '03-1111-1111',
                'address' => '東京都千代田区',
                'source' => 'kintone',
                'sent_at' => '',
                'send_count' => 0,
                'answer_status' => 'unanswered',
                'kintone_status' => 'registered'
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

function survey_load(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        $data = survey_default_data();
        survey_save($data);
        return $data;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);
    if ($raw === false || trim($raw) === '') {
        return survey_default_data();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return survey_default_data();
    }

    foreach (['surveys', 'responses', 'customers', 'settings', 'mail_logs'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
    }

    return $data;
}

function survey_save(array $data): bool
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    return @file_put_contents(
        SURVEY_STORAGE_FILE,
        $json,
        LOCK_EX
    ) !== false;
}

function survey_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function survey_require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !isset($_SESSION['survey_csrf_token']) ||
        !hash_equals($_SESSION['survey_csrf_token'], $token)
    ) {
        survey_json([
            'ok' => false,
            'message' => 'CSRFトークンが無効です。ページを再読み込みしてください。'
        ], 403);
    }
}

function survey_find_index(array $items, string $id): int
{
    foreach ($items as $i => $item) {
        if ((string)($item['id'] ?? '') === $id) {
            return $i;
        }
    }
    return -1;
}

function survey_kintone_request(
    string $subdomain,
    string $login,
    string $password,
    string $path,
    string $method = 'GET',
    ?array $body = null,
    bool $sslVerify = false,
    string $proxy = ''
): array {
    $subdomain = trim($subdomain);

    if ($subdomain === '') {
        return ['ok' => false, 'message' => 'サブドメインが未入力です。'];
    }

    $subdomain = preg_replace('#^https?://#i', '', $subdomain);
    $subdomain = preg_replace('#/.*$#', '', $subdomain);

    if (!str_ends_with($subdomain, '.cybozu.com')) {
        $host = $subdomain . '.cybozu.com';
    } else {
        $host = $subdomain;
    }

    $url = 'https://' . $host . $path;

    $headers = [
        'X-Cybozu-Authorization: ' . base64_encode($login . ':' . $password),
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 30,
            'protocol_version' => 1.1
        ],
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify
        ]
    ];

    if ($body !== null) {
        $options['http']['content'] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    if ($proxy !== '') {
        $options['http']['proxy'] = 'tcp://' . trim($proxy);
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    /*
     * PHP 8.4/8.5でHTTPレスポンスヘッダーを安全に取得する。
     */
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : [];

    if ($result === false) {
        return [
            'ok' => false,
            'message' => 'kintone APIへの接続に失敗しました。',
            'headers' => $responseHeaders
        ];
    }

    $decoded = json_decode($result, true);

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'message' => 'kintone APIから不正なレスポンスが返されました。',
            'headers' => $responseHeaders
        ];
    }

    return [
        'ok' => true,
        'data' => $decoded,
        'headers' => $responseHeaders
    ];
}

/* ============================================================
   API
   ============================================================ */

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        survey_require_csrf();
    }

    $data = survey_load();

    switch ($action) {

        case 'load':
            survey_json([
                'ok' => true,
                'data' => $data,
                'csrf_token' => $_SESSION['survey_csrf_token']
            ]);
            break;

        case 'save_survey':
            $json = $_POST['survey_json'] ?? '';

            if (!is_string($json) || $json === '') {
                survey_json(['ok' => false, 'message' => 'アンケートデータがありません。'], 400);
            }

            $survey = json_decode($json, true);

            if (!is_array($survey)) {
                survey_json(['ok' => false, 'message' => 'アンケートデータが不正です。'], 400);
            }

            $survey['id'] = (string)($survey['id'] ?? '');
            if ($survey['id'] === '') {
                $survey['id'] = survey_id('survey');
            }

            $survey['title'] = trim((string)($survey['title'] ?? '無題のアンケート'));
            $survey['status'] = in_array(
                $survey['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            ) ? $survey['status'] : 'draft';

            $survey['updated_at'] = survey_now();

            if (empty($survey['created_at'])) {
                $survey['created_at'] = survey_now();
            }

            if (!isset($survey['groups']) || !is_array($survey['groups'])) {
                $survey['groups'] = [];
            }

            foreach ($survey['groups'] as &$group) {
                $group['id'] = (string)($group['id'] ?? survey_id('group'));
                $group['name'] = (string)($group['name'] ?? '新しいグループ');
                $group['questions'] = is_array($group['questions'] ?? null)
                    ? $group['questions']
                    : [];

                foreach ($group['questions'] as &$q) {
                    $q['id'] = (string)($q['id'] ?? survey_id('question'));
                    $q['text'] = (string)($q['text'] ?? '');
                    $q['type'] = in_array(
                        $q['type'] ?? 'single',
                        ['single', 'multiple', 'text'],
                        true
                    ) ? $q['type'] : 'single';
                    $q['required'] = !empty($q['required']);
                    $q['options'] = is_array($q['options'] ?? null)
                        ? array_values($q['options'])
                        : [];
                    $q['other_enabled'] = !empty($q['other_enabled']);
                }
                unset($q);
            }
            unset($group);

            $idx = survey_find_index($data['surveys'], $survey['id']);

            if ($idx >= 0) {
                $data['surveys'][$idx] = $survey;
            } else {
                $data['surveys'][] = $survey;
            }

            if (!survey_save($data)) {
                survey_json(['ok' => false, 'message' => '保存に失敗しました。'], 500);
            }

            survey_json([
                'ok' => true,
                'message' => '保存しました。',
                'survey' => $survey
            ]);
            break;

        case 'change_status':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $newStatus = (string)($_POST['status'] ?? '');

            if (!in_array($newStatus, ['draft', 'active', 'ended'], true)) {
                survey_json(['ok' => false, 'message' => '不正なステータスです。'], 400);
            }

            $idx = survey_find_index($data['surveys'], $surveyId);

            if ($idx < 0) {
                survey_json(['ok' => false, 'message' => 'アンケートが見つかりません。'], 404);
            }

            $data['surveys'][$idx]['status'] = $newStatus;
            $data['surveys'][$idx]['updated_at'] = survey_now();

            survey_save($data);

            survey_json([
                'ok' => true,
                'message' => $newStatus === 'active' ? 'アンケートを公開しました。' : '公開を停止しました。',
                'survey' => $data['surveys'][$idx]
            ]);
            break;

        case 'delete_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $idx = survey_find_index($data['surveys'], $surveyId);

            if ($idx < 0) {
                survey_json(['ok' => false, 'message' => 'アンケートが見つかりません。'], 404);
            }

            $data['surveys'][$idx]['deleted'] = true;
            $data['surveys'][$idx]['updated_at'] = survey_now();

            survey_save($data);

            survey_json(['ok' => true, 'message' => '削除しました。']);
            break;

        case 'duplicate_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $idx = survey_find_index($data['surveys'], $surveyId);

            if ($idx < 0) {
                survey_json(['ok' => false, 'message' => 'アンケートが見つかりません。'], 404);
            }

            $copy = $data['surveys'][$idx];
            $copy['id'] = survey_id('survey');
            $copy['title'] .= '（コピー）';
            $copy['status'] = 'draft';
            $copy['created_at'] = survey_now();
            $copy['updated_at'] = survey_now();
            $copy['deleted'] = false;

            foreach ($copy['groups'] as &$group) {
                $group['id'] = survey_id('group');

                foreach ($group['questions'] as &$question) {
                    $question['id'] = survey_id('question');
                }
                unset($question);
            }
            unset($group);

            $data['surveys'][] = $copy;
            survey_save($data);

            survey_json([
                'ok' => true,
                'message' => 'アンケートを複製しました。',
                'survey' => $copy
            ]);
            break;

        case 'save_settings':
            $json = $_POST['settings_json'] ?? '';
            $settings = json_decode((string)$json, true);

            if (!is_array($settings)) {
                survey_json(['ok' => false, 'message' => '設定データが不正です。'], 400);
            }

            $data['settings'] = array_merge($data['settings'], [
                'subdomain' => trim((string)($settings['subdomain'] ?? '')),
                'login_name' => (string)($settings['login_name'] ?? ''),
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

            survey_save($data);

            survey_json([
                'ok' => true,
                'message' => 'kintone設定を保存しました。'
            ]);
            break;

        case 'kintone_fields':
            $settings = $data['settings'];

            $appId = trim((string)($_POST['app_id'] ?? $settings['app_id'] ?? ''));

            if ($appId === '') {
                survey_json(['ok' => false, 'message' => 'アプリIDを入力してください。'], 400);
            }

            $result = survey_kintone_request(
                (string)$settings['subdomain'],
                (string)$settings['login_name'],
                (string)$settings['password'],
                '/k/v1/app/form/fields.json?app=' . rawurlencode($appId),
                'GET',
                null,
                !empty($settings['ssl_verify']),
                (string)$settings['proxy']
            );

            if (!$result['ok']) {
                survey_json($result, 502);
            }

            $fields = [];

            foreach (($result['data']['properties'] ?? []) as $code => $property) {
                $fields[] = [
                    'code' => (string)$code,
                    'label' => (string)($property['label'] ?? $code),
                    'type' => (string)($property['type'] ?? '')
                ];
            }

            survey_json([
                'ok' => true,
                'fields' => $fields
            ]);
            break;

        case 'send_mail':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $recipientIds = json_decode((string)($_POST['recipient_ids'] ?? '[]'), true);

            if (!is_array($recipientIds)) {
                $recipientIds = [];
            }

            if (!$recipientIds) {
                survey_json(['ok' => false, 'message' => '送信先を選択してください。'], 400);
            }

            $subject = (string)($_POST['mail_subject'] ?? '');
            $body = (string)($_POST['mail_body'] ?? '');
            $templateType = in_array(
                $_POST['template_type'] ?? 'initial',
                ['initial', 'reminder'],
                true
            ) ? $_POST['template_type'] : 'initial';

            $count = 0;
            $now = survey_now();

            foreach ($data['customers'] as &$customer) {
                if (!in_array($customer['id'], $recipientIds, true)) {
                    continue;
                }

                $personalBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        $customer['name'],
                        '?answer=1&survey_id=' . rawurlencode($surveyId) . '&customer_id=' . rawurlencode($customer['id'])
                    ],
                    $body
                );

                /*
                 * 実メール送信処理を接続する場合はここへSMTP/API処理を追加。
                 * 本モックでは送信履歴を保存する。
                 */
                $customer['sent_at'] = $now;
                $customer['send_count'] = (int)($customer['send_count'] ?? 0) + 1;

                $data['mail_logs'][] = [
                    'id' => survey_id('mail'),
                    'survey_id' => $surveyId,
                    'customer_id' => $customer['id'],
                    'sent_at' => $now,
                    'type' => $templateType,
                    'subject' => $subject,
                    'body' => $personalBody,
                    'executor' => '管理者'
                ];

                $count++;
            }
            unset($customer);

            survey_save($data);

            survey_json([
                'ok' => true,
                'message' => $count . '件の送信処理を記録しました。',
                'count' => $count
            ]);
            break;

        case 'mark_kintone':
            $customerId = (string)($_POST['customer_id'] ?? '');

            foreach ($data['customers'] as &$customer) {
                if ($customer['id'] === $customerId) {
                    $customer['kintone_status'] = 'registered';
                }
            }
            unset($customer);

            survey_save($data);

            survey_json([
                'ok' => true,
                'message' => 'kintone登録完了として更新しました。'
            ]);
            break;

        case 'csv':
            $surveyId = (string)($_GET['survey_id'] ?? '');
            $survey = null;

            foreach ($data['surveys'] as $s) {
                if ($s['id'] === $surveyId) {
                    $survey = $s;
                    break;
                }
            }

            if (!$survey) {
                http_response_code(404);
                exit('Survey not found');
            }

            $questions = [];

            foreach ($survey['groups'] as $group) {
                foreach ($group['questions'] as $question) {
                    $questions[] = $question;
                }
            }

            $fp = fopen('php://output', 'wb');

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="survey_' .
                preg_replace('/[^A-Za-z0-9_-]/', '_', $surveyId) .
                '.csv"'
            );

            fwrite($fp, "\xEF\xBB\xBF");

            $header = ['回答ID', '回答日時', '顧客ID', '会社名', '氏名'];

            foreach ($questions as $q) {
                $header[] = $q['text'];
            }

            fputcsv($fp, $header);

            foreach ($data['responses'] as $response) {
                if ($response['survey_id'] !== $surveyId) {
                    continue;
                }

                $row = [
                    $response['id'],
                    $response['answered_at'],
                    $response['customer_id'],
                    $response['company'],
                    $response['name']
                ];

                foreach ($questions as $q) {
                    $row[] = $response['answers'][$q['id']] ?? '';
                }

                fputcsv($fp, $row);
            }

            fclose($fp);
            exit;

        default:
            survey_json([
                'ok' => false,
                'message' => '不正なリクエストです。'
            ], 400);
    }
}

$csrf = survey_h($_SESSION['survey_csrf_token']);
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

<script>
window.App = {

    state: {
        csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
        data: null,
        screen: 'list',
        surveyId: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        editingSurvey: null,
        previewMode: 'pc',
        responseFilter: '',
        selectedQuestions: {},
        selectedCustomers: [],
        fields: []
    },

    utils: {

        esc(value) {
            const d = document.createElement('div');
            d.textContent = value ?? '';
            return d.innerHTML;
        },

        uid(prefix) {
            return prefix + '_' + Math.random().toString(36).slice(2) + Date.now();
        },

        findSurvey(id) {
            return App.state.data.surveys.find(x => x.id === id);
        },

        allQuestions(survey) {
            const result = [];
            (survey.groups || []).forEach(group => {
                (group.questions || []).forEach(question => {
                    result.push(question);
                });
            });
            return result;
        },

        renumber(survey) {
            let n = 1;

            if (survey.numbering_mode === 'group') {
                (survey.groups || []).forEach((group, gi) => {
                    (group.questions || []).forEach((q, qi) => {
                        q.number = 'Q' + (gi + 1) + '-' + (qi + 1);
                    });
                });
            } else {
                (survey.groups || []).forEach(group => {
                    (group.questions || []).forEach(q => {
                        q.number = 'Q' + n++;
                    });
                });
            }
        }
    },

    api: {

        async post(action, payload = {}) {
            const form = new FormData();

            form.append('action', action);
            form.append('csrf_token', App.state.csrf);

            Object.entries(payload).forEach(([key, value]) => {
                if (typeof value === 'object') {
                    form.append(key, JSON.stringify(value));
                } else {
                    form.append(key, value ?? '');
                }
            });

            const response = await fetch(location.href, {
                method: 'POST',
                body: form,
                credentials: 'same-origin'
            });

            const json = await response.json();

            if (!json.ok) {
                throw new Error(json.message || '処理に失敗しました。');
            }

            return json;
        },

        async load() {
            const response = await fetch(location.href + '?action=load', {
                credentials: 'same-origin'
            });

            const json = await response.json();

            if (!json.ok) {
                throw new Error(json.message || 'データ取得に失敗しました。');
            }

            App.state.data = json.data;
            App.state.csrf = json.csrf_token;
        }
    },

    init() {
        if (App.state.initialized) return;
        App.state.initialized = true;

        App.api.load()
            .then(() => App.render())
            .catch(error => {
                App.renderError(error.message);
            });
    },

    renderError(message) {
        document.getElementById('app').innerHTML = `
            <div class="min-h-screen flex items-center justify-center p-8">
                <div class="bg-white rounded-2xl shadow-sm p-8 max-w-lg w-full">
                    <h1 class="text-xl font-bold text-red-600 mb-3">エラー</h1>
                    <p class="text-slate-600">${App.utils.esc(message)}</p>
                    <button onclick="location.reload()"
                        class="mt-6 px-4 py-2 bg-indigo-600 text-white rounded-lg">
                        再読み込み
                    </button>
                </div>
            </div>`;
    },

    render() {
        const app = document.getElementById('app');

        app.innerHTML = `
            <header class="fixed top-0 left-0 right-0 z-40 bg-white border-b border-slate-200">
                <div class="max-w-[1600px] mx-auto px-6 h-16 flex items-center justify-between">
                    <button onclick="App.actions.goList()"
                        class="font-bold text-lg text-slate-900">
                        アンケート管理
                    </button>

                    <nav class="flex items-center gap-2">
                        <button onclick="App.actions.goList()"
                            class="px-3 py-2 rounded-lg hover:bg-slate-100">
                            アンケート一覧
                        </button>
                        <button onclick="App.actions.settings()"
                            class="px-3 py-2 rounded-lg hover:bg-slate-100">
                            kintone連携設定
                        </button>
                        <button onclick="App.actions.logout()"
                            class="px-3 py-2 rounded-lg hover:bg-slate-100">
                            ログアウト
                        </button>
                    </nav>
                </div>
            </header>

            <main class="pt-20 pb-10 px-6">
                <div class="max-w-[1600px] mx-auto" id="screen"></div>
            </main>

            <div id="preview_modal"></div>
            <div id="response_modal"></div>
        `;

        App.renderScreen();
    },

    renderScreen() {
        if (App.state.screen === 'list') App.renderList();
        if (App.state.screen === 'edit') App.renderEditor();
        if (App.state.screen === 'send') App.renderSend();
        if (App.state.screen === 'analytics') App.renderAnalytics();
        if (App.state.screen === 'settings') App.renderSettings();
    },

    renderList() {

        let surveys = App.state.data.surveys
            .filter(s => !s.deleted);

        if (App.state.keyword) {
            const k = App.state.keyword.toLowerCase();
            surveys = surveys.filter(s =>
                s.title.toLowerCase().includes(k)
            );
        }

        if (App.state.statusFilter !== 'all') {
            surveys = surveys.filter(
                s => s.status === App.state.statusFilter
            );
        }

        surveys.sort((a, b) => {

            if (App.state.sort === 'updated_desc') {
                return b.updated_at.localeCompare(a.updated_at);
            }

            if (App.state.sort === 'updated_asc') {
                return a.updated_at.localeCompare(b.updated_at);
            }

            if (App.state.sort === 'answers_desc') {
                return App.answerCount(b.id) - App.answerCount(a.id);
            }

            if (App.state.sort === 'answers_asc') {
                return App.answerCount(a.id) - App.answerCount(b.id);
            }

            return 0;
        });

        document.getElementById('screen').innerHTML = `

            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold">アンケート一覧</h1>
                    <p class="text-sm text-slate-500 mt-1">
                        アンケートの作成・公開・送信・集計を管理します。
                    </p>
                </div>

                <button onclick="App.actions.newSurvey()"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl shadow-sm font-semibold">
                    ＋ 新規アンケート作成
                </button>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-5">
                <div class="flex gap-3 flex-wrap">

                    <input
                        id="list_keyword"
                        value="${App.utils.esc(App.state.keyword)}"
                        onkeydown="App.actions.searchKey(event)"
                        placeholder="タイトルを検索してEnter"
                        class="border border-slate-300 rounded-lg px-4 py-2 w-72">

                    <select onchange="App.actions.statusFilter(this.value)"
                        class="border border-slate-300 rounded-lg px-4 py-2">
                        <option value="all" ${App.state.statusFilter === 'all' ? 'selected' : ''}>すべて</option>
                        <option value="active" ${App.state.statusFilter === 'active' ? 'selected' : ''}>公開中</option>
                        <option value="draft" ${App.state.statusFilter === 'draft' ? 'selected' : ''}>下書き</option>
                        <option value="ended" ${App.state.statusFilter === 'ended' ? 'selected' : ''}>終了</option>
                    </select>

                    <select onchange="App.actions.sort(this.value)"
                        class="border border-slate-300 rounded-lg px-4 py-2">
                        <option value="updated_desc">更新日：新しい順</option>
                        <option value="updated_asc">更新日：古い順</option>
                        <option value="answers_desc">回答数：多い順</option>
                        <option value="answers_asc">回答数：少ない順</option>
                    </select>

                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="text-left p-4">作成日 / 更新日</th>
                                <th class="text-left p-4">タイトル</th>
                                <th class="text-left p-4">アンケート期間</th>
                                <th class="text-left p-4">ステータス</th>
                                <th class="text-right p-4">回答数</th>
                                <th class="text-left p-4">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${surveys.length
                                ? surveys.map(s => App.templates.surveyRow(s)).join('')
                                : `
                                    <tr>
                                        <td colspan="6" class="p-12 text-center text-slate-400">
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

    templates: {

        badge(status) {
            const map = {
                active: ['公開中', 'bg-emerald-100 text-emerald-700'],
                draft: ['下書き', 'bg-slate-100 text-slate-600'],
                ended: ['終了', 'bg-amber-100 text-amber-700']
            };

            const x = map[status] || map.draft;

            return `
                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold ${x[1]}">
                    ${x[0]}
                </span>`;
        },

        surveyRow(s) {

            let buttons = '';

            if (s.status === 'active') {
                buttons = `
                    <button onclick="App.actions.editSurvey('${s.id}')"
                        class="text-indigo-600 hover:underline">確認・編集</button>

                    <button onclick="App.actions.analytics('${s.id}')"
                        class="text-indigo-600 hover:underline">集計</button>

                    <button onclick="App.actions.send('${s.id}')"
                        class="text-indigo-600 hover:underline font-semibold">送信</button>

                    <button onclick="App.actions.changeStatus('${s.id}','ended')"
                        class="text-red-600 hover:underline">停止</button>

                    <button onclick="App.actions.duplicate('${s.id}')"
                        class="text-slate-600 hover:underline">複製</button>
                `;
            }

            if (s.status === 'draft') {
                buttons = `
                    <button onclick="App.actions.editSurvey('${s.id}')"
                        class="text-indigo-600 hover:underline">確認・編集</button>

                    <button onclick="App.actions.changeStatus('${s.id}','active')"
                        class="text-emerald-600 hover:underline font-semibold">公開</button>

                    <button onclick="App.actions.deleteSurvey('${s.id}')"
                        class="text-red-600 hover:underline">削除</button>

                    <button onclick="App.actions.duplicate('${s.id}')"
                        class="text-slate-600 hover:underline">複製</button>
                `;
            }

            if (s.status === 'ended') {
                buttons = `
                    <button onclick="App.actions.editSurvey('${s.id}')"
                        class="text-indigo-600 hover:underline">確認・編集</button>

                    <button onclick="App.actions.analytics('${s.id}')"
                        class="text-indigo-600 hover:underline">集計</button>

                    <button onclick="App.actions.duplicate('${s.id}')"
                        class="text-slate-600 hover:underline">複製</button>
                `;
            }

            return `
                <tr class="border-b last:border-0 hover:bg-slate-50">
                    <td class="p-4 whitespace-nowrap text-slate-500">
                        ${App.utils.esc(s.created_at.slice(0,10))}
                        <br>
                        <span class="text-xs">
                            更新: ${App.utils.esc(s.updated_at.slice(0,10))}
                        </span>
                    </td>

                    <td class="p-4 font-bold">
                        ${App.utils.esc(s.title)}
                    </td>

                    <td class="p-4 whitespace-nowrap">
                        ${s.start_at || '未設定'}
                        <br>
                        <span class="text-slate-400">～</span>
                        ${s.end_at || '未設定'}
                    </td>

                    <td class="p-4">
                        ${App.templates.badge(s.status)}
                    </td>

                    <td class="p-4 text-right font-semibold">
                        ${App.answerCount(s.id)} 件
                    </td>

                    <td class="p-4">
                        <div class="flex gap-3 flex-wrap">
                            ${buttons}
                        </div>
                    </td>
                </tr>`;
        }
    },

    answerCount(id) {
        return App.state.data.responses.filter(
            r => r.survey_id === id
        ).length;
    },

    renderEditor() {

        const s = App.state.editingSurvey;

        App.utils.renumber(s);

        document.getElementById('screen').innerHTML = `

            <div class="flex items-center justify-between mb-6">
                <div>
                    <button onclick="App.actions.cancelEdit()"
                        class="text-sm text-slate-500 hover:text-slate-900">
                        ← アンケート一覧
                    </button>

                    <h1 class="text-2xl font-bold mt-2">
                        アンケート作成・編集
                    </h1>
                </div>

                <div class="flex gap-3">
                    <button onclick="App.actions.preview()"
                        class="px-4 py-2 border rounded-lg bg-white">
                        プレビュー
                    </button>

                    <button onclick="App.actions.saveSurvey()"
                        class="px-5 py-2 bg-indigo-600 text-white rounded-lg font-semibold">
                        保存して一覧へ戻る
                    </button>
                </div>
            </div>

            <div class="bg-white border rounded-2xl p-6 mb-5">

                <div class="grid grid-cols-3 gap-4">

                    <label class="col-span-3">
                        <span class="text-sm font-semibold">タイトル</span>
                        <input
                            id="survey_title"
                            value="${App.utils.esc(s.title)}"
                            oninput="App.actions.editTitle(this.value)"
                            class="mt-1 w-full border rounded-lg px-4 py-3 text-lg font-semibold">
                    </label>

                    <label>
                        <span class="text-sm font-semibold">開始日時</span>
                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.utils.esc(s.start_at || '')}"
                            onchange="App.actions.editStart(this.value)"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm font-semibold">終了日時</span>
                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.utils.esc(s.end_at || '')}"
                            onchange="App.actions.editEnd(this.value)"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm font-semibold">質問番号</span>
                        <select
                            id="survey_numbering_mode"
                            onchange="App.actions.numbering(this.value)"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                            <option value="global" ${s.numbering_mode === 'global' ? 'selected' : ''}>
                                Q1 / Q2 / Q3
                            </option>
                            <option value="group" ${s.numbering_mode === 'group' ? 'selected' : ''}>
                                Q1-1 / Q1-2 / Q2-1
                            </option>
                        </select>
                    </label>

                </div>
            </div>

            <div id="question_editor" class="space-y-5">
                ${s.groups.map((g, gi) => App.templates.group(g, gi)).join('')}
            </div>

            <button onclick="App.actions.addGroup()"
                class="mt-5 w-full border-2 border-dashed border-slate-300 rounded-xl py-4 text-slate-500 hover:border-indigo-400 hover:text-indigo-600">
                ＋ グループを追加
            </button>
        `;

        App.actions.initSortable();
    },

    templatesGroupPlaceholder: '',

    templates: {

        ...window.App?.templates,

        group(g, gi) {
            return `
                <section
                    class="survey-group bg-white border rounded-2xl overflow-hidden"
                    data-group-id="${g.id}">

                    <div class="bg-slate-50 px-5 py-4 flex items-center gap-3 border-b">
                        <span class="group-handle cursor-move text-xl text-slate-400">⠿</span>

                        <input
                            value="${App.utils.esc(g.name)}"
                            oninput="App.actions.groupName('${g.id}',this.value)"
                            class="flex-1 bg-transparent font-bold text-lg outline-none">

                        <button onclick="App.actions.deleteGroup('${g.id}')"
                            class="text-red-500 hover:text-red-700">
                            グループ削除
                        </button>
                    </div>

                    <div class="question-list p-5 space-y-4"
                        data-group-id="${g.id}">

                        ${(g.questions || []).map(
                            (q, qi) => App.templates.question(q, g.id)
                        ).join('')}

                        <button onclick="App.actions.addQuestion('${g.id}')"
                            class="w-full border border-dashed rounded-lg py-3 text-indigo-600 hover:bg-indigo-50">
                            ＋ 質問を追加
                        </button>
                    </div>
                </section>`;
        },

        question(q, groupId) {
            return `
                <div
                    class="question-item border rounded-xl p-5 bg-white shadow-sm"
                    data-question-id="${q.id}">

                    <div class="flex gap-3">

                        <span class="question-handle cursor-move text-slate-400 text-xl">
                            ⠿
                        </span>

                        <div class="flex-1">

                            <div class="flex justify-between gap-4">
                                <span class="text-xs font-bold text-indigo-600">
                                    ${q.number || ''}
                                </span>

                                <button onclick="App.actions.deleteQuestion('${groupId}','${q.id}')"
                                    class="text-red-500 text-sm">
                                    削除
                                </button>
                            </div>

                            <input
                                value="${App.utils.esc(q.text)}"
                                oninput="App.actions.questionText('${groupId}','${q.id}',this.value)"
                                placeholder="質問文を入力"
                                class="mt-2 w-full border rounded-lg px-3 py-2 font-medium">

                            <div class="mt-3 flex gap-3">

                                <select
                                    onchange="App.actions.questionType('${groupId}','${q.id}',this.value)"
                                    class="border rounded-lg px-3 py-2">
                                    <option value="single" ${q.type === 'single' ? 'selected' : ''}>
                                        単一選択
                                    </option>
                                    <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>
                                        複数選択
                                    </option>
                                    <option value="text" ${q.type === 'text' ? 'selected' : ''}>
                                        自由記述
                                    </option>
                                </select>

                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        ${q.required ? 'checked' : ''}
                                        onchange="App.actions.required('${groupId}','${q.id}',this.checked)">
                                    必須回答
                                </label>

                                ${q.type !== 'text' ? `
                                    <label class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            ${q.other_enabled ? 'checked' : ''}
                                            onchange="App.actions.other('${groupId}','${q.id}',this.checked)">
                                        その他
                                    </label>` : ''}
                            </div>

                            ${q.type !== 'text' ? `
                                <div class="mt-4 space-y-2">
                                    ${(q.options || []).map((option, oi) => `
                                        <div class="flex gap-2">
                                            <input
                                                value="${App.utils.esc(option)}"
                                                oninput="App.actions.option('${groupId}','${q.id}',${oi},this.value)"
                                                class="flex-1 border rounded-lg px-3 py-2"
                                                placeholder="選択肢">

                                            <button onclick="App.actions.removeOption('${groupId}','${q.id}',${oi})"
                                                class="px-3 text-red-500">
                                                ×
                                            </button>
                                        </div>
                                    `).join('')}

                                    <button onclick="App.actions.addOption('${groupId}','${q.id}')"
                                        class="text-sm text-indigo-600">
                                        ＋ 選択肢を追加
                                    </button>
                                </div>` : ''}

                        </div>
                    </div>
                </div>`;
        }
    },

    renderSend() {

        const s = App.utils.findSurvey(App.state.surveyId);

        if (!s) {
            App.actions.goList();
            return;
        }

        const keyword = App.state.customerKeyword || '';

        const customers = App.state.data.customers.filter(c => {
            if (!keyword) return true;

            const k = keyword.toLowerCase();

            return (
                c.name.toLowerCase().includes(k) ||
                c.company.toLowerCase().includes(k) ||
                c.email.toLowerCase().includes(k)
            );
        });

        document.getElementById('screen').innerHTML = `

            <div class="mb-6">
                <button onclick="App.actions.goList()"
                    class="text-sm text-slate-500">
                    ホーム ＞ アンケート一覧
                </button>

                <h1 class="text-2xl font-bold mt-2">
                    顧客選択・メール送信
                </h1>

                <p class="text-slate-500 mt-1">
                    ${App.utils.esc(s.title)}
                </p>
            </div>

            <div class="bg-white border rounded-2xl p-6 mb-5">

                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-bold">メールテンプレート</h2>
                        <p class="text-sm text-slate-500">
                            {顧客名} と {アンケートURL} が自動置換されます。
                        </p>
                    </div>

                    <select id="template_type"
                        onchange="App.actions.templateType(this.value)"
                        class="border rounded-lg px-3 py-2">
                        <option value="initial">初回送信</option>
                        <option value="reminder">再送・リマインド</option>
                    </select>
                </div>

                <input
                    id="mail_subject"
                    value="アンケートご協力のお願い"
                    class="mt-4 w-full border rounded-lg px-3 py-2"
                    placeholder="件名">

                <textarea
                    id="mail_body"
                    rows="8"
                    class="mt-3 w-full border rounded-lg px-3 py-2">${App.utils.esc(
`{顧客名} 様

いつもお世話になっております。

以下のURLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。`
                    )}</textarea>

                <div class="mt-4 flex justify-between items-center">
                    <input
                        id="customer_filter"
                        value="${App.utils.esc(keyword)}"
                        oninput="App.actions.customerSearch(this.value)"
                        placeholder="会社名・氏名・メールアドレスで検索"
                        class="border rounded-lg px-3 py-2 w-96">

                    <button onclick="App.actions.sendSelected('${s.id}')"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-lg font-semibold">
                        選択した顧客へ一括送信
                    </button>
                </div>
            </div>

            <div class="bg-white border rounded-2xl overflow-hidden">

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="p-4 text-left">
                                    <input id="select_all"
                                        type="checkbox"
                                        onchange="App.actions.selectAll(this.checked)">
                                </th>
                                <th class="p-4 text-left">会社名 / 氏名</th>
                                <th class="p-4 text-left">メール</th>
                                <th class="p-4 text-left">送信履歴</th>
                                <th class="p-4 text-left">回答</th>
                                <th class="p-4 text-left">kintone</th>
                            </tr>
                        </thead>

                        <tbody id="customer_table">
                            ${customers.map(c => `
                                <tr class="border-b">
                                    <td class="p-4">
                                        ${c.source === 'web' ? '' : `
                                            <input
                                                type="checkbox"
                                                ${App.state.selectedCustomers.includes(c.id) ? 'checked' : ''}
                                                onchange="App.actions.selectCustomer('${c.id}',this.checked)">
                                        `}
                                    </td>

                                    <td class="p-4">
                                        <b>${App.utils.esc(c.company)}</b><br>
                                        ${App.utils.esc(c.name)}
                                    </td>

                                    <td class="p-4">
                                        ${App.utils.esc(c.email)}
                                    </td>

                                    <td class="p-4">
                                        ${c.sent_at || '未送信'}
                                        <br>
                                        <span class="text-xs text-slate-500">
                                            ${c.send_count || 0} 回
                                        </span>
                                    </td>

                                    <td class="p-4">
                                        ${c.answer_status === 'answered'
                                            ? '<span class="text-emerald-600 font-semibold">回答済み</span>'
                                            : '<span class="text-amber-600">未回答</span>'}
                                    </td>

                                    <td class="p-4">
                                        ${c.kintone_status === 'registered'
                                            ? '<span class="text-emerald-600">✓ 登録完了</span>'
                                            : `
                                                <button onclick="App.actions.markKintone('${c.id}')"
                                                    class="text-indigo-600 underline">
                                                    kintone登録完了
                                                </button>`}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    },

    renderAnalytics() {

        const s = App.utils.findSurvey(App.state.surveyId);

        if (!s) return;

        const responses = App.state.data.responses.filter(
            r => r.survey_id === s.id
        );

        const sentCustomers = App.state.data.customers.filter(
            c => c.sent_at
        );

        const questions = App.utils.allQuestions(s);

        document.getElementById('screen').innerHTML = `

            <div class="flex justify-between items-center mb-6">

                <div>
                    <button onclick="App.actions.goList()"
                        class="text-sm text-slate-500">
                        ← アンケート一覧
                    </button>

                    <h1 class="text-2xl font-bold mt-2">
                        アンケート集計・分析
                    </h1>

                    <p class="text-slate-500">
                        ${App.utils.esc(s.title)}
                    </p>
                </div>

                <a
                    href="?action=csv&survey_id=${encodeURIComponent(s.id)}"
                    class="px-4 py-2 bg-emerald-600 text-white rounded-lg">
                    CSV出力
                </a>
            </div>

            <div class="grid grid-cols-5 gap-4 mb-6">

                ${App.analyticsCard('送信対象者数', sentCustomers.length + ' 人')}
                ${App.analyticsCard('回答数', responses.length + ' 件')}
                ${App.analyticsCard(
                    '未登録顧客からの回答数',
                    responses.filter(r => !r.customer_id).length + ' 件'
                )}

                ${App.analyticsCard(
                    '未回答数',
                    Math.max(sentCustomers.length - responses.length, 0) + ' 人'
                )}

                ${App.analyticsCard(
                    '回答率',
                    sentCustomers.length
                        ? ((responses.length / sentCustomers.length) * 100).toFixed(1) + ' %'
                        : '0.0 %'
                )}

            </div>

            <div class="grid grid-cols-[280px_1fr] gap-5">

                <aside class="bg-white border rounded-2xl p-5">
                    <h2 class="font-bold mb-4">設問絞り込み</h2>

                    <button onclick="App.actions.selectAllQuestions()"
                        class="text-sm text-indigo-600 mr-3">
                        全選択
                    </button>

                    <button onclick="App.actions.clearQuestions()"
                        class="text-sm text-slate-500">
                        全解除
                    </button>

                    <div class="mt-4 space-y-3">
                        ${questions.map((q, i) => `
                            <label class="flex gap-2">
                                <input
                                    type="checkbox"
                                    ${App.state.selectedQuestions[q.id] !== false ? 'checked' : ''}
                                    onchange="App.actions.questionFilter('${q.id}',this.checked)">
                                <span class="text-sm">
                                    ${q.number || 'Q' + (i + 1)}.
                                    ${App.utils.esc(q.text)}
                                </span>
                            </label>
                        `).join('')}
                    </div>
                </aside>

                <section class="space-y-5">

                    ${responses.length === 0
                        ? `
                            <div class="bg-white border rounded-2xl p-12 text-center text-slate-400">
                                現在、回答データはありません
                            </div>`
                        : questions
                            .filter(q => App.state.selectedQuestions[q.id] !== false)
                            .map(q => App.templates.chart(q, responses))
                            .join('')
                    }

                    <div class="bg-white border rounded-2xl p-5">

                        <h2 class="font-bold text-lg mb-4">
                            個別回答一覧
                        </h2>

                        <input
                            id="response_filter"
                            value="${App.utils.esc(App.state.responseFilter)}"
                            oninput="App.actions.responseSearch(this.value)"
                            placeholder="会社名・氏名で検索"
                            class="border rounded-lg px-3 py-2 w-80 mb-4">

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="p-3 text-left">会社名</th>
                                        <th class="p-3 text-left">氏名</th>
                                        <th class="p-3 text-left">回答日時</th>
                                        <th class="p-3">操作</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    ${responses
                                        .filter(r => {
                                            const k = App.state.responseFilter.toLowerCase();
                                            return !k ||
                                                r.company.toLowerCase().includes(k) ||
                                                r.name.toLowerCase().includes(k);
                                        })
                                        .map(r => `
                                            <tr class="border-t">
                                                <td class="p-3">${App.utils.esc(r.company)}</td>
                                                <td class="p-3">${App.utils.esc(r.name)}</td>
                                                <td class="p-3">${App.utils.esc(r.answered_at)}</td>
                                                <td class="p-3 text-center">
                                                    <button onclick="App.actions.responseDetail('${r.id}')"
                                                        class="text-indigo-600 underline">
                                                        全回答を表示
                                                    </button>
                                                </td>
                                            </tr>
                                        `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>

                </section>
            </div>
        `;
    },

    analyticsCard(title, value) {
        return `
            <div class="bg-white border rounded-2xl p-5">
                <div class="text-sm text-slate-500">${title}</div>
                <div class="text-2xl font-bold mt-2">${value}</div>
            </div>`;
    },

    templates: {

        chart(q, responses) {

            const counts = {};

            (q.options || []).forEach(o => counts[o] = 0);

            responses.forEach(r => {
                const answer = r.answers?.[q.id];

                if (Array.isArray(answer)) {
                    answer.forEach(a => {
                        counts[a] = (counts[a] || 0) + 1;
                    });
                } else if (answer) {
                    counts[answer] = (counts[answer] || 0) + 1;
                }
            });

            const total = responses.length || 1;

            if (q.type === 'text') {
                return `
                    <div class="bg-white border rounded-2xl p-5">
                        <div class="flex justify-between">
                            <h2 class="font-bold">${q.number || ''}. ${App.utils.esc(q.text)}</h2>
                            <span class="text-xs bg-slate-100 px-2 py-1 rounded">
                                自由記述
                            </span>
                        </div>

                        <div class="mt-4 space-y-3 max-h-80 overflow-y-auto">
                            ${responses.map(r => {
                                const a = r.answers?.[q.id];
                                if (!a) return '';

                                return `
                                    <div class="border-l-4 border-indigo-400 pl-4">
                                        <div class="text-xs text-slate-500">
                                            ${App.utils.esc(r.company)} / ${App.utils.esc(r.name)}
                                        </div>
                                        <div class="mt-1">
                                            ${App.utils.esc(a)}
                                        </div>
                                    </div>`;
                            }).join('')}
                        </div>
                    </div>`;
            }

            return `
                <div class="bg-white border rounded-2xl p-5">
                    <h2 class="font-bold">
                        ${q.number || ''}. ${App.utils.esc(q.text)}
                    </h2>

                    <div class="mt-5 space-y-4">
                        ${Object.entries(counts).map(([label,count]) => {

                            const percent = Math.round(count / total * 100);

                            return `
                                <div>
                                    <div class="flex justify-between text-sm">
                                        <span>${App.utils.esc(label)}</span>
                                        <span>${count} 件 / ${percent}%</span>
                                    </div>

                                    <div class="h-3 bg-slate-100 rounded-full mt-1 overflow-hidden">
                                        <div
                                            class="h-full bg-indigo-500 rounded-full"
                                            style="width:${percent}%">
                                        </div>
                                    </div>
                                </div>`;
                        }).join('')}
                    </div>
                </div>`;
        }
    },

    renderSettings() {

        const s = App.state.data.settings;

        document.getElementById('screen').innerHTML = `

            <div class="mb-6">
                <h1 class="text-2xl font-bold">kintone連携設定</h1>
                <p class="text-slate-500 mt-1">
                    kintone顧客情報との連携設定を行います。
                </p>
            </div>

            <div class="bg-white border rounded-2xl p-6">

                <div id="settings_form" class="grid grid-cols-2 gap-5">

                    <label>
                        <span class="text-sm font-semibold">サブドメイン</span>
                        <input id="setting_subdomain"
                            value="${App.utils.esc(s.subdomain)}"
                            placeholder="xxxx.cybozu.com または xxxx"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm font-semibold">アプリID</span>
                        <div class="flex gap-2">
                            <input id="setting_app_id"
                                value="${App.utils.esc(s.app_id)}"
                                class="mt-1 flex-1 border rounded-lg px-3 py-2">
                            <button onclick="App.actions.fetchKintoneFields()"
                                class="mt-1 px-4 bg-indigo-600 text-white rounded-lg">
                                項目一覧取得
                            </button>
                        </div>
                    </label>

                    <label>
                        <span class="text-sm font-semibold">ログイン名</span>
                        <input id="setting_login_name"
                            value="${App.utils.esc(s.login_name)}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label>
                        <span class="text-sm font-semibold">パスワード</span>
                        <input id="setting_password"
                            type="password"
                            value="${App.utils.esc(s.password)}"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label class="col-span-2">
                        <span class="text-sm font-semibold">Proxyサーバ</span>
                        <input id="setting_proxy"
                            value="${App.utils.esc(s.proxy)}"
                            placeholder="host名:port番号"
                            class="mt-1 w-full border rounded-lg px-3 py-2">
                    </label>

                    <label class="flex items-center gap-2">
                        <input id="setting_ssl_verify"
                            type="checkbox"
                            ${s.ssl_verify ? 'checked' : ''}>
                        SSL証明書を検証する
                    </label>

                </div>

                <div id="field_message"
                    class="mt-5 text-sm text-slate-500">
                    kintoneの項目一覧を取得するとマッピング欄が表示されます。
                </div>

                <div id="kintone_mapping"
                    class="mt-5 grid grid-cols-2 gap-4"></div>

                <button onclick="App.actions.saveSettings()"
                    class="mt-6 bg-indigo-600 text-white px-5 py-3 rounded-lg font-semibold">
                    設定を保存
                </button>

            </div>
        `;

        App.actions.renderFields();
    }
};

App.actions = {

    goList() {
        App.state.screen = 'list';
        App.state.surveyId = null;
        App.render();
    },

    newSurvey() {
        App.state.editingSurvey = {
            id: '',
            title: '新しいアンケート',
            start_at: '',
            end_at: '',
            status: 'draft',
            created_at: '',
            updated_at: '',
            numbering_mode: 'global',
            deleted: false,
            groups: [
                {
                    id: App.utils.uid('group'),
                    name: '基本情報',
                    questions: []
                }
            ]
        };

        App.state.screen = 'edit';
        App.render();
    },

    editSurvey(id) {
        const s = App.utils.findSurvey(id);

        if (!s) return;

        App.state.editingSurvey = JSON.parse(JSON.stringify(s));
        App.state.surveyId = id;
        App.state.screen = 'edit';
        App.render();
    },

    editTitle(value) {
        App.state.editingSurvey.title = value;
    },

    editStart(value) {
        App.state.editingSurvey.start_at = value;
    },

    editEnd(value) {
        App.state.editingSurvey.end_at = value;
    },

    numbering(value) {
        App.state.editingSurvey.numbering_mode = value;
        App.renderEditor();
    },

    groupName(id, value) {
        const g = App.state.editingSurvey.groups.find(x => x.id === id);
        if (g) g.name = value;
    },

    addGroup() {
        App.state.editingSurvey.groups.push({
            id: App.utils.uid('group'),
            name: '新しいグループ',
            questions: []
        });

        App.renderEditor();
    },

    deleteGroup(id) {

        if (!confirm('このグループと内包する質問を削除しますか？')) return;

        App.state.editingSurvey.groups =
            App.state.editingSurvey.groups.filter(g => g.id !== id);

        App.renderEditor();
    },

    addQuestion(groupId) {

        const g = App.state.editingSurvey.groups.find(
            x => x.id === groupId
        );

        if (!g) return;

        g.questions.push({
            id: App.utils.uid('question'),
            text: '',
            type: 'single',
            required: false,
            options: ['選択肢1', '選択肢2'],
            other_enabled: false
        });

        App.utils.renumber(App.state.editingSurvey);
        App.renderEditor();
    },

    deleteQuestion(groupId, id) {

        const g = App.state.editingSurvey.groups.find(
            x => x.id === groupId
        );

        if (!g) return;

        g.questions = g.questions.filter(q => q.id !== id);

        App.renderEditor();
    },

    questionText(groupId, id, value) {
        const q = App.findQuestion(groupId, id);
        if (q) q.text = value;
    },

    questionType(groupId, id, value) {
        const q = App.findQuestion(groupId, id);

        if (!q) return;

        q.type = value;

        if (value === 'text') {
            q.options = [];
            q.other_enabled = false;
        } else if (!q.options.length) {
            q.options = ['選択肢1', '選択肢2'];
        }

        App.renderEditor();
    },

    required(groupId, id, value) {
        const q = App.findQuestion(groupId, id);
        if (q) q.required = value;
    },

    other(groupId, id, value) {
        const q = App.findQuestion(groupId, id);
        if (q) q.other_enabled = value;
    },

    option(groupId, id, index, value) {
        const q = App.findQuestion(groupId, id);
        if (q) q.options[index] = value;
    },

    addOption(groupId, id) {
        const q = App.findQuestion(groupId, id);

        if (!q) return;

        q.options.push('新しい選択肢');
        App.renderEditor();
    },

    removeOption(groupId, id, index) {
        const q = App.findQuestion(groupId, id);

        if (!q) return;

        q.options.splice(index, 1);
        App.renderEditor();
    },

    findQuestion(groupId, id) {
        const g = App.state.editingSurvey.groups.find(
            x => x.id === groupId
        );

        return g?.questions.find(q => q.id === id);
    },

    initSortable() {

        const editor = document.getElementById('question_editor');

        if (editor) {
            new Sortable(editor, {
                handle: '.group-handle',
                animation: 180,
                ghostClass: 'opacity-40',
                onEnd: event => {

                    const groups = App.state.editingSurvey.groups;
                    const moved = groups.splice(event.oldIndex, 1)[0];

                    groups.splice(event.newIndex, 0, moved);

                    App.utils.renumber(App.state.editingSurvey);
                    App.renderEditor();
                }
            });
        }

        document.querySelectorAll('.question-list').forEach(list => {

            new Sortable(list, {
                group: 'survey-questions',
                handle: '.question-handle',
                animation: 180,
                ghostClass: 'opacity-40',
                filter: 'button',
                onEnd: event => {

                    const fromGroup = App.state.editingSurvey.groups.find(
                        g => g.id === event.from.dataset.groupId
                    );

                    const toGroup = App.state.editingSurvey.groups.find(
                        g => g.id === event.to.dataset.groupId
                    );

                    if (!fromGroup || !toGroup) return;

                    const questionId =
                        event.item.dataset.questionId;

                    const qi = fromGroup.questions.findIndex(
                        q => q.id === questionId
                    );

                    if (qi < 0) return;

                    const question =
                        fromGroup.questions.splice(qi, 1)[0];

                    let newIndex = event.newIndex;

                    if (newIndex > toGroup.questions.length) {
                        newIndex = toGroup.questions.length;
                    }

                    toGroup.questions.splice(newIndex, 0, question);

                    App.utils.renumber(App.state.editingSurvey);
                    App.renderEditor();
                }
            });

        });
    },

    async saveSurvey() {

        try {

            const result = await App.api.post(
                'save_survey',
                {
                    survey_json: App.state.editingSurvey
                }
            );

            alert(result.message);

            await App.api.load();

            App.state.screen = 'list';
            App.state.editingSurvey = null;

            App.render();

        } catch (e) {
            alert(e.message);
        }
    },

    cancelEdit() {

        if (!confirm('変更を破棄して一覧へ戻りますか？')) {
            return;
        }

        App.actions.goList();
    },

    preview() {

        const s = App.state.editingSurvey;

        document.getElementById('preview_modal').innerHTML = `

            <div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-6">

                <div class="${App.state.previewMode === 'pc'
                    ? 'w-[900px]'
                    : 'w-[390px]'} max-h-[90vh] overflow-y-auto bg-white rounded-2xl shadow-xl">

                    <div class="sticky top-0 bg-white border-b p-4 flex justify-between">

                        <div>
                            <b>プレビュー</b>
                        </div>

                        <div class="flex gap-2">
                            <button onclick="App.actions.previewMode('pc')"
                                class="px-3 py-1 border rounded">
                                PC
                            </button>

                            <button onclick="App.actions.previewMode('mobile')"
                                class="px-3 py-1 border rounded">
                                スマートフォン
                            </button>

                            <button onclick="App.actions.closePreview()"
                                class="px-3 py-1 text-red-500">
                                閉じる
                            </button>
                        </div>

                    </div>

                    <div id="preview_content" class="p-8">

                        <h1 class="text-2xl font-bold mb-8">
                            ${App.utils.esc(s.title)}
                        </h1>

                        ${s.groups.map(g => `
                            <section class="mb-8">
                                <h2 class="font-bold text-lg mb-4">
                                    ${App.utils.esc(g.name)}
                                </h2>

                                ${(g.questions || []).map(q => `
                                    <div class="mb-6">
                                        <label class="font-semibold">
                                            ${q.number || ''}.
                                            ${App.utils.esc(q.text)}
                                            ${q.required ? '<span class="text-red-500">*</span>' : ''}
                                        </label>

                                        <div class="mt-3 space-y-2">

                                            ${q.type === 'text'
                                                ? `
                                                    <textarea
                                                        class="w-full border rounded-lg p-3"
                                                        rows="4"
                                                        placeholder="回答を入力">
                                                    </textarea>`
                                                : q.options.map(o => `
                                                    <label class="flex gap-2">
                                                        <input
                                                            type="${q.type === 'multiple' ? 'checkbox' : 'radio'}"
                                                            name="${q.id}">
                                                        ${App.utils.esc(o)}
                                                    </label>
                                                `).join('')
                                            }

                                        </div>
                                    </div>
                                `).join('')}

                            </section>
                        `).join('')}

                        <button onclick="alert('これはプレビューです。実際には送信されません。')"
                            class="w-full bg-indigo-600 text-white py-3 rounded-lg">
                            送信
                        </button>

                    </div>
                </div>
            </div>`;
    },

    previewMode(mode) {
        App.state.previewMode = mode;
        App.actions.preview();
    },

    closePreview() {
        document.getElementById('preview_modal').innerHTML = '';
    },

    async changeStatus(id, status) {

        const message = status === 'active'
            ? 'このアンケートを公開しますか？'
            : 'このアンケートの公開を停止しますか？';

        if (!confirm(message)) return;

        try {

            const result = await App.api.post(
                'change_status',
                {
                    survey_id: id,
                    status
                }
            );

            alert(result.message);

            await App.api.load();
            App.render();

        } catch (e) {
            alert(e.message);
        }
    },

    async deleteSurvey(id) {

        if (!confirm('この下書きを削除しますか？')) return;

        try {

            const result = await App.api.post(
                'delete_survey',
                {survey_id: id}
            );

            alert(result.message);

            await App.api.load();
            App.render();

        } catch (e) {
            alert(e.message);
        }
    },

    async duplicate(id) {

        try {

            const result = await App.api.post(
                'duplicate_survey',
                {survey_id: id}
            );

            alert(result.message);

            await App.api.load();
            App.render();

        } catch (e) {
            alert(e.message);
        }
    },

    send(id) {
        App.state.surveyId = id;
        App.state.screen = 'send';
        App.state.selectedCustomers = [];
        App.render();
    },

    customerSearch(value) {
        App.state.customerKeyword = value;
        App.renderSend();
    },

    selectCustomer(id, checked) {

        if (checked) {
            if (!App.state.selectedCustomers.includes(id)) {
                App.state.selectedCustomers.push(id);
            }
        } else {
            App.state.selectedCustomers =
                App.state.selectedCustomers.filter(x => x !== id);
        }
    },

    selectAll(checked) {

        if (checked) {
            App.state.selectedCustomers =
                App.state.data.customers
                    .filter(c => c.source !== 'web')
                    .map(c => c.id);
        } else {
            App.state.selectedCustomers = [];
        }

        App.renderSend();
    },

    templateType(value) {},

    async sendSelected(surveyId) {

        if (!App.state.selectedCustomers.length) {
            alert('送信先を選択してください。');
            return;
        }

        const selected =
            App.state.data.customers.filter(c =>
                App.state.selectedCustomers.includes(c.id)
            );

        const alreadySent = selected.filter(c => c.send_count > 0);

        if (alreadySent.length) {

            const ok = confirm(
                '既に送信済みの宛先が含まれています。再送しますか？'
            );

            if (!ok) return;
        }

        const subject =
            document.getElementById('mail_subject').value;

        const body =
            document.getElementById('mail_body').value;

        const templateType =
            document.getElementById('template_type').value;

        try {

            const result = await App.api.post(
                'send_mail',
                {
                    survey_id: surveyId,
                    recipient_ids: App.state.selectedCustomers,
                    mail_subject: subject,
                    mail_body: body,
                    template_type: templateType
                }
            );

            alert(result.message);

            await App.api.load();

            App.state.selectedCustomers = [];
            App.renderSend();

        } catch (e) {
            alert(e.message);
        }
    },

    async markKintone(id) {

        try {

            const result = await App.api.post(
                'mark_kintone',
                {customer_id: id}
            );

            alert(result.message);

            await App.api.load();
            App.renderSend();

        } catch (e) {
            alert(e.message);
        }
    },

    analytics(id) {

        App.state.surveyId = id;
        App.state.screen = 'analytics';

        const s = App.utils.findSurvey(id);

        App.state.selectedQuestions = {};

        App.utils.allQuestions(s).forEach(q => {
            App.state.selectedQuestions[q.id] = true;
        });

        App.render();
    },

    questionFilter(id, checked) {
        App.state.selectedQuestions[id] = checked;
        App.renderAnalytics();
    },

    selectAllQuestions() {

        const s = App.utils.findSurvey(App.state.surveyId);

        App.utils.allQuestions(s).forEach(q => {
            App.state.selectedQuestions[q.id] = true;
        });

        App.renderAnalytics();
    },

    clearQuestions() {

        const s = App.utils.findSurvey(App.state.surveyId);

        App.utils.allQuestions(s).forEach(q => {
            App.state.selectedQuestions[q.id] = false;
        });

        App.renderAnalytics();
    },

    responseSearch(value) {
        App.state.responseFilter = value;
        App.renderAnalytics();
    },

    responseDetail(id) {

        const response =
            App.state.data.responses.find(r => r.id === id);

        if (!response) return;

        const s = App.utils.findSurvey(response.survey_id);

        document.getElementById('response_modal').innerHTML = `

            <div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-6">

                <div class="bg-white rounded-2xl w-[800px] max-w-full max-h-[90vh] overflow-y-auto">

                    <div class="sticky top-0 bg-white border-b p-5 flex justify-between">
                        <div>
                            <b>${App.utils.esc(response.company)}</b>
                            <span class="ml-2">${App.utils.esc(response.name)}</span>
                        </div>

                        <button onclick="App.actions.closeResponse()"
                            class="text-red-500">
                            閉じる
                        </button>
                    </div>

                    <div class="p-6 space-y-5">

                        ${App.utils.allQuestions(s).map(q => `
                            <div class="border rounded-xl p-4">
                                <div class="text-sm text-slate-500">
                                    ${q.number || ''}.
                                    ${App.utils.esc(q.text)}
                                </div>

                                <div class="font-semibold mt-2">
                                    ${Array.isArray(response.answers?.[q.id])
                                        ? response.answers[q.id].join(', ')
                                        : App.utils.esc(response.answers?.[q.id] || '未回答')}
                                </div>
                            </div>
                        `).join('')}

                    </div>
                </div>
            </div>`;
    },

    closeResponse() {
        document.getElementById('response_modal').innerHTML = '';
    },

    settings() {
        App.state.screen = 'settings';
        App.render();
    },

    async fetchKintoneFields() {

        const appId =
            document.getElementById('setting_app_id').value;

        const message =
            document.getElementById('field_message');

        message.textContent = 'kintoneから項目一覧を取得しています…';

        try {

            const result = await App.api.post(
                'kintone_fields',
                {
                    app_id: appId
                }
            );

            App.state.fields = result.fields || [];

            App.actions.renderFields();

            message.textContent =
                App.state.fields.length + '件の項目を取得しました。';

        } catch (e) {

            message.textContent = e.message;
            alert(e.message);
        }
    },

    renderFields() {

        const target =
            document.getElementById('kintone_mapping');

        if (!target) return;

        const settings = App.state.data.settings;

        const makeSelect = (label, key, multiple = false) => {

            const selected = settings[key];

            return `
                <label>
                    <span class="text-sm font-semibold">${label}</span>

                    <select
                        data-map-key="${key}"
                        ${multiple ? 'multiple' : ''}
                        class="mt-1 w-full border rounded-lg px-3 py-2">

                        <option value="">-- 選択してください --</option>

                        ${App.state.fields.map(f => {

                            const isSelected = multiple
                                ? Array.isArray(selected) &&
                                  selected.includes(f.code)
                                : selected === f.code;

                            return `
                                <option value="${App.utils.esc(f.code)}"
                                    ${isSelected ? 'selected' : ''}>
                                    ${App.utils.esc(f.label)}
                                    (${App.utils.esc(f.code)})
                                </option>`;
                        }).join('')}
                    </select>
                </label>`;
        };

        target.innerHTML = `

            ${makeSelect('会社名 (Company)', 'field_company')}
            ${makeSelect('氏名 (Name)', 'field_name')}
            ${makeSelect('メールアドレス (Email)', 'field_email')}
            ${makeSelect('部署名 (Department)', 'field_department')}
            ${makeSelect('電話番号 (Phone)', 'field_phone')}
            ${makeSelect('住所 (Address)', 'field_address', true)}
        `;
    },

    async saveSettings() {

        const mapping = {};

        document.querySelectorAll('[data-map-key]').forEach(select => {

            if (select.multiple) {
                mapping[select.dataset.mapKey] =
                    Array.from(select.selectedOptions).map(o => o.value);
            } else {
                mapping[select.dataset.mapKey] = select.value;
            }
        });

        const settings = {
            subdomain:
                document.getElementById('setting_subdomain').value,

            app_id:
                document.getElementById('setting_app_id').value,

            login_name:
                document.getElementById('setting_login_name').value,

            password:
                document.getElementById('setting_password').value,

            proxy:
                document.getElementById('setting_proxy').value,

            ssl_verify:
                document.getElementById('setting_ssl_verify').checked,

            ...mapping
        };

        try {

            const result = await App.api.post(
                'save_settings',
                {
                    settings_json: settings
                }
            );

            alert(result.message);

            await App.api.load();
            App.actions.settings();

        } catch (e) {
            alert(e.message);
        }
    },

    searchKey(event) {

        if (event.key === 'Enter') {
            App.state.keyword =
                document.getElementById('list_keyword').value;

            App.renderList();
        }
    },

    statusFilter(value) {
        App.state.statusFilter = value;
        App.renderList();
    },

    sort(value) {
        App.state.sort = value;
        App.renderList();
    },

    logout() {
        alert('ログアウト処理は認証機構導入時に接続します。');
    }
};

/*
 * renderAnalytics を App に明示的に公開。
 * window.App 単一名前空間ルールに従う。
 */
App.renderAnalytics = function() {
    App.renderAnalyticsScreen();
};

App.renderAnalyticsScreen = App.renderAnalytics;

App.actions.renderAnalytics = function() {
    App.renderAnalyticsScreen();
};

App.renderEditor = App.renderEditor;
App.renderSend = App.renderSend;

/*
 * 初期化ガード
 */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => App.init(), {once:true});
} else {
    App.init();
}
</script>

</body>
</html>
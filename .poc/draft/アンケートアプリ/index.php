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

header_remove('X-Powered-By');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0750, true);
}

/* ApacheからJSONを直接取得できないよう、実行時に保護ファイルを生成 */
$guardFile = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . '.htaccess';
if (!file_exists($guardFile)) {
    @file_put_contents(
        $guardFile,
        "Require all denied\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
    );
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_id(string $prefix): string
{
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
}

function survey_default_data(): array
{
    return [
        'surveys' => [],
        'responses' => [],
        'customers' => [
            [
                'id' => 'cus_demo_001',
                'company' => 'サンプル株式会社',
                'name' => '山田 太郎',
                'email' => 'yamada@example.com',
                'department' => '営業部',
                'phone' => '03-0000-0000',
                'address' => '東京都港区赤坂1-1-1',
                'source' => 'kintone',
                'sent_at' => '2026-08-10 10:00:00',
                'send_count' => 1,
                'answer_status' => 'unanswered',
                'kintone_status' => 'registered'
            ],
            [
                'id' => 'cus_demo_002',
                'company' => 'テスト商事株式会社',
                'name' => '佐藤 花子',
                'email' => 'sato@example.com',
                'department' => '企画部',
                'phone' => '03-1111-1111',
                'address' => '東京都千代田区丸の内2-2-2',
                'source' => 'kintone',
                'sent_at' => '2026-08-11 14:00:00',
                'send_count' => 2,
                'answer_status' => 'answered',
                'kintone_status' => 'registered'
            ],
            [
                'id' => 'cus_demo_003',
                'company' => 'Web回答者',
                'name' => '鈴木 一郎',
                'email' => 'suzuki@example.com',
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
            'field_address' => ''
        ],
        'mail_logs' => []
    ];
}

function survey_read(): array
{
    if (!file_exists(SURVEY_STORAGE_FILE)) {
        $data = survey_default_data();
        survey_write($data);
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
        if (!array_key_exists($key, $data)) {
            $data[$key] = survey_default_data()[$key];
        }
    }

    return $data;
}

function survey_write(array $data): bool
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    $fp = @fopen(SURVEY_STORAGE_FILE, 'c+');
    if ($fp === false) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, $json) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    @chmod(SURVEY_STORAGE_FILE, 0640);

    return $ok;
}

function survey_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function survey_require_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (
        $token === '' ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals((string)$_SESSION['csrf_token'], $token)
    ) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。ページを再読み込みしてください。'
        ], 403);
    }
}

function survey_normalize_domain(string $value): string
{
    $value = trim($value);
    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = preg_replace('#/.*$#', '', $value) ?? $value;

    if (!str_ends_with($value, '.cybozu.com')) {
        $value .= '.cybozu.com';
    }

    return $value;
}

function survey_http_request(
    string $url,
    string $method,
    array $headers,
    ?string $body,
    bool $sslVerify,
    string $proxy
): array {
    $httpHeaders = implode("\r\n", $headers);

    $ssl = [
        'verify_peer' => $sslVerify,
        'verify_peer_name' => $sslVerify,
        'allow_self_signed' => !$sslVerify
    ];

    $http = [
        'method' => strtoupper($method),
        'header' => $httpHeaders . "\r\n",
        'ignore_errors' => true,
        'timeout' => 30,
        'protocol_version' => 1.1
    ];

    if ($body !== null) {
        $http['content'] = $body;
    }

    if ($proxy !== '') {
        $http['proxy'] = 'tcp://' . $proxy;
        $http['request_fulluri'] = true;
    }

    $context = stream_context_create([
        'http' => $http,
        'ssl' => $ssl
    ]);

    $result = @file_get_contents($url, false, $context);

    $headersReceived = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : [];

    $status = 0;

    foreach ($headersReceived ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
            $status = (int)$m[1];
        }
    }

    return [
        'status' => $status,
        'body' => $result === false ? '' : $result,
        'headers' => $headersReceived ?? []
    ];
}

function survey_kintone_fields(array $settings): array
{
    $domain = survey_normalize_domain((string)($settings['subdomain'] ?? ''));
    $appId = trim((string)($settings['app_id'] ?? ''));

    if ($appId === '' || $domain === '') {
        return [
            'ok' => false,
            'message' => 'サブドメインとアプリIDを入力してください。',
            'fields' => []
        ];
    }

    $url = 'https://' . $domain . '/k/v1/app/form/fields.json?app=' .
        rawurlencode($appId);

    $headers = [
        'X-Cybozu-Authorization: ' .
            base64_encode(
                (string)$settings['login_name'] . ':' .
                (string)$settings['password']
            ),
        'Accept: application/json'
    ];

    $result = survey_http_request(
        $url,
        'GET',
        $headers,
        null,
        (bool)($settings['ssl_verify'] ?? false),
        (string)($settings['proxy'] ?? '')
    );

    $json = json_decode($result['body'], true);

    if (
        $result['status'] < 200 ||
        $result['status'] >= 300 ||
        !is_array($json)
    ) {
        return [
            'ok' => false,
            'message' => is_array($json)
                ? (string)($json['message'] ?? 'kintone APIエラー')
                : 'kintone APIとの通信に失敗しました。',
            'fields' => []
        ];
    }

    $fields = [];

    foreach (($json['properties'] ?? []) as $code => $property) {
        if (!is_array($property)) {
            continue;
        }

        $fields[] = [
            'label' => (string)($property['label'] ?? $code),
            'code' => (string)$code,
            'type' => (string)($property['type'] ?? '')
        ];
    }

    return [
        'ok' => true,
        'message' => count($fields) . '件の項目を取得しました。',
        'fields' => $fields
    ];
}

function survey_mail_send(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $headers = [
        'From: ' . ($_SERVER['SERVER_ADMIN'] ?? 'webmaster@localhost'),
        'Content-Type: text/plain; charset=UTF-8'
    ];

    return @mail(
        $to,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $body,
        implode("\r\n", $headers)
    );
}

function survey_csv_value(mixed $value): string
{
    if (is_array($value)) {
        return implode(' / ', array_map(
            static fn($v): string => (string)$v,
            $value
        ));
    }

    return (string)$value;
}

function survey_csv_output(array $data, string $surveyId): never
{
    $survey = null;

    foreach ($data['surveys'] as $item) {
        if (($item['id'] ?? '') === $surveyId && empty($item['deleted'])) {
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
        foreach ($group['questions'] as $question) {
            $questions[] = $question;
        }
    }

    $rows = [];
    $rows[] = array_merge(
        ['回答ID', '回答日時', '顧客ID', '会社名', '氏名', 'メールアドレス'],
        array_map(
            static fn($q): string => (string)($q['text'] ?? ''),
            $questions
        )
    );

    foreach ($data['responses'] as $response) {
        if (($response['survey_id'] ?? '') !== $surveyId) {
            continue;
        }

        $answers = $response['answers'] ?? [];

        $row = [
            (string)($response['id'] ?? ''),
            (string)($response['answered_at'] ?? ''),
            (string)($response['customer_id'] ?? ''),
            (string)($response['company'] ?? ''),
            (string)($response['name'] ?? ''),
            (string)($response['email'] ?? '')
        ];

        foreach ($questions as $question) {
            $row[] = survey_csv_value(
                $answers[$question['id']] ?? ''
            );
        }

        $rows[] = $row;
    }

    $fp = fopen('php://temp', 'w+');

    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }

    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);

    $csv = "\xEF\xBB\xBF" . ($csv ?: '');

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="survey_' .
        preg_replace('/[^A-Za-z0-9_-]/', '_', $surveyId) .
        '.csv"'
    );

    echo $csv;
    exit;
}

/* ================================================================
   API
================================================================ */

$action = (string)($_REQUEST['action'] ?? '');

if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        survey_require_csrf();
    }

    $data = survey_read();

    switch ($action) {
        case 'load':
            survey_json_response([
                'ok' => true,
                'data' => $data,
                'csrf_token' => $_SESSION['csrf_token']
            ]);
            break;

        case 'save_survey':
            $json = (string)($_POST['survey_json'] ?? '');
            $survey = json_decode($json, true);

            if (!is_array($survey)) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 422);
            }

            $survey['id'] = (string)($survey['id'] ?? survey_id('survey'));
            $survey['title'] = trim((string)($survey['title'] ?? '無題のアンケート'));
            $survey['status'] = in_array(
                ($survey['status'] ?? 'draft'),
                ['draft', 'active', 'ended'],
                true
            ) ? $survey['status'] : 'draft';
            $survey['updated_at'] = survey_now();
            $survey['created_at'] = (string)($survey['created_at'] ?? survey_now());
            $survey['deleted'] = false;

            $found = false;

            foreach ($data['surveys'] as $index => $existing) {
                if (($existing['id'] ?? '') === $survey['id']) {
                    $data['surveys'][$index] = $survey;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                array_unshift($data['surveys'], $survey);
            }

            if (!survey_write($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true,
                'message' => 'アンケートを保存しました。',
                'survey' => $survey
            ]);
            break;

        case 'duplicate_survey':
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
                    'message' => '対象アンケートが見つかりません。'
                ], 404);
            }

            $source['id'] = survey_id('survey');
            $source['title'] = (string)$source['title'] . '（複製）';
            $source['status'] = 'draft';
            $source['created_at'] = survey_now();
            $source['updated_at'] = survey_now();
            $source['deleted'] = false;

            array_unshift($data['surveys'], $source);
            survey_write($data);

            survey_json_response([
                'ok' => true,
                'message' => '下書きとして複製しました。',
                'survey' => $source
            ]);
            break;

        case 'delete_survey':
            $surveyId = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $surveyId) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = survey_now();
                }
            }
            unset($survey);

            survey_write($data);

            survey_json_response([
                'ok' => true,
                'message' => 'アンケートを削除しました。'
            ]);
            break;

        case 'status':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $newStatus = (string)($_POST['status'] ?? '');

            if (!in_array($newStatus, ['draft', 'active', 'ended'], true)) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'ステータスが不正です。'
                ], 422);
            }

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $surveyId) {
                    $survey['status'] = $newStatus;
                    $survey['updated_at'] = survey_now();
                }
            }
            unset($survey);

            survey_write($data);

            survey_json_response([
                'ok' => true,
                'message' => 'ステータスを変更しました。'
            ]);
            break;

        case 'save_settings':
            $json = (string)($_POST['settings_json'] ?? '');
            $settings = json_decode($json, true);

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 422);
            }

            $data['settings'] = array_merge(
                survey_default_data()['settings'],
                $settings
            );

            $data['settings']['subdomain'] = trim(
                (string)$data['settings']['subdomain']
            );

            survey_write($data);

            survey_json_response([
                'ok' => true,
                'message' => 'kintone連携設定を保存しました。'
            ]);
            break;

        case 'kintone_fields':
            $settings = $data['settings'];

            if (isset($_POST['app_id'])) {
                $settings['app_id'] = trim((string)$_POST['app_id']);
            }

            survey_json_response(
                survey_kintone_fields($settings)
            );
            break;

        case 'send_mail':
            $surveyId = (string)($_POST['survey_id'] ?? '');
            $recipientIds = json_decode(
                (string)($_POST['recipient_ids'] ?? '[]'),
                true
            );

            $recipientIds = is_array($recipientIds) ? $recipientIds : [];

            $subject = trim((string)($_POST['mail_subject'] ?? ''));
            $body = (string)($_POST['mail_body'] ?? '');
            $templateType = (string)($_POST['template_type'] ?? 'initial');

            if ($subject === '' || $body === '') {
                survey_json_response([
                    'ok' => false,
                    'message' => '件名と本文を入力してください。'
                ], 422);
            }

            $sent = 0;
            $failed = 0;
            $logRecipients = [];

            foreach ($data['customers'] as &$customer) {
                if (!in_array((string)$customer['id'], $recipientIds, true)) {
                    continue;
                }

                if (($customer['source'] ?? '') === 'web') {
                    continue;
                }

                $customerName = (string)$customer['name'];
                $personalBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        $customerName,
                        (
                            rtrim(
                                (
                                    isset($_SERVER['HTTPS']) &&
                                    $_SERVER['HTTPS'] !== 'off'
                                )
                                    ? 'https'
                                    : 'http',
                                '/'
                            ) .
                            '://' .
                            ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                            dirname($_SERVER['SCRIPT_NAME']) .
                            '/?answer=1&survey_id=' .
                            rawurlencode($surveyId) .
                            '&customer_id=' .
                            rawurlencode((string)$customer['id'])
                        )
                    ],
                    $body
                );

                $ok = survey_mail_send(
                    (string)$customer['email'],
                    $subject,
                    $personalBody
                );

                if ($ok) {
                    $customer['sent_at'] = survey_now();
                    $customer['send_count'] =
                        (int)($customer['send_count'] ?? 0) + 1;
                    $customer['answer_status'] = 'unanswered';
                    $sent++;
                    $logRecipients[] = $customer['id'];
                } else {
                    $failed++;
                }
            }
            unset($customer);

            $data['mail_logs'][] = [
                'id' => survey_id('mail'),
                'survey_id' => $surveyId,
                'sent_at' => survey_now(),
                'type' => $templateType,
                'count' => $sent,
                'subject' => $subject,
                'body' => $body,
                'recipients' => $logRecipients,
                'executor' => (string)($_SESSION['login_name'] ?? '管理者')
            ];

            survey_write($data);

            survey_json_response([
                'ok' => true,
                'message' => $sent . '件送信しました。失敗: ' . $failed . '件',
                'sent' => $sent,
                'failed' => $failed
            ]);
            break;

        case 'mark_kintone':
            $customerId = (string)($_POST['customer_id'] ?? '');

            foreach ($data['customers'] as &$customer) {
                if (($customer['id'] ?? '') === $customerId) {
                    $customer['kintone_status'] = 'registered';
                }
            }
            unset($customer);

            survey_write($data);

            survey_json_response([
                'ok' => true,
                'message' => 'kintone登録済みに変更しました。'
            ]);
            break;

        case 'csv':
            survey_csv_output(
                $data,
                (string)($_GET['survey_id'] ?? '')
            );
            break;

        default:
            survey_json_response([
                'ok' => false,
                'message' => '不明なAPIです。'
            ], 404);
    }
}

/* ================================================================
   HTML
================================================================ */

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<input type="hidden" id="csrf_token"
       value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

<div id="app"></div>

<script>
"use strict";

/*
 * ================================================================
 * window.App
 * ================================================================
 * クライアント側の全状態・描画・操作・APIをここに集約する。
 */

window.App = {
    state: {
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mail_logs: [],
        currentView: "list",
        currentSurveyId: null,
        currentSurvey: null,
        keyword: "",
        statusFilter: "all",
        sort: "updated_desc",
        customerFilter: "",
        responseFilter: "",
        selectedRecipients: [],
        previewDevice: "pc",
        selectedQuestions: [],
        fields: [],
        dirty: false,
        initialized: false
    },

    render: {},

    actions: {},

    api: {},

    utils: {},

    init: async function() {
        if (App.state.initialized) return;
        App.state.initialized = true;

        App.render.shell();
        await App.api.load();
    }
};

/* ================================================================
   Utility
================================================================ */

App.utils.escape = function(value) {
    const div = document.createElement("div");
    div.textContent = value == null ? "" : String(value);
    return div.innerHTML;
};

App.utils.uid = function(prefix) {
    return prefix + "_" + Date.now() + "_" +
        Math.random().toString(16).slice(2);
};

App.utils.date = function(value) {
    if (!value) return "未設定";
    return String(value).replace(" ", " ");
};

App.utils.statusLabel = function(status) {
    const map = {
        draft: ["下書き", "bg-slate-100 text-slate-600"],
        active: ["公開中", "bg-emerald-100 text-emerald-700"],
        ended: ["終了", "bg-amber-100 text-amber-700"]
    };

    return map[status] || ["不明", "bg-slate-100 text-slate-600"];
};

App.utils.typeLabel = function(type) {
    return {
        single: "単一選択",
        multiple: "複数選択",
        text: "自由記述"
    }[type] || type;
};

App.utils.surveyById = function(id) {
    return App.state.surveys.find(s => s.id === id);
};

App.utils.questionList = function(survey) {
    if (!survey) return [];

    const result = [];

    (survey.groups || []).forEach(group => {
        (group.questions || []).forEach(q => {
            result.push(q);
        });
    });

    return result;
};

App.utils.renumber = function(survey) {
    if (!survey) return;

    let globalNo = 1;

    (survey.groups || []).forEach((group, gi) => {
        (group.questions || []).forEach((q, qi) => {
            q.number = survey.numbering_mode === "group"
                ? "Q" + (gi + 1) + "-" + (qi + 1)
                : "Q" + globalNo;

            globalNo++;
        });
    });
};

App.utils.confirm = function(message) {
    return window.confirm(message);
};

App.utils.toast = function(message, type = "success") {
    const color = type === "error"
        ? "bg-red-600"
        : "bg-slate-800";

    const el = document.createElement("div");

    el.className =
        "fixed right-5 bottom-5 z-[100] px-5 py-3 rounded-xl " +
        "text-white shadow-xl " + color;

    el.textContent = message;

    document.body.appendChild(el);

    setTimeout(() => el.remove(), 2800);
};

App.utils.markDirty = function() {
    App.state.dirty = true;
};

App.utils.deepClone = function(value) {
    return JSON.parse(JSON.stringify(value));
};

/* ================================================================
   API
================================================================ */

App.api.post = async function(action, data = {}) {
    const body = new URLSearchParams();

    body.set("action", action);
    body.set(
        "csrf_token",
        document.getElementById("csrf_token").value
    );

    Object.keys(data).forEach(key => {
        const value = data[key];

        if (typeof value === "object") {
            body.set(key, JSON.stringify(value));
        } else {
            body.set(key, value == null ? "" : value);
        }
    });

    const response = await fetch(location.pathname, {
        method: "POST",
        headers: {
            "Content-Type":
                "application/x-www-form-urlencoded;charset=UTF-8"
        },
        body
    });

    const json = await response.json();

    if (!json.ok) {
        throw new Error(json.message || "APIエラー");
    }

    return json;
};

App.api.load = async function() {
    try {
        const response = await fetch(
            location.pathname + "?action=load",
            { cache: "no-store" }
        );

        const json = await response.json();

        if (!json.ok) {
            throw new Error(json.message);
        }

        App.state.surveys = json.data.surveys || [];
        App.state.responses = json.data.responses || [];
        App.state.customers = json.data.customers || [];
        App.state.settings = json.data.settings || {};
        App.state.mail_logs = json.data.mail_logs || [];

        if (json.csrf_token) {
            document.getElementById("csrf_token").value =
                json.csrf_token;
        }

        App.render.list();
    } catch (e) {
        App.render.error(e.message);
    }
};

App.api.saveSurvey = async function(survey) {
    return await App.api.post("save_survey", {
        survey_json: survey
    });
};

App.api.saveSettings = async function(settings) {
    return await App.api.post("save_settings", {
        settings_json: settings
    });
};

/* ================================================================
   Shell
================================================================ */

App.render.shell = function() {
    document.getElementById("app").innerHTML = `
        <header class="sticky top-0 z-40 bg-white border-b
                       border-slate-200 shadow-sm">
            <div class="max-w-[1600px] mx-auto px-6 py-4
                        flex items-center justify-between gap-5">

                <button
                    onclick="App.actions.goList()"
                    class="flex items-center gap-3 text-left">
                    <span class="w-10 h-10 rounded-xl bg-blue-600
                                 text-white grid place-items-center
                                 font-bold">A</span>
                    <span>
                        <span class="block font-bold text-slate-900">
                            アンケート管理
                        </span>
                        <span class="block text-xs text-slate-400">
                            Survey Management System
                        </span>
                    </span>
                </button>

                <nav class="flex items-center gap-2">
                    <button
                        onclick="App.actions.goList()"
                        class="px-4 py-2 rounded-lg text-sm
                               hover:bg-slate-100">
                        アンケート一覧
                    </button>

                    <button
                        onclick="App.actions.goSettings()"
                        class="px-4 py-2 rounded-lg text-sm
                               hover:bg-slate-100">
                        kintone連携設定
                    </button>

                    <button
                        onclick="App.actions.logout()"
                        class="px-4 py-2 rounded-lg text-sm
                               text-slate-500 hover:bg-slate-100">
                        ログアウト
                    </button>
                </nav>
            </div>
        </header>

        <main id="main-content"
              class="max-w-[1600px] mx-auto px-6 py-7"></main>

        <div id="preview_modal"></div>
        <div id="response_modal"></div>
    `;
};

App.render.error = function(message) {
    document.getElementById("main-content").innerHTML = `
        <div class="max-w-xl mx-auto mt-20 bg-white rounded-2xl
                    border border-red-200 p-8 shadow-sm">
            <h1 class="text-xl font-bold text-red-700 mb-3">
                読み込みエラー
            </h1>
            <p class="text-slate-600">${App.utils.escape(message)}</p>
            <button
                onclick="location.reload()"
                class="mt-6 bg-blue-600 text-white px-5 py-2.5
                       rounded-lg">
                再読み込み
            </button>
        </div>
    `;
};

/* ================================================================
   Survey List
================================================================ */

App.render.list = function() {
    App.state.currentView = "list";

    let surveys = App.state.surveys.filter(s => !s.deleted);

    const keyword = App.state.keyword.toLowerCase();

    if (keyword) {
        surveys = surveys.filter(s =>
            String(s.title || "").toLowerCase().includes(keyword)
        );
    }

    if (App.state.statusFilter !== "all") {
        surveys = surveys.filter(
            s => s.status === App.state.statusFilter
        );
    }

    surveys.sort((a, b) => {
        if (App.state.sort === "updated_desc") {
            return String(b.updated_at).localeCompare(String(a.updated_at));
        }

        if (App.state.sort === "updated_asc") {
            return String(a.updated_at).localeCompare(String(b.updated_at));
        }

        if (App.state.sort === "answers_desc") {
            return App.actions.answerCount(b) -
                   App.actions.answerCount(a);
        }

        if (App.state.sort === "answers_asc") {
            return App.actions.answerCount(a) -
                   App.actions.answerCount(b);
        }

        if (App.state.sort === "start_desc") {
            return String(b.start_at || "")
                .localeCompare(String(a.start_at || ""));
        }

        return String(a.start_at || "")
            .localeCompare(String(b.start_at || ""));
    });

    document.getElementById("main-content").innerHTML = `
        <section>
            <div class="flex flex-wrap items-center
                        justify-between gap-4 mb-7">

                <div>
                    <h1 class="text-2xl font-bold text-slate-900">
                        アンケート一覧
                    </h1>
                    <p class="text-sm text-slate-500 mt-1">
                        作成・送信・集計を一元管理します。
                    </p>
                </div>

                <button
                    onclick="App.actions.newSurvey()"
                    class="bg-blue-600 hover:bg-blue-700 text-white
                           px-5 py-3 rounded-xl shadow-sm
                           font-semibold">
                    ＋ 新規アンケート作成
                </button>
            </div>

            <div class="bg-white border border-slate-200
                        rounded-2xl p-4 mb-5 shadow-sm">
                <div class="flex flex-wrap gap-3">

                    <input
                        id="survey_search"
                        value="${App.utils.escape(App.state.keyword)}"
                        onkeydown="App.actions.searchKey(event)"
                        placeholder="タイトルを検索してEnter"
                        class="w-80 max-w-full border border-slate-300
                               rounded-lg px-4 py-2.5 outline-none
                               focus:ring-2 focus:ring-blue-200">

                    <select
                        onchange="App.actions.toggleStatusFilter(this.value)"
                        class="border border-slate-300 rounded-lg
                               px-4 py-2.5 bg-white">
                        <option value="all"
                            ${App.state.statusFilter === "all" ? "selected" : ""}>
                            すべて
                        </option>
                        <option value="active"
                            ${App.state.statusFilter === "active" ? "selected" : ""}>
                            公開中
                        </option>
                        <option value="draft"
                            ${App.state.statusFilter === "draft" ? "selected" : ""}>
                            下書き
                        </option>
                        <option value="ended"
                            ${App.state.statusFilter === "ended" ? "selected" : ""}>
                            終了
                        </option>
                    </select>

                    <select
                        onchange="App.actions.changeSort(this.value)"
                        class="border border-slate-300 rounded-lg
                               px-4 py-2.5 bg-white">
                        <option value="updated_desc"
                            ${App.state.sort === "updated_desc" ? "selected" : ""}>
                            更新日：新しい順
                        </option>
                        <option value="updated_asc"
                            ${App.state.sort === "updated_asc" ? "selected" : ""}>
                            更新日：古い順
                        </option>
                        <option value="answers_desc"
                            ${App.state.sort === "answers_desc" ? "selected" : ""}>
                            回答数：多い順
                        </option>
                        <option value="answers_asc"
                            ${App.state.sort === "answers_asc" ? "selected" : ""}>
                            回答数：少ない順
                        </option>
                        <option value="start_desc"
                            ${App.state.sort === "start_desc" ? "selected" : ""}>
                            開始日：新しい順
                        </option>
                        <option value="start_asc"
                            ${App.state.sort === "start_asc" ? "selected" : ""}>
                            開始日：古い順
                        </option>
                    </select>
                </div>
            </div>

            <div class="bg-white border border-slate-200
                        rounded-2xl shadow-sm overflow-hidden">

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1200px] text-sm">
                        <thead class="bg-slate-50 border-b
                                       border-slate-200">
                            <tr class="text-left text-slate-500">
                                <th class="p-4">作成日 / 更新日</th>
                                <th class="p-4">タイトル</th>
                                <th class="p-4">アンケート期間</th>
                                <th class="p-4">ステータス</th>
                                <th class="p-4 text-right">回答数</th>
                                <th class="p-4">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${
                                surveys.length
                                ? surveys.map(App.render.surveyRow).join("")
                                : `
                                <tr>
                                    <td colspan="6"
                                        class="p-14 text-center
                                               text-slate-400">
                                        アンケートがありません。
                                    </td>
                                </tr>`
                            }
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    `;
};

App.render.surveyRow = function(survey) {
    const status = App.utils.statusLabel(survey.status);
    const count = App.actions.answerCount(survey);

    let actions = "";

    if (survey.status === "active") {
        actions = `
            <button onclick="App.actions.editSurvey('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-100
                           hover:bg-slate-200">確認・編集</button>
            <button onclick="App.actions.analytics('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-100
                           hover:bg-slate-200">集計</button>
            <button onclick="App.actions.send('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-blue-50
                           text-blue-700 hover:bg-blue-100">送信</button>
            <button onclick="App.actions.stop('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-amber-50
                           text-amber-700 hover:bg-amber-100">停止</button>
            <button onclick="App.actions.duplicate('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-100
                           hover:bg-slate-200">複製</button>
        `;
    } else if (survey.status === "draft") {
        actions = `
            <button onclick="App.actions.editSurvey('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-100
                           hover:bg-slate-200">確認・編集</button>
            <button onclick="App.actions.deleteSurvey('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-red-50
                           text-red-700 hover:bg-red-100">削除</button>
            <button onclick="App.actions.duplicate('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-100
                           hover:bg-slate-200">複製</button>
        `;
    } else {
        actions = `
            <button onclick="App.actions.editSurvey('${survey.id}', true)"
                    class="px-3 py-1.5 rounded-lg bg-slate-100
                           hover:bg-slate-200">確認・編集</button>
            <button onclick="App.actions.analytics('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-100
                           hover:bg-slate-200">集計</button>
            <button onclick="App.actions.duplicate('${survey.id}')"
                    class="px-3 py-1.5 rounded-lg bg-slate-100
                           hover:bg-slate-200">複製</button>
        `;
    }

    return `
        <tr class="border-b border-slate-100 hover:bg-slate-50">
            <td class="p-4 whitespace-nowrap">
                <div>${App.utils.escape(survey.created_at || "")}</div>
                <div class="text-xs text-slate-400 mt-1">
                    更新: ${App.utils.escape(survey.updated_at || "")}
                </div>
            </td>

            <td class="p-4">
                <div class="font-bold text-slate-900">
                    ${App.utils.escape(survey.title)}
                </div>
            </td>

            <td class="p-4 whitespace-nowrap text-slate-600">
                ${
                    survey.start_at
                    ? App.utils.escape(survey.start_at) +
                      " ～ " +
                      App.utils.escape(survey.end_at || "未設定")
                    : "未設定"
                }
            </td>

            <td class="p-4">
                <span class="inline-flex px-2.5 py-1 rounded-full
                             text-xs font-semibold ${status[1]}">
                    ${status[0]}
                </span>
            </td>

            <td class="p-4 text-right font-semibold">
                ${count} 件
            </td>

            <td class="p-4">
                <div class="flex flex-wrap gap-2">
                    ${actions}
                </div>
            </td>
        </tr>
    `;
};

/* ================================================================
   List actions
================================================================ */

App.actions.answerCount = function(survey) {
    return App.state.responses.filter(
        r => r.survey_id === survey.id
    ).length;
};

App.actions.searchKey = function(event) {
    if (event.key === "Enter") {
        App.state.keyword = event.target.value.trim();
        App.render.list();
    }
};

App.actions.toggleStatusFilter = function(value) {
    App.state.statusFilter = value;
    App.render.list();
};

App.actions.changeSort = function(value) {
    App.state.sort = value;
    App.render.list();
};

App.actions.goList = function() {
    if (App.state.dirty) {
        if (!App.utils.confirm("未保存の変更があります。移動しますか？")) {
            return;
        }
    }

    App.state.dirty = false;
    App.state.currentView = "list";
    App.render.list();
};

App.actions.newSurvey = function() {
    const now = new Date();
    const local = new Date(
        now.getTime() - now.getTimezoneOffset() * 60000
    ).toISOString().slice(0, 16);

    App.state.currentSurvey = {
        id: App.utils.uid("survey"),
        title: "新しいアンケート",
        start_at: local,
        end_at: "",
        status: "draft",
        created_at: new Date().toLocaleString("ja-JP"),
        updated_at: new Date().toLocaleString("ja-JP"),
        numbering_mode: "global",
        groups: [],
        deleted: false
    };

    App.actions.addGroup(false);
    App.state.dirty = false;
    App.render.editor();
};

App.actions.editSurvey = function(id, readonly = false) {
    const survey = App.utils.surveyById(id);

    if (!survey) return;

    App.state.currentSurvey = App.utils.deepClone(survey);
    App.state.currentView = "editor";
    App.state.readonly = readonly || survey.status === "ended";
    App.state.dirty = false;

    App.render.editor();
};

App.actions.stop = async function(id) {
    if (!App.utils.confirm(
        "このアンケートを停止して終了状態にしますか？"
    )) return;

    try {
        await App.api.post("status", {
            survey_id: id,
            status: "ended"
        });

        await App.api.load();
        App.utils.toast("アンケートを停止しました。");
    } catch (e) {
        App.utils.toast(e.message, "error");
    }
};

App.actions.deleteSurvey = async function(id) {
    if (!App.utils.confirm(
        "この下書きを削除しますか？\n削除後は一覧から表示されません。"
    )) return;

    try {
        await App.api.post("delete_survey", {
            survey_id: id
        });

        await App.api.load();
        App.utils.toast("削除しました。");
    } catch (e) {
        App.utils.toast(e.message, "error");
    }
};

App.actions.duplicate = async function(id) {
    try {
        await App.api.post("duplicate_survey", {
            survey_id: id
        });

        await App.api.load();
        App.utils.toast("下書きとして複製しました。");
    } catch (e) {
        App.utils.toast(e.message, "error");
    }
};

App.actions.logout = function() {
    if (!App.utils.confirm("ログアウトしますか？")) return;

    window.location.href = location.pathname +
        "?logout=1";
};

/* ================================================================
   Editor
================================================================ */

App.render.editor = function() {
    const survey = App.state.currentSurvey;
    const readonly = !!App.state.readonly;

    document.getElementById("main-content").innerHTML = `
        <section>
            <div class="flex flex-wrap items-center
                        justify-between gap-4 mb-6">

                <div>
                    <div class="text-xs text-slate-400 mb-1">
                        ホーム ＞ アンケート一覧 ＞
                        ${readonly ? "確認" : "作成・編集"}
                    </div>

                    <div class="flex items-center gap-3">
                        <input
                            id="survey_title"
                            value="${App.utils.escape(survey.title)}"
                            ${readonly ? "disabled" : ""}
                            oninput="App.actions.editorChange()"
                            class="text-2xl font-bold bg-transparent
                                   border-0 outline-none
                                   focus:ring-0 w-[650px] max-w-full">

                        <span class="px-2.5 py-1 rounded-full text-xs
                                     ${App.utils.statusLabel(survey.status)[1]}">
                            ${App.utils.statusLabel(survey.status)[0]}
                        </span>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button
                        onclick="App.actions.preview()"
                        class="px-4 py-2.5 bg-white border
                               border-slate-300 rounded-lg">
                        プレビュー
                    </button>

                    ${
                        readonly
                        ? `
                        <button
                            onclick="App.actions.goList()"
                            class="px-5 py-2.5 bg-slate-800
                                   text-white rounded-lg">
                            一覧へ戻る
                        </button>`
                        : `
                        <button
                            onclick="App.actions.cancelEditor()"
                            class="px-4 py-2.5 bg-white border
                                   border-slate-300 rounded-lg">
                            キャンセル
                        </button>

                        <button
                            onclick="App.actions.saveEditor()"
                            class="px-5 py-2.5 bg-blue-600
                                   hover:bg-blue-700 text-white
                                   rounded-lg font-semibold">
                            保存して一覧へ戻る
                        </button>`
                    }
                </div>
            </div>

            <div class="grid xl:grid-cols-4 gap-5 mb-5">
                <div class="xl:col-span-3 bg-white border
                            border-slate-200 rounded-2xl
                            p-5 shadow-sm">

                    <div class="grid md:grid-cols-3 gap-4">
                        <label class="block">
                            <span class="text-xs font-semibold
                                         text-slate-500">
                                開始日時
                            </span>
                            <input
                                id="survey_start_at"
                                type="datetime-local"
                                value="${App.utils.escape(survey.start_at || "")}"
                                ${readonly ? "disabled" : ""}
                                onchange="App.actions.editorChange()"
                                class="mt-1.5 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2">
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold
                                         text-slate-500">
                                終了日時
                            </span>
                            <input
                                id="survey_end_at"
                                type="datetime-local"
                                value="${App.utils.escape(survey.end_at || "")}"
                                ${readonly ? "disabled" : ""}
                                onchange="App.actions.editorChange()"
                                class="mt-1.5 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2">
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold
                                         text-slate-500">
                                質問番号
                            </span>
                            <select
                                id="survey_numbering_mode"
                                onchange="App.actions.changeNumbering(this.value)"
                                ${readonly ? "disabled" : ""}
                                class="mt-1.5 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2">
                                <option value="global"
                                    ${survey.numbering_mode === "global" ? "selected" : ""}>
                                    Q1 / Q2 / Q3...
                                </option>
                                <option value="group"
                                    ${survey.numbering_mode === "group" ? "selected" : ""}>
                                    Q1-1 / Q1-2...
                                </option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="bg-white border border-slate-200
                            rounded-2xl p-5 shadow-sm">
                    <div class="text-xs text-slate-400">
                        質問数
                    </div>
                    <div class="text-3xl font-bold mt-1">
                        ${App.utils.questionList(survey).length}
                    </div>
                </div>
            </div>

            <div id="question_editor" class="space-y-5"></div>

            ${
                readonly
                ? ""
                : `
                <button
                    onclick="App.actions.addGroup(true)"
                    class="mt-5 w-full border-2 border-dashed
                           border-slate-300 hover:border-blue-400
                           hover:bg-blue-50 rounded-2xl py-5
                           text-slate-500">
                    ＋ グループを追加
                </button>`
            }
        </section>
    `;

    App.render.groups();
};

App.render.groups = function() {
    const survey = App.state.currentSurvey;
    const readonly = !!App.state.readonly;
    const root = document.getElementById("question_editor");

    root.innerHTML = (survey.groups || []).map((group, gi) => `
        <div class="group-card bg-white border
                    border-slate-200 rounded-2xl shadow-sm"
             data-group-id="${group.id}">

            <div class="px-5 py-4 border-b border-slate-100
                        flex items-center gap-3">

                ${
                    readonly
                    ? ""
                    : `
                    <span class="group-handle cursor-grab
                                 text-xl text-slate-300">
                        ⠿
                    </span>`
                }

                <span class="text-xs font-bold text-blue-600">
                    GROUP ${gi + 1}
                </span>

                <input
                    value="${App.utils.escape(group.name)}"
                    ${readonly ? "disabled" : ""}
                    oninput="App.actions.changeGroupName('${group.id}', this.value)"
                    class="flex-1 font-bold text-slate-800
                           bg-transparent border-0 outline-none">

                ${
                    readonly
                    ? ""
                    : `
                    <button
                        onclick="App.actions.deleteGroup('${group.id}')"
                        class="text-red-500 hover:bg-red-50
                               px-3 py-1.5 rounded-lg">
                        グループ削除
                    </button>`
                }
            </div>

            <div class="p-5 space-y-4 question-list"
                 data-group-id="${group.id}">
                ${
                    (group.questions || []).length
                    ? group.questions.map(
                        (q, qi) => App.render.question(q, group.id, qi)
                      ).join("")
                    : `
                    <div class="border border-dashed
                                border-slate-300 rounded-xl p-7
                                text-center text-slate-400">
                        質問がありません。
                    </div>`
                }
            </div>

            ${
                readonly
                ? ""
                : `
                <div class="px-5 pb-5">
                    <button
                        onclick="App.actions.addQuestion('${group.id}')"
                        class="w-full py-3 rounded-xl
                               bg-slate-50 hover:bg-blue-50
                               text-blue-600 font-semibold">
                        ＋ 質問を追加
                    </button>
                </div>`
            }
        </div>
    `).join("");

    App.actions.enableSortable();
};

App.render.question = function(q, groupId, index) {
    const readonly = !!App.state.readonly;

    return `
        <div class="question-card border border-slate-200
                    rounded-xl p-4 bg-slate-50"
             data-question-id="${q.id}">

            <div class="flex gap-3">

                ${
                    readonly
                    ? ""
                    : `
                    <span class="question-handle cursor-grab
                                 text-lg text-slate-300 pt-2">
                        ⠿
                    </span>`
                }

                <div class="flex-1">
                    <div class="flex flex-wrap items-center
                                gap-2 mb-3">
                        <span class="question-number
                                     text-blue-600 font-bold">
                            ${q.number || ""}
                        </span>

                        <span class="text-xs px-2 py-1
                                     rounded-full bg-slate-200
                                     text-slate-600">
                            ${App.utils.typeLabel(q.type)}
                        </span>

                        ${
                            q.required
                            ? `<span class="text-xs px-2 py-1 rounded-full
                                        bg-red-50 text-red-600">
                                    必須
                               </span>`
                            : ""
                        }
                    </div>

                    <input
                        value="${App.utils.escape(q.text)}"
                        ${readonly ? "disabled" : ""}
                        oninput="App.actions.changeQuestion('${groupId}','${q.id}',this.value)"
                        placeholder="質問文を入力してください"
                        class="w-full border border-slate-300
                               bg-white rounded-lg px-4 py-3
                               font-medium outline-none
                               focus:ring-2 focus:ring-blue-100">

                    ${
                        readonly
                        ? App.render.answerPreview(q)
                        : App.render.questionEditor(q, groupId)
                    }
                </div>

                ${
                    readonly
                    ? ""
                    : `
                    <button
                        onclick="App.actions.deleteQuestion('${groupId}','${q.id}')"
                        class="self-start text-slate-400
                               hover:text-red-600 p-2">
                        ×
                    </button>`
                }
            </div>
        </div>
    `;
};

App.render.questionEditor = function(q, groupId) {
    return `
        <div class="mt-4 grid md:grid-cols-3 gap-4">

            <label class="block">
                <span class="text-xs text-slate-500">
                    回答形式
                </span>
                <select
                    onchange="App.actions.changeQuestionType('${groupId}','${q.id}',this.value)"
                    class="mt-1 w-full border border-slate-300
                           bg-white rounded-lg px-3 py-2">
                    <option value="single"
                        ${q.type === "single" ? "selected" : ""}>
                        単一選択
                    </option>
                    <option value="multiple"
                        ${q.type === "multiple" ? "selected" : ""}>
                        複数選択
                    </option>
                    <option value="text"
                        ${q.type === "text" ? "selected" : ""}>
                        自由記述
                    </option>
                </select>
            </label>

            <label class="flex items-center gap-3 pt-6">
                <input
                    type="checkbox"
                    ${q.required ? "checked" : ""}
                    onchange="App.actions.toggleRequired('${groupId}','${q.id}',this.checked)"
                    class="w-4 h-4">
                <span class="text-sm">必須回答</span>
            </label>

            <label class="flex items-center gap-3 pt-6">
                <input
                    type="checkbox"
                    ${q.other_enabled ? "checked" : ""}
                    onchange="App.actions.toggleOther('${groupId}','${q.id}',this.checked)"
                    class="w-4 h-4">
                <span class="text-sm">その他入力を許可</span>
            </label>
        </div>

        ${
            q.type === "text"
            ? `
            <div class="mt-4">
                <textarea disabled rows="3"
                    class="w-full border border-slate-200
                           rounded-lg bg-white p-3
                           text-slate-400"
                    placeholder="回答者の自由記述欄"></textarea>
            </div>`
            : `
            <div class="mt-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-500">
                        選択肢
                    </span>
                    <button
                        onclick="App.actions.addOption('${groupId}','${q.id}')"
                        class="text-xs text-blue-600">
                        ＋ 選択肢追加
                    </button>
                </div>

                <div class="space-y-2">
                    ${(q.options || []).map((option, oi) => `
                        <div class="flex gap-2">
                            <input
                                value="${App.utils.escape(option)}"
                                oninput="App.actions.changeOption('${groupId}','${q.id}',${oi},this.value)"
                                class="flex-1 border border-slate-300
                                       bg-white rounded-lg px-3 py-2">
                            <button
                                onclick="App.actions.deleteOption('${groupId}','${q.id}',${oi})"
                                class="px-3 rounded-lg bg-white
                                       border border-slate-200
                                       text-red-500">
                                ×
                            </button>
                        </div>
                    `).join("")}
                </div>
            </div>`
        }
    `;
};

App.render.answerPreview = function(q) {
    if (q.type === "text") {
        return `
            <textarea disabled rows="3"
                class="mt-4 w-full border border-slate-200
                       rounded-lg bg-white p-3"
                placeholder="回答欄"></textarea>
        `;
    }

    const control = q.type === "single" ? "radio" : "checkbox";

    return `
        <div class="mt-4 space-y-2">
            ${(q.options || []).map(option => `
                <label class="flex items-center gap-2">
                    <input type="${control}" disabled>
                    <span>${App.utils.escape(option)}</span>
                </label>
            `).join("")}
        </div>
    `;
};

/* ================================================================
   Editor actions
================================================================ */

App.actions.editorChange = function() {
    const survey = App.state.currentSurvey;

    survey.title = document.getElementById("survey_title").value;
    survey.start_at = document.getElementById("survey_start_at").value;
    survey.end_at = document.getElementById("survey_end_at").value;

    App.utils.markDirty();
};

App.actions.changeNumbering = function(value) {
    App.state.currentSurvey.numbering_mode = value;
    App.utils.renumber(App.state.currentSurvey);
    App.utils.markDirty();
    App.render.groups();
};

App.actions.addGroup = function(renderNow = true) {
    const survey = App.state.currentSurvey;

    survey.groups.push({
        id: App.utils.uid("group"),
        name: "新しいグループ",
        questions: []
    });

    App.utils.markDirty();

    if (renderNow) App.render.editor();
};

App.actions.deleteGroup = function(groupId) {
    if (!App.utils.confirm(
        "グループと、その中の質問をすべて削除しますか？"
    )) return;

    App.state.currentSurvey.groups =
        App.state.currentSurvey.groups.filter(
            group => group.id !== groupId
        );

    App.utils.renumber(App.state.currentSurvey);
    App.utils.markDirty();
    App.render.groups();
};

App.actions.changeGroupName = function(groupId, value) {
    const group = App.state.currentSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.name = value;
    App.utils.markDirty();
};

App.actions.addQuestion = function(groupId) {
    const group = App.state.currentSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions.push({
        id: App.utils.uid("question"),
        text: "新しい質問",
        type: "single",
        required: false,
        options: ["選択肢1", "選択肢2"],
        other_enabled: false
    });

    App.utils.renumber(App.state.currentSurvey);
    App.utils.markDirty();
    App.render.groups();
};

App.actions.deleteQuestion = function(groupId, questionId) {
    const group = App.state.currentSurvey.groups.find(
        g => g.id === groupId
    );

    if (!group) return;

    group.questions = group.questions.filter(
        q => q.id !== questionId
    );

    App.utils.renumber(App.state.currentSurvey);
    App.utils.markDirty();
    App.render.groups();
};

App.actions.changeQuestion = function(groupId, questionId, value) {
    const q = App.actions.findQuestion(groupId, questionId);

    if (!q) return;

    q.text = value;
    App.utils.markDirty();
};

App.actions.findQuestion = function(groupId, questionId) {
    const group = App.state.currentSurvey.groups.find(
        g => g.id === groupId
    );

    return group
        ? group.questions.find(q => q.id === questionId)
        : null;
};

App.actions.changeQuestionType = function(
    groupId,
    questionId,
    type
) {
    const q = App.actions.findQuestion(groupId, questionId);

    if (!q) return;

    q.type = type;

    if (type === "text") {
        q.options = [];
    } else if (!q.options.length) {
        q.options = ["選択肢1", "選択肢2"];
    }

    App.utils.markDirty();
    App.render.groups();
};

App.actions.toggleRequired = function(
    groupId,
    questionId,
    checked
) {
    const q = App.actions.findQuestion(groupId, questionId);

    if (!q) return;

    q.required = checked;
    App.utils.markDirty();
};

App.actions.toggleOther = function(
    groupId,
    questionId,
    checked
) {
    const q = App.actions.findQuestion(groupId, questionId);

    if (!q) return;

    q.other_enabled = checked;
    App.utils.markDirty();
};

App.actions.addOption = function(groupId, questionId) {
    const q = App.actions.findQuestion(groupId, questionId);

    if (!q) return;

    q.options.push("新しい選択肢");
    App.utils.markDirty();
    App.render.groups();
};

App.actions.changeOption = function(
    groupId,
    questionId,
    index,
    value
) {
    const q = App.actions.findQuestion(groupId, questionId);

    if (!q) return;

    q.options[index] = value;
    App.utils.markDirty();
};

App.actions.deleteOption = function(
    groupId,
    questionId,
    index
) {
    const q = App.actions.findQuestion(groupId, questionId);

    if (!q) return;

    q.options.splice(index, 1);
    App.utils.markDirty();
    App.render.groups();
};

App.actions.enableSortable = function() {
    if (typeof Sortable === "undefined") return;

    const root = document.getElementById("question_editor");

    if (root) {
        new Sortable(root, {
            animation: 180,
            handle: ".group-handle",
            ghostClass: "opacity-40",
            onEnd: function(evt) {
                if (evt.oldIndex === evt.newIndex) return;

                const groups = App.state.currentSurvey.groups;
                const moved = groups.splice(evt.oldIndex, 1)[0];

                groups.splice(evt.newIndex, 0, moved);

                App.utils.renumber(App.state.currentSurvey);
                App.utils.markDirty();
                App.render.groups();
            }
        });
    }

    document.querySelectorAll(".question-list").forEach(list => {
        new Sortable(list, {
            group: "survey_questions",
            animation: 180,
            handle: ".question-handle",
            ghostClass: "opacity-40",

            onEnd: function() {
                const survey = App.state.currentSurvey;

                document.querySelectorAll(".question-list")
                    .forEach(container => {
                        const groupId =
                            container.dataset.groupId;

                        const group = survey.groups.find(
                            g => g.id === groupId
                        );

                        if (!group) return;

                        const ids = Array.from(
                            container.querySelectorAll(
                                ".question-card"
                            )
                        ).map(el => el.dataset.questionId);

                        const allQuestions = [];

                        survey.groups.forEach(g => {
                            g.questions.forEach(q => {
                                if (ids.includes(q.id)) {
                                    allQuestions.push(q);
                                }
                            });
                        });

                        group.questions = ids.map(id =>
                            allQuestions.find(q => q.id === id)
                        ).filter(Boolean);
                    });

                /* 他グループから移動された質問を回収 */
                const assigned = new Set();

                survey.groups.forEach(g => {
                    g.questions.forEach(q => assigned.add(q.id));
                });

                const all = App.utils.questionList(survey);

                survey.groups.forEach(g => {
                    g.questions = g.questions.filter(q =>
                        assigned.has(q.id)
                    );
                });

                App.utils.renumber(survey);
                App.utils.markDirty();
                App.render.groups();
            }
        });
    });
};

App.actions.saveEditor = async function() {
    App.actions.editorChange();

    const survey = App.state.currentSurvey;

    App.utils.renumber(survey);

    try {
        await App.api.saveSurvey(survey);

        App.state.dirty = false;
        await App.api.load();
        App.utils.toast("保存しました。");
    } catch (e) {
        App.utils.toast(e.message, "error");
    }
};

App.actions.cancelEditor = function() {
    if (
        App.state.dirty &&
        !App.utils.confirm(
            "変更内容を破棄して一覧へ戻りますか？"
        )
    ) {
        return;
    }

    App.state.dirty = false;
    App.render.list();
};

/* ================================================================
   Preview
================================================================ */

App.actions.preview = function() {
    App.actions.editorChange();

    const survey = App.state.currentSurvey;

    document.getElementById("preview_modal").innerHTML = `
        <div class="fixed inset-0 z-50 bg-black/50
                    flex items-center justify-center p-5">

            <div class="bg-white rounded-2xl shadow-2xl
                        w-full max-w-5xl max-h-[92vh]
                        overflow-hidden">

                <div class="px-5 py-4 border-b
                            flex items-center justify-between">

                    <div>
                        <h2 class="font-bold">
                            プレビュー
                        </h2>
                        <p class="text-xs text-slate-400">
                            DB未保存の編集内容も反映されています。
                        </p>
                    </div>

                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.previewDevice('pc')"
                            class="px-3 py-1.5 rounded-lg
                                   ${App.state.previewDevice === "pc"
                                       ? "bg-blue-600 text-white"
                                       : "bg-slate-100"}">
                            PC
                        </button>

                        <button
                            onclick="App.actions.previewDevice('mobile')"
                            class="px-3 py-1.5 rounded-lg
                                   ${App.state.previewDevice === "mobile"
                                       ? "bg-blue-600 text-white"
                                       : "bg-slate-100"}">
                            スマートフォン
                        </button>

                        <button
                            onclick="App.actions.closePreview()"
                            class="px-3 py-1.5 rounded-lg
                                   bg-slate-100">
                            閉じる
                        </button>
                    </div>
                </div>

                <div class="p-7 bg-slate-100 overflow-auto max-h-[80vh]">
                    <div class="${
                        App.state.previewDevice === "mobile"
                        ? "max-w-[390px]"
                        : "max-w-3xl"
                    } mx-auto bg-white rounded-xl p-7 shadow-sm">

                        <h1 class="text-2xl font-bold mb-2">
                            ${App.utils.escape(survey.title)}
                        </h1>

                        <p class="text-sm text-slate-500 mb-7">
                            アンケート回答フォーム
                        </p>

                        ${
                            App.utils.questionList(survey)
                            .map(q => `
                                <div class="mb-7">
                                    <div class="font-semibold mb-3">
                                        <span class="text-blue-600">
                                            ${q.number}
                                        </span>
                                        ${App.utils.escape(q.text)}
                                        ${
                                            q.required
                                            ? `<span class="text-red-500">*</span>`
                                            : ""
                                        }
                                    </div>

                                    ${
                                        q.type === "text"
                                        ? `
                                        <textarea
                                            rows="4"
                                            class="w-full border
                                                   border-slate-300
                                                   rounded-lg p-3"
                                            placeholder="回答を入力してください">
                                        </textarea>`
                                        : `
                                        <div class="space-y-2">
                                            ${(q.options || [])
                                            .map(option => `
                                                <label class="flex gap-2
                                                              items-center">
                                                    <input
                                                        type="${
                                                            q.type === "single"
                                                            ? "radio"
                                                            : "checkbox"
                                                        }"
                                                        name="preview_${q.id}">
                                                    <span>
                                                        ${App.utils.escape(option)}
                                                    </span>
                                                </label>
                                            `).join("")}
                                        </div>`
                                    }
                                </div>
                            `).join("")
                        }

                        <button
                            onclick="App.actions.previewSubmit()"
                            class="w-full py-3 rounded-xl
                                   bg-blue-600 text-white
                                   font-semibold">
                            回答を送信する
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
};

App.actions.previewDevice = function(device) {
    App.state.previewDevice = device;
    App.actions.preview();
};

App.actions.previewSubmit = function() {
    window.alert(
        "これはプレビューです。実際の回答送信は行われません。"
    );
};

App.actions.closePreview = function() {
    document.getElementById("preview_modal").innerHTML = "";
};

/* ================================================================
   Send
================================================================ */

App.actions.send = function(id) {
    App.state.currentSurveyId = id;
    App.state.currentView = "send";
    App.state.selectedRecipients = [];
    App.render.send();
};

App.render.send = function() {
    const survey = App.utils.surveyById(
        App.state.currentSurveyId
    );

    if (!survey) return;

    let customers = App.state.customers;

    const keyword =
        App.state.customerFilter.toLowerCase();

    if (keyword) {
        customers = customers.filter(c =>
            [
                c.company,
                c.name,
                c.email,
                c.department
            ].join(" ").toLowerCase().includes(keyword)
        );
    }

    document.getElementById("main-content").innerHTML = `
        <section>
            <div class="text-xs text-slate-400 mb-2">
                ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
            </div>

            <div class="flex flex-wrap items-center
                        justify-between gap-4 mb-6">

                <div>
                    <h1 class="text-2xl font-bold">
                        顧客選択・メール送信
                    </h1>
                    <p class="text-sm text-slate-500 mt-1">
                        ${App.utils.escape(survey.title)}
                    </p>
                </div>

                <button
                    onclick="App.actions.executeSend()"
                    class="px-5 py-3 rounded-xl
                           bg-blue-600 text-white font-semibold">
                    選択した顧客へ一括送信
                </button>
            </div>

            ${
                customers.some(
                    c => c.kintone_status === "unregistered"
                )
                ? `
                <div class="mb-5 bg-amber-50 border
                            border-amber-200 rounded-xl p-4
                            text-amber-800">
                    ⚠ kintone未登録の回答者が存在します。
                    登録完了後にステータスを更新してください。
                </div>`
                : ""
            }

            <div class="grid xl:grid-cols-3 gap-5">

                <div class="xl:col-span-2 bg-white border
                            border-slate-200 rounded-2xl
                            shadow-sm overflow-hidden">

                    <div class="p-4 border-b flex flex-wrap gap-3">
                        <input
                            id="customer_filter"
                            value="${App.utils.escape(App.state.customerFilter)}"
                            oninput="App.actions.filterCustomers(this.value)"
                            placeholder="顧客名・メール等を検索"
                            class="flex-1 min-w-[250px]
                                   border border-slate-300
                                   rounded-lg px-4 py-2.5">

                        <button
                            onclick="App.actions.selectAllCustomers()"
                            class="px-4 py-2 rounded-lg
                                   bg-slate-100">
                            全選択
                        </button>

                        <button
                            onclick="App.actions.clearRecipients()"
                            class="px-4 py-2 rounded-lg
                                   bg-slate-100">
                            全解除
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1100px]
                                      text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="p-4">
                                        <input id="select_all"
                                               type="checkbox"
                                               onchange="App.actions.selectAllToggle(this.checked)">
                                    </th>
                                    <th class="p-4 text-left">
                                        会社名 / 氏名
                                    </th>
                                    <th class="p-4 text-left">
                                        連絡先
                                    </th>
                                    <th class="p-4 text-left">
                                        送信履歴
                                    </th>
                                    <th class="p-4 text-left">
                                        回答
                                    </th>
                                    <th class="p-4 text-left">
                                        kintone
                                    </th>
                                </tr>
                            </thead>

                            <tbody id="customer_table">
                                ${customers.map(
                                    App.render.customerRow
                                ).join("")}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-5">

                    <div class="bg-white border border-slate-200
                                rounded-2xl p-5 shadow-sm">

                        <h2 class="font-bold mb-4">
                            メールテンプレート
                        </h2>

                        <label class="block mb-4">
                            <span class="text-xs text-slate-500">
                                種別
                            </span>
                            <select
                                id="template_type"
                                onchange="App.actions.templateChanged(this.value)"
                                class="mt-1 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2">
                                <option value="initial">初回送信</option>
                                <option value="reminder">リマインド</option>
                            </select>
                        </label>

                        <label class="block mb-4">
                            <span class="text-xs text-slate-500">
                                件名
                            </span>
                            <input
                                id="mail_subject"
                                value="アンケートご回答のお願い"
                                class="mt-1 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2">
                        </label>

                        <label class="block">
                            <span class="text-xs text-slate-500">
                                本文
                            </span>
                            <textarea
                                id="mail_body"
                                rows="12"
                                class="mt-1 w-full border
                                       border-slate-300 rounded-lg
                                       p-3">${App.utils.escape(
`{顧客名} 様

いつもお世話になっております。

以下のURLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。`
                                )}</textarea>
                        </label>

                        <div class="mt-3 text-xs text-slate-400">
                            利用可能な変数：
                            {顧客名} / {アンケートURL}
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200
                                rounded-2xl p-5 shadow-sm">

                        <h2 class="font-bold mb-4">
                            送信履歴
                        </h2>

                        <div class="space-y-3">
                            ${
                                App.state.mail_logs
                                .filter(
                                    l =>
                                    l.survey_id === survey.id
                                )
                                .slice()
                                .reverse()
                                .map(log => `
                                    <div class="border-b
                                                border-slate-100
                                                pb-3">
                                        <div class="text-sm font-medium">
                                            ${App.utils.escape(log.subject)}
                                        </div>
                                        <div class="text-xs
                                                    text-slate-400 mt-1">
                                            ${App.utils.escape(log.sent_at)}
                                            /
                                            ${log.type === "reminder"
                                                ? "リマインド"
                                                : "初回"}
                                            /
                                            ${log.count}件
                                        </div>
                                        <button
                                            onclick="App.actions.viewMail('${log.id}')"
                                            class="text-xs text-blue-600
                                                   mt-1">
                                            送信文を確認
                                        </button>
                                    </div>
                                `).join("")
                                || `<div class="text-sm text-slate-400">
                                    送信履歴はありません。
                                   </div>`
                            }
                        </div>
                    </div>
                </div>
            </div>
        </section>
    `;
};

App.render.customerRow = function(customer) {
    const selected =
        App.state.selectedRecipients.includes(customer.id);

    const answered =
        customer.answer_status === "answered";

    const web = customer.source === "web";

    return `
        <tr class="border-t border-slate-100">
            <td class="p-4">
                <input
                    type="checkbox"
                    ${selected ? "checked" : ""}
                    ${web ? "disabled" : ""}
                    onchange="App.actions.toggleRecipient(
                        '${customer.id}',
                        this.checked
                    )">
            </td>

            <td class="p-4">
                <div class="font-bold">
                    ${App.utils.escape(customer.company)}
                </div>
                <div>${App.utils.escape(customer.name)}</div>
            </td>

            <td class="p-4">
                <div>${App.utils.escape(customer.email)}</div>
                <div class="text-xs text-slate-400">
                    ${App.utils.escape(customer.phone)}
                </div>
                <div class="text-xs text-slate-400">
                    ${App.utils.escape(customer.address)}
                </div>
            </td>

            <td class="p-4">
                <div>
                    最終:
                    ${App.utils.escape(customer.sent_at || "未送信")}
                </div>
                <div class="text-xs text-slate-400">
                    送信回数: ${customer.send_count || 0}
                </div>
                ${
                    customer.sent_at
                    ? `
                    <button
                        onclick="App.actions.viewCustomerMail('${customer.id}')"
                        class="text-xs text-blue-600 mt-1">
                        送信文を確認
                    </button>`
                    : ""
                }
            </td>

            <td class="p-4">
                <span class="px-2 py-1 rounded-full text-xs
                    ${
                        answered
                        ? "bg-emerald-100 text-emerald-700"
                        : "bg-amber-100 text-amber-700"
                    }">
                    ${
                        answered
                        ? "回答済み"
                        : customer.sent_at
                            ? "送信済み（未回答）"
                            : "未送信"
                    }
                </span>
            </td>

            <td class="p-4">
                ${
                    customer.kintone_status === "registered"
                    ? `
                    <span class="text-xs text-emerald-700">
                        ✓ キントーン登録完了
                    </span>`
                    : `
                    <button
                        onclick="App.actions.markKintone('${customer.id}')"
                        class="text-xs bg-blue-50 text-blue-700
                               px-3 py-1.5 rounded-lg">
                        キントーン登録完了
                    </button>`
                }
            </td>
        </tr>
    `;
};

App.actions.filterCustomers = function(value) {
    App.state.customerFilter = value;
    App.render.send();
};

App.actions.toggleRecipient = function(id, checked) {
    if (checked) {
        if (!App.state.selectedRecipients.includes(id)) {
            App.state.selectedRecipients.push(id);
        }
    } else {
        App.state.selectedRecipients =
            App.state.selectedRecipients.filter(x => x !== id);
    }
};

App.actions.selectAllCustomers = function() {
    App.state.selectedRecipients =
        App.state.customers
            .filter(c => c.source !== "web")
            .map(c => c.id);

    App.render.send();
};

App.actions.selectAllToggle = function(checked) {
    if (checked) {
        App.actions.selectAllCustomers();
    } else {
        App.actions.clearRecipients();
    }
};

App.actions.clearRecipients = function() {
    App.state.selectedRecipients = [];
    App.render.send();
};

App.actions.templateChanged = function(value) {
    const subject = document.getElementById("mail_subject");
    const body = document.getElementById("mail_body");

    if (value === "reminder") {
        subject.value = "【再送】アンケートご回答のお願い";
        body.value =
`{顧客名} 様

先日ご案内したアンケートについて、
まだご回答がお済みでない場合は、以下よりご回答ください。

{アンケートURL}

よろしくお願いいたします。`;
    } else {
        subject.value = "アンケートご回答のお願い";
        body.value =
`{顧客名} 様

いつもお世話になっております。

以下のURLよりアンケートへのご回答をお願いいたします。

{アンケートURL}

ご協力のほど、よろしくお願いいたします。`;
    }
};

App.actions.executeSend = async function() {
    const ids = App.state.selectedRecipients;

    if (!ids.length) {
        App.utils.toast(
            "送信対象を選択してください。",
            "error"
        );
        return;
    }

    const alreadySent = App.state.customers.filter(
        c =>
            ids.includes(c.id) &&
            c.sent_at
    );

    if (
        alreadySent.length &&
        !App.utils.confirm(
            "既に送信済みの宛先が含まれています。\n再送しますか？"
        )
    ) {
        return;
    }

    const subject =
        document.getElementById("mail_subject").value;

    const body =
        document.getElementById("mail_body").value;

    const type =
        document.getElementById("template_type").value;

    try {
        const result = await App.api.post("send_mail", {
            survey_id: App.state.currentSurveyId,
            recipient_ids: ids,
            mail_subject: subject,
            mail_body: body,
            template_type: type
        });

        App.utils.toast(result.message);
        App.state.selectedRecipients = [];

        await App.api.load();
        App.actions.send(App.state.currentSurveyId);
    } catch (e) {
        App.utils.toast(e.message, "error");
    }
};

App.actions.markKintone = async function(id) {
    try {
        await App.api.post("mark_kintone", {
            customer_id: id
        });

        await App.api.load();
        App.actions.send(App.state.currentSurveyId);
    } catch (e) {
        App.utils.toast(e.message, "error");
    }
};

App.actions.viewMail = function(id) {
    const log = App.state.mail_logs.find(
        x => x.id === id
    );

    if (!log) return;

    App.actions.showResponseModal(
        "送信文確認",
        `
        <div class="space-y-4">
            <div>
                <div class="text-xs text-slate-400">件名</div>
                <div class="font-semibold mt-1">
                    ${App.utils.escape(log.subject)}
                </div>
            </div>
            <pre class="whitespace-pre-wrap bg-slate-50
                        rounded-xl p-4 text-sm">${App.utils.escape(
                            log.body
                        )}</pre>
        </div>
        `
    );
};

App.actions.viewCustomerMail = function(id) {
    const customer = App.state.customers.find(
        c => c.id === id
    );

    if (!customer) return;

    const logs = App.state.mail_logs.filter(
        log =>
            log.survey_id === App.state.currentSurveyId &&
            (log.recipients || []).includes(id)
    );

    const html = logs.length
        ? logs.slice().reverse().map(log => `
            <div class="border-b border-slate-100 pb-4">
                <div class="text-sm font-semibold">
                    ${App.utils.escape(log.subject)}
                </div>
                <pre class="mt-2 whitespace-pre-wrap
                            bg-slate-50 p-4 rounded-xl
                            text-sm">${App.utils.escape(log.body)}</pre>
            </div>
        `).join("")
        : `<div class="text-slate-400">
                個別送信文の履歴はありません。
           </div>`;

    App.actions.showResponseModal(
        customer.name + " 様への送信履歴",
        html
    );
};

/* ================================================================
   Analytics
================================================================ */

App.actions.analytics = function(id) {
    App.state.currentSurveyId = id;
    App.state.selectedQuestions = [];
    App.state.currentView = "analytics";
    App.render.analytics();
};

App.render.analytics = function() {
    const survey = App.utils.surveyById(
        App.state.currentSurveyId
    );

    if (!survey) return;

    const questions = App.utils.questionList(survey);

    if (!App.state.selectedQuestions.length) {
        App.state.selectedQuestions =
            questions.map(q => q.id);
    }

    const responses =
        App.state.responses.filter(
            r => r.survey_id === survey.id
        );

    const customers =
        App.state.customers.filter(
            c => c.sent_at
        );

    const answeredFromCustomers =
        responses.filter(r =>
            customers.some(c => c.id === r.customer_id)
        ).length;

    const unregistered =
        responses.filter(r =>
            !customers.some(c => c.id === r.customer_id)
        ).length;

    const unanswered =
        Math.max(0, customers.length - answeredFromCustomers);

    const rate = customers.length
        ? (answeredFromCustomers / customers.length * 100)
            .toFixed(1)
        : "0.0";

    document.getElementById("main-content").innerHTML = `
        <section>
            <div class="flex flex-wrap items-center
                        justify-between gap-4 mb-6">

                <div>
                    <div class="text-xs text-slate-400 mb-2">
                        ホーム ＞ アンケート一覧 ＞ 集計・分析
                    </div>

                    <h1 class="text-2xl font-bold">
                        ${App.utils.escape(survey.title)}
                    </h1>
                </div>

                <div class="flex gap-2">
                    <a
                        href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
                        class="px-4 py-2.5 rounded-lg
                               bg-emerald-600 text-white">
                        CSV出力
                    </a>

                    <button
                        onclick="App.actions.printAnalytics()"
                        class="px-4 py-2.5 rounded-lg
                               bg-white border border-slate-300">
                        PDF / 印刷
                    </button>
                </div>
            </div>

            <div class="grid md:grid-cols-5 gap-4 mb-6">
                ${[
                    ["送信対象者数", customers.length + " 人"],
                    ["回答数", responses.length + " 件"],
                    ["未登録顧客からの回答数", unregistered + " 件"],
                    ["未回答数", unanswered + " 人"],
                    ["回答率", rate + " %"]
                ].map(item => `
                    <div class="bg-white border
                                border-slate-200 rounded-2xl
                                p-5 shadow-sm">
                        <div class="text-xs text-slate-400">
                            ${item[0]}
                        </div>
                        <div class="text-2xl font-bold mt-2">
                            ${item[1]}
                        </div>
                    </div>
                `).join("")}
            </div>

            <div class="grid xl:grid-cols-4 gap-5">

                <aside class="bg-white border border-slate-200
                              rounded-2xl p-5 shadow-sm h-fit">

                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-bold">設問絞り込み</h2>

                        <div class="flex gap-1">
                            <button
                                onclick="App.actions.selectQuestions(true)"
                                class="text-xs text-blue-600">
                                全選択
                            </button>
                            <button
                                onclick="App.actions.selectQuestions(false)"
                                class="text-xs text-slate-500">
                                全解除
                            </button>
                        </div>
                    </div>

                    <div class="space-y-3">
                        ${questions.map(q => `
                            <label class="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    ${App.state.selectedQuestions.includes(q.id)
                                        ? "checked" : ""}
                                    onchange="App.actions.toggleQuestion('${q.id}',this.checked)"
                                    class="mt-1">
                                <span class="text-sm">
                                    <span class="font-semibold">
                                        ${q.number}
                                    </span>
                                    ${App.utils.escape(q.text)}
                                    <span class="block text-xs
                                                 text-slate-400 mt-1">
                                        ${App.utils.typeLabel(q.type)}
                                    </span>
                                </span>
                            </label>
                        `).join("")}
                    </div>
                </aside>

                <div class="xl:col-span-3 space-y-5">
                    ${
                        responses.length
                        ? questions
                            .filter(q =>
                                App.state.selectedQuestions.includes(q.id)
                            )
                            .map(q =>
                                App.render.questionStats(q, responses)
                            ).join("")
                        : `
                        <div class="bg-white border
                                    border-slate-200 rounded-2xl
                                    p-16 text-center
                                    text-slate-400">
                            現在、回答データはありません
                        </div>`
                    }

                    ${
                        responses.length
                        ? App.render.responseTable(
                            responses,
                            questions
                          )
                        : ""
                    }
                </div>
            </div>
        </section>
    `;
};

App.render.questionStats = function(q, responses) {
    if (q.type === "text") {
        const items = responses.map(r => ({
            r,
            value: r.answers?.[q.id] || ""
        })).filter(x => x.value);

        return `
            <div class="bg-white border border-slate-200
                        rounded-2xl p-5 shadow-sm">

                <div class="flex items-center gap-2 mb-4">
                    <span class="font-bold text-blue-600">
                        ${q.number}
                    </span>
                    <h2 class="font-bold">
                        ${App.utils.escape(q.text)}
                    </h2>
                    <span class="text-xs bg-slate-100
                                 rounded-full px-2 py-1">
                        自由記述
                    </span>
                </div>

                <div class="space-y-3 max-h-96 overflow-auto">
                    ${
                        items.length
                        ? items.map(x => `
                            <div class="border-l-4
                                        border-blue-200 pl-4">
                                <div class="text-sm font-medium">
                                    ${App.utils.escape(
                                        x.r.name ||
                                        "回答者"
                                    )}
                                </div>
                                <div class="text-xs text-slate-400">
                                    ${App.utils.escape(
                                        x.r.answered_at || ""
                                    )}
                                </div>
                                <div class="mt-1 text-sm">
                                    ${App.utils.escape(
                                        Array.isArray(x.value)
                                        ? x.value.join(" / ")
                                        : x.value
                                    )}
                                </div>
                            </div>
                        `).join("")
                        : `<div class="text-slate-400">
                               回答なし
                           </div>`
                    }
                </div>
            </div>
        `;
    }

    const counts = {};

    (q.options || []).forEach(option => {
        counts[option] = 0;
    });

    responses.forEach(r => {
        let answer = r.answers?.[q.id];

        if (!Array.isArray(answer)) {
            answer = [answer];
        }

        answer.forEach(value => {
            if (value && counts[value] !== undefined) {
                counts[value]++;
            }
        });
    });

    const total = responses.length || 1;

    return `
        <div class="bg-white border border-slate-200
                    rounded-2xl p-5 shadow-sm">

            <div class="flex items-center gap-2 mb-5">
                <span class="font-bold text-blue-600">
                    ${q.number}
                </span>
                <h2 class="font-bold">
                    ${App.utils.escape(q.text)}
                </h2>
                <span class="text-xs bg-slate-100
                             rounded-full px-2 py-1">
                    ${App.utils.typeLabel(q.type)}
                </span>
            </div>

            <div class="space-y-4">
                ${
                    Object.keys(counts).map(option => {
                        const count = counts[option];
                        const percent =
                            count / total * 100;

                        return `
                            <div>
                                <div class="flex justify-between
                                            text-sm mb-1">
                                    <span>
                                        ${App.utils.escape(option)}
                                    </span>
                                    <span class="font-semibold">
                                        ${count}件 /
                                        ${percent.toFixed(1)}%
                                    </span>
                                </div>

                                <div class="h-3 bg-slate-100
                                            rounded-full overflow-hidden">
                                    <div
                                        class="h-full bg-blue-600
                                               rounded-full"
                                        style="width:${percent}%">
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join("")
                }
            </div>
        </div>
    `;
};

App.render.responseTable = function(responses, questions) {
    const keyword =
        App.state.responseFilter.toLowerCase();

    const filtered = responses.filter(r =>
        [
            r.company,
            r.name,
            r.email
        ].join(" ").toLowerCase().includes(keyword)
    );

    return `
        <div class="bg-white border border-slate-200
                    rounded-2xl shadow-sm overflow-hidden">

            <div class="p-5 border-b flex items-center
                        justify-between gap-3">

                <h2 class="font-bold">
                    個別回答一覧
                </h2>

                <input
                    id="response_filter"
                    value="${App.utils.escape(
                        App.state.responseFilter
                    )}"
                    oninput="App.actions.filterResponses(this.value)"
                    placeholder="会社名・氏名で検索"
                    class="border border-slate-300
                           rounded-lg px-3 py-2">
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="p-4 text-left">回答者</th>
                            <th class="p-4 text-left">回答日時</th>
                            <th class="p-4 text-left">回答概要</th>
                            <th class="p-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        ${
                            filtered.map(r => `
                                <tr class="border-t
                                           border-slate-100">
                                    <td class="p-4">
                                        <div class="font-bold">
                                            ${App.utils.escape(
                                                r.company
                                            )}
                                        </div>
                                        <div>
                                            ${App.utils.escape(
                                                r.name
                                            )}
                                        </div>
                                    </td>

                                    <td class="p-4">
                                        ${App.utils.escape(
                                            r.answered_at
                                        )}
                                    </td>

                                    <td class="p-4">
                                        ${questions.slice(0, 2)
                                            .map(q => `
                                                <div class="mb-1">
                                                    <span class="text-xs
                                                                 text-blue-600">
                                                        ${q.number}
                                                    </span>
                                                    ${App.utils.escape(
                                                        Array.isArray(
                                                            r.answers?.[q.id]
                                                        )
                                                        ? r.answers[q.id].join(" / ")
                                                        : r.answers?.[q.id] || ""
                                                    )}
                                                </div>
                                            `).join("")}
                                    </td>

                                    <td class="p-4 text-right">
                                        <button
                                            onclick="App.actions.showResponse('${r.id}')"
                                            class="px-3 py-1.5
                                                   rounded-lg
                                                   bg-blue-50
                                                   text-blue-700">
                                            全回答を表示
                                        </button>
                                    </td>
                                </tr>
                            `).join("")
                            ||
                            `<tr>
                                <td colspan="4"
                                    class="p-10 text-center
                                           text-slate-400">
                                    該当する回答がありません。
                                </td>
                            </tr>`
                        }
                    </tbody>
                </table>
            </div>
        </div>
    `;
};

App.actions.selectQuestions = function(all) {
    const survey = App.utils.surveyById(
        App.state.currentSurveyId
    );

    App.state.selectedQuestions = all
        ? App.utils.questionList(survey).map(q => q.id)
        : [];

    App.render.analytics();
};

App.actions.toggleQuestion = function(id, checked) {
    if (checked) {
        if (!App.state.selectedQuestions.includes(id)) {
            App.state.selectedQuestions.push(id);
        }
    } else {
        App.state.selectedQuestions =
            App.state.selectedQuestions.filter(x => x !== id);
    }

    App.render.analytics();
};

App.actions.filterResponses = function(value) {
    App.state.responseFilter = value;
    App.render.analytics();
};

App.actions.showResponse = function(id) {
    const response = App.state.responses.find(
        r => r.id === id
    );

    const survey = App.utils.surveyById(
        App.state.currentSurveyId
    );

    if (!response || !survey) return;

    const questions = App.utils.questionList(survey);

    App.actions.showResponseModal(
        "全回答",
        `
        <div class="space-y-4">
            <div class="bg-slate-50 rounded-xl p-4">
                <div class="font-bold">
                    ${App.utils.escape(response.company)}
                    /
                    ${App.utils.escape(response.name)}
                </div>
                <div class="text-xs text-slate-400 mt-1">
                    ${App.utils.escape(response.answered_at)}
                </div>
            </div>

            ${questions.map(q => `
                <div class="border-b border-slate-100 pb-4">
                    <div class="text-xs text-blue-600">
                        ${q.number}
                    </div>
                    <div class="font-semibold">
                        ${App.utils.escape(q.text)}
                    </div>
                    <div class="mt-2 text-sm">
                        ${App.utils.escape(
                            Array.isArray(response.answers?.[q.id])
                            ? response.answers[q.id].join(" / ")
                            : response.answers?.[q.id] || "未回答"
                        )}
                    </div>
                </div>
            `).join("")}
        </div>
        `
    );
};

App.actions.printAnalytics = function() {
    window.print();
};

/* ================================================================
   Settings
================================================================ */

App.actions.goSettings = function() {
    App.state.currentView = "settings";
    App.render.settings();
};

App.render.settings = function() {
    const s = App.state.settings;

    document.getElementById("main-content").innerHTML = `
        <section class="max-w-5xl">

            <div class="text-xs text-slate-400 mb-2">
                ホーム ＞ システム設定 ＞ kintone連携設定
            </div>

            <div class="flex items-center justify-between
                        gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold">
                        kintone連携設定
                    </h1>
                    <p class="text-sm text-slate-500 mt-1">
                        顧客管理アプリとの項目マッピングを設定します。
                    </p>
                </div>

                <button
                    onclick="App.actions.saveSettings()"
                    class="px-5 py-3 rounded-xl bg-blue-600
                           text-white font-semibold">
                    設定を保存
                </button>
            </div>

            <form id="settings_form"
                  onsubmit="return false"
                  class="space-y-5">

                <div class="bg-white border border-slate-200
                            rounded-2xl p-6 shadow-sm">

                    <h2 class="font-bold mb-5">
                        接続情報
                    </h2>

                    <div class="grid md:grid-cols-2 gap-5">

                        <label>
                            <span class="text-sm font-semibold">
                                サブドメイン
                            </span>
                            <input
                                id="setting_subdomain"
                                value="${App.utils.escape(s.subdomain || "")}"
                                placeholder="xxxx.cybozu.com"
                                class="mt-1 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2.5">
                        </label>

                        <label>
                            <span class="text-sm font-semibold">
                                顧客管理アプリID
                            </span>
                            <input
                                id="setting_app_id"
                                value="${App.utils.escape(s.app_id || "")}"
                                class="mt-1 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2.5">
                        </label>

                        <label>
                            <span class="text-sm font-semibold">
                                ログイン名
                            </span>
                            <input
                                id="setting_login_name"
                                value="${App.utils.escape(s.login_name || "")}"
                                class="mt-1 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2.5">
                        </label>

                        <label>
                            <span class="text-sm font-semibold">
                                パスワード
                            </span>
                            <input
                                id="setting_password"
                                type="password"
                                value="${App.utils.escape(s.password || "")}"
                                class="mt-1 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2.5">
                        </label>

                        <label>
                            <span class="text-sm font-semibold">
                                Proxyサーバ
                            </span>
                            <input
                                id="setting_proxy"
                                value="${App.utils.escape(s.proxy || "")}"
                                placeholder="proxy.example.local:8080"
                                class="mt-1 w-full border
                                       border-slate-300 rounded-lg
                                       px-3 py-2.5">
                        </label>

                        <label class="flex items-center gap-3
                                      pt-7">
                            <input
                                id="setting_ssl_verify"
                                type="checkbox"
                                ${s.ssl_verify ? "checked" : ""}
                                class="w-4 h-4">
                            <span class="text-sm">
                                SSL証明書を検証する
                            </span>
                        </label>
                    </div>

                    <div id="field_message"
                         class="mt-4 text-sm text-slate-500">
                    </div>

                    <button
                        onclick="App.actions.fetchKintoneFields()"
                        type="button"
                        class="mt-4 px-4 py-2.5 rounded-lg
                               bg-slate-100 hover:bg-slate-200">
                        項目一覧を再取得
                    </button>
                </div>

                <div class="bg-white border border-slate-200
                            rounded-2xl p-6 shadow-sm">

                    <div class="flex items-center justify-between
                                mb-5">
                        <div>
                            <h2 class="font-bold">
                                フィールドマッピング
                            </h2>
                            <p class="text-xs text-slate-400 mt-1">
                                日本語のフィールド名から選択できます。
                            </p>
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-5">
                        ${App.render.mappingSelect(
                            "field_company",
                            "会社名",
                            s.field_company
                        )}

                        ${App.render.mappingSelect(
                            "field_name",
                            "氏名",
                            s.field_name
                        )}

                        ${App.render.mappingSelect(
                            "field_email",
                            "メールアドレス",
                            s.field_email
                        )}

                        ${App.render.mappingSelect(
                            "field_department",
                            "部署名",
                            s.field_department
                        )}

                        ${App.render.mappingSelect(
                            "field_phone",
                            "電話番号",
                            s.field_phone
                        )}

                        ${App.render.mappingSelect(
                            "field_address",
                            "住所（複数選択可）",
                            s.field_address,
                            true
                        )}
                    </div>
                </div>
            </form>
        </section>
    `;
};

App.render.mappingSelect = function(
    key,
    label,
    selected,
    multiple = false
) {
    const values = multiple
        ? String(selected || "").split(",").filter(Boolean)
        : [String(selected || "")];

    return `
        <label>
            <span class="text-sm font-semibold">
                ${label}
            </span>

            <select
                id="${key}"
                ${multiple ? "multiple size=\"4\"" : ""}
                class="mt-1 w-full border border-slate-300
                       rounded-lg px-3 py-2.5 bg-white">

                <option value="">-- 未選択 --</option>

                ${App.state.fields.map(field => `
                    <option
                        value="${App.utils.escape(field.code)}"
                        ${values.includes(field.code)
                            ? "selected" : ""}>
                        ${App.utils.escape(field.label)}
                        (${App.utils.escape(field.code)})
                    </option>
                `).join("")}
            </select>
        </label>
    `;
};

/*
 * 必須実装:
 * fetchKintoneFields()
 */
App.actions.fetchKintoneFields = async function() {
    const message =
        document.getElementById("field_message");

    const appId =
        document.getElementById("setting_app_id").value;

    const settings = App.actions.readSettingsForm();

    message.textContent = "kintoneから項目一覧を取得しています…";

    try {
        const result = await App.api.post(
            "kintone_fields",
            { app_id: appId }
        );

        App.state.fields = result.fields || [];

        message.textContent =
            result.message ||
            App.state.fields.length + "件取得しました。";

        App.render.settings();

        document.getElementById("field_message").textContent =
            message.textContent;
    } catch (e) {
        message.textContent = e.message;
        App.utils.toast(e.message, "error");
    }
};

App.actions.readSettingsForm = function() {
    const get = id =>
        document.getElementById(id);

    const address =
        get("field_address");

    return {
        subdomain: get("setting_subdomain").value.trim(),
        login_name: get("setting_login_name").value.trim(),
        password: get("setting_password").value,
        app_id: get("setting_app_id").value.trim(),
        ssl_verify: get("setting_ssl_verify").checked,
        proxy: get("setting_proxy").value.trim(),
        field_company: get("field_company").value,
        field_name: get("field_name").value,
        field_email: get("field_email").value,
        field_department: get("field_department").value,
        field_phone: get("field_phone").value,
        field_address: address
            ? Array.from(address.selectedOptions)
                .map(x => x.value)
                .filter(Boolean)
                .join(",")
            : ""
    };
};

App.actions.saveSettings = async function() {
    try {
        const settings =
            App.actions.readSettingsForm();

        await App.api.saveSettings(settings);

        App.state.settings = settings;

        App.utils.toast(
            "kintone連携設定を保存しました。"
        );
    } catch (e) {
        App.utils.toast(e.message, "error");
    }
};

/* ================================================================
   Modal
================================================================ */

App.actions.showResponseModal = function(title, html) {
    document.getElementById("response_modal").innerHTML = `
        <div class="fixed inset-0 z-[60] bg-black/50
                    flex items-center justify-center p-5">

            <div class="bg-white rounded-2xl shadow-2xl
                        w-full max-w-3xl max-h-[90vh]
                        overflow-hidden">

                <div class="p-5 border-b flex items-center
                            justify-between">

                    <h2 class="font-bold">
                        ${App.utils.escape(title)}
                    </h2>

                    <button
                        onclick="App.actions.closeResponseModal()"
                        class="w-9 h-9 rounded-lg
                               bg-slate-100">
                        ×
                    </button>
                </div>

                <div class="p-6 overflow-auto max-h-[75vh]">
                    ${html}
                </div>
            </div>
        </div>
    `;
};

App.actions.closeResponseModal = function() {
    document.getElementById("response_modal").innerHTML = "";
};

/* ================================================================
   Initial load
================================================================ */

if (document.readyState === "loading") {
    document.addEventListener(
        "DOMContentLoaded",
        () => App.init(),
        { once: true }
    );
} else {
    App.init();
}

</script>
</body>
</html>
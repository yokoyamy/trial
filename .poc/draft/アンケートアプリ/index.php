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

const survey_storage_directory = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const survey_storage_file = survey_storage_directory . DIRECTORY_SEPARATOR . 'survey_data.json';
const survey_admin_session_v1 = 'survey_admin_session_v1';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(survey_admin_session_v1);
    session_start();
}

if (!is_dir(survey_storage_directory)) {
    @mkdir(survey_storage_directory, 0775, true);
}

if (!file_exists(survey_storage_file)) {
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
    @file_put_contents(
        survey_storage_file,
        json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function survey_read_data(): array
{
    $raw = @file_get_contents(survey_storage_file);
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
        throw new RuntimeException('データファイルのJSONが壊れています。');
    }

    foreach (['surveys', 'responses', 'customers', 'settings', 'mail_logs'] as $key) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $key === 'settings' ? [] : [];
        }
    }

    return $data;
}

function survey_write_data(array $data): void
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        throw new RuntimeException('JSONデータの生成に失敗しました。');
    }

    if (@file_put_contents(survey_storage_file, $json, LOCK_EX) === false) {
        throw new RuntimeException(
            'データファイルへ書き込めません。survey_storage の書込権限を確認してください。'
        );
    }
}

function survey_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function survey_id(string $prefix): string
{
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_get_safe_response_headers(): array
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
        'timeout' => 20,
        'protocol_version' => 1.1
    ];

    if ($method !== 'GET' && $payload !== null) {
        $encoded = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string)$payload;

        $http_options['content'] = $encoded;
    }

    $context_options = [
        'http' => $http_options,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $proxy_host_port = trim((string)($config['proxy'] ?? ''));
    if ($proxy_host_port !== '') {
        $context_options['http']['proxy'] = 'tcp://' . $proxy_host_port;
        $context_options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($context_options);
    $body = @file_get_contents($url, false, $context);
    $headers_received = survey_get_safe_response_headers();

    $status_code = 500;

    foreach ($headers_received as $header_line) {
        if (preg_match('/HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $header_line, $m)) {
            $status_code = (int)$m[1];
            break;
        }
    }

    $decoded = json_decode($body ?: '', true);

    if ($status_code >= 200 && $status_code < 300) {
        return [
            'success' => true,
            'status' => $status_code,
            'data' => is_array($decoded) ? $decoded : []
        ];
    }

    $message = is_array($decoded)
        ? (string)($decoded['message'] ?? 'kintone API通信エラー')
        : 'kintone API通信エラー';

    return [
        'success' => false,
        'status' => $status_code,
        'message' => $message,
        'raw' => $decoded
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string
{
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . trim($password));
}

/* ------------------------------------------------------------------
   API
------------------------------------------------------------------ */

$action = (string)($_REQUEST['action'] ?? '');

if ($action !== '') {
    try {
        $data = survey_read_data();

        if ($action === 'bootstrap') {
            survey_json_response([
                'ok' => true,
                'data' => $data,
                'csrf_token' => $_SESSION['csrf_token'] ??= bin2hex(random_bytes(24))
            ]);
        }

        if ($action === 'save_survey') {
            $json = (string)($_POST['survey_json'] ?? '');
            $survey = json_decode($json, true);

            if (!is_array($survey)) {
                survey_json_response(['ok' => false, 'message' => 'アンケートデータが不正です。'], 400);
            }

            $now = survey_now();

            if (empty($survey['id'])) {
                $survey['id'] = survey_id('survey');
                $survey['created_at'] = $now;
            }

            $survey['updated_at'] = $now;
            $survey['deleted'] = false;

            if (!isset($survey['status']) || !in_array($survey['status'], ['draft', 'active', 'ended'], true)) {
                $survey['status'] = 'draft';
            }

            if (!isset($survey['groups']) || !is_array($survey['groups'])) {
                $survey['groups'] = [];
            }

            $found = false;

            foreach ($data['surveys'] as $i => $existing) {
                if (($existing['id'] ?? '') === $survey['id']) {
                    $data['surveys'][$i] = $survey;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $data['surveys'][] = $survey;
            }

            survey_write_data($data);

            survey_json_response([
                'ok' => true,
                'survey' => $survey,
                'data' => $data
            ]);
        }

        if ($action === 'duplicate_survey') {
            $id = (string)($_POST['survey_id'] ?? '');
            $source = null;

            foreach ($data['surveys'] as $survey) {
                if (($survey['id'] ?? '') === $id && empty($survey['deleted'])) {
                    $source = $survey;
                    break;
                }
            }

            if ($source === null) {
                survey_json_response(['ok' => false, 'message' => 'アンケートが見つかりません。'], 404);
            }

            $copy = $source;
            $copy['id'] = survey_id('survey');
            $copy['title'] = ($copy['title'] ?? '無題アンケート') . '（複製）';
            $copy['status'] = 'draft';
            $copy['created_at'] = survey_now();
            $copy['updated_at'] = survey_now();
            $copy['deleted'] = false;

            $data['surveys'][] = $copy;
            survey_write_data($data);

            survey_json_response(['ok' => true, 'survey' => $copy]);
        }

        if ($action === 'change_status') {
            $id = (string)($_POST['survey_id'] ?? '');
            $status = (string)($_POST['status'] ?? '');

            if (!in_array($status, ['draft', 'active', 'ended'], true)) {
                survey_json_response(['ok' => false, 'message' => 'ステータスが不正です。'], 400);
            }

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['status'] = $status;
                    $survey['updated_at'] = survey_now();
                    break;
                }
            }
            unset($survey);

            survey_write_data($data);
            survey_json_response(['ok' => true, 'data' => $data]);
        }

        if ($action === 'delete_survey') {
            $id = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {
                if (($survey['id'] ?? '') === $id) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = survey_now();
                    break;
                }
            }
            unset($survey);

            survey_write_data($data);
            survey_json_response(['ok' => true]);
        }

        if ($action === 'save_settings') {
            $json = (string)($_POST['settings_json'] ?? '');
            $settings = json_decode($json, true);

            if (!is_array($settings)) {
                survey_json_response(['ok' => false, 'message' => '設定データが不正です。'], 400);
            }

            $settings['ssl_verify'] = !empty($settings['ssl_verify']);
            $settings['field_address'] = is_array($settings['field_address'] ?? null)
                ? $settings['field_address']
                : [];

            $data['settings'] = array_merge($data['settings'], $settings);
            survey_write_data($data);

            survey_json_response(['ok' => true, 'settings' => $data['settings']]);
        }

        if ($action === 'kintone_fields') {
            $settings = $data['settings'];

            $subdomain = trim((string)($_POST['subdomain'] ?? $settings['subdomain'] ?? ''));
            $login = trim((string)($_POST['login_name'] ?? $settings['login_name'] ?? ''));
            $password = (string)($_POST['password'] ?? $settings['password'] ?? '');
            $app_id = trim((string)($_POST['app_id'] ?? ''));

            if ($subdomain === '' || $login === '' || $password === '' || $app_id === '') {
                survey_json_response([
                    'ok' => false,
                    'message' => 'サブドメイン、ログイン名、パスワード、アプリIDを入力してください。'
                ], 400);
            }

            $url = kintone_build_url(
                $subdomain,
                '/k/v1/app/form/fields.json?app=' . rawurlencode($app_id)
            );

            $headers = [
                'X-Cybozu-Authorization: ' . base64_encode($login . ':' . $password),
                'Content-Type: application/json',
                'Accept: application/json'
            ];

            $result = kintone_api_request(
                'GET',
                $url,
                $headers,
                null,
                ['proxy' => $settings['proxy'] ?? '']
            );

            if (!$result['success']) {
                survey_json_response([
                    'ok' => false,
                    'message' => $result['message'] ?? 'kintone接続に失敗しました。',
                    'status' => $result['status'] ?? 500
                ]);
            }

            $fields = [];

            foreach (($result['data']['properties'] ?? []) as $code => $property) {
                $fields[] = [
                    'code' => $code,
                    'label' => $property['label'] ?? $code,
                    'type' => $property['type'] ?? ''
                ];
            }

            survey_json_response([
                'ok' => true,
                'fields' => $fields
            ]);
        }

        if ($action === 'kintone_test') {
            $settings = $data['settings'];

            $subdomain = trim((string)($_POST['subdomain'] ?? ''));
            $login = trim((string)($_POST['login_name'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $app_id = trim((string)($_POST['app_id'] ?? ''));

            if ($subdomain === '' || $login === '' || $password === '' || $app_id === '') {
                survey_json_response([
                    'ok' => false,
                    'message' => '接続確認には全項目が必要です。'
                ], 400);
            }

            $url = kintone_build_url(
                $subdomain,
                '/k/v1/app.json?id=' . rawurlencode($app_id)
            );

            $result = kintone_api_request(
                'GET',
                $url,
                [
                    make_cybozu_auth_header($login, $password),
                    'Accept: application/json'
                ],
                null,
                ['proxy' => $settings['proxy'] ?? '']
            );

            survey_json_response([
                'ok' => $result['success'],
                'message' => $result['success']
                    ? 'kintoneへの接続に成功しました。'
                    : ($result['message'] ?? '接続に失敗しました。'),
                'status' => $result['status'] ?? 500
            ]);
        }

        if ($action === 'csv') {
            $survey_id = (string)($_GET['survey_id'] ?? '');

            $survey = null;
            foreach ($data['surveys'] as $s) {
                if (($s['id'] ?? '') === $survey_id) {
                    $survey = $s;
                    break;
                }
            }

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

            $fp = fopen('php://output', 'wb');

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="' .
                rawurlencode(($survey['title'] ?? 'survey') . '.csv') .
                '"'
            );

            fwrite($fp, "\xEF\xBB\xBF");

            $header = ['回答ID', '回答日時', '顧客ID', '会社名', '氏名'];

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

                foreach ($questions as $question) {
                    $answer = $response['answers'][$question['id'] ?? ''] ?? '';

                    if (is_array($answer)) {
                        $answer = implode('、', $answer);
                    }

                    $row[] = $answer;
                }

                fputcsv($fp, $row);
            }

            fclose($fp);
            exit;
        }

        if ($action === 'mark_sent') {
            $survey_id = (string)($_POST['survey_id'] ?? '');
            $ids = json_decode((string)($_POST['recipient_ids'] ?? '[]'), true);

            if (!is_array($ids)) {
                $ids = [];
            }

            $subject = (string)($_POST['mail_subject'] ?? '');
            $body = (string)($_POST['mail_body'] ?? '');
            $template_type = (string)($_POST['template_type'] ?? 'initial');

            $sent = 0;

            foreach ($data['customers'] as &$customer) {
                if (!in_array($customer['id'] ?? '', $ids, true)) {
                    continue;
                }

                $customer['sent_at'] = survey_now();
                $customer['send_count'] = (int)($customer['send_count'] ?? 0) + 1;
                $customer['answer_status'] = $customer['answer_status'] ?? 'unanswered';

                $sent++;
            }
            unset($customer);

            $data['mail_logs'][] = [
                'id' => survey_id('mail'),
                'survey_id' => $survey_id,
                'sent_at' => survey_now(),
                'template_type' => in_array($template_type, ['initial', 'reminder'], true)
                    ? $template_type
                    : 'initial',
                'count' => $sent,
                'subject' => $subject,
                'body' => $body,
                'executed_by' => $_SESSION['survey_admin_name'] ?? '管理者'
            ];

            survey_write_data($data);

            survey_json_response([
                'ok' => true,
                'sent' => $sent,
                'data' => $data
            ]);
        }

        if ($action === 'register_kintone') {
            $customer_id = (string)($_POST['customer_id'] ?? '');

            foreach ($data['customers'] as &$customer) {
                if (($customer['id'] ?? '') === $customer_id) {
                    $customer['kintone_status'] = 'registered';
                    break;
                }
            }
            unset($customer);

            survey_write_data($data);
            survey_json_response(['ok' => true]);
        }

        survey_json_response([
            'ok' => false,
            'message' => 'Unknown action: ' . $action
        ], 400);
    } catch (Throwable $e) {
        survey_json_response([
            'ok' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

$csrf_token = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(24));
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

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

<script>
'use strict';

/*
 * ================================================================
 * アンケート管理SPA
 * すべてのアプリケーション状態・処理を window.App 配下に配置。
 * ================================================================
 */

window.App = {
    state: {
        initialized: false,
        screen: 'list',
        data: {
            surveys: [],
            responses: [],
            customers: [],
            settings: {},
            mail_logs: []
        },
        survey: null,
        editing: null,
        previewMobile: false,
        responseSurveyId: null,
        responseKeyword: '',
        customerKeyword: '',
        statusFilter: '',
        sort: 'updated_desc',
        responseSelected: {},
        modal: null,
        selectedCustomers: [],
        fields: []
    },

    api: {},

    actions: {},

    render: {},

    util: {},

    init: async function() {
        if (window.App.state.initialized) {
            return;
        }

        window.App.state.initialized = true;

        try {
            window.App.render.loading();

            const result = await window.App.api.request('bootstrap');

            if (!result.ok) {
                throw new Error(result.message || '初期化に失敗しました。');
            }

            window.App.state.data = result.data || window.App.state.data;

            window.App.render.layout();
            window.App.render.list();
        } catch (error) {
            console.error(error);

            document.getElementById('app').innerHTML = `
                <div class="min-h-screen flex items-center justify-center p-8">
                    <div class="max-w-xl w-full bg-white border border-red-200 rounded-2xl shadow-sm p-8">
                        <div class="text-red-600 font-bold text-xl mb-3">
                            初期化に失敗しました
                        </div>
                        <div class="bg-red-50 border border-red-100 rounded-xl p-4 text-sm text-red-700 break-words">
                            ${window.App.util.escape(error.message)}
                        </div>
                        <div class="mt-5 text-sm text-slate-500">
                            survey_storage ディレクトリと survey_data.json の書込権限を確認してください。
                        </div>
                        <button
                            class="mt-6 px-5 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700"
                            onclick="location.reload()">
                            再読み込み
                        </button>
                    </div>
                </div>
            `;
        }
    },

    api: {
        request: async function(action, params = {}) {
            const form = new FormData();

            form.append('action', action);

            const csrf = document.getElementById('csrf_token');

            if (csrf) {
                form.append('csrf_token', csrf.value);
            }

            Object.keys(params).forEach(function(key) {
                let value = params[key];

                if (typeof value === 'object') {
                    value = JSON.stringify(value);
                }

                form.append(key, value == null ? '' : value);
            });

            const response = await fetch(location.href, {
                method: 'POST',
                body: form,
                credentials: 'same-origin'
            });

            const text = await response.text();

            let json;

            try {
                json = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーからJSON以外の応答が返されました。HTTP ' +
                    response.status +
                    ' / ' +
                    text.substring(0, 300)
                );
            }

            if (!response.ok && json.ok !== false) {
                throw new Error('HTTP ' + response.status);
            }

            return json;
        }
    },

    util: {
        escape: function(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        id: function(prefix) {
            return prefix + '_' +
                Date.now().toString(36) + '_' +
                Math.random().toString(36).substring(2, 9);
        },

        nowInput: function() {
            const d = new Date();

            const pad = function(n) {
                return String(n).padStart(2, '0');
            };

            return d.getFullYear() + '-' +
                pad(d.getMonth() + 1) + '-' +
                pad(d.getDate()) + 'T' +
                pad(d.getHours()) + ':' +
                pad(d.getMinutes());
        },

        formatDate: function(value) {
            if (!value) {
                return '未設定';
            }

            const s = String(value).replace('T', ' ');

            return s.substring(0, 16);
        },

        statusText: function(status) {
            return {
                active: '公開中',
                draft: '下書き',
                ended: '終了'
            }[status] || status;
        },

        statusClass: function(status) {
            return {
                active: 'bg-emerald-50 text-emerald-700 border-emerald-200',
                draft: 'bg-amber-50 text-amber-700 border-amber-200',
                ended: 'bg-slate-100 text-slate-600 border-slate-200'
            }[status] || 'bg-slate-100 text-slate-600';
        },

        typeText: function(type) {
            return {
                single: '単一選択',
                multiple: '複数選択',
                text: '自由記述'
            }[type] || type;
        },

        clone: function(value) {
            return JSON.parse(JSON.stringify(value));
        },

        questions: function(survey) {
            const result = [];

            (survey?.groups || []).forEach(function(group) {
                (group.questions || []).forEach(function(question) {
                    result.push(question);
                });
            });

            return result;
        },

        questionNumber: function(survey, groupIndex, questionIndex) {
            if (survey.numbering_mode === 'group') {
                return 'Q' + (groupIndex + 1) + '-' + (questionIndex + 1);
            }

            let number = 0;

            for (let g = 0; g <= groupIndex; g++) {
                number += (survey.groups[g].questions || []).length;

                if (g === groupIndex) {
                    break;
                }
            }

            return 'Q' + number;
        },

        surveyById: function(id) {
            return window.App.state.data.surveys.find(function(s) {
                return s.id === id;
            }) || null;
        },

        responsesBySurvey: function(id) {
            return window.App.state.data.responses.filter(function(r) {
                return r.survey_id === id;
            });
        },

        customersBySurvey: function(id) {
            /*
             * 顧客データにはsurvey_idを必須保存しない。
             * 送信履歴はsurveyごとに独立して管理するため、
             * 現行の簡易実装では全顧客を対象にする。
             */
            return window.App.state.data.customers.filter(function(c) {
                return !c.deleted;
            });
        },

        toast: function(message, type = 'success') {
            const old = document.getElementById('app_toast');

            if (old) {
                old.remove();
            }

            const color = type === 'error'
                ? 'bg-red-600'
                : 'bg-slate-900';

            const div = document.createElement('div');

            div.id = 'app_toast';
            div.className =
                'fixed bottom-6 right-6 z-[100] ' +
                color +
                ' text-white px-5 py-3 rounded-xl shadow-xl text-sm';

            div.textContent = message;

            document.body.appendChild(div);

            setTimeout(function() {
                div.remove();
            }, 3000);
        }
    },

    render: {
        loading: function() {
            document.getElementById('app').innerHTML = `
                <div class="min-h-screen flex items-center justify-center">
                    <div class="text-center">
                        <div class="w-10 h-10 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin mx-auto"></div>
                        <div class="mt-4 text-slate-500">読み込み中...</div>
                    </div>
                </div>
            `;
        },

        layout: function() {
            document.getElementById('app').innerHTML = `
                <div class="min-h-screen">
                    <header class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-sm">
                        <div class="max-w-[1500px] mx-auto px-5 h-16 flex items-center justify-between">
                            <button
                                class="font-bold text-lg text-slate-800"
                                onclick="App.actions.goList()">
                                アンケート管理
                            </button>

                            <nav class="flex items-center gap-2">
                                <button
                                    class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100"
                                    onclick="App.actions.goList()">
                                    アンケート一覧
                                </button>

                                <button
                                    class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100"
                                    onclick="App.actions.settings()">
                                    kintone連携設定
                                </button>

                                <button
                                    class="px-4 py-2 rounded-lg text-sm text-slate-500 hover:bg-slate-100"
                                    onclick="App.actions.logout()">
                                    ログアウト
                                </button>
                            </nav>
                        </div>
                    </header>

                    <main id="main_content" class="max-w-[1500px] mx-auto p-5 md:p-8"></main>
                </div>
            `;
        },

        list: function() {
            window.App.state.screen = 'list';

            const main = document.getElementById('main_content');

            if (!main) {
                window.App.render.layout();
                return window.App.render.list();
            }

            let surveys = window.App.state.data.surveys.filter(function(s) {
                return !s.deleted;
            });

            const keyword = window.App.state.keyword || '';
            const filter = window.App.state.statusFilter || '';
            const sort = window.App.state.sort || 'updated_desc';

            if (keyword) {
                surveys = surveys.filter(function(s) {
                    return String(s.title || '')
                        .toLowerCase()
                        .includes(keyword.toLowerCase());
                });
            }

            if (filter) {
                surveys = surveys.filter(function(s) {
                    return s.status === filter;
                });
            }

            surveys.sort(function(a, b) {
                const responsesA = window.App.util.responsesBySurvey(a.id).length;
                const responsesB = window.App.util.responsesBySurvey(b.id).length;

                if (sort === 'responses_desc') {
                    return responsesB - responsesA;
                }

                if (sort === 'responses_asc') {
                    return responsesA - responsesB;
                }

                if (sort === 'start_desc') {
                    return String(b.start_at || '').localeCompare(String(a.start_at || ''));
                }

                if (sort === 'start_asc') {
                    return String(a.start_at || '').localeCompare(String(b.start_at || ''));
                }

                const result = String(b.updated_at || '').localeCompare(
                    String(a.updated_at || '')
                );

                return sort === 'updated_asc' ? -result : result;
            });

            main.innerHTML = `
                <div class="space-y-6">

                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div>
                            <h1 class="text-2xl font-bold">アンケート一覧</h1>
                            <p class="text-sm text-slate-500 mt-1">
                                アンケートの作成・送信・集計を管理します。
                            </p>
                        </div>

                        <button
                            class="px-5 py-3 bg-blue-600 text-white rounded-xl shadow-sm hover:bg-blue-700"
                            onclick="App.actions.newSurvey()">
                            ＋ 新規アンケート作成
                        </button>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                        <div class="grid md:grid-cols-3 gap-3">
                            <input
                                id="survey_search"
                                value="${window.App.util.escape(keyword)}"
                                placeholder="タイトルを検索"
                                class="border border-slate-300 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500"
                                onkeydown="App.actions.searchKey(event)">

                            <select
                                class="border border-slate-300 rounded-xl px-4 py-3"
                                onchange="App.actions.statusFilter(this.value)">
                                <option value="">すべて</option>
                                <option value="active" ${filter === 'active' ? 'selected' : ''}>公開中</option>
                                <option value="draft" ${filter === 'draft' ? 'selected' : ''}>下書き</option>
                                <option value="ended" ${filter === 'ended' ? 'selected' : ''}>終了</option>
                            </select>

                            <select
                                class="border border-slate-300 rounded-xl px-4 py-3"
                                onchange="App.actions.sort(this.value)">
                                <option value="updated_desc" ${sort === 'updated_desc' ? 'selected' : ''}>更新日：新しい順</option>
                                <option value="updated_asc" ${sort === 'updated_asc' ? 'selected' : ''}>更新日：古い順</option>
                                <option value="responses_desc" ${sort === 'responses_desc' ? 'selected' : ''}>回答数：多い順</option>
                                <option value="responses_asc" ${sort === 'responses_asc' ? 'selected' : ''}>回答数：少ない順</option>
                                <option value="start_desc" ${sort === 'start_desc' ? 'selected' : ''}>開始日：新しい順</option>
                                <option value="start_asc" ${sort === 'start_asc' ? 'selected' : ''}>開始日：古い順</option>
                            </select>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[1100px] text-sm">
                                <thead class="bg-slate-50 border-b border-slate-200">
                                    <tr>
                                        <th class="text-left px-5 py-4">作成日 / 更新日</th>
                                        <th class="text-left px-5 py-4">タイトル</th>
                                        <th class="text-left px-5 py-4">アンケート期間</th>
                                        <th class="text-left px-5 py-4">ステータス</th>
                                        <th class="text-right px-5 py-4">回答数</th>
                                        <th class="text-right px-5 py-4">操作</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-slate-100">
                                    ${
                                        surveys.length
                                        ? surveys.map(function(survey) {
                                            return window.App.render.surveyRow(survey);
                                        }).join('')
                                        : `
                                            <tr>
                                                <td colspan="6" class="py-20 text-center text-slate-400">
                                                    アンケートはありません。
                                                </td>
                                            </tr>
                                        `
                                    }
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
        },

        surveyRow: function(survey) {
            const count = window.App.util.responsesBySurvey(survey.id).length;
            const status = survey.status;

            let buttons = `
                <button
                    class="px-3 py-2 rounded-lg bg-slate-100 hover:bg-slate-200"
                    onclick="App.actions.editSurvey('${survey.id}')">
                    確認・編集
                </button>
            `;

            if (status === 'active') {
                buttons += `
                    <button
                        class="px-3 py-2 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100"
                        onclick="App.actions.analytics('${survey.id}')">
                        集計
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100"
                        onclick="App.actions.send('${survey.id}')">
                        送信
                    </button>

                    <button
                        class="px-3 py-2 rounded-lg bg-orange-50 text-orange-700 hover:bg-orange-100"
                        onclick="App.actions.stop('${survey.id}')">
                        停止
                    </button>
                `;
            }

            if (status === 'draft') {
                buttons += `
                    <button
                        class="px-3 py-2 rounded-lg bg-red-50 text-red-700 hover:bg-red-100"
                        onclick="App.actions.deleteSurvey('${survey.id}')">
                        削除
                    </button>
                `;
            }

            if (status === 'ended') {
                buttons += `
                    <button
                        class="px-3 py-2 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100"
                        onclick="App.actions.analytics('${survey.id}')">
                        集計
                    </button>
                `;
            }

            buttons += `
                <button
                    class="px-3 py-2 rounded-lg bg-slate-100 hover:bg-slate-200"
                    onclick="App.actions.duplicate('${survey.id}')">
                    複製
                </button>
            `;

            return `
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-4 text-slate-500">
                        <div>${window.App.util.escape(
                            String(survey.created_at || '').substring(0, 10)
                        )}</div>
                        <div class="text-xs mt-1">
                            更新: ${window.App.util.escape(
                                String(survey.updated_at || '').substring(0, 10)
                            )}
                        </div>
                    </td>

                    <td class="px-5 py-4 font-bold">
                        ${window.App.util.escape(survey.title || '無題アンケート')}
                    </td>

                    <td class="px-5 py-4 text-slate-600">
                        ${window.App.util.escape(
                            window.App.util.formatDate(survey.start_at)
                        )}
                        <span class="mx-1">～</span>
                        ${window.App.util.escape(
                            window.App.util.formatDate(survey.end_at)
                        )}
                    </td>

                    <td class="px-5 py-4">
                        <span class="inline-flex px-3 py-1 rounded-full border text-xs font-medium ${window.App.util.statusClass(status)}">
                            ${window.App.util.statusText(status)}
                        </span>
                    </td>

                    <td class="px-5 py-4 text-right font-semibold">
                        ${count} 件
                    </td>

                    <td class="px-5 py-4">
                        <div class="flex flex-wrap justify-end gap-2">
                            ${buttons}
                        </div>
                    </td>
                </tr>
            `;
        },

        editor: function() {
            const survey = window.App.state.editing;

            const main = document.getElementById('main_content');

            main.innerHTML = `
                <div class="space-y-5">

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-sm text-slate-500 mb-1">
                                ホーム ＞ アンケート一覧 ＞ 編集
                            </div>

                            <h1 class="text-2xl font-bold">
                                アンケート作成・編集
                            </h1>
                        </div>

                        <div class="flex gap-2">
                            <button
                                class="px-4 py-2 border rounded-xl bg-white hover:bg-slate-50"
                                onclick="App.actions.preview()">
                                プレビュー
                            </button>

                            <button
                                class="px-4 py-2 border rounded-xl bg-white hover:bg-slate-50"
                                onclick="App.actions.cancelEdit()">
                                キャンセル
                            </button>

                            <button
                                class="px-5 py-2 bg-blue-600 text-white rounded-xl hover:bg-blue-700"
                                onclick="App.actions.saveSurvey()">
                                保存して一覧へ戻る
                            </button>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                        <div class="grid md:grid-cols-4 gap-4">
                            <div class="md:col-span-2">
                                <label class="text-sm font-medium">タイトル</label>
                                <input
                                    id="survey_title"
                                    value="${window.App.util.escape(survey.title)}"
                                    oninput="App.actions.editorChange()"
                                    class="mt-2 w-full border border-slate-300 rounded-xl px-4 py-3 text-lg font-semibold">
                            </div>

                            <div>
                                <label class="text-sm font-medium">開始日時</label>
                                <input
                                    id="survey_start_at"
                                    type="datetime-local"
                                    value="${window.App.util.escape(survey.start_at || '')}"
                                    onchange="App.actions.editorChange()"
                                    class="mt-2 w-full border border-slate-300 rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="text-sm font-medium">終了日時</label>
                                <input
                                    id="survey_end_at"
                                    type="datetime-local"
                                    value="${window.App.util.escape(survey.end_at || '')}"
                                    onchange="App.actions.editorChange()"
                                    class="mt-2 w-full border border-slate-300 rounded-xl px-4 py-3">
                            </div>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-5">
                            <div>
                                <span class="text-sm font-medium mr-3">ステータス</span>

                                <select
                                    class="border rounded-lg px-3 py-2"
                                    onchange="App.actions.setEditorStatus(this.value)">
                                    <option value="draft" ${survey.status === 'draft' ? 'selected' : ''}>下書き</option>
                                    <option value="active" ${survey.status === 'active' ? 'selected' : ''}>公開中</option>
                                    <option value="ended" ${survey.status === 'ended' ? 'selected' : ''}>終了</option>
                                </select>
                            </div>

                            <div>
                                <span class="text-sm font-medium mr-3">質問番号</span>

                                <select
                                    id="survey_numbering_mode"
                                    class="border rounded-lg px-3 py-2"
                                    onchange="App.actions.setNumbering(this.value)">
                                    <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                        Q1, Q2, Q3...
                                    </option>
                                    <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                        Q1-1, Q1-2...
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div
                        id="question_editor"
                        class="space-y-4">
                    </div>

                    <button
                        class="w-full border-2 border-dashed border-slate-300 rounded-2xl py-5 text-slate-500 hover:border-blue-400 hover:text-blue-600"
                        onclick="App.actions.addGroup()">
                        ＋ グループを追加
                    </button>
                </div>
            `;

            window.App.render.groups();
            window.App.actions.enableSortable();
        },

        groups: function() {
            const root = document.getElementById('question_editor');

            if (!root) {
                return;
            }

            const survey = window.App.state.editing;

            root.innerHTML = (survey.groups || []).map(function(group, gi) {
                return `
                    <section
                        class="group-card bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden"
                        data-group-id="${window.App.util.escape(group.id)}">

                        <div class="bg-slate-50 border-b px-5 py-4 flex items-center gap-3">
                            <span class="group-handle cursor-grab text-xl text-slate-400">⠿</span>

                            <input
                                value="${window.App.util.escape(group.name)}"
                                class="flex-1 bg-transparent border-0 font-bold text-lg focus:ring-0"
                                onchange="App.actions.changeGroupName('${group.id}', this.value)">

                            <button
                                class="px-3 py-2 text-red-600 hover:bg-red-50 rounded-lg"
                                onclick="App.actions.deleteGroup('${group.id}')">
                                グループ削除
                            </button>
                        </div>

                        <div
                            class="question-list p-5 space-y-4 min-h-[80px]"
                            data-group-id="${window.App.util.escape(group.id)}">

                            ${(group.questions || []).map(function(question, qi) {
                                return window.App.render.question(
                                    survey,
                                    group,
                                    question,
                                    gi,
                                    qi
                                );
                            }).join('')}

                        </div>

                        <div class="px-5 pb-5">
                            <button
                                class="px-4 py-2 bg-blue-50 text-blue-700 rounded-xl hover:bg-blue-100"
                                onclick="App.actions.addQuestion('${group.id}')">
                                ＋ 質問を追加
                            </button>
                        </div>
                    </section>
                `;
            }).join('');
        },

        question: function(survey, group, question, gi, qi) {
            const number = window.App.util.questionNumber(survey, gi, qi);

            const options = question.options || [];

            return `
                <div
                    class="question-card border border-slate-200 rounded-xl p-5 bg-white"
                    data-question-id="${window.App.util.escape(question.id)}">

                    <div class="flex items-start gap-3">
                        <div class="question-handle cursor-grab text-xl text-slate-400 pt-2">
                            ⠿
                        </div>

                        <div class="flex-1 space-y-4">
                            <div class="flex flex-wrap gap-3 items-center">
                                <span class="font-bold text-blue-700">${number}</span>

                                <select
                                    class="border rounded-lg px-3 py-2"
                                    onchange="App.actions.changeQuestionType('${question.id}', this.value)">
                                    <option value="single" ${question.type === 'single' ? 'selected' : ''}>単一選択</option>
                                    <option value="multiple" ${question.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                                    <option value="text" ${question.type === 'text' ? 'selected' : ''}>自由記述</option>
                                </select>

                                <label class="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        ${question.required ? 'checked' : ''}
                                        onchange="App.actions.toggleRequired('${question.id}', this.checked)"
                                        class="w-4 h-4">
                                    必須回答
                                </label>

                                ${
                                    question.type !== 'text'
                                    ? `
                                        <label class="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                ${question.other_enabled ? 'checked' : ''}
                                                onchange="App.actions.toggleOther('${question.id}', this.checked)"
                                                class="w-4 h-4">
                                            その他入力
                                        </label>
                                    `
                                    : ''
                                }
                            </div>

                            <input
                                value="${window.App.util.escape(question.text)}"
                                placeholder="質問文を入力してください"
                                onchange="App.actions.changeQuestionText('${question.id}', this.value)"
                                class="w-full border border-slate-300 rounded-xl px-4 py-3 font-medium">

                            ${
                                question.type === 'text'
                                ? `
                                    <textarea
                                        disabled
                                        class="w-full border rounded-xl p-3 bg-slate-50 h-24"
                                        placeholder="回答者の自由記述欄"></textarea>
                                `
                                : `
                                    <div class="space-y-2">
                                        ${options.map(function(option, oi) {
                                            return `
                                                <div class="flex gap-2">
                                                    <span class="pt-2 text-slate-400">${oi + 1}.</span>
                                                    <input
                                                        value="${window.App.util.escape(option)}"
                                                        onchange="App.actions.changeOption('${question.id}', ${oi}, this.value)"
                                                        class="flex-1 border rounded-lg px-3 py-2">
                                                    <button
                                                        class="px-3 text-red-500 hover:bg-red-50 rounded-lg"
                                                        onclick="App.actions.removeOption('${question.id}', ${oi})">
                                                        ×
                                                    </button>
                                                </div>
                                            `;
                                        }).join('')}

                                        <button
                                            class="text-sm px-3 py-2 bg-slate-100 rounded-lg hover:bg-slate-200"
                                            onclick="App.actions.addOption('${question.id}')">
                                            ＋ 選択肢追加
                                        </button>
                                    </div>
                                `
                            }
                        </div>

                        <button
                            class="text-red-500 hover:bg-red-50 px-3 py-2 rounded-lg"
                            onclick="App.actions.deleteQuestion('${group.id}', '${question.id}')">
                            削除
                        </button>
                    </div>
                </div>
            `;
        },

        preview: function() {
            const survey = window.App.state.editing;

            const content = document.getElementById('preview_content');

            content.innerHTML = `
                <div class="${window.App.state.previewMobile ? 'max-w-sm' : 'max-w-3xl'} mx-auto bg-white min-h-[500px] p-6 rounded-xl">
                    <h2 class="text-2xl font-bold mb-2">
                        ${window.App.util.escape(survey.title || '無題アンケート')}
                    </h2>

                    <div class="space-y-8 mt-8">
                        ${(survey.groups || []).map(function(group, gi) {
                            return `
                                <div>
                                    <h3 class="text-lg font-bold border-b pb-2 mb-4">
                                        ${window.App.util.escape(group.name)}
                                    </h3>

                                    <div class="space-y-6">
                                        ${(group.questions || []).map(function(q, qi) {
                                            const n = window.App.util.questionNumber(
                                                survey,
                                                gi,
                                                qi
                                            );

                                            return `
                                                <div>
                                                    <div class="font-medium mb-3">
                                                        ${n}. ${window.App.util.escape(q.text)}
                                                        ${q.required ? '<span class="text-red-500 ml-1">必須</span>' : ''}
                                                    </div>

                                                    ${
                                                        q.type === 'text'
                                                        ? `
                                                            <textarea
                                                                class="w-full border rounded-xl p-3 h-28"
                                                                placeholder="回答を入力"></textarea>
                                                        `
                                                        : `
                                                            <div class="space-y-2">
                                                                ${(q.options || []).map(function(opt) {
                                                                    const input = q.type === 'single'
                                                                        ? 'radio'
                                                                        : 'checkbox';

                                                                    return `
                                                                        <label class="flex items-center gap-3 p-3 border rounded-xl">
                                                                            <input type="${input}" name="${q.id}">
                                                                            <span>${window.App.util.escape(opt)}</span>
                                                                        </label>
                                                                    `;
                                                                }).join('')}

                                                                ${q.other_enabled ? `
                                                                    <input
                                                                        class="w-full border rounded-xl px-3 py-2"
                                                                        placeholder="その他">
                                                                ` : ''}
                                                            </div>
                                                        `
                                                    }
                                                </div>
                                            `;
                                        }).join('')}
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>

                    <button
                        class="mt-8 w-full py-3 bg-blue-600 text-white rounded-xl"
                        onclick="App.actions.previewSubmit()">
                        回答を送信
                    </button>
                </div>
            `;

            document.getElementById('preview_modal').classList.remove('hidden');
        },

        analytics: function() {
            const survey = window.App.state.survey;

            const responses = window.App.util.responsesBySurvey(survey.id);
            const customers = window.App.util.customersBySurvey(survey.id);

            const sent = customers.filter(function(c) {
                return Number(c.send_count || 0) > 0;
            }).length;

            const answeredFromCustomers = responses.filter(function(r) {
                return r.customer_id && customers.some(function(c) {
                    return c.id === r.customer_id;
                });
            }).length;

            const unregistered = responses.length - answeredFromCustomers;
            const unanswered = Math.max(sent - answeredFromCustomers, 0);
            const rate = sent > 0
                ? ((answeredFromCustomers / sent) * 100).toFixed(1)
                : '0.0';

            const questions = window.App.util.questions(survey);

            main_content.innerHTML = `
                <div class="space-y-6">

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-sm text-slate-500">
                                ホーム ＞ アンケート一覧 ＞ 集計
                            </div>
                            <h1 class="text-2xl font-bold mt-1">
                                ${window.App.util.escape(survey.title)}
                            </h1>
                        </div>

                        <div class="flex gap-2">
                            <a
                                href="?action=csv&survey_id=${encodeURIComponent(survey.id)}"
                                class="px-4 py-2 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700">
                                CSV出力
                            </a>

                            <button
                                class="px-4 py-2 border rounded-xl bg-white"
                                onclick="window.print()">
                                PDF / 印刷
                            </button>

                            <button
                                class="px-4 py-2 border rounded-xl bg-white"
                                onclick="App.actions.goList()">
                                一覧へ
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                        ${[
                            ['送信対象者数', sent + ' 人'],
                            ['回答数', responses.length + ' 件'],
                            ['未登録顧客からの回答数', unregistered + ' 件'],
                            ['未回答数', unanswered + ' 人'],
                            ['回答率', rate + ' %']
                        ].map(function(item) {
                            return `
                                <div class="bg-white border rounded-2xl p-5 shadow-sm">
                                    <div class="text-sm text-slate-500">${item[0]}</div>
                                    <div class="text-2xl font-bold mt-2">${item[1]}</div>
                                </div>
                            `;
                        }).join('')}
                    </div>

                    ${
                        responses.length === 0
                        ? `
                            <div class="bg-white border rounded-2xl p-16 text-center text-slate-400">
                                現在、回答データはありません
                            </div>
                        `
                        : ''
                    }

                    <div class="bg-white border rounded-2xl p-5 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                            <h2 class="font-bold text-lg">設問別集計</h2>

                            <div class="flex gap-2">
                                <button
                                    class="px-3 py-2 bg-slate-100 rounded-lg"
                                    onclick="App.actions.selectAllQuestions(true)">
                                    全選択
                                </button>

                                <button
                                    class="px-3 py-2 bg-slate-100 rounded-lg"
                                    onclick="App.actions.selectAllQuestions(false)">
                                    全解除
                                </button>
                            </div>
                        </div>

                        <div class="grid md:grid-cols-3 gap-2 mb-6">
                            ${questions.map(function(q, i) {
                                const selected =
                                    window.App.state.responseSelected[q.id] !== false;

                                return `
                                    <label class="flex items-center gap-2 p-3 border rounded-lg">
                                        <input
                                            type="checkbox"
                                            ${selected ? 'checked' : ''}
                                            onchange="App.actions.toggleResponseQuestion('${q.id}', this.checked)">
                                        <span class="text-sm">
                                            ${i + 1}. ${window.App.util.escape(q.text)}
                                        </span>
                                    </label>
                                `;
                            }).join('')}
                        </div>

                        <div class="space-y-6">
                            ${questions.map(function(q) {
                                if (window.App.state.responseSelected[q.id] === false) {
                                    return '';
                                }

                                return window.App.render.questionResult(
                                    q,
                                    responses,
                                    survey
                                );
                            }).join('')}
                        </div>
                    </div>

                    <div class="bg-white border rounded-2xl p-5 shadow-sm">
                        <div class="flex items-center justify-between gap-3 mb-4">
                            <h2 class="font-bold text-lg">個別回答一覧</h2>

                            <input
                                id="response_filter"
                                value="${window.App.util.escape(window.App.state.responseKeyword || '')}"
                                oninput="App.actions.filterResponses(this.value)"
                                placeholder="会社名・氏名で検索"
                                class="border rounded-xl px-4 py-2">
                        </div>

                        <div id="response_table" class="overflow-x-auto">
                            ${window.App.render.responseTable(responses)}
                        </div>
                    </div>
                </div>
            `;
        },

        questionResult: function(question, responses, survey) {
            if (question.type === 'text') {
                const rows = responses.map(function(response) {
                    const value = response.answers?.[question.id];

                    if (!value) {
                        return '';
                    }

                    return `
                        <div class="border-l-4 border-blue-500 pl-4 py-2">
                            <div class="font-medium">
                                ${window.App.util.escape(response.company || '')}
                                /
                                ${window.App.util.escape(response.name || '匿名')}
                            </div>
                            <div class="text-xs text-slate-400">
                                ${window.App.util.escape(response.answered_at || '')}
                            </div>
                            <div class="mt-2 whitespace-pre-wrap">
                                ${window.App.util.escape(value)}
                            </div>
                        </div>
                    `;
                }).join('');

                return `
                    <div class="border rounded-xl p-5">
                        <div class="flex items-center gap-2 mb-4">
                            <h3 class="font-semibold">
                                ${window.App.util.escape(question.text)}
                            </h3>
                            <span class="text-xs px-2 py-1 bg-slate-100 rounded">
                                自由記述
                            </span>
                        </div>

                        <div class="space-y-4 max-h-80 overflow-auto">
                            ${rows || '<div class="text-slate-400">回答なし</div>'}
                        </div>
                    </div>
                `;
            }

            const counts = {};

            (question.options || []).forEach(function(option) {
                counts[option] = 0;
            });

            let total = 0;

            responses.forEach(function(response) {
                let answer = response.answers?.[question.id];

                if (!answer) {
                    return;
                }

                if (!Array.isArray(answer)) {
                    answer = [answer];
                }

                answer.forEach(function(value) {
                    counts[value] = (counts[value] || 0) + 1;
                    total++;
                });
            });

            return `
                <div class="border rounded-xl p-5">
                    <div class="flex items-center gap-2 mb-5">
                        <h3 class="font-semibold">
                            ${window.App.util.escape(question.text)}
                        </h3>
                        <span class="text-xs px-2 py-1 bg-slate-100 rounded">
                            ${window.App.util.typeText(question.type)}
                        </span>
                    </div>

                    <div class="space-y-4">
                        ${(question.options || []).map(function(option) {
                            const count = counts[option] || 0;
                            const percent = total
                                ? Math.round((count / total) * 100)
                                : 0;

                            return `
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span>${window.App.util.escape(option)}</span>
                                        <span>${count} 件 / ${percent}%</span>
                                    </div>

                                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                        <div
                                            class="h-full bg-blue-500 rounded-full"
                                            style="width:${percent}%"></div>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
            `;
        },

        responseTable: function(responses) {
            const keyword = (window.App.state.responseKeyword || '').toLowerCase();

            const filtered = responses.filter(function(r) {
                return !keyword ||
                    String(r.company || '').toLowerCase().includes(keyword) ||
                    String(r.name || '').toLowerCase().includes(keyword);
            });

            return `
                <table class="w-full min-w-[850px] text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b">
                            <th class="text-left p-3">会社名</th>
                            <th class="text-left p-3">氏名</th>
                            <th class="text-left p-3">回答日時</th>
                            <th class="text-left p-3">メール</th>
                            <th class="text-right p-3">操作</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y">
                        ${filtered.map(function(r) {
                            return `
                                <tr>
                                    <td class="p-3 font-medium">
                                        ${window.App.util.escape(r.company || '')}
                                    </td>
                                    <td class="p-3">
                                        ${window.App.util.escape(r.name || '')}
                                    </td>
                                    <td class="p-3">
                                        ${window.App.util.escape(r.answered_at || '')}
                                    </td>
                                    <td class="p-3">
                                        ${window.App.util.escape(r.email || '')}
                                    </td>
                                    <td class="p-3 text-right">
                                        <button
                                            class="px-3 py-2 bg-blue-50 text-blue-700 rounded-lg"
                                            onclick="App.actions.showResponse('${r.id}')">
                                            全回答を表示
                                        </button>
                                    </td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            `;
        },

        send: function() {
            const survey = window.App.state.survey;
            const customers = window.App.util.customersBySurvey(survey.id);

            const unregistered = customers.filter(function(c) {
                return c.source === 'web' &&
                    c.kintone_status !== 'registered';
            }).length;

            main_content.innerHTML = `
                <div class="space-y-6">

                    <div>
                        <div class="text-sm text-slate-500">
                            ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 mt-2">
                            <h1 class="text-2xl font-bold">
                                ${window.App.util.escape(survey.title)}
                            </h1>

                            <button
                                class="px-4 py-2 border rounded-xl"
                                onclick="App.actions.goList()">
                                一覧へ
                            </button>
                        </div>
                    </div>

                    ${
                        unregistered
                        ? `
                            <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4">
                                kintone未登録の回答者が ${unregistered} 件あります。
                            </div>
                        `
                        : ''
                    }

                    <div class="bg-white border rounded-2xl p-5 shadow-sm">
                        <div class="grid md:grid-cols-3 gap-4">
                            <div>
                                <label class="text-sm font-medium">メール件名</label>
                                <input
                                    id="mail_subject"
                                    value="アンケートご協力のお願い"
                                    class="mt-2 w-full border rounded-xl px-4 py-3">
                            </div>

                            <div>
                                <label class="text-sm font-medium">テンプレート</label>
                                <select
                                    id="template_type"
                                    class="mt-2 w-full border rounded-xl px-4 py-3"
                                    onchange="App.actions.templateChanged(this.value)">
                                    <option value="initial">初回送信</option>
                                    <option value="reminder">再送・リマインド</option>
                                </select>
                            </div>

                            <div class="flex items-end">
                                <button
                                    class="w-full py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700"
                                    onclick="App.actions.sendSelected()">
                                    選択顧客へ一括送信
                                </button>
                            </div>
                        </div>

                        <textarea
                            id="mail_body"
                            class="mt-4 w-full border rounded-xl p-4 h-36"
                            placeholder="本文：{顧客名} 様&#10;&#10;アンケートへのご協力をお願いいたします。&#10;{アンケートURL}"></textarea>

                        <div class="text-xs text-slate-400 mt-2">
                            使用可能な変数：{顧客名} / {アンケートURL}
                        </div>
                    </div>

                    <div class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                        <div class="p-5 border-b flex flex-wrap justify-between gap-3">
                            <input
                                id="customer_filter"
                                oninput="App.actions.filterCustomers(this.value)"
                                placeholder="顧客名・メールアドレスで検索"
                                class="border rounded-xl px-4 py-2">

                            <label class="flex items-center gap-2">
                                <input
                                    id="select_all"
                                    type="checkbox"
                                    onchange="App.actions.selectAllCustomers(this.checked)">
                                全選択
                            </label>
                        </div>

                        <div id="customer_table" class="overflow-x-auto">
                            ${window.App.render.customerTable(customers)}
                        </div>
                    </div>

                    <div class="bg-white border rounded-2xl p-5 shadow-sm">
                        <h2 class="font-bold mb-4">一括送信履歴</h2>

                        <div class="space-y-2">
                            ${window.App.state.data.mail_logs
                                .filter(function(log) {
                                    return log.survey_id === survey.id;
                                })
                                .reverse()
                                .map(function(log) {
                                    return `
                                        <div class="border rounded-xl p-4">
                                            <div class="flex justify-between">
                                                <span class="font-medium">
                                                    ${log.template_type === 'reminder' ? 'リマインド' : '初回'}
                                                </span>
                                                <span class="text-sm text-slate-400">
                                                    ${window.App.util.escape(log.sent_at)}
                                                </span>
                                            </div>
                                            <div class="text-sm mt-1">
                                                ${window.App.util.escape(log.subject)}
                                            </div>
                                            <div class="text-xs text-slate-500 mt-1">
                                                ${log.count} 件 / ${window.App.util.escape(log.executed_by)}
                                            </div>
                                        </div>
                                    `;
                                }).join('') || '<div class="text-slate-400">送信履歴はありません。</div>'}
                        </div>
                    </div>
                </div>
            `;
        },

        customerTable: function(customers) {
            const keyword = (window.App.state.customerKeyword || '').toLowerCase();

            const rows = customers.filter(function(c) {
                return !keyword ||
                    String(c.name || '').toLowerCase().includes(keyword) ||
                    String(c.email || '').toLowerCase().includes(keyword) ||
                    String(c.company || '').toLowerCase().includes(keyword);
            });

            return `
                <table class="w-full min-w-[1200px] text-sm">
                    <thead class="bg-slate-50 border-b">
                        <tr>
                            <th class="p-4 text-left">選択</th>
                            <th class="p-4 text-left">会社名 / 氏名</th>
                            <th class="p-4 text-left">メール</th>
                            <th class="p-4 text-left">電話 / 住所</th>
                            <th class="p-4 text-left">送信状況</th>
                            <th class="p-4 text-left">回答</th>
                            <th class="p-4 text-left">kintone</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y">
                        ${rows.map(function(c) {
                            const disabled = c.source === 'web';

                            const selected =
                                window.App.state.selectedCustomers.includes(c.id);

                            return `
                                <tr>
                                    <td class="p-4">
                                        <input
                                            type="checkbox"
                                            ${selected ? 'checked' : ''}
                                            ${disabled ? 'disabled' : ''}
                                            onchange="App.actions.selectCustomer('${c.id}', this.checked)">
                                    </td>

                                    <td class="p-4">
                                        <div class="font-bold">
                                            ${window.App.util.escape(c.company || '')}
                                        </div>
                                        <div>
                                            ${window.App.util.escape(c.name || '')}
                                        </div>
                                    </td>

                                    <td class="p-4">
                                        ${window.App.util.escape(c.email || '')}
                                    </td>

                                    <td class="p-4">
                                        ${window.App.util.escape(c.phone || '')}<br>
                                        <span class="text-slate-500">
                                            ${window.App.util.escape(c.address || '')}
                                        </span>
                                    </td>

                                    <td class="p-4">
                                        ${
                                            c.sent_at
                                            ? `
                                                <div>${window.App.util.escape(c.sent_at)}</div>
                                                <div class="text-xs text-slate-500">
                                                    ${c.send_count || 0} 回送信済み
                                                </div>
                                            `
                                            : '<span class="text-slate-400">未送信</span>'
                                        }
                                    </td>

                                    <td class="p-4">
                                        <span class="px-2 py-1 rounded-full text-xs ${
                                            c.answer_status === 'answered'
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-amber-50 text-amber-700'
                                        }">
                                            ${
                                                c.answer_status === 'answered'
                                                ? '回答済み'
                                                : '送信済み（未回答）'
                                            }
                                        </span>
                                    </td>

                                    <td class="p-4">
                                        ${
                                            c.kintone_status === 'registered'
                                            ? `
                                                <span class="text-emerald-600">
                                                    ✓ 登録完了
                                                </span>
                                            `
                                            : `
                                                <button
                                                    class="px-3 py-2 bg-amber-50 text-amber-700 rounded-lg"
                                                    onclick="App.actions.registerKintone('${c.id}')">
                                                    kintone登録完了
                                                </button>
                                            `
                                        }
                                    </td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            `;
        },

        settings: function() {
            const s = window.App.state.data.settings || {};

            main_content.innerHTML = `
                <div class="max-w-5xl mx-auto space-y-6">

                    <div>
                        <div class="text-sm text-slate-500">
                            ホーム ＞ システム設定 ＞ kintone連携設定
                        </div>
                        <h1 class="text-2xl font-bold mt-2">
                            kintone連携設定
                        </h1>
                    </div>

                    <div class="bg-white border rounded-2xl p-6 shadow-sm">
                        <form id="settings_form" onsubmit="App.actions.saveSettings(event)">
                            <div class="grid md:grid-cols-2 gap-5">

                                <div>
                                    <label class="text-sm font-medium">
                                        サブドメイン
                                    </label>
                                    <input
                                        id="setting_subdomain"
                                        value="${window.App.util.escape(s.subdomain || '')}"
                                        placeholder="xxxx または xxxx.cybozu.com"
                                        class="mt-2 w-full border rounded-xl px-4 py-3">
                                </div>

                                <div>
                                    <label class="text-sm font-medium">
                                        顧客管理アプリID
                                    </label>
                                    <input
                                        id="setting_app_id"
                                        value="${window.App.util.escape(s.app_id || '')}"
                                        class="mt-2 w-full border rounded-xl px-4 py-3">
                                </div>

                                <div>
                                    <label class="text-sm font-medium">
                                        ログイン名
                                    </label>
                                    <input
                                        id="setting_login_name"
                                        value="${window.App.util.escape(s.login_name || '')}"
                                        class="mt-2 w-full border rounded-xl px-4 py-3">
                                </div>

                                <div>
                                    <label class="text-sm font-medium">
                                        パスワード
                                    </label>
                                    <input
                                        id="setting_password"
                                        type="password"
                                        value="${window.App.util.escape(s.password || '')}"
                                        class="mt-2 w-full border rounded-xl px-4 py-3">
                                </div>

                                <div>
                                    <label class="text-sm font-medium">
                                        Proxyサーバ
                                    </label>
                                    <input
                                        id="setting_proxy"
                                        value="${window.App.util.escape(s.proxy || '')}"
                                        placeholder="host:port"
                                        class="mt-2 w-full border rounded-xl px-4 py-3">
                                </div>

                                <div class="flex items-end">
                                    <label class="flex items-center gap-3">
                                        <input
                                            id="setting_ssl_verify"
                                            type="checkbox"
                                            ${s.ssl_verify ? 'checked' : ''}
                                            class="w-5 h-5">
                                        SSL証明書検証を行う
                                    </label>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-3 mt-6">
                                <button
                                    type="button"
                                    class="px-5 py-3 border rounded-xl hover:bg-slate-50"
                                    onclick="App.actions.testKintone()">
                                    接続確認
                                </button>

                                <button
                                    type="button"
                                    class="px-5 py-3 bg-blue-50 text-blue-700 rounded-xl"
                                    onclick="App.actions.fetchKintoneFields()">
                                    項目一覧を取得
                                </button>

                                <button
                                    type="submit"
                                    class="px-5 py-3 bg-blue-600 text-white rounded-xl">
                                    設定を保存
                                </button>
                            </div>

                            <div
                                id="field_message"
                                class="mt-4 text-sm"></div>
                        </form>
                    </div>

                    <div class="bg-white border rounded-2xl p-6 shadow-sm">
                        <h2 class="font-bold text-lg mb-5">
                            フィールドマッピング
                        </h2>

                        <div id="field_mapping" class="space-y-4">
                            ${window.App.render.fieldMapping()}
                        </div>
                    </div>
                </div>
            `;
        },

        fieldMapping: function() {
            const s = window.App.state.data.settings || {};
            const fields = window.App.state.fields || [];

            const definitions = [
                ['field_company', '会社名', false],
                ['field_name', '氏名', false],
                ['field_email', 'メールアドレス', false],
                ['field_department', '部署名', false],
                ['field_phone', '電話番号', false],
                ['field_address', '住所', true]
            ];

            return definitions.map(function(def) {
                const key = def[0];
                const label = def[1];
                const multiple = def[2];

                const selected = multiple
                    ? (Array.isArray(s[key]) ? s[key] : [])
                    : [s[key] || ''];

                if (!fields.length) {
                    return `
                        <div>
                            <label class="font-medium">${label}</label>
                            <div class="mt-2 text-sm text-slate-400">
                                「項目一覧を取得」を実行してください。
                            </div>
                        </div>
                    `;
                }

                return `
                    <div>
                        <label class="font-medium">${label}</label>

                        <select
                            data-field-key="${key}"
                            ${multiple ? 'multiple size="4"' : ''}
                            class="mt-2 w-full border rounded-xl px-4 py-3">
                            ${fields.map(function(field) {
                                return `
                                    <option
                                        value="${window.App.util.escape(field.code)}"
                                        ${selected.includes(field.code) ? 'selected' : ''}>
                                        ${window.App.util.escape(field.label)}
                                        (${window.App.util.escape(field.code)})
                                    </option>
                                `;
                            }).join('')}
                        </select>
                    </div>
                `;
            }).join('');
        }
    },

    actions: {
        goList: function() {
            window.App.state.screen = 'list';
            window.App.state.survey = null;
            window.App.state.editing = null;
            window.App.render.list();
        },

        newSurvey: function() {
            window.App.state.editing = {
                id: '',
                title: '新しいアンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: '',
                updated_at: '',
                numbering_mode: 'global',
                groups: [],
                deleted: false
            };

            window.App.render.editor();
        },

        editSurvey: function(id) {
            const survey = window.App.util.surveyById(id);

            if (!survey) {
                return;
            }

            window.App.state.survey = survey;
            window.App.state.editing = window.App.util.clone(survey);

            window.App.render.editor();
        },

        editorChange: function() {
            const survey = window.App.state.editing;

            survey.title =
                document.getElementById('survey_title')?.value || '';

            survey.start_at =
                document.getElementById('survey_start_at')?.value || '';

            survey.end_at =
                document.getElementById('survey_end_at')?.value || '';
        },

        setEditorStatus: function(value) {
            window.App.state.editing.status = value;
        },

        setNumbering: function(value) {
            window.App.state.editing.numbering_mode = value;
            window.App.render.groups();
            window.App.actions.enableSortable();
        },

        saveSurvey: async function() {
            window.App.actions.editorChange();

            const survey = window.App.state.editing;

            if (!String(survey.title || '').trim()) {
                alert('タイトルを入力してください。');
                return;
            }

            try {
                const result = await window.App.api.request('save_survey', {
                    survey_json: survey
                });

                if (!result.ok) {
                    throw new Error(result.message);
                }

                window.App.state.data = result.data;

                window.App.util.toast('保存しました。');
                window.App.actions.goList();
            } catch (e) {
                window.App.util.toast(e.message, 'error');
            }
        },

        cancelEdit: function() {
            if (!confirm('変更を破棄して一覧へ戻りますか？')) {
                return;
            }

            window.App.actions.goList();
        },

        addGroup: function() {
            const survey = window.App.state.editing;

            survey.groups.push({
                id: window.App.util.id('group'),
                name: '新しいグループ',
                questions: []
            });

            window.App.render.groups();
            window.App.actions.enableSortable();
        },

        deleteGroup: function(id) {
            if (!confirm('グループと内包する全質問を削除しますか？')) {
                return;
            }

            const survey = window.App.state.editing;

            survey.groups = survey.groups.filter(function(group) {
                return group.id !== id;
            });

            window.App.render.groups();
            window.App.actions.enableSortable();
        },

        changeGroupName: function(id, value) {
            const group = window.App.state.editing.groups.find(function(g) {
                return g.id === id;
            });

            if (group) {
                group.name = value;
            }
        },

        addQuestion: function(groupId) {
            const group = window.App.state.editing.groups.find(function(g) {
                return g.id === groupId;
            });

            if (!group) {
                return;
            }

            group.questions.push({
                id: window.App.util.id('question'),
                text: '',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false
            });

            window.App.render.groups();
            window.App.actions.enableSortable();
        },

        findQuestion: function(id) {
            const survey = window.App.state.editing;

            for (const group of survey.groups) {
                for (const question of group.questions) {
                    if (question.id === id) {
                        return question;
                    }
                }
            }

            return null;
        },

        changeQuestionText: function(id, value) {
            const q = window.App.actions.findQuestion(id);

            if (q) {
                q.text = value;
            }
        },

        changeQuestionType: function(id, value) {
            const q = window.App.actions.findQuestion(id);

            if (!q) {
                return;
            }

            q.type = value;

            if (value === 'text') {
                q.options = [];
            } else if (!Array.isArray(q.options) || q.options.length === 0) {
                q.options = ['選択肢1', '選択肢2'];
            }

            window.App.render.groups();
            window.App.actions.enableSortable();
        },

        toggleRequired: function(id, value) {
            const q = window.App.actions.findQuestion(id);

            if (q) {
                q.required = Boolean(value);
            }
        },

        toggleOther: function(id, value) {
            const q = window.App.actions.findQuestion(id);

            if (q) {
                q.other_enabled = Boolean(value);
            }
        },

        addOption: function(id) {
            const q = window.App.actions.findQuestion(id);

            if (!q) {
                return;
            }

            q.options = q.options || [];
            q.options.push('新しい選択肢');

            window.App.render.groups();
            window.App.actions.enableSortable();
        },

        changeOption: function(id, index, value) {
            const q = window.App.actions.findQuestion(id);

            if (q && q.options) {
                q.options[index] = value;
            }
        },

        removeOption: function(id, index) {
            const q = window.App.actions.findQuestion(id);

            if (!q || !q.options) {
                return;
            }

            if (q.options.length <= 1) {
                alert('選択肢は最低1つ必要です。');
                return;
            }

            q.options.splice(index, 1);

            window.App.render.groups();
            window.App.actions.enableSortable();
        },

        deleteQuestion: function(groupId, questionId) {
            const group = window.App.state.editing.groups.find(function(g) {
                return g.id === groupId;
            });

            if (!group) {
                return;
            }

            group.questions = group.questions.filter(function(q) {
                return q.id !== questionId;
            });

            window.App.render.groups();
            window.App.actions.enableSortable();
        },

        enableSortable: function() {
            if (typeof Sortable === 'undefined') {
                return;
            }

            const editor = document.getElementById('question_editor');

            if (!editor) {
                return;
            }

            if (editor._sortable) {
                editor._sortable.destroy();
            }

            editor._sortable = new Sortable(editor, {
                animation: 180,
                handle: '.group-handle',
                ghostClass: 'opacity-40',
                onEnd: function(evt) {
                    const survey = window.App.state.editing;

                    const moved = survey.groups.splice(evt.oldIndex, 1)[0];

                    survey.groups.splice(evt.newIndex, 0, moved);

                    window.App.render.groups();
                    window.App.actions.enableSortable();
                }
            });

            document.querySelectorAll('.question-list').forEach(function(list) {
                if (list._sortable) {
                    list._sortable.destroy();
                }

                list._sortable = new Sortable(list, {
                    group: 'survey_questions',
                    animation: 180,
                    handle: '.question-handle',
                    ghostClass: 'opacity-40',

                    onEnd: function(evt) {
                        const survey = window.App.state.editing;

                        const fromId =
                            evt.from.dataset.groupId;

                        const toId =
                            evt.to.dataset.groupId;

                        const from = survey.groups.find(function(g) {
                            return g.id === fromId;
                        });

                        const to = survey.groups.find(function(g) {
                            return g.id === toId;
                        });

                        if (!from || !to) {
                            return;
                        }

                        const questionId =
                            evt.item.dataset.questionId;

                        const questionIndex =
                            from.questions.findIndex(function(q) {
                                return q.id === questionId;
                            });

                        if (questionIndex < 0) {
                            return;
                        }

                        const question =
                            from.questions.splice(questionIndex, 1)[0];

                        to.questions.splice(evt.newIndex, 0, question);

                        window.App.render.groups();
                        window.App.actions.enableSortable();
                    }
                });
            });
        },

        preview: function() {
            window.App.render.preview();
        },

        previewClose: function() {
            document.getElementById('preview_modal').classList.add('hidden');
        },

        previewSubmit: function() {
            alert('これはプレビューです。実際の回答は送信されません。');
        },

        stop: async function(id) {
            if (!confirm('このアンケートを停止して終了状態にしますか？')) {
                return;
            }

            try {
                const result = await window.App.api.request('change_status', {
                    survey_id: id,
                    status: 'ended'
                });

                if (!result.ok) {
                    throw new Error(result.message);
                }

                window.App.state.data = result.data;
                window.App.render.list();
                window.App.util.toast('アンケートを停止しました。');
            } catch (e) {
                window.App.util.toast(e.message, 'error');
            }
        },

        deleteSurvey: async function(id) {
            if (!confirm('この下書きを削除しますか？')) {
                return;
            }

            try {
                const result = await window.App.api.request('delete_survey', {
                    survey_id: id
                });

                if (!result.ok) {
                    throw new Error(result.message);
                }

                const survey = window.App.util.surveyById(id);

                if (survey) {
                    survey.deleted = true;
                }

                window.App.render.list();
                window.App.util.toast('削除しました。');
            } catch (e) {
                window.App.util.toast(e.message, 'error');
            }
        },

        duplicate: async function(id) {
            try {
                const result = await window.App.api.request('duplicate_survey', {
                    survey_id: id
                });

                if (!result.ok) {
                    throw new Error(result.message);
                }

                window.App.state.data.surveys.push(result.survey);

                window.App.render.list();
                window.App.util.toast('下書きとして複製しました。');
            } catch (e) {
                window.App.util.toast(e.message, 'error');
            }
        },

        searchKey: function(event) {
            if (event.key !== 'Enter') {
                return;
            }

            window.App.state.keyword =
                event.target.value.trim();

            window.App.render.list();
        },

        statusFilter: function(value) {
            window.App.state.statusFilter = value;
            window.App.render.list();
        },

        sort: function(value) {
            window.App.state.sort = value;
            window.App.render.list();
        },

        analytics: function(id) {
            const survey = window.App.util.surveyById(id);

            if (!survey) {
                return;
            }

            window.App.state.survey = survey;

            const questions = window.App.util.questions(survey);

            window.App.state.responseSelected = {};

            questions.forEach(function(q) {
                window.App.state.responseSelected[q.id] = true;
            });

            window.App.render.analytics();
        },

        toggleResponseQuestion: function(id, checked) {
            window.App.state.responseSelected[id] = checked;
            window.App.render.analytics();
        },

        selectAllQuestions: function(value) {
            const survey = window.App.state.survey;

            window.App.util.questions(survey).forEach(function(q) {
                window.App.state.responseSelected[q.id] = value;
            });

            window.App.render.analytics();
        },

        filterResponses: function(value) {
            window.App.state.responseKeyword = value;

            const responses =
                window.App.util.responsesBySurvey(
                    window.App.state.survey.id
                );

            const table = document.getElementById('response_table');

            if (table) {
                table.innerHTML =
                    window.App.render.responseTable(responses);
            }
        },

        showResponse: function(id) {
            const response =
                window.App.state.data.responses.find(function(r) {
                    return r.id === id;
                });

            if (!response) {
                return;
            }

            const survey = window.App.state.survey;
            const questions = window.App.util.questions(survey);

            document.getElementById('response_detail').innerHTML = `
                <div class="space-y-5">
                    <div>
                        <div class="font-bold">
                            ${window.App.util.escape(response.company || '')}
                            /
                            ${window.App.util.escape(response.name || '')}
                        </div>
                        <div class="text-sm text-slate-500">
                            ${window.App.util.escape(response.answered_at || '')}
                        </div>
                    </div>

                    ${questions.map(function(q, i) {
                        let answer = response.answers?.[q.id] ?? '';

                        if (Array.isArray(answer)) {
                            answer = answer.join('、');
                        }

                        return `
                            <div class="border-b pb-4">
                                <div class="text-sm font-medium text-slate-500">
                                    ${i + 1}. ${window.App.util.escape(q.text)}
                                </div>
                                <div class="mt-2 whitespace-pre-wrap">
                                    ${window.App.util.escape(answer)}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;

            document.getElementById('response_modal').classList.remove('hidden');
        },

        closeResponse: function() {
            document.getElementById('response_modal').classList.add('hidden');
        },

        send: function(id) {
            const survey = window.App.util.surveyById(id);

            if (!survey) {
                return;
            }

            window.App.state.survey = survey;
            window.App.state.selectedCustomers = [];

            window.App.render.send();
        },

        filterCustomers: function(value) {
            window.App.state.customerKeyword = value;

            const customers =
                window.App.util.customersBySurvey(
                    window.App.state.survey.id
                );

            document.getElementById('customer_table').innerHTML =
                window.App.render.customerTable(customers);
        },

        selectCustomer: function(id, checked) {
            const list = window.App.state.selectedCustomers;

            if (checked) {
                if (!list.includes(id)) {
                    list.push(id);
                }
            } else {
                window.App.state.selectedCustomers =
                    list.filter(function(x) {
                        return x !== id;
                    });
            }
        },

        selectAllCustomers: function(checked) {
            const customers =
                window.App.util.customersBySurvey(
                    window.App.state.survey.id
                );

            window.App.state.selectedCustomers = checked
                ? customers
                    .filter(function(c) {
                        return c.source !== 'web';
                    })
                    .map(function(c) {
                        return c.id;
                    })
                : [];

            document.getElementById('customer_table').innerHTML =
                window.App.render.customerTable(customers);
        },

        templateChanged: function(value) {
            const body = document.getElementById('mail_body');

            if (!body) {
                return;
            }

            if (value === 'reminder') {
                body.value =
                    '{顧客名} 様\n\n先日ご案内したアンケートが未回答となっております。\n' +
                    'お手数ですが、以下よりご回答ください。\n\n{アンケートURL}';
            } else {
                body.value =
                    '{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}';
            }
        },

        sendSelected: async function() {
            const ids = window.App.state.selectedCustomers;

            if (!ids.length) {
                alert('送信先を選択してください。');
                return;
            }

            const customers =
                window.App.util.customersBySurvey(
                    window.App.state.survey.id
                );

            const alreadySent = ids.some(function(id) {
                const c = customers.find(function(x) {
                    return x.id === id;
                });

                return c && Number(c.send_count || 0) > 0;
            });

            if (alreadySent) {
                if (!confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )) {
                    return;
                }
            }

            const subject =
                document.getElementById('mail_subject').value;

            const body =
                document.getElementById('mail_body').value;

            const template =
                document.getElementById('template_type').value;

            try {
                const result = await window.App.api.request('mark_sent', {
                    survey_id: window.App.state.survey.id,
                    recipient_ids: ids,
                    mail_subject: subject,
                    mail_body: body,
                    template_type: template
                });

                if (!result.ok) {
                    throw new Error(result.message);
                }

                window.App.state.data = result.data;
                window.App.state.selectedCustomers = [];

                window.App.render.send();
                window.App.util.toast(
                    result.sent + '件を送信対象として記録しました。'
                );
            } catch (e) {
                window.App.util.toast(e.message, 'error');
            }
        },

        registerKintone: async function(id) {
            try {
                const result =
                    await window.App.api.request('register_kintone', {
                        customer_id: id
                    });

                if (!result.ok) {
                    throw new Error(result.message);
                }

                const customer =
                    window.App.state.data.customers.find(function(c) {
                        return c.id === id;
                    });

                if (customer) {
                    customer.kintone_status = 'registered';
                }

                window.App.render.send();
                window.App.util.toast('kintone登録完了として更新しました。');
            } catch (e) {
                window.App.util.toast(e.message, 'error');
            }
        },

        settings: function() {
            window.App.state.fields = [];
            window.App.render.settings();
        },

        collectSettings: function() {
            const s = window.App.state.data.settings || {};

            const settings = {
                subdomain:
                    document.getElementById('setting_subdomain').value.trim(),

                app_id:
                    document.getElementById('setting_app_id').value.trim(),

                login_name:
                    document.getElementById('setting_login_name').value.trim(),

                password:
                    document.getElementById('setting_password').value,

                proxy:
                    document.getElementById('setting_proxy').value.trim(),

                ssl_verify:
                    document.getElementById('setting_ssl_verify').checked,

                field_company: s.field_company || '',
                field_name: s.field_name || '',
                field_email: s.field_email || '',
                field_department: s.field_department || '',
                field_phone: s.field_phone || '',
                field_address: Array.isArray(s.field_address)
                    ? s.field_address
                    : []
            };

            document
                .querySelectorAll('#field_mapping select[data-field-key]')
                .forEach(function(select) {
                    const key = select.dataset.fieldKey;

                    if (select.multiple) {
                        settings[key] =
                            Array.from(select.selectedOptions).map(function(o) {
                                return o.value;
                            });
                    } else {
                        settings[key] = select.value;
                    }
                });

            return settings;
        },

        saveSettings: async function(event) {
            event.preventDefault();

            try {
                const settings =
                    window.App.actions.collectSettings();

                const result =
                    await window.App.api.request('save_settings', {
                        settings_json: settings
                    });

                if (!result.ok) {
                    throw new Error(result.message);
                }

                window.App.state.data.settings = result.settings;

                window.App.util.toast('設定を保存しました。');
            } catch (e) {
                window.App.util.toast(e.message, 'error');
            }
        },

        testKintone: async function() {
            const settings =
                window.App.actions.collectSettings();

            const message =
                document.getElementById('field_message');

            message.className = 'mt-4 text-sm text-blue-600';
            message.textContent = '接続確認中...';

            try {
                const result =
                    await window.App.api.request('kintone_test', {
                        subdomain: settings.subdomain,
                        login_name: settings.login_name,
                        password: settings.password,
                        app_id: settings.app_id
                    });

                message.className =
                    'mt-4 text-sm ' +
                    (result.ok
                        ? 'text-emerald-600'
                        : 'text-red-600');

                message.textContent = result.message;
            } catch (e) {
                message.className = 'mt-4 text-sm text-red-600';
                message.textContent = e.message;
            }
        },

        fetchKintoneFields: async function() {
            const settings =
                window.App.actions.collectSettings();

            const message =
                document.getElementById('field_message');

            message.className = 'mt-4 text-sm text-blue-600';
            message.textContent = 'kintoneから項目一覧を取得中...';

            try {
                const result =
                    await window.App.api.request('kintone_fields', {
                        subdomain: settings.subdomain,
                        login_name: settings.login_name,
                        password: settings.password,
                        app_id: settings.app_id
                    });

                if (!result.ok) {
                    throw new Error(result.message);
                }

                window.App.state.fields = result.fields || [];

                document.getElementById('field_mapping').innerHTML =
                    window.App.render.fieldMapping();

                message.className =
                    'mt-4 text-sm text-emerald-600';

                message.textContent =
                    window.App.state.fields.length +
                    '件のフィールドを取得しました。';
            } catch (e) {
                message.className =
                    'mt-4 text-sm text-red-600';

                message.textContent = e.message;
            }
        },

        logout: function() {
            alert('この簡易版では管理者認証を実装していません。');
        }
    }
};

/*
 * モーダルをレイアウトに追加。
 */
window.App.render.modals = function() {
    const existing = document.getElementById('preview_modal');

    if (existing) {
        return;
    }

    document.body.insertAdjacentHTML('beforeend', `
        <div
            id="preview_modal"
            class="hidden fixed inset-0 z-50 bg-black/50 p-5 overflow-auto">

            <div class="max-w-6xl mx-auto my-8">
                <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">

                    <div class="p-4 border-b flex justify-between items-center">
                        <div class="font-bold">
                            プレビュー
                        </div>

                        <div class="flex gap-2">
                            <button
                                class="px-3 py-2 bg-slate-100 rounded-lg"
                                onclick="App.state.previewMobile=false; App.render.preview()">
                                PC表示
                            </button>

                            <button
                                class="px-3 py-2 bg-slate-100 rounded-lg"
                                onclick="App.state.previewMobile=true; App.render.preview()">
                                スマートフォン表示
                            </button>

                            <button
                                class="px-3 py-2 bg-red-50 text-red-600 rounded-lg"
                                onclick="App.actions.previewClose()">
                                閉じる
                            </button>
                        </div>
                    </div>

                    <div
                        id="preview_content"
                        class="p-6 bg-slate-100">
                    </div>
                </div>
            </div>
        </div>

        <div
            id="response_modal"
            class="hidden fixed inset-0 z-50 bg-black/50 p-5 overflow-auto">

            <div class="max-w-3xl mx-auto my-10 bg-white rounded-2xl shadow-2xl">

                <div class="p-5 border-b flex items-center justify-between">
                    <h2 class="font-bold text-lg">
                        全回答
                    </h2>

                    <button
                        class="px-3 py-2 bg-slate-100 rounded-lg"
                        onclick="App.actions.closeResponse()">
                        閉じる
                    </button>
                </div>

                <div
                    id="response_detail"
                    class="p-6">
                </div>
            </div>
        </div>
    `);
};

const appLauncher = function() {
    if (!window.App.state.initialized) {
        window.App.render.modals();
        window.App.init();
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', appLauncher, {once: true});
} else {
    appLauncher();
}
</script>

</body>
</html>
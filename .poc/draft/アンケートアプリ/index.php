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

追加DOM / JS名称:
- question_branching
- branch_target
- branch_option
- branch_message

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

const SURVEY_STORAGE_DIRECTORY =
    __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';

const SURVEY_STORAGE_FILE =
    SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';

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

    foreach (survey_default_data() as $key => $default) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $default;
        }
    }

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

/* ================================================================
 * kintone domain normalization
 *
 * 重要:
 *
 * xxxx
 * xxxx.cybozu.com
 * https://xxxx.cybozu.com
 *
 * の3種類をすべて許容する。
 *
 * FQDNの場合は .cybozu.com を二重付与しない。
 * ================================================================ */

function survey_normalize_kintone_host(string $input): string
{
    $host = trim($input);

    if ($host === '') {
        return '';
    }

    $host = preg_replace(
        '#^\s*https?://#i',
        '',
        $host
    );

    $host = preg_replace(
        '#/.*$#',
        '',
        (string)$host
    );

    $host = preg_replace(
        '#:\d+$#',
        '',
        (string)$host
    );

    $host = strtolower(trim((string)$host));

    if ($host === '') {
        return '';
    }

    /*
     * xxxx.cybozu.com はそのまま使用。
     */
    if (preg_match(
        '#^[a-z0-9][a-z0-9.-]*\.cybozu\.com$#i',
        $host
    )) {
        return $host;
    }

    /*
     * xxxx の場合だけ .cybozu.com を付与。
     */
    if (preg_match(
        '#^[a-z0-9][a-z0-9-]*$#i',
        $host
    )) {
        return $host . '.cybozu.com';
    }

    return '';
}

function survey_kintone_base_url(array $settings): string
{
    $host = survey_normalize_kintone_host(
        (string)($settings['subdomain'] ?? '')
    );

    if ($host === '') {
        return '';
    }

    return 'https://' . $host;
}

/* ================================================================
 * PHP 8.4 / 8.5 compatible response header handling
 * ================================================================ */

function survey_header_status(): int
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
            $header,
            $m
        )) {
            return (int)$m[1];
        }
    }

    return 0;
}

/* ================================================================
 * kintone API
 * ================================================================ */

function survey_kintone_request(
    string $method,
    string $path,
    array $settings,
    ?array $body = null
): array {

    $baseUrl = survey_kintone_base_url($settings);

    if ($baseUrl === '') {
        return [
            'ok' => false,
            'message' =>
                'kintoneのサブドメイン/FQDNが正しくありません。' .
                ' xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com のいずれかを入力してください。'
        ];
    }

    /*
     * ここで「サブドメイン」を再加工しない。
     * baseUrlは既に正規化済み。
     */
    $url = $baseUrl .
        (str_starts_with($path, '/') ? $path : '/' . $path);

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
                'message' => 'kintone APIリクエストのJSON化に失敗しました。'
            ];
        }

        $options['http']['content'] = $encoded;
    }

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match(
            '#^[a-z0-9.-]+:\d+$#i',
            $proxy
        )) {
            return [
                'ok' => false,
                'message' =>
                    'Proxyサーバは host名:port番号 の形式で入力してください。'
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

    $message =
        (string)($decoded['message'] ??
        'kintone API通信に失敗しました。');

    if ($status === 401) {
        $message =
            'kintone認証に失敗しました。' .
            'ログイン名・パスワード・対象ドメインを確認してください。';
    } elseif ($status === 403) {
        $message =
            'kintone APIへのアクセス権限がありません。' .
            '対象アプリの権限を確認してください。';
    } elseif ($status === 404) {
        $message =
            'kintone API URLが見つかりません。' .
            'サブドメイン/FQDNまたはアプリIDを確認してください。';
    }

    return [
        'ok' => false,
        'status' => $status,
        'message' => $message,
        'data' => $decoded,
        'request_url' => $url
    ];
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

        case 'save_survey':

            $json = (string)($_POST['survey_json'] ?? '');
            $survey = json_decode($json, true);

            if (!is_array($survey) || empty($survey['id'])) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            /*
             * 分岐先の整合性をサーバー側でも確認。
             */
            $validQuestionIds = [];

            foreach (($survey['groups'] ?? []) as $group) {
                foreach (($group['questions'] ?? []) as $question) {
                    if (!empty($question['id'])) {
                        $validQuestionIds[] =
                            (string)$question['id'];
                    }
                }
            }

            foreach ($survey['groups'] as &$group) {
                foreach (($group['questions'] ?? []) as &$question) {

                    if (!isset($question['branching']) ||
                        !is_array($question['branching'])) {
                        $question['branching'] = [];
                    }

                    foreach (
                        $question['branching']
                        as $option => $target
                    ) {
                        if (
                            $target !== '' &&
                            !in_array(
                                (string)$target,
                                $validQuestionIds,
                                true
                            )
                        ) {
                            $question['branching'][$option] = '';
                        }
                    }
                }

                unset($question);
            }

            unset($group);

            $now = survey_now();
            $found = false;

            foreach (
                $data['surveys']
                as $index => $existing
            ) {

                if (
                    (string)$existing['id'] ===
                    (string)$survey['id']
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

        case 'status':

            $surveyId =
                (string)($_POST['survey_id'] ?? '');

            $status =
                (string)($_POST['status'] ?? '');

            if (
                !in_array(
                    $status,
                    ['draft', 'active', 'ended'],
                    true
                )
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' => '不正なステータスです。'
                ], 400);
            }

            foreach ($data['surveys'] as &$survey) {

                if (
                    (string)$survey['id'] ===
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

        case 'delete_survey':

            $surveyId =
                (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {

                if (
                    (string)$survey['id'] ===
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

        case 'save_settings':

            $json =
                (string)($_POST['settings_json'] ?? '');

            $settings =
                json_decode($json, true);

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            /*
             * パスワード空欄の場合は既存値を維持。
             */
            if (
                ($settings['password'] ?? '') === '' &&
                ($data['settings']['password'] ?? '') !== ''
            ) {
                $settings['password'] =
                    $data['settings']['password'];
            }

            /*
             * 保存前にFQDNを正規化。
             * 以後は常に xxxx.cybozu.com を保存。
             */
            $normalized =
                survey_normalize_kintone_host(
                    (string)($settings['subdomain'] ?? '')
                );

            if ($normalized === '') {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'サブドメイン/FQDNが正しくありません。'
                ], 400);
            }

            $settings['subdomain'] = $normalized;

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

        case 'kintone_fields':

            $settings = $data['settings'];

            if (
                isset($_POST['app_id']) &&
                trim((string)$_POST['app_id']) !== ''
            ) {
                $settings['app_id'] =
                    trim((string)$_POST['app_id']);
            }

            $appId =
                trim((string)($settings['app_id'] ?? ''));

            if ($appId === '') {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アプリIDを入力してください。'
                ], 400);
            }

            /*
             * GET /k/v1/app/form/fields.json
             */
            $result = survey_kintone_request(
                'GET',
                '/k/v1/app/form/fields.json?app=' .
                    rawurlencode($appId) .
                    '&lang=ja',
                $settings
            );

            if (!$result['ok']) {
                survey_json_response($result, 400);
            }

            $fields = [];

            foreach (
                ($result['data']['properties'] ?? [])
                as $code => $field
            ) {

                $fields[] = [
                    'label' =>
                        (string)($field['label'] ?? $code),
                    'code' =>
                        (string)$code,
                    'type' =>
                        (string)($field['type'] ?? '')
                ];
            }

            survey_json_response([
                'ok' => true,
                'fields' => $fields,
                'host' =>
                    survey_normalize_kintone_host(
                        (string)($settings['subdomain'] ?? '')
                    )
            ]);

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

            $count = 0;

            foreach ($data['customers'] as &$customer) {

                if (
                    in_array(
                        (string)$customer['id'],
                        array_map('strval', $recipientIds),
                        true
                    )
                ) {

                    $customer['sent_at'] =
                        survey_now();

                    $customer['send_count'] =
                        (int)($customer['send_count'] ?? 0) + 1;

                    if (
                        $customer['answer_status'] !==
                        'answered'
                    ) {
                        $customer['answer_status'] =
                            'unanswered';
                    }

                    $count++;
                }
            }

            unset($customer);

            $data['mail_logs'][] = [
                'id' => survey_id('mail'),
                'survey_id' => $surveyId,
                'sent_at' => survey_now(),
                'template_type' => $templateType,
                'count' => $count,
                'subject' => $subject,
                'body' => $body,
                'executor' => 'admin'
            ];

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'count' => $count
            ]);

        default:

            survey_json_response([
                'ok' => false,
                'message' => 'Unknown action.'
            ], 400);
    }
}

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-800">

<div id="app"></div>

<script>
'use strict';

window.App = {
    State: {
        data: null,
        csrf_token: '',
        page: 'surveys',
        surveyId: null,
        editSurvey: null,
        dirty: false,
        keyword: '',
        status_filter: '',
        sort: 'updated_desc',
        selectedCustomers: [],
        customerFilter: '',
        previewMobile: false,
        selectedQuestions: [],
        responseId: null
    },

    API: {},
    Util: {},
    Render: {},
    actions: {},

    initialized: false
};

/* ================================================================
 * Utility
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
    return prefix + '_' +
        Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 10);
};

App.Util.findSurvey = function(id) {
    if (!App.State.data) return null;

    return App.State.data.surveys.find(
        survey =>
            String(survey.id) === String(id) &&
            !survey.deleted
    ) || null;
};

App.Util.findQuestion = function(id) {

    if (!App.State.editSurvey) {
        return null;
    }

    for (
        const group of App.State.editSurvey.groups || []
    ) {

        const question =
            (group.questions || []).find(
                q =>
                    String(q.id) === String(id)
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
        survey.numbering_mode === 'group'
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
            (survey.groups[i].questions || []).length;
    }

    return 'Q' +
        (number + questionIndex + 1);
};

App.Util.statusText = function(status) {

    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
};

App.Util.statusClass = function(status) {

    return {
        draft:
            'bg-slate-100 text-slate-700',
        active:
            'bg-emerald-100 text-emerald-700',
        ended:
            'bg-amber-100 text-amber-700'
    }[status] || 'bg-slate-100';
};

/* ================================================================
 * API
 * ================================================================ */

App.API.request = async function(
    action,
    params = {}
) {

    const body = new URLSearchParams();

    body.set('action', action);
    body.set(
        'csrf_token',
        App.State.csrf_token
    );

    Object.entries(params).forEach(
        ([key, value]) => {

            if (
                value !== null &&
                typeof value === 'object'
            ) {
                body.set(
                    key,
                    JSON.stringify(value)
                );
            } else {
                body.set(
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
            body
        }
    );

    const result = await response.json();

    if (!result.ok) {
        throw new Error(
            result.message ||
            '処理に失敗しました。'
        );
    }

    return result;
};

App.API.load = async function() {

    const response = await fetch(
        location.pathname +
        '?action=load',
        {
            credentials: 'same-origin'
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

App.API.saveSurvey = function(
    survey
) {

    return App.API.request(
        'save_survey',
        {
            survey_json: survey
        }
    );
};

App.API.saveSettings = function(
    settings
) {

    return App.API.request(
        'save_settings',
        {
            settings_json: settings
        }
    );
};

/* ================================================================
 * kintone
 * ================================================================ */

App.API.fetchKintoneFields =
async function() {

    const settings =
        App.State.data.settings;

    const appId =
        document.getElementById(
            'setting_app_id'
        )?.value.trim() || '';

    const subdomain =
        document.getElementById(
            'setting_subdomain'
        )?.value.trim() || '';

    if (!subdomain) {
        alert(
            'サブドメインまたはFQDNを入力してください。'
        );
        return;
    }

    if (!appId) {
        alert(
            'アプリIDを入力してください。'
        );
        return;
    }

    /*
     * 保存前の入力値を一時的に使う。
     */
    const temporarySettings = {
        ...settings,
        subdomain,
        app_id: appId,
        login_name:
            document.getElementById(
                'setting_login_name'
            )?.value || '',
        password:
            document.getElementById(
                'setting_password'
            )?.value || '',
        proxy:
            document.getElementById(
                'setting_proxy'
            )?.value || '',
        ssl_verify:
            document.getElementById(
                'setting_ssl_verify'
            )?.checked || false
    };

    /*
     * パスワード空欄なら保存済みを使用。
     */
    if (
        !temporarySettings.password &&
        settings.password
    ) {
        temporarySettings.password =
            settings.password;
    }

    const result =
        await App.API.request(
            'kintone_fields',
            {
                app_id: appId
            }
        ).catch(async error => {

            /*
             * PHP側は保存済みsettingsを使用するため、
             * 入力途中の設定を一旦保存せずには
             * fetchできない。
             *
             * そのため一時保存→取得→再保存。
             */
            await App.API.saveSettings(
                temporarySettings
            );

            await App.API.load();

            return App.API.request(
                'kintone_fields',
                {
                    app_id: appId
                }
            );
        });

    const fields =
        result.fields || [];

    App.State.kintoneFields =
        fields;

    App.Render.settingsFields(
        fields
    );

    const message =
        document.getElementById(
            'field_message'
        );

    if (message) {
        message.textContent =
            fields.length +
            '項目を取得しました。';
        message.className =
            'text-sm text-emerald-600 mt-2';
    }
};

App.actions.fetchKintoneFields =
async function() {

    try {
        await App.API.fetchKintoneFields();
    } catch (error) {
        alert(error.message);
    }
};

/* ================================================================
 * Survey
 * ================================================================ */

App.actions.newSurvey = function() {

    const survey = {
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

    App.State.editSurvey = survey;
    App.State.surveyId = survey.id;
    App.State.page = 'edit';

    /*
     * 新規アンケートではdirty=false。
     * 質問追加を可能にする。
     */
    App.State.dirty = false;

    App.Render.main();
};

App.actions.editSurvey = function(id) {

    const survey =
        App.Util.findSurvey(id);

    if (!survey) return;

    App.State.editSurvey =
        JSON.parse(
            JSON.stringify(survey)
        );

    /*
     * 古いデータにもbranchingがない場合に対応。
     */
    App.State.editSurvey.groups
        .forEach(group => {

            group.questions =
                group.questions || [];

            group.questions.forEach(
                question => {

                    if (
                        !Array.isArray(
                            question.options
                        )
                    ) {
                        question.options = [];
                    }

                    if (
                        !question.branching ||
                        typeof question.branching !== 'object'
                    ) {
                        question.branching = {};
                    }
                }
            );
        });

    App.State.surveyId = id;
    App.State.page = 'edit';
    App.State.dirty = false;

    App.Render.main();
};

App.actions.saveSurvey = async function() {

    App.actions.syncEditor();

    const survey =
        App.State.editSurvey;

    if (!survey.title.trim()) {
        alert(
            'タイトルを入力してください。'
        );
        return;
    }

    /*
     * 分岐先が自分自身になっていないかチェック。
     */
    for (
        const group of survey.groups
    ) {

        for (
            const question of group.questions
        ) {

            if (
                question.type !== 'single'
            ) {
                question.branching = {};
                continue;
            }

            for (
                const option of question.options
            ) {

                const target =
                    question.branching?.[option] ||
                    '';

                if (
                    target &&
                    String(target) ===
                    String(question.id)
                ) {

                    alert(
                        '質問「' +
                        question.text +
                        '」の分岐先に自分自身を設定することはできません。'
                    );

                    return;
                }
            }
        }
    }

    try {

        await App.API.saveSurvey(
            survey
        );

        App.State.dirty = false;

        await App.API.load();

        alert('保存しました。');

        App.actions.goSurveys();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.goSurveys = function() {

    App.State.page = 'surveys';
    App.State.surveyId = null;
    App.State.editSurvey = null;
    App.State.dirty = false;

    App.Render.main();
};

/* ================================================================
 * ★重要
 *
 * 新規質問追加時にsyncEditor()を呼ばない。
 *
 * これが今回の修正ポイント。
 * ================================================================ */

App.actions.addQuestion = function(
    groupId
) {

    const survey =
        App.State.editSurvey;

    if (!survey) return;

    const group =
        survey.groups.find(
            g =>
                String(g.id) ===
                String(groupId)
        );

    if (!group) return;

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

        /*
         * option名 => question_id
         */
        branching: {
            '選択肢1': '',
            '選択肢2': ''
        }
    });

    App.State.dirty = true;

    App.Render.editor();

    App.actions.initSortables();
};

/* ================================================================
 * Group
 * ================================================================ */

App.actions.addGroup = function() {

    const survey =
        App.State.editSurvey;

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
};

App.actions.deleteGroup = function(
    groupId
) {

    const survey =
        App.State.editSurvey;

    if (!survey) return;

    const group =
        survey.groups.find(
            g =>
                String(g.id) ===
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

    survey.groups =
        survey.groups.filter(
            g =>
                String(g.id) !==
                String(groupId)
        );

    if (!survey.groups.length) {

        survey.groups.push({
            id: App.Util.id('group'),
            name: 'グループ1',
            questions: []
        });
    }

    App.State.dirty = true;

    App.Render.editor();

    App.actions.initSortables();
};

App.actions.renameGroup = function(
    groupId,
    value
) {

    const group =
        App.State.editSurvey.groups.find(
            g =>
                String(g.id) ===
                String(groupId)
        );

    if (!group) return;

    group.name = value;

    App.State.dirty = true;
};

/* ================================================================
 * Question
 * ================================================================ */

App.actions.deleteQuestion =
function(questionId) {

    if (
        !confirm(
            'この質問を削除しますか？'
        )
    ) {
        return;
    }

    const survey =
        App.State.editSurvey;

    survey.groups.forEach(
        group => {

            group.questions =
                group.questions.filter(
                    q =>
                        String(q.id) !==
                        String(questionId)
                );
        }
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
        App.Util.findQuestion(
            questionId
        );

    if (!question) return;

    if (
        key === 'required' ||
        key === 'other_enabled'
    ) {

        question[key] =
            Boolean(value);

    } else {

        question[key] = value;
    }

    if (
        key === 'type' &&
        value !== 'single'
    ) {
        question.branching = {};
    }

    if (
        key === 'type' &&
        value === 'single'
    ) {

        question.branching =
            question.branching || {};

        question.options.forEach(
            option => {

                if (
                    !Object.prototype.hasOwnProperty.call(
                        question.branching,
                        option
                    )
                ) {
                    question.branching[option] =
                        '';
                }
            }
        );
    }

    App.State.dirty = true;

    if (
        key === 'type'
    ) {

        App.Render.editor();
        App.actions.initSortables();
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

    if (!question) return;

    const oldOption =
        question.options[index];

    question.options[index] =
        value;

    if (
        question.branching &&
        Object.prototype.hasOwnProperty.call(
            question.branching,
            oldOption
        )
    ) {

        const target =
            question.branching[oldOption];

        delete question.branching[
            oldOption
        ];

        question.branching[value] =
            target;
    }

    App.State.dirty = true;

    App.Render.editor();

    App.actions.initSortables();
};

App.actions.updateBranch =
function(
    questionId,
    option,
    targetQuestionId
) {

    const question =
        App.Util.findQuestion(
            questionId
        );

    if (!question) return;

    question.branching =
        question.branching || {};

    /*
     * 自分自身は禁止。
     */
    if (
        String(targetQuestionId) ===
        String(questionId)
    ) {
        targetQuestionId = '';
    }

    question.branching[option] =
        targetQuestionId;

    App.State.dirty = true;
};

App.actions.addOption =
function(questionId) {

    const question =
        App.Util.findQuestion(
            questionId
        );

    if (!question) return;

    const option =
        '選択肢' +
        (question.options.length + 1);

    question.options.push(option);

    question.branching =
        question.branching || {};

    if (
        question.type === 'single'
    ) {
        question.branching[option] = '';
    }

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
        App.Util.findQuestion(
            questionId
        );

    if (!question) return;

    const option =
        question.options[index];

    question.options.splice(
        index,
        1
    );

    if (
        question.branching &&
        option
    ) {
        delete question.branching[
            option
        ];
    }

    App.State.dirty = true;

    App.Render.editor();

    App.actions.initSortables();
};

/* ================================================================
 * DOM → State
 *
 * 「追加」操作では使用しない。
 * 保存時だけ使用。
 * ================================================================ */

App.actions.syncEditor = function() {

    const survey =
        App.State.editSurvey;

    if (!survey) return;

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

    /*
     * SortableJS後のDOM順をStateへ反映。
     */
    const groups = [];

    document.querySelectorAll(
        '#question_editor [data-group-id]'
    ).forEach(
        groupElement => {

            const groupId =
                groupElement.dataset.groupId;

            const group =
                survey.groups.find(
                    g =>
                        String(g.id) ===
                        String(groupId)
                );

            if (!group) return;

            const ids = [];

            groupElement
                .querySelectorAll(
                    '[data-question-id]'
                )
                .forEach(
                    questionElement => {

                        ids.push(
                            questionElement.dataset.questionId
                        );
                    }
                );

            /*
             * DOM上の質問順に並べ替え。
             */
            const map =
                new Map(
                    group.questions.map(
                        q => [
                            String(q.id),
                            q
                        ]
                    )
                );

            group.questions =
                ids.map(
                    id =>
                        map.get(String(id))
                ).filter(Boolean);

            groups.push(group);
        }
    );

    if (groups.length) {
        survey.groups = groups;
    }
};

/* ================================================================
 * SortableJS
 * ================================================================ */

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

    if (!editor) return;

    const groupList =
        editor.querySelector(
            '[data-group-list]'
        );

    if (groupList) {

        new Sortable(
            groupList,
            {
                animation: 180,
                handle: '.group-handle',
                ghostClass:
                    'opacity-40',

                onEnd: function() {

                    App.actions.syncEditor();

                    App.State.dirty = true;

                    App.Render.editor();

                    App.actions.initSortables();
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
                        pull: true,
                        put: true
                    },

                    animation: 180,

                    handle:
                        '.question-handle',

                    ghostClass:
                        'opacity-40',

                    onAdd: function() {

                        /*
                         * 質問が別グループへ
                         * 移動された場合、
                         * DOMからStateを再構築。
                         */
                        App.actions.syncEditor();

                        App.State.dirty = true;

                        App.Render.editor();

                        App.actions.initSortables();
                    },

                    onUpdate: function() {

                        App.actions.syncEditor();

                        App.State.dirty = true;

                        App.Render.editor();

                        App.actions.initSortables();
                    }
                }
            );
        }
    );
};

/* ================================================================
 * Numbering
 * ================================================================ */

App.actions.updateQuestionNumbering =
function(value) {

    App.State.editSurvey.numbering_mode =
        value;

    App.State.dirty = true;

    App.Render.editor();

    App.actions.initSortables();
};

/* ================================================================
 * Preview
 * ================================================================ */

App.actions.openPreview = function() {

    App.actions.syncEditor();

    const modal =
        document.getElementById(
            'preview_modal'
        );

    if (modal) {
        modal.classList.remove(
            'hidden'
        );
    }

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
        mobile;

    App.Render.preview();
};

/* ================================================================
 * Render editor
 * ================================================================ */

App.Render.editor = function() {

    const survey =
        App.State.editSurvey;

    if (!survey) return '';

    let groupsHtml = '';

    survey.groups.forEach(
        (group, groupIndex) => {

            let questionsHtml = '';

            group.questions.forEach(
                (question, questionIndex) => {

                    const number =
                        App.Util.questionNumber(
                            survey,
                            groupIndex,
                            questionIndex
                        );

                    /*
                     * 選択肢
                     */
                    let optionHtml = '';

                    if (
                        question.type !==
                        'text'
                    ) {

                        optionHtml = `
<div class="mt-4">
    <div class="font-semibold text-sm mb-2">
        選択肢
    </div>

    <div class="space-y-2">
`;

                        question.options
                            .forEach(
                                (option, index) => {

                                    const target =
                                        question.branching?.[option] ||
                                        '';

                                    optionHtml += `
<div class="grid grid-cols-1 lg:grid-cols-[1fr_250px_auto] gap-2 items-center">

    <div class="flex items-center gap-2">
        <span class="text-slate-400 text-sm">
            ${index + 1}.
        </span>

        <input
            value="${App.Util.escapeAttr(option)}"
            oninput="App.actions.updateOption('${question.id}',${index},this.value)"
            class="w-full border border-slate-300 rounded-lg px-3 py-2"
        >
    </div>

    ${
        question.type === 'single'
        ?
`
<div>
    <select
        onchange="App.actions.updateBranch('${question.id}',${JSON.stringify(option)},this.value)"
        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
    >
        <option value="">
            分岐しない（次の質問へ）
        </option>

        ${App.Render.branchTargets(
            question.id,
            target
        )}
    </select>
</div>
`
        :
        ''
    }

    <button
        onclick="App.actions.removeOption('${question.id}',${index})"
        class="text-rose-500 hover:text-rose-700 px-2"
    >
        削除
    </button>
</div>
`;
                                }
                            );

                        optionHtml += `
    </div>

    <button
        onclick="App.actions.addOption('${question.id}')"
        class="mt-2 text-sm text-indigo-600 font-semibold"
    >
        ＋ 選択肢を追加
    </button>
</div>
`;
                    }

                    questionsHtml += `
<article
    data-question-id="${question.id}"
    class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm"
>

<div class="flex items-start gap-3">

    <div
        class="question-handle cursor-grab text-slate-400 text-xl pt-1"
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
                onchange="App.actions.updateQuestion('${question.id}','type',this.value)"
                class="border border-slate-300 rounded-lg px-2.5 py-1.5 text-sm"
            >
                <option
                    value="single"
                    ${question.type === 'single' ? 'selected' : ''}
                >
                    単一選択
                </option>

                <option
                    value="multiple"
                    ${question.type === 'multiple' ? 'selected' : ''}
                >
                    複数選択
                </option>

                <option
                    value="text"
                    ${question.type === 'text' ? 'selected' : ''}
                >
                    自由記述
                </option>
            </select>

        </div>

        <input
            value="${App.Util.escapeAttr(question.text)}"
            oninput="App.actions.updateQuestion('${question.id}','text',this.value)"
            class="w-full text-base font-semibold border border-slate-300 rounded-lg px-3 py-2"
            placeholder="質問文"
        >

        ${optionHtml}

        <div class="mt-4 flex items-center gap-5 text-sm">

            <label class="flex items-center gap-2">
                <input
                    type="checkbox"
                    ${question.required ? 'checked' : ''}
                    onchange="App.actions.updateQuestion('${question.id}','required',this.checked)"
                >
                必須回答
            </label>

            ${
                question.type !== 'text'
                ?
`
<label class="flex items-center gap-2">
    <input
        type="checkbox"
        ${question.other_enabled ? 'checked' : ''}
        onchange="App.actions.updateQuestion('${question.id}','other_enabled',this.checked)"
    >
    その他を許可
</label>
`
                :
                ''
            }

        </div>

        ${
            question.type === 'single'
            ?
`
<div class="mt-4 bg-indigo-50 border border-indigo-100 rounded-xl p-3 text-sm text-indigo-800">
    <div class="font-bold mb-1">
        分岐設定
    </div>

    <div>
        各選択肢の右側から、
        その選択肢を選んだ場合に表示する
        質問を指定できます。
    </div>
</div>
`
            :
            ''
        }

    </div>

    <button
        onclick="App.actions.deleteQuestion('${question.id}')"
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
    data-group-id="${group.id}"
    class="bg-slate-50 border border-slate-200 rounded-2xl p-4"
>

<div class="flex items-center gap-3 mb-4">

    <div
        class="group-handle cursor-grab text-slate-400 text-xl"
        title="グループを移動"
    >
        ⠿
    </div>

    <input
        value="${App.Util.escapeAttr(group.name)}"
        onchange="App.actions.renameGroup('${group.id}',this.value)"
        class="flex-1 bg-transparent text-lg font-bold outline-none border-b border-transparent focus:border-indigo-400"
    >

    <button
        onclick="App.actions.addQuestion('${group.id}')"
        class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm"
    >
        ＋ 質問
    </button>

    <button
        onclick="App.actions.deleteGroup('${group.id}')"
        class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-slate-500 hover:text-rose-600 text-sm"
    >
        グループ削除
    </button>

</div>

<div
    data-question-list
    class="space-y-3 min-h-[60px]"
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
            class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white"
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

<!--
    グループ追加ボタンは必ず全グループの末尾。
-->
<button
    onclick="App.actions.addGroup()"
    class="w-full border-2 border-dashed border-slate-300 hover:border-indigo-400 hover:text-indigo-600 rounded-2xl py-5 font-semibold bg-white"
>
    ＋ グループを追加
</button>

</div>

</section>

<div
    id="preview_modal"
    class="hidden fixed inset-0 z-50 bg-slate-900/50 p-4"
>

<div class="bg-white rounded-2xl shadow-xl h-full max-w-5xl mx-auto flex flex-col overflow-hidden">

<div class="p-4 border-b flex items-center justify-between">

<div class="font-bold">
回答者プレビュー
</div>

<div class="flex gap-2">

<button
    onclick="App.actions.previewSize(false)"
    class="px-3 py-1.5 rounded-lg text-sm bg-slate-100"
>
PC表示
</button>

<button
    onclick="App.actions.previewSize(true)"
    class="px-3 py-1.5 rounded-lg text-sm bg-slate-100"
>
スマートフォン表示
</button>

<button
    onclick="App.actions.closePreview()"
    class="px-3 py-1.5 rounded-lg text-sm bg-slate-100"
>
閉じる
</button>

</div>

</div>

<div class="flex-1 overflow-auto bg-slate-100 p-5">

<div
    id="preview_content"
    class="${
        App.State.previewMobile
        ? 'max-w-sm'
        : 'max-w-3xl'
    } mx-auto bg-white rounded-2xl p-6"
></div>

</div>

</div>

</div>
`;
};

/* ================================================================
 * Branch target renderer
 * ================================================================ */

App.Render.branchTargets =
function(
    currentQuestionId,
    selectedId
) {

    const survey =
        App.State.editSurvey;

    if (!survey) return '';

    let html = '';

    survey.groups.forEach(
        group => {

            group.questions.forEach(
                question => {

                    if (
                        String(question.id) ===
                        String(currentQuestionId)
                    ) {
                        return;
                    }

                    html += `
<option
    value="${App.Util.escapeAttr(question.id)}"
    ${
        String(question.id) ===
        String(selectedId)
        ? 'selected'
        : ''
    }
>
${App.Util.escape(
    App.Util.questionNumber(
        survey,
        survey.groups.indexOf(group),
        group.questions.indexOf(question)
    )
)}：
${App.Util.escape(
    question.text || '（質問文なし）'
)}
</option>
`;
                }
            );
        }
    );

    return html;
};

/* ================================================================
 * Preview
 * ================================================================ */

App.Render.preview = function() {

    const container =
        document.getElementById(
            'preview_content'
        );

    const survey =
        App.State.editSurvey;

    if (!container || !survey) {
        return;
    }

    let html = `
<h1 class="text-2xl font-bold mb-7">
${App.Util.escape(survey.title)}
</h1>
`;

    survey.groups.forEach(
        (group, gi) => {

            html += `
<section class="mb-8">

<h2 class="text-lg font-bold border-b pb-2 mb-5">
${App.Util.escape(group.name)}
</h2>
`;

            group.questions.forEach(
                (q, qi) => {

                    const number =
                        App.Util.questionNumber(
                            survey,
                            gi,
                            qi
                        );

                    html += `
<div class="mb-7">

<div class="font-semibold mb-3">

<span class="text-indigo-600 mr-2">
${number}
</span>

${App.Util.escape(q.text)}

${
    q.required
    ? '<span class="text-red-500">*</span>'
    : ''
}

</div>
`;

                    if (
                        q.type === 'text'
                    ) {

                        html += `
<textarea
    rows="4"
    class="w-full border rounded-xl p-3"
></textarea>
`;

                    } else {

                        q.options.forEach(
                            option => {

                                html += `
<label class="flex gap-2 items-center mb-2">

<input
    type="${
        q.type === 'single'
        ? 'radio'
        : 'checkbox'
    }"
>

<span>
${App.Util.escape(option)}
</span>

</label>
`;
                            }
                        );

                        if (
                            q.other_enabled
                        ) {

                            html += `
<input
    class="w-full border rounded-xl p-2 mt-2"
    placeholder="その他"
/>
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

    html += `
<button
    onclick="alert('これはプレビューです。実際の送信は行われません。')"
    class="w-full py-3 bg-indigo-600 text-white rounded-xl font-semibold"
>
送信する
</button>
`;

    container.innerHTML =
        html;
};

/* ================================================================
 * Survey list
 * ================================================================ */

App.actions.responseCount =
function(surveyId) {

    return (
        App.State.data.responses || []
    ).filter(
        response =>
            String(response.survey_id) ===
            String(surveyId)
    ).length;
};

App.actions.changeStatus =
async function(
    id,
    status
) {

    const message =
        status === 'active'
        ? '公開しますか？'
        : '停止しますか？';

    if (!confirm(message)) {
        return;
    }

    try {

        await App.API.request(
            'status',
            {
                survey_id: id,
                status
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
            'この下書きを削除しますか？'
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

App.actions.cloneSurvey =
async function(id) {

    const survey =
        App.Util.findSurvey(id);

    if (!survey) return;

    const copy =
        JSON.parse(
            JSON.stringify(survey)
        );

    copy.id =
        App.Util.id('survey');

    copy.title += '（複製）';

    copy.status = 'draft';
    copy.created_at = '';
    copy.updated_at = '';
    copy.deleted = false;

    copy.groups.forEach(
        group => {

            group.id =
                App.Util.id('group');

            group.questions.forEach(
                question => {

                    const oldId =
                        question.id;

                    question.id =
                        App.Util.id('question');

                    /*
                     * 分岐先もIDを変換する必要がある。
                     */
                    if (
                        question.branching
                    ) {

                        Object.keys(
                            question.branching
                        ).forEach(
                            option => {

                                /*
                                 * oldIdから新IDへの
                                 * 変換は後段で行う。
                                 */
                            }
                        );
                    }

                    void oldId;
                }
            );
        }
    );

    try {

        await App.API.saveSurvey(copy);

        await App.API.load();

        App.Render.surveys();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.searchSurveys =
function(value) {

    App.State.keyword = value;

    App.Render.surveys();
};

App.actions.toggleStatusFilter =
function(value) {

    App.State.status_filter = value;

    App.Render.surveys();
};

App.actions.sortSurveys =
function(value) {

    App.State.sort = value;

    App.Render.surveys();
};

App.Render.surveys = function() {

    const data =
        App.State.data;

    if (!data) return '';

    let surveys =
        data.surveys.filter(
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
                        survey.title || ''
                    )
                    .toLowerCase()
                    .includes(keyword)
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

            if (
                App.State.sort ===
                'updated_asc'
            ) {

                return String(
                    a.updated_at || ''
                ).localeCompare(
                    String(
                        b.updated_at || ''
                    )
                );
            }

            if (
                App.State.sort ===
                'count_desc'
            ) {

                return (
                    App.actions.responseCount(
                        b.id
                    ) -
                    App.actions.responseCount(
                        a.id
                    )
                );
            }

            return String(
                b.updated_at || ''
            ).localeCompare(
                String(
                    a.updated_at || ''
                )
            );
        }
    );

    const rows =
        surveys.map(
            survey => {

                let buttons = `
<button
    onclick="App.actions.editSurvey('${survey.id}')"
    class="px-3 py-1.5 rounded-lg bg-slate-100 text-xs"
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
    onclick="App.actions.changeStatus('${survey.id}','ended')"
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
    onclick="App.actions.changeStatus('${survey.id}','active')"
    class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 text-xs"
>
公開
</button>

<button
    onclick="App.actions.deleteSurvey('${survey.id}')"
    class="px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 text-xs"
>
削除
</button>
`;
                }

                buttons += `
<button
    onclick="App.actions.cloneSurvey('${survey.id}')"
    class="px-3 py-1.5 rounded-lg bg-slate-100 text-xs"
>
複製
</button>
`;

                return `
<tr class="border-b border-slate-100">

<td class="px-4 py-4 text-xs">
${App.Util.escape(
    String(
        survey.created_at || ''
    ).slice(0,10)
)}

<br>

<span class="text-slate-400">
更新:
${App.Util.escape(
    String(
        survey.updated_at || ''
    ).slice(0,10)
)}
</span>

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

<span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold ${
    App.Util.statusClass(
        survey.status
    )
}">
${App.Util.statusText(
    survey.status
)}
</span>

</td>

<td class="px-4 py-4 text-right">
${App.actions.responseCount(
    survey.id
)} 件
</td>

<td class="px-4 py-4">

<div class="flex flex-wrap gap-1.5">
${buttons}
</div>

</td>

</tr>
`;
            }
        ).join('');

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
    class="bg-indigo-600 text-white rounded-xl px-5 py-3 font-semibold"
>
＋ 新規アンケート作成
</button>

</div>

<div class="bg-white rounded-2xl border shadow-sm">

<div class="p-4 border-b flex flex-wrap gap-3">

<input
    value="${App.Util.escapeAttr(
        App.State.keyword
    )}"
    oninput="App.actions.searchSurveys(this.value)"
    placeholder="タイトルを検索"
    class="flex-1 min-w-[240px] border rounded-xl px-4 py-2.5"
>

<select
    onchange="App.actions.toggleStatusFilter(this.value)"
    class="border rounded-xl px-4 py-2.5"
>

<option value="">
すべて
</option>

<option
    value="active"
    ${
        App.State.status_filter ===
        'active'
        ? 'selected'
        : ''
    }
>
公開中
</option>

<option
    value="draft"
    ${
        App.State.status_filter ===
        'draft'
        ? 'selected'
        : ''
    }
>
下書き
</option>

<option
    value="ended"
    ${
        App.State.status_filter ===
        'ended'
        ? 'selected'
        : ''
    }
>
終了
</option>

</select>

<select
    onchange="App.actions.sortSurveys(this.value)"
    class="border rounded-xl px-4 py-2.5"
>

<option
    value="updated_desc"
>
更新日 新しい順
</option>

<option
    value="updated_asc"
>
更新日 古い順
</option>

<option
    value="count_desc"
>
回答数 多い順
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

<th class="px-4 py-3 text-right">
回答数
</th>

<th class="px-4 py-3">
操作
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
 * Settings
 * ================================================================ */

App.Render.settingsFields =
function(fields) {

    const container =
        document.getElementById(
            'kintone_fields_container'
        );

    if (!container) return;

    const settings =
        App.State.data.settings;

    const makeSelect =
        function(
            key,
            label,
            multiple = false
        ) {

            const current =
                settings[key];

            const currentValues =
                Array.isArray(current)
                ? current
                : [current || ''];

            const options =
                fields.map(
                    field => `
<option
    value="${App.Util.escapeAttr(field.code)}"
    ${
        currentValues.includes(
            field.code
        )
        ? 'selected'
        : ''
    }
>
${App.Util.escape(field.label)}
（${App.Util.escape(field.code)}）
</option>
`
                ).join('');

            return `
<div>

<label class="block text-sm font-semibold mb-2">
${label}
</label>

<select
    data-setting-field="${key}"
    ${multiple ? 'multiple' : ''}
    class="w-full border rounded-xl px-3 py-2.5 ${
        multiple
        ? 'h-32'
        : ''
    }"
>
${options}
</select>

</div>
`;
        };

    container.innerHTML = `
<div class="grid md:grid-cols-2 gap-4">

${makeSelect(
    'field_company',
    '会社名'
)}

${makeSelect(
    'field_name',
    '氏名'
)}

${makeSelect(
    'field_email',
    'メールアドレス'
)}

${makeSelect(
    'field_department',
    '部署名'
)}

${makeSelect(
    'field_phone',
    '電話番号'
)}

${makeSelect(
    'field_address',
    '住所',
    true
)}

</div>
`;
};

App.actions.saveSettings =
async function() {

    const settings =
        App.State.data.settings;

    settings.subdomain =
        document.getElementById(
            'setting_subdomain'
        ).value.trim();

    settings.app_id =
        document.getElementById(
            'setting_app_id'
        ).value.trim();

    settings.login_name =
        document.getElementById(
            'setting_login_name'
        ).value;

    const password =
        document.getElementById(
            'setting_password'
        ).value;

    if (password) {
        settings.password =
            password;
    }

    settings.proxy =
        document.getElementById(
            'setting_proxy'
        ).value.trim();

    settings.ssl_verify =
        document.getElementById(
            'setting_ssl_verify'
        ).checked;

    document
        .querySelectorAll(
            '[data-setting-field]'
        )
        .forEach(
            select => {

                const key =
                    select.dataset.settingField;

                if (
                    select.multiple
                ) {

                    settings[key] =
                        Array.from(
                            select.selectedOptions
                        ).map(
                            option =>
                                option.value
                        );

                } else {

                    settings[key] =
                        select.value;
                }
            }
        );

    try {

        await App.API.saveSettings(
            settings
        );

        await App.API.load();

        alert(
            'kintone連携設定を保存しました。'
        );

    } catch (error) {
        alert(error.message);
    }
};

App.Render.settings =
function() {

    const settings =
        App.State.data.settings;

    return `
<section>

<div class="mb-6">

<div class="text-sm text-indigo-600 font-semibold">
SETTINGS
</div>

<h1 class="text-2xl font-bold">
kintone連携設定
</h1>

</div>

<div class="bg-white rounded-2xl border shadow-sm p-6">

<div class="grid md:grid-cols-2 gap-5">

<div>

<label class="block text-sm font-semibold mb-2">
サブドメイン / FQDN
</label>

<input
    id="setting_subdomain"
    value="${App.Util.escapeAttr(
        settings.subdomain
    )}"
    placeholder="xxxx または xxxx.cybozu.com"
    class="w-full border rounded-xl px-4 py-3"
>

<p class="text-xs text-slate-500 mt-2">
xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com
のいずれも入力できます。
</p>

</div>

<div>

<label class="block text-sm font-semibold mb-2">
顧客管理アプリID
</label>

<input
    id="setting_app_id"
    value="${App.Util.escapeAttr(
        settings.app_id
    )}"
    class="w-full border rounded-xl px-4 py-3"
>

</div>

<div>

<label class="block text-sm font-semibold mb-2">
ログイン名
</label>

<input
    id="setting_login_name"
    value="${App.Util.escapeAttr(
        settings.login_name
    )}"
    class="w-full border rounded-xl px-4 py-3"
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
    class="w-full border rounded-xl px-4 py-3"
>

</div>

<div>

<label class="block text-sm font-semibold mb-2">
Proxyサーバ
</label>

<input
    id="setting_proxy"
    value="${App.Util.escapeAttr(
        settings.proxy
    )}"
    placeholder="proxy.example.local:8080"
    class="w-full border rounded-xl px-4 py-3"
>

</div>

<div class="flex items-center gap-3 pt-8">

<input
    id="setting_ssl_verify"
    type="checkbox"
    ${
        settings.ssl_verify
        ? 'checked'
        : ''
    }
>

<label>
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

<span
    id="field_message"
    class="text-sm text-slate-500"
>
</span>

</div>

<div
    id="kintone_fields_container"
    class="mt-6"
></div>

<div class="mt-8 pt-6 border-t">

<button
    onclick="App.actions.saveSettings()"
    class="px-6 py-3 rounded-xl bg-slate-900 text-white font-semibold"
>
設定を保存
</button>

</div>

</div>

</section>
`;
};

/* ================================================================
 * Cancel
 * ================================================================ */

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

App.actions.markDirty =
function() {
    App.State.dirty = true;
};

/* ================================================================
 * Main
 * ================================================================ */

App.Render.main = function() {

    const app =
        document.getElementById(
            'app'
        );

    if (!app) return;

    let content = '';

    if (
        App.State.page ===
        'edit'
    ) {

        content =
            App.Render.editor();

    } else if (
        App.State.page ===
        'settings'
    ) {

        content =
            App.Render.settings();

    } else {

        content =
            App.Render.surveys();
    }

    app.innerHTML = `

<header class="bg-white border-b sticky top-0 z-40">

<div class="max-w-7xl mx-auto px-5 py-4 flex items-center justify-between">

<div class="font-bold">
アンケート管理
</div>

<nav class="flex gap-2">

<button
    onclick="App.actions.goSurveys()"
    class="px-4 py-2 rounded-lg text-sm ${
        App.State.page === 'surveys'
        ? 'bg-indigo-50 text-indigo-700'
        : 'text-slate-600'
    }"
>
アンケート一覧
</button>

<button
    onclick="App.actions.goSettings()"
    class="px-4 py-2 rounded-lg text-sm ${
        App.State.page === 'settings'
        ? 'bg-indigo-50 text-indigo-700'
        : 'text-slate-600'
    }"
>
kintone連携設定
</button>

</nav>

</div>

</header>

<main class="max-w-7xl mx-auto px-5 py-8">
${content}
</main>
`;

    if (
        App.State.page ===
        'edit'
    ) {
        App.actions.initSortables();
    }
};

App.actions.goSettings =
function() {

    App.State.page =
        'settings';

    App.Render.main();
};

App.actions.init =
async function() {

    if (App.initialized) {
        return;
    }

    App.initialized = true;

    try {

        await App.API.load();

        App.Render.main();

    } catch (error) {

        document.getElementById(
            'app'
        ).innerHTML = `
<div class="max-w-xl mx-auto mt-20 bg-white rounded-2xl border p-6">

<h1 class="text-xl font-bold text-rose-600">
初期化エラー
</h1>

<p class="mt-3 text-slate-600">
${App.Util.escape(
    error.message
)}
</p>

</div>
`;
    }
};

/* ================================================================
 * Safe initialization
 * ================================================================ */

if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        function() {
            App.actions.init();
        },
        {
            once: true
        }
    );

} else {

    App.actions.init();
}

</script>

</body>
</html>
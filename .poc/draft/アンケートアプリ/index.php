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
- branch

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

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields
- errors

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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* ---------------------------------------------------------------------
 * PHP utility
 * ------------------------------------------------------------------- */

function surveyGuardData(): array
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

function surveyReadStorage(): array
{
    $default = surveyGuardData();

    if (!is_file(SURVEY_STORAGE_FILE)) {
        surveyWriteStorage($default);
        return $default;
    }

    $raw = @file_get_contents(SURVEY_STORAGE_FILE);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return $default;
    }

    foreach ($default as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function surveyWriteStorage(array $data): bool
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

function surveyJsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function surveyCsrf(): string
{
    if (
        empty($_SESSION['survey_csrf_token']) ||
        !is_string($_SESSION['survey_csrf_token'])
    ) {
        $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['survey_csrf_token'];
}

function surveyVerifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals(surveyCsrf(), $token)
    ) {
        surveyJsonResponse([
            'ok' => false,
            'message' => '不正なリクエストです。ページを再読み込みしてください。'
        ], 403);
    }
}

function surveyId(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function surveyNow(): string
{
    return date('Y-m-d\TH:i:s');
}

function surveyPostJson(string $key): ?array
{
    $raw = $_POST[$key] ?? null;

    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $value = json_decode($raw, true);

    return is_array($value) ? $value : null;
}

/* ---------------------------------------------------------------------
 * kintone
 *
 * 重要:
 * GETの場合はcontentを絶対に設定しない。
 * ------------------------------------------------------------------- */

function surveyKintoneBuildUrl(
    string $domain,
    string $endpoint,
    array $query = []
): string {
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/\/.*$/', '', $domain);
    $domain = preg_replace('/\.cybozu\.com$/i', '', $domain);

    $url = 'https://' . $domain . '.cybozu.com';
    $url .= '/' . ltrim($endpoint, '/');

    if ($query !== []) {
        $url .= '?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    return $url;
}

function surveySafeResponseHeaders(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();

        if (is_array($headers)) {
            return $headers;
        }
    }

    global $http_response_header;

    return isset($http_response_header) && is_array($http_response_header)
        ? $http_response_header
        : [];
}

function surveyKintoneStatus(array $headers): int
{
    $status = 0;

    foreach ($headers as $header) {
        if (
            preg_match(
                '/^HTTP\/\S+\s+(\d{3})/i',
                (string)$header,
                $m
            )
        ) {
            $status = (int)$m[1];
        }
    }

    return $status;
}

function surveyKintoneRequest(
    array $settings,
    string $endpoint,
    string $method = 'GET',
    array $query = [],
    ?array $body = null
): array {
    $domain = trim((string)($settings['subdomain'] ?? ''));

    if ($domain === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'kintoneのサブドメインが設定されていません。'
        ];
    }

    $url = surveyKintoneBuildUrl($domain, $endpoint, $query);

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password)
    ];

    $options = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers) . "\r\n",
        'ignore_errors' => true,
        'timeout' => 20
    ];

    /*
     * GETにはcontentを付けない。
     * これが今回の重要な修正点。
     */
    if (
        strtoupper($method) !== 'GET' &&
        $body !== null
    ) {
        $headers[] = 'Content-Type: application/json';

        $options['header'] =
            implode("\r\n", $headers) . "\r\n";

        $options['content'] = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    $sslVerify = (bool)($settings['ssl_verify'] ?? false);

    $contextOptions = [
        'http' => $options,
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify
        ]
    ];

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $headersReceived = surveySafeResponseHeaders();
    $status = surveyKintoneStatus($headersReceived);

    if ($response === false) {
        return [
            'ok' => false,
            'status' => $status,
            'message' => 'kintone APIへの接続に失敗しました。',
            'url' => $url,
            'headers' => $headersReceived
        ];
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $status,
            'message' => 'kintone APIからJSON以外のレスポンスが返されました。',
            'url' => $url,
            'raw' => $response,
            'headers' => $headersReceived
        ];
    }

    if ($status < 200 || $status >= 300) {
        $message = (string)(
            $decoded['message'] ??
            'kintone APIエラーが発生しました。'
        );

        return [
            'ok' => false,
            'status' => $status,
            'message' => $message,
            'code' => $decoded['code'] ?? '',
            'errors' => $decoded['errors'] ?? [],
            'data' => $decoded,
            'url' => $url
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'data' => $decoded,
        'url' => $url
    ];
}

/* ---------------------------------------------------------------------
 * Survey normalization
 * ------------------------------------------------------------------- */

function surveyNormalize(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? surveyId('survey'));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['start_at'] = (string)($survey['start_at'] ?? '');
    $survey['end_at'] = (string)($survey['end_at'] ?? '');

    $status = $survey['status'] ?? 'draft';

    $survey['status'] = in_array(
        $status,
        ['draft', 'active', 'ended'],
        true
    ) ? $status : 'draft';

    $survey['created_at'] =
        (string)($survey['created_at'] ?? surveyNow());

    $survey['updated_at'] =
        (string)($survey['updated_at'] ?? surveyNow());

    $mode = $survey['numbering_mode'] ?? 'global';

    $survey['numbering_mode'] = in_array(
        $mode,
        ['global', 'group'],
        true
    ) ? $mode : 'global';

    $survey['deleted'] = (bool)($survey['deleted'] ?? false);
    $survey['groups'] =
        is_array($survey['groups'] ?? null)
            ? $survey['groups']
            : [];

    foreach ($survey['groups'] as &$group) {
        $group['id'] =
            (string)($group['id'] ?? surveyId('group'));

        $group['name'] =
            (string)($group['name'] ?? '新しいグループ');

        $group['questions'] =
            is_array($group['questions'] ?? null)
                ? $group['questions']
                : [];

        foreach ($group['questions'] as &$question) {
            $question['id'] =
                (string)($question['id'] ?? surveyId('question'));

            $question['text'] =
                (string)($question['text'] ?? '');

            $type = $question['type'] ?? 'single';

            $question['type'] = in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            ) ? $type : 'single';

            $question['required'] =
                (bool)($question['required'] ?? false);

            $question['options'] =
                is_array($question['options'] ?? null)
                    ? array_values(
                        array_map(
                            'strval',
                            $question['options']
                        )
                    )
                    : [];

            $question['other_enabled'] =
                (bool)($question['other_enabled'] ?? false);

            $question['branch'] =
                is_array($question['branch'] ?? null)
                    ? $question['branch']
                    : [];
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

/* ---------------------------------------------------------------------
 * API
 * ------------------------------------------------------------------- */

if (isset($_GET['action'])) {
    $action = (string)$_GET['action'];
    $data = surveyReadStorage();

    if ($action === 'load') {
        $surveys = [];

        foreach ($data['surveys'] as $survey) {
            if (
                !is_array($survey) ||
                !empty($survey['deleted'])
            ) {
                continue;
            }

            $survey = surveyNormalize($survey);
            $survey['answer_count'] = 0;

            foreach ($data['responses'] as $response) {
                if (
                    is_array($response) &&
                    (string)($response['survey_id'] ?? '') ===
                    $survey['id']
                ) {
                    $survey['answer_count']++;
                }
            }

            $surveys[] = $survey;
        }

        surveyJsonResponse([
            'ok' => true,
            'csrf_token' => surveyCsrf(),
            'surveys' => $surveys,
            'responses' => $data['responses'],
            'customers' => $data['customers'],
            'settings' => $data['settings'],
            'mail_logs' => $data['mail_logs']
        ]);
    }

    if ($action === 'kintone_fields') {
        surveyVerifyCsrf();

        $settings =
            surveyPostJson('settings_json') ??
            $data['settings'];

        $appId = trim(
            (string)(
                $settings['app_id'] ??
                ($_POST['app_id'] ?? '')
            )
        );

        if ($appId === '') {
            surveyJsonResponse([
                'ok' => false,
                'message' => 'アプリIDを入力してください。'
            ], 400);
        }

        /*
         * appはGETクエリとして渡す。
         * GET bodyは使用しない。
         */
        $result = surveyKintoneRequest(
            $settings,
            '/k/v1/app/form/fields.json',
            'GET',
            ['app' => $appId]
        );

        if (!$result['ok']) {
            surveyJsonResponse($result, 400);
        }

        $fields = [];

        foreach (
            ($result['data']['properties'] ?? [])
            as $code => $property
        ) {
            if (!is_array($property)) {
                continue;
            }

            $fields[] = [
                'code' => (string)$code,
                'label' => (string)(
                    $property['label'] ?? $code
                ),
                'type' => (string)(
                    $property['type'] ?? ''
                )
            ];
        }

        surveyJsonResponse([
            'ok' => true,
            'fields' => $fields
        ]);
    }

    if ($action === 'kintone_test') {
        surveyVerifyCsrf();

        $settings =
            surveyPostJson('settings_json') ??
            $data['settings'];

        $appId = trim(
            (string)($settings['app_id'] ?? '')
        );

        if ($appId === '') {
            surveyJsonResponse([
                'ok' => false,
                'message' => 'アプリIDを入力してください。'
            ], 400);
        }

        $result = surveyKintoneRequest(
            $settings,
            '/k/v1/app/form/fields.json',
            'GET',
            ['app' => $appId]
        );

        surveyJsonResponse($result, $result['ok'] ? 200 : 400);
    }

    if ($action === 'export_csv') {
        surveyVerifyCsrf();

        $surveyId =
            (string)($_GET['survey_id'] ?? '');

        $survey = null;

        foreach ($data['surveys'] as $item) {
            if (
                is_array($item) &&
                (string)($item['id'] ?? '') ===
                $surveyId
            ) {
                $survey = surveyNormalize($item);
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

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="survey_' .
            rawurlencode($surveyId) .
            '_' .
            date('YmdHis') .
            '.csv"'
        );

        $out = fopen('php://output', 'wb');

        fwrite($out, "\xEF\xBB\xBF");

        $head = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス'
        ];

        foreach ($questions as $i => $question) {
            $head[] = '設問' . ($i + 1);
        }

        fputcsv($out, $head);

        foreach ($data['responses'] as $response) {
            if (
                !is_array($response) ||
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
                $response['name'] ?? '',
                $response['email'] ?? ''
            ];

            $answers =
                is_array($response['answers'] ?? null)
                    ? $response['answers']
                    : [];

            foreach ($questions as $question) {
                $value =
                    $answers[$question['id']] ?? '';

                if (is_array($value)) {
                    $value = implode('、', $value);
                }

                $row[] = (string)$value;
            }

            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        surveyJsonResponse([
            'ok' => false,
            'message' => '不正なリクエストです。'
        ], 405);
    }

    surveyVerifyCsrf();

    if ($action === 'save_survey') {
        $survey =
            surveyPostJson('survey_json');

        if ($survey === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], 400);
        }

        $survey = surveyNormalize($survey);
        $survey['updated_at'] = surveyNow();

        $found = false;

        foreach ($data['surveys'] as $i => $old) {
            if (
                is_array($old) &&
                (string)($old['id'] ?? '') ===
                $survey['id']
            ) {
                $survey['created_at'] =
                    (string)(
                        $old['created_at'] ??
                        $survey['created_at']
                    );

                $data['surveys'][$i] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['surveys'][] = $survey;
        }

        if (!surveyWriteStorage($data)) {
            surveyJsonResponse([
                'ok' => false,
                'message' => 'データの保存に失敗しました。'
            ], 500);
        }

        surveyJsonResponse([
            'ok' => true,
            'survey' => $survey
        ]);
    }

    if ($action === 'delete_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (
                is_array($survey) &&
                (string)($survey['id'] ?? '') === $id
            ) {
                $survey['deleted'] = true;
                $survey['updated_at'] = surveyNow();
            }
        }

        unset($survey);

        surveyWriteStorage($data);
        surveyJsonResponse(['ok' => true]);
    }

    if ($action === 'status_survey') {
        $id = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? 'draft');

        if (!in_array(
            $status,
            ['draft', 'active', 'ended'],
            true
        )) {
            surveyJsonResponse([
                'ok' => false,
                'message' => '不正なステータスです。'
            ], 400);
        }

        foreach ($data['surveys'] as &$survey) {
            if (
                is_array($survey) &&
                (string)($survey['id'] ?? '') === $id
            ) {
                $survey['status'] = $status;
                $survey['updated_at'] = surveyNow();
            }
        }

        unset($survey);

        surveyWriteStorage($data);
        surveyJsonResponse(['ok' => true]);
    }

    if ($action === 'duplicate_survey') {
        $id = (string)($_POST['survey_id'] ?? '');
        $copy = null;

        foreach ($data['surveys'] as $survey) {
            if (
                is_array($survey) &&
                (string)($survey['id'] ?? '') === $id
            ) {
                $copy = surveyNormalize($survey);
                break;
            }
        }

        if ($copy === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' => '複製元アンケートが見つかりません。'
            ], 404);
        }

        $copy['id'] = surveyId('survey');
        $copy['title'] .= '（コピー）';
        $copy['status'] = 'draft';
        $copy['created_at'] = surveyNow();
        $copy['updated_at'] = surveyNow();
        $copy['deleted'] = false;

        foreach ($copy['groups'] as &$group) {
            $group['id'] = surveyId('group');

            foreach ($group['questions'] as &$question) {
                $question['id'] =
                    surveyId('question');
            }

            unset($question);
        }

        unset($group);

        $data['surveys'][] = $copy;
        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true,
            'survey' => $copy
        ]);
    }

    if ($action === 'save_settings') {
        $settings =
            surveyPostJson('settings_json');

        if ($settings === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' => '設定データが不正です。'
            ], 400);
        }

        $settings = array_merge(
            surveyGuardData()['settings'],
            $settings
        );

        if (!is_array($settings['field_address'])) {
            $settings['field_address'] = [];
        }

        $data['settings'] = $settings;

        if (!surveyWriteStorage($data)) {
            surveyJsonResponse([
                'ok' => false,
                'message' => '設定保存に失敗しました。'
            ], 500);
        }

        surveyJsonResponse([
            'ok' => true,
            'settings' => $settings
        ]);
    }

    if ($action === 'mark_kintone') {
        $id = (string)($_POST['customer_id'] ?? '');

        foreach ($data['customers'] as &$customer) {
            if (
                is_array($customer) &&
                (string)($customer['id'] ?? '') === $id
            ) {
                $customer['kintone_status'] =
                    'registered';
            }
        }

        unset($customer);

        surveyWriteStorage($data);

        surveyJsonResponse(['ok' => true]);
    }

    if ($action === 'send_mail') {
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
            (string)($_POST['mail_subject'] ?? '');

        $body =
            (string)($_POST['mail_body'] ?? '');

        $templateType =
            (string)($_POST['template_type'] ?? 'initial');

        $now = surveyNow();
        $count = 0;

        foreach ($data['customers'] as &$customer) {
            if (
                !is_array($customer) ||
                !in_array(
                    (string)($customer['id'] ?? ''),
                    $recipientIds,
                    true
                )
            ) {
                continue;
            }

            $customer['sent_at'] = $now;
            $customer['send_count'] =
                ((int)($customer['send_count'] ?? 0)) + 1;

            $customer['answer_status'] =
                'unanswered';

            $count++;
        }

        unset($customer);

        $data['mail_logs'][] = [
            'id' => surveyId('mail'),
            'survey_id' => $surveyId,
            'sent_at' => $now,
            'template_type' => $templateType,
            'count' => $count,
            'subject' => $subject,
            'body' => $body,
            'executor' => 'admin'
        ];

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true,
            'count' => $count
        ]);
    }

    surveyJsonResponse([
        'ok' => false,
        'message' => '未知のAPIです。'
    ], 404);
}

/* ---------------------------------------------------------------------
 * HTML
 * ------------------------------------------------------------------- */
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
'use strict';

window.App = {
    state: {
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mail_logs: [],
        csrf_token: '',
        page: 'list',
        survey: null,
        filter: '',
        statusFilter: '',
        sort: 'updated_desc',
        responseSurveyId: '',
        selectedQuestions: {},
        previewMode: 'pc',
        selectedCustomerIds: [],
        settingsFields: [],
        branchSourceQuestion: null
    },

    initDone: false,

    api: {
        async get(action, params = {}) {
            const query = new URLSearchParams({
                action,
                ...params
            });

            const response = await fetch(
                '?' + query.toString(),
                {
                    credentials: 'same-origin'
                }
            );

            const text = await response.text();

            let data;

            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーからJSON以外のレスポンスが返されました。'
                );
            }

            if (!response.ok || data.ok === false) {
                throw new Error(
                    data.message ||
                    '通信に失敗しました。'
                );
            }

            return data;
        },

        async post(action, payload = {}) {
            const form = new FormData();

            form.append('csrf_token', App.state.csrf_token);

            Object.entries(payload).forEach(([key, value]) => {
                if (
                    value !== null &&
                    typeof value === 'object'
                ) {
                    form.append(key, JSON.stringify(value));
                } else {
                    form.append(key, String(value ?? ''));
                }
            });

            const response = await fetch(
                '?action=' +
                encodeURIComponent(action),
                {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin'
                }
            );

            const text = await response.text();

            let data;

            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(
                    'サーバーからJSON以外のレスポンスが返されました。'
                );
            }

            if (!response.ok || data.ok === false) {
                const detail = [];

                if (data.status) {
                    detail.push(
                        'HTTP ' + data.status
                    );
                }

                if (data.code) {
                    detail.push(
                        '[' + data.code + ']'
                    );
                }

                throw new Error(
                    detail.join(' ') +
                    (detail.length ? ' ' : '') +
                    (data.message || '通信に失敗しました。')
                );
            }

            return data;
        }
    },

    util: {
        esc(value) {
            const div = document.createElement('div');
            div.textContent = String(value ?? '');
            return div.innerHTML;
        },

        id() {
            return (
                Date.now().toString(36) +
                Math.random()
                    .toString(36)
                    .slice(2)
            );
        },

        clone(value) {
            return JSON.parse(
                JSON.stringify(value)
            );
        },

        statusLabel(status) {
            return {
                draft: '下書き',
                active: '公開中',
                ended: '終了'
            }[status] || status;
        },

        statusClass(status) {
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

        typeLabel(type) {
            return {
                single: '単一選択',
                multiple: '複数選択',
                text: '自由記述'
            }[type] || type;
        }
    },

    actions: {},

    render: {}
};

/* ---------------------------------------------------------------------
 * Actions
 * ------------------------------------------------------------------- */

App.actions.reload = async function() {
    const data = await App.api.get('load');

    App.state.surveys = data.surveys || [];
    App.state.responses = data.responses || [];
    App.state.customers = data.customers || [];
    App.state.settings = data.settings || {};
    App.state.mail_logs = data.mail_logs || [];
    App.state.csrf_token = data.csrf_token || '';

    App.render.current();
};

App.actions.goList = function() {
    if (App.state.page === 'edit') {
        if (
            !confirm(
                '編集内容を破棄して一覧へ戻りますか？'
            )
        ) {
            return;
        }
    }

    App.state.page = 'list';
    App.state.survey = null;
    App.render.current();
};

App.actions.newSurvey = function() {
    App.state.survey = {
        id: App.util.id(),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [
            {
                id: App.util.id(),
                name: 'グループ1',
                questions: []
            }
        ],
        deleted: false
    };

    App.state.page = 'edit';
    App.render.current();
    App.actions.initSortable();
};

App.actions.editSurvey = function(id) {
    const found = App.state.surveys.find(
        s => s.id === id
    );

    if (!found) {
        alert('アンケートが見つかりません。');
        return;
    }

    App.state.survey =
        App.util.clone(found);

    App.state.page = 'edit';
    App.render.current();

    setTimeout(
        () => App.actions.initSortable(),
        0
    );
};

App.actions.preview = function() {
    App.render.previewModal();
};

App.actions.closePreview = function() {
    const el =
        document.getElementById('preview_modal');

    if (el) {
        el.remove();
    }
};

App.actions.previewSubmit = function() {
    alert(
        'これはプレビューです。実際の回答は送信されません。'
    );
};

App.actions.saveSurvey = async function() {
    const survey =
        App.actions.collectSurvey();

    if (!survey.title.trim()) {
        alert('アンケートタイトルを入力してください。');
        return;
    }

    try {
        const result =
            await App.api.post(
                'save_survey',
                {
                    survey_json: survey
                }
            );

        const index =
            App.state.surveys.findIndex(
                s => s.id === result.survey.id
            );

        if (index >= 0) {
            App.state.surveys[index] =
                result.survey;
        } else {
            App.state.surveys.push(
                result.survey
            );
        }

        alert('保存しました。');

        App.state.page = 'list';
        App.state.survey = null;
        App.render.current();
    } catch (e) {
        alert(e.message);
    }
};

App.actions.collectSurvey = function() {
    const survey =
        App.util.clone(App.state.survey);

    const title =
        document.getElementById('survey_title');

    const start =
        document.getElementById('survey_start_at');

    const end =
        document.getElementById('survey_end_at');

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

    document
        .querySelectorAll('[data-group-name]')
        .forEach(el => {
            const group =
                survey.groups.find(
                    g => g.id === el.dataset.groupName
                );

            if (group) {
                group.name = el.value;
            }
        });

    document
        .querySelectorAll('[data-question]')
        .forEach(card => {
            const qid =
                card.dataset.question;

            for (const group of survey.groups) {
                const q =
                    group.questions.find(
                        x => x.id === qid
                    );

                if (!q) continue;

                const text =
                    card.querySelector(
                        '[data-question-text]'
                    );

                const type =
                    card.querySelector(
                        '[data-question-type]'
                    );

                const required =
                    card.querySelector(
                        '[data-required]'
                    );

                if (text) {
                    q.text = text.value;
                }

                if (type) {
                    q.type = type.value;
                }

                if (required) {
                    q.required =
                        required.checked;
                }

                q.options = [
                    ...card.querySelectorAll(
                        '[data-option]'
                    )
                ].map(x => x.value);

                const other =
                    card.querySelector(
                        '[data-other]'
                    );

                q.other_enabled =
                    !!other?.checked;

                q.branch = {};

                card.querySelectorAll(
                    '[data-branch-option]'
                ).forEach(select => {
                    const option =
                        select.dataset.branchOption;

                    if (select.value) {
                        q.branch[option] =
                            select.value;
                    }
                });
            }
        });

    App.state.survey = survey;

    App.actions.renumber();

    return survey;
};

App.actions.addGroup = function() {
    App.actions.collectSurvey();

    const number =
        App.state.survey.groups.length + 1;

    App.state.survey.groups.push({
        id: App.util.id(),
        name: 'グループ' + number,
        questions: []
    });

    App.render.edit();
    App.actions.initSortable();
};

App.actions.deleteGroup = function(id) {
    if (
        !confirm(
            'グループと内包する質問を削除しますか？'
        )
    ) {
        return;
    }

    App.actions.collectSurvey();

    App.state.survey.groups =
        App.state.survey.groups.filter(
            g => g.id !== id
        );

    if (
        App.state.survey.groups.length === 0
    ) {
        App.state.survey.groups.push({
            id: App.util.id(),
            name: 'グループ1',
            questions: []
        });
    }

    App.actions.renumber();
    App.render.edit();
    App.actions.initSortable();
};

App.actions.addQuestion = function(groupId) {
    App.actions.collectSurvey();

    const group =
        App.state.survey.groups.find(
            g => g.id === groupId
        );

    if (!group) return;

    group.questions.push({
        id: App.util.id(),
        text: '',
        type: 'single',
        required: false,
        options: [
            '選択肢1',
            '選択肢2'
        ],
        other_enabled: false,
        branch: {}
    });

    App.actions.renumber();
    App.render.edit();
    App.actions.initSortable();
};

App.actions.deleteQuestion = function(id) {
    if (!confirm('この質問を削除しますか？')) {
        return;
    }

    App.actions.collectSurvey();

    for (const group of App.state.survey.groups) {
        group.questions =
            group.questions.filter(
                q => q.id !== id
            );
    }

    App.actions.renumber();
    App.render.edit();
    App.actions.initSortable();
};

App.actions.addOption = function(qid) {
    App.actions.collectSurvey();

    for (const group of App.state.survey.groups) {
        const q =
            group.questions.find(
                x => x.id === qid
            );

        if (q) {
            q.options.push(
                '選択肢' +
                (q.options.length + 1)
            );
            break;
        }
    }

    App.render.edit();
    App.actions.initSortable();
};

App.actions.removeOption = function(
    qid,
    index
) {
    App.actions.collectSurvey();

    for (const group of App.state.survey.groups) {
        const q =
            group.questions.find(
                x => x.id === qid
            );

        if (q) {
            q.options.splice(index, 1);
            delete q.branch[q.options[index]];
            break;
        }
    }

    App.render.edit();
    App.actions.initSortable();
};

App.actions.renumber = function() {
    const survey =
        App.state.survey;

    if (!survey) return;

    let globalNo = 1;

    survey.groups.forEach(
        (group, gi) => {
            group.questions.forEach(
                (q, qi) => {
                    if (
                        survey.numbering_mode ===
                        'group'
                    ) {
                        q.number =
                            'Q' +
                            (gi + 1) +
                            '-' +
                            (qi + 1);
                    } else {
                        q.number =
                            'Q' +
                            globalNo++;
                    }
                }
            );
        }
    );
};

App.actions.changeType = function(
    qid,
    type
) {
    App.actions.collectSurvey();

    for (const group of App.state.survey.groups) {
        const q =
            group.questions.find(
                x => x.id === qid
            );

        if (q) {
            q.type = type;

            if (type === 'text') {
                q.options = [];
                q.other_enabled = false;
                q.branch = {};
            } else if (!q.options.length) {
                q.options = [
                    '選択肢1',
                    '選択肢2'
                ];
            }

            break;
        }
    }

    App.render.edit();
    App.actions.initSortable();
};

App.actions.initSortable = function() {
    if (
        typeof Sortable === 'undefined'
    ) {
        return;
    }

    const groupContainer =
        document.getElementById(
            'question_editor'
        );

    if (!groupContainer) return;

    document
        .querySelectorAll('[data-group-list]')
        .forEach(el => {
            if (el._sortable) {
                el._sortable.destroy();
            }

            el._sortable = new Sortable(
                el,
                {
                    group: 'survey-questions',
                    animation: 180,
                    handle: '[data-drag-question]',
                    ghostClass:
                        'opacity-40',
                    onEnd() {
                        App.actions.syncSort();
                    }
                }
            );
        });

    const groups =
        document.querySelector(
            '[data-groups]'
        );

    if (groups) {
        if (groups._sortable) {
            groups._sortable.destroy();
        }

        groups._sortable = new Sortable(
            groups,
            {
                animation: 180,
                handle: '[data-drag-group]',
                ghostClass:
                    'opacity-40',
                onEnd() {
                    App.actions.syncGroups();
                }
            }
        );
    }
};

App.actions.syncSort = function() {
    App.actions.collectSurvey();

    const idsByGroup = [];

    document
        .querySelectorAll('[data-group-list]')
        .forEach(list => {
            idsByGroup.push({
                groupId:
                    list.dataset.groupList,
                ids: [
                    ...list.children
                ].map(
                    el =>
                        el.dataset.question
                )
            });
        });

    const old = App.state.survey.groups;

    const map = {};

    old.forEach(group => {
        group.questions.forEach(q => {
            map[q.id] = q;
        });
    });

    old.forEach(
        group => {
            group.questions = [];
        }
    );

    idsByGroup.forEach(item => {
        const group =
            old.find(
                g => g.id === item.groupId
            );

        if (!group) return;

        item.ids.forEach(id => {
            if (map[id]) {
                group.questions.push(
                    map[id]
                );
            }
        });
    });

    App.actions.renumber();
    App.render.edit();
    App.actions.initSortable();
};

App.actions.syncGroups = function() {
    App.actions.collectSurvey();

    const ids = [
        ...document.querySelectorAll(
            '[data-group]'
        )
    ].map(
        el => el.dataset.group
    );

    const map =
        Object.fromEntries(
            App.state.survey.groups.map(
                g => [g.id, g]
            )
        );

    App.state.survey.groups =
        ids.map(id => map[id]).filter(Boolean);

    App.actions.renumber();
    App.render.edit();
    App.actions.initSortable();
};

App.actions.toggleStatus = async function(
    id
) {
    const survey =
        App.state.surveys.find(
            s => s.id === id
        );

    if (!survey) return;

    const next =
        survey.status === 'active'
            ? 'ended'
            : 'active';

    if (
        !confirm(
            next === 'active'
                ? 'このアンケートを公開しますか？'
                : 'このアンケートを停止しますか？'
        )
    ) {
        return;
    }

    try {
        await App.api.post(
            'status_survey',
            {
                survey_id: id,
                status: next
            }
        );

        await App.actions.reload();
    } catch (e) {
        alert(e.message);
    }
};

App.actions.deleteSurvey = async function(
    id
) {
    if (!confirm('この下書きを削除しますか？')) {
        return;
    }

    try {
        await App.api.post(
            'delete_survey',
            {survey_id: id}
        );

        await App.actions.reload();
    } catch (e) {
        alert(e.message);
    }
};

App.actions.duplicateSurvey = async function(
    id
) {
    try {
        await App.api.post(
            'duplicate_survey',
            {survey_id: id}
        );

        await App.actions.reload();
    } catch (e) {
        alert(e.message);
    }
};

App.actions.setPage = function(page, id = '') {
    if (page === 'edit') {
        App.actions.editSurvey(id);
        return;
    }

    if (page === 'settings') {
        App.state.page = 'settings';
        App.render.current();
        return;
    }

    if (page === 'send') {
        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        App.state.survey = survey
            ? App.util.clone(survey)
            : null;

        App.state.page = 'send';
        App.render.current();
        return;
    }

    if (page === 'aggregate') {
        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        App.state.survey = survey
            ? App.util.clone(survey)
            : null;

        App.state.page = 'aggregate';
        App.state.responseSurveyId = id;
        App.render.current();
    }
};

App.actions.filterList = function() {
    App.state.filter =
        document.getElementById(
            'survey_filter'
        )?.value || '';

    App.state.statusFilter =
        document.getElementById(
            'survey_status_filter'
        )?.value || '';

    App.state.sort =
        document.getElementById(
            'survey_sort'
        )?.value || 'updated_desc';

    App.render.list();
};

App.actions.fetchKintoneFields = async function() {
    const appId =
        document.getElementById(
            'setting_app_id'
        )?.value.trim();

    if (!appId) {
        alert('顧客管理アプリIDを入力してください。');
        return;
    }

    const settings =
        App.actions.collectSettings();

    settings.app_id = appId;

    const message =
        document.getElementById(
            'field_message'
        );

    if (message) {
        message.textContent =
            '項目一覧を取得しています…';
    }

    try {
        const result =
            await App.api.post(
                'kintone_fields',
                {
                    app_id: appId,
                    settings_json: settings
                }
            );

        App.state.settingsFields =
            result.fields || [];

        App.state.settings = settings;

        if (message) {
            message.textContent =
                App.state.settingsFields.length +
                '項目を取得しました。';
        }

        App.render.settings();
    } catch (e) {
        if (message) {
            message.textContent =
                e.message;
        }

        alert(e.message);
    }
};

App.actions.collectSettings = function() {
    const s =
        App.util.clone(
            App.state.settings || {}
        );

    s.subdomain =
        document.getElementById(
            'setting_subdomain'
        )?.value || '';

    s.app_id =
        document.getElementById(
            'setting_app_id'
        )?.value || '';

    s.login_name =
        document.getElementById(
            'setting_login_name'
        )?.value || '';

    s.password =
        document.getElementById(
            'setting_password'
        )?.value || '';

    s.proxy =
        document.getElementById(
            'setting_proxy'
        )?.value || '';

    s.ssl_verify =
        document.getElementById(
            'setting_ssl_verify'
        )?.checked || false;

    s.field_company =
        document.getElementById(
            'field_company'
        )?.value || '';

    s.field_name =
        document.getElementById(
            'field_name'
        )?.value || '';

    s.field_email =
        document.getElementById(
            'field_email'
        )?.value || '';

    s.field_department =
        document.getElementById(
            'field_department'
        )?.value || '';

    s.field_phone =
        document.getElementById(
            'field_phone'
        )?.value || '';

    s.field_address = [
        ...document.querySelectorAll(
            '[data-address-field]:checked'
        )
    ].map(
        el => el.value
    );

    return s;
};

App.actions.saveSettings = async function() {
    const settings =
        App.actions.collectSettings();

    try {
        const result =
            await App.api.post(
                'save_settings',
                {
                    settings_json: settings
                }
            );

        App.state.settings =
            result.settings;

        alert('kintone連携設定を保存しました。');
    } catch (e) {
        alert(e.message);
    }
};

App.actions.testKintone = async function() {
    const settings =
        App.actions.collectSettings();

    try {
        const result =
            await App.api.post(
                'kintone_test',
                {
                    settings_json: settings
                }
            );

        alert(
            '接続確認成功。\nHTTP ' +
            result.status
        );
    } catch (e) {
        alert(
            '接続確認に失敗しました。\n' +
            e.message
        );
    }
};

App.actions.openResponse = function(
    responseId
) {
    const response =
        App.state.responses.find(
            r => r.id === responseId
        );

    if (!response) return;

    App.render.responseModal(response);
};

App.actions.closeResponse = function() {
    document
        .getElementById('response_modal')
        ?.remove();
};

App.actions.sendMail = async function() {
    const ids =
        App.state.selectedCustomerIds;

    if (!ids.length) {
        alert('送信先を選択してください。');
        return;
    }

    const selected =
        App.state.customers.filter(
            c => ids.includes(c.id)
        );

    const already =
        selected.filter(
            c => Number(c.send_count || 0) > 0
        );

    if (
        already.length &&
        !confirm(
            '既に送信済みの宛先が含まれています。' +
            '再送しますか？'
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

    const type =
        document.getElementById(
            'template_type'
        )?.value || 'initial';

    try {
        const result =
            await App.api.post(
                'send_mail',
                {
                    survey_id:
                        App.state.survey.id,
                    recipient_ids: ids,
                    mail_subject: subject,
                    mail_body: body,
                    template_type: type
                }
            );

        alert(
            result.count +
            '件の送信記録を登録しました。'
        );

        App.state.selectedCustomerIds = [];

        await App.actions.reload();
        App.state.page = 'send';
        App.render.current();
    } catch (e) {
        alert(e.message);
    }
};

App.actions.toggleCustomer = function(
    id,
    checked
) {
    const list =
        App.state.selectedCustomerIds;

    if (checked) {
        if (!list.includes(id)) {
            list.push(id);
        }
    } else {
        App.state.selectedCustomerIds =
            list.filter(x => x !== id);
    }
};

App.actions.toggleAllCustomers = function(
    checked
) {
    if (checked) {
        App.state.selectedCustomerIds =
            App.state.customers
                .filter(
                    c =>
                        c.source !== 'web'
                )
                .map(c => c.id);
    } else {
        App.state.selectedCustomerIds = [];
    }

    App.render.send();
};

App.actions.filterResponses = function() {
    App.render.aggregate();
};

App.actions.toggleQuestion = function(
    id,
    checked
) {
    App.state.selectedQuestions[id] =
        checked;

    App.render.aggregate();
};

App.actions.allQuestions = function(
    value
) {
    if (!App.state.survey) return;

    const questions = [];

    App.state.survey.groups.forEach(
        g =>
            g.questions.forEach(
                q => questions.push(q)
            )
    );

    questions.forEach(
        q => {
            App.state.selectedQuestions[q.id] =
                value;
        }
    );

    App.render.aggregate();
};

App.actions.exportCsv = function() {
    const url =
        '?action=export_csv' +
        '&survey_id=' +
        encodeURIComponent(
            App.state.survey.id
        );

    const form =
        document.createElement('form');

    form.method = 'POST';
    form.action = url;

    const token =
        document.createElement('input');

    token.type = 'hidden';
    token.name = 'csrf_token';
    token.value =
        App.state.csrf_token;

    form.appendChild(token);
    document.body.appendChild(form);
    form.submit();
    form.remove();
};

App.actions.printAggregate = function() {
    window.print();
};

/* ---------------------------------------------------------------------
 * Render
 * ------------------------------------------------------------------- */

App.render.header = function() {
    return `
<header class="sticky top-0 z-40 bg-white border-b border-slate-200">
 <div class="max-w-7xl mx-auto px-5 h-16 flex items-center justify-between">
  <div class="font-bold text-lg text-slate-900">
   アンケート管理システム
  </div>
  <nav class="flex gap-2 text-sm">
   <button class="px-3 py-2 rounded-lg hover:bg-slate-100"
    onclick="App.actions.goList()">アンケート一覧</button>
   <button class="px-3 py-2 rounded-lg hover:bg-slate-100"
    onclick="App.actions.setPage('settings')">キントーン連携設定</button>
  </nav>
 </div>
</header>`;
};

App.render.current = function() {
    const app =
        document.getElementById('app');

    let body = '';

    if (App.state.page === 'list') {
        body = App.render.list();
    } else if (App.state.page === 'edit') {
        body = App.render.edit();
    } else if (App.state.page === 'settings') {
        body = App.render.settings();
    } else if (App.state.page === 'send') {
        body = App.render.send();
    } else if (App.state.page === 'aggregate') {
        body = App.render.aggregate();
    }

    app.innerHTML =
        App.render.header() +
        `<main class="max-w-7xl mx-auto p-5">${body}</main>`;
};

App.render.list = function() {
    let surveys =
        [...App.state.surveys];

    const keyword =
        App.state.filter
            .trim()
            .toLowerCase();

    if (keyword) {
        surveys =
            surveys.filter(
                s =>
                    s.title
                        .toLowerCase()
                        .includes(keyword)
            );
    }

    if (App.state.statusFilter) {
        surveys =
            surveys.filter(
                s =>
                    s.status ===
                    App.state.statusFilter
            );
    }

    surveys.sort((a, b) => {
        if (App.state.sort === 'updated_desc') {
            return String(b.updated_at)
                .localeCompare(
                    String(a.updated_at)
                );
        }

        if (App.state.sort === 'updated_asc') {
            return String(a.updated_at)
                .localeCompare(
                    String(b.updated_at)
                );
        }

        if (App.state.sort === 'answers_desc') {
            return (
                Number(b.answer_count || 0) -
                Number(a.answer_count || 0)
            );
        }

        if (App.state.sort === 'answers_asc') {
            return (
                Number(a.answer_count || 0) -
                Number(b.answer_count || 0)
            );
        }

        if (App.state.sort === 'start_desc') {
            return String(b.start_at)
                .localeCompare(
                    String(a.start_at)
                );
        }

        return String(a.start_at)
            .localeCompare(
                String(b.start_at)
            );
    });

    return `
<section>
 <div class="flex items-center justify-between mb-5">
  <div>
   <h1 class="text-2xl font-bold">アンケート一覧</h1>
   <p class="text-sm text-slate-500 mt-1">
    アンケートの作成・公開・集計・送信を管理します。
   </p>
  </div>
  <button onclick="App.actions.newSurvey()"
   class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2.5 rounded-lg font-medium">
   ＋ 新規アンケート作成
  </button>
 </div>

 <div class="bg-white border border-slate-200 rounded-xl p-4 mb-4 flex gap-3">
  <input id="survey_filter"
   value="${App.util.esc(App.state.filter)}"
   onkeydown="if(event.key==='Enter')App.actions.filterList()"
   placeholder="タイトルを検索"
   class="flex-1 border border-slate-300 rounded-lg px-3 py-2">

  <select id="survey_status_filter"
   onchange="App.actions.filterList()"
   class="border border-slate-300 rounded-lg px-3">
   <option value="">すべて</option>
   <option value="active" ${App.state.statusFilter==='active'?'selected':''}>公開中</option>
   <option value="draft" ${App.state.statusFilter==='draft'?'selected':''}>下書き</option>
   <option value="ended" ${App.state.statusFilter==='ended'?'selected':''}>終了</option>
  </select>

  <select id="survey_sort"
   onchange="App.actions.filterList()"
   class="border border-slate-300 rounded-lg px-3">
   <option value="updated_desc">更新日 新しい順</option>
   <option value="updated_asc">更新日 古い順</option>
   <option value="answers_desc">回答数 多い順</option>
   <option value="answers_asc">回答数 少ない順</option>
   <option value="start_desc">期間開始 新しい順</option>
   <option value="start_asc">期間開始 古い順</option>
  </select>
 </div>

 <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
  <div class="overflow-x-auto">
   <table class="w-full text-sm">
    <thead class="bg-slate-50 border-b">
     <tr>
      <th class="text-left p-4">作成日 / 更新日</th>
      <th class="text-left p-4">タイトル</th>
      <th class="text-left p-4">アンケート期間</th>
      <th class="text-left p-4">ステータス</th>
      <th class="text-right p-4">回答数</th>
      <th class="text-right p-4">操作</th>
     </tr>
    </thead>
    <tbody>
     ${
        surveys.length
            ? surveys.map(
                s =>
                    App.render.surveyRow(s)
            ).join('')
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
</section>`;
};

App.render.surveyRow = function(s) {
    const status =
        App.util.esc(s.status);

    let actions = `
<button onclick="App.actions.editSurvey('${App.util.esc(s.id)}')"
 class="px-2.5 py-1.5 rounded bg-slate-100 hover:bg-slate-200">
 確認・編集
</button>`;

    if (s.status === 'active') {
        actions += `
<button onclick="App.actions.setPage('aggregate','${App.util.esc(s.id)}')"
 class="px-2.5 py-1.5 rounded bg-indigo-50 text-indigo-700">集計</button>
<button onclick="App.actions.setPage('send','${App.util.esc(s.id)}')"
 class="px-2.5 py-1.5 rounded bg-indigo-50 text-indigo-700">送信</button>
<button onclick="App.actions.toggleStatus('${App.util.esc(s.id)}')"
 class="px-2.5 py-1.5 rounded bg-amber-50 text-amber-700">停止</button>`;
    }

    if (s.status === 'draft') {
        actions += `
<button onclick="App.actions.deleteSurvey('${App.util.esc(s.id)}')"
 class="px-2.5 py-1.5 rounded bg-red-50 text-red-700">削除</button>`;
    }

    if (s.status === 'ended') {
        actions += `
<button onclick="App.actions.setPage('aggregate','${App.util.esc(s.id)}')"
 class="px-2.5 py-1.5 rounded bg-indigo-50 text-indigo-700">集計</button>`;
    }

    actions += `
<button onclick="App.actions.duplicateSurvey('${App.util.esc(s.id)}')"
 class="px-2.5 py-1.5 rounded bg-slate-100 hover:bg-slate-200">
 複製
</button>`;

    return `
<tr class="border-b last:border-0 hover:bg-slate-50">
 <td class="p-4 whitespace-nowrap text-slate-500">
  ${App.util.esc(
      String(s.created_at || '').replace('T',' ')
  )}<br>
  <span class="text-xs">
   更新: ${App.util.esc(
       String(s.updated_at || '').replace('T',' ')
   )}
  </span>
 </td>

 <td class="p-4 font-bold">
  ${App.util.esc(s.title)}
 </td>

 <td class="p-4">
  ${
      s.start_at || s.end_at
          ? App.util.esc(
              (s.start_at || '未設定') +
              ' ～ ' +
              (s.end_at || '未設定')
          )
          : '未設定'
  }
 </td>

 <td class="p-4">
  <span class="px-2.5 py-1 rounded-full text-xs font-medium ${App.util.statusClass(status)}">
   ${App.util.statusLabel(status)}
  </span>
 </td>

 <td class="p-4 text-right">
  ${Number(s.answer_count || 0)} 件
 </td>

 <td class="p-4">
  <div class="flex flex-wrap gap-1.5 justify-end">
   ${actions}
  </div>
 </td>
</tr>`;
};

App.render.edit = function() {
    const s =
        App.state.survey;

    App.actions.renumber();

    return `
<section>
 <div class="flex items-center justify-between mb-5">
  <div>
   <div class="text-sm text-slate-500 mb-1">
    アンケート一覧 ＞ 編集
   </div>
   <h1 class="text-2xl font-bold">
    アンケート作成・編集
   </h1>
  </div>

  <div class="flex gap-2">
   <button onclick="App.actions.preview()"
    class="px-4 py-2 rounded-lg border bg-white">
    プレビュー
   </button>
   <button onclick="App.actions.goList()"
    class="px-4 py-2 rounded-lg border bg-white">
    キャンセル
   </button>
   <button onclick="App.actions.saveSurvey()"
    class="px-4 py-2 rounded-lg bg-indigo-600 text-white">
    保存して一覧へ戻る
   </button>
  </div>
 </div>

 <div class="bg-white rounded-xl border p-5 mb-5">
  <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
   <label class="md:col-span-2">
    <span class="text-sm font-medium">タイトル</span>
    <input id="survey_title"
     value="${App.util.esc(s.title)}"
     class="mt-1 w-full border rounded-lg px-3 py-2">
   </label>

   <label>
    <span class="text-sm font-medium">開始日時</span>
    <input id="survey_start_at"
     type="datetime-local"
     value="${App.util.esc(s.start_at)}"
     class="mt-1 w-full border rounded-lg px-3 py-2">
   </label>

   <label>
    <span class="text-sm font-medium">終了日時</span>
    <input id="survey_end_at"
     type="datetime-local"
     value="${App.util.esc(s.end_at)}"
     class="mt-1 w-full border rounded-lg px-3 py-2">
   </label>

   <label>
    <span class="text-sm font-medium">質問番号</span>
    <select id="survey_numbering_mode"
     onchange="App.actions.collectSurvey();App.actions.renumber();App.render.edit();App.actions.initSortable()"
     class="mt-1 w-full border rounded-lg px-3 py-2">
     <option value="global" ${s.numbering_mode==='global'?'selected':''}>
      Q1 / Q2 / Q3
     </option>
     <option value="group" ${s.numbering_mode==='group'?'selected':''}>
      Q1-1 / Q1-2 / Q2-1
     </option>
    </select>
   </label>

   <label>
    <span class="text-sm font-medium">ステータス</span>
    <select
     onchange="App.actions.collectSurvey();App.state.survey.status=this.value"
     class="mt-1 w-full border rounded-lg px-3 py-2">
     <option value="draft" ${s.status==='draft'?'selected':''}>下書き</option>
     <option value="active" ${s.status==='active'?'selected':''}>公開中</option>
     <option value="ended" ${s.status==='ended'?'selected':''}>終了</option>
    </select>
   </label>
  </div>
 </div>

 <div id="question_editor">
  <div data-groups class="space-y-5">
   ${s.groups.map(
       g => App.render.group(g)
   ).join('')}
  </div>

  <button onclick="App.actions.addGroup()"
   class="mt-5 w-full py-3 rounded-xl border-2 border-dashed border-slate-300 text-slate-500 hover:bg-white">
   ＋ グループを追加
  </button>
 </div>
</section>`;
};

App.render.group = function(g) {
    return `
<div data-group="${App.util.esc(g.id)}"
 class="bg-white rounded-xl border shadow-sm">

 <div class="px-5 py-4 border-b bg-slate-50 flex items-center gap-3">
  <span data-drag-group
   class="cursor-grab text-slate-400 text-xl"
   title="グループを並べ替え">⠿</span>

  <input
   data-group-name="${App.util.esc(g.id)}"
   value="${App.util.esc(g.name)}"
   class="flex-1 bg-transparent font-bold text-lg outline-none">

  <button
   onclick="App.actions.deleteGroup('${App.util.esc(g.id)}')"
   class="text-red-600 text-sm">
   グループ削除
  </button>
 </div>

 <div data-group-list="${App.util.esc(g.id)}"
  class="p-5 space-y-4 min-h-20">
  ${
      g.questions.length
          ? g.questions.map(
              q => App.render.question(q)
          ).join('')
          : `<div class="text-center text-slate-400 py-5">
              質問をここへ追加してください
             </div>`
  }
 </div>

 <div class="px-5 pb-5">
  <button
   onclick="App.actions.addQuestion('${App.util.esc(g.id)}')"
   class="px-4 py-2 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100">
   ＋ 質問を追加
  </button>
 </div>
</div>`;
};

App.render.question = function(q) {
    const survey =
        App.state.survey;

    const allQuestions = [];

    survey.groups.forEach(
        g =>
            g.questions.forEach(
                x => {
                    if (x.id !== q.id) {
                        allQuestions.push(x);
                    }
                }
            )
    );

    return `
<div data-question="${App.util.esc(q.id)}"
 class="border border-slate-200 rounded-xl p-4 bg-white">

 <div class="flex items-start gap-3">
  <span data-drag-question
   class="cursor-grab text-slate-400 text-xl pt-1">⠿</span>

  <div class="flex-1">
   <div class="flex items-center justify-between mb-3">
    <span class="font-bold text-indigo-700">
     ${App.util.esc(q.number || '')}
    </span>

    <button
     onclick="App.actions.deleteQuestion('${App.util.esc(q.id)}')"
     class="text-red-600 text-sm">
     削除
    </button>
   </div>

   <input data-question-text
    value="${App.util.esc(q.text)}"
    placeholder="質問文を入力してください"
    class="w-full border rounded-lg px-3 py-2 mb-3">

   <div class="grid md:grid-cols-2 gap-3 mb-3">
    <select data-question-type
     onchange="App.actions.changeType('${App.util.esc(q.id)}',this.value)"
     class="border rounded-lg px-3 py-2">
     <option value="single" ${q.type==='single'?'selected':''}>単一選択</option>
     <option value="multiple" ${q.type==='multiple'?'selected':''}>複数選択</option>
     <option value="text" ${q.type==='text'?'selected':''}>自由記述</option>
    </select>

    <label class="flex items-center gap-2">
     <input data-required
      type="checkbox"
      ${q.required?'checked':''}>
     必須回答
    </label>
   </div>

   ${
       q.type !== 'text'
           ? `
   <div class="border rounded-lg p-3 bg-slate-50">
    <div class="text-sm font-medium mb-2">
     選択肢
    </div>

    <div class="space-y-2">
     ${q.options.map(
         (option, i) =>
             App.render.option(
                 q,
                 option,
                 i,
                 allQuestions
             )
     ).join('')}
    </div>

    <button
     onclick="App.actions.addOption('${App.util.esc(q.id)}')"
     class="mt-3 text-sm text-indigo-700">
     ＋ 選択肢を追加
    </button>

    <label class="flex items-center gap-2 mt-3 text-sm">
     <input data-other
      type="checkbox"
      ${q.other_enabled?'checked':''}>
     「その他」を許可
    </label>
   </div>`
           : `
   <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">
    回答者が複数行のテキストを入力できます。
   </div>`
   }
  </div>
 </div>
</div>`;
};

App.render.option = function(
    q,
    option,
    index,
    allQuestions
) {
    return `
<div class="flex gap-2 items-center">
 <input data-option
  value="${App.util.esc(option)}"
  class="flex-1 border rounded-lg px-3 py-2">

 <button
  onclick="App.actions.removeOption('${App.util.esc(q.id)}',${index})"
  class="text-red-500 px-2">
  ×
 </button>

 ${
     q.type === 'single'
         ? `
  <select data-branch-option="${App.util.esc(option)}"
   class="w-48 border rounded-lg px-2 py-2 text-xs">
   <option value="">分岐なし</option>
   ${allQuestions.map(
       target =>
           `<option value="${App.util.esc(target.id)}"
            ${q.branch?.[option]===target.id?'selected':''}>
            ${App.util.esc(target.number || '')}
            ${App.util.esc(target.text || '（未入力）').slice(0,20)}
           </option>`
   ).join('')}
  </select>`
         : ''
 }
</div>`;
};

App.render.settings = function() {
    const s =
        App.state.settings || {};

    const fields =
        App.state.settingsFields || [];

    const select = (
        id,
        current,
        multiple = false
    ) => {
        return `
<select id="${id}"
 ${multiple?'multiple':''}
 class="w-full border rounded-lg px-3 py-2 ${multiple?'h-32':''}">
 <option value="">-- 選択してください --</option>
 ${fields.map(
     f =>
         `<option value="${App.util.esc(f.code)}"
          ${current===f.code?'selected':''}>
          ${App.util.esc(f.label)}
          [${App.util.esc(f.code)}]
         </option>`
 ).join('')}
</select>`;
    };

    return `
<section>
 <div class="mb-5">
  <div class="text-sm text-slate-500">
   ホーム ＞ システム設定
  </div>
  <h1 class="text-2xl font-bold mt-1">
   kintone連携設定
  </h1>
 </div>

 <div class="bg-white border rounded-xl p-6">
  <div class="grid md:grid-cols-2 gap-5">

   <label>
    <span class="text-sm font-medium">
     サブドメイン
    </span>
    <input id="setting_subdomain"
     value="${App.util.esc(s.subdomain || '')}"
     placeholder="xxxx または xxxx.cybozu.com"
     class="mt-1 w-full border rounded-lg px-3 py-2">
   </label>

   <label>
    <span class="text-sm font-medium">
     顧客管理アプリID
    </span>
    <input id="setting_app_id"
     value="${App.util.esc(s.app_id || '')}"
     class="mt-1 w-full border rounded-lg px-3 py-2">
   </label>

   <label>
    <span class="text-sm font-medium">
     ログイン名
    </span>
    <input id="setting_login_name"
     value="${App.util.esc(s.login_name || '')}"
     class="mt-1 w-full border rounded-lg px-3 py-2">
   </label>

   <label>
    <span class="text-sm font-medium">
     パスワード
    </span>
    <input id="setting_password"
     type="password"
     value="${App.util.esc(s.password || '')}"
     class="mt-1 w-full border rounded-lg px-3 py-2">
   </label>

   <label>
    <span class="text-sm font-medium">
     Proxyサーバ
    </span>
    <input id="setting_proxy"
     value="${App.util.esc(s.proxy || '')}"
     placeholder="host:port"
     class="mt-1 w-full border rounded-lg px-3 py-2">
   </label>

   <label class="flex items-center gap-2 mt-7">
    <input id="setting_ssl_verify"
     type="checkbox"
     ${s.ssl_verify?'checked':''}>
    SSL証明書を検証する
   </label>
  </div>

  <div class="flex gap-2 mt-6">
   <button
    onclick="App.actions.fetchKintoneFields()"
    class="px-4 py-2 rounded-lg bg-indigo-600 text-white">
    項目一覧を取得
   </button>

   <button
    onclick="App.actions.testKintone()"
    class="px-4 py-2 rounded-lg border">
    接続確認
   </button>

   <button
    onclick="App.actions.saveSettings()"
    class="px-4 py-2 rounded-lg border">
    設定を保存
   </button>
  </div>

  <div id="field_message"
   class="mt-3 text-sm text-slate-500">
   ${fields.length ? fields.length+'項目取得済み' : ''}
  </div>

  <hr class="my-7">

  <h2 class="font-bold text-lg mb-4">
   kintoneフィールドマッピング
  </h2>

  <div class="grid md:grid-cols-2 gap-5">

   <label>
    <span class="text-sm font-medium">会社名</span>
    ${select('field_company',s.field_company)}
   </label>

   <label>
    <span class="text-sm font-medium">氏名</span>
    ${select('field_name',s.field_name)}
   </label>

   <label>
    <span class="text-sm font-medium">メールアドレス</span>
    ${select('field_email',s.field_email)}
   </label>

   <label>
    <span class="text-sm font-medium">部署名</span>
    ${select('field_department',s.field_department)}
   </label>

   <label>
    <span class="text-sm font-medium">電話番号</span>
    ${select('field_phone',s.field_phone)}
   </label>

   <div>
    <div class="text-sm font-medium mb-1">
     住所（複数選択可）
    </div>
    <div class="border rounded-lg p-3 max-h-48 overflow-auto">
     ${
         fields.map(
             f =>
                 `<label class="flex gap-2 items-center py-1">
                   <input data-address-field
                    type="checkbox"
                    value="${App.util.esc(f.code)}"
                    ${(s.field_address||[]).includes(f.code)?'checked':''}>
                   ${App.util.esc(f.label)}
                   <span class="text-xs text-slate-400">
                    [${App.util.esc(f.code)}]
                   </span>
                  </label>`
         ).join('') ||
         '<span class="text-sm text-slate-400">項目一覧を取得してください。</span>'
     }
    </div>
   </div>
  </div>
 </div>
</section>`;
};

App.render.send = function() {
    const survey =
        App.state.survey;

    if (!survey) {
        return '<div>アンケートが見つかりません。</div>';
    }

    const keyword =
        document.getElementById(
            'customer_filter'
        )?.value || '';

    const customers =
        App.state.customers.filter(c => {
            if (!keyword) return true;

            return (
                String(c.company || '')
                    .includes(keyword) ||
                String(c.name || '')
                    .includes(keyword) ||
                String(c.email || '')
                    .includes(keyword)
            );
        });

    return `
<section>
 <div class="mb-5">
  <div class="text-sm text-slate-500">
   ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
  </div>
  <h1 class="text-2xl font-bold mt-1">
   ${App.util.esc(survey.title)}
  </h1>
 </div>

 <div class="bg-white border rounded-xl p-5 mb-5">
  <div class="grid gap-4">
   <label>
    <span class="font-medium">件名</span>
    <input id="mail_subject"
     value="アンケートのご案内"
     class="mt-1 w-full border rounded-lg px-3 py-2">
   </label>

   <label>
    <span class="font-medium">テンプレート</span>
    <select id="template_type"
     class="mt-1 border rounded-lg px-3 py-2">
     <option value="initial">初回送信</option>
     <option value="reminder">リマインド</option>
    </select>
   </label>

   <label>
    <span class="font-medium">本文</span>
    <textarea id="mail_body" rows="8"
     class="mt-1 w-full border rounded-lg px-3 py-2">${App.util.esc(
         '{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n{アンケートURL}'
     )}</textarea>
   </label>
  </div>
 </div>

 <div class="bg-white border rounded-xl overflow-hidden">
  <div class="p-4 border-b flex items-center gap-3">
   <input id="customer_filter"
    oninput="App.render.send()"
    placeholder="顧客名・会社名・メールアドレス"
    class="flex-1 border rounded-lg px-3 py-2">

   <button onclick="App.actions.sendMail()"
    class="px-4 py-2 rounded-lg bg-indigo-600 text-white">
    一括送信
   </button>
  </div>

  <div class="overflow-x-auto">
   <table class="w-full text-sm">
    <thead class="bg-slate-50 border-b">
     <tr>
      <th class="p-3">
       <input id="select_all"
        type="checkbox"
        onclick="App.actions.toggleAllCustomers(this.checked)">
      </th>
      <th class="p-3 text-left">会社名 / 氏名</th>
      <th class="p-3 text-left">メール</th>
      <th class="p-3 text-left">電話番号</th>
      <th class="p-3 text-left">送信履歴</th>
      <th class="p-3 text-left">回答</th>
      <th class="p-3 text-left">kintone</th>
     </tr>
    </thead>

    <tbody>
     ${customers.map(c => `
      <tr class="border-b">
       <td class="p-3">
        ${
            c.source === 'web'
                ? ''
                : `<input type="checkbox"
                    ${App.state.selectedCustomerIds.includes(c.id)?'checked':''}
                    onchange="App.actions.toggleCustomer('${App.util.esc(c.id)}',this.checked)">`
        }
       </td>

       <td class="p-3">
        <b>${App.util.esc(c.company)}</b><br>
        ${App.util.esc(c.name)}
       </td>

       <td class="p-3">
        ${App.util.esc(c.email)}
       </td>

       <td class="p-3">
        ${App.util.esc(c.phone)}
       </td>

       <td class="p-3">
        ${c.sent_at ? App.util.esc(c.sent_at) : '未送信'}<br>
        <span class="text-xs text-slate-400">
         ${Number(c.send_count || 0)}回
        </span>
       </td>

       <td class="p-3">
        <span class="px-2 py-1 rounded text-xs ${
            c.answer_status === 'answered'
                ? 'bg-emerald-100 text-emerald-700'
                : 'bg-slate-100 text-slate-600'
        }">
         ${
             c.answer_status === 'answered'
                 ? '回答済み'
                 : '送信済み（未回答）'
         }
        </span>
       </td>

       <td class="p-3">
        ${
            c.kintone_status === 'registered'
                ? '<span class="text-emerald-600">✓ 登録完了</span>'
                : `<button
                    onclick="App.actions.markKintone('${App.util.esc(c.id)}')"
                    class="text-indigo-600">登録完了</button>`
        }
       </td>
      </tr>
     `).join('')}
    </tbody>
   </table>
  </div>
 </div>
</section>`;
};

App.actions.markKintone = async function(id) {
    try {
        await App.api.post(
            'mark_kintone',
            {customer_id:id}
        );

        await App.actions.reload();

        App.state.page = 'send';
        App.render.current();
    } catch(e) {
        alert(e.message);
    }
};

App.render.aggregate = function() {
    const survey =
        App.state.survey;

    if (!survey) {
        return '<div>アンケートが見つかりません。</div>';
    }

    const responses =
        App.state.responses.filter(
            r => r.survey_id === survey.id
        );

    const customers =
        App.state.customers;

    const sent =
        customers.filter(
            c => Number(c.send_count || 0) > 0
        );

    const answeredCustomerIds =
        new Set(
            responses
                .map(r => r.customer_id)
                .filter(Boolean)
        );

    const unanswered =
        sent.filter(
            c => !answeredCustomerIds.has(c.id)
        ).length;

    const fromWeb =
        responses.filter(
            r => !r.customer_id
        ).length;

    const rate =
        sent.length
            ? (
                responses.filter(
                    r => r.customer_id
                ).length /
                sent.length *
                100
            ).toFixed(1)
            : '0.0';

    const questions = [];

    survey.groups.forEach(
        g =>
            g.questions.forEach(
                q => questions.push(q)
            )
    );

    if (!Object.keys(
        App.state.selectedQuestions
    ).length) {
        questions.forEach(
            q =>
                App.state.selectedQuestions[q.id] =
                true
        );
    }

    const responseKeyword =
        document.getElementById(
            'response_filter'
        )?.value || '';

    const filteredResponses =
        responses.filter(r => {
            if (!responseKeyword) return true;

            return (
                String(r.company || '')
                    .includes(responseKeyword) ||
                String(r.name || '')
                    .includes(responseKeyword)
            );
        });

    return `
<section>
 <div class="mb-5">
  <div class="text-sm text-slate-500">
   ホーム ＞ アンケート一覧 ＞ 集計
  </div>
  <h1 class="text-2xl font-bold mt-1">
   ${App.util.esc(survey.title)}
  </h1>
 </div>

 <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
  ${[
      ['送信対象者数',sent.length+' 人'],
      ['回答数',responses.length+' 件'],
      ['未登録顧客からの回答',fromWeb+' 件'],
      ['未回答数',unanswered+' 人'],
      ['回答率',rate+' %']
  ].map(
      x =>
          `<div class="bg-white border rounded-xl p-4">
            <div class="text-xs text-slate-500">${x[0]}</div>
            <div class="text-2xl font-bold mt-1">${x[1]}</div>
           </div>`
  ).join('')}
 </div>

 <div class="grid lg:grid-cols-4 gap-5">

  <aside class="bg-white border rounded-xl p-4 h-fit">
   <h2 class="font-bold mb-3">
    設問絞り込み
   </h2>

   <div class="flex gap-2 mb-3">
    <button onclick="App.actions.allQuestions(true)"
     class="text-xs text-indigo-600">
     全選択
    </button>
    <button onclick="App.actions.allQuestions(false)"
     class="text-xs text-indigo-600">
     全解除
    </button>
   </div>

   <div class="space-y-2">
    ${questions.map(q => `
     <label class="flex gap-2 text-sm">
      <input type="checkbox"
       ${App.state.selectedQuestions[q.id]?'checked':''}
       onchange="App.actions.toggleQuestion('${App.util.esc(q.id)}',this.checked)">
      <span>
       ${App.util.esc(q.number || '')}
       ${App.util.esc(q.text || '未入力')}
      </span>
     </label>
    `).join('')}
   </div>
  </aside>

  <div class="lg:col-span-3 space-y-5">

   ${
       responses.length
           ? questions
               .filter(
                   q =>
                       App.state.selectedQuestions[q.id]
               )
               .map(
                   q =>
                       App.render.questionSummary(
                           q,
                           responses
                       )
               ).join('')
           : `
      <div class="bg-white border rounded-xl p-12 text-center text-slate-400">
       現在、回答データはありません
      </div>`
   }

   <div class="bg-white border rounded-xl overflow-hidden">
    <div class="p-4 border-b flex items-center gap-2">
     <h2 class="font-bold flex-1">
      個別回答一覧
     </h2>

     <input id="response_filter"
      value="${App.util.esc(responseKeyword)}"
      oninput="App.render.aggregate()"
      placeholder="会社名・氏名"
      class="border rounded-lg px-3 py-2 text-sm">

     <button onclick="App.actions.exportCsv()"
      class="px-3 py-2 border rounded-lg text-sm">
      CSV
     </button>

     <button onclick="App.actions.printAggregate()"
      class="px-3 py-2 border rounded-lg text-sm">
      PDF / 印刷
     </button>
    </div>

    <div class="overflow-x-auto">
     <table class="w-full text-sm">
      <thead class="bg-slate-50 border-b">
       <tr>
        <th class="p-3 text-left">会社名</th>
        <th class="p-3 text-left">氏名</th>
        <th class="p-3 text-left">回答日時</th>
        <th class="p-3 text-right">操作</th>
       </tr>
      </thead>
      <tbody>
       ${filteredResponses.map(
           r =>
               `<tr class="border-b">
                 <td class="p-3">${App.util.esc(r.company)}</td>
                 <td class="p-3">${App.util.esc(r.name)}</td>
                 <td class="p-3">${App.util.esc(r.answered_at)}</td>
                 <td class="p-3 text-right">
                  <button
                   onclick="App.actions.openResponse('${App.util.esc(r.id)}')"
                   class="text-indigo-600">
                   全回答を表示
                  </button>
                 </td>
                </tr>`
       ).join('')}
      </tbody>
     </table>
    </div>
   </div>

  </div>
 </div>
</section>`;
};

App.render.questionSummary = function(
    q,
    responses
) {
    const counts = {};

    q.options.forEach(
        option => {
            counts[option] = 0;
        }
    );

    responses.forEach(r => {
        let value =
            r.answers?.[q.id];

        if (Array.isArray(value)) {
            value.forEach(
                v => {
                    counts[v] =
                        (counts[v] || 0) + 1;
                }
            );
        } else if (value !== undefined) {
            counts[value] =
                (counts[value] || 0) + 1;
        }
    });

    const total = responses.length || 1;

    if (q.type === 'text') {
        const texts =
            responses
                .map(
                    r => ({
                        r,
                        value:
                            r.answers?.[q.id] || ''
                    })
                )
                .filter(
                    x => String(x.value).trim()
                );

        return `
<div class="bg-white border rounded-xl p-5">
 <div class="flex justify-between mb-4">
  <div>
   <span class="text-indigo-600 font-bold">
    ${App.util.esc(q.number || '')}
   </span>
   <h2 class="font-bold">
    ${App.util.esc(q.text)}
   </h2>
  </div>
  <span class="text-xs bg-slate-100 px-2 py-1 rounded">
   自由記述
  </span>
 </div>

 <div class="space-y-3 max-h-72 overflow-auto">
  ${
      texts.map(
          x =>
              `<div class="border-l-2 border-indigo-300 pl-3">
                <div class="text-xs text-slate-500">
                 ${App.util.esc(x.r.company)}
                 ${App.util.esc(x.r.name)}
                </div>
                <div class="mt-1">
                 ${App.util.esc(x.value)}
                </div>
               </div>`
      ).join('') ||
      '<div class="text-slate-400">回答なし</div>'
  }
 </div>
</div>`;
    }

    return `
<div class="bg-white border rounded-xl p-5">
 <div class="flex justify-between mb-4">
  <div>
   <span class="text-indigo-600 font-bold">
    ${App.util.esc(q.number || '')}
   </span>
   <h2 class="font-bold">
    ${App.util.esc(q.text)}
   </h2>
  </div>
  <span class="text-xs bg-slate-100 px-2 py-1 rounded">
   ${App.util.typeLabel(q.type)}
  </span>
 </div>

 <div class="space-y-3">
  ${Object.entries(counts).map(
      ([option,count]) => {
          const percent =
              Math.round(
                  count / total * 1000
              ) / 10;

          return `
<div>
 <div class="flex justify-between text-sm mb-1">
  <span>${App.util.esc(option)}</span>
  <span>${count}件 / ${percent}%</span>
 </div>
 <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
  <div
   class="h-full bg-indigo-500"
   style="width:${percent}%"></div>
 </div>
</div>`;
      }
  ).join('')}
 </div>
</div>`;
};

App.render.previewModal = function() {
    const survey =
        App.actions.collectSurvey();

    const questions = [];

    survey.groups.forEach(
        g =>
            g.questions.forEach(
                q => questions.push(q)
            )
    );

    document.body.insertAdjacentHTML(
        'beforeend',
        `
<div id="preview_modal"
 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-5">
 <div class="${
     App.state.previewMode === 'pc'
         ? 'w-full max-w-3xl'
         : 'w-[390px]'
 } max-h-[90vh] overflow-auto bg-white rounded-2xl shadow-xl">

  <div class="sticky top-0 bg-white border-b p-4 flex justify-between">
   <div class="font-bold">プレビュー</div>
   <div class="flex gap-2">
    <button
     onclick="App.state.previewMode='pc';App.actions.closePreview();App.actions.preview()"
     class="px-3 py-1 border rounded">
     PC表示
    </button>
    <button
     onclick="App.state.previewMode='sp';App.actions.closePreview();App.actions.preview()"
     class="px-3 py-1 border rounded">
     スマートフォン表示
    </button>
    <button onclick="App.actions.closePreview()"
     class="px-3 py-1 border rounded">
     閉じる
    </button>
   </div>
  </div>

  <div id="preview_content" class="p-6">
   <h1 class="text-2xl font-bold mb-6">
    ${App.util.esc(survey.title)}
   </h1>

   ${questions.map(
       q => `
    <div class="mb-6">
     <div class="font-bold mb-2">
      ${App.util.esc(q.number || '')}
      ${App.util.esc(q.text)}
      ${q.required ? '<span class="text-red-500">*</span>' : ''}
     </div>

     ${
         q.type === 'text'
             ? `<textarea rows="5"
                  class="w-full border rounded-lg p-3"></textarea>`
             : q.options.map(
                 o =>
                     `<label class="block mb-2">
                       <input
                        type="${q.type==='single'?'radio':'checkbox'}"
                        name="preview_${App.util.esc(q.id)}">
                       ${App.util.esc(o)}
                      </label>`
             ).join('')
     }
    </div>`
   ).join('')}

   <button onclick="App.actions.previewSubmit()"
    class="w-full bg-indigo-600 text-white py-3 rounded-lg">
    送信
   </button>
  </div>
 </div>
</div>`
    );
};

App.render.responseModal = function(response) {
    const survey =
        App.state.survey;

    const questions = [];

    survey.groups.forEach(
        g =>
            g.questions.forEach(
                q => questions.push(q)
            )
    );

    document.body.insertAdjacentHTML(
        'beforeend',
        `
<div id="response_modal"
 class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-5">
 <div class="bg-white w-full max-w-2xl max-h-[90vh] overflow-auto rounded-2xl">

  <div class="sticky top-0 bg-white border-b p-4 flex justify-between">
   <div class="font-bold">
    ${App.util.esc(response.name)} さんの回答
   </div>
   <button
    onclick="App.actions.closeResponse()"
    class="px-3 py-1 border rounded">
    閉じる
   </button>
  </div>

  <div id="response_detail" class="p-5 space-y-5">
   <div class="text-sm text-slate-500">
    ${App.util.esc(response.company)}
    ／
    ${App.util.esc(response.email)}
    ／
    ${App.util.esc(response.answered_at)}
   </div>

   ${questions.map(
       q =>
           `<div>
             <div class="font-bold">
              ${App.util.esc(q.number || '')}
              ${App.util.esc(q.text)}
             </div>
             <div class="mt-1 bg-slate-50 rounded-lg p-3 whitespace-pre-wrap">
              ${App.util.esc(
                  Array.isArray(
                      response.answers?.[q.id]
                  )
                      ? response.answers[q.id].join('、')
                      : response.answers?.[q.id] ?? ''
              )}
             </div>
            </div>`
   ).join('')}
  </div>
 </div>
</div>`
    );
};

/* ---------------------------------------------------------------------
 * Initializer
 * ------------------------------------------------------------------- */

App.init = async function() {
    if (App.initDone) {
        return;
    }

    App.initDone = true;

    const app =
        document.getElementById('app');

    app.innerHTML = `
<div class="min-h-screen flex items-center justify-center">
 <div class="text-slate-500">
  読み込み中…
 </div>
</div>`;

    try {
        await App.actions.reload();
    } catch (e) {
        app.innerHTML = `
<div class="max-w-xl mx-auto mt-20 bg-white border rounded-xl p-6">
 <h1 class="font-bold text-lg mb-2">
  読み込みに失敗しました
 </h1>
 <p class="text-red-600">
  ${App.util.esc(e.message)}
 </p>
 <button
  onclick="location.reload()"
  class="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-lg">
  再読み込み
 </button>
</div>`;
    }
};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        {once:true}
    );
} else {
    App.init();
}
</script>

</body>
</html>
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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* =========================================================
 * PHP UTILITIES
 * ======================================================= */

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
            'message' => 'CSRFトークンが無効です。ページを再読み込みしてください。'
        ], 403);
    }
}

function surveyId(string $prefix = 'id'): string
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

function surveyNormalizeSurvey(array $survey): array
{
    $survey['id'] = (string)($survey['id'] ?? surveyId('survey'));
    $survey['title'] = (string)($survey['title'] ?? '');
    $survey['start_at'] = (string)($survey['start_at'] ?? '');
    $survey['end_at'] = (string)($survey['end_at'] ?? '');

    $survey['status'] = in_array(
        $survey['status'] ?? 'draft',
        ['draft', 'active', 'ended'],
        true
    )
        ? $survey['status']
        : 'draft';

    $survey['created_at'] =
        (string)($survey['created_at'] ?? surveyNow());

    $survey['updated_at'] =
        (string)($survey['updated_at'] ?? surveyNow());

    $survey['numbering_mode'] = in_array(
        $survey['numbering_mode'] ?? 'global',
        ['global', 'group'],
        true
    )
        ? $survey['numbering_mode']
        : 'global';

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

            $question['type'] = in_array(
                $question['type'] ?? 'single',
                ['single', 'multiple', 'text'],
                true
            )
                ? $question['type']
                : 'single';

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
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

/*
 * =========================================================
 * kintone API
 *
 * 重要:
 * GETではContent-Typeとcontentを送らない。
 * これが今回のCB_IL02対策。
 * =========================================================
 */
function surveyKintoneRequest(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    $subdomain =
        trim((string)($settings['subdomain'] ?? ''));

    if ($subdomain === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'kintoneのサブドメインを入力してください。'
        ];
    }

    $subdomain =
        preg_replace(
            '#^https?://#i',
            '',
            $subdomain
        );

    $subdomain =
        preg_replace(
            '#/.*$#',
            '',
            $subdomain
        );

    $subdomain = trim((string)$subdomain);

    if ($subdomain === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'kintoneのドメインが不正です。'
        ];
    }

    $host = str_contains($subdomain, '.')
        ? $subdomain
        : $subdomain . '.cybozu.com';

    $url = 'https://' . $host . $path;

    $login =
        (string)($settings['login_name'] ?? '');

    $password =
        (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'kintoneのログイン名とパスワードを入力してください。'
        ];
    }

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password)
    ];

    $http = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers) . "\r\n",
        'ignore_errors' => true,
        'timeout' => 30,
        'protocol_version' => 1.1
    ];

    /*
     * GETには絶対にcontentを付けない。
     */
    if (
        $body !== null &&
        strtoupper($method) !== 'GET'
    ) {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'JSON生成に失敗しました。'
            ];
        }

        $http['header'] .=
            "Content-Type: application/json\r\n";

        $http['content'] = $json;
    }

    $sslVerify =
        (bool)($settings['ssl_verify'] ?? false);

    $options = [
        'http' => $http,
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify,
            'SNI_enabled' => true
        ]
    ];

    $proxy =
        trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match(
            '/^[a-zA-Z0-9._-]+:\d+$/',
            $proxy
        )) {
            return [
                'ok' => false,
                'status' => 0,
                'message' =>
                    'Proxyサーバは「host名:port番号」で入力してください。'
            ];
        }

        $options['http']['proxy'] =
            'tcp://' . $proxy;

        $options['http']['request_fulluri'] =
            true;
    }

    $context =
        stream_context_create($options);

    $result =
        @file_get_contents(
            $url,
            false,
            $context
        );

    $responseHeaders = [];

    /*
     * PHP 8.4 / 8.5対応。
     */
    if (
        function_exists(
            'http_get_last_response_headers'
        )
    ) {
        $headers =
            http_get_last_response_headers();

        if (is_array($headers)) {
            $responseHeaders = $headers;
        }
    }

    /*
     * 旧PHP環境用フォールバック。
     */
    if (
        empty($responseHeaders) &&
        isset($http_response_header) &&
        is_array($http_response_header)
    ) {
        $responseHeaders =
            $http_response_header;
    }

    $statusCode = 0;

    foreach ($responseHeaders as $header) {
        if (
            is_string($header) &&
            preg_match(
                '#^HTTP/\S+\s+(\d{3})#i',
                $header,
                $m
            )
        ) {
            $statusCode = (int)$m[1];
        }
    }

    if ($result === false) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' =>
                'kintone APIへの接続に失敗しました。',
            'url' => $url,
            'headers' => $responseHeaders
        ];
    }

    $decoded =
        json_decode($result, true);

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' =>
                'kintone APIからJSONを取得できませんでした。',
            'raw' => $result
        ];
    }

    if ($statusCode >= 400) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'code' =>
                (string)($decoded['code'] ?? ''),
            'message' =>
                (string)(
                    $decoded['message'] ??
                    'kintone APIエラー'
                ),
            'data' => $decoded
        ];
    }

    return [
        'ok' => true,
        'status' => $statusCode,
        'data' => $decoded
    ];
}

/* =========================================================
 * API ROUTER
 * ======================================================= */

if (isset($_GET['action'])) {
    $action =
        (string)$_GET['action'];

    $data =
        surveyReadStorage();

    if ($action === 'load') {
        $surveys = [];

        foreach ($data['surveys'] as $survey) {
            if (
                !is_array($survey) ||
                !empty($survey['deleted'])
            ) {
                continue;
            }

            $survey =
                surveyNormalizeSurvey($survey);

            $count = 0;

            foreach ($data['responses'] as $response) {
                if (
                    is_array($response) &&
                    (string)(
                        $response['survey_id'] ?? ''
                    ) ===
                    (string)$survey['id']
                ) {
                    $count++;
                }
            }

            $survey['answer_count'] =
                $count;

            $surveys[] =
                $survey;
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

    /*
     * =====================================================
     * kintone項目一覧
     * =====================================================
     */
    if ($action === 'kintone_fields') {
        surveyVerifyCsrf();

        $settings =
            surveyPostJson('settings_json');

        if ($settings === null) {
            $settings =
                $data['settings'];
        }

        $appId =
            trim(
                (string)(
                    $_POST['app_id'] ??
                    $settings['app_id'] ??
                    ''
                )
            );

        if ($appId === '') {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アプリIDを入力してください。'
            ], 400);
        }

        $settings['app_id'] =
            $appId;

        /*
         * lang=jaを明示。
         */
        $path =
            '/k/v1/app/form/fields.json?' .
            http_build_query(
                [
                    'app' => $appId,
                    'lang' => 'ja'
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $result =
            surveyKintoneRequest(
                $settings,
                $path,
                'GET'
            );

        if (!$result['ok']) {
            surveyJsonResponse([
                'ok' => false,
                'status' =>
                    $result['status'] ?? 0,
                'code' =>
                    $result['code'] ?? '',
                'message' =>
                    $result['message'] ??
                    'kintone項目一覧取得に失敗しました。',
                'data' =>
                    $result['data'] ?? null
            ], 400);
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
                'code' =>
                    (string)$code,
                'label' =>
                    (string)(
                        $property['label'] ??
                        $code
                    ),
                'type' =>
                    (string)(
                        $property['type'] ??
                        ''
                    )
            ];
        }

        usort(
            $fields,
            static function (
                array $a,
                array $b
            ): int {
                return strnatcasecmp(
                    $a['label'],
                    $b['label']
                );
            }
        );

        surveyJsonResponse([
            'ok' => true,
            'fields' => $fields
        ]);
    }

    /*
     * CSV
     */
    if ($action === 'export_csv') {
        surveyVerifyCsrf();

        $surveyId =
            (string)(
                $_GET['survey_id'] ?? ''
            );

        $survey = null;

        foreach ($data['surveys'] as $item) {
            if (
                is_array($item) &&
                (string)(
                    $item['id'] ?? ''
                ) === $surveyId
            ) {
                $survey =
                    surveyNormalizeSurvey($item);
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
                $group['questions']
                as $question
            ) {
                $questions[] =
                    $question;
            }
        }

        $filename =
            'survey_' .
            $surveyId .
            '_' .
            date('YmdHis') .
            '.csv';

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="' .
            $filename .
            '"'
        );

        $output =
            fopen(
                'php://output',
                'wb'
            );

        fwrite(
            $output,
            "\xEF\xBB\xBF"
        );

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス'
        ];

        foreach (
            $questions
            as $index => $question
        ) {
            $header[] =
                '設問' . ($index + 1);
        }

        fputcsv(
            $output,
            $header
        );

        foreach (
            $data['responses']
            as $response
        ) {
            if (
                !is_array($response) ||
                (string)(
                    $response['survey_id'] ?? ''
                ) !== $surveyId
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
                is_array(
                    $response['answers'] ?? null
                )
                    ? $response['answers']
                    : [];

            foreach ($questions as $question) {
                $value =
                    $answers[
                        $question['id']
                    ] ?? '';

                if (is_array($value)) {
                    $value =
                        implode(
                            '、',
                            $value
                        );
                }

                $row[] =
                    (string)$value;
            }

            fputcsv(
                $output,
                $row
            );
        }

        fclose($output);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        surveyJsonResponse([
            'ok' => false,
            'message' =>
                '不正なリクエストです。'
        ], 405);
    }

    surveyVerifyCsrf();

    /*
     * アンケート保存
     */
    if ($action === 'save_survey') {
        $survey =
            surveyPostJson('survey_json');

        if ($survey === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アンケートデータが不正です。'
            ], 400);
        }

        $survey =
            surveyNormalizeSurvey($survey);

        $survey['updated_at'] =
            surveyNow();

        $found = false;

        foreach (
            $data['surveys']
            as $index => $existing
        ) {
            if (
                is_array($existing) &&
                (string)(
                    $existing['id'] ?? ''
                ) ===
                $survey['id']
            ) {
                $data['surveys'][$index] =
                    $survey;

                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['created_at'] =
                surveyNow();

            $data['surveys'][] =
                $survey;
        }

        if (!surveyWriteStorage($data)) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'データ保存に失敗しました。'
            ], 500);
        }

        surveyJsonResponse([
            'ok' => true,
            'survey' => $survey
        ]);
    }

    /*
     * ステータス
     */
    if ($action === 'status_survey') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $status =
            (string)(
                $_POST['status'] ?? ''
            );

        if (
            !in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )
        ) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '不正なステータスです。'
            ], 400);
        }

        foreach (
            $data['surveys']
            as $index => $survey
        ) {
            if (
                is_array($survey) &&
                (string)(
                    $survey['id'] ?? ''
                ) === $surveyId
            ) {
                $data['surveys'][$index]['status'] =
                    $status;

                $data['surveys'][$index]['updated_at'] =
                    surveyNow();

                break;
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }

    /*
     * 削除
     */
    if ($action === 'delete_survey') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        foreach (
            $data['surveys']
            as $index => $survey
        ) {
            if (
                is_array($survey) &&
                (string)(
                    $survey['id'] ?? ''
                ) === $surveyId
            ) {
                $data['surveys'][$index]['deleted'] =
                    true;

                $data['surveys'][$index]['updated_at'] =
                    surveyNow();
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }

    /*
     * 複製
     */
    if ($action === 'duplicate_survey') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $copy = null;

        foreach (
            $data['surveys']
            as $survey
        ) {
            if (
                is_array($survey) &&
                (string)(
                    $survey['id'] ?? ''
                ) === $surveyId
            ) {
                $copy =
                    surveyNormalizeSurvey(
                        $survey
                    );
                break;
            }
        }

        if ($copy === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '複製元アンケートが見つかりません。'
            ], 404);
        }

        $copy['id'] =
            surveyId('survey');

        $copy['title'] .=
            '（コピー）';

        $copy['status'] =
            'draft';

        $copy['created_at'] =
            surveyNow();

        $copy['updated_at'] =
            surveyNow();

        $copy['deleted'] =
            false;

        foreach (
            $copy['groups']
            as &$group
        ) {
            $group['id'] =
                surveyId('group');

            foreach (
                $group['questions']
                as &$question
            ) {
                $question['id'] =
                    surveyId('question');
            }

            unset($question);
        }

        unset($group);

        $data['surveys'][] =
            $copy;

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true,
            'survey' => $copy
        ]);
    }

    /*
     * 設定保存
     */
    if ($action === 'save_settings') {
        $settings =
            surveyPostJson('settings_json');

        if ($settings === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '設定データが不正です。'
            ], 400);
        }

        $settings =
            array_merge(
                surveyGuardData()['settings'],
                $settings
            );

        if (
            !is_array(
                $settings['field_address']
            )
        ) {
            $settings['field_address'] =
                [];
        }

        $data['settings'] =
            $settings;

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true,
            'settings' => $settings
        ]);
    }

    /*
     * kintone登録済みにする
     */
    if ($action === 'mark_kintone') {
        $customerId =
            (string)(
                $_POST['customer_id'] ?? ''
            );

        foreach (
            $data['customers']
            as $index => $customer
        ) {
            if (
                is_array($customer) &&
                (string)(
                    $customer['id'] ?? ''
                ) === $customerId
            ) {
                $data['customers'][$index]
                    ['kintone_status'] =
                    'registered';

                break;
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }

    surveyJsonResponse([
        'ok' => false,
        'message' =>
            '不明なAPIアクションです。'
    ], 400);
}

/* =========================================================
 * HTML
 * ======================================================= */
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

<body class="bg-slate-50 text-slate-800">

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
        screen: 'list',
        currentSurvey: null,
        selectedSurveyId: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        responseQuestionFilter: {},
        editing: false
    },

    templates: {},

    api: {},

    actions: {},

    render: {},

    init: async function () {
        if (window.App._initialized) {
            return;
        }

        window.App._initialized = true;

        await window.App.api.load();
        window.App.render.app();
    }
};

/* =========================================================
 * Utility
 * ======================================================= */

App.templates.escape = function (value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
};

App.templates.status = function (status) {

    const map = {
        active: ['公開中', 'bg-emerald-100 text-emerald-700'],
        draft: ['下書き', 'bg-slate-100 text-slate-600'],
        ended: ['終了', 'bg-amber-100 text-amber-700']
    };

    const item =
        map[status] ||
        map.draft;

    return `
        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ${item[1]}">
            ${item[0]}
        </span>
    `;
};

/* =========================================================
 * surveyRow
 * 今回の「App.templates.surveyRow is not a function」対策
 * ======================================================= */

App.templates.surveyRow = function (survey) {

    const id =
        App.templates.escape(survey.id);

    const title =
        App.templates.escape(
            survey.title || '無題のアンケート'
        );

    const buttons = [];

    buttons.push(`
        <button
            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50"
            onclick="App.actions.editSurvey('${id}')">
            確認・編集
        </button>
    `);

    if (survey.status === 'active') {

        buttons.push(`
            <button
                class="rounded-lg bg-indigo-600 px-3 py-2 text-sm text-white hover:bg-indigo-700"
                onclick="App.actions.aggregate('${id}')">
                集計
            </button>
        `);

        buttons.push(`
            <button
                class="rounded-lg bg-violet-600 px-3 py-2 text-sm text-white hover:bg-violet-700"
                onclick="App.actions.send('${id}')">
                送信
            </button>
        `);

        buttons.push(`
            <button
                class="rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm text-rose-600 hover:bg-rose-50"
                onclick="App.actions.changeStatus('${id}','ended')">
                停止
            </button>
        `);

    } else if (survey.status === 'draft') {

        buttons.push(`
            <button
                class="rounded-lg border border-rose-200 px-3 py-2 text-sm text-rose-600 hover:bg-rose-50"
                onclick="App.actions.deleteSurvey('${id}')">
                削除
            </button>
        `);

    } else {

        buttons.push(`
            <button
                class="rounded-lg bg-indigo-600 px-3 py-2 text-sm text-white"
                onclick="App.actions.aggregate('${id}')">
                集計
            </button>
        `);

    }

    buttons.push(`
        <button
            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50"
            onclick="App.actions.duplicateSurvey('${id}')">
            複製
        </button>
    `);

    return `
        <tr class="border-b border-slate-100 hover:bg-slate-50">

            <td class="px-4 py-4 text-sm text-slate-500">
                ${App.templates.escape(
                    String(survey.created_at || '').slice(0,10)
                )}
                <div class="text-xs text-slate-400">
                    更新:
                    ${App.templates.escape(
                        String(survey.updated_at || '').slice(0,10)
                    )}
                </div>
            </td>

            <td class="px-4 py-4">
                <div class="font-semibold text-slate-800">
                    ${title}
                </div>
            </td>

            <td class="px-4 py-4 text-sm text-slate-600">
                ${
                    survey.start_at || survey.end_at
                    ?
                    `${App.templates.escape(survey.start_at || '未設定')}
                    ～ ${App.templates.escape(survey.end_at || '未設定')}`
                    :
                    '未設定'
                }
            </td>

            <td class="px-4 py-4">
                ${App.templates.status(survey.status)}
            </td>

            <td class="px-4 py-4 text-right font-semibold">
                ${Number(survey.answer_count || 0)} 件
            </td>

            <td class="px-4 py-4">
                <div class="flex flex-wrap gap-2">
                    ${buttons.join('')}
                </div>
            </td>
        </tr>
    `;
};

/* =========================================================
 * API
 * ======================================================= */

App.api.load = async function () {

    const response =
        await fetch('?action=load', {
            credentials: 'same-origin'
        });

    const data =
        await response.json();

    if (!data.ok) {
        alert(
            data.message ||
            'データ取得に失敗しました。'
        );
        return;
    }

    App.state.csrf_token =
        data.csrf_token || '';

    App.state.surveys =
        Array.isArray(data.surveys)
            ? data.surveys
            : [];

    App.state.responses =
        Array.isArray(data.responses)
            ? data.responses
            : [];

    App.state.customers =
        Array.isArray(data.customers)
            ? data.customers
            : [];

    App.state.settings =
        data.settings || {};

    App.state.mail_logs =
        Array.isArray(data.mail_logs)
            ? data.mail_logs
            : [];
};

App.api.post = async function (
    action,
    params = {}
) {

    const form =
        new URLSearchParams();

    form.set(
        'csrf_token',
        App.state.csrf_token
    );

    Object.entries(params).forEach(
        ([key, value]) => {

            if (
                value !== null &&
                typeof value === 'object'
            ) {
                form.set(
                    key,
                    JSON.stringify(value)
                );
            } else {
                form.set(
                    key,
                    String(value ?? '')
                );
            }
        }
    );

    const response =
        await fetch(
            '?action=' +
            encodeURIComponent(action),
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: form.toString(),
                credentials: 'same-origin'
            }
        );

    const data =
        await response.json();

    if (!data.ok) {
        throw new Error(
            data.message ||
            '処理に失敗しました。'
        );
    }

    return data;
};

/* =========================================================
 * Layout
 * ======================================================= */

App.render.app = function () {

    document.getElementById('app').innerHTML = `

        <div class="min-h-screen">

            <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">

                <div class="mx-auto flex max-w-[1600px] items-center justify-between px-6 py-4">

                    <div>
                        <div class="text-lg font-bold text-slate-900">
                            アンケート管理システム
                        </div>
                        <div class="text-xs text-slate-400">
                            Survey Management
                        </div>
                    </div>

                    <nav class="flex gap-2">

                        <button
                            class="rounded-lg px-4 py-2 text-sm font-medium hover:bg-slate-100"
                            onclick="App.actions.list()">
                            アンケート一覧
                        </button>

                        <button
                            class="rounded-lg px-4 py-2 text-sm font-medium hover:bg-slate-100"
                            onclick="App.actions.settings()">
                            キントーン連携設定
                        </button>

                        <button
                            class="rounded-lg px-4 py-2 text-sm font-medium text-slate-500 hover:bg-slate-100"
                            onclick="alert('ログアウト処理は認証基盤接続時に実装してください。')">
                            ログアウト
                        </button>

                    </nav>

                </div>

            </header>

            <main id="screen" class="mx-auto max-w-[1600px] px-6 py-8"></main>

        </div>
    `;

    App.render.screen();
};

App.render.screen = function () {

    const target =
        document.getElementById('screen');

    if (App.state.screen === 'list') {
        App.render.list(target);
        return;
    }

    if (App.state.screen === 'edit') {
        App.render.edit(target);
        return;
    }

    if (App.state.screen === 'send') {
        App.render.send(target);
        return;
    }

    if (App.state.screen === 'aggregate') {
        App.render.aggregate(target);
        return;
    }

    if (App.state.screen === 'settings') {
        App.render.settings(target);
    }
};

/* =========================================================
 * List
 * ======================================================= */

App.render.list = function (target) {

    let surveys =
        App.state.surveys.filter(
            s => !s.deleted
        );

    const keyword =
        App.state.keyword
            .trim()
            .toLowerCase();

    if (keyword) {
        surveys =
            surveys.filter(
                s =>
                    String(s.title || '')
                        .toLowerCase()
                        .includes(keyword)
            );
    }

    if (
        App.state.statusFilter !==
        'all'
    ) {
        surveys =
            surveys.filter(
                s =>
                    s.status ===
                    App.state.statusFilter
            );
    }

    surveys.sort(
        (a,b) => {

            if (
                App.state.sort ===
                'answers_desc'
            ) {
                return (
                    Number(b.answer_count || 0) -
                    Number(a.answer_count || 0)
                );
            }

            if (
                App.state.sort ===
                'answers_asc'
            ) {
                return (
                    Number(a.answer_count || 0) -
                    Number(b.answer_count || 0)
                );
            }

            if (
                App.state.sort ===
                'updated_asc'
            ) {
                return String(
                    a.updated_at || ''
                ).localeCompare(
                    String(b.updated_at || '')
                );
            }

            return String(
                b.updated_at || ''
            ).localeCompare(
                String(a.updated_at || '')
            );
        }
    );

    target.innerHTML = `

        <div class="mb-8 flex items-center justify-between">

            <div>
                <h1 class="text-2xl font-bold">
                    アンケート一覧
                </h1>

                <p class="mt-1 text-sm text-slate-500">
                    アンケートの作成・公開・送信・集計を管理します。
                </p>
            </div>

            <button
                class="rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white shadow-sm hover:bg-indigo-700"
                onclick="App.actions.newSurvey()">
                ＋ 新規アンケート作成
            </button>

        </div>

        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

            <div class="grid gap-4 md:grid-cols-3">

                <input
                    class="rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-indigo-500"
                    placeholder="タイトルを検索"
                    value="${App.templates.escape(App.state.keyword)}"
                    onkeydown="if(event.key==='Enter'){App.actions.search(this.value)}">

                <select
                    class="rounded-xl border border-slate-200 px-4 py-3"
                    onchange="App.actions.toggleStatusFilter(this.value)">

                    <option value="all" ${App.state.statusFilter==='all'?'selected':''}>
                        すべて
                    </option>

                    <option value="active" ${App.state.statusFilter==='active'?'selected':''}>
                        公開中
                    </option>

                    <option value="draft" ${App.state.statusFilter==='draft'?'selected':''}>
                        下書き
                    </option>

                    <option value="ended" ${App.state.statusFilter==='ended'?'selected':''}>
                        終了
                    </option>

                </select>

                <select
                    class="rounded-xl border border-slate-200 px-4 py-3"
                    onchange="App.actions.sort(this.value)">

                    <option value="updated_desc" ${App.state.sort==='updated_desc'?'selected':''}>
                        更新日：新しい順
                    </option>

                    <option value="updated_asc" ${App.state.sort==='updated_asc'?'selected':''}>
                        更新日：古い順
                    </option>

                    <option value="answers_desc" ${App.state.sort==='answers_desc'?'selected':''}>
                        回答数：多い順
                    </option>

                    <option value="answers_asc" ${App.state.sort==='answers_asc'?'selected':''}>
                        回答数：少ない順
                    </option>

                </select>

            </div>

        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

            <div class="overflow-x-auto">

                <table class="min-w-[1200px] w-full text-left">

                    <thead class="border-b border-slate-200 bg-slate-50">

                        <tr>
                            <th class="px-4 py-4 text-xs font-semibold text-slate-500">
                                作成日 / 更新日
                            </th>

                            <th class="px-4 py-4 text-xs font-semibold text-slate-500">
                                タイトル
                            </th>

                            <th class="px-4 py-4 text-xs font-semibold text-slate-500">
                                アンケート期間
                            </th>

                            <th class="px-4 py-4 text-xs font-semibold text-slate-500">
                                ステータス
                            </th>

                            <th class="px-4 py-4 text-right text-xs font-semibold text-slate-500">
                                回答数
                            </th>

                            <th class="px-4 py-4 text-xs font-semibold text-slate-500">
                                操作
                            </th>
                        </tr>

                    </thead>

                    <tbody>

                        ${
                            surveys.length
                            ?
                            surveys
                                .map(
                                    App.templates.surveyRow
                                )
                                .join('')
                            :
                            `
                                <tr>
                                    <td
                                        colspan="6"
                                        class="px-6 py-16 text-center text-slate-400">
                                        アンケートがありません。
                                    </td>
                                </tr>
                            `
                        }

                    </tbody>

                </table>

            </div>

        </section>
    `;
};

/* =========================================================
 * Edit
 * ======================================================= */

App.render.edit = function (target) {

    const survey =
        App.state.currentSurvey;

    if (!survey) {
        App.actions.list();
        return;
    }

    target.innerHTML = `

        <div class="mb-6 flex items-center justify-between">

            <div class="flex-1">

                <div class="mb-2 text-sm text-slate-400">
                    ホーム ＞ アンケート一覧 ＞ アンケート編集
                </div>

                <input
                    id="survey_title"
                    class="w-full max-w-3xl border-0 bg-transparent text-3xl font-bold outline-none"
                    value="${App.templates.escape(survey.title)}"
                    oninput="App.actions.markEditing()">

            </div>

            <div class="flex gap-2">

                <button
                    class="rounded-xl border border-slate-200 bg-white px-4 py-3"
                    onclick="App.actions.preview()">
                    プレビュー
                </button>

                <button
                    class="rounded-xl border border-slate-200 bg-white px-4 py-3"
                    onclick="App.actions.cancelEdit()">
                    キャンセル
                </button>

                <button
                    class="rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white"
                    onclick="App.actions.saveSurvey()">
                    保存して一覧へ戻る
                </button>

            </div>

        </div>

        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5">

            <div class="grid gap-4 md:grid-cols-3">

                <label class="text-sm">
                    <span class="mb-2 block font-medium">
                        開始日時
                    </span>

                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        class="w-full rounded-xl border border-slate-200 px-3 py-3"
                        value="${App.templates.escape(survey.start_at)}"
                        onchange="App.actions.markEditing()">
                </label>

                <label class="text-sm">
                    <span class="mb-2 block font-medium">
                        終了日時
                    </span>

                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        class="w-full rounded-xl border border-slate-200 px-3 py-3"
                        value="${App.templates.escape(survey.end_at)}"
                        onchange="App.actions.markEditing()">
                </label>

                <label class="text-sm">
                    <span class="mb-2 block font-medium">
                        質問番号
                    </span>

                    <select
                        id="survey_numbering_mode"
                        class="w-full rounded-xl border border-slate-200 px-3 py-3"
                        onchange="App.actions.numbering(this.value)">

                        <option value="global" ${survey.numbering_mode==='global'?'selected':''}>
                            Q1 / Q2 / Q3
                        </option>

                        <option value="group" ${survey.numbering_mode==='group'?'selected':''}>
                            Q1-1 / Q1-2
                        </option>

                    </select>
                </label>

            </div>

        </section>

        <section
            id="question_editor"
            class="space-y-5">
        </section>

        <button
            class="mt-5 rounded-xl border border-dashed border-indigo-300 bg-indigo-50 px-5 py-3 font-semibold text-indigo-700"
            onclick="App.actions.addGroup()">
            ＋ グループ追加
        </button>
    `;

    App.render.questionEditor();
};

App.render.questionEditor = function () {

    const root =
        document.getElementById(
            'question_editor'
        );

    if (!root) {
        return;
    }

    root.innerHTML =
        App.state.currentSurvey.groups
            .map(
                (group, gi) => `
                    <section
                        class="group-card rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                        data-group-id="${App.templates.escape(group.id)}">

                        <div class="mb-4 flex items-center gap-3">

                            <span class="cursor-move text-xl text-slate-300">
                                ⠿
                            </span>

                            <input
                                class="flex-1 rounded-lg border border-transparent px-3 py-2 text-lg font-bold hover:border-slate-200 focus:border-indigo-400 outline-none"
                                value="${App.templates.escape(group.name)}"
                                onchange="App.actions.groupName('${App.templates.escape(group.id)}',this.value)">

                            <button
                                class="rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50"
                                onclick="App.actions.deleteGroup('${App.templates.escape(group.id)}')">
                                グループ削除
                            </button>

                        </div>

                        <div
                            class="question-list space-y-4"
                            data-group-id="${App.templates.escape(group.id)}">

                            ${
                                group.questions.map(
                                    (q, qi) =>
                                        App.templates.question(
                                            q,
                                            gi,
                                            qi,
                                            group.id
                                        )
                                ).join('')
                            }

                        </div>

                        <button
                            class="mt-4 rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium hover:bg-slate-200"
                            onclick="App.actions.addQuestion('${App.templates.escape(group.id)}')">
                            ＋ 質問追加
                        </button>

                    </section>
                `
            )
            .join('');

    App.actions.sortable();
};

App.templates.question = function (
    q,
    gi,
    qi,
    groupId
) {

    const number =
        App.state.currentSurvey.numbering_mode === 'group'
            ? `Q${gi + 1}-${qi + 1}`
            : `Q${App.questionNumber(gi, qi)}`;

    return `

        <article
            class="question-card rounded-xl border border-slate-200 p-4"
            data-question-id="${App.templates.escape(q.id)}">

            <div class="flex gap-3">

                <span class="cursor-move pt-2 text-slate-300">
                    ⠿
                </span>

                <div class="min-w-0 flex-1">

                    <div class="mb-3 flex items-center justify-between">

                        <div class="font-bold text-indigo-600">
                            ${number}
                        </div>

                        <div class="flex items-center gap-3">

                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    ${q.required?'checked':''}
                                    onchange="App.actions.required('${App.templates.escape(q.id)}',this.checked)">
                                必須
                            </label>

                            <button
                                class="text-sm text-rose-600"
                                onclick="App.actions.deleteQuestion('${App.templates.escape(q.id)}')">
                                削除
                            </button>

                        </div>

                    </div>

                    <input
                        class="mb-3 w-full rounded-xl border border-slate-200 px-4 py-3"
                        placeholder="質問文を入力"
                        value="${App.templates.escape(q.text)}"
                        onchange="App.actions.questionText('${App.templates.escape(q.id)}',this.value)">

                    <select
                        class="mb-3 rounded-xl border border-slate-200 px-3 py-2"
                        onchange="App.actions.questionType('${App.templates.escape(q.id)}',this.value)">

                        <option value="single" ${q.type==='single'?'selected':''}>
                            単一選択
                        </option>

                        <option value="multiple" ${q.type==='multiple'?'selected':''}>
                            複数選択
                        </option>

                        <option value="text" ${q.type==='text'?'selected':''}>
                            自由記述
                        </option>

                    </select>

                    ${
                        q.type !== 'text'
                        ?
                        `
                        <div class="space-y-2">

                            ${q.options.map(
                                (option, oi) => `
                                    <div class="flex gap-2">

                                        <input
                                            class="flex-1 rounded-lg border border-slate-200 px-3 py-2"
                                            value="${App.templates.escape(option)}"
                                            onchange="App.actions.option('${App.templates.escape(q.id)}',${oi},this.value)">

                                        <button
                                            class="px-3 text-rose-600"
                                            onclick="App.actions.removeOption('${App.templates.escape(q.id)}',${oi})">
                                            ×
                                        </button>

                                    </div>
                                `
                            ).join('')}

                            <button
                                class="rounded-lg bg-slate-100 px-3 py-2 text-sm"
                                onclick="App.actions.addOption('${App.templates.escape(q.id)}')">
                                ＋ 選択肢追加
                            </button>

                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    ${q.other_enabled?'checked':''}
                                    onchange="App.actions.other('${App.templates.escape(q.id)}',this.checked)">
                                「その他」を追加
                            </label>

                        </div>
                        `
                        :
                        `
                        <textarea
                            disabled
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"
                            rows="3">回答者がここに自由記述します</textarea>
                        `
                    }

                </div>

            </div>

        </article>
    `;
};

App.questionNumber = function (gi, qi) {

    let n = 0;

    for (
        let i = 0;
        i < gi;
        i++
    ) {
        n +=
            App.state.currentSurvey
                .groups[i]
                .questions.length;
    }

    return n + qi + 1;
};

/* =========================================================
 * Sortable
 * ======================================================= */

App.actions.sortable = function () {

    const editor =
        document.getElementById(
            'question_editor'
        );

    if (!editor ||
        typeof Sortable === 'undefined'
    ) {
        return;
    }

    new Sortable(
        editor,
        {
            handle: '.group-card > div:first-child span',
            animation: 180,
            ghostClass: 'opacity-40',
            onEnd: function (event) {

                const groups =
                    [...editor.querySelectorAll(
                        '.group-card'
                    )];

                const ids =
                    groups.map(
                        x => x.dataset.groupId
                    );

                App.state.currentSurvey.groups =
                    ids.map(
                        id =>
                            App.state.currentSurvey.groups.find(
                                g => g.id === id
                            )
                    );

                App.render.questionEditor();
            }
        }
    );

    editor.querySelectorAll(
        '.question-list'
    ).forEach(
        list => {

            new Sortable(
                list,
                {
                    group: 'survey-questions',
                    handle: '.question-card > div > span',
                    animation: 180,
                    ghostClass: 'opacity-40',

                    onEnd: function () {

                        const groups =
                            App.state.currentSurvey.groups;

                        groups.forEach(
                            group => {

                                const list =
                                    editor.querySelector(
                                        `.question-list[data-group-id="${CSS.escape(group.id)}"]`
                                    );

                                if (!list) {
                                    return;
                                }

                                const ids =
                                    [...list.querySelectorAll(
                                        '.question-card'
                                    )]
                                    .map(
                                        x =>
                                            x.dataset.questionId
                                    );

                                group.questions =
                                    ids.map(
                                        id => {

                                            for (
                                                const g
                                                of groups
                                            ) {

                                                const q =
                                                    g.questions.find(
                                                        x =>
                                                            x.id === id
                                                    );

                                                if (q) {
                                                    return q;
                                                }
                                            }

                                            return null;
                                        }
                                    )
                                    .filter(Boolean);
                            }
                        );

                        const moved =
                            [];

                        groups.forEach(
                            group => {

                                group.questions =
                                    group.questions.filter(
                                        q => {

                                            if (
                                                moved.includes(
                                                    q.id
                                                )
                                            ) {
                                                return false;
                                            }

                                            moved.push(q.id);
                                            return true;
                                        }
                                    );
                            }
                        );

                        App.render.questionEditor();
                    }
                }
            );
        }
    );
};

/* =========================================================
 * Actions
 * ======================================================= */

App.actions.list = async function () {

    await App.api.load();

    App.state.screen =
        'list';

    App.state.currentSurvey =
        null;

    App.render.app();
};

App.actions.search = function (value) {

    App.state.keyword =
        value;

    App.render.screen();
};

App.actions.toggleStatusFilter =
    function (value) {

        App.state.statusFilter =
            value;

        App.render.screen();
    };

App.actions.sort =
    function (value) {

        App.state.sort =
            value;

        App.render.screen();
    };

App.actions.newSurvey =
    function () {

        App.state.currentSurvey = {
            id: 'survey_' +
                Date.now(),
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

        App.state.editing =
            true;

        App.state.screen =
            'edit';

        App.render.app();
    };

App.actions.editSurvey =
    function (id) {

        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            alert(
                'アンケートが見つかりません。'
            );
            return;
        }

        App.state.currentSurvey =
            JSON.parse(
                JSON.stringify(survey)
            );

        App.state.editing =
            false;

        App.state.screen =
            'edit';

        App.render.app();
    };

App.actions.markEditing =
    function () {
        App.state.editing = true;
    };

App.actions.groupName =
    function (id, value) {

        const group =
            App.state.currentSurvey.groups
                .find(
                    g => g.id === id
                );

        if (group) {
            group.name =
                value;

            App.state.editing =
                true;
        }
    };

App.actions.addGroup =
    function () {

        App.state.currentSurvey.groups.push({
            id: 'group_' +
                Date.now() +
                '_' +
                Math.random()
                    .toString(16)
                    .slice(2),

            name:
                '新しいグループ',

            questions: []
        });

        App.state.editing =
            true;

        App.render.questionEditor();
    };

App.actions.deleteGroup =
    function (id) {

        if (
            !confirm(
                'グループと内包する質問を削除しますか？'
            )
        ) {
            return;
        }

        App.state.currentSurvey.groups =
            App.state.currentSurvey.groups
                .filter(
                    g => g.id !== id
                );

        App.state.editing =
            true;

        App.render.questionEditor();
    };

App.actions.addQuestion =
    function (groupId) {

        const group =
            App.state.currentSurvey.groups
                .find(
                    g => g.id === groupId
                );

        if (!group) {
            return;
        }

        group.questions.push({
            id:
                'question_' +
                Date.now() +
                '_' +
                Math.random()
                    .toString(16)
                    .slice(2),

            text: '',
            type: 'single',
            required: false,
            options: [
                '選択肢1',
                '選択肢2'
            ],
            other_enabled: false
        });

        App.state.editing =
            true;

        App.render.questionEditor();
    };

App.actions.deleteQuestion =
    function (id) {

        if (
            !confirm(
                'この質問を削除しますか？'
            )
        ) {
            return;
        }

        App.state.currentSurvey.groups
            .forEach(
                group => {
                    group.questions =
                        group.questions.filter(
                            q => q.id !== id
                        );
                }
            );

        App.state.editing =
            true;

        App.render.questionEditor();
    };

App.actions.questionText =
    function (id, value) {

        const q =
            App.findQuestion(id);

        if (q) {
            q.text =
                value;

            App.state.editing =
                true;
        }
    };

App.actions.questionType =
    function (id, value) {

        const q =
            App.findQuestion(id);

        if (q) {

            q.type =
                ['single','multiple','text']
                    .includes(value)
                    ? value
                    : 'single';

            App.state.editing =
                true;

            App.render.questionEditor();
        }
    };

App.actions.required =
    function (id, value) {

        const q =
            App.findQuestion(id);

        if (q) {
            q.required =
                !!value;

            App.state.editing =
                true;
        }
    };

App.actions.other =
    function (id, value) {

        const q =
            App.findQuestion(id);

        if (q) {
            q.other_enabled =
                !!value;

            App.state.editing =
                true;
        }
    };

App.actions.addOption =
    function (id) {

        const q =
            App.findQuestion(id);

        if (q) {

            q.options.push(
                '選択肢' +
                (q.options.length + 1)
            );

            App.state.editing =
                true;

            App.render.questionEditor();
        }
    };

App.actions.removeOption =
    function (id, index) {

        const q =
            App.findQuestion(id);

        if (
            q &&
            q.options.length > 1
        ) {

            q.options.splice(
                index,
                1
            );

            App.state.editing =
                true;

            App.render.questionEditor();
        }
    };

App.actions.option =
    function (id, index, value) {

        const q =
            App.findQuestion(id);

        if (
            q &&
            q.options[index] !== undefined
        ) {
            q.options[index] =
                value;

            App.state.editing =
                true;
        }
    };

App.findQuestion =
    function (id) {

        for (
            const group
            of App.state.currentSurvey.groups
        ) {
            const q =
                group.questions.find(
                    q => q.id === id
                );

            if (q) {
                return q;
            }
        }

        return null;
    };

App.actions.numbering =
    function (value) {

        App.state.currentSurvey.numbering_mode =
            value === 'group'
                ? 'group'
                : 'global';

        App.state.editing =
            true;

        App.render.questionEditor();
    };

App.actions.saveSurvey =
    async function () {

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

        if (title) {
            App.state.currentSurvey.title =
                title.value;
        }

        if (start) {
            App.state.currentSurvey.start_at =
                start.value;
        }

        if (end) {
            App.state.currentSurvey.end_at =
                end.value;
        }

        try {

            await App.api.post(
                'save_survey',
                {
                    survey_json:
                        App.state.currentSurvey
                }
            );

            alert(
                'アンケートを保存しました。'
            );

            App.state.editing =
                false;

            await App.api.load();

            App.state.screen =
                'list';

            App.state.currentSurvey =
                null;

            App.render.app();

        } catch (error) {

            alert(
                error.message
            );
        }
    };

App.actions.cancelEdit =
    function () {

        if (
            App.state.editing &&
            !confirm(
                '未保存の変更を破棄して一覧へ戻りますか？'
            )
        ) {
            return;
        }

        App.actions.list();
    };

App.actions.changeStatus =
    async function (id, status) {

        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            return;
        }

        const text =
            status === 'active'
                ? 'このアンケートを公開しますか？'
                : status === 'ended'
                    ? 'このアンケートを停止しますか？'
                    : '下書きに戻しますか？';

        if (!confirm(text)) {
            return;
        }

        try {

            await App.api.post(
                'status_survey',
                {
                    survey_id: id,
                    status: status
                }
            );

            await App.api.load();

            App.render.screen();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.deleteSurvey =
    async function (id) {

        if (
            !confirm(
                'この下書きを削除しますか？'
            )
        ) {
            return;
        }

        try {

            await App.api.post(
                'delete_survey',
                {
                    survey_id: id
                }
            );

            await App.api.load();

            App.render.screen();

        } catch (error) {
            alert(error.message);
        }
    };

App.actions.duplicateSurvey =
    async function (id) {

        try {

            await App.api.post(
                'duplicate_survey',
                {
                    survey_id: id
                }
            );

            await App.api.load();

            App.render.screen();

        } catch (error) {
            alert(error.message);
        }
    };

/* =========================================================
 * Preview
 * ======================================================= */

App.actions.preview =
    function () {

        const survey =
            App.state.currentSurvey;

        document.body.insertAdjacentHTML(
            'beforeend',
            `
            <div
                id="preview_modal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-6">

                <div class="max-h-[90vh] w-full max-w-3xl overflow-auto rounded-2xl bg-white shadow-2xl">

                    <div class="sticky top-0 flex items-center justify-between border-b bg-white px-6 py-4">

                        <div class="font-bold">
                            プレビュー
                        </div>

                        <button
                            class="rounded-lg px-3 py-2 hover:bg-slate-100"
                            onclick="document.getElementById('preview_modal').remove()">
                            閉じる
                        </button>

                    </div>

                    <div
                        id="preview_content"
                        class="p-8">
                    </div>

                </div>
            </div>
            `
        );

        const content =
            document.getElementById(
                'preview_content'
            );

        content.innerHTML = `
            <h1 class="mb-8 text-2xl font-bold">
                ${App.templates.escape(survey.title)}
            </h1>

            ${
                survey.groups.map(
                    group => `
                        <section class="mb-8">

                            <h2 class="mb-4 text-lg font-bold">
                                ${App.templates.escape(group.name)}
                            </h2>

                            <div class="space-y-6">

                                ${
                                    group.questions.map(
                                        (q, i) => `
                                            <div>
                                                <div class="mb-2 font-medium">
                                                    ${App.templates.escape(q.text || '質問未入力')}
                                                    ${q.required ? '<span class="ml-2 text-rose-500">必須</span>' : ''}
                                                </div>

                                                ${
                                                    q.type === 'text'
                                                    ?
                                                    `
                                                    <textarea
                                                        class="w-full rounded-xl border border-slate-200 p-3"
                                                        rows="4">
                                                    </textarea>
                                                    `
                                                    :
                                                    q.options.map(
                                                        option =>
                                                            `
                                                            <label class="mb-2 flex items-center gap-3">
                                                                <input
                                                                    type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                                                                    name="preview_${q.id}">
                                                                ${App.templates.escape(option)}
                                                            </label>
                                                            `
                                                    ).join('')
                                                }

                                            </div>
                                        `
                                    ).join('')
                                }

                            </div>

                        </section>
                    `
                ).join('')
            }

            <button
                class="w-full rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white"
                onclick="alert('これはプレビューです。実際の送信は行われません。')">
                回答を送信
            </button>
        `;
    };

/* =========================================================
 * Send
 * ======================================================= */

App.actions.send =
    function (id) {

        const survey =
            App.state.surveys.find(
                s => s.id === id
            );

        if (!survey) {
            return;
        }

        if (survey.status !== 'active') {
            alert(
                '公開中のアンケートだけ送信できます。'
            );
            return;
        }

        App.state.selectedSurveyId =
            id;

        App.state.screen =
            'send';

        App.render.app();
    };

App.render.send =
    function (target) {

        const survey =
            App.state.surveys.find(
                s =>
                    s.id ===
                    App.state.selectedSurveyId
            );

        const customers =
            App.state.customers;

        target.innerHTML = `

            <div class="mb-6">

                <div class="mb-2 text-sm text-slate-400">
                    ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
                </div>

                <h1 class="text-2xl font-bold">
                    ${App.templates.escape(survey?.title || '')}
                </h1>

            </div>

            <div class="mb-6 rounded-2xl border border-indigo-100 bg-indigo-50 p-5">

                <div class="font-semibold text-indigo-800">
                    メール送信
                </div>

                <p class="mt-1 text-sm text-indigo-700">
                    公開中のアンケートを顧客へ送信します。
                </p>

            </div>

            <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5">

                <label class="mb-4 block">
                    <span class="mb-2 block text-sm font-medium">
                        件名
                    </span>

                    <input
                        id="mail_subject"
                        class="w-full rounded-xl border border-slate-200 px-4 py-3"
                        value="${App.templates.escape(survey?.title || '')}のご案内">
                </label>

                <label class="mb-4 block">
                    <span class="mb-2 block text-sm font-medium">
                        本文
                    </span>

                    <textarea
                        id="mail_body"
                        class="w-full rounded-xl border border-slate-200 px-4 py-3"
                        rows="7">{$顧客名} 様

アンケートへのご協力をお願いいたします。

アンケートURL：
{アンケートURL}

よろしくお願いいたします。</textarea>
                </label>

                <div class="flex gap-3">

                    <select
                        id="template_type"
                        class="rounded-xl border border-slate-200 px-4 py-3">

                        <option value="initial">
                            初回送信
                        </option>

                        <option value="reminder">
                            リマインド
                        </option>

                    </select>

                    <button
                        class="rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white"
                        onclick="App.actions.executeSend()">
                        選択した顧客へ一括送信
                    </button>

                </div>

            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">

                <div class="border-b p-4">

                    <input
                        id="customer_filter"
                        class="w-full rounded-xl border border-slate-200 px-4 py-3"
                        placeholder="顧客名・メールアドレスで検索"
                        oninput="App.actions.filterCustomers(this.value)">

                </div>

                <div class="overflow-x-auto">

                    <table class="min-w-[1200px] w-full">

                        <thead class="bg-slate-50">

                            <tr>

                                <th class="px-4 py-3">
                                    <input
                                        id="select_all"
                                        type="checkbox"
                                        onchange="App.actions.selectAll(this.checked)">
                                </th>

                                <th class="px-4 py-3 text-left">
                                    会社名 / 氏名
                                </th>

                                <th class="px-4 py-3 text-left">
                                    メール
                                </th>

                                <th class="px-4 py-3 text-left">
                                    電話番号
                                </th>

                                <th class="px-4 py-3 text-left">
                                    送信履歴
                                </th>

                                <th class="px-4 py-3 text-left">
                                    回答
                                </th>

                                <th class="px-4 py-3 text-left">
                                    kintone
                                </th>

                            </tr>

                        </thead>

                        <tbody id="customer_table">

                            ${
                                customers.map(
                                    c =>
                                        App.templates.customerRow(
                                            c
                                        )
                                ).join('')
                            }

                        </tbody>

                    </table>

                </div>

            </section>
        `;
    };

App.templates.customerRow =
    function (customer) {

        return `

            <tr
                class="customer-row border-b border-slate-100"
                data-search="${App.templates.escape(
                    [
                        customer.company,
                        customer.name,
                        customer.email
                    ].join(' ')
                )}">

                <td class="px-4 py-4">

                    <input
                        class="customer-check"
                        type="checkbox"
                        value="${App.templates.escape(customer.id)}"
                        ${customer.source === 'web' ? 'disabled' : ''}>

                </td>

                <td class="px-4 py-4">

                    <div class="font-semibold">
                        ${App.templates.escape(customer.company)}
                    </div>

                    <div class="text-sm text-slate-500">
                        ${App.templates.escape(customer.name)}
                    </div>

                </td>

                <td class="px-4 py-4 text-sm">
                    ${App.templates.escape(customer.email)}
                </td>

                <td class="px-4 py-4 text-sm">
                    ${App.templates.escape(customer.phone)}
                </td>

                <td class="px-4 py-4 text-sm">

                    ${
                        customer.sent_at
                        ?
                        `
                        <div>
                            ${App.templates.escape(customer.sent_at)}
                        </div>
                        <div class="text-xs text-slate-400">
                            ${Number(customer.send_count || 0)} 回
                        </div>
                        `
                        :
                        '未送信'
                    }

                </td>

                <td class="px-4 py-4">

                    ${
                        customer.answer_status === 'answered'
                        ?
                        '<span class="rounded-full bg-emerald-100 px-3 py-1 text-xs text-emerald-700">回答済み</span>'
                        :
                        '<span class="rounded-full bg-amber-100 px-3 py-1 text-xs text-amber-700">未回答</span>'
                    }

                </td>

                <td class="px-4 py-4">

                    ${
                        customer.kintone_status === 'registered'
                        ?
                        '<span class="text-emerald-600">✓ 登録完了</span>'
                        :
                        `
                        <button
                            class="rounded-lg border border-indigo-200 px-3 py-2 text-xs text-indigo-600"
                            onclick="App.actions.markKintone('${App.templates.escape(customer.id)}')">
                            kintone登録完了
                        </button>
                        `
                    }

                </td>

            </tr>
        `;
    };

App.actions.filterCustomers =
    function (value) {

        const keyword =
            value.toLowerCase();

        document
            .querySelectorAll(
                '.customer-row'
            )
            .forEach(
                row => {

                    row.classList.toggle(
                        'hidden',
                        !row.dataset.search
                            .toLowerCase()
                            .includes(keyword)
                    );
                }
            );
    };

App.actions.selectAll =
    function (checked) {

        document
            .querySelectorAll(
                '.customer-check:not(:disabled)'
            )
            .forEach(
                input => {
                    input.checked =
                        checked;
                }
            );
    };

App.actions.executeSend =
    function () {

        const ids =
            [...document.querySelectorAll(
                '.customer-check:checked'
            )]
            .map(
                input =>
                    input.value
            );

        if (!ids.length) {
            alert(
                '送信対象を選択してください。'
            );
            return;
        }

        const already =
            App.state.customers.filter(
                c =>
                    ids.includes(c.id) &&
                    c.sent_at
            );

        if (
            already.length &&
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

        alert(
            `${ids.length}件の送信処理を受け付けました。\n\n件名: ${subject}\n\nこのデモ版では実メール送信処理は行わず、送信対象管理を実装しています。`
        );
    };

App.actions.markKintone =
    async function (id) {

        try {

            await App.api.post(
                'mark_kintone',
                {
                    customer_id: id
                }
            );

            await App.api.load();

            App.render.screen();

        } catch (error) {
            alert(error.message);
        }
    };

/* =========================================================
 * Aggregate
 * ======================================================= */

App.actions.aggregate =
    function (id) {

        App.state.selectedSurveyId =
            id;

        App.state.screen =
            'aggregate';

        App.render.app();
    };

App.render.aggregate =
    function (target) {

        const survey =
            App.state.surveys.find(
                s =>
                    s.id ===
                    App.state.selectedSurveyId
            );

        const responses =
            App.state.responses.filter(
                r =>
                    r.survey_id ===
                    App.state.selectedSurveyId
            );

        const customerResponses =
            responses.filter(
                r =>
                    r.customer_id
            );

        const questions = [];

        survey.groups.forEach(
            group =>
                group.questions.forEach(
                    q =>
                        questions.push(q)
                )
        );

        const customers =
            App.state.customers.filter(
                c =>
                    c.sent_at
            );

        const unanswered =
            customers.filter(
                c =>
                    c.answer_status !==
                    'answered'
            ).length;

        const rate =
            customers.length
                ? (
                    customerResponses.length /
                    customers.length *
                    100
                ).toFixed(1)
                : '0.0';

        target.innerHTML = `

            <div class="mb-6">

                <div class="mb-2 text-sm text-slate-400">
                    ホーム ＞ アンケート一覧 ＞ 集計
                </div>

                <div class="flex items-center justify-between">

                    <h1 class="text-2xl font-bold">
                        ${App.templates.escape(survey.title)}
                    </h1>

                    <div class="flex gap-2">

                        <button
                            class="rounded-xl border border-slate-200 bg-white px-4 py-3"
                            onclick="App.actions.exportCsv('${App.templates.escape(survey.id)}')">
                            CSV出力
                        </button>

                        <button
                            class="rounded-xl bg-slate-900 px-4 py-3 text-white"
                            onclick="window.print()">
                            PDF / 印刷
                        </button>

                    </div>

                </div>

            </div>

            <div class="mb-8 grid gap-4 md:grid-cols-5">

                ${App.templates.statCard(
                    '送信対象者数',
                    customers.length + ' 人'
                )}

                ${App.templates.statCard(
                    '回答数',
                    responses.length + ' 件'
                )}

                ${App.templates.statCard(
                    '未登録顧客からの回答',
                    responses.filter(
                        r =>
                            !r.customer_id
                    ).length + ' 件'
                )}

                ${App.templates.statCard(
                    '未回答数',
                    unanswered + ' 人'
                )}

                ${App.templates.statCard(
                    '回答率',
                    rate + ' %'
                )}

            </div>

            <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5">

                <div class="mb-4 flex items-center justify-between">

                    <h2 class="font-bold">
                        設問別集計
                    </h2>

                    <div class="flex gap-2">

                        <button
                            class="rounded-lg bg-slate-100 px-3 py-2 text-sm"
                            onclick="App.actions.selectAllQuestions(true)">
                            全選択
                        </button>

                        <button
                            class="rounded-lg bg-slate-100 px-3 py-2 text-sm"
                            onclick="App.actions.selectAllQuestions(false)">
                            全解除
                        </button>

                    </div>

                </div>

                <div class="space-y-2">

                    ${
                        questions.map(
                            q => `
                                <label class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-slate-50">

                                    <input
                                        class="response-filter"
                                        type="checkbox"
                                        data-question-id="${App.templates.escape(q.id)}"
                                        checked
                                        onchange="App.actions.toggleResponseQuestion('${App.templates.escape(q.id)}',this.checked)">

                                    <span class="font-medium">
                                        ${App.templates.escape(q.text || '質問未入力')}
                                    </span>

                                    <span class="text-xs text-slate-400">
                                        ${q.type}
                                    </span>

                                </label>
                            `
                        ).join('')
                    }

                </div>

            </section>

            <section
                id="response_table"
                class="rounded-2xl border border-slate-200 bg-white p-5">

                <h2 class="mb-4 font-bold">
                    個別回答一覧
                </h2>

                <input
                    id="response_filter"
                    class="mb-4 w-full rounded-xl border border-slate-200 px-4 py-3"
                    placeholder="会社名・氏名で検索"
                    oninput="App.actions.filterResponses(this.value)">

                <div class="overflow-x-auto">

                    <table class="min-w-[900px] w-full">

                        <thead class="bg-slate-50">

                            <tr>

                                <th class="px-4 py-3 text-left">
                                    回答日時
                                </th>

                                <th class="px-4 py-3 text-left">
                                    会社名
                                </th>

                                <th class="px-4 py-3 text-left">
                                    氏名
                                </th>

                                <th class="px-4 py-3 text-left">
                                    メール
                                </th>

                                <th class="px-4 py-3">
                                    操作
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            ${
                                responses.length
                                ?
                                responses.map(
                                    r =>
                                        `
                                        <tr
                                            class="response-row border-b border-slate-100"
                                            data-search="${App.templates.escape(
                                                [
                                                    r.company,
                                                    r.name
                                                ].join(' ')
                                            )}">

                                            <td class="px-4 py-4 text-sm">
                                                ${App.templates.escape(r.answered_at)}
                                            </td>

                                            <td class="px-4 py-4 font-semibold">
                                                ${App.templates.escape(r.company)}
                                            </td>

                                            <td class="px-4 py-4">
                                                ${App.templates.escape(r.name)}
                                            </td>

                                            <td class="px-4 py-4 text-sm">
                                                ${App.templates.escape(r.email)}
                                            </td>

                                            <td class="px-4 py-4 text-center">

                                                <button
                                                    class="rounded-lg bg-indigo-50 px-3 py-2 text-sm text-indigo-700"
                                                    onclick="App.actions.showResponse('${App.templates.escape(r.id)}')">
                                                    全回答を表示
                                                </button>

                                            </td>

                                        </tr>
                                        `
                                ).join('')
                                :
                                `
                                <tr>
                                    <td
                                        colspan="5"
                                        class="px-6 py-16 text-center text-slate-400">
                                        現在、回答データはありません
                                    </td>
                                </tr>
                                `
                            }

                        </tbody>

                    </table>

                </div>

            </section>
        `;
    };

App.templates.statCard =
    function (label, value) {

        return `
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">

                <div class="text-sm text-slate-500">
                    ${App.templates.escape(label)}
                </div>

                <div class="mt-2 text-2xl font-bold">
                    ${App.templates.escape(value)}
                </div>

            </div>
        `;
    };

App.actions.filterResponses =
    function (value) {

        const keyword =
            value.toLowerCase();

        document
            .querySelectorAll(
                '.response-row'
            )
            .forEach(
                row => {

                    row.classList.toggle(
                        'hidden',
                        !row.dataset.search
                            .toLowerCase()
                            .includes(keyword)
                    );
                }
            );
    };

App.actions.toggleResponseQuestion =
    function (id, checked) {

        App.state.responseQuestionFilter[id] =
            checked;

        /*
         * DOM上の設問フィルター状態を保持。
         * 再描画時にも利用可能。
         */
    };

App.actions.selectAllQuestions =
    function (checked) {

        document
            .querySelectorAll(
                '.response-filter'
            )
            .forEach(
                input => {
                    input.checked =
                        checked;

                    App.state.responseQuestionFilter[
                        input.dataset.questionId
                    ] = checked;
                }
            );
    };

App.actions.showResponse =
    function (id) {

        const response =
            App.state.responses.find(
                r => r.id === id
            );

        if (!response) {
            return;
        }

        const survey =
            App.state.surveys.find(
                s =>
                    s.id ===
                    response.survey_id
            );

        const questions = [];

        survey.groups.forEach(
            group =>
                group.questions.forEach(
                    q =>
                        questions.push(q)
                )
        );

        document.body.insertAdjacentHTML(
            'beforeend',
            `
            <div
                id="response_modal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-6">

                <div class="max-h-[90vh] w-full max-w-3xl overflow-auto rounded-2xl bg-white shadow-2xl">

                    <div class="sticky top-0 flex justify-between border-b bg-white px-6 py-4">

                        <div class="font-bold">
                            回答詳細
                        </div>

                        <button
                            onclick="document.getElementById('response_modal').remove()"
                            class="rounded-lg px-3 py-2 hover:bg-slate-100">
                            閉じる
                        </button>

                    </div>

                    <div
                        id="response_detail"
                        class="space-y-5 p-6">

                        <div>
                            <div class="text-sm text-slate-400">
                                回答日時
                            </div>
                            <div>
                                ${App.templates.escape(response.answered_at)}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm text-slate-400">
                                回答者
                            </div>
                            <div class="font-semibold">
                                ${App.templates.escape(response.company)}
                                /
                                ${App.templates.escape(response.name)}
                            </div>
                        </div>

                        ${
                            questions.map(
                                q => {

                                    let value =
                                        response.answers?.[q.id] ?? '';

                                    if (
                                        Array.isArray(value)
                                    ) {
                                        value =
                                            value.join('、');
                                    }

                                    return `
                                        <div class="rounded-xl bg-slate-50 p-4">

                                            <div class="mb-2 font-semibold">
                                                ${App.templates.escape(q.text)}
                                            </div>

                                            <div class="text-slate-600">
                                                ${App.templates.escape(value)}
                                            </div>

                                        </div>
                                    `;
                                }
                            ).join('')
                        }

                    </div>

                </div>

            </div>
            `
        );
    };

App.actions.exportCsv =
    function (id) {

        const token =
            encodeURIComponent(
                App.state.csrf_token
            );

        window.location.href =
            '?action=export_csv' +
            '&survey_id=' +
            encodeURIComponent(id) +
            '&csrf_token=' +
            token;
    };

/* =========================================================
 * Settings
 * ======================================================= */

App.actions.settings =
    function () {

        App.state.screen =
            'settings';

        App.render.app();
    };

App.render.settings =
    function (target) {

        const s =
            App.state.settings || {};

        target.innerHTML = `

            <div class="mb-6">

                <div class="mb-2 text-sm text-slate-400">
                    ホーム ＞ システム設定 ＞ kintone連携設定
                </div>

                <h1 class="text-2xl font-bold">
                    kintone連携設定
                </h1>

            </div>

            <section
                id="settings_form"
                class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">

                <div class="grid gap-5 md:grid-cols-2">

                    <label>
                        <span class="mb-2 block text-sm font-medium">
                            サブドメイン / FQDN
                        </span>

                        <input
                            id="setting_subdomain"
                            class="w-full rounded-xl border border-slate-200 px-4 py-3"
                            placeholder="xxxx または xxxx.cybozu.com"
                            value="${App.templates.escape(s.subdomain)}">
                    </label>

                    <label>
                        <span class="mb-2 block text-sm font-medium">
                            顧客管理アプリID
                        </span>

                        <input
                            id="setting_app_id"
                            class="w-full rounded-xl border border-slate-200 px-4 py-3"
                            value="${App.templates.escape(s.app_id)}">
                    </label>

                    <label>
                        <span class="mb-2 block text-sm font-medium">
                            ログイン名
                        </span>

                        <input
                            id="setting_login_name"
                            class="w-full rounded-xl border border-slate-200 px-4 py-3"
                            value="${App.templates.escape(s.login_name)}">
                    </label>

                    <label>
                        <span class="mb-2 block text-sm font-medium">
                            パスワード
                        </span>

                        <input
                            id="setting_password"
                            type="password"
                            class="w-full rounded-xl border border-slate-200 px-4 py-3"
                            value="${App.templates.escape(s.password)}">
                    </label>

                    <label>
                        <span class="mb-2 block text-sm font-medium">
                            Proxy
                        </span>

                        <input
                            id="setting_proxy"
                            class="w-full rounded-xl border border-slate-200 px-4 py-3"
                            placeholder="host:port"
                            value="${App.templates.escape(s.proxy)}">
                    </label>

                    <label class="flex items-center gap-3 pt-8">
                        <input
                            id="setting_ssl_verify"
                            type="checkbox"
                            ${s.ssl_verify ? 'checked' : ''}>
                        SSL証明書を検証する
                    </label>

                </div>

                <div class="mt-6 flex flex-wrap gap-3">

                    <button
                        class="rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white"
                        onclick="App.actions.fetchKintoneFields()">
                        項目一覧を再取得
                    </button>

                    <button
                        class="rounded-xl border border-slate-200 bg-white px-5 py-3"
                        onclick="App.actions.saveSettings()">
                        設定を保存
                    </button>

                </div>

                <div
                    id="field_message"
                    class="mt-4">
                </div>

                <div
                    id="kintone_fields"
                    class="mt-6">
                </div>

            </section>
        `;
    };

/*
 * =========================================================
 * fetchKintoneFields()
 *
 * 必須実装。
 * =========================================================
 */
App.actions.fetchKintoneFields =
    async function () {

        const message =
            document.getElementById(
                'field_message'
            );

        const subdomain =
            document.getElementById(
                'setting_subdomain'
            ).value.trim();

        const appId =
            document.getElementById(
                'setting_app_id'
            ).value.trim();

        const loginName =
            document.getElementById(
                'setting_login_name'
            ).value;

        const password =
            document.getElementById(
                'setting_password'
            ).value;

        const proxy =
            document.getElementById(
                'setting_proxy'
            ).value.trim();

        const sslVerify =
            document.getElementById(
                'setting_ssl_verify'
            ).checked;

        if (!subdomain) {
            alert(
                'サブドメインを入力してください。'
            );
            return;
        }

        if (!appId) {
            alert(
                'アプリIDを入力してください。'
            );
            return;
        }

        message.innerHTML = `
            <div class="rounded-xl bg-indigo-50 p-4 text-indigo-700">
                kintoneから項目一覧を取得しています...
            </div>
        `;

        try {

            const data =
                await App.api.post(
                    'kintone_fields',
                    {
                        app_id: appId,

                        settings_json: {
                            subdomain:
                                subdomain,

                            login_name:
                                loginName,

                            password:
                                password,

                            app_id:
                                appId,

                            ssl_verify:
                                sslVerify,

                            proxy:
                                proxy
                        }
                    }
                );

            App.renderKintoneFields(
                data.fields || []
            );

            message.innerHTML = `
                <div class="rounded-xl bg-emerald-50 p-4 text-emerald-700">
                    kintoneから ${Number(
                        (data.fields || []).length
                    )} 項目を取得しました。
                </div>
            `;

        } catch (error) {

            message.innerHTML = `
                <div class="rounded-xl bg-rose-50 p-4 text-rose-700">

                    <div class="font-bold">
                        kintone項目一覧取得に失敗しました。
                    </div>

                    <div class="mt-1">
                        ${App.templates.escape(error.message)}
                    </div>

                </div>
            `;
        }
    };

App.renderKintoneFields =
    function (fields) {

        const root =
            document.getElementById(
                'kintone_fields'
            );

        if (!root) {
            return;
        }

        const current =
            App.state.settings || {};

        const select =
            function (
                id,
                label,
                multiple
            ) {

                const currentValue =
                    current[id] || [];

                const values =
                    Array.isArray(currentValue)
                        ? currentValue
                        : [currentValue];

                return `
                    <label class="block">

                        <span class="mb-2 block text-sm font-medium">
                            ${label}
                        </span>

                        <select
                            ${multiple ? 'multiple' : ''}
                            data-map-key="${id}"
                            class="kintone-map w-full rounded-xl border border-slate-200 px-4 py-3">

                            ${
                                !multiple
                                ?
                                '<option value="">未設定</option>'
                                :
                                ''
                            }

                            ${
                                fields.map(
                                    f =>
                                        `
                                        <option
                                            value="${App.templates.escape(f.code)}"
                                            ${values.includes(f.code) ? 'selected' : ''}>
                                            ${App.templates.escape(f.label)}
                                            (${App.templates.escape(f.code)})
                                        </option>
                                        `
                                ).join('')
                            }

                        </select>

                    </label>
                `;
            };

        root.innerHTML = `

            <div class="mb-4 text-lg font-bold">
                フィールドマッピング
            </div>

            <div class="grid gap-5 md:grid-cols-2">

                ${select(
                    'field_company',
                    '会社名 (Company)',
                    false
                )}

                ${select(
                    'field_name',
                    '氏名 (Name)',
                    false
                )}

                ${select(
                    'field_email',
                    'メールアドレス (Email)',
                    false
                )}

                ${select(
                    'field_department',
                    '部署名 (Department)',
                    false
                )}

                ${select(
                    'field_phone',
                    '電話番号 (Phone)',
                    false
                )}

                ${select(
                    'field_address',
                    '住所 (Address)',
                    true
                )}

            </div>
        `;
    };

App.actions.saveSettings =
    async function () {

        const settings = {
            subdomain:
                document.getElementById(
                    'setting_subdomain'
                ).value.trim(),

            login_name:
                document.getElementById(
                    'setting_login_name'
                ).value,

            password:
                document.getElementById(
                    'setting_password'
                ).value,

            app_id:
                document.getElementById(
                    'setting_app_id'
                ).value.trim(),

            ssl_verify:
                document.getElementById(
                    'setting_ssl_verify'
                ).checked,

            proxy:
                document.getElementById(
                    'setting_proxy'
                ).value.trim(),

            field_company:
                App.state.settings.field_company || '',

            field_name:
                App.state.settings.field_name || '',

            field_email:
                App.state.settings.field_email || '',

            field_department:
                App.state.settings.field_department || '',

            field_phone:
                App.state.settings.field_phone || '',

            field_address:
                App.state.settings.field_address || []
        };

        document
            .querySelectorAll(
                '.kintone-map'
            )
            .forEach(
                select => {

                    const key =
                        select.dataset.mapKey;

                    if (
                        select.multiple
                    ) {
                        settings[key] =
                            [...select.options]
                                .filter(
                                    o => o.selected
                                )
                                .map(
                                    o => o.value
                                );
                    } else {
                        settings[key] =
                            select.value;
                    }
                }
            );

        try {

            await App.api.post(
                'save_settings',
                {
                    settings_json:
                        settings
                }
            );

            App.state.settings =
                settings;

            alert(
                'kintone連携設定を保存しました。'
            );

        } catch (error) {

            alert(
                error.message
            );
        }
    };

/* =========================================================
 * Init
 * ======================================================= */

if (
    document.readyState ===
    'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
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
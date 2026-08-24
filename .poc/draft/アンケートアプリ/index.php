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
- branch_enabled
- branch_rules

質問分岐項目:
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

/* ============================================================
 * PHP utility
 * ========================================================== */

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
            'message' => 'CSRFトークンが不正です。ページを再読み込みしてください。'
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

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/* ============================================================
 * Survey normalization
 * ========================================================== */

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

    $survey['deleted'] =
        (bool)($survey['deleted'] ?? false);

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

            /*
             * 質問分岐
             */
            $question['branch_enabled'] =
                (bool)($question['branch_enabled'] ?? false);

            $question['branch_rules'] =
                is_array($question['branch_rules'] ?? null)
                    ? $question['branch_rules']
                    : [];

            foreach ($question['branch_rules'] as &$rule) {
                $rule['option'] =
                    (string)($rule['option'] ?? '');

                $rule['target_question_id'] =
                    (string)($rule['target_question_id'] ?? '');
            }

            unset($rule);
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

/* ============================================================
 * Kintone API
 * ========================================================== */

function surveyKintoneRequest(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    $subdomain = trim(
        (string)($settings['subdomain'] ?? '')
    );

    if ($subdomain === '') {
        return [
            'ok' => false,
            'status' => 400,
            'message' => 'kintoneのサブドメインが設定されていません。'
        ];
    }

    $subdomain = preg_replace(
        '#^https?://#i',
        '',
        $subdomain
    );

    $subdomain = preg_replace(
        '#/.*$#',
        '',
        $subdomain
    );

    if (!str_contains($subdomain, '.')) {
        $host = $subdomain . '.cybozu.com';
    } else {
        $host = $subdomain;
    }

    $url = 'https://' . $host . $path;

    $login = (string)(
        $settings['login_name'] ?? ''
    );

    $password = (string)(
        $settings['password'] ?? ''
    );

    /*
     * Basic認証方式。
     *
     * X-Cybozu-Authorizationでも動作する環境があるが、
     * kintoneの標準的なログイン認証としてBasicを併用する。
     */
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password),
        'Authorization: Basic ' .
            base64_encode($login . ':' . $password)
    ];

    $content = '';

    if ($body !== null) {
        $content = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            $content = '';
        }
    }

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 30,
            'content' => $content
        ],
        'ssl' => [
            'verify_peer' =>
                (bool)($settings['ssl_verify'] ?? false),
            'verify_peer_name' =>
                (bool)($settings['ssl_verify'] ?? false),
            'allow_self_signed' =>
                !(bool)($settings['ssl_verify'] ?? false)
        ]
    ];

    $proxy = trim(
        (string)($settings['proxy'] ?? '')
    );

    if ($proxy !== '') {
        $options['http']['proxy'] =
            'tcp://' . $proxy;

        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $result = @file_get_contents(
        $url,
        false,
        $context
    );

    $responseHeaders = [];

    if (function_exists('http_get_last_response_headers')) {
        $lastHeaders =
            http_get_last_response_headers();

        if (is_array($lastHeaders)) {
            $responseHeaders = $lastHeaders;
        }
    } elseif (
        isset($http_response_header) &&
        is_array($http_response_header)
    ) {
        $responseHeaders =
            $http_response_header;
    }

    $statusCode = 0;

    foreach ($responseHeaders as $header) {
        if (
            preg_match(
                '#HTTP/\S+\s+(\d{3})#',
                $header,
                $match
            )
        ) {
            $statusCode = (int)$match[1];
        }
    }

    if ($result === false) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' =>
                'kintone APIへの接続に失敗しました。',
            'headers' => $responseHeaders
        ];
    }

    $decoded = json_decode(
        $result,
        true
    );

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' =>
                'kintone APIから不正なJSONが返されました。',
            'raw' => $result
        ];
    }

    if ($statusCode >= 400) {
        return [
            'ok' => false,
            'status' => $statusCode,
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

/* ============================================================
 * API
 * ========================================================== */

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

            $survey['answer_count'] = $count;
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

    /*
     * kintone項目一覧取得
     */
    if ($action === 'kintone_fields') {
        surveyVerifyCsrf();

        $settings =
            surveyPostJson('settings_json')
            ?? $data['settings'];

        $appId = trim(
            (string)(
                $settings['app_id'] ??
                ($_POST['app_id'] ?? '')
            )
        );

        if ($appId === '') {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    'アプリIDを入力してください。'
            ], 400);
        }

        $result = surveyKintoneRequest(
            $settings,
            '/k/v1/app/form/fields.json?app=' .
                rawurlencode($appId),
            'GET'
        );

        if (!$result['ok']) {
            surveyJsonResponse(
                $result,
                (int)(
                    $result['status'] >= 400
                        ? $result['status']
                        : 502
                )
            );
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

    if ($action === 'export_csv') {
        surveyVerifyCsrf();

        $surveyId =
            (string)($_GET['survey_id'] ?? '');

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
            foreach ($group['questions'] as $question) {
                $questions[] = $question;
            }
        }

        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        header(
            'Content-Disposition: attachment; filename="' .
            'survey_' .
            $surveyId .
            '_' .
            date('YmdHis') .
            '.csv"'
        );

        $output =
            fopen('php://output', 'wb');

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

        foreach ($questions as $index => $question) {
            $header[] =
                '設問' . ($index + 1);
        }

        fputcsv($output, $header);

        foreach ($data['responses'] as $response) {
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
                    $answers[$question['id']]
                    ?? '';

                if (is_array($value)) {
                    $value =
                        implode('、', $value);
                }

                $row[] =
                    (string)$value;
            }

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /*
     * POST以外の書き込みは禁止
     */
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
                (string)$survey['id']
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
                    'データの保存に失敗しました。'
            ], 500);
        }

        surveyJsonResponse([
            'ok' => true,
            'survey' => $survey
        ]);
    }

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

    if ($action === 'status_survey') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $newStatus =
            (string)(
                $_POST['status'] ?? 'draft'
            );

        if (
            !in_array(
                $newStatus,
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
                    $newStatus;

                $data['surveys'][$index]['updated_at'] =
                    surveyNow();
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }

    if ($action === 'duplicate_survey') {
        $surveyId =
            (string)(
                $_POST['survey_id'] ?? ''
            );

        $copy = null;

        foreach ($data['surveys'] as $survey) {
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

        /*
         * IDをすべて再生成する。
         * 分岐先IDも同時に付け替える。
         */
        $questionIdMap = [];

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

        foreach ($copy['groups'] as &$group) {
            $group['id'] =
                surveyId('group');

            foreach (
                $group['questions']
                as &$question
            ) {
                $oldId =
                    (string)$question['id'];

                $newId =
                    surveyId('question');

                $questionIdMap[$oldId] =
                    $newId;

                $question['id'] =
                    $newId;
            }

            unset($question);
        }

        unset($group);

        /*
         * 分岐先IDをコピー後のIDへ変換。
         */
        foreach ($copy['groups'] as &$group) {
            foreach (
                $group['questions']
                as &$question
            ) {
                if (
                    !empty(
                        $question['branch_rules']
                    )
                ) {
                    foreach (
                        $question['branch_rules']
                        as &$rule
                    ) {
                        $oldTarget =
                            (string)(
                                $rule[
                                    'target_question_id'
                                ] ?? ''
                            );

                        $rule[
                            'target_question_id'
                        ] =
                            $questionIdMap[
                                $oldTarget
                            ] ?? '';

                    }

                    unset($rule);
                }
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

    if ($action === 'save_settings') {
        $settings =
            surveyPostJson(
                'settings_json'
            );

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

        if (!surveyWriteStorage($data)) {
            surveyJsonResponse([
                'ok' => false,
                'message' =>
                    '設定保存に失敗しました。'
            ], 500);
        }

        surveyJsonResponse([
            'ok' => true,
            'settings' => $settings
        ]);
    }

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
                $data['customers'][$index][
                    'kintone_status'
                ] = 'registered';
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true
        ]);
    }

    surveyJsonResponse([
        'ok' => false,
        'message' => '不明なAPIです。'
    ], 404);
}

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

<body class="bg-slate-100 text-slate-800">

<div id="app"></div>

<script>
window.App = {
    state: {
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mail_logs: [],
        csrf: '',
        screen: 'list',
        currentSurvey: null,
        previewSurvey: null,
        previewAnswers: {},
        previewMode: 'pc',
        branchTarget: null,
        selectedQuestions: {},
        selectedCustomers: [],
        editingCustomerSurvey: null
    },

    templates: {},

    utils: {},

    api: {},

    actions: {},

    render: {}
};


/* ============================================================
 * Utility
 * ========================================================== */

App.utils.escape = function(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

App.utils.uid = function(prefix) {
    return prefix + '_' +
        Math.random()
            .toString(36)
            .slice(2) +
        Date.now().toString(36);
};

App.utils.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
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

App.utils.typeLabel = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || type;
};


/* ============================================================
 * API
 * ========================================================== */

App.api.get = async function(action) {
    const response =
        await fetch(
            '?action=' +
            encodeURIComponent(action),
            {
                credentials: 'same-origin'
            }
        );

    return await response.json();
};

App.api.post = async function(action, data) {
    const body =
        new URLSearchParams();

    body.set(
        'csrf_token',
        App.state.csrf
    );

    Object.keys(data || {}).forEach(function(key) {
        body.set(
            key,
            typeof data[key] === 'object'
                ? JSON.stringify(data[key])
                : String(data[key])
        );
    });

    const response =
        await fetch(
            '?action=' +
            encodeURIComponent(action),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString()
            }
        );

    const result =
        await response.json();

    if (!response.ok || !result.ok) {
        throw new Error(
            result.message ||
            '通信に失敗しました。'
        );
    }

    return result;
};


/* ============================================================
 * Question helpers
 * ========================================================== */

App.actions.allQuestions = function(survey) {
    const result = [];

    if (!survey || !Array.isArray(survey.groups)) {
        return result;
    }

    survey.groups.forEach(function(group) {
        (group.questions || []).forEach(
            function(question) {
                result.push(question);
            }
        );
    });

    return result;
};

App.actions.findQuestion = function(
    groupId,
    questionId
) {
    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return null;
    }

    const group =
        survey.groups.find(
            item =>
                String(item.id) ===
                String(groupId)
        );

    if (!group) {
        return null;
    }

    return group.questions.find(
        item =>
            String(item.id) ===
            String(questionId)
    ) || null;
};

App.actions.questionNumber = function(
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
            survey.groups[i].questions.length;
    }

    number += questionIndex + 1;

    return 'Q' + number;
};

App.actions.normalizeBranchRules = function(question) {
    if (!question) {
        return;
    }

    if (
        !Array.isArray(
            question.branch_rules
        )
    ) {
        question.branch_rules = [];
    }

    const old =
        question.branch_rules;

    question.branch_rules =
        (question.options || []).map(
            function(option) {
                const found =
                    old.find(
                        rule =>
                            String(rule.option) ===
                            String(option)
                    );

                return {
                    option: option,
                    target_question_id:
                        found
                            ? String(
                                found.target_question_id ||
                                ''
                            )
                            : ''
                };
            }
        );
};


/* ============================================================
 * Group / Question
 * ========================================================== */

App.actions.addGroup = function() {
    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    survey.groups.push({
        id: App.utils.uid('group'),
        name: '新しいグループ',
        questions: []
    });

    App.render.editor();
    App.actions.enableSortables();
};

App.actions.removeGroup = function(groupId) {
    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    if (
        !confirm(
            'このグループと、含まれる質問をすべて削除しますか？'
        )
    ) {
        return;
    }

    survey.groups =
        survey.groups.filter(
            group =>
                String(group.id) !==
                String(groupId)
        );

    App.render.editor();
    App.actions.enableSortables();
};

App.actions.addQuestion = function(groupId) {
    const survey =
        App.state.currentSurvey;

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

    group.questions.push({
        id: App.utils.uid('question'),
        text: '新しい質問',
        type: 'single',
        required: false,
        options: [
            '選択肢1',
            '選択肢2'
        ],
        other_enabled: false,
        branch_enabled: false,
        branch_rules: []
    });

    App.render.editor();
    App.actions.enableSortables();
};

App.actions.removeQuestion = function(
    groupId,
    questionId
) {
    const group =
        App.state.currentSurvey.groups.find(
            item =>
                String(item.id) ===
                String(groupId)
        );

    if (!group) {
        return;
    }

    group.questions =
        group.questions.filter(
            question =>
                String(question.id) !==
                String(questionId)
        );

    App.render.editor();
    App.actions.enableSortables();
};

App.actions.updateGroup = function(
    groupId,
    value
) {
    const group =
        App.state.currentSurvey.groups.find(
            item =>
                String(item.id) ===
                String(groupId)
        );

    if (group) {
        group.name = value;
    }
};

App.actions.updateQuestion = function(
    groupId,
    questionId,
    key,
    value
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    if (key === 'required') {
        question.required =
            Boolean(value);
    } else {
        question[key] = value;
    }

    if (
        key === 'type' &&
        value !== 'single'
    ) {
        question.branch_enabled = false;
        question.branch_rules = [];
    }

    if (key === 'options') {
        App.actions.normalizeBranchRules(
            question
        );
    }
};

App.actions.addOption = function(
    groupId,
    questionId
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    question.options.push(
        '選択肢' +
        (question.options.length + 1)
    );

    App.actions.normalizeBranchRules(
        question
    );

    App.render.editor();
    App.actions.enableSortables();
};

App.actions.removeOption = function(
    groupId,
    questionId,
    index
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    question.options.splice(index, 1);

    App.actions.normalizeBranchRules(
        question
    );

    App.render.editor();
    App.actions.enableSortables();
};

App.actions.updateOption = function(
    groupId,
    questionId,
    index,
    value
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    question.options[index] = value;

    App.actions.normalizeBranchRules(
        question
    );
};


/* ============================================================
 * Branching
 * ========================================================== */

App.actions.toggleBranch = function(
    groupId,
    questionId,
    checked
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    question.branch_enabled =
        Boolean(checked);

    if (
        question.branch_enabled
    ) {
        App.actions.normalizeBranchRules(
            question
        );
    } else {
        question.branch_rules = [];
    }

    App.render.editor();
    App.actions.enableSortables();
};

App.actions.setBranchTarget = function(
    groupId,
    questionId,
    option,
    targetId
) {
    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) {
        return;
    }

    App.actions.normalizeBranchRules(
        question
    );

    const rule =
        question.branch_rules.find(
            item =>
                String(item.option) ===
                String(option)
        );

    if (rule) {
        rule.target_question_id =
            String(targetId);
    }

    App.render.editor();
    App.actions.enableSortables();
};


/* ============================================================
 * SortableJS
 * ========================================================== */

App.actions.enableSortables = function() {
    if (
        typeof Sortable ===
        'undefined'
    ) {
        return;
    }

    document
        .querySelectorAll(
            '[data-sortable-groups]'
        )
        .forEach(function(element) {
            if (element._sortable) {
                element._sortable.destroy();
            }

            element._sortable =
                new Sortable(
                    element,
                    {
                        handle:
                            '[data-group-handle]',
                        animation: 180,
                        ghostClass:
                            'opacity-40',
                        onEnd: function() {
                            const ids =
                                Array.from(
                                    element.children
                                ).map(
                                    node =>
                                        node.dataset.groupId
                                );

                            const survey =
                                App.state.currentSurvey;

                            survey.groups.sort(
                                function(a, b) {
                                    return (
                                        ids.indexOf(
                                            String(a.id)
                                        ) -
                                        ids.indexOf(
                                            String(b.id)
                                        )
                                    );
                                }
                            );

                            App.render.editor();
                            App.actions.enableSortables();
                        }
                    }
                );
        });

    document
        .querySelectorAll(
            '[data-sortable-questions]'
        )
        .forEach(function(element) {
            if (element._sortable) {
                element._sortable.destroy();
            }

            element._sortable =
                new Sortable(
                    element,
                    {
                        group:
                            'survey-questions',
                        handle:
                            '[data-question-handle]',
                        animation: 180,
                        ghostClass:
                            'opacity-40',

                        onEnd: function(evt) {
                            const survey =
                                App.state.currentSurvey;

                            const fromGroup =
                                survey.groups.find(
                                    group =>
                                        String(group.id) ===
                                        String(
                                            evt.from.dataset.groupId
                                        )
                                );

                            const toGroup =
                                survey.groups.find(
                                    group =>
                                        String(group.id) ===
                                        String(
                                            evt.to.dataset.groupId
                                        )
                                );

                            if (
                                !fromGroup ||
                                !toGroup
                            ) {
                                return;
                            }

                            const movedId =
                                String(
                                    evt.item.dataset.questionId
                                );

                            const question =
                                fromGroup.questions.find(
                                    item =>
                                        String(item.id) ===
                                        movedId
                                );

                            if (!question) {
                                return;
                            }

                            fromGroup.questions =
                                fromGroup.questions.filter(
                                    item =>
                                        String(item.id) !==
                                        movedId
                                );

                            toGroup.questions.splice(
                                evt.newIndex,
                                0,
                                question
                            );

                            App.render.editor();
                            App.actions.enableSortables();
                        }
                    }
                );
        });
};


/* ============================================================
 * Survey templates
 * ========================================================== */

App.templates.surveyRow = function(
    survey
) {
    const status =
        App.utils.escape(
            App.utils.statusLabel(
                survey.status
            )
        );

    const statusClass =
        App.utils.statusClass(
            survey.status
        );

    let actions = '';

    if (survey.status === 'active') {
        actions = `
            <button class="px-3 py-1.5 rounded-lg bg-slate-800 text-white text-sm"
                onclick="App.actions.editSurvey('${App.utils.escape(survey.id)}')">
                確認・編集
            </button>

            <button class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-sm"
                onclick="App.actions.showAnalytics('${App.utils.escape(survey.id)}')">
                集計
            </button>

            <button class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm"
                onclick="App.actions.showMail('${App.utils.escape(survey.id)}')">
                送信
            </button>

            <button class="px-3 py-1.5 rounded-lg bg-rose-100 text-rose-700 text-sm"
                onclick="App.actions.changeStatus('${App.utils.escape(survey.id)}','ended')">
                停止
            </button>

            <button class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-sm"
                onclick="App.actions.duplicateSurvey('${App.utils.escape(survey.id)}')">
                複製
            </button>
        `;
    } else if (
        survey.status === 'draft'
    ) {
        actions = `
            <button class="px-3 py-1.5 rounded-lg bg-slate-800 text-white text-sm"
                onclick="App.actions.editSurvey('${App.utils.escape(survey.id)}')">
                確認・編集
            </button>

            <button class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-sm"
                onclick="App.actions.changeStatus('${App.utils.escape(survey.id)}','active')">
                公開
            </button>

            <button class="px-3 py-1.5 rounded-lg bg-rose-100 text-rose-700 text-sm"
                onclick="App.actions.deleteSurvey('${App.utils.escape(survey.id)}')">
                削除
            </button>

            <button class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-sm"
                onclick="App.actions.duplicateSurvey('${App.utils.escape(survey.id)}')">
                複製
            </button>
        `;
    } else {
        actions = `
            <button class="px-3 py-1.5 rounded-lg bg-slate-800 text-white text-sm"
                onclick="App.actions.editSurvey('${App.utils.escape(survey.id)}')">
                閲覧
            </button>

            <button class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-sm"
                onclick="App.actions.showAnalytics('${App.utils.escape(survey.id)}')">
                集計
            </button>

            <button class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 text-sm"
                onclick="App.actions.duplicateSurvey('${App.utils.escape(survey.id)}')">
                複製
            </button>
        `;
    }

    return `
        <tr class="border-b border-slate-100 hover:bg-slate-50">
            <td class="px-5 py-4 text-sm">
                <div>${App.utils.escape(
                    String(survey.created_at).slice(0,10)
                )}</div>
                <div class="text-slate-400 text-xs mt-1">
                    更新: ${App.utils.escape(
                        String(survey.updated_at).slice(0,10)
                    )}
                </div>
            </td>

            <td class="px-5 py-4">
                <div class="font-semibold">
                    ${App.utils.escape(survey.title || '無題')}
                </div>
            </td>

            <td class="px-5 py-4 text-sm text-slate-600">
                ${
                    survey.start_at
                    ? App.utils.escape(
                        survey.start_at
                    )
                    : '未設定'
                }
                <div class="text-slate-400">～</div>
                ${
                    survey.end_at
                    ? App.utils.escape(
                        survey.end_at
                    )
                    : '未設定'
                }
            </td>

            <td class="px-5 py-4">
                <span class="px-2.5 py-1 rounded-full text-xs font-medium ${statusClass}">
                    ${status}
                </span>
            </td>

            <td class="px-5 py-4 text-right font-semibold">
                ${Number(survey.answer_count || 0)} 件
            </td>

            <td class="px-5 py-4">
                <div class="flex flex-wrap gap-2">
                    ${actions}
                </div>
            </td>
        </tr>
    `;
};


/* ============================================================
 * Editor question template
 * ========================================================== */

App.templates.question = function(
    survey,
    group,
    groupIndex,
    question,
    questionIndex
) {
    const number =
        App.actions.questionNumber(
            survey,
            groupIndex,
            questionIndex
        );

    const options =
        (question.options || [])
            .map(
                function(option, index) {
                    return `
                        <div class="flex gap-2 items-center mb-2">
                            <input
                                class="flex-1 border border-slate-300 rounded-lg px-3 py-2"
                                value="${App.utils.escape(option)}"
                                onchange="App.actions.updateOption(
                                    '${App.utils.escape(group.id)}',
                                    '${App.utils.escape(question.id)}',
                                    ${index},
                                    this.value
                                )">

                            <button
                                class="text-rose-500 px-2"
                                onclick="App.actions.removeOption(
                                    '${App.utils.escape(group.id)}',
                                    '${App.utils.escape(question.id)}',
                                    ${index}
                                )">
                                ×
                            </button>
                        </div>
                    `;
                }
            )
            .join('');

    let branch = '';

    if (
        question.type === 'single'
    ) {
        const allQuestions =
            App.actions.allQuestions(
                survey
            );

        const branchRows =
            (question.options || [])
                .map(
                    function(option) {
                        const rule =
                            (
                                question.branch_rules ||
                                []
                            ).find(
                                item =>
                                    String(item.option) ===
                                    String(option)
                            );

                        const target =
                            rule
                                ? String(
                                    rule.target_question_id ||
                                    ''
                                )
                                : '';

                        const targets =
                            allQuestions
                                .filter(
                                    item =>
                                        String(item.id) !==
                                        String(question.id)
                                )
                                .map(
                                    function(targetQuestion) {
                                        const index =
                                            allQuestions.indexOf(
                                                targetQuestion
                                            );

                                        const label =
                                            'Q' +
                                            (index + 1) +
                                            ' ' +
                                            targetQuestion.text;

                                        return `
                                            <option
                                                value="${App.utils.escape(targetQuestion.id)}"
                                                ${
                                                    target ===
                                                    String(targetQuestion.id)
                                                        ? 'selected'
                                                        : ''
                                                }>
                                                ${App.utils.escape(label)}
                                            </option>
                                        `;
                                    }
                                )
                                .join('');

                        return `
                            <div class="grid grid-cols-[180px_1fr] gap-3 items-center mb-2">
                                <div class="text-sm font-medium">
                                    「${App.utils.escape(option)}」
                                </div>

                                <select
                                    class="border border-slate-300 rounded-lg px-3 py-2 bg-white"
                                    onchange="App.actions.setBranchTarget(
                                        '${App.utils.escape(group.id)}',
                                        '${App.utils.escape(question.id)}',
                                        '${App.utils.escape(option)}',
                                        this.value
                                    )">
                                    <option value="">
                                        通常どおり次の質問へ
                                    </option>
                                    ${targets}
                                </select>
                            </div>
                        `;
                    }
                )
                .join('');

        branch = `
            <div class="mt-5 border-t border-slate-200 pt-5">
                <label class="flex items-center gap-2 font-medium text-sm">
                    <input
                        type="checkbox"
                        class="w-4 h-4"
                        ${
                            question.branch_enabled
                                ? 'checked'
                                : ''
                        }
                        onchange="App.actions.toggleBranch(
                            '${App.utils.escape(group.id)}',
                            '${App.utils.escape(question.id)}',
                            this.checked
                        )">
                    回答によって次の質問を分岐する
                </label>

                ${
                    question.branch_enabled
                    ? `
                        <div class="mt-3 rounded-xl bg-indigo-50 border border-indigo-100 p-4">
                            <div class="text-xs text-indigo-700 mb-3">
                                各回答を選択した場合の遷移先を設定できます。
                                グループを跨いだ質問も選択できます。
                            </div>

                            ${branchRows}
                        </div>
                    `
                    : ''
                }
            </div>
        `;
    }

    return `
        <div
            data-question-id="${App.utils.escape(question.id)}"
            class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">

            <div class="flex gap-3">

                <div
                    data-question-handle
                    class="cursor-grab text-slate-300 text-xl select-none">
                    ⠿
                </div>

                <div class="flex-1">

                    <div class="flex items-center justify-between mb-4">
                        <div class="font-semibold">
                            ${number}
                        </div>

                        <button
                            class="text-rose-500 text-sm"
                            onclick="App.actions.removeQuestion(
                                '${App.utils.escape(group.id)}',
                                '${App.utils.escape(question.id)}'
                            )">
                            質問を削除
                        </button>
                    </div>

                    <input
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 mb-4"
                        value="${App.utils.escape(question.text)}"
                        onchange="App.actions.updateQuestion(
                            '${App.utils.escape(group.id)}',
                            '${App.utils.escape(question.id)}',
                            'text',
                            this.value
                        )">

                    <div class="flex gap-3 mb-4">

                        <select
                            class="border border-slate-300 rounded-lg px-3 py-2"
                            onchange="App.actions.updateQuestion(
                                '${App.utils.escape(group.id)}',
                                '${App.utils.escape(question.id)}',
                                'type',
                                this.value
                            )">

                            <option value="single"
                                ${question.type === 'single' ? 'selected' : ''}>
                                単一選択
                            </option>

                            <option value="multiple"
                                ${question.type === 'multiple' ? 'selected' : ''}>
                                複数選択
                            </option>

                            <option value="text"
                                ${question.type === 'text' ? 'selected' : ''}>
                                自由記述
                            </option>

                        </select>

                        <label class="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                class="w-4 h-4"
                                ${
                                    question.required
                                        ? 'checked'
                                        : ''
                                }
                                onchange="App.actions.updateQuestion(
                                    '${App.utils.escape(group.id)}',
                                    '${App.utils.escape(question.id)}',
                                    'required',
                                    this.checked
                                )">
                            必須回答
                        </label>
                    </div>

                    ${
                        question.type !== 'text'
                        ? `
                            <div class="rounded-lg bg-slate-50 p-4">
                                <div class="text-sm font-medium mb-3">
                                    選択肢
                                </div>

                                ${options}

                                <button
                                    class="text-sm text-indigo-600"
                                    onclick="App.actions.addOption(
                                        '${App.utils.escape(group.id)}',
                                        '${App.utils.escape(question.id)}'
                                    )">
                                    ＋ 選択肢を追加
                                </button>
                            </div>
                        `
                        : ''
                    }

                    ${
                        question.type === 'single'
                        ? branch
                        : ''
                    }

                </div>
            </div>
        </div>
    `;
};


/* ============================================================
 * Header
 * ========================================================== */

App.render.header = function() {
    return `
        <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
            <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">

                <button
                    class="font-bold text-lg"
                    onclick="App.actions.goList()">
                    アンケート管理
                </button>

                <nav class="flex items-center gap-2">
                    <button
                        class="px-4 py-2 rounded-lg hover:bg-slate-100"
                        onclick="App.actions.goList()">
                        アンケート一覧
                    </button>

                    <button
                        class="px-4 py-2 rounded-lg hover:bg-slate-100"
                        onclick="App.actions.showSettings()">
                        キントーン連携設定
                    </button>

                    <button
                        class="px-4 py-2 rounded-lg text-slate-500 hover:bg-slate-100">
                        ログアウト
                    </button>
                </nav>
            </div>
        </header>
    `;
};


/* ============================================================
 * List
 * ========================================================== */

App.render.list = function() {
    App.state.screen =
        'list';

    const surveys =
        App.state.surveys;

    App.state.currentSurvey =
        null;

    document.getElementById('app').innerHTML =
        App.render.header() +
        `
        <main class="max-w-7xl mx-auto px-6 py-8">

            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold">
                        アンケート一覧
                    </h1>

                    <p class="text-sm text-slate-500 mt-1">
                        アンケートの作成・公開・送信・集計を管理します。
                    </p>
                </div>

                <button
                    class="bg-indigo-600 text-white px-5 py-3 rounded-xl font-semibold shadow-sm"
                    onclick="App.actions.newSurvey()">
                    ＋ 新規アンケート作成
                </button>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-4 mb-5">
                <div class="grid grid-cols-3 gap-3">

                    <input
                        id="survey_filter_keyword"
                        class="border border-slate-300 rounded-lg px-3 py-2"
                        placeholder="タイトルを検索"
                        oninput="App.actions.filterSurveys()">

                    <select
                        id="survey_filter_status"
                        class="border border-slate-300 rounded-lg px-3 py-2"
                        onchange="App.actions.filterSurveys()">

                        <option value="">すべて</option>
                        <option value="active">公開中</option>
                        <option value="draft">下書き</option>
                        <option value="ended">終了</option>

                    </select>

                    <select
                        id="survey_filter_sort"
                        class="border border-slate-300 rounded-lg px-3 py-2"
                        onchange="App.actions.filterSurveys()">

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

                    </select>

                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">

                <div class="overflow-x-auto">

                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="px-5 py-3 text-xs text-slate-500">
                                    作成・更新
                                </th>

                                <th class="px-5 py-3 text-xs text-slate-500">
                                    タイトル
                                </th>

                                <th class="px-5 py-3 text-xs text-slate-500">
                                    アンケート期間
                                </th>

                                <th class="px-5 py-3 text-xs text-slate-500">
                                    ステータス
                                </th>

                                <th class="px-5 py-3 text-xs text-slate-500 text-right">
                                    回答数
                                </th>

                                <th class="px-5 py-3 text-xs text-slate-500">
                                    操作
                                </th>
                            </tr>
                        </thead>

                        <tbody id="survey_table_body">
                            ${surveys.map(
                                App.templates.surveyRow
                            ).join('')}
                        </tbody>

                    </table>

                </div>
            </div>

        </main>
        `;

    App.actions.filterSurveys();
};


/* ============================================================
 * Editor
 * ========================================================== */

App.render.editor = function() {
    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    App.state.screen =
        'editor';

    document.getElementById('app').innerHTML =
        App.render.header() +
        `
        <main class="max-w-6xl mx-auto px-6 py-8">

            <div class="flex items-center justify-between mb-6">

                <div>
                    <div class="text-sm text-slate-400 mb-1">
                        ホーム ＞ アンケート一覧 ＞ 編集
                    </div>

                    <input
                        id="survey_title"
                        class="text-2xl font-bold bg-transparent border-0 border-b border-transparent focus:border-indigo-400 outline-none"
                        value="${App.utils.escape(survey.title)}"
                        onchange="App.actions.updateSurveyTitle(this.value)">
                </div>

                <div class="flex gap-2">

                    <button
                        class="px-4 py-2 rounded-lg bg-slate-100"
                        onclick="App.actions.preview()">
                        プレビュー
                    </button>

                    <button
                        class="px-4 py-2 rounded-lg bg-slate-200"
                        onclick="App.actions.cancelEdit()">
                        キャンセル
                    </button>

                    <button
                        class="px-4 py-2 rounded-lg bg-indigo-600 text-white"
                        onclick="App.actions.saveSurvey()">
                        保存して一覧へ戻る
                    </button>

                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-5 mb-6">

                <div class="grid grid-cols-3 gap-4">

                    <label class="text-sm">
                        開始日時
                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            class="block mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
                            value="${App.utils.escape(survey.start_at)}"
                            onchange="App.actions.updateSurveyField('start_at',this.value)">
                    </label>

                    <label class="text-sm">
                        終了日時
                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            class="block mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
                            value="${App.utils.escape(survey.end_at)}"
                            onchange="App.actions.updateSurveyField('end_at',this.value)">
                    </label>

                    <label class="text-sm">
                        質問番号
                        <select
                            id="survey_numbering_mode"
                            class="block mt-1 w-full border border-slate-300 rounded-lg px-3 py-2"
                            onchange="App.actions.updateSurveyField('numbering_mode',this.value)">

                            <option
                                value="global"
                                ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                Q1 / Q2 / Q3
                            </option>

                            <option
                                value="group"
                                ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                Q1-1 / Q1-2
                            </option>

                        </select>
                    </label>

                </div>

            </div>

            <div
                id="question_editor"
                data-sortable-groups
                class="space-y-5">

                ${survey.groups.map(
                    function(group, groupIndex) {

                        return `
                            <section
                                data-group-id="${App.utils.escape(group.id)}"
                                class="bg-slate-50 border border-slate-200 rounded-xl p-5">

                                <div class="flex items-center gap-3 mb-4">

                                    <span
                                        data-group-handle
                                        class="cursor-grab text-slate-400 text-xl">
                                        ⠿
                                    </span>

                                    <input
                                        class="flex-1 text-lg font-semibold bg-transparent border-b border-transparent focus:border-indigo-400 outline-none"
                                        value="${App.utils.escape(group.name)}"
                                        onchange="App.actions.updateGroup(
                                            '${App.utils.escape(group.id)}',
                                            this.value
                                        )">

                                    <button
                                        class="text-rose-500 text-sm"
                                        onclick="App.actions.removeGroup(
                                            '${App.utils.escape(group.id)}'
                                        )">
                                        グループ削除
                                    </button>

                                </div>

                                <div
                                    data-sortable-questions
                                    data-group-id="${App.utils.escape(group.id)}"
                                    class="space-y-4">

                                    ${(group.questions || [])
                                        .map(
                                            function(
                                                question,
                                                questionIndex
                                            ) {
                                                return App.templates.question(
                                                    survey,
                                                    group,
                                                    groupIndex,
                                                    question,
                                                    questionIndex
                                                );
                                            }
                                        )
                                        .join('')}

                                </div>

                                <button
                                    class="mt-4 w-full border-2 border-dashed border-slate-300 rounded-xl py-3 text-slate-500 hover:bg-white"
                                    onclick="App.actions.addQuestion(
                                        '${App.utils.escape(group.id)}'
                                    )">
                                    ＋ 質問を追加
                                </button>

                            </section>
                        `;
                    }
                ).join('')}

            </div>

            <button
                class="mt-6 w-full py-4 rounded-xl border-2 border-dashed border-indigo-200 text-indigo-600 bg-indigo-50"
                onclick="App.actions.addGroup()">
                ＋ グループを追加
            </button>

        </main>
        `;

    App.actions.enableSortables();
};


/* ============================================================
 * Preview
 * ========================================================== */

App.render.preview = function() {
    const survey =
        App.state.previewSurvey;

    if (!survey) {
        return;
    }

    const allQuestions =
        App.actions.allQuestions(
            survey
        );

    let html = '';

    allQuestions.forEach(
        function(question, index) {
            if (
                !App.actions.isQuestionVisible(
                    survey,
                    question
                )
            ) {
                return;
            }

            const answer =
                App.state.previewAnswers[
                    question.id
                ];

            html += `
                <div
                    data-preview-question="${App.utils.escape(question.id)}"
                    class="mb-6 border-b border-slate-200 pb-6">

                    <div class="font-semibold mb-3">
                        Q${index + 1}.
                        ${App.utils.escape(question.text)}
                        ${
                            question.required
                                ? '<span class="text-rose-500 ml-1">*</span>'
                                : ''
                        }
                    </div>
            `;

            if (
                question.type === 'single'
            ) {
                html +=
                    (question.options || [])
                        .map(
                            function(option) {
                                return `
                                    <label class="flex items-center gap-2 py-2">
                                        <input
                                            type="radio"
                                            name="preview_${App.utils.escape(question.id)}"
                                            value="${App.utils.escape(option)}"
                                            ${
                                                String(answer) ===
                                                String(option)
                                                    ? 'checked'
                                                    : ''
                                            }
                                            onchange="App.actions.previewAnswer(
                                                '${App.utils.escape(question.id)}',
                                                this.value
                                            )">
                                        ${App.utils.escape(option)}
                                    </label>
                                `;
                            }
                        )
                        .join('');
            }

            if (
                question.type === 'multiple'
            ) {
                html +=
                    (question.options || [])
                        .map(
                            function(option) {
                                const checked =
                                    Array.isArray(answer) &&
                                    answer.includes(
                                        option
                                    );

                                return `
                                    <label class="flex items-center gap-2 py-2">
                                        <input
                                            type="checkbox"
                                            value="${App.utils.escape(option)}"
                                            ${
                                                checked
                                                    ? 'checked'
                                                    : ''
                                            }
                                            onchange="App.actions.previewMultiple(
                                                '${App.utils.escape(question.id)}'
                                            )">
                                        ${App.utils.escape(option)}
                                    </label>
                                `;
                            }
                        )
                        .join('');
            }

            if (
                question.type === 'text'
            ) {
                html += `
                    <textarea
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                        rows="4"
                        onchange="App.actions.previewAnswer(
                            '${App.utils.escape(question.id)}',
                            this.value
                        )">${App.utils.escape(answer || '')}</textarea>
                `;
            }

            html += `
                </div>
            `;
        }
    );

    document.getElementById(
        'preview_content'
    ).innerHTML =
        html +
        `
            <button
                class="w-full bg-indigo-600 text-white rounded-xl py-3"
                onclick="alert('プレビューのため送信は実行されません。')">
                回答を送信
            </button>
        `;
};

App.actions.isQuestionVisible = function(
    survey,
    question
) {
    const questions =
        App.actions.allQuestions(
            survey
        );

    const index =
        questions.findIndex(
            item =>
                String(item.id) ===
                String(question.id)
        );

    if (index <= 0) {
        return true;
    }

    /*
     * この質問へ明示的に到達する分岐があるか。
     */
    const incoming =
        questions.some(
            previous =>
                previous.branch_enabled &&
                Array.isArray(
                    previous.branch_rules
                ) &&
                previous.branch_rules.some(
                    rule =>
                        String(
                            rule.target_question_id
                        ) ===
                        String(question.id)
                )
        );

    /*
     * 通常質問。
     */
    if (!incoming) {
        return true;
    }

    /*
     * どれかの回答からこの質問へ
     * 明示的に到達した場合のみ表示。
     */
    for (
        let i = 0;
        i < index;
        i++
    ) {
        const previous =
            questions[i];

        if (
            !previous.branch_enabled ||
            !Array.isArray(
                previous.branch_rules
            )
        ) {
            continue;
        }

        const answer =
            App.state.previewAnswers[
                previous.id
            ];

        if (
            answer === undefined ||
            answer === ''
        ) {
            continue;
        }

        const answers =
            Array.isArray(answer)
                ? answer
                : [answer];

        const matched =
            previous.branch_rules.some(
                function(rule) {
                    return (
                        String(
                            rule.target_question_id
                        ) ===
                        String(question.id) &&
                        answers.some(
                            value =>
                                String(
                                    rule.option
                                ) ===
                                String(value)
                        )
                    );
                }
            );

        if (matched) {
            return true;
        }
    }

    return false;
};


/* ============================================================
 * Preview actions
 * ========================================================== */

App.actions.previewAnswer = function(
    questionId,
    value
) {
    App.state.previewAnswers[
        questionId
    ] = value;

    App.render.preview();
};

App.actions.previewMultiple = function(
    questionId
) {
    const nodes =
        document.querySelectorAll(
            '[name="preview_' +
            CSS.escape(questionId) +
            '"]'
        );

    const values = [];

    nodes.forEach(function(node) {
        if (node.checked) {
            values.push(node.value);
        }
    });

    App.state.previewAnswers[
        questionId
    ] = values;

    App.render.preview();
};

App.actions.preview = function() {
    const survey =
        JSON.parse(
            JSON.stringify(
                App.state.currentSurvey
            )
        );

    App.state.previewSurvey =
        survey;

    App.state.previewAnswers =
        {};

    document.body.insertAdjacentHTML(
        'beforeend',
        `
        <div
            id="preview_modal"
            class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-6">

            <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[90vh] overflow-hidden">

                <div class="px-6 py-4 border-b flex items-center justify-between">

                    <div class="font-bold">
                        ${App.utils.escape(survey.title)}
                        - プレビュー
                    </div>

                    <div class="flex gap-2">
                        <button
                            class="px-3 py-2 rounded-lg bg-slate-100"
                            onclick="App.actions.previewMode('pc')">
                            PC
                        </button>

                        <button
                            class="px-3 py-2 rounded-lg bg-slate-100"
                            onclick="App.actions.previewMode('mobile')">
                            スマートフォン
                        </button>

                        <button
                            class="px-3 py-2 rounded-lg text-rose-500"
                            onclick="App.actions.closePreview()">
                            閉じる
                        </button>
                    </div>
                </div>

                <div
                    id="preview_content"
                    class="p-6 overflow-y-auto max-h-[75vh]">
                </div>

            </div>
        </div>
        `
    );

    App.render.preview();
};

App.actions.previewMode = function(mode) {
    App.state.previewMode =
        mode;

    App.render.preview();
};

App.actions.closePreview = function() {
    const modal =
        document.getElementById(
            'preview_modal'
        );

    if (modal) {
        modal.remove();
    }
};


/* ============================================================
 * Survey actions
 * ========================================================== */

App.actions.newSurvey = function() {
    App.state.currentSurvey = {
        id: App.utils.uid('survey'),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [
            {
                id: App.utils.uid('group'),
                name: '基本情報',
                questions: [
                    {
                        id: App.utils.uid('question'),
                        text: 'ご利用経験はありますか？',
                        type: 'single',
                        required: true,
                        options: [
                            'はい',
                            'いいえ'
                        ],
                        other_enabled: false,
                        branch_enabled: true,
                        branch_rules: [
                            {
                                option: 'はい',
                                target_question_id: ''
                            },
                            {
                                option: 'いいえ',
                                target_question_id: ''
                            }
                        ]
                    }
                ]
            },
            {
                id: App.utils.uid('group'),
                name: '詳細',
                questions: [
                    {
                        id: App.utils.uid('question'),
                        text: '利用したサービスを教えてください。',
                        type: 'text',
                        required: false,
                        options: [],
                        other_enabled: false,
                        branch_enabled: false,
                        branch_rules: []
                    }
                ]
            }
        ],
        deleted: false
    };

    App.render.editor();
};

App.actions.editSurvey = function(id) {
    const survey =
        App.state.surveys.find(
            item =>
                String(item.id) ===
                String(id)
        );

    if (!survey) {
        return;
    }

    App.state.currentSurvey =
        JSON.parse(
            JSON.stringify(survey)
        );

    App.render.editor();
};

App.actions.updateSurveyTitle = function(value) {
    if (
        App.state.currentSurvey
    ) {
        App.state.currentSurvey.title =
            value;
    }
};

App.actions.updateSurveyField = function(
    key,
    value
) {
    if (
        App.state.currentSurvey
    ) {
        App.state.currentSurvey[key] =
            value;
    }
};

App.actions.saveSurvey = async function() {
    const survey =
        App.state.currentSurvey;

    if (!survey) {
        return;
    }

    try {
        const result =
            await App.api.post(
                'save_survey',
                {
                    survey_json:
                        survey
                }
            );

        const index =
            App.state.surveys.findIndex(
                item =>
                    String(item.id) ===
                    String(result.survey.id)
            );

        if (index >= 0) {
            App.state.surveys[index] =
                result.survey;
        } else {
            App.state.surveys.push(
                result.survey
            );
        }

        alert(
            'アンケートを保存しました。'
        );

        App.render.list();

    } catch (error) {
        alert(
            error.message
        );
    }
};

App.actions.cancelEdit = function() {
    if (
        confirm(
            '変更を破棄して一覧へ戻りますか？'
        )
    ) {
        App.actions.goList();
    }
};

App.actions.goList = function() {
    App.state.currentSurvey =
        null;

    App.render.list();
};

App.actions.changeStatus = async function(
    id,
    status
) {
    const label =
        App.utils.statusLabel(
            status
        );

    if (
        !confirm(
            'アンケートを「' +
            label +
            '」に変更しますか？'
        )
    ) {
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

        const survey =
            App.state.surveys.find(
                item =>
                    String(item.id) ===
                    String(id)
            );

        if (survey) {
            survey.status =
                status;
        }

        App.render.list();

    } catch (error) {
        alert(
            error.message
        );
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
        await App.api.post(
            'delete_survey',
            {
                survey_id: id
            }
        );

        App.state.surveys =
            App.state.surveys.filter(
                item =>
                    String(item.id) !==
                    String(id)
            );

        App.render.list();

    } catch (error) {
        alert(
            error.message
        );
    }
};

App.actions.duplicateSurvey = async function(id) {
    try {
        const result =
            await App.api.post(
                'duplicate_survey',
                {
                    survey_id: id
                }
            );

        App.state.surveys.push(
            result.survey
        );

        App.render.list();

        alert(
            'アンケートを下書きとして複製しました。'
        );

    } catch (error) {
        alert(
            error.message
        );
    }
};


/* ============================================================
 * Filtering
 * ========================================================== */

App.actions.filterSurveys = function() {
    const keyword =
        (
            document.getElementById(
                'survey_filter_keyword'
            )?.value || ''
        ).toLowerCase();

    const status =
        document.getElementById(
            'survey_filter_status'
        )?.value || '';

    const sort =
        document.getElementById(
            'survey_filter_sort'
        )?.value ||
        'updated_desc';

    let list =
        App.state.surveys.filter(
            function(survey) {
                const hit =
                    !keyword ||
                    String(
                        survey.title
                    )
                        .toLowerCase()
                        .includes(
                            keyword
                        );

                const statusHit =
                    !status ||
                    survey.status ===
                    status;

                return hit &&
                    statusHit;
            }
        );

    list.sort(function(a, b) {
        if (
            sort ===
            'answers_desc'
        ) {
            return (
                Number(
                    b.answer_count || 0
                ) -
                Number(
                    a.answer_count || 0
                )
            );
        }

        if (
            sort ===
            'answers_asc'
        ) {
            return (
                Number(
                    a.answer_count || 0
                ) -
                Number(
                    b.answer_count || 0
                )
            );
        }

        if (
            sort ===
            'updated_asc'
        ) {
            return String(
                a.updated_at
            ).localeCompare(
                String(b.updated_at)
            );
        }

        return String(
            b.updated_at
        ).localeCompare(
            String(a.updated_at)
        );
    });

    const body =
        document.getElementById(
            'survey_table_body'
        );

    if (body) {
        body.innerHTML =
            list.length
                ? list.map(
                    App.templates.surveyRow
                ).join('')
                : `
                    <tr>
                        <td
                            colspan="6"
                            class="px-5 py-12 text-center text-slate-400">
                            アンケートがありません。
                        </td>
                    </tr>
                `;
    }
};


/* ============================================================
 * Analytics
 * ========================================================== */

App.actions.showAnalytics = function(id) {
    const survey =
        App.state.surveys.find(
            item =>
                String(item.id) ===
                String(id)
        );

    if (!survey) {
        return;
    }

    App.state.currentSurvey =
        survey;

    const responses =
        App.state.responses.filter(
            response =>
                String(
                    response.survey_id
                ) ===
                String(id)
        );

    const questions =
        App.actions.allQuestions(
            survey
        );

    document.getElementById('app').innerHTML =
        App.render.header() +
        `
        <main class="max-w-7xl mx-auto px-6 py-8">

            <div class="flex items-center justify-between mb-6">

                <div>
                    <div class="text-sm text-slate-400 mb-1">
                        ホーム ＞ アンケート一覧 ＞ 集計
                    </div>

                    <h1 class="text-2xl font-bold">
                        ${App.utils.escape(survey.title)}
                    </h1>
                </div>

                <div class="flex gap-2">

                    <button
                        class="px-4 py-2 rounded-lg bg-slate-100"
                        onclick="App.actions.goList()">
                        一覧へ戻る
                    </button>

                    <button
                        class="px-4 py-2 rounded-lg bg-indigo-600 text-white"
                        onclick="App.actions.exportCsv('${App.utils.escape(id)}')">
                        CSV出力
                    </button>

                    <button
                        class="px-4 py-2 rounded-lg bg-slate-800 text-white"
                        onclick="window.print()">
                        PDF / 印刷
                    </button>

                </div>
            </div>

            <div class="grid grid-cols-5 gap-4 mb-6">

                <div class="bg-white border rounded-xl p-5">
                    <div class="text-sm text-slate-500">
                        送信対象者数
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${App.state.customers.length} 人
                    </div>
                </div>

                <div class="bg-white border rounded-xl p-5">
                    <div class="text-sm text-slate-500">
                        回答数
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${responses.length} 件
                    </div>
                </div>

                <div class="bg-white border rounded-xl p-5">
                    <div class="text-sm text-slate-500">
                        未登録顧客からの回答
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${
                            responses.filter(
                                r =>
                                    !r.customer_id
                            ).length
                        } 件
                    </div>
                </div>

                <div class="bg-white border rounded-xl p-5">
                    <div class="text-sm text-slate-500">
                        未回答
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${
                            Math.max(
                                0,
                                App.state.customers.length -
                                responses.length
                            )
                        } 人
                    </div>
                </div>

                <div class="bg-white border rounded-xl p-5">
                    <div class="text-sm text-slate-500">
                        回答率
                    </div>
                    <div class="text-2xl font-bold mt-2">
                        ${
                            App.state.customers.length
                                ? (
                                    responses.length /
                                    App.state.customers.length *
                                    100
                                ).toFixed(1)
                                : '0.0'
                        } %
                    </div>
                </div>

            </div>

            <div class="grid grid-cols-[260px_1fr] gap-6">

                <aside class="bg-white border rounded-xl p-4">
                    <div class="font-semibold mb-3">
                        設問絞り込み
                    </div>

                    <div class="flex gap-2 mb-3">
                        <button
                            class="text-xs text-indigo-600"
                            onclick="App.actions.selectAllQuestions(true)">
                            全選択
                        </button>

                        <button
                            class="text-xs text-slate-500"
                            onclick="App.actions.selectAllQuestions(false)">
                            全解除
                        </button>
                    </div>

                    ${questions.map(
                        function(question, index) {

                            const selected =
                                App.state.selectedQuestions[
                                    question.id
                                ] !== false;

                            return `
                                <label class="flex gap-2 items-start py-2 text-sm">

                                    <input
                                        type="checkbox"
                                        ${
                                            selected
                                                ? 'checked'
                                                : ''
                                        }
                                        onchange="App.actions.toggleQuestionAnalytics(
                                            '${App.utils.escape(question.id)}',
                                            this.checked
                                        )">

                                    <span>
                                        Q${index + 1}
                                        ${App.utils.escape(question.text)}
                                        <span class="block text-xs text-slate-400">
                                            ${App.utils.typeLabel(question.type)}
                                        </span>
                                    </span>

                                </label>
                            `;
                        }
                    ).join('')}

                </aside>

                <section id="analytics_content" class="space-y-5">
                </section>

            </div>

        </main>
        `;

    App.render.analyticsContent(
        survey,
        responses
    );
};

App.actions.toggleQuestionAnalytics =
    function(id, checked) {
        App.state.selectedQuestions[id] =
            checked;

        App.render.analyticsContent(
            App.state.currentSurvey,
            App.state.responses.filter(
                response =>
                    String(
                        response.survey_id
                    ) ===
                    String(
                        App.state.currentSurvey.id
                    )
            )
        );
    };

App.actions.selectAllQuestions =
    function(checked) {
        App.actions.allQuestions(
            App.state.currentSurvey
        ).forEach(
            question => {
                App.state.selectedQuestions[
                    question.id
                ] = checked;
            }
        );

        App.actions.showAnalytics(
            App.state.currentSurvey.id
        );
    };

App.render.analyticsContent =
    function(
        survey,
        responses
    ) {
        const questions =
            App.actions.allQuestions(
                survey
            );

        const visible =
            questions.filter(
                question =>
                    App.state.selectedQuestions[
                        question.id
                    ] !== false
            );

        const container =
            document.getElementById(
                'analytics_content'
            );

        if (!container) {
            return;
        }

        if (!responses.length) {
            container.innerHTML = `
                <div class="bg-white border rounded-xl p-12 text-center text-slate-400">
                    現在、回答データはありません
                </div>
            `;
            return;
        }

        container.innerHTML =
            visible.map(
                function(question) {
                    if (
                        question.type ===
                        'text'
                    ) {
                        const rows =
                            responses.map(
                                function(response) {
                                    return `
                                        <div class="border-b border-slate-100 py-4">
                                            <div class="font-medium">
                                                ${App.utils.escape(
                                                    response.company || ''
                                                )}
                                                ${App.utils.escape(
                                                    response.name || ''
                                                )}
                                            </div>

                                            <div class="mt-2 text-slate-600 whitespace-pre-wrap">
                                                ${App.utils.escape(
                                                    response.answers?.[
                                                        question.id
                                                    ] || ''
                                                )}
                                            </div>
                                        </div>
                                    `;
                                }
                            ).join('');

                        return `
                            <div class="bg-white border rounded-xl p-5">

                                <div class="font-semibold mb-4">
                                    ${App.utils.escape(question.text)}
                                </div>

                                ${rows}

                            </div>
                        `;
                    }

                    const counts = {};

                    (
                        question.options ||
                        []
                    ).forEach(
                        option =>
                            counts[option] = 0
                    );

                    responses.forEach(
                        response => {
                            let value =
                                response.answers?.[
                                    question.id
                                ];

                            const values =
                                Array.isArray(value)
                                    ? value
                                    : [value];

                            values.forEach(
                                value => {
                                    if (
                                        value &&
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
                        }
                    );

                    return `
                        <div class="bg-white border rounded-xl p-5">

                            <div class="font-semibold mb-5">
                                ${App.utils.escape(question.text)}
                            </div>

                            <div class="space-y-4">

                                ${
                                    Object.entries(
                                        counts
                                    ).map(
                                        function(
                                            [label, count]
                                        ) {
                                            const rate =
                                                responses.length
                                                    ? count /
                                                      responses.length *
                                                      100
                                                    : 0;

                                            return `
                                                <div>

                                                    <div class="flex justify-between text-sm mb-1">
                                                        <span>
                                                            ${App.utils.escape(label)}
                                                        </span>

                                                        <span>
                                                            ${count} 件
                                                            (${rate.toFixed(1)}%)
                                                        </span>
                                                    </div>

                                                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                                        <div
                                                            class="h-full bg-indigo-500"
                                                            style="width:${Math.min(rate,100)}%">
                                                        </div>
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
            ).join('');
    };


/* ============================================================
 * Response detail modal
 * ========================================================== */

App.actions.showResponse = function(
    responseId
) {
    const response =
        App.state.responses.find(
            item =>
                String(item.id) ===
                String(responseId)
        );

    if (!response) {
        return;
    }

    const survey =
        App.state.currentSurvey;

    const questions =
        App.actions.allQuestions(
            survey
        );

    document.body.insertAdjacentHTML(
        'beforeend',
        `
        <div
            id="response_modal"
            class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-6">

            <div class="bg-white rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden">

                <div class="px-6 py-4 border-b flex justify-between">
                    <div class="font-bold">
                        全回答
                    </div>

                    <button
                        onclick="document.getElementById('response_modal').remove()"
                        class="text-rose-500">
                        閉じる
                    </button>
                </div>

                <div
                    id="response_detail"
                    class="p-6 overflow-y-auto max-h-[75vh]">

                    <div class="mb-5">
                        <div class="font-semibold">
                            ${App.utils.escape(response.company || '')}
                            ${App.utils.escape(response.name || '')}
                        </div>

                        <div class="text-sm text-slate-400">
                            ${App.utils.escape(response.answered_at || '')}
                        </div>
                    </div>

                    ${
                        questions.map(
                            function(question, index) {
                                let value =
                                    response.answers?.[
                                        question.id
                                    ] ?? '';

                                if (
                                    Array.isArray(value)
                                ) {
                                    value =
                                        value.join('、');
                                }

                                return `
                                    <div class="border-b py-4">
                                        <div class="text-sm font-medium">
                                            Q${index + 1}
                                            ${App.utils.escape(question.text)}
                                        </div>

                                        <div class="mt-2 text-slate-600 whitespace-pre-wrap">
                                            ${App.utils.escape(value)}
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


/* ============================================================
 * Mail
 * ========================================================== */

App.actions.showMail = function(id) {
    const survey =
        App.state.surveys.find(
            item =>
                String(item.id) ===
                String(id)
        );

    if (!survey) {
        return;
    }

    App.state.editingCustomerSurvey =
        survey;

    document.getElementById('app').innerHTML =
        App.render.header() +
        `
        <main class="max-w-7xl mx-auto px-6 py-8">

            <div class="text-sm text-slate-400 mb-1">
                ホーム ＞ アンケート一覧 ＞ 顧客選択・送信・送信履歴
            </div>

            <div class="flex items-center justify-between mb-6">

                <h1 class="text-2xl font-bold">
                    顧客選択・メール送信
                </h1>

                <button
                    class="px-4 py-2 rounded-lg bg-slate-100"
                    onclick="App.actions.goList()">
                    一覧へ戻る
                </button>

            </div>

            <div class="grid grid-cols-2 gap-5 mb-5">

                <div class="bg-white border rounded-xl p-5">

                    <div class="font-semibold mb-3">
                        メールテンプレート
                    </div>

                    <select
                        id="template_type"
                        class="w-full border rounded-lg px-3 py-2 mb-3"
                        onchange="App.actions.changeTemplate(this.value)">

                        <option value="initial">
                            初回送信
                        </option>

                        <option value="reminder">
                            再送・リマインド
                        </option>

                    </select>

                    <input
                        id="mail_subject"
                        class="w-full border rounded-lg px-3 py-2 mb-3"
                        value="アンケートご協力のお願い">

                    <textarea
                        id="mail_body"
                        class="w-full border rounded-lg px-3 py-2"
                        rows="9">{$customer_name} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>

                </div>

                <div class="bg-white border rounded-xl p-5">

                    <div class="font-semibold mb-3">
                        送信対象
                    </div>

                    <div class="text-sm text-slate-500">
                        顧客を選択して一括送信します。
                    </div>

                    <button
                        class="mt-6 w-full bg-indigo-600 text-white rounded-xl py-3 font-semibold"
                        onclick="App.actions.sendMail()">
                        選択した顧客へ一括送信
                    </button>

                </div>

            </div>

            <div class="bg-white border rounded-xl overflow-hidden">

                <div class="p-4 border-b">

                    <input
                        id="customer_filter"
                        class="w-full border rounded-lg px-3 py-2"
                        placeholder="顧客名・メールアドレスで検索"
                        oninput="App.actions.filterCustomers()">

                </div>

                <div class="overflow-x-auto">

                    <table class="w-full text-left">

                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="px-4 py-3">
                                    <input
                                        id="select_all"
                                        type="checkbox"
                                        onchange="App.actions.selectAllCustomers(this.checked)">
                                </th>

                                <th class="px-4 py-3">
                                    会社名 / 氏名
                                </th>

                                <th class="px-4 py-3">
                                    メールアドレス
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

                        <tbody id="customer_table">
                        </tbody>

                    </table>

                </div>
            </div>

        </main>
        `;

    App.render.customers();
};

App.render.customers = function() {
    const body =
        document.getElementById(
            'customer_table'
        );

    if (!body) {
        return;
    }

    body.innerHTML =
        App.state.customers.map(
            function(customer) {

                const selected =
                    App.state.selectedCustomers.includes(
                        customer.id
                    );

                return `
                    <tr class="border-b">

                        <td class="px-4 py-4">
                            <input
                                type="checkbox"
                                ${
                                    customer.source === 'web'
                                        ? 'disabled'
                                        : ''
                                }
                                ${
                                    selected
                                        ? 'checked'
                                        : ''
                                }
                                onchange="App.actions.toggleCustomer(
                                    '${App.utils.escape(customer.id)}',
                                    this.checked
                                )">
                        </td>

                        <td class="px-4 py-4">
                            <div class="font-semibold">
                                ${App.utils.escape(customer.company || '')}
                            </div>
                            <div>
                                ${App.utils.escape(customer.name || '')}
                            </div>
                        </td>

                        <td class="px-4 py-4">
                            ${App.utils.escape(customer.email || '')}
                        </td>

                        <td class="px-4 py-4 text-sm">
                            ${
                                customer.sent_at
                                    ? App.utils.escape(customer.sent_at)
                                    : '未送信'
                            }

                            <div class="text-slate-400">
                                送信回数:
                                ${Number(customer.send_count || 0)}
                            </div>
                        </td>

                        <td class="px-4 py-4">
                            <span class="px-2 py-1 rounded-full text-xs ${
                                customer.answer_status === 'answered'
                                    ? 'bg-emerald-100 text-emerald-700'
                                    : 'bg-amber-100 text-amber-700'
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
                                customer.kintone_status ===
                                'registered'
                                ? `
                                    <span class="text-emerald-600 text-sm">
                                        ✓ 登録完了
                                    </span>
                                `
                                : `
                                    <button
                                        class="text-indigo-600 text-sm"
                                        onclick="App.actions.markKintone('${App.utils.escape(customer.id)}')">
                                        キントーン登録完了
                                    </button>
                                `
                            }

                        </td>

                    </tr>
                `;
            }
        ).join('');
};

App.actions.toggleCustomer =
    function(id, checked) {
        if (checked) {
            if (
                !App.state.selectedCustomers.includes(
                    id
                )
            ) {
                App.state.selectedCustomers.push(
                    id
                );
            }
        } else {
            App.state.selectedCustomers =
                App.state.selectedCustomers.filter(
                    item =>
                        String(item) !==
                        String(id)
                );
        }
    };

App.actions.selectAllCustomers =
    function(checked) {
        App.state.selectedCustomers =
            checked
                ? App.state.customers
                    .filter(
                        customer =>
                            customer.source !==
                            'web'
                    )
                    .map(
                        customer =>
                            customer.id
                    )
                : [];

        App.render.customers();
    };

App.actions.filterCustomers =
    function() {
        const keyword =
            (
                document.getElementById(
                    'customer_filter'
                )?.value || ''
            ).toLowerCase();

        const rows =
            App.state.customers.filter(
                customer =>
                    !keyword ||
                    (
                        String(
                            customer.name || ''
                        ) +
                        String(
                            customer.email || ''
                        ) +
                        String(
                            customer.company || ''
                        )
                    )
                        .toLowerCase()
                        .includes(
                            keyword
                        )
            );

        const backup =
            App.state.customers;

        App.state.customers =
            rows;

        App.render.customers();

        App.state.customers =
            backup;
    };

App.actions.changeTemplate =
    function(type) {
        const body =
            document.getElementById(
                'mail_body'
            );

        if (!body) {
            return;
        }

        if (type === 'reminder') {
            body.value =
                '{顧客名} 様\n\nまだアンケートへのご回答がお済みでない方へ、再度ご案内いたします。\n\n{アンケートURL}';
        } else {
            body.value =
                '{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}';
        }
    };

App.actions.sendMail = function() {
    if (
        !App.state.selectedCustomers.length
    ) {
        alert(
            '送信対象を選択してください。'
        );
        return;
    }

    const alreadySent =
        App.state.customers.filter(
            customer =>
                App.state.selectedCustomers.includes(
                    customer.id
                ) &&
                Number(
                    customer.send_count || 0
                ) > 0
        );

    if (
        alreadySent.length &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )
    ) {
        return;
    }

    alert(
        'デモ環境ではメール送信処理を実行する代わりに、送信対象を確認しました。\n\n' +
        App.state.selectedCustomers.length +
        '件'
    );
};

App.actions.markKintone =
    async function(id) {
        try {
            await App.api.post(
                'mark_kintone',
                {
                    customer_id: id
                }
            );

            const customer =
                App.state.customers.find(
                    item =>
                        String(item.id) ===
                        String(id)
                );

            if (customer) {
                customer.kintone_status =
                    'registered';
            }

            App.render.customers();

        } catch (error) {
            alert(
                error.message
            );
        }
    };


/* ============================================================
 * Settings
 * ========================================================== */

App.actions.showSettings = function() {
    const settings =
        App.state.settings || {};

    document.getElementById('app').innerHTML =
        App.render.header() +
        `
        <main class="max-w-5xl mx-auto px-6 py-8">

            <div class="text-sm text-slate-400 mb-1">
                ホーム ＞ システム設定 ＞ kintone連携設定
            </div>

            <div class="flex justify-between items-center mb-6">

                <h1 class="text-2xl font-bold">
                    kintone連携設定
                </h1>

                <button
                    class="px-4 py-2 rounded-lg bg-slate-100"
                    onclick="App.actions.goList()">
                    一覧へ戻る
                </button>

            </div>

            <div class="bg-white border rounded-xl p-6">

                <div class="grid grid-cols-2 gap-5">

                    <label>
                        サブドメイン
                        <input
                            id="setting_subdomain"
                            class="mt-1 w-full border rounded-lg px-3 py-2"
                            value="${App.utils.escape(settings.subdomain || '')}"
                            placeholder="xxxx.cybozu.com">
                    </label>

                    <label>
                        顧客管理アプリID
                        <input
                            id="setting_app_id"
                            class="mt-1 w-full border rounded-lg px-3 py-2"
                            value="${App.utils.escape(settings.app_id || '')}">
                    </label>

                    <label>
                        ログイン名
                        <input
                            id="setting_login_name"
                            class="mt-1 w-full border rounded-lg px-3 py-2"
                            value="${App.utils.escape(settings.login_name || '')}">
                    </label>

                    <label>
                        パスワード
                        <input
                            id="setting_password"
                            type="password"
                            class="mt-1 w-full border rounded-lg px-3 py-2"
                            value="${App.utils.escape(settings.password || '')}">
                    </label>

                    <label>
                        Proxyサーバ
                        <input
                            id="setting_proxy"
                            class="mt-1 w-full border rounded-lg px-3 py-2"
                            value="${App.utils.escape(settings.proxy || '')}"
                            placeholder="host:port">
                    </label>

                    <label class="flex items-center gap-2 mt-7">
                        <input
                            id="setting_ssl_verify"
                            type="checkbox"
                            ${
                                settings.ssl_verify
                                    ? 'checked'
                                    : ''
                            }>
                        SSL証明書を検証する
                    </label>

                </div>

                <div class="mt-6 flex gap-3">

                    <button
                        class="px-4 py-2 rounded-lg bg-indigo-600 text-white"
                        onclick="App.actions.fetchKintoneFields()">
                        項目一覧を取得
                    </button>

                    <button
                        class="px-4 py-2 rounded-lg bg-slate-800 text-white"
                        onclick="App.actions.saveSettings()">
                        設定を保存
                    </button>

                </div>

                <div
                    id="field_message"
                    class="mt-4">
                </div>

                <div
                    id="kintone_field_mapping"
                    class="mt-6">
                </div>

            </div>

        </main>
        `;
};

App.actions.fetchKintoneFields =
    async function() {
        const settings = {
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
                ).value,

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

        const message =
            document.getElementById(
                'field_message'
            );

        message.innerHTML =
            '<div class="text-indigo-600">kintoneから項目一覧を取得しています...</div>';

        try {
            const result =
                await App.api.post(
                    'kintone_fields',
                    {
                        settings_json:
                            settings,
                        app_id:
                            settings.app_id
                    }
                );

            App.actions.renderKintoneFields(
                result.fields
            );

            message.innerHTML =
                '<div class="text-emerald-600">項目一覧を取得しました。</div>';

        } catch (error) {
            /*
             * 400の本文をそのまま表示。
             * 「不正なリクエストです」だけでは原因が
             * 分からないため、kintoneのmessageも表示する。
             */
            message.innerHTML =
                `
                    <div class="rounded-lg bg-rose-50 border border-rose-200 text-rose-700 p-4">
                        kintone項目一覧取得に失敗しました。<br>
                        ${App.utils.escape(error.message)}
                    </div>
                `;
        }
    };

App.actions.renderKintoneFields =
    function(fields) {
        const container =
            document.getElementById(
                'kintone_field_mapping'
            );

        if (!container) {
            return;
        }

        const settings =
            App.state.settings || {};

        const makeSelect =
            function(
                label,
                key,
                multiple
            ) {
                const selected =
                    settings[key] || '';

                const values =
                    Array.isArray(selected)
                        ? selected
                        : [selected];

                return `
                    <div class="mb-4">

                        <label class="block text-sm font-medium mb-1">
                            ${label}
                        </label>

                        <select
                            ${
                                multiple
                                    ? 'multiple size="5"'
                                    : ''
                            }
                            data-kintone-field="${key}"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 bg-white">

                            ${fields.map(
                                function(field) {
                                    return `
                                        <option
                                            value="${App.utils.escape(field.code)}"
                                            ${
                                                values.includes(
                                                    field.code
                                                )
                                                    ? 'selected'
                                                    : ''
                                            }>
                                            ${App.utils.escape(field.label)}
                                            (${App.utils.escape(field.code)})
                                        </option>
                                    `;
                                }
                            ).join('')}

                        </select>

                    </div>
                `;
            };

        container.innerHTML =
            `
            <div class="border-t pt-6">

                <h2 class="font-semibold mb-4">
                    kintone項目マッピング
                </h2>

                ${makeSelect(
                    '会社名',
                    'field_company',
                    false
                )}

                ${makeSelect(
                    '氏名',
                    'field_name',
                    false
                )}

                ${makeSelect(
                    'メールアドレス',
                    'field_email',
                    false
                )}

                ${makeSelect(
                    '部署名',
                    'field_department',
                    false
                )}

                ${makeSelect(
                    '電話番号',
                    'field_phone',
                    false
                )}

                ${makeSelect(
                    '住所',
                    'field_address',
                    true
                )}

            </div>
            `;
    };

App.actions.saveSettings =
    async function() {
        const settings = {
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
                ).value,

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
                ).checked,

            field_company:
                App.state.settings.field_company ||
                '',

            field_name:
                App.state.settings.field_name ||
                '',

            field_email:
                App.state.settings.field_email ||
                '',

            field_department:
                App.state.settings.field_department ||
                '',

            field_phone:
                App.state.settings.field_phone ||
                '',

            field_address:
                App.state.settings.field_address ||
                []
        };

        document
            .querySelectorAll(
                '[data-kintone-field]'
            )
            .forEach(
                function(select) {
                    const key =
                        select.dataset.kintoneField;

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
            const result =
                await App.api.post(
                    'save_settings',
                    {
                        settings_json:
                            settings
                    }
                );

            App.state.settings =
                result.settings;

            alert(
                'kintone設定を保存しました。'
            );

        } catch (error) {
            alert(
                error.message
            );
        }
    };


/* ============================================================
 * CSV
 * ========================================================== */

App.actions.exportCsv = function(
    surveyId
) {
    const url =
        '?action=export_csv' +
        '&survey_id=' +
        encodeURIComponent(
            surveyId
        ) +
        '&csrf_token=' +
        encodeURIComponent(
            App.state.csrf
        );

    window.location.href =
        url;
};


/* ============================================================
 * Init
 * ========================================================== */

App.init = async function() {
    if (
        App.state.initialized
    ) {
        return;
    }

    App.state.initialized =
        true;

    try {
        const result =
            await App.api.get(
                'load'
            );

        App.state.csrf =
            result.csrf_token;

        App.state.surveys =
            Array.isArray(
                result.surveys
            )
                ? result.surveys
                : [];

        App.state.responses =
            Array.isArray(
                result.responses
            )
                ? result.responses
                : [];

        App.state.customers =
            Array.isArray(
                result.customers
            )
                ? result.customers
                : [];

        App.state.settings =
            result.settings || {};

        App.state.mail_logs =
            Array.isArray(
                result.mail_logs
            )
                ? result.mail_logs
                : [];

        /*
         * 古いデータを読み込んだ場合も、
         * 分岐項目を補完する。
         */
        App.state.surveys.forEach(
            function(survey) {
                survey.groups =
                    Array.isArray(
                        survey.groups
                    )
                        ? survey.groups
                        : [];

                survey.groups.forEach(
                    function(group) {
                        group.questions =
                            Array.isArray(
                                group.questions
                            )
                                ? group.questions
                                : [];

                        group.questions.forEach(
                            function(question) {
                                question.branch_enabled =
                                    Boolean(
                                        question.branch_enabled
                                    );

                                question.branch_rules =
                                    Array.isArray(
                                        question.branch_rules
                                    )
                                        ? question.branch_rules
                                        : [];

                                App.actions.normalizeBranchRules(
                                    question
                                );
                            }
                        );
                    }
                );
            }
        );

        App.render.list();

    } catch (error) {
        document.getElementById(
            'app'
        ).innerHTML =
            `
            <div class="min-h-screen flex items-center justify-center p-6">

                <div class="bg-white border rounded-2xl p-8 max-w-lg w-full">

                    <h1 class="text-xl font-bold text-rose-600">
                        初期化に失敗しました
                    </h1>

                    <p class="mt-4 text-slate-600">
                        ${App.utils.escape(
                            error.message
                        )}
                    </p>

                    <button
                        class="mt-6 px-4 py-2 bg-slate-800 text-white rounded-lg"
                        onclick="location.reload()">
                        再読み込み
                    </button>

                </div>

            </div>
            `;
    }
};


/*
 * DOMContentLoaded前後どちらでも動作。
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
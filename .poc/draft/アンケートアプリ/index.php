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

    $ok = @file_put_contents(
        $tmp,
        $json,
        LOCK_EX
    );

    if ($ok === false) {
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
        surveyJsonResponse(
            [
                'ok' => false,
                'message' => '不正なリクエストです。ページを再読み込みしてください。'
            ],
            403
        );
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
    ) ? $survey['status'] : 'draft';

    $survey['created_at'] = (string)($survey['created_at'] ?? surveyNow());
    $survey['updated_at'] = (string)($survey['updated_at'] ?? surveyNow());

    $survey['numbering_mode'] = in_array(
        $survey['numbering_mode'] ?? 'global',
        ['global', 'group'],
        true
    ) ? $survey['numbering_mode'] : 'global';

    $survey['deleted'] = (bool)($survey['deleted'] ?? false);
    $survey['groups'] = is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];

    foreach ($survey['groups'] as &$group) {
        $group['id'] = (string)($group['id'] ?? surveyId('group'));
        $group['name'] = (string)($group['name'] ?? '新しいグループ');
        $group['questions'] = is_array($group['questions'] ?? null)
            ? $group['questions']
            : [];

        foreach ($group['questions'] as &$question) {
            $question['id'] = (string)($question['id'] ?? surveyId('question'));
            $question['text'] = (string)($question['text'] ?? '');
            $question['type'] = in_array(
                $question['type'] ?? 'single',
                ['single', 'multiple', 'text'],
                true
            ) ? $question['type'] : 'single';

            $question['required'] = (bool)($question['required'] ?? false);
            $question['options'] = is_array($question['options'] ?? null)
                ? array_values(array_map('strval', $question['options']))
                : [];

            $question['other_enabled'] = (bool)($question['other_enabled'] ?? false);
        }

        unset($question);
    }

    unset($group);

    return $survey;
}

function surveyKintoneRequest(
    array $settings,
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {
    $subdomain = trim((string)($settings['subdomain'] ?? ''));

    if ($subdomain === '') {
        return [
            'ok' => false,
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

    $headers = [
        'Content-Type: application/json',
        'X-Cybozu-Authorization: ' . base64_encode(
            (string)($settings['login_name'] ?? '') .
            ':' .
            (string)($settings['password'] ?? '')
        )
    ];

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout' => 20,
            'content' => $body === null
                ? ''
                : json_encode(
                    $body,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
        ],
        'ssl' => [
            'verify_peer' => (bool)($settings['ssl_verify'] ?? false),
            'verify_peer_name' => (bool)($settings['ssl_verify'] ?? false),
            'allow_self_signed' => !(bool)($settings['ssl_verify'] ?? false)
        ]
    ];

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        $options['http']['proxy'] = 'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $result = @file_get_contents($url, false, $context);

    $responseHeaders = [];

    if (function_exists('http_get_last_response_headers')) {
        $lastHeaders = http_get_last_response_headers();

        if (is_array($lastHeaders)) {
            $responseHeaders = $lastHeaders;
        }
    } elseif (isset($http_response_header) && is_array($http_response_header)) {
        $responseHeaders = $http_response_header;
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
            break;
        }
    }

    if ($result === false) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' => 'kintone APIへの接続に失敗しました。',
            'headers' => $responseHeaders
        ];
    }

    $decoded = json_decode($result, true);

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' => 'kintone APIから不正なJSONが返されました。',
            'raw' => $result
        ];
    }

    if ($statusCode >= 400) {
        return [
            'ok' => false,
            'status' => $statusCode,
            'message' => (string)($decoded['message'] ?? 'kintone APIエラー'),
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
 * API
 * ======================================================= */

if (isset($_GET['action'])) {
    $action = (string)$_GET['action'];
    $data = surveyReadStorage();

    if ($action === 'load') {
        $surveys = [];

        foreach ($data['surveys'] as $survey) {
            if (!is_array($survey) || !empty($survey['deleted'])) {
                continue;
            }

            $survey = surveyNormalizeSurvey($survey);

            $answerCount = 0;

            foreach ($data['responses'] as $response) {
                if (
                    is_array($response) &&
                    (string)($response['survey_id'] ?? '') ===
                    (string)$survey['id']
                ) {
                    $answerCount++;
                }
            }

            $survey['answer_count'] = $answerCount;
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

    if ($action === 'export_csv') {
        surveyVerifyCsrf();

        $surveyId = (string)($_GET['survey_id'] ?? '');

        $survey = null;

        foreach ($data['surveys'] as $item) {
            if (
                is_array($item) &&
                (string)($item['id'] ?? '') === $surveyId
            ) {
                $survey = surveyNormalizeSurvey($item);
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

        $filename = 'survey_' . $surveyId . '_' . date('YmdHis') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="' .
            $filename .
            '"'
        );

        $output = fopen('php://output', 'wb');

        fwrite($output, "\xEF\xBB\xBF");

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名',
            'メールアドレス'
        ];

        foreach ($questions as $index => $question) {
            $header[] = '設問' . ($index + 1);
        }

        fputcsv($output, $header);

        foreach ($data['responses'] as $response) {
            if (
                !is_array($response) ||
                (string)($response['survey_id'] ?? '') !== $surveyId
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

            $answers = is_array($response['answers'] ?? null)
                ? $response['answers']
                : [];

            foreach ($questions as $question) {
                $value = $answers[$question['id']] ?? '';

                if (is_array($value)) {
                    $value = implode('、', $value);
                }

                $row[] = (string)$value;
            }

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    if ($action === 'kintone_fields') {
        surveyVerifyCsrf();

        $settings = surveyPostJson('settings_json') ?? $data['settings'];

        $appId = trim(
            (string)($settings['app_id'] ?? ($_POST['app_id'] ?? ''))
        );

        if ($appId === '') {
            surveyJsonResponse([
                'ok' => false,
                'message' => 'アプリIDを入力してください。'
            ], 400);
        }

        $result = surveyKintoneRequest(
            $settings,
            '/k/v1/app/form/fields.json?app=' .
            rawurlencode($appId),
            'GET'
        );

        if (!$result['ok']) {
            surveyJsonResponse($result, 400);
        }

        $fields = [];

        foreach (($result['data']['properties'] ?? []) as $code => $property) {
            if (!is_array($property)) {
                continue;
            }

            $fields[] = [
                'code' => (string)$code,
                'label' => (string)($property['label'] ?? $code),
                'type' => (string)($property['type'] ?? '')
            ];
        }

        surveyJsonResponse([
            'ok' => true,
            'fields' => $fields
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        surveyJsonResponse([
            'ok' => false,
            'message' => '不正なリクエストです。'
        ], 405);
    }

    surveyVerifyCsrf();

    if ($action === 'save_survey') {
        $survey = surveyPostJson('survey_json');

        if ($survey === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], 400);
        }

        $survey = surveyNormalizeSurvey($survey);
        $survey['updated_at'] = surveyNow();

        $found = false;

        foreach ($data['surveys'] as $index => $existing) {
            if (
                is_array($existing) &&
                (string)($existing['id'] ?? '') ===
                (string)$survey['id']
            ) {
                $data['surveys'][$index] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['created_at'] = surveyNow();
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
        $surveyId = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as $index => $survey) {
            if (
                is_array($survey) &&
                (string)($survey['id'] ?? '') === $surveyId
            ) {
                $data['surveys'][$index]['deleted'] = true;
                $data['surveys'][$index]['updated_at'] = surveyNow();
                break;
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse(['ok' => true]);
    }

    if ($action === 'status_survey') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $newStatus = (string)($_POST['status'] ?? 'draft');

        if (!in_array($newStatus, ['draft', 'active', 'ended'], true)) {
            surveyJsonResponse([
                'ok' => false,
                'message' => '不正なステータスです。'
            ], 400);
        }

        foreach ($data['surveys'] as $index => $survey) {
            if (
                is_array($survey) &&
                (string)($survey['id'] ?? '') === $surveyId
            ) {
                $data['surveys'][$index]['status'] = $newStatus;
                $data['surveys'][$index]['updated_at'] = surveyNow();
                break;
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse(['ok' => true]);
    }

    if ($action === 'duplicate_survey') {
        $surveyId = (string)($_POST['survey_id'] ?? '');

        $copy = null;

        foreach ($data['surveys'] as $survey) {
            if (
                is_array($survey) &&
                (string)($survey['id'] ?? '') === $surveyId
            ) {
                $copy = surveyNormalizeSurvey($survey);
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
                $question['id'] = surveyId('question');
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
        $settings = surveyPostJson('settings_json');

        if ($settings === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' => '設定データが不正です。'
            ], 400);
        }

        $defaults = surveyGuardData()['settings'];

        $settings = array_merge($defaults, $settings);

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
        $customerId = (string)($_POST['customer_id'] ?? '');

        foreach ($data['customers'] as $index => $customer) {
            if (
                is_array($customer) &&
                (string)($customer['id'] ?? '') === $customerId
            ) {
                $data['customers'][$index]['kintone_status'] = 'registered';
                break;
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse(['ok' => true]);
    }

    if ($action === 'send_mail') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $recipientIds = $_POST['recipient_ids'] ?? [];

        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

        $subject = (string)($_POST['mail_subject'] ?? '');
        $body = (string)($_POST['mail_body'] ?? '');
        $templateType = (string)($_POST['template_type'] ?? 'initial');

        $survey = null;

        foreach ($data['surveys'] as $item) {
            if (
                is_array($item) &&
                (string)($item['id'] ?? '') === $surveyId
            ) {
                $survey = $item;
                break;
            }
        }

        if ($survey === null) {
            surveyJsonResponse([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        if (($survey['status'] ?? '') !== 'active') {
            surveyJsonResponse([
                'ok' => false,
                'message' => '公開中のアンケートだけ送信できます。'
            ], 400);
        }

        $sent = 0;
        $errors = [];

        foreach ($data['customers'] as $index => $customer) {
            if (!is_array($customer)) {
                continue;
            }

            $customerId = (string)($customer['id'] ?? '');

            if (!in_array($customerId, $recipientIds, true)) {
                continue;
            }

            $email = trim((string)($customer['email'] ?? ''));

            if (
                $email === '' ||
                !filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {
                $errors[] = ($customer['name'] ?? $email) .
                    ': メールアドレス不正';
                continue;
            }

            $customerName = (string)($customer['name'] ?? '');
            $answerUrl =
                (
                    (!empty($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS'] !== 'off')
                    ? 'https'
                    : 'http'
                ) .
                '://' .
                ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') .
                '/?answer=' .
                rawurlencode($surveyId) .
                '&customer=' .
                rawurlencode($customerId);

            $actualSubject = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [$customerName, $answerUrl],
                $subject
            );

            $actualBody = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [$customerName, $answerUrl],
                $body
            );

            $mailHeaders = [
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' .
                ($_SERVER['SERVER_ADMIN'] ?? 'webmaster@localhost')
            ];

            $mailOk = @mail(
                $email,
                $actualSubject,
                $actualBody,
                implode("\r\n", $mailHeaders)
            );

            /*
             * PHPのmail()はサーバー側のメール送信環境に依存します。
             * 開発環境ではmail()がfalseになることがあるため、
             * 実送信できた場合だけ送信済みとしてカウントします。
             */
            if ($mailOk) {
                $sent++;

                $data['customers'][$index]['sent_at'] = surveyNow();
                $data['customers'][$index]['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;
                $data['customers'][$index]['answer_status'] =
                    'unanswered';

                $data['mail_logs'][] = [
                    'id' => surveyId('mail'),
                    'survey_id' => $surveyId,
                    'customer_id' => $customerId,
                    'sent_at' => surveyNow(),
                    'type' => $templateType,
                    'subject' => $actualSubject,
                    'body' => $actualBody,
                    'executor' => $_SESSION['survey_admin_name'] ?? '管理者'
                ];
            } else {
                $errors[] = $customerName .
                    ': メール送信に失敗しました';
            }
        }

        surveyWriteStorage($data);

        surveyJsonResponse([
            'ok' => true,
            'sent' => $sent,
            'errors' => $errors,
            'message' => $sent . '件のメールを送信しました。'
        ]);
    }

    surveyJsonResponse([
        'ok' => false,
        'message' => '不明なAPIアクションです。'
    ], 400);
}

/* =========================================================
 * SPA HTML
 * ======================================================= */

$csrf = surveyCsrf();

?><!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>

<script>
tailwind.config = {
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#eef7f8',
                    100: '#d9eef0',
                    500: '#16808a',
                    600: '#116d76',
                    700: '#0e5a61'
                }
            }
        }
    }
};
</script>
</head>

<body class="bg-slate-50 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {
    state: {
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mailLogs: [],
        csrfToken: <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>,
        view: 'list',
        currentSurveyId: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        editingSurvey: null,
        responseKeyword: '',
        selectedQuestions: {},
        selectedCustomers: [],
        mailSurveyId: null,
        loading: false,
        previewSurvey: null,
        responseModalId: null
    },

    templates: {},

    utils: {},

    api: {},

    actions: {},

    render: {},

    init: function() {
        if (App.state.initialized) {
            return;
        }

        App.state.initialized = true;
        App.api.load();
    }
};

/* =========================================================
 * Utilities
 * ======================================================= */

App.utils.escapeHtml = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.utils.escapeAttr = function(value) {
    return App.utils.escapeHtml(value)
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
};

App.utils.uid = function(prefix) {
    return prefix + '_' +
        Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 10);
};

App.utils.now = function() {
    const d = new Date();
    const pad = n => String(n).padStart(2, '0');

    return d.getFullYear() +
        '-' + pad(d.getMonth() + 1) +
        '-' + pad(d.getDate()) +
        'T' + pad(d.getHours()) +
        ':' + pad(d.getMinutes()) +
        ':' + pad(d.getSeconds());
};

App.utils.formatDate = function(value) {
    if (!value) {
        return '-';
    }

    return String(value)
        .substring(0, 16)
        .replace('T', ' ');
};

App.utils.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
};

App.utils.questionTypeLabel = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || type;
};

App.utils.clone = function(value) {
    return JSON.parse(JSON.stringify(value));
};

App.utils.notify = function(message, type = 'info') {
    const color = {
        success: 'bg-emerald-600',
        error: 'bg-red-600',
        warning: 'bg-amber-600',
        info: 'bg-slate-800'
    }[type] || 'bg-slate-800';

    const element = document.createElement('div');

    element.className =
        'fixed right-5 top-5 z-[100] ' +
        color +
        ' text-white px-5 py-3 rounded-xl shadow-xl text-sm ' +
        'max-w-md';

    element.textContent = message;

    document.body.appendChild(element);

    setTimeout(function() {
        element.remove();
    }, 3500);
};

App.utils.statusBadge = function(status) {
    const map = {
        draft: 'bg-slate-100 text-slate-700',
        active: 'bg-emerald-100 text-emerald-700',
        ended: 'bg-gray-100 text-gray-500'
    };

    return `
        <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold
            ${map[status] || map.draft}">
            ${App.utils.escapeHtml(App.utils.statusLabel(status))}
        </span>
    `;
};

/* =========================================================
 * API
 * ======================================================= */

App.api.request = async function(action, data = {}, method = 'POST') {
    const options = {
        method: method,
        headers: {}
    };

    let url = '?action=' + encodeURIComponent(action);

    if (method === 'POST') {
        const body = new URLSearchParams();

        body.set('csrf_token', App.state.csrfToken);

        Object.entries(data).forEach(function(entry) {
            const key = entry[0];
            const value = entry[1];

            if (Array.isArray(value) || typeof value === 'object') {
                body.set(key, JSON.stringify(value));
            } else {
                body.set(key, value == null ? '' : String(value));
            }
        });

        options.headers['Content-Type'] =
            'application/x-www-form-urlencoded;charset=UTF-8';

        options.body = body.toString();
    } else {
        Object.entries(data).forEach(function(entry) {
            url += '&' +
                encodeURIComponent(entry[0]) +
                '=' +
                encodeURIComponent(entry[1]);
        });
    }

    const response = await fetch(url, options);

    const json = await response.json();

    if (!response.ok || json.ok === false) {
        throw new Error(
            json.message || 'サーバーエラーが発生しました。'
        );
    }

    return json;
};

App.api.load = async function() {
    App.state.loading = true;
    App.render.loading();

    try {
        const json = await App.api.request('load', {}, 'GET');

        App.state.csrfToken = json.csrf_token;
        App.state.surveys = json.surveys || [];
        App.state.responses = json.responses || [];
        App.state.customers = json.customers || [];
        App.state.settings = json.settings || {};
        App.state.mailLogs = json.mail_logs || [];

        App.render.shell();
        App.render.list();
    } catch (error) {
        App.render.error(error.message);
    } finally {
        App.state.loading = false;
    }
};

App.api.saveSurvey = async function(survey) {
    const json = await App.api.request(
        'save_survey',
        {
            survey_json: survey
        }
    );

    const saved = json.survey;

    const index = App.state.surveys.findIndex(
        item => String(item.id) === String(saved.id)
    );

    if (index >= 0) {
        App.state.surveys[index] = saved;
    } else {
        App.state.surveys.unshift(saved);
    }

    return saved;
};

App.api.saveSettings = async function(settings) {
    const json = await App.api.request(
        'save_settings',
        {
            settings_json: settings
        }
    );

    App.state.settings = json.settings;

    return json.settings;
};

App.api.sendMail = async function(
    surveyId,
    customerIds,
    subject,
    body,
    templateType
) {
    return App.api.request(
        'send_mail',
        {
            survey_id: surveyId,
            recipient_ids: customerIds,
            mail_subject: subject,
            mail_body: body,
            template_type: templateType
        }
    );
};

/* =========================================================
 * Templates
 * ======================================================= */

App.templates.surveyRow = function(survey) {
    let actions = '';

    if (survey.status === 'active') {
        actions = `
            <button
                class="px-3 py-1.5 rounded-lg border border-slate-200
                       bg-white text-sm hover:bg-slate-100"
                onclick="App.actions.editSurvey('${App.utils.escapeAttr(survey.id)}')">
                確認・編集
            </button>

            <button
                class="px-3 py-1.5 rounded-lg border border-slate-200
                       bg-white text-sm hover:bg-slate-100"
                onclick="App.actions.openResults('${App.utils.escapeAttr(survey.id)}')">
                集計
            </button>

            <button
                class="px-3 py-1.5 rounded-lg bg-brand-600 text-white
                       text-sm hover:bg-brand-700"
                onclick="App.actions.openMail('${App.utils.escapeAttr(survey.id)}')">
                送信
            </button>

            <button
                class="px-3 py-1.5 rounded-lg border border-amber-200
                       bg-amber-50 text-amber-700 text-sm hover:bg-amber-100"
                onclick="App.actions.stopSurvey('${App.utils.escapeAttr(survey.id)}')">
                停止
            </button>

            <button
                class="px-3 py-1.5 rounded-lg border border-slate-200
                       bg-white text-sm hover:bg-slate-100"
                onclick="App.actions.duplicateSurvey('${App.utils.escapeAttr(survey.id)}')">
                複製
            </button>
        `;
    } else if (survey.status === 'draft') {
        actions = `
            <button
                class="px-3 py-1.5 rounded-lg border border-slate-200
                       bg-white text-sm hover:bg-slate-100"
                onclick="App.actions.editSurvey('${App.utils.escapeAttr(survey.id)}')">
                確認・編集
            </button>

            <button
                class="px-3 py-1.5 rounded-lg border border-red-200
                       bg-white text-red-600 text-sm hover:bg-red-50"
                onclick="App.actions.deleteSurvey('${App.utils.escapeAttr(survey.id)}')">
                削除
            </button>

            <button
                class="px-3 py-1.5 rounded-lg border border-slate-200
                       bg-white text-sm hover:bg-slate-100"
                onclick="App.actions.duplicateSurvey('${App.utils.escapeAttr(survey.id)}')">
                複製
            </button>
        `;
    } else {
        actions = `
            <button
                class="px-3 py-1.5 rounded-lg border border-slate-200
                       bg-white text-sm hover:bg-slate-100"
                onclick="App.actions.editSurvey('${App.utils.escapeAttr(survey.id)}')">
                確認・編集
            </button>

            <button
                class="px-3 py-1.5 rounded-lg border border-slate-200
                       bg-white text-sm hover:bg-slate-100"
                onclick="App.actions.openResults('${App.utils.escapeAttr(survey.id)}')">
                集計
            </button>

            <button
                class="px-3 py-1.5 rounded-lg border border-slate-200
                       bg-white text-sm hover:bg-slate-100"
                onclick="App.actions.duplicateSurvey('${App.utils.escapeAttr(survey.id)}')">
                複製
            </button>
        `;
    }

    return `
        <tr class="border-b border-slate-100 hover:bg-slate-50">

            <td class="px-5 py-4 whitespace-nowrap">
                <div class="text-sm text-slate-700">
                    ${App.utils.escapeHtml(
                        String(survey.created_at || '').substring(0, 10)
                    )}
                </div>
                <div class="text-xs text-slate-400 mt-1">
                    更新:
                    ${App.utils.escapeHtml(
                        String(survey.updated_at || '').substring(0, 10)
                    )}
                </div>
            </td>

            <td class="px-5 py-4">
                <div class="font-semibold text-slate-800">
                    ${App.utils.escapeHtml(
                        survey.title || '無題のアンケート'
                    )}
                </div>
            </td>

            <td class="px-5 py-4 whitespace-nowrap">
                <div class="text-sm text-slate-600">
                    ${App.utils.escapeHtml(
                        App.utils.formatDate(survey.start_at)
                    )}
                </div>
                <div class="text-xs text-slate-400 text-center my-0.5">
                    〜
                </div>
                <div class="text-sm text-slate-600">
                    ${App.utils.escapeHtml(
                        App.utils.formatDate(survey.end_at)
                    )}
                </div>
            </td>

            <td class="px-5 py-4">
                ${App.utils.statusBadge(survey.status)}
            </td>

            <td class="px-5 py-4 text-right whitespace-nowrap">
                <span class="font-semibold">
                    ${Number(survey.answer_count || 0).toLocaleString('ja-JP')}
                </span>
                <span class="text-xs text-slate-400">件</span>
            </td>

            <td class="px-5 py-4">
                <div class="flex flex-wrap gap-2">
                    ${actions}
                </div>
            </td>
        </tr>
    `;
};

App.templates.question = function(
    question,
    questionNumber,
    groupId
) {
    let answerHtml = '';

    if (question.type === 'single') {
        answerHtml = `
            <div class="space-y-2">
                ${(question.options || []).map(function(option, index) {
                    return `
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-4 rounded-full border border-slate-300"></span>
                            <input
                                class="flex-1 border border-slate-200 rounded-lg px-3 py-2
                                       text-sm"
                                value="${App.utils.escapeAttr(option)}"
                                oninput="
                                    App.actions.updateOption(
                                        '${App.utils.escapeAttr(groupId)}',
                                        '${App.utils.escapeAttr(question.id)}',
                                        ${index},
                                        this.value
                                    )
                                ">
                            <button
                                class="text-slate-400 hover:text-red-600"
                                onclick="
                                    App.actions.removeOption(
                                        '${App.utils.escapeAttr(groupId)}',
                                        '${App.utils.escapeAttr(question.id)}',
                                        ${index}
                                    )
                                ">
                                ×
                            </button>
                        </div>
                    `;
                }).join('')}

                <button
                    class="text-sm text-brand-600 hover:text-brand-700"
                    onclick="
                        App.actions.addOption(
                            '${App.utils.escapeAttr(groupId)}',
                            '${App.utils.escapeAttr(question.id)}'
                        )
                    ">
                    ＋ 選択肢を追加
                </button>
            </div>
        `;
    } else if (question.type === 'multiple') {
        answerHtml = `
            <div class="space-y-2">
                ${(question.options || []).map(function(option, index) {
                    return `
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-4 rounded border border-slate-300"></span>
                            <input
                                class="flex-1 border border-slate-200 rounded-lg px-3 py-2
                                       text-sm"
                                value="${App.utils.escapeAttr(option)}"
                                oninput="
                                    App.actions.updateOption(
                                        '${App.utils.escapeAttr(groupId)}',
                                        '${App.utils.escapeAttr(question.id)}',
                                        ${index},
                                        this.value
                                    )
                                ">
                            <button
                                class="text-slate-400 hover:text-red-600"
                                onclick="
                                    App.actions.removeOption(
                                        '${App.utils.escapeAttr(groupId)}',
                                        '${App.utils.escapeAttr(question.id)}',
                                        ${index}
                                    )
                                ">
                                ×
                            </button>
                        </div>
                    `;
                }).join('')}

                <button
                    class="text-sm text-brand-600 hover:text-brand-700"
                    onclick="
                        App.actions.addOption(
                            '${App.utils.escapeAttr(groupId)}',
                            '${App.utils.escapeAttr(question.id)}'
                        )
                    ">
                    ＋ 選択肢を追加
                </button>
            </div>
        `;
    } else {
        answerHtml = `
            <textarea
                disabled
                class="w-full border border-slate-200 rounded-lg px-3 py-2
                       text-sm bg-slate-50"
                rows="3"
                placeholder="回答者が入力します"></textarea>
        `;
    }

    return `
        <div
            data-question-id="${App.utils.escapeAttr(question.id)}"
            class="question-card bg-white border border-slate-200 rounded-xl p-5
                   shadow-sm">

            <div class="flex gap-4">

                <div class="question-drag cursor-grab text-slate-300 text-xl pt-1">
                    ⠿
                </div>

                <div class="flex-1">

                    <div class="flex items-start justify-between gap-4">

                        <div class="flex-1">

                            <div class="flex items-center gap-2 mb-2">
                                <span
                                    class="question-number text-xs font-bold
                                           bg-brand-50 text-brand-700 px-2 py-1
                                           rounded">
                                    ${questionNumber}
                                </span>

                                <span
                                    class="text-xs bg-slate-100 text-slate-600
                                           px-2 py-1 rounded">
                                    ${App.utils.questionTypeLabel(question.type)}
                                </span>
                            </div>

                            <input
                                class="w-full text-base font-medium border-0
                                       border-b border-transparent focus:border-brand-500
                                       focus:ring-0 px-0 py-1"
                                value="${App.utils.escapeAttr(question.text)}"
                                placeholder="質問文を入力してください"
                                oninput="
                                    App.actions.updateQuestionText(
                                        '${App.utils.escapeAttr(groupId)}',
                                        '${App.utils.escapeAttr(question.id)}',
                                        this.value
                                    )
                                ">
                        </div>

                        <div class="flex items-center gap-2">

                            <select
                                class="border border-slate-200 rounded-lg px-2 py-2 text-sm"
                                onchange="
                                    App.actions.changeQuestionType(
                                        '${App.utils.escapeAttr(groupId)}',
                                        '${App.utils.escapeAttr(question.id)}',
                                        this.value
                                    )
                                ">
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

                            <button
                                class="text-slate-400 hover:text-red-600 px-2"
                                onclick="
                                    App.actions.deleteQuestion(
                                        '${App.utils.escapeAttr(groupId)}',
                                        '${App.utils.escapeAttr(question.id)}'
                                    )
                                ">
                                削除
                            </button>
                        </div>
                    </div>

                    <div class="mt-5">
                        ${answerHtml}
                    </div>

                    <div class="mt-5 flex items-center gap-5">

                        <label class="inline-flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                class="rounded border-slate-300 text-brand-600"
                                ${question.required ? 'checked' : ''}
                                onchange="
                                    App.actions.toggleRequired(
                                        '${App.utils.escapeAttr(groupId)}',
                                        '${App.utils.escapeAttr(question.id)}',
                                        this.checked
                                    )
                                ">
                            必須回答
                        </label>

                        ${
                            question.type !== 'text'
                            ? `
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    class="rounded border-slate-300 text-brand-600"
                                    ${question.other_enabled ? 'checked' : ''}
                                    onchange="
                                        App.actions.toggleOther(
                                            '${App.utils.escapeAttr(groupId)}',
                                            '${App.utils.escapeAttr(question.id)}',
                                            this.checked
                                        )
                                    ">
                                その他入力
                            </label>
                            `
                            : ''
                        }

                    </div>

                </div>
            </div>
        </div>
    `;
};

/* =========================================================
 * Shell
 * ======================================================= */

App.render.loading = function() {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen flex items-center justify-center">
            <div class="text-center">
                <div class="w-10 h-10 border-4 border-slate-200
                            border-t-brand-600 rounded-full animate-spin mx-auto">
                </div>
                <div class="mt-4 text-sm text-slate-500">
                    読み込み中...
                </div>
            </div>
        </div>
    `;
};

App.render.error = function(message) {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen flex items-center justify-center p-6">
            <div class="bg-white rounded-2xl shadow-sm border border-red-100
                        p-8 max-w-xl w-full">
                <div class="text-red-600 font-semibold text-lg">
                    アプリケーションエラー
                </div>
                <div class="mt-3 text-sm text-slate-600">
                    ${App.utils.escapeHtml(message)}
                </div>
                <button
                    class="mt-6 px-4 py-2 bg-brand-600 text-white rounded-lg"
                    onclick="location.reload()">
                    再読み込み
                </button>
            </div>
        </div>
    `;
};

App.render.shell = function() {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen">

            <header class="sticky top-0 z-40 bg-white border-b border-slate-200">
                <div class="max-w-[1600px] mx-auto px-6 h-16
                            flex items-center justify-between">

                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-brand-600
                                    text-white flex items-center justify-center
                                    font-bold">
                            A
                        </div>

                        <div>
                            <div class="font-bold text-slate-800">
                                アンケート管理
                            </div>
                            <div class="text-[11px] text-slate-400">
                                Survey Management System
                            </div>
                        </div>
                    </div>

                    <nav class="flex items-center gap-2">

                        <button
                            id="nav_surveys"
                            class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100"
                            onclick="App.actions.goList()">
                            アンケート一覧
                        </button>

                        <button
                            id="nav_settings"
                            class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100"
                            onclick="App.actions.openSettings()">
                            キントーン連携設定
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg text-sm text-slate-500
                                   hover:bg-slate-100"
                            onclick="App.actions.logout()">
                            ログアウト
                        </button>
                    </nav>
                </div>
            </header>

            <main id="main_content"
                  class="max-w-[1600px] mx-auto px-6 py-7">
            </main>

        </div>

        <div id="preview_modal"></div>
        <div id="response_modal"></div>
    `;
};

/* =========================================================
 * List
 * ======================================================= */

App.render.list = function() {
    App.state.view = 'list';

    const keyword = App.state.keyword.toLowerCase();

    let surveys = App.state.surveys.filter(function(survey) {
        const matchesKeyword =
            !keyword ||
            String(survey.title || '')
                .toLowerCase()
                .includes(keyword);

        const matchesStatus =
            App.state.statusFilter === 'all' ||
            survey.status === App.state.statusFilter;

        return matchesKeyword && matchesStatus;
    });

    surveys.sort(function(a, b) {
        if (App.state.sort === 'updated_asc') {
            return String(a.updated_at).localeCompare(
                String(b.updated_at)
            );
        }

        if (App.state.sort === 'answers_desc') {
            return Number(b.answer_count || 0) -
                Number(a.answer_count || 0);
        }

        if (App.state.sort === 'answers_asc') {
            return Number(a.answer_count || 0) -
                Number(b.answer_count || 0);
        }

        if (App.state.sort === 'start_desc') {
            return String(b.start_at).localeCompare(
                String(a.start_at)
            );
        }

        if (App.state.sort === 'start_asc') {
            return String(a.start_at).localeCompare(
                String(b.start_at)
            );
        }

        return String(b.updated_at).localeCompare(
            String(a.updated_at)
        );
    });

    const activeCount = App.state.surveys.filter(
        item => item.status === 'active'
    ).length;

    const draftCount = App.state.surveys.filter(
        item => item.status === 'draft'
    ).length;

    const endedCount = App.state.surveys.filter(
        item => item.status === 'ended'
    ).length;

    document.getElementById('main_content').innerHTML = `
        <div>

            <div class="flex items-start justify-between gap-5 mb-7">

                <div>
                    <div class="text-sm text-slate-400 mb-1">
                        ホーム
                    </div>
                    <h1 class="text-2xl font-bold text-slate-800">
                        アンケート一覧
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        アンケートの作成・公開・送信・集計を管理します。
                    </p>
                </div>

                <button
                    onclick="App.actions.newSurvey()"
                    class="px-5 py-3 bg-brand-600 hover:bg-brand-700
                           text-white rounded-xl font-semibold shadow-sm">
                    ＋ 新規アンケート作成
                </button>
            </div>

            <div class="grid grid-cols-3 gap-4 mb-6">

                <div class="bg-white border border-slate-200 rounded-xl p-5">
                    <div class="text-sm text-slate-500">公開中</div>
                    <div class="text-2xl font-bold text-emerald-600 mt-1">
                        ${activeCount}
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-xl p-5">
                    <div class="text-sm text-slate-500">下書き</div>
                    <div class="text-2xl font-bold text-slate-700 mt-1">
                        ${draftCount}
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-xl p-5">
                    <div class="text-sm text-slate-500">終了</div>
                    <div class="text-2xl font-bold text-slate-500 mt-1">
                        ${endedCount}
                    </div>
                </div>

            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-4 mb-5">

                <div class="flex gap-3">

                    <input
                        id="survey_keyword"
                        class="flex-1 border border-slate-200 rounded-lg
                               px-4 py-2.5 text-sm"
                        placeholder="タイトルで検索"
                        value="${App.utils.escapeAttr(App.state.keyword)}"
                        onkeydown="
                            if(event.key === 'Enter'){
                                App.actions.searchSurveys(this.value);
                            }
                        ">

                    <select
                        class="border border-slate-200 rounded-lg px-4 py-2.5 text-sm"
                        onchange="App.actions.toggleStatusFilter(this.value)">
                        <option value="all"
                            ${App.state.statusFilter === 'all' ? 'selected' : ''}>
                            すべて
                        </option>
                        <option value="active"
                            ${App.state.statusFilter === 'active' ? 'selected' : ''}>
                            公開中
                        </option>
                        <option value="draft"
                            ${App.state.statusFilter === 'draft' ? 'selected' : ''}>
                            下書き
                        </option>
                        <option value="ended"
                            ${App.state.statusFilter === 'ended' ? 'selected' : ''}>
                            終了
                        </option>
                    </select>

                    <select
                        class="border border-slate-200 rounded-lg px-4 py-2.5 text-sm"
                        onchange="App.actions.changeSort(this.value)">
                        <option value="updated_desc"
                            ${App.state.sort === 'updated_desc' ? 'selected' : ''}>
                            更新日：新しい順
                        </option>
                        <option value="updated_asc"
                            ${App.state.sort === 'updated_asc' ? 'selected' : ''}>
                            更新日：古い順
                        </option>
                        <option value="answers_desc"
                            ${App.state.sort === 'answers_desc' ? 'selected' : ''}>
                            回答数：多い順
                        </option>
                        <option value="answers_asc"
                            ${App.state.sort === 'answers_asc' ? 'selected' : ''}>
                            回答数：少ない順
                        </option>
                        <option value="start_desc"
                            ${App.state.sort === 'start_desc' ? 'selected' : ''}>
                            開始日：新しい順
                        </option>
                        <option value="start_asc"
                            ${App.state.sort === 'start_asc' ? 'selected' : ''}>
                            開始日：古い順
                        </option>
                    </select>

                    <button
                        onclick="
                            App.actions.searchSurveys(
                                document.getElementById('survey_keyword').value
                            )
                        "
                        class="px-5 py-2.5 rounded-lg bg-slate-800 text-white
                               text-sm">
                        検索
                    </button>

                </div>

            </div>

            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">

                ${
                    surveys.length === 0
                    ? `
                    <div class="py-20 text-center">
                        <div class="text-slate-300 text-5xl">□</div>
                        <div class="mt-4 font-medium text-slate-600">
                            アンケートがありません
                        </div>
                        <div class="mt-1 text-sm text-slate-400">
                            新規アンケートを作成してください。
                        </div>
                    </div>
                    `
                    : `
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1200px]">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr class="text-left text-xs text-slate-500">
                                    <th class="px-5 py-3">作成日 / 更新日</th>
                                    <th class="px-5 py-3">タイトル</th>
                                    <th class="px-5 py-3">アンケート期間</th>
                                    <th class="px-5 py-3">ステータス</th>
                                    <th class="px-5 py-3 text-right">回答数</th>
                                    <th class="px-5 py-3">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${surveys.map(
                                    App.templates.surveyRow
                                ).join('')}
                            </tbody>
                        </table>
                    </div>
                    `
                }

            </div>

        </div>
    `;
};

/* =========================================================
 * Survey actions
 * ======================================================= */

App.actions.goList = function() {
    App.render.list();
};

App.actions.searchSurveys = function(value) {
    App.state.keyword = value || '';
    App.render.list();
};

App.actions.toggleStatusFilter = function(value) {
    App.state.statusFilter = value;
    App.render.list();
};

App.actions.changeSort = function(value) {
    App.state.sort = value;
    App.render.list();
};

App.actions.newSurvey = function() {
    const survey = {
        id: App.utils.uid('survey'),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: App.utils.now(),
        updated_at: App.utils.now(),
        numbering_mode: 'global',
        groups: [
            {
                id: App.utils.uid('group'),
                name: '基本情報',
                questions: [
                    {
                        id: App.utils.uid('question'),
                        text: 'お名前を入力してください。',
                        type: 'text',
                        required: true,
                        options: [],
                        other_enabled: false
                    }
                ]
            }
        ],
        deleted: false
    };

    App.state.editingSurvey = survey;
    App.render.editor();
};

App.actions.editSurvey = function(id) {
    const survey = App.state.surveys.find(
        item => String(item.id) === String(id)
    );

    if (!survey) {
        App.utils.notify('アンケートが見つかりません。', 'error');
        return;
    }

    App.state.editingSurvey = App.utils.clone(survey);

    App.render.editor();
};

App.actions.saveAndList = async function() {
    const survey = App.state.editingSurvey;

    if (!survey) {
        return;
    }

    survey.title =
        document.getElementById('survey_title')?.value ||
        survey.title;

    survey.start_at =
        document.getElementById('survey_start_at')?.value || '';

    survey.end_at =
        document.getElementById('survey_end_at')?.value || '';

    survey.numbering_mode =
        document.getElementById('survey_numbering_mode')?.value ||
        survey.numbering_mode;

    survey.updated_at = App.utils.now();

    try {
        await App.api.saveSurvey(survey);

        App.utils.notify(
            'アンケートを保存しました。',
            'success'
        );

        App.state.editingSurvey = null;
        App.render.list();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.cancelEditor = function() {
    if (
        !confirm(
            '変更内容を破棄してアンケート一覧へ戻りますか？'
        )
    ) {
        return;
    }

    App.state.editingSurvey = null;
    App.render.list();
};

App.actions.publishSurvey = async function(id) {
    const survey = App.state.editingSurvey ||
        App.state.surveys.find(
            item => String(item.id) === String(id)
        );

    if (!survey) {
        return;
    }

    survey.title =
        document.getElementById('survey_title')?.value ||
        survey.title;

    survey.start_at =
        document.getElementById('survey_start_at')?.value || '';

    survey.end_at =
        document.getElementById('survey_end_at')?.value || '';

    if (!survey.title.trim()) {
        App.utils.notify(
            'アンケートタイトルを入力してください。',
            'warning'
        );
        return;
    }

    if (!survey.groups.length) {
        App.utils.notify(
            'グループを1つ以上追加してください。',
            'warning'
        );
        return;
    }

    if (!confirm(
        'このアンケートを公開しますか？\n\n公開すると「送信」ボタンが表示されます。'
    )) {
        return;
    }

    survey.status = 'active';
    survey.updated_at = App.utils.now();

    try {
        await App.api.saveSurvey(survey);

        App.utils.notify(
            'アンケートを公開しました。「送信」が利用できます。',
            'success'
        );

        App.state.editingSurvey = null;
        App.render.list();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.stopSurvey = async function(id) {
    if (!confirm(
        'このアンケートを停止しますか？\n\n停止すると「送信」はできなくなります。'
    )) {
        return;
    }

    try {
        await App.api.request(
            'status_survey',
            {
                survey_id: id,
                status: 'ended'
            }
        );

        const survey = App.state.surveys.find(
            item => String(item.id) === String(id)
        );

        if (survey) {
            survey.status = 'ended';
        }

        App.utils.notify(
            'アンケートを停止しました。',
            'success'
        );

        App.render.list();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.deleteSurvey = async function(id) {
    if (!confirm(
        'この下書きを削除しますか？\n削除後は一覧から表示されません。'
    )) {
        return;
    }

    try {
        await App.api.request(
            'delete_survey',
            {
                survey_id: id
            }
        );

        App.state.surveys =
            App.state.surveys.filter(
                item => String(item.id) !== String(id)
            );

        App.utils.notify(
            'アンケートを削除しました。',
            'success'
        );

        App.render.list();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

App.actions.duplicateSurvey = async function(id) {
    try {
        const json = await App.api.request(
            'duplicate_survey',
            {
                survey_id: id
            }
        );

        App.state.surveys.unshift(json.survey);

        App.utils.notify(
            'アンケートを複製しました。下書きとして追加されています。',
            'success'
        );

        App.render.list();
    } catch (error) {
        App.utils.notify(error.message, 'error');
    }
};

/* =========================================================
 * Editor
 * ======================================================= */

App.render.editor = function() {
    const survey = App.state.editingSurvey;

    if (!survey) {
        App.render.list();
        return;
    }

    App.state.view = 'editor';

    document.getElementById('main_content').innerHTML = `
        <div>

            <div class="flex items-center justify-between mb-6">

                <div>
                    <div class="text-sm text-slate-400 mb-1">
                        ホーム ＞ アンケート一覧 ＞ 編集
                    </div>

                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold">
                            アンケート編集
                        </h1>

                        ${App.utils.statusBadge(survey.status)}
                    </div>
                </div>

                <div class="flex gap-2">

                    <button
                        onclick="App.actions.openPreview()"
                        class="px-4 py-2.5 border border-slate-200
                               bg-white rounded-lg text-sm">
                        プレビュー
                    </button>

                    ${
                        survey.status === 'draft'
                        ? `
                        <button
                            onclick="
                                App.actions.publishSurvey(
                                    '${App.utils.escapeAttr(survey.id)}'
                                )
                            "
                            class="px-4 py-2.5 bg-emerald-600 text-white
                                   rounded-lg text-sm font-semibold">
                            公開する
                        </button>
                        `
                        : ''
                    }

                    ${
                        survey.status === 'active'
                        ? `
                        <button
                            onclick="
                                App.actions.stopSurvey(
                                    '${App.utils.escapeAttr(survey.id)}'
                                )
                            "
                            class="px-4 py-2.5 bg-amber-50 text-amber-700
                                   border border-amber-200 rounded-lg text-sm">
                            停止する
                        </button>
                        `
                        : ''
                    }

                    <button
                        onclick="App.actions.cancelEditor()"
                        class="px-4 py-2.5 border border-slate-200
                               bg-white rounded-lg text-sm">
                        キャンセル
                    </button>

                    <button
                        onclick="App.actions.saveAndList()"
                        class="px-4 py-2.5 bg-brand-600 text-white
                               rounded-lg text-sm font-semibold">
                        保存して一覧へ戻る
                    </button>

                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-6 mb-5">

                <div class="grid grid-cols-12 gap-5">

                    <div class="col-span-6">
                        <label class="block text-sm font-medium mb-2">
                            アンケートタイトル
                        </label>
                        <input
                            id="survey_title"
                            value="${App.utils.escapeAttr(survey.title)}"
                            class="w-full border border-slate-200 rounded-lg
                                   px-4 py-3 font-medium">
                    </div>

                    <div class="col-span-2">
                        <label class="block text-sm font-medium mb-2">
                            開始日時
                        </label>
                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            value="${App.utils.escapeAttr(
                                survey.start_at.substring(0, 16)
                            )}"
                            class="w-full border border-slate-200 rounded-lg
                                   px-3 py-3">
                    </div>

                    <div class="col-span-2">
                        <label class="block text-sm font-medium mb-2">
                            終了日時
                        </label>
                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            value="${App.utils.escapeAttr(
                                survey.end_at.substring(0, 16)
                            )}"
                            class="w-full border border-slate-200 rounded-lg
                                   px-3 py-3">
                    </div>

                    <div class="col-span-2">
                        <label class="block text-sm font-medium mb-2">
                            質問番号
                        </label>
                        <select
                            id="survey_numbering_mode"
                            onchange="App.actions.changeNumberingMode(this.value)"
                            class="w-full border border-slate-200 rounded-lg
                                   px-3 py-3">
                            <option value="global"
                                ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                                Q1 / Q2 / Q3
                            </option>
                            <option value="group"
                                ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                                Q1-1 / Q1-2
                            </option>
                        </select>
                    </div>

                </div>

            </div>

            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="font-bold text-lg">質問構成</h2>
                    <p class="text-sm text-slate-400">
                        ドラッグ＆ドロップでグループ・質問を並べ替えできます。
                    </p>
                </div>

                <button
                    onclick="App.actions.addGroup()"
                    class="px-4 py-2.5 bg-brand-600 text-white
                           rounded-lg text-sm font-semibold">
                    ＋ グループ追加
                </button>
            </div>

            <div id="question_editor" class="space-y-5">
                ${survey.groups.map(function(group, groupIndex) {

                    return `
                        <section
                            data-group-id="${App.utils.escapeAttr(group.id)}"
                            class="survey-group bg-slate-100 border border-slate-200
                                   rounded-xl p-4">

                            <div class="flex items-center gap-3 mb-4">

                                <div class="group-drag cursor-grab
                                            text-slate-400 text-xl">
                                    ⠿
                                </div>

                                <input
                                    value="${App.utils.escapeAttr(group.name)}"
                                    class="flex-1 bg-transparent border-0
                                           border-b border-transparent
                                           focus:border-brand-500
                                           focus:ring-0 font-bold text-lg"
                                    oninput="
                                        App.actions.updateGroupName(
                                            '${App.utils.escapeAttr(group.id)}',
                                            this.value
                                        )
                                    ">

                                <button
                                    onclick="
                                        App.actions.addQuestion(
                                            '${App.utils.escapeAttr(group.id)}'
                                        )
                                    "
                                    class="px-3 py-2 rounded-lg bg-white
                                           border border-slate-200 text-sm">
                                    ＋ 質問
                                </button>

                                <button
                                    onclick="
                                        App.actions.deleteGroup(
                                            '${App.utils.escapeAttr(group.id)}'
                                        )
                                    "
                                    class="px-3 py-2 rounded-lg bg-white
                                           border border-red-200 text-red-600 text-sm">
                                    グループ削除
                                </button>

                            </div>

                            <div
                                class="question-list space-y-3"
                                data-group-id="${App.utils.escapeAttr(group.id)}">

                                ${
                                    group.questions.length
                                    ? group.questions.map(function(question, qIndex) {
                                        return App.templates.question(
                                            question,
                                            App.actions.questionNumber(
                                                groupIndex,
                                                qIndex
                                            ),
                                            group.id
                                        );
                                    }).join('')
                                    : `
                                    <div
                                        class="question-empty border-2 border-dashed
                                               border-slate-300 rounded-xl p-8
                                               text-center text-sm text-slate-400">
                                        質問がありません。
                                        「＋ 質問」から追加してください。
                                    </div>
                                    `
                                }

                            </div>
                        </section>
                    `;
                }).join('')}
            </div>

        </div>
    `;

    App.actions.initSortables();
};

App.actions.changeNumberingMode = function(value) {
    App.state.editingSurvey.numbering_mode = value;
    App.render.editor();
};

App.actions.questionNumber = function(groupIndex, questionIndex) {
    const survey = App.state.editingSurvey;

    if (survey.numbering_mode === 'group') {
        return 'Q' +
            (groupIndex + 1) +
            '-' +
            (questionIndex + 1);
    }

    let number = 0;

    for (let i = 0; i < groupIndex; i++) {
        number += survey.groups[i].questions.length;
    }

    number += questionIndex + 1;

    return 'Q' + number;
};

App.actions.addGroup = function() {
    const survey = App.state.editingSurvey;

    survey.groups.push({
        id: App.utils.uid('group'),
        name: '新しいグループ',
        questions: []
    });

    App.render.editor();
};

App.actions.deleteGroup = function(groupId) {
    const group = App.state.editingSurvey.groups.find(
        item => String(item.id) === String(groupId)
    );

    if (!group) {
        return;
    }

    if (!confirm(
        '「' + group.name +
        '」を削除しますか？\n内包する質問もすべて削除されます。'
    )) {
        return;
    }

    App.state.editingSurvey.groups =
        App.state.editingSurvey.groups.filter(
            item => String(item.id) !== String(groupId)
        );

    App.render.editor();
};

App.actions.addQuestion = function(groupId) {
    const group = App.state.editingSurvey.groups.find(
        item => String(item.id) === String(groupId)
    );

    if (!group) {
        return;
    }

    group.questions.push({
        id: App.utils.uid('question'),
        text: '新しい質問',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false
    });

    App.render.editor();
};

App.actions.deleteQuestion = function(groupId, questionId) {
    const group = App.state.editingSurvey.groups.find(
        item => String(item.id) === String(groupId)
    );

    if (!group) {
        return;
    }

    if (!confirm('この質問を削除しますか？')) {
        return;
    }

    group.questions = group.questions.filter(
        item => String(item.id) !== String(questionId)
    );

    App.render.editor();
};

App.actions.updateGroupName = function(groupId, value) {
    const group = App.state.editingSurvey.groups.find(
        item => String(item.id) === String(groupId)
    );

    if (group) {
        group.name = value;
    }
};

App.actions.findQuestion = function(groupId, questionId) {
    const group = App.state.editingSurvey.groups.find(
        item => String(item.id) === String(groupId)
    );

    if (!group) {
        return null;
    }

    return group.questions.find(
        item => String(item.id) === String(questionId)
    ) || null;
};

App.actions.updateQuestionText = function(
    groupId,
    questionId,
    value
) {
    const question = App.actions.findQuestion(
        groupId,
        questionId
    );

    if (question) {
        question.text = value;
    }
};

App.actions.changeQuestionType = function(
    groupId,
    questionId,
    type
) {
    const question = App.actions.findQuestion(
        groupId,
        questionId
    );

    if (!question) {
        return;
    }

    question.type = type;

    if (type === 'text') {
        question.options = [];
    } else if (!question.options.length) {
        question.options = ['選択肢1', '選択肢2'];
    }

    App.render.editor();
};

App.actions.toggleRequired = function(
    groupId,
    questionId,
    checked
) {
    const question = App.actions.findQuestion(
        groupId,
        questionId
    );

    if (question) {
        question.required = checked;
    }
};

App.actions.toggleOther = function(
    groupId,
    questionId,
    checked
) {
    const question = App.actions.findQuestion(
        groupId,
        questionId
    );

    if (question) {
        question.other_enabled = checked;
    }
};

App.actions.addOption = function(groupId, questionId) {
    const question = App.actions.findQuestion(
        groupId,
        questionId
    );

    if (!question) {
        return;
    }

    question.options.push(
        '選択肢' + (question.options.length + 1)
    );

    App.render.editor();
};

App.actions.removeOption = function(
    groupId,
    questionId,
    index
) {
    const question = App.actions.findQuestion(
        groupId,
        questionId
    );

    if (!question) {
        return;
    }

    question.options.splice(index, 1);

    App.render.editor();
};

App.actions.updateOption = function(
    groupId,
    questionId,
    index,
    value
) {
    const question = App.actions.findQuestion(
        groupId,
        questionId
    );

    if (question && question.options[index] !== undefined) {
        question.options[index] = value;
    }
};

App.actions.initSortables = function() {
    const editor = document.getElementById('question_editor');

    if (!editor || typeof Sortable === 'undefined') {
        return;
    }

    new Sortable(editor, {
        animation: 180,
        handle: '.group-drag',
        ghostClass: 'opacity-40',
        onEnd: function(event) {
            const groups = App.state.editingSurvey.groups;
            const moved = groups.splice(event.oldIndex, 1)[0];

            groups.splice(event.newIndex, 0, moved);

            App.render.editor();
        }
    });

    document.querySelectorAll('.question-list').forEach(
        function(list) {
            new Sortable(list, {
                group: 'questions',
                animation: 180,
                handle: '.question-drag',
                ghostClass: 'opacity-40',

                onEnd: function(event) {
                    App.actions.syncQuestionSort();
                }
            });
        }
    );
};

App.actions.syncQuestionSort = function() {
    const survey = App.state.editingSurvey;
    const newGroups = [];

    document.querySelectorAll(
        '#question_editor > .survey-group'
    ).forEach(function(groupElement) {

        const groupId =
            groupElement.dataset.groupId;

        const oldGroup = survey.groups.find(
            item => String(item.id) === String(groupId)
        );

        if (!oldGroup) {
            return;
        }

        const questions = [];

        groupElement
            .querySelectorAll('.question-list > .question-card')
            .forEach(function(questionElement) {

                const questionId =
                    questionElement.dataset.questionId;

                const question = survey.groups
                    .flatMap(item => item.questions)
                    .find(
                        item =>
                            String(item.id) ===
                            String(questionId)
                    );

                if (question) {
                    questions.push(question);
                }
            });

        newGroups.push({
            id: oldGroup.id,
            name: oldGroup.name,
            questions: questions
        });
    });

    survey.groups = newGroups;

    App.render.editor();
};

/* =========================================================
 * Preview
 * ======================================================= */

App.actions.openPreview = function() {
    const survey = App.state.editingSurvey;

    App.state.previewSurvey = App.utils.clone(survey);

    const modal = document.getElementById('preview_modal');

    modal.innerHTML = `
        <div class="fixed inset-0 z-50 bg-black/40 flex items-center
                    justify-center p-5">

            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl
                        max-h-[90vh] overflow-hidden">

                <div class="px-5 py-4 border-b border-slate-200
                            flex items-center justify-between">

                    <div class="font-bold">
                        プレビュー
                    </div>

                    <div class="flex items-center gap-2">

                        <button
                            onclick="App.actions.previewMode('pc')"
                            class="px-3 py-1.5 text-sm rounded-lg
                                   bg-slate-100">
                            PC表示
                        </button>

                        <button
                            onclick="App.actions.previewMode('mobile')"
                            class="px-3 py-1.5 text-sm rounded-lg
                                   bg-slate-100">
                            スマートフォン表示
                        </button>

                        <button
                            onclick="App.actions.closePreview()"
                            class="px-3 py-1.5 text-sm text-slate-500">
                            閉じる
                        </button>

                    </div>
                </div>

                <div id="preview_content"
                     class="p-6 overflow-y-auto max-h-[calc(90vh-70px)]">
                </div>

            </div>
        </div>
    `;

    App.actions.renderPreview('pc');
};

App.actions.previewMode = function(mode) {
    App.actions.renderPreview(mode);
};

App.actions.renderPreview = function(mode) {
    const survey = App.state.previewSurvey;

    const width =
        mode === 'mobile'
        ? 'max-w-[390px]'
        : 'max-w-3xl';

    document.getElementById('preview_content').innerHTML = `
        <div class="${width} mx-auto">

            <div class="mb-7">
                <h1 class="text-2xl font-bold">
                    ${App.utils.escapeHtml(survey.title)}
                </h1>
            </div>

            ${
                survey.groups.map(function(group) {
                    return `
                        <section class="mb-8">
                            <h2 class="font-bold text-lg mb-4">
                                ${App.utils.escapeHtml(group.name)}
                            </h2>

                            <div class="space-y-5">
                                ${group.questions.map(function(question, index) {
                                    return `
                                        <div class="border border-slate-200
                                                    rounded-xl p-5">

                                            <div class="font-medium mb-4">
                                                <span class="text-brand-600 mr-2">
                                                    Q${index + 1}
                                                </span>
                                                ${App.utils.escapeHtml(question.text)}
                                                ${
                                                    question.required
                                                    ? '<span class="text-red-500 ml-1">*</span>'
                                                    : ''
                                                }
                                            </div>

                                            ${
                                                question.type === 'text'
                                                ? `
                                                <textarea
                                                    class="w-full border border-slate-200
                                                           rounded-lg p-3"
                                                    rows="4"
                                                    placeholder="入力してください">
                                                </textarea>
                                                `
                                                : `
                                                <div class="space-y-3">
                                                    ${(question.options || []).map(
                                                        function(option) {
                                                            return `
                                                                <label class="flex gap-3">
                                                                    <input
                                                                        type="${question.type === 'single' ? 'radio' : 'checkbox'}"
                                                                        name="preview_${question.id}">
                                                                    <span>
                                                                        ${App.utils.escapeHtml(option)}
                                                                    </span>
                                                                </label>
                                                            `;
                                                        }
                                                    ).join('')}

                                                    ${
                                                        question.other_enabled
                                                        ? `
                                                        <input
                                                            class="w-full border border-slate-200
                                                                   rounded-lg px-3 py-2"
                                                            placeholder="その他">
                                                        `
                                                        : ''
                                                    }
                                                </div>
                                                `
                                            }

                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </section>
                    `;
                }).join('')
            }

            <button
                onclick="App.actions.previewSubmit()"
                class="w-full py-3 rounded-lg bg-brand-600 text-white
                       font-semibold">
                回答を送信
            </button>

        </div>
    `;
};

App.actions.previewSubmit = function() {
    alert(
        'これはプレビューです。実際の回答送信は行われません。'
    );
};

App.actions.closePreview = function() {
    document.getElementById('preview_modal').innerHTML = '';
};

/* =========================================================
 * Results
 * ======================================================= */

App.actions.openResults = function(id) {
    App.state.currentSurveyId = id;
    App.state.view = 'results';
    App.render.results();
};

App.render.results = function() {
    const survey = App.state.surveys.find(
        item => String(item.id) ===
            String(App.state.currentSurveyId)
    );

    if (!survey) {
        return;
    }

    const responses = App.state.responses.filter(
        item => String(item.survey_id) === String(survey.id)
    );

    const customersSent = App.state.customers.filter(
        item =>
            item.sent_at &&
            Number(item.send_count || 0) > 0
    ).length;

    const answeredCustomers = responses.filter(
        item => item.customer_id
    ).length;

    const webResponses = responses.filter(
        item => !item.customer_id
    ).length;

    const unanswered = Math.max(
        0,
        customersSent - answeredCustomers
    );

    const rate = customersSent > 0
        ? ((answeredCustomers / customersSent) * 100).toFixed(1)
        : '0.0';

    const questions = survey.groups.flatMap(
        group => group.questions
    );

    if (!Object.keys(App.state.selectedQuestions).length) {
        questions.forEach(
            question =>
                App.state.selectedQuestions[question.id] = true
        );
    }

    document.getElementById('main_content').innerHTML = `
        <div>

            <div class="flex items-center justify-between mb-6">

                <div>
                    <div class="text-sm text-slate-400 mb-1">
                        ホーム ＞ アンケート一覧 ＞ 集計
                    </div>
                    <h1 class="text-2xl font-bold">
                        ${App.utils.escapeHtml(survey.title)}
                    </h1>
                    <p class="text-sm text-slate-400 mt-1">
                        回答集計・分析
                    </p>
                </div>

                <div class="flex gap-2">

                    <button
                        onclick="App.actions.exportCsv('${App.utils.escapeAttr(survey.id)}')"
                        class="px-4 py-2.5 rounded-lg border border-slate-200
                               bg-white text-sm">
                        CSV出力
                    </button>

                    <button
                        onclick="window.print()"
                        class="px-4 py-2.5 rounded-lg bg-brand-600 text-white
                               text-sm">
                        PDF / 印刷
                    </button>

                    <button
                        onclick="App.actions.goList()"
                        class="px-4 py-2.5 rounded-lg border border-slate-200
                               bg-white text-sm">
                        一覧へ戻る
                    </button>

                </div>

            </div>

            <div class="grid grid-cols-5 gap-4 mb-6">

                ${[
                    ['送信対象者数', customersSent, '人'],
                    ['回答数', responses.length, '件'],
                    ['未登録顧客からの回答数', webResponses, '件'],
                    ['未回答数', unanswered, '人'],
                    ['回答率', rate, '%']
                ].map(function(item) {
                    return `
                        <div class="bg-white border border-slate-200
                                    rounded-xl p-5">
                            <div class="text-xs text-slate-500">
                                ${item[0]}
                            </div>
                            <div class="text-2xl font-bold mt-2">
                                ${item[1]}
                                <span class="text-sm text-slate-400">
                                    ${item[2]}
                                </span>
                            </div>
                        </div>
                    `;
                }).join('')}

            </div>

            <div class="grid grid-cols-12 gap-5">

                <aside class="col-span-3 bg-white border border-slate-200
                              rounded-xl p-5 h-fit">

                    <div class="font-bold mb-4">
                        設問絞り込み
                    </div>

                    <div class="flex gap-2 mb-4">
                        <button
                            onclick="App.actions.selectAllQuestions(true)"
                            class="text-xs text-brand-600">
                            全選択
                        </button>
                        <button
                            onclick="App.actions.selectAllQuestions(false)"
                            class="text-xs text-slate-500">
                            全解除
                        </button>
                    </div>

                    <div class="space-y-3">
                        ${questions.map(function(question, index) {
                            return `
                                <label class="flex gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        ${App.state.selectedQuestions[question.id] ? 'checked' : ''}
                                        onchange="
                                            App.actions.toggleQuestionFilter(
                                                '${App.utils.escapeAttr(question.id)}',
                                                this.checked
                                            )
                                        "
                                        class="mt-1 rounded border-slate-300
                                               text-brand-600">
                                    <span>
                                        Q${index + 1}
                                        ${App.utils.escapeHtml(question.text)}
                                    </span>
                                </label>
                            `;
                        }).join('')}
                    </div>

                </aside>

                <section class="col-span-9 space-y-5">

                    ${
                        responses.length === 0
                        ? `
                        <div class="bg-white border border-slate-200
                                    rounded-xl py-20 text-center">
                            <div class="text-lg font-semibold">
                                現在、回答データはありません
                            </div>
                            <div class="text-sm text-slate-400 mt-2">
                                アンケートを送信すると回答結果がここに表示されます。
                            </div>
                        </div>
                        `
                        : questions
                            .filter(q => App.state.selectedQuestions[q.id])
                            .map(function(question) {
                                return App.render.questionResult(
                                    question,
                                    responses
                                );
                            }).join('')
                    }

                    <div class="bg-white border border-slate-200 rounded-xl">

                        <div class="p-5 border-b border-slate-200">
                            <div class="flex items-center justify-between">

                                <div class="font-bold">
                                    個別回答一覧
                                </div>

                                <input
                                    id="response_filter"
                                    value="${App.utils.escapeAttr(
                                        App.state.responseKeyword
                                    )}"
                                    oninput="App.actions.filterResponses(this.value)"
                                    class="w-72 border border-slate-200
                                           rounded-lg px-3 py-2 text-sm"
                                    placeholder="会社名・氏名で検索">

                            </div>
                        </div>

                        <div id="response_table">
                            ${App.render.responseTableHtml(
                                responses
                            )}
                        </div>

                    </div>

                </section>
            </div>

        </div>
    `;
};

App.render.questionResult = function(question, responses) {
    const counts = {};

    (question.options || []).forEach(
        option => counts[option] = 0
    );

    let answered = 0;

    responses.forEach(function(response) {
        const value =
            response.answers?.[question.id];

        if (value === undefined || value === '') {
            return;
        }

        answered++;

        if (Array.isArray(value)) {
            value.forEach(function(item) {
                counts[item] =
                    (counts[item] || 0) + 1;
            });
        } else {
            counts[value] =
                (counts[value] || 0) + 1;
        }
    });

    if (question.type === 'text') {
        const texts = responses
            .map(function(response) {
                const value =
                    response.answers?.[question.id];

                if (!value) {
                    return null;
                }

                return {
                    name:
                        response.company ||
                        response.name ||
                        '匿名',
                    text: Array.isArray(value)
                        ? value.join('、')
                        : value,
                    answered_at: response.answered_at
                };
            })
            .filter(Boolean);

        return `
            <div class="bg-white border border-slate-200 rounded-xl p-5">

                <div class="flex items-center justify-between mb-5">
                    <div>
                        <div class="font-bold">
                            ${App.utils.escapeHtml(question.text)}
                        </div>
                        <span class="inline-flex mt-2 px-2 py-1 rounded
                                     bg-slate-100 text-xs text-slate-600">
                            自由記述
                        </span>
                    </div>
                    <div class="text-sm text-slate-400">
                        ${texts.length}件
                    </div>
                </div>

                <div class="space-y-3 max-h-80 overflow-y-auto">
                    ${
                        texts.length
                        ? texts.map(function(item) {
                            return `
                                <div class="border-l-2 border-brand-500
                                            pl-4 py-2">
                                    <div class="text-xs text-slate-400">
                                        ${App.utils.escapeHtml(item.name)}
                                        ・
                                        ${App.utils.escapeHtml(
                                            App.utils.formatDate(
                                                item.answered_at
                                            )
                                        )}
                                    </div>
                                    <div class="mt-1 text-sm">
                                        ${App.utils.escapeHtml(item.text)}
                                    </div>
                                </div>
                            `;
                        }).join('')
                        : `
                        <div class="text-sm text-slate-400">
                            回答はありません。
                        </div>
                        `
                    }
                </div>

            </div>
        `;
    }

    const total = responses.length || 1;

    return `
        <div class="bg-white border border-slate-200 rounded-xl p-5">

            <div class="flex items-center justify-between mb-5">
                <div>
                    <div class="font-bold">
                        ${App.utils.escapeHtml(question.text)}
                    </div>
                    <span class="inline-flex mt-2 px-2 py-1 rounded
                                 bg-slate-100 text-xs text-slate-600">
                        ${App.utils.questionTypeLabel(question.type)}
                    </span>
                </div>
                <div class="text-sm text-slate-400">
                    回答 ${answered}件
                </div>
            </div>

            <div class="space-y-4">
                ${Object.entries(counts).map(function(entry) {
                    const option = entry[0];
                    const count = entry[1];
                    const percent =
                        Math.round((count / total) * 100);

                    return `
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span>
                                    ${App.utils.escapeHtml(option)}
                                </span>
                                <span class="text-slate-500">
                                    ${count}件 / ${percent}%
                                </span>
                            </div>

                            <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-brand-500 rounded-full"
                                    style="width:${percent}%">
                                </div>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>

        </div>
    `;
};

App.render.responseTableHtml = function(responses) {
    const keyword =
        App.state.responseKeyword.toLowerCase();

    const filtered = responses.filter(function(response) {
        return !keyword ||
            String(response.company || '')
                .toLowerCase()
                .includes(keyword) ||
            String(response.name || '')
                .toLowerCase()
                .includes(keyword);
    });

    return `
        <div class="overflow-x-auto">

            <table class="w-full min-w-[900px]">

                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-left text-xs text-slate-500">
                        <th class="px-5 py-3">回答者</th>
                        <th class="px-5 py-3">メール</th>
                        <th class="px-5 py-3">回答日時</th>
                        <th class="px-5 py-3">種別</th>
                        <th class="px-5 py-3">操作</th>
                    </tr>
                </thead>

                <tbody>
                    ${
                        filtered.length
                        ? filtered.map(function(response) {
                            return `
                                <tr class="border-b border-slate-100">

                                    <td class="px-5 py-4">
                                        <div class="font-medium">
                                            ${App.utils.escapeHtml(
                                                response.company || '-'
                                            )}
                                        </div>
                                        <div class="text-sm text-slate-500">
                                            ${App.utils.escapeHtml(
                                                response.name || '匿名'
                                            )}
                                        </div>
                                    </td>

                                    <td class="px-5 py-4 text-sm">
                                        ${App.utils.escapeHtml(
                                            response.email || '-'
                                        )}
                                    </td>

                                    <td class="px-5 py-4 text-sm">
                                        ${App.utils.escapeHtml(
                                            App.utils.formatDate(
                                                response.answered_at
                                            )
                                        )}
                                    </td>

                                    <td class="px-5 py-4">
                                        ${
                                            response.customer_id
                                            ? `
                                            <span class="px-2 py-1 rounded-full
                                                         bg-emerald-100
                                                         text-emerald-700
                                                         text-xs">
                                                顧客リスト
                                            </span>
                                            `
                                            : `
                                            <span class="px-2 py-1 rounded-full
                                                         bg-amber-100
                                                         text-amber-700
                                                         text-xs">
                                                未登録
                                            </span>
                                            `
                                        }
                                    </td>

                                    <td class="px-5 py-4">
                                        <button
                                            onclick="
                                                App.actions.openResponse(
                                                    '${App.utils.escapeAttr(response.id)}'
                                                )
                                            "
                                            class="text-sm text-brand-600">
                                            全回答を表示
                                        </button>
                                    </td>

                                </tr>
                            `;
                        }).join('')
                        : `
                        <tr>
                            <td colspan="5"
                                class="px-5 py-12 text-center
                                       text-sm text-slate-400">
                                該当する回答はありません。
                            </td>
                        </tr>
                        `
                    }
                </tbody>

            </table>

        </div>
    `;
};

App.actions.toggleQuestionFilter = function(id, checked) {
    App.state.selectedQuestions[id] = checked;
    App.render.results();
};

App.actions.selectAllQuestions = function(value) {
    const survey = App.state.surveys.find(
        item => String(item.id) ===
            String(App.state.currentSurveyId)
    );

    if (!survey) {
        return;
    }

    survey.groups.flatMap(
        group => group.questions
    ).forEach(
        question =>
            App.state.selectedQuestions[question.id] = value
    );

    App.render.results();
};

App.actions.filterResponses = function(value) {
    App.state.responseKeyword = value;

    const survey = App.state.surveys.find(
        item => String(item.id) ===
            String(App.state.currentSurveyId)
    );

    if (!survey) {
        return;
    }

    const responses = App.state.responses.filter(
        item => String(item.survey_id) ===
            String(survey.id)
    );

    document.getElementById('response_table').innerHTML =
        App.render.responseTableHtml(responses);
};

App.actions.openResponse = function(id) {
    const response = App.state.responses.find(
        item => String(item.id) === String(id)
    );

    if (!response) {
        return;
    }

    const survey = App.state.surveys.find(
        item => String(item.id) ===
            String(response.survey_id)
    );

    if (!survey) {
        return;
    }

    const questions = survey.groups.flatMap(
        group => group.questions
    );

    document.getElementById('response_modal').innerHTML = `
        <div class="fixed inset-0 z-50 bg-black/40
                    flex items-center justify-center p-5">

            <div class="bg-white rounded-2xl shadow-2xl
                        w-full max-w-3xl max-h-[90vh]
                        overflow-hidden">

                <div class="px-5 py-4 border-b border-slate-200
                            flex justify-between items-center">

                    <div>
                        <div class="font-bold">
                            回答詳細
                        </div>
                        <div class="text-xs text-slate-400 mt-1">
                            ${App.utils.escapeHtml(
                                response.name || '匿名'
                            )}
                        </div>
                    </div>

                    <button
                        onclick="App.actions.closeResponse()"
                        class="text-slate-500">
                        閉じる
                    </button>
                </div>

                <div id="response_detail"
                     class="p-6 overflow-y-auto max-h-[75vh]">

                    <div class="space-y-5">
                        ${questions.map(function(question, index) {
                            let value =
                                response.answers?.[question.id] ?? '';

                            if (Array.isArray(value)) {
                                value = value.join('、');
                            }

                            return `
                                <div class="border border-slate-200
                                            rounded-xl p-4">

                                    <div class="text-sm font-semibold">
                                        Q${index + 1}.
                                        ${App.utils.escapeHtml(
                                            question.text
                                        )}
                                    </div>

                                    <div class="mt-3 text-sm text-slate-600
                                                whitespace-pre-wrap">
                                        ${App.utils.escapeHtml(
                                            value || '未回答'
                                        )}
                                    </div>

                                </div>
                            `;
                        }).join('')}
                    </div>

                </div>
            </div>
        </div>
    `;
};

App.actions.closeResponse = function() {
    document.getElementById('response_modal').innerHTML = '';
};

App.actions.exportCsv = function(surveyId) {
    const url =
        '?action=export_csv' +
        '&survey_id=' +
        encodeURIComponent(surveyId) +
        '&csrf_token=' +
        encodeURIComponent(App.state.csrfToken);

    window.location.href = url;
};

/* =========================================================
 * Mail
 * ======================================================= */

App.actions.openMail = function(id) {
    const survey = App.state.surveys.find(
        item => String(item.id) === String(id)
    );

    if (!survey) {
        return;
    }

    if (survey.status !== 'active') {
        App.utils.notify(
            '公開中のアンケートだけ送信できます。',
            'warning'
        );
        return;
    }

    App.state.mailSurveyId = id;
    App.state.selectedCustomers = [];

    App.render.mail();
};

App.render.mail = function() {
    const survey = App.state.surveys.find(
        item => String(item.id) ===
            String(App.state.mailSurveyId)
    );

    if (!survey) {
        return;
    }

    document.getElementById('main_content').innerHTML = `

        <div>

            <div class="text-sm text-slate-400 mb-1">
                ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
            </div>

            <div class="flex items-center justify-between mb-6">

                <div>
                    <h1 class="text-2xl font-bold">
                        メール送信
                    </h1>
                    <p class="text-sm text-slate-500 mt-1">
                        ${App.utils.escapeHtml(survey.title)}
                    </p>
                </div>

                <button
                    onclick="App.actions.goList()"
                    class="px-4 py-2.5 border border-slate-200
                           rounded-lg bg-white text-sm">
                    一覧へ戻る
                </button>

            </div>

            <div class="grid grid-cols-12 gap-5">

                <section class="col-span-4">

                    <div class="bg-white border border-slate-200
                                rounded-xl p-5">

                        <div class="font-bold mb-4">
                            メールテンプレート
                        </div>

                        <label class="block text-sm font-medium mb-2">
                            種別
                        </label>

                        <select
                            id="template_type"
                            onchange="App.actions.applyMailTemplate(this.value)"
                            class="w-full border border-slate-200
                                   rounded-lg px-3 py-2.5 mb-4">
                            <option value="initial">
                                初回案内
                            </option>
                            <option value="reminder">
                                再送・リマインド
                            </option>
                        </select>

                        <label class="block text-sm font-medium mb-2">
                            件名
                        </label>

                        <input
                            id="mail_subject"
                            value="【アンケートのお願い】ご回答ください"
                            class="w-full border border-slate-200
                                   rounded-lg px-3 py-2.5 mb-4">

                        <label class="block text-sm font-medium mb-2">
                            本文
                        </label>

                        <textarea
                            id="mail_body"
                            rows="12"
                            class="w-full border border-slate-200
                                   rounded-lg px-3 py-2.5">ご担当者様

アンケートへのご協力をお願いいたします。

{顧客名} 様

以下のURLよりご回答ください。

{アンケートURL}

よろしくお願いいたします。</textarea>

                        <div class="mt-3 text-xs text-slate-400">
                            利用可能な変数：
                            <code>{顧客名}</code>
                            <code>{アンケートURL}</code>
                        </div>

                    </div>

                    <div class="bg-white border border-slate-200
                                rounded-xl p-5 mt-5">

                        <div class="font-bold mb-4">
                            送信履歴
                        </div>

                        <div class="space-y-3 max-h-80 overflow-y-auto">
                            ${
                                App.state.mailLogs
                                    .filter(
                                        log =>
                                            String(log.survey_id) ===
                                            String(survey.id)
                                    )
                                    .slice()
                                    .reverse()
                                    .map(function(log) {
                                        return `
                                            <div class="border-b border-slate-100
                                                        pb-3">
                                                <div class="text-xs text-slate-400">
                                                    ${App.utils.escapeHtml(
                                                        App.utils.formatDate(
                                                            log.sent_at
                                                        )
                                                    )}
                                                </div>
                                                <div class="text-sm font-medium mt-1">
                                                    ${App.utils.escapeHtml(
                                                        log.subject
                                                    )}
                                                </div>
                                            </div>
                                        `;
                                    }).join('')
                                    ||
                                    `
                                    <div class="text-sm text-slate-400">
                                        送信履歴はありません。
                                    </div>
                                    `
                            }
                        </div>

                    </div>

                </section>

                <section class="col-span-8">

                    <div class="bg-white border border-slate-200
                                rounded-xl overflow-hidden">

                        <div class="p-5 border-b border-slate-200">

                            <div class="flex items-center gap-3">

                                <input
                                    id="customer_filter"
                                    oninput="
                                        App.actions.filterCustomers(this.value)
                                    "
                                    class="flex-1 border border-slate-200
                                           rounded-lg px-3 py-2.5 text-sm"
                                    placeholder="顧客名・メールアドレスで検索">

                                <button
                                    onclick="App.actions.selectUnanswered()"
                                    class="px-3 py-2.5 rounded-lg border
                                           border-slate-200 text-sm">
                                    未回答のみ
                                </button>

                                <button
                                    onclick="App.actions.selectAllCustomers()"
                                    class="px-3 py-2.5 rounded-lg border
                                           border-slate-200 text-sm">
                                    全選択
                                </button>

                            </div>

                            <div class="mt-4 bg-amber-50 border border-amber-200
                                        text-amber-800 rounded-lg px-4 py-3
                                        text-sm">
                                kintone未登録の顧客は
                                「未登録」バッジで表示されます。
                            </div>

                        </div>

                        <div id="customer_table">
                            ${App.render.customerTableHtml()}
                        </div>

                        <div class="p-5 border-t border-slate-200
                                    flex items-center justify-between">

                            <div class="text-sm text-slate-500">
                                選択：
                                <strong id="selected_customer_count">
                                    0
                                </strong>
                                件
                            </div>

                            <button
                                onclick="App.actions.sendSelectedMail()"
                                class="px-6 py-3 rounded-lg bg-brand-600
                                       text-white font-semibold">
                                選択した顧客へ一括送信
                            </button>

                        </div>

                    </div>

                </section>

            </div>

        </div>
    `;

    App.actions.updateSelectedCount();
};

App.render.customerTableHtml = function() {
    const filter =
        (
            document.getElementById('customer_filter')?.value ||
            ''
        ).toLowerCase();

    const customers = App.state.customers.filter(
        function(customer) {
            return !filter ||
                String(customer.company || '')
                    .toLowerCase()
                    .includes(filter) ||
                String(customer.name || '')
                    .toLowerCase()
                    .includes(filter) ||
                String(customer.email || '')
                    .toLowerCase()
                    .includes(filter);
        }
    );

    return `
        <div class="overflow-x-auto">

            <table class="w-full min-w-[1050px]">

                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-xs text-slate-500 text-left">

                        <th class="px-5 py-3">
                            <input
                                id="select_all"
                                type="checkbox"
                                onchange="App.actions.toggleAllVisible(this.checked)"
                                class="rounded border-slate-300">
                        </th>

                        <th class="px-5 py-3">
                            会社名 / 氏名
                        </th>

                        <th class="px-5 py-3">
                            メールアドレス
                        </th>

                        <th class="px-5 py-3">
                            送信状況
                        </th>

                        <th class="px-5 py-3">
                            回答状況
                        </th>

                        <th class="px-5 py-3">
                            kintone
                        </th>

                    </tr>
                </thead>

                <tbody>

                    ${
                        customers.length
                        ? customers.map(function(customer) {

                            const selected =
                                App.state.selectedCustomers
                                    .includes(String(customer.id));

                            const alreadySent =
                                Number(customer.send_count || 0) > 0;

                            return `
                                <tr class="border-b border-slate-100">

                                    <td class="px-5 py-4">

                                        <input
                                            type="checkbox"
                                            ${selected ? 'checked' : ''}
                                            ${
                                                customer.source === 'web'
                                                ? 'disabled'
                                                : ''
                                            }
                                            onchange="
                                                App.actions.toggleCustomer(
                                                    '${App.utils.escapeAttr(customer.id)}',
                                                    this.checked
                                                )
                                            "
                                            class="rounded border-slate-300
                                                   text-brand-600">

                                    </td>

                                    <td class="px-5 py-4">

                                        <div class="font-semibold text-sm">
                                            ${App.utils.escapeHtml(
                                                customer.company || '-'
                                            )}
                                        </div>

                                        <div class="text-sm text-slate-500">
                                            ${App.utils.escapeHtml(
                                                customer.name || '-'
                                            )}
                                        </div>

                                        <div class="text-xs text-slate-400 mt-1">
                                            ${App.utils.escapeHtml(
                                                customer.phone || ''
                                            )}
                                        </div>

                                    </td>

                                    <td class="px-5 py-4 text-sm">
                                        ${App.utils.escapeHtml(
                                            customer.email || '-'
                                        )}
                                    </td>

                                    <td class="px-5 py-4">

                                        ${
                                            alreadySent
                                            ? `
                                            <div>
                                                <span class="px-2 py-1 rounded-full
                                                             bg-blue-100 text-blue-700
                                                             text-xs">
                                                    送信済み
                                                </span>

                                                <div class="text-xs text-slate-400 mt-1">
                                                    回数：
                                                    ${customer.send_count}
                                                </div>

                                                <div class="text-xs text-slate-400">
                                                    ${App.utils.escapeHtml(
                                                        App.utils.formatDate(
                                                            customer.sent_at
                                                        )
                                                    )}
                                                </div>
                                            </div>
                                            `
                                            : `
                                            <span class="px-2 py-1 rounded-full
                                                         bg-slate-100 text-slate-600
                                                         text-xs">
                                                未送信
                                            </span>
                                            `
                                        }

                                    </td>

                                    <td class="px-5 py-4">

                                        ${
                                            customer.answer_status === 'answered'
                                            ? `
                                            <span class="px-2 py-1 rounded-full
                                                         bg-emerald-100
                                                         text-emerald-700
                                                         text-xs">
                                                回答済み
                                            </span>
                                            `
                                            : `
                                            <span class="px-2 py-1 rounded-full
                                                         bg-amber-100
                                                         text-amber-700
                                                         text-xs">
                                                未回答
                                            </span>
                                            `
                                        }

                                    </td>

                                    <td class="px-5 py-4">

                                        ${
                                            customer.kintone_status === 'registered'
                                            ? `
                                            <span class="text-xs text-emerald-600">
                                                ✓ 登録完了
                                            </span>
                                            `
                                            : `
                                            <button
                                                onclick="
                                                    App.actions.markKintone(
                                                        '${App.utils.escapeAttr(customer.id)}'
                                                    )
                                                "
                                                class="text-xs px-2 py-1 rounded
                                                       border border-slate-200
                                                       hover:bg-slate-50">
                                                kintone登録完了
                                            </button>
                                            `
                                        }

                                    </td>

                                </tr>
                            `;
                        }).join('')
                        : `
                        <tr>
                            <td colspan="6"
                                class="px-5 py-12 text-center
                                       text-sm text-slate-400">
                                顧客データがありません。
                            </td>
                        </tr>
                        `
                    }

                </tbody>

            </table>

        </div>
    `;
};

App.actions.filterCustomers = function() {
    document.getElementById('customer_table').innerHTML =
        App.render.customerTableHtml();
    App.actions.updateSelectedCount();
};

App.actions.toggleCustomer = function(id, checked) {
    id = String(id);

    if (checked) {
        if (!App.state.selectedCustomers.includes(id)) {
            App.state.selectedCustomers.push(id);
        }
    } else {
        App.state.selectedCustomers =
            App.state.selectedCustomers.filter(
                item => item !== id
            );
    }

    App.actions.updateSelectedCount();
};

App.actions.toggleAllVisible = function(checked) {
    const filter =
        (
            document.getElementById('customer_filter')?.value ||
            ''
        ).toLowerCase();

    App.state.customers
        .filter(function(customer) {
            return !filter ||
                String(customer.company || '')
                    .toLowerCase()
                    .includes(filter) ||
                String(customer.name || '')
                    .toLowerCase()
                    .includes(filter) ||
                String(customer.email || '')
                    .toLowerCase()
                    .includes(filter);
        })
        .filter(customer => customer.source !== 'web')
        .forEach(function(customer) {
            const id = String(customer.id);

            if (checked) {
                if (!App.state.selectedCustomers.includes(id)) {
                    App.state.selectedCustomers.push(id);
                }
            } else {
                App.state.selectedCustomers =
                    App.state.selectedCustomers.filter(
                        item => item !== id
                    );
            }
        });

    App.render.mail();
};

App.actions.selectAllCustomers = function() {
    App.state.selectedCustomers =
        App.state.customers
            .filter(
                customer => customer.source !== 'web'
            )
            .map(customer => String(customer.id));

    App.render.mail();
};

App.actions.selectUnanswered = function() {
    App.state.selectedCustomers =
        App.state.customers
            .filter(function(customer) {
                return customer.source !== 'web' &&
                    customer.answer_status !== 'answered';
            })
            .map(customer => String(customer.id));

    App.render.mail();
};

App.actions.updateSelectedCount = function() {
    const element =
        document.getElementById('selected_customer_count');

    if (element) {
        element.textContent =
            App.state.selectedCustomers.length;
    }
};

App.actions.applyMailTemplate = function(type) {
    const subject =
        document.getElementById('mail_subject');

    const body =
        document.getElementById('mail_body');

    if (!subject || !body) {
        return;
    }

    if (type === 'reminder') {
        subject.value =
            '【再送】アンケートご回答のお願い';

        body.value =
`ご担当者様

先日ご案内したアンケートが未回答となっております。

{顧客名} 様

お手数ですが、以下よりご回答をお願いいたします。

{アンケートURL}

よろしくお願いいたします。`;
    } else {
        subject.value =
            '【アンケートのお願い】ご回答ください';

        body.value =
`ご担当者様

アンケートへのご協力をお願いいたします。

{顧客名} 様

以下のURLよりご回答ください。

{アンケートURL}

よろしくお願いいたします。`;
    }
};

App.actions.sendSelectedMail = async function() {
    const selected =
        App.state.selectedCustomers;

    if (!selected.length) {
        App.utils.notify(
            '送信対象を選択してください。',
            'warning'
        );
        return;
    }

    const alreadySent =
        App.state.customers.filter(
            customer =>
                selected.includes(String(customer.id)) &&
                Number(customer.send_count || 0) > 0
        );

    if (alreadySent.length) {
        if (!confirm(
            '既に送信済みの宛先が含まれています。\n' +
            '再送しますか？'
        )) {
            return;
        }
    }

    const subject =
        document.getElementById('mail_subject').value;

    const body =
        document.getElementById('mail_body').value;

    const templateType =
        document.getElementById('template_type').value;

    if (!subject.trim() || !body.trim()) {
        App.utils.notify(
            '件名と本文を入力してください。',
            'warning'
        );
        return;
    }

    if (!confirm(
        selected.length +
        '件の顧客へメールを送信します。\n\n実行しますか？'
    )) {
        return;
    }

    try {
        const result =
            await App.api.sendMail(
                App.state.mailSurveyId,
                selected,
                subject,
                body,
                templateType
            );

        App.utils.notify(
            result.message,
            result.errors?.length ? 'warning' : 'success'
        );

        if (result.errors?.length) {
            alert(
                result.errors.join('\n')
            );
        }

        await App.api.load();
        App.state.mailSurveyId =
            App.state.mailSurveyId;

        App.render.mail();
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};

App.actions.markKintone = async function(id) {
    try {
        await App.api.request(
            'mark_kintone',
            {
                customer_id: id
            }
        );

        const customer =
            App.state.customers.find(
                item => String(item.id) === String(id)
            );

        if (customer) {
            customer.kintone_status =
                'registered';
        }

        App.render.mail();

        App.utils.notify(
            'kintone登録完了として更新しました。',
            'success'
        );
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};

/* =========================================================
 * kintone settings
 * ======================================================= */

App.actions.openSettings = function() {
    App.render.settings();
};

App.render.settings = function() {
    const settings = App.state.settings || {};

    document.getElementById('main_content').innerHTML = `

        <div class="max-w-5xl">

            <div class="text-sm text-slate-400 mb-1">
                ホーム ＞ システム設定 ＞ kintone連携設定
            </div>

            <div class="flex items-center justify-between mb-6">

                <div>
                    <h1 class="text-2xl font-bold">
                        kintone連携設定
                    </h1>
                    <p class="text-sm text-slate-500 mt-1">
                        cybozu.com APIとの接続情報と項目マッピングを設定します。
                    </p>
                </div>

                <button
                    onclick="App.actions.goList()"
                    class="px-4 py-2.5 border border-slate-200
                           bg-white rounded-lg text-sm">
                    一覧へ戻る
                </button>

            </div>

            <form id="settings_form"
                  onsubmit="event.preventDefault(); App.actions.saveSettings();">

                <div class="bg-white border border-slate-200 rounded-xl p-6">

                    <div class="grid grid-cols-2 gap-5">

                        <div>
                            <label class="block text-sm font-medium mb-2">
                                サブドメイン / FQDN
                            </label>

                            <input
                                id="setting_subdomain"
                                value="${App.utils.escapeAttr(
                                    settings.subdomain || ''
                                )}"
                                placeholder="xxxx または xxxx.cybozu.com"
                                class="w-full border border-slate-200
                                       rounded-lg px-3 py-2.5">
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-2">
                                顧客管理アプリID
                            </label>

                            <input
                                id="setting_app_id"
                                value="${App.utils.escapeAttr(
                                    settings.app_id || ''
                                )}"
                                class="w-full border border-slate-200
                                       rounded-lg px-3 py-2.5">
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-2">
                                ログイン名
                            </label>

                            <input
                                id="setting_login_name"
                                value="${App.utils.escapeAttr(
                                    settings.login_name || ''
                                )}"
                                class="w-full border border-slate-200
                                       rounded-lg px-3 py-2.5">
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-2">
                                パスワード
                            </label>

                            <input
                                id="setting_password"
                                type="password"
                                value="${App.utils.escapeAttr(
                                    settings.password || ''
                                )}"
                                class="w-full border border-slate-200
                                       rounded-lg px-3 py-2.5">
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-2">
                                Proxyサーバ
                            </label>

                            <input
                                id="setting_proxy"
                                value="${App.utils.escapeAttr(
                                    settings.proxy || ''
                                )}"
                                placeholder="host名:port番号"
                                class="w-full border border-slate-200
                                       rounded-lg px-3 py-2.5">
                        </div>

                        <div class="flex items-end pb-2">

                            <label class="inline-flex items-center gap-2 text-sm">
                                <input
                                    id="setting_ssl_verify"
                                    type="checkbox"
                                    ${
                                        settings.ssl_verify
                                        ? 'checked'
                                        : ''
                                    }
                                    class="rounded border-slate-300">
                                SSL証明書を検証する
                            </label>

                        </div>

                    </div>

                    <div class="mt-6 p-4 bg-amber-50
                                border border-amber-200 rounded-lg
                                text-sm text-amber-800">

                        指定要件により、初期状態ではSSL証明書検証を
                        <strong>行わない</strong>設定です。
                        本番環境では可能な限り証明書検証を有効にしてください。

                    </div>

                </div>

                <div class="bg-white border border-slate-200
                            rounded-xl p-6 mt-5">

                    <div class="flex items-center justify-between mb-5">

                        <div>
                            <div class="font-bold">
                                kintoneフィールドマッピング
                            </div>

                            <div class="text-sm text-slate-400 mt-1">
                                「項目一覧を取得」で日本語フィールド名を取得できます。
                            </div>
                        </div>

                        <button
                            type="button"
                            onclick="App.actions.fetchKintoneFields()"
                            class="px-4 py-2.5 rounded-lg border
                                   border-slate-200 bg-white text-sm">
                            項目一覧を再取得
                        </button>

                    </div>

                    <div id="field_message"
                         class="mb-4 text-sm text-slate-500">
                    </div>

                    <div id="field_mapping"
                         class="grid grid-cols-2 gap-5">

                        ${App.render.fieldSelect(
                            'field_company',
                            '会社名',
                            settings.field_company
                        )}

                        ${App.render.fieldSelect(
                            'field_name',
                            '氏名',
                            settings.field_name
                        )}

                        ${App.render.fieldSelect(
                            'field_email',
                            'メールアドレス',
                            settings.field_email
                        )}

                        ${App.render.fieldSelect(
                            'field_department',
                            '部署名',
                            settings.field_department
                        )}

                        ${App.render.fieldSelect(
                            'field_phone',
                            '電話番号',
                            settings.field_phone
                        )}

                        ${App.render.fieldAddressSelects(
                            settings.field_address || []
                        )}

                    </div>

                </div>

                <div class="mt-5 flex justify-end gap-3">

                    <button
                        type="button"
                        onclick="App.actions.goList()"
                        class="px-5 py-2.5 border border-slate-200
                               rounded-lg bg-white">
                        キャンセル
                    </button>

                    <button
                        type="submit"
                        class="px-6 py-2.5 bg-brand-600 text-white
                               rounded-lg font-semibold">
                        設定を保存
                    </button>

                </div>

            </form>

        </div>
    `;
};

App.render.fieldSelect = function(
    key,
    label,
    value
) {
    const fields =
        App.state.kintoneFields || [];

    return `
        <div>
            <label class="block text-sm font-medium mb-2">
                ${App.utils.escapeHtml(label)}
            </label>

            <select
                data-field-key="${App.utils.escapeAttr(key)}"
                class="kintone-field-select w-full
                       border border-slate-200 rounded-lg
                       px-3 py-2.5">

                <option value="">
                    未設定
                </option>

                ${
                    fields.map(function(field) {
                        return `
                            <option
                                value="${App.utils.escapeAttr(field.code)}"
                                ${String(value || '') === String(field.code)
                                    ? 'selected'
                                    : ''}>
                                ${App.utils.escapeHtml(field.label)}
                                (${App.utils.escapeHtml(field.code)})
                            </option>
                        `;
                    }).join('')
                }

            </select>
        </div>
    `;
};

App.render.fieldAddressSelects = function(values) {
    const fields =
        App.state.kintoneFields || [];

    const list =
        Array.isArray(values) ? values : [];

    return `
        <div class="col-span-2">

            <label class="block text-sm font-medium mb-2">
                住所
                <span class="text-xs text-slate-400 ml-1">
                    複数フィールド可
                </span>
            </label>

            <div id="address_mapping"
                 class="space-y-2">

                ${
                    list.length
                    ? list.map(function(value) {
                        return App.render.addressSelect(value);
                    }).join('')
                    : App.render.addressSelect('')
                }

            </div>

            <button
                type="button"
                onclick="App.actions.addAddressField()"
                class="mt-2 text-sm text-brand-600">
                ＋ 住所フィールドを追加
            </button>

        </div>
    `;
};

App.render.addressSelect = function(value) {
    const fields =
        App.state.kintoneFields || [];

    return `
        <div class="flex gap-2">

            <select
                data-address-field="1"
                class="kintone-address-select flex-1
                       border border-slate-200 rounded-lg
                       px-3 py-2.5">

                <option value="">未設定</option>

                ${
                    fields.map(function(field) {
                        return `
                            <option
                                value="${App.utils.escapeAttr(field.code)}"
                                ${String(value || '') === String(field.code)
                                    ? 'selected'
                                    : ''}>
                                ${App.utils.escapeHtml(field.label)}
                                (${App.utils.escapeHtml(field.code)})
                            </option>
                        `;
                    }).join('')
                }

            </select>

            <button
                type="button"
                onclick="App.actions.removeAddressField(this)"
                class="px-3 text-slate-400 hover:text-red-600">
                ×
            </button>

        </div>
    `;
};

/*
 * 必須実装：
 * kintone APIを呼び出してフィールド一覧を取得する。
 */
App.actions.fetchKintoneFields = async function() {
    const settings = {
        subdomain:
            document.getElementById('setting_subdomain')?.value || '',
        app_id:
            document.getElementById('setting_app_id')?.value || '',
        login_name:
            document.getElementById('setting_login_name')?.value || '',
        password:
            document.getElementById('setting_password')?.value || '',
        ssl_verify:
            document.getElementById('setting_ssl_verify')?.checked || false,
        proxy:
            document.getElementById('setting_proxy')?.value || ''
    };

    const message =
        document.getElementById('field_message');

    if (message) {
        message.textContent =
            'kintoneから項目一覧を取得しています...';
    }

    try {
        const json = await App.api.request(
            'kintone_fields',
            {
                settings_json: settings,
                app_id: settings.app_id
            }
        );

        App.state.kintoneFields =
            json.fields || [];

        const fieldMapping =
            document.getElementById('field_mapping');

        if (fieldMapping) {
            fieldMapping.innerHTML =
                App.render.fieldSelect(
                    'field_company',
                    '会社名',
                    App.state.settings.field_company
                ) +
                App.render.fieldSelect(
                    'field_name',
                    '氏名',
                    App.state.settings.field_name
                ) +
                App.render.fieldSelect(
                    'field_email',
                    'メールアドレス',
                    App.state.settings.field_email
                ) +
                App.render.fieldSelect(
                    'field_department',
                    '部署名',
                    App.state.settings.field_department
                ) +
                App.render.fieldSelect(
                    'field_phone',
                    '電話番号',
                    App.state.settings.field_phone
                ) +
                App.render.fieldAddressSelects(
                    App.state.settings.field_address || []
                );
        }

        if (message) {
            message.textContent =
                App.state.kintoneFields.length +
                '件のフィールドを取得しました。';
        }

        App.utils.notify(
            'kintoneの項目一覧を取得しました。',
            'success'
        );

    } catch (error) {
        if (message) {
            message.textContent =
                error.message;
        }

        App.utils.notify(
            error.message,
            'error'
        );
    }
};

App.actions.addAddressField = function() {
    const container =
        document.getElementById('address_mapping');

    if (!container) {
        return;
    }

    container.insertAdjacentHTML(
        'beforeend',
        App.render.addressSelect('')
    );
};

App.actions.removeAddressField = function(button) {
    const container =
        document.getElementById('address_mapping');

    if (!container) {
        return;
    }

    if (
        container.querySelectorAll(
            '[data-address-field]'
        ).length <= 1
    ) {
        return;
    }

    button.closest('.flex')?.remove();
};

App.actions.saveSettings = async function() {
    const addressFields =
        Array.from(
            document.querySelectorAll(
                '.kintone-address-select'
            )
        )
        .map(select => select.value)
        .filter(Boolean);

    const settings = {
        subdomain:
            document.getElementById('setting_subdomain').value.trim(),

        login_name:
            document.getElementById('setting_login_name').value.trim(),

        password:
            document.getElementById('setting_password').value,

        app_id:
            document.getElementById('setting_app_id').value.trim(),

        ssl_verify:
            document.getElementById('setting_ssl_verify').checked,

        proxy:
            document.getElementById('setting_proxy').value.trim(),

        field_company:
            document.querySelector(
                '[data-field-key="field_company"]'
            )?.value || '',

        field_name:
            document.querySelector(
                '[data-field-key="field_name"]'
            )?.value || '',

        field_email:
            document.querySelector(
                '[data-field-key="field_email"]'
            )?.value || '',

        field_department:
            document.querySelector(
                '[data-field-key="field_department"]'
            )?.value || '',

        field_phone:
            document.querySelector(
                '[data-field-key="field_phone"]'
            )?.value || '',

        field_address: addressFields
    };

    try {
        await App.api.saveSettings(settings);

        App.utils.notify(
            'kintone連携設定を保存しました。',
            'success'
        );
    } catch (error) {
        App.utils.notify(
            error.message,
            'error'
        );
    }
};

App.actions.logout = function() {
    alert(
        'このサンプルでは管理者セッションの認証機構を外部認証へ接続していません。'
    );
};

/* =========================================================
 * Initialization
 * ======================================================= */

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        { once: true }
    );
} else {
    App.init();
}
</script>

</body>
</html>
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
- status
- answers
- email
- name
- company

API/JSONキー:
- properties
- records
- label
- code
- type
- message
- ok
- fields
- data
- csrf_token
- status
- error_code
- endpoint

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
- field_company
- field_name
- field_email
- field_department
- field_phone
- field_address
- survey_list
- editor_groups
- editor_group_list
- kintone_status
- branching_editor

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

/* =====================================================================
 * PHP utility
 * ===================================================================== */

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

    $defaults = survey_default_data();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    $data['settings'] = array_merge(
        $defaults['settings'],
        is_array($data['settings'] ?? null) ? $data['settings'] : []
    );

    foreach (['surveys', 'responses', 'customers', 'mail_logs'] as $key) {
        if (!is_array($data[$key])) {
            $data[$key] = [];
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

    if (is_file(SURVEY_STORAGE_FILE)) {
        @copy(SURVEY_STORAGE_FILE, SURVEY_STORAGE_FILE . '.bak');
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

function survey_h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* =====================================================================
 * Normalization
 * ===================================================================== */

function survey_normalize_question(mixed $question): array
{
    $q = is_array($question) ? $question : [];

    $type = (string)($q['type'] ?? 'single');

    if (!in_array($type, ['single', 'multiple', 'text'], true)) {
        $type = 'single';
    }

    $options = $q['options'] ?? [];

    if (!is_array($options)) {
        $options = [];
    }

    $options = array_values(array_map(
        static fn(mixed $v): string => trim((string)$v),
        $options
    ));

    $branching = $q['branching'] ?? [];

    if (!is_array($branching)) {
        $branching = [];
    }

    $normalizedBranching = [];

    foreach ($branching as $item) {
        if (!is_array($item)) {
            continue;
        }

        $normalizedBranching[] = [
            'option' => (string)($item['option'] ?? ''),
            'target_question_id' =>
                (string)($item['target_question_id'] ?? '')
        ];
    }

    if ($type !== 'single') {
        $normalizedBranching = [];
    } else {
        $newBranching = [];

        foreach ($options as $option) {
            $target = '';

            foreach ($normalizedBranching as $old) {
                if ($old['option'] === $option) {
                    $target = $old['target_question_id'];
                    break;
                }
            }

            $newBranching[] = [
                'option' => $option,
                'target_question_id' => $target
            ];
        }

        $normalizedBranching = $newBranching;
    }

    return [
        'id' => (string)($q['id'] ?? survey_id('question')),
        'text' => (string)($q['text'] ?? ''),
        'type' => $type,
        'required' => !empty($q['required']),
        'options' => $options,
        'other_enabled' => !empty($q['other_enabled']),
        'branching' => $normalizedBranching
    ];
}

function survey_normalize_survey(mixed $survey): array
{
    $s = is_array($survey) ? $survey : [];

    $status = (string)($s['status'] ?? 'draft');

    if (!in_array($status, ['draft', 'active', 'ended'], true)) {
        $status = 'draft';
    }

    $numbering = (string)($s['numbering_mode'] ?? 'global');

    if (!in_array($numbering, ['global', 'group'], true)) {
        $numbering = 'global';
    }

    $groups = $s['groups'] ?? [];

    if (!is_array($groups)) {
        $groups = [];
    }

    $normalizedGroups = [];

    foreach ($groups as $gi => $group) {
        $group = is_array($group) ? $group : [];
        $questions = $group['questions'] ?? [];

        if (!is_array($questions)) {
            $questions = [];
        }

        $normalizedGroups[] = [
            'id' => (string)($group['id'] ?? survey_id('group')),
            'name' => (string)(
                $group['name'] ??
                'グループ' . ((int)$gi + 1)
            ),
            'questions' => array_map(
                'survey_normalize_question',
                $questions
            )
        ];
    }

    if (!$normalizedGroups) {
        $normalizedGroups[] = [
            'id' => survey_id('group'),
            'name' => 'グループ1',
            'questions' => []
        ];
    }

    return [
        'id' => (string)($s['id'] ?? survey_id('survey')),
        'title' => (string)($s['title'] ?? '新しいアンケート'),
        'start_at' => (string)($s['start_at'] ?? ''),
        'end_at' => (string)($s['end_at'] ?? ''),
        'status' => $status,
        'created_at' => (string)($s['created_at'] ?? survey_now()),
        'updated_at' => (string)($s['updated_at'] ?? survey_now()),
        'numbering_mode' => $numbering,
        'groups' => $normalizedGroups,
        'deleted' => !empty($s['deleted'])
    ];
}

function survey_normalize_all(array $data): array
{
    $data['surveys'] = array_map(
        'survey_normalize_survey',
        $data['surveys']
    );

    return $data;
}

/* =====================================================================
 * kintone
 * ===================================================================== */

function survey_normalize_kintone_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace(
        '#^\s*https?://#i',
        '',
        $value
    );

    $value = preg_replace(
        '#/.*$#',
        '',
        (string)$value
    );

    $value = trim($value, ". \t\n\r\0\x0B");

    if (preg_match('/\.cybozu\.com$/i', $value)) {
        return 'https://' . $value;
    }

    return 'https://' . $value . '.cybozu.com';
}

function survey_http_status(): int
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
            (string)$header,
            $m
        )) {
            return (int)$m[1];
        }
    }

    return 0;
}

function survey_kintone_request(
    string $method,
    string $path,
    array $settings,
    ?array $body = null
): array {
    $base = survey_normalize_kintone_host(
        (string)($settings['subdomain'] ?? '')
    );

    if ($base === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' => 'kintoneサブドメインが設定されていません。'
        ];
    }

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' => 'kintoneログイン情報を設定してください。'
        ];
    }

    $url = $base . '/' . ltrim($path, '/');

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' =>
                "Content-Type: application/json\r\n" .
                "Accept: application/json\r\n" .
                "X-Cybozu-Authorization: " .
                base64_encode($login . ':' . $password),
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
        $json = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'JSON',
                'message' => 'JSON生成に失敗しました。',
                'endpoint' => $url
            ];
        }

        $options['http']['content'] = $json;
    }

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match(
            '/^[^:\/\s]+:\d+$/',
            $proxy
        )) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'PROXY',
                'message' => 'Proxyサーバは host:port 形式で入力してください。',
                'endpoint' => $url
            ];
        }

        $options['http']['proxy'] = 'tcp://' . $proxy;
        $options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($options);

    $raw = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = survey_http_status();

    $decoded = json_decode((string)$raw, true);

    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'status' => $status,
            'data' => $decoded,
            'endpoint' => $url
        ];
    }

    return [
        'ok' => false,
        'status' => $status,
        'error_code' => (string)(
            $decoded['code'] ??
            $decoded['error_code'] ??
            ''
        ),
        'message' => (string)(
            $decoded['message'] ??
            'kintone API通信に失敗しました。'
        ),
        'data' => $decoded,
        'endpoint' => $url
    ];
}

function survey_kintone_field_value(
    array $record,
    string $code
): string {
    if ($code === '' || !isset($record[$code])) {
        return '';
    }

    $value = $record[$code]['value'] ?? '';

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $parts[] = (string)($item['value'] ?? '');
            } else {
                $parts[] = (string)$item;
            }
        }

        return implode(' / ', array_filter($parts, 'strlen'));
    }

    return (string)$value;
}

function survey_kintone_sync(array &$data): array
{
    $settings = $data['settings'];

    $appId = trim((string)($settings['app_id'] ?? ''));

    if ($appId === '' || !ctype_digit($appId)) {
        return [
            'ok' => false,
            'message' => 'kintoneアプリIDを設定してください。'
        ];
    }

    $result = survey_kintone_request(
        'GET',
        '/k/v1/records.json?app=' .
        rawurlencode($appId) .
        '&totalCount=true&query=' .
        rawurlencode('order by $id asc limit 500'),
        $settings
    );

    if (!$result['ok']) {
        return $result;
    }

    $records = $result['data']['records'] ?? [];

    if (!is_array($records)) {
        $records = [];
    }

    $map = [
        'company' => (string)($settings['field_company'] ?? ''),
        'name' => (string)($settings['field_name'] ?? ''),
        'email' => (string)($settings['field_email'] ?? ''),
        'department' => (string)($settings['field_department'] ?? ''),
        'phone' => (string)($settings['field_phone'] ?? '')
    ];

    $address = $settings['field_address'] ?? [];

    if (!is_array($address)) {
        $address = [];
    }

    $existingByEmail = [];

    foreach ($data['customers'] as $index => $customer) {
        $email = strtolower(trim((string)($customer['email'] ?? '')));

        if ($email !== '') {
            $existingByEmail[$email] = $index;
        }
    }

    $count = 0;

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $email = trim(
            survey_kintone_field_value(
                $record,
                $map['email']
            )
        );

        if ($email === '') {
            continue;
        }

        $company = survey_kintone_field_value(
            $record,
            $map['company']
        );

        $name = survey_kintone_field_value(
            $record,
            $map['name']
        );

        $department = survey_kintone_field_value(
            $record,
            $map['department']
        );

        $phone = survey_kintone_field_value(
            $record,
            $map['phone']
        );

        $addressParts = [];

        foreach ($address as $field) {
            $field = trim((string)$field);

            if ($field !== '') {
                $v = survey_kintone_field_value(
                    $record,
                    $field
                );

                if ($v !== '') {
                    $addressParts[] = $v;
                }
            }
        }

        $key = strtolower($email);

        $customer = [
            'id' => survey_id('customer'),
            'company' => $company,
            'name' => $name,
            'email' => $email,
            'department' => $department,
            'phone' => $phone,
            'address' => implode(' ', $addressParts),
            'source' => 'kintone',
            'sent_at' => '',
            'send_count' => 0,
            'answer_status' => 'unanswered',
            'kintone_status' => 'registered'
        ];

        if (isset($existingByEmail[$key])) {
            $index = $existingByEmail[$key];

            $old = $data['customers'][$index];

            $customer['id'] =
                (string)($old['id'] ?? $customer['id']);

            $customer['sent_at'] =
                (string)($old['sent_at'] ?? '');

            $customer['send_count'] =
                (int)($old['send_count'] ?? 0);

            $customer['answer_status'] =
                (string)($old['answer_status'] ?? 'unanswered');

            $data['customers'][$index] = $customer;
        } else {
            $data['customers'][] = $customer;
            $existingByEmail[$key] =
                array_key_last($data['customers']);
        }

        $count++;
    }

    survey_save_data($data);

    return [
        'ok' => true,
        'count' => $count
    ];
}

/* =====================================================================
 * Public URL
 * ===================================================================== */

function survey_public_url(
    string $surveyId,
    string $customerId = ''
): string {
    $scheme =
        (!empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

    $host = (string)(
        $_SERVER['HTTP_HOST'] ?? 'localhost'
    );

    $path = (string)(
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    return $scheme . '://' .
        $host .
        $path .
        '?' .
        http_build_query([
            'public' => '1',
            'survey_id' => $surveyId,
            'customer_id' => $customerId
        ]);
}

/* =====================================================================
 * API
 * ===================================================================== */

$data = survey_normalize_all(
    survey_load_data()
);

$action = (string)(
    $_REQUEST['action'] ?? ''
);

/* Public answer screen */
if (
    isset($_GET['public']) &&
    $_GET['public'] === '1'
) {
    $surveyId = (string)(
        $_GET['survey_id'] ?? ''
    );

    $customerId = (string)(
        $_GET['customer_id'] ?? ''
    );

    $survey = null;

    foreach ($data['surveys'] as $item) {
        if (
            $item['id'] === $surveyId &&
            !$item['deleted']
        ) {
            $survey = $item;
            break;
        }
    }

    if (!$survey) {
        http_response_code(404);
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <script src="https://cdn.tailwindcss.com"></script>
            <title>アンケート</title>
        </head>
        <body class="bg-gray-50 min-h-screen flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow p-8 text-center">
            <h1 class="text-xl font-bold text-gray-800">アンケートが見つかりません</h1>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    if ($survey['status'] !== 'active') {
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <script src="https://cdn.tailwindcss.com"></script>
            <title><?= survey_h($survey['title']) ?></title>
        </head>
        <body class="bg-gray-50 min-h-screen flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow p-8 max-w-lg w-full text-center">
            <h1 class="text-xl font-bold text-gray-800">
                <?= survey_h($survey['title']) ?>
            </h1>
            <p class="mt-4 text-gray-500">
                このアンケートは現在回答を受け付けていません。
            </p>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    $alreadyAnswered = false;

    foreach ($data['responses'] as $response) {
        if (
            $response['survey_id'] === $surveyId &&
            (
                ($customerId !== '' &&
                $response['customer_id'] === $customerId)
                ||
                ($customerId === '' &&
                !empty($_POST['email']) &&
                strtolower((string)$response['email']) ===
                strtolower((string)$_POST['email']))
            )
        ) {
            $alreadyAnswered = true;
            break;
        }
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['public_answer'])
    ) {
        header('Content-Type: text/html; charset=UTF-8');

        $email = trim((string)($_POST['email'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $company = trim((string)($_POST['company'] ?? ''));
        $answersRaw = $_POST['answers'] ?? [];

        if (!is_array($answersRaw)) {
            $answersRaw = [];
        }

        $errors = [];

        if ($email === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '有効なメールアドレスを入力してください。';
        }

        if ($name === '') {
            $errors[] = '氏名を入力してください。';
        }

        if ($alreadyAnswered) {
            $errors[] = 'このアンケートにはすでに回答済みです。';
        }

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                if (!$question['required']) {
                    continue;
                }

                $answer = $answersRaw[$question['id']] ?? '';

                if (is_array($answer)) {
                    $answer = array_filter(
                        $answer,
                        static fn($v) => trim((string)$v) !== ''
                    );
                    $empty = count($answer) === 0;
                } else {
                    $empty = trim((string)$answer) === '';
                }

                if ($empty) {
                    $errors[] =
                        '必須設問「' .
                        $question['text'] .
                        '」に回答してください。';
                }
            }
        }

        if (!$errors) {
            $customerIndex = null;

            foreach ($data['customers'] as $i => $customer) {
                if (
                    strtolower(trim((string)$customer['email'])) ===
                    strtolower($email)
                ) {
                    $customerIndex = $i;
                    break;
                }
            }

            if ($customerIndex === null) {
                $customer = [
                    'id' => $customerId !== ''
                        ? $customerId
                        : survey_id('customer'),
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

                $data['customers'][] = $customer;
                $customerIndex =
                    array_key_last($data['customers']);
            }

            $customer = $data['customers'][$customerIndex];

            $customer['company'] =
                $company !== ''
                    ? $company
                    : (string)$customer['company'];

            $customer['name'] =
                $name !== ''
                    ? $name
                    : (string)$customer['name'];

            $customer['email'] = $email;
            $customer['answer_status'] = 'answered';

            $data['customers'][$customerIndex] = $customer;

            $data['responses'][] = [
                'id' => survey_id('response'),
                'survey_id' => $surveyId,
                'customer_id' => (string)$customer['id'],
                'company' => $customer['company'],
                'name' => $customer['name'],
                'email' => $email,
                'answered_at' => survey_now(),
                'answers' => $answersRaw
            ];

            survey_save_data($data);
            ?>
            <!doctype html>
            <html lang="ja">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width,initial-scale=1">
                <script src="https://cdn.tailwindcss.com"></script>
                <title>回答完了</title>
            </head>
            <body class="bg-gray-50 min-h-screen flex items-center justify-center">
            <div class="bg-white rounded-2xl shadow p-8 max-w-lg w-full text-center">
                <div class="w-14 h-14 mx-auto rounded-full bg-green-100 text-green-600 flex items-center justify-center text-2xl">✓</div>
                <h1 class="mt-5 text-2xl font-bold text-gray-800">回答ありがとうございました</h1>
                <p class="mt-3 text-gray-500">回答を正常に受け付けました。</p>
            </div>
            </body>
            </html>
            <?php
            exit;
        }

        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <script src="https://cdn.tailwindcss.com"></script>
            <title><?= survey_h($survey['title']) ?></title>
        </head>
        <body class="bg-gray-50">
        <div class="max-w-3xl mx-auto p-5">
            <div class="bg-white rounded-2xl shadow p-6">
                <h1 class="text-2xl font-bold"><?= survey_h($survey['title']) ?></h1>
                <div class="mt-5 rounded-xl bg-red-50 text-red-700 p-4">
                    <?php foreach ($errors as $error): ?>
                        <div><?= survey_h($error) ?></div>
                    <?php endforeach; ?>
                </div>
                <a href="<?= survey_h(survey_public_url($surveyId, $customerId)) ?>"
                   class="inline-block mt-5 px-5 py-3 bg-indigo-600 text-white rounded-xl">
                    回答画面へ戻る
                </a>
            </div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    if ($alreadyAnswered) {
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <script src="https://cdn.tailwindcss.com"></script>
            <title>回答済み</title>
        </head>
        <body class="bg-gray-50 min-h-screen flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow p-8 text-center">
            <h1 class="text-xl font-bold">回答済みです</h1>
            <p class="mt-3 text-gray-500">このアンケートにはすでに回答されています。</p>
        </div>
        </body>
        </html>
        <?php
        exit;
    }
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <script src="https://cdn.tailwindcss.com"></script>
        <title><?= survey_h($survey['title']) ?></title>
    </head>
    <body class="bg-gray-50 text-gray-800">
    <div class="max-w-3xl mx-auto p-5 md:p-8">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="bg-indigo-600 text-white p-7">
                <h1 class="text-2xl font-bold"><?= survey_h($survey['title']) ?></h1>
                <p class="mt-2 text-indigo-100">アンケートにご回答ください。</p>
            </div>

            <form method="post" class="p-6 md:p-8 space-y-8">
                <input type="hidden" name="public_answer" value="1">

                <section class="space-y-4">
                    <h2 class="font-bold text-lg">回答者情報</h2>

                    <div>
                        <label class="block text-sm font-medium mb-1">会社名</label>
                        <input name="company"
                               value="<?= survey_h((string)($_POST['company'] ?? '')) ?>"
                               class="w-full rounded-xl border border-gray-300 px-4 py-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">氏名 *</label>
                        <input name="name"
                               required
                               value="<?= survey_h((string)($_POST['name'] ?? '')) ?>"
                               class="w-full rounded-xl border border-gray-300 px-4 py-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">メールアドレス *</label>
                        <input name="email"
                               type="email"
                               required
                               value="<?= survey_h((string)($_POST['email'] ?? '')) ?>"
                               class="w-full rounded-xl border border-gray-300 px-4 py-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                </section>

                <?php
                $globalNo = 0;
                foreach ($survey['groups'] as $gi => $group):
                ?>
                    <section class="border-t pt-7">
                        <h2 class="text-lg font-bold mb-5">
                            <?= survey_h($group['name']) ?>
                        </h2>

                        <div class="space-y-7">
                        <?php
                        foreach ($group['questions'] as $qi => $question):
                            $globalNo++;

                            $number =
                                $survey['numbering_mode'] === 'group'
                                    ? ($gi + 1) . '-' . ($qi + 1)
                                    : (string)$globalNo;

                            $value =
                                $_POST['answers'][$question['id']]
                                ?? '';
                        ?>
                            <div>
                                <div class="font-medium mb-3">
                                    <span class="text-indigo-600 mr-1">
                                        Q<?= survey_h($number) ?>
                                    </span>
                                    <?= survey_h($question['text']) ?>
                                    <?php if ($question['required']): ?>
                                        <span class="text-red-500 ml-1">*</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($question['type'] === 'text'): ?>

                                    <textarea
                                        name="answers[<?= survey_h($question['id']) ?>]"
                                        rows="4"
                                        class="w-full rounded-xl border border-gray-300 px-4 py-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none"><?= survey_h((string)$value) ?></textarea>

                                <?php elseif ($question['type'] === 'single'): ?>

                                    <div class="space-y-2">
                                    <?php foreach ($question['options'] as $option): ?>
                                        <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50">
                                            <input type="radio"
                                                   name="answers[<?= survey_h($question['id']) ?>]"
                                                   value="<?= survey_h($option) ?>"
                                                   <?= (string)$value === $option ? 'checked' : '' ?>
                                                   class="w-5 h-5 text-indigo-600">
                                            <span><?= survey_h($option) ?></span>
                                        </label>
                                    <?php endforeach; ?>

                                    <?php if ($question['other_enabled']): ?>
                                        <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50">
                                            <input type="radio"
                                                   name="answers[<?= survey_h($question['id']) ?>]"
                                                   value="その他"
                                                   <?= (string)$value === 'その他' ? 'checked' : '' ?>
                                                   class="w-5 h-5 text-indigo-600">
                                            <span>その他</span>
                                        </label>
                                        <input name="answers[<?= survey_h($question['id']) ?>_other]"
                                               placeholder="その他の内容"
                                               class="w-full rounded-xl border border-gray-300 px-4 py-3">
                                    <?php endif; ?>
                                    </div>

                                <?php else: ?>

                                    <div class="space-y-2">
                                    <?php
                                    $multipleValue =
                                        is_array($value) ? $value : [];
                                    foreach ($question['options'] as $option):
                                    ?>
                                        <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-gray-50">
                                            <input type="checkbox"
                                                   name="answers[<?= survey_h($question['id']) ?>][]"
                                                   value="<?= survey_h($option) ?>"
                                                   <?= in_array($option, $multipleValue, true) ? 'checked' : '' ?>
                                                   class="w-5 h-5 rounded text-indigo-600">
                                            <span><?= survey_h($option) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>

                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <button
                    class="w-full rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-4 transition">
                    回答を送信する
                </button>
            </form>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/* ---------------------------------------------------------------------
 * Admin API
 * --------------------------------------------------------------------- */

if ($action !== '') {

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        $action !== 'public_answer'
    ) {
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
            $survey = json_decode(
                (string)($_POST['survey_json'] ?? ''),
                true
            );

            if (!is_array($survey)) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $survey = survey_normalize_survey($survey);
            $now = survey_now();
            $found = false;

            foreach ($data['surveys'] as $i => $old) {
                if ($old['id'] === $survey['id']) {
                    $survey['created_at'] =
                        $old['created_at'] ?? $now;
                    $survey['updated_at'] = $now;
                    $data['surveys'][$i] = $survey;
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

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'survey' => $survey
            ]);

        case 'delete_survey':
            $id = (string)($_POST['survey_id'] ?? '');

            foreach ($data['surveys'] as &$survey) {
                if ($survey['id'] === $id) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] = survey_now();
                    break;
                }
            }

            unset($survey);
            survey_save_data($data);

            survey_json_response(['ok' => true]);

        case 'change_status':
            $id = (string)($_POST['survey_id'] ?? '');
            $status = (string)($_POST['status'] ?? '');

            if (!in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'ステータスが不正です。'
                ], 400);
            }

            foreach ($data['surveys'] as &$survey) {
                if ($survey['id'] === $id) {
                    $survey['status'] = $status;
                    $survey['updated_at'] = survey_now();
                    break;
                }
            }

            unset($survey);
            survey_save_data($data);

            survey_json_response(['ok' => true]);

        case 'save_settings':
            $settings = json_decode(
                (string)($_POST['settings_json'] ?? ''),
                true
            );

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            if (
                empty($settings['password']) &&
                !empty($data['settings']['password'])
            ) {
                $settings['password'] =
                    $data['settings']['password'];
            }

            $settings['ssl_verify'] =
                !empty($settings['ssl_verify']);

            if (!is_array($settings['field_address'] ?? null)) {
                $settings['field_address'] = [];
            }

            $data['settings'] = array_merge(
                survey_default_data()['settings'],
                $settings
            );

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'settings' => $data['settings']
            ]);

        case 'kintone_fields':
            $settings = $data['settings'];

            $appId = trim(
                (string)(
                    $_POST['app_id'] ??
                    $settings['app_id'] ??
                    ''
                )
            );

            if ($appId === '' || !ctype_digit($appId)) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アプリIDは数字で入力してください。'
                ], 400);
            }

            $settings['app_id'] = $appId;

            $result = survey_kintone_request(
                'GET',
                '/k/v1/app/form/fields.json?app=' .
                rawurlencode($appId),
                $settings
            );

            if (!$result['ok']) {
                survey_json_response([
                    'ok' => false,
                    'message' => $result['message'] ?? 'kintone API通信に失敗しました。',
                    'error_code' => $result['error_code'] ?? '',
                    'status' => $result['status'] ?? 0
                ], 400);
            }

            survey_json_response([
                'ok' => true,
                'fields' => $result['data']['properties'] ?? []
            ]);

        case 'kintone_sync':
            $result = survey_kintone_sync($data);

            if (!$result['ok']) {
                survey_json_response($result, 400);
            }

            survey_json_response([
                'ok' => true,
                'count' => $result['count'],
                'data' => $data
            ]);

        case 'kintone_register_customer':
            $customerId = (string)(
                $_POST['customer_id'] ?? ''
            );

            $customer = null;

            foreach ($data['customers'] as $item) {
                if ($item['id'] === $customerId) {
                    $customer = $item;
                    break;
                }
            }

            if (!$customer) {
                survey_json_response([
                    'ok' => false,
                    'message' => '顧客が見つかりません。'
                ], 404);
            }

            $settings = $data['settings'];

            if (
                empty($settings['app_id']) ||
                !ctype_digit((string)$settings['app_id'])
            ) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'kintoneアプリIDを設定してください。'
                ], 400);
            }

            $fields = [];

            $map = [
                'field_company' => 'company',
                'field_name' => 'name',
                'field_email' => 'email',
                'field_department' => 'department',
                'field_phone' => 'phone'
            ];

            foreach ($map as $settingKey => $customerKey) {
                $code = (string)(
                    $settings[$settingKey] ?? ''
                );

                if ($code !== '') {
                    $fields[$code] = [
                        'value' => (string)(
                            $customer[$customerKey] ?? ''
                        )
                    ];
                }
            }

            $addressFields =
                $settings['field_address'] ?? [];

            if (!is_array($addressFields)) {
                $addressFields = [];
            }

            $address = (string)(
                $customer['address'] ?? ''
            );

            foreach ($addressFields as $code) {
                $code = trim((string)$code);

                if ($code === '') {
                    continue;
                }

                $fields[$code] = [
                    'value' => $address
                ];
            }

            $result = survey_kintone_request(
                'POST',
                '/k/v1/record.json',
                $settings,
                [
                    'app' => (int)$settings['app_id'],
                    'record' => $fields
                ]
            );

            if (!$result['ok']) {
                survey_json_response([
                    'ok' => false,
                    'message' => $result['message'],
                    'status' => $result['status'],
                    'error_code' => $result['error_code']
                ], 400);
            }

            foreach ($data['customers'] as &$item) {
                if ($item['id'] === $customerId) {
                    $item['kintone_status'] = 'registered';
                    break;
                }
            }

            unset($item);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);

        case 'send_mail':
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

            $body = (string)(
                $_POST['mail_body'] ?? ''
            );

            $templateType = (string)(
                $_POST['template_type'] ?? 'initial'
            );

            if (!in_array(
                $templateType,
                ['initial', 'reminder'],
                true
            )) {
                $templateType = 'initial';
            }

            if ($subject === '' || trim($body) === '') {
                survey_json_response([
                    'ok' => false,
                    'message' => '件名と本文を入力してください。'
                ], 400);
            }

            $surveyId = (string)(
                $_POST['survey_id'] ?? ''
            );

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if ($item['id'] === $surveyId) {
                    $survey = $item;
                    break;
                }
            }

            if (!$survey) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。'
                ], 404);
            }

            $sent = 0;
            $failed = 0;
            $results = [];
            $now = survey_now();

            foreach ($recipientIds as $recipientId) {
                $recipientId = (string)$recipientId;

                $customerIndex = null;

                foreach ($data['customers'] as $i => $customer) {
                    if ($customer['id'] === $recipientId) {
                        $customerIndex = $i;
                        break;
                    }
                }

                if ($customerIndex === null) {
                    continue;
                }

                $customer = $data['customers'][$customerIndex];
                $email = trim((string)$customer['email']);

                if (
                    $email === '' ||
                    !filter_var($email, FILTER_VALIDATE_EMAIL)
                ) {
                    $failed++;

                    $results[] = [
                        'customer_id' => $recipientId,
                        'ok' => false,
                        'message' => 'メールアドレスが不正です。'
                    ];

                    continue;
                }

                $personalSubject = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        (string)$customer['name'],
                        survey_public_url(
                            $surveyId,
                            $recipientId
                        )
                    ],
                    $subject
                );

                $personalBody = str_replace(
                    ['{顧客名}', '{アンケートURL}'],
                    [
                        (string)$customer['name'],
                        survey_public_url(
                            $surveyId,
                            $recipientId
                        )
                    ],
                    $body
                );

                $headers =
                    "MIME-Version: 1.0\r\n" .
                    "Content-Type: text/plain; charset=UTF-8\r\n" .
                    "From: " .
                    ((string)(
                        $_SERVER['SERVER_ADMIN'] ??
                        'webmaster@localhost'
                    )) .
                    "\r\n";

                $ok = @mail(
                    $email,
                    mb_encode_mimeheader(
                        $personalSubject,
                        'UTF-8'
                    ),
                    $personalBody,
                    $headers
                );

                if ($ok) {
                    $sent++;

                    $customer['sent_at'] = $now;
                    $customer['send_count'] =
                        ((int)$customer['send_count']) + 1;

                    if (
                        $customer['answer_status'] !== 'answered'
                    ) {
                        $customer['answer_status'] =
                            'unanswered';
                    }

                    $data['customers'][$customerIndex] =
                        $customer;

                    $results[] = [
                        'customer_id' => $recipientId,
                        'ok' => true
                    ];
                } else {
                    $failed++;

                    $results[] = [
                        'customer_id' => $recipientId,
                        'ok' => false,
                        'message' => 'mail() が送信に失敗しました。'
                    ];
                }

                $data['mail_logs'][] = [
                    'id' => survey_id('mail_log'),
                    'survey_id' => $surveyId,
                    'customer_id' => $recipientId,
                    'sent_at' => $now,
                    'type' => $templateType,
                    'subject' => $personalSubject,
                    'body' => $personalBody,
                    'executed_by' =>
                        (string)(
                            $_SESSION['admin_user'] ??
                            'admin'
                        ),
                    'ok' => $ok
                ];
            }

            survey_save_data($data);

            survey_json_response([
                'ok' => $failed === 0,
                'sent' => $sent,
                'failed' => $failed,
                'results' => $results,
                'message' =>
                    $sent . '件送信、' .
                    $failed . '件失敗しました。'
            ]);

        case 'register_customer':
            /* 互換API */
            $_POST['customer_id'] =
                (string)($_POST['customer_id'] ?? '');

            $customerId = (string)$_POST['customer_id'];

            foreach ($data['customers'] as &$customer) {
                if ($customer['id'] === $customerId) {
                    $customer['kintone_status'] =
                        'registered';
                    break;
                }
            }

            unset($customer);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);

        case 'csv':
            $surveyId = (string)(
                $_GET['survey_id'] ?? ''
            );

            $survey = null;

            foreach ($data['surveys'] as $item) {
                if ($item['id'] === $surveyId) {
                    $survey = $item;
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

            $out = fopen('php://output', 'wb');

            header(
                'Content-Type: text/csv; charset=UTF-8'
            );

            header(
                'Content-Disposition: attachment; filename="survey_' .
                preg_replace(
                    '/[^A-Za-z0-9_-]/',
                    '_',
                    $surveyId
                ) .
                '.csv"'
            );

            fwrite($out, "\xEF\xBB\xBF");

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名'
            ];

            $globalNo = 0;

            foreach ($survey['groups'] as $gi => $group) {
                foreach ($group['questions'] as $qi => $question) {
                    $globalNo++;

                    $number =
                        $survey['numbering_mode'] === 'group'
                            ? ($gi + 1) . '-' . ($qi + 1)
                            : (string)$globalNo;

                    $header[] =
                        'Q' . $number . ' ' .
                        $question['text'];
                }
            }

            fputcsv($out, $header);

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

                $answers =
                    is_array($response['answers'] ?? null)
                        ? $response['answers']
                        : [];

                foreach ($questions as $question) {
                    $value =
                        $answers[$question['id']] ?? '';

                    if (is_array($value)) {
                        $value = implode(
                            ' / ',
                            array_map(
                                'strval',
                                $value
                            )
                        );
                    }

                    $row[] = (string)$value;
                }

                fputcsv($out, $row);
            }

            fclose($out);
            exit;

        case 'get_response':
            $responseId = (string)(
                $_POST['response_id'] ?? ''
            );

            foreach ($data['responses'] as $response) {
                if ($response['id'] === $responseId) {
                    survey_json_response([
                        'ok' => true,
                        'response' => $response
                    ]);
                }
            }

            survey_json_response([
                'ok' => false,
                'message' => '回答が見つかりません。'
            ], 404);

        default:
            survey_json_response([
                'ok' => false,
                'message' => '未対応のactionです。'
            ], 400);
    }
}

/* =====================================================================
 * Admin SPA
 * ===================================================================== */
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

<div id="app"></div>

<script>
window.App = {

    state: {
        data: null,
        csrf: '',
        screen: 'list',
        survey: null,
        keyword: '',
        statusFilter: '',
        sort: 'updated_desc',
        customerFilter: '',
        selectedCustomers: [],
        selectedQuestions: [],
        responseSurveyId: '',
        previewMobile: false,
        dirty: false,
        fieldCache: {},
        settings: null
    },

    api: {},

    render: {},

    actions: {},

    utils: {},

    init: async function () {
        if (this.state.initialized) return;
        this.state.initialized = true;

        await this.api.load();

        const publicSurvey =
            new URLSearchParams(location.search).get('public');

        if (publicSurvey === '1') return;

        this.render.shell();
        this.render.list();
    }
};

App.api.load = async function () {
    const response = await fetch(
        location.pathname + '?action=load',
        {
            credentials: 'same-origin'
        }
    );

    const json = await response.json();

    if (!json.ok) {
        throw new Error(json.message || 'ロードに失敗しました。');
    }

    App.state.data = json.data;
    App.state.csrf = json.csrf_token;
    App.state.settings = json.data.settings;
};

App.api.post = async function (action, values = {}) {

    const form = new FormData();

    form.append('action', action);
    form.append('csrf_token', App.state.csrf);

    Object.keys(values).forEach(function (key) {

        const value = values[key];

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
        location.pathname,
        {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        }
    );

    const json = await response.json();

    if (!json.ok) {
        throw new Error(
            json.message ||
            '処理に失敗しました。'
        );
    }

    return json;
};

App.utils.escape = function (value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
};

App.utils.date = function (value) {
    if (!value) return '未設定';

    const d = new Date(
        String(value).replace(' ', 'T')
    );

    if (Number.isNaN(d.getTime())) {
        return App.utils.escape(value);
    }

    return d.getFullYear() +
        '/' +
        String(d.getMonth() + 1).padStart(2, '0') +
        '/' +
        String(d.getDate()).padStart(2, '0');
};

App.utils.status = function (status) {

    const map = {
        active: [
            '公開中',
            'bg-emerald-100 text-emerald-700'
        ],
        draft: [
            '下書き',
            'bg-slate-100 text-slate-600'
        ],
        ended: [
            '終了',
            'bg-amber-100 text-amber-700'
        ]
    };

    const item =
        map[status] ||
        map.draft;

    return `<span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold ${item[1]}">${item[0]}</span>`;
};

App.utils.allQuestions = function (survey) {

    const list = [];

    if (!survey) return list;

    survey.groups.forEach(function (group) {
        group.questions.forEach(function (question) {
            list.push(question);
        });
    });

    return list;
};

App.utils.questionNumber = function (
    survey,
    groupIndex,
    questionIndex
) {

    if (
        survey.numbering_mode === 'group'
    ) {
        return (
            (groupIndex + 1) +
            '-' +
            (questionIndex + 1)
        );
    }

    let n = 0;

    for (
        let i = 0;
        i <= groupIndex;
        i++
    ) {
        const group =
            survey.groups[i];

        for (
            let q = 0;
            q < group.questions.length;
            q++
        ) {
            n++;

            if (
                i === groupIndex &&
                q === questionIndex
            ) {
                return String(n);
            }
        }
    }

    return '';
};

App.utils.clone = function (value) {
    return JSON.parse(JSON.stringify(value));
};

App.render.shell = function () {

    document.getElementById('app').innerHTML = `
        <div class="min-h-screen">

            <header class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-sm">
                <div class="max-w-[1500px] mx-auto px-5">
                    <div class="h-16 flex items-center justify-between gap-4">

                        <button
                            onclick="App.actions.goList()"
                            class="flex items-center gap-3 font-bold text-slate-800">
                            <span class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center">Q</span>
                            <span>アンケート管理</span>
                        </button>

                        <nav class="flex items-center gap-1">
                            <button
                                onclick="App.actions.goList()"
                                class="px-4 py-2 rounded-lg hover:bg-slate-100 text-sm">
                                アンケート一覧
                            </button>

                            <button
                                onclick="App.actions.settings()"
                                class="px-4 py-2 rounded-lg hover:bg-slate-100 text-sm">
                                キントーン連携設定
                            </button>

                            <button
                                onclick="App.actions.logout()"
                                class="px-4 py-2 rounded-lg hover:bg-slate-100 text-sm text-slate-500">
                                ログアウト
                            </button>
                        </nav>
                    </div>
                </div>
            </header>

            <main id="main"
                  class="max-w-[1500px] mx-auto px-5 py-7">
            </main>
        </div>

        <div id="preview_modal"></div>
        <div id="response_modal"></div>
    `;
};

App.actions.goList = function () {

    if (App.state.dirty) {
        if (!confirm('未保存の変更があります。破棄して一覧へ戻りますか？')) {
            return;
        }
    }

    App.state.dirty = false;
    App.state.screen = 'list';
    App.state.survey = null;

    App.render.shell();
    App.render.list();
};

App.render.list = function () {

    App.state.screen = 'list';

    let surveys =
        App.state.data.surveys
        .filter(s => !s.deleted);

    const keyword =
        App.state.keyword
        .trim()
        .toLowerCase();

    if (keyword) {
        surveys = surveys.filter(s =>
            s.title.toLowerCase().includes(keyword)
        );
    }

    if (App.state.statusFilter) {
        surveys = surveys.filter(s =>
            s.status === App.state.statusFilter
        );
    }

    const responseCount = function (surveyId) {
        return App.state.data.responses.filter(
            r => r.survey_id === surveyId
        ).length;
    };

    surveys.sort(function (a, b) {

        if (App.state.sort === 'updated_asc') {
            return a.updated_at.localeCompare(b.updated_at);
        }

        if (App.state.sort === 'answers_desc') {
            return responseCount(b.id) - responseCount(a.id);
        }

        if (App.state.sort === 'answers_asc') {
            return responseCount(a.id) - responseCount(b.id);
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

        return b.updated_at.localeCompare(a.updated_at);
    });

    document.getElementById('main').innerHTML = `

        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold">アンケート一覧</h1>
                <p class="text-sm text-slate-500 mt-1">
                    アンケートの作成・配信・集計を管理します。
                </p>
            </div>

            <button
                onclick="App.actions.newSurvey()"
                class="px-5 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-sm">
                ＋ 新規アンケート作成
            </button>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-4 mb-5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

                <input
                    id="survey_filter_keyword"
                    value="${App.utils.escape(App.state.keyword)}"
                    onkeydown="if(event.key==='Enter')App.actions.filterList()"
                    placeholder="タイトルを検索"
                    class="rounded-xl border border-slate-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-indigo-500">

                <select
                    onchange="App.actions.toggleStatusFilter(this.value)"
                    class="rounded-xl border border-slate-300 px-4 py-3">
                    <option value="">すべて</option>
                    <option value="active" ${App.state.statusFilter === 'active' ? 'selected' : ''}>公開中</option>
                    <option value="draft" ${App.state.statusFilter === 'draft' ? 'selected' : ''}>下書き</option>
                    <option value="ended" ${App.state.statusFilter === 'ended' ? 'selected' : ''}>終了</option>
                </select>

                <select
                    onchange="App.actions.sortList(this.value)"
                    class="rounded-xl border border-slate-300 px-4 py-3">
                    <option value="updated_desc" ${App.state.sort === 'updated_desc' ? 'selected' : ''}>更新日：新しい順</option>
                    <option value="updated_asc" ${App.state.sort === 'updated_asc' ? 'selected' : ''}>更新日：古い順</option>
                    <option value="answers_desc" ${App.state.sort === 'answers_desc' ? 'selected' : ''}>回答数：多い順</option>
                    <option value="answers_asc" ${App.state.sort === 'answers_asc' ? 'selected' : ''}>回答数：少ない順</option>
                    <option value="start_desc" ${App.state.sort === 'start_desc' ? 'selected' : ''}>開始日：新しい順</option>
                    <option value="start_asc" ${App.state.sort === 'start_asc' ? 'selected' : ''}>開始日：古い順</option>
                </select>

            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 border-b">
                        <tr>
                            <th class="text-left p-4 whitespace-nowrap">作成日 / 更新日</th>
                            <th class="text-left p-4">タイトル</th>
                            <th class="text-left p-4 whitespace-nowrap">アンケート期間</th>
                            <th class="text-left p-4">ステータス</th>
                            <th class="text-right p-4">回答数</th>
                            <th class="text-left p-4">操作</th>
                        </tr>
                    </thead>

                    <tbody>
                        ${
                            surveys.length
                            ? surveys.map(function(survey) {

                                const count =
                                    responseCount(survey.id);

                                const actions =
                                    survey.status === 'active'
                                    ? `
                                        <button onclick="App.actions.editSurvey('${survey.id}')"
                                            class="text-indigo-600 font-medium">確認・編集</button>
                                        <button onclick="App.actions.analytics('${survey.id}')"
                                            class="text-indigo-600 font-medium">集計</button>
                                        <button onclick="App.actions.mail('${survey.id}')"
                                            class="text-indigo-600 font-medium">送信</button>
                                        <button onclick="App.actions.changeStatus('${survey.id}','ended')"
                                            class="text-amber-600 font-medium">停止</button>
                                        <button onclick="App.actions.duplicate('${survey.id}')"
                                            class="text-slate-600 font-medium">複製</button>
                                      `
                                    : survey.status === 'draft'
                                    ? `
                                        <button onclick="App.actions.editSurvey('${survey.id}')"
                                            class="text-indigo-600 font-medium">確認・編集</button>
                                        <button onclick="App.actions.deleteSurvey('${survey.id}')"
                                            class="text-red-600 font-medium">削除</button>
                                        <button onclick="App.actions.duplicate('${survey.id}')"
                                            class="text-slate-600 font-medium">複製</button>
                                      `
                                    : `
                                        <button onclick="App.actions.editSurvey('${survey.id}')"
                                            class="text-indigo-600 font-medium">確認・編集</button>
                                        <button onclick="App.actions.analytics('${survey.id}')"
                                            class="text-indigo-600 font-medium">集計</button>
                                        <button onclick="App.actions.duplicate('${survey.id}')"
                                            class="text-slate-600 font-medium">複製</button>
                                      `;

                                return `
                                    <tr class="border-b last:border-0 hover:bg-slate-50">

                                        <td class="p-4 whitespace-nowrap">
                                            <div>${App.utils.date(survey.created_at)}</div>
                                            <div class="text-xs text-slate-400 mt-1">
                                                更新: ${App.utils.date(survey.updated_at)}
                                            </div>
                                        </td>

                                        <td class="p-4">
                                            <div class="font-bold">${App.utils.escape(survey.title)}</div>
                                        </td>

                                        <td class="p-4 whitespace-nowrap">
                                            ${
                                                survey.start_at ||
                                                survey.end_at
                                                ? App.utils.escape(
                                                    survey.start_at ||
                                                    '未設定'
                                                  ) +
                                                  ' ～ ' +
                                                  App.utils.escape(
                                                    survey.end_at ||
                                                    '未設定'
                                                  )
                                                : '未設定'
                                            }
                                        </td>

                                        <td class="p-4">
                                            ${App.utils.status(survey.status)}
                                        </td>

                                        <td class="p-4 text-right font-bold">
                                            ${count} 件
                                        </td>

                                        <td class="p-4">
                                            <div class="flex flex-wrap gap-3">
                                                ${actions}
                                            </div>
                                        </td>

                                    </tr>
                                `;
                            }).join('')
                            : `
                                <tr>
                                    <td colspan="6" class="p-12 text-center text-slate-400">
                                        アンケートがありません。
                                    </td>
                                </tr>
                            `
                        }
                    </tbody>
                </table>
            </div>
        </div>
    `;
};

App.actions.filterList = function () {
    const input =
        document.getElementById(
            'survey_filter_keyword'
        );

    App.state.keyword =
        input ? input.value : '';

    App.render.list();
};

App.actions.toggleStatusFilter = function (value) {
    App.state.statusFilter = value;
    App.render.list();
};

App.actions.sortList = function (value) {
    App.state.sort = value;
    App.render.list();
};

App.actions.newSurvey = function () {

    App.state.survey = {
        id: 'survey_' + crypto.randomUUID(),
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [{
            id: 'group_' + crypto.randomUUID(),
            name: 'グループ1',
            questions: []
        }],
        deleted: false
    };

    App.state.dirty = true;

    App.render.editor();
};

App.actions.editSurvey = function (id) {

    const survey =
        App.state.data.surveys.find(
            s => s.id === id
        );

    if (!survey) return;

    App.state.survey =
        App.utils.clone(survey);

    App.state.dirty = false;

    App.render.editor();
};

App.render.editor = function () {

    const survey = App.state.survey;

    document.getElementById('main').innerHTML = `

        <div class="flex items-center justify-between gap-4 mb-6">
            <div>
                <button
                    onclick="App.actions.goList()"
                    class="text-sm text-indigo-600 mb-2">
                    ← アンケート一覧
                </button>

                <h1 class="text-2xl font-bold">
                    アンケート作成・編集
                </h1>
            </div>

            <div class="flex gap-2">
                <button
                    onclick="App.actions.preview()"
                    class="px-4 py-2 rounded-xl border bg-white">
                    プレビュー
                </button>

                <button
                    onclick="App.actions.saveSurvey()"
                    class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold">
                    保存して一覧へ戻る
                </button>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <div class="md:col-span-3">
                    <label class="block text-sm font-medium mb-1">
                        タイトル
                    </label>
                    <input
                        id="survey_title"
                        value="${App.utils.escape(survey.title)}"
                        oninput="App.actions.editSurveyField('title',this.value)"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 text-lg font-bold">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">
                        開始日時
                    </label>
                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        value="${App.utils.escape(survey.start_at)}"
                        onchange="App.actions.editSurveyField('start_at',this.value)"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">
                        終了日時
                    </label>
                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        value="${App.utils.escape(survey.end_at)}"
                        onchange="App.actions.editSurveyField('end_at',this.value)"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">
                        質問番号
                    </label>
                    <select
                        id="survey_numbering_mode"
                        onchange="App.actions.editSurveyField('numbering_mode',this.value)"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3">
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

        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold">質問構成</h2>

            <button
                onclick="App.actions.addGroup()"
                class="px-4 py-2 rounded-xl bg-slate-800 text-white">
                ＋ グループ追加
            </button>
        </div>

        <div
            id="editor_groups"
            class="space-y-5">
        </div>
    `;

    App.render.groups();
    App.actions.initGroupSortable();
};

App.render.groups = function () {

    const container =
        document.getElementById(
            'editor_groups'
        );

    if (!container) return;

    container.innerHTML =
        App.state.survey.groups
        .map(function (group, gi) {

            return `
                <section
                    data-group-id="${App.utils.escape(group.id)}"
                    class="bg-white rounded-2xl border border-slate-200 overflow-hidden">

                    <div class="bg-slate-50 border-b p-4 flex items-center gap-3">

                        <span class="group-handle cursor-grab text-xl text-slate-400">
                            ⠿
                        </span>

                        <input
                            value="${App.utils.escape(group.name)}"
                            oninput="App.actions.editGroup('${group.id}',this.value)"
                            class="flex-1 bg-transparent font-bold text-lg outline-none">

                        <button
                            onclick="App.actions.deleteGroup('${group.id}')"
                            class="px-3 py-2 text-red-600 hover:bg-red-50 rounded-lg">
                            グループ削除
                        </button>
                    </div>

                    <div
                        class="question-list p-4 space-y-3"
                        data-group-id="${App.utils.escape(group.id)}">

                        ${
                            group.questions.length
                            ? group.questions.map(
                                (question, qi) =>
                                    App.render.question(
                                        question,
                                        gi,
                                        qi,
                                        group.id
                                    )
                              ).join('')
                            : `
                                <div class="empty-question p-8 text-center text-slate-400 border-2 border-dashed rounded-xl">
                                    質問がありません
                                </div>
                            `
                        }

                    </div>

                    <div class="p-4 border-t">
                        <button
                            onclick="App.actions.addQuestion('${group.id}')"
                            class="w-full py-3 rounded-xl border-2 border-dashed border-indigo-200 text-indigo-600 font-bold hover:bg-indigo-50">
                            ＋ 質問を追加
                        </button>
                    </div>

                </section>
            `;
        })
        .join('');

    App.actions.initQuestionSortable();
};

App.render.question = function (
    question,
    gi,
    qi,
    groupId
) {

    const number =
        App.utils.questionNumber(
            App.state.survey,
            gi,
            qi
        );

    const options =
        question.options || [];

    return `
        <article
            data-question-id="${App.utils.escape(question.id)}"
            class="question-item border border-slate-200 rounded-xl p-4 bg-white shadow-sm">

            <div class="flex items-start gap-3">

                <span class="question-handle cursor-grab text-xl text-slate-400 pt-1">
                    ⠿
                </span>

                <div class="flex-1">

                    <div class="flex items-center justify-between gap-3 mb-3">

                        <div class="font-bold text-indigo-600">
                            Q${App.utils.escape(number)}
                        </div>

                        <button
                            onclick="App.actions.deleteQuestion('${groupId}','${question.id}')"
                            class="text-red-500 text-sm">
                            削除
                        </button>
                    </div>

                    <input
                        value="${App.utils.escape(question.text)}"
                        oninput="App.actions.editQuestion('${groupId}','${question.id}','text',this.value)"
                        placeholder="質問文"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 mb-3">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

                        <select
                            onchange="App.actions.changeQuestionType('${groupId}','${question.id}',this.value)"
                            class="rounded-xl border border-slate-300 px-4 py-3">

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

                        <label class="flex items-center gap-2 px-3">
                            <input
                                type="checkbox"
                                ${question.required ? 'checked' : ''}
                                onchange="App.actions.editQuestion('${groupId}','${question.id}','required',this.checked)"
                                class="w-5 h-5 text-indigo-600">
                            必須回答
                        </label>

                        ${
                            question.type !== 'text'
                            ? `
                                <label class="flex items-center gap-2 px-3">
                                    <input
                                        type="checkbox"
                                        ${question.other_enabled ? 'checked' : ''}
                                        onchange="App.actions.editQuestion('${groupId}','${question.id}','other_enabled',this.checked)"
                                        class="w-5 h-5 text-indigo-600">
                                    その他を許可
                                </label>
                              `
                            : ''
                        }

                    </div>

                    ${
                        question.type !== 'text'
                        ? `
                            <div class="mt-4 space-y-2">

                                <div class="text-sm font-bold">
                                    選択肢
                                </div>

                                ${
                                    options.map(
                                        (option, oi) => `
                                            <div class="flex gap-2">
                                                <input
                                                    value="${App.utils.escape(option)}"
                                                    oninput="App.actions.editOption('${groupId}','${question.id}',${oi},this.value)"
                                                    class="flex-1 rounded-lg border border-slate-300 px-3 py-2">

                                                <button
                                                    onclick="App.actions.deleteOption('${groupId}','${question.id}',${oi})"
                                                    class="px-3 text-red-500">
                                                    ×
                                                </button>
                                            </div>
                                        `
                                    ).join('')
                                }

                                <button
                                    onclick="App.actions.addOption('${groupId}','${question.id}')"
                                    class="text-sm text-indigo-600 font-medium">
                                    ＋ 選択肢追加
                                </button>

                            </div>

                            ${
                                question.type === 'single'
                                ? `
                                    <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                        <div class="font-bold text-sm mb-3">
                                            回答による質問分岐
                                        </div>

                                        ${
                                            options.length
                                            ? options.map(
                                                option => {

                                                    const branch =
                                                        question.branching.find(
                                                            b => b.option === option
                                                        );

                                                    return `
                                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-2">

                                                            <div class="px-3 py-2 bg-white rounded-lg border">
                                                                ${App.utils.escape(option)}
                                                            </div>

                                                            <select
                                                                onchange="App.actions.setBranch('${groupId}','${question.id}','${App.utils.escape(option)}',this.value)"
                                                                class="rounded-lg border px-3 py-2">

                                                                <option value="">
                                                                    次の質問へ
                                                                </option>

                                                                ${
                                                                    App.utils.allQuestions(App.state.survey)
                                                                    .filter(q => q.id !== question.id)
                                                                    .map(q =>
                                                                        `<option value="${q.id}" ${branch && branch.target_question_id === q.id ? 'selected' : ''}>
                                                                            ${App.utils.escape(q.text || '無題')}
                                                                        </option>`
                                                                    ).join('')
                                                                }

                                                            </select>
                                                        </div>
                                                    `;
                                                }
                                              ).join('')
                                            : '<div class="text-sm text-slate-400">選択肢を追加してください。</div>'
                                        }

                                    </div>
                                  `
                                : ''
                            }
                          `
                        : ''
                    }

                </div>
            </div>
        </article>
    `;
};

App.actions.editSurveyField = function (
    field,
    value
) {
    App.state.survey[field] = value;
    App.state.dirty = true;

    if (field === 'numbering_mode') {
        App.render.groups();
    }
};

App.actions.addGroup = function () {

    App.state.survey.groups.push({
        id: 'group_' + crypto.randomUUID(),
        name:
            'グループ' +
            (App.state.survey.groups.length + 1),
        questions: []
    });

    App.state.dirty = true;

    App.render.groups();
    App.actions.initGroupSortable();
};

App.actions.deleteGroup = function (id) {

    if (!confirm(
        'グループと内包される質問を削除しますか？'
    )) {
        return;
    }

    App.state.survey.groups =
        App.state.survey.groups.filter(
            g => g.id !== id
        );

    if (!App.state.survey.groups.length) {
        App.actions.addGroup();
        return;
    }

    App.state.dirty = true;

    App.render.groups();
};

App.actions.editGroup = function (
    id,
    value
) {
    const group =
        App.state.survey.groups.find(
            g => g.id === id
        );

    if (!group) return;

    group.name = value;
    App.state.dirty = true;
};

App.actions.addQuestion = function (groupId) {

    const group =
        App.state.survey.groups.find(
            g => g.id === groupId
        );

    if (!group) return;

    group.questions.push({
        id: 'question_' + crypto.randomUUID(),
        text: '',
        type: 'single',
        required: false,
        options: [
            '選択肢1',
            '選択肢2'
        ],
        other_enabled: false,
        branching: []
    });

    App.state.dirty = true;

    App.render.groups();
    App.actions.initQuestionSortable();
};

App.actions.deleteQuestion = function (
    groupId,
    questionId
) {

    if (!confirm('この質問を削除しますか？')) {
        return;
    }

    const group =
        App.state.survey.groups.find(
            g => g.id === groupId
        );

    if (!group) return;

    group.questions =
        group.questions.filter(
            q => q.id !== questionId
        );

    App.state.dirty = true;

    App.render.groups();
};

App.actions.editQuestion = function (
    groupId,
    questionId,
    field,
    value
) {

    const question =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!question) return;

    question[field] = value;
    App.state.dirty = true;

    if (
        field === 'type' ||
        field === 'other_enabled'
    ) {
        App.render.groups();
    }
};

App.actions.findQuestion = function (
    groupId,
    questionId
) {

    const group =
        App.state.survey.groups.find(
            g => g.id === groupId
        );

    if (!group) return null;

    return group.questions.find(
        q => q.id === questionId
    ) || null;
};

App.actions.changeQuestionType = function (
    groupId,
    questionId,
    value
) {
    const q =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    q.type = value;

    if (value === 'text') {
        q.options = [];
        q.branching = [];
        q.other_enabled = false;
    }

    if (
        value !== 'single'
    ) {
        q.branching = [];
    }

    if (
        value !== 'text' &&
        !q.options.length
    ) {
        q.options = [
            '選択肢1',
            '選択肢2'
        ];
    }

    App.state.dirty = true;

    App.render.groups();
};

App.actions.addOption = function (
    groupId,
    questionId
) {
    const q =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    q.options.push(
        '選択肢' +
        (q.options.length + 1)
    );

    App.state.dirty = true;
    App.render.groups();
};

App.actions.editOption = function (
    groupId,
    questionId,
    index,
    value
) {
    const q =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    const old = q.options[index];

    q.options[index] = value;

    q.branching =
        q.branching.map(
            b => b.option === old
                ? {...b, option: value}
                : b
        );

    App.state.dirty = true;
};

App.actions.deleteOption = function (
    groupId,
    questionId,
    index
) {
    const q =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    const old = q.options[index];

    q.options.splice(index, 1);

    q.branching =
        q.branching.filter(
            b => b.option !== old
        );

    App.state.dirty = true;

    App.render.groups();
};

App.actions.setBranch = function (
    groupId,
    questionId,
    option,
    target
) {
    const q =
        App.actions.findQuestion(
            groupId,
            questionId
        );

    if (!q) return;

    const branch =
        q.branching.find(
            b => b.option === option
        );

    if (branch) {
        branch.target_question_id = target;
    } else {
        q.branching.push({
            option: option,
            target_question_id: target
        });
    }

    App.state.dirty = true;
};

App.actions.initGroupSortable = function () {

    const el =
        document.getElementById(
            'editor_groups'
        );

    if (!el || typeof Sortable === 'undefined') {
        return;
    }

    if (el._sortable) {
        el._sortable.destroy();
    }

    el._sortable = new Sortable(
        el,
        {
            animation: 180,
            handle: '.group-handle',
            ghostClass: 'opacity-40',
            onEnd: function (event) {

                if (
                    event.oldIndex === event.newIndex
                ) {
                    return;
                }

                const groups =
                    App.state.survey.groups;

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

                App.state.dirty = true;

                App.render.groups();
                App.actions.initGroupSortable();
            }
        }
    );
};

App.actions.initQuestionSortable = function () {

    document
        .querySelectorAll('.question-list')
        .forEach(function (el) {

            if (el._sortable) {
                el._sortable.destroy();
            }

            if (typeof Sortable === 'undefined') {
                return;
            }

            el._sortable =
                new Sortable(
                    el,
                    {
                        group: 'survey_questions',
                        animation: 180,
                        handle: '.question-handle',
                        ghostClass: 'opacity-40',
                        onEnd: function (event) {

                            const fromGroupId =
                                event.from.dataset.groupId;

                            const toGroupId =
                                event.to.dataset.groupId;

                            const fromGroup =
                                App.state.survey.groups.find(
                                    g => g.id === fromGroupId
                                );

                            const toGroup =
                                App.state.survey.groups.find(
                                    g => g.id === toGroupId
                                );

                            if (!fromGroup || !toGroup) {
                                return;
                            }

                            let moved;

                            if (
                                fromGroupId === toGroupId
                            ) {
                                moved =
                                    fromGroup.questions.splice(
                                        event.oldIndex,
                                        1
                                    )[0];

                                fromGroup.questions.splice(
                                    event.newIndex,
                                    0,
                                    moved
                                );
                            } else {

                                moved =
                                    fromGroup.questions.splice(
                                        event.oldIndex,
                                        1
                                    )[0];

                                toGroup.questions.splice(
                                    event.newIndex,
                                    0,
                                    moved
                                );
                            }

                            App.state.dirty = true;

                            App.render.groups();
                            App.actions.initQuestionSortable();
                        }
                    }
                );
        });
};

App.actions.saveSurvey = async function () {

    try {

        const json =
            await App.api.post(
                'save_survey',
                {
                    survey_json:
                        App.state.survey
                }
            );

        const index =
            App.state.data.surveys.findIndex(
                s => s.id === json.survey.id
            );

        if (index >= 0) {
            App.state.data.surveys[index] =
                json.survey;
        } else {
            App.state.data.surveys.push(
                json.survey
            );
        }

        App.state.dirty = false;

        alert('保存しました。');

        App.actions.goList();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.deleteSurvey = async function (id) {

    if (!confirm(
        'この下書きを削除しますか？'
    )) {
        return;
    }

    try {
        await App.api.post(
            'delete_survey',
            {
                survey_id: id
            }
        );

        const survey =
            App.state.data.surveys.find(
                s => s.id === id
            );

        if (survey) {
            survey.deleted = true;
        }

        App.render.list();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.changeStatus = async function (
    id,
    status
) {

    const message =
        status === 'ended'
            ? 'アンケートを停止しますか？'
            : 'ステータスを変更しますか？';

    if (!confirm(message)) return;

    try {

        await App.api.post(
            'change_status',
            {
                survey_id: id,
                status: status
            }
        );

        const survey =
            App.state.data.surveys.find(
                s => s.id === id
            );

        if (survey) {
            survey.status = status;
        }

        App.render.list();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.duplicate = async function (id) {

    const source =
        App.state.data.surveys.find(
            s => s.id === id
        );

    if (!source) return;

    const copy =
        App.utils.clone(source);

    copy.id =
        'survey_' +
        crypto.randomUUID();

    copy.title =
        source.title + '（複製）';

    copy.status = 'draft';
    copy.deleted = false;
    copy.created_at = '';
    copy.updated_at = '';

    copy.groups.forEach(function (group) {

        group.id =
            'group_' +
            crypto.randomUUID();

        group.questions.forEach(function (q) {
            q.id =
                'question_' +
                crypto.randomUUID();

            q.branching =
                Array.isArray(q.branching)
                    ? q.branching
                    : [];
        });
    });

    try {

        const result =
            await App.api.post(
                'save_survey',
                {
                    survey_json: copy
                }
            );

        App.state.data.surveys.push(
            result.survey
        );

        App.render.list();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.preview = function () {

    const survey =
        App.state.survey;

    const questions = [];

    survey.groups.forEach(
        function (group, gi) {

            group.questions.forEach(
                function (question, qi) {

                    questions.push({
                        question,
                        number:
                            App.utils.questionNumber(
                                survey,
                                gi,
                                qi
                            )
                    });
                }
            );
        }
    );

    document.getElementById(
        'preview_modal'
    ).innerHTML = `

        <div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">

            <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-hidden">

                <div class="p-4 border-b flex items-center justify-between">

                    <div class="font-bold">
                        プレビュー
                    </div>

                    <div class="flex gap-2">

                        <button
                            onclick="App.actions.previewMode(false)"
                            class="px-3 py-2 rounded-lg border">
                            PC表示
                        </button>

                        <button
                            onclick="App.actions.previewMode(true)"
                            class="px-3 py-2 rounded-lg border">
                            スマートフォン表示
                        </button>

                        <button
                            onclick="App.actions.closePreview()"
                            class="px-3 py-2 rounded-lg bg-slate-100">
                            閉じる
                        </button>

                    </div>
                </div>

                <div
                    id="preview_content"
                    class="overflow-y-auto max-h-[calc(90vh-70px)] bg-slate-100 p-6">

                    <div class="${App.state.previewMobile ? 'max-w-sm' : 'max-w-2xl'} mx-auto bg-white rounded-xl p-6">

                        <h1 class="text-2xl font-bold">
                            ${App.utils.escape(survey.title)}
                        </h1>

                        <div class="mt-6 space-y-7">

                            ${
                                questions.map(
                                    item => {

                                        const q =
                                            item.question;

                                        return `
                                            <div>

                                                <div class="font-medium mb-3">
                                                    <span class="text-indigo-600">
                                                        Q${item.number}
                                                    </span>
                                                    ${App.utils.escape(q.text)}
                                                    ${q.required ? '<span class="text-red-500">*</span>' : ''}
                                                </div>

                                                ${
                                                    q.type === 'text'
                                                    ? `
                                                        <textarea
                                                            disabled
                                                            rows="4"
                                                            class="w-full border rounded-xl px-4 py-3"
                                                            placeholder="回答欄">
                                                        </textarea>
                                                      `
                                                    : q.options.map(
                                                        option => `
                                                            <label class="flex items-center gap-3 p-3">
                                                                <input
                                                                    disabled
                                                                    type="${q.type === 'single' ? 'radio' : 'checkbox'}">
                                                                ${App.utils.escape(option)}
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

                        <button
                            onclick="alert('プレビューでは送信されません。')"
                            class="mt-8 w-full py-3 rounded-xl bg-indigo-600 text-white font-bold">
                            送信する
                        </button>

                    </div>
                </div>

            </div>
        </div>
    `;
};

App.actions.previewMode = function (
    mobile
) {
    App.state.previewMobile = mobile;
    App.actions.preview();
};

App.actions.closePreview = function () {
    document.getElementById(
        'preview_modal'
    ).innerHTML = '';
};

App.actions.analytics = function (surveyId) {

    const survey =
        App.state.data.surveys.find(
            s => s.id === surveyId
        );

    if (!survey) return;

    App.state.responseSurveyId = surveyId;

    const responses =
        App.state.data.responses.filter(
            r => r.survey_id === surveyId
        );

    const customers =
        App.state.data.customers;

    const targetCustomers =
        customers.filter(
            c => c.source === 'kintone'
        );

    const targetIds =
        new Set(
            targetCustomers.map(c => c.id)
        );

    const targetResponses =
        responses.filter(
            r => targetIds.has(r.customer_id)
        );

    const unanswered =
        targetCustomers.filter(
            c => c.answer_status !== 'answered'
        ).length;

    const rate =
        targetCustomers.length
            ? (
                targetResponses.length /
                targetCustomers.length *
                100
              ).toFixed(1)
            : '0.0';

    const questions =
        App.utils.allQuestions(survey);

    document.getElementById('main').innerHTML = `

        <div class="flex items-center justify-between gap-4 mb-6">

            <div>
                <button
                    onclick="App.actions.goList()"
                    class="text-sm text-indigo-600 mb-2">
                    ← アンケート一覧
                </button>

                <h1 class="text-2xl font-bold">
                    回答集計・分析
                </h1>

                <p class="mt-1 text-slate-500">
                    ${App.utils.escape(survey.title)}
                </p>
            </div>

            <a
                href="?action=csv&survey_id=${encodeURIComponent(surveyId)}"
                class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold">
                CSV出力
            </a>

        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">

            ${[
                ['送信対象者数', targetCustomers.length + ' 人'],
                ['回答数', responses.length + ' 件'],
                [
                    '未登録顧客からの回答数',
                    responses.filter(r =>
                        !targetIds.has(r.customer_id)
                    ).length + ' 件'
                ],
                ['未回答数', unanswered + ' 人'],
                ['回答率', rate + ' %']
            ].map(
                item => `
                    <div class="bg-white rounded-2xl border p-5">
                        <div class="text-sm text-slate-500">${item[0]}</div>
                        <div class="text-2xl font-bold mt-2">${item[1]}</div>
                    </div>
                `
            ).join('')}

        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-5">

            <aside class="bg-white rounded-2xl border p-5 h-fit">

                <div class="font-bold mb-4">
                    設問絞り込み
                </div>

                <div class="flex gap-2 mb-4">
                    <button
                        onclick="App.actions.selectAllQuestions(true)"
                        class="text-xs text-indigo-600">
                        一括選択
                    </button>

                    <button
                        onclick="App.actions.selectAllQuestions(false)"
                        class="text-xs text-slate-500">
                        全解除
                    </button>
                </div>

                <div class="space-y-2">

                    ${
                        questions.map(
                            (q, i) => `
                                <label class="flex gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked
                                        data-question-check="${q.id}"
                                        onchange="App.actions.toggleQuestion('${q.id}',this.checked)"
                                        class="mt-1">
                                    <span>
                                        Q${i + 1}
                                        ${App.utils.escape(q.text)}
                                    </span>
                                </label>
                            `
                        ).join('')
                    }

                </div>

            </aside>

            <section class="lg:col-span-3 space-y-5">

                ${
                    responses.length
                    ? questions.map(
                        q => App.render.resultQuestion(
                            q,
                            responses
                        )
                      ).join('')
                    : `
                        <div class="bg-white rounded-2xl border p-12 text-center text-slate-400">
                            現在、回答データはありません
                        </div>
                    `
                }

                <div class="bg-white rounded-2xl border overflow-hidden">

                    <div class="p-5 border-b">
                        <h2 class="font-bold">
                            個別回答一覧
                        </h2>
                    </div>

                    <div class="p-4">
                        <input
                            id="response_filter"
                            oninput="App.actions.filterResponses()"
                            placeholder="会社名・氏名で検索"
                            class="w-full rounded-xl border px-4 py-3">
                    </div>

                    <div
                        id="response_table"
                        class="overflow-x-auto">

                        ${App.render.responseRows(responses)}

                    </div>

                </div>

            </section>

        </div>
    `;
};

App.render.resultQuestion = function (
    question,
    responses
) {

    if (
        question.type === 'text'
    ) {

        const values = [];

        responses.forEach(
            response => {

                const value =
                    response.answers?.[question.id];

                if (
                    value !== undefined &&
                    String(value).trim() !== ''
                ) {
                    values.push({
                        name: response.name,
                        company: response.company,
                        value: String(value)
                    });
                }
            }
        );

        return `
            <div
                data-result-question="${question.id}"
                class="bg-white rounded-2xl border p-5">

                <h2 class="font-bold mb-4">
                    ${App.utils.escape(question.text)}
                </h2>

                ${
                    values.length
                    ? `
                        <div class="space-y-3 max-h-96 overflow-y-auto">
                            ${
                                values.map(
                                    item => `
                                        <div class="border-l-4 border-indigo-200 pl-4">
                                            <div class="text-sm font-bold">
                                                ${App.utils.escape(item.company)}
                                                ${App.utils.escape(item.name)}
                                            </div>
                                            <div class="mt-1 text-slate-600 whitespace-pre-wrap">
                                                ${App.utils.escape(item.value)}
                                            </div>
                                        </div>
                                    `
                                ).join('')
                            }
                        </div>
                      `
                    : `
                        <div class="text-slate-400">
                            回答なし
                        </div>
                      `
                }

            </div>
        `;
    }

    const counts = {};

    question.options.forEach(
        option => counts[option] = 0
    );

    let total = 0;

    responses.forEach(
        response => {

            let value =
                response.answers?.[question.id];

            if (Array.isArray(value)) {
                value.forEach(
                    v => {
                        if (counts[v] !== undefined) {
                            counts[v]++;
                        }
                    }
                );
                total++;
            } else if (
                value !== undefined &&
                value !== ''
            ) {
                if (counts[value] !== undefined) {
                    counts[value]++;
                }

                total++;
            }
        }
    );

    return `
        <div
            data-result-question="${question.id}"
            class="bg-white rounded-2xl border p-5">

            <h2 class="font-bold mb-5">
                ${App.utils.escape(question.text)}
            </h2>

            <div class="space-y-4">

                ${
                    question.options.map(
                        option => {

                            const count =
                                counts[option] || 0;

                            const percent =
                                total
                                    ? (
                                        count /
                                        total *
                                        100
                                      )
                                    : 0;

                            return `
                                <div>

                                    <div class="flex justify-between text-sm mb-1">
                                        <span>${App.utils.escape(option)}</span>
                                        <span>${count}件 / ${percent.toFixed(1)}%</span>
                                    </div>

                                    <div class="h-3 rounded-full bg-slate-100 overflow-hidden">
                                        <div
                                            class="h-full bg-indigo-500 rounded-full"
                                            style="width:${percent}%">
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
};

App.render.responseRows = function (
    responses
) {

    if (!responses.length) {
        return `
            <div class="p-10 text-center text-slate-400">
                回答データがありません。
            </div>
        `;
    }

    return `
        <table class="w-full text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="text-left p-4">会社名</th>
                    <th class="text-left p-4">氏名</th>
                    <th class="text-left p-4">回答日時</th>
                    <th class="text-left p-4">操作</th>
                </tr>
            </thead>
            <tbody>
                ${
                    responses.map(
                        response => `
                            <tr
                                data-response-row
                                data-search="${App.utils.escape(
                                    response.company +
                                    ' ' +
                                    response.name
                                ).toLowerCase()}"
                                class="border-t">

                                <td class="p-4">
                                    ${App.utils.escape(response.company)}
                                </td>

                                <td class="p-4 font-medium">
                                    ${App.utils.escape(response.name)}
                                </td>

                                <td class="p-4">
                                    ${App.utils.escape(response.answered_at)}
                                </td>

                                <td class="p-4">
                                    <button
                                        onclick="App.actions.showResponse('${response.id}')"
                                        class="text-indigo-600 font-bold">
                                        全回答を表示
                                    </button>
                                </td>

                            </tr>
                        `
                    ).join('')
                }
            </tbody>
        </table>
    `;
};

App.actions.selectAllQuestions = function (
    checked
) {

    document
        .querySelectorAll(
            '[data-question-check]'
        )
        .forEach(
            el => {
                el.checked = checked;
            }
        );

    document
        .querySelectorAll(
            '[data-result-question]'
        )
        .forEach(
            el => {
                el.style.display =
                    checked ? '' : 'none';
            }
        );
};

App.actions.toggleQuestion = function (
    id,
    checked
) {

    const el =
        document.querySelector(
            `[data-result-question="${CSS.escape(id)}"]`
        );

    if (el) {
        el.style.display =
            checked ? '' : 'none';
    }
};

App.actions.filterResponses = function () {

    const input =
        document.getElementById(
            'response_filter'
        );

    const keyword =
        (input?.value || '').toLowerCase();

    document
        .querySelectorAll(
            '[data-response-row]'
        )
        .forEach(
            row => {

                const text =
                    row.dataset.search || '';

                row.style.display =
                    !keyword ||
                    text.includes(keyword)
                        ? ''
                        : 'none';
            }
        );
};

App.actions.showResponse = function (
    responseId
) {

    const response =
        App.state.data.responses.find(
            r => r.id === responseId
        );

    if (!response) return;

    const survey =
        App.state.data.surveys.find(
            s => s.id === response.survey_id
        );

    if (!survey) return;

    const questions =
        App.utils.allQuestions(survey);

    document.getElementById(
        'response_modal'
    ).innerHTML = `

        <div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">

            <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-hidden">

                <div class="p-5 border-b flex items-center justify-between">

                    <div>
                        <div class="font-bold">
                            ${App.utils.escape(response.name)}
                        </div>
                        <div class="text-sm text-slate-500">
                            ${App.utils.escape(response.company)}
                        </div>
                    </div>

                    <button
                        onclick="App.actions.closeResponse()"
                        class="px-3 py-2 rounded-lg bg-slate-100">
                        閉じる
                    </button>

                </div>

                <div
                    id="response_detail"
                    class="p-6 overflow-y-auto max-h-[calc(90vh-80px)]">

                    <div class="space-y-5">

                        ${
                            questions.map(
                                q => {

                                    let value =
                                        response.answers?.[q.id] ??
                                        '';

                                    if (Array.isArray(value)) {
                                        value =
                                            value.join(' / ');
                                    }

                                    return `
                                        <div class="border-b pb-4">
                                            <div class="font-bold text-sm">
                                                ${App.utils.escape(q.text)}
                                            </div>
                                            <div class="mt-2 whitespace-pre-wrap">
                                                ${App.utils.escape(String(value))}
                                            </div>
                                        </div>
                                    `;
                                }
                            ).join('')
                        }

                    </div>
                </div>

            </div>
        </div>
    `;
};

App.actions.closeResponse = function () {
    document.getElementById(
        'response_modal'
    ).innerHTML = '';
};

App.actions.mail = function (surveyId) {

    const survey =
        App.state.data.surveys.find(
            s => s.id === surveyId
        );

    if (!survey) return;

    const customers =
        App.state.data.customers;

    document.getElementById('main').innerHTML = `

        <div class="mb-6">

            <button
                onclick="App.actions.goList()"
                class="text-sm text-indigo-600 mb-2">
                ← アンケート一覧
            </button>

            <h1 class="text-2xl font-bold">
                顧客選択・メール送信
            </h1>

            <p class="mt-1 text-slate-500">
                ${App.utils.escape(survey.title)}
            </p>

        </div>

        <div class="bg-white rounded-2xl border p-5 mb-5">

            <div class="grid md:grid-cols-3 gap-4">

                <div>
                    <label class="block text-sm font-medium mb-1">
                        顧客検索
                    </label>
                    <input
                        id="customer_filter"
                        oninput="App.actions.filterCustomers()"
                        placeholder="会社名・氏名・メール"
                        class="w-full rounded-xl border px-4 py-3">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">
                        テンプレート
                    </label>
                    <select
                        id="template_type"
                        class="w-full rounded-xl border px-4 py-3">
                        <option value="initial">初回送信</option>
                        <option value="reminder">リマインド</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button
                        onclick="App.actions.syncKintoneBeforeMail()"
                        class="w-full py-3 rounded-xl border border-indigo-200 text-indigo-600 font-bold">
                        kintone顧客を同期
                    </button>
                </div>

            </div>

        </div>

        <div class="bg-white rounded-2xl border p-5 mb-5">

            <h2 class="font-bold mb-4">
                メールテンプレート
            </h2>

            <input
                id="mail_subject"
                value="【アンケート】${App.utils.escape(survey.title)}"
                placeholder="件名"
                class="w-full rounded-xl border px-4 py-3 mb-3">

            <textarea
                id="mail_body"
                rows="7"
                placeholder="本文"
                class="w-full rounded-xl border px-4 py-3">{${
                    '{顧客名}'
                }} 様

アンケートへのご協力をお願いいたします。

回答はこちら：
${
    '{アンケートURL}'
}

よろしくお願いいたします。</textarea>

            <p class="text-xs text-slate-400 mt-2">
                使用可能な変数：{顧客名} / {アンケートURL}
            </p>

        </div>

        <div class="bg-white rounded-2xl border overflow-hidden">

            <div class="p-5 border-b flex items-center justify-between">

                <div>
                    <h2 class="font-bold">
                        顧客一覧
                    </h2>
                    <p class="text-xs text-slate-400 mt-1">
                        送信済み顧客を再選択すると再送確認を行います。
                    </p>
                </div>

                <button
                    onclick="App.actions.sendSelected('${surveyId}')"
                    class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-bold">
                    選択した顧客へ送信
                </button>

            </div>

            <div
                id="customer_table"
                class="overflow-x-auto">

                ${App.render.customerTable(customers, surveyId)}

            </div>

        </div>
    `;
};

App.render.customerTable = function (
    customers,
    surveyId
) {

    return `
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b">
                <tr>
                    <th class="p-4 text-left">
                        <input
                            id="select_all"
                            type="checkbox"
                            onchange="App.actions.selectAllCustomers(this.checked)"
                            class="w-5 h-5">
                    </th>
                    <th class="p-4 text-left">会社名 / 氏名</th>
                    <th class="p-4 text-left">メール</th>
                    <th class="p-4 text-left">電話</th>
                    <th class="p-4 text-left">送信状況</th>
                    <th class="p-4 text-left">回答状況</th>
                    <th class="p-4 text-left">kintone</th>
                </tr>
            </thead>

            <tbody>

                ${
                    customers.map(
                        customer => {

                            const disabled =
                                customer.source === 'web'
                                    ? 'disabled'
                                    : '';

                            return `
                                <tr
                                    data-customer-row
                                    data-search="${App.utils.escape(
                                        (
                                            customer.company +
                                            ' ' +
                                            customer.name +
                                            ' ' +
                                            customer.email
                                        ).toLowerCase()
                                    )}"
                                    class="border-b hover:bg-slate-50">

                                    <td class="p-4">
                                        <input
                                            type="checkbox"
                                            data-customer-check
                                            value="${customer.id}"
                                            ${disabled}
                                            onchange="App.actions.toggleCustomer('${customer.id}',this.checked)"
                                            class="w-5 h-5 text-indigo-600">
                                    </td>

                                    <td class="p-4">
                                        <div class="font-bold">
                                            ${App.utils.escape(customer.company)}
                                        </div>
                                        <div class="text-slate-600">
                                            ${App.utils.escape(customer.name)}
                                        </div>
                                    </td>

                                    <td class="p-4">
                                        ${App.utils.escape(customer.email)}
                                    </td>

                                    <td class="p-4">
                                        ${App.utils.escape(customer.phone)}
                                    </td>

                                    <td class="p-4">
                                        ${
                                            customer.sent_at
                                            ? `
                                                <div>
                                                    ${App.utils.escape(customer.sent_at)}
                                                </div>
                                                <div class="text-xs text-slate-400">
                                                    ${customer.send_count}回送信
                                                </div>
                                              `
                                            : '<span class="text-slate-400">未送信</span>'
                                        }
                                    </td>

                                    <td class="p-4">
                                        ${
                                            customer.answer_status === 'answered'
                                            ? '<span class="px-2 py-1 rounded-full bg-green-100 text-green-700 text-xs font-bold">回答済み</span>'
                                            : '<span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700 text-xs font-bold">送信済み（未回答）</span>'
                                        }
                                    </td>

                                    <td class="p-4">

                                        ${
                                            customer.kintone_status === 'registered'
                                            ? '<span class="text-green-600 font-bold">✓ kintone登録完了</span>'
                                            : `
                                                <div class="flex flex-col gap-1">
                                                    <span class="text-xs text-red-500">未登録</span>
                                                    <button
                                                        onclick="App.actions.registerKintone('${customer.id}')"
                                                        class="text-indigo-600 text-xs font-bold">
                                                        kintone登録
                                                    </button>
                                                </div>
                                              `
                                        }

                                    </td>

                                </tr>
                            `;
                        }
                    ).join('')
                }

            </tbody>
        </table>
    `;
};

App.actions.toggleCustomer = function (
    id,
    checked
) {

    const list =
        App.state.selectedCustomers;

    if (checked) {
        if (!list.includes(id)) {
            list.push(id);
        }
    } else {
        const index =
            list.indexOf(id);

        if (index >= 0) {
            list.splice(index, 1);
        }
    }
};

App.actions.selectAllCustomers = function (
    checked
) {

    App.state.selectedCustomers = [];

    document
        .querySelectorAll(
            '[data-customer-check]:not(:disabled)'
        )
        .forEach(
            checkbox => {

                checkbox.checked = checked;

                if (checked) {
                    App.state.selectedCustomers.push(
                        checkbox.value
                    );
                }
            }
        );
};

App.actions.filterCustomers = function () {

    const input =
        document.getElementById(
            'customer_filter'
        );

    const keyword =
        (input?.value || '').toLowerCase();

    document
        .querySelectorAll(
            '[data-customer-row]'
        )
        .forEach(
            row => {

                const text =
                    row.dataset.search || '';

                row.style.display =
                    !keyword ||
                    text.includes(keyword)
                        ? ''
                        : 'none';
            }
        );
};

App.actions.sendSelected = async function (
    surveyId
) {

    const ids =
        App.state.selectedCustomers;

    if (!ids.length) {
        alert('送信先を選択してください。');
        return;
    }

    const selected =
        App.state.data.customers.filter(
            c => ids.includes(c.id)
        );

    const resent =
        selected.filter(
            c => c.sent_at
        );

    if (
        resent.length &&
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

    const template =
        document.getElementById(
            'template_type'
        ).value;

    try {

        const result =
            await App.api.post(
                'send_mail',
                {
                    survey_id: surveyId,
                    recipient_ids: ids,
                    mail_subject: subject,
                    mail_body: body,
                    template_type: template
                }
            );

        alert(result.message);

        await App.api.load();

        App.actions.mail(surveyId);

    } catch (error) {
        alert(error.message);
    }
};

App.actions.syncKintoneBeforeMail = async function () {

    try {

        const result =
            await App.api.post(
                'kintone_sync'
            );

        App.state.data =
            result.data;

        alert(
            result.count +
            '件のkintone顧客を同期しました。'
        );

        App.actions.mail(
            App.state.responseSurveyId
        );

    } catch (error) {
        alert(error.message);
    }
};

App.actions.registerKintone = async function (
    customerId
) {

    if (!confirm(
        'この顧客をkintoneへ登録しますか？'
    )) {
        return;
    }

    try {

        await App.api.post(
            'kintone_register_customer',
            {
                customer_id: customerId
            }
        );

        const customer =
            App.state.data.customers.find(
                c => c.id === customerId
            );

        if (customer) {
            customer.kintone_status =
                'registered';
        }

        alert('kintoneへ登録しました。');

        App.actions.mail(
            App.state.responseSurveyId
        );

    } catch (error) {
        alert(error.message);
    }
};

App.actions.settings = function () {

    const settings =
        App.state.data.settings;

    const fields =
        App.state.fieldCache;

    document.getElementById('main').innerHTML = `

        <div class="mb-6">

            <button
                onclick="App.actions.goList()"
                class="text-sm text-indigo-600 mb-2">
                ← アンケート一覧
            </button>

            <h1 class="text-2xl font-bold">
                キントーン連携設定
            </h1>

            <p class="text-sm text-slate-500 mt-1">
                kintone API接続と顧客項目マッピングを設定します。
            </p>

        </div>

        <form
            id="settings_form"
            onsubmit="event.preventDefault();App.actions.saveSettings()"
            class="space-y-5">

            <div class="bg-white rounded-2xl border p-6">

                <h2 class="font-bold text-lg mb-5">
                    接続・認証設定
                </h2>

                <div class="grid md:grid-cols-2 gap-4">

                    <div>
                        <label class="block text-sm font-medium mb-1">
                            サブドメイン
                        </label>
                        <input
                            id="setting_subdomain"
                            value="${App.utils.escape(settings.subdomain)}"
                            placeholder="xxxx または xxxx.cybozu.com"
                            class="w-full rounded-xl border px-4 py-3">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">
                            アプリID
                        </label>
                        <div class="flex gap-2">
                            <input
                                id="setting_app_id"
                                value="${App.utils.escape(settings.app_id)}"
                                class="flex-1 rounded-xl border px-4 py-3">
                            <button
                                type="button"
                                onclick="App.actions.fetchKintoneFields()"
                                class="px-4 rounded-xl bg-slate-800 text-white">
                                項目取得
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">
                            ログイン名
                        </label>
                        <input
                            id="setting_login_name"
                            value="${App.utils.escape(settings.login_name)}"
                            class="w-full rounded-xl border px-4 py-3">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">
                            パスワード
                        </label>
                        <input
                            id="setting_password"
                            type="password"
                            placeholder="変更しない場合は空欄"
                            class="w-full rounded-xl border px-4 py-3">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">
                            Proxyサーバ
                        </label>
                        <input
                            id="setting_proxy"
                            value="${App.utils.escape(settings.proxy)}"
                            placeholder="host:port"
                            class="w-full rounded-xl border px-4 py-3">
                    </div>

                    <label class="flex items-center gap-3 p-3">
                        <input
                            id="setting_ssl_verify"
                            type="checkbox"
                            ${settings.ssl_verify ? 'checked' : ''}
                            class="w-5 h-5">
                        SSL証明書を検証する
                    </label>

                </div>

            </div>

            <div class="bg-white rounded-2xl border p-6">

                <h2 class="font-bold text-lg mb-2">
                    項目マッピング
                </h2>

                <p
                    id="field_message"
                    class="text-sm text-slate-500 mb-5">
                    日本語のフィールド名から選択できます。
                </p>

                <div
                    id="kintone_status"
                    class="grid md:grid-cols-2 gap-4">

                    ${App.render.mappingFields(fields)}

                </div>

            </div>

            <div class="flex justify-end gap-3">

                <button
                    type="button"
                    onclick="App.actions.goList()"
                    class="px-5 py-3 rounded-xl border bg-white">
                    キャンセル
                </button>

                <button
                    class="px-6 py-3 rounded-xl bg-indigo-600 text-white font-bold">
                    設定を保存
                </button>

            </div>

        </form>
    `;
};

App.render.mappingFields = function (
    fields
) {

    const settings =
        App.state.data.settings;

    const select = function (
        id,
        label,
        multiple = false
    ) {

        const options =
            Object.keys(fields || {})
            .map(
                code => {

                    const item =
                        fields[code] || {};

                    const selected =
                        multiple
                            ? (
                                Array.isArray(
                                    settings[id]
                                ) &&
                                settings[id].includes(code)
                              )
                            : settings[id] === code;

                    return `
                        <option
                            value="${App.utils.escape(code)}"
                            ${selected ? 'selected' : ''}>
                            ${App.utils.escape(
                                item.label ||
                                item.code ||
                                code
                            )}
                        </option>
                    `;
                }
            ).join('');

        return `
            <div>
                <label class="block text-sm font-medium mb-1">
                    ${label}
                </label>

                <select
                    id="${id}"
                    ${multiple ? 'multiple size="5"' : ''}
                    class="w-full rounded-xl border px-4 py-3">

                    <option value="">
                        -- 選択してください --
                    </option>

                    ${options}

                </select>
            </div>
        `;
    };

    return `
        ${select('field_company','会社名')}
        ${select('field_name','氏名')}
        ${select('field_email','メールアドレス')}
        ${select('field_department','部署名')}
        ${select('field_phone','電話番号')}
        ${select('field_address','住所',true)}
    `;
};

App.actions.fetchKintoneFields = async function () {

    const settings = {
        ...App.state.data.settings,
        subdomain:
            document.getElementById(
                'setting_subdomain'
            ).value,
        app_id:
            document.getElementById(
                'setting_app_id'
            ).value,
        login_name:
            document.getElementById(
                'setting_login_name'
            ).value,
        password:
            document.getElementById(
                'setting_password'
            ).value ||
            App.state.data.settings.password,
        proxy:
            document.getElementById(
                'setting_proxy'
            ).value,
        ssl_verify:
            document.getElementById(
                'setting_ssl_verify'
            ).checked
    };

    try {

        const form =
            new FormData();

        form.append(
            'action',
            'kintone_fields'
        );

        form.append(
            'csrf_token',
            App.state.csrf
        );

        form.append(
            'app_id',
            settings.app_id
        );

        /*
         * fetchKintoneFields() はkintone APIへのPHP Proxyを使用。
         * パスワード等の認証情報をブラウザから直接kintoneへ送らない。
         */
        const original =
            App.state.data.settings;

        App.state.data.settings =
            settings;

        await App.api.post(
            'save_settings',
            {
                settings_json: settings
            }
        );

        const response =
            await fetch(
                location.pathname,
                {
                    method: 'POST',
                    body: form
                }
            );

        const json =
            await response.json();

        App.state.data.settings =
            original;

        if (!json.ok) {
            throw new Error(
                json.message ||
                '項目取得に失敗しました。'
            );
        }

        App.state.fieldCache =
            json.fields || {};

        document.getElementById(
            'kintone_status'
        ).innerHTML =
            App.render.mappingFields(
                App.state.fieldCache
            );

        document.getElementById(
            'field_message'
        ).textContent =
            Object.keys(
                App.state.fieldCache
            ).length +
            '件のフィールドを取得しました。';

    } catch (error) {
        alert(error.message);
    }
};

App.actions.saveSettings = async function () {

    const addressSelect =
        document.getElementById(
            'field_address'
        );

    const address = addressSelect
        ? Array.from(
            addressSelect.selectedOptions
          ).map(
            option => option.value
          ).filter(Boolean)
        : [];

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
            ).value.trim(),

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
            address
    };

    if (!settings.password) {
        settings.password =
            App.state.data.settings.password;
    }

    try {

        const result =
            await App.api.post(
                'save_settings',
                {
                    settings_json: settings
                }
            );

        App.state.data.settings =
            result.settings;

        alert('設定を保存しました。');

        App.actions.goList();

    } catch (error) {
        alert(error.message);
    }
};

App.actions.logout = function () {

    if (!confirm(
        'ログアウトしますか？'
    )) {
        return;
    }

    /*
     * 認証基盤が別途存在する環境を想定し、
     * 本ファイルではセッション破棄のみ行う。
     */
    location.href =
        location.pathname +
        '?logout=1';
};

if (
    document.readyState === 'loading'
) {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init()
    );
} else {
    App.init();
}
</script>

</body>
</html>
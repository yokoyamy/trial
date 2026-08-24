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
- token

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

declare(strict_types=1);

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

session_name(SURVEY_ADMIN_SESSION);
session_start();

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

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

function survey_read_data(): array
{
    if (!file_exists(SURVEY_STORAGE_FILE)) {
        $data = survey_default_data();
        @file_put_contents(
            SURVEY_STORAGE_FILE,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
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

    $base = survey_default_data();
    foreach ($base as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    return $data;
}

function survey_write_data(array $data): bool
{
    if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
        if (!@mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true) && !is_dir(SURVEY_STORAGE_DIRECTORY)) {
            return false;
        }
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

function survey_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function survey_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_id(string $prefix): string
{
    return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
}

function survey_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function survey_check_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals(survey_csrf(), $token)) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。ページを再読み込みしてください。'
        ], 403);
    }
}

/**
 * kintone URLの成形
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain) ?? '';
    $domain = preg_replace('/\.cybozu\.com.*$/i', '', $domain) ?? '';
    $domain = rtrim($domain, '/');
    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

function survey_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    return [];
}

/**
 * kintone REST API。
 * cURLを使用せずstream_context_create/file_get_contentsで通信。
 */
function kintone_api_request(
    string $method,
    string $url,
    array $headers,
    mixed $payload = null,
    array $config = []
): array {
    $method = strtoupper($method);

    $httpOptions = [
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

        $httpOptions['content'] = $encoded;
    }

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));
    if ($proxy !== '') {
        $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);
    $body = @file_get_contents($url, false, $context);
    $headersReceived = survey_safe_response_headers();

    $status = 500;

    foreach ($headersReceived as $headerLine) {
        if (preg_match('/HTTP\/[\d.]+\s+(\d+)/i', $headerLine, $m)) {
            $status = (int)$m[1];
        }
    }

    $decoded = json_decode($body ?: '', true);

    if ($status >= 200 && $status < 300) {
        return [
            'success' => true,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : []
        ];
    }

    $message = is_array($decoded)
        ? (string)($decoded['message'] ?? 'kintone API 通信エラーが発生しました。')
        : 'kintone API 通信エラーが発生しました。';

    return [
        'success' => false,
        'status' => $status,
        'message' => $message,
        'raw' => is_array($decoded) ? $decoded : []
    ];
}

function make_cybozu_auth_header(string $login_name, string $password): string
{
    return 'X-Cybozu-Authorization: ' .
        base64_encode(trim($login_name) . ':' . trim($password));
}

function survey_kintone_headers(array $settings): array
{
    return [
        make_cybozu_auth_header(
            (string)($settings['login_name'] ?? ''),
            (string)($settings['password'] ?? '')
        ),
        'Content-Type: application/json',
        'Accept: application/json'
    ];
}

function survey_get_settings(): array
{
    $data = survey_read_data();
    return is_array($data['settings'] ?? null)
        ? $data['settings']
        : survey_default_data()['settings'];
}

function survey_find_survey(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if ((string)($survey['id'] ?? '') === $id) {
            return $survey;
        }
    }
    return null;
}

function survey_public_token(string $surveyId, string $customerId): string
{
    return hash_hmac(
        'sha256',
        $surveyId . ':' . $customerId,
        __FILE__ . '|' . php_uname()
    );
}

function survey_validate_public_token(string $surveyId, string $customerId, string $token): bool
{
    return hash_equals(
        survey_public_token($surveyId, $customerId),
        $token
    );
}

function survey_question_list(array $survey): array
{
    $result = [];

    foreach (($survey['groups'] ?? []) as $groupIndex => $group) {
        foreach (($group['questions'] ?? []) as $questionIndex => $question) {
            $question['_group_index'] = $groupIndex;
            $question['_question_index'] = $questionIndex;
            $result[] = $question;
        }
    }

    return $result;
}

function survey_handle_api(): void
{
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

    if ($action === '') {
        return;
    }

    /*
     * 公開回答画面は管理者CSRF認証を必要としない。
     */
    if ($action === 'respond') {
        $surveyId = (string)($_GET['survey_id'] ?? '');
        $customerId = (string)($_GET['customer_id'] ?? '');
        $token = (string)($_GET['token'] ?? '');

        $data = survey_read_data();
        $survey = survey_find_survey($data, $surveyId);

        if (
            !$survey ||
            ($survey['deleted'] ?? false) ||
            !in_array(($survey['status'] ?? ''), ['active', 'ended'], true) ||
            !survey_validate_public_token($surveyId, $customerId, $token)
        ) {
            http_response_code(404);
            echo '<!doctype html><meta charset="UTF-8"><title>アンケート</title>';
            echo '<div style="font-family:sans-serif;padding:40px">アンケートURLが無効です。</div>';
            exit;
        }

        $customer = null;
        foreach ($data['customers'] as $item) {
            if ((string)($item['id'] ?? '') === $customerId) {
                $customer = $item;
                break;
            }
        }

        $responseExisting = null;
        foreach ($data['responses'] as $response) {
            if (
                ($response['survey_id'] ?? '') === $surveyId &&
                ($response['customer_id'] ?? '') === $customerId
            ) {
                $responseExisting = $response;
                break;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $answers = $_POST['answers'] ?? [];
            if (!is_array($answers)) {
                $answers = [];
            }

            $newResponse = [
                'id' => $responseExisting['id'] ?? survey_id('response'),
                'survey_id' => $surveyId,
                'customer_id' => $customerId,
                'company' => (string)($customer['company'] ?? ''),
                'name' => (string)($customer['name'] ?? ''),
                'email' => (string)($customer['email'] ?? ''),
                'answered_at' => survey_now(),
                'answers' => $answers
            ];

            $found = false;
            foreach ($data['responses'] as $i => $response) {
                if (($response['id'] ?? '') === $newResponse['id']) {
                    $data['responses'][$i] = $newResponse;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $data['responses'][] = $newResponse;
            }

            foreach ($data['customers'] as $i => $item) {
                if (($item['id'] ?? '') === $customerId) {
                    $data['customers'][$i]['answer_status'] = 'answered';
                    break;
                }
            }

            survey_write_data($data);

            echo '<!doctype html><html lang="ja"><meta charset="UTF-8">';
            echo '<title>回答完了</title>';
            echo '<body style="font-family:system-ui;background:#f5f7fa;padding:40px">';
            echo '<div style="max-width:680px;margin:auto;background:#fff;border-radius:16px;padding:40px;box-shadow:0 4px 20px #0001">';
            echo '<h1>回答ありがとうございました</h1>';
            echo '<p>アンケートへの回答を受け付けました。</p>';
            echo '</div></body></html>';
            exit;
        }

        $questions = survey_question_list($survey);

        echo '<!doctype html><html lang="ja"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . survey_h((string)$survey['title']) . '</title></head>';
        echo '<body class="bg-slate-50 text-slate-800">';
        echo '<main class="max-w-3xl mx-auto px-4 py-10">';
        echo '<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 md:p-10">';
        echo '<h1 class="text-2xl font-bold mb-8">' . survey_h((string)$survey['title']) . '</h1>';
        echo '<form method="post" class="space-y-8">';

        foreach ($survey['groups'] as $group) {
            echo '<section class="border-t pt-6">';
            echo '<h2 class="text-lg font-bold mb-5">' . survey_h((string)$group['name']) . '</h2>';

            foreach ($group['questions'] as $q) {
                $qid = survey_h((string)$q['id']);
                $required = !empty($q['required']);
                echo '<div class="mb-7">';
                echo '<label class="block font-semibold mb-3">';
                echo survey_h((string)$q['text']);
                if ($required) {
                    echo '<span class="text-red-500 ml-1">必須</span>';
                }
                echo '</label>';

                if (($q['type'] ?? '') === 'text') {
                    echo '<textarea name="answers[' . $qid . ']" rows="4" class="w-full border border-slate-300 rounded-xl px-4 py-3 focus:ring-2 focus:ring-indigo-400 focus:outline-none"';
                    echo $required ? ' required' : '';
                    echo '>' . survey_h((string)($responseExisting['answers'][$q['id']] ?? '')) . '</textarea>';
                } else {
                    foreach (($q['options'] ?? []) as $oi => $option) {
                        $oid = $qid . '_' . $oi;
                        $name = 'answers[' . $qid . ']';
                        $type = (($q['type'] ?? '') === 'multiple') ? 'checkbox' : 'radio';
                        if ($type === 'checkbox') {
                            $name .= '[]';
                        }

                        echo '<label class="flex items-center gap-3 py-2">';
                        echo '<input type="' . $type . '" name="' . $name . '" value="' . survey_h((string)$option) . '" class="h-4 w-4"';
                        echo $required && $type === 'radio' ? ' required' : '';
                        echo '>';
                        echo '<span>' . survey_h((string)$option) . '</span></label>';
                    }

                    if (!empty($q['other_enabled'])) {
                        echo '<input type="text" name="answers[' . $qid . '_other]" placeholder="その他" class="mt-2 w-full border border-slate-300 rounded-xl px-4 py-3">';
                    }
                }

                echo '</div>';
            }

            echo '</section>';
        }

        echo '<button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl py-3 font-semibold">回答を送信する</button>';
        echo '</form></div></main></body></html>';
        exit;
    }

    survey_check_csrf();

    $data = survey_read_data();

    if ($action === 'load') {
        survey_json_response(['ok' => true, 'data' => $data]);
    }

    if ($action === 'save_survey') {
        $json = (string)($_POST['survey_json'] ?? '');
        $survey = json_decode($json, true);

        if (!is_array($survey)) {
            survey_json_response(['ok' => false, 'message' => 'アンケートデータが不正です。'], 400);
        }

        $now = survey_now();
        $survey['id'] = (string)($survey['id'] ?? survey_id('survey'));
        $survey['title'] = trim((string)($survey['title'] ?? '無題のアンケート'));
        $survey['start_at'] = (string)($survey['start_at'] ?? '');
        $survey['end_at'] = (string)($survey['end_at'] ?? '');
        $survey['status'] = in_array(($survey['status'] ?? 'draft'), ['draft', 'active', 'ended'], true)
            ? $survey['status']
            : 'draft';
        $survey['numbering_mode'] = ($survey['numbering_mode'] ?? 'global') === 'group'
            ? 'group'
            : 'global';
        $survey['groups'] = is_array($survey['groups'] ?? null) ? $survey['groups'] : [];
        $survey['deleted'] = false;

        $existingIndex = null;

        foreach ($data['surveys'] as $i => $item) {
            if (($item['id'] ?? '') === $survey['id']) {
                $existingIndex = $i;
                break;
            }
        }

        if ($existingIndex === null) {
            $survey['created_at'] = $now;
            $survey['updated_at'] = $now;
            $data['surveys'][] = $survey;
        } else {
            $survey['created_at'] = $data['surveys'][$existingIndex]['created_at'] ?? $now;
            $survey['updated_at'] = $now;
            $data['surveys'][$existingIndex] = $survey;
        }

        if (!survey_write_data($data)) {
            survey_json_response(['ok' => false, 'message' => 'データファイルを書き込めません。'], 500);
        }

        survey_json_response(['ok' => true, 'survey' => $survey]);
    }

    if ($action === 'delete_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as $i => $survey) {
            if (($survey['id'] ?? '') === $id) {
                $data['surveys'][$i]['deleted'] = true;
                $data['surveys'][$i]['updated_at'] = survey_now();
            }
        }

        survey_write_data($data);
        survey_json_response(['ok' => true]);
    }

    if ($action === 'status') {
        $id = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? 'draft');

        if (!in_array($status, ['draft', 'active', 'ended'], true)) {
            survey_json_response(['ok' => false, 'message' => '不正なステータスです。'], 400);
        }

        foreach ($data['surveys'] as $i => $survey) {
            if (($survey['id'] ?? '') === $id) {
                $data['surveys'][$i]['status'] = $status;
                $data['surveys'][$i]['updated_at'] = survey_now();
            }
        }

        survey_write_data($data);
        survey_json_response(['ok' => true]);
    }

    if ($action === 'duplicate_survey') {
        $id = (string)($_POST['survey_id'] ?? '');
        $source = survey_find_survey($data, $id);

        if (!$source) {
            survey_json_response(['ok' => false, 'message' => 'アンケートが見つかりません。'], 404);
        }

        $copy = $source;
        $copy['id'] = survey_id('survey');
        $copy['title'] = $source['title'] . '（複製）';
        $copy['status'] = 'draft';
        $copy['deleted'] = false;
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();

        foreach ($copy['groups'] as &$group) {
            $group['id'] = survey_id('group');
            foreach (($group['questions'] ?? []) as &$question) {
                $question['id'] = survey_id('question');
            }
        }
        unset($group, $question);

        $data['surveys'][] = $copy;
        survey_write_data($data);

        survey_json_response(['ok' => true, 'survey' => $copy]);
    }

    if ($action === 'save_settings') {
        $settings = json_decode((string)($_POST['settings_json'] ?? ''), true);

        if (!is_array($settings)) {
            survey_json_response(['ok' => false, 'message' => '設定データが不正です。'], 400);
        }

        $base = survey_default_data()['settings'];

        foreach ($base as $key => $default) {
            if (array_key_exists($key, $settings)) {
                $base[$key] = $settings[$key];
            }
        }

        $data['settings'] = $base;

        if (!survey_write_data($data)) {
            survey_json_response(['ok' => false, 'message' => '設定を保存できません。'], 500);
        }

        survey_json_response(['ok' => true]);
    }

    if ($action === 'kintone_fields') {
        $settings = survey_get_settings();

        if (!empty($_POST['settings_json'])) {
            $posted = json_decode((string)$_POST['settings_json'], true);
            if (is_array($posted)) {
                $settings = array_merge($settings, $posted);
            }
        }

        $appId = (string)($_POST['app_id'] ?? $settings['app_id'] ?? '');
        $domain = (string)($settings['subdomain'] ?? '');

        if ($domain === '' || $appId === '') {
            survey_json_response([
                'ok' => false,
                'message' => 'サブドメインとアプリIDを入力してください。'
            ], 400);
        }

        $url = kintone_build_url(
            $domain,
            '/k/v1/app/form/fields.json?app=' . rawurlencode($appId)
        );

        $result = kintone_api_request(
            'GET',
            $url,
            survey_kintone_headers($settings),
            null,
            ['proxy' => $settings['proxy'] ?? '']
        );

        if (!$result['success']) {
            survey_json_response([
                'ok' => false,
                'message' => $result['message'],
                'status' => $result['status']
            ], 502);
        }

        $fields = [];

        foreach (($result['data']['properties'] ?? []) as $code => $property) {
            $fields[] = [
                'code' => (string)$code,
                'label' => (string)($property['label'] ?? $code),
                'type' => (string)($property['type'] ?? '')
            ];
        }

        survey_json_response([
            'ok' => true,
            'fields' => $fields
        ]);
    }

    if ($action === 'kintone_test') {
        $settings = json_decode((string)($_POST['settings_json'] ?? ''), true);

        if (!is_array($settings)) {
            survey_json_response(['ok' => false, 'message' => '設定データが不正です。'], 400);
        }

        $domain = (string)($settings['subdomain'] ?? '');
        if ($domain === '') {
            survey_json_response(['ok' => false, 'message' => 'サブドメインを入力してください。'], 400);
        }

        $url = kintone_build_url($domain, '/k/v1/apps.json');

        $result = kintone_api_request(
            'GET',
            $url,
            survey_kintone_headers($settings),
            null,
            ['proxy' => $settings['proxy'] ?? '']
        );

        survey_json_response([
            'ok' => $result['success'],
            'message' => $result['success']
                ? 'kintoneへの接続に成功しました。'
                : $result['message'],
            'status' => $result['status']
        ]);
    }

    if ($action === 'sync_customers') {
        $settings = survey_get_settings();
        $appId = (string)($settings['app_id'] ?? '');

        if ($appId === '') {
            survey_json_response(['ok' => false, 'message' => '顧客管理アプリIDが設定されていません。'], 400);
        }

        $mapping = [
            'company' => $settings['field_company'] ?? '',
            'name' => $settings['field_name'] ?? '',
            'email' => $settings['field_email'] ?? '',
            'department' => $settings['field_department'] ?? '',
            'phone' => $settings['field_phone'] ?? '',
            'address' => $settings['field_address'] ?? []
        ];

        $fields = [
            $mapping['company'],
            $mapping['name'],
            $mapping['email'],
            $mapping['department'],
            $mapping['phone']
        ];

        foreach ((array)$mapping['address'] as $addressCode) {
            $fields[] = $addressCode;
        }

        $fields = array_values(array_filter(array_unique(array_map('strval', $fields))));

        $query = [];
        $url = kintone_build_url(
            (string)$settings['subdomain'],
            '/k/v1/records.json?app=' . rawurlencode($appId)
        );

        if ($fields) {
            $url .= '&fields%5B%5D=' . implode('&fields%5B%5D=', array_map('rawurlencode', $fields));
        }

        $result = kintone_api_request(
            'GET',
            $url,
            survey_kintone_headers($settings),
            null,
            ['proxy' => $settings['proxy'] ?? '']
        );

        if (!$result['success']) {
            survey_json_response([
                'ok' => false,
                'message' => $result['message']
            ], 502);
        }

        $existingByEmail = [];

        foreach ($data['customers'] as $i => $customer) {
            $email = strtolower(trim((string)($customer['email'] ?? '')));
            if ($email !== '') {
                $existingByEmail[$email] = $i;
            }
        }

        $count = 0;

        foreach (($result['data']['records'] ?? []) as $record) {
            $getValue = static function (string $code) use ($record): string {
                if ($code === '') {
                    return '';
                }

                $value = $record[$code]['value'] ?? '';

                if (is_array($value)) {
                    $parts = [];
                    foreach ($value as $v) {
                        if (is_array($v)) {
                            $parts[] = (string)($v['name'] ?? $v['value'] ?? '');
                        } else {
                            $parts[] = (string)$v;
                        }
                    }
                    return implode(' ', array_filter($parts));
                }

                return (string)$value;
            };

            $addressParts = [];
            foreach ((array)$mapping['address'] as $code) {
                $value = $getValue((string)$code);
                if ($value !== '') {
                    $addressParts[] = $value;
                }
            }

            $email = strtolower(trim($getValue((string)$mapping['email'])));

            if ($email === '') {
                continue;
            }

            $customer = [
                'id' => survey_id('customer'),
                'company' => $getValue((string)$mapping['company']),
                'name' => $getValue((string)$mapping['name']),
                'email' => $email,
                'department' => $getValue((string)$mapping['department']),
                'phone' => $getValue((string)$mapping['phone']),
                'address' => implode(' ', $addressParts),
                'source' => 'kintone',
                'sent_at' => '',
                'send_count' => 0,
                'answer_status' => 'unanswered',
                'kintone_status' => 'registered'
            ];

            if (isset($existingByEmail[$email])) {
                $idx = $existingByEmail[$email];
                $oldId = $data['customers'][$idx]['id'] ?? $customer['id'];
                $customer['id'] = $oldId;
                $customer['sent_at'] = $data['customers'][$idx]['sent_at'] ?? '';
                $customer['send_count'] = $data['customers'][$idx]['send_count'] ?? 0;
                $customer['answer_status'] = $data['customers'][$idx]['answer_status'] ?? 'unanswered';
                $data['customers'][$idx] = $customer;
            } else {
                $data['customers'][] = $customer;
                $existingByEmail[$email] = count($data['customers']) - 1;
            }

            $count++;
        }

        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'message' => $count . ' 件の顧客情報を同期しました。',
            'count' => $count
        ]);
    }

    if ($action === 'send_mail') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $recipientIds = json_decode((string)($_POST['recipient_ids'] ?? '[]'), true);

        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

        $subject = (string)($_POST['mail_subject'] ?? '');
        $body = (string)($_POST['mail_body'] ?? '');
        $templateType = (string)($_POST['template_type'] ?? 'initial');

        $survey = survey_find_survey($data, $surveyId);

        if (!$survey) {
            survey_json_response(['ok' => false, 'message' => 'アンケートが見つかりません。'], 404);
        }

        $sent = 0;
        $failed = 0;
        $sentIds = [];

        foreach ($data['customers'] as $i => $customer) {
            if (!in_array($customer['id'] ?? '', $recipientIds, true)) {
                continue;
            }

            $email = trim((string)($customer['email'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                continue;
            }

            $personalUrl = '';
            if (!empty($_SERVER['HTTP_HOST'])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $base = $scheme . '://' . $_SERVER['HTTP_HOST'] .
                    rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

                $personalUrl = $base . '/' . basename(__FILE__) .
                    '?action=respond&survey_id=' . rawurlencode($surveyId) .
                    '&customer_id=' . rawurlencode((string)$customer['id']) .
                    '&token=' . rawurlencode(
                        survey_public_token($surveyId, (string)$customer['id'])
                    );
            }

            $mailSubject = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [(string)($customer['name'] ?? ''), $personalUrl],
                $subject
            );

            $mailBody = str_replace(
                ['{顧客名}', '{アンケートURL}'],
                [(string)($customer['name'] ?? ''), $personalUrl],
                $body
            );

            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . (string)($_SERVER['SERVER_ADMIN'] ?? 'noreply@localhost')
            ];

            $ok = @mail(
                $email,
                '=?UTF-8?B?' . base64_encode($mailSubject) . '?=',
                $mailBody,
                implode("\r\n", $headers)
            );

            /*
             * PHPのmail()はサーバーのMTA設定に依存する。
             * 成功したものだけ送信履歴を更新する。
             */
            if ($ok) {
                $data['customers'][$i]['sent_at'] = survey_now();
                $data['customers'][$i]['send_count'] =
                    (int)($data['customers'][$i]['send_count'] ?? 0) + 1;

                $data['customers'][$i]['answer_status'] =
                    $data['customers'][$i]['answer_status'] === 'answered'
                        ? 'answered'
                        : 'unanswered';

                $sentIds[] = $customer['id'];
                $sent++;

                $data['mail_logs'][] = [
                    'id' => survey_id('mail_log'),
                    'survey_id' => $surveyId,
                    'customer_id' => $customer['id'],
                    'sent_at' => survey_now(),
                    'template_type' => $templateType,
                    'subject' => $mailSubject,
                    'body' => $mailBody,
                    'executor' => (string)($_SESSION['admin_name'] ?? '管理者')
                ];
            } else {
                $failed++;
            }
        }

        survey_write_data($data);

        survey_json_response([
            'ok' => true,
            'sent' => $sent,
            'failed' => $failed,
            'message' => $sent . ' 件送信しました。'
        ]);
    }

    if ($action === 'mark_kintone') {
        $customerId = (string)($_POST['customer_id'] ?? '');

        foreach ($data['customers'] as $i => $customer) {
            if (($customer['id'] ?? '') === $customerId) {
                $data['customers'][$i]['kintone_status'] = 'registered';
            }
        }

        survey_write_data($data);
        survey_json_response(['ok' => true]);
    }

    if ($action === 'csv') {
        $surveyId = (string)($_GET['survey_id'] ?? '');

        $survey = survey_find_survey($data, $surveyId);

        if (!$survey) {
            http_response_code(404);
            exit('Survey not found');
        }

        $questions = survey_question_list($survey);

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="survey_' .
            preg_replace('/[^A-Za-z0-9_-]/', '_', $surveyId) .
            '.csv"'
        );

        echo "\xEF\xBB\xBF";

        $fp = fopen('php://output', 'w');

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
                $value = $response['answers'][$question['id']] ?? '';

                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
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
        'message' => '不明なアクションです。'
    ], 400);
}

if (isset($_GET['action']) || isset($_POST['action'])) {
    survey_handle_api();
}

$csrf = survey_csrf();
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

<body class="bg-slate-50 text-slate-800 min-h-screen">

<div id="app"></div>

<input type="hidden" id="csrf_token" value="<?= survey_h($csrf) ?>">

<script>
window.App = {
    state: {
        data: null,
        screen: 'list',
        survey: null,
        surveyId: '',
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        customerFilter: '',
        responseFilter: '',
        selectedCustomers: [],
        selectedQuestions: [],
        previewMobile: false,
        editing: false,
        dirty: false,
        loading: false,
        kintoneFields: []
    },

    api: {},

    render: {},

    actions: {},

    util: {},

    init: async function() {
        if (this.state.initialized) return;
        this.state.initialized = true;

        this.util.showLoading('読み込み中...');
        try {
            const result = await this.api.load();
            if (!result.ok) {
                throw new Error(result.message || '初期化に失敗しました。');
            }

            this.state.data = result.data;
            this.state.screen = 'list';
            this.render.app();
        } catch (error) {
            document.getElementById('app').innerHTML = `
                <div class="min-h-screen flex items-center justify-center p-6">
                    <div class="max-w-xl w-full bg-white border border-red-200 rounded-2xl shadow-sm p-8">
                        <div class="text-red-600 text-xl font-bold mb-3">初期化に失敗しました</div>
                        <p class="text-slate-600 mb-4">${this.util.e(error.message)}</p>
                        <p class="text-sm text-slate-500">
                            データファイルへのアクセス権限、survey_storageディレクトリの権限、
                            PHP設定などを確認してください。
                        </p>
                        <button onclick="location.reload()"
                            class="mt-6 bg-indigo-600 text-white px-5 py-2.5 rounded-xl">
                            再読み込み
                        </button>
                    </div>
                </div>`;
        } finally {
            this.util.hideLoading();
        }
    },

    util: {
        e: function(value) {
            const div = document.createElement('div');
            div.textContent = String(value ?? '');
            return div.innerHTML;
        },

        uid: function(prefix) {
            return prefix + '_' + Date.now() + '_' +
                Math.random().toString(36).slice(2, 10);
        },

        escAttr: function(value) {
            return this.e(value).replace(/`/g, '&#96;');
        },

        formatDate: function(value) {
            if (!value) return '未設定';
            return String(value).replace(' ', ' ');
        },

        statusLabel: function(status) {
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

        typeLabel: function(type) {
            return {
                single: '単一選択',
                multiple: '複数選択',
                text: '自由記述'
            }[type] || type;
        },

        showLoading: function(text) {
            const old = document.getElementById('app-loading');
            if (old) old.remove();

            document.body.insertAdjacentHTML('beforeend', `
                <div id="app-loading"
                    class="fixed inset-0 z-[100] bg-slate-900/30 flex items-center justify-center">
                    <div class="bg-white rounded-2xl shadow-xl px-7 py-5 flex items-center gap-4">
                        <div class="h-6 w-6 border-2 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
                        <span class="font-medium">${this.e(text || '処理中...')}</span>
                    </div>
                </div>`);
        },

        hideLoading: function() {
            document.getElementById('app-loading')?.remove();
        },

        toast: function(message, type = 'success') {
            const color = type === 'error'
                ? 'bg-red-600'
                : type === 'warning'
                    ? 'bg-amber-500'
                    : 'bg-slate-800';

            const id = 'toast_' + Date.now();

            document.body.insertAdjacentHTML('beforeend', `
                <div id="${id}"
                    class="fixed right-5 bottom-5 z-[120] ${color} text-white px-5 py-3 rounded-xl shadow-xl">
                    ${this.e(message)}
                </div>`);

            setTimeout(() => document.getElementById(id)?.remove(), 3500);
        },

        confirm: function(message) {
            return window.confirm(message);
        },

        currentSurvey: function() {
            return this.state.survey;
        },

        questionCount: function(survey) {
            return (survey.groups || []).reduce(
                (sum, group) => sum + (group.questions || []).length,
                0
            );
        },

        responsesFor: function(surveyId) {
            return (this.state.data.responses || []).filter(
                response => response.survey_id === surveyId
            );
        },

        sentCustomerCount: function(surveyId) {
            const logs = (this.state.data.mail_logs || []).filter(
                log => log.survey_id === surveyId
            );

            return new Set(logs.map(log => log.customer_id)).size;
        },

        findCustomer: function(id) {
            return (this.state.data.customers || []).find(
                customer => customer.id === id
            );
        },

        publicUrl: function(surveyId, customerId) {
            const base = location.origin +
                location.pathname.substring(0, location.pathname.lastIndexOf('/') + 1);

            return base + location.pathname.split('/').pop() +
                '?action=respond&survey_id=' + encodeURIComponent(surveyId) +
                '&customer_id=' + encodeURIComponent(customerId) +
                '&token=' + encodeURIComponent(
                    this.state.data.public_tokens?.[surveyId]?.[customerId] || ''
                );
        }
    },

    api: {
        request: async function(action, params = {}) {
            const body = new URLSearchParams();
            body.set('action', action);
            body.set(
                'csrf_token',
                document.getElementById('csrf_token').value
            );

            Object.entries(params).forEach(([key, value]) => {
                if (value !== undefined && value !== null) {
                    body.set(
                        key,
                        typeof value === 'object' ? JSON.stringify(value) : String(value)
                    );
                }
            });

            const response = await fetch(location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body
            });

            const text = await response.text();

            let json;

            try {
                json = JSON.parse(text);
            } catch (error) {
                throw new Error(
                    'サーバーから正しいJSONが返されませんでした。HTTP ' +
                    response.status
                );
            }

            if (!response.ok && !json.ok) {
                throw new Error(json.message || 'サーバーエラーが発生しました。');
            }

            return json;
        },

        load: async function() {
            return await this.request('load');
        },

        saveSurvey: async function(survey) {
            return await this.request('save_survey', {
                survey_json: survey
            });
        },

        deleteSurvey: async function(id) {
            return await this.request('delete_survey', {
                survey_id: id
            });
        },

        changeStatus: async function(id, status) {
            return await this.request('status', {
                survey_id: id,
                status
            });
        },

        duplicateSurvey: async function(id) {
            return await this.request('duplicate_survey', {
                survey_id: id
            });
        },

        saveSettings: async function(settings) {
            return await this.request('save_settings', {
                settings_json: settings
            });
        },

        testKintone: async function(settings) {
            return await this.request('kintone_test', {
                settings_json: settings
            });
        },

        fields: async function(settings, appId) {
            return await this.request('kintone_fields', {
                settings_json: settings,
                app_id: appId
            });
        },

        syncCustomers: async function() {
            return await this.request('sync_customers');
        },

        sendMail: async function(surveyId, ids, subject, body, type) {
            return await this.request('send_mail', {
                survey_id: surveyId,
                recipient_ids: ids,
                mail_subject: subject,
                mail_body: body,
                template_type: type
            });
        },

        markKintone: async function(id) {
            return await this.request('mark_kintone', {
                customer_id: id
            });
        }
    },

    render: {
        app: function() {
            const app = document.getElementById('app');

            app.innerHTML = `
                <div class="min-h-screen">
                    ${this.header()}
                    <main class="max-w-[1500px] mx-auto px-5 py-6">
                        ${this.content()}
                    </main>
                </div>`;

            this.afterRender();
        },

        header: function() {
            return `
                <header class="sticky top-0 z-40 bg-white border-b border-slate-200">
                    <div class="max-w-[1500px] mx-auto px-5 h-16 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="h-9 w-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-bold">
                                Q
                            </div>
                            <div>
                                <div class="font-bold text-slate-900">アンケート管理</div>
                                <div class="text-xs text-slate-400">Survey Management System</div>
                            </div>
                        </div>

                        <nav class="flex items-center gap-2">
                            <button onclick="App.actions.goList()"
                                class="px-4 py-2 rounded-lg text-sm font-medium
                                ${this.state.screen === 'list'
                                    ? 'bg-indigo-50 text-indigo-700'
                                    : 'text-slate-600 hover:bg-slate-100'}">
                                アンケート一覧
                            </button>

                            <button onclick="App.actions.goSettings()"
                                class="px-4 py-2 rounded-lg text-sm font-medium
                                ${this.state.screen === 'settings'
                                    ? 'bg-indigo-50 text-indigo-700'
                                    : 'text-slate-600 hover:bg-slate-100'}">
                                kintone連携設定
                            </button>

                            <button onclick="App.actions.logout()"
                                class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:bg-slate-100">
                                ログアウト
                            </button>
                        </nav>
                    </div>
                </header>`;
        },

        content: function() {
            switch (this.state.screen) {
                case 'editor':
                    return this.editor();
                case 'mail':
                    return this.mail();
                case 'analytics':
                    return this.analytics();
                case 'settings':
                    return this.settings();
                default:
                    return this.list();
            }
        },

        list: function() {
            let surveys = (this.state.data.surveys || []).filter(
                survey => !survey.deleted
            );

            const keyword = this.state.keyword.trim().toLowerCase();

            if (keyword) {
                surveys = surveys.filter(
                    survey => String(survey.title || '').toLowerCase().includes(keyword)
                );
            }

            if (this.state.statusFilter !== 'all') {
                surveys = surveys.filter(
                    survey => survey.status === this.state.statusFilter
                );
            }

            surveys.sort((a, b) => {
                const responsesA = this.util.responsesFor(a.id).length;
                const responsesB = this.util.responsesFor(b.id).length;

                switch (this.state.sort) {
                    case 'updated_asc':
                        return String(a.updated_at).localeCompare(String(b.updated_at));
                    case 'responses_desc':
                        return responsesB - responsesA;
                    case 'responses_asc':
                        return responsesA - responsesB;
                    case 'start_desc':
                        return String(b.start_at || '').localeCompare(String(a.start_at || ''));
                    case 'start_asc':
                        return String(a.start_at || '').localeCompare(String(b.start_at || ''));
                    default:
                        return String(b.updated_at).localeCompare(String(a.updated_at));
                }
            });

            return `
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-5 mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">アンケート一覧</h1>
                        <p class="text-sm text-slate-500 mt-1">
                            アンケートの作成・公開・送信・集計を管理します。
                        </p>
                    </div>

                    <button onclick="App.actions.newSurvey()"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-semibold shadow-sm">
                        ＋ 新規アンケート作成
                    </button>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-5">
                    <div class="grid md:grid-cols-[1fr_180px_220px] gap-3">
                        <input
                            value="${this.util.escAttr(this.state.keyword)}"
                            onkeydown="App.actions.searchKey(event)"
                            oninput="App.actions.search(this.value)"
                            placeholder="タイトルを検索..."
                            class="border border-slate-300 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-indigo-200">

                        <select onchange="App.actions.toggleStatusFilter(this.value)"
                            class="border border-slate-300 rounded-xl px-4 py-2.5">
                            <option value="all" ${this.state.statusFilter === 'all' ? 'selected' : ''}>すべて</option>
                            <option value="active" ${this.state.statusFilter === 'active' ? 'selected' : ''}>公開中</option>
                            <option value="draft" ${this.state.statusFilter === 'draft' ? 'selected' : ''}>下書き</option>
                            <option value="ended" ${this.state.statusFilter === 'ended' ? 'selected' : ''}>終了</option>
                        </select>

                        <select onchange="App.actions.changeSort(this.value)"
                            class="border border-slate-300 rounded-xl px-4 py-2.5">
                            <option value="updated_desc" ${this.state.sort === 'updated_desc' ? 'selected' : ''}>更新日：新しい順</option>
                            <option value="updated_asc" ${this.state.sort === 'updated_asc' ? 'selected' : ''}>更新日：古い順</option>
                            <option value="responses_desc" ${this.state.sort === 'responses_desc' ? 'selected' : ''}>回答数：多い順</option>
                            <option value="responses_asc" ${this.state.sort === 'responses_asc' ? 'selected' : ''}>回答数：少ない順</option>
                            <option value="start_desc" ${this.state.sort === 'start_desc' ? 'selected' : ''}>開始日：新しい順</option>
                            <option value="start_asc" ${this.state.sort === 'start_asc' ? 'selected' : ''}>開始日：古い順</option>
                        </select>
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
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
                                ${surveys.length
                                    ? surveys.map(survey => this.surveyRow(survey)).join('')
                                    : `
                                    <tr>
                                        <td colspan="6" class="py-20 text-center text-slate-400">
                                            アンケートがありません。
                                        </td>
                                    </tr>`}
                            </tbody>
                        </table>
                    </div>
                </div>`;
        },

        surveyRow: function(survey) {
            const responseCount = this.util.responsesFor(survey.id).length;
            const status = survey.status;

            let buttons = `
                <button onclick="App.actions.editSurvey('${this.util.escAttr(survey.id)}')"
                    class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">
                    確認・編集
                </button>`;

            if (status === 'active') {
                buttons += `
                    <button onclick="App.actions.analytics('${this.util.escAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">
                        集計
                    </button>
                    <button onclick="App.actions.mail('${this.util.escAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">
                        送信
                    </button>
                    <button onclick="App.actions.stopSurvey('${this.util.escAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 border border-amber-200">
                        停止
                    </button>`;
            }

            if (status === 'draft') {
                buttons += `
                    <button onclick="App.actions.deleteSurvey('${this.util.escAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg bg-red-50 text-red-600 border border-red-200">
                        削除
                    </button>`;
            }

            if (status === 'ended') {
                buttons += `
                    <button onclick="App.actions.analytics('${this.util.escAttr(survey.id)}')"
                        class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">
                        集計
                    </button>`;
            }

            buttons += `
                <button onclick="App.actions.duplicateSurvey('${this.util.escAttr(survey.id)}')"
                    class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">
                    複製
                </button>`;

            return `
                <tr class="hover:bg-slate-50/70">
                    <td class="px-5 py-4 whitespace-nowrap text-slate-500">
                        <div>${this.util.e((survey.created_at || '').slice(0,10))}</div>
                        <div class="text-xs mt-1">更新: ${this.util.e((survey.updated_at || '').slice(0,10))}</div>
                    </td>
                    <td class="px-5 py-4">
                        <div class="font-bold text-slate-900">${this.util.e(survey.title)}</div>
                        <div class="text-xs text-slate-400 mt-1">
                            ${this.util.questionCount(survey)} 問
                        </div>
                    </td>
                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                        ${this.util.e(survey.start_at || '未設定')}
                        <span class="text-slate-300 mx-1">～</span>
                        ${this.util.e(survey.end_at || '未設定')}
                    </td>
                    <td class="px-5 py-4">
                        <span class="inline-flex px-2.5 py-1 rounded-full border text-xs font-semibold ${this.util.statusClass(status)}">
                            ${this.util.statusLabel(status)}
                        </span>
                    </td>
                    <td class="px-5 py-4 text-right font-semibold">${responseCount} 件</td>
                    <td class="px-5 py-4">
                        <div class="flex justify-end flex-wrap gap-2">${buttons}</div>
                    </td>
                </tr>`;
        },

        editor: function() {
            const survey = this.state.survey;

            return `
                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <button onclick="App.actions.cancelEditor()"
                            class="text-sm text-slate-500 hover:text-indigo-600">
                            ← アンケート一覧
                        </button>
                        <h1 class="text-2xl font-bold mt-2">
                            ${survey.id ? 'アンケート編集' : '新規アンケート作成'}
                        </h1>
                    </div>

                    <div class="flex gap-2">
                        <button onclick="App.actions.preview()"
                            class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white">
                            プレビュー
                        </button>
                        <button onclick="App.actions.saveEditor()"
                            class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">
                            保存して一覧へ戻る
                        </button>
                    </div>
                </div>

                <div class="grid lg:grid-cols-[320px_1fr] gap-5">
                    <aside class="bg-white border border-slate-200 rounded-2xl p-5 h-fit">
                        <h2 class="font-bold mb-5">基本設定</h2>

                        <label class="block text-sm font-medium mb-2">タイトル</label>
                        <input id="survey_title"
                            value="${this.util.escAttr(survey.title || '')}"
                            oninput="App.actions.updateSurveyField('title', this.value)"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2.5 mb-5">

                        <label class="block text-sm font-medium mb-2">開始日時</label>
                        <input id="survey_start_at" type="datetime-local"
                            value="${this.util.escAttr((survey.start_at || '').replace(' ', 'T'))}"
                            onchange="App.actions.updateSurveyField('start_at', this.value)"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2.5 mb-5">

                        <label class="block text-sm font-medium mb-2">終了日時</label>
                        <input id="survey_end_at" type="datetime-local"
                            value="${this.util.escAttr((survey.end_at || '').replace(' ', 'T'))}"
                            onchange="App.actions.updateSurveyField('end_at', this.value)"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2.5 mb-5">

                        <label class="block text-sm font-medium mb-2">質問番号</label>
                        <select id="survey_numbering_mode"
                            onchange="App.actions.updateSurveyField('numbering_mode', this.value)"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2.5 mb-5">
                            <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>Q1, Q2, Q3...</option>
                            <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>Q1-1, Q1-2...</option>
                        </select>

                        <label class="block text-sm font-medium mb-2">公開ステータス</label>
                        <select onchange="App.actions.updateSurveyField('status', this.value)"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2.5">
                            <option value="draft" ${survey.status === 'draft' ? 'selected' : ''}>下書き</option>
                            <option value="active" ${survey.status === 'active' ? 'selected' : ''}>公開中</option>
                            <option value="ended" ${survey.status === 'ended' ? 'selected' : ''}>終了</option>
                        </select>
                    </aside>

                    <section class="bg-white border border-slate-200 rounded-2xl p-5">
                        <div class="flex items-center justify-between mb-5">
                            <div>
                                <h2 class="font-bold text-lg">質問構成</h2>
                                <p class="text-sm text-slate-400">ドラッグ＆ドロップで順序を変更できます。</p>
                            </div>

                            <button onclick="App.actions.addGroup()"
                                class="px-4 py-2 rounded-xl bg-indigo-50 text-indigo-700 font-semibold">
                                ＋ グループ追加
                            </button>
                        </div>

                        <div id="question_editor" class="space-y-5">
                            ${(survey.groups || []).map((group, gi) =>
                                this.groupEditor(group, gi)
                            ).join('')}
                        </div>
                    </section>
                </div>

                ${this.previewModal()}
                ${this.responseModal()}`;
        },

        groupEditor: function(group, gi) {
            return `
                <div class="group-item border border-slate-200 rounded-2xl overflow-hidden bg-slate-50"
                    data-group-id="${this.util.escAttr(group.id)}">

                    <div class="group-header flex items-center gap-3 px-4 py-3 bg-white border-b border-slate-200">
                        <span class="group-handle cursor-grab text-xl text-slate-400">⠿</span>

                        <input
                            value="${this.util.escAttr(group.name || '')}"
                            onchange="App.actions.updateGroupName('${this.util.escAttr(group.id)}', this.value)"
                            class="flex-1 font-bold border-0 outline-none bg-transparent">

                        <button onclick="App.actions.addQuestion('${this.util.escAttr(group.id)}')"
                            class="text-sm px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700">
                            ＋ 質問
                        </button>

                        <button onclick="App.actions.deleteGroup('${this.util.escAttr(group.id)}')"
                            class="text-sm px-3 py-1.5 rounded-lg bg-red-50 text-red-600">
                            削除
                        </button>
                    </div>

                    <div class="question-list p-4 space-y-3"
                        data-group-id="${this.util.escAttr(group.id)}">
                        ${(group.questions || []).map((question, qi) =>
                            this.questionEditor(question, gi, qi)
                        ).join('')}

                        ${!(group.questions || []).length
                            ? `<div class="empty-question text-center py-8 text-sm text-slate-400 border border-dashed rounded-xl">
                                質問がありません。「＋ 質問」で追加してください。
                               </div>`
                            : ''}
                    </div>
                </div>`;
        },

        questionEditor: function(q, gi, qi) {
            const number = this.questionNumber(q.id);
            const options = q.options || [];

            return `
                <div class="question-item bg-white border border-slate-200 rounded-xl p-4 shadow-sm"
                    data-question-id="${this.util.escAttr(q.id)}">

                    <div class="flex gap-3">
                        <span class="question-handle cursor-grab text-slate-400 text-lg pt-1">⠿</span>

                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="question-number text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded">
                                    ${number}
                                </span>

                                <select onchange="App.actions.updateQuestion('${this.util.escAttr(q.id)}','type',this.value)"
                                    class="text-xs border border-slate-300 rounded-lg px-2 py-1">
                                    <option value="single" ${q.type === 'single' ? 'selected' : ''}>単一選択</option>
                                    <option value="multiple" ${q.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                                    <option value="text" ${q.type === 'text' ? 'selected' : ''}>自由記述</option>
                                </select>

                                <label class="ml-auto flex items-center gap-2 text-xs">
                                    <input type="checkbox"
                                        ${q.required ? 'checked' : ''}
                                        onchange="App.actions.updateQuestion('${this.util.escAttr(q.id)}','required',this.checked)">
                                    必須
                                </label>

                                <button onclick="App.actions.deleteQuestion('${this.util.escAttr(q.id)}')"
                                    class="text-red-500 text-sm">
                                    削除
                                </button>
                            </div>

                            <input
                                value="${this.util.escAttr(q.text || '')}"
                                oninput="App.actions.updateQuestion('${this.util.escAttr(q.id)}','text',this.value)"
                                placeholder="質問文を入力してください"
                                class="w-full border border-slate-300 rounded-xl px-3 py-2.5 mb-3">

                            ${q.type === 'text'
                                ? `<div class="bg-slate-50 rounded-xl px-4 py-5 text-sm text-slate-400">
                                    回答者が複数行のテキストを入力します。
                                   </div>`
                                : `
                                    <div class="space-y-2">
                                        ${options.map((option, oi) => `
                                            <div class="flex items-center gap-2">
                                                <span class="text-slate-400">
                                                    ${q.type === 'multiple' ? '□' : '○'}
                                                </span>
                                                <input value="${this.util.escAttr(option)}"
                                                    oninput="App.actions.updateOption('${this.util.escAttr(q.id)}',${oi},this.value)"
                                                    class="flex-1 border border-slate-200 rounded-lg px-3 py-2">
                                                <button onclick="App.actions.removeOption('${this.util.escAttr(q.id)}',${oi})"
                                                    class="text-slate-400 hover:text-red-500">×</button>
                                            </div>`).join('')}

                                        <button onclick="App.actions.addOption('${this.util.escAttr(q.id)}')"
                                            class="text-sm text-indigo-600 mt-2">
                                            ＋ 選択肢追加
                                        </button>

                                        <label class="flex items-center gap-2 text-sm mt-3">
                                            <input type="checkbox"
                                                ${q.other_enabled ? 'checked' : ''}
                                                onchange="App.actions.updateQuestion('${this.util.escAttr(q.id)}','other_enabled',this.checked)">
                                            「その他」を追加
                                        </label>
                                    </div>`}
                        </div>
                    </div>
                </div>`;
        },

        previewModal: function() {
            return `
                <div id="preview_modal" class="hidden fixed inset-0 z-50 bg-slate-900/50 p-5">
                    <div class="bg-white max-w-4xl mx-auto h-[90vh] rounded-2xl shadow-2xl overflow-hidden">
                        <div class="h-14 border-b flex items-center justify-between px-5">
                            <div class="font-bold">プレビュー</div>
                            <div class="flex gap-2">
                                <button onclick="App.actions.previewMode(false)"
                                    class="px-3 py-1.5 rounded-lg bg-slate-100">
                                    PC表示
                                </button>
                                <button onclick="App.actions.previewMode(true)"
                                    class="px-3 py-1.5 rounded-lg bg-slate-100">
                                    スマートフォン表示
                                </button>
                                <button onclick="App.actions.closePreview()"
                                    class="px-3 py-1.5 rounded-lg bg-slate-100">
                                    閉じる
                                </button>
                            </div>
                        </div>
                        <div id="preview_content" class="h-[calc(90vh-56px)] overflow-auto bg-slate-50 p-5"></div>
                    </div>
                </div>`;
        },

        responseModal: function() {
            return `
                <div id="response_modal" class="hidden fixed inset-0 z-[60] bg-slate-900/50 p-5">
                    <div class="bg-white max-w-3xl mx-auto max-h-[90vh] rounded-2xl shadow-2xl overflow-hidden">
                        <div class="h-14 border-b flex items-center justify-between px-5">
                            <div class="font-bold">回答詳細</div>
                            <button onclick="App.actions.closeResponseModal()"
                                class="px-3 py-1.5 rounded-lg bg-slate-100">閉じる</button>
                        </div>
                        <div id="response_detail" class="p-6 max-h-[calc(90vh-56px)] overflow-auto"></div>
                    </div>
                </div>`;
        },

        mail: function() {
            const survey = this.state.survey;
            let customers = [...(this.state.data.customers || [])];

            const keyword = this.state.customerFilter.trim().toLowerCase();

            if (keyword) {
                customers = customers.filter(c =>
                    String(c.company || '').toLowerCase().includes(keyword) ||
                    String(c.name || '').toLowerCase().includes(keyword) ||
                    String(c.email || '').toLowerCase().includes(keyword)
                );
            }

            const logs = this.state.data.mail_logs || [];

            return `
                <div class="mb-6">
                    <button onclick="App.actions.goList()"
                        class="text-sm text-slate-500 hover:text-indigo-600">
                        ホーム ＞ アンケート一覧
                    </button>
                    <div class="flex items-center justify-between mt-3">
                        <div>
                            <h1 class="text-2xl font-bold">顧客選択・送信・送信履歴</h1>
                            <p class="text-sm text-slate-500 mt-1">${this.util.e(survey.title)}</p>
                        </div>
                        <button onclick="App.actions.syncCustomers()"
                            class="px-4 py-2.5 border border-slate-300 bg-white rounded-xl">
                            顧客一覧を更新
                        </button>
                    </div>
                </div>

                ${(customers.some(c => c.source === 'web' && c.kintone_status === 'unregistered'))
                    ? `<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 mb-5">
                        kintone未登録の回答者が存在します。
                       </div>`
                    : ''}

                <div class="grid lg:grid-cols-[1fr_350px] gap-5">
                    <section class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                        <div class="p-4 border-b flex gap-3">
                            <input id="customer_filter"
                                value="${this.util.escAttr(this.state.customerFilter)}"
                                oninput="App.actions.customerSearch(this.value)"
                                placeholder="顧客名・会社名・メールアドレスで検索"
                                class="flex-1 border border-slate-300 rounded-xl px-4 py-2.5">

                            <label class="flex items-center gap-2 px-3 text-sm">
                                <input id="select_all" type="checkbox"
                                    onchange="App.actions.selectAll(this.checked)">
                                全選択
                            </label>
                        </div>

                        <div class="overflow-x-auto">
                            <table id="customer_table" class="w-full min-w-[1050px] text-sm">
                                <thead class="bg-slate-50 border-b">
                                    <tr>
                                        <th class="px-4 py-3 text-left">選択</th>
                                        <th class="px-4 py-3 text-left">会社名 / 氏名</th>
                                        <th class="px-4 py-3 text-left">メール</th>
                                        <th class="px-4 py-3 text-left">電話</th>
                                        <th class="px-4 py-3 text-left">送信履歴</th>
                                        <th class="px-4 py-3 text-left">回答</th>
                                        <th class="px-4 py-3 text-left">kintone</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    ${customers.map(customer => {
                                        const selected = this.state.selectedCustomers.includes(customer.id);
                                        const sentLog = [...logs]
                                            .filter(l =>
                                                l.survey_id === survey.id &&
                                                l.customer_id === customer.id
                                            ).pop();

                                        const disabled = customer.source === 'web';

                                        return `
                                            <tr>
                                                <td class="px-4 py-4">
                                                    <input type="checkbox"
                                                        ${selected ? 'checked' : ''}
                                                        ${disabled ? 'disabled' : ''}
                                                        onchange="App.actions.toggleCustomer('${this.util.escAttr(customer.id)}',this.checked)">
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div class="font-bold">${this.util.e(customer.company)}</div>
                                                    <div>${this.util.e(customer.name)}</div>
                                                    <div class="text-xs text-slate-400">${this.util.e(customer.department)}</div>
                                                </td>
                                                <td class="px-4 py-4">${this.util.e(customer.email)}</td>
                                                <td class="px-4 py-4">${this.util.e(customer.phone)}</td>
                                                <td class="px-4 py-4">
                                                    <div>${customer.sent_at ? this.util.e(customer.sent_at) : '未送信'}</div>
                                                    <div class="text-xs text-slate-400">${customer.send_count || 0} 回</div>
                                                    ${sentLog ? `
                                                        <button onclick="App.actions.showMailLog('${this.util.escAttr(sentLog.id)}')"
                                                            class="text-indigo-600 text-xs mt-1">
                                                            送信文を確認
                                                        </button>` : ''}
                                                </td>
                                                <td class="px-4 py-4">
                                                    <span class="px-2 py-1 rounded-full text-xs
                                                        ${customer.answer_status === 'answered'
                                                            ? 'bg-emerald-50 text-emerald-700'
                                                            : 'bg-slate-100 text-slate-600'}">
                                                        ${customer.answer_status === 'answered'
                                                            ? '回答済み'
                                                            : '送信済み（未回答）'}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-4">
                                                    ${customer.kintone_status === 'registered'
                                                        ? `<span class="text-emerald-600 text-xs">✓ 登録完了</span>`
                                                        : `<button onclick="App.actions.markKintone('${this.util.escAttr(customer.id)}')"
                                                            class="text-xs px-2 py-1 rounded bg-amber-50 text-amber-700">
                                                            kintone登録完了
                                                           </button>`}
                                                </td>
                                            </tr>`;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <aside class="bg-white border border-slate-200 rounded-2xl p-5 h-fit">
                        <h2 class="font-bold text-lg mb-4">メール送信</h2>

                        <label class="text-sm font-medium">テンプレート</label>
                        <select id="template_type"
                            onchange="App.actions.templateChanged(this.value)"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2.5 mt-2 mb-4">
                            <option value="initial">初回送信</option>
                            <option value="reminder">再送・リマインド</option>
                        </select>

                        <label class="text-sm font-medium">件名</label>
                        <input id="mail_subject"
                            value="${this.util.escAttr(
                                this.state.mailSubject ||
                                (survey.title + 'のご案内')
                            )}"
                            oninput="App.state.mailSubject=this.value"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2.5 mt-2 mb-4">

                        <label class="text-sm font-medium">本文</label>
                        <textarea id="mail_body" rows="10"
                            oninput="App.state.mailBody=this.value"
                            class="w-full border border-slate-300 rounded-xl px-3 py-2.5 mt-2 mb-3">${this.util.e(
                                this.state.mailBody ||
                                '{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}\n\nよろしくお願いいたします。'
                            )}</textarea>

                        <div class="text-xs text-slate-400 mb-4">
                            使用可能な変数：{顧客名} / {アンケートURL}
                        </div>

                        <div class="bg-slate-50 rounded-xl p-3 text-sm mb-4">
                            選択中：<b>${this.state.selectedCustomers.length}</b> 件
                        </div>

                        <button onclick="App.actions.sendMail()"
                            class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-3 rounded-xl font-semibold">
                            一括送信実行
                        </button>

                        <div class="mt-7">
                            <h3 class="font-semibold mb-3">一括送信ログ</h3>
                            <div class="space-y-2 max-h-64 overflow-auto">
                                ${logs.filter(l => l.survey_id === survey.id).slice().reverse().map(log => `
                                    <div class="border-b pb-2">
                                        <div class="text-xs text-slate-400">${this.util.e(log.sent_at)}</div>
                                        <div class="text-sm">${this.util.e(log.subject)}</div>
                                        <button onclick="App.actions.showMailLog('${this.util.escAttr(log.id)}')"
                                            class="text-xs text-indigo-600">
                                            送信文を確認
                                        </button>
                                    </div>`).join('') || '<div class="text-sm text-slate-400">履歴はありません。</div>'}
                            </div>
                        </div>
                    </aside>
                </div>`;
        },

        analytics: function() {
            const survey = this.state.survey;
            const responses = this.util.responsesFor(survey.id);
            const sent = this.util.sentCustomerCount(survey.id);
            const registeredResponses = responses.filter(r =>
                r.customer_id && this.util.findCustomer(r.customer_id)
            ).length;
            const unregistered = responses.length - registeredResponses;
            const answeredTarget = responses.filter(r => r.customer_id).length;
            const unanswered = Math.max(sent - answeredTarget, 0);
            const rate = sent ? ((answeredTarget / sent) * 100).toFixed(1) : '0.0';

            const questions = this.util.questionListWithNumbers(survey);

            if (!this.state.selectedQuestions.length) {
                this.state.selectedQuestions = questions.map(q => q.id);
            }

            return `
                <div class="mb-6">
                    <button onclick="App.actions.goList()"
                        class="text-sm text-slate-500 hover:text-indigo-600">
                        ホーム ＞ アンケート一覧
                    </button>

                    <div class="flex items-center justify-between mt-3">
                        <div>
                            <h1 class="text-2xl font-bold">回答集計・分析</h1>
                            <p class="text-sm text-slate-500 mt-1">${this.util.e(survey.title)}</p>
                        </div>

                        <div class="flex gap-2">
                            <button onclick="App.actions.downloadCSV('${this.util.escAttr(survey.id)}')"
                                class="px-4 py-2.5 rounded-xl border bg-white">
                                CSV出力
                            </button>
                            <button onclick="App.actions.printAnalytics()"
                                class="px-4 py-2.5 rounded-xl border bg-white">
                                PDF / 印刷
                            </button>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
                    ${[
                        ['送信対象者数', sent + ' 人'],
                        ['回答数', responses.length + ' 件'],
                        ['未登録顧客からの回答数', unregistered + ' 件'],
                        ['未回答数', unanswered + ' 人'],
                        ['回答率', rate + ' %']
                    ].map(card => `
                        <div class="bg-white border border-slate-200 rounded-2xl p-4">
                            <div class="text-xs text-slate-400">${card[0]}</div>
                            <div class="text-2xl font-bold mt-2">${card[1]}</div>
                        </div>`).join('')}
                </div>

                <div class="grid lg:grid-cols-[280px_1fr] gap-5">
                    <aside class="bg-white border border-slate-200 rounded-2xl p-5 h-fit">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="font-bold">設問絞り込み</h2>
                            <div class="flex gap-2">
                                <button onclick="App.actions.selectAllQuestions(true)"
                                    class="text-xs text-indigo-600">全選択</button>
                                <button onclick="App.actions.selectAllQuestions(false)"
                                    class="text-xs text-slate-500">全解除</button>
                            </div>
                        </div>

                        <div class="space-y-3">
                            ${questions.map(q => `
                                <label class="flex gap-2 items-start text-sm">
                                    <input type="checkbox"
                                        ${this.state.selectedQuestions.includes(q.id) ? 'checked' : ''}
                                        onchange="App.actions.toggleQuestion('${this.util.escAttr(q.id)}',this.checked)"
                                        class="mt-1">
                                    <span>
                                        <b>${this.util.e(q.number)}</b>
                                        ${this.util.e(q.text)}
                                        <span class="block text-xs text-slate-400">${this.util.typeLabel(q.type)}</span>
                                    </span>
                                </label>`).join('')}
                        </div>
                    </aside>

                    <section class="space-y-5">
                        ${responses.length === 0
                            ? `<div class="bg-white border border-slate-200 rounded-2xl p-16 text-center text-slate-400">
                                現在、回答データはありません
                               </div>`
                            : ''}

                        ${questions
                            .filter(q => this.state.selectedQuestions.includes(q.id))
                            .map(q => this.questionAnalysis(q, responses)).join('')}

                        ${responses.length
                            ? this.responseTable(survey, responses, questions)
                            : ''}
                    </section>
                </div>

                ${this.responseModal()}`;
        },

        questionAnalysis: function(question, responses) {
            const values = [];

            responses.forEach(response => {
                let value = response.answers?.[question.id];

                if (Array.isArray(value)) {
                    value.forEach(v => values.push(String(v)));
                } else if (value !== undefined && value !== null && String(value) !== '') {
                    values.push(String(value));
                }
            });

            if (question.type === 'text') {
                return `
                    <div class="bg-white border border-slate-200 rounded-2xl p-5">
                        <div class="flex justify-between mb-4">
                            <h2 class="font-bold">${this.util.e(question.number)} ${this.util.e(question.text)}</h2>
                            <span class="text-xs bg-slate-100 px-2 py-1 rounded">
                                自由記述 ${values.length}件
                            </span>
                        </div>

                        <div class="max-h-72 overflow-auto space-y-3">
                            ${values.map(value => `
                                <div class="border-l-2 border-indigo-400 pl-4">
                                    <div class="text-sm">${this.util.e(value)}</div>
                                </div>`).join('')}
                        </div>
                    </div>`;
            }

            const total = values.length || 1;

            return `
                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <h2 class="font-bold mb-5">
                        ${this.util.e(question.number)} ${this.util.e(question.text)}
                    </h2>

                    <div class="space-y-4">
                        ${(question.options || []).map(option => {
                            const count = values.filter(v => v === option).length;
                            const percent = Math.round((count / total) * 100);

                            return `
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span>${this.util.e(option)}</span>
                                        <span>${count} 件 / ${percent}%</span>
                                    </div>
                                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                        <div class="h-full bg-indigo-500 rounded-full"
                                            style="width:${percent}%"></div>
                                    </div>
                                </div>`;
                        }).join('')}
                    </div>
                </div>`;
        },

        responseTable: function(survey, responses, questions) {
            const keyword = this.state.responseFilter.trim().toLowerCase();

            const filtered = responses.filter(r =>
                !keyword ||
                String(r.company || '').toLowerCase().includes(keyword) ||
                String(r.name || '').toLowerCase().includes(keyword)
            );

            return `
                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                    <div class="p-5 border-b flex items-center justify-between">
                        <div>
                            <h2 class="font-bold">個別回答一覧</h2>
                            <div class="text-xs text-slate-400 mt-1">${filtered.length} 件</div>
                        </div>
                        <input id="response_filter"
                            value="${this.util.escAttr(this.state.responseFilter)}"
                            oninput="App.actions.responseSearch(this.value)"
                            placeholder="会社名・氏名"
                            class="border border-slate-300 rounded-xl px-3 py-2">
                    </div>

                    <div class="overflow-x-auto">
                        <table id="response_table" class="w-full min-w-[800px] text-sm">
                            <thead class="bg-slate-50 border-b">
                                <tr>
                                    <th class="px-5 py-3 text-left">会社名</th>
                                    <th class="px-5 py-3 text-left">氏名</th>
                                    <th class="px-5 py-3 text-left">回答日時</th>
                                    <th class="px-5 py-3 text-left">回答</th>
                                    <th class="px-5 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                ${filtered.map(response => {
                                    const preview = questions.slice(0, 2).map(q => {
                                        let value = response.answers?.[q.id] ?? '';
                                        if (Array.isArray(value)) value = value.join(', ');
                                        return `${q.number}: ${value}`;
                                    }).join(' / ');

                                    return `
                                        <tr>
                                            <td class="px-5 py-4 font-semibold">${this.util.e(response.company)}</td>
                                            <td class="px-5 py-4">${this.util.e(response.name)}</td>
                                            <td class="px-5 py-4">${this.util.e(response.answered_at)}</td>
                                            <td class="px-5 py-4 max-w-[450px] truncate">${this.util.e(preview)}</td>
                                            <td class="px-5 py-4 text-right">
                                                <button onclick="App.actions.showResponse('${this.util.escAttr(response.id)}')"
                                                    class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700">
                                                    全回答を表示
                                                </button>
                                            </td>
                                        </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>`;
        },

        settings: function() {
            const settings = this.state.data.settings || {};
            const address = Array.isArray(settings.field_address)
                ? settings.field_address
                : [];

            return `
                <div class="max-w-5xl">
                    <div class="mb-6">
                        <div class="text-sm text-slate-400">ホーム ＞ システム設定</div>
                        <h1 class="text-2xl font-bold mt-2">kintone連携設定</h1>
                        <p class="text-sm text-slate-500 mt-1">
                            顧客管理アプリとの接続情報とフィールドマッピングを設定します。
                        </p>
                    </div>

                    <div class="space-y-5">
                        <section class="bg-white border border-slate-200 rounded-2xl p-6">
                            <h2 class="font-bold text-lg mb-5">接続・認証設定</h2>

                            <div class="grid md:grid-cols-2 gap-5">
                                <div>
                                    <label class="text-sm font-medium">サブドメイン</label>
                                    <input id="setting_subdomain"
                                        value="${this.util.escAttr(settings.subdomain || '')}"
                                        placeholder="xxxx または xxxx.cybozu.com"
                                        class="w-full border rounded-xl px-3 py-2.5 mt-2">
                                </div>

                                <div>
                                    <label class="text-sm font-medium">顧客管理アプリID</label>
                                    <input id="setting_app_id"
                                        value="${this.util.escAttr(settings.app_id || '')}"
                                        class="w-full border rounded-xl px-3 py-2.5 mt-2">
                                </div>

                                <div>
                                    <label class="text-sm font-medium">ログイン名</label>
                                    <input id="setting_login_name"
                                        value="${this.util.escAttr(settings.login_name || '')}"
                                        class="w-full border rounded-xl px-3 py-2.5 mt-2">
                                </div>

                                <div>
                                    <label class="text-sm font-medium">パスワード</label>
                                    <input id="setting_password" type="password"
                                        value="${this.util.escAttr(settings.password || '')}"
                                        class="w-full border rounded-xl px-3 py-2.5 mt-2">
                                </div>

                                <div>
                                    <label class="text-sm font-medium">Proxyサーバ</label>
                                    <input id="setting_proxy"
                                        value="${this.util.escAttr(settings.proxy || '')}"
                                        placeholder="host名:port番号"
                                        class="w-full border rounded-xl px-3 py-2.5 mt-2">
                                </div>

                                <label class="flex items-center gap-2 mt-7">
                                    <input id="setting_ssl_verify" type="checkbox"
                                        ${settings.ssl_verify ? 'checked' : ''}>
                                    SSL証明書検証を行う
                                </label>
                            </div>

                            <div class="flex gap-3 mt-6">
                                <button onclick="App.actions.testKintone()"
                                    class="px-4 py-2.5 rounded-xl border border-slate-300">
                                    接続確認
                                </button>

                                <button onclick="App.actions.fetchKintoneFields()"
                                    class="px-4 py-2.5 rounded-xl bg-indigo-600 text-white">
                                    項目一覧を取得
                                </button>

                                <button onclick="App.actions.syncCustomers()"
                                    class="px-4 py-2.5 rounded-xl bg-slate-800 text-white">
                                    顧客データを同期
                                </button>
                            </div>

                            <div id="field_message" class="mt-4 text-sm"></div>
                        </section>

                        <section class="bg-white border border-slate-200 rounded-2xl p-6">
                            <h2 class="font-bold text-lg mb-2">フィールドマッピング</h2>
                            <p class="text-sm text-slate-500 mb-5">
                                「項目一覧を取得」で取得した日本語フィールド名から選択してください。
                            </p>

                            <div id="kintone_mapping" class="grid md:grid-cols-2 gap-5">
                                ${this.mappingSelect('field_company', '会社名', settings.field_company)}
                                ${this.mappingSelect('field_name', '氏名', settings.field_name)}
                                ${this.mappingSelect('field_email', 'メールアドレス', settings.field_email)}
                                ${this.mappingSelect('field_department', '部署名', settings.field_department)}
                                ${this.mappingSelect('field_phone', '電話番号', settings.field_phone)}

                                <div>
                                    <label class="text-sm font-medium">住所</label>
                                    <select id="field_address" multiple
                                        class="w-full border rounded-xl px-3 py-2.5 mt-2 h-36">
                                        ${this.state.kintoneFields.map(field => `
                                            <option value="${this.util.escAttr(field.code)}"
                                                ${address.includes(field.code) ? 'selected' : ''}>
                                                ${this.util.e(field.label)} (${this.util.e(field.code)})
                                            </option>`).join('')}
                                    </select>
                                    <div class="text-xs text-slate-400 mt-1">
                                        Ctrl / Commandキーで複数選択できます。
                                    </div>
                                </div>
                            </div>

                            <button onclick="App.actions.saveSettings()"
                                class="mt-6 bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-semibold">
                                設定を保存
                            </button>
                        </section>
                    </div>
                </div>`;
        },

        mappingSelect: function(id, label, value) {
            return `
                <div>
                    <label class="text-sm font-medium">${label}</label>
                    <select id="${id}"
                        class="w-full border rounded-xl px-3 py-2.5 mt-2">
                        <option value="">未設定</option>
                        ${this.state.kintoneFields.map(field => `
                            <option value="${this.util.escAttr(field.code)}"
                                ${field.code === value ? 'selected' : ''}>
                                ${this.util.e(field.label)} (${this.util.e(field.code)})
                            </option>`).join('')}
                    </select>
                </div>`;
        },

        afterRender: function() {
            if (this.state.screen === 'editor') {
                this.actions.initSortable();
            }
        }
    },

    actions: {
        goList: async function() {
            if (this.state.screen === 'editor' && this.state.dirty) {
                if (!this.util.confirm('未保存の変更があります。破棄して一覧へ戻りますか？')) {
                    return;
                }
            }

            this.state.screen = 'list';
            this.state.survey = null;
            this.state.dirty = false;
            this.render.app();
        },

        goSettings: function() {
            this.state.screen = 'settings';
            this.render.app();
        },

        logout: function() {
            this.util.toast('ログアウト機能は単一ファイル構成のため、管理セッションを利用しています。');
        },

        newSurvey: function() {
            this.state.survey = {
                id: this.util.uid('survey'),
                title: '',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: '',
                updated_at: '',
                numbering_mode: 'global',
                groups: [
                    {
                        id: this.util.uid('group'),
                        name: 'グループ1',
                        questions: []
                    }
                ],
                deleted: false
            };

            this.state.screen = 'editor';
            this.state.dirty = false;
            this.render.app();
        },

        editSurvey: function(id) {
            const source = (this.state.data.surveys || []).find(s => s.id === id);
            if (!source) return;

            this.state.survey = JSON.parse(JSON.stringify(source));
            this.state.survey.groups ||= [];
            this.state.screen = 'editor';
            this.state.dirty = false;
            this.render.app();
        },

        cancelEditor: function() {
            if (this.state.dirty &&
                !this.util.confirm('未保存の変更を破棄して一覧へ戻りますか？')) {
                return;
            }

            this.state.screen = 'list';
            this.state.survey = null;
            this.state.dirty = false;
            this.render.app();
        },

        updateSurveyField: function(key, value) {
            if (!this.state.survey) return;

            if (key === 'start_at' || key === 'end_at') {
                value = value.replace('T', ' ');
            }

            this.state.survey[key] = value;
            this.state.dirty = true;
        },

        addGroup: function() {
            const survey = this.state.survey;

            survey.groups.push({
                id: this.util.uid('group'),
                name: '新しいグループ',
                questions: []
            });

            this.state.dirty = true;
            this.render.app();
        },

        updateGroupName: function(groupId, value) {
            const group = this.state.survey.groups.find(g => g.id === groupId);
            if (!group) return;

            group.name = value;
            this.state.dirty = true;
        },

        deleteGroup: function(groupId) {
            if (!this.util.confirm('このグループと内包される質問を削除しますか？')) {
                return;
            }

            this.state.survey.groups =
                this.state.survey.groups.filter(g => g.id !== groupId);

            this.state.dirty = true;
            this.render.app();
        },

        addQuestion: function(groupId) {
            const group = this.state.survey.groups.find(g => g.id === groupId);
            if (!group) return;

            group.questions.push({
                id: this.util.uid('question'),
                text: '',
                type: 'single',
                required: false,
                options: ['選択肢1', '選択肢2'],
                other_enabled: false
            });

            this.state.dirty = true;
            this.render.app();
        },

        deleteQuestion: function(questionId) {
            if (!this.util.confirm('この質問を削除しますか？')) return;

            for (const group of this.state.survey.groups) {
                group.questions =
                    (group.questions || []).filter(q => q.id !== questionId);
            }

            this.state.dirty = true;
            this.render.app();
        },

        updateQuestion: function(questionId, key, value) {
            const q = this.findQuestion(questionId);
            if (!q) return;

            q[key] = value;

            if (key === 'type' && value === 'text') {
                q.options = [];
                q.other_enabled = false;
            }

            if (key === 'type' && value !== 'text' && !q.options?.length) {
                q.options = ['選択肢1', '選択肢2'];
            }

            this.state.dirty = true;

            if (key === 'type') {
                this.render.app();
            }
        },

        findQuestion: function(id) {
            for (const group of this.state.survey.groups) {
                const q = (group.questions || []).find(q => q.id === id);
                if (q) return q;
            }
            return null;
        },

        addOption: function(questionId) {
            const q = this.findQuestion(questionId);
            if (!q) return;

            q.options ||= [];
            q.options.push('新しい選択肢');
            this.state.dirty = true;
            this.render.app();
        },

        updateOption: function(questionId, index, value) {
            const q = this.findQuestion(questionId);
            if (!q || !q.options?.[index] === undefined) return;

            q.options[index] = value;
            this.state.dirty = true;
        },

        removeOption: function(questionId, index) {
            const q = this.findQuestion(questionId);
            if (!q || !q.options) return;

            q.options.splice(index, 1);
            this.state.dirty = true;
            this.render.app();
        },

        questionNumber: function(id) {
            let globalIndex = 0;

            for (let gi = 0; gi < this.state.survey.groups.length; gi++) {
                const group = this.state.survey.groups[gi];

                for (let qi = 0; qi < group.questions.length; qi++) {
                    globalIndex++;

                    if (group.questions[qi].id === id) {
                        if (this.state.survey.numbering_mode === 'group') {
                            return `Q${gi + 1}-${qi + 1}`;
                        }

                        return `Q${globalIndex}`;
                    }
                }
            }

            return 'Q?';
        },

        questionListWithNumbers: function(survey) {
            const result = [];
            let n = 0;

            (survey.groups || []).forEach((group, gi) => {
                (group.questions || []).forEach((q, qi) => {
                    n++;
                    result.push({
                        ...q,
                        number: survey.numbering_mode === 'group'
                            ? `Q${gi + 1}-${qi + 1}`
                            : `Q${n}`
                    });
                });
            });

            return result;
        },

        initSortable: function() {
            if (typeof Sortable === 'undefined') return;

            const editor = document.getElementById('question_editor');

            if (editor && !editor.dataset.sortable) {
                new Sortable(editor, {
                    animation: 180,
                    handle: '.group-handle',
                    ghostClass: 'opacity-40',
                    onEnd: () => {
                        const ids = [...editor.querySelectorAll('.group-item')]
                            .map(el => el.dataset.groupId);

                        this.state.survey.groups.sort(
                            (a, b) => ids.indexOf(a.id) - ids.indexOf(b.id)
                        );

                        this.state.dirty = true;
                        this.render.app();
                    }
                });

                editor.dataset.sortable = '1';
            }

            document.querySelectorAll('.question-list').forEach(list => {
                if (list.dataset.sortable) return;

                new Sortable(list, {
                    group: 'surveyQuestions',
                    animation: 180,
                    handle: '.question-handle',
                    ghostClass: 'opacity-40',
                    onEnd: event => {
                        const questionId = event.item.dataset.questionId;

                        let movedQuestion = null;

                        for (const group of this.state.survey.groups) {
                            const idx = group.questions.findIndex(q => q.id === questionId);

                            if (idx >= 0) {
                                movedQuestion = group.questions.splice(idx, 1)[0];
                                break;
                            }
                        }

                        if (!movedQuestion) return;

                        const targetGroupId = event.to.dataset.groupId;
                        const targetGroup = this.state.survey.groups.find(
                            g => g.id === targetGroupId
                        );

                        if (!targetGroup) return;

                        const items = [...event.to.querySelectorAll('.question-item')]
                            .map(el => el.dataset.questionId);

                        let targetIndex = items.indexOf(questionId);

                        if (targetIndex < 0) {
                            targetIndex = targetGroup.questions.length;
                        }

                        targetGroup.questions.splice(targetIndex, 0, movedQuestion);

                        this.state.dirty = true;
                        this.render.app();
                    }
                });

                list.dataset.sortable = '1';
            });
        },

        saveEditor: async function() {
            if (!this.state.survey) return;

            this.util.showLoading('保存中...');

            try {
                const result = await this.api.saveSurvey(this.state.survey);

                if (!result.ok) {
                    throw new Error(result.message);
                }

                const index = this.state.data.surveys.findIndex(
                    s => s.id === result.survey.id
                );

                if (index >= 0) {
                    this.state.data.surveys[index] = result.survey;
                } else {
                    this.state.data.surveys.push(result.survey);
                }

                this.state.dirty = false;
                this.state.screen = 'list';
                this.state.survey = null;

                this.util.toast('アンケートを保存しました。');
                this.render.app();
            } catch (error) {
                this.util.toast(error.message, 'error');
            } finally {
                this.util.hideLoading();
            }
        },

        preview: function() {
            const modal = document.getElementById('preview_modal');
            if (!modal) return;

            modal.classList.remove('hidden');
            this.actions.previewMode(this.state.previewMobile);
        },

        closePreview: function() {
            document.getElementById('preview_modal')?.classList.add('hidden');
        },

        previewMode: function(mobile) {
            this.state.previewMobile = mobile;

            const content = document.getElementById('preview_content');
            if (!content) return;

            const survey = this.state.survey;

            content.innerHTML = `
                <div class="${mobile ? 'max-w-[390px]' : 'max-w-3xl'} mx-auto bg-white rounded-2xl p-6 shadow-sm">
                    <h1 class="text-2xl font-bold mb-8">${this.util.e(survey.title || '無題のアンケート')}</h1>

                    ${(survey.groups || []).map((group, gi) => `
                        <section class="border-t pt-6 mb-8">
                            <h2 class="text-lg font-bold mb-5">${this.util.e(group.name)}</h2>

                            ${(group.questions || []).map((q, qi) => `
                                <div class="mb-7">
                                    <div class="font-semibold mb-3">
                                        ${this.util.e(this.actions.questionNumber(q.id))}
                                        ${this.util.e(q.text || '質問文未入力')}
                                        ${q.required ? '<span class="text-red-500 ml-1">必須</span>' : ''}
                                    </div>

                                    ${q.type === 'text'
                                        ? `<textarea rows="4" disabled
                                            class="w-full border rounded-xl p-3 bg-slate-50"></textarea>`
                                        : (q.options || []).map(option => `
                                            <label class="flex gap-3 py-2">
                                                <input disabled type="${q.type === 'multiple' ? 'checkbox' : 'radio'}">
                                                <span>${this.util.e(option)}</span>
                                            </label>`).join('')}

                                    ${q.other_enabled ? `
                                        <div class="mt-2 border rounded-xl p-3 text-slate-400">
                                            その他
                                        </div>` : ''}
                                </div>`).join('')}
                        </section>`).join('')}

                    <button onclick="App.actions.previewSubmit()"
                        class="w-full bg-indigo-600 text-white rounded-xl py-3">
                        送信する
                    </button>
                </div>`;
        },

        previewSubmit: function() {
            alert('これはプレビューです。実際の送信は行われません。');
        },

        analytics: function(id) {
            const survey = this.state.data.surveys.find(s => s.id === id);
            if (!survey) return;

            this.state.survey = JSON.parse(JSON.stringify(survey));
            this.state.surveyId = id;
            this.state.screen = 'analytics';
            this.state.selectedQuestions = [];
            this.render.app();
        },

        mail: function(id) {
            const survey = this.state.data.surveys.find(s => s.id === id);
            if (!survey) return;

            this.state.survey = JSON.parse(JSON.stringify(survey));
            this.state.surveyId = id;
            this.state.screen = 'mail';
            this.state.selectedCustomers = [];
            this.state.customerFilter = '';
            this.state.mailSubject = survey.title + 'のご案内';
            this.state.mailBody =
                '{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}\n\nよろしくお願いいたします。';

            this.render.app();
        },

        stopSurvey: async function(id) {
            if (!this.util.confirm('アンケートを停止しますか？')) return;

            this.util.showLoading('停止しています...');

            try {
                const result = await this.api.changeStatus(id, 'ended');

                if (!result.ok) throw new Error(result.message);

                const survey = this.state.data.surveys.find(s => s.id === id);
                if (survey) {
                    survey.status = 'ended';
                    survey.updated_at = new Date().toISOString().slice(0,19).replace('T',' ');
                }

                this.util.toast('アンケートを停止しました。');
                this.render.app();
            } catch (error) {
                this.util.toast(error.message, 'error');
            } finally {
                this.util.hideLoading();
            }
        },

        deleteSurvey: async function(id) {
            if (!this.util.confirm('このアンケートを削除しますか？')) return;

            try {
                const result = await this.api.deleteSurvey(id);

                if (!result.ok) throw new Error(result.message);

                const survey = this.state.data.surveys.find(s => s.id === id);
                if (survey) survey.deleted = true;

                this.util.toast('アンケートを削除しました。');
                this.render.app();
            } catch (error) {
                this.util.toast(error.message, 'error');
            }
        },

        duplicateSurvey: async function(id) {
            try {
                const result = await this.api.duplicateSurvey(id);

                if (!result.ok) throw new Error(result.message);

                this.state.data.surveys.push(result.survey);

                this.util.toast('アンケートを複製しました。');
                this.render.app();
            } catch (error) {
                this.util.toast(error.message, 'error');
            }
        },

        search: function(value) {
            this.state.keyword = value;
            this.render.app();
        },

        searchKey: function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.state.keyword = event.target.value;
                this.render.app();
            }
        },

        toggleStatusFilter: function(value) {
            this.state.statusFilter = value;
            this.render.app();
        },

        changeSort: function(value) {
            this.state.sort = value;
            this.render.app();
        },

        customerSearch: function(value) {
            this.state.customerFilter = value;
            this.render.app();
        },

        toggleCustomer: function(id, checked) {
            if (checked) {
                if (!this.state.selectedCustomers.includes(id)) {
                    this.state.selectedCustomers.push(id);
                }
            } else {
                this.state.selectedCustomers =
                    this.state.selectedCustomers.filter(x => x !== id);
            }

            this.render.app();
        },

        selectAll: function(checked) {
            const customers = (this.state.data.customers || [])
                .filter(c => c.source !== 'web');

            this.state.selectedCustomers = checked
                ? customers.map(c => c.id)
                : [];

            this.render.app();
        },

        templateChanged: function(type) {
            this.state.template_type = type;

            if (type === 'reminder') {
                this.state.mailSubject = this.state.survey.title + 'のリマインド';
                this.state.mailBody =
                    '{顧客名} 様\n\nまだアンケートへの回答がお済みでない場合は、以下よりご回答ください。\n\n{アンケートURL}\n\nよろしくお願いいたします。';
            } else {
                this.state.mailSubject = this.state.survey.title + 'のご案内';
                this.state.mailBody =
                    '{顧客名} 様\n\nアンケートへのご協力をお願いいたします。\n\n{アンケートURL}\n\nよろしくお願いいたします。';
            }

            this.render.app();
        },

        sendMail: async function() {
            const ids = this.state.selectedCustomers;

            if (!ids.length) {
                this.util.toast('送信対象を選択してください。', 'warning');
                return;
            }

            const alreadySent = ids.filter(id => {
                const customer = this.util.findCustomer(id);
                return Number(customer?.send_count || 0) > 0;
            });

            if (
                alreadySent.length &&
                !this.util.confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )
            ) {
                return;
            }

            const subject =
                document.getElementById('mail_subject')?.value ||
                this.state.mailSubject ||
                '';

            const body =
                document.getElementById('mail_body')?.value ||
                this.state.mailBody ||
                '';

            if (!subject.trim() || !body.trim()) {
                this.util.toast('件名と本文を入力してください。', 'warning');
                return;
            }

            this.util.showLoading('メール送信中...');

            try {
                const result = await this.api.sendMail(
                    this.state.survey.id,
                    ids,
                    subject,
                    body,
                    document.getElementById('template_type')?.value || 'initial'
                );

                if (!result.ok) throw new Error(result.message);

                const refreshed = await this.api.load();
                this.state.data = refreshed.data;
                this.state.selectedCustomers = [];

                this.util.toast(
                    result.message + (result.failed ? ' 失敗: ' + result.failed + ' 件' : '')
                );

                this.render.app();
            } catch (error) {
                this.util.toast(error.message, 'error');
            } finally {
                this.util.hideLoading();
            }
        },

        syncCustomers: async function() {
            this.util.showLoading('kintoneから顧客情報を取得中...');

            try {
                const result = await this.api.syncCustomers();

                if (!result.ok) throw new Error(result.message);

                const refreshed = await this.api.load();
                this.state.data = refreshed.data;

                this.util.toast(result.message);
                this.render.app();
            } catch (error) {
                this.util.toast(error.message, 'error');
            } finally {
                this.util.hideLoading();
            }
        },

        markKintone: async function(id) {
            try {
                const result = await this.api.markKintone(id);

                if (!result.ok) throw new Error(result.message);

                const customer = this.util.findCustomer(id);
                if (customer) customer.kintone_status = 'registered';

                this.util.toast('kintone登録完了として更新しました。');
                this.render.app();
            } catch (error) {
                this.util.toast(error.message, 'error');
            }
        },

        showMailLog: function(id) {
            const log = (this.state.data.mail_logs || []).find(l => l.id === id);
            if (!log) return;

            const overlay = document.createElement('div');
            overlay.className =
                'fixed inset-0 z-[80] bg-slate-900/50 p-5 flex items-center justify-center';

            overlay.innerHTML = `
                <div class="bg-white max-w-2xl w-full rounded-2xl shadow-2xl p-6">
                    <div class="flex justify-between items-center mb-5">
                        <h2 class="font-bold text-lg">送信済みメール</h2>
                        <button class="px-3 py-1 bg-slate-100 rounded-lg">閉じる</button>
                    </div>

                    <div class="text-sm text-slate-500 mb-3">
                        ${this.util.e(log.sent_at)}
                    </div>

                    <div class="font-semibold border-b pb-3 mb-3">
                        ${this.util.e(log.subject)}
                    </div>

                    <pre class="whitespace-pre-wrap text-sm bg-slate-50 rounded-xl p-4">${this.util.e(log.body)}</pre>
                </div>`;

            overlay.querySelector('button').onclick = () => overlay.remove();
            document.body.appendChild(overlay);
        },

        toggleQuestion: function(id, checked) {
            if (checked) {
                if (!this.state.selectedQuestions.includes(id)) {
                    this.state.selectedQuestions.push(id);
                }
            } else {
                this.state.selectedQuestions =
                    this.state.selectedQuestions.filter(x => x !== id);
            }

            this.render.app();
        },

        selectAllQuestions: function(checked) {
            const questions = this.actions.questionListWithNumbers(this.state.survey);

            this.state.selectedQuestions = checked
                ? questions.map(q => q.id)
                : [];

            this.render.app();
        },

        responseSearch: function(value) {
            this.state.responseFilter = value;
            this.render.app();
        },

        showResponse: function(id) {
            const response = (this.state.data.responses || []).find(r => r.id === id);
            if (!response) return;

            const survey = this.state.survey;
            const questions = this.actions.questionListWithNumbers(survey);

            const detail = document.getElementById('response_detail');
            const modal = document.getElementById('response_modal');

            if (!detail || !modal) return;

            detail.innerHTML = `
                <div class="mb-6">
                    <div class="text-xl font-bold">${this.util.e(response.company)} / ${this.util.e(response.name)}</div>
                    <div class="text-sm text-slate-400 mt-1">
                        ${this.util.e(response.answered_at)}
                    </div>
                </div>

                <div class="space-y-5">
                    ${questions.map(q => {
                        let value = response.answers?.[q.id] ?? '';

                        if (Array.isArray(value)) {
                            value = value.join(', ');
                        }

                        return `
                            <div class="border-b pb-4">
                                <div class="font-semibold mb-2">
                                    ${this.util.e(q.number)} ${this.util.e(q.text)}
                                </div>
                                <div class="bg-slate-50 rounded-xl p-3 whitespace-pre-wrap">
                                    ${this.util.e(value || '未回答')}
                                </div>
                            </div>`;
                    }).join('')}
                </div>`;

            modal.classList.remove('hidden');
        },

        closeResponseModal: function() {
            document.getElementById('response_modal')?.classList.add('hidden');
        },

        downloadCSV: function(id) {
            location.href =
                location.pathname +
                '?action=csv&survey_id=' + encodeURIComponent(id);
        },

        printAnalytics: function() {
            window.print();
        },

        fetchKintoneFields: async function() {
            const settings = this.readSettingsForm();
            const appId = document.getElementById('setting_app_id')?.value.trim();

            if (!appId) {
                this.util.toast('顧客管理アプリIDを入力してください。', 'warning');
                return;
            }

            this.util.showLoading('kintone項目一覧を取得中...');

            try {
                const result = await this.api.fields(settings, appId);

                if (!result.ok) throw new Error(result.message);

                this.state.kintoneFields = result.fields || [];

                document.getElementById('field_message').innerHTML = `
                    <span class="text-emerald-600">
                        ${result.fields.length} 件のフィールドを取得しました。
                    </span>`;

                this.render.app();
            } catch (error) {
                const message = document.getElementById('field_message');

                if (message) {
                    message.innerHTML =
                        `<span class="text-red-600">${this.util.e(error.message)}</span>`;
                } else {
                    this.util.toast(error.message, 'error');
                }
            } finally {
                this.util.hideLoading();
            }
        },

        readSettingsForm: function() {
            const current = this.state.data.settings || {};

            const addressSelect = document.getElementById('field_address');

            return {
                ...current,
                subdomain: document.getElementById('setting_subdomain')?.value.trim() || '',
                app_id: document.getElementById('setting_app_id')?.value.trim() || '',
                login_name: document.getElementById('setting_login_name')?.value.trim() || '',
                password: document.getElementById('setting_password')?.value || '',
                proxy: document.getElementById('setting_proxy')?.value.trim() || '',
                ssl_verify: document.getElementById('setting_ssl_verify')?.checked || false,
                field_company: document.getElementById('field_company')?.value || '',
                field_name: document.getElementById('field_name')?.value || '',
                field_email: document.getElementById('field_email')?.value || '',
                field_department: document.getElementById('field_department')?.value || '',
                field_phone: document.getElementById('field_phone')?.value || '',
                field_address: addressSelect
                    ? [...addressSelect.selectedOptions].map(option => option.value)
                    : current.field_address || []
            };
        },

        saveSettings: async function() {
            const settings = this.readSettingsForm();

            this.util.showLoading('設定を保存中...');

            try {
                const result = await this.api.saveSettings(settings);

                if (!result.ok) throw new Error(result.message);

                this.state.data.settings = settings;

                this.util.toast('kintone連携設定を保存しました。');
            } catch (error) {
                this.util.toast(error.message, 'error');
            } finally {
                this.util.hideLoading();
            }
        },

        testKintone: async function() {
            const settings = this.readSettingsForm();

            this.util.showLoading('kintone接続確認中...');

            try {
                const result = await this.api.testKintone(settings);

                if (!result.ok) {
                    throw new Error(
                        result.message +
                        (result.status ? ' (HTTP ' + result.status + ')' : '')
                    );
                }

                this.util.toast(result.message);
            } catch (error) {
                this.util.toast(error.message, 'error');
            } finally {
                this.util.hideLoading();
            }
        }
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => App.init(), {once: true});
} else {
    App.init();
}
</script>

</body>
</html>
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

/* =========================================================
 * PHP utility
 * ========================================================= */

function survey_guard_data(): array
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

function survey_read(): array
{
    $default = survey_guard_data();

    if (!is_file(SURVEY_STORAGE_FILE)) {
        survey_write($default);
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

function survey_write(array $data): bool
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

function survey_json(array $data, int $status = 200): never
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

function survey_csrf(): string
{
    if (
        empty($_SESSION['survey_csrf_token']) ||
        !is_string($_SESSION['survey_csrf_token'])
    ) {
        $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['survey_csrf_token'];
}

function survey_verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals(survey_csrf(), $token)
    ) {
        survey_json([
            'ok' => false,
            'message' => '不正なリクエストです。ページを再読み込みしてください。'
        ], 403);
    }
}

function survey_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function survey_now(): string
{
    return date('Y-m-d\TH:i:s');
}

function survey_post_json(string $key): ?array
{
    $raw = $_POST[$key] ?? null;

    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $value = json_decode($raw, true);

    return is_array($value) ? $value : null;
}

/* =========================================================
 * kintone
 * ========================================================= */

function survey_kintone_url(
    string $domain,
    string $endpoint,
    array $query = []
): string {
    $domain = trim($domain);

    $domain = preg_replace(
        '/^https?:\/\//i',
        '',
        $domain
    );

    $domain = preg_replace(
        '/\/.*$/',
        '',
        $domain
    );

    $domain = preg_replace(
        '/\.cybozu\.com$/i',
        '',
        $domain
    );

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

function survey_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();

        if (is_array($headers)) {
            return $headers;
        }
    }

    global $http_response_header;

    return isset($http_response_header) &&
        is_array($http_response_header)
        ? $http_response_header
        : [];
}

function survey_http_status(array $headers): int
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

/**
 * cURLを使用しないkintone API共通処理。
 *
 * GET:
 *   /k/v1/app/form/fields.json?app=123
 *
 * POST/PUT:
 *   JSON body
 */
function survey_kintone_request(
    array $settings,
    string $endpoint,
    string $method = 'GET',
    array $query = [],
    ?array $payload = null
): array {
    $domain = trim((string)($settings['subdomain'] ?? ''));

    if ($domain === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'kintoneのサブドメインが設定されていません。'
        ];
    }

    $url = survey_kintone_url(
        $domain,
        $endpoint,
        $query
    );

    $method = strtoupper($method);

    $login = (string)($settings['login_name'] ?? '');
    $password = (string)($settings['password'] ?? '');

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password)
    ];

    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers) . "\r\n",
        'ignore_errors' => true,
        'timeout' => 20
    ];

    /*
     * GETではcontentを絶対に設定しない。
     * これが /k/v1/app/form/fields.json のCB_IL02防止に重要。
     */
    if ($method !== 'GET' && $payload !== null) {
        $headers[] = 'Content-Type: application/json';

        $http['header'] =
            implode("\r\n", $headers) . "\r\n";

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'JSONデータの生成に失敗しました。'
            ];
        }

        $http['content'] = $json;
    }

    $verify = (bool)($settings['ssl_verify'] ?? false);

    $context_options = [
        'http' => $http,
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify
        ]
    ];

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        $context_options['http']['proxy'] =
            'tcp://' . $proxy;

        $context_options['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($context_options);

    $body = @file_get_contents(
        $url,
        false,
        $context
    );

    $headers_received = survey_response_headers();
    $status = survey_http_status($headers_received);

    if ($body === false) {
        return [
            'ok' => false,
            'status' => $status,
            'message' => 'kintone APIへの接続に失敗しました。',
            'url' => $url,
            'headers' => $headers_received
        ];
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $status,
            'message' =>
                'kintone APIからJSON以外のレスポンスが返されました。',
            'url' => $url,
            'raw' => mb_substr($body, 0, 2000),
            'headers' => $headers_received
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'status' => $status,
            'message' => (string)(
                $decoded['message'] ??
                'kintone APIエラーが発生しました。'
            ),
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

/* =========================================================
 * normalize
 * ========================================================= */

function survey_normalize(array $survey): array
{
    $survey['id'] = (string)(
        $survey['id'] ?? survey_id('survey')
    );

    $survey['title'] = (string)(
        $survey['title'] ?? ''
    );

    $survey['start_at'] = (string)(
        $survey['start_at'] ?? ''
    );

    $survey['end_at'] = (string)(
        $survey['end_at'] ?? ''
    );

    $status = $survey['status'] ?? 'draft';

    $survey['status'] = in_array(
        $status,
        ['draft', 'active', 'ended'],
        true
    ) ? $status : 'draft';

    $survey['created_at'] = (string)(
        $survey['created_at'] ?? survey_now()
    );

    $survey['updated_at'] = (string)(
        $survey['updated_at'] ?? survey_now()
    );

    $mode = $survey['numbering_mode'] ?? 'global';

    $survey['numbering_mode'] = in_array(
        $mode,
        ['global', 'group'],
        true
    ) ? $mode : 'global';

    $survey['deleted'] = (bool)(
        $survey['deleted'] ?? false
    );

    $survey['groups'] =
        is_array($survey['groups'] ?? null)
        ? $survey['groups']
        : [];

    foreach ($survey['groups'] as &$group) {
        $group['id'] = (string)(
            $group['id'] ?? survey_id('group')
        );

        $group['name'] = (string)(
            $group['name'] ?? '新しいグループ'
        );

        $group['questions'] =
            is_array($group['questions'] ?? null)
            ? $group['questions']
            : [];

        foreach ($group['questions'] as &$question) {
            $question['id'] = (string)(
                $question['id'] ?? survey_id('question')
            );

            $question['text'] = (string)(
                $question['text'] ?? ''
            );

            $type = $question['type'] ?? 'single';

            $question['type'] = in_array(
                $type,
                ['single', 'multiple', 'text'],
                true
            ) ? $type : 'single';

            $question['required'] = (bool)(
                $question['required'] ?? false
            );

            $question['options'] =
                is_array($question['options'] ?? null)
                ? array_values(
                    array_map(
                        'strval',
                        $question['options']
                    )
                )
                : [];

            $question['other_enabled'] = (bool)(
                $question['other_enabled'] ?? false
            );

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

/* =========================================================
 * API
 * ========================================================= */

$action = (string)(
    $_GET['action'] ??
    $_POST['action'] ??
    ''
);

if ($action !== '') {
    $data = survey_read();

    /* ---------------- load ---------------- */

    if ($action === 'load') {
        $surveys = [];

        foreach ($data['surveys'] as $survey) {
            if (
                !is_array($survey) ||
                !empty($survey['deleted'])
            ) {
                continue;
            }

            $survey = survey_normalize($survey);

            $count = 0;

            foreach ($data['responses'] as $response) {
                if (
                    is_array($response) &&
                    (string)($response['survey_id'] ?? '') ===
                    $survey['id']
                ) {
                    $count++;
                }
            }

            $survey['answer_count'] = $count;
            $surveys[] = $survey;
        }

        survey_json([
            'ok' => true,
            'csrf_token' => survey_csrf(),
            'surveys' => $surveys,
            'responses' => $data['responses'],
            'customers' => $data['customers'],
            'settings' => $data['settings'],
            'mail_logs' => $data['mail_logs']
        ]);
    }

    /* ---------------- kintone fields ---------------- */

    if ($action === 'kintone_fields') {
        survey_verify_csrf();

        $settings =
            survey_post_json('settings_json')
            ?? $data['settings'];

        $appId = trim((string)(
            $settings['app_id'] ??
            ($_POST['app_id'] ?? '')
        ));

        if ($appId === '') {
            survey_json([
                'ok' => false,
                'message' => 'アプリIDを入力してください。'
            ], 400);
        }

        $result = survey_kintone_request(
            $settings,
            '/k/v1/app/form/fields.json',
            'GET',
            ['app' => $appId]
        );

        if (!$result['ok']) {
            survey_json($result, 400);
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

        survey_json([
            'ok' => true,
            'fields' => $fields
        ]);
    }

    /* ---------------- kintone test ---------------- */

    if ($action === 'kintone_test') {
        survey_verify_csrf();

        $settings =
            survey_post_json('settings_json')
            ?? $data['settings'];

        $appId = trim((string)(
            $settings['app_id'] ??
            ($_POST['app_id'] ?? '')
        ));

        if ($appId === '') {
            survey_json([
                'ok' => false,
                'message' => 'アプリIDを入力してください。'
            ], 400);
        }

        /*
         * 接続確認も実際のkintone APIを使用する。
         *
         * 項目一覧取得と同一のGETを利用するため、
         * 接続確認だけ別仕様になってCB_IL02になることを防ぐ。
         */
        $result = survey_kintone_request(
            $settings,
            '/k/v1/app/form/fields.json',
            'GET',
            ['app' => $appId]
        );

        if (!$result['ok']) {
            survey_json([
                'ok' => false,
                'message' => $result['message'] ?? '接続確認に失敗しました。',
                'status' => $result['status'] ?? 0,
                'code' => $result['code'] ?? '',
                'url' => $result['url'] ?? '',
                'errors' => $result['errors'] ?? [],
                'headers' => $result['headers'] ?? [],
                'raw' => $result['raw'] ?? ''
            ], 400);
        }

        $count = count(
            $result['data']['properties'] ?? []
        );

        survey_json([
            'ok' => true,
            'message' => 'kintoneへの接続に成功しました。',
            'status' => $result['status'],
            'field_count' => $count
        ]);
    }

    /* ---------------- save settings ---------------- */

    if ($action === 'save_settings') {
        survey_verify_csrf();

        $settings =
            survey_post_json('settings_json');

        if (!is_array($settings)) {
            survey_json([
                'ok' => false,
                'message' => '設定データが不正です。'
            ], 400);
        }

        $allowed = [
            'subdomain',
            'login_name',
            'password',
            'app_id',
            'ssl_verify',
            'proxy',
            'field_company',
            'field_name',
            'field_email',
            'field_department',
            'field_phone',
            'field_address'
        ];

        $new = $data['settings'];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $settings)) {
                $new[$key] = $settings[$key];
            }
        }

        $new['ssl_verify'] = !empty(
            $new['ssl_verify']
        );

        $new['field_address'] =
            is_array($new['field_address'] ?? null)
            ? $new['field_address']
            : [];

        $data['settings'] = $new;

        if (!survey_write($data)) {
            survey_json([
                'ok' => false,
                'message' => '設定の保存に失敗しました。'
            ], 500);
        }

        survey_json([
            'ok' => true,
            'settings' => $new
        ]);
    }

    /* ---------------- save survey ---------------- */

    if ($action === 'save_survey') {
        survey_verify_csrf();

        $survey = survey_post_json('survey_json');

        if (!is_array($survey)) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], 400);
        }

        $survey = survey_normalize($survey);

        $survey['updated_at'] = survey_now();

        $found = false;

        foreach ($data['surveys'] as $index => $old) {
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

                $data['surveys'][$index] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['created_at'] = survey_now();
            $data['surveys'][] = $survey;
        }

        if (!survey_write($data)) {
            survey_json([
                'ok' => false,
                'message' => 'アンケート保存に失敗しました。'
            ], 500);
        }

        survey_json([
            'ok' => true,
            'survey' => $survey
        ]);
    }

    /* ---------------- delete ---------------- */

    if ($action === 'delete_survey') {
        survey_verify_csrf();

        $id = (string)(
            $_POST['survey_id'] ?? ''
        );

        foreach ($data['surveys'] as &$survey) {
            if (
                is_array($survey) &&
                (string)($survey['id'] ?? '') === $id
            ) {
                $survey['deleted'] = true;
                $survey['updated_at'] = survey_now();
            }
        }

        unset($survey);

        survey_write($data);

        survey_json(['ok' => true]);
    }

    /* ---------------- status ---------------- */

    if ($action === 'status_survey') {
        survey_verify_csrf();

        $id = (string)(
            $_POST['survey_id'] ?? ''
        );

        $status = (string)(
            $_POST['status'] ?? 'draft'
        );

        if (!in_array(
            $status,
            ['draft', 'active', 'ended'],
            true
        )) {
            survey_json([
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
                $survey['updated_at'] = survey_now();
            }
        }

        unset($survey);

        survey_write($data);

        survey_json(['ok' => true]);
    }

    /* ---------------- duplicate ---------------- */

    if ($action === 'duplicate_survey') {
        survey_verify_csrf();

        $id = (string)(
            $_POST['survey_id'] ?? ''
        );

        $source = null;

        foreach ($data['surveys'] as $survey) {
            if (
                is_array($survey) &&
                (string)($survey['id'] ?? '') === $id
            ) {
                $source = $survey;
                break;
            }
        }

        if (!is_array($source)) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $copy = survey_normalize($source);

        $copy['id'] = survey_id('survey');
        $copy['title'] =
            $copy['title'] . '（複製）';
        $copy['status'] = 'draft';
        $copy['deleted'] = false;
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();

        foreach ($copy['groups'] as &$group) {
            $group['id'] = survey_id('group');

            foreach ($group['questions'] as &$question) {
                $question['id'] = survey_id('question');
            }

            unset($question);
        }

        unset($group);

        $data['surveys'][] = $copy;

        survey_write($data);

        survey_json([
            'ok' => true,
            'survey' => $copy
        ]);
    }

    /* ---------------- customer kintone status ---------------- */

    if ($action === 'customer_kintone_status') {
        survey_verify_csrf();

        $id = (string)(
            $_POST['customer_id'] ?? ''
        );

        foreach ($data['customers'] as &$customer) {
            if (
                is_array($customer) &&
                (string)($customer['id'] ?? '') === $id
            ) {
                $customer['kintone_status'] = 'registered';
            }
        }

        unset($customer);

        survey_write($data);

        survey_json(['ok' => true]);
    }

    /* ---------------- mail log ---------------- */

    if ($action === 'send_mail') {
        survey_verify_csrf();

        $surveyId = (string)(
            $_POST['survey_id'] ?? ''
        );

        $ids = $_POST['recipient_ids'] ?? [];

        if (!is_array($ids)) {
            $ids = [];
        }

        $subject = (string)(
            $_POST['mail_subject'] ?? ''
        );

        $body = (string)(
            $_POST['mail_body'] ?? ''
        );

        $templateType = (string)(
            $_POST['template_type'] ?? 'initial'
        );

        $sent = 0;

        foreach ($data['customers'] as &$customer) {
            $customerId = (string)(
                $customer['id'] ?? ''
            );

            if (!in_array(
                $customerId,
                $ids,
                true
            )) {
                continue;
            }

            if (
                trim((string)($customer['email'] ?? '')) === ''
            ) {
                continue;
            }

            $customer['sent_at'] = survey_now();

            $customer['send_count'] =
                (int)(
                    $customer['send_count'] ?? 0
                ) + 1;

            $customer['answer_status'] =
                'unanswered';

            $sent++;
        }

        unset($customer);

        $data['mail_logs'][] = [
            'id' => survey_id('mail'),
            'survey_id' => $surveyId,
            'sent_at' => survey_now(),
            'template_type' => in_array(
                $templateType,
                ['initial', 'reminder'],
                true
            ) ? $templateType : 'initial',
            'count' => $sent,
            'subject' => $subject,
            'body' => $body,
            'recipient_ids' => array_values($ids)
        ];

        survey_write($data);

        survey_json([
            'ok' => true,
            'sent_count' => $sent
        ]);
    }

    /* ---------------- CSV ---------------- */

    if ($action === 'export_csv') {
        survey_verify_csrf();

        $surveyId = (string)(
            $_POST['survey_id'] ?? ''
        );

        $survey = null;

        foreach ($data['surveys'] as $item) {
            if (
                is_array($item) &&
                (string)($item['id'] ?? '') === $surveyId
            ) {
                $survey = survey_normalize($item);
                break;
            }
        }

        if (!is_array($survey)) {
            survey_json([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $questions = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $question) {
                $questions[] = $question;
            }
        }

        $rows = [];

        $header = [
            '回答ID',
            '回答日時',
            '顧客ID',
            '会社名',
            '氏名'
        ];

        foreach ($questions as $i => $question) {
            $header[] = '設問' . ($i + 1);
        }

        $rows[] = $header;

        foreach ($data['responses'] as $response) {
            if (
                !is_array($response) ||
                (string)($response['survey_id'] ?? '') !==
                $surveyId
            ) {
                continue;
            }

            $answers =
                is_array($response['answers'] ?? null)
                ? $response['answers']
                : [];

            $row = [
                $response['id'] ?? '',
                $response['answered_at'] ?? '',
                $response['customer_id'] ?? '',
                $response['company'] ?? '',
                $response['name'] ?? ''
            ];

            foreach ($questions as $question) {
                $value = $answers[
                    $question['id']
                ] ?? '';

                if (is_array($value)) {
                    $value = implode(
                        '、',
                        array_map('strval', $value)
                    );
                }

                $row[] = (string)$value;
            }

            $rows[] = $row;
        }

        $out = "\xEF\xBB\xBF";

        foreach ($rows as $row) {
            $fp = fopen('php://temp', 'r+');
            fputcsv($fp, $row);
            rewind($fp);
            $out .= stream_get_contents($fp);
            fclose($fp);
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="survey_' .
            rawurlencode($surveyId) .
            '.csv"'
        );

        echo $out;
        exit;
    }

    survey_json([
        'ok' => false,
        'message' => '未知のAPIアクションです。',
        'action' => $action
    ], 404);
}

/* =========================================================
 * HTML
 * ========================================================= */
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
    State: {
        csrf: '',
        surveys: [],
        responses: [],
        customers: [],
        settings: {},
        mailLogs: [],
        screen: 'list',
        survey: null,
        originalSurvey: null,
        previewMode: 'pc',
        responseSearch: '',
        customerSearch: '',
        statusFilter: 'all',
        keyword: '',
        sort: 'updated_desc',
        selectedQuestions: {},
        editingSurveyId: null
    },

    api: {},

    actions: {},

    render: {},

    util: {},

    initDone: false
};

App.util.escape = function(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
};

App.util.id = function(prefix) {
    return prefix + '_' +
        Math.random().toString(36).slice(2) +
        Date.now().toString(36);
};

App.util.clone = function(value) {
    return JSON.parse(JSON.stringify(value));
};

App.util.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
};

App.util.typeLabel = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || type;
};

App.api.request = async function(action, method='POST', data={}) {
    const url = '?action=' + encodeURIComponent(action);

    const body = new URLSearchParams();

    if (method !== 'GET') {
        body.set('csrf_token', App.State.csrf);
    }

    Object.entries(data).forEach(([key, value]) => {
        if (typeof value === 'object') {
            body.set(key, JSON.stringify(value));
        } else {
            body.set(key, value ?? '');
        }
    });

    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type':
                'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: method === 'GET' ? undefined : body
    });

    const text = await response.text();

    let json;

    try {
        json = JSON.parse(text);
    } catch (e) {
        throw new Error(
            'サーバーからJSON以外のレスポンスが返されました。\n' +
            text.slice(0, 500)
        );
    }

    if (!response.ok || json.ok === false) {
        const error = new Error(
            json.message ||
            ('HTTP ' + response.status)
        );
        error.data = json;
        throw error;
    }

    return json;
};

App.api.load = async function() {
    const data = await App.api.request('load', 'GET');

    App.State.csrf = data.csrf_token;
    App.State.surveys = data.surveys || [];
    App.State.responses = data.responses || [];
    App.State.customers = data.customers || [];
    App.State.settings = data.settings || {};
    App.State.mailLogs = data.mail_logs || {};
};

App.api.saveSurvey = async function() {
    const data = await App.api.request(
        'save_survey',
        'POST',
        {
            survey_json: App.State.survey
        }
    );

    App.State.survey = data.survey;
    App.State.surveys = App.State.surveys.filter(
        x => x.id !== data.survey.id
    );
    App.State.surveys.push(data.survey);
};

App.api.saveSettings = async function() {
    const data = await App.api.request(
        'save_settings',
        'POST',
        {
            settings_json: App.State.settings
        }
    );

    App.State.settings = data.settings;
};

App.api.fetchKintoneFields = async function() {
    const message = document.getElementById('field_message');

    if (message) {
        message.textContent = '項目一覧を取得しています…';
    }

    try {
        const data = await App.api.request(
            'kintone_fields',
            'POST',
            {
                settings_json: App.State.settings,
                app_id: App.State.settings.app_id
            }
        );

        App.State.kintoneFields = data.fields || [];

        if (message) {
            message.textContent =
                '項目一覧を ' +
                App.State.kintoneFields.length +
                ' 件取得しました。';
        }

        App.render.settings();
    } catch (e) {
        if (message) {
            message.textContent =
                '取得失敗: ' + e.message;
        }

        alert(e.message);
    }
};

App.api.testKintone = async function() {
    try {
        const data = await App.api.request(
            'kintone_test',
            'POST',
            {
                settings_json: App.State.settings,
                app_id: App.State.settings.app_id
            }
        );

        alert(
            'kintone接続成功\n' +
            'HTTP: ' + data.status + '\n' +
            '取得可能項目数: ' +
            data.field_count
        );
    } catch (e) {
        let message = e.message;

        if (e.data) {
            message +=
                '\nHTTP: ' +
                (e.data.status ?? '') +
                '\ncode: ' +
                (e.data.code ?? '');

            if (e.data.url) {
                message +=
                    '\nURL: ' + e.data.url;
            }

            if (e.data.raw) {
                message +=
                    '\nレスポンス:\n' +
                    e.data.raw.slice(0, 1000);
            }
        }

        alert(message);
    }
};

/* =========================================================
 * survey manipulation
 * ========================================================= */

App.actions.newSurvey = function() {
    const now = new Date().toISOString().slice(0,16);

    App.State.survey = {
        id: App.util.id('survey'),
        title: '新しいアンケート',
        start_at: now,
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [{
            id: App.util.id('group'),
            name: 'グループ1',
            questions: []
        }],
        deleted: false
    };

    App.State.originalSurvey =
        App.util.clone(App.State.survey);

    App.State.screen = 'edit';

    App.render.current();
};

App.actions.editSurvey = function(id) {
    const survey = App.State.surveys.find(
        x => x.id === id
    );

    if (!survey) return;

    App.State.survey =
        App.util.clone(survey);

    App.State.originalSurvey =
        App.util.clone(survey);

    App.State.editingSurveyId = id;
    App.State.screen = 'edit';

    App.render.current();
};

App.actions.cancelEdit = function() {
    if (
        JSON.stringify(App.State.survey) !==
        JSON.stringify(App.State.originalSurvey)
    ) {
        if (!confirm(
            '変更内容を破棄して一覧へ戻りますか？'
        )) {
            return;
        }
    }

    App.State.screen = 'list';
    App.render.current();
};

App.actions.addGroup = function() {
    if (!App.State.survey) return;

    App.State.survey.groups.push({
        id: App.util.id('group'),
        name:
            'グループ' +
            (App.State.survey.groups.length + 1),
        questions: []
    });

    App.render.current();
    App.actions.initSortable();
};

App.actions.deleteGroup = function(groupId) {
    if (!App.State.survey) return;

    if (!confirm(
        'このグループと質問をすべて削除しますか？'
    )) return;

    App.State.survey.groups =
        App.State.survey.groups.filter(
            g => g.id !== groupId
        );

    App.render.current();
    App.actions.initSortable();
};

App.actions.addQuestion = function(groupId) {
    const group =
        App.State.survey.groups.find(
            g => g.id === groupId
        );

    if (!group) return;

    group.questions.push({
        id: App.util.id('question'),
        text: '質問文を入力してください',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false,
        branch: {}
    });

    App.render.current();
    App.actions.initSortable();
};

App.actions.deleteQuestion = function(groupId, questionId) {
    const group =
        App.State.survey.groups.find(
            g => g.id === groupId
        );

    if (!group) return;

    group.questions =
        group.questions.filter(
            q => q.id !== questionId
        );

    App.render.current();
    App.actions.initSortable();
};

App.actions.addOption = function(groupId, questionId) {
    const q = App.util.findQuestion(
        groupId,
        questionId
    );

    if (!q) return;

    q.options.push(
        '選択肢' + (q.options.length + 1)
    );

    App.render.current();
    App.actions.initSortable();
};

App.actions.removeOption = function(
    groupId,
    questionId,
    index
) {
    const q = App.util.findQuestion(
        groupId,
        questionId
    );

    if (!q) return;

    q.options.splice(index, 1);

    Object.keys(q.branch || {}).forEach(key => {
        if (!q.options.includes(key)) {
            delete q.branch[key];
        }
    });

    App.render.current();
};

App.actions.changeType = function(
    groupId,
    questionId,
    type
) {
    const q = App.util.findQuestion(
        groupId,
        questionId
    );

    if (!q) return;

    q.type = type;

    if (type === 'text') {
        q.options = [];
        q.branch = {};
    } else if (!q.options.length) {
        q.options = [
            '選択肢1',
            '選択肢2'
        ];
    }

    App.render.current();
    App.actions.initSortable();
};

App.actions.toggleRequired = function(
    groupId,
    questionId,
    value
) {
    const q = App.util.findQuestion(
        groupId,
        questionId
    );

    if (q) {
        q.required = !!value;
    }
};

App.actions.changeQuestionText = function(
    groupId,
    questionId,
    value
) {
    const q = App.util.findQuestion(
        groupId,
        questionId
    );

    if (q) q.text = value;
};

App.actions.changeOption = function(
    groupId,
    questionId,
    index,
    value
) {
    const q = App.util.findQuestion(
        groupId,
        questionId
    );

    if (q) {
        q.options[index] = value;
    }
};

App.actions.changeBranch = function(
    groupId,
    questionId,
    option,
    value
) {
    const q = App.util.findQuestion(
        groupId,
        questionId
    );

    if (!q) return;

    if (!q.branch) {
        q.branch = {};
    }

    q.branch[option] = value;
};

App.util.findQuestion = function(
    groupId,
    questionId
) {
    const group =
        App.State.survey.groups.find(
            g => g.id === groupId
        );

    return group?.questions.find(
        q => q.id === questionId
    );
};

/* =========================================================
 * SortableJS
 * ========================================================= */

App.actions.initSortable = function() {
    if (
        typeof Sortable === 'undefined' ||
        !App.State.survey
    ) return;

    document
        .querySelectorAll('.question-list')
        .forEach(el => {
            if (el._sortable) {
                el._sortable.destroy();
            }

            el._sortable = new Sortable(el, {
                group: 'survey-questions',
                animation: 180,
                handle: '.question-handle',
                ghostClass: 'opacity-40',
                onEnd: function(evt) {
                    const fromGroup =
                        evt.from.dataset.groupId;

                    const toGroup =
                        evt.to.dataset.groupId;

                    const from =
                        App.State.survey.groups.find(
                            g => g.id === fromGroup
                        );

                    const to =
                        App.State.survey.groups.find(
                            g => g.id === toGroup
                        );

                    if (!from || !to) return;

                    const q =
                        from.questions.splice(
                            evt.oldIndex,
                            1
                        )[0];

                    if (q) {
                        to.questions.splice(
                            evt.newIndex,
                            0,
                            q
                        );
                    }

                    App.render.current();
                    App.actions.initSortable();
                }
            });
        });

    const groupList =
        document.getElementById('group-list');

    if (groupList) {
        if (groupList._sortable) {
            groupList._sortable.destroy();
        }

        groupList._sortable =
            new Sortable(groupList, {
                animation: 180,
                handle: '.group-handle',
                ghostClass: 'opacity-40',
                onEnd: function(evt) {
                    const groups =
                        App.State.survey.groups;

                    const moved =
                        groups.splice(
                            evt.oldIndex,
                            1
                        )[0];

                    groups.splice(
                        evt.newIndex,
                        0,
                        moved
                    );

                    App.render.current();
                    App.actions.initSortable();
                }
            });
    }
};

/* =========================================================
 * numbering
 * ========================================================= */

App.util.numberQuestions = function() {
    const result = [];

    if (!App.State.survey) return result;

    let global = 1;

    App.State.survey.groups.forEach(
        (group, gi) => {
            group.questions.forEach(
                (q, qi) => {
                    result.push({
                        q,
                        group,
                        number:
                            App.State.survey.numbering_mode ===
                            'group'
                                ? `Q${gi + 1}-${qi + 1}`
                                : `Q${global++}`
                    });
                }
            );
        }
    );

    return result;
};

/* =========================================================
 * status / duplicate
 * ========================================================= */

App.actions.changeStatus = async function(
    id,
    status
) {
    try {
        await App.api.request(
            'status_survey',
            'POST',
            {
                survey_id: id,
                status
            }
        );

        await App.api.load();
        App.render.current();
    } catch (e) {
        alert(e.message);
    }
};

App.actions.stopSurvey = async function(id) {
    if (!confirm(
        'このアンケートを停止しますか？'
    )) return;

    await App.actions.changeStatus(
        id,
        'ended'
    );
};

App.actions.duplicateSurvey = async function(id) {
    try {
        await App.api.request(
            'duplicate_survey',
            'POST',
            {
                survey_id: id
            }
        );

        await App.api.load();
        App.render.current();
    } catch (e) {
        alert(e.message);
    }
};

App.actions.deleteSurvey = async function(id) {
    if (!confirm(
        'この下書きを削除しますか？'
    )) return;

    try {
        await App.api.request(
            'delete_survey',
            'POST',
            {
                survey_id: id
            }
        );

        await App.api.load();
        App.render.current();
    } catch (e) {
        alert(e.message);
    }
};

/* =========================================================
 * save
 * ========================================================= */

App.actions.saveSurvey = async function() {
    try {
        await App.api.saveSurvey();

        await App.api.load();

        alert('保存しました。');

        App.State.screen = 'list';
        App.State.survey = null;

        App.render.current();
    } catch (e) {
        alert(e.message);
    }
};

/* =========================================================
 * preview
 * ========================================================= */

App.actions.preview = function() {
    const modal =
        document.getElementById('preview_modal');

    const content =
        document.getElementById('preview_content');

    if (!modal || !content) return;

    content.innerHTML =
        App.render.previewHtml();

    modal.classList.remove('hidden');
};

App.actions.closePreview = function() {
    const modal =
        document.getElementById('preview_modal');

    if (modal) {
        modal.classList.add('hidden');
    }
};

App.actions.previewSubmit = function() {
    alert(
        'これはプレビューです。実際の回答は送信されません。'
    );
};

/* =========================================================
 * settings
 * ========================================================= */

App.actions.settings = function() {
    App.State.screen = 'settings';
    App.render.current();
};

App.actions.updateSetting = function(
    key,
    value
) {
    App.State.settings[key] = value;
};

App.actions.updateAddress = function(
    index,
    value
) {
    if (!Array.isArray(
        App.State.settings.field_address
    )) {
        App.State.settings.field_address = [];
    }

    App.State.settings.field_address[index] =
        value;
};

App.actions.addAddressField = function() {
    if (!Array.isArray(
        App.State.settings.field_address
    )) {
        App.State.settings.field_address = [];
    }

    App.State.settings.field_address.push('');
    App.render.settings();
};

App.actions.removeAddressField = function(index) {
    App.State.settings.field_address.splice(
        index,
        1
    );

    App.render.settings();
};

App.actions.saveSettings = async function() {
    try {
        await App.api.saveSettings();
        alert('kintone設定を保存しました。');
    } catch (e) {
        alert(e.message);
    }
};

/* =========================================================
 * list
 * ========================================================= */

App.actions.search = function(value) {
    App.State.keyword = value;
    App.render.list();
};

App.actions.statusFilter = function(value) {
    App.State.statusFilter = value;
    App.render.list();
};

App.actions.sort = function(value) {
    App.State.sort = value;
    App.render.list();
};

App.util.filteredSurveys = function() {
    let list =
        App.State.surveys.filter(
            s => !s.deleted
        );

    const keyword =
        App.State.keyword.trim().toLowerCase();

    if (keyword) {
        list = list.filter(
            s =>
                String(s.title)
                    .toLowerCase()
                    .includes(keyword)
        );
    }

    if (
        App.State.statusFilter !== 'all'
    ) {
        list = list.filter(
            s =>
                s.status ===
                App.State.statusFilter
        );
    }

    list.sort((a,b) => {
        if (App.State.sort === 'updated_desc') {
            return String(b.updated_at)
                .localeCompare(String(a.updated_at));
        }

        if (App.State.sort === 'updated_asc') {
            return String(a.updated_at)
                .localeCompare(String(b.updated_at));
        }

        if (App.State.sort === 'answers_desc') {
            return (b.answer_count || 0) -
                (a.answer_count || 0);
        }

        if (App.State.sort === 'answers_asc') {
            return (a.answer_count || 0) -
                (b.answer_count || 0);
        }

        if (App.State.sort === 'start_desc') {
            return String(b.start_at)
                .localeCompare(String(a.start_at));
        }

        return String(a.start_at)
            .localeCompare(String(b.start_at));
    });

    return list;
};

/* =========================================================
 * aggregation
 * ========================================================= */

App.actions.aggregate = function(id) {
    App.State.survey =
        App.State.surveys.find(
            s => s.id === id
        );

    if (!App.State.survey) return;

    App.State.screen = 'aggregate';
    App.render.current();
};

App.actions.responseDetail = function(id) {
    const response =
        App.State.responses.find(
            r => r.id === id
        );

    if (!response) return;

    const survey =
        App.State.survey;

    const answers =
        response.answers || {};

    let html = '';

    survey.groups.forEach(group => {
        group.questions.forEach(q => {
            let value = answers[q.id] ?? '';

            if (Array.isArray(value)) {
                value = value.join('、');
            }

            html += `
                <div class="border-b py-3">
                    <div class="font-semibold">
                        ${App.util.escape(q.text)}
                    </div>
                    <div class="mt-1 text-slate-600 whitespace-pre-wrap">
                        ${App.util.escape(value)}
                    </div>
                </div>
            `;
        });
    });

    document.getElementById(
        'response_detail'
    ).innerHTML = html;

    document.getElementById(
        'response_modal'
    ).classList.remove('hidden');
};

App.actions.closeResponse = function() {
    document.getElementById(
        'response_modal'
    ).classList.add('hidden');
};

App.actions.toggleQuestion = function(
    id,
    value
) {
    App.State.selectedQuestions[id] =
        !!value;

    App.render.aggregate();
};

App.actions.allQuestions = function(value) {
    App.util.numberQuestions().forEach(
        item => {
            App.State.selectedQuestions[
                item.q.id
            ] = value;
        }
    );

    App.render.aggregate();
};

App.util.questionStats = function(q) {
    const responses =
        App.State.responses.filter(
            r =>
                r.survey_id ===
                App.State.survey.id
        );

    const counts = {};

    q.options.forEach(
        option => counts[option] = 0
    );

    let other = 0;

    responses.forEach(r => {
        const value =
            r.answers?.[q.id];

        const values =
            Array.isArray(value)
            ? value
            : [value];

        values.forEach(v => {
            if (
                v &&
                Object.prototype.hasOwnProperty
                    .call(counts, v)
            ) {
                counts[v]++;
            } else if (v) {
                other++;
            }
        });
    });

    return {
        total: responses.length,
        counts,
        other
    };
};

/* =========================================================
 * mail
 * ========================================================= */

App.actions.mail = function(id) {
    App.State.survey =
        App.State.surveys.find(
            s => s.id === id
        );

    App.State.screen = 'mail';

    App.render.current();
};

App.actions.sendMail = async function() {
    const checked =
        [...document.querySelectorAll(
            'input[name="recipient"]:checked'
        )].map(
            x => x.value
        );

    if (!checked.length) {
        alert('送信先を選択してください。');
        return;
    }

    const already =
        App.State.customers.filter(
            c =>
                checked.includes(c.id) &&
                Number(c.send_count || 0) > 0
        );

    if (
        already.length &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )
    ) {
        return;
    }

    try {
        const data =
            await App.api.request(
                'send_mail',
                'POST',
                {
                    survey_id:
                        App.State.survey.id,
                    recipient_ids: checked,
                    mail_subject:
                        document.getElementById(
                            'mail_subject'
                        ).value,
                    mail_body:
                        document.getElementById(
                            'mail_body'
                        ).value,
                    template_type:
                        document.getElementById(
                            'template_type'
                        ).value
                }
            );

        await App.api.load();

        alert(
            data.sent_count +
            '件を送信対象として記録しました。'
        );

        App.render.current();
    } catch (e) {
        alert(e.message);
    }
};

/* =========================================================
 * render
 * ========================================================= */

App.render.header = function() {
    return `
        <header class="sticky top-0 z-20 bg-white border-b shadow-sm">
            <div class="max-w-7xl mx-auto px-5 h-16 flex items-center justify-between">
                <div class="font-bold text-lg text-slate-800">
                    アンケート管理システム
                </div>
                <nav class="flex gap-2">
                    <button
                        class="px-4 py-2 rounded-lg hover:bg-slate-100"
                        onclick="App.State.screen='list';App.render.current()">
                        アンケート一覧
                    </button>
                    <button
                        class="px-4 py-2 rounded-lg hover:bg-slate-100"
                        onclick="App.actions.settings()">
                        kintone連携設定
                    </button>
                </nav>
            </div>
        </header>
    `;
};

App.render.list = function() {
    const list =
        App.util.filteredSurveys();

    let rows = '';

    if (!list.length) {
        rows = `
            <tr>
                <td colspan="6"
                    class="text-center py-16 text-slate-400">
                    アンケートがありません
                </td>
            </tr>
        `;
    }

    list.forEach(s => {
        const badge =
            s.status === 'active'
            ? 'bg-emerald-100 text-emerald-700'
            : s.status === 'ended'
                ? 'bg-slate-200 text-slate-600'
                : 'bg-amber-100 text-amber-700';

        let buttons = `
            <button
                class="px-2 py-1 text-xs rounded bg-slate-100 hover:bg-slate-200"
                onclick="App.actions.editSurvey('${s.id}')">
                確認・編集
            </button>
        `;

        if (s.status === 'active') {
            buttons += `
                <button
                    class="px-2 py-1 text-xs rounded bg-blue-50 text-blue-700"
                    onclick="App.actions.aggregate('${s.id}')">
                    集計
                </button>
                <button
                    class="px-2 py-1 text-xs rounded bg-indigo-50 text-indigo-700"
                    onclick="App.actions.mail('${s.id}')">
                    送信
                </button>
                <button
                    class="px-2 py-1 text-xs rounded bg-red-50 text-red-700"
                    onclick="App.actions.stopSurvey('${s.id}')">
                    停止
                </button>
            `;

        } else if (s.status === 'draft') {
            buttons += `
                <button
                    class="px-2 py-1 text-xs rounded bg-red-50 text-red-700"
                    onclick="App.actions.deleteSurvey('${s.id}')">
                    削除
                </button>
            `;

        } else {
            buttons += `
                <button
                    class="px-2 py-1 text-xs rounded bg-blue-50 text-blue-700"
                    onclick="App.actions.aggregate('${s.id}')">
                    集計
                </button>
            `;
        }

        buttons += `
            <button
                class="px-2 py-1 text-xs rounded bg-violet-50 text-violet-700"
                onclick="App.actions.duplicateSurvey('${s.id}')">
                複製
            </button>
        `;

        rows += `
            <tr class="border-t">
                <td class="px-4 py-4">
                    <div>${App.util.escape(
                        s.created_at?.slice(0,10) || ''
                    )}</div>
                    <div class="text-xs text-slate-400">
                        更新:
                        ${App.util.escape(
                            s.updated_at?.slice(0,10) || ''
                        )}
                    </div>
                </td>
                <td class="px-4 py-4 font-bold">
                    ${App.util.escape(s.title)}
                </td>
                <td class="px-4 py-4 text-sm">
                    ${App.util.escape(s.start_at || '未設定')}
                    <br>～
                    ${App.util.escape(s.end_at || '未設定')}
                </td>
                <td class="px-4 py-4">
                    <span class="px-2 py-1 rounded-full text-xs ${badge}">
                        ${App.util.statusLabel(s.status)}
                    </span>
                </td>
                <td class="px-4 py-4">
                    ${(s.answer_count || 0)} 件
                </td>
                <td class="px-4 py-4">
                    <div class="flex flex-wrap gap-1">
                        ${buttons}
                    </div>
                </td>
            </tr>
        `;
    });

    return `
        ${App.render.header()}

        <main class="max-w-7xl mx-auto p-6">

            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold">
                        アンケート一覧
                    </h1>
                    <p class="text-sm text-slate-500 mt-1">
                        アンケートの作成・公開・集計・送信を管理します。
                    </p>
                </div>

                <button
                    class="px-5 py-3 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700"
                    onclick="App.actions.newSurvey()">
                    ＋ 新規アンケート作成
                </button>
            </div>

            <div class="bg-white rounded-2xl border p-4 mb-5">
                <div class="grid md:grid-cols-3 gap-3">
                    <input
                        class="border rounded-lg px-3 py-2"
                        placeholder="タイトル検索"
                        value="${App.util.escape(App.State.keyword)}"
                        oninput="App.actions.search(this.value)"
                        onkeydown="if(event.key==='Enter')App.actions.search(this.value)">

                    <select
                        class="border rounded-lg px-3 py-2"
                        onchange="App.actions.statusFilter(this.value)">
                        <option value="all"
                            ${App.State.statusFilter==='all'?'selected':''}>
                            すべて
                        </option>
                        <option value="active"
                            ${App.State.statusFilter==='active'?'selected':''}>
                            公開中
                        </option>
                        <option value="draft"
                            ${App.State.statusFilter==='draft'?'selected':''}>
                            下書き
                        </option>
                        <option value="ended"
                            ${App.State.statusFilter==='ended'?'selected':''}>
                            終了
                        </option>
                    </select>

                    <select
                        class="border rounded-lg px-3 py-2"
                        onchange="App.actions.sort(this.value)">
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
                        <option value="start_desc">
                            開始日：新しい順
                        </option>
                        <option value="start_asc">
                            開始日：古い順
                        </option>
                    </select>
                </div>
            </div>

            <div class="bg-white rounded-2xl border overflow-x-auto">
                <table class="w-full min-w-[1100px] text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="text-left px-4 py-3">作成日 / 更新日</th>
                            <th class="text-left px-4 py-3">タイトル</th>
                            <th class="text-left px-4 py-3">アンケート期間</th>
                            <th class="text-left px-4 py-3">ステータス</th>
                            <th class="text-left px-4 py-3">回答数</th>
                            <th class="text-left px-4 py-3">操作</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </main>
    `;
};

App.render.edit = function() {
    const s = App.State.survey;

    const numbers =
        App.util.numberQuestions();

    const numberMap = {};
    numbers.forEach(x => {
        numberMap[x.q.id] = x.number;
    });

    let groups = '';

    s.groups.forEach(group => {
        let questions = '';

        group.questions.forEach(q => {
            let options = '';

            if (q.type !== 'text') {
                q.options.forEach((option, index) => {
                    let branchOptions = `
                        <option value="">
                            分岐なし
                        </option>
                        <option value="next">
                            次の質問
                        </option>
                    `;

                    numbers.forEach(item => {
                        if (item.q.id !== q.id) {
                            branchOptions += `
                                <option value="${App.util.escape(item.q.id)}"
                                    ${q.branch?.[option]===item.q.id?'selected':''}>
                                    ${App.util.escape(item.number)}:
                                    ${App.util.escape(item.q.text)}
                                </option>
                            `;
                        }
                    });

                    options += `
                        <div class="flex gap-2 items-center mb-2">
                            <input
                                class="flex-1 border rounded-lg px-3 py-2"
                                value="${App.util.escape(option)}"
                                onchange="App.actions.changeOption('${group.id}','${q.id}',${index},this.value)">

                            <select
                                class="border rounded-lg px-2 py-2 text-sm"
                                onchange="App.actions.changeBranch('${group.id}','${q.id}',this.parentElement.querySelector('input').value,this.value)">
                                ${branchOptions}
                            </select>

                            <button
                                class="text-red-500 px-2"
                                onclick="App.actions.removeOption('${group.id}','${q.id}',${index})">
                                ×
                            </button>
                        </div>
                    `;
                });

                options += `
                    <button
                        class="text-sm text-blue-600"
                        onclick="App.actions.addOption('${group.id}','${q.id}')">
                        ＋ 選択肢追加
                    </button>
                `;
            }

            questions += `
                <div
                    class="question-card bg-white border rounded-xl p-4 mb-3"
                    data-question-id="${q.id}">

                    <div class="flex gap-3">
                        <div class="question-handle cursor-grab text-slate-400 text-xl">
                            ⠿
                        </div>

                        <div class="flex-1">

                            <div class="flex justify-between gap-3">
                                <div class="font-bold text-blue-600">
                                    ${numberMap[q.id]}
                                </div>

                                <button
                                    class="text-red-500"
                                    onclick="App.actions.deleteQuestion('${group.id}','${q.id}')">
                                    削除
                                </button>
                            </div>

                            <textarea
                                class="w-full border rounded-lg p-3 mt-2"
                                rows="2"
                                oninput="App.actions.changeQuestionText('${group.id}','${q.id}',this.value)">${App.util.escape(q.text)}</textarea>

                            <div class="grid md:grid-cols-3 gap-3 mt-3">

                                <select
                                    class="border rounded-lg px-3 py-2"
                                    onchange="App.actions.changeType('${group.id}','${q.id}',this.value)">
                                    <option value="single"
                                        ${q.type==='single'?'selected':''}>
                                        単一選択
                                    </option>
                                    <option value="multiple"
                                        ${q.type==='multiple'?'selected':''}>
                                        複数選択
                                    </option>
                                    <option value="text"
                                        ${q.type==='text'?'selected':''}>
                                        自由記述
                                    </option>
                                </select>

                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        ${q.required?'checked':''}
                                        onchange="App.actions.toggleRequired('${group.id}','${q.id}',this.checked)">
                                    必須回答
                                </label>

                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        ${q.other_enabled?'checked':''}
                                        onchange="App.util.findQuestion('${group.id}','${q.id}').other_enabled=this.checked">
                                    その他
                                </label>

                            </div>

                            ${q.type !== 'text'
                                ? `
                                <div class="mt-4 bg-slate-50 rounded-lg p-3">
                                    <div class="text-sm font-semibold mb-2">
                                        選択肢・質問分岐
                                    </div>
                                    ${options}
                                </div>
                                `
                                : ''
                            }

                        </div>
                    </div>
                </div>
            `;
        });

        groups += `
            <section
                class="group-card bg-slate-50 border rounded-2xl p-4 mb-5"
                data-group-id="${group.id}">

                <div class="flex items-center gap-3 mb-4">

                    <div class="group-handle cursor-grab text-xl text-slate-400">
                        ⠿
                    </div>

                    <input
                        class="flex-1 text-lg font-bold bg-transparent border-b px-2 py-2"
                        value="${App.util.escape(group.name)}"
                        onchange="App.State.survey.groups.find(g=>g.id==='${group.id}').name=this.value">

                    <button
                        class="text-red-500 px-3 py-2"
                        onclick="App.actions.deleteGroup('${group.id}')">
                        グループ削除
                    </button>
                </div>

                <div
                    class="question-list"
                    data-group-id="${group.id}">
                    ${questions}
                </div>

                <button
                    class="mt-2 px-4 py-2 bg-white border rounded-lg hover:bg-slate-100"
                    onclick="App.actions.addQuestion('${group.id}')">
                    ＋ 質問を追加
                </button>
            </section>
        `;
    });

    return `
        ${App.render.header()}

        <main class="max-w-6xl mx-auto p-6">

            <div class="flex flex-wrap justify-between gap-3 mb-5">
                <div class="flex-1 min-w-[350px]">
                    <input
                        id="survey_title"
                        class="w-full text-2xl font-bold bg-transparent border-b-2 border-slate-200 focus:border-blue-500 outline-none py-2"
                        value="${App.util.escape(s.title)}"
                        oninput="App.State.survey.title=this.value">
                </div>

                <div class="flex gap-2">
                    <button
                        class="px-4 py-2 bg-white border rounded-lg"
                        onclick="App.actions.preview()">
                        プレビュー
                    </button>

                    <button
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg"
                        onclick="App.actions.saveSurvey()">
                        保存して一覧へ戻る
                    </button>

                    <button
                        class="px-4 py-2 bg-white border rounded-lg"
                        onclick="App.actions.cancelEdit()">
                        キャンセル
                    </button>
                </div>
            </div>

            <div class="bg-white border rounded-2xl p-5 mb-5">
                <div class="grid md:grid-cols-4 gap-4">

                    <label>
                        <span class="text-sm text-slate-500">
                            開始日時
                        </span>
                        <input
                            id="survey_start_at"
                            type="datetime-local"
                            class="w-full border rounded-lg px-3 py-2"
                            value="${App.util.escape(s.start_at)}"
                            onchange="App.State.survey.start_at=this.value">
                    </label>

                    <label>
                        <span class="text-sm text-slate-500">
                            終了日時
                        </span>
                        <input
                            id="survey_end_at"
                            type="datetime-local"
                            class="w-full border rounded-lg px-3 py-2"
                            value="${App.util.escape(s.end_at)}"
                            onchange="App.State.survey.end_at=this.value">
                    </label>

                    <label>
                        <span class="text-sm text-slate-500">
                            質問番号
                        </span>
                        <select
                            id="survey_numbering_mode"
                            class="w-full border rounded-lg px-3 py-2"
                            onchange="App.State.survey.numbering_mode=this.value;App.render.current()">
                            <option value="global"
                                ${s.numbering_mode==='global'?'selected':''}>
                                Q1 / Q2 / Q3
                            </option>
                            <option value="group"
                                ${s.numbering_mode==='group'?'selected':''}>
                                Q1-1 / Q1-2
                            </option>
                        </select>
                    </label>

                    <label>
                        <span class="text-sm text-slate-500">
                            ステータス
                        </span>
                        <select
                            class="w-full border rounded-lg px-3 py-2"
                            onchange="App.State.survey.status=this.value">
                            <option value="draft"
                                ${s.status==='draft'?'selected':''}>
                                下書き
                            </option>
                            <option value="active"
                                ${s.status==='active'?'selected':''}>
                                公開中
                            </option>
                            <option value="ended"
                                ${s.status==='ended'?'selected':''}>
                                終了
                            </option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="flex justify-between items-center mb-3">
                <h2 class="text-xl font-bold">
                    質問構成
                </h2>

                <button
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg"
                    onclick="App.actions.addGroup()">
                    ＋ グループを追加
                </button>
            </div>

            <div id="group-list">
                ${groups}
            </div>

        </main>

        ${App.render.previewModal()}
    `;
};

App.render.previewModal = function() {
    return `
        <div
            id="preview_modal"
            class="hidden fixed inset-0 z-50 bg-black/50 p-6">

            <div class="bg-white rounded-2xl max-w-3xl mx-auto h-full overflow-auto">

                <div class="sticky top-0 bg-white border-b px-5 py-4 flex justify-between">
                    <div class="font-bold">
                        プレビュー
                    </div>

                    <button
                        onclick="App.actions.closePreview()"
                        class="text-xl">
                        ×
                    </button>
                </div>

                <div id="preview_content" class="p-6"></div>

            </div>
        </div>
    `;
};

App.render.previewHtml = function() {
    const s = App.State.survey;

    let html = `
        <div class="max-w-xl mx-auto">
            <h1 class="text-2xl font-bold mb-6">
                ${App.util.escape(s.title)}
            </h1>
    `;

    App.util.numberQuestions()
        .forEach(item => {
            const q = item.q;

            html += `
                <div class="mb-7">
                    <div class="font-semibold mb-2">
                        ${item.number}.
                        ${App.util.escape(q.text)}
                        ${q.required
                            ? '<span class="text-red-500">*</span>'
                            : ''}
                    </div>
            `;

            if (q.type === 'text') {
                html += `
                    <textarea
                        class="w-full border rounded-lg p-3"
                        rows="4"></textarea>
                `;
            } else {
                q.options.forEach(option => {
                    html += `
                        <label class="flex gap-2 items-center mb-2">
                            <input
                                type="${q.type==='single'?'radio':'checkbox'}"
                                name="${q.id}">
                            ${App.util.escape(option)}
                        </label>
                    `;
                });

                if (q.other_enabled) {
                    html += `
                        <input
                            class="border rounded-lg px-3 py-2 mt-2 w-full"
                            placeholder="その他">
                    `;
                }
            }

            html += '</div>';
        });

    html += `
            <button
                class="w-full bg-blue-600 text-white rounded-lg py-3"
                onclick="App.actions.previewSubmit()">
                送信
            </button>
        </div>
    `;

    return html;
};

/* =========================================================
 * settings render
 * ========================================================= */

App.render.fieldSelect = function(
    key,
    label,
    multi = false
) {
    const current =
        App.State.settings[key] || '';

    const fields =
        App.State.kintoneFields || [];

    if (multi) {
        const values =
            Array.isArray(current)
            ? current
            : [];

        return `
            <div class="border rounded-xl p-3">
                <div class="font-semibold mb-2">
                    ${label}
                </div>

                ${values.map((value,index) => `
                    <div class="flex gap-2 mb-2">
                        <select
                            class="flex-1 border rounded-lg px-3 py-2"
                            onchange="App.actions.updateAddress(${index},this.value)">
                            <option value="">
                                選択してください
                            </option>
                            ${fields.map(f => `
                                <option
                                    value="${App.util.escape(f.code)}"
                                    ${f.code===value?'selected':''}>
                                    ${App.util.escape(f.label)}
                                    (${App.util.escape(f.code)})
                                </option>
                            `).join('')}
                        </select>

                        <button
                            class="text-red-500 px-2"
                            onclick="App.actions.removeAddressField(${index})">
                            ×
                        </button>
                    </div>
                `).join('')}

                <button
                    class="text-blue-600 text-sm"
                    onclick="App.actions.addAddressField()">
                    ＋ 住所項目追加
                </button>
            </div>
        `;
    }

    return `
        <label class="block border rounded-xl p-3">
            <div class="font-semibold mb-2">
                ${label}
            </div>

            <select
                class="w-full border rounded-lg px-3 py-2"
                onchange="App.actions.updateSetting('${key}',this.value)">
                <option value="">
                    選択してください
                </option>

                ${fields.map(f => `
                    <option
                        value="${App.util.escape(f.code)}"
                        ${f.code===current?'selected':''}>
                        ${App.util.escape(f.label)}
                        (${App.util.escape(f.code)})
                    </option>
                `).join('')}
            </select>
        </label>
    `;
};

App.render.settings = function() {
    const s = App.State.settings;

    return `
        ${App.render.header()}

        <main class="max-w-5xl mx-auto p-6">

            <div class="mb-6">
                <h1 class="text-2xl font-bold">
                    kintone連携設定
                </h1>
                <p class="text-sm text-slate-500 mt-1">
                    kintoneの顧客管理アプリとの接続を設定します。
                </p>
            </div>

            <div class="bg-white border rounded-2xl p-6">

                <div class="grid md:grid-cols-2 gap-4">

                    <label>
                        <div class="text-sm font-semibold mb-1">
                            サブドメイン
                        </div>
                        <input
                            id="setting_subdomain"
                            class="w-full border rounded-lg px-3 py-2"
                            placeholder="example または example.cybozu.com"
                            value="${App.util.escape(s.subdomain || '')}"
                            oninput="App.actions.updateSetting('subdomain',this.value)">
                    </label>

                    <label>
                        <div class="text-sm font-semibold mb-1">
                            アプリID
                        </div>
                        <input
                            id="setting_app_id"
                            class="w-full border rounded-lg px-3 py-2"
                            value="${App.util.escape(s.app_id || '')}"
                            oninput="App.actions.updateSetting('app_id',this.value)">
                    </label>

                    <label>
                        <div class="text-sm font-semibold mb-1">
                            ログイン名
                        </div>
                        <input
                            id="setting_login_name"
                            class="w-full border rounded-lg px-3 py-2"
                            value="${App.util.escape(s.login_name || '')}"
                            oninput="App.actions.updateSetting('login_name',this.value)">
                    </label>

                    <label>
                        <div class="text-sm font-semibold mb-1">
                            パスワード
                        </div>
                        <input
                            id="setting_password"
                            type="password"
                            class="w-full border rounded-lg px-3 py-2"
                            value="${App.util.escape(s.password || '')}"
                            oninput="App.actions.updateSetting('password',this.value)">
                    </label>

                    <label class="md:col-span-2">
                        <div class="text-sm font-semibold mb-1">
                            Proxy
                        </div>
                        <input
                            id="setting_proxy"
                            class="w-full border rounded-lg px-3 py-2"
                            placeholder="host:port"
                            value="${App.util.escape(s.proxy || '')}"
                            oninput="App.actions.updateSetting('proxy',this.value)">
                    </label>

                    <label class="flex items-center gap-2">
                        <input
                            id="setting_ssl_verify"
                            type="checkbox"
                            ${s.ssl_verify?'checked':''}
                            onchange="App.actions.updateSetting('ssl_verify',this.checked)">
                        SSL証明書を検証する
                    </label>

                </div>

                <div class="flex flex-wrap gap-2 mt-5">

                    <button
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg"
                        onclick="App.api.testKintone()">
                        接続確認
                    </button>

                    <button
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg"
                        onclick="App.api.fetchKintoneFields()">
                        項目一覧を取得
                    </button>

                    <button
                        class="px-4 py-2 bg-white border rounded-lg"
                        onclick="App.actions.saveSettings()">
                        設定を保存
                    </button>

                </div>

                <div
                    id="field_message"
                    class="mt-4 text-sm text-slate-500">
                </div>

                <div class="border-t mt-6 pt-6">

                    <h2 class="font-bold text-lg mb-4">
                        kintone項目マッピング
                    </h2>

                    <div class="grid md:grid-cols-2 gap-4">

                        ${App.render.fieldSelect(
                            'field_company',
                            '会社名 (Company)'
                        )}

                        ${App.render.fieldSelect(
                            'field_name',
                            '氏名 (Name)'
                        )}

                        ${App.render.fieldSelect(
                            'field_email',
                            'メールアドレス (Email)'
                        )}

                        ${App.render.fieldSelect(
                            'field_department',
                            '部署名 (Department)'
                        )}

                        ${App.render.fieldSelect(
                            'field_phone',
                            '電話番号 (Phone)'
                        )}

                        ${App.render.fieldSelect(
                            'field_address',
                            '住所 (Address)',
                            true
                        )}

                    </div>
                </div>

            </div>
        </main>
    `;
};

/* =========================================================
 * mail render
 * ========================================================= */

App.render.mail = function() {
    const s = App.State.survey;

    let customers =
        [...App.State.customers];

    const keyword =
        App.State.customerSearch
            .toLowerCase();

    if (keyword) {
        customers =
            customers.filter(c =>
                String(c.name || '')
                    .toLowerCase()
                    .includes(keyword) ||
                String(c.email || '')
                    .toLowerCase()
                    .includes(keyword) ||
                String(c.company || '')
                    .toLowerCase()
                    .includes(keyword)
            );
    }

    return `
        ${App.render.header()}

        <main class="max-w-7xl mx-auto p-6">

            <div class="mb-5">
                <h1 class="text-2xl font-bold">
                    顧客選択・送信
                </h1>
                <p class="text-slate-500">
                    ${App.util.escape(s.title)}
                </p>
            </div>

            <div class="grid lg:grid-cols-3 gap-5">

                <div class="lg:col-span-1 bg-white border rounded-2xl p-5">
                    <h2 class="font-bold mb-4">
                        メールテンプレート
                    </h2>

                    <select
                        id="template_type"
                        class="w-full border rounded-lg px-3 py-2 mb-3">
                        <option value="initial">
                            初回送信
                        </option>
                        <option value="reminder">
                            リマインド
                        </option>
                    </select>

                    <input
                        id="mail_subject"
                        class="w-full border rounded-lg px-3 py-2 mb-3"
                        placeholder="件名"
                        value="アンケートご協力のお願い">

                    <textarea
                        id="mail_body"
                        class="w-full border rounded-lg p-3"
                        rows="12">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

                    <button
                        class="w-full mt-3 bg-blue-600 text-white rounded-lg py-3 font-semibold"
                        onclick="App.actions.sendMail()">
                        選択した顧客へ送信
                    </button>
                </div>

                <div class="lg:col-span-2 bg-white border rounded-2xl overflow-hidden">

                    <div class="p-4 border-b">
                        <input
                            id="customer_filter"
                            class="w-full border rounded-lg px-3 py-2"
                            placeholder="会社名・氏名・メールアドレス検索"
                            oninput="App.State.customerSearch=this.value;App.render.current()">
                    </div>

                    <div class="overflow-x-auto">
                        <table
                            id="customer_table"
                            class="w-full min-w-[900px] text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-3 py-3">
                                        <input
                                            id="select_all"
                                            type="checkbox"
                                            onchange="document.querySelectorAll('input[name=recipient]').forEach(x=>x.checked=this.checked)">
                                    </th>
                                    <th class="text-left px-3 py-3">会社名 / 氏名</th>
                                    <th class="text-left px-3 py-3">メール</th>
                                    <th class="text-left px-3 py-3">電話</th>
                                    <th class="text-left px-3 py-3">送信履歴</th>
                                    <th class="text-left px-3 py-3">回答</th>
                                    <th class="text-left px-3 py-3">kintone</th>
                                </tr>
                            </thead>

                            <tbody>
                                ${customers.map(c => `
                                    <tr class="border-t">
                                        <td class="px-3 py-3">
                                            ${
                                                c.source === 'web'
                                                ? ''
                                                : `
                                                <input
                                                    type="checkbox"
                                                    name="recipient"
                                                    value="${App.util.escape(c.id)}">
                                                `
                                            }
                                        </td>

                                        <td class="px-3 py-3">
                                            <strong>
                                                ${App.util.escape(c.company)}
                                            </strong>
                                            <br>
                                            ${App.util.escape(c.name)}
                                        </td>

                                        <td class="px-3 py-3">
                                            ${App.util.escape(c.email)}
                                        </td>

                                        <td class="px-3 py-3">
                                            ${App.util.escape(c.phone)}
                                        </td>

                                        <td class="px-3 py-3">
                                            ${c.sent_at || '未送信'}
                                            <br>
                                            ${c.send_count || 0} 回
                                        </td>

                                        <td class="px-3 py-3">
                                            ${
                                                c.answer_status === 'answered'
                                                ? '<span class="text-emerald-600">回答済み</span>'
                                                : '<span class="text-amber-600">未回答</span>'
                                            }
                                        </td>

                                        <td class="px-3 py-3">
                                            ${
                                                c.kintone_status === 'registered'
                                                ? '✓ 登録完了'
                                                : `
                                                <button
                                                    class="text-blue-600"
                                                    onclick="App.actions.registerKintone('${c.id}')">
                                                    未登録
                                                </button>
                                                `
                                            }
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    `;
};

App.actions.registerKintone = async function(id) {
    try {
        await App.api.request(
            'customer_kintone_status',
            'POST',
            {
                customer_id: id
            }
        );

        await App.api.load();
        App.render.current();
    } catch(e) {
        alert(e.message);
    }
};

/* =========================================================
 * aggregate render
 * ========================================================= */

App.render.aggregate = function() {
    const s = App.State.survey;

    const responses =
        App.State.responses.filter(
            r => r.survey_id === s.id
        );

    const customerIds =
        new Set(
            App.State.customers.map(c => c.id)
        );

    const sent =
        App.State.customers.filter(
            c => Number(c.send_count || 0) > 0
        ).length;

    const answeredCustomers =
        new Set(
            responses
                .filter(r =>
                    r.customer_id &&
                    customerIds.has(r.customer_id)
                )
                .map(r => r.customer_id)
        );

    const unanswered =
        Math.max(
            0,
            sent - answeredCustomers.size
        );

    const rate =
        sent
        ? (
            answeredCustomers.size /
            sent *
            100
        ).toFixed(1)
        : '0.0';

    const webResponses =
        responses.filter(
            r =>
                !r.customer_id ||
                !customerIds.has(r.customer_id)
        ).length;

    const nums =
        App.util.numberQuestions();

    nums.forEach(item => {
        if (
            App.State.selectedQuestions[item.q.id] ===
            undefined
        ) {
            App.State.selectedQuestions[item.q.id] =
                true;
        }
    });

    let questions = '';

    nums.forEach(item => {
        const q = item.q;

        if (!App.State.selectedQuestions[q.id]) {
            return;
        }

        if (q.type === 'text') {
            const texts =
                responses
                    .map(r => ({
                        response: r,
                        value: r.answers?.[q.id]
                    }))
                    .filter(x =>
                        x.value !== undefined &&
                        x.value !== ''
                    );

            questions += `
                <div class="bg-white border rounded-2xl p-5 mb-5">
                    <div class="font-bold mb-4">
                        ${item.number}.
                        ${App.util.escape(q.text)}
                        <span class="text-xs bg-slate-100 px-2 py-1 rounded">
                            自由記述
                        </span>
                    </div>

                    ${
                        texts.length
                        ? texts.map(x => `
                            <div class="border-l-4 border-blue-400 pl-4 py-3 mb-3">
                                <div class="text-sm text-slate-500">
                                    ${App.util.escape(x.response.company)}
                                    /
                                    ${App.util.escape(x.response.name)}
                                </div>
                                <div class="whitespace-pre-wrap mt-1">
                                    ${App.util.escape(x.value)}
                                </div>
                            </div>
                        `).join('')
                        : '<div class="text-slate-400">回答データはありません</div>'
                    }
                </div>
            `;
        } else {
            const stats =
                App.util.questionStats(q);

            const max =
                Math.max(
                    1,
                    ...Object.values(stats.counts)
                );

            questions += `
                <div class="bg-white border rounded-2xl p-5 mb-5">

                    <div class="font-bold mb-5">
                        ${item.number}.
                        ${App.util.escape(q.text)}
                    </div>

                    ${Object.entries(stats.counts)
                        .map(([option,count]) => {
                            const percent =
                                stats.total
                                ? count /
                                  stats.total *
                                  100
                                : 0;

                            return `
                                <div class="mb-4">
                                    <div class="flex justify-between text-sm mb-1">
                                        <span>
                                            ${App.util.escape(option)}
                                        </span>
                                        <span>
                                            ${count}件 /
                                            ${percent.toFixed(1)}%
                                        </span>
                                    </div>

                                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                        <div
                                            class="h-full bg-blue-500"
                                            style="width:${percent}%">
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('')}

                    ${
                        q.other_enabled
                        ? `
                            <div class="mt-4 text-sm text-slate-500">
                                その他・自由記述:
                                ${stats.other} 件
                            </div>
                        `
                        : ''
                    }
                </div>
            `;
        }
    });

    let responseRows =
        responses.filter(r => {
            const key =
                App.State.responseSearch
                    .toLowerCase();

            return !key ||
                String(r.company || '')
                    .toLowerCase()
                    .includes(key) ||
                String(r.name || '')
                    .toLowerCase()
                    .includes(key);
        }).map(r => `
            <tr class="border-t">
                <td class="px-3 py-3">
                    ${App.util.escape(r.company)}
                </td>
                <td class="px-3 py-3">
                    ${App.util.escape(r.name)}
                </td>
                <td class="px-3 py-3">
                    ${App.util.escape(r.answered_at)}
                </td>
                <td class="px-3 py-3">
                    <button
                        class="text-blue-600"
                        onclick="App.actions.responseDetail('${r.id}')">
                        全回答を表示
                    </button>
                </td>
            </tr>
        `).join('');

    return `
        ${App.render.header()}

        <main class="max-w-7xl mx-auto p-6">

            <div class="flex justify-between mb-5">
                <div>
                    <h1 class="text-2xl font-bold">
                        集計・分析
                    </h1>
                    <p class="text-slate-500">
                        ${App.util.escape(s.title)}
                    </p>
                </div>

                <form method="post" action="?action=export_csv">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="${App.util.escape(App.State.csrf)}">
                    <input
                        type="hidden"
                        name="survey_id"
                        value="${App.util.escape(s.id)}">
                    <button
                        class="px-4 py-2 bg-emerald-600 text-white rounded-lg">
                        CSV出力
                    </button>
                </form>
            </div>

            <div class="grid md:grid-cols-5 gap-3 mb-6">

                ${[
                    ['送信対象者数', sent + ' 人'],
                    ['回答数', responses.length + ' 件'],
                    ['未登録顧客からの回答', webResponses + ' 件'],
                    ['未回答数', unanswered + ' 人'],
                    ['回答率', rate + ' %']
                ].map(x => `
                    <div class="bg-white border rounded-2xl p-4">
                        <div class="text-xs text-slate-500">
                            ${x[0]}
                        </div>
                        <div class="text-2xl font-bold mt-2">
                            ${x[1]}
                        </div>
                    </div>
                `).join('')}

            </div>

            <div class="bg-white border rounded-2xl p-5 mb-5">

                <div class="flex justify-between mb-4">
                    <h2 class="font-bold">
                        設問表示
                    </h2>

                    <div class="flex gap-2">
                        <button
                            class="text-sm text-blue-600"
                            onclick="App.actions.allQuestions(true)">
                            全選択
                        </button>

                        <button
                            class="text-sm text-blue-600"
                            onclick="App.actions.allQuestions(false)">
                            全解除
                        </button>
                    </div>
                </div>

                <div class="grid md:grid-cols-3 gap-2">
                    ${nums.map(item => `
                        <label class="flex gap-2 items-center p-2 rounded hover:bg-slate-50">
                            <input
                                type="checkbox"
                                ${App.State.selectedQuestions[item.q.id]?'checked':''}
                                onchange="App.actions.toggleQuestion('${item.q.id}',this.checked)">
                            ${item.number}.
                            ${App.util.escape(item.q.text)}
                        </label>
                    `).join('')}
                </div>
            </div>

            ${
                responses.length
                ? questions
                : `
                    <div class="bg-white border rounded-2xl p-16 text-center text-slate-400">
                        現在、回答データはありません
                    </div>
                `
            }

            <div class="bg-white border rounded-2xl overflow-hidden">

                <div class="p-4 border-b">
                    <h2 class="font-bold mb-3">
                        個別回答一覧
                    </h2>

                    <input
                        id="response_filter"
                        class="border rounded-lg px-3 py-2 w-full"
                        placeholder="会社名・氏名検索"
                        value="${App.util.escape(App.State.responseSearch)}"
                        oninput="App.State.responseSearch=this.value;App.render.current()">
                </div>

                <table
                    id="response_table"
                    class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="text-left px-3 py-3">会社名</th>
                            <th class="text-left px-3 py-3">氏名</th>
                            <th class="text-left px-3 py-3">回答日時</th>
                            <th class="text-left px-3 py-3">詳細</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${responseRows}
                    </tbody>
                </table>

            </div>

        </main>

        <div
            id="response_modal"
            class="hidden fixed inset-0 z-50 bg-black/50 p-6">

            <div class="bg-white rounded-2xl max-w-3xl mx-auto max-h-full overflow-auto">

                <div class="sticky top-0 bg-white border-b p-4 flex justify-between">
                    <strong>回答詳細</strong>

                    <button
                        onclick="App.actions.closeResponse()"
                        class="text-xl">
                        ×
                    </button>
                </div>

                <div
                    id="response_detail"
                    class="p-5">
                </div>

            </div>
        </div>
    `;
};

/* =========================================================
 * current render
 * ========================================================= */

App.render.current = function() {
    const root =
        document.getElementById('app');

    if (!root) return;

    if (App.State.screen === 'list') {
        root.innerHTML =
            App.render.list();
    }

    else if (App.State.screen === 'edit') {
        root.innerHTML =
            App.render.edit();

        App.actions.initSortable();
    }

    else if (App.State.screen === 'settings') {
        root.innerHTML =
            App.render.settings();
    }

    else if (App.State.screen === 'mail') {
        root.innerHTML =
            App.render.mail();
    }

    else if (App.State.screen === 'aggregate') {
        root.innerHTML =
            App.render.aggregate();
    }
};

/* =========================================================
 * initialization
 * ========================================================= */

App.init = async function() {
    if (App.initDone) return;

    App.initDone = true;

    try {
        await App.api.load();
        App.render.current();
    } catch (e) {
        document.getElementById('app').innerHTML = `
            <div class="min-h-screen flex items-center justify-center p-6">
                <div class="bg-white border rounded-2xl p-8 max-w-xl">
                    <h1 class="text-xl font-bold text-red-600">
                        初期化に失敗しました
                    </h1>
                    <pre class="mt-4 whitespace-pre-wrap text-sm">${App.util.escape(e.message)}</pre>
                    <button
                        class="mt-5 px-4 py-2 bg-blue-600 text-white rounded-lg"
                        onclick="location.reload()">
                        再読み込み
                    </button>
                </div>
            </div>
        `;
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
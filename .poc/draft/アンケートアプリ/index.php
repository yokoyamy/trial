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

declare(strict_types=1);

const SURVEY_STORAGE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'survey_storage';
const SURVEY_STORAGE_FILE = SURVEY_STORAGE_DIRECTORY . DIRECTORY_SEPARATOR . 'survey_data.json';
const SURVEY_ADMIN_SESSION = 'survey_admin_session_v1';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SURVEY_ADMIN_SESSION);
    session_start();
}

header_remove('X-Powered-By');

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0750, true);
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

function survey_load_data(): array
{
    if (!is_file(SURVEY_STORAGE_FILE)) {
        return survey_default_data();
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

    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

function survey_post_string(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key])
        ? trim($_POST[$key])
        : $default;
}

function survey_get_string(string $key, string $default = ''): string
{
    return isset($_GET[$key]) && is_string($_GET[$key])
        ? trim($_GET[$key])
        : $default;
}

function survey_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function survey_uuid(): string
{
    try {
        return bin2hex(random_bytes(12));
    } catch (Throwable) {
        return uniqid('s_', true);
    }
}

function survey_now(): string
{
    return date('Y-m-d H:i:s');
}

function survey_csrf(): string
{
    if (empty($_SESSION['survey_csrf_token'])) {
        $_SESSION['survey_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['survey_csrf_token'];
}

function survey_verify_csrf(): void
{
    $token = survey_post_string('csrf_token');

    if (
        $token === '' ||
        empty($_SESSION['survey_csrf_token']) ||
        !hash_equals($_SESSION['survey_csrf_token'], $token)
    ) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。ページを再読み込みしてください。'
        ], 403);
    }
}

function survey_find_survey(array $data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if (($survey['id'] ?? '') === $id && empty($survey['deleted'])) {
            return $survey;
        }
    }

    return null;
}

function survey_find_survey_index(array $data, string $id): int
{
    foreach ($data['surveys'] as $i => $survey) {
        if (($survey['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function survey_questions(array $survey): array
{
    $result = [];

    foreach (($survey['groups'] ?? []) as $group) {
        foreach (($group['questions'] ?? []) as $question) {
            $result[] = $question;
        }
    }

    return $result;
}

function survey_question_map(array $survey): array
{
    $map = [];

    foreach (survey_questions($survey) as $question) {
        $map[$question['id']] = $question;
    }

    return $map;
}

/**
 * http_get_last_response_headers() が利用可能なPHPで安全に取得する。
 * 利用できない環境でも $http_response_header にフォールバック。
 */
function get_safe_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? $headers : [];
    }

    if (isset($http_response_header) && is_array($http_response_header)) {
        return $http_response_header;
    }

    return [];
}

/**
 * kintone URLの成形。
 *
 * xxxx
 * xxxx.cybozu.com
 * https://xxxx.cybozu.com
 * のいずれも許容する。
 */
function kintone_build_url(string $domain, string $endpoint): string
{
    $domain = trim($domain);

    $domain = preg_replace(
        '/^https?:\/\//i',
        '',
        $domain
    ) ?? $domain;

    $domain = preg_replace(
        '/\.cybozu\.com.*$/i',
        '',
        $domain
    ) ?? $domain;

    $domain = trim($domain, "/ \t\r\n");

    $endpoint = '/' . ltrim($endpoint, '/');

    return 'https://' . $domain . '.cybozu.com' . $endpoint;
}

/**
 * Proxy host:port
 */
function kintone_proxy_parts(string $proxy): ?array
{
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match('/^[a-zA-Z0-9.\-]+:\d+$/', $proxy)) {
        return null;
    }

    return explode(':', $proxy, 2);
}

/**
 * cURLを使用しないkintone API共通通信。
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
        $httpOptions['content'] = is_string($payload)
            ? $payload
            : json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
    }

    $verify = !empty($config['ssl_verify']);

    $contextOptions = [
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify
        ]
    ];

    $proxy = trim((string)($config['proxy'] ?? ''));

    if ($proxy !== '') {
        $parts = kintone_proxy_parts($proxy);

        if ($parts !== null) {
            $contextOptions['http']['proxy'] = 'tcp://' . $proxy;
            $contextOptions['http']['request_fulluri'] = true;
        }
    }

    $context = stream_context_create($contextOptions);

    $body = @file_get_contents(
        $url,
        false,
        $context
    );

    $headersReceived = get_safe_response_headers();

    $statusCode = 500;

    foreach ($headersReceived as $headerLine) {
        if (
            preg_match(
                '/^HTTP\/[\d.]+\s+(\d+)/i',
                $headerLine,
                $m
            )
        ) {
            $statusCode = (int)$m[1];
        }
    }

    $decoded = json_decode(
        is_string($body) ? $body : '',
        true
    );

    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'success' => true,
            'status' => $statusCode,
            'data' => is_array($decoded) ? $decoded : []
        ];
    }

    $message = 'kintone API 通信エラーが発生しました。';

    if (is_array($decoded) && isset($decoded['message'])) {
        $message = (string)$decoded['message'];
    }

    if (
        is_array($decoded) &&
        isset($decoded['id']) &&
        is_string($decoded['id'])
    ) {
        $message .= ' [' . $decoded['id'] . ']';
    }

    return [
        'success' => false,
        'status' => $statusCode,
        'message' => $message,
        'raw' => is_array($decoded) ? $decoded : [],
        'body' => is_string($body) ? $body : ''
    ];
}

function make_cybozu_auth_header(
    string $login_name,
    string $password
): string {
    return 'X-Cybozu-Authorization: ' .
        base64_encode(
            trim($login_name) . ':' . trim($password)
        );
}

/**
 * kintone項目一覧取得。
 *
 * 重要:
 * GETの場合 app は JSON body ではなく、
 * ?app=123 のクエリパラメータで渡す。
 */
function kintone_fetch_fields(array $settings): array
{
    $domain = trim((string)($settings['subdomain'] ?? ''));
    $login = trim((string)($settings['login_name'] ?? ''));
    $password = (string)($settings['password'] ?? '');
    $appId = trim((string)($settings['app_id'] ?? ''));

    if ($domain === '') {
        return [
            'success' => false,
            'status' => 400,
            'message' => 'サブドメインを入力してください。'
        ];
    }

    if ($login === '' || $password === '') {
        return [
            'success' => false,
            'status' => 400,
            'message' => 'ログイン名とパスワードを入力してください。'
        ];
    }

    if ($appId === '' || !ctype_digit($appId)) {
        return [
            'success' => false,
            'status' => 400,
            'message' => '顧客管理アプリIDは数字で入力してください。'
        ];
    }

    $url = kintone_build_url(
        $domain,
        '/k/v1/app/form/fields.json'
    );

    /*
     * ★ここが重要
     *
     * kintone REST API の GET パラメータを
     * URLの ?app=... として送る。
     *
     * JSON body に app を入れると
     * CB_IL02 / 不正なリクエスト
     * になる。
     */
    $url .= '?app=' . rawurlencode($appId);

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password),
        'User-Agent: SurveyManager/1.0'
    ];

    return kintone_api_request(
        'GET',
        $url,
        $headers,
        null,
        $settings
    );
}

function kintone_add_customer(
    array $settings,
    array $customer
): array {
    $domain = trim((string)($settings['subdomain'] ?? ''));
    $login = trim((string)($settings['login_name'] ?? ''));
    $password = (string)($settings['password'] ?? '');
    $appId = trim((string)($settings['app_id'] ?? ''));

    if ($domain === '' || $login === '' || $password === '' || $appId === '') {
        return [
            'success' => false,
            'status' => 400,
            'message' => 'kintone接続設定が不足しています。'
        ];
    }

    $url = kintone_build_url(
        $domain,
        '/k/v1/record.json'
    );

    $mapping = $settings;

    $record = [];

    $simpleFields = [
        'field_company' => 'company',
        'field_name' => 'name',
        'field_email' => 'email',
        'field_department' => 'department',
        'field_phone' => 'phone'
    ];

    foreach ($simpleFields as $settingKey => $customerKey) {
        $fieldCode = trim((string)($mapping[$settingKey] ?? ''));

        if ($fieldCode !== '') {
            $record[$fieldCode] = [
                'value' => (string)($customer[$customerKey] ?? '')
            ];
        }
    }

    $addressCodes = $mapping['field_address'] ?? [];

    if (is_string($addressCodes)) {
        $addressCodes = array_filter(
            array_map('trim', explode(',', $addressCodes))
        );
    }

    if (is_array($addressCodes)) {
        $addressValue = (string)($customer['address'] ?? '');

        foreach ($addressCodes as $code) {
            $code = trim((string)$code);

            if ($code !== '') {
                $record[$code] = [
                    'value' => $addressValue
                ];
            }
        }
    }

    $payload = [
        'app' => (int)$appId,
        'record' => $record
    ];

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Cybozu-Authorization: ' .
            base64_encode($login . ':' . $password),
        'User-Agent: SurveyManager/1.0'
    ];

    return kintone_api_request(
        'POST',
        $url,
        $headers,
        $payload,
        $settings
    );
}

/* ================================================================
 * API処理
 * ================================================================ */

$action = survey_get_string('action');

if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        survey_verify_csrf();
    }

    $data = survey_load_data();

    switch ($action) {
        case 'load':
            survey_json_response([
                'ok' => true,
                'data' => $data
            ]);
            break;

        case 'save_survey':
            $json = survey_post_string('survey_json');

            $survey = json_decode($json, true);

            if (!is_array($survey)) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートデータが不正です。'
                ], 400);
            }

            $id = (string)($survey['id'] ?? '');

            if ($id === '') {
                $id = survey_uuid();
                $survey['id'] = $id;
                $survey['created_at'] = survey_now();
            }

            $survey['updated_at'] = survey_now();
            $survey['deleted'] = false;

            if (!isset($survey['status'])) {
                $survey['status'] = 'draft';
            }

            $index = survey_find_survey_index($data, $id);

            if ($index >= 0) {
                $data['surveys'][$index] = $survey;
            } else {
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
                'survey' => $survey,
                'data' => $data
            ]);
            break;

        case 'delete_survey':
            $id = survey_post_string('survey_id');

            $index = survey_find_survey_index($data, $id);

            if ($index < 0) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。'
                ], 404);
            }

            $data['surveys'][$index]['deleted'] = true;
            $data['surveys'][$index]['updated_at'] = survey_now();

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'data' => $data
            ]);
            break;

        case 'set_status':
            $id = survey_post_string('survey_id');
            $status = survey_post_string('status');

            if (!in_array($status, ['draft', 'active', 'ended'], true)) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'ステータスが不正です。'
                ], 400);
            }

            $index = survey_find_survey_index($data, $id);

            if ($index < 0) {
                survey_json_response([
                    'ok' => false,
                    'message' => 'アンケートが見つかりません。'
                ], 404);
            }

            $data['surveys'][$index]['status'] = $status;
            $data['surveys'][$index]['updated_at'] = survey_now();

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'data' => $data
            ]);
            break;

        case 'duplicate_survey':
            $id = survey_post_string('survey_id');
            $survey = survey_find_survey($data, $id);

            if ($survey === null) {
                survey_json_response([
                    'ok' => false,
                    'message' => '複製元アンケートが見つかりません。'
                ], 404);
            }

            $copy = $survey;
            $copy['id'] = survey_uuid();
            $copy['title'] = ($copy['title'] ?? '') . '（コピー）';
            $copy['status'] = 'draft';
            $copy['created_at'] = survey_now();
            $copy['updated_at'] = survey_now();
            $copy['deleted'] = false;

            $data['surveys'][] = $copy;

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'survey' => $copy,
                'data' => $data
            ]);
            break;

        case 'save_settings':
            $json = survey_post_string('settings_json');
            $settings = json_decode($json, true);

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '設定データが不正です。'
                ], 400);
            }

            $settings['ssl_verify'] = !empty($settings['ssl_verify']);

            if (!isset($settings['field_address'])) {
                $settings['field_address'] = [];
            }

            if (is_string($settings['field_address'])) {
                $settings['field_address'] = array_values(
                    array_filter(
                        array_map(
                            'trim',
                            explode(',', $settings['field_address'])
                        )
                    )
                );
            }

            $data['settings'] = array_merge(
                $data['settings'],
                $settings
            );

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'settings' => $data['settings']
            ]);
            break;

        case 'kintone_fields':
            /*
             * POST app_id を受け取って設定に反映。
             * その後、正しい GET ?app=... でAPIを呼ぶ。
             */
            $appId = survey_post_string('app_id');

            if ($appId !== '') {
                $data['settings']['app_id'] = $appId;
            }

            $result = kintone_fetch_fields($data['settings']);

            if (!$result['success']) {
                $message =
                    'kintone項目一覧取得に失敗しました。 ' .
                    'HTTP ' . (int)$result['status'] . '. ' .
                    ($result['message'] ?? '');

                survey_json_response([
                    'ok' => false,
                    'message' => $message,
                    'status' => $result['status'],
                    'raw' => $result['raw'] ?? []
                ], 400);
            }

            survey_json_response([
                'ok' => true,
                'fields' => $result['data']['properties'] ?? []
            ]);
            break;

        case 'mark_kintone_registered':
            $customerId = survey_post_string('customer_id');

            foreach ($data['customers'] as &$customer) {
                if (($customer['id'] ?? '') === $customerId) {
                    $customer['kintone_status'] = 'registered';
                    break;
                }
            }
            unset($customer);

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'data' => $data
            ]);
            break;

        case 'register_kintone':
            $customerId = survey_post_string('customer_id');
            $customer = null;

            foreach ($data['customers'] as $item) {
                if (($item['id'] ?? '') === $customerId) {
                    $customer = $item;
                    break;
                }
            }

            if ($customer === null) {
                survey_json_response([
                    'ok' => false,
                    'message' => '顧客が見つかりません。'
                ], 404);
            }

            $result = kintone_add_customer(
                $data['settings'],
                $customer
            );

            if (!$result['success']) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'kintone登録に失敗しました。 HTTP ' .
                        $result['status'] . '. ' .
                        ($result['message'] ?? ''),
                    'raw' => $result['raw'] ?? []
                ], 400);
            }

            foreach ($data['customers'] as &$item) {
                if (($item['id'] ?? '') === $customerId) {
                    $item['kintone_status'] = 'registered';
                    break;
                }
            }
            unset($item);

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'data' => $data
            ]);
            break;

        case 'send_mail':
            $surveyId = survey_post_string('survey_id');
            $recipientIdsRaw = survey_post_string('recipient_ids');
            $subject = survey_post_string('mail_subject');
            $body = survey_post_string('mail_body');
            $templateType = survey_post_string(
                'template_type',
                'initial'
            );

            $ids = json_decode($recipientIdsRaw, true);

            if (!is_array($ids)) {
                $ids = [];
            }

            if ($subject === '' || $body === '') {
                survey_json_response([
                    'ok' => false,
                    'message' => '件名と本文を入力してください。'
                ], 400);
            }

            $sent = 0;
            $errors = [];
            $sentMessages = [];

            foreach ($data['customers'] as &$customer) {
                if (!in_array($customer['id'] ?? '', $ids, true)) {
                    continue;
                }

                $email = trim((string)($customer['email'] ?? ''));

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] =
                        ($customer['name'] ?? '') .
                        '：メールアドレス不正';
                    continue;
                }

                $surveyUrl =
                    (isset($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS'] !== 'off'
                        ? 'https'
                        : 'http') .
                    '://' .
                    ($_SERVER['HTTP_HOST'] ?? '') .
                    dirname($_SERVER['SCRIPT_NAME'] ?? '/') .
                    '/index.php?public=1&survey_id=' .
                    rawurlencode($surveyId) .
                    '&customer_id=' .
                    rawurlencode($customer['id']);

                $replace = [
                    '{顧客名}' => (string)($customer['name'] ?? ''),
                    '{アンケートURL}' => $surveyUrl
                ];

                $actualSubject = strtr($subject, $replace);
                $actualBody = strtr($body, $replace);

                $headers = [
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                    'From: ' .
                        'survey@' .
                        ($_SERVER['HTTP_HOST'] ?? 'localhost')
                ];

                $success = @mail(
                    $email,
                    $actualSubject,
                    $actualBody,
                    implode("\r\n", $headers)
                );

                /*
                 * mail() が利用できない環境でも、
                 * 送信履歴テストができるようにはしない。
                 * 実送信成功のみ送信済みとする。
                 */
                if ($success) {
                    $customer['sent_at'] = survey_now();
                    $customer['send_count'] =
                        (int)($customer['send_count'] ?? 0) + 1;
                    $customer['answer_status'] = 'unanswered';

                    $sent++;

                    $sentMessages[] = [
                        'customer_id' => $customer['id'],
                        'email' => $email,
                        'subject' => $actualSubject,
                        'body' => $actualBody
                    ];
                }
            }
            unset($customer);

            if ($sent > 0) {
                $data['mail_logs'][] = [
                    'id' => survey_uuid(),
                    'survey_id' => $surveyId,
                    'sent_at' => survey_now(),
                    'template_type' => $templateType,
                    'count' => $sent,
                    'subject' => $subject,
                    'executed_by' => $_SESSION['survey_admin_user'] ?? 'admin',
                    'messages' => $sentMessages
                ];
            }

            survey_save_data($data);

            survey_json_response([
                'ok' => $sent > 0,
                'sent' => $sent,
                'errors' => $errors,
                'data' => $data,
                'message' =>
                    $sent . '件送信しました。' .
                    (!empty($errors)
                        ? ' 一部送信できない宛先があります。'
                        : '')
            ]);
            break;

        case 'csv':
            $surveyId = survey_get_string('survey_id');
            $survey = survey_find_survey($data, $surveyId);

            if ($survey === null) {
                http_response_code(404);
                exit('Survey not found');
            }

            $questions = survey_questions($survey);

            $filename =
                'survey_' .
                preg_replace('/[^\w\-]+/u', '_', $survey['title'] ?? 'data') .
                '_' .
                date('YmdHis') .
                '.csv';

            header('Content-Type: text/csv; charset=UTF-8');
            header(
                'Content-Disposition: attachment; filename="' .
                $filename .
                '"'
            );

            echo "\xEF\xBB\xBF";

            $fp = fopen('php://output', 'wb');

            $headerRow = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名'
            ];

            foreach ($questions as $i => $question) {
                $headerRow[] =
                    '設問' . ($i + 1) . ' ' .
                    ($question['text'] ?? '');
            }

            fputcsv($fp, $headerRow);

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

                $answers = $response['answers'] ?? [];

                foreach ($questions as $question) {
                    $value = $answers[$question['id']] ?? '';

                    if (is_array($value)) {
                        $value = implode('、', $value);
                    }

                    $row[] = (string)$value;
                }

                fputcsv($fp, $row);
            }

            fclose($fp);
            exit;

        case 'answer':
            /*
             * 公開アンケート回答。
             */
            $surveyId = survey_post_string('survey_id');
            $customerId = survey_post_string('customer_id');

            $survey = survey_find_survey($data, $surveyId);

            if ($survey === null || ($survey['status'] ?? '') !== 'active') {
                survey_json_response([
                    'ok' => false,
                    'message' => 'このアンケートは公開されていません。'
                ], 400);
            }

            $answersRaw = survey_post_string('answers');
            $answers = json_decode($answersRaw, true);

            if (!is_array($answers)) {
                survey_json_response([
                    'ok' => false,
                    'message' => '回答データが不正です。'
                ], 400);
            }

            $questionMap = survey_question_map($survey);

            foreach ($questionMap as $qid => $question) {
                if (!empty($question['required'])) {
                    $value = $answers[$qid] ?? '';

                    $empty = $value === '' ||
                        $value === null ||
                        (is_array($value) && count($value) === 0);

                    if ($empty) {
                        survey_json_response([
                            'ok' => false,
                            'message' =>
                                '必須設問に回答してください。'
                        ], 400);
                    }
                }
            }

            $customer = null;

            foreach ($data['customers'] as $item) {
                if (
                    $customerId !== '' &&
                    ($item['id'] ?? '') === $customerId
                ) {
                    $customer = $item;
                    break;
                }
            }

            $response = [
                'id' => survey_uuid(),
                'survey_id' => $surveyId,
                'customer_id' => $customer['id'] ?? '',
                'company' => $customer['company'] ?? '',
                'name' => $customer['name'] ?? '',
                'email' => $customer['email'] ?? '',
                'answered_at' => survey_now(),
                'answers' => $answers
            ];

            $data['responses'][] = $response;

            if ($customer !== null) {
                foreach ($data['customers'] as &$item) {
                    if (($item['id'] ?? '') === ($customer['id'] ?? '')) {
                        $item['answer_status'] = 'answered';
                        break;
                    }
                }
                unset($item);
            }

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'message' => '回答を受け付けました。'
            ]);
            break;

        default:
            survey_json_response([
                'ok' => false,
                'message' => '不明なリクエストです。'
            ], 400);
    }
}

/* ================================================================
 * 公開回答画面
 * ================================================================ */

if (isset($_GET['public']) && $_GET['public'] === '1') {
    $data = survey_load_data();

    $surveyId = survey_get_string('survey_id');
    $customerId = survey_get_string('customer_id');

    $survey = survey_find_survey($data, $surveyId);

    if ($survey === null || ($survey['status'] ?? '') !== 'active') {
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
        <body class="bg-slate-50 min-h-screen flex items-center justify-center">
            <div class="bg-white p-8 rounded-2xl shadow max-w-lg w-full text-center">
                <h1 class="text-xl font-bold text-slate-800 mb-3">
                    アンケートを表示できません
                </h1>
                <p class="text-slate-500">
                    このアンケートは公開されていないか、終了しています。
                </p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    $questions = survey_questions($survey);
    $csrf = survey_csrf();
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <script src="https://cdn.tailwindcss.com"></script>
        <title><?= survey_h((string)$survey['title']) ?></title>
    </head>
    <body class="bg-slate-50 min-h-screen">
    <div class="max-w-3xl mx-auto px-4 py-10">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 md:p-10">
            <h1 class="text-2xl font-bold text-slate-900 mb-8">
                <?= survey_h((string)$survey['title']) ?>
            </h1>

            <form id="publicForm" class="space-y-8">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= survey_h($csrf) ?>"
                >

                <input
                    type="hidden"
                    id="publicSurveyId"
                    value="<?= survey_h($surveyId) ?>"
                >

                <input
                    type="hidden"
                    id="publicCustomerId"
                    value="<?= survey_h($customerId) ?>"
                >

                <?php
                $globalNo = 0;
                foreach (($survey['groups'] ?? []) as $gi => $group):
                ?>
                    <section class="border border-slate-200 rounded-xl p-5">
                        <h2 class="text-lg font-bold text-slate-800 mb-6">
                            <?= survey_h((string)($group['name'] ?? 'グループ')) ?>
                        </h2>

                        <div class="space-y-7">
                            <?php foreach (($group['questions'] ?? []) as $qi => $question):
                                $globalNo++;
                                $labelNo =
                                    (($survey['numbering_mode'] ?? 'global') === 'group')
                                    ? 'Q' . ($gi + 1) . '-' . ($qi + 1)
                                    : 'Q' . $globalNo;
                            ?>
                            <div
                                data-question-id="<?= survey_h((string)$question['id']) ?>"
                                class="question-block"
                            >
                                <label class="block font-semibold text-slate-800 mb-3">
                                    <?= survey_h($labelNo) ?>.
                                    <?= survey_h((string)($question['text'] ?? '')) ?>
                                    <?php if (!empty($question['required'])): ?>
                                        <span class="text-red-500 text-sm">必須</span>
                                    <?php endif; ?>
                                </label>

                                <?php
                                $type = $question['type'] ?? 'single';
                                $qid = (string)$question['id'];
                                ?>

                                <?php if ($type === 'single'): ?>
                                    <div class="space-y-2">
                                    <?php foreach (($question['options'] ?? []) as $option): ?>
                                        <label class="flex items-center gap-2 p-3 rounded-lg hover:bg-slate-50 cursor-pointer">
                                            <input
                                                type="radio"
                                                name="q_<?= survey_h($qid) ?>"
                                                value="<?= survey_h((string)$option) ?>"
                                                class="accent-indigo-600"
                                                data-qid="<?= survey_h($qid) ?>"
                                                onchange="window.PublicSurvey.change('<?= survey_h($qid) ?>')"
                                            >
                                            <span><?= survey_h((string)$option) ?></span>
                                        </label>
                                    <?php endforeach; ?>

                                    <?php if (!empty($question['other_enabled'])): ?>
                                        <label class="flex items-center gap-2 p-3 rounded-lg hover:bg-slate-50 cursor-pointer">
                                            <input
                                                type="radio"
                                                name="q_<?= survey_h($qid) ?>"
                                                value="その他"
                                                class="accent-indigo-600"
                                                data-qid="<?= survey_h($qid) ?>"
                                                onchange="window.PublicSurvey.change('<?= survey_h($qid) ?>')"
                                            >
                                            <span>その他</span>
                                        </label>

                                        <input
                                            type="text"
                                            id="other_<?= survey_h($qid) ?>"
                                            class="hidden w-full border border-slate-300 rounded-lg px-3 py-2"
                                            placeholder="その他の内容"
                                        >
                                    <?php endif; ?>
                                    </div>

                                <?php elseif ($type === 'multiple'): ?>
                                    <div class="space-y-2">
                                    <?php foreach (($question['options'] ?? []) as $option): ?>
                                        <label class="flex items-center gap-2 p-3 rounded-lg hover:bg-slate-50 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                name="q_<?= survey_h($qid) ?>[]"
                                                value="<?= survey_h((string)$option) ?>"
                                                class="accent-indigo-600"
                                            >
                                            <span><?= survey_h((string)$option) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>

                                <?php else: ?>
                                    <textarea
                                        name="q_<?= survey_h($qid) ?>"
                                        rows="5"
                                        class="w-full border border-slate-300 rounded-xl px-4 py-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                    ></textarea>
                                <?php endif; ?>

                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <button
                    type="button"
                    onclick="window.PublicSurvey.submit()"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-xl"
                >
                    回答を送信する
                </button>
            </form>
        </div>
    </div>

    <script>
    window.PublicSurvey = {
        change: function(qid) {
            var other = document.getElementById('other_' + qid);
            if (!other) return;

            var checked = document.querySelector(
                'input[name="q_' + CSS.escape(qid) + '"]:checked'
            );

            if (checked && checked.value === 'その他') {
                other.classList.remove('hidden');
            } else {
                other.classList.add('hidden');
                other.value = '';
            }
        },

        submit: async function() {
            var form = document.getElementById('publicForm');
            var fd = new FormData(form);
            var answers = {};

            document.querySelectorAll('[data-question-id]').forEach(function(block) {
                var qid = block.dataset.questionId;

                var radios = block.querySelectorAll(
                    'input[type="radio"][name="q_' + CSS.escape(qid) + '"]'
                );

                if (radios.length) {
                    var checked = block.querySelector(
                        'input[type="radio"][name="q_' + CSS.escape(qid) + '"]:checked'
                    );

                    if (checked) {
                        if (checked.value === 'その他') {
                            var other = document.getElementById('other_' + qid);
                            answers[qid] = other && other.value
                                ? 'その他: ' + other.value
                                : 'その他';
                        } else {
                            answers[qid] = checked.value;
                        }
                    }

                    return;
                }

                var checks = block.querySelectorAll(
                    'input[type="checkbox"][name="q_' + CSS.escape(qid) + '[]"]'
                );

                if (checks.length) {
                    answers[qid] = Array.from(checks)
                        .filter(function(x) { return x.checked; })
                        .map(function(x) { return x.value; });
                    return;
                }

                var textarea = block.querySelector('textarea');

                if (textarea) {
                    answers[qid] = textarea.value;
                }
            });

            fd.set('action', 'answer');
            fd.set('survey_id', document.getElementById('publicSurveyId').value);
            fd.set('customer_id', document.getElementById('publicCustomerId').value);
            fd.set('answers', JSON.stringify(answers));

            var response = await fetch(location.pathname, {
                method: 'POST',
                body: fd
            });

            var result = await response.json();

            if (!result.ok) {
                alert(result.message || '送信に失敗しました。');
                return;
            }

            document.getElementById('publicForm').innerHTML =
                '<div class="text-center py-16">' +
                '<div class="text-5xl mb-5">✓</div>' +
                '<h2 class="text-2xl font-bold text-slate-800 mb-3">回答ありがとうございました</h2>' +
                '<p class="text-slate-500">回答を正常に受け付けました。</p>' +
                '</div>';
        }
    };
    </script>
    </body>
    </html>
    <?php
    exit;
}

/* ================================================================
 * 管理画面
 * ================================================================ */

$csrf = survey_csrf();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<title>アンケート管理システム</title>
</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {
    state: {
        data: null,
        page: 'list',
        survey: null,
        selectedSurveyId: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        customerFilter: '',
        responseFilter: '',
        previewMode: 'pc',
        selectedQuestions: {},
        addressFields: [],
        kintoneFields: {}
    },

    api: {},

    actions: {},

    render: {},

    templates: {},

    init: async function() {
        if (this.state.initialized) return;
        this.state.initialized = true;

        await this.api.load();
        this.render.app();
    }
};

window.App.templates.escape = function(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

window.App.templates.statusBadge = function(status) {
    var map = {
        active: ['公開中', 'bg-emerald-100 text-emerald-700'],
        draft: ['下書き', 'bg-slate-200 text-slate-700'],
        ended: ['終了', 'bg-amber-100 text-amber-700']
    };

    var item = map[status] || ['不明', 'bg-slate-100 text-slate-600'];

    return '<span class="px-2.5 py-1 rounded-full text-xs font-semibold ' +
        item[1] + '">' + item[0] + '</span>';
};

/* ---------------------------------------------------------------
 * surveyRow
 * ※以前の App.templates.surveyRow is not a function 問題を防ぐ
 * --------------------------------------------------------------- */
window.App.templates.surveyRow = function(survey) {
    var e = window.App.templates.escape;
    var answers = window.App.state.data.responses.filter(function(r) {
        return r.survey_id === survey.id;
    }).length;

    var actions = '';

    actions +=
        '<button onclick="App.actions.editSurvey(\'' +
        e(survey.id) +
        '\')" class="px-2 py-1 rounded bg-slate-100 hover:bg-slate-200 text-xs">確認・編集</button>';

    if (survey.status === 'active') {
        actions +=
            '<button onclick="App.actions.analysis(\'' +
            e(survey.id) +
            '\')" class="px-2 py-1 rounded bg-indigo-50 text-indigo-700 text-xs">集計</button>';

        actions +=
            '<button onclick="App.actions.send(\'' +
            e(survey.id) +
            '\')" class="px-2 py-1 rounded bg-indigo-600 text-white text-xs">送信</button>';

        actions +=
            '<button onclick="App.actions.setStatus(\'' +
            e(survey.id) +
            '\',\'ended\')" class="px-2 py-1 rounded bg-rose-50 text-rose-700 text-xs">停止</button>';

    } else if (survey.status === 'draft') {
        actions +=
            '<button onclick="App.actions.deleteSurvey(\'' +
            e(survey.id) +
            '\')" class="px-2 py-1 rounded bg-rose-50 text-rose-700 text-xs">削除</button>';

        actions +=
            '<button onclick="App.actions.setStatus(\'' +
            e(survey.id) +
            '\',\'active\')" class="px-2 py-1 rounded bg-emerald-600 text-white text-xs">公開</button>';

    } else if (survey.status === 'ended') {
        actions +=
            '<button onclick="App.actions.setStatus(\'' +
            e(survey.id) +
            '\',\'active\')" class="px-2 py-1 rounded bg-emerald-600 text-white text-xs">再開</button>';

        actions +=
            '<button onclick="App.actions.analysis(\'' +
            e(survey.id) +
            '\')" class="px-2 py-1 rounded bg-indigo-50 text-indigo-700 text-xs">集計</button>';
    }

    actions +=
        '<button onclick="App.actions.duplicateSurvey(\'' +
        e(survey.id) +
        '\')" class="px-2 py-1 rounded bg-slate-100 hover:bg-slate-200 text-xs">複製</button>';

    return `
    <tr class="border-b border-slate-100 hover:bg-slate-50">
        <td class="p-4">
            <div class="text-xs text-slate-500">${e(survey.created_at || '')}</div>
            <div class="text-xs text-slate-400">更新: ${e(survey.updated_at || '')}</div>
        </td>
        <td class="p-4">
            <div class="font-bold">${e(survey.title || '無題')}</div>
        </td>
        <td class="p-4 text-sm">
            ${e(survey.start_at || '未設定')}
            <span class="text-slate-400">～</span>
            ${e(survey.end_at || '未設定')}
        </td>
        <td class="p-4">${window.App.templates.statusBadge(survey.status)}</td>
        <td class="p-4 font-semibold">${answers} 件</td>
        <td class="p-4">
            <div class="flex flex-wrap gap-1">${actions}</div>
        </td>
    </tr>`;
};

window.App.api.request = async function(action, data, method) {
    method = method || 'POST';

    var url = location.pathname + '?action=' + encodeURIComponent(action);

    var options = {
        method: method,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    };

    if (method === 'GET') {
        /* no-op */
    } else {
        var fd = new FormData();

        fd.append(
            'csrf_token',
            document.getElementById('csrf_token').value
        );

        Object.keys(data || {}).forEach(function(key) {
            var value = data[key];

            if (typeof value === 'object') {
                value = JSON.stringify(value);
            }

            fd.append(key, value == null ? '' : value);
        });

        options.body = fd;
    }

    var response = await fetch(url, options);

    if (!response.ok && response.status !== 400) {
        throw new Error('HTTP ' + response.status);
    }

    return await response.json();
};

window.App.api.load = async function() {
    var result = await fetch(
        location.pathname + '?action=load',
        {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }
    );

    var json = await result.json();

    if (!json.ok) {
        throw new Error(json.message || 'データ取得失敗');
    }

    window.App.state.data = json.data;
};

window.App.api.saveSurvey = async function(survey) {
    var result = await window.App.api.request(
        'save_survey',
        {
            survey_json: survey
        }
    );

    if (!result.ok) {
        throw new Error(result.message);
    }

    window.App.state.data = result.data;
};

window.App.render.app = function() {
    var app = document.getElementById('app');

    app.innerHTML = `
    <input type="hidden" id="csrf_token" value="<?= survey_h($csrf) ?>">

    <header class="bg-white border-b border-slate-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-5 h-16 flex items-center justify-between">
            <div class="font-bold text-lg">
                アンケート管理システム
            </div>

            <nav class="flex gap-2">
                <button
                    onclick="App.actions.home()"
                    class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100"
                >アンケート一覧</button>

                <button
                    onclick="App.actions.settings()"
                    class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100"
                >キントーン連携設定</button>

                <button
                    onclick="App.actions.logout()"
                    class="px-3 py-2 rounded-lg text-sm hover:bg-slate-100"
                >ログアウト</button>
            </nav>
        </div>
    </header>

    <main id="main" class="max-w-7xl mx-auto p-5"></main>
    `;

    window.App.render.page();
};

window.App.render.page = function() {
    var main = document.getElementById('main');

    if (window.App.state.page === 'list') {
        window.App.render.list();
    } else if (window.App.state.page === 'edit') {
        window.App.render.edit();
    } else if (window.App.state.page === 'analysis') {
        window.App.render.analysis();
    } else if (window.App.state.page === 'send') {
        window.App.render.send();
    } else if (window.App.state.page === 'settings') {
        window.App.render.settings();
    }
};

window.App.render.list = function() {
    var data = window.App.state.data;

    var surveys = (data.surveys || [])
        .filter(function(s) {
            return !s.deleted;
        })
        .filter(function(s) {
            if (window.App.state.keyword === '') return true;
            return String(s.title || '')
                .toLowerCase()
                .includes(
                    window.App.state.keyword.toLowerCase()
                );
        })
        .filter(function(s) {
            if (window.App.state.statusFilter === 'all') {
                return true;
            }

            return s.status === window.App.state.statusFilter;
        });

    surveys.sort(function(a, b) {
        if (window.App.state.sort === 'answers_desc') {
            var ac = data.responses.filter(function(r) {
                return r.survey_id === a.id;
            }).length;

            var bc = data.responses.filter(function(r) {
                return r.survey_id === b.id;
            }).length;

            return bc - ac;
        }

        if (window.App.state.sort === 'answers_asc') {
            var ac2 = data.responses.filter(function(r) {
                return r.survey_id === a.id;
            }).length;

            var bc2 = data.responses.filter(function(r) {
                return r.survey_id === b.id;
            }).length;

            return ac2 - bc2;
        }

        if (window.App.state.sort === 'updated_asc') {
            return String(a.updated_at || '')
                .localeCompare(String(b.updated_at || ''));
        }

        return String(b.updated_at || '')
            .localeCompare(String(a.updated_at || ''));
    });

    document.getElementById('main').innerHTML = `
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold">アンケート一覧</h1>
            <p class="text-sm text-slate-500 mt-1">
                アンケートの作成・公開・送信・集計を管理します。
            </p>
        </div>

        <button
            onclick="App.actions.newSurvey()"
            class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-semibold"
        >
            ＋ 新規アンケート作成
        </button>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-4 mb-5">
        <div class="flex flex-wrap gap-3">
            <input
                value="${window.App.templates.escape(window.App.state.keyword)}"
                onkeydown="if(event.key==='Enter') App.actions.search(this.value)"
                placeholder="タイトルを検索"
                class="border border-slate-300 rounded-lg px-3 py-2 w-72"
            >

            <select
                onchange="App.actions.toggleStatusFilter(this.value)"
                class="border border-slate-300 rounded-lg px-3 py-2"
            >
                <option value="all" ${window.App.state.statusFilter === 'all' ? 'selected' : ''}>すべて</option>
                <option value="active" ${window.App.state.statusFilter === 'active' ? 'selected' : ''}>公開中</option>
                <option value="draft" ${window.App.state.statusFilter === 'draft' ? 'selected' : ''}>下書き</option>
                <option value="ended" ${window.App.state.statusFilter === 'ended' ? 'selected' : ''}>終了</option>
            </select>

            <select
                onchange="App.actions.sort(this.value)"
                class="border border-slate-300 rounded-lg px-3 py-2"
            >
                <option value="updated_desc">更新日：新しい順</option>
                <option value="updated_asc">更新日：古い順</option>
                <option value="answers_desc">回答数：多い順</option>
                <option value="answers_asc">回答数：少ない順</option>
            </select>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left min-w-[1100px]">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="p-4">作成 / 更新</th>
                        <th class="p-4">タイトル</th>
                        <th class="p-4">アンケート期間</th>
                        <th class="p-4">ステータス</th>
                        <th class="p-4">回答数</th>
                        <th class="p-4">操作</th>
                    </tr>
                </thead>
                <tbody>
                    ${surveys.length
                        ? surveys.map(window.App.templates.surveyRow).join('')
                        : `
                        <tr>
                            <td colspan="6" class="p-12 text-center text-slate-400">
                                アンケートがありません。
                            </td>
                        </tr>`}
                </tbody>
            </table>
        </div>
    </div>`;
};

window.App.actions.home = function() {
    window.App.state.page = 'list';
    window.App.render.page();
};

window.App.actions.search = function(value) {
    window.App.state.keyword = value;
    window.App.render.list();
};

window.App.actions.toggleStatusFilter = function(value) {
    window.App.state.statusFilter = value;
    window.App.render.list();
};

window.App.actions.sort = function(value) {
    window.App.state.sort = value;
    window.App.render.list();
};

window.App.actions.newSurvey = function() {
    var now = new Date();

    window.App.state.survey = {
        id: '',
        title: '新しいアンケート',
        start_at: '',
        end_at: '',
        status: 'draft',
        created_at: '',
        updated_at: '',
        numbering_mode: 'global',
        groups: [
            {
                id: window.App.actions.uid(),
                name: '基本情報',
                questions: []
            }
        ],
        deleted: false
    };

    window.App.state.page = 'edit';
    window.App.render.page();
};

window.App.actions.uid = function() {
    return 'q_' +
        Date.now().toString(36) +
        '_' +
        Math.random().toString(36).slice(2, 9);
};

window.App.actions.editSurvey = function(id) {
    var survey = window.App.state.data.surveys.find(function(s) {
        return s.id === id && !s.deleted;
    });

    if (!survey) return;

    window.App.state.survey =
        JSON.parse(JSON.stringify(survey));

    window.App.state.page = 'edit';

    window.App.render.page();
};

window.App.actions.setStatus = async function(id, status) {
    var survey = window.App.state.data.surveys.find(function(s) {
        return s.id === id;
    });

    if (!survey) return;

    var text =
        status === 'active'
            ? 'このアンケートを公開しますか？'
            : status === 'ended'
                ? 'このアンケートを停止しますか？'
                : 'ステータスを変更しますか？';

    if (!confirm(text)) return;

    var result = await window.App.api.request(
        'set_status',
        {
            survey_id: id,
            status: status
        }
    );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    window.App.state.data = result.data;
    window.App.render.list();
};

window.App.actions.deleteSurvey = async function(id) {
    if (!confirm('この下書きを削除しますか？')) return;

    var result = await window.App.api.request(
        'delete_survey',
        {
            survey_id: id
        }
    );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    window.App.state.data = result.data;
    window.App.render.list();
};

window.App.actions.duplicateSurvey = async function(id) {
    var result = await window.App.api.request(
        'duplicate_survey',
        {
            survey_id: id
        }
    );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    window.App.state.data = result.data;
    window.App.render.list();
};

window.App.render.edit = function() {
    var survey = window.App.state.survey;

    var e = window.App.templates.escape;

    document.getElementById('main').innerHTML = `
    <div class="flex items-center justify-between mb-5">
        <div>
            <button
                onclick="App.actions.cancelEdit()"
                class="text-sm text-slate-500 hover:text-slate-800"
            >← 一覧へ戻る</button>

            <h1 class="text-2xl font-bold mt-2">
                アンケート作成・編集
            </h1>
        </div>

        <div class="flex gap-2">
            <button
                onclick="App.actions.preview()"
                class="px-4 py-2 border border-slate-300 rounded-lg bg-white"
            >プレビュー</button>

            <button
                onclick="App.actions.saveSurvey()"
                class="px-5 py-2 bg-indigo-600 text-white rounded-lg font-semibold"
            >保存して一覧へ戻る</button>
        </div>
    </div>

    <div class="space-y-5">

        <section class="bg-white rounded-2xl border border-slate-200 p-6">
            <div class="grid md:grid-cols-3 gap-4">

                <div class="md:col-span-3">
                    <label class="block text-sm font-semibold mb-2">タイトル</label>
                    <input
                        id="survey_title"
                        value="${e(survey.title || '')}"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                    >
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2">開始日時</label>
                    <input
                        id="survey_start_at"
                        type="datetime-local"
                        value="${e((survey.start_at || '').replace(' ', 'T'))}"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                    >
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2">終了日時</label>
                    <input
                        id="survey_end_at"
                        type="datetime-local"
                        value="${e((survey.end_at || '').replace(' ', 'T'))}"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                    >
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2">質問番号</label>
                    <select
                        id="survey_numbering_mode"
                        onchange="App.actions.numberingMode(this.value)"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2"
                    >
                        <option value="global" ${survey.numbering_mode === 'global' ? 'selected' : ''}>Q1, Q2, Q3...</option>
                        <option value="group" ${survey.numbering_mode === 'group' ? 'selected' : ''}>Q1-1, Q1-2...</option>
                    </select>
                </div>

            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-5">
                <div>
                    <h2 class="font-bold text-lg">質問構成</h2>
                    <p class="text-xs text-slate-500">
                        グループ・質問をドラッグして並べ替えできます。
                    </p>
                </div>

                <button
                    onclick="App.actions.addGroup()"
                    class="px-4 py-2 bg-indigo-50 text-indigo-700 rounded-lg font-semibold"
                >＋ グループ追加</button>
            </div>

            <div id="question_editor" class="space-y-5"></div>
        </section>
    </div>

    <div id="preview_modal"></div>
    `;

    window.App.render.questionEditor();
};

window.App.render.questionEditor = function() {
    var survey = window.App.state.survey;
    var editor = document.getElementById('question_editor');

    var html = '';

    survey.groups.forEach(function(group, gi) {
        html += `
        <section
            class="group-card border border-slate-200 rounded-xl overflow-hidden"
            data-group-id="${window.App.templates.escape(group.id)}"
        >
            <div class="bg-slate-50 px-4 py-3 flex items-center gap-3">
                <span class="group-handle cursor-grab text-slate-400 text-xl">⠿</span>

                <input
                    value="${window.App.templates.escape(group.name || '')}"
                    onchange="App.actions.groupName('${window.App.templates.escape(group.id)}', this.value)"
                    class="flex-1 bg-transparent font-bold outline-none"
                >

                <button
                    onclick="App.actions.deleteGroup('${window.App.templates.escape(group.id)}')"
                    class="text-rose-600 text-sm"
                >削除</button>
            </div>

            <div
                class="questions-container p-4 space-y-4"
                data-group-id="${window.App.templates.escape(group.id)}"
            >
            `;

        group.questions.forEach(function(question) {
            html += window.App.templates.questionCard(
                question,
                gi
            );
        });

        html += `
            </div>

            <div class="p-4 border-t border-slate-100">
                <button
                    onclick="App.actions.addQuestion('${window.App.templates.escape(group.id)}')"
                    class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm"
                >＋ 質問追加</button>
            </div>
        </section>`;
    });

    editor.innerHTML = html;

    window.App.actions.initSortable();
    window.App.actions.renumber();
};

window.App.templates.questionCard = function(question, gi) {
    var e = window.App.templates.escape;

    var options = '';

    if (
        question.type === 'single' ||
        question.type === 'multiple'
    ) {
        options = `
        <div class="mt-4">
            <div class="text-xs font-semibold text-slate-500 mb-2">
                選択肢
            </div>

            <div id="options_${e(question.id)}" class="space-y-2">
                ${(question.options || []).map(function(option, index) {
                    return `
                    <div class="flex gap-2">
                        <input
                            value="${e(option)}"
                            onchange="App.actions.optionChange('${e(question.id)}',${index},this.value)"
                            class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm"
                        >

                        <button
                            onclick="App.actions.removeOption('${e(question.id)}',${index})"
                            class="px-3 text-rose-600"
                        >×</button>
                    </div>`;
                }).join('')}
            </div>

            <button
                onclick="App.actions.addOption('${e(question.id)}')"
                class="mt-2 text-sm text-indigo-600"
            >＋ 選択肢追加</button>

            <label class="flex items-center gap-2 mt-3 text-sm">
                <input
                    type="checkbox"
                    ${question.other_enabled ? 'checked' : ''}
                    onchange="App.actions.otherEnabled('${e(question.id)}',this.checked)"
                >
                「その他」を追加
            </label>
        </div>`;
    }

    var branchHtml = '';

    if (question.type === 'single') {
        var allQuestions = [];

        window.App.state.survey.groups.forEach(function(g) {
            g.questions.forEach(function(q) {
                allQuestions.push(q);
            });
        });

        branchHtml = `
        <div class="mt-4 border-t border-slate-100 pt-4">
            <div class="text-xs font-semibold text-slate-500 mb-2">
                回答による質問分岐
            </div>

            ${(question.options || []).map(function(option) {
                var branch =
                    (question.branching || []).find(function(b) {
                        return b.option === option;
                    });

                return `
                <div class="grid grid-cols-[1fr_220px] gap-2 items-center mb-2">
                    <div class="text-sm">${e(option)}</div>
                    <select
                        onchange="App.actions.branchChange('${e(question.id)}','${e(option)}',this.value)"
                        class="border border-slate-300 rounded-lg px-2 py-2 text-sm"
                    >
                        <option value="">通常どおり進む</option>
                        ${allQuestions
                            .filter(function(q) {
                                return q.id !== question.id;
                            })
                            .map(function(q) {
                                return `
                                <option
                                    value="${e(q.id)}"
                                    ${branch && branch.target_question_id === q.id ? 'selected' : ''}
                                >${e(q.text || '未入力')}</option>`;
                            }).join('')}
                    </select>
                </div>`;
            }).join('')}
        </div>`;
    }

    return `
    <article
        class="question-card border border-slate-200 rounded-xl p-4 bg-white"
        data-question-id="${e(question.id)}"
    >
        <div class="flex gap-3">
            <span class="question-handle cursor-grab text-slate-400 text-xl">⠿</span>

            <div class="flex-1">
                <div class="flex items-center gap-2 mb-3">
                    <span
                        class="question-number font-bold text-indigo-600"
                        data-number-for="${e(question.id)}"
                    ></span>

                    <input
                        value="${e(question.text || '')}"
                        onchange="App.actions.questionText('${e(question.id)}',this.value)"
                        placeholder="質問文を入力"
                        class="flex-1 border border-slate-300 rounded-lg px-3 py-2"
                    >

                    <button
                        onclick="App.actions.deleteQuestion('${e(question.id)}')"
                        class="text-rose-600 px-2"
                    >削除</button>
                </div>

                <div class="grid md:grid-cols-2 gap-3">
                    <select
                        onchange="App.actions.questionType('${e(question.id)}',this.value)"
                        class="border border-slate-300 rounded-lg px-3 py-2"
                    >
                        <option value="single" ${question.type === 'single' ? 'selected' : ''}>単一選択</option>
                        <option value="multiple" ${question.type === 'multiple' ? 'selected' : ''}>複数選択</option>
                        <option value="text" ${question.type === 'text' ? 'selected' : ''}>自由記述</option>
                    </select>

                    <label class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            ${question.required ? 'checked' : ''}
                            onchange="App.actions.required('${e(question.id)}',this.checked)"
                        >
                        必須回答
                    </label>
                </div>

                ${options}
                ${branchHtml}
            </div>
        </div>
    </article>`;
};

window.App.actions.addGroup = function() {
    window.App.state.survey.groups.push({
        id: window.App.actions.uid(),
        name: '新しいグループ',
        questions: []
    });

    window.App.render.questionEditor();
};

window.App.actions.deleteGroup = function(id) {
    if (!confirm('グループと、その中の質問を削除しますか？')) {
        return;
    }

    window.App.state.survey.groups =
        window.App.state.survey.groups.filter(function(g) {
            return g.id !== id;
        });

    if (window.App.state.survey.groups.length === 0) {
        window.App.state.survey.groups.push({
            id: window.App.actions.uid(),
            name: '基本情報',
            questions: []
        });
    }

    window.App.render.questionEditor();
};

window.App.actions.groupName = function(id, value) {
    var group = window.App.state.survey.groups.find(function(g) {
        return g.id === id;
    });

    if (group) group.name = value;
};

window.App.actions.addQuestion = function(groupId) {
    var group = window.App.state.survey.groups.find(function(g) {
        return g.id === groupId;
    });

    if (!group) return;

    group.questions.push({
        id: window.App.actions.uid(),
        text: '',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false,
        branching: []
    });

    window.App.render.questionEditor();
};

window.App.actions.findQuestion = function(id) {
    for (var g of window.App.state.survey.groups) {
        for (var q of g.questions) {
            if (q.id === id) {
                return q;
            }
        }
    }

    return null;
};

window.App.actions.deleteQuestion = function(id) {
    for (var g of window.App.state.survey.groups) {
        var index = g.questions.findIndex(function(q) {
            return q.id === id;
        });

        if (index >= 0) {
            g.questions.splice(index, 1);
            window.App.render.questionEditor();
            return;
        }
    }
};

window.App.actions.questionText = function(id, value) {
    var q = window.App.actions.findQuestion(id);
    if (q) q.text = value;
};

window.App.actions.questionType = function(id, type) {
    var q = window.App.actions.findQuestion(id);
    if (!q) return;

    q.type = type;

    if (type === 'text') {
        q.options = [];
        q.branching = [];
    } else if (!Array.isArray(q.options) || q.options.length === 0) {
        q.options = ['選択肢1', '選択肢2'];
    }

    window.App.render.questionEditor();
};

window.App.actions.required = function(id, value) {
    var q = window.App.actions.findQuestion(id);
    if (q) q.required = !!value;
};

window.App.actions.otherEnabled = function(id, value) {
    var q = window.App.actions.findQuestion(id);
    if (q) q.other_enabled = !!value;
};

window.App.actions.addOption = function(id) {
    var q = window.App.actions.findQuestion(id);

    if (!q) return;

    if (!Array.isArray(q.options)) {
        q.options = [];
    }

    q.options.push('選択肢' + (q.options.length + 1));

    window.App.render.questionEditor();
};

window.App.actions.removeOption = function(id, index) {
    var q = window.App.actions.findQuestion(id);

    if (!q) return;

    q.options.splice(index, 1);

    window.App.render.questionEditor();
};

window.App.actions.optionChange = function(id, index, value) {
    var q = window.App.actions.findQuestion(id);

    if (!q) return;

    q.options[index] = value;
};

window.App.actions.branchChange = function(
    questionId,
    option,
    targetId
) {
    var q = window.App.actions.findQuestion(questionId);

    if (!q) return;

    if (!Array.isArray(q.branching)) {
        q.branching = [];
    }

    var existing = q.branching.find(function(b) {
        return b.option === option;
    });

    if (targetId === '') {
        q.branching = q.branching.filter(function(b) {
            return b.option !== option;
        });
        return;
    }

    if (existing) {
        existing.target_question_id = targetId;
    } else {
        q.branching.push({
            option: option,
            target_question_id: targetId
        });
    }
};

window.App.actions.numberingMode = function(value) {
    window.App.state.survey.numbering_mode = value;
    window.App.actions.renumber();
};

window.App.actions.renumber = function() {
    var survey = window.App.state.survey;

    var globalNo = 0;

    survey.groups.forEach(function(group, gi) {
        group.questions.forEach(function(q, qi) {
            globalNo++;

            var label =
                survey.numbering_mode === 'group'
                    ? 'Q' + (gi + 1) + '-' + (qi + 1)
                    : 'Q' + globalNo;

            var el = document.querySelector(
                '[data-number-for="' +
                CSS.escape(q.id) +
                '"]'
            );

            if (el) {
                el.textContent = label;
            }
        });
    });
};

window.App.actions.initSortable = function() {
    var editor = document.getElementById('question_editor');

    if (!editor || typeof Sortable === 'undefined') {
        return;
    }

    new Sortable(editor, {
        animation: 180,
        handle: '.group-handle',
        ghostClass: 'opacity-40',
        onEnd: function(evt) {
            var groups = window.App.state.survey.groups;

            var moved = groups.splice(evt.oldIndex, 1)[0];

            groups.splice(evt.newIndex, 0, moved);

            window.App.render.questionEditor();
        }
    });

    document.querySelectorAll('.questions-container')
        .forEach(function(container) {

        new Sortable(container, {
            group: 'survey-questions',
            animation: 180,
            handle: '.question-handle',
            ghostClass: 'opacity-40',

            onEnd: function(evt) {
                var fromId =
                    evt.from.dataset.groupId;

                var toId =
                    evt.to.dataset.groupId;

                var fromGroup =
                    window.App.state.survey.groups.find(
                        function(g) {
                            return g.id === fromId;
                        }
                    );

                var toGroup =
                    window.App.state.survey.groups.find(
                        function(g) {
                            return g.id === toId;
                        }
                    );

                if (!fromGroup || !toGroup) return;

                var moved =
                    fromGroup.questions.splice(
                        evt.oldIndex,
                        1
                    )[0];

                toGroup.questions.splice(
                    evt.newIndex,
                    0,
                    moved
                );

                window.App.render.questionEditor();
            }
        });
    });
};

window.App.actions.saveSurvey = async function() {
    var survey = window.App.state.survey;

    survey.title =
        document.getElementById('survey_title').value.trim();

    survey.start_at =
        document.getElementById('survey_start_at').value;

    survey.end_at =
        document.getElementById('survey_end_at').value;

    survey.numbering_mode =
        document.getElementById('survey_numbering_mode').value;

    if (!survey.title) {
        alert('タイトルを入力してください。');
        return;
    }

    try {
        await window.App.api.saveSurvey(survey);

        alert('保存しました。');

        window.App.state.page = 'list';
        window.App.render.page();
    } catch (e) {
        alert(e.message);
    }
};

window.App.actions.cancelEdit = function() {
    if (
        !confirm(
            '変更内容を破棄して一覧へ戻りますか？'
        )
    ) {
        return;
    }

    window.App.state.page = 'list';
    window.App.render.page();
};

window.App.actions.preview = function() {
    var survey =
        JSON.parse(
            JSON.stringify(window.App.state.survey)
        );

    var html = '';

    survey.groups.forEach(function(group, gi) {
        html += `
        <div class="mb-6">
            <h3 class="font-bold text-lg mb-4">
                ${window.App.templates.escape(group.name)}
            </h3>`;

        group.questions.forEach(function(q, qi) {
            var number =
                survey.numbering_mode === 'group'
                    ? 'Q' + (gi + 1) + '-' + (qi + 1)
                    : 'Q' + (
                        survey.groups
                            .slice(0, gi)
                            .reduce(
                                function(total, g) {
                                    return total + g.questions.length;
                                },
                                0
                            ) + qi + 1
                    );

            html += `
            <div class="mb-6">
                <div class="font-semibold mb-2">
                    ${number}. ${window.App.templates.escape(q.text)}
                    ${q.required ? '<span class="text-red-500">必須</span>' : ''}
                </div>`;

            if (q.type === 'text') {
                html +=
                    '<textarea disabled rows="4" class="w-full border rounded-lg p-3"></textarea>';
            } else {
                (q.options || []).forEach(function(option) {
                    html += `
                    <label class="block py-2">
                        <input
                            disabled
                            type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                        >
                        ${window.App.templates.escape(option)}
                    </label>`;
                });

                if (q.other_enabled) {
                    html += `
                    <label class="block py-2">
                        <input
                            disabled
                            type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                        >
                        その他
                    </label>`;
                }
            }

            html += '</div>';
        });

        html += '</div>';
    });

    document.getElementById('preview_modal').innerHTML = `
    <div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
        <div class="${
            window.App.state.previewMode === 'mobile'
                ? 'w-[390px]'
                : 'w-full max-w-3xl'
        } max-h-[90vh] overflow-auto bg-white rounded-2xl shadow-2xl">

            <div class="sticky top-0 bg-white border-b p-4 flex justify-between">
                <div class="font-bold">プレビュー</div>

                <div class="flex gap-2">
                    <button
                        onclick="App.actions.previewMode('pc')"
                        class="px-2 py-1 text-xs border rounded"
                    >PC</button>

                    <button
                        onclick="App.actions.previewMode('mobile')"
                        class="px-2 py-1 text-xs border rounded"
                    >スマートフォン</button>

                    <button
                        onclick="App.actions.closePreview()"
                        class="px-2 py-1 text-xs"
                    >閉じる</button>
                </div>
            </div>

            <div id="preview_content" class="p-6">
                <h2 class="text-2xl font-bold mb-8">
                    ${window.App.templates.escape(survey.title)}
                </h2>

                ${html}

                <button
                    onclick="alert('これはプレビューです。実際には送信されません。')"
                    class="w-full bg-indigo-600 text-white rounded-lg py-3"
                >回答を送信する</button>
            </div>
        </div>
    </div>`;
};

window.App.actions.previewMode = function(mode) {
    window.App.state.previewMode = mode;
    window.App.actions.preview();
};

window.App.actions.closePreview = function() {
    var modal = document.getElementById('preview_modal');

    if (modal) modal.innerHTML = '';
};

window.App.actions.send = function(id) {
    window.App.state.selectedSurveyId = id;
    window.App.state.page = 'send';
    window.App.render.page();
};

window.App.render.send = function() {
    var data = window.App.state.data;
    var survey = data.surveys.find(function(s) {
        return s.id === window.App.state.selectedSurveyId;
    });

    if (!survey) {
        window.App.state.page = 'list';
        window.App.render.page();
        return;
    }

    var customers = data.customers || [];

    var filter =
        window.App.state.customerFilter.toLowerCase();

    if (filter) {
        customers = customers.filter(function(c) {
            return (
                String(c.name || '').toLowerCase().includes(filter) ||
                String(c.company || '').toLowerCase().includes(filter) ||
                String(c.email || '').toLowerCase().includes(filter)
            );
        });
    }

    document.getElementById('main').innerHTML = `
    <div class="mb-5">
        <button
            onclick="App.actions.home()"
            class="text-sm text-slate-500"
        >← アンケート一覧</button>

        <h1 class="text-2xl font-bold mt-2">
            顧客選択・メール送信
        </h1>

        <p class="text-slate-500">
            ${window.App.templates.escape(survey.title)}
        </p>
    </div>

    <div class="grid lg:grid-cols-[1fr_380px] gap-5">

        <section class="bg-white rounded-2xl border border-slate-200 overflow-hidden">

            <div class="p-4 border-b flex gap-3">
                <input
                    id="customer_filter"
                    value="${window.App.templates.escape(window.App.state.customerFilter)}"
                    oninput="App.actions.customerSearch(this.value)"
                    placeholder="顧客名・会社名・メールアドレス"
                    class="flex-1 border border-slate-300 rounded-lg px-3 py-2"
                >

                <label class="flex items-center gap-2 text-sm">
                    <input
                        id="select_all"
                        type="checkbox"
                        onchange="App.actions.selectAll(this.checked)"
                    >
                    全選択
                </label>
            </div>

            <div id="customer_table" class="overflow-x-auto">
                <table class="w-full min-w-[1000px] text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="p-3">選択</th>
                            <th class="p-3">会社 / 氏名</th>
                            <th class="p-3">メール</th>
                            <th class="p-3">送信状況</th>
                            <th class="p-3">回答</th>
                            <th class="p-3">kintone</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${customers.map(function(c) {
                            var disabled = c.source === 'web';

                            return `
                            <tr class="border-b border-slate-100">
                                <td class="p-3">
                                    <input
                                        type="checkbox"
                                        class="customer-check"
                                        value="${window.App.templates.escape(c.id)}"
                                        ${disabled ? 'disabled' : ''}
                                    >
                                </td>

                                <td class="p-3">
                                    <div class="font-bold">
                                        ${window.App.templates.escape(c.company || '')}
                                    </div>
                                    <div>
                                        ${window.App.templates.escape(c.name || '')}
                                    </div>
                                </td>

                                <td class="p-3">
                                    ${window.App.templates.escape(c.email || '')}
                                </td>

                                <td class="p-3">
                                    ${c.sent_at
                                        ? `${window.App.templates.escape(c.sent_at)}<br>送信 ${c.send_count || 0}回`
                                        : '未送信'}
                                </td>

                                <td class="p-3">
                                    ${c.answer_status === 'answered'
                                        ? '<span class="text-emerald-600 font-semibold">回答済み</span>'
                                        : '<span class="text-amber-600">未回答</span>'}
                                </td>

                                <td class="p-3">
                                    ${c.kintone_status === 'registered'
                                        ? '<span class="text-emerald-600">✓ 登録済み</span>'
                                        : `
                                        <button
                                            onclick="App.actions.registerKintone('${window.App.templates.escape(c.id)}')"
                                            class="text-indigo-600 text-xs"
                                        >kintone登録</button>`}
                                </td>
                            </tr>`;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 p-5 h-fit">
            <h2 class="font-bold mb-4">メールテンプレート</h2>

            <select
                id="template_type"
                onchange="App.actions.templateType(this.value)"
                class="w-full border rounded-lg px-3 py-2 mb-3"
            >
                <option value="initial">初回送信用</option>
                <option value="reminder">再送・リマインド用</option>
            </select>

            <input
                id="mail_subject"
                placeholder="件名"
                class="w-full border rounded-lg px-3 py-2 mb-3"
                value="アンケートご協力のお願い"
            >

            <textarea
                id="mail_body"
                rows="12"
                class="w-full border rounded-lg px-3 py-3"
            >{顧客名} 様

アンケートへのご協力をお願いいたします。

以下のURLよりご回答ください。
{アンケートURL}

よろしくお願いいたします。</textarea>

            <button
                onclick="App.actions.sendMail()"
                class="mt-4 w-full bg-indigo-600 hover:bg-indigo-700 text-white py-3 rounded-xl font-semibold"
            >
                選択した顧客へ一括送信
            </button>

            <p class="text-xs text-slate-400 mt-3">
                {顧客名} と {アンケートURL} は送信時に自動置換されます。
            </p>
        </section>
    </div>`;
};

window.App.actions.customerSearch = function(value) {
    window.App.state.customerFilter = value;
    window.App.render.send();
};

window.App.actions.selectAll = function(checked) {
    document.querySelectorAll('.customer-check:not(:disabled)')
        .forEach(function(el) {
            el.checked = checked;
        });
};

window.App.actions.templateType = function(value) {
    if (value === 'reminder') {
        document.getElementById('mail_subject').value =
            '【再送】アンケートご回答のお願い';

        document.getElementById('mail_body').value =
            '{顧客名} 様\n\n' +
            '先日ご案内しましたアンケートが未回答となっております。\n\n' +
            'お手数ですが以下よりご回答ください。\n' +
            '{アンケートURL}\n';
    } else {
        document.getElementById('mail_subject').value =
            'アンケートご協力のお願い';

        document.getElementById('mail_body').value =
            '{顧客名} 様\n\n' +
            'アンケートへのご協力をお願いいたします。\n\n' +
            '以下のURLよりご回答ください。\n' +
            '{アンケートURL}\n\n' +
            'よろしくお願いいたします。';
    }
};

window.App.actions.sendMail = async function() {
    var ids = Array.from(
        document.querySelectorAll('.customer-check:checked')
    ).map(function(el) {
        return el.value;
    });

    if (!ids.length) {
        alert('送信先を選択してください。');
        return;
    }

    var data = window.App.state.data;

    var alreadySent = ids.some(function(id) {
        var customer = data.customers.find(function(c) {
            return c.id === id;
        });

        return customer && Number(customer.send_count || 0) > 0;
    });

    if (
        alreadySent &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )
    ) {
        return;
    }

    var result = await window.App.api.request(
        'send_mail',
        {
            survey_id: window.App.state.selectedSurveyId,
            recipient_ids: ids,
            mail_subject:
                document.getElementById('mail_subject').value,
            mail_body:
                document.getElementById('mail_body').value,
            template_type:
                document.getElementById('template_type').value
        }
    );

    if (!result.ok) {
        alert(result.message || '送信に失敗しました。');
    } else {
        alert(result.message);
    }

    window.App.state.data = result.data;
    window.App.render.send();
};

window.App.actions.registerKintone = async function(customerId) {
    if (!confirm('kintoneへ顧客を登録しますか？')) return;

    var result = await window.App.api.request(
        'register_kintone',
        {
            customer_id: customerId
        }
    );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    window.App.state.data = result.data;
    window.App.render.send();
};

window.App.actions.analysis = function(id) {
    window.App.state.selectedSurveyId = id;
    window.App.state.page = 'analysis';
    window.App.render.page();
};

window.App.render.analysis = function() {
    var data = window.App.state.data;

    var survey = data.surveys.find(function(s) {
        return s.id === window.App.state.selectedSurveyId;
    });

    if (!survey) {
        window.App.state.page = 'list';
        window.App.render.page();
        return;
    }

    var responses = data.responses.filter(function(r) {
        return r.survey_id === survey.id;
    });

    var questions = window.App.actions.questionsWithNumber(
        survey
    );

    if (!Object.keys(window.App.state.selectedQuestions).length) {
        questions.forEach(function(q) {
            window.App.state.selectedQuestions[q.id] = true;
        });
    }

    var sentCustomers =
        data.customers.filter(function(c) {
            return Number(c.send_count || 0) > 0;
        });

    var unanswered =
        sentCustomers.filter(function(c) {
            return c.answer_status !== 'answered';
        }).length;

    var registeredCustomerIds =
        new Set(data.customers.map(function(c) {
            return c.id;
        }));

    var unregisteredResponses =
        responses.filter(function(r) {
            return !registeredCustomerIds.has(r.customer_id);
        }).length;

    var rate = sentCustomers.length
        ? (
            (
                sentCustomers.length -
                unanswered
            ) / sentCustomers.length * 100
        ).toFixed(1)
        : '0.0';

    document.getElementById('main').innerHTML = `
    <div class="flex justify-between mb-5">
        <div>
            <button
                onclick="App.actions.home()"
                class="text-sm text-slate-500"
            >← 一覧</button>

            <h1 class="text-2xl font-bold mt-2">
                集計・分析
            </h1>

            <p class="text-slate-500">
                ${window.App.templates.escape(survey.title)}
            </p>
        </div>

        <div class="flex gap-2">
            <button
                onclick="App.actions.exportCsv('${window.App.templates.escape(survey.id)}')"
                class="px-4 py-2 border rounded-lg bg-white"
            >CSV出力</button>

            <button
                onclick="window.print()"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg"
            >PDF / 印刷</button>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
        ${[
            ['送信対象者数', sentCustomers.length + ' 人'],
            ['回答数', responses.length + ' 件'],
            ['未登録顧客からの回答', unregisteredResponses + ' 件'],
            ['未回答数', unanswered + ' 人'],
            ['回答率', rate + ' %']
        ].map(function(card) {
            return `
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <div class="text-xs text-slate-500">${card[0]}</div>
                <div class="text-xl font-bold mt-2">${card[1]}</div>
            </div>`;
        }).join('')}
    </div>

    <section class="bg-white border border-slate-200 rounded-2xl p-5 mb-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-bold">設問絞り込み</h2>

            <div class="flex gap-2">
                <button
                    onclick="App.actions.selectAllQuestions(true)"
                    class="text-xs text-indigo-600"
                >全選択</button>

                <button
                    onclick="App.actions.selectAllQuestions(false)"
                    class="text-xs text-indigo-600"
                >全解除</button>
            </div>
        </div>

        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-2">
            ${questions.map(function(q) {
                return `
                <label class="flex gap-2 p-2 rounded-lg hover:bg-slate-50">
                    <input
                        type="checkbox"
                        ${window.App.state.selectedQuestions[q.id] ? 'checked' : ''}
                        onchange="App.actions.toggleQuestion('${window.App.templates.escape(q.id)}',this.checked)"
                    >
                    <span class="text-sm">
                        ${window.App.templates.escape(q.number)}
                        ${window.App.templates.escape(q.text)}
                    </span>
                </label>`;
            }).join('')}
        </div>
    </section>

    <section class="space-y-5">
        ${questions
            .filter(function(q) {
                return window.App.state.selectedQuestions[q.id];
            })
            .map(function(q) {
                return window.App.templates.analysisQuestion(
                    q,
                    responses
                );
            }).join('')}
    </section>

    <section class="bg-white border border-slate-200 rounded-2xl overflow-hidden mt-5">
        <div class="p-5 border-b">
            <h2 class="font-bold">個別回答一覧</h2>
            <input
                id="response_filter"
                value="${window.App.templates.escape(window.App.state.responseFilter)}"
                oninput="App.actions.responseSearch(this.value)"
                placeholder="会社名・氏名で検索"
                class="mt-3 border rounded-lg px-3 py-2 w-full max-w-md"
            >
        </div>

        <div id="response_table" class="overflow-x-auto">
            ${window.App.templates.responseTable(responses)}
        </div>
    </section>

    <div id="response_modal"></div>`;
};

window.App.actions.questionsWithNumber = function(survey) {
    var result = [];
    var n = 0;

    survey.groups.forEach(function(group, gi) {
        group.questions.forEach(function(q, qi) {
            n++;

            result.push({
                id: q.id,
                text: q.text || '',
                type: q.type || 'single',
                options: q.options || [],
                other_enabled: !!q.other_enabled,
                number:
                    survey.numbering_mode === 'group'
                        ? 'Q' + (gi + 1) + '-' + (qi + 1)
                        : 'Q' + n
            });
        });
    });

    return result;
};

window.App.templates.analysisQuestion = function(q, responses) {
    var e = window.App.templates.escape;

    if (q.type === 'text') {
        var texts = responses.map(function(r) {
            return {
                value: r.answers ? r.answers[q.id] : '',
                name: r.name,
                company: r.company,
                date: r.answered_at
            };
        }).filter(function(x) {
            return x.value !== '' &&
                x.value !== null &&
                x.value !== undefined;
        });

        return `
        <section class="bg-white border border-slate-200 rounded-2xl p-5">
            <div class="flex items-center gap-2 mb-4">
                <span class="font-bold">${e(q.number)}. ${e(q.text)}</span>
                <span class="text-xs bg-slate-100 px-2 py-1 rounded">自由記述</span>
            </div>

            <div class="space-y-3 max-h-96 overflow-auto">
                ${texts.length
                    ? texts.map(function(x) {
                        return `
                        <div class="border-l-4 border-indigo-400 pl-4 py-2">
                            <div class="text-xs text-slate-400">
                                ${e(x.company)} / ${e(x.name)}
                                ${e(x.date)}
                            </div>
                            <div class="mt-1 whitespace-pre-wrap">
                                ${e(String(x.value))}
                            </div>
                        </div>`;
                    }).join('')
                    : '<div class="text-slate-400">回答データはありません。</div>'}
            </div>
        </section>`;
    }

    var counts = {};

    q.options.forEach(function(option) {
        counts[option] = 0;
    });

    var otherCount = 0;

    responses.forEach(function(r) {
        var value =
            r.answers
                ? r.answers[q.id]
                : '';

        if (Array.isArray(value)) {
            value.forEach(function(v) {
                if (counts[v] !== undefined) {
                    counts[v]++;
                }
            });
        } else if (counts[value] !== undefined) {
            counts[value]++;
        } else if (
            value &&
            q.other_enabled
        ) {
            otherCount++;
        }
    });

    var total = responses.length || 1;

    return `
    <section class="bg-white border border-slate-200 rounded-2xl p-5">
        <div class="font-bold mb-4">
            ${e(q.number)}. ${e(q.text)}
        </div>

        <div class="space-y-4">
            ${Object.keys(counts).map(function(option) {
                var count = counts[option];
                var percent = count / total * 100;

                return `
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span>${e(option)}</span>
                        <span>${count}件 / ${percent.toFixed(1)}%</span>
                    </div>

                    <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                        <div
                            class="h-full bg-indigo-500"
                            style="width:${Math.min(percent,100)}%"
                        ></div>
                    </div>
                </div>`;
            }).join('')}

            ${q.other_enabled
                ? `
                <button
                    onclick="App.actions.showOther('${e(q.id)}')"
                    class="text-sm text-indigo-600"
                >その他 ${otherCount}件を表示</button>`
                : ''}
        </div>
    </section>`;
};

window.App.templates.responseTable = function(responses) {
    var filter =
        window.App.state.responseFilter.toLowerCase();

    var list = responses.filter(function(r) {
        return (
            !filter ||
            String(r.company || '').toLowerCase().includes(filter) ||
            String(r.name || '').toLowerCase().includes(filter)
        );
    });

    return `
    <table class="w-full min-w-[900px] text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="p-3 text-left">会社名</th>
                <th class="p-3 text-left">氏名</th>
                <th class="p-3 text-left">回答日時</th>
                <th class="p-3 text-left">操作</th>
            </tr>
        </thead>
        <tbody>
            ${list.length
                ? list.map(function(r) {
                    return `
                    <tr class="border-b border-slate-100">
                        <td class="p-3">${window.App.templates.escape(r.company || '')}</td>
                        <td class="p-3">${window.App.templates.escape(r.name || '')}</td>
                        <td class="p-3">${window.App.templates.escape(r.answered_at || '')}</td>
                        <td class="p-3">
                            <button
                                onclick="App.actions.showResponse('${window.App.templates.escape(r.id)}')"
                                class="text-indigo-600"
                            >全回答を表示</button>
                        </td>
                    </tr>`;
                }).join('')
                : `
                <tr>
                    <td colspan="4" class="p-10 text-center text-slate-400">
                        現在、回答データはありません
                    </td>
                </tr>`}
        </tbody>
    </table>`;
};

window.App.actions.toggleQuestion = function(id, checked) {
    window.App.state.selectedQuestions[id] = checked;
    window.App.render.analysis();
};

window.App.actions.selectAllQuestions = function(value) {
    var survey =
        window.App.state.data.surveys.find(function(s) {
            return s.id === window.App.state.selectedSurveyId;
        });

    if (!survey) return;

    window.App.actions.questionsWithNumber(survey)
        .forEach(function(q) {
            window.App.state.selectedQuestions[q.id] = value;
        });

    window.App.render.analysis();
};

window.App.actions.responseSearch = function(value) {
    window.App.state.responseFilter = value;
    window.App.render.analysis();
};

window.App.actions.showResponse = function(responseId) {
    var response =
        window.App.state.data.responses.find(function(r) {
            return r.id === responseId;
        });

    if (!response) return;

    var survey =
        window.App.state.data.surveys.find(function(s) {
            return s.id === response.survey_id;
        });

    if (!survey) return;

    var questions =
        window.App.actions.questionsWithNumber(survey);

    var html = questions.map(function(q) {
        var value =
            response.answers
                ? response.answers[q.id]
                : '';

        if (Array.isArray(value)) {
            value = value.join('、');
        }

        return `
        <div class="border-b py-4">
            <div class="text-sm font-semibold">
                ${window.App.templates.escape(q.number)}
                ${window.App.templates.escape(q.text)}
            </div>
            <div class="mt-2 whitespace-pre-wrap text-slate-600">
                ${window.App.templates.escape(String(value || '未回答'))}
            </div>
        </div>`;
    }).join('');

    document.getElementById('response_modal').innerHTML = `
    <div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-auto">
            <div class="sticky top-0 bg-white border-b p-5 flex justify-between">
                <div>
                    <div class="font-bold">
                        ${window.App.templates.escape(response.company || '')}
                        /
                        ${window.App.templates.escape(response.name || '')}
                    </div>
                    <div class="text-xs text-slate-400">
                        ${window.App.templates.escape(response.answered_at || '')}
                    </div>
                </div>

                <button
                    onclick="App.actions.closeResponse()"
                    class="text-slate-500"
                >閉じる</button>
            </div>

            <div class="p-5">${html}</div>
        </div>
    </div>`;
};

window.App.actions.closeResponse = function() {
    var modal = document.getElementById('response_modal');

    if (modal) modal.innerHTML = '';
};

window.App.actions.showOther = function(qid) {
    var responses =
        window.App.state.data.responses.filter(function(r) {
            return r.survey_id ===
                window.App.state.selectedSurveyId;
        });

    var items = [];

    responses.forEach(function(r) {
        var value = r.answers ? r.answers[qid] : '';

        if (
            typeof value === 'string' &&
            value &&
            value.indexOf('その他') === 0
        ) {
            items.push({
                name: r.name,
                company: r.company,
                value: value
            });
        }
    });

    alert(
        items.length
            ? items.map(function(x) {
                return (
                    x.company +
                    ' / ' +
                    x.name +
                    '\n' +
                    x.value
                );
            }).join('\n\n')
            : 'その他の回答はありません。'
    );
};

window.App.actions.exportCsv = function(id) {
    var url =
        location.pathname +
        '?action=csv&survey_id=' +
        encodeURIComponent(id);

    location.href = url;
};

window.App.actions.settings = function() {
    window.App.state.page = 'settings';
    window.App.render.page();
};

window.App.render.settings = function() {
    var s = window.App.state.data.settings || {};

    document.getElementById('main').innerHTML = `
    <div class="max-w-5xl">
        <div class="mb-5">
            <button
                onclick="App.actions.home()"
                class="text-sm text-slate-500"
            >← 一覧</button>

            <h1 class="text-2xl font-bold mt-2">
                kintone連携設定
            </h1>
        </div>

        <form
            id="settings_form"
            onsubmit="event.preventDefault(); App.actions.saveSettings()"
            class="bg-white border border-slate-200 rounded-2xl p-6 space-y-5"
        >
            <div class="grid md:grid-cols-2 gap-4">

                <div>
                    <label class="block text-sm font-semibold mb-2">
                        サブドメイン
                    </label>
                    <input
                        id="setting_subdomain"
                        value="${window.App.templates.escape(s.subdomain || '')}"
                        placeholder="xxxx または xxxx.cybozu.com"
                        class="w-full border rounded-lg px-3 py-2"
                    >
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2">
                        顧客管理アプリID
                    </label>
                    <input
                        id="setting_app_id"
                        value="${window.App.templates.escape(s.app_id || '')}"
                        class="w-full border rounded-lg px-3 py-2"
                    >
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2">
                        ログイン名
                    </label>
                    <input
                        id="setting_login_name"
                        value="${window.App.templates.escape(s.login_name || '')}"
                        class="w-full border rounded-lg px-3 py-2"
                    >
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2">
                        パスワード
                    </label>
                    <input
                        id="setting_password"
                        type="password"
                        value="${window.App.templates.escape(s.password || '')}"
                        class="w-full border rounded-lg px-3 py-2"
                    >
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2">
                        Proxyサーバ
                    </label>
                    <input
                        id="setting_proxy"
                        value="${window.App.templates.escape(s.proxy || '')}"
                        placeholder="proxy.example.local:8080"
                        class="w-full border rounded-lg px-3 py-2"
                    >
                </div>

                <div class="flex items-center">
                    <label class="flex gap-2">
                        <input
                            id="setting_ssl_verify"
                            type="checkbox"
                            ${s.ssl_verify ? 'checked' : ''}
                        >
                        SSL証明書を検証する
                    </label>
                </div>
            </div>

            <div class="border-t pt-5">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="font-bold">
                            kintoneフィールドマッピング
                        </h2>

                        <p class="text-xs text-slate-500">
                            「項目一覧を取得」を押すとkintoneから日本語フィールド名を取得します。
                        </p>
                    </div>

                    <button
                        type="button"
                        onclick="App.actions.fetchKintoneFields()"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg"
                    >
                        項目一覧を再取得
                    </button>
                </div>

                <div
                    id="field_message"
                    class="mt-3 text-sm"
                ></div>

                <div
                    id="kintone_mapping"
                    class="mt-4 grid md:grid-cols-2 gap-4"
                >
                    ${window.App.render.mappingSelects()}
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    onclick="App.actions.home()"
                    class="px-5 py-2 border rounded-lg"
                >キャンセル</button>

                <button
                    type="submit"
                    class="px-5 py-2 bg-indigo-600 text-white rounded-lg"
                >設定を保存</button>
            </div>

            <input
                type="hidden"
                id="settings_json"
            >
        </form>
    </div>`;
};

window.App.render.mappingSelects = function() {
    var settings = window.App.state.data.settings || {};
    var fields = window.App.state.kintoneFields || {};

    function select(
        label,
        settingKey,
        multiple
    ) {
        var current = settings[settingKey];

        var selectedValues =
            Array.isArray(current)
                ? current
                : [current || ''];

        var options =
            '<option value="">-- 選択してください --</option>';

        Object.keys(fields).forEach(function(code) {
            var field = fields[code];

            if (
                !field ||
                !field.label
            ) return;

            var selected =
                selectedValues.includes(code);

            options += `
            <option
                value="${window.App.templates.escape(code)}"
                ${selected ? 'selected' : ''}
            >
                ${window.App.templates.escape(field.label)}
                (${window.App.templates.escape(code)})
            </option>`;
        });

        return `
        <div>
            <label class="block text-sm font-semibold mb-2">
                ${label}
            </label>

            <select
                ${multiple ? 'multiple size="5"' : ''}
                data-mapping="${settingKey}"
                class="w-full border rounded-lg px-3 py-2"
            >
                ${options}
            </select>

            ${multiple
                ? '<div class="text-xs text-slate-400 mt-1">Ctrl / Commandで複数選択</div>'
                : ''}
        </div>`;
    }

    return (
        select('会社名 (Company)', 'field_company', false) +
        select('氏名 (Name)', 'field_name', false) +
        select('メールアドレス (Email)', 'field_email', false) +
        select('部署名 (Department)', 'field_department', false) +
        select('電話番号 (Phone)', 'field_phone', false) +
        select('住所 (Address)', 'field_address', true)
    );
};

/* ================================================================
 * kintone項目一覧取得
 * ================================================================ */

window.App.actions.fetchKintoneFields = async function() {
    var message =
        document.getElementById('field_message');

    message.className =
        'mt-3 text-sm text-indigo-600';

    message.textContent =
        'kintoneから項目一覧を取得しています……';

    var appId =
        document.getElementById('setting_app_id').value.trim();

    if (!appId) {
        message.className =
            'mt-3 text-sm text-rose-600';

        message.textContent =
            '顧客管理アプリIDを入力してください。';

        return;
    }

    /*
     * 現在入力されている接続情報を
     * settingsへ一時反映してからAPIへ送る。
     */
    var current =
        window.App.state.data.settings;

    current.subdomain =
        document.getElementById('setting_subdomain').value.trim();

    current.app_id = appId;

    current.login_name =
        document.getElementById('setting_login_name').value;

    current.password =
        document.getElementById('setting_password').value;

    current.proxy =
        document.getElementById('setting_proxy').value.trim();

    current.ssl_verify =
        document.getElementById('setting_ssl_verify').checked;

    var result;

    try {
        result = await window.App.api.request(
            'kintone_fields',
            {
                app_id: appId
            }
        );
    } catch (error) {
        message.className =
            'mt-3 text-sm text-rose-600';

        message.textContent =
            '通信エラー: ' + error.message;

        return;
    }

    if (!result.ok) {
        message.className =
            'mt-3 text-sm text-rose-600';

        message.textContent =
            result.message ||
            'kintone項目一覧取得に失敗しました。';

        console.error(
            'kintone_fields error',
            result
        );

        return;
    }

    window.App.state.kintoneFields =
        result.fields || {};

    message.className =
        'mt-3 text-sm text-emerald-600';

    message.textContent =
        Object.keys(window.App.state.kintoneFields).length +
        '項目を取得しました。';

    document.getElementById('kintone_mapping').innerHTML =
        window.App.render.mappingSelects();
};

window.App.actions.saveSettings = async function() {
    var settings =
        window.App.state.data.settings;

    settings.subdomain =
        document.getElementById('setting_subdomain').value.trim();

    settings.app_id =
        document.getElementById('setting_app_id').value.trim();

    settings.login_name =
        document.getElementById('setting_login_name').value;

    settings.password =
        document.getElementById('setting_password').value;

    settings.proxy =
        document.getElementById('setting_proxy').value.trim();

    settings.ssl_verify =
        document.getElementById('setting_ssl_verify').checked;

    document.querySelectorAll('[data-mapping]')
        .forEach(function(select) {

        var key = select.dataset.mapping;

        if (select.multiple) {
            settings[key] =
                Array.from(select.selectedOptions)
                    .map(function(o) {
                        return o.value;
                    })
                    .filter(Boolean);
        } else {
            settings[key] = select.value;
        }
    });

    var result = await window.App.api.request(
        'save_settings',
        {
            settings_json: settings
        }
    );

    if (!result.ok) {
        alert(result.message);
        return;
    }

    window.App.state.data.settings =
        result.settings;

    alert('設定を保存しました。');

    window.App.actions.home();
};

window.App.actions.logout = function() {
    alert(
        'この簡易版では管理画面のログアウト処理はサーバー認証方式に合わせて実装してください。'
    );
};

window.App.actions.responseSearch = function(value) {
    window.App.state.responseFilter = value;

    var container =
        document.getElementById('response_table');

    if (!container) return;

    var responses =
        window.App.state.data.responses.filter(function(r) {
            return r.survey_id ===
                window.App.state.selectedSurveyId;
        });

    container.innerHTML =
        window.App.templates.responseTable(responses);
};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        function() {
            window.App.init();
        },
        { once: true }
    );
} else {
    window.App.init();
}
</script>

</body>
</html>
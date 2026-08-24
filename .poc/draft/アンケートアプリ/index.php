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

session_name(SURVEY_ADMIN_SESSION);
session_start();

if (!is_dir(SURVEY_STORAGE_DIRECTORY)) {
    @mkdir(SURVEY_STORAGE_DIRECTORY, 0775, true);
}

/* ================================================================
 * PHP 共通
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

    if (!is_array($data['surveys'])) {
        $data['surveys'] = [];
    }

    if (!is_array($data['responses'])) {
        $data['responses'] = [];
    }

    if (!is_array($data['customers'])) {
        $data['customers'] = [];
    }

    if (!is_array($data['mail_logs'])) {
        $data['mail_logs'] = [];
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
        @unlink(SURVEY_STORAGE_FILE . '.bak');
        @copy(SURVEY_STORAGE_FILE, SURVEY_STORAGE_FILE . '.bak');
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

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

function survey_clean_text(mixed $value, int $max = 10000): string
{
    $value = (string)$value;
    $value = trim($value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    return substr($value, 0, $max);
}

function survey_h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/* ================================================================
 * kintone
 * ================================================================ */

function survey_normalize_kintone_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('#^\s*https?://#i', '', $value);
    $value = preg_replace('#/.*$#', '', (string)$value);
    $value = trim((string)$value, ". \t\n\r\0\x0B");

    if ($value === '') {
        return '';
    }

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

    $status = 0;

    foreach ($headers as $header) {
        if (preg_match(
            '/^HTTP\/[\d.]+\s+(\d+)/i',
            (string)$header,
            $m
        )) {
            $status = (int)$m[1];
        }
    }

    return $status;
}

function survey_kintone_request(
    string $method,
    string $path,
    array $settings,
    ?array $body = null
): array {
    $baseUrl = survey_normalize_kintone_host(
        (string)($settings['subdomain'] ?? '')
    );

    if ($baseUrl === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' => 'kintoneのサブドメイン/FQDNが設定されていません。',
            'endpoint' => ''
        ];
    }

    $method = strtoupper($method);
    $path = '/' . ltrim($path, '/');
    $url = $baseUrl . $path;

    /*
     * ログイン名だけ前後空白を除去。
     *
     * パスワードはtrimしない。
     * パスワードに意図した空白が含まれている可能性があるため。
     */
    $login = trim((string)($settings['login_name'] ?? ''));
    $password = (string)($settings['password'] ?? '');

    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'CONFIG',
            'message' => 'kintoneのログイン名とパスワードを入力してください。',
            'endpoint' => $url
        ];
    }

    /*
     * kintone公式仕様:
     * X-Cybozu-Authorization =
     * Base64("ログイン名:パスワード")
     */
    $authorization = base64_encode($login . ':' . $password);

    $headers = [
        'Accept: application/json',
        'X-Cybozu-Authorization: ' . $authorization
    ];

    /*
     * GETにはContent-Typeもbodyも付けない。
     */
    if ($method !== 'GET' && $body !== null) {
        $headers[] = 'Content-Type: application/json';

        $encodedBody = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($encodedBody === false) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'JSON',
                'message' => 'kintone送信用JSONの生成に失敗しました。',
                'endpoint' => $url
            ];
        }
    } else {
        $encodedBody = null;
    }

    $httpOptions = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 30,
        'protocol_version' => 1.1
    ];

    if ($encodedBody !== null) {
        $httpOptions['content'] = $encodedBody;
    }

    $proxy = trim((string)($settings['proxy'] ?? ''));

    if ($proxy !== '') {
        if (!preg_match('/^[^:\/\s]+:\d+$/', $proxy)) {
            return [
                'ok' => false,
                'status' => 0,
                'error_code' => 'PROXY',
                'message' => 'Proxyサーバは host:port 形式で入力してください。',
                'endpoint' => $url
            ];
        }

        $httpOptions['proxy'] = 'tcp://' . $proxy;
        $httpOptions['request_fulluri'] = true;
    }

    $verify = !empty($settings['ssl_verify']);

    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true
        ]
    ]);

    /*
     * 認証情報をURLへ絶対に埋め込まない。
     */
    $raw = @file_get_contents(
        $url,
        false,
        $context
    );

    $status = survey_http_status();

    if ($raw === false) {
        $message = 'kintone APIへの通信に失敗しました。';

        if ($status > 0) {
            $message .= ' HTTPステータス: ' . $status;
        }

        return [
            'ok' => false,
            'status' => $status,
            'error_code' => 'NETWORK',
            'message' => $message,
            'endpoint' => $url
        ];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $status,
            'error_code' => 'INVALID_JSON',
            'message' => 'kintoneからJSON形式ではないレスポンスが返されました。',
            'endpoint' => $url,
            'data' => $raw
        ];
    }

    if ($status >= 400) {
        $code = (string)($decoded['code'] ?? 'UNKNOWN');
        $message = (string)($decoded['message'] ?? 'kintone APIエラー');

        if ($status === 401 && $code === 'CB_WA01') {
            $message =
                'kintoneのユーザー認証に失敗しました。' .
                'ログイン名・パスワード、2要素認証、SAML認証、' .
                'IPアドレス制限等のcybozu.com設定を確認してください。';
        }

        return [
            'ok' => false,
            'status' => $status,
            'error_code' => $code,
            'message' => $message,
            'endpoint' => $url,
            'data' => $decoded
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'error_code' => '',
        'message' => '',
        'endpoint' => $url,
        'data' => $decoded
    ];
}

function survey_kintone_fields(array $settings): array
{
    $appId = (int)($settings['app_id'] ?? 0);

    if ($appId <= 0) {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'APP_ID',
            'message' => 'kintoneアプリIDが正しくありません。'
        ];
    }

    return survey_kintone_request(
        'GET',
        '/k/v1/app/form/fields.json?app=' . rawurlencode((string)$appId),
        $settings
    );
}

function survey_kintone_records(
    array $settings,
    string $query = '',
    int $limit = 500
): array {
    $appId = (int)($settings['app_id'] ?? 0);

    if ($appId <= 0) {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'APP_ID',
            'message' => 'kintoneアプリIDが正しくありません。'
        ];
    }

    $query = trim($query);
    $query .= ($query !== '' ? ' ' : '') . 'limit ' . max(1, min(500, $limit));

    $path =
        '/k/v1/records.json?app=' .
        rawurlencode((string)$appId) .
        '&query=' .
        rawurlencode($query);

    return survey_kintone_request(
        'GET',
        $path,
        $settings
    );
}

function survey_kintone_add_customer(
    array $settings,
    array $customer
): array {
    $appId = (int)($settings['app_id'] ?? 0);

    if ($appId <= 0) {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'APP_ID',
            'message' => 'kintoneアプリIDが設定されていません。'
        ];
    }

    $map = [];

    $singleMap = [
        'field_company' => 'company',
        'field_name' => 'name',
        'field_email' => 'email',
        'field_department' => 'department',
        'field_phone' => 'phone'
    ];

    foreach ($singleMap as $settingKey => $customerKey) {
        $code = trim((string)($settings[$settingKey] ?? ''));

        if ($code !== '') {
            $map[$code] = [
                'value' => (string)($customer[$customerKey] ?? '')
            ];
        }
    }

    $addressCodes = $settings['field_address'] ?? [];

    if (!is_array($addressCodes)) {
        $addressCodes = [];
    }

    foreach ($addressCodes as $code) {
        $code = trim((string)$code);

        if ($code === '') {
            continue;
        }

        $map[$code] = [
            'value' => (string)($customer['address'] ?? '')
        ];
    }

    if (!$map) {
        return [
            'ok' => false,
            'status' => 0,
            'error_code' => 'MAPPING',
            'message' => 'kintoneフィールドマッピングが設定されていません。'
        ];
    }

    return survey_kintone_request(
        'POST',
        '/k/v1/record.json',
        $settings,
        [
            'app' => $appId,
            'record' => $map
        ]
    );
}

/* ================================================================
 * API
 * ================================================================ */

$data = survey_load_data();

$action = (string)($_REQUEST['action'] ?? '');

if ($action === 'api') {
    $subAction = (string)($_POST['sub_action'] ?? $_GET['sub_action'] ?? '');

    if ($subAction !== 'public_answer') {
        survey_check_csrf();
    }

    if ($subAction === 'get_data') {
        $publicSurveys = [];

        foreach ($data['surveys'] as $survey) {
            if (!empty($survey['deleted'])) {
                continue;
            }

            $publicSurveys[] = $survey;
        }

        survey_json_response([
            'ok' => true,
            'data' => $data,
            'surveys' => $publicSurveys,
            'csrf_token' => survey_csrf()
        ]);
    }

    if ($subAction === 'save_survey') {
        $json = (string)($_POST['survey_json'] ?? '');
        $survey = json_decode($json, true);

        if (!is_array($survey)) {
            survey_json_response([
                'ok' => false,
                'message' => 'アンケートデータが不正です。'
            ], 400);
        }

        $now = survey_now();

        $survey['id'] = survey_clean_text(
            $survey['id'] ?? survey_id('survey'),
            100
        );

        $survey['title'] = survey_clean_text(
            $survey['title'] ?? '無題のアンケート',
            500
        );

        $survey['start_at'] = survey_clean_text(
            $survey['start_at'] ?? '',
            50
        );

        $survey['end_at'] = survey_clean_text(
            $survey['end_at'] ?? '',
            50
        );

        $survey['status'] =
            in_array(
                $survey['status'] ?? 'draft',
                ['draft', 'active', 'ended'],
                true
            )
            ? $survey['status']
            : 'draft';

        $survey['numbering_mode'] =
            ($survey['numbering_mode'] ?? 'global') === 'group'
                ? 'group'
                : 'global';

        $survey['groups'] =
            is_array($survey['groups'] ?? null)
                ? $survey['groups']
                : [];

        $survey['updated_at'] = $now;

        $found = false;

        foreach ($data['surveys'] as $i => $old) {
            if (($old['id'] ?? '') === $survey['id']) {
                $survey['created_at'] =
                    (string)($old['created_at'] ?? $now);
                $survey['deleted'] =
                    !empty($old['deleted']);

                $data['surveys'][$i] = $survey;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $survey['created_at'] = $now;
            $survey['deleted'] = false;
            $data['surveys'][] = $survey;
        }

        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' => 'アンケートを保存しました。',
            'survey' => $survey
        ]);
    }

    if ($subAction === 'delete_survey') {
        $id = (string)($_POST['survey_id'] ?? '');

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['deleted'] = true;
                $survey['updated_at'] = survey_now();
            }
        }
        unset($survey);

        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' => 'アンケートを削除しました。'
        ]);
    }

    if ($subAction === 'change_status') {
        $id = (string)($_POST['survey_id'] ?? '');
        $status = (string)($_POST['status'] ?? '');

        if (!in_array($status, ['draft', 'active', 'ended'], true)) {
            survey_json_response([
                'ok' => false,
                'message' => '不正なステータスです。'
            ], 400);
        }

        foreach ($data['surveys'] as &$survey) {
            if (($survey['id'] ?? '') === $id) {
                $survey['status'] = $status;
                $survey['updated_at'] = survey_now();
            }
        }
        unset($survey);

        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' => 'ステータスを変更しました。'
        ]);
    }

    if ($subAction === 'duplicate_survey') {
        $id = (string)($_POST['survey_id'] ?? '');
        $copy = null;

        foreach ($data['surveys'] as $survey) {
            if (($survey['id'] ?? '') === $id) {
                $copy = $survey;
                break;
            }
        }

        if (!$copy) {
            survey_json_response([
                'ok' => false,
                'message' => '複製対象がありません。'
            ], 404);
        }

        $copy['id'] = survey_id('survey');
        $copy['title'] =
            survey_clean_text($copy['title'] ?? '', 500) . '（複製）';
        $copy['status'] = 'draft';
        $copy['deleted'] = false;
        $copy['created_at'] = survey_now();
        $copy['updated_at'] = survey_now();

        $data['surveys'][] = $copy;

        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' => 'アンケートを複製しました。',
            'survey' => $copy
        ]);
    }

    if ($subAction === 'save_settings') {
        $json = (string)($_POST['settings_json'] ?? '');
        $settings = json_decode($json, true);

        if (!is_array($settings)) {
            survey_json_response([
                'ok' => false,
                'message' => '設定データが不正です。'
            ], 400);
        }

        $old = $data['settings'];

        $new = [
            'subdomain' => survey_clean_text(
                $settings['subdomain'] ?? '',
                255
            ),
            'login_name' => survey_clean_text(
                $settings['login_name'] ?? '',
                255
            ),
            /*
             * パスワードはtrimしない。
             */
            'password' =>
                array_key_exists('password', $settings) &&
                (string)$settings['password'] !== ''
                    ? (string)$settings['password']
                    : (string)($old['password'] ?? ''),
            'app_id' => survey_clean_text(
                $settings['app_id'] ?? '',
                50
            ),
            'ssl_verify' => !empty($settings['ssl_verify']),
            'proxy' => survey_clean_text(
                $settings['proxy'] ?? '',
                255
            ),
            'field_company' => survey_clean_text(
                $settings['field_company'] ?? '',
                255
            ),
            'field_name' => survey_clean_text(
                $settings['field_name'] ?? '',
                255
            ),
            'field_email' => survey_clean_text(
                $settings['field_email'] ?? '',
                255
            ),
            'field_department' => survey_clean_text(
                $settings['field_department'] ?? '',
                255
            ),
            'field_phone' => survey_clean_text(
                $settings['field_phone'] ?? '',
                255
            ),
            'field_address' =>
                is_array($settings['field_address'] ?? null)
                    ? array_values($settings['field_address'])
                    : []
        ];

        $data['settings'] = $new;
        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' => 'kintone設定を保存しました。'
        ]);
    }

    if ($subAction === 'kintone_fields') {
        $settings = $data['settings'];

        $incoming = json_decode(
            (string)($_POST['settings_json'] ?? ''),
            true
        );

        if (is_array($incoming)) {
            $settings = array_merge($settings, $incoming);
        }

        $result = survey_kintone_fields($settings);

        if (!$result['ok']) {
            survey_json_response($result, 400);
        }

        $properties = $result['data']['properties'] ?? [];

        $fields = [];

        if (is_array($properties)) {
            foreach ($properties as $code => $property) {
                if (!is_array($property)) {
                    continue;
                }

                $fields[] = [
                    'code' => (string)$code,
                    'label' => (string)($property['label'] ?? $code),
                    'type' => (string)($property['type'] ?? '')
                ];
            }
        }

        usort(
            $fields,
            static fn(array $a, array $b): int =>
                strcmp($a['label'], $b['label'])
        );

        survey_json_response([
            'ok' => true,
            'fields' => $fields
        ]);
    }

    if ($subAction === 'kintone_test') {
        $result = survey_kintone_fields($data['settings']);

        if (!$result['ok']) {
            survey_json_response([
                'ok' => false,
                'status' => $result['status'] ?? 0,
                'error_code' => $result['error_code'] ?? '',
                'message' => $result['message'] ?? '接続失敗',
                'endpoint' => $result['endpoint'] ?? ''
            ], 400);
        }

        survey_json_response([
            'ok' => true,
            'message' => 'kintoneへの接続と認証に成功しました。'
        ]);
    }

    if ($subAction === 'sync_customers') {
        $settings = $data['settings'];

        $result = survey_kintone_records(
            $settings,
            '',
            500
        );

        if (!$result['ok']) {
            survey_json_response($result, 400);
        }

        $records = $result['data']['records'] ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        $map = $settings;

        $companyCode = (string)($map['field_company'] ?? '');
        $nameCode = (string)($map['field_name'] ?? '');
        $emailCode = (string)($map['field_email'] ?? '');
        $deptCode = (string)($map['field_department'] ?? '');
        $phoneCode = (string)($map['field_phone'] ?? '');

        $getValue = static function(
            array $record,
            string $code
        ): string {
            if ($code === '') {
                return '';
            }

            $v = $record[$code]['value'] ?? '';

            if (is_array($v)) {
                $parts = [];

                foreach ($v as $item) {
                    if (is_array($item)) {
                        $parts[] = (string)($item['name'] ?? $item['code'] ?? '');
                    } else {
                        $parts[] = (string)$item;
                    }
                }

                return implode(', ', $parts);
            }

            return (string)$v;
        };

        $count = 0;

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $email = $getValue($record, $emailCode);

            if ($email === '') {
                continue;
            }

            $customer = [
                'id' => 'k_' . (string)($record['$id']['value'] ?? survey_id()),
                'company' => $getValue($record, $companyCode),
                'name' => $getValue($record, $nameCode),
                'email' => $email,
                'department' => $getValue($record, $deptCode),
                'phone' => $getValue($record, $phoneCode),
                'address' => '',
                'source' => 'kintone',
                'sent_at' => '',
                'send_count' => 0,
                'answer_status' => 'unanswered',
                'kintone_status' => 'registered'
            ];

            $found = false;

            foreach ($data['customers'] as $i => $oldCustomer) {
                if (
                    strtolower((string)($oldCustomer['email'] ?? '')) ===
                    strtolower($email)
                ) {
                    $customer['sent_at'] =
                        (string)($oldCustomer['sent_at'] ?? '');
                    $customer['send_count'] =
                        (int)($oldCustomer['send_count'] ?? 0);
                    $customer['answer_status'] =
                        (string)($oldCustomer['answer_status'] ?? 'unanswered');

                    $data['customers'][$i] = array_merge(
                        $oldCustomer,
                        $customer
                    );

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $data['customers'][] = $customer;
            }

            $count++;
        }

        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' => $count . '件の顧客を同期しました。',
            'count' => $count
        ]);
    }

    if ($subAction === 'register_customer') {
        $id = (string)($_POST['customer_id'] ?? '');
        $customer = null;

        foreach ($data['customers'] as $item) {
            if (($item['id'] ?? '') === $id) {
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

        $result = survey_kintone_add_customer(
            $data['settings'],
            $customer
        );

        if (!$result['ok']) {
            survey_json_response($result, 400);
        }

        foreach ($data['customers'] as &$item) {
            if (($item['id'] ?? '') === $id) {
                $item['kintone_status'] = 'registered';
            }
        }
        unset($item);

        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' => 'kintoneへの登録処理が完了しました。'
        ]);
    }

    if ($subAction === 'send_mail') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $recipientIds = json_decode(
            (string)($_POST['recipient_ids'] ?? '[]'),
            true
        );

        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

        $subject = survey_clean_text(
            $_POST['mail_subject'] ?? '',
            500
        );

        $body = survey_clean_text(
            $_POST['mail_body'] ?? '',
            30000
        );

        $templateType =
            in_array(
                $_POST['template_type'] ?? 'initial',
                ['initial', 'reminder'],
                true
            )
            ? (string)$_POST['template_type']
            : 'initial';

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (($s['id'] ?? '') === $surveyId) {
                $survey = $s;
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

        foreach ($data['customers'] as &$customer) {
            if (
                !in_array(
                    (string)($customer['id'] ?? ''),
                    array_map('strval', $recipientIds),
                    true
                )
            ) {
                continue;
            }

            $email = trim((string)($customer['email'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                continue;
            }

            $individualUrl =
                (isset($_SERVER['HTTPS']) &&
                 $_SERVER['HTTPS'] !== 'off'
                    ? 'https'
                    : 'http') .
                '://' .
                ($_SERVER['HTTP_HOST'] ?? '') .
                dirname($_SERVER['SCRIPT_NAME'] ?? '/') .
                '/index.php?action=public&survey_id=' .
                rawurlencode($surveyId) .
                '&customer_id=' .
                rawurlencode((string)$customer['id']);

            $replace = [
                '{顧客名}' => (string)($customer['name'] ?? ''),
                '{アンケートURL}' => $individualUrl
            ];

            $actualSubject = strtr($subject, $replace);
            $actualBody = strtr($body, $replace);

            $ok = @mail(
                $email,
                $actualSubject,
                $actualBody,
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "From: " .
                ((string)($_SERVER['SERVER_ADMIN'] ?? 'noreply@localhost'))
            );

            /*
             * mail() はSMTP送信成功そのものではなく、
             * MTAへの引き渡し成否を返す。
             */
            if ($ok) {
                $customer['sent_at'] = survey_now();
                $customer['send_count'] =
                    (int)($customer['send_count'] ?? 0) + 1;

                $customer['answer_status'] = 'unanswered';

                $sent++;

                $data['mail_logs'][] = [
                    'id' => survey_id('mail'),
                    'survey_id' => $surveyId,
                    'customer_id' => $customer['id'],
                    'sent_at' => survey_now(),
                    'type' => $templateType,
                    'subject' => $actualSubject,
                    'body' => $actualBody,
                    'email' => $email,
                    'operator' => (string)($_SESSION['operator'] ?? 'admin')
                ];
            } else {
                $failed++;
            }
        }
        unset($customer);

        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' =>
                $sent . '件送信処理しました。' .
                ($failed > 0 ? ' ' . $failed . '件は失敗しました。' : ''),
            'sent' => $sent,
            'failed' => $failed
        ]);
    }

    if ($subAction === 'kintone_register') {
        $customerId = (string)($_POST['customer_id'] ?? '');

        foreach ($data['customers'] as &$customer) {
            if (($customer['id'] ?? '') === $customerId) {
                $customer['kintone_status'] = 'registered';
            }
        }
        unset($customer);

        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' => 'kintone登録済みに変更しました。'
        ]);
    }

    if ($subAction === 'public_answer') {
        $surveyId = (string)($_POST['survey_id'] ?? '');
        $customerId = (string)($_POST['customer_id'] ?? '');

        $answers = json_decode(
            (string)($_POST['answers'] ?? '{}'),
            true
        );

        if (!is_array($answers)) {
            $answers = [];
        }

        $survey = null;

        foreach ($data['surveys'] as $s) {
            if (
                ($s['id'] ?? '') === $surveyId &&
                empty($s['deleted'])
            ) {
                $survey = $s;
                break;
            }
        }

        if (!$survey) {
            survey_json_response([
                'ok' => false,
                'message' => 'アンケートが見つかりません。'
            ], 404);
        }

        $customer = null;

        foreach ($data['customers'] as $c) {
            if (($c['id'] ?? '') === $customerId) {
                $customer = $c;
                break;
            }
        }

        $response = [
            'id' => survey_id('response'),
            'survey_id' => $surveyId,
            'customer_id' => $customerId,
            'company' => (string)($customer['company'] ?? ''),
            'name' => (string)($customer['name'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'answered_at' => survey_now(),
            'answers' => $answers
        ];

        $data['responses'][] = $response;

        foreach ($data['customers'] as &$c) {
            if (($c['id'] ?? '') === $customerId) {
                $c['answer_status'] = 'answered';
            }
        }
        unset($c);

        survey_save_data($data);

        survey_json_response([
            'ok' => true,
            'message' => '回答を送信しました。'
        ]);
    }

    survey_json_response([
        'ok' => false,
        'message' => '不明なAPIです。'
    ], 404);
}

/* ================================================================
 * CSV
 * ================================================================ */

if ($action === 'csv') {
    $surveyId = (string)($_GET['survey_id'] ?? '');

    $survey = null;

    foreach ($data['surveys'] as $s) {
        if (($s['id'] ?? '') === $surveyId) {
            $survey = $s;
            break;
        }
    }

    if (!$survey) {
        http_response_code(404);
        exit('Survey not found');
    }

    $questions = [];

    foreach ($survey['groups'] ?? [] as $group) {
        foreach ($group['questions'] ?? [] as $question) {
            $questions[] = $question;
        }
    }

    $filename =
        'survey_' .
        preg_replace('/[^A-Za-z0-9_-]/', '_', $surveyId) .
        '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
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

    foreach ($questions as $q) {
        $header[] = (string)($q['text'] ?? '設問');
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

        $answers = is_array($response['answers'] ?? null)
            ? $response['answers']
            : [];

        foreach ($questions as $q) {
            $qid = (string)($q['id'] ?? '');
            $value = $answers[$qid] ?? '';

            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $row[] = (string)$value;
        }

        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

/* ================================================================
 * Public answer page
 * ================================================================ */

if ($action === 'public') {
    $surveyId = (string)($_GET['survey_id'] ?? '');
    $customerId = (string)($_GET['customer_id'] ?? '');

    $publicSurvey = null;

    foreach ($data['surveys'] as $survey) {
        if (
            ($survey['id'] ?? '') === $surveyId &&
            empty($survey['deleted'])
        ) {
            $publicSurvey = $survey;
            break;
        }
    }

    if (!$publicSurvey) {
        http_response_code(404);
        echo '<!doctype html><meta charset="UTF-8">';
        echo '<p>アンケートが見つかりません。</p>';
        exit;
    }

    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= survey_h($publicSurvey['title']) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-50 text-slate-800">
    <main class="max-w-3xl mx-auto p-6">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-2xl font-bold mb-8">
                <?= survey_h($publicSurvey['title']) ?>
            </h1>

            <form
                id="public_form"
                class="space-y-8"
                onsubmit="return App.public.submit(event)"
            >
            <?php
            $number = 0;

            foreach ($publicSurvey['groups'] ?? [] as $group) {
                ?>
                <section class="border-t border-slate-200 pt-6">
                    <h2 class="text-lg font-bold mb-5">
                        <?= survey_h($group['name'] ?? '') ?>
                    </h2>

                    <div class="space-y-6">
                    <?php
                    foreach ($group['questions'] ?? [] as $question) {
                        $number++;
                        $qid = (string)($question['id'] ?? '');
                        $qtext = (string)($question['text'] ?? '');
                        $type = (string)($question['type'] ?? 'text');
                        $required = !empty($question['required']);
                        ?>
                        <div class="space-y-3">
                            <label class="block font-semibold">
                                Q<?= $number ?>.
                                <?= survey_h($qtext) ?>
                                <?php if ($required): ?>
                                    <span class="text-red-500">必須</span>
                                <?php endif; ?>
                            </label>

                            <?php if ($type === 'single'): ?>

                                <div class="space-y-2">
                                <?php foreach ($question['options'] ?? [] as $oi => $option): ?>
                                    <label class="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="q_<?= survey_h($qid) ?>"
                                            value="<?= survey_h($option) ?>"
                                            <?= $required ? 'required' : '' ?>
                                            class="w-4 h-4"
                                        >
                                        <span><?= survey_h($option) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                </div>

                            <?php elseif ($type === 'multiple'): ?>

                                <div class="space-y-2">
                                <?php foreach ($question['options'] ?? [] as $option): ?>
                                    <label class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            name="q_<?= survey_h($qid) ?>[]"
                                            value="<?= survey_h($option) ?>"
                                            class="w-4 h-4"
                                        >
                                        <span><?= survey_h($option) ?></span>
                                    </label>
                                <?php endforeach; ?>
                                </div>

                            <?php else: ?>

                                <textarea
                                    name="q_<?= survey_h($qid) ?>"
                                    rows="4"
                                    <?= $required ? 'required' : '' ?>
                                    class="w-full border border-slate-300 rounded-xl p-3 outline-none focus:ring-2 focus:ring-blue-500"
                                ></textarea>

                            <?php endif; ?>
                        </div>
                        <?php
                    }
                    ?>
                    </div>
                </section>
                <?php
            }
            ?>

            <button
                type="submit"
                class="w-full py-3 rounded-xl bg-blue-600 text-white font-bold hover:bg-blue-700"
            >
                回答を送信
            </button>

            </form>
        </div>
    </main>

    <script>
    window.App = window.App || {};
    App.public = {
        async submit(event) {
            event.preventDefault();

            const form = event.currentTarget;
            const data = new FormData(form);
            const answers = {};

            for (const [key, value] of data.entries()) {
                if (!key.startsWith('q_')) continue;

                const id = key.substring(2);

                if (key.endsWith('[]')) {
                    const clean = id.substring(0, id.length - 2);
                    if (!Array.isArray(answers[clean])) {
                        answers[clean] = [];
                    }
                    answers[clean].push(value);
                } else {
                    answers[id] = value;
                }
            }

            const body = new URLSearchParams();

            body.set('action', 'api');
            body.set('sub_action', 'public_answer');
            body.set('survey_id', <?= json_encode($surveyId, JSON_UNESCAPED_UNICODE) ?>);
            body.set('customer_id', <?= json_encode($customerId, JSON_UNESCAPED_UNICODE) ?>);
            body.set('answers', JSON.stringify(answers));

            const response = await fetch(location.href, {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body
            });

            const result = await response.json();

            if (!result.ok) {
                alert(result.message || '送信に失敗しました。');
                return false;
            }

            form.innerHTML =
                '<div class="text-center py-16">' +
                '<div class="text-5xl mb-5">✓</div>' +
                '<h2 class="text-2xl font-bold mb-3">回答ありがとうございました</h2>' +
                '<p class="text-slate-500">回答は正常に受け付けられました。</p>' +
                '</div>';

            return false;
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
<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>
<title>アンケート管理システム</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
</head>

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {
    state: {
        initialized: false,
        csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
        data: null,
        screen: 'list',
        survey: null,
        fields: [],
        filters: {
            keyword: '',
            status: '',
            sort: 'updated_desc',
            customer: ''
        },
        selectedQuestions: {},
        selectedCustomers: [],
        previewMobile: false,
        responseId: null,
        responseFilter: ''
    },

    API: {},

    render: {},

    actions: {},

    utils: {},

    init() {
        if (this.state.initialized) return;
        this.state.initialized = true;

        this.API.load();
    }
};

/* ================================================================
 * Utility
 * ================================================================ */

App.utils.escape = function(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
};

App.utils.id = function(prefix = 'id') {
    return prefix + '_' +
        Date.now().toString(36) + '_' +
        Math.random().toString(36).slice(2, 10);
};

App.utils.confirm = function(message) {
    return window.confirm(message);
};

App.utils.statusLabel = function(status) {
    return {
        draft: '下書き',
        active: '公開中',
        ended: '終了'
    }[status] || status;
};

App.utils.statusClass = function(status) {
    return {
        draft: 'bg-slate-100 text-slate-600',
        active: 'bg-emerald-100 text-emerald-700',
        ended: 'bg-amber-100 text-amber-700'
    }[status] || 'bg-slate-100 text-slate-600';
};

App.utils.typeLabel = function(type) {
    return {
        single: '単一選択',
        multiple: '複数選択',
        text: '自由記述'
    }[type] || type;
};

App.utils.formatDate = function(value) {
    if (!value) return '未設定';

    const d = new Date(String(value).replace(' ', 'T'));

    if (Number.isNaN(d.getTime())) return value;

    return d.toLocaleString('ja-JP', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
};

App.utils.questionList = function(survey) {
    const result = [];

    (survey.groups || []).forEach(group => {
        (group.questions || []).forEach(question => {
            result.push({
                group,
                question
            });
        });
    });

    return result;
};

App.utils.answerCount = function(surveyId) {
    return (App.state.data.responses || [])
        .filter(x => x.survey_id === surveyId)
        .length;
};

App.utils.customerCountSent = function(surveyId) {
    return (App.state.data.customers || [])
        .filter(x => Number(x.send_count || 0) > 0)
        .length;
};

App.utils.html = function(strings, ...values) {
    return strings.reduce(
        (result, string, i) =>
            result + string + (values[i] ?? ''),
        ''
    );
};

/* ================================================================
 * API
 * ================================================================ */

App.API.request = async function(subAction, payload = {}) {
    const body = new URLSearchParams();

    body.set('action', 'api');
    body.set('sub_action', subAction);
    body.set('csrf_token', App.state.csrf);

    Object.entries(payload).forEach(([key, value]) => {
        if (typeof value === 'object') {
            body.set(key, JSON.stringify(value));
        } else {
            body.set(key, String(value ?? ''));
        }
    });

    const response = await fetch(location.href, {
        method: 'POST',
        headers: {
            'Content-Type':
                'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body
    });

    let result;

    try {
        result = await response.json();
    } catch (e) {
        throw new Error(
            'サーバーからJSONではないレスポンスが返されました。'
        );
    }

    if (!result.ok) {
        const error = new Error(
            result.message || 'APIエラー'
        );

        error.result = result;
        throw error;
    }

    return result;
};

App.API.load = async function() {
    try {
        const result =
            await App.API.request('get_data');

        App.state.data = result.data;
        App.state.csrf = result.csrf_token;

        App.render.shell();
        App.render.list();
    } catch (error) {
        document.getElementById('app').innerHTML =
            '<div class="max-w-3xl mx-auto p-8">' +
            '<div class="bg-white rounded-2xl shadow p-8">' +
            '<h1 class="text-xl font-bold text-red-600 mb-3">' +
            '初期化エラー' +
            '</h1>' +
            '<p>' +
            App.utils.escape(error.message) +
            '</p>' +
            '</div></div>';
    }
};

/* ================================================================
 * Shell
 * ================================================================ */

App.render.shell = function() {
    document.getElementById('app').innerHTML = `
        <div class="min-h-screen">
            <header class="sticky top-0 z-30 bg-white border-b border-slate-200">
                <div class="max-w-[1600px] mx-auto px-5 py-3 flex items-center justify-between">
                    <div>
                        <div class="font-bold text-lg">
                            アンケート管理システム
                        </div>
                        <div class="text-xs text-slate-400">
                            Survey Management
                        </div>
                    </div>

                    <nav class="flex gap-2">
                        <button
                            class="px-4 py-2 rounded-lg hover:bg-slate-100"
                            onclick="App.actions.home()"
                        >
                            アンケート一覧
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg hover:bg-slate-100"
                            onclick="App.actions.settings()"
                        >
                            kintone連携設定
                        </button>

                        <button
                            class="px-4 py-2 rounded-lg text-red-600 hover:bg-red-50"
                            onclick="App.actions.logout()"
                        >
                            ログアウト
                        </button>
                    </nav>
                </div>
            </header>

            <main
                id="main"
                class="max-w-[1600px] mx-auto p-5"
            ></main>

            <div id="preview_modal"></div>
            <div id="response_modal"></div>
        </div>
    `;
};

App.actions.home = function() {
    App.state.screen = 'list';
    App.state.survey = null;
    App.render.list();
};

App.actions.logout = function() {
    if (!confirm('ログアウトしますか？')) return;

    location.href =
        location.pathname +
        '?action=logout';
};

App.actions.settings = function() {
    App.state.screen = 'settings';
    App.render.settings();
};

/* ================================================================
 * List
 * ================================================================ */

App.render.list = function() {
    const data = App.state.data;
    const main = document.getElementById('main');

    let surveys = (data.surveys || [])
        .filter(x => !x.deleted);

    const keyword =
        App.state.filters.keyword.toLowerCase();

    if (keyword) {
        surveys = surveys.filter(x =>
            String(x.title || '')
                .toLowerCase()
                .includes(keyword)
        );
    }

    if (App.state.filters.status) {
        surveys = surveys.filter(
            x => x.status === App.state.filters.status
        );
    }

    const sort = App.state.filters.sort;

    surveys.sort((a, b) => {
        if (sort === 'updated_desc') {
            return String(b.updated_at || '')
                .localeCompare(String(a.updated_at || ''));
        }

        if (sort === 'updated_asc') {
            return String(a.updated_at || '')
                .localeCompare(String(b.updated_at || ''));
        }

        if (sort === 'answers_desc') {
            return App.utils.answerCount(b.id) -
                App.utils.answerCount(a.id);
        }

        if (sort === 'answers_asc') {
            return App.utils.answerCount(a.id) -
                App.utils.answerCount(b.id);
        }

        if (sort === 'start_desc') {
            return String(b.start_at || '')
                .localeCompare(String(a.start_at || ''));
        }

        if (sort === 'start_asc') {
            return String(a.start_at || '')
                .localeCompare(String(b.start_at || ''));
        }

        return 0;
    });

    main.innerHTML = `
        <div class="flex items-center justify-between mb-5">
            <div>
                <h1 class="text-2xl font-bold">
                    アンケート一覧
                </h1>
                <p class="text-sm text-slate-500 mt-1">
                    アンケートの作成・公開・集計・送信を管理します。
                </p>
            </div>

            <button
                onclick="App.actions.newSurvey()"
                class="bg-blue-600 text-white px-5 py-3 rounded-xl font-bold hover:bg-blue-700"
            >
                ＋ 新規アンケート作成
            </button>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-4 mb-5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <input
                    id="list_keyword"
                    value="${App.utils.escape(App.state.filters.keyword)}"
                    placeholder="タイトルを検索"
                    class="border border-slate-300 rounded-xl px-3 py-2"
                    onkeydown="if(event.key==='Enter')App.actions.searchList()"
                >

                <select
                    class="border border-slate-300 rounded-xl px-3 py-2"
                    onchange="App.actions.statusFilter(this.value)"
                >
                    <option value="">すべて</option>
                    <option value="active"
                        ${App.state.filters.status === 'active' ? 'selected' : ''}>
                        公開中
                    </option>
                    <option value="draft"
                        ${App.state.filters.status === 'draft' ? 'selected' : ''}>
                        下書き
                    </option>
                    <option value="ended"
                        ${App.state.filters.status === 'ended' ? 'selected' : ''}>
                        終了
                    </option>
                </select>

                <select
                    class="border border-slate-300 rounded-xl px-3 py-2"
                    onchange="App.actions.sortList(this.value)"
                >
                    <option value="updated_desc"
                        ${sort === 'updated_desc' ? 'selected' : ''}>
                        更新日：新しい順
                    </option>
                    <option value="updated_asc"
                        ${sort === 'updated_asc' ? 'selected' : ''}>
                        更新日：古い順
                    </option>
                    <option value="answers_desc"
                        ${sort === 'answers_desc' ? 'selected' : ''}>
                        回答数：多い順
                    </option>
                    <option value="answers_asc"
                        ${sort === 'answers_asc' ? 'selected' : ''}>
                        回答数：少ない順
                    </option>
                    <option value="start_desc"
                        ${sort === 'start_desc' ? 'selected' : ''}>
                        開始日：新しい順
                    </option>
                    <option value="start_asc"
                        ${sort === 'start_asc' ? 'selected' : ''}>
                        開始日：古い順
                    </option>
                </select>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 overflow-x-auto">
            <table class="w-full min-w-[1100px]">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-left text-sm">
                        <th class="p-4">作成日 / 更新日</th>
                        <th class="p-4">タイトル</th>
                        <th class="p-4">アンケート期間</th>
                        <th class="p-4">ステータス</th>
                        <th class="p-4 text-right">回答数</th>
                        <th class="p-4">操作</th>
                    </tr>
                </thead>
                <tbody>
                    ${
                        surveys.length
                        ? surveys.map(
                            survey =>
                                App.render.surveyRow(survey)
                        ).join('')
                        : `
                            <tr>
                                <td colspan="6"
                                    class="p-12 text-center text-slate-400">
                                    アンケートがありません。
                                </td>
                            </tr>
                        `
                    }
                </tbody>
            </table>
        </div>
    `;
};

App.render.surveyRow = function(survey) {
    const status = survey.status;
    const count = App.utils.answerCount(survey.id);

    let buttons = `
        <button
            onclick="App.actions.editSurvey('${survey.id}')"
            class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-sm"
        >
            確認・編集
        </button>
    `;

    if (status === 'active') {
        buttons += `
            <button
                onclick="App.actions.analysis('${survey.id}')"
                class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 text-sm"
            >
                集計
            </button>

            <button
                onclick="App.actions.mail('${survey.id}')"
                class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 text-sm"
            >
                送信
            </button>

            <button
                onclick="App.actions.changeStatus('${survey.id}','ended')"
                class="px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 hover:bg-amber-100 text-sm"
            >
                停止
            </button>
        `;
    }

    if (status === 'draft') {
        buttons += `
            <button
                onclick="App.actions.deleteSurvey('${survey.id}')"
                class="px-3 py-1.5 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 text-sm"
            >
                削除
            </button>
        `;
    }

    if (status === 'ended') {
        buttons += `
            <button
                onclick="App.actions.analysis('${survey.id}')"
                class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 text-sm"
            >
                集計
            </button>

            <button
                onclick="App.actions.changeStatus('${survey.id}','active')"
                class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 text-sm"
            >
                再開
            </button>
        `;
    }

    buttons += `
        <button
            onclick="App.actions.duplicateSurvey('${survey.id}')"
            class="px-3 py-1.5 rounded-lg bg-slate-50 hover:bg-slate-100 text-sm"
        >
            複製
        </button>
    `;

    return `
        <tr class="border-b border-slate-100">
            <td class="p-4 text-sm">
                <div>${App.utils.escape(
                    String(survey.created_at || '').slice(0,10)
                )}</div>
                <div class="text-xs text-slate-400">
                    更新:
                    ${App.utils.escape(
                        String(survey.updated_at || '').slice(0,10)
                    )}
                </div>
            </td>

            <td class="p-4 font-bold">
                ${App.utils.escape(survey.title || '')}
            </td>

            <td class="p-4 text-sm">
                ${
                    survey.start_at || survey.end_at
                    ? App.utils.escape(
                        (survey.start_at || '未設定') +
                        ' ～ ' +
                        (survey.end_at || '未設定')
                    )
                    : '未設定'
                }
            </td>

            <td class="p-4">
                <span class="px-2.5 py-1 rounded-full text-xs font-bold ${App.utils.statusClass(status)}">
                    ${App.utils.statusLabel(status)}
                </span>
            </td>

            <td class="p-4 text-right font-bold">
                ${count} 件
            </td>

            <td class="p-4">
                <div class="flex flex-wrap gap-2">
                    ${buttons}
                </div>
            </td>
        </tr>
    `;
};

App.actions.searchList = function() {
    App.state.filters.keyword =
        document.getElementById('list_keyword')?.value || '';

    App.render.list();
};

App.actions.statusFilter = function(value) {
    App.state.filters.status = value;
    App.render.list();
};

App.actions.sortList = function(value) {
    App.state.filters.sort = value;
    App.render.list();
};

App.actions.newSurvey = function() {
    App.state.survey = {
        id: App.utils.id('survey'),
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

    App.state.screen = 'editor';
    App.render.editor();
};

App.actions.editSurvey = function(id) {
    const survey =
        App.state.data.surveys.find(x => x.id === id);

    if (!survey) return;

    App.state.survey =
        JSON.parse(JSON.stringify(survey));

    App.state.screen = 'editor';
    App.render.editor();
};

App.actions.changeStatus = async function(id, status) {
    const label =
        status === 'active' ? '公開' :
        status === 'ended' ? '停止' : '下書き';

    if (!confirm(label + 'に変更しますか？')) return;

    try {
        await App.API.request('change_status', {
            survey_id: id,
            status
        });

        await App.API.load();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.deleteSurvey = async function(id) {
    if (!confirm('このアンケートを削除しますか？')) return;

    try {
        await App.API.request('delete_survey', {
            survey_id: id
        });

        await App.API.load();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.duplicateSurvey = async function(id) {
    try {
        await App.API.request('duplicate_survey', {
            survey_id: id
        });

        await App.API.load();
    } catch (error) {
        alert(error.message);
    }
};

/* ================================================================
 * Editor
 * ================================================================ */

App.render.editor = function() {
    const survey = App.state.survey;
    const main = document.getElementById('main');

    main.innerHTML = `
        <div class="flex items-center justify-between mb-5">
            <div>
                <div class="text-sm text-slate-400 mb-1">
                    アンケート一覧 ＞ 編集
                </div>

                <input
                    id="survey_title"
                    value="${App.utils.escape(survey.title || '')}"
                    class="text-2xl font-bold bg-transparent border-b border-transparent hover:border-slate-300 focus:border-blue-500 outline-none w-full"
                    oninput="App.actions.editorChanged()"
                >
            </div>

            <div class="flex gap-2">
                <button
                    onclick="App.actions.preview()"
                    class="px-4 py-2 rounded-xl bg-white border border-slate-300"
                >
                    プレビュー
                </button>

                <button
                    onclick="App.actions.saveSurvey()"
                    class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold"
                >
                    保存して一覧へ戻る
                </button>

                <button
                    onclick="App.actions.cancelEditor()"
                    class="px-4 py-2 rounded-xl bg-slate-200"
                >
                    キャンセル
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-5">
            <aside class="bg-white rounded-2xl border border-slate-200 p-5 h-fit">
                <h2 class="font-bold mb-4">基本設定</h2>

                <label class="block text-sm font-semibold mb-1">
                    開始日時
                </label>
                <input
                    id="survey_start_at"
                    type="datetime-local"
                    value="${App.utils.escape(
                        (survey.start_at || '').replace(' ', 'T')
                    )}"
                    onchange="App.actions.editorChanged()"
                    class="w-full border rounded-xl px-3 py-2 mb-4"
                >

                <label class="block text-sm font-semibold mb-1">
                    終了日時
                </label>
                <input
                    id="survey_end_at"
                    type="datetime-local"
                    value="${App.utils.escape(
                        (survey.end_at || '').replace(' ', 'T')
                    )}"
                    onchange="App.actions.editorChanged()"
                    class="w-full border rounded-xl px-3 py-2 mb-4"
                >

                <label class="block text-sm font-semibold mb-1">
                    質問番号
                </label>
                <select
                    id="survey_numbering_mode"
                    onchange="App.actions.numberingChanged(this.value)"
                    class="w-full border rounded-xl px-3 py-2"
                >
                    <option value="global"
                        ${survey.numbering_mode === 'global' ? 'selected' : ''}>
                        Q1, Q2, Q3...
                    </option>
                    <option value="group"
                        ${survey.numbering_mode === 'group' ? 'selected' : ''}>
                        Q1-1, Q1-2...
                    </option>
                </select>
            </aside>

            <section class="lg:col-span-3">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-lg">
                        グループ・設問
                    </h2>

                    <button
                        onclick="App.actions.addGroup()"
                        class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold"
                    >
                        ＋ グループ追加
                    </button>
                </div>

                <div
                    id="question_editor"
                    class="space-y-4"
                ></div>
            </section>
        </div>
    `;

    App.render.groups();
};

App.actions.editorChanged = function() {
    if (!App.state.survey) return;

    App.state.survey.title =
        document.getElementById('survey_title')?.value || '';

    App.state.survey.start_at =
        document.getElementById('survey_start_at')?.value || '';

    App.state.survey.end_at =
        document.getElementById('survey_end_at')?.value || '';
};

App.actions.numberingChanged = function(value) {
    App.state.survey.numbering_mode =
        value === 'group' ? 'group' : 'global';

    App.render.groups();
};

App.actions.addGroup = function() {
    App.state.survey.groups.push({
        id: App.utils.id('group'),
        name: '新しいグループ',
        questions: []
    });

    App.render.groups();
};

App.actions.deleteGroup = function(groupId) {
    if (!confirm(
        'このグループと内包される質問を削除しますか？'
    )) {
        return;
    }

    App.state.survey.groups =
        App.state.survey.groups.filter(
            x => x.id !== groupId
        );

    App.render.groups();
};

App.actions.addQuestion = function(groupId) {
    const group =
        App.state.survey.groups.find(x => x.id === groupId);

    if (!group) return;

    group.questions.push({
        id: App.utils.id('question'),
        text: '新しい質問',
        type: 'single',
        required: false,
        options: ['選択肢1', '選択肢2'],
        other_enabled: false,
        branching: []
    });

    App.render.groups();
};

App.actions.deleteQuestion = function(groupId, questionId) {
    const group =
        App.state.survey.groups.find(x => x.id === groupId);

    if (!group) return;

    group.questions =
        group.questions.filter(q => q.id !== questionId);

    App.render.groups();
};

App.actions.questionChanged = function(
    groupId,
    questionId,
    field,
    value
) {
    const group =
        App.state.survey.groups.find(x => x.id === groupId);

    const question =
        group?.questions.find(q => q.id === questionId);

    if (!question) return;

    if (field === 'required') {
        question.required = value;
    } else if (field === 'other_enabled') {
        question.other_enabled = value;
    } else {
        question[field] = value;
    }
};

App.actions.groupNameChanged = function(
    groupId,
    value
) {
    const group =
        App.state.survey.groups.find(x => x.id === groupId);

    if (group) {
        group.name = value;
    }
};

App.actions.optionChanged = function(
    groupId,
    questionId,
    index,
    value
) {
    const group =
        App.state.survey.groups.find(x => x.id === groupId);

    const question =
        group?.questions.find(q => q.id === questionId);

    if (!question) return;

    question.options[index] = value;
};

App.actions.addOption = function(
    groupId,
    questionId
) {
    const group =
        App.state.survey.groups.find(x => x.id === groupId);

    const question =
        group?.questions.find(q => q.id === questionId);

    if (!question) return;

    if (!Array.isArray(question.options)) {
        question.options = [];
    }

    question.options.push(
        '選択肢' + (question.options.length + 1)
    );

    App.render.groups();
};

App.actions.deleteOption = function(
    groupId,
    questionId,
    index
) {
    const group =
        App.state.survey.groups.find(x => x.id === groupId);

    const question =
        group?.questions.find(q => q.id === questionId);

    if (!question) return;

    question.options.splice(index, 1);

    App.render.groups();
};

App.render.groups = function() {
    const container =
        document.getElementById('question_editor');

    if (!container) return;

    const survey = App.state.survey;

    container.innerHTML =
        survey.groups.map(
            (group, groupIndex) => `
            <section
                class="bg-white border border-slate-200 rounded-2xl p-5"
                data-group-id="${group.id}"
            >
                <div class="flex items-center gap-3 mb-5">
                    <span class="cursor-move text-slate-400 text-xl">
                        ⠿
                    </span>

                    <input
                        value="${App.utils.escape(group.name || '')}"
                        oninput="App.actions.groupNameChanged('${group.id}',this.value)"
                        class="flex-1 text-lg font-bold border-b border-transparent hover:border-slate-300 focus:border-blue-500 outline-none"
                    >

                    <button
                        onclick="App.actions.addQuestion('${group.id}')"
                        class="px-3 py-2 rounded-lg bg-blue-50 text-blue-700"
                    >
                        ＋質問
                    </button>

                    <button
                        onclick="App.actions.deleteGroup('${group.id}')"
                        class="px-3 py-2 rounded-lg bg-red-50 text-red-600"
                    >
                        削除
                    </button>
                </div>

                <div
                    class="question-list space-y-4"
                    data-group-id="${group.id}"
                >
                    ${
                        (group.questions || []).map(
                            (q, qi) =>
                                App.render.question(
                                    group,
                                    q,
                                    qi,
                                    groupIndex
                                )
                        ).join('')
                    }
                </div>
            </section>
        `
        ).join('');

    new Sortable(container, {
        animation: 180,
        handle: '.cursor-move',
        ghostClass: 'opacity-40',
        onEnd(event) {
            const items =
                Array.from(container.children);

            const groups = [];

            items.forEach(item => {
                const id =
                    item.dataset.groupId;

                const group =
                    survey.groups.find(
                        x => x.id === id
                    );

                if (group) groups.push(group);
            });

            survey.groups = groups;
            App.render.groups();
        }
    });

    document.querySelectorAll('.question-list').forEach(
        list => {
            new Sortable(list, {
                group: 'survey-questions',
                animation: 180,
                handle: '.question-handle',
                ghostClass: 'opacity-40',

                onEnd(event) {
                    const fromId =
                        event.from.dataset.groupId;

                    const toId =
                        event.to.dataset.groupId;

                    const fromGroup =
                        survey.groups.find(
                            x => x.id === fromId
                        );

                    const toGroup =
                        survey.groups.find(
                            x => x.id === toId
                        );

                    if (!fromGroup || !toGroup) return;

                    const moved =
                        fromGroup.questions
                            .find(
                                q =>
                                    q.id ===
                                    event.item.dataset.questionId
                            );

                    if (!moved) {
                        App.render.groups();
                        return;
                    }

                    fromGroup.questions =
                        fromGroup.questions.filter(
                            q => q.id !== moved.id
                        );

                    toGroup.questions.splice(
                        event.newIndex,
                        0,
                        moved
                    );

                    App.render.groups();
                }
            });
        }
    );
};

App.render.question = function(
    group,
    question,
    index,
    groupIndex
) {
    const mode =
        App.state.survey.numbering_mode;

    let number = '';

    if (mode === 'group') {
        number =
            'Q' +
            (groupIndex + 1) +
            '-' +
            (index + 1);
    } else {
        let total = 0;

        for (
            let i = 0;
            i < groupIndex;
            i++
        ) {
            total +=
                App.state.survey.groups[i].questions.length;
        }

        number =
            'Q' +
            (total + index + 1);
    }

    const type = question.type || 'single';

    return `
        <article
            class="border border-slate-200 rounded-xl p-4 bg-slate-50"
            data-question-id="${question.id}"
        >
            <div class="flex gap-3">
                <span class="question-handle cursor-move text-slate-400 text-xl">
                    ⠿
                </span>

                <div class="flex-1 space-y-4">
                    <div class="flex gap-3 items-start">
                        <span class="font-bold text-blue-600 pt-2">
                            ${number}
                        </span>

                        <input
                            value="${App.utils.escape(question.text || '')}"
                            oninput="App.actions.questionChanged('${group.id}','${question.id}','text',this.value)"
                            class="flex-1 bg-white border border-slate-300 rounded-xl px-3 py-2"
                        >

                        <button
                            onclick="App.actions.deleteQuestion('${group.id}','${question.id}')"
                            class="text-red-500 px-2"
                        >
                            削除
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <select
                            onchange="App.actions.questionChanged('${group.id}','${question.id}','type',this.value);App.render.groups()"
                            class="bg-white border border-slate-300 rounded-xl px-3 py-2"
                        >
                            <option value="single"
                                ${type === 'single' ? 'selected' : ''}>
                                単一選択
                            </option>
                            <option value="multiple"
                                ${type === 'multiple' ? 'selected' : ''}>
                                複数選択
                            </option>
                            <option value="text"
                                ${type === 'text' ? 'selected' : ''}>
                                自由記述
                            </option>
                        </select>

                        <label class="flex items-center gap-2 bg-white rounded-xl px-3">
                            <input
                                type="checkbox"
                                ${question.required ? 'checked' : ''}
                                onchange="App.actions.questionChanged('${group.id}','${question.id}','required',this.checked)"
                            >
                            必須回答
                        </label>

                        <label class="flex items-center gap-2 bg-white rounded-xl px-3">
                            <input
                                type="checkbox"
                                ${question.other_enabled ? 'checked' : ''}
                                onchange="App.actions.questionChanged('${group.id}','${question.id}','other_enabled',this.checked)"
                            >
                            その他
                        </label>
                    </div>

                    ${
                        type === 'single' ||
                        type === 'multiple'
                        ? `
                            <div class="bg-white rounded-xl p-3">
                                <div class="font-semibold mb-2">
                                    選択肢
                                </div>

                                <div class="space-y-2">
                                ${
                                    (question.options || []).map(
                                        (option, oi) => `
                                        <div class="flex gap-2">
                                            <input
                                                value="${App.utils.escape(option)}"
                                                oninput="App.actions.optionChanged('${group.id}','${question.id}',${oi},this.value)"
                                                class="flex-1 border border-slate-300 rounded-lg px-3 py-2"
                                            >
                                            <button
                                                onclick="App.actions.deleteOption('${group.id}','${question.id}',${oi})"
                                                class="px-3 text-red-500"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    `
                                    ).join('')
                                }
                                </div>

                                <button
                                    onclick="App.actions.addOption('${group.id}','${question.id}')"
                                    class="mt-3 text-blue-600 text-sm font-bold"
                                >
                                    ＋選択肢追加
                                </button>
                            </div>
                        `
                        : ''
                    }
                </div>
            </div>
        </article>
    `;
};

App.actions.saveSurvey = async function() {
    App.actions.editorChanged();

    App.state.survey.updated_at = new Date()
        .toISOString()
        .replace('T', ' ')
        .substring(0, 19);

    if (!App.state.survey.title.trim()) {
        alert('タイトルを入力してください。');
        return;
    }

    try {
        await App.API.request('save_survey', {
            survey_json: App.state.survey
        });

        alert('保存しました。');

        await App.API.load();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.cancelEditor = function() {
    if (!confirm(
        '変更を破棄して一覧へ戻りますか？'
    )) return;

    App.state.survey = null;
    App.render.list();
};

/* ================================================================
 * Preview
 * ================================================================ */

App.actions.preview = function() {
    const survey = App.state.survey;

    document.getElementById('preview_modal').innerHTML = `
        <div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-5">
            <div class="${App.state.previewMobile ? 'w-[390px]' : 'w-full max-w-3xl'} max-h-[90vh] overflow-auto bg-white rounded-2xl shadow-xl">
                <div class="sticky top-0 bg-white border-b p-4 flex justify-between items-center">
                    <div class="font-bold">
                        プレビュー
                    </div>

                    <div class="flex gap-2">
                        <button
                            onclick="App.actions.previewMode(false)"
                            class="px-3 py-1.5 rounded-lg border"
                        >
                            PC
                        </button>

                        <button
                            onclick="App.actions.previewMode(true)"
                            class="px-3 py-1.5 rounded-lg border"
                        >
                            スマートフォン
                        </button>

                        <button
                            onclick="App.actions.closePreview()"
                            class="px-3 py-1.5 rounded-lg bg-slate-200"
                        >
                            閉じる
                        </button>
                    </div>
                </div>

                <div id="preview_content" class="p-6">
                </div>
            </div>
        </div>
    `;

    App.render.previewContent();
};

App.actions.previewMode = function(mobile) {
    App.state.previewMobile = mobile;
    App.actions.preview();
};

App.actions.closePreview = function() {
    document.getElementById('preview_modal').innerHTML = '';
};

App.render.previewContent = function() {
    const survey = App.state.survey;
    const target =
        document.getElementById('preview_content');

    if (!target) return;

    target.innerHTML = `
        <h1 class="text-2xl font-bold mb-8">
            ${App.utils.escape(survey.title || '')}
        </h1>

        ${
            (survey.groups || []).map(
                group => `
                <section class="mb-8">
                    <h2 class="text-lg font-bold border-b pb-2 mb-5">
                        ${App.utils.escape(group.name || '')}
                    </h2>

                    <div class="space-y-7">
                    ${
                        (group.questions || []).map(
                            q => `
                            <div>
                                <div class="font-semibold mb-2">
                                    ${App.utils.escape(q.text || '')}
                                    ${q.required
                                        ? '<span class="text-red-500 ml-1">必須</span>'
                                        : ''}
                                </div>

                                ${
                                    q.type === 'text'
                                    ? `
                                        <textarea
                                            rows="4"
                                            class="w-full border rounded-xl p-3"
                                        ></textarea>
                                    `
                                    : `
                                        <div class="space-y-2">
                                        ${
                                            (q.options || []).map(
                                                o => `
                                                <label class="flex gap-2">
                                                    <input
                                                        type="${q.type === 'single' ? 'radio' : 'checkbox'}"
                                                        name="preview_${q.id}"
                                                    >
                                                    ${App.utils.escape(o)}
                                                </label>
                                            `
                                            ).join('')
                                        }
                                        </div>
                                    `
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
            onclick="alert('これはプレビューです。実際の送信は行われません。')"
            class="w-full bg-blue-600 text-white py-3 rounded-xl font-bold"
        >
            回答を送信
        </button>
    `;
};

/* ================================================================
 * Mail
 * ================================================================ */

App.actions.mail = function(surveyId) {
    App.state.survey =
        App.state.data.surveys.find(
            x => x.id === surveyId
        );

    App.state.screen = 'mail';
    App.render.mail();
};

App.render.mail = function() {
    const main = document.getElementById('main');
    const customers =
        App.state.data.customers || [];

    main.innerHTML = `
        <div class="mb-5">
            <div class="text-sm text-slate-400">
                ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
            </div>

            <h1 class="text-2xl font-bold mt-2">
                ${App.utils.escape(App.state.survey.title)}
            </h1>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
            <section class="xl:col-span-2 bg-white border rounded-2xl overflow-hidden">
                <div class="p-4 border-b flex gap-3">
                    <input
                        id="customer_filter"
                        placeholder="顧客名・メールアドレスで検索"
                        value="${App.utils.escape(App.state.filters.customer)}"
                        oninput="App.actions.customerFilter(this.value)"
                        class="flex-1 border rounded-xl px-3 py-2"
                    >

                    <button
                        onclick="App.actions.selectAllCustomers()"
                        class="px-3 py-2 border rounded-xl"
                    >
                        全選択
                    </button>

                    <button
                        onclick="App.actions.clearCustomers()"
                        class="px-3 py-2 border rounded-xl"
                    >
                        全解除
                    </button>
                </div>

                <div
                    id="customer_table"
                    class="overflow-x-auto"
                ></div>
            </section>

            <section class="bg-white border rounded-2xl p-5 h-fit">
                <h2 class="font-bold mb-4">
                    メールテンプレート
                </h2>

                <select
                    id="template_type"
                    onchange="App.actions.templateChanged(this.value)"
                    class="w-full border rounded-xl px-3 py-2 mb-3"
                >
                    <option value="initial">初回送信</option>
                    <option value="reminder">リマインド</option>
                </select>

                <input
                    id="mail_subject"
                    value="アンケートのお願い"
                    placeholder="件名"
                    class="w-full border rounded-xl px-3 py-2 mb-3"
                >

                <textarea
                    id="mail_body"
                    rows="12"
                    class="w-full border rounded-xl p-3 mb-3"
                >{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}

よろしくお願いいたします。</textarea>

                <div class="text-xs text-slate-400 mb-4">
                    使用可能な変数：
                    {顧客名} / {アンケートURL}
                </div>

                <button
                    onclick="App.actions.sendMail()"
                    class="w-full bg-blue-600 text-white rounded-xl py-3 font-bold"
                >
                    選択した顧客へ一括送信
                </button>
            </section>
        </div>
    `;

    App.render.customerTable();
};

App.actions.customerFilter = function(value) {
    App.state.filters.customer = value;
    App.render.customerTable();
};

App.render.customerTable = function() {
    const target =
        document.getElementById('customer_table');

    if (!target) return;

    const keyword =
        App.state.filters.customer.toLowerCase();

    const customers =
        (App.state.data.customers || [])
            .filter(c => {
                if (!keyword) return true;

                return (
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
            });

    target.innerHTML = `
        <table class="w-full min-w-[800px]">
            <thead class="bg-slate-50 border-b">
                <tr>
                    <th class="p-3 text-left">
                        <input
                            id="select_all"
                            type="checkbox"
                            onchange="App.actions.toggleAll(this.checked)"
                        >
                    </th>
                    <th class="p-3 text-left">会社名 / 氏名</th>
                    <th class="p-3 text-left">メール</th>
                    <th class="p-3 text-left">送信状況</th>
                    <th class="p-3 text-left">回答</th>
                    <th class="p-3 text-left">kintone</th>
                </tr>
            </thead>

            <tbody>
            ${
                customers.map(
                    c => {
                        const selected =
                            App.state.selectedCustomers
                                .includes(c.id);

                        const disabled =
                            c.source === 'web';

                        return `
                            <tr class="border-b">
                                <td class="p-3">
                                    <input
                                        type="checkbox"
                                        ${selected ? 'checked' : ''}
                                        ${disabled ? 'disabled' : ''}
                                        onchange="App.actions.toggleCustomer('${c.id}',this.checked)"
                                    >
                                </td>

                                <td class="p-3">
                                    <div class="font-bold">
                                        ${App.utils.escape(c.company || '')}
                                    </div>
                                    <div class="text-sm">
                                        ${App.utils.escape(c.name || '')}
                                    </div>
                                </td>

                                <td class="p-3 text-sm">
                                    ${App.utils.escape(c.email || '')}
                                </td>

                                <td class="p-3 text-sm">
                                    ${c.sent_at
                                        ? App.utils.formatDate(c.sent_at)
                                        : '未送信'}
                                    <div class="text-xs text-slate-400">
                                        ${Number(c.send_count || 0)}回
                                    </div>
                                </td>

                                <td class="p-3">
                                    <span class="px-2 py-1 rounded-full text-xs ${c.answer_status === 'answered'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-slate-100 text-slate-600'}">
                                        ${c.answer_status === 'answered'
                                            ? '回答済み'
                                            : '未回答'}
                                    </span>
                                </td>

                                <td class="p-3">
                                    ${
                                        c.kintone_status === 'registered'
                                        ? '<span class="text-emerald-600">✓ 登録完了</span>'
                                        : `
                                            <button
                                                onclick="App.actions.registerCustomer('${c.id}')"
                                                class="text-blue-600"
                                            >
                                                登録完了にする
                                            </button>
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

App.actions.toggleCustomer = function(id, checked) {
    if (checked) {
        if (!App.state.selectedCustomers.includes(id)) {
            App.state.selectedCustomers.push(id);
        }
    } else {
        App.state.selectedCustomers =
            App.state.selectedCustomers.filter(
                x => x !== id
            );
    }
};

App.actions.toggleAll = function(checked) {
    const customers =
        App.state.data.customers || [];

    App.state.selectedCustomers =
        checked
        ? customers
            .filter(c => c.source !== 'web')
            .map(c => c.id)
        : [];

    App.render.customerTable();
};

App.actions.selectAllCustomers = function() {
    App.actions.toggleAll(true);
};

App.actions.clearCustomers = function() {
    App.actions.toggleAll(false);
};

App.actions.templateChanged = function(value) {
    if (value === 'reminder') {
        document.getElementById('mail_subject').value =
            '【再送】アンケートご回答のお願い';

        document.getElementById('mail_body').value =
            '{顧客名} 様\n\n' +
            '先日ご案内したアンケートが未回答となっております。\n\n' +
            '{アンケートURL}\n\n' +
            'ご協力をお願いいたします。';
    }
};

App.actions.sendMail = async function() {
    const ids =
        App.state.selectedCustomers;

    if (!ids.length) {
        alert('送信先を選択してください。');
        return;
    }

    const alreadySent =
        (App.state.data.customers || [])
            .filter(c =>
                ids.includes(c.id) &&
                Number(c.send_count || 0) > 0
            );

    if (
        alreadySent.length &&
        !confirm(
            '既に送信済みの宛先が含まれています。再送しますか？'
        )
    ) {
        return;
    }

    const subject =
        document.getElementById('mail_subject').value;

    const mailBody =
        document.getElementById('mail_body').value;

    const templateType =
        document.getElementById('template_type').value;

    try {
        const result =
            await App.API.request('send_mail', {
                survey_id: App.state.survey.id,
                recipient_ids: ids,
                mail_subject: subject,
                mail_body: mailBody,
                template_type: templateType
            });

        alert(result.message);

        App.state.selectedCustomers = [];

        await App.API.load();
    } catch (error) {
        alert(error.message);
    }
};

App.actions.registerCustomer = async function(id) {
    try {
        await App.API.request(
            'kintone_register',
            {customer_id: id}
        );

        await App.API.load();

        App.actions.mail(App.state.survey.id);
    } catch (error) {
        alert(error.message);
    }
};

/* ================================================================
 * Analysis
 * ================================================================ */

App.actions.analysis = function(surveyId) {
    App.state.survey =
        App.state.data.surveys.find(
            x => x.id === surveyId
        );

    App.state.screen = 'analysis';

    App.render.analysis();
};

App.render.analysis = function() {
    const main = document.getElementById('main');
    const survey = App.state.survey;

    const responses =
        (App.state.data.responses || [])
            .filter(r => r.survey_id === survey.id);

    const customers =
        App.state.data.customers || [];

    const sent =
        customers.filter(
            c => Number(c.send_count || 0) > 0
        ).length;

    const answeredCustomers =
        customers.filter(
            c =>
                Number(c.send_count || 0) > 0 &&
                c.answer_status === 'answered'
        ).length;

    const webResponses =
        responses.filter(
            r =>
                !r.customer_id ||
                !customers.some(
                    c => c.id === r.customer_id
                )
        ).length;

    const unanswered =
        Math.max(0, sent - answeredCustomers);

    const rate =
        sent > 0
            ? ((answeredCustomers / sent) * 100).toFixed(1)
            : '0.0';

    const questions =
        App.utils.questionList(survey);

    if (!Object.keys(
        App.state.selectedQuestions
    ).length) {
        questions.forEach(x => {
            App.state.selectedQuestions[
                x.question.id
            ] = true;
        });
    }

    main.innerHTML = `
        <div class="flex items-center justify-between mb-5">
            <div>
                <div class="text-sm text-slate-400">
                    ホーム ＞ アンケート一覧 ＞ 集計
                </div>

                <h1 class="text-2xl font-bold mt-2">
                    ${App.utils.escape(survey.title)}
                </h1>
            </div>

            <div class="flex gap-2">
                <button
                    onclick="App.actions.exportCsv()"
                    class="px-4 py-2 rounded-xl bg-white border"
                >
                    CSV出力
                </button>

                <button
                    onclick="App.actions.printAnalysis()"
                    class="px-4 py-2 rounded-xl bg-white border"
                >
                    PDF / 印刷
                </button>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
            ${App.render.summaryCard('送信対象者数', sent + ' 人')}
            ${App.render.summaryCard('回答数', responses.length + ' 件')}
            ${App.render.summaryCard('未登録顧客回答', webResponses + ' 件')}
            ${App.render.summaryCard('未回答数', unanswered + ' 人')}
            ${App.render.summaryCard('回答率', rate + ' %')}
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-5">
            <section class="bg-white rounded-2xl border p-4 h-fit">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="font-bold">設問絞り込み</h2>

                    <div class="flex gap-1">
                        <button
                            onclick="App.actions.selectAllQuestions(true)"
                            class="text-xs text-blue-600"
                        >
                            全選択
                        </button>

                        <button
                            onclick="App.actions.selectAllQuestions(false)"
                            class="text-xs text-blue-600"
                        >
                            全解除
                        </button>
                    </div>
                </div>

                <div class="space-y-2">
                    ${
                        questions.map(
                            x => `
                            <label class="flex gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    ${App.state.selectedQuestions[x.question.id] ? 'checked' : ''}
                                    onchange="App.actions.toggleQuestion('${x.question.id}',this.checked)"
                                >
                                <span>
                                    ${App.utils.escape(x.question.text || '')}
                                    <span class="text-xs text-slate-400">
                                        ${App.utils.typeLabel(x.question.type)}
                                    </span>
                                </span>
                            </label>
                        `
                        ).join('')
                    }
                </div>
            </section>

            <section
                id="analysis_content"
                class="lg:col-span-3 space-y-5"
            ></section>
        </div>
    `;

    App.render.analysisContent();
};

App.render.summaryCard = function(label, value) {
    return `
        <div class="bg-white rounded-2xl border p-4">
            <div class="text-xs text-slate-400 mb-2">
                ${label}
            </div>
            <div class="text-xl font-bold">
                ${value}
            </div>
        </div>
    `;
};

App.actions.toggleQuestion = function(id, checked) {
    App.state.selectedQuestions[id] = checked;
    App.render.analysisContent();
};

App.actions.selectAllQuestions = function(value) {
    App.utils.questionList(App.state.survey)
        .forEach(x => {
            App.state.selectedQuestions[
                x.question.id
            ] = value;
        });

    App.render.analysis();
};

App.render.analysisContent = function() {
    const target =
        document.getElementById('analysis_content');

    if (!target) return;

    const survey = App.state.survey;

    const responses =
        App.state.data.responses.filter(
            r => r.survey_id === survey.id
        );

    const questions =
        App.utils.questionList(survey)
            .filter(
                x =>
                    App.state.selectedQuestions[
                        x.question.id
                    ]
            );

    let html = '';

    if (!responses.length) {
        html += `
            <div class="bg-white rounded-2xl border p-12 text-center text-slate-400">
                現在、回答データはありません
            </div>
        `;
    }

    questions.forEach(item => {
        const q = item.question;

        if (q.type === 'text') {
            html += App.render.textAnalysis(
                q,
                responses
            );
        } else {
            html += App.render.choiceAnalysis(
                q,
                responses
            );
        }
    });

    html += `
        <div class="bg-white rounded-2xl border overflow-x-auto">
            <div class="p-4 border-b flex items-center justify-between">
                <h2 class="font-bold">
                    個別回答一覧
                </h2>

                <input
                    id="response_filter"
                    value="${App.utils.escape(App.state.responseFilter)}"
                    oninput="App.actions.responseFilter(this.value)"
                    placeholder="会社名・氏名で検索"
                    class="border rounded-xl px-3 py-2"
                >
            </div>

            <div id="response_table"></div>
        </div>
    `;

    target.innerHTML = html;

    App.render.responseTable(responses);
};

App.render.choiceAnalysis = function(
    question,
    responses
) {
    const counts = {};

    (question.options || []).forEach(
        option => counts[option] = 0
    );

    let otherCount = 0;

    responses.forEach(response => {
        let value =
            response.answers?.[question.id];

        if (!Array.isArray(value)) {
            value = [value];
        }

        value.forEach(answer => {
            if (
                answer &&
                Object.prototype.hasOwnProperty.call(
                    counts,
                    answer
                )
            ) {
                counts[answer]++;
            } else if (answer) {
                otherCount++;
            }
        });
    });

    const total = Math.max(
        1,
        responses.length
    );

    return `
        <div class="bg-white rounded-2xl border p-5">
            <div class="flex justify-between items-start mb-5">
                <div>
                    <h2 class="font-bold">
                        ${App.utils.escape(question.text || '')}
                    </h2>
                    <span class="text-xs text-slate-400">
                        ${App.utils.typeLabel(question.type)}
                    </span>
                </div>

                ${
                    otherCount
                    ? `
                        <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700 text-xs">
                            その他 ${otherCount} 件
                        </span>
                    `
                    : ''
                }
            </div>

            <div class="space-y-4">
            ${
                Object.entries(counts).map(
                    ([option, count]) => {
                        const percent =
                            ((count / total) * 100).toFixed(1);

                        return `
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span>
                                        ${App.utils.escape(option)}
                                    </span>
                                    <span>
                                        ${count} 件 / ${percent}%
                                    </span>
                                </div>

                                <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                    <div
                                        class="h-full bg-blue-500 rounded-full"
                                        style="width:${percent}%"
                                    ></div>
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

App.render.textAnalysis = function(
    question,
    responses
) {
    const items = responses
        .map(r => ({
            r,
            value: r.answers?.[question.id] || ''
        }))
        .filter(x => x.value);

    return `
        <div class="bg-white rounded-2xl border p-5">
            <h2 class="font-bold mb-4">
                ${App.utils.escape(question.text || '')}
            </h2>

            <div class="space-y-3 max-h-[400px] overflow-auto">
                ${
                    items.length
                    ? items.map(
                        x => `
                            <div class="border-l-4 border-blue-400 pl-4 py-2">
                                <div class="text-sm font-bold">
                                    ${App.utils.escape(x.r.company || '')}
                                    /
                                    ${App.utils.escape(x.r.name || '')}
                                </div>
                                <div class="text-xs text-slate-400">
                                    ${App.utils.escape(x.r.answered_at || '')}
                                </div>
                                <div class="mt-2 whitespace-pre-wrap">
                                    ${App.utils.escape(String(x.value))}
                                </div>
                            </div>
                        `
                    ).join('')
                    : '<div class="text-slate-400">回答なし</div>'
                }
            </div>
        </div>
    `;
};

App.actions.responseFilter = function(value) {
    App.state.responseFilter = value;

    const responses =
        App.state.data.responses.filter(
            r => r.survey_id === App.state.survey.id
        );

    App.render.responseTable(responses);
};

App.render.responseTable = function(responses) {
    const target =
        document.getElementById('response_table');

    if (!target) return;

    const keyword =
        App.state.responseFilter.toLowerCase();

    const filtered =
        responses.filter(r =>
            !keyword ||
            String(r.company || '')
                .toLowerCase()
                .includes(keyword) ||
            String(r.name || '')
                .toLowerCase()
                .includes(keyword)
        );

    target.innerHTML = `
        <table class="w-full min-w-[800px]">
            <thead class="bg-slate-50">
                <tr>
                    <th class="p-3 text-left">会社名</th>
                    <th class="p-3 text-left">氏名</th>
                    <th class="p-3 text-left">回答日時</th>
                    <th class="p-3 text-left">操作</th>
                </tr>
            </thead>

            <tbody>
            ${
                filtered.map(
                    r => `
                    <tr class="border-t">
                        <td class="p-3">
                            ${App.utils.escape(r.company || '')}
                        </td>
                        <td class="p-3">
                            ${App.utils.escape(r.name || '')}
                        </td>
                        <td class="p-3 text-sm">
                            ${App.utils.escape(r.answered_at || '')}
                        </td>
                        <td class="p-3">
                            <button
                                onclick="App.actions.showResponse('${r.id}')"
                                class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700"
                            >
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

App.actions.showResponse = function(id) {
    const response =
        App.state.data.responses.find(
            r => r.id === id
        );

    if (!response) return;

    const questions =
        App.utils.questionList(App.state.survey);

    document.getElementById('response_modal').innerHTML = `
        <div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-5">
            <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-auto">
                <div class="sticky top-0 bg-white border-b p-4 flex justify-between">
                    <div class="font-bold">
                        回答詳細
                    </div>

                    <button
                        onclick="App.actions.closeResponse()"
                        class="px-3 py-1 rounded-lg bg-slate-200"
                    >
                        閉じる
                    </button>
                </div>

                <div
                    id="response_detail"
                    class="p-6 space-y-5"
                >
                    <div class="grid grid-cols-2 gap-3 bg-slate-50 rounded-xl p-4">
                        <div>
                            <div class="text-xs text-slate-400">
                                会社名
                            </div>
                            <div class="font-bold">
                                ${App.utils.escape(response.company || '')}
                            </div>
                        </div>

                        <div>
                            <div class="text-xs text-slate-400">
                                氏名
                            </div>
                            <div class="font-bold">
                                ${App.utils.escape(response.name || '')}
                            </div>
                        </div>

                        <div>
                            <div class="text-xs text-slate-400">
                                メール
                            </div>
                            <div>
                                ${App.utils.escape(response.email || '')}
                            </div>
                        </div>

                        <div>
                            <div class="text-xs text-slate-400">
                                回答日時
                            </div>
                            <div>
                                ${App.utils.escape(response.answered_at || '')}
                            </div>
                        </div>
                    </div>

                    ${
                        questions.map(
                            x => `
                                <div class="border-b pb-4">
                                    <div class="font-bold mb-2">
                                        ${App.utils.escape(x.question.text || '')}
                                    </div>

                                    <div class="whitespace-pre-wrap text-slate-600">
                                        ${App.utils.escape(
                                            Array.isArray(
                                                response.answers?.[x.question.id]
                                            )
                                            ? response.answers[x.question.id].join(', ')
                                            : String(
                                                response.answers?.[x.question.id] ?? ''
                                            )
                                        )}
                                    </div>
                                </div>
                            `
                        ).join('')
                    }
                </div>
            </div>
        </div>
    `;
};

App.actions.closeResponse = function() {
    document.getElementById('response_modal').innerHTML = '';
};

App.actions.exportCsv = function() {
    location.href =
        '?action=csv&survey_id=' +
        encodeURIComponent(App.state.survey.id);
};

App.actions.printAnalysis = function() {
    window.print();
};

/* ================================================================
 * Settings
 * ================================================================ */

App.render.settings = function() {
    const main =
        document.getElementById('main');

    const settings =
        App.state.data.settings || {};

    main.innerHTML = `
        <div class="mb-5">
            <div class="text-sm text-slate-400">
                ホーム ＞ システム設定 ＞ kintone連携設定
            </div>

            <h1 class="text-2xl font-bold mt-2">
                kintone連携設定
            </h1>
        </div>

        <div class="max-w-4xl bg-white rounded-2xl border p-6">
            <form
                id="settings_form"
                onsubmit="return App.actions.saveSettings(event)"
                class="space-y-5"
            >
                <div>
                    <label class="block font-semibold mb-1">
                        サブドメイン / FQDN
                    </label>

                    <input
                        id="setting_subdomain"
                        value="${App.utils.escape(settings.subdomain || '')}"
                        placeholder="xxxx または xxxx.cybozu.com"
                        class="w-full border rounded-xl px-3 py-2"
                    >

                    <p class="text-xs text-slate-400 mt-1">
                        xxxx / xxxx.cybozu.com / https://xxxx.cybozu.com のいずれも可
                    </p>
                </div>

                <div>
                    <label class="block font-semibold mb-1">
                        アプリID
                    </label>

                    <input
                        id="setting_app_id"
                        value="${App.utils.escape(settings.app_id || '')}"
                        class="w-full border rounded-xl px-3 py-2"
                    >
                </div>

                <div>
                    <label class="block font-semibold mb-1">
                        ログイン名
                    </label>

                    <input
                        id="setting_login_name"
                        autocomplete="username"
                        value="${App.utils.escape(settings.login_name || '')}"
                        class="w-full border rounded-xl px-3 py-2"
                    >
                </div>

                <div>
                    <label class="block font-semibold mb-1">
                        パスワード
                    </label>

                    <input
                        id="setting_password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="変更しない場合は空欄"
                        class="w-full border rounded-xl px-3 py-2"
                    >

                    <p class="text-xs text-slate-400 mt-1">
                        前後空白も含めて入力した値をそのまま使用します。
                    </p>
                </div>

                <div>
                    <label class="block font-semibold mb-1">
                        Proxy
                    </label>

                    <input
                        id="setting_proxy"
                        value="${App.utils.escape(settings.proxy || '')}"
                        placeholder="proxy.example.local:8080"
                        class="w-full border rounded-xl px-3 py-2"
                    >
                </div>

                <label class="flex items-center gap-2">
                    <input
                        id="setting_ssl_verify"
                        type="checkbox"
                        ${settings.ssl_verify ? 'checked' : ''}
                    >
                    SSL証明書を検証する
                </label>

                <div class="border-t pt-5">
                    <div class="flex justify-between items-center mb-3">
                        <div>
                            <h2 class="font-bold">
                                kintoneフィールドマッピング
                            </h2>

                            <p class="text-xs text-slate-400">
                                日本語フィールド名から選択できます。
                            </p>
                        </div>

                        <button
                            type="button"
                            onclick="App.actions.fetchKintoneFields()"
                            class="px-4 py-2 rounded-xl bg-blue-600 text-white"
                        >
                            項目一覧を再取得
                        </button>
                    </div>

                    <div
                        id="field_message"
                        class="mb-3 text-sm"
                    ></div>

                    <div
                        id="field_mapping"
                        class="space-y-3"
                    >
                        ${App.render.fieldMapping(settings)}
                    </div>
                </div>

                <div class="flex gap-3 pt-3">
                    <button
                        type="submit"
                        class="px-5 py-3 rounded-xl bg-blue-600 text-white font-bold"
                    >
                        設定を保存
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.testKintone()"
                        class="px-5 py-3 rounded-xl border"
                    >
                        接続テスト
                    </button>

                    <button
                        type="button"
                        onclick="App.actions.syncCustomers()"
                        class="px-5 py-3 rounded-xl border"
                    >
                        顧客を手動同期
                    </button>
                </div>

                <input
                    type="hidden"
                    id="settings_json"
                >
            </form>
        </div>
    `;
};

App.render.fieldMapping = function(settings) {
    const fields = App.state.fields || [];

    const makeSelect = function(
        id,
        value,
        multiple = false
    ) {
        return `
            <select
                id="${id}"
                ${multiple ? 'multiple size="4"' : ''}
                class="w-full border rounded-xl px-3 py-2"
            >
                ${
                    !multiple
                    ? '<option value="">-- 選択してください --</option>'
                    : ''
                }

                ${
                    fields.map(
                        field => `
                            <option
                                value="${App.utils.escape(field.code)}"
                                ${
                                    multiple
                                    ? (
                                        Array.isArray(value) &&
                                        value.includes(field.code)
                                    )
                                        ? 'selected'
                                        : ''
                                    : value === field.code
                                        ? 'selected'
                                        : ''
                                }
                            >
                                ${App.utils.escape(field.label)}
                                [${App.utils.escape(field.code)}]
                                (${App.utils.escape(field.type)})
                            </option>
                        `
                    ).join('')
                }
            </select>
        `;
    };

    return `
        <div>
            <label class="block text-sm font-semibold mb-1">
                会社名 (Company)
            </label>
            ${makeSelect(
                'field_company',
                settings.field_company || ''
            )}
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1">
                氏名 (Name)
            </label>
            ${makeSelect(
                'field_name',
                settings.field_name || ''
            )}
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1">
                メールアドレス (Email)
            </label>
            ${makeSelect(
                'field_email',
                settings.field_email || ''
            )}
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1">
                部署名 (Department)
            </label>
            ${makeSelect(
                'field_department',
                settings.field_department || ''
            )}
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1">
                電話番号 (Phone)
            </label>
            ${makeSelect(
                'field_phone',
                settings.field_phone || ''
            )}
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1">
                住所 (Address)
            </label>
            ${makeSelect(
                'field_address',
                settings.field_address || [],
                true
            )}
            <p class="text-xs text-slate-400 mt-1">
                Ctrl / Commandを押しながら複数選択できます。
            </p>
        </div>
    `;
};

App.actions.readSettings = function() {
    const old =
        App.state.data.settings || {};

    const addressElement =
        document.getElementById('field_address');

    const address =
        addressElement
            ? Array.from(addressElement.selectedOptions)
                .map(o => o.value)
            : [];

    return {
        subdomain:
            document.getElementById(
                'setting_subdomain'
            )?.value || '',

        login_name:
            document.getElementById(
                'setting_login_name'
            )?.value || '',

        /*
         * 空欄なら既存パスワードを維持する。
         * 入力された場合はtrimしない。
         */
        password:
            document.getElementById(
                'setting_password'
            )?.value || old.password || '',

        app_id:
            document.getElementById(
                'setting_app_id'
            )?.value || '',

        proxy:
            document.getElementById(
                'setting_proxy'
            )?.value || '',

        ssl_verify:
            !!document.getElementById(
                'setting_ssl_verify'
            )?.checked,

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

        field_address: address
    };
};

App.actions.saveSettings = async function(event) {
    event.preventDefault();

    const settings =
        App.actions.readSettings();

    try {
        await App.API.request(
            'save_settings',
            {
                settings_json: settings
            }
        );

        App.state.data.settings = settings;

        alert('設定を保存しました。');
    } catch (error) {
        alert(error.message);
    }

    return false;
};

/*
 * 必須関数:
 * fetchKintoneFields()
 */
App.actions.fetchKintoneFields = async function() {
    const settings =
        App.actions.readSettings();

    const message =
        document.getElementById('field_message');

    if (message) {
        message.className =
            'mb-3 text-sm text-blue-600';
        message.textContent =
            'kintoneから項目一覧を取得しています…';
    }

    try {
        /*
         * 先に設定を保存する。
         * これにより「項目一覧取得」時にも
         * 実際に入力されている認証情報を使用する。
         */
        await App.API.request(
            'save_settings',
            {
                settings_json: settings
            }
        );

        const result =
            await App.API.request(
                'kintone_fields',
                {
                    settings_json: settings
                }
            );

        App.state.fields =
            Array.isArray(result.fields)
                ? result.fields
                : [];

        App.render.settings();

        const msg =
            document.getElementById('field_message');

        if (msg) {
            msg.className =
                'mb-3 text-sm text-emerald-600';
            msg.textContent =
                App.state.fields.length +
                '件のフィールドを取得しました。';
        }
    } catch (error) {
        const result = error.result || {};

        let text = error.message;

        if (result.status) {
            text +=
                '\nHTTPステータス: ' +
                result.status;
        }

        if (result.error_code) {
            text +=
                '\nエラーコード: ' +
                result.error_code;
        }

        if (result.endpoint) {
            text +=
                '\n接続先: ' +
                result.endpoint;
        }

        if (
            result.status === 401 &&
            result.error_code === 'CB_WA01'
        ) {
            text +=
                '\n\n確認事項:' +
                '\n・ログイン名' +
                '\n・パスワード' +
                '\n・2要素認証' +
                '\n・SAML認証' +
                '\n・IPアドレス制限';
        }

        if (message) {
            message.className =
                'mb-3 text-sm text-red-600 whitespace-pre-wrap';
            message.textContent = text;
        }

        alert(text);
    }
};

App.actions.testKintone = async function() {
    const settings =
        App.actions.readSettings();

    try {
        await App.API.request(
            'save_settings',
            {
                settings_json: settings
            }
        );

        const result =
            await App.API.request(
                'kintone_test'
            );

        alert(result.message);
    } catch (error) {
        const result = error.result || {};

        let text = error.message;

        if (result.status) {
            text +=
                '\nHTTPステータス: ' +
                result.status;
        }

        if (result.error_code) {
            text +=
                '\nエラーコード: ' +
                result.error_code;
        }

        if (result.endpoint) {
            text +=
                '\n接続先: ' +
                result.endpoint;
        }

        alert(text);
    }
};

App.actions.syncCustomers = async function() {
    if (!confirm(
        'kintoneから顧客データを手動同期しますか？'
    )) return;

    try {
        const settings =
            App.actions.readSettings();

        await App.API.request(
            'save_settings',
            {
                settings_json: settings
            }
        );

        const result =
            await App.API.request(
                'sync_customers'
            );

        alert(result.message);

        await App.API.load();
    } catch (error) {
        alert(error.message);
    }
};

/* ================================================================
 * 初期化
 * ================================================================ */

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => App.init(),
        {once: true}
    );
} else {
    App.init();
}
</script>

</body>
</html>
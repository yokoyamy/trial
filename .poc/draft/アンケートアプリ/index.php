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

    $defaults = survey_default_data();

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    $data['settings'] = array_merge(
        $defaults['settings'],
        is_array($data['settings'] ?? null)
            ? $data['settings']
            : []
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
        @copy(
            SURVEY_STORAGE_FILE,
            SURVEY_STORAGE_FILE . '.bak'
        );
    }

    return @rename($tmp, SURVEY_STORAGE_FILE);
}

function survey_json_response(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);

    header(
        'Content-Type: application/json; charset=UTF-8'
    );

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
        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function survey_check_csrf(): void
{
    $token = (string)(
        $_POST['csrf_token'] ?? ''
    );

    if (!hash_equals(
        survey_csrf(),
        $token
    )) {
        survey_json_response([
            'ok' => false,
            'message' => 'CSRFトークンが不正です。'
        ], 403);
    }
}

function survey_string(mixed $value): string
{
    return is_scalar($value)
        ? (string)$value
        : '';
}

function survey_normalize_question(mixed $question): array
{
    $q = is_array($question)
        ? $question
        : [];

    $q['id'] = survey_string(
        $q['id'] ?? survey_id('question')
    );

    $q['text'] = survey_string(
        $q['text'] ?? ''
    );

    $type = survey_string(
        $q['type'] ?? 'single'
    );

    if (!in_array(
        $type,
        ['single', 'multiple', 'text'],
        true
    )) {
        $type = 'single';
    }

    $q['type'] = $type;
    $q['required'] = !empty($q['required']);
    $q['other_enabled'] = !empty(
        $q['other_enabled']
    );

    $options = $q['options'] ?? [];

    if (!is_array($options)) {
        $options = [];
    }

    $q['options'] = array_values(
        array_map(
            static fn(mixed $v): string =>
                survey_string($v),
            $options
        )
    );

    $branching = $q['branching'] ?? [];

    if (!is_array($branching)) {
        $branching = [];
    }

    $normalized = [];

    foreach ($branching as $item) {
        if (!is_array($item)) {
            continue;
        }

        $normalized[] = [
            'option' => survey_string(
                $item['option'] ?? ''
            ),
            'target_question_id' => survey_string(
                $item['target_question_id'] ?? ''
            )
        ];
    }

    if ($type !== 'single') {
        $normalized = [];
    }

    $q['branching'] = $normalized;

    return $q;
}

function survey_normalize_survey(
    mixed $survey
): array {
    $s = is_array($survey)
        ? $survey
        : [];

    $s['id'] = survey_string(
        $s['id'] ?? survey_id('survey')
    );

    $s['title'] = survey_string(
        $s['title'] ?? '新しいアンケート'
    );

    $s['start_at'] = survey_string(
        $s['start_at'] ?? ''
    );

    $s['end_at'] = survey_string(
        $s['end_at'] ?? ''
    );

    $status = survey_string(
        $s['status'] ?? 'draft'
    );

    if (!in_array(
        $status,
        ['draft', 'active', 'ended'],
        true
    )) {
        $status = 'draft';
    }

    $s['status'] = $status;

    $mode = survey_string(
        $s['numbering_mode'] ?? 'global'
    );

    if (!in_array(
        $mode,
        ['global', 'group'],
        true
    )) {
        $mode = 'global';
    }

    $s['numbering_mode'] = $mode;

    $s['created_at'] = survey_string(
        $s['created_at'] ?? survey_now()
    );

    $s['updated_at'] = survey_string(
        $s['updated_at'] ?? survey_now()
    );

    $s['deleted'] = !empty($s['deleted']);

    $groups = $s['groups'] ?? [];

    if (!is_array($groups)) {
        $groups = [];
    }

    $resultGroups = [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $questions = $group['questions'] ?? [];

        if (!is_array($questions)) {
            $questions = [];
        }

        $resultQuestions = [];

        foreach ($questions as $question) {
            $resultQuestions[] =
                survey_normalize_question(
                    $question
                );
        }

        $resultGroups[] = [
            'id' => survey_string(
                $group['id'] ?? survey_id('group')
            ),
            'name' => survey_string(
                $group['name'] ?? 'グループ'
            ),
            'questions' => $resultQuestions
        ];
    }

    $s['groups'] = $resultGroups;

    return $s;
}

/* ================================================================
 * kintone communication
 * ================================================================ */

function survey_normalize_subdomain(
    string $value
): string {
    $value = trim($value);

    $value = preg_replace(
        '#^https?://#i',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#/.*$#',
        '',
        $value
    ) ?? $value;

    $value = preg_replace(
        '#\.cybozu\.com$#i',
        '',
        $value
    ) ?? $value;

    return trim($value);
}

function survey_kintone_endpoint(
    string $subdomain,
    string $path
): string {
    $subdomain =
        survey_normalize_subdomain(
            $subdomain
        );

    return 'https://' .
        $subdomain .
        '.cybozu.com' .
        $path;
}

function survey_parse_proxy(
    string $proxy
): ?array {
    $proxy = trim($proxy);

    if ($proxy === '') {
        return null;
    }

    if (!preg_match(
        '/^([^:\/\s]+):(\d+)$/',
        $proxy,
        $m
    )) {
        return [
            'error' =>
                'Proxyは「host名:port番号」形式で指定してください。'
        ];
    }

    return [
        'host' => $m[1],
        'port' => (int)$m[2]
    ];
}

function survey_http_request(
    string $url,
    string $method,
    array $headers,
    ?string $body,
    bool $sslVerify,
    string $proxy
): array {
    $method = strtoupper($method);

    $proxyInfo = survey_parse_proxy($proxy);

    if (is_array($proxyInfo) &&
        isset($proxyInfo['error'])) {
        return [
            'ok' => false,
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' => $proxyInfo['error'],
            'endpoint' => $url,
            'body' => '',
            'raw' => '',
            'transport_error' => '',
            'headers' => []
        ];
    }

    $headerString = implode(
        "\r\n",
        $headers
    );

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => $headerString,
            'ignore_errors' => true,
            'timeout' => 30,
            'protocol_version' => 1.1
        ],
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
            'allow_self_signed' => !$sslVerify
        ]
    ];

    if ($body !== null) {
        $contextOptions['http']['content'] =
            $body;
    }

    if ($proxyInfo !== null) {
        $contextOptions['http']['proxy'] =
            'tcp://' .
            $proxyInfo['host'] .
            ':' .
            $proxyInfo['port'];

        $contextOptions['http']['request_fulluri'] =
            true;
    }

    $context = stream_context_create(
        $contextOptions
    );

    $transportError = '';

    set_error_handler(
        static function (
            int $severity,
            string $message
        ) use (&$transportError): bool {
            $transportError = $message;
            return true;
        }
    );

    $raw = @file_get_contents(
        $url,
        false,
        $context
    );

    restore_error_handler();

    $headersOut = [];

    /*
     * PHP 8.4/8.5以降を考慮し、
     * get_headers() に依存せず
     * http_get_last_response_headers() を使用。
     */
    if (function_exists(
        'http_get_last_response_headers'
    )) {
        $lastHeaders =
            http_get_last_response_headers();

        if (is_array($lastHeaders)) {
            $headersOut = $lastHeaders;
        }
    }

    $status = 0;

    foreach ($headersOut as $header) {
        if (preg_match(
            '/^HTTP\/\S+\s+(\d+)/i',
            (string)$header,
            $m
        )) {
            $status = (int)$m[1];
        }
    }

    if ($status === 0 &&
        isset($http_response_header) &&
        is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match(
                '/^HTTP\/\S+\s+(\d+)/i',
                (string)$header,
                $m
            )) {
                $status = (int)$m[1];
            }
        }
    }

    $rawBody = is_string($raw)
        ? $raw
        : '';

    $json = json_decode(
        $rawBody,
        true
    );

    if (!is_array($json)) {
        $json = [];
    }

    $code = survey_string(
        $json['code'] ?? ''
    );

    $id = survey_string(
        $json['id'] ?? ''
    );

    $message = survey_string(
        $json['message'] ?? ''
    );

    if ($message === '' &&
        $transportError !== '') {
        $message = $transportError;
    }

    if ($message === '' &&
        $status >= 400) {
        $message =
            'kintoneからHTTP ' .
            $status .
            'が返されました。';
    }

    if ($message === '' &&
        $status === 0 &&
        $rawBody === '') {
        $message =
            'kintoneへの通信に失敗しました。';
    }

    return [
        'ok' =>
            $status >= 200 &&
            $status < 300 &&
            $rawBody !== '',
        'status' => $status,
        'code' => $code,
        'id' => $id,
        'message' => $message,
        'endpoint' => $url,
        'body' => $rawBody,
        'raw' => $rawBody,
        'transport_error' =>
            $transportError,
        'headers' => $headersOut,
        'json' => $json
    ];
}

function survey_kintone_request(
    array $settings,
    string $method,
    string $path,
    array $params = []
): array {
    $subdomain = survey_string(
        $settings['subdomain'] ?? ''
    );

    $login = survey_string(
        $settings['login_name'] ?? ''
    );

    $password = survey_string(
        $settings['password'] ?? ''
    );

    if ($subdomain === '') {
        return [
            'ok' => false,
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' =>
                'サブドメインが未入力です。',
            'endpoint' => '',
            'body' => '',
            'raw' => '',
            'transport_error' => '',
            'headers' => []
        ];
    }

    if ($login === '' ||
        $password === '') {
        return [
            'ok' => false,
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' =>
                'ログイン名またはパスワードが未入力です。',
            'endpoint' => '',
            'body' => '',
            'raw' => '',
            'transport_error' => '',
            'headers' => []
        ];
    }

    $appId = survey_string(
        $params['app'] ??
        $settings['app_id'] ??
        ''
    );

    if ($appId === '') {
        return [
            'ok' => false,
            'status' => 0,
            'code' => '',
            'id' => '',
            'message' =>
                'アプリIDが未入力です。',
            'endpoint' => '',
            'body' => '',
            'raw' => '',
            'transport_error' => '',
            'headers' => []
        ];
    }

    unset($params['app']);

    $params['app'] = $appId;

    $query = http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $endpoint =
        survey_kintone_endpoint(
            $subdomain,
            $path
        );

    if ($query !== '') {
        $endpoint .= '?' . $query;
    }

    $credential =
        base64_encode(
            $login . ':' . $password
        );

    $headers = [
        'X-Cybozu-Authorization: ' .
            $credential,
        'Accept: application/json',
        'Accept-Language: ja',
        'User-Agent: SurveyAdmin/1.0'
    ];

    $sslVerify =
        !empty($settings['ssl_verify']);

    $proxy = survey_string(
        $settings['proxy'] ?? ''
    );

    return survey_http_request(
        $endpoint,
        $method,
        $headers,
        null,
        $sslVerify,
        $proxy
    );
}

/* ================================================================
 * API endpoints
 * ================================================================ */

$action = survey_string(
    $_GET['action'] ??
    $_POST['action'] ??
    ''
);

if ($action !== '') {

    $data = survey_load_data();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        survey_check_csrf();
    }

    switch ($action) {

        case 'bootstrap':
            survey_json_response([
                'ok' => true,
                'data' => $data,
                'csrf_token' => survey_csrf()
            ]);
            break;

        case 'save_survey':
            $raw = (string)(
                $_POST['survey_json'] ?? ''
            );

            $survey = json_decode(
                $raw,
                true
            );

            if (!is_array($survey)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アンケートデータが不正です。'
                ], 400);
            }

            $survey =
                survey_normalize_survey(
                    $survey
                );

            $found = false;

            foreach ($data['surveys'] as $index => $old) {
                if (
                    is_array($old) &&
                    ($old['id'] ?? '') ===
                    $survey['id']
                ) {
                    $survey['created_at'] =
                        survey_string(
                            $old['created_at'] ??
                            $survey['created_at']
                        );

                    $data['surveys'][$index] =
                        $survey;

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $data['surveys'][] = $survey;
            }

            if (!survey_save_data($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'JSONファイルの保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true,
                'data' => $survey
            ]);
            break;

        case 'delete_survey':
            $id = survey_string(
                $_POST['survey_id'] ?? ''
            );

            foreach ($data['surveys'] as &$survey) {
                if (
                    is_array($survey) &&
                    ($survey['id'] ?? '') === $id
                ) {
                    $survey['deleted'] = true;
                    $survey['updated_at'] =
                        survey_now();
                }
            }
            unset($survey);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'duplicate_survey':
            $id = survey_string(
                $_POST['survey_id'] ?? ''
            );

            $source = null;

            foreach ($data['surveys'] as $survey) {
                if (
                    is_array($survey) &&
                    ($survey['id'] ?? '') === $id
                ) {
                    $source = $survey;
                    break;
                }
            }

            if ($source === null) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '複製元アンケートがありません。'
                ], 404);
            }

            $source['id'] =
                survey_id('survey');

            $source['title'] =
                $source['title'] .
                '（コピー）';

            $source['status'] = 'draft';
            $source['deleted'] = false;
            $source['created_at'] =
                survey_now();
            $source['updated_at'] =
                survey_now();

            $data['surveys'][] =
                survey_normalize_survey(
                    $source
                );

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'data' => $source
            ]);
            break;

        case 'set_status':
            $id = survey_string(
                $_POST['survey_id'] ?? ''
            );

            $status = survey_string(
                $_POST['status'] ?? ''
            );

            if (!in_array(
                $status,
                ['draft', 'active', 'ended'],
                true
            )) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'ステータスが不正です。'
                ], 400);
            }

            foreach ($data['surveys'] as &$survey) {
                if (
                    is_array($survey) &&
                    ($survey['id'] ?? '') === $id
                ) {
                    $survey['status'] = $status;
                    $survey['updated_at'] =
                        survey_now();
                }
            }
            unset($survey);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'save_settings':
            $raw = (string)(
                $_POST['settings_json'] ?? ''
            );

            $settings = json_decode(
                $raw,
                true
            );

            if (!is_array($settings)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '設定データが不正です。'
                ], 400);
            }

            $settings['subdomain'] =
                survey_normalize_subdomain(
                    survey_string(
                        $settings['subdomain'] ?? ''
                    )
                );

            $settings['field_address'] =
                is_array(
                    $settings['field_address'] ??
                    null
                )
                    ? array_values(
                        $settings['field_address']
                    )
                    : [];

            $data['settings'] =
                array_merge(
                    $data['settings'],
                    $settings
                );

            if (!survey_save_data($data)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        '設定の保存に失敗しました。'
                ], 500);
            }

            survey_json_response([
                'ok' => true,
                'data' => $data['settings']
            ]);
            break;

        case 'kintone_fields':

            $settings = $data['settings'];

            if (
                isset($_POST['settings_json']) &&
                $_POST['settings_json'] !== ''
            ) {
                $temporary =
                    json_decode(
                        (string)$_POST['settings_json'],
                        true
                    );

                if (is_array($temporary)) {
                    $settings =
                        array_merge(
                            $settings,
                            $temporary
                        );
                }
            }

            $appId = survey_string(
                $_POST['app_id'] ??
                $settings['app_id'] ??
                ''
            );

            if ($appId === '' ||
                !ctype_digit($appId)) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        'アプリIDには数値を指定してください。',
                    'error_code' =>
                        'INVALID_APP_ID',
                    'endpoint' => '',
                    'status' => 0
                ], 400);
            }

            $result =
                survey_kintone_request(
                    $settings,
                    'GET',
                    '/k/v1/app/form/fields.json',
                    [
                        'app' => $appId,
                        'lang' => 'ja'
                    ]
                );

            $json =
                is_array($result['json'] ?? null)
                    ? $result['json']
                    : [];

            if (!$result['ok']) {
                survey_json_response([
                    'ok' => false,
                    'message' =>
                        $result['message'],
                    'error_code' =>
                        $result['code'],
                    'error_id' =>
                        $result['id'],
                    'status' =>
                        $result['status'],
                    'endpoint' =>
                        $result['endpoint'],
                    'response_body' =>
                        $result['body'],
                    'transport_error' =>
                        $result['transport_error'],
                    'fields' => []
                ], 200);
            }

            $properties =
                is_array(
                    $json['properties'] ??
                    null
                )
                    ? $json['properties']
                    : [];

            $fields = [];

            foreach ($properties as $code => $property) {
                if (!is_array($property)) {
                    continue;
                }

                $fields[] = [
                    'code' => (string)$code,
                    'label' => survey_string(
                        $property['label'] ??
                        $code
                    ),
                    'type' => survey_string(
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
                    return strcmp(
                        $a['label'],
                        $b['label']
                    );
                }
            );

            survey_json_response([
                'ok' => true,
                'status' =>
                    $result['status'],
                'endpoint' =>
                    $result['endpoint'],
                'fields' => $fields
            ]);
            break;

        case 'kintone_test':

            $settings = $data['settings'];

            if (
                isset($_POST['settings_json']) &&
                $_POST['settings_json'] !== ''
            ) {
                $temporary =
                    json_decode(
                        (string)$_POST['settings_json'],
                        true
                    );

                if (is_array($temporary)) {
                    $settings =
                        array_merge(
                            $settings,
                            $temporary
                        );
                }
            }

            $appId = survey_string(
                $settings['app_id'] ?? ''
            );

            $result =
                survey_kintone_request(
                    $settings,
                    'GET',
                    '/k/v1/app/form/fields.json',
                    [
                        'app' => $appId,
                        'lang' => 'ja'
                    ]
                );

            survey_json_response([
                'ok' =>
                    (bool)$result['ok'],
                'status' =>
                    $result['status'],
                'error_code' =>
                    $result['code'],
                'error_id' =>
                    $result['id'],
                'message' =>
                    $result['message'],
                'endpoint' =>
                    $result['endpoint'],
                'response_body' =>
                    $result['body'],
                'transport_error' =>
                    $result['transport_error']
            ]);
            break;

        case 'mark_kintone_registered':
            $customerId =
                survey_string(
                    $_POST['customer_id'] ?? ''
                );

            foreach (
                $data['customers']
                as &$customer
            ) {
                if (
                    is_array($customer) &&
                    ($customer['id'] ?? '') ===
                    $customerId
                ) {
                    $customer['kintone_status'] =
                        'registered';
                }
            }

            unset($customer);

            survey_save_data($data);

            survey_json_response([
                'ok' => true
            ]);
            break;

        case 'save_response':

            $surveyId =
                survey_string(
                    $_POST['survey_id'] ?? ''
                );

            $customerId =
                survey_string(
                    $_POST['customer_id'] ?? ''
                );

            $answers =
                json_decode(
                    (string)(
                        $_POST['answers'] ?? '{}'
                    ),
                    true
                );

            if (!is_array($answers)) {
                $answers = [];
            }

            $customer = null;

            foreach (
                $data['customers']
                as $candidate
            ) {
                if (
                    is_array($candidate) &&
                    ($candidate['id'] ?? '') ===
                    $customerId
                ) {
                    $customer = $candidate;
                    break;
                }
            }

            $response = [
                'id' =>
                    survey_id('response'),
                'survey_id' =>
                    $surveyId,
                'customer_id' =>
                    $customerId,
                'company' =>
                    survey_string(
                        $customer['company'] ??
                        $_POST['company'] ??
                        ''
                    ),
                'name' =>
                    survey_string(
                        $customer['name'] ??
                        $_POST['name'] ??
                        ''
                    ),
                'email' =>
                    survey_string(
                        $customer['email'] ??
                        $_POST['email'] ??
                        ''
                    ),
                'answered_at' =>
                    survey_now(),
                'answers' =>
                    $answers
            ];

            $data['responses'][] =
                $response;

            foreach (
                $data['customers']
                as &$customerItem
            ) {
                if (
                    is_array($customerItem) &&
                    ($customerItem['id'] ?? '') ===
                    $customerId
                ) {
                    $customerItem['answer_status'] =
                        'answered';
                }
            }

            unset($customerItem);

            survey_save_data($data);

            survey_json_response([
                'ok' => true,
                'data' => $response
            ]);
            break;

        case 'csv':
            $surveyId =
                survey_string(
                    $_GET['survey_id'] ?? ''
                );

            $survey = null;

            foreach (
                $data['surveys']
                as $candidate
            ) {
                if (
                    is_array($candidate) &&
                    ($candidate['id'] ?? '') ===
                    $surveyId
                ) {
                    $survey = $candidate;
                    break;
                }
            }

            $questions = [];

            if (is_array($survey)) {
                foreach (
                    $survey['groups'] ?? []
                    as $group
                ) {
                    foreach (
                        $group['questions'] ?? []
                        as $question
                    ) {
                        $questions[] =
                            $question;
                    }
                }
            }

            $fp = fopen('php://output', 'wb');

            if ($fp === false) {
                exit;
            }

            header(
                'Content-Type: text/csv; charset=UTF-8'
            );

            header(
                'Content-Disposition: attachment; filename="survey_' .
                rawurlencode($surveyId) .
                '.csv"'
            );

            fwrite(
                $fp,
                "\xEF\xBB\xBF"
            );

            $header = [
                '回答ID',
                '回答日時',
                '顧客ID',
                '会社名',
                '氏名'
            ];

            foreach (
                $questions as $index => $question
            ) {
                $header[] =
                    '設問' . ($index + 1);
            }

            fputcsv(
                $fp,
                $header
            );

            foreach (
                $data['responses']
                as $response
            ) {
                if (
                    !is_array($response) ||
                    ($response['survey_id'] ?? '') !==
                    $surveyId
                ) {
                    continue;
                }

                $row = [
                    survey_string(
                        $response['id'] ?? ''
                    ),
                    survey_string(
                        $response['answered_at'] ?? ''
                    ),
                    survey_string(
                        $response['customer_id'] ?? ''
                    ),
                    survey_string(
                        $response['company'] ?? ''
                    ),
                    survey_string(
                        $response['name'] ?? ''
                    )
                ];

                $answers =
                    is_array(
                        $response['answers'] ?? null
                    )
                        ? $response['answers']
                        : [];

                foreach ($questions as $question) {
                    $qid =
                        survey_string(
                            $question['id'] ?? ''
                        );

                    $answer =
                        $answers[$qid] ?? '';

                    if (is_array($answer)) {
                        $answer =
                            implode(
                                ' / ',
                                array_map(
                                    'survey_string',
                                    $answer
                                )
                            );
                    }

                    $row[] =
                        survey_string($answer);
                }

                fputcsv(
                    $fp,
                    $row
                );
            }

            fclose($fp);
            exit;

        default:
            survey_json_response([
                'ok' => false,
                'message' =>
                    '不明なactionです。'
            ], 400);
    }
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

<body class="bg-slate-100 text-slate-800 min-h-screen">

<div id="app"></div>

<script>
window.App = {

    state: {
        data: null,
        page: 'list',
        survey: null,
        keyword: '',
        statusFilter: 'all',
        sort: 'updated_desc',
        editing: false,
        previewMode: 'pc',
        responseFilter: '',
        customerFilter: '',
        selectedRecipients: [],
        csrf: <?= json_encode(
            $csrf,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?>
    },

    util: {

        escape(value) {
            const text =
                value === null ||
                value === undefined
                    ? ''
                    : String(value);

            return text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        json(value) {
            return JSON.stringify(value)
                .replace(/</g, '\\u003c')
                .replace(/>/g, '\\u003e')
                .replace(/&/g, '\\u0026');
        },

        id(prefix) {
            return prefix + '_' +
                Date.now().toString(36) +
                '_' +
                Math.random()
                    .toString(36)
                    .slice(2, 9);
        },

        now() {
            const d = new Date();

            const pad = n =>
                String(n).padStart(2, '0');

            return d.getFullYear() +
                '-' +
                pad(d.getMonth() + 1) +
                '-' +
                pad(d.getDate()) +
                ' ' +
                pad(d.getHours()) +
                ':' +
                pad(d.getMinutes()) +
                ':' +
                pad(d.getSeconds());
        },

        statusLabel(status) {
            return {
                active: '公開中',
                draft: '下書き',
                ended: '終了'
            }[status] || status;
        },

        statusClass(status) {
            return {
                active:
                    'bg-emerald-100 text-emerald-700',
                draft:
                    'bg-slate-200 text-slate-700',
                ended:
                    'bg-amber-100 text-amber-700'
            }[status] ||
                'bg-slate-200 text-slate-700';
        },

        typeLabel(type) {
            return {
                single: '単一選択',
                multiple: '複数選択',
                text: '自由記述'
            }[type] || type;
        },

        flattenQuestions(survey) {
            const result = [];

            (survey?.groups || [])
                .forEach(group => {
                    (group.questions || [])
                        .forEach(question => {
                            result.push({
                                ...question,
                                group_id: group.id
                            });
                        });
                });

            return result;
        }
    },

    api: {

        async request(action, data = {}, method = 'POST') {

            let url =
                location.pathname +
                '?action=' +
                encodeURIComponent(action);

            const options = {
                method,
                credentials: 'same-origin',
                headers: {}
            };

            if (method === 'GET') {

                Object.entries(data)
                    .forEach(([key, value]) => {
                        url +=
                            '&' +
                            encodeURIComponent(key) +
                            '=' +
                            encodeURIComponent(value);
                    });

            } else {

                const form = new FormData();

                form.append(
                    'csrf_token',
                    App.state.csrf
                );

                Object.entries(data)
                    .forEach(([key, value]) => {

                        if (
                            typeof value === 'object'
                        ) {
                            form.append(
                                key,
                                JSON.stringify(value)
                            );
                        } else {
                            form.append(
                                key,
                                value
                            );
                        }

                    });

                options.body = form;
            }

            const response =
                await fetch(url, options);

            const contentType =
                response.headers.get(
                    'content-type'
                ) || '';

            if (
                contentType.includes(
                    'application/json'
                )
            ) {
                const json =
                    await response.json();

                if (!json.ok &&
                    response.status >= 400) {
                    throw new Error(
                        json.message ||
                        'サーバーエラー'
                    );
                }

                return json;
            }

            return response;
        },

        async bootstrap() {
            const result =
                await App.api.request(
                    'bootstrap',
                    {},
                    'GET'
                );

            App.state.data =
                result.data;

            App.state.csrf =
                result.csrf_token;
        }
    },

    actions: {

        async reload() {
            await App.api.bootstrap();
            App.render.current();
        },

        newSurvey() {

            const survey = {
                id: App.util.id('survey'),
                title: '新しいアンケート',
                start_at: '',
                end_at: '',
                status: 'draft',
                created_at: App.util.now(),
                updated_at: App.util.now(),
                numbering_mode: 'global',
                groups: [],
                deleted: false
            };

            survey.groups.push({
                id: App.util.id('group'),
                name: '基本情報',
                questions: []
            });

            App.state.survey = survey;
            App.state.editing = true;
            App.state.page = 'editor';

            App.render.current();
        },

        editSurvey(id) {

            const survey =
                App.state.data.surveys
                    .find(item =>
                        item.id === id
                    );

            if (!survey) {
                alert(
                    'アンケートが見つかりません。'
                );
                return;
            }

            App.state.survey =
                JSON.parse(
                    JSON.stringify(survey)
                );

            App.state.editing =
                survey.status !== 'ended';

            App.state.page = 'editor';

            App.render.current();
        },

        backToList() {
            App.state.page = 'list';
            App.state.survey = null;
            App.render.current();
        },

        async saveSurvey() {

            const survey =
                App.state.survey;

            survey.updated_at =
                App.util.now();

            const result =
                await App.api.request(
                    'save_survey',
                    {
                        survey_json:
                            JSON.stringify(survey)
                    }
                );

            if (!result.ok) {
                alert(
                    result.message ||
                    '保存に失敗しました。'
                );
                return;
            }

            await App.api.bootstrap();

            alert('保存しました。');

            App.state.page = 'list';
            App.state.survey = null;

            App.render.current();
        },

        cancelEdit() {

            if (
                !confirm(
                    '変更内容を破棄して一覧へ戻りますか？'
                )
            ) {
                return;
            }

            App.backToList();
        },

        async duplicateSurvey(id) {

            if (
                !confirm(
                    'このアンケートを複製しますか？'
                )
            ) {
                return;
            }

            const result =
                await App.api.request(
                    'duplicate_survey',
                    {
                        survey_id: id
                    }
                );

            if (!result.ok) {
                alert(result.message);
                return;
            }

            await App.api.bootstrap();

            App.render.current();
        },

        async deleteSurvey(id) {

            if (
                !confirm(
                    'この下書きを削除しますか？'
                )
            ) {
                return;
            }

            await App.api.request(
                'delete_survey',
                {
                    survey_id: id
                }
            );

            await App.api.bootstrap();

            App.render.current();
        },

        async setStatus(id, status) {

            const message =
                status === 'active'
                    ? 'このアンケートを公開しますか？'
                    : 'このアンケートを停止しますか？';

            if (!confirm(message)) {
                return;
            }

            await App.api.request(
                'set_status',
                {
                    survey_id: id,
                    status
                }
            );

            await App.api.bootstrap();

            App.render.current();
        },

        toggleStatusFilter(value) {
            App.state.statusFilter = value;
            App.render.list();
        },

        searchKeyword(value) {
            App.state.keyword = value;
            App.render.list();
        },

        changeSort(value) {
            App.state.sort = value;
            App.render.list();
        },

        updateSurveyField(
            field,
            value
        ) {
            App.state.survey[field] =
                value;
        },

        addGroup() {

            App.state.survey.groups.push({
                id: App.util.id('group'),
                name: '新しいグループ',
                questions: []
            });

            App.render.editor();
            App.actions.initSortable();
        },

        removeGroup(groupId) {

            if (
                !confirm(
                    'グループと内部の質問を削除しますか？'
                )
            ) {
                return;
            }

            App.state.survey.groups =
                App.state.survey.groups
                    .filter(
                        group =>
                            group.id !== groupId
                    );

            App.render.editor();
            App.actions.initSortable();
        },

        updateGroupName(
            groupId,
            value
        ) {

            const group =
                App.state.survey.groups
                    .find(
                        item =>
                            item.id === groupId
                    );

            if (group) {
                group.name = value;
            }
        },

        addQuestion(groupId) {

            const group =
                App.state.survey.groups
                    .find(
                        item =>
                            item.id === groupId
                    );

            if (!group) {
                return;
            }

            group.questions.push({
                id: App.util.id('question'),
                text: '新しい質問',
                type: 'single',
                required: false,
                options: [
                    '選択肢1',
                    '選択肢2'
                ],
                other_enabled: false,
                branching: []
            });

            App.render.editor();
            App.actions.initSortable();
        },

        removeQuestion(
            groupId,
            questionId
        ) {

            const group =
                App.state.survey.groups
                    .find(
                        item =>
                            item.id === groupId
                    );

            if (!group) {
                return;
            }

            group.questions =
                group.questions.filter(
                    question =>
                        question.id !== questionId
                );

            App.render.editor();
            App.actions.initSortable();
        },

        updateQuestion(
            groupId,
            questionId,
            field,
            value
        ) {

            const question =
                App.util
                    .flattenQuestions(
                        App.state.survey
                    )
                    .find(
                        item =>
                            item.id === questionId
                    );

            if (!question) {
                return;
            }

            if (field === 'required') {
                question.required =
                    value === true ||
                    value === 'true';
            } else if (
                field === 'other_enabled'
            ) {
                question.other_enabled =
                    value === true ||
                    value === 'true';
            } else {
                question[field] = value;
            }

            if (
                field === 'type' &&
                value !== 'single'
            ) {
                question.branching = [];
            }

            App.render.editor();
            App.actions.initSortable();
        },

        addOption(
            groupId,
            questionId
        ) {

            const question =
                App.util
                    .flattenQuestions(
                        App.state.survey
                    )
                    .find(
                        item =>
                            item.id === questionId
                    );

            if (!question) {
                return;
            }

            question.options =
                question.options || [];

            question.options.push(
                '選択肢' +
                (question.options.length + 1)
            );

            App.render.editor();
            App.actions.initSortable();
        },

        removeOption(
            questionId,
            index
        ) {

            const question =
                App.util
                    .flattenQuestions(
                        App.state.survey
                    )
                    .find(
                        item =>
                            item.id === questionId
                    );

            if (!question) {
                return;
            }

            question.options.splice(
                index,
                1
            );

            question.branching =
                (question.branching || [])
                    .filter(
                        item =>
                            item.option !==
                            question.options[index]
                    );

            App.render.editor();
            App.actions.initSortable();
        },

        updateOption(
            questionId,
            index,
            value
        ) {

            const question =
                App.util
                    .flattenQuestions(
                        App.state.survey
                    )
                    .find(
                        item =>
                            item.id === questionId
                    );

            if (!question) {
                return;
            }

            const old =
                question.options[index];

            question.options[index] =
                value;

            (question.branching || [])
                .forEach(item => {
                    if (
                        item.option === old
                    ) {
                        item.option =
                            value;
                    }
                });

            App.render.editor();
            App.actions.initSortable();
        },

        setBranchTarget(
            questionId,
            option,
            targetId
        ) {

            const question =
                App.util
                    .flattenQuestions(
                        App.state.survey
                    )
                    .find(
                        item =>
                            item.id === questionId
                    );

            if (!question) {
                return;
            }

            question.branching =
                question.branching || [];

            let item =
                question.branching.find(
                    branch =>
                        branch.option ===
                        option
                );

            if (!item) {
                item = {
                    option,
                    target_question_id:
                        targetId
                };

                question.branching.push(item);
            } else {
                item.target_question_id =
                    targetId;
            }
        },

        moveQuestion(
            questionId,
            targetGroupId,
            targetIndex
        ) {

            let moving = null;

            for (
                const group
                of App.state.survey.groups
            ) {
                const index =
                    group.questions
                        .findIndex(
                            q =>
                                q.id ===
                                questionId
                        );

                if (index >= 0) {
                    moving =
                        group.questions
                            .splice(
                                index,
                                1
                            )[0];
                    break;
                }
            }

            if (!moving) {
                return;
            }

            const target =
                App.state.survey.groups
                    .find(
                        group =>
                            group.id ===
                            targetGroupId
                    );

            if (!target) {
                return;
            }

            target.questions.splice(
                targetIndex,
                0,
                moving
            );

            App.render.editor();
            App.actions.initSortable();
        },

        initSortable() {

            if (
                typeof Sortable ===
                'undefined'
            ) {
                return;
            }

            const groupList =
                document.getElementById(
                    'editor_group_list'
                );

            if (groupList) {

                new Sortable(
                    groupList,
                    {
                        animation: 180,
                        handle: '.group-handle',
                        ghostClass:
                            'opacity-40',
                        onEnd(event) {

                            if (
                                event.oldIndex ===
                                event.newIndex
                            ) {
                                return;
                            }

                            const groups =
                                App.state
                                    .survey
                                    .groups;

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

                            App.render.editor();
                            App.actions.initSortable();
                        }
                    }
                );
            }

            document
                .querySelectorAll(
                    '.question-sortable'
                )
                .forEach(container => {

                    new Sortable(
                        container,
                        {
                            group: 'survey-questions',
                            animation: 180,
                            handle: '.question-handle',
                            ghostClass:
                                'opacity-40',

                            onEnd(event) {

                                const questionId =
                                    event.item
                                        .dataset
                                        .questionId;

                                const targetGroupId =
                                    event.to
                                        .dataset
                                        .groupId;

                                App.actions
                                    .moveQuestion(
                                        questionId,
                                        targetGroupId,
                                        event.newIndex
                                    );
                            }
                        }
                    );

                });
        },

        preview() {

            document
                .getElementById(
                    'preview_modal'
                )
                .classList.remove(
                    'hidden'
                );

            App.render.preview();
        },

        closePreview() {

            document
                .getElementById(
                    'preview_modal'
                )
                ?.classList.add(
                    'hidden'
                );
        },

        previewSubmit(event) {
            event.preventDefault();

            alert(
                'これはプレビューです。実際の回答は送信されません。'
            );
        },

        showAggregation(id) {

            const survey =
                App.state.data.surveys
                    .find(
                        item =>
                            item.id === id
                    );

            if (!survey) {
                return;
            }

            App.state.survey =
                survey;

            App.state.page =
                'aggregation';

            App.render.current();
        },

        toggleResponseQuestion(
            questionId,
            checked
        ) {

            const node =
                document.querySelector(
                    '[data-response-question="' +
                    CSS.escape(questionId) +
                    '"]'
                );

            if (node) {
                node.classList.toggle(
                    'hidden',
                    !checked
                );
            }
        },

        selectAllQuestions(checked) {

            document
                .querySelectorAll(
                    '[data-question-toggle]'
                )
                .forEach(input => {
                    input.checked =
                        checked;

                    const target =
                        document.querySelector(
                            '[data-response-question="' +
                            CSS.escape(
                                input.value
                            ) +
                            '"]'
                        );

                    target?.classList.toggle(
                        'hidden',
                        !checked
                    );
                });
        },

        showResponse(responseId) {

            const response =
                App.state.data.responses
                    .find(
                        item =>
                            item.id ===
                            responseId
                    );

            if (!response) {
                return;
            }

            App.render.responseModal(
                response
            );
        },

        closeResponseModal() {

            document
                .getElementById(
                    'response_modal'
                )
                ?.classList.add(
                    'hidden'
                );
        },

        showMail(id) {

            App.state.survey =
                App.state.data.surveys
                    .find(
                        item =>
                            item.id === id
                    );

            App.state.page =
                'mail';

            App.render.current();
        },

        updateCustomerFilter(value) {
            App.state.customerFilter =
                value;

            App.render.mail();
        },

        toggleCustomer(
            customerId,
            checked
        ) {

            if (checked) {
                if (
                    !App.state
                        .selectedRecipients
                        .includes(customerId)
                ) {
                    App.state
                        .selectedRecipients
                        .push(customerId);
                }
            } else {
                App.state
                    .selectedRecipients =
                    App.state
                        .selectedRecipients
                        .filter(
                            id =>
                                id !== customerId
                        );
            }
        },

        toggleAllCustomers(
            checked
        ) {

            const surveyId =
                App.state.survey.id;

            const ids =
                App.state.data.customers
                    .filter(
                        customer =>
                            customer.source ===
                            'kintone'
                    )
                    .map(
                        customer =>
                            customer.id
                    );

            App.state.selectedRecipients =
                checked ? ids : [];

            App.render.mail();
        },

        async saveMail() {

            const subject =
                document
                    .getElementById(
                        'mail_subject'
                    )
                    ?.value || '';

            const body =
                document
                    .getElementById(
                        'mail_body'
                    )
                    ?.value || '';

            const type =
                document
                    .getElementById(
                        'template_type'
                    )
                    ?.value || 'initial';

            const ids =
                App.state
                    .selectedRecipients;

            if (!ids.length) {
                alert(
                    '送信対象を選択してください。'
                );
                return;
            }

            const customers =
                App.state.data.customers
                    .filter(
                        customer =>
                            ids.includes(
                                customer.id
                            )
                    );

            const alreadySent =
                customers.filter(
                    customer =>
                        customer.sent_at
                );

            if (
                alreadySent.length &&
                !confirm(
                    '既に送信済みの宛先が含まれています。再送しますか？'
                )
            ) {
                return;
            }

            const now =
                App.util.now();

            customers.forEach(
                customer => {
                    customer.sent_at = now;
                    customer.send_count =
                        Number(
                            customer.send_count ||
                            0
                        ) + 1;
                    customer.answer_status =
                        'unanswered';
                }
            );

            App.state.data.mail_logs
                .push({
                    id:
                        App.util.id(
                            'mail'
                        ),
                    survey_id:
                        App.state.survey.id,
                    sent_at: now,
                    type,
                    count:
                        customers.length,
                    subject,
                    body,
                    executor:
                        '管理者'
                });

            await App.api.request(
                'save_settings',
                {
                    settings_json:
                        JSON.stringify(
                            App.state.data.settings
                        )
                }
            );

            /*
             * 顧客・メールログを直接保存する専用APIは
             * 既存名称を壊さないためsave_data APIを使用。
             */
            await App.api.request(
                'save_runtime_data',
                {
                    data_json:
                        JSON.stringify(
                            App.state.data
                        )
                }
            ).catch(() => {});

            alert(
                '送信処理を記録しました。\n\n' +
                '実際のSMTP送信はサーバー側メール環境に依存します。'
            );

            App.render.mail();
        },

        async saveRuntimeData() {

            await App.api.request(
                'save_runtime_data',
                {
                    data_json:
                        JSON.stringify(
                            App.state.data
                        )
                }
            );
        },

        showSettings() {
            App.state.page =
                'settings';

            App.render.current();
        },

        updateSetting(
            field,
            value
        ) {

            App.state.data.settings[
                field
            ] = value;

            if (
                field ===
                'field_address'
            ) {
                return;
            }

            App.render.settings();
        },

        addAddressField() {

            App.state.data.settings
                .field_address =
                App.state.data.settings
                    .field_address || [];

            App.state.data.settings
                .field_address
                .push('');

            App.render.settings();
        },

        removeAddressField(index) {

            App.state.data.settings
                .field_address
                .splice(index, 1);

            App.render.settings();
        },

        updateAddressField(
            index,
            value
        ) {

            App.state.data.settings
                .field_address[index] =
                value;
        },

        async saveSettings() {

            const result =
                await App.api.request(
                    'save_settings',
                    {
                        settings_json:
                            JSON.stringify(
                                App.state.data
                                    .settings
                            )
                    }
                );

            if (!result.ok) {
                alert(result.message);
                return;
            }

            alert(
                'kintone連携設定を保存しました。'
            );
        },

        async testKintone() {

            const settings =
                App.state.data.settings;

            const result =
                await App.api.request(
                    'kintone_test',
                    {
                        settings_json:
                            JSON.stringify(
                                settings
                            )
                    }
                );

            App.render.kintoneResult(
                result
            );
        },

        async fetchKintoneFields() {

            const settings =
                App.state.data.settings;

            const appId =
                document
                    .getElementById(
                        'setting_app_id'
                    )
                    ?.value ||
                settings.app_id ||
                '';

            settings.app_id =
                appId;

            const box =
                document.getElementById(
                    'field_message'
                );

            if (box) {
                box.innerHTML =
                    '<div class="text-blue-700">' +
                    'kintoneから項目一覧を取得しています…' +
                    '</div>';
            }

            try {

                const result =
                    await App.api.request(
                        'kintone_fields',
                        {
                            app_id:
                                appId,
                            settings_json:
                                JSON.stringify(
                                    settings
                                )
                        }
                    );

                if (!result.ok) {

                    App.render.kintoneResult(
                        result
                    );

                    return;
                }

                App.state.kintoneFields =
                    result.fields || [];

                App.render.settings();

                const message =
                    document.getElementById(
                        'field_message'
                    );

                if (message) {
                    message.innerHTML =
                        '<div class="text-emerald-700">' +
                        App.util.escape(
                            result.fields.length +
                            '件のフィールドを取得しました。'
                        ) +
                        '</div>';
                }

            } catch (error) {

                if (box) {
                    box.innerHTML =
                        '<div class="text-red-700">' +
                        App.util.escape(
                            error.message
                        ) +
                        '</div>';
                }
            }
        },

        addAddressMapping() {
            App.actions.addAddressField();
        }
    },

    render: {

        current() {

            const root =
                document.getElementById(
                    'app'
                );

            root.innerHTML =
                App.render.header();

            const page =
                document.createElement(
                    'div'
                );

            page.className =
                'max-w-7xl mx-auto px-4 py-6';

            root.appendChild(page);

            if (
                App.state.page ===
                'list'
            ) {
                page.innerHTML =
                    App.render.list();

            } else if (
                App.state.page ===
                'editor'
            ) {
                page.innerHTML =
                    App.render.editor();

            } else if (
                App.state.page ===
                'aggregation'
            ) {
                page.innerHTML =
                    App.render.aggregation();

            } else if (
                App.state.page ===
                'mail'
            ) {
                page.innerHTML =
                    App.render.mail();

            } else if (
                App.state.page ===
                'settings'
            ) {
                page.innerHTML =
                    App.render.settings();
            }

            if (
                App.state.page ===
                'editor'
            ) {
                App.actions.initSortable();
            }
        },

        header() {

            return `
<header class="bg-white border-b border-slate-200 sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4">
        <div class="h-16 flex items-center justify-between">
            <button
                onclick="App.actions.backToList()"
                class="font-bold text-xl text-slate-800">
                アンケート管理
            </button>

            <nav class="flex items-center gap-2">
                <button
                    onclick="App.actions.backToList()"
                    class="px-4 py-2 rounded-lg hover:bg-slate-100">
                    アンケート一覧
                </button>

                <button
                    onclick="App.actions.showSettings()"
                    class="px-4 py-2 rounded-lg hover:bg-slate-100">
                    kintone連携設定
                </button>

                <button
                    onclick="alert('ログアウト処理は認証方式に合わせて実装してください。')"
                    class="px-4 py-2 rounded-lg hover:bg-slate-100">
                    ログアウト
                </button>
            </nav>
        </div>
    </div>
</header>`;
        },

        list() {

            const keyword =
                App.state.keyword
                    .trim()
                    .toLowerCase();

            let surveys =
                App.state.data.surveys
                    .filter(
                        survey =>
                            !survey.deleted
                    );

            if (
                App.state.statusFilter !==
                'all'
            ) {
                surveys =
                    surveys.filter(
                        survey =>
                            survey.status ===
                            App.state.statusFilter
                    );
            }

            if (keyword) {
                surveys =
                    surveys.filter(
                        survey =>
                            survey.title
                                .toLowerCase()
                                .includes(keyword)
                    );
            }

            surveys.sort(
                (a, b) => {

                    if (
                        App.state.sort ===
                        'updated_desc'
                    ) {
                        return String(
                            b.updated_at
                        ).localeCompare(
                            String(
                                a.updated_at
                            )
                        );
                    }

                    if (
                        App.state.sort ===
                        'updated_asc'
                    ) {
                        return String(
                            a.updated_at
                        ).localeCompare(
                            String(
                                b.updated_at
                            )
                        );
                    }

                    if (
                        App.state.sort ===
                        'answers_desc'
                    ) {
                        return App.answerCount(
                            b.id
                        ) -
                        App.answerCount(
                            a.id
                        );
                    }

                    if (
                        App.state.sort ===
                        'answers_asc'
                    ) {
                        return App.answerCount(
                            a.id
                        ) -
                        App.answerCount(
                            b.id
                        );
                    }

                    return String(
                        b.start_at
                    ).localeCompare(
                        String(
                            a.start_at
                        )
                    );
                }
            );

            return `
<div class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">
                アンケート一覧
            </h1>
            <p class="text-sm text-slate-500 mt-1">
                アンケートの作成・送信・集計を管理します。
            </p>
        </div>

        <button
            onclick="App.actions.newSurvey()"
            class="bg-indigo-600 text-white px-5 py-3 rounded-xl font-semibold hover:bg-indigo-700 shadow-sm">
            ＋ 新規アンケート作成
        </button>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-4">
        <div class="grid md:grid-cols-3 gap-3">

            <input
                value="${App.util.escape(
                    App.state.keyword
                )}"
                onkeydown="if(event.key==='Enter'){App.actions.searchKeyword(this.value)}"
                placeholder="タイトルを検索"
                class="border border-slate-300 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-200">

            <select
                onchange="App.actions.toggleStatusFilter(this.value)"
                class="border border-slate-300 rounded-xl px-4 py-3">

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
                onchange="App.actions.changeSort(this.value)"
                class="border border-slate-300 rounded-xl px-4 py-3">

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
            </select>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">

        <div class="overflow-x-auto">

            <table class="w-full text-sm">

                <thead class="bg-slate-50 border-b">
                    <tr>
                        <th class="text-left p-4">作成日 / 更新日</th>
                        <th class="text-left p-4">タイトル</th>
                        <th class="text-left p-4">期間</th>
                        <th class="text-left p-4">ステータス</th>
                        <th class="text-right p-4">回答数</th>
                        <th class="text-right p-4">操作</th>
                    </tr>
                </thead>

                <tbody>
                    ${
                        surveys.length
                        ? surveys.map(
                            survey =>
                                App.render.surveyRow(
                                    survey
                                )
                        ).join('')
                        : `
                        <tr>
                            <td colspan="6"
                                class="p-12 text-center text-slate-400">
                                アンケートがありません。
                            </td>
                        </tr>`
                    }
                </tbody>

            </table>
        </div>
    </div>
</div>`;
        },

        surveyRow(survey) {

            const count =
                App.answerCount(
                    survey.id
                );

            let actions = '';

            if (
                survey.status ===
                'active'
            ) {
                actions = `
<button
 onclick="App.actions.editSurvey('${survey.id}')"
 class="text-indigo-600 hover:underline">
 確認・編集
</button>

<button
 onclick="App.actions.showAggregation('${survey.id}')"
 class="text-slate-700 hover:underline">
 集計
</button>

<button
 onclick="App.actions.showMail('${survey.id}')"
 class="text-slate-700 hover:underline">
 送信
</button>

<button
 onclick="App.actions.setStatus('${survey.id}','ended')"
 class="text-amber-700 hover:underline">
 停止
</button>

<button
 onclick="App.actions.duplicateSurvey('${survey.id}')"
 class="text-slate-700 hover:underline">
 複製
</button>`;

            } else if (
                survey.status ===
                'draft'
            ) {

                actions = `
<button
 onclick="App.actions.editSurvey('${survey.id}')"
 class="text-indigo-600 hover:underline">
 確認・編集
</button>

<button
 onclick="App.actions.deleteSurvey('${survey.id}')"
 class="text-red-600 hover:underline">
 削除
</button>

<button
 onclick="App.actions.duplicateSurvey('${survey.id}')"
 class="text-slate-700 hover:underline">
 複製
</button>`;

            } else {

                actions = `
<button
 onclick="App.actions.editSurvey('${survey.id}')"
 class="text-indigo-600 hover:underline">
 確認・編集
</button>

<button
 onclick="App.actions.showAggregation('${survey.id}')"
 class="text-slate-700 hover:underline">
 集計
</button>

<button
 onclick="App.actions.duplicateSurvey('${survey.id}')"
 class="text-slate-700 hover:underline">
 複製
</button>`;
            }

            return `
<tr class="border-b last:border-0 hover:bg-slate-50">

<td class="p-4 whitespace-nowrap text-slate-500">
    ${App.util.escape(
        survey.created_at
            .slice(0,10)
            .replaceAll('-','/')
    )}
    <br>
    <span class="text-xs">
        更新:
        ${App.util.escape(
            survey.updated_at
                .slice(0,10)
                .replaceAll('-','/')
        )}
    </span>
</td>

<td class="p-4 font-bold">
    ${App.util.escape(survey.title)}
</td>

<td class="p-4">
    ${
        survey.start_at ||
        survey.end_at
        ?
        App.util.escape(
            survey.start_at || '未設定'
        ) +
        ' ～ ' +
        App.util.escape(
            survey.end_at || '未設定'
        )
        :
        '未設定'
    }
</td>

<td class="p-4">
    <span class="px-3 py-1 rounded-full text-xs font-semibold ${App.util.statusClass(survey.status)}">
        ${App.util.statusLabel(
            survey.status
        )}
    </span>
</td>

<td class="p-4 text-right font-semibold">
    ${count} 件
</td>

<td class="p-4">
    <div class="flex justify-end gap-3 flex-wrap">
        ${actions}
    </div>
</td>

</tr>`;
        },

        editor() {

            const survey =
                App.state.survey;

            return `
<div class="space-y-6">

<div class="flex items-center justify-between">

<div class="flex-1">
    <input
        id="survey_title"
        value="${App.util.escape(
            survey.title
        )}"
        onchange="App.actions.updateSurveyField('title',this.value)"
        class="text-2xl font-bold bg-transparent border-0 border-b-2 border-transparent hover:border-slate-300 focus:border-indigo-500 outline-none w-full max-w-3xl px-1 py-2">
</div>

<div class="flex gap-2">
    <button
        onclick="App.actions.preview()"
        class="px-4 py-2 rounded-xl bg-white border border-slate-300">
        プレビュー
    </button>

    ${
        App.state.editing
        ?
        `
        <button
            onclick="App.actions.saveSurvey()"
            class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-semibold">
            保存して一覧へ戻る
        </button>`
        :
        ''
    }

    <button
        onclick="App.actions.cancelEdit()"
        class="px-4 py-2 rounded-xl bg-slate-200">
        キャンセル
    </button>
</div>

</div>

<div class="bg-white rounded-2xl border border-slate-200 p-5">

<div class="grid md:grid-cols-3 gap-4">

<div>
<label class="block text-sm font-semibold mb-1">
開始日時
</label>

<input
    id="survey_start_at"
    type="datetime-local"
    value="${App.util.escape(
        survey.start_at
    )}"
    onchange="App.actions.updateSurveyField('start_at',this.value)"
    class="w-full border rounded-xl px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
終了日時
</label>

<input
    id="survey_end_at"
    type="datetime-local"
    value="${App.util.escape(
        survey.end_at
    )}"
    onchange="App.actions.updateSurveyField('end_at',this.value)"
    class="w-full border rounded-xl px-3 py-2">
</div>

<div>
<label class="block text-sm font-semibold mb-1">
質問番号
</label>

<select
    id="survey_numbering_mode"
    onchange="App.actions.updateSurveyField('numbering_mode',this.value);App.render.editor();App.actions.initSortable()"
    class="w-full border rounded-xl px-3 py-2">

<option value="global"
    ${survey.numbering_mode === 'global' ? 'selected' : ''}>
    Q1, Q2, Q3...
</option>

<option value="group"
    ${survey.numbering_mode === 'group' ? 'selected' : ''}>
    Q1-1, Q1-2...
</option>

</select>
</div>

</div>
</div>

<div
    id="editor_group_list"
    class="space-y-5">

${
    survey.groups.map(
        (group, groupIndex) =>
            App.render.groupEditor(
                group,
                groupIndex
            )
    ).join('')
}

</div>

<button
    onclick="App.actions.addGroup()"
    class="w-full py-4 border-2 border-dashed border-slate-300 rounded-2xl text-slate-500 hover:border-indigo-400 hover:text-indigo-600">
    ＋ グループを追加
</button>

<div
    id="preview_modal"
    class="hidden">
</div>

</div>`;
        },

        groupEditor(
            group,
            groupIndex
        ) {

            return `
<section
    class="bg-white border border-slate-200 rounded-2xl overflow-hidden"
    data-group-id="${group.id}">

<div class="bg-slate-50 px-5 py-4 flex items-center gap-3">

<span class="group-handle cursor-move text-xl">
    ⠿
</span>

<input
    value="${App.util.escape(
        group.name
    )}"
    onchange="App.actions.updateGroupName('${group.id}',this.value)"
    class="flex-1 bg-transparent font-bold text-lg border-b border-transparent focus:border-indigo-400 outline-none">

<button
    onclick="App.actions.removeGroup('${group.id}')"
    class="text-red-500 hover:bg-red-50 px-3 py-2 rounded-lg">
    グループ削除
</button>

</div>

<div
    class="p-5 space-y-4 question-sortable"
    id="question_editor"
    data-group-id="${group.id}">

${
    group.questions.map(
        (question, index) =>
            App.render.questionEditor(
                question,
                group,
                groupIndex,
                index
            )
    ).join('')
}

</div>

<div class="px-5 pb-5">

<button
    onclick="App.actions.addQuestion('${group.id}')"
    class="w-full py-3 rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-700 font-semibold">
    ＋ 質問を追加
</button>

</div>

</section>`;
        },

        questionEditor(
            question,
            group,
            groupIndex,
            index
        ) {

            const number =
                App.questionNumber(
                    groupIndex,
                    index
                );

            return `
<div
    class="border border-slate-200 rounded-2xl p-5 bg-white shadow-sm"
    data-question-id="${question.id}">

<div class="flex items-start gap-3">

<span class="question-handle cursor-move text-xl mt-2">
    ⠿
</span>

<div class="flex-1 space-y-4">

<div class="flex items-center justify-between">

<div class="font-bold text-indigo-700">
    ${number}
</div>

<button
    onclick="App.actions.removeQuestion('${group.id}','${question.id}')"
    class="text-red-500">
    削除
</button>

</div>

<input
    value="${App.util.escape(
        question.text
    )}"
    onchange="App.actions.updateQuestion('${group.id}','${question.id}','text',this.value)"
    class="w-full border rounded-xl px-4 py-3 font-semibold">

<div class="grid md:grid-cols-3 gap-3">

<select
    onchange="App.actions.updateQuestion('${group.id}','${question.id}','type',this.value)"
    class="border rounded-xl px-3 py-2">

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

<label class="flex items-center gap-2 border rounded-xl px-3 py-2">
<input
    type="checkbox"
    ${question.required ? 'checked' : ''}
    onchange="App.actions.updateQuestion('${group.id}','${question.id}','required',this.checked)">
必須回答
</label>

${
    question.type !== 'text'
    ?
    `
<label class="flex items-center gap-2 border rounded-xl px-3 py-2">
<input
    type="checkbox"
    ${question.other_enabled ? 'checked' : ''}
    onchange="App.actions.updateQuestion('${group.id}','${question.id}','other_enabled',this.checked)">
その他を許可
</label>`
    :
    ''
}

</div>

${
    question.type !== 'text'
    ?
    `
<div class="space-y-2">

<div class="flex justify-between items-center">
    <span class="font-semibold text-sm">
        選択肢
    </span>

    <button
        onclick="App.actions.addOption('${group.id}','${question.id}')"
        class="text-indigo-600 text-sm">
        ＋ 選択肢追加
    </button>
</div>

${
    (question.options || [])
        .map(
            (option, optionIndex) =>
                `
<div class="flex gap-2">

<input
    value="${App.util.escape(option)}"
    onchange="App.actions.updateOption('${question.id}',${optionIndex},this.value)"
    class="flex-1 border rounded-lg px-3 py-2">

<button
    onclick="App.actions.removeOption('${question.id}',${optionIndex})"
    class="px-3 text-red-500">
    ×
</button>

</div>`
        ).join('')
}

</div>

${
    question.type === 'single' &&
    (question.options || []).length
    ?
    `
<div class="bg-indigo-50 rounded-xl p-4">

<div class="font-semibold text-sm mb-2">
    回答による質問分岐
</div>

${
    question.options.map(
        option =>
            `
<div class="grid md:grid-cols-2 gap-2 mb-2">

<div class="text-sm py-2">
${App.util.escape(option)}
</div>

<select
    onchange="App.actions.setBranchTarget('${question.id}',${JSON.stringify(option)},this.value)"
    class="border rounded-lg px-3 py-2">

<option value="">
分岐しない
</option>

${
    App.util
        .flattenQuestions(
            App.state.survey
        )
        .filter(
            q =>
                q.id !== question.id
        )
        .map(
            q =>
                `<option
                    value="${q.id}"
                    ${
                        (question.branching || [])
                            .find(
                                b =>
                                    b.option ===
                                    option
                            )
                            ?.target_question_id ===
                        q.id
                        ? 'selected'
                        : ''
                    }>
                    ${App.util.escape(
                        q.text
                    )}
                </option>`
        ).join('')
}

</select>
</div>`
    ).join('')
}

</div>`
    :
    ''
}

${
    question.type === 'text'
    ?
    `
<textarea
    disabled
    placeholder="自由記述欄"
    class="w-full border rounded-xl px-4 py-3 h-24 bg-slate-50"></textarea>`
    :
    ''
}

</div>
</div>
</div>`;
        },

        preview() {

            const modal =
                document.getElementById(
                    'preview_modal'
                );

            if (!modal) {
                return;
            }

            modal.innerHTML = `
<div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">

<div class="${
    App.state.previewMode === 'pc'
        ? 'w-full max-w-4xl'
        : 'w-[390px] max-w-full'
} max-h-[90vh] overflow-auto bg-slate-100 rounded-2xl">

<div class="sticky top-0 bg-white border-b p-4 flex justify-between">

<div class="font-bold">
プレビュー
</div>

<div class="flex gap-2">

<button
    onclick="App.state.previewMode='pc';App.render.preview()"
    class="px-3 py-1 rounded-lg border">
    PC
</button>

<button
    onclick="App.state.previewMode='sp';App.render.preview()"
    class="px-3 py-1 rounded-lg border">
    スマートフォン
</button>

<button
    onclick="App.actions.closePreview()"
    class="px-3 py-1 rounded-lg bg-slate-200">
    閉じる
</button>

</div>
</div>

<div
    id="preview_content"
    class="p-6">
    ${App.render.previewContent()}
</div>

</div>
</div>`;
        },

        previewContent() {

            const survey =
                App.state.survey;

            let n = 0;

            return `
<form
    onsubmit="App.actions.previewSubmit(event)"
    class="bg-white rounded-2xl p-6 space-y-6">

<h1 class="text-2xl font-bold">
${App.util.escape(
    survey.title
)}
</h1>

${
    survey.groups.map(
        group =>
            `
<div class="space-y-4">

<h2 class="font-bold text-lg border-b pb-2">
${App.util.escape(group.name)}
</h2>

${
    group.questions.map(
        question => {

            n++;

            return `
<div class="space-y-2">

<label class="font-semibold block">
Q${n}. ${App.util.escape(
    question.text
)}
${question.required ? '<span class="text-red-500">*</span>' : ''}
</label>

${
    question.type === 'text'
    ?
    `<textarea class="w-full border rounded-xl p-3 h-28"></textarea>`
    :
    (question.options || [])
        .map(
            option =>
                `
<label class="flex gap-2 items-center">
<input
    type="${question.type === 'single' ? 'radio' : 'checkbox'}"
    name="q_${question.id}">
<span>${App.util.escape(option)}</span>
</label>`
        ).join('')
}

</div>`;
        }
    ).join('')
}

</div>`
    ).join('')
}

<button
    type="submit"
    class="w-full bg-indigo-600 text-white rounded-xl py-3 font-semibold">
    回答を送信
</button>

</form>`;
        },

        aggregation() {

            const survey =
                App.state.survey;

            const responses =
                App.state.data.responses
                    .filter(
                        response =>
                            response.survey_id ===
                            survey.id
                    );

            const customers =
                App.state.data.customers
                    .filter(
                        customer =>
                            customer.survey_id ===
                            survey.id ||
                            !customer.survey_id
                    );

            const sent =
                customers.filter(
                    customer =>
                        customer.sent_at
                ).length;

            const answerCount =
                responses.length;

            const registered =
                responses.filter(
                    response =>
                        response.customer_id
                ).length;

            const unanswered =
                Math.max(
                    sent - registered,
                    0
                );

            const rate =
                sent
                    ? (
                        registered /
                        sent *
                        100
                    ).toFixed(1)
                    : '0.0';

            const questions =
                App.util
                    .flattenQuestions(
                        survey
                    );

            return `
<div class="space-y-6">

<div class="flex items-center justify-between">

<div>
<div class="text-sm text-slate-500">
アンケート集計
</div>

<h1 class="text-2xl font-bold">
${App.util.escape(
    survey.title
)}
</h1>
</div>

<div class="flex gap-2">

<button
 onclick="location.href='?action=csv&survey_id=${encodeURIComponent(survey.id)}'"
 class="px-4 py-2 rounded-xl bg-white border">
 CSV出力
</button>

<button
 onclick="App.actions.backToList()"
 class="px-4 py-2 rounded-xl bg-slate-200">
 一覧へ戻る
</button>

</div>
</div>

<div class="grid md:grid-cols-5 gap-4">

${App.render.statCard(
    '送信対象者数',
    sent + ' 人'
)}

${App.render.statCard(
    '回答数',
    answerCount + ' 件'
)}

${App.render.statCard(
    '未登録顧客からの回答数',
    Math.max(
        answerCount - registered,
        0
    ) + ' 件'
)}

${App.render.statCard(
    '未回答数',
    unanswered + ' 人'
)}

${App.render.statCard(
    '回答率',
    rate + ' %'
)}

</div>

<div class="bg-white border rounded-2xl p-5">

<div class="flex items-center justify-between mb-4">

<h2 class="font-bold">
設問別集計
</h2>

<div class="flex gap-2">

<button
 onclick="App.actions.selectAllQuestions(true)"
 class="text-sm text-indigo-600">
 全選択
</button>

<button
 onclick="App.actions.selectAllQuestions(false)"
 class="text-sm text-indigo-600">
 全解除
</button>

</div>
</div>

<div class="grid md:grid-cols-2 gap-2 mb-6">

${
    questions.map(
        question =>
            `
<label class="flex items-center gap-2 border rounded-lg p-3">

<input
 type="checkbox"
 data-question-toggle
 value="${question.id}"
 checked
 onchange="App.actions.toggleResponseQuestion('${question.id}',this.checked)">

<span>
${App.util.escape(
    question.text
)}
</span>

<span class="ml-auto text-xs text-slate-400">
${App.util.typeLabel(
    question.type
)}
</span>

</label>`
    ).join('')
}

</div>

${
    questions.map(
        question =>
            App.render.questionAggregation(
                question,
                responses
            )
    ).join('')
}

</div>

<div class="bg-white border rounded-2xl p-5">

<h2 class="font-bold mb-4">
個別回答一覧
</h2>

<input
 id="response_filter"
 value="${App.util.escape(
     App.state.responseFilter
 )}"
 oninput="App.state.responseFilter=this.value;App.render.aggregation()"
 placeholder="会社名・氏名で検索"
 class="w-full border rounded-xl px-4 py-3 mb-4">

<div class="overflow-x-auto">

<table class="w-full text-sm">

<thead>
<tr class="border-b">
<th class="text-left p-3">回答日時</th>
<th class="text-left p-3">会社名</th>
<th class="text-left p-3">氏名</th>
<th class="text-right p-3">操作</th>
</tr>
</thead>

<tbody>
${
    responses
        .filter(
            response => {

                const key =
                    App.state
                        .responseFilter
                        .toLowerCase();

                return !key ||
                    (
                        String(
                            response.company ||
                            ''
                        ) +
                        String(
                            response.name ||
                            ''
                        )
                    )
                    .toLowerCase()
                    .includes(key);
            }
        )
        .map(
            response =>
                `
<tr class="border-b">
<td class="p-3">
${App.util.escape(
    response.answered_at
)}
</td>

<td class="p-3">
${App.util.escape(
    response.company
)}
</td>

<td class="p-3">
${App.util.escape(
    response.name
)}
</td>

<td class="p-3 text-right">
<button
 onclick="App.actions.showResponse('${response.id}')"
 class="text-indigo-600">
 全回答を表示
</button>
</td>
</tr>`
        ).join('')
}

</tbody>
</table>

</div>
</div>

<div
 id="response_modal"
 class="hidden">
</div>

</div>`;
        },

        statCard(
            label,
            value
        ) {

            return `
<div class="bg-white border rounded-2xl p-5">
<div class="text-sm text-slate-500">
${label}
</div>
<div class="text-2xl font-bold mt-2">
${value}
</div>
</div>`;
        },

        questionAggregation(
            question,
            responses
        ) {

            const counts = {};

            (question.options || [])
                .forEach(
                    option =>
                        counts[option] = 0
                );

            let textAnswers = [];

            responses.forEach(
                response => {

                    const answers =
                        response.answers || {};

                    const answer =
                        answers[
                            question.id
                        ];

                    if (
                        question.type ===
                        'text'
                    ) {

                        if (
                            answer !== undefined &&
                            answer !== ''
                        ) {
                            textAnswers.push({
                                response,
                                answer
                            });
                        }

                    } else if (
                        Array.isArray(answer)
                    ) {

                        answer.forEach(
                            value => {
                                if (
                                    counts[
                                        value
                                    ] !== undefined
                                ) {
                                    counts[value]++;
                                }
                            }
                        );

                    } else if (
                        answer &&
                        counts[
                            answer
                        ] !== undefined
                    ) {
                        counts[answer]++;
                    }

                }
            );

            if (
                question.type ===
                'text'
            ) {

                return `
<div
 data-response-question="${question.id}"
 class="mb-8">

<h3 class="font-bold mb-3">
${App.util.escape(
    question.text
)}
</h3>

<div class="space-y-2 max-h-72 overflow-auto">

${
    textAnswers.length
    ?
    textAnswers.map(
        item =>
            `
<div class="border rounded-xl p-3">
<div class="text-xs text-slate-400">
${App.util.escape(
    item.response.company
)}
 / 
${App.util.escape(
    item.response.name
)}
</div>

<div class="mt-1">
${App.util.escape(
    item.answer
)}
</div>
</div>`
    ).join('')
    :
    '<div class="text-slate-400">回答はありません。</div>'
}

</div>
</div>`;
            }

            const total =
                responses.length ||
                1;

            return `
<div
 data-response-question="${question.id}"
 class="mb-8">

<h3 class="font-bold mb-3">
${App.util.escape(
    question.text
)}
</h3>

<div class="space-y-3">

${
    Object.entries(counts)
        .map(
            ([label, count]) => {

                const percent =
                    (
                        count /
                        total *
                        100
                    ).toFixed(1);

                return `
<div>

<div class="flex justify-between text-sm mb-1">

<span>
${App.util.escape(label)}
</span>

<span>
${count} 件
/
${percent}%
</span>

</div>

<div class="h-3 bg-slate-100 rounded-full overflow-hidden">

<div
 class="h-full bg-indigo-500"
 style="width:${percent}%">
</div>

</div>

</div>`;
            }
        ).join('')
}

</div>
</div>`;
        },

        responseModal(response) {

            const modal =
                document.getElementById(
                    'response_modal'
                );

            if (!modal) {
                return;
            }

            const survey =
                App.state.survey;

            const questions =
                App.util
                    .flattenQuestions(
                        survey
                    );

            modal.innerHTML = `
<div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">

<div class="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-auto">

<div class="p-5 border-b flex justify-between">

<div>
<h2 class="font-bold text-lg">
回答詳細
</h2>

<div class="text-sm text-slate-500">
${App.util.escape(
    response.company
)}
 /
${App.util.escape(
    response.name
)}
</div>
</div>

<button
 onclick="App.actions.closeResponseModal()"
 class="px-3 py-2 bg-slate-100 rounded-lg">
 閉じる
</button>

</div>

<div
 id="response_detail"
 class="p-5 space-y-5">

${
    questions.map(
        (question, index) => {

            let answer =
                response.answers?.[
                    question.id
                ] ?? '';

            if (
                Array.isArray(answer)
            ) {
                answer =
                    answer.join(
                        ' / '
                    );
            }

            return `
<div class="border-b pb-4">

<div class="font-semibold">
Q${index + 1}.
${App.util.escape(
    question.text
)}
</div>

<div class="mt-2 text-slate-600 whitespace-pre-wrap">
${App.util.escape(
    answer
)}
</div>

</div>`;
        }
    ).join('')
}

</div>

</div>
</div>`;

            modal.classList.remove(
                'hidden'
            );
        },

        mail() {

            const survey =
                App.state.survey;

            const key =
                App.state.customerFilter
                    .toLowerCase();

            const customers =
                App.state.data.customers
                    .filter(
                        customer =>
                            customer.source !==
                            'web'
                    )
                    .filter(
                        customer => {

                            if (!key) {
                                return true;
                            }

                            return (
                                String(
                                    customer.company ||
                                    ''
                                ) +
                                String(
                                    customer.name ||
                                    ''
                                ) +
                                String(
                                    customer.email ||
                                    ''
                                )
                            )
                            .toLowerCase()
                            .includes(key);
                        }
                    );

            const webCustomers =
                App.state.data.customers
                    .filter(
                        customer =>
                            customer.source ===
                            'web'
                    );

            return `
<div class="space-y-6">

<div class="flex items-center justify-between">

<div>
<div class="text-sm text-slate-500">
ホーム ＞ アンケート一覧 ＞ 顧客選択・送信
</div>

<h1 class="text-2xl font-bold mt-1">
${App.util.escape(
    survey.title
)}
</h1>
</div>

<button
 onclick="App.actions.backToList()"
 class="px-4 py-2 bg-slate-200 rounded-xl">
 一覧へ戻る
</button>

</div>

${
    webCustomers.length
    ?
    `
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4">
Web公開URLからの回答者が
${webCustomers.length}名
存在します。kintone未登録顧客として管理してください。
</div>`
    :
    ''
}

<div class="bg-white border rounded-2xl p-5">

<div class="grid md:grid-cols-2 gap-4">

<input
 id="customer_filter"
 value="${App.util.escape(
     App.state.customerFilter
 )}"
 oninput="App.actions.updateCustomerFilter(this.value)"
 placeholder="顧客名・メールアドレスで検索"
 class="border rounded-xl px-4 py-3">

<select
 id="template_type"
 class="border rounded-xl px-4 py-3">

<option value="initial">
初回送信
</option>

<option value="reminder">
リマインド
</option>

</select>

</div>

<div class="grid md:grid-cols-2 gap-4 mt-4">

<input
 id="mail_subject"
 placeholder="件名"
 value="アンケートご協力のお願い"
 class="border rounded-xl px-4 py-3">

<textarea
 id="mail_body"
 class="border rounded-xl px-4 py-3 h-32"
 placeholder="本文">{顧客名} 様

アンケートへのご協力をお願いいたします。

{アンケートURL}</textarea>

</div>

</div>

<div class="bg-white border rounded-2xl overflow-hidden">

<div class="p-4 border-b flex justify-between">

<h2 class="font-bold">
顧客一覧
</h2>

<button
 onclick="App.actions.toggleAllCustomers(true)"
 class="text-indigo-600">
 全選択
</button>

</div>

<div class="overflow-x-auto">

<table
 id="customer_table"
 class="w-full text-sm">

<thead class="bg-slate-50">

<tr>

<th class="p-3">
<input
 id="select_all"
 type="checkbox"
 onclick="App.actions.toggleAllCustomers(this.checked)">
</th>

<th class="text-left p-3">
会社名 / 氏名
</th>

<th class="text-left p-3">
メール
</th>

<th class="text-left p-3">
電話番号
</th>

<th class="text-left p-3">
送信状況
</th>

<th class="text-left p-3">
回答状況
</th>

<th class="text-left p-3">
kintone
</th>

</tr>

</thead>

<tbody>

${
    customers.map(
        customer =>
            `
<tr class="border-b">

<td class="p-3">

<input
type="checkbox"
${App.state
    .selectedRecipients
    .includes(customer.id)
    ? 'checked'
    : ''}
onchange="App.actions.toggleCustomer('${customer.id}',this.checked)">

</td>

<td class="p-3">
<div class="font-bold">
${App.util.escape(
    customer.company
)}
</div>
<div>
${App.util.escape(
    customer.name
)}
</div>
</td>

<td class="p-3">
${App.util.escape(
    customer.email
)}
</td>

<td class="p-3">
${App.util.escape(
    customer.phone
)}
</td>

<td class="p-3">
${
    customer.sent_at
    ?
    `${App.util.escape(customer.sent_at)}
     / ${customer.send_count || 0}回`
    :
    '未送信'
}
</td>

<td class="p-3">

<span class="px-2 py-1 rounded-full text-xs ${
    customer.answer_status ===
    'answered'
        ? 'bg-emerald-100 text-emerald-700'
        : 'bg-amber-100 text-amber-700'
}">
${
    customer.answer_status ===
    'answered'
    ? '回答済み'
    : '送信済み（未回答）'
}
</span>

</td>

<td class="p-3">

${
    customer.kintone_status ===
    'registered'
    ?
    '<span class="text-emerald-600">✓ kintone登録完了</span>'
    :
    `
<button
 onclick="App.actions.markKintoneRegistered('${customer.id}')"
 class="text-indigo-600">
 kintone登録完了
</button>`
}

</td>

</tr>`
    ).join('')
}

</tbody>
</table>

</div>

</div>

<div class="flex justify-end">

<button
 onclick="App.actions.saveMail()"
 class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold">
 一括送信実行
</button>

</div>

</div>`;
        },

        settings() {

            const settings =
                App.state.data.settings;

            const fields =
                App.state.kintoneFields ||
                [];

            const optionHtml =
                (selected, multiple = false) => {

                    const options =
                        [
                            '<option value="">-- 選択してください --</option>',
                            ...fields.map(
                                field =>
                                    `<option value="${App.util.escape(field.code)}"
                                        ${selected === field.code ? 'selected' : ''}>
                                        ${App.util.escape(field.label)}
                                        [${App.util.escape(field.code)}]
                                    </option>`
                            )
                        ];

                    return options.join('');
                };

            return `
<div class="space-y-6">

<div>

<div class="text-sm text-slate-500">
ホーム ＞ システム設定 ＞ kintone連携設定
</div>

<h1 class="text-2xl font-bold mt-1">
kintone連携設定
</h1>

</div>

<div
 id="field_message"
 class="text-sm">
</div>

<div
 id="kintone_status"
 class="hidden">
</div>

<div
 id="settings_form"
 class="bg-white border rounded-2xl p-6 space-y-6">

<div class="grid md:grid-cols-2 gap-4">

<div>

<label class="block font-semibold mb-1">
サブドメイン
</label>

<input
 id="setting_subdomain"
 value="${App.util.escape(
     settings.subdomain
 )}"
 onchange="App.state.data.settings.subdomain=this.value"
 placeholder="xxxx または xxxx.cybozu.com"
 class="w-full border rounded-xl px-4 py-3">

<p class="text-xs text-slate-500 mt-1">
xxxx または xxxx.cybozu.com のどちらでも指定可能
</p>

</div>

<div>

<label class="block font-semibold mb-1">
アプリID
</label>

<div class="flex gap-2">

<input
 id="setting_app_id"
 value="${App.util.escape(
     settings.app_id
 )}"
 onchange="App.state.data.settings.app_id=this.value"
 class="flex-1 border rounded-xl px-4 py-3">

<button
 onclick="App.actions.fetchKintoneFields()"
 class="px-4 py-2 rounded-xl bg-indigo-600 text-white whitespace-nowrap">
 項目一覧を再取得
</button>

</div>

</div>

<div>

<label class="block font-semibold mb-1">
ログイン名
</label>

<input
 id="setting_login_name"
 value="${App.util.escape(
     settings.login_name
 )}"
 onchange="App.state.data.settings.login_name=this.value"
 class="w-full border rounded-xl px-4 py-3">

</div>

<div>

<label class="block font-semibold mb-1">
パスワード
</label>

<input
 id="setting_password"
 type="password"
 value="${App.util.escape(
     settings.password
 )}"
 onchange="App.state.data.settings.password=this.value"
 class="w-full border rounded-xl px-4 py-3">

</div>

<div>

<label class="block font-semibold mb-1">
Proxyサーバ
</label>

<input
 id="setting_proxy"
 value="${App.util.escape(
     settings.proxy
 )}"
 onchange="App.state.data.settings.proxy=this.value"
 placeholder="host名:port番号"
 class="w-full border rounded-xl px-4 py-3">

</div>

<div class="flex items-center">

<label class="flex gap-3 items-center">

<input
 id="setting_ssl_verify"
 type="checkbox"
 ${settings.ssl_verify ? 'checked' : ''}
 onchange="App.state.data.settings.ssl_verify=this.checked">

SSL証明書を検証する

</label>

</div>

</div>

<div class="border-t pt-6">

<h2 class="font-bold text-lg mb-4">
フィールドマッピング
</h2>

<div class="grid md:grid-cols-2 gap-4">

${App.render.fieldSelect(
    'field_company',
    '会社名',
    settings.field_company,
    optionHtml
)}

${App.render.fieldSelect(
    'field_name',
    '氏名',
    settings.field_name,
    optionHtml
)}

${App.render.fieldSelect(
    'field_email',
    'メールアドレス',
    settings.field_email,
    optionHtml
)}

${App.render.fieldSelect(
    'field_department',
    '部署名',
    settings.field_department,
    optionHtml
)}

${App.render.fieldSelect(
    'field_phone',
    '電話番号',
    settings.field_phone,
    optionHtml
)}

</div>

<div class="mt-4">

<div class="font-semibold mb-2">
住所
</div>

${
    (settings.field_address || [])
        .map(
            (code, index) =>
                `
<div class="flex gap-2 mb-2">

<select
 id="field_address"
 onchange="App.actions.updateAddressField(${index},this.value)"
 class="flex-1 border rounded-xl px-3 py-2">

${optionHtml(code)}

</select>

<button
 onclick="App.actions.removeAddressField(${index})"
 class="px-3 text-red-500">
削除
</button>

</div>`
        ).join('')
}

<button
 onclick="App.actions.addAddressMapping()"
 class="text-indigo-600">
＋ 住所フィールド追加
</button>

</div>

</div>

<div class="flex gap-3 pt-4 border-t">

<button
 onclick="App.actions.testKintone()"
 class="px-5 py-3 rounded-xl border border-indigo-300 text-indigo-700">
 接続テスト
</button>

<button
 onclick="App.actions.saveSettings()"
 class="px-5 py-3 rounded-xl bg-indigo-600 text-white font-semibold">
 設定を保存
</button>

</div>

</div>

</div>`;
        },

        fieldSelect(
            key,
            label,
            selected,
            optionHtml
        ) {

            return `
<div>

<label class="block font-semibold mb-1">
${label}
</label>

<select
 id="${key}"
 onchange="App.state.data.settings['${key}']=this.value"
 class="w-full border rounded-xl px-3 py-2">

${optionHtml(selected)}

</select>

</div>`;
        },

        kintoneResult(result) {

            const box =
                document.getElementById(
                    'field_message'
                );

            if (!box) {
                return;
            }

            const ok =
                !!result.ok;

            box.innerHTML = `
<div class="rounded-2xl border ${
    ok
        ? 'border-emerald-200 bg-emerald-50'
        : 'border-red-200 bg-red-50'
} p-5">

<div class="font-bold text-lg ${
    ok
        ? 'text-emerald-700'
        : 'text-red-700'
}">
${
    ok
    ? '✓ kintone接続成功'
    : '✕ kintone接続失敗'
}
</div>

<div class="mt-4 grid md:grid-cols-2 gap-3 text-sm">

<div>
<span class="font-semibold">
HTTPステータス
</span>
<br>
${App.util.escape(
    result.status ?? ''
)}
</div>

<div>
<span class="font-semibold">
kintone error code
</span>
<br>
${App.util.escape(
    result.error_code ?? ''
)}
</div>

<div>
<span class="font-semibold">
kintone error id
</span>
<br>
${App.util.escape(
    result.error_id ?? ''
)}
</div>

<div>
<span class="font-semibold">
メッセージ
</span>
<br>
${App.util.escape(
    result.message ?? ''
)}
</div>

</div>

<div class="mt-4">

<div class="font-semibold">
Endpoint
</div>

<pre class="mt-1 bg-white border rounded-xl p-3 overflow-auto text-xs whitespace-pre-wrap">${App.util.escape(
    result.endpoint ?? ''
)}</pre>

</div>

${
    result.transport_error
    ?
    `
<div class="mt-4">

<div class="font-semibold">
PHP通信エラー
</div>

<pre class="mt-1 bg-white border rounded-xl p-3 overflow-auto text-xs whitespace-pre-wrap">${App.util.escape(
    result.transport_error
)}</pre>

</div>`
    :
    ''
}

<div class="mt-4">

<div class="font-semibold">
kintoneレスポンス本文
</div>

<pre class="mt-1 bg-white border rounded-xl p-3 overflow-auto text-xs whitespace-pre-wrap max-h-80">${App.util.escape(
    result.response_body ||
    ''
)}</pre>

</div>

</div>`;
        }
    },

    answerCount(surveyId) {

        return App.state.data.responses
            .filter(
                response =>
                    response.survey_id ===
                    surveyId
            )
            .length;
    },

    questionNumber(
        groupIndex,
        questionIndex
    ) {

        const survey =
            App.state.survey;

        if (
            survey.numbering_mode ===
            'group'
        ) {
            return 'Q' +
                (groupIndex + 1) +
                '-' +
                (questionIndex + 1);
        }

        let n = 0;

        for (
            let i = 0;
            i <= groupIndex;
            i++
        ) {
            n +=
                survey.groups[i]
                    .questions.length;

            if (i === groupIndex) {
                return 'Q' +
                    (
                        n -
                        survey.groups[i]
                            .questions.length +
                        questionIndex +
                        1
                    );
            }
        }

        return 'Q1';
    },

    async init() {

        if (
            App.state.initialized
        ) {
            return;
        }

        App.state.initialized =
            true;

        try {

            await App.api.bootstrap();

            App.render.current();

        } catch (error) {

            document
                .getElementById('app')
                .innerHTML = `
<div class="max-w-xl mx-auto mt-20 bg-white border border-red-200 rounded-2xl p-8">

<h1 class="text-xl font-bold text-red-600">
初期化エラー
</h1>

<p class="mt-3 text-slate-600">
${App.util.escape(
    error.message
)}
</p>

<button
 onclick="location.reload()"
 class="mt-5 px-4 py-2 bg-indigo-600 text-white rounded-lg">
 再読み込み
</button>

</div>`;
        }
    }
};

/* ================================================================
 * 追加API
 * ================================================================ */

App.api.request = (async function(original) {

    return async function(
        action,
        data = {},
        method = 'POST'
    ) {

        if (
            action ===
            'save_runtime_data'
        ) {

            const url =
                location.pathname +
                '?action=save_runtime_data';

            const form =
                new FormData();

            form.append(
                'csrf_token',
                App.state.csrf
            );

            form.append(
                'data_json',
                data.data_json || ''
            );

            const response =
                await fetch(
                    url,
                    {
                        method: 'POST',
                        body: form,
                        credentials:
                            'same-origin'
                    }
                );

            const json =
                await response.json();

            return json;
        }

        return original(
            action,
            data,
            method
        );
    };

})(App.api.request);

/*
 * save_runtime_data は固定名称を追加せず、
 * 既存データ構造を維持したまま保存する。
 */
</script>

<?php
/*
 * JavaScriptから使用するruntime保存処理。
 * HTML出力後にactionを受け取るため、通常リクエストでは
 * ここには到達しない。AJAX時のみこのブロックを利用する。
 */
?>

<script>
/*
 * runtime保存APIはPHPの初期API switchへ到達させる必要があるため、
 * 直接fetchする簡易実装をここで定義する。
 */
App.api.request = (function(previous) {

    return async function(
        action,
        data = {},
        method = 'POST'
    ) {

        if (
            action !==
            'save_runtime_data'
        ) {
            return previous(
                action,
                data,
                method
            );
        }

        const form =
            new FormData();

        form.append(
            'csrf_token',
            App.state.csrf
        );

        form.append(
            'data_json',
            data.data_json || ''
        );

        const response =
            await fetch(
                location.pathname +
                '?action=save_runtime_data',
                {
                    method: 'POST',
                    credentials:
                        'same-origin',
                    body: form
                }
            );

        return response.json();
    };

})(App.api.request);

/*
 * PHP側にruntime保存actionが存在しない環境でも、
 * 顧客・メールログの画面操作だけでJavaScriptが停止しないようにする。
 */
App.actions.markKintoneRegistered =
    async function(customerId) {

        await App.api.request(
            'mark_kintone_registered',
            {
                customer_id:
                    customerId
            }
        );

        await App.api.bootstrap();

        App.render.mail();
    };

/*
 * DOM readyStateガード。
 * 初期化は必ず1回だけ実行する。
 */
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